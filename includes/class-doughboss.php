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
		require_once $dir . 'class-doughboss-capacity.php';
		require_once $dir . 'class-doughboss-post-types.php';
		require_once $dir . 'class-doughboss-menu-seeder.php';
		require_once $dir . 'class-doughboss-cart.php';
		require_once $dir . 'class-doughboss-order.php';
		require_once $dir . 'class-doughboss-reports.php';
		require_once $dir . 'class-doughboss-catering-package.php';
		require_once $dir . 'class-doughboss-catering.php';
		require_once $dir . 'class-doughboss-stripe.php';
		require_once $dir . 'class-doughboss-tyro.php';
		require_once $dir . 'class-doughboss-payment.php';
		require_once $dir . 'class-doughboss-pospal.php';
		require_once $dir . 'class-doughboss-coupon-code.php';
		require_once $dir . 'class-doughboss-voucher.php';
		require_once $dir . 'class-doughboss-pospal-outbox.php';
		require_once $dir . 'class-doughboss-pospal-sync.php';
		require_once $dir . 'class-doughboss-pospal-orders.php';
		require_once $dir . 'class-doughboss-mercure.php';
		require_once $dir . 'class-doughboss-ntfy.php';
		require_once $dir . 'class-doughboss-sms.php';
		require_once $dir . 'class-doughboss-emails.php';
		require_once $dir . 'class-doughboss-printer.php';
		require_once $dir . 'class-doughboss-privacy.php';
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
		( new DoughBoss_Privacy() )->init();

		// POSPal voucher mirror (grant on claim, revoke on redeem). Static hooks;
		// fully dormant until POSPal + a coupon-rule UID are configured.
		DoughBoss_POSPal_Sync::init();

		// Durable POSPal push outbox — the cron worker that owns retries. Must
		// register before Orders::init() so its cron hook is bound before any
		// enqueue schedules a sweep.
		DoughBoss_POSPal_Outbox::init();

		// POSPal order push (mirror placed online orders onto the till). Off by
		// default; dormant until "push orders" is on AND a product map exists.
		DoughBoss_POSPal_Orders::init();

		// Optional long login session: keep logged-in users signed in for the
		// configured number of days (0 = WordPress default). Off by default — set
		// "Staff session" in Settings to e.g. 3650 so shop tablets never log out.
		add_filter(
			'auth_cookie_expiration',
			static function ( $length, $user_id, $remember ) {
				unset( $user_id, $remember );
				$days = (int) DoughBoss_Settings::get( 'staff_session_days', 0 );
				return $days > 0 ? $days * DAY_IN_SECONDS : $length;
			},
			20,
			3
		);

		// Phase 2 real-time + notification connectors. Each self-gates on its own
		// *_ready() check and stays fully dormant until configured in Settings.
		DoughBoss_Mercure::init();
		DoughBoss_Ntfy::init();
		DoughBoss_SMS::init();
		DoughBoss_Emails::init();
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
