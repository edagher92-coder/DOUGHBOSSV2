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
 * Companion schema version: what DoughBoss_Growth_Activator::install() brings the tables up to and records in option
 * doughboss_growth_db_version. Raise it whenever a CREATE TABLE statement changes.
 */
define( 'DOUGHBOSS_GROWTH_DB_VERSION', '1.1.0' );

/**
 * The oldest recorded schema version this code can still run on. DoughBoss_Growth_Activator::storage_ready() compares the
 * stored version with THIS, not with DOUGHBOSS_GROWTH_DB_VERSION, so a plugin replaced by a zip upload keeps every module
 * running (opt-out pages, exporter, eraser, purge, lead form, conversions) while the table upgrade waits for a manager to
 * open wp-admin. Today it equals DOUGHBOSS_GROWTH_DB_VERSION. A release that adds only nullable or defaulted columns (or
 * indexes) that its code works without leaves it at the previous value; a release whose code cannot work on the old tables
 * raises it to the new schema version in the same release (the modules then wait for the upgrade). It must never be above
 * DOUGHBOSS_GROWTH_DB_VERSION. See "Upgrading" in docs/RELEASE-0.1.0.md.
 */
define( 'DOUGHBOSS_GROWTH_DB_MIN_COMPAT', '1.1.0' );

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
