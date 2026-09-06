<?php
/**
 * The core plugin class: loads dependencies and wires up components.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin orchestrator (singleton).
 */
final class DoughBoss {

	/**
	 * Singleton instance.
	 *
	 * @var DoughBoss|null
	 */
	private static $instance = null;

	/**
	 * Cart service, shared between components.
	 *
	 * @var DoughBoss_Cart
	 */
	private $cart;

	/**
	 * Retrieve (and lazily build) the singleton.
	 *
	 * @return DoughBoss
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * The shared cart service.
	 *
	 * @return DoughBoss_Cart
	 */
	public function cart() {
		return $this->cart;
	}

	/**
	 * Load files and register everything.
	 *
	 * @return void
	 */
	private function boot() {
		$this->load_dependencies();
		$this->init_components();

		DoughBoss_Settings::init();

		// Schema upgrades run at `init` (not `plugins_loaded`): roles and
		// rewrite rules exist by then, and the CPT is registered normally.
		add_action( 'init', array( $this, 'maybe_upgrade_db' ), 1 );
		add_action( 'init', array( 'DoughBoss_Activator', 'maybe_flush_rewrites' ), 99 );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Require class files.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		$dir = DOUGHBOSS_PLUGIN_DIR . 'includes/';

		require_once $dir . 'class-doughboss-settings.php';
		require_once $dir . 'class-doughboss-post-types.php';
		require_once $dir . 'class-doughboss-cart.php';
		require_once $dir . 'class-doughboss-order.php';
		require_once $dir . 'class-doughboss-rest-controller.php';
		require_once $dir . 'class-doughboss-shortcodes.php';
		require_once $dir . 'class-doughboss-assets.php';
		require_once $dir . 'class-doughboss-activator.php';

		if ( is_admin() ) {
			require_once DOUGHBOSS_PLUGIN_DIR . 'admin/class-doughboss-admin.php';
		}
	}

	/**
	 * Run the schema routine if the DB version is behind.
	 *
	 * Covers sites updated via file copy (where the activation hook never
	 * fires). Guarded by a short mutex so a deploy during a busy period does
	 * not have twenty concurrent requests all running dbDelta/ALTER TABLE.
	 *
	 * @return void
	 */
	public function maybe_upgrade_db() {
		if ( get_option( 'doughboss_db_version' ) === DOUGHBOSS_DB_VERSION ) {
			return;
		}
		if ( ! wp_cache_add( 'doughboss_upgrading', 1, 'doughboss', 60 ) ) {
			return; // Another request is already upgrading.
		}
		DoughBoss_Activator::install();
		wp_cache_delete( 'doughboss_upgrading', 'doughboss' );
	}

	/**
	 * Instantiate and initialise the runtime components.
	 *
	 * @return void
	 */
	private function init_components() {
		$this->cart = new DoughBoss_Cart();

		( new DoughBoss_Post_Types() )->init();
		( new DoughBoss_Shortcodes( $this->cart ) )->init();
		( new DoughBoss_Assets() )->init();
		( new DoughBoss_REST_Controller( $this->cart ) )->init();

		if ( is_admin() ) {
			( new DoughBoss_Admin() )->init();
		}
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'doughboss', false, dirname( DOUGHBOSS_PLUGIN_BASENAME ) . '/languages' );
	}
}
