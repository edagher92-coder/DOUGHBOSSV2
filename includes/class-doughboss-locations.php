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
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d' )
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
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d' ),
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
			'capacity_preview' => isset( $loc->capacity_mode ) && 'shadow' === $loc->capacity_mode,
		);
	}
}
