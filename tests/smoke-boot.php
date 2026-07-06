<?php
/**
 * DoughBoss boot smoke test.
 *
 * Boots the plugin against the WordPress kernel stub and asserts that every
 * domain wires up: all classes load, the plugin boots with no fatal, the REST
 * surface registers routes, money-path methods exist, and each optional
 * integration is dormant by default (its *_ready() gate returns false with no
 * configuration). This proves the platform "works" at the load/boot level
 * without a live WordPress — complementary to dev-check.sh (syntax) and
 * build-zip.sh (packaging).
 *
 * Run: php tests/smoke-boot.php
 * Exit non-zero on any failure (CI-safe).
 *
 * @package DoughBoss\Tests
 */

require __DIR__ . '/wp-stubs.php';

$fail = 0;
$pass = 0;
function ok( $cond, $label ) {
	global $fail, $pass;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; }
	else { $fail++; echo "  FAIL $label\n"; }
}
function section( $t ) { echo "\n== $t ==\n"; }

echo "=== DoughBoss boot smoke test ===\n";

// 1. Load + boot the plugin.
require __DIR__ . '/../doughboss.php';
ok( function_exists( 'doughboss' ), 'plugin bootstrap loaded' );

// Build the singleton (runs load_dependencies) and fire the WP lifecycle hooks
// the plugin registered, so init_components() and route registration execute.
try {
	doughboss();
	do_action( 'plugins_loaded' );
	do_action( 'init' );
	do_action( 'rest_api_init' );
	do_action( 'wp_loaded' );
	ok( true, 'plugin booted (plugins_loaded/init/rest_api_init) with no fatal' );
} catch ( Throwable $e ) {
	ok( false, 'plugin booted with no fatal — threw: ' . $e->getMessage() );
}

// 2. Per-slice class presence. Each slice must contribute its classes.
$slices = array(
	'1 · core plugin'          => array( 'DoughBoss', 'DoughBoss_Activator', 'DoughBoss_Settings', 'DoughBoss_Post_Types', 'DoughBoss_Cart', 'DoughBoss_Order', 'DoughBoss_REST_Controller', 'DoughBoss_Shortcodes', 'DoughBoss_Assets', 'DoughBoss_Migrations' ),
	'2 · Stripe'               => array( 'DoughBoss_Stripe' ),
	'3 · vouchers'             => array( 'DoughBoss_Voucher', 'DoughBoss_Coupon_Code' ),
	'4 · POSPal'               => array( 'DoughBoss_POSPal', 'DoughBoss_POSPal_Sync', 'DoughBoss_POSPal_Orders' ),
	'5 · catering'             => array( 'DoughBoss_Catering', 'DoughBoss_Catering_Package' ),
	'6 · notifications/RT'     => array( 'DoughBoss_Mercure', 'DoughBoss_Ntfy', 'DoughBoss_SMS', 'DoughBoss_Printer' ),
	'7 · locations/reports/etc'=> array( 'DoughBoss_Locations', 'DoughBoss_Reports', 'DoughBoss_Privacy', 'DoughBoss_Menu_Seeder', 'DoughBoss_CLI' ),
);
foreach ( $slices as $name => $classes ) {
	section( "Slice $name" );
	foreach ( $classes as $c ) {
		ok( class_exists( $c ), "class $c present" );
	}
}

// 3. Money-path methods exist (server-side recompute surface).
section( 'Money-path surface' );
ok( method_exists( 'DoughBoss_Cart', 'totals' ), 'DoughBoss_Cart::totals() exists' );
ok( class_exists( 'DoughBoss_Coupon_Code' ) && method_exists( 'DoughBoss_Coupon_Code', 'validate' ), 'DoughBoss_Coupon_Code::validate() exists' );
ok( class_exists( 'DoughBoss_Coupon_Code' ) && method_exists( 'DoughBoss_Coupon_Code', 'normalize' ), 'DoughBoss_Coupon_Code::normalize() exists' );

// 4. REST surface registered routes.
section( 'REST surface' );
$routes = $GLOBALS['__db_rest'];
ok( count( $routes ) > 0, 'REST routes registered (' . count( $routes ) . ' routes)' );
ok( in_array( 'doughboss/v1/config', $routes, true ) || (bool) preg_grep( '#doughboss/v1/config#', $routes ), 'GET /config route present' );
ok( (bool) preg_grep( '#doughboss/v1/menu#', $routes ), '/menu route present' );
ok( (bool) preg_grep( '#doughboss/v1/checkout#', $routes ), '/checkout route present' );

// 5. Storefront shortcodes registered.
section( 'Shortcodes' );
foreach ( array( 'doughboss_menu', 'doughboss_builder', 'doughboss_cart', 'doughboss_order_tracking' ) as $sc ) {
	ok( isset( $GLOBALS['__db_shortcodes'][ $sc ] ), "[$sc] registered" );
}

// 6. Menu CPT registered.
section( 'Post types' );
ok( in_array( 'doughboss_item', $GLOBALS['__db_posttypes'], true ), 'doughboss_item CPT registered' );

// 7. Optional integrations dormant by default (no config → *_ready() false).
section( 'Integrations dormant-by-default (security gate)' );
$gates = array(
	'DoughBoss_Stripe'  => 'is_ready',
	'DoughBoss_Mercure' => 'is_ready',
	'DoughBoss_Ntfy'    => 'is_ready',
	'DoughBoss_SMS'     => 'is_ready',
	'DoughBoss_Printer' => 'is_ready',
	'DoughBoss_POSPal'  => 'is_ready',
);
foreach ( $gates as $class => $method ) {
	if ( ! class_exists( $class ) ) { ok( false, "$class present for gate check" ); continue; }
	$m = method_exists( $class, $method ) ? $method : ( method_exists( $class, 'ready' ) ? 'ready' : null );
	if ( null === $m ) { echo "  ..   $class has no is_ready()/ready() — skipping gate assert\n"; continue; }
	try {
		$dormant = ! call_user_func( array( $class, $m ) );
		ok( $dormant, "$class::$m() dormant with no config" );
	} catch ( Throwable $e ) {
		ok( false, "$class::$m() threw: " . $e->getMessage() );
	}
}

echo "\n=== RESULT: $pass passed · $fail failed ===\n";
exit( $fail > 0 ? 1 : 0 );
