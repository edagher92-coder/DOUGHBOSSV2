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
		// A deactivated plugin must not leave recurring or single-run POSPal work
		// queued in WP-Cron. Customer/order data remains intact for reactivation.
		wp_clear_scheduled_hook( 'doughboss_pospal_outbox_dispatch' );
		wp_clear_scheduled_hook( 'doughboss_pospal_outbox_reconcile' );
		flush_rewrite_rules();
	}
}
