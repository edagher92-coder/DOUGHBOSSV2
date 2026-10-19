<?php
/** Validate a canonical, installable DoughBoss plugin archive. */

if ( PHP_SAPI !== 'cli' || 2 !== $argc ) {
	fwrite( STDERR, "Usage: php scripts/validate-zip.php path/to/doughboss.zip\n" );
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

function doughboss_validate_fail( $message ) {
	fwrite( STDERR, "ERROR: {$message}\n" );
	exit( 1 );
}

function doughboss_validate_versions( $plugin, $readme ) {
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
			doughboss_validate_fail( "invalid or missing {$source} version metadata" );
		}
	}
	if ( count( array_unique( $versions ) ) !== 1 ) {
		doughboss_validate_fail( sprintf( 'archive version mismatch (header=%s, constant=%s, stable=%s, changelog=%s)', $versions['header'], $versions['constant'], $versions['stable'], $versions['changelog'] ) );
	}
	return $versions['header'];
}

$archive = new ZipArchive();
if ( true !== $archive->open( $argv[1] ) ) {
	doughboss_validate_fail( "could not open archive: {$argv[1]}" );
}

$root_files = array(
	'doughboss/doughboss.php',
	'doughboss/uninstall.php',
	'doughboss/readme.txt',
	'doughboss/THIRD_PARTY_NOTICES.md',
);
$allowed_directories = array( 'includes', 'admin', 'public', 'languages' );
$required_entries = array(
	'doughboss/doughboss.php',
	'doughboss/includes/class-doughboss.php',
	'doughboss/public/js/doughboss.js',
);
$entries = array();

for ( $index = 0; $index < $archive->numFiles; ++$index ) {
	$name = $archive->getNameIndex( $index );
	if ( false === $name || '' === $name || false !== strpos( $name, '\\' ) || false !== strpos( $name, '..' ) || 0 !== strpos( $name, 'doughboss/' ) ) {
		$archive->close();
		doughboss_validate_fail( "unsafe or noncanonical archive entry: {$name}" );
	}
	if ( isset( $entries[ $name ] ) ) {
		$archive->close();
		doughboss_validate_fail( "duplicate archive entry: {$name}" );
	}
	$relative = substr( $name, strlen( 'doughboss/' ) );
	if ( '' === $relative || '/' === substr( $relative, -1 ) ) {
		$archive->close();
		doughboss_validate_fail( "directory entries are not allowed: {$name}" );
	}
	$first_segment = strtok( $relative, '/' );
	if ( false === $first_segment || ( ! in_array( $name, $root_files, true ) && ! in_array( $first_segment, $allowed_directories, true ) ) ) {
		$archive->close();
		doughboss_validate_fail( "non-runtime archive entry: {$name}" );
	}
	$entries[ $name ] = true;
}

foreach ( $required_entries as $required ) {
	if ( ! isset( $entries[ $required ] ) ) {
		$archive->close();
		doughboss_validate_fail( "required archive entry missing: {$required}" );
	}
}

// Prove that the archive is an exact snapshot of the current whitelisted
// runtime tree. Layout-only validation can otherwise bless a stale but
// installable ZIP after source changes.
$root     = dirname( __DIR__ );
$expected = array();
foreach ( $root_files as $entry ) {
	$relative = substr( $entry, strlen( 'doughboss/' ) );
	$source   = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
	if ( ! is_file( $source ) || is_link( $source ) ) {
		$archive->close();
		doughboss_validate_fail( "required runtime file is missing or linked: {$relative}" );
	}
	$expected[ $entry ] = $source;
}
foreach ( $allowed_directories as $directory ) {
	$source_directory = $root . DIRECTORY_SEPARATOR . $directory;
	if ( ! is_dir( $source_directory ) ) {
		if ( 'languages' === $directory ) {
			continue;
		}
		$archive->close();
		doughboss_validate_fail( "required runtime directory is missing: {$directory}" );
	}
	if ( is_link( $source_directory ) ) {
		$archive->close();
		doughboss_validate_fail( "linked runtime directory is not allowed: {$directory}" );
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source_directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $iterator as $file ) {
		if ( $file->isLink() ) {
			$archive->close();
			doughboss_validate_fail( 'linked runtime file is not allowed: ' . $file->getPathname() );
		}
		if ( ! $file->isFile() ) {
			continue;
		}
		$relative = substr( $file->getPathname(), strlen( $source_directory ) + 1 );
		$entry    = 'doughboss/' . $directory . '/' . str_replace( DIRECTORY_SEPARATOR, '/', $relative );
		$expected[ $entry ] = $file->getPathname();
	}
}

$entry_names    = array_keys( $entries );
$expected_names = array_keys( $expected );
sort( $entry_names, SORT_STRING );
sort( $expected_names, SORT_STRING );
if ( $entry_names !== $expected_names ) {
	$archive->close();
	$missing    = array_values( array_diff( $expected_names, $entry_names ) );
	$unexpected = array_values( array_diff( $entry_names, $expected_names ) );
	doughboss_validate_fail( 'archive/current-tree file mismatch (missing=' . count( $missing ) . ', unexpected=' . count( $unexpected ) . ')' );
}
foreach ( $expected as $entry => $source ) {
	$archived = $archive->getFromName( $entry );
	$current  = file_get_contents( $source );
	if ( false === $archived || false === $current || ! hash_equals( hash( 'sha256', $current ), hash( 'sha256', $archived ) ) ) {
		$archive->close();
		doughboss_validate_fail( "archive/current-tree content mismatch: {$entry}" );
	}
}

$plugin = $archive->getFromName( 'doughboss/doughboss.php' );
$readme = $archive->getFromName( 'doughboss/readme.txt' );
$archive->close();
if ( false === $plugin || false === $readme ) {
	doughboss_validate_fail( 'could not read archive version metadata' );
}
$version = doughboss_validate_versions( $plugin, $readme );

echo "Archive layout and current-tree bytes are valid ({$version}, " . count( $entries ) . " files): {$argv[1]}\n";
