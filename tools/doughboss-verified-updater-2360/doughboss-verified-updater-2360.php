<?php
/**
 * Plugin Name: DoughBoss Verified Updater 2.36.0
 * Description: One-purpose, hash-pinned update of DoughBoss and its Migration Gate with verified external rollback copies.
 * Version: 1.0.0
 * Author: DoughBoss
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DoughBoss_Deploy_Bridge_2360 {
	const RESULT_OPTION     = 'doughboss_deploy_2360_result';
	const JOURNAL_OPTION    = 'doughboss_deploy_2360_journal';
	const DB_LOCK_OPTION    = 'doughboss_deploy_2360_db_lock';
	const LOCK_FILE         = '.doughboss-deploy-2360.lock';

	private static $targets = array(
		'gate' => array(
			'basename' => 'doughboss-migration-gate/doughboss-migration-gate.php',
			'dir'      => 'doughboss-migration-gate',
			'main'     => 'doughboss-migration-gate.php',
			'current'  => '1.0.2',
			'target'   => '1.0.3',
			'name'     => 'DoughBoss Migration Gate',
			'textdomain' => '',
			'url'      => 'https://github.com/edagher92-coder/DOUGHBOSSV2/releases/download/v2.36.0-rc.1/doughboss-migration-gate-1.0.3.zip',
			'bytes'    => 3285,
			'sha256'   => '7365c464f9786627d94d39b878e06a2cf2d247cdc3133d3d1776c7062efe693a',
			'entries'  => 2,
			'unpacked' => 6646,
			'max_file' => 8192,
			'required' => array( 'doughboss-migration-gate.php', 'README.md' ),
		),
		'main' => array(
			'basename' => 'doughboss/doughboss.php',
			'dir'      => 'doughboss',
			'main'     => 'doughboss.php',
			'current'  => '2.34.1',
			'target'   => '2.36.0',
			'name'     => 'DoughBoss',
			'textdomain' => 'doughboss',
			'url'      => 'https://github.com/edagher92-coder/DOUGHBOSSV2/releases/download/v2.36.0-rc.1/doughboss.zip',
			'bytes'    => 2498594,
			'sha256'   => 'b451b72111cd5aa46800ad7a2eebebc0e7e926ced2773666853f4b8a8c1e7f27',
			'entries'  => 131,
			'unpacked' => 3786294,
			'max_file' => 524288,
			'required' => array(
				'doughboss.php',
				'includes/class-doughboss.php',
				'includes/class-doughboss-activator.php',
				'includes/class-doughboss-migrations.php',
				'includes/class-doughboss-portals.php',
				'includes/class-doughboss-staff-scope.php',
				'includes/class-doughboss-staff-experience.php',
				'includes/class-doughboss-timeclock.php',
				'public/css/doughboss-portals.css',
				'public/css/doughboss-timeclock.css',
				'public/js/doughboss-portals.js',
				'uninstall.php'
			),
		),
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_init', array( __CLASS__, 'verify_fresh_request' ), 100 );
	}

	public static function verify_fresh_request() {
		$journal = get_option( self::JOURNAL_OPTION, array() );
		if ( ! is_array( $journal ) || 'files_installed' !== ( $journal['phase'] ?? '' ) ) { return; }
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$settings = get_option( 'doughboss_settings', null );
		$safe = is_array( $settings ) && array_key_exists( 'ordering_open', $settings ) && array_key_exists( 'payments_enabled', $settings ) && self::is_exact_off( $settings['ordering_open'] ) && self::is_exact_off( $settings['payments_enabled'] );
		$files = self::installed_target_valid( self::$targets['gate'], self::$targets['gate']['target'] ) && self::installed_target_valid( self::$targets['main'], self::$targets['main']['target'] ) && is_plugin_active( self::$targets['gate']['basename'] ) && is_plugin_active( self::$targets['main']['basename'] );
		$shield = class_exists( 'DoughBoss_Migration_Gate', false ) && false !== has_action( 'template_redirect', array( 'DoughBoss_Migration_Gate', 'protect_public_site' ) );
		$operational = class_exists( 'DoughBoss_Timeclock', false ) && method_exists( 'DoughBoss_Timeclock', 'storage_ready' ) && DoughBoss_Timeclock::storage_ready() && '3' === (string) get_option( 'doughboss_portal_routes_version', '' );
		if ( $files && $shield && $operational && $safe && '1.20.0' === (string) get_option( 'doughboss_db_version', '' ) && ! get_option( 'doughboss_migration_error', false ) && ! get_option( 'doughboss_migration_lock', false ) ) {
			$journal['phase'] = 'complete'; $journal['completed_utc'] = gmdate( 'c' ); $journal['recovery_required'] = false;
			$result = array( 'time_utc' => gmdate( 'c' ), 'status' => 'complete', 'backup' => sanitize_file_name( (string) ( $journal['backup'] ?? '' ) ) );
			if ( self::write_verified_option( self::JOURNAL_OPTION, $journal ) && self::write_verified_option( self::RESULT_OPTION, $result ) ) {
				deactivate_plugins( plugin_basename( __FILE__ ), true );
			}
		} elseif ( get_option( 'doughboss_migration_error', false ) || ! $files || ! $safe || ! $operational || ! $shield ) {
			$journal['phase'] = 'recovery_required'; $journal['recovery_required'] = true; self::write_verified_option( self::JOURNAL_OPTION, $journal );
		}
	}

	private static function write_verified_option( $name, $value ) {
		return update_option( $name, $value, false ) || get_option( $name ) === $value;
	}

	public static function register_page() {
		add_plugins_page( 'DoughBoss verified deployment', 'DoughBoss 2.36 updater', 'update_plugins', 'doughboss-deploy-2360', array( __CLASS__, 'render_page' ) );
	}

	public static function register_routes() {
		register_rest_route( 'doughboss-updater/v1', '/preflight', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'rest_preflight' ), 'permission_callback' => array( __CLASS__, 'allowed' ) ) );
	}

	public static function allowed() {
		return current_user_can( 'manage_options' ) && current_user_can( 'update_plugins' ) && current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' );
	}

	public static function rest_preflight() { return rest_ensure_response( self::preflight_summary() ); }
	public static function render_page() {
		if ( ! self::allowed() ) {
			wp_die( 'Required administrator permissions are missing.', 'Deployment stopped', array( 'response' => 403 ) );
		}
		$result = null;
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			check_admin_referer( 'doughboss_deploy_2360' );
			try { $result = self::run(); } catch ( Throwable $e ) { $result = new WP_Error( 'unexpected', 'The deployment stopped after an unexpected server error. Inspect the durable recovery journal before retrying.' ); }
			self::store_result( $result );
		}
		$state = self::preflight_summary();
		echo '<div class="wrap"><h1>DoughBoss 2.36.0 verified deployment</h1>';
		if ( is_wp_error( $result ) ) { echo '<div class="notice notice-error"><p><strong>Stopped safely.</strong> ' . esc_html( $result->get_error_message() ) . '</p></div>'; }
		elseif ( is_array( $result ) ) { echo '<div class="notice notice-success"><p><strong>Files installed.</strong> A fresh request will complete and verify the database migration.</p></div>'; }
		echo '<div class="notice notice-warning inline"><p>This one-use installer preserves byte-verified external backups, then updates Migration Gate 1.0.2 to 1.0.3 and DoughBoss 2.34.1 to 2.36.0. It stops unless orders and card payments are both off.</p></div>';
		foreach ( $state as $key => $value ) { echo '<p><strong>' . esc_html( $key ) . ':</strong> ' . esc_html( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value ) . '</p>'; }
		if ( ! empty( $state['ready'] ) ) { echo '<form method="post">'; wp_nonce_field( 'doughboss_deploy_2360' ); submit_button( 'Run verified deployment' ); echo '</form>'; }
		echo '</div>';
	}

	private static function preflight_summary() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$settings = get_option( 'doughboss_settings', null );
		$gate = self::$targets['gate']; $main = self::$targets['main'];
		$ready = is_array( $settings ) && array_key_exists( 'ordering_open', $settings ) && array_key_exists( 'payments_enabled', $settings )
			&& self::is_exact_off( $settings['ordering_open'] ) && self::is_exact_off( $settings['payments_enabled'] )
			&& '1.18.0' === (string) get_option( 'doughboss_db_version', '' ) && ! get_option( 'doughboss_migration_error', false ) && ! get_option( 'doughboss_migration_lock', false )
			&& self::installed_target_valid( $gate, $gate['current'] ) && self::installed_target_valid( $main, $main['current'] )
			&& is_plugin_active( $gate['basename'] ) && is_plugin_active( $main['basename'] );
		return array( 'ready' => $ready, 'orders_off' => is_array( $settings ) && array_key_exists( 'ordering_open', $settings ) && self::is_exact_off( $settings['ordering_open'] ), 'payments_off' => is_array( $settings ) && array_key_exists( 'payments_enabled', $settings ) && self::is_exact_off( $settings['payments_enabled'] ), 'database' => (string) get_option( 'doughboss_db_version', '' ), 'gate' => self::installed_target_valid( $gate, $gate['current'] ) ? $gate['current'] : 'unexpected', 'doughboss' => self::installed_target_valid( $main, $main['current'] ) ? $main['current'] : 'unexpected' );
	}

	private static function is_exact_off( $value ) { return false === $value || 0 === $value || '0' === $value; }

	private static function run() {
		if ( is_multisite() || ! is_ssl() || 'https' !== wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) || 'https' !== wp_parse_url( admin_url( '/' ), PHP_URL_SCHEME ) ) {
			return new WP_Error( 'environment', 'Deployment requires a single HTTPS WordPress site.' );
		}
		foreach ( array( 'manage_options', 'update_plugins', 'install_plugins', 'activate_plugins' ) as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				return new WP_Error( 'permission', 'Required administrator plugin permissions are missing.' );
			}
		}
		$lock_path = WP_CONTENT_DIR . '/' . self::LOCK_FILE;
		$lock = @fopen( $lock_path, 'c' );
		if ( ! is_resource( $lock ) || ! @flock( $lock, LOCK_EX | LOCK_NB ) ) {
			return new WP_Error( 'locked', 'Another DoughBoss deployment is already running.' );
		}
		@chmod( $lock_path, 0600 );
		$owns_db_lock = false;
		try {
			$owns_db_lock = add_option( self::DB_LOCK_OPTION, array( 'started_utc' => gmdate( 'c' ), 'user_id' => get_current_user_id() ), '', 'no' );
			if ( ! $owns_db_lock ) { return new WP_Error( 'database_locked', 'Another verified deployment owns the database lock.' ); }
			return self::run_locked();
		} finally {
			if ( $owns_db_lock ) { delete_option( self::DB_LOCK_OPTION ); }
			@flock( $lock, LOCK_UN );
			@fclose( $lock );
		}
	}

	private static function run_locked() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return new WP_Error( 'file_mods', 'WordPress file modifications are disabled.' );
		}
		if ( 'direct' !== get_filesystem_method() ) {
			return new WP_Error( 'filesystem', 'Direct WordPress filesystem access is unavailable.' );
		}
		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! is_object( $wp_filesystem ) || 'direct' !== $wp_filesystem->method ) { return new WP_Error( 'filesystem_init', 'WordPress could not initialise direct filesystem access.' ); }
		$settings = get_option( 'doughboss_settings', null );
		if ( ! is_array( $settings ) || ! array_key_exists( 'ordering_open', $settings ) || ! array_key_exists( 'payments_enabled', $settings ) || ! self::is_exact_off( $settings['ordering_open'] ) || ! self::is_exact_off( $settings['payments_enabled'] ) ) {
			return new WP_Error( 'safety_toggles', 'Orders and card payments must both be off.' );
		}
		if ( '1.18.0' !== (string) get_option( 'doughboss_db_version', '' ) || get_option( 'doughboss_migration_error', false ) || get_option( 'doughboss_migration_lock', false ) ) {
			return new WP_Error( 'database_state', 'The expected safe 1.18.0 database baseline was not found.' );
		}
		foreach ( self::$targets as $target ) {
			if ( ! self::installed_target_valid( $target, $target['current'] ) || ! is_plugin_active( $target['basename'] ) ) {
				return new WP_Error( 'baseline', 'The canonical active plugin baseline did not match the approved versions.' );
			}
		}
		$backup_root = self::external_backup_root();
		if ( is_wp_error( $backup_root ) ) {
			return $backup_root;
		}
		$free = @disk_free_space( dirname( ABSPATH ) );
		$stage_free = @disk_free_space( WP_CONTENT_DIR . '/upgrade' );
		$live_bytes = self::tree_bytes( WP_PLUGIN_DIR . '/doughboss' ) + self::tree_bytes( WP_PLUGIN_DIR . '/doughboss-migration-gate' );
		if ( $live_bytes < 0 ) { return new WP_Error( 'tree_size', 'A live plugin tree could not be measured safely.' ); }
		$needed = 3 * ( self::$targets['main']['bytes'] + self::$targets['gate']['bytes'] + $live_bytes );
		if ( false === $free || false === $stage_free || (float) $free < (float) $needed || (float) $stage_free < (float) $needed ) {
			return new WP_Error( 'space', 'The host does not have enough verified free space for deployment and rollback.' );
		}
		$source_manifests = array();
		foreach ( self::$targets as $key => $target ) {
			$source_manifests[ $key ] = self::manifest( WP_PLUGIN_DIR . '/' . $target['dir'] );
			if ( is_wp_error( $source_manifests[ $key ] ) ) { return $source_manifests[ $key ]; }
			$copied = self::backup_tree( WP_PLUGIN_DIR . '/' . $target['dir'], $backup_root . '/' . $target['dir'] );
			if ( is_wp_error( $copied ) ) {
				return $copied;
			}
			$manifest_file = $backup_root . '/' . $key . '-manifest.json';
			$manifest_json = wp_json_encode( $source_manifests[ $key ], JSON_UNESCAPED_SLASHES );
			if ( false === @file_put_contents( $manifest_file, $manifest_json, LOCK_EX ) ) { return new WP_Error( 'manifest_write', 'A protected backup manifest could not be written.' ); }
			@chmod( $manifest_file, 0600 );
			$source_manifests[ $key . '_digest' ] = hash( 'sha256', $manifest_json );
		}
		$journal = array( 'phase' => 'backed_up', 'recovery_required' => false, 'started_utc' => gmdate( 'c' ), 'backup' => basename( $backup_root ), 'manifest_sha256' => array( 'gate' => $source_manifests['gate_digest'], 'main' => $source_manifests['main_digest'] ) );
		if ( ! update_option( self::JOURNAL_OPTION, $journal, false ) && get_option( self::JOURNAL_OPTION ) !== $journal ) { return new WP_Error( 'journal_backup', 'The verified-backup journal could not be persisted.' ); }
		foreach ( array( 'gate', 'main' ) as $key ) {
			if ( $source_manifests[ $key ] !== self::manifest( WP_PLUGIN_DIR . '/' . self::$targets[ $key ]['dir'] ) ) { return new WP_Error( 'source_changed', 'A live plugin changed after backup; deployment stopped before replacing it.' ); }
			$journal['phase'] = 'cutover_' . $key . '_pending'; $journal['recovery_required'] = true;
			if ( ! update_option( self::JOURNAL_OPTION, $journal, false ) && get_option( self::JOURNAL_OPTION ) !== $journal ) { return new WP_Error( 'journal_write', 'The durable recovery journal could not be checkpointed.' ); }
			$installed = self::install_target( self::$targets[ $key ] );
			if ( is_wp_error( $installed ) ) {
				$restored = self::restore_all( $backup_root );
				$journal['phase'] = is_wp_error( $restored ) ? 'recovery_required' : 'rolled_back'; $journal['recovery_required'] = is_wp_error( $restored );
				if ( ! update_option( self::JOURNAL_OPTION, $journal, false ) && get_option( self::JOURNAL_OPTION ) !== $journal ) { return new WP_Error( 'journal_rollback', 'The rollback result could not be durably recorded.' ); }
				return is_wp_error( $restored ) ? $restored : $installed;
			}
			$journal['phase'] = 'cutover_' . $key . '_complete'; if ( ! update_option( self::JOURNAL_OPTION, $journal, false ) && get_option( self::JOURNAL_OPTION ) !== $journal ) { return new WP_Error( 'journal_cutover', 'The cutover checkpoint could not be persisted.' ); }
		}
		$journal['phase'] = 'files_installed'; $journal['recovery_required'] = true;
		if ( ! update_option( self::JOURNAL_OPTION, $journal, false ) && get_option( self::JOURNAL_OPTION ) !== $journal ) { return new WP_Error( 'journal_finalize', 'The installed-files recovery checkpoint could not be persisted.' ); }
		return array( 'status' => 'files_installed', 'backup' => basename( $backup_root ) );
	}

	private static function external_backup_root() {
		$parent = realpath( dirname( ABSPATH ) );
		if ( false === $parent || '/' === wp_normalize_path( $parent ) || is_link( dirname( ABSPATH ) ) || ! is_dir( $parent ) || ! is_writable( $parent ) || 0 === strpos( wp_normalize_path( $parent ), trailingslashit( wp_normalize_path( ABSPATH ) ) ) ) {
			return new WP_Error( 'backup_parent', 'A writable backup location outside the public site was not available.' );
		}
		$base = $parent . '/doughboss-deploy-backups';
		if ( ! is_dir( $base ) && ! @mkdir( $base, 0700 ) ) {
			return new WP_Error( 'backup_base', 'The protected backup base could not be created.' );
		}
		$real_base = realpath( $base );
		if ( is_link( $base ) || false === $real_base || wp_normalize_path( $real_base ) !== wp_normalize_path( $base ) || trailingslashit( wp_normalize_path( dirname( $real_base ) ) ) !== trailingslashit( wp_normalize_path( $parent ) ) ) { return new WP_Error( 'backup_base_link', 'The protected backup base was not a canonical normal child directory.' ); }
		$root = $base . '/' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 12, false, false ) );
		if ( ! @mkdir( $root, 0700 ) ) {
			return new WP_Error( 'backup_root', 'A unique protected backup directory could not be created.' );
		}
		$real_root = realpath( $root ); $real_abspath = realpath( ABSPATH );
		if ( false === $real_root || false === $real_abspath || 0 === strpos( trailingslashit( wp_normalize_path( $real_root ) ), trailingslashit( wp_normalize_path( $real_abspath ) ) ) ) { return new WP_Error( 'backup_escape', 'The protected backup directory could not be proven outside the public site.' ); }
		return $real_root;
	}

	private static function backup_tree( $source, $destination ) {
		$source_manifest = self::manifest( $source );
		if ( is_wp_error( $source_manifest ) || ! @mkdir( $destination, 0700 ) ) {
			return new WP_Error( 'backup_copy', 'A complete protected plugin backup could not be started.' );
		}
		foreach ( $source_manifest as $relative => $identity ) {
			$from = $source . '/' . $relative;
			$to = $destination . '/' . $relative;
			if ( ! is_dir( dirname( $to ) ) && ! wp_mkdir_p( dirname( $to ) ) ) {
				return new WP_Error( 'backup_dir', 'A protected backup directory could not be created.' );
			}
			if ( ! @copy( $from, $to ) || ! @chmod( $to, 0600 ) ) {
				return new WP_Error( 'backup_file', 'A protected backup file could not be copied.' );
			}
		}
		$backup_manifest = self::manifest( $destination );
		if ( is_wp_error( $backup_manifest ) || $source_manifest !== $backup_manifest ) {
			return new WP_Error( 'backup_verify', 'The protected backup did not match the live plugin byte for byte.' );
		}
		return true;
	}

	private static function install_target( $target ) {
		$tmp = download_url( $target['url'], 300 );
		if ( is_wp_error( $tmp ) || ! is_file( $tmp ) || is_link( $tmp ) ) {
			return new WP_Error( 'download', 'A release package could not be downloaded as a normal file.' );
		}
		$extract_root = '';
		try {
			clearstatcache( true, $tmp );
			if ( (int) filesize( $tmp ) !== (int) $target['bytes'] || ! hash_equals( $target['sha256'], strtolower( (string) hash_file( 'sha256', $tmp ) ) ) ) {
				return new WP_Error( 'package_identity', 'A release package failed its exact size or SHA-256 check.' );
			}
			$zip_check = self::validate_zip_entries( $tmp, $target['dir'] );
			if ( is_wp_error( $zip_check ) ) {
				return $zip_check;
			}
			$upgrade = WP_CONTENT_DIR . '/upgrade';
			if ( ! is_dir( $upgrade ) && ! wp_mkdir_p( $upgrade ) ) {
				return new WP_Error( 'upgrade_dir', 'The WordPress upgrade folder could not be created.' );
			}
			$extract_root = $upgrade . '/db2360-' . strtolower( wp_generate_password( 12, false, false ) );
			if ( ! @mkdir( $extract_root, 0700 ) ) {
				return new WP_Error( 'extract_root', 'A protected extraction directory could not be created.' );
			}
			$unzipped = unzip_file( $tmp, $extract_root );
			if ( is_wp_error( $unzipped ) ) {
				return new WP_Error( 'unzip', 'The hash-verified package could not be unpacked.' );
			}
			$candidate = $extract_root . '/' . $target['dir'];
			$valid = self::candidate_valid( $candidate, $target );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$current = WP_PLUGIN_DIR . '/' . $target['dir'];
			$failed = WP_CONTENT_DIR . '/upgrade/doughboss-displaced-' . $target['dir'] . '-' . strtolower( wp_generate_password( 12, false, false ) );
			$current_stat = @stat( dirname( $current ) ); $candidate_stat = @stat( dirname( $candidate ) ); $failed_stat = @stat( dirname( $failed ) );
			if ( ! is_array( $current_stat ) || ! is_array( $candidate_stat ) || ! is_array( $failed_stat ) || $current_stat['dev'] !== $candidate_stat['dev'] || $current_stat['dev'] !== $failed_stat['dev'] ) { return new WP_Error( 'cross_device', 'Atomic cutover could not be proven to remain on one filesystem.' ); }
			if ( ! @rename( $current, $failed ) || ! @rename( $candidate, $current ) ) {
				if ( ! is_dir( $current ) && is_dir( $failed ) ) {
					@rename( $failed, $current );
				}
				return new WP_Error( 'cutover', 'The canonical plugin folder could not be replaced safely.' );
			}
			wp_clean_plugins_cache( true );
			if ( ! self::installed_target_valid( $target, $target['target'] ) || ! is_plugin_active( $target['basename'] ) ) {
				return new WP_Error( 'post_install', 'Installed file verification failed.' );
			}
			return true;
		} finally {
			if ( is_string( $tmp ) && is_file( $tmp ) ) {
				@unlink( $tmp );
			}
			if ( $extract_root && is_dir( $extract_root ) ) {
				self::remove_tree( $extract_root );
			}
		}
	}

	private static function validate_zip_entries( $zip_path, $root ) {
		$target = 'doughboss' === $root ? self::$targets['main'] : self::$targets['gate'];
		if ( class_exists( 'ZipArchive' ) ) {
			return self::validate_with_ziparchive( $zip_path, $root, $target );
		}

		// WordPress ships PclZip specifically for hosts where ext-zip is not
		// available. The release bytes are already pinned by exact size and
		// SHA-256; this fallback independently validates every member before
		// WordPress extracts the package, and candidate_valid() revalidates the
		// resulting normal-file tree afterwards.
		$pclzip_file = ABSPATH . 'wp-admin/includes/class-pclzip.php';
		if ( ! class_exists( 'PclZip' ) && is_file( $pclzip_file ) && ! is_link( $pclzip_file ) ) {
			require_once $pclzip_file;
		}
		if ( ! class_exists( 'PclZip' ) ) {
			return new WP_Error( 'archive_inspector', 'Neither ZipArchive nor the bundled WordPress PclZip inspector is available.' );
		}

		$archive = new PclZip( $zip_path );
		$list    = $archive->listContent();
		if ( ! is_array( $list ) ) {
			return new WP_Error( 'pclzip_open', 'The release archive could not be safely inspected by WordPress PclZip.' );
		}
		$entries = array();
		foreach ( $list as $member ) {
			if ( ! is_array( $member ) || ( isset( $member['status'] ) && 'ok' !== strtolower( (string) $member['status'] ) ) ) {
				return new WP_Error( 'pclzip_member', 'WordPress PclZip reported an invalid release archive member.' );
			}
			$name = isset( $member['stored_filename'] ) && '' !== (string) $member['stored_filename'] ? (string) $member['stored_filename'] : ( isset( $member['filename'] ) ? (string) $member['filename'] : '' );
			$entries[] = array(
				'name'            => $name,
				'size'            => isset( $member['size'] ) ? (int) $member['size'] : -1,
				'compressed_size' => isset( $member['compressed_size'] ) ? (int) $member['compressed_size'] : -1,
				'type'            => ! empty( $member['folder'] ) ? 'directory' : 'file',
			);
		}
		return self::validate_archive_members( $entries, $root, $target, false );
	}

	private static function validate_with_ziparchive( $zip_path, $root, $target ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'zip_open', 'The release archive could not be safely inspected.' );
		}
		$entries = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$type = '/' === substr( isset( $stat['name'] ) ? (string) $stat['name'] : '', -1 ) ? 'directory' : 'file';
			if ( method_exists( $zip, 'getExternalAttributesIndex' ) ) {
				$opsys = 0;
				$attr = 0;
				if ( $zip->getExternalAttributesIndex( $i, $opsys, $attr ) ) {
					$mode_type = ( $attr >> 16 ) & 0170000;
					if ( 0 !== $mode_type && 0100000 !== $mode_type && 0040000 !== $mode_type ) { $type = 'special'; }
				}
			}
			$entries[] = array( 'name' => isset( $stat['name'] ) ? (string) $stat['name'] : '', 'size' => isset( $stat['size'] ) ? (int) $stat['size'] : -1, 'compressed_size' => isset( $stat['comp_size'] ) ? (int) $stat['comp_size'] : -1, 'type' => $type );
		}
		$zip->close();
		return self::validate_archive_members( $entries, $root, $target, true );
	}

	private static function validate_archive_members( $entries, $root, $target, $type_metadata_complete ) {
		if ( count( $entries ) !== (int) $target['entries'] ) { return new WP_Error( 'zip_entries', 'The release archive entry count did not match the approved package.' ); }
		$total = 0;
		$seen  = array();
		foreach ( $entries as $entry ) {
			$name       = (string) $entry['name'];
			$size       = (int) $entry['size'];
			$compressed = (int) $entry['compressed_size'];
			$type       = (string) $entry['type'];
			$normalized = strtolower( rtrim( $name, '/' ) );
			if ( '' === $name || false !== strpos( $name, "\0" ) || false !== strpos( $name, '\\' ) || false !== strpos( $name, '//' ) || preg_match( '#^[a-zA-Z]:#', $name ) || 0 === strpos( $name, '/' ) || ! preg_match( '#^' . preg_quote( $root, '#' ) . '/#', $name ) || preg_match( '#(^|/)\.\.?(/|$)#', $name ) || isset( $seen[ $normalized ] ) ) { return new WP_Error( 'zip_path', 'The release archive contained an unsafe or duplicate path.' ); }
			$seen[ $normalized ] = true;
			if ( $type_metadata_complete && ! in_array( $type, array( 'file', 'directory' ), true ) ) { return new WP_Error( 'zip_special', 'The release archive contained a link or special file.' ); }
			if ( $size < 0 || $compressed < 0 || $size > (int) $target['max_file'] || ( 'directory' === $type && 0 !== $size ) ) { return new WP_Error( 'zip_member_size', 'A release archive member had invalid or excessive size metadata.' ); }
			if ( $size > 0 && $compressed <= 0 ) { return new WP_Error( 'zip_compression', 'A non-empty archive member had invalid compressed-size metadata.' ); }
			if ( $size > 0 && ( $size / $compressed ) > 100 ) { return new WP_Error( 'zip_ratio', 'A release archive member had an unsafe compression ratio.' ); }
			if ( $total > PHP_INT_MAX - $size ) { return new WP_Error( 'zip_overflow', 'The release archive size metadata overflowed the safety counter.' ); }
			$total += $size;
			if ( $total > (int) $target['unpacked'] ) { return new WP_Error( 'zip_size', 'The release archive expanded beyond the approved safety limit.' ); }
		}
		if ( $total !== (int) $target['unpacked'] ) { return new WP_Error( 'zip_unpacked', 'The release archive expanded size did not match the approved package.' ); }
		return true;
	}

	private static function candidate_valid( $candidate, $target ) {
		$manifest = self::manifest( $candidate );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		foreach ( $target['required'] as $required ) {
			if ( ! isset( $manifest[ $required ] ) ) {
				return new WP_Error( 'required_file', 'A required production file was missing.' );
			}
		}
		$data = get_plugin_data( $candidate . '/' . $target['main'], false, false );
		if ( $target['name'] !== (string) $data['Name'] || $target['target'] !== (string) $data['Version'] ) {
			return new WP_Error( 'plugin_identity', 'The extracted plugin name or version did not match the approved target.' );
		}
		if ( '' !== $target['textdomain'] && $target['textdomain'] !== (string) $data['TextDomain'] ) {
			return new WP_Error( 'textdomain', 'The extracted plugin text domain did not match the approved target.' );
		}
		return true;
	}

	private static function installed_target_valid( $target, $version ) {
		$dir = WP_PLUGIN_DIR . '/' . $target['dir'];
		if ( ! is_dir( $dir ) || is_link( $dir ) || ! is_file( $dir . '/' . $target['main'] ) || is_link( $dir . '/' . $target['main'] ) ) {
			return false;
		}
		$data = get_plugin_data( $dir . '/' . $target['main'], false, false );
		if ( $target['name'] !== (string) $data['Name'] || $version !== (string) $data['Version'] || ( '' !== $target['textdomain'] && $target['textdomain'] !== (string) $data['TextDomain'] ) ) {
			return false;
		}
		if ( $target['target'] === $version ) {
			$manifest = self::manifest( $dir );
			if ( is_wp_error( $manifest ) ) {
				return false;
			}
			foreach ( $target['required'] as $required ) {
				if ( ! isset( $manifest[ $required ] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	private static function manifest( $root ) {
		if ( ! is_dir( $root ) || is_link( $root ) ) {
			return new WP_Error( 'manifest_root', 'A plugin folder was not a normal directory.' );
		}
		$out = array();
		$prefix = trailingslashit( wp_normalize_path( $root ) );
		try {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $iterator as $entry ) {
				$path = wp_normalize_path( $entry->getPathname() );
				if ( 0 !== strpos( $path, $prefix ) || $entry->isLink() ) {
					return new WP_Error( 'manifest_link', 'A plugin tree contained an unsafe path or symbolic link.' );
				}
				if ( $entry->isFile() ) {
					$relative = substr( $path, strlen( $prefix ) );
					$out[ $relative ] = array( (int) $entry->getSize(), strtolower( (string) hash_file( 'sha256', $entry->getPathname() ) ) );
				}
			}
		} catch ( UnexpectedValueException $e ) {
			return new WP_Error( 'manifest_scan', 'A plugin tree could not be fully inspected.' );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	private static function tree_bytes( $root ) {
		$manifest = self::manifest( $root );
		if ( is_wp_error( $manifest ) ) {
			return -1;
		}
		$total = 0;
		foreach ( $manifest as $item ) {
			if ( (int) $item[0] < 0 || $total > PHP_INT_MAX - (int) $item[0] ) { return -1; }
			$total += (int) $item[0];
		}
		return $total;
	}

	private static function restore_all( $backup_root ) {
		$failures = array();
		$journal = get_option( self::JOURNAL_OPTION, array() );
		foreach ( self::$targets as $key => $target ) {
			$current = WP_PLUGIN_DIR . '/' . $target['dir'];
			$backup = $backup_root . '/' . $target['dir'];
			$manifest = self::manifest( $backup );
			$manifest_file = $backup_root . '/' . $key . '-manifest.json';
			$manifest_json = is_file( $manifest_file ) && ! is_link( $manifest_file ) ? file_get_contents( $manifest_file ) : false;
			$expected_digest = is_array( $journal ) && isset( $journal['manifest_sha256'][ $key ] ) ? (string) $journal['manifest_sha256'][ $key ] : '';
			$expected_manifest = is_string( $manifest_json ) ? json_decode( $manifest_json, true ) : null;
			if ( is_wp_error( $manifest ) || ! is_array( $expected_manifest ) || ! hash_equals( $expected_digest, hash( 'sha256', (string) $manifest_json ) ) || $expected_manifest !== $manifest ) {
				$failures[] = $target['dir']; continue;
			}
			$quarantine = WP_CONTENT_DIR . '/upgrade/failed-' . $target['dir'] . '-' . strtolower( wp_generate_password( 8, false, false ) );
			if ( is_dir( $current ) && ! @rename( $current, $quarantine ) ) {
				$failures[] = $target['dir']; continue;
			}
			$copy = self::backup_tree( $backup, $current );
			if ( is_wp_error( $copy ) ) {
				$failures[] = $target['dir']; continue;
			}
			if ( $manifest !== self::manifest( $current ) || ! self::installed_target_valid( $target, $target['current'] ) ) { $failures[] = $target['dir']; }
		}
		wp_clean_plugins_cache( true );
		foreach ( self::$targets as $target ) { if ( ! is_plugin_active( $target['basename'] ) || ! self::installed_target_valid( $target, $target['current'] ) ) { $failures[] = $target['dir']; } }
		return empty( $failures ) ? true : new WP_Error( 'rollback_incomplete', 'One or more canonical plugin folders require host-assisted recovery from the preserved external backup.' );
	}

	private static function remove_tree( $root ) {
		$root = wp_normalize_path( $root );
		$expected = trailingslashit( wp_normalize_path( WP_CONTENT_DIR . '/upgrade' ) );
		if ( trailingslashit( wp_normalize_path( dirname( $root ) ) ) !== $expected || ! preg_match( '/^db2360-[a-z0-9]{12}$/', basename( $root ) ) || is_link( $root ) ) {
			return;
		}
		try {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $entry ) {
				$entry->isDir() && ! $entry->isLink() ? @rmdir( $entry->getPathname() ) : @unlink( $entry->getPathname() );
			}
		} catch ( UnexpectedValueException $e ) {
			return;
		}
		@rmdir( $root );
	}

	private static function store_result( $result ) {
		$value = array( 'time_utc' => gmdate( 'c' ) );
		if ( is_wp_error( $result ) ) {
			$value['status'] = 'stopped';
			$value['code'] = sanitize_key( $result->get_error_code() );
		} else {
			$value['status'] = 'files_installed';
			$value['backup'] = sanitize_file_name( (string) $result['backup'] );
		}
		update_option( self::RESULT_OPTION, $value, false );
	}
}

DoughBoss_Deploy_Bridge_2360::init();
