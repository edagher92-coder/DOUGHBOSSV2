<?php
/**
 * DoughBoss_Stripe configuration and pure contract tests.
 *
 * These tests deliberately stop before payment-attempt storage or HTTP. They
 * cover the local readiness truth table, canonical provider references, the
 * pure hosted Checkout response validator, and webhook HMAC verification.
 *
 * @package DoughBoss
 */

require_once dirname( __DIR__ ) . '/includes/class-doughboss-stripe.php';

/* -------------------------------------------------------------------------- */
/* Fixtures                                                                    */
/* -------------------------------------------------------------------------- */

/**
 * Run a test with Stripe's environment overrides absent, then restore them.
 *
 * Values are retained only in memory and are never included in assertions or
 * output. Constants cannot be unset, but the dependency-free runner does not
 * load wp-config.php and therefore does not define these production overrides.
 *
 * @param callable $body Test body.
 * @return void
 */
function db_stripe_without_environment_overrides( $body ) {
	$names = array(
		'DOUGHBOSS_STRIPE_TEST_SK',
		'DOUGHBOSS_STRIPE_LIVE_SK',
		'DOUGHBOSS_STRIPE_TEST_WHSEC',
		'DOUGHBOSS_STRIPE_LIVE_WHSEC',
	);
	$prior = array();

	foreach ( $names as $name ) {
		$prior[ $name ] = getenv( $name );
		putenv( $name );
	}

	try {
		call_user_func( $body );
	} finally {
		foreach ( $prior as $name => $value ) {
			if ( false === $value ) {
				putenv( $name );
			} else {
				putenv( $name . '=' . $value );
			}
		}
	}
}

/**
 * Complete synthetic Stripe settings for the selected mode.
 *
 * @param array $overrides Keys to replace.
 * @return array
 */
function db_stripe_settings( array $overrides = array() ) {
	return array_merge(
		array(
			'payments_enabled'  => 1,
			'payment_gateway'   => 'stripe',
			'stripe_mode'       => 'test',
			'stripe_test_pk'    => '',
			'stripe_test_sk'    => 'sk_test_synthetic123456789',
			'stripe_test_whsec' => '',
			'stripe_live_pk'    => '',
			'stripe_live_sk'    => 'sk_live_synthetic123456789',
			'stripe_live_whsec' => 'whsec_live_synthetic123456789',
		),
		$overrides
	);
}

/**
 * Invoke the private, side-effect-free hosted Checkout response validator.
 *
 * The public creation path requires durable payment-attempt storage and an
 * HTTP request, neither of which belongs in the dependency-free test runner.
 * Reflection is already used by the real WordPress integration suite for a
 * similarly narrow private contract.
 *
 * @param array  $response     Synthetic Stripe response.
 * @param array  $attempt      Synthetic durable attempt.
 * @param int    $amount_minor Expected amount.
 * @param string $currency     Expected currency.
 * @return array|WP_Error
 */
function db_stripe_checkout_session_payload( array $response, array $attempt, $amount_minor, $currency ) {
	$method = new ReflectionMethod( 'DoughBoss_Stripe', 'checkout_session_payload' );
	$method->setAccessible( true );
	return $method->invoke( null, $response, $attempt, $amount_minor, $currency );
}

/**
 * Valid synthetic hosted Checkout response.
 *
 * @param array $overrides Keys to replace.
 * @return array
 */
function db_stripe_checkout_response( array $overrides = array() ) {
	return array_merge(
		array(
			'id'           => 'cs_test_SYNTHETIC123456789',
			'url'          => 'https://checkout.stripe.com/c/pay/synthetic-session',
			'amount_total' => 2750,
			'currency'     => 'aud',
		),
		$overrides
	);
}

/* -------------------------------------------------------------------------- */
/* Readiness                                                                   */
/* -------------------------------------------------------------------------- */

db_test(
	'Stripe readiness follows the local test-mode truth table',
	function () {
		db_stripe_without_environment_overrides(
			function () {
				doughboss_test_set_settings( db_stripe_settings( array( 'payments_enabled' => 0 ) ) );
				assert_false( DoughBoss_Stripe::ready(), 'the master payment toggle keeps Stripe dormant' );

				doughboss_test_set_settings( db_stripe_settings( array( 'stripe_test_sk' => '' ) ) );
				assert_false( DoughBoss_Stripe::ready(), 'test mode rejects a missing secret key' );

				doughboss_test_set_settings( db_stripe_settings( array( 'stripe_test_sk' => 'sk_live_wrongmode123456' ) ) );
				assert_false( DoughBoss_Stripe::ready(), 'test mode rejects a live secret key' );

				doughboss_test_set_settings( db_stripe_settings() );
				assert_true( DoughBoss_Stripe::ready(), 'test mode is ready with a valid test secret and no webhook secret' );
				assert_same( '', DoughBoss_Stripe::publishable_key(), 'hosted Checkout readiness does not require a publishable key' );
			}
		);
	}
);

db_test(
	'Stripe live mode fails closed without its active webhook secret',
	function () {
		db_stripe_without_environment_overrides(
			function () {
				doughboss_test_set_settings( db_stripe_settings( array( 'stripe_mode' => 'live', 'stripe_live_whsec' => '' ) ) );
				assert_false( DoughBoss_Stripe::ready(), 'live mode rejects a missing webhook signing secret' );

				doughboss_test_set_settings( db_stripe_settings( array( 'stripe_mode' => 'live' ) ) );
				assert_true( DoughBoss_Stripe::ready(), 'live mode is ready with a live secret and webhook signing secret' );

				doughboss_test_set_settings( db_stripe_settings( array( 'stripe_mode' => 'live', 'stripe_live_sk' => 'sk_test_wrongmode123456' ) ) );
				assert_false( DoughBoss_Stripe::ready(), 'live mode rejects a test secret key' );
			}
		);
	}
);

/* -------------------------------------------------------------------------- */
/* Canonical references                                                        */
/* -------------------------------------------------------------------------- */

db_test(
	'Stripe canonical references preserve the provider id and sanitise text',
	function () {
		assert_same( 'pi_SYNTHETIC123456789', DoughBoss_Stripe::canonical_id( 'pi_SYNTHETIC123456789' ), 'a PaymentIntent id passes through unchanged' );
		assert_same( 'pi_SYNTHETIC123456789', DoughBoss_Stripe::canonical_id( " \tpi_SYNTHETIC123456789\r\n" ), 'surrounding whitespace and controls are removed' );
		assert_same( 'pi_SYNTHETIC123456789', DoughBoss_Stripe::canonical_id( '<b>pi_SYNTHETIC123456789</b>' ), 'markup is removed from an otherwise canonical id' );
		assert_same( '', DoughBoss_Stripe::canonical_id( null ), 'a null reference becomes an empty string' );
	}
);

/* -------------------------------------------------------------------------- */
/* Hosted Checkout response validation                                         */
/* -------------------------------------------------------------------------- */

db_test(
	'a valid Stripe-hosted Checkout response is normalised',
	function () {
		$payload = db_stripe_checkout_session_payload( db_stripe_checkout_response(), array( 'id' => 42 ), 2750, 'AUD' );

		assert_false( is_wp_error( $payload ), 'a matching Stripe Checkout response is accepted' );
		assert_same( 'cs_test_SYNTHETIC123456789', $payload['checkout_session'], 'the Checkout Session id is retained' );
		assert_same( 'https://checkout.stripe.com/c/pay/synthetic-session', $payload['checkout_url'], 'the exact HTTPS Stripe URL is retained' );
		assert_same( 42, $payload['attempt_id'], 'the durable attempt id is retained' );
		assert_same( 2750, $payload['amount'], 'the server-expected amount is retained' );
		assert_same( 'aud', $payload['currency'], 'currency is normalised to lowercase' );
	}
);

db_test(
	'hosted Checkout responses reject an untrusted destination or identifier',
	function () {
		$attempt = array( 'id' => 42 );
		$cases   = array(
			'lookalike host' => db_stripe_checkout_response( array( 'url' => 'https://checkout.stripe.com.attacker.test/c/pay/session' ) ),
			'insecure URL'   => db_stripe_checkout_response( array( 'url' => 'http://checkout.stripe.com/c/pay/session' ) ),
			'foreign host'   => db_stripe_checkout_response( array( 'url' => 'https://example.test/c/pay/session' ) ),
			'invalid id'     => db_stripe_checkout_response( array( 'id' => 'pi_SYNTHETIC123456789' ) ),
		);

		foreach ( $cases as $label => $response ) {
			$error = db_stripe_checkout_session_payload( $response, $attempt, 2750, 'AUD' );
			assert_true( is_wp_error( $error ), $label . ' is rejected' );
			assert_same( 'doughboss_pay_create', $error->get_error_code(), $label . ' uses the safe creation error' );
			assert_same( 502, $error->get_error_data()['status'], $label . ' reports an upstream-response failure' );
		}
	}
);

db_test(
	'hosted Checkout responses reject amount and currency drift',
	function () {
		$attempt = array( 'id' => 42 );

		$amount_error = db_stripe_checkout_session_payload( db_stripe_checkout_response( array( 'amount_total' => 2749 ) ), $attempt, 2750, 'AUD' );
		assert_true( is_wp_error( $amount_error ), 'a one-cent amount mismatch is rejected' );
		assert_same( 'doughboss_pay_create', $amount_error->get_error_code(), 'amount drift uses the safe creation error' );

		$currency_error = db_stripe_checkout_session_payload( db_stripe_checkout_response( array( 'currency' => 'usd' ) ), $attempt, 2750, 'AUD' );
		assert_true( is_wp_error( $currency_error ), 'a currency mismatch is rejected' );
		assert_same( 'doughboss_pay_create', $currency_error->get_error_code(), 'currency drift uses the safe creation error' );
	}
);

/* -------------------------------------------------------------------------- */
/* Webhook signatures                                                          */
/* -------------------------------------------------------------------------- */

db_test(
	'Stripe webhook verification accepts a current matching v1 signature',
	function () {
		db_stripe_without_environment_overrides(
			function () {
				$key       = 'whsec_test_synthetic123456789';
				$payload   = '{"id":"evt_SYNTHETIC123456789","type":"checkout.session.completed"}';
				$timestamp = time();
				$signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, $key );

				doughboss_test_set_settings( db_stripe_settings( array( 'stripe_test_whsec' => $key ) ) );
				assert_true( DoughBoss_Stripe::verify_webhook_signature( $payload, 't=' . $timestamp . ',v1=' . $signature ), 'the exact signed payload verifies' );
				assert_true( DoughBoss_Stripe::verify_webhook_signature( $payload, 't=' . $timestamp . ',v1=wrong,v1=' . $signature ), 'one matching v1 signature is sufficient during key rotation' );
			}
		);
	}
);

db_test(
	'Stripe webhook verification rejects tampering, malformed headers and stale events',
	function () {
		db_stripe_without_environment_overrides(
			function () {
				$key       = 'whsec_test_synthetic123456789';
				$payload   = '{"id":"evt_SYNTHETIC123456789","type":"checkout.session.completed"}';
				$timestamp = time();
				$signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, $key );

				doughboss_test_set_settings( db_stripe_settings( array( 'stripe_test_whsec' => $key ) ) );
				assert_false( DoughBoss_Stripe::verify_webhook_signature( $payload . ' ', 't=' . $timestamp . ',v1=' . $signature ), 'a one-byte body change is rejected' );
				assert_false( DoughBoss_Stripe::verify_webhook_signature( $payload, 't=' . $timestamp . ',v1=' . str_repeat( '0', 64 ) ), 'a signature made with another key is rejected' );
				assert_false( DoughBoss_Stripe::verify_webhook_signature( $payload, '' ), 'an empty signature header is rejected' );
				assert_false( DoughBoss_Stripe::verify_webhook_signature( $payload, 'garbage' ), 'a malformed signature header is rejected' );
				assert_false( DoughBoss_Stripe::verify_webhook_signature( $payload, 't=' . ( $timestamp - 301 ) . ',v1=' . hash_hmac( 'sha256', ( $timestamp - 301 ) . '.' . $payload, $key ) ), 'a correctly signed event outside the tolerance is rejected' );

				doughboss_test_set_settings( db_stripe_settings( array( 'stripe_test_whsec' => '' ) ) );
				assert_false( DoughBoss_Stripe::verify_webhook_signature( $payload, 't=' . $timestamp . ',v1=' . $signature ), 'verification fails closed without a configured signing secret' );
			}
		);
	}
);
