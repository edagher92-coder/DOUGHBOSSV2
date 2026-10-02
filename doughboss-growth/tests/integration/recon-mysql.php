<?php
/**
 * Timesheet reconciliation (WP-11): real WordPress + MySQL integration checks.
 *
 * Run through WP-CLI in the disposable DoughBoss integration site, with DoughBoss core (2.41.0 or later) and
 * this companion active:
 *   php wp-cli.phar --exec="define('WP_HTTP_BLOCK_EXTERNAL',true);" eval-file doughboss-growth/tests/integration/recon-mysql.php --path=...
 *
 * The four guards below are copied from core tests/integration/wordpress-mysql.php:16-28 so this file can never
 * run against a real site. Square is never called: pre_http_request answers the labour search and FAILS the
 * run on any other outbound request.
 *
 * Checks:
 *   1. parity: for seeded shifts (random instants, breaks including an open break on a closed shift), the
 *      reader's net minutes equal DoughBoss_Timeclock::worked_minutes() of the REAL core on MySQL;
 *   2. zero writes to core: every statement the companion issues during a full reconciliation run (captured
 *      with the "query" filter) writes only {prefix}doughboss_growth_* tables, and no statement touching a core
 *      table is anything but a SELECT;
 *   3. no names, logins or reasons reach the recon tables;
 *   4. the real dbDelta created the three recon tables and the run lock works on MySQL (second insert refused).
 * Everything seeded is removed again at the end (by id), and the option and environment are restored.
 *
 * @package DoughBoss_Growth
 */

if (
	'local' !== wp_get_environment_type()
	|| 'doughboss_wp_test' !== DB_NAME
	|| 'http://doughboss.local.test' !== untrailingslashit( home_url() )
) {
	fwrite( STDERR, "Refusing to run outside the disposable DoughBoss integration site.\n" );
	exit( 2 );
}
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || true !== WP_HTTP_BLOCK_EXTERNAL ) {
	fwrite( STDERR, "Refusing to run without the disposable site's outbound HTTP block.\n" );
	exit( 2 );
}
if ( ! class_exists( 'DoughBoss_Growth' ) || ! class_exists( 'DoughBoss_Timeclock' ) || ! DoughBoss_Growth::load_module( 'recon' ) ) {
	fwrite( STDERR, "DoughBoss core (2.41.0+) and the DoughBoss Growth companion must both be active.\n" );
	exit( 2 );
}

$GLOBALS['dbgr_it_passed'] = 0;
$GLOBALS['dbgr_it_failed'] = array();
$GLOBALS['dbgr_it_sql']    = array();
$GLOBALS['dbgr_it_http']   = array();

/**
 * @param bool   $condition Result.
 * @param string $message   Label.
 * @return void
 */
function dbgr_it_assert( $condition, $message ) {
	if ( $condition ) {
		++$GLOBALS['dbgr_it_passed'];
		return;
	}
	$GLOBALS['dbgr_it_failed'][] = $message;
}

/**
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $message  Label.
 * @return void
 */
function dbgr_it_same( $expected, $actual, $message ) {
	dbgr_it_assert( $expected === $actual, $message . ' (expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) . ')' );
}

/**
 * Record every SQL statement (the "query" filter sees each one before it runs).
 *
 * @param string $sql SQL.
 * @return string
 */
function dbgr_it_capture_sql( $sql ) {
	$GLOBALS['dbgr_it_sql'][] = (string) $sql;
	return $sql;
}

/**
 * Fake Square: answer the labour search with no timecards; refuse and record anything else.
 *
 * @param mixed  $preempt Preempt.
 * @param array  $args    Args.
 * @param string $url     URL.
 * @return array|WP_Error
 */
function dbgr_it_fake_http( $preempt, $args, $url ) {
	unset( $preempt, $args );
	$GLOBALS['dbgr_it_http'][] = (string) $url;
	if ( 'https://connect.squareupsandbox.com/v2/labor/timecards/search' === $url ) {
		return array(
			'headers'  => array(),
			'body'     => '{"timecards":[]}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}
	return new WP_Error( 'dbgr_it_blocked', 'Only the fake Square labour search is allowed in this test.' );
}

global $wpdb;
$shifts_table = DoughBoss_Timeclock::table();
$breaks_table = DoughBoss_Staff_Badge::breaks_table();
$events_table = DoughBoss_Timeclock::events_table();
$seeded       = array();
$seeded_brk   = array();
$seeded_evt   = array();
$xref_ids     = array();
$prior_option = get_option( 'doughboss_growth_settings', null );
$prior_env    = getenv( 'DOUGHBOSS_GROWTH_SQUARE_ENV' );
$prior_token  = getenv( 'DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN' );

try {
	// 4. Real dbDelta: install (idempotent) and check the three tables.
	dbgr_it_assert( DoughBoss_Growth_Activator::install(), 'companion install succeeds on MySQL' );
	dbgr_it_assert( DoughBoss_Growth_Recon_Report::tables_ready(), 'the three recon tables exist' );
	dbgr_it_same( '', DoughBoss_Growth_Recon_Reader::guard(), 'reader guards pass against real core' );

	// Seed synthetic shifts on past dates no other fixture uses (a manual run cannot cover future days); ids are recorded.
	mt_srand( 11 );
	$base = gmmktime( 0, 0, 0, 1, 6, 2025 );
	for ( $i = 0; $i < 40; $i++ ) {
		$in  = $base + mt_rand( 0, 3 * 86400 );
		$out = $in + mt_rand( 0, 13 * 3600 );
		$wpdb->insert(
			$shifts_table,
			array(
				'user_id'           => 990000 + $i,
				'staff_name'        => 'Integration Private Name ' . $i,
				'staff_login'       => 'integration.private.login.' . $i,
				'location_id'       => 1,
				'location_name'     => 'Integration Shop Snapshot',
				'timezone_snapshot' => 'Australia/Sydney',
				'clock_in_utc'      => gmdate( 'Y-m-d H:i:s', $in ),
				'clock_out_utc'     => gmdate( 'Y-m-d H:i:s', $out ),
				'open_guard'        => null,
				'source'            => 'staff_badge',
				'created_at'        => gmdate( 'Y-m-d H:i:s', $in ),
				'updated_at'        => gmdate( 'Y-m-d H:i:s', $out ),
			)
		);
		$shift_id = (int) $wpdb->insert_id;
		$seeded[] = $shift_id;
		$cursor   = $in;
		for ( $b = 0; $b < mt_rand( 0, 3 ); $b++ ) {
			$start = $cursor + mt_rand( 0, 7200 );
			$end   = mt_rand( 0, 5 ) ? $start + mt_rand( 0, 2400 ) : null;
			$wpdb->insert(
				$breaks_table,
				array(
					'shift_id'        => $shift_id,
					'user_id'         => 990000 + $i,
					'break_start_utc' => gmdate( 'Y-m-d H:i:s', $start ),
					'break_end_utc'   => null === $end ? null : gmdate( 'Y-m-d H:i:s', $end ),
					'open_guard'      => null === $end ? 1 : null,
					'source'          => 'staff_badge',
				)
			);
			$seeded_brk[] = (int) $wpdb->insert_id;
			if ( null === $end ) {
				break;
			}
			$cursor = $end;
		}
		if ( 0 === $i % 9 ) {
			$wpdb->insert(
				$events_table,
				array(
					'shift_id'        => $shift_id,
					'event_type'      => 'manager_closed',
					'actor_user_id'   => 1,
					'reason'          => 'Integration private correction reason',
					'before_json'     => '{}',
					'after_json'      => '{}',
					'occurred_at_utc' => gmdate( 'Y-m-d H:i:s', $out ),
				)
			);
			$seeded_evt[] = (int) $wpdb->insert_id;
		}
	}
	dbgr_it_same( 40, count( array_filter( $seeded ) ), 'forty shifts seeded' );

	// 1. Parity with the real core on MySQL.
	$read = DoughBoss_Growth_Recon_Reader::read( $base - 86400, $base + 5 * 86400, $base + 30 * 86400 );
	dbgr_it_assert( is_array( $read ), 'reader returned rows' );
	$by_id = array();
	foreach ( (array) $read as $shift ) {
		$by_id[ $shift['shift_id'] ] = $shift;
	}
	foreach ( $seeded as $shift_id ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$shifts_table} WHERE id = %d", $shift_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		dbgr_it_assert( isset( $by_id[ $shift_id ] ), 'shift ' . $shift_id . ' read' );
		if ( isset( $by_id[ $shift_id ] ) ) {
			dbgr_it_same( DoughBoss_Timeclock::worked_minutes( $row ), $by_id[ $shift_id ]['net'], 'parity for shift ' . $shift_id );
		}
	}

	// 2 and 3. A full run with the query log on: only companion tables are written.
	update_option( 'doughboss_growth_settings', array( 'features' => array( 'timesheet_recon' => true ) ), true );
	putenv( 'DOUGHBOSS_GROWTH_SQUARE_ENV=sandbox' );
	putenv( 'DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN=integration-fake-token-not-real' );
	$xref = DoughBoss_Growth_Recon_Report::table( 'xref' );
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$xref} (kind, environment, local_id, square_id, status, active_guard, confirmed_by, confirmed_at) VALUES ('location', 'sandbox', %d, %s, 'confirmed', 1, 1, %s)", 1, 'LINTEGRATION1', gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$xref_ids[] = (int) $wpdb->insert_id;

	add_filter( 'pre_http_request', 'dbgr_it_fake_http', 10, 3 );
	add_filter( 'query', 'dbgr_it_capture_sql' );
	$result = DoughBoss_Growth_Recon_Report::run( 'manual', gmdate( 'Y-m-d', $base ), gmdate( 'Y-m-d', $base + 2 * 86400 ), 0 );
	remove_filter( 'query', 'dbgr_it_capture_sql' );
	remove_filter( 'pre_http_request', 'dbgr_it_fake_http', 10 );

	dbgr_it_assert( in_array( $result['status'], array( 'COMPLETE', 'INCOMPLETE' ), true ), 'run finished (' . wp_json_encode( $result ) . ')' );
	foreach ( $GLOBALS['dbgr_it_http'] as $url ) {
		dbgr_it_same( 'https://connect.squareupsandbox.com/v2/labor/timecards/search', $url, 'only the fake labour search was requested' );
	}
	$growth = $wpdb->prefix . 'doughboss_growth_';
	foreach ( $GLOBALS['dbgr_it_sql'] as $sql ) {
		if ( 1 === preg_match( '/^\s*(INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM|DROP\s+TABLE|ALTER\s+TABLE|CREATE\s+TABLE|TRUNCATE)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m ) ) {
			dbgr_it_assert( 0 === strpos( $m[2], $growth ), 'companion wrote only its own tables: ' . substr( $sql, 0, 120 ) );
		}
		foreach ( array( $shifts_table, $breaks_table, $events_table ) as $core_table ) {
			if ( false !== strpos( $sql, $core_table ) ) {
				dbgr_it_assert( 1 === preg_match( '/^\s*SELECT\b/i', $sql ), 'core attendance table only read: ' . substr( $sql, 0, 120 ) );
				dbgr_it_assert( false === stripos( $sql, 'staff_name' ) && false === stripos( $sql, 'reason' ) && false === stripos( $sql, 'staff_login' ), 'no personal column selected' );
			}
		}
	}
	$dump = wp_json_encode(
		array(
			$wpdb->get_results( 'SELECT * FROM ' . DoughBoss_Growth_Recon_Report::table( 'run' ), ARRAY_A ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->get_results( 'SELECT * FROM ' . DoughBoss_Growth_Recon_Report::table( 'row' ), ARRAY_A ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		)
	);
	foreach ( array( 'Integration Private Name', 'integration.private.login', 'Integration private correction reason', 'Integration Shop Snapshot', 'integration-fake-token' ) as $needle ) {
		dbgr_it_assert( false === strpos( (string) $dump, $needle ), 'not stored: ' . $needle );
	}

	// Run lock on MySQL: a second RUNNING row is refused by the unique key.
	$run_table = DoughBoss_Growth_Recon_Report::table( 'run' );
	$first     = $wpdb->query( $wpdb->prepare( "INSERT INTO {$run_table} (status, trigger_type, running_guard, started_at) VALUES ('RUNNING', 'integration', 1, %s)", gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$lock_id   = (int) $wpdb->insert_id;
	$suppress  = $wpdb->suppress_errors( true );
	$second    = $wpdb->query( $wpdb->prepare( "INSERT INTO {$run_table} (status, trigger_type, running_guard, started_at) VALUES ('RUNNING', 'integration', 1, %s)", gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->suppress_errors( $suppress );
	dbgr_it_same( 1, $first, 'first lock row inserted' );
	dbgr_it_same( false, $second, 'second concurrent lock row refused by UNIQUE running_guard' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$run_table} WHERE id = %d", $lock_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $result['run_id'] > 0 ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . DoughBoss_Growth_Recon_Report::table( 'row' ) . ' WHERE run_id = %d', $result['run_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$run_table} WHERE id = %d", $result['run_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
} finally {
	// Clean up every seeded row by id, then restore the option and environment.
	foreach ( $seeded_evt as $id ) {
		$wpdb->delete( $events_table, array( 'id' => $id ), array( '%d' ) );
	}
	foreach ( $seeded_brk as $id ) {
		$wpdb->delete( $breaks_table, array( 'id' => $id ), array( '%d' ) );
	}
	foreach ( $seeded as $id ) {
		$wpdb->delete( $shifts_table, array( 'id' => $id ), array( '%d' ) );
	}
	foreach ( $xref_ids as $id ) {
		$wpdb->delete( DoughBoss_Growth_Recon_Report::table( 'xref' ), array( 'id' => $id ), array( '%d' ) );
	}
	if ( null === $prior_option ) {
		delete_option( 'doughboss_growth_settings' );
	} else {
		update_option( 'doughboss_growth_settings', $prior_option, true );
	}
	putenv( false === $prior_env ? 'DOUGHBOSS_GROWTH_SQUARE_ENV' : 'DOUGHBOSS_GROWTH_SQUARE_ENV=' . $prior_env );
	putenv( false === $prior_token ? 'DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN' : 'DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN=' . $prior_token );
}

$failed = $GLOBALS['dbgr_it_failed'];
echo 'recon-mysql: ' . $GLOBALS['dbgr_it_passed'] . ' passed, ' . count( $failed ) . " failed\n";
foreach ( $failed as $message ) {
	echo '  x ' . $message . "\n";
}
exit( array() === $failed ? 0 : 1 );
