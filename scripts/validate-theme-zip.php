<?php
/** Validate the canonical theme archive against the current source tree. */

if ( PHP_SAPI !== 'cli' || $argc !== 2 ) {
	fwrite( STDERR, "Usage: php scripts/validate-theme-zip.php <archive-path>\n" );
	exit( 1 );
}
if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "ERROR: PHP ZipArchive is required to validate the theme archive.\n" );
	exit( 1 );
}

function doughboss_theme_validate_fail( $message ) {
	fwrite( STDERR, "ERROR: {$message}\n" );
	exit( 1 );
}

$root       = dirname( __DIR__ );
$slug       = 'doughboss-final';
$theme_root = $root . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . $slug;
$path       = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $argv[1] );
if ( ! preg_match( '#^(?:[A-Za-z]:)?[\\\\/]#', $path ) ) {
	$path = $root . DIRECTORY_SEPARATOR . $path;
}
if ( ! is_file( $path ) ) {
	doughboss_theme_validate_fail( "archive not found: {$path}" );
}

$archive = new ZipArchive();
if ( true !== $archive->open( $path, ZipArchive::RDONLY ) ) {
	doughboss_theme_validate_fail( "could not open archive: {$path}" );
}
$entries = array();
for ( $index = 0; $index < $archive->numFiles; $index++ ) {
	$name = $archive->getNameIndex( $index );
	if ( ! is_string( $name ) || '' === $name || isset( $entries[ $name ] ) ) {
		$archive->close();
		doughboss_theme_validate_fail( 'archive contains an empty or duplicate entry' );
	}
	if ( false !== strpos( $name, '\\' ) || 0 !== strpos( $name, $slug . '/' ) || preg_match( '#(^|/)\.\.(/|$)#', $name ) ) {
		$archive->close();
		doughboss_theme_validate_fail( "unsafe or non-canonical archive entry: {$name}" );
	}
	$entries[ $name ] = true;
}

$expected = array();
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $theme_root, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ( $iterator as $file ) {
	if ( $file->isLink() || ! $file->isFile() ) {
		$archive->close();
		doughboss_theme_validate_fail( 'theme source contains a linked or non-file entry' );
	}
	$relative           = substr( $file->getPathname(), strlen( $theme_root ) + 1 );
	$entry              = $slug . '/' . str_replace( DIRECTORY_SEPARATOR, '/', $relative );
	$expected[ $entry ] = $file->getPathname();
}
ksort( $entries, SORT_STRING );
ksort( $expected, SORT_STRING );
if ( array_keys( $entries ) !== array_keys( $expected ) ) {
	$archive->close();
	doughboss_theme_validate_fail( 'archive entries do not exactly match the current theme tree' );
}
foreach ( $expected as $entry => $source ) {
	$archived = $archive->getFromName( $entry );
	$current  = file_get_contents( $source );
	if ( false === $archived || false === $current || ! hash_equals( hash( 'sha256', $current ), hash( 'sha256', $archived ) ) ) {
		$archive->close();
		doughboss_theme_validate_fail( "archive byte mismatch: {$entry}" );
	}
}

$style = $archive->getFromName( $slug . '/style.css' );
$php   = $archive->getFromName( $slug . '/functions.php' );
$archive->close();
$style_match    = array();
$constant_match = array();
preg_match( '/^Version:\s*([^\s]+)\s*$/mi', (string) $style, $style_match );
preg_match( "/define\\( 'DOUGHBOSS_FINAL_VERSION', '([^']+)' \\);/", (string) $php, $constant_match );
$style_version    = isset( $style_match[1] ) ? trim( $style_match[1] ) : '';
$constant_version = isset( $constant_match[1] ) ? trim( $constant_match[1] ) : '';
if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $style_version ) || $style_version !== $constant_version ) {
	doughboss_theme_validate_fail( "archived theme version mismatch (style={$style_version}, constant={$constant_version})" );
}

echo 'Theme archive layout and current-tree bytes are valid (' . $style_version . ', ' . count( $expected ) . " files): {$path}\n";
