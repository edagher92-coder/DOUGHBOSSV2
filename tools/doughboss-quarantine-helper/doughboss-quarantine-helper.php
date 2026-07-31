<?php
/**
 * Plugin Name:       DoughBoss Duplicate Quarantine Helper
 * Description:       Moves two known malformed, inactive migration-gate upload folders out of the WordPress plugins directory.
 * Version:           1.0.2
 * Author:            DoughBoss
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quarantine only the two exact directories created by malformed ZIP uploads.
 *
 * The canonical `doughboss` and `doughboss-migration-gate` directories are
 * never candidates. Files are moved, not deleted, so recovery remains possible.
 *
 * @return void
 */
function doughboss_quarantine_known_duplicate_gate_directories() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_die( esc_html__( 'You do not have permission to run this cleanup.', 'doughboss-quarantine-helper' ) );
	}

	$plugin_root = wp_normalize_path( WP_PLUGIN_DIR );
	$active      = (array) get_option( 'active_plugins', array() );
	$network_active = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
	$candidates  = array(
		'DoughBoss-Migration-Gate-1.0.0',
		'doughboss-migration-gate-1.0.1',
	);
	$quarantine = wp_normalize_path( WP_CONTENT_DIR . '/doughboss-quarantine' );

	if ( ! is_dir( $quarantine ) && ! wp_mkdir_p( $quarantine ) ) {
		wp_die( esc_html__( 'The quarantine directory could not be created.', 'doughboss-quarantine-helper' ) );
	}

	foreach ( $candidates as $directory ) {
		$source = wp_normalize_path( WP_PLUGIN_DIR . '/' . $directory );
		if ( ! is_dir( $source ) ) {
			continue;
		}
		if ( wp_normalize_path( dirname( $source ) ) !== $plugin_root || basename( $source ) !== $directory ) {
			wp_die( esc_html__( 'Safety stop: a candidate path was outside the plugins directory.', 'doughboss-quarantine-helper' ) );
		}

		foreach ( $active as $active_plugin ) {
			if ( 0 === strpos( wp_normalize_path( (string) $active_plugin ), $directory . '/' ) ) {
				wp_die( esc_html__( 'Safety stop: a duplicate candidate is active and was not moved.', 'doughboss-quarantine-helper' ) );
			}
		}

		foreach ( array_keys( $network_active ) as $active_plugin ) {
			if ( 0 === strpos( wp_normalize_path( (string) $active_plugin ), $directory . '/' ) ) {
				wp_die( esc_html__( 'Safety stop: a duplicate candidate is network-active and was not moved.', 'doughboss-quarantine-helper' ) );
			}
		}

		$destination = $quarantine . '/' . $directory . '-' . gmdate( 'Ymd-His' );
		if ( ! @rename( $source, $destination ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- activation must return one controlled error without leaking host paths.
			wp_die( esc_html__( 'A duplicate directory could not be moved into quarantine.', 'doughboss-quarantine-helper' ) );
		}
	}
}

register_activation_hook( __FILE__, 'doughboss_quarantine_known_duplicate_gate_directories' );
