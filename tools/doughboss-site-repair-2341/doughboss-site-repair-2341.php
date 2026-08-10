<?php
/**
 * Plugin Name: DoughBoss Site Repair 2.34.1
 * Description: Hash-pinned, rollback-safe repair for DoughBoss 2.34.1 and DoughBoss Final 1.3.0.
 * Version: 1.0.0
 * Author: DoughBoss
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DoughBoss_Site_Repair_2341 {
	const PAGE = 'doughboss-site-repair-2341';
	const PLUGIN_BASENAME = 'doughboss/doughboss.php';
	const CURRENT_PLUGIN = '2.34.0';
	const TARGET_PLUGIN = '2.34.1';
	const CURRENT_THEME = '1.2.0';
	const TARGET_THEME = '1.3.0';
	const THEME_SLUG = 'doughboss-final';
	const PLUGIN_URL = 'https://github.com/edagher92-coder/DOUGHBOSSV2/releases/download/v2.34.1-rc.1/doughboss.zip';
	const PLUGIN_BYTES = 2475049;
	const PLUGIN_SHA256 = '75680c4c9b1275525c31805c373c3ee8c7576854fd5cb9449424a53313587811';
	const THEME_URL = 'https://github.com/edagher92-coder/DOUGHBOSSV2/releases/download/v2.34.1-rc.1/doughboss-final.zip';
	const THEME_BYTES = 24982;
	const THEME_SHA256 = 'e5a2553668dfd094c590a6619f576816e461b9cba8934f63253264826e1f5daf';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function menu() {
		add_plugins_page( 'DoughBoss Site Repair', 'DoughBoss Site Repair', 'update_plugins', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function routes() {
		register_rest_route( 'doughboss-site-repair/v1', '/preflight', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'rest_preflight' ),
			'permission_callback' => array( __CLASS__, 'allowed' ),
		) );
		register_rest_route( 'doughboss-site-repair/v1', '/run', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'rest_run' ),
			'permission_callback' => array( __CLASS__, 'allowed' ),
		) );
	}

	public static function allowed() {
		return current_user_can( 'update_plugins' ) && current_user_can( 'activate_plugins' ) && current_user_can( 'switch_themes' );
	}

	public static function rest_preflight() {
		return rest_ensure_response( self::state() );
	}

	public static function rest_run() {
		$result = self::run();
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( array_merge( array( 'success' => true ), self::state(), $result ) );
	}

	public static function render() {
		if ( ! self::allowed() ) { wp_die( esc_html__( 'Administrator plugin and theme update permissions are required.', 'doughboss' ) ); }
		$result = null;
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			check_admin_referer( 'doughboss_site_repair_2341' );
			$result = self::run();
		}
		$state = self::state();
		?>
		<div class="wrap">
			<h1>DoughBoss verified site repair</h1>
			<?php if ( is_wp_error( $result ) ) : ?>
				<div class="notice notice-error"><p><strong>Repair stopped safely.</strong> <?php echo esc_html( $result->get_error_message() ); ?></p></div>
			<?php elseif ( is_array( $result ) ) : ?>
				<div class="notice notice-success"><p><strong>Repair complete.</strong> DoughBoss 2.34.1 and DoughBoss Final 1.3.0 are installed. Both previous folders were preserved.</p></div>
			<?php endif; ?>
			<p>This one-purpose helper updates only the canonical DoughBoss plugin and active DoughBoss Final theme folders. It does not change content, users, orders, payment settings, credentials, or the database.</p>
			<table class="widefat striped" style="max-width:950px"><tbody>
				<tr><th>Canonical plugin</th><td><?php echo esc_html( $state['plugin_version'] ); ?> <?php echo $state['plugin_active'] ? '(active)' : '(not active)'; ?></td></tr>
				<tr><th>Active theme</th><td><?php echo esc_html( $state['theme_slug'] . ' ' . $state['theme_version'] ); ?></td></tr>
				<tr><th>Target</th><td>DoughBoss 2.34.1 + DoughBoss Final 1.3.0</td></tr>
				<tr><th>Plugin SHA-256</th><td><code><?php echo esc_html( self::PLUGIN_SHA256 ); ?></code></td></tr>
				<tr><th>Theme SHA-256</th><td><code><?php echo esc_html( self::THEME_SHA256 ); ?></code></td></tr>
			</tbody></table>
			<?php if ( ! $state['target_ready'] ) : ?>
				<form method="post" style="margin-top:20px"><?php wp_nonce_field( 'doughboss_site_repair_2341' ); ?><?php submit_button( 'Install verified site repair' ); ?></form>
			<?php else : ?><p><strong>The target plugin and theme are already installed.</strong></p><?php endif; ?>
		</div>
		<?php
	}

	private static function state() {
		if ( ! function_exists( 'is_plugin_active' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
		$plugin_version = self::plugin_version( WP_PLUGIN_DIR . '/doughboss/doughboss.php' );
		$theme = wp_get_theme( self::THEME_SLUG );
		$theme_version = $theme->exists() ? (string) $theme->get( 'Version' ) : '';
		$theme_slug = (string) get_stylesheet();
		return array(
			'plugin_version' => $plugin_version,
			'plugin_active' => is_plugin_active( self::PLUGIN_BASENAME ),
			'theme_slug' => $theme_slug,
			'theme_version' => $theme_version,
			'target_ready' => self::TARGET_PLUGIN === $plugin_version && self::TARGET_THEME === $theme_version && self::THEME_SLUG === $theme_slug,
			'plugin_package' => array( 'bytes' => self::PLUGIN_BYTES, 'sha256' => self::PLUGIN_SHA256 ),
			'theme_package' => array( 'bytes' => self::THEME_BYTES, 'sha256' => self::THEME_SHA256 ),
		);
	}

	private static function plugin_version( $file ) {
		if ( ! is_file( $file ) || is_link( $file ) ) { return ''; }
		if ( ! function_exists( 'get_plugin_data' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
		$data = get_plugin_data( $file, false, false );
		return isset( $data['Version'] ) ? (string) $data['Version'] : '';
	}

	private static function run() {
		if ( ! self::allowed() ) { return new WP_Error( 'dbsr_permission', 'Administrator plugin and theme update permissions are required.' ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( 'direct' !== get_filesystem_method() ) { return new WP_Error( 'dbsr_filesystem_method', 'Direct WordPress filesystem access is required. Nothing was changed.' ); }
		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! is_object( $wp_filesystem ) || 'direct' !== $wp_filesystem->method ) {
			return new WP_Error( 'dbsr_filesystem', 'WordPress could not initialise direct filesystem access. Nothing was changed.' );
		}
		$lock_path = WP_CONTENT_DIR . '/.doughboss-site-repair-2341.lock';
		$lock = @fopen( $lock_path, 'c' );
		if ( ! is_resource( $lock ) ) { return new WP_Error( 'dbsr_lock_open', 'The repair lock could not be opened. Nothing was changed.' ); }
		@chmod( $lock_path, 0600 );
		if ( ! @flock( $lock, LOCK_EX | LOCK_NB ) ) { @fclose( $lock ); return new WP_Error( 'dbsr_locked', 'Another repair is already running. Nothing was changed.' ); }
		try { return self::run_locked(); }
		finally { @flock( $lock, LOCK_UN ); @fclose( $lock ); }
	}

	private static function run_locked() {
		$state = self::state();
		if ( $state['target_ready'] ) { return array( 'plugin_backup' => 'No changes required', 'theme_backup' => 'No changes required' ); }
		if ( self::CURRENT_PLUGIN !== $state['plugin_version'] || ! $state['plugin_active'] ) {
			return new WP_Error( 'dbsr_plugin_state', 'Expected the active canonical DoughBoss 2.34.0 installation. Nothing was changed.' );
		}
		if ( self::THEME_SLUG !== $state['theme_slug'] || self::CURRENT_THEME !== $state['theme_version'] ) {
			return new WP_Error( 'dbsr_theme_state', 'Expected the active DoughBoss Final 1.2.0 theme. Nothing was changed.' );
		}
		$plugin_dir = WP_PLUGIN_DIR . '/doughboss';
		$theme_dir = get_theme_root( self::THEME_SLUG ) . '/' . self::THEME_SLUG;
		if ( ! self::normal_dir( $plugin_dir ) || ! self::normal_dir( $theme_dir ) ) {
			return new WP_Error( 'dbsr_layout', 'The canonical plugin or theme is not a normal directory. Nothing was changed.' );
		}
		if ( ! is_writable( WP_PLUGIN_DIR ) || ! is_writable( get_theme_root( self::THEME_SLUG ) ) || ! is_writable( WP_CONTENT_DIR ) ) {
			return new WP_Error( 'dbsr_writable', 'A required WordPress directory is not writable. Nothing was changed.' );
		}

		$plugin_zip = self::download_verified( self::PLUGIN_URL, self::PLUGIN_BYTES, self::PLUGIN_SHA256, 'plugin' );
		if ( is_wp_error( $plugin_zip ) ) { return $plugin_zip; }
		$theme_zip = self::download_verified( self::THEME_URL, self::THEME_BYTES, self::THEME_SHA256, 'theme' );
		if ( is_wp_error( $theme_zip ) ) { @unlink( $plugin_zip ); return $theme_zip; }
		$extract_root = '';
		try {
			$upgrade = WP_CONTENT_DIR . '/upgrade';
			if ( ! is_dir( $upgrade ) && ! wp_mkdir_p( $upgrade ) ) { return new WP_Error( 'dbsr_upgrade', 'The protected upgrade directory could not be created. Nothing was changed.' ); }
			$extract_root = $upgrade . '/doughboss-site-repair-2341-' . strtolower( wp_generate_password( 12, false, false ) );
			if ( file_exists( $extract_root ) || ! wp_mkdir_p( $extract_root . '/plugin' ) || ! wp_mkdir_p( $extract_root . '/theme' ) ) {
				return new WP_Error( 'dbsr_temp', 'A unique protected repair directory could not be created. Nothing was changed.' );
			}
			$unzip_plugin = unzip_file( $plugin_zip, $extract_root . '/plugin' );
			$unzip_theme = unzip_file( $theme_zip, $extract_root . '/theme' );
			if ( is_wp_error( $unzip_plugin ) || is_wp_error( $unzip_theme ) ) { return new WP_Error( 'dbsr_unzip', 'A hash-verified package could not be unpacked. Nothing was changed.' ); }
			$plugin_candidate = $extract_root . '/plugin/doughboss';
			$theme_candidate = $extract_root . '/theme/' . self::THEME_SLUG;
			$valid = self::validate_candidates( $plugin_candidate, $theme_candidate );
			if ( is_wp_error( $valid ) ) { return $valid; }

			$suffix = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 8, false, false ) );
			$plugin_backup = WP_CONTENT_DIR . '/doughboss-backup-' . self::CURRENT_PLUGIN . '-pre-' . self::TARGET_PLUGIN . '-' . $suffix;
			$theme_backup = WP_CONTENT_DIR . '/doughboss-final-backup-' . self::CURRENT_THEME . '-pre-' . self::TARGET_THEME . '-' . $suffix;
			if ( file_exists( $plugin_backup ) || file_exists( $theme_backup ) ) { return new WP_Error( 'dbsr_backup_exists', 'A unique backup path already exists. Nothing was changed.' ); }

			if ( ! @rename( $plugin_dir, $plugin_backup ) ) { return new WP_Error( 'dbsr_plugin_backup', 'The current plugin could not be moved to backup. Nothing was changed.' ); }
			if ( ! @rename( $theme_dir, $theme_backup ) ) {
				@rename( $plugin_backup, $plugin_dir );
				return new WP_Error( 'dbsr_theme_backup', 'The current theme could not be moved to backup; the plugin was restored.' );
			}
			if ( ! @rename( $plugin_candidate, $plugin_dir ) ) {
				self::restore_pair( $plugin_dir, $plugin_backup, $theme_dir, $theme_backup, $extract_root );
				return new WP_Error( 'dbsr_plugin_install', 'The new plugin could not be installed; the original plugin and theme were restored.' );
			}
			if ( ! @rename( $theme_candidate, $theme_dir ) ) {
				self::restore_pair( $plugin_dir, $plugin_backup, $theme_dir, $theme_backup, $extract_root );
				return new WP_Error( 'dbsr_theme_install', 'The new theme could not be installed; the original plugin and theme were restored.' );
			}
			wp_clean_plugins_cache( true );
			wp_clean_themes_cache( true );
			if ( ! self::state()['target_ready'] ) {
				$restored = self::restore_pair( $plugin_dir, $plugin_backup, $theme_dir, $theme_backup, $extract_root );
				return new WP_Error( 'dbsr_verify', $restored ? 'Post-install verification failed; both original folders were restored.' : 'Post-install verification failed and host assistance is required. Recoverable backups were preserved.' );
			}
			delete_site_transient( 'update_plugins' );
			delete_site_transient( 'update_themes' );
			return array( 'plugin_backup' => wp_normalize_path( $plugin_backup ), 'theme_backup' => wp_normalize_path( $theme_backup ) );
		} finally {
			if ( is_file( $plugin_zip ) ) { @unlink( $plugin_zip ); }
			if ( is_file( $theme_zip ) ) { @unlink( $theme_zip ); }
			if ( $extract_root ) { self::cleanup( $extract_root ); }
		}
	}

	private static function download_verified( $url, $bytes, $sha256, $label ) {
		$file = download_url( $url, 300 );
		if ( is_wp_error( $file ) || ! is_string( $file ) || ! is_file( $file ) || is_link( $file ) ) {
			return new WP_Error( 'dbsr_download_' . $label, ucfirst( $label ) . ' package download failed. Nothing was changed.' );
		}
		clearstatcache( true, $file );
		$size = filesize( $file );
		$hash = hash_file( 'sha256', $file );
		if ( (int) $size !== (int) $bytes || ! is_string( $hash ) || ! hash_equals( $sha256, strtolower( $hash ) ) ) {
			@unlink( $file );
			return new WP_Error( 'dbsr_verify_' . $label, ucfirst( $label ) . ' package failed size or SHA-256 verification. Nothing was changed.' );
		}
		return $file;
	}

	private static function validate_candidates( $plugin, $theme ) {
		if ( ! self::normal_dir( $plugin ) || ! self::normal_dir( $theme ) || ! self::safe_tree( $plugin ) || ! self::safe_tree( $theme ) ) {
			return new WP_Error( 'dbsr_candidate', 'A package contained an unsafe or malformed directory. Nothing was changed.' );
		}
		$data = get_plugin_data( $plugin . '/doughboss.php', false, false );
		if ( 'DoughBoss' !== (string) $data['Name'] || self::TARGET_PLUGIN !== (string) $data['Version'] || 'doughboss' !== (string) $data['TextDomain'] ) {
			return new WP_Error( 'dbsr_plugin_identity', 'The plugin package identity did not match DoughBoss 2.34.1. Nothing was changed.' );
		}
		$style = file_get_contents( $theme . '/style.css' );
		if ( false === $style || ! preg_match( '/^Theme Name:\s*DoughBoss Final\s*$/mi', $style ) || ! preg_match( '/^Version:\s*1\.3\.0\s*$/mi', $style ) ) {
			return new WP_Error( 'dbsr_theme_identity', 'The theme package identity did not match DoughBoss Final 1.3.0. Nothing was changed.' );
		}
		foreach ( self::plugin_required() as $file ) { if ( ! is_file( $plugin . '/' . $file ) || is_link( $plugin . '/' . $file ) ) { return new WP_Error( 'dbsr_plugin_required', 'The plugin package is missing a required production asset. Nothing was changed.' ); } }
		foreach ( self::theme_required() as $file ) { if ( ! is_file( $theme . '/' . $file ) || is_link( $theme . '/' . $file ) ) { return new WP_Error( 'dbsr_theme_required', 'The theme package is missing a required template. Nothing was changed.' ); } }
		return true;
	}

	private static function plugin_required() {
		return array(
			'public/js/doughboss.js', 'public/css/doughboss.css', 'includes/class-doughboss-rest-controller.php',
			'public/images/doughboss-feast-real-v1.jpg', 'public/images/menu/real-v1/sujuk-deluxe.jpg',
			'public/images/menu/real-v1/zaatar-cheese.jpg', 'public/images/menu/real-v1/haloumi-pie.jpg',
			'public/images/menu/real-v1/meat-cheese.jpg', 'public/css/doughboss-portals.css', 'public/js/doughboss-portals.js',
		);
	}

	private static function theme_required() {
		return array( 'style.css', 'functions.php', 'front-page.php', 'page-order.php', 'page-about-us.php', 'page-catering.php', 'page-menu.php', 'header.php', 'footer.php', 'assets/theme.js' );
	}

	private static function normal_dir( $path ) { return is_dir( $path ) && ! is_link( $path ); }

	private static function safe_tree( $root ) {
		try {
			$prefix = trailingslashit( wp_normalize_path( $root ) );
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $iterator as $entry ) { $path = wp_normalize_path( $entry->getPathname() ); if ( 0 !== strpos( $path, $prefix ) || $entry->isLink() ) { return false; } }
			return true;
		} catch ( UnexpectedValueException $exception ) { return false; }
	}

	private static function restore_pair( $plugin_dir, $plugin_backup, $theme_dir, $theme_backup, $extract_root ) {
		$failed_plugin = $extract_root . '/failed-plugin-' . strtolower( wp_generate_password( 6, false, false ) );
		$failed_theme = $extract_root . '/failed-theme-' . strtolower( wp_generate_password( 6, false, false ) );
		if ( is_dir( $plugin_dir ) && ! @rename( $plugin_dir, $failed_plugin ) ) { return false; }
		if ( is_dir( $theme_dir ) && ! @rename( $theme_dir, $failed_theme ) ) { return false; }
		if ( ! is_dir( $plugin_backup ) || ! @rename( $plugin_backup, $plugin_dir ) ) { return false; }
		if ( ! is_dir( $theme_backup ) || ! @rename( $theme_backup, $theme_dir ) ) { return false; }
		wp_clean_plugins_cache( true );
		wp_clean_themes_cache( true );
		$state = self::state();
		return self::CURRENT_PLUGIN === $state['plugin_version'] && $state['plugin_active'] && self::THEME_SLUG === $state['theme_slug'] && self::CURRENT_THEME === $state['theme_version'];
	}

	private static function cleanup( $root ) {
		$normalized = wp_normalize_path( $root );
		if ( trailingslashit( wp_normalize_path( dirname( $root ) ) ) !== trailingslashit( wp_normalize_path( WP_CONTENT_DIR . '/upgrade' ) ) || ! preg_match( '/^doughboss-site-repair-2341-[a-z0-9]{12}$/', basename( $normalized ) ) || ! self::normal_dir( $root ) ) { return; }
		try {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $entry ) { if ( $entry->isLink() || $entry->isFile() ) { @unlink( $entry->getPathname() ); } elseif ( $entry->isDir() ) { @rmdir( $entry->getPathname() ); } }
		} catch ( UnexpectedValueException $exception ) {}
		@rmdir( $root );
	}
}

DoughBoss_Site_Repair_2341::init();
