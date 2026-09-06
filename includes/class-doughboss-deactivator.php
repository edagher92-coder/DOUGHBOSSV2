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
 * Cleans up transient state on deactivation. Data (orders, menu items,
 * settings) is preserved; uninstall.php handles permanent removal.
 */
class DoughBoss_Deactivator {

	/**
	 * Deactivation routine.
	 *
	 * The post type is unregistered BEFORE flushing so the plugin's rewrite
	 * rules are not baked back into the cached rules (2.0 flushed with the CPT
	 * still registered, which left its rules behind).
	 *
	 * @return void
	 */
	public static function deactivate() {
		if ( post_type_exists( 'doughboss_item' ) ) {
			unregister_post_type( 'doughboss_item' );
		}
		if ( taxonomy_exists( 'doughboss_category' ) ) {
			unregister_taxonomy( 'doughboss_category' );
		}
		flush_rewrite_rules();

		wp_clear_scheduled_hook( 'doughboss_send_order_emails' );
	}
}
