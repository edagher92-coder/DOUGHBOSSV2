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
 * This is the ONLY place defaults live. The activator seeds sample sizes and
 * toppings on top of these, but every scalar default is defined here so the
 * runtime and the installer can never disagree (they did in 2.0).
 *
 * Reads are memoised per request: get_option() caches the serialised string,
 * but every call still paid for maybe_unserialize() + wp_parse_args(), and the
 * pricing path called it once per topping.
 */
class DoughBoss_Settings {

	const OPTION_KEY = 'doughboss_settings';

	/**
	 * Per-request memo of the merged settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Slug-keyed lookup maps, built once per request.
	 *
	 * @var array{sizes:array,toppings:array}|null
	 */
	private static $maps = null;

	/**
	 * Register the cache-busting hook. Called once from the loader.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'flush' ) );
		add_action( 'add_option_' . self::OPTION_KEY, array( __CLASS__, 'flush' ) );
	}

	/**
	 * Drop the memo (after the option is written, or in tests).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
		self::$maps  = null;
	}

	/**
	 * Return the full settings array merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		self::$cache = wp_parse_args( $stored, self::defaults() );
		return self::$cache;
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
	 * Default settings — the single source of truth.
	 *
	 * Currency defaults to AUD because the plugin runs doughboss.com.au. The tax
	 * rate deliberately defaults to 0 rather than a guessed figure: whether GST
	 * applies (and to which items — plain bread is GST-free, hot prepared food is
	 * not) is the shop's call, and the admin screen nags until it is set.
	 * Prices are treated as tax-INCLUSIVE by default, which is how Australian
	 * consumer pricing must be displayed.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'currency_symbol'          => '$',
			'currency_code'            => 'AUD',
			'tax_rate'                 => 0,
			'tax_label'                => 'GST',
			'prices_include_tax'       => 1,
			'tax_applies_to_delivery'  => 1,
			'delivery_fee'             => 0,
			'min_order'                => 0,
			'enable_pickup'            => 1,
			'enable_delivery'          => 0,
			'ordering_open'            => 1,
			'store_phone'              => '',
			'pay_note'                 => '',
			'tracking_page_url'        => '',
			'menu_page_url'            => '',
			'sizes'                    => array(),
			'toppings'                 => array(),
		);
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
	 * Build (once) the slug-keyed lookup maps.
	 *
	 * @return array{sizes:array,toppings:array}
	 */
	private static function maps() {
		if ( null !== self::$maps ) {
			return self::$maps;
		}
		$maps = array(
			'sizes'    => array(),
			'toppings' => array(),
		);
		foreach ( self::sizes() as $size ) {
			if ( isset( $size['slug'] ) ) {
				$maps['sizes'][ $size['slug'] ] = $size;
			}
		}
		foreach ( self::toppings() as $topping ) {
			if ( isset( $topping['slug'] ) ) {
				$maps['toppings'][ $topping['slug'] ] = $topping;
			}
		}
		self::$maps = $maps;
		return $maps;
	}

	/**
	 * Look up a single size definition by slug. O(1).
	 *
	 * @param string $slug Size slug.
	 * @return array|null
	 */
	public static function find_size( $slug ) {
		$maps = self::maps();
		return isset( $maps['sizes'][ $slug ] ) ? $maps['sizes'][ $slug ] : null;
	}

	/**
	 * Look up a single topping definition by slug. O(1).
	 *
	 * @param string $slug Topping slug.
	 * @return array|null
	 */
	public static function find_topping( $slug ) {
		$maps = self::maps();
		return isset( $maps['toppings'][ $slug ] ) ? $maps['toppings'][ $slug ] : null;
	}

	/**
	 * Tax rate as a fraction (e.g. 10% -> 0.10).
	 *
	 * @return float
	 */
	public static function tax_fraction() {
		return max( 0.0, (float) self::get( 'tax_rate', 0 ) ) / 100;
	}

	/**
	 * Whether displayed prices already include tax (Australian convention).
	 *
	 * @return bool
	 */
	public static function prices_include_tax() {
		return (bool) self::get( 'prices_include_tax', 1 );
	}

	/**
	 * Whether the delivery fee is a taxable supply.
	 *
	 * @return bool
	 */
	public static function tax_applies_to_delivery() {
		return (bool) self::get( 'tax_applies_to_delivery', 1 );
	}

	/**
	 * Human label for the tax line ("GST").
	 *
	 * @return string
	 */
	public static function tax_label() {
		$label = (string) self::get( 'tax_label', 'GST' );
		return '' !== $label ? $label : __( 'Tax', 'doughboss' );
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
	 * Minimum order value in dollars (0 = none).
	 *
	 * @return float
	 */
	public static function min_order() {
		return max( 0.0, round( (float) self::get( 'min_order', 0 ), 2 ) );
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
	 * The storefront configuration payload shared by GET /config and the
	 * inlined `DoughBossData.config`.
	 *
	 * @return array
	 */
	public static function public_config() {
		return array(
			'currency_symbol' => self::get( 'currency_symbol', '$' ),
			'currency_code'   => self::get( 'currency_code', 'AUD' ),
			'tax_rate'        => (float) self::get( 'tax_rate', 0 ),
			'tax_label'       => self::tax_label(),
			'tax_inclusive'   => self::prices_include_tax(),
			'delivery_fee'    => round( (float) self::get( 'delivery_fee', 0 ), 2 ),
			'min_order'       => self::min_order(),
			'enable_pickup'   => (bool) self::get( 'enable_pickup', 1 ),
			'enable_delivery' => (bool) self::get( 'enable_delivery', 0 ),
			'ordering_open'   => self::ordering_open(),
			'sizes'           => self::sizes(),
			'toppings'        => self::toppings(),
			'pay_note'        => (string) self::get( 'pay_note', '' ),
			'store_phone'     => (string) self::get( 'store_phone', '' ),
		);
	}
}
