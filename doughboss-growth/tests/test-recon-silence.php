<?php
/**
 * Timesheet reconciliation silence-rule tests: a failed read is reported as "could not be read", never
 * rendered as an empty list, an empty file, "no finished run", "no shops" or a saved value; a failed write is
 * never reported as a lock held by someone else or as a mapping conflict.
 *
 * Self-contained (own helpers), so `php tests/run.php recon-silence` runs on its own.
 *
 * @package DoughBoss_Growth
 */

DoughBoss_Growth::load_module( 'recon' );

/**
 * Call an admin handler and return the exception it ended with (redirect or die).
 *
 * @param string $method Handler method.
 * @return Exception|null
 */
function dbgr_recon_silence_call( $method ) {
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
function dbgr_recon_silence_args( $e ) {
	if ( ! $e instanceof DBGR_Test_Redirect ) {
		return array( 'not_a_redirect' => $e ? get_class( $e ) . ': ' . $e->getMessage() : 'nothing thrown' );
	}
	parse_str( (string) parse_url( $e->url, PHP_URL_QUERY ), $args );
	return $args;
}

/**
 * Post a form as the logged-in manager with a valid nonce.
 *
 * @param string $action Nonce action.
 * @param array  $fields Fields.
 * @return void
 */
function dbgr_recon_silence_post( $action, array $fields ) {
	$_POST                = $fields;
	$_REQUEST             = $fields;
	$_REQUEST['_wpnonce'] = dbgr_test_nonce( $action );
}

/**
 * Render the report screen and return the HTML.
 *
 * @return string
 */
function dbgr_recon_silence_page() {
	ob_start();
	DoughBoss_Growth_Recon_Admin::render_page();
	return (string) ob_get_clean();
}

/**
 * One finished COMPLETE run with one stored row (a shift with no Square timecard), manager logged in.
 *
 * @return array Run result.
 */
function dbgr_recon_silence_run() {
	dbgr_recon_setup();
	dbgr_test_set_time( gmmktime( 1, 0, 0, 10, 16, 2026 ) );
	dbgr_recon_map( 'location', 1, 'LREV1' );
	dbgr_recon_map( 'employee', 11, 'TM11' );
	dbgr_recon_seed_shift( array( 'id' => 101, 'user' => 11, 'loc' => 1, 'in' => '2026-10-14 22:00:00', 'out' => '2026-10-15 06:00:00' ) );
	dbgr_recon_fake_square( array() );
	dbgr_test_login( array( 'manage_doughboss' ), 2 );
	return DoughBoss_Growth_Recon_Report::run( 'manual' );
}

db_test(
	'recon silence: a failed row read is a read error, never a header-only CSV',
	function () {
		$run = dbgr_recon_silence_run();
		assert_same( 'COMPLETE', $run['status'], 'set-up: run #' . $run['run_id'] . ' is COMPLETE' );
		$id = $run['run_id'];

		$good = DoughBoss_Growth_Recon_Report::csv( $id );
		assert_true( is_string( $good ) && 2 === count( explode( "\n", trim( $good ) ) ), 'control: header plus the one stored row' );
		assert_count( 1, DoughBoss_Growth_Recon_Report::rows( $id ), 'control: rows() lists the stored row' );

		$GLOBALS['wpdb']->fail_on( '/^SELECT \* FROM wp_doughboss_growth_recon_row\b/' );
		assert_same( null, DoughBoss_Growth_Recon_Report::rows( $id ), 'rows(): a read error is null, not an empty list' );
		assert_same( false, DoughBoss_Growth_Recon_Report::csv( $id ), 'csv(): a read error is false, not a header-only file' );

		DoughBoss_Growth_Recon_Admin::capture_downloads( true );
		dbgr_recon_silence_post( 'doughboss_growth_recon_export', array( 'run_id' => (string) $id ) );
		$e = dbgr_recon_silence_call( 'handle_export' );
		assert_true( $e instanceof DBGR_Test_Die && 500 === $e->args['response'], 'Download CSV on a lock timeout: an error page, never HTTP 200' );
		assert_contains( 'could not be read', $e instanceof DBGR_Test_Die ? $e->getMessage() : '', 'the error says the rows could not be read' );
		assert_not_contains( 'There is no finished', $e instanceof DBGR_Test_Die ? $e->getMessage() : '', 'and does not claim there is no run' );
		assert_same( null, DoughBoss_Growth_Recon_Admin::captured_download(), 'no file was sent' );

		$GLOBALS['wpdb']->clear_failures();
		assert_same( null, DoughBoss_Growth_Recon_Report::csv( 999 ), 'control: an unknown run is still null (not a finished run)' );
		dbgr_recon_silence_post( 'doughboss_growth_recon_export', array( 'run_id' => (string) $id ) );
		assert_same( null, dbgr_recon_silence_call( 'handle_export' ), 'control: the same request works once reads work' );
		$download = DoughBoss_Growth_Recon_Admin::captured_download();
		assert_contains( 'MISSING_IN_SQUARE', is_array( $download ) ? $download['body'] : '', 'control: the real evidence is in the file' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon silence: a legitimate run with no rows still exports its header (control)',
	function () {
		dbgr_recon_setup();
		dbgr_test_set_time( gmmktime( 1, 0, 0, 10, 16, 2026 ) );
		dbgr_recon_map( 'location', 1, 'LREV1' );
		dbgr_recon_fake_square( array() );
		$run = DoughBoss_Growth_Recon_Report::run( 'manual' );
		assert_same( 'COMPLETE', $run['status'], 'a COMPLETE run with nothing to compare' );
		$csv = DoughBoss_Growth_Recon_Report::csv( $run['run_id'] );
		assert_same( implode( ',', DoughBoss_Growth_Recon_Report::csv_columns() ), trim( (string) $csv ), 'a successful read of zero rows is the header only' );
		assert_same( array(), DoughBoss_Growth_Recon_Report::rows( $run['run_id'] ), 'and rows() is an empty list, not null' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon silence: a failed latest-run read is not "No finished run yet" and not "no finished run to export"',
	function () {
		$run = dbgr_recon_silence_run();
		assert_same( 'COMPLETE', $run['status'], 'set-up' );

		$GLOBALS['wpdb']->fail_on( '/^SELECT \* FROM wp_doughboss_growth_recon_run WHERE status IN/' );
		assert_same( false, DoughBoss_Growth_Recon_Report::latest_run(), 'latest_run() reports the read error as false' );
		$html = dbgr_recon_silence_page();
		assert_contains( 'The latest finished run could not be read', $html, 'the screen says the read failed' );
		assert_not_contains( 'No finished run yet.', $html, 'the screen does not say there is no run' );
		assert_not_contains( 'Latest result: run', $html, 'and shows no half-read result' );

		DoughBoss_Growth_Recon_Admin::capture_downloads( true );
		dbgr_recon_silence_post( 'doughboss_growth_recon_export', array() );
		$e = dbgr_recon_silence_call( 'handle_export' );
		assert_true( $e instanceof DBGR_Test_Die && 500 === $e->args['response'], 'export of "the latest run": a read error is 500, not the 404 for "none"' );
		assert_not_contains( 'There is no finished', $e instanceof DBGR_Test_Die ? $e->getMessage() : '', 'export does not claim there is no run' );
		assert_same( null, DoughBoss_Growth_Recon_Admin::captured_download(), 'no file was sent' );
		$GLOBALS['wpdb']->clear_failures();
		dbgr_recon_teardown();

		// Control: with no run at all, "none" is still said, and still is a 404.
		dbgr_recon_setup();
		dbgr_test_login( array( 'manage_doughboss' ), 2 );
		$html = dbgr_recon_silence_page();
		assert_contains( 'No finished run yet.', $html, 'control: a healthy empty store still says so' );
		DoughBoss_Growth_Recon_Admin::capture_downloads( true );
		dbgr_recon_silence_post( 'doughboss_growth_recon_export', array() );
		$e = dbgr_recon_silence_call( 'handle_export' );
		assert_true( $e instanceof DBGR_Test_Die && 404 === $e->args['response'], 'control: no run is still a 404' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon silence: a failed last-attempt read is shown as unread, not left out of the status table',
	function () {
		$run = dbgr_recon_silence_run();
		assert_same( 'COMPLETE', $run['status'], 'set-up' );
		$html = dbgr_recon_silence_page();
		assert_contains( 'Last attempt', $html, 'control: the last attempt row is shown' );
		assert_not_contains( 'could not be read', $html, 'control: nothing is flagged on a healthy read' );

		$GLOBALS['wpdb']->fail_on( '/^SELECT \* FROM wp_doughboss_growth_recon_run ORDER BY id DESC/' );
		assert_same( false, DoughBoss_Growth_Recon_Report::last_attempt(), 'last_attempt() reports the read error as false' );
		$html = dbgr_recon_silence_page();
		assert_contains( 'Last attempt', $html, 'the row is still there' );
		assert_contains( 'Could not be read', $html, 'and says it could not be read' );
		$GLOBALS['wpdb']->clear_failures();
		dbgr_recon_teardown();
	}
);

db_test(
	'recon silence: a failed row read on screen is stated, not an empty table under a COMPLETE heading',
	function () {
		$run = dbgr_recon_silence_run();
		assert_same( 'COMPLETE', $run['status'], 'set-up' );
		$html = dbgr_recon_silence_page();
		assert_contains( 'Minutes: start / finish / break / net difference', $html, 'control: the rows table is shown' );
		assert_contains( 'MISSING_IN_SQUARE', $html, 'control: with its evidence' );
		assert_not_contains( 'The rows of this run could not be read', $html, 'control: no error on a healthy read' );

		$GLOBALS['wpdb']->fail_on( '/^SELECT \* FROM wp_doughboss_growth_recon_row\b/' );
		$html = dbgr_recon_silence_page();
		assert_contains( 'run #' . $run['run_id'] . ' (COMPLETE)', $html, 'the heading still names the run' );
		assert_contains( 'The rows of this run could not be read', $html, 'and says its rows could not be read' );
		assert_not_contains( 'Minutes: start / finish / break / net difference', $html, 'no empty table that could be read as "no discrepancies"' );
		$GLOBALS['wpdb']->clear_failures();
		dbgr_recon_teardown();
	}
);

db_test(
	'recon silence: an unreadable run summary is stated, not silently dropped',
	function () {
		$run = dbgr_recon_silence_run();
		assert_same( 'COMPLETE', $run['status'], 'set-up' );
		$html = dbgr_recon_silence_page();
		assert_contains( '<th scope="col">Business day</th>', $html, 'control: the summary table is shown' );
		assert_not_contains( 'The summary for this run could not be read', $html, 'control: no error on a good summary' );

		foreach ( array( '{broken json', '', 'null', '{"total":{}}' ) as $bad ) {
			$GLOBALS['wpdb']->sqlite_raw( "UPDATE wp_doughboss_growth_recon_run SET counts_json = '" . $bad . "'" );
			$html = dbgr_recon_silence_page();
			assert_contains( 'The summary for this run could not be read', $html, 'bad counts_json ' . wp_json_encode( $bad ) . ': stated' );
			assert_not_contains( '<th scope="col">Business day</th>', $html, 'bad counts_json ' . wp_json_encode( $bad ) . ': no half summary' );
			assert_contains( 'MISSING_IN_SQUARE', $html, 'bad counts_json ' . wp_json_encode( $bad ) . ': the rows are still shown' );
		}
		dbgr_recon_teardown();
	}
);

db_test(
	'recon silence: an unreadable shop list never wipes stored cutoffs and is stated on screen',
	function () {
		dbgr_recon_setup();
		dbgr_test_login( array( 'manage_doughboss' ), 2 );
		DoughBoss_Growth_Recon_Report::save_params(
			array(
				'start_tolerance_minutes'   => '3',
				'business_day_cutoff_local' => array(
					1 => '04:00',
					2 => '05:30',
				),
			)
		);
		$html = dbgr_recon_silence_page();
		assert_contains( 'name="dbgr_recon[business_day_cutoff_local][1]"', $html, 'control: readable shops get a cutoff input each' );
		assert_not_contains( 'The shop list could not be read', $html, 'control: no error on a healthy read' );

		DoughBoss_Locations::$throw = true;
		$html = dbgr_recon_silence_page();
		assert_contains( 'The shop list could not be read', $html, 'a failed shop read is stated' );
		assert_not_contains( 'name="dbgr_recon[business_day_cutoff_local]', $html, 'no cutoff inputs are offered while shops are unreadable' );
		assert_contains( '04:00', $html, 'the stored cutoffs are still shown (read-only)' );

		// What the form sent without cutoff fields would post: numbers only.
		dbgr_recon_silence_post( 'doughboss_growth_recon_save_params', array( 'dbgr_recon' => array( 'start_tolerance_minutes' => '3' ) ) );
		$args = dbgr_recon_silence_args( dbgr_recon_silence_call( 'handle_params' ) );
		assert_same( 'saved', $args['dbgr_recon_params'], 'the numbers are saved' );
		$params = DoughBoss_Growth_Recon_Report::params();
		assert_same( 3, $params['start_tolerance_minutes'], 'the posted number is stored' );
		assert_same( array( 1 => '04:00', 2 => '05:30' ), $params['business_day_cutoff_local'], 'the stored cutoffs are kept, not wiped' );

		// A shop list that is not a list at all is the same failure.
		DoughBoss_Locations::$throw = false;
		DoughBoss_Locations::$rows  = null;
		assert_contains( 'The shop list could not be read', dbgr_recon_silence_page(), 'a null shop list is unreadable, not "no shops"' );

		// Control: a readable, genuinely empty shop list is not an error.
		DoughBoss_Locations::$rows = array();
		assert_not_contains( 'The shop list could not be read', dbgr_recon_silence_page(), 'control: zero shops read fine is not flagged' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon silence: saving the cutoff form changes only the shops it shows',
	function () {
		dbgr_recon_setup();
		dbgr_test_login( array( 'manage_doughboss' ), 2 );
		DoughBoss_Growth_Recon_Report::save_params(
			array(
				'business_day_cutoff_local' => array(
					1 => '04:00',
					2 => '05:30',
					9 => '03:00',
				),
			)
		);
		dbgr_recon_silence_post(
			'doughboss_growth_recon_save_params',
			array(
				'dbgr_recon' => array(
					'business_day_cutoff_local' => array(
						'1' => '04:30',
						'2' => '',
						'3' => '06:00',
					),
				),
			)
		);
		$args = dbgr_recon_silence_args( dbgr_recon_silence_call( 'handle_params' ) );
		assert_same( 'saved', $args['dbgr_recon_params'], 'saved' );
		assert_same( array( 1 => '04:30', 3 => '06:00', 9 => '03:00' ), DoughBoss_Growth_Recon_Report::params()['business_day_cutoff_local'], 'a changed value is stored, a blank field clears its shop, a shop the form never showed (inactive #9) keeps its cutoff' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon silence: an unreadable shop list is not "unknown shop" when confirming a mapping',
	function () {
		dbgr_recon_setup();
		dbgr_recon_user( 11, 'Staff Eleven', 'eleven@staff.invalid' );
		dbgr_test_login( array( 'manage_doughboss' ), 2 );
		DoughBoss_Locations::$throw = true;
		dbgr_recon_silence_post( 'doughboss_growth_recon_confirm_mapping', array( 'op' => 'confirm', 'kind' => 'location', 'local_id' => '1', 'square_id' => 'LREV1' ) );
		$args = dbgr_recon_silence_args( dbgr_recon_silence_call( 'handle_mapping' ) );
		assert_same( 'shops_unreadable', $args['dbgr_recon_map'], 'a shop read failure is reported as such, not as unknown_local' );
		assert_count( 0, $GLOBALS['wpdb']->sqlite_raw( 'SELECT id FROM wp_doughboss_growth_recon_xref' ), 'nothing was mapped' );
		$_GET = $args;
		assert_contains( 'The shop list could not be read, so the shop was not mapped', dbgr_recon_silence_page(), 'the screen explains it' );

		dbgr_recon_silence_post( 'doughboss_growth_recon_confirm_mapping', array( 'op' => 'confirm', 'kind' => 'employee', 'local_id' => '11', 'square_id' => 'TM11' ) );
		$args = dbgr_recon_silence_args( dbgr_recon_silence_call( 'handle_mapping' ) );
		assert_same( 'confirmed', $args['dbgr_recon_map'], 'control: staff mapping does not depend on the shop list' );

		DoughBoss_Locations::$throw = false;
		dbgr_recon_silence_post( 'doughboss_growth_recon_confirm_mapping', array( 'op' => 'confirm', 'kind' => 'location', 'local_id' => '9', 'square_id' => 'LNINE' ) );
		$args = dbgr_recon_silence_args( dbgr_recon_silence_call( 'handle_mapping' ) );
		assert_same( 'unknown_local', $args['dbgr_recon_map'], 'control: a genuinely unknown shop is still unknown_local' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon silence: a failed save is not reported as saved (sub-process, option write refused)',
	function () {
		if ( ! dbgr_test_can_subprocess() ) {
			dbgr_test_skip( 'this PHP cannot start a sub-process' );
			return;
		}
		$prelude = <<<'PHP'
function update_option( $name, $value, $autoload = null ) {
	if ( 'doughboss_growth_recon' === $name ) {
		return false;
	}
	$GLOBALS['dbgr_options'][ $name ] = $value;
	return true;
}
PHP;
		$code    = <<<'PHP'
DoughBoss_Growth::load_module( 'recon' );
dbgr_recon_setup();
dbgr_test_login( array( 'manage_doughboss' ), 2 );
$out             = array();
$out['returned'] = DoughBoss_Growth_Recon_Report::save_params( array( 'start_tolerance_minutes' => '3' ) );
$_POST           = array( 'dbgr_recon' => array( 'start_tolerance_minutes' => '3' ) );
$_REQUEST        = $_POST;
$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_recon_save_params' );
$out['args']     = array();
try {
	DoughBoss_Growth_Recon_Admin::handle_params();
} catch ( DBGR_Test_Redirect $e ) {
	parse_str( (string) parse_url( $e->url, PHP_URL_QUERY ), $out['args'] );
}
$stored          = DoughBoss_Growth_Recon_Report::params();
$out['stored']   = $stored['start_tolerance_minutes'];
echo json_encode( $out );
PHP;
		$result  = dbgr_test_subprocess( $code, $prelude );
		$data    = json_decode( trim( $result['out'] ), true );
		assert_true( is_array( $data ), 'sub-process result (' . trim( substr( $result['err'], 0, 300 ) ) . ')' );
		if ( ! is_array( $data ) ) {
			return;
		}
		assert_same( null, $data['stored'], 'set-up: the refused write left nothing stored' );
		assert_same( false, $data['returned'], 'save_params() says false when the value did not persist' );
		assert_same( 'not_saved', isset( $data['args']['dbgr_recon_params'] ) ? $data['args']['dbgr_recon_params'] : null, 'the handler redirects with not_saved, never saved' );
		assert_false( isset( $data['args']['dbgr_recon_rejected'] ), 'and does not blame the values as refused' );
	}
);

db_test(
	'recon silence: save_params() and the screen: saved only when read back, not_saved is a visible error',
	function () {
		dbgr_recon_setup();
		dbgr_test_login( array( 'manage_doughboss' ), 2 );
		$saved = DoughBoss_Growth_Recon_Report::save_params( array( 'start_tolerance_minutes' => '3' ) );
		assert_same( 3, is_array( $saved ) ? $saved['start_tolerance_minutes'] : null, 'control: a persisted save returns the stored value' );
		$again = DoughBoss_Growth_Recon_Report::save_params( array( 'start_tolerance_minutes' => '3' ) );
		assert_same( 3, is_array( $again ) ? $again['start_tolerance_minutes'] : null, 'control: saving an unchanged value is still a success (update_option says false, the read-back matches)' );

		$_GET = array( 'dbgr_recon_params' => 'saved' );
		$html = dbgr_recon_silence_page();
		assert_contains( 'Parameters saved.', $html, 'control: saved is announced' );
		$_GET = array( 'dbgr_recon_params' => 'not_saved' );
		$html = dbgr_recon_silence_page();
		assert_contains( 'The parameters could not be saved', $html, 'not_saved is announced as an error' );
		assert_not_contains( 'Parameters saved.', $html, 'and never as a success' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon silence: a failed run-lock insert is a storage failure, not "run in progress"',
	function () {
		dbgr_recon_setup();
		dbgr_test_set_time( gmmktime( 1, 0, 0, 10, 16, 2026 ) );
		dbgr_recon_map( 'location', 1, 'LREV1' );
		dbgr_recon_fake_square( array() );
		dbgr_test_login( array( 'manage_doughboss' ), 2 );

		$GLOBALS['wpdb']->fail_on( '/^INSERT INTO wp_doughboss_growth_recon_run\b/' );
		$result = DoughBoss_Growth_Recon_Report::run( 'manual' );
		assert_same( array( 'NOT_RUN', 'storage_write_failed', 0 ), array( $result['status'], $result['reason'], $result['run_id'] ), 'an insert error that is not a duplicate key is a storage failure' );
		assert_count( 0, $GLOBALS['wpdb']->sqlite_raw( 'SELECT id FROM wp_doughboss_growth_recon_run' ), 'no run row was left behind' );
		assert_count( 0, dbgr_test_http_calls(), 'no Square call was made' );

		dbgr_recon_silence_post( 'doughboss_growth_recon_run', array() );
		$args = dbgr_recon_silence_args( dbgr_recon_silence_call( 'handle_run' ) );
		assert_same( array( 'NOT_RUN', 'storage_write_failed' ), array( $args['dbgr_recon_status'], $args['dbgr_recon_reason'] ), 'the handler reports the storage failure' );
		$_GET = $args;
		$html = dbgr_recon_silence_page();
		assert_contains( 'The report storage could not be written, so no result was stored.', $html, 'and the screen says what it means' );
		assert_not_contains( 'run_in_progress', $html, 'and does not blame another run' );
		$GLOBALS['wpdb']->clear_failures();

		// Control: a genuinely held lock is still "run in progress", and a stale one is still recovered.
		$now = DoughBoss_Growth::now();
		$GLOBALS['wpdb']->sqlite_raw( "INSERT INTO wp_doughboss_growth_recon_run (status, trigger_type, running_guard, started_at) VALUES ('RUNNING', 'cron', 1, '" . gmdate( 'Y-m-d H:i:s', $now - 60 ) . "')" );
		$busy = DoughBoss_Growth_Recon_Report::run( 'manual' );
		assert_same( array( 'NOT_RUN', 'run_in_progress', 0 ), array( $busy['status'], $busy['reason'], $busy['run_id'] ), 'control: a live lock is run_in_progress' );
		$GLOBALS['wpdb']->sqlite_raw( "UPDATE wp_doughboss_growth_recon_run SET started_at = '" . gmdate( 'Y-m-d H:i:s', $now - DoughBoss_Growth_Recon_Report::STALE_RUN_SECONDS - 1 ) . "'" );
		$ok = DoughBoss_Growth_Recon_Report::run( 'manual' );
		assert_same( 'COMPLETE', $ok['status'], 'control: a stale lock is still recovered' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon silence: a failed mapping insert is an error, only a duplicate key is a conflict',
	function () {
		dbgr_recon_setup();
		dbgr_test_login( array( 'manage_doughboss' ), 2 );

		$GLOBALS['wpdb']->fail_on( '/^INSERT INTO wp_doughboss_growth_recon_xref\b/' );
		assert_same( 'error', DoughBoss_Growth_Recon_Report::confirm_mapping( 'employee', 'production', 11, 'TM11', 1 ), 'an insert error that is not a duplicate key is an error, not a conflict' );
		dbgr_recon_silence_post( 'doughboss_growth_recon_confirm_mapping', array( 'op' => 'confirm', 'kind' => 'location', 'local_id' => '1', 'square_id' => 'LREV1' ) );
		$args = dbgr_recon_silence_args( dbgr_recon_silence_call( 'handle_mapping' ) );
		assert_same( 'error', $args['dbgr_recon_map'], 'the handler reports error' );
		$_GET = $args;
		$html = dbgr_recon_silence_page();
		assert_contains( 'The mapping was not changed because the report storage could not be read or written.', $html, 'and the screen says what it means' );
		$GLOBALS['wpdb']->clear_failures();
		assert_count( 0, $GLOBALS['wpdb']->sqlite_raw( 'SELECT id FROM wp_doughboss_growth_recon_xref' ), 'nothing was mapped' );

		// Control: a real unique-key collision after the pre-check (a concurrent confirmation) is a conflict.
		dbgr_recon_map( 'employee', 11, 'TM11' );
		$GLOBALS['wpdb']->respond( '/^SELECT COUNT\(\*\) FROM wp_doughboss_growth_recon_xref/', 0 );
		assert_same( 'conflict', DoughBoss_Growth_Recon_Report::confirm_mapping( 'employee', 'production', 11, 'TM99', 1 ), 'control: the unique key rejects a concurrent confirmation: conflict' );
		dbgr_recon_teardown();
	}
);
