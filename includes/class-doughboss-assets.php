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
		'doughboss_shop_picker',
		'doughboss_catering',
		'doughboss_voucher_claim',
		'doughboss_ordering_status',
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
	 * Whether the current singular post contains a given shortcode.
	 *
	 * @param string $shortcode Shortcode tag.
	 * @return bool
	 */
	private function current_post_has( $shortcode ) {
		if ( is_singular() ) {
			$post = get_post();
			if ( $post instanceof WP_Post && has_shortcode( $post->post_content, $shortcode ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Enqueue styles and scripts when needed.
	 *
	 * @return void
	 */
	public function enqueue() {
		// The hero has deliberately separate, dependency-free assets. Load it
		// before considering the storefront app, so a hero-only landing page
		// stays free of checkout and payment code.
		if ( $this->current_post_has( 'doughboss_manoush_hero' ) || apply_filters( 'doughboss_load_manoush_hero_assets', false ) ) {
			wp_enqueue_style(
				'doughboss-manoush-hero',
				DOUGHBOSS_PLUGIN_URL . 'public/css/doughboss-manoush-hero.css',
				array(),
				DOUGHBOSS_VERSION
			);
			wp_enqueue_script(
				'doughboss-manoush-hero',
				DOUGHBOSS_PLUGIN_URL . 'public/js/doughboss-manoush-hero.js',
				array(),
				DOUGHBOSS_VERSION,
				true
			);
		}

		if ( ! $this->should_load() ) {
			return;
		}

		wp_enqueue_style(
			'doughboss',
			DOUGHBOSS_PLUGIN_URL . 'public/css/doughboss.css',
			array(),
			DOUGHBOSS_VERSION
		);

		/*
		 * Consent-gated measurement bridge. It contains no tracker IDs, customer
		 * data or vendor network calls by default. A consent manager must call
		 * DoughBossMarketing.setConsent() before configured Meta/TikTok globals
		 * can receive an event. AdPilot remains server-to-server and is exposed
		 * here only as a readiness flag, never as a browser endpoint or secret.
		 */
		$marketing_env     = getenv( 'DOUGHBOSS_MARKETING_ENABLED' );
		$marketing_enabled = defined( 'DOUGHBOSS_MARKETING_ENABLED' )
			? (bool) DOUGHBOSS_MARKETING_ENABLED
			: ( false !== $marketing_env && filter_var( $marketing_env, FILTER_VALIDATE_BOOLEAN ) );
		$meta_pixel_id     = defined( 'DOUGHBOSS_META_PIXEL_ID' ) ? (string) DOUGHBOSS_META_PIXEL_ID : (string) getenv( 'DOUGHBOSS_META_PIXEL_ID' );
		$tiktok_pixel_id   = defined( 'DOUGHBOSS_TIKTOK_PIXEL_ID' ) ? (string) DOUGHBOSS_TIKTOK_PIXEL_ID : (string) getenv( 'DOUGHBOSS_TIKTOK_PIXEL_ID' );
		$marketing_config  = apply_filters(
			'doughboss_marketing_config',
			array(
				'enabled'            => (bool) $marketing_enabled,
				'metaPixelId'        => sanitize_text_field( $meta_pixel_id ),
				'tiktokPixelId'      => sanitize_text_field( $tiktok_pixel_id ),
				'consentVersion'     => '2026-07',
				'adpilotServerReady' => false,
			)
		);
		wp_enqueue_script(
			'doughboss-marketing',
			DOUGHBOSS_PLUGIN_URL . 'public/js/doughboss-marketing.js',
			array(),
			DOUGHBOSS_VERSION,
			true
		);
		wp_localize_script( 'doughboss-marketing', 'DoughBossMarketingConfig', $marketing_config );

		// Load the ACTIVE gateway's official card-capture library (from the
		// gateway's own host, as both require) only when card payments are
		// switched on and configured. Gating goes through the gateway-agnostic
		// DoughBoss_Payment facade — the same gate the REST checkout enforces —
		// never DoughBoss_Stripe directly: checking only Stripe here while the
		// `payment_gateway` setting selects Tyro would leave checkout demanding
		// a payment the storefront renders no card UI for.
		$deps        = array( 'doughboss-marketing' );
		// A configured gateway must not initialize browser payment fields while
		// the store is intentionally in browse-only / Coming Soon mode.
		$payments_on = DoughBoss_Settings::ordering_open() && DoughBoss_Payment::ready();
		$gateway     = DoughBoss_Settings::payment_gateway();
		if ( $payments_on ) {
			if ( 'tyro' === $gateway ) {
				// Tyro requires its PCI-scoped browser library to be loaded directly
				// from Tyro. Never bundle, proxy or self-host this file.
				wp_enqueue_script( 'tyro-js', 'https://pay.connect.tyro.com/v1/tyro.js', array(), null, true );
				$deps[] = 'tyro-js';
			} else {
				wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true );
				$deps[] = 'stripe-js';
			}
		}

		wp_enqueue_script(
			'doughboss',
			DOUGHBOSS_PLUGIN_URL . 'public/js/doughboss.js',
			$deps,
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
				'payments' => array(
					'enabled' => $payments_on,
					// Public-safe browser identifier for the ACTIVE gateway:
					// Stripe's publishable key, or Tyro Connect's harmless bootstrap marker.
					'pk'      => $payments_on ? DoughBoss_Payment::publishable_key() : '',
					// Which gateway the storefront JS should drive (Stripe.js
					// Elements vs Tyro Connect's hosted pay form).
					'gateway' => $gateway,
					'liveMode'=> 'tyro' === $gateway && DoughBoss_Settings::tyro_live_mode(),
				),
				'i18n'     => array(
					'addToCart'    => __( 'Add to cart', 'doughboss' ),
					'added'        => __( 'Added!', 'doughboss' ),
					'addedToCart'  => __( 'added to cart', 'doughboss' ),
					'menuCategories' => __( 'Menu categories', 'doughboss' ),
					'emptyCart'    => __( 'Your cart is empty.', 'doughboss' ),
					'remove'       => __( 'Remove', 'doughboss' ),
					'subtotal'     => __( 'Subtotal', 'doughboss' ),
					'tax'          => __( 'Tax', 'doughboss' ),
					'delivery'     => __( 'Delivery', 'doughboss' ),
					'total'        => __( 'Total', 'doughboss' ),
					'placeOrder'   => __( 'Place order', 'doughboss' ),
					'orderingComingSoon' => __( 'Online ordering coming soon', 'doughboss' ),
					'placing'      => __( 'Placing order…', 'doughboss' ),
					'soldOut'      => __( 'Sold out', 'doughboss' ),
					'chooseShop'   => __( 'Choose your shop', 'doughboss' ),
					'genericError' => __( 'Something went wrong. Please try again.', 'doughboss' ),
					'pay'          => __( 'Pay', 'doughboss' ),
					'cardDetails'  => __( 'Card details', 'doughboss' ),
					'payProcessing'=> __( 'Processing payment…', 'doughboss' ),
					'cardError'    => __( 'Please check your card details and try again.', 'doughboss' ),
					'cardNumber'   => __( 'Card number', 'doughboss' ),
					'cardExpiryMonth' => __( 'Expiry month (MM)', 'doughboss' ),
					'cardExpiryYear'  => __( 'Expiry year (YY)', 'doughboss' ),
					'cardCsc'         => __( 'Security code (CVC)', 'doughboss' ),
					'cardNumberError' => __( 'Please check the card number.', 'doughboss' ),
					'cardExpiryError' => __( 'Please check the card expiry date.', 'doughboss' ),
					'cardCscError'    => __( 'Please check the card security code.', 'doughboss' ),
					'cardInitError'   => __( 'The secure card form could not be loaded. Please refresh the page and try again.', 'doughboss' ),
					'vClaiming'    => __( 'Getting your code…', 'doughboss' ),
					'vYourCode'    => __( 'Your code', 'doughboss' ),
					'vUseInfo'     => __( 'Show this code at the till, or paste it at checkout. One use only.', 'doughboss' ),
					'vNeedPhone'   => __( 'Please enter your mobile number.', 'doughboss' ),
					'discount'     => __( 'Discount', 'doughboss' ),
					'apply'        => __( 'Apply', 'doughboss' ),
					'voucherApplied'     => __( 'Voucher applied', 'doughboss' ),
					'voucherPlaceholder' => __( 'Voucher code', 'doughboss' ),
				),
			)
		);

		// Catering page: ship its own self-contained app + styles, loaded only
		// when the catering shortcode is on the page (it reuses DoughBossData).
		if ( $this->current_post_has( 'doughboss_catering' ) || apply_filters( 'doughboss_load_assets', false ) ) {
			wp_enqueue_style(
				'doughboss-catering',
				DOUGHBOSS_PLUGIN_URL . 'public/css/doughboss-catering.css',
				array( 'doughboss' ),
				DOUGHBOSS_VERSION
			);
			wp_enqueue_script(
				'doughboss-catering',
				DOUGHBOSS_PLUGIN_URL . 'public/js/doughboss-catering.js',
				array( 'doughboss' ),
				DOUGHBOSS_VERSION,
				true
			);
		}

		// Voucher claim widget: its own small app + styles, loaded only when the
		// claim shortcode is on the page (reuses DoughBossData from the main app).
		if ( $this->current_post_has( 'doughboss_voucher_claim' ) || apply_filters( 'doughboss_load_assets', false ) ) {
			wp_enqueue_style(
				'doughboss-voucher',
				DOUGHBOSS_PLUGIN_URL . 'public/css/doughboss-voucher.css',
				array( 'doughboss' ),
				DOUGHBOSS_VERSION
			);
			// Load the QR generator (from jsDelivr's CDN, as a runtime dependency,
			// exactly like Stripe.js above): full https URL, no local file, version
			// null, in footer. Exposes the global `qrcode`. Our voucher script then
			// depends on it so it loads first; the script degrades gracefully if the
			// CDN is unreachable and the global is missing.
			wp_enqueue_script(
				'doughboss-qrcode',
				'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js',
				array(),
				null,
				true
			);
			wp_enqueue_script(
				'doughboss-voucher',
				DOUGHBOSS_PLUGIN_URL . 'public/js/doughboss-voucher.js',
				array( 'doughboss', 'doughboss-qrcode' ),
				DOUGHBOSS_VERSION,
				true
			);
		}
	}
}
