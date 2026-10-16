<?php
/**
 * 2.51.0 timed-pickup notifications: placed/delayed emails, delay SMS text,
 * accepted-ETA maths and kitchen-ticket pickup line (pure builders only; hook
 * wiring and wp_mail() need a WordPress runtime).
 *
 * @package DoughBoss
 */

require_once dirname( __DIR__ ) . '/includes/class-doughboss-emails.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-sms.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-printer.php';

if ( ! function_exists( 'wp_date' ) ) {
	/** Deterministic stand-in so the printer's fallback label can be exercised. */
	function wp_date( $format, $timestamp = null ) {
		return gmdate( $format, (int) $timestamp );
	}
}

/**
 * Synthetic paid Revesby pickup order for the 2.51 tests.
 *
 * @param array $extra Overrides.
 * @return array
 */
function db_test_251_order( array $extra = array() ) {
	return array_merge(
		array(
			'id'             => 77,
			'order_number'   => 'DB-261016-XYZ789',
			'order_type'     => 'pickup',
			'customer_name'  => 'Synthetic Customer',
			'subtotal'       => 11.0,
			'discount'       => 0,
			'delivery_fee'   => 0,
			'tax'            => 1.0,
			'total'          => 11.0,
			'payment_status' => 'paid',
		),
		$extra
	);
}

/**
 * @return array
 */
function db_test_251_items() {
	return array( array( 'name' => 'Zaatar Manoush', 'size' => '', 'toppings' => array(), 'quantity' => 2, 'unit_price' => 5.5, 'line_total' => 11.0 ) );
}

/**
 * @return array
 */
function db_test_251_location() {
	return array( 'name' => 'Revesby', 'suburb' => 'Revesby', 'address' => 'Shop 12/25 Selems Parade, Revesby NSW 2212', 'phone' => '(02) 9774 2286' );
}

db_test(
	'2.51 placed email: scheduled pickup names the shop and the chosen time',
	function () {
		$order = db_test_251_order( array( 'pickup_mode' => 'scheduled', 'pickup_label' => 'Today 12:45pm' ) );
		$email = DoughBoss_Emails::placed_email( $order, db_test_251_items(), db_test_251_location(), '', '$' );
		$body  = $email['body'];
		assert_true( false !== strpos( $body, "Pickup at Dough Boss Revesby, Today 12:45pm\nShop 12/25 Selems Parade, Revesby NSW 2212\nPhone: (02) 9774 2286" ), 'pickup line then address and phone' );
		assert_true( false === strpos( $body, 'Pickup from:' ), 'no legacy Pickup from: line' );
		assert_true( 1 === substr_count( $body, 'Dough Boss Revesby' ), 'shop name appears once' );
		assert_true( false === strpos( $body, 'Estimated ready' ), 'scheduled orders carry no ETA line' );
		assert_same( 'Dough Boss order DB-261016-XYZ789 confirmed', $email['subject'], 'subject unchanged' );
	}
);

db_test(
	'2.51 placed email: ASAP adds the estimated-ready line',
	function () {
		$order = db_test_251_order( array( 'pickup_mode' => 'asap', 'pickup_label' => 'Today 12:25pm', 'pickup_eta_minutes' => 25 ) );
		$body  = DoughBoss_Emails::placed_email( $order, db_test_251_items(), db_test_251_location() )['body'];
		assert_true( false !== strpos( $body, "Pickup at Dough Boss Revesby, Today 12:25pm\nEstimated ready: about 25 min (Today 12:25pm)\n" ), 'ASAP shows pickup time and estimate' );
		$no_eta = DoughBoss_Emails::placed_email( db_test_251_order( array( 'pickup_mode' => 'asap', 'pickup_label' => 'Today 12:25pm' ) ), db_test_251_items(), db_test_251_location() )['body'];
		assert_true( false === strpos( $no_eta, 'Estimated ready' ), 'no estimate line without minutes' );
	}
);

db_test(
	'2.51 placed email: legacy and unlabelled orders are byte-identical to before',
	function () {
		$base = DoughBoss_Emails::placed_email( db_test_251_order(), db_test_251_items(), db_test_251_location(), 'https://example.test/t', '$' );
		$legacy = DoughBoss_Emails::placed_email( db_test_251_order( array( 'pickup_mode' => '', 'pickup_label' => 'Today 12:45pm', 'pickup_eta_minutes' => 20 ) ), db_test_251_items(), db_test_251_location(), 'https://example.test/t', '$' );
		assert_same( $base, $legacy, 'pickup_mode empty ignores label and ETA' );
		assert_true( false !== strpos( $base['body'], "Pickup from:\nDough Boss Revesby\nShop 12/25 Selems Parade, Revesby NSW 2212\nPhone: (02) 9774 2286" ), 'legacy block intact' );
		$no_label = DoughBoss_Emails::placed_email( db_test_251_order( array( 'pickup_mode' => 'scheduled' ) ), db_test_251_items(), db_test_251_location(), 'https://example.test/t', '$' );
		assert_same( $base, $no_label, 'a timed order whose label is unavailable falls back to the legacy block' );
		$delivery = DoughBoss_Emails::placed_email( db_test_251_order( array( 'order_type' => 'delivery', 'address' => '1 Test St', 'pickup_mode' => 'scheduled', 'pickup_label' => 'Today 1pm' ) ), db_test_251_items(), db_test_251_location() );
		assert_true( false === strpos( $delivery['body'], 'Pickup at' ), 'delivery orders never use the pickup wording' );
	}
);

db_test(
	'2.51 placed email: timed pickup without a stored shop still names the time',
	function () {
		$body = DoughBoss_Emails::placed_email( db_test_251_order( array( 'pickup_mode' => 'scheduled', 'pickup_label' => 'Tomorrow 7:30am' ) ), db_test_251_items(), array() )['body'];
		assert_true( false !== strpos( $body, "Pickup: Tomorrow 7:30am\nThe shop you selected at checkout." ), 'time then generic shop line' );
	}
);

db_test(
	'2.51 delayed email: subject, new time and apology',
	function () {
		$email = DoughBoss_Emails::delayed_email( db_test_251_order(), 10, 'Today 1:05pm', 'Revesby' );
		assert_same( 'Dough Boss order DB-261016-XYZ789: new pickup time', $email['subject'], 'subject' );
		$body = $email['body'];
		assert_true( 0 === strpos( $body, "Hi Synthetic,\n" ), 'greets by first name' );
		assert_true( false !== strpos( $body, 'Order DB-261016-XYZ789 will now be ready for pickup about Today 1:05pm.' ), 'new time' );
		assert_true( false !== strpos( $body, 'That is 10 minutes later than we first said.' ), 'minutes later' );
		assert_true( false !== strpos( $body, 'Pickup from: Dough Boss Revesby' ), 'shop' );
		assert_true( false === strpos( $body, "\r" ) && false === strpos( $body, '<' ), 'plain LF text' );
		$one = DoughBoss_Emails::delayed_email( db_test_251_order(), 1, 'Today 1:05pm', 'Revesby' );
		assert_true( false !== strpos( $one['body'], 'That is 1 minute later' ), 'singular minute' );
		$bare = DoughBoss_Emails::delayed_email( array( 'id' => 5 ), 0, '', '' );
		assert_true( false !== strpos( $bare['body'], 'Hi there,' ) && false !== strpos( $bare['body'], 'a little later than planned' ), 'degrades without name or label' );
		assert_same( 'Dough Boss order 5: new pickup time', $bare['subject'], 'falls back to the id' );
	}
);

db_test(
	'2.51 accepted ETA: minutes until the promise, never negative',
	function () {
		$now   = strtotime( '2026-10-16 02:00:00 UTC' );
		$order = array( 'pickup_mode' => 'scheduled', 'promised_ready_from_utc' => '2026-10-16 02:25:30' );
		assert_same( 26, DoughBoss_Emails::minutes_until_promise( $order, $now ), 'rounds up to whole minutes' );
		assert_same( 0, DoughBoss_Emails::minutes_until_promise( array( 'promised_ready_from_utc' => '2026-10-16 01:00:00' ), $now ), 'a passed promise is zero' );
		assert_same( 0, DoughBoss_Emails::minutes_until_promise( array(), $now ), 'no promise is zero' );
		assert_true( DoughBoss_Emails::is_timed_order( $order ), 'scheduled is timed' );
		assert_true( DoughBoss_Emails::is_timed_order( array( 'pickup_mode' => 'asap' ) ), 'asap is timed' );
		assert_false( DoughBoss_Emails::is_timed_order( array( 'pickup_mode' => '' ) ), 'legacy is not timed' );
		assert_false( DoughBoss_Emails::is_timed_order( array() ), 'missing mode is not timed' );
	}
);

db_test(
	'2.51 delayed SMS text',
	function () {
		assert_same( 'Dough Boss: order DB-1 will now be ready about Today 1:05pm. Sorry for the wait.', DoughBoss_SMS::delayed_message( 'DB-1', 'Today 1:05pm' ), 'message with label' );
		assert_same( 'Dough Boss: order DB-1 will now be ready a little later than planned. Sorry for the wait.', DoughBoss_SMS::delayed_message( 'DB-1', '' ), 'message without label' );
		assert_true( false === strpos( DoughBoss_SMS::delayed_message( "DB\n1", "Today\r\n1pm" ), "\n" ), 'control characters are stripped' );
	}
);

db_test(
	'2.51 kitchen ticket pickup line',
	function () {
		$method = new ReflectionMethod( 'DoughBoss_Printer', 'pickup_line' );
		$method->setAccessible( true );
		$legacy = (object) array( 'pickup_mode' => '', 'promised_ready_from_utc' => '2026-10-16 02:45:00' );
		assert_same( '', $method->invoke( null, $legacy ), 'legacy order prints no pickup line' );
		assert_same( '', $method->invoke( null, (object) array() ), 'row without the column prints nothing' );
		$asap = (object) array( 'pickup_mode' => 'asap', 'promised_ready_from_utc' => '2026-10-16 02:45:00', 'timezone_snapshot' => 'Australia/Sydney' );
		$line = $method->invoke( null, $asap );
		assert_true( 0 === strpos( $line, 'Pickup: ' ) && ' (ASAP)' === substr( $line, -7 ), 'ASAP ticket line' );
		$sched = (object) array( 'pickup_mode' => 'scheduled', 'promised_ready_from_utc' => '2026-10-16 02:45:00' );
		$line  = $method->invoke( null, $sched );
		assert_true( ' (Scheduled)' === substr( $line, -12 ), 'Scheduled ticket line' );
		assert_same( '', $method->invoke( null, (object) array( 'pickup_mode' => 'asap', 'promised_ready_from_utc' => '' ) ), 'no promise prints nothing' );
	}
);
