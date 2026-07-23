<?php
/**
 * Cross-platform DoughBoss release archive builder.
 *
 * Run with: php scripts/build-zip.php
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must run from the command line.\n" );
	exit( 1 );
}
if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "ERROR: PHP ZipArchive is required to build the plugin archive.\n" );
	exit( 1 );
}

$root = dirname( __DIR__ );
$slug = 'doughboss';
$dist = $root . DIRECTORY_SEPARATOR . 'dist';
$stage = $dist . DIRECTORY_SEPARATOR . $slug;
$zip_path = $dist . DIRECTORY_SEPARATOR . $slug . '.zip';

function doughboss_build_fail( $message ) {
	fwrite( STDERR, "ERROR: {$message}\n" );
	exit( 1 );
}

function doughboss_build_remove_tree( $path ) {
	if ( ! file_exists( $path ) ) { return; }
	if ( is_file( $path ) || is_link( $path ) ) {
		if ( ! unlink( $path ) ) { doughboss_build_fail( "could not remove {$path}" ); }
		return;
	}
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		$item_path = $item->getPathname();
		if ( $item->isDir() && ! $item->isLink() ) {
			if ( ! rmdir( $item_path ) ) { doughboss_build_fail( "could not remove {$item_path}" ); }
		} elseif ( ! unlink( $item_path ) ) {
			doughboss_build_fail( "could not remove {$item_path}" );
		}
	}
	if ( ! rmdir( $path ) ) { doughboss_build_fail( "could not remove {$path}" ); }
}

function doughboss_build_copy( $source, $destination ) {
	$directory = dirname( $destination );
	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) && ! is_dir( $directory ) ) {
		doughboss_build_fail( "could not create {$directory}" );
	}
	if ( ! copy( $source, $destination ) ) { doughboss_build_fail( "could not copy {$source}" ); }
}

$plugin = file_get_contents( $root . DIRECTORY_SEPARATOR . 'doughboss.php' );
$readme = file_get_contents( $root . DIRECTORY_SEPARATOR . 'readme.txt' );
if ( false === $plugin || false === $readme ) { doughboss_build_fail( 'could not read release metadata' ); }
preg_match( "/define\\( 'DOUGHBOSS_VERSION', '([0-9.]+)' \\);/", $plugin, $version_match );
preg_match( '/^Stable tag:\s*(\S+)\s*$/mi', $readme, $stable_match );
preg_match( '/^== Changelog ==\s*$.*?^=\s*([^=]+?)\s*=$/ms', $readme, $changelog_match );
$version = isset( $version_match[1] ) ? $version_match[1] : '';
$stable = isset( $stable_match[1] ) ? $stable_match[1] : '';
$changelog = isset( $changelog_match[1] ) ? trim( $changelog_match[1] ) : '';
if ( '' === $version || $version !== $stable || $version !== $changelog ) {
	doughboss_build_fail( "release version mismatch (plugin={$version}, stable={$stable}, changelog={$changelog})" );
}

if ( ! is_dir( $dist ) && ! mkdir( $dist, 0777, true ) && ! is_dir( $dist ) ) { doughboss_build_fail( "could not create {$dist}" ); }
doughboss_build_remove_tree( $stage );
if ( file_exists( $zip_path ) && ! unlink( $zip_path ) ) { doughboss_build_fail( "could not replace {$zip_path}" ); }
if ( ! mkdir( $stage, 0777, true ) && ! is_dir( $stage ) ) { doughboss_build_fail( "could not create {$stage}" ); }

foreach ( array( 'doughboss.php', 'uninstall.php', 'readme.txt', 'README.md', 'THIRD_PARTY_NOTICES.md' ) as $file ) {
	doughboss_build_copy( $root . DIRECTORY_SEPARATOR . $file, $stage . DIRECTORY_SEPARATOR . $file );
}
$directories = array( 'includes', 'admin', 'public' );
if ( is_dir( $root . DIRECTORY_SEPARATOR . 'languages' ) ) { $directories[] = 'languages'; }
foreach ( $directories as $directory ) {
	$source = $root . DIRECTORY_SEPARATOR . $directory;
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $item ) {
		if ( ! $item->isFile() || $item->isLink() ) { continue; }
		$relative = substr( $item->getPathname(), strlen( $source ) + 1 );
		doughboss_build_copy( $item->getPathname(), $stage . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $relative );
	}
}
if ( is_file( $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'seed-menu.php' ) ) {
	doughboss_build_copy( $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'seed-menu.php', $stage . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'seed-menu.php' );
}

$php_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $stage, FilesystemIterator::SKIP_DOTS ) );
foreach ( $php_files as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file->getPathname() ), $output, $code );
		if ( 0 !== $code ) { doughboss_build_fail( "PHP syntax check failed for {$file->getPathname()}" ); }
	}
}

$archive = new ZipArchive();
if ( true !== $archive->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) { doughboss_build_fail( "could not create {$zip_path}" ); }
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $stage, FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file ) {
	if ( ! $file->isFile() || $file->isLink() ) { continue; }
	$relative = substr( $file->getPathname(), strlen( $stage ) + 1 );
	$archive_name = $slug . '/' . str_replace( DIRECTORY_SEPARATOR, '/', $relative );
	if ( ! $archive->addFile( $file->getPathname(), $archive_name ) ) {
		$archive->close();
		doughboss_build_fail( "could not archive {$archive_name}" );
	}
}
if ( ! $archive->close() ) { doughboss_build_fail( 'could not finish archive' ); }
doughboss_build_remove_tree( $stage );
echo "Built {$zip_path}\n";
