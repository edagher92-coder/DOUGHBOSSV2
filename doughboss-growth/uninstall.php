<?php
/**
 * DoughBoss Growth uninstall routine.
 *
 * Deletes NOTHING unless DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA is defined as exactly true in
 * wp-config.php. Even then it only touches names beginning "doughboss_growth_"; it never touches a
 * DoughBoss core table, option, post type, role or capability.
 *
 * @package DoughBoss_Growth
 */

// If uninstall is not called from WordPress, bail.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Keep all data unless the site owner explicitly opted in.
if ( ! defined( 'DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA' ) || true !== DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA ) {
	return;
}

require_once __DIR__ . '/includes/class-doughboss-growth-activator.php';

global $wpdb;
DoughBoss_Growth_Activator::run_uninstall( $wpdb );
