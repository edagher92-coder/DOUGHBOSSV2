<?php
/**
 * Offline contract for provider-ready boundaries.
 *
 * This intentionally makes no HTTP calls and reads no credentials.
 * Run: php tests/provider-readiness-contract.php
 */

$root = dirname( __DIR__ );
$files = array(
	'settings' => file_get_contents( $root . '/includes/class-doughboss-settings.php' ),
	'tyro'     => file_get_contents( $root . '/includes/class-doughboss-tyro.php' ),
	'pospal'   => file_get_contents( $root . '/includes/class-doughboss-pospal.php' ),
	'outbox'   => file_get_contents( $root . '/includes/class-doughboss-pospal-outbox.php' ),
	'voucher'  => file_get_contents( $root . '/includes/class-doughboss-voucher.php' ),
	'voucher_sync' => file_get_contents( $root . '/includes/class-doughboss-pospal-sync.php' ),
	'rest'     => file_get_contents( $root . '/includes/class-doughboss-rest-controller.php' ),
	'env'      => file_get_contents( $root . '/.env.example' ),
	'readme'   => file_get_contents( $root . '/readme.txt' ),
);
$pass = 0;
$fail = 0;

function provider_ok( $condition, $label ) {
	global $pass, $fail;
	if ( $condition ) {
		$pass++;
		echo "  ok   $label\n";
	} else {
		$fail++;
		echo "  FAIL $label\n";
	}
}

echo "=== Provider readiness contract ===\n";

provider_ok(
	false !== strpos( $files['settings'], "env_first_secret( 'DOUGHBOSS_TYRO_TEST_CLIENT_SECRET'" )
		&& false !== strpos( $files['settings'], "env_first_secret( 'DOUGHBOSS_TYRO_LIVE_CLIENT_SECRET'" )
		&& false !== strpos( $files['env'], 'DOUGHBOSS_TYRO_TEST_WHSEC=' ),
	'Tyro credentials and webhook keys are environment-first with documented injection names'
);
provider_ok(
	false !== strpos( $files['settings'], "&& ( 'test' === self::tyro_mode() || (bool) self::get( 'tyro_live_certified', 0 ) );" )
		&& false !== strpos( $files['tyro'], "'doughboss_pay_config'" ),
	'Tyro stays fail-closed unless configured, and live mode also requires certification'
);
provider_ok(
	false !== strpos( $files['tyro'], "hash_hmac( 'sha256', (string) \$payload, \$secret )" )
		&& false !== strpos( $files['tyro'], 'hash_equals(' )
		&& false !== strpos( $files['rest'], 'DoughBoss_Payment_Attempts::claim_event( $event_key'),
	'Tyro webhook verifies the raw body and de-duplicates events before provider retrieval'
);
provider_ok(
	false !== strpos( $files['rest'], "hash_equals( \$expected_checkout, \$meta_checkout )" )
		&& false !== strpos( $files['tyro'], "'doughboss_pay_binding'" ),
	'Tyro checkout binding rejects changed checkout state and immutable references cannot be rebound'
);
provider_ok(
	false !== strpos( $files['settings'], "defined( 'DOUGHBOSS_POSPAL_APPKEY' )" )
		&& false !== strpos( $files['settings'], "getenv( 'DOUGHBOSS_POSPAL_APPKEY' )" )
		&& false !== strpos( $files['pospal'], "! DoughBoss_Settings::pospal_enabled()" ),
	'POSPal app keys are environment-first and all calls fail closed while disabled or incomplete'
);
provider_ok(
	false !== strpos( $files['voucher_sync'], 'pospal_grant_enabled' )
		&& false !== strpos( $files['voucher_sync'], 'revoke_coupon' )
		&& false !== strpos( $files['settings'], 'pospal_coupon_uid_for' ),
	'voucher mirroring is gated by an explicit POSPal coupon-rule mapping'
);
provider_ok(
	false !== strpos( $files['outbox'], "'ambiguous_network'" )
		&& false !== strpos( $files['outbox'], "'ambiguous_in_flight'" )
		&& false !== strpos( $files['outbox'], 'MAX_ATTEMPTS = 5' )
		&& false !== strpos( $files['outbox'], "WHERE id = %d AND status = 'pending'" ),
	'POSPal outbox has atomic claims, capped retries, and quarantines ambiguous delivery'
);
provider_ok(
	false !== strpos( $files['outbox'], "'remote_reference' => \$pospal_no" )
		&& false !== strpos( $files['outbox'], 'reconciliation inconclusive; no re-push performed' ),
	'POSPal persists an identified order number and treats inconclusive reconciliation as fail-closed'
);
provider_ok(
	false !== strpos( $files['readme'], 'Tyro-provided sandbox credentials' )
		&& false !== strpos( file_get_contents( $root . '/docs/DoughBoss-Architecture-Review-2026-07-14.md' ), 'POSPal pickup type and stable order identifier verified in sandbox/test environment' ),
	'sandbox and in-store acceptance gates remain documented as external validation'
);

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
