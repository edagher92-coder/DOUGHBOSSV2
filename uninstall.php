<?php
/**
 * Uninstall routine — permanently removes DoughBoss data.
 *
 * Only reached through WordPress's "Delete" action on the Plugins screen,
 * which already enforces the delete_plugins capability.
 *
 * @package DoughBoss
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Custom tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}doughboss_order_items" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}doughboss_orders" );

// Menu items and their categories.
$items = get_posts(
	array(
		'post_type'      => 'doughboss_item',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $items as $item_id ) {
	wp_delete_post( $item_id, true );
}
$terms = get_terms(
	array(
		'taxonomy'   => 'doughboss_category',
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);
if ( ! is_wp_error( $terms ) ) {
	foreach ( $terms as $term_id ) {
		wp_delete_term( $term_id, 'doughboss_category' );
	}
}

// Options.
delete_option( 'doughboss_settings' );
delete_option( 'doughboss_db_version' );
delete_option( 'doughboss_menu_version' );
delete_option( 'doughboss_flush_rewrites' );
delete_option( 'doughboss_tax_notice_dismissed' );

// Carts, menu cache, rate-limit counters and checkout locks.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_doughboss\_%' OR option_name LIKE '\_transient\_timeout\_doughboss\_%' OR option_name LIKE 'doughboss\_lock\_%'" );
// phpcs:enable

// Roles and capabilities.
remove_role( 'doughboss_manager' );
$admin = get_role( 'administrator' );
if ( $admin ) {
	foreach ( array_keys( (array) $admin->capabilities ) as $cap ) {
		if ( 'manage_doughboss' === $cap || false !== strpos( $cap, 'doughboss_item' ) || 'manage_doughboss_categories' === $cap ) {
			$admin->remove_cap( $cap );
		}
	}
}

wp_clear_scheduled_hook( 'doughboss_send_order_emails' );
wp_cache_flush();
