<?php
/**
 * Pickup scheduling (2.51) foundation tests: capacity engine additions, live
 * location configuration, holds, the pickup contract and REST handlers.
 *
 * Uses an in-memory fake $wpdb that understands only the queries these classes
 * issue. No MariaDB locking or concurrency is exercised here.
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
		return 'test-salt-' . $scheme;
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	/** @return string */
	function sanitize_title( $title ) {
		return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $title ) ), '-' );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/** @return string */
	function sanitize_textarea_field( $value ) {
		return trim( (string) $value );
	}
}
if ( ! class_exists( 'DB_PK_Response' ) ) {
	/** Minimal REST response stand-in. */
	class DB_PK_Response {
		public $data;
		public $headers = array();
		public function __construct( $data ) {
			$this->data = $data;
		}
		public function header( $name, $value ) {
			$this->headers[ $name ] = $value;
		}
		public function get_data() {
			return $this->data;
		}
	}
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	/** @return DB_PK_Response */
	function rest_ensure_response( $data ) {
		return new DB_PK_Response( $data );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-doughboss-locations.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-store-hours.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-capacity.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-cart.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-pickup.php';

/** Minimal REST request stand-in. */
class DB_PK_Request {
	public $params;
	public function __construct( array $params = array() ) {
		$this->params = $params;
	}
	public function get_param( $key ) {
		return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
	}
	public function get_header( $key ) {
		return null;
	}
}

/** Cart whose contents are fixed by the test. */
class DB_PK_Cart extends DoughBoss_Cart {
	public $lines;
	public $total = 21.00;
	public $voucher = '';
	public function __construct( array $lines = null ) {
		$this->lines = null === $lines ? array( array( 'type' => 'menu', 'item_id' => 11, 'size' => 'large', 'toppings' => array(), 'quantity' => 2, 'unit_price' => 10.5 ) ) : $lines;
	}
	public function get_lines() {
		return $this->lines;
	}
	public function is_empty() {
		return 0 === count( $this->lines );
	}
	public function totals( $order_type = 'pickup' ) {
		return array( 'total' => $this->total, 'voucher_code' => $this->voucher );
	}
}

/**
 * In-memory $wpdb for the pickup classes.
 */
class DB_PK_DB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $insert_id = 0;
	public $locations = array();
	public $hours = array();
	public $exceptions = array();
	public $slots = array();
	public $holds = array();
	public $orders = array();
	public $log = array();
	public $last_update = null;
	public $last_insert = null;

	public function reset() {
		$this->locations  = array();
		$this->hours      = array();
		$this->exceptions = array();
		$this->slots      = array();
		$this->holds      = array();
		$this->orders     = array();
		$this->log        = array();
		$this->insert_id  = 0;
	}

	public function prepare( $query ) {
		$args = func_get_args();
		array_shift( $args );
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			function ( $match ) use ( &$i, $args ) {
				$arg = isset( $args[ $i++ ] ) ? $args[ $i - 1 ] : '';
				return '%d' === $match[0] ? (string) (int) $arg : "'" . addslashes( (string) $arg ) . "'";
			},
			$query
		);
	}

	private function note( $kind, $sql ) {
		$this->log[] = array( $kind, $sql );
	}

	public function count_kind( $kind ) {
		$n = 0;
		foreach ( $this->log as $entry ) {
			if ( $entry[0] === $kind ) {
				++$n;
			}
		}
		return $n;
	}

	public function sql_like( $needle ) {
		foreach ( $this->log as $entry ) {
			if ( false !== stripos( $entry[1], $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private function obj( $row ) {
		return $row ? (object) $row : null;
	}

	private function live_hold( $hold, $now ) {
		return 'converted' === $hold['status'] || ( 'held' === $hold['status'] && $hold['expires_at'] > $now );
	}

	public function get_row( $sql ) {
		$this->note( 'read', $sql );
		if ( preg_match( '/FROM wp_doughboss_locations WHERE id = (\d+)/', $sql, $m ) ) {
			return isset( $this->locations[ (int) $m[1] ] ) ? (object) $this->locations[ (int) $m[1] ] : null;
		}
		if ( preg_match( '/FROM wp_doughboss_capacity_slots WHERE id = (\d+) FOR UPDATE/', $sql, $m ) ) {
			return isset( $this->slots[ (int) $m[1] ] ) ? $this->obj( $this->slots[ (int) $m[1] ] ) : null;
		}
		if ( preg_match( "/FROM wp_doughboss_capacity_holds WHERE token_hash = '(\w+)' AND slot_id = (\d+) FOR UPDATE/", $sql, $m ) ) {
			foreach ( $this->holds as $hold ) {
				if ( $hold['token_hash'] === $m[1] && (int) $hold['slot_id'] === (int) $m[2] ) {
					return $this->obj( $hold );
				}
			}
			return null;
		}
		if ( preg_match( "/FROM wp_doughboss_capacity_holds WHERE id = (\d+) FOR UPDATE/", $sql, $m ) ) {
			foreach ( $this->holds as $hold ) {
				if ( (int) $hold['id'] === (int) $m[1] ) {
					return $this->obj( $hold );
				}
			}
			return null;
		}
		if ( preg_match( "/FROM wp_doughboss_capacity_holds WHERE idempotency_key = '([^']*)'$/", $sql, $m ) ) {
			foreach ( $this->holds as $hold ) {
				if ( $hold['idempotency_key'] === stripslashes( $m[1] ) ) {
					return $this->obj( $hold );
				}
			}
			return null;
		}
		if ( preg_match( "/FROM wp_doughboss_capacity_holds h JOIN wp_doughboss_capacity_slots s ON s.id = h.slot_id WHERE h.token_hash = '(\w+)'/", $sql, $m ) ) {
			foreach ( $this->holds as $hold ) {
				if ( $hold['token_hash'] === $m[1] ) {
					$slot = $this->slots[ (int) $hold['slot_id'] ];
					return (object) array(
						'status'            => $hold['status'],
						'slot_id'           => $hold['slot_id'],
						'expires_at'        => $hold['expires_at'],
						'cart_hash'         => $hold['cart_hash'],
						'location_id'       => $slot['location_id'],
						'starts_at_utc'     => $slot['starts_at_utc'],
						'ends_at_utc'       => $slot['ends_at_utc'],
						'timezone_snapshot' => $slot['timezone_snapshot'],
					);
				}
			}
			return null;
		}
		if ( preg_match( "/COUNT\(\*\) AS orders_used, COALESCE\(SUM\(capacity_units\),0\) AS units_used FROM wp_doughboss_capacity_holds WHERE slot_id = (\d+)(?: AND id <> (\d+))? AND \(status = 'converted' OR \(status = 'held' AND expires_at > '([^']+)'\)\)/", $sql, $m ) ) {
			$orders = 0;
			$units  = 0;
			foreach ( $this->holds as $hold ) {
				if ( (int) $hold['slot_id'] !== (int) $m[1] || ( '' !== $m[2] && (int) $hold['id'] === (int) $m[2] ) ) {
					continue;
				}
				if ( $this->live_hold( $hold, $m[3] ) ) {
					++$orders;
					$units += (int) $hold['capacity_units'];
				}
			}
			return (object) array( 'orders_used' => $orders, 'units_used' => $units );
		}
		return null;
	}

	public function get_var( $sql ) {
		$this->note( 'read', $sql );
		if ( preg_match( "/SELECT slot_id FROM wp_doughboss_capacity_holds WHERE token_hash = '(\w+)'/", $sql, $m ) ) {
			foreach ( $this->holds as $hold ) {
				if ( $hold['token_hash'] === $m[1] ) {
					return (string) $hold['slot_id'];
				}
			}
			return null;
		}
		if ( preg_match( '/SELECT id FROM wp_doughboss_orders WHERE id = (\d+) AND capacity_hold_id = (\d+)/', $sql, $m ) ) {
			return isset( $this->orders[ (int) $m[1] ] ) && (int) $this->orders[ (int) $m[1] ]['capacity_hold_id'] === (int) $m[2] ? (string) $m[1] : null;
		}
		if ( false !== strpos( $sql, 'COUNT(*) FROM wp_doughboss_locations WHERE slug' ) ) {
			return '0';
		}
		return null;
	}

	public function get_col( $sql ) {
		$this->note( 'read', $sql );
		if ( preg_match( "/^SELECT id FROM wp_doughboss_capacity_holds WHERE slot_id = (\d+) AND status = 'held' AND expires_at <= '([^']+)'/", $sql, $m ) ) {
			$ids = array();
			foreach ( $this->holds as $hold ) {
				if ( (int) $hold['slot_id'] === (int) $m[1] && 'held' === $hold['status'] && $hold['expires_at'] <= $m[2] ) {
					$ids[] = (string) $hold['id'];
				}
			}
			return $ids;
		}
		$dates = array();
		if ( preg_match( "/is_closed = 1 AND service_date >= '([^']+)'/", $sql, $m ) ) {
			foreach ( $this->exceptions as $row ) {
				if ( $row['is_closed'] && $row['service_date'] >= $m[1] ) {
					$dates[] = $row['service_date'];
				}
			}
			sort( $dates );
			return array_values( array_unique( $dates ) );
		}
		foreach ( $this->exceptions as $row ) {
			$dates[] = $row['service_date'];
		}
		return array_values( array_unique( $dates ) );
	}

	public function get_results( $sql ) {
		$this->note( 'read', $sql );
		if ( false !== strpos( $sql, 'FROM wp_doughboss_location_hours' ) ) {
			return $this->hours;
		}
		if ( preg_match( "/GROUP BY s.starts_at_utc/", $sql ) && preg_match( "/s.location_id = (\d+) .* BETWEEN '([^']+)' AND '([^']+)' AND \(h.status = 'converted' OR \(h.status = 'held' AND h.expires_at > '([^']+)'\)\)/", $sql, $m ) ) {
			$out = array();
			foreach ( $this->holds as $hold ) {
				$slot = $this->slots[ (int) $hold['slot_id'] ];
				if ( (int) $slot['location_id'] !== (int) $m[1] || $slot['starts_at_utc'] < $m[2] || $slot['starts_at_utc'] > $m[3] || ! $this->live_hold( $hold, $m[4] ) ) {
					continue;
				}
				$out[ $slot['starts_at_utc'] ] = isset( $out[ $slot['starts_at_utc'] ] ) ? $out[ $slot['starts_at_utc'] ] + 1 : 1;
			}
			$rows = array();
			foreach ( $out as $start => $count ) {
				$rows[] = (object) array( 'starts_at_utc' => $start, 'orders_used' => $count );
			}
			return $rows;
		}
		return array();
	}

	public function query( $sql ) {
		$this->note( 'write', $sql );
		if ( in_array( $sql, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) {
			return true;
		}
		if ( preg_match( "/^UPDATE wp_doughboss_capacity_holds SET status = 'held', expires_at = '([^']+)', released_at = NULL, updated_at = '[^']+' WHERE id = (\d+) AND order_id IS NULL/", $sql, $m ) ) {
			foreach ( $this->holds as &$hold ) {
				if ( (int) $hold['id'] === (int) $m[2] && null === $hold['order_id'] ) {
					$hold['status']     = 'held';
					$hold['expires_at'] = $m[1];
					return 1;
				}
			}
			return 0;
		}
		if ( preg_match( "/^UPDATE wp_doughboss_capacity_holds SET status = 'expired', updated_at = '[^']+' WHERE id = (\d+) AND status = 'held' AND expires_at <= '([^']+)'/", $sql, $m ) ) {
			$n = 0;
			foreach ( $this->holds as &$hold ) {
				if ( (int) $hold['id'] === (int) $m[1] && 'held' === $hold['status'] && $hold['expires_at'] <= $m[2] ) {
					$hold['status'] = 'expired';
					++$n;
				}
			}
			return $n;
		}
		if ( preg_match( "/^UPDATE wp_doughboss_capacity_holds SET status = 'released', released_at = '[^']+', updated_at = '[^']+' WHERE token_hash = '(\w+)' AND status = 'held'/", $sql, $m ) ) {
			foreach ( $this->holds as &$hold ) {
				if ( $hold['token_hash'] === $m[1] && 'held' === $hold['status'] ) {
					$hold['status'] = 'released';
					return 1;
				}
			}
			return 0;
		}
		if ( preg_match( "/^UPDATE wp_doughboss_capacity_holds SET status = 'released', released_at = '[^']+', updated_at = '[^']+' WHERE order_id = (\d+) AND status = 'converted'/", $sql, $m ) ) {
			foreach ( $this->holds as &$hold ) {
				if ( (int) $hold['order_id'] === (int) $m[1] && 'converted' === $hold['status'] ) {
					$hold['status'] = 'released';
					return 1;
				}
			}
			return 0;
		}
		if ( preg_match( "/^UPDATE wp_doughboss_capacity_holds SET status = 'converted', order_id = (\d+), converted_at = '[^']+', updated_at = '[^']+' WHERE id = (\d+) AND status = 'held' AND order_id IS NULL/", $sql, $m ) ) {
			foreach ( $this->holds as &$hold ) {
				if ( (int) $hold['id'] === (int) $m[2] && 'held' === $hold['status'] && null === $hold['order_id'] ) {
					$hold['status']   = 'converted';
					$hold['order_id'] = (int) $m[1];
					return 1;
				}
			}
			return 0;
		}
		if ( preg_match( "/^INSERT INTO wp_doughboss_capacity_slots .* VALUES \((\d+), 'pickup', '([^']+)', '([^']+)', '([^']+)', (\d+), (\d+), (\d+), 1, '[^']+', '[^']+'\) ON DUPLICATE KEY UPDATE order_capacity = VALUES\(order_capacity\), planning_version = VALUES\(planning_version\), updated_at = VALUES\(updated_at\), id = LAST_INSERT_ID\(id\)/", $sql, $m ) ) {
			foreach ( $this->slots as $id => &$slot ) {
				if ( (int) $slot['location_id'] === (int) $m[1] && $slot['starts_at_utc'] === $m[2] ) {
					$slot['order_capacity']  = (int) $m[5];
					$slot['planning_version'] = (int) $m[7];
					$this->insert_id = $id;
					return 2;
				}
			}
			$id = count( $this->slots ) + 1;
			$this->slots[ $id ] = array(
				'id' => $id, 'location_id' => (int) $m[1], 'order_type' => 'pickup', 'starts_at_utc' => $m[2], 'ends_at_utc' => $m[3],
				'timezone_snapshot' => $m[4], 'order_capacity' => (int) $m[5], 'unit_capacity' => (int) $m[6], 'planning_version' => (int) $m[7], 'accepting_holds' => 1,
			);
			$this->insert_id = $id;
			return 1;
		}
		if ( preg_match( "/^DELETE FROM wp_doughboss_schedule_exceptions WHERE location_id = (\d+) AND order_type = 'pickup' AND segment = 1 AND is_closed = 1/", $sql, $m ) ) {
			$before = count( $this->exceptions );
			$this->exceptions = array_values( array_filter( $this->exceptions, function ( $row ) use ( $m ) {
				return ! ( (int) $row['location_id'] === (int) $m[1] && $row['is_closed'] );
			} ) );
			return $before - count( $this->exceptions );
		}
		if ( preg_match( "/^INSERT INTO wp_doughboss_schedule_exceptions .* VALUES \((\d+), 'pickup', '([^']+)', 1, 1, '[^']*'\) ON DUPLICATE KEY UPDATE is_closed = 1/", $sql, $m ) ) {
			$this->exceptions[] = array( 'location_id' => (int) $m[1], 'service_date' => $m[2], 'is_closed' => 1 );
			return 1;
		}
		if ( 0 === strpos( $sql, 'UPDATE wp_doughboss_locations SET planning_version' ) ) {
			return 1;
		}
		return 0;
	}

	public function insert( $table, $row, $format = null ) {
		$this->note( 'write', 'INSERT ' . $table );
		$this->last_insert = array( $table, $row, $format );
		if ( 'wp_doughboss_capacity_holds' === $table ) {
			$row['id']           = count( $this->holds ) + 1;
			$row['order_id']     = null;
			$row['converted_at'] = null;
			$row['released_at']  = null;
			$this->holds[ $row['id'] ] = $row;
			$this->insert_id = $row['id'];
		} else {
			$this->insert_id = 99;
		}
		return 1;
	}

	public function update( $table, $row, $where, $format = null, $where_format = null ) {
		$this->note( 'write', 'UPDATE ' . $table );
		$this->last_update = array( $table, $row, $where, $format, $where_format );
		return 1;
	}
}

$GLOBALS['db_pk_previous_wpdb'] = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
$GLOBALS['wpdb']                = new DB_PK_DB();

/** @return DateTimeImmutable */
function db_pk_utc( $iso ) {
	return new DateTimeImmutable( $iso, new DateTimeZone( 'UTC' ) );
}

/** @return array */
function db_pk_week( $open = '06:30', $close = '14:30', array $days = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' ) ) {
	$week = array( 'sun' => array(), 'mon' => array(), 'tue' => array(), 'wed' => array(), 'thu' => array(), 'fri' => array(), 'sat' => array() );
	foreach ( $days as $day ) {
		$week[ $day ] = array( array( $open, $close ) );
	}
	return $week;
}

/** @return array Live config as capacity_live_config() would build it. */
function db_pk_config( array $overrides = array() ) {
	return array_merge(
		array(
			'location_id'      => 7,
			'planning_version' => 3,
			'enabled'          => true,
			'active'           => true,
			'pickup_enabled'   => true,
			'timezone'         => 'Australia/Sydney',
			'slot_minutes'     => 15,
			'notice_minutes'   => 20,
			'horizon_days'     => 2,
			'capacity_units'   => 6,
			'cutoff_minutes'   => 20,
			'blackout_dates'   => array(),
			'hours'            => db_pk_week(),
			'hours_source'     => 'stored',
			'prep_minutes'     => 20,
			'hold_minutes'     => 10,
		),
		$overrides
	);
}

/** @return array Location row with live defaults. */
function db_pk_location( array $overrides = array() ) {
	return array_merge(
		array(
			'id' => 7, 'name' => 'Revesby', 'slug' => 'revesby', 'suburb' => 'Revesby', 'address' => '', 'phone' => '',
			'prep_time_default' => 20, 'timezone' => 'Australia/Sydney', 'capacity_mode' => 'live', 'slot_minutes' => 15,
			'minimum_notice_minutes' => 30, 'booking_horizon_days' => 7, 'hold_minutes' => 10, 'slot_order_capacity' => 6,
			'slot_unit_capacity' => 12, 'pickup_days_ahead' => 1, 'cutoff_minutes' => 20, 'busy_extra_minutes' => 0,
			'ordering_paused' => 0, 'planning_version' => 3, 'pickup_enabled' => 1, 'delivery_enabled' => 0, 'is_active' => 1,
		),
		$overrides
	);
}

/** Reset the fake DB and seed one live location with stored 06:30-14:30 hours all week. */
function db_pk_seed( array $location = array(), $with_hours = true ) {
	$db = $GLOBALS['wpdb'];
	$db->reset();
	$row = db_pk_location( $location );
	$db->locations[ (int) $row['id'] ] = $row;
	if ( $with_hours ) {
		for ( $day = 1; $day <= 7; ++$day ) {
			$db->hours[] = (object) array( 'weekday' => $day, 'opens_at' => '06:30:00', 'closes_at' => '14:30:00' );
		}
	}
	update_option( 'doughboss_db_version', '1.30.0' );
	return $db;
}

/** @return array[] */
function db_pk_lines() {
	return array( array( 'type' => 'menu', 'item_id' => 11, 'size' => 'large', 'toppings' => array(), 'quantity' => 2, 'unit_price' => 10.5 ) );
}

/** @return string cart hash of the stub cart for location 7. */
function db_pk_hash( $total = 21.00, $voucher = '' ) {
	return DoughBoss_Capacity::cart_hash( db_pk_lines(), array( 'location_id' => 7, 'order_type' => 'pickup', 'voucher' => $voucher, 'total' => $total ) );
}

/** Materialise the first window at/after the clock and hold it. @return array{hold:array,window:array,slot_id:int} */
function db_pk_make_hold( $now, $key = 'idem-key-0000000001', $skip = 0 ) {
	$config  = db_pk_config();
	$windows = DoughBoss_Capacity::windows( $config, 1, array(), $now );
	$window  = $windows[ $skip ];
	$slot_id = DoughBoss_Capacity::materialise_slot( $config, $window, $now );
	$hold    = DoughBoss_Capacity::create_hold( $slot_id, db_pk_lines(), array( 'location_id' => 7, 'order_type' => 'pickup', 'voucher' => '', 'total' => 21.00, 'hold_minutes' => 10 ), $key, $now );
	return array( 'hold' => $hold, 'window' => $window, 'slot_id' => $slot_id );
}

/** @return array<string,string> */
function db_pk_local_froms( array $windows, $date ) {
	$out = array();
	foreach ( $windows as $window ) {
		if ( $window['local_date'] === $date ) {
			$out[] = $window['local_from'];
		}
	}
	return $out;
}

// ---------------------------------------------------------------------------
// windows(): cut-off, DST, horizon
// ---------------------------------------------------------------------------

db_test(
	'windows cut-off stops the last slot start at close minus the cut-off',
	function () {
		$now     = db_pk_utc( '2026-10-15T20:00:00Z' ); // Fri 07:00 Sydney.
		$base    = db_pk_config( array( 'notice_minutes' => 0, 'horizon_days' => 1, 'cutoff_minutes' => 0 ) );
		$no_cut  = db_pk_local_froms( DoughBoss_Capacity::windows( $base, 1, array(), $now ), '2026-10-16' );
		assert_same( '14:15', end( $no_cut ), 'cutoff 0 keeps the legacy last window (14:15-14:30)' );

		$cut20 = db_pk_local_froms( DoughBoss_Capacity::windows( array_merge( $base, array( 'cutoff_minutes' => 20 ) ), 1, array(), $now ), '2026-10-16' );
		assert_same( '14:00', end( $cut20 ), 'cutoff 20 against a 14:30 close excludes the 14:15 start' );
		assert_false( in_array( '14:15', $cut20, true ), '14:15 is absent with a 20 minute cut-off' );

		$cut30 = db_pk_local_froms( DoughBoss_Capacity::windows( array_merge( $base, array( 'cutoff_minutes' => 30 ) ), 1, array(), $now ), '2026-10-16' );
		assert_same( '14:00', end( $cut30 ), 'a start exactly at close minus cut-off is still allowed' );
		$cut31 = db_pk_local_froms( DoughBoss_Capacity::windows( array_merge( $base, array( 'cutoff_minutes' => 31 ) ), 1, array(), $now ), '2026-10-16' );
		assert_same( '13:45', end( $cut31 ), 'one minute more pushes the last start back a slot' );

		$absent = $base;
		unset( $absent['cutoff_minutes'] );
		assert_same( DoughBoss_Capacity::windows( $base, 1, array(), $now ), DoughBoss_Capacity::windows( $absent, 1, array(), $now ), 'a missing cutoff key behaves as zero' );
		$first = DoughBoss_Capacity::windows( $base, 1, array(), $now )[0];
		assert_same( 0, $first['load_orders'], 'windows now expose load_orders' );
		assert_true( isset( $first['slot_id'], $first['remaining_units'], $first['availability'], $first['capacity_units'], $first['utc_offset'] ), 'every existing windows() key remains' );
	}
);

db_test(
	'windows load_orders and availability follow usage',
	function () {
		$now     = db_pk_utc( '2026-10-15T20:00:00Z' );
		$config  = db_pk_config( array( 'notice_minutes' => 0, 'horizon_days' => 1, 'capacity_units' => 6 ) );
		$windows = DoughBoss_Capacity::windows( $config, 1, array( '2026-10-15 20:00:00' => 5, '2026-10-15 20:15:00' => 6 ), $now );
		$by      = array();
		foreach ( $windows as $window ) {
			$by[ $window['ready_from_utc'] ] = $window;
		}
		// 07:00 Sydney is 20:00Z the previous day; hours start 06:30 so the first window is 06:30 (19:30Z) and is in the past.
		assert_same( 5, $by['2026-10-15T20:00:00Z']['load_orders'], 'load_orders reflects usage' );
		assert_same( 1, $by['2026-10-15T20:00:00Z']['remaining_units'], 'one order of room remains' );
		assert_same( 'available', $by['2026-10-15T20:00:00Z']['availability'], 'a slot with room is available' );
		assert_same( 'full', $by['2026-10-15T20:15:00Z']['availability'], 'a slot at capacity is full' );
	}
);

db_test(
	'windows stay contiguous across the Sydney DST start and end',
	function () {
		// DST starts Sun 2026-10-04 02:00 (+10 -> +11).
		$config  = db_pk_config( array( 'notice_minutes' => 0, 'horizon_days' => 4, 'cutoff_minutes' => 0 ) );
		$windows = DoughBoss_Capacity::windows( $config, 1, array(), db_pk_utc( '2026-10-02T12:00:00Z' ) );
		$sat     = db_pk_local_froms( $windows, '2026-10-03' );
		$sun     = db_pk_local_froms( $windows, '2026-10-04' );
		assert_same( 32, count( $sat ), 'Saturday has 32 fifteen-minute windows' );
		assert_same( 32, count( $sun ), 'the DST Sunday keeps 32 windows (hours start after the gap)' );
		$first = array();
		foreach ( $windows as $window ) {
			if ( ! isset( $first[ $window['local_date'] ] ) ) {
				$first[ $window['local_date'] ] = $window;
			}
		}
		assert_same( '2026-10-02T20:30:00Z', $first['2026-10-03']['ready_from_utc'], 'Saturday 06:30 AEST is 20:30Z' );
		assert_same( '+10:00', $first['2026-10-03']['utc_offset'], 'Saturday offset is +10:00' );
		assert_same( '2026-10-03T19:30:00Z', $first['2026-10-04']['ready_from_utc'], 'Sunday 06:30 AEDT is 19:30Z' );
		assert_same( '+11:00', $first['2026-10-04']['utc_offset'], 'Sunday offset is +11:00' );
		$contiguous = true;
		for ( $i = 1; $i < count( $windows ); ++$i ) {
			if ( $windows[ $i ]['local_date'] === $windows[ $i - 1 ]['local_date'] && $windows[ $i ]['ready_from_utc'] !== $windows[ $i - 1 ]['ready_by_utc'] ) {
				$contiguous = false;
			}
		}
		assert_true( $contiguous, 'consecutive UTC windows on one day abut exactly' );

		// A range straddling the missing hour is measured in real elapsed time (3h = 12 windows).
		$straddle = db_pk_config( array( 'notice_minutes' => 0, 'horizon_days' => 2, 'cutoff_minutes' => 0, 'hours' => db_pk_week( '01:00', '05:00', array( 'sun' ) ) ) );
		$day      = db_pk_local_froms( DoughBoss_Capacity::windows( $straddle, 1, array(), db_pk_utc( '2026-10-03T12:00:00Z' ) ), '2026-10-04' );
		assert_same( 12, count( $day ), '01:00-05:00 on the DST start day is three real hours' );

		// Fails closed when an opening time falls inside the gap.
		$gap = db_pk_config( array( 'notice_minutes' => 0, 'horizon_days' => 2, 'hours' => db_pk_week( '02:30', '05:00', array( 'sun' ) ) ) );
		assert_same( array(), DoughBoss_Capacity::windows( $gap, 1, array(), db_pk_utc( '2026-10-03T12:00:00Z' ) ), 'an opening time that does not exist fails closed' );

		// DST ends Sun 2027-04-04 03:00 (+11 -> +10).
		$end_windows = DoughBoss_Capacity::windows( db_pk_config( array( 'notice_minutes' => 0, 'horizon_days' => 4, 'cutoff_minutes' => 0 ) ), 1, array(), db_pk_utc( '2027-04-02T12:00:00Z' ) );
		$firsts      = array();
		foreach ( $end_windows as $window ) {
			if ( ! isset( $firsts[ $window['local_date'] ] ) ) {
				$firsts[ $window['local_date'] ] = $window;
			}
		}
		assert_same( '2027-04-02T19:30:00Z', $firsts['2027-04-03']['ready_from_utc'], 'Saturday 06:30 AEDT is 19:30Z' );
		assert_same( '+11:00', $firsts['2027-04-03']['utc_offset'], 'Saturday before DST end is +11:00' );
		assert_same( '2027-04-03T20:30:00Z', $firsts['2027-04-04']['ready_from_utc'], 'Sunday 06:30 AEST is 20:30Z' );
		assert_same( '+10:00', $firsts['2027-04-04']['utc_offset'], 'Sunday after DST end is +10:00' );
		$end_contiguous = true;
		for ( $i = 1; $i < count( $end_windows ); ++$i ) {
			if ( $end_windows[ $i ]['local_date'] === $end_windows[ $i - 1 ]['local_date'] && $end_windows[ $i ]['ready_from_utc'] !== $end_windows[ $i - 1 ]['ready_by_utc'] ) {
				$end_contiguous = false;
			}
		}
		assert_true( $end_contiguous, 'windows abut across the DST end too' );
	}
);

// ---------------------------------------------------------------------------
// Locations: sanitize, live config, holiday closures, public view
// ---------------------------------------------------------------------------

db_test(
	'Locations sanitize accepts live and bounds the new pickup settings',
	function () {
		$method = new ReflectionMethod( 'DoughBoss_Locations', 'sanitize' );
		$method->setAccessible( true );
		$row = $method->invoke( null, array( 'name' => 'Revesby', 'capacity_mode' => 'live', 'pickup_days_ahead' => 99, 'cutoff_minutes' => 500, 'busy_extra_minutes' => 500, 'ordering_paused' => 'on' ) );
		assert_same( 'live', $row['capacity_mode'], 'live is accepted' );
		assert_same( 7, $row['pickup_days_ahead'], 'days ahead is capped at 7' );
		assert_same( 180, $row['cutoff_minutes'], 'cutoff is capped at 180' );
		assert_same( 120, $row['busy_extra_minutes'], 'busy extra is capped at 120' );
		assert_same( 1, $row['ordering_paused'], 'ordering_paused is a flag' );
		$low = $method->invoke( null, array( 'name' => 'Revesby', 'pickup_days_ahead' => -4, 'cutoff_minutes' => -1, 'busy_extra_minutes' => -9 ) );
		assert_same( 0, $low['pickup_days_ahead'], 'days ahead floors at 0' );
		assert_same( 0, $low['cutoff_minutes'], 'cutoff floors at 0' );
		assert_same( 0, $low['busy_extra_minutes'], 'busy extra floors at 0' );
		$bogus = $method->invoke( null, array( 'name' => 'Revesby', 'capacity_mode' => 'turbo' ) );
		assert_same( 'off', $bogus['capacity_mode'], 'unknown modes fall back to off' );
		assert_same( 'shadow', $method->invoke( null, array( 'name' => 'R', 'capacity_mode' => 'shadow' ) )['capacity_mode'], 'shadow still works' );
		$defaults = $method->invoke( null, array( 'name' => 'Revesby' ) );
		assert_same( 'off', $defaults['capacity_mode'], 'default mode is off' );
		assert_same( 6, $defaults['slot_order_capacity'], 'default orders per slot is 6' );
		assert_same( 1, $defaults['pickup_days_ahead'], 'default days ahead is 1' );
		assert_same( 20, $defaults['cutoff_minutes'], 'default cutoff is 20' );
		assert_same( 0, $defaults['busy_extra_minutes'], 'default busy extra is 0' );
		assert_same( 0, $defaults['ordering_paused'], 'default is not paused' );
	}
);

db_test(
	'Locations create and update pass one format per column in row order',
	function () {
		$db = db_pk_seed();
		DoughBoss_Locations::update( 7, array( 'name' => 'Revesby', 'slug' => 'revesby', 'capacity_mode' => 'live', 'cutoff_minutes' => 25 ) );
		list( , $row, , $format ) = $db->last_update;
		assert_same( count( $row ), count( $format ), 'update: one format per column' );
		$strings = array( 'name', 'slug', 'suburb', 'address', 'phone', 'postcodes', 'timezone', 'capacity_mode', 'tyro_location_id' );
		$ok      = true;
		$i       = 0;
		foreach ( array_keys( $row ) as $key ) {
			$expected = in_array( $key, $strings, true ) ? '%s' : '%d';
			if ( $format[ $i ] !== $expected ) {
				$ok = false;
			}
			++$i;
		}
		assert_true( $ok, 'update: every column has the matching %s/%d format' );
		assert_same( 25, $row['cutoff_minutes'], 'update: cutoff persisted from input' );
		DoughBoss_Locations::create( array( 'name' => 'New Shop' ) );
		list( , $created, $created_format ) = $db->last_insert;
		assert_same( count( $created ), count( $created_format ), 'create: one format per column' );
		assert_same( 6, $created['slot_order_capacity'], 'create: default orders per slot is 6' );
	}
);

db_test(
	'capacity_live_config is null unless the shop is live, active and pickup-enabled',
	function () {
		foreach ( array( 'off', 'shadow' ) as $mode ) {
			db_pk_seed( array( 'capacity_mode' => $mode ) );
			assert_same( null, DoughBoss_Locations::capacity_live_config( 7 ), $mode . ' mode has no live config' );
		}
		db_pk_seed( array( 'is_active' => 0 ) );
		assert_same( null, DoughBoss_Locations::capacity_live_config( 7 ), 'inactive shop has no live config' );
		db_pk_seed( array( 'pickup_enabled' => 0 ) );
		assert_same( null, DoughBoss_Locations::capacity_live_config( 7 ), 'pickup-disabled shop has no live config' );
		db_pk_seed();
		assert_same( null, DoughBoss_Locations::capacity_live_config( 99 ), 'unknown shop has no live config' );
		assert_true( is_array( DoughBoss_Locations::capacity_live_config( 7 ) ), 'live, active shop builds a config' );
	}
);

db_test(
	'capacity_live_config maps shop settings onto the capacity engine config',
	function () {
		$db = db_pk_seed( array( 'slot_order_capacity' => 5, 'prep_time_default' => 20, 'busy_extra_minutes' => 10, 'cutoff_minutes' => 25, 'pickup_days_ahead' => 3, 'slot_minutes' => 20, 'hold_minutes' => 8 ), false );
		$db->hours = array( (object) array( 'weekday' => 1, 'opens_at' => '08:00:00', 'closes_at' => '12:00:00' ) );
		$db->exceptions[] = array( 'location_id' => 7, 'service_date' => '2026-12-25', 'is_closed' => 1 );
		$config = DoughBoss_Locations::capacity_live_config( 7 );
		assert_same( 5, $config['capacity_units'], 'capacity_units is the per-slot order limit' );
		assert_same( 30, $config['notice_minutes'], 'lead time is prep plus busy extra' );
		assert_same( 4, $config['horizon_days'], 'horizon is days ahead plus one' );
		assert_same( 25, $config['cutoff_minutes'], 'cutoff passes through' );
		assert_same( 20, $config['slot_minutes'], 'slot minutes pass through' );
		assert_same( 8, $config['hold_minutes'], 'hold minutes pass through' );
		assert_same( 'stored', $config['hours_source'], 'stored hours are preferred' );
		assert_same( array( array( '08:00', '12:00' ) ), $config['hours']['mon'], 'stored Monday range is used' );
		assert_same( array(), $config['hours']['tue'], 'unset days stay empty (published hours are not mixed in)' );
		assert_same( array( '2026-12-25' ), $config['blackout_dates'], 'exception dates are blackouts' );
		assert_true( true === $config['enabled'] && true === $config['active'] && true === $config['pickup_enabled'], 'engine gate flags are set' );
		assert_true( array() !== DoughBoss_Capacity::windows( $config, 1, array(), db_pk_utc( '2026-10-18T20:00:00Z' ) ), 'the config is consumable by windows()' );
	}
);

db_test(
	'capacity_live_config falls back to published hours only for known slugs',
	function () {
		$db = db_pk_seed( array(), false );
		$config = DoughBoss_Locations::capacity_live_config( 7 );
		assert_same( 'published', $config['hours_source'], 'revesby without stored hours uses published hours' );
		assert_same( array( array( '06:30', '14:30' ) ), $config['hours']['sun'], 'published Sunday hours' );
		assert_same( array( array( '06:30', '14:30' ) ), $config['hours']['sat'], 'published Saturday hours' );
		$db = db_pk_seed( array( 'slug' => 'mystery-shop' ), false );
		$config = DoughBoss_Locations::capacity_live_config( 7 );
		assert_same( 'none', $config['hours_source'], 'unknown shops have no published hours' );
		$all_empty = true;
		foreach ( $config['hours'] as $ranges ) {
			if ( $ranges ) {
				$all_empty = false;
			}
		}
		assert_true( $all_empty, 'hours are never invented' );
		assert_same( array(), DoughBoss_Capacity::windows( $config, 1, array(), db_pk_utc( '2026-10-15T20:00:00Z' ) ), 'no hours means no windows' );
		$bank = db_pk_seed( array( 'slug' => 'bankstown' ), false );
		$config = DoughBoss_Locations::capacity_live_config( 7 );
		assert_same( array( array( '07:00', '14:00' ) ), $config['hours']['mon'], 'bankstown published weekday hours' );
		assert_same( array(), $config['hours']['sat'], 'bankstown is closed Saturday in the published hours' );
	}
);

db_test(
	'horizon is days_ahead plus one: 0 is today only, 1 adds tomorrow, 7 gives eight days',
	function () {
		$now = db_pk_utc( '2026-10-15T23:30:00Z' ); // Fri 10:30 Sydney.
		foreach ( array( 0 => 1, 1 => 2, 7 => 8 ) as $ahead => $days ) {
			db_pk_seed( array( 'pickup_days_ahead' => $ahead ) );
			$config  = DoughBoss_Locations::capacity_live_config( 7 );
			assert_same( $days, $config['horizon_days'], "days_ahead {$ahead} gives horizon {$days}" );
			$options = DoughBoss_Pickup::compose_options( $config, array(), $now );
			assert_same( $days, count( $options['days'] ), "days_ahead {$ahead} lists {$days} day(s) of slots" );
			assert_same( $ahead, $options['days_ahead'], 'days_ahead is reported' );
		}
	}
);

db_test(
	'holiday closures round-trip through the exceptions table and block live windows',
	function () {
		$db = db_pk_seed();
		assert_true( true === DoughBoss_Locations::save_holiday_closures( 7, array( '2099-12-26', '2099-12-25', '2099-12-25', '' ) ), 'saving succeeds' );
		assert_same( array( '2099-12-25', '2099-12-26' ), DoughBoss_Locations::holiday_closures( 7 ), 'dates are de-duplicated and sorted' );
		assert_same( 2, count( $db->exceptions ), 'one pickup closure row per date' );
		$config = DoughBoss_Locations::capacity_live_config( 7 );
		assert_same( array( '2099-12-25', '2099-12-26' ), $config['blackout_dates'], 'closures appear as blackout dates' );
		$windows = DoughBoss_Capacity::windows( db_pk_config( array( 'blackout_dates' => array( '2026-10-16' ), 'notice_minutes' => 0 ) ), 1, array(), db_pk_utc( '2026-10-15T20:00:00Z' ) );
		assert_same( array(), db_pk_local_froms( $windows, '2026-10-16' ), 'a blacked-out date has no windows' );
		assert_true( true === DoughBoss_Locations::save_holiday_closures( 7, array() ), 'clearing succeeds' );
		assert_same( array(), DoughBoss_Locations::holiday_closures( 7 ), 'all closures cleared' );
		assert_same( array( '2099-12-25' ), ( function () use ( $db ) {
			DoughBoss_Locations::save_holiday_closures( 7, array( '2099-12-25' ) );
			return DoughBoss_Locations::holiday_closures( 7, '2099-12-01' );
		} )(), 'the from date filters earlier closures' );
	}
);

db_test(
	'holiday closure validation rejects bad dates before any write',
	function () {
		$db = db_pk_seed();
		$db->log = array();
		$result = DoughBoss_Locations::save_holiday_closures( 7, array( '2099-02-30' ) );
		assert_true( is_wp_error( $result ), 'impossible dates are rejected' );
		assert_same( 'doughboss_holiday_invalid', $result->get_error_code(), 'error code' );
		assert_true( is_wp_error( DoughBoss_Locations::save_holiday_closures( 7, array( '25/12/2099' ) ) ), 'wrong format is rejected' );
		assert_same( 0, $db->count_kind( 'write' ), 'validation failures do not touch the database' );
	}
);

db_test(
	'public_view exposes the live pickup keys and todays hours',
	function () {
		$now = db_pk_utc( '2026-10-15T23:30:00Z' ); // Fri 16 Oct 10:30 Sydney.
		db_pk_seed();
		$view = DoughBoss_Locations::public_view( (object) db_pk_location(), $now );
		assert_same( 'live', $view['capacity_mode'], 'capacity_mode' );
		assert_true( true === $view['pickup_live'], 'pickup_live is true for a live, active, pickup shop' );
		assert_true( false === $view['ordering_paused'], 'not paused' );
		assert_same( '6:30am–2:30pm', $view['today_hours'], 'today_hours from stored hours' );
		assert_same( 'stored', $view['hours_source'], 'hours_source stored' );
		assert_true( isset( $view['pickup_status'], $view['capacity_preview'], $view['prep_time'] ), 'existing public keys remain' );

		$off = DoughBoss_Locations::public_view( (object) db_pk_location( array( 'capacity_mode' => 'off' ) ), $now );
		assert_true( false === $off['pickup_live'], 'off shops are not pickup_live' );
		$paused = DoughBoss_Locations::public_view( (object) db_pk_location( array( 'ordering_paused' => 1 ) ), $now );
		assert_true( true === $paused['ordering_paused'], 'paused flag is exposed' );

		$db = db_pk_seed( array(), false );
		$db->hours = array( (object) array( 'weekday' => 1, 'opens_at' => '09:00:00', 'closes_at' => '12:00:00' ) );
		$closed = DoughBoss_Locations::public_view( (object) db_pk_location(), $now );
		assert_same( 'Closed today', $closed['today_hours'], 'a stored schedule without Friday hours is closed today' );

		$db = db_pk_seed( array(), false );
		$published = DoughBoss_Locations::public_view( (object) db_pk_location(), $now );
		assert_same( '6:30am–2:30pm', $published['today_hours'], 'published hours fill in for a known slug' );
		assert_same( 'published', $published['hours_source'], 'hours_source published' );

		$db = db_pk_seed( array( 'slug' => 'mystery-shop' ), false );
		$none = DoughBoss_Locations::public_view( (object) db_pk_location( array( 'slug' => 'mystery-shop' ) ), $now );
		assert_same( '', $none['today_hours'], 'unknown hours stay blank' );
		assert_same( 'none', $none['hours_source'], 'hours_source none' );

		$db = db_pk_seed();
		$db->exceptions[] = array( 'location_id' => 7, 'service_date' => '2026-10-16', 'is_closed' => 1 );
		$holiday = DoughBoss_Locations::public_view( (object) db_pk_location(), $now );
		assert_same( 'Closed today', $holiday['today_hours'], 'a holiday closure today shows closed' );
	}
);

// ---------------------------------------------------------------------------
// Store hours: format_pickup_time
// ---------------------------------------------------------------------------

db_test(
	'format_pickup_time uses Today, Tomorrow and dated labels in the shop timezone',
	function () {
		$now = db_pk_utc( '2026-10-16T01:00:00Z' ); // Fri 16 Oct 12:00 Sydney.
		assert_same( 'Today 12:45pm', DoughBoss_Store_Hours::format_pickup_time( '2026-10-16 01:45:00', 'Australia/Sydney', $now ), 'MySQL UTC input, same local day' );
		assert_same( 'Today 12:45pm', DoughBoss_Store_Hours::format_pickup_time( '2026-10-16T01:45:00Z', 'Australia/Sydney', $now ), 'ISO input' );
		assert_same( 'Tomorrow 7:30am', DoughBoss_Store_Hours::format_pickup_time( '2026-10-16 20:30:00', 'Australia/Sydney', $now ), 'next local day' );
		assert_same( 'Mon 19 Oct, 12:45pm', DoughBoss_Store_Hours::format_pickup_time( '2026-10-19 01:45:00', 'Australia/Sydney', $now ), 'later days are dated' );
		assert_same( 'Thu 15 Oct, 12:45pm', DoughBoss_Store_Hours::format_pickup_time( '2026-10-15 01:45:00', 'Australia/Sydney', $now ), 'past days are dated' );
		assert_same( 'Today 12am', DoughBoss_Store_Hours::format_pickup_time( '2026-10-15 13:00:00', 'Australia/Sydney', $now ), 'local midnight is 12:00am' );
		assert_same( 'Today 6am', DoughBoss_Store_Hours::format_pickup_time( '2026-10-15 19:00:00', 'Australia/Sydney', $now ), 'on-the-hour times omit minutes' );

		// Day words are relative to the shop clock, not UTC.
		$late = db_pk_utc( '2026-10-16T14:30:00Z' ); // Sat 17 Oct 01:30 Sydney.
		assert_same( 'Today 6:30am', DoughBoss_Store_Hours::format_pickup_time( '2026-10-16 19:30:00', 'Australia/Sydney', $late ), 'UTC date differs from the shop date' );

		// DST boundaries: 06:30 AEST (Oct 3) vs 06:30 AEDT (Oct 4).
		$before = db_pk_utc( '2026-10-02T12:00:00Z' );
		assert_same( 'Tomorrow 6:30am', DoughBoss_Store_Hours::format_pickup_time( '2026-10-02 20:30:00', 'Australia/Sydney', $before ), 'before DST start' );
		assert_same( 'Sun 4 Oct, 6:30am', DoughBoss_Store_Hours::format_pickup_time( '2026-10-03 19:30:00', 'Australia/Sydney', $before ), 'after DST start' );
		assert_same( 'Sun 4 Apr, 6:30am', DoughBoss_Store_Hours::format_pickup_time( '2027-04-03 20:30:00', 'Australia/Sydney', db_pk_utc( '2027-04-01T12:00:00Z' ) ), 'after DST end' );

		assert_same( 'Today 11:45am', DoughBoss_Store_Hours::format_pickup_time( new DateTimeImmutable( '2026-10-16T00:45:00Z' ), 'Australia/Sydney', $now ), 'DateTime input' );
		assert_same( 'Today 11:45am', DoughBoss_Store_Hours::format_pickup_time( '2026-10-16 01:45:00', 'Australia/Brisbane', $now ), 'other shop timezones' );
		assert_same( 'Today 12:45pm', DoughBoss_Store_Hours::format_pickup_time( '2026-10-16 01:45:00', 'Not/AZone', $now ), 'an unknown timezone falls back to Sydney' );
		assert_same( '', DoughBoss_Store_Hours::format_pickup_time( '', 'Australia/Sydney', $now ), 'empty input' );
		assert_same( '', DoughBoss_Store_Hours::format_pickup_time( 'tomorrow noon', 'Australia/Sydney', $now ), 'free text is rejected' );
		assert_same( '', DoughBoss_Store_Hours::format_pickup_time( '2026-13-45 99:99:99', 'Australia/Sydney', $now ), 'impossible dates are rejected' );
		assert_same( '', DoughBoss_Store_Hours::format_pickup_time( null, 'Australia/Sydney', $now ), 'null input' );
	}
);

// ---------------------------------------------------------------------------
// ASAP selection and option composition (pure)
// ---------------------------------------------------------------------------

/** @return array Window list shaped like windows(). */
function db_pk_windows( array $specs ) {
	$out = array();
	foreach ( $specs as $spec ) {
		$from = db_pk_utc( $spec[0] );
		$out[] = array(
			'ready_from_utc' => $from->format( 'Y-m-d\TH:i:s\Z' ),
			'ready_by_utc'   => $from->modify( '+15 minutes' )->format( 'Y-m-d\TH:i:s\Z' ),
			'local_date'     => $from->setTimezone( new DateTimeZone( 'Australia/Sydney' ) )->format( 'Y-m-d' ),
			'availability'   => isset( $spec[1] ) ? $spec[1] : 'available',
		);
	}
	return $out;
}

db_test(
	'asap_from_windows: closed shop, full first window, eta and tomorrow-only cases',
	function () {
		$now     = db_pk_utc( '2026-10-15T23:30:00Z' ); // Fri 10:30 Sydney.
		$windows = db_pk_windows( array( array( '2026-10-16T00:00:00Z' ), array( '2026-10-16T00:15:00Z' ) ) );
		assert_same( null, DoughBoss_Pickup::asap_from_windows( $windows, false, $now, 'Australia/Sydney' ), 'closed now means no ASAP' );
		$asap = DoughBoss_Pickup::asap_from_windows( $windows, true, $now, 'Australia/Sydney' );
		assert_same( '2026-10-16T00:00:00Z', $asap['ready_from_utc'], 'the first available window' );
		assert_same( '2026-10-16T00:15:00Z', $asap['ready_by_utc'], 'ready_by' );
		assert_same( 45, $asap['eta_minutes'], 'eta is minutes until ready_by (10:30 to 11:15)' );
		assert_same( 'ASAP — ready in about 45 min', $asap['label'], 'label' );

		$full_first = db_pk_windows( array( array( '2026-10-16T00:00:00Z', 'full' ), array( '2026-10-16T00:15:00Z' ) ) );
		$next       = DoughBoss_Pickup::asap_from_windows( $full_first, true, $now, 'Australia/Sydney' );
		assert_same( '2026-10-16T00:15:00Z', $next['ready_from_utc'], 'a full first window pushes ASAP to the next' );
		assert_same( 60, $next['eta_minutes'], 'eta follows the later window' );

		$all_full = db_pk_windows( array( array( '2026-10-16T00:00:00Z', 'full' ) ) );
		assert_same( null, DoughBoss_Pickup::asap_from_windows( $all_full, true, $now, 'Australia/Sydney' ), 'nothing available today means no ASAP' );

		$tomorrow = db_pk_windows( array( array( '2026-10-16T20:30:00Z' ) ) ); // Sat 07:30 Sydney.
		assert_same( null, DoughBoss_Pickup::asap_from_windows( $tomorrow, true, $now, 'Australia/Sydney' ), 'tomorrow windows are never ASAP' );
		assert_same( null, DoughBoss_Pickup::asap_from_windows( array(), true, $now, new DateTimeZone( 'Australia/Sydney' ) ), 'no windows' );
		assert_same( null, DoughBoss_Pickup::asap_from_windows( $windows, true, $now, 'Not/AZone' ), 'bad timezone fails closed' );

		$partial = db_pk_utc( '2026-10-16T00:25:30Z' );
		assert_same( 5, DoughBoss_Pickup::asap_from_windows( db_pk_windows( array( array( '2026-10-16T00:15:00Z' ) ) ), true, $partial, 'Australia/Sydney' )['eta_minutes'], 'partial minutes round up' );
	}
);

db_test(
	'compose_options shapes days, slots, ASAP and the closed reasons',
	function () {
		$now     = db_pk_utc( '2026-10-15T23:30:00Z' ); // Fri 10:30 Sydney, open.
		$options = DoughBoss_Pickup::compose_options( db_pk_config(), array(), $now );
		assert_same( 'live', $options['mode'], 'mode' );
		assert_true( true === $options['enforce'] && false === $options['paused'], 'enforcing and not paused' );
		assert_same( '', $options['closed_reason'], 'open shop has no closed reason' );
		assert_same( 'Australia/Sydney', $options['timezone'], 'timezone' );
		assert_same( '2026-10-15T23:30:00Z', $options['server_now_utc'], 'server clock' );
		assert_same( 20, $options['prep_minutes'], 'prep minutes' );
		assert_same( 20, $options['lead_minutes'], 'lead minutes' );
		assert_same( 15, $options['slot_minutes'], 'slot minutes' );
		assert_same( 10, $options['hold_minutes'], 'hold minutes' );
		assert_same( 1, $options['days_ahead'], 'days ahead' );
		assert_same( 2, count( $options['days'] ), 'today and tomorrow' );
		assert_same( 'Today', $options['days'][0]['label'], 'first day label' );
		assert_same( 'Tomorrow', $options['days'][1]['label'], 'second day label' );
		assert_same( '2026-10-16', $options['days'][0]['date'], 'first day date' );
		$first = $options['days'][0]['slots'][0];
		assert_same( '11:00', $first['local_from'], 'first slot after 20 minutes lead on a 15 minute grid' );
		assert_same( '11:15', $first['local_by'], 'slot end' );
		assert_same( '11am', $first['label'], 'slot label' );
		assert_true( true === $first['available'], 'slot available' );
		assert_same( 6, $first['remaining'], 'slot remaining' );
		assert_same( '2026-10-16T00:00:00Z', $first['ready_from_utc'], 'public slot identity is the UTC start' );
		assert_same( 45, $options['asap']['eta_minutes'], 'asap eta' );
		$today_last = end( $options['days'][0]['slots'] );
		assert_same( '14:00', $today_last['local_from'], 'cut-off removes the 14:15 start' );

		// Load expressed through capacity.
		$busy = DoughBoss_Pickup::compose_options( db_pk_config(), array( '2026-10-16 00:00:00' => 6 ), $now );
		assert_false( $busy['days'][0]['slots'][0]['available'], 'a full slot is unavailable' );
		assert_same( 0, $busy['days'][0]['slots'][0]['remaining'], 'a full slot has nothing remaining' );
		assert_same( '2026-10-16T00:15:00Z', $busy['asap']['ready_from_utc'], 'ASAP skips the full slot' );

		// After closing: no ASAP, tomorrow only.
		$after = DoughBoss_Pickup::compose_options( db_pk_config(), array(), db_pk_utc( '2026-10-16T05:00:00Z' ) ); // 16:00.
		assert_same( 'closed_now', $after['closed_reason'], 'closed after hours' );
		assert_same( null, $after['asap'], 'no ASAP after hours' );
		assert_same( 'Tomorrow', $after['days'][0]['label'], 'only tomorrow is offered' );

		// Before opening: closed now but today still bookable.
		$early = DoughBoss_Pickup::compose_options( db_pk_config(), array(), db_pk_utc( '2026-10-15T19:00:00Z' ) ); // 06:00.
		assert_same( 'closed_now', $early['closed_reason'], 'closed before opening' );
		assert_same( null, $early['asap'], 'no ASAP before opening' );
		assert_same( 'Today', $early['days'][0]['label'], 'today slots are still listed' );

		$no_hours = DoughBoss_Pickup::compose_options( db_pk_config( array( 'hours' => db_pk_week( '06:30', '14:30', array() ) ) ), array(), $now );
		assert_same( 'no_hours', $no_hours['closed_reason'], 'no hours' );
		assert_same( array(), $no_hours['days'], 'no hours means no days' );
		$blocked = DoughBoss_Pickup::compose_options( db_pk_config( array( 'blackout_dates' => array( '2026-10-16', '2026-10-17' ) ) ), array(), $now );
		assert_same( 'no_slots', $blocked['closed_reason'], 'no slots when every date is blacked out' );

		$overnight = DoughBoss_Pickup::shop_open_now( db_pk_config( array( 'hours' => db_pk_week( '22:00', '02:00' ) ) ), db_pk_utc( '2026-10-15T15:30:00Z' ) ); // Fri 02:30? (Sat 02:30 AEDT is 15:30Z Fri)
		assert_false( $overnight, 'overnight ranges close at their end' );
		assert_true( DoughBoss_Pickup::shop_open_now( db_pk_config( array( 'hours' => db_pk_week( '22:00', '02:00' ) ) ), db_pk_utc( '2026-10-15T14:30:00Z' ) ), 'an overnight range is open after midnight' );
	}
);

// ---------------------------------------------------------------------------
// Capacity engine: materialise, usage, peek, release, lock_hold_for_checkout
// ---------------------------------------------------------------------------

db_test(
	'materialise_slot upserts one durable slot per start and refreshes the limit',
	function () {
		$db      = db_pk_seed();
		$now     = db_pk_utc( '2026-10-15T23:30:00Z' );
		$config  = db_pk_config();
		$windows = DoughBoss_Capacity::windows( $config, 1, array(), $now );
		$id      = DoughBoss_Capacity::materialise_slot( $config, $windows[0], $now );
		assert_true( is_int( $id ) && $id > 0, 'an integer slot id is returned' );
		assert_same( $id, DoughBoss_Capacity::materialise_slot( $config, $windows[0], $now ), 'the same window resolves to the same row' );
		assert_same( 1, count( $db->slots ), 'one row per window' );
		$slot = $db->slots[ $id ];
		assert_same( '2026-10-16 00:00:00', $slot['starts_at_utc'], 'start stored as UTC MySQL' );
		assert_same( '2026-10-16 00:15:00', $slot['ends_at_utc'], 'end stored as UTC MySQL' );
		assert_same( 10000, $slot['unit_capacity'], 'item-unit limit is disabled' );
		assert_same( 6, $slot['order_capacity'], 'order limit from the config' );
		assert_same( 'Australia/Sydney', $slot['timezone_snapshot'], 'timezone snapshot' );
		$id2 = DoughBoss_Capacity::materialise_slot( db_pk_config( array( 'capacity_units' => 9, 'planning_version' => 4 ) ), $windows[0], $now );
		assert_same( $id, $id2, 'a changed limit keeps the row' );
		assert_same( 9, $db->slots[ $id ]['order_capacity'], 'order limit refreshed' );
		assert_same( 4, $db->slots[ $id ]['planning_version'], 'planning version refreshed' );
		assert_true( is_wp_error( DoughBoss_Capacity::materialise_slot( $config, array( 'ready_from_utc' => 'x', 'ready_by_utc' => 'y' ), $now ) ), 'a malformed window is refused' );
		assert_true( is_wp_error( DoughBoss_Capacity::materialise_slot( db_pk_config( array( 'capacity_units' => 0 ) ), $windows[0], $now ) ), 'a zero limit is refused' );
	}
);

db_test(
	'usage_for_location counts converted and live holds in one query',
	function () {
		$db   = db_pk_seed();
		$now  = db_pk_utc( '2026-10-15T23:30:00Z' );
		$a    = db_pk_make_hold( $now, 'idem-key-0000000001' );
		$b    = db_pk_make_hold( $now, 'idem-key-0000000002' );
		$c    = db_pk_make_hold( $now, 'idem-key-0000000003', 1 );
		// Expire hold b and convert hold c.
		$db->holds[ $b['hold']['hold_id'] ]['status'] = 'expired';
		$db->holds[ $c['hold']['hold_id'] ]['status'] = 'converted';
		$db->log = array();
		$usage   = DoughBoss_Capacity::usage_for_location( 7, '2026-10-15 23:00:00', '2026-10-18 00:00:00', $now );
		assert_same( 1, $db->count_kind( 'read' ), 'usage is computed with a single query' );
		assert_same( 1, $usage['2026-10-16 00:00:00'], 'a live hold counts once' );
		assert_same( 1, $usage['2026-10-16 00:15:00'], 'a converted hold counts' );
		assert_same( 2, count( $usage ), 'expired holds are excluded' );
		$later = DoughBoss_Capacity::usage_for_location( 7, '2026-10-15 23:00:00', '2026-10-18 00:00:00', db_pk_utc( '2026-10-16T00:20:00Z' ) );
		assert_false( isset( $later['2026-10-16 00:00:00'] ), 'a hold past its expiry is ignored' );
		assert_same( array(), DoughBoss_Capacity::usage_for_location( 8, '2026-10-15 23:00:00', '2026-10-18 00:00:00', $now ), 'other shops are separate' );
		assert_same( 1, DoughBoss_Capacity::usage_for_location( 7, '2026-10-15 23:00:00', '2026-10-18 00:00:00', '2026-10-15 23:30:00' )['2026-10-16 00:15:00'], 'a MySQL clock string is accepted' );
	}
);

db_test(
	'peek_hold reports status, ownership and match flags without locking',
	function () {
		$db   = db_pk_seed();
		$now  = db_pk_utc( '2026-10-15T23:30:00Z' );
		$made = db_pk_make_hold( $now );
		$token = $made['hold']['hold_token'];
		$db->log = array();
		$peek = DoughBoss_Capacity::peek_hold( $token, db_pk_hash(), 7, $now );
		assert_false( $db->sql_like( 'FOR UPDATE' ), 'peek takes no locks' );
		assert_same( 'held', $peek['status'], 'fresh hold' );
		assert_same( $made['slot_id'], $peek['slot_id'], 'slot id' );
		assert_same( '2026-10-16T00:00:00Z', $peek['ready_from_utc'], 'ready_from' );
		assert_same( '2026-10-16T00:15:00Z', $peek['ready_by_utc'], 'ready_by' );
		assert_same( 'Australia/Sydney', $peek['timezone'], 'timezone' );
		assert_same( '2026-10-15T23:40:00Z', $peek['expires_at_utc'], 'expires after the hold window' );
		assert_true( $peek['cart_matches'] && $peek['location_matches'], 'matching cart and shop' );
		assert_false( DoughBoss_Capacity::peek_hold( $token, db_pk_hash( 22.00 ), 7, $now )['cart_matches'], 'a changed cart does not match' );
		assert_false( DoughBoss_Capacity::peek_hold( $token, db_pk_hash(), 8, $now )['location_matches'], 'another shop does not match' );
		assert_same( 'expired', DoughBoss_Capacity::peek_hold( $token, db_pk_hash(), 7, db_pk_utc( '2026-10-15T23:41:00Z' ) )['status'], 'a held hold past expiry reads as expired' );
		assert_same( 'missing', DoughBoss_Capacity::peek_hold( str_repeat( 'a', 64 ), db_pk_hash(), 7, $now )['status'], 'unknown token' );
		assert_same( 'missing', DoughBoss_Capacity::peek_hold( 'short', db_pk_hash(), 7, $now )['status'], 'malformed token' );
		assert_same( 'missing', DoughBoss_Capacity::peek_hold( strtoupper( $token ), db_pk_hash(), 7, $now )['status'], 'tokens are lowercase hex' );
		$db->holds[ $made['hold']['hold_id'] ]['status'] = 'converted';
		assert_same( 'converted', DoughBoss_Capacity::peek_hold( $token, db_pk_hash(), 7, $now )['status'], 'converted' );
	}
);

db_test(
	'release_hold and release_order_hold free capacity',
	function () {
		$db    = db_pk_seed();
		$now   = db_pk_utc( '2026-10-15T23:30:00Z' );
		$made  = db_pk_make_hold( $now );
		$token = $made['hold']['hold_token'];
		assert_true( DoughBoss_Capacity::release_hold( $token ), 'a held hold is released' );
		assert_false( DoughBoss_Capacity::release_hold( $token ), 'releasing twice is a no-op' );
		assert_same( 'released', $db->holds[ $made['hold']['hold_id'] ]['status'], 'status released' );
		assert_false( DoughBoss_Capacity::release_hold( 'short' ), 'malformed token' );
		assert_same( array(), DoughBoss_Capacity::usage_for_location( 7, '2026-10-15 23:00:00', '2026-10-18 00:00:00', $now ), 'released holds no longer count' );

		$other = db_pk_make_hold( $now, 'idem-key-0000000009' );
		$db->holds[ $other['hold']['hold_id'] ]['status']   = 'converted';
		$db->holds[ $other['hold']['hold_id'] ]['order_id'] = 55;
		assert_true( DoughBoss_Capacity::release_order_hold( 55 ), 'a cancelled order releases its converted hold' );
		assert_same( 'released', $db->holds[ $other['hold']['hold_id'] ]['status'], 'status released' );
		assert_same( 55, $db->holds[ $other['hold']['hold_id'] ]['order_id'], 'order_id is kept for audit' );
		assert_false( DoughBoss_Capacity::release_order_hold( 55 ), 'idempotent' );
		assert_false( DoughBoss_Capacity::release_order_hold( 0 ), 'invalid order id' );
	}
);

db_test(
	'lock_hold_for_checkout: unpaid behaves like lock_hold_for_order',
	function () {
		$db    = db_pk_seed();
		$now   = db_pk_utc( '2026-10-15T23:30:00Z' );
		$made  = db_pk_make_hold( $now );
		$token = $made['hold']['hold_token'];
		$hash  = db_pk_hash();
		$old   = DoughBoss_Capacity::lock_hold_for_order( $token, $hash, 7, $now );
		$new   = DoughBoss_Capacity::lock_hold_for_checkout( $token, $hash, 7, array( 'paid' => false, 'prep_minutes' => 20, 'slot_minutes' => 15 ), $now );
		foreach ( array( 'hold_id', 'capacity_units', 'promised_ready_from_utc', 'promised_ready_by_utc', 'timezone_snapshot', 'planning_version' ) as $key ) {
			assert_same( $old[ $key ], $new[ $key ], "unpaid result matches lock_hold_for_order for {$key}" );
		}
		assert_same( '2026-10-16 00:00:00', $new['promised_ready_from_utc'], 'promise is the slot start' );
		assert_same( '2026-10-15 23:40:00', $new['fire_at_utc'], 'fire_at is ready_from minus prep' );
		assert_true( false === $new['reacquired'] && false === $new['overflow'] && false === $new['shifted'], 'no special flags on the happy path' );
		assert_same( null, $old['fire_at_utc'], 'the legacy method is unchanged' );

		$no_prep = DoughBoss_Capacity::lock_hold_for_checkout( $token, $hash, 7, array( 'paid' => false ), $now );
		assert_same( null, $no_prep['fire_at_utc'], 'no fire_at without prep minutes' );

		$expired_at = db_pk_utc( '2026-10-15T23:41:00Z' );
		$expired    = DoughBoss_Capacity::lock_hold_for_checkout( $token, $hash, 7, array( 'paid' => false ), $expired_at );
		assert_same( 'doughboss_hold_expired', $expired->get_error_code(), 'unpaid orders never revive an expired hold' );
		$passed = DoughBoss_Capacity::lock_hold_for_checkout( $token, $hash, 7, array( 'paid' => false ), db_pk_utc( '2026-10-16T00:05:00Z' ) );
		assert_true( is_wp_error( $passed ), 'unpaid orders are refused once the slot has started' );

		assert_same( 'doughboss_hold_mismatch', DoughBoss_Capacity::lock_hold_for_checkout( $token, db_pk_hash( 99 ), 7, array( 'paid' => true ), $now )->get_error_code(), 'a different cart is refused even when paid' );
		assert_same( 'doughboss_hold_mismatch', DoughBoss_Capacity::lock_hold_for_checkout( $token, $hash, 8, array( 'paid' => true ), $now )->get_error_code(), 'another shop is refused even when paid' );
		assert_same( 'doughboss_hold_missing', DoughBoss_Capacity::lock_hold_for_checkout( str_repeat( 'b', 64 ), $hash, 7, array( 'paid' => true ), $now )->get_error_code(), 'an unknown token is refused' );
		assert_same( 'doughboss_hold_invalid', DoughBoss_Capacity::lock_hold_for_checkout( 'short', $hash, 7, array( 'paid' => true ), $now )->get_error_code(), 'a malformed token is refused' );

		$db->holds[ $made['hold']['hold_id'] ]['status']   = 'converted';
		$db->holds[ $made['hold']['hold_id'] ]['order_id'] = 31;
		$db->orders[31] = array( 'id' => 31, 'capacity_hold_id' => $made['hold']['hold_id'] );
		$replay = DoughBoss_Capacity::lock_hold_for_checkout( $token, $hash, 7, array( 'paid' => true ), $now );
		assert_same( 31, $replay['replayed_order_id'], 'a converted hold replays its order' );
		$db->orders[31]['capacity_hold_id'] = 0;
		assert_same( 'doughboss_hold_corrupt', DoughBoss_Capacity::lock_hold_for_checkout( $token, $hash, 7, array( 'paid' => true ), $now )->get_error_code(), 'a half-linked allocation is flagged' );
	}
);

db_test(
	'lock_hold_for_checkout: paid orders reacquire, overflow and shift instead of failing',
	function () {
		$db    = db_pk_seed();
		$now   = db_pk_utc( '2026-10-15T23:30:00Z' );
		$made  = db_pk_make_hold( $now );
		$token = $made['hold']['hold_token'];
		$hash  = db_pk_hash();
		$hold_id = $made['hold']['hold_id'];
		$opts  = array( 'paid' => true, 'prep_minutes' => 20, 'slot_minutes' => 15 );

		// Hold expired during payment, room in the slot: reacquired.
		$later = db_pk_utc( '2026-10-15T23:45:00Z' );
		$lock  = DoughBoss_Capacity::lock_hold_for_checkout( $token, $hash, 7, $opts, $later );
		assert_false( is_wp_error( $lock ), 'a paid order with an expired hold is accepted' );
		assert_true( true === $lock['reacquired'] && false === $lock['overflow'] && false === $lock['shifted'], 'reacquired without overflow' );
		assert_same( 'held', $db->holds[ $hold_id ]['status'], 'the hold is held again' );
		assert_same( '2026-10-15 23:46:00', $db->holds[ $hold_id ]['expires_at'], 'revived for sixty seconds' );
		assert_same( '2026-10-16 00:00:00', $lock['promised_ready_from_utc'], 'the original slot is kept' );
		assert_true( DoughBoss_Capacity::convert_locked_hold( $lock['hold_id'], 77, '2026-10-15 23:45:00' ), 'the revived hold converts' );

		// Slot filled by other customers meanwhile: still accepted, flagged overflow.
		$db2   = db_pk_seed( array( 'slot_order_capacity' => 1 ) );
		$a     = db_pk_make_hold( $now, 'idem-key-0000000001' );
		$config = db_pk_config( array( 'capacity_units' => 1 ) );
		$db2->slots[ $a['slot_id'] ]['order_capacity'] = 1;
		$db2->holds[ $a['hold']['hold_id'] ]['status'] = 'expired';
		$db2->holds[] = array( 'id' => 50, 'slot_id' => $a['slot_id'], 'token_hash' => 'x', 'idempotency_key' => 'other-customer-000001', 'cart_hash' => 'y', 'status' => 'converted', 'capacity_units' => 2, 'expires_at' => '2026-10-15 23:40:00', 'order_id' => 12, 'converted_at' => null, 'released_at' => null );
		$unpaid = DoughBoss_Capacity::lock_hold_for_checkout( $a['hold']['hold_token'], $hash, 7, array( 'paid' => false ), $later );
		assert_same( 'doughboss_hold_expired', $unpaid->get_error_code(), 'unpaid orders stay strict on the same data' );
		$over = DoughBoss_Capacity::lock_hold_for_checkout( $a['hold']['hold_token'], $hash, 7, $opts, $later );
		assert_false( is_wp_error( $over ), 'a paid order is never refused because the slot filled' );
		assert_true( true === $over['reacquired'] && true === $over['overflow'], 'flagged as overflow' );

		// Slot already started when payment completed: shifted to now + prep.
		$db3  = db_pk_seed();
		$b    = db_pk_make_hold( $now );
		$late = db_pk_utc( '2026-10-16T00:10:00Z' );
		$shift = DoughBoss_Capacity::lock_hold_for_checkout( $b['hold']['hold_token'], $hash, 7, $opts, $late );
		assert_false( is_wp_error( $shift ), 'a paid order after its slot started is accepted' );
		assert_true( true === $shift['shifted'], 'flagged as shifted' );
		assert_same( '2026-10-16 00:30:00', $shift['promised_ready_from_utc'], 'promise is now plus prep' );
		assert_same( '2026-10-16 00:45:00', $shift['promised_ready_by_utc'], 'window is one slot long' );
		assert_same( '2026-10-16 00:10:00', $shift['fire_at_utc'], 'fire_at is now' );

		// Cancelled orders keep their released hold released.
		$db4 = db_pk_seed();
		$c   = db_pk_make_hold( $now );
		$db4->holds[ $c['hold']['hold_id'] ]['status']   = 'released';
		$db4->holds[ $c['hold']['hold_id'] ]['order_id'] = 40;
		assert_same( 'doughboss_hold_expired', DoughBoss_Capacity::lock_hold_for_checkout( $c['hold']['hold_token'], $hash, 7, $opts, $now )->get_error_code(), 'a cancelled order\'s hold is not revived' );

		// A hold the customer released (cart change) can still be reacquired by a paid order.
		$db5 = db_pk_seed();
		$d   = db_pk_make_hold( $now );
		DoughBoss_Capacity::release_hold( $d['hold']['hold_token'] );
		$re  = DoughBoss_Capacity::lock_hold_for_checkout( $d['hold']['hold_token'], $hash, 7, $opts, $now );
		assert_false( is_wp_error( $re ), 'a client-released hold is revived for a paid order' );
		assert_true( $re['reacquired'], 'reacquired' );
	}
);

// ---------------------------------------------------------------------------
// Pickup: requirement, checkout selection, REST handlers
// ---------------------------------------------------------------------------

db_test(
	'requirement is true only for live pickup orders without a table context',
	function () {
		db_pk_seed();
		assert_same( array( 'mode' => 'live', 'required' => true, 'paused' => false ), DoughBoss_Pickup::requirement( 7, 'pickup', false ), 'live pickup' );
		assert_false( DoughBoss_Pickup::requirement( 7, 'delivery', false )['required'], 'delivery is not scheduled' );
		assert_false( DoughBoss_Pickup::requirement( 7, 'pickup', true )['required'], 'table orders are not scheduled' );
		db_pk_seed( array( 'ordering_paused' => 1 ) );
		$paused = DoughBoss_Pickup::requirement( 7, 'pickup', false );
		assert_true( $paused['paused'] && 'live' === $paused['mode'], 'paused is reported' );
		db_pk_seed( array( 'capacity_mode' => 'off' ) );
		assert_same( array( 'mode' => 'off', 'required' => false, 'paused' => false ), DoughBoss_Pickup::requirement( 7, 'pickup', false ), 'off mode never requires a time' );
		db_pk_seed( array( 'capacity_mode' => 'shadow' ) );
		assert_same( 'shadow', DoughBoss_Pickup::requirement( 7, 'pickup', false )['mode'], 'shadow mode is reported but not required' );
		db_pk_seed();
		update_option( 'doughboss_db_version', '1.29.0' );
		assert_same( 'off', DoughBoss_Pickup::requirement( 7, 'pickup', false )['mode'], 'live is ignored until the 1.30.0 schema exists' );
		assert_same( 'off', DoughBoss_Pickup::requirement( 404, 'pickup', false )['mode'], 'unknown shop' );
	}
);

/** Install the stub cart as the one the pickup handlers use. */
function db_pk_use_cart( DB_PK_Cart $cart ) {
	$property = new ReflectionProperty( 'DoughBoss_Pickup', 'cart' );
	$property->setAccessible( true );
	$property->setValue( null, $cart );
}

/** First offered scheduled slot start for the seeded shop (real clock). */
function db_pk_first_slot() {
	$options = DoughBoss_Pickup::options_for_location( DoughBoss_Locations::get( 7 ), new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
	foreach ( $options['days'] as $day ) {
		foreach ( $day['slots'] as $slot ) {
			if ( $slot['available'] ) {
				return $slot['ready_from_utc'];
			}
		}
	}
	return '';
}

db_test(
	'prepare_checkout_selection: nothing added unless a live pickup order needs a time',
	function () {
		$cart = new DB_PK_Cart();
		db_pk_seed( array( 'capacity_mode' => 'off' ) );
		assert_same( array(), DoughBoss_Pickup::prepare_checkout_selection( $cart, 7, 'pickup', array(), true ), 'off mode adds nothing' );
		assert_same( array(), DoughBoss_Pickup::prepare_checkout_selection( $cart, 7, 'pickup', array( 'pickup_mode' => 'asap', 'capacity_hold_token' => str_repeat( 'a', 64 ) ), false ), 'off mode ignores stray pickup input' );
		db_pk_seed( array( 'capacity_mode' => 'shadow' ) );
		assert_same( array(), DoughBoss_Pickup::prepare_checkout_selection( $cart, 7, 'pickup', array(), true ), 'shadow mode adds nothing' );
		db_pk_seed();
		assert_same( array(), DoughBoss_Pickup::prepare_checkout_selection( $cart, 7, 'delivery', array(), true ), 'delivery adds nothing' );
		assert_same( array(), DoughBoss_Pickup::prepare_checkout_selection( $cart, 7, 'dine_in', array(), true ), 'dine-in adds nothing' );
	}
);

db_test(
	'prepare_checkout_selection validates the hold and returns the checkout facts',
	function () {
		$cart = new DB_PK_Cart();
		$db   = db_pk_seed();
		db_pk_use_cart( $cart );
		$slot = db_pk_first_slot();
		assert_true( '' !== $slot, 'the seeded shop offers a slot' );
		$held = DoughBoss_Pickup::create_hold( new DB_PK_Request( array( 'location_id' => 7, 'pickup_mode' => 'scheduled', 'ready_from_utc' => $slot, 'idempotency_key' => 'idem-key-00000000a1' ) ) );
		assert_false( is_wp_error( $held ), 'a hold is created' );
		$hold = $held->get_data();
		$ok = DoughBoss_Pickup::prepare_checkout_selection( $cart, 7, 'pickup', array( 'pickup_mode' => 'scheduled', 'capacity_hold_token' => $hold['hold_token'] ), true );
		assert_false( is_wp_error( $ok ), 'a valid hold is accepted' );
		assert_same( array( 'pickup_mode', 'capacity_hold_token', 'pickup_slot_id', 'pickup_ready_from_utc', 'pickup_ready_by_utc', 'pickup_label', 'capacity_unpaid_allowed' ), array_keys( $ok ), 'contract keys and order' );
		assert_same( 'scheduled', $ok['pickup_mode'], 'mode echoed' );
		assert_same( $hold['hold_token'], $ok['capacity_hold_token'], 'token echoed' );
		assert_same( $hold['slot_id'], $ok['pickup_slot_id'], 'slot id' );
		assert_same( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $slot ), $ok['pickup_ready_from_utc'], 'ready_from is MySQL UTC' );
		assert_true( (bool) preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ok['pickup_ready_by_utc'] ), 'ready_by is MySQL UTC' );
		assert_true( (bool) preg_match( '/^(Today|Tomorrow) \d{1,2}(:\d{2})?(am|pm)$/', $ok['pickup_label'] ), 'label is customer-readable: ' . $ok['pickup_label'] );
		assert_false( $ok['capacity_unpaid_allowed'], 'an online-paid order does not allow unpaid conversion' );
		$unpaid = DoughBoss_Pickup::prepare_checkout_selection( $cart, 7, 'pickup', array( 'pickup_mode' => 'asap', 'capacity_hold_token' => $hold['hold_token'] ), false );
		assert_true( $unpaid['capacity_unpaid_allowed'], 'pay-at-shop allows unpaid conversion' );
		assert_same( 'asap', $unpaid['pickup_mode'], 'asap accepted' );

		// Error codes.
		$code = function ( $input ) use ( $cart ) {
			$result = DoughBoss_Pickup::prepare_checkout_selection( $cart, 7, 'pickup', $input, true );
			return is_wp_error( $result ) ? $result->get_error_code() : 'ok';
		};
		assert_same( 'doughboss_pickup_required', $code( array() ), 'no selection' );
		assert_same( 'doughboss_pickup_required', $code( array( 'pickup_mode' => 'scheduled' ) ), 'no token' );
		assert_same( 'doughboss_pickup_required', $code( array( 'pickup_mode' => 'scheduled', 'capacity_hold_token' => 'not-a-token' ) ), 'malformed token' );
		assert_same( 'doughboss_pickup_required', $code( array( 'pickup_mode' => 'later', 'capacity_hold_token' => $hold['hold_token'] ) ), 'bad mode' );
		assert_same( 'doughboss_pickup_required', $code( array( 'pickup_mode' => 'scheduled', 'capacity_hold_token' => str_repeat( 'c', 64 ) ) ), 'unknown token' );
		$error = DoughBoss_Pickup::prepare_checkout_selection( $cart, 7, 'pickup', array(), true );
		assert_same( 400, $error->get_error_data()['status'], 'pickup_required is a 400' );
		assert_true( ! empty( $error->get_error_data()['retry_safe'] ), 'pickup_required is retry safe' );

		$changed = new DB_PK_Cart();
		$changed->total = 25.00;
		$stale = DoughBoss_Pickup::prepare_checkout_selection( $changed, 7, 'pickup', array( 'pickup_mode' => 'scheduled', 'capacity_hold_token' => $hold['hold_token'] ), true );
		assert_same( 'doughboss_hold_stale', $stale->get_error_code(), 'a changed cart is stale' );
		assert_same( 409, $stale->get_error_data()['status'], 'stale is a 409' );
		$voucher = new DB_PK_Cart();
		$voucher->voucher = 'SAVE5';
		assert_same( 'doughboss_hold_stale', DoughBoss_Pickup::prepare_checkout_selection( $voucher, 7, 'pickup', array( 'pickup_mode' => 'scheduled', 'capacity_hold_token' => $hold['hold_token'] ), true )->get_error_code(), 'a voucher change is stale too' );

		foreach ( $db->holds as &$row ) {
			$row['expires_at'] = '2000-01-01 00:00:00';
		}
		unset( $row );
		assert_same( 'doughboss_hold_expired', $code( array( 'pickup_mode' => 'scheduled', 'capacity_hold_token' => $hold['hold_token'] ) ), 'an expired hold' );
		foreach ( $db->holds as &$row ) {
			$row['expires_at'] = '2099-01-01 00:00:00';
			$row['status']     = 'converted';
		}
		unset( $row );
		assert_same( 'doughboss_hold_used', $code( array( 'pickup_mode' => 'scheduled', 'capacity_hold_token' => $hold['hold_token'] ) ), 'a converted hold' );
		foreach ( $db->holds as &$row ) {
			$row['status'] = 'released';
		}
		unset( $row );
		assert_same( 'doughboss_hold_expired', $code( array( 'pickup_mode' => 'scheduled', 'capacity_hold_token' => $hold['hold_token'] ) ), 'a released hold needs a new choice' );

		db_pk_seed( array( 'ordering_paused' => 1 ) );
		$paused = DoughBoss_Pickup::prepare_checkout_selection( $cart, 7, 'pickup', array(), true );
		assert_same( 'doughboss_location_paused', $paused->get_error_code(), 'paused shops refuse checkout' );
		assert_same( 503, $paused->get_error_data()['status'], 'paused is a 503' );
	}
);

db_test(
	'GET /pickup/options in off mode answers from the location row alone',
	function () {
		$db = db_pk_seed( array( 'capacity_mode' => 'off' ) );
		$db->log = array();
		$response = DoughBoss_Pickup::get_options( new DB_PK_Request( array( 'location_id' => 7 ) ) );
		$data     = $response->get_data();
		assert_same( 'off', $data['mode'], 'mode off' );
		assert_true( false === $data['enforce'] && false === $data['paused'], 'not enforced, not paused' );
		assert_same( array(), $data['days'], 'no days' );
		assert_same( null, $data['asap'], 'no asap' );
		assert_same( '', $data['closed_reason'], 'no closed reason' );
		assert_same( 1, count( $db->log ), 'exactly one database read (the location row)' );
		assert_same( 0, $db->count_kind( 'write' ), 'no writes' );
		assert_same( 'no-store, max-age=0', $response->headers['Cache-Control'], 'responses are never cached' );

		$db->log = array();
		$unknown = DoughBoss_Pickup::get_options( new DB_PK_Request( array( 'location_id' => 404 ) ) )->get_data();
		assert_same( 'off', $unknown['mode'], 'unknown shop is off' );

		$shadow = db_pk_seed( array( 'capacity_mode' => 'shadow' ) );
		$shadow->log = array();
		$data = DoughBoss_Pickup::get_options( new DB_PK_Request( array( 'location_id' => 7 ) ) )->get_data();
		assert_same( 'shadow', $data['mode'], 'shadow is reported' );
		assert_false( $data['enforce'], 'shadow never enforces' );
		assert_same( 1, count( $shadow->log ), 'shadow also needs only the location row' );
	}
);

db_test(
	'GET /pickup/options reports paused, pre-migration and live shops',
	function () {
		db_pk_seed( array( 'ordering_paused' => 1 ) );
		$paused = DoughBoss_Pickup::get_options( new DB_PK_Request( array( 'location_id' => 7 ) ) )->get_data();
		assert_true( true === $paused['paused'] && 'paused' === $paused['closed_reason'] && false === $paused['enforce'], 'paused live shop' );
		assert_same( array(), $paused['days'], 'paused shops list nothing' );
		db_pk_seed();
		update_option( 'doughboss_db_version', '1.29.0' );
		assert_same( 'off', DoughBoss_Pickup::get_options( new DB_PK_Request( array( 'location_id' => 7 ) ) )->get_data()['mode'], 'pre-1.30.0 schema is off' );

		$db = db_pk_seed();
		$live = DoughBoss_Pickup::get_options( new DB_PK_Request( array( 'location_id' => 7 ) ) )->get_data();
		assert_same( 'live', $live['mode'], 'live' );
		assert_true( true === $live['enforce'], 'enforced' );
		assert_true( count( $live['days'] ) >= 1, 'offers days' );
		assert_true( count( $live['days'] ) <= 8, 'never more than eight days' );
		$usage_reads = 0;
		foreach ( $db->log as $entry ) {
			if ( false !== strpos( $entry[1], 'GROUP BY s.starts_at_utc' ) ) {
				++$usage_reads;
			}
		}
		assert_same( 1, $usage_reads, 'usage for the whole horizon is one query' );
	}
);

db_test(
	'POST /pickup/hold validates input and returns the hold contract',
	function () {
		$cart = new DB_PK_Cart();
		$db   = db_pk_seed();
		db_pk_use_cart( $cart );
		$slot = db_pk_first_slot();
		$req  = function ( array $over = array() ) use ( $slot ) {
			return new DB_PK_Request( array_merge( array( 'location_id' => 7, 'pickup_mode' => 'scheduled', 'ready_from_utc' => $slot, 'idempotency_key' => 'idem-key-00000000b1' ), $over ) );
		};
		$code = function ( $result ) {
			return is_wp_error( $result ) ? $result->get_error_code() : 'ok';
		};

		assert_same( 'doughboss_pickup_invalid', $code( DoughBoss_Pickup::create_hold( $req( array( 'pickup_mode' => 'whenever' ) ) ) ), 'unknown mode' );
		assert_same( 'doughboss_pickup_invalid', $code( DoughBoss_Pickup::create_hold( $req( array( 'ready_from_utc' => 'soon' ) ) ) ), 'unparseable slot' );
		assert_same( 'doughboss_slot_unavailable', $code( DoughBoss_Pickup::create_hold( $req( array( 'ready_from_utc' => '2001-01-01T00:00:00Z' ) ) ) ), 'a slot that is not offered' );
		assert_same( 'doughboss_hold_invalid', $code( DoughBoss_Pickup::create_hold( $req( array( 'idempotency_key' => 'short' ) ) ) ), 'bad idempotency key passes through from create_hold' );
		$empty = new DB_PK_Cart( array() );
		db_pk_use_cart( $empty );
		assert_same( 'doughboss_empty', $code( DoughBoss_Pickup::create_hold( $req() ) ), 'an empty cart' );
		db_pk_use_cart( $cart );

		$response = DoughBoss_Pickup::create_hold( $req() );
		assert_false( is_wp_error( $response ), 'a valid request succeeds' );
		$data = $response->get_data();
		assert_same( array( 'hold_token', 'slot_id', 'expires_at_utc', 'ready_from_utc', 'ready_by_utc', 'label', 'eta_minutes', 'pickup_mode' ), array_keys( $data ), 'contract keys' );
		assert_true( (bool) preg_match( '/^[0-9a-f]{64}$/', $data['hold_token'] ), 'token is 64 hex' );
		assert_true( is_int( $data['slot_id'] ) && $data['slot_id'] > 0, 'integer slot id' );
		assert_same( $slot, $data['ready_from_utc'], 'slot identity echoed' );
		assert_same( 'scheduled', $data['pickup_mode'], 'mode echoed' );
		assert_true( is_int( $data['eta_minutes'] ) && $data['eta_minutes'] >= 0, 'eta' );
		assert_true( '' !== $data['label'], 'label' );
		assert_same( 'no-store, max-age=0', $response->headers['Cache-Control'], 'not cached' );
		$replay = DoughBoss_Pickup::create_hold( $req() )->get_data();
		assert_same( $data['hold_token'], $replay['hold_token'], 'the same key replays the same hold' );
		assert_same( 1, count( $db->holds ), 'and does not allocate twice' );
		assert_same( 'doughboss_hold_conflict', $code( DoughBoss_Pickup::create_hold( $req( array( 'ready_from_utc' => db_pk_second_slot( $slot ) ) ) ) ), 'a reused key for another slot is a conflict' );

		// Release path.
		$released = DoughBoss_Pickup::release( new DB_PK_Request( array( 'hold_token' => $data['hold_token'] ) ) )->get_data();
		assert_true( true === $released['released'], 'release frees the hold' );
		assert_true( false === DoughBoss_Pickup::release( new DB_PK_Request( array( 'hold_token' => $data['hold_token'] ) ) )->get_data()['released'], 'releasing again reports false' );
		assert_true( false === DoughBoss_Pickup::release( new DB_PK_Request( array( 'hold_token' => '' ) ) )->get_data()['released'], 'empty token' );
	}
);

/** @return string A different offered slot start than $not. */
function db_pk_second_slot( $not ) {
	$options = DoughBoss_Pickup::options_for_location( DoughBoss_Locations::get( 7 ), new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
	foreach ( $options['days'] as $day ) {
		foreach ( $day['slots'] as $slot ) {
			if ( $slot['available'] && $slot['ready_from_utc'] !== $not ) {
				return $slot['ready_from_utc'];
			}
		}
	}
	return '';
}

db_test(
	'POST /pickup/hold refuses full slots, paused shops and off mode; release_token frees the old hold',
	function () {
		$cart = new DB_PK_Cart();
		$db   = db_pk_seed( array( 'slot_order_capacity' => 1 ) );
		db_pk_use_cart( $cart );
		$slot = db_pk_first_slot();
		$req  = function ( $key, array $over = array() ) use ( $slot ) {
			return new DB_PK_Request( array_merge( array( 'location_id' => 7, 'pickup_mode' => 'scheduled', 'ready_from_utc' => $slot, 'idempotency_key' => $key ), $over ) );
		};
		$first = DoughBoss_Pickup::create_hold( $req( 'idem-key-00000000c1' ) );
		assert_false( is_wp_error( $first ), 'the only place in the slot is held' );
		$full = DoughBoss_Pickup::create_hold( $req( 'idem-key-00000000c2' ) );
		assert_true( is_wp_error( $full ), 'a second customer is refused' );
		assert_same( 'doughboss_slot_full', $full->get_error_code(), 'slot_full' );
		assert_same( 409, $full->get_error_data()['status'], 'slot_full is a 409' );
		$again = DoughBoss_Pickup::create_hold( $req( 'idem-key-00000000c3', array( 'release_token' => $first->get_data()['hold_token'] ) ) );
		assert_false( is_wp_error( $again ), 're-holding the same slot works after releasing the old hold' );
		assert_true( $again->get_data()['hold_token'] !== $first->get_data()['hold_token'], 'with a fresh token' );

		db_pk_seed( array( 'ordering_paused' => 1 ) );
		assert_same( 'doughboss_pickup_off', DoughBoss_Pickup::create_hold( $req( 'idem-key-00000000c4' ) )->get_error_code(), 'paused shops take no holds' );
		db_pk_seed( array( 'capacity_mode' => 'off' ) );
		$off = DoughBoss_Pickup::create_hold( $req( 'idem-key-00000000c5' ) );
		assert_same( 'doughboss_pickup_off', $off->get_error_code(), 'off mode takes no holds' );
		assert_same( 400, $off->get_error_data()['status'], 'pickup_off is a 400' );
		assert_same( 0, $GLOBALS['wpdb']->count_kind( 'write' ), 'off mode writes nothing' );
	}
);

db_test(
	'ASAP holds require the shop to be open now',
	function () {
		$cart = new DB_PK_Cart();
		db_pk_seed();
		db_pk_use_cart( $cart );
		$now    = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$config = DoughBoss_Locations::capacity_live_config( 7 );
		$open   = DoughBoss_Pickup::shop_open_now( $config, $now );
		$slot   = db_pk_first_slot();
		$result = DoughBoss_Pickup::create_hold( new DB_PK_Request( array( 'location_id' => 7, 'pickup_mode' => 'asap', 'ready_from_utc' => $slot, 'idempotency_key' => 'idem-key-00000000d1' ) ) );
		if ( $open ) {
			assert_false( is_wp_error( $result ), 'ASAP is accepted while open' );
		} else {
			assert_same( 'doughboss_slot_unavailable', $result->get_error_code(), 'ASAP is refused while closed' );
		}
	}
);

db_test(
	'order cancellation releases the slot through the status hook',
	function () {
		$db   = db_pk_seed();
		$now  = db_pk_utc( '2026-10-15T23:30:00Z' );
		$made = db_pk_make_hold( $now );
		$db->holds[ $made['hold']['hold_id'] ]['status']   = 'converted';
		$db->holds[ $made['hold']['hold_id'] ]['order_id'] = 61;
		DoughBoss_Pickup::on_order_status_changed( 61, 'preparing' );
		assert_same( 'converted', $db->holds[ $made['hold']['hold_id'] ]['status'], 'other statuses leave the hold alone' );
		DoughBoss_Pickup::on_order_status_changed( 61, 'cancelled' );
		assert_same( 'released', $db->holds[ $made['hold']['hold_id'] ]['status'], 'cancelled releases the hold' );
	}
);

db_test(
	'checkout: a verified paid order without a snapshot (Tyro/MPGS) still carries its held pickup time',
	function () {
		if ( ! class_exists( 'DoughBoss_REST_Controller' ) || ! class_exists( 'WP_REST_Request' ) ) {
			assert_true( true, 'controller suite not loaded in this run' );
			return;
		}
		$cart = new DB_PK_Cart();
		$db   = db_pk_seed();
		db_pk_use_cart( $cart );
		$slot = db_pk_first_slot();
		$held = DoughBoss_Pickup::create_hold( new DB_PK_Request( array( 'location_id' => 7, 'pickup_mode' => 'scheduled', 'ready_from_utc' => $slot, 'idempotency_key' => 'idem-key-00000000e1' ) ) );
		assert_false( is_wp_error( $held ), 'a hold is created' );
		$token = $held->get_data()['hold_token'];

		$ref        = new ReflectionClass( 'DoughBoss_REST_Controller' );
		$controller = $ref->newInstanceWithoutConstructor();
		$cart_prop  = $ref->getProperty( 'cart' );
		$cart_prop->setAccessible( true );
		$cart_prop->setValue( $controller, $cart );
		$method = $ref->getMethod( 'pickup_fields_from_paid_request' );
		$method->setAccessible( true );
		$call = function ( array $params, $type = 'pickup' ) use ( $method, $controller ) {
			return $method->invokeArgs( $controller, array( new WP_REST_Request( $params ), 7, $type ) );
		};

		$fields = $call( array( 'pickup_mode' => 'scheduled', 'pickup_slot_id' => (string) $held->get_data()['slot_id'], 'capacity_hold_token' => $token ) );
		assert_same( $token, isset( $fields['capacity_hold_token'] ) ? $fields['capacity_hold_token'] : '', 'the hold token reaches the order writer so the slot converts' );
		assert_same( 'scheduled', $fields['pickup_mode'], 'mode carried' );
		assert_same( (int) $held->get_data()['slot_id'], $fields['pickup_slot_id'], 'slot carried' );
		assert_true( '' !== $fields['pickup_label'], 'a live hold yields its label' );
		assert_same( 'Australia/Sydney', $fields['pickup_timezone'], 'timezone recorded' );
		assert_false( array_key_exists( 'capacity_unpaid_allowed', $fields ), 'a paid order never gets the unpaid allowance' );

		// A lapsed hold is not re-validated here: money has moved, the order writer decides.
		foreach ( $db->holds as &$row ) {
			$row['expires_at'] = '2000-01-01 00:00:00';
		}
		unset( $row );
		$lapsed = $call( array( 'pickup_mode' => 'scheduled', 'pickup_slot_id' => '5', 'capacity_hold_token' => $token ) );
		assert_same( $token, $lapsed['capacity_hold_token'], 'a lapsed hold still travels with the paid order' );
		assert_same( 5, $lapsed['pickup_slot_id'], 'raw slot id from the request' );
		assert_same( '', $lapsed['pickup_label'], 'no label can be promised for a lapsed hold' );

		assert_same( array(), $call( array() ), 'no selection in the request adds nothing' );
		assert_same( array(), $call( array( 'pickup_mode' => 'scheduled', 'capacity_hold_token' => 'nope' ) ), 'a malformed token adds nothing' );
		assert_same( array(), $call( array( 'pickup_mode' => 'scheduled', 'capacity_hold_token' => $token ), 'delivery' ), 'delivery never schedules' );
		db_pk_seed( array( 'capacity_mode' => 'off' ) );
		assert_same( array(), $call( array( 'pickup_mode' => 'scheduled', 'capacity_hold_token' => $token ) ), 'an off shop adds nothing (legacy checkout untouched)' );
	}
);

db_test(
	'hold limiter identifies the client like the REST controller: REMOTE_ADDR unless a trusted proxy header is opted into',
	function () {
		$saved_server   = $_SERVER;
		$saved_settings = get_option( 'doughboss_settings', array() );
		$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 203.0.113.9';
		doughboss_test_set_settings( array( 'behind_reverse_proxy' => 0 ) );
		assert_same( '203.0.113.9', DoughBoss_Pickup::client_ip(), 'without the opt-in the forwarded header is ignored' );
		doughboss_test_set_settings( array( 'behind_reverse_proxy' => 1, 'trusted_proxy_header' => 'X-Forwarded-For' ) );
		assert_same( '198.51.100.7', DoughBoss_Pickup::client_ip(), 'behind a trusted proxy each customer gets their own bucket' );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
		assert_same( '203.0.113.9', DoughBoss_Pickup::client_ip(), 'a malformed header falls back to REMOTE_ADDR' );
		$_SERVER = $saved_server;
		doughboss_test_set_settings( is_array( $saved_settings ) ? $saved_settings : array() );
	}
);

// Restore whatever $wpdb earlier suites installed so later suites are unaffected.
$GLOBALS['wpdb'] = $GLOBALS['db_pk_previous_wpdb'];

db_test( 'create_hold never takes a gap lock that holds on other slots can collide with (InnoDB deadlock found on real MariaDB)', function () {
	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-capacity.php' );
	$start  = strpos( $source, 'public static function create_hold(' );
	$end    = strpos( $source, 'public static function lock_hold_for_order(', $start );
	$body   = substr( $source, $start, $end - $start );
	assert_true( false === strpos( $body, 'idempotency_key = %s FOR UPDATE' ), 'the idempotency lookup is not a locking read (FOR UPDATE on an absent key locks the index gap)' );
	assert_true( false !== strpos( $body, 'WHERE id = %d FOR UPDATE' ), 'an existing idempotency row is locked by primary key' );
	assert_true( 0 === preg_match( '/UPDATE \{\$holds_table\}[^;]*WHERE slot_id = %d/', $body ), 'lapsed holds are flipped by primary key, not by a slot_id range UPDATE' );
	assert_true( false !== strpos( $body, "WHERE slot_id = %d AND status = 'held' AND expires_at <= %s\", \$slot_id, \$now_mysql" ), 'lapsed holds are found with a plain read' );
} );
