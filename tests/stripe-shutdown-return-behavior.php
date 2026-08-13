<?php
/**
 * Offline behavior contract for a Stripe Checkout return during shutdown.
 *
 * Proves that disabling new card acceptance cannot make a paid hosted-session
 * return look like an unpaid/pay-on-pickup checkout. No network or credentials
 * are used: Stripe responses are deterministic in-memory doubles.
 *
 * Run: php tests/stripe-shutdown-return-behavior.php
 *
 * @package DoughBoss\Tests
 */

require __DIR__ . '/wp-stubs.php';

$GLOBALS['__db_shutdown_http_calls'] = array();
$GLOBALS['__db_shutdown_checkout']   = '';

/** Deterministic Stripe transport double. */
function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['__db_shutdown_http_calls'][] = array( $url, $args );

	if ( false !== strpos( $url, '/checkout/sessions/' ) ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'id'                  => 'cs_test_contract123456',
					'payment_status'      => 'paid',
					'payment_intent'      => 'pi_contract123456',
					'amount_total'        => 350,
					'currency'            => 'aud',
					'client_reference_id' => $GLOBALS['__db_shutdown_checkout'],
				)
			),
		);
	}

	if ( false !== strpos( $url, '/payment_intents/' ) ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'id'       => 'pi_contract123456',
					'status'   => 'succeeded',
					'amount'   => 350,
					'currency' => 'aud',
					'metadata' => array(
						'order_type'  => 'pickup',
						'location_id' => '0',
						'table_id'    => '0',
						'qr_code_id'  => '0',
						'checkout_key' => $GLOBALS['__db_shutdown_checkout'],
					),
				)
			),
		);
	}

	return new WP_Error( 'unexpected_request', 'Unexpected fake Stripe request.' );
}

require dirname( __DIR__ ) . '/includes/class-doughboss-settings.php';
require dirname( __DIR__ ) . '/includes/class-doughboss-cart.php';
require dirname( __DIR__ ) . '/includes/class-doughboss-stripe.php';
require dirname( __DIR__ ) . '/includes/class-doughboss-payment.php';
require dirname( __DIR__ ) . '/includes/class-doughboss-rest-controller.php';

/** Stable cart snapshot for immutable checkout binding. */
class DoughBoss_Shutdown_Return_Cart extends DoughBoss_Cart {
	public function to_array( $order_type = 'pickup' ) {
		return array(
			'items'  => array( array( 'item_id' => 1, 'quantity' => 1 ) ),
			'totals' => array( 'total' => 3.50 ),
		);
	}
}

$pass = 0;
$fail = 0;

function shutdown_return_ok( $condition, $label ) {
	global $pass, $fail;
	if ( $condition ) {
		$pass++;
		echo "  ok   $label\n";
	} else {
		$fail++;
		echo "  FAIL $label\n";
	}
}

function shutdown_return_settings( $gateway = 'stripe', $mode = 'test' ) {
	$GLOBALS['__db_options'][ DoughBoss_Settings::OPTION_KEY ] = array_merge(
		DoughBoss_Settings::defaults(),
		array(
			'payments_enabled' => 0,
			'payment_gateway'  => $gateway,
			'stripe_mode'      => $mode,
			'stripe_test_sk'   => 'sk_' . 'test_' . str_repeat( 'x', 24 ),
			'stripe_live_sk'   => 'sk_' . 'live_' . str_repeat( 'x', 24 ),
			'currency_code'    => 'AUD',
		)
	);
}

echo "=== Stripe shutdown return behavior ===\n";

$cart       = new DoughBoss_Shutdown_Return_Cart();
$controller = new DoughBoss_REST_Controller( $cart );
$verify     = new ReflectionMethod( $controller, 'verify_payment' );
$verify->setAccessible( true );
$request    = new WP_REST_Request(
	array(
		'payment_intent_id'  => 'cs_test_contract123456',
		'payment_attempt_key'=> 'shutdown-contract-001',
	)
);
$snapshot   = array(
	'cart'        => $cart->to_array( 'pickup' ),
	'location_id' => 0,
	'order_type'  => 'pickup',
	'table'       => null,
);
$GLOBALS['__db_shutdown_checkout'] = hash_hmac(
	'sha256',
	'order|shutdown-contract-001|' . wp_json_encode( $snapshot ),
	wp_salt( 'auth' )
);

shutdown_return_settings();
shutdown_return_ok( ! DoughBoss_Payment::ready(), 'new card acceptance is OFF before the return is verified' );
$GLOBALS['__db_shutdown_http_calls'] = array();
$verified = $verify->invoke( $controller, $request, 3.50, 'pickup', 0, null );
shutdown_return_ok( 'pi_contract123456' === $verified, 'a bound paid Test Checkout Session is verified while new card acceptance is OFF' );
shutdown_return_ok( 2 === count( $GLOBALS['__db_shutdown_http_calls'] ), 'verification retrieves both the hosted session and canonical PaymentIntent' );

shutdown_return_settings( 'stripe', 'live' );
$GLOBALS['__db_shutdown_http_calls'] = array();
$wrong_mode = $verify->invoke( $controller, $request, 3.50, 'pickup', 0, null );
shutdown_return_ok( is_wp_error( $wrong_mode ) && 'doughboss_pay_mode_changed' === $wrong_mode->get_error_code(), 'a Test session fails closed after the shop switches to Live mode' );
shutdown_return_ok( 0 === count( $GLOBALS['__db_shutdown_http_calls'] ), 'mode mismatch is rejected before any provider request' );

shutdown_return_settings( 'tyro', 'test' );
$GLOBALS['__db_shutdown_http_calls'] = array();
$wrong_gateway = $verify->invoke( $controller, $request, 3.50, 'pickup', 0, null );
shutdown_return_ok( is_wp_error( $wrong_gateway ) && 'doughboss_pay_mode_changed' === $wrong_gateway->get_error_code(), 'a Stripe session fails closed after the active gateway changes' );
shutdown_return_ok( 0 === count( $GLOBALS['__db_shutdown_http_calls'] ), 'gateway mismatch is rejected before any provider request' );

shutdown_return_settings();
$GLOBALS['__db_shutdown_http_calls'] = array();
$non_hosted = new WP_REST_Request(
	array(
		'payment_intent_id'  => 'pi_contract123456',
		'payment_attempt_key'=> 'shutdown-contract-001',
	)
);
$payment_off = $verify->invoke( $controller, $non_hosted, 3.50, 'pickup', 0, null );
shutdown_return_ok( is_wp_error( $payment_off ) && 'doughboss_pay_off' === $payment_off->get_error_code(), 'a non-hosted reference cannot bypass the payment-off safety boundary' );
shutdown_return_ok( 0 === count( $GLOBALS['__db_shutdown_http_calls'] ), 'non-hosted payment-off rejection performs no provider request' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
