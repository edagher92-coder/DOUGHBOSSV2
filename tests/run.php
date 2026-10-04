<?php
/**
 * DoughBoss test runner.
 *
 * Usage: php tests/run.php
 *
 * Loads tests/bootstrap.php (the WordPress-function shim), then every
 * tests/test-*.php file, and reports pass/fail. Exits non-zero on any failure
 * so it can gate a commit or CI step.
 *
 * @package DoughBoss
 */

require_once __DIR__ . '/bootstrap.php';

$files = glob( __DIR__ . '/test-*.php' );
sort( $files );

if ( empty( $files ) ) {
	fwrite( STDERR, "No test files found in tests/.\n" );
	exit( 1 );
}

foreach ( $files as $file ) {
	echo '· ' . basename( $file ) . "\n";
	require_once $file;
}

$results = $GLOBALS['doughboss_test_results'];
$total   = $results['passed'] + $results['failed'];

echo "\n";
if ( $results['failed'] > 0 ) {
	echo "FAILURES:\n";
	foreach ( $results['failures'] as $failure ) {
		echo '  ✗ ' . $failure . "\n";
	}
	echo "\n";
}

echo sprintf( "%d assertions: %d passed, %d failed\n", $total, $results['passed'], $results['failed'] );

exit( $results['failed'] > 0 ? 1 : 0 );
