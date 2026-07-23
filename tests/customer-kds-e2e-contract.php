<?php
/**
 * In-memory customer-to-kitchen REST contract.
 *
 * Exercises the safe, no-gateway path from durable order creation through
 * email-bound customer tracking and versioned staff lifecycle transitions.
 * It deliberately uses no WordPress database, payment provider, or network.
 *
 * Run: php tests/customer-kds-e2e-contract.php
 *
 * @package DoughBoss\Tests
 */

require __DIR__ . '/wp-stubs.php';

if ( ! defined( 'DOUGHBOSS_REST_NAMESPACE' ) ) {
	define( 'DOUGHBOSS_REST_NAMESPACE', 'doughboss/v1' );
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $data ) {
		return $data instanceof WP_REST_Response ? $data : new WP_REST_Response( $data );
	}
}

class DoughBoss_Customer_KDS_E2E_DB extends DB_Stub {
	public $orders = array();
	public $items = array();
	public $events = array();
	private $snapshot = null;

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$value = is_numeric( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query = preg_replace( '/%[dsf]/', $value, $query, 1 );
		}
		return $query;
	}

	public function query( $query ) {
		if ( 'START TRANSACTION' === $query ) {
			$this->snapshot = serialize( array( $this->orders, $this->items, $this->events ) );
		} elseif ( 'ROLLBACK' === $query && null !== $this->snapshot ) {
			list( $this->orders, $this->items, $this->events ) = unserialize( $this->snapshot );
			$this->snapshot = null;
		} elseif ( 'COMMIT' === $query ) {
			$this->snapshot = null;
		}
		return 0;
	}

	public function get_var( $query = null ) {
		if ( preg_match( "/SELECT id FROM wp_doughboss_orders WHERE (checkout_key|payment_intent_id|order_number) = '([^']+)'/", (string) $query, $match ) ) {
			foreach ( $this->orders as $row ) {
				if ( (string) ( $row[ $match[1] ] ?? '' ) === $match[2] ) { return (int) $row['id']; }
			}
		}
		if ( preg_match( "/SELECT 1 FROM wp_doughboss_order_events.*event_key = '([^']+)'/", (string) $query, $match ) ) {
			return isset( $this->events[ $match[1] ] ) ? 1 : null;
		}
		return null;
	}

	public function get_row( $query = null, $output = OBJECT, $offset = 0 ) {
		if ( preg_match( "/FROM wp_doughboss_orders WHERE (id|order_number) = (?:'([^']+)'|(\d+))/", (string) $query, $match ) ) {
			$value = '' !== ( $match[2] ?? '' ) ? $match[2] : $match[3];
			foreach ( $this->orders as $row ) {
				if ( (string) $row[ $match[1] ] === (string) $value ) { return ARRAY_A === $output ? $row : (object) $row; }
			}
		}
		if ( preg_match( "/FROM wp_doughboss_order_events.*event_key = '([^']+)'/", (string) $query, $match ) && isset( $this->events[ $match[1] ] ) ) {
			return (object) $this->events[ $match[1] ];
		}
		return null;
	}

	public function get_results( $query = null, $output = OBJECT ) {
		if ( preg_match( '/FROM wp_doughboss_order_items WHERE order_id = (\d+)/', (string) $query, $match ) ) {
			$rows = array_values( array_filter( $this->items, static function ( $item ) use ( $match ) { return (int) $item['order_id'] === (int) $match[1]; } ) );
			return ARRAY_A === $output ? $rows : array_map( static function ( $row ) { return (object) $row; }, $rows );
		}
		return array();
	}

	public function insert( $table, $data, $formats = null ) {
		if ( false !== strpos( $table, 'doughboss_orders' ) ) {
			$this->insert_id = count( $this->orders ) + 1;
			// MySQL supplies these schema defaults in production; make them explicit
			// in the in-memory double so lifecycle CAS uses the same initial state.
			$data['version'] = isset( $data['version'] ) ? $data['version'] : 1;
			$data['timezone_snapshot'] = isset( $data['timezone_snapshot'] ) ? $data['timezone_snapshot'] : '';
			$data['id'] = $this->insert_id;
			$this->orders[ $this->insert_id ] = $data;
			return 1;
		}
		if ( false !== strpos( $table, 'doughboss_order_items' ) ) { $this->items[] = $data; return 1; }
		if ( false !== strpos( $table, 'doughboss_order_events' ) ) {
			if ( isset( $this->events[ $data['event_key'] ] ) ) { return false; }
			$this->events[ $data['event_key'] ] = $data;
			return 1;
		}
		return 1;
	}

	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		$id = (int) ( $where['id'] ?? 0 );
		if ( ! isset( $this->orders[ $id ] ) ) { return 0; }
		foreach ( $where as $field => $value ) {
			if ( (string) ( $this->orders[ $id ][ $field ] ?? '' ) !== (string) $value ) { return 0; }
		}
		$this->orders[ $id ] = array_merge( $this->orders[ $id ], $data );
		return 1;
	}
}

require __DIR__ . '/../includes/class-doughboss-settings.php';
require __DIR__ . '/../includes/class-doughboss-order.php';
require __DIR__ . '/../includes/class-doughboss-cart.php';
require __DIR__ . '/../includes/class-doughboss-rest-controller.php';

$passed = 0;
$failed = 0;
function customer_kds_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) { ++$passed; echo "  ok   {$label}\n"; }
	else { ++$failed; echo "  FAIL {$label}\n"; }
}

echo "=== DoughBoss customer to KDS E2E contract ===\n";
update_option( 'doughboss_db_version', '1.16.0' );
$db = new DoughBoss_Customer_KDS_E2E_DB();
$GLOBALS['wpdb'] = $db;

$created = DoughBoss_Order::create(
	array(
		'order_type' => 'pickup', 'location_id' => 1, 'customer_name' => 'Customer Test',
		'customer_email' => 'customer@example.test', 'customer_phone' => '0400000000',
		'address' => '', 'notes' => '', 'subtotal' => 24, 'tax' => 2.18, 'delivery_fee' => 0,
		'total' => 24, 'discount' => 0, 'voucher_code' => '', 'payment_status' => 'unpaid',
		'payment_method' => '', 'payment_intent_id' => '', 'checkout_key' => str_repeat( 'c', 64 ),
	),
	array( array( 'item_id' => 7, 'name' => 'Test Manoush', 'size' => '', 'toppings' => array(), 'quantity' => 1, 'unit_price' => 24, 'line_total' => 24 ) )
);
customer_kds_ok( is_array( $created ) && empty( $created['replayed'] ), 'customer checkout persistence creates one pending order without a gateway' );
$order_id = is_array( $created ) ? (int) $created['order_id'] : 0;
$order = DoughBoss_Order::get( $order_id );
customer_kds_ok( $order && 'pending' === $order->status && 1 === (int) $order->version, 'new order begins at the KDS pending truth state' );

$controller = new DoughBoss_REST_Controller( new DoughBoss_Cart() );
$tracked = $controller->track_order( new WP_REST_Request( array( 'number' => $order->order_number, 'email' => 'customer@example.test' ) ) );
customer_kds_ok( $tracked instanceof WP_REST_Response && 'received' === $tracked->data['customer_status'], 'email-bound tracking returns the received customer projection' );
$denied = $controller->track_order( new WP_REST_Request( array( 'number' => $order->order_number, 'email' => 'other@example.test' ) ) );
customer_kds_ok( is_wp_error( $denied ) && 'doughboss_not_found' === $denied->get_error_code(), 'tracking rejects a mismatched customer email' );
$tracking_response = $controller->protect_tracking_response(
	new WP_REST_Response( array( 'ok' => true ) ),
	null,
	new WP_REST_Request( array( '_route' => '/doughboss/v1/order/track' ) )
);
$tracking_headers = $tracking_response->get_headers();
customer_kds_ok(
	isset( $tracking_headers['Cache-Control'], $tracking_headers['Referrer-Policy'] )
		&& 'no-store, private' === $tracking_headers['Cache-Control']
		&& 'no-referrer' === $tracking_headers['Referrer-Policy'],
	'tracking responses are private, non-cacheable and never used as a referrer'
);

$accepted = $controller->admin_accept(
	new WP_REST_Request(
		array( 'id' => $order_id, 'expected_version' => 1, 'event_key' => 'e2e:accept', 'eta' => 15 )
	)
);
customer_kds_ok( $accepted instanceof WP_REST_Response && 2 === $accepted->data['version'], 'staff REST acceptance uses a versioned compare-and-set transition' );
$stale = $controller->admin_update_status(
	new WP_REST_Request(
		array( 'id' => $order_id, 'status' => 'preparing', 'expected_version' => 1, 'event_key' => 'e2e:stale' )
	)
);
customer_kds_ok( is_wp_error( $stale ) && 'doughboss_stale_order' === $stale->get_error_code(), 'stale KDS client cannot overwrite acceptance' );

foreach ( array( 'preparing' => 'e2e:preparing', 'ready' => 'e2e:ready', 'completed' => 'e2e:completed' ) as $status => $event_key ) {
	$current = DoughBoss_Order::get( $order_id );
	$result = $controller->admin_update_status(
		new WP_REST_Request(
			array( 'id' => $order_id, 'status' => $status, 'expected_version' => (int) $current->version, 'event_key' => $event_key )
		)
	);
	customer_kds_ok( $result instanceof WP_REST_Response, "staff REST transitions order to {$status}" );
}

$final = $controller->track_order( new WP_REST_Request( array( 'number' => $order->order_number, 'email' => 'customer@example.test' ) ) );
customer_kds_ok( $final instanceof WP_REST_Response && 'collected' === $final->data['customer_status'] && 'completed' === $final->data['status'], 'customer tracking projects the completed pickup order as collected' );
customer_kds_ok( 5 === count( $db->events ), 'order lifecycle records creation plus every winning staff transition' );

echo "=== RESULT: {$passed} passed · {$failed} failed ===\n";
exit( $failed ? 1 : 0 );
