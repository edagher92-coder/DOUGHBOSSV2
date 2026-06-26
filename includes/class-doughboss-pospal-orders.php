<?php
/**
 * POSPal order push — mirror a placed online order onto the POS till (off by default).
 *
 * On `doughboss_order_created`, build a POSPal Order Push body from the order and
 * its line items and POST it to `orderOpenApi/addOnLineOrder` so the order appears
 * in POSPal. FULLY DORMANT unless `DoughBoss_Settings::pospal_push_enabled()` is true
 * (POSPal on AND "push orders" on). Best-effort: any failure is logged (status only,
 * never PII/secrets) and swallowed — a POS hiccup must never block or undo the order.
 *
 * POSPal line items require an existing POSPal product **uid** (no free text), so each
 * menu item must be mapped to a uid first (settings `pospal_product_map`, built with
 * `wp doughboss pospal-map`). If any item in an order is unmapped, the push is skipped
 * (logged) rather than sending POSPal a broken order.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires order creation to the POSPal Order Push API. Static; register via init().
 */
class DoughBoss_POSPal_Orders {

	/**
	 * Register the order-created hook. Safe to call always — the handler self-gates.
	 *
	 * @return void
	 */
	public static function init() {
		// Priority 20: after the kitchen board / notifications have seen the order.
		add_action( 'doughboss_order_created', array( __CLASS__, 'on_order_created' ), 20, 2 );
	}

	/**
	 * Push a newly created order to POSPal.
	 *
	 * @param int   $order_id New order id.
	 * @param array $data     Create payload (unused; the row is reloaded).
	 * @return void
	 */
	public static function on_order_created( $order_id, $data = array() ) {
		unset( $data );

		if ( ! DoughBoss_Settings::pospal_push_enabled() ) {
			return;
		}

		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return;
		}

		$order = DoughBoss_Order::get( $order_id );
		if ( ! $order ) {
			return;
		}

		$items = DoughBoss_Order::get_items( $order_id );
		if ( empty( $items ) ) {
			return;
		}

		// Which POSPal store this order rings into. Default: the primary/legacy store
		// (creds = null). Filterable so a site can route by the order's location.
		$creds = apply_filters( 'doughboss_pospal_order_store_creds', null, $order );

		$build = self::build_body( $order, $items );
		if ( ! empty( $build['unmapped'] ) ) {
			self::log( 'push skipped for #' . $order_id . ' — unmapped items: ' . implode( ', ', $build['unmapped'] ) );
			return;
		}

		$result = DoughBoss_POSPal::push_order( $build['body'], is_array( $creds ) ? $creds : null );
		if ( is_wp_error( $result ) ) {
			self::log( 'push failed for #' . $order_id . ' (' . $result->get_error_code() . ')' );
			return;
		}
		$order_no = is_array( $result ) && isset( $result['orderNo'] ) ? (string) $result['orderNo'] : '';
		self::log( 'push ok for #' . $order_id . ( '' !== $order_no ? ' -> POSPal ' . $order_no : '' ) );
	}

	/**
	 * Build the POSPal addOnLineOrder body from an order row + its items.
	 *
	 * @param object             $order Order row.
	 * @param array<int,array>   $items Line items (name, quantity, unit_price, size, toppings).
	 * @return array{body:array,unmapped:string[]} Body + any item names with no product uid.
	 */
	public static function build_body( $order, array $items ) {
		$map      = DoughBoss_Settings::pospal_product_map();
		$lines    = array();
		$unmapped = array();

		foreach ( $items as $item ) {
			$name = isset( $item['name'] ) ? (string) $item['name'] : '';
			$uid  = self::map_uid( $name, $map );
			if ( ! $uid ) {
				$unmapped[] = $name;
				continue;
			}
			$line = array(
				'productUid'      => DoughBoss_POSPal::long_id( $uid ),
				'quantity'        => (float) ( isset( $item['quantity'] ) ? $item['quantity'] : 1 ),
				'manualSellPrice' => round( (float) ( isset( $item['unit_price'] ) ? $item['unit_price'] : 0 ), 2 ),
			);
			$comment = self::item_comment( $item );
			if ( '' !== $comment ) {
				$line['comment'] = $comment;
			}
			$lines[] = $line;
		}

		$type     = isset( $order->order_type ) ? (string) $order->order_type : 'pickup';
		$delivery = ( 'delivery' === $type );
		$address  = isset( $order->address ) ? trim( (string) $order->address ) : '';

		$body = array(
			'orderSource'                => 'openApi',
			'payMethod'                  => DoughBoss_Settings::pospal_order_pay_method(),
			'orderDateTime'              => isset( $order->created_at ) ? (string) $order->created_at : current_time( 'mysql' ),
			'deliveryType'               => $delivery ? 0 : 1,
			'daySeq'                     => isset( $order->order_number ) ? (string) $order->order_number : '',
			'contactName'                => '' !== (string) $order->customer_name ? (string) $order->customer_name : __( 'Online order', 'doughboss' ),
			'contactTel'                 => isset( $order->customer_phone ) ? (string) $order->customer_phone : '',
			'contactAddress'             => ( $delivery && '' !== $address ) ? $address : __( 'Pickup in store', 'doughboss' ),
			'totalAmount'                => round( (float) ( isset( $order->total ) ? $order->total : 0 ), 2 ),
			'skipProductStockValidation' => 1,
			'items'                      => $lines,
		);

		if ( $delivery && isset( $order->delivery_fee ) && (float) $order->delivery_fee > 0 ) {
			$body['shippingFee'] = round( (float) $order->delivery_fee, 2 );
		}
		if ( isset( $order->notes ) && '' !== trim( (string) $order->notes ) ) {
			$body['orderRemark'] = substr( (string) $order->notes, 0, 200 );
		}

		// Mark online-paid (Stripe) orders as paid on the POS when configured. POSPal
		// requires payOnLine=1 to pair with a Wxpay/Alipay/custom pay method.
		$paid = isset( $order->payment_status ) && 'paid' === $order->payment_status;
		if ( $paid && DoughBoss_Settings::pospal_order_pay_online() ) {
			$body['payOnLine'] = 1;
			$code = DoughBoss_Settings::pospal_order_pay_method_code();
			if ( '' !== $code ) {
				$body['payMethodCode'] = $code;
			}
		}

		return array(
			'body'     => $body,
			'unmapped' => array_values( array_unique( $unmapped ) ),
		);
	}

	/**
	 * Resolve a menu item name to a POSPal product uid via the saved map. Matches on a
	 * normalised (trimmed, lower-cased, collapsed-space) name.
	 *
	 * @param string $name Item name.
	 * @param array  $map  Map of normalised-name => uid.
	 * @return int|string 0 when unmapped.
	 */
	private static function map_uid( $name, array $map ) {
		$key = self::norm( $name );
		if ( '' === $key || ! isset( $map[ $key ] ) ) {
			return 0;
		}
		return $map[ $key ];
	}

	/**
	 * Normalise an item name into a map key.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	public static function norm( $name ) {
		$name = strtolower( trim( (string) $name ) );
		return (string) preg_replace( '/\s+/', ' ', $name );
	}

	/**
	 * Compose a short per-item note from size + toppings (toppings stored as JSON).
	 *
	 * @param array $item Line item.
	 * @return string
	 */
	private static function item_comment( $item ) {
		$parts = array();
		if ( ! empty( $item['size'] ) ) {
			$parts[] = (string) $item['size'];
		}
		if ( ! empty( $item['toppings'] ) ) {
			$tops = is_string( $item['toppings'] ) ? json_decode( $item['toppings'], true ) : $item['toppings'];
			if ( is_array( $tops ) && ! empty( $tops ) ) {
				$labels = array();
				foreach ( $tops as $t ) {
					if ( is_array( $t ) && isset( $t['label'] ) ) {
						$labels[] = (string) $t['label'];
					} elseif ( is_string( $t ) ) {
						$labels[] = $t;
					}
				}
				if ( ! empty( $labels ) ) {
					$parts[] = implode( ', ', $labels );
				}
			}
		}
		return substr( implode( ' · ', $parts ), 0, 200 );
	}

	/**
	 * Log a sync status line for the operator — status + order id only.
	 *
	 * @param string $message Short status string.
	 * @return void
	 */
	private static function log( $message ) {
		if ( function_exists( 'error_log' ) ) {
			error_log( 'DoughBoss POSPal order: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
