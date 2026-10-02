<?php
/**
 * Plugin Name:       DoughBoss Growth
 * Description:       Companion to the DoughBoss plugin: consent, attribution, landing pages, waitlist and reporting modules. Every feature ships switched off.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DoughBoss
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       doughboss-growth
 * Domain Path:       /languages
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Current companion version. Must match the header above, readme.txt "Stable tag"
 * and the first changelog heading (scripts/build-zip.php enforces the four-way match).
 */
define( 'DOUGHBOSS_GROWTH_VERSION', '0.1.0' );

/**
 * Companion schema version, stored in option doughboss_growth_db_version.
 */
define( 'DOUGHBOSS_GROWTH_DB_VERSION', '1.1.0' );

define( 'DOUGHBOSS_GROWTH_FILE', __FILE__ );
define( 'DOUGHBOSS_GROWTH_DIR', plugin_dir_path( __FILE__ ) );
define( 'DOUGHBOSS_GROWTH_URL', plugin_dir_url( __FILE__ ) );

require_once DOUGHBOSS_GROWTH_DIR . 'includes/class-doughboss-growth-settings.php';
require_once DOUGHBOSS_GROWTH_DIR . 'includes/class-doughboss-growth-rate-limit.php';
require_once DOUGHBOSS_GROWTH_DIR . 'includes/class-doughboss-growth-failures.php';
require_once DOUGHBOSS_GROWTH_DIR . 'includes/class-doughboss-growth-http.php';
require_once DOUGHBOSS_GROWTH_DIR . 'includes/class-doughboss-growth-outbox.php';
require_once DOUGHBOSS_GROWTH_DIR . 'includes/class-doughboss-growth-activator.php';
require_once DOUGHBOSS_GROWTH_DIR . 'includes/class-doughboss-growth.php';
require_once DOUGHBOSS_GROWTH_DIR . 'admin/class-doughboss-growth-admin.php';

register_activation_hook( __FILE__, array( 'DoughBoss_Growth_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DoughBoss_Growth_Activator', 'deactivate' ) );

/*
 * Plugins load alphabetically and "doughboss-growth" sorts before "doughboss", so the core
 * gate must run on plugins_loaded, never at file load.
 */
add_action( 'plugins_loaded', array( 'DoughBoss_Growth', 'init' ), 20 );
