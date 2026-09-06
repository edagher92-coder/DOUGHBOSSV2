<?php
/**
 * Dependency-free test runner for the pure-logic parts of DoughBoss.
 *
 * Usage: php tests/run.php   (exit code 1 on any failure)
 *
 * These tests guard the money-critical invariants (tax maths, cart pricing,
 * order-number format) and the 2.5 regressions (cart-token case bug,
 * cookie minted on read, array-to-string sanitiser crash).
 *
 * @package DoughBoss
 */

require __DIR__ . '/bootstrap.php';

$db_tests_passed = 0;
$db_tests_failed = 0;

/**
 * Assert helper.
 *
 * @param bool   $cond    Condition.
 * @param string $message Failure message.
 * @return void
 */
function ok( $cond, $message ) {
	global $db_tests_passed, $db_tests_failed;
	if ( $cond ) {
		$db_tests_passed++;
		return;
	}
	$db_tests_failed++;
	fwrite( STDERR, "FAIL: {$message}\n" );
}

/**
 * Float equality to the cent.
 *
 * @param float  $expected Expected.
 * @param float  $actual   Actual.
 * @param string $message  Message.
 * @return void
 */
function same_money( $expected, $actual, $message ) {
	ok( abs( (float) $expected - (float) $actual ) < 0.005, sprintf( '%s (expected %.2f, got %s)', $message, $expected, var_export( $actual, true ) ) );
}

/**
 * Install a settings array for a test.
 *
 * @param array $overrides Settings.
 * @return void
 */
function with_settings( array $overrides ) {
	update_option( DoughBoss_Settings::OPTION_KEY, $overrides );
	DoughBoss_Settings::flush();
}

/**
 * Give the request a valid cart cookie.
 *
 * @return string
 */
function with_cookie() {
	$token                              = str_repeat( 'ab', 16 );
	$_COOKIE[ DoughBoss_Cart::COOKIE ] = $token;
	return $token;
}

// ---------------------------------------------------------------------------
// Settings defaults (NUMBERS RULE: no fabricated tax rate, AUD, inclusive).
// ---------------------------------------------------------------------------
db_test_reset();
$d = DoughBoss_Settings::defaults();
ok( 'AUD' === $d['currency_code'], 'default currency is AUD' );
ok( 0 === $d['tax_rate'], 'default tax rate is 0 (never a guessed figure)' );
ok( 1 === $d['prices_include_tax'], 'prices are tax-inclusive by default' );
ok( 'GST' === $d['tax_label'], 'tax label defaults to GST' );
ok( 0 === $d['enable_delivery'] && 1 === $d['enable_pickup'], 'pickup on, delivery off by default' );
ok( '$0.00' === DoughBoss_Settings::format_price( 0 ), 'format_price with defaults' );
ok( '$1,234.50' === DoughBoss_Settings::format_price( 1234.5 ), 'format_price thousands' );

with_settings( array( 'tax_label' => '' ) );
ok( 'Tax' === DoughBoss_Settings::tax_label(), 'empty tax label falls back to "Tax"' );

with_settings( array( 'sizes' => array( array( 'slug' => 'large', 'label' => 'Large', 'price' => 18 ) ) ) );
ok( null !== DoughBoss_Settings::find_size( 'large' ), 'find_size hits' );
ok( null === DoughBoss_Settings::find_size( 'LARGE' ), 'find_size is exact-match' );

// Memo is flushed when the option is written.
with_settings( array( 'min_order' => 25 ) );
same_money( 25, DoughBoss_Settings::min_order(), 'min_order reads new value after update' );
update_option( DoughBoss_Settings::OPTION_KEY, array( 'min_order' => 30 ) );
DoughBoss_Settings::init();
update_option( DoughBoss_Settings::OPTION_KEY, array( 'min_order' => 35 ) );
same_money( 35, DoughBoss_Settings::min_order(), 'init() hooks the update so the memo is dropped' );

// ---------------------------------------------------------------------------
// Cart token handling.
// ---------------------------------------------------------------------------
db_test_reset();
$cart = new DoughBoss_Cart();
ok( null === $cart->get_token(), 'no cookie -> no token' );
ok( array() === $cart->get_lines(), 'reading an empty cart is fine' );
ok( ! $cart->has_token(), 'reading does NOT mint a cookie' );
ok( empty( $GLOBALS['db_test_transients'] ), 'reading writes nothing to storage' );

db_test_reset();
$_COOKIE[ DoughBoss_Cart::COOKIE ] = 'AbCdEf0123456789AbCdEf0123456789';
$cart                              = new DoughBoss_Cart();
ok( null === $cart->get_token(), 'legacy mixed-case token is rejected, never lower-cased' );

db_test_reset();
$_COOKIE[ DoughBoss_Cart::COOKIE ] = "' OR 1=1 --";
$cart                              = new DoughBoss_Cart();
ok( null === $cart->get_token(), 'junk cookie is rejected' );

db_test_reset();
$token = with_cookie();
$cart  = new DoughBoss_Cart();
ok( $token === $cart->get_token(), 'valid lowercase hex token is accepted verbatim' );

// ---------------------------------------------------------------------------
// Cart add / merge / caps.
// ---------------------------------------------------------------------------
db_test_reset();
with_cookie();
$cart = new DoughBoss_Cart();
$line = $cart->add(
	array(
		'type'       => 'menu',
		'item_id'    => 7,
		'name'       => 'Margherita',
		'unit_price' => 14.5,
		'quantity'   => 2,
	)
);
ok( is_array( $line ) && 29.0 === (float) $line['line_total'], 'add returns decorated line with line_total' );
ok( isset( $GLOBALS['db_test_transients'][ DoughBoss_Cart::PREFIX . str_repeat( 'ab', 16 ) ] ), 'transient key uses the exact cookie token' );

$cart->add(
	array(
		'type'       => 'menu',
		'item_id'    => 7,
		'name'       => 'Margherita',
		'unit_price' => 15.0,
		'quantity'   => 1,
	)
);
$lines = $cart->get_lines();
ok( 1 === count( $lines ), 'same item merges into one line' );
ok( 3 === (int) $lines[0]['quantity'], 'merged quantity adds up' );
same_money( 15.0, $lines[0]['unit_price'], 'merge refreshes the unit price to the latest server price' );

$cart->update_quantity( $lines[0]['key'], 999 );
ok( DoughBoss_Cart::MAX_QTY === (int) $cart->get_lines()[0]['quantity'], 'quantity is capped at MAX_QTY' );
ok( false === $cart->update_quantity( 'nope', 1 ), 'updating a missing line returns false' );
ok( true === $cart->update_quantity( $lines[0]['key'], 0 ), 'quantity 0 removes the line' );
ok( $cart->is_empty(), 'cart is empty after removal' );

// Custom pizzas with the same toppings in a different order merge.
db_test_reset();
with_cookie();
$cart = new DoughBoss_Cart();
$base = array(
	'type'       => 'custom',
	'item_id'    => 0,
	'name'       => 'Custom Large',
	'size'       => 'Large',
	'size_slug'  => 'large',
	'unit_price' => 21.0,
	'quantity'   => 1,
);
$cart->add( $base + array( 'toppings' => array( array( 'slug' => 'ham', 'label' => 'Ham', 'price' => 1.5 ), array( 'slug' => 'olives', 'label' => 'Olives', 'price' => 1.5 ) ) ) );
$cart->add( $base + array( 'toppings' => array( array( 'slug' => 'olives', 'label' => 'Olives', 'price' => 1.5 ), array( 'slug' => 'ham', 'label' => 'Ham', 'price' => 1.5 ) ) ) );
ok( 1 === count( $cart->get_lines() ), 'topping order does not split lines' );
$cart->add( $base + array( 'toppings' => array( array( 'slug' => 'ham', 'label' => 'Ham', 'price' => 1.5 ) ) ) );
ok( 2 === count( $cart->get_lines() ), 'different toppings are separate lines' );

// MAX_LINES guard.
db_test_reset();
with_cookie();
$cart = new DoughBoss_Cart();
for ( $i = 1; $i <= DoughBoss_Cart::MAX_LINES; $i++ ) {
	$cart->add( array( 'type' => 'menu', 'item_id' => $i, 'name' => "Item {$i}", 'unit_price' => 1, 'quantity' => 1 ) );
}
$full = $cart->add( array( 'type' => 'menu', 'item_id' => 999, 'name' => 'One too many', 'unit_price' => 1, 'quantity' => 1 ) );
ok( is_wp_error( $full ) && 'doughboss_cart_full' === $full->get_error_code(), 'cart refuses a 51st line' );

// ---------------------------------------------------------------------------
// Totals — inclusive GST (Australian default).
// ---------------------------------------------------------------------------
db_test_reset();
with_cookie();
with_settings( array( 'tax_rate' => 10, 'prices_include_tax' => 1, 'tax_applies_to_delivery' => 1, 'delivery_fee' => 5 ) );
$cart = new DoughBoss_Cart();
$cart->add( array( 'type' => 'menu', 'item_id' => 1, 'name' => 'A', 'unit_price' => 22.0, 'quantity' => 1 ) );
$t = $cart->totals( 'pickup' );
same_money( 22.0, $t['subtotal'], 'inclusive: subtotal' );
same_money( 22.0, $t['total'], 'inclusive: total equals displayed price (nothing added on top)' );
same_money( 2.0, $t['tax'], 'inclusive: GST component is 1/11th of the price' );
ok( true === $t['tax_inclusive'], 'inclusive flag surfaces' );
ok( 'GST' === $t['tax_label'], 'tax label surfaces' );
same_money( 10.0, $t['tax_rate'], 'tax rate surfaces as a percentage' );

$t = $cart->totals( 'delivery' );
same_money( 5.0, $t['delivery_fee'], 'delivery fee applied for delivery' );
same_money( 27.0, $t['total'], 'inclusive + delivery: total = subtotal + fee' );
same_money( round( 27.0 / 11, 2 ), $t['tax'], 'inclusive + taxable delivery: GST on the fee too' );

with_settings( array( 'tax_rate' => 10, 'prices_include_tax' => 1, 'tax_applies_to_delivery' => 0, 'delivery_fee' => 5 ) );
$t = $cart->totals( 'delivery' );
same_money( 2.0, $t['tax'], 'inclusive + non-taxable delivery: GST only on food' );
same_money( 27.0, $t['total'], 'inclusive + non-taxable delivery: total unchanged' );

// ---------------------------------------------------------------------------
// Totals — exclusive (added on top).
// ---------------------------------------------------------------------------
with_settings( array( 'tax_rate' => 10, 'prices_include_tax' => 0, 'tax_applies_to_delivery' => 1, 'delivery_fee' => 5 ) );
$t = $cart->totals( 'delivery' );
same_money( 2.7, $t['tax'], 'exclusive: 10% of (22 + 5)' );
same_money( 29.7, $t['total'], 'exclusive: total = subtotal + fee + tax' );
ok( false === $t['tax_inclusive'], 'exclusive flag surfaces' );

with_settings( array( 'tax_rate' => 10, 'prices_include_tax' => 0, 'tax_applies_to_delivery' => 0, 'delivery_fee' => 5 ) );
$t = $cart->totals( 'delivery' );
same_money( 2.2, $t['tax'], 'exclusive + non-taxable delivery: 10% of 22' );
same_money( 29.2, $t['total'], 'exclusive + non-taxable delivery: total' );

// ---------------------------------------------------------------------------
// Totals — zero rate (the shipped default) and min order.
// ---------------------------------------------------------------------------
with_settings( array( 'delivery_fee' => 5 ) );
$t = $cart->totals( 'delivery' );
same_money( 0.0, $t['tax'], 'zero rate: no tax line' );
same_money( 27.0, $t['total'], 'zero rate: total = subtotal + fee' );
ok( true === $t['min_order_met'], 'no minimum -> met' );

with_settings( array( 'min_order' => 30 ) );
$t = $cart->totals( 'pickup' );
ok( false === $t['min_order_met'], '$22 does not meet a $30 minimum' );
same_money( 30.0, $t['min_order'], 'min order surfaces' );
$cart->add( array( 'type' => 'menu', 'item_id' => 2, 'name' => 'B', 'unit_price' => 8.0, 'quantity' => 1 ) );
ok( true === $cart->totals( 'pickup' )['min_order_met'], 'exactly the minimum counts as met' );

with_settings( array( 'tax_rate' => -5 ) );
same_money( 0.0, DoughBoss_Settings::tax_fraction(), 'negative tax rate is clamped to 0' );

// Rounding: many cheap lines must not accumulate float drift.
db_test_reset();
with_cookie();
with_settings( array( 'tax_rate' => 10, 'prices_include_tax' => 1 ) );
$cart = new DoughBoss_Cart();
for ( $i = 1; $i <= 10; $i++ ) {
	$cart->add( array( 'type' => 'menu', 'item_id' => $i, 'name' => "L{$i}", 'unit_price' => 0.1, 'quantity' => 3 ) );
}
$t = $cart->totals();
same_money( 3.0, $t['subtotal'], '30 × $0.10 rounds to exactly $3.00' );
same_money( 0.27, $t['tax'], 'GST on $3.00 inclusive rounds to $0.27' );

// ---------------------------------------------------------------------------
// Order numbers.
// ---------------------------------------------------------------------------
$seen = array();
for ( $i = 0; $i < 200; $i++ ) {
	$n = DoughBoss_Order::generate_order_number();
	ok( (bool) preg_match( '/^DB-\d{6}-[A-Z0-9]{4,8}$/i', $n ), "order number {$n} matches the admin search fast-path regex" );
	ok( (bool) preg_match( '/^DB-\d{6}-[' . DoughBoss_Order::NUMBER_ALPHABET . ']{' . DoughBoss_Order::NUMBER_LENGTH . '}$/', $n ), "order number {$n} uses only the unambiguous alphabet" );
	$seen[ $n ] = true;
}
ok( count( $seen ) > 195, 'order numbers are not obviously colliding' );
ok( false === strpbrk( DoughBoss_Order::NUMBER_ALPHABET, 'IO01' ), 'alphabet excludes I, O, 0 and 1' );

// Site-timezone date: at 08:00 Sydney on 2 Jan it must read 260102, not 260101 (UTC).
$GLOBALS['db_test_timezone'] = 'Australia/Sydney';
$sydney_8am                  = ( new DateTime( '2026-01-02 08:00:00', new DateTimeZone( 'Australia/Sydney' ) ) )->getTimestamp();
ok( '260102' === wp_date( 'ymd', $sydney_8am ), 'wp_date shim honours the site timezone' );
ok( '260101' === gmdate( 'ymd', $sydney_8am ), 'sanity: the same instant is still 1 Jan in UTC' );
$n = DoughBoss_Order::generate_order_number();
ok( substr( $n, 3, 6 ) === wp_date( 'ymd' ), 'order number carries the local trading date' );

$active = DoughBoss_Order::active_statuses();
ok( ! in_array( 'completed', $active, true ) && ! in_array( 'cancelled', $active, true ), 'completed/cancelled are not active' );
ok( array() === array_diff( $active, array_keys( DoughBoss_Order::statuses() ) ), 'every active status is a known status' );

// ---------------------------------------------------------------------------
// REST sanitisers (2.0 fatalled on array input to sanitize_email()).
// ---------------------------------------------------------------------------
ok( '' === DoughBoss_REST_Controller::sanitize_email_arg( array( 'x' ) ), 'array email -> empty string, no TypeError' );
ok( '' === DoughBoss_REST_Controller::sanitize_email_arg( null ), 'null email -> empty string' );
ok( 'sam@example.com' === DoughBoss_REST_Controller::sanitize_email_arg( 'Sam@Example.com ' ), 'email is trimmed and lower-cased for matching' );
ok( '' === DoughBoss_REST_Controller::sanitize_idempotency_key( array() ), 'array idempotency key -> empty' );
ok( 'abc-DEF_123script' === DoughBoss_REST_Controller::sanitize_idempotency_key( 'abc-DEF_123<script>' ), 'idempotency key stripped to [A-Za-z0-9_-]' );
ok( 64 === strlen( DoughBoss_REST_Controller::sanitize_idempotency_key( str_repeat( 'a', 100 ) ) ), 'idempotency key capped at 64 chars' );

// ---------------------------------------------------------------------------
printf( "%d passed, %d failed\n", $db_tests_passed, $db_tests_failed );
exit( $db_tests_failed > 0 ? 1 : 0 );
