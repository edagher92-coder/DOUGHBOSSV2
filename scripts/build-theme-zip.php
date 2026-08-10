<?php
/** Build the production DoughBoss Final theme archive. */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must run from the command line.\n" );
	exit( 1 );
}
if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "ERROR: PHP ZipArchive is required.\n" );
	exit( 1 );
}

$root       = dirname( __DIR__ );
$theme_slug = 'doughboss-final';
$theme_root = $root . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . $theme_slug;
$dist       = $root . DIRECTORY_SEPARATOR . 'dist';
$zip_path   = $dist . DIRECTORY_SEPARATOR . $theme_slug . '.zip';

if ( ! is_dir( $theme_root ) ) {
	fwrite( STDERR, "ERROR: theme source was not found.\n" );
	exit( 1 );
}
if ( ! is_dir( $dist ) && ! mkdir( $dist, 0777, true ) && ! is_dir( $dist ) ) {
	fwrite( STDERR, "ERROR: dist could not be created.\n" );
	exit( 1 );
}

$style = file_get_contents( $theme_root . DIRECTORY_SEPARATOR . 'style.css' );
if ( false === $style || 1 !== preg_match( '/^Version:\s*1\.3\.0\s*$/mi', $style ) ) {
	fwrite( STDERR, "ERROR: expected DoughBoss Final theme version 1.3.0.\n" );
	exit( 1 );
}

$files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $theme_root, FilesystemIterator::SKIP_DOTS )
);
foreach ( $files as $file ) {
	if ( $file->isFile() && ! $file->isLink() && 'php' === strtolower( $file->getExtension() ) ) {
		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file->getPathname() ), $output, $code );
		if ( 0 !== $code ) {
			fwrite( STDERR, 'ERROR: PHP syntax check failed for ' . $file->getPathname() . "\n" );
			exit( 1 );
		}
	}
}

$archive = new ZipArchive();
if ( true !== $archive->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "ERROR: theme archive could not be created.\n" );
	exit( 1 );
}
$files->rewind();
foreach ( $files as $file ) {
	if ( ! $file->isFile() || $file->isLink() ) { continue; }
	$relative = substr( $file->getPathname(), strlen( $theme_root ) + 1 );
	$archive_name = $theme_slug . '/' . str_replace( DIRECTORY_SEPARATOR, '/', $relative );
	if ( ! $archive->addFile( $file->getPathname(), $archive_name ) ) {
		$archive->close();
		fwrite( STDERR, "ERROR: {$archive_name} could not be archived.\n" );
		exit( 1 );
	}
}
if ( ! $archive->close() ) {
	fwrite( STDERR, "ERROR: theme archive could not be finalized.\n" );
	exit( 1 );
}

echo "Built {$zip_path}\n";
