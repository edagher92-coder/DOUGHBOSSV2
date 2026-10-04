<?php
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
