<?php
/**
 * Plugin Name:       DoughBoss
 * Plugin URI:        https://github.com/edagher92-coder/doughbossv2
 * Description:        Pizza & food ordering for WordPress — menu management, a custom pizza builder, online ordering, and order tracking.
 * Version:           2.10.9
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DoughBoss
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       doughboss
 * Domain Path:       /languages
 *
 * @package DoughBoss
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Current plugin version.
 */
define( 'DOUGHBOSS_VERSION', '2.10.9' );

/**
 * Database schema version. Bump when the schema in the activator changes.
 */
define( 'DOUGHBOSS_DB_VERSION', '1.7.0' );

define( 'DOUGHBOSS_PLUGIN_FILE', __FILE__ );
define( 'DOUGHBOSS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DOUGHBOSS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DOUGHBOSS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * REST API namespace used throughout the plugin.
 */
define( 'DOUGHBOSS_REST_NAMESPACE', 'doughboss/v1' );

require_once DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss.php';
require_once DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss-activator.php';
require_once DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss-deactivator.php';

/**
 * Runs on plugin activation.
 *
 * @return void
 */
function doughboss_activate() {
	DoughBoss_Activator::activate();
}
register_activation_hook( __FILE__, 'doughboss_activate' );

/**
 * Runs on plugin deactivation.
 *
 * @return void
 */
function doughboss_deactivate() {
	DoughBoss_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'doughboss_deactivate' );

/**
 * Boot the plugin once all plugins are loaded.
 *
 * @return void
 */
function doughboss() {
	return DoughBoss::instance();
}
add_action( 'plugins_loaded', 'doughboss' );
