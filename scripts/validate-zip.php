<?php
/** Validate the installable plugin archive layout on every platform. */

if ( PHP_SAPI !== 'cli' || 2 !== $argc ) {
	fwrite( STDERR, "Usage: php scripts/validate-zip.php dist/doughboss.zip\n" );
	exit( 1 );
}
if ( ! class_exists( 'ZipArchive' ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "ERROR: archive or PHP ZipArchive is unavailable.\n" );
	exit( 1 );
}
$archive = new ZipArchive();
if ( true !== $archive->open( $argv[1] ) ) {
	fwrite( STDERR, "ERROR: could not open {$argv[1]}.\n" );
	exit( 1 );
}
$entries = array();
for ( $index = 0; $index < $archive->numFiles; ++$index ) {
	$name = $archive->getNameIndex( $index );
	$entries[] = $name;
	if ( 0 !== strpos( $name, 'doughboss/' ) || false !== strpos( $name, '..' ) || false !== strpos( $name, '\\' ) ) {
		$archive->close();
		fwrite( STDERR, "ERROR: unsafe or invalid archive entry {$name}.\n" );
		exit( 1 );
	}
}
$archive->close();
foreach ( array( 'doughboss/doughboss.php', 'doughboss/includes/class-doughboss.php', 'doughboss/public/js/doughboss.js' ) as $required ) {
	if ( ! in_array( $required, $entries, true ) ) {
		fwrite( STDERR, "ERROR: required archive entry missing: {$required}.\n" );
		exit( 1 );
	}
}
echo "Archive layout is valid: {$argv[1]}\n";
