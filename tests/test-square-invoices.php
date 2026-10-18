<?php
/**
 * Catering via Square invoices, business-day notice, and card-decline handling.
 *
 * @package DoughBoss
 */

require_once dirname( __DIR__ ) . '/includes/class-doughboss-catering.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-square-invoices.php';

function doughboss_test_invoice_enquiry( $overrides = array() ) {
	return array_merge(
		array(
			'id'                => 7,
			'enquiry_number'    => 'CAT-1007',
			'location_id'       => 1,
			'customer_name'     => 'Sam Example',
			'customer_email'    => 'sam@example.com',
			'customer_phone'    => '0412 345 678',
			'event_date'        => '2026-10-30',
			'event_time'        => '11:00',
			'guest_count'       => 40,
			'order_type'        => 'pickup',
			'quote_total'       => 600.00,
			'deposit_amount'    => 150.00,
			'balance_amount'    => 450.00,
			'deposit_intent_id' => '',
			'balance_intent_id' => '',
		),
		$overrides
	);
}

db_test(
	'catering notice counts business days and skips weekends',
	function () {
		assert_same( '2026-10-20', DoughBoss_Catering::earliest_event_date( '2026-10-16', 2 ), 'Friday + 2 business days = Tuesday' );
		assert_same( '2026-10-15', DoughBoss_Catering::earliest_event_date( '2026-10-13', 2 ), 'Tuesday + 2 = Thursday' );
		assert_same( '2026-10-20', DoughBoss_Catering::earliest_event_date( '2026-10-17', 2 ), 'Saturday + 2 = Tuesday' );
		assert_same( '2026-10-20', DoughBoss_Catering::earliest_event_date( '2026-10-18', 2 ), 'Sunday + 2 = Tuesday' );
		assert_same( '2026-10-15', DoughBoss_Catering::earliest_event_date( '2026-10-15', 0 ), 'zero notice is today' );
		doughboss_test_set_settings( array() );
		assert_same( 2, DoughBoss_Catering::min_notice_business_days(), 'default notice is 2 business days' );
		doughboss_test_set_settings( array( 'catering_min_notice_business_days' => 99 ) );
		assert_same( 30, DoughBoss_Catering::min_notice_business_days(), 'notice is clamped to 30' );
		doughboss_test_set_settings( array( 'catering_min_notice_business_days' => -4 ) );
		assert_same( 0, DoughBoss_Catering::min_notice_business_days(), 'notice never goes negative' );
		doughboss_test_set_settings( array() );
	}
);

db_test(
	'square invoice ids and intents are recognised safely',
	function () {
		assert_same( 'inv:0-ChCHu2mZEabLeeHahQnXDjZQECY', DoughBoss_Square_Invoices::canonical_invoice_id( 'inv:0-ChCHu2mZEabLeeHahQnXDjZQECY' ), 'real invoice id shape is accepted' );
		assert_same( '', DoughBoss_Square_Invoices::canonical_invoice_id( 'inv/../x' ), 'path characters are rejected' );
		assert_same( '', DoughBoss_Square_Invoices::canonical_invoice_id( str_repeat( 'a', 59 ) ), 'ids too long for the intent column are rejected' );
		assert_same( '', DoughBoss_Square_Invoices::canonical_invoice_id( array( 'x' ) ), 'non-scalars are rejected' );
		assert_true( strlen( DoughBoss_Square_Invoices::INTENT_PREFIX . str_repeat( 'a', 58 ) ) <= 64, 'prefix + max id fits varchar(64)' );
		assert_true( DoughBoss_Square_Invoices::is_invoice_intent( 'sqinv:inv:0-abc12345' ), 'invoice intent detected' );
		assert_false( DoughBoss_Square_Invoices::is_invoice_intent( 'sqinv:' ), 'bare prefix is not an intent' );
		assert_false( DoughBoss_Square_Invoices::is_invoice_intent( 'pi_123' ), 'card intents are not invoice intents' );
	}
);

db_test(
	'australian phones normalise to E.164 or blank',
	function () {
		assert_same( '+61412345678', DoughBoss_Square_Invoices::e164_au( '0412 345 678' ), 'mobile' );
		assert_same( '+61297771234', DoughBoss_Square_Invoices::e164_au( '(02) 9777 1234' ), 'landline' );
		assert_same( '+61412345678', DoughBoss_Square_Invoices::e164_au( '+61 412 345 678' ), 'already E.164' );
		assert_same( '', DoughBoss_Square_Invoices::e164_au( '12345' ), 'junk is blank, not guessed' );
	}
);

db_test(
	'invoice due dates never fall after the event or in the past',
	function () {
		$e = doughboss_test_invoice_enquiry();
		assert_same( '2026-10-17', DoughBoss_Square_Invoices::due_date( $e, 'deposit', '2026-10-15' ), 'deposit due in 2 days' );
		assert_same( '2026-10-28', DoughBoss_Square_Invoices::due_date( $e, 'balance', '2026-10-15' ), 'balance due 2 days before the event' );
		assert_same( '2026-10-29', DoughBoss_Square_Invoices::due_date( $e, 'balance', '2026-10-29' ), 'late balance is due today, not in the past' );
		$soon = doughboss_test_invoice_enquiry( array( 'event_date' => '2026-10-16' ) );
		assert_same( '2026-10-16', DoughBoss_Square_Invoices::due_date( $soon, 'deposit', '2026-10-15' ), 'deposit capped at the event date' );
	}
);

db_test(
	'order and invoice bodies carry exact amounts and card-only email delivery',
	function () {
		$e     = doughboss_test_invoice_enquiry();
		$order = DoughBoss_Square_Invoices::order_body( $e, 'deposit', 15000, 'LHMBV6NDDD4BC', 'CUST1' );
		assert_same( 15000, $order['order']['line_items'][0]['base_price_money']['amount'], 'order amount in cents' );
		assert_same( 'AUD', $order['order']['line_items'][0]['base_price_money']['currency'], 'AUD' );
		assert_same( 'LHMBV6NDDD4BC', $order['order']['location_id'], 'bound to the Square location' );
		assert_same( 'CAT-1007:deposit', $order['order']['reference_id'], 'reference ties back to the enquiry' );
		assert_true( strlen( $order['idempotency_key'] ) <= 45, 'idempotency key within Square limit' );

		$inv = DoughBoss_Square_Invoices::invoice_body( $e, 'balance', 45000, 'LHMBV6NDDD4BC', 'ORD1', 'CUST1', '2026-10-15' );
		assert_same( 'CAT-1007-B', $inv['invoice']['invoice_number'], 'balance invoice number' );
		assert_same( 'EMAIL', $inv['invoice']['delivery_method'], 'Square emails the invoice' );
		assert_same( 'BALANCE', $inv['invoice']['payment_requests'][0]['request_type'], 'single full-amount request' );
		assert_true( $inv['invoice']['accepted_payment_methods']['card'], 'cards accepted' );
		assert_false( $inv['invoice']['accepted_payment_methods']['bank_account'], 'no slow bank debits' );
		assert_true( false !== strpos( $inv['invoice']['description'], 'Revesby' ), 'pickup location named' );
		$dep = DoughBoss_Square_Invoices::invoice_body( $e, 'deposit', 15000, 'LHMBV6NDDD4BC', 'ORD1', 'CUST1', '2026-10-15' );
		assert_same( 'CAT-1007-D', $dep['invoice']['invoice_number'], 'deposit invoice number' );
		assert_true( $dep['idempotency_key'] !== $inv['idempotency_key'], 'legs never share idempotency keys' );
		assert_same( $dep['idempotency_key'], DoughBoss_Square_Invoices::invoice_body( $e, 'deposit', 15000, 'X', 'Y', 'Z', '2026-10-20' )['idempotency_key'], 'retries reuse the same key' );
	}
);

db_test(
	'only a PAID invoice for the exact amount at our location settles a leg',
	function () {
		$paid = array(
			'status'           => 'PAID',
			'location_id'      => 'LHMBV6NDDD4BC',
			'payment_requests' => array(
				array(
					'computed_amount_money'        => array( 'amount' => 15000, 'currency' => 'AUD' ),
					'total_completed_amount_money' => array( 'amount' => 15000, 'currency' => 'AUD' ),
				),
			),
		);
		assert_true( DoughBoss_Square_Invoices::invoice_settles( $paid, 15000, 'LHMBV6NDDD4BC' ), 'exact paid invoice settles' );
		assert_false( DoughBoss_Square_Invoices::invoice_settles( array_merge( $paid, array( 'status' => 'UNPAID' ) ), 15000, 'LHMBV6NDDD4BC' ), 'unpaid does not settle' );
		assert_false( DoughBoss_Square_Invoices::invoice_settles( $paid, 15000, 'OTHERLOC' ), 'other location does not settle' );
		assert_false( DoughBoss_Square_Invoices::invoice_settles( $paid, 45000, 'LHMBV6NDDD4BC' ), 'amount mismatch does not settle' );
		$usd = $paid;
		$usd['payment_requests'][0]['total_completed_amount_money']['currency'] = 'USD';
		assert_false( DoughBoss_Square_Invoices::invoice_settles( $usd, 15000, 'LHMBV6NDDD4BC' ), 'wrong currency does not count' );
		assert_false( DoughBoss_Square_Invoices::invoice_settles( $paid, 0, 'LHMBV6NDDD4BC' ), 'zero expectation never settles' );
	}
);

db_test(
	'card refusals are recognised as safe-to-retry declines',
	function () {
		$decline = new WP_Error( 'doughboss_pay_api', 'x', array( 'status' => 502, 'square_http' => 402, 'square_category' => 'PAYMENT_METHOD_ERROR', 'square_code' => 'CARD_DECLINED' ) );
		assert_true( DoughBoss_Square::is_payment_method_refusal( $decline ), 'card declined is a refusal' );
		$bad_cvv = new WP_Error( 'doughboss_pay_api', 'x', array( 'status' => 502, 'square_http' => 400, 'square_category' => 'PAYMENT_METHOD_ERROR', 'square_code' => 'CVV_FAILURE' ) );
		assert_true( DoughBoss_Square::is_payment_method_refusal( $bad_cvv ), 'CVV failure is a refusal' );
		$outage = new WP_Error( 'doughboss_pay_api', 'x', array( 'status' => 502, 'square_http' => 500, 'square_category' => 'API_ERROR', 'square_code' => 'INTERNAL_SERVER_ERROR' ) );
		assert_false( DoughBoss_Square::is_payment_method_refusal( $outage ), 'server errors stay unknown (money may have moved)' );
		$odd = new WP_Error( 'doughboss_pay_api', 'x', array( 'status' => 502, 'square_http' => 500, 'square_category' => 'PAYMENT_METHOD_ERROR' ) );
		assert_false( DoughBoss_Square::is_payment_method_refusal( $odd ), 'refusal category on a 5xx is not trusted' );
		assert_false( DoughBoss_Square::is_payment_method_refusal( new WP_Error( 'other', 'x', array( 'square_http' => 402, 'square_category' => 'PAYMENT_METHOD_ERROR' ) ) ), 'only API errors qualify' );
		assert_false( DoughBoss_Square::is_payment_method_refusal( array() ), 'non-errors never qualify' );
	}
);

require_once dirname( __DIR__ ) . '/includes/class-doughboss-square-loyalty.php';

db_test( 'Square Loyalty accrual requires an explicit saved opt-in', function () {
	$before = $GLOBALS['doughboss_test_options'];
	try {
		$defaults = DoughBoss_Settings::defaults();
		assert_same( 0, $defaults['square_loyalty_accrual'], 'new settings default to no accrual' );
		doughboss_test_set_settings( array() );
		assert_false( DoughBoss_Square_Loyalty::enabled(), 'missing opt-in stays off' );
		foreach ( array( 0, false, '0' ) as $off ) {
			doughboss_test_set_settings( array( 'square_loyalty_accrual' => $off ) );
			assert_false( DoughBoss_Square_Loyalty::enabled(), 'explicit saved off stays off' );
		}
		foreach ( array( 1, true, '1' ) as $on ) {
			doughboss_test_set_settings( array( 'square_loyalty_accrual' => $on ) );
			assert_true( DoughBoss_Square_Loyalty::enabled(), 'explicit saved opt-in is preserved' );
		}
		$admin = file_get_contents( dirname( __DIR__ ) . '/admin/class-doughboss-admin.php' );
		assert_true( false !== strpos( $admin, 'checked( DoughBoss_Square_Loyalty::enabled(), true )' ), 'admin checkbox uses the same runtime opt-in decision' );
	} finally {
		$GLOBALS['doughboss_test_options'] = $before;
	}
} );

db_test(
	'square loyalty accrues only for square-paid orders with a usable phone',
	function () {
		$ok = array( 'payment_status' => 'paid', 'payment_intent_id' => 'R2b3c4d5e6f7g8h9', 'customer_phone' => '0412345678' );
		assert_true( DoughBoss_Square_Loyalty::eligible_data( $ok ), 'paid square order with mobile is eligible' );
		assert_false( DoughBoss_Square_Loyalty::eligible_data( array_merge( $ok, array( 'payment_status' => 'unpaid' ) ) ), 'unpaid orders earn nothing' );
		assert_false( DoughBoss_Square_Loyalty::eligible_data( array_merge( $ok, array( 'payment_intent_id' => 'pi_123' ) ) ), 'stripe payments are not square' );
		assert_false( DoughBoss_Square_Loyalty::eligible_data( array_merge( $ok, array( 'customer_phone' => 'n/a' ) ) ), 'no phone, no lookup' );
		$key = DoughBoss_Square_Loyalty::idempotency_key( 'ORDER123456' );
		assert_true( strlen( $key ) <= 45, 'accumulate key within Square limit' );
		assert_same( $key, DoughBoss_Square_Loyalty::idempotency_key( 'ORDER123456' ), 'retries reuse the same key' );
		assert_same( 'buyer@example.com', DoughBoss_Square::safe_buyer_email( ' buyer@example.com ' ), 'buyer email trimmed' );
		assert_same( '', DoughBoss_Square::safe_buyer_email( 'not-an-email' ), 'invalid buyer email dropped' );
		assert_same( '', DoughBoss_Square::safe_buyer_email( null ), 'missing buyer email dropped' );
	}
);

db_test(
	'square refund events only settle a completed refund of the same payment',
	function () {
		$attempt = array( 'provider_reference' => 'PAY12345678', 'amount_minor' => 2550, 'currency' => 'AUD' );
		$refund  = array( 'status' => 'COMPLETED', 'payment_id' => 'PAY12345678', 'amount_money' => array( 'amount' => 2550, 'currency' => 'AUD' ) );
		assert_same( 'full', DoughBoss_Square::refund_settlement( $refund, $attempt ), 'full completed refund' );
		$part = $refund; $part['amount_money']['amount'] = 500;
		assert_same( 'partial', DoughBoss_Square::refund_settlement( $part, $attempt ), 'partial refund leaves order paid' );
		assert_same( 'none', DoughBoss_Square::refund_settlement( array_merge( $refund, array( 'status' => 'PENDING' ) ), $attempt ), 'pending refund changes nothing' );
		assert_same( 'none', DoughBoss_Square::refund_settlement( array_merge( $refund, array( 'payment_id' => 'OTHER1234567' ) ), $attempt ), 'other payment ignored' );
		$usd = $refund; $usd['amount_money']['currency'] = 'USD';
		assert_same( 'none', DoughBoss_Square::refund_settlement( $usd, $attempt ), 'currency mismatch ignored' );
		assert_same( 'none', DoughBoss_Square::refund_settlement( array(), $attempt ), 'malformed refund ignored' );
	}
);

require_once dirname( __DIR__ ) . '/includes/class-doughboss-square-tiers.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-square-wallets.php';

db_test(
	'member tiers map to fixed Square customer groups',
	function () {
		assert_same( 'student', DoughBoss_Square_Tiers::tier( ' Student ' ), 'tier normalised' );
		assert_same( '', DoughBoss_Square_Tiers::tier( 'vip' ), 'unknown tier rejected' );
		assert_same( 'DoughBoss Tradie', DoughBoss_Square_Tiers::group_name( 'tradie' ), 'tradie group name' );
		$groups = array( array( 'id' => 'G1', 'name' => 'Other' ), array( 'id' => 'G2', 'name' => 'doughboss club ' ) );
		assert_same( 'G2', DoughBoss_Square_Tiers::find_group( $groups, 'DoughBoss Club' ), 'case-insensitive exact group match' );
		assert_same( '', DoughBoss_Square_Tiers::find_group( $groups, 'DoughBoss Student' ), 'missing group is created, not guessed' );
		assert_same( '', DoughBoss_Square_Tiers::find_group( 'junk', 'DoughBoss Club' ), 'malformed list ignored' );
		assert_true( DoughBoss_Square_Tiers::club_active( 'ACTIVE' ), 'active subscription = club member' );
		foreach ( array( 'PENDING', 'CANCELED', 'PAUSED', 'DEACTIVATED', '' ) as $s ) {
			assert_false( DoughBoss_Square_Tiers::club_active( $s ), $s . ' subscription is not club' );
		}
		assert_true( DoughBoss_Square_Wallets::is_association_request( '/.well-known/apple-developer-merchantid-domain-association' ), 'apple association path' );
		assert_true( DoughBoss_Square_Wallets::is_association_request( '/.well-known/apple-developer-merchantid-domain-association?x=1' ), 'query string tolerated' );
		assert_false( DoughBoss_Square_Wallets::is_association_request( '/.well-known/other' ), 'other paths untouched' );
	}
);

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-' . $scheme;
	}
}
require_once dirname( __DIR__ ) . '/includes/class-doughboss-preorder-approval.php';

db_test(
	'a declined approved pre-order rotates to a fresh, deterministic checkout key',
	function () {
		$old = str_repeat( 'a', 64 );
		$k1  = DoughBoss_Preorder_Approval::retry_checkout_key( $old, 11 );
		assert_true( 1 === preg_match( '/^[a-f0-9]{64}$/', $k1 ), 'successor key fits the char(64) unique column' );
		assert_true( $k1 !== $old, 'successor differs from the declined key' );
		assert_same( $k1, DoughBoss_Preorder_Approval::retry_checkout_key( $old, 11 ), 'same decline always yields the same key' );
		assert_true( $k1 !== DoughBoss_Preorder_Approval::retry_checkout_key( $old, 12 ), 'each declined attempt gets its own successor' );
		assert_same( 5, DoughBoss_Preorder_Approval::MAX_DECLINE_RETRIES, 'declines are capped against card testing' );
	}
);

db_test(
	'commerce paths accept Square invoice ids (inv:0-…) but never traversal',
	function () {
		$r = DoughBoss_Square::commerce_request( 'POST', '/v2/invoices/inv:0-ChAbCdEf12345678/publish', array(), 0 );
		assert_true( is_wp_error( $r ) && 'doughboss_pay_config' !== $r->get_error_code() || ( is_wp_error( $r ) && false === strpos( $r->get_error_message(), 'not available' ) ), 'invoice id with colon passes the path guard' );
		$bad = DoughBoss_Square::commerce_request( 'GET', '/v2/invoices/../payments', array(), 0 );
		assert_true( is_wp_error( $bad ) && false !== strpos( $bad->get_error_message(), 'not available' ), 'traversal still rejected' );
		$other = DoughBoss_Square::commerce_request( 'POST', '/v2/payments', array(), 0 );
		assert_true( is_wp_error( $other ) && false !== strpos( $other->get_error_message(), 'not available' ), 'non-commerce endpoints still rejected' );
	}
);
