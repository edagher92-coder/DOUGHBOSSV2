<?php
/**
 * Build the canonical, installable DoughBoss Growth archive.
 *
 * Usage: php scripts/build-zip.php [output-path]      (default: dist/doughboss-growth-<version>.zip)
 *
 * The builder whitelists the WordPress plugin runtime payload (the main file, uninstall.php, readme.txt and
 * the includes, admin, public, content and languages directories). It refuses to replace an existing
 * archive, refuses a release version mismatch (plugin header, DOUGHBOSS_GROWTH_VERSION, readme "Stable tag",
 * first changelog heading), refuses hidden or secret-looking files and refuses an archive over the byte
 * budget (scripts/budgets.php). Entries are sorted and carry fixed metadata so two builds of the same tree
 * are byte-identical on PHP 8.0 and later.
 *
 * @package DoughBoss_Growth
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
require_once __DIR__ . '/budgets.php';

$dbgr_root = dirname( __DIR__ );
$dbgr_slug = 'doughboss-growth';

/** Fixed timestamp for archive entries (2026-01-01 00:00:00 UTC). */
const DBGR_ZIP_MTIME = 1767225600;

function dbgr_build_fail( $message ) {
	fwrite( STDERR, "ERROR: {$message}\n" );
	exit( 1 );
}

function dbgr_build_read_version( $plugin, $readme ) {
	$header_match    = array();
	$constant_match  = array();
	$stable_match    = array();
	$changelog_match = array();

	preg_match( '/^ \* Version:\s*([^\s]+)\s*$/mi', $plugin, $header_match );
	preg_match( "/define\\( 'DOUGHBOSS_GROWTH_VERSION', '([^']+)' \\);/", $plugin, $constant_match );
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
			dbgr_build_fail( "invalid or missing {$source} version metadata" );
		}
	}
	if ( count( array_unique( $versions ) ) !== 1 ) {
		dbgr_build_fail( sprintf( 'release version mismatch (header=%s, constant=%s, stable=%s, changelog=%s)', $versions['header'], $versions['constant'], $versions['stable'], $versions['changelog'] ) );
	}
	return $versions['header'];
}

/** Files that must never travel in a plugin archive. */
function dbgr_build_forbidden( $relative ) {
	$base = basename( $relative );
	if ( '' !== $base && '.' === $base[0] ) {
		return 'hidden file';
	}
	if ( preg_match( '/\.(env|pem|key|crt|p12|sql|log|zip|bak|orig|swp|map|sh|mjs)$/i', $base ) ) {
		return 'forbidden file type';
	}
	return '';
}

$plugin_path = $dbgr_root . DIRECTORY_SEPARATOR . 'doughboss-growth.php';
$readme_path = $dbgr_root . DIRECTORY_SEPARATOR . 'readme.txt';
$plugin      = @file_get_contents( $plugin_path );
$readme      = @file_get_contents( $readme_path );
if ( false === $plugin || false === $readme ) {
	dbgr_build_fail( 'could not read required plugin metadata' );
}
$version = dbgr_build_read_version( $plugin, $readme );
if ( ! preg_match( "/define\\( 'DOUGHBOSS_GROWTH_DB_VERSION', '\\d+\\.\\d+\\.\\d+' \\);/", $plugin ) ) {
	dbgr_build_fail( 'invalid or missing DOUGHBOSS_GROWTH_DB_VERSION' );
}

$default_path = $dbgr_root . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . $dbgr_slug . '-' . $version . '.zip';
$output_path  = ( 2 === $argc ) ? $argv[1] : $default_path;
$output_path  = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $output_path );
if ( ! preg_match( '#^(?:[A-Za-z]:)?[\\\\/]#', $output_path ) ) {
	$output_path = getcwd() . DIRECTORY_SEPARATOR . $output_path;
}
$output_dir = dirname( $output_path );
if ( file_exists( $output_path ) ) {
	dbgr_build_fail( "refusing to replace existing archive: {$output_path}" );
}
if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0777, true ) && ! is_dir( $output_dir ) ) {
	dbgr_build_fail( "could not create archive directory: {$output_dir}" );
}

$root_files  = array( 'doughboss-growth.php', 'uninstall.php', 'readme.txt' );
$required    = array( 'includes', 'admin' );
$optional    = array( 'public', 'content', 'languages' );
$directories = $required;
foreach ( $optional as $directory ) {
	if ( is_dir( $dbgr_root . DIRECTORY_SEPARATOR . $directory ) ) {
		$directories[] = $directory;
	}
}

$payload = array();
foreach ( $root_files as $file ) {
	$source = $dbgr_root . DIRECTORY_SEPARATOR . $file;
	if ( ! is_file( $source ) || is_link( $source ) ) {
		dbgr_build_fail( "required runtime file is missing or linked: {$file}" );
	}
	$payload[ $dbgr_slug . '/' . $file ] = $source;
}
foreach ( $directories as $directory ) {
	$source_directory = $dbgr_root . DIRECTORY_SEPARATOR . $directory;
	if ( ! is_dir( $source_directory ) || is_link( $source_directory ) ) {
		dbgr_build_fail( "required runtime directory is missing or linked: {$directory}" );
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source_directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $iterator as $file ) {
		if ( $file->isLink() ) {
			dbgr_build_fail( 'linked runtime file is not allowed: ' . $file->getPathname() );
		}
		if ( ! $file->isFile() ) {
			continue;
		}
		$relative = substr( $file->getPathname(), strlen( $source_directory ) + 1 );
		$entry    = $dbgr_slug . '/' . $directory . '/' . str_replace( DIRECTORY_SEPARATOR, '/', $relative );
		if ( false !== strpos( $entry, '\\' ) || false !== strpos( $entry, '..' ) ) {
			dbgr_build_fail( "unsafe runtime path: {$entry}" );
		}
		$reason = dbgr_build_forbidden( $relative );
		if ( '' !== $reason ) {
			dbgr_build_fail( "{$reason} is not allowed in the archive: {$entry}" );
		}
		$payload[ $entry ] = $file->getPathname();
	}
}

ksort( $payload, SORT_STRING );
$temporary_base = tempnam( $output_dir, '.dbgr-build-' );
if ( false === $temporary_base ) {
	dbgr_build_fail( "could not create temporary archive in: {$output_dir}" );
}
if ( ! unlink( $temporary_base ) ) {
	dbgr_build_fail( "could not prepare temporary archive: {$temporary_base}" );
}
$temporary_path = $temporary_base . '.zip';
$archive        = new ZipArchive();
if ( true !== $archive->open( $temporary_path, ZipArchive::CREATE ) ) {
	dbgr_build_fail( "could not create temporary archive: {$temporary_path}" );
}
foreach ( $payload as $entry => $source ) {
	if ( ! $archive->addFile( $source, $entry ) ) {
		$archive->close();
		@unlink( $temporary_path );
		dbgr_build_fail( "could not add archive entry: {$entry}" );
	}
	if ( method_exists( $archive, 'setMtimeName' ) ) {
		$archive->setMtimeName( $entry, DBGR_ZIP_MTIME );
	}
	$archive->setExternalAttributesName( $entry, ZipArchive::OPSYS_UNIX, 0100644 << 16 );
}
if ( ! $archive->close() ) {
	@unlink( $temporary_path );
	dbgr_build_fail( 'could not finish archive' );
}
$budget = DBGR_Budgets::check_zip( $temporary_path );
if ( ! $budget['ok'] ) {
	@unlink( $temporary_path );
	dbgr_build_fail( 'archive is over budget: ' . $budget['message'] );
}
if ( ! rename( $temporary_path, $output_path ) ) {
	@unlink( $temporary_path );
	dbgr_build_fail( "could not move completed archive to: {$output_path}" );
}

echo "Built DoughBoss Growth archive ({$version}, " . count( $payload ) . ' files, ' . $budget['message'] . "): {$output_path}\n";
