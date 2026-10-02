<?php
/**
 * Timesheet reconciliation: module entry (init, schema, daily cron, admin-post handlers, report screen).
 *
 * Feature flag "timesheet_recon" (off by default). While it is off nothing here is hooked: no menu, no
 * handler, no cron callback. Every admin-post handler checks the manager capability AND a nonce, then
 * re-checks the flag. The screen is read-only evidence: it never edits a shift, a break, a timecard or pay.
 *
 * Personal data: rows hold WordPress user ids and Square team member ids only. Names are resolved at render
 * time from WordPress users; Square emails are read only when a manager asks for mapping suggestions, are
 * compared in memory and are never stored or shown.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reconciliation admin and scheduling.
 */
final class DoughBoss_Growth_Recon_Admin {

	/**
	 * Submenu slug under the core "doughboss" menu.
	 */
	const PAGE_SLUG = 'doughboss-growth-recon';

	/**
	 * Cron hook (frozen name). Scheduled as a single event at the next local run time and re-armed after
	 * each run, so the run stays at the same Sydney wall-clock time across DST changes.
	 */
	const CRON_HOOK = 'doughboss_growth_recon_daily';

	/**
	 * admin-post actions (also the nonce actions).
	 */
	const ACTION_RUN     = 'doughboss_growth_recon_run';
	const ACTION_MAPPING = 'doughboss_growth_recon_confirm_mapping';
	const ACTION_EXPORT  = 'doughboss_growth_recon_export';
	const ACTION_PARAMS  = 'doughboss_growth_recon_save_params';

	/**
	 * Nonce action for the read-only "suggest mappings from Square" view.
	 */
	const NONCE_SUGGEST = 'doughboss_growth_recon_suggest';

	/**
	 * Capability core gives everyone who may clock in (used to list staff for mapping).
	 */
	const STAFF_CAP = 'clock_doughboss_staff';

	/**
	 * Rows shown on screen (the CSV has every row).
	 */
	const SCREEN_ROWS = 1000;

	/**
	 * Whether init() hooked everything in this request.
	 *
	 * @var bool
	 */
	private static $initialised = false;

	/**
	 * Test seam: capture downloads instead of sending them and exiting.
	 *
	 * @var bool
	 */
	private static $capture = false;

	/**
	 * Last captured download (tests only).
	 *
	 * @var array|null
	 */
	private static $captured = null;

	/**
	 * Tables of this module (called by the activator through the module registry).
	 *
	 * @return array
	 */
	public static function schema() {
		return DoughBoss_Growth_Recon_Report::schema();
	}

	/**
	 * Hook the module. Does nothing while the feature is off.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$initialised || ! DoughBoss_Growth_Settings::enabled( DoughBoss_Growth_Recon_Report::FEATURE ) ) {
			return;
		}
		self::$initialised = true;
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 31 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( 'admin_post_' . self::ACTION_RUN, array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_' . self::ACTION_MAPPING, array( __CLASS__, 'handle_mapping' ) );
		add_action( 'admin_post_' . self::ACTION_EXPORT, array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_' . self::ACTION_PARAMS, array( __CLASS__, 'handle_params' ) );
	}

	/**
	 * Forget per-request state (tests only).
	 *
	 * @return void
	 */
	public static function reset_state() {
		self::$initialised = false;
		self::$capture     = false;
		self::$captured    = null;
	}

	/**
	 * Capture downloads instead of sending them (tests only; ignored outside the harness).
	 *
	 * @param bool $flag Capture.
	 * @return void
	 */
	public static function capture_downloads( $flag ) {
		if ( ! defined( 'DBGR_TESTING' ) ) {
			return;
		}
		self::$capture  = (bool) $flag;
		self::$captured = null;
	}

	/**
	 * The last captured download (tests only).
	 *
	 * @return array|null { filename, body }
	 */
	public static function captured_download() {
		return self::$captured;
	}

	/* ------------------------------------------------------------------ */
	/* Scheduling                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Next UTC instant (strictly after $after) at which the local clock shows $hm.
	 *
	 * @param int    $after UNIX time.
	 * @param string $hm    HH:MM (Sydney).
	 * @return int|null
	 */
	public static function next_run_at( $after, $hm ) {
		if ( ! DoughBoss_Growth_Recon_Matcher::valid_hm( $hm ) ) {
			return null;
		}
		$date = DoughBoss_Growth_Recon_Matcher::local_parts( $after );
		$date = $date['date'];
		for ( $i = 0; $i < 3; $i++ ) {
			$candidate = DoughBoss_Growth_Recon_Matcher::local_to_utc( DoughBoss_Growth_Recon_Matcher::add_days( $date, $i ), $hm );
			if ( null === $candidate ) {
				return null;
			}
			if ( $candidate > (int) $after ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Keep exactly one future single event at the configured run time; none when the feature is off or no
	 * run time is set. An already-scheduled event at a valid occurrence of the run time is kept (even if
	 * overdue), so a slow WP-Cron never loses a run.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( ! DoughBoss_Growth_Settings::enabled( DoughBoss_Growth_Recon_Report::FEATURE ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}
		$params = DoughBoss_Growth_Recon_Report::params();
		$next   = wp_next_scheduled( self::CRON_HOOK );
		if ( null === $params['run_time_local'] ) {
			if ( false !== $next ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return;
		}
		if ( false !== $next ) {
			if ( (int) $next === self::next_run_at( (int) $next - 1, $params['run_time_local'] ) ) {
				return;
			}
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
		$at = self::next_run_at( DoughBoss_Growth::now(), $params['run_time_local'] );
		if ( null !== $at ) {
			wp_schedule_single_event( $at, self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public static function run_cron() {
		if ( ! DoughBoss_Growth_Settings::enabled( DoughBoss_Growth_Recon_Report::FEATURE ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}
		DoughBoss_Growth_Recon_Report::run( 'cron' );
		self::maybe_schedule();
	}

	/* ------------------------------------------------------------------ */
	/* Handlers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Refuse anyone who is not a manager.
	 *
	 * @return void
	 */
	private static function require_manager() {
		if ( ! DoughBoss_Growth_Admin::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'doughboss-growth' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirect back to the report screen with result codes.
	 *
	 * @param array $args Query args.
	 * @return void
	 */
	private static function back( array $args ) {
		$args['page'] = self::PAGE_SLUG;
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * A sanitised POST field.
	 *
	 * @param string $key Field.
	 * @return string
	 */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verified the nonce first.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * "Run now".
	 *
	 * @return void
	 */
	public static function handle_run() {
		self::require_manager();
		check_admin_referer( self::ACTION_RUN );
		if ( ! DoughBoss_Growth_Settings::enabled( DoughBoss_Growth_Recon_Report::FEATURE ) ) {
			self::back( array( 'dbgr_recon_status' => 'NOT_RUN', 'dbgr_recon_reason' => 'feature_off' ) );
		}
		$from = self::posted( 'from' );
		$to   = self::posted( 'to' );
		if ( '' === $from && '' === $to ) {
			$result = DoughBoss_Growth_Recon_Report::run( 'manual', null, null, get_current_user_id() );
		} else {
			$result = DoughBoss_Growth_Recon_Report::run( 'manual', $from, $to, get_current_user_id() );
		}
		self::back(
			array(
				'dbgr_recon_status' => $result['status'],
				'dbgr_recon_reason' => $result['reason'],
			)
		);
	}

	/**
	 * Confirm or revoke a mapping. A mapping is only ever created by a manager's explicit action here.
	 *
	 * @return void
	 */
	public static function handle_mapping() {
		self::require_manager();
		check_admin_referer( self::ACTION_MAPPING );
		if ( ! DoughBoss_Growth_Settings::enabled( DoughBoss_Growth_Recon_Report::FEATURE ) ) {
			self::back( array( 'dbgr_recon_map' => 'feature_off' ) );
		}
		$environment = DoughBoss_Growth_Recon_Square::environment();
		if ( '' === $environment ) {
			self::back( array( 'dbgr_recon_map' => 'square_env_missing' ) );
		}
		$op       = self::posted( 'op' );
		$kind     = self::posted( 'kind' );
		$local_id = absint( self::posted( 'local_id' ) );
		if ( ! in_array( $kind, array( 'employee', 'location' ), true ) || $local_id < 1 ) {
			self::back( array( 'dbgr_recon_map' => 'invalid' ) );
		}
		if ( 'revoke' === $op ) {
			$result = DoughBoss_Growth_Recon_Report::revoke_mapping( $kind, $environment, $local_id, get_current_user_id() );
			self::back( array( 'dbgr_recon_map' => $result ) );
		}
		if ( 'confirm' !== $op ) {
			self::back( array( 'dbgr_recon_map' => 'invalid' ) );
		}
		$exists = ( 'employee' === $kind ) ? ( false !== get_userdata( $local_id ) ) : self::shop_exists( $local_id );
		if ( null === $exists ) {
			// The shop list could not be read: that is not the same as the shop not existing.
			self::back( array( 'dbgr_recon_map' => 'shops_unreadable' ) );
		}
		if ( ! $exists ) {
			self::back( array( 'dbgr_recon_map' => 'unknown_local' ) );
		}
		$result = DoughBoss_Growth_Recon_Report::confirm_mapping( $kind, $environment, $local_id, self::posted( 'square_id' ), get_current_user_id() );
		self::back( array( 'dbgr_recon_map' => $result ) );
	}

	/**
	 * Save the parameters. A blank field means "unset"; a value outside its accepted range is refused and
	 * left unset (never clamped to a guess).
	 *
	 * @return void
	 */
	public static function handle_params() {
		self::require_manager();
		check_admin_referer( self::ACTION_PARAMS );
		if ( ! DoughBoss_Growth_Settings::enabled( DoughBoss_Growth_Recon_Report::FEATURE ) ) {
			self::back( array( 'dbgr_recon_params' => 'feature_off' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is validated by DoughBoss_Growth_Recon_Report::sanitize_params().
		$raw   = ( isset( $_POST['dbgr_recon'] ) && is_array( $_POST['dbgr_recon'] ) ) ? wp_unslash( $_POST['dbgr_recon'] ) : array();
		$input = array();
		$given = array();
		foreach ( array_merge( array_keys( DoughBoss_Growth_Recon_Report::INT_PARAMS ), array( 'run_time_local' ) ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && '' !== trim( $raw[ $key ] ) ) {
				$input[ $key ] = sanitize_text_field( $raw[ $key ] );
				$given[]       = $key;
			}
		}
		// A cutoff is changed only by a field the form actually posted (blank = unset). A shop the form did not
		// show (its list could not be read, or the shop is inactive) keeps its stored cutoff, never wiped.
		$stored                             = DoughBoss_Growth_Recon_Report::params();
		$input['business_day_cutoff_local'] = $stored['business_day_cutoff_local'];
		if ( isset( $raw['business_day_cutoff_local'] ) && is_array( $raw['business_day_cutoff_local'] ) ) {
			foreach ( $raw['business_day_cutoff_local'] as $shop => $hm ) {
				if ( ! is_string( $hm ) ) {
					continue;
				}
				if ( '' !== trim( $hm ) ) {
					$input['business_day_cutoff_local'][ absint( $shop ) ] = sanitize_text_field( $hm );
					$given[] = 'cutoff_' . absint( $shop );
				} else {
					unset( $input['business_day_cutoff_local'][ absint( $shop ) ] );
				}
			}
		}
		$saved = DoughBoss_Growth_Recon_Report::save_params( $input );
		if ( false === $saved ) {
			self::back( array( 'dbgr_recon_params' => 'not_saved' ) );
		}
		$rejected = array();
		foreach ( $given as $key ) {
			if ( 0 === strpos( $key, 'cutoff_' ) ) {
				if ( ! isset( $saved['business_day_cutoff_local'][ (int) substr( $key, 7 ) ] ) ) {
					$rejected[] = $key;
				}
			} elseif ( null === $saved[ $key ] ) {
				$rejected[] = $key;
			}
		}
		self::maybe_schedule();
		$args = array( 'dbgr_recon_params' => 'saved' );
		if ( array() !== $rejected ) {
			$args['dbgr_recon_rejected'] = implode( ',', $rejected );
		}
		self::back( $args );
	}

	/**
	 * CSV download of one finished run (ids, codes and minutes only).
	 *
	 * @return void
	 */
	public static function handle_export() {
		self::require_manager();
		check_admin_referer( self::ACTION_EXPORT );
		if ( ! DoughBoss_Growth_Settings::enabled( DoughBoss_Growth_Recon_Report::FEATURE ) ) {
			wp_die( esc_html__( 'Timesheet reconciliation is switched off.', 'doughboss-growth' ), '', array( 'response' => 403 ) );
		}
		$run_id = absint( self::posted( 'run_id' ) );
		if ( $run_id < 1 ) {
			$latest = DoughBoss_Growth_Recon_Report::latest_run();
			if ( false === $latest ) {
				self::export_unreadable();
			}
			$run_id = is_array( $latest ) ? (int) $latest['id'] : 0;
		}
		$csv = ( $run_id > 0 ) ? DoughBoss_Growth_Recon_Report::csv( $run_id ) : null;
		if ( false === $csv ) {
			self::export_unreadable();
		}
		if ( null === $csv ) {
			wp_die( esc_html__( 'There is no finished reconciliation run to export.', 'doughboss-growth' ), '', array( 'response' => 404 ) );
		}
		self::emit_download( 'doughboss-timesheet-check-run-' . $run_id . '.csv', $csv );
	}

	/**
	 * Stop an export whose data could not be read. An error page, never a file and never "no run".
	 *
	 * @return void
	 */
	private static function export_unreadable() {
		wp_die( esc_html__( 'The reconciliation run or its rows could not be read, so no file was produced. Nothing was exported. This is not the same as there being nothing to export; try again.', 'doughboss-growth' ), '', array( 'response' => 500 ) );
	}

	/**
	 * Send a CSV download and stop (or capture it in tests).
	 *
	 * @param string $filename File name (ascii, generated).
	 * @param string $body     CSV.
	 * @return void
	 */
	private static function emit_download( $filename, $body ) {
		if ( self::$capture ) {
			self::$captured = array(
				'filename' => $filename,
				'body'     => $body,
			);
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $filename ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body: ids, codes, numbers; formula cells neutralised.
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Screen                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Add the submenu under the core DoughBoss menu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			'doughboss',
			__( 'Timesheet check', 'doughboss-growth' ),
			__( 'Timesheet check', 'doughboss-growth' ),
			DoughBoss_Growth_Admin::cap(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Plain-language meaning of each code (admin only).
	 *
	 * @return array
	 */
	public static function code_labels() {
		return array(
			'UNMAPPED_EMPLOYEE'      => __( 'Staff member has no confirmed Square mapping; nothing else was checked.', 'doughboss-growth' ),
			'UNMAPPED_LOCATION'      => __( 'Shop has no confirmed Square location mapping; nothing else was checked.', 'doughboss-growth' ),
			'MISSING_IN_SQUARE'      => __( 'Clocked on the website clock, no matching Square timecard.', 'doughboss-growth' ),
			'MISSING_IN_PLUGIN'      => __( 'Square timecard, no matching website clock shift.', 'doughboss-growth' ),
			'OPEN_PLUGIN_SHIFT'      => __( 'Website clock shift still open longer than the set limit.', 'doughboss-growth' ),
			'OPEN_SQUARE_TIMECARD'   => __( 'Square timecard still open longer than the set limit.', 'doughboss-growth' ),
			'OPEN_ONE_SIDE'          => __( 'Closed in one system, still open in the other.', 'doughboss-growth' ),
			'START_DIFF'             => __( 'Start times differ by more than the set tolerance.', 'doughboss-growth' ),
			'END_DIFF'               => __( 'Finish times differ by more than the set tolerance.', 'doughboss-growth' ),
			'BREAK_DIFF'             => __( 'Break totals differ by more than the set tolerance.', 'doughboss-growth' ),
			'NET_DIFF'               => __( 'Net worked minutes differ by more than the set tolerance.', 'doughboss-growth' ),
			'LOCATION_MISMATCH'      => __( 'The two records are for different shops.', 'doughboss-growth' ),
			'OVERLAP_SAME_SIDE'      => __( 'Two records in the same system overlap.', 'doughboss-growth' ),
			'CROSS_LOCATION_OVERLAP' => __( 'Records in the same system overlap at two shops.', 'doughboss-growth' ),
			'LONG_SHIFT'             => __( 'Longer than the set longest normal shift.', 'doughboss-growth' ),
			'MANAGER_CLOSED'         => __( 'Website shift was closed by a manager: its finish is the time of the correction, not the real finish.', 'doughboss-growth' ),
			'NO_OVERLAP_NEAR_MISS'   => __( 'No overlap, but close enough in time to probably be the same shift.', 'doughboss-growth' ),
			'BREAK_PAID_CLASS'       => __( 'Square marks a break as paid; the website clock deducts every break. A pay-policy question, not a data fault.', 'doughboss-growth' ),
			'TIMEZONE_MISMATCH'      => __( 'Square and the website clock record different timezones.', 'doughboss-growth' ),
			'SQUARE_EDITED'          => __( 'The Square timecard was changed after it was created or after it ended.', 'doughboss-growth' ),
			'SPLIT'                  => __( 'Several records on one side cover one record on the other; compared as a whole.', 'doughboss-growth' ),
			'UNRATED_START'          => __( 'Start tolerance not set: shown, not rated.', 'doughboss-growth' ),
			'UNRATED_END'            => __( 'Finish tolerance not set: shown, not rated.', 'doughboss-growth' ),
			'UNRATED_BREAK'          => __( 'Break tolerance not set: shown, not rated.', 'doughboss-growth' ),
			'UNRATED_NET'            => __( 'Net tolerance not set: shown, not rated.', 'doughboss-growth' ),
			'UNRATED_OPEN_ALERT'     => __( 'Open-shift limit not set: shown, not rated.', 'doughboss-growth' ),
			'UNRATED_LONG_SHIFT'     => __( 'Longest-shift limit not set: shown, not rated.', 'doughboss-growth' ),
			'UNRATED_NEAR_MISS'      => __( 'Near-miss distance not set: possible same-shift pairs were not linked.', 'doughboss-growth' ),
		);
	}

	/**
	 * Whether a plugin shop exists and is active (core public API; fails closed when unavailable).
	 *
	 * @param int $id Location id.
	 * @return bool|null Null when the shop list could not be read.
	 */
	private static function shop_exists( $id ) {
		$shops = self::shops();
		if ( null === $shops ) {
			return null;
		}
		return (int) $id > 0 && isset( $shops[ (int) $id ] );
	}

	/**
	 * Active plugin shops as id => name, from core's DoughBoss_Locations::all( true ) (resolved now, never
	 * stored). Null when the list could not be read (missing class, an error, or a result that is not a
	 * list): that is not the same as there being no shops, which is an empty array.
	 *
	 * @return array|null
	 */
	private static function shops() {
		if ( ! class_exists( 'DoughBoss_Locations' ) || ! is_callable( array( 'DoughBoss_Locations', 'all' ) ) ) {
			return null;
		}
		try {
			$rows = DoughBoss_Locations::all( true );
		} catch ( Throwable $e ) {
			return null;
		}
		if ( ! is_array( $rows ) ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $shop ) {
			$shop = (array) $shop;
			if ( isset( $shop['id'] ) && (int) $shop['id'] > 0 ) {
				$out[ (int) $shop['id'] ] = isset( $shop['name'] ) ? (string) $shop['name'] : '';
			}
		}
		return $out;
	}

	/**
	 * Display label for a shop.
	 *
	 * @param int   $id    Location id.
	 * @param array $shops Known shops.
	 * @return string
	 */
	private static function shop_label( $id, array $shops ) {
		if ( (int) $id < 1 ) {
			return __( 'Unknown shop', 'doughboss-growth' );
		}
		return ( isset( $shops[ (int) $id ] ) && '' !== $shops[ (int) $id ] ) ? $shops[ (int) $id ] . ' (#' . (int) $id . ')' : '#' . (int) $id;
	}

	/**
	 * Display label for a WordPress user (resolved at render time, never stored).
	 *
	 * @param int $id User id.
	 * @return string
	 */
	private static function user_label( $id ) {
		if ( (int) $id < 1 ) {
			return '';
		}
		$user = get_userdata( (int) $id );
		if ( is_object( $user ) && isset( $user->display_name ) && '' !== (string) $user->display_name ) {
			return (string) $user->display_name . ' (#' . (int) $id . ')';
		}
		return '#' . (int) $id;
	}

	/**
	 * Staff users who can clock in, as id => object with ID, display_name, user_email.
	 *
	 * @return array
	 */
	private static function staff_users() {
		$out = array();
		if ( ! function_exists( 'get_users' ) ) {
			return $out;
		}
		$users = get_users(
			array(
				'capability' => self::STAFF_CAP,
				'fields'     => array( 'ID', 'display_name', 'user_email' ),
				'orderby'    => 'display_name',
				'number'     => 1000,
			)
		);
		foreach ( (array) $users as $user ) {
			if ( is_object( $user ) && isset( $user->ID ) && (int) $user->ID > 0 ) {
				$out[ (int) $user->ID ] = $user;
			}
		}
		return $out;
	}

	/**
	 * Format a stored UTC datetime for display in Sydney time.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private static function local_time( $value ) {
		$ts = DoughBoss_Growth_Recon_Reader::parse_utc( $value );
		return null === $ts ? '' : DoughBoss_Growth_Recon_Matcher::format_local( $ts );
	}

	/**
	 * Render the report screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		self::require_manager();
		$enabled     = DoughBoss_Growth_Settings::enabled( DoughBoss_Growth_Recon_Report::FEATURE );
		$environment = DoughBoss_Growth_Recon_Square::environment();
		$params      = DoughBoss_Growth_Recon_Report::params();
		$shops       = self::shops();
		$shops_ok    = null !== $shops;
		if ( ! $shops_ok ) {
			$shops = array();
		}
		$ready = DoughBoss_Growth_Activator::storage_ready() && DoughBoss_Growth_Recon_Report::tables_ready();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Timesheet check: website clock and Square', 'doughboss-growth' ) . '</h1>';
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Evidence only.', 'doughboss-growth' ) . '</strong> ' . esc_html__( 'This report compares the two records. It does not decide which one is right and it does not calculate pay. Nothing in either system is changed by this page.', 'doughboss-growth' ) . '</p></div>';
		self::render_result_notices();

		if ( ! $enabled ) {
			echo '<p>' . esc_html__( 'Timesheet reconciliation is switched off.', 'doughboss-growth' ) . '</p></div>';
			return;
		}

		if ( ! $shops_ok ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The shop list could not be read, so shop names, shop mappings and per-shop business-day cutoffs are not shown. This is not the same as having no shops.', 'doughboss-growth' ) . '</p></div>';
		}
		$gaps = DoughBoss_Growth_Recon_Report::unset_params( array_keys( $shops ) );
		$gaps = array_merge(
			array( 'approval' => '[CONFIRM: Elie\'s approval to read Square staff data (personal data) before the first real run, and who owns the Square merchant account. Do not supply the labour token until both are confirmed.]' ),
			$gaps
		);
		echo '<h2>' . esc_html__( 'Owner decisions still outstanding', 'doughboss-growth' ) . '</h2><ul class="ul-disc">';
		foreach ( $gaps as $text ) {
			echo '<li>' . esc_html( $text ) . '</li>';
		}
		echo '</ul>';

		echo '<h2>' . esc_html__( 'Status', 'doughboss-growth' ) . '</h2><table class="widefat striped" style="max-width:760px"><tbody>';
		$guard = DoughBoss_Growth_Recon_Reader::guard();
		self::status_row( __( 'Website clock readable', 'doughboss-growth' ), '' === $guard ? __( 'Yes', 'doughboss-growth' ) : $guard );
		self::status_row( __( 'Square environment (DOUGHBOSS_GROWTH_SQUARE_ENV)', 'doughboss-growth' ), '' === $environment ? __( 'Not set', 'doughboss-growth' ) : $environment );
		self::status_row( __( 'Square labour token (DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN)', 'doughboss-growth' ), DoughBoss_Growth_Recon_Square::has_token() ? __( 'Set', 'doughboss-growth' ) : __( 'Not set', 'doughboss-growth' ) );
		self::status_row( __( 'Square API version (pinned)', 'doughboss-growth' ), DoughBoss_Growth_Recon_Square::SQUARE_VERSION );
		self::status_row( __( 'Report tables', 'doughboss-growth' ), $ready ? __( 'Ready', 'doughboss-growth' ) : __( 'Not ready', 'doughboss-growth' ) );
		$next = wp_next_scheduled( self::CRON_HOOK );
		self::status_row( __( 'Next automatic run', 'doughboss-growth' ), false === $next ? __( 'None scheduled', 'doughboss-growth' ) : DoughBoss_Growth_Recon_Matcher::format_local( (int) $next ) );
		$attempt = $ready ? DoughBoss_Growth_Recon_Report::last_attempt() : null;
		if ( false === $attempt ) {
			self::status_row( __( 'Last attempt', 'doughboss-growth' ), __( 'Could not be read', 'doughboss-growth' ) );
		} elseif ( is_array( $attempt ) ) {
			self::status_row( __( 'Last attempt', 'doughboss-growth' ), sprintf( '#%d %s %s %s', (int) $attempt['id'], (string) $attempt['status'], (string) $attempt['reason_code'], self::local_time( $attempt['started_at'] ) ) );
		}
		echo '</tbody></table>';

		if ( $ready ) {
			self::render_run_form();
			$latest = DoughBoss_Growth_Recon_Report::latest_run();
			if ( is_array( $latest ) ) {
				self::render_report( $latest, $shops );
			} elseif ( false === $latest ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The latest finished run could not be read, so no result is shown. This is not the same as there being no finished run.', 'doughboss-growth' ) . '</p></div>';
			} else {
				echo '<p>' . esc_html__( 'No finished run yet.', 'doughboss-growth' ) . '</p>';
			}
			self::render_mappings( $environment, $shops, $shops_ok );
		}
		self::render_params_form( $params, $shops, $shops_ok );
		echo '</div>';
	}

	/**
	 * Notices after a redirect (codes only, sanitised).
	 *
	 * @return void
	 */
	private static function render_result_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only result codes after a redirect.
		$status   = isset( $_GET['dbgr_recon_status'] ) ? sanitize_key( wp_unslash( $_GET['dbgr_recon_status'] ) ) : '';
		$reason   = isset( $_GET['dbgr_recon_reason'] ) ? sanitize_key( wp_unslash( $_GET['dbgr_recon_reason'] ) ) : '';
		$map      = isset( $_GET['dbgr_recon_map'] ) ? sanitize_key( wp_unslash( $_GET['dbgr_recon_map'] ) ) : '';
		$saved    = isset( $_GET['dbgr_recon_params'] ) ? sanitize_key( wp_unslash( $_GET['dbgr_recon_params'] ) ) : '';
		$rejected = isset( $_GET['dbgr_recon_rejected'] ) ? sanitize_text_field( wp_unslash( $_GET['dbgr_recon_rejected'] ) ) : '';
		// phpcs:enable
		if ( '' !== $status ) {
			$ok = in_array( strtoupper( $status ), array( 'COMPLETE' ), true );
			echo '<div class="notice ' . esc_attr( $ok ? 'notice-success' : 'notice-warning' ) . '"><p>' . esc_html( sprintf( __( 'Run result: %1$s %2$s', 'doughboss-growth' ), strtoupper( $status ), $reason ) ) . '</p></div>';
			if ( 'storage_write_failed' === $reason ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'The report storage could not be written, so no result was stored.', 'doughboss-growth' ) . '</p></div>';
			}
		}
		if ( '' !== $map ) {
			$map_text = array(
				'error'            => __( 'The mapping was not changed because the report storage could not be read or written.', 'doughboss-growth' ),
				'shops_unreadable' => __( 'The shop list could not be read, so the shop was not mapped.', 'doughboss-growth' ),
			);
			if ( isset( $map_text[ $map ] ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $map_text[ $map ] ) . '</p></div>';
			} else {
				echo '<div class="notice ' . esc_attr( 'confirmed' === $map || 'revoked' === $map ? 'notice-success' : 'notice-warning' ) . '"><p>' . esc_html( sprintf( __( 'Mapping: %s', 'doughboss-growth' ), $map ) ) . '</p></div>';
			}
		}
		if ( 'saved' === $saved ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Parameters saved.', 'doughboss-growth' ) . '</p></div>';
		} elseif ( 'not_saved' === $saved ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The parameters could not be saved, so nothing was changed. Try again.', 'doughboss-growth' ) . '</p></div>';
		}
		if ( '' !== $rejected ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'These values were refused and left unset: %s', 'doughboss-growth' ), preg_replace( '/[^a-z0-9_,]/', '', $rejected ) ) ) . '</p></div>';
		}
	}

	/**
	 * One status row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return void
	 */
	private static function status_row( $label, $value ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * "Run now" form.
	 *
	 * @return void
	 */
	private static function render_run_form() {
		echo '<h2>' . esc_html__( 'Run now', 'doughboss-growth' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_RUN ) . '" />';
		wp_nonce_field( self::ACTION_RUN );
		echo '<p>' . esc_html__( 'Leave both dates empty to check the previous business day (plus the look-back days). Or choose business days (Sydney dates, at most 31):', 'doughboss-growth' ) . '</p>';
		echo '<p><label for="dbgr-recon-from">' . esc_html__( 'From', 'doughboss-growth' ) . '</label> <input type="date" id="dbgr-recon-from" name="from" /> ';
		echo '<label for="dbgr-recon-to">' . esc_html__( 'To', 'doughboss-growth' ) . '</label> <input type="date" id="dbgr-recon-to" name="to" /></p>';
		submit_button( __( 'Run the check now', 'doughboss-growth' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Summary and rows of the newest finished run.
	 *
	 * @param array $run   Run row.
	 * @param array $shops Shops.
	 * @return void
	 */
	private static function render_report( array $run, array $shops ) {
		$labels = self::code_labels();
		echo '<h2>' . esc_html( sprintf( __( 'Latest result: run #%1$d (%2$s), business days %3$s to %4$s', 'doughboss-growth' ), (int) $run['id'], (string) $run['status'], (string) $run['report_from'], (string) $run['report_to'] ) ) . '</h2>';
		if ( DoughBoss_Growth_Recon_Report::STATUS_INCOMPLETE === $run['status'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Incomplete: some records could not be checked because a staff member or shop has no confirmed mapping.', 'doughboss-growth' ) . '</p></div>';
		}
		$counts = json_decode( (string) $run['counts_json'], true );
		if ( is_array( $counts ) && isset( $counts['by_day_shop'] ) && is_array( $counts['by_day_shop'] ) ) {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Business day', 'doughboss-growth' ) . '</th><th scope="col">' . esc_html__( 'Shop', 'doughboss-growth' ) . '</th>';
			foreach ( DoughBoss_Growth_Recon_Matcher::STATES as $state ) {
				echo '<th scope="col">' . esc_html( $state ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $counts['by_day_shop'] as $key => $cell ) {
				$parts = explode( '|', (string) $key );
				echo '<tr><td>' . esc_html( $parts[0] ) . '</td><td>' . esc_html( self::shop_label( isset( $parts[1] ) ? (int) $parts[1] : 0, $shops ) ) . '</td>';
				foreach ( DoughBoss_Growth_Recon_Matcher::STATES as $state ) {
					echo '<td>' . esc_html( (string) ( isset( $cell[ $state ] ) ? (int) $cell[ $state ] : 0 ) ) . '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The summary for this run could not be read, so no day-by-shop counts are shown.', 'doughboss-growth' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_EXPORT ) . '" /><input type="hidden" name="run_id" value="' . esc_attr( (string) (int) $run['id'] ) . '" />';
		wp_nonce_field( self::ACTION_EXPORT );
		submit_button( __( 'Download CSV (ids and minutes only)', 'doughboss-growth' ), 'secondary', 'submit', false );
		echo '</form>';

		$rows = DoughBoss_Growth_Recon_Report::rows( (int) $run['id'], '', self::SCREEN_ROWS + 1 );
		if ( null === $rows ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The rows of this run could not be read, so none are shown. This is not the same as the run having no rows or no differences.', 'doughboss-growth' ) . '</p></div>';
			return;
		}
		if ( count( $rows ) > self::SCREEN_ROWS ) {
			echo '<p>' . esc_html( sprintf( __( 'Showing the first %d rows; the CSV has all of them.', 'doughboss-growth' ), self::SCREEN_ROWS ) ) . '</p>';
			$rows = array_slice( $rows, 0, self::SCREEN_ROWS );
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Day', 'doughboss-growth' ), __( 'Shop', 'doughboss-growth' ), __( 'Staff', 'doughboss-growth' ), __( 'State', 'doughboss-growth' ), __( 'Website clock (Sydney)', 'doughboss-growth' ), __( 'Square (Sydney)', 'doughboss-growth' ), __( 'Minutes: start / finish / break / net difference', 'doughboss-growth' ), __( 'Net: website / Square all / Square unpaid', 'doughboss-growth' ), __( 'Flags', 'doughboss-growth' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$staff = (int) $row['user_id'] > 0 ? self::user_label( (int) $row['user_id'] ) : '';
			if ( '' !== (string) $row['team_member_id'] ) {
				$staff .= ( '' === $staff ? '' : ' / ' ) . sprintf( __( 'Square %s', 'doughboss-growth' ), (string) $row['team_member_id'] );
			}
			$flags = array();
			foreach ( array_filter( explode( ',', (string) $row['codes'] ) ) as $code ) {
				$flags[] = $code . ( isset( $labels[ $code ] ) ? ': ' . $labels[ $code ] : '' );
			}
			if ( '1' === (string) $row['changed'] ) {
				$flags[] = __( 'Changed in a source system since the previous run.', 'doughboss-growth' );
			}
			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['workday_local'] ) . '</td>';
			echo '<td>' . esc_html( self::shop_label( (int) $row['plugin_location_id'], $shops ) ) . '</td>';
			echo '<td>' . esc_html( $staff ) . '</td>';
			echo '<td><strong>' . esc_html( (string) $row['state'] ) . '</strong></td>';
			echo '<td>' . esc_html( self::local_time( $row['plugin_start_utc'] ) ) . '<br />' . esc_html( self::local_time( $row['plugin_end_utc'] ) ) . '</td>';
			echo '<td>' . esc_html( self::local_time( $row['square_start_utc'] ) ) . '<br />' . esc_html( self::local_time( $row['square_end_utc'] ) ) . '</td>';
			echo '<td>' . esc_html( implode( ' / ', array( (string) $row['start_delta_min'], (string) $row['end_delta_min'], (string) $row['break_delta_min'], (string) $row['net_delta_min'] ) ) ) . '</td>';
			echo '<td>' . esc_html( implode( ' / ', array( (string) $row['plugin_net_min'], (string) $row['square_net_all_min'], (string) $row['square_net_unpaid_min'] ) ) ) . '</td>';
			echo '<td><ul style="margin:0">';
			foreach ( $flags as $flag ) {
				echo '<li>' . esc_html( $flag ) . '</li>';
			}
			echo '</ul></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Mapping section, with optional read-only suggestions by email.
	 *
	 * @param string $environment Square environment.
	 * @param array  $shops       Shops.
	 * @param bool   $shops_ok    False when the shop list could not be read.
	 * @return void
	 */
	private static function render_mappings( $environment, array $shops, $shops_ok ) {
		echo '<h2>' . esc_html__( 'Mappings (confirmed by a manager only)', 'doughboss-growth' ) . '</h2>';
		if ( '' === $environment ) {
			echo '<p>' . esc_html__( 'Set DOUGHBOSS_GROWTH_SQUARE_ENV to production or sandbox before confirming mappings.', 'doughboss-growth' ) . '</p>';
			return;
		}
		$locations = DoughBoss_Growth_Recon_Report::mappings( 'location', $environment );
		$employees = DoughBoss_Growth_Recon_Report::mappings( 'employee', $environment );
		if ( null === $locations || null === $employees ) {
			echo '<p>' . esc_html__( 'The mappings could not be read.', 'doughboss-growth' ) . '</p>';
			return;
		}
		$effective = DoughBoss_Growth_Recon_Report::effective_locations( $environment );
		if ( is_wp_error( $effective ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $effective->get_error_code() ) . '</p></div>';
		} elseif ( array() !== $effective['blocked'] ) {
			foreach ( $effective['blocked'] as $shop => $why ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'Shop %1$s is blocked (%2$s): the core Square location map and this mapping disagree. Resolve it in one place.', 'doughboss-growth' ), self::shop_label( (int) $shop, $shops ), $why ) ) . '</p></div>';
			}
		}

		echo '<h3>' . esc_html__( 'Shops', 'doughboss-growth' ) . '</h3>';
		if ( ! $shops_ok ) {
			echo '<p>' . esc_html__( 'The shop list could not be read, so no shops are listed here. Existing shop mappings are unchanged.', 'doughboss-growth' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:760px"><tbody>';
			foreach ( $shops as $id => $name ) {
				echo '<tr><th scope="row">' . esc_html( self::shop_label( $id, $shops ) ) . '</th><td>';
				if ( isset( $locations[ $id ] ) ) {
					echo esc_html( $locations[ $id ] ) . ' ';
					self::mapping_button( 'revoke', 'location', $id, '', __( 'Remove', 'doughboss-growth' ) );
				} else {
					self::mapping_input( 'location', $id, __( 'Square location id', 'doughboss-growth' ) );
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		$staff = self::staff_users();
		echo '<h3>' . esc_html__( 'Staff', 'doughboss-growth' ) . '</h3><table class="widefat striped" style="max-width:760px"><tbody>';
		foreach ( $staff as $id => $user ) {
			echo '<tr><th scope="row">' . esc_html( self::user_label( $id ) ) . '</th><td>';
			if ( isset( $employees[ $id ] ) ) {
				echo esc_html( $employees[ $id ] ) . ' ';
				self::mapping_button( 'revoke', 'employee', $id, '', __( 'Remove', 'doughboss-growth' ) );
			} else {
				self::mapping_input( 'employee', $id, __( 'Square team member id', 'doughboss-growth' ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'page'         => self::PAGE_SLUG,
					'dbgr_suggest' => '1',
				),
				admin_url( 'admin.php' )
			),
			self::NONCE_SUGGEST
		);
		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Suggest staff mappings from Square (matching email; nothing is saved until you confirm)', 'doughboss-growth' ) . '</a></p>';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified with wp_verify_nonce below.
		$wants   = isset( $_GET['dbgr_suggest'] ) && '1' === $_GET['dbgr_suggest'];
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable
		if ( $wants && false !== wp_verify_nonce( $nonce, self::NONCE_SUGGEST ) && ! is_wp_error( $effective ) ) {
			self::render_suggestions( $staff, $employees, array_values( array_diff_key( $effective['locations'], $effective['blocked'] ) ) );
		}
	}

	/**
	 * Read-only suggestions: an unmapped staff member whose WordPress email equals exactly one unmapped
	 * active Square team member's email. Emails are never stored or printed.
	 *
	 * @param array $staff     Staff users.
	 * @param array $employees Confirmed mappings.
	 * @param array $locations Square location ids.
	 * @return void
	 */
	private static function render_suggestions( array $staff, array $employees, array $locations ) {
		if ( array() === $locations ) {
			echo '<p>' . esc_html__( 'Map at least one shop first.', 'doughboss-growth' ) . '</p>';
			return;
		}
		$members = DoughBoss_Growth_Recon_Square::search_team_members( $locations );
		if ( is_wp_error( $members ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( __( 'Square could not be read: %s', 'doughboss-growth' ), $members->get_error_code() ) ) . '</p></div>';
			return;
		}
		$mapped = array_flip( array_map( 'strval', $employees ) );
		$by_email = array();
		foreach ( $members as $member ) {
			if ( '' !== $member['email'] && ! isset( $mapped[ $member['id'] ] ) ) {
				$by_email[ $member['email'] ][] = $member['id'];
			}
		}
		$suggestions = array();
		foreach ( $staff as $id => $user ) {
			$email = isset( $user->user_email ) ? strtolower( trim( (string) $user->user_email ) ) : '';
			if ( isset( $employees[ $id ] ) || '' === $email || ! isset( $by_email[ $email ] ) || 1 !== count( $by_email[ $email ] ) ) {
				continue;
			}
			$suggestions[ $id ] = $by_email[ $email ][0];
		}
		echo '<h3>' . esc_html__( 'Suggestions (not saved)', 'doughboss-growth' ) . '</h3>';
		if ( array() === $suggestions ) {
			echo '<p>' . esc_html__( 'No unambiguous email matches.', 'doughboss-growth' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		foreach ( $suggestions as $id => $member ) {
			echo '<tr><th scope="row">' . esc_html( self::user_label( $id ) ) . '</th><td>' . esc_html( $member ) . ' ';
			self::mapping_button( 'confirm', 'employee', $id, $member, __( 'Confirm this mapping', 'doughboss-growth' ) );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * One mapping action button.
	 *
	 * @param string $op        confirm|revoke.
	 * @param string $kind      employee|location.
	 * @param int    $local_id  Local id.
	 * @param string $square_id Square id (confirm only).
	 * @param string $label     Button label.
	 * @return void
	 */
	private static function mapping_button( $op, $kind, $local_id, $square_id, $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_MAPPING ) . '" />';
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '" /><input type="hidden" name="kind" value="' . esc_attr( $kind ) . '" />';
		echo '<input type="hidden" name="local_id" value="' . esc_attr( (string) (int) $local_id ) . '" />';
		if ( '' !== $square_id ) {
			echo '<input type="hidden" name="square_id" value="' . esc_attr( $square_id ) . '" />';
		}
		wp_nonce_field( self::ACTION_MAPPING );
		echo '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * One "type the Square id and confirm" form.
	 *
	 * @param string $kind     employee|location.
	 * @param int    $local_id Local id.
	 * @param string $label    Field label.
	 * @return void
	 */
	private static function mapping_input( $kind, $local_id, $label ) {
		$id = 'dbgr-map-' . $kind . '-' . (int) $local_id;
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_MAPPING ) . '" />';
		echo '<input type="hidden" name="op" value="confirm" /><input type="hidden" name="kind" value="' . esc_attr( $kind ) . '" />';
		echo '<input type="hidden" name="local_id" value="' . esc_attr( (string) (int) $local_id ) . '" />';
		wp_nonce_field( self::ACTION_MAPPING );
		echo '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<input type="text" id="' . esc_attr( $id ) . '" name="square_id" pattern="[A-Za-z0-9_-]{1,64}" maxlength="64" placeholder="' . esc_attr( $label ) . '" autocomplete="off" /> ';
		echo '<button type="submit" class="button button-small">' . esc_html__( 'Confirm', 'doughboss-growth' ) . '</button></form>';
	}

	/**
	 * Parameter form. Every field starts blank: no value is shipped.
	 *
	 * @param array $params   Parameters.
	 * @param array $shops    Shops.
	 * @param bool  $shops_ok False when the shop list could not be read.
	 * @return void
	 */
	private static function render_params_form( array $params, array $shops, $shops_ok ) {
		$fields = array(
			'start_tolerance_minutes'  => __( 'Start time tolerance (minutes)', 'doughboss-growth' ),
			'end_tolerance_minutes'    => __( 'Finish time tolerance (minutes)', 'doughboss-growth' ),
			'break_tolerance_minutes'  => __( 'Break total tolerance (minutes)', 'doughboss-growth' ),
			'net_tolerance_minutes'    => __( 'Net worked time tolerance (minutes)', 'doughboss-growth' ),
			'open_alert_after_minutes' => __( 'Flag a shift still open after (minutes)', 'doughboss-growth' ),
			'max_shift_minutes'        => __( 'Longest normal shift (minutes)', 'doughboss-growth' ),
			'near_miss_search_minutes' => __( 'Link unmatched records up to this far apart (minutes)', 'doughboss-growth' ),
			'lookback_days'            => __( 'Re-check this many earlier days on each run', 'doughboss-growth' ),
			'run_time_local'           => __( 'Daily run time (Sydney, HH:MM)', 'doughboss-growth' ),
		);
		echo '<h2>' . esc_html__( 'Parameters (Elie decides every number; blank means not set)', 'doughboss-growth' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_PARAMS ) . '" />';
		wp_nonce_field( self::ACTION_PARAMS );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $fields as $key => $label ) {
			$id    = 'dbgr-recon-' . $key;
			$value = ( null === $params[ $key ] ) ? '' : (string) $params[ $key ];
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><input type="text" id="' . esc_attr( $id ) . '" name="dbgr_recon[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" autocomplete="off" /></td></tr>';
		}
		if ( ! $shops_ok ) {
			$kept = array();
			foreach ( $params['business_day_cutoff_local'] as $shop => $hm ) {
				$kept[] = '#' . (int) $shop . ' ' . $hm;
			}
			$note = __( 'The shop list could not be read, so cutoffs cannot be edited now. Saving keeps the stored cutoffs.', 'doughboss-growth' );
			if ( array() !== $kept ) {
				$note .= ' ' . sprintf( __( 'Stored: %s', 'doughboss-growth' ), implode( ', ', $kept ) );
			}
			echo '<tr><th scope="row">' . esc_html__( 'Business-day cutoffs', 'doughboss-growth' ) . '</th><td>' . esc_html( $note ) . '</td></tr>';
		}
		foreach ( $shops as $shop => $name ) {
			$id    = 'dbgr-recon-cutoff-' . (int) $shop;
			$value = isset( $params['business_day_cutoff_local'][ $shop ] ) ? $params['business_day_cutoff_local'][ $shop ] : '';
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( sprintf( __( 'Business-day cutoff for %s (Sydney, HH:MM)', 'doughboss-growth' ), self::shop_label( $shop, $shops ) ) ) . '</label></th><td><input type="text" id="' . esc_attr( $id ) . '" name="dbgr_recon[business_day_cutoff_local][' . esc_attr( (string) (int) $shop ) . ']" value="' . esc_attr( $value ) . '" autocomplete="off" /></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save parameters', 'doughboss-growth' ) );
		echo '</form>';
	}
}
