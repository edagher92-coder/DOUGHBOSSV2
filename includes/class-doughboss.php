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
		require_once $dir . 'class-doughboss-migrations.php';
		require_once $dir . 'class-doughboss-locations.php';
		require_once $dir . 'class-doughboss-post-types.php';
		require_once $dir . 'class-doughboss-cart.php';
		require_once $dir . 'class-doughboss-order.php';
		require_once $dir . 'class-doughboss-catering-package.php';
		require_once $dir . 'class-doughboss-catering.php';
		require_once $dir . 'class-doughboss-stripe.php';
		require_once $dir . 'class-doughboss-pospal.php';
		require_once $dir . 'class-doughboss-coupon-code.php';
		require_once $dir . 'class-doughboss-voucher.php';
		require_once $dir . 'class-doughboss-pospal-sync.php';
		require_once $dir . 'class-doughboss-pospal-orders.php';
		require_once $dir . 'class-doughboss-mercure.php';
		require_once $dir . 'class-doughboss-ntfy.php';
		require_once $dir . 'class-doughboss-sms.php';
		require_once $dir . 'class-doughboss-printer.php';
		require_once $dir . 'class-doughboss-cli.php';
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
		DoughBoss_Migrations::run();
	}

	/**
	 * Instantiate and initialise the runtime components.
	 *
	 * @return void
	 */
	private function init_components() {
		$this->cart = new DoughBoss_Cart();

		( new DoughBoss_Post_Types() )->init();
		( new DoughBoss_Catering_Package() )->init();
		( new DoughBoss_Shortcodes() )->init();
		( new DoughBoss_Assets() )->init();
		( new DoughBoss_REST_Controller( $this->cart ) )->init();

		// POSPal voucher mirror (grant on claim, revoke on redeem). Static hooks;
		// fully dormant until POSPal + a coupon-rule UID are configured.
		DoughBoss_POSPal_Sync::init();

		// POSPal order push (mirror placed online orders onto the till). Off by
		// default; dormant until "push orders" is on AND a product map exists.
		DoughBoss_POSPal_Orders::init();

		// Phase 2 real-time + notification connectors. Each self-gates on its own
		// *_ready() check and stays fully dormant until configured in Settings.
		DoughBoss_Mercure::init();
		DoughBoss_Ntfy::init();
		DoughBoss_SMS::init();
		DoughBoss_Printer::init();

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
