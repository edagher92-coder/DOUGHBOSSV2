<?php
/**
 * Plugin Name:       DoughBoss Growth Media
 * Description:       Asset-only pack for DoughBoss Growth. It registers nothing and runs no code; it only holds files under assets/.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DoughBoss
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       doughboss-growth-media
 *
 * @package DoughBoss_Growth_Media
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media pack version. scripts/build-zip.php --package=media checks it against the header.
 */
define( 'DOUGHBOSS_GROWTH_MEDIA_VERSION', '0.1.0' );
