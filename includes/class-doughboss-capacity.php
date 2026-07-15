<?php
/**
 * Deterministic pickup-capacity slot calculator.
 *
 * This class deliberately contains no database writes. It is the shared,
 * testable planning core used by the preview API and, once transactional holds
 * are enabled, checkout. All stored instants remain UTC; wall-clock labels are
 * presentation only.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds location-scoped pickup windows from explicit operating rules.
 */
class DoughBoss_Capacity {

	/**
	 * Supported weekly schedule keys.
	 *
	 * @var string[]
	 */
	private static $weekdays = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );

	/**
	 * Calculate capacity windows.
	 *
	 * The method fails closed: malformed time zones, hours, demand or capacity
	 * return no windows. Usage is keyed by the UTC window start in MySQL format.
	 *
	 * @param array                  $config  Normalised location configuration.
	 * @param int                    $demand  Server-computed cart capacity units.
	 * @param array                  $usage   Used units keyed by UTC start.
	 * @param DateTimeImmutable|null $now_utc Injected UTC clock for deterministic tests.
	 * @return array<int,array<string,mixed>>
	 */
	public static function windows( array $config, $demand, array $usage = array(), $now_utc = null ) {
		$demand = (int) $demand;
		if ( $demand < 1 || $demand > 10000 || empty( $config['enabled'] ) || empty( $config['active'] ) || empty( $config['pickup_enabled'] ) ) {
			return array();
		}

		try {
			$timezone = new DateTimeZone( isset( $config['timezone'] ) ? (string) $config['timezone'] : '' );
		} catch ( Exception $e ) {
			return array();
		}

		$slot_minutes   = isset( $config['slot_minutes'] ) ? (int) $config['slot_minutes'] : 0;
		$notice_minutes = isset( $config['notice_minutes'] ) ? (int) $config['notice_minutes'] : 0;
		$horizon_days   = isset( $config['horizon_days'] ) ? (int) $config['horizon_days'] : 0;
		$capacity       = isset( $config['capacity_units'] ) ? (int) $config['capacity_units'] : 0;
		if ( $slot_minutes < 5 || $slot_minutes > 120 || $notice_minutes < 0 || $notice_minutes > 1440 || $horizon_days < 1 || $horizon_days > 31 || $capacity < 1 || $capacity > 10000 ) {
			return array();
		}

		$hours = self::validate_hours( isset( $config['hours'] ) ? $config['hours'] : array() );
		if ( false === $hours ) {
			return array();
		}

		$blackouts = array();
		foreach ( isset( $config['blackout_dates'] ) ? (array) $config['blackout_dates'] : array() as $date ) {
			if ( is_string( $date ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$blackouts[ $date ] = true;
			}
		}

		if ( ! $now_utc instanceof DateTimeImmutable ) {
			try {
				$now_utc = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			} catch ( Exception $e ) {
				return array();
			}
		}
		$now_utc       = $now_utc->setTimezone( new DateTimeZone( 'UTC' ) );
		$earliest      = $now_utc->modify( '+' . $notice_minutes . ' minutes' );
		$local_today   = $now_utc->setTimezone( $timezone )->setTime( 0, 0, 0 );
		$slot_seconds  = $slot_minutes * MINUTE_IN_SECONDS;
		$windows       = array();
		$seen_slot_ids = array();

		for ( $day = 0; $day < $horizon_days; ++$day ) {
			$local_date = $local_today->modify( '+' . $day . ' days' );
			$date_key   = $local_date->format( 'Y-m-d' );
			if ( isset( $blackouts[ $date_key ] ) ) {
				continue;
			}
			$weekday = self::$weekdays[ (int) $local_date->format( 'w' ) ];
			foreach ( $hours[ $weekday ] as $range ) {
				$open = self::local_instant( $date_key, $range[0], $timezone );
				$close_date = self::minutes( $range[1] ) <= self::minutes( $range[0] ) ? $local_date->modify( '+1 day' )->format( 'Y-m-d' ) : $date_key;
				$close = self::local_instant( $close_date, $range[1], $timezone );
				if ( ! $open || ! $close || $close <= $open ) {
					return array();
				}

				$cursor = $open->setTimezone( new DateTimeZone( 'UTC' ) );
				$end    = $close->setTimezone( new DateTimeZone( 'UTC' ) );
				while ( $cursor->getTimestamp() + $slot_seconds <= $end->getTimestamp() ) {
					$by = $cursor->modify( '+' . $slot_minutes . ' minutes' );
					if ( $cursor >= $earliest ) {
						$usage_key = $cursor->format( 'Y-m-d H:i:s' );
						$used      = isset( $usage[ $usage_key ] ) ? max( 0, (int) $usage[ $usage_key ] ) : 0;
						$remaining = max( 0, $capacity - $used );
						$slot_id   = self::slot_id( $config, $cursor, $by );
						if ( isset( $seen_slot_ids[ $slot_id ] ) ) {
							return array();
						}
						$seen_slot_ids[ $slot_id ] = true;
						$windows[] = array(
							'slot_id'        => $slot_id,
							'ready_from_utc' => $cursor->format( 'Y-m-d\TH:i:s\Z' ),
							'ready_by_utc'   => $by->format( 'Y-m-d\TH:i:s\Z' ),
							'local_date'     => $cursor->setTimezone( $timezone )->format( 'Y-m-d' ),
							'local_from'     => $cursor->setTimezone( $timezone )->format( 'H:i' ),
							'local_by'       => $by->setTimezone( $timezone )->format( 'H:i' ),
							'utc_offset'     => $cursor->setTimezone( $timezone )->format( 'P' ),
							'capacity_units' => $capacity,
							'remaining_units'=> $remaining,
							'availability'   => $demand <= $remaining ? 'available' : 'full',
						);
					}
					$cursor = $by;
				}
			}
		}

		return $windows;
	}

	/**
	 * Compute the conservative Phase 3A workload: one unit per item quantity.
	 *
	 * @param array $lines Server-side cart lines.
	 * @return int
	 */
	public static function demand_units( array $lines ) {
		$units = 0;
		foreach ( $lines as $line ) {
			$quantity = isset( $line['quantity'] ) ? (int) $line['quantity'] : 0;
			if ( $quantity < 1 || $quantity > 50 || $units > 10000 - $quantity ) {
				return 0;
			}
			$units += $quantity;
		}
		return $units;
	}

	/**
	 * Hash the complete server-owned cart/configuration relevant to a hold.
	 *
	 * @param array $lines   Server-side cart lines.
	 * @param array $context Location, type, voucher and pricing context.
	 * @return string Empty when the cart is invalid.
	 */
	public static function cart_hash( array $lines, array $context ) {
		if ( self::demand_units( $lines ) < 1 ) {
			return '';
		}
		$canonical = array();
		foreach ( $lines as $line ) {
			$toppings = array();
			foreach ( isset( $line['toppings'] ) ? (array) $line['toppings'] : array() as $topping ) {
				$toppings[] = is_array( $topping ) && isset( $topping['slug'] ) ? (string) $topping['slug'] : (string) $topping;
			}
			sort( $toppings, SORT_STRING );
			$canonical[] = array(
				'type'       => isset( $line['type'] ) ? (string) $line['type'] : '',
				'item_id'    => isset( $line['item_id'] ) ? (int) $line['item_id'] : 0,
				'size'       => isset( $line['size'] ) ? (string) $line['size'] : '',
				'toppings'   => $toppings,
				'quantity'   => (int) $line['quantity'],
				'unit_price' => number_format( isset( $line['unit_price'] ) ? (float) $line['unit_price'] : 0, 2, '.', '' ),
			);
		}
		usort(
			$canonical,
			static function ( $a, $b ) {
				return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
			}
		);
		$owned_context = array(
			'location_id' => isset( $context['location_id'] ) ? (int) $context['location_id'] : 0,
			'order_type'  => isset( $context['order_type'] ) ? (string) $context['order_type'] : 'pickup',
			'voucher'     => isset( $context['voucher'] ) ? strtoupper( trim( (string) $context['voucher'] ) ) : '',
			'total'       => number_format( isset( $context['total'] ) ? (float) $context['total'] : 0, 2, '.', '' ),
		);
		return hash( 'sha256', wp_json_encode( array( 'lines' => $canonical, 'context' => $owned_context ) ) );
	}

	/**
	 * Atomically reserve a materialised slot by locking the durable slot row.
	 *
	 * @param int                    $slot_id         Materialised slot id.
	 * @param array                  $lines           Server-side cart lines.
	 * @param array                  $context         Server-owned cart context.
	 * @param string                 $idempotency_key Client-generated retry key.
	 * @param DateTimeImmutable|null $now_utc         Injected test clock.
	 * @return array|WP_Error
	 */
	public static function create_hold( $slot_id, array $lines, array $context, $idempotency_key, $now_utc = null ) {
		global $wpdb;
		$slot_id         = absint( $slot_id );
		$idempotency_key = trim( (string) $idempotency_key );
		$cart_hash       = self::cart_hash( $lines, $context );
		$units           = self::demand_units( $lines );
		if ( ! $slot_id || strlen( $idempotency_key ) < 16 || strlen( $idempotency_key ) > 191 || preg_match( '/[\x00-\x1F\x7F]/', $idempotency_key ) || ! $cart_hash || ! $units ) {
			return new WP_Error( 'doughboss_hold_invalid', __( 'The capacity hold request is invalid.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$now = self::utc_clock( $now_utc );
		if ( ! $now ) {
			return new WP_Error( 'doughboss_hold_clock', __( 'The server clock is unavailable.', 'doughboss' ), array( 'status' => 503 ) );
		}

		$slots_table = $wpdb->prefix . 'doughboss_capacity_slots';
		$holds_table = $wpdb->prefix . 'doughboss_capacity_holds';
		$now_mysql   = $now->format( 'Y-m-d H:i:s' );
		$raw_token   = self::hold_token( $slot_id, $cart_hash, $idempotency_key );
		$token_hash  = hash( 'sha256', $raw_token );
		$started     = false !== $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $started ) {
			return new WP_Error( 'doughboss_hold_storage', __( 'Capacity storage is unavailable.', 'doughboss' ), array( 'status' => 503 ) );
		}

		try {
			// The durable slot row exists before holds and is the mutex even when the
			// slot has zero existing holds. This avoids the empty-range phantom race.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$slot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$slots_table} WHERE id = %d FOR UPDATE", $slot_id ) );
			if ( ! $slot || empty( $slot->accepting_holds ) || 'pickup' !== $slot->order_type ) {
				throw new DoughBoss_Capacity_Exception( 'doughboss_slot_unavailable', __( 'That pickup time is no longer available.', 'doughboss' ), 409 );
			}
			if ( isset( $context['location_id'] ) && (int) $context['location_id'] !== (int) $slot->location_id ) {
				throw new DoughBoss_Capacity_Exception( 'doughboss_hold_mismatch', __( 'That pickup time belongs to another shop.', 'doughboss' ), 409 );
			}

			$slot_start = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $slot->starts_at_utc, new DateTimeZone( 'UTC' ) );
			if ( ! $slot_start || $slot_start <= $now ) {
				throw new DoughBoss_Capacity_Exception( 'doughboss_slot_unavailable', __( 'That pickup time has passed.', 'doughboss' ), 409 );
			}

			// Lock order is always slot then idempotency row, preventing two retries
			// for the same slot from deadlocking in opposite order.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$holds_table} WHERE idempotency_key = %s FOR UPDATE", $idempotency_key ) );
			if ( $existing ) {
				if ( (int) $existing->slot_id !== $slot_id || ! hash_equals( (string) $existing->cart_hash, $cart_hash ) ) {
					throw new DoughBoss_Capacity_Exception( 'doughboss_hold_conflict', __( 'That retry key was already used for another cart or pickup time.', 'doughboss' ), 409 );
				}
				if ( 'held' !== $existing->status && 'converted' !== $existing->status ) {
					throw new DoughBoss_Capacity_Exception( 'doughboss_hold_expired', __( 'That capacity hold is no longer active.', 'doughboss' ), 409 );
				}
				if ( 'held' === $existing->status && (string) $existing->expires_at <= $now_mysql ) {
					throw new DoughBoss_Capacity_Exception( 'doughboss_hold_expired', __( 'That capacity hold has expired.', 'doughboss' ), 409 );
				}
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return self::shape_hold( $existing, $raw_token, true );
			}

			// Expiry is enforced by every writer, not left to a cron cleanup race.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$holds_table} SET status = 'expired', updated_at = %s WHERE slot_id = %d AND status = 'held' AND expires_at <= %s", $now_mysql, $slot_id, $now_mysql ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$used = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS orders_used, COALESCE(SUM(capacity_units),0) AS units_used FROM {$holds_table} WHERE slot_id = %d AND (status = 'converted' OR (status = 'held' AND expires_at > %s))", $slot_id, $now_mysql ) );
			$orders_used = $used ? (int) $used->orders_used : 0;
			$units_used  = $used ? (int) $used->units_used : 0;
			if ( $orders_used + 1 > (int) $slot->order_capacity || $units_used + $units > (int) $slot->unit_capacity ) {
				throw new DoughBoss_Capacity_Exception( 'doughboss_slot_full', __( 'That pickup time has just filled up. Please choose another time.', 'doughboss' ), 409 );
			}

			$hold_minutes = isset( $context['hold_minutes'] ) ? max( 1, min( 30, (int) $context['hold_minutes'] ) ) : 10;
			$expires      = $now->modify( '+' . $hold_minutes . ' minutes' );
			if ( $expires > $slot_start ) {
				$expires = $slot_start;
			}
			$row = array(
				'slot_id'         => $slot_id,
				'token_hash'      => $token_hash,
				'idempotency_key' => $idempotency_key,
				'cart_hash'       => $cart_hash,
				'status'          => 'held',
				'capacity_units'  => $units,
				'expires_at'      => $expires->format( 'Y-m-d H:i:s' ),
				'created_at'      => $now_mysql,
				'updated_at'      => $now_mysql,
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $wpdb->insert( $holds_table, $row, array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ) ) ) {
				throw new RuntimeException( 'Capacity hold insert failed.' );
			}
			$row['id'] = (int) $wpdb->insert_id;
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return self::shape_hold( (object) $row, $raw_token, false );
		} catch ( DoughBoss_Capacity_Exception $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( $e->get_error_code(), $e->getMessage(), array( 'status' => $e->get_status() ) );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_hold_storage', __( 'The pickup time could not be held. Please try again.', 'doughboss' ), array( 'status' => 503 ) );
		}
	}

	/** @return DateTimeImmutable|false */
	private static function utc_clock( $now_utc ) {
		try {
			$clock = $now_utc instanceof DateTimeImmutable ? $now_utc : new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			return $clock->setTimezone( new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/** @return string */
	private static function hold_token( $slot_id, $cart_hash, $idempotency_key ) {
		return hash_hmac( 'sha256', $slot_id . '|' . $cart_hash . '|' . $idempotency_key, wp_salt( 'auth' ) );
	}

	/** @return array */
	private static function shape_hold( $hold, $raw_token, $replayed ) {
		return array(
			'hold_id'        => (int) $hold->id,
			'hold_token'     => $raw_token,
			'slot_id'        => (int) $hold->slot_id,
			'capacity_units' => (int) $hold->capacity_units,
			'expires_at_utc' => str_replace( ' ', 'T', (string) $hold->expires_at ) . 'Z',
			'replayed'       => (bool) $replayed,
		);
	}

	/**
	 * Validate weekly ranges and reject malformed or overlapping local hours.
	 *
	 * @param mixed $raw Raw hours.
	 * @return array|false
	 */
	private static function validate_hours( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$out = array();
		foreach ( self::$weekdays as $weekday ) {
			$out[ $weekday ] = array();
			$ranges = isset( $raw[ $weekday ] ) ? $raw[ $weekday ] : array();
			if ( ! is_array( $ranges ) ) {
				return false;
			}
			$previous_end = -1;
			foreach ( $ranges as $range ) {
				if ( ! is_array( $range ) || 2 !== count( $range ) || ! self::valid_time( $range[0] ) || ! self::valid_time( $range[1] ) || $range[0] === $range[1] ) {
					return false;
				}
				$start = self::minutes( $range[0] );
				$end   = self::minutes( $range[1] );
				$effective_end = $end <= $start ? $end + 1440 : $end;
				if ( $start < $previous_end ) {
					return false;
				}
				$previous_end = $effective_end;
				$out[ $weekday ][] = array( (string) $range[0], (string) $range[1] );
			}
		}
		return $out;
	}

	/** @return bool */
	private static function valid_time( $value ) {
		return is_string( $value ) && (bool) preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value );
	}

	/** @return int */
	private static function minutes( $value ) {
		$parts = explode( ':', (string) $value );
		return ( (int) $parts[0] * 60 ) + (int) $parts[1];
	}

	/**
	 * Parse a wall time and prove PHP did not silently normalise it across a DST gap.
	 *
	 * @return DateTimeImmutable|false
	 */
	private static function local_instant( $date, $time, DateTimeZone $timezone ) {
		$instant = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, $timezone );
		if ( ! $instant || $instant->format( 'Y-m-d H:i' ) !== $date . ' ' . $time ) {
			return false;
		}
		return $instant;
	}

	/** @return string */
	private static function slot_id( array $config, DateTimeImmutable $from, DateTimeImmutable $by ) {
		$location_id = isset( $config['location_id'] ) ? (int) $config['location_id'] : 0;
		$version     = isset( $config['planning_version'] ) ? max( 1, (int) $config['planning_version'] ) : 1;
		return 'dbs_' . substr( hash( 'sha256', $location_id . '|' . $version . '|' . $from->format( 'U' ) . '|' . $by->format( 'U' ) ), 0, 32 );
	}
}

/**
 * Internal typed exception used to preserve public error codes through rollback.
 */
class DoughBoss_Capacity_Exception extends RuntimeException {
	private $error_code;
	private $status;
	public function __construct( $error_code, $message, $status ) {
		parent::__construct( $message );
		$this->error_code = (string) $error_code;
		$this->status     = (int) $status;
	}
	public function get_error_code() { return $this->error_code; }
	public function get_status() { return $this->status; }
}
