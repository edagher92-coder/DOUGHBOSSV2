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
	}
}
