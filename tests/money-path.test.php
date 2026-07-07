<?php
/**
 * DoughBoss money-path unit tests.
 *
 * Exercises the pure, database-free money-path logic that the release checklist
 * (see CLAUDE.md → "Money-path requirements") requires test coverage for before
 * the voucher domain can ship:
 *
 *   - DoughBoss_Coupon_Code — typo / guess-resistant check characters and the
 *     normalization that folds common mis-reads back onto the canonical alphabet.
 *   - DoughBoss_Voucher::generate_code() — the code format built on top of it.
 *   - DoughBoss_Voucher::evaluate() — the voucher *preview* discount maths (the
 *     amount shown in the cart before an atomic redeem), across fixed / percent /
 *     scope / validity-window / min-spend branches.
 *
 * These run against the WordPress kernel stub — no live WordPress and no database
 * — because every method under test computes from its arguments alone. The atomic
 * single-use redeem itself (conditional UPDATE) needs a real DB and is covered by
 * the staging smoke test, not here.
 *
 * Run: php tests/money-path.test.php
 * Exit non-zero on any failure (CI-safe).
 *
 * @package DoughBoss\Tests
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../includes/class-doughboss-voucher.php';
require __DIR__ . '/../includes/class-doughboss-coupon-code.php';

$fail = 0;
$pass = 0;
function ok( $cond, $label ) {
	global $fail, $pass;
	if ( $cond ) {
		$pass++;
		echo "  ok   $label\n";
	} else {
		$fail++;
		echo "  FAIL $label\n";
	}
}
function section( $t ) {
	echo "\n== $t ==\n";
}

echo "=== DoughBoss money-path unit tests ===\n";

/* -------------------------------------------------------------------------- */
/* 1. Coupon check-character: generate -> validate round-trip                 */
/* -------------------------------------------------------------------------- */

section( 'Coupon codes — generate/validate round-trip' );

$alpha        = DoughBoss_Coupon_Code::alphabet();
$round_trips  = 0;
$shape_ok     = 0;
$samples      = 200;
$sample_codes = array();

for ( $i = 0; $i < $samples; $i++ ) {
	$code             = DoughBoss_Coupon_Code::generate( 2, 4 );
	$sample_codes[]   = $code;
	$parts            = explode( '-', $code );
	$is_shape         = ( 2 === count( $parts ) && 4 === strlen( $parts[0] ) && 4 === strlen( $parts[1] ) );
	$shape_ok        += $is_shape ? 1 : 0;
	$round_trips     += DoughBoss_Coupon_Code::validate( $code ) ? 1 : 0;
}

ok( $samples === $shape_ok, "generate() always yields the expected 4-4 grouped shape ($shape_ok/$samples)" );
ok( $samples === $round_trips, "every freshly generated code validate()s ($round_trips/$samples)" );

/* -------------------------------------------------------------------------- */
/* 2. Check character catches a corrupted check digit — GUARANTEED            */
/* -------------------------------------------------------------------------- */

section( 'Coupon codes — corrupted check character is rejected' );

$check_rejected = 0;
foreach ( $sample_codes as $code ) {
	// Flip the LAST character (the check char of the second part) to a
	// different alphabet symbol. By construction the check must equal the
	// recomputed value, so any different symbol must fail validation.
	$last     = substr( $code, -1 );
	$replace  = ( $alpha[0] === $last ) ? $alpha[1] : $alpha[0];
	$broken   = substr( $code, 0, -1 ) . $replace;
	$check_rejected += DoughBoss_Coupon_Code::validate( $broken ) ? 0 : 1;
}
ok( count( $sample_codes ) === $check_rejected, "a wrong check character is always rejected ($check_rejected/" . count( $sample_codes ) . ')' );

/* -------------------------------------------------------------------------- */
/* 3. Single data-character typo is caught "almost always"                    */
/* -------------------------------------------------------------------------- */

section( 'Coupon codes — single data-character typo resistance' );

$typo_caught = 0;
foreach ( $sample_codes as $code ) {
	// Corrupt the FIRST data character of the first part to the next alphabet
	// symbol. This shifts the position-weighted sum, so the check char no
	// longer matches — except in the rare cases where the delta is a multiple
	// of the alphabet length (the documented "almost always", not "always").
	$first    = $code[0];
	$idx      = strpos( $alpha, $first );
	$next     = $alpha[ ( $idx + 1 ) % strlen( $alpha ) ];
	$broken   = $next . substr( $code, 1 );
	$typo_caught += DoughBoss_Coupon_Code::validate( $broken ) ? 0 : 1;
}
$rate = $typo_caught / count( $sample_codes );
ok( $rate >= 0.9, sprintf( 'single data-char typos caught %.0f%% of the time (>=90%% expected)', $rate * 100 ) );

/* -------------------------------------------------------------------------- */
/* 4. Transposed parts are rejected (index salt)                              */
/* -------------------------------------------------------------------------- */

section( 'Coupon codes — transposed parts rejected' );

$transpose_caught = 0;
$transpose_total  = 0;
foreach ( $sample_codes as $code ) {
	$parts = explode( '-', $code );
	if ( $parts[0] === $parts[1] ) {
		continue; // Identical parts can't be detected by a swap; skip.
	}
	$transpose_total++;
	$swapped = $parts[1] . '-' . $parts[0];
	$transpose_caught += DoughBoss_Coupon_Code::validate( $swapped ) ? 0 : 1;
}
// The per-part index salt means a genuine swap almost always breaks the check.
$trate = $transpose_total ? ( $transpose_caught / $transpose_total ) : 1.0;
ok( $trate >= 0.9, sprintf( 'part transposition caught %.0f%% of the time ($transpose_caught/$transpose_total)', $trate * 100 ) );

/* -------------------------------------------------------------------------- */
/* 5. normalize() folds common mis-reads and formatting noise                 */
/* -------------------------------------------------------------------------- */

section( 'Coupon codes — normalize() folds mis-reads' );

ok( 'K7QF-3MR9' === DoughBoss_Coupon_Code::normalize( ' k7qf-3mr9 ' ), 'lower-case + surrounding space normalized' );
ok( 'K7QF-3MR9' === DoughBoss_Coupon_Code::normalize( 'k7qf - 3mr9' ), 'inner spaces around the ASCII hyphen are stripped' );
ok( 'K7QF3MR9' === DoughBoss_Coupon_Code::normalize( 'K7QF–3MR9' ), 'a non-ASCII en-dash is dropped (only ASCII hyphens are part boundaries)' );
// O -> 0 -> Q, I/L -> 1 -> 7 (alphabet has no 0/O/1/I/L).
ok( 'QQ77' === DoughBoss_Coupon_Code::normalize( 'OoIl' ), 'O/I/L fold onto Q/7 (alphabet has no 0/O/1/I/L)' );
ok( '' === DoughBoss_Coupon_Code::normalize( '   ' ), 'blank input normalizes to empty' );

/* -------------------------------------------------------------------------- */
/* 6. validate() defers on non-new-format codes (backward compatibility)      */
/* -------------------------------------------------------------------------- */

section( 'Coupon codes — legacy/prefixed codes are deferred, not rejected' );

ok( true === DoughBoss_Coupon_Code::validate( 'ABCDEFGH' ), 'bare (un-hyphenated) legacy body is deferred to the DB' );
ok( true === DoughBoss_Coupon_Code::validate( 'SNOW-463XKDC7' ), 'uneven-length prefixed code is deferred' );
ok( true === DoughBoss_Coupon_Code::validate( '' ), 'empty code is deferred (DB decides)' );

/* -------------------------------------------------------------------------- */
/* 7. Voucher::generate_code() — prefix + validatable body                    */
/* -------------------------------------------------------------------------- */

section( 'Voucher::generate_code() — format' );

$prefixed_ok = 0;
$body_ok     = 0;
for ( $i = 0; $i < 100; $i++ ) {
	$code    = DoughBoss_Voucher::generate_code( 'SNOW', 8 );
	$has_pre = ( 0 === strpos( $code, 'SNOW-' ) );
	$prefixed_ok += $has_pre ? 1 : 0;
	// validate() skips the prefix and checks the last two body parts.
	$body_ok += DoughBoss_Coupon_Code::validate( $code ) ? 1 : 0;
}
ok( 100 === $prefixed_ok, "prefixed codes keep their SNOW- prefix ($prefixed_ok/100)" );
ok( 100 === $body_ok, "generated code bodies all validate() ($body_ok/100)" );

$no_prefix = DoughBoss_Voucher::generate_code( '', 8 );
ok( false === strpos( $no_prefix, '-' ) || substr_count( $no_prefix, '-' ) === 1, 'empty prefix yields a bare two-part body (no leading hyphen)' );
ok( true === DoughBoss_Coupon_Code::validate( $no_prefix ), 'un-prefixed generated code validate()s' );

/* -------------------------------------------------------------------------- */
/* 8. Voucher::evaluate() — preview discount maths                            */
/* -------------------------------------------------------------------------- */

section( 'Voucher::evaluate() — preview discount maths' );

/**
 * Build a voucher row with sensible money-path defaults.
 *
 * @param array $overrides Field overrides.
 * @return object
 */
function db_row( array $overrides = array() ) {
	return (object) array_merge(
		array(
			'status'     => 'issued',
			'scope'      => 'both',
			'type'       => 'fixed',
			'value'      => 10.0,
			'min_spend'  => 0.0,
			'valid_from' => '',
			'valid_to'   => '',
		),
		$overrides
	);
}

/**
 * Assert an evaluate() result's validity, amount (2dp) and reason.
 */
function eval_is( $label, $row, $subtotal, $channel, $valid, $amount, $reason = null ) {
	$r      = DoughBoss_Voucher::evaluate( $row, $subtotal, $channel );
	$got_ok = ( (bool) $valid === (bool) $r['valid'] );
	$got_am = ( number_format( (float) $amount, 2 ) === number_format( (float) $r['amount'], 2 ) );
	$got_rz = ( null === $reason ) ? true : ( $reason === $r['reason'] );
	ok(
		$got_ok && $got_am && $got_rz,
		sprintf(
			'%s → valid=%s amount=%s reason=%s',
			$label,
			$r['valid'] ? 'true' : 'false',
			number_format( (float) $r['amount'], 2 ),
			$r['reason']
		)
	);
}

// Fixed-amount vouchers.
eval_is( 'fixed $10 on $25', db_row(), 25.0, 'online', true, 10.00, 'ok' );
eval_is( 'fixed $10 capped at a $6 subtotal', db_row(), 6.0, 'online', true, 6.00, 'ok' );
eval_is( 'fixed $10 on a $0 cart', db_row(), 0.0, 'online', true, 0.00, 'ok' );
eval_is( 'negative subtotal is clamped to 0', db_row(), -5.0, 'online', true, 0.00, 'ok' );

// Percentage vouchers (rounded to cents).
eval_is( 'percent 20% on $50', db_row( array( 'type' => 'percent', 'value' => 20.0 ) ), 50.0, 'online', true, 10.00, 'ok' );
eval_is( 'percent 15% on $33.33 (rounds)', db_row( array( 'type' => 'percent', 'value' => 15.0 ) ), 33.33, 'online', true, 5.00, 'ok' );

// Status gate.
eval_is( 'already-redeemed voucher is invalid', db_row( array( 'status' => 'redeemed' ) ), 25.0, 'online', false, 0.00, 'invalid' );
eval_is( 'voided voucher is invalid', db_row( array( 'status' => 'void' ) ), 25.0, 'online', false, 0.00, 'invalid' );

// Channel scope.
eval_is( 'in-store-only voucher rejected online', db_row( array( 'scope' => 'instore' ) ), 25.0, 'online', false, 0.00, 'invalid' );
eval_is( 'in-store-only voucher accepted in-store', db_row( array( 'scope' => 'instore' ) ), 25.0, 'instore', true, 10.00, 'ok' );
eval_is( 'online-only voucher accepted online', db_row( array( 'scope' => 'online' ) ), 25.0, 'online', true, 10.00, 'ok' );

// Minimum spend.
eval_is( 'below min-spend is rejected', db_row( array( 'min_spend' => 30.0 ) ), 25.0, 'online', false, 0.00, 'min_spend' );
eval_is( 'exactly at min-spend is accepted', db_row( array( 'min_spend' => 30.0 ) ), 30.0, 'online', true, 10.00, 'ok' );

// Validity window (stub "now" = 1750000000 ≈ mid-2025).
eval_is( 'expired voucher (valid_to in the past) rejected', db_row( array( 'valid_to' => '2020-01-01 00:00:00' ) ), 25.0, 'online', false, 0.00, 'invalid' );
eval_is( 'not-yet-valid voucher (valid_from in the future) rejected', db_row( array( 'valid_from' => '2030-01-01 00:00:00' ) ), 25.0, 'online', false, 0.00, 'invalid' );
eval_is( 'inside the validity window is accepted', db_row( array( 'valid_from' => '2020-01-01 00:00:00', 'valid_to' => '2030-01-01 00:00:00' ) ), 25.0, 'online', true, 10.00, 'ok' );

/* -------------------------------------------------------------------------- */
/* Result                                                                     */
/* -------------------------------------------------------------------------- */

echo "\n=== RESULT: $pass passed · $fail failed ===\n";
exit( $fail > 0 ? 1 : 0 );
