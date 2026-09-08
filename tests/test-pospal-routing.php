<?php
/**
 * Pure POSPal payload/mapping checks with synthetic products and customers.
 * Does not enqueue, send an order, call a provider or prove till delivery.
 *
 * @package DoughBoss
 */

require_once dirname( __DIR__ ) . '/includes/class-doughboss-pospal.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-pospal-orders.php';

/** @return object Synthetic order with no real customer data. */
function db_routing_order( $payment_status = 'unpaid' ) {
	return (object) array(
		'order_type'     => 'pickup',
		'order_number'   => 'SYNTHETIC-ROUTING-ONLY',
		'customer_name'  => 'Synthetic customer',
		'customer_phone' => '',
		'address'        => '',
		'notes'          => '',
		'created_at'     => '2026-09-08 00:00:00',
		'total'          => 18.0,
		'payment_status' => $payment_status,
	);
}

db_test(
	'POSPal payload preserves modifier text and server line price',
	function () {
		doughboss_test_set_settings( array( 'pospal_product_map' => array( 'test pizza' => '9007199254740993' ) ) );
		$build = DoughBoss_POSPal_Orders::build_body(
			db_routing_order(),
			array( array( 'name' => ' Test   PIZZA ', 'quantity' => 1, 'unit_price' => 18.0, 'size' => 'Large', 'toppings' => array( array( 'label' => 'Wholemeal' ), array( 'label' => 'No olives' ) ) ) )
		);
		assert_same( array(), $build['unmapped'], 'normalised menu name maps to the product' );
		assert_same( '9007199254740993', (string) $build['body']['items'][0]['productUid'], '64-bit product ID retains its exact digits' );
		assert_same( 18.0, $build['body']['items'][0]['manualSellPrice'], 'server-priced modifiers are included in the POS line price' );
		assert_true( false !== strpos( $build['body']['items'][0]['comment'], 'Wholemeal, No olives' ), 'kitchen modifier instructions are preserved' );
		assert_false( isset( $build['body']['payOnLine'] ), 'pay-at-shop is not marked online-paid' );
	}
);

db_test(
	'POSPal missing custom-pizza mapping is reported instead of guessed',
	function () {
		doughboss_test_set_settings( array( 'pospal_product_map' => array( 'test pizza' => '42' ) ) );
		$custom_name = 'Custom Pizza (Medium (12"))';
		$build = DoughBoss_POSPal_Orders::build_body( db_routing_order(), array( array( 'name' => $custom_name, 'quantity' => 1, 'unit_price' => 12.0 ) ) );
		assert_same( array( $custom_name ), $build['unmapped'], 'unmapped builder line is explicit' );
		assert_same( array(), $build['body']['items'], 'no fabricated POS product is sent' );
		// This documents the release prerequisite: on_order_created refuses the
		// whole push when unmapped is non-empty. It does not certify routing.
	}
);

db_test(
	'POSPal online-paid marker requires both payment and the explicit setting',
	function () {
		$item = array( array( 'name' => 'Test Pizza', 'quantity' => 1, 'unit_price' => 18.0 ) );
		doughboss_test_set_settings( array( 'pospal_product_map' => array( 'test pizza' => '42' ), 'pospal_order_pay_online' => 1 ) );
		$unpaid = DoughBoss_POSPal_Orders::build_body( db_routing_order( 'unpaid' ), $item );
		assert_false( isset( $unpaid['body']['payOnLine'] ), 'setting alone does not mark unpaid orders paid' );
		$paid = DoughBoss_POSPal_Orders::build_body( db_routing_order( 'paid' ), $item );
		assert_same( 1, $paid['body']['payOnLine'], 'verified paid order may be marked paid when explicitly configured' );
	}
);
