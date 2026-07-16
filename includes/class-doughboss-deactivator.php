<?php
/**
 * Fired during plugin deactivation.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clean up transient rewrite state on deactivation.
 *
 * Note: customer/order data and settings are intentionally preserved on
 * deactivation. They are only removed on uninstall (see uninstall.php).
 */
class DoughBoss_Deactivator {

	/**
	 * Deactivation routine.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();

		// Clear the POSPal outbox cron events — a deactivated plugin must not
		// leave recurring hooks behind (WP would fire them into a void, and the
		// hourly event would linger forever after uninstall otherwise). Constants
		// are inlined rather than referencing DoughBoss_POSPal_Outbox:: because
		// the class file may not be loaded on the deactivation request.
		wp_clear_scheduled_hook( 'doughboss_pospal_outbox_dispatch' );
		wp_clear_scheduled_hook( 'doughboss_pospal_outbox_reconcile' );
	}
}
