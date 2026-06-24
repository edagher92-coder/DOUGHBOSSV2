<?php
/**
 * WP-CLI commands (loaded only under WP-CLI).
 *
 * Provides a clean way to exercise the POSPal connector from the live host —
 * where outbound network access exists — without putting the secret key into a
 * shell command or a file: the key is read from the environment / settings by
 * the connector itself.
 *
 *   wp doughboss pospal-test
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

/**
 * DoughBoss CLI commands.
 */
class DoughBoss_CLI {

	/**
	 * Read-only POSPal connectivity check: lists the account's coupon promotion
	 * rules. Confirms the host, appId/appKey signing and that the discount
	 * coupons exist — without changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss pospal-test
	 *
	 * @return void
	 */
	public static function pospal_test() {
		if ( ! DoughBoss_POSPal::ready() ) {
			WP_CLI::error( 'POSPal is not configured. Set pospal_host + pospal_app_id in settings and DOUGHBOSS_POSPAL_APPKEY in the environment, and enable POSPal.' );
		}

		$result = DoughBoss_POSPal::test_connection();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'POSPal call failed: ' . $result->get_error_message() );
		}

		WP_CLI::success( 'POSPal reachable and the signature was accepted.' );
		WP_CLI::log( 'Coupon promotion rules: ' . wp_json_encode( $result ) );
	}
}

WP_CLI::add_command( 'doughboss pospal-test', array( 'DoughBoss_CLI', 'pospal_test' ) );
