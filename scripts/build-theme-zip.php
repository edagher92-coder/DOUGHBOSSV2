<?php
/** Build the installable DoughBoss Final WordPress theme archive. */

$root = dirname( __DIR__ );
$source = $root . '/themes/doughboss-final';
$output_dir = $root . '/dist';
$output = $output_dir . '/doughboss-final.zip';

if ( ! extension_loaded( 'zip' ) ) {
	fwrite( STDERR, "The PHP zip extension is required.\n" );
	exit( 1 );
}
if ( ! is_dir( $source ) || ! is_file( $source . '/style.css' ) || ! is_file( $source . '/index.php' ) ) {
	fwrite( STDERR, "Theme source is incomplete.\n" );
	exit( 1 );
}
if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0777, true ) && ! is_dir( $output_dir ) ) {
	fwrite( STDERR, "Unable to create dist directory.\n" );
	exit( 1 );
}
if ( is_file( $output ) ) {
	unlink( $output );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $output, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Unable to create theme archive.\n" );
	exit( 1 );
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ( $iterator as $file ) {
	if ( ! $file->isFile() ) {
		continue;
	}
	$relative = substr( $file->getPathname(), strlen( $source ) + 1 );
	$zip->addFile( $file->getPathname(), 'doughboss-final/' . str_replace( DIRECTORY_SEPARATOR, '/', $relative ) );
}
$zip->close();

echo $output . PHP_EOL;
