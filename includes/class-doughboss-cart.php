<?php
/**
 * Guest-friendly shopping cart.
 *
 * Carts are keyed by a random token stored in a cookie and persisted in a
 * transient, so customers do not need an account and we never rely on PHP
 * sessions (which are discouraged in WordPress).
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart storage and pricing.
 */
class DoughBoss_Cart {

	const COOKIE      = 'doughboss_cart';
	const PREFIX      = 'doughboss_cart_';
	const TTL         = DAY_IN_SECONDS;
	const MAX_QTY     = 50;
	const MAX_LINES   = 50;

	/**
	 * Cached token for the current request (null = no valid cookie yet).
	 *
	 * @var string|null
	 */
	private $token = null;

	/**
	 * Per-request memo of the stored lines (null = not read yet).
	 *
	 * @var array|null
	 */
	private $lines = null;

	/**
	 * Read the cart token from the cookie WITHOUT creating one.
	 *
	 * 2.0 generated a mixed-case token and then lower-cased it on read via
	 * sanitize_key(), so the key written and the key read never matched. Plain
	 * MySQL hid the bug behind a case-insensitive collation; on any host with a
	 * persistent object cache (where core stores transients ONLY in the cache)
	 * the first item added was silently lost. Tokens are now lowercase hex and
	 * are validated, never mutated, on read.
	 *
	 * @return string|null
	 */
	public function get_token() {
		if ( null !== $this->token ) {
			return $this->token;
		}
		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$raw = wp_unslash( $_COOKIE[ self::COOKIE ] );
			if ( is_string( $raw ) && preg_match( '/^[a-f0-9]{32}$/', $raw ) ) {
				$this->token = $raw;
				return $this->token;
			}
		}
		return null;
	}

	/**
	 * Resolve the token, minting one if the visitor has none. Only write paths
	 * call this, so browse-only visitors never receive a cookie (page caches
	 * that bypass on unknown cookies stay effective, and no consent surface is
	 * created for someone who only looked at the menu).
	 *
	 * @return string
	 */
	private function ensure_token() {
		$token = $this->get_token();
		if ( null !== $token ) {
			return $token;
		}
		$this->token = bin2hex( random_bytes( 16 ) );
		$this->set_cookie( $this->token );
		return $this->token;
	}

	/**
	 * Send the cart cookie. Safe to call before output in a REST callback.
	 *
	 * @param string $token Cart token.
	 * @return void
	 */
	private function set_cookie( $token ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$token,
			array(
				'expires'  => time() + self::TTL,
				'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE ] = $token;
	}

	/**
	 * Transient key for a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private function transient_key( $token ) {
		return self::PREFIX . $token;
	}

	/**
	 * Read raw cart lines from storage (memoised per request).
	 *
	 * @return array
	 */
	private function read() {
		if ( null !== $this->lines ) {
			return $this->lines;
		}
		$token = $this->get_token();
		if ( null === $token ) {
			$this->lines = array();
			return $this->lines;
		}
		$data        = get_transient( $this->transient_key( $token ) );
		$this->lines = is_array( $data ) ? $data : array();
		return $this->lines;
	}

	/**
	 * Persist cart lines to storage.
	 *
	 * @param array $lines Cart lines.
	 * @return void
	 */
	private function write( array $lines ) {
		$token = $this->ensure_token();
		set_transient( $this->transient_key( $token ), $lines, self::TTL );
		$this->lines = $lines;
	}

	/**
	 * Whether the visitor has a cart cookie at all (used to avoid a needless
	 * cart fetch on pages that only show a badge).
	 *
	 * @return bool
	 */
	public function has_token() {
		return null !== $this->get_token();
	}

	/**
	 * Build a stable line key for a configuration so identical items merge.
	 *
	 * @param array $line Line data.
	 * @return string
	 */
	private function line_key( array $line ) {
		$toppings = isset( $line['toppings'] ) ? wp_list_pluck( $line['toppings'], 'slug' ) : array();
		sort( $toppings );
		$signature = wp_json_encode(
			array(
				$line['type'],
				(int) $line['item_id'],
				isset( $line['size_slug'] ) ? $line['size_slug'] : ( isset( $line['size'] ) ? $line['size'] : '' ),
				$toppings,
			)
		);
		return md5( $signature );
	}

	/**
	 * Add an item to the cart.
	 *
	 * @param array $line {
	 *     Line definition (already priced server-side by the caller).
	 *
	 *     @type string $type       'menu' or 'custom'.
	 *     @type int    $item_id    Menu item post ID (0 for custom builds).
	 *     @type string $name       Display name.
	 *     @type string $size       Size label (optional).
	 *     @type string $size_slug  Size slug (optional; used to re-price).
	 *     @type array  $toppings   List of array{slug,label,price} (optional).
	 *     @type float  $unit_price Per-unit price.
	 *     @type int    $quantity   Quantity to add.
	 * }
	 * @return array|WP_Error The added/merged line, or an error.
	 */
	public function add( array $line ) {
		$lines    = $this->read();
		$quantity = max( 1, (int) ( isset( $line['quantity'] ) ? $line['quantity'] : 1 ) );
		$key      = $this->line_key( $line );

		if ( isset( $lines[ $key ] ) ) {
			$lines[ $key ]['quantity'] = min( self::MAX_QTY, $lines[ $key ]['quantity'] + $quantity );
			// Re-adding the same configuration refreshes the price: a price rise
			// between the two adds must not be frozen at the old figure.
			$lines[ $key ]['unit_price'] = round( (float) $line['unit_price'], 2 );
		} else {
			if ( count( $lines ) >= self::MAX_LINES ) {
				return new WP_Error( 'doughboss_cart_full', __( 'Your cart is full.', 'doughboss' ), array( 'status' => 400 ) );
			}
			$lines[ $key ] = array(
				'key'        => $key,
				'type'       => $line['type'],
				'item_id'    => (int) $line['item_id'],
				'name'       => $line['name'],
				'size'       => isset( $line['size'] ) ? $line['size'] : '',
				'size_slug'  => isset( $line['size_slug'] ) ? $line['size_slug'] : '',
				'toppings'   => isset( $line['toppings'] ) ? array_values( $line['toppings'] ) : array(),
				'unit_price' => round( (float) $line['unit_price'], 2 ),
				'quantity'   => min( self::MAX_QTY, $quantity ),
				'available'  => true,
			);
		}

		$this->write( $lines );
		return $this->decorate_line( $lines[ $key ] );
	}

	/**
	 * Update the quantity of a line. A quantity of 0 removes it.
	 *
	 * @param string $key      Line key.
	 * @param int    $quantity New quantity.
	 * @return bool True when the line existed.
	 */
	public function update_quantity( $key, $quantity ) {
		$lines = $this->read();
		if ( ! isset( $lines[ $key ] ) ) {
			return false;
		}

		$quantity = (int) $quantity;
		if ( $quantity <= 0 ) {
			unset( $lines[ $key ] );
		} else {
			$lines[ $key ]['quantity'] = min( self::MAX_QTY, $quantity );
		}

		$this->write( $lines );
		return true;
	}

	/**
	 * Remove a single line.
	 *
	 * @param string $key Line key.
	 * @return bool
	 */
	public function remove( $key ) {
		$lines = $this->read();
		if ( ! isset( $lines[ $key ] ) ) {
			return false;
		}
		unset( $lines[ $key ] );
		$this->write( $lines );
		return true;
	}

	/**
	 * Empty the cart.
	 *
	 * @return void
	 */
	public function clear() {
		$token = $this->get_token();
		if ( null !== $token ) {
			delete_transient( $this->transient_key( $token ) );
		}
		$this->lines = array();
	}

	/**
	 * Replace every stored line (used by checkout re-validation after
	 * re-pricing against the live menu and settings).
	 *
	 * @param array[] $lines Keyed lines.
	 * @return void
	 */
	public function replace_lines( array $lines ) {
		$this->write( $lines );
	}

	/**
	 * Raw stored lines keyed by line key (no computed totals).
	 *
	 * @return array[]
	 */
	public function raw_lines() {
		return $this->read();
	}

	/**
	 * Decorate a stored line with computed line_total.
	 *
	 * @param array $line Stored line.
	 * @return array
	 */
	private function decorate_line( array $line ) {
		$line['line_total'] = round( $line['unit_price'] * $line['quantity'], 2 );
		if ( ! isset( $line['available'] ) ) {
			$line['available'] = true;
		}
		return $line;
	}

	/**
	 * Get all decorated cart lines.
	 *
	 * @return array[]
	 */
	public function get_lines() {
		return array_map( array( $this, 'decorate_line' ), array_values( $this->read() ) );
	}

	/**
	 * Compute cart totals.
	 *
	 * Two tax models are supported:
	 *
	 *  - prices_include_tax = 1 (Australian default): the displayed prices are
	 *    what the customer pays. total = subtotal + delivery. The tax figure is
	 *    the amount INCLUDED in that total: base × r / (1 + r).
	 *  - prices_include_tax = 0 (US-style): tax is ADDED on top of the displayed
	 *    prices. total = subtotal + delivery + base × r.
	 *
	 * In both models `tax_applies_to_delivery` decides whether the delivery fee
	 * forms part of the taxable base.
	 *
	 * @param string $order_type 'pickup' or 'delivery' (affects delivery fee).
	 * @return array
	 */
	public function totals( $order_type = 'pickup' ) {
		$subtotal   = 0.0;
		$item_count = 0;

		foreach ( $this->get_lines() as $line ) {
			$subtotal   += $line['line_total'];
			$item_count += $line['quantity'];
		}

		$subtotal     = round( $subtotal, 2 );
		$delivery_fee = ( 'delivery' === $order_type ) ? round( (float) DoughBoss_Settings::get( 'delivery_fee', 0 ), 2 ) : 0.0;
		$rate         = DoughBoss_Settings::tax_fraction();
		$inclusive    = DoughBoss_Settings::prices_include_tax();
		$taxable_base = $subtotal + ( DoughBoss_Settings::tax_applies_to_delivery() ? $delivery_fee : 0.0 );

		if ( $rate <= 0 ) {
			$tax   = 0.0;
			$total = round( $subtotal + $delivery_fee, 2 );
		} elseif ( $inclusive ) {
			$tax   = round( $taxable_base * $rate / ( 1 + $rate ), 2 );
			$total = round( $subtotal + $delivery_fee, 2 );
		} else {
			$tax   = round( $taxable_base * $rate, 2 );
			$total = round( $subtotal + $delivery_fee + $tax, 2 );
		}

		$min_order = DoughBoss_Settings::min_order();

		return array(
			'subtotal'      => $subtotal,
			'tax'           => $tax,
			'delivery_fee'  => $delivery_fee,
			'total'         => $total,
			'item_count'    => $item_count,
			'tax_inclusive' => $inclusive,
			'tax_label'     => DoughBoss_Settings::tax_label(),
			'tax_rate'      => round( $rate * 100, 2 ),
			'min_order'     => $min_order,
			'min_order_met' => ( $min_order <= 0 ) || ( $subtotal >= $min_order ),
		);
	}

	/**
	 * Whether the cart has no items.
	 *
	 * @return bool
	 */
	public function is_empty() {
		return 0 === count( $this->read() );
	}

	/**
	 * Full cart payload for API/template consumption.
	 *
	 * @param string $order_type Order type for fee calculation.
	 * @return array
	 */
	public function to_array( $order_type = 'pickup' ) {
		return array(
			'items'  => $this->get_lines(),
			'totals' => $this->totals( $order_type ),
		);
	}
}
