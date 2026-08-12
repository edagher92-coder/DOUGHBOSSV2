<?php
/** Build the temporary DoughBoss Migration Gate install package. */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "ERROR: PHP zip extension is required.\n" );
	exit( 1 );
}

$root     = dirname( __DIR__ );
$source   = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'doughboss-migration-gate';
$main     = $source . DIRECTORY_SEPARATOR . 'doughboss-migration-gate.php';
$readme   = $source . DIRECTORY_SEPARATOR . 'README.md';
$dist     = $root . DIRECTORY_SEPARATOR . 'dist';
$zip_path = $dist . DIRECTORY_SEPARATOR . 'doughboss-migration-gate-1.0.3.zip';

if ( ! is_file( $main ) || ! is_file( $readme ) ) {
	fwrite( STDERR, "ERROR: Migration Gate sources are incomplete.\n" );
	exit( 1 );
}
$contents = file_get_contents( $main );
if ( false === $contents || ! preg_match( '/^ \* Version:\s+1\.0\.3\s*$/m', $contents ) ) {
	fwrite( STDERR, "ERROR: Migration Gate source is not version 1.0.3.\n" );
	exit( 1 );
}
if ( ! is_dir( $dist ) && ! mkdir( $dist, 0777, true ) && ! is_dir( $dist ) ) {
	fwrite( STDERR, "ERROR: Could not create dist directory.\n" );
	exit( 1 );
}

$archive = new ZipArchive();
if ( true !== $archive->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "ERROR: Could not create Migration Gate ZIP.\n" );
	exit( 1 );
}
$prefix = 'doughboss-migration-gate/';
$ok     = $archive->addFile( $main, $prefix . 'doughboss-migration-gate.php' )
	&& $archive->addFile( $readme, $prefix . 'README.md' );
$archive->close();
if ( ! $ok ) {
	fwrite( STDERR, "ERROR: Could not add all Migration Gate files.\n" );
	exit( 1 );
}

echo "Built {$zip_path}\n";
