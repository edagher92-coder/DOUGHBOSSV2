<?php
/**
 * Shop locations data model and persistence.
 *
 * Multi-shop foundation: each location is a shop (e.g. Bankstown, Revesby,
 * Roselands) with its own fulfilment options and order routing. Orders carry a
 * location_id so each shop's kitchen board sees only its own orders.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes shop locations.
 */
class DoughBoss_Locations {

	/**
	 * Locations table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_locations';
	}

	/**
	 * Fetch all locations (optionally only active), ordered for display.
	 *
	 * @param bool $active_only Only return active shops.
	 * @return object[]
	 */
	public static function all( $active_only = false ) {
		global $wpdb;
		$table = self::table();
		$where = $active_only ? 'WHERE is_active = 1' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (array) $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, name ASC" );
	}

	/**
	 * Fetch a single location.
	 *
	 * @param int $id Location ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$id    = absint( $id );
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Whether a location id refers to an existing, active shop.
	 *
	 * @param int $id Location ID.
	 * @return bool
	 */
	public static function is_valid( $id ) {
		$loc = self::get( $id );
		return $loc && (int) $loc->is_active === 1;
	}

	/**
	 * How many locations exist.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * The default shop id (first active, else first, else 0).
	 *
	 * @return int
	 */
	public static function default_id() {
		$all = self::all( true );
		if ( ! $all ) {
			$all = self::all( false );
		}
		return $all ? (int) $all[0]->id : 0;
	}

	/**
	 * Effective single-location mode.
	 *
	 * The stored toggle is only honoured when exactly one active shop exists.
	 * This fail-closed rule prevents a stale migration/default from silently
	 * routing a multi-shop order to whichever row happens to sort first.
	 *
	 * @return int The sole active location id, or 0 when the mode is not effective.
	 */
	public static function single_location_id() {
		if ( ! DoughBoss_Settings::get( 'single_location_mode', 1 ) ) {
			return 0;
		}
		$active = self::all( true );
		return 1 === count( $active ) ? (int) $active[0]->id : 0;
	}

	/**
	 * Sanitize a raw input row into a storable record.
	 *
	 * @param array $data Raw input.
	 * @return array
	 */
	private static function sanitize( array $data ) {
		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$slug = isset( $data['slug'] ) && '' !== $data['slug'] ? sanitize_title( $data['slug'] ) : sanitize_title( $name );
		$timezone = isset( $data['timezone'] ) ? sanitize_text_field( $data['timezone'] ) : 'Australia/Sydney';
		try {
			new DateTimeZone( $timezone );
		} catch ( Exception $e ) {
			$timezone = 'Australia/Sydney';
		}
		$capacity_mode = isset( $data['capacity_mode'] ) ? sanitize_key( $data['capacity_mode'] ) : 'off';
		// Customer enforcement is intentionally not exposed until checkout hold
		// conversion and the real MariaDB race suite are both green.
		if ( ! in_array( $capacity_mode, array( 'off', 'shadow' ), true ) ) {
			$capacity_mode = 'off';
		}

		return array(
			'name'              => $name,
			'slug'              => $slug,
			'suburb'            => isset( $data['suburb'] ) ? sanitize_text_field( $data['suburb'] ) : '',
			'address'           => isset( $data['address'] ) ? sanitize_textarea_field( $data['address'] ) : '',
			'phone'             => isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '',
			'postcodes'         => isset( $data['postcodes'] ) ? sanitize_text_field( $data['postcodes'] ) : '',
			'prep_time_default' => isset( $data['prep_time_default'] ) ? max( 0, (int) $data['prep_time_default'] ) : 20,
			'timezone'          => $timezone,
			'capacity_mode'     => $capacity_mode,
			'slot_minutes'      => isset( $data['slot_minutes'] ) ? max( 5, min( 120, (int) $data['slot_minutes'] ) ) : 15,
			'minimum_notice_minutes' => isset( $data['minimum_notice_minutes'] ) ? max( 0, min( 1440, (int) $data['minimum_notice_minutes'] ) ) : 30,
			'booking_horizon_days' => isset( $data['booking_horizon_days'] ) ? max( 1, min( 31, (int) $data['booking_horizon_days'] ) ) : 7,
			'hold_minutes'      => isset( $data['hold_minutes'] ) ? max( 1, min( 30, (int) $data['hold_minutes'] ) ) : 10,
			'slot_order_capacity' => isset( $data['slot_order_capacity'] ) ? max( 1, min( 10000, (int) $data['slot_order_capacity'] ) ) : 4,
			'slot_unit_capacity' => isset( $data['slot_unit_capacity'] ) ? max( 1, min( 10000, (int) $data['slot_unit_capacity'] ) ) : 12,
			'tyro_location_id'   => isset( $data['tyro_location_id'] ) ? substr( sanitize_text_field( $data['tyro_location_id'] ), 0, 191 ) : '',
			'pospal_store_index'  => isset( $data['pospal_store_index'] ) ? max( 0, min( 3, (int) $data['pospal_store_index'] ) ) : 0,
			'online_payment_enabled' => empty( $data['online_payment_enabled'] ) ? 0 : 1,
			'pickup_enabled'    => empty( $data['pickup_enabled'] ) ? 0 : 1,
			'delivery_enabled'  => empty( $data['delivery_enabled'] ) ? 0 : 1,
			'is_active'         => empty( $data['is_active'] ) ? 0 : 1,
			'sort_order'        => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
		);
	}

	/**
	 * Make a slug unique among stored locations by appending a numeric suffix
	 * on collision (bankstown, bankstown-2, bankstown-3, …) — the same de-dup
	 * idea as the settings rows in DoughBoss_Admin::sanitize_rows(), but
	 * deterministic against what's already in the table.
	 *
	 * @param string $slug Candidate slug (already sanitized).
	 * @return string
	 */
	private static function unique_slug( $slug ) {
		global $wpdb;
		$slug  = '' !== $slug ? $slug : 'shop';
		$table = self::table();

		$candidate = $slug;
		$suffix    = 2;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE slug = %s", $candidate ) ) > 0 ) {
			$candidate = $slug . '-' . $suffix;
			++$suffix;
		}
		return $candidate;
	}

	/**
	 * Create a location.
	 *
	 * @param array $data Raw input.
	 * @return int New id (0 on failure / empty name).
	 */
	public static function create( array $data ) {
		global $wpdb;
		$row = self::sanitize( $data );
		if ( '' === $row['name'] ) {
			return 0;
		}
		$row['slug'] = self::unique_slug( $row['slug'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			self::table(),
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%d' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a location.
	 *
	 * @param int   $id   Location ID.
	 * @param array $data Raw input.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}
		$row = self::sanitize( $data );
		if ( '' === $row['name'] ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			self::table(),
			$row,
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%d' ),
			array( '%d' )
		);
		if ( false !== $updated ) {
			$table = self::table();
			// A changed location plan only affects newly materialised slots. Existing
			// promises retain their slot snapshot and planning version.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET planning_version = planning_version + 1 WHERE id = %d", $id ) );
		}
		return false !== $updated;
	}

	/**
	 * Read weekly pickup hours as admin-friendly comma-separated ranges.
	 *
	 * @param int $location_id Location id.
	 * @return array<string,string>
	 */
	public static function weekly_hours( $location_id ) {
		global $wpdb;
		$keys = array( 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun' );
		$out  = array_fill_keys( array_values( $keys ), '' );
		$table = $wpdb->prefix . 'doughboss_location_hours';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT weekday, opens_at, closes_at FROM {$table} WHERE location_id = %d AND order_type = 'pickup' AND is_active = 1 ORDER BY weekday, segment", absint( $location_id ) ) );
		foreach ( (array) $rows as $row ) {
			$weekday = isset( $keys[ (int) $row->weekday ] ) ? $keys[ (int) $row->weekday ] : '';
			if ( ! $weekday ) { continue; }
			$range = substr( (string) $row->opens_at, 0, 5 ) . '-' . substr( (string) $row->closes_at, 0, 5 );
			$out[ $weekday ] = $out[ $weekday ] ? $out[ $weekday ] . ', ' . $range : $range;
		}
		return $out;
	}

	/**
	 * Atomically replace a location's weekly pickup-hour segments.
	 *
	 * @param int   $location_id Location id.
	 * @param array $input       mon..sun comma-separated HH:MM-HH:MM ranges.
	 * @return true|WP_Error
	 */
	public static function save_weekly_hours( $location_id, array $input ) {
		global $wpdb;
		$map = array( 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7 );
		$rows = array();
		foreach ( $map as $key => $weekday ) {
			$raw = isset( $input[ $key ] ) ? trim( sanitize_text_field( $input[ $key ] ) ) : '';
			if ( '' === $raw ) { continue; }
			$segment = 0;
			foreach ( explode( ',', $raw ) as $range ) {
				++$segment;
				$range = trim( $range );
				if ( ! preg_match( '/^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', $range, $match ) || ! self::valid_clock( $match[1] ) || ! self::valid_clock( $match[2] ) || $match[1] === $match[2] ) {
					return new WP_Error( 'doughboss_hours_invalid', sprintf( __( 'Invalid %s hours. Use HH:MM-HH:MM, for example 11:00-21:00.', 'doughboss' ), strtoupper( $key ) ) );
				}
				$rows[] = array( 'weekday' => $weekday, 'segment' => $segment, 'opens_at' => $match[1] . ':00', 'closes_at' => $match[2] . ':00' );
			}
		}

		$table = $wpdb->prefix . 'doughboss_location_hours';
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE location_id = %d AND order_type = 'pickup'", absint( $location_id ) ) ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_hours_storage', __( 'Pickup hours could not be saved.', 'doughboss' ) );
		}
		foreach ( $rows as $row ) {
			$row = array(
				'location_id' => absint( $location_id ),
				'order_type'  => 'pickup',
				'weekday'     => $row['weekday'],
				'segment'     => $row['segment'],
				'opens_at'    => $row['opens_at'],
				'closes_at'   => $row['closes_at'],
				'is_active'   => 1,
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $wpdb->insert( $table, $row, array( '%d', '%s', '%d', '%d', '%s', '%s', '%d' ) ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return new WP_Error( 'doughboss_hours_storage', __( 'Pickup hours could not be saved.', 'doughboss' ) );
			}
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return true;
	}

	/**
	 * Build a read-only, schedule-only capacity configuration for staff shadowing.
	 *
	 * Dated overrides are not interpreted as live capacity yet. Closed dates and
	 * every unsupported override date are blacked out so the preview fails closed.
	 *
	 * @param int $location_id Location id.
	 * @return array|null
	 */
	public static function capacity_preview_config( $location_id ) {
		global $wpdb;
		$loc = self::get( $location_id );
		if ( ! $loc || ! isset( $loc->capacity_mode ) || 'shadow' !== (string) $loc->capacity_mode || empty( $loc->is_active ) || empty( $loc->pickup_enabled ) ) {
			return null;
		}
		$hours_raw = self::weekly_hours( $location_id );
		$hours = array();
		foreach ( array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' ) as $day ) {
			$hours[ $day ] = array();
			if ( empty( $hours_raw[ $day ] ) ) { continue; }
			foreach ( explode( ',', $hours_raw[ $day ] ) as $range ) {
				if ( preg_match( '/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', trim( $range ), $match ) ) {
					$hours[ $day ][] = array( $match[1], $match[2] );
				}
			}
		}

		$exceptions = $wpdb->prefix . 'doughboss_schedule_exceptions';
		try {
			$local_today = new DateTimeImmutable( 'today', new DateTimeZone( (string) $loc->timezone ) );
		} catch ( Exception $e ) {
			return null;
		}
		$today   = $local_today->format( 'Y-m-d' );
		$through = $local_today->modify( '+' . max( 1, min( 31, (int) $loc->booking_horizon_days ) ) . ' days' )->format( 'Y-m-d' );
		// Until dated hours/capacity overrides are implemented end-to-end, omit
		// every exception date rather than showing a potentially false promise.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$blackouts = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT service_date FROM {$exceptions} WHERE location_id = %d AND order_type = 'pickup' AND service_date BETWEEN %s AND %s", absint( $location_id ), $today, $through ) );

		return array(
			'location_id'      => (int) $loc->id,
			'planning_version' => (int) $loc->planning_version,
			'enabled'          => true,
			'active'           => true,
			'pickup_enabled'   => true,
			'timezone'         => (string) $loc->timezone,
			'slot_minutes'     => (int) $loc->slot_minutes,
			'notice_minutes'   => (int) $loc->minimum_notice_minutes,
			'horizon_days'     => (int) $loc->booking_horizon_days,
			'capacity_units'   => (int) $loc->slot_unit_capacity,
			'blackout_dates'   => $blackouts,
			'hours'            => $hours,
		);
	}

	/** @return bool */
	private static function valid_clock( $time ) {
		return (bool) preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $time );
	}

	/**
	 * Delete a location.
	 *
	 * @param int $id Location ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Create a sensible default shop the first time, so existing single-shop
	 * sites work without configuration.
	 *
	 * @return void
	 */
	public static function ensure_default() {
		if ( self::count() > 0 ) {
			return;
		}
		// If the blogname looks like a Dough Boss install, seed the primary
		// Revesby shop with real address + phone so the storefront works out of
		// the box. Any other install falls back to the WP site name (previous
		// behaviour). Owners can edit or rename the seed row afterwards.
		$blog       = (string) get_option( 'blogname' );
		$is_dough   = '' !== $blog && false !== stripos( $blog, 'dough boss' );
		$seed_name  = $is_dough ? __( 'Revesby', 'doughboss' ) : ( '' !== $blog ? $blog : __( 'Main Shop', 'doughboss' ) );
		$seed = array(
			'name'             => $seed_name,
			'pickup_enabled'   => DoughBoss_Settings::get( 'enable_pickup', 1 ),
			'delivery_enabled' => DoughBoss_Settings::get( 'enable_delivery', 0 ),
			'is_active'        => 1,
		);
		if ( $is_dough ) {
			$seed['suburb']  = 'Revesby';
			$seed['address'] = "12/25 Selems Parade\nRevesby NSW 2212";
			$seed['phone']   = '(02) 9774 2286';
		}
		self::create( $seed );
	}

	/**
	 * Public-facing view of a location for the storefront/board.
	 *
	 * @param object $loc Location row.
	 * @return array
	 */
	public static function public_view( $loc ) {
		$pickup_status = self::pickup_status( $loc );
		return array(
			'id'               => (int) $loc->id,
			'name'             => $loc->name,
			'slug'             => $loc->slug,
			'suburb'           => $loc->suburb,
			'address'          => $loc->address,
			'phone'            => $loc->phone,
			'pickup_enabled'   => (bool) $loc->pickup_enabled,
			'delivery_enabled' => (bool) $loc->delivery_enabled,
			'prep_time'        => (int) $loc->prep_time_default,
			'timezone'         => isset( $loc->timezone ) ? $loc->timezone : 'Australia/Sydney',
			// Schedule only. This is not an assertion about ordering, capacity,
			// payment availability or a guaranteed pickup time.
			'pickup_status'    => $pickup_status,
			'capacity_preview' => isset( $loc->capacity_mode ) && 'shadow' === $loc->capacity_mode,
		);
	}

	/**
	 * Return a short-lived, read-only view of configured pickup hours.
	 *
	 * Dated exceptions are conservatively treated as blackouts until their
	 * override semantics are implemented end-to-end. The optional clock exists
	 * for deterministic tests; production callers use the current UTC instant.
	 *
	 * @param object                     $loc Location row.
	 * @param DateTimeImmutable|null     $now_utc Optional injected UTC clock.
	 * @return array
	 */
	public static function pickup_status( $loc, $now_utc = null ) {
		$now_utc = self::pickup_status_clock( $now_utc );
		if ( ! $now_utc ) {
			return self::unknown_pickup_status( '' );
		}

		$timezone_name = is_object( $loc ) && isset( $loc->timezone ) ? (string) $loc->timezone : '';
		if ( ! is_object( $loc ) || empty( $loc->pickup_enabled ) || ( isset( $loc->is_active ) && ! $loc->is_active ) ) {
			return self::pickup_status_response( 'unavailable', $now_utc, null, null, null, $timezone_name );
		}
		try {
			$timezone = new DateTimeZone( $timezone_name );
		} catch ( Exception $e ) {
			return self::unknown_pickup_status( $timezone_name, $now_utc );
		}

		$location_id = isset( $loc->id ) ? absint( $loc->id ) : 0;
		if ( ! $location_id ) {
			return self::unknown_pickup_status( $timezone->getName(), $now_utc );
		}
		$hours = self::pickup_hours( $location_id );
		if ( false === $hours || empty( $hours ) ) {
			return self::unknown_pickup_status( $timezone->getName(), $now_utc );
		}

		$local_now     = $now_utc->setTimezone( $timezone );
		$local_today   = $local_now->setTime( 0, 0, 0 );
		$horizon_days  = isset( $loc->booking_horizon_days ) ? max( 1, min( 8, (int) $loc->booking_horizon_days ) ) : 8;
		$blackouts     = self::pickup_blackouts( $location_id, $local_today, $horizon_days );
		if ( false === $blackouts ) {
			return self::unknown_pickup_status( $timezone->getName(), $now_utc );
		}
		$intervals = self::pickup_intervals( $hours, $blackouts, $local_today, $horizon_days, $timezone );
		if ( false === $intervals ) {
			return self::unknown_pickup_status( $timezone->getName(), $now_utc );
		}

		$current   = null;
		$next_open = null;
		foreach ( $intervals as $interval ) {
			if ( $interval[0] <= $now_utc && $now_utc < $interval[1] ) {
				$current = $interval;
				break;
			}
			if ( $interval[0] > $now_utc && null === $next_open ) {
				$next_open = $interval[0];
			}
		}

		if ( $current ) {
			$state = ( $current[1]->getTimestamp() - $now_utc->getTimestamp() <= 30 * MINUTE_IN_SECONDS ) ? 'closes_soon' : 'open';
			$state_transition = 'open' === $state ? $current[1]->modify( '-30 minutes' ) : null;
			return self::pickup_status_response( $state, $now_utc, $current[1], null, null, $timezone->getName(), $state_transition );
		}
		return self::pickup_status_response(
			'closed',
			$now_utc,
			null,
			$next_open,
			$next_open ? self::pickup_open_label( $next_open, $timezone ) : null,
			$timezone->getName()
		);
	}

	/** @return DateTimeImmutable|false */
	private static function pickup_status_clock( $now_utc ) {
		try {
			$clock = $now_utc instanceof DateTimeImmutable ? $now_utc : new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			return $clock->setTimezone( new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/** @return array */
	private static function unknown_pickup_status( $timezone, $now_utc = null ) {
		$clock = self::pickup_status_clock( $now_utc );
		if ( ! $clock ) {
			$clock = new DateTimeImmutable( '@0' );
		}
		return self::pickup_status_response( 'unknown', $clock, null, null, null, (string) $timezone );
	}

	/** @return array */
	private static function pickup_status_response( $state, DateTimeImmutable $observed, $closes_at, $next_open_at, $next_open_label, $timezone, $state_transition = null ) {
		$expires = $observed->modify( '+60 seconds' );
		foreach ( array( $closes_at, $next_open_at, $state_transition ) as $transition ) {
			if ( $transition instanceof DateTimeImmutable && $transition > $observed && $transition < $expires ) {
				$expires = $transition;
			}
		}
		return array(
			'state'           => $state,
			'observed_at_utc' => $observed->format( 'Y-m-d\\TH:i:s\\Z' ),
			'expires_at_utc'  => $expires->format( 'Y-m-d\\TH:i:s\\Z' ),
			'closes_at_utc'   => $closes_at instanceof DateTimeImmutable ? $closes_at->format( 'Y-m-d\\TH:i:s\\Z' ) : null,
			'next_open_at_utc'=> $next_open_at instanceof DateTimeImmutable ? $next_open_at->format( 'Y-m-d\\TH:i:s\\Z' ) : null,
			'next_open_label' => $next_open_label,
			'timezone'        => $timezone,
		);
	}

	/** @return array|false */
	private static function pickup_hours( $location_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'doughboss_location_hours';
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT weekday, opens_at, closes_at FROM {$table} WHERE location_id = %d AND order_type = 'pickup' AND is_active = 1 ORDER BY weekday, segment", $location_id ) );
		} catch ( Throwable $e ) {
			return false;
		}
		if ( null === $rows || false === $rows || ! empty( $wpdb->last_error ) ) {
			return false;
		}
		$out = array_fill( 1, 7, array() );
		$has_ranges = false;
		foreach ( (array) $rows as $row ) {
			$weekday = isset( $row->weekday ) ? (int) $row->weekday : 0;
			$open    = isset( $row->opens_at ) ? substr( (string) $row->opens_at, 0, 5 ) : '';
			$close   = isset( $row->closes_at ) ? substr( (string) $row->closes_at, 0, 5 ) : '';
			if ( ! isset( $out[ $weekday ] ) || ! self::valid_clock( $open ) || ! self::valid_clock( $close ) || $open === $close ) {
				return false;
			}
			$out[ $weekday ][] = array( $open, $close );
			$has_ranges = true;
		}
		return $has_ranges ? $out : array();
	}

	/** @return array|false */
	private static function pickup_blackouts( $location_id, DateTimeImmutable $local_today, $horizon_days ) {
		global $wpdb;
		$table = $wpdb->prefix . 'doughboss_schedule_exceptions';
		$from  = $local_today->modify( '-1 day' )->format( 'Y-m-d' );
		$to    = $local_today->modify( '+' . $horizon_days . ' days' )->format( 'Y-m-d' );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$dates = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT service_date FROM {$table} WHERE location_id = %d AND order_type = 'pickup' AND service_date BETWEEN %s AND %s", $location_id, $from, $to ) );
		} catch ( Throwable $e ) {
			return false;
		}
		if ( null === $dates || false === $dates || ! empty( $wpdb->last_error ) ) {
			return false;
		}
		$out = array();
		foreach ( (array) $dates as $date ) {
			if ( is_string( $date ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$out[ $date ] = true;
			}
		}
		return $out;
	}

	/** @return array|false */
	private static function pickup_intervals( array $hours, array $blackouts, DateTimeImmutable $local_today, $horizon_days, DateTimeZone $timezone ) {
		$intervals = array();
		for ( $offset = -1; $offset <= $horizon_days; ++$offset ) {
			$date    = $local_today->modify( ( $offset >= 0 ? '+' : '' ) . $offset . ' days' );
			$date_key = $date->format( 'Y-m-d' );
			$weekday = (int) $date->format( 'N' );
			foreach ( $hours[ $weekday ] as $range ) {
				$open  = self::pickup_local_instant( $date_key, $range[0], $timezone );
				$close_date = self::clock_minutes( $range[1] ) <= self::clock_minutes( $range[0] ) ? $date->modify( '+1 day' )->format( 'Y-m-d' ) : $date_key;
				$close = self::pickup_local_instant( $close_date, $range[1], $timezone );
				if ( ! $open || ! $close ) {
					return false;
				}
				if ( isset( $blackouts[ $date_key ] ) || isset( $blackouts[ $close_date ] ) ) {
					continue;
				}
				$open  = $open->setTimezone( new DateTimeZone( 'UTC' ) );
				$close = $close->setTimezone( new DateTimeZone( 'UTC' ) );
				if ( $close <= $open ) {
					return false;
				}
				$intervals[] = array( $open, $close );
			}
		}
		usort(
			$intervals,
			function ( $left, $right ) {
				return $left[0] <=> $right[0];
			}
		);
		$merged = array();
		foreach ( $intervals as $interval ) {
			$last = count( $merged ) - 1;
			if ( $last >= 0 && $interval[0] <= $merged[ $last ][1] ) {
				if ( $interval[1] > $merged[ $last ][1] ) {
					$merged[ $last ][1] = $interval[1];
				}
				continue;
			}
			$merged[] = $interval;
		}
		return $merged;
	}

	/** @return DateTimeImmutable|false */
	private static function pickup_local_instant( $date, $time, DateTimeZone $timezone ) {
		$input = $date . ' ' . $time;
		$wall  = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $input, new DateTimeZone( 'UTC' ) );
		if ( ! $wall ) {
			return false;
		}
		$matches = array();
		$transitions = $timezone->getTransitions( $wall->getTimestamp() - DAY_IN_SECONDS, $wall->getTimestamp() + DAY_IN_SECONDS );
		if ( false === $transitions ) {
			$instant = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $input, $timezone );
			return $instant && $instant->format( 'Y-m-d H:i' ) === $input ? $instant : false;
		}
		foreach ( $transitions as $transition ) {
			$candidate = ( new DateTimeImmutable( '@' . ( $wall->getTimestamp() - (int) $transition['offset'] ) ) )->setTimezone( $timezone );
			if ( $candidate->format( 'Y-m-d H:i' ) === $input ) {
				$matches[ $candidate->getTimestamp() ] = $candidate;
			}
		}
		return 1 === count( $matches ) ? reset( $matches ) : false;
	}

	/** @return int */
	private static function clock_minutes( $time ) {
		$parts = explode( ':', (string) $time );
		return (int) $parts[0] * 60 + (int) $parts[1];
	}

	/** @return string */
	private static function pickup_open_label( DateTimeImmutable $open, DateTimeZone $timezone ) {
		return $open->setTimezone( $timezone )->format( 'l g:ia' );
	}

	/**
	 * Resolve the server-side Tyro Connect location for an active shop.
	 *
	 * @param int $location_id DoughBoss location id.
	 * @return string|WP_Error
	 */
	public static function tyro_location_id( $location_id ) {
		$location = self::online_payment_location( $location_id );
		if ( is_wp_error( $location ) ) {
			return $location;
		}
		$provider_id = isset( $location->tyro_location_id ) ? trim( (string) $location->tyro_location_id ) : '';
		if ( '' === $provider_id ) {
			return new WP_Error( 'doughboss_pay_location_unmapped', __( 'This shop is not mapped to a Tyro payment location.', 'doughboss' ), array( 'status' => 503 ) );
		}
		return $provider_id;
	}

	/**
	 * Return an active shop that is explicitly allowed to take online card
	 * payments. This applies to every gateway, not only Tyro Connect.
	 *
	 * @param int $location_id DoughBoss location id.
	 * @return object|WP_Error
	 */
	public static function online_payment_location( $location_id ) {
		$location = self::get( $location_id );
		if ( ! $location || empty( $location->is_active ) || empty( $location->online_payment_enabled ) ) {
			return new WP_Error( 'doughboss_pay_location_off', __( 'Online payment is not enabled for this shop.', 'doughboss' ), array( 'status' => 503 ) );
		}
		return $location;
	}
}
