<?php
/**
 * 2.51.0 WP-B: checkout integration for pickup scheduling.
 *
 * Covers the Square pickup ticket projection, the order model helpers
 * (upcoming, delay, promise guard, insert columns), the board workload and the
 * REST binding helpers. Pure and fake-$wpdb only; none of the pickup
 * foundation classes (DoughBoss_Pickup, Capacity) are required.
 *
 * @package DoughBoss
 */

if ( ! function_exists( 'wp_json_encode' ) ) {
	/** @return string|false */
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	/** @return string */
	function wp_salt( $scheme = 'auth' ) {
		unset( $scheme );
		return 'doughboss-test-salt';
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook ) {}
}
if ( ! function_exists( 'wp_list_pluck' ) ) {
	/** @return array */
	function wp_list_pluck( $list, $field ) {
		$out = array();
		foreach ( (array) $list as $item ) {
			$item  = (array) $item;
			$out[] = isset( $item[ $field ] ) ? $item[ $field ] : null;
		}
		return $out;
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	/** @return string */
	function sanitize_email( $email ) {
		return is_scalar( $email ) ? trim( (string) $email ) : '';
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/** @return string */
	function sanitize_textarea_field( $value ) {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	/** Minimal request double. */
	class WP_REST_Request {
		public $params = array();
		public function __construct( array $params = array() ) {
			$this->params = $params;
		}
		public function get_param( $key ) {
			return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-doughboss-order.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-square.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-square-orders.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-locations.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-rest-controller.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-checkout-snapshots.php';

/** Scripted $wpdb: records writes and answers the few reads WP-B code makes. */
class DoughBoss_WPB_DB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $insert_id = 0;
	public $queries = array();
	public $updates = array();
	public $inserts = array();
	public $order_row = null;
	public $event_row = null;
	public $location_row = null;
	public $workload_row = null;
	public $update_result = 1;
	public $snapshot_row = null;
	public $attempt_row = null;

	public function prepare( $query ) {
		$args = func_get_args();
		array_shift( $args );
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'", $query, 1 );
		}
		return $query;
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		return 1;
	}

	public function get_row( $query ) {
		$this->queries[] = $query;
		if ( false !== strpos( $query, 'doughboss_checkout_snapshots' ) ) { return $this->snapshot_row; }
		if ( false !== strpos( $query, 'doughboss_payment_attempts' ) ) { return $this->attempt_row; }
		if ( false !== strpos( $query, 'make_count' ) ) {
			return $this->workload_row;
		}
		if ( false !== strpos( $query, 'order_events' ) ) {
			return $this->event_row;
		}
		if ( false !== strpos( $query, 'doughboss_locations' ) ) {
			return $this->location_row;
		}
		return $this->order_row;
	}

	public function get_results( $query ) {
		$this->queries[] = $query;
		return array();
	}

	public function get_var( $query ) {
		$this->queries[] = $query;
		return null;
	}

	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		$this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where, 'formats' => $formats, 'where_formats' => $where_formats );
		return $this->update_result;
	}

	public function insert( $table, $data, $formats = null ) {
		$this->inserts[] = array( 'table' => $table, 'data' => $data, 'formats' => $formats );
		return 1;
	}
}

/** Cart double exposing only what the binding helpers read. */
class DoughBoss_WPB_Cart {
	public function get_voucher_code() {
		return 'save10';
	}
	public function get_lines() {
		return array( array( 'key' => 'a', 'item_id' => 5, 'name' => 'Pizza', 'quantity' => 1 ) );
	}
}

/** Install a fresh fake and return the previous global for restoration. */
function db_wpb_use_db( DoughBoss_WPB_DB $db ) {
	$previous       = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
	$GLOBALS['wpdb'] = $db;
	return $previous;
}

/** A live kitchen order row. */
function db_wpb_order( array $overrides = array() ) {
	return (object) array_merge(
		array(
			'id'                      => 77,
			'order_number'            => 'DB-77',
			'location_id'             => 3,
			'status'                  => 'confirmed',
			'version'                 => 4,
			'order_type'              => 'pickup',
			'order_source'            => 'web',
			'payment_status'          => 'paid',
			'eta_minutes'             => 0,
			'promised_ready_from_utc' => '2026-10-16 02:45:00',
			'promised_ready_by_utc'   => '2026-10-16 03:00:00',
			'timezone_snapshot'       => 'Australia/Sydney',
			'fire_at_utc'             => '2026-10-16 02:25:00',
			'pickup_mode'             => 'scheduled',
			'capacity_overflow'       => 0,
			'promised_delay_minutes'  => 0,
			'created_at'              => '2026-10-16 01:00:00',
		),
		$overrides
	);
}

/** Reflection helper for private instance methods of the REST controller. */
function db_wpb_controller_call( $method, array $args = array() ) {
	$ref        = new ReflectionClass( 'DoughBoss_REST_Controller' );
	$controller = $ref->newInstanceWithoutConstructor();
	$cart_prop  = $ref->getProperty( 'cart' );
	$cart_prop->setAccessible( true );
	$cart_prop->setValue( $controller, new DoughBoss_WPB_Cart() );
	$m = $ref->getMethod( $method );
	$m->setAccessible( true );
	return $m->invokeArgs( $controller, $args );
}

/** Self-contained Square fixture (tax-inclusive, no discount, no delivery). */
function db_wpb_square_payload( array $order_extra = array() ) {
	$payload = array(
		'order' => array_merge(
			array(
				'currency' => 'AUD', 'subtotal' => '22.00', 'discount' => '0.00', 'delivery_fee' => '0.00', 'tax' => 2.0, 'total' => 22.0,
				'accounting_basis' => array( 'version' => 1, 'tax_inclusive' => true, 'tax_rate' => 10, 'discount_policy' => 'goods_before_tax', 'delivery_taxable' => true ),
			),
			$order_extra
		),
		'lines' => array(
			array( 'name' => 'Signature pizza', 'size' => 'Large', 'quantity' => '2', 'unit_price' => '11.00', 'line_total' => '22.00', 'toppings' => array() ),
		),
	);
	return array( $payload, array( 'shop_slug' => 'revesby', 'square_location_id' => 'LOCATION_R_TEST', 'square_mode' => 'test' ) );
}

db_test( 'Native pickup snapshot freezes prep and promise across later shop policy changes', function () {
	$db = new DoughBoss_WPB_DB(); $previous = db_wpb_use_db( $db );
	$settings = get_option( 'doughboss_settings', array() );
	try {
		doughboss_test_set_settings( db_square_settings() );
		$db->location_row = (object) array( 'id' => 3, 'prep_time_default' => 18, 'busy_extra_minutes' => 7, 'timezone' => 'Australia/Sydney' );
		$key = str_repeat( 'c', 64 );
		foreach ( array( 'asap', 'scheduled' ) as $mode ) {
			list( $payload, $binding ) = db_wpb_square_payload( array( 'order_type' => 'pickup', 'location_id' => 3, 'customer_name' => 'Synthetic Buyer', 'customer_email' => 'synthetic@example.com', 'customer_phone' => '0400000000',
				'pickup_mode' => $mode, 'pickup_ready_from_utc' => '2026-10-17 03:45:00', 'pickup_ready_by_utc' => '2026-10-17 04:00:00', 'pickup_timezone' => 'Australia/Sydney' ) );
			$db->snapshot_row = null;
			$db->location_row->prep_time_default = 18; $db->location_row->busy_extra_minutes = 7;
			$saved = db_wpb_controller_call( 'native_pickup_snapshot_order', array( $payload['order'], $key, 3 ) );
			assert_same( 25, $saved['square_pickup_prep_minutes'], 'same existing default-plus-busy website policy captured once' );
			assert_same( 'square-orders-v2-pickup', $saved['square_orders_protocol'], 'only native timed snapshot is marked' );
			$payload['order'] = $saved;
			$payload['binding_hash'] = str_repeat( 'b', 64 );
			$json = wp_json_encode( $payload );
			$db->snapshot_row = array( 'checkout_key' => $key, 'payload_json' => $json, 'payload_hash' => hash( 'sha256', $json ), 'status' => 'prepared', 'order_id' => 0, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
			$metadata = array( 'protocol_version' => 'square-v2', 'orders_protocol' => 'square-orders-v2-pickup', 'orders_snapshot_hash' => hash( 'sha256', $json ), 'binding_hash' => $payload['binding_hash'], 'square_api_version' => '2025-01-23' );
			$db->attempt_row = array( 'id' => 37, 'checkout_key' => $key, 'provider' => 'square', 'status' => 'prepared', 'location_id' => 3, 'amount_minor' => 2200, 'currency' => 'AUD', 'safe_metadata_json' => wp_json_encode( $metadata ) );
			$project = new ReflectionMethod( 'DoughBoss_Square', 'order_projection' ); $project->setAccessible( true );
			$linked = $project->invoke( null, $db->attempt_row, $binding );
			assert_false( is_wp_error( $linked ), 'native creation and paid reread load exact retained v2 projection' );
			assert_same( 'PICKUP', $linked['order']['fulfillments'][0]['type'], 'retained protocol cannot silently downgrade to v1' );
			$email = new ReflectionMethod( 'DoughBoss_Square', 'buyer_email' ); $email->setAccessible( true );
			assert_same( 'synthetic@example.com', $email->invoke( null, $db->attempt_row, 2 ), 'v2 buyer email comes only from hash-bound snapshot' );
			$metadata['square_api_version'] = '2026-09-16'; $bad_attempt = $db->attempt_row; $bad_attempt['safe_metadata_json'] = wp_json_encode( $metadata );
			assert_true( is_wp_error( $project->invoke( null, $bad_attempt, $binding ) ), 'metadata cannot substitute a different API version for snapshot' );
			$metadata['square_api_version'] = '2025-01-23';
			$downgrade = $metadata; $downgrade['orders_protocol'] = 'square-orders-v1'; $bad_attempt['safe_metadata_json'] = wp_json_encode( $downgrade );
			assert_true( is_wp_error( $project->invoke( null, $bad_attempt, $binding ) ), 'v2 snapshot cannot be projected through a downgraded v1 attempt marker' );
			$db->location_row->prep_time_default = 90; $db->location_row->busy_extra_minutes = 30;
			$candidate = $saved; $candidate['pickup_ready_from_utc'] = '2026-10-17 06:00:00';
			$replay = db_wpb_controller_call( 'native_pickup_snapshot_order', array( $candidate, $key, 3 ) );
			assert_same( $saved, $replay, 'settings and fresh selection cannot move promise or prep' );
			$first = DoughBoss_Square_Orders::project( array( 'order' => $saved, 'lines' => $payload['lines'] ), $binding, 37, 'square-orders-v2-pickup' );
			$again = DoughBoss_Square_Orders::project( array( 'order' => $replay, 'lines' => $payload['lines'] ), $binding, 37, 'square-orders-v2-pickup' );
			assert_same( wp_json_encode( $first ), wp_json_encode( $again ), 'projection replay remains byte-identical under policy drift' );
			$timing = new ReflectionMethod( 'DoughBoss_Order', 'native_pickup_timing' ); $timing->setAccessible( true );
			assert_same( $timing->invoke( null, $saved ), $timing->invoke( null, $replay ), 'local row timing remains exact after settings drift' );
			assert_same( '2026-10-17 03:20:00', $timing->invoke( null, $replay )['fire_at_utc'], 'local kitchen fire is saved promise minus captured prep, not current policy' );
			$copied = db_wpb_controller_call( 'pickup_fields_from_snapshot', array( $saved ) );
			assert_same( 25, $copied['square_pickup_prep_minutes'], 'final order receives captured prep' );
			unset( $payload['order']['square_orders_protocol'], $payload['order']['square_pickup_prep_minutes'], $payload['order']['square_api_version'] );
			$legacy_json = wp_json_encode( $payload ); $db->snapshot_row['payload_json'] = $legacy_json; $db->snapshot_row['payload_hash'] = hash( 'sha256', $legacy_json );
			$legacy = db_wpb_controller_call( 'native_pickup_snapshot_order', array( $saved, $key, 3 ) );
			assert_same( $payload['order'], $legacy, 'pre-existing v1 snapshot never upgraded or rewritten' );
		}
		foreach ( array( 0 => '2026-10-17 03:45:00', 1440 => '2026-10-16 03:45:00' ) as $minutes => $fire ) {
			$valid = $saved; $valid['square_pickup_prep_minutes'] = $minutes;
			assert_same( $fire, $timing->invoke( null, $valid )['fire_at_utc'], 'prep boundary keeps exact local fire time' );
		}
		foreach ( array( 'square_pickup_prep_minutes' => -1, 'pickup_ready_from_utc' => '2026-02-30 03:45:00', 'square_orders_protocol' => 'unknown-v3', 'pickup_ready_by_utc' => '2026-10-16 03:00:00' ) as $field => $value ) {
			$bad = $saved; $bad[ $field ] = $value;
			assert_true( is_wp_error( $timing->invoke( null, $bad ) ), 'invalid native timing has no mutable-policy fallback' );
		}
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-order.php' );
		assert_true( false !== strpos( $source, 'self::native_pickup_timing( $native_pickup )' ) && false !== strpos( $source, 'array_replace( $row, $native_timing )' ), 'limited wiring evidence: actual order writer consumes the tested timing result' );
	} finally { $GLOBALS['wpdb'] = $previous; doughboss_test_set_settings( $settings ); }
} );

db_test( 'WP-B Square projection adds a short ticket name and metadata only for timed pickups', function () {
	list( $legacy_payload, $binding ) = db_wpb_square_payload();
	$legacy = DoughBoss_Square_Orders::project( $legacy_payload, $binding, 37 );
	assert_false( is_wp_error( $legacy ), 'legacy payload projects' );
	assert_false( isset( $legacy['order']['ticket_name'] ) || isset( $legacy['order']['metadata'] ), 'legacy projection has no ticket name or metadata' );

	list( $blank_payload ) = db_wpb_square_payload( array( 'pickup_mode' => '', 'pickup_ready_from_utc' => '2026-10-16 02:45:00' ) );
	assert_same( json_encode( $legacy ), json_encode( DoughBoss_Square_Orders::project( $blank_payload, $binding, 37 ) ), 'an empty pickup mode projects byte-identically to legacy' );

	list( $payload ) = db_wpb_square_payload( array( 'pickup_mode' => 'scheduled', 'pickup_ready_from_utc' => '2026-10-16 02:45:00', 'pickup_timezone' => 'Australia/Sydney' ) );
	$timed = DoughBoss_Square_Orders::project( $payload, $binding, 37 );
	assert_false( is_wp_error( $timed ), 'timed payload projects' );
	assert_same( 'Pickup 1:45pm Fri', $timed['order']['ticket_name'], 'ticket name is the shop-local pickup time (AEDT, UTC+11)' );
	assert_same( array( 'db_pickup_at' => '2026-10-16T02:45:00Z', 'db_pickup_mode' => 'scheduled' ), $timed['order']['metadata'], 'metadata carries ISO UTC pickup time and mode' );
	assert_true( strlen( $timed['order']['ticket_name'] ) <= 30, 'ticket name is within 30 characters' );
	assert_false( isset( $timed['order']['fulfillments'] ), 'no fulfillments are ever projected' );

	$stripped = $timed;
	unset( $stripped['order']['ticket_name'], $stripped['order']['metadata'] );
	assert_same( json_encode( $legacy ), json_encode( $stripped ), 'everything else in the projection is unchanged by pickup facts' );

	list( $asap_payload ) = db_wpb_square_payload( array( 'pickup_mode' => 'asap', 'pickup_ready_from_utc' => '2027-04-04 22:30:00' ) );
	$asap = DoughBoss_Square_Orders::project( $asap_payload, $binding, 37 );
	assert_same( 'Pickup 8:30am Mon', $asap['order']['ticket_name'], 'default timezone is Australia/Sydney (AEST, UTC+10, after the April DST end)' );
	assert_same( 'asap', $asap['order']['metadata']['db_pickup_mode'], 'asap mode is carried' );

	list( $bad_tz ) = db_wpb_square_payload( array( 'pickup_mode' => 'scheduled', 'pickup_ready_from_utc' => '2026-10-16 02:45:00', 'pickup_timezone' => 'Not/AZone' ) );
	$fallback = DoughBoss_Square_Orders::project( $bad_tz, $binding, 37 );
	assert_same( 'Pickup 1:45pm Fri', $fallback['order']['ticket_name'], 'an unknown timezone falls back to Sydney' );

	foreach ( array( 'garbage', '', null, 12345 ) as $bad_time ) {
		list( $junk ) = db_wpb_square_payload( array( 'pickup_mode' => 'scheduled', 'pickup_ready_from_utc' => $bad_time ) );
		$result = DoughBoss_Square_Orders::project( $junk, $binding, 37 );
		assert_false( isset( $result['order']['ticket_name'] ), 'an unparsable pickup time never produces a ticket name' );
	}
	list( $bad_mode ) = db_wpb_square_payload( array( 'pickup_mode' => 'later', 'pickup_ready_from_utc' => '2026-10-16 02:45:00' ) );
	assert_false( isset( DoughBoss_Square_Orders::project( $bad_mode, $binding, 37 )['order']['ticket_name'] ), 'an unknown mode is treated as untimed' );
} );

db_test( 'WP-B Square unpaid response validation ignores ticket name and metadata', function () {
	if ( ! function_exists( 'db_square_orders_response_fixture' ) ) {
		assert_true( true, 'Square response fixture unavailable standalone; covered by the full suite' );
		return;
	}
	list( $payload, $binding ) = db_wpb_square_payload( array( 'pickup_mode' => 'scheduled', 'pickup_ready_from_utc' => '2026-10-16 02:45:00' ) );
	$projection = DoughBoss_Square_Orders::project( $payload, $binding, 37 );
	$response   = db_square_orders_response_fixture( $projection );
	assert_true( isset( $response['order']['ticket_name'], $response['order']['metadata'] ), 'the provider response echoes the ticket name and metadata' );
	assert_same( array( 'id' => 'squareORDEREXAMPLE00000001', 'version' => 1 ), DoughBoss_Square_Orders::validate_unpaid_response( $response, $projection ), 'a response carrying them validates' );
	unset( $response['order']['ticket_name'], $response['order']['metadata'] );
	assert_false( is_wp_error( DoughBoss_Square_Orders::validate_unpaid_response( $response, $projection ) ), 'a response without them also validates (never compared)' );
	$response['order']['metadata'] = array( 'db_pickup_at' => 'tampered' );
	assert_false( is_wp_error( DoughBoss_Square_Orders::validate_unpaid_response( $response, $projection ) ), 'metadata is informational, not an authority' );
} );

db_test( 'WP-B pickup mode is restricted to the stored vocabulary', function () {
	assert_same( 'asap', DoughBoss_Order::sanitize_pickup_mode( 'asap' ), 'asap accepted' );
	assert_same( 'scheduled', DoughBoss_Order::sanitize_pickup_mode( 'Scheduled' ), 'case is normalised' );
	foreach ( array( '', 'later', 'ASAP; DROP', null, 5, array( 'asap' ) ) as $value ) {
		assert_same( '', DoughBoss_Order::sanitize_pickup_mode( $value ), 'anything else is untimed' );
	}
} );

db_test( 'WP-B order insert gains the pickup columns only on a 1.30.0 schema', function () {
	$row     = array( 'order_number' => 'X', 'location_id' => 1 );
	$formats = array( '%s', '%d' );

	update_option( 'doughboss_db_version', '1.29.0' );
	list( $old_row, $old_formats ) = DoughBoss_Order::with_pickup_columns( $row, $formats, 'scheduled', 1 );
	assert_same( $row, $old_row, 'a pre-1.30.0 schema insert is the 2.50 shape' );
	assert_same( $formats, $old_formats, 'and so are its formats' );

	update_option( 'doughboss_db_version', '1.30.0' );
	list( $new_row, $new_formats ) = DoughBoss_Order::with_pickup_columns( $row, $formats, 'scheduled', 1 );
	assert_same( array( 'order_number', 'location_id', 'pickup_mode', 'capacity_overflow', 'promised_delay_minutes' ), array_keys( $new_row ), 'three columns are appended in order' );
	assert_same( 'scheduled', $new_row['pickup_mode'], 'mode stored' );
	assert_same( 1, $new_row['capacity_overflow'], 'overflow flag stored' );
	assert_same( 0, $new_row['promised_delay_minutes'], 'delay starts at zero' );
	assert_same( count( $new_row ), count( $new_formats ), 'format count equals column count' );
	assert_same( array( '%s', '%d', '%s', '%d', '%d' ), $new_formats, 'formats are positional' );

	list( $clean ) = DoughBoss_Order::with_pickup_columns( $row, $formats, 'hax', 0 );
	assert_same( '', $clean['pickup_mode'], 'an invalid mode is stored as untimed' );

	// The inline legacy insert in create() keeps its keys and formats aligned.
	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-order.php' );
	preg_match( '/\$row = array\((.*?)\n\t\t\t\);\r?\n\t\t\t\$row_formats = array\((.*?)\);/s', $source, $m );
	assert_true( ! empty( $m ), 'create() builds $row and $row_formats' );
	if ( ! empty( $m ) ) {
		$keys = preg_match_all( "/^\s*'[a-z_]+'\s*=>/m", $m[1] );
		$fmts = preg_match_all( "/'%[dsf]'/", $m[2] );
		assert_same( $keys, $fmts, 'create() has one format per legacy column' );
		assert_true( $keys >= 36, 'create() still inserts the full legacy column set' );
	}
} );

db_test( 'WP-B is_upcoming needs a pending order with a future fire time', function () {
	$now = '2026-10-16 02:00:00';
	assert_true( DoughBoss_Order::is_upcoming( array( 'status' => 'pending', 'fire_at_utc' => '2026-10-16 02:25:00' ), $now ), 'future fire time, pending' );
	assert_true( DoughBoss_Order::is_upcoming( array( 'status' => 'pending', 'fire_at_utc' => '2026-10-16T02:25:00Z' ), $now ), 'ISO fire time also works' );
	assert_false( DoughBoss_Order::is_upcoming( array( 'status' => 'pending', 'fire_at_utc' => '2026-10-16 02:00:00' ), $now ), 'fire time reached means it is live' );
	assert_false( DoughBoss_Order::is_upcoming( array( 'status' => 'pending', 'fire_at_utc' => '2026-10-16 01:00:00' ), $now ), 'past fire time is live' );
	assert_false( DoughBoss_Order::is_upcoming( array( 'status' => 'confirmed', 'fire_at_utc' => '2026-10-16 02:25:00' ), $now ), 'an accepted order is never upcoming' );
	assert_false( DoughBoss_Order::is_upcoming( array( 'status' => 'pending', 'fire_at_utc' => null ), $now ), 'no fire time (legacy) is live' );
	assert_false( DoughBoss_Order::is_upcoming( array( 'status' => 'pending', 'fire_at_utc' => '0000-00-00 00:00:00' ), $now ), 'zero date is live' );
	assert_false( DoughBoss_Order::is_upcoming( array( 'status' => 'pending' ), $now ), 'missing field is live' );
	assert_false( DoughBoss_Order::is_upcoming( array( 'status' => 'pending', 'fire_at_utc' => '2026-10-16 02:25:00' ), 'garbage' ), 'an unparsable clock fails safe to live' );
} );

db_test( 'WP-B pickup_fields and the capacity note', function () {
	update_option( 'doughboss_db_version', '1.30.0' );
	$previous_db = db_wpb_use_db( new DoughBoss_WPB_DB() );
	$legacy = DoughBoss_Order::pickup_fields( db_wpb_order( array( 'pickup_mode' => '', 'fire_at_utc' => null, 'status' => 'pending' ) ) );
	assert_same( '', $legacy['pickup_mode'], 'legacy mode empty' );
	assert_same( '', $legacy['pickup_label'], 'legacy has no label' );
	assert_same( '', $legacy['fire_at_utc'], 'legacy has no fire time' );
	assert_false( $legacy['upcoming'], 'legacy is not upcoming' );
	assert_false( isset( $legacy['location_name'] ), 'no shop name unless asked' );

	$future = gmdate( 'Y-m-d H:i:s', time() + 3600 );
	$timed  = DoughBoss_Order::pickup_fields( db_wpb_order( array( 'status' => 'pending', 'fire_at_utc' => $future, 'capacity_overflow' => 1, 'promised_delay_minutes' => 20 ) ), true );
	assert_same( 'scheduled', $timed['pickup_mode'], 'mode surfaced' );
	assert_true( $timed['upcoming'], 'future pending order is upcoming' );
	assert_true( $timed['capacity_overflow'], 'overflow surfaced as bool' );
	assert_same( 20, $timed['promised_delay_minutes'], 'cumulative delay surfaced' );
	assert_same( str_replace( ' ', 'T', $future ) . 'Z', $timed['fire_at_utc'], 'fire time is ISO UTC' );
	assert_same( '', $timed['location_name'], 'shop name present (empty without a location row)' );

	assert_same( '[SYSTEM] The chosen pickup slot was full or its hold had lapsed when payment completed. This paid order was accepted over capacity - check the pickup time.', DoughBoss_Order::append_capacity_note( '', true, false ), 'overflow note' );
	$both = DoughBoss_Order::append_capacity_note( 'Extra cheese', true, true );
	assert_true( 0 === strpos( $both, 'Extra cheese' ), 'customer notes lead' );
	assert_true( false !== strpos( $both, 'over capacity' ) && false !== strpos( $both, 'already started' ), 'both facts are noted' );
	assert_same( 'Extra cheese', DoughBoss_Order::append_capacity_note( 'Extra cheese', false, false ), 'no facts, no note' );
	assert_true( false !== strpos( DoughBoss_Order::append_capacity_note( '', false, false, 'Today 12:45pm', true ), 'Today 12:45pm' ), 'a lost hold names the chosen time' );
	$GLOBALS['wpdb'] = $previous_db;
} );

db_test( 'WP-B legacy customer and timing projections are unchanged', function () {
	$row = array( 'status' => 'confirmed', 'order_type' => 'pickup', 'promised_ready_by_utc' => '2000-01-01 00:00:00', 'pickup_mode' => '' );
	assert_same( array( 'status' => 'confirmed', 'label' => 'Accepted by the shop' ), DoughBoss_Order::customer_projection( $row ), 'customer projection' );
	assert_same( array( 'status' => 'estimate_passed', 'label' => 'Estimate passed — check this order' ), DoughBoss_Order::timing_projection( $row, '2026-10-16 02:00:00' ), 'timing projection for a passed estimate' );
	$pending = array( 'status' => 'pending', 'order_type' => 'pickup' );
	assert_same( 'awaiting_acceptance', DoughBoss_Order::timing_projection( $pending )['status'], 'pending timing' );
} );

db_test( 'WP-B delay_promise validates minutes, key, version and schema', function () {
	$db = new DoughBoss_WPB_DB();
	$prev = db_wpb_use_db( $db );
	try {
		update_option( 'doughboss_db_version', '1.30.0' );
		$ctx = array( 'expected_version' => 4, 'event_key' => 'delay-77-a' );
		foreach ( array( 0, 4, 61, 600, -5 ) as $minutes ) {
			$result = DoughBoss_Order::delay_promise( 77, $minutes, $ctx );
			assert_same( 'doughboss_delay_invalid', is_wp_error( $result ) ? $result->get_error_code() : '', 'minutes ' . $minutes . ' is out of range' );
		}
		assert_same( 'doughboss_delay_invalid', DoughBoss_Order::delay_promise( 77, 10, array( 'event_key' => 'k' ) )->get_error_code(), 'expected version required' );
		assert_same( 'doughboss_delay_invalid', DoughBoss_Order::delay_promise( 77, 10, array( 'expected_version' => 4 ) )->get_error_code(), 'event key required' );
		assert_same( 'doughboss_delay_invalid', DoughBoss_Order::delay_promise( 0, 10, $ctx )->get_error_code(), 'order id required' );
		assert_same( array(), $db->queries, 'invalid input never touches the database' );

		update_option( 'doughboss_db_version', '1.29.0' );
		assert_same( 'doughboss_lifecycle_unavailable', DoughBoss_Order::delay_promise( 77, 10, $ctx )->get_error_code(), 'refused before the 1.30.0 schema exists' );
		update_option( 'doughboss_db_version', '1.30.0' );

		$db->order_row = null;
		assert_same( 'doughboss_order_not_found', DoughBoss_Order::delay_promise( 77, 10, $ctx )->get_error_code(), 'missing order' );

		$db->order_row = db_wpb_order( array( 'version' => 9 ) );
		assert_same( 'doughboss_stale_order', DoughBoss_Order::delay_promise( 77, 10, $ctx )->get_error_code(), 'stale version loses the compare-and-set' );

		foreach ( array( 'ready', 'completed', 'cancelled', 'out_for_delivery' ) as $status ) {
			$db->order_row = db_wpb_order( array( 'status' => $status ) );
			assert_same( 'doughboss_delay_unavailable', DoughBoss_Order::delay_promise( 77, 10, $ctx )->get_error_code(), $status . ' cannot be delayed' );
		}
		$db->order_row = db_wpb_order( array( 'promised_ready_from_utc' => null ) );
		assert_same( 'doughboss_delay_unavailable', DoughBoss_Order::delay_promise( 77, 10, $ctx )->get_error_code(), 'an order with no promise cannot be delayed' );
		$db->order_row = db_wpb_order( array( 'status' => 'pending', 'order_source' => 'preorder_request' ) );
		assert_same( 'doughboss_delay_unavailable', DoughBoss_Order::delay_promise( 77, 10, $ctx )->get_error_code(), 'a pre-order request is outside the kitchen lifecycle' );
		assert_same( array(), $db->updates, 'no refusal wrote anything' );

		$db->event_row = (object) array( 'order_id' => 99, 'event_type' => 'promise_delayed' );
		assert_same( 'doughboss_event_key_conflict', DoughBoss_Order::delay_promise( 77, 10, $ctx )->get_error_code(), 'an event key from another order conflicts' );
	} finally {
		$GLOBALS['wpdb'] = $prev;
	}
} );

db_test( 'WP-B delay_promise moves the promise, fire time and cumulative delay', function () {
	$db = new DoughBoss_WPB_DB();
	$prev = db_wpb_use_db( $db );
	try {
		update_option( 'doughboss_db_version', '1.30.0' );
		$db->order_row = db_wpb_order( array( 'status' => 'preparing', 'promised_delay_minutes' => 10 ) );
		$result = DoughBoss_Order::delay_promise( 77, 15, array( 'expected_version' => 4, 'event_key' => 'delay-77-b', 'actor_id' => 8 ) );
		assert_false( is_wp_error( $result ), 'a valid delay succeeds' );
		assert_same( 1, count( $db->updates ), 'one compare-and-set update' );
		$update = $db->updates[0];
		assert_same( '2026-10-16 03:00:00', $update['data']['promised_ready_from_utc'], 'promise start +15 min' );
		assert_same( '2026-10-16 03:15:00', $update['data']['promised_ready_by_utc'], 'promise end +15 min' );
		assert_same( '2026-10-16 02:40:00', $update['data']['fire_at_utc'], 'fire time +15 min' );
		assert_same( 25, $update['data']['promised_delay_minutes'], 'delay accumulates' );
		assert_same( 5, $update['data']['version'], 'version bumps' );
		assert_same( count( $update['data'] ), count( $update['formats'] ), 'update formats match columns' );
		assert_same( array( 'id' => 77, 'status' => 'preparing', 'version' => 4 ), $update['where'], 'compare-and-set on status and version' );
		assert_same( 1, count( $db->inserts ), 'one audit event' );
		assert_same( 'promise_delayed', $db->inserts[0]['data']['event_type'], 'event type' );
		assert_same( 'delay_15', $db->inserts[0]['data']['reason_code'], 'reason code' );
		assert_same( 'delay-77-b', $db->inserts[0]['data']['event_key'], 'event key stored for replay' );
		assert_same( 5, $db->inserts[0]['data']['order_version'], 'event records the new version' );
		assert_same( 'COMMIT', end( $db->queries ), 'transaction committed' );
		assert_same( 25, $result['promised_delay_minutes'], 'result carries the cumulative delay' );
		assert_same( '2026-10-16T03:00:00Z', $result['promised_ready_from_utc'], 'result carries the new promise' );
		assert_same( 5, $result['version'], 'result carries the new version' );

		// No fire time: nothing invented.
		$db2 = new DoughBoss_WPB_DB();
		db_wpb_use_db( $db2 );
		$db2->order_row = db_wpb_order( array( 'fire_at_utc' => null, 'promised_ready_by_utc' => null ) );
		DoughBoss_Order::delay_promise( 77, 5, array( 'expected_version' => 4, 'event_key' => 'delay-77-c' ) );
		assert_false( isset( $db2->updates[0]['data']['fire_at_utc'] ), 'fire time untouched when unset' );
		assert_false( isset( $db2->updates[0]['data']['promised_ready_by_utc'] ), 'promise end untouched when unset' );

		// Replay with the same key on the same order returns the stored order, no write.
		$db3 = new DoughBoss_WPB_DB();
		db_wpb_use_db( $db3 );
		$db3->order_row = db_wpb_order( array( 'version' => 5 ) );
		$db3->event_row = (object) array( 'order_id' => 77, 'event_type' => 'promise_delayed' );
		$replay = DoughBoss_Order::delay_promise( 77, 10, array( 'expected_version' => 4, 'event_key' => 'delay-77-b' ) );
		assert_true( ! is_wp_error( $replay ) && true === $replay['replayed'], 'same key replays' );
		assert_same( array(), $db3->updates, 'a replay writes nothing' );

		// Lost compare-and-set.
		$db4 = new DoughBoss_WPB_DB();
		db_wpb_use_db( $db4 );
		$db4->order_row     = db_wpb_order();
		$db4->update_result = 0;
		assert_same( 'doughboss_stale_order', DoughBoss_Order::delay_promise( 77, 10, array( 'expected_version' => 4, 'event_key' => 'delay-77-d' ) )->get_error_code(), 'zero rows updated is a conflict' );
		assert_same( 'ROLLBACK', end( $db4->queries ), 'and rolls back' );
	} finally {
		$GLOBALS['wpdb'] = $prev;
	}
} );

db_test( 'WP-B accepting a timed order keeps its promise; an untimed order still takes the ETA', function () {
	$db = new DoughBoss_WPB_DB();
	$prev = db_wpb_use_db( $db );
	try {
		update_option( 'doughboss_db_version', '1.30.0' );
		$ctx = array( 'expected_version' => 4, 'event_key' => 'accept-77', 'eta_minutes' => 30 );

		$db->order_row = db_wpb_order( array( 'status' => 'pending' ) );
		$result = DoughBoss_Order::transition( 77, 'confirmed', $ctx );
		assert_false( is_wp_error( $result ), 'accept succeeds' );
		$data = $db->updates[0]['data'];
		assert_false( isset( $data['promised_ready_from_utc'] ) || isset( $data['promised_ready_by_utc'] ) || isset( $data['timezone_snapshot'] ), 'a timed order promise is not rewritten' );
		assert_same( 0, $data['eta_minutes'], 'a staff ETA on a timed order is ignored' );
		assert_same( '2026-10-16T02:45:00Z', $result['promised_ready_from_utc'], 'the customer promise survives acceptance' );
		assert_same( 'scheduled', $result['pickup_mode'], 'result reports the mode' );

		$db2 = new DoughBoss_WPB_DB();
		db_wpb_use_db( $db2 );
		$db2->order_row = db_wpb_order( array( 'status' => 'pending', 'pickup_mode' => '', 'promised_ready_from_utc' => null, 'promised_ready_by_utc' => null, 'fire_at_utc' => null ) );
		DoughBoss_Order::transition( 77, 'confirmed', $ctx );
		$legacy = $db2->updates[0]['data'];
		assert_same( 30, $legacy['eta_minutes'], 'legacy accept still records the ETA' );
		assert_true( isset( $legacy['promised_ready_from_utc'], $legacy['promised_ready_by_utc'] ), 'legacy accept still writes the estimate' );
	} finally {
		$GLOBALS['wpdb'] = $prev;
	}
} );

db_test( 'WP-B board workload separates upcoming from make; active orders sort timed orders by promise', function () {
	$db = new DoughBoss_WPB_DB();
	$prev = db_wpb_use_db( $db );
	try {
		$db->workload_row = (object) array( 'make_count' => '3', 'upcoming_count' => '2', 'pass_count' => '1', 'preorder_review_count' => '4' );
		$counts = DoughBoss_Order::kitchen_workload_counts( 3 );
		assert_same( array( 'make' => 3, 'upcoming' => 2, 'pass' => 1, 'preorder_review' => 4 ), $counts, 'counts include upcoming' );
		$query = end( $db->queries );
		assert_true( false !== strpos( $query, "NOT ( o.status = 'pending' AND o.fire_at_utc IS NOT NULL AND o.fire_at_utc >" ), 'upcoming orders are excluded from make' );
		assert_true( false !== strpos( $query, 'o.location_id = 3' ), 'shop filter retained' );

		$db->workload_row = null;
		assert_same( array( 'make' => 0, 'upcoming' => 0, 'pass' => 0, 'preorder_review' => 0 ), DoughBoss_Order::kitchen_workload_counts(), 'empty result is all zero' );

		update_option( 'doughboss_db_version', '1.29.0' );
		$db->queries = array();
		DoughBoss_Order::active_orders( 50, 3 );
		assert_true( false !== strpos( $db->queries[0], 'ORDER BY created_at ASC' ), 'pre-1.30.0 board keeps the arrival order' );
		assert_false( false !== strpos( $db->queries[0], 'pickup_mode' ), 'and never references a missing column' );

		update_option( 'doughboss_db_version', '1.30.0' );
		$db->queries = array();
		DoughBoss_Order::active_orders( 50, 3 );
		assert_true( false !== strpos( $db->queries[0], "CASE WHEN pickup_mode IN ( 'asap', 'scheduled' ) THEN promised_ready_from_utc ELSE created_at END ASC" ), 'timed orders sort by promise' );
		$db->queries = array();
		DoughBoss_Order::active_orders( 50, 0 );
		assert_true( false !== strpos( $db->queries[0], 'pickup_mode' ), 'all-shop board sorts the same way' );
	} finally {
		$GLOBALS['wpdb'] = $prev;
	}
} );

db_test( 'WP-B Square binding and Stripe key gain pickup keys only when a slot is chosen', function () {
	$base = array( 'DoughBoss_Settings', 'square_mode' );
	assert_true( is_callable( $base ), 'settings available' );
	$legacy = db_wpb_controller_call( 'square_binding_payload', array( 'pickup', 3, null, 'Sam', 'sam@example.com', '0400000000', '', 'notes' ) );
	assert_false( array_key_exists( 'pickup_mode', $legacy ) || array_key_exists( 'pickup_slot_id', $legacy ), 'no keys without a selection' );
	$with_empty = db_wpb_controller_call( 'square_binding_payload', array( 'pickup', 3, null, 'Sam', 'sam@example.com', '0400000000', '', 'notes', array() ) );
	assert_same( wp_json_encode( $legacy ), wp_json_encode( $with_empty ), 'an empty selection is byte-identical to 2.50' );
	$with_blank = db_wpb_controller_call( 'square_binding_payload', array( 'pickup', 3, null, 'Sam', 'sam@example.com', '0400000000', '', 'notes', array( 'pickup_mode' => '', 'pickup_slot_id' => 9 ) ) );
	assert_same( wp_json_encode( $legacy ), wp_json_encode( $with_blank ), 'a blank mode adds nothing' );
	$timed = db_wpb_controller_call( 'square_binding_payload', array( 'pickup', 3, null, 'Sam', 'sam@example.com', '0400000000', '', 'notes', array( 'pickup_mode' => 'asap', 'pickup_slot_id' => 41 ) ) );
	assert_same( 'asap', $timed['pickup_mode'], 'mode is bound' );
	assert_same( 41, $timed['pickup_slot_id'], 'slot is bound' );
	$prefix = $timed;
	unset( $prefix['pickup_mode'], $prefix['pickup_slot_id'] );
	assert_same( $legacy, $prefix, 'every other binding fact is unchanged' );
	$other = db_wpb_controller_call( 'square_binding_payload', array( 'pickup', 3, null, 'Sam', 'sam@example.com', '0400000000', '', 'notes', array( 'pickup_mode' => 'asap', 'pickup_slot_id' => 42 ) ) );
	assert_true( wp_json_encode( $timed ) !== wp_json_encode( $other ), 'a different slot is a different binding' );

	$cart_key = array( 'cart' => array( 'items' => 1 ), 'location_id' => 3, 'order_type' => 'pickup', 'table' => null );
	assert_same( $cart_key, db_wpb_controller_call( 'pickup_checkout_key_binding', array( $cart_key, array() ) ), 'Stripe key untouched without a selection' );
	$keyed = db_wpb_controller_call( 'pickup_checkout_key_binding', array( $cart_key, array( 'pickup_mode' => 'scheduled', 'pickup_slot_id' => 7 ) ) );
	assert_same( array( 'scheduled', 7 ), array( $keyed['pickup_mode'], $keyed['pickup_slot_id'] ), 'Stripe key binds the slot' );

	$req = new WP_REST_Request( array( 'pickup_mode' => 'asap', 'pickup_slot_id' => '41' ) );
	assert_same( array( 'pickup_mode' => 'asap', 'pickup_slot_id' => 41 ), db_wpb_controller_call( 'pickup_binding_from_request', array( $req ) ), 'binding is recomputed from the request' );
	assert_same( array(), db_wpb_controller_call( 'pickup_binding_from_request', array( new WP_REST_Request( array( 'pickup_mode' => 'asap' ) ) ) ), 'no slot, no binding' );
	assert_same( array(), db_wpb_controller_call( 'pickup_binding_from_request', array( new WP_REST_Request() ) ), 'no params, no binding' );
} );

db_test( 'WP-B snapshot pickup facts: built from the selection, copied to paid orders, empty when absent', function () {
	$db = new DoughBoss_WPB_DB();
	$prev = db_wpb_use_db( $db );
	try {
		$db->location_row = (object) array( 'id' => 3, 'timezone' => 'Australia/Sydney', 'name' => 'Dough Boss Revesby' );
		$selection = array(
			'pickup_mode' => 'scheduled', 'capacity_hold_token' => str_repeat( 'a', 64 ), 'pickup_slot_id' => 41,
			'pickup_ready_from_utc' => '2026-10-16 02:45:00', 'pickup_ready_by_utc' => '2026-10-16 03:00:00',
			'pickup_label' => 'Today 1:45pm', 'capacity_unpaid_allowed' => false,
		);
		$fields = db_wpb_controller_call( 'pickup_snapshot_fields', array( $selection, 3 ) );
		assert_same( 'scheduled', $fields['pickup_mode'], 'mode in snapshot' );
		assert_same( str_repeat( 'a', 64 ), $fields['capacity_hold_token'], 'hold token in snapshot so every writer converts it' );
		assert_same( 'Australia/Sydney', $fields['pickup_timezone'], 'timezone recorded for the Square ticket' );
		assert_false( array_key_exists( 'capacity_unpaid_allowed', $fields ), 'the unpaid flag never enters a payment snapshot' );
		assert_same( array(), db_wpb_controller_call( 'pickup_snapshot_fields', array( array(), 3 ) ), 'no selection, no fields' );
		assert_same( array(), db_wpb_controller_call( 'pickup_snapshot_fields', array( array( 'pickup_mode' => '' ), 3 ) ), 'blank mode, no fields' );

		$copied = db_wpb_controller_call( 'pickup_fields_from_snapshot', array( array_merge( $fields, array( 'customer_name' => 'Sam', 'total' => 22.0 ) ) ) );
		assert_same( $fields, $copied, 'only pickup facts are copied from a paid snapshot' );
		assert_same( array(), db_wpb_controller_call( 'pickup_fields_from_snapshot', array( array( 'customer_name' => 'Sam' ) ) ), 'a legacy snapshot yields nothing' );
	} finally {
		$GLOBALS['wpdb'] = $prev;
	}
} );

db_test( 'WP-B checkout selection is empty when the pickup foundation is not loaded', function () {
	if ( class_exists( 'DoughBoss_Pickup' ) ) {
		assert_true( true, 'foundation present; contract covered by its own tests' );
		return;
	}
	$req = new WP_REST_Request( array( 'pickup_mode' => 'asap', 'capacity_hold_token' => str_repeat( 'b', 64 ) ) );
	assert_same( array(), db_wpb_controller_call( 'pickup_selection_for_checkout', array( $req, 3, 'pickup', true ) ), 'nothing is added, so legacy checkout is unchanged' );
} );

db_test( 'WP-B REST surface: routes, args, config and board summary are wired', function () {
	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-rest-controller.php' );
	foreach ( array( "'/admin/order/(?P<id>\\d+)/delay'", "'/cart/revalidate'", "'admin_delay'", "'cart_revalidate'", "'pickup_scheduling' => true" ) as $needle ) {
		assert_true( false !== strpos( $source, $needle ), 'controller contains ' . $needle );
	}
	assert_same( 2, substr_count( $source, "'capacity_hold_token' => array(" ), '/payment-intent and /checkout both accept the hold token' );
	assert_same( 2, substr_count( $source, "'pickup_slot_id' => array(" ) + substr_count( $source, "'pickup_slot_id'    => array(" ), 'both accept the slot id' );
	assert_true( false !== strpos( $source, "'upcoming'        => isset( \$orders['upcoming'] )" ), 'board summary reports upcoming' );
	assert_true( (bool) preg_match( "/'\\/admin\\/order\\/\\(\\?P<id>\\\\d\\+\\)\\/delay',\\s+array\\(\\s+'methods'\\s+=> WP_REST_Server::CREATABLE,\\s+'callback'\\s+=> array\\( \\\$this, 'admin_delay' \\),\\s+'permission_callback' => array\\( \\\$this, 'verify_board_access' \\)/", $source ), 'the delay route is staff-gated like /accept' );
	assert_true( (bool) preg_match( "/'\\/cart\\/revalidate',\\s+array\\(\\s+'methods'\\s+=> WP_REST_Server::CREATABLE,\\s+'callback'\\s+=> array\\( \\\$this, 'cart_revalidate' \\),\\s+'permission_callback' => array\\( \\\$this, 'verify_nonce' \\)/", $source ), 'revalidate is nonce-gated like the other cart routes' );
	assert_true( false !== strpos( $source, "'sanitize_callback' => 'absint'" ), 'slot id is sanitised to an integer' );
} );

db_test( 'WP-B order creation gates capacity on payment or an explicit unpaid allowance', function () {
	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-order.php' );
	assert_true( false !== strpos( $source, "! \$paid_order && empty( \$data['capacity_unpaid_allowed'] )" ), 'an unpaid hold needs capacity_unpaid_allowed' );
	assert_true( false !== strpos( $source, "method_exists( 'DoughBoss_Capacity', 'lock_hold_for_checkout' )" ), 'prefers the checkout lock when available' );
	assert_true( false !== strpos( $source, "DoughBoss_Capacity::lock_hold_for_order( \$data['capacity_hold_token']" ), 'falls back to the original lock' );
	assert_true( false !== strpos( $source, "'doughboss_preorder_payment_forbidden'" ) && false !== strpos( $source, "! empty( \$data['capacity_hold_token'] )" ), 'pre-order requests still refuse hold tokens' );
	$options = DoughBoss_Order::capacity_lock_options( 0, true );
	assert_same( array( 'paid' => true, 'prep_minutes' => 0, 'slot_minutes' => 15 ), $options, 'lock options default safely without a shop row' );
} );

db_test( 'Order::create takes the settings-row lock before the gap-locking replay lookups (InnoDB deadlock found on real MariaDB)', function () {
	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-order.php' );
	$start  = strpos( $source, 'public static function create( array $data, array $lines )' );
	$begin  = strpos( $source, "'START TRANSACTION'", $start );
	$lock   = strpos( $source, 'DoughBoss_Settings::staff_approval_policy( true );', $begin );
	$replay = strpos( $source, 'self::find_id_by_checkout_key( $checkout_key, true )', $begin );
	assert_true( false !== $start && false !== $begin && false !== $lock && false !== $replay, 'create() lock sequence located' );
	assert_true( $begin < $lock && $lock < $replay, 'options-row FOR UPDATE precedes the checkout_key FOR UPDATE lookup, so concurrent creators serialise instead of deadlocking on the index gap' );
} );
