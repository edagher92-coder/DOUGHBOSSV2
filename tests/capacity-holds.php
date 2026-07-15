<?php
/**
 * Stateful contract tests for transactional capacity holds.
 *
 * @package DoughBoss\Tests
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-capacity.php';

class DoughBoss_Capacity_DB_Stub extends DB_Stub {
	public $slots = array();
	public $holds = array();
	public $fail_insert = false;
	public $slot_lock_queries = 0;
	private $snapshot = null;

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$replacement = is_numeric( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query = preg_replace( '/%[dsf]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function query( $query ) {
		if ( 'START TRANSACTION' === $query ) {
			$this->snapshot = $this->holds;
			return 0;
		}
		if ( 'ROLLBACK' === $query ) {
			$this->holds = null !== $this->snapshot ? $this->snapshot : $this->holds;
			$this->snapshot = null;
			return 0;
		}
		if ( 'COMMIT' === $query ) {
			$this->snapshot = null;
			return 0;
		}
		if ( false !== strpos( $query, 'UPDATE wp_doughboss_capacity_holds' ) && preg_match( "/slot_id = (\d+).*expires_at <= '([^']+)'/", $query, $match ) ) {
			foreach ( $this->holds as &$hold ) {
				if ( (int) $hold['slot_id'] === (int) $match[1] && 'held' === $hold['status'] && $hold['expires_at'] <= $match[2] ) {
					$hold['status'] = 'expired';
				}
			}
			unset( $hold );
		}
		return 0;
	}

	public function get_row( $query = null, $output = OBJECT, $offset = 0 ) {
		if ( false !== strpos( $query, 'wp_doughboss_capacity_slots' ) && preg_match( '/WHERE id = (\d+) FOR UPDATE/', $query, $match ) ) {
			++$this->slot_lock_queries;
			$id = (int) $match[1];
			return isset( $this->slots[ $id ] ) ? (object) $this->slots[ $id ] : null;
		}
		if ( false !== strpos( $query, 'WHERE idempotency_key' ) && preg_match( "/idempotency_key = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->holds as $hold ) {
				if ( $hold['idempotency_key'] === $match[1] ) { return (object) $hold; }
			}
			return null;
		}
		if ( false !== strpos( $query, 'COUNT(*) AS orders_used' ) && preg_match( "/slot_id = (\d+).*expires_at > '([^']+)'/", $query, $match ) ) {
			$orders = 0;
			$units  = 0;
			foreach ( $this->holds as $hold ) {
				if ( (int) $hold['slot_id'] !== (int) $match[1] ) { continue; }
				if ( 'converted' === $hold['status'] || ( 'held' === $hold['status'] && $hold['expires_at'] > $match[2] ) ) {
					++$orders;
					$units += (int) $hold['capacity_units'];
				}
			}
			return (object) array( 'orders_used' => $orders, 'units_used' => $units );
		}
		return null;
	}

	public function insert( $table, $data, $formats = null ) {
		if ( $this->fail_insert ) { return false; }
		foreach ( $this->holds as $hold ) {
			if ( $hold['idempotency_key'] === $data['idempotency_key'] || $hold['token_hash'] === $data['token_hash'] ) { return false; }
		}
		$this->insert_id = count( $this->holds ) + 1;
		$data['id'] = $this->insert_id;
		$this->holds[] = $data;
		return 1;
	}
}

$passed = 0;
$failed = 0;
function hold_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) { ++$passed; echo "  ok   {$label}\n"; }
	else { ++$failed; echo "  FAIL {$label}\n"; }
}

echo "=== DoughBoss capacity hold test ===\n";
$db = new DoughBoss_Capacity_DB_Stub();
$GLOBALS['wpdb'] = $db;
$db->slots[7] = array(
	'id' => 7, 'location_id' => 1, 'order_type' => 'pickup',
	'starts_at_utc' => '2026-07-15 09:00:00', 'ends_at_utc' => '2026-07-15 09:15:00',
	'accepting_holds' => 1, 'order_capacity' => 2, 'unit_capacity' => 3,
);
$lines = array( array( 'type' => 'menu', 'item_id' => 10, 'size' => '', 'toppings' => array(), 'quantity' => 2, 'unit_price' => 12 ) );
$context = array( 'location_id' => 1, 'order_type' => 'pickup', 'total' => 24, 'hold_minutes' => 10 );
$now = new DateTimeImmutable( '2026-07-15 08:00:00Z' );

$first = DoughBoss_Capacity::create_hold( 7, $lines, $context, 'hold-request-00000001', $now );
hold_ok( ! is_wp_error( $first ), 'first hold succeeds' );
hold_ok( 1 === count( $db->holds ) && 2 === $db->holds[0]['capacity_units'], 'server computes and stores cart demand' );
hold_ok( $db->holds[0]['token_hash'] === hash( 'sha256', $first['hold_token'] ) && $db->holds[0]['token_hash'] !== $first['hold_token'], 'only the hold-token hash is stored' );
hold_ok( 1 === $db->slot_lock_queries, 'slot row is locked before capacity is checked' );

$replay = DoughBoss_Capacity::create_hold( 7, $lines, $context, 'hold-request-00000001', $now );
hold_ok( ! is_wp_error( $replay ) && ! empty( $replay['replayed'] ), 'same request is an idempotent replay' );
hold_ok( $first['hold_token'] === $replay['hold_token'] && 1 === count( $db->holds ), 'replay returns the same token without consuming capacity twice' );

$changed = $lines;
$changed[0]['quantity'] = 1;
$conflict = DoughBoss_Capacity::create_hold( 7, $changed, $context, 'hold-request-00000001', $now );
hold_ok( is_wp_error( $conflict ) && 'doughboss_hold_conflict' === $conflict->get_error_code(), 'same retry key with a changed cart conflicts' );

$full = DoughBoss_Capacity::create_hold( 7, $lines, $context, 'hold-request-00000002', $now );
hold_ok( is_wp_error( $full ) && 'doughboss_slot_full' === $full->get_error_code(), 'unit capacity rejects an over-capacity hold' );
hold_ok( 1 === count( $db->holds ), 'capacity rejection inserts no partial hold' );

$later = new DateTimeImmutable( '2026-07-15 08:11:00Z' );
$after_expiry = DoughBoss_Capacity::create_hold( 7, $lines, $context, 'hold-request-00000003', $later );
hold_ok( ! is_wp_error( $after_expiry ), 'expired hold releases capacity immediately without cron' );
hold_ok( 'expired' === $db->holds[0]['status'] && 2 === count( $db->holds ), 'expired row remains as audit history and new hold is stored' );

$before = count( $db->holds );
$db->fail_insert = true;
$failed_insert = DoughBoss_Capacity::create_hold( 7, array( array_merge( $lines[0], array( 'quantity' => 1 ) ) ), $context, 'hold-request-00000004', new DateTimeImmutable( '2026-07-15 08:22:00Z' ) );
hold_ok( is_wp_error( $failed_insert ) && 'doughboss_hold_storage' === $failed_insert->get_error_code(), 'storage failure returns a safe error' );
hold_ok( $before === count( $db->holds ), 'storage failure rolls back without a partial hold' );

$reordered = array(
	array( 'type' => 'menu', 'item_id' => 2, 'quantity' => 1, 'unit_price' => 8, 'toppings' => array() ),
	array( 'type' => 'menu', 'item_id' => 1, 'quantity' => 1, 'unit_price' => 9, 'toppings' => array() ),
);
$reverse = array_reverse( $reordered );
hold_ok( DoughBoss_Capacity::cart_hash( $reordered, $context ) === DoughBoss_Capacity::cart_hash( $reverse, $context ), 'cart hash is stable across line ordering' );
hold_ok( '' === DoughBoss_Capacity::cart_hash( array(), $context ), 'empty cart cannot create a hold hash' );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
