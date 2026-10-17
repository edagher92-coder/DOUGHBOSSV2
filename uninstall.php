<?php
/**
 * DoughBoss uninstall routine.
 *
 * Runs only when the user deletes the plugin from the WordPress admin. Removes
 * all plugin data: custom tables, options, menu items, capabilities and any
 * leftover cart transients.
 *
 * @package DoughBoss
 */

// If uninstall is not called from WordPress, bail.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop custom tables.
$orders_table = $wpdb->prefix . 'doughboss_orders';
$items_table  = $wpdb->prefix . 'doughboss_order_items';
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$items_table}" );
$wpdb->query( "DROP TABLE IF EXISTS {$orders_table}" );
// phpcs:enable

// Delete menu items and their meta.
$item_ids = get_posts(
	array(
		'post_type'   => 'doughboss_item',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);
foreach ( $item_ids as $item_id ) {
	wp_delete_post( $item_id, true );
}

// Remove options.
delete_option( 'doughboss_settings' );
delete_option( 'doughboss_db_version' );

// Remove the custom capability.
$role = get_role( 'administrator' );
if ( $role ) {
	$role->remove_cap( 'manage_doughboss' );
}

// Clean up cart transients.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_doughboss_cart_%' OR option_name LIKE '_transient_timeout_doughboss_cart_%'"
);
// phpcs:enable
