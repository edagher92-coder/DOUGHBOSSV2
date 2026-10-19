<?php
/** Build a canonical, installable DoughBoss Final theme archive. */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "ERROR: This script must run from the command line.\n" );
	exit( 1 );
}
if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "ERROR: PHP ZipArchive is required to build the theme archive.\n" );
	exit( 1 );
}
if ( $argc > 2 ) {
	fwrite( STDERR, "Usage: php scripts/build-theme-zip.php [output-path]\n" );
	exit( 1 );
}

function doughboss_theme_build_fail( $message ) {
	fwrite( STDERR, "ERROR: {$message}\n" );
	exit( 1 );
}

$root         = dirname( __DIR__ );
$slug         = 'doughboss-final';
$theme_root   = $root . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . $slug;
$default_path = $root . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . $slug . '-review-candidate.zip';
$output_path  = $argc === 2 ? str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $argv[1] ) : $default_path;
if ( ! preg_match( '#^(?:[A-Za-z]:)?[\\\\/]#', $output_path ) ) {
	$output_path = $root . DIRECTORY_SEPARATOR . $output_path;
}

$style = @file_get_contents( $theme_root . DIRECTORY_SEPARATOR . 'style.css' );
$php   = @file_get_contents( $theme_root . DIRECTORY_SEPARATOR . 'functions.php' );
if ( false === $style || false === $php ) {
	doughboss_theme_build_fail( 'could not read required theme entrypoints' );
}
$style_match    = array();
$constant_match = array();
preg_match( '/^Version:\s*([^\s]+)\s*$/mi', $style, $style_match );
preg_match( "/define\\( 'DOUGHBOSS_FINAL_VERSION', '([^']+)' \\);/", $php, $constant_match );
$style_version    = isset( $style_match[1] ) ? trim( $style_match[1] ) : '';
$constant_version = isset( $constant_match[1] ) ? trim( $constant_match[1] ) : '';
if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $style_version ) || $style_version !== $constant_version ) {
	doughboss_theme_build_fail( "theme version mismatch (style={$style_version}, constant={$constant_version})" );
}
if ( file_exists( $output_path ) ) {
	doughboss_theme_build_fail( "refusing to replace existing archive: {$output_path}" );
}
$output_dir = dirname( $output_path );
if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0777, true ) && ! is_dir( $output_dir ) ) {
	doughboss_theme_build_fail( "could not create archive directory: {$output_dir}" );
}
if ( ! is_dir( $theme_root ) || is_link( $theme_root ) ) {
	doughboss_theme_build_fail( 'theme source directory is missing or linked' );
}

$payload  = array();
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $theme_root, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ( $iterator as $file ) {
	if ( $file->isLink() ) {
		doughboss_theme_build_fail( 'linked theme file is not allowed: ' . $file->getPathname() );
	}
	if ( ! $file->isFile() ) {
		continue;
	}
	$relative = substr( $file->getPathname(), strlen( $theme_root ) + 1 );
	$entry    = $slug . '/' . str_replace( DIRECTORY_SEPARATOR, '/', $relative );
	if ( false !== strpos( $entry, '\\' ) || false !== strpos( $entry, '..' ) ) {
		doughboss_theme_build_fail( "unsafe theme path: {$entry}" );
	}
	$payload[ $entry ] = $file->getPathname();
}
foreach ( array( $slug . '/style.css', $slug . '/functions.php', $slug . '/index.php' ) as $required ) {
	if ( ! isset( $payload[ $required ] ) ) {
		doughboss_theme_build_fail( "required theme entry is missing: {$required}" );
	}
}

ksort( $payload, SORT_STRING );
$temporary_base = tempnam( $output_dir, '.doughboss-theme-build-' );
if ( false === $temporary_base || ! unlink( $temporary_base ) ) {
	doughboss_theme_build_fail( 'could not prepare temporary theme archive' );
}
$temporary_path = $temporary_base . '.zip';
$archive        = new ZipArchive();
if ( true !== $archive->open( $temporary_path, ZipArchive::CREATE ) ) {
	doughboss_theme_build_fail( "could not create temporary archive: {$temporary_path}" );
}
foreach ( $payload as $entry => $source ) {
	if ( ! $archive->addFile( $source, $entry ) ) {
		$archive->close();
		@unlink( $temporary_path );
		doughboss_theme_build_fail( "could not add archive entry: {$entry}" );
	}
}
if ( ! $archive->close() || ! rename( $temporary_path, $output_path ) ) {
	@unlink( $temporary_path );
	doughboss_theme_build_fail( 'could not finish theme archive' );
}

echo "Built theme archive ({$style_version}): {$output_path}\n";
