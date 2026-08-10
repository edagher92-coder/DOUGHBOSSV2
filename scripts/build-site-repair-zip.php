<?php
/** Build the one-purpose DoughBoss 2.34.1 site-repair helper archive. */

if ( PHP_SAPI !== 'cli' || ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "ERROR: CLI PHP with ZipArchive is required.\n" );
	exit( 1 );
}

$root = dirname( __DIR__ );
$slug = 'doughboss-site-repair-2341';
$source = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . $slug . '.php';
$dist = $root . DIRECTORY_SEPARATOR . 'dist';
$target = $dist . DIRECTORY_SEPARATOR . $slug . '.zip';

if ( ! is_file( $source ) ) {
	fwrite( STDERR, "ERROR: site-repair helper source is missing.\n" );
	exit( 1 );
}
if ( ! is_dir( $dist ) && ! mkdir( $dist, 0777, true ) && ! is_dir( $dist ) ) {
	fwrite( STDERR, "ERROR: dist could not be created.\n" );
	exit( 1 );
}

$archive = new ZipArchive();
if ( true !== $archive->open( $target, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "ERROR: site-repair archive could not be created.\n" );
	exit( 1 );
}
if ( ! $archive->addFile( $source, $slug . '/' . $slug . '.php' ) || ! $archive->close() ) {
	fwrite( STDERR, "ERROR: site-repair archive could not be finalized.\n" );
	exit( 1 );
}

echo "Built {$target}\n";
