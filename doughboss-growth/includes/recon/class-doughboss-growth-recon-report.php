<?php
/**
 * Timesheet reconciliation: parameters, mappings, run orchestration, stored report and CSV.
 *
 * Owns the companion tables doughboss_growth_recon_run, doughboss_growth_recon_row and
 * doughboss_growth_recon_xref and the option doughboss_growth_recon. It never writes a core table, option,
 * post type, role or capability, and never touches Square except through the read-only client.
 *
 * Run model (03 section 2.9): a run reads the plugin clock and Square for the previous complete business day
 * plus lookback_days, matches, and stores rows. A run is visible only once it finished COMPLETE or
 * INCOMPLETE (INCOMPLETE = some item is UNMAPPED). NOT_RUN (a precondition is missing) and FAILED (an error
 * during the run) keep the previous result on screen and are never shown as a clean result.
 *
 * Stored data is ids and minutes only: no staff name, login, email, wage or free-text reason is written to
 * any recon table, the run record or the CSV. Names are resolved at render time by the admin screen.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reconciliation report.
 */
final class DoughBoss_Growth_Recon_Report {

	/**
	 * Option holding the parameters. Nothing is shipped: every value starts unset.
	 */
	const OPTION = 'doughboss_growth_recon';

	/**
	 * Feature flag.
	 */
	const FEATURE = 'timesheet_recon';

	/**
	 * Numeric parameters and the range accepted when Elie types a value (a value outside the range is
	 * refused, it is never clamped). The ranges only reject nonsense; they are not suggested values.
	 */
	const INT_PARAMS = array(
		'start_tolerance_minutes'  => array( 0, 1440 ),
		'end_tolerance_minutes'    => array( 0, 1440 ),
		'break_tolerance_minutes'  => array( 0, 1440 ),
		'net_tolerance_minutes'    => array( 0, 1440 ),
		'open_alert_after_minutes' => array( 0, 10080 ),
		'max_shift_minutes'        => array( 1, 2880 ),
		'near_miss_search_minutes' => array( 0, 1440 ),
		'lookback_days'            => array( 0, 62 ),
	);

	/**
	 * Run statuses.
	 */
	const STATUS_RUNNING    = 'RUNNING';
	const STATUS_COMPLETE   = 'COMPLETE';
	const STATUS_INCOMPLETE = 'INCOMPLETE';
	const STATUS_FAILED     = 'FAILED';
	const STATUS_NOT_RUN    = 'NOT_RUN';

	/**
	 * A RUNNING run older than this is treated as crashed and marked FAILED (engineering value). It must exceed
	 * the longest possible run: 2 searches x MAX_PAGES x 10 s = 1,000 s of Square time.
	 */
	const STALE_RUN_SECONDS = 1800;

	/**
	 * Margin added on both sides of the fetch window so a counterpart just outside a business day is still
	 * seen (engineering value: one day).
	 */
	const FETCH_MARGIN_SECONDS = 86400;

	/**
	 * Longest manual run, in business days (engineering value).
	 */
	const MAX_SPAN_DAYS = 31;

	/**
	 * Runs whose rows are kept; older rows are deleted after each finished run (engineering value).
	 */
	const RETAIN_RUNS = 30;

	/**
	 * Rows per multi-row INSERT.
	 */
	const INSERT_BATCH = 50;

	/**
	 * Environments as core 2.44.0 names them in doughboss_square_locations.
	 */
	const CORE_ENVIRONMENTS = array(
		'production' => 'live',
		'sandbox'    => 'test',
	);

	/**
	 * Table name helper.
	 *
	 * @param string $suffix run|row|xref.
	 * @return string
	 */
	public static function table( $suffix ) {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_growth_recon_' . $suffix;
	}

	/**
	 * CREATE TABLE statements (dbDelta format).
	 *
	 * @return array
	 */
	public static function schema() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$run     = self::table( 'run' );
		$row     = self::table( 'row' );
		$xref    = self::table( 'xref' );
		return array(
			"CREATE TABLE {$run} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(16) NOT NULL DEFAULT 'RUNNING',
  trigger_type varchar(16) NOT NULL DEFAULT '',
  reason_code varchar(64) NOT NULL DEFAULT '',
  environment varchar(16) NOT NULL DEFAULT '',
  report_from varchar(10) NOT NULL DEFAULT '',
  report_to varchar(10) NOT NULL DEFAULT '',
  window_from_utc datetime NULL DEFAULT NULL,
  window_to_utc datetime NULL DEFAULT NULL,
  params_json longtext NULL,
  counts_json longtext NULL,
  plugin_items int(10) unsigned NOT NULL DEFAULT 0,
  square_items int(10) unsigned NOT NULL DEFAULT 0,
  square_pages int(10) unsigned NOT NULL DEFAULT 0,
  row_count int(10) unsigned NOT NULL DEFAULT 0,
  actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  running_guard tinyint(1) unsigned NULL DEFAULT 1,
  started_at datetime NOT NULL,
  finished_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY running_guard (running_guard),
  KEY status_started (status,started_at)
) {$charset};",
			"CREATE TABLE {$row} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NOT NULL,
  stable_key char(64) NOT NULL,
  content_hash char(64) NOT NULL,
  changed tinyint(1) unsigned NOT NULL DEFAULT 0,
  workday_local varchar(10) NOT NULL,
  plugin_location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  square_location_id varchar(64) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  team_member_id varchar(64) NOT NULL DEFAULT '',
  kind varchar(16) NOT NULL,
  state varchar(16) NOT NULL,
  codes varchar(600) NOT NULL DEFAULT '',
  plugin_ids text NULL,
  square_ids text NULL,
  plugin_start_utc datetime NULL DEFAULT NULL,
  plugin_end_utc datetime NULL DEFAULT NULL,
  square_start_utc datetime NULL DEFAULT NULL,
  square_end_utc datetime NULL DEFAULT NULL,
  start_delta_min int(11) NULL DEFAULT NULL,
  end_delta_min int(11) NULL DEFAULT NULL,
  break_delta_min int(11) NULL DEFAULT NULL,
  plugin_break_min int(11) NULL DEFAULT NULL,
  square_break_all_min int(11) NULL DEFAULT NULL,
  square_break_unpaid_min int(11) NULL DEFAULT NULL,
  plugin_net_min int(11) NULL DEFAULT NULL,
  square_net_all_min int(11) NULL DEFAULT NULL,
  square_net_unpaid_min int(11) NULL DEFAULT NULL,
  net_delta_min int(11) NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY run_stable (run_id,stable_key),
  KEY run_state (run_id,state),
  KEY run_day_shop (run_id,workday_local,plugin_location_id)
) {$charset};",
			"CREATE TABLE {$xref} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(16) NOT NULL,
  environment varchar(16) NOT NULL,
  local_id bigint(20) unsigned NOT NULL,
  square_id varchar(64) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'confirmed',
  active_guard tinyint(1) unsigned NULL DEFAULT 1,
  confirmed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  confirmed_at datetime NULL DEFAULT NULL,
  revoked_by bigint(20) unsigned NOT NULL DEFAULT 0,
  revoked_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY local_active (kind,environment,local_id,active_guard),
  UNIQUE KEY square_active (kind,environment,square_id,active_guard),
  KEY kind_env_status (kind,environment,status)
) {$charset};",
		);
	}

	/**
	 * Whether the three recon tables exist (a manager's admin visit creates any that are missing).
	 *
	 * @return bool
	 */
	public static function tables_ready() {
		global $wpdb;
		foreach ( array( 'run', 'row', 'xref' ) as $suffix ) {
			$table = self::table( $suffix );
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( 0 !== strcasecmp( (string) $found, $table ) ) {
				return false;
			}
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Parameters                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Unset parameters: every number null, no run time, no cutoffs.
	 *
	 * @return array
	 */
	public static function default_params() {
		$out = array();
		foreach ( array_keys( self::INT_PARAMS ) as $key ) {
			$out[ $key ] = null;
		}
		$out['run_time_local']            = null;
		$out['business_day_cutoff_local'] = array();
		return $out;
	}

	/**
	 * Sanitise stored or submitted parameters. Anything invalid becomes unset (never a guessed value).
	 *
	 * @param mixed $raw Raw value.
	 * @return array
	 */
	public static function sanitize_params( $raw ) {
		$out = self::default_params();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( self::INT_PARAMS as $key => $range ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			$value = $raw[ $key ];
			if ( is_string( $value ) ) {
				$value = trim( $value );
				if ( 1 === preg_match( '/^[0-9]{1,6}$/D', $value ) ) {
					$value = (int) $value;
				}
			}
			if ( is_int( $value ) && $value >= $range[0] && $value <= $range[1] ) {
				$out[ $key ] = $value;
			}
		}
		if ( isset( $raw['run_time_local'] ) && is_string( $raw['run_time_local'] ) && DoughBoss_Growth_Recon_Matcher::valid_hm( trim( $raw['run_time_local'] ) ) ) {
			$out['run_time_local'] = trim( $raw['run_time_local'] );
		}
		if ( isset( $raw['business_day_cutoff_local'] ) && is_array( $raw['business_day_cutoff_local'] ) ) {
			foreach ( $raw['business_day_cutoff_local'] as $shop => $hm ) {
				$shop = is_int( $shop ) ? $shop : ( ( is_string( $shop ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/D', $shop ) ) ? (int) $shop : 0 );
				if ( $shop > 0 && is_string( $hm ) && DoughBoss_Growth_Recon_Matcher::valid_hm( trim( $hm ) ) ) {
					$out['business_day_cutoff_local'][ $shop ] = trim( $hm );
				}
			}
			ksort( $out['business_day_cutoff_local'] );
		}
		return $out;
	}

	/**
	 * Current parameters (sanitised on read).
	 *
	 * @return array
	 */
	public static function params() {
		return self::sanitize_params( get_option( self::OPTION, array() ) );
	}

	/**
	 * Save parameters. Returns the stored value, or false when it did not persist. update_option() returns
	 * false for an unchanged value as well as for a failed write, so success is confirmed by reading back
	 * (as DoughBoss_Growth_Settings::apply_save() does).
	 *
	 * @param mixed $raw Raw input.
	 * @return array|false
	 */
	public static function save_params( $raw ) {
		$clean = self::sanitize_params( $raw );
		update_option( self::OPTION, $clean, false );
		return ( self::params() === $clean ) ? $clean : false;
	}

	/**
	 * Parameters that are unset, with the [CONFIRM] text shown in admin.
	 *
	 * @param array $shops Plugin location ids whose cutoff should be set.
	 * @return array Key => text.
	 */
	public static function unset_params( array $shops = array() ) {
		$params = self::params();
		$labels = array(
			'start_tolerance_minutes'  => 'start time tolerance (minutes)',
			'end_tolerance_minutes'    => 'finish time tolerance (minutes)',
			'break_tolerance_minutes'  => 'break total tolerance (minutes)',
			'net_tolerance_minutes'    => 'net worked time tolerance (minutes)',
			'open_alert_after_minutes' => 'how long a shift may stay open before it is flagged (minutes)',
			'max_shift_minutes'        => 'longest normal shift (minutes)',
			'near_miss_search_minutes' => 'how far apart two unmatched records may be and still be linked as one shift (minutes)',
			'lookback_days'            => 'how many earlier days each run re-checks (until set, only the previous business day)',
			'run_time_local'           => 'daily run time, Sydney time (until set, there is no automatic run)',
		);
		$out = array();
		foreach ( $labels as $key => $label ) {
			if ( null !== $params[ $key ] ) {
				continue;
			}
			if ( 'run_time_local' === $key || 'lookback_days' === $key ) {
				$out[ $key ] = '[CONFIRM: ' . $label . '.]';
			} else {
				$out[ $key ] = '[CONFIRM: ' . $label . '. Until Elie sets it, this check is shown as UNRATED and never as matched.]';
			}
		}
		foreach ( $shops as $shop ) {
			if ( ! isset( $params['business_day_cutoff_local'][ (int) $shop ] ) ) {
				$out[ 'cutoff_' . (int) $shop ] = '[CONFIRM: business-day cutoff for shop #' . (int) $shop . ' (Sydney time). Until set, a shift belongs to the calendar date it starts on.]';
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Mappings (doughboss_growth_recon_xref)                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Confirmed mappings of one kind in one environment.
	 *
	 * @param string $kind        employee|location.
	 * @param string $environment production|sandbox.
	 * @return array|null array( local_id => square_id ), or null on a read error. A local id or Square id that
	 *                    appears twice (should be impossible under the unique keys) is dropped, fail closed.
	 */
	public static function mappings( $kind, $environment ) {
		global $wpdb;
		if ( ! in_array( $kind, array( 'employee', 'location' ), true ) || ! array_key_exists( $environment, self::CORE_ENVIRONMENTS ) ) {
			return array();
		}
		$table = self::table( 'xref' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT local_id, square_id FROM {$table} WHERE kind = %s AND environment = %s AND status = %s AND active_guard = 1 ORDER BY local_id ASC, id ASC", $kind, $environment, 'confirmed' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return null;
		}
		$by_local  = array();
		$by_square = array();
		foreach ( $rows as $row ) {
			$local  = (int) $row['local_id'];
			$square = (string) $row['square_id'];
			if ( $local < 1 || ! DoughBoss_Growth_Recon_Square::valid_id( $square ) ) {
				continue;
			}
			$by_local[ $local ][]   = $square;
			$by_square[ $square ][] = $local;
		}
		$out = array();
		foreach ( $by_local as $local => $squares ) {
			if ( 1 === count( $squares ) && 1 === count( $by_square[ $squares[0] ] ) ) {
				$out[ $local ] = $squares[0];
			}
		}
		return $out;
	}

	/**
	 * Confirm a mapping (one-to-one per kind and environment; a conflicting active mapping is refused).
	 *
	 * @param string $kind        employee|location.
	 * @param string $environment production|sandbox.
	 * @param int    $local_id    WordPress user id or plugin location id.
	 * @param string $square_id   Square team member id or location id.
	 * @param int    $actor       Confirming user id.
	 * @return string confirmed|exists|conflict|invalid|error
	 */
	public static function confirm_mapping( $kind, $environment, $local_id, $square_id, $actor ) {
		global $wpdb;
		$local_id = (int) $local_id;
		if ( ! in_array( $kind, array( 'employee', 'location' ), true ) || ! array_key_exists( $environment, self::CORE_ENVIRONMENTS ) || $local_id < 1 || ! DoughBoss_Growth_Recon_Square::valid_id( $square_id ) ) {
			return 'invalid';
		}
		$current = self::mappings( $kind, $environment );
		if ( null === $current ) {
			return 'error';
		}
		if ( isset( $current[ $local_id ] ) && $current[ $local_id ] === $square_id ) {
			return 'exists';
		}
		$table = self::table( 'xref' );
		$taken = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE kind = %s AND environment = %s AND active_guard = 1 AND ( local_id = %d OR square_id = %s )", $kind, $environment, $local_id, $square_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return 'error';
		}
		if ( (int) $taken > 0 ) {
			return 'conflict';
		}
		$now      = DoughBoss_Growth_Recon_Reader::mysql_utc( DoughBoss_Growth::now() );
		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare( "INSERT INTO {$table} (kind, environment, local_id, square_id, status, active_guard, confirmed_by, confirmed_at) VALUES (%s, %s, %d, %s, %s, 1, %d, %s)", $kind, $environment, $local_id, $square_id, 'confirmed', (int) $actor, $now ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( 1 !== $inserted ) {
			// Only a concurrent confirmation hitting the unique key is a conflict; any other failure is an error.
			return self::is_duplicate_key_error() ? 'conflict' : 'error';
		}
		return 'confirmed';
	}

	/**
	 * Whether the last statement failed on a unique key (MySQL/MariaDB "Duplicate entry", SQLite "UNIQUE
	 * constraint failed"), as opposed to any other storage error.
	 *
	 * @return bool
	 */
	private static function is_duplicate_key_error() {
		global $wpdb;
		$error = (string) $wpdb->last_error;
		return false !== stripos( $error, 'duplicate entry' ) || false !== stripos( $error, 'unique constraint failed' );
	}

	/**
	 * Revoke an active mapping (kept as history with active_guard NULL).
	 *
	 * @param string $kind        employee|location.
	 * @param string $environment production|sandbox.
	 * @param int    $local_id    Local id.
	 * @param int    $actor       Revoking user id.
	 * @return string revoked|absent|invalid|error
	 */
	public static function revoke_mapping( $kind, $environment, $local_id, $actor ) {
		global $wpdb;
		$local_id = (int) $local_id;
		if ( ! in_array( $kind, array( 'employee', 'location' ), true ) || ! array_key_exists( $environment, self::CORE_ENVIRONMENTS ) || $local_id < 1 ) {
			return 'invalid';
		}
		$table   = self::table( 'xref' );
		$now     = DoughBoss_Growth_Recon_Reader::mysql_utc( DoughBoss_Growth::now() );
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare( "UPDATE {$table} SET status = %s, active_guard = NULL, revoked_by = %d, revoked_at = %s WHERE kind = %s AND environment = %s AND local_id = %d AND active_guard = 1", 'revoked', (int) $actor, $now, $kind, $environment, $local_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( false === $updated ) {
			return 'error';
		}
		return ( (int) $updated > 0 ) ? 'revoked' : 'absent';
	}

	/**
	 * Location map to use for a run: the companion's confirmed rows, overridden by core 2.44.0's verified
	 * doughboss_square_locations rows when that table exists (core is authoritative). A shop where the two
	 * disagree is blocked (UNMAPPED_LOCATION) until resolved.
	 *
	 * @param string $environment production|sandbox.
	 * @return array|WP_Error { locations: array( plugin_id => square_id ), blocked: array( plugin_id => reason ), core: bool }
	 */
	public static function effective_locations( $environment ) {
		global $wpdb;
		$companion = self::mappings( 'location', $environment );
		if ( null === $companion ) {
			return new WP_Error( 'storage_read_failed', 'The location mappings could not be read.' );
		}
		$out = array(
			'locations' => $companion,
			'blocked'   => array(),
			'core'      => false,
		);
		$core_version = defined( 'DOUGHBOSS_VERSION' ) ? (string) constant( 'DOUGHBOSS_VERSION' ) : '';
		if ( '' === $core_version || version_compare( $core_version, '2.44.0', '<' ) ) {
			return $out;
		}
		$table = $wpdb->prefix . 'doughboss_square_locations';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( '' !== (string) $wpdb->last_error || 0 !== strcasecmp( (string) $found, $table ) ) {
			return new WP_Error( 'core_location_map_missing', 'Core 2.44.0 is active but its Square location table cannot be read.' );
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT wp_location_id, square_location_id FROM {$table} WHERE environment = %s AND status = %s ORDER BY wp_location_id ASC", self::CORE_ENVIRONMENTS[ $environment ], 'verified' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'core_location_map_unreadable', 'Core 2.44.0 Square location rows cannot be read.' );
		}
		$out['core'] = true;
		$core        = array();
		foreach ( $rows as $row ) {
			$shop   = (int) $row['wp_location_id'];
			$square = (string) $row['square_location_id'];
			if ( $shop < 1 || ! DoughBoss_Growth_Recon_Square::valid_id( $square ) ) {
				continue;
			}
			if ( isset( $core[ $shop ] ) && $core[ $shop ] !== $square ) {
				$out['blocked'][ $shop ] = 'core_map_ambiguous';
			}
			$core[ $shop ] = $square;
		}
		foreach ( $core as $shop => $square ) {
			if ( isset( $companion[ $shop ] ) && $companion[ $shop ] !== $square ) {
				$out['blocked'][ $shop ] = 'core_map_disagrees';
			}
			$out['locations'][ $shop ] = $square;
		}
		// A Square location claimed by two shops cannot be attributed: block both.
		$claims = array();
		foreach ( $out['locations'] as $shop => $square ) {
			$claims[ $square ][] = $shop;
		}
		foreach ( $claims as $shops ) {
			if ( count( $shops ) > 1 ) {
				foreach ( $shops as $shop ) {
					$out['blocked'][ $shop ] = 'square_location_shared';
				}
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Business days                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * The business days a scheduled run reports: the previous COMPLETE business day for every shop, plus
	 * lookback_days earlier days.
	 *
	 * @param int   $now    Run time.
	 * @param array $params Parameters.
	 * @param array $shops  Plugin location ids (for their cutoffs).
	 * @return array { from: Y-m-d, to: Y-m-d }
	 */
	public static function default_days( $now, array $params, array $shops ) {
		$current = DoughBoss_Growth_Recon_Matcher::business_date( $now, null );
		foreach ( $shops as $shop ) {
			$cutoff = isset( $params['business_day_cutoff_local'][ (int) $shop ] ) ? $params['business_day_cutoff_local'][ (int) $shop ] : null;
			$date   = DoughBoss_Growth_Recon_Matcher::business_date( $now, $cutoff );
			if ( strcmp( $date, $current ) < 0 ) {
				$current = $date;
			}
		}
		$to       = DoughBoss_Growth_Recon_Matcher::add_days( $current, -1 );
		$lookback = ( null === $params['lookback_days'] ) ? 0 : (int) $params['lookback_days'];
		return array(
			'from' => DoughBoss_Growth_Recon_Matcher::add_days( $to, -$lookback ),
			'to'   => $to,
		);
	}

	/**
	 * UTC fetch window covering business days $from..$to for any cutoff, plus the margin.
	 *
	 * @param string $from   First business day.
	 * @param string $to     Last business day.
	 * @param array  $params Parameters.
	 * @return array|null { from: int, to: int }
	 */
	public static function fetch_window( $from, $to, array $params ) {
		$start = DoughBoss_Growth_Recon_Matcher::local_to_utc( $from, '00:00' );
		$end   = DoughBoss_Growth_Recon_Matcher::local_to_utc( DoughBoss_Growth_Recon_Matcher::add_days( $to, 2 ), '00:00' );
		if ( null === $start || null === $end ) {
			return null;
		}
		$margin = self::FETCH_MARGIN_SECONDS;
		if ( null !== $params['near_miss_search_minutes'] ) {
			$margin += 60 * (int) $params['near_miss_search_minutes'];
		}
		return array(
			'from' => $start - $margin,
			'to'   => $end + $margin,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Runs                                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Run a reconciliation.
	 *
	 * @param string      $trigger cron|manual.
	 * @param string|null $from    First business day (manual runs), Y-m-d.
	 * @param string|null $to      Last business day (manual runs), Y-m-d.
	 * @param int         $actor   User id who asked (0 for cron).
	 * @return array { status: string, reason: string, run_id: int }
	 */
	public static function run( $trigger, $from = null, $to = null, $actor = 0 ) {
		$trigger = ( 'cron' === $trigger ) ? 'cron' : 'manual';
		if ( ! DoughBoss_Growth_Settings::enabled( self::FEATURE ) ) {
			return self::result( self::STATUS_NOT_RUN, 'feature_off', 0 );
		}
		if ( ! DoughBoss_Growth_Activator::storage_ready() || ! self::tables_ready() ) {
			return self::result( self::STATUS_NOT_RUN, 'companion_storage_not_ready', 0 );
		}

		$run_id = self::start_run( $trigger, (int) $actor );
		if ( $run_id < 1 ) {
			return self::result( self::STATUS_NOT_RUN, -1 === $run_id ? 'run_in_progress' : 'storage_write_failed', 0 );
		}

		try {
			$outcome = self::execute( $run_id, $from, $to );
		} catch ( Throwable $e ) {
			$outcome = array(
				'status' => self::STATUS_FAILED,
				'reason' => 'internal_error',
			);
			DoughBoss_Growth_Http::log( 'recon_failed', array( 'error' => get_class( $e ) ) );
		}
		if ( self::STATUS_COMPLETE !== $outcome['status'] && self::STATUS_INCOMPLETE !== $outcome['status'] ) {
			self::delete_rows( $run_id );
			self::finish_run( $run_id, $outcome['status'], $outcome['reason'], array() );
		}
		DoughBoss_Growth_Http::log(
			'recon_run',
			array(
				'run'    => $run_id,
				'status' => $outcome['status'],
				'reason' => $outcome['reason'],
			)
		);
		do_action( 'doughboss_growth_recon_completed', $run_id, $outcome['status'] );
		return self::result( $outcome['status'], $outcome['reason'], $run_id );
	}

	/**
	 * The body of a run once the lock is held. Returns status and reason; on success the run row is already
	 * finished.
	 *
	 * @param int         $run_id Run id.
	 * @param string|null $from   First business day.
	 * @param string|null $to     Last business day.
	 * @return array { status, reason }
	 */
	private static function execute( $run_id, $from, $to ) {
		$now    = DoughBoss_Growth::now();
		$params = self::params();

		$guard = DoughBoss_Growth_Recon_Reader::guard();
		if ( '' !== $guard ) {
			return array(
				'status' => self::STATUS_NOT_RUN,
				'reason' => $guard,
			);
		}
		$square_reason = DoughBoss_Growth_Recon_Square::not_ready_reason();
		if ( '' !== $square_reason ) {
			return array(
				'status' => self::STATUS_NOT_RUN,
				'reason' => $square_reason,
			);
		}
		$environment = DoughBoss_Growth_Recon_Square::environment();
		$locations   = self::effective_locations( $environment );
		if ( is_wp_error( $locations ) ) {
			return array(
				'status' => self::STATUS_FAILED,
				'reason' => $locations->get_error_code(),
			);
		}
		$usable = array_diff_key( $locations['locations'], $locations['blocked'] );
		if ( array() === $usable ) {
			return array(
				'status' => self::STATUS_NOT_RUN,
				'reason' => 'no_location_mapping',
			);
		}
		$employees = self::mappings( 'employee', $environment );
		if ( null === $employees ) {
			return array(
				'status' => self::STATUS_FAILED,
				'reason' => 'storage_read_failed',
			);
		}

		if ( null === $from && null === $to ) {
			$days = self::default_days( $now, $params, array_keys( $locations['locations'] ) );
		} else {
			$days = self::manual_days( $from, $to, $now );
			if ( null === $days ) {
				return array(
					'status' => self::STATUS_NOT_RUN,
					'reason' => 'invalid_dates',
				);
			}
		}
		$window = self::fetch_window( $days['from'], $days['to'], $params );
		if ( null === $window ) {
			return array(
				'status' => self::STATUS_FAILED,
				'reason' => 'timezone_error',
			);
		}
		self::update_run_window( $run_id, $environment, $days, $window, $params );

		$shifts = DoughBoss_Growth_Recon_Reader::read( $window['from'], $window['to'], $now );
		if ( is_wp_error( $shifts ) ) {
			return array(
				'status' => 0 === strpos( $shifts->get_error_code(), 'plugin_storage' ) || 0 === strpos( $shifts->get_error_code(), 'plugin_timeclock' ) ? self::STATUS_NOT_RUN : self::STATUS_FAILED,
				'reason' => $shifts->get_error_code(),
			);
		}
		$cards = DoughBoss_Growth_Recon_Square::search_timecards( array_values( $usable ), $window['from'], $window['to'] );
		if ( is_wp_error( $cards ) ) {
			return array(
				'status' => self::STATUS_FAILED,
				'reason' => $cards->get_error_code(),
			);
		}

		$rows = DoughBoss_Growth_Recon_Matcher::match(
			array(
				'now'               => $now,
				'report_from'       => $days['from'],
				'report_to'         => $days['to'],
				'params'            => $params,
				'employees'         => $employees,
				'locations'         => $locations['locations'],
				'blocked_locations' => $locations['blocked'],
				'shifts'            => $shifts,
				'timecards'         => $cards['timecards'],
			)
		);
		$rows = self::mark_changes( $rows, $run_id );
		if ( null === $rows ) {
			return array(
				'status' => self::STATUS_FAILED,
				'reason' => 'storage_read_failed',
			);
		}
		if ( ! self::insert_rows( $run_id, $rows ) ) {
			return array(
				'status' => self::STATUS_FAILED,
				'reason' => 'storage_write_failed',
			);
		}

		$summary = DoughBoss_Growth_Recon_Matcher::summarise( $rows );
		$status  = ( $summary['total']['UNMAPPED'] > 0 ) ? self::STATUS_INCOMPLETE : self::STATUS_COMPLETE;
		$stats   = array(
			'counts'       => $summary,
			'plugin_items' => count( $shifts ),
			'square_items' => count( $cards['timecards'] ),
			'square_pages' => (int) $cards['pages'],
			'row_count'    => count( $rows ),
		);
		if ( ! self::finish_run( $run_id, $status, self::STATUS_INCOMPLETE === $status ? 'unmapped_items' : '', $stats ) ) {
			return array(
				'status' => self::STATUS_FAILED,
				'reason' => 'storage_write_failed',
			);
		}
		self::prune();
		return array(
			'status' => $status,
			'reason' => self::STATUS_INCOMPLETE === $status ? 'unmapped_items' : '',
		);
	}

	/**
	 * Validate a manual date range.
	 *
	 * @param mixed $from First business day.
	 * @param mixed $to   Last business day.
	 * @param int   $now  Run time.
	 * @return array|null
	 */
	public static function manual_days( $from, $to, $now ) {
		if ( ! DoughBoss_Growth_Recon_Matcher::valid_date( $from ) || ! DoughBoss_Growth_Recon_Matcher::valid_date( $to ) ) {
			return null;
		}
		$today = DoughBoss_Growth_Recon_Matcher::business_date( $now, null );
		if ( strcmp( $from, $to ) > 0 || strcmp( $to, $today ) > 0 ) {
			return null;
		}
		if ( strcmp( DoughBoss_Growth_Recon_Matcher::add_days( $from, self::MAX_SPAN_DAYS - 1 ), $to ) < 0 ) {
			return null;
		}
		return array(
			'from' => $from,
			'to'   => $to,
		);
	}

	/**
	 * Result array.
	 *
	 * @param string $status Status.
	 * @param string $reason Reason code.
	 * @param int    $run_id Run id.
	 * @return array
	 */
	private static function result( $status, $reason, $run_id ) {
		return array(
			'status' => $status,
			'reason' => (string) $reason,
			'run_id' => (int) $run_id,
		);
	}

	/**
	 * Take the run lock by inserting a RUNNING row (UNIQUE running_guard). A RUNNING row older than
	 * STALE_RUN_SECONDS is marked FAILED first.
	 *
	 * @param string $trigger cron|manual.
	 * @param int    $actor   User id.
	 * @return int Run id, -1 when another run holds the lock, 0 on a storage error.
	 */
	private static function start_run( $trigger, $actor ) {
		global $wpdb;
		$table = self::table( 'run' );
		$now   = DoughBoss_Growth::now();
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare( "INSERT INTO {$table} (status, trigger_type, actor_user_id, running_guard, started_at) VALUES (%s, %s, %d, 1, %s)", self::STATUS_RUNNING, $trigger, $actor, DoughBoss_Growth_Recon_Reader::mysql_utc( $now ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			if ( 1 === $inserted ) {
				return (int) $wpdb->insert_id;
			}
			if ( ! self::is_duplicate_key_error() ) {
				// Not the unique guard: a storage failure must not be reported as another run holding the lock.
				return 0;
			}
			$stale = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare( "UPDATE {$table} SET status = %s, reason_code = %s, running_guard = NULL, finished_at = %s WHERE running_guard = 1 AND status = %s AND started_at < %s", self::STATUS_FAILED, 'stale_run', DoughBoss_Growth_Recon_Reader::mysql_utc( $now ), self::STATUS_RUNNING, DoughBoss_Growth_Recon_Reader::mysql_utc( $now - self::STALE_RUN_SECONDS ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			if ( false === $stale ) {
				return 0;
			}
			if ( 0 === (int) $stale ) {
				return -1;
			}
		}
		return -1;
	}

	/**
	 * Record the window and parameters a run used (numbers and dates only).
	 *
	 * @param int    $run_id      Run id.
	 * @param string $environment Square environment.
	 * @param array  $days        Business days.
	 * @param array  $window      UTC window.
	 * @param array  $params      Parameters.
	 * @return void
	 */
	private static function update_run_window( $run_id, $environment, array $days, array $window, array $params ) {
		global $wpdb;
		$table = self::table( 'run' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare( "UPDATE {$table} SET environment = %s, report_from = %s, report_to = %s, window_from_utc = %s, window_to_utc = %s, params_json = %s WHERE id = %d AND status = %s", $environment, $days['from'], $days['to'], DoughBoss_Growth_Recon_Reader::mysql_utc( $window['from'] ), DoughBoss_Growth_Recon_Reader::mysql_utc( $window['to'] ), (string) wp_json_encode( $params ), $run_id, self::STATUS_RUNNING ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Finish a run and release the lock.
	 *
	 * @param int    $run_id Run id.
	 * @param string $status Final status.
	 * @param string $reason Reason code.
	 * @param array  $stats  Counts.
	 * @return bool
	 */
	private static function finish_run( $run_id, $status, $reason, array $stats ) {
		global $wpdb;
		$table   = self::table( 'run' );
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, reason_code = %s, counts_json = %s, plugin_items = %d, square_items = %d, square_pages = %d, row_count = %d, running_guard = NULL, finished_at = %s WHERE id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$status,
				substr( (string) $reason, 0, 64 ),
				isset( $stats['counts'] ) ? (string) wp_json_encode( $stats['counts'] ) : '',
				isset( $stats['plugin_items'] ) ? (int) $stats['plugin_items'] : 0,
				isset( $stats['square_items'] ) ? (int) $stats['square_items'] : 0,
				isset( $stats['square_pages'] ) ? (int) $stats['square_pages'] : 0,
				isset( $stats['row_count'] ) ? (int) $stats['row_count'] : 0,
				DoughBoss_Growth_Recon_Reader::mysql_utc( DoughBoss_Growth::now() ),
				$run_id,
				self::STATUS_RUNNING
			)
		);
		return 1 === $updated;
	}

	/**
	 * Flag rows whose sources changed since the last shown run (a later edit on either side re-opens a row).
	 *
	 * @param array $rows   Rows.
	 * @param int   $run_id This run.
	 * @return array|null Rows with "changed", or null on a read error.
	 */
	private static function mark_changes( array $rows, $run_id ) {
		global $wpdb;
		$previous = self::latest_run( $run_id );
		if ( false === $previous ) {
			return null;
		}
		$known = array();
		if ( null !== $previous ) {
			$table  = self::table( 'row' );
			$stored = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "SELECT stable_key, content_hash FROM {$table} WHERE run_id = %d", (int) $previous['id'] ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			if ( '' !== (string) $wpdb->last_error || ! is_array( $stored ) ) {
				return null;
			}
			foreach ( $stored as $row ) {
				$known[ (string) $row['stable_key'] ] = (string) $row['content_hash'];
			}
		}
		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['changed'] = ( isset( $known[ $row['stable_key'] ] ) && $known[ $row['stable_key'] ] !== $row['content_hash'] );
		}
		return $rows;
	}

	/**
	 * Store rows with multi-row INSERTs. Rows stay invisible until the run is finished.
	 *
	 * @param int   $run_id Run id.
	 * @param array $rows   Rows.
	 * @return bool
	 */
	private static function insert_rows( $run_id, array $rows ) {
		global $wpdb;
		$table   = self::table( 'row' );
		$columns = 'run_id, stable_key, content_hash, changed, workday_local, plugin_location_id, square_location_id, user_id, team_member_id, kind, state, codes, plugin_ids, square_ids, plugin_start_utc, plugin_end_utc, square_start_utc, square_end_utc, start_delta_min, end_delta_min, break_delta_min, plugin_break_min, square_break_all_min, square_break_unpaid_min, plugin_net_min, square_net_all_min, square_net_unpaid_min, net_delta_min';
		foreach ( array_chunk( $rows, self::INSERT_BATCH ) as $batch ) {
			$values = array();
			foreach ( $batch as $row ) {
				$formats = array( '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' );
				$args    = array(
					(int) $run_id,
					(string) $row['stable_key'],
					(string) $row['content_hash'],
					! empty( $row['changed'] ) ? 1 : 0,
					(string) $row['workday_local'],
					(int) $row['plugin_location_id'],
					(string) $row['square_location_id'],
					(int) $row['user_id'],
					(string) $row['team_member_id'],
					(string) $row['kind'],
					(string) $row['state'],
					implode( ',', $row['codes'] ),
					implode( ',', $row['plugin_ids'] ),
					implode( ',', $row['square_ids'] ),
				);
				// wpdb::prepare() has no NULL placeholder: a missing value is written as the literal NULL.
				foreach ( array( 'plugin_start', 'plugin_end', 'square_start', 'square_end' ) as $key ) {
					if ( null === $row[ $key ] ) {
						$formats[] = 'NULL';
					} else {
						$formats[] = '%s';
						$args[]    = DoughBoss_Growth_Recon_Reader::mysql_utc( (int) $row[ $key ] );
					}
				}
				foreach ( array( 'start_delta', 'end_delta', 'break_delta', 'plugin_break', 'square_break_all', 'square_break_unpaid', 'plugin_net', 'square_net_all', 'square_net_unpaid', 'net_delta' ) as $key ) {
					if ( null === $row[ $key ] ) {
						$formats[] = 'NULL';
					} else {
						$formats[] = '%d';
						$args[]    = (int) $row[ $key ];
					}
				}
				$values[] = $wpdb->prepare( '(' . implode( ', ', $formats ) . ')', $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- formats are fixed placeholders or the literal NULL.
			}
			$inserted = $wpdb->query( "INSERT INTO {$table} ({$columns}) VALUES " . implode( ', ', $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- every tuple was prepared above.
			if ( count( $batch ) !== $inserted ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Delete a run's rows (failed runs leave none behind).
	 *
	 * @param int $run_id Run id.
	 * @return void
	 */
	private static function delete_rows( $run_id ) {
		global $wpdb;
		$table = self::table( 'row' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE run_id = %d", (int) $run_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Keep the rows of the newest RETAIN_RUNS finished runs only.
	 *
	 * @return void
	 */
	private static function prune() {
		global $wpdb;
		$runs = self::table( 'run' );
		$rows = self::table( 'row' );
		$keep = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id FROM {$runs} WHERE status IN (%s, %s) ORDER BY id DESC LIMIT %d", self::STATUS_COMPLETE, self::STATUS_INCOMPLETE, self::RETAIN_RUNS ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $keep ) || count( $keep ) < self::RETAIN_RUNS ) {
			return;
		}
		$oldest = min( array_map( 'intval', $keep ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$rows} WHERE run_id < %d", $oldest ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ------------------------------------------------------------------ */
	/* Reading the report                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * The newest finished (COMPLETE or INCOMPLETE) run, optionally before a given run id.
	 *
	 * @param int $before Only runs with a smaller id (0 = any).
	 * @return array|null|false Run row, null when none, false on a read error.
	 */
	public static function latest_run( $before = 0 ) {
		global $wpdb;
		$table = self::table( 'run' );
		$sql   = "SELECT * FROM {$table} WHERE status IN (%s, %s)";
		$args  = array( self::STATUS_COMPLETE, self::STATUS_INCOMPLETE );
		if ( (int) $before > 0 ) {
			$sql   .= ' AND id < %d';
			$args[] = (int) $before;
		}
		$sql .= ' ORDER BY id DESC LIMIT 1';
		$row  = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * The newest run of any status (for the "last attempt" banner).
	 *
	 * @return array|null|false Run row, null when none, false on a read error.
	 */
	public static function last_attempt() {
		global $wpdb;
		$table = self::table( 'run' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * One finished run by id.
	 *
	 * @param int $run_id Run id.
	 * @return array|null|false Run row, null when it is not a finished run, false on a read error.
	 */
	public static function finished_run( $run_id ) {
		global $wpdb;
		$table = self::table( 'run' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND status IN (%s, %s)", (int) $run_id, self::STATUS_COMPLETE, self::STATUS_INCOMPLETE ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Rows of one run, optionally filtered by state.
	 *
	 * @param int    $run_id Run id.
	 * @param string $state  State or "".
	 * @param int    $limit  Max rows.
	 * @return array|null Rows (an empty list when the run has none), or null on a read error.
	 */
	public static function rows( $run_id, $state = '', $limit = 5000 ) {
		global $wpdb;
		$table = self::table( 'row' );
		$sql   = "SELECT * FROM {$table} WHERE run_id = %d";
		$args  = array( (int) $run_id );
		if ( in_array( $state, DoughBoss_Growth_Recon_Matcher::STATES, true ) ) {
			$sql   .= ' AND state = %s';
			$args[] = $state;
		}
		$sql   .= ' ORDER BY workday_local ASC, plugin_location_id ASC, user_id ASC, id ASC LIMIT %d';
		$args[] = max( 1, min( 50000, (int) $limit ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return ( '' === (string) $wpdb->last_error && is_array( $rows ) ) ? $rows : null;
	}

	/**
	 * CSV columns (ids, codes and minutes only).
	 *
	 * @return array
	 */
	public static function csv_columns() {
		return array( 'run_id', 'workday_local', 'plugin_location_id', 'square_location_id', 'user_id', 'team_member_id', 'kind', 'state', 'codes', 'plugin_shift_ids', 'square_timecard_ids', 'plugin_start_utc', 'plugin_end_utc', 'square_start_utc', 'square_end_utc', 'start_delta_min', 'end_delta_min', 'break_delta_min', 'plugin_break_min', 'square_break_all_min', 'square_break_unpaid_min', 'plugin_net_min', 'square_net_all_min', 'square_net_unpaid_min', 'net_delta_min', 'changed_since_previous_run' );
	}

	/**
	 * CSV of one finished run. No names, logins, emails or free text: every cell is an id, a code, a UTC
	 * datetime or a number. Formula-like cells are neutralised anyway.
	 *
	 * @param int $run_id Run id.
	 * @return string|null|false Null when the run is not a finished run; false when the run or its rows could
	 *                           not be read (never a header-only file standing in for rows that were not read).
	 */
	public static function csv( $run_id ) {
		$run = self::finished_run( $run_id );
		if ( ! is_array( $run ) ) {
			return $run;
		}
		$rows = self::rows( $run_id, '', 50000 );
		if ( null === $rows ) {
			return false;
		}
		$handle = fopen( 'php://temp', 'w+' );
		if ( false === $handle ) {
			return null;
		}
		fputcsv( $handle, self::csv_columns(), ',', '"', '\\' );
		foreach ( $rows as $row ) {
			$cells = array(
				$row['run_id'],
				$row['workday_local'],
				$row['plugin_location_id'],
				$row['square_location_id'],
				$row['user_id'],
				$row['team_member_id'],
				$row['kind'],
				$row['state'],
				$row['codes'],
				$row['plugin_ids'],
				$row['square_ids'],
				$row['plugin_start_utc'],
				$row['plugin_end_utc'],
				$row['square_start_utc'],
				$row['square_end_utc'],
				$row['start_delta_min'],
				$row['end_delta_min'],
				$row['break_delta_min'],
				$row['plugin_break_min'],
				$row['square_break_all_min'],
				$row['square_break_unpaid_min'],
				$row['plugin_net_min'],
				$row['square_net_all_min'],
				$row['square_net_unpaid_min'],
				$row['net_delta_min'],
				$row['changed'],
			);
			fputcsv( $handle, array_map( array( __CLASS__, 'csv_cell' ), $cells ), ',', '"', '\\' );
		}
		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );
		return is_string( $csv ) ? $csv : null;
	}

	/**
	 * Neutralise spreadsheet formulas (same rule as core's timesheet CSV), leaving plain integers intact.
	 *
	 * @param mixed $value Cell.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = str_replace( array( "\r", "\n", "\t" ), ' ', (string) $value );
		if ( 1 === preg_match( '/^-?[0-9]+$/D', $value ) ) {
			return $value;
		}
		if ( 1 === preg_match( '/^\s*[=+\-@]/', $value ) ) {
			$value = "'" . $value;
		}
		return $value;
	}
}
