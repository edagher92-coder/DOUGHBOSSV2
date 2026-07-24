<?php
/**
 * Real MariaDB rehearsal for checkout identity and payment uniqueness.
 *
 * @package DoughBoss\Tests
 */

$plugin = dirname( __DIR__ );
require_once $plugin . '/doughboss.php';
require_once $plugin . '/includes/class-doughboss-settings.php';
require_once $plugin . '/includes/class-doughboss-migrations.php';
require_once $plugin . '/includes/class-doughboss-locations.php';
require_once $plugin . '/includes/class-doughboss-cart.php';
require_once $plugin . '/includes/class-doughboss-order.php';
require_once $plugin . '/includes/class-doughboss-table-qr.php';
require_once $plugin . '/includes/class-doughboss-stripe.php';
require_once $plugin . '/includes/class-doughboss-tyro.php';
require_once $plugin . '/includes/class-doughboss-payment.php';
require_once $plugin . '/includes/class-doughboss-rest-controller.php';

global $wpdb;
$passed = 0;
$failed = 0;
function checkout_db_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) { ++$passed; echo "  ok   {$label}\n"; }
	else { ++$failed; echo "  FAIL {$label}\n"; }
}
function checkout_db_sql( $sql ) {
	global $wpdb;
	$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( false === $result ) {
		throw new RuntimeException( 'SQL failed: ' . $wpdb->last_error . ' :: ' . $sql );
	}
	return $result;
}
function checkout_race( $orders, $mode ) {
	global $wpdb;
	$base = sys_get_temp_dir() . '/doughboss-checkout-race-' . $mode . '-' . getmypid();
	$gate = $base . '.go';
	$children = array();
	for ( $i = 1; $i <= 2; ++$i ) {
		$pid = pcntl_fork();
		if ( -1 === $pid ) { throw new RuntimeException( 'pcntl_fork failed' ); }
		if ( 0 === $pid ) {
			$db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$db->query( 'SET SESSION innodb_lock_wait_timeout = 10' );
			file_put_contents( $base . '.ready.' . $i, 'ready' );
			$deadline = microtime( true ) + 10;
			while ( ! file_exists( $gate ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
			$key = 'checkout_key' === $mode ? str_repeat( 'a', 64 ) : str_repeat( (string) $i, 64 );
			$payment = 'checkout_key' === $mode ? 'pi_checkout_' . $i : 'pi_shared_race';
			$ok = $db->insert(
				$orders,
				array(
					'order_number' => 'DB-RACE-' . strtoupper( $mode ) . '-' . $i,
					'checkout_key' => $key,
					'payment_intent_id' => $payment,
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%s', '%s', '%s', '%s' )
			);
			file_put_contents( $base . '.result.' . $i, false === $ok ? 'duplicate' : 'created' );
			exit( 0 );
		}
		$children[] = $pid;
	}
	$deadline = microtime( true ) + 10;
	while ( ( ! file_exists( $base . '.ready.1' ) || ! file_exists( $base . '.ready.2' ) ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
	file_put_contents( $gate, 'go' );
	foreach ( $children as $pid ) { pcntl_waitpid( $pid, $status ); }
	$results = array(
		(string) file_get_contents( $base . '.result.1' ),
		(string) file_get_contents( $base . '.result.2' ),
	);
	foreach ( glob( $base . '.*' ) as $file ) { unlink( $file ); }
	sort( $results );
	return $results;
}
function checkout_order_race( $mode ) {
	global $wpdb;
	$base = sys_get_temp_dir() . '/doughboss-order-race-' . $mode . '-' . getmypid();
	$children = array();
	for ( $i = 1; $i <= 2; ++$i ) {
		$pid = pcntl_fork();
		if ( -1 === $pid ) { throw new RuntimeException( 'pcntl_fork failed' ); }
		if ( 0 === $pid ) {
			$db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$db->prefix = $wpdb->prefix;
			$db->query( 'SET SESSION innodb_lock_wait_timeout = 10' );
			$GLOBALS['wpdb'] = $db;
			file_put_contents( $base . '.ready.' . $i, 'ready' );
			$deadline = microtime( true ) + 10;
			while ( ! file_exists( $base . '.go' ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
			$key = 'checkout' === $mode ? str_repeat( 'd', 64 ) : str_repeat( (string) ( $i + 2 ), 64 );
			$data = array(
				'order_type' => 'pickup', 'location_id' => 0, 'customer_name' => 'Race Test',
				'customer_email' => 'race-' . $mode . '@example.test', 'customer_phone' => '0400000000',
				'address' => '', 'notes' => '', 'subtotal' => 20, 'tax' => 1.82, 'delivery_fee' => 0,
				'total' => 20, 'discount' => 0, 'voucher_code' => '', 'checkout_key' => $key,
				'payment_status' => 'payment' === $mode ? 'paid' : 'unpaid',
				'payment_method' => 'payment' === $mode ? 'stripe' : '',
				'payment_intent_id' => 'payment' === $mode ? 'pi_runtime_race' : '',
			);
			$lines = array( array( 'item_id' => 1, 'name' => 'Race Pizza', 'size' => '', 'toppings' => array(), 'quantity' => 1, 'unit_price' => 20, 'line_total' => 20 ) );
			$result = DoughBoss_Order::create( $data, $lines );
			$out = is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : $result;
			file_put_contents( $base . '.result.' . $i, wp_json_encode( $out ) );
			exit( 0 );
		}
		$children[] = $pid;
	}
	$deadline = microtime( true ) + 10;
	while ( ( ! file_exists( $base . '.ready.1' ) || ! file_exists( $base . '.ready.2' ) ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
	file_put_contents( $base . '.go', 'go' );
	foreach ( $children as $pid ) { pcntl_waitpid( $pid, $status ); }
	$results = array(
		json_decode( (string) file_get_contents( $base . '.result.1' ), true ),
		json_decode( (string) file_get_contents( $base . '.result.2' ), true ),
	);
	foreach ( glob( $base . '.*' ) as $file ) { unlink( $file ); }
	return $results;
}

echo "=== DoughBoss MariaDB checkout-integrity rehearsal ===\n";
$orders = $wpdb->prefix . 'doughboss_orders';
DoughBoss_Activator::create_tables();

// Reconstruct the 1.12 payment contract without touching other order fields.
checkout_db_sql( "ALTER TABLE {$orders} DROP INDEX checkout_key, DROP INDEX payment_intent_id, DROP COLUMN checkout_key, MODIFY payment_intent_id varchar(64) NOT NULL DEFAULT ''" );
$wpdb->insert( $orders, array( 'order_number' => 'DB-LEGACY-UNPAID', 'payment_intent_id' => '' ) );
$wpdb->insert( $orders, array( 'order_number' => 'DB-LEGACY-PAID', 'payment_intent_id' => 'pi_legacy_unique' ) );
update_option( 'doughboss_db_version', '1.12.0' );
delete_option( 'doughboss_migration_error' );
DoughBoss_Migrations::run();

checkout_db_ok( '1.16.0' === get_option( 'doughboss_db_version' ), 'clean 1.12 fixture advances through checkout, table-QR, payment-attempt, and POSPal-reference storage' );
checkout_db_ok( DoughBoss_Activator::checkout_storage_ready(), 'checkout columns and exact unique indexes are ready' );
checkout_db_ok( DoughBoss_Activator::table_qr_storage_ready(), 'table QR tables, snapshots, and exact unique indexes are ready' );
checkout_db_ok( DoughBoss_Activator::payment_storage_ready(), 'payment attempts, webhook events, and location mappings are ready' );
checkout_db_ok( DoughBoss_Activator::pospal_outbox_storage_ready(), 'POSPal outbox retains an indexed stable remote reference' );
checkout_db_ok( null === $wpdb->get_var( "SELECT payment_intent_id FROM {$orders} WHERE order_number = 'DB-LEGACY-UNPAID'" ), 'legacy blank payment reference becomes NULL' );
checkout_db_ok( 'pi_legacy_unique' === $wpdb->get_var( "SELECT payment_intent_id FROM {$orders} WHERE order_number = 'DB-LEGACY-PAID'" ), 'real payment reference is preserved' );

// A QR code is a public bearer value, but only its SHA-256 hash is persisted.
$locations = $wpdb->prefix . 'doughboss_locations';
$wpdb->insert( $locations, array( 'name' => 'Revesby QR Test', 'slug' => 'revesby-qr-test', 'is_active' => 1 ) );
$location_id = (int) $wpdb->insert_id;
$issued = DoughBoss_Table_QR::create_table( $location_id, '12', 'Dining room', home_url( '/order/' ) );
checkout_db_ok( ! is_wp_error( $issued ) && ! empty( $issued['code'] ), 'manager can issue a table QR bearer code once' );
$stored_hash = $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$wpdb->prefix}doughboss_table_qr_codes WHERE id = %d", (int) $issued['qr_code_id'] ) );
checkout_db_ok( hash( 'sha256', $issued['code'] ) === $stored_hash && $issued['code'] !== $stored_hash, 'database stores only the QR token hash' );
$table_cart = new DoughBoss_Cart();
$started = DoughBoss_Table_QR::start_from_code( $issued['code'], $table_cart );
$context = is_wp_error( $started ) ? $started : DoughBoss_Table_QR::current_context( $table_cart->get_token() );
checkout_db_ok( ! is_wp_error( $context ) && 12 === (int) $context['table_label'] && $location_id === (int) $context['location_id'], 'scan locks a fresh cart to the authoritative store and table' );
$rotated = DoughBoss_Table_QR::issue_code( (int) $issued['table_id'] );
$old_context = DoughBoss_Table_QR::current_context( $table_cart->get_token() );
checkout_db_ok( ! is_wp_error( $rotated ) && is_wp_error( $old_context ), 'QR rotation immediately invalidates the old table session' );

// The REST money path derives location/type/table from the server session and
// ignores forged pickup/delivery fields from the browser.
$locked_cart = new DoughBoss_Cart();
$locked_start = DoughBoss_Table_QR::start_from_code( $rotated['code'], $locked_cart );
$locked_cart->add( array( 'type' => 'menu', 'item_id' => 1, 'name' => 'QR Pizza', 'size' => '', 'toppings' => array(), 'unit_price' => 20, 'quantity' => 1 ) );
$controller = new DoughBoss_REST_Controller( $locked_cart );
$request = new WP_REST_Request( 'POST', '/' . DOUGHBOSS_REST_NAMESPACE . '/checkout' );
$request->set_header( 'Idempotency-Key', 'table-qr-forgery-test-0001' );
foreach ( array( 'order_type' => 'delivery', 'location_id' => 999999, 'customer_name' => 'Table Guest', 'customer_email' => 'table-guest@example.test', 'customer_phone' => '0400000000', 'address' => 'Forged address' ) as $key => $value ) {
	$request->set_param( $key, $value );
}
$response = $controller->checkout( $request );
$payload = is_wp_error( $response ) ? array() : $response->get_data();
$locked_order = ! empty( $payload['order_number'] ) ? DoughBoss_Order::get_by_number( $payload['order_number'] ) : null;
checkout_db_ok( $locked_order && 'dine_in' === $locked_order->order_type && $location_id === (int) $locked_order->location_id && '12' === (string) $locked_order->table_label, 'forged browser fulfilment fields cannot replace the server-bound table route' );

// A session that is rotated after cart creation must fail before order creation.
$revoked_cart = new DoughBoss_Cart();
DoughBoss_Table_QR::start_from_code( $rotated['code'], $revoked_cart );
$revoked_cart->add( array( 'type' => 'menu', 'item_id' => 1, 'name' => 'Revoked QR Pizza', 'size' => '', 'toppings' => array(), 'unit_price' => 20, 'quantity' => 1 ) );
DoughBoss_Table_QR::issue_code( (int) $issued['table_id'] );
$revoked_request = new WP_REST_Request( 'POST', '/' . DOUGHBOSS_REST_NAMESPACE . '/checkout' );
$revoked_request->set_header( 'Idempotency-Key', 'table-qr-revoked-test-0001' );
foreach ( array( 'order_type' => 'pickup', 'location_id' => $location_id, 'customer_name' => 'Revoked Guest', 'customer_email' => 'revoked@example.test', 'customer_phone' => '0400000001' ) as $key => $value ) {
	$revoked_request->set_param( $key, $value );
}
$controller = new DoughBoss_REST_Controller( $revoked_cart );
$revoked_response = $controller->checkout( $revoked_request );
checkout_db_ok( is_wp_error( $revoked_response ) && 'doughboss_table_session_expired' === $revoked_response->get_error_code(), 'revoked table session blocks checkout instead of falling back to pickup' );

$wpdb->insert( $orders, array( 'order_number' => 'DB-UNPAID-2', 'payment_intent_id' => null, 'checkout_key' => str_repeat( 'b', 64 ) ) );
$wpdb->insert( $orders, array( 'order_number' => 'DB-UNPAID-3', 'payment_intent_id' => null, 'checkout_key' => str_repeat( 'c', 64 ) ) );
checkout_db_ok( 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders} WHERE order_number IN ('DB-UNPAID-2','DB-UNPAID-3')" ), 'multiple unpaid orders coexist with NULL payment references' );

$same_key = checkout_race( $orders, 'checkout_key' );
checkout_db_ok( array( 'created', 'duplicate' ) === $same_key, 'concurrent same-key inserts produce exactly one order' );
$same_payment = checkout_race( $orders, 'payment_intent' );
checkout_db_ok( array( 'created', 'duplicate' ) === $same_payment, 'concurrent different-key inserts cannot reuse one payment' );

$runtime_checkout = checkout_order_race( 'checkout' );
$checkout_ids = array_unique( array_map( static function ( $row ) { return isset( $row['order_id'] ) ? (int) $row['order_id'] : 0; }, $runtime_checkout ) );
$checkout_replays = array_sum( array_map( static function ( $row ) { return ! empty( $row['replayed'] ) ? 1 : 0; }, $runtime_checkout ) );
checkout_db_ok( 1 === count( array_filter( $checkout_ids ) ) && 1 === $checkout_replays, 'Order::create returns one winner and one same-key replay' );
checkout_db_ok( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders} WHERE customer_email = 'race-checkout@example.test'" ), 'same-key runtime race stores one order' );

$runtime_payment = checkout_order_race( 'payment' );
$payment_ids = array_unique( array_map( static function ( $row ) { return isset( $row['order_id'] ) ? (int) $row['order_id'] : 0; }, $runtime_payment ) );
$payment_replays = array_values( array_filter( $runtime_payment, static function ( $row ) { return ! empty( $row['replayed'] ); } ) );
checkout_db_ok( 1 === count( array_filter( $payment_ids ) ) && 1 === count( $payment_replays ) && 'payment_intent_id' === $payment_replays[0]['replayed_by'], 'Order::create replays the payment owner for different checkout keys' );
checkout_db_ok( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders} WHERE customer_email = 'race-payment@example.test'" ), 'same-payment runtime race stores one order' );

// Historical duplicate paid orders fail closed and remain untouched.
checkout_db_sql( "ALTER TABLE {$orders} DROP INDEX checkout_key, DROP INDEX payment_intent_id, DROP COLUMN checkout_key" );
$wpdb->insert( $orders, array( 'order_number' => 'DB-DUPLICATE-1', 'payment_intent_id' => 'pi_historical_duplicate' ) );
$wpdb->insert( $orders, array( 'order_number' => 'DB-DUPLICATE-2', 'payment_intent_id' => 'pi_historical_duplicate' ) );
$before = $wpdb->get_results( "SELECT id,order_number,payment_intent_id FROM {$orders} WHERE payment_intent_id = 'pi_historical_duplicate' ORDER BY id", ARRAY_A );
update_option( 'doughboss_db_version', '1.12.0' );
delete_option( 'doughboss_migration_error' );
DoughBoss_Migrations::run();
$after = $wpdb->get_results( "SELECT id,order_number,payment_intent_id FROM {$orders} WHERE payment_intent_id = 'pi_historical_duplicate' ORDER BY id", ARRAY_A );
checkout_db_ok( '1.12.0' === get_option( 'doughboss_db_version' ), 'duplicate paid references block the version checkpoint' );
checkout_db_ok( $before === $after, 'duplicate paid order evidence is not rewritten or deleted' );
checkout_db_ok( false !== strpos( (string) get_option( 'doughboss_migration_error' ), 'Duplicate payment references' ), 'operator-visible error names the reconciliation blocker' );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
