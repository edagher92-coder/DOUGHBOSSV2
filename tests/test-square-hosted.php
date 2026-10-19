<?php
/**
 * Square-hosted checkout (Checkout API payment links) pure contract tests.
 *
 * These stop before storage and HTTP: the payment-link body builder, return
 * and checkout URL validation, Order payment-state classification and the
 * paid-order proof validator that must reject every unproven payment.
 *
 * @package DoughBoss
 */

if ( ! function_exists( 'wp_json_encode' ) ) {
	/** @return string|false */
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-doughboss-order.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-square-orders.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-payment-attempts.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-square-hosted.php';

/** Synthetic immutable itemization (no catalog, provider or customer records). */
function db_hosted_projection() {
	$payload = array(
		'order' => array(
			'currency' => 'AUD', 'subtotal' => '22.00', 'discount' => '2.00', 'delivery_fee' => '0.00',
			'tax' => 1.82, 'total' => 20.00,
			'accounting_basis' => array( 'version' => 1, 'tax_inclusive' => true, 'tax_rate' => 10,
				'discount_policy' => 'goods_before_tax', 'delivery_taxable' => true ),
		),
		'lines' => array( array( 'name' => 'Signature pizza', 'size' => 'Large', 'quantity' => '2',
			'unit_price' => '11.00', 'line_total' => '22.00' ) ),
	);
	return DoughBoss_Square_Orders::project( $payload, array( 'shop_slug' => 'revesby', 'square_location_id' => 'LOCATION_R_TEST', 'square_mode' => 'test' ), 37 );
}

/** Square's view of the link Order: DRAFT, one DIGITAL fulfillment, provider rollups. */
function db_hosted_order( array $projection, $state = 'DRAFT' ) {
	$order    = $projection['order'];
	$money    = function ( $amount ) { return array( 'amount' => $amount, 'currency' => 'AUD' ); };
	unset( $order['state'] );
	$order['id']           = 'squareHOSTEDORDER0000001';
	$order['version']      = 3;
	$order['state']        = $state;
	$order['fulfillments'] = array( array( 'uid' => 'sq-digital', 'type' => 'DIGITAL', 'state' => 'PROPOSED' ) );
	$order['source']       = array( 'name' => 'Checkout' );
	foreach ( $order['line_items'] as &$row ) {
		$base = $row['base_price_money']['amount'] * (int) $row['quantity'];
		$row['item_type'] = 'ITEM';
		$row['gross_sales_money'] = $money( $base );
		$row['variation_total_price_money'] = $money( $base );
		$row['total_tax_money'] = $money( $projection['amounts']['tax'] );
		$row['total_discount_money'] = $money( $projection['amounts']['discount'] );
		$row['total_money'] = $money( $base - $projection['amounts']['discount'] );
		$row['applied_taxes'][0]['uid'] = 'generated-tax';
		$row['applied_taxes'][0]['applied_money'] = $money( $projection['amounts']['tax'] );
		$row['applied_discounts'] = array( array( 'uid' => 'generated-discount', 'discount_uid' => 'db-discount', 'applied_money' => $money( $projection['amounts']['discount'] ) ) );
	}
	unset( $row );
	$order['total_tax_money']      = $money( $projection['amounts']['tax'] );
	$order['total_discount_money'] = $money( $projection['amounts']['discount'] );
	$order['total_money']          = $money( $projection['amounts']['total'] );
	$order['taxes'][0]['applied_money']     = $money( $projection['amounts']['tax'] );
	$order['discounts'][0]['applied_money'] = $money( $projection['amounts']['discount'] );
	return array( 'order' => $order );
}

/** Paid Order plus its hosted Payment (no DoughBoss reference_id on the Payment). */
function db_hosted_paid( array $projection ) {
	$order   = db_hosted_order( $projection, 'OPEN' );
	$payment = array(
		'id' => 'sqpayHOSTEDPAYMENT000001', 'status' => 'COMPLETED', 'source_type' => 'CARD',
		'order_id' => $order['order']['id'], 'location_id' => 'LOCATION_R_TEST',
		'amount_money' => array( 'amount' => 2000, 'currency' => 'AUD' ),
		'total_money' => array( 'amount' => 2000, 'currency' => 'AUD' ),
		'card_details' => array( 'status' => 'CAPTURED', 'card' => array( 'card_brand' => 'VISA' ) ),
	);
	$order['order']['tenders'] = array( array( 'id' => 'tender1', 'payment_id' => $payment['id'], 'type' => 'CARD', 'amount_money' => $payment['amount_money'] ) );
	$order['order']['net_amount_due_money'] = array( 'amount' => 0, 'currency' => 'AUD' );
	return array( $order, $payment );
}

db_test( 'Square hosted: AU phone numbers become E.164 or are omitted', function () {
	$cases = array(
		'0412 345 678' => '+61412345678', '+61 412 345 678' => '+61412345678', '61412345678' => '+61412345678',
		'(02) 9708 2222' => '+61297082222', '0297082222' => '+61297082222', '+61297082222' => '+61297082222',
		'412345678' => '', '+1 415 555 1212' => '', '0012345678' => '', 'call me' => '', '' => '', '04123456789' => '',
	);
	foreach ( $cases as $in => $want ) {
		assert_same( $want, DoughBoss_Square_Hosted::au_e164( (string) $in ), 'phone ' . $in );
	}
	assert_same( '', DoughBoss_Square_Hosted::au_e164( null ), 'non-string phone' );
	assert_true( strlen( DoughBoss_Square_Hosted::au_e164( '0412345678' ) ) <= 17, 'Square buyer_phone_number limit' );
} );

db_test( 'Square hosted: return URL stays same-site HTTPS and carries only the checkout key', function () {
	$key  = str_repeat( 'a1', 32 );
	$home = 'https://doughboss.test/';
	assert_same( 'https://doughboss.test/order/?doughboss_square_return=' . $key, DoughBoss_Square_Hosted::return_url( 'https://doughboss.test/order/', $key, $home ), 'plain page' );
	assert_same( 'https://doughboss.test/?page_id=7&doughboss_square_return=' . $key, DoughBoss_Square_Hosted::return_url( 'https://doughboss.test/?page_id=7#frag', $key, $home ), 'query kept, fragment dropped' );
	foreach ( array( 'http://doughboss.test/order/', 'https://evil.test/order/', 'https://doughboss.test.evil.test/', 'https://doughboss.test:8443/', 'https://user@doughboss.test/', '', 'javascript:alert(1)', "https://doughboss.test/\n" ) as $bad ) {
		assert_same( '', DoughBoss_Square_Hosted::return_url( $bad, $key, $home ), 'rejects return base ' . $bad );
	}
	foreach ( array( '', 'ABC', str_repeat( 'g', 64 ), strtoupper( $key ) ) as $bad_key ) {
		assert_same( '', DoughBoss_Square_Hosted::return_url( 'https://doughboss.test/', $bad_key, $home ), 'rejects checkout key ' . $bad_key );
	}
	assert_same( '', DoughBoss_Square_Hosted::return_url( 'https://doughboss.test/' . str_repeat( 'x', 2100 ), $key, $home ), 'respects 2048 redirect_url limit' );
	assert_same( $key, DoughBoss_Square_Hosted::returned_checkout_key( strtoupper( $key ) ), 'returned key normalised' );
	assert_same( '', DoughBoss_Square_Hosted::returned_checkout_key( array( $key ) ), 'array input refused' );
	assert_same( '', DoughBoss_Square_Hosted::returned_checkout_key( $key . 'a' ), 'wrong length refused' );
} );

db_test( 'Square hosted: checkout URLs are limited to Square hosts for the active mode', function () {
	assert_true( DoughBoss_Square_Hosted::checkout_url_valid( 'https://square.link/u/AbC123', 'live' ), 'live short link' );
	assert_true( DoughBoss_Square_Hosted::checkout_url_valid( 'https://checkout.square.site/merchant/x/order/y', 'live' ), 'live long link' );
	assert_true( DoughBoss_Square_Hosted::checkout_url_valid( 'https://sandbox.square.link/u/jUjglZiR', 'test' ), 'sandbox short link' );
	foreach ( array( 'https://sandbox.square.link/u/x' => 'live', 'https://square.link/u/x' => 'test', 'http://square.link/u/x' => 'live',
		'https://square.link.evil.test/u/x' => 'live', 'https://evil.test/square.link' => 'live', 'https://square.link:444/u/x' => 'live',
		'https://user@square.link/u/x' => 'live', 'https://square.link/u/"x' => 'live' ) as $url => $mode ) {
		assert_false( DoughBoss_Square_Hosted::checkout_url_valid( $url, $mode ), 'rejects ' . $url . ' in ' . $mode );
	}
} );

db_test( 'Square hosted: payment-link body is exact, location bound and tip/coupon/loyalty free', function () {
	$projection = db_hosted_projection();
	assert_false( is_wp_error( $projection ), 'fixture projects' );
	$key  = str_repeat( 'b2', 32 );
	$url  = 'https://doughboss.test/order/?doughboss_square_return=' . $key;
	$body = DoughBoss_Square_Hosted::payment_link_body( $projection, $key, $url, 'sam@example.com', '0412 345 678' );
	assert_false( is_wp_error( $body ), 'body builds' );
	$expected_order = $projection['order'];
	unset( $expected_order['state'] );
	assert_same( $expected_order, $body['order'], 'order is the itemised projection without a client-set state' );
	assert_same( 'LOCATION_R_TEST', $body['order']['location_id'], 'order is location bound' );
	assert_same( 'db-attempt-37', $body['order']['reference_id'], 'order carries the attempt reference' );
	assert_same( DoughBoss_Square::idempotency_key( 'plink', $key ), $body['idempotency_key'], 'idempotency derived from checkout key' );
	assert_true( strlen( $body['idempotency_key'] ) <= 45, 'idempotency key within limits' );
	assert_false( DoughBoss_Square::idempotency_key( 'plink', $key ) === DoughBoss_Square::idempotency_key( 'pay', $key ), 'link and payment keys differ' );
	assert_same( array(
		'redirect_url' => $url,
		'ask_for_shipping_address' => false,
		'accepted_payment_methods' => array( 'apple_pay' => true, 'google_pay' => true, 'cash_app_pay' => false, 'afterpay_clearpay' => false ),
		'allow_tipping' => false,
		'enable_coupon' => false,
		'enable_loyalty' => false,
	), $body['checkout_options'], 'checkout options exact' );
	assert_same( array( 'buyer_email' => 'sam@example.com', 'buyer_phone_number' => '+61412345678' ), $body['pre_populated_data'], 'buyer prefill' );
	assert_same( 'DoughBoss web order db-attempt-37', $body['payment_note'], 'payment note names the order reference' );
	assert_same( array( 'idempotency_key', 'order', 'checkout_options', 'payment_note', 'pre_populated_data' ), array_keys( $body ), 'no other fields (no quick_pay, app fee, shipping)' );
	$plain = DoughBoss_Square_Hosted::payment_link_body( $projection, $key, $url, 'not-an-email', 'n/a' );
	assert_false( isset( $plain['pre_populated_data'] ), 'invalid contacts are omitted, not sent' );
	foreach ( array( array( $projection, 'short', $url ), array( $projection, $key, 'http://doughboss.test/' ), array( $projection, $key, '' ), array( array(), $key, $url ) ) as $index => $bad ) {
		assert_true( is_wp_error( DoughBoss_Square_Hosted::payment_link_body( $bad[0], $bad[1], $bad[2], '', '' ) ), 'invalid body input ' . $index );
	}
} );

db_test( 'Square hosted: link response must name an id, an order and a Square URL', function () {
	$good = array( 'payment_link' => array( 'id' => 'PKVT6XGJZXYUP3NZ', 'version' => 1, 'url' => 'https://sandbox.square.link/u/EXAMPLE', 'order_id' => 'o4b7saqp4HzhNttf5AJxC0Srjd4F' ) );
	assert_same( array( 'id' => 'PKVT6XGJZXYUP3NZ', 'url' => 'https://sandbox.square.link/u/EXAMPLE', 'order_id' => 'o4b7saqp4HzhNttf5AJxC0Srjd4F' ), DoughBoss_Square_Hosted::link_facts( $good, 'test' ), 'valid sandbox link' );
	assert_true( is_wp_error( DoughBoss_Square_Hosted::link_facts( $good, 'live' ) ), 'sandbox link refused in live mode' );
	foreach ( array( 'id' => 'bad id!', 'order_id' => 'x', 'url' => 'https://evil.test/u/EXAMPLE' ) as $field => $value ) {
		$bad = $good; $bad['payment_link'][ $field ] = $value;
		assert_true( is_wp_error( DoughBoss_Square_Hosted::link_facts( $bad, 'test' ) ), 'bad link ' . $field );
	}
	$bad = $good; $bad['errors'] = array( array( 'code' => 'X' ) );
	assert_true( is_wp_error( DoughBoss_Square_Hosted::link_facts( $bad, 'test' ) ), 'errors refuse the link' );
	assert_true( is_wp_error( DoughBoss_Square_Hosted::link_facts( null, 'test' ) ), 'empty response refused' );
} );

db_test( 'Square hosted: unpaid link Order (DRAFT/OPEN + DIGITAL fulfillment) validates, others do not', function () {
	$projection = db_hosted_projection();
	foreach ( array( 'DRAFT', 'OPEN' ) as $state ) {
		$order = db_hosted_order( $projection, $state );
		assert_same( array( 'id' => 'squareHOSTEDORDER0000001', 'version' => 3 ), DoughBoss_Square_Orders::validate_hosted_response( $order, $projection, null, 'squareHOSTEDORDER0000001' ), 'unpaid ' . $state . ' link order' );
		assert_true( is_wp_error( DoughBoss_Square_Orders::validate_unpaid_response( $order, $projection ) ), 'embedded check stays strict about the provider fulfillment' );
	}
	$bad_cases = array();
	$o = db_hosted_order( $projection, 'COMPLETED' ); $bad_cases['completed'] = $o;
	$o = db_hosted_order( $projection ); $o['order']['fulfillments'][0]['type'] = 'PICKUP'; $bad_cases['pickup'] = $o;
	$o = db_hosted_order( $projection ); $o['order']['fulfillments'][] = $o['order']['fulfillments'][0]; $bad_cases['two fulfillments'] = $o;
	$o = db_hosted_order( $projection ); $o['order']['fulfillments'][0]['state'] = 'CANCELED'; $bad_cases['cancelled fulfillment'] = $o;
	$o = db_hosted_order( $projection ); $o['order']['service_charges'] = array( array( 'name' => 'Shipping' ) ); $bad_cases['service charge'] = $o;
	$o = db_hosted_order( $projection ); $o['order']['line_items'][0]['base_price_money']['amount'] = 1; $bad_cases['price drift'] = $o;
	$o = db_hosted_order( $projection ); $o['order']['location_id'] = 'OTHER_LOCATION'; $bad_cases['location'] = $o;
	$o = db_hosted_order( $projection ); $o['order']['reference_id'] = 'db-attempt-38'; $bad_cases['reference'] = $o;
	$o = db_hosted_order( $projection ); $o['order']['rewards'] = array( array( 'id' => 'r' ) ); $bad_cases['loyalty reward'] = $o;
	foreach ( $bad_cases as $label => $bad ) {
		assert_true( is_wp_error( DoughBoss_Square_Orders::validate_hosted_response( $bad, $projection, null, 'squareHOSTEDORDER0000001' ) ), 'unpaid link order rejects ' . $label );
	}
	assert_true( is_wp_error( DoughBoss_Square_Orders::validate_hosted_response( db_hosted_order( $projection ), $projection, null, 'squareOTHERORDER00000001' ) ), 'foreign order id rejected' );
} );

db_test( 'Square hosted: Order payment state never treats Order state as payment', function () {
	$projection = db_hosted_projection();
	$id = 'squareHOSTEDORDER0000001';
	assert_same( array( 'state' => 'unpaid' ), DoughBoss_Square_Hosted::order_payment_state( db_hosted_order( $projection, 'OPEN' ), $id ), 'OPEN without tender is unpaid' );
	assert_same( array( 'state' => 'canceled' ), DoughBoss_Square_Hosted::order_payment_state( db_hosted_order( $projection, 'CANCELED' ), $id ), 'cancelled link order' );
	list( $paid, $payment ) = db_hosted_paid( $projection );
	assert_same( array( 'state' => 'tendered', 'payment_id' => $payment['id'] ), DoughBoss_Square_Hosted::order_payment_state( $paid, $id ), 'one tender names its payment' );
	$two = $paid; $two['order']['tenders'][] = $two['order']['tenders'][0];
	assert_same( array( 'state' => 'invalid' ), DoughBoss_Square_Hosted::order_payment_state( $two, $id ), 'split tenders are held' );
	assert_same( array( 'state' => 'invalid' ), DoughBoss_Square_Hosted::order_payment_state( $paid, 'squareOTHERORDER00000001' ), 'foreign order is invalid' );
	assert_same( array( 'state' => 'invalid' ), DoughBoss_Square_Hosted::order_payment_state( array(), $id ), 'empty read is invalid' );
} );

db_test( 'Square hosted: paid proof accepts only the exact COMPLETED payment for the bound order', function () {
	$projection = db_hosted_projection();
	$id = 'squareHOSTEDORDER0000001';
	list( $paid, $payment ) = db_hosted_paid( $projection );
	assert_same( array( 'payment_id' => $payment['id'], 'order_id' => $id ), DoughBoss_Square_Hosted::paid_proof( $paid, $payment, $projection, $id, 'LOCATION_R_TEST' ), 'exact hosted payment proves the order' );
	$completed = $paid; $completed['order']['state'] = 'COMPLETED';
	assert_false( is_wp_error( DoughBoss_Square_Hosted::paid_proof( $completed, $payment, $projection, $id, 'LOCATION_R_TEST' ) ), 'completed order state is also fine' );
	$no_card_status = $payment; unset( $no_card_status['card_details'] );
	assert_false( is_wp_error( DoughBoss_Square_Hosted::paid_proof( $paid, $no_card_status, $projection, $id, 'LOCATION_R_TEST' ) ), 'sparse card details tolerated' );

	$bad_payments = array();
	$p = $payment; $p['amount_money']['amount'] = 1999; $bad_payments['short amount'] = $p;
	$p = $payment; $p['amount_money']['amount'] = 2001; $bad_payments['over amount'] = $p;
	$p = $payment; $p['amount_money']['amount'] = '2000'; $bad_payments['string amount'] = $p;
	$p = $payment; $p['amount_money']['currency'] = 'USD'; $bad_payments['currency'] = $p;
	$p = $payment; $p['location_id'] = 'OTHER_LOCATION'; $bad_payments['payment location'] = $p;
	$p = $payment; $p['order_id'] = 'squareOTHERORDER00000001'; $bad_payments['foreign payment order'] = $p;
	foreach ( array( 'APPROVED', 'PENDING', 'FAILED', 'CANCELED', 'completed', '' ) as $status ) {
		$p = $payment; $p['status'] = $status; $bad_payments[ 'status ' . $status ] = $p;
	}
	$p = $payment; $p['tip_money'] = array( 'amount' => 100, 'currency' => 'AUD' ); $bad_payments['tip'] = $p;
	$p = $payment; $p['total_money']['amount'] = 2100; $bad_payments['total with tip'] = $p;
	$p = $payment; $p['source_type'] = 'WALLET'; $bad_payments['wallet source'] = $p;
	$p = $payment; unset( $p['source_type'] ); $bad_payments['missing source'] = $p;
	$p = $payment; $p['card_details']['card']['card_brand'] = 'SQUARE_GIFT_CARD'; $bad_payments['gift card'] = $p;
	$p = $payment; $p['card_details']['status'] = 'AUTHORIZED'; $bad_payments['uncaptured'] = $p;
	foreach ( array( 'id', 'status', 'order_id', 'location_id', 'amount_money' ) as $field ) {
		$p = $payment; unset( $p[ $field ] ); $bad_payments[ 'missing ' . $field ] = $p;
	}
	foreach ( $bad_payments as $label => $bad ) {
		assert_true( is_wp_error( DoughBoss_Square_Hosted::paid_proof( $paid, $bad, $projection, $id, 'LOCATION_R_TEST' ) ), 'paid proof rejects ' . $label );
	}

	$bad_orders = array();
	$o = db_hosted_order( $projection, 'OPEN' ); $bad_orders['unpaid (no tender)'] = $o;
	$o = $paid; $o['order']['state'] = 'DRAFT'; $bad_orders['draft'] = $o;
	$o = $paid; $o['order']['state'] = 'CANCELED'; $bad_orders['canceled'] = $o;
	$o = $paid; $o['order']['tenders'][0]['payment_id'] = 'sqpayOTHER00000001'; $bad_orders['tender for another payment'] = $o;
	$o = $paid; $o['order']['tenders'][0]['type'] = 'SQUARE_GIFT_CARD'; $bad_orders['gift card tender'] = $o;
	$o = $paid; $o['order']['tenders'][0]['amount_money']['amount'] = 1500; $bad_orders['partial tender'] = $o;
	$o = $paid; $o['order']['tenders'][] = $o['order']['tenders'][0]; $bad_orders['split tender'] = $o;
	$o = $paid; $o['order']['net_amount_due_money']['amount'] = 500; $bad_orders['amount still due'] = $o;
	$o = $paid; $o['order']['location_id'] = 'OTHER_LOCATION'; $bad_orders['order location'] = $o;
	$o = $paid; $o['order']['reference_id'] = 'db-attempt-99'; $bad_orders['other attempt reference'] = $o;
	$o = $paid; $o['order']['id'] = 'squareOTHERORDER00000001'; $bad_orders['foreign order'] = $o;
	$o = $paid; $o['order']['total_money']['amount'] = 2500; $bad_orders['order total drift'] = $o;
	$o = $paid; $o['order']['refunds'] = array( array( 'id' => 'r' ) ); $bad_orders['refunded'] = $o;
	$o = $paid; $o['order']['fulfillments'][0]['type'] = 'SHIPMENT'; $bad_orders['shipment fulfillment'] = $o;
	foreach ( $bad_orders as $label => $bad ) {
		assert_true( is_wp_error( DoughBoss_Square_Hosted::paid_proof( $bad, $payment, $projection, $id, 'LOCATION_R_TEST' ) ), 'paid proof rejects ' . $label );
	}
	assert_true( is_wp_error( DoughBoss_Square_Hosted::paid_proof( $paid, $payment, $projection, 'squareOTHERORDER00000001', 'LOCATION_R_TEST' ) ), 'wrong bound order rejected' );
	assert_true( is_wp_error( DoughBoss_Square_Hosted::paid_proof( $paid, $payment, $projection, $id, 'OTHER_LOCATION' ) ), 'wrong bound location rejected' );
	$other_projection = $projection; $other_projection['amounts']['total'] = 2100;
	assert_true( is_wp_error( DoughBoss_Square_Hosted::paid_proof( $paid, $payment, $other_projection, $id, 'LOCATION_R_TEST' ) ), 'projection amount mismatch rejected' );
	$embedded = DoughBoss_Square_Orders::validate_paid_response( $paid, $projection, $payment, $id );
	assert_true( is_wp_error( $embedded ), 'embedded validator still requires its own payment reference_id' );
} );

db_test( 'Square hosted: attempts are recognised only by their stored hosted style', function () {
	$base = array( 'provider' => 'square', 'safe_metadata_json' => '{"protocol_version":"square-v2","checkout_style":"hosted"}' );
	assert_true( DoughBoss_Square_Hosted::is_hosted_attempt( $base ), 'hosted attempt' );
	$embedded = $base; $embedded['safe_metadata_json'] = '{"protocol_version":"square-v2"}';
	assert_false( DoughBoss_Square_Hosted::is_hosted_attempt( $embedded ), 'embedded attempt' );
	$stripe = $base; $stripe['provider'] = 'stripe';
	assert_false( DoughBoss_Square_Hosted::is_hosted_attempt( $stripe ), 'other provider' );
	assert_false( DoughBoss_Square_Hosted::is_hosted_attempt( null ), 'missing attempt' );
} );

db_test( 'Square hosted: checkout style setting defaults to embedded', function () {
	assert_same( 'embedded', DoughBoss_Settings::square_checkout_style(), 'default embedded' );
	update_option( DoughBoss_Settings::OPTION_KEY, array( 'payment_gateway' => 'square', 'square_checkout_style' => 'hosted' ) );
	assert_same( 'hosted', DoughBoss_Settings::square_checkout_style(), 'hosted when chosen' );
	assert_true( DoughBoss_Square_Hosted::enabled(), 'hosted enabled for Square gateway' );
	update_option( DoughBoss_Settings::OPTION_KEY, array( 'payment_gateway' => 'stripe', 'square_checkout_style' => 'hosted' ) );
	assert_false( DoughBoss_Square_Hosted::enabled(), 'never hosted Square for another gateway' );
	update_option( DoughBoss_Settings::OPTION_KEY, array( 'payment_gateway' => 'square', 'square_checkout_style' => 'HOSTED<script>' ) );
	assert_same( 'embedded', DoughBoss_Settings::square_checkout_style(), 'junk falls back to embedded' );
} );
