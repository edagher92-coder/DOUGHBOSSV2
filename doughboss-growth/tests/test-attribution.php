<?php
/**
 * WP-04 tests: attribution capture and side records (runner).
 *
 * The suite itself is tests/attribution-suite.php. It loads the real consent module (the attribution module reads the
 * visitor's consent through DoughBoss_Growth_Consent::current(), not a stub), and a PHP class cannot be unloaded, while
 * WP-03's test-consent.php asserts that the consent module has NOT been loaded when it starts. So the suite runs in its
 * own PHP process and this file replays every pass, failure, skip and foreign write into the main run. Where a sub-process
 * cannot be started (a sandboxed PHP) the suite runs in-process instead.
 *
 * @package DoughBoss_Growth
 */

if ( ! dbgr_test_can_subprocess() ) {
	require_once __DIR__ . '/attribution-suite.php';
	return;
}

$dbgr_at_code = <<<'PHP'
error_reporting( E_ALL );
set_error_handler(
	function ( $severity, $message, $file, $line ) {
		if ( ! ( error_reporting() & $severity ) ) {
			return false;
		}
		db_fail( 'PHP diagnostic: ' . $message . ' @ ' . basename( $file ) . ':' . $line );
		return true;
	}
);
require __DIR__ . '/attribution-suite.php';
echo "\n<<<DBGR-RESULT>>>" . json_encode(
	array(
		'results' => $GLOBALS['doughboss_test_results'],
		'foreign' => $GLOBALS['dbgr_foreign_writes'],
	)
);
PHP;

$dbgr_at_run = dbgr_test_subprocess( 'chdir( ' . var_export( __DIR__, true ) . " );\n" . str_replace( '__DIR__', var_export( __DIR__, true ), $dbgr_at_code ) );

db_test(
	'attribution suite (isolated process): ran to the end and reported its results',
	function () use ( $dbgr_at_run ) {
		$marker  = strrpos( $dbgr_at_run['out'], '<<<DBGR-RESULT>>>' );
		$decoded = ( false === $marker ) ? null : json_decode( substr( $dbgr_at_run['out'], $marker + strlen( '<<<DBGR-RESULT>>>' ) ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['results']['passed'] ) ) {
			db_fail( 'the isolated suite produced no result (exit ' . $dbgr_at_run['exit'] . '): ' . substr( $dbgr_at_run['err'] . $dbgr_at_run['out'], 0, 600 ) );
			return;
		}
		$child = $decoded['results'];
		assert_same( 0, $dbgr_at_run['exit'], 'the isolated process exited cleanly' );
		assert_true( $child['passed'] + $child['failed'] >= 300, 'the isolated suite made at least 300 assertions (made ' . ( $child['passed'] + $child['failed'] ) . ')' );
		// Replay: every pass, every failure (already labelled with its test name), every skip, every foreign write.
		$GLOBALS['doughboss_test_results']['passed'] += (int) $child['passed'];
		$GLOBALS['doughboss_test_results']['failed'] += (int) $child['failed'];
		foreach ( (array) $child['failures'] as $failure ) {
			$GLOBALS['doughboss_test_results']['failures'][] = '[isolated] ' . $failure;
		}
		foreach ( (array) $child['skipped'] as $skip ) {
			$GLOBALS['doughboss_test_results']['skipped'][] = '[isolated] ' . $skip;
		}
		foreach ( (array) $decoded['foreign'] as $write ) {
			$GLOBALS['dbgr_foreign_writes'][] = '[isolated] ' . $write;
		}
	}
);
