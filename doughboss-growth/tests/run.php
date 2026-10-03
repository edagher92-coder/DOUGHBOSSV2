<?php
/**
 * DoughBoss Growth test runner.
 *
 * Usage:
 *   php tests/run.php                 run every tests/test-*.php
 *   php tests/run.php ledger consent  run only files whose name contains one of the filters
 *
 * Loads tests/bootstrap.php (the WordPress stub harness), then every tests/stubs-*.php (the DoughBoss core
 * stub and module stubs added by later work packages), then every tests/test-*.php in name order. Prints a pass/fail count and
 * exits non-zero on any failure, on a test file that produced no assertions, or when the run recorded a
 * "foreign write" (the companion touched an option, transient, cron hook or table outside its own
 * doughboss_growth_ namespace).
 *
 * @package DoughBoss_Growth
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

require_once __DIR__ . '/bootstrap.php';

$stubs = dbgr_test_load_stubs();

$filters = array_slice( $argv, 1 );
$files   = glob( __DIR__ . '/test-*.php' );
sort( $files );
if ( array() !== $filters ) {
	$files = array_values(
		array_filter(
			$files,
			function ( $file ) use ( $filters ) {
				foreach ( $filters as $filter ) {
					if ( false !== strpos( basename( $file ), $filter ) ) {
						return true;
					}
				}
				return false;
			}
		)
	);
}
if ( empty( $files ) ) {
	fwrite( STDERR, "No test files found in tests/.\n" );
	exit( 1 );
}

// PHP warnings and notices are failures: a clean run must be silent.
$GLOBALS['dbgr_php_errors'] = array();
set_error_handler(
	function ( $severity, $message, $file, $line ) {
		if ( ! ( error_reporting() & $severity ) ) {
			return false;
		}
		$GLOBALS['dbgr_php_errors'][] = $message . ' @ ' . basename( $file ) . ':' . $line . ' (during ' . $GLOBALS['doughboss_test_results']['current'] . ')';
		db_fail( 'PHP diagnostic: ' . $message . ' @ ' . basename( $file ) . ':' . $line );
		return true;
	}
);

foreach ( $stubs as $stub ) {
	echo '. stubs: ' . basename( $stub ) . "\n";
}
foreach ( $files as $file ) {
	$before = $GLOBALS['doughboss_test_results']['passed'] + $GLOBALS['doughboss_test_results']['failed'];
	require_once $file;
	$after = $GLOBALS['doughboss_test_results']['passed'] + $GLOBALS['doughboss_test_results']['failed'];
	echo '. ' . basename( $file ) . ' (' . ( $after - $before ) . " assertions)\n";
	if ( $after === $before ) {
		$GLOBALS['doughboss_test_results']['current'] = basename( $file );
		db_fail( 'test file made no assertions' );
	}
}

$results = $GLOBALS['doughboss_test_results'];
$foreign = $GLOBALS['dbgr_foreign_writes'];
$total   = $results['passed'] + $results['failed'];

echo "\n";
if ( $results['failed'] > 0 ) {
	echo "FAILURES:\n";
	foreach ( $results['failures'] as $failure ) {
		echo '  x ' . $failure . "\n";
	}
	echo "\n";
}
if ( array() !== $foreign ) {
	echo "FOREIGN WRITES (the companion must only write doughboss_growth_* names):\n";
	foreach ( $foreign as $line ) {
		echo '  x ' . $line . "\n";
	}
	echo "\n";
}

if ( array() !== $results['skipped'] ) {
	echo "SKIPPED (the environment cannot run these; they are NOT passes):\n";
	foreach ( $results['skipped'] as $line ) {
		echo '  - ' . $line . "\n";
	}
	echo "\n";
}

echo sprintf( "%d assertions: %d passed, %d failed, %d skipped, %d foreign writes\n", $total, $results['passed'], $results['failed'], count( $results['skipped'] ), count( $foreign ) );

exit( ( $results['failed'] > 0 || array() !== $foreign ) ? 1 : 0 );
