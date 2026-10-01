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
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || true !== WP_HTTP_BLOCK_EXTERNAL ) {
	fwrite( STDERR, "Refusing race without outbound HTTP block.\n" );
	exit( 2 );
}
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
$settings['square_test_location_map'] = array( 'revesby' => 'LINTEGRATIONLOCATION' );
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
				'location_id'  => isset( $body['location_id'] ) ? $body['location_id'] : '',
				'reference_id' => $reference,
				'amount_money' => $body['amount_money'],
			);
		} else {
			$id      = rawurldecode( basename( wp_parse_url( (string) $url, PHP_URL_PATH ) ) );
			$attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $id );
			if ( ! $attempt ) {
				return new WP_Error( 'integration_missing_attempt', 'Synthetic attempt missing.' );
			}
			$stored_meta = DoughBoss_Payment_Attempts::metadata( $attempt );
			$payment = array(
				'id'           => $id,
				'status'       => 'COMPLETED',
				'location_id'  => isset( $stored_meta['square_location_id'] ) ? $stored_meta['square_location_id'] : '',
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

$race_mode = getenv( 'DOUGHBOSS_SQUARE_RACE_MODE' );
if ( 'approved_preorder' === $race_mode ) {
	$request_id = DoughBoss_Order::find_id_by_checkout_key( hash( 'sha256', 'preorder-request|square-two-process-race' ) );
	$approval = DoughBoss_Preorder_Approval::get( $request_id );
	if ( ! $request_id || ! is_array( $approval ) ) {
		fwrite( STDERR, "Approved Square race fixture missing.\n" );
		exit( 2 );
	}
	$snapshot = DoughBoss_Checkout_Snapshots::find( $approval['payment_checkout_key'] );
	if ( ! is_array( $snapshot ) || empty( $snapshot['payload']['binding_hash'] ) ) {
		fwrite( STDERR, "Approved Square race snapshot missing.\n" );
		exit( 2 );
	}
	$token_method = new ReflectionMethod( 'DoughBoss_Preorder_Approval', 'capability_token' );
	$token_method->setAccessible( true );
	$result = DoughBoss_Square::create_payment_intent( 1400, 'AUD', array(
		'checkout_key' => $approval['payment_checkout_key'],
		'protocol_version' => 'square-v2',
		'attempt_identity' => hash( 'sha256', 'approved-square-two-process-race' ),
		'binding_hash' => $snapshot['payload']['binding_hash'],
		'cart_guard' => hash( 'sha256', 'approved-square-cart-two-process-race' ),
		'source_id' => 'cnon:integration-approved-race-token',
		'verification_token' => '',
		'purpose' => 'order',
		'context' => 'approved_preorder',
		'order_type' => 'pickup',
		'location_id' => 1,
		'table_id' => 0,
		'qr_code_id' => 0,
		'source_request_id' => $request_id,
		'approved_revision' => $approval['approved_revision'],
		'request_fingerprint' => $approval['request_fingerprint'],
		'preorder_payment_token' => $token_method->invoke( null, $request_id, $approval['approved_revision'], $approval['request_fingerprint'], $approval['payment_token_expires_at_utc'] ),
	) );
} else {
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

}
if ( is_wp_error( $result ) ) {
	$data = $result->get_error_data();
	if ( ! is_array( $data ) || empty( $data['payment_pending'] ) ) {
		fwrite( STDERR, 'Unexpected race error: ' . $result->get_error_code() . "\n" );
		if ( 'approved_preorder' === $race_mode ) {
			$race_attempt = DoughBoss_Payment_Attempts::find_by_checkout_key( $approval['payment_checkout_key'] );
			fwrite( STDERR, 'Approved race attempt state: ' . ( is_array( $race_attempt ) ? $race_attempt['status'] : 'missing' ) . "\n" );
		}
		exit( 1 );
	}
}

if ( 'approved_preorder' === $race_mode ) {
	// Converge on the same verified payment before racing kitchen release.
	$deadline = microtime( true ) + 8.0;
	do {
		$race_attempt = DoughBoss_Payment_Attempts::find_by_checkout_key( $approval['payment_checkout_key'] );
		if ( is_array( $race_attempt ) && ! empty( $race_attempt['provider_reference'] ) ) {
			break;
		}
		usleep( 50000 );
	} while ( microtime( true ) < $deadline );
	if ( ! is_array( $race_attempt ) || empty( $race_attempt['provider_reference'] ) ) {
		fwrite( STDERR, "Approved Square race never received durable provider reference.\n" );
		exit( 1 );
	}
	$verified = DoughBoss_Square::retrieve_payment_intent( $race_attempt['provider_reference'] );
	if ( is_wp_error( $verified ) || 'succeeded' !== $verified['status'] ) {
		fwrite( STDERR, "Approved Square race payment was not verified.\n" );
		exit( 1 );
	}
	add_filter( 'pre_wp_mail', function () { return true; }, 999 );
	add_action( 'doughboss_order_created', function () use ( $race_log ) {
		file_put_contents( $race_log, "FULFILL\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}, 999 );
	$fulfillment = array_merge( $snapshot['payload']['order'], array(
		'source_request_id' => $request_id,
		'payment_status' => 'paid',
		'payment_method' => 'square',
		'payment_intent_id' => $race_attempt['provider_reference'],
	) );
	global $wpdb;
	$errors_before = $wpdb->suppress_errors( true );
	$created = DoughBoss_Order::create( $fulfillment, $snapshot['payload']['lines'] );
	$wpdb->suppress_errors( $errors_before );
	if ( is_wp_error( $created ) || empty( $created['order_id'] ) ) {
		fwrite( STDERR, "Approved Square race fulfillment failed.\n" );
		exit( 1 );
	}
}
echo "WORKER_OK\n";
exit( 0 );
