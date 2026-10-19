<?php
/**
 * One side of the two-process Square dispatch race.
 *
 * Invoked only by scripts/test-wordpress-integration.ps1 after the main
 * disposable-environment checks reset payment state.
 *
 * @package DoughBoss
 */

if (
	'local' !== wp_get_environment_type()
	|| 'doughboss_wp_test' !== DB_NAME
	|| 'http://doughboss.local.test' !== untrailingslashit( home_url() )
) {
	fwrite( STDERR, "Refusing to run outside the disposable DoughBoss integration site.\n" );
	exit( 2 );
}

$race_log = getenv( 'DOUGHBOSS_RACE_LOG' );
if ( ! is_string( $race_log ) || '' === $race_log ) {
	fwrite( STDERR, "DOUGHBOSS_RACE_LOG is required.\n" );
	exit( 2 );
}

$settings = DoughBoss_Settings::all();
$settings['payments_enabled']         = 1;
$settings['payment_gateway']          = 'square';
$settings['square_mode']              = 'test';
$settings['square_test_app_id']       = 'sandbox-sq0idb-INTEGRATIONAPP000000';
$settings['square_test_access_token'] = 'EAAAlocalSyntheticIntegrationToken000000';
$settings['square_test_location_id']  = 'LINTEGRATIONLOCATION';
update_option( 'doughboss_settings', $settings, false );

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) use ( $race_log ) {
		if ( false === strpos( (string) $url, 'squareup' ) ) {
			return $preempt;
		}
		$method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
		if ( 'POST' === $method ) {
			file_put_contents( $race_log, "POST\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			usleep( 750000 );
			$body      = json_decode( (string) $args['body'], true );
			$reference = isset( $body['reference_id'] ) ? (string) $body['reference_id'] : '';
			$payment   = array(
				'id'           => 'sqpay' . substr( hash( 'sha256', $reference ), 0, 28 ),
				'status'       => 'COMPLETED',
				'location_id'  => DoughBoss_Settings::square_location_id(),
				'reference_id' => $reference,
				'amount_money' => $body['amount_money'],
			);
		} else {
			$id      = rawurldecode( basename( wp_parse_url( (string) $url, PHP_URL_PATH ) ) );
			$attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $id );
			if ( ! $attempt ) {
				return new WP_Error( 'integration_missing_attempt', 'Synthetic attempt missing.' );
			}
			$payment = array(
				'id'           => $id,
				'status'       => 'COMPLETED',
				'location_id'  => DoughBoss_Settings::square_location_id(),
				'reference_id' => DoughBoss_Square::attempt_reference( (int) $attempt['id'] ),
				'amount_money' => array( 'amount' => (int) $attempt['amount_minor'], 'currency' => (string) $attempt['currency'] ),
			);
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'payment' => $payment ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

$result = DoughBoss_Square::create_payment_intent(
	3200,
	'AUD',
	array(
		'checkout_key'      => hash( 'sha256', 'race-checkout-v1' ),
		'protocol_version'   => 'square-v2',
		'attempt_identity'   => hash( 'sha256', 'race-attempt-v1' ),
		'binding_hash'       => hash( 'sha256', 'race-binding-v1' ),
		'cart_guard'         => hash( 'sha256', 'race-cart-v1' ),
		'source_id'          => 'cnon:integration-race-token',
		'verification_token' => '',
		'purpose'            => 'order',
		'context'            => 'web',
		'order_type'         => 'pickup',
		'location_id'        => 1,
		'table_id'           => 0,
		'qr_code_id'         => 0,
	)
);

if ( is_wp_error( $result ) ) {
	$data = $result->get_error_data();
	if ( ! is_array( $data ) || empty( $data['payment_pending'] ) ) {
		fwrite( STDERR, 'Unexpected race error: ' . $result->get_error_code() . "\n" );
		exit( 1 );
	}
}

echo "WORKER_OK\n";
exit( 0 );
