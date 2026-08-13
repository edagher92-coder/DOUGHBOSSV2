<?php
/**
 * Plugin Name: DoughBoss Deploy 2.37.0
 * Description: One-use, hash-pinned DoughBoss 2.37.0 deployment with protected rollback copy.
 * Version: 1.0.0
 * Author: DoughBoss
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DoughBoss_Deploy_2370 {
	const PACKAGE_URL = 'https://raw.githubusercontent.com/edagher92-coder/DOUGHBOSSV2/7bad17d92a8e98d60f050976b57c36aff7ed2bfe/release-assets/doughboss-2.37.0.zip';
	const PACKAGE_SHA = '3d778601d8600ffd8e0e58614b9d4d0f85dca6ca31d0ca87acea7b3e828ab7d4';
	const PACKAGE_BYTES = 2509077;
	const RESULT_OPTION = 'doughboss_deploy_2370_result';
	const LOCK_OPTION = 'doughboss_deploy_2370_lock';
	const EXPECTED_CURRENT = '2.36.0';
	const EXPECTED_TARGET = '2.37.0';
	const ACTION = 'doughboss_deploy_2370_run';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function menu() {
		add_plugins_page( 'DoughBoss 2.37 deployment', 'DoughBoss 2.37 deployer', 'update_plugins', 'doughboss-deploy-2370', array( __CLASS__, 'page' ) );
	}

	private static function allowed() {
		return current_user_can( 'manage_options' ) && current_user_can( 'update_plugins' ) && current_user_can( 'activate_plugins' );
	}

	private static function off( $value ) {
		return false === $value || 0 === $value || '0' === $value;
	}

	private static function state() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$settings = get_option( 'doughboss_settings', null );
		$data = get_plugin_data( WP_PLUGIN_DIR . '/doughboss/doughboss.php', false, false );
		$orders_off = is_array( $settings ) && array_key_exists( 'ordering_open', $settings ) && self::off( $settings['ordering_open'] );
		$payments_off = is_array( $settings ) && array_key_exists( 'payments_enabled', $settings ) && self::off( $settings['payments_enabled'] );
		return array(
			'orders_off' => $orders_off,
			'payments_off' => $payments_off,
			'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
			'active' => is_plugin_active( 'doughboss/doughboss.php' ),
			'database' => (string) get_option( 'doughboss_db_version', '' ),
			'migration_error' => (bool) get_option( 'doughboss_migration_error', false ),
		);
	}

	public static function page() {
		if ( ! self::allowed() ) { wp_die( 'Required administrator permissions are missing.', 'Deployment stopped', array( 'response' => 403 ) ); }
		$stored = get_option( self::RESULT_OPTION, null );
		$result = is_array( $stored ) ? $stored : null;
		$state = self::state();
		echo '<div class="wrap"><h1>DoughBoss 2.37.0 verified deployment</h1>';
		if ( is_array( $result ) && 'error' === ( $result['status'] ?? '' ) ) { echo '<div class="notice notice-error"><p><strong>Stopped safely.</strong> ' . esc_html( (string) ( $result['message'] ?? 'The deployment stopped.' ) ) . '</p></div>'; }
		elseif ( is_array( $result ) && 'complete' === ( $result['status'] ?? '' ) ) { echo '<div class="notice notice-success"><p><strong>Deployment complete.</strong> DoughBoss 2.37.0 is active and verified.</p></div>'; }
		echo '<p>This one-use installer accepts only the byte-pinned 2.37.0 package, preserves a rollback copy outside the public site, and refuses to run unless orders and payments are off.</p>';
		foreach ( $state as $key => $value ) { echo '<p><strong>' . esc_html( $key ) . ':</strong> ' . esc_html( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : $value ) . '</p>'; }
		$ready = $state['orders_off'] && $state['payments_off'] && $state['active'] && self::EXPECTED_CURRENT === $state['version'] && ! $state['migration_error'];
		if ( $ready ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />'; wp_nonce_field( self::ACTION ); submit_button( 'Install verified DoughBoss 2.37.0' ); echo '</form>'; }
		echo '</div>';
	}

	public static function handle() {
		if ( ! self::allowed() ) { wp_die( 'Required administrator permissions are missing.', 'Deployment stopped', array( 'response' => 403 ) ); }
		check_admin_referer( self::ACTION );
		$result = self::run();
		update_option( self::RESULT_OPTION, is_wp_error( $result ) ? array( 'status' => 'error', 'message' => $result->get_error_message(), 'utc' => gmdate( 'c' ) ) : $result, false );
		wp_safe_redirect( admin_url( 'plugins.php?page=doughboss-deploy-2370' ) );
		exit;
	}

	private static function run() {
		if ( ! self::allowed() || is_multisite() || ! is_ssl() ) { return new WP_Error( 'environment', 'The deployment requires a single HTTPS site and administrator plugin permissions.' ); }
		$state = self::state();
		if ( ! $state['orders_off'] || ! $state['payments_off'] || ! $state['active'] || self::EXPECTED_CURRENT !== $state['version'] || $state['migration_error'] ) {
			return new WP_Error( 'baseline', 'The expected safe 2.36.0 baseline with orders and payments off was not found.' );
		}
		$lock = array( 'utc' => gmdate( 'c' ), 'user' => get_current_user_id() );
		if ( ! add_option( self::LOCK_OPTION, $lock, '', 'no' ) ) { return new WP_Error( 'locked', 'Another DoughBoss deployment is already running.' ); }
		try {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) { return new WP_Error( 'file_mods', 'WordPress file changes are disabled.' ); }
			if ( 'direct' !== get_filesystem_method() ) { return new WP_Error( 'filesystem', 'Direct WordPress filesystem access is unavailable.' ); }
			$parent = realpath( dirname( ABSPATH ) );
			$live = realpath( WP_PLUGIN_DIR . '/doughboss' );
			if ( false === $parent || false === $live || ! is_writable( $parent ) ) { return new WP_Error( 'backup', 'A protected rollback location could not be established.' ); }
			$backup_base = $parent . '/doughboss-deploy-backups';
			if ( ! is_dir( $backup_base ) && ! wp_mkdir_p( $backup_base ) ) { return new WP_Error( 'backup_dir', 'The rollback directory could not be created.' ); }
			$backup = $backup_base . '/2.36.0-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 10, false, false ) );
			if ( ! self::copy_tree( $live, $backup ) ) { return new WP_Error( 'backup_copy', 'The live plugin could not be copied to protected rollback storage.' ); }
			$tmp = download_url( self::PACKAGE_URL, 60 );
			if ( is_wp_error( $tmp ) ) { return new WP_Error( 'download', 'The verified package could not be downloaded.' ); }
			try {
				if ( self::PACKAGE_BYTES !== (int) filesize( $tmp ) || self::PACKAGE_SHA !== strtolower( hash_file( 'sha256', $tmp ) ) ) {
					return new WP_Error( 'integrity', 'The downloaded package did not match the approved size and SHA-256.' );
				}
				$skin = new Automatic_Upgrader_Skin();
				$upgrader = new Plugin_Upgrader( $skin );
				$installed = $upgrader->run( array(
					'package' => $tmp,
					'destination' => WP_PLUGIN_DIR,
					'clear_destination' => true,
					'clear_working' => true,
					'hook_extra' => array( 'plugin' => 'doughboss/doughboss.php', 'type' => 'plugin', 'action' => 'update' ),
				) );
				if ( is_wp_error( $installed ) || ! $installed ) { return new WP_Error( 'upgrade', 'WordPress could not complete the verified plugin update.' ); }
			} finally {
				if ( is_string( $tmp ) && file_exists( $tmp ) ) { @unlink( $tmp ); }
			}
			if ( function_exists( 'wp_clean_plugins_cache' ) ) { wp_clean_plugins_cache( true ); }
			$data = get_plugin_data( WP_PLUGIN_DIR . '/doughboss/doughboss.php', false, false );
			if ( self::EXPECTED_TARGET !== (string) ( $data['Version'] ?? '' ) ) { return new WP_Error( 'version', 'The installed DoughBoss version did not match 2.37.0.' ); }
			if ( ! is_plugin_active( 'doughboss/doughboss.php' ) ) { $activated = activate_plugin( 'doughboss/doughboss.php' ); if ( is_wp_error( $activated ) ) { return new WP_Error( 'activation', 'DoughBoss 2.37.0 installed but could not be activated.' ); } }
			if ( class_exists( 'DoughBoss_Activator' ) ) { DoughBoss_Activator::activate(); }
			if ( function_exists( 'flush_rewrite_rules' ) ) { flush_rewrite_rules( false ); }
			$final = self::state();
			if ( self::EXPECTED_TARGET !== $final['version'] || ! $final['active'] || '1.21.0' !== $final['database'] || $final['migration_error'] || ! $final['orders_off'] || ! $final['payments_off'] ) {
				return new WP_Error( 'verification', 'Files installed, but the final safety/database verification did not pass. Protected rollback copy: ' . basename( $backup ) );
			}
			deactivate_plugins( plugin_basename( __FILE__ ), true );
			return array( 'status' => 'complete', 'version' => $final['version'], 'database' => $final['database'], 'backup' => basename( $backup ), 'utc' => gmdate( 'c' ) );
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	private static function copy_tree( $source, $destination ) {
		if ( ! is_dir( $source ) || is_link( $source ) || ( ! is_dir( $destination ) && ! wp_mkdir_p( $destination ) ) ) { return false; }
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) { return false; }
			$relative = substr( $item->getPathname(), strlen( $source ) + 1 );
			$target = $destination . '/' . str_replace( DIRECTORY_SEPARATOR, '/', $relative );
			if ( $item->isDir() ) { if ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) { return false; } }
			elseif ( ! copy( $item->getPathname(), $target ) || filesize( $item->getPathname() ) !== filesize( $target ) || hash_file( 'sha256', $item->getPathname() ) !== hash_file( 'sha256', $target ) ) { return false; }
		}
		return true;
	}
}

DoughBoss_Deploy_2370::init();

