≠rá^—f•ñÿ¶{M¨y 'v√Æ∂õ≠<?php
/**
 * PII-free, staff-managed table occupancy for QR table service.
 *
 * Automatic holds are deliberately derived from committed table-QR orders,
 * rather than a best-effort post-checkout write. This means a paid/accepted
 * order can never be visible to production while its own table appears free.
 * A staff release records a watermark: it clears earlier holds but a genuinely
 * later QR order from that table reserves it again.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DoughBoss_Table_Occupancy {

	/** QR table orders reserve a table for exactly fifteen minutes. */
	const HOLD_SECONDS = 900;

	/**
	 * Return PII-free table cards for one permitted location scope.
	 *
	 * @param int $location_id Optional location; zero is intentional manager all-shop scope.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_tables( $location_id = 0 ) {
		global $wpdb;
		$tables    = $wpdb->prefix . 'doughboss_dining_tables';
		$orders    = $wpdb->prefix . 'doughboss_orders';
		$locations = $wpdb->prefix . 'doughboss_locations';
		$location_id = absint( $location_id );
		$where = '';
		$params = array();
		if ( $location_id ) {
			$where    = 'WHERE t.location_id = %d';
			$params[] = $location_id;
		}

		// The correlated lookup uses the existing location/table/created index and
		// runs over the small operational table list. It selects only the newest
		// durable QR order after a staff release watermark; no QR/session/customer
		// material crosses this boundary.
		$sql = "SELECT t.id, t.location_id, t.label, t.zone, t.is_active, t.manual_reserved_until, t.manual_released_at, t.manual_release_order_id, t.reservation_version, l.name AS location_name,
			o.id AS recent_order_id, o.order_number AS recent_order_number, o.created_at AS recent_order_created_at
			FROM {$tables} t
			INNER JOIN {$locations} l ON l.id = t.location_id
			LEFT JOIN {$orders} o ON o.id = (
				SELECT newest.id FROM {$orders} newest
				WHERE newest.table_id = t.id
					AND newest.order_type = 'dine_in'
					AND newest.order_source = 'table_qr'
					AND newest.status <> 'cancelled'
					AND (
						newest.created_at > COALESCE(t.manual_released_at, '1970-01-01 00:00:00')
						OR newest.id > t.manual_release_order_id
					)
					AND newest.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)
				ORDER BY newest.created_at DESC, newest.id DESC
				LIMIT 1
			)
			{$where}
			ORDER BY l.sort_order ASC, l.name ASC, t.sort_order ASC, CAST(t.label AS UNSIGNED) ASC, t.label ASC";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $params ? (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : (array) $wpdb->get_results( $sql );
		return array_map( array( __CLASS__, 'shape_table' ), $rows );
	}

	/**
	 * Read a single PII-free table row.
	 *
	 * @param int $table_id Table ID.
	 * @return array|WP_Error
	 */
	public static function get_table( $table_id ) {
		global $wpdb;
		$table_id = absint( $table_id );
		if ( ! $table_id ) {
			return new WP_Error( 'doughboss_table_not_found', __( 'Table not found.', 'doughboss' ), array( 'status' => 404 ) );
		}
		$tables = $wpdb->prefix . 'doughboss_dining_tables';
		// Read only the location scope here. The public response remains shaped by
		// list_tables(), which deliberately omits every QR/session/customer field.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$location_id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT location_id FROM {$tables} WHERE id = %d LIMIT 1", $table_id ) ) );
		if ( ! $location_id ) {
			return new WP_Error( 'doughboss_table_not_found', __( 'Table not found.', 'doughboss' ), array( 'status' => 404 ) );
		}
		foreach ( self::list_tables( $location_id ) as $table ) {
			if ( $table_id === (int) $table['id'] ) {
				return $table;
			}
		}
		return new WP_Error( 'doughboss_table_not_found', __( 'Table not found.', 'doughboss' ), array( 'status' => 404 ) );
	}

	/**
	 * Reserve an active table for a staff-controlled fifteen-minute window.
	 *
	 * @param int    $table_id Table ID.
	 * @param int    $expected_version Last observed reservation version.
	 * @param int    $actor_id WordPress user ID.
	 * @param string $event_key Client idempotency key.
	 * @return array|WP_Error
	 */
	public static function reserve_staff( $table_id, $expected_version, $actor_id, $event_key ) {
		return self::write_staff_event( $table_id, 'reserved', $expected_version, $actor_id, $event_key );
	}

	/**
	 * Release a table. The release watermark stops an older QR order from
	 * reappearing; a later QR order still creates its normal fresh hold.
	 *
	 * @param int    $table_id Table ID.
	 * @param int    $expected_version Last observed reservation version.
	 * @param int    $actor_id WordPress user ID.
	 * @param string $event_key Client idempotency key.
	 * @return array|WP_Error
	 */
	public static function release_staff( $table_id, $expected_version, $actor_id, $event_key ) {
		return self::write_staff_event( $table_id, 'released', $expected_version, $actor_id, $event_key );
	}

	/**
	 * Serialize and audit a staff transition. The table row is locked before an
	 * idempotency lookup, preventing concurrent double taps from racing into a
	 * false conflict. Reusing an event key for another table/action is rejected.
	 *
	 * @param int    $table_id Table ID.
	 * @param string $event_type reserved|released.
	 * @param int    $expected_version Last observed version.
	 * @param int    $actor_id Staff user ID.
	 * @param string $event_key Client idempotency key.
	 * @return array|WP_Error
	 */
	private static function write_staff_event( $table_id, $event_type, $expected_version, $actor_id, $event_key ) {
		global $wpdb;
		$table_id = absint( $table_id );
		$expected_version = absint( $expected_version );
		$actor_id = absint( $actor_id );
		$event_key = sanitize_text_field( $event_key );
		if ( ! $table_id || ! in_array( $event_type, array( 'reserved', 'released' ), true ) || ! $actor_id || ! preg_match( '/^[A-Za-z0-9:_-]{8,191}$/', $event_key ) ) {
			return new WP_Error( 'doughboss_table_reservation_invalid', __( 'That table action could not be verified. Refresh the board and try again.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$tables = $wpdb->prefix . 'doughboss_dining_tables';
		$events = $wpdb->prefix . 'doughboss_table_reservation_events';
		$orders = $wpdb->prefix . 'doughboss_orders';
		$now    = current_time( 'mysql', true );
		$until  = 'reserved' === $event_type ? gmdate( 'Y-m-d H:i:s', time() + self::HOLD_SECONDS ) : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'doughboss_table_reservation_unavailable', __( 'Table reservations are temporarily unavailable. Please try again.', 'doughboss' ), array( 'status' => 503 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables} WHERE id = %d LIMIT 1 FOR UPDATE", $table_id ) );
		if ( ! $row ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_table_not_found', __( 'Table not found.', 'doughboss' ), array( 'status' => 404 ) );
		}
		if ( ! (int) $row->is_active ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_table_inactive', __( 'That table is not active for service.', 'doughboss' ), array( 'status' => 409 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$replay = $wpdb->get_row( $wpdb->prepare( "SELECT table_id, event_type FROM {$events} WHERE event_key = %s LIMIT 1", $event_key ) );
		if ( $replay ) {
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( (int) $replay->table_id !== $table_id || $event_type !== (string) $replay->event_type ) {
				return new WP_Error( 'doughboss_table_reservation_key_reused', __( 'This table action key was already used for a different action. Refresh and try again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			return self::get_table( $table_id );
		}
		if ( $expected_version !== (int) $row->reservation_version ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_table_reservation_conflict', __( 'This table changed on another screen. Refreshing the current table state now.', 'doughboss' ), array( 'status' => 409 ) );
		}

		// created_at has second precision on established sites. Capture the newest
		// QR order ID while the table transition is serialized, too, so a genuinely
		// later QR order in the same second is never mistaken for the old hold that
		// staff just released. New order IDs are monotonic and can renew the table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$release_order_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$orders} WHERE table_id = %d AND order_type = 'dine_in' AND order_source = 'table_qr' ORDER BY id DESC LIMIT 1",
				$table_id
			)
		);

		$next_version = (int) $row->reservation_version + 1;
		$values = array(
			'manual_reserved_until' => $until,
			// Both actions establish a watermark. Therefore an older QR order never
			// springs back after a staff release/reserve expires; a later order does.
			'manual_released_at'    => $now,
			'manual_release_order_id' => $release_order_id,
			'reservation_version'   => $next_version,
			'updated_at'            => $now,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update( $tables, $values, array( 'id' => $table_id ), array( '%s', '%s', '%d', '%d', '%s' ), array( '%d' ) );
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_table_reservation_failed', __( 'Could not update that table. Please try again.', 'doughboss' ), array( 'status' => 500 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			$events,
			array(
				'table_id'            => $table_id,
				'reservation_version' => $next_version,
				'event_type'          => $event_type,
				'actor_user_id'       => $actor_id,
				'event_key'           => $event_key,
				'reserved_until'      => $until,
				'created_at'          => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( false === $inserted || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_table_reservation_failed', __( 'Could not save that table action. Please try again.', 'doughboss' ), array( 'status' => 500 ) );
		}
		return self::get_table( $table_id );
	}

	/**
	 * Convert one storage row into the effective table state.
	 *
	 * @param object $row Database row.
	 * @return array<string,mixed>
	 */
	private static function shape_table( $row ) {
		$now = time();
		$manual_until = ! empty( $row->manual_reserved_until ) ? strtotime( $row->manual_reserved_until . ' UTC' ) : 0;
		$order_until  = ! empty( $row->recent_order_created_at ) ? strtotime( $row->recent_order_created_at . ' UTC' ) + self::HOLD_SECONDS : 0;
		$manual_live  = $manual_until > $now;
		$order_live   = $order_until > $now;
		$source       = '';
		$until        = 0;
		if ( $manual_live && $manual_until >= $order_until ) {
			$source = 'staff';
			$until  = $manual_until;
		} elseif ( $order_live ) {
			$source = 'order';
			$until  = $order_until;
		}
		$reserved = (int) $row->is_active && $until > $now;
		return array(
			'id'                    => (int) $row->id,
			'location_id'           => (int) $row->location_id,
			'location_name'         => sanitize_text_field( (string) $row->location_name ),
			'label'                 => sanitize_text_field( (string) $row->label ),
			'zone'                  => sanitize_text_field( (string) $row->zone ),
			'is_active'             => (bool) $row->is_active,
			'status'                => ! (int) $row->is_active ? 'inactive' : ( $reserved ? 'reserved' : 'available' ),
			'reservation_source'    => $reserved ? $source : '',
			'reserved_until'        => $reserved ? gmdate( 'c', $until ) : '',
			'seconds_remaining'     => $reserved ? $until - $now : 0,
			'reserved_order_number' => $reserved && 'order' === $source ? sanitize_text_field( (string) $row->recent_order_number ) : '',
			'reservation_version'   => (int) $row->reservation_version,
		);
	}
}
