<?php
/**
 * Front-end asset registration and localization.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the storefront CSS/JS and passes runtime config to JavaScript.
 */
class DoughBoss_Assets {

	/**
	 * Whether the storefront assets have been enqueued this request.
	 *
	 * @var bool
	 */
	private static $enqueued = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Cheap early check: does this singular post's content contain any of our
	 * shortcodes? has_shortcode() rebuilds a regex from every registered
	 * shortcode on the site, so guard it with a strpos first. This is only a
	 * fast path — the shortcodes themselves enqueue on render, which covers
	 * page builders, block templates and synced patterns.
	 *
	 * @return bool
	 */
	private function should_load_early() {
		if ( is_singular() ) {
			$post = get_post();
			if ( $post instanceof WP_Post && false !== strpos( $post->post_content, '[doughboss_' ) ) {
				return true;
			}
		}

		/**
		 * Force-load the storefront assets (e.g. for a theme that renders the
		 * shortcodes from a template part).
		 *
		 * @param bool $load Whether to load.
		 */
		return (bool) apply_filters( 'doughboss_load_assets', false );
	}

	/**
	 * Enqueue styles and scripts when the early check says so.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( $this->should_load_early() ) {
			self::enqueue_now();
		}
	}

	/**
	 * Enqueue the storefront assets (idempotent). Safe to call from a
	 * shortcode callback: the script is a footer script, and WordPress prints
	 * late-enqueued styles in the footer too.
	 *
	 * @return void
	 */
	public static function enqueue_now() {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

		wp_enqueue_style(
			'doughboss',
			DOUGHBOSS_PLUGIN_URL . 'public/css/doughboss.css',
			array(),
			DOUGHBOSS_VERSION
		);

		wp_enqueue_script(
			'doughboss',
			DOUGHBOSS_PLUGIN_URL . 'public/js/doughboss.js',
			array(),
			DOUGHBOSS_VERSION,
			true
		);

		wp_localize_script( 'doughboss', 'DoughBossData', self::script_data() );
	}

	/**
	 * The `DoughBossData` payload.
	 *
	 * The config is inlined so the builder and cart never block on a request
	 * for it. The nonce is still embedded for the common uncached case; the
	 * storefront refreshes it from GET /nonce when it has gone stale behind a
	 * full-page cache instead of failing every cart action with a 403.
	 *
	 * @return array
	 */
	private static function script_data() {
		return array(
			'restUrl'      => esc_url_raw( rest_url( DOUGHBOSS_REST_NAMESPACE ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'currency'     => DoughBoss_Settings::get( 'currency_symbol', '$' ),
			'config'       => DoughBoss_Settings::public_config(),
			'storePhone'   => (string) DoughBoss_Settings::get( 'store_phone', '' ),
			'menuUrl'      => (string) DoughBoss_Settings::get( 'menu_page_url', '' ),
			'trackingUrl'  => (string) DoughBoss_Settings::get( 'tracking_page_url', '' ),
			'headingLevel' => 2,
			'i18n'         => array(
				'addToCart'       => __( 'Add to cart', 'doughboss' ),
				'added'           => __( 'Added!', 'doughboss' ),
				'adding'          => __( 'Adding…', 'doughboss' ),
				'soldOut'         => __( 'Sold out', 'doughboss' ),
				'emptyCart'       => __( 'Your cart is empty.', 'doughboss' ),
				'browseMenu'      => __( 'Browse the menu', 'doughboss' ),
				'remove'          => __( 'Remove', 'doughboss' ),
				'removed'         => __( 'Removed', 'doughboss' ),
				'undo'            => __( 'Undo', 'doughboss' ),
				'quantity'        => __( 'Quantity', 'doughboss' ),
				'quantityFor'     => __( 'Quantity for %s', 'doughboss' ),
				'removeItem'      => __( 'Remove %s from cart', 'doughboss' ),
				'decrease'        => __( 'Decrease quantity', 'doughboss' ),
				'increase'        => __( 'Increase quantity', 'doughboss' ),
				'subtotal'        => __( 'Subtotal', 'doughboss' ),
				'tax'             => __( 'Tax', 'doughboss' ),
				'includesTax'     => __( 'Includes %1$s %2$s', 'doughboss' ),
				'delivery'        => __( 'Delivery', 'doughboss' ),
				'total'           => __( 'Total', 'doughboss' ),
				'totalDue'        => __( 'Total due on %s', 'doughboss' ),
				'pickup'          => __( 'Pickup', 'doughboss' ),
				'deliveryOption'  => __( 'Delivery', 'doughboss' ),
				'fulfilment'      => __( 'How would you like your order?', 'doughboss' ),
				'minOrder'        => __( 'Minimum order %s', 'doughboss' ),
				'checkout'        => __( 'Checkout', 'doughboss' ),
				'name'            => __( 'Name', 'doughboss' ),
				'email'           => __( 'Email', 'doughboss' ),
				'phone'           => __( 'Phone', 'doughboss' ),
				'address'         => __( 'Delivery address', 'doughboss' ),
				'notes'           => __( 'Notes (optional)', 'doughboss' ),
				'notesHint'       => __( 'Allergies, gate codes, anything we should know.', 'doughboss' ),
				'placeOrder'      => __( 'Place order', 'doughboss' ),
				'placing'         => __( 'Placing order…', 'doughboss' ),
				'orderReceived'   => __( 'Thanks! Your order has been received.', 'doughboss' ),
				'orderNumber'     => __( 'Your order number is', 'doughboss' ),
				'emailedTo'       => __( 'We have emailed a confirmation to %s — check your junk folder if it does not arrive.', 'doughboss' ),
				'trackOrder'      => __( 'Track this order', 'doughboss' ),
				'recentOrder'     => __( 'Your recent order', 'doughboss' ),
				'callUs'          => __( 'Call us', 'doughboss' ),
				'closedTitle'     => __( 'Online ordering is closed right now', 'doughboss' ),
				'closedBody'      => __( 'Please check back later or call us to order.', 'doughboss' ),
				'buildTitle'      => __( 'Build your pizza', 'doughboss' ),
				'size'            => __( 'Size', 'doughboss' ),
				'toppings'        => __( 'Toppings', 'doughboss' ),
				'noSizes'         => __( 'No pizza sizes configured yet.', 'doughboss' ),
				'noMenu'          => __( 'No menu items yet.', 'doughboss' ),
				'yourPizza'       => __( '%1$s pizza with %2$s — %3$s', 'doughboss' ),
				'plainPizza'      => __( '%1$s pizza, no toppings — %2$s', 'doughboss' ),
				'loading'         => __( 'Loading…', 'doughboss' ),
				'loadFailed'      => __( 'We could not load this right now. Please refresh, or call us to order.', 'doughboss' ),
				'pricesChanged'   => __( 'Some prices changed — please review your order.', 'doughboss' ),
				'cartExpired'     => __( 'Your cart has expired. Please add your items again.', 'doughboss' ),
				'rateLimited'     => __( 'Too many attempts. Please wait a minute and try again.', 'doughboss' ),
				'genericError'    => __( 'Something went wrong. Please try again.', 'doughboss' ),
				'required'        => __( 'This field is required.', 'doughboss' ),
				'invalidEmail'    => __( 'Please enter a valid email address.', 'doughboss' ),
				'orderLabel'      => __( 'Order', 'doughboss' ),
				'status'          => __( 'Status', 'doughboss' ),
				'checkStatus'     => __( 'Check status', 'doughboss' ),
				'viewCart'        => __( 'View cart', 'doughboss' ),
				'items'           => __( 'items', 'doughboss' ),
				'item'            => __( 'item', 'doughboss' ),
			),
		);
	}
}
