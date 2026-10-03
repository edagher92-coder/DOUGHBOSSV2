<?php
/**
 * Plugin Name:       DoughBoss Growth Catering Box
 * Description:       Shortcodes for the Dough Boss catering box story page, the band under the hero, a site-wide "Feed the whole table." strip and a seal badge, plus an optional looping video in the home hero. Every feature switch ships off. Images come from the separate DoughBoss Growth Media plugin.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DoughBoss
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       doughboss-growth-box
 *
 * @package DoughBoss_Growth_Box
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Current version. Must match the header above, readme.txt "Stable tag" and the first changelog heading
 * (scripts/build-zip.php enforces the four-way match).
 */
define( 'DBGRBOX_VERSION', '0.2.0' );
define( 'DBGRBOX_FILE', __FILE__ );
define( 'DBGRBOX_DIR', plugin_dir_path( __FILE__ ) );
define( 'DBGRBOX_URL', plugin_dir_url( __FILE__ ) );

require_once DBGRBOX_DIR . 'includes/class-dbgrbox-settings.php';
require_once DBGRBOX_DIR . 'includes/class-dbgrbox-copy.php';
require_once DBGRBOX_DIR . 'includes/class-dbgrbox-manifest.php';
require_once DBGRBOX_DIR . 'includes/class-dbgrbox-hero-media.php';
require_once DBGRBOX_DIR . 'includes/class-dbgrbox-render.php';
require_once DBGRBOX_DIR . 'includes/class-dbgrbox-shortcodes.php';
require_once DBGRBOX_DIR . 'includes/class-dbgrbox-hero.php';
require_once DBGRBOX_DIR . 'includes/class-dbgrbox-inject.php';
require_once DBGRBOX_DIR . 'includes/class-dbgrbox-admin.php';
require_once DBGRBOX_DIR . 'includes/class-dbgrbox.php';

// Deactivating moves the draft story page back to draft so no raw shortcode text can ever show.
register_deactivation_hook( __FILE__, array( 'DoughBoss_Growth_Box_Admin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'DoughBoss_Growth_Box', 'init' ), 20 );
