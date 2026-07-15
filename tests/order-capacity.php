<?php
/**
 * Stateful tests for atomic hold-to-order conversion.
 *
 * @package DoughBoss\Tests
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-capacity.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-order.php';

class DoughBoss_Order_Capacity_DB_Stub extends DB_Stub {
	public $orders = array();
	public $items = array();
	public $events = array();
	public $hold;
	public $slot;
	public $fail_item = false;
	public $fail_conversion = false;
	public $locks = array();
	private $snapshot;

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$replacement = is_numeric( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query = preg_replace( '/%[dsf]/', $replacement, $query, 1 );
		}
		return $query;
	}
	public function query( $query ) {
		if ( 'START TRANSACTION' === $query ) {
			$this->snapshot = serialize( array( $this->orders, $this->items, $this->events, $this->hold ) );
			return 0;
		}
		if ( 'ROLLBACK' === $query ) {
			list( $this->orders, $this->items, $this->events, $this->hold ) = unserialize( $this->snapshot );
			return 0;
		}
		if ( 'COMMIT' === $query ) { return 0; }
		if ( false !== strpos( $query, "SET status = 'converted'" ) ) {
			if ( $this->fail_conversion || 'held' !== $this->hold['status'] ) { return 0; }
			preg_match( '/order_id = (\d+)/', $query, $match );
			$this->hold['status'] = 'converted';
			$this->hold['order_id'] = (int) $match[1];
			return 1;
		}
		return 0;
	}
	public function get_var( $query = null ) {
		if ( false !== strpos( $query, 'SELECT slot_id FROM wp_doughboss_capacity_holds' ) ) { return $this->hold['slot_id']; }
		return null;
	}
	public function get_row( $query = null, $output = OBJECT, $offset = 0 ) {
		if ( false !== strpos( $query, 'INNER JOIN wp_doughboss_locations' ) ) {
			$this->locks[] = 'slot';
			return (object) array_merge( $this->slot, array( 'prep_time_default' => 20 ) );
		}
		if ( false !== strpos( $query, 'FROM wp_doughboss_capacity_holds' ) && false !== strpos( $query, 'FOR UPDATE' ) ) {
			$this->locks[] = 'hold';
			return (object) $this->hold;
		}
		return null;
	}
	public function insert( $table, $data, $formats = null ) {
		if ( false !== strpos( $table, 'doughboss_orders' ) ) {
			$this->insert_id = 51;
			$data['id'] = 51;
			$this->orders[51] = $data;
			return 1;
		}
		if ( false !== strpos( $table, 'doughboss_order_items' ) ) {
			if ( $this->fail_item ) { return false; }
			$this->items[] = $data;
			return 1;
		}
		if ( false !== strpos( $table, 'doughboss_order_events' ) ) {
			$this->events[] = $data;
			return 1;
		}
		return 1;
	}
}

$passed = 0;
$failed = 0;
function order_capacity_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) { ++$passed; echo "  ok   {$label}\n"; }
	else { ++$failed; echo "  FAIL {$label}\n"; }
}
function order_capacity_fixture() {
	$db = new DoughBoss_Order_Capacity_DB_Stub();
	$token = str_repeat( 'a', 64 );
	$lines = array( array( 'type' => 'menu', 'item_id' => 3, 'name' => 'Pizza', 'size' => '', 'toppings' => array(), 'quantity' => 1, 'unit_price' => 20, 'line_total' => 20 ) );
	$data = array(
		'order_type' => 'pickup', 'location_id' => 1, 'customer_name' => 'Test', 'customer_email' => 'test@example.test',
		'customer_phone' => '0400000000', 'address' => '', 'notes' => '', 'subtotal' => 20, 'tax' => 1.82,
		'delivery_fee' => 0, 'total' => 20, 'discount' => 0, 'voucher_code' => '', 'capacity_hold_token' => $token,
	);
	$hash = DoughBoss_Capacity::cart_hash( $lines, array( 'location_id' => 1, 'order_type' => 'pickup', 'voucher' => '', 'total' => 20 ) );
	$db->slot = array(
		'id' => 9, 'location_id' => 1, 'order_type' => 'pickup', 'starts_at_utc' => '2027-07-15 09:00:00',
		'ends_at_utc' => '2027-07-15 09:15:00', 'timezone_snapshot' => 'Australia/Sydney', 'planning_version' => 4,
	);
	$db->hold = array(
		'id' => 12, 'slot_id' => 9, 'token_hash' => hash( 'sha256', $token ), 'cart_hash' => $hash, 'status' => 'held',
		'capacity_units' => 1, 'expires_at' => '2027-07-15 08:30:00', 'order_id' => null,
	);
	return array( $db, $data, $lines );
}

echo "=== DoughBoss atomic capacity order test ===\n";
update_option( 'doughboss_db_version', '1.12.0' );
list( $db, $data, $lines ) = order_capacity_fixture();
$GLOBALS['wpdb'] = $db;
$order_id = DoughBoss_Order::create( $data, $lines );
order_capacity_ok( 51 === $order_id, 'scheduled order is created' );
order_capacity_ok( array( 'slot', 'hold' ) === $db->locks, 'lock order is slot then hold' );
order_capacity_ok( 'confirmed' === $db->orders[51]['status'], 'capacity-backed order starts confirmed' );
order_capacity_ok( 12 === $db->orders[51]['capacity_hold_id'] && 1 === $db->orders[51]['capacity_units'], 'order stores its capacity allocation' );
order_capacity_ok( '2027-07-15 08:40:00' === $db->orders[51]['fire_at_utc'], 'fire projection uses ready time minus location prep time' );
order_capacity_ok( 'converted' === $db->hold['status'] && 51 === $db->hold['order_id'], 'hold conversion commits with the order' );
order_capacity_ok( 'confirmed' === $db->events[0]['to_status'], 'creation event records confirmed truth' );

$before = array( count( $db->orders ), count( $db->items ), count( $db->events ) );
$replay = DoughBoss_Order::create( $data, $lines );
order_capacity_ok( 51 === $replay, 'converted hold replay returns the linked order' );
order_capacity_ok( $before === array( count( $db->orders ), count( $db->items ), count( $db->events ) ), 'replay creates no duplicate order, item or event' );

list( $db, $data, $lines ) = order_capacity_fixture();
$GLOBALS['wpdb'] = $db;
$db->fail_item = true;
$item_failure = DoughBoss_Order::create( $data, $lines );
order_capacity_ok( is_wp_error( $item_failure ), 'item failure rejects order creation' );
order_capacity_ok( array() === $db->orders && 'held' === $db->hold['status'], 'item failure rolls order back and leaves hold retryable' );

list( $db, $data, $lines ) = order_capacity_fixture();
$GLOBALS['wpdb'] = $db;
$db->fail_conversion = true;
$conversion_failure = DoughBoss_Order::create( $data, $lines );
order_capacity_ok( is_wp_error( $conversion_failure ) && 'doughboss_hold_conversion' === $conversion_failure->get_error_code(), 'conversion race returns a controlled conflict' );
order_capacity_ok( array() === $db->orders && array() === $db->items && array() === $db->events && 'held' === $db->hold['status'], 'conversion failure rolls back order, items, event and hold' );

list( $db, $data, $lines ) = order_capacity_fixture();
$GLOBALS['wpdb'] = $db;
$lines[0]['quantity'] = 2;
$lines[0]['line_total'] = 40;
$changed = DoughBoss_Order::create( $data, $lines );
order_capacity_ok( is_wp_error( $changed ) && 'doughboss_hold_mismatch' === $changed->get_error_code(), 'changed cart cannot consume an old hold' );
order_capacity_ok( array() === $db->orders && 'held' === $db->hold['status'], 'cart mismatch writes nothing' );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
