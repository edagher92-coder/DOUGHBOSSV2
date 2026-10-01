<?php
/**
 * Pure Square Orders projection and unpaid-response verification.
 *
 * This boundary has no database, settings, catalog or HTTP access. Its caller
 * must supply a previously hash-validated immutable snapshot and shop binding.
 * It does not authorize a payment or fulfillment, and is not a live transport.
 *
 * @package DoughBoss
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DoughBoss_Square_Orders {
	/** DECIMAL(10,2) persisted order amounts, expressed in cents. */
	const MAX_MONEY = 9999999999;

	/** Build an ad-hoc OPEN Order without fulfillment or additional pricing. */
	public static function project( array $payload, array $binding, $attempt_id ) {
		if ( PHP_INT_SIZE < 8 || ! is_int( $attempt_id ) || $attempt_id <= 0
			|| ! isset( $binding['shop_slug'], $binding['square_location_id'], $binding['square_mode'] )
			|| ! in_array( $binding['shop_slug'], array( 'revesby', 'bankstown', 'roselands' ), true )
			|| ! in_array( $binding['square_mode'], array( 'test', 'live' ), true )
			|| ! is_string( $binding['square_location_id'] )
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]{4,64}\z/', $binding['square_location_id'] )
			|| ! isset( $payload['order'], $payload['lines'] )
			|| ! is_array( $payload['order'] ) || ! is_array( $payload['lines'] )
			|| empty( $payload['lines'] ) || count( $payload['lines'] ) > 500 ) {
			return self::error();
		}
		$order = $payload['order'];
		$basis = DoughBoss_Order::accounting_basis_from_payload( $order );
		if ( ! is_array( $basis ) || ! isset( $order['currency'] )
			|| ! is_string( $order['currency'] ) || 1 !== preg_match( '/^[A-Z]{3}\z/', $order['currency'] ) ) {
			return self::error();
		}
		$currency = $order['currency'];
		$amounts = array();
		foreach ( array( 'subtotal', 'discount', 'tax', 'delivery_fee', 'total' ) as $key ) {
			$value = array_key_exists( $key, $order ) ? self::cents( $order[ $key ] ) : null;
			if ( null === $value ) {
				return self::error();
			}
			$amounts[ $key ] = $value;
		}
		$expected_total = $amounts['subtotal'] - $amounts['discount'] + $amounts['delivery_fee'];
		if ( ! $basis['tax_inclusive'] ) {
			$expected_total += $amounts['tax'];
		}
		$percentage = wp_json_encode( $basis['tax_rate'] );
		if ( $amounts['discount'] > $amounts['subtotal'] || $expected_total !== $amounts['total']
			|| $amounts['tax'] > $amounts['total']
			|| ( 0.0 === $basis['tax_rate'] && 0 !== $amounts['tax'] )
			|| ! is_string( $percentage ) || strlen( $percentage ) > 10
			|| 1 !== preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/', $percentage ) ) {
			return self::error();
		}
		$has_tax = $basis['tax_rate'] > 0;
		$has_discount = $amounts['discount'] > 0;
		$lines = array();
		$subtotal = 0;
		foreach ( array_values( $payload['lines'] ) as $index => $line ) {
			if ( ! is_array( $line ) || ! isset( $line['name'], $line['quantity'], $line['unit_price'], $line['line_total'] )
				|| ! self::text( $line['name'], 512, false ) ) {
				return self::error();
			}
			$quantity = self::quantity( $line['quantity'] );
			$unit = self::cents( $line['unit_price'] );
			$total = self::cents( $line['line_total'] );
			$note = self::line_note( $line );
			if ( null === $quantity || null === $unit || null === $total || null === $note
				|| ( $unit > 0 && $quantity > intdiv( self::MAX_MONEY, $unit ) )
				|| $quantity * $unit !== $total || $subtotal > self::MAX_MONEY - $total ) {
				return self::error();
			}
			$subtotal += $total;
			$item = array(
				'uid' => 'db-line-' . ( $index + 1 ),
				'name' => $line['name'],
				'quantity' => (string) $quantity,
				'base_price_money' => self::money( $unit, $currency ),
			);
			if ( '' !== $note ) {
				$item['note'] = $note;
			}
			if ( $has_tax ) {
				$item['applied_taxes'] = array( array( 'tax_uid' => 'db-tax' ) );
			}
			$lines[] = $item;
		}
		if ( $subtotal !== $amounts['subtotal'] ) {
			return self::error();
		}
		if ( $amounts['delivery_fee'] > 0 ) {
			$fee = array(
				'uid' => 'db-delivery',
				'name' => 'Delivery fee',
				'quantity' => '1',
				'base_price_money' => self::money( $amounts['delivery_fee'], $currency ),
			);
			if ( $has_tax && $basis['delivery_taxable'] ) {
				$fee['applied_taxes'] = array( array( 'tax_uid' => 'db-tax' ) );
			}
			if ( $has_discount ) {
				$fee['pricing_blocklists'] = array( 'blocked_discounts' => array( array( 'uid' => 'db-block-delivery-discount', 'discount_uid' => 'db-discount' ) ) );
			}
			$lines[] = $fee;
		}
		$provider_order = array(
			'location_id' => $binding['square_location_id'],
			'reference_id' => DoughBoss_Square::attempt_reference( $attempt_id ),
			'state' => 'OPEN',
			'line_items' => $lines,
			'pricing_options' => array( 'auto_apply_taxes' => false, 'auto_apply_discounts' => false ),
		);
		if ( $has_tax ) {
			$provider_order['taxes'] = array( array(
				'uid' => 'db-tax', 'name' => 'Tax',
				'type' => $basis['tax_inclusive'] ? 'INCLUSIVE' : 'ADDITIVE',
				'percentage' => $percentage, 'scope' => 'LINE_ITEM',
			) );
		}
		if ( $has_discount ) {
			$provider_order['discounts'] = array( array(
				'uid' => 'db-discount', 'name' => 'Goods discount',
				'type' => 'FIXED_AMOUNT', 'scope' => 'ORDER',
				'amount_money' => self::money( $amounts['discount'], $currency ),
			) );
		}
		return array( 'order' => $provider_order, 'amounts' => $amounts, 'currency' => $currency, 'tax_inclusive' => $basis['tax_inclusive'] );
	}

	/** Verify a fresh OPEN Order, not payment or kitchen authority. */
	public static function validate_unpaid_response( array $response, array $projection ) {
		if ( PHP_INT_SIZE < 8 || ! isset( $projection['order'], $projection['amounts'], $projection['currency'], $projection['tax_inclusive'] )
			|| ! is_array( $projection['order'] ) || ! is_array( $projection['amounts'] )
			|| ! is_bool( $projection['tax_inclusive'] ) || ! is_string( $projection['currency'] )
			|| 1 !== preg_match( '/^[A-Z]{3}\z/', $projection['currency'] )
			|| ! isset( $response['order'] ) || ! is_array( $response['order'] )
			|| ( array_key_exists( 'errors', $response ) && ( ! is_array( $response['errors'] ) || ! empty( $response['errors'] ) ) ) ) {
			return self::error();
		}
		$actual = $response['order'];
		$expected = $projection['order'];
		$currency = $projection['currency'];
		if ( ! isset( $expected['location_id'], $expected['reference_id'], $expected['line_items'] )
			|| ! is_string( $expected['location_id'] ) || ! is_string( $expected['reference_id'] )
			|| ! is_array( $expected['line_items'] ) || empty( $expected['line_items'] ) ) {
			return self::error();
		}
		foreach ( array( 'subtotal', 'discount', 'tax', 'delivery_fee', 'total' ) as $key ) {
			if ( ! isset( $projection['amounts'][ $key ] ) || ! is_int( $projection['amounts'][ $key ] )
				|| $projection['amounts'][ $key ] < 0 || $projection['amounts'][ $key ] > self::MAX_MONEY ) {
				return self::error();
			}
		}
		if ( ! isset( $actual['id'], $actual['version'], $actual['location_id'], $actual['reference_id'], $actual['state'], $actual['line_items'] )
			|| ! is_string( $actual['id'] ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{8,192}\z/', $actual['id'] )
			|| ! is_int( $actual['version'] ) || $actual['version'] < 1 || $actual['version'] > 2147483647
			|| $actual['location_id'] !== $expected['location_id'] || $actual['reference_id'] !== $expected['reference_id']
			|| 'OPEN' !== $actual['state'] || ! is_array( $actual['line_items'] )
			|| count( $actual['line_items'] ) !== count( $expected['line_items'] ) ) {
			return self::error();
		}
		foreach ( array( 'fulfillments', 'tenders', 'refunds', 'returns', 'rewards', 'service_charges' ) as $field ) {
			if ( array_key_exists( $field, $actual ) && ( ! is_array( $actual[ $field ] ) || ! empty( $actual[ $field ] ) ) ) {
				return self::error();
			}
		}
		if ( array_key_exists( 'rounding_adjustment', $actual ) || ! self::pricing_disabled( $actual ) ) {
			return self::error();
		}
		foreach ( array( 'taxes', 'discounts' ) as $field ) {
			if ( ! self::adjustments_match( $actual, $expected, $field, $currency ) ) {
				return self::error();
			}
		}
		$by_uid = array();
		foreach ( $actual['line_items'] as $line ) {
			if ( ! is_array( $line ) || ! isset( $line['uid'] ) || ! is_string( $line['uid'] ) || isset( $by_uid[ $line['uid'] ] ) ) {
				return self::error();
			}
			$by_uid[ $line['uid'] ] = $line;
		}
		$sums = array( 'tax' => 0, 'discount' => 0, 'total' => 0 );
		foreach ( $expected['line_items'] as $line ) {
			if ( ! is_array( $line ) || ! isset( $line['uid'], $line['name'], $line['quantity'], $line['base_price_money'] )
				|| ! is_string( $line['uid'] ) || ! is_string( $line['name'] )
				|| ! is_string( $line['quantity'] ) || null === self::quantity( $line['quantity'] )
				|| null === self::money_amount( $line['base_price_money'], $currency )
				|| ! isset( $by_uid[ $line['uid'] ] ) ) {
				return self::error();
			}
			$row = $by_uid[ $line['uid'] ];
			if ( ! isset( $row['name'], $row['quantity'], $row['base_price_money'] )
				|| $row['name'] !== $line['name'] || $row['quantity'] !== $line['quantity']
				|| ( array_key_exists( 'note', $row ) ? $row['note'] : '' ) !== ( isset( $line['note'] ) ? $line['note'] : '' )
				|| self::money_amount( $row['base_price_money'], $currency ) !== $line['base_price_money']['amount']
				|| ! self::ad_hoc( $row ) || ! self::blocklists_match( $row, $line ) ) {
				return self::error();
			}
			$base = $line['base_price_money']['amount'] * (int) $line['quantity'];
			$tax = self::money_amount( isset( $row['total_tax_money'] ) ? $row['total_tax_money'] : null, $currency );
			$discount = self::money_amount( isset( $row['total_discount_money'] ) ? $row['total_discount_money'] : null, $currency );
			$total = self::money_amount( isset( $row['total_money'] ) ? $row['total_money'] : null, $currency );
			$taxed = ! empty( $line['applied_taxes'] );
			$discounted = isset( $expected['discounts'] ) && 'db-delivery' !== $line['uid'];
			if ( null === $tax || null === $discount || null === $total || $discount > $base
				|| ! self::applied_match( $row, 'applied_taxes', 'tax_uid', $taxed ? 'db-tax' : '', $tax, $currency )
				|| ! self::applied_match( $row, 'applied_discounts', 'discount_uid', $discounted ? 'db-discount' : '', $discount, $currency )
				|| self::money_amount( isset( $row['gross_sales_money'] ) ? $row['gross_sales_money'] : null, $currency ) !== $base
				|| self::money_amount( isset( $row['variation_total_price_money'] ) ? $row['variation_total_price_money'] : null, $currency ) !== $base
				|| $total !== $base - $discount + ( $projection['tax_inclusive'] ? 0 : $tax )
				|| ! self::zero_optional( $row, 'total_service_charge_money', $currency ) ) {
				return self::error();
			}
			foreach ( $sums as $key => $sum ) {
				$value = 'tax' === $key ? $tax : ( 'discount' === $key ? $discount : $total );
				if ( $sum > self::MAX_MONEY - $value ) {
					return self::error();
				}
				$sums[ $key ] += $value;
			}
		}
		foreach ( array( 'tax' => 'total_tax_money', 'discount' => 'total_discount_money', 'total' => 'total_money' ) as $key => $field ) {
			if ( $sums[ $key ] !== $projection['amounts'][ $key ]
				|| self::money_amount( isset( $actual[ $field ] ) ? $actual[ $field ] : null, $currency ) !== $sums[ $key ] ) {
				return self::error();
			}
		}
		foreach ( array( 'total_tip_money', 'total_service_charge_money' ) as $field ) {
			if ( ! self::zero_optional( $actual, $field, $currency ) ) {
				return self::error();
			}
		}
		foreach ( array( 'taxes' => 'tax', 'discounts' => 'discount' ) as $field => $key ) {
			if ( ! empty( $actual[ $field ] ) && self::money_amount( isset( $actual[ $field ][0]['applied_money'] ) ? $actual[ $field ][0]['applied_money'] : null, $currency ) !== $sums[ $key ] ) {
				return self::error();
			}
		}
		return array( 'id' => $actual['id'], 'version' => $actual['version'] );
	}

	private static function adjustments_match( array $actual, array $expected, $field, $currency ) {
		$rows = array_key_exists( $field, $actual ) ? $actual[ $field ] : array();
		$wanted = isset( $expected[ $field ] ) ? $expected[ $field ] : array();
		if ( ! is_array( $rows ) || count( $rows ) !== count( $wanted ) ) {
			return false;
		}
		foreach ( $wanted as $index => $definition ) {
			if ( ! isset( $rows[ $index ] ) || ! is_array( $rows[ $index ] ) || ! self::ad_hoc( $rows[ $index ] ) ) {
				return false;
			}
			foreach ( $definition as $key => $value ) {
				if ( ! array_key_exists( $key, $rows[ $index ] )
					|| ( 'amount_money' === $key ? self::money_amount( $rows[ $index ][ $key ], $currency ) !== $value['amount'] : $rows[ $index ][ $key ] !== $value ) ) {
					return false;
				}
			}
			$allowed = array_merge( array_keys( $definition ), array( 'applied_money', 'auto_applied', 'metadata' ) );
			foreach ( $rows[ $index ] as $key => $value ) {
				if ( ! in_array( $key, $allowed, true )
					|| ( 'auto_applied' === $key && false !== $value )
					|| ( 'metadata' === $key && ! is_array( $value ) ) ) {
					return false;
				}
			}
			if ( ! empty( $rows[ $index ]['auto_applied'] ) || array_key_exists( 'pricing_rule_id', $rows[ $index ] ) || array_key_exists( 'reward_ids', $rows[ $index ] )
				|| ( 'discounts' === $field && array_key_exists( 'percentage', $rows[ $index ] ) )
				|| ( 'taxes' === $field && array_key_exists( 'amount_money', $rows[ $index ] ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** Block UIDs are supplied explicitly, never inferred from provider pricing. */
	private static function blocklists_match( array $actual, array $expected ) {
		$blocks = array_key_exists( 'pricing_blocklists', $actual ) ? $actual['pricing_blocklists'] : array();
		$wanted = isset( $expected['pricing_blocklists'] ) ? $expected['pricing_blocklists'] : array();
		if ( ! is_array( $blocks ) || ! is_array( $wanted ) ) {
			return false;
		}
		foreach ( $blocks as $key => $rows ) {
			if ( ! in_array( $key, array( 'blocked_discounts', 'blocked_taxes' ), true ) || ! is_array( $rows ) ) {
				return false;
			}
		}
		foreach ( array( 'blocked_discounts', 'blocked_taxes' ) as $key ) {
			$rows = isset( $blocks[ $key ] ) ? $blocks[ $key ] : array();
			$definitions = isset( $wanted[ $key ] ) ? $wanted[ $key ] : array();
			if ( count( $rows ) !== count( $definitions ) ) {
				return false;
			}
			foreach ( $definitions as $index => $definition ) {
				if ( ! isset( $rows[ $index ] ) || ! is_array( $rows[ $index ] ) || count( $rows[ $index ] ) !== count( $definition ) ) {
					return false;
				}
				foreach ( $definition as $field => $value ) {
					if ( ! array_key_exists( $field, $rows[ $index ] ) || $rows[ $index ][ $field ] !== $value ) {
						return false;
					}
				}
			}
		}
		return true;
	}

	private static function applied_match( array $row, $field, $reference_field, $uid, $amount, $currency ) {
		$applied = array_key_exists( $field, $row ) ? $row[ $field ] : array();
		if ( ! is_array( $applied ) ) {
			return false;
		}
		if ( '' === $uid ) {
			return empty( $applied ) && 0 === $amount;
		}
		return 1 === count( $applied ) && isset( $applied[0] ) && is_array( $applied[0] )
			&& isset( $applied[0][ $reference_field ] ) && $uid === $applied[0][ $reference_field ]
			&& self::money_amount( isset( $applied[0]['applied_money'] ) ? $applied[0]['applied_money'] : null, $currency ) === $amount;
	}

	private static function ad_hoc( array $row ) {
		foreach ( array( 'catalog_object_id', 'catalog_version', 'variation_name', 'quantity_unit' ) as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				return false;
			}
		}
		return ( ! array_key_exists( 'item_type', $row ) || 'ITEM' === $row['item_type'] )
			&& ( ! array_key_exists( 'modifiers', $row ) || ( is_array( $row['modifiers'] ) && empty( $row['modifiers'] ) ) );
	}

	private static function pricing_disabled( array $order ) {
		if ( ! array_key_exists( 'pricing_options', $order ) ) {
			return true; // Square can omit default-false options in its response.
		}
		$options = $order['pricing_options'];
		if ( ! is_array( $options ) ) {
			return false;
		}
		foreach ( $options as $key => $value ) {
			if ( ! in_array( $key, array( 'auto_apply_taxes', 'auto_apply_discounts' ), true ) || false !== $value ) {
				return false;
			}
		}
		return true;
	}

	private static function line_note( array $line ) {
		$parts = array();
		if ( array_key_exists( 'size', $line ) ) {
			if ( ! self::text( $line['size'], 80 ) ) {
				return null;
			}
			if ( '' !== $line['size'] ) {
				$parts[] = 'Size: ' . $line['size'];
			}
		}
		if ( array_key_exists( 'toppings', $line ) ) {
			if ( ! is_array( $line['toppings'] ) ) {
				return null;
			}
			foreach ( $line['toppings'] as $modifier ) {
				if ( ! is_array( $modifier ) || ! isset( $modifier['label'] ) || ! self::text( $modifier['label'], 512, false ) ) {
					return null;
				}
				$parts[] = $modifier['label'];
			}
		}
		$note = implode( "\n", $parts );
		return self::text( $note, 2000 ) ? $note : null;
	}

	private static function text( $value, $limit, $empty_allowed = true ) {
		return is_string( $value ) && ( $empty_allowed || '' !== trim( $value ) )
			&& strlen( $value ) <= $limit * 4 && 1 === preg_match( '//u', $value )
			&& ! preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value )
			&& preg_match_all( '/./us', $value ) <= $limit;
	}

	private static function quantity( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 && $value <= 2147483647 ? $value : null;
		}
		return is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,9}\z/', $value ) && (float) $value <= 2147483647 ? (int) $value : null;
	}

	/** Validate decimal storage values; never silently round a third cent digit. */
	private static function cents( $value ) {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		} elseif ( is_float( $value ) ) {
			if ( ! is_finite( $value ) || $value < 0 || $value > self::MAX_MONEY / 100 ) {
				return null;
			}
			$decimal = sprintf( '%.2F', $value );
			if ( $value !== (float) $decimal ) {
				return null;
			}
			$value = $decimal;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?\z/', $value, $parts ) ) {
			return null;
		}
		return (int) $parts[1] * 100 + ( isset( $parts[2] ) ? (int) str_pad( $parts[2], 2, '0' ) : 0 );
	}

	private static function money( $amount, $currency ) {
		return array( 'amount' => $amount, 'currency' => $currency );
	}

	private static function money_amount( $value, $currency ) {
		return is_array( $value ) && isset( $value['amount'], $value['currency'] )
			&& is_int( $value['amount'] ) && $value['amount'] >= 0 && $value['amount'] <= self::MAX_MONEY
			&& is_string( $value['currency'] ) && $currency === $value['currency'] ? $value['amount'] : null;
	}

	private static function zero_optional( array $row, $field, $currency ) {
		return ! array_key_exists( $field, $row ) || 0 === self::money_amount( $row[ $field ], $currency );
	}

	private static function error() {
		return new WP_Error( 'doughboss_square_order_facts', __( 'The itemized order could not be verified. No new payment should be started; please contact the shop.', 'doughboss' ), array( 'status' => 409 ) );
	}
}
