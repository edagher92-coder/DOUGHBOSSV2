<?php
/**
 * DoughBoss Growth claims ledger.
 *
 * Every factual statement a public page can make is a claim with a source. A page renders a claim only
 * when it is confirmed AND carries a source AND the ledger as a whole is valid AND the wording passes the
 * public-copy lint; otherwise the template omits the whole block (fail closed).
 *
 * This is a port of web/src/content/ledger.ts (publishable(), claimText(), assertLedger()). The pure
 * functions (validate( $claims ), publishable_claims(), claim_text()) return byte-identical results to the
 * TypeScript for the same input; tests/fixtures/ledger-oracle.json is generated from the TypeScript and
 * the PHP is checked against it. PHP-only additions: the "core-data" source kind (a value read live from
 * the core plugin at render time), type checks (JSON from a file can be any shape), the public-copy lint
 * and the "safe" accessors publishable() / text() that also refuse everything when the ledger is invalid.
 *
 * The module has no side effects at include time. It writes nothing: no option, table, post or file.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claims ledger.
 */
final class DoughBoss_Growth_Ledger {

	/**
	 * Features switched off while the ledger is invalid. They are the ones that put claims on public pages.
	 */
	const GUARDED_FEATURES = array( 'landing_pages', 'seo_head', 'coming_soon' );

	/**
	 * Source kinds. The first three are the TypeScript kinds; core-data is PHP only.
	 */
	const SOURCE_KINDS = array( 'owner-site', 'owner-confirmed', 'public-web', 'core-data' );

	/**
	 * Source kinds that may carry digits, "$" and "%" in the claim text.
	 */
	const NUMBER_SOURCE_KINDS = array( 'owner-confirmed', 'core-data' );

	/**
	 * Largest claims file read, in bytes. A bigger file is treated as invalid.
	 */
	const MAX_FILE_BYTES = 1048576;

	/**
	 * Same expression as the TypeScript PLACEHOLDER (no u flag, case-insensitive).
	 */
	const PLACEHOLDER = '/\[CONFIRM|TODO|TBC|lorem ipsum|xxx/i';

	/**
	 * Same expression as the TypeScript id check (D: "$" must not match before a trailing newline).
	 */
	const KEBAB = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

	/**
	 * Cached load result for this request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Test seam: path of the claims file. Production code never sets this.
	 *
	 * @var string|null
	 */
	private static $file_override = null;

	/**
	 * Register the runtime guard and the read-only admin tab. Idempotent.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'doughboss_growth_feature_enabled', array( __CLASS__, 'guard_features' ), 10, 2 );
		if ( is_admin() ) {
			add_action( 'doughboss_growth_admin_tabs', array( __CLASS__, 'register_admin_tab' ) );
			add_action( 'admin_notices', array( __CLASS__, 'render_invalid_notice' ) );
		}
	}

	/**
	 * Filter callback for doughboss_growth_feature_enabled: switch the claim-bearing features off while the
	 * ledger is invalid or unreadable. It can only turn a feature off; any error also turns it off.
	 *
	 * @param mixed  $enabled Value so far.
	 * @param string $feature Feature key.
	 * @return mixed
	 */
	public static function guard_features( $enabled, $feature = '' ) {
		if ( true !== $enabled ) {
			return $enabled;
		}
		if ( ! is_string( $feature ) || ! in_array( $feature, self::GUARDED_FEATURES, true ) ) {
			return $enabled;
		}
		try {
			return self::is_valid() ? $enabled : false;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Path of the shipped claims file.
	 *
	 * @return string
	 */
	public static function file_path() {
		if ( null !== self::$file_override ) {
			return self::$file_override;
		}
		$dir = defined( 'DOUGHBOSS_GROWTH_DIR' ) ? (string) constant( 'DOUGHBOSS_GROWTH_DIR' ) : dirname( dirname( __DIR__ ) ) . '/';
		return rtrim( $dir, '/\\' ) . '/content/claims.json';
	}

	/**
	 * Point the ledger at another file (tests only). Pass null to restore. Clears the cache.
	 *
	 * @param string|null $path File path or null.
	 * @return void
	 */
	public static function set_file_override( $path ) {
		if ( ! defined( 'DBGR_TESTING' ) ) {
			return; // Honoured only inside the test harness.
		}
		self::$file_override = ( is_string( $path ) && '' !== $path ) ? $path : null;
		self::$cache         = null;
	}

	/**
	 * Forget the cached load result.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$cache = null;
	}

	/**
	 * Load and validate the claims file once per request.
	 *
	 * @return array { claims: array, problems: string[], load_error: string, valid: bool }
	 *               "load_error" is "" when the file was read and parsed, otherwise one of
	 *               unreadable, too_large, bad_json, bad_shape. Anything but valid === true means: show nothing.
	 */
	public static function load() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$result = array(
			'claims'     => array(),
			'problems'   => array(),
			'load_error' => '',
			'valid'      => false,
		);
		$path   = self::file_path();
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			$result['load_error'] = 'unreadable';
			self::$cache          = $result;
			return $result;
		}
		$size = filesize( $path );
		if ( false === $size || $size > self::MAX_FILE_BYTES ) {
			$result['load_error'] = 'too_large';
			self::$cache          = $result;
			return $result;
		}
		$json = file_get_contents( $path );
		if ( ! is_string( $json ) ) {
			$result['load_error'] = 'unreadable';
			self::$cache          = $result;
			return $result;
		}
		$data = json_decode( $json, true, 16 );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$result['load_error'] = 'bad_json';
			self::$cache          = $result;
			return $result;
		}
		if ( ! is_array( $data ) || ! isset( $data['claims'] ) || ! is_array( $data['claims'] ) || array_values( $data['claims'] ) !== $data['claims'] ) {
			$result['load_error'] = 'bad_shape';
			self::$cache          = $result;
			return $result;
		}
		$result['claims']   = $data['claims'];
		$result['problems'] = self::validate( $data['claims'] );
		$result['valid']    = ( array() === $result['problems'] );
		self::$cache        = $result;
		return $result;
	}

	/**
	 * Whether the shipped ledger loaded and has no problems.
	 *
	 * @return bool
	 */
	public static function is_valid() {
		$loaded = self::load();
		return true === $loaded['valid'];
	}

	/**
	 * Port of assertLedger(): every violation as a string, in the same order and wording as the TypeScript.
	 * An empty array means valid. With no argument the shipped ledger is validated (a load error is then
	 * reported as one problem).
	 *
	 * PHP-only checks (types and source kind) come after the ported checks of the same claim and never
	 * fire on input the TypeScript type system allows.
	 *
	 * @param array|null $claims List of claims, or null for the shipped file.
	 * @return string[]
	 */
	public static function validate( $claims = null ) {
		if ( null === $claims ) {
			$loaded = self::load();
			if ( '' !== $loaded['load_error'] ) {
				return array( 'ledger: could not be loaded (' . $loaded['load_error'] . ')' );
			}
			return $loaded['problems'];
		}
		if ( ! is_array( $claims ) ) {
			return array( 'ledger: must be a list of claims' );
		}
		$problems = array();
		$seen     = array();
		$index    = -1;
		foreach ( $claims as $c ) {
			++$index;
			if ( ! is_array( $c ) ) {
				$problems[] = '#' . $index . ': claim must be an object';
				continue;
			}
			if ( ! isset( $c['id'] ) || ! is_string( $c['id'] ) ) {
				$problems[] = '#' . $index . ': id must be a string';
				continue;
			}
			$id = $c['id'];
			if ( 1 !== preg_match( self::KEBAB, $id ) ) {
				$problems[] = $id . ': id must be kebab-case';
			}
			if ( isset( $seen[ $id ] ) ) {
				$problems[] = $id . ': duplicate id';
			}
			$seen[ $id ] = true;

			$confirmed = array_key_exists( 'confirmed', $c ) ? $c['confirmed'] : null;
			if ( ! isset( $c['text'] ) || ! is_string( $c['text'] ) ) {
				$problems[] = $id . ': text must be a string';
			} else {
				if ( '' === self::js_trim( $c['text'] ) ) {
					$problems[] = $id . ': empty text';
				}
				if ( 1 === preg_match( self::PLACEHOLDER, $c['text'] ) ) {
					$problems[] = $id . ': text contains a placeholder marker';
				}
			}
			$source = array_key_exists( 'source', $c ) ? $c['source'] : null;
			if ( true === $confirmed && null === $source ) {
				$problems[] = $id . ': confirmed but has no source';
			}
			if ( is_array( $source ) ) {
				if ( ! isset( $source['ref'] ) || ! is_string( $source['ref'] ) ) {
					$problems[] = $id . ': source ref must be a string';
				} elseif ( '' === self::js_trim( $source['ref'] ) ) {
					$problems[] = $id . ': source has an empty ref';
				}
			} elseif ( null !== $source ) {
				$problems[] = $id . ': source must be an object';
			}

			// PHP-only checks.
			if ( ! is_bool( $confirmed ) ) {
				$problems[] = $id . ': confirmed must be true or false';
			}
			if ( is_array( $source ) && ( ! isset( $source['kind'] ) || ! is_string( $source['kind'] ) || ! in_array( $source['kind'], self::SOURCE_KINDS, true ) ) ) {
				$problems[] = $id . ': source kind is not recognised';
			}
		}
		return $problems;
	}

	/**
	 * Port of publishable(): confirmed and sourced claims in original order. Pure: validity of the ledger
	 * is not considered. Use publishable() for anything that reaches a customer.
	 *
	 * @param array $claims List of claims.
	 * @return array
	 */
	public static function publishable_claims( array $claims ) {
		$out = array();
		foreach ( $claims as $c ) {
			if ( is_array( $c ) && isset( $c['confirmed'] ) && true === $c['confirmed'] && isset( $c['source'] ) ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/**
	 * Port of claimText(): the text of one publishable claim, or null. Pure (see publishable_claims()).
	 *
	 * @param array  $claims List of claims.
	 * @param string $id     Claim id.
	 * @return string|null
	 */
	public static function claim_text( array $claims, $id ) {
		foreach ( self::publishable_claims( $claims ) as $c ) {
			if ( isset( $c['id'] ) && $c['id'] === $id ) {
				return isset( $c['text'] ) && is_string( $c['text'] ) ? $c['text'] : null;
			}
		}
		return null;
	}

	/**
	 * Claims safe to show customers from the shipped ledger: the ledger is valid, the claim is confirmed and
	 * sourced, and its wording passes the public-copy lint for its source kind. Keyed by id.
	 *
	 * @return array Id => claim.
	 */
	public static function publishable() {
		$loaded = self::load();
		if ( true !== $loaded['valid'] ) {
			return array();
		}
		$out = array();
		foreach ( self::publishable_claims( $loaded['claims'] ) as $c ) {
			$kind = ( is_array( $c['source'] ) && isset( $c['source']['kind'] ) ) ? $c['source']['kind'] : null;
			if ( array() !== self::lint_public( $c['text'], $kind ) ) {
				continue;
			}
			if ( ! isset( $out[ $c['id'] ] ) ) {
				$out[ $c['id'] ] = $c;
			}
		}
		return $out;
	}

	/**
	 * The text of one claim for a public page, or null (the template then omits the whole block).
	 *
	 * @param string $id Claim id.
	 * @return string|null
	 */
	public static function text( $id ) {
		if ( ! is_string( $id ) ) {
			return null;
		}
		$all = self::publishable();
		return isset( $all[ $id ] ) ? $all[ $id ]['text'] : null;
	}

	/**
	 * Public-copy lint. Returns the list of violation codes (empty array = clean).
	 *
	 * Always rejected: the unannounced product's working name (teaser-direction.md) anywhere (any case, also inside other words, to match the settings sanitiser),
	 * halal, vegan, gluten, nut-free, certified, best, "#1" / "number one". Rejected unless the source kind
	 * is owner-confirmed or core-data: any digit, any currency symbol (including "$") and "%".
	 * Text that is not a string or not valid UTF-8 is a violation (fail closed).
	 *
	 * @param mixed       $text        Text to check.
	 * @param string|null $source_kind Source kind of the claim, or null for text with no source (teaser text).
	 * @return string[] Violation codes: encoding, product_name, halal, vegan, gluten, nut_free, certified, best, number_one, digit, currency, percent.
	 */
	public static function lint_public( $text, $source_kind = null ) {
		if ( ! is_string( $text ) || 1 !== preg_match( '//u', $text ) ) {
			return array( 'encoding' );
		}
		// Remove invisible characters that could hide a word, then fold width and compatibility forms.
		$clean = preg_replace( '/[\x{00AD}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $text );
		if ( ! is_string( $clean ) ) {
			return array( 'encoding' );
		}
		$clean = self::fold_compat( $clean );
		if ( null === $clean ) {
			return array( 'encoding' );
		}
		$rules      = array(
			'product_name' => '/' . 'mini' . 's/iu', // The unannounced product's working name; spelled in two parts so no shipped file contains it.
			'halal'      => '/halal/iu',
			'vegan'      => '/vegan/iu',
			'gluten'     => '/gluten/iu',
			'nut_free'   => '/nut[\s_-]*free/iu',
			'certified'  => '/certified/iu',
			'best'       => '/(?<![\p{L}])best/iu',
			'number_one' => '/#\s*1(?![0-9])|number\s+one/iu',
		);
		$violations = array();
		foreach ( $rules as $code => $pattern ) {
			if ( 1 === preg_match( $pattern, $clean ) ) {
				$violations[] = $code;
			}
		}
		$numbers_allowed = is_string( $source_kind ) && in_array( $source_kind, self::NUMBER_SOURCE_KINDS, true );
		if ( ! $numbers_allowed ) {
			if ( 1 === preg_match( '/\p{Nd}/u', $clean ) ) {
				$violations[] = 'digit';
			}
			if ( 1 === preg_match( '/\p{Sc}/u', $clean ) ) {
				$violations[] = 'currency';
			}
			if ( 1 === preg_match( '/[%\x{FF05}\x{FE6A}\x{2030}\x{2031}]/u', $clean ) ) {
				$violations[] = 'percent';
			}
		}
		return $violations;
	}

	/**
	 * Fold compatibility and width forms ("full-width letters", enclosed or mathematical alphabets) to plain text so a
	 * banned word cannot be hidden in them. With the intl extension this is Unicode NFKC. Without it (some hosts, and the
	 * WebAssembly PHP 7.4 used for the release smoke test) the full-width ASCII block is folded by hand and the other
	 * compatibility alphabets are refused outright (fail closed: null), because they cannot be folded safely.
	 *
	 * Public so the other public-copy lint (the coming-soon teaser) shares exactly one implementation.
	 *
	 * @param string $text     Valid UTF-8 text.
	 * @param bool   $use_intl False forces the no-intl path (tests only).
	 * @return string|null Folded text, or null when it cannot be folded safely.
	 */
	public static function fold_compat( $text, $use_intl = true ) {
		if ( ! is_string( $text ) ) {
			return null;
		}
		if ( $use_intl && class_exists( 'Normalizer', false ) ) {
			$folded = Normalizer::normalize( $text, Normalizer::FORM_KC );
			return is_string( $folded ) ? $folded : null;
		}
		if ( 1 === preg_match( '/[\x{2100}-\x{214F}\x{2460}-\x{24FF}\x{FE50}-\x{FE6F}\x{FF5F}-\x{FFEF}\x{1D400}-\x{1D7FF}\x{1F100}-\x{1F1FF}]/u', $text ) ) {
			return null;
		}
		$out = preg_replace_callback(
			'/[\x{FF01}-\x{FF5E}]/u',
			function ( $match ) {
				$bytes = array_values( unpack( 'C*', $match[0] ) );
				$cp    = ( ( $bytes[0] & 0x0F ) << 12 ) | ( ( $bytes[1] & 0x3F ) << 6 ) | ( $bytes[2] & 0x3F );
				return chr( $cp - 0xFEE0 );
			},
			$text
		);
		return is_string( $out ) ? $out : null;
	}

	/**
	 * Blocks that a page declared and that are hidden now because a claim they need is not publishable.
	 * Other modules declare their blocks on the doughboss_growth_ledger_blocks filter as a list of
	 * array( 'page' => string, 'block' => string, 'claims' => string[] ). Read only: nothing is stored.
	 *
	 * @return array List of array( page, block, missing ) where missing is the list of claim ids.
	 */
	public static function hidden_blocks() {
		$declared = apply_filters( 'doughboss_growth_ledger_blocks', array() );
		if ( ! is_array( $declared ) ) {
			return array();
		}
		$publishable = self::publishable();
		$hidden      = array();
		foreach ( $declared as $block ) {
			if ( ! is_array( $block ) || ! isset( $block['page'], $block['block'], $block['claims'] ) || ! is_string( $block['page'] ) || ! is_string( $block['block'] ) || ! is_array( $block['claims'] ) ) {
				continue;
			}
			$missing = array();
			foreach ( $block['claims'] as $claim_id ) {
				if ( ! is_string( $claim_id ) || ! isset( $publishable[ $claim_id ] ) ) {
					$missing[] = is_string( $claim_id ) ? $claim_id : '(invalid id)';
				}
			}
			if ( array() !== $missing ) {
				$hidden[] = array(
					'page'    => $block['page'],
					'block'   => $block['block'],
					'missing' => $missing,
				);
			}
		}
		return $hidden;
	}

	/**
	 * Status of one claim for the admin list.
	 *
	 * @param mixed $claim Claim as loaded.
	 * @return string One of published, gap, blocked_lint, invalid.
	 */
	public static function status( $claim ) {
		if ( ! self::is_valid() || ! is_array( $claim ) || ! isset( $claim['id'], $claim['text'] ) ) {
			return 'invalid';
		}
		$publishable = self::publishable();
		if ( isset( $publishable[ $claim['id'] ] ) ) {
			return 'published';
		}
		if ( isset( $claim['confirmed'] ) && true === $claim['confirmed'] && isset( $claim['source'] ) ) {
			return 'blocked_lint';
		}
		return 'gap';
	}

	/**
	 * JavaScript String.prototype.trim for the TypeScript parity: strips the Unicode white space and line
	 * terminators JavaScript strips (PHP trim() only strips ASCII).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function js_trim( $text ) {
		$ws      = '[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';
		$trimmed = preg_replace( '/^' . $ws . '+|' . $ws . '+$/u', '', $text );
		if ( is_string( $trimmed ) ) {
			return $trimmed;
		}
		// Not valid UTF-8: JavaScript cannot see such a string; fall back to the ASCII trim.
		return trim( $text );
	}

	/**
	 * Register the Claims tab (callback of the doughboss_growth_admin_tabs action).
	 *
	 * @return void
	 */
	public static function register_admin_tab() {
		DoughBoss_Growth_Admin::add_tab( 'claims', __( 'Claims', 'doughboss-growth' ), array( __CLASS__, 'render_admin_tab' ) );
	}

	/**
	 * Notice shown to managers while the ledger is invalid.
	 *
	 * @return void
	 */
	public static function render_invalid_notice() {
		if ( ! DoughBoss_Growth_Admin::user_can_manage() || self::is_valid() ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'DoughBoss Growth: the claims ledger is invalid or unreadable, so landing pages, their SEO head output and the coming-soon section are switched off until it is fixed. See the Claims tab.', 'doughboss-growth' ) . '</p></div>';
	}

	/**
	 * Human label for a status code.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_label( $status ) {
		switch ( $status ) {
			case 'published':
				return __( 'Published', 'doughboss-growth' );
			case 'gap':
				return __( 'Gap: not confirmed, never shown', 'doughboss-growth' );
			case 'blocked_lint':
				return __( 'Blocked by the public-copy lint, never shown', 'doughboss-growth' );
			default:
				return __( 'Invalid, never shown', 'doughboss-growth' );
		}
	}

	/**
	 * Read-only Claims tab: every claim with its status and source, the ledger problems, and the page blocks
	 * that are hidden. Escapes everything; no forms, so there is no handler to nonce.
	 *
	 * @return void
	 */
	public static function render_admin_tab() {
		if ( ! DoughBoss_Growth_Admin::user_can_manage() ) {
			return;
		}
		$loaded = self::load();
		echo '<h2>' . esc_html__( 'Claims ledger', 'doughboss-growth' ) . '</h2>';
		echo '<p>' . esc_html__( 'Public pages show only claims that are confirmed, have a source and pass the copy lint. This list is read only: change content/claims.json through a pull request.', 'doughboss-growth' ) . '</p>';

		if ( true === $loaded['valid'] ) {
			echo '<p><strong>' . esc_html__( 'Ledger status: valid.', 'doughboss-growth' ) . '</strong></p>';
		} else {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Ledger status: invalid. Landing pages, SEO head output and the coming-soon section are off.', 'doughboss-growth' ) . '</strong></p><ul>';
			foreach ( self::validate() as $problem ) {
				echo '<li>' . esc_html( $problem ) . '</li>';
			}
			echo '</ul></div>';
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Id', 'doughboss-growth' ), __( 'Status', 'doughboss-growth' ), __( 'Wording', 'doughboss-growth' ), __( 'Source', 'doughboss-growth' ), __( 'Note', 'doughboss-growth' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( array() === $loaded['claims'] ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No claims loaded.', 'doughboss-growth' ) . '</td></tr>';
		}
		foreach ( $loaded['claims'] as $claim ) {
			$source = '';
			if ( is_array( $claim ) && isset( $claim['source'] ) && is_array( $claim['source'] ) ) {
				$parts = array();
				foreach ( array( 'kind', 'ref', 'retrieved', 'confirmedOn' ) as $key ) {
					if ( isset( $claim['source'][ $key ] ) && is_string( $claim['source'][ $key ] ) ) {
						$parts[] = $claim['source'][ $key ];
					}
				}
				$source = implode( ' | ', $parts );
			}
			$id   = ( is_array( $claim ) && isset( $claim['id'] ) && is_string( $claim['id'] ) ) ? $claim['id'] : '';
			$text = ( is_array( $claim ) && isset( $claim['text'] ) && is_string( $claim['text'] ) ) ? $claim['text'] : '';
			$note = ( is_array( $claim ) && isset( $claim['note'] ) && is_string( $claim['note'] ) ) ? $claim['note'] : '';
			echo '<tr>';
			echo '<td><code>' . esc_html( $id ) . '</code></td>';
			echo '<td>' . esc_html( self::status_label( self::status( $claim ) ) ) . '</td>';
			echo '<td>' . esc_html( $text ) . '</td>';
			echo '<td>' . esc_html( $source ) . '</td>';
			echo '<td>' . esc_html( $note ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Page blocks hidden because a claim is not publishable', 'doughboss-growth' ) . '</h3>';
		$hidden = self::hidden_blocks();
		if ( array() === $hidden ) {
			echo '<p>' . esc_html__( 'None declared, or every declared block has its claims.', 'doughboss-growth' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $hidden as $row ) {
				/* translators: 1: page, 2: block, 3: claim ids. */
				$line = sprintf( __( '%1$s, block "%2$s": waiting for %3$s', 'doughboss-growth' ), $row['page'], $row['block'], implode( ', ', $row['missing'] ) );
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul>';
		}
	}
}
