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
	 * Sanitize a raw input row into a storable record.
	 *
	 * @param array $data Raw input.
	 * @return array
	 */
	private static function sanitize( array $data ) {
		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$slug = isset( $data['slug'] ) && '' !== $data['slug'] ? sanitize_title( $data['slug'] ) : sanitize_title( $name );

		return array(
			'name'              => $name,
			'slug'              => $slug,
			'suburb'            => isset( $data['suburb'] ) ? sanitize_text_field( $data['suburb'] ) : '',
			'address'           => isset( $data['address'] ) ? sanitize_textarea_field( $data['address'] ) : '',
			'phone'             => isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '',
			'postcodes'         => isset( $data['postcodes'] ) ? sanitize_text_field( $data['postcodes'] ) : '',
			'prep_time_default' => isset( $data['prep_time_default'] ) ? max( 0, (int) $data['prep_time_default'] ) : 20,
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
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d' )
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
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d' ),
			array( '%d' )
		);
		return false !== $updated;
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
		);
	}
}
