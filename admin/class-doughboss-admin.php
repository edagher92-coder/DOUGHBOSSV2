<?php
/**
 * Admin screens: orders management and settings.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires up the wp-admin experience for DoughBoss.
 */
class DoughBoss_Admin {

	const SETTINGS_GROUP = 'doughboss_settings_group';
	const CAP            = 'manage_doughboss';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_doughboss_save_location', array( $this, 'handle_save_location' ) );
		add_action( 'admin_post_doughboss_delete_location', array( $this, 'handle_delete_location' ) );
		add_action( 'admin_post_doughboss_issue_voucher', array( $this, 'handle_issue_voucher' ) );
		add_action( 'admin_post_doughboss_claim_voucher', array( $this, 'handle_claim_voucher' ) );
		add_action( 'admin_post_doughboss_void_voucher', array( $this, 'handle_void_voucher' ) );
		add_action( 'admin_post_doughboss_seed_menu', array( $this, 'handle_seed_menu' ) );
		add_action( 'admin_post_doughboss_save_templates', array( $this, 'handle_save_templates' ) );
		add_action( 'admin_post_doughboss_clear_payment_issues', array( $this, 'handle_clear_payment_issues' ) );
		add_action( 'admin_post_doughboss_refund_order', array( $this, 'handle_refund_order' ) );
		add_action( 'admin_post_doughboss_export_report', array( $this, 'handle_export_report' ) );
		add_action( 'admin_post_doughboss_clear_pospal_alerts', array( $this, 'handle_clear_pospal_alerts' ) );
		add_action( 'admin_notices', array( $this, 'render_pospal_unmapped_notice' ) );
		add_action( 'admin_post_doughboss_pospal_outbox_resend', array( $this, 'handle_pospal_outbox_resend' ) );
		add_action( 'admin_notices', array( $this, 'render_pospal_outbox_notice' ) );
		add_action( 'admin_post_doughboss_generate_board_key', array( $this, 'handle_generate_board_key' ) );
		add_action( 'admin_post_doughboss_clear_board_key', array( $this, 'handle_clear_board_key' ) );
		add_action( 'admin_notices', array( $this, 'render_board_key_reveal_notice' ) );
		add_action( 'admin_post_doughboss_clear_delivery_notice', array( $this, 'handle_clear_delivery_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_delivery_autodisabled_notice' ) );
	}

	/**
	 * The capability required for management screens.
	 *
	 * @return string
	 */
	private function cap() {
		return current_user_can( self::CAP ) ? self::CAP : 'manage_options';
	}

	/**
	 * Capability for the in-store scan dashboard. Prefers the dedicated redeem
	 * cap (so a low-privilege kitchen tablet can reach the scanner) and falls
	 * back to manage_options so owners always see it.
	 *
	 * @return string
	 */
	private function scan_cap() {
		return current_user_can( 'redeem_doughboss_vouchers' ) ? 'redeem_doughboss_vouchers' : 'manage_options';
	}

	/**
	 * Register the top-level menu and sub-pages. The Menu Items CPT and its
	 * category taxonomy attach automatically via `show_in_menu`.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'DoughBoss', 'doughboss' ),
			__( 'DoughBoss', 'doughboss' ),
			$this->cap(),
			'doughboss',
			array( $this, 'render_orders_page' ),
			'dashicons-food',
			26
		);

		add_submenu_page(
			'doughboss',
			__( 'Orders', 'doughboss' ),
			__( 'Orders', 'doughboss' ),
			$this->cap(),
			'doughboss',
			array( $this, 'render_orders_page' )
		);

		add_submenu_page(
			'doughboss',
			__( 'Catering Enquiries', 'doughboss' ),
			__( 'Catering', 'doughboss' ),
			$this->cap(),
			'doughboss-catering',
			array( $this, 'render_catering_page' )
		);

		add_submenu_page(
			'doughboss',
			__( 'Shops / Locations', 'doughboss' ),
			__( 'Shops', 'doughboss' ),
			$this->cap(),
			'doughboss-locations',
			array( $this, 'render_locations_page' )
		);

		add_submenu_page(
			'doughboss',
			__( 'Vouchers', 'doughboss' ),
			__( 'Vouchers', 'doughboss' ),
			$this->cap(),
			'doughboss-vouchers',
			array( $this, 'render_vouchers_page' )
		);

		add_submenu_page(
			'doughboss',
			__( 'DoughBoss Settings', 'doughboss' ),
			__( 'Settings', 'doughboss' ),
			$this->cap(),
			'doughboss-settings',
			array( $this, 'render_settings_page' )
		);

		// Owner-only: the customer-facing copy sent by email/SMS. A dedicated
		// page rather than a Settings tab so it's never touched by a Settings
		// save from a different tab, and saves via its own admin-post handler
		// (a true partial DoughBoss_Settings::update(), not the full Settings-API
		// rebuild) so it can never wipe an unrelated setting.
		add_submenu_page(
			'doughboss',
			__( 'Message Templates', 'doughboss' ),
			__( 'Message Templates', 'doughboss' ),
			$this->cap(),
			'doughboss-templates',
			array( $this, 'render_templates_page' )
		);

		add_submenu_page(
			'doughboss',
			__( 'Reports', 'doughboss' ),
			__( 'Reports', 'doughboss' ),
			$this->cap(),
			'doughboss-reports',
			array( $this, 'render_reports_page' )
		);

		// Standalone, tablet-friendly live order board. Registered with the
		// kitchen capability so a low-privilege "DoughBoss Kitchen" user can
		// reach it without a full admin login on a shop device.
		add_menu_page(
			__( 'Order Board', 'doughboss' ),
			__( 'Order Board', 'doughboss' ),
			'manage_doughboss_kds',
			'doughboss-board',
			array( $this, 'render_board_page' ),
			'dashicons-screenoptions',
			27
		);

		// Standalone, tablet-friendly voucher scanner for staff/till. Uses the
		// dedicated redeem capability so a "DoughBoss Kitchen" device can scan &
		// redeem without owner privileges (and without being able to issue value).
		add_menu_page(
			__( 'Voucher Scan', 'doughboss' ),
			__( 'Voucher Scan', 'doughboss' ),
			$this->scan_cap(),
			'doughboss-scan',
			array( $this, 'render_scan_page' ),
			'dashicons-tickets-alt',
			28
		);
	}

	/**
	 * Register the settings option with a sanitizing callback.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			DoughBoss_Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize the entire settings payload coming from the settings form.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		// Start from the currently stored settings so any key this form doesn't
		// explicitly know about (e.g. app_origin, voucher_campaigns, pospal_label)
		// survives a save instead of being silently dropped — every key this
		// function lists below is still explicitly re-validated from $input,
		// this only changes what happens to keys it does NOT list.
		$existing = DoughBoss_Settings::all();
		$clean    = $existing;

		$clean['currency_symbol'] = isset( $input['currency_symbol'] ) ? sanitize_text_field( $input['currency_symbol'] ) : '$';
		$clean['currency_code']   = isset( $input['currency_code'] ) ? sanitize_text_field( $input['currency_code'] ) : ( isset( $existing['currency_code'] ) ? $existing['currency_code'] : 'AUD' );
		$clean['tax_rate']        = isset( $input['tax_rate'] ) ? max( 0, (float) $input['tax_rate'] ) : 0;
		$clean['gst_inclusive']   = empty( $input['gst_inclusive'] ) ? 0 : 1;
		$clean['delivery_fee']    = isset( $input['delivery_fee'] ) ? max( 0, (float) $input['delivery_fee'] ) : 0;
		$clean['enable_pickup']   = empty( $input['enable_pickup'] ) ? 0 : 1;
		$clean['enable_delivery'] = empty( $input['enable_delivery'] ) ? 0 : 1;
		$clean['ordering_open']   = empty( $input['ordering_open'] ) ? 0 : 1;
		$active_locations         = DoughBoss_Locations::all( true );
		$clean['single_location_mode'] = ! empty( $input['single_location_mode'] ) && 1 === count( $active_locations ) ? 1 : 0;
		if ( $clean['single_location_mode'] ) {
			// Single-location launch mode is deliberately pickup-only.
			$clean['enable_pickup']   = 1;
			$clean['enable_delivery'] = 0;
		}

		// Payments (Stripe). Keys are stored for the active mode; secret keys are
		// only ever used in server-side calls and are never sent to the browser.
		// The secret key and webhook secret fields render blank (see the form
		// below) and use keep_secret() so a routine save without re-entering them
		// preserves the stored value instead of wiping it.
		$clean['payments_enabled']  = empty( $input['payments_enabled'] ) ? 0 : 1;
		$clean['payment_gateway']   = ( isset( $input['payment_gateway'] ) && 'tyro' === $input['payment_gateway'] ) ? 'tyro' : 'stripe';
		$clean['stripe_mode']       = ( isset( $input['stripe_mode'] ) && 'live' === $input['stripe_mode'] ) ? 'live' : 'test';
		$clean['stripe_test_pk']    = isset( $input['stripe_test_pk'] ) ? sanitize_text_field( $input['stripe_test_pk'] ) : '';
		$clean['stripe_test_sk']    = $this->keep_secret( $input, $existing, 'stripe_test_sk' );
		$clean['stripe_live_pk']    = isset( $input['stripe_live_pk'] ) ? sanitize_text_field( $input['stripe_live_pk'] ) : '';
		$clean['stripe_live_sk']    = $this->keep_secret( $input, $existing, 'stripe_live_sk' );
		$clean['stripe_test_whsec'] = $this->keep_secret( $input, $existing, 'stripe_test_whsec' );
		$clean['stripe_live_whsec'] = $this->keep_secret( $input, $existing, 'stripe_live_whsec' );

		// Tyro eCommerce. Same write-only pattern as Stripe above: the merchant
		// id is public-safe (echoed back), the password/webhook secrets are not.
		$clean['tyro_mode']                = ( isset( $input['tyro_mode'] ) && 'live' === $input['tyro_mode'] ) ? 'live' : 'test';
		$clean['tyro_merchant_id']         = isset( $input['tyro_merchant_id'] ) ? sanitize_text_field( $input['tyro_merchant_id'] ) : '';
		$clean['tyro_host']                = isset( $input['tyro_host'] ) ? esc_url_raw( trim( (string) $input['tyro_host'] ) ) : '';
		$clean['tyro_api_version']         = isset( $input['tyro_api_version'] ) ? sanitize_text_field( $input['tyro_api_version'] ) : '';
		$clean['tyro_test_password']       = $this->keep_secret( $input, $existing, 'tyro_test_password' );
		$clean['tyro_live_password']       = $this->keep_secret( $input, $existing, 'tyro_live_password' );
		$clean['tyro_test_webhook_secret'] = $this->keep_secret( $input, $existing, 'tyro_test_webhook_secret' );
		$clean['tyro_live_webhook_secret'] = $this->keep_secret( $input, $existing, 'tyro_live_webhook_secret' );

		// POSPal POS (Open Platform) — Revesby pilot. The secret appKey is read
		// env-first (DOUGHBOSS_POSPAL_APPKEY); this field is only a fallback, and
		// (like the Stripe secret keys above) renders blank + uses keep_secret()
		// so it is never echoed back into the page and a routine save can't wipe it.
		$clean['pospal_enabled'] = empty( $input['pospal_enabled'] ) ? 0 : 1;
		$clean['pospal_host']    = isset( $input['pospal_host'] ) ? esc_url_raw( trim( (string) $input['pospal_host'] ) ) : '';
		$clean['pospal_app_id']  = isset( $input['pospal_app_id'] ) ? sanitize_text_field( $input['pospal_app_id'] ) : '';
		$clean['pospal_app_key'] = $this->keep_secret( $input, $existing, 'pospal_app_key' );
		$clean['pospal_coupon_uid_5']  = isset( $input['pospal_coupon_uid_5'] ) ? sanitize_text_field( $input['pospal_coupon_uid_5'] ) : '';
		// The $10 voucher tier has been retired — $5 is the only student voucher
		// going forward. Purge any leftover $10 coupon-rule UID from storage.
		unset( $clean['pospal_coupon_uid_10'] );
		// Additional POSPal stores (multi-store): store 2 + store 3.
		foreach ( array( 2, 3 ) as $sn ) {
			$clean[ 'pospal' . $sn . '_label' ]         = isset( $input[ 'pospal' . $sn . '_label' ] ) ? sanitize_text_field( $input[ 'pospal' . $sn . '_label' ] ) : '';
			$clean[ 'pospal' . $sn . '_host' ]          = isset( $input[ 'pospal' . $sn . '_host' ] ) ? esc_url_raw( trim( (string) $input[ 'pospal' . $sn . '_host' ] ) ) : '';
			$clean[ 'pospal' . $sn . '_app_id' ]        = isset( $input[ 'pospal' . $sn . '_app_id' ] ) ? sanitize_text_field( $input[ 'pospal' . $sn . '_app_id' ] ) : '';
			$clean[ 'pospal' . $sn . '_app_key' ]       = $this->keep_secret( $input, $existing, 'pospal' . $sn . '_app_key' );
			$clean[ 'pospal' . $sn . '_coupon_uid_5' ]  = isset( $input[ 'pospal' . $sn . '_coupon_uid_5' ] ) ? sanitize_text_field( $input[ 'pospal' . $sn . '_coupon_uid_5' ] ) : '';
			unset( $clean[ 'pospal' . $sn . '_coupon_uid_10' ] );
		}

		// Phase 2 — real-time & notifications. Off by default; fully dormant until
		// configured. Secret fields (publish JWT, ntfy token, ClickSend API key,
		// printer token) render blank in the form, so a blank submission PRESERVES
		// the previously stored value rather than wiping it (see keep_secret()).
		// ($existing was already fetched above, at the top of this function.)

		// Shop inbox for order + catering notifications. When the field is present,
		// take a valid email or blank (blank => orders_email() falls back to the WP
		// admin email). When absent, PRESERVE the stored value / default so a routine
		// Save never silently reverts notifications away from the orders inbox.
		if ( isset( $input['orders_email'] ) ) {
			$clean['orders_email'] = is_email( $input['orders_email'] ) ? sanitize_email( $input['orders_email'] ) : '';
		} else {
			$clean['orders_email'] = isset( $existing['orders_email'] ) ? $existing['orders_email'] : 'hello@doughboss.com.au';
		}
		$clean['staff_session_days'] = isset( $input['staff_session_days'] ) ? max( 0, absint( $input['staff_session_days'] ) ) : 0;

		// Order Board access key is intentionally NOT part of this form — it is
		// only ever set by handle_generate_board_key() (a random, URL-safe value)
		// or cleared by handle_clear_board_key(), so the settings POST handler
		// preserves whatever is already stored rather than accepting free text.
		// This keeps it out of reach of a general form submission and guarantees
		// the stored value always comes from the safe-alphabet generator below.
		$existing_settings              = DoughBoss_Settings::all();
		$clean['board_access_key']      = isset( $existing_settings['board_access_key'] ) ? $existing_settings['board_access_key'] : '';

		// POSPal order push (mirror online orders to the till). The product map is
		// managed via WP-CLI (`wp doughboss pospal-map`), not this form, so it is
		// preserved rather than rebuilt on save.
		$clean['pospal_push_orders']           = empty( $input['pospal_push_orders'] ) ? 0 : 1;
		$clean['pospal_order_pay_method']      = isset( $input['pospal_order_pay_method'] ) ? sanitize_text_field( $input['pospal_order_pay_method'] ) : 'Cash';
		$clean['pospal_order_pay_method_code'] = isset( $input['pospal_order_pay_method_code'] ) ? sanitize_text_field( $input['pospal_order_pay_method_code'] ) : '';
		$clean['pospal_order_pay_online']      = empty( $input['pospal_order_pay_online'] ) ? 0 : 1;
		$clean['pospal_product_map']           = ( isset( $existing['pospal_product_map'] ) && is_array( $existing['pospal_product_map'] ) ) ? $existing['pospal_product_map'] : array();

		// Mercure real-time push.
		$clean['mercure_enabled']       = empty( $input['mercure_enabled'] ) ? 0 : 1;
		$clean['mercure_hub_url']       = isset( $input['mercure_hub_url'] ) ? esc_url_raw( trim( (string) $input['mercure_hub_url'] ) ) : '';
		$clean['mercure_publish_jwt']   = $this->keep_secret( $input, $existing, 'mercure_publish_jwt' );
		$clean['mercure_subscribe_jwt'] = isset( $input['mercure_subscribe_jwt'] ) ? sanitize_text_field( $input['mercure_subscribe_jwt'] ) : '';
		$clean['mercure_topic_prefix']  = isset( $input['mercure_topic_prefix'] ) ? sanitize_text_field( $input['mercure_topic_prefix'] ) : 'doughboss';

		// ntfy push notifications.
		$clean['ntfy_enabled']  = empty( $input['ntfy_enabled'] ) ? 0 : 1;
		$clean['ntfy_server']   = isset( $input['ntfy_server'] ) ? esc_url_raw( trim( (string) $input['ntfy_server'] ) ) : 'https://ntfy.sh';
		$clean['ntfy_topic']    = isset( $input['ntfy_topic'] ) ? sanitize_text_field( $input['ntfy_topic'] ) : '';
		$clean['ntfy_token']    = $this->keep_secret( $input, $existing, 'ntfy_token' );
		$clean['ntfy_priority'] = ( isset( $input['ntfy_priority'] ) && in_array( $input['ntfy_priority'], array( 'high', 'default', 'low' ), true ) ) ? $input['ntfy_priority'] : 'high';

		// SMS (ClickSend).
		$clean['sms_enabled']          = empty( $input['sms_enabled'] ) ? 0 : 1;
		$clean['clicksend_username']   = isset( $input['clicksend_username'] ) ? sanitize_text_field( $input['clicksend_username'] ) : '';
		$clean['clicksend_api_key']    = $this->keep_secret( $input, $existing, 'clicksend_api_key' );
		$clean['sms_from']             = isset( $input['sms_from'] ) ? sanitize_text_field( $input['sms_from'] ) : '';
		$clean['sms_on_ready']         = empty( $input['sms_on_ready'] ) ? 0 : 1;
		$clean['sms_on_voucher_claim'] = empty( $input['sms_on_voucher_claim'] ) ? 0 : 1;

		// Receipt printer (CloudPRNT / ePOS).
		$clean['printer_enabled']  = empty( $input['printer_enabled'] ) ? 0 : 1;
		$clean['printer_protocol'] = ( isset( $input['printer_protocol'] ) && 'epos' === $input['printer_protocol'] ) ? 'epos' : 'cloudprnt';
		$clean['printer_token']    = $this->keep_secret( $input, $existing, 'printer_token' );
		$clean['printer_width']    = isset( $input['printer_width'] ) ? max( 1, absint( $input['printer_width'] ) ) : 48;

		$clean['sizes']    = $this->sanitize_rows( isset( $input['sizes'] ) ? $input['sizes'] : array() );
		$clean['toppings'] = $this->sanitize_rows( isset( $input['toppings'] ) ? $input['toppings'] : array() );

		return $clean;
	}

	/**
	 * Keep-current handling for a write-only secret field. Phase 2 secret inputs
	 * render blank (never echoing the stored secret back to the browser), so an
	 * empty submission means "leave unchanged" — we preserve the previously stored
	 * value rather than overwriting it with ''. A non-empty submission replaces it.
	 *
	 * @param array  $input    Raw submitted settings.
	 * @param array  $existing Currently stored settings (merged over defaults).
	 * @param string $key      Secret setting key.
	 * @return string
	 */
	private function keep_secret( $input, $existing, $key ) {
		$submitted = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
		if ( '' === $submitted ) {
			return isset( $existing[ $key ] ) ? (string) $existing[ $key ] : '';
		}
		return sanitize_text_field( $submitted );
	}

	/**
	 * Sanitize a repeatable list of {label, price} rows into {slug,label,price}.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array[]
	 */
	private function sanitize_rows( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$clean = array();
		$seen  = array();

		foreach ( $rows as $row ) {
			if ( empty( $row['label'] ) ) {
				continue;
			}
			$label = sanitize_text_field( $row['label'] );
			$slug  = sanitize_title( $label );
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				$slug = $slug ? $slug . '-' . wp_rand( 100, 999 ) : 'item-' . wp_rand( 100, 999 );
			}
			$seen[ $slug ] = true;

			$clean[] = array(
				'slug'  => $slug,
				'label' => $label,
				'price' => isset( $row['price'] ) ? max( 0, round( (float) $row['price'], 2 ) ) : 0,
			);
		}

		return $clean;
	}

	/**
	 * Enqueue admin assets on our screens only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, 'doughboss' ) ) {
			return;
		}

		wp_enqueue_style(
			'doughboss-admin',
			DOUGHBOSS_PLUGIN_URL . 'public/css/doughboss-admin.css',
			array(),
			DOUGHBOSS_VERSION
		);

		// Small inline script for live status changes & settings repeaters.
		wp_register_script( 'doughboss-admin', false, array(), DOUGHBOSS_VERSION, true );
		wp_enqueue_script( 'doughboss-admin' );
		wp_localize_script(
			'doughboss-admin',
			'DoughBossAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( DOUGHBOSS_REST_NAMESPACE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
		wp_add_inline_script( 'doughboss-admin', $this->inline_admin_js() );

		// The voucher scanner ships its own modern dashboard app + styles, loaded
		// only on its screen.
		if ( false !== strpos( $hook, 'doughboss-scan' ) ) {
			wp_enqueue_style(
				'doughboss-voucher-scan',
				DOUGHBOSS_PLUGIN_URL . 'public/css/doughboss-voucher-scan.css',
				array(),
				DOUGHBOSS_VERSION
			);
			wp_enqueue_script(
				'doughboss-voucher-scan',
				DOUGHBOSS_PLUGIN_URL . 'public/js/doughboss-voucher-scan.js',
				array(),
				DOUGHBOSS_VERSION,
				true
			);
			wp_localize_script(
				'doughboss-voucher-scan',
				'DoughBossScan',
				array(
					'restUrl'  => esc_url_raw( rest_url( DOUGHBOSS_REST_NAMESPACE ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'currency' => DoughBoss_Settings::get( 'currency_symbol', '$' ),
				)
			);
		}

		// The live order board ships its own (larger) app + styles, loaded only
		// on its screen.
		if ( false !== strpos( $hook, 'doughboss-board' ) ) {
			// Only pass a raw board key to the board JavaScript after it has passed
			// the same verifier used by the page and REST API. The key is held in
			// memory for this page load and sent on each protected KDS request.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page gate, verified against the stored secret.
			$supplied_board_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
			$board_key_for_js    = DoughBoss_Settings::verify_board_access_key( $supplied_board_key ) ? $supplied_board_key : '';
			wp_enqueue_style(
				'doughboss-orderboard',
				DOUGHBOSS_PLUGIN_URL . 'public/css/doughboss-orderboard.css',
				array(),
				DOUGHBOSS_VERSION
			);
			wp_enqueue_script(
				'doughboss-orderboard',
				DOUGHBOSS_PLUGIN_URL . 'public/js/doughboss-orderboard.js',
				array(),
				DOUGHBOSS_VERSION,
				true
			);
			wp_localize_script(
				'doughboss-orderboard',
				'DoughBossBoard',
				array(
					'restUrl'   => esc_url_raw( rest_url( DOUGHBOSS_REST_NAMESPACE ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'boardKey'  => $board_key_for_js,
					'currency'  => DoughBoss_Settings::get( 'currency_symbol', '$' ),
					'pollMs'    => 7000,
					'statuses'  => DoughBoss_Order::statuses(),
					'locations' => $this->board_locations(),
					// Mercure real-time config (public hub URL + topic only; never the
					// publish JWT). Empty/disabled => the board stays on its poll.
					'mercure'   => DoughBoss_Mercure::js_config(),
				)
			);
		}
	}

	/**
	 * Compact list of shops for the board's shop filter.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	private function board_locations() {
		$out = array();
		foreach ( DoughBoss_Locations::all() as $loc ) {
			$out[] = array( 'id' => (int) $loc->id, 'name' => $loc->name );
		}
		return $out;
	}

	/**
	 * Shop names keyed by location ID, for oversight filters/columns.
	 *
	 * @return array<int,string>
	 */
	private function location_names() {
		$out = array();
		foreach ( DoughBoss_Locations::all() as $loc ) {
			$out[ (int) $loc->id ] = (string) $loc->name;
		}
		return $out;
	}

	/**
	 * Inline JS powering the orders status dropdowns and settings repeaters.
	 *
	 * @return string
	 */
	private function inline_admin_js() {
		return <<<'JS'
(function () {
	document.addEventListener('change', function (e) {
		var sel = e.target;
		var isOrder = sel.matches('.db-status-select');
		var isCatering = sel.matches('.db-catering-status');
		if (!isOrder && !isCatering) { return; }
		var id = sel.getAttribute(isOrder ? 'data-order' : 'data-enquiry');
		var endpoint = isOrder
			? DoughBossAdmin.restUrl + '/admin/order/' + id + '/status'
			: DoughBossAdmin.restUrl + '/admin/catering/' + id + '/status';
		sel.disabled = true;
		fetch(endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DoughBossAdmin.nonce },
			body: JSON.stringify({ status: sel.value })
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (data) {
				if (!r.ok) { throw new Error(data.message || 'Request failed.'); }
				return data;
			});
		}).then(function () {
			sel.disabled = false;
			var row = sel.closest('tr');
			if (row) { row.style.transition = 'background .6s'; row.style.background = '#eaffea'; setTimeout(function(){ row.style.background=''; }, 800); }
		}).catch(function () { sel.disabled = false; alert('Could not update status.'); });
	});

	document.addEventListener('click', function (e) {
		var addBtn = e.target.closest('.db-add-row');
		if (addBtn) {
			e.preventDefault();
			var body = document.querySelector('#' + addBtn.getAttribute('data-target') + ' tbody');
			var tpl = body.querySelector('tr');
			var clone = tpl.cloneNode(true);
			clone.querySelectorAll('input').forEach(function (i) { i.value = ''; });
			body.appendChild(clone);
			return;
		}
		var removeBtn = e.target.closest('.db-remove-row');
		if (removeBtn) {
			e.preventDefault();
			var tr = removeBtn.closest('tr');
			var tbody = tr.parentNode;
			if (tbody.querySelectorAll('tr').length > 1) { tr.remove(); }
			else { tr.querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
			return;
		}

		var testBtn = e.target.closest('#db-mercure-test');
		if (testBtn) {
			e.preventDefault();
			var out = document.getElementById('db-mercure-test-result');
			testBtn.disabled = true;
			if (out) { out.textContent = '…'; out.style.color = ''; }
			fetch(DoughBossAdmin.restUrl + '/mercure/test', {
				headers: { 'X-WP-Nonce': DoughBossAdmin.nonce }
			}).then(function (r) { return r.json(); }).then(function (d) {
				testBtn.disabled = false;
				if (!out) { return; }
				out.textContent = (d && d.message) ? d.message : 'Done.';
				out.style.color = (d && d.ok) ? '#1f7a37' : '#b32d2e';
			}).catch(function () {
				testBtn.disabled = false;
				if (out) { out.textContent = 'Request failed.'; out.style.color = '#b32d2e'; }
			});
		}

		var pvBtn = e.target.closest('#db-pospal-verify');
		if (pvBtn) {
			e.preventDefault();
			var pvOut = document.getElementById('db-pospal-verify-result');
			pvBtn.disabled = true;
			if (pvOut) { pvOut.textContent = '…'; pvOut.style.color = ''; }
			fetch(DoughBossAdmin.restUrl + '/pospal/verify-coupons?store=' + encodeURIComponent((document.getElementById('db-pospal-store') || {}).value || '1'), {
				headers: { 'X-WP-Nonce': DoughBossAdmin.nonce }
			}).then(function (r) { return r.json(); }).then(function (d) {
				pvBtn.disabled = false;
				if (!pvOut) { return; }
				pvOut.textContent = (d && d.message) ? d.message : 'Done.';
				pvOut.style.color = (d && d.ok) ? '#1f7a37' : '#b32d2e';
			}).catch(function () {
				pvBtn.disabled = false;
				if (pvOut) { pvOut.textContent = 'Request failed.'; pvOut.style.color = '#b32d2e'; }
			});
		}

		var tgBtn = e.target.closest('#db-pospal-test-grant');
		if (tgBtn) {
			e.preventDefault();
			var tgOut = document.getElementById('db-pospal-test-result');
			var phoneEl = document.getElementById('db-pospal-test-phone');
			var valEl = document.getElementById('db-pospal-test-value');
			tgBtn.disabled = true;
			if (tgOut) { tgOut.textContent = '…'; tgOut.style.color = ''; }
			fetch(DoughBossAdmin.restUrl + '/pospal/test-grant', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DoughBossAdmin.nonce },
				body: JSON.stringify({ phone: phoneEl ? phoneEl.value : '', value: valEl ? valEl.value : '5', store: (document.getElementById('db-pospal-store') || {}).value || '1' })
			}).then(function (r) { return r.json(); }).then(function (d) {
				tgBtn.disabled = false;
				if (tgOut) {
					tgOut.textContent = (d && d.message ? d.message : 'Done.') + (d && d.response ? '\n' + JSON.stringify(d.response) : '');
					tgOut.style.color = (d && d.ok) ? '#1f7a37' : '#b32d2e';
				}
				var rev = document.getElementById('db-pospal-test-revoke');
				if (rev && d && d.ok && d.member_uid && d.coupon_ref) {
					rev.style.display = '';
					rev.setAttribute('data-uid', d.member_uid);
					rev.setAttribute('data-ref', d.coupon_ref);
				}
			}).catch(function () {
				tgBtn.disabled = false;
				if (tgOut) { tgOut.textContent = 'Request failed.'; tgOut.style.color = '#b32d2e'; }
			});
		}

		var pbBtn = e.target.closest('#db-pospal-probe');
		if (pbBtn) {
			e.preventDefault();
			var pbOut = document.getElementById('db-pospal-test-result');
			var pbPhone = document.getElementById('db-pospal-test-phone');
			var pbVal = document.getElementById('db-pospal-test-value');
			pbBtn.disabled = true;
			if (pbOut) { pbOut.textContent = 'Probing…'; pbOut.style.color = ''; }
			fetch(DoughBossAdmin.restUrl + '/pospal/probe-grant', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DoughBossAdmin.nonce },
				body: JSON.stringify({ phone: pbPhone ? pbPhone.value : '', value: pbVal ? pbVal.value : '5', store: (document.getElementById('db-pospal-store') || {}).value || '1' })
			}).then(function (r) { return r.json(); }).then(function (d) {
				pbBtn.disabled = false;
				if (pbOut) {
					pbOut.textContent = (d && d.message) ? d.message : 'Done.';
					pbOut.style.color = (d && d.ok) ? '#1f7a37' : '#b32d2e';
				}
			}).catch(function () {
				pbBtn.disabled = false;
				if (pbOut) { pbOut.textContent = 'Request failed.'; pbOut.style.color = '#b32d2e'; }
			});
		}

		var trBtn = e.target.closest('#db-pospal-test-revoke');
		if (trBtn) {
			e.preventDefault();
			var trOut = document.getElementById('db-pospal-test-result');
			trBtn.disabled = true;
			fetch(DoughBossAdmin.restUrl + '/pospal/test-revoke', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DoughBossAdmin.nonce },
				body: JSON.stringify({ customer_uid: trBtn.getAttribute('data-uid'), coupon_ref: trBtn.getAttribute('data-ref'), store: (document.getElementById('db-pospal-store') || {}).value || '1' })
			}).then(function (r) { return r.json(); }).then(function (d) {
				trBtn.disabled = false;
				if (trOut) { trOut.textContent = (d && d.message) ? d.message : 'Done.'; trOut.style.color = (d && d.ok) ? '#1f7a37' : '#b32d2e'; }
			}).catch(function () {
				trBtn.disabled = false;
				if (trOut) { trOut.textContent = 'Request failed.'; trOut.style.color = '#b32d2e'; }
			});
		}

		var pmLoadBtn = e.target.closest('#db-pospal-map-load');
		if (pmLoadBtn) {
			e.preventDefault();
			var pmLoadOut = document.getElementById('db-pospal-map-result');
			var pmStore = (document.getElementById('db-pospal-map-store') || {}).value || '1';
			pmLoadBtn.disabled = true;
			if (pmLoadOut) { pmLoadOut.textContent = 'Loading…'; pmLoadOut.style.color = ''; }
			fetch(DoughBossAdmin.restUrl + '/pospal/products?store=' + encodeURIComponent(pmStore), {
				headers: { 'X-WP-Nonce': DoughBossAdmin.nonce }
			}).then(function (r) { return r.json(); }).then(function (d) {
				pmLoadBtn.disabled = false;
				if (!d || !d.ok) {
					if (pmLoadOut) { pmLoadOut.textContent = (d && d.message) ? d.message : 'Could not load products.'; pmLoadOut.style.color = '#b32d2e'; }
					return;
				}
				var norm = function (s) { return String(s == null ? '' : s).toLowerCase().trim().replace(/\s+/g, ' '); };
				var byName = {};
				(d.products || []).forEach(function (p) { byName[norm(p.name)] = p; });
				var selects = document.querySelectorAll('.db-pospal-map-select');
				var matched = 0;
				selects.forEach(function (sel) {
					var key = sel.getAttribute('data-key');
					var current = sel.getAttribute('data-current') || '';
					var keep = sel.value; // Preserve "not mapped" vs the placeholder "currently mapped" option if no fresh match is found below.
					sel.innerHTML = '';
					var blank = document.createElement('option');
					blank.value = '';
					blank.textContent = '— not mapped —';
					sel.appendChild(blank);
					(d.products || []).forEach(function (p) {
						var opt = document.createElement('option');
						opt.value = p.uid;
						opt.textContent = p.name + (p.price != null ? ' ($' + p.price + ')' : '');
						sel.appendChild(opt);
					});
					var hit = byName[key];
					if (hit) {
						sel.value = String(hit.uid);
						matched++;
					} else if (current && !sel.querySelector('option[value="' + CSS.escape(current) + '"]')) {
						// Kept mapping doesn't match any fetched product by name — surface it
						// rather than silently dropping it so the operator can decide.
						var keepOpt = document.createElement('option');
						keepOpt.value = current;
						keepOpt.textContent = 'Currently mapped (uid ' + current + ') — no name match found';
						keepOpt.selected = true;
						sel.appendChild(keepOpt);
					} else {
						sel.value = current || '';
					}
				});
				if (pmLoadOut) {
					pmLoadOut.textContent = (d.message || '') + ' — ' + matched + ' of ' + selects.length + ' menu items auto-matched by name. Review below, then Save.';
					pmLoadOut.style.color = '#1f7a37';
				}
			}).catch(function () {
				pmLoadBtn.disabled = false;
				if (pmLoadOut) { pmLoadOut.textContent = 'Request failed.'; pmLoadOut.style.color = '#b32d2e'; }
			});
		}

		var pmSaveBtn = e.target.closest('#db-pospal-map-save');
		if (pmSaveBtn) {
			e.preventDefault();
			var pmSaveOut = document.getElementById('db-pospal-map-result');
			var map = {};
			document.querySelectorAll('.db-pospal-map-select').forEach(function (sel) {
				if (sel.value) { map[sel.getAttribute('data-key')] = sel.value; }
			});
			pmSaveBtn.disabled = true;
			if (pmSaveOut) { pmSaveOut.textContent = 'Saving…'; pmSaveOut.style.color = ''; }
			fetch(DoughBossAdmin.restUrl + '/pospal/product-map', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DoughBossAdmin.nonce },
				body: JSON.stringify({ map: map })
			}).then(function (r) { return r.json(); }).then(function (d) {
				pmSaveBtn.disabled = false;
				if (pmSaveOut) { pmSaveOut.textContent = (d && d.message) ? d.message : 'Done.'; pmSaveOut.style.color = (d && d.ok) ? '#1f7a37' : '#b32d2e'; }
			}).catch(function () {
				pmSaveBtn.disabled = false;
				if (pmSaveOut) { pmSaveOut.textContent = 'Request failed.'; pmSaveOut.style.color = '#b32d2e'; }
			});
		}
	});
}());
JS;
	}

	/**
	 * Render the Orders management page.
	 *
	 * @return void
	 */
	public function render_orders_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}

		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$location = isset( $_GET['location'] ) ? absint( $_GET['location'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$per_page = 20;
		$result   = DoughBoss_Order::query(
			array(
				'status'      => $status,
				'search'      => $search,
				'location_id' => $location,
				'per_page'    => $per_page,
				'page'        => $paged,
			)
		);

		$statuses       = DoughBoss_Order::statuses();
		$total_pages    = (int) ceil( $result['total'] / $per_page );
		$pay_issues     = $this->unreconciled_payments();
		$items_by_order = DoughBoss_Order::get_items_for_orders( wp_list_pluck( $result['items'], 'id' ) );
		$location_names = $this->location_names();
		$multi_shop     = count( $location_names ) > 1;
		$colspan        = $multi_shop ? 8 : 7;

		// Today-at-a-glance strip (site-local day, respects the shop filter).
		list( $t_start, $t_end ) = DoughBoss_Reports::today_bounds();
		$today     = DoughBoss_Reports::summary( $t_start, $t_end, $location );
		$today_pay = DoughBoss_Reports::payment_mix( $t_start, $t_end, $location );
		$today_unpaid   = isset( $today_pay['unpaid'] ) ? $today_pay['unpaid'] : array( 'orders' => 0, 'revenue' => 0.0 );
		$today_refunded = isset( $today_pay['refunded'] ) ? $today_pay['refunded'] : array( 'orders' => 0, 'revenue' => 0.0 );
		?>
		<div class="wrap doughboss-orders">
			<h1><?php esc_html_e( 'Orders', 'doughboss' ); ?></h1>

			<h2 style="margin:14px 0 6px;">
				<?php
				if ( $location > 0 && isset( $location_names[ $location ] ) ) {
					/* translators: %s: shop/location name. */
					echo esc_html( sprintf( __( 'Today — %s', 'doughboss' ), $location_names[ $location ] ) );
				} else {
					esc_html_e( 'Today — all shops', 'doughboss' );
				}
				?>
			</h2>
			<div style="display:flex;gap:12px;flex-wrap:wrap;margin:0 0 18px;">
				<div class="card" style="min-width:140px;margin:0;padding:10px 16px;">
					<p style="margin:0;color:#646970;"><?php esc_html_e( 'Orders', 'doughboss' ); ?></p>
					<p style="font-size:1.5em;margin:2px 0 0;"><strong><?php echo esc_html( number_format_i18n( $today['orders'] ) ); ?></strong></p>
				</div>
				<div class="card" style="min-width:140px;margin:0;padding:10px 16px;">
					<p style="margin:0;color:#646970;"><?php esc_html_e( 'Gross sales', 'doughboss' ); ?></p>
					<p style="font-size:1.5em;margin:2px 0 0;"><strong><?php echo esc_html( DoughBoss_Settings::format_price( $today['revenue'] ) ); ?></strong></p>
				</div>
				<div class="card" style="min-width:140px;margin:0;padding:10px 16px;">
					<p style="margin:0;color:#646970;"><?php esc_html_e( 'Paid by card', 'doughboss' ); ?></p>
					<p style="font-size:1.5em;margin:2px 0 0;"><strong><?php echo esc_html( DoughBoss_Settings::format_price( $today['paid_revenue'] ) ); ?></strong>
						<small><?php echo esc_html( sprintf( /* translators: %s: order count. */ _n( '%s order', '%s orders', $today['paid_orders'], 'doughboss' ), number_format_i18n( $today['paid_orders'] ) ) ); ?></small></p>
				</div>
				<div class="card" style="min-width:140px;margin:0;padding:10px 16px;">
					<p style="margin:0;color:#646970;"><?php esc_html_e( 'To collect in store', 'doughboss' ); ?></p>
					<p style="font-size:1.5em;margin:2px 0 0;"><strong><?php echo esc_html( DoughBoss_Settings::format_price( $today_unpaid['revenue'] ) ); ?></strong>
						<small><?php echo esc_html( sprintf( /* translators: %s: order count. */ _n( '%s order', '%s orders', $today_unpaid['orders'], 'doughboss' ), number_format_i18n( $today_unpaid['orders'] ) ) ); ?></small></p>
				</div>
				<?php if ( $today_refunded['orders'] > 0 ) : ?>
					<div class="card" style="min-width:140px;margin:0;padding:10px 16px;">
						<p style="margin:0;color:#b32d2e;"><?php esc_html_e( 'Refunded', 'doughboss' ); ?></p>
						<p style="font-size:1.5em;margin:2px 0 0;"><strong><?php echo esc_html( DoughBoss_Settings::format_price( $today_refunded['revenue'] ) ); ?></strong>
							<small><?php echo esc_html( sprintf( /* translators: %s: order count. */ _n( '%s order', '%s orders', $today_refunded['orders'], 'doughboss' ), number_format_i18n( $today_refunded['orders'] ) ) ); ?></small></p>
					</div>
				<?php endif; ?>
			</div>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash after a nonce-checked redirect.
			$flash = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
			if ( 'refunded' === $flash ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Refund issued. Note: a voucher used on the order is NOT automatically reissued — issue a new one from the Vouchers page if needed.', 'doughboss' ) . '</p></div>';
			} elseif ( 'refund_already' === $flash ) {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'That order has already been refunded.', 'doughboss' ) . '</p></div>';
			} elseif ( 'refund_ineligible' === $flash ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'That order cannot be refunded here — it has no verified card payment.', 'doughboss' ) . '</p></div>';
			} elseif ( 'refund_error' === $flash ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash after a nonce-checked redirect.
				$detail = isset( $_GET['detail'] ) ? sanitize_text_field( wp_unslash( $_GET['detail'] ) ) : '';
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The refund could not be processed.', 'doughboss' ) . ( '' !== $detail ? ' ' . esc_html( $detail ) : '' ) . '</p></div>';
			}
			?>

			<?php if ( ! empty( $pay_issues ) ) : ?>
				<div class="notice notice-error" style="padding:12px 16px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Payment issues — money taken, no order created', 'doughboss' ); ?></h2>
					<p><?php esc_html_e( 'The payment gateway reported these payments as succeeded, but no matching order was ever created (the customer paid and then their checkout never completed). Look each one up in your payment gateway\'s dashboard for the card-holder details, then contact the customer or refund the payment. Clear the list once every payment has been dealt with.', 'doughboss' ); ?></p>
					<table class="widefat striped" style="max-width:720px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'PaymentIntent', 'doughboss' ); ?></th>
								<th><?php esc_html_e( 'Amount', 'doughboss' ); ?></th>
								<th><?php esc_html_e( 'Currency', 'doughboss' ); ?></th>
								<th><?php esc_html_e( 'Seen', 'doughboss' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $pay_issues as $issue ) : ?>
								<tr>
									<td><code><?php echo esc_html( $issue['id'] ); ?></code></td>
									<td><?php echo esc_html( number_format_i18n( absint( $issue['amount'] ) / 100, 2 ) ); ?></td>
									<td><?php echo esc_html( strtoupper( (string) $issue['currency'] ) ); ?></td>
									<td><?php echo esc_html( wp_date( 'M j, Y g:i a', (int) $issue['time'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'doughboss_clear_payment_issues' ); ?>
						<input type="hidden" name="action" value="doughboss_clear_payment_issues" />
						<p><button type="submit" class="button"><?php esc_html_e( 'Clear list — all handled', 'doughboss' ); ?></button></p>
					</form>
				</div>
			<?php endif; ?>

			<form method="get" class="db-orders-filter">
				<input type="hidden" name="page" value="doughboss" />
				<select name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'doughboss' ); ?></option>
					<?php foreach ( $statuses as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( $multi_shop ) : ?>
					<select name="location">
						<option value="0"><?php esc_html_e( 'All shops', 'doughboss' ); ?></option>
						<?php foreach ( $location_names as $loc_id => $loc_name ) : ?>
							<option value="<?php echo esc_attr( $loc_id ); ?>" <?php selected( $location, $loc_id ); ?>>
								<?php echo esc_html( $loc_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search orders…', 'doughboss' ); ?>" />
				<button class="button"><?php esc_html_e( 'Filter', 'doughboss' ); ?></button>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order #', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'doughboss' ); ?></th>
						<?php if ( $multi_shop ) : ?>
							<th><?php esc_html_e( 'Shop', 'doughboss' ); ?></th>
						<?php endif; ?>
						<th><?php esc_html_e( 'Type', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Items', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Total', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Placed', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Status', 'doughboss' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $result['items'] ) ) : ?>
						<tr><td colspan="<?php echo esc_attr( $colspan ); ?>"><?php esc_html_e( 'No orders yet.', 'doughboss' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $result['items'] as $order ) : ?>
							<?php $items = isset( $items_by_order[ (int) $order->id ] ) ? $items_by_order[ (int) $order->id ] : array(); ?>
							<tr>
								<td><strong><?php echo esc_html( $order->order_number ); ?></strong></td>
								<td>
									<?php echo esc_html( $order->customer_name ); ?><br />
									<small><?php echo esc_html( $order->customer_email ); ?></small><br />
									<small><?php echo esc_html( $order->customer_phone ); ?></small>
								</td>
								<?php if ( $multi_shop ) : ?>
									<td>
										<?php
										$loc_id = isset( $order->location_id ) ? (int) $order->location_id : 0;
										echo esc_html( isset( $location_names[ $loc_id ] ) ? $location_names[ $loc_id ] : __( '—', 'doughboss' ) );
										?>
									</td>
								<?php endif; ?>
								<td><?php echo esc_html( ucfirst( $order->order_type ) ); ?></td>
								<td>
									<ul class="db-item-list">
										<?php foreach ( $items as $item ) : ?>
											<li>
												<?php echo esc_html( $item['quantity'] . '× ' . $item['name'] ); ?>
												<?php if ( ! empty( $item['toppings'] ) ) : ?>
													<small>(<?php echo esc_html( implode( ', ', wp_list_pluck( $item['toppings'], 'label' ) ) ); ?>)</small>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
								</td>
								<td>
									<?php echo esc_html( DoughBoss_Settings::format_price( $order->total ) ); ?>
									<?php if ( isset( $order->discount ) && (float) $order->discount > 0 ) : ?>
										<br /><small style="color:#1f8a54;">
										<?php
										printf(
											/* translators: 1: discount amount, 2: voucher code. */
											esc_html__( '−%1$s voucher %2$s', 'doughboss' ),
											esc_html( DoughBoss_Settings::format_price( (float) $order->discount ) ),
											esc_html( $order->voucher_code )
										);
										?>
										</small>
									<?php endif; ?>
									<?php if ( isset( $order->payment_method ) && in_array( $order->payment_method, array( 'stripe', 'tyro' ), true ) && ! empty( $order->payment_intent_id ) ) : ?>
										<?php if ( 'paid' === $order->payment_status ) : ?>
											<br /><small>
												<?php esc_html_e( 'Paid by card', 'doughboss' ); ?> ·
												<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=doughboss_refund_order&id=' . $order->id ), 'doughboss_refund_order_' . $order->id ) ); ?>" style="color:#b32d2e;" onclick="return confirm('<?php echo esc_js( __( 'Refund this order in full? A voucher used on the order is NOT automatically reissued.', 'doughboss' ) ); ?>');"><?php esc_html_e( 'Refund', 'doughboss' ); ?></a>
											</small>
										<?php elseif ( 'refunded' === $order->payment_status ) : ?>
											<br /><small style="color:#b32d2e;"><?php esc_html_e( 'Refunded', 'doughboss' ); ?></small>
										<?php endif; ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( mysql2date( 'M j, g:i a', $order->created_at ) ); ?></td>
								<td>
									<select class="db-status-select" data-order="<?php echo esc_attr( $order->id ); ?>">
										<?php foreach ( $statuses as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $order->status, $key ); ?>>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Unreconciled Stripe payments to surface, pruned of false alarms.
	 *
	 * The storefront webhook usually races the synchronous /checkout call, so
	 * an entry is often reconciled seconds after it is recorded. Re-checking
	 * payment_intent_used() here (and holding back entries still inside a
	 * short race window) keeps the card limited to payments that genuinely
	 * never became an order.
	 *
	 * @return array[] Entries with id/amount/currency/time keys.
	 */
	private function unreconciled_payments() {
		global $wpdb;

		$list = get_option( 'doughboss_unreconciled_payments', array() );
		if ( ! is_array( $list ) || empty( $list ) ) {
			return array();
		}

		$kept     = array();
		$kept_ids = array();
		foreach ( $list as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
				continue;
			}
			if ( DoughBoss_Order::payment_intent_used( (string) $entry['id'] ) ) {
				continue;
			}
			$kept[]     = $entry;
			$kept_ids[] = $entry['id'];
		}

		if ( count( $kept ) !== count( $list ) ) {
			// Persist the prune only under the same named lock the webhook writer
			// takes, re-reading inside it: a plain rewrite here could clobber an
			// entry a concurrent webhook just appended, and Stripe never
			// redelivers an event it got a 200 for. If the lock isn't free, skip
			// persisting — pruning is cosmetic and re-runs on the next page load.
			$locked = ( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', 'doughboss_unrec_pay', 1 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $locked ) {
				wp_cache_delete( 'doughboss_unreconciled_payments', 'options' );
				$fresh    = get_option( 'doughboss_unreconciled_payments', array() );
				$read_ids = wp_list_pluck( $list, 'id' );
				$store    = array();
				foreach ( ( is_array( $fresh ) ? $fresh : array() ) as $entry ) {
					if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
						continue;
					}
					// Keep entries that arrived after our read, plus the ones we
					// verified as still unreconciled; drop only verified-pruned ones.
					if ( ! in_array( $entry['id'], $read_ids, true ) || in_array( $entry['id'], $kept_ids, true ) ) {
						$store[] = $entry;
					}
				}
				if ( empty( $store ) ) {
					delete_option( 'doughboss_unreconciled_payments' );
				} else {
					update_option( 'doughboss_unreconciled_payments', $store, false );
				}
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'doughboss_unrec_pay' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		$now  = time();
		$show = array();
		foreach ( $kept as $entry ) {
			$seen = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
			if ( ( $now - $seen ) >= 5 * MINUTE_IN_SECONDS ) {
				$show[] = $entry;
			}
		}

		return $show;
	}

	/**
	 * Admin-post handler: clear the unreconciled-payments list once the owner
	 * has dealt with the flagged payments in the Stripe Dashboard.
	 *
	 * @return void
	 */
	public function handle_clear_payment_issues() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_clear_payment_issues' );

		delete_option( 'doughboss_unreconciled_payments' );

		$base = wp_get_referer();
		if ( ! $base ) {
			$base = admin_url( 'admin.php?page=doughboss' );
		}
		wp_safe_redirect( $base );
		exit;
	}

	/**
	 * Admin-post handler: clear the POSPal unmapped-item alert queue.
	 *
	 * @return void
	 */
	public function handle_clear_pospal_alerts() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_clear_pospal_alerts' );

		delete_option( DoughBoss_POSPal_Orders::UNMAPPED_ALERTS_OPTION );

		$base = wp_get_referer();
		if ( ! $base ) {
			$base = admin_url( 'admin.php?page=doughboss-settings' );
		}
		wp_safe_redirect( $base );
		exit;
	}

	/**
	 * admin_notices: surface recent POSPal order-push skips (unmapped menu items)
	 * so they're visible in wp-admin rather than only in the server error log.
	 * Shown to anyone who can manage DoughBoss, on every admin screen, until
	 * dismissed — a skipped till push is easy to miss otherwise.
	 *
	 * @return void
	 */
	public function render_pospal_unmapped_notice() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$alerts = get_option( DoughBoss_POSPal_Orders::UNMAPPED_ALERTS_OPTION, array() );
		if ( empty( $alerts ) || ! is_array( $alerts ) ) {
			return;
		}

		$names = array();
		foreach ( $alerts as $a ) {
			foreach ( (array) ( isset( $a['items'] ) ? $a['items'] : array() ) as $n ) {
				$names[ $n ] = true;
			}
		}
		$names = array_keys( $names );

		$clear_url = wp_nonce_url( admin_url( 'admin-post.php?action=doughboss_clear_pospal_alerts' ), 'doughboss_clear_pospal_alerts' );
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<?php
				printf(
					/* translators: 1: number of orders, 2: comma-separated item names. */
					esc_html( _n( '%1$d recent order didn\'t reach the POSPal till — unmapped menu item(s): %2$s.', '%1$d recent orders didn\'t reach the POSPal till — unmapped menu item(s): %2$s.', count( $alerts ), 'doughboss' ) ),
					count( $alerts ),
					esc_html( implode( ', ', array_slice( $names, 0, 8 ) ) . ( count( $names ) > 8 ? '…' : '' ) )
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=doughboss-settings' ) ); ?>#db-pospal-map-load"><?php esc_html_e( 'Map them in Settings', 'doughboss' ); ?></a>
				&middot;
				<a href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Dismiss', 'doughboss' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * admin_notices: tell the owner that the 1.10.0 migration turned delivery
	 * off (single-location pickup-only scope). The migration is a one-shot and
	 * deliberately conservative, but it must never be silent — this notice
	 * stays until dismissed so the owner knows delivery can be re-enabled in
	 * Settings at any time.
	 *
	 * @return void
	 */
	public function render_delivery_autodisabled_notice() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! get_option( 'doughboss_delivery_autodisabled' ) ) {
			return;
		}
		$dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=doughboss_clear_delivery_notice' ), 'doughboss_clear_delivery_notice' );
		?>
		<div class="notice notice-info">
			<p>
				<?php esc_html_e( 'DoughBoss update: delivery ordering was switched off as part of the pickup-only launch scope (single-location mode). Pickup ordering is unaffected.', 'doughboss' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=doughboss-settings' ) ); ?>"><?php esc_html_e( 'Re-enable delivery in Settings', 'doughboss' ); ?></a>
				&middot;
				<a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'doughboss' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the dismiss link on the delivery-autodisabled notice.
	 *
	 * @return void
	 */
	public function handle_clear_delivery_notice() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_clear_delivery_notice' );

		delete_option( 'doughboss_delivery_autodisabled' );

		$base = wp_get_referer();
		if ( ! $base ) {
			$base = admin_url( 'admin.php?page=doughboss-settings' );
		}
		wp_safe_redirect( $base );
		exit;
	}

	/**
	 * Handle POSPal outbox retries. Bulk retry excludes ambiguous outcomes. A
	 * selected ambiguous row requires an explicit confirmation that staff checked
	 * the till and found no matching order.
	 *
	 * @return void
	 */
	public function handle_pospal_outbox_resend() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		$id = isset( $_REQUEST['outbox_id'] ) ? absint( $_REQUEST['outbox_id'] ) : 0;
		if ( $id ) {
			$expected_updated_at = isset( $_POST['outbox_updated_at'] ) ? sanitize_text_field( wp_unslash( $_POST['outbox_updated_at'] ) ) : '';
			$expected_error      = isset( $_POST['outbox_error'] ) ? sanitize_key( wp_unslash( $_POST['outbox_error'] ) ) : '';
			if ( '' === $expected_updated_at || ! in_array( $expected_error, array( 'ambiguous_network', 'ambiguous_in_flight' ), true ) ) {
				wp_die( esc_html__( 'This POSPal review form is incomplete. Refresh the page and try again.', 'doughboss' ) );
			}
			$state_token = md5( $expected_updated_at . '|' . $expected_error );
			check_admin_referer( 'doughboss_pospal_outbox_resend_' . $id . '_' . $state_token );
			if ( empty( $_POST['confirm_missing'] ) ) {
				wp_die( esc_html__( 'Check the POSPal till and confirm that the order is missing before re-sending it.', 'doughboss' ) );
			}
			$released = DoughBoss_POSPal_Outbox::reset_for_retry( $id, true, $expected_updated_at, $expected_error );
			if ( 1 !== (int) $released ) {
				wp_die( esc_html__( 'This POSPal outcome changed after you opened the page. Nothing was re-sent; refresh and review the current state.', 'doughboss' ) );
			}
		} else {
			check_admin_referer( 'doughboss_pospal_outbox_resend' );
			DoughBoss_POSPal_Outbox::reset_for_retry();
		}

		$base = wp_get_referer();
		if ( ! $base ) {
			$base = admin_url( 'admin.php?page=doughboss-settings' );
		}
		wp_safe_redirect( $base );
		exit;
	}

	/**
	 * admin_notices: surface POSPal outbox rows that either exhausted their retry
	 * budget (terminal) or are still retrying after 3+ attempts (borderline).
	 * Explicit remote failures may be bulk-retried. Ambiguous outcomes are listed
	 * individually and require a confirmed till check before release.
	 * Silent when the outbox is clean — no notice noise on a healthy site.
	 *
	 * @return void
	 */
	public function render_pospal_outbox_notice() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! class_exists( 'DoughBoss_POSPal_Outbox' ) ) {
			return;
		}
		$counts = DoughBoss_POSPal_Outbox::counts_for_alert();
		if ( 0 === (int) $counts['terminal'] && 0 === (int) $counts['retrying'] ) {
			return;
		}

		$resend_url = wp_nonce_url( admin_url( 'admin-post.php?action=doughboss_pospal_outbox_resend' ), 'doughboss_pospal_outbox_resend' );
		$rows       = DoughBoss_POSPal_Outbox::list_ambiguous_rows( 100 );
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				if ( $counts['retryable_terminal'] > 0 ) {
					printf(
						/* translators: %d: number of explicit POSPal failures. */
						esc_html( _n( '%d order received repeated explicit POSPal errors.', '%d orders received repeated explicit POSPal errors.', $counts['retryable_terminal'], 'doughboss' ) ),
						(int) $counts['retryable_terminal']
					);
				}
				if ( $counts['ambiguous'] > 0 ) {
					if ( $counts['retryable_terminal'] > 0 ) {
						echo ' ';
					}
					printf(
						/* translators: %d: number of orders whose remote outcome is unknown. */
						esc_html( _n( '%d order may already be on the till and needs a manual check.', '%d orders may already be on the till and need a manual check.', $counts['ambiguous'], 'doughboss' ) ),
						(int) $counts['ambiguous']
					);
				}
				if ( $counts['retrying'] > 0 ) {
					if ( $counts['terminal'] > 0 ) {
						echo ' ';
					}
					printf(
						/* translators: %d: number of POSPal push rows still retrying. */
						esc_html( _n( '%d order is still retrying.', '%d orders are still retrying.', $counts['retrying'], 'doughboss' ) ),
						(int) $counts['retrying']
					);
				}
				if ( $counts['retryable_terminal'] > 0 ) {
					echo ' ';
				?>
				<a href="<?php echo esc_url( $resend_url ); ?>"><?php esc_html_e( 'Retry explicit failures', 'doughboss' ); ?></a>
				<?php } ?>
			</p>
			<?php if ( (int) $counts['ambiguous'] > count( $rows ) ) : ?>
				<p><?php echo esc_html( sprintf( __( 'Showing the oldest %1$d of %2$d ambiguous orders. Resolve these and refresh to review the remainder.', 'doughboss' ), count( $rows ), (int) $counts['ambiguous'] ) ); ?></p>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$payload = json_decode( (string) $row->payload_json, true );
				$day_seq = is_array( $payload ) && ! empty( $payload['daySeq'] ) ? sanitize_text_field( (string) $payload['daySeq'] ) : '';
				$state_token = md5( (string) $row->updated_at . '|' . (string) $row->last_error );
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0 12px;">
					<input type="hidden" name="action" value="doughboss_pospal_outbox_resend" />
					<input type="hidden" name="outbox_id" value="<?php echo esc_attr( (int) $row->id ); ?>" />
					<input type="hidden" name="outbox_updated_at" value="<?php echo esc_attr( (string) $row->updated_at ); ?>" />
					<input type="hidden" name="outbox_error" value="<?php echo esc_attr( (string) $row->last_error ); ?>" />
					<?php wp_nonce_field( 'doughboss_pospal_outbox_resend_' . (int) $row->id . '_' . $state_token ); ?>
					<strong><?php echo esc_html( $day_seq ? sprintf( __( 'POSPal order %s', 'doughboss' ), $day_seq ) : __( 'POSPal order number unavailable', 'doughboss' ) ); ?></strong>
					&mdash; <?php echo esc_html( sprintf( __( 'store %1$d, outbox #%2$d, %3$s, %4$s', 'doughboss' ), (int) $row->store_index, (int) $row->id, (string) $row->updated_at, (string) $row->last_error ) ); ?><br />
					<?php if ( $day_seq ) : ?>
						<label><input type="checkbox" name="confirm_missing" value="1" required /> <?php echo esc_html( sprintf( __( 'I searched POSPal for %s and confirmed this order is missing.', 'doughboss' ), $day_seq ) ); ?></label>
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Re-send this order', 'doughboss' ); ?></button>
					<?php else : ?>
						<?php esc_html_e( 'Do not re-send from this screen. Match the customer, store and time with POSPal, then escalate for manual recovery.', 'doughboss' ); ?>
					<?php endif; ?>
				</form>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Generate a fresh random Order Board access key and store its verifier. Only ever
	 * called from this dedicated, nonce-protected action — never accepted as
	 * free text from the general settings form — so the stored key is always
	 * drawn from generate_board_key()'s URL-safe alphabet. Redirects back with
	 * a one-time reveal transient so the plaintext key can be shown exactly
	 * once (see render_board_key_reveal_notice()).
	 *
	 * @return void
	 */
	public function handle_generate_board_key() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_generate_board_key' );

		$key = self::generate_board_key();
		// Store only a sha256 digest — a DB read/dump/backup can no longer
		// disclose the working key. The one-time reveal below is the only place
		// the plaintext exists, and verification hashes the supplied ?key=
		// before comparing (see render_board_page()).
		DoughBoss_Settings::update( array( 'board_access_key' => hash( 'sha256', $key ) ) );

		// One-time reveal: a short-lived transient keyed to this user so only
		// the person who just generated it sees the plaintext, and only once —
		// the settings form itself never echoes the raw key back into HTML. The
		// persistent option contains only a SHA-256 verifier.
		set_transient( 'doughboss_board_key_reveal_' . get_current_user_id(), $key, MINUTE_IN_SECONDS );

		$base = wp_get_referer();
		if ( ! $base ) {
			$base = admin_url( 'admin.php?page=doughboss-settings' );
		}
		wp_safe_redirect( $base );
		exit;
	}

	/**
	 * Turn the Order Board access key off — the board falls back to being
	 * gated by login + the manage_doughboss_kds capability only.
	 *
	 * @return void
	 */
	public function handle_clear_board_key() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_clear_board_key' );

		DoughBoss_Settings::update( array( 'board_access_key' => '' ) );
		delete_transient( 'doughboss_board_key_reveal_' . get_current_user_id() );

		$base = wp_get_referer();
		if ( ! $base ) {
			$base = admin_url( 'admin.php?page=doughboss-settings' );
		}
		wp_safe_redirect( $base );
		exit;
	}

	/**
	 * admin_notices: show the just-generated Order Board key + bookmark URL
	 * exactly once, immediately after handle_generate_board_key() redirects
	 * back. The transient is deleted on read, so refreshing the page (or any
	 * later page load) never re-shows the plaintext key — the settings form
	 * itself only ever renders a masked "a key is set" state.
	 *
	 * @return void
	 */
	public function render_board_key_reveal_notice() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$uid = get_current_user_id();
		$key = get_transient( 'doughboss_board_key_reveal_' . $uid );
		if ( ! $key ) {
			return;
		}
		delete_transient( 'doughboss_board_key_reveal_' . $uid );

		$board_url = add_query_arg(
			array(
				'page' => 'doughboss-board',
				'key'  => $key,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<strong><?php esc_html_e( 'New Order Board link generated.', 'doughboss' ); ?></strong>
				<?php esc_html_e( "Copy it now — for your security it won't be shown again. Bookmark it on the kitchen device.", 'doughboss' ); ?>
			</p>
			<p>
				<input type="text" class="large-text" readonly onclick="this.select();" value="<?php echo esc_url( $board_url ); ?>" style="max-width:560px;" />
			</p>
			<p class="description">
				<?php esc_html_e( "This link contains a secret key in the URL. Anyone who sees the URL — a screen-share, browser history, a shared device, or your web host's access logs — could use it, so only bookmark it on the kitchen device itself and don't share it anywhere else.", 'doughboss' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * A random, URL-safe Order Board access key. Restricting the alphabet to
	 * unambiguous alphanumerics (no 0/O/1/l/I confusion, and critically no &,
	 * +, #, space, or / — characters that would otherwise corrupt the
	 * generated bookmark URL's query string or fall foul of PHP's '+' -> space
	 * GET-decoding) means the value is always safe to embed in a URL by
	 * construction, rather than relying on sanitizing free-text input after
	 * the fact.
	 *
	 * @return string 24-character key.
	 */
	private static function generate_board_key() {
		$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
		$max      = strlen( $alphabet ) - 1;
		$key      = '';
		for ( $i = 0; $i < 24; $i++ ) {
			$key .= $alphabet[ random_int( 0, $max ) ];
		}
		return $key;
	}

	/**
	 * Admin-post handler: refund a card-paid order in full.
	 *
	 * The payment reference id is always read from the stored order row (never
	 * from the request), and only orders verified as paid by card are
	 * eligible. The refund is routed to whichever gateway the order's own
	 * `payment_method` column records ('stripe' or 'tyro') — NOT whichever
	 * gateway is active in Settings today — so refunding an old order still
	 * works correctly even after the owner switches the active gateway. See
	 * DoughBoss_Payment::refund_via(). A voucher redeemed on the order is
	 * deliberately NOT reissued here — the owner reissues one manually from
	 * the Vouchers page if they want to.
	 *
	 * @return void
	 */
	public function handle_refund_order() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_refund_order_' . $id );

		$args      = array( 'page' => 'doughboss' );
		$order     = $id ? DoughBoss_Order::get( $id ) : null;
		$gateway   = $order && isset( $order->payment_method ) ? (string) $order->payment_method : '';
		$is_paid_by_gateway = in_array( $gateway, array( 'stripe', 'tyro' ), true );

		if ( ! $order ) {
			$args['msg'] = 'refund_error';
		} elseif ( isset( $order->payment_status ) && 'refunded' === $order->payment_status ) {
			$args['msg'] = 'refund_already';
		} elseif ( ! isset( $order->payment_status ) || 'paid' !== $order->payment_status || ! $is_paid_by_gateway || empty( $order->payment_intent_id ) ) {
			$args['msg'] = 'refund_ineligible';
		} else {
			$refund = DoughBoss_Payment::refund_via( $gateway, (string) $order->payment_intent_id );
			if ( is_wp_error( $refund ) ) {
				$args['msg']    = 'refund_error';
				$args['detail'] = rawurlencode( $refund->get_error_message() );
			} else {
				DoughBoss_Order::update_payment_status( (int) $order->id, 'refunded' );
				$args['msg'] = 'refunded';
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the Catering Enquiries management page.
	 *
	 * @return void
	 */
	public function render_catering_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}

		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$per_page = 20;
		$result   = DoughBoss_Catering::query(
			array(
				'status'   => $status,
				'search'   => $search,
				'per_page' => $per_page,
				'page'     => $paged,
			)
		);

		$statuses    = DoughBoss_Catering::statuses();
		$total_pages = (int) ceil( $result['total'] / $per_page );
		?>
		<div class="wrap doughboss-orders">
			<h1><?php esc_html_e( 'Catering Enquiries', 'doughboss' ); ?></h1>

			<form method="get" class="db-orders-filter">
				<input type="hidden" name="page" value="doughboss-catering" />
				<select name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'doughboss' ); ?></option>
					<?php foreach ( $statuses as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search enquiries…', 'doughboss' ); ?>" />
				<button class="button"><?php esc_html_e( 'Filter', 'doughboss' ); ?></button>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Enquiry #', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Event', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Package', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Guests', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Quote / Deposit', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Received', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Status', 'doughboss' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $result['items'] ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No catering enquiries yet.', 'doughboss' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $result['items'] as $row ) : ?>
							<?php
							$package = (int) $row['package_id'] ? get_the_title( (int) $row['package_id'] ) : __( 'Custom', 'doughboss' );
							$event   = '';
							if ( ! empty( $row['event_date'] ) ) {
								$event = mysql2date( 'M j, Y', $row['event_date'] );
								if ( ! empty( $row['event_time'] ) ) {
									$event .= ' · ' . $row['event_time'];
								}
							}
							?>
							<tr>
								<td><strong><?php echo esc_html( $row['enquiry_number'] ); ?></strong><br /><small><?php echo esc_html( ucfirst( $row['order_type'] ) ); ?></small></td>
								<td>
									<?php echo esc_html( $row['customer_name'] ); ?><br />
									<small><?php echo esc_html( $row['customer_email'] ); ?></small><br />
									<small><?php echo esc_html( $row['customer_phone'] ); ?></small>
								</td>
								<td><?php echo $event ? esc_html( $event ) : '—'; ?></td>
								<td><?php echo esc_html( $package ? $package : '—' ); ?></td>
								<td><?php echo esc_html( (int) $row['guest_count'] ? (string) (int) $row['guest_count'] : '—' ); ?></td>
								<td>
									<?php echo esc_html( DoughBoss_Settings::format_price( $row['quote_total'] ) ); ?><br />
									<small><?php echo esc_html( DoughBoss_Settings::format_price( $row['deposit_amount'] ) ); ?> <?php esc_html_e( 'deposit', 'doughboss' ); ?></small>
								</td>
								<td><?php echo esc_html( mysql2date( 'M j, g:i a', $row['created_at'] ) ); ?></td>
								<td>
									<select class="db-catering-status" data-enquiry="<?php echo esc_attr( $row['id'] ); ?>">
										<?php foreach ( $statuses as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row['status'], $key ); ?>>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the live Order Board screen. The board app (JS) fills #db-board.
	 *
	 * @return void
	 */
	public function render_board_page() {
		// Primary gate: WP login + the kitchen capability. This is the real
		// security boundary and is never weakened by the optional key below.
		if ( ! current_user_can( 'manage_doughboss_kds' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}

		// Optional secondary gate: if the owner has set a board access key in
		// Settings, this authenticated + capable user must ALSO supply it via
		// ?key= — giving kitchen staff a specific, bookmarkable URL per the
		// owner's request, in addition to (not instead of) the login above.
		$required_key = DoughBoss_Settings::board_access_key();
		$kds_only     = ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' );
		if ( $kds_only && '' !== $required_key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page gate, not a state change; compared with hash_equals() below.
			$supplied_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
			if ( ! DoughBoss_Settings::verify_board_access_key( $supplied_key ) ) {
				wp_die( esc_html__( 'This Order Board link is missing or has an incorrect access key. Ask an owner/manager for the bookmarked board URL from DoughBoss Settings.', 'doughboss' ) );
			}
		}
		?>
		<div class="wrap doughboss-board-wrap">
			<div class="db-board-bar">
				<h1><?php esc_html_e( 'Live Order Board', 'doughboss' ); ?></h1>
				<div class="db-board-actions">
					<span class="db-board-status" role="status" aria-live="polite"></span>
					<button type="button" class="button db-sound-toggle" aria-pressed="false">
						<?php esc_html_e( '🔔 Enable sound alerts', 'doughboss' ); ?>
					</button>
				</div>
			</div>
			<div id="db-board" class="db-board" aria-live="polite">
				<p class="db-board-loading"><?php esc_html_e( 'Loading orders…', 'doughboss' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Shops / Locations management screen (list + add/edit form).
	 *
	 * @return void
	 */
	public function render_locations_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$editing = $edit_id ? DoughBoss_Locations::get( $edit_id ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg     = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';

		$f = function ( $key, $default = '' ) use ( $editing ) {
			return $editing && isset( $editing->$key ) ? $editing->$key : $default;
		};
		?>
		<div class="wrap doughboss-locations">
			<h1><?php esc_html_e( 'Shops / Locations', 'doughboss' ); ?></h1>
			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Shop saved.', 'doughboss' ); ?></p></div>
			<?php elseif ( 'deleted' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Shop deleted.', 'doughboss' ); ?></p></div>
			<?php endif; ?>

			<table class="wp-list-table widefat fixed striped" style="margin-bottom:1.5rem;">
				<thead><tr>
					<th><?php esc_html_e( 'Shop', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Suburb', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Fulfilment', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Active', 'doughboss' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
					<?php $rows = DoughBoss_Locations::all(); ?>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No shops yet. Add your first one below.', 'doughboss' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $loc ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $loc->name ); ?></strong><br /><small><?php echo esc_html( $loc->phone ); ?></small></td>
								<td><?php echo esc_html( $loc->suburb ); ?></td>
								<td>
									<?php
									$ful = array();
									if ( $loc->pickup_enabled ) {
										$ful[] = __( 'Pickup', 'doughboss' );
									}
									if ( $loc->delivery_enabled ) {
										$ful[] = __( 'Delivery', 'doughboss' );
									}
									echo esc_html( $ful ? implode( ' + ', $ful ) : '—' );
									?>
								</td>
								<td><?php echo $loc->is_active ? '✓' : '—'; ?></td>
								<td>
									<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'doughboss-locations', 'edit' => $loc->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'doughboss' ); ?></a> |
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=doughboss_delete_location&id=' . $loc->id ), 'doughboss_delete_location_' . $loc->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this shop?', 'doughboss' ) ); ?>');" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'doughboss' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php echo $editing ? esc_html__( 'Edit shop', 'doughboss' ) : esc_html__( 'Add a shop', 'doughboss' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="doughboss_save_location" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (int) $f( 'id', 0 ) ); ?>" />
				<?php wp_nonce_field( 'doughboss_save_location' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="db-loc-name"><?php esc_html_e( 'Name', 'doughboss' ); ?></label></th>
						<td><input name="name" id="db-loc-name" type="text" class="regular-text" required value="<?php echo esc_attr( $f( 'name' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="db-loc-suburb"><?php esc_html_e( 'Suburb', 'doughboss' ); ?></label></th>
						<td><input name="suburb" id="db-loc-suburb" type="text" class="regular-text" value="<?php echo esc_attr( $f( 'suburb' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="db-loc-address"><?php esc_html_e( 'Address', 'doughboss' ); ?></label></th>
						<td><textarea name="address" id="db-loc-address" class="large-text" rows="2"><?php echo esc_textarea( $f( 'address' ) ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="db-loc-phone"><?php esc_html_e( 'Phone', 'doughboss' ); ?></label></th>
						<td><input name="phone" id="db-loc-phone" type="text" class="regular-text" value="<?php echo esc_attr( $f( 'phone' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="db-loc-postcodes"><?php esc_html_e( 'Delivery postcodes', 'doughboss' ); ?></label></th>
						<td><input name="postcodes" id="db-loc-postcodes" type="text" class="regular-text" value="<?php echo esc_attr( $f( 'postcodes' ) ); ?>" /><p class="description"><?php esc_html_e( 'Comma-separated, used to route delivery orders to this shop.', 'doughboss' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="db-loc-prep"><?php esc_html_e( 'Default prep time (min)', 'doughboss' ); ?></label></th>
						<td><input name="prep_time_default" id="db-loc-prep" type="number" min="0" class="small-text" value="<?php echo esc_attr( $f( 'prep_time_default', 20 ) ); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Fulfilment', 'doughboss' ); ?></th>
						<td>
							<label><input type="checkbox" name="pickup_enabled" value="1" <?php checked( $editing ? $editing->pickup_enabled : 1, 1 ); ?> /> <?php esc_html_e( 'Pickup', 'doughboss' ); ?></label><br />
							<label><input type="checkbox" name="delivery_enabled" value="1" <?php checked( $editing ? $editing->delivery_enabled : 0, 1 ); ?> /> <?php esc_html_e( 'Delivery', 'doughboss' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Active', 'doughboss' ); ?></th>
						<td><label><input type="checkbox" name="is_active" value="1" <?php checked( $editing ? $editing->is_active : 1, 1 ); ?> /> <?php esc_html_e( 'Accept orders for this shop', 'doughboss' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( $editing ? __( 'Update shop', 'doughboss' ) : __( 'Add shop', 'doughboss' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the add/edit shop form submission.
	 *
	 * @return void
	 */
	public function handle_save_location() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_save_location' );

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = array(
			'name'              => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
			'suburb'            => isset( $_POST['suburb'] ) ? wp_unslash( $_POST['suburb'] ) : '',
			'address'           => isset( $_POST['address'] ) ? wp_unslash( $_POST['address'] ) : '',
			'phone'             => isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '',
			'postcodes'         => isset( $_POST['postcodes'] ) ? wp_unslash( $_POST['postcodes'] ) : '',
			'prep_time_default' => isset( $_POST['prep_time_default'] ) ? (int) $_POST['prep_time_default'] : 20,
			'pickup_enabled'    => isset( $_POST['pickup_enabled'] ) ? 1 : 0,
			'delivery_enabled'  => isset( $_POST['delivery_enabled'] ) ? 1 : 0,
			'is_active'         => isset( $_POST['is_active'] ) ? 1 : 0,
		);

		if ( $id ) {
			DoughBoss_Locations::update( $id, $data );
		} else {
			DoughBoss_Locations::create( $data );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'doughboss-locations', 'msg' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle deleting a shop.
	 *
	 * @return void
	 */
	public function handle_delete_location() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_delete_location_' . $id );

		if ( $id ) {
			DoughBoss_Locations::delete( $id );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'doughboss-locations', 'msg' => 'deleted' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the Vouchers management screen: daily-campaign tracking, a create
	 * form, and a recent-vouchers list with redemption status + void.
	 *
	 * @return void
	 */
	public function render_scan_page() {
		if ( ! current_user_can( $this->scan_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}
		?>
		<div class="wrap db-scan-wrap">
			<div id="doughboss-scan-app" class="db-scan" aria-live="polite">
				<noscript><?php esc_html_e( 'The voucher scanner needs JavaScript enabled.', 'doughboss' ); ?></noscript>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the owner Vouchers management screen (create, void, campaign
	 * tracker, full list).
	 *
	 * @return void
	 */
	public function render_vouchers_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg       = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$new_code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$campaigns = DoughBoss_Voucher::campaigns();
		$vouchers  = DoughBoss_Voucher::query( 100 );
		?>
		<div class="wrap doughboss-vouchers">
			<h1><?php esc_html_e( 'Vouchers', 'doughboss' ); ?></h1>
			<?php if ( 'issued' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Voucher created:', 'doughboss' ); ?> <code><?php echo esc_html( $new_code ); ?></code> — <?php esc_html_e( 'reminder: this one does not reach POSPal.', 'doughboss' ); ?></p></div>
			<?php elseif ( 'claimed' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Voucher claimed:', 'doughboss' ); ?> <code><?php echo esc_html( $new_code ); ?></code> — <?php esc_html_e( 'this went through the real claim flow, so it will be granted to POSPal if configured.', 'doughboss' ); ?></p></div>
			<?php elseif ( 'voided' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Voucher voided.', 'doughboss' ); ?></p></div>
			<?php elseif ( 'claim_error' === $msg ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not claim the voucher — the campaign may be inactive or today\'s cap may be reached.', 'doughboss' ); ?></p></div>
			<?php elseif ( 'error' === $msg ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not create the voucher — check the value and try again.', 'doughboss' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Daily campaigns', 'doughboss' ); ?></h2>
			<table class="wp-list-table widefat fixed striped" style="margin-bottom:1.5rem;max-width:820px;">
				<thead><tr>
					<th><?php esc_html_e( 'Campaign', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Value', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Daily cap', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Claimed today', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Remaining', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Active', 'doughboss' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $campaigns as $c ) : ?>
					<?php
					$cap       = (int) ( isset( $c['daily_cap'] ) ? $c['daily_cap'] : 0 );
					$shared    = ! empty( $c['cap_group'] );
					$claimed   = DoughBoss_Voucher::claimed_today( $c['slug'] );
					$pool_used = DoughBoss_Voucher::claimed_today_for( $c );
					$remaining = $cap > 0 ? (string) max( 0, $cap - $pool_used ) : '∞';
					$cap_label = $cap > 0 ? (string) $cap . ( $shared ? ' ' . __( '(shared)', 'doughboss' ) : '' ) : '∞';
					?>
					<tr>
						<td><strong><?php echo esc_html( $c['label'] ); ?></strong><br /><small><?php echo esc_html( $c['slug'] ); ?></small></td>
						<td><?php echo esc_html( 'percent' === $c['type'] ? $c['value'] . '%' : DoughBoss_Settings::format_price( $c['value'] ) ); ?></td>
						<td><?php echo esc_html( $cap_label ); ?></td>
						<td><?php echo esc_html( (string) $claimed ); ?></td>
						<td><?php echo esc_html( $remaining ); ?></td>
						<td><?php echo empty( $c['active'] ) ? '—' : '✓'; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php
				// When campaigns share a daily pool, show the combined total so the
				// "Remaining" columns (which all show the same shared figure) read clearly.
				$groups = array();
				foreach ( $campaigns as $c ) {
					if ( ! empty( $c['cap_group'] ) ) {
						$groups[ $c['cap_group'] ] = (int) ( isset( $c['daily_cap'] ) ? $c['daily_cap'] : 0 );
					}
				}
				foreach ( $groups as $g => $g_cap ) {
					$g_used = DoughBoss_Voucher::claimed_today_for( array( 'cap_group' => $g ) );
					?>
					<tr style="background:#f6f7f7;">
						<td colspan="3"><em><?php printf( esc_html__( 'Shared daily pool (%s)', 'doughboss' ), esc_html( $g ) ); ?></em></td>
						<td><?php echo esc_html( (string) $g_used ); ?></td>
						<td><?php echo esc_html( $g_cap > 0 ? (string) max( 0, $g_cap - $g_used ) : '∞' ); ?></td>
						<td>—</td>
					</tr>
					<?php
				}
				?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Claim a voucher for a customer', 'doughboss' ); ?></h2>
			<p class="description" style="max-width:640px;">
				<?php esc_html_e( 'Use this for a customer standing in front of you (or on the phone) who wants a real campaign voucher — it runs the exact same claim used by the website widget, so it counts against the daily cap and, when POSPal is configured, automatically grants a matching coupon at the till against their phone number.', 'doughboss' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="doughboss_claim_voucher" />
				<?php wp_nonce_field( 'doughboss_claim_voucher' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="db-cv-campaign"><?php esc_html_e( 'Campaign', 'doughboss' ); ?></label></th>
						<td><select name="campaign" id="db-cv-campaign">
							<?php foreach ( $campaigns as $c ) : ?>
								<option value="<?php echo esc_attr( $c['slug'] ); ?>"><?php echo esc_html( $c['label'] . ' (' . ( 'percent' === $c['type'] ? $c['value'] . '%' : DoughBoss_Settings::format_price( $c['value'] ) ) . ')' ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th><label for="db-cv-phone"><?php esc_html_e( 'Customer phone', 'doughboss' ); ?></label></th>
						<td><input name="phone" id="db-cv-phone" type="tel" class="regular-text" required />
							<p class="description"><?php esc_html_e( 'Required — this is the POSPal member key. Without it the voucher still works online but nothing is granted at the till.', 'doughboss' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="db-cv-email"><?php esc_html_e( 'Customer email', 'doughboss' ); ?></label></th>
						<td><input name="email" id="db-cv-email" type="email" class="regular-text" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Claim voucher', 'doughboss' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Create a voucher (manual, one-off)', 'doughboss' ); ?></h2>
			<p class="description" style="max-width:640px;">
				<?php esc_html_e( 'For a custom one-off code (a promotion, a goodwill gesture) outside the daily campaigns. Important: this does NOT reach POSPal — it never grants a till coupon, even with a phone number. It still works fine as an online discount and can still be redeemed in-store via the Scan tool. If you need a code that is already sitting on the POSPal till, use "Claim a voucher for a customer" above instead.', 'doughboss' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="doughboss_issue_voucher" />
				<?php wp_nonce_field( 'doughboss_issue_voucher' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="db-v-type"><?php esc_html_e( 'Type', 'doughboss' ); ?></label></th>
						<td><select name="type" id="db-v-type">
							<option value="amount"><?php esc_html_e( 'Amount off ($)', 'doughboss' ); ?></option>
							<option value="percent"><?php esc_html_e( 'Percent off (%)', 'doughboss' ); ?></option>
						</select></td>
					</tr>
					<tr>
						<th><label for="db-v-value"><?php esc_html_e( 'Value', 'doughboss' ); ?></label></th>
						<td><input name="value" id="db-v-value" type="number" step="0.01" min="0" class="small-text" required /></td>
					</tr>
					<tr>
						<th><label for="db-v-prefix"><?php esc_html_e( 'Code prefix', 'doughboss' ); ?></label></th>
						<td><input name="prefix" id="db-v-prefix" type="text" class="regular-text" value="DOUGH" /></td>
					</tr>
					<tr>
						<th><label for="db-v-min"><?php esc_html_e( 'Minimum spend', 'doughboss' ); ?></label></th>
						<td><input name="min_spend" id="db-v-min" type="number" step="0.01" min="0" class="small-text" value="0" /></td>
					</tr>
					<tr>
						<th><label for="db-v-scope"><?php esc_html_e( 'Where', 'doughboss' ); ?></label></th>
						<td><select name="scope" id="db-v-scope">
							<option value="both"><?php esc_html_e( 'Online & in-store', 'doughboss' ); ?></option>
							<option value="online"><?php esc_html_e( 'Online only', 'doughboss' ); ?></option>
							<option value="instore"><?php esc_html_e( 'In-store only', 'doughboss' ); ?></option>
						</select></td>
					</tr>
					<tr>
						<th><label for="db-v-to"><?php esc_html_e( 'Valid until', 'doughboss' ); ?></label></th>
						<td><input name="valid_to" id="db-v-to" type="date" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Create voucher', 'doughboss' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent vouchers', 'doughboss' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th><?php esc_html_e( 'Code', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Discount', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Campaign', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Status', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Created', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Redeemed', 'doughboss' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $vouchers ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No vouchers yet. Create one above, or they appear here as customers claim them.', 'doughboss' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $vouchers as $v ) : ?>
						<tr>
							<td><code><?php echo esc_html( $v->code ); ?></code></td>
							<td><?php echo esc_html( 'percent' === $v->type ? $v->value . '%' : DoughBoss_Settings::format_price( $v->value ) ); ?></td>
							<td><?php echo esc_html( $v->campaign ? $v->campaign : '—' ); ?></td>
							<td><?php echo esc_html( ucfirst( $v->status ) ); ?></td>
							<td><?php echo esc_html( $v->customer_phone ? $v->customer_phone : ( $v->customer_email ? $v->customer_email : '—' ) ); ?></td>
							<td><?php echo esc_html( mysql2date( 'j M, g:ia', $v->created_at ) ); ?></td>
							<td><?php echo $v->redeemed_at ? esc_html( mysql2date( 'j M, g:ia', $v->redeemed_at ) . ' · ' . $v->redeemed_channel ) : '—'; ?></td>
							<td>
								<?php if ( 'issued' === $v->status ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=doughboss_void_voucher&id=' . $v->id ), 'doughboss_void_voucher_' . $v->id ) ); ?>" style="color:#b32d2e;" onclick="return confirm('<?php echo esc_js( __( 'Void this voucher?', 'doughboss' ) ); ?>');"><?php esc_html_e( 'Void', 'doughboss' ); ?></a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle the create-voucher form submission.
	 *
	 * @return void
	 */
	public function handle_issue_voucher() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_issue_voucher' );

		$result = DoughBoss_Voucher::issue(
			array(
				'type'      => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'amount',
				'value'     => isset( $_POST['value'] ) ? (float) wp_unslash( $_POST['value'] ) : 0,
				'prefix'    => isset( $_POST['prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['prefix'] ) ) : 'DB',
				'min_spend' => isset( $_POST['min_spend'] ) ? (float) wp_unslash( $_POST['min_spend'] ) : 0,
				'scope'     => isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'both',
				'valid_to'  => ( isset( $_POST['valid_to'] ) && '' !== $_POST['valid_to'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_to'] ) ) . ' 23:59:59' : '',
			)
		);

		$args = array( 'page' => 'doughboss-vouchers' );
		if ( is_wp_error( $result ) ) {
			$args['msg'] = 'error';
		} else {
			$args['msg']  = 'issued';
			$args['code'] = $result['code'];
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle claiming a real campaign voucher on a customer's behalf — runs the
	 * same DoughBoss_Voucher::claim() path the storefront widget uses, so it
	 * counts against the daily cap and fires the doughboss_voucher_claimed hook
	 * (which triggers the POSPal grant when configured). Unlike issue() above,
	 * a code from here is a real campaign claim, not a one-off manual code.
	 *
	 * @return void
	 */
	public function handle_claim_voucher() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_claim_voucher' );

		$campaign = isset( $_POST['campaign'] ) ? sanitize_key( wp_unslash( $_POST['campaign'] ) ) : '';
		$phone    = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		$result = DoughBoss_Voucher::claim(
			$campaign,
			array(
				'customer_phone' => $phone,
				'customer_email' => $email,
			)
		);

		$args = array( 'page' => 'doughboss-vouchers' );
		if ( is_wp_error( $result ) ) {
			$args['msg'] = 'claim_error';
		} else {
			$args['msg']  = 'claimed';
			$args['code'] = $result['code'];
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle voiding an unredeemed voucher.
	 *
	 * @return void
	 */
	public function handle_void_voucher() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_void_voucher_' . $id );
		if ( $id ) {
			DoughBoss_Voucher::void( $id );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'doughboss-vouchers', 'msg' => 'voided' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Admin-post handler: import the standard menu (one click) via the seeder.
	 *
	 * @return void
	 */
	public function handle_seed_menu() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_seed_menu' );
		$r   = DoughBoss_Menu_Seeder::seed( false );
		$msg = sprintf(
			/* translators: 1: created count, 2: updated count, 3: categories count. */
			__( 'Menu imported: %1$d created, %2$d updated, %3$d categories.', 'doughboss' ),
			(int) $r['created'],
			(int) $r['updated'],
			(int) $r['categories']
		);
		$base = wp_get_referer();
		if ( ! $base ) {
			$base = admin_url( 'admin.php?page=doughboss-settings' );
		}
		wp_safe_redirect( add_query_arg( 'doughboss_seeded', rawurlencode( $msg ), $base ) );
		exit;
	}

	/**
	 * Admin-post handler: save the customer-facing message templates.
	 *
	 * Deliberately bypasses the Settings API (register_setting/sanitize_settings)
	 * that the main Settings page uses, and instead calls
	 * DoughBoss_Settings::update() directly — a true partial merge onto the
	 * stored option. This page's form only ever contains the four tpl_* fields,
	 * and a true partial merge is the only way to save that without risking
	 * resetting every other setting to its hardcoded fallback (the same class
	 * of bug already fixed once for the main Settings form).
	 *
	 * @return void
	 */
	public function handle_save_templates() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_save_templates' );

		DoughBoss_Settings::update(
			array(
				'tpl_order_email_subject' => isset( $_POST['tpl_order_email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['tpl_order_email_subject'] ) ) : '',
				'tpl_order_email_body'    => isset( $_POST['tpl_order_email_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tpl_order_email_body'] ) ) : '',
				'tpl_sms_ready'           => isset( $_POST['tpl_sms_ready'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tpl_sms_ready'] ) ) : '',
				'tpl_sms_voucher'         => isset( $_POST['tpl_sms_voucher'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tpl_sms_voucher'] ) ) : '',
			)
		);

		wp_safe_redirect( add_query_arg( array( 'page' => 'doughboss-templates', 'msg' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the Message Templates page.
	 *
	 * @return void
	 */
	public function render_templates_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}

		$t = array(
			'tpl_order_email_subject' => DoughBoss_Settings::tpl_order_email_subject(),
			'tpl_order_email_body'    => DoughBoss_Settings::tpl_order_email_body(),
			'tpl_sms_ready'           => DoughBoss_Settings::tpl_sms_ready(),
			'tpl_sms_voucher'         => DoughBoss_Settings::tpl_sms_voucher(),
		);
		?>
		<div class="wrap doughboss-templates">
			<h1><?php esc_html_e( 'Message Templates', 'doughboss' ); ?></h1>
			<p class="description" style="max-width:760px;">
				<?php esc_html_e( 'The exact wording sent to customers by email and SMS. Leave any field blank and save to restore its built-in default text — nothing here can ever be left broken.', 'doughboss' ); ?>
			</p>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash after a nonce-checked redirect.
			if ( isset( $_GET['msg'] ) && 'saved' === $_GET['msg'] ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Message templates saved.', 'doughboss' ) . '</p></div>';
			}
			?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="doughboss_save_templates" />
				<?php wp_nonce_field( 'doughboss_save_templates' ); ?>

				<h2><?php esc_html_e( 'Order confirmation email', 'doughboss' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Sent to the customer (and a copy to your shop inbox) the moment an order is placed.', 'doughboss' ); ?>
					<?php esc_html_e( 'Placeholders:', 'doughboss' ); ?>
					<code>{site_name}</code> <code>{order_number}</code> <code>{customer_name}</code> <code>{items}</code> <code>{total}</code>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="db-tpl-order-subject"><?php esc_html_e( 'Subject', 'doughboss' ); ?></label></th>
						<td><input type="text" id="db-tpl-order-subject" class="large-text" name="tpl_order_email_subject" value="<?php echo esc_attr( $t['tpl_order_email_subject'] ); ?>" placeholder="[{site_name}] Order {order_number} received" /></td>
					</tr>
					<tr>
						<th><label for="db-tpl-order-body"><?php esc_html_e( 'Body', 'doughboss' ); ?></label></th>
						<td><textarea id="db-tpl-order-body" class="large-text" rows="8" name="tpl_order_email_body" placeholder="Hi {customer_name}, thanks for your order {order_number}...&#10;&#10;{items}&#10;&#10;Total: {total}"><?php echo esc_textarea( $t['tpl_order_email_body'] ); ?></textarea></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'SMS messages', 'doughboss' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Only sent when SMS is switched on under Settings → Real-time & Notifications.', 'doughboss' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="db-tpl-sms-ready"><?php esc_html_e( '"Order ready" text', 'doughboss' ); ?></label></th>
						<td><input type="text" id="db-tpl-sms-ready" class="large-text" name="tpl_sms_ready" value="<?php echo esc_attr( $t['tpl_sms_ready'] ); ?>" placeholder="Your DoughBoss order #{order_number} is ready for pickup." />
							<p class="description"><?php esc_html_e( 'Placeholder:', 'doughboss' ); ?> <code>{order_number}</code></p></td>
					</tr>
					<tr>
						<th><label for="db-tpl-sms-voucher"><?php esc_html_e( '"Voucher claimed" text', 'doughboss' ); ?></label></th>
						<td><input type="text" id="db-tpl-sms-voucher" class="large-text" name="tpl_sms_voucher" value="<?php echo esc_attr( $t['tpl_sms_voucher'] ); ?>" placeholder="Your DoughBoss voucher is ready: {code}. Show this code to redeem." />
							<p class="description"><?php esc_html_e( 'Placeholder:', 'doughboss' ); ?> <code>{code}</code></p></td>
					</tr>
				</table>

				<?php submit_button( __( 'Save message templates', 'doughboss' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Resolve the requested report date range from $_GET, defaulting to the
	 * last 7 days. Display-only, so no nonce is required (matching the other
	 * read-only list pages).
	 *
	 * @return array{0:string,1:string} From/to dates (Y-m-d).
	 */
	private function report_range() {
		$to_default   = gmdate( 'Y-m-d' );
		$from_default = gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter form.
		$from = DoughBoss_Reports::sanitize_date( isset( $_GET['from'] ) ? wp_unslash( $_GET['from'] ) : '', $from_default );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter form.
		$to = DoughBoss_Reports::sanitize_date( isset( $_GET['to'] ) ? wp_unslash( $_GET['to'] ) : '', $to_default );

		if ( strtotime( $from ) > strtotime( $to ) ) {
			list( $from, $to ) = array( $to, $from );
		}

		return array( $from, $to );
	}

	/**
	 * Render the Reports page: revenue summary, pickup/delivery split and
	 * top-selling items for a date range, with a CSV export.
	 *
	 * @return void
	 */
	public function render_reports_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}

		list( $from, $to ) = $this->report_range();
		$location = isset( $_GET['location'] ) ? absint( $_GET['location'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter form.

		$summary     = DoughBoss_Reports::summary( $from, $to, $location );
		$mix         = DoughBoss_Reports::order_type_mix( $from, $to, $location );
		$top_items   = DoughBoss_Reports::top_items( $from, $to, 10, $location );
		$payment_mix = DoughBoss_Reports::payment_mix( $from, $to, $location );

		$location_names = $this->location_names();
		$multi_shop     = count( $location_names ) > 1;
		$by_location    = $multi_shop ? DoughBoss_Reports::location_breakdown( $from, $to ) : array();

		$type_labels = array(
			'pickup'   => __( 'Pickup', 'doughboss' ),
			'delivery' => __( 'Delivery', 'doughboss' ),
		);

		$payment_labels = array(
			'paid'     => __( 'Paid by card', 'doughboss' ),
			'unpaid'   => __( 'Unpaid — collected in store', 'doughboss' ),
			'refunded' => __( 'Refunded', 'doughboss' ),
		);
		?>
		<div class="wrap doughboss-reports">
			<h1><?php esc_html_e( 'Reports', 'doughboss' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Sales for the selected period. Cancelled orders are excluded. Gross sales include unpaid (pay-in-store) and refunded orders — see the payments breakdown for money actually collected by card.', 'doughboss' ); ?></p>

			<form method="get" style="margin:12px 0 20px;">
				<input type="hidden" name="page" value="doughboss-reports" />
				<label for="db-report-from"><?php esc_html_e( 'From', 'doughboss' ); ?></label>
				<input type="date" id="db-report-from" name="from" value="<?php echo esc_attr( $from ); ?>" />
				<label for="db-report-to"><?php esc_html_e( 'To', 'doughboss' ); ?></label>
				<input type="date" id="db-report-to" name="to" value="<?php echo esc_attr( $to ); ?>" />
				<?php if ( $multi_shop ) : ?>
					<label for="db-report-location"><?php esc_html_e( 'Shop', 'doughboss' ); ?></label>
					<select id="db-report-location" name="location">
						<option value="0"><?php esc_html_e( 'All shops', 'doughboss' ); ?></option>
						<?php foreach ( $location_names as $loc_id => $loc_name ) : ?>
							<option value="<?php echo esc_attr( $loc_id ); ?>" <?php selected( $location, $loc_id ); ?>>
								<?php echo esc_html( $loc_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<button class="button"><?php esc_html_e( 'Apply', 'doughboss' ); ?></button>
			</form>

			<?php if ( $location > 0 && isset( $location_names[ $location ] ) ) : ?>
				<p><strong>
					<?php
					/* translators: %s: shop/location name. */
					echo esc_html( sprintf( __( 'Showing: %s only.', 'doughboss' ), $location_names[ $location ] ) );
					?>
				</strong></p>
			<?php endif; ?>

			<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
				<div class="card" style="min-width:180px;margin:0;padding:12px 18px;">
					<h2 style="margin:0 0 4px;"><?php esc_html_e( 'Gross sales', 'doughboss' ); ?></h2>
					<p style="font-size:1.8em;margin:0;"><strong><?php echo esc_html( DoughBoss_Settings::format_price( $summary['revenue'] ) ); ?></strong></p>
				</div>
				<div class="card" style="min-width:180px;margin:0;padding:12px 18px;">
					<h2 style="margin:0 0 4px;"><?php esc_html_e( 'Paid by card', 'doughboss' ); ?></h2>
					<p style="font-size:1.8em;margin:0;"><strong><?php echo esc_html( DoughBoss_Settings::format_price( $summary['paid_revenue'] ) ); ?></strong></p>
					<p class="description" style="margin:2px 0 0;">
						<?php
						/* translators: %s: order count. */
						echo esc_html( sprintf( _n( '%s order', '%s orders', $summary['paid_orders'], 'doughboss' ), number_format_i18n( $summary['paid_orders'] ) ) );
						?>
					</p>
				</div>
				<div class="card" style="min-width:180px;margin:0;padding:12px 18px;">
					<h2 style="margin:0 0 4px;"><?php esc_html_e( 'Orders', 'doughboss' ); ?></h2>
					<p style="font-size:1.8em;margin:0;"><strong><?php echo esc_html( number_format_i18n( $summary['orders'] ) ); ?></strong></p>
				</div>
				<div class="card" style="min-width:180px;margin:0;padding:12px 18px;">
					<h2 style="margin:0 0 4px;"><?php esc_html_e( 'Average order value', 'doughboss' ); ?></h2>
					<p style="font-size:1.8em;margin:0;"><strong><?php echo esc_html( DoughBoss_Settings::format_price( $summary['aov'] ) ); ?></strong></p>
				</div>
			</div>

			<h2><?php esc_html_e( 'Payments', 'doughboss' ); ?></h2>
			<table class="widefat striped" style="max-width:560px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Payment status', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'doughboss' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $payment_mix ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No orders in this period.', 'doughboss' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $payment_mix as $pay_status => $stats ) : ?>
							<tr>
								<td><?php echo esc_html( isset( $payment_labels[ $pay_status ] ) ? $payment_labels[ $pay_status ] : ucfirst( $pay_status ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $stats['orders'] ) ); ?></td>
								<td><?php echo esc_html( DoughBoss_Settings::format_price( $stats['revenue'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $multi_shop ) : ?>
				<h2 style="margin-top:24px;"><?php esc_html_e( 'By shop', 'doughboss' ); ?></h2>
				<p class="description"><?php esc_html_e( 'All shops for the selected period, regardless of the shop filter above.', 'doughboss' ); ?></p>
				<table class="widefat striped" style="max-width:560px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Shop', 'doughboss' ); ?></th>
							<th><?php esc_html_e( 'Orders', 'doughboss' ); ?></th>
							<th><?php esc_html_e( 'Gross sales', 'doughboss' ); ?></th>
							<th><?php esc_html_e( 'Paid by card', 'doughboss' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $by_location ) ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'No orders in this period.', 'doughboss' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $by_location as $loc_row ) : ?>
								<tr>
									<td><?php echo esc_html( isset( $location_names[ $loc_row['location_id'] ] ) ? $location_names[ $loc_row['location_id'] ] : __( 'Unassigned', 'doughboss' ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $loc_row['orders'] ) ); ?></td>
									<td><?php echo esc_html( DoughBoss_Settings::format_price( $loc_row['revenue'] ) ); ?></td>
									<td><?php echo esc_html( DoughBoss_Settings::format_price( $loc_row['paid_revenue'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 style="margin-top:24px;"><?php esc_html_e( 'Pickup vs delivery', 'doughboss' ); ?></h2>
			<table class="widefat striped" style="max-width:560px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Type', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Revenue', 'doughboss' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $mix ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No orders in this period.', 'doughboss' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $mix as $type => $stats ) : ?>
							<tr>
								<td><?php echo esc_html( isset( $type_labels[ $type ] ) ? $type_labels[ $type ] : ucfirst( $type ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $stats['orders'] ) ); ?></td>
								<td><?php echo esc_html( DoughBoss_Settings::format_price( $stats['revenue'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:24px;"><?php esc_html_e( 'Top items', 'doughboss' ); ?></h2>
			<table class="widefat striped" style="max-width:560px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Item', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Units sold', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Revenue', 'doughboss' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $top_items ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No items sold in this period.', 'doughboss' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $top_items as $item ) : ?>
							<tr>
								<td><?php echo esc_html( $item['name'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $item['quantity'] ) ); ?></td>
								<td><?php echo esc_html( DoughBoss_Settings::format_price( $item['revenue'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px;">
				<input type="hidden" name="action" value="doughboss_export_report" />
				<?php wp_nonce_field( 'doughboss_export_report' ); ?>
				<input type="hidden" name="from" value="<?php echo esc_attr( $from ); ?>" />
				<input type="hidden" name="to" value="<?php echo esc_attr( $to ); ?>" />
				<input type="hidden" name="location" value="<?php echo esc_attr( $location ); ?>" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Download CSV', 'doughboss' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Neutralise spreadsheet formula injection in a CSV cell.
	 *
	 * Excel/Sheets execute cells starting with =, +, -, @ (or a tab/CR) as
	 * formulas, and customer-supplied values (names, emails) end up in this
	 * export — prefix such cells with a single quote so they render as text.
	 *
	 * @param mixed $value Raw cell value.
	 * @return string
	 */
	private function csv_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Admin-post handler: stream the per-order report as a CSV download for
	 * the posted date range.
	 *
	 * @return void
	 */
	public function handle_export_report() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_export_report' );

		$to_default   = gmdate( 'Y-m-d' );
		$from_default = gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS );
		$from         = DoughBoss_Reports::sanitize_date( isset( $_POST['from'] ) ? wp_unslash( $_POST['from'] ) : '', $from_default );
		$to           = DoughBoss_Reports::sanitize_date( isset( $_POST['to'] ) ? wp_unslash( $_POST['to'] ) : '', $to_default );
		$location     = isset( $_POST['location'] ) ? absint( $_POST['location'] ) : 0;

		$rows           = DoughBoss_Reports::orders_for_export( $from, $to, $location );
		$location_names = $this->location_names();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="doughboss-report-' . $from . '-to-' . $to . '.csv"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV to the browser.
		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array( 'Order #', 'Placed (UTC)', 'Type', 'Status', 'Shop', 'Customer', 'Email', 'Subtotal', 'Tax', 'Delivery fee', 'Discount', 'Voucher', 'Total', 'Currency', 'Payment status' )
		);
		foreach ( $rows as $row ) {
			$row_loc = isset( $row->location_id ) ? (int) $row->location_id : 0;
			fputcsv(
				$out,
				array(
					$this->csv_cell( $row->order_number ),
					$this->csv_cell( $row->created_at ),
					$this->csv_cell( $row->order_type ),
					$this->csv_cell( $row->status ),
					$this->csv_cell( isset( $location_names[ $row_loc ] ) ? $location_names[ $row_loc ] : '' ),
					$this->csv_cell( $row->customer_name ),
					$this->csv_cell( $row->customer_email ),
					number_format( (float) $row->subtotal, 2, '.', '' ),
					number_format( (float) $row->tax, 2, '.', '' ),
					number_format( (float) $row->delivery_fee, 2, '.', '' ),
					number_format( (float) $row->discount, 2, '.', '' ),
					$this->csv_cell( $row->voucher_code ),
					number_format( (float) $row->total, 2, '.', '' ),
					$this->csv_cell( $row->currency ),
					$this->csv_cell( $row->payment_status ),
				)
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}

	/**
	 * Render the Settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}

		$settings = DoughBoss_Settings::all();
		?>
		<div class="wrap doughboss-settings">
			<h1><?php esc_html_e( 'DoughBoss Settings', 'doughboss' ); ?></h1>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash after a nonce-checked redirect.
			if ( isset( $_GET['doughboss_seeded'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['doughboss_seeded'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			$db_item_count = (int) wp_count_posts( DoughBoss_Post_Types::POST_TYPE )->publish;
			?>
			<div class="card" style="max-width:680px;padding:6px 18px 14px;margin:14px 0 22px;">
				<h2><?php esc_html_e( 'Menu', 'doughboss' ); ?></h2>
				<p class="description"><?php
					/* translators: %d: number of published menu items. */
					echo esc_html( sprintf( __( 'There are currently %d published menu item(s). Import the standard Dough Boss menu — Manoush, Pizza, Pies, Wraps, Desserts, Drinks (27 items, with prices, categories and dietary flags). Safe to re-run: matching items are updated, never duplicated.', 'doughboss' ), $db_item_count ) );
				?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="doughboss_seed_menu" />
					<?php wp_nonce_field( 'doughboss_seed_menu' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import standard menu', 'doughboss' ); ?></button>
				</form>
			</div>
			<form method="post" action="options.php">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>
				<?php $opt = DoughBoss_Settings::OPTION_KEY; ?>

				<h2><?php esc_html_e( 'Store', 'doughboss' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="db-ordering-open"><?php esc_html_e( 'Accept orders', 'doughboss' ); ?></label></th>
						<td><input type="checkbox" id="db-ordering-open" name="<?php echo esc_attr( $opt ); ?>[ordering_open]" value="1" <?php checked( $settings['ordering_open'], 1 ); ?> />
							<span class="description"><?php esc_html_e( 'Uncheck to temporarily pause online ordering.', 'doughboss' ); ?></span></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Fulfilment', 'doughboss' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enable_pickup]" value="1" <?php checked( $settings['enable_pickup'], 1 ); ?> /> <?php esc_html_e( 'Pickup', 'doughboss' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enable_delivery]" value="1" <?php checked( $settings['enable_delivery'], 1 ); ?> /> <?php esc_html_e( 'Delivery', 'doughboss' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><label for="db-single-location-mode"><?php esc_html_e( 'Single-location launch', 'doughboss' ); ?></label></th>
						<td>
							<label><input type="checkbox" id="db-single-location-mode" name="<?php echo esc_attr( $opt ); ?>[single_location_mode]" value="1" <?php checked( ! empty( $settings['single_location_mode'] ), true ); ?> <?php disabled( 1 !== count( DoughBoss_Locations::all( true ) ) ); ?> /> <?php esc_html_e( 'Pin ordering to the sole active shop and allow pickup only', 'doughboss' ); ?></label>
							<p class="description"><?php esc_html_e( 'Available only when exactly one shop is active. This prevents accidental routing to the wrong shop on multi-location sites.', 'doughboss' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="db-currency-symbol"><?php esc_html_e( 'Currency symbol', 'doughboss' ); ?></label></th>
						<td><input type="text" id="db-currency-symbol" class="small-text" name="<?php echo esc_attr( $opt ); ?>[currency_symbol]" value="<?php echo esc_attr( $settings['currency_symbol'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="db-currency-code"><?php esc_html_e( 'Currency code', 'doughboss' ); ?></label></th>
						<td><input type="text" id="db-currency-code" class="small-text" name="<?php echo esc_attr( $opt ); ?>[currency_code]" value="<?php echo esc_attr( $settings['currency_code'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="db-tax-rate"><?php esc_html_e( 'Tax / GST rate (%)', 'doughboss' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" id="db-tax-rate" class="small-text" name="<?php echo esc_attr( $opt ); ?>[tax_rate]" value="<?php echo esc_attr( $settings['tax_rate'] ); ?>" />
							<span class="description"><?php esc_html_e( 'Australian GST is 10%.', 'doughboss' ); ?></span></td>
					</tr>
					<tr>
						<th><label for="db-gst-inclusive"><?php esc_html_e( 'Prices include GST', 'doughboss' ); ?></label></th>
						<td><input type="checkbox" id="db-gst-inclusive" name="<?php echo esc_attr( $opt ); ?>[gst_inclusive]" value="1" <?php checked( ! empty( $settings['gst_inclusive'] ), true ); ?> />
							<span class="description"><?php esc_html_e( 'Tick for Australia: menu prices already include GST (tax is shown as a component, not added on top).', 'doughboss' ); ?></span></td>
					</tr>
					<tr>
						<th><label for="db-delivery-fee"><?php esc_html_e( 'Delivery fee', 'doughboss' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" id="db-delivery-fee" class="small-text" name="<?php echo esc_attr( $opt ); ?>[delivery_fee]" value="<?php echo esc_attr( $settings['delivery_fee'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="db-orders-email"><?php esc_html_e( 'Order notification email', 'doughboss' ); ?></label></th>
						<td><input type="email" id="db-orders-email" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[orders_email]" value="<?php echo esc_attr( isset( $settings['orders_email'] ) ? $settings['orders_email'] : '' ); ?>" />
							<span class="description"><?php esc_html_e( 'Where new order and catering enquiry emails are sent. Leave blank to use the site admin email.', 'doughboss' ); ?></span></td>
					</tr>
					<tr>
						<th><label for="db-staff-session"><?php esc_html_e( 'Staff session (days)', 'doughboss' ); ?></label></th>
						<td><input type="number" min="0" step="1" id="db-staff-session" class="small-text" name="<?php echo esc_attr( $opt ); ?>[staff_session_days]" value="<?php echo esc_attr( isset( $settings['staff_session_days'] ) ? $settings['staff_session_days'] : 0 ); ?>" />
							<span class="description"><?php esc_html_e( 'Keep logged-in users signed in for this many days (0 = WordPress default ~2 days). Set high, e.g. 3650, so shop tablets stay signed in and never time out.', 'doughboss' ); ?></span></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Order Board access key', 'doughboss' ); ?></th>
						<td>
							<?php $has_board_key = '' !== trim( (string) ( isset( $settings['board_access_key'] ) ? $settings['board_access_key'] : '' ) ); ?>
							<p>
								<?php if ( $has_board_key ) : ?>
									<span class="description"><strong><?php esc_html_e( 'A key is set.', 'doughboss' ); ?></strong> <?php esc_html_e( "The key itself isn't shown again here for security — generate a new link below if you need it again.", 'doughboss' ); ?></span>
								<?php else : ?>
									<span class="description"><?php esc_html_e( 'No key set — the board is reachable at the normal address for anyone who signs in with kitchen access.', 'doughboss' ); ?></span>
								<?php endif; ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Optional. KDS-only kitchen staff still sign in with their own WordPress username and password — this does not replace that. When set, it adds a specific bookmarkable link and protects the order feed/actions: kitchen accounts must also have the matching key, while owner/manager accounts retain their broader wp-admin access. The link is shown once after generation and only a verifier is stored.', 'doughboss' ); ?>
							</p>
							<p>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=doughboss_generate_board_key' ), 'doughboss_generate_board_key' ) ); ?>"><?php echo $has_board_key ? esc_html__( 'Generate new link (invalidates the old one)', 'doughboss' ) : esc_html__( 'Generate a board link', 'doughboss' ); ?></a>
								<?php if ( $has_board_key ) : ?>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=doughboss_clear_board_key' ), 'doughboss_clear_board_key' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Turn off the board link key? The board will go back to being reachable by login + capability only.', 'doughboss' ) ); ?>');"><?php esc_html_e( 'Turn off', 'doughboss' ); ?></a>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Pizza Sizes', 'doughboss' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The base price of a plain pizza of each size. Used by the custom pizza builder.', 'doughboss' ); ?></p>
				<?php $this->render_repeater( 'sizes', $settings['sizes'], $opt ); ?>

				<h2><?php esc_html_e( 'Toppings', 'doughboss' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Each topping and the price added when selected in the builder.', 'doughboss' ); ?></p>
				<?php $this->render_repeater( 'toppings', $settings['toppings'], $opt ); ?>

				<h2><?php esc_html_e( 'Payments', 'doughboss' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Optional. Take card payments at checkout via Stripe or Tyro — pick one active gateway below. Off by default — start in Test/Sandbox mode with your test keys, then switch to Live. Card payments apply only once payments are on AND the active gateway is fully configured for its mode.', 'doughboss' ); ?>
						<?php
						if ( ! class_exists( 'DoughBoss_Payment' ) || ! DoughBoss_Payment::ready() ) {
							echo ' <strong>' . esc_html__( 'Status: card payments are OFF.', 'doughboss' ) . '</strong>';
						} else {
							/* translators: 1: gateway name (Stripe or Tyro), 2: mode (Test/Sandbox or Live). */
							echo ' <strong style="color:#1f8a54;">' . esc_html(
								sprintf(
									__( 'Status: card payments are ON via %1$s (%2$s mode).', 'doughboss' ),
									DoughBoss_Payment::gateway_label(),
									'tyro' === DoughBoss_Settings::payment_gateway()
										? ( DoughBoss_Settings::tyro_mode() === 'live' ? __( 'Live', 'doughboss' ) : __( 'Sandbox', 'doughboss' ) )
										: ( DoughBoss_Settings::stripe_mode() === 'live' ? __( 'Live', 'doughboss' ) : __( 'Test', 'doughboss' ) )
								)
							) . '</strong>';
						}
						?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="db-payments-enabled"><?php esc_html_e( 'Accept card payments', 'doughboss' ); ?></label></th>
							<td><input type="checkbox" id="db-payments-enabled" name="<?php echo esc_attr( $opt ); ?>[payments_enabled]" value="1" <?php checked( ! empty( $settings['payments_enabled'] ), true ); ?> />
								<span class="description"><?php esc_html_e( 'When on (and the active gateway is fully configured), customers pay by card before the order is placed.', 'doughboss' ); ?></span></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Active gateway', 'doughboss' ); ?></th>
							<td>
								<?php $gateway = isset( $settings['payment_gateway'] ) && 'tyro' === $settings['payment_gateway'] ? 'tyro' : 'stripe'; ?>
								<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[payment_gateway]" value="stripe" <?php checked( 'stripe' === $gateway, true ); ?> /> <?php esc_html_e( 'Stripe', 'doughboss' ); ?></label>&nbsp;&nbsp;
								<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[payment_gateway]" value="tyro" <?php checked( 'tyro' === $gateway, true ); ?> /> <?php esc_html_e( 'Tyro', 'doughboss' ); ?></label>
								<p class="description"><?php esc_html_e( 'Existing paid orders always refund correctly against whichever gateway actually processed them, even after you switch this.', 'doughboss' ); ?></p>
							</td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'Stripe', 'doughboss' ); ?></h3>
					<?php $mode = isset( $settings['stripe_mode'] ) && 'live' === $settings['stripe_mode'] ? 'live' : 'test'; ?>
					<table class="form-table" role="presentation">
						<tr>
							<th><?php esc_html_e( 'Mode', 'doughboss' ); ?></th>
							<td>
								<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[stripe_mode]" value="test" <?php checked( 'test' === $mode, true ); ?> /> <?php esc_html_e( 'Test', 'doughboss' ); ?></label>&nbsp;&nbsp;
								<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[stripe_mode]" value="live" <?php checked( 'live' === $mode, true ); ?> /> <?php esc_html_e( 'Live', 'doughboss' ); ?></label>
							</td>
						</tr>
						<tr>
							<th><label for="db-stripe-test-pk"><?php esc_html_e( 'Test publishable key', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-stripe-test-pk" class="regular-text" autocomplete="off" placeholder="pk_test_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_test_pk]" value="<?php echo esc_attr( isset( $settings['stripe_test_pk'] ) ? $settings['stripe_test_pk'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-stripe-test-sk"><?php esc_html_e( 'Test secret key', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-stripe-test-sk" class="regular-text" autocomplete="off" placeholder="sk_test_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_test_sk]" value="" />
								<p class="description"><?php esc_html_e( 'For best security set it as the DOUGHBOSS_STRIPE_TEST_SK environment variable instead of here; this field is a fallback.', 'doughboss' ); ?> <?php echo isset( $settings['stripe_test_sk'] ) && '' !== $settings['stripe_test_sk'] ? esc_html__( 'A key is set. Leave blank to keep it.', 'doughboss' ) : esc_html__( 'Leave blank to keep the current value.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-stripe-live-pk"><?php esc_html_e( 'Live publishable key', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-stripe-live-pk" class="regular-text" autocomplete="off" placeholder="pk_live_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_live_pk]" value="<?php echo esc_attr( isset( $settings['stripe_live_pk'] ) ? $settings['stripe_live_pk'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-stripe-live-sk"><?php esc_html_e( 'Live secret key', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-stripe-live-sk" class="regular-text" autocomplete="off" placeholder="sk_live_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_live_sk]" value="" />
								<p class="description"><?php esc_html_e( 'Find your keys in the Stripe Dashboard → Developers → API keys. Secret keys are used only on the server.', 'doughboss' ); ?> <?php esc_html_e( 'For best security set it as the DOUGHBOSS_STRIPE_LIVE_SK environment variable instead of here; this field is a fallback.', 'doughboss' ); ?> <?php echo isset( $settings['stripe_live_sk'] ) && '' !== $settings['stripe_live_sk'] ? esc_html__( 'A key is set — leave blank to keep it.', 'doughboss' ) : esc_html__( 'Leave blank to keep the current value.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-stripe-test-whsec"><?php esc_html_e( 'Test webhook secret', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-stripe-test-whsec" class="regular-text" autocomplete="off" placeholder="whsec_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_test_whsec]" value="" />
								<p class="description"><?php esc_html_e( 'For best security set it as the DOUGHBOSS_STRIPE_TEST_WHSEC environment variable instead of here; this field is a fallback.', 'doughboss' ); ?> <?php echo isset( $settings['stripe_test_whsec'] ) && '' !== $settings['stripe_test_whsec'] ? esc_html__( 'A secret is set. Leave blank to keep it.', 'doughboss' ) : esc_html__( 'Leave blank to keep the current value.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-stripe-live-whsec"><?php esc_html_e( 'Live webhook secret', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-stripe-live-whsec" class="regular-text" autocomplete="off" placeholder="whsec_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_live_whsec]" value="" />
								<p class="description">
									<?php esc_html_e( 'Stripe Dashboard → Developers → Webhooks. Add one endpoint pointing to:', 'doughboss' ); ?>
									<code><?php echo esc_html( rest_url( DOUGHBOSS_REST_NAMESPACE . '/stripe-webhook' ) ); ?></code>
									<?php esc_html_e( 'and subscribe it to payment_intent.succeeded, then paste its signing secret here. It covers both storefront orders (flagging any successful payment that never became an order) and catering deposits. The older catering-only endpoint', 'doughboss' ); ?>
									<code><?php echo esc_html( rest_url( DOUGHBOSS_REST_NAMESPACE . '/catering/stripe-webhook' ) ); ?></code>
									<?php esc_html_e( 'still works if already registered — Stripe issues one signing secret per endpoint and this plugin stores a single secret, so register only one of them.', 'doughboss' ); ?>
									<?php esc_html_e( 'For best security set it as the DOUGHBOSS_STRIPE_LIVE_WHSEC environment variable instead; this field is a fallback.', 'doughboss' ); ?>
									<?php echo isset( $settings['stripe_live_whsec'] ) && '' !== $settings['stripe_live_whsec'] ? esc_html__( 'A secret is set — leave blank to keep it.', 'doughboss' ) : esc_html__( 'Leave blank to keep the current value.', 'doughboss' ); ?>
								</p></td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'Tyro', 'doughboss' ); ?></h3>
					<?php $tyro_mode = isset( $settings['tyro_mode'] ) && 'live' === $settings['tyro_mode'] ? 'live' : 'test'; ?>
					<table class="form-table" role="presentation">
						<tr>
							<th><?php esc_html_e( 'Mode', 'doughboss' ); ?></th>
							<td>
								<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[tyro_mode]" value="test" <?php checked( 'test' === $tyro_mode, true ); ?> /> <?php esc_html_e( 'Sandbox', 'doughboss' ); ?></label>&nbsp;&nbsp;
								<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[tyro_mode]" value="live" <?php checked( 'live' === $tyro_mode, true ); ?> /> <?php esc_html_e( 'Live', 'doughboss' ); ?></label>
							</td>
						</tr>
						<tr>
							<th><label for="db-tyro-merchant-id"><?php esc_html_e( 'Merchant ID', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-tyro-merchant-id" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[tyro_merchant_id]" value="<?php echo esc_attr( isset( $settings['tyro_merchant_id'] ) ? $settings['tyro_merchant_id'] : '' ); ?>" />
								<p class="description"><?php esc_html_e( 'From your Tyro / Mastercard Payment Gateway Services merchant portal. Used for both sandbox and live — the Mode above selects which password is used against it.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-tyro-host"><?php esc_html_e( 'API host (optional)', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-tyro-host" class="regular-text" autocomplete="off" placeholder="https://tyro.gateway.mastercard.com" name="<?php echo esc_attr( $opt ); ?>[tyro_host]" value="<?php echo esc_attr( isset( $settings['tyro_host'] ) ? $settings['tyro_host'] : '' ); ?>" />
								<p class="description"><?php esc_html_e( 'Leave blank to use the default gateway host shown above.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-tyro-api-version"><?php esc_html_e( 'API version (optional)', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-tyro-api-version" class="small-text" autocomplete="off" placeholder="74" name="<?php echo esc_attr( $opt ); ?>[tyro_api_version]" value="<?php echo esc_attr( isset( $settings['tyro_api_version'] ) ? $settings['tyro_api_version'] : '' ); ?>" />
								<p class="description"><?php esc_html_e( 'Leave blank to use the default API version shown above.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-tyro-test-password"><?php esc_html_e( 'Sandbox integration password', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-tyro-test-password" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[tyro_test_password]" value="" />
								<p class="description"><?php esc_html_e( 'For best security set it as the DOUGHBOSS_TYRO_TEST_PASSWORD environment variable instead of here; this field is a fallback.', 'doughboss' ); ?> <?php echo isset( $settings['tyro_test_password'] ) && '' !== $settings['tyro_test_password'] ? esc_html__( 'A password is set. Leave blank to keep it.', 'doughboss' ) : esc_html__( 'Leave blank to keep the current value.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-tyro-live-password"><?php esc_html_e( 'Live integration password', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-tyro-live-password" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[tyro_live_password]" value="" />
								<p class="description"><?php esc_html_e( 'For best security set it as the DOUGHBOSS_TYRO_LIVE_PASSWORD environment variable instead of here; this field is a fallback.', 'doughboss' ); ?> <?php echo isset( $settings['tyro_live_password'] ) && '' !== $settings['tyro_live_password'] ? esc_html__( 'A password is set — leave blank to keep it.', 'doughboss' ) : esc_html__( 'Leave blank to keep the current value.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-tyro-test-whsec"><?php esc_html_e( 'Sandbox webhook secret', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-tyro-test-whsec" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[tyro_test_webhook_secret]" value="" />
								<p class="description">
									<?php esc_html_e( 'Register a webhook in your Tyro sandbox account pointing to:', 'doughboss' ); ?>
									<code><?php echo esc_html( rest_url( DOUGHBOSS_REST_NAMESPACE . '/tyro-webhook' ) ); ?></code>
									<?php esc_html_e( 'Catering deposits use:', 'doughboss' ); ?>
									<code><?php echo esc_html( rest_url( DOUGHBOSS_REST_NAMESPACE . '/catering/tyro-webhook' ) ); ?></code>
									<?php esc_html_e( 'then paste its signing secret here. For best security set it as the DOUGHBOSS_TYRO_TEST_WHSEC environment variable instead; this field is a fallback.', 'doughboss' ); ?>
									<?php echo isset( $settings['tyro_test_webhook_secret'] ) && '' !== $settings['tyro_test_webhook_secret'] ? esc_html__( 'A secret is set. Leave blank to keep it.', 'doughboss' ) : esc_html__( 'Leave blank to keep the current value.', 'doughboss' ); ?>
								</p></td>
						</tr>
						<tr>
							<th><label for="db-tyro-live-whsec"><?php esc_html_e( 'Live webhook secret', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-tyro-live-whsec" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[tyro_live_webhook_secret]" value="" />
								<p class="description">
									<?php esc_html_e( 'Same endpoints as above, registered against your live Tyro account. For best security set it as the DOUGHBOSS_TYRO_LIVE_WHSEC environment variable instead; this field is a fallback.', 'doughboss' ); ?>
									<?php echo isset( $settings['tyro_live_webhook_secret'] ) && '' !== $settings['tyro_live_webhook_secret'] ? esc_html__( 'A secret is set — leave blank to keep it.', 'doughboss' ) : esc_html__( 'Leave blank to keep the current value.', 'doughboss' ); ?>
								</p></td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'POSPal POS (in-store coupons)', 'doughboss' ); ?></h2>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Connect the Revesby POSPal till so issued vouchers can be mirrored as member coupons and reconciled. Off by default. Enter the area host, App ID and App Key from your POSPal Open Platform account, save, then test the connection.', 'doughboss' ); ?>
						<?php
						if ( class_exists( 'DoughBoss_POSPal' ) && DoughBoss_POSPal::ready() ) {
							echo ' <strong style="color:#1f8a54;">' . esc_html__( 'Status: POSPal is configured and enabled.', 'doughboss' ) . '</strong>';
						} else {
							echo ' <strong style="color:#a15c00;">' . esc_html__( 'Status: not connected yet.', 'doughboss' ) . '</strong>';
						}
						?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable POSPal', 'doughboss' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[pospal_enabled]" value="1" <?php checked( ! empty( $settings['pospal_enabled'] ), true ); ?> /> <?php esc_html_e( 'Connect this site to POSPal', 'doughboss' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="db-pospal-host"><?php esc_html_e( 'Area host', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-pospal-host" class="regular-text" autocomplete="off" placeholder="https://area28-win.pospal.cn:443" name="<?php echo esc_attr( $opt ); ?>[pospal_host]" value="<?php echo esc_attr( isset( $settings['pospal_host'] ) ? $settings['pospal_host'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-pospal-app-id"><?php esc_html_e( 'App ID', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-pospal-app-id" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[pospal_app_id]" value="<?php echo esc_attr( isset( $settings['pospal_app_id'] ) ? $settings['pospal_app_id'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-pospal-app-key"><?php esc_html_e( 'App Key', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-pospal-app-key" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[pospal_app_key]" value="" />
								<p class="description"><?php esc_html_e( 'The secret key is used only to sign server-side calls. For best security set it as the DOUGHBOSS_POSPAL_APPKEY environment variable instead of here; this field is a fallback.', 'doughboss' ); ?> <?php echo isset( $settings['pospal_app_key'] ) && '' !== $settings['pospal_app_key'] ? esc_html__( 'A key is set — leave blank to keep it.', 'doughboss' ) : esc_html__( 'Leave blank to keep the current value.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-pospal-uid5"><?php esc_html_e( '$5 coupon rule UID', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-pospal-uid5" class="regular-text" autocomplete="off" inputmode="numeric" placeholder="e.g. 1782386149561969589" name="<?php echo esc_attr( $opt ); ?>[pospal_coupon_uid_5]" value="<?php echo esc_attr( isset( $settings['pospal_coupon_uid_5'] ) ? $settings['pospal_coupon_uid_5'] : '' ); ?>" />
								<p class="description"><?php esc_html_e( 'The POSPal coupon-rule UID for the $5 student voucher (copied from your POSPal coupon list). Granting stays off until this is set.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Verify coupons', 'doughboss' ); ?></th>
							<td>
								<select id="db-pospal-store" style="margin-right:6px;">
									<option value="1"><?php esc_html_e( 'Store 1', 'doughboss' ); ?></option>
									<option value="2"><?php esc_html_e( 'Store 2', 'doughboss' ); ?></option>
									<option value="3"><?php esc_html_e( 'Store 3', 'doughboss' ); ?></option>
								</select>
								<button type="button" class="button" id="db-pospal-verify"><?php esc_html_e( 'Verify coupon setup', 'doughboss' ); ?></button>
								<span id="db-pospal-verify-result" class="description" style="margin-left:8px;"></span>
								<p class="description"><?php esc_html_e( 'Read-only: checks the selected store\'s POSPal connection and that its UIDs match real coupon rules. This store dropdown also applies to the Test grant below. Save your changes first.', 'doughboss' ); ?></p>
							</td>
						</tr>
						<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : // Dev-only diagnostics — the matching REST routes are only registered under WP_DEBUG. ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Test grant', 'doughboss' ); ?></th>
							<td>
								<input type="text" id="db-pospal-test-phone" class="regular-text" inputmode="tel" autocomplete="off" style="max-width:240px;" placeholder="<?php esc_attr_e( 'Throwaway test phone, e.g. 0400000000', 'doughboss' ); ?>" />
								<select id="db-pospal-test-value">
									<option value="5"><?php esc_html_e( '$5 coupon', 'doughboss' ); ?></option>
								</select>
								<button type="button" class="button" id="db-pospal-test-grant"><?php esc_html_e( 'Send test coupon', 'doughboss' ); ?></button>
								<button type="button" class="button" id="db-pospal-probe"><?php esc_html_e( 'Probe methods', 'doughboss' ); ?></button>
								<button type="button" class="button" id="db-pospal-test-revoke" style="display:none;"><?php esc_html_e( 'Revoke test', 'doughboss' ); ?></button>
								<p id="db-pospal-test-result" class="description" style="margin-top:8px; white-space:pre-wrap; word-break:break-word;"></p>
								<p class="description"><?php esc_html_e( 'Writes a test member + grants the mapped coupon in POSPal and shows the raw response — use a throwaway phone, then Revoke. This is how the exact coupon-grant method is confirmed.', 'doughboss' ); ?></p>
							</td>
						</tr>
						<?php endif; ?>
					</table>

					<h3><?php esc_html_e( 'Additional stores (multi-store)', 'doughboss' ); ?></h3>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Optional. Add Bankstown and Roselands here — each is a separate POSPal account with its own host, App ID, App Key and $5 coupon-rule UID. A claimed voucher is granted to EVERY configured store. Leave a store blank to skip it. After saving, use the store dropdown above (Store 2 / Store 3) to Verify and Test each one.', 'doughboss' ); ?>
					</p>
					<?php foreach ( array( 2, 3 ) as $sn ) : ?>
						<h4>
							<?php
							/* translators: %d: store number. */
							printf( esc_html__( 'Store %d', 'doughboss' ), (int) $sn );
							?>
						</h4>
						<table class="form-table" role="presentation">
							<tr>
								<th><?php esc_html_e( 'Label', 'doughboss' ); ?></th>
								<td><input type="text" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( 2 === $sn ? 'Bankstown' : 'Roselands' ); ?>" name="<?php echo esc_attr( $opt ); ?>[pospal<?php echo (int) $sn; ?>_label]" value="<?php echo esc_attr( isset( $settings[ 'pospal' . $sn . '_label' ] ) ? $settings[ 'pospal' . $sn . '_label' ] : '' ); ?>" /></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Area host', 'doughboss' ); ?></th>
								<td><input type="text" class="regular-text" autocomplete="off" placeholder="https://areaXX-win.pospal.cn:443" name="<?php echo esc_attr( $opt ); ?>[pospal<?php echo (int) $sn; ?>_host]" value="<?php echo esc_attr( isset( $settings[ 'pospal' . $sn . '_host' ] ) ? $settings[ 'pospal' . $sn . '_host' ] : '' ); ?>" /></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'App ID', 'doughboss' ); ?></th>
								<td><input type="text" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[pospal<?php echo (int) $sn; ?>_app_id]" value="<?php echo esc_attr( isset( $settings[ 'pospal' . $sn . '_app_id' ] ) ? $settings[ 'pospal' . $sn . '_app_id' ] : '' ); ?>" /></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'App Key', 'doughboss' ); ?></th>
								<td><input type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[pospal<?php echo (int) $sn; ?>_app_key]" value="" />
									<p class="description">
										<?php
										/* translators: %d: store number. */
										printf( esc_html__( 'For best security set the DOUGHBOSS_POSPAL_APPKEY_%d environment variable instead; this field is a fallback.', 'doughboss' ), (int) $sn );
										echo ' ';
										echo ( isset( $settings[ 'pospal' . $sn . '_app_key' ] ) && '' !== $settings[ 'pospal' . $sn . '_app_key' ] ) ? esc_html__( 'A key is set — leave blank to keep it.', 'doughboss' ) : esc_html__( 'Leave blank to keep the current value.', 'doughboss' );
										?>
									</p></td>
							</tr>
							<tr>
								<th><?php esc_html_e( '$5 coupon rule UID', 'doughboss' ); ?></th>
								<td><input type="text" class="regular-text" autocomplete="off" inputmode="numeric" name="<?php echo esc_attr( $opt ); ?>[pospal<?php echo (int) $sn; ?>_coupon_uid_5]" value="<?php echo esc_attr( isset( $settings[ 'pospal' . $sn . '_coupon_uid_5' ] ) ? $settings[ 'pospal' . $sn . '_coupon_uid_5' ] : '' ); ?>" /></td>
							</tr>
						</table>
					<?php endforeach; ?>

					<h3><?php esc_html_e( 'Product mapping (for order push)', 'doughboss' ); ?></h3>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Only needed if you switch on "Push online orders" above. Each menu item below needs a matching POSPal product before an order containing it can reach the till — an unmapped item is safely skipped from the push rather than sent broken, but it won\'t show up on the till until it\'s mapped here.', 'doughboss' ); ?>
					</p>
					<?php
					$map_items = get_posts(
						array(
							'post_type'      => DoughBoss_Post_Types::POST_TYPE,
							'post_status'    => 'publish',
							'posts_per_page' => -1,
							'orderby'        => 'title',
							'order'          => 'ASC',
						)
					);
					$saved_map = isset( $settings['pospal_product_map'] ) && is_array( $settings['pospal_product_map'] ) ? $settings['pospal_product_map'] : array();
					?>
					<p>
						<select id="db-pospal-map-store" style="margin-right:6px;">
							<option value="1"><?php esc_html_e( 'Store 1', 'doughboss' ); ?></option>
							<option value="2"><?php esc_html_e( 'Store 2', 'doughboss' ); ?></option>
							<option value="3"><?php esc_html_e( 'Store 3', 'doughboss' ); ?></option>
						</select>
						<button type="button" class="button" id="db-pospal-map-load"><?php esc_html_e( 'Load POSPal products &amp; auto-match', 'doughboss' ); ?></button>
						<button type="button" class="button button-primary" id="db-pospal-map-save"><?php esc_html_e( 'Save mapping', 'doughboss' ); ?></button>
						<span id="db-pospal-map-result" class="description" style="margin-left:8px;"></span>
					</p>
					<?php if ( empty( $map_items ) ) : ?>
						<p class="description"><?php esc_html_e( 'No published menu items yet — import the standard menu above first.', 'doughboss' ); ?></p>
					<?php else : ?>
						<table class="wp-list-table widefat striped" style="max-width:760px;">
							<thead><tr>
								<th><?php esc_html_e( 'Menu item', 'doughboss' ); ?></th>
								<th><?php esc_html_e( 'POSPal product', 'doughboss' ); ?></th>
							</tr></thead>
							<tbody id="db-pospal-map-body">
								<?php foreach ( $map_items as $mi ) :
									$key         = DoughBoss_POSPal_Orders::norm( $mi->post_title );
									$current_uid = isset( $saved_map[ $key ] ) ? (string) $saved_map[ $key ] : '';
									?>
									<tr>
										<td><?php echo esc_html( $mi->post_title ); ?></td>
										<td>
											<select class="db-pospal-map-select" data-key="<?php echo esc_attr( $key ); ?>" data-current="<?php echo esc_attr( $current_uid ); ?>" style="min-width:280px;">
												<option value=""><?php esc_html_e( '— not mapped —', 'doughboss' ); ?></option>
												<?php if ( '' !== $current_uid ) : ?>
													<option value="<?php echo esc_attr( $current_uid ); ?>" selected="selected"><?php echo esc_html( sprintf( /* translators: %s: POSPal product uid. */ __( 'Currently mapped (uid %s) — load products to see its name', 'doughboss' ), $current_uid ) ); ?></option>
												<?php endif; ?>
											</select>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p class="description"><?php esc_html_e( '"Load POSPal products" fetches your catalogue and auto-selects the closest name match for every item below — review each one, adjust anything wrong, then Save mapping. Nothing is saved until you click Save.', 'doughboss' ); ?></p>
					<?php endif; ?>

					<h2><?php esc_html_e( 'Real-time &amp; Notifications', 'doughboss' ); ?></h2>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Optional push, SMS and printing channels. Each group is off by default and stays fully dormant until you enable it and supply its settings. Secret fields (the JWT, token and API key below) are write-only: they show blank and are kept as-is unless you type a new value.', 'doughboss' ); ?>
					</p>

					<h3><?php esc_html_e( 'Mercure (real-time order board)', 'doughboss' ); ?></h3>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Stream live order updates over a Mercure hub instead of polling. For best security set the publish JWT as the DOUGHBOSS_MERCURE_PUBLISH_JWT environment variable; the field below is a fallback.', 'doughboss' ); ?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable Mercure', 'doughboss' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[mercure_enabled]" value="1" <?php checked( ! empty( $settings['mercure_enabled'] ), true ); ?> /> <?php esc_html_e( 'Publish real-time updates to a Mercure hub', 'doughboss' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="db-mercure-hub"><?php esc_html_e( 'Hub URL', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-mercure-hub" class="regular-text" autocomplete="off" placeholder="https://hub.example.com/.well-known/mercure" name="<?php echo esc_attr( $opt ); ?>[mercure_hub_url]" value="<?php echo esc_attr( isset( $settings['mercure_hub_url'] ) ? $settings['mercure_hub_url'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-mercure-pub-jwt"><?php esc_html_e( 'Publish JWT', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-mercure-pub-jwt" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[mercure_publish_jwt]" value="" />
								<p class="description"><?php esc_html_e( 'Server-side publisher credential. Leave blank to keep the current value.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-mercure-sub-jwt"><?php esc_html_e( 'Subscribe JWT', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-mercure-sub-jwt" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[mercure_subscribe_jwt]" value="" />
								<p class="description"><?php esc_html_e( 'Optional subscriber token handed to the order board. Leave blank to keep the current value.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-mercure-topic"><?php esc_html_e( 'Topic prefix', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-mercure-topic" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[mercure_topic_prefix]" value="<?php echo esc_attr( isset( $settings['mercure_topic_prefix'] ) ? $settings['mercure_topic_prefix'] : 'doughboss' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Connection', 'doughboss' ); ?></th>
							<td>
								<button type="button" class="button" id="db-mercure-test"><?php esc_html_e( 'Test connection', 'doughboss' ); ?></button>
								<span id="db-mercure-test-result" class="description" style="margin-left:8px;"></span>
								<p class="description"><?php esc_html_e( 'Sends a test publish to the hub and reports whether the JWT was accepted. Save your changes first — the test uses the stored hub URL and publish JWT.', 'doughboss' ); ?></p>
							</td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'ntfy (staff push alerts)', 'doughboss' ); ?></h3>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Push a notification to staff phones via ntfy when a new order arrives. For best security set the token as the DOUGHBOSS_NTFY_TOKEN environment variable; the field below is a fallback.', 'doughboss' ); ?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable ntfy', 'doughboss' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[ntfy_enabled]" value="1" <?php checked( ! empty( $settings['ntfy_enabled'] ), true ); ?> /> <?php esc_html_e( 'Send push notifications via ntfy', 'doughboss' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="db-ntfy-server"><?php esc_html_e( 'Server', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-ntfy-server" class="regular-text" autocomplete="off" placeholder="https://ntfy.sh" name="<?php echo esc_attr( $opt ); ?>[ntfy_server]" value="<?php echo esc_attr( isset( $settings['ntfy_server'] ) ? $settings['ntfy_server'] : 'https://ntfy.sh' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-ntfy-topic"><?php esc_html_e( 'Topic', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-ntfy-topic" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[ntfy_topic]" value="<?php echo esc_attr( isset( $settings['ntfy_topic'] ) ? $settings['ntfy_topic'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-ntfy-token"><?php esc_html_e( 'Access token', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-ntfy-token" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[ntfy_token]" value="" />
								<p class="description"><?php esc_html_e( 'Only needed for protected topics. Leave blank to keep the current value.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-ntfy-priority"><?php esc_html_e( 'Priority', 'doughboss' ); ?></label></th>
							<td>
								<?php $ntfy_priority = isset( $settings['ntfy_priority'] ) ? $settings['ntfy_priority'] : 'high'; ?>
								<select id="db-ntfy-priority" name="<?php echo esc_attr( $opt ); ?>[ntfy_priority]">
									<option value="high" <?php selected( 'high', $ntfy_priority ); ?>><?php esc_html_e( 'High', 'doughboss' ); ?></option>
									<option value="default" <?php selected( 'default', $ntfy_priority ); ?>><?php esc_html_e( 'Default', 'doughboss' ); ?></option>
									<option value="low" <?php selected( 'low', $ntfy_priority ); ?>><?php esc_html_e( 'Low', 'doughboss' ); ?></option>
								</select>
							</td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'SMS (ClickSend)', 'doughboss' ); ?></h3>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Text customers when their order is ready (and optionally their voucher code on claim) via ClickSend. For best security set the API key as the DOUGHBOSS_CLICKSEND_API_KEY environment variable; the field below is a fallback.', 'doughboss' ); ?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable SMS', 'doughboss' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[sms_enabled]" value="1" <?php checked( ! empty( $settings['sms_enabled'] ), true ); ?> /> <?php esc_html_e( 'Send SMS via ClickSend', 'doughboss' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="db-clicksend-username"><?php esc_html_e( 'Username', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-clicksend-username" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[clicksend_username]" value="<?php echo esc_attr( isset( $settings['clicksend_username'] ) ? $settings['clicksend_username'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-clicksend-api-key"><?php esc_html_e( 'API key', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-clicksend-api-key" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[clicksend_api_key]" value="" />
								<p class="description"><?php esc_html_e( 'Server-side credential. Leave blank to keep the current value.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-sms-from"><?php esc_html_e( 'From (sender ID)', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-sms-from" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[sms_from]" value="<?php echo esc_attr( isset( $settings['sms_from'] ) ? $settings['sms_from'] : '' ); ?>" />
								<p class="description"><?php esc_html_e( 'A registered alphanumeric sender ID or number. Leave blank to use the ClickSend default.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'When to text', 'doughboss' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[sms_on_ready]" value="1" <?php checked( ! empty( $settings['sms_on_ready'] ), true ); ?> /> <?php esc_html_e( 'SMS the customer when their order is ready', 'doughboss' ); ?></label><br />
								<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[sms_on_voucher_claim]" value="1" <?php checked( ! empty( $settings['sms_on_voucher_claim'] ), true ); ?> /> <?php esc_html_e( 'SMS the voucher code to the customer when a voucher is claimed', 'doughboss' ); ?></label>
							</td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'Receipt printer', 'doughboss' ); ?></h3>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Print kitchen/customer dockets to a network receipt printer. For best security set the shared token as the DOUGHBOSS_PRINTER_TOKEN environment variable; the field below is a fallback.', 'doughboss' ); ?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable printing', 'doughboss' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[printer_enabled]" value="1" <?php checked( ! empty( $settings['printer_enabled'] ), true ); ?> /> <?php esc_html_e( 'Print receipts for new orders', 'doughboss' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="db-printer-protocol"><?php esc_html_e( 'Protocol', 'doughboss' ); ?></label></th>
							<td>
								<?php $printer_protocol = isset( $settings['printer_protocol'] ) && 'epos' === $settings['printer_protocol'] ? 'epos' : 'cloudprnt'; ?>
								<select id="db-printer-protocol" name="<?php echo esc_attr( $opt ); ?>[printer_protocol]">
									<option value="cloudprnt" <?php selected( 'cloudprnt', $printer_protocol ); ?>><?php esc_html_e( 'Star CloudPRNT', 'doughboss' ); ?></option>
									<option value="epos" <?php selected( 'epos', $printer_protocol ); ?>><?php esc_html_e( 'Epson ePOS', 'doughboss' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="db-printer-token"><?php esc_html_e( 'Shared token', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-printer-token" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[printer_token]" value="" />
								<p class="description"><?php esc_html_e( 'Used to authenticate the printer/poll exchange. Leave blank to keep the current value.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-printer-width"><?php esc_html_e( 'Receipt width (chars)', 'doughboss' ); ?></label></th>
							<td><input type="number" min="1" step="1" id="db-printer-width" class="small-text" name="<?php echo esc_attr( $opt ); ?>[printer_width]" value="<?php echo esc_attr( isset( $settings['printer_width'] ) ? $settings['printer_width'] : 48 ); ?>" />
								<p class="description"><?php esc_html_e( 'Characters per line: 48 for an 80mm roll, 32 for 58mm.', 'doughboss' ); ?></p></td>
						</tr>
					</table>

					<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a repeatable label/price table for sizes or toppings.
	 *
	 * @param string $field    Field key ('sizes' or 'toppings').
	 * @param array  $rows     Existing rows.
	 * @param string $opt_name Option name.
	 * @return void
	 */
	private function render_repeater( $field, $rows, $opt_name ) {
		$rows    = ! empty( $rows ) ? $rows : array( array( 'label' => '', 'price' => '' ) );
		$table_id = 'db-repeater-' . $field;
		?>
		<table class="widefat db-repeater" id="<?php echo esc_attr( $table_id ); ?>" style="max-width:560px;">
			<thead><tr>
				<th><?php esc_html_e( 'Label', 'doughboss' ); ?></th>
				<th style="width:120px;"><?php esc_html_e( 'Price', 'doughboss' ); ?></th>
				<th style="width:40px;"></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $rows as $i => $row ) : ?>
					<tr>
						<td><input type="text" name="<?php echo esc_attr( $opt_name . '[' . $field . '][' . $i . '][label]' ); ?>" value="<?php echo esc_attr( isset( $row['label'] ) ? $row['label'] : '' ); ?>" style="width:100%;" /></td>
						<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $opt_name . '[' . $field . '][' . $i . '][price]' ); ?>" value="<?php echo esc_attr( isset( $row['price'] ) ? $row['price'] : '' ); ?>" style="width:100%;" /></td>
						<td><button class="button-link db-remove-row" aria-label="<?php esc_attr_e( 'Remove row', 'doughboss' ); ?>">✕</button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button class="button db-add-row" data-target="<?php echo esc_attr( $table_id ); ?>"><?php esc_html_e( '+ Add row', 'doughboss' ); ?></button></p>
		<?php
	}
}
