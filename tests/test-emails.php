<?php
/**
 * Paid-order "placed" confirmation email body tests (pure builder only).
 *
 * The hook wiring, wp_mail(), the stage log and the delivery lock need a real
 * WordPress runtime and are not exercised here.
 *
 * @package DoughBoss
 */

require_once dirname( __DIR__ ) . '/includes/class-doughboss-emails.php';

/**
 * A synthetic paid Revesby pickup order.
 *
 * @return array
 */
function db_test_placed_order() {
	return array(
		'id'            => 41,
		'order_number'  => 'DB-261015-ABC123',
		'order_type'    => 'pickup',
		'customer_name' => 'Synthetic Customer',
		'subtotal'      => 27.5,
		'discount'      => 2.5,
		'voucher_code'  => 'STUDENT5',
		'delivery_fee'  => 0,
		'tax'           => 2.27,
		'total'         => 25.0,
		'notes'         => 'Extra napkins please',
		'payment_status' => 'paid',
	);
}

/**
 * Synthetic order items, including a modifier array and a JSON-string option list.
 *
 * @return array
 */
function db_test_placed_items() {
	return array(
		array( 'name' => 'Zaatar Manoush', 'size' => '', 'toppings' => array( array( 'slug' => 'extra-oil', 'label' => 'Extra oil', 'price' => 0 ) ), 'quantity' => 2, 'unit_price' => 5.5, 'line_total' => 11.0 ),
		array( 'name' => 'Spinach Pie', 'size' => 'Large', 'toppings' => '["No onion"]', 'quantity' => 1, 'unit_price' => 16.5, 'line_total' => 16.5 ),
	);
}

/**
 * Synthetic stored location row (the live facts for Revesby).
 *
 * @return array
 */
function db_test_placed_location() {
	return array( 'name' => 'Revesby', 'suburb' => 'Revesby', 'address' => 'Shop 12/25 Selems Parade, Revesby NSW 2212', 'phone' => '(02) 9774 2286' );
}

db_test(
	'placed email itemises lines, options, money and GST exactly as stored',
	function () {
		$email = DoughBoss_Emails::placed_email( db_test_placed_order(), db_test_placed_items(), db_test_placed_location(), 'https://example.test/track-order/?order=DB-261015-ABC123', '$' );
		$body  = $email['body'];
		assert_same( 'Dough Boss order DB-261015-ABC123 confirmed', $email['subject'], 'subject carries the order number' );
		assert_true( 0 === strpos( $body, "Hi Synthetic,\n" ), 'greets by first name' );
		assert_true( false !== strpos( $body, 'Order number: DB-261015-ABC123' ), 'order number line' );
		assert_true( false !== strpos( $body, '2 x Zaatar Manoush  $11.00' ), 'qty x name with line total' );
		assert_true( false !== strpos( $body, '    + Extra oil' ), 'modifier label from array option' );
		assert_true( false !== strpos( $body, '1 x Spinach Pie (Large)  $16.50' ), 'size is shown with the line' );
		assert_true( false !== strpos( $body, '    + No onion' ), 'JSON-string options are decoded' );
		assert_true( false !== strpos( $body, 'Subtotal: $27.50' ), 'subtotal' );
		assert_true( false !== strpos( $body, 'Voucher STUDENT5: -$2.50' ), 'voucher discount' );
		assert_true( false !== strpos( $body, 'Total paid: $25.00' ), 'total' );
		assert_true( false !== strpos( $body, 'Includes GST of $2.27' ), 'GST included line' );
		assert_true( false === strpos( $body, 'Delivery:' ), 'no zero delivery fee line' );
		assert_true( false !== strpos( $body, 'Your notes: Extra napkins please' ), 'order notes' );
		assert_true( false !== strpos( $body, "Pickup from:\nDough Boss Revesby\nShop 12/25 Selems Parade, Revesby NSW 2212\nPhone: (02) 9774 2286" ), 'pickup shop name, address and phone' );
		assert_true( false !== strpos( $body, 'Track your order: https://example.test/track-order/?order=DB-261015-ABC123' ), 'tracking link' );
		assert_true( false === strpos( $body, '<' ), 'plain text only' );
		assert_true( false === strpos( $body, "\r" ), 'LF-only body' );
	}
);

db_test(
	'placed email degrades honestly without tracking page, GST or shop data',
	function () {
		$order = db_test_placed_order();
		$order['tax']           = 0;
		$order['discount']      = 0;
		$order['notes']         = '';
		$order['customer_name'] = '';
		$email = DoughBoss_Emails::placed_email( $order, db_test_placed_items(), array(), '', '$' );
		$body  = $email['body'];
		assert_true( 0 === strpos( $body, "Hi there,\n" ), 'neutral greeting without a name' );
		assert_true( false === strpos( $body, 'GST' ), 'no invented GST figure when none is stored' );
		assert_true( false === strpos( $body, 'Voucher' ) && false === strpos( $body, 'Discount' ), 'no zero discount line' );
		assert_true( false !== strpos( $body, 'The shop you selected at checkout.' ), 'no invented shop details' );
		assert_true( false !== strpos( $body, 'Keep order DB-261015-ABC123 and use it with the same email address on the Track My Order page.' ), 'tracking fallback copy' );
	}
);

db_test(
	'placed email strips markup and control characters from stored text',
	function () {
		$items = array( array( 'name' => "<b>Meat</b>\r\nPie", 'quantity' => 1, 'line_total' => 9 ) );
		$email = DoughBoss_Emails::placed_email( db_test_placed_order(), $items, db_test_placed_location(), '', '$' );
		assert_true( false !== strpos( $email['body'], '1 x Meat Pie  $9.00' ), 'tags and CR/LF collapse to plain text' );
	}
);

db_test(
	'shop display names are prefixed once',
	function () {
		assert_same( 'Dough Boss Bankstown', DoughBoss_Emails::shop_display_name( 'Bankstown' ), 'prefixed' );
		assert_same( 'Dough Boss Roselands', DoughBoss_Emails::shop_display_name( 'Dough Boss Roselands' ), 'not double-prefixed' );
		assert_same( '', DoughBoss_Emails::shop_display_name( '  ' ), 'empty stays empty' );
	}
);

db_test(
	'placed confirmation toggle defaults on and can be switched off',
	function () {
		doughboss_test_set_settings( array() );
		assert_true( DoughBoss_Settings::email_on_placed(), 'default on' );
		doughboss_test_set_settings( array( 'email_on_placed' => 0 ) );
		assert_false( DoughBoss_Settings::email_on_placed(), 'stored off is honoured' );
	}
);
