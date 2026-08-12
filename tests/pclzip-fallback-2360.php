<?php
/** Exercise the updater's actual PclZip fallback against the exact release archives. */

if ( $argc !== 4 ) {
	fwrite( STDERR, "Usage: php pclzip-fallback-2360.php <pclzip.php> <main.zip> <gate.zip>\n" );
	exit( 2 );
}
$test_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'db-pclzip-' . bin2hex( random_bytes( 8 ) );
$include_dir = $test_root . DIRECTORY_SEPARATOR . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes';
if ( ! mkdir( $include_dir, 0700, true ) || ! copy( $argv[1], $include_dir . DIRECTORY_SEPARATOR . 'class-pclzip.php' ) ) {
	throw new RuntimeException( 'Unable to prepare the isolated WordPress PclZip test fixture.' );
}

define( 'ABSPATH', $test_root . DIRECTORY_SEPARATOR );
class WP_Error {
	public $code;
	public $message;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
}
function add_action() {}

require dirname( __DIR__ ) . '/tools/doughboss-deploy-2360-pclzip/doughboss-deploy-2360-pclzip.php';
$method = new ReflectionMethod( 'DoughBoss_Deploy_2360_PclZip', 'validate_zip_entries' );
$method->setAccessible( true );

foreach ( array( array( $argv[2], 'doughboss' ), array( $argv[3], 'doughboss-migration-gate' ) ) as $fixture ) {
	$result = $method->invoke( null, $fixture[0], $fixture[1] );
	if ( true !== $result ) {
		throw new RuntimeException( 'Actual updater PclZip fallback rejected ' . basename( $fixture[0] ) . ': ' . ( $result instanceof WP_Error ? $result->code . ' ' . $result->message : 'unknown result' ) );
	}
	echo basename( $fixture[0] ) . ": actual updater PclZip fallback PASS\n";
}

unlink( $include_dir . DIRECTORY_SEPARATOR . 'class-pclzip.php' );
rmdir( $include_dir );
rmdir( dirname( $include_dir ) );
rmdir( $test_root );
