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
	public $cart;

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
	 * Load files and register everything.
	 *
	 * @return void
	 */
	private function boot() {
		$this->load_dependencies();
		$this->maybe_upgrade_db();
		$this->init_components();

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

		if ( is_admin() ) {
			require_once DOUGHBOSS_PLUGIN_DIR . 'admin/class-doughboss-admin.php';
		}
	}

	/**
	 * Run the activator's schema routine if the DB version is behind.
	 *
	 * Covers sites updated via file copy (where the activation hook never
	 * fires) so tables always exist for the current schema version.
	 *
	 * @return void
	 */
	private function maybe_upgrade_db() {
		if ( get_option( 'doughboss_db_version' ) === DOUGHBOSS_DB_VERSION ) {
			return;
		}
		require_once DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss-activator.php';
		DoughBoss_Activator::activate();
	}

	/**
	 * Instantiate and initialise the runtime components.
	 *
	 * @return void
	 */
	private function init_components() {
		$this->cart = new DoughBoss_Cart();

		( new DoughBoss_Post_Types() )->init();
		( new DoughBoss_Shortcodes() )->init();
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
