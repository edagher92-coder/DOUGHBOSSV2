<?php
/**
 * Byte budget for the DoughBoss Growth Catering Box code archive.
 *
 * The Crazy Domains host caps a browser plugin upload at 2 MB. The code zip is small by design (about 40 KB), so the
 * budget is 100,000 bytes. build-zip.php refuses to write an archive over budget.
 *
 * Library use:  require_once __DIR__ . '/budgets.php'; DBGRBOX_Budgets::check_zip( $path );
 * CLI use:      php scripts/budgets.php path/to/doughboss-growth-box.zip      (exit 1 when over budget)
 *
 * @package DoughBoss_Growth_Box
 */

/**
 * Archive size budget.
 */
final class DBGRBOX_Budgets {

	/**
	 * Maximum size of the code zip in bytes.
	 */
	const ZIP_MAX_BYTES = 100000;

	/**
	 * Check an archive against the budget.
	 *
	 * @param string $path Archive path.
	 * @return array { ok: bool, bytes: int, max: int, message: string }
	 */
	public static function check_zip( $path ) {
		clearstatcache( true, $path );
		$bytes = is_file( $path ) ? (int) filesize( $path ) : -1;
		if ( $bytes < 1 ) {
			return array(
				'ok'      => false,
				'bytes'   => $bytes,
				'max'     => self::ZIP_MAX_BYTES,
				'message' => 'archive is missing or empty: ' . $path,
			);
		}
		$ok = ( $bytes <= self::ZIP_MAX_BYTES );
		return array(
			'ok'      => $ok,
			'bytes'   => $bytes,
			'max'     => self::ZIP_MAX_BYTES,
			'message' => sprintf( '%s %d bytes of %d byte budget (%d%%)', $ok ? 'within' : 'OVER', $bytes, self::ZIP_MAX_BYTES, (int) round( 100 * $bytes / self::ZIP_MAX_BYTES ) ),
		);
	}
}

if ( PHP_SAPI === 'cli' && isset( $_SERVER['argv'][0] ) && realpath( $_SERVER['argv'][0] ) === realpath( __FILE__ ) ) {
	if ( 2 !== $_SERVER['argc'] ) {
		fwrite( STDERR, "Usage: php scripts/budgets.php path/to/doughboss-growth-box.zip\n" );
		exit( 2 );
	}
	$dbgrbox_budget_result = DBGRBOX_Budgets::check_zip( $_SERVER['argv'][1] );
	echo ( $dbgrbox_budget_result['ok'] ? 'OK: ' : 'ERROR: ' ) . $dbgrbox_budget_result['message'] . "\n";
	exit( $dbgrbox_budget_result['ok'] ? 0 : 1 );
}
