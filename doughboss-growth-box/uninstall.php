<?php
/**
 * DoughBoss Growth Catering Box uninstall routine.
 *
 * Removes only this plugin's two options and its one cached lookup (a transient). It never touches a page, an image, a DoughBoss table, option, post
 * type, role or capability.
 *
 * @package DoughBoss_Growth_Box
 */

// If uninstall is not called from WordPress, bail.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'doughboss_growth_box' );
delete_option( 'doughboss_growth_box_page_id' );
delete_transient( 'dbgrbox_hero_media' );
