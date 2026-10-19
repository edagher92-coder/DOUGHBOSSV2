<?php
/**
 * Validate the canonical, installable DoughBoss Growth Catering Box archive.
 *
 * Usage: php scripts/validate-zip.php path/to/doughboss-growth-box-0.2.0.zip
 *
 * The release archive has exactly 18 runtime files. This validator refuses an archive that is
 * unsafe, non-canonical, stale, incomplete, over budget, or different from that exact runtime
 * allow-list. It is intentionally separate from build-zip.php so a hosted artifact can be
 * independently checked after it has been produced.
 *
 * @package DoughBoss_Growth_Box
 */

if ( PHP_SAPI !== 'cli' || 2 !== $argc ) {
	fwrite( STDERR, "Usage: php scripts/validate-zip.php path/to/doughboss-growth-box.zip\n" );
	exit( 1 );
}
if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "ERROR: PHP ZipArchive is required to validate the plugin archive.\n" );
	exit( 1 );
}
if ( ! is_file( $argv[1] ) || filesize( $argv[1] ) < 1 ) {
	fwrite( STDERR, "ERROR: archive is missing or empty: {$argv[1]}\n" );
	exit( 1 );
}
require_once __DIR__ . '/budgets.php';

/**
 * Stop validation with a single actionable diagnostic.
 *
 * @param string $message Reason the archive cannot be released.
 * @return void
 */
function dbgrbox_validate_fail( $message ) {
	fwrite( STDERR, "ERROR: {$message}\n" );
	exit( 1 );
}

/**
 * Read and require matching release metadata from archived files.
 *
 * @param string $plugin Archived main plugin file.
 * @param string $readme Archived readme.
 * @return string Release version.
 */
function dbgrbox_validate_versions( $plugin, $readme ) {
	$header_match    = array();
	$constant_match  = array();
	$stable_match    = array();
	$changelog_match = array();
	preg_match( '/^ \* Version:\s*([^\s]+)\s*$/mi', $plugin, $header_match );
	preg_match( "/define\\( 'DBGRBOX_VERSION', '([^']+)' \\);/", $plugin, $constant_match );
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
			dbgrbox_validate_fail( "invalid or missing {$source} version metadata" );
		}
	}
	if ( count( array_unique( $versions ) ) !== 1 ) {
		dbgrbox_validate_fail( sprintf( 'archive version mismatch (header=%s, constant=%s, stable=%s, changelog=%s)', $versions['header'], $versions['constant'], $versions['stable'], $versions['changelog'] ) );
	}
	return $versions['header'];
}

/**
 * Return a reason that a relative archive path is unsafe or must not ship.
 *
 * @param string $relative Path below the plugin root.
 * @return string Empty when allowed.
 */
function dbgrbox_validate_forbidden_path( $relative ) {
	$segments = explode( '/', $relative );
	foreach ( $segments as $segment ) {
		if ( '' === $segment || '.' === $segment || '..' === $segment || ( '' !== $segment && '.' === $segment[0] ) ) {
			return 'hidden or unsafe path segment';
		}
	}
	$base = basename( $relative );
	if ( preg_match( '/\.(env|pem|key|crt|p12|sql|log|zip|bak|orig|swp|map|sh|mjs)$/i', $base ) ) {
		return 'forbidden file type';
	}
	return '';
}

$budget = DBGRBOX_Budgets::check_zip( $argv[1] );
if ( ! $budget['ok'] ) {
	dbgrbox_validate_fail( 'archive is over budget: ' . $budget['message'] );
}
$archive = new ZipArchive();
if ( true !== $archive->open( $argv[1] ) ) {
	dbgrbox_validate_fail( "could not open archive: {$argv[1]}" );
}

$slug   = 'doughboss-growth-box';
$prefix = $slug . '/';
// This is deliberately a fixed release allow-list rather than a directory glob. A new runtime
// file is a release change and must be intentionally added to both the builder and this gate.
$runtime_files = array(
	'doughboss-growth-box.php',
	'uninstall.php',
	'readme.txt',
	'content/copy.json',
	'includes/class-dbgrbox.php',
	'includes/class-dbgrbox-admin.php',
	'includes/class-dbgrbox-copy.php',
	'includes/class-dbgrbox-hero.php',
	'includes/class-dbgrbox-hero-media.php',
	'includes/class-dbgrbox-inject.php',
	'includes/class-dbgrbox-manifest.php',
	'includes/class-dbgrbox-render.php',
	'includes/class-dbgrbox-settings.php',
	'includes/class-dbgrbox-shortcodes.php',
	'public/css/dbgr-box.css',
	'public/css/dbgr-hero.css',
	'public/css/dbgr-strip.css',
	'public/js/dbgr-hero.js',
);
$expected = array();
$root     = dirname( __DIR__ );
foreach ( $runtime_files as $relative ) {
	$source = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
	if ( ! is_file( $source ) || is_link( $source ) ) {
		$archive->close();
		dbgrbox_validate_fail( "canonical runtime file is missing or linked: {$relative}" );
	}
	$expected[ $prefix . $relative ] = $source;
}

$entries = array();
for ( $index = 0; $index < $archive->numFiles; ++$index ) {
	$name = $archive->getNameIndex( $index );
	if ( false === $name || '' === $name || false !== strpos( $name, '\\' ) || false !== strpos( $name, '..' ) || 0 !== strpos( $name, $prefix ) ) {
		$archive->close();
		dbgrbox_validate_fail( "unsafe or noncanonical archive entry: {$name}" );
	}
	if ( isset( $entries[ $name ] ) ) {
		$archive->close();
		dbgrbox_validate_fail( "duplicate archive entry: {$name}" );
	}
	$relative = substr( $name, strlen( $prefix ) );
	if ( '' === $relative || '/' === substr( $relative, -1 ) ) {
		$archive->close();
		dbgrbox_validate_fail( "directory entries are not allowed: {$name}" );
	}
	$reason = dbgrbox_validate_forbidden_path( $relative );
	if ( '' !== $reason ) {
		$archive->close();
		dbgrbox_validate_fail( "{$reason} in archive: {$name}" );
	}
	if ( ! isset( $expected[ $name ] ) ) {
		$archive->close();
		dbgrbox_validate_fail( "non-runtime archive entry: {$name}" );
	}
	$operations_system = 0;
	$attributes        = 0;
	if ( false === $archive->getExternalAttributesIndex( $index, $operations_system, $attributes ) ) {
		$archive->close();
		dbgrbox_validate_fail( "unreadable archive entry attributes: {$name}" );
	}
	$entry_type = ( $attributes >> 16 ) & 0170000;
	if ( ZipArchive::OPSYS_UNIX === $operations_system && 0120000 === $entry_type ) {
		$archive->close();
		dbgrbox_validate_fail( "symbolic-link archive entry is not allowed: {$name}" );
	}
	// The canonical builder explicitly emits Unix regular files on every host.
	if ( ZipArchive::OPSYS_UNIX !== $operations_system || 0100000 !== $entry_type || 0 !== ( $attributes & 0x10 ) ) {
		$archive->close();
		dbgrbox_validate_fail( "non-regular or unsupported archive entry type: {$name}" );
	}
	$entries[ $name ] = true;
}

$entry_names    = array_keys( $entries );
$expected_names = array_keys( $expected );
sort( $entry_names, SORT_STRING );
sort( $expected_names, SORT_STRING );
if ( $entry_names !== $expected_names ) {
	$archive->close();
	$missing    = array_values( array_diff( $expected_names, $entry_names ) );
	$unexpected = array_values( array_diff( $entry_names, $expected_names ) );
	dbgrbox_validate_fail( 'archive canonical-runtime mismatch (missing=' . count( $missing ) . ', unexpected=' . count( $unexpected ) . ')' );
}

// Every shipped PHP file must retain the appropriate direct-access guard.
foreach ( $expected_names as $name ) {
	if ( '.php' !== strtolower( substr( $name, -4 ) ) ) {
		continue;
	}
	$body  = $archive->getFromName( $name );
	$guard = ( $prefix . 'uninstall.php' === $name ) ? "/defined\\(\\s*'WP_UNINSTALL_PLUGIN'\\s*\\)/" : "/defined\\(\\s*'ABSPATH'\\s*\\)/";
	if ( false === $body || ! preg_match( $guard, $body ) ) {
		$archive->close();
		dbgrbox_validate_fail( "PHP file lacks its direct-access guard: {$name}" );
	}
}

$plugin = $archive->getFromName( $prefix . 'doughboss-growth-box.php' );
$readme = $archive->getFromName( $prefix . 'readme.txt' );
if ( false === $plugin || false === $readme ) {
	$archive->close();
	dbgrbox_validate_fail( 'could not read archive version metadata' );
}
$version = dbgrbox_validate_versions( $plugin, $readme );

// Layout-only validation can bless a stale but installable zip after a source change. Require a
// byte-for-byte snapshot of this release's canonical source files.
foreach ( $expected as $entry => $source ) {
	$archived = $archive->getFromName( $entry );
	$current  = file_get_contents( $source );
	if ( false === $archived || false === $current || ! hash_equals( hash( 'sha256', $current ), hash( 'sha256', $archived ) ) ) {
		$archive->close();
		dbgrbox_validate_fail( "archive/current-tree content mismatch: {$entry}" );
	}
}
$archive->close();


echo "Archive layout, canonical runtime bytes and budget are valid ({$version}, " . count( $entries ) . ' files, ' . $budget['message'] . "): {$argv[1]}\n";
