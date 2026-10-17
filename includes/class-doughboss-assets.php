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
	 * Shortcodes that should trigger asset loading.
	 *
	 * @var string[]
	 */
	private $shortcodes = array(
		'doughboss_menu',
		'doughboss_builder',
		'doughboss_cart',
		'doughboss_order_tracking',
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Whether the current request should load DoughBoss assets.
	 *
	 * @return bool
	 */
	private function should_load() {
		if ( is_singular() ) {
			$post = get_post();
			if ( $post instanceof WP_Post ) {
				foreach ( $this->shortcodes as $shortcode ) {
					if ( has_shortcode( $post->post_content, $shortcode ) ) {
						return true;
					}
				}
			}
		}

		/**
		 * Allow themes/templates that render shortcodes outside post content
		 * (e.g. block templates or widgets) to force-load the assets.
		 *
		 * @param bool $load Whether to load.
		 */
		return (bool) apply_filters( 'doughboss_load_assets', false );
	}

	/**
	 * Enqueue styles and scripts when needed.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->should_load() ) {
			return;
		}

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

		wp_localize_script(
			'doughboss',
			'DoughBossData',
			array(
				'restUrl'  => esc_url_raw( rest_url( DOUGHBOSS_REST_NAMESPACE ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'currency' => DoughBoss_Settings::get( 'currency_symbol', '$' ),
				'i18n'     => array(
					'addToCart'    => __( 'Add to cart', 'doughboss' ),
					'added'        => __( 'Added!', 'doughboss' ),
					'emptyCart'    => __( 'Your cart is empty.', 'doughboss' ),
					'remove'       => __( 'Remove', 'doughboss' ),
					'subtotal'     => __( 'Subtotal', 'doughboss' ),
					'tax'          => __( 'Tax', 'doughboss' ),
					'delivery'     => __( 'Delivery', 'doughboss' ),
					'total'        => __( 'Total', 'doughboss' ),
					'placeOrder'   => __( 'Place order', 'doughboss' ),
					'placing'      => __( 'Placing order…', 'doughboss' ),
					'genericError' => __( 'Something went wrong. Please try again.', 'doughboss' ),
				),
			)
		);
	}
}
