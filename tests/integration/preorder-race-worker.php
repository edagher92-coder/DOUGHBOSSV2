<?php
/**
 * One synchronised side of the approved-preorder payment race fixture.
 *
 * This file is invoked only by scripts/test-wordpress-integration.ps1 against
 * the disposable local WordPress/MySQL site. It intentionally accepts neither
 * live configuration nor a real provider/mail transport.
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

if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || true !== WP_HTTP_BLOCK_EXTERNAL ) {
	fwrite( STDERR, "Refusing to run without the disposable site's outbound HTTP block.\n" );
	exit( 2 );
}

$race_mode = getenv( 'DOUGHBOSS_PREORDER_RACE_MODE' );
if ( ! is_string( $race_mode ) || ! in_array( $race_mode, array( 'prepare', 'start', 'complete', 'verify-start', 'verify-complete' ), true ) ) {
	fwrite( STDERR, "DOUGHBOSS_PREORDER_RACE_MODE is invalid.\n" );
	exit( 2 );
}

$race_scratch = getenv( 'DOUGHBOSS_PREORDER_RACE_SCRATCH' );
$race_temp    = realpath( sys_get_temp_dir() );
$race_real    = is_string( $race_scratch ) && '' !== $race_scratch ? realpath( $race_scratch ) : false;
if (
	false === $race_temp
	|| false === $race_real
	|| $race_temp !== dirname( $race_real )
	|| 0 !== strpos( basename( $race_real ), 'doughboss-preorder-race-' )
) {
	fwrite( STDERR, "DOUGHBOSS_PREORDER_RACE_SCRATCH is not a verified temporary race directory.\n" );
	exit( 2 );
}
$GLOBALS['db_preorder_race_scratch_real'] = $race_real;
$GLOBALS['db_preorder_race_mode']         = $race_mode;

/** Write a non-sensitive, line-oriented local race record under the verified scratch path. */
function db_preorder_race_log( $event, $detail = '' ) {
	$race_real = isset( $GLOBALS['db_preorder_race_scratch_real'] ) ? (string) $GLOBALS['db_preorder_race_scratch_real'] : '';
	$race_mode = isset( $GLOBALS['db_preorder_race_mode'] ) ? (string) $GLOBALS['db_preorder_race_mode'] : '';
	$event  = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $event ) );
	$detail = preg_replace( '/[^a-z0-9_:-]/', '', strtolower( (string) $detail ) );
	$line   = $event . '|' . $race_mode . '|' . (int) getmypid() . '|' . sprintf( '%.6f', microtime( true ) ) . '|' . $detail . "\n";
	if ( false === file_put_contents( $race_real . DIRECTORY_SEPARATOR . 'events.log', $line, FILE_APPEND | LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		fwrite( STDERR, "Unable to write the local race log.\n" );
		exit( 2 );
	}
}

/** Stop without emitting option values, tokens, provider payloads, or PII. */
function db_preorder_race_fail( $message ) {
	db_preorder_race_log( 'worker_fail', 'guard' );
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

$race_fixture = get_option( 'doughboss_preorder_race_fixture', array() );
if ( ! is_array( $race_fixture ) ) {
	db_preorder_race_fail( 'The approved-preorder race fixture is missing.' );
}

$race_request_id   = isset( $race_fixture['request_id'] ) ? absint( $race_fixture['request_id'] ) : 0;
$race_token        = isset( $race_fixture['token'] ) && is_scalar( $race_fixture['token'] ) ? trim( (string) $race_fixture['token'] ) : '';
$race_checkout_key = isset( $race_fixture['checkout_key'] ) && is_scalar( $race_fixture['checkout_key'] ) ? strtolower( (string) $race_fixture['checkout_key'] ) : '';
$race_session_id   = isset( $race_fixture['session_id'] ) && is_scalar( $race_fixture['session_id'] ) ? (string) $race_fixture['session_id'] : '';
$race_amount       = isset( $race_fixture['amount'] ) ? (int) $race_fixture['amount'] : 0;
$race_currency     = isset( $race_fixture['currency'] ) && is_scalar( $race_fixture['currency'] ) ? strtoupper( (string) $race_fixture['currency'] ) : '';

if (
	! $race_request_id
	|| '' === $race_token
	|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $race_checkout_key )
	|| 1 !== preg_match( '/^cs_test_[A-Za-z0-9_]{8,191}$/', $race_session_id )
	|| $race_amount < 1
	|| 1 !== preg_match( '/^[A-Z]{3}$/', $race_currency )
) {
	db_preorder_race_fail( 'The approved-preorder race fixture has an invalid shape.' );
}

if ( 'prepare' === $race_mode ) {
	// The preceding Square race intentionally selects Square. Reassert the exact
	// synthetic Stripe lane once, before concurrent workers are launched.
	$race_settings                              = DoughBoss_Settings::all();
	$race_settings['payments_enabled']          = 1;
	$race_settings['payment_gateway']           = 'stripe';
	$race_settings['stripe_mode']               = 'test';
	$race_settings['stripe_test_sk']            = 'sk_test_localSyntheticIntegrationOnly000000';
	$race_settings['stripe_test_whsec']         = 'whsec_localSyntheticIntegrationOnly000000';
	$race_settings['currency_code']             = $race_currency;
	$race_settings['ordering_open']             = 0;
	$race_settings['pospal_order_push_enabled'] = 0;
	update_option( 'doughboss_settings', $race_settings, false );
}

// Production payment returns are HTTPS-only. The disposable fixture's stored
// home URL is HTTP because it has no web server, so upgrade generated return
// URLs in-memory after the strict local-environment guard above has run.
add_filter(
	'home_url',
	static function ( $url ) {
		return set_url_scheme( $url, 'https' );
	},
	10,
	1
);

$race_approval = DoughBoss_Preorder_Approval::get( $race_request_id );
if (
	is_wp_error( $race_approval )
	|| ! is_array( $race_approval )
	|| ! isset( $race_approval['payment_checkout_key'] )
	|| ! hash_equals( $race_checkout_key, (string) $race_approval['payment_checkout_key'] )
) {
	db_preorder_race_fail( 'The approved-preorder race fixture does not match durable approval state.' );
}

if ( 'prepare' === $race_mode ) {
	if ( 'stripe' !== DoughBoss_Settings::payment_gateway() || ! DoughBoss_Payment::ready() ) {
		db_preorder_race_fail( 'The synthetic Stripe race lane could not be prepared.' );
	}
	db_preorder_race_log( 'prepare_ok', 'synthetic_stripe' );
	echo "WORKER_OK prepare\n";
	exit( 0 );
}

if ( 'verify-start' === $race_mode ) {
	$race_attempt = DoughBoss_Payment_Attempts::find_by_checkout_key( $race_checkout_key );
	$race_final   = DoughBoss_Order::find_id_by_source_request( $race_request_id );
	if (
		! $race_attempt
		|| 'stripe' !== (string) $race_attempt['provider']
		|| ! hash_equals( $race_session_id, (string) $race_attempt['provider_reference'] )
		|| $race_amount !== (int) $race_attempt['amount_minor']
		|| ! hash_equals( $race_currency, strtoupper( (string) $race_attempt['currency'] ) )
		|| 'payment_active' !== (string) $race_approval['state']
		|| $race_final
	) {
		db_preorder_race_fail( 'Start-race durable state is not exactly one active approved payment.' );
	}
	db_preorder_race_log( 'verify_start_ok', 'durable' );
	echo "WORKER_OK verify-start\n";
	exit( 0 );
}

if ( 'verify-complete' === $race_mode ) {
	global $wpdb;
	$race_order_id = isset( $race_approval['fulfillment_order_id'] ) ? absint( $race_approval['fulfillment_order_id'] ) : 0;
	$race_order    = $race_order_id ? DoughBoss_Order::get( $race_order_id ) : null;
	$race_count    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'doughboss_orders WHERE source_request_id = %d', $race_request_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if (
		'released' !== (string) $race_approval['state']
		|| ! $race_order_id
		|| ! $race_order
		|| $race_request_id !== (int) $race_order->source_request_id
		|| 'paid' !== (string) $race_order->payment_status
		|| 'stripe' !== (string) $race_order->payment_method
		|| 1 !== $race_count
	) {
		db_preorder_race_fail( 'Completion-race durable state is not exactly one released paid fulfillment order.' );
	}
	db_preorder_race_log( 'verify_complete_ok', 'durable' );
	echo "WORKER_OK verify-complete\n";
	exit( 0 );
}

/** Return a synthetic successful Stripe response without any network transport. */
function db_preorder_race_stripe_response( array $body ) {
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( $body ),
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'cookies'  => array(),
		'filename' => null,
	);
}

add_filter(
	'pre_wp_mail',
	static function () {
		db_preorder_race_log( 'mail_intercepted', 'preempt' );
		return true;
	},
	10,
	1
);

add_action(
	'doughboss_order_created',
	static function ( $order_id, $data ) use ( $race_request_id ) {
		unset( $order_id );
		if ( is_array( $data ) && $race_request_id === ( isset( $data['source_request_id'] ) ? absint( $data['source_request_id'] ) : 0 ) ) {
			db_preorder_race_log( 'order_created', 'fulfillment' );
		}
	},
	999,
	2
);

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) use ( $race_mode, $race_request_id, $race_checkout_key, $race_session_id, $race_amount, $race_currency ) {
		$prefix = 'https://api.stripe.com/v1';
		if ( $prefix !== (string) $url && 0 !== strpos( (string) $url, $prefix . '/' ) ) {
			return $preempt;
		}

		$method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
		$path   = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$intent_id = 'pi_' . substr( hash( 'sha256', 'preorder-race|' . $race_checkout_key ), 0, 24 );
		if ( 'POST' === $method && '/v1/checkout/sessions' === $path ) {
			if ( 'start' !== $race_mode ) {
				return new WP_Error( 'integration_preorder_unexpected_post', 'Synthetic provider post is not allowed in this race mode.' );
			}
			$body = isset( $args['body'] ) && is_array( $args['body'] ) ? $args['body'] : array();
			if (
				! isset( $body['client_reference_id'], $body['line_items[0][price_data][unit_amount]'], $body['line_items[0][price_data][currency]'] )
				|| ! hash_equals( $race_checkout_key, strtolower( (string) $body['client_reference_id'] ) )
				|| $race_amount !== (int) $body['line_items[0][price_data][unit_amount]']
				|| ! hash_equals( strtolower( $race_currency ), strtolower( (string) $body['line_items[0][price_data][currency]'] ) )
			) {
				return new WP_Error( 'integration_preorder_provider_binding', 'Synthetic provider received a mismatched checkout request.' );
			}
			db_preorder_race_log( 'stripe_checkout_post', 'accepted' );
			usleep( 750000 );
			return db_preorder_race_stripe_response(
				array(
					'id'                  => $race_session_id,
					'url'                 => 'https://checkout.stripe.com/c/pay/' . $race_session_id,
					'payment_status'      => 'unpaid',
					'payment_intent'      => $intent_id,
					'amount_total'        => $race_amount,
					'currency'            => strtolower( $race_currency ),
					'client_reference_id' => $race_checkout_key,
				)
			);
		}

		if ( 'GET' === $method && '/v1/checkout/sessions/' . rawurlencode( $race_session_id ) === $path ) {
			db_preorder_race_log( 'stripe_checkout_get', 'accepted' );
			return db_preorder_race_stripe_response(
				array(
					'id'                  => $race_session_id,
					'url'                 => 'https://checkout.stripe.com/c/pay/' . $race_session_id,
					'payment_status'      => 'complete' === $race_mode ? 'paid' : 'unpaid',
					'payment_intent'      => $intent_id,
					'amount_total'        => $race_amount,
					'currency'            => strtolower( $race_currency ),
					'client_reference_id' => $race_checkout_key,
				)
			);
		}

		if ( 'GET' === $method && '/v1/payment_intents/' . rawurlencode( $intent_id ) === $path && 'complete' === $race_mode ) {
			$approval = DoughBoss_Preorder_Approval::get( $race_request_id );
			if ( is_wp_error( $approval ) || ! is_array( $approval ) || empty( $approval['request_fingerprint'] ) ) {
				return new WP_Error( 'integration_preorder_approval_missing', 'Synthetic provider cannot read durable approval binding.' );
			}
			db_preorder_race_log( 'stripe_intent_get', 'accepted' );
			return db_preorder_race_stripe_response(
				array(
					'id'              => $intent_id,
					'status'          => 'succeeded',
					'amount'          => $race_amount,
					'amount_received' => $race_amount,
					'currency'        => strtolower( $race_currency ),
					'metadata'        => array(
						'checkout_key'        => $race_checkout_key,
						'purpose'             => 'order',
						'context'             => 'approved_preorder',
						'source_request_id'   => (string) $race_request_id,
						'approved_revision'   => isset( $approval['approved_revision'] ) ? (string) (int) $approval['approved_revision'] : '0',
						'request_fingerprint' => (string) $approval['request_fingerprint'],
					),
				)
			);
		}

		return new WP_Error( 'integration_preorder_unhandled_provider_request', 'Synthetic provider refused an unrecognised request.' );
	},
	10,
	3
);

$ready_path = $race_real . DIRECTORY_SEPARATOR . 'ready-' . $race_mode . '-' . (int) getmypid() . '.json';
$ready_body = wp_json_encode( array( 'mode' => $race_mode, 'pid' => (int) getmypid(), 'at' => microtime( true ) ) );
if ( ! is_string( $ready_body ) || false === file_put_contents( $ready_path, $ready_body, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	db_preorder_race_fail( 'Unable to record worker readiness.' );
}
db_preorder_race_log( 'worker_ready', 'barrier' );

$go_path  = $race_real . DIRECTORY_SEPARATOR . 'go-' . $race_mode;
$deadline = microtime( true ) + 20;
while ( ! file_exists( $go_path ) && microtime( true ) < $deadline ) {
	usleep( 50000 );
}
if ( ! file_exists( $go_path ) ) {
	db_preorder_race_fail( 'Timed out waiting for the explicit race barrier.' );
}
db_preorder_race_log( 'worker_go', 'barrier' );

$race_controller = new DoughBoss_REST_Controller( new DoughBoss_Cart() );
$race_request    = new WP_REST_Request( 'POST', 'start' === $race_mode ? '/doughboss/v1/preorder-payment/start' : '/doughboss/v1/preorder-payment/complete' );
global $wpdb;
$wpdb->suppress_errors( true );
$race_request->set_param( 'request_id', $race_request_id );
$race_request->set_param( 'token', $race_token );
if ( 'complete' === $race_mode ) {
	$race_request->set_param( 'session_id', $race_session_id );
	$race_result = $race_controller->preorder_payment_complete( $race_request );
} else {
	$race_result = $race_controller->preorder_payment_start( $race_request );
}

if ( 'start' === $race_mode && is_wp_error( $race_result ) ) {
	$race_code = $race_result->get_error_code();
	if ( ! in_array( $race_code, array( 'doughboss_pay_provisioning', 'doughboss_preorder_payment_link_pending' ), true ) ) {
		db_preorder_race_fail( 'Start-race worker received unexpected fail-closed code: ' . sanitize_key( $race_code ) . '.' );
	}
	db_preorder_race_log( 'worker_ok', 'start_pending' );
	echo "WORKER_OK start-pending\n";
	exit( 0 );
}

if ( is_wp_error( $race_result ) || ! ( $race_result instanceof WP_REST_Response ) ) {
	db_preorder_race_fail( 'Race worker did not receive a successful REST response.' );
}

if ( 'start' === $race_mode ) {
	$race_data = $race_result->get_data();
	if ( ! is_array( $race_data ) || ! isset( $race_data['checkout_session'] ) || ! hash_equals( $race_session_id, (string) $race_data['checkout_session'] ) ) {
		db_preorder_race_fail( 'Start-race worker received an unexpected checkout session.' );
	}
}

db_preorder_race_log( 'worker_ok', $race_mode );
echo 'WORKER_OK ' . $race_mode . "\n";
exit( 0 );
