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
	public $fail_event = false;
	public $fail_conversion = false;
	public $fail_commit = false;
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
		if ( 'COMMIT' === $query ) { return $this->fail_commit ? false : 0; }
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
		if ( false !== strpos( $query, 'SELECT slot_id FROM wp_doughboss_capacity_holds' ) && false !== strpos( $query, "token_hash = '" . $this->hold['token_hash'] . "'" ) ) { return $this->hold['slot_id']; }
		if ( false !== strpos( $query, 'SELECT id FROM wp_doughboss_orders' ) && preg_match( '/WHERE id = (\d+) AND capacity_hold_id = (\d+)/', $query, $match ) ) {
			$id = (int) $match[1];
			return isset( $this->orders[ $id ] ) && (int) $this->orders[ $id ]['capacity_hold_id'] === (int) $match[2] ? $id : null;
		}
		return null;
	}
	public function get_row( $query = null, $output = OBJECT, $offset = 0 ) {
		if ( false !== strpos( $query, 'FROM wp_doughboss_capacity_slots' ) && false !== strpos( $query, 'FOR UPDATE' ) ) {
			$this->locks[] = 'slot';
			return (object) $this->slot;
		}
		if ( false !== strpos( $query, 'FROM wp_doughboss_capacity_holds' ) && false !== strpos( $query, 'FOR UPDATE' ) && false !== strpos( $query, "token_hash = '" . $this->hold['token_hash'] . "'" ) ) {
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
			if ( $this->fail_event ) { return false; }
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
		'delivery_fee' => 0, 'total' => 20, 'discount' => 0, 'voucher_code' => '', 'payment_status' => 'paid', 'capacity_hold_token' => $token,
	);
	$hash = DoughBoss_Capacity::cart_hash( $lines, array( 'location_id' => 1, 'order_type' => 'pickup', 'voucher' => '', 'total' => 20 ) );
	$ready = new DateTimeImmutable( '+2 hours', new DateTimeZone( 'UTC' ) );
	$db->slot = array(
		'id' => 9, 'location_id' => 1, 'order_type' => 'pickup', 'starts_at_utc' => '2027-07-15 09:00:00',
		'ends_at_utc' => $ready->modify( '+15 minutes' )->format( 'Y-m-d H:i:s' ), 'timezone_snapshot' => 'Australia/Sydney', 'planning_version' => 4,
	);
	$db->slot['starts_at_utc'] = $ready->format( 'Y-m-d H:i:s' );
	$db->hold = array(
		'id' => 12, 'slot_id' => 9, 'token_hash' => hash( 'sha256', $token ), 'cart_hash' => $hash, 'status' => 'held',
		'capacity_units' => 1, 'expires_at' => ( new DateTimeImmutable( '+30 minutes', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' ), 'order_id' => null,
	);
	return array( $db, $data, $lines );
}

echo "=== DoughBoss atomic capacity order test ===\n";
update_option( 'doughboss_db_version', '1.12.0' );
list( $db, $data, $lines ) = order_capacity_fixture();
$GLOBALS['wpdb'] = $db;
$created_hooks = 0;
add_action( 'doughboss_order_created', function () use ( &$created_hooks ) { ++$created_hooks; } );
$created = DoughBoss_Order::create( $data, $lines );
$order_id = $created['order_id'];
order_capacity_ok( 51 === $order_id && empty( $created['replayed'] ), 'scheduled order is created' );
order_capacity_ok( array( 'slot', 'hold' ) === $db->locks, 'lock order is slot then hold' );
order_capacity_ok( 'pending' === $db->orders[51]['status'], 'backend conversion does not bypass staff acceptance' );
order_capacity_ok( 12 === $db->orders[51]['capacity_hold_id'] && 1 === $db->orders[51]['capacity_units'], 'order stores its capacity allocation' );
order_capacity_ok( null === $db->orders[51]['fire_at_utc'], 'fire time waits for a snapshotted planning model' );
order_capacity_ok( 'converted' === $db->hold['status'] && 51 === $db->hold['order_id'], 'hold conversion commits with the order' );
order_capacity_ok( 'pending' === $db->events[0]['to_status'], 'creation event preserves pending lifecycle truth' );
order_capacity_ok( 1 === $created_hooks, 'new-order hook fires once after commit' );

$before = array( count( $db->orders ), count( $db->items ), count( $db->events ) );
$replay = DoughBoss_Order::create( $data, $lines );
order_capacity_ok( 51 === $replay['order_id'] && ! empty( $replay['replayed'] ), 'converted hold replay returns the linked order' );
order_capacity_ok( $before === array( count( $db->orders ), count( $db->items ), count( $db->events ) ), 'replay creates no duplicate order, item or event' );
order_capacity_ok( 1 === $created_hooks, 'replay fires no duplicate new-order hook' );
$db->fail_commit = true;
$commit_failure = DoughBoss_Order::create( $data, $lines );
order_capacity_ok( is_wp_error( $commit_failure ) && 'doughboss_db_error' === $commit_failure->get_error_code(), 'replay reports a failed transaction commit' );

list( $db, $data, $lines ) = order_capacity_fixture();
$GLOBALS['wpdb'] = $db;
$data['payment_status'] = 'unpaid';
$unpaid = DoughBoss_Order::create( $data, $lines );
order_capacity_ok( is_wp_error( $unpaid ) && 'doughboss_capacity_payment_required' === $unpaid->get_error_code(), 'unpaid checkout cannot confirm a capacity allocation' );
order_capacity_ok( array() === $db->orders && 'held' === $db->hold['status'], 'unpaid attempt writes nothing and leaves hold retryable' );

list( $db, $data, $lines ) = order_capacity_fixture();
$GLOBALS['wpdb'] = $db;
$data['capacity_hold_token'] = str_repeat( 'b', 64 );
$wrong_token = DoughBoss_Order::create( $data, $lines );
order_capacity_ok( is_wp_error( $wrong_token ) && 'doughboss_hold_missing' === $wrong_token->get_error_code(), 'wrong hold token writes nothing' );

list( $db, $data, $lines ) = order_capacity_fixture();
$GLOBALS['wpdb'] = $db;
$db->hold['expires_at'] = '2020-01-01 00:00:00';
$expired = DoughBoss_Order::create( $data, $lines );
order_capacity_ok( is_wp_error( $expired ) && 'doughboss_hold_expired' === $expired->get_error_code(), 'expired hold writes nothing' );

list( $db, $data, $lines ) = order_capacity_fixture();
$GLOBALS['wpdb'] = $db;
$db->hold['status'] = 'converted';
$db->hold['order_id'] = 999;
$dangling = DoughBoss_Order::create( $data, $lines );
order_capacity_ok( is_wp_error( $dangling ) && 'doughboss_hold_corrupt' === $dangling->get_error_code(), 'dangling converted hold fails closed' );

list( $db, $data, $lines ) = order_capacity_fixture();
$GLOBALS['wpdb'] = $db;
$db->fail_item = true;
$item_failure = DoughBoss_Order::create( $data, $lines );
order_capacity_ok( is_wp_error( $item_failure ), 'item failure rejects order creation' );
order_capacity_ok( array() === $db->orders && 'held' === $db->hold['status'], 'item failure rolls order back and leaves hold retryable' );

list( $db, $data, $lines ) = order_capacity_fixture();
$GLOBALS['wpdb'] = $db;
$db->fail_event = true;
$event_failure = DoughBoss_Order::create( $data, $lines );
order_capacity_ok( is_wp_error( $event_failure ), 'event failure rejects order creation' );
order_capacity_ok( array() === $db->orders && array() === $db->items && 'held' === $db->hold['status'], 'event failure rolls back order and items and leaves hold retryable' );

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
