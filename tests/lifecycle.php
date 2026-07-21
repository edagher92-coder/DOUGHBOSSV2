<?php
/**
 * Focused stateful tests for the order transition transaction.
 *
 * @package DoughBoss\Tests
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-order.php';

class DoughBoss_Lifecycle_DB_Stub extends DB_Stub {
	public $orders = array();
	public $events = array();
	public $fail_event_insert = false;
	private $snapshot = null;

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		foreach ( $args as $arg ) {
			$replacement = is_numeric( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query = preg_replace( '/%[dsf]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function query( $query ) {
		if ( 'START TRANSACTION' === $query ) {
			$this->snapshot = array( 'orders' => $this->orders, 'events' => $this->events );
		} elseif ( 'ROLLBACK' === $query && $this->snapshot ) {
			$this->orders = $this->snapshot['orders'];
			$this->events = $this->snapshot['events'];
			$this->snapshot = null;
		} elseif ( 'COMMIT' === $query ) {
			$this->snapshot = null;
		}
		return 0;
	}

	public function get_row( $query = null, $output = OBJECT, $offset = 0 ) {
		if ( false !== strpos( $query, 'doughboss_order_events' ) && preg_match( "/event_key = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->events as $event ) {
				if ( $event['event_key'] === $match[1] ) { return (object) $event; }
			}
			return null;
		}
		if ( false !== strpos( $query, 'doughboss_orders' ) && preg_match( '/WHERE id = (\d+)/', $query, $match ) ) {
			$id = (int) $match[1];
			return isset( $this->orders[ $id ] ) ? (object) $this->orders[ $id ] : null;
		}
		return null;
	}

	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		$id = isset( $where['id'] ) ? (int) $where['id'] : 0;
		if ( ! isset( $this->orders[ $id ] ) ) { return 0; }
		foreach ( $where as $field => $value ) {
			if ( ! isset( $this->orders[ $id ][ $field ] ) || (string) $this->orders[ $id ][ $field ] !== (string) $value ) { return 0; }
		}
		$this->orders[ $id ] = array_merge( $this->orders[ $id ], $data );
		return 1;
	}

	public function insert( $table, $data, $formats = null ) {
		if ( false !== strpos( $table, 'doughboss_order_events' ) ) {
			if ( $this->fail_event_insert ) { return false; }
			foreach ( $this->events as $event ) {
				if ( $event['event_key'] === $data['event_key'] || (int) $event['order_id'] === (int) $data['order_id'] && (int) $event['order_version'] === (int) $data['order_version'] ) { return false; }
			}
			$this->events[] = $data;
		}
		return 1;
	}
}

$passed = 0;
$failed = 0;
function lifecycle_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) { ++$passed; echo "  ok   {$label}\n"; }
	else { ++$failed; echo "  FAIL {$label}\n"; }
}

echo "=== DoughBoss lifecycle transaction test ===\n";
$db = new DoughBoss_Lifecycle_DB_Stub();
$GLOBALS['wpdb'] = $db;
update_option( 'doughboss_db_version', '1.11.0' );
$db->orders[1] = array(
	'id' => 1, 'status' => 'pending', 'version' => 1, 'order_type' => 'pickup',
	'payment_status' => 'unpaid', 'timezone_snapshot' => '',
);

$hook_count = 0;
add_action( 'doughboss_order_status_changed', function () use ( &$hook_count ) { ++$hook_count; }, 10, 2 );
$accepted = DoughBoss_Order::transition( 1, 'confirmed', array(
	'expected_version' => 1, 'event_key' => 'test:accept:1', 'actor_type' => 'staff', 'actor_id' => 7, 'eta_minutes' => 20,
) );
lifecycle_ok( ! is_wp_error( $accepted ), 'pending to confirmed succeeds' );
lifecycle_ok( 2 === $db->orders[1]['version'], 'successful transition increments version' );
lifecycle_ok( 'confirmed' === $db->orders[1]['status'], 'successful transition stores target status' );
lifecycle_ok( ! empty( $db->orders[1]['promised_ready_from_utc'] ) && ! empty( $db->orders[1]['promised_ready_by_utc'] ), 'acceptance stores an absolute ready window' );
lifecycle_ok( 1 === count( $db->events ) && 2 === $db->events[0]['order_version'], 'transition appends exactly one versioned event' );
lifecycle_ok( 1 === $hook_count, 'status hook fires once after commit' );

$replay = DoughBoss_Order::transition( 1, 'confirmed', array(
	'expected_version' => 1, 'event_key' => 'test:accept:1', 'actor_type' => 'staff', 'actor_id' => 7, 'eta_minutes' => 20,
) );
lifecycle_ok( ! is_wp_error( $replay ) && ! empty( $replay['replayed'] ), 'same event key is an idempotent replay' );
lifecycle_ok( 1 === count( $db->events ) && 1 === $hook_count, 'replay emits no event or hook' );

$skip = DoughBoss_Order::transition( 1, 'ready', array(
	'expected_version' => 2, 'event_key' => 'test:skip:1', 'actor_type' => 'staff', 'actor_id' => 7,
) );
lifecycle_ok( is_wp_error( $skip ) && 'doughboss_invalid_transition' === $skip->get_error_code(), 'confirmed cannot skip directly to ready' );

$cooking = DoughBoss_Order::transition( 1, 'preparing', array(
	'expected_version' => 2, 'event_key' => 'test:cooking:1', 'actor_type' => 'staff', 'actor_id' => 7,
) );
lifecycle_ok( ! is_wp_error( $cooking ) && 3 === $db->orders[1]['version'], 'confirmed to preparing succeeds' );
lifecycle_ok( ! empty( $db->orders[1]['cooking_started_at'] ), 'starting preparation owns the cooking timestamp' );

$stale = DoughBoss_Order::transition( 1, 'ready', array(
	'expected_version' => 2, 'event_key' => 'test:stale:1', 'actor_type' => 'staff', 'actor_id' => 9,
) );
lifecycle_ok( is_wp_error( $stale ) && 'doughboss_stale_order' === $stale->get_error_code(), 'stale staff screen loses the compare-and-set race' );
lifecycle_ok( 2 === count( $db->events ), 'stale write creates no event' );

$db->orders[2] = array(
	'id' => 2, 'status' => 'pending', 'version' => 1, 'order_type' => 'pickup',
	'payment_status' => 'paid', 'timezone_snapshot' => '',
);
$paid_cancel = DoughBoss_Order::transition( 2, 'cancelled', array(
	'expected_version' => 1, 'event_key' => 'test:paid-cancel:2', 'actor_type' => 'staff', 'actor_id' => 7, 'reason_code' => 'staff_cancelled',
) );
lifecycle_ok( is_wp_error( $paid_cancel ) && 'doughboss_refund_required' === $paid_cancel->get_error_code(), 'paid order cannot be cancelled before refund' );

$db->orders[3] = array(
	'id' => 3, 'status' => 'pending', 'version' => 1, 'order_type' => 'pickup',
	'payment_status' => 'unpaid', 'timezone_snapshot' => '',
);
$db->fail_event_insert = true;
$atomic = DoughBoss_Order::transition( 3, 'confirmed', array(
	'expected_version' => 1, 'event_key' => 'test:event-fail:3', 'actor_type' => 'staff', 'actor_id' => 7, 'eta_minutes' => 15,
) );
lifecycle_ok( is_wp_error( $atomic ) && 'doughboss_transition_db' === $atomic->get_error_code(), 'event failure returns a server error' );
lifecycle_ok( 'pending' === $db->orders[3]['status'] && 1 === $db->orders[3]['version'], 'event failure rolls back status and version' );

echo "=== RESULT: {$passed} passed · {$failed} failed ===\n";
exit( $failed ? 1 : 0 );
