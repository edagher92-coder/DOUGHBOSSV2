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
		);
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
	 * Stripe secret key for the active mode.
	 *
	 * @return string
	 */
	public static function stripe_secret_key() {
		return (string) self::get( 'live' === self::stripe_mode() ? 'stripe_live_sk' : 'stripe_test_sk', '' );
	}

	/**
	 * Stripe webhook signing secret for the active mode (server-side only).
	 *
	 * @return string
	 */
	public static function stripe_webhook_secret() {
		return (string) self::get( 'live' === self::stripe_mode() ? 'stripe_live_whsec' : 'stripe_test_whsec', '' );
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
}
