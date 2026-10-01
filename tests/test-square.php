<?php

if ( ! function_exists( 'wp_json_encode' ) ) {
	/** @return string|false */
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-doughboss-order.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-square-dashboard.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-square-orders.php';

/** Synthetic immutable itemization; no catalog, provider or customer records. */
function db_square_orders_projection_fixture( $inclusive = true, $discount = true, $delivery = true ) {
	$tax = $inclusive ? ( $delivery ? 212 : 182 ) : 200;
	if ( ! $discount ) {
		$tax = $inclusive ? ( $delivery ? 230 : 200 ) : 220;
	}
	$payload = array(
		'order' => array(
			'currency' => 'AUD', 'subtotal' => '22.00', 'discount' => $discount ? '2.00' : '0.00',
			'delivery_fee' => $delivery ? '3.30' : '0.00', 'tax' => $tax / 100,
			'total' => ( 2200 - ( $discount ? 200 : 0 ) + ( $delivery ? 330 : 0 ) + ( $inclusive ? 0 : $tax ) ) / 100,
			'accounting_basis' => array( 'version' => 1, 'tax_inclusive' => $inclusive, 'tax_rate' => 10,
				'discount_policy' => 'goods_before_tax', 'delivery_taxable' => $inclusive ),
		),
		'lines' => array( array( 'name' => 'Signature pizza', 'size' => 'Large', 'quantity' => '2',
			'unit_price' => '11.00', 'line_total' => '22.00',
			'toppings' => array( array( 'slug' => 'add--sujuk', 'label' => 'Add sujuk', 'price' => 2.0 ),
				array( 'slug' => 'remove--onion', 'label' => 'No onion', 'price' => 0.0 ) ),
		) ),
	);
	return array( $payload, array( 'shop_slug' => 'revesby', 'square_location_id' => 'LOCATION_R_TEST', 'square_mode' => 'test' ) );
}

/** Explicit synthetic provider rollups, not a Square calculation simulation. */
function db_square_orders_response_fixture( array $projection ) {
	$order = $projection['order'];
	$currency = $projection['currency'];
	$money = function ( $amount ) use ( $currency ) { return array( 'amount' => $amount, 'currency' => $currency ); };
	$order['id'] = 'squareORDEREXAMPLE00000001';
	$order['version'] = 1;
	$fee_tax = $projection['tax_inclusive'] && $projection['amounts']['delivery_fee'] > 0 ? 30 : 0;
	foreach ( $order['line_items'] as &$row ) {
		$is_fee = 'db-delivery' === $row['uid'];
		$base = $row['base_price_money']['amount'] * (int) $row['quantity'];
		$discount = $is_fee ? 0 : $projection['amounts']['discount'];
		$tax = $is_fee ? $fee_tax : $projection['amounts']['tax'] - $fee_tax;
		$row['item_type'] = 'ITEM';
		$row['gross_sales_money'] = $money( $base );
		$row['variation_total_price_money'] = $money( $base );
		$row['total_tax_money'] = $money( $tax );
		$row['total_discount_money'] = $money( $discount );
		$row['total_money'] = $money( $base - $discount + ( $projection['tax_inclusive'] ? 0 : $tax ) );
		if ( ! empty( $row['applied_taxes'] ) ) {
			$row['applied_taxes'][0]['uid'] = 'generated-tax-' . $row['uid'];
			$row['applied_taxes'][0]['applied_money'] = $money( $tax );
		}
		if ( ! $is_fee && ! empty( $order['discounts'] ) ) {
			$row['applied_discounts'] = array( array( 'uid' => 'generated-discount', 'discount_uid' => 'db-discount', 'applied_money' => $money( $discount ) ) );
		}
	}
	unset( $row );
	foreach ( array( 'tax' => 'total_tax_money', 'discount' => 'total_discount_money', 'total' => 'total_money' ) as $key => $field ) {
		$order[ $field ] = $money( $projection['amounts'][ $key ] );
	}
	foreach ( array( 'taxes' => 'tax', 'discounts' => 'discount' ) as $field => $key ) {
		if ( ! empty( $order[ $field ] ) ) {
			$order[ $field ][0]['applied_money'] = $money( $projection['amounts'][ $key ] );
		}
	}
	return array( 'order' => $order );
}

db_test( 'Square Orders project historical itemization without charging modifiers twice', function () {
	foreach ( array( true, false ) as $inclusive ) {
		foreach ( array( true, false ) as $discount ) {
			foreach ( array( true, false ) as $delivery ) {
				list( $payload, $binding ) = db_square_orders_projection_fixture( $inclusive, $discount, $delivery );
				$projection = DoughBoss_Square_Orders::project( $payload, $binding, 37 );
				assert_false( is_wp_error( $projection ), 'inclusive/exclusive discount/delivery combinations project' );
				if ( is_wp_error( $projection ) ) { continue; }
				assert_same( 1100, $projection['order']['line_items'][0]['base_price_money']['amount'], 'final unit price includes sujuk once' );
				assert_same( "Size: Large\nAdd sujuk\nNo onion", $projection['order']['line_items'][0]['note'], 'all saved choices are retained without repricing' );
				assert_same( 'db-attempt-37', $projection['order']['reference_id'], 'separate Order is correlated to original attempt' );
				assert_same( 'OPEN', $projection['order']['state'], 'the planned Order is payable, never a DRAFT' );
				assert_false( isset( $projection['order']['fulfillments'], $projection['order']['customer_id'] ), 'projection makes no fulfillment/customer promise' );
				$response = db_square_orders_response_fixture( $projection );
				assert_same( array( 'id' => 'squareORDEREXAMPLE00000001', 'version' => 1 ), DoughBoss_Square_Orders::validate_unpaid_response( $response, $projection ), 'exact synthetic item/adjustment rollups validate' );
			}
		}
	}
	list( $payload, $binding ) = db_square_orders_projection_fixture( true, false, false );
	$payload['order']['accounting_basis']['tax_rate'] = 0;
	$payload['order']['tax'] = 0;
	$projection = DoughBoss_Square_Orders::project( $payload, $binding, 37 );
	assert_false( isset( $projection['order']['taxes'], $projection['order']['discounts'] ), 'zero-rate/no-discount omit synthetic adjustments' );
	assert_false( is_wp_error( DoughBoss_Square_Orders::validate_unpaid_response( db_square_orders_response_fixture( $projection ), $projection ) ), 'zero adjustment response is supported' );
} );

db_test( 'Square paid Order verification requires exact completed Payment and one matching tender', function () {
	list( $payload, $binding ) = db_square_orders_projection_fixture();
	$projection = DoughBoss_Square_Orders::project( $payload, $binding, 37 );
	$paid = db_square_orders_response_fixture( $projection );
	$id = $paid['order']['id'];
	$payment = array( 'id' => 'sqpayORDERSVERIFIED00001', 'status' => 'COMPLETED',
		'order_id' => $id, 'location_id' => $binding['square_location_id'], 'reference_id' => 'db-attempt-37',
		'amount_money' => array( 'amount' => $projection['amounts']['total'], 'currency' => 'AUD' ) );
	$paid['order']['state'] = 'COMPLETED';
	$paid['order']['tenders'] = array( array( 'payment_id' => $payment['id'], 'type' => 'CARD', 'amount_money' => $payment['amount_money'] ) );
	$paid['order']['net_amount_due_money'] = array( 'amount' => 0, 'currency' => 'AUD' );
	foreach ( array( 'OPEN', 'COMPLETED' ) as $state ) {
		$good = $paid; $good['order']['state'] = $state;
		assert_same( array( 'id' => $id, 'version' => 1 ), DoughBoss_Square_Orders::validate_paid_response( $good, $projection, $payment, $id ), 'paid economics validate without using Order state as payment authority' );
		assert_true( is_wp_error( DoughBoss_Square_Orders::validate_unpaid_response( $good, $projection ) ), 'a paid Order cannot satisfy the unpaid dispatch check' );
	}
	foreach ( array( 'id', 'status', 'order_id', 'location_id', 'reference_id', 'amount_money' ) as $field ) {
		$bad = $payment; unset( $bad[ $field ] );
		assert_true( is_wp_error( DoughBoss_Square_Orders::validate_paid_response( $paid, $projection, $bad, $id ) ), 'missing Payment ' . $field . ' cannot confirm a linked Order' );
	}
	foreach ( array( 'APPROVED', 'PENDING', 'FAILED', 'CANCELED', 'completed', '', null ) as $status ) {
		$bad = $payment; $bad['status'] = $status;
		assert_true( is_wp_error( DoughBoss_Square_Orders::validate_paid_response( $paid, $projection, $bad, $id ) ), 'anything except exact provider COMPLETED denies paid verification' );
	}
	foreach ( array( 'id' => 'sqpayANOTHERPAYMENT0001', 'order_id' => 'squareANOTHERORDER00001', 'location_id' => 'OTHER_LOCATION', 'reference_id' => 'db-attempt-38' ) as $field => $value ) {
		$bad = $payment; $bad[ $field ] = $value;
		assert_true( is_wp_error( DoughBoss_Square_Orders::validate_paid_response( $paid, $projection, $bad, $id ) ), 'changed Payment identity/binding ' . $field . ' denies paid verification' );
	}
	$invalid = array();
	foreach ( array( 'DRAFT', 'CANCELED', 'completed', null ) as $state ) { $bad = $paid; $bad['order']['state'] = $state; $invalid[] = $bad; }
	foreach ( array( null, array(), array( $paid['order']['tenders'][0], $paid['order']['tenders'][0] ) ) as $tenders ) { $bad = $paid; $bad['order']['tenders'] = $tenders; $invalid[] = $bad; }
	foreach ( array( 'payment_id' => 'sqpayOTHER00000001', 'type' => 'CASH', 'amount_money' => array( 'amount' => $projection['amounts']['total'] - 1, 'currency' => 'AUD' ) ) as $field => $value ) {
		$bad = $paid; $bad['order']['tenders'][0][ $field ] = $value; $invalid[] = $bad;
	}
	$bad = $paid; $bad['order']['net_amount_due_money']['amount'] = 1; $invalid[] = $bad;
	$bad = $paid; $bad['order']['tenders'][0]['tip_money'] = array( 'amount' => 1, 'currency' => 'AUD' ); $invalid[] = $bad;
	$bad = $paid; $bad['order']['line_items'][0]['name'] = 'Changed after payment'; $invalid[] = $bad;
	$bad = $paid; $bad['order']['line_items'][0]['note'] = 'Modifiers changed after payment'; $invalid[] = $bad;
	$bad = $paid; $bad['order']['discounts'][0]['amount_money']['amount'] -= 1; $invalid[] = $bad;
	$bad = $paid; $bad['order']['taxes'][0]['percentage'] = '11'; $invalid[] = $bad;
	$bad = $paid; $bad['order']['fulfillments'] = array( array( 'type' => 'PICKUP' ) ); $invalid[] = $bad;
	$bad = $paid; $bad['order']['refunds'] = array( array( 'amount_money' => $payment['amount_money'] ) ); $invalid[] = $bad;
	foreach ( $invalid as $index => $bad ) {
		assert_true( is_wp_error( DoughBoss_Square_Orders::validate_paid_response( $bad, $projection, $payment, $id ) ), 'post-payment drift ' . $index . ' remains held even with matching final total' );
	}
	foreach ( array( 'amount' => '2330', 'currency' => 'USD' ) as $field => $value ) {
		$bad = $payment; $bad['amount_money'][ $field ] = $value;
		assert_true( is_wp_error( DoughBoss_Square_Orders::validate_paid_response( $paid, $projection, $bad, $id ) ), 'malformed Payment money cannot confirm linked Order' );
	}
	assert_true( is_wp_error( DoughBoss_Square_Orders::validate_paid_response( $paid, $projection, $payment, 'squareWRONGBINDING0001' ) ), 'different immutable bound Order identity denies verification' );
} );

db_test( 'Square Orders deny unknown or inconsistent immutable checkout facts', function () {
	list( $payload, $binding ) = db_square_orders_projection_fixture();
	$invalid = array();
	foreach ( array( null, array(), array( 'version' => 2 ) ) as $basis ) {
		$bad = $payload; $bad['order']['accounting_basis'] = $basis; $invalid[] = $bad;
	}
	$bad = $payload; unset( $bad['order']['accounting_basis'] ); $invalid[] = $bad;
	foreach ( array( true, '1e1', '1.001', 0.0000000001, INF, NAN, -1, '100000000.00', array() ) as $price ) {
		$bad = $payload; $bad['lines'][0]['unit_price'] = $price; $invalid[] = $bad;
	}
	foreach ( array( 0, -1, '02', '2.0', 2.1, true, 2147483648, array() ) as $quantity ) {
		$bad = $payload; $bad['lines'][0]['quantity'] = $quantity; $invalid[] = $bad;
	}
	foreach ( array( 'subtotal' => '23.00', 'discount' => '23.00', 'total' => '23.31', 'tax' => '999.00', 'currency' => 'aud' ) as $key => $value ) {
		$bad = $payload; $bad['order'][ $key ] = $value; $invalid[] = $bad;
	}
	$bad = $payload; $bad['lines'][0]['line_total'] = '22.01'; $invalid[] = $bad;
	$bad = $payload; $bad['lines'][0]['toppings'][0]['label'] = array(); $invalid[] = $bad;
	$bad = $payload; $bad['lines'][0]['toppings'] = '[malformed]'; $invalid[] = $bad;
	$bad = $payload; $bad['lines'][0]['name'] = "\xff"; $invalid[] = $bad;
	$bad = $payload; $bad['lines'][0]['size'] = str_repeat( 'x', 81 ); $invalid[] = $bad;
	$bad = $payload; $bad['lines'][0]['toppings'] = array_fill( 0, 5, array( 'label' => str_repeat( 'x', 500 ) ) ); $invalid[] = $bad;
	$bad = $payload; $bad['order']['accounting_basis']['tax_rate'] = 0.000000001; $invalid[] = $bad;
	foreach ( $invalid as $index => $bad ) {
		assert_true( is_wp_error( DoughBoss_Square_Orders::project( $bad, $binding, 37 ) ), 'invalid persisted input ' . $index . ' cannot be itemized' );
	}
	foreach ( array( 'shop_slug' => 'unannounced', 'square_location_id' => '<wrong>', 'square_mode' => 'production' ) as $key => $value ) {
		$bad_binding = $binding; $bad_binding[ $key ] = $value;
		assert_true( is_wp_error( DoughBoss_Square_Orders::project( $payload, $bad_binding, 37 ) ), 'invalid/private shop binding is refused' );
	}
	assert_true( is_wp_error( DoughBoss_Square_Orders::project( $payload, $binding, '37' ) ), 'attempt identity is not coerced' );
} );

db_test( 'Square Orders equal final totals cannot conceal altered provider facts', function () {
	list( $payload, $binding ) = db_square_orders_projection_fixture();
	$projection = DoughBoss_Square_Orders::project( $payload, $binding, 37 );
	$good = db_square_orders_response_fixture( $projection );
	$invalid = array();
	foreach ( array( 'id' => array(), 'version' => '1', 'location_id' => 'OTHER_LOCATION', 'reference_id' => 'db-attempt-38', 'state' => 'COMPLETED' ) as $key => $value ) {
		$bad = $good; $bad['order'][ $key ] = $value; $invalid[] = $bad;
	}
	foreach ( array( 'fulfillments', 'tenders', 'refunds', 'returns', 'rewards', 'service_charges' ) as $key ) {
		$bad = $good; $bad['order'][ $key ] = array( array( 'unexpected' => true ) ); $invalid[] = $bad;
		$bad = $good; $bad['order'][ $key ] = null; $invalid[] = $bad;
	}
	foreach ( array( 'uid' => 'other-line', 'quantity' => '3', 'name' => 'Different pizza', 'note' => 'No choices', 'modifiers' => array( array( 'name' => 'Double-charged sujuk' ) ), 'catalog_object_id' => 'MUTABLE_CATALOG', 'item_type' => 'GIFT_CARD' ) as $key => $value ) {
		$bad = $good; $bad['order']['line_items'][0][ $key ] = $value; $invalid[] = $bad;
	}
	$bad = $good; $bad['order']['line_items'][1]['uid'] = 'db-line-1'; $invalid[] = $bad;
	$bad = $good; $bad['order']['line_items'][] = $bad['order']['line_items'][0]; $invalid[] = $bad;
	$bad = $good; $bad['order']['line_items'][0]['base_price_money']['amount'] = 1200; $invalid[] = $bad;
	$bad = $good; $bad['order']['line_items'][0]['base_price_money']['currency'] = 'USD'; $invalid[] = $bad;
	$bad = $good; $bad['order']['line_items'][0]['applied_taxes'][0]['tax_uid'] = 'other-tax'; $invalid[] = $bad;
	$bad = $good; $bad['order']['line_items'][0]['applied_discounts'][0]['applied_money']['amount'] = 199; $invalid[] = $bad;
	$bad = $good; $bad['order']['line_items'][1]['total_discount_money']['amount'] = 1; $invalid[] = $bad;
	$bad = $good; $bad['order']['taxes'][0]['percentage'] = '11'; $invalid[] = $bad;
	$bad = $good; $bad['order']['taxes'][0]['scope'] = 'ORDER'; $invalid[] = $bad;
	$bad = $good; $bad['order']['discounts'][0]['amount_money']['amount'] = 201; $invalid[] = $bad;
	$bad = $good; $bad['order']['discounts'][0]['pricing_rule_id'] = 'RULE'; $invalid[] = $bad;
	$bad = $good; $bad['order']['discounts'][0]['reward_ids'] = array( 'REWARD' ); $invalid[] = $bad;
	$bad = $good; $bad['order']['taxes'][0]['auto_applied'] = true; $invalid[] = $bad;
	$bad = $good; $bad['order']['total_tax_money']['amount'] = 213; $invalid[] = $bad;
	$bad = $good; $bad['order']['total_money']['amount'] = '2330'; $invalid[] = $bad;
	$bad = $good; $bad['order']['total_money']['amount'] = 2330.0; $invalid[] = $bad;
	$bad = $good; $bad['order']['total_tip_money'] = array( 'amount' => 1, 'currency' => 'AUD' ); $invalid[] = $bad;
	$bad = $good; $bad['order']['pricing_options']['auto_apply_taxes'] = true; $invalid[] = $bad;
	$bad = $good; $bad['order']['pricing_options']['auto_apply_rewards'] = false; $invalid[] = $bad;
	$bad = $good; $bad['order']['taxes'][0]['effective_percentage'] = '11'; $invalid[] = $bad;
	$bad = $good; unset( $bad['order']['line_items'][1]['pricing_blocklists'] ); $invalid[] = $bad;
	$bad = $good; $bad['order']['line_items'][1]['pricing_blocklists']['blocked_discounts'][0]['discount_uid'] = 'other-discount'; $invalid[] = $bad;
	$bad = $good; $bad['order']['line_items'][1]['pricing_blocklists']['blocked_discounts'][0]['uid'] = 'other-block'; $invalid[] = $bad;
	$bad = $good; $bad['order']['line_items'][0]['pricing_blocklists'] = array( 'blocked_taxes' => array( array( 'tax_uid' => 'db-tax' ) ) ); $invalid[] = $bad;
	$bad = $good; $bad['order']['version'] = 2147483648; $invalid[] = $bad;
	$bad = $good; $bad['order']['rounding_adjustment'] = null; $invalid[] = $bad;
	$bad = $good; $bad['errors'] = array( array( 'code' => 'ERROR' ) ); $invalid[] = $bad;
	foreach ( $invalid as $index => $bad ) {
		assert_true( is_wp_error( DoughBoss_Square_Orders::validate_unpaid_response( $bad, $projection ) ), 'provider mismatch ' . $index . ' fails even when order total is unchanged' );
	}
	$good['order']['line_items'] = array_reverse( $good['order']['line_items'] );
	unset( $good['order']['pricing_options'] );
	assert_false( is_wp_error( DoughBoss_Square_Orders::validate_unpaid_response( $good, $projection ) ), 'provider line order/default-false omission do not alter UID-bound facts' );
} );

db_test( 'Square Orders missing zero-value adjustment evidence is not integer zero', function () {
	list( $payload, $binding ) = db_square_orders_projection_fixture( true, false, false );
	foreach ( array( 'subtotal', 'discount', 'tax', 'delivery_fee', 'total' ) as $field ) { $payload['order'][ $field ] = 0; }
	$payload['lines'][0]['unit_price'] = 0;
	$payload['lines'][0]['line_total'] = 0;
	$projection = DoughBoss_Square_Orders::project( $payload, $binding, 37 );
	$good = db_square_orders_response_fixture( $projection );
	assert_false( is_wp_error( DoughBoss_Square_Orders::validate_unpaid_response( $good, $projection ) ), 'zero amounts still require explicit valid applied money' );
	foreach ( array( null, array( 'amount' => null, 'currency' => 'AUD' ), array( 'amount' => '0', 'currency' => 'AUD' ) ) as $value ) {
		$bad = $good; $bad['order']['line_items'][0]['applied_taxes'][0]['applied_money'] = $value;
		assert_true( is_wp_error( DoughBoss_Square_Orders::validate_unpaid_response( $bad, $projection ) ), 'malformed or absent money cannot compare as strict integer zero' );
	}
	$bad = $good; unset( $bad['order']['line_items'][0]['applied_taxes'][0]['applied_money'] );
	assert_true( is_wp_error( DoughBoss_Square_Orders::validate_unpaid_response( $bad, $projection ) ), 'missing applied money fails closed, including zero' );
	foreach ( array( array(), array( 'order' => array(), 'amounts' => array(), 'currency' => 'AUD', 'tax_inclusive' => true ) ) as $invalid_projection ) {
		assert_true( is_wp_error( DoughBoss_Square_Orders::validate_unpaid_response( $good, $invalid_projection ) ), 'incomplete internal projection returns an error rather than partial verification' );
	}
} );

db_test( 'Order accounting basis codec preserves only canonical historical facts', function () {
	$basis = array(
		'version'          => 1,
		'tax_inclusive'    => true,
		'tax_rate'         => 10,
		'discount_policy'  => 'goods_before_tax',
		'delivery_taxable' => true,
	);
	$normalized = array(
		'version'          => 1,
		'tax_inclusive'    => true,
		'tax_rate'         => 10.0,
		'discount_policy'  => 'goods_before_tax',
		'delivery_taxable' => true,
	);

	assert_same( $normalized, DoughBoss_Order::normalize_accounting_basis( $basis ), 'the exact five accounting fields normalise in literal order' );
	assert_same( false, DoughBoss_Order::normalize_accounting_basis( array_merge( $basis, array( 'tax_inclusive' => false, 'delivery_taxable' => false ) ) )['tax_inclusive'], 'exclusive policy accepts matching boolean tax and delivery flags' );
	assert_same( $normalized, DoughBoss_Order::normalize_accounting_basis( array_merge( $basis, array( 'tax_rate' => 10.0 ) ) ), 'integer and float rates normalise identically' );
	assert_same( 0.0, DoughBoss_Order::normalize_accounting_basis( array_merge( $basis, array( 'tax_rate' => -0.0 ) ) )['tax_rate'], 'negative zero normalises to the same zero rate' );
	assert_same( 7.125, DoughBoss_Order::normalize_accounting_basis( array_merge( $basis, array( 'tax_rate' => 7.125 ) ) )['tax_rate'], 'fractional rates retain their exact supplied precision' );
	assert_same( 999999.9999, DoughBoss_Order::normalize_accounting_basis( array_merge( $basis, array( 'tax_rate' => 999999.9999 ) ) )['tax_rate'], 'the codec imposes no arbitrary maximum tax rate' );

	$invalid_bases = array(
		array(),
		array_diff_key( $basis, array( 'delivery_taxable' => true ) ),
		array_merge( $basis, array( 'extra' => true ) ),
		array_merge( $basis, array( 'version' => 2 ) ),
		array_merge( $basis, array( 'version' => '1' ) ),
		array_merge( $basis, array( 'version' => true ) ),
		array_merge( $basis, array( 'tax_inclusive' => 'true' ) ),
		array_merge( $basis, array( 'delivery_taxable' => false ) ),
		array_merge( $basis, array( 'discount_policy' => 'delivery_after_tax' ) ),
		array_merge( $basis, array( 'tax_rate' => '10' ) ),
		array_merge( $basis, array( 'tax_rate' => true ) ),
		array_merge( $basis, array( 'tax_rate' => -0.01 ) ),
		array_merge( $basis, array( 'tax_rate' => INF ) ),
		array_merge( $basis, array( 'tax_rate' => NAN ) ),
	);
	foreach ( $invalid_bases as $invalid_basis ) {
		assert_true( is_wp_error( DoughBoss_Order::normalize_accounting_basis( $invalid_basis ) ), 'missing, extra, version, policy, mode, and non-finite rate facts fail closed' );
	}

	$encoded = DoughBoss_Order::encode_accounting_basis( $basis );
	assert_same( '{"version":1,"tax_inclusive":true,"tax_rate":10,"discount_policy":"goods_before_tax","delivery_taxable":true}', $encoded, 'canonical encoding preserves the frozen literal key order' );
	assert_same( $encoded, DoughBoss_Order::encode_accounting_basis( array_merge( $basis, array( 'tax_rate' => 10.0 ) ) ), 'integer and float rate encodings are canonical equivalents' );
	assert_same( $normalized, DoughBoss_Order::decode_accounting_basis( $encoded ), 'canonical stored JSON decodes to the normalised object' );
	assert_same( null, DoughBoss_Order::decode_accounting_basis( null ), 'only SQL NULL stored basis remains legacy unknown' );

	$noncanonical_stored = array(
		'',
		' ',
		'null',
		'true',
		'10',
		'"basis"',
		'[]',
		'{}',
		'{',
		'{"version":1,"tax_inclusive":true,"tax_rate":10,"tax_rate":10,"discount_policy":"goods_before_tax","delivery_taxable":true}',
		' ' . $encoded,
		'{"tax_rate":10,"version":1,"tax_inclusive":true,"discount_policy":"goods_before_tax","delivery_taxable":true}',
	);
	foreach ( $noncanonical_stored as $stored ) {
		assert_true( is_wp_error( DoughBoss_Order::decode_accounting_basis( $stored ) ), 'non-NULL scalar, malformed, duplicate, whitespace, and noncanonical stored JSON fails closed' );
	}

	assert_same( null, DoughBoss_Order::accounting_basis_from_payload( array() ), 'a payload with no basis key remains unknown' );
	assert_true( is_wp_error( DoughBoss_Order::accounting_basis_from_payload( array( 'accounting_basis' => null ) ) ), 'an explicit NULL transport basis is invalid rather than legacy unknown' );
	assert_same( $normalized, DoughBoss_Order::accounting_basis_from_payload( array( 'accounting_basis' => $basis ) ), 'a present valid transport basis normalises' );
	assert_same( $normalized, DoughBoss_Order::accounting_basis_from_order( (object) array( 'accounting_basis_json' => $encoded ) ), 'a canonical persisted order basis is available for snapshots' );
	assert_same( null, DoughBoss_Order::accounting_basis_from_order( (object) array() ), 'an absent legacy order field remains unknown' );
	assert_true( is_wp_error( DoughBoss_Order::accounting_basis_from_order( (object) array( 'accounting_basis_json' => '{}' ) ) ), 'a malformed non-NULL persisted basis is never legacy unknown' );

	$known_order   = (object) array( 'accounting_basis_json' => $encoded );
	$legacy_order  = (object) array( 'accounting_basis_json' => null );
	$malformed_order = (object) array( 'accounting_basis_json' => '{}' );
	assert_true( DoughBoss_Order::accounting_basis_matches_payload( $known_order, array( 'accounting_basis' => $basis ) ), 'known canonical order and payload basis match' );
	assert_false( DoughBoss_Order::accounting_basis_matches_payload( $known_order, array() ), 'known order basis cannot match an unknown payload' );
	assert_true( DoughBoss_Order::accounting_basis_matches_payload( $legacy_order, array() ), 'legacy unknown order and absent payload basis match' );
	assert_false( DoughBoss_Order::accounting_basis_matches_payload( $legacy_order, array( 'accounting_basis' => $basis ) ), 'legacy unknown order cannot match a newly supplied basis' );
	assert_false( DoughBoss_Order::accounting_basis_matches_payload( $known_order, array( 'accounting_basis' => null ) ), 'explicit NULL payload basis holds rather than matching' );
	assert_false( DoughBoss_Order::accounting_basis_matches_payload( $malformed_order, array( 'accounting_basis' => $basis ) ), 'malformed persisted basis holds rather than matching' );
} );

// Synthetic dashboard figures must never masquerade as connected provider data.
$dashboard_original_settings = get_option( 'doughboss_settings', array() );
doughboss_test_reset_options();
$dashboard_status = DoughBoss_Square_Dashboard::view();
assert_same( 'not_connected', $dashboard_status['data_state'], 'dashboard starts disconnected' );
assert_same( 'not_configured', $dashboard_status['connection']['state'], 'missing credentials are explicit' );
assert_false( isset( $dashboard_status['summary'] ), 'disconnected view has no invented revenue' );
assert_false( $dashboard_status['staff']['attendance_available'], 'Square attendance is not simulated as connected' );
assert_same( 'private_unmapped', $dashboard_status['planned_location']['state'], 'new location stays private and unmapped' );
assert_same( 3, count( $dashboard_status['locations'] ), 'only the three confirmed shops enter selectors' );
$dashboard_all = DoughBoss_Square_Dashboard::view( 'demo' );
assert_same( 'synthetic', $dashboard_all['source'], 'demo identifies synthetic source' );
assert_same( 'demo', $dashboard_all['data_state'], 'demo state is explicit' );
assert_same( 12, count( $dashboard_all['orders'] ), 'demo includes four fictional orders per confirmed shop' );
$dashboard_sum = array( 'gross_sales_minor' => 0, 'refunds_minor' => 0, 'net_sales_minor' => 0, 'orders' => 0 );
foreach ( array( 'revesby', 'bankstown', 'roselands' ) as $dashboard_shop ) {
	$dashboard_filtered = DoughBoss_Square_Dashboard::view( 'demo', $dashboard_shop );
	assert_same( 1, count( $dashboard_filtered['location_summaries'] ), 'location selection isolates its summary' );
	assert_same( 4, count( $dashboard_filtered['orders'] ), 'location selection isolates its fictional orders' );
	foreach ( $dashboard_filtered['orders'] as $dashboard_order ) {
		assert_same( $dashboard_shop, $dashboard_order['location_id'], 'no cross-location order disclosure in selected view' );
		$dashboard_line_total = 0;
		foreach ( $dashboard_order['items'] as $dashboard_item ) {
			$dashboard_line_total += $dashboard_item['quantity'] * $dashboard_item['unit_price_minor'];
		}
		assert_same( $dashboard_line_total, $dashboard_order['amount_minor'], 'order total equals integer-cents line totals' );
	}
	assert_same( 3, $dashboard_filtered['summary']['orders'], 'unpaid request is excluded from paid sales count' );
	foreach ( $dashboard_sum as $dashboard_key => $dashboard_value ) {
		$dashboard_sum[ $dashboard_key ] += $dashboard_filtered['summary'][ $dashboard_key ];
	}
}
foreach ( $dashboard_sum as $dashboard_key => $dashboard_value ) {
	assert_same( $dashboard_value, $dashboard_all['summary'][ $dashboard_key ], 'all-shops total is sum of each shop for ' . $dashboard_key );
}
assert_same( $dashboard_all['summary']['gross_sales_minor'], array_sum( array_column( $dashboard_all['sales_series'], 'gross_sales_minor' ) ), 'chart totals reconcile to gross sales' );
assert_same( 1317, $dashboard_all['summary']['average_order_minor'], 'average order uses integer HALF_UP rounding' );
foreach ( array( array( 'live', 'all' ), array( 'demo', 'planned' ), array( 'demo', '../revesby' ), array( array(), 'all' ), array( 'demo', array() ) ) as $dashboard_bad ) {
	assert_true( is_wp_error( DoughBoss_Square_Dashboard::view( $dashboard_bad[0], $dashboard_bad[1] ) ), 'invalid source or location is denied, never inferred' );
}
assert_false( isset( $dashboard_all['access_token'] ), 'dashboard contract contains no token field' );
doughboss_test_set_settings( $dashboard_original_settings );

db_test( 'Square shop bindings are explicit, bounded and distinct', function () {
	assert_same( array(), DoughBoss_Settings::defaults()['square_test_location_map'], 'sandbox bindings start empty' );
	assert_same( array(), DoughBoss_Settings::defaults()['square_live_location_map'], 'production bindings start empty' );
	foreach ( array( null, false, 42, 'LUNSCOPED' ) as $invalid ) {
		assert_same( array(), DoughBoss_Settings::sanitize_square_location_map( $invalid ), 'malformed persisted map fails closed' );
	}
	$map = DoughBoss_Settings::sanitize_square_location_map( array(
		'revesby' => ' LDEMO_REVESBY ', 'bankstown' => 'LDEMO_BANKSTOWN', 'roselands' => 'LDEMO_ROSELANDS',
		'planned' => 'LPRIVATE', 'unknown' => 'LUNKNOWN',
	) );
	assert_same( array( 'revesby' => 'LDEMO_REVESBY', 'bankstown' => 'LDEMO_BANKSTOWN', 'roselands' => 'LDEMO_ROSELANDS' ), $map, 'only confirmed shops have explicit bindings' );
	foreach ( array( '', 'abc', str_repeat( 'L', 65 ), "LVALID\nINVALID", '<script>', array( 'LARRAY' ), 12345 ) as $invalid ) {
		assert_same( array(), DoughBoss_Settings::sanitize_square_location_map( array( 'revesby' => $invalid ) ), 'invalid provider ID cannot become a mapping' );
	}
	assert_same( array( 'roselands' => 'LUNIQUE' ), DoughBoss_Settings::sanitize_square_location_map( array(
		'revesby' => 'LDUPLICATE', 'bankstown' => 'LDUPLICATE', 'roselands' => 'LUNIQUE',
	) ), 'both ambiguous bindings are removed, not first-wins' );
} );

db_test( 'Square mapping status is environment-isolated and never connected evidence', function () {
	$original = get_option( 'doughboss_settings', array() );
	try {
		doughboss_test_set_settings( db_square_settings( array(
			'square_test_location_map' => array( 'revesby' => 'LTEST_REVESBY', 'bankstown' => 'LTEST_BANKSTOWN' ),
			'square_live_location_map' => array( 'roselands' => 'LLIVE_ROSELANDS' ),
		) ) );
		assert_same( 'LTEST_REVESBY', DoughBoss_Settings::square_shop_location_id( 'revesby' ), 'exact sandbox shop resolves' );
		assert_same( '', DoughBoss_Settings::square_shop_location_id( 'roselands' ), 'live ID never fills a sandbox gap' );
		foreach ( array( 'Revesby', 'revesby ', '../revesby', 'planned', 'unknown', 1, null, array() ) as $unknown ) {
			assert_same( '', DoughBoss_Settings::square_shop_location_id( $unknown ), 'unknown or non-exact shop denied' );
		}
		$status = DoughBoss_Square_Dashboard::view();
		assert_same( 'configured_unverified', $status['locations'][0]['state'], 'saved ID remains unverified' );
		assert_same( 'unmapped', $status['locations'][2]['state'], 'missing shop never inherits legacy global ID' );
		assert_same( 'not_connected', $status['data_state'], 'mapping does not attest a connection' );
		assert_false( $status['connection']['provider_verified'], 'no provider verification is invented' );
		assert_false( isset( $status['summary'] ), 'mapping never creates revenue' );
		assert_false( isset( $status['locations'][0]['square_location_id'] ), 'dashboard does not disclose provider IDs' );
		assert_same( 'private_unmapped', $status['planned_location']['state'], 'planned shop stays private' );
		doughboss_test_set_settings( db_square_settings( array(
			'square_mode' => 'live', 'square_live_location_map' => array( 'roselands' => 'LLIVE_ROSELANDS' ),
			'square_test_location_map' => array( 'revesby' => 'LTEST_REVESBY' ),
		) ) );
		assert_same( '', DoughBoss_Settings::square_shop_location_id( 'revesby' ), 'sandbox ID cannot enter live map' );
		assert_same( 'LLIVE_ROSELANDS', DoughBoss_Settings::square_shop_location_id( 'roselands' ), 'only explicit live binding resolves' );
	} finally {
		doughboss_test_set_settings( $original );
	}
} );

db_test( 'Square v2 native shop binding is exact, active and map-scoped', function () {
	$original = get_option( 'doughboss_settings', array() );
	$method   = new ReflectionMethod( 'DoughBoss_Square', 'shop_binding_from_location' );
	$method->setAccessible( true );
	try {
		doughboss_test_set_settings( db_square_settings( array(
			'square_test_location_id'  => 'LLEGACYGLOBAL',
			'square_test_location_map' => array( 'revesby' => 'LTEST_REVESBY', 'bankstown' => 'LTEST_BANKSTOWN' ),
		) ) );
		$revesby = (object) array( 'id' => 7, 'slug' => 'revesby', 'is_active' => 1 );
		assert_same(
			array( 'shop_slug' => 'revesby', 'square_location_id' => 'LTEST_REVESBY', 'square_mode' => 'test' ),
			$method->invoke( null, $revesby, 7 ),
			'an active native shop resolves only its exact active-mode mapping'
		);

		foreach ( array(
			(object) array( 'id' => 7, 'slug' => 'Revesby', 'is_active' => 1 ),
			(object) array( 'id' => 7, 'slug' => 'revesby', 'is_active' => 0 ),
			(object) array( 'id' => 7, 'slug' => 'planned', 'is_active' => 1 ),
			(object) array( 'id' => 8, 'slug' => 'revesby', 'is_active' => 1 ),
			null,
		) as $invalid_location ) {
			assert_same( 'doughboss_pay_shop_binding', $method->invoke( null, $invalid_location, 7 )->get_error_code(), 'inactive, unknown, mismatched or non-exact native shop fails closed' );
		}

		$roselands = (object) array( 'id' => 9, 'slug' => 'roselands', 'is_active' => 1 );
		assert_same( 'doughboss_pay_shop_binding', $method->invoke( null, $roselands, 9 )->get_error_code(), 'a supported shop without an active-mode mapping never falls back to the global Square location' );
	} finally {
		doughboss_test_set_settings( $original );
	}
} );

db_test( 'Square v2 payment payload requires its saved shop location binding', function () {
	$method = new ReflectionMethod( 'DoughBoss_Square', 'payment_payload' );
	$method->setAccessible( true );
	$attempt = array(
		'id' => 42,
		'safe_metadata_json' => json_encode( array(
			'square_shop_slug'   => 'revesby',
			'square_location_id' => 'LTEST_REVESBY',
			'square_mode'        => 'test',
		) ),
	);
	$matching = db_square_payment( 2750, 'AUD', 'db-attempt-42' );
	$matching['location_id'] = 'LTEST_REVESBY';
	assert_false( is_wp_error( $method->invoke( null, $matching, $attempt, 2750, 'AUD' ) ), 'provider payment at the saved shop location is accepted' );

	$foreign = $matching;
	$foreign['location_id'] = 'LTEST_BANKSTOWN';
	assert_same( 'doughboss_pay_create', $method->invoke( null, $foreign, $attempt, 2750, 'AUD' )->get_error_code(), 'provider location must match the immutable saved shop binding' );

	$legacy = $attempt;
	$legacy['safe_metadata_json'] = json_encode( array( 'square_location_id' => 'LTEST_REVESBY', 'square_mode' => 'test' ) );
	assert_same( 'doughboss_pay_create', $method->invoke( null, $matching, $legacy, 2750, 'AUD' )->get_error_code(), 'a v2 response cannot be accepted without a saved shop slug' );
} );

db_test( 'legacy Square transport retains its global location gate', function () {
	$original = get_option( 'doughboss_settings', array() );
	$method   = new ReflectionMethod( 'DoughBoss_Square', 'request' );
	$method->setAccessible( true );
	try {
		doughboss_test_set_settings( db_square_settings( array(
			'square_test_location_id'  => '',
			'square_test_location_map' => array( 'revesby' => 'LTEST_REVESBY' ),
		) ) );
		$result = $method->invoke( null, 'GET', '/v2/payments/sqpaymentEXAMPLE0000000001', array() );
		assert_same( 'doughboss_pay_config', is_wp_error( $result ) ? $result->get_error_code() : '', 'a legacy request without an explicit mapped binding remains blocked when the global location is blank' );
	} finally {
		doughboss_test_set_settings( $original );
	}
} );

/**
 * DoughBoss_Square + Square settings tests.
 *
 * These cover the money-safety invariants that can be proven without a live
 * Square account, a WordPress runtime or a database:
 *
 *   - minor-unit conversion, including GST-inclusive AUD totals and rounding;
 *   - webhook signature verification (accept / tamper / wrong key / no header);
 *   - the amount, currency and binding assertion that refuses a mismatch;
 *   - the not-enabled / not-configured / wrong-mode short-circuits;
 *   - Square's 45-character idempotency-key contract and its stability.
 *
 * Anything requiring an HTTP round trip to Square or a payment_attempts row is
 * NOT covered here and is listed as unverified in the report.
 *
 * @package DoughBoss
 */

/* -------------------------------------------------------------------------- */
/* Fixtures                                                                    */
/* -------------------------------------------------------------------------- */

/**
 * A complete, working sandbox configuration.
 *
 * @param array $overrides Keys to change.
 * @return array
 */
function db_square_settings( array $overrides = array() ) {
	return array_merge(
		array(
			'payments_enabled'          => 1,
			'payment_gateway'           => 'square',
			'square_mode'               => 'test',
			'square_api_version'        => '2025-01-23',
			'square_webhook_url'        => '',
			'square_test_app_id'        => 'sandbox-sq0idb-EXAMPLETESTAPPID000000',
			'square_test_access_token'  => 'EAAAtestExampleSandboxAccessToken0000',
			'square_test_location_id'   => 'LTESTLOCATION123',
			'square_test_webhook_key'   => 'sandbox-signature-key-example-000000',
			'square_live_app_id'        => 'sq0idp-EXAMPLELIVEAPPID0000000',
			'square_live_access_token'  => 'EAAAliveExampleProductionAccessToken0',
			'square_live_location_id'   => 'LLIVELOCATION456',
			'square_live_webhook_key'   => 'live-signature-key-example-0000000000',
			'square_live_approved'      => 0,
		),
		$overrides
	);
}

/**
 * A well-formed Square payment object for the given amount.
 *
 * @param int    $amount    Amount in cents.
 * @param string $currency  ISO currency.
 * @param string $reference reference_id.
 * @return array
 */
function db_square_payment( $amount = 2750, $currency = 'AUD', $reference = 'db-attempt-42' ) {
	return array(
		'id'           => 'sqpaymentEXAMPLE0000000001',
		'status'       => 'COMPLETED',
		'location_id'  => 'LTESTLOCATION123',
		'reference_id' => $reference,
		'amount_money' => array(
			'amount'   => $amount,
			'currency' => $currency,
		),
	);
}

/* -------------------------------------------------------------------------- */
/* Minor units — AUD, GST-inclusive                                            */
/* -------------------------------------------------------------------------- */

db_test(
	'to_minor_units converts dollars to cents',
	function () {
		assert_same( 1250, DoughBoss_Square::to_minor_units( 12.50 ), '12.50 becomes 1250 cents' );
		assert_same( 0, DoughBoss_Square::to_minor_units( 0 ), 'zero stays zero' );
		assert_same( 100, DoughBoss_Square::to_minor_units( 1 ), 'a whole dollar becomes 100 cents' );
		assert_same( 1250, DoughBoss_Square::to_minor_units( '12.50' ), 'a numeric string converts identically' );
	}
);

db_test(
	'to_minor_units rounds binary-float artefacts to the nearest cent',
	function () {
		// 8.15 * 100 is 814.9999999999999 in IEEE-754. A truncating cast would
		// undercharge by a cent; round() is what keeps this correct.
		assert_same( 815, DoughBoss_Square::to_minor_units( 8.15 ), '8.15 becomes 815, not 814' );
		assert_same( 1999, DoughBoss_Square::to_minor_units( 19.99 ), '19.99 becomes 1999' );
		assert_same( 7005, DoughBoss_Square::to_minor_units( 70.05 ), '70.05 becomes 7005' );
		assert_same( 3335, DoughBoss_Square::to_minor_units( 33.35 ), '33.35 becomes 3335' );
	}
);

db_test(
	'GST-inclusive AUD totals are charged as-is, never grossed up again',
	function () {
		// DoughBoss prices include GST. DoughBoss_Cart computes
		// tax = total * rate / (100 + rate) — the GST component OF the total,
		// not an addition to it. The charged amount must equal the total.
		$total = 27.50;
		$tax   = round( $total * ( 10 / 110 ), 2 );

		assert_same( 2.50, $tax, 'GST component of a $27.50 inclusive total is $2.50' );
		assert_same( 2750, DoughBoss_Square::to_minor_units( $total ), 'the charge is the inclusive total in cents' );
		assert_same( 250, DoughBoss_Square::to_minor_units( $tax ), 'the GST component is 250 cents' );

		// The bug this guards against: charging total * 1.1.
		$grossed_up = DoughBoss_Square::to_minor_units( $total * 1.1 );
		assert_true( $grossed_up !== DoughBoss_Square::to_minor_units( $total ), 'GST is not added on top of an inclusive price' );
		assert_same( 3025, $grossed_up, 'the wrong figure would have been 3025 cents' );
	}
);

/* -------------------------------------------------------------------------- */
/* Idempotency keys                                                            */
/* -------------------------------------------------------------------------- */

db_test(
	'idempotency keys satisfy Square\'s 45-character limit',
	function () {
		$checkout_key = str_repeat( 'a1', 32 ); // 64 hex characters, as the real key is.
		$key          = DoughBoss_Square::idempotency_key( 'pay', $checkout_key );

		assert_true( strlen( $key ) <= 45, 'key is within Square\'s 45-character cap' );
		assert_true( strlen( $key ) >= 16, 'key is long enough to be collision-safe' );
		assert_same( 1, preg_match( '/^[A-Za-z0-9_-]+$/', $key ), 'key contains only safe characters' );

		// A very long identity must not overflow the cap either.
		$long = DoughBoss_Square::idempotency_key( 'pay', str_repeat( 'z', 5000 ) );
		assert_true( strlen( $long ) <= 45, 'an oversized identity is still capped at 45' );
	}
);

db_test(
	'idempotency keys are stable per checkout and distinct across operations',
	function () {
		$a = str_repeat( 'b2', 32 );
		$b = str_repeat( 'c3', 32 );

		assert_same(
			DoughBoss_Square::idempotency_key( 'pay', $a ),
			DoughBoss_Square::idempotency_key( 'pay', $a ),
			'the same checkout replays the same key, so a retry cannot double-charge'
		);
		assert_true(
			DoughBoss_Square::idempotency_key( 'pay', $a ) !== DoughBoss_Square::idempotency_key( 'pay', $b ),
			'a different checkout gets a different key'
		);
		assert_true(
			DoughBoss_Square::idempotency_key( 'pay', $a ) !== DoughBoss_Square::idempotency_key( 'refund', $a ),
			'a refund never collides with the payment it refunds'
		);
		assert_true(
			false === strpos( DoughBoss_Square::idempotency_key( 'pay', $a ), $a ),
			'the checkout key is hashed, not embedded verbatim'
		);
	}
);

/* -------------------------------------------------------------------------- */
/* Webhook signature verification                                              */
/* -------------------------------------------------------------------------- */

db_test(
	'a correctly signed webhook is accepted',
	function () {
		doughboss_test_set_settings( db_square_settings() );

		$url  = DoughBoss_Settings::square_webhook_url();
		$key  = 'sandbox-signature-key-example-000000';
		$body = '{"event_id":"evt_1","type":"payment.updated","data":{"type":"payment","id":"sqpay1"}}';
		$sig  = base64_encode( hash_hmac( 'sha256', $url . $body, $key, true ) );

		assert_true( DoughBoss_Square::verify_webhook_signature( $body, $sig ), 'a valid signature verifies' );
	}
);

db_test(
	'a tampered body is rejected',
	function () {
		doughboss_test_set_settings( db_square_settings() );

		$url      = DoughBoss_Settings::square_webhook_url();
		$key      = 'sandbox-signature-key-example-000000';
		$body     = '{"event_id":"evt_1","type":"payment.updated","data":{"type":"payment","id":"sqpay1"}}';
		$sig      = base64_encode( hash_hmac( 'sha256', $url . $body, $key, true ) );
		$tampered = '{"event_id":"evt_1","type":"payment.updated","data":{"type":"payment","id":"sqpayEVIL"}}';

		assert_false( DoughBoss_Square::verify_webhook_signature( $tampered, $sig ), 'a swapped payment id fails' );
		assert_false( DoughBoss_Square::verify_webhook_signature( $body . ' ', $sig ), 'even one trailing byte fails' );
	}
);

db_test(
	'a signature made with the wrong key is rejected',
	function () {
		doughboss_test_set_settings( db_square_settings() );

		$url  = DoughBoss_Settings::square_webhook_url();
		$body = '{"event_id":"evt_1","type":"payment.updated"}';
		$sig  = base64_encode( hash_hmac( 'sha256', $url . $body, 'not-the-configured-key', true ) );

		assert_false( DoughBoss_Square::verify_webhook_signature( $body, $sig ), 'another key does not verify' );
	}
);

db_test(
	'a missing or malformed signature header is rejected',
	function () {
		doughboss_test_set_settings( db_square_settings() );

		$body = '{"event_id":"evt_1","type":"payment.updated"}';

		assert_false( DoughBoss_Square::verify_webhook_signature( $body, '' ), 'an empty header fails' );
		assert_false( DoughBoss_Square::verify_webhook_signature( $body, null ), 'a null header fails' );
		assert_false( DoughBoss_Square::verify_webhook_signature( $body, 'garbage' ), 'a junk header fails' );
		assert_false( DoughBoss_Square::verify_webhook_signature( '', 'anything' ), 'an empty body fails' );
	}
);

db_test(
	'the notification URL is part of the signed material',
	function () {
		doughboss_test_set_settings( db_square_settings() );

		$key  = 'sandbox-signature-key-example-000000';
		$body = '{"event_id":"evt_1","type":"payment.updated"}';
		// Signed for someone else's endpoint — a replay against this site must fail.
		$sig  = base64_encode( hash_hmac( 'sha256', 'https://attacker.example/hook' . $body, $key, true ) );

		assert_false( DoughBoss_Square::verify_webhook_signature( $body, $sig ), 'a signature for another URL fails' );
		assert_true(
			DoughBoss_Square::verify_webhook_signature( $body, $sig, 'https://attacker.example/hook' ),
			'the same signature verifies only against the URL it was made for'
		);
	}
);

db_test(
	'webhook verification fails closed when no signature key is stored',
	function () {
		doughboss_test_set_settings( db_square_settings( array( 'square_test_webhook_key' => '' ) ) );

		$url  = DoughBoss_Settings::square_webhook_url();
		$body = '{"event_id":"evt_1","type":"payment.updated"}';
		$sig  = base64_encode( hash_hmac( 'sha256', $url . $body, '', true ) );

		assert_false( DoughBoss_Square::verify_webhook_signature( $body, $sig ), 'no key means no verification, ever' );
	}
);

/* -------------------------------------------------------------------------- */
/* Amount / currency / binding mismatch                                        */
/* -------------------------------------------------------------------------- */

db_test(
	'an exactly matching payment is accepted',
	function () {
		assert_true(
			DoughBoss_Square::payment_matches( db_square_payment( 2750, 'AUD' ), 2750, 'AUD', 'db-attempt-42' ),
			'the expected amount, currency and reference match'
		);
		assert_true(
			DoughBoss_Square::payment_matches( db_square_payment( 2750, 'aud' ), 2750, 'AUD', 'db-attempt-42' ),
			'currency comparison is case-insensitive'
		);
		assert_true(
			DoughBoss_Square::payment_matches( db_square_payment( '2750', 'AUD' ), 2750, 'AUD', 'db-attempt-42' ),
			'a numeric-string amount from the API still matches'
		);
	}
);

db_test(
	'an amount mismatch is refused in both directions',
	function () {
		assert_false(
			DoughBoss_Square::payment_matches( db_square_payment( 2749, 'AUD' ), 2750, 'AUD', 'db-attempt-42' ),
			'one cent short is refused'
		);
		assert_false(
			DoughBoss_Square::payment_matches( db_square_payment( 2751, 'AUD' ), 2750, 'AUD', 'db-attempt-42' ),
			'one cent over is refused'
		);
		assert_false(
			DoughBoss_Square::payment_matches( db_square_payment( 1, 'AUD' ), 2750, 'AUD', 'db-attempt-42' ),
			'a one-cent payment for a $27.50 order is refused'
		);
		assert_false(
			DoughBoss_Square::payment_matches( db_square_payment( 0, 'AUD' ), 0, 'AUD', 'db-attempt-42' ),
			'a zero-amount payment is never accepted, even if zero was expected'
		);
	}
);

db_test(
	'a currency or binding mismatch is refused',
	function () {
		assert_false(
			DoughBoss_Square::payment_matches( db_square_payment( 2750, 'USD' ), 2750, 'AUD', 'db-attempt-42' ),
			'a USD payment cannot settle an AUD order'
		);
		assert_false(
			DoughBoss_Square::payment_matches( db_square_payment( 2750, 'AUD', 'db-attempt-99' ), 2750, 'AUD', 'db-attempt-42' ),
			'a payment bound to another attempt is refused'
		);
		assert_false(
			DoughBoss_Square::payment_matches( db_square_payment( 2750, 'AUD', '' ), 2750, 'AUD', 'db-attempt-42' ),
			'a payment with no reference is refused'
		);
	}
);

db_test(
	'a malformed or truncated payment object is refused',
	function () {
		assert_false( DoughBoss_Square::payment_matches( array(), 2750, 'AUD', 'db-attempt-42' ), 'an empty object is refused' );
		assert_false(
			DoughBoss_Square::payment_matches( array( 'id' => 'sqpay1', 'reference_id' => 'db-attempt-42' ), 2750, 'AUD', 'db-attempt-42' ),
			'a missing amount_money is refused'
		);
		assert_false(
			DoughBoss_Square::payment_matches(
				array(
					'id'           => '',
					'reference_id' => 'db-attempt-42',
					'amount_money' => array( 'amount' => 2750, 'currency' => 'AUD' ),
				),
				2750,
				'AUD',
				'db-attempt-42'
			),
			'a payment with no id is refused'
		);
		assert_false(
			DoughBoss_Square::payment_matches(
				array(
					'id'           => 'sqpay1EXAMPLE00000',
					'reference_id' => 'db-attempt-42',
					'amount_money' => array( 'amount' => 'twenty seven fifty', 'currency' => 'AUD' ),
				),
				2750,
				'AUD',
				'db-attempt-42'
			),
			'a non-numeric amount is refused rather than cast to 0'
		);
	}
);

/* -------------------------------------------------------------------------- */
/* Not-enabled / not-configured short-circuits                                 */
/* -------------------------------------------------------------------------- */

db_test(
	'Square is inert on a default install',
	function () {
		doughboss_test_reset_options();

		assert_same( 'stripe', DoughBoss_Settings::payment_gateway(), 'the default gateway is unchanged' );
		assert_false( DoughBoss_Settings::payments_enabled(), 'payments are off by default' );
		assert_false( DoughBoss_Settings::square_ready(), 'Square is not ready on a fresh install' );
		assert_false( DoughBoss_Square::ready(), 'the gateway gate agrees' );
		assert_same( '', DoughBoss_Settings::square_application_id(), 'no application id is configured' );
		assert_same( '', DoughBoss_Settings::square_access_token(), 'no access token is configured' );
	}
);

db_test(
	'Square stays off while another gateway is active',
	function () {
		doughboss_test_set_settings( db_square_settings( array( 'payment_gateway' => 'stripe' ) ) );
		assert_false( DoughBoss_Square::ready(), 'fully configured Square is still inert while Stripe is active' );

		doughboss_test_set_settings( db_square_settings( array( 'payment_gateway' => 'tyro' ) ) );
		assert_false( DoughBoss_Square::ready(), 'and inert while Tyro is active' );
	}
);

db_test(
	'Square stays off while card payments are switched off',
	function () {
		doughboss_test_set_settings( db_square_settings( array( 'payments_enabled' => 0 ) ) );
		assert_false( DoughBoss_Square::ready(), 'the master payments switch gates Square too' );
	}
);

db_test(
	'incomplete sandbox credentials keep Square off',
	function () {
		doughboss_test_set_settings( db_square_settings( array( 'square_test_app_id' => '' ) ) );
		assert_false( DoughBoss_Square::ready(), 'no application id, no payments' );

		doughboss_test_set_settings( db_square_settings( array( 'square_test_access_token' => '' ) ) );
		assert_false( DoughBoss_Square::ready(), 'no access token, no payments' );

		doughboss_test_set_settings( db_square_settings( array( 'square_test_access_token' => 'short' ) ) );
		assert_false( DoughBoss_Square::ready(), 'an implausibly short token is refused' );

		doughboss_test_set_settings( db_square_settings( array( 'square_test_location_id' => '' ) ) );
		assert_false( DoughBoss_Square::ready(), 'no location id, no payments' );
	}
);

db_test(
	'a fully configured sandbox is ready',
	function () {
		doughboss_test_set_settings( db_square_settings() );

		assert_same( 'square', DoughBoss_Settings::payment_gateway(), 'Square is the active gateway' );
		assert_same( 'test', DoughBoss_Settings::square_mode(), 'sandbox mode is active' );
		assert_true( DoughBoss_Square::ready(), 'sandbox Square is ready' );
		assert_same( 'https://connect.squareupsandbox.com', DoughBoss_Square::api_host(), 'sandbox host is used' );
		assert_same( 'https://sandbox.web.squarecdn.com/v1/square.js', DoughBoss_Square::sdk_url(), 'sandbox SDK is used' );
		assert_same( 'sandbox-sq0idb-EXAMPLETESTAPPID000000', DoughBoss_Square::publishable_key(), 'the sandbox application id is exposed' );
		assert_same( 'LTESTLOCATION123', DoughBoss_Square::location_id(), 'the sandbox location id is exposed' );
	}
);

db_test(
	'live mode fails closed without explicit approval and a webhook key',
	function () {
		doughboss_test_set_settings( db_square_settings( array( 'square_mode' => 'live' ) ) );
		assert_false( DoughBoss_Square::ready(), 'live is refused until approval is ticked' );

		doughboss_test_set_settings(
			db_square_settings(
				array(
					'square_mode'             => 'live',
					'square_live_approved'    => 1,
					'square_live_webhook_key' => '',
				)
			)
		);
		assert_false( DoughBoss_Square::ready(), 'live is refused without a webhook signature key' );

		doughboss_test_set_settings(
			db_square_settings(
				array(
					'square_mode'          => 'live',
					'square_live_approved' => 1,
				)
			)
		);
		assert_true( DoughBoss_Square::ready(), 'approved live with a webhook key is ready' );
		assert_same( 'https://connect.squareup.com', DoughBoss_Square::api_host(), 'production host is used' );
		assert_same( 'https://web.squarecdn.com/v1/square.js', DoughBoss_Square::sdk_url(), 'production SDK is used' );
	}
);

db_test(
	'a sandbox application id cannot be used in live mode, or vice versa',
	function () {
		doughboss_test_set_settings(
			db_square_settings(
				array(
					'square_mode'          => 'live',
					'square_live_approved' => 1,
					'square_live_app_id'   => 'sandbox-sq0idb-WRONGMODE00000000',
				)
			)
		);
		assert_same( '', DoughBoss_Settings::square_application_id(), 'a sandbox id is rejected in live mode' );
		assert_false( DoughBoss_Square::ready(), 'and Square stays off' );

		doughboss_test_set_settings( db_square_settings( array( 'square_test_app_id' => 'sq0idp-PRODUCTIONIDINSANDBOX' ) ) );
		assert_same( '', DoughBoss_Settings::square_application_id(), 'a production id is rejected in sandbox mode' );
		assert_false( DoughBoss_Square::ready(), 'and Square stays off' );
	}
);

/* -------------------------------------------------------------------------- */
/* Status mapping and reference validation                                     */
/* -------------------------------------------------------------------------- */

db_test(
	'only a COMPLETED Square payment maps to success',
	function () {
		assert_same( 'succeeded', DoughBoss_Square::normalised_status( 'COMPLETED' ), 'COMPLETED succeeds' );
		assert_same( 'processing', DoughBoss_Square::normalised_status( 'APPROVED' ), 'APPROVED is not yet money' );
		assert_same( 'processing', DoughBoss_Square::normalised_status( 'PENDING' ), 'PENDING is not yet money' );
		assert_same( 'voided', DoughBoss_Square::normalised_status( 'CANCELED' ), 'CANCELED is void' );
		assert_same( 'failed', DoughBoss_Square::normalised_status( 'FAILED' ), 'FAILED is a failure' );
		assert_same( 'unknown', DoughBoss_Square::normalised_status( 'SOMETHING_NEW' ), 'an unknown status never passes' );
		assert_same( 'unknown', DoughBoss_Square::normalised_status( '' ), 'an empty status never passes' );
	}
);

db_test(
	'payment references are validated, not merely sanitised',
	function () {
		assert_same( 'sqpaymentEXAMPLE0000000001', DoughBoss_Square::canonical_id( 'sqpaymentEXAMPLE0000000001' ), 'a valid id passes through' );
		assert_same( '', DoughBoss_Square::canonical_id( '' ), 'an empty id is refused' );
		assert_same( '', DoughBoss_Square::canonical_id( 'short' ), 'a too-short id is refused' );
		assert_same( '', DoughBoss_Square::canonical_id( '../../etc/passwd' ), 'a traversal attempt is refused' );
		assert_same( '', DoughBoss_Square::canonical_id( 'has spaces in it' ), 'whitespace is refused' );
		assert_same( '', DoughBoss_Square::canonical_id( str_repeat( 'a', 500 ) ), 'an oversized id is refused' );
	}
);

db_test(
	'attempt references stay inside Square\'s 40-character reference_id limit',
	function () {
		assert_same( 'db-attempt-42', DoughBoss_Square::attempt_reference( 42 ), 'the reference names the attempt row' );
		assert_true( strlen( DoughBoss_Square::attempt_reference( PHP_INT_MAX ) ) <= 40, 'even a huge id fits Square\'s limit' );
		assert_same( 'db-attempt-0', DoughBoss_Square::attempt_reference( 'not-a-number' ), 'a non-numeric id degrades to 0, which never matches a real attempt' );
	}
);

/* -------------------------------------------------------------------------- */
/* API version                                                                 */
/* -------------------------------------------------------------------------- */

db_test(
	'the Square API version is validated before it reaches a header',
	function () {
		doughboss_test_set_settings( db_square_settings() );
		assert_same( '2025-01-23', DoughBoss_Settings::square_api_version(), 'the configured version is used' );

		doughboss_test_set_settings( db_square_settings( array( 'square_api_version' => 'not a date' ) ) );
		assert_same( '2025-01-23', DoughBoss_Settings::square_api_version(), 'junk falls back to the shipped default' );

		doughboss_test_set_settings( db_square_settings( array( 'square_api_version' => '2024-06-04' ) ) );
		assert_same( '2024-06-04', DoughBoss_Settings::square_api_version(), 'an older valid version is honoured' );
	}
);

function db_square_gift_card_response( $id = 'gftc:EXAMPLECARD123', $type = 'DIGITAL', $state = 'ACTIVE', $amount = 1250, $currency = 'AUD' ) {
	return array(
		'gift_card' => array(
			'id'            => $id,
			'type'          => $type,
			'state'         => $state,
			'balance_money' => array(
				'amount'   => $amount,
				'currency' => $currency,
			),
			'gan'           => 'GAN-MUST-NOT-ESCAPE',
			'customer_id'   => 'customer-must-not-escape',
			'extra_field'   => 'must-not-escape',
		),
		'extra_response_field' => 'must-not-escape',
	);
}

db_test(
	'Square gift card observations are minimal, exact and never spendable',
	function () {
		$method = new ReflectionMethod( 'DoughBoss_Square', 'gift_card_observation' );
		$method->setAccessible( true );
		$id = 'gftc:EXAMPLECARD123';
		foreach ( array( 'DIGITAL', 'PHYSICAL' ) as $type ) {
			foreach ( array( 'ACTIVE', 'DEACTIVATED', 'BLOCKED', 'PENDING' ) as $state ) {
				$response = $method->invoke( null, db_square_gift_card_response( $id, $type, $state, 0 ), $id, 'AUD' );
				assert_same(
					array( 'id' => $id, 'type' => $type, 'state' => $state, 'balance_minor' => 0, 'currency' => 'AUD' ),
					$response,
					$type . ' ' . $state . ' zero-balance observation has no spendable flag or raw provider fields'
				);
			}
		}
		$positive = $method->invoke( null, db_square_gift_card_response( $id, 'PHYSICAL', 'ACTIVE', 1250 ), $id, 'AUD' );
		assert_same( 1250, $positive['balance_minor'], 'gift card balance remains an exact nonnegative integer minor amount' );
	}
);

db_test(
	'Square gift card observations reject malformed or mismatched provider evidence',
	function () {
		$method = new ReflectionMethod( 'DoughBoss_Square', 'gift_card_observation' );
		$method->setAccessible( true );
		$id = 'gftc:EXAMPLECARD123';
		foreach ( array(
			'id'       => array( 'gftc:OTHER', '', 42, true, array() ),
			'type'     => array( 'UNKNOWN', '', 42, null ),
			'state'    => array( 'UNKNOWN', '', 42, null ),
		) as $field => $values ) {
			foreach ( $values as $value ) {
				$response = db_square_gift_card_response();
				$response['gift_card'][ $field ] = $value;
				assert_true( is_wp_error( $method->invoke( null, $response, $id, 'AUD' ) ), 'malformed or mismatched gift card ' . $field . ' is rejected' );
			}
		}
		foreach ( array(
			'amount'   => array( null, '1250', 1250.5, -1, 9007199254740992, true, array() ),
			'currency' => array( 'USD', 'aud', '', null, 42, array() ),
		) as $field => $values ) {
			foreach ( $values as $value ) {
				$response = db_square_gift_card_response();
				$response['gift_card']['balance_money'][ $field ] = $value;
				assert_true( is_wp_error( $method->invoke( null, $response, $id, 'AUD' ) ), 'malformed or mismatched gift card money ' . $field . ' is rejected' );
			}
		}
		foreach ( array( array(), array( 'gift_card' => 'bad' ), array( 'gift_card' => array() ), array( 'errors' => array( array( 'code' => 'CARD_ERROR' ) ), 'gift_card' => db_square_gift_card_response()['gift_card'] ) ) as $response ) {
			assert_true( is_wp_error( $method->invoke( null, $response, $id, 'AUD' ) ), 'missing or contradictory gift card response is rejected' );
		}
		$response = db_square_gift_card_response();
		assert_true( is_wp_error( $method->invoke( null, $response, $id, 'USD' ) ), 'configured currency mismatch is rejected' );
	}
);

db_test(
	'Square gift card IDs and native lookup inputs fail closed before provider access',
	function () {
		$valid = new ReflectionMethod( 'DoughBoss_Square', 'valid_gift_card_id' );
		$valid->setAccessible( true );
		foreach ( array( '', 'gftc:', 'gftc:bad id', 'gftc:bad/slash', 'GAN-EXAMPLE', 'gftc:' . str_repeat( 'a', 129 ), 42, true, null, array() ) as $invalid ) {
			assert_false( $valid->invoke( null, $invalid ), 'invalid or native gift card ID type is rejected' );
		}
		foreach ( array( '', 'gftc:bad id', 'GAN-EXAMPLE', 42, true, null, array() ) as $invalid ) {
			$result = DoughBoss_Square::retrieve_gift_card( $invalid, 1 );
			assert_same( 'doughboss_gift_card_input', is_wp_error( $result ) ? $result->get_error_code() : '', 'invalid gift card lookup ID stops before provider access' );
		}
		foreach ( array( 0, -1, '1', 1.0, true, null, array() ) as $invalid_location ) {
			$result = DoughBoss_Square::retrieve_gift_card( 'gftc:EXAMPLECARD123', $invalid_location );
			assert_same( 'doughboss_gift_card_input', is_wp_error( $result ) ? $result->get_error_code() : '', 'native or nonpositive gift card location stops before provider access' );
		}
	}
);

/** A synthetic provider refund response: never calls Square. */
function db_square_refund_response( $status = 'COMPLETED' ) {
	return array( 'refund' => array(
		'id' => 'sqrefundEXAMPLE0000000001',
		'payment_id' => 'sqpaymentEXAMPLE0000000001',
		'status' => $status,
		'amount_money' => array( 'amount' => 2750, 'currency' => 'AUD' ),
	) );
}

db_test(
	'Square refund acceptance requires completion, not HTTP success',
	function () {
		$completed = db_square_refund_response();
		assert_same( $completed, DoughBoss_Square::validate_refund_response( $completed, 'sqpaymentEXAMPLE0000000001', 2750, 'AUD' ), 'completed refund preserves provider evidence' );
		foreach ( array( 'PENDING', 'REJECTED', 'FAILED', 'UNKNOWN', '', 'completed' ) as $status ) {
			$result = DoughBoss_Square::validate_refund_response( db_square_refund_response( $status ), 'sqpaymentEXAMPLE0000000001', 2750, 'AUD' );
			assert_true( is_wp_error( $result ), $status . ' cannot mark the order refunded' );
			if ( 'PENDING' === $status ) {
				assert_same( 'doughboss_refund_pending', $result->get_error_code(), 'pending is distinguished from completion/failure' );
			}
		}
	}
);

db_test(
	'Square completed refunds must match the exact requested payment and money',
	function () {
		foreach ( array( 'id' => array( '', 'short', array(), '<b>sqrefundEXAMPLE0000000001</b>' ), 'payment_id' => array( 'otherpayment0000000001', array(), '' ), 'status' => array( array(), null ) ) as $field => $values ) {
			foreach ( $values as $value ) {
				$response = db_square_refund_response();
				$response['refund'][ $field ] = $value;
				assert_true( is_wp_error( DoughBoss_Square::validate_refund_response( $response, 'sqpaymentEXAMPLE0000000001', 2750, 'AUD' ) ), 'malformed or mismatched ' . $field . ' refuses completion' );
			}
		}
		foreach ( array( 'amount' => array( 2700, 2800, -2750, '2750', 2750.5, null, array() ), 'currency' => array( 'USD', 'aud', null, array() ) ) as $field => $values ) {
			foreach ( $values as $value ) {
				$response = db_square_refund_response();
				$response['refund']['amount_money'][ $field ] = $value;
				assert_true( is_wp_error( DoughBoss_Square::validate_refund_response( $response, 'sqpaymentEXAMPLE0000000001', 2750, 'AUD' ) ), 'malformed or mismatched money ' . $field . ' refuses completion' );
			}
		}
		foreach ( array( array(), array( 'refund' => 'bad' ), array( 'refund' => array() ), array( 'errors' => array( array( 'code' => 'REFUND_ERROR' ) ), 'refund' => db_square_refund_response()['refund'] ) ) as $response ) {
			assert_true( is_wp_error( DoughBoss_Square::validate_refund_response( $response, 'sqpaymentEXAMPLE0000000001', 2750, 'AUD' ) ), 'absent or contradictory provider evidence refuses completion' );
		}
		$response = db_square_refund_response();
		$response['refund']['amount_money']['amount'] = 1000;
		assert_same( $response, DoughBoss_Square::validate_refund_response( $response, 'sqpaymentEXAMPLE0000000001', 1000, 'AUD' ), 'a requested partial refund validates only its own exact amount' );
	}
);
