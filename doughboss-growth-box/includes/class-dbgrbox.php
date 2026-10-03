<?php
/**
 * Bootstrap: wires the shortcodes, the injection hooks and the admin screen.
 *
 * @package DoughBoss_Growth_Box
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap.
 */
final class DoughBoss_Growth_Box {

	/**
	 * Register everything. Shortcodes are always registered (and print nothing while the kill switch or the
	 * matching switch is off) so a raw "[shortcode]" is never shown to a visitor. The hooks that change other
	 * pages are added only when the kill switch is not set.
	 *
	 * @return void
	 */
	public static function init() {
		DoughBoss_Growth_Box_Shortcodes::register();
		// The hero media cache is cleared on every request type, even under the kill switch, so it can never be stale
		// when the plugin is switched back on.
		DoughBoss_Growth_Box_Hero_Media::register();
		if ( is_admin() ) {
			DoughBoss_Growth_Box_Admin::register();
		}
		if ( DoughBoss_Growth_Box_Settings::disabled() ) {
			return;
		}
		DoughBoss_Growth_Box_Inject::register();
	}
}
