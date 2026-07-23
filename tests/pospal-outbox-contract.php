<?php
/**
 * Behavioural POSPal outbox contract. All provider responses are local stubs.
 *
 * Run: php tests/pospal-outbox-contract.php
 */

require __DIR__ . '/wp-stubs.php';
function wp_schedule_single_event( ...$args ) { return true; }

class DoughBoss_POSPal_Outbox_Contract_DB extends DB_Stub {
	public $rows = array();
	private $next_id = 1;
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function ( $match ) use ( &$args, &$i ) {
			$value = $args[ $i++ ];
			return '%d' === $match[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
		}, $query );
	}
	public function get_var( $query = null ) {
		if ( preg_match( "/idempotency_key = '([^']+)'/", $query, $m ) ) {
			foreach ( $this->rows as $row ) { if ( $row['idempotency_key'] === stripslashes( $m[1] ) ) { return $row['id']; } }
		}
		return null;
	}
	public function insert( $table, $data, $formats = null ) {
		foreach ( $this->rows as $row ) { if ( $row['idempotency_key'] === $data['idempotency_key'] ) { return false; } }
		$data['id'] = $this->next_id++; $this->insert_id = $data['id']; $this->rows[ $data['id'] ] = $data; return 1;
	}
	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		$id = (int) $where['id']; if ( ! isset( $this->rows[ $id ] ) ) { return 0; }
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data ); return 1;
	}
	public function get_col( $query = null ) {
		$out = array(); foreach ( $this->rows as $row ) { if ( 'pending' === $row['status'] ) { $out[] = $row['id']; } } return $out;
	}
	public function get_row( $query = null, $output = OBJECT, $offset = 0 ) {
		if ( preg_match( '/WHERE id = (\d+)/', $query, $m ) && isset( $this->rows[ (int) $m[1] ] ) ) { return (object) $this->rows[ (int) $m[1] ]; }
		return null;
	}
	public function query( $query ) {
		if ( preg_match( "/SET status = 'in_flight'.*WHERE id = (\d+) AND status = 'pending'/s", $query, $m ) ) {
			$id = (int) $m[1]; if ( ! isset( $this->rows[ $id ] ) || 'pending' !== $this->rows[ $id ]['status'] ) { return 0; }
			$this->rows[ $id ]['status'] = 'in_flight'; return 1;
		}
		if ( preg_match( "/WHERE id = (\d+) AND status = 'failed_terminal'/", $query, $match ) && preg_match( "/SET status = 'pending', attempts = 0, last_error = ''/", $query ) ) {
			$id = (int) $match[1]; if ( ! isset( $this->rows[ $id ] ) || 'failed_terminal' !== $this->rows[ $id ]['status'] ) { return 0; }
			$this->rows[ $id ]['status'] = 'pending'; $this->rows[ $id ]['attempts'] = 0; $this->rows[ $id ]['last_error'] = ''; return 1;
		}
		if ( preg_match( "/SET status = 'pending', attempts = 0, last_error = ''/", $query ) ) {
			$count = 0; foreach ( $this->rows as &$row ) { if ( 'failed_terminal' === $row['status'] && ! in_array( $row['last_error'], array( 'ambiguous_network', 'ambiguous_in_flight', 'ambiguous_missing_order_no' ), true ) ) { $row['status'] = 'pending'; $row['attempts'] = 0; $row['last_error'] = ''; $count++; } } unset( $row ); return $count;
		}
		return 0;
	}
}

class DoughBoss_POSPal {
	public static $results = array();
	public static function push_order( $payload, $creds, $blocking = true ) { return array_shift( self::$results ); }
}

$GLOBALS['wpdb'] = new DoughBoss_POSPal_Outbox_Contract_DB();
require __DIR__ . '/../includes/class-doughboss-settings.php';
require __DIR__ . '/../includes/class-doughboss-pospal-outbox.php';
$GLOBALS['__db_options'][ DoughBoss_Settings::OPTION_KEY ] = array(
	'pospal_enabled' => 1, 'pospal_push_orders' => 1, 'pospal_host' => 'https://pos.example.test', 'pospal_app_id' => 'app', 'pospal_app_key' => 'key',
);

$pass = 0; $fail = 0;
function outbox_ok( $condition, $label ) { global $pass, $fail; if ( $condition ) { $pass++; echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label\n"; } }
function dispatch_outbox_row( $id ) { global $wpdb; $method = new ReflectionMethod( 'DoughBoss_POSPal_Outbox', 'dispatch_row' ); $method->setAccessible( true ); return $method->invoke( null, (object) $wpdb->rows[ $id ] ); }

echo "=== POSPal outbox behavioural contract ===\n";
$body = array( 'daySeq' => 'DB-1001', 'items' => array( array( 'productUid' => 1 ) ) );
$id = DoughBoss_POSPal_Outbox::enqueue_order_push( 1001, 1, $body );
$again = DoughBoss_POSPal_Outbox::enqueue_order_push( 1001, 1, $body );
outbox_ok( $id > 0 && $id === $again && 1 === count( $GLOBALS['wpdb']->rows ), 'enqueue is locally idempotent' );

$GLOBALS['wpdb']->rows[ $id ]['status'] = 'in_flight';
DoughBoss_POSPal::$results[] = new WP_Error( 'doughboss_pospal_api', 'rejected' );
$retry_at = dispatch_outbox_row( $id );
$row = $GLOBALS['wpdb']->rows[ $id ];
outbox_ok( 'pending' === $row['status'] && 1 === $row['attempts'] && 'doughboss_pospal_api' === $row['last_error'] && $retry_at > time(), 'explicit provider failure backs off for retry' );

$GLOBALS['wpdb']->rows[ $id ]['status'] = 'in_flight';
DoughBoss_POSPal::$results[] = new WP_Error( 'doughboss_pospal_network', 'timeout' );
dispatch_outbox_row( $id );
$row = $GLOBALS['wpdb']->rows[ $id ];
outbox_ok( 'failed_terminal' === $row['status'] && 'ambiguous_network' === $row['last_error'], 'ambiguous transport is quarantined without blind replay' );
outbox_ok( 0 === DoughBoss_POSPal_Outbox::reset_for_retry(), 'bulk retry does not release ambiguous transport outcomes' );
outbox_ok( 1 === DoughBoss_POSPal_Outbox::reset_for_retry( $id, true, $row['updated_at'], 'ambiguous_network' ) && 'pending' === $GLOBALS['wpdb']->rows[ $id ]['status'], 'manual reviewed release can requeue one ambiguous outcome' );

$GLOBALS['wpdb']->rows[ $id ]['status'] = 'in_flight'; $GLOBALS['wpdb']->rows[ $id ]['attempts'] = 4;
DoughBoss_POSPal::$results[] = new WP_Error( 'doughboss_pospal_api', 'rejected again' );
dispatch_outbox_row( $id );
$row = $GLOBALS['wpdb']->rows[ $id ];
outbox_ok( 'failed_terminal' === $row['status'] && 5 === $row['attempts'], 'fifth explicit failure becomes terminal' );

$success = DoughBoss_POSPal_Outbox::enqueue_order_push( 1002, 1, $body );
$GLOBALS['wpdb']->rows[ $success ]['status'] = 'in_flight';
DoughBoss_POSPal::$results[] = array( 'orderNo' => 'POS-9001' );
dispatch_outbox_row( $success );
$row = $GLOBALS['wpdb']->rows[ $success ];
outbox_ok( 'succeeded' === $row['status'] && 1 === $row['attempts'] && '' === $row['last_error'] && 'POS-9001' === $row['remote_reference'], 'identified successful provider response is retained with its stable reference' );

$missing = DoughBoss_POSPal_Outbox::enqueue_order_push( 1003, 1, $body );
$GLOBALS['wpdb']->rows[ $missing ]['status'] = 'in_flight';
DoughBoss_POSPal::$results[] = array();
dispatch_outbox_row( $missing );
outbox_ok( 'failed_terminal' === $GLOBALS['wpdb']->rows[ $missing ]['status'] && 'ambiguous_missing_order_no' === $GLOBALS['wpdb']->rows[ $missing ]['last_error'], '2xx response without a stable order number is quarantined, not treated as safe success' );
DoughBoss_POSPal_Outbox::reset_for_retry();
outbox_ok( 'failed_terminal' === $GLOBALS['wpdb']->rows[ $missing ]['status'], 'bulk retry cannot replay a success response whose stable order number is missing' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
