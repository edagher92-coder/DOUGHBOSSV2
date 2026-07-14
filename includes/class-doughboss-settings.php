<?php
/**
 * Settings access helpers.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the doughboss_settings option.
 *
 * Centralises reads so the rest of the plugin never has to know the option
 * shape, and provides typed getters with sane fallbacks.
 */
class DoughBoss_Settings {

	const OPTION_KEY = 'doughboss_settings';

	/**
	 * Return the full settings array merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Get a single setting by key.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is absent.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Merge a partial array of settings into the stored option (preserving the
	 * keys not supplied) and persist. Used by programmatic config writers such
	 * as the POSPal connect endpoint.
	 *
	 * @param array $partial Keys to set/overwrite.
	 * @return array The merged settings now stored.
	 */
	public static function update( array $partial ) {
		$current = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$merged = array_merge( $current, $partial );
		update_option( self::OPTION_KEY, $merged );
		return $merged;
	}

	/**
	 * Default settings used when nothing is stored yet.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'currency_symbol' => '$',
			'currency_code'   => 'AUD',
			'tax_rate'        => 10,
			'gst_inclusive'   => 1,
			'delivery_fee'    => 0,
			'enable_pickup'   => 1,
			'enable_delivery' => 0,
			'ordering_open'   => 1,
			// Single-shop mode: hides the shop picker + delivery toggle on the
			// storefront and pins every order to the one active location. Set
			// automatically by the 1.10.0 migration when the site has ≤ 1 active
			// shop, and manually by the owner when they want to lock ordering to a
			// single location for a period (e.g. "Revesby-only pickup for now").
			// Flip to 0 to re-enable multi-shop ordering.
			'single_location_mode' => 1,
			// Shop inbox: where order + catering notifications are emailed. Blank falls
			// back to the WordPress admin email (see orders_email()). Defaults to the
			// Dough Boss orders inbox so the shop is notified out of the box.
			'orders_email'    => 'orders@doughboss.com.au',
			// Keep logged-in sessions for this many days (0 = WordPress default).
			// Set high (e.g. 3650) so shop tablets stay signed in; off by default.
			'staff_session_days' => 0,
			// Kitchen Order Board — optional extra access-key layer. Blank (default)
			// means the board is reachable at the normal wp-admin URL, gated only by
			// login + the manage_doughboss_kds capability (the real security
			// boundary). When set, render_board_page() ALSO requires a matching
			// ?key= query arg — a memorable, bookmarkable "specific URL" for
			// kitchen staff, layered on top of (never instead of) the WP login +
			// capability check. Only ever written by the random generator in
			// DoughBoss_Admin::generate_board_key() (admin-post actions
			// doughboss_generate_board_key / doughboss_clear_board_key) — never
			// accepted as free text. New keys are stored only as SHA-256 verifiers;
			// the raw value exists in the one-time owner reveal and staff URL.
			// See admin/class-doughboss-admin.php render_board_page().
			'board_access_key' => '',
			// Rate-limiter client-IP resolution. Off by default so REMOTE_ADDR is used
			// verbatim (zero behaviour change). Only enable 'behind_reverse_proxy' when
			// the site sits behind a reverse proxy/CDN/load balancer that you have
			// confirmed strips or overwrites any client-supplied forwarded header
			// before appending its own — otherwise a caller could spoof the header and
			// evade the checkout/voucher rate limiter. 'trusted_proxy_header' names the
			// header the proxy sets (its first comma-separated entry is the client IP).
			// See DoughBoss_REST_Controller::client_ip().
			'behind_reverse_proxy' => 0,
			'trusted_proxy_header' => 'X-Forwarded-For',
			'sizes'           => array(),
			'toppings'        => array(),
			// Payments (Stripe) — off by default; keys added later.
			'payments_enabled' => 0,
			'stripe_mode'      => 'test',
			'stripe_test_pk'   => '',
			'stripe_test_sk'   => '',
			'stripe_live_pk'   => '',
			'stripe_live_sk'   => '',
			'stripe_test_whsec' => '',
			'stripe_live_whsec' => '',
			// POSPal POS (Open Platform) — off by default; Revesby store for the pilot.
			// The secret appKey is read env-first (DOUGHBOSS_POSPAL_APPKEY constant/env);
			// this option is only a fallback and is best left blank where env is set.
			'pospal_enabled'    => 0,
			'pospal_host'       => '',
			'pospal_app_id'     => '',
			'pospal_app_key'    => '',
			// POSPal coupon-rule mapping: which POSPal coupon (优惠券) rule UID
			// represents the $5 student voucher. Blank = grant disabled (the GRANT
			// leg is dormant until this is set). The $10 tier has been retired.
			'pospal_coupon_uid_5'  => '',
			// Additional POSPal stores (multi-store). Store 2 + store 3 each carry their
			// own host / App ID / App Key (env-first DOUGHBOSS_POSPAL_APPKEY_2/_3) and
			// $5 coupon-rule UID. Blank = that store is skipped; store 1 is the
			// legacy single-store fields above.
			'pospal2_label'         => '',
			'pospal2_host'          => '',
			'pospal2_app_id'        => '',
			'pospal2_app_key'       => '',
			'pospal2_coupon_uid_5'  => '',
			'pospal3_label'         => '',
			'pospal3_host'          => '',
			'pospal3_app_id'        => '',
			'pospal3_app_key'       => '',
			'pospal3_coupon_uid_5'  => '',
			// POSPal order push (mirror online orders onto the till) — off by default.
			// Orders only push once a product map is built (pospal_product_map, via
			// `wp doughboss pospal-map`). pay_method/pay_online describe how a Stripe-
			// paid order is represented on the POS.
			'pospal_push_orders'          => 0,
			'pospal_order_pay_method'     => 'Cash',
			'pospal_order_pay_method_code' => '',
			'pospal_order_pay_online'     => 0,
			'pospal_product_map'          => array(),
			// Standalone staff console (separate origin, e.g. GitHub Pages) allowed
			// to call the doughboss/v1 routes cross-origin via Application Password.
			'app_origin'        => 'https://edagher92-coder.github.io',
			// Real-time push (Mercure hub) — off by default. The publish JWT is a
			// secret, read env-first (DOUGHBOSS_MERCURE_PUBLISH_JWT); this option is
			// only a fallback and is best left blank where env is set.
			'mercure_enabled'       => 0,
			'mercure_hub_url'       => '',
			'mercure_publish_jwt'   => '',
			'mercure_subscribe_jwt' => '',
			'mercure_topic_prefix'  => 'doughboss',
			// ntfy push notifications — off by default. The bearer token is a secret,
			// read env-first (DOUGHBOSS_NTFY_TOKEN); this option is only a fallback.
			'ntfy_enabled'  => 0,
			'ntfy_server'   => 'https://ntfy.sh',
			'ntfy_topic'    => '',
			'ntfy_token'    => '',
			'ntfy_priority' => 'high',
			// SMS (ClickSend) — off by default. The API key is a secret, read
			// env-first (DOUGHBOSS_CLICKSEND_API_KEY); this option is only a fallback.
			'sms_enabled'           => 0,
			'clicksend_username'    => '',
			'clicksend_api_key'     => '',
			'sms_from'              => '',
			'sms_on_ready'          => 1,
			'sms_on_voucher_claim'  => 0,
			// Receipt printer (CloudPRNT / ePOS) — off by default. The shared token
			// is a secret, read env-first (DOUGHBOSS_PRINTER_TOKEN); this option is
			// only a fallback.
			'printer_enabled'  => 0,
			'printer_protocol' => 'cloudprnt',
			'printer_token'    => '',
			'printer_width'    => 48,
			// Customer-facing message templates — owner-editable copy for the
			// order-confirmation email and the two SMS messages. Blank means
			// "use the built-in default text" (see the tpl_*() getters below),
			// so leaving a field blank restores the default rather than sending
			// an empty message.
			'tpl_order_email_subject' => '',
			'tpl_order_email_body'    => '',
			'tpl_sms_ready'           => '',
			'tpl_sms_voucher'         => '',
		);
	}

	/**
	 * Allowed origin for the standalone staff console (CORS). Empty disables
	 * cross-origin access. Filterable via 'doughboss_app_origin'.
	 *
	 * @return string
	 */
	public static function app_origin() {
		return untrailingslashit( (string) apply_filters( 'doughboss_app_origin', self::get( 'app_origin', '' ) ) );
	}

	/**
	 * Optional extra access-key verifier for the Order Board. Blank (default)
	 * means the board relies solely on WP login + the manage_doughboss_kds
	 * capability. When set, render_board_page() requires a matching ?key=
	 * query argument in addition to that login + capability check — a
	 * bookmarkable "specific URL" for kitchen staff, layered on top of the
	 * real auth boundary and enforced again on KDS REST calls. New values are
	 * SHA-256 verifiers rather than recoverable plaintext.
	 *
	 * @return string
	 */
	public static function board_access_key() {
		return trim( (string) self::get( 'board_access_key', '' ) );
	}

	/**
	 * Verify a presented Order Board key against the stored verifier.
	 *
	 * New keys are stored as SHA-256 verifiers so database/config backups cannot
	 * reveal the bookmark secret. A 24-character legacy plaintext value is still
	 * accepted for a safe upgrade path and is replaced the next time the owner
	 * generates a key.
	 *
	 * @param string $supplied Raw key supplied by the staff client.
	 * @return bool
	 */
	public static function verify_board_access_key( $supplied ) {
		$stored   = self::board_access_key();
		$supplied = trim( (string) $supplied );
		if ( '' === $stored ) {
			return true;
		}
		if ( '' === $supplied ) {
			return false;
		}
		if ( 64 === strlen( $stored ) && ctype_xdigit( $stored ) ) {
			return hash_equals( strtolower( $stored ), hash( 'sha256', $supplied ) );
		}
		return hash_equals( $stored, $supplied );
	}

	/**
	 * Whether prices already include tax (GST-inclusive, the Australian norm).
	 *
	 * @return bool
	 */
	public static function gst_inclusive() {
		return (bool) self::get( 'gst_inclusive', 1 );
	}

	/**
	 * Email address that order + catering notifications are sent to (the shop inbox).
	 * Falls back to the WordPress admin email when unset or invalid. Filterable via
	 * 'doughboss_orders_email'.
	 *
	 * @return string
	 */
	public static function orders_email() {
		$email = sanitize_email( (string) self::get( 'orders_email', '' ) );
		if ( ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
		}
		return (string) apply_filters( 'doughboss_orders_email', $email );
	}

	/**
	 * Configured pizza sizes.
	 *
	 * @return array[] List of array{slug:string,label:string,price:float}.
	 */
	public static function sizes() {
		$sizes = self::get( 'sizes', array() );
		return is_array( $sizes ) ? array_values( $sizes ) : array();
	}

	/**
	 * Configured toppings.
	 *
	 * @return array[] List of array{slug:string,label:string,price:float}.
	 */
	public static function toppings() {
		$toppings = self::get( 'toppings', array() );
		return is_array( $toppings ) ? array_values( $toppings ) : array();
	}

	/**
	 * Look up a single size definition by slug.
	 *
	 * @param string $slug Size slug.
	 * @return array|null
	 */
	public static function find_size( $slug ) {
		foreach ( self::sizes() as $size ) {
			if ( isset( $size['slug'] ) && $size['slug'] === $slug ) {
				return $size;
			}
		}
		return null;
	}

	/**
	 * Look up a single topping definition by slug.
	 *
	 * @param string $slug Topping slug.
	 * @return array|null
	 */
	public static function find_topping( $slug ) {
		foreach ( self::toppings() as $topping ) {
			if ( isset( $topping['slug'] ) && $topping['slug'] === $slug ) {
				return $topping;
			}
		}
		return null;
	}

	/**
	 * Tax rate as a fraction (e.g. 8.25% -> 0.0825).
	 *
	 * @return float
	 */
	public static function tax_fraction() {
		return (float) self::get( 'tax_rate', 0 ) / 100;
	}

	/**
	 * Is online ordering currently accepting orders?
	 *
	 * @return bool
	 */
	public static function ordering_open() {
		return (bool) self::get( 'ordering_open', 1 );
	}

	/**
	 * Whether the site sits behind a trusted reverse proxy/CDN, so the rate
	 * limiter should read the client IP from a forwarded header instead of
	 * REMOTE_ADDR. Off by default. See the note in defaults() and
	 * DoughBoss_REST_Controller::client_ip() for the trust assumption.
	 *
	 * @return bool
	 */
	public static function behind_reverse_proxy() {
		return (bool) self::get( 'behind_reverse_proxy', 0 );
	}

	/**
	 * The forwarded header the trusted proxy sets the real client IP in (e.g.
	 * 'X-Forwarded-For'). Only consulted when behind_reverse_proxy() is true.
	 *
	 * @return string
	 */
	public static function trusted_proxy_header() {
		$header = trim( (string) self::get( 'trusted_proxy_header', 'X-Forwarded-For' ) );
		return '' !== $header ? $header : 'X-Forwarded-For';
	}

	/**
	 * Format a numeric amount for display using the configured symbol.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function format_price( $amount ) {
		$symbol = self::get( 'currency_symbol', '$' );
		return $symbol . number_format( (float) $amount, 2 );
	}

	/**
	 * Whether online card payments are switched on by the operator.
	 *
	 * @return bool
	 */
	public static function payments_enabled() {
		return (bool) self::get( 'payments_enabled', 0 );
	}

	/**
	 * Active Stripe mode: 'test' or 'live'.
	 *
	 * @return string
	 */
	public static function stripe_mode() {
		return 'live' === self::get( 'stripe_mode', 'test' ) ? 'live' : 'test';
	}

	/**
	 * Stripe publishable key for the active mode.
	 *
	 * @return string
	 */
	public static function stripe_publishable_key() {
		return (string) self::get( 'live' === self::stripe_mode() ? 'stripe_live_pk' : 'stripe_test_pk', '' );
	}

	/**
	 * Env-first read for a secret: a wp-config.php constant or environment
	 * variable of the given name overrides the stored option, so the secret can
	 * be kept out of the database (and therefore out of backups). Mirrors the
	 * pattern already used for POSPal/Mercure/ntfy/ClickSend/printer secrets.
	 *
	 * @param string $const_name Constant/env var name (e.g. DOUGHBOSS_STRIPE_TEST_SK).
	 * @param string $option_key Fallback option key.
	 * @return string
	 */
	private static function env_first_secret( $const_name, $option_key ) {
		if ( defined( $const_name ) && '' !== (string) constant( $const_name ) ) {
			return (string) constant( $const_name );
		}
		$env = getenv( $const_name );
		if ( false !== $env && '' !== $env ) {
			return (string) $env;
		}
		return (string) self::get( $option_key, '' );
	}

	/**
	 * Stripe secret key for the active mode. Read env-first — the constant
	 * DOUGHBOSS_STRIPE_TEST_SK/DOUGHBOSS_STRIPE_LIVE_SK or the matching
	 * environment variable take precedence over the stored option. Only ever
	 * used server-side; never echoed to a client.
	 *
	 * @return string
	 */
	public static function stripe_secret_key() {
		return 'live' === self::stripe_mode()
			? self::env_first_secret( 'DOUGHBOSS_STRIPE_LIVE_SK', 'stripe_live_sk' )
			: self::env_first_secret( 'DOUGHBOSS_STRIPE_TEST_SK', 'stripe_test_sk' );
	}

	/**
	 * Stripe webhook signing secret for the active mode (server-side only).
	 * Read env-first — the constant DOUGHBOSS_STRIPE_TEST_WHSEC/
	 * DOUGHBOSS_STRIPE_LIVE_WHSEC or the matching environment variable take
	 * precedence over the stored option.
	 *
	 * @return string
	 */
	public static function stripe_webhook_secret() {
		return 'live' === self::stripe_mode()
			? self::env_first_secret( 'DOUGHBOSS_STRIPE_LIVE_WHSEC', 'stripe_live_whsec' )
			: self::env_first_secret( 'DOUGHBOSS_STRIPE_TEST_WHSEC', 'stripe_test_whsec' );
	}

	/**
	 * Whether Stripe is both enabled and fully configured for the active mode
	 * (so the storefront should actually collect card payment).
	 *
	 * @return bool
	 */
	public static function stripe_ready() {
		return self::payments_enabled() && '' !== self::stripe_publishable_key() && '' !== self::stripe_secret_key();
	}

	/**
	 * Whether the POSPal POS integration is switched on by the operator.
	 *
	 * @return bool
	 */
	public static function pospal_enabled() {
		return (bool) self::get( 'pospal_enabled', 0 );
	}

	/**
	 * POSPal area host, trailing slash removed (e.g. https://area28-win.pospal.cn:443).
	 *
	 * @return string
	 */
	public static function pospal_host() {
		return untrailingslashit( (string) self::get( 'pospal_host', '' ) );
	}

	/**
	 * POSPal public application id.
	 *
	 * @return string
	 */
	public static function pospal_app_id() {
		return (string) self::get( 'pospal_app_id', '' );
	}

	/**
	 * POSPal secret application key. Read env-first — the constant
	 * DOUGHBOSS_POSPAL_APPKEY or the matching environment variable take
	 * precedence over the stored option, so the secret can be kept out of the
	 * database (and therefore out of backups). Only ever used server-side to
	 * sign requests; never echoed to a client.
	 *
	 * @return string
	 */
	public static function pospal_app_key() {
		if ( defined( 'DOUGHBOSS_POSPAL_APPKEY' ) && '' !== (string) DOUGHBOSS_POSPAL_APPKEY ) {
			return (string) DOUGHBOSS_POSPAL_APPKEY;
		}
		$env = getenv( 'DOUGHBOSS_POSPAL_APPKEY' );
		if ( false !== $env && '' !== $env ) {
			return (string) $env;
		}
		return (string) self::get( 'pospal_app_key', '' );
	}

	/**
	 * Whether POSPal is both enabled and fully configured (host + appId + appKey).
	 *
	 * @return bool
	 */
	public static function pospal_ready() {
		return self::pospal_enabled() && '' !== self::pospal_host() && '' !== self::pospal_app_id() && '' !== self::pospal_app_key();
	}

	/**
	 * POSPal coupon-rule UID mapped to the $5 voucher (blank when unmapped).
	 *
	 * @return string
	 */
	public static function pospal_coupon_uid_5() {
		return (string) self::get( 'pospal_coupon_uid_5', '' );
	}

	/**
	 * Map a dollar voucher value to the configured POSPal coupon-rule UID.
	 *
	 * Only the pilot's $5 student voucher is mapped today (the $10 tier was
	 * retired); any other value returns '' (no rule), which the grant flow
	 * treats as "skip — nothing to grant in POSPal".
	 *
	 * @param int|float|string $value Voucher dollar value (e.g. 5, '5.00').
	 * @return string The mapped coupon-rule UID, or '' when none is configured.
	 */
	public static function pospal_coupon_uid_for( $value ) {
		$dollars = (int) round( (float) $value );
		switch ( $dollars ) {
			case 5:
				return self::pospal_coupon_uid_5();
			default:
				return '';
		}
	}

	/**
	 * Whether the POSPal coupon-GRANT leg should run: POSPal is fully configured
	 * AND at least one coupon-rule UID is mapped. When false the whole grant/revoke
	 * sync stays dormant and voucher claims behave exactly as before.
	 *
	 * @return bool
	 */
	public static function pospal_grant_enabled() {
		if ( ! self::pospal_enabled() ) {
			return false;
		}
		foreach ( self::pospal_stores() as $store ) {
			if ( '' !== $store['uid5'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether mirroring online orders onto the POSPal till is switched on.
	 *
	 * @return bool
	 */
	public static function pospal_push_orders() {
		return (bool) self::get( 'pospal_push_orders', 0 );
	}

	/**
	 * Whether the order-push leg is live: POSPal on AND order push on.
	 *
	 * @return bool
	 */
	public static function pospal_push_enabled() {
		return self::pospal_enabled() && self::pospal_push_orders();
	}

	/**
	 * Pay method recorded on pushed POS orders (Cash / Wxpay / Alipay / a custom name).
	 *
	 * @return string
	 */
	public static function pospal_order_pay_method() {
		$m = trim( (string) self::get( 'pospal_order_pay_method', 'Cash' ) );
		return '' !== $m ? $m : 'Cash';
	}

	/**
	 * Custom pay-method code (originalCode), required when pay method is a custom one.
	 *
	 * @return string
	 */
	public static function pospal_order_pay_method_code() {
		return trim( (string) self::get( 'pospal_order_pay_method_code', '' ) );
	}

	/**
	 * Whether a Stripe-paid order should be marked paid online (payOnLine=1) on the POS.
	 *
	 * @return bool
	 */
	public static function pospal_order_pay_online() {
		return (bool) self::get( 'pospal_order_pay_online', 0 );
	}

	/**
	 * Map of normalised menu-item name => POSPal product uid, used to translate order
	 * lines into POSPal products. Built with `wp doughboss pospal-map`.
	 *
	 * @return array<string,int|string>
	 */
	public static function pospal_product_map() {
		$map = self::get( 'pospal_product_map', array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Env-first App Key for an additional POSPal store (store 2, 3, …). Mirrors
	 * pospal_app_key(): a DOUGHBOSS_POSPAL_APPKEY_<n> constant or env var overrides
	 * the stored option so secrets can be kept out of the database.
	 *
	 * @param int $n Store number (2, 3, …).
	 * @return string
	 */
	public static function pospal_store_key( $n ) {
		$n     = (int) $n;
		$const = 'DOUGHBOSS_POSPAL_APPKEY_' . $n;
		if ( defined( $const ) && '' !== (string) constant( $const ) ) {
			return (string) constant( $const );
		}
		$env = getenv( $const );
		if ( false !== $env && '' !== $env ) {
			return (string) $env;
		}
		return (string) self::get( 'pospal' . $n . '_app_key', '' );
	}

	/**
	 * The configured POSPal stores for multi-store grants. Store 1 is the legacy
	 * single-store fields (kept first for backward-compat); stores 2 and 3 come from
	 * the pospal2_* / pospal3_* settings. Only fully-configured stores (host + App ID
	 * + App Key all set) are returned, so an empty store is skipped and nothing breaks.
	 *
	 * @return array[] List of { label, host, app_id, app_key, uid5 }.
	 */
	public static function pospal_stores() {
		$raw = array(
			array(
				'label'   => (string) self::get( 'pospal_label', '' ),
				'host'    => self::pospal_host(),
				'app_id'  => self::pospal_app_id(),
				'app_key' => self::pospal_app_key(),
				'uid5'    => self::pospal_coupon_uid_5(),
				'default' => __( 'Store 1', 'doughboss' ),
			),
		);
		foreach ( array( 2, 3 ) as $n ) {
			$raw[] = array(
				'label'   => (string) self::get( 'pospal' . $n . '_label', '' ),
				'host'    => untrailingslashit( (string) self::get( 'pospal' . $n . '_host', '' ) ),
				'app_id'  => (string) self::get( 'pospal' . $n . '_app_id', '' ),
				'app_key' => self::pospal_store_key( $n ),
				'uid5'    => (string) self::get( 'pospal' . $n . '_coupon_uid_5', '' ),
				/* translators: %d: store number. */
				'default' => sprintf( __( 'Store %d', 'doughboss' ), $n ),
			);
		}

		$stores = array();
		foreach ( $raw as $s ) {
			if ( '' === $s['host'] || '' === $s['app_id'] || '' === $s['app_key'] ) {
				continue; // Skip incompletely-configured stores.
			}
			$s['label'] = '' !== $s['label'] ? $s['label'] : $s['default'];
			unset( $s['default'] );
			$stores[] = $s;
		}
		return $stores;
	}

	/**
	 * A single POSPal store's config by number (1 = legacy/primary, 2, 3) regardless
	 * of whether it is fully configured — used by the per-store admin Verify/Test
	 * tools so an incomplete store reports clearly instead of falling back silently.
	 *
	 * @param int $n Store number.
	 * @return array { label, host, app_id, app_key, uid5 }.
	 */
	public static function pospal_store( $n ) {
		$n = max( 1, (int) $n );
		if ( 1 === $n ) {
			$label1 = (string) self::get( 'pospal_label', '' );
			return array(
				'label'   => '' !== $label1 ? $label1 : __( 'Store 1', 'doughboss' ),
				'host'    => self::pospal_host(),
				'app_id'  => self::pospal_app_id(),
				'app_key' => self::pospal_app_key(),
				'uid5'    => self::pospal_coupon_uid_5(),
			);
		}
		$label = (string) self::get( 'pospal' . $n . '_label', '' );
		return array(
			/* translators: %d: store number. */
			'label'   => '' !== $label ? $label : sprintf( __( 'Store %d', 'doughboss' ), $n ),
			'host'    => untrailingslashit( (string) self::get( 'pospal' . $n . '_host', '' ) ),
			'app_id'  => (string) self::get( 'pospal' . $n . '_app_id', '' ),
			'app_key' => self::pospal_store_key( $n ),
			'uid5'    => (string) self::get( 'pospal' . $n . '_coupon_uid_5', '' ),
		);
	}

	/**
	 * Whether the Mercure real-time push integration is switched on by the operator.
	 *
	 * @return bool
	 */
	public static function mercure_enabled() {
		return (bool) self::get( 'mercure_enabled', 0 );
	}

	/**
	 * Mercure hub URL, trailing slash removed (e.g. https://hub.example.com/.well-known/mercure).
	 *
	 * @return string
	 */
	public static function mercure_hub_url() {
		return untrailingslashit( (string) self::get( 'mercure_hub_url', '' ) );
	}

	/**
	 * Mercure publisher JWT. Read env-first — the constant
	 * DOUGHBOSS_MERCURE_PUBLISH_JWT or the matching environment variable take
	 * precedence over the stored option, so the secret can be kept out of the
	 * database (and therefore out of backups). Only ever used server-side to
	 * authenticate publishes to the hub; never echoed to a client.
	 *
	 * @return string
	 */
	public static function mercure_publish_jwt() {
		if ( defined( 'DOUGHBOSS_MERCURE_PUBLISH_JWT' ) && '' !== (string) DOUGHBOSS_MERCURE_PUBLISH_JWT ) {
			return (string) DOUGHBOSS_MERCURE_PUBLISH_JWT;
		}
		$env = getenv( 'DOUGHBOSS_MERCURE_PUBLISH_JWT' );
		if ( false !== $env && '' !== $env ) {
			return (string) $env;
		}
		return (string) self::get( 'mercure_publish_jwt', '' );
	}

	/**
	 * Mercure subscriber JWT, handed to browser clients so they may subscribe to
	 * topics. Not a publish credential, so it is read from the stored option.
	 *
	 * @return string
	 */
	public static function mercure_subscribe_jwt() {
		return (string) self::get( 'mercure_subscribe_jwt', '' );
	}

	/**
	 * Prefix used when composing Mercure topic URIs/names.
	 *
	 * @return string
	 */
	public static function mercure_topic_prefix() {
		return (string) self::get( 'mercure_topic_prefix', 'doughboss' );
	}

	/**
	 * Whether Mercure is both enabled and the minimum config (hub URL + publish
	 * JWT) is present, so the server should actually publish real-time updates.
	 *
	 * @return bool
	 */
	public static function mercure_ready() {
		return self::mercure_enabled() && '' !== self::mercure_hub_url() && '' !== self::mercure_publish_jwt();
	}

	/**
	 * Whether the ntfy push-notification integration is switched on by the operator.
	 *
	 * @return bool
	 */
	public static function ntfy_enabled() {
		return (bool) self::get( 'ntfy_enabled', 0 );
	}

	/**
	 * ntfy server base URL, trailing slash removed (default https://ntfy.sh).
	 *
	 * @return string
	 */
	public static function ntfy_server() {
		$server = untrailingslashit( (string) self::get( 'ntfy_server', 'https://ntfy.sh' ) );
		return '' !== $server ? $server : 'https://ntfy.sh';
	}

	/**
	 * ntfy topic to publish to (blank when unconfigured).
	 *
	 * @return string
	 */
	public static function ntfy_topic() {
		return (string) self::get( 'ntfy_topic', '' );
	}

	/**
	 * ntfy bearer token. Read env-first — the constant DOUGHBOSS_NTFY_TOKEN or the
	 * matching environment variable take precedence over the stored option, so the
	 * secret can be kept out of the database (and therefore out of backups). Only
	 * ever used server-side to authenticate publishes; never echoed to a client.
	 *
	 * @return string
	 */
	public static function ntfy_token() {
		if ( defined( 'DOUGHBOSS_NTFY_TOKEN' ) && '' !== (string) DOUGHBOSS_NTFY_TOKEN ) {
			return (string) DOUGHBOSS_NTFY_TOKEN;
		}
		$env = getenv( 'DOUGHBOSS_NTFY_TOKEN' );
		if ( false !== $env && '' !== $env ) {
			return (string) $env;
		}
		return (string) self::get( 'ntfy_token', '' );
	}

	/**
	 * ntfy message priority (default 'high').
	 *
	 * @return string
	 */
	public static function ntfy_priority() {
		return (string) self::get( 'ntfy_priority', 'high' );
	}

	/**
	 * Whether ntfy is both enabled and a topic is configured, so the server should
	 * actually publish notifications.
	 *
	 * @return bool
	 */
	public static function ntfy_ready() {
		return self::ntfy_enabled() && '' !== self::ntfy_topic();
	}

	/**
	 * Whether the SMS (ClickSend) integration is switched on by the operator.
	 *
	 * @return bool
	 */
	public static function sms_enabled() {
		return (bool) self::get( 'sms_enabled', 0 );
	}

	/**
	 * ClickSend account username.
	 *
	 * @return string
	 */
	public static function clicksend_username() {
		return (string) self::get( 'clicksend_username', '' );
	}

	/**
	 * ClickSend API key. Read env-first — the constant DOUGHBOSS_CLICKSEND_API_KEY
	 * or the matching environment variable take precedence over the stored option,
	 * so the secret can be kept out of the database (and therefore out of backups).
	 * Only ever used server-side to authenticate the API; never echoed to a client.
	 *
	 * @return string
	 */
	public static function clicksend_api_key() {
		if ( defined( 'DOUGHBOSS_CLICKSEND_API_KEY' ) && '' !== (string) DOUGHBOSS_CLICKSEND_API_KEY ) {
			return (string) DOUGHBOSS_CLICKSEND_API_KEY;
		}
		$env = getenv( 'DOUGHBOSS_CLICKSEND_API_KEY' );
		if ( false !== $env && '' !== $env ) {
			return (string) $env;
		}
		return (string) self::get( 'clicksend_api_key', '' );
	}

	/**
	 * The sender ID / from-number used for outbound SMS.
	 *
	 * @return string
	 */
	public static function sms_from() {
		return (string) self::get( 'sms_from', '' );
	}

	/**
	 * Whether to text the customer when their order is marked ready (default on).
	 *
	 * @return bool
	 */
	public static function sms_on_ready() {
		return (bool) self::get( 'sms_on_ready', 1 );
	}

	/**
	 * Whether to text the voucher code to the customer when a voucher is claimed
	 * (default off).
	 *
	 * @return bool
	 */
	public static function sms_on_voucher_claim() {
		return (bool) self::get( 'sms_on_voucher_claim', 0 );
	}

	/**
	 * Whether SMS is both enabled and fully configured (username + API key), so
	 * the server should actually send messages.
	 *
	 * @return bool
	 */
	public static function sms_ready() {
		return self::sms_enabled() && '' !== self::clicksend_username() && '' !== self::clicksend_api_key();
	}

	/**
	 * Whether the receipt-printer integration is switched on by the operator.
	 *
	 * @return bool
	 */
	public static function printer_enabled() {
		return (bool) self::get( 'printer_enabled', 0 );
	}

	/**
	 * Receipt printer protocol: 'cloudprnt' or 'epos' (default 'cloudprnt').
	 *
	 * @return string
	 */
	public static function printer_protocol() {
		return 'epos' === self::get( 'printer_protocol', 'cloudprnt' ) ? 'epos' : 'cloudprnt';
	}

	/**
	 * Receipt printer shared token. Read env-first — the constant
	 * DOUGHBOSS_PRINTER_TOKEN or the matching environment variable take precedence
	 * over the stored option, so the secret can be kept out of the database (and
	 * therefore out of backups). Used to authenticate the printer/poll exchange;
	 * never echoed to a client.
	 *
	 * @return string
	 */
	public static function printer_token() {
		if ( defined( 'DOUGHBOSS_PRINTER_TOKEN' ) && '' !== (string) DOUGHBOSS_PRINTER_TOKEN ) {
			return (string) DOUGHBOSS_PRINTER_TOKEN;
		}
		$env = getenv( 'DOUGHBOSS_PRINTER_TOKEN' );
		if ( false !== $env && '' !== $env ) {
			return (string) $env;
		}
		return (string) self::get( 'printer_token', '' );
	}

	/**
	 * Receipt width in characters (default 48 for an 80mm roll).
	 *
	 * @return int
	 */
	public static function printer_width() {
		return (int) self::get( 'printer_width', 48 );
	}

	/**
	 * Whether the printer is both enabled and a shared token is set, so the server
	 * should actually emit receipts.
	 *
	 * @return bool
	 */
	public static function printer_ready() {
		return self::printer_enabled() && '' !== self::printer_token();
	}

	/**
	 * Order-confirmation email subject. Owner-editable (DoughBoss → Message
	 * Templates); blank restores the built-in default. Supports the
	 * {site_name}/{order_number} placeholders — see render_template().
	 *
	 * @return string
	 */
	public static function tpl_order_email_subject() {
		$v = trim( (string) self::get( 'tpl_order_email_subject', '' ) );
		return '' !== $v ? $v : '[{site_name}] Order {order_number} received';
	}

	/**
	 * Order-confirmation email body. Owner-editable; blank restores the
	 * built-in default. Supports {customer_name}/{order_number}/{items}/{total}.
	 *
	 * @return string
	 */
	public static function tpl_order_email_body() {
		$v = (string) self::get( 'tpl_order_email_body', '' );
		return '' !== trim( $v )
			? $v
			: "Hi {customer_name},\n\nThanks for your order {order_number}. Here's what we got:\n\n{items}\n\nTotal: {total}\n\nKeep your order number and email to check the latest status on our website.\n";
	}

	/**
	 * "Order ready" SMS text. Owner-editable; blank restores the built-in
	 * default. Supports {order_number}.
	 *
	 * @return string
	 */
	public static function tpl_sms_ready() {
		$v = trim( (string) self::get( 'tpl_sms_ready', '' ) );
		return '' !== $v ? $v : 'Your DoughBoss order #{order_number} is ready for pickup.';
	}

	/**
	 * Voucher-claimed SMS text. Owner-editable; blank restores the built-in
	 * default. Supports {code}.
	 *
	 * @return string
	 */
	public static function tpl_sms_voucher() {
		$v = trim( (string) self::get( 'tpl_sms_voucher', '' ) );
		return '' !== $v ? $v : 'Your DoughBoss voucher is ready: {code}. Show this code to redeem.';
	}

	/**
	 * Replace {placeholder} tokens in a message template with the given values.
	 * Unknown placeholders are left as literal text rather than silently
	 * blanked, so a typo in a custom template stays visible instead of hidden.
	 *
	 * @param string $template Template text containing {placeholder} tokens.
	 * @param array  $vars     Map of placeholder name (without braces) => value.
	 * @return string
	 */
	public static function render_template( $template, array $vars ) {
		$search  = array();
		$replace = array();
		foreach ( $vars as $key => $value ) {
			$search[]  = '{' . $key . '}';
			$replace[] = (string) $value;
		}
		return str_replace( $search, $replace, (string) $template );
	}
}
