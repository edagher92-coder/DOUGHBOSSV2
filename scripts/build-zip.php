<?php
/**
 * Build a canonical, installable DoughBoss review-candidate archive.
 *
 * Usage: php scripts/build-zip.php [output-path]
 *
 * The builder intentionally whitelists the WordPress plugin runtime payload.
 * It never replaces an existing archive or removes an existing dist directory.
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "ERROR: This script must run from the command line.\n" );
	exit( 1 );
}
if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "ERROR: PHP ZipArchive is required to build the plugin archive.\n" );
	exit( 1 );
}
if ( $argc > 2 ) {
	fwrite( STDERR, "Usage: php scripts/build-zip.php [output-path]\n" );
	exit( 1 );
}

$root         = dirname( __DIR__ );
$slug         = 'doughboss';
$default_path = $root . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'doughboss-review-candidate.zip';
$output_path  = $argc === 2 ? $argv[1] : $default_path;

function doughboss_build_fail( $message ) {
	fwrite( STDERR, "ERROR: {$message}\n" );
	exit( 1 );
}

function doughboss_build_normalize_path( $path ) {
	return str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $path );
}

function doughboss_build_read_version( $plugin, $readme ) {
	$header_match    = array();
	$constant_match  = array();
	$stable_match    = array();
	$changelog_match = array();

	preg_match( '/^ \* Version:\s*([^\s]+)\s*$/mi', $plugin, $header_match );
	preg_match( "/define\\( 'DOUGHBOSS_VERSION', '([^']+)' \\);/", $plugin, $constant_match );
	preg_match( '/^Stable tag:\s*(\S+)\s*$/mi', $readme, $stable_match );
	preg_match( '/^== Changelog ==\r?\n\r?\n= ([0-9.]+) =\r?$/m', $readme, $changelog_match );

	$versions = array(
		'header'    => isset( $header_match[1] ) ? trim( $header_match[1] ) : '',
		'constant'  => isset( $constant_match[1] ) ? trim( $constant_match[1] ) : '',
		'stable'    => isset( $stable_match[1] ) ? trim( $stable_match[1] ) : '',
		'changelog' => isset( $changelog_match[1] ) ? trim( $changelog_match[1] ) : '',
	);
	foreach ( $versions as $source => $version ) {
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			doughboss_build_fail( "invalid or missing {$source} version metadata" );
		}
	}
	if ( count( array_unique( $versions ) ) !== 1 ) {
		doughboss_build_fail( sprintf( 'release version mismatch (header=%s, constant=%s, stable=%s, changelog=%s)', $versions['header'], $versions['constant'], $versions['stable'], $versions['changelog'] ) );
	}
	return $versions['header'];
}

$plugin_path = $root . DIRECTORY_SEPARATOR . 'doughboss.php';
$readme_path = $root . DIRECTORY_SEPARATOR . 'readme.txt';
$plugin      = @file_get_contents( $plugin_path );
$readme      = @file_get_contents( $readme_path );
if ( false === $plugin || false === $readme ) {
	doughboss_build_fail( 'could not read required plugin metadata' );
}
$version = doughboss_build_read_version( $plugin, $readme );

$output_path = doughboss_build_normalize_path( $output_path );
if ( ! preg_match( '#^(?:[A-Za-z]:)?[\\\\/]#', $output_path ) ) {
	$output_path = $root . DIRECTORY_SEPARATOR . $output_path;
}
$output_dir = dirname( $output_path );
if ( file_exists( $output_path ) ) {
	doughboss_build_fail( "refusing to replace existing archive: {$output_path}" );
}
if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0777, true ) && ! is_dir( $output_dir ) ) {
	doughboss_build_fail( "could not create archive directory: {$output_dir}" );
}

$root_files  = array( 'doughboss.php', 'uninstall.php', 'readme.txt', 'THIRD_PARTY_NOTICES.md' );
$directories = array( 'includes', 'admin', 'public' );
if ( is_dir( $root . DIRECTORY_SEPARATOR . 'languages' ) ) {
	$directories[] = 'languages';
}

$payload = array();
foreach ( $root_files as $file ) {
	$source = $root . DIRECTORY_SEPARATOR . $file;
	if ( ! is_file( $source ) || is_link( $source ) ) {
		doughboss_build_fail( "required runtime file is missing or linked: {$file}" );
	}
	$payload[ $slug . '/' . $file ] = $source;
}
foreach ( $directories as $directory ) {
	$source_directory = $root . DIRECTORY_SEPARATOR . $directory;
	if ( ! is_dir( $source_directory ) || is_link( $source_directory ) ) {
		doughboss_build_fail( "required runtime directory is missing or linked: {$directory}" );
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source_directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $iterator as $file ) {
		if ( $file->isLink() ) {
			doughboss_build_fail( 'linked runtime file is not allowed: ' . $file->getPathname() );
		}
		if ( ! $file->isFile() ) {
			continue;
		}
		$relative = substr( $file->getPathname(), strlen( $source_directory ) + 1 );
		$entry    = $slug . '/' . $directory . '/' . str_replace( DIRECTORY_SEPARATOR, '/', $relative );
		if ( false !== strpos( $entry, '\\' ) || false !== strpos( $entry, '..' ) ) {
			doughboss_build_fail( "unsafe runtime path: {$entry}" );
		}
		$payload[ $entry ] = $file->getPathname();
	}
}

ksort( $payload, SORT_STRING );
$temporary_base = tempnam( $output_dir, '.doughboss-build-' );
if ( false === $temporary_base ) {
	doughboss_build_fail( "could not create temporary archive in: {$output_dir}" );
}
if ( ! unlink( $temporary_base ) ) {
	doughboss_build_fail( "could not prepare temporary archive: {$temporary_base}" );
}
$temporary_path = $temporary_base . '.zip';
$archive        = new ZipArchive();
if ( true !== $archive->open( $temporary_path, ZipArchive::CREATE ) ) {
	doughboss_build_fail( "could not create temporary archive: {$temporary_path}" );
}
foreach ( $payload as $entry => $source ) {
	if ( ! $archive->addFile( $source, $entry ) ) {
		$archive->close();
		@unlink( $temporary_path );
		doughboss_build_fail( "could not add archive entry: {$entry}" );
	}
}
if ( ! $archive->close() ) {
	@unlink( $temporary_path );
	doughboss_build_fail( 'could not finish archive' );
}
if ( ! rename( $temporary_path, $output_path ) ) {
	@unlink( $temporary_path );
	doughboss_build_fail( "could not move completed archive to: {$output_path}" );
}

echo "Built review-candidate archive ({$version}): {$output_path}\n";
