<?php
/**
 * Timesheet reconciliation: read-only reader of the DoughBoss core staff clock.
 *
 * Reads the core tables doughboss_staff_shifts, doughboss_staff_breaks and doughboss_staff_shift_events
 * through core's own public table-name accessors, behind fail-closed guards (03 section 2.7). It issues SELECT
 * statements only, never writes anything, and never selects a name, a login or a correction reason: the row
 * contract is ids, instants, shop, timezone, source, roster snapshot and a manager_closed boolean.
 *
 * Net minutes are computed with exactly the formula of DoughBoss_Timeclock::worked_minutes() (see
 * DoughBoss_Growth_Recon_Matcher::net_minutes()); tests/test-recon-reader.php proves parity against the real
 * core class when its source is available, and tests/integration/recon-mysql.php does so on MySQL.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core staff-clock reader.
 */
final class DoughBoss_Growth_Recon_Reader {

	/**
	 * Core version that introduced the badge kiosk and breaks (readme 2.37.0).
	 */
	const MIN_CORE_VERSION = '2.37.0';

	/**
	 * Core schema version that holds the four attendance tables.
	 */
	const MIN_CORE_DB_VERSION = '1.21.0';

	/**
	 * Hard cap on shifts per read. Reaching it fails the read (an incomplete set is never reported).
	 */
	const MAX_SHIFTS = 5000;

	/**
	 * Hard cap on breaks per read, and ids per IN (...) batch.
	 */
	const MAX_BREAKS  = 20000;
	const BREAK_BATCH = 500;

	/**
	 * Why the core staff clock cannot be read, or "" when it can.
	 *
	 * @return string
	 */
	public static function guard() {
		if ( ! class_exists( 'DoughBoss_Timeclock' ) || ! class_exists( 'DoughBoss_Staff_Badge' ) ) {
			return 'plugin_timeclock_missing';
		}
		if ( ! defined( 'DOUGHBOSS_VERSION' ) || version_compare( (string) constant( 'DOUGHBOSS_VERSION' ), self::MIN_CORE_VERSION, '<' ) ) {
			return 'plugin_version_too_old';
		}
		$db_version = get_option( 'doughboss_db_version', '' );
		if ( ! is_string( $db_version ) || '' === $db_version || version_compare( $db_version, self::MIN_CORE_DB_VERSION, '<' ) ) {
			return 'plugin_db_too_old';
		}
		foreach ( array( array( 'DoughBoss_Timeclock', 'storage_ready' ), array( 'DoughBoss_Timeclock', 'table' ), array( 'DoughBoss_Timeclock', 'events_table' ), array( 'DoughBoss_Staff_Badge', 'breaks_table' ) ) as $callable ) {
			if ( ! is_callable( $callable ) ) {
				return 'plugin_timeclock_missing';
			}
		}
		try {
			$ready = DoughBoss_Timeclock::storage_ready();
		} catch ( Throwable $e ) {
			return 'plugin_storage_not_ready';
		}
		if ( true !== $ready ) {
			return 'plugin_storage_not_ready';
		}
		if ( null === self::tables() ) {
			return 'plugin_storage_not_ready';
		}
		return '';
	}

	/**
	 * Core table names from core's accessors, validated before they are placed in SQL.
	 *
	 * @return array|null { shifts, breaks, events } or null when any name is unusable.
	 */
	public static function tables() {
		$names = array(
			'shifts' => (string) DoughBoss_Timeclock::table(),
			'breaks' => (string) DoughBoss_Staff_Badge::breaks_table(),
			'events' => (string) DoughBoss_Timeclock::events_table(),
		);
		foreach ( $names as $name ) {
			if ( 1 !== preg_match( '/^[A-Za-z0-9_]{1,64}$/D', $name ) ) {
				return null;
			}
		}
		return $names;
	}

	/**
	 * Parse a core "Y-m-d H:i:s" UTC datetime. Gives the same value as core's strtotime( $value . ' UTC' )
	 * for every valid datetime and rejects anything else (including the MySQL zero date).
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	public static function parse_utc( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})$/D', $value, $m ) ) {
			return null;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59 || (int) $m[1] < 1971 ) {
			return null;
		}
		return gmmktime( (int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * MySQL UTC datetime literal for a UNIX time.
	 *
	 * @param int $ts UNIX time.
	 * @return string
	 */
	public static function mysql_utc( $ts ) {
		return gmdate( 'Y-m-d H:i:s', (int) $ts );
	}

	/**
	 * Read every shift that overlaps [$from, $to) (open shifts always qualify, same predicate as core's
	 * timesheet), with its breaks and the manager_closed flag. All shops are read, so a shop without a
	 * mapping is reported as UNMAPPED_LOCATION rather than silently skipped.
	 *
	 * @param int $from Window start (UNIX time).
	 * @param int $to   Window end (UNIX time).
	 * @param int $now  Run time (provisional end of open shifts).
	 * @return array|WP_Error List of normalised shifts.
	 */
	public static function read( $from, $to, $now ) {
		global $wpdb;
		$guard = self::guard();
		if ( '' !== $guard ) {
			return new WP_Error( $guard, 'The DoughBoss staff clock cannot be read.' );
		}
		$tables = self::tables();
		$shifts = $tables['shifts'];
		$events = $tables['events'];
		$breaks = $tables['breaks'];

		// Explicit column list: no staff_name, staff_login, location_name, reason or JSON snapshots.
		$sql = "SELECT s.id, s.user_id, s.location_id, s.timezone_snapshot, s.clock_in_utc, s.clock_out_utc, s.source, s.late_minutes, s.scheduled_start_local, s.updated_at, CASE WHEN EXISTS (SELECT 1 FROM {$events} e WHERE e.shift_id = s.id AND e.event_type = %s) THEN 1 ELSE 0 END AS manager_closed FROM {$shifts} s WHERE s.clock_in_utc < %s AND ( s.clock_out_utc IS NULL OR s.clock_out_utc >= %s ) ORDER BY s.clock_in_utc ASC, s.id ASC LIMIT %d";
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $sql, 'manager_closed', self::mysql_utc( $to ), self::mysql_utc( $from ), self::MAX_SHIFTS + 1 ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from core accessors and are validated.
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'plugin_read_failed', 'The staff shifts could not be read.' );
		}
		if ( count( $rows ) > self::MAX_SHIFTS ) {
			return new WP_Error( 'plugin_row_cap', 'Too many staff shifts in the window to report completely.' );
		}

		$out = array();
		foreach ( $rows as $row ) {
			$shift = self::normalise_shift( $row );
			if ( null === $shift ) {
				return new WP_Error( 'plugin_data_invalid', 'A staff shift has an invalid value.' );
			}
			$out[ $shift['shift_id'] ] = $shift;
		}
		if ( array() === $out ) {
			return array();
		}

		$ids   = array_keys( $out );
		$total = 0;
		foreach ( array_chunk( $ids, self::BREAK_BATCH ) as $batch ) {
			$placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );
			$args         = $batch;
			$args[]       = self::MAX_BREAKS + 1;
			$break_rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "SELECT b.id, b.shift_id, b.break_start_utc, b.break_end_utc, b.source FROM {$breaks} b WHERE b.shift_id IN ({$placeholders}) ORDER BY b.shift_id ASC, b.id ASC LIMIT %d", $args ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- validated table name and generated placeholders.
				ARRAY_A
			);
			if ( '' !== (string) $wpdb->last_error || ! is_array( $break_rows ) ) {
				return new WP_Error( 'plugin_read_failed', 'The staff breaks could not be read.' );
			}
			$total += count( $break_rows );
			if ( count( $break_rows ) > self::MAX_BREAKS || $total > self::MAX_BREAKS ) {
				return new WP_Error( 'plugin_row_cap', 'Too many staff breaks in the window to report completely.' );
			}
			foreach ( $break_rows as $break_row ) {
				$break = self::normalise_break( $break_row );
				if ( null === $break || ! isset( $out[ $break['shift_id'] ] ) ) {
					return new WP_Error( 'plugin_data_invalid', 'A staff break has an invalid value.' );
				}
				$out[ $break['shift_id'] ]['breaks'][] = array(
					'break_id' => $break['break_id'],
					'start'    => $break['start'],
					'end'      => $break['end'],
					'source'   => $break['source'],
				);
			}
		}

		foreach ( $out as $id => $shift ) {
			$until                  = ( null === $shift['out'] ) ? (int) $now : $shift['out'];
			$out[ $id ]['break_all'] = DoughBoss_Growth_Recon_Matcher::break_minutes( $shift['breaks'], $until );
			$out[ $id ]['net']       = ( null === $shift['out'] ) ? null : DoughBoss_Growth_Recon_Matcher::net_minutes( $shift['in'], $shift['out'], $shift['breaks'] );
		}
		return array_values( $out );
	}

	/**
	 * Validate one shift row.
	 *
	 * @param array $row Row.
	 * @return array|null
	 */
	private static function normalise_shift( array $row ) {
		$id       = self::positive_int( isset( $row['id'] ) ? $row['id'] : null );
		$user_id  = self::positive_int( isset( $row['user_id'] ) ? $row['user_id'] : null );
		$location = self::positive_int( isset( $row['location_id'] ) ? $row['location_id'] : null );
		$in       = self::parse_utc( isset( $row['clock_in_utc'] ) ? $row['clock_in_utc'] : null );
		if ( null === $id || null === $user_id || null === $location || null === $in ) {
			return null;
		}
		$out = null;
		if ( isset( $row['clock_out_utc'] ) && null !== $row['clock_out_utc'] ) {
			$out = self::parse_utc( $row['clock_out_utc'] );
			if ( null === $out || $out < $in ) {
				return null;
			}
		}
		$timezone = isset( $row['timezone_snapshot'] ) ? (string) $row['timezone_snapshot'] : '';
		if ( 1 !== preg_match( '/^[A-Za-z_]{1,32}(\/[A-Za-z0-9_+-]{1,32}){0,2}$/D', $timezone ) ) {
			return null;
		}
		$source = isset( $row['source'] ) ? (string) $row['source'] : '';
		if ( 1 !== preg_match( '/^[a-z0-9_]{0,32}$/D', $source ) ) {
			return null;
		}
		$scheduled = isset( $row['scheduled_start_local'] ) ? (string) $row['scheduled_start_local'] : '';
		if ( '' !== $scheduled && ! DoughBoss_Growth_Recon_Matcher::valid_hm( $scheduled ) ) {
			$scheduled = '';
		}
		$updated = ( isset( $row['updated_at'] ) && null !== $row['updated_at'] ) ? self::parse_utc( $row['updated_at'] ) : null;
		return array(
			'shift_id'              => $id,
			'user_id'               => $user_id,
			'location_id'           => $location,
			'timezone'              => $timezone,
			'in'                    => $in,
			'out'                   => $out,
			'source'                => $source,
			'late_minutes'          => max( 0, (int) ( isset( $row['late_minutes'] ) ? $row['late_minutes'] : 0 ) ),
			'scheduled_start_local' => $scheduled,
			'updated_at'            => $updated,
			'manager_closed'        => ( 1 === (int) ( isset( $row['manager_closed'] ) ? $row['manager_closed'] : 0 ) ),
			'breaks'                => array(),
		);
	}

	/**
	 * Validate one break row.
	 *
	 * @param array $row Row.
	 * @return array|null
	 */
	private static function normalise_break( array $row ) {
		$id    = self::positive_int( isset( $row['id'] ) ? $row['id'] : null );
		$shift = self::positive_int( isset( $row['shift_id'] ) ? $row['shift_id'] : null );
		$start = self::parse_utc( isset( $row['break_start_utc'] ) ? $row['break_start_utc'] : null );
		if ( null === $id || null === $shift || null === $start ) {
			return null;
		}
		$end = null;
		if ( isset( $row['break_end_utc'] ) && null !== $row['break_end_utc'] ) {
			$end = self::parse_utc( $row['break_end_utc'] );
			if ( null === $end || $end < $start ) {
				return null;
			}
		}
		$source = isset( $row['source'] ) ? (string) $row['source'] : '';
		if ( 1 !== preg_match( '/^[a-z0-9_]{0,32}$/D', $source ) ) {
			return null;
		}
		return array(
			'break_id' => $id,
			'shift_id' => $shift,
			'start'    => $start,
			'end'      => $end,
			'source'   => $source,
		);
	}

	/**
	 * A positive integer from a database value, else null.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private static function positive_int( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/D', $value ) ) {
			return (int) $value;
		}
		return null;
	}
}
