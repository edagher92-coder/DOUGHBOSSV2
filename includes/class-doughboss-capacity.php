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

		// Optional last-start cut-off (live pickup): a window may not start later
		// than close minus this many minutes. Zero keeps the shadow behaviour.
		$cutoff_minutes = isset( $config['cutoff_minutes'] ) ? max( 0, min( 180, (int) $config['cutoff_minutes'] ) ) : 0;
		$cutoff_seconds = $cutoff_minutes * MINUTE_IN_SECONDS;

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
					if ( $cursor >= $earliest && ( 0 === $cutoff_seconds || $cursor->getTimestamp() <= $end->getTimestamp() - $cutoff_seconds ) ) {
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
							'load_orders'    => $used,
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

	/**
	 * Lock and validate a hold from inside the order-creation transaction.
	 *
	 * The first lookup is advisory only; writers always lock the durable slot
	 * row before the hold row, matching create_hold() and preventing deadlocks.
	 *
	 * @param string                 $raw_token   Browser hold token.
	 * @param string                 $cart_hash   Recomputed server cart hash.
	 * @param int                    $location_id Resolved order location.
	 * @param DateTimeImmutable|null $now_utc     Injected test clock.
	 * @return array|WP_Error
	 */
	public static function lock_hold_for_order( $raw_token, $cart_hash, $location_id, $now_utc = null ) {
		global $wpdb;
		$raw_token  = trim( (string) $raw_token );
		$cart_hash  = (string) $cart_hash;
		$token_hash = hash( 'sha256', $raw_token );
		if ( 64 !== strlen( $raw_token ) || 64 !== strlen( $cart_hash ) || ! absint( $location_id ) ) {
			return new WP_Error( 'doughboss_hold_invalid', __( 'The pickup-time hold is invalid.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$now = self::utc_clock( $now_utc );
		if ( ! $now ) {
			return new WP_Error( 'doughboss_hold_clock', __( 'The server clock is unavailable.', 'doughboss' ), array( 'status' => 503 ) );
		}

		$holds = $wpdb->prefix . 'doughboss_capacity_holds';
		$slots = $wpdb->prefix . 'doughboss_capacity_slots';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$slot_id = $wpdb->get_var( $wpdb->prepare( "SELECT slot_id FROM {$holds} WHERE token_hash = %s", $token_hash ) );
		if ( ! $slot_id ) {
			return new WP_Error( 'doughboss_hold_missing', __( 'That pickup-time hold could not be found.', 'doughboss' ), array( 'status' => 409 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$slot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$slots} WHERE id = %d FOR UPDATE", $slot_id ) );
		if ( ! $slot || (int) $slot->location_id !== (int) $location_id || 'pickup' !== $slot->order_type ) {
			return new WP_Error( 'doughboss_hold_mismatch', __( 'That pickup-time hold belongs to another shop or order type.', 'doughboss' ), array( 'status' => 409 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hold = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$holds} WHERE token_hash = %s AND slot_id = %d FOR UPDATE", $token_hash, $slot_id ) );
		if ( ! $hold || ! hash_equals( (string) $hold->token_hash, $token_hash ) || ! hash_equals( (string) $hold->cart_hash, $cart_hash ) ) {
			return new WP_Error( 'doughboss_hold_mismatch', __( 'The cart changed after this pickup time was held.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( 'converted' === $hold->status && ! empty( $hold->order_id ) ) {
			$orders = $wpdb->prefix . 'doughboss_orders';
			// A replay is valid only when both sides of the allocation link agree.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$linked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$orders} WHERE id = %d AND capacity_hold_id = %d", $hold->order_id, $hold->id ) );
			if ( (int) $linked !== (int) $hold->order_id ) {
				return new WP_Error( 'doughboss_hold_corrupt', __( 'That pickup-time allocation is incomplete. Please contact the shop.', 'doughboss' ), array( 'status' => 409 ) );
			}
			return array( 'replayed_order_id' => (int) $hold->order_id );
		}
		if ( 'held' !== $hold->status || (string) $hold->expires_at <= $now->format( 'Y-m-d H:i:s' ) ) {
			return new WP_Error( 'doughboss_hold_expired', __( 'That pickup-time hold has expired. Please choose a time again.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$ready_from = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $slot->starts_at_utc, new DateTimeZone( 'UTC' ) );
		$ready_by   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $slot->ends_at_utc, new DateTimeZone( 'UTC' ) );
		if ( ! $ready_from || ! $ready_by || $ready_from <= $now || $ready_by <= $ready_from ) {
			return new WP_Error( 'doughboss_slot_unavailable', __( 'That pickup time is no longer available.', 'doughboss' ), array( 'status' => 409 ) );
		}
		return array(
			'hold_id'        => (int) $hold->id,
			'capacity_units' => (int) $hold->capacity_units,
			'promised_ready_from_utc' => $ready_from->format( 'Y-m-d H:i:s' ),
			'promised_ready_by_utc'   => $ready_by->format( 'Y-m-d H:i:s' ),
			'timezone_snapshot'       => (string) $slot->timezone_snapshot,
			'fire_at_utc'             => null,
			'planning_version'        => (int) $slot->planning_version,
		);
	}

	/**
	 * Convert a previously locked hold to an order in the caller's transaction.
	 *
	 * @param int    $hold_id Hold id.
	 * @param int    $order_id New order id.
	 * @param string $now_utc UTC MySQL timestamp.
	 * @return bool
	 */
	public static function convert_locked_hold( $hold_id, $order_id, $now_utc ) {
		global $wpdb;
		$table = $wpdb->prefix . 'doughboss_capacity_holds';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'converted', order_id = %d, converted_at = %s, updated_at = %s WHERE id = %d AND status = 'held' AND order_id IS NULL", absint( $order_id ), $now_utc, $now_utc, absint( $hold_id ) ) );
		return 1 === $updated;
	}

	/**
	 * Count held/converted orders per pickup slot start for one location.
	 *
	 * One query for the whole horizon. The result is keyed by the UTC slot start
	 * ('Y-m-d H:i:s'), the same key windows() expects in its $usage argument.
	 *
	 * @param int                           $location_id   Location id.
	 * @param string                        $from_utc      Range start (UTC MySQL).
	 * @param string                        $to_utc        Range end (UTC MySQL).
	 * @param string|DateTimeImmutable|null $now_utc       Clock used to ignore expired holds.
	 * @return array<string,int>
	 */
	public static function usage_for_location( $location_id, $from_utc, $to_utc, $now_utc = null ) {
		global $wpdb;
		if ( $now_utc instanceof DateTimeImmutable ) {
			$now_mysql = $now_utc->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} elseif ( is_string( $now_utc ) && '' !== $now_utc ) {
			$now_mysql = $now_utc;
		} else {
			$now_mysql = gmdate( 'Y-m-d H:i:s' );
		}
		$slots = $wpdb->prefix . 'doughboss_capacity_slots';
		$holds = $wpdb->prefix . 'doughboss_capacity_holds';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.starts_at_utc AS starts_at_utc, COUNT(h.id) AS orders_used FROM {$slots} s JOIN {$holds} h ON h.slot_id = s.id WHERE s.location_id = %d AND s.order_type = 'pickup' AND s.starts_at_utc BETWEEN %s AND %s AND (h.status = 'converted' OR (h.status = 'held' AND h.expires_at > %s)) GROUP BY s.starts_at_utc", absint( $location_id ), (string) $from_utc, (string) $to_utc, $now_mysql ) );
		$usage = array();
		foreach ( (array) $rows as $row ) {
			if ( is_object( $row ) && isset( $row->starts_at_utc ) ) {
				$usage[ (string) $row->starts_at_utc ] = max( 0, (int) $row->orders_used );
			}
		}
		return $usage;
	}

	/**
	 * Ensure a durable slot row exists for a window and return its integer id.
	 *
	 * windows() ids are hashes; holds need the integer primary key. The slot is
	 * keyed by (location, order type, UTC start), so repeated and concurrent
	 * calls converge on one row. Only the per-slot order limit and planning
	 * version are refreshed on conflict.
	 *
	 * @param array                  $config  Live capacity config (location_id, capacity_units, timezone, planning_version).
	 * @param array                  $window  One entry from windows().
	 * @param DateTimeImmutable|null $now_utc Injected clock.
	 * @return int|WP_Error
	 */
	public static function materialise_slot( array $config, array $window, $now_utc = null ) {
		global $wpdb;
		$location_id = isset( $config['location_id'] ) ? absint( $config['location_id'] ) : 0;
		$order_cap   = isset( $config['capacity_units'] ) ? (int) $config['capacity_units'] : 0;
		$from        = isset( $window['ready_from_utc'] ) ? self::iso_to_mysql( $window['ready_from_utc'] ) : '';
		$by          = isset( $window['ready_by_utc'] ) ? self::iso_to_mysql( $window['ready_by_utc'] ) : '';
		$timezone    = isset( $config['timezone'] ) ? substr( (string) $config['timezone'], 0, 64 ) : '';
		$now         = self::utc_clock( $now_utc );
		if ( ! $location_id || $order_cap < 1 || $order_cap > 10000 || '' === $from || '' === $by || $by <= $from || '' === $timezone || ! $now ) {
			return new WP_Error( 'doughboss_slot_unavailable', __( 'That pickup time is no longer available.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$now_mysql = $now->format( 'Y-m-d H:i:s' );
		$version   = isset( $config['planning_version'] ) ? max( 1, (int) $config['planning_version'] ) : 1;
		$table     = $wpdb->prefix . 'doughboss_capacity_slots';
		// Item-unit limits are disabled in live mode (order count is the only
		// limit), so the unit column is written at its maximum.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (location_id, order_type, starts_at_utc, ends_at_utc, timezone_snapshot, order_capacity, unit_capacity, planning_version, accepting_holds, created_at, updated_at) VALUES (%d, 'pickup', %s, %s, %s, %d, %d, %d, 1, %s, %s) ON DUPLICATE KEY UPDATE order_capacity = VALUES(order_capacity), planning_version = VALUES(planning_version), updated_at = VALUES(updated_at), id = LAST_INSERT_ID(id)", $location_id, $from, $by, $timezone, $order_cap, 10000, $version, $now_mysql, $now_mysql ) );
		$slot_id = false === $result ? 0 : (int) $wpdb->insert_id;
		if ( $slot_id < 1 ) {
			return new WP_Error( 'doughboss_hold_storage', __( 'The pickup time could not be held. Please try again.', 'doughboss' ), array( 'status' => 503 ) );
		}
		return $slot_id;
	}

	/**
	 * Read a hold without locking anything (advisory pre-payment validation).
	 *
	 * @param string                 $raw_token   Browser hold token.
	 * @param string                 $cart_hash   Recomputed server cart hash.
	 * @param int                    $location_id Resolved location.
	 * @param DateTimeImmutable|null $now_utc     Injected clock.
	 * @return array{status:string,slot_id:int,expires_at_utc:string,ready_from_utc:string,ready_by_utc:string,timezone:string,cart_matches:bool,location_matches:bool}
	 */
	public static function peek_hold( $raw_token, $cart_hash, $location_id, $now_utc = null ) {
		global $wpdb;
		$missing = array(
			'status'           => 'missing',
			'slot_id'          => 0,
			'expires_at_utc'   => '',
			'ready_from_utc'   => '',
			'ready_by_utc'     => '',
			'timezone'         => '',
			'cart_matches'     => false,
			'location_matches' => false,
		);
		$raw_token = trim( (string) $raw_token );
		$now       = self::utc_clock( $now_utc );
		if ( 64 !== strlen( $raw_token ) || ! preg_match( '/^[0-9a-f]{64}$/', $raw_token ) || ! $now ) {
			return $missing;
		}
		$holds = $wpdb->prefix . 'doughboss_capacity_holds';
		$slots = $wpdb->prefix . 'doughboss_capacity_slots';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT h.status AS status, h.slot_id AS slot_id, h.expires_at AS expires_at, h.cart_hash AS cart_hash, s.location_id AS location_id, s.starts_at_utc AS starts_at_utc, s.ends_at_utc AS ends_at_utc, s.timezone_snapshot AS timezone_snapshot FROM {$holds} h JOIN {$slots} s ON s.id = h.slot_id WHERE h.token_hash = %s", hash( 'sha256', $raw_token ) ) );
		if ( ! $row ) {
			return $missing;
		}
		$status = (string) $row->status;
		if ( 'held' === $status && (string) $row->expires_at <= $now->format( 'Y-m-d H:i:s' ) ) {
			$status = 'expired';
		}
		return array(
			'status'           => $status,
			'slot_id'          => (int) $row->slot_id,
			'expires_at_utc'   => str_replace( ' ', 'T', (string) $row->expires_at ) . 'Z',
			'ready_from_utc'   => str_replace( ' ', 'T', (string) $row->starts_at_utc ) . 'Z',
			'ready_by_utc'     => str_replace( ' ', 'T', (string) $row->ends_at_utc ) . 'Z',
			'timezone'         => (string) $row->timezone_snapshot,
			'cart_matches'     => hash_equals( (string) $row->cart_hash, (string) $cart_hash ),
			'location_matches' => (int) $row->location_id === (int) $location_id,
		);
	}

	/**
	 * Best-effort release of an unconverted hold (cart changed, tab closed).
	 *
	 * @param string $raw_token Browser hold token.
	 * @return bool True when a held hold was released.
	 */
	public static function release_hold( $raw_token ) {
		global $wpdb;
		$raw_token = trim( (string) $raw_token );
		if ( 64 !== strlen( $raw_token ) ) {
			return false;
		}
		$table = $wpdb->prefix . 'doughboss_capacity_holds';
		$now   = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'released', released_at = %s, updated_at = %s WHERE token_hash = %s AND status = 'held'", $now, $now, hash( 'sha256', $raw_token ) ) );
		return 1 === $updated;
	}

	/**
	 * Free the capacity of a cancelled order. The hold keeps its order_id so the
	 * allocation history stays auditable.
	 *
	 * @param int $order_id Order id.
	 * @return bool True when a converted hold was released.
	 */
	public static function release_order_hold( $order_id ) {
		global $wpdb;
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return false;
		}
		$table = $wpdb->prefix . 'doughboss_capacity_holds';
		$now   = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'released', released_at = %s, updated_at = %s WHERE order_id = %d AND status = 'converted'", $now, $now, $order_id ) );
		return (int) $updated > 0;
	}

	/**
	 * Lock a hold for order creation, with paid-order guarantees.
	 *
	 * Superset of lock_hold_for_order() (which is unchanged). With
	 * options['paid'] false it behaves identically. With options['paid'] true it
	 * never fails an order for capacity reasons because the money has already
	 * moved: an expired/released hold is re-acquired (flagging overflow when the
	 * slot filled meanwhile) and a slot that has already started is shifted to
	 * now + prep. Wrong shop / cart mismatch / unknown token stay errors.
	 *
	 * Must run inside the caller's order transaction (slot lock, then hold lock).
	 *
	 * @param string                 $raw_token   Browser hold token.
	 * @param string                 $cart_hash   Recomputed server cart hash.
	 * @param int                    $location_id Resolved order location.
	 * @param array                  $options     paid (bool), prep_minutes (int), slot_minutes (int).
	 * @param DateTimeImmutable|null $now_utc     Injected clock.
	 * @return array|WP_Error
	 */
	public static function lock_hold_for_checkout( $raw_token, $cart_hash, $location_id, array $options = array(), $now_utc = null ) {
		global $wpdb;
		$paid         = ! empty( $options['paid'] );
		$prep_minutes = isset( $options['prep_minutes'] ) ? max( 0, min( 1440, (int) $options['prep_minutes'] ) ) : null;
		$slot_minutes = isset( $options['slot_minutes'] ) && (int) $options['slot_minutes'] > 0 ? max( 5, min( 120, (int) $options['slot_minutes'] ) ) : 15;
		$raw_token    = trim( (string) $raw_token );
		$cart_hash    = (string) $cart_hash;
		$token_hash   = hash( 'sha256', $raw_token );
		if ( 64 !== strlen( $raw_token ) || 64 !== strlen( $cart_hash ) || ! absint( $location_id ) ) {
			return new WP_Error( 'doughboss_hold_invalid', __( 'The pickup-time hold is invalid.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$now = self::utc_clock( $now_utc );
		if ( ! $now ) {
			return new WP_Error( 'doughboss_hold_clock', __( 'The server clock is unavailable.', 'doughboss' ), array( 'status' => 503 ) );
		}
		$now_mysql = $now->format( 'Y-m-d H:i:s' );

		$holds = $wpdb->prefix . 'doughboss_capacity_holds';
		$slots = $wpdb->prefix . 'doughboss_capacity_slots';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$slot_id = $wpdb->get_var( $wpdb->prepare( "SELECT slot_id FROM {$holds} WHERE token_hash = %s", $token_hash ) );
		if ( ! $slot_id ) {
			return new WP_Error( 'doughboss_hold_missing', __( 'That pickup-time hold could not be found.', 'doughboss' ), array( 'status' => 409 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$slot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$slots} WHERE id = %d FOR UPDATE", $slot_id ) );
		if ( ! $slot || (int) $slot->location_id !== (int) $location_id || 'pickup' !== $slot->order_type ) {
			return new WP_Error( 'doughboss_hold_mismatch', __( 'That pickup-time hold belongs to another shop or order type.', 'doughboss' ), array( 'status' => 409 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hold = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$holds} WHERE token_hash = %s AND slot_id = %d FOR UPDATE", $token_hash, $slot_id ) );
		if ( ! $hold || ! hash_equals( (string) $hold->token_hash, $token_hash ) || ! hash_equals( (string) $hold->cart_hash, $cart_hash ) ) {
			return new WP_Error( 'doughboss_hold_mismatch', __( 'The cart changed after this pickup time was held.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( 'converted' === $hold->status && ! empty( $hold->order_id ) ) {
			$orders = $wpdb->prefix . 'doughboss_orders';
			// A replay is valid only when both sides of the allocation link agree.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$linked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$orders} WHERE id = %d AND capacity_hold_id = %d", $hold->order_id, $hold->id ) );
			if ( (int) $linked !== (int) $hold->order_id ) {
				return new WP_Error( 'doughboss_hold_corrupt', __( 'That pickup-time allocation is incomplete. Please contact the shop.', 'doughboss' ), array( 'status' => 409 ) );
			}
			return array( 'replayed_order_id' => (int) $hold->order_id );
		}

		$inactive    = 'held' !== $hold->status || (string) $hold->expires_at <= $now_mysql;
		$reacquired  = false;
		$overflow    = false;
		if ( $inactive ) {
			// Only a hold that was never converted can be revived, and only for money
			// that has already moved. A cancelled order's released hold stays released.
			if ( ! $paid || ! empty( $hold->order_id ) || ! in_array( (string) $hold->status, array( 'held', 'expired', 'released' ), true ) ) {
				return new WP_Error( 'doughboss_hold_expired', __( 'That pickup-time hold has expired. Please choose a time again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$used = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS orders_used, COALESCE(SUM(capacity_units),0) AS units_used FROM {$holds} WHERE slot_id = %d AND id <> %d AND (status = 'converted' OR (status = 'held' AND expires_at > %s))", $slot_id, (int) $hold->id, $now_mysql ) );
			$orders_used = $used ? (int) $used->orders_used : 0;
			$overflow    = $orders_used + 1 > (int) $slot->order_capacity;
			$revived_to  = $now->modify( '+60 seconds' )->format( 'Y-m-d H:i:s' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$revived = $wpdb->query( $wpdb->prepare( "UPDATE {$holds} SET status = 'held', expires_at = %s, released_at = NULL, updated_at = %s WHERE id = %d AND order_id IS NULL", $revived_to, $now_mysql, (int) $hold->id ) );
			if ( 1 !== $revived ) {
				return new WP_Error( 'doughboss_hold_storage', __( 'The pickup time could not be held. Please try again.', 'doughboss' ), array( 'status' => 503 ) );
			}
			$reacquired = true;
		}

		$ready_from = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $slot->starts_at_utc, new DateTimeZone( 'UTC' ) );
		$ready_by   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $slot->ends_at_utc, new DateTimeZone( 'UTC' ) );
		if ( ! $ready_from || ! $ready_by || $ready_by <= $ready_from ) {
			return new WP_Error( 'doughboss_slot_unavailable', __( 'That pickup time is no longer available.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$shifted = false;
		if ( $ready_from <= $now ) {
			if ( ! $paid ) {
				return new WP_Error( 'doughboss_slot_unavailable', __( 'That pickup time is no longer available.', 'doughboss' ), array( 'status' => 409 ) );
			}
			// The slot started while the customer was paying: promise now + prep.
			$shifted    = true;
			$ready_from = $now->modify( '+' . (int) $prep_minutes . ' minutes' );
			$ready_by   = $ready_from->modify( '+' . $slot_minutes . ' minutes' );
		}
		$fire_at = null;
		if ( null !== $prep_minutes ) {
			$fire_at = $ready_from->modify( '-' . $prep_minutes . ' minutes' )->format( 'Y-m-d H:i:s' );
		}
		return array(
			'hold_id'                 => (int) $hold->id,
			'capacity_units'          => (int) $hold->capacity_units,
			'promised_ready_from_utc' => $ready_from->format( 'Y-m-d H:i:s' ),
			'promised_ready_by_utc'   => $ready_by->format( 'Y-m-d H:i:s' ),
			'timezone_snapshot'       => (string) $slot->timezone_snapshot,
			'fire_at_utc'             => $fire_at,
			'planning_version'        => (int) $slot->planning_version,
			'reacquired'              => $reacquired,
			'overflow'                => $overflow,
			'shifted'                 => $shifted,
		);
	}

	/** @return string MySQL UTC timestamp, or '' when the ISO instant is invalid. */
	private static function iso_to_mysql( $iso ) {
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', (string) $iso, new DateTimeZone( 'UTC' ) );
		return $parsed ? $parsed->format( 'Y-m-d H:i:s' ) : '';
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
