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
	'6 · notifications/RT'     => array( 'DoughBoss_Mercure', 'DoughBoss_Ntfy', 'DoughBoss_SMS', 'DoughBoss_Emails', 'DoughBoss_Printer' ),
	'7 · locations/reports/etc'=> array( 'DoughBoss_Locations', 'DoughBoss_Capacity', 'DoughBoss_Reports', 'DoughBoss_Privacy', 'DoughBoss_Menu_Seeder', 'DoughBoss_CLI' ),
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
ok( method_exists( 'DoughBoss_Order', 'query_board' ), 'DoughBoss_Order::query_board() exists' );
// Not just existence — actually call it against the stub $wpdb so the shared
// row-shaping path (shape_board_row(), get_items_for_orders(), wp_list_pluck())
// executes end-to-end with no fatal, and confirm the {items,total} contract.
try {
	$board_result = DoughBoss_Order::query_board();
	ok(
		is_array( $board_result ) && array_key_exists( 'items', $board_result ) && array_key_exists( 'total', $board_result ),
		'DoughBoss_Order::query_board() returns an {items,total} array with no fatal'
	);
} catch ( Throwable $e ) {
	ok( false, 'DoughBoss_Order::query_board() threw: ' . $e->getMessage() );
}

echo "\n== Versioned order lifecycle ==\n";
ok( '1.12.0' === DOUGHBOSS_DB_VERSION, 'database contract version is 1.12.0' );
ok( method_exists( 'DoughBoss_Order', 'transition' ), 'DoughBoss_Order::transition() exists' );
ok( method_exists( 'DoughBoss_Order', 'events' ), 'DoughBoss_Order::events() exists' );
ok( DoughBoss_Order::can_transition( 'pending', 'confirmed' ), 'pending can be accepted' );
ok( ! DoughBoss_Order::can_transition( 'pending', 'completed' ), 'pending cannot jump to completed' );
ok( ! DoughBoss_Order::can_transition( 'confirmed', 'ready' ), 'confirmed cannot skip cooking' );
ok( DoughBoss_Order::can_transition( 'confirmed', 'preparing' ), 'confirmed can start cooking' );
ok( DoughBoss_Order::can_transition( 'preparing', 'ready' ), 'preparing can be marked ready' );
ok( DoughBoss_Order::can_transition( 'ready', 'completed', 'pickup' ), 'pickup ready can complete' );
ok( DoughBoss_Order::can_transition( 'ready', 'out_for_delivery', 'delivery' ), 'delivery ready can leave the shop' );
ok( ! DoughBoss_Order::can_transition( 'ready', 'completed', 'delivery' ), 'delivery cannot be marked delivered before leaving the shop' );
ok( ! DoughBoss_Order::can_transition( 'ready', 'out_for_delivery', 'pickup' ), 'pickup cannot enter delivery state' );
ok( empty( DoughBoss_Order::allowed_transitions( 'completed' ) ), 'completed is terminal' );
ok( empty( DoughBoss_Order::allowed_transitions( 'cancelled' ) ), 'cancelled is terminal' );

$customer_pending = DoughBoss_Order::customer_projection( (object) array( 'status' => 'pending', 'order_type' => 'pickup' ) );
$customer_baking  = DoughBoss_Order::customer_projection( (object) array( 'status' => 'baking', 'order_type' => 'pickup' ) );
$customer_done    = DoughBoss_Order::customer_projection( (object) array( 'status' => 'completed', 'order_type' => 'pickup' ) );
$delivery_ready   = DoughBoss_Order::customer_projection( (object) array( 'status' => 'ready', 'order_type' => 'delivery' ) );
ok( 'received' === $customer_pending['status'], 'pending projects to customer received' );
ok( 'preparing' === $customer_baking['status'], 'baking projects to customer preparing' );
ok( 'collected' === $customer_done['status'], 'completed pickup projects to collected' );
ok( 'ready_for_delivery' === $delivery_ready['status'], 'ready delivery never projects to pickup wording' );
$late = DoughBoss_Order::timing_projection(
	(object) array(
		'status'                    => 'preparing',
		'promised_ready_by_utc'     => '2026-07-06 00:10:00',
	),
	'2026-07-06 00:11:00'
);
ok( 'estimate_passed' === $late['status'], 'passed promise derives a warning without changing status' );

// 3b. Settings defaults for the new proxy-aware rate limiting keys.
section( 'Settings defaults' );
$defaults = DoughBoss_Settings::defaults();
ok( array_key_exists( 'behind_reverse_proxy', $defaults ), "defaults() has 'behind_reverse_proxy' key" );
ok( isset( $defaults['behind_reverse_proxy'] ) && 0 === $defaults['behind_reverse_proxy'], "'behind_reverse_proxy' defaults to 0 (off)" );
ok( array_key_exists( 'trusted_proxy_header', $defaults ), "defaults() has 'trusted_proxy_header' key" );
ok( isset( $defaults['trusted_proxy_header'] ) && 'X-Forwarded-For' === $defaults['trusted_proxy_header'], "'trusted_proxy_header' defaults to 'X-Forwarded-For'" );
ok( method_exists( 'DoughBoss_Settings', 'behind_reverse_proxy' ) && false === DoughBoss_Settings::behind_reverse_proxy(), 'DoughBoss_Settings::behind_reverse_proxy() reads false with no option set' );
ok( method_exists( 'DoughBoss_Settings', 'trusted_proxy_header' ) && 'X-Forwarded-For' === DoughBoss_Settings::trusted_proxy_header(), "DoughBoss_Settings::trusted_proxy_header() reads 'X-Forwarded-For' with no option set" );

// 3b-ii. Customer stage-email defaults: both customer stages on by default
// (native wp_mail needs no external config), staff copy off, and all four
// template overrides blank (= built-in default copy).
ok( array_key_exists( 'email_on_accepted', $defaults ) && 1 === $defaults['email_on_accepted'], "'email_on_accepted' defaults to 1 (on)" );
ok( array_key_exists( 'email_on_ready', $defaults ) && 1 === $defaults['email_on_ready'], "'email_on_ready' defaults to 1 (on)" );
ok( array_key_exists( 'email_staff_copy', $defaults ) && 0 === $defaults['email_staff_copy'], "'email_staff_copy' defaults to 0 (off)" );
foreach ( array( 'tpl_accepted_email_subject', 'tpl_accepted_email_body', 'tpl_ready_email_subject', 'tpl_ready_email_body' ) as $tpl_key ) {
	ok( array_key_exists( $tpl_key, $defaults ) && '' === $defaults[ $tpl_key ], "'$tpl_key' defaults to '' (built-in copy)" );
}
ok( method_exists( 'DoughBoss_Emails', 'emails_ready' ) && true === DoughBoss_Emails::emails_ready(), 'DoughBoss_Emails::emails_ready() true with default toggles (at least one stage on)' );

// 3c. Order Board optional access key (kitchen access hardening). Defaults to
// blank so a fresh install's board is gated by login + manage_doughboss_kds
// only, never silently locked out by an unset key.
ok( array_key_exists( 'board_access_key', $defaults ), "defaults() has 'board_access_key' key" );
ok( isset( $defaults['board_access_key'] ) && '' === $defaults['board_access_key'], "'board_access_key' defaults to '' (extra gate off)" );
ok( method_exists( 'DoughBoss_Settings', 'board_access_key' ) && '' === DoughBoss_Settings::board_access_key(), 'DoughBoss_Settings::board_access_key() reads \'\' with no option set' );
ok( method_exists( 'DoughBoss_Settings', 'verify_board_access_key' ), 'Order Board key has a central verifier' );

$board_test_key = 'BoardKey23456789ABCDEFGH';
update_option( DoughBoss_Settings::OPTION_KEY, array( 'board_access_key' => hash( 'sha256', $board_test_key ) ) );
$board_controller = new DoughBoss_REST_Controller( new DoughBoss_Cart() );
$GLOBALS['__db_caps_override'] = array( 'manage_doughboss_kds' );
ok( true === $board_controller->verify_board_access( new WP_REST_Request( array(), array( 'X-DoughBoss-Board-Key' => $board_test_key ) ) ), 'correct Order Board key unlocks KDS REST access' );
ok( is_wp_error( $board_controller->verify_board_access( new WP_REST_Request( array(), array( 'X-DoughBoss-Board-Key' => 'wrong-key' ) ) ) ), 'wrong Order Board key blocks KDS REST access' );
$GLOBALS['__db_caps_override'] = null;
update_option( DoughBoss_Settings::OPTION_KEY, array() );

// 3d. Management oversight surface: shop/location filtering on the admin
// order query and an honest paid-vs-gross split in reporting. These are
// core (always-on) views, so assert the query paths execute with no fatal
// against the stub $wpdb and keep their return contracts.
section( 'Management oversight' );
try {
	$oversight_q = DoughBoss_Order::query( array( 'location_id' => 2 ) );
	ok(
		is_array( $oversight_q ) && array_key_exists( 'items', $oversight_q ) && array_key_exists( 'total', $oversight_q ),
		'DoughBoss_Order::query() accepts location_id and keeps the {items,total} contract'
	);
} catch ( Throwable $e ) {
	ok( false, 'DoughBoss_Order::query( location_id ) threw: ' . $e->getMessage() );
}
try {
	$oversight_s = DoughBoss_Reports::summary( '2026-01-01', '2026-01-31', 1 );
	$s_keys      = array( 'revenue', 'orders', 'aov', 'paid_revenue', 'paid_orders' );
	$s_ok        = is_array( $oversight_s );
	foreach ( $s_keys as $k ) {
		$s_ok = $s_ok && array_key_exists( $k, $oversight_s );
	}
	ok( $s_ok, 'DoughBoss_Reports::summary() returns gross + paid split (revenue/orders/aov/paid_revenue/paid_orders)' );
} catch ( Throwable $e ) {
	ok( false, 'DoughBoss_Reports::summary() threw: ' . $e->getMessage() );
}
ok( method_exists( 'DoughBoss_Reports', 'payment_mix' ), 'DoughBoss_Reports::payment_mix() exists (paid/unpaid/refunded split)' );
ok( method_exists( 'DoughBoss_Reports', 'location_breakdown' ), 'DoughBoss_Reports::location_breakdown() exists (per-shop oversight)' );
try {
	ok( is_array( DoughBoss_Reports::payment_mix( '2026-01-01', '2026-01-31', 1 ) ), 'payment_mix() executes with a location filter, no fatal' );
	ok( is_array( DoughBoss_Reports::location_breakdown( '2026-01-01', '2026-01-31' ) ), 'location_breakdown() executes with no fatal' );
} catch ( Throwable $e ) {
	ok( false, 'reports oversight queries threw: ' . $e->getMessage() );
}
try {
	$tb = DoughBoss_Reports::today_bounds();
	ok(
		is_array( $tb ) && 2 === count( $tb )
			&& preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $tb[0] )
			&& preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $tb[1] )
			&& strtotime( $tb[0] ) < strtotime( $tb[1] ),
		'today_bounds() returns an ordered UTC datetime pair (site-local day)'
	);
} catch ( Throwable $e ) {
	ok( false, 'DoughBoss_Reports::today_bounds() threw: ' . $e->getMessage() );
}

// 4. REST surface registered routes.
section( 'REST surface' );
$routes = $GLOBALS['__db_rest'];
ok( count( $routes ) > 0, 'REST routes registered (' . count( $routes ) . ' routes)' );
ok( in_array( 'doughboss/v1/config', $routes, true ) || (bool) preg_grep( '#doughboss/v1/config#', $routes ), 'GET /config route present' );
ok( (bool) preg_grep( '#doughboss/v1/menu#', $routes ), '/menu route present' );
ok( (bool) preg_grep( '#doughboss/v1/checkout#', $routes ), '/checkout route present' );
ok( (bool) preg_grep( '#doughboss/v1/admin/catering$#', $routes ), 'GET /admin/catering route present' );
$payment_route = $GLOBALS['__db_rest_args']['doughboss/v1/payment-intent'] ?? array();
$payment_args  = isset( $payment_route['args'] ) ? $payment_route['args'] : array();
ok( isset( $payment_args['location_id'] ), 'POST /payment-intent declares location_id validation' );
$remove_voucher_route = $GLOBALS['__db_rest_args']['doughboss/v1/cart/remove-voucher'] ?? array();
$remove_voucher_args  = isset( $remove_voucher_route['args'] ) ? $remove_voucher_route['args'] : array();
ok( ! isset( $remove_voucher_args['location_id'] ), 'POST /cart/remove-voucher does not expose unrelated location_id' );
$status_route = $GLOBALS['__db_rest_args']['doughboss/v1/admin/order/(?P<id>\d+)/status'] ?? array();
$status_args  = isset( $status_route['args'] ) ? $status_route['args'] : array();
$accept_route = $GLOBALS['__db_rest_args']['doughboss/v1/admin/order/(?P<id>\d+)/accept'] ?? array();
$accept_args  = isset( $accept_route['args'] ) ? $accept_route['args'] : array();
ok( ! empty( $status_args['expected_version']['required'] ), 'status update requires expected_version' );
ok( ! empty( $status_args['event_key']['required'] ), 'status update requires an idempotency event_key' );
ok( ! empty( $accept_args['expected_version']['required'] ), 'accept requires expected_version' );
ok( ! empty( $accept_args['event_key']['required'] ), 'accept requires an idempotency event_key' );
$GLOBALS['__db_caps_override'] = array( 'manage_doughboss_kds' );
$kds_cancel = $board_controller->admin_update_status(
	new WP_REST_Request(
		array( 'id' => 1, 'status' => 'cancelled', 'expected_version' => 1, 'event_key' => 'smoke:kds-cancel', 'reason_code' => 'staff_cancelled' )
	)
);
ok( is_wp_error( $kds_cancel ) && 'doughboss_cancel_forbidden' === $kds_cancel->get_error_code(), 'kitchen-only account cannot cancel orders' );
$GLOBALS['__db_caps_override'] = null;
$board_routes = array(
	'doughboss/v1/admin/orders',
	'doughboss/v1/admin/order/(?P<id>\d+)/status',
	'doughboss/v1/admin/order/(?P<id>\d+)/ack',
	'doughboss/v1/admin/order/(?P<id>\d+)/accept',
);
foreach ( $board_routes as $board_route ) {
	$permission = $GLOBALS['__db_rest_args'][ $board_route ]['permission_callback'] ?? null;
	ok( is_array( $permission ) && isset( $permission[1] ) && 'verify_board_access' === $permission[1], $board_route . ' requires the board key verifier' );
}
// A real count check, not just ">0", so a route silently failing to register
// would fail this. Bumped to 47 with the /tyro-webhook, /catering/tyro-webhook
// and /pay/tyro-test routes added for the Tyro payment gateway backend.
ok( 47 === count( $routes ), 'REST route count reflects Tyro route additions (' . count( $routes ) . ' routes, expected 47)' );

// 5. Storefront shortcodes registered.
section( 'Shortcodes' );
foreach ( array( 'doughboss_menu', 'doughboss_builder', 'doughboss_cart', 'doughboss_order_tracking' ) as $sc ) {
	ok( isset( $GLOBALS['__db_shortcodes'][ $sc ] ), "[$sc] registered" );
}

// 6. Menu CPT registered.
section( 'Post types' );
ok( in_array( 'doughboss_item', $GLOBALS['__db_posttypes'], true ), 'doughboss_item CPT registered' );

// 6b. Roles & capabilities. The stub's add_role()/get_role() now keep real
// WP_Role-like objects with has_cap()/add_cap(), so we can exercise
// DoughBoss_Activator::add_capabilities() and assert on the resulting role
// capability sets rather than just "didn't throw". Covers both the existing
// doughboss_kitchen role (previously untested) and the new doughboss_manager
// role, consistently.
section( 'Roles & capabilities' );
try {
	DoughBoss_Activator::add_capabilities();
	ok( true, 'DoughBoss_Activator::add_capabilities() ran with no fatal' );
} catch ( Throwable $e ) {
	ok( false, 'DoughBoss_Activator::add_capabilities() threw: ' . $e->getMessage() );
}

$kitchen = get_role( 'doughboss_kitchen' );
ok( null !== $kitchen, 'doughboss_kitchen role exists after add_capabilities()' );
if ( $kitchen ) {
	ok( $kitchen->has_cap( 'read' ), 'doughboss_kitchen has read' );
	ok( $kitchen->has_cap( 'manage_doughboss_kds' ), 'doughboss_kitchen has manage_doughboss_kds' );
	ok( $kitchen->has_cap( 'redeem_doughboss_vouchers' ), 'doughboss_kitchen has redeem_doughboss_vouchers' );
	ok( ! $kitchen->has_cap( 'manage_doughboss' ), 'doughboss_kitchen does NOT have manage_doughboss (low-privilege boundary)' );
}

$manager = get_role( 'doughboss_manager' );
ok( null !== $manager, 'doughboss_manager role exists after add_capabilities()' );
if ( $manager ) {
	ok( $manager->has_cap( 'read' ), 'doughboss_manager has read' );
	ok( $manager->has_cap( 'manage_doughboss' ), 'doughboss_manager has manage_doughboss' );
	ok( $manager->has_cap( 'manage_doughboss_kds' ), 'doughboss_manager has manage_doughboss_kds' );
	ok( $manager->has_cap( 'redeem_doughboss_vouchers' ), 'doughboss_manager has redeem_doughboss_vouchers' );
	ok( 4 === count( $manager->capabilities ), 'doughboss_manager has exactly the 4 expected capabilities' );
}

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

// 7b. Single-location / pickup-only mode default (1.10.0). This asserts the
// new setting exists in defaults and defaults on — the money-path invariant
// that a fresh install lands in pickup-only Revesby mode.
section( 'Single-location mode' );
$defs = DoughBoss_Settings::defaults();
ok( isset( $defs['single_location_mode'] ) && 1 === (int) $defs['single_location_mode'],
	'single_location_mode default is 1 (pickup-only Revesby launch scope)' );
ok( isset( $defs['enable_delivery'] ) && 0 === (int) $defs['enable_delivery'],
	'enable_delivery default is 0' );

// 8. POSPal signature invariant. The signature format is a wire-protocol contract
// with POSPal's Open Platform — strtoupper(md5(appKey . rawBody)) — so it needs a
// known-vector test that breaks loudly if the algorithm ever drifts. Anything
// else in the transport can move; this must not.
section( 'POSPal signature invariant' );
$expected_sig = strtoupper( md5( 'demoKey' . '{"appId":"demoApp","x":1}' ) );
ok( DoughBoss_POSPal::sign( 'demoKey', '{"appId":"demoApp","x":1}' ) === $expected_sig,
	'DoughBoss_POSPal::sign() matches strtoupper(md5(appKey . rawBody))' );
ok( 32 === strlen( DoughBoss_POSPal::sign( 'k', 'body' ) ), 'signature is 32 hex chars' );
ok( DoughBoss_POSPal::sign( 'k', 'body' ) === strtoupper( DoughBoss_POSPal::sign( 'k', 'body' ) ), 'signature is uppercase' );

// 8b. Voucher redeem invariants (audit for step 5 of the POSPal hardening).
// The POSPal grant/revoke code is best-effort by design — WordPress owns the
// redemption transition — so this asserts the WP-side safety net rather than
// the POSPal mirror: atomic redeem, revert_redemption() present, and the
// POSPal_Sync hook gated behind pospal_grant_enabled().
section( 'Voucher redeem invariants' );
ok( method_exists( 'DoughBoss_Voucher', 'redeem' ), 'DoughBoss_Voucher::redeem() present' );
ok( method_exists( 'DoughBoss_Voucher', 'revert_redemption' ), 'DoughBoss_Voucher::revert_redemption() present (checkout-failure revert path)' );
ok( method_exists( 'DoughBoss_Voucher', 'redemption_by_key' ), 'DoughBoss_Voucher::redemption_by_key() present (idempotency lookup)' );
ok( class_exists( 'DoughBoss_POSPal_Sync' ), 'DoughBoss_POSPal_Sync class exists' );
ok( method_exists( 'DoughBoss_POSPal_Sync', 'on_voucher_claimed' ), 'grant hook (on_voucher_claimed) present' );
ok( method_exists( 'DoughBoss_POSPal_Sync', 'on_voucher_redeemed' ), 'revoke hook (on_voucher_redeemed) present' );

// 9. POSPal outbox: table declared + gated + retry curve constants sane.
section( 'POSPal outbox' );
ok( class_exists( 'DoughBoss_POSPal_Outbox' ), 'DoughBoss_POSPal_Outbox class exists' );
ok( method_exists( 'DoughBoss_POSPal_Outbox', 'ensure_dispatch_scheduled' ), 'POSPal outbox can re-arm durable dispatch after activation' );
ok( method_exists( 'DoughBoss_POSPal_Outbox', 'list_ambiguous_rows' ), 'ambiguous POSPal outcomes have a dedicated operator review query' );
$outbox_source = file_get_contents( DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss-pospal-outbox.php' );
ok( false !== strpos( $outbox_source, "last_error NOT IN ('ambiguous_network', 'ambiguous_in_flight')" ), 'bulk POSPal retry excludes ambiguous remote outcomes' );
ok( false !== strpos( $outbox_source, 'allow_ambiguous' ), 'ambiguous POSPal retry requires an explicit per-row release path' );
ok( false !== strpos( $outbox_source, 'expected_updated_at' ), 'ambiguous POSPal retry is bound to the reviewed attempt state' );
ok( defined( 'DoughBoss_POSPal_Outbox::MAX_ATTEMPTS' ) && 5 === DoughBoss_POSPal_Outbox::MAX_ATTEMPTS, 'MAX_ATTEMPTS === 5' );
ok( count( DoughBoss_POSPal_Outbox::BACKOFF_SECONDS ) >= DoughBoss_POSPal_Outbox::MAX_ATTEMPTS,
	'BACKOFF_SECONDS covers every attempt slot' );
ok( DoughBoss_POSPal_Outbox::BACKOFF_SECONDS[0] <= 300, 'first backoff <= 5 min' );

echo "\n=== RESULT: $pass passed · $fail failed ===\n";
exit( $fail > 0 ? 1 : 0 );
