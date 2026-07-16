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

// Drop custom tables (children before parents).
$tables = array(
	$wpdb->prefix . 'doughboss_voucher_redemptions',
	$wpdb->prefix . 'doughboss_vouchers',
	$wpdb->prefix . 'doughboss_order_items',
	$wpdb->prefix . 'doughboss_orders',
	$wpdb->prefix . 'doughboss_catering_enquiries',
	$wpdb->prefix . 'doughboss_locations',
	$wpdb->prefix . 'doughboss_pospal_outbox',
);
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}
// phpcs:enable

// Delete menu items, catering packages, and their meta.
$post_ids = get_posts(
	array(
		'post_type'   => array( 'doughboss_item', 'doughboss_cat_pkg' ),
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);
foreach ( $post_ids as $post_id ) {
	wp_delete_post( $post_id, true );
}

// Remove options.
delete_option( 'doughboss_settings' );
delete_option( 'doughboss_db_version' );
delete_option( 'doughboss_unreconciled_payments' );
delete_option( 'doughboss_pospal_unmapped_alerts' );
delete_option( 'doughboss_delivery_autodisabled' );

// Remove the custom capabilities and the kitchen role.
$role = get_role( 'administrator' );
if ( $role ) {
	$role->remove_cap( 'manage_doughboss' );
	$role->remove_cap( 'manage_doughboss_kds' );
	$role->remove_cap( 'redeem_doughboss_vouchers' );
}
remove_role( 'doughboss_kitchen' );

// Clean up checkout idempotency transients.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_doughboss_idem_%' OR option_name LIKE '_transient_timeout_doughboss_idem_%'"
);
// phpcs:enable

// Clean up cart transients.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_doughboss_cart_%' OR option_name LIKE '_transient_timeout_doughboss_cart_%'"
);
// phpcs:enable
