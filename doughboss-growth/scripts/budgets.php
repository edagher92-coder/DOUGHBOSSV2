<?php
/**
 * Byte budgets for the DoughBoss Growth archive.
 *
 * The Crazy Domains host caps a browser plugin upload at 2 MB, so the companion ships as ONE code zip with a
 * budget of 1.0 MB (1,000,000 bytes, deliberately stricter than 1 MiB). build-zip.php refuses to write an
 * archive over budget and validate-zip.php re-checks it; the CI job runs both.
 *
 * Library use:  require_once __DIR__ . '/budgets.php'; DBGR_Budgets::check_zip( $path );
 * CLI use:      php scripts/budgets.php path/to/doughboss-growth.zip      (exit 1 when over budget)
 *
 * @package DoughBoss_Growth
 */

/**
 * Archive size budgets.
 */
final class DBGR_Budgets {

	/**
	 * Maximum size of the code zip in bytes (1.0 MB).
	 */
	const ZIP_MAX_BYTES = 1000000;

	/**
	 * Check an archive against the budget.
	 *
	 * @param string $path Archive path.
	 * @return array { ok: bool, bytes: int, max: int, message: string }
	 */
	public static function check_zip( $path ) {
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
		fwrite( STDERR, "Usage: php scripts/budgets.php path/to/doughboss-growth.zip\n" );
		exit( 2 );
	}
	$dbgr_budget_result = DBGR_Budgets::check_zip( $_SERVER['argv'][1] );
	echo ( $dbgr_budget_result['ok'] ? 'OK: ' : 'ERROR: ' ) . $dbgr_budget_result['message'] . "\n";
	exit( $dbgr_budget_result['ok'] ? 0 : 1 );
}
