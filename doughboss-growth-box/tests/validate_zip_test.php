<?php
/**
 * Black-box archive-validator tests. They run only against temporary archives and never mutate
 * the plugin source tree.
 */

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "SKIP: ZipArchive is required for validate_zip_test.php\n" );
	exit( 0 );
}

$root      = dirname( __DIR__ );
$validator = $root . '/scripts/validate-zip.php';
$builder   = $root . '/scripts/build-zip.php';
$temp      = sys_get_temp_dir() . '/dbgrbox-validate-' . uniqid( '', true );
if ( ! mkdir( $temp, 0700, true ) ) {
	fwrite( STDERR, "Could not create temporary test directory\n" );
	exit( 1 );
}

$pass = 0;
$fail = 0;

function dbgrbox_validator_cleanup( $path ) {
	if ( is_dir( $path ) ) {
		$items = scandir( $path );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( '.' !== $item && '..' !== $item ) {
					@unlink( $path . DIRECTORY_SEPARATOR . $item );
				}
			}
		}
		@rmdir( $path );
	}
}

function dbgrbox_validator_run( $script, $archive ) {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $archive ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array( $status, implode( "\n", $output ) );
}

function dbgrbox_validator_expect( $condition, $message ) {
	global $pass, $fail;
	if ( $condition ) {
		++$pass;
		echo "PASS {$message}\n";
	} else {
		++$fail;
		echo "FAIL {$message}\n";
	}
}

function dbgrbox_validator_copy( $from, $to ) {
	if ( ! copy( $from, $to ) ) {
		throw new RuntimeException( "Could not copy test archive to {$to}" );
	}
}

function dbgrbox_validator_change_entry( $archive_path, $name, $body ) {
	$archive = new ZipArchive();
	if ( true !== $archive->open( $archive_path ) || ! $archive->deleteName( $name ) || ! $archive->addFromString( $name, $body ) || ! $archive->close() ) {
		throw new RuntimeException( "Could not alter {$name} in {$archive_path}" );
	}
}

function dbgrbox_validator_delete_entry( $archive_path, $name ) {
	$archive = new ZipArchive();
	if ( true !== $archive->open( $archive_path ) || ! $archive->deleteName( $name ) || ! $archive->close() ) {
		throw new RuntimeException( "Could not remove {$name} from {$archive_path}" );
	}
}

function dbgrbox_validator_add_entry( $archive_path, $name, $body ) {
	$archive = new ZipArchive();
	if ( true !== $archive->open( $archive_path ) || ! $archive->addFromString( $name, $body ) || ! $archive->close() ) {
		throw new RuntimeException( "Could not add {$name} to {$archive_path}" );
	}
}

/**
 * Write a small stored ZIP without normalising duplicate names or UNIX mode bits. ZipArchive is
 * intentionally not used here because it replaces an entry with the same name, whereas a hostile
 * ZIP may retain both central-directory records.
 *
 * @param string $path Output path.
 * @param array  $entries List of array( name, body, unix-mode ).
 * @return void
 */
function dbgrbox_validator_write_raw_zip( $path, array $entries ) {
	$body       = '';
	$central    = '';
	$offset     = 0;
	$entry_count = 0;
	foreach ( $entries as $entry ) {
		$name     = $entry[0];
		$contents = $entry[1];
		$mode     = $entry[2];
		$crc      = crc32( $contents );
		$length   = strlen( $contents );
		$name_len = strlen( $name );
		$body    .= pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 33, $crc, $length, $length, $name_len, 0 ) . $name . $contents;
		$central .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 0x0314, 20, 0, 0, 0, 33, $crc, $length, $length, $name_len, 0, 0, 0, 0, $mode << 16, $offset ) . $name;
		$offset += 30 + $name_len + $length;
		++$entry_count;
	}
	$zip = $body . $central . pack( 'VvvvvVVv', 0x06054b50, 0, 0, $entry_count, $entry_count, strlen( $central ), strlen( $body ), 0 );
	if ( false === file_put_contents( $path, $zip ) ) {
		throw new RuntimeException( "Could not write raw ZIP: {$path}" );
	}
}

try {
	$positive = $temp . '/positive.zip';
	list( $build_status, $build_output ) = dbgrbox_validator_run( $builder, $positive );
	dbgrbox_validator_expect( 0 === $build_status, 'build canonical archive' );
	list( $status, $output ) = dbgrbox_validator_run( $validator, $positive );
	dbgrbox_validator_expect( 0 === $status && false !== strpos( $output, '18 files' ), 'accept canonical 18-file archive' );

	$modified = $temp . '/modified.zip';
	dbgrbox_validator_copy( $positive, $modified );
	dbgrbox_validator_change_entry( $modified, 'doughboss-growth-box/content/copy.json', '{"changed":true}' );
	list( $status, $output ) = dbgrbox_validator_run( $validator, $modified );
	dbgrbox_validator_expect( 0 !== $status && false !== strpos( $output, 'content mismatch' ), 'refuse modified runtime bytes' );

	$missing = $temp . '/missing.zip';
	dbgrbox_validator_copy( $positive, $missing );
	dbgrbox_validator_delete_entry( $missing, 'doughboss-growth-box/content/copy.json' );
	list( $status, $output ) = dbgrbox_validator_run( $validator, $missing );
	dbgrbox_validator_expect( 0 !== $status && false !== strpos( $output, 'canonical-runtime mismatch' ), 'refuse missing runtime file' );

	$extra = $temp . '/extra.zip';
	dbgrbox_validator_copy( $positive, $extra );
	dbgrbox_validator_add_entry( $extra, 'doughboss-growth-box/includes/extra.php', "<?php defined( 'ABSPATH' ) || exit;" );
	list( $status, $output ) = dbgrbox_validator_run( $validator, $extra );
	dbgrbox_validator_expect( 0 !== $status && false !== strpos( $output, 'non-runtime archive entry' ), 'refuse extra runtime-looking file' );

	$wrong_root = $temp . '/wrong-root.zip';
	dbgrbox_validator_copy( $positive, $wrong_root );
	dbgrbox_validator_add_entry( $wrong_root, 'wrong-root/doughboss-growth-box.php', 'wrong root' );
	list( $status, $output ) = dbgrbox_validator_run( $validator, $wrong_root );
	dbgrbox_validator_expect( 0 !== $status && false !== strpos( $output, 'unsafe or noncanonical archive entry' ), 'refuse wrong archive root' );

	$version = $temp . '/version.zip';
	dbgrbox_validator_copy( $positive, $version );
	$archive = new ZipArchive();
	if ( true !== $archive->open( $version ) ) {
		throw new RuntimeException( 'Could not open version archive' );
	}
	$plugin = $archive->getFromName( 'doughboss-growth-box/doughboss-growth-box.php' );
	$archive->close();
	dbgrbox_validator_change_entry( $version, 'doughboss-growth-box/doughboss-growth-box.php', str_replace( "define( 'DBGRBOX_VERSION', '0.2.0' );", "define( 'DBGRBOX_VERSION', '0.2.1' );", $plugin ) );
	list( $status, $output ) = dbgrbox_validator_run( $validator, $version );
	dbgrbox_validator_expect( 0 !== $status && false !== strpos( $output, 'archive version mismatch' ), 'refuse inconsistent release metadata' );

	$non_runtime = $temp . '/non-runtime.zip';
	dbgrbox_validator_copy( $positive, $non_runtime );
	dbgrbox_validator_add_entry( $non_runtime, 'doughboss-growth-box/tests/probe.php', "<?php defined( 'ABSPATH' ) || exit;" );
	list( $status, $output ) = dbgrbox_validator_run( $validator, $non_runtime );
	dbgrbox_validator_expect( 0 !== $status && false !== strpos( $output, 'non-runtime archive entry' ), 'refuse test or non-runtime payload' );

	$unsafe = $temp . '/unsafe.zip';
	dbgrbox_validator_copy( $positive, $unsafe );
	dbgrbox_validator_add_entry( $unsafe, 'doughboss-growth-box/../secret.txt', 'nope' );
	list( $status, $output ) = dbgrbox_validator_run( $validator, $unsafe );
	dbgrbox_validator_expect( 0 !== $status && false !== strpos( $output, 'unsafe or noncanonical archive entry' ), 'refuse traversal entry' );

	$duplicate = $temp . '/duplicate.zip';
	dbgrbox_validator_write_raw_zip( $duplicate, array(
		array( 'doughboss-growth-box/readme.txt', 'one', 0100644 ),
		array( 'doughboss-growth-box/readme.txt', 'two', 0100644 ),
	) );
	list( $status, $output ) = dbgrbox_validator_run( $validator, $duplicate );
	dbgrbox_validator_expect( 0 !== $status && false !== strpos( $output, 'duplicate archive entry' ), 'refuse duplicate archive entry' );

	$symlink = $temp . '/symlink.zip';
	dbgrbox_validator_write_raw_zip( $symlink, array(
		array( 'doughboss-growth-box/readme.txt', 'target', 0120777 ),
	) );
	list( $status, $output ) = dbgrbox_validator_run( $validator, $symlink );
	dbgrbox_validator_expect( 0 !== $status && false !== strpos( $output, 'symbolic-link archive entry' ), 'refuse symbolic-link archive entry' );

	// Keep all canonical names and payload bytes intact while changing one entry type.
	$type_cases = array(
		'unix-directory' => array( ZipArchive::OPSYS_UNIX, 0040755 << 16 ),
		'unix-fifo' => array( ZipArchive::OPSYS_UNIX, 0010644 << 16 ),
		'unix-socket' => array( ZipArchive::OPSYS_UNIX, 0140644 << 16 ),
		'unix-character-device' => array( ZipArchive::OPSYS_UNIX, 0020644 << 16 ),
		'unix-block-device' => array( ZipArchive::OPSYS_UNIX, 0060644 << 16 ),
		'dos-directory' => array( ZipArchive::OPSYS_DOS, 0x10 ),
		'unsupported-dos-file' => array( ZipArchive::OPSYS_DOS, 0x20 ),
		'unix-regular-with-directory-bit' => array( ZipArchive::OPSYS_UNIX, ( 0100644 << 16 ) | 0x10 ),
	);
	foreach ( $type_cases as $label => $type_case ) {
		$type_path = $temp . '/' . $label . '.zip';
		dbgrbox_validator_copy( $positive, $type_path );
		$archive = new ZipArchive();
		if ( true !== $archive->open( $type_path )
			|| ! $archive->setExternalAttributesName( 'doughboss-growth-box/readme.txt', $type_case[0], $type_case[1] )
			|| ! $archive->close() ) {
			throw new RuntimeException( "Could not create complete-payload type fixture: {$label}" );
		}
		list( $status, $output ) = dbgrbox_validator_run( $validator, $type_path );
		dbgrbox_validator_expect( 0 !== $status && false !== strpos( $output, 'non-regular or unsupported archive entry type' ), 'refuse complete-payload ' . $label );
	}
	$over_budget = $temp . '/over-budget.zip';
	dbgrbox_validator_copy( $positive, $over_budget );
	file_put_contents( $over_budget, str_repeat( 'x', 100001 ), FILE_APPEND );
	list( $status, $output ) = dbgrbox_validator_run( $validator, $over_budget );
	dbgrbox_validator_expect( 0 !== $status && false !== strpos( $output, 'over budget' ), 'refuse over-budget archive before entry reads' );
	$unguarded = $temp . '/unguarded.zip';
	dbgrbox_validator_copy( $positive, $unguarded );
	dbgrbox_validator_change_entry( $unguarded, 'doughboss-growth-box/includes/class-dbgrbox.php', '<?php // guard removed' );
	list( $status, $output ) = dbgrbox_validator_run( $validator, $unguarded );
	dbgrbox_validator_expect( 0 !== $status && false !== strpos( $output, 'direct-access guard' ), 'refuse PHP entry without direct-access guard' );
} catch ( Throwable $error ) {
	dbgrbox_validator_expect( false, $error->getMessage() );
}

dbgrbox_validator_cleanup( $temp );
echo "done {$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
