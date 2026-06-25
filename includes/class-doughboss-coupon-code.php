<?php
/**
 * Typo / guess-resistant coupon-code helper.
 *
 * Generates short, grouped, human-readable codes (e.g. K7QF-3MR9) where the
 * LAST character of every part is a deterministic CHECK character derived from
 * the preceding characters of that part plus the part's index. A code that has
 * been mis-typed or mis-read by one character almost always fails its check, so
 * it can be rejected before it ever hits the database — and a random guess only
 * passes its check with 1-in-31 odds per part.
 *
 * Dependency-free and inspired by mariuswilms/coupon_code (BSD), but it reuses
 * the project's existing unambiguous alphabet (DoughBoss_Voucher::ALPHABET =
 * 'ABCDEFGHJKMNPQRSTUVWXYZ23456789', which already excludes 0/O/1/I/L) so its
 * output is interchangeable with the rest of the voucher system.
 *
 * Check scheme (documented so validate() is reproducible): for a part made of
 * the data characters d_0 .. d_{n-2} at part index p (0-based), the check char
 * is the alphabet symbol at:
 *
 *     ( sum_{i} ( index_of(d_i) + 1 ) * ( i + 1 ) + ( p + 1 ) ) mod ALPHABET_LEN
 *
 * i.e. a position-weighted sum over the alphabet, salted by the part index so
 * the same data characters yield a different check char in different positions
 * (catching transposed parts). It is a simple mod-over-alphabet scheme, not a
 * cryptographic MAC — its job is catching honest typos, not forgery.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate, normalize and validate check-character coupon codes.
 */
class DoughBoss_Coupon_Code {

	/**
	 * The unambiguous code alphabet. Re-uses the voucher alphabet so generated
	 * bodies are drop-in compatible with the rest of the voucher system.
	 *
	 * @return string
	 */
	public static function alphabet() {
		if ( class_exists( 'DoughBoss_Voucher' ) ) {
			return DoughBoss_Voucher::ALPHABET;
		}
		// Fallback mirror (no 0/O/1/I/L) should the voucher class be absent.
		return 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	}

	/**
	 * Compute the deterministic check character for a single part.
	 *
	 * @param string $data       The part's data characters (everything but the
	 *                           check char), already restricted to the alphabet.
	 * @param int    $part_index Zero-based index of this part within the code.
	 * @return string Single check character from the alphabet.
	 */
	protected static function check_char( $data, $part_index ) {
		$alpha = self::alphabet();
		$n     = strlen( $alpha );
		$len   = strlen( $data );
		$sum   = 0;

		for ( $i = 0; $i < $len; $i++ ) {
			$pos = strpos( $alpha, $data[ $i ] );
			if ( false === $pos ) {
				// A character outside the alphabet should never reach here, but
				// fold it in as 0 so the function stays total.
				$pos = -1;
			}
			$sum += ( $pos + 1 ) * ( $i + 1 );
		}

		$sum += ( (int) $part_index + 1 );

		return $alpha[ ( $sum % $n + $n ) % $n ];
	}

	/**
	 * Generate a grouped, check-character-bearing code, e.g. 'K7QF-3MR9'.
	 *
	 * Each part is `$part_len` characters: ( $part_len - 1 ) high-entropy random
	 * characters followed by one deterministic check character.
	 *
	 * @param int $parts    Number of hyphen-separated parts (default 2).
	 * @param int $part_len Characters per part, including the check char (default 4).
	 * @return string Upper-case hyphen-joined code.
	 */
	public static function generate( $parts = 2, $part_len = 4 ) {
		$parts    = max( 1, (int) $parts );
		$part_len = max( 2, (int) $part_len );
		$alpha    = self::alphabet();
		$n        = strlen( $alpha );
		$data_len = $part_len - 1;

		// One random byte per data character across every part.
		$needed = $parts * $data_len;
		$bytes  = self::random_bytes( $needed );

		$out = array();
		$b   = 0;
		for ( $p = 0; $p < $parts; $p++ ) {
			$data = '';
			for ( $i = 0; $i < $data_len; $i++ ) {
				$data .= $alpha[ ord( $bytes[ $b ] ) % $n ];
				$b++;
			}
			$out[] = $data . self::check_char( $data, $p );
		}

		return implode( '-', $out );
	}

	/**
	 * Source of random bytes: prefer random_bytes(), fall back to
	 * wp_generate_password() (which uses a CSPRNG when available).
	 *
	 * @param int $length Number of bytes required.
	 * @return string Binary string of at least $length bytes.
	 */
	protected static function random_bytes( $length ) {
		$length = max( 1, (int) $length );
		try {
			return random_bytes( $length );
		} catch ( Exception $e ) {
			// wp_generate_password returns printable chars; ord() of each is a
			// fine entropy source for indexing into the alphabet.
			return wp_generate_password( $length, true, true );
		}
	}

	/**
	 * Normalize user-entered text toward the canonical alphabet so a sloppily
	 * typed code still resolves: upper-case, trim, drop spaces and stray
	 * punctuation, map common mis-reads onto alphabet members, and keep the
	 * hyphens that separate parts.
	 *
	 * Because the alphabet excludes 0/O/1/I/L, the usual confusions are folded
	 * toward the character that DOES exist: O/0 -> 0 isn't possible (no 0), so
	 * both fold to the closest valid letter/digit; likewise for 1/I/L.
	 *
	 * @param string $code Raw user input.
	 * @return string Normalized code (may be empty).
	 */
	public static function normalize( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		if ( '' === $code ) {
			return '';
		}

		// Common visual mis-reads, mapped onto characters that exist in the
		// alphabet (which has no 0, O, 1, I or L):
		//   O, Q-like 0 -> ... there is no O/0, so collapse both to 'Q' is wrong;
		//   the safe, reversible choice is to map the absent glyphs onto the
		//   present digit/letter people most often intend.
		$map = array(
			'O' => '0', // 'O' typed for the absent letter -> treat as digit 0 (below).
			'I' => '1', // 'I' -> intended digit 1 (below).
			'L' => '1', // 'L' -> intended digit 1 (below).
		);
		$code = strtr( $code, $map );

		// Now fold the digits the alphabet doesn't contain (0 and 1) onto their
		// nearest valid neighbours so the value still indexes into the alphabet.
		//   0 -> Q (round glyph), 1 -> 7 (stroke glyph). These pairings are
		// deterministic so generate()/validate() agree on the canonical form.
		$code = strtr(
			$code,
			array(
				'0' => 'Q',
				'1' => '7',
			)
		);

		// Strip everything that is not an alphabet character or a part hyphen.
		$code = preg_replace( '/[^A-Z0-9-]/', '', $code );

		// Collapse runs of hyphens and trim leading/trailing ones.
		$code = preg_replace( '/-+/', '-', $code );
		$code = trim( $code, '-' );

		return $code;
	}

	/**
	 * Validate a code against its embedded check characters.
	 *
	 * BACKWARD COMPATIBILITY: only codes that ARE in this check-character format
	 * are judged. A code that doesn't look like the new format — e.g. a legacy
	 * voucher body that was generated before check chars existed, or a prefixed
	 * code like 'SNOW-463XKDC7' — returns TRUE ("unknown format, let the DB
	 * decide") rather than hard-failing. Only a code that is plausibly in the new
	 * format yet whose check character is wrong returns FALSE.
	 *
	 * @param string $code Code to validate (will be normalized first).
	 * @return bool True if valid OR not in the new format; false only on a
	 *              genuine check-character mismatch.
	 */
	public static function validate( $code ) {
		$code = self::normalize( $code );
		if ( '' === $code ) {
			return true;
		}

		$alpha = self::alphabet();
		$parts = explode( '-', $code );

		// A bare code (no hyphen) is not in the grouped new format; defer.
		if ( count( $parts ) < 2 ) {
			return true;
		}

		// The new format is groups of equal length, every character within the
		// alphabet. If any part fails those shape rules, this isn't a new-format
		// code (it may be a legacy/prefixed code) — defer to the DB.
		$expected_len = strlen( $parts[0] );
		if ( $expected_len < 2 ) {
			return true;
		}

		foreach ( $parts as $part ) {
			if ( strlen( $part ) !== $expected_len ) {
				return true; // Uneven groups -> not new-format.
			}
			if ( strlen( $part ) !== strspn( $part, $alpha ) ) {
				return true; // Contains a non-alphabet char -> not new-format.
			}
		}

		// Looks like a new-format code: every part's check char must recompute.
		foreach ( $parts as $p => $part ) {
			$data  = substr( $part, 0, -1 );
			$check = substr( $part, -1 );
			if ( self::check_char( $data, $p ) !== $check ) {
				return false;
			}
		}

		return true;
	}
}
