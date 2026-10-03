<?php
/**
 * Timesheet reconciliation admin tests (WP-11): inert while off, schema names, capability AND nonce on every
 * handler, mapping and parameter handlers, CSV download, DST-safe daily scheduling, and the report screen
 * (names resolved at render only, everything escaped, no email printed, suggestions never saved).
 *
 * @package DoughBoss_Growth
 */

DoughBoss_Growth::load_module( 'recon' );

/**
 * Call a handler and return the exception it ended with (redirect or die).
 *
 * @param string $method Handler method.
 * @return Exception|null
 */
function dbgr_recon_call( $method ) {
	try {
		call_user_func( array( 'DoughBoss_Growth_Recon_Admin', $method ) );
	} catch ( DBGR_Test_Redirect $e ) {
		return $e;
	} catch ( DBGR_Test_Die $e ) {
		return $e;
	}
	return null;
}

/**
 * Query args of a redirect.
 *
 * @param Exception|null $e Redirect.
 * @return array
 */
function dbgr_recon_redirect_args( $e ) {
	if ( ! $e instanceof DBGR_Test_Redirect ) {
		return array( 'not_a_redirect' => $e ? get_class( $e ) . ': ' . $e->getMessage() : 'nothing thrown' );
	}
	$query = (string) parse_url( $e->url, PHP_URL_QUERY );
	parse_str( $query, $args );
	return $args;
}

/**
 * Post a form as a logged-in manager with a valid nonce.
 *
 * @param string $action Nonce action.
 * @param array  $fields Fields.
 * @return void
 */
function dbgr_recon_post( $action, array $fields ) {
	$_POST                = $fields;
	$_REQUEST             = $fields;
	$_REQUEST['_wpnonce'] = dbgr_test_nonce( $action );
}

$dbgr_recon_handlers = array(
	'handle_run'     => 'doughboss_growth_recon_run',
	'handle_mapping' => 'doughboss_growth_recon_confirm_mapping',
	'handle_params'  => 'doughboss_growth_recon_save_params',
	'handle_export'  => 'doughboss_growth_recon_export',
);

db_test(
	'recon admin: nothing is hooked while the feature is off; everything once it is on',
	function () {
		dbgr_recon_setup( array( 'feature' => false ) );
		DoughBoss_Growth_Recon_Admin::init();
		foreach ( array( 'doughboss_growth_recon_daily', 'admin_post_doughboss_growth_recon_run', 'admin_post_doughboss_growth_recon_confirm_mapping', 'admin_post_doughboss_growth_recon_export', 'admin_post_doughboss_growth_recon_save_params' ) as $hook ) {
			assert_same( 0, dbgr_test_hook_count( $hook ), 'off: ' . $hook . ' not hooked' );
		}
		update_option( 'doughboss_growth_settings', array( 'features' => array( 'timesheet_recon' => true ) ) );
		DoughBoss_Growth_Recon_Admin::init();
		DoughBoss_Growth_Recon_Admin::init();
		foreach ( array( 'doughboss_growth_recon_daily', 'admin_post_doughboss_growth_recon_run', 'admin_post_doughboss_growth_recon_confirm_mapping', 'admin_post_doughboss_growth_recon_export', 'admin_post_doughboss_growth_recon_save_params', 'admin_menu', 'admin_init' ) as $hook ) {
			assert_same( 1, dbgr_test_hook_count( $hook ), 'on: ' . $hook . ' hooked exactly once (init is idempotent)' );
		}
		foreach ( array( 'admin_post_nopriv_doughboss_growth_recon_run', 'admin_post_nopriv_doughboss_growth_recon_export', 'wp_head', 'wp_footer', 'rest_api_init' ) as $hook ) {
			assert_same( 0, dbgr_test_hook_count( $hook ), 'nothing public: ' . $hook );
		}
		dbgr_recon_teardown();
	}
);

db_test(
	'recon admin: the companion bootstrap starts the module only when the flag is on and storage is ready',
	function () {
		dbgr_recon_setup( array( 'feature' => false ) );
		DoughBoss_Growth::init();
		$health = DoughBoss_Growth::health();
		assert_false( $health['modules_active']['recon'], 'flag off: module not started' );
		assert_true( $health['modules_present']['recon'], 'module file present' );
		assert_same( 0, dbgr_test_hook_count( 'admin_post_doughboss_growth_recon_run' ), 'flag off: no handler' );

		DoughBoss_Growth::reset_state();
		DoughBoss_Growth_Recon_Admin::reset_state();
		update_option( 'doughboss_growth_settings', array( 'features' => array( 'timesheet_recon' => true ) ) );
		delete_option( 'doughboss_growth_db_version' );
		DoughBoss_Growth::init();
		assert_false( DoughBoss_Growth::health()['modules_active']['recon'], 'flag on but storage not ready: module not started' );

		DoughBoss_Growth::reset_state();
		DoughBoss_Growth_Recon_Admin::reset_state();
		$GLOBALS['dbgr_hooks'] = $GLOBALS['dbgr_hooks_baseline'];
		update_option( 'doughboss_growth_db_version', DOUGHBOSS_GROWTH_DB_VERSION );
		DoughBoss_Growth::init();
		assert_true( DoughBoss_Growth::health()['modules_active']['recon'], 'flag on and storage ready: module started' );
		assert_same( 1, dbgr_test_hook_count( 'admin_post_doughboss_growth_recon_run' ), 'handlers registered by the registry' );
		assert_true( DoughBoss_Growth::health()['flags']['timesheet_recon'], 'health shows the flag' );
		$filter = function ( $enabled, $feature ) {
			return 'timesheet_recon' === $feature ? false : $enabled;
		};
		add_filter( 'doughboss_growth_feature_enabled', $filter, 10, 2 );
		$result = DoughBoss_Growth_Recon_Report::run( 'manual' );
		assert_same( 'feature_off', $result['reason'], 'the disable-only filter switches the run off too' );
		remove_filter( 'doughboss_growth_feature_enabled', $filter, 10 );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon admin: the module registry loads it and its tables use only the frozen companion names',
	function () {
		$schema = DoughBoss_Growth_Recon_Admin::schema();
		assert_count( 3, $schema, 'three tables' );
		$tables = DoughBoss_Growth_Activator::expected_tables( $schema );
		assert_same( array( 'wp_doughboss_growth_recon_run', 'wp_doughboss_growth_recon_row', 'wp_doughboss_growth_recon_xref' ), $tables, 'frozen table names' );
		foreach ( $tables as $table ) {
			assert_true( in_array( substr( $table, 3 ), DoughBoss_Growth_Activator::TABLE_SUFFIXES, true ), $table . ' is in the uninstall plan' );
		}
		foreach ( $schema as $sql ) {
			assert_contains( 'PRIMARY KEY  (id)', $sql, 'dbDelta two-space PRIMARY KEY' );
			assert_same( 0, preg_match( '/\\b(staff_name|staff_login|display_name|user_login|user_email|email|email_address|phone|reason|notes?|before_json|after_json|wage|hourly_rate|given_name|family_name)\\b/i', $sql ), 'no personal-data or free-text column' );
		}
		assert_contains( 'doughboss_growth_recon', implode( ',', DoughBoss_Growth_Activator::OPTIONS ), 'option in the uninstall plan' );
		assert_contains( 'doughboss_growth_recon_daily', implode( ',', DoughBoss_Growth_Activator::CRON_HOOKS ), 'cron hook in the uninstall plan' );
		$all = DoughBoss_Growth::schemas();
		assert_true( count( array_intersect( $schema, $all ) ) === 3, 'the activator installs the recon tables through the registry' );
	}
);

db_test(
	'recon admin: every handler needs the manager capability AND a valid nonce',
	function () use ( $dbgr_recon_handlers ) {
		foreach ( $dbgr_recon_handlers as $method => $action ) {
			dbgr_recon_setup();
			dbgr_recon_post( $action, array( 'op' => 'confirm', 'kind' => 'location', 'local_id' => '1', 'square_id' => 'LREV1' ) );
			dbgr_test_logout();
			$e = dbgr_recon_call( $method );
			assert_true( $e instanceof DBGR_Test_Die, $method . ': anonymous refused' );
			dbgr_test_login( array( 'read', 'clock_doughboss_staff' ), 5 );
			$_REQUEST['_wpnonce'] = dbgr_test_nonce( $action );
			$e = dbgr_recon_call( $method );
			assert_true( $e instanceof DBGR_Test_Die && isset( $e->args['response'] ) && 403 === $e->args['response'], $method . ': staff member with a valid nonce refused 403' );
			dbgr_test_login( array( 'manage_doughboss' ), 2 );
			unset( $_REQUEST['_wpnonce'] );
			assert_true( dbgr_recon_call( $method ) instanceof DBGR_Test_Die, $method . ': manager without nonce refused' );
			$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_save_settings' );
			assert_true( dbgr_recon_call( $method ) instanceof DBGR_Test_Die, $method . ': manager with another action\'s nonce refused' );
			assert_count( 0, $GLOBALS['wpdb']->sqlite_raw( 'SELECT id FROM wp_doughboss_growth_recon_xref' ), $method . ': nothing written by refused requests' );
			assert_count( 0, $GLOBALS['wpdb']->sqlite_raw( 'SELECT id FROM wp_doughboss_growth_recon_run' ), $method . ': no run started by refused requests' );
			assert_count( 0, dbgr_test_http_calls(), $method . ': no Square call by refused requests' );
			dbgr_recon_teardown();
		}
	}
);

db_test(
	'recon admin: run now runs and reports codes only; a bad date range is refused',
	function () {
		dbgr_recon_setup();
		dbgr_recon_map( 'location', 1, 'LREV1' );
		dbgr_recon_fake_square( array() );
		dbgr_test_login( array( 'manage_doughboss' ), 2 );
		dbgr_recon_post( 'doughboss_growth_recon_run', array() );
		$args = dbgr_recon_redirect_args( dbgr_recon_call( 'handle_run' ) );
		assert_same( 'COMPLETE', $args['dbgr_recon_status'], 'empty world runs COMPLETE' );
		assert_same( 'doughboss-growth-recon', $args['page'], 'back to the report' );
		$run = DoughBoss_Growth_Recon_Report::last_attempt();
		assert_same( '2', (string) $run['actor_user_id'], 'the manager who pressed Run is recorded by id' );
		assert_same( 'manual', $run['trigger_type'], 'manual trigger' );

		dbgr_recon_post( 'doughboss_growth_recon_run', array( 'from' => '2026-10-10', 'to' => '2026-09-01' ) );
		$args = dbgr_recon_redirect_args( dbgr_recon_call( 'handle_run' ) );
		assert_same( array( 'NOT_RUN', 'invalid_dates' ), array( $args['dbgr_recon_status'], $args['dbgr_recon_reason'] ), 'reversed range refused' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon admin: mapping handler confirms, refuses conflicts and unknown records, revokes',
	function () {
		dbgr_recon_setup();
		dbgr_recon_user( 11, 'Staff Eleven', 'eleven@staff.invalid' );
		dbgr_test_login( array( 'manage_doughboss' ), 2 );
		$cases = array(
			array( array( 'op' => 'confirm', 'kind' => 'location', 'local_id' => '1', 'square_id' => 'LREV1' ), 'confirmed' ),
			array( array( 'op' => 'confirm', 'kind' => 'location', 'local_id' => '2', 'square_id' => 'LREV1' ), 'conflict' ),
			array( array( 'op' => 'confirm', 'kind' => 'location', 'local_id' => '9', 'square_id' => 'LNINE' ), 'unknown_local' ),
			array( array( 'op' => 'confirm', 'kind' => 'employee', 'local_id' => '11', 'square_id' => 'TM11' ), 'confirmed' ),
			array( array( 'op' => 'confirm', 'kind' => 'employee', 'local_id' => '77', 'square_id' => 'TM77' ), 'unknown_local' ),
			array( array( 'op' => 'confirm', 'kind' => 'employee', 'local_id' => '11', 'square_id' => '<script>' ), 'invalid' ),
			array( array( 'op' => 'confirm', 'kind' => 'wage', 'local_id' => '11', 'square_id' => 'TM11' ), 'invalid' ),
			array( array( 'op' => 'delete', 'kind' => 'employee', 'local_id' => '11' ), 'invalid' ),
			array( array( 'op' => 'revoke', 'kind' => 'employee', 'local_id' => '11' ), 'revoked' ),
		);
		foreach ( $cases as $case ) {
			dbgr_recon_post( 'doughboss_growth_recon_confirm_mapping', $case[0] );
			$args = dbgr_recon_redirect_args( dbgr_recon_call( 'handle_mapping' ) );
			assert_same( $case[1], isset( $args['dbgr_recon_map'] ) ? $args['dbgr_recon_map'] : $args, wp_json_encode( $case[0] ) );
		}
		assert_same( array( 1 => 'LREV1' ), DoughBoss_Growth_Recon_Report::mappings( 'location', 'production' ), 'only the valid location mapping exists' );
		assert_same( array(), DoughBoss_Growth_Recon_Report::mappings( 'employee', 'production' ), 'employee mapping revoked' );

		dbgr_recon_env( 'DOUGHBOSS_GROWTH_SQUARE_ENV', null );
		dbgr_recon_post( 'doughboss_growth_recon_confirm_mapping', array( 'op' => 'confirm', 'kind' => 'location', 'local_id' => '2', 'square_id' => 'LBNK2' ) );
		$args = dbgr_recon_redirect_args( dbgr_recon_call( 'handle_mapping' ) );
		assert_same( 'square_env_missing', $args['dbgr_recon_map'], 'no mapping without a known Square environment' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon admin: parameters handler saves chosen values, treats blank as unset and refuses bad values',
	function () {
		dbgr_recon_setup();
		dbgr_test_login( array( 'manage_doughboss' ), 2 );
		dbgr_recon_post(
			'doughboss_growth_recon_save_params',
			array(
				'dbgr_recon' => array(
					'start_tolerance_minutes'   => '3',
					'end_tolerance_minutes'     => '',
					'break_tolerance_minutes'   => '99999',
					'net_tolerance_minutes'     => 'ten',
					'max_shift_minutes'         => '600',
					'run_time_local'            => '06:10',
					'business_day_cutoff_local' => array(
						'1' => '04:30',
						'2' => '28:00',
						'3' => '',
					),
				),
			)
		);
		$args   = dbgr_recon_redirect_args( dbgr_recon_call( 'handle_params' ) );
		$params = DoughBoss_Growth_Recon_Report::params();
		assert_same( 'saved', $args['dbgr_recon_params'], 'saved' );
		assert_same( 'break_tolerance_minutes,net_tolerance_minutes,cutoff_2', $args['dbgr_recon_rejected'], 'refused fields reported' );
		assert_same( 3, $params['start_tolerance_minutes'], 'chosen value stored' );
		assert_same( null, $params['end_tolerance_minutes'], 'blank stays unset' );
		assert_same( null, $params['break_tolerance_minutes'], 'out-of-range stays unset (never clamped)' );
		assert_same( null, $params['net_tolerance_minutes'], 'text stays unset' );
		assert_same( array( 1 => '04:30' ), $params['business_day_cutoff_local'], 'valid cutoff only' );
		$next = wp_next_scheduled( 'doughboss_growth_recon_daily' );
		assert_true( false !== $next, 'setting a run time schedules the daily run' );
		$local = DoughBoss_Growth_Recon_Matcher::local_parts( $next );
		assert_same( '06:10', $local['hm'], 'scheduled at 06:10 Sydney time' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon admin: daily schedule keeps Sydney wall-clock time across DST and never churns',
	function () {
		dbgr_recon_setup();
		// Friday 2026-10-02 12:00 AEST.
		dbgr_test_set_time( gmmktime( 2, 0, 0, 10, 2, 2026 ) );
		DoughBoss_Growth_Recon_Admin::maybe_schedule();
		assert_same( false, wp_next_scheduled( 'doughboss_growth_recon_daily' ), 'no run time: nothing scheduled' );

		DoughBoss_Growth_Recon_Report::save_params( array( 'run_time_local' => '02:30' ) );
		DoughBoss_Growth_Recon_Admin::maybe_schedule();
		$first = wp_next_scheduled( 'doughboss_growth_recon_daily' );
		assert_same( gmmktime( 16, 30, 0, 10, 2, 2026 ), $first, 'Saturday 02:30 AEST' );

		// Simulate WP-Cron: the event is removed, then the callback runs.
		$GLOBALS['dbgr_cron'] = array();
		dbgr_test_set_time( $first + 5 );
		dbgr_recon_map( 'location', 1, 'LREV1' );
		dbgr_recon_fake_square( array() );
		DoughBoss_Growth_Recon_Admin::run_cron();
		$second = wp_next_scheduled( 'doughboss_growth_recon_daily' );
		assert_same( 1791043200, $second, 'Sunday 02:30 does not exist (DST gap): runs when the gap ends, 03:00 AEDT' );
		$run = DoughBoss_Growth_Recon_Report::last_attempt();
		assert_same( 'cron', $run['trigger_type'], 'the cron callback ran a reconciliation' );

		DoughBoss_Growth_Recon_Admin::maybe_schedule();
		DoughBoss_Growth_Recon_Admin::maybe_schedule();
		assert_count( 1, $GLOBALS['dbgr_cron'], 'checking again does not reschedule or duplicate (no churn on the gap day)' );
		assert_same( $second, wp_next_scheduled( 'doughboss_growth_recon_daily' ), 'same event kept' );

		$GLOBALS['dbgr_cron'] = array();
		dbgr_test_set_time( $second + 5 );
		DoughBoss_Growth_Recon_Admin::run_cron();
		assert_same( gmmktime( 15, 30, 0, 10, 4, 2026 ), wp_next_scheduled( 'doughboss_growth_recon_daily' ), 'Monday 02:30 AEDT (UTC+11 now)' );

		// An overdue event at a valid occurrence is kept, not pushed to tomorrow.
		dbgr_test_set_time( gmmktime( 15, 30, 0, 10, 4, 2026 ) + 3 * 3600 );
		DoughBoss_Growth_Recon_Admin::maybe_schedule();
		assert_same( gmmktime( 15, 30, 0, 10, 4, 2026 ), wp_next_scheduled( 'doughboss_growth_recon_daily' ), 'overdue event kept so a slow WP-Cron never loses a run' );

		DoughBoss_Growth_Recon_Report::save_params( array( 'run_time_local' => '05:45' ) );
		DoughBoss_Growth_Recon_Admin::maybe_schedule();
		$moved = DoughBoss_Growth_Recon_Matcher::local_parts( wp_next_scheduled( 'doughboss_growth_recon_daily' ) );
		assert_same( '05:45', $moved['hm'], 'a new run time replaces the old event' );
		assert_count( 1, $GLOBALS['dbgr_cron'], 'exactly one event' );

		// Fold day: 2027-04-04 02:30 happens twice; the first one is used.
		assert_same( gmmktime( 15, 30, 0, 4, 3, 2027 ), DoughBoss_Growth_Recon_Admin::next_run_at( gmmktime( 12, 0, 0, 4, 3, 2027 ), '02:30' ), 'fold: earlier 02:30 (AEDT)' );

		update_option( 'doughboss_growth_settings', array( 'features' => array( 'timesheet_recon' => false ) ) );
		DoughBoss_Growth_Recon_Admin::run_cron();
		assert_same( false, wp_next_scheduled( 'doughboss_growth_recon_daily' ), 'feature off: the cron event is cleared and nothing runs' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon admin: CSV export is a manager-only download of ids and minutes',
	function () {
		dbgr_recon_setup();
		dbgr_test_login( array( 'manage_doughboss' ), 2 );
		DoughBoss_Growth_Recon_Admin::capture_downloads( true );
		dbgr_recon_post( 'doughboss_growth_recon_export', array() );
		$e = dbgr_recon_call( 'handle_export' );
		assert_true( $e instanceof DBGR_Test_Die && 404 === $e->args['response'], 'no finished run: 404' );

		dbgr_recon_map( 'location', 1, 'LREV1' );
		dbgr_recon_map( 'employee', 11, 'TM11' );
		dbgr_recon_seed_shift( array( 'id' => 101, 'user' => 11, 'loc' => 1, 'in' => '2026-10-14 22:00:00', 'out' => '2026-10-15 06:00:00' ) );
		dbgr_test_set_time( gmmktime( 1, 0, 0, 10, 16, 2026 ) );
		dbgr_recon_fake_square( array() );
		$run = DoughBoss_Growth_Recon_Report::run( 'manual' );
		dbgr_recon_post( 'doughboss_growth_recon_export', array( 'run_id' => (string) $run['run_id'] ) );
		assert_same( null, dbgr_recon_call( 'handle_export' ), 'download sent' );
		$download = DoughBoss_Growth_Recon_Admin::captured_download();
		assert_same( 'doughboss-timesheet-check-run-' . $run['run_id'] . '.csv', $download['filename'], 'file name' );
		assert_contains( 'MISSING_IN_SQUARE', $download['body'], 'evidence in the CSV' );
		assert_not_contains( 'Zed Example', $download['body'], 'no name in the CSV' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon admin: report screen shows evidence with names resolved at render, escaped, and no stored names',
	function () {
		dbgr_recon_setup();
		dbgr_test_set_time( gmmktime( 1, 0, 0, 10, 16, 2026 ) );
		dbgr_recon_user( 11, 'Ava <b>Bold</b> & Co', 'ava@staff.invalid' );
		dbgr_recon_map( 'location', 1, 'LREV1' );
		dbgr_recon_map( 'employee', 11, 'TM11' );
		dbgr_recon_seed_shift( array( 'id' => 101, 'user' => 11, 'loc' => 1, 'in' => '2026-10-14 22:00:00', 'out' => '2026-10-15 06:00:00', 'mc' => true ) );
		dbgr_recon_fake_square( array() );
		DoughBoss_Growth_Recon_Report::run( 'manual' );

		dbgr_test_logout();
		assert_throws( 'DBGR_Test_Die', array( 'DoughBoss_Growth_Recon_Admin', 'render_page' ), 'anonymous cannot view' );
		dbgr_test_login( array( 'manage_doughboss' ), 2 );
		ob_start();
		DoughBoss_Growth_Recon_Admin::render_page();
		$html = ob_get_clean();
		assert_contains( 'Evidence only.', $html, 'evidence-only banner' );
		assert_contains( 'does not calculate pay', $html, 'never a pay decision' );
		assert_contains( 'Your approval to read Square staff data', $html, 'approval gate shown, as a plain sentence' );
		assert_contains( 'Start time tolerance', $html, 'unset parameters listed, as plain sentences' );
		assert_not_contains( '[CONFIRM', $html, 'no bracket marker reaches the owner' );
		assert_not_contains( 'Elie', $html, 'the owner is never named in the owner-facing text' );
		assert_contains( 'Ava &lt;b&gt;Bold&lt;/b&gt; &amp; Co (#11)', $html, 'name resolved at render time and escaped' );
		assert_not_contains( '<b>Bold</b>', $html, 'no raw HTML from a display name' );
		assert_contains( 'MANAGER_CLOSED', $html, 'flag shown' );
		assert_contains( '2026-10-15 09:00 AEDT', $html, 'times shown in Sydney time with the zone' );
		assert_not_contains( 'ava@staff.invalid', $html, 'no email printed' );
		assert_not_contains( 'dbgr-test-labour-token', $html, 'no token printed' );
		assert_contains( 'name="_wpnonce"', $html, 'forms carry nonces' );
		assert_not_contains( 'Ava', dbgr_recon_dump(), 'the name is not stored anywhere' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon admin: suggestions come from exact unique email matches, need a nonce and are never saved',
	function () {
		dbgr_recon_setup();
		dbgr_recon_user( 11, 'Staff Eleven', 'Eleven@Staff.invalid' );
		dbgr_recon_user( 12, 'Staff Twelve', 'twelve@staff.invalid' );
		dbgr_recon_user( 13, 'Staff Thirteen', 'shared@staff.invalid' );
		dbgr_recon_map( 'location', 1, 'LREV1' );
		dbgr_test_login( array( 'manage_doughboss' ), 2 );

		$_GET = array( 'page' => 'doughboss-growth-recon', 'dbgr_suggest' => '1', '_wpnonce' => 'forged0000' );
		ob_start();
		DoughBoss_Growth_Recon_Admin::render_page();
		ob_get_clean();
		assert_count( 0, dbgr_test_http_calls(), 'a forged nonce never calls Square' );

		dbgr_test_http_expect(
			'https://connect.squareup.com/v2/team-members/search',
			dbgr_test_http_response(
				200,
				wp_json_encode(
					array(
						'team_members' => array(
							array( 'id' => 'TM11', 'status' => 'ACTIVE', 'email_address' => 'eleven@staff.invalid' ),
							array( 'id' => 'TMA', 'status' => 'ACTIVE', 'email_address' => 'shared@staff.invalid' ),
							array( 'id' => 'TMB', 'status' => 'ACTIVE', 'email_address' => 'shared@staff.invalid' ),
						),
					)
				)
			)
		);
		$_GET = array( 'page' => 'doughboss-growth-recon', 'dbgr_suggest' => '1', '_wpnonce' => dbgr_test_nonce( 'doughboss_growth_recon_suggest' ) );
		$before = $GLOBALS['wpdb']->sqlite_raw( 'SELECT COUNT(*) AS n FROM wp_doughboss_growth_recon_xref' );
		ob_start();
		DoughBoss_Growth_Recon_Admin::render_page();
		$html  = ob_get_clean();
		$after = $GLOBALS['wpdb']->sqlite_raw( 'SELECT COUNT(*) AS n FROM wp_doughboss_growth_recon_xref' );
		dbgr_test_http_assert_done( 'one team-member search made' );
		assert_same( $before, $after, 'suggesting saves nothing' );
		assert_contains( 'Suggestions (not saved)', $html, 'suggestion table shown' );
		assert_contains( 'Staff Eleven (#11)</th><td>TM11', $html, 'exact email match (case-insensitive) suggested' );
		assert_not_contains( 'TMA', $html, 'an email shared by two Square members is ambiguous: no suggestion' );
		assert_not_contains( 'staff.invalid', strtolower( $html ), 'no email printed' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon admin: every mismatch code has a plain-language label',
	function () {
		$labels = DoughBoss_Growth_Recon_Admin::code_labels();
		foreach ( array_keys( DoughBoss_Growth_Recon_Matcher::codes() ) as $code ) {
			assert_true( isset( $labels[ $code ] ) && '' !== $labels[ $code ], 'label for ' . $code );
		}
		assert_same( array(), array_diff( array_keys( $labels ), array_keys( DoughBoss_Growth_Recon_Matcher::codes() ) ), 'no label for an unknown code' );
	}
);
