<?php
/**
 * Timesheet reconciliation reader tests (WP-11): guards fail closed, SELECT-only reads with an explicit
 * column list (no names, logins or reasons), window predicate, breaks and manager_closed, invalid data,
 * caps, and parity of net minutes with DoughBoss_Timeclock::worked_minutes().
 *
 * @package DoughBoss_Growth
 */

DoughBoss_Growth::load_module( 'recon' );

/**
 * Directory holding the REAL core plugin source, or "" when none is available.
 *
 * @return string
 */
function dbgr_recon_core_src() {
	$candidates = array();
	$env        = getenv( 'DBGR_CORE_SRC' );
	if ( is_string( $env ) && '' !== $env ) {
		$candidates[] = rtrim( $env, '/' );
	}
	$candidates[] = dirname( dirname( __DIR__ ) );
	foreach ( $candidates as $dir ) {
		if ( is_file( $dir . '/includes/class-doughboss-timeclock.php' ) && is_file( $dir . '/includes/class-doughboss-staff-badge.php' ) ) {
			return $dir;
		}
	}
	return '';
}

$dbgr_recon_window = array( gmmktime( 13, 0, 0, 10, 14, 2026 ), gmmktime( 13, 0, 0, 10, 16, 2026 ) );
$dbgr_recon_now    = gmmktime( 1, 0, 0, 10, 16, 2026 );

db_test(
	'recon reader: guards pass on a ready core and fail closed on each missing precondition',
	function () {
		dbgr_recon_setup();
		assert_same( '', DoughBoss_Growth_Recon_Reader::guard(), 'ready core passes' );

		dbgr_test_core_option( 'doughboss_db_version', '1.20.0' );
		assert_same( 'plugin_db_too_old', DoughBoss_Growth_Recon_Reader::guard(), 'core schema older than 1.21.0' );
		dbgr_test_core_option( 'doughboss_db_version', array( 'x' ) );
		assert_same( 'plugin_db_too_old', DoughBoss_Growth_Recon_Reader::guard(), 'corrupt core schema version' );
		dbgr_test_core_option( 'doughboss_db_version', '1.23.0' );

		$GLOBALS['dbgr_recon_storage_ready'] = false;
		assert_same( 'plugin_storage_not_ready', DoughBoss_Growth_Recon_Reader::guard(), 'core storage_ready() false' );
		$GLOBALS['dbgr_recon_storage_ready'] = 'throw';
		assert_same( 'plugin_storage_not_ready', DoughBoss_Growth_Recon_Reader::guard(), 'core storage_ready() throwing' );
		$GLOBALS['dbgr_recon_storage_ready'] = 'yes';
		assert_same( 'plugin_storage_not_ready', DoughBoss_Growth_Recon_Reader::guard(), 'a truthy non-true answer is not ready' );
		$GLOBALS['dbgr_recon_storage_ready'] = true;

		$read = DoughBoss_Growth_Recon_Reader::read( 0, 1, 2 );
		assert_false( is_wp_error( $read ), 'read works once ready again' );
		$GLOBALS['dbgr_recon_storage_ready'] = false;
		$read = DoughBoss_Growth_Recon_Reader::read( 0, 1, 2 );
		assert_true( is_wp_error( $read ) && 'plugin_storage_not_ready' === $read->get_error_code(), 'read refuses when not ready' );
		$GLOBALS['dbgr_recon_storage_ready'] = true;
		dbgr_recon_teardown();
	}
);

db_test(
	'recon reader: core missing or too old (sub-process, constants cannot be undefined in-process)',
	function () {
		if ( ! dbgr_test_can_subprocess() ) {
			dbgr_test_skip( 'this PHP cannot start a sub-process' );
			return;
		}
		$code   = "DoughBoss_Growth::load_module( 'recon' ); echo DoughBoss_Growth_Recon_Reader::guard();";
		$absent = dbgr_test_subprocess( "define( 'DOUGHBOSS_VERSION', '2.43.2' ); class DoughBoss_Settings {}\n" . $code, '', false );
		assert_same( 'plugin_timeclock_missing', trim( $absent['out'] ), 'no core staff-clock classes' );
		$old = dbgr_test_subprocess( $code, "define( 'DOUGHBOSS_VERSION', '2.36.0' );" );
		assert_same( 'plugin_version_too_old', trim( $old['out'] ), 'core older than 2.37.0' );
	}
);

db_test(
	'recon reader: reads the row contract only, SELECT-only, with the window predicate of core',
	function () use ( $dbgr_recon_window, $dbgr_recon_now ) {
		dbgr_recon_setup();
		dbgr_recon_seed_shift( array( 'id' => 1, 'user' => 11, 'loc' => 1, 'in' => '2026-10-14 22:00:20', 'out' => '2026-10-15 06:00:40', 'breaks' => array( array( '2026-10-15 01:00:10', '2026-10-15 01:30:05' ) ), 'mc' => true ) );
		dbgr_recon_seed_shift( array( 'id' => 2, 'user' => 12, 'loc' => 2, 'in' => '2026-10-01 22:00:00', 'out' => null ) );
		dbgr_recon_seed_shift( array( 'id' => 3, 'user' => 13, 'loc' => 1, 'in' => '2026-10-10 22:00:00', 'out' => '2026-10-11 06:00:00' ) );
		dbgr_recon_seed_shift( array( 'id' => 4, 'user' => 14, 'loc' => 1, 'in' => '2026-10-14 10:00:00', 'out' => '2026-10-14 14:00:00' ) );
		dbgr_recon_seed_shift( array( 'id' => 5, 'user' => 15, 'loc' => 1, 'in' => '2026-10-16 13:00:00', 'out' => '2026-10-16 15:00:00' ) );
		$GLOBALS['wpdb']->reset_log();

		$shifts = DoughBoss_Growth_Recon_Reader::read( $dbgr_recon_window[0], $dbgr_recon_window[1], $dbgr_recon_now );
		assert_false( is_wp_error( $shifts ), 'read succeeds' );
		$ids = array();
		foreach ( $shifts as $s ) {
			$ids[] = $s['shift_id'];
		}
		assert_same( array( 2, 4, 1 ), $ids, 'old open shift and the two overlapping shifts, ordered by clock-in; outside shifts excluded' );
		$one = $shifts[2];
		assert_same( array( 'shift_id', 'user_id', 'location_id', 'timezone', 'in', 'out', 'source', 'late_minutes', 'scheduled_start_local', 'updated_at', 'manager_closed', 'breaks', 'break_all', 'net' ), array_keys( $one ), 'exact row contract' );
		assert_true( $one['manager_closed'], 'manager_closed from the event row' );
		assert_false( $shifts[0]['manager_closed'], 'no event, no flag' );
		assert_same( 29, $one['break_all'], 'break floored' );
		assert_same( 451, $one['net'], 'net = 480 - 29' );
		assert_same( null, $shifts[0]['net'], 'open shift has no net' );
		assert_same( array( 'break_id', 'start', 'end', 'source' ), array_keys( $one['breaks'][0] ), 'break contract' );

		$json = wp_json_encode( $shifts );
		foreach ( array( 'Zed Example', 'zed.example.login', 'private detail', 'Shop name snapshot' ) as $pii ) {
			assert_not_contains( $pii, $json, 'no name, login, reason or location-name snapshot in the read: ' . $pii );
		}
		foreach ( $GLOBALS['wpdb']->queries as $q ) {
			assert_matches( '/^\s*SELECT\b/', $q['sql'], 'reader issues SELECT only' );
			assert_true( 1 !== preg_match( '/staff_name|staff_login|reason|location_name|before_json|after_json|\*/', $q['sql'] ), 'no personal column and no SELECT *' );
		}
		assert_same( array(), $GLOBALS['wpdb']->unprepared, 'every statement went through prepare()' );
		assert_same( array(), dbgr_recon_core_writes(), 'zero writes to any core table' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon reader: invalid core data and database errors fail closed',
	function () use ( $dbgr_recon_window, $dbgr_recon_now ) {
		dbgr_recon_setup();
		dbgr_recon_seed_shift( array( 'id' => 1, 'user' => 11, 'loc' => 1, 'in' => '2026-10-15 06:00:00', 'out' => '2026-10-14 22:00:00' ) );
		$read = DoughBoss_Growth_Recon_Reader::read( $dbgr_recon_window[0], $dbgr_recon_window[1], $dbgr_recon_now );
		assert_true( is_wp_error( $read ) && 'plugin_data_invalid' === $read->get_error_code(), 'clock-out before clock-in' );

		dbgr_recon_setup();
		dbgr_recon_seed_shift( array( 'id' => 1, 'user' => 11, 'loc' => 1, 'in' => '2026-10-14 22:00:00', 'out' => '2026-10-15 06:00:00', 'tz' => 'Not a zone; DROP' ) );
		$read = DoughBoss_Growth_Recon_Reader::read( $dbgr_recon_window[0], $dbgr_recon_window[1], $dbgr_recon_now );
		assert_true( is_wp_error( $read ) && 'plugin_data_invalid' === $read->get_error_code(), 'invalid timezone snapshot' );

		dbgr_recon_setup();
		dbgr_recon_seed_shift( array( 'id' => 1, 'user' => 11, 'loc' => 1, 'in' => '2026-10-14 22:00:00', 'out' => '2026-10-15 06:00:00', 'breaks' => array( array( '2026-10-15 01:00:00', '2026-10-15 00:30:00' ) ) ) );
		$read = DoughBoss_Growth_Recon_Reader::read( $dbgr_recon_window[0], $dbgr_recon_window[1], $dbgr_recon_now );
		assert_true( is_wp_error( $read ) && 'plugin_data_invalid' === $read->get_error_code(), 'break ending before it starts' );

		dbgr_recon_setup();
		dbgr_recon_seed_shift( array( 'id' => 1, 'user' => 11, 'loc' => 1, 'in' => '2026-10-14 22:00:00', 'out' => '2026-10-15 06:00:00' ) );
		$GLOBALS['wpdb']->fail_on( '/FROM wp_doughboss_staff_breaks/' );
		$read = DoughBoss_Growth_Recon_Reader::read( $dbgr_recon_window[0], $dbgr_recon_window[1], $dbgr_recon_now );
		assert_true( is_wp_error( $read ) && 'plugin_read_failed' === $read->get_error_code(), 'break query error' );
		$GLOBALS['wpdb']->clear_failures();
		$GLOBALS['wpdb']->fail_on( '/FROM wp_doughboss_staff_shifts/' );
		$read = DoughBoss_Growth_Recon_Reader::read( $dbgr_recon_window[0], $dbgr_recon_window[1], $dbgr_recon_now );
		assert_true( is_wp_error( $read ) && 'plugin_read_failed' === $read->get_error_code(), 'shift query error' );
		$GLOBALS['wpdb']->clear_failures();

		$rows = array();
		for ( $i = 1; $i <= DoughBoss_Growth_Recon_Reader::MAX_SHIFTS + 1; $i++ ) {
			$rows[] = array( 'id' => (string) $i, 'user_id' => '11', 'location_id' => '1', 'timezone_snapshot' => 'Australia/Sydney', 'clock_in_utc' => '2026-10-14 22:00:00', 'clock_out_utc' => '2026-10-15 06:00:00', 'source' => 'staff_badge', 'late_minutes' => '0', 'scheduled_start_local' => '', 'updated_at' => null, 'manager_closed' => '0' );
		}
		$GLOBALS['wpdb']->respond( '/FROM wp_doughboss_staff_shifts/', $rows );
		$read = DoughBoss_Growth_Recon_Reader::read( $dbgr_recon_window[0], $dbgr_recon_window[1], $dbgr_recon_now );
		assert_true( is_wp_error( $read ) && 'plugin_row_cap' === $read->get_error_code(), 'more rows than the cap is an incomplete read, refused' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon reader: core datetime parsing matches strtotime( value . " UTC" ) and rejects junk',
	function () {
		foreach ( array( '2026-10-14 22:00:20', '2026-04-04 15:30:00', '2026-10-03 16:00:00', '2024-02-29 23:59:59', '2030-01-01 00:00:00' ) as $value ) {
			assert_same( strtotime( $value . ' UTC' ), DoughBoss_Growth_Recon_Reader::parse_utc( $value ), 'same as core for ' . $value );
		}
		foreach ( array( '0000-00-00 00:00:00', '2026-02-30 00:00:00', '2026-10-14T22:00:20', '2026-10-14 22:00', '2026-10-14 24:00:00', '', null, 5 ) as $bad ) {
			assert_same( null, DoughBoss_Growth_Recon_Reader::parse_utc( $bad ), 'refused: ' . wp_json_encode( $bad ) );
		}
	}
);

db_test(
	'recon reader: break batches stay below the IN-list limit',
	function () use ( $dbgr_recon_now ) {
		dbgr_recon_setup();
		for ( $i = 1; $i <= 620; $i++ ) {
			dbgr_recon_seed_shift( array( 'id' => $i, 'user' => 1000 + $i, 'loc' => 1, 'in' => '2026-10-14 22:00:00', 'out' => '2026-10-15 01:00:00', 'breaks' => ( 0 === $i % 3 ) ? array( array( '2026-10-14 23:00:00', '2026-10-14 23:10:59' ) ) : array() ) );
		}
		$GLOBALS['wpdb']->reset_log();
		$shifts = DoughBoss_Growth_Recon_Reader::read( gmmktime( 13, 0, 0, 10, 14, 2026 ), gmmktime( 13, 0, 0, 10, 16, 2026 ), $dbgr_recon_now );
		assert_count( 620, $shifts, 'all shifts read' );
		assert_count( 2, $GLOBALS['wpdb']->queries_matching( '/FROM wp_doughboss_staff_breaks/' ), '620 shifts need two break batches of at most 500' );
		$with_break = 0;
		foreach ( $shifts as $s ) {
			$with_break += count( $s['breaks'] );
		}
		assert_same( 206, $with_break, 'every third shift has its break attached' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon reader parity: net minutes equal core worked_minutes() on seeded rows (verbatim core formula)',
	function () use ( $dbgr_recon_now ) {
		dbgr_recon_setup();
		mt_srand( 20261002 );
		$seeded = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$in     = gmmktime( 13, 0, 0, 10, 14, 2026 ) + mt_rand( 0, 86400 );
			$out    = $in + mt_rand( 0, 14 * 3600 );
			$breaks = array();
			$cursor = $in;
			for ( $b = 0; $b < mt_rand( 0, 3 ); $b++ ) {
				$start    = $cursor + mt_rand( 0, 3 * 3600 );
				$end      = ( 0 === mt_rand( 0, 6 ) ) ? null : $start + mt_rand( 0, 3600 );
				$breaks[] = array( gmdate( 'Y-m-d H:i:s', $start ), null === $end ? null : gmdate( 'Y-m-d H:i:s', $end ) );
				$cursor   = ( null === $end ) ? $start : $end;
				if ( null === $end ) {
					break;
				}
			}
			$seeded[ $i ] = array( 'id' => $i, 'user' => $i, 'loc' => 1, 'in' => gmdate( 'Y-m-d H:i:s', $in ), 'out' => gmdate( 'Y-m-d H:i:s', $out ), 'breaks' => $breaks );
			dbgr_recon_seed_shift( $seeded[ $i ] );
		}
		$shifts = DoughBoss_Growth_Recon_Reader::read( gmmktime( 0, 0, 0, 10, 1, 2026 ), gmmktime( 0, 0, 0, 10, 30, 2026 ), $dbgr_recon_now );
		assert_count( 60, $shifts, 'all seeded shifts read' );
		$mismatch = array();
		foreach ( $shifts as $s ) {
			$core = DoughBoss_Timeclock::worked_minutes(
				(object) array(
					'id'            => $s['shift_id'],
					'clock_in_utc'  => $seeded[ $s['shift_id'] ]['in'],
					'clock_out_utc' => $seeded[ $s['shift_id'] ]['out'],
				)
			);
			if ( $core !== $s['net'] ) {
				$mismatch[] = $s['shift_id'] . ': core ' . $core . ' companion ' . $s['net'];
			}
		}
		assert_same( array(), $mismatch, 'every seeded shift: companion net == core worked_minutes()' );

		// Negative control: the seeded data is discriminating. A plausible but wrong formula (flooring the
		// break total once instead of each break) must disagree with core on at least one shift.
		$wrong = 0;
		foreach ( $shifts as $s ) {
			$until   = $s['out'];
			$seconds = 0;
			foreach ( $s['breaks'] as $b ) {
				$finish = ( null === $b['end'] ) ? $until : $b['end'];
				if ( $finish > $b['start'] ) {
					$seconds += $finish - $b['start'];
				}
			}
			$naive = max( 0, (int) floor( ( $s['out'] - $s['in'] ) / 60 ) - (int) floor( $seconds / 60 ) );
			if ( $naive !== $s['net'] ) {
				$wrong++;
			}
		}
		assert_true( $wrong > 0, 'a wrong break formula is detected by this data (' . $wrong . ' shifts differ)' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon reader parity against the REAL core classes (sub-process loads core source)',
	function () {
		$src = dbgr_recon_core_src();
		if ( '' === $src ) {
			dbgr_test_skip( 'core source not found (set DBGR_CORE_SRC to a DoughBoss 2.41.0+ checkout); the verbatim-copy parity test above still ran' );
			return;
		}
		if ( ! dbgr_test_can_subprocess() ) {
			dbgr_test_skip( 'this PHP cannot start a sub-process' );
			return;
		}
		$code = <<<'PHP'
define( 'DOUGHBOSS_VERSION', '2.43.2' );
class DoughBoss_Settings {}
final class DoughBoss_Activator { public static function timeclock_storage_ready() { return true; } }
require_once getenv( 'DBGR_RECON_CORE' ) . '/includes/class-doughboss-timeclock.php';
require_once getenv( 'DBGR_RECON_CORE' ) . '/includes/class-doughboss-staff-badge.php';
$GLOBALS['wpdb']->use_sqlite();
$src = file_get_contents( getenv( 'DBGR_RECON_STUBS' ) );
preg_match_all( '/"(CREATE TABLE wp_doughboss_staff_[a-z_]+ \(.*?\) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;)"/s', $src, $m );
foreach ( $m[1] as $ddl ) { $GLOBALS['wpdb']->create_table_from_mysql( $ddl ); }
dbgr_test_core_option( 'doughboss_db_version', '1.23.0' );
DoughBoss_Growth::load_module( 'recon' );
mt_srand( 7 );
$rows = array();
for ( $i = 1; $i <= 80; $i++ ) {
	$in  = 1792000000 + mt_rand( 0, 86400 * 3 );
	$out = $in + mt_rand( 0, 13 * 3600 );
	$GLOBALS['wpdb']->sqlite_raw( "INSERT INTO wp_doughboss_staff_shifts (id, user_id, location_id, clock_in_utc, clock_out_utc, open_guard) VALUES ($i, $i, 1, '" . gmdate( 'Y-m-d H:i:s', $in ) . "', '" . gmdate( 'Y-m-d H:i:s', $out ) . "', NULL)" );
	$c = $in;
	for ( $b = 0; $b < mt_rand( 0, 3 ); $b++ ) {
		$s = $c + mt_rand( 0, 7200 ); $e = mt_rand( 0, 5 ) ? $s + mt_rand( 0, 2400 ) : null;
		$GLOBALS['wpdb']->sqlite_raw( 'INSERT INTO wp_doughboss_staff_breaks (shift_id, user_id, break_start_utc, break_end_utc, open_guard) VALUES (' . $i . ', ' . $i . ", '" . gmdate( 'Y-m-d H:i:s', $s ) . "', " . ( null === $e ? 'NULL' : "'" . gmdate( 'Y-m-d H:i:s', $e ) . "'" ) . ', ' . ( null === $e ? '1' : 'NULL' ) . ')' );
		if ( null === $e ) { break; }
		$c = $e;
	}
}
$shifts = DoughBoss_Growth_Recon_Reader::read( 1790000000, 1795000000, 1795000000 );
$out = array( 'guard' => DoughBoss_Growth_Recon_Reader::guard(), 'count' => is_array( $shifts ) ? count( $shifts ) : -1, 'mismatch' => array(), 'real' => ( new ReflectionClass( 'DoughBoss_Timeclock' ) )->getFileName() );
foreach ( (array) $shifts as $s ) {
	$row  = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( 'SELECT * FROM wp_doughboss_staff_shifts WHERE id = %d', $s['shift_id'] ) );
	$core = DoughBoss_Timeclock::worked_minutes( $row );
	if ( $core !== $s['net'] ) { $out['mismatch'][] = $s['shift_id']; }
}
echo json_encode( $out );
PHP;
		putenv( 'DBGR_RECON_CORE=' . $src );
		putenv( 'DBGR_RECON_STUBS=' . __DIR__ . '/stubs-recon.php' );
		$result = dbgr_test_subprocess( $code, '', false );
		putenv( 'DBGR_RECON_CORE' );
		putenv( 'DBGR_RECON_STUBS' );
		$data = json_decode( trim( $result['out'] ), true );
		assert_true( is_array( $data ), 'sub-process produced a result (' . trim( substr( $result['err'], 0, 300 ) ) . ')' );
		if ( ! is_array( $data ) ) {
			return;
		}
		$expected_core_file = realpath( $src . '/includes/class-doughboss-timeclock.php' );
		assert_true( false !== $expected_core_file, 'the expected real core file exists' );
		assert_same( $expected_core_file, $data['real'], 'the REAL core class was loaded' );
		assert_same( '', $data['guard'], 'reader guard passes against real core accessors' );
		assert_same( 80, $data['count'], 'all 80 seeded shifts read' );
		assert_same( array(), $data['mismatch'], 'companion net == real core worked_minutes() for every seeded shift' );
	}
);
