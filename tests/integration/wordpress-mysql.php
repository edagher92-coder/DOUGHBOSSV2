<?php
/**
 * Real WordPress + MySQL integration checks.
 *
 * Run through WP-CLI after copying the plugin into the disposable site:
 * php wp-cli.phar --exec="define('WP_HTTP_BLOCK_EXTERNAL',true);" eval-file tests/integration/wordpress-mysql.php --path=...
 *
 * The hard environment guard prevents this destructive fixture reset from ever
 * running against a real site. Provider HTTP is intercepted by pre_http_request;
 * no provider request, email, order notification or live setting is used.
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

// Never allow a developer-machine Stripe override to win over the synthetic
// options below. Values are retained only in this process and never printed.
$db_stripe_environment_names = array(
	'DOUGHBOSS_STRIPE_TEST_SK',
	'DOUGHBOSS_STRIPE_LIVE_SK',
	'DOUGHBOSS_STRIPE_TEST_WHSEC',
	'DOUGHBOSS_STRIPE_LIVE_WHSEC',
);
foreach ( $db_stripe_environment_names as $db_stripe_environment_name ) {
	if ( defined( $db_stripe_environment_name ) ) {
		fwrite( STDERR, "Refusing to replace a configured Stripe constant in the disposable test process.\n" );
		exit( 2 );
	}
}
$db_stripe_environment_prior = array();
foreach ( $db_stripe_environment_names as $db_stripe_environment_name ) {
	$db_stripe_environment_prior[ $db_stripe_environment_name ] = getenv( $db_stripe_environment_name );
	putenv( $db_stripe_environment_name );
}
register_shutdown_function(
	static function () use ( $db_stripe_environment_prior ) {
		foreach ( $db_stripe_environment_prior as $name => $value ) {
			if ( false === $value ) {
				putenv( $name );
			} else {
				putenv( $name . '=' . $value );
			}
		}
	}
);

$GLOBALS['db_integration_passed'] = 0;
$GLOBALS['db_integration_failed'] = array();
$GLOBALS['db_square_http_mode']   = 'success';
$GLOBALS['db_square_post_count']  = 0;
$GLOBALS['db_square_get_count']   = 0;
$GLOBALS['db_square_post_bodies'] = array();
$GLOBALS['db_fail_guard_once']    = false;
$GLOBALS['db_catering_mail']        = array();
$GLOBALS['db_catering_mail_result'] = true;
$GLOBALS['db_stripe_post_count']    = 0;
$GLOBALS['db_stripe_session_gets']  = 0;
$GLOBALS['db_stripe_intent_gets']   = 0;
$GLOBALS['db_stripe_network_once']  = false;
$GLOBALS['db_stripe_session_network_once'] = false;
$GLOBALS['db_stripe_post_bodies']   = array();
$GLOBALS['db_stripe_idempotency']   = array();
$GLOBALS['db_stripe_sessions']      = array();
$GLOBALS['db_stripe_intents']       = array();
$GLOBALS['db_fail_snapshot_complete_once'] = false;
$GLOBALS['db_fail_attempt_succeed_once']   = false;
$GLOBALS['db_order_created_events']        = array();
$GLOBALS['db_preorder_mail']               = array();
$GLOBALS['db_preorder_mail_results']       = array();
$GLOBALS['db_fail_preorder_delivery_once'] = false;

/** @param bool $condition Assertion result. @param string $message Label. */
function db_integration_assert( $condition, $message ) {
	if ( $condition ) {
		++$GLOBALS['db_integration_passed'];
		return;
	}
	$GLOBALS['db_integration_failed'][] = $message;
}

/** @param mixed $expected Expected. @param mixed $actual Actual. @param string $message Label. */
function db_integration_same( $expected, $actual, $message ) {
	db_integration_assert( $expected === $actual, $message . ' (expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) . ')' );
}

/** Read a WP_Error's HTTP status without allowing a failed fixture to cascade. */
function db_integration_error_status( $error ) {
	$data = is_wp_error( $error ) ? $error->get_error_data() : null;
	return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
}

/** Build a real WordPress REST request for controller-level integration checks. */
function db_integration_rest_request( $route, array $params ) {
	$request = new WP_REST_Request( 'POST', $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return $request;
}

/** Capture only the order-created events this integration fixture observes. */
function db_integration_capture_order_created( $order_id, $data ) {
	$GLOBALS['db_order_created_events'][] = array(
		'order_id' => (int) $order_id,
		'source'   => isset( $data['order_source'] ) ? (string) $data['order_source'] : 'web',
	);
}

/** Build one durable unpaid pre-order request through the real order writer. */
function db_integration_create_preorder_request( $seed, $total = 14.00 ) {
	$seed  = sanitize_key( (string) $seed );
	$total = (float) $total;
	return DoughBoss_Order::create(
		array(
			'checkout_key'   => hash( 'sha256', 'preorder-request|' . $seed ),
			'order_source'   => 'preorder_request',
			'order_type'     => 'pickup',
			'location_id'    => 1,
			'customer_name'  => 'Pre-order ' . $seed,
			'customer_email' => 'preorder.' . $seed . '@example.invalid',
			'customer_phone' => '0400000011',
			'address'        => '',
			'notes'          => 'Integration approval fixture ' . $seed,
			'subtotal'       => $total,
			'tax'            => 0.0,
			'delivery_fee'   => 0.0,
			'total'          => $total,
			'discount'       => 0.0,
			'voucher_code'   => '',
			'payment_status' => 'unpaid',
			'payment_method' => '',
		),
		array(
			array(
				'item_id'    => 9700 + (int) substr( hash( 'sha256', $seed ), 0, 3 ),
				'name'       => 'Synthetic Pre-order ' . $seed,
				'quantity'   => 1,
				'unit_price' => $total,
				'line_total' => $total,
			),
		)
	);
}

/** Keep the local Stripe checkout fixture on its production-required HTTPS origin. */
function db_integration_preorder_payment_page_url( $url ) {
	unset( $url );
	return 'https://doughboss.local.test/menu/';
}

/** Set a deterministic synthetic approval expiry and its matching opaque token. */
function db_integration_set_preorder_expiry( $request_id, $expires_at ) {
	global $wpdb;
	$approval = DoughBoss_Preorder_Approval::get( $request_id );
	if ( is_wp_error( $approval ) ) {
		return '';
	}
	$method = new ReflectionMethod( 'DoughBoss_Preorder_Approval', 'capability_token' );
	$method->setAccessible( true );
	$token = $method->invoke( null, (int) $request_id, (int) $approval['approved_revision'], (string) $approval['request_fingerprint'], (string) $expires_at );
	$updated = $wpdb->update(
		$wpdb->prefix . 'doughboss_preorder_approvals',
		array( 'payment_token_expires_at_utc' => (string) $expires_at, 'payment_token_hash' => hash( 'sha256', $token ) ),
		array( 'request_order_id' => (int) $request_id ),
		array( '%s', '%s' ),
		array( '%d' )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return false === $updated ? '' : $token;
}

/** Prevent the disposable checkout fixture from invoking a local mail transport. */
function db_integration_block_mail() {
	return true;
}

/** Capture approval mail and return a recipient-specific synthetic handoff result. */
function db_integration_capture_preorder_mail( $preempt, $attributes ) {
	unset( $preempt );
	$GLOBALS['db_preorder_mail'][] = $attributes;
	$recipient = isset( $attributes['to'] ) ? strtolower( (string) $attributes['to'] ) : '';
	return array_key_exists( $recipient, $GLOBALS['db_preorder_mail_results'] )
		? (bool) $GLOBALS['db_preorder_mail_results'][ $recipient ]
		: true;
}

/** Read one captured approval mail by normalized recipient. */
function db_integration_preorder_mail_to( $recipient ) {
	$recipient = strtolower( (string) $recipient );
	foreach ( $GLOBALS['db_preorder_mail'] as $mail ) {
		if ( isset( $mail['to'] ) && $recipient === strtolower( (string) $mail['to'] ) ) {
			return $mail;
		}
	}
	return array();
}

/** Capture catering mail without invoking any transport. */
function db_integration_capture_catering_mail( $preempt, $attributes ) {
	unset( $preempt );
	$GLOBALS['db_catering_mail'][] = $attributes;
	return $GLOBALS['db_catering_mail_result'];
}

/** Build a synthetic catering package with the server-owned pricing fields. */
function db_integration_catering_package( $title, $status = 'publish' ) {
	$id = wp_insert_post(
		array(
			'post_title'  => $title,
			'post_type'   => DoughBoss_Catering_Package::POST_TYPE,
			'post_status' => $status,
		)
	);
	if ( ! is_wp_error( $id ) && $id > 0 ) {
		update_post_meta( $id, DoughBoss_Catering_Package::META_BASE_PRICE, '240.00' );
		update_post_meta( $id, DoughBoss_Catering_Package::META_PER_HEAD, '12.50' );
		update_post_meta( $id, DoughBoss_Catering_Package::META_SERVES_MAX, '20' );
	}
	return is_wp_error( $id ) ? 0 : (int) $id;
}

/** Read a captured wp_mail attribute by recipient. */
function db_integration_catering_mail_to( $recipient ) {
	foreach ( $GLOBALS['db_catering_mail'] as $mail ) {
		if ( isset( $mail['to'] ) && $recipient === $mail['to'] ) {
			return $mail;
		}
	}
	return array();
}

/**
 * Provider-free Square transport fixture.
 *
 * @param false|array|WP_Error $preempt Existing preemption.
 * @param array                $args    Request arguments.
 * @param string               $url     Request URL.
 * @return false|array|WP_Error
 */
function db_integration_square_http( $preempt, $args, $url ) {
	if ( false === strpos( (string) $url, 'squareup' ) ) {
		return $preempt;
	}
	$method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
	if ( 'POST' === $method && '/v2/refunds' === wp_parse_url( (string) $url, PHP_URL_PATH ) ) {
		$body = json_decode( (string) $args['body'], true );
		$GLOBALS['db_square_refund_requests'][] = $body;
		$refund = array(
			'id' => 'sqrefundINTEGRATION00001',
			'payment_id' => $body['payment_id'],
			'amount_money' => $body['amount_money'],
			'status' => $GLOBALS['db_square_refund_status'],
		);
		if ( 'MISMATCH' === $refund['status'] ) {
			$refund['status'] = 'COMPLETED';
			$refund['amount_money']['amount'] -= 1;
		}
		return db_integration_stripe_http_response( array( 'refund' => $refund ) );
	}
	if ( 'POST' === $method ) {
		++$GLOBALS['db_square_post_count'];
		if ( ! empty( $GLOBALS['db_square_approval_dispatch_observer'] ) ) {
			$observed = $GLOBALS['db_square_approval_dispatch_observer'];
			$approval = DoughBoss_Preorder_Approval::get( $observed['request_id'] );
			$attempt = is_array( $approval ) ? DoughBoss_Payment_Attempts::find( $approval['payment_attempt_id'] ) : null;
			db_integration_assert( is_array( $attempt ) && 'dispatching' === $attempt['status'] && (string) $attempt['checkout_key'] === (string) $observed['checkout_key'], 'approved Square POST observes committed exact attempt link and dispatch claim' );
		}
		if ( 'network_error' === $GLOBALS['db_square_http_mode'] ) {
			return new WP_Error( 'integration_network', 'Synthetic network failure.' );
		}
		$body      = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : array();
		$GLOBALS['db_square_post_bodies'][] = $body;
		$reference = isset( $body['reference_id'] ) ? (string) $body['reference_id'] : '';
		$payment_id = 'sqpay' . substr( hash( 'sha256', $reference ), 0, 28 );
		$payment = array(
			'id'           => $payment_id,
			'status'       => 'failed' === $GLOBALS['db_square_http_mode'] ? 'FAILED' : ( 'pending' === $GLOBALS['db_square_http_mode'] ? 'PENDING' : 'COMPLETED' ),
			'location_id'  => 'location_mismatch' === $GLOBALS['db_square_http_mode'] ? 'LWRONGLOCATION' : ( isset( $body['location_id'] ) ? $body['location_id'] : '' ),
			'reference_id' => $reference,
			'amount_money' => isset( $body['amount_money'] ) ? $body['amount_money'] : array(),
		);
	} else {
		++$GLOBALS['db_square_get_count'];
		$payment_id = rawurldecode( basename( wp_parse_url( (string) $url, PHP_URL_PATH ) ) );
		$attempt    = DoughBoss_Payment_Attempts::find_by_provider_reference( $payment_id );
		if ( ! $attempt ) {
			return new WP_Error( 'integration_missing_attempt', 'Synthetic attempt missing.' );
		}
		$stored_meta = DoughBoss_Payment_Attempts::metadata( $attempt );
		$payment = array(
			'id'           => $payment_id,
			'status'       => 'failed' === $GLOBALS['db_square_http_mode'] ? 'FAILED' : ( 'pending' === $GLOBALS['db_square_http_mode'] ? 'PENDING' : 'COMPLETED' ),
			'location_id'  => 'location_mismatch' === $GLOBALS['db_square_http_mode'] ? 'LWRONGLOCATION' : ( isset( $stored_meta['square_location_id'] ) ? $stored_meta['square_location_id'] : '' ),
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
}

/** Return a synthetic successful WordPress HTTP response. */
function db_integration_stripe_http_response( array $body ) {
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( $body ),
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'cookies'  => array(),
		'filename' => null,
	);
}

/** Read the server-owned Stripe metadata fields from a captured form body. */
function db_integration_stripe_metadata_from_body( array $body ) {
	$metadata = array();
	foreach ( $body as $key => $value ) {
		if ( preg_match( '/^metadata\[([a-z0-9_\-]+)\]$/', (string) $key, $match ) && is_scalar( $value ) ) {
			$metadata[ $match[1] ] = (string) $value;
		}
	}
	return $metadata;
}

/**
 * Strict provider-free Stripe transport fixture.
 *
 * Only the three endpoints used by hosted Checkout are accepted. Any other
 * Stripe path or method returns a local WP_Error, while non-Stripe traffic is
 * left to its own interceptor (and the process-wide external HTTP block).
 *
 * @param false|array|WP_Error $preempt Existing preemption.
 * @param array                $args    Request arguments.
 * @param string               $url     Request URL.
 * @return false|array|WP_Error
 */
function db_integration_stripe_http( $preempt, $args, $url ) {
	$prefix = 'https://api.stripe.com/v1';
	if ( $prefix !== (string) $url && 0 !== strpos( (string) $url, $prefix . '/' ) ) {
		return $preempt;
	}

	$method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
	$path   = wp_parse_url( (string) $url, PHP_URL_PATH );
	if ( 'POST' === $method && '/v1/checkout/sessions' === $path ) {
		++$GLOBALS['db_stripe_post_count'];
		$body = isset( $args['body'] ) && is_array( $args['body'] ) ? $args['body'] : array();
		$GLOBALS['db_stripe_post_bodies'][] = $body;
		$GLOBALS['db_stripe_idempotency'][] = isset( $args['headers']['Idempotency-Key'] ) ? (string) $args['headers']['Idempotency-Key'] : '';

		if ( ! empty( $GLOBALS['db_stripe_network_once'] ) ) {
			$GLOBALS['db_stripe_network_once'] = false;
			return new WP_Error( 'integration_stripe_network', 'Synthetic Stripe network failure.' );
		}

		$checkout_key = isset( $body['client_reference_id'] ) ? (string) $body['client_reference_id'] : '';
		$session_id   = 'cs_test_' . substr( hash( 'sha256', 'session|' . $checkout_key ), 0, 24 );
		$intent_id    = 'pi_' . substr( hash( 'sha256', 'intent|' . $checkout_key ), 0, 24 );
		$amount       = isset( $body['line_items[0][price_data][unit_amount]'] ) ? (int) $body['line_items[0][price_data][unit_amount]'] : 0;
		$currency     = isset( $body['line_items[0][price_data][currency]'] ) ? strtolower( (string) $body['line_items[0][price_data][currency]'] ) : '';
		$metadata     = db_integration_stripe_metadata_from_body( $body );
		$session      = array(
			'id'                  => $session_id,
			'url'                 => 'https://checkout.stripe.com/c/pay/' . $session_id,
			'payment_status'      => 'unpaid',
			'payment_intent'      => $intent_id,
			'amount_total'        => $amount,
			'currency'            => $currency,
			'client_reference_id' => $checkout_key,
			'metadata'            => $metadata,
		);
		$GLOBALS['db_stripe_sessions'][ $session_id ] = $session;
		$GLOBALS['db_stripe_intents'][ $intent_id ]   = array(
			'id'              => $intent_id,
			'status'          => 'succeeded',
			'amount'          => $amount,
			'amount_received' => $amount,
			'currency'        => $currency,
			'metadata'        => $metadata,
		);
		return db_integration_stripe_http_response( $session );
	}

	if ( 'GET' === $method && preg_match( '#^/v1/checkout/sessions/(cs_(?:test|live)_[A-Za-z0-9_]{8,191})$#', (string) $path, $match ) ) {
		++$GLOBALS['db_stripe_session_gets'];
		if ( ! empty( $GLOBALS['db_stripe_session_network_once'] ) ) {
			$GLOBALS['db_stripe_session_network_once'] = false;
			return new WP_Error( 'integration_stripe_session_network', 'Synthetic Stripe Session retrieval failure.' );
		}
		if ( ! isset( $GLOBALS['db_stripe_sessions'][ $match[1] ] ) ) {
			return new WP_Error( 'integration_stripe_session_missing', 'Synthetic Stripe session missing.' );
		}
		$session                   = $GLOBALS['db_stripe_sessions'][ $match[1] ];
		$session['payment_status'] = 'paid';
		return db_integration_stripe_http_response( $session );
	}

	if ( 'GET' === $method && preg_match( '#^/v1/payment_intents/(pi_[A-Za-z0-9_]{8,191})$#', (string) $path, $match ) ) {
		++$GLOBALS['db_stripe_intent_gets'];
		if ( ! isset( $GLOBALS['db_stripe_intents'][ $match[1] ] ) ) {
			return new WP_Error( 'integration_stripe_intent_missing', 'Synthetic Stripe PaymentIntent missing.' );
		}
		return db_integration_stripe_http_response( $GLOBALS['db_stripe_intents'][ $match[1] ] );
	}

	return new WP_Error( 'integration_stripe_unhandled', 'Unhandled synthetic Stripe request.' );
}

/** Build server-owned Stripe metadata for one hosted Checkout fixture. */
function db_integration_stripe_metadata( $seed ) {
	return array(
		'checkout_key' => hash( 'sha256', 'stripe-checkout|' . $seed ),
		'purpose'      => 'order',
		'context'      => 'web',
		'order_type'   => 'pickup',
		'location_id'  => 1,
		'table_id'     => 0,
		'qr_code_id'   => 0,
	);
}

/** Prepare one isolated cart, hosted Stripe Session, attempt and snapshot. */
function db_integration_prepare_stripe_rest_fixture( $seed, $item_id, $unit_price, array $contact_overrides = array() ) {
	$_COOKIE[ DoughBoss_Cart::COOKIE ] = 'IntegrationStripeFixture' . substr( hash( 'sha256', (string) $seed ), 0, 24 );
	$cart = new DoughBoss_Cart();
	$cart->clear();
	$cart->add(
		array(
			'type'       => 'menu',
			'item_id'    => (int) $item_id,
			'name'       => 'Synthetic Stripe ' . sanitize_text_field( (string) $seed ),
			'unit_price' => (float) $unit_price,
			'quantity'   => 1,
		)
	);
	$controller = new DoughBoss_REST_Controller( $cart );
	$params = array_merge(
		array(
			'payment_attempt_key' => 'stripe-' . sanitize_key( (string) $seed ) . '-payment-attempt-0001',
			'customer_name'       => 'Stripe ' . sanitize_text_field( (string) $seed ) . ' Customer',
			'customer_email'      => sanitize_key( (string) $seed ) . '@example.invalid',
			'customer_phone'      => '0400000099',
			'address'             => '',
			'notes'               => 'Original ' . sanitize_text_field( (string) $seed ) . ' note',
			'order_type'          => 'pickup',
			'location_id'         => 1,
			'return_url'          => 'https://doughboss.local.test/order/',
		),
		$contact_overrides
	);
	$prepared = $controller->create_payment_intent( db_integration_rest_request( '/doughboss/v1/payment-intent', $params ) );
	$data     = $prepared instanceof WP_REST_Response ? $prepared->get_data() : array();
	$session  = isset( $data['checkout_session'] ) ? (string) $data['checkout_session'] : '';
	$attempt  = '' !== $session ? DoughBoss_Payment_Attempts::find_by_provider_reference( $session ) : null;
	$snapshot = is_array( $attempt ) ? DoughBoss_Checkout_Snapshots::find( (string) $attempt['checkout_key'] ) : null;
	db_integration_assert( $prepared instanceof WP_REST_Response && '' !== $session && is_array( $attempt ) && is_array( $snapshot ), 'REST Stripe ' . $seed . ' fixture prepares its Session, attempt and snapshot' );
	return array(
		'cart'       => $cart,
		'controller' => $controller,
		'params'     => $params,
		'session'    => $session,
		'pi'         => isset( $GLOBALS['db_stripe_sessions'][ $session ]['payment_intent'] ) ? (string) $GLOBALS['db_stripe_sessions'][ $session ]['payment_intent'] : '',
		'attempt'    => $attempt,
		'snapshot'   => $snapshot,
	);
}

/** Build the exact browser return for a prepared Stripe fixture. */
function db_integration_stripe_checkout_fixture_params( array $fixture, $idempotency_key, array $overrides = array() ) {
	return array_merge(
		array(
			'idempotency_key'     => (string) $idempotency_key,
			'payment_attempt_key' => (string) $fixture['params']['payment_attempt_key'],
			'customer_name'       => (string) $fixture['params']['customer_name'],
			'customer_email'      => (string) $fixture['params']['customer_email'],
			'customer_phone'      => (string) $fixture['params']['customer_phone'],
			'address'             => (string) $fixture['params']['address'],
			'notes'               => (string) $fixture['params']['notes'],
			'order_type'          => 'pickup',
			'location_id'         => 1,
			'payment_intent_id'   => (string) $fixture['session'],
		),
		$overrides
	);
}

/** Keep a confirmation fixture to one intercepted customer-mail attempt. */
function db_integration_no_orders_email( $email ) {
	unset( $email );
	return '';
}

/** Build server-owned Square v2 metadata for one synthetic attempt. */
function db_integration_square_metadata( $attempt_seed, $checkout_seed, $guard_seed, $voucher = array() ) {
	return array_merge(
		array(
			'checkout_key'      => hash( 'sha256', $checkout_seed ),
			'protocol_version'   => 'square-v2',
			'attempt_identity'   => hash( 'sha256', $attempt_seed ),
			'binding_hash'       => hash( 'sha256', 'binding|' . $checkout_seed ),
			'cart_guard'         => hash( 'sha256', $guard_seed ),
			'source_id'          => 'cnon:integration-card-token',
			'verification_token' => 'verify:integration-token',
			'purpose'            => 'order',
			'context'            => 'web',
			'order_type'         => 'pickup',
			'location_id'        => 1,
			'table_id'           => 0,
			'qr_code_id'         => 0,
		),
		$voucher
	);
}

/** Fail exactly one cart-guard read while leaving every later query intact. */
function db_integration_fail_guard_select( $query ) {
	if (
		! empty( $GLOBALS['db_fail_guard_once'] )
		&& false !== strpos( (string) $query, 'doughboss_payment_attempts' )
		&& false !== strpos( (string) $query, 'local_reference =' )
	) {
		$GLOBALS['db_fail_guard_once'] = false;
		return 'SELECT * FROM wp_doughboss_missing_guard_table';
	}
	return $query;
}

/** Server-owned approved Square snapshot; all contacts and amounts are synthetic. */
function db_integration_approved_square_fixture( $seed ) {
	$created = db_integration_create_preorder_request( 'square-' . $seed, 14.00 );
	$id = is_array( $created ) ? (int) $created['order_id'] : 0;
	$pending = DoughBoss_Preorder_Approval::ensure_pending( $id );
	$order = $id ? DoughBoss_Order::get( $id ) : null;
	$approved = $order && ! is_wp_error( $pending ) ? DoughBoss_Preorder_Approval::decide( $id, 'approve', 9001, 'Synthetic Square Approver', 'integration-square-approval-' . $seed, '', (int) $order->version ) : new WP_Error( 'integration_square_fixture' );
	if ( is_wp_error( $approved ) ) { return $approved; }
	$binding = DoughBoss_Square::shop_binding( (int) $order->location_id );
	if ( is_wp_error( $binding ) ) { return $binding; }
	$key = (string) $approved['payment_checkout_key'];
	$lines = DoughBoss_Order::get_items( $id );
	$data = array(
		'order_type' => (string) $order->order_type, 'location_id' => (int) $order->location_id,
		'table_id' => 0, 'table_label' => '', 'table_qr_code_id' => 0, 'table_session_id' => 0,
		'order_source' => 'web', 'source_request_id' => $id,
		'customer_name' => (string) $order->customer_name, 'customer_email' => (string) $order->customer_email,
		'customer_phone' => (string) $order->customer_phone, 'address' => (string) $order->address, 'notes' => (string) $order->notes,
		'subtotal' => (float) $order->subtotal, 'tax' => (float) $order->tax, 'delivery_fee' => (float) $order->delivery_fee,
		'total' => (float) $order->total, 'discount' => 0.0, 'voucher_code' => '', 'currency' => (string) $order->currency, 'checkout_key' => $key,
	);
	$binding_hash = hash( 'sha256', 'approved-square-binding|' . $seed . '|' . $key );
	$payload = array( 'order' => $data, 'lines' => array_values( $lines ), 'approval' => array(
		'request_id' => $id, 'approved_revision' => (int) $approved['approved_revision'], 'request_fingerprint' => (string) $approved['request_fingerprint'],
	), 'square_binding' => $binding, 'binding_hash' => $binding_hash );
	$snapshot = DoughBoss_Checkout_Snapshots::store( $key, $payload, (string) $approved['payment_token_expires_at_utc'] );
	if ( is_wp_error( $snapshot ) ) { return $snapshot; }
	$meta = db_integration_square_metadata( 'approved-square-' . $seed, 'unused-approved-square-' . $seed, 'approved-square-cart-' . $seed, array(
		'checkout_key' => $key, 'context' => 'approved_preorder', 'source_request_id' => $id,
		'approved_revision' => (int) $approved['approved_revision'], 'request_fingerprint' => (string) $approved['request_fingerprint'],
		'binding_hash' => $binding_hash, 'preorder_payment_token' => (string) $approved['capability_token'],
	) );
	return array( 'request_id' => $id, 'token' => (string) $approved['capability_token'], 'approval' => $approved, 'order' => $order, 'order_data' => $data, 'lines' => $lines, 'snapshot' => $snapshot, 'metadata' => $meta );
}

/** Find a synthetic approved Square attempt without exposing its capability. */
function db_integration_approved_square_attempt( array $fixture ) {
	return DoughBoss_Payment_Attempts::find_by_attempt_key( hash( 'sha256', 'square-v2|' . $fixture['metadata']['attempt_identity'] ) );
}

/** Inject exactly one approval-link write failure inside the dispatch transaction. */
function db_integration_fail_square_approval_link( $query ) {
	if ( ! empty( $GLOBALS['db_fail_square_approval_link_once'] ) && 0 === strpos( ltrim( (string) $query ), 'UPDATE' ) && false !== strpos( (string) $query, 'doughboss_preorder_approvals' ) && false !== strpos( (string) $query, 'payment_attempt_id' ) ) {
		$GLOBALS['db_fail_square_approval_link_once'] = false;
		return 'UPDATE wp_doughboss_missing_approval_table SET id = 1';
	}
	return $query;
}

/** Return no attempt exactly once after its approval link has been written. */
function db_integration_fail_square_final_attempt_read( $query ) {
	global $wpdb;
	if ( ! empty( $GLOBALS['db_square_final_read_armed'] ) && 0 === strpos( ltrim( (string) $query ), 'SELECT' ) && false !== strpos( (string) $query, 'doughboss_payment_attempts' ) && false !== strpos( (string) $query, 'WHERE id =' ) && false !== strpos( (string) $query, 'LIMIT 1' ) ) {
		$GLOBALS['db_square_final_read_armed'] = false;
		$GLOBALS['db_square_final_read_exercised'] = true;
		return 'SELECT * FROM ' . $wpdb->prefix . 'doughboss_payment_attempts WHERE id = 0 LIMIT 1';
	}
	if ( 0 === strpos( ltrim( (string) $query ), 'UPDATE' ) && false !== strpos( (string) $query, 'doughboss_preorder_approvals' ) && false !== strpos( (string) $query, 'payment_attempt_id' ) ) {
		$GLOBALS['db_square_final_read_armed'] = true;
	}
	return $query;
}

/** Fail one COMMIT as a statement, leaving cleanup to the transaction owner. */
function db_integration_fail_square_commit( $query ) {
	if ( ! empty( $GLOBALS['db_fail_square_commit_once'] ) && 'COMMIT' === strtoupper( trim( (string) $query ) ) ) {
		$GLOBALS['db_fail_square_commit_once'] = false;
		return 'SELECT FROM doughboss_synthetic_commit_failure';
	}
	return $query;
}

/** Prove another connection can immediately lock the same guarded fixture rows. */
function db_integration_square_rows_unlocked( $request_id, $attempt_id ) {
	global $wpdb;
	$probe = mysqli_init();
	if ( ! $probe || ! $probe->real_connect( '127.0.0.1', DB_USER, DB_PASSWORD, DB_NAME, 33117 ) ) {
		return false;
	}
	$probe->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
	$probe->query( 'START TRANSACTION' );
	$orders = $probe->query( 'SELECT id FROM ' . $wpdb->prefix . 'doughboss_orders WHERE id = ' . (int) $request_id . ' FOR UPDATE' );
	$approvals = false !== $orders ? $probe->query( 'SELECT request_order_id FROM ' . $wpdb->prefix . 'doughboss_preorder_approvals WHERE request_order_id = ' . (int) $request_id . ' FOR UPDATE' ) : false;
	$attempts = false !== $approvals ? $probe->query( 'SELECT id FROM ' . $wpdb->prefix . 'doughboss_payment_attempts WHERE id = ' . (int) $attempt_id . ' FOR UPDATE' ) : false;
	$probe->query( 'ROLLBACK' );
	$probe->close();
	return false !== $orders && false !== $approvals && false !== $attempts;
}

/** Schedule a real second-connection quarantine around fulfillment validation. */
function db_integration_square_quarantine_interleave( $query ) {
	global $wpdb;
	$mode = isset( $GLOBALS['db_square_quarantine_mode'] ) ? $GLOBALS['db_square_quarantine_mode'] : '';
	$before = 'before-read' === $mode && 0 === strpos( ltrim( (string) $query ), 'SELECT' ) && false !== strpos( (string) $query, 'doughboss_payment_attempts' ) && false !== strpos( (string) $query, 'provider_reference =' );
	$after = 'after-read' === $mode && 0 === strpos( ltrim( (string) $query ), 'UPDATE' ) && false !== strpos( (string) $query, 'doughboss_preorder_approvals' ) && false !== strpos( (string) $query, "'released'" );
	if ( ! $before && ! $after ) {
		return $query;
	}
	$GLOBALS['db_square_quarantine_mode'] = '';
	$GLOBALS['db_square_quarantine_exercised'] = true;
	$probe = mysqli_init();
	if ( ! $probe || ! $probe->real_connect( '127.0.0.1', DB_USER, DB_PASSWORD, DB_NAME, 33117 ) ) {
		$GLOBALS['db_square_quarantine_probe_failed'] = true;
		return $query;
	}
	$probe->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
	$changed = $probe->query( 'UPDATE ' . $wpdb->prefix . 'doughboss_payment_attempts SET status = \'mismatch\' WHERE id = ' . (int) $GLOBALS['db_square_quarantine_attempt_id'] );
	$GLOBALS['db_square_quarantine_won'] = false !== $changed && 1 === $probe->affected_rows;
	$GLOBALS['db_square_quarantine_blocked_by_lock'] = false === $changed && 1205 === (int) $probe->errno;
	$probe->close();
	return $query;
}

/** Change a synthetic shop map at the dispatch claim, before provider HTTP. */
function db_integration_square_binding_drift( $query ) {
	if ( ! empty( $GLOBALS['db_square_binding_drift_once'] ) && false !== strpos( (string) $query, 'doughboss_payment_attempts' ) && false !== strpos( (string) $query, "status = 'dispatching'" ) ) {
		$GLOBALS['db_square_binding_drift_once'] = false;
		$changed = DoughBoss_Settings::all();
		$changed['square_test_location_map']['revesby'] = 'LCHANGEDATDISPATCH';
		update_option( DoughBoss_Settings::OPTION_KEY, $changed, false );
	}
	return $query;
}

/** Fail exactly one deployment-wide legacy drain read. */
function db_integration_fail_legacy_select( $query ) {
	if (
		! empty( $GLOBALS['db_fail_legacy_once'] )
		&& false !== strpos( (string) $query, 'doughboss_payment_attempts' )
		&& false !== strpos( (string) $query, 'LEFT JOIN' )
		&& false !== strpos( (string) $query, 'safe_metadata_json' )
	) {
		$GLOBALS['db_fail_legacy_once'] = false;
		return 'SELECT * FROM wp_doughboss_missing_legacy_table';
	}
	return $query;
}

/** Fail exactly one voucher UPDATE during terminal webhook release. */
function db_integration_fail_voucher_release( $query ) {
	if (
		! empty( $GLOBALS['db_fail_voucher_release_once'] )
		&& 0 === strpos( ltrim( (string) $query ), 'UPDATE ' )
		&& false !== strpos( (string) $query, 'doughboss_vouchers' )
	) {
		$GLOBALS['db_fail_voucher_release_once'] = false;
		return 'UPDATE wp_doughboss_missing_voucher_table SET meta = NULL';
	}
	return $query;
}

/** Fail exactly one checkout-snapshot completion UPDATE. */
function db_integration_fail_snapshot_complete( $query ) {
	if (
		! empty( $GLOBALS['db_fail_snapshot_complete_once'] )
		&& 0 === strpos( ltrim( (string) $query ), 'UPDATE ' )
		&& false !== strpos( (string) $query, 'doughboss_checkout_snapshots' )
		&& false !== strpos( (string) $query, "'completed'" )
	) {
		$GLOBALS['db_fail_snapshot_complete_once'] = false;
		return "UPDATE wp_doughboss_missing_snapshot_table SET status = 'completed'";
	}
	return $query;
}

/** Fail exactly one Stripe finalizer UPDATE after its snapshot is completed. */
function db_integration_fail_attempt_succeed( $query ) {
	if (
		! empty( $GLOBALS['db_fail_attempt_succeed_once'] )
		&& 0 === strpos( ltrim( (string) $query ), 'UPDATE ' )
		&& false !== strpos( (string) $query, 'doughboss_payment_attempts' )
		&& false !== strpos( (string) $query, "'succeeded'" )
	) {
		$GLOBALS['db_fail_attempt_succeed_once'] = false;
		return "UPDATE wp_doughboss_missing_attempt_table SET status = 'succeeded'";
	}
	return $query;
}

/** Fail exactly one approval mail-status UPDATE without changing approval state. */
function db_integration_fail_preorder_delivery_status( $query ) {
	if (
		! empty( $GLOBALS['db_fail_preorder_delivery_once'] )
		&& 0 === strpos( ltrim( (string) $query ), 'UPDATE ' )
		&& false !== strpos( (string) $query, 'doughboss_preorder_approvals' )
		&& false !== strpos( (string) $query, 'payment_link_delivery_status' )
	) {
		$GLOBALS['db_fail_preorder_delivery_once'] = false;
		return "UPDATE wp_doughboss_missing_preorder_delivery_table SET payment_link_delivery_status = 'failed'";
	}
	return $query;
}

/** Fail exactly one compare-and-set of the legacy operational order inbox. */
function db_integration_fail_orders_email_migration( $query ) {
	if (
		! empty( $GLOBALS['db_fail_orders_email_migration_once'] )
		&& 0 === strpos( ltrim( (string) $query ), 'UPDATE ' )
		&& false !== strpos( (string) $query, 'doughboss_settings' )
		&& false !== strpos( (string) $query, 'info@doughboss.com.au' )
	) {
		$GLOBALS['db_fail_orders_email_migration_once'] = false;
		return "UPDATE wp_doughboss_missing_orders_email_option SET option_value = 'failed'";
	}
	return $query;
}

global $wpdb;

// Fresh activation contract and reactivation idempotency.
db_integration_same( true, defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && true === WP_HTTP_BLOCK_EXTERNAL, 'disposable integration process blocks every unhandled external HTTP request' );
$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'doughboss_locations' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
db_integration_same( 0, DoughBoss_Locations::count(), 'fresh activation fixture begins with no saved locations' );
DoughBoss_Activator::activate();
$locations = DoughBoss_Locations::all();
db_integration_same( 1, count( $locations ), 'fresh activation seeds exactly one location' );
db_integration_same( 'Revesby', (string) $locations[0]->name, 'Dough Boss installation seeds Revesby' );
db_integration_same( 'revesby', (string) $locations[0]->slug, 'seed location has stable Revesby slug' );
DoughBoss_Activator::activate();
db_integration_same( 1, DoughBoss_Locations::count(), 'reactivation does not duplicate the seed location' );
db_integration_same( DOUGHBOSS_DB_VERSION, (string) get_option( 'doughboss_db_version' ), 'activation stamps the complete schema version' );

$bad_engines = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s AND UPPER(ENGINE) <> 'INNODB'",
		$wpdb->esc_like( $wpdb->prefix . 'doughboss_' ) . '%'
	)
);
db_integration_same( 0, $bad_engines, 'every DoughBoss table uses InnoDB' );
db_integration_assert( DoughBoss_Activator::lifecycle_storage_ready(), 'order lifecycle storage contract is ready' );
db_integration_assert( DoughBoss_Activator::checkout_storage_ready(), 'checkout snapshot storage contract is ready' );
db_integration_assert( DoughBoss_Activator::payment_storage_ready(), 'payment attempt storage contract is ready' );
db_integration_assert( DoughBoss_Activator::preorder_approval_storage_ready(), 'pre-order approval table and index contract is ready' );
$migration_settings_original = get_option( DoughBoss_Settings::OPTION_KEY, array() );
$migration_settings_legacy = is_array( $migration_settings_original ) ? $migration_settings_original : array();
$migration_settings_legacy['orders_email'] = 'orders@doughboss.com.au';
update_option( DoughBoss_Settings::OPTION_KEY, $migration_settings_legacy, false );
$migration_124 = new ReflectionMethod( 'DoughBoss_Migrations', 'upgrade_to_1_24_0' );
$migration_124->setAccessible( true );
$GLOBALS['db_fail_orders_email_migration_once'] = true;
add_filter( 'query', 'db_integration_fail_orders_email_migration' );
$migration_failure_message = '';
$previous_suppress_errors = $wpdb->suppress_errors( true );
try {
	$migration_124->invoke( null );
} catch ( Throwable $migration_failure ) {
	$migration_failure_message = $migration_failure->getMessage();
}
$wpdb->suppress_errors( $previous_suppress_errors );
remove_filter( 'query', 'db_integration_fail_orders_email_migration' );
$migration_settings_failed = get_option( DoughBoss_Settings::OPTION_KEY, array() );
db_integration_same( false, (bool) $GLOBALS['db_fail_orders_email_migration_once'], '1.24 inbox failure fixture intercepts the exact compare-and-set' );
db_integration_same( 'The operational order inbox could not be migrated safely.', $migration_failure_message, '1.24 inbox migration fails closed when its database write fails' );
db_integration_same( 'orders@doughboss.com.au', is_array( $migration_settings_failed ) && isset( $migration_settings_failed['orders_email'] ) ? (string) $migration_settings_failed['orders_email'] : '', 'failed 1.24 inbox migration leaves the legacy value visible for a later retry' );
$migration_124->invoke( null );
$migration_settings_updated = get_option( DoughBoss_Settings::OPTION_KEY, array() );
db_integration_same( 'info@doughboss.com.au', is_array( $migration_settings_updated ) && isset( $migration_settings_updated['orders_email'] ) ? (string) $migration_settings_updated['orders_email'] : '', '1.24 migration persists the exact legacy order inbox replacement before checkpointing' );
$migration_settings_updated['orders_email'] = 'custom.integration@example.invalid';
update_option( DoughBoss_Settings::OPTION_KEY, $migration_settings_updated, false );
$migration_124->invoke( null );
$migration_settings_custom = get_option( DoughBoss_Settings::OPTION_KEY, array() );
db_integration_same( 'custom.integration@example.invalid', is_array( $migration_settings_custom ) && isset( $migration_settings_custom['orders_email'] ) ? (string) $migration_settings_custom['orders_email'] : '', '1.24 migration preserves an independently supplied custom order inbox' );
update_option( DoughBoss_Settings::OPTION_KEY, $migration_settings_original, false );
$preorder_approval_table = $wpdb->prefix . 'doughboss_preorder_approvals';
$preorder_approval_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $preorder_approval_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$preorder_approval_checkout_index = $wpdb->get_row( 'SHOW INDEX FROM ' . $preorder_approval_table . " WHERE Key_name = 'payment_checkout_key'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$preorder_delivery_status_column = $wpdb->get_row( 'SHOW COLUMNS FROM ' . $preorder_approval_table . " LIKE 'payment_link_delivery_status'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$preorder_delivery_time_column = $wpdb->get_row( 'SHOW COLUMNS FROM ' . $preorder_approval_table . " LIKE 'payment_link_delivery_attempted_at_utc'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
db_integration_same( $preorder_approval_table, $preorder_approval_table_exists, 'pre-order approval table is present after fresh activation' );
db_integration_same( 'payment_checkout_key', isset( $preorder_approval_checkout_index->Key_name ) ? (string) $preorder_approval_checkout_index->Key_name : '', 'pre-order approval checkout key has its unique storage index' );
db_integration_same( 'not_attempted', isset( $preorder_delivery_status_column->Default ) ? (string) $preorder_delivery_status_column->Default : '', 'pre-order approval stores an explicit default delivery outcome' );
db_integration_same( 'YES', isset( $preorder_delivery_time_column->Null ) ? (string) $preorder_delivery_time_column->Null : '', 'pre-order approval delivery-attempt time remains nullable until an attempt occurs' );

// Isolate the payment protocol fixtures from activation evidence.
	foreach ( array( 'doughboss_order_items', 'doughboss_order_events', 'doughboss_orders', 'doughboss_preorder_approvals', 'doughboss_payment_events', 'doughboss_payment_attempts', 'doughboss_checkout_snapshots', 'doughboss_voucher_redemptions', 'doughboss_voucher_audit', 'doughboss_vouchers', 'doughboss_catering_enquiries' ) as $suffix ) {
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . $suffix ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_doughboss_rl_%' OR option_name LIKE '_transient_timeout_doughboss_rl_%' OR option_name LIKE '_transient_doughboss_idem_%' OR option_name LIKE '_transient_timeout_doughboss_idem_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$settings = DoughBoss_Settings::all();
$settings['ordering_open']            = 1;
$settings['enable_pickup']            = 1;
$settings['enable_delivery']          = 0;
$settings['payments_enabled']         = 1;
$settings['payment_gateway']          = 'square';
$settings['currency_code']            = 'AUD';
$settings['pospal_order_push_enabled'] = 0;
$settings['square_mode']              = 'test';
$settings['legacy_voucher_issuance_enabled'] = 0;
$settings['square_test_app_id']       = 'sandbox-sq0idb-INTEGRATIONAPP000000';
$settings['square_test_access_token'] = 'EAAAlocalSyntheticIntegrationToken000000';
$settings['square_test_location_id']  = 'LUNUSEDGLOBALLOCATION';
$settings['square_test_location_map'] = array( 'revesby' => 'LINTEGRATIONLOCATION', 'bankstown' => 'LINTEGRATIONBANKSTOWN', 'roselands' => 'LINTEGRATIONROSELANDS' );
$settings['square_test_webhook_key']  = 'local-synthetic-webhook-key-000000';
$settings['square_webhook_url']       = 'https://example.invalid/wp-json/doughboss/v1/square-webhook';
update_option( 'doughboss_settings', $settings, false );
$paused_voucher_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}doughboss_vouchers" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$paused_manual_voucher = DoughBoss_Voucher::issue( array( 'type' => 'amount', 'value' => 5, 'prefix' => 'PAUSE' ) );
$paused_campaign_voucher = DoughBoss_Voucher::claim(
	'dough5',
	array(
		'customer_email' => 'pause@student.example.edu.au',
		'customer_email_confirmation' => 'pause@student.example.edu.au',
	)
);
db_integration_same( 'doughboss_voucher_issuance_paused', is_wp_error( $paused_manual_voucher ) ? $paused_manual_voucher->get_error_code() : '', 'paused manual voucher issue fails before creation' );
db_integration_same( 'doughboss_voucher_issuance_paused', is_wp_error( $paused_campaign_voucher ) ? $paused_campaign_voucher->get_error_code() : '', 'paused active campaign cannot claim a new voucher' );
db_integration_same( $paused_voucher_rows, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}doughboss_vouchers" ), 'paused issuance does not write voucher rows' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$settings['legacy_voucher_issuance_enabled'] = 1;
update_option( 'doughboss_settings', $settings, false );
$wpdb->update( $wpdb->prefix . 'doughboss_locations', array( 'online_payment_enabled' => 1, 'pickup_enabled' => 1, 'is_active' => 1 ), array( 'id' => 1 ) );
add_filter( 'pre_http_request', 'db_integration_square_http', 10, 3 );

$first_meta = db_integration_square_metadata( 'attempt-one', 'checkout-one', 'cart-one' );
$first      = DoughBoss_Square::create_payment_intent( 2750, 'AUD', $first_meta );
db_integration_assert( ! is_wp_error( $first ), 'first Square v2 request succeeds against the mocked provider' );
db_integration_same( 1, $GLOBALS['db_square_post_count'], 'first attempt performs exactly one provider POST' );
db_integration_same( 'succeeded', isset( $first['status'] ) ? $first['status'] : '', 'COMPLETED provider response is persisted as succeeded' );

$first_attempt = ! is_wp_error( $first ) ? DoughBoss_Payment_Attempts::find( (int) $first['attempt_id'] ) : null;
db_integration_same( 'succeeded', is_array( $first_attempt ) ? $first_attempt['status'] : '', 'bound attempt has succeeded state' );
$first_stored_meta = is_array( $first_attempt ) ? DoughBoss_Payment_Attempts::metadata( $first_attempt ) : array();
db_integration_same( 'square-v2', isset( $first_stored_meta['protocol_version'] ) ? $first_stored_meta['protocol_version'] : '', 'attempt stores the v2 protocol marker' );
db_integration_same( 'revesby', isset( $first_stored_meta['square_shop_slug'] ) ? $first_stored_meta['square_shop_slug'] : '', 'attempt stores exact server-derived shop slug' );
db_integration_same( 'LINTEGRATIONLOCATION', isset( $first_stored_meta['square_location_id'] ) ? $first_stored_meta['square_location_id'] : '', 'attempt uses explicit map, not different global ID' );
db_integration_same( 'LINTEGRATIONLOCATION', $GLOBALS['db_square_post_bodies'][0]['location_id'], 'provider dispatch uses mapped Revesby location' );
db_integration_assert( ! isset( $first_stored_meta['source_id'], $first_stored_meta['verification_token'] ), 'single-use card and verification tokens are never persisted' );
db_integration_same( false, DoughBoss_Payment_Attempts::update( (int) $first['attempt_id'], array( 'status' => 'processing' ) ), 'generic update cannot regress Square v2 state' );
db_integration_same( false, DoughBoss_Payment_Attempts::update( (int) $first['attempt_id'], array( 'safe_metadata' => array( 'protocol_version' => 'square-v2' ) ) ), 'generic update cannot replace the immutable Square v2 binding' );

$replay = DoughBoss_Square::create_payment_intent( 2750, 'AUD', $first_meta );
db_integration_assert( ! is_wp_error( $replay ), 'same attempt replays successfully' );
db_integration_same( 1, $GLOBALS['db_square_post_count'], 'same attempt never performs a second provider POST' );
db_integration_same( 1, $GLOBALS['db_square_get_count'], 'same attempt reconciles through a provider GET' );
db_integration_same( $first['id'], $replay['id'], 'replay returns the original payment reference' );

// An accepted refund request is not necessarily a completed refund. Exercise
// the real gateway facade and HTTP decoder so the admin's WP_Error gate holds.
$GLOBALS['db_square_refund_requests'] = array();
foreach ( array( 'PENDING', 'FAILED', 'REJECTED', 'MISMATCH', 'COMPLETED' ) as $refund_status ) {
	$GLOBALS['db_square_refund_status'] = $refund_status;
	$refund_result = DoughBoss_Payment::refund_via( 'square', $first['id'] );
	db_integration_same( 'COMPLETED' !== $refund_status, is_wp_error( $refund_result ), 'refund facade refuses to mark ' . $refund_status . ' as refunded' );
}
$refund_requests = $GLOBALS['db_square_refund_requests'];
db_integration_same( 5, count( $refund_requests ), 'refund scenarios reach the mocked refund transport' );
foreach ( $refund_requests as $refund_request ) {
	db_integration_same( $refund_requests[0]['idempotency_key'], $refund_request['idempotency_key'], 'same refund retains its idempotency key across pending and completed responses' );
	db_integration_same( array( 'amount' => 2750, 'currency' => 'AUD' ), $refund_request['amount_money'], 'refund amount comes from the persisted payment' );
	db_integration_same( $first['id'], $refund_request['payment_id'], 'refund uses the stored payment reference' );
}

$blocked = DoughBoss_Square::create_payment_intent( 2750, 'AUD', db_integration_square_metadata( 'attempt-two', 'checkout-two', 'cart-one' ) );
db_integration_assert( is_wp_error( $blocked ), 'a new nonce for the same unresolved cart is blocked' );
db_integration_same( true, is_wp_error( $blocked ) && ! empty( $blocked->get_error_data()['payment_pending'] ), 'blocked cart reports payment pending' );
db_integration_same( 1, $GLOBALS['db_square_post_count'], 'blocked cart does not POST another charge' );

// A cart guard is released only after a durable order references the payment.
$order_key = hash( 'sha256', 'durable-order-one' );
$wpdb->insert(
	$wpdb->prefix . 'doughboss_orders',
	array(
		'order_number'       => 'DB-INTEGRATION-1',
		'location_id'        => 1,
		'status'             => 'pending',
		'order_type'         => 'pickup',
		'customer_name'      => 'Integration Customer',
		'customer_email'     => 'customer@example.invalid',
		'customer_phone'     => '0400000000',
		'subtotal'           => 27.50,
		'tax'                => 2.50,
		'delivery_fee'       => 0,
		'total'              => 27.50,
		'payment_status'     => 'paid',
		'payment_method'     => 'square',
		'payment_intent_id'  => $first['id'],
		'checkout_key'       => $order_key,
		'created_at'         => current_time( 'mysql', true ),
		'updated_at'         => current_time( 'mysql', true ),
	)
);
$order_id = (int) $wpdb->insert_id;
db_integration_assert( $order_id > 0, 'synthetic durable order is stored' );
db_integration_assert( DoughBoss_Payment_Attempts::mark_order_committed( $first['id'], $order_id ), 'attempt is committed only after its durable order exists' );
$committed = DoughBoss_Payment_Attempts::find( (int) $first['attempt_id'] );
db_integration_same( 'order_committed', $committed['status'], 'durable order releases the old cart guard' );
$committed_replay = DoughBoss_Square::retrieve_payment_intent( $first['id'] );
db_integration_same( 'succeeded', is_array( $committed_replay ) ? $committed_replay['status'] : '', 'durable committed order remains safely replayable without regressing its stored state' );

$after_commit = DoughBoss_Square::create_payment_intent( 3100, 'AUD', db_integration_square_metadata( 'attempt-three', 'checkout-three', 'cart-one' ) );
db_integration_assert( ! is_wp_error( $after_commit ), 'same cart can begin a new payment after its prior order is durable' );
db_integration_same( 2, $GLOBALS['db_square_post_count'], 'post-commit attempt performs one new provider POST' );

// Durable quarantine and winner states override stale provider observations.
$mismatch_payment = DoughBoss_Square::create_payment_intent( 3200, 'AUD', db_integration_square_metadata( 'mismatch-attempt', 'mismatch-checkout', 'mismatch-cart' ) );
db_integration_assert( ! is_wp_error( $mismatch_payment ), 'mismatch fixture first records a matching payment' );
DoughBoss_Payment_Attempts::reconcile_irreversible_reference( $mismatch_payment['id'], 'mismatch', 'COMPLETED' );
$mismatch_replay = DoughBoss_Square::retrieve_payment_intent( $mismatch_payment['id'] );
db_integration_same( 'doughboss_pay_mismatch', is_wp_error( $mismatch_replay ) ? $mismatch_replay->get_error_code() : '', 'durable mismatch cannot be reopened by a later matching response' );

$stale_payment = DoughBoss_Square::create_payment_intent( 3300, 'AUD', db_integration_square_metadata( 'stale-attempt', 'stale-checkout', 'stale-cart' ) );
db_integration_assert( ! is_wp_error( $stale_payment ), 'stale-response fixture first records success' );
$GLOBALS['db_square_http_mode'] = 'failed';
$stale_failure = DoughBoss_Square::retrieve_payment_intent( $stale_payment['id'] );
db_integration_same( 'doughboss_pay_reconcile', is_wp_error( $stale_failure ) ? $stale_failure->get_error_code() : '', 'stale FAILED response cannot override durable success' );
$stale_row = DoughBoss_Payment_Attempts::find( (int) $stale_payment['attempt_id'] );
db_integration_same( 'succeeded', $stale_row['status'], 'stale terminal response leaves the winning succeeded state intact' );

$GLOBALS['db_square_http_mode'] = 'success';
$mode_payment = DoughBoss_Square::create_payment_intent( 3400, 'AUD', db_integration_square_metadata( 'mode-attempt', 'mode-checkout', 'mode-cart' ) );
$settings['square_mode'] = 'live';
update_option( 'doughboss_settings', $settings, false );
$mode_drift = DoughBoss_Square::retrieve_payment_intent( $mode_payment['id'] );
db_integration_same( 'doughboss_pay_mode_changed', is_wp_error( $mode_drift ) ? $mode_drift->get_error_code() : '', 'Square mode drift fails before provider retrieval' );
$settings['square_mode'] = 'test';
update_option( 'doughboss_settings', $settings, false );

// An ambiguous provider boundary is irreversible and never re-posted.
$GLOBALS['db_square_http_mode'] = 'network_error';
$unknown_meta = db_integration_square_metadata( 'attempt-unknown', 'checkout-unknown', 'cart-unknown' );
$unknown      = DoughBoss_Square::create_payment_intent( 1800, 'AUD', $unknown_meta );
db_integration_assert( is_wp_error( $unknown ), 'synthetic network failure returns a safe error' );
db_integration_same( true, is_wp_error( $unknown ) && ! empty( $unknown->get_error_data()['payment_pending'] ), 'ambiguous POST reports payment pending' );
$posts_after_unknown = $GLOBALS['db_square_post_count'];
$unknown_replay      = DoughBoss_Square::create_payment_intent( 1800, 'AUD', $unknown_meta );
db_integration_assert( is_wp_error( $unknown_replay ), 'ambiguous attempt stays blocked on replay' );
db_integration_same( $posts_after_unknown, $GLOBALS['db_square_post_count'], 'ambiguous attempt never posts again' );
$unknown_row = DoughBoss_Payment_Attempts::find_by_attempt_key( hash( 'sha256', 'square-v2|' . hash( 'sha256', 'attempt-unknown' ) ) );
db_integration_same( 'unknown', is_array( $unknown_row ) ? $unknown_row['status'] : '', 'ambiguous dispatch is durably marked unknown' );
$unknown_snapshot = DoughBoss_Checkout_Snapshots::store( $unknown_row['checkout_key'], array( 'order' => array( 'fixture' => true ), 'lines' => array() ) );
db_integration_assert( ! is_wp_error( $unknown_snapshot ), 'unknown-payment recovery snapshot is stored' );
$wpdb->update(
	$wpdb->prefix . 'doughboss_checkout_snapshots',
	array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
	array( 'checkout_key' => $unknown_row['checkout_key'] )
);
db_integration_same( null, DoughBoss_Checkout_Snapshots::find( $unknown_row['checkout_key'] ), 'ordinary snapshot lookup still enforces retention expiry' );
db_integration_assert( is_array( DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( $unknown_row['checkout_key'] ) ), 'irreversible unknown payment retains its recovery snapshot beyond ordinary expiry' );
$unprotected_key = hash( 'sha256', 'expired-unprotected-snapshot' );
$unprotected_snapshot = DoughBoss_Checkout_Snapshots::store( $unprotected_key, array( 'order' => array( 'fixture' => 'expired' ), 'lines' => array() ) );
db_integration_assert( ! is_wp_error( $unprotected_snapshot ), 'unprotected expiry fixture is stored' );
$wpdb->update(
	$wpdb->prefix . 'doughboss_checkout_snapshots',
	array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
	array( 'checkout_key' => $unprotected_key )
);
$purge = new ReflectionMethod( 'DoughBoss_Checkout_Snapshots', 'purge_expired' );
$purge->setAccessible( true );
$purge->invoke( null, true );
$protected_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'doughboss_checkout_snapshots WHERE checkout_key = %s', $unknown_row['checkout_key'] ) );
$unprotected_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'doughboss_checkout_snapshots WHERE checkout_key = %s', $unprotected_key ) );
db_integration_same( 1, $protected_count, 'forced retention purge preserves an expired unresolved-payment snapshot' );
db_integration_same( 0, $unprotected_count, 'forced retention purge removes an expired unprotected snapshot' );

// A failed guard SELECT is not equivalent to finding no unresolved payment.
$GLOBALS['db_square_http_mode'] = 'success';
$guard_fail_meta = db_integration_square_metadata( 'guard-first', 'guard-first-checkout', 'guard-read-failure' );
$guard_first     = DoughBoss_Square::create_payment_intent( 1900, 'AUD', $guard_fail_meta );
db_integration_assert( ! is_wp_error( $guard_first ), 'guard-failure fixture creates one unresolved payment first' );
$posts_before_guard_failure          = $GLOBALS['db_square_post_count'];
$GLOBALS['db_fail_guard_once']       = true;
$previous_suppress_errors            = $wpdb->suppress_errors( true );
add_filter( 'query', 'db_integration_fail_guard_select' );
$guard_failure = DoughBoss_Square::create_payment_intent( 1900, 'AUD', db_integration_square_metadata( 'guard-second', 'guard-second-checkout', 'guard-read-failure' ) );
remove_filter( 'query', 'db_integration_fail_guard_select' );
$wpdb->suppress_errors( $previous_suppress_errors );
db_integration_assert( is_wp_error( $guard_failure ), 'failed cart-guard read fails closed' );
db_integration_same( 'doughboss_pay_guard_storage', is_wp_error( $guard_failure ) ? $guard_failure->get_error_code() : '', 'guard storage failure is distinguishable from no row' );
db_integration_same( $posts_before_guard_failure, $GLOBALS['db_square_post_count'], 'failed guard read cannot POST another payment' );

// Voucher ownership survives expiry once the provider boundary is crossed.
$GLOBALS['db_square_http_mode'] = 'network_error';
$pause_preserved_voucher = DoughBoss_Voucher::issue( array( 'type' => 'amount', 'value' => 5, 'scope' => 'online', 'location_id' => 1, 'prefix' => 'KEEP' ) );
db_integration_assert( ! is_wp_error( $pause_preserved_voucher ), 'existing-voucher preservation fixture issued before pause' );
$pause_preserved_settings = DoughBoss_Settings::all();
$pause_active_settings = $pause_preserved_settings;
$pause_active_settings['legacy_voucher_issuance_enabled'] = 0;
update_option( DoughBoss_Settings::OPTION_KEY, $pause_active_settings, false );
$pause_preserved_key = hash( 'sha256', 'existing-voucher-preservation' );
$pause_preserved_reservation = DoughBoss_Voucher::reserve( $pause_preserved_voucher['code'], 25, 'online', $pause_preserved_key, 300, 'preservation@example.invalid' );
db_integration_assert( ! is_wp_error( $pause_preserved_reservation ), 'issued voucher can still reserve while new issuance is paused' );
$pause_preserved_extra = array( 'idempotency_key' => 'integration-preserved-voucher', 'reservation_key' => $pause_preserved_key, 'location_id' => 1 );
$pause_preserved_redemption = DoughBoss_Voucher::redeem( $pause_preserved_voucher['code'], 25, 'online', $pause_preserved_extra );
$pause_preserved_replay = DoughBoss_Voucher::redeem( $pause_preserved_voucher['code'], 25, 'online', $pause_preserved_extra );
db_integration_assert( ! is_wp_error( $pause_preserved_redemption ) && 5.0 === $pause_preserved_redemption['amount'] && $pause_preserved_redemption === $pause_preserved_replay, 'paused issuance preserves exact existing redemption and idempotent replay' );
$pause_preserved_row = DoughBoss_Voucher::find_by_code( $pause_preserved_voucher['code'] );
db_integration_assert( $pause_preserved_row && 'redeemed' === $pause_preserved_row->status && $pause_preserved_voucher['code'] === $pause_preserved_row->code, 'pause keeps the original voucher identity and durable redemption state' );
db_integration_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}doughboss_voucher_redemptions WHERE voucher_id = %d", $pause_preserved_voucher['id'] ) ), 'paused existing-voucher replay keeps exactly one immutable audit record' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
update_option( DoughBoss_Settings::OPTION_KEY, $pause_preserved_settings, false );
$voucher = DoughBoss_Voucher::issue( array( 'type' => 'amount', 'value' => 5, 'scope' => 'online', 'location_id' => 1, 'prefix' => 'IT' ) );
db_integration_assert( ! is_wp_error( $voucher ), 'synthetic voucher is issued' );
$voucher_key = hash( 'sha256', 'voucher-checkout' );
$reserved    = DoughBoss_Voucher::reserve( $voucher['code'], 25, 'online', $voucher_key, 300, 'customer@example.invalid' );
db_integration_assert( ! is_wp_error( $reserved ), 'voucher is reserved by the checkout' );
$voucher_meta = db_integration_square_metadata(
	'voucher-attempt',
	'voucher-checkout',
	'voucher-cart',
	array( 'voucher_code' => $voucher['code'], 'voucher_reservation_key' => $voucher_key )
);
$voucher_unknown = DoughBoss_Square::create_payment_intent( 2000, 'AUD', $voucher_meta );
db_integration_assert( is_wp_error( $voucher_unknown ), 'voucher-backed ambiguous payment fails closed' );
$voucher_row = DoughBoss_Voucher::find_by_code( $voucher['code'] );
$voucher_state = json_decode( (string) $voucher_row->meta, true );
$pending_owner = isset( $voucher_state[ DoughBoss_Voucher::RESERVATION_META_KEY ] ) ? $voucher_state[ DoughBoss_Voucher::RESERVATION_META_KEY ] : array();
db_integration_same( true, ! empty( $pending_owner['payment_pending'] ), 'voucher becomes non-stealable while payment is unresolved' );
$pending_owner['expires_at'] = time() - 60;
$voucher_state[ DoughBoss_Voucher::RESERVATION_META_KEY ] = $pending_owner;
$wpdb->update( $wpdb->prefix . 'doughboss_vouchers', array( 'meta' => wp_json_encode( $voucher_state ) ), array( 'id' => (int) $voucher['id'] ) );
$steal = DoughBoss_Voucher::reserve( $voucher['code'], 25, 'online', hash( 'sha256', 'different-owner' ), 300, 'other@example.invalid' );
db_integration_assert( is_wp_error( $steal ), 'expired timestamp cannot steal a payment-pending voucher' );
db_integration_same( false, DoughBoss_Voucher::release_reservation( $voucher['code'], $voucher_key ), 'ordinary lease release cannot clear payment-pending ownership' );
db_integration_same( false, DoughBoss_Voucher::release_payment_reservation( $voucher['code'], $voucher_key, (int) $pending_owner['attempt_id'] + 1 ), 'wrong attempt cannot release payment-pending voucher' );
db_integration_same( false, DoughBoss_Voucher::release_payment_reservation( $voucher['code'], $voucher_key, (int) $pending_owner['attempt_id'] ), 'unknown payment cannot release payment-pending voucher' );

// A matching, provider-validated FAILED attempt is terminal no-charge evidence.
$terminal_voucher = DoughBoss_Voucher::issue( array( 'type' => 'amount', 'value' => 4, 'scope' => 'online', 'location_id' => 1, 'prefix' => 'TF' ) );
$terminal_key     = hash( 'sha256', 'terminal-voucher-checkout' );
$terminal_reserve = DoughBoss_Voucher::reserve( $terminal_voucher['code'], 25, 'online', $terminal_key, 300, 'customer@example.invalid' );
db_integration_assert( ! is_wp_error( $terminal_reserve ), 'terminal voucher fixture is reserved' );
$GLOBALS['db_square_http_mode'] = 'failed';
$terminal_payment = DoughBoss_Square::create_payment_intent(
	2100,
	'AUD',
	db_integration_square_metadata(
		'terminal-voucher-attempt',
		'terminal-voucher-checkout',
		'terminal-voucher-cart',
		array( 'voucher_code' => $terminal_voucher['code'], 'voucher_reservation_key' => $terminal_key )
	)
);
db_integration_same( 'failed', is_array( $terminal_payment ) ? $terminal_payment['status'] : '', 'provider FAILED becomes a durable terminal attempt' );
db_integration_same( true, DoughBoss_Voucher::release_payment_reservation( $terminal_voucher['code'], $terminal_key, (int) $terminal_payment['attempt_id'] ), 'only the matching terminal attempt releases payment-pending voucher ownership' );

// A signed webhook must also release a voucher when an asynchronous payment
// moves from processing to conclusive failure after the browser has gone away.
$webhook_voucher = DoughBoss_Voucher::issue( array( 'type' => 'amount', 'value' => 3, 'scope' => 'online', 'location_id' => 1, 'prefix' => 'WH' ) );
$webhook_key     = hash( 'sha256', 'webhook-terminal-voucher-checkout' );
$webhook_reserve = DoughBoss_Voucher::reserve( $webhook_voucher['code'], 25, 'online', $webhook_key, 300, 'webhook@example.invalid' );
db_integration_assert( ! is_wp_error( $webhook_reserve ), 'webhook terminal voucher fixture is reserved' );
$GLOBALS['db_square_http_mode'] = 'pending';
$webhook_payment = DoughBoss_Square::create_payment_intent(
	2200,
	'AUD',
	db_integration_square_metadata(
		'webhook-terminal-attempt',
		'webhook-terminal-voucher-checkout',
		'webhook-terminal-cart',
		array( 'voucher_code' => $webhook_voucher['code'], 'voucher_reservation_key' => $webhook_key )
	)
);
db_integration_same( 'processing', is_array( $webhook_payment ) ? $webhook_payment['status'] : '', 'voucher payment begins in a durable processing state' );
$webhook_posts = $GLOBALS['db_square_post_count'];
$GLOBALS['db_square_http_mode'] = 'failed';
$webhook_body = wp_json_encode(
	array(
		'event_id' => 'evt_synthetic_terminal_voucher',
		'type'     => 'payment.updated',
		'data'     => array( 'type' => 'payment', 'id' => $webhook_payment['id'] ),
	)
);
$webhook_signature = base64_encode( hash_hmac( 'sha256', DoughBoss_Settings::square_webhook_url() . $webhook_body, $settings['square_test_webhook_key'], true ) );
$webhook_request = new WP_REST_Request( 'POST', '/doughboss/v1/square-webhook' );
$webhook_request->set_body( $webhook_body );
$webhook_request->set_header( DoughBoss_Square::webhook_signature_header(), $webhook_signature );
$webhook_controller = new DoughBoss_REST_Controller( new DoughBoss_Cart() );
$webhook_result = $webhook_controller->square_webhook( $webhook_request );
$webhook_data = $webhook_result instanceof WP_REST_Response ? $webhook_result->get_data() : array();
db_integration_same( true, ! empty( $webhook_data['received'] ), 'signed Square terminal webhook is acknowledged' );
db_integration_same( $webhook_posts, $GLOBALS['db_square_post_count'], 'webhook reconciliation performs no additional payment POST' );
$webhook_reuse = DoughBoss_Voucher::reserve( $webhook_voucher['code'], 25, 'online', hash( 'sha256', 'webhook-retry-owner' ), 300, 'retry@example.invalid' );
db_integration_assert( ! is_wp_error( $webhook_reuse ), 'terminal Square webhook releases the exact voucher for a later safe retry' );

$retry_voucher = DoughBoss_Voucher::issue( array( 'type' => 'amount', 'value' => 3, 'scope' => 'online', 'location_id' => 1, 'prefix' => 'WR' ) );
$retry_key     = hash( 'sha256', 'webhook-storage-retry-checkout' );
$retry_reserve = DoughBoss_Voucher::reserve( $retry_voucher['code'], 25, 'online', $retry_key, 300, 'storage@example.invalid' );
db_integration_assert( ! is_wp_error( $retry_reserve ), 'webhook storage-retry voucher fixture is reserved' );
$GLOBALS['db_square_http_mode'] = 'pending';
$retry_payment = DoughBoss_Square::create_payment_intent(
	2250,
	'AUD',
	db_integration_square_metadata(
		'webhook-storage-retry-attempt',
		'webhook-storage-retry-checkout',
		'webhook-storage-retry-cart',
		array( 'voucher_code' => $retry_voucher['code'], 'voucher_reservation_key' => $retry_key )
	)
);
$GLOBALS['db_square_http_mode'] = 'failed';
$retry_body = wp_json_encode(
	array(
		'event_id' => 'evt_synthetic_voucher_storage_retry',
		'type'     => 'payment.updated',
		'data'     => array( 'type' => 'payment', 'id' => $retry_payment['id'] ),
	)
);
$retry_signature = base64_encode( hash_hmac( 'sha256', DoughBoss_Settings::square_webhook_url() . $retry_body, $settings['square_test_webhook_key'], true ) );
$retry_request = new WP_REST_Request( 'POST', '/doughboss/v1/square-webhook' );
$retry_request->set_body( $retry_body );
$retry_request->set_header( DoughBoss_Square::webhook_signature_header(), $retry_signature );
$GLOBALS['db_fail_voucher_release_once'] = true;
$previous_suppress_errors = $wpdb->suppress_errors( true );
add_filter( 'query', 'db_integration_fail_voucher_release' );
$retry_failure = $webhook_controller->square_webhook( $retry_request );
remove_filter( 'query', 'db_integration_fail_voucher_release' );
$wpdb->suppress_errors( $previous_suppress_errors );
db_integration_same( 'doughboss_square_webhook_retry', is_wp_error( $retry_failure ) ? $retry_failure->get_error_code() : '', 'voucher release storage failure keeps the signed webhook retryable' );
$retry_event_key = hash( 'sha256', 'square|evt_synthetic_voucher_storage_retry' );
db_integration_same( 'retry', DoughBoss_Payment_Attempts::event_outcome( $retry_event_key ), 'failed voucher release is not permanently acknowledged' );
$retry_row = DoughBoss_Voucher::find_by_code( $retry_voucher['code'] );
$retry_meta = json_decode( (string) $retry_row->meta, true );
db_integration_same( true, ! empty( $retry_meta[ DoughBoss_Voucher::RESERVATION_META_KEY ]['payment_pending'] ), 'failed storage release retains the exact payment-pending owner' );
$previous_suppress_errors = $wpdb->suppress_errors( true );
$retry_success = $webhook_controller->square_webhook( $retry_request );
$wpdb->suppress_errors( $previous_suppress_errors );
$retry_success_data = $retry_success instanceof WP_REST_Response ? $retry_success->get_data() : array();
db_integration_same( true, ! empty( $retry_success_data['received'] ), 'Square webhook reclaims and completes a retry event after storage recovers' );
db_integration_same( 'processed', DoughBoss_Payment_Attempts::event_outcome( $retry_event_key ), 'recovered voucher webhook is durably processed' );
$retry_reuse = DoughBoss_Voucher::reserve( $retry_voucher['code'], 25, 'online', hash( 'sha256', 'webhook-storage-recovered-owner' ), 300, 'recovered@example.invalid' );
db_integration_assert( ! is_wp_error( $retry_reuse ), 'recovered webhook release makes the terminal voucher reusable' );
db_integration_same( $webhook_posts + 1, $GLOBALS['db_square_post_count'], 'two terminal webhook deliveries add no provider payment POSTs' );

// Exercise the real controller boundary: immutable contact binding, one paid
// order, durable replay and fail-closed currency drift. Provider HTTP remains
// intercepted and local mail is preempted.
$GLOBALS['db_square_http_mode'] = 'success';
$_COOKIE[ DoughBoss_Cart::COOKIE ] = 'IntegrationRestCartToken1234567890';
$rest_cart = new DoughBoss_Cart();
$rest_cart->clear();
$rest_cart->add(
	array(
		'type'       => 'menu',
		'item_id'    => 9001,
		'name'       => 'Synthetic REST Manoush',
		'unit_price' => 12.00,
		'quantity'   => 1,
	)
);
$rest_controller = new DoughBoss_REST_Controller( $rest_cart );
$payment_params = array(
	'payment_attempt_key' => 'rest-payment-attempt-0001',
	'customer_name'       => 'REST Customer',
	'customer_email'      => 'rest.customer@example.invalid',
	'customer_phone'      => '0400000001',
	'address'             => '',
	'notes'               => 'Original synthetic note',
	'order_type'          => 'pickup',
	'location_id'         => 1,
	'source_id'           => 'cnon:rest-integration-card',
	'verification_token'  => 'verify:rest-integration',
);
$posts_before_rest = $GLOBALS['db_square_post_count'];
$rest_payment_response = $rest_controller->create_payment_intent( db_integration_rest_request( '/doughboss/v1/payment-intent', $payment_params ) );
$rest_payment = $rest_payment_response instanceof WP_REST_Response ? $rest_payment_response->get_data() : $rest_payment_response;
$rest_payment_id = is_array( $rest_payment ) && isset( $rest_payment['payment_intent'] ) ? (string) $rest_payment['payment_intent'] : '';
db_integration_assert( '' !== $rest_payment_id && 'square' === $rest_payment['gateway'], 'REST payment preparation succeeds with immutable snapshot' );
db_integration_same( $posts_before_rest + 1, $GLOBALS['db_square_post_count'], 'REST preparation performs exactly one provider POST' );

$checkout_params = array(
	'idempotency_key'  => 'rest-checkout-idempotency-0001',
	'customer_name'    => $payment_params['customer_name'],
	'customer_email'   => $payment_params['customer_email'],
	'customer_phone'   => $payment_params['customer_phone'],
	'address'          => '',
	'notes'            => $payment_params['notes'],
	'order_type'       => 'pickup',
	'location_id'      => 1,
	'payment_intent_id'=> $rest_payment_id,
);
$changed_checkout = $checkout_params;
$changed_checkout['customer_email'] = 'changed@example.invalid';
$changed_result = $rest_controller->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $changed_checkout ) );
db_integration_same( 'doughboss_pay_attempt_changed', is_wp_error( $changed_result ) ? $changed_result->get_error_code() : '', 'REST checkout rejects changed contact facts after payment starts' );
db_integration_same( 0, (int) DoughBoss_Order::find_id_by_payment_intent( $rest_payment_id ), 'changed REST details cannot create a paid order' );

add_filter( 'pre_wp_mail', 'db_integration_block_mail' );
$checkout_result = $rest_controller->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $checkout_params ) );
remove_filter( 'pre_wp_mail', 'db_integration_block_mail' );
$checkout_data = $checkout_result instanceof WP_REST_Response ? $checkout_result->get_data() : array();
db_integration_same( true, ! empty( $checkout_data['success'] ), 'REST checkout creates the paid order from the stored snapshot' );
$rest_order_id = DoughBoss_Order::find_id_by_payment_intent( $rest_payment_id );
db_integration_assert( $rest_order_id > 0, 'REST paid order is durably linked to its Square payment' );
$rest_attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $rest_payment_id );
db_integration_same( 'order_committed', is_array( $rest_attempt ) ? $rest_attempt['status'] : '', 'REST order commit releases the cart guard only after persistence' );

$rest_cart->add(
	array(
		'type'       => 'menu',
		'item_id'    => 9002,
		'name'       => 'Synthetic Currency Pie',
		'unit_price' => 13.00,
		'quantity'   => 1,
	)
);
$currency_payment_params = $payment_params;
$currency_payment_params['payment_attempt_key'] = 'rest-payment-attempt-0002';
$currency_payment_params['notes'] = 'Currency drift fixture';
$currency_payment_response = $rest_controller->create_payment_intent( db_integration_rest_request( '/doughboss/v1/payment-intent', $currency_payment_params ) );
$currency_payment = $currency_payment_response instanceof WP_REST_Response ? $currency_payment_response->get_data() : $currency_payment_response;
$currency_payment_id = is_array( $currency_payment ) && isset( $currency_payment['payment_intent'] ) ? (string) $currency_payment['payment_intent'] : '';
db_integration_assert( '' !== $currency_payment_id && 'square' === $currency_payment['gateway'], 'currency drift fixture records one AUD payment' );
$settings['currency_code'] = 'USD';
update_option( 'doughboss_settings', $settings, false );
$currency_checkout = $checkout_params;
$currency_checkout['idempotency_key'] = 'rest-checkout-idempotency-0002';
$currency_checkout['notes'] = $currency_payment_params['notes'];
$currency_checkout['payment_intent_id'] = $currency_payment_id;
$currency_result = $rest_controller->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $currency_checkout ) );
db_integration_same( 'doughboss_pay_currency_changed', is_wp_error( $currency_result ) ? $currency_result->get_error_code() : '', 'REST checkout rejects payment/order currency drift' );
db_integration_same( 0, (int) DoughBoss_Order::find_id_by_payment_intent( $currency_payment_id ), 'currency drift cannot create a differently denominated order' );
$settings['currency_code'] = 'AUD';
update_option( 'doughboss_settings', $settings, false );

// Pre-v2 attempts have no trustworthy cart guard, so v2 allocation drains them
// globally while preserving their provider reference for manual inspection.
$legacy = DoughBoss_Payment_Attempts::create_or_find(
	array(
		'attempt_key'     => hash( 'sha256', 'legacy-square-attempt' ),
		'checkout_key'    => hash( 'sha256', 'legacy-square-checkout' ),
		'provider'        => 'square',
		'purpose'         => 'order',
		'context'         => 'web',
		'location_id'     => 1,
		'amount_minor'    => 2200,
		'currency'        => 'AUD',
		'status'          => 'created',
		'safe_metadata'   => array( 'square_location_id' => DoughBoss_Settings::square_location_id() ),
	)
);
db_integration_assert( is_array( $legacy ), 'legacy Square fixture is stored without backfilled v2 binding' );
$posts_before_legacy_gate = $GLOBALS['db_square_post_count'];
$legacy_block = DoughBoss_Square::create_payment_intent( 2300, 'AUD', db_integration_square_metadata( 'post-legacy-attempt', 'post-legacy-checkout', 'post-legacy-cart' ) );
db_integration_same( 'doughboss_pay_legacy_pending', is_wp_error( $legacy_block ) ? $legacy_block->get_error_code() : '', 'unresolved legacy Square attempt blocks v2 allocation' );
db_integration_same( $posts_before_legacy_gate, $GLOBALS['db_square_post_count'], 'legacy drain gate prevents another provider POST' );
if ( is_array( $legacy ) ) {
	DoughBoss_Payment_Attempts::update( (int) $legacy['id'], array( 'status' => 'failed' ) );
}

// More than one page of already-linked legacy successes cannot hide a later
// unresolved row, and NULL legacy metadata is included in the drain.
$legacy_fixture_ok = true;
for ( $legacy_index = 0; $legacy_index < 101; ++$legacy_index ) {
	$legacy_reference = sprintf( 'sqpay_legacy_done_%03d', $legacy_index );
	$legacy_fixture_ok = $legacy_fixture_ok && 1 === $wpdb->insert(
		$wpdb->prefix . 'doughboss_payment_attempts',
		array(
			'attempt_key'        => hash( 'sha256', 'legacy-done-attempt-' . $legacy_index ),
			'provider'           => 'square',
			'provider_reference' => $legacy_reference,
			'checkout_key'       => hash( 'sha256', 'legacy-done-checkout-' . $legacy_index ),
			'status'             => 'succeeded',
			'safe_metadata_json' => '{}',
		)
	);
	$legacy_fixture_ok = $legacy_fixture_ok && 1 === $wpdb->insert(
		$wpdb->prefix . 'doughboss_orders',
		array(
			'order_number'      => sprintf( 'LEGACY-%03d', $legacy_index ),
			'payment_status'    => 'paid',
			'payment_method'    => 'square',
			'payment_intent_id' => $legacy_reference,
		)
	);
}
db_integration_assert( $legacy_fixture_ok, 'more than 100 fulfilled legacy Square attempts are stored for drain pagination coverage' );
$wpdb->insert(
	$wpdb->prefix . 'doughboss_payment_attempts',
	array(
		'attempt_key'        => hash( 'sha256', 'legacy-null-attempt' ),
		'provider'           => 'square',
		'provider_reference' => 'sqpay_legacy_unresolved_101',
		'checkout_key'       => hash( 'sha256', 'legacy-null-checkout' ),
		'status'             => 'processing',
		'safe_metadata_json' => null,
	)
);
$legacy_null_id = (int) $wpdb->insert_id;
$legacy_after_page = DoughBoss_Payment_Attempts::find_blocking_legacy_square();
db_integration_same( $legacy_null_id, is_array( $legacy_after_page ) ? (int) $legacy_after_page['id'] : 0, 'fulfilled legacy rows cannot hide a later unresolved row with NULL metadata' );

$GLOBALS['db_fail_legacy_once'] = true;
$previous_suppress_errors       = $wpdb->suppress_errors( true );
add_filter( 'query', 'db_integration_fail_legacy_select' );
$legacy_read_failure = DoughBoss_Payment_Attempts::find_blocking_legacy_square();
remove_filter( 'query', 'db_integration_fail_legacy_select' );
$wpdb->suppress_errors( $previous_suppress_errors );
db_integration_same( 'doughboss_pay_legacy_storage', is_wp_error( $legacy_read_failure ) ? $legacy_read_failure->get_error_code() : '', 'legacy drain query failure is distinguishable from an empty result' );
DoughBoss_Payment_Attempts::update( $legacy_null_id, array( 'status' => 'failed' ) );

// Stripe hosted Checkout lifecycle against real WordPress/MySQL. Every Stripe
// request and every mail is intercepted; WP_HTTP_BLOCK_EXTERNAL is the final
// guard if an unexpected request ever escapes these fixtures.
foreach ( array( 'doughboss_order_items', 'doughboss_order_events', 'doughboss_orders', 'doughboss_preorder_approvals', 'doughboss_payment_events', 'doughboss_payment_attempts', 'doughboss_checkout_snapshots' ) as $suffix ) {
	$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . $suffix ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
$settings['payments_enabled']         = 1;
$settings['payment_gateway']          = 'stripe';
$settings['stripe_mode']              = 'test';
$settings['stripe_test_pk']           = '';
$settings['stripe_test_sk']           = 'sk_test_localSyntheticIntegrationOnly000000';
$settings['stripe_test_whsec']        = 'whsec_localSyntheticIntegrationOnly000000';
$settings['currency_code']            = 'AUD';
$settings['pospal_order_push_enabled'] = 0;
update_option( 'doughboss_settings', $settings, false );
$wpdb->update( $wpdb->prefix . 'doughboss_locations', array( 'online_payment_enabled' => 1, 'pickup_enabled' => 1, 'is_active' => 1 ), array( 'id' => 1 ) );
add_filter( 'pre_http_request', 'db_integration_stripe_http', 10, 3 );
$stripe_original_remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
$_SERVER['REMOTE_ADDR']      = '192.0.2.201';

// Direct session creation binds one durable attempt; replay retrieves the same
// provider session rather than dispatching a second POST.
$stripe_direct_meta = db_integration_stripe_metadata( 'direct-replay' );
$stripe_success_url = 'https://doughboss.local.test/order/#doughboss_stripe_return=1&session_id={CHECKOUT_SESSION_ID}';
$stripe_cancel_url  = 'https://doughboss.local.test/order/#doughboss_stripe_cancel=1';
$stripe_direct      = DoughBoss_Stripe::create_checkout_session( 2750, 'AUD', $stripe_direct_meta, $stripe_success_url, $stripe_cancel_url, 'stripe.direct@example.invalid' );
db_integration_assert( ! is_wp_error( $stripe_direct ), 'first Stripe hosted Checkout request succeeds against the intercepted provider' );
db_integration_same( 1, $GLOBALS['db_stripe_post_count'], 'first Stripe attempt performs exactly one provider POST' );
$stripe_direct_session = ! is_wp_error( $stripe_direct ) && isset( $stripe_direct['checkout_session'] ) ? (string) $stripe_direct['checkout_session'] : '';
$stripe_direct_attempt = DoughBoss_Payment_Attempts::find_by_checkout_key( $stripe_direct_meta['checkout_key'] );
db_integration_same( $stripe_direct_session, is_array( $stripe_direct_attempt ) ? (string) $stripe_direct_attempt['provider_reference'] : '', 'Stripe session is durably bound to its checkout attempt' );
db_integration_same( 'processing', is_array( $stripe_direct_attempt ) ? (string) $stripe_direct_attempt['status'] : '', 'new Stripe session is stored as processing' );
db_integration_same( 'unpaid', is_array( $stripe_direct_attempt ) ? (string) $stripe_direct_attempt['provider_status'] : '', 'new Stripe session records its initial provider status' );
$stripe_direct_stored_meta = is_array( $stripe_direct_attempt ) ? DoughBoss_Payment_Attempts::metadata( $stripe_direct_attempt ) : array();
db_integration_same( $stripe_direct_meta['checkout_key'], isset( $stripe_direct_stored_meta['checkout_key'] ) ? (string) $stripe_direct_stored_meta['checkout_key'] : '', 'Stripe attempt retains the server-owned checkout identity' );
$stripe_first_idempotency = isset( $GLOBALS['db_stripe_idempotency'][0] ) ? (string) $GLOBALS['db_stripe_idempotency'][0] : '';
db_integration_assert( 0 === strpos( $stripe_first_idempotency, 'doughboss-checkout-' ) && false === strpos( $stripe_first_idempotency, $stripe_direct_meta['checkout_key'] ), 'Stripe idempotency header is stable and does not expose the raw checkout key' );

$stripe_direct_replay = DoughBoss_Stripe::create_checkout_session( 2750, 'AUD', $stripe_direct_meta, $stripe_success_url, $stripe_cancel_url, 'stripe.direct@example.invalid' );
db_integration_same( $stripe_direct_session, ! is_wp_error( $stripe_direct_replay ) ? (string) $stripe_direct_replay['checkout_session'] : '', 'same Stripe checkout replays the original hosted Session' );
db_integration_same( 1, $GLOBALS['db_stripe_post_count'], 'same Stripe checkout never performs a second provider POST' );
db_integration_same( 1, $GLOBALS['db_stripe_session_gets'], 'same Stripe checkout reconciles through one provider GET' );
db_integration_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'doughboss_payment_attempts WHERE checkout_key = %s', $stripe_direct_meta['checkout_key'] ) ), 'same Stripe checkout owns exactly one durable attempt row' );

// A replay-safe transport failure releases only the unbound provisioning lease;
// the next call uses the same provider idempotency key and can bind normally.
$stripe_failure_meta                  = db_integration_stripe_metadata( 'network-retry' );
$GLOBALS['db_stripe_network_once']   = true;
$stripe_posts_before_failure          = $GLOBALS['db_stripe_post_count'];
$stripe_idempotency_before_failure    = count( $GLOBALS['db_stripe_idempotency'] );
$stripe_failure = DoughBoss_Stripe::create_checkout_session( 3100, 'AUD', $stripe_failure_meta, $stripe_success_url, $stripe_cancel_url, 'stripe.retry@example.invalid' );
db_integration_same( 'doughboss_pay_network', is_wp_error( $stripe_failure ) ? $stripe_failure->get_error_code() : '', 'Stripe transport failure remains a safe network error' );
$stripe_failed_attempt = DoughBoss_Payment_Attempts::find_by_checkout_key( $stripe_failure_meta['checkout_key'] );
db_integration_same( 'created', is_array( $stripe_failed_attempt ) ? (string) $stripe_failed_attempt['status'] : '', 'failed Stripe request releases the provisioning lease for idempotent retry' );
db_integration_same( '', is_array( $stripe_failed_attempt ) ? (string) $stripe_failed_attempt['provider_reference'] : '', 'failed Stripe request never binds a guessed provider reference' );
db_integration_same( 'doughboss_pay_network', is_array( $stripe_failed_attempt ) ? (string) $stripe_failed_attempt['last_error'] : '', 'failed Stripe attempt stores only the safe error identifier' );

$stripe_retry = DoughBoss_Stripe::create_checkout_session( 3100, 'AUD', $stripe_failure_meta, $stripe_success_url, $stripe_cancel_url, 'stripe.retry@example.invalid' );
db_integration_assert( ! is_wp_error( $stripe_retry ), 'released Stripe attempt succeeds on a later intercepted retry' );
db_integration_same( $stripe_posts_before_failure + 2, $GLOBALS['db_stripe_post_count'], 'network failure and retry perform exactly two idempotent provider POSTs' );
$stripe_failure_idempotency = isset( $GLOBALS['db_stripe_idempotency'][ $stripe_idempotency_before_failure ] ) ? (string) $GLOBALS['db_stripe_idempotency'][ $stripe_idempotency_before_failure ] : '';
$stripe_retry_idempotency   = isset( $GLOBALS['db_stripe_idempotency'][ $stripe_idempotency_before_failure + 1 ] ) ? (string) $GLOBALS['db_stripe_idempotency'][ $stripe_idempotency_before_failure + 1 ] : '';
db_integration_same( $stripe_failure_idempotency, $stripe_retry_idempotency, 'Stripe network retry reuses the exact provider idempotency key' );
$stripe_retried_attempt = DoughBoss_Payment_Attempts::find_by_checkout_key( $stripe_failure_meta['checkout_key'] );
db_integration_same( isset( $stripe_retry['checkout_session'] ) ? (string) $stripe_retry['checkout_session'] : '', is_array( $stripe_retried_attempt ) ? (string) $stripe_retried_attempt['provider_reference'] : '', 'successful retry binds its one hosted Session' );

// Full REST preparation and synchronous return: amount, currency, metadata,
// durable order creation, mail preemption and lifecycle completion.
$_COOKIE[ DoughBoss_Cart::COOKIE ] = 'IntegrationStripeSyncCartToken123456';
$stripe_sync_cart = new DoughBoss_Cart();
$stripe_sync_cart->clear();
$stripe_sync_cart->add(
	array(
		'type'       => 'menu',
		'item_id'    => 9101,
		'name'       => 'Synthetic Stripe Sync Manoush',
		'unit_price' => 12.00,
		'quantity'   => 1,
	)
);
$stripe_sync_controller = new DoughBoss_REST_Controller( $stripe_sync_cart );
$stripe_sync_payment_params = array(
	'payment_attempt_key' => 'stripe-sync-payment-attempt-0001',
	'customer_name'       => 'Stripe Sync Customer',
	'customer_email'      => 'stripe.sync@example.invalid',
	'customer_phone'      => '0400000011',
	'address'             => '',
	'notes'               => 'Original Stripe sync note',
	'order_type'          => 'pickup',
	'location_id'         => 1,
	'return_url'          => 'https://doughboss.local.test/order/?page_id=42&ignored=1',
);
$stripe_sync_prepared = $stripe_sync_controller->create_payment_intent( db_integration_rest_request( '/doughboss/v1/payment-intent', $stripe_sync_payment_params ) );
$stripe_sync_data     = $stripe_sync_prepared instanceof WP_REST_Response ? $stripe_sync_prepared->get_data() : array();
$stripe_sync_session  = isset( $stripe_sync_data['checkout_session'] ) ? (string) $stripe_sync_data['checkout_session'] : '';
$stripe_sync_pi       = isset( $GLOBALS['db_stripe_sessions'][ $stripe_sync_session ]['payment_intent'] ) ? (string) $GLOBALS['db_stripe_sessions'][ $stripe_sync_session ]['payment_intent'] : '';
db_integration_assert( $stripe_sync_prepared instanceof WP_REST_Response && '' !== $stripe_sync_session, 'REST Stripe preparation succeeds before the synchronous-return fixture continues' );
db_integration_same( 'stripe', isset( $stripe_sync_data['gateway'] ) ? (string) $stripe_sync_data['gateway'] : '', 'REST Stripe preparation returns the hosted gateway contract' );
db_integration_same( 'https://checkout.stripe.com', substr( isset( $stripe_sync_data['checkout_url'] ) ? (string) $stripe_sync_data['checkout_url'] : '', 0, 27 ), 'REST Stripe preparation returns only the trusted hosted Checkout origin' );
$stripe_sync_attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $stripe_sync_session );
$stripe_sync_snapshot = is_array( $stripe_sync_attempt ) ? DoughBoss_Checkout_Snapshots::find( (string) $stripe_sync_attempt['checkout_key'] ) : null;
db_integration_assert( is_array( $stripe_sync_snapshot ) && ! empty( $stripe_sync_snapshot['payload']['order'] ) && ! empty( $stripe_sync_snapshot['payload']['lines'] ), 'REST Stripe preparation persists its immutable recovery snapshot before redirect' );

$stripe_sync_checkout_params = array(
	'idempotency_key'     => 'stripe-sync-checkout-idempotency-0001',
	'payment_attempt_key' => $stripe_sync_payment_params['payment_attempt_key'],
	'customer_name'       => $stripe_sync_payment_params['customer_name'],
	'customer_email'      => $stripe_sync_payment_params['customer_email'],
	'customer_phone'      => $stripe_sync_payment_params['customer_phone'],
	'address'             => '',
	'notes'               => $stripe_sync_payment_params['notes'],
	'order_type'          => 'pickup',
	'location_id'         => 1,
	'payment_intent_id'   => $stripe_sync_session,
);
$GLOBALS['db_catering_mail'] = array();
add_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10, 2 );
$stripe_sync_checkout = $stripe_sync_controller->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $stripe_sync_checkout_params ) );
$stripe_sync_checkout_data = $stripe_sync_checkout instanceof WP_REST_Response ? $stripe_sync_checkout->get_data() : array();
db_integration_same( true, ! empty( $stripe_sync_checkout_data['success'] ), 'server-verified Stripe return creates a paid order' );
$stripe_sync_order_id = DoughBoss_Order::find_id_by_payment_intent( $stripe_sync_pi );
$stripe_sync_order    = $stripe_sync_order_id ? DoughBoss_Order::get( $stripe_sync_order_id ) : null;
db_integration_assert( $stripe_sync_order && 'paid' === (string) $stripe_sync_order->payment_status && 'stripe' === (string) $stripe_sync_order->payment_method, 'Stripe order is durably linked to its canonical paid PaymentIntent' );
db_integration_assert( count( $GLOBALS['db_catering_mail'] ) > 0, 'Stripe order mail is captured before transport' );

// Regression B is checked before any replay helper can repair the state: the
// initial synchronous success itself must close the recovery bookkeeping.
$stripe_sync_attempt_after  = DoughBoss_Payment_Attempts::find( (int) $stripe_sync_attempt['id'] );
$stripe_sync_snapshot_after = DoughBoss_Checkout_Snapshots::find( (string) $stripe_sync_attempt['checkout_key'] );
db_integration_same( 'completed', is_array( $stripe_sync_snapshot_after ) ? (string) $stripe_sync_snapshot_after['status'] : '', 'synchronous Stripe completion closes its recovery snapshot' );
db_integration_assert(
	is_array( $stripe_sync_attempt_after )
	&& 'succeeded' === (string) $stripe_sync_attempt_after['status']
	&& ! empty( $stripe_sync_attempt_after['verified_at'] ),
	'synchronous Stripe completion advances and verifies its durable attempt'
);
db_integration_same( $stripe_sync_order ? (string) $stripe_sync_order->order_number : '', is_array( $stripe_sync_attempt_after ) ? (string) $stripe_sync_attempt_after['local_reference'] : '', 'synchronous Stripe completion binds its durable attempt to the created order' );

$stripe_sync_mail_count    = count( $GLOBALS['db_catering_mail'] );
$stripe_sync_posts_before  = $GLOBALS['db_stripe_post_count'];
$stripe_sync_cached_result = $stripe_sync_controller->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $stripe_sync_checkout_params ) );
$stripe_sync_cached_data   = $stripe_sync_cached_result instanceof WP_REST_Response ? $stripe_sync_cached_result->get_data() : array();
db_integration_assert( ! empty( $stripe_sync_cached_data['success'] ) && ! empty( $stripe_sync_cached_data['replayed'] ), 'cached-key Stripe browser duplicate is reverified and replays the paid order' );
db_integration_same( $stripe_sync_order ? (string) $stripe_sync_order->order_number : '', isset( $stripe_sync_cached_data['order_number'] ) ? (string) $stripe_sync_cached_data['order_number'] : '', 'cached-key Stripe browser duplicate returns the original order' );
db_integration_same( $stripe_sync_mail_count, count( $GLOBALS['db_catering_mail'] ), 'cached-key Stripe browser duplicate sends no extra confirmation' );

$stripe_sync_new_key_params = $stripe_sync_checkout_params;
$stripe_sync_new_key_params['idempotency_key'] = 'stripe-sync-checkout-idempotency-0002';
$stripe_sync_browser_result = $stripe_sync_controller->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $stripe_sync_new_key_params ) );
$stripe_sync_browser_data   = $stripe_sync_browser_result instanceof WP_REST_Response ? $stripe_sync_browser_result->get_data() : array();
db_integration_assert( ! empty( $stripe_sync_browser_data['success'] ) && ! empty( $stripe_sync_browser_data['replayed'] ), 'new-key Stripe browser duplicate replays the paid order' );
db_integration_same( $stripe_sync_order ? (string) $stripe_sync_order->order_number : '', isset( $stripe_sync_browser_data['order_number'] ) ? (string) $stripe_sync_browser_data['order_number'] : '', 'new-key Stripe browser duplicate returns the original order' );
db_integration_same( $stripe_sync_mail_count, count( $GLOBALS['db_catering_mail'] ), 'new-key Stripe browser duplicate sends no extra confirmation' );
db_integration_same( $stripe_sync_posts_before, $GLOBALS['db_stripe_post_count'], 'Stripe browser replays never create another hosted Session' );
$stripe_sync_changed_after_cache = $stripe_sync_checkout_params;
$stripe_sync_changed_after_cache['notes'] = 'Changed after a successful cached replay';
$stripe_sync_changed_result = $stripe_sync_controller->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $stripe_sync_changed_after_cache ) );
db_integration_same( 'doughboss_pay_attempt_changed', is_wp_error( $stripe_sync_changed_result ) ? $stripe_sync_changed_result->get_error_code() : '', 'successful cached Stripe checkout cannot bypass later snapshot contact validation' );
db_integration_same( 409, db_integration_error_status( $stripe_sync_changed_result ), 'post-cache Stripe contact mismatch remains an HTTP 409 conflict' );
db_integration_same( $stripe_sync_order_id, DoughBoss_Order::find_id_by_payment_intent( $stripe_sync_pi ), 'post-cache Stripe contact mismatch cannot create another order' );
db_integration_same( $stripe_sync_mail_count, count( $GLOBALS['db_catering_mail'] ), 'post-cache Stripe contact mismatch sends no extra confirmation' );
remove_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10 );

// Provider money or currency drift must fail before an order row is created.
$_SERVER['REMOTE_ADDR'] = '192.0.2.202';
$_COOKIE[ DoughBoss_Cart::COOKIE ] = 'IntegrationStripeMismatchCart123456';
$stripe_mismatch_cart = new DoughBoss_Cart();
$stripe_mismatch_cart->clear();
$stripe_mismatch_cart->add( array( 'type' => 'menu', 'item_id' => 9102, 'name' => 'Synthetic Stripe Drift Pie', 'unit_price' => 13.00, 'quantity' => 1 ) );
$stripe_mismatch_controller = new DoughBoss_REST_Controller( $stripe_mismatch_cart );
$stripe_mismatch_params = $stripe_sync_payment_params;
$stripe_mismatch_params['payment_attempt_key'] = 'stripe-mismatch-payment-attempt-0001';
$stripe_mismatch_params['customer_email']      = 'stripe.mismatch@example.invalid';
$stripe_mismatch_prepared = $stripe_mismatch_controller->create_payment_intent( db_integration_rest_request( '/doughboss/v1/payment-intent', $stripe_mismatch_params ) );
$stripe_mismatch_data     = $stripe_mismatch_prepared instanceof WP_REST_Response ? $stripe_mismatch_prepared->get_data() : array();
$stripe_mismatch_session  = isset( $stripe_mismatch_data['checkout_session'] ) ? (string) $stripe_mismatch_data['checkout_session'] : '';
$stripe_mismatch_pi       = isset( $GLOBALS['db_stripe_sessions'][ $stripe_mismatch_session ]['payment_intent'] ) ? (string) $GLOBALS['db_stripe_sessions'][ $stripe_mismatch_session ]['payment_intent'] : '';
db_integration_assert( $stripe_mismatch_prepared instanceof WP_REST_Response && '' !== $stripe_mismatch_session, 'REST Stripe preparation succeeds before the amount-drift fixture continues' );
if ( isset( $GLOBALS['db_stripe_intents'][ $stripe_mismatch_pi ]['amount'] ) ) {
	++$GLOBALS['db_stripe_intents'][ $stripe_mismatch_pi ]['amount'];
}
$stripe_mismatch_checkout = array(
	'idempotency_key'     => 'stripe-mismatch-checkout-idempotency-0001',
	'payment_attempt_key' => $stripe_mismatch_params['payment_attempt_key'],
	'customer_name'       => $stripe_mismatch_params['customer_name'],
	'customer_email'      => $stripe_mismatch_params['customer_email'],
	'customer_phone'      => $stripe_mismatch_params['customer_phone'],
	'address'             => '',
	'notes'               => $stripe_mismatch_params['notes'],
	'order_type'          => 'pickup',
	'location_id'         => 1,
	'payment_intent_id'   => $stripe_mismatch_session,
);
$stripe_mismatch_result = $stripe_mismatch_controller->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $stripe_mismatch_checkout ) );
db_integration_same( 'doughboss_pay_unverified', is_wp_error( $stripe_mismatch_result ) ? $stripe_mismatch_result->get_error_code() : '', 'Stripe amount drift is rejected by the real checkout boundary' );
db_integration_same( 0, (int) DoughBoss_Order::find_id_by_payment_intent( $stripe_mismatch_pi ), 'Stripe amount drift cannot create a paid order' );

// Regression A: the contact facts saved before redirect are immutable payment
// facts; a changed return request must not silently replace them on the order.
$_SERVER['REMOTE_ADDR'] = '192.0.2.203';
$_COOKIE[ DoughBoss_Cart::COOKIE ] = 'IntegrationStripeContactCart1234567';
$stripe_contact_cart = new DoughBoss_Cart();
$stripe_contact_cart->clear();
$stripe_contact_cart->add( array( 'type' => 'menu', 'item_id' => 9103, 'name' => 'Synthetic Stripe Contact Pie', 'unit_price' => 14.00, 'quantity' => 1 ) );
$stripe_contact_controller = new DoughBoss_REST_Controller( $stripe_contact_cart );
$stripe_contact_params = $stripe_sync_payment_params;
$stripe_contact_params['payment_attempt_key'] = 'stripe-contact-payment-attempt-0001';
$stripe_contact_params['customer_email']      = 'stripe.original@example.invalid';
$stripe_contact_prepared = $stripe_contact_controller->create_payment_intent( db_integration_rest_request( '/doughboss/v1/payment-intent', $stripe_contact_params ) );
$stripe_contact_data     = $stripe_contact_prepared instanceof WP_REST_Response ? $stripe_contact_prepared->get_data() : array();
$stripe_contact_session  = isset( $stripe_contact_data['checkout_session'] ) ? (string) $stripe_contact_data['checkout_session'] : '';
$stripe_contact_pi       = isset( $GLOBALS['db_stripe_sessions'][ $stripe_contact_session ]['payment_intent'] ) ? (string) $GLOBALS['db_stripe_sessions'][ $stripe_contact_session ]['payment_intent'] : '';
db_integration_assert( $stripe_contact_prepared instanceof WP_REST_Response && '' !== $stripe_contact_session, 'REST Stripe preparation succeeds before the contact-drift fixture continues' );
$stripe_changed_contact = array(
	'idempotency_key'     => 'stripe-contact-checkout-idempotency-0001',
	'payment_attempt_key' => $stripe_contact_params['payment_attempt_key'],
	'customer_name'       => $stripe_contact_params['customer_name'],
	'customer_email'      => 'stripe.changed@example.invalid',
	'customer_phone'      => $stripe_contact_params['customer_phone'],
	'address'             => '',
	'notes'               => $stripe_contact_params['notes'],
	'order_type'          => 'pickup',
	'location_id'         => 1,
	'payment_intent_id'   => $stripe_contact_session,
);
$GLOBALS['db_catering_mail'] = array();
add_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10, 2 );
$stripe_changed_result = $stripe_contact_controller->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $stripe_changed_contact ) );
db_integration_same( 'doughboss_pay_attempt_changed', is_wp_error( $stripe_changed_result ) ? $stripe_changed_result->get_error_code() : '', 'Stripe checkout rejects contact facts changed after hosted payment starts' );
db_integration_same( 409, db_integration_error_status( $stripe_changed_result ), 'Stripe email mismatch returns HTTP 409' );
db_integration_same( 0, (int) DoughBoss_Order::find_id_by_payment_intent( $stripe_contact_pi ), 'changed Stripe contact facts cannot create a paid order' );
$stripe_contact_variants = array(
	'customer_name'  => 'Different Stripe Customer',
	'customer_phone' => '0499999999',
	'address'        => 'Changed pickup contact address',
	'notes'          => 'Changed Stripe order note',
);
$stripe_contact_variant_number = 2;
foreach ( $stripe_contact_variants as $stripe_contact_field => $stripe_contact_value ) {
	$stripe_contact_variant = $stripe_changed_contact;
	$stripe_contact_variant['idempotency_key'] = 'stripe-contact-checkout-idempotency-000' . $stripe_contact_variant_number++;
	$stripe_contact_variant['customer_email']  = $stripe_contact_params['customer_email'];
	$stripe_contact_variant[ $stripe_contact_field ] = $stripe_contact_value;
	$stripe_contact_variant_result = $stripe_contact_controller->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $stripe_contact_variant ) );
	db_integration_same( 'doughboss_pay_attempt_changed', is_wp_error( $stripe_contact_variant_result ) ? $stripe_contact_variant_result->get_error_code() : '', 'Stripe checkout rejects changed ' . $stripe_contact_field . ' after hosted payment starts' );
	db_integration_same( 409, db_integration_error_status( $stripe_contact_variant_result ), 'Stripe ' . $stripe_contact_field . ' mismatch returns HTTP 409' );
	db_integration_same( 0, (int) DoughBoss_Order::find_id_by_payment_intent( $stripe_contact_pi ), 'Stripe ' . $stripe_contact_field . ' mismatch cannot create a paid order' );
}
db_integration_same( 0, count( $GLOBALS['db_catering_mail'] ), 'all Stripe contact mismatches fail before confirmation mail' );
remove_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10 );

// A paid return without its exact immutable snapshot must stop before order or
// notification creation. A self-consistent payload hash cannot legitimise a
// snapshot whose embedded order identity is bound to another checkout.
$_SERVER['REMOTE_ADDR'] = '192.0.2.205';
$stripe_missing_fixture = db_integration_prepare_stripe_rest_fixture( 'snapshot-missing', 9105, 16.00 );
$stripe_missing_key     = is_array( $stripe_missing_fixture['attempt'] ) ? (string) $stripe_missing_fixture['attempt']['checkout_key'] : '';
$stripe_missing_deleted = $wpdb->delete( $wpdb->prefix . 'doughboss_checkout_snapshots', array( 'checkout_key' => $stripe_missing_key ), array( '%s' ) );
db_integration_same( 1, (int) $stripe_missing_deleted, 'missing Stripe snapshot fixture removes exactly its own row' );
$stripe_missing_checkout = db_integration_stripe_checkout_fixture_params( $stripe_missing_fixture, 'stripe-missing-checkout-idempotency-0001' );
$GLOBALS['db_catering_mail'] = array();
add_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10, 2 );
$stripe_missing_result = $stripe_missing_fixture['controller']->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $stripe_missing_checkout ) );
db_integration_same( 'doughboss_pay_snapshot_missing', is_wp_error( $stripe_missing_result ) ? $stripe_missing_result->get_error_code() : '', 'Stripe checkout fails closed when its immutable snapshot is missing' );
db_integration_same( 409, db_integration_error_status( $stripe_missing_result ), 'missing Stripe snapshot returns HTTP 409' );
db_integration_same( 0, (int) DoughBoss_Order::find_id_by_payment_intent( $stripe_missing_fixture['pi'] ), 'missing Stripe snapshot cannot create an order' );
db_integration_same( 0, count( $GLOBALS['db_catering_mail'] ), 'missing Stripe snapshot cannot send confirmation mail' );
remove_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10 );

$_SERVER['REMOTE_ADDR'] = '192.0.2.206';
$stripe_misbound_fixture = db_integration_prepare_stripe_rest_fixture( 'snapshot-misbound', 9106, 17.00 );
$stripe_misbound_key     = is_array( $stripe_misbound_fixture['attempt'] ) ? (string) $stripe_misbound_fixture['attempt']['checkout_key'] : '';
$stripe_misbound_payload = is_array( $stripe_misbound_fixture['snapshot'] ) ? $stripe_misbound_fixture['snapshot']['payload'] : array();
if ( isset( $stripe_misbound_payload['order'] ) && is_array( $stripe_misbound_payload['order'] ) ) {
	$stripe_misbound_payload['order']['checkout_key'] = hash( 'sha256', 'another-stripe-checkout-owner' );
}
$stripe_misbound_json = wp_json_encode( $stripe_misbound_payload );
$stripe_misbound_updated = $wpdb->update(
	$wpdb->prefix . 'doughboss_checkout_snapshots',
	array( 'payload_json' => $stripe_misbound_json, 'payload_hash' => hash( 'sha256', $stripe_misbound_json ) ),
	array( 'checkout_key' => $stripe_misbound_key ),
	array( '%s', '%s' ),
	array( '%s' )
);
db_integration_same( 1, (int) $stripe_misbound_updated, 'misbound Stripe snapshot fixture rewrites exactly its own synthetic row' );
$stripe_misbound_checkout = db_integration_stripe_checkout_fixture_params( $stripe_misbound_fixture, 'stripe-misbound-checkout-idempotency-0001' );
$GLOBALS['db_catering_mail'] = array();
add_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10, 2 );
$stripe_misbound_result = $stripe_misbound_fixture['controller']->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $stripe_misbound_checkout ) );
db_integration_same( 'doughboss_pay_snapshot_missing', is_wp_error( $stripe_misbound_result ) ? $stripe_misbound_result->get_error_code() : '', 'Stripe checkout fails closed when the snapshot embeds another checkout identity' );
db_integration_same( 409, db_integration_error_status( $stripe_misbound_result ), 'misbound Stripe snapshot returns HTTP 409' );
db_integration_same( 0, (int) DoughBoss_Order::find_id_by_payment_intent( $stripe_misbound_fixture['pi'] ), 'misbound Stripe snapshot cannot create an order' );
db_integration_same( 0, count( $GLOBALS['db_catering_mail'] ), 'misbound Stripe snapshot cannot send confirmation mail' );
remove_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10 );

// Webhook-first recovery creates the exact snapshotted order once. Duplicate
// delivery and the later browser return both replay that one durable winner.
$_SERVER['REMOTE_ADDR'] = '192.0.2.204';
$_COOKIE[ DoughBoss_Cart::COOKIE ] = 'IntegrationStripeWebhookCart1234567';
$stripe_webhook_cart = new DoughBoss_Cart();
$stripe_webhook_cart->clear();
$stripe_webhook_cart->add( array( 'type' => 'menu', 'item_id' => 9104, 'name' => 'Synthetic Stripe Webhook Pie', 'unit_price' => 15.00, 'quantity' => 1 ) );
$stripe_webhook_controller = new DoughBoss_REST_Controller( $stripe_webhook_cart );
$stripe_webhook_params = $stripe_sync_payment_params;
$stripe_webhook_params['payment_attempt_key'] = 'stripe-webhook-payment-attempt-0001';
$stripe_webhook_params['customer_email']      = 'stripe.webhook@example.invalid';
$stripe_webhook_prepared = $stripe_webhook_controller->create_payment_intent( db_integration_rest_request( '/doughboss/v1/payment-intent', $stripe_webhook_params ) );
$stripe_webhook_prepared_data = $stripe_webhook_prepared instanceof WP_REST_Response ? $stripe_webhook_prepared->get_data() : array();
$stripe_webhook_session_id = isset( $stripe_webhook_prepared_data['checkout_session'] ) ? (string) $stripe_webhook_prepared_data['checkout_session'] : '';
$stripe_webhook_session    = isset( $GLOBALS['db_stripe_sessions'][ $stripe_webhook_session_id ] ) ? $GLOBALS['db_stripe_sessions'][ $stripe_webhook_session_id ] : array();
$stripe_webhook_pi         = isset( $stripe_webhook_session['payment_intent'] ) ? (string) $stripe_webhook_session['payment_intent'] : '';
db_integration_assert( $stripe_webhook_prepared instanceof WP_REST_Response && '' !== $stripe_webhook_session_id, 'REST Stripe preparation succeeds before the webhook-first fixture continues' );
$stripe_webhook_session['payment_status'] = 'paid';
$stripe_webhook_event_id = 'evt_STRIPEWEBHOOKINTEGRATION0001';
$stripe_webhook_body = wp_json_encode(
	array(
		'id'   => $stripe_webhook_event_id,
		'type' => 'checkout.session.completed',
		'data' => array( 'object' => $stripe_webhook_session ),
	)
);
$stripe_webhook_timestamp = time();
$stripe_webhook_signature = hash_hmac( 'sha256', $stripe_webhook_timestamp . '.' . $stripe_webhook_body, $settings['stripe_test_whsec'] );
$stripe_webhook_request = new WP_REST_Request( 'POST', '/doughboss/v1/stripe-webhook' );
$stripe_webhook_request->set_body( $stripe_webhook_body );
$stripe_webhook_request->set_header( 'Stripe-Signature', 't=' . $stripe_webhook_timestamp . ',v1=' . $stripe_webhook_signature );
$GLOBALS['db_catering_mail'] = array();
add_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10, 2 );
$stripe_webhook_result = $stripe_webhook_controller->stripe_webhook( $stripe_webhook_request );
$stripe_webhook_result_data = $stripe_webhook_result instanceof WP_REST_Response ? $stripe_webhook_result->get_data() : array();
db_integration_same( true, ! empty( $stripe_webhook_result_data['processed'] ), 'signed Stripe webhook recovers the paid order before browser return' );
$stripe_webhook_order_id = DoughBoss_Order::find_id_by_payment_intent( $stripe_webhook_pi );
db_integration_assert( $stripe_webhook_order_id > 0, 'Stripe webhook recovery durably links one order to the PaymentIntent' );
$stripe_webhook_attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $stripe_webhook_session_id );
$stripe_webhook_snapshot = is_array( $stripe_webhook_attempt ) ? DoughBoss_Checkout_Snapshots::find( (string) $stripe_webhook_attempt['checkout_key'] ) : null;
db_integration_same( 'succeeded', is_array( $stripe_webhook_attempt ) ? (string) $stripe_webhook_attempt['status'] : '', 'Stripe webhook advances its durable payment attempt' );
db_integration_same( 'completed', is_array( $stripe_webhook_snapshot ) ? (string) $stripe_webhook_snapshot['status'] : '', 'Stripe webhook completes its recovery snapshot' );
$stripe_webhook_event_key = hash( 'sha256', 'stripe|' . $stripe_webhook_event_id );
db_integration_same( 'processed', DoughBoss_Payment_Attempts::event_outcome( $stripe_webhook_event_key ), 'Stripe webhook event is durably marked processed' );
$stripe_webhook_mail_count = count( $GLOBALS['db_catering_mail'] );
$previous_suppress_errors = $wpdb->suppress_errors( true );
$stripe_webhook_duplicate = $stripe_webhook_controller->stripe_webhook( $stripe_webhook_request );
$wpdb->suppress_errors( $previous_suppress_errors );
$stripe_webhook_duplicate_data = $stripe_webhook_duplicate instanceof WP_REST_Response ? $stripe_webhook_duplicate->get_data() : array();
db_integration_same( true, ! empty( $stripe_webhook_duplicate_data['duplicate'] ), 'duplicate Stripe webhook is acknowledged without reprocessing' );
db_integration_same( $stripe_webhook_order_id, DoughBoss_Order::find_id_by_payment_intent( $stripe_webhook_pi ), 'duplicate Stripe webhook cannot create a second paid order' );
db_integration_same( $stripe_webhook_mail_count, count( $GLOBALS['db_catering_mail'] ), 'duplicate Stripe webhook cannot send confirmation twice' );

$stripe_webhook_checkout = array(
	'idempotency_key'     => 'stripe-webhook-browser-return-0001',
	'payment_attempt_key' => $stripe_webhook_params['payment_attempt_key'],
	'customer_name'       => $stripe_webhook_params['customer_name'],
	'customer_email'      => $stripe_webhook_params['customer_email'],
	'customer_phone'      => $stripe_webhook_params['customer_phone'],
	'address'             => '',
	'notes'               => $stripe_webhook_params['notes'],
	'order_type'          => 'pickup',
	'location_id'         => 1,
	'payment_intent_id'   => $stripe_webhook_session_id,
);
$stripe_webhook_browser_result = $stripe_webhook_controller->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $stripe_webhook_checkout ) );
$stripe_webhook_browser_data = $stripe_webhook_browser_result instanceof WP_REST_Response ? $stripe_webhook_browser_result->get_data() : array();
db_integration_same( true, ! empty( $stripe_webhook_browser_data['success'] ) && ! empty( $stripe_webhook_browser_data['replayed'] ), 'browser return after Stripe webhook replays the recovered order' );
db_integration_same( $stripe_webhook_order_id, DoughBoss_Order::find_id_by_payment_intent( $stripe_webhook_pi ), 'webhook/browser race retains one durable paid order' );
db_integration_same( $stripe_webhook_mail_count, count( $GLOBALS['db_catering_mail'] ), 'browser replay after Stripe webhook sends no duplicate confirmation' );
remove_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10 );

// If one post-order bookkeeping write fails, the paid order remains durable and
// a single confirmation attempt is captured. The same Session/browser key
// repairs the snapshot and attempt without another order or notification.
$_SERVER['REMOTE_ADDR'] = '192.0.2.207';
$stripe_finalize_fixture = db_integration_prepare_stripe_rest_fixture( 'finalize-retry', 9107, 18.00 );
$stripe_finalize_checkout = db_integration_stripe_checkout_fixture_params( $stripe_finalize_fixture, 'stripe-finalize-checkout-idempotency-0001' );
$stripe_finalize_post_count = $GLOBALS['db_stripe_post_count'];
$GLOBALS['db_catering_mail'] = array();
add_filter( 'doughboss_orders_email', 'db_integration_no_orders_email' );
add_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10, 2 );
$GLOBALS['db_fail_snapshot_complete_once'] = true;
add_filter( 'query', 'db_integration_fail_snapshot_complete' );
$previous_suppress_errors = $wpdb->suppress_errors( true );
$stripe_finalize_first = $stripe_finalize_fixture['controller']->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $stripe_finalize_checkout ) );
$wpdb->suppress_errors( $previous_suppress_errors );
remove_filter( 'query', 'db_integration_fail_snapshot_complete' );
db_integration_same( false, (bool) $GLOBALS['db_fail_snapshot_complete_once'], 'Stripe browser finalization fixture consumes its one injected SQL failure' );
db_integration_same( 'doughboss_pay_finalize_pending', is_wp_error( $stripe_finalize_first ) ? $stripe_finalize_first->get_error_code() : '', 'Stripe checkout reports retryable pending finalization after snapshot completion storage fails' );
db_integration_same( 503, db_integration_error_status( $stripe_finalize_first ), 'pending Stripe finalization returns HTTP 503' );
$stripe_finalize_order_id = DoughBoss_Order::find_id_by_payment_intent( $stripe_finalize_fixture['pi'] );
db_integration_assert( $stripe_finalize_order_id > 0, 'pending Stripe finalization retains its durable paid order' );
db_integration_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'doughboss_orders WHERE payment_intent_id = %s', $stripe_finalize_fixture['pi'] ) ), 'pending Stripe finalization owns exactly one order' );
db_integration_same( 1, count( $GLOBALS['db_catering_mail'] ), 'pending Stripe finalization attempts customer confirmation exactly once' );
$stripe_finalize_pending_snapshot = DoughBoss_Checkout_Snapshots::find( (string) $stripe_finalize_fixture['attempt']['checkout_key'] );
db_integration_same( 'prepared', is_array( $stripe_finalize_pending_snapshot ) ? (string) $stripe_finalize_pending_snapshot['status'] : '', 'failed Stripe snapshot completion remains visibly pending' );

$stripe_finalize_retry = $stripe_finalize_fixture['controller']->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $stripe_finalize_checkout ) );
$stripe_finalize_retry_data = $stripe_finalize_retry instanceof WP_REST_Response ? $stripe_finalize_retry->get_data() : array();
db_integration_assert( ! empty( $stripe_finalize_retry_data['success'] ) && ! empty( $stripe_finalize_retry_data['replayed'] ), 'same Stripe Session and browser key heal incomplete finalization' );
db_integration_same( $stripe_finalize_order_id, DoughBoss_Order::find_id_by_payment_intent( $stripe_finalize_fixture['pi'] ), 'Stripe finalization retry retains the original order' );
db_integration_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'doughboss_orders WHERE payment_intent_id = %s', $stripe_finalize_fixture['pi'] ) ), 'Stripe finalization retry creates no duplicate order' );
db_integration_same( 1, count( $GLOBALS['db_catering_mail'] ), 'Stripe finalization retry sends no duplicate confirmation' );
db_integration_same( $stripe_finalize_post_count, $GLOBALS['db_stripe_post_count'], 'Stripe finalization failure and browser retry never create another hosted Session' );
$stripe_finalize_healed_snapshot = DoughBoss_Checkout_Snapshots::find( (string) $stripe_finalize_fixture['attempt']['checkout_key'] );
$stripe_finalize_healed_attempt  = DoughBoss_Payment_Attempts::find( (int) $stripe_finalize_fixture['attempt']['id'] );
$stripe_finalize_healed_order    = DoughBoss_Order::get( $stripe_finalize_order_id );
db_integration_assert( is_array( $stripe_finalize_healed_snapshot ) && 'completed' === (string) $stripe_finalize_healed_snapshot['status'] && (int) $stripe_finalize_healed_snapshot['order_id'] === $stripe_finalize_order_id, 'Stripe browser retry heals snapshot completion linkage' );
db_integration_assert( is_array( $stripe_finalize_healed_attempt ) && 'succeeded' === (string) $stripe_finalize_healed_attempt['status'] && ! empty( $stripe_finalize_healed_attempt['verified_at'] ), 'Stripe browser retry heals attempt verification state' );
db_integration_same( $stripe_finalize_healed_order ? (string) $stripe_finalize_healed_order->order_number : '', is_array( $stripe_finalize_healed_attempt ) ? (string) $stripe_finalize_healed_attempt['local_reference'] : '', 'Stripe browser retry heals attempt order linkage' );
remove_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10 );
remove_filter( 'doughboss_orders_email', 'db_integration_no_orders_email' );

// The second finalizer write can fail after snapshot completion succeeds. The
// same browser return must finish only the pending attempt linkage.
$_SERVER['REMOTE_ADDR'] = '192.0.2.208';
$stripe_attempt_finalize_fixture = db_integration_prepare_stripe_rest_fixture( 'attempt-finalize-retry', 9109, 20.00 );
$stripe_attempt_finalize_checkout = db_integration_stripe_checkout_fixture_params( $stripe_attempt_finalize_fixture, 'stripe-attempt-finalize-idempotency-0001' );
$stripe_attempt_finalize_post_count = $GLOBALS['db_stripe_post_count'];
$GLOBALS['db_catering_mail'] = array();
add_filter( 'doughboss_orders_email', 'db_integration_no_orders_email' );
add_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10, 2 );
$GLOBALS['db_fail_attempt_succeed_once'] = true;
add_filter( 'query', 'db_integration_fail_attempt_succeed' );
$previous_suppress_errors = $wpdb->suppress_errors( true );
$stripe_attempt_finalize_first = $stripe_attempt_finalize_fixture['controller']->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $stripe_attempt_finalize_checkout ) );
$wpdb->suppress_errors( $previous_suppress_errors );
remove_filter( 'query', 'db_integration_fail_attempt_succeed' );
db_integration_same( false, (bool) $GLOBALS['db_fail_attempt_succeed_once'], 'Stripe attempt-finalization fixture consumes its one injected SQL failure' );
db_integration_same( 'doughboss_pay_finalize_pending', is_wp_error( $stripe_attempt_finalize_first ) ? $stripe_attempt_finalize_first->get_error_code() : '', 'Stripe checkout reports pending finalization when the completed snapshot cannot advance its attempt' );
db_integration_same( 503, db_integration_error_status( $stripe_attempt_finalize_first ), 'pending Stripe attempt finalization returns HTTP 503' );
$stripe_attempt_finalize_order_id = DoughBoss_Order::find_id_by_payment_intent( $stripe_attempt_finalize_fixture['pi'] );
$stripe_attempt_finalize_order    = DoughBoss_Order::get( $stripe_attempt_finalize_order_id );
db_integration_assert( $stripe_attempt_finalize_order_id > 0, 'pending Stripe attempt finalization retains its durable paid order' );
db_integration_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'doughboss_orders WHERE payment_intent_id = %s', $stripe_attempt_finalize_fixture['pi'] ) ), 'pending Stripe attempt finalization owns exactly one order' );
db_integration_same( 1, count( $GLOBALS['db_catering_mail'] ), 'pending Stripe attempt finalization captures exactly one confirmation attempt' );
$stripe_attempt_finalize_snapshot = DoughBoss_Checkout_Snapshots::find( (string) $stripe_attempt_finalize_fixture['attempt']['checkout_key'] );
$stripe_attempt_finalize_pending  = DoughBoss_Payment_Attempts::find( (int) $stripe_attempt_finalize_fixture['attempt']['id'] );
db_integration_assert( is_array( $stripe_attempt_finalize_snapshot ) && 'completed' === (string) $stripe_attempt_finalize_snapshot['status'] && (int) $stripe_attempt_finalize_snapshot['order_id'] === $stripe_attempt_finalize_order_id, 'Stripe snapshot stays completed when the later attempt update fails' );
db_integration_assert( is_array( $stripe_attempt_finalize_pending ) && 'processing' === (string) $stripe_attempt_finalize_pending['status'] && empty( $stripe_attempt_finalize_pending['verified_at'] ) && empty( $stripe_attempt_finalize_pending['local_reference'] ), 'failed Stripe attempt update leaves only the attempt visibly pending' );

$stripe_attempt_finalize_retry = $stripe_attempt_finalize_fixture['controller']->checkout( db_integration_rest_request( '/doughboss/v1/checkout', $stripe_attempt_finalize_checkout ) );
$stripe_attempt_finalize_retry_data = $stripe_attempt_finalize_retry instanceof WP_REST_Response ? $stripe_attempt_finalize_retry->get_data() : array();
db_integration_assert( ! empty( $stripe_attempt_finalize_retry_data['success'] ) && ! empty( $stripe_attempt_finalize_retry_data['replayed'] ), 'same Stripe Session and browser key heal the pending attempt update' );
db_integration_same( $stripe_attempt_finalize_order_id, DoughBoss_Order::find_id_by_payment_intent( $stripe_attempt_finalize_fixture['pi'] ), 'Stripe attempt-finalization retry retains the original order' );
db_integration_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'doughboss_orders WHERE payment_intent_id = %s', $stripe_attempt_finalize_fixture['pi'] ) ), 'Stripe attempt-finalization retry creates no duplicate order' );
db_integration_same( 1, count( $GLOBALS['db_catering_mail'] ), 'Stripe attempt-finalization retry sends no duplicate confirmation' );
db_integration_same( $stripe_attempt_finalize_post_count, $GLOBALS['db_stripe_post_count'], 'Stripe attempt-finalization failure and retry never create another hosted Session' );
$stripe_attempt_finalize_healed_snapshot = DoughBoss_Checkout_Snapshots::find( (string) $stripe_attempt_finalize_fixture['attempt']['checkout_key'] );
$stripe_attempt_finalize_healed          = DoughBoss_Payment_Attempts::find( (int) $stripe_attempt_finalize_fixture['attempt']['id'] );
db_integration_assert( is_array( $stripe_attempt_finalize_healed_snapshot ) && 'completed' === (string) $stripe_attempt_finalize_healed_snapshot['status'] && (int) $stripe_attempt_finalize_healed_snapshot['order_id'] === $stripe_attempt_finalize_order_id && (string) $stripe_attempt_finalize_healed_snapshot['payload_hash'] === (string) $stripe_attempt_finalize_snapshot['payload_hash'], 'Stripe attempt-finalization retry leaves the completed snapshot binding unchanged' );
db_integration_assert( is_array( $stripe_attempt_finalize_healed ) && 'succeeded' === (string) $stripe_attempt_finalize_healed['status'] && ! empty( $stripe_attempt_finalize_healed['verified_at'] ), 'Stripe attempt-finalization retry heals verification state' );
db_integration_same( $stripe_attempt_finalize_order ? (string) $stripe_attempt_finalize_order->order_number : '', is_array( $stripe_attempt_finalize_healed ) ? (string) $stripe_attempt_finalize_healed['local_reference'] : '', 'Stripe attempt-finalization retry heals order linkage' );
remove_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10 );
remove_filter( 'doughboss_orders_email', 'db_integration_no_orders_email' );

// The same repair contract applies when a signed webhook created the order.
// Its retry ledger stays retryable until finalization is fully durable.
$_SERVER['REMOTE_ADDR'] = '192.0.2.209';
$stripe_webhook_retry_fixture = db_integration_prepare_stripe_rest_fixture( 'webhook-finalize-retry', 9108, 19.00 );
$stripe_webhook_retry_post_count = $GLOBALS['db_stripe_post_count'];
$stripe_webhook_retry_session = isset( $GLOBALS['db_stripe_sessions'][ $stripe_webhook_retry_fixture['session'] ] ) ? $GLOBALS['db_stripe_sessions'][ $stripe_webhook_retry_fixture['session'] ] : array();
$stripe_webhook_retry_session['payment_status'] = 'paid';
$stripe_webhook_retry_event_id = 'evt_STRIPEWEBHOOKFINALIZERETRY0001';
$stripe_webhook_retry_body = wp_json_encode(
	array(
		'id'   => $stripe_webhook_retry_event_id,
		'type' => 'checkout.session.completed',
		'data' => array( 'object' => $stripe_webhook_retry_session ),
	)
);
$stripe_webhook_retry_timestamp = time();
$stripe_webhook_retry_signature = hash_hmac( 'sha256', $stripe_webhook_retry_timestamp . '.' . $stripe_webhook_retry_body, $settings['stripe_test_whsec'] );
$stripe_webhook_retry_request = new WP_REST_Request( 'POST', '/doughboss/v1/stripe-webhook' );
$stripe_webhook_retry_request->set_body( $stripe_webhook_retry_body );
$stripe_webhook_retry_request->set_header( 'Stripe-Signature', 't=' . $stripe_webhook_retry_timestamp . ',v1=' . $stripe_webhook_retry_signature );
$GLOBALS['db_catering_mail'] = array();
add_filter( 'doughboss_orders_email', 'db_integration_no_orders_email' );
add_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10, 2 );
$GLOBALS['db_fail_snapshot_complete_once'] = true;
add_filter( 'query', 'db_integration_fail_snapshot_complete' );
$previous_suppress_errors = $wpdb->suppress_errors( true );
$stripe_webhook_retry_first = $stripe_webhook_retry_fixture['controller']->stripe_webhook( $stripe_webhook_retry_request );
$wpdb->suppress_errors( $previous_suppress_errors );
remove_filter( 'query', 'db_integration_fail_snapshot_complete' );
db_integration_same( false, (bool) $GLOBALS['db_fail_snapshot_complete_once'], 'Stripe webhook finalization fixture consumes its one injected SQL failure' );
db_integration_same( 'doughboss_wh_retry', is_wp_error( $stripe_webhook_retry_first ) ? $stripe_webhook_retry_first->get_error_code() : '', 'Stripe webhook leaves its event retryable when post-order finalization fails' );
$stripe_webhook_retry_order_id = DoughBoss_Order::find_id_by_payment_intent( $stripe_webhook_retry_fixture['pi'] );
db_integration_assert( $stripe_webhook_retry_order_id > 0, 'retryable Stripe webhook retains its durable paid order' );
db_integration_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'doughboss_orders WHERE payment_intent_id = %s', $stripe_webhook_retry_fixture['pi'] ) ), 'retryable Stripe webhook owns exactly one order' );
db_integration_same( 1, count( $GLOBALS['db_catering_mail'] ), 'retryable Stripe webhook attempts customer confirmation exactly once' );
$stripe_webhook_retry_event_key = hash( 'sha256', 'stripe|' . $stripe_webhook_retry_event_id );
db_integration_same( 'retry', DoughBoss_Payment_Attempts::event_outcome( $stripe_webhook_retry_event_key ), 'incomplete Stripe webhook finalization stays retryable in the event ledger' );

$previous_suppress_errors = $wpdb->suppress_errors( true );
$stripe_webhook_retry_healed = $stripe_webhook_retry_fixture['controller']->stripe_webhook( $stripe_webhook_retry_request );
$wpdb->suppress_errors( $previous_suppress_errors );
$stripe_webhook_retry_healed_data = $stripe_webhook_retry_healed instanceof WP_REST_Response ? $stripe_webhook_retry_healed->get_data() : array();
db_integration_same( true, ! empty( $stripe_webhook_retry_healed_data['processed'] ), 'Stripe webhook retry heals an existing order with incomplete finalization' );
db_integration_same( $stripe_webhook_retry_order_id, DoughBoss_Order::find_id_by_payment_intent( $stripe_webhook_retry_fixture['pi'] ), 'healed Stripe webhook retains the original order' );
db_integration_same( 1, count( $GLOBALS['db_catering_mail'] ), 'healed Stripe webhook sends no duplicate confirmation' );
db_integration_same( $stripe_webhook_retry_post_count, $GLOBALS['db_stripe_post_count'], 'Stripe webhook failure and retry never create another hosted Session' );
db_integration_same( 'processed', DoughBoss_Payment_Attempts::event_outcome( $stripe_webhook_retry_event_key ), 'healed Stripe webhook marks its event processed' );
$stripe_webhook_retry_snapshot = DoughBoss_Checkout_Snapshots::find( (string) $stripe_webhook_retry_fixture['attempt']['checkout_key'] );
$stripe_webhook_retry_attempt  = DoughBoss_Payment_Attempts::find( (int) $stripe_webhook_retry_fixture['attempt']['id'] );
$stripe_webhook_retry_order    = DoughBoss_Order::get( $stripe_webhook_retry_order_id );
db_integration_assert( is_array( $stripe_webhook_retry_snapshot ) && 'completed' === (string) $stripe_webhook_retry_snapshot['status'] && (int) $stripe_webhook_retry_snapshot['order_id'] === $stripe_webhook_retry_order_id, 'Stripe webhook retry heals snapshot completion linkage' );
db_integration_assert( is_array( $stripe_webhook_retry_attempt ) && 'succeeded' === (string) $stripe_webhook_retry_attempt['status'] && ! empty( $stripe_webhook_retry_attempt['verified_at'] ), 'Stripe webhook retry heals attempt verification state' );
db_integration_same( $stripe_webhook_retry_order ? (string) $stripe_webhook_retry_order->order_number : '', is_array( $stripe_webhook_retry_attempt ) ? (string) $stripe_webhook_retry_attempt['local_reference'] : '', 'Stripe webhook retry heals attempt order linkage' );

// Approval-before-payment remains a separate durable request until a signed,
// server-retrieved Stripe result creates its one paid fulfilment order.
$preorder_approver = get_user_by( 'login', 'db-integration-approver' );
if ( ! $preorder_approver ) {
	$preorder_approver = wp_insert_user(
		array(
			'user_login' => 'db-integration-approver',
			'user_pass'  => 'SyntheticIntegrationOnlyPassword',
			'user_email' => 'db.integration.approver@example.invalid',
			'role'       => 'administrator',
		)
	);
}
$preorder_other = get_user_by( 'login', 'db-integration-other-manager' );
if ( ! $preorder_other ) {
	$preorder_other = wp_insert_user(
		array(
			'user_login' => 'db-integration-other-manager',
			'user_pass'  => 'SyntheticIntegrationOnlyPassword',
			'user_email' => 'db.integration.other.manager@example.invalid',
			'role'       => 'administrator',
		)
	);
}
$preorder_approver_id = is_wp_error( $preorder_approver ) ? 0 : (int) ( is_object( $preorder_approver ) ? $preorder_approver->ID : $preorder_approver );
$preorder_other_id    = is_wp_error( $preorder_other ) ? 0 : (int) ( is_object( $preorder_other ) ? $preorder_other->ID : $preorder_other );
db_integration_assert( $preorder_approver_id > 0 && $preorder_other_id > 0, 'approval fixture provisions distinct authenticated managers' );
$settings['preorder_approver_user_id'] = $preorder_approver_id;
$settings['ordering_open']             = 0;
update_option( 'doughboss_settings', $settings, false );

$preorder_controller = new DoughBoss_REST_Controller( new DoughBoss_Cart() );
$preorder_permission_request = new WP_REST_Request( 'POST', '/doughboss/v1/admin/preorder/1/decision' );
wp_set_current_user( $preorder_other_id );
$preorder_permission_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$preorder_wrong_manager = $preorder_controller->verify_preorder_approver( $preorder_permission_request );
db_integration_same( 'doughboss_preorder_approver_required', is_wp_error( $preorder_wrong_manager ) ? $preorder_wrong_manager->get_error_code() : '', 'a different authenticated manager cannot approve pre-order payment' );
wp_set_current_user( $preorder_approver_id );
$preorder_missing_nonce = $preorder_controller->verify_preorder_approver( new WP_REST_Request( 'POST', '/doughboss/v1/admin/preorder/1/decision' ) );
db_integration_same( 'doughboss_bad_nonce', is_wp_error( $preorder_missing_nonce ) ? $preorder_missing_nonce->get_error_code() : '', 'the named approver still needs a valid REST nonce' );
$preorder_permission_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$preorder_permission = $preorder_controller->verify_preorder_approver( $preorder_permission_request );
db_integration_same( true, $preorder_permission, 'only the named approver with a valid nonce can authorize payment' );
wp_set_current_user( 0 );

$GLOBALS['db_order_created_events'] = array();
add_action( 'doughboss_order_created', 'db_integration_capture_order_created', 999, 2 );
$preorder_main = db_integration_create_preorder_request( 'approval-main', 21.00 );
$preorder_reject = db_integration_create_preorder_request( 'approval-reject', 22.00 );
$preorder_expired = db_integration_create_preorder_request( 'approval-expired', 23.00 );
$preorder_spoof = db_integration_create_preorder_request( 'approval-spoof', 24.00 );
$preorder_main_id = is_array( $preorder_main ) ? (int) $preorder_main['order_id'] : 0;
$preorder_reject_id = is_array( $preorder_reject ) ? (int) $preorder_reject['order_id'] : 0;
$preorder_expired_id = is_array( $preorder_expired ) ? (int) $preorder_expired['order_id'] : 0;
$preorder_spoof_id = is_array( $preorder_spoof ) ? (int) $preorder_spoof['order_id'] : 0;
db_integration_assert( $preorder_main_id > 0 && $preorder_reject_id > 0 && $preorder_expired_id > 0 && $preorder_spoof_id > 0, 'unpaid pre-order request fixtures are stored through the real order writer' );
foreach ( array( $preorder_main_id, $preorder_reject_id, $preorder_expired_id, $preorder_spoof_id ) as $preorder_request_id ) {
	$pending = DoughBoss_Preorder_Approval::ensure_pending( $preorder_request_id );
	db_integration_same( 'pending', is_array( $pending ) ? (string) $pending['state'] : '', 'each new pre-order request starts in durable pending approval state' );
}
$preorder_created_before_decision = count( $GLOBALS['db_order_created_events'] );
$preorder_pending_claim = DoughBoss_Preorder_Approval::claim_payment( $preorder_main_id, 'not-an-approved-capability' );
db_integration_same( 'doughboss_preorder_payment_unavailable', is_wp_error( $preorder_pending_claim ) ? $preorder_pending_claim->get_error_code() : '', 'a pending request cannot claim payment with an arbitrary token' );
$preorder_pending_order = DoughBoss_Order::get( $preorder_main_id );
$preorder_transition_bypass = DoughBoss_Order::transition(
	$preorder_main_id,
	'confirmed',
	array(
		'expected_version' => $preorder_pending_order ? (int) $preorder_pending_order->version : 1,
		'event_key'        => 'integration-preorder-transition-bypass-0001',
		'actor_type'       => 'staff',
		'actor_id'         => $preorder_approver_id,
	)
);
db_integration_same( 'doughboss_preorder_decision_required', is_wp_error( $preorder_transition_bypass ) ? $preorder_transition_bypass->get_error_code() : '', 'the generic order lifecycle cannot convert an unpaid pre-order request into kitchen work' );
$preorder_posts_before_unapproved_start = $GLOBALS['db_stripe_post_count'];
$preorder_unapproved_start = $preorder_controller->preorder_payment_start(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/start',
		array( 'request_id' => $preorder_main_id, 'token' => 'not-an-approved-capability' )
	)
);
db_integration_same( 'doughboss_preorder_payment_unavailable', is_wp_error( $preorder_unapproved_start ) ? $preorder_unapproved_start->get_error_code() : '', 'unapproved request cannot start a Stripe Session' );
db_integration_same( $preorder_posts_before_unapproved_start, $GLOBALS['db_stripe_post_count'], 'unapproved request sends no Stripe creation request' );

$GLOBALS['db_preorder_mail'] = array();
$GLOBALS['db_preorder_mail_results'] = array();
add_filter( 'pre_wp_mail', 'db_integration_capture_preorder_mail', 10, 2 );
$preorder_main_order = DoughBoss_Order::get( $preorder_main_id );
$preorder_main_revision = $preorder_main_order ? (int) $preorder_main_order->version : 0;
$preorder_route = '/doughboss/v1/admin/preorder/' . $preorder_main_id . '/decision';
$preorder_decision_request = db_integration_rest_request(
	$preorder_route,
	array(
		'decision'          => 'accept',
		'contact_confirmed' => true,
		'expected_version'  => $preorder_main_revision,
		'event_key'         => 'integration-preorder-approve-main-0001',
	)
);
wp_set_current_user( 0 );
$preorder_anonymous_route = rest_do_request( $preorder_decision_request );
$preorder_anonymous_data = $preorder_anonymous_route->get_data();
db_integration_same( 'doughboss_bad_nonce', isset( $preorder_anonymous_data['code'] ) ? (string) $preorder_anonymous_data['code'] : '', 'registered approval route rejects an anonymous request before its callback' );
wp_set_current_user( $preorder_other_id );
$preorder_decision_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$preorder_wrong_route = rest_do_request( $preorder_decision_request );
$preorder_wrong_data = $preorder_wrong_route->get_data();
db_integration_same( 'doughboss_preorder_approver_required', isset( $preorder_wrong_data['code'] ) ? (string) $preorder_wrong_data['code'] : '', 'registered approval route enforces the named approver identity' );
$preorder_spoof_order = DoughBoss_Order::get( $preorder_spoof_id );
$preorder_stale_route_request = db_integration_rest_request(
	'/doughboss/v1/admin/preorder/' . $preorder_spoof_id . '/decision',
	array(
		'decision'          => 'accept',
		'contact_confirmed' => true,
		'expected_version'  => $preorder_spoof_order ? (int) $preorder_spoof_order->version + 1 : 1,
		'event_key'         => 'integration-preorder-stale-0001',
	)
);
wp_set_current_user( $preorder_approver_id );
$preorder_stale_route_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$preorder_stale_route = rest_do_request( $preorder_stale_route_request );
$preorder_stale_data = $preorder_stale_route->get_data();
db_integration_same( 'doughboss_preorder_revision_conflict', isset( $preorder_stale_data['code'] ) ? (string) $preorder_stale_data['code'] : '', 'registered approval route rejects a stale displayed request revision' );
db_integration_same( 'pending', is_array( DoughBoss_Preorder_Approval::get( $preorder_spoof_id ) ) ? (string) DoughBoss_Preorder_Approval::get( $preorder_spoof_id )['state'] : '', 'stale approval attempt cannot alter durable request state' );
$preorder_decision_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$preorder_approved_response = rest_do_request( $preorder_decision_request );
$preorder_approved_data = $preorder_approved_response instanceof WP_REST_Response ? $preorder_approved_response->get_data() : array();
$preorder_approved_headers = $preorder_approved_response instanceof WP_REST_Response ? $preorder_approved_response->get_headers() : array();
$preorder_approval = isset( $preorder_approved_data['approval'] ) && is_array( $preorder_approved_data['approval'] ) ? $preorder_approved_data['approval'] : array();
$preorder_token = isset( $preorder_approval['capability_token'] ) ? (string) $preorder_approval['capability_token'] : '';
db_integration_assert( $preorder_approved_response instanceof WP_REST_Response && 200 === $preorder_approved_response->get_status() && 'approved_unpaid' === ( isset( $preorder_approval['state'] ) ? (string) $preorder_approval['state'] : '' ) && '' !== $preorder_token, 'registered named-approver route records an unpaid approval and returns its opaque payment capability' );
$preorder_notification = isset( $preorder_approved_data['notification'] ) && is_array( $preorder_approved_data['notification'] ) ? $preorder_approved_data['notification'] : array();
$preorder_payment_url = isset( $preorder_approved_data['payment_url'] ) ? (string) $preorder_approved_data['payment_url'] : '';
$preorder_customer_mail = db_integration_preorder_mail_to( 'preorder.approval-main@example.invalid' );
$preorder_customer_body = isset( $preorder_customer_mail['message'] ) ? (string) $preorder_customer_mail['message'] : '';
$preorder_saved_delivery = DoughBoss_Preorder_Approval::get( $preorder_main_id );
db_integration_same( 'accepted', isset( $preorder_notification['customer_status'] ) ? (string) $preorder_notification['customer_status'] : '', 'approval reports only synthetic WordPress customer-mail acceptance' );
db_integration_same( 'accepted', isset( $preorder_notification['shop_status'] ) ? (string) $preorder_notification['shop_status'] : '', 'approval reports only synthetic WordPress shop-mail acceptance' );
db_integration_same( 'accepted', is_array( $preorder_saved_delivery ) ? (string) $preorder_saved_delivery['payment_link_delivery_status'] : '', 'customer mail handoff outcome is durably recorded without changing approval state' );
db_integration_assert( false !== strpos( $preorder_customer_body, 'original deadline' ) && false !== strpos( $preorder_customer_body, 'does not extend that deadline' ) && false === strpos( $preorder_customer_body, 'within 24 hours' ), 'approval email states the original deadline and never promises a fresh 24-hour resend window' );
db_integration_assert( '' !== $preorder_payment_url && false !== strpos( $preorder_payment_url, '#doughboss_request=' . $preorder_main_id . '&doughboss_token=' ), 'approval returns the same-site fragment capability only to the authenticated approver' );
db_integration_assert( isset( $preorder_approved_headers['Cache-Control'] ) && false !== strpos( (string) $preorder_approved_headers['Cache-Control'], 'no-store' ), 'approval response carrying a private capability is explicitly non-cacheable' );
$preorder_active_ids = array();
foreach ( DoughBoss_Order::active_orders() as $preorder_active_order ) {
	$preorder_active_ids[] = isset( $preorder_active_order['id'] ) ? (int) $preorder_active_order['id'] : 0;
}
db_integration_assert( $preorder_main_order && 'preorder_request' === (string) $preorder_main_order->order_source && 'unpaid' === (string) $preorder_main_order->payment_status && ! in_array( $preorder_main_id, $preorder_active_ids, true ), 'approval leaves the request unpaid and outside the active kitchen feed' );
db_integration_same( $preorder_created_before_decision, count( $GLOBALS['db_order_created_events'] ), 'approval itself emits no created-order hook' );
db_integration_same( $preorder_approver_id, isset( $preorder_approval['approver_user_id'] ) ? (int) $preorder_approval['approver_user_id'] : 0, 'approval records the named approver identity' );
db_integration_assert( ! empty( $preorder_approval['decided_at_utc'] ) && ! empty( $preorder_approval['request_fingerprint'] ), 'approval records a decision time and immutable request fingerprint' );

$preorder_mail_count_before_replay = count( $GLOBALS['db_preorder_mail'] );
$preorder_replayed_response = rest_do_request( $preorder_decision_request );
$preorder_replayed_data = $preorder_replayed_response instanceof WP_REST_Response ? $preorder_replayed_response->get_data() : array();
db_integration_same( $preorder_token, isset( $preorder_replayed_data['approval']['capability_token'] ) ? (string) $preorder_replayed_data['approval']['capability_token'] : '', 'approval event replay returns the same capability instead of a second decision' );
db_integration_same( true, ! empty( $preorder_replayed_data['approval']['replayed'] ), 'approval event replay is identified by the atomic decision transaction' );
db_integration_same( 'not_attempted', isset( $preorder_replayed_data['notification']['customer_status'] ) ? (string) $preorder_replayed_data['notification']['customer_status'] : '', 'approval event replay does not claim another customer mail handoff' );
db_integration_same( $preorder_mail_count_before_replay, count( $GLOBALS['db_preorder_mail'] ), 'approval event replay emits no duplicate customer or shop mail' );
db_integration_same( $preorder_created_before_decision, count( $GLOBALS['db_order_created_events'] ), 'approval event replay emits no created-order hook' );

$preorder_recovery_route = '/doughboss/v1/admin/preorder/' . $preorder_main_id . '/payment-link';
$preorder_recovery_view_request = db_integration_rest_request( $preorder_recovery_route, array( 'mode' => 'view' ) );
wp_set_current_user( $preorder_other_id );
$preorder_recovery_view_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$preorder_recovery_wrong = rest_do_request( $preorder_recovery_view_request );
$preorder_recovery_wrong_data = $preorder_recovery_wrong->get_data();
db_integration_same( 'doughboss_preorder_approver_required', isset( $preorder_recovery_wrong_data['code'] ) ? (string) $preorder_recovery_wrong_data['code'] : '', 'payment-link recovery is restricted to the exact named approver' );
wp_set_current_user( $preorder_approver_id );
$preorder_recovery_view_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$preorder_recovery_view = rest_do_request( $preorder_recovery_view_request );
$preorder_recovery_view_data = $preorder_recovery_view instanceof WP_REST_Response ? $preorder_recovery_view->get_data() : array();
$preorder_recovery_view_headers = $preorder_recovery_view instanceof WP_REST_Response ? $preorder_recovery_view->get_headers() : array();
$preorder_before_resend = DoughBoss_Preorder_Approval::get( $preorder_main_id );
$preorder_resend_request = db_integration_rest_request( $preorder_recovery_route, array( 'mode' => 'resend' ) );
$preorder_resend_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$preorder_resend = rest_do_request( $preorder_resend_request );
$preorder_resend_data = $preorder_resend instanceof WP_REST_Response ? $preorder_resend->get_data() : array();
$preorder_after_resend = DoughBoss_Preorder_Approval::get( $preorder_main_id );
wp_set_current_user( 0 );
db_integration_same( $preorder_payment_url, isset( $preorder_recovery_view_data['payment_url'] ) ? (string) $preorder_recovery_view_data['payment_url'] : '', 'view recovery returns the exact original capability URL without rotating it' );
db_integration_same( $preorder_payment_url, isset( $preorder_resend_data['payment_url'] ) ? (string) $preorder_resend_data['payment_url'] : '', 'resend returns the exact original capability URL without rotating it' );
db_integration_assert( isset( $preorder_recovery_view_headers['Cache-Control'] ) && false !== strpos( (string) $preorder_recovery_view_headers['Cache-Control'], 'no-store' ), 'capability-bearing recovery response is explicitly non-cacheable' );
foreach ( array( 'state', 'version', 'approved_revision', 'request_fingerprint', 'payment_token_expires_at_utc', 'payment_checkout_key', 'payment_attempt_id' ) as $stable_field ) {
	db_integration_same( isset( $preorder_before_resend[ $stable_field ] ) ? $preorder_before_resend[ $stable_field ] : null, isset( $preorder_after_resend[ $stable_field ] ) ? $preorder_after_resend[ $stable_field ] : null, 'resend preserves approval field ' . $stable_field );
}
db_integration_same( 'accepted', isset( $preorder_resend_data['notification']['customer_status'] ) ? (string) $preorder_resend_data['notification']['customer_status'] : '', 'resend reports only synthetic WordPress mail acceptance' );

// Mail is an external handoff, not part of the approval transaction. Exercise
// independent customer/shop outcomes and a failed local result write.
$preorder_mail_customer_fail = db_integration_create_preorder_request( 'approval-mail-customer-fail', 19.00 );
$preorder_mail_customer_fail_id = is_array( $preorder_mail_customer_fail ) ? (int) $preorder_mail_customer_fail['order_id'] : 0;
$preorder_mail_customer_fail_order = $preorder_mail_customer_fail_id ? DoughBoss_Order::get( $preorder_mail_customer_fail_id ) : null;
DoughBoss_Preorder_Approval::ensure_pending( $preorder_mail_customer_fail_id );
$GLOBALS['db_preorder_mail_results'] = array( 'preorder.approval-mail-customer-fail@example.invalid' => false );
$preorder_mail_customer_fail_request = db_integration_rest_request(
	'/doughboss/v1/admin/preorder/' . $preorder_mail_customer_fail_id . '/decision',
	array( 'decision' => 'accept', 'contact_confirmed' => true, 'expected_version' => $preorder_mail_customer_fail_order ? (int) $preorder_mail_customer_fail_order->version : 0, 'event_key' => 'integration-preorder-mail-customer-fail-0001' )
);
wp_set_current_user( $preorder_approver_id );
$preorder_mail_customer_fail_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$preorder_mail_customer_fail_response = rest_do_request( $preorder_mail_customer_fail_request );
$preorder_mail_customer_fail_data = $preorder_mail_customer_fail_response instanceof WP_REST_Response ? $preorder_mail_customer_fail_response->get_data() : array();
$preorder_mail_customer_fail_approval = DoughBoss_Preorder_Approval::get( $preorder_mail_customer_fail_id );
db_integration_same( 'failed', isset( $preorder_mail_customer_fail_data['notification']['customer_status'] ) ? (string) $preorder_mail_customer_fail_data['notification']['customer_status'] : '', 'customer mail rejection is reported without claiming delivery' );
db_integration_same( 'accepted', isset( $preorder_mail_customer_fail_data['notification']['shop_status'] ) ? (string) $preorder_mail_customer_fail_data['notification']['shop_status'] : '', 'shop copy can be accepted independently of customer mail' );
db_integration_same( 'approved_unpaid', is_array( $preorder_mail_customer_fail_approval ) ? (string) $preorder_mail_customer_fail_approval['state'] : '', 'customer mail failure never rolls back approval or releases kitchen work' );
db_integration_same( 'failed', is_array( $preorder_mail_customer_fail_approval ) ? (string) $preorder_mail_customer_fail_approval['payment_link_delivery_status'] : '', 'customer mail failure is durably visible for same-link recovery' );

$preorder_mail_shop_fail = db_integration_create_preorder_request( 'approval-mail-shop-fail', 19.50 );
$preorder_mail_shop_fail_id = is_array( $preorder_mail_shop_fail ) ? (int) $preorder_mail_shop_fail['order_id'] : 0;
$preorder_mail_shop_fail_order = $preorder_mail_shop_fail_id ? DoughBoss_Order::get( $preorder_mail_shop_fail_id ) : null;
DoughBoss_Preorder_Approval::ensure_pending( $preorder_mail_shop_fail_id );
$GLOBALS['db_preorder_mail_results'] = array( 'info@doughboss.com.au' => false );
$preorder_mail_shop_fail_request = db_integration_rest_request(
	'/doughboss/v1/admin/preorder/' . $preorder_mail_shop_fail_id . '/decision',
	array( 'decision' => 'accept', 'contact_confirmed' => true, 'expected_version' => $preorder_mail_shop_fail_order ? (int) $preorder_mail_shop_fail_order->version : 0, 'event_key' => 'integration-preorder-mail-shop-fail-0001' )
);
$preorder_mail_shop_fail_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$preorder_mail_shop_fail_response = rest_do_request( $preorder_mail_shop_fail_request );
$preorder_mail_shop_fail_data = $preorder_mail_shop_fail_response instanceof WP_REST_Response ? $preorder_mail_shop_fail_response->get_data() : array();
db_integration_same( 'accepted', isset( $preorder_mail_shop_fail_data['notification']['customer_status'] ) ? (string) $preorder_mail_shop_fail_data['notification']['customer_status'] : '', 'customer mail can be accepted independently of a failed shop copy' );
db_integration_same( 'failed', isset( $preorder_mail_shop_fail_data['notification']['shop_status'] ) ? (string) $preorder_mail_shop_fail_data['notification']['shop_status'] : '', 'shop copy rejection is reported without relabelling customer handoff' );

$preorder_mail_write_fail = db_integration_create_preorder_request( 'approval-mail-write-fail', 19.75 );
$preorder_mail_write_fail_id = is_array( $preorder_mail_write_fail ) ? (int) $preorder_mail_write_fail['order_id'] : 0;
$preorder_mail_write_fail_order = $preorder_mail_write_fail_id ? DoughBoss_Order::get( $preorder_mail_write_fail_id ) : null;
DoughBoss_Preorder_Approval::ensure_pending( $preorder_mail_write_fail_id );
$GLOBALS['db_preorder_mail_results'] = array();
$GLOBALS['db_fail_preorder_delivery_once'] = true;
add_filter( 'query', 'db_integration_fail_preorder_delivery_status' );
$preorder_mail_write_fail_request = db_integration_rest_request(
	'/doughboss/v1/admin/preorder/' . $preorder_mail_write_fail_id . '/decision',
	array( 'decision' => 'accept', 'contact_confirmed' => true, 'expected_version' => $preorder_mail_write_fail_order ? (int) $preorder_mail_write_fail_order->version : 0, 'event_key' => 'integration-preorder-mail-write-fail-0001' )
);
$preorder_mail_write_fail_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$previous_suppress_errors = $wpdb->suppress_errors( true );
$preorder_mail_write_fail_response = rest_do_request( $preorder_mail_write_fail_request );
$wpdb->suppress_errors( $previous_suppress_errors );
remove_filter( 'query', 'db_integration_fail_preorder_delivery_status' );
$preorder_mail_write_fail_data = $preorder_mail_write_fail_response instanceof WP_REST_Response ? $preorder_mail_write_fail_response->get_data() : array();
$preorder_mail_write_fail_approval = DoughBoss_Preorder_Approval::get( $preorder_mail_write_fail_id );
wp_set_current_user( 0 );
db_integration_same( false, (bool) $GLOBALS['db_fail_preorder_delivery_once'], 'delivery-status persistence fixture consumes its one injected SQL failure' );
db_integration_same( 'delivery_status_not_persisted', isset( $preorder_mail_write_fail_data['notification']['warning'] ) ? (string) $preorder_mail_write_fail_data['notification']['warning'] : '', 'mail-status write failure is returned as uncertainty instead of false durable success' );
db_integration_same( 'approved_unpaid', is_array( $preorder_mail_write_fail_approval ) ? (string) $preorder_mail_write_fail_approval['state'] : '', 'mail-status write failure preserves the committed approval state' );
db_integration_same( 'not_attempted', is_array( $preorder_mail_write_fail_approval ) ? (string) $preorder_mail_write_fail_approval['payment_link_delivery_status'] : '', 'failed delivery-status write cannot claim a stored handoff result' );

$preorder_invalid_token = DoughBoss_Preorder_Approval::verify_payment_token( $preorder_main_id, 'wrong-capability' );
db_integration_same( 'doughboss_preorder_payment_token_invalid', is_wp_error( $preorder_invalid_token ) ? $preorder_invalid_token->get_error_code() : '', 'invalid approved-payment capability fails closed' );
$preorder_ready_view = $preorder_controller->preorder_payment_view(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/view',
		array( 'request_id' => $preorder_main_id, 'token' => $preorder_token )
	)
);
$preorder_ready_view_data = $preorder_ready_view instanceof WP_REST_Response ? $preorder_ready_view->get_data() : array();
db_integration_same( 'ready', isset( $preorder_ready_view_data['presentation_state'] ) ? (string) $preorder_ready_view_data['presentation_state'] : '', 'approved capability exposes one deterministic ready presentation state' );
db_integration_same( true, ! empty( $preorder_ready_view_data['can_start_payment'] ), 'fresh approved capability explicitly authorizes one payment start' );
db_integration_assert( empty( $preorder_ready_view_data['contact_tel'] ) || 1 === preg_match( '/^tel:\+[0-9]{8,15}$/', (string) $preorder_ready_view_data['contact_tel'] ), 'public help link is empty or normalized E.164 shop contact data' );
$preorder_expired_decision = DoughBoss_Preorder_Approval::decide( $preorder_expired_id, 'approve', $preorder_approver_id, 'Integration Approver', 'integration-preorder-expired-0001' );
$preorder_expired_token = is_array( $preorder_expired_decision ) && isset( $preorder_expired_decision['capability_token'] ) ? (string) $preorder_expired_decision['capability_token'] : '';
$wpdb->update( $preorder_approval_table, array( 'payment_token_expires_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'request_order_id' => $preorder_expired_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$preorder_expired_token_result = DoughBoss_Preorder_Approval::verify_payment_token( $preorder_expired_id, $preorder_expired_token );
db_integration_same( 'doughboss_preorder_payment_token_expired', is_wp_error( $preorder_expired_token_result ) ? $preorder_expired_token_result->get_error_code() : '', 'expired approved-payment capability cannot be used' );

// Stripe allows a Session only from 30 minutes; DoughBoss keeps a five-minute
// safety margin for a new creation while preserving exact existing-Session replay.
$preorder_cutoff_results = array();
foreach ( array( 2099 => '34:59', 2100 => '35:00', 2101 => '35:01' ) as $remaining_seconds => $cutoff_label ) {
	$cutoff_fixture = db_integration_create_preorder_request( 'approval-cutoff-' . $remaining_seconds, 18.00 );
	$cutoff_id = is_array( $cutoff_fixture ) ? (int) $cutoff_fixture['order_id'] : 0;
	$cutoff_order = $cutoff_id ? DoughBoss_Order::get( $cutoff_id ) : null;
	$cutoff_pending = $cutoff_id ? DoughBoss_Preorder_Approval::ensure_pending( $cutoff_id ) : new WP_Error( 'integration_fixture', 'Missing cutoff request.' );
	$cutoff_decision = $cutoff_id ? DoughBoss_Preorder_Approval::decide( $cutoff_id, 'approve', $preorder_approver_id, 'Integration Approver', 'integration-preorder-cutoff-' . $remaining_seconds, '', $cutoff_order ? (int) $cutoff_order->version : 0 ) : new WP_Error( 'integration_fixture', 'Missing cutoff request.' );
	$cutoff_expiry = gmdate( 'Y-m-d H:i:s', time() + $remaining_seconds );
	$cutoff_token = is_array( $cutoff_pending ) && is_array( $cutoff_decision ) ? db_integration_set_preorder_expiry( $cutoff_id, $cutoff_expiry ) : '';
	$preorder_cutoff_results[ $remaining_seconds ] = '' !== $cutoff_token ? DoughBoss_Preorder_Approval::claim_payment( $cutoff_id, $cutoff_token ) : new WP_Error( 'integration_fixture', 'Missing cutoff token.' );
	db_integration_assert( $cutoff_id > 0 && '' !== $cutoff_token, 'new-payment cutoff fixture ' . $cutoff_label . ' is valid' );
}
db_integration_same( 'doughboss_preorder_payment_start_cutoff', is_wp_error( $preorder_cutoff_results[2099] ) ? $preorder_cutoff_results[2099]->get_error_code() : '', '34:59 remaining cannot claim a new Stripe Session' );
db_integration_same( 'payment_active', is_array( $preorder_cutoff_results[2100] ) ? (string) $preorder_cutoff_results[2100]['state'] : '', '35:00 remaining is the accepted new-Session boundary' );
db_integration_same( 'payment_active', is_array( $preorder_cutoff_results[2101] ) ? (string) $preorder_cutoff_results[2101]['state'] : '', '35:01 remaining can claim a new Stripe Session' );

$preorder_reject_result = DoughBoss_Preorder_Approval::decide( $preorder_reject_id, 'reject', $preorder_approver_id, 'Integration Approver', 'integration-preorder-reject-0001' );
$preorder_reject_replay = DoughBoss_Preorder_Approval::decide( $preorder_reject_id, 'reject', $preorder_approver_id, 'Integration Approver', 'integration-preorder-reject-0001' );
$preorder_rejected_order = DoughBoss_Order::get( $preorder_reject_id );
db_integration_same( 'rejected', is_array( $preorder_reject_result ) ? (string) $preorder_reject_result['state'] : '', 'rejecting a request is terminal in approval storage' );
db_integration_same( 'rejected', is_array( $preorder_reject_replay ) ? (string) $preorder_reject_replay['state'] : '', 'rejected decision event replays without reopening the request' );
db_integration_same( 'cancelled', $preorder_rejected_order ? (string) $preorder_rejected_order->status : '', 'rejected request is cancelled outside the kitchen lifecycle hooks' );

$preorder_claim = DoughBoss_Preorder_Approval::claim_payment( $preorder_main_id, $preorder_token );
$preorder_claim_replay = DoughBoss_Preorder_Approval::claim_payment( $preorder_main_id, $preorder_token );
db_integration_same( 'payment_active', is_array( $preorder_claim ) ? (string) $preorder_claim['state'] : '', 'approved capability claims one payment state' );
db_integration_same( isset( $preorder_claim['payment_checkout_key'] ) ? (string) $preorder_claim['payment_checkout_key'] : '', isset( $preorder_claim_replay['payment_checkout_key'] ) ? (string) $preorder_claim_replay['payment_checkout_key'] : '', 'payment claim replay preserves one deterministic checkout key' );
db_integration_same( true, ! empty( $preorder_claim_replay['replayed'] ), 'second payment claim is a replay, not a second payment authorization' );

$preorder_spoof_decision = DoughBoss_Preorder_Approval::decide( $preorder_spoof_id, 'approve', $preorder_approver_id, 'Integration Approver', 'integration-preorder-spoof-0001', '', $preorder_spoof_order ? (int) $preorder_spoof_order->version : 0 );
$preorder_spoof_token = is_array( $preorder_spoof_decision ) && isset( $preorder_spoof_decision['capability_token'] ) ? (string) $preorder_spoof_decision['capability_token'] : '';
$preorder_spoof_claim = DoughBoss_Preorder_Approval::claim_payment( $preorder_spoof_id, $preorder_spoof_token );
$preorder_spoof_final = DoughBoss_Order::create(
	array(
		'checkout_key'      => isset( $preorder_spoof_claim['payment_checkout_key'] ) ? (string) $preorder_spoof_claim['payment_checkout_key'] : '',
		'order_source'      => 'web',
		'source_request_id' => $preorder_spoof_id,
		'order_type'        => 'pickup',
		'location_id'       => 1,
		'customer_name'     => 'Spoofed payment',
		'customer_email'    => 'spoofed.payment@example.invalid',
		'customer_phone'    => '0400000012',
		'address'           => '',
		'notes'             => '',
		'subtotal'          => 24.00,
		'tax'               => 0.0,
		'delivery_fee'      => 0.0,
		'total'             => 24.00,
		'payment_status'    => 'unpaid',
		'payment_method'    => 'stripe',
	),
	array( array( 'item_id' => 9799, 'name' => 'Spoofed final order', 'quantity' => 1, 'unit_price' => 24.00, 'line_total' => 24.00 ) )
);
db_integration_same( 'doughboss_preorder_release_forbidden', is_wp_error( $preorder_spoof_final ) ? $preorder_spoof_final->get_error_code() : '', 'unpaid final data cannot release an approved request into fulfilment' );
db_integration_same( 'payment_active', is_array( DoughBoss_Preorder_Approval::get( $preorder_spoof_id ) ) ? (string) DoughBoss_Preorder_Approval::get( $preorder_spoof_id )['state'] : '', 'rejected final data leaves its approval unreleased' );

$preorder_posts_before_start = $GLOBALS['db_stripe_post_count'];
add_filter( 'doughboss_preorder_payment_page_url', 'db_integration_preorder_payment_page_url' );

// The public capability must not bypass the shop-level online-payment switch,
// including the non-mutating view used by the payment page bootstrap.
$preorder_location_off = db_integration_create_preorder_request( 'approval-location-off', 25.00 );
$preorder_location_off_id = is_array( $preorder_location_off ) ? (int) $preorder_location_off['order_id'] : 0;
$preorder_location_off_pending = $preorder_location_off_id ? DoughBoss_Preorder_Approval::ensure_pending( $preorder_location_off_id ) : new WP_Error( 'integration_fixture', 'Missing location-off request.' );
$preorder_location_off_order = $preorder_location_off_id ? DoughBoss_Order::get( $preorder_location_off_id ) : null;
$preorder_location_off_decision = $preorder_location_off_id ? DoughBoss_Preorder_Approval::decide( $preorder_location_off_id, 'approve', $preorder_approver_id, 'Integration Approver', 'integration-preorder-location-off-0001', '', $preorder_location_off_order ? (int) $preorder_location_off_order->version : 0 ) : new WP_Error( 'integration_fixture', 'Missing location-off request.' );
$preorder_location_off_token = is_array( $preorder_location_off_decision ) && isset( $preorder_location_off_decision['capability_token'] ) ? (string) $preorder_location_off_decision['capability_token'] : '';
db_integration_assert( is_array( $preorder_location_off_pending ) && is_array( $preorder_location_off_decision ) && '' !== $preorder_location_off_token, 'location-disabled payment fixture is durably approved before disabling its shop' );
$wpdb->update( $wpdb->prefix . 'doughboss_locations', array( 'online_payment_enabled' => 0 ), array( 'id' => 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$_SERVER['REMOTE_ADDR'] = '192.0.2.210';
$preorder_location_off_view = $preorder_controller->preorder_payment_view(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/view',
		array( 'request_id' => $preorder_location_off_id, 'token' => $preorder_location_off_token )
	)
);
$preorder_location_off_start = $preorder_controller->preorder_payment_start(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/start',
		array( 'request_id' => $preorder_location_off_id, 'token' => $preorder_location_off_token )
	)
);
$preorder_location_off_view_data = $preorder_location_off_view instanceof WP_REST_Response ? $preorder_location_off_view->get_data() : array();
db_integration_same( false, ! empty( $preorder_location_off_view_data['payable'] ), 'approved payment view returns a safe non-payable summary when its shop is disabled' );
db_integration_same( 'doughboss_pay_location_off', is_wp_error( $preorder_location_off_start ) ? $preorder_location_off_start->get_error_code() : '', 'approved payment start refuses a shop whose online payments are disabled' );
db_integration_same( $preorder_posts_before_start, $GLOBALS['db_stripe_post_count'], 'disabled shop cannot create a Stripe Session through an approved capability' );
$wpdb->update( $wpdb->prefix . 'doughboss_locations', array( 'online_payment_enabled' => 1 ), array( 'id' => 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Expiry applies throughout the payment lifecycle. An abandoned, active
// capability cannot be replayed into another payable state.
$preorder_active_expired = db_integration_create_preorder_request( 'approval-active-expired', 26.00 );
$preorder_active_expired_id = is_array( $preorder_active_expired ) ? (int) $preorder_active_expired['order_id'] : 0;
$preorder_active_expired_pending = $preorder_active_expired_id ? DoughBoss_Preorder_Approval::ensure_pending( $preorder_active_expired_id ) : new WP_Error( 'integration_fixture', 'Missing active-expired request.' );
$preorder_active_expired_order = $preorder_active_expired_id ? DoughBoss_Order::get( $preorder_active_expired_id ) : null;
$preorder_active_expired_decision = $preorder_active_expired_id ? DoughBoss_Preorder_Approval::decide( $preorder_active_expired_id, 'approve', $preorder_approver_id, 'Integration Approver', 'integration-preorder-active-expired-0001', '', $preorder_active_expired_order ? (int) $preorder_active_expired_order->version : 0 ) : new WP_Error( 'integration_fixture', 'Missing active-expired request.' );
$preorder_active_expired_token = is_array( $preorder_active_expired_decision ) && isset( $preorder_active_expired_decision['capability_token'] ) ? (string) $preorder_active_expired_decision['capability_token'] : '';
$preorder_active_expired_claim = $preorder_active_expired_id ? DoughBoss_Preorder_Approval::claim_payment( $preorder_active_expired_id, $preorder_active_expired_token ) : new WP_Error( 'integration_fixture', 'Missing active-expired request.' );
$wpdb->update( $preorder_approval_table, array( 'payment_token_expires_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'request_order_id' => $preorder_active_expired_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$_SERVER['REMOTE_ADDR'] = '192.0.2.211';
$preorder_active_expired_view = $preorder_controller->preorder_payment_view(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/view',
		array( 'request_id' => $preorder_active_expired_id, 'token' => $preorder_active_expired_token )
	)
);
$preorder_active_expired_reclaim = DoughBoss_Preorder_Approval::claim_payment( $preorder_active_expired_id, $preorder_active_expired_token );
db_integration_assert( is_array( $preorder_active_expired_pending ) && is_array( $preorder_active_expired_claim ), 'expired-active fixture reaches a durable payment-active state before expiry' );
db_integration_same( 'doughboss_preorder_payment_token_expired', is_wp_error( $preorder_active_expired_view ) ? $preorder_active_expired_view->get_error_code() : '', 'expired payment-active capability cannot expose a payable payment view' );
db_integration_same( 'doughboss_preorder_payment_token_expired', is_wp_error( $preorder_active_expired_reclaim ) ? $preorder_active_expired_reclaim->get_error_code() : '', 'expired payment-active capability cannot be claimed again' );

// A transport-ambiguous browser completion freezes this request rather than
// returning a second payment action that could create another charge attempt.
$preorder_ambiguous = db_integration_create_preorder_request( 'approval-ambiguous', 27.00 );
$preorder_ambiguous_id = is_array( $preorder_ambiguous ) ? (int) $preorder_ambiguous['order_id'] : 0;
$preorder_ambiguous_pending = $preorder_ambiguous_id ? DoughBoss_Preorder_Approval::ensure_pending( $preorder_ambiguous_id ) : new WP_Error( 'integration_fixture', 'Missing ambiguous request.' );
$preorder_ambiguous_order = $preorder_ambiguous_id ? DoughBoss_Order::get( $preorder_ambiguous_id ) : null;
$preorder_ambiguous_decision = $preorder_ambiguous_id ? DoughBoss_Preorder_Approval::decide( $preorder_ambiguous_id, 'approve', $preorder_approver_id, 'Integration Approver', 'integration-preorder-ambiguous-0001', '', $preorder_ambiguous_order ? (int) $preorder_ambiguous_order->version : 0 ) : new WP_Error( 'integration_fixture', 'Missing ambiguous request.' );
$preorder_ambiguous_token = is_array( $preorder_ambiguous_decision ) && isset( $preorder_ambiguous_decision['capability_token'] ) ? (string) $preorder_ambiguous_decision['capability_token'] : '';
$_SERVER['REMOTE_ADDR'] = '192.0.2.212';
$preorder_ambiguous_start = $preorder_controller->preorder_payment_start(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/start',
		array( 'request_id' => $preorder_ambiguous_id, 'token' => $preorder_ambiguous_token )
	)
);
$preorder_ambiguous_start_data = $preorder_ambiguous_start instanceof WP_REST_Response ? $preorder_ambiguous_start->get_data() : array();
$preorder_ambiguous_session = isset( $preorder_ambiguous_start_data['checkout_session'] ) ? (string) $preorder_ambiguous_start_data['checkout_session'] : '';
$GLOBALS['db_stripe_session_network_once'] = true;
$preorder_ambiguous_complete = $preorder_controller->preorder_payment_complete(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/complete',
		array( 'request_id' => $preorder_ambiguous_id, 'token' => $preorder_ambiguous_token, 'session_id' => $preorder_ambiguous_session )
	)
);
$preorder_ambiguous_state = DoughBoss_Preorder_Approval::get( $preorder_ambiguous_id );
$preorder_ambiguous_view = $preorder_controller->preorder_payment_view(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/view',
		array( 'request_id' => $preorder_ambiguous_id, 'token' => $preorder_ambiguous_token )
	)
);
$preorder_ambiguous_view_data = $preorder_ambiguous_view instanceof WP_REST_Response ? $preorder_ambiguous_view->get_data() : array();
$preorder_ambiguous_posts_before_replay = $GLOBALS['db_stripe_post_count'];
$preorder_ambiguous_replay = $preorder_controller->preorder_payment_start(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/start',
		array( 'request_id' => $preorder_ambiguous_id, 'token' => $preorder_ambiguous_token )
	)
);
db_integration_assert( is_array( $preorder_ambiguous_pending ) && $preorder_ambiguous_start instanceof WP_REST_Response && '' !== $preorder_ambiguous_session && is_wp_error( $preorder_ambiguous_complete ), 'ambiguous browser completion fixture reaches a real provider retrieval failure' );
db_integration_same( 'payment_held', is_array( $preorder_ambiguous_state ) ? (string) $preorder_ambiguous_state['state'] : '', 'ambiguous Stripe retrieval freezes the approved request for staff review' );
db_integration_same( false, ! empty( $preorder_ambiguous_view_data['payable'] ), 'held approval never exposes another payable payment action' );
db_integration_assert( is_wp_error( $preorder_ambiguous_replay ), 'held approval cannot start or replay a payment session' );
db_integration_same( $preorder_ambiguous_posts_before_replay, $GLOBALS['db_stripe_post_count'], 'held approval emits no second Stripe Session creation request' );

$preorder_posts_before_main_start = $GLOBALS['db_stripe_post_count'];
$preorder_start_response = $preorder_controller->preorder_payment_start(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/start',
		array( 'request_id' => $preorder_main_id, 'token' => $preorder_token )
	)
);
$preorder_start_data = $preorder_start_response instanceof WP_REST_Response ? $preorder_start_response->get_data() : array();
$preorder_session = isset( $preorder_start_data['checkout_session'] ) ? (string) $preorder_start_data['checkout_session'] : '';
$preorder_active_approval = DoughBoss_Preorder_Approval::get( $preorder_main_id );
db_integration_assert( $preorder_start_response instanceof WP_REST_Response && '' !== $preorder_session, 'approved request starts one Stripe Session even while normal ordering is closed' );
db_integration_same( $preorder_posts_before_main_start + 1, $GLOBALS['db_stripe_post_count'], 'approved payment creates exactly one Stripe Session' );
db_integration_assert( is_array( $preorder_active_approval ) && ! empty( $preorder_active_approval['payment_attempt_id'] ) && 0 === DoughBoss_Order::find_id_by_source_request( $preorder_main_id ), 'starting payment links an attempt but creates no kitchen order' );
$preorder_snapshot = isset( $preorder_active_approval['payment_checkout_key'] ) ? DoughBoss_Checkout_Snapshots::find( (string) $preorder_active_approval['payment_checkout_key'] ) : null;
$preorder_last_stripe_body = ! empty( $GLOBALS['db_stripe_post_bodies'] ) ? end( $GLOBALS['db_stripe_post_bodies'] ) : array();
$preorder_original_expiry = is_array( $preorder_active_approval ) ? (string) $preorder_active_approval['payment_token_expires_at_utc'] : '';
db_integration_same( $preorder_original_expiry, is_array( $preorder_snapshot ) ? (string) $preorder_snapshot['expires_at'] : '', 'approved snapshot uses the stable approval deadline instead of renewing retention' );
db_integration_same( strtotime( $preorder_original_expiry . ' UTC' ), isset( $preorder_last_stripe_body['expires_at'] ) ? (int) $preorder_last_stripe_body['expires_at'] : 0, 'Stripe Checkout receives the exact stable approval deadline' );

$wpdb->update( $preorder_approval_table, array( 'payment_token_expires_at_utc' => gmdate( 'Y-m-d H:i:s', time() + ( 20 * MINUTE_IN_SECONDS ) ) ), array( 'request_order_id' => $preorder_main_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$preorder_posts_before_short_replay = $GLOBALS['db_stripe_post_count'];
$_SERVER['REMOTE_ADDR'] = '192.0.2.212';
$preorder_short_replay = $preorder_controller->preorder_payment_start(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/start',
		array( 'request_id' => $preorder_main_id, 'token' => $preorder_token )
	)
);
$preorder_short_replay_data = $preorder_short_replay instanceof WP_REST_Response ? $preorder_short_replay->get_data() : array();
$preorder_short_view = $preorder_controller->preorder_payment_view(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/view',
		array( 'request_id' => $preorder_main_id, 'token' => $preorder_token )
	)
);
$preorder_short_view_data = $preorder_short_view instanceof WP_REST_Response ? $preorder_short_view->get_data() : array();
db_integration_same( $preorder_session, isset( $preorder_short_replay_data['checkout_session'] ) ? (string) $preorder_short_replay_data['checkout_session'] : '', 'a bound active Session replays below Stripe new-creation minimum' );
db_integration_same( $preorder_posts_before_short_replay, $GLOBALS['db_stripe_post_count'], 'below-30-minute active replay sends no second Stripe creation request' );
db_integration_same( true, ! empty( $preorder_short_view_data['can_start_payment'] ), 'active Session remains available as exact replay inside the new-creation cutoff' );
$wpdb->update( $preorder_approval_table, array( 'payment_token_expires_at_utc' => $preorder_original_expiry ), array( 'request_order_id' => $preorder_main_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Expired snapshot recovery and purge use the same bounded exact-Stripe
// predicate. Corrupt payloads, malformed/future timestamps, excluded states,
// wrong references and attempts older than seven days all fail closed.
$preorder_attempt = DoughBoss_Payment_Attempts::find( (int) $preorder_active_approval['payment_attempt_id'] );
$preorder_attempt_table = $wpdb->prefix . 'doughboss_payment_attempts';
$preorder_snapshot_table = $wpdb->prefix . 'doughboss_checkout_snapshots';
$preorder_attempt_original = $preorder_attempt;
$preorder_snapshot_original_hash = is_array( $preorder_snapshot ) ? (string) $preorder_snapshot['payload_hash'] : '';
$wpdb->update( $preorder_snapshot_table, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'checkout_key' => (string) $preorder_active_approval['payment_checkout_key'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$stripe_recovery_allowed_statuses = array( 'processing', 'unknown', 'succeeded', 'mismatch' );
foreach ( $stripe_recovery_allowed_statuses as $allowed_status ) {
	$wpdb->update( $preorder_attempt_table, array( 'status' => $allowed_status, 'provider_reference' => $preorder_session, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) + 10 ) ), array( 'id' => (int) $preorder_attempt['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	db_integration_assert( is_array( DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( (string) $preorder_active_approval['payment_checkout_key'] ) ), 'bounded Stripe recovery accepts exact ' . $allowed_status . ' attempt' );
}
foreach ( array( 'prepared', 'dispatching', 'failed', 'created' ) as $excluded_status ) {
	$wpdb->update( $preorder_attempt_table, array( 'status' => $excluded_status, 'provider_reference' => $preorder_session, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => (int) $preorder_attempt['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	db_integration_same( null, DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( (string) $preorder_active_approval['payment_checkout_key'] ), 'Stripe recovery rejects ' . $excluded_status . ' attempt' );
}
$stripe_recovery_time_cases = array(
	'outside seven days' => gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) - 10 ),
	'future time'        => gmdate( 'Y-m-d H:i:s', time() + 10 ),
	'malformed time'     => 'not-a-date',
	'missing time'       => null,
);
foreach ( $stripe_recovery_time_cases as $time_label => $created_at ) {
	$wpdb->update( $preorder_attempt_table, array( 'status' => 'processing', 'provider_reference' => $preorder_session, 'created_at' => $created_at ), array( 'id' => (int) $preorder_attempt['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	db_integration_same( null, DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( (string) $preorder_active_approval['payment_checkout_key'] ), 'Stripe recovery rejects ' . $time_label );
}
$wpdb->update( $preorder_attempt_table, array( 'status' => 'processing', 'provider_reference' => 'pi_wrong_reference_12345678', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => (int) $preorder_attempt['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
db_integration_same( null, DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( (string) $preorder_active_approval['payment_checkout_key'] ), 'Stripe recovery rejects a non-Session provider binding' );
$wpdb->update( $preorder_attempt_table, array( 'status' => 'processing', 'provider_reference' => $preorder_session, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => (int) $preorder_attempt['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->update( $preorder_snapshot_table, array( 'payload_hash' => hash( 'sha256', 'altered-payload-hash' ) ), array( 'checkout_key' => (string) $preorder_active_approval['payment_checkout_key'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
db_integration_same( null, DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( (string) $preorder_active_approval['payment_checkout_key'] ), 'Stripe recovery rejects an altered immutable snapshot hash' );
$wpdb->update( $preorder_snapshot_table, array( 'payload_hash' => $preorder_snapshot_original_hash ), array( 'checkout_key' => (string) $preorder_active_approval['payment_checkout_key'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$purge_method = new ReflectionMethod( 'DoughBoss_Checkout_Snapshots', 'purge_expired' );
$purge_method->setAccessible( true );
$purge_method->invoke( null, true );
db_integration_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $preorder_snapshot_table . ' WHERE checkout_key = %s', (string) $preorder_active_approval['payment_checkout_key'] ) ), 'purge preserves the same exact in-horizon Stripe recovery row' );
$wpdb->update( $preorder_attempt_table, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) - 10 ) ), array( 'id' => (int) $preorder_attempt['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$purge_method->invoke( null, true );
db_integration_same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $preorder_snapshot_table . ' WHERE checkout_key = %s', (string) $preorder_active_approval['payment_checkout_key'] ) ), 'purge removes the same out-of-horizon Stripe recovery row' );
$wpdb->update( $preorder_attempt_table, array( 'status' => (string) $preorder_attempt_original['status'], 'provider_reference' => (string) $preorder_attempt_original['provider_reference'], 'created_at' => (string) $preorder_attempt_original['created_at'] ), array( 'id' => (int) $preorder_attempt['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$preorder_snapshot_restored = DoughBoss_Checkout_Snapshots::store( (string) $preorder_active_approval['payment_checkout_key'], $preorder_snapshot['payload'], $preorder_original_expiry );
db_integration_assert( is_array( $preorder_snapshot_restored ) && $preorder_original_expiry === (string) $preorder_snapshot_restored['expires_at'], 'recovery predicate test restores the original immutable snapshot without renewing its deadline' );

// A signed checkout.session event still cannot release an approved request if
// its Session reference is not the one recorded on the payment attempt. The
// matching PaymentIntent and metadata are deliberately insufficient proof.
$preorder_webhook_misbound = db_integration_create_preorder_request( 'approval-webhook-misbound', 28.00 );
$preorder_webhook_misbound_id = is_array( $preorder_webhook_misbound ) ? (int) $preorder_webhook_misbound['order_id'] : 0;
$preorder_webhook_misbound_pending = $preorder_webhook_misbound_id ? DoughBoss_Preorder_Approval::ensure_pending( $preorder_webhook_misbound_id ) : new WP_Error( 'integration_fixture', 'Missing webhook-misbound request.' );
$preorder_webhook_misbound_order = $preorder_webhook_misbound_id ? DoughBoss_Order::get( $preorder_webhook_misbound_id ) : null;
$preorder_webhook_misbound_decision = $preorder_webhook_misbound_id ? DoughBoss_Preorder_Approval::decide( $preorder_webhook_misbound_id, 'approve', $preorder_approver_id, 'Integration Approver', 'integration-preorder-webhook-misbound-0001', '', $preorder_webhook_misbound_order ? (int) $preorder_webhook_misbound_order->version : 0 ) : new WP_Error( 'integration_fixture', 'Missing webhook-misbound request.' );
$preorder_webhook_misbound_token = is_array( $preorder_webhook_misbound_decision ) && isset( $preorder_webhook_misbound_decision['capability_token'] ) ? (string) $preorder_webhook_misbound_decision['capability_token'] : '';
$_SERVER['REMOTE_ADDR'] = '192.0.2.213';
$preorder_webhook_misbound_start = $preorder_controller->preorder_payment_start(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/start',
		array( 'request_id' => $preorder_webhook_misbound_id, 'token' => $preorder_webhook_misbound_token )
	)
);
$preorder_webhook_misbound_start_data = $preorder_webhook_misbound_start instanceof WP_REST_Response ? $preorder_webhook_misbound_start->get_data() : array();
$preorder_webhook_misbound_session_id = isset( $preorder_webhook_misbound_start_data['checkout_session'] ) ? (string) $preorder_webhook_misbound_start_data['checkout_session'] : '';
$preorder_webhook_misbound_session = isset( $GLOBALS['db_stripe_sessions'][ $preorder_webhook_misbound_session_id ] ) ? $GLOBALS['db_stripe_sessions'][ $preorder_webhook_misbound_session_id ] : array();
$preorder_webhook_misbound_session['id'] = 'cs_test_' . substr( hash( 'sha256', 'misbound-session|' . $preorder_webhook_misbound_session_id ), 0, 24 );
$preorder_webhook_misbound_session['payment_status'] = 'paid';
$preorder_webhook_misbound_event_id = 'evt_PREORDERMISBOUNDSESSION0001';
$preorder_webhook_misbound_body = wp_json_encode(
	array(
		'id'   => $preorder_webhook_misbound_event_id,
		'type' => 'checkout.session.completed',
		'data' => array( 'object' => $preorder_webhook_misbound_session ),
	)
);
$preorder_webhook_misbound_timestamp = time();
$preorder_webhook_misbound_signature = hash_hmac( 'sha256', $preorder_webhook_misbound_timestamp . '.' . $preorder_webhook_misbound_body, $settings['stripe_test_whsec'] );
$preorder_webhook_misbound_request = new WP_REST_Request( 'POST', '/doughboss/v1/stripe-webhook' );
$preorder_webhook_misbound_request->set_body( $preorder_webhook_misbound_body );
$preorder_webhook_misbound_request->set_header( 'Stripe-Signature', 't=' . $preorder_webhook_misbound_timestamp . ',v1=' . $preorder_webhook_misbound_signature );
$preorder_webhook_misbound_created_before = count( $GLOBALS['db_order_created_events'] );
$preorder_webhook_misbound_result = $preorder_controller->stripe_webhook( $preorder_webhook_misbound_request );
$preorder_webhook_misbound_approval = DoughBoss_Preorder_Approval::get( $preorder_webhook_misbound_id );
$_SERVER['REMOTE_ADDR'] = '192.0.2.214';
$preorder_webhook_misbound_view = $preorder_controller->preorder_payment_view(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/view',
		array( 'request_id' => $preorder_webhook_misbound_id, 'token' => $preorder_webhook_misbound_token )
	)
);
$preorder_webhook_misbound_view_data = $preorder_webhook_misbound_view instanceof WP_REST_Response ? $preorder_webhook_misbound_view->get_data() : array();
$preorder_webhook_misbound_posts_before_replay = $GLOBALS['db_stripe_post_count'];
$preorder_webhook_misbound_replay = $preorder_controller->preorder_payment_start(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/start',
		array( 'request_id' => $preorder_webhook_misbound_id, 'token' => $preorder_webhook_misbound_token )
	)
);
db_integration_assert( is_array( $preorder_webhook_misbound_pending ) && $preorder_webhook_misbound_start instanceof WP_REST_Response && '' !== $preorder_webhook_misbound_session_id, 'misbound webhook fixture owns a real approved-request Checkout Session' );
db_integration_same( 'doughboss_wh_retry', is_wp_error( $preorder_webhook_misbound_result ) ? $preorder_webhook_misbound_result->get_error_code() : '', 'misbound Checkout Session event is retryable but cannot be released' );
db_integration_same( 0, (int) DoughBoss_Order::find_id_by_source_request( $preorder_webhook_misbound_id ), 'misbound Checkout Session cannot create a paid fulfilment order from a matching PaymentIntent' );
db_integration_same( 'payment_held', is_array( $preorder_webhook_misbound_approval ) ? (string) $preorder_webhook_misbound_approval['state'] : '', 'misbound Checkout Session freezes the approved request for staff review' );
db_integration_same( false, ! empty( $preorder_webhook_misbound_view_data['payable'] ), 'misbound Checkout Session cannot expose another payable action' );
db_integration_assert( is_wp_error( $preorder_webhook_misbound_replay ), 'misbound Checkout Session cannot start or replay a payment session' );
db_integration_same( $preorder_webhook_misbound_posts_before_replay, $GLOBALS['db_stripe_post_count'], 'misbound Checkout Session emits no second Stripe Session creation request' );
db_integration_same( $preorder_webhook_misbound_created_before, count( $GLOBALS['db_order_created_events'] ), 'misbound Checkout Session emits no created-order hook' );

// A payment can settle at the edge of the approved Session deadline and reach
// the site after the ordinary customer snapshot has expired. The already-bound
// Stripe attempt retains that immutable snapshot for bounded paid recovery.
$preorder_main_snapshot_expired = $wpdb->update(
	$preorder_snapshot_table,
	array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
	array( 'checkout_key' => (string) $preorder_active_approval['payment_checkout_key'] )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
db_integration_same( 1, (int) $preorder_main_snapshot_expired, 'paid-return fixture expires only its ordinary Stripe snapshot window' );
db_integration_same( null, DoughBoss_Checkout_Snapshots::find( (string) $preorder_active_approval['payment_checkout_key'] ), 'expired paid-return snapshot is unavailable to ordinary reads' );
db_integration_assert( is_array( DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( (string) $preorder_active_approval['payment_checkout_key'] ) ), 'expired paid-return snapshot remains available only through bounded irreversible-payment recovery' );

$preorder_review_before_complete = DoughBoss_Order::kitchen_workload_counts( 1 );
$preorder_created_before_main_complete = count( $GLOBALS['db_order_created_events'] );
$preorder_complete_response = $preorder_controller->preorder_payment_complete(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/complete',
		array( 'request_id' => $preorder_main_id, 'token' => $preorder_token, 'session_id' => $preorder_session )
	)
);
$preorder_complete_data = $preorder_complete_response instanceof WP_REST_Response ? $preorder_complete_response->get_data() : array();
$preorder_final_id = DoughBoss_Order::find_id_by_source_request( $preorder_main_id );
$preorder_final = $preorder_final_id ? DoughBoss_Order::get( $preorder_final_id ) : null;
$preorder_released = DoughBoss_Preorder_Approval::get( $preorder_main_id );
$preorder_complete_outcome = is_wp_error( $preorder_complete_response ) ? $preorder_complete_response->get_error_code() : ( $preorder_complete_response instanceof WP_REST_Response ? 'response' : gettype( $preorder_complete_response ) );
db_integration_assert( $preorder_complete_response instanceof WP_REST_Response && ! empty( $preorder_complete_data['success'] ) && $preorder_final && 'paid' === (string) $preorder_final->payment_status && 'stripe' === (string) $preorder_final->payment_method, 'server-verified paid Stripe completion creates the fulfilment order from its bounded expired snapshot (outcome: ' . $preorder_complete_outcome . ')' );
db_integration_same( 'released', is_array( $preorder_released ) ? (string) $preorder_released['state'] : '', 'paid Stripe fulfilment atomically releases the approved request' );
db_integration_same( $preorder_final_id, is_array( $preorder_released ) ? (int) $preorder_released['fulfillment_order_id'] : 0, 'release records the exact paid fulfilment order before hooks run' );
db_integration_same( $preorder_created_before_main_complete + 1, count( $GLOBALS['db_order_created_events'] ), 'only the verified paid final order emits one created-order hook' );
$preorder_requests_after_release = DoughBoss_Order::preorder_requests( 200, 1 );
$preorder_request_ids_after_release = array_map(
	function ( $row ) {
		return isset( $row['id'] ) ? (int) $row['id'] : 0;
	},
	$preorder_requests_after_release
);
$preorder_review_after_complete = DoughBoss_Order::kitchen_workload_counts( 1 );
wp_set_current_user( $preorder_approver_id );
$preorder_morning_queue_request = new WP_REST_Request( 'GET', '/doughboss/v1/admin/preorder-requests' );
$preorder_morning_queue_request->set_param( 'per_page', 200 );
$preorder_morning_queue_response = $preorder_controller->admin_preorder_requests( $preorder_morning_queue_request );
wp_set_current_user( 0 );
$preorder_morning_queue_data = $preorder_morning_queue_response instanceof WP_REST_Response ? $preorder_morning_queue_response->get_data() : array();
$preorder_morning_queue_ids = isset( $preorder_morning_queue_data['data'] ) && is_array( $preorder_morning_queue_data['data'] ) ? array_map(
	function ( $row ) {
		return isset( $row['id'] ) ? (int) $row['id'] : 0;
	},
	$preorder_morning_queue_data['data']
) : array();
db_integration_same( false, in_array( $preorder_main_id, $preorder_request_ids_after_release, true ), 'released request is removed from the durable pre-order request list' );
db_integration_same( max( 0, (int) $preorder_review_before_complete['preorder_review'] - 1 ), isset( $preorder_review_after_complete['preorder_review'] ) ? (int) $preorder_review_after_complete['preorder_review'] : -1, 'released request no longer inflates the kitchen pre-order review count' );
db_integration_same( false, in_array( $preorder_main_id, $preorder_morning_queue_ids, true ), 'released request is absent from the staff morning approval queue' );

$preorder_complete_replay = $preorder_controller->preorder_payment_complete(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/complete',
		array( 'request_id' => $preorder_main_id, 'token' => $preorder_token, 'session_id' => $preorder_session )
	)
);
db_integration_assert( $preorder_complete_replay instanceof WP_REST_Response, 'verified Stripe completion replay is acknowledged' );
db_integration_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'doughboss_orders WHERE source_request_id = %d', $preorder_main_id ) ), 'duplicate finalization resolves to exactly one paid fulfilment order' );
db_integration_same( $preorder_created_before_main_complete + 1, count( $GLOBALS['db_order_created_events'] ), 'duplicate finalization emits no second created-order hook' );

// Stripe's verified session is immutable evidence. A later global currency
// configuration change cannot relabel the already-approved AUD payment.
$preorder_currency_drift = db_integration_create_preorder_request( 'approval-currency-drift', 29.00 );
$preorder_currency_drift_id = is_array( $preorder_currency_drift ) ? (int) $preorder_currency_drift['order_id'] : 0;
$preorder_currency_drift_pending = $preorder_currency_drift_id ? DoughBoss_Preorder_Approval::ensure_pending( $preorder_currency_drift_id ) : new WP_Error( 'integration_fixture', 'Missing currency-drift request.' );
$preorder_currency_drift_order = $preorder_currency_drift_id ? DoughBoss_Order::get( $preorder_currency_drift_id ) : null;
$preorder_currency_drift_decision = $preorder_currency_drift_id ? DoughBoss_Preorder_Approval::decide( $preorder_currency_drift_id, 'approve', $preorder_approver_id, 'Integration Approver', 'integration-preorder-currency-drift-0001', '', $preorder_currency_drift_order ? (int) $preorder_currency_drift_order->version : 0 ) : new WP_Error( 'integration_fixture', 'Missing currency-drift request.' );
$preorder_currency_drift_token = is_array( $preorder_currency_drift_decision ) && isset( $preorder_currency_drift_decision['capability_token'] ) ? (string) $preorder_currency_drift_decision['capability_token'] : '';
$_SERVER['REMOTE_ADDR'] = '192.0.2.215';
$preorder_currency_drift_start = $preorder_controller->preorder_payment_start(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/start',
		array( 'request_id' => $preorder_currency_drift_id, 'token' => $preorder_currency_drift_token )
	)
);
$preorder_currency_drift_start_data = $preorder_currency_drift_start instanceof WP_REST_Response ? $preorder_currency_drift_start->get_data() : array();
$preorder_currency_drift_session = isset( $preorder_currency_drift_start_data['checkout_session'] ) ? (string) $preorder_currency_drift_start_data['checkout_session'] : '';
$settings['currency_code'] = 'USD';
update_option( 'doughboss_settings', $settings, false );
$preorder_currency_drift_complete = $preorder_controller->preorder_payment_complete(
	db_integration_rest_request(
		'/doughboss/v1/preorder-payment/complete',
		array( 'request_id' => $preorder_currency_drift_id, 'token' => $preorder_currency_drift_token, 'session_id' => $preorder_currency_drift_session )
	)
);
$preorder_currency_drift_final_id = DoughBoss_Order::find_id_by_source_request( $preorder_currency_drift_id );
$preorder_currency_drift_final = $preorder_currency_drift_final_id ? DoughBoss_Order::get( $preorder_currency_drift_final_id ) : null;
$settings['currency_code'] = 'AUD';
update_option( 'doughboss_settings', $settings, false );
db_integration_assert( is_array( $preorder_currency_drift_pending ) && $preorder_currency_drift_start instanceof WP_REST_Response && '' !== $preorder_currency_drift_session, 'currency-drift fixture starts one approved AUD Checkout Session before the global setting changes' );
db_integration_assert( $preorder_currency_drift_complete instanceof WP_REST_Response && $preorder_currency_drift_final && 'paid' === (string) $preorder_currency_drift_final->payment_status, 'verified old-currency Session still creates its one paid fulfilment order after configuration drift' );
db_integration_same( 'AUD', $preorder_currency_drift_final ? strtoupper( (string) $preorder_currency_drift_final->currency ) : '', 'verified old-currency Session persists the immutable snapshot currency instead of the new global setting' );
remove_filter( 'doughboss_preorder_payment_page_url', 'db_integration_preorder_payment_page_url', 10 );
remove_action( 'doughboss_order_created', 'db_integration_capture_order_created', 999 );
remove_filter( 'pre_wp_mail', 'db_integration_capture_preorder_mail', 10 );
wp_set_current_user( 0 );
$settings['preorder_approver_user_id'] = 0;
$settings['ordering_open']             = 1;
update_option( 'doughboss_settings', $settings, false );
remove_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10 );
remove_filter( 'doughboss_orders_email', 'db_integration_no_orders_email' );

if ( null === $stripe_original_remote_addr ) {
	unset( $_SERVER['REMOTE_ADDR'] );
} else {
	$_SERVER['REMOTE_ADDR'] = $stripe_original_remote_addr;
}
remove_filter( 'pre_http_request', 'db_integration_stripe_http', 10 );

// Catering enquiry integrity uses real posts, locations, REST callbacks and the
// MySQL enquiry table. Every mail is intercepted before transport.
$settings['catering_email'] = 'catering.integration@example.invalid';
update_option( 'doughboss_settings', $settings, false );
$catering_table = DoughBoss_Catering::table();
$published_package = db_integration_catering_package( 'Published Integration Feast' );
$draft_package     = db_integration_catering_package( 'Draft Integration Feast', 'draft' );
$deleted_package   = db_integration_catering_package( 'Deleted Integration Feast' );
$race_package      = db_integration_catering_package( 'Race Integration Feast' );
$wrong_type        = wp_insert_post(
	array(
		'post_title'  => 'Ordinary Integration Post',
		'post_type'   => 'post',
		'post_status' => 'publish',
	)
);
wp_delete_post( $deleted_package, true );
db_integration_assert( $published_package > 0 && $draft_package > 0 && $deleted_package > 0 && $race_package > 0 && ! is_wp_error( $wrong_type ), 'published, draft, deleted and wrong-type package fixtures are created' );

$quote_request = new WP_REST_Request( 'GET', '/doughboss/v1/catering/quote' );
$quote_request->set_param( 'package_id', $published_package );
$quote_request->set_param( 'guest_count', 25 );
$quote_request->set_param( 'order_type', 'pickup' );
$published_quote = $rest_controller->get_catering_quote( $quote_request );
$published_quote_data = $published_quote instanceof WP_REST_Response ? $published_quote->get_data() : array();
db_integration_same( 302.5, isset( $published_quote_data['total'] ) ? (float) $published_quote_data['total'] : -1.0, 'published catering package keeps its server-computed quote' );

$custom_quote_request = new WP_REST_Request( 'GET', '/doughboss/v1/catering/quote' );
$custom_quote_request->set_param( 'package_id', 0 );
$custom_quote_request->set_param( 'guest_count', 25 );
$custom_quote_request->set_param( 'order_type', 'pickup' );
$custom_quote = $rest_controller->get_catering_quote( $custom_quote_request );
$custom_quote_data = $custom_quote instanceof WP_REST_Response ? $custom_quote->get_data() : array();
db_integration_same( 0.0, isset( $custom_quote_data['total'] ) ? (float) $custom_quote_data['total'] : -1.0, 'explicit custom package zero keeps the legacy zero quote' );

$invalid_packages = array(
	'draft'      => $draft_package,
	'wrong type' => (int) $wrong_type,
	'deleted'    => $deleted_package,
);
foreach ( $invalid_packages as $fixture_name => $invalid_package_id ) {
	$invalid_quote_request = new WP_REST_Request( 'GET', '/doughboss/v1/catering/quote' );
	$invalid_quote_request->set_param( 'package_id', $invalid_package_id );
	$invalid_quote_request->set_param( 'guest_count', 25 );
	$invalid_quote_request->set_param( 'order_type', 'pickup' );
	$invalid_quote = $rest_controller->get_catering_quote( $invalid_quote_request );
	db_integration_same( 'doughboss_catering_package_unavailable', is_wp_error( $invalid_quote ) ? $invalid_quote->get_error_code() : '', $fixture_name . ' positive package is rejected by GET quote' );
	db_integration_same( 400, is_wp_error( $invalid_quote ) ? (int) $invalid_quote->get_error_data()['status'] : 0, $fixture_name . ' GET rejection is a validation response' );
}

$shop_b = DoughBoss_Locations::create(
	array(
		'name'            => 'Integration Shop B',
		'slug'            => 'integration-shop-b',
		'timezone'        => 'Australia/Sydney',
		'pickup_enabled'  => 1,
		'delivery_enabled' => 1,
		'is_active'       => 1,
		'sort_order'      => 20,
	)
);
$inactive_shop = DoughBoss_Locations::create(
	array(
		'name'       => 'Inactive Integration Shop',
		'slug'       => 'inactive-integration-shop',
		'is_active'  => 0,
		'sort_order' => 30,
	)
);
db_integration_assert( $shop_b > 0 && $inactive_shop > 0, 'active shop B and inactive location fixtures are stored' );

$event_date = wp_date( 'Y-m-d', current_time( 'timestamp' ) + ( 30 * DAY_IN_SECONDS ) );
$catering_params = array(
	'customer_name'  => 'Catering Integration Customer',
	'customer_email' => 'catering.customer@example.invalid',
	'customer_phone' => '0400123456',
	'package_id'     => $published_package,
	'guest_count'    => 25,
	'order_type'     => 'delivery',
	'event_date'     => $event_date,
	'event_time'     => '18:30',
	'address'        => '22 Integration Street, Sydney',
	'dietary'        => 'One gluten-free meal',
	'notes'          => 'Use the rear loading entrance',
	'location_id'    => $shop_b,
);
$original_remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
$mail_start = count( $GLOBALS['db_catering_mail'] );
add_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10, 2 );

$fixture_ip = 10;
foreach ( $invalid_packages as $fixture_name => $invalid_package_id ) {
	$_SERVER['REMOTE_ADDR'] = '192.0.2.' . $fixture_ip++;
	$invalid_params = $catering_params;
	$invalid_params['package_id'] = $invalid_package_id;
	$rows_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$catering_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$mail_before = count( $GLOBALS['db_catering_mail'] );
	$invalid_submit = $rest_controller->create_catering_enquiry( db_integration_rest_request( '/doughboss/v1/catering/enquiry', $invalid_params ) );
	db_integration_same( 'doughboss_catering_package_unavailable', is_wp_error( $invalid_submit ) ? $invalid_submit->get_error_code() : '', $fixture_name . ' positive package is rejected by POST capture' );
	db_integration_same( $rows_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$catering_table}" ), $fixture_name . ' package rejection stores no enquiry' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	db_integration_same( $mail_before, count( $GLOBALS['db_catering_mail'] ), $fixture_name . ' package rejection sends no mail' );
}

$race_quote_request = new WP_REST_Request( 'GET', '/doughboss/v1/catering/quote' );
$race_quote_request->set_param( 'package_id', $race_package );
$race_quote_request->set_param( 'guest_count', 10 );
$race_quote_request->set_param( 'order_type', 'pickup' );
$race_quote = $rest_controller->get_catering_quote( $race_quote_request );
db_integration_assert( $race_quote instanceof WP_REST_Response, 'published package can be quoted before an unpublish race' );
wp_update_post( array( 'ID' => $race_package, 'post_status' => 'draft' ) );
$_SERVER['REMOTE_ADDR'] = '192.0.2.' . $fixture_ip++;
$race_params = $catering_params;
$race_params['package_id'] = $race_package;
$rows_before_race = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$catering_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$mail_before_race = count( $GLOBALS['db_catering_mail'] );
$race_submit = $rest_controller->create_catering_enquiry( db_integration_rest_request( '/doughboss/v1/catering/enquiry', $race_params ) );
db_integration_same( 'doughboss_catering_package_unavailable', is_wp_error( $race_submit ) ? $race_submit->get_error_code() : '', 'package unpublished between quote and submit is rejected' );
db_integration_same( $rows_before_race, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$catering_table}" ), 'unpublish race stores no enquiry' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
db_integration_same( $mail_before_race, count( $GLOBALS['db_catering_mail'] ), 'unpublish race sends no mail' );

$_SERVER['REMOTE_ADDR'] = '192.0.2.' . $fixture_ip++;
$mail_before_shop_b = count( $GLOBALS['db_catering_mail'] );
$shop_b_submit = $rest_controller->create_catering_enquiry( db_integration_rest_request( '/doughboss/v1/catering/enquiry', $catering_params ) );
$shop_b_data = $shop_b_submit instanceof WP_REST_Response ? $shop_b_submit->get_data() : array();
$shop_b_row = ! empty( $shop_b_data['enquiry_number'] ) ? DoughBoss_Catering::get_by_number( $shop_b_data['enquiry_number'] ) : null;
db_integration_assert( ! empty( $shop_b_data['success'] ) && is_array( $shop_b_row ), 'valid published-package enquiry is saved' );
db_integration_same( $shop_b, is_array( $shop_b_row ) ? (int) $shop_b_row['location_id'] : 0, 'selected shop B is persisted as shop B' );
db_integration_same( $mail_before_shop_b + 2, count( $GLOBALS['db_catering_mail'] ), 'valid enquiry emits exactly customer and configured-staff mail' );
$customer_mail = db_integration_catering_mail_to( $catering_params['customer_email'] );
$staff_mail = db_integration_catering_mail_to( $settings['catering_email'] );
db_integration_assert( ! empty( $customer_mail ) && false !== strpos( (string) $customer_mail['message'], 'Thanks for your catering enquiry' ), 'customer acknowledgement wording is preserved' );
db_integration_same( $settings['catering_email'], isset( $staff_mail['to'] ) ? $staff_mail['to'] : '', 'staff notification uses only the configured catering recipient' );
$staff_message = isset( $staff_mail['message'] ) ? (string) $staff_mail['message'] : '';
$expected_staff_fields = array(
	'Reference: ' . $shop_b_row['enquiry_number'],
	'Location: Integration Shop B (ID ' . $shop_b . ')',
	'Package: Published Integration Feast',
	'Guests: 25',
	'Event date: ' . $event_date,
	'Event time: 18:30',
	'Order type: Delivery',
	'Address: 22 Integration Street, Sydney',
	'Customer name: Catering Integration Customer',
	'Customer email: catering.customer@example.invalid',
	'Customer phone: 0400123456',
	'Dietary notes: One gluten-free meal',
	'Notes: Use the rear loading entrance',
	'Indicative subtotal: AUD $302.50',
	'Indicative total: AUD $302.50',
	'Indicative deposit:',
);
$staff_has_fields = true;
foreach ( $expected_staff_fields as $expected_staff_field ) {
	$staff_has_fields = $staff_has_fields && false !== strpos( $staff_message, $expected_staff_field );
}
db_integration_assert( $staff_has_fields, 'staff notification contains saved routing, event, contact, notes and indicative pricing fields' );
db_integration_assert( empty( $staff_mail['headers'] ), 'staff notification adds no PII-bearing headers' );

foreach ( array( 'inactive' => $inactive_shop, 'missing' => 987654321 ) as $fixture_name => $invalid_location_id ) {
	$_SERVER['REMOTE_ADDR'] = '192.0.2.' . $fixture_ip++;
	$invalid_location_params = $catering_params;
	$invalid_location_params['location_id'] = $invalid_location_id;
	$rows_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$catering_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$mail_before = count( $GLOBALS['db_catering_mail'] );
	$invalid_location_submit = $rest_controller->create_catering_enquiry( db_integration_rest_request( '/doughboss/v1/catering/enquiry', $invalid_location_params ) );
	db_integration_same( 'doughboss_catering_location_unavailable', is_wp_error( $invalid_location_submit ) ? $invalid_location_submit->get_error_code() : '', 'explicit positive ' . $fixture_name . ' location is rejected' );
	db_integration_same( 400, is_wp_error( $invalid_location_submit ) ? (int) $invalid_location_submit->get_error_data()['status'] : 0, $fixture_name . ' location rejection is a validation response' );
	db_integration_same( $rows_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$catering_table}" ), $fixture_name . ' location rejection stores no enquiry' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	db_integration_same( $mail_before, count( $GLOBALS['db_catering_mail'] ), $fixture_name . ' location rejection sends no mail' );
}

$_SERVER['REMOTE_ADDR'] = '192.0.2.' . $fixture_ip++;
$legacy_params = $catering_params;
$legacy_params['package_id'] = 0;
unset( $legacy_params['location_id'] );
$legacy_params['customer_email'] = 'legacy.catering@example.invalid';
$legacy_params['customer_phone'] = '';
$legacy_params['event_time'] = '';
$legacy_params['address'] = '';
$legacy_params['dietary'] = '';
$legacy_params['notes'] = '';
$legacy_submit = $rest_controller->create_catering_enquiry( db_integration_rest_request( '/doughboss/v1/catering/enquiry', $legacy_params ) );
$legacy_data = $legacy_submit instanceof WP_REST_Response ? $legacy_submit->get_data() : array();
$legacy_row = ! empty( $legacy_data['enquiry_number'] ) ? DoughBoss_Catering::get_by_number( $legacy_data['enquiry_number'] ) : null;
db_integration_same( DoughBoss_Locations::default_id(), is_array( $legacy_row ) ? (int) $legacy_row['location_id'] : 0, 'omitted legacy location still routes to the configured default' );
db_integration_same( 0, is_array( $legacy_row ) ? (int) $legacy_row['package_id'] : -1, 'explicit custom package zero is persisted as custom' );
$legacy_staff_mail = db_integration_catering_mail_to( $settings['catering_email'] );
foreach ( array_reverse( $GLOBALS['db_catering_mail'] ) as $candidate_mail ) {
	if ( isset( $candidate_mail['to'] ) && $settings['catering_email'] === $candidate_mail['to'] ) {
		$legacy_staff_mail = $candidate_mail;
		break;
	}
}
$legacy_staff_message = isset( $legacy_staff_mail['message'] ) ? (string) $legacy_staff_mail['message'] : '';
db_integration_assert(
	false !== strpos( $legacy_staff_message, 'Package: Custom' )
	&& false !== strpos( $legacy_staff_message, 'Event time: To be confirmed' )
	&& false !== strpos( $legacy_staff_message, 'Address: Not provided' )
	&& false !== strpos( $legacy_staff_message, 'Customer phone: Not provided' )
	&& false !== strpos( $legacy_staff_message, 'Dietary notes: Not provided' )
	&& false !== strpos( $legacy_staff_message, 'Notes: Not provided' ),
	'custom enquiry staff mail uses honest optional-field fallbacks'
);

// An installation with no location rows keeps the historical location_id 0.
$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'doughboss_locations' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$_SERVER['REMOTE_ADDR'] = '192.0.2.' . $fixture_ip++;
$no_location_params = $legacy_params;
$no_location_params['customer_email'] = 'no.location.catering@example.invalid';
$no_location_submit = $rest_controller->create_catering_enquiry( db_integration_rest_request( '/doughboss/v1/catering/enquiry', $no_location_params ) );
$no_location_data = $no_location_submit instanceof WP_REST_Response ? $no_location_submit->get_data() : array();
$no_location_row = ! empty( $no_location_data['enquiry_number'] ) ? DoughBoss_Catering::get_by_number( $no_location_data['enquiry_number'] ) : null;
db_integration_same( 0, is_array( $no_location_row ) ? (int) $no_location_row['location_id'] : -1, 'no-location installation preserves legacy location zero' );
$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$GLOBALS['db_catering_mail_result'] = false;
$_SERVER['REMOTE_ADDR'] = '192.0.2.' . $fixture_ip++;
$failed_mail_params = $legacy_params;
$failed_mail_params['customer_email'] = 'failed.mail.catering@example.invalid';
$rows_before_failed_mail = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$catering_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$failed_mail_submit = $rest_controller->create_catering_enquiry( db_integration_rest_request( '/doughboss/v1/catering/enquiry', $failed_mail_params ) );
$failed_mail_data = $failed_mail_submit instanceof WP_REST_Response ? $failed_mail_submit->get_data() : array();
db_integration_same( true, ! empty( $failed_mail_data['success'] ), 'false wp_mail result does not undo successful enquiry response' );
db_integration_same( $rows_before_failed_mail + 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$catering_table}" ), 'false wp_mail result retains the saved enquiry row' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$GLOBALS['db_catering_mail_result'] = true;
remove_filter( 'pre_wp_mail', 'db_integration_capture_catering_mail', 10 );
db_integration_assert( count( $GLOBALS['db_catering_mail'] ) > $mail_start, 'all catering fixture mail was intercepted locally' );
if ( null === $original_remote_addr ) {
	unset( $_SERVER['REMOTE_ADDR'] );
} else {
	$_SERVER['REMOTE_ADDR'] = $original_remote_addr;
}

// Exercise the new read-only hours adapter against real WP/MySQL, not its unit shim.
$original_hours = DoughBoss_Locations::weekly_hours( 1 );
$posts_before_hours = $GLOBALS['db_square_post_count'];
$hours_saved = DoughBoss_Locations::save_weekly_hours( 1, array( 'tue' => '09:00-17:00' ) );
db_integration_same( true, $hours_saved, 'pickup-hour fixture persists through the existing admin adapter' );
$status_location = DoughBoss_Locations::get( 1 );
$status_location->timezone = 'Australia/Sydney';
$status_location->pickup_enabled = 1;
$status_location->is_active = 1;
$pickup_observation = DoughBoss_Locations::pickup_status( $status_location, new DateTimeImmutable( '2026-09-08T01:00:00Z' ) );
db_integration_same( 'open', $pickup_observation['state'], 'stored weekday hours produce the correct Sydney pickup status' );
$public_locations = $rest_controller->get_locations();
db_integration_assert( $public_locations instanceof WP_REST_Response && isset( $public_locations->get_data()[0]['pickup_status']['observed_at_utc'] ), 'public locations expose timestamped schedule evidence' );
$location_headers = $public_locations->get_headers();
db_integration_same( 'no-store, max-age=0', isset( $location_headers['Cache-Control'] ) ? $location_headers['Cache-Control'] : '', 'public pickup observations cannot be cached beyond their validity' );
db_integration_same( $posts_before_hours, $GLOBALS['db_square_post_count'], 'pickup-status observation performs no provider POST' );
DoughBoss_Locations::save_weekly_hours( 1, $original_hours );

// Multi-shop Square binding matrix: synthetic provider and disposable database only.
$binding_original_settings = DoughBoss_Settings::all();
$binding_settings = array_merge( $settings, array(
	'payments_enabled' => 1, 'payment_gateway' => 'square', 'square_mode' => 'test',
	'square_test_location_id' => 'LUNUSEDGLOBALLOCATION',
	'square_test_location_map' => array( 'revesby' => 'LINTEGRATIONLOCATION', 'bankstown' => 'LINTEGRATIONBANKSTOWN', 'roselands' => 'LINTEGRATIONROSELANDS' ),
) );
update_option( DoughBoss_Settings::OPTION_KEY, $binding_settings, false );
$binding_original_location = DoughBoss_Locations::get( 1 );
$wpdb->update( $wpdb->prefix . 'doughboss_locations', array( 'slug' => 'revesby', 'is_active' => 1 ), array( 'id' => 1 ) );
$binding_shop_ids = array();
foreach ( array( 'bankstown', 'roselands' ) as $binding_shop ) {
	$binding_shop_ids[ $binding_shop ] = DoughBoss_Locations::create( array( 'name' => ucfirst( $binding_shop ), 'slug' => $binding_shop, 'is_active' => 1, 'pickup_enabled' => 1, 'online_payment_enabled' => 1 ) );
	db_integration_assert( $binding_shop_ids[ $binding_shop ] > 0, 'synthetic ' . $binding_shop . ' native shop is persisted' );
}
$GLOBALS['db_square_http_mode'] = 'success';
// A v2 map is sufficient for gateway transport; an unrelated legacy global ID
// may be absent or malformed. Legacy callers retain their original gate.
foreach ( array( '', 'abc', '<invalid-global>' ) as $binding_global_value ) {
	$mapped_only = $binding_settings;
	$mapped_only['square_test_location_id'] = $binding_global_value;
	update_option( DoughBoss_Settings::OPTION_KEY, $mapped_only, false );
	$seed = 'mapped-only-' . hash( 'sha256', $binding_global_value );
	$meta = db_integration_square_metadata( $seed, $seed . '-key', $seed . '-cart' );
	$posts = $GLOBALS['db_square_post_count'];
	$paid = DoughBoss_Square::create_payment_intent( 1250, 'AUD', $meta );
	db_integration_assert( ! is_wp_error( $paid ), 'valid mapped shop can dispatch with absent or malformed legacy global ID' );
	db_integration_same( $posts + 1, $GLOBALS['db_square_post_count'], 'mapped-only creation performs exactly one synthetic POST' );
	if ( is_wp_error( $paid ) ) { continue; }
	$attempt = DoughBoss_Payment_Attempts::find( $paid['attempt_id'] );
	$saved = DoughBoss_Payment_Attempts::metadata( $attempt );
	db_integration_same( 'LINTEGRATIONLOCATION', $saved['square_location_id'], 'mapped-only attempt retains correct provider location' );
	$last_body = end( $GLOBALS['db_square_post_bodies'] );
	db_integration_same( 'LINTEGRATIONLOCATION', $last_body['location_id'], 'mapped-only provider dispatch uses exact shop mapping' );
	$verified = DoughBoss_Square::retrieve_payment_intent( $paid['id'] );
	db_integration_assert( ! is_wp_error( $verified ) && 'succeeded' === $verified['status'], 'mapped-only payment verification does not consult legacy global ID' );
	$replayed = DoughBoss_Square::create_payment_intent( 1250, 'AUD', $meta );
	db_integration_assert( ! is_wp_error( $replayed ) && $paid['id'] === $replayed['id'], 'mapped-only retry replays same verified payment' );
	db_integration_same( $posts + 1, $GLOBALS['db_square_post_count'], 'mapped-only replay and verification never POST a second charge' );
}
update_option( DoughBoss_Settings::OPTION_KEY, $binding_settings, false );
$binding_posts_before = $GLOBALS['db_square_post_count'];
$binding_rows_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}doughboss_payment_attempts" );
foreach ( array( array(), array( 'revesby' => 'LDUPLICATE', 'bankstown' => 'LDUPLICATE' ), array( 'revesby' => array( 'LINVALID' ) ), array( 'revesby' => 'abc' ) ) as $binding_bad_map ) {
	$changed = $binding_settings;
	$changed['square_test_location_map'] = $binding_bad_map;
	update_option( DoughBoss_Settings::OPTION_KEY, $changed, false );
	$denied = DoughBoss_Square::create_payment_intent( 1200, 'AUD', db_integration_square_metadata( 'binding-invalid-map', 'binding-invalid-map-key', 'binding-invalid-map-cart' ) );
	db_integration_assert( is_wp_error( $denied ), 'invalid shop map fails closed despite valid global ID' );
}
update_option( DoughBoss_Settings::OPTION_KEY, $binding_settings, false );
foreach ( array( array( 'slug' => 'planned', 'is_active' => 1 ), array( 'slug' => 'revesby', 'is_active' => 0 ) ) as $binding_bad_shop ) {
	$wpdb->update( $wpdb->prefix . 'doughboss_locations', $binding_bad_shop, array( 'id' => 1 ) );
	db_integration_assert( is_wp_error( DoughBoss_Square::create_payment_intent( 1200, 'AUD', db_integration_square_metadata( 'binding-invalid-shop', 'binding-invalid-shop-key', 'binding-invalid-shop-cart' ) ) ), 'private or inactive native shop cannot dispatch' );
}
$wpdb->update( $wpdb->prefix . 'doughboss_locations', array( 'slug' => 'revesby', 'is_active' => 1 ), array( 'id' => 1 ) );
db_integration_assert( is_wp_error( DoughBoss_Square::create_payment_intent( 1200, 'AUD', db_integration_square_metadata( 'binding-unknown-shop', 'binding-unknown-shop-key', 'binding-unknown-shop-cart', array( 'location_id' => 999999 ) ) ) ), 'unknown native shop cannot dispatch' );
db_integration_same( $binding_posts_before, $GLOBALS['db_square_post_count'], 'invalid binding matrix makes no provider POST' );
db_integration_same( $binding_rows_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}doughboss_payment_attempts" ), 'invalid bindings do not allocate attempts' );

foreach ( $binding_shop_ids as $binding_shop => $binding_shop_id ) {
	$meta = db_integration_square_metadata( 'binding-' . $binding_shop, 'binding-key-' . $binding_shop, 'binding-cart-' . $binding_shop, array( 'location_id' => $binding_shop_id, 'square_location_id' => 'LSPOOFED', 'square_shop_slug' => 'revesby', 'square_mode' => 'live' ) );
	$paid = DoughBoss_Square::create_payment_intent( 1300, 'AUD', $meta );
	db_integration_assert( ! is_wp_error( $paid ), 'explicit ' . $binding_shop . ' map dispatches successfully' );
	if ( is_wp_error( $paid ) ) { continue; }
	$attempt = DoughBoss_Payment_Attempts::find( $paid['attempt_id'] );
	$saved = DoughBoss_Payment_Attempts::metadata( $attempt );
	db_integration_same( $binding_shop, isset( $saved['square_shop_slug'] ) ? $saved['square_shop_slug'] : '', 'caller cannot override native shop slug' );
	db_integration_same( $binding_settings['square_test_location_map'][ $binding_shop ], isset( $saved['square_location_id'] ) ? $saved['square_location_id'] : '', 'caller cannot override native shop mapping' );
	db_integration_same( 'test', isset( $saved['square_mode'] ) ? $saved['square_mode'] : '', 'caller cannot override server mode' );
	$reads = $GLOBALS['db_square_get_count'];
	$posts = $GLOBALS['db_square_post_count'];
	$changed = $binding_settings;
	$changed['square_test_location_map'][ $binding_shop ] = 'LCHANGEDSHOPLOCATION';
	update_option( DoughBoss_Settings::OPTION_KEY, $changed, false );
	db_integration_assert( is_wp_error( DoughBoss_Square::create_payment_intent( 1300, 'AUD', $meta ) ), 'map drift blocks payment retry' );
	db_integration_assert( is_wp_error( DoughBoss_Square::retrieve_payment_intent( $paid['id'] ) ), 'map drift blocks verification' );
	db_integration_same( $reads, $GLOBALS['db_square_get_count'], 'map drift stops before GET' );
	db_integration_same( $posts, $GLOBALS['db_square_post_count'], 'map drift stops before POST' );
	update_option( DoughBoss_Settings::OPTION_KEY, $binding_settings, false );
	$verified = DoughBoss_Square::retrieve_payment_intent( $paid['id'] );
	db_integration_assert( ! is_wp_error( $verified ) && 'succeeded' === $verified['status'], 'restoring exact binding permits reconciliation' );
	$legacy_metadata = $saved;
	unset( $legacy_metadata['square_shop_slug'] );
	$legacy_json = wp_json_encode( $legacy_metadata );
	$wpdb->update( $wpdb->prefix . 'doughboss_payment_attempts', array( 'safe_metadata_json' => $legacy_json ), array( 'id' => (int) $attempt['id'] ) );
	$reads = $GLOBALS['db_square_get_count'];
	db_integration_assert( is_wp_error( DoughBoss_Square::retrieve_payment_intent( $paid['id'] ) ), 'v2 record without immutable shop slug cannot be automatically verified' );
	db_integration_same( $reads, $GLOBALS['db_square_get_count'], 'unbound historical v2 record stops before provider GET' );
	$preserved_attempt = DoughBoss_Payment_Attempts::find( (int) $attempt['id'] );
	db_integration_same( $legacy_json, $preserved_attempt['safe_metadata_json'], 'unbound historical metadata is preserved without backfill' );
	$wpdb->update( $wpdb->prefix . 'doughboss_payment_attempts', array( 'safe_metadata_json' => $attempt['safe_metadata_json'] ), array( 'id' => (int) $attempt['id'] ) );
	$wpdb->update( $wpdb->prefix . 'doughboss_locations', array( 'slug' => 'planned' ), array( 'id' => $binding_shop_id ) );
	$reads = $GLOBALS['db_square_get_count'];
	db_integration_assert( is_wp_error( DoughBoss_Square::retrieve_payment_intent( $paid['id'] ) ), 'native slug drift cannot reuse provider binding' );
	db_integration_same( $reads, $GLOBALS['db_square_get_count'], 'native slug drift stops before GET' );
	$wpdb->update( $wpdb->prefix . 'doughboss_locations', array( 'slug' => $binding_shop ), array( 'id' => $binding_shop_id ) );
}

$dispatch_meta = db_integration_square_metadata( 'binding-dispatch-drift', 'binding-dispatch-drift-key', 'binding-dispatch-drift-cart' );
$GLOBALS['db_square_binding_drift_once'] = true;
add_filter( 'query', 'db_integration_square_binding_drift' );
$posts = $GLOBALS['db_square_post_count'];
$denied = DoughBoss_Square::create_payment_intent( 1400, 'AUD', $dispatch_meta );
remove_filter( 'query', 'db_integration_square_binding_drift' );
db_integration_same( false, $GLOBALS['db_square_binding_drift_once'], 'fixture changes map at irreversible dispatch claim' );
db_integration_assert( is_wp_error( $denied ), 'dispatch-time map drift is denied' );
db_integration_same( $posts, $GLOBALS['db_square_post_count'], 'dispatch-time drift makes no POST' );
update_option( DoughBoss_Settings::OPTION_KEY, $binding_settings, false );
db_integration_assert( is_wp_error( DoughBoss_Square::create_payment_intent( 1400, 'AUD', $dispatch_meta ) ), 'claimed drift cannot redispatch on retry' );
db_integration_same( $posts, $GLOBALS['db_square_post_count'], 'claimed drift retry makes no POST' );

$GLOBALS['db_square_http_mode'] = 'location_mismatch';
$wrong_meta = db_integration_square_metadata( 'binding-provider-wrong', 'binding-provider-wrong-key', 'binding-provider-wrong-cart' );
db_integration_assert( is_wp_error( DoughBoss_Square::create_payment_intent( 1500, 'AUD', $wrong_meta ) ), 'matching money/reference but wrong provider location is denied' );
$posts = $GLOBALS['db_square_post_count'];
$GLOBALS['db_square_http_mode'] = 'success';
db_integration_assert( is_wp_error( DoughBoss_Square::create_payment_intent( 1500, 'AUD', $wrong_meta ) ), 'wrong provider location remains held on replay' );
db_integration_same( $posts, $GLOBALS['db_square_post_count'], 'wrong provider location never charges twice' );

// Approved Square dispatch must bind approval and attempt before provider HTTP.
add_action( 'doughboss_order_created', 'db_integration_capture_order_created', 999, 2 );
add_filter( 'pre_wp_mail', 'db_integration_block_mail', 999 );
$approved_square_smoke = db_integration_approved_square_fixture( 'smoke' );
db_integration_assert( is_array( $approved_square_smoke ), 'approved Square fixture retains its immutable snapshot' );
if ( is_array( $approved_square_smoke ) ) {
	$posts = $GLOBALS['db_square_post_count'];
	$paid = DoughBoss_Square::create_payment_intent( 1400, 'AUD', $approved_square_smoke['metadata'] );
	$approved_square_payment = $paid;
	db_integration_assert( ! is_wp_error( $paid ), 'approved Square dispatch accepts matching capability and retained request' . ( is_wp_error( $paid ) ? ' [' . $paid->get_error_code() . ']' : '' ) );
	db_integration_same( $posts + 1, $GLOBALS['db_square_post_count'], 'approved Square dispatch performs one synthetic POST' );
	$linked = DoughBoss_Preorder_Approval::get( $approved_square_smoke['request_id'] );
	if ( ! is_wp_error( $paid ) ) {
		db_integration_same( (int) $paid['attempt_id'], (int) $linked['payment_attempt_id'], 'approved Square dispatch links exact durable attempt' );
		$smoke_attempt = db_integration_approved_square_attempt( $approved_square_smoke );
		$smoke_meta = DoughBoss_Payment_Attempts::metadata( $smoke_attempt );
		db_integration_same( (int) $approved_square_smoke['request_id'], (int) $smoke_meta['approval_id'], 'approved Square safe metadata retains exact approval id' );
		db_integration_same( $approved_square_smoke['approval']['request_fingerprint'], $smoke_meta['approval_fingerprint'], 'approved Square safe metadata retains request fingerprint' );
		foreach ( array( 'preorder_payment_token', 'source_id', 'verification_token' ) as $secret_field ) {
			db_integration_same( false, isset( $smoke_meta[ $secret_field ] ), 'approved Square never persists ' . $secret_field );
		}
	}
}

$square_approval_table = $wpdb->prefix . 'doughboss_preorder_approvals';
$square_snapshot_table = $wpdb->prefix . 'doughboss_checkout_snapshots';
foreach ( array( 'bad-token', 'no-token', 'pending', 'rejected', 'expired', 'near-cutoff', 'revision', 'fingerprint', 'meta-request', 'meta-revision', 'meta-fingerprint', 'meta-binding', 'amount', 'currency', 'native-location', 'snapshot-contact', 'snapshot-lines', 'snapshot-binding' ) as $case ) {
	$fixture = db_integration_approved_square_fixture( 'denied-' . $case );
	db_integration_assert( is_array( $fixture ), 'approved Square denial fixture prepares ' . $case );
	if ( ! is_array( $fixture ) ) {
		continue;
	}
	$meta = $fixture['metadata'];
	$amount = 1400;
	$currency = 'AUD';
	if ( 'bad-token' === $case || 'no-token' === $case ) {
		$meta['preorder_payment_token'] = 'no-token' === $case ? '' : str_repeat( 'f', 64 );
	} elseif ( in_array( $case, array( 'pending', 'rejected' ), true ) ) {
		$wpdb->update( $square_approval_table, array( 'state' => $case ), array( 'request_order_id' => $fixture['request_id'] ) );
	} elseif ( in_array( $case, array( 'expired', 'near-cutoff' ), true ) ) {
		$meta['preorder_payment_token'] = db_integration_set_preorder_expiry( $fixture['request_id'], gmdate( 'Y-m-d H:i:s', time() + ( 'expired' === $case ? -60 : 20 * MINUTE_IN_SECONDS ) ) );
	} elseif ( 'revision' === $case ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}doughboss_orders SET version = version + 1 WHERE id = %d", $fixture['request_id'] ) );
	} elseif ( 'fingerprint' === $case ) {
		$wpdb->update( $wpdb->prefix . 'doughboss_orders', array( 'notes' => 'Synthetic post-approval drift' ), array( 'id' => $fixture['request_id'] ) );
	} elseif ( 'meta-request' === $case ) {
		$meta['source_request_id'] = 999999;
	} elseif ( 'meta-revision' === $case ) {
		++$meta['approved_revision'];
	} elseif ( 'meta-fingerprint' === $case ) {
		$meta['request_fingerprint'] = str_repeat( 'a', 64 );
	} elseif ( 'meta-binding' === $case ) {
		$meta['binding_hash'] = str_repeat( 'b', 64 );
	} elseif ( 'amount' === $case ) {
		$amount = 1399;
	} elseif ( 'currency' === $case ) {
		$currency = 'USD';
	} elseif ( 'native-location' === $case ) {
		$meta['location_id'] = $binding_shop_ids['bankstown'];
	} else {
		$payload = $fixture['snapshot']['payload'];
		if ( 'snapshot-contact' === $case ) {
			$payload['order']['customer_email'] = 'tampered@example.invalid';
		} elseif ( 'snapshot-lines' === $case ) {
			$payload['lines'][0]['name'] = 'Tampered synthetic item';
		} else {
			$payload['square_binding']['square_location_id'] = 'LTAMPERED';
		}
		$json = wp_json_encode( $payload );
		$wpdb->update( $square_snapshot_table, array( 'payload_json' => $json, 'payload_hash' => hash( 'sha256', $json ) ), array( 'checkout_key' => $fixture['approval']['payment_checkout_key'] ) );
	}
	$posts = $GLOBALS['db_square_post_count'];
	$denied = DoughBoss_Square::create_payment_intent( $amount, $currency, $meta );
	db_integration_assert( is_wp_error( $denied ), 'approved Square denies ' . $case );
	db_integration_same( $posts, $GLOBALS['db_square_post_count'], 'approved Square ' . $case . ' makes no provider POST' );
	$denied_attempt = db_integration_approved_square_attempt( $fixture );
	db_integration_assert( ! is_array( $denied_attempt ) || 'prepared' === $denied_attempt['status'], 'approved Square ' . $case . ' cannot claim irreversible dispatch' );
}

foreach ( array( 'web-context', 'omitted-context', 'changed-purpose' ) as $case ) {
	$fixture = db_integration_approved_square_fixture( 'immutable-' . $case );
	if ( ! is_array( $fixture ) ) {
		db_integration_assert( false, 'immutable Square ' . $case . ' fixture is available' );
		continue;
	}
	$denied_meta = $fixture['metadata'];
	$denied_meta['preorder_payment_token'] = str_repeat( 'f', 64 );
	$denied = DoughBoss_Square::create_payment_intent( 1400, 'AUD', $denied_meta );
	$prepared = db_integration_approved_square_attempt( $fixture );
	db_integration_assert( is_wp_error( $denied ) && is_array( $prepared ) && 'prepared' === $prepared['status'], 'bad capability leaves immutable approved attempt prepared for ' . $case );
	$spoof = $fixture['metadata'];
	if ( 'changed-purpose' === $case ) {
		$spoof['purpose'] = 'voucher';
	} else {
		unset( $spoof['preorder_payment_token'] );
		if ( 'web-context' === $case ) {
			$spoof['context'] = 'web';
		} else {
			unset( $spoof['context'] );
		}
	}
	$posts = $GLOBALS['db_square_post_count'];
	$changed = DoughBoss_Square::create_payment_intent( 1400, 'AUD', $spoof );
	db_integration_assert( is_wp_error( $changed ), 'immutable approved Square denies ' . $case . ' route substitution' );
	db_integration_same( $posts, $GLOBALS['db_square_post_count'], 'immutable approved Square ' . $case . ' cannot POST' );
	$after = db_integration_approved_square_attempt( $fixture );
	$unlinked = DoughBoss_Preorder_Approval::get( $fixture['request_id'] );
	db_integration_same( 'prepared', is_array( $after ) ? $after['status'] : '', 'immutable approved Square ' . $case . ' remains unclaimed' );
	db_integration_same( 0, (int) $unlinked['payment_attempt_id'], 'immutable approved Square ' . $case . ' remains unlinked' );
}

$rollback_fixture = db_integration_approved_square_fixture( 'atomic-link-rollback' );
if ( is_array( $rollback_fixture ) ) {
	$posts = $GLOBALS['db_square_post_count'];
	$GLOBALS['db_fail_square_approval_link_once'] = true;
	$square_errors_before = $wpdb->suppress_errors( true );
	add_filter( 'query', 'db_integration_fail_square_approval_link', 10 );
	$rollback_result = DoughBoss_Square::create_payment_intent( 1400, 'AUD', $rollback_fixture['metadata'] );
	remove_filter( 'query', 'db_integration_fail_square_approval_link', 10 );
	$wpdb->suppress_errors( $square_errors_before );
	db_integration_assert( is_wp_error( $rollback_result ) && ! $GLOBALS['db_fail_square_approval_link_once'], 'approval-link write failure is exercised and denied' );
	db_integration_same( $posts, $GLOBALS['db_square_post_count'], 'approval-link write failure sends no Square POST' );
	$rollback_attempt = db_integration_approved_square_attempt( $rollback_fixture );
	db_integration_same( 'prepared', is_array( $rollback_attempt ) ? $rollback_attempt['status'] : '', 'failed approval link rolls dispatch claim back to prepared' );
	$rollback_approval = DoughBoss_Preorder_Approval::get( $rollback_fixture['request_id'] );
	db_integration_same( 0, (int) $rollback_approval['payment_attempt_id'], 'failed dispatch transaction retains no partial approval link' );
	$GLOBALS['db_square_approval_dispatch_observer'] = array( 'request_id' => $rollback_fixture['request_id'], 'checkout_key' => $rollback_fixture['approval']['payment_checkout_key'] );
	$rollback_retry = DoughBoss_Square::create_payment_intent( 1400, 'AUD', $rollback_fixture['metadata'] );
	$GLOBALS['db_square_approval_dispatch_observer'] = null;
	db_integration_assert( ! is_wp_error( $rollback_retry ), 'atomic rollback permits one later valid dispatch' );
	db_integration_same( $posts + 1, $GLOBALS['db_square_post_count'], 'atomic rollback retry performs one Square POST' );
}

$final_read_fixture = db_integration_approved_square_fixture( 'final-attempt-read-rollback' );
if ( is_array( $final_read_fixture ) ) {
	$posts = $GLOBALS['db_square_post_count'];
	$GLOBALS['db_square_final_read_armed'] = false;
	$GLOBALS['db_square_final_read_exercised'] = false;
	add_filter( 'query', 'db_integration_fail_square_final_attempt_read', 10 );
	$final_read_result = DoughBoss_Square::create_payment_intent( 1400, 'AUD', $final_read_fixture['metadata'] );
	remove_filter( 'query', 'db_integration_fail_square_final_attempt_read', 10 );
	db_integration_assert( is_wp_error( $final_read_result ) && $GLOBALS['db_square_final_read_exercised'], 'post-link attempt read failure is exercised and denied' );
	db_integration_same( $posts, $GLOBALS['db_square_post_count'], 'post-link attempt read failure sends no Square POST' );
	$rolled_back_attempt = db_integration_approved_square_attempt( $final_read_fixture );
	$rolled_back_approval = DoughBoss_Preorder_Approval::get( $final_read_fixture['request_id'] );
	db_integration_same( 'prepared', is_array( $rolled_back_attempt ) ? $rolled_back_attempt['status'] : '', 'post-link read failure rolls irreversible claim back' );
	db_integration_same( 0, (int) $rolled_back_approval['payment_attempt_id'], 'post-link read failure rolls approval link back' );
}

foreach ( array( 'initial', 'linked' ) as $commit_case ) {
	$fixture = db_integration_approved_square_fixture( 'commit-failure-' . $commit_case );
	if ( ! is_array( $fixture ) ) {
		db_integration_assert( false, 'Square COMMIT failure fixture is available' );
		continue;
	}
	if ( 'linked' === $commit_case ) {
		$started = DoughBoss_Square::create_payment_intent( 1400, 'AUD', $fixture['metadata'] );
		db_integration_assert( ! is_wp_error( $started ), 'linked COMMIT failure fixture has one existing dispatch' );
	}
	$posts = $GLOBALS['db_square_post_count'];
	$GLOBALS['db_fail_square_commit_once'] = true;
	$errors_before = $wpdb->suppress_errors( true );
	add_filter( 'query', 'db_integration_fail_square_commit', 10 );
	if ( 'linked' === $commit_case ) {
		$commit_result = DoughBoss_Preorder_Approval::claim_square_dispatch( $fixture['request_id'], $fixture['token'], $started['attempt_id'] );
	} else {
		$commit_result = DoughBoss_Square::create_payment_intent( 1400, 'AUD', $fixture['metadata'] );
	}
	remove_filter( 'query', 'db_integration_fail_square_commit', 10 );
	$wpdb->suppress_errors( $errors_before );
	db_integration_assert( is_wp_error( $commit_result ) && ! $GLOBALS['db_fail_square_commit_once'], 'Square ' . $commit_case . ' COMMIT failure is exercised and denied' );
	db_integration_same( $posts, $GLOBALS['db_square_post_count'], 'Square ' . $commit_case . ' COMMIT failure sends no additional POST' );
	$attempt = db_integration_approved_square_attempt( $fixture );
	$approval = DoughBoss_Preorder_Approval::get( $fixture['request_id'] );
	$transaction_open = (int) $wpdb->get_var( 'SELECT @@in_transaction' );
	db_integration_same( 0, $transaction_open, 'Square ' . $commit_case . ' failed COMMIT leaves no open transaction' );
	db_integration_assert( db_integration_square_rows_unlocked( $fixture['request_id'], $attempt['id'] ), 'Square ' . $commit_case . ' failed COMMIT releases rows for a second connection' );
	if ( 'initial' === $commit_case ) {
		db_integration_same( 'prepared', $attempt['status'], 'failed initial COMMIT restores prepared attempt' );
		db_integration_same( 0, (int) $approval['payment_attempt_id'], 'failed initial COMMIT restores unlinked approval' );
	}
	// Keep a deliberately failing pre-fix run from contaminating later cases.
	if ( $transaction_open ) {
		$wpdb->query( 'ROLLBACK' );
	}
}

$unknown_fixture = db_integration_approved_square_fixture( 'unknown-outcome' );
if ( is_array( $unknown_fixture ) ) {
	$posts = $GLOBALS['db_square_post_count'];
	$GLOBALS['db_square_http_mode'] = 'network_error';
	$unknown = DoughBoss_Square::create_payment_intent( 1400, 'AUD', $unknown_fixture['metadata'] );
	$GLOBALS['db_square_http_mode'] = 'success';
	db_integration_assert( is_wp_error( $unknown ), 'approved Square ambiguous transport remains unpaid and held' );
	db_integration_same( $posts + 1, $GLOBALS['db_square_post_count'], 'approved Square unknown outcome dispatched once' );
	$unknown_replay = DoughBoss_Square::create_payment_intent( 1400, 'AUD', $unknown_fixture['metadata'] );
	db_integration_assert( is_wp_error( $unknown_replay ), 'approved Square unknown outcome cannot redispatch' );
	db_integration_same( $posts + 1, $GLOBALS['db_square_post_count'], 'approved Square unknown replay cannot double-charge' );
	db_integration_same( 0, DoughBoss_Order::find_id_by_source_request( $unknown_fixture['request_id'] ), 'approved Square unknown outcome creates no kitchen order' );
}

if ( is_array( $approved_square_smoke ) && ! is_wp_error( $approved_square_payment ) && isset( $approved_square_payment['id'] ) ) {
	$fulfillment = array_merge( $approved_square_smoke['order_data'], array( 'source_request_id' => $approved_square_smoke['request_id'], 'payment_method' => 'square', 'payment_status' => 'paid', 'payment_intent_id' => $approved_square_payment['id'] ) );
	$unverified_release = DoughBoss_Order::create( $fulfillment, $approved_square_smoke['lines'] );
	db_integration_assert( is_wp_error( $unverified_release ), 'Square create response alone cannot release approved request without durable verification' );
	db_integration_same( 0, DoughBoss_Order::find_id_by_source_request( $approved_square_smoke['request_id'] ), 'unverified Square payment cannot create kitchen order' );
	$verified = DoughBoss_Square::retrieve_payment_intent( $approved_square_payment['id'] );
	db_integration_assert( ! is_wp_error( $verified ) && 'succeeded' === $verified['status'], 'approved Square fulfillment requires provider-verified payment' );
	$hooks = count( $GLOBALS['db_order_created_events'] );
	foreach ( array( 'unpaid', 'forged-reference', 'wrong-total', 'wrong-location', 'wrong-contact', 'wrong-lines', 'different-attempt' ) as $case ) {
		$wrong = $fulfillment;
		$wrong_lines = $approved_square_smoke['lines'];
		$linked_approval = DoughBoss_Preorder_Approval::get( $approved_square_smoke['request_id'] );
		if ( 'unpaid' === $case ) {
			$wrong['payment_status'] = 'unpaid';
		} elseif ( 'forged-reference' === $case ) {
			$wrong['payment_intent_id'] = 'sqpayFORGEDREFERENCE';
		} elseif ( 'wrong-total' === $case ) {
			$wrong['total'] = 15.00;
		} elseif ( 'wrong-location' === $case ) {
			$wrong['location_id'] = $binding_shop_ids['bankstown'];
		} elseif ( 'wrong-contact' === $case ) {
			$wrong['customer_phone'] = '0400000098';
		} elseif ( 'wrong-lines' === $case ) {
			$wrong_lines[0]['name'] = 'Unapproved synthetic replacement';
		} else {
			$wpdb->update( $square_approval_table, array( 'payment_attempt_id' => (int) $linked_approval['payment_attempt_id'] + 100000 ), array( 'request_order_id' => $approved_square_smoke['request_id'] ) );
		}
		$denied = DoughBoss_Order::create( $wrong, $wrong_lines );
		if ( 'different-attempt' === $case ) {
			$wpdb->update( $square_approval_table, array( 'payment_attempt_id' => $linked_approval['payment_attempt_id'] ), array( 'request_order_id' => $approved_square_smoke['request_id'] ) );
		}
		db_integration_assert( is_wp_error( $denied ), 'approved Square fulfillment denies ' . $case );
		db_integration_same( 0, DoughBoss_Order::find_id_by_source_request( $approved_square_smoke['request_id'] ), 'denied Square ' . $case . ' leaves no partial fulfillment order' );
		db_integration_same( $hooks, count( $GLOBALS['db_order_created_events'] ), 'denied Square ' . $case . ' fires no kitchen hook' );
	}
	$square_map_before_release = DoughBoss_Settings::all();
	$square_map_changed = $square_map_before_release;
	$square_map_changed['square_test_location_map']['revesby'] = 'LCHANGEDAFTERVERIFICATION';
	update_option( DoughBoss_Settings::OPTION_KEY, $square_map_changed, false );
	db_integration_assert( is_wp_error( DoughBoss_Order::create( $fulfillment, $approved_square_smoke['lines'] ) ), 'approved Square map drift after verification blocks kitchen release' );
	db_integration_same( 0, DoughBoss_Order::find_id_by_source_request( $approved_square_smoke['request_id'] ), 'post-verification map drift leaves no partial kitchen order' );
	update_option( DoughBoss_Settings::OPTION_KEY, $square_map_before_release, false );
	$wpdb->update( $square_approval_table, array( 'payment_token_expires_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'request_order_id' => $approved_square_smoke['request_id'] ) );
	$wpdb->update( $square_snapshot_table, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'checkout_key' => $approved_square_smoke['approval']['payment_checkout_key'] ) );
	db_integration_same( null, DoughBoss_Checkout_Snapshots::find( $approved_square_smoke['approval']['payment_checkout_key'] ), 'expired ordinary approved Square snapshot is hidden from ordinary reads' );
	db_integration_assert( is_array( DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( $approved_square_smoke['approval']['payment_checkout_key'] ) ), 'verified approved Square payment retains bounded immutable recovery snapshot' );
	$fulfilled = DoughBoss_Order::create( $fulfillment, $approved_square_smoke['lines'] );
	db_integration_assert( is_array( $fulfilled ) && empty( $fulfilled['replayed'] ), 'matching paid approved Square request creates one fulfillment order' );
	$square_errors_before_replay = $wpdb->suppress_errors( true );
	$replayed = DoughBoss_Order::create( $fulfillment, $approved_square_smoke['lines'] );
	$wpdb->suppress_errors( $square_errors_before_replay );
	db_integration_assert( is_array( $replayed ) && ! empty( $replayed['replayed'] ) && is_array( $fulfilled ) && $replayed['order_id'] === $fulfilled['order_id'], 'paid approved Square replay returns same fulfillment order' );
	db_integration_same( $hooks + 1, count( $GLOBALS['db_order_created_events'] ), 'paid approved Square release and replay fire exactly one kitchen hook' );
	$released = DoughBoss_Preorder_Approval::get( $approved_square_smoke['request_id'] );
	db_integration_same( 'released', $released['state'], 'paid approved Square fulfillment atomically records released approval' );
	if ( is_array( $fulfilled ) ) {
		db_integration_same( $fulfilled['order_id'], (int) $released['fulfillment_order_id'], 'paid approved Square approval retains exact fulfillment receipt' );
	}
}
remove_action( 'doughboss_order_created', 'db_integration_capture_order_created', 999 );
remove_filter( 'pre_wp_mail', 'db_integration_block_mail', 999 );
add_filter( 'pre_wp_mail', 'db_integration_block_mail', 999 );
foreach ( array( 'before-read', 'after-read' ) as $quarantine_mode ) {
	$fixture = db_integration_approved_square_fixture( 'quarantine-' . $quarantine_mode );
	$paid_quarantine = is_array( $fixture ) ? DoughBoss_Square::create_payment_intent( 1400, 'AUD', $fixture['metadata'] ) : null;
	if ( ! is_array( $paid_quarantine ) ) {
		db_integration_assert( false, 'Square quarantine race prepares valid payment' );
		continue;
	}
	$verified_quarantine = DoughBoss_Square::retrieve_payment_intent( $paid_quarantine['id'] );
	db_integration_assert( ! is_wp_error( $verified_quarantine ), 'Square quarantine race payment starts verified' );
	$GLOBALS['db_square_quarantine_attempt_id'] = $paid_quarantine['attempt_id'];
	$GLOBALS['db_square_quarantine_mode'] = $quarantine_mode;
	$GLOBALS['db_square_quarantine_exercised'] = false;
	$GLOBALS['db_square_quarantine_won'] = false;
	$GLOBALS['db_square_quarantine_blocked_by_lock'] = false;
	$GLOBALS['db_square_quarantine_probe_failed'] = false;
	$fulfillment = array_merge( $fixture['order_data'], array( 'source_request_id' => $fixture['request_id'], 'payment_method' => 'square', 'payment_status' => 'paid', 'payment_intent_id' => $paid_quarantine['id'] ) );
	add_filter( 'query', 'db_integration_square_quarantine_interleave', 10 );
	$quarantine_release = DoughBoss_Order::create( $fulfillment, $fixture['lines'] );
	remove_filter( 'query', 'db_integration_square_quarantine_interleave', 10 );
	db_integration_assert( $GLOBALS['db_square_quarantine_exercised'] && ! $GLOBALS['db_square_quarantine_probe_failed'], 'Square ' . $quarantine_mode . ' quarantine interleaving runs on a second connection' );
	if ( $GLOBALS['db_square_quarantine_won'] ) {
		db_integration_assert( is_wp_error( $quarantine_release ), 'Square quarantine that wins before fulfillment commit denies release' );
		db_integration_same( 0, DoughBoss_Order::find_id_by_source_request( $fixture['request_id'] ), 'winning Square quarantine creates no kitchen order' );
	} else {
		db_integration_assert( 'after-read' === $quarantine_mode && $GLOBALS['db_square_quarantine_blocked_by_lock'], 'Square fulfillment holds payment row until commit against concurrent quarantine' );
		db_integration_assert( is_array( $quarantine_release ), 'serialized verified Square fulfillment can commit before later quarantine' );
	}
}
remove_filter( 'pre_wp_mail', 'db_integration_block_mail', 999 );
$approved_square_race_fixture = db_integration_approved_square_fixture( 'two-process-race' );
db_integration_assert( is_array( $approved_square_race_fixture ), 'approved Square race leaves one immutable synthetic request for two workers' );
foreach ( $binding_shop_ids as $binding_shop_id ) {
	$wpdb->delete( $wpdb->prefix . 'doughboss_locations', array( 'id' => $binding_shop_id ) );
}
$wpdb->update( $wpdb->prefix . 'doughboss_locations', array( 'slug' => $binding_original_location->slug, 'is_active' => $binding_original_location->is_active ), array( 'id' => 1 ) );
update_option( DoughBoss_Settings::OPTION_KEY, $binding_original_settings, false );

remove_filter( 'pre_http_request', 'db_integration_square_http', 10 );

// Exercise the real Settings API sanitiser and persisted mapping boundary.
require_once dirname( __DIR__, 2 ) . '/admin/class-doughboss-admin.php';
$square_map_original = DoughBoss_Settings::all();
$square_map_admin = new DoughBoss_Admin();
$square_map_saved = $square_map_admin->sanitize_settings( array(
	'square_test_location_map' => array( 'revesby' => 'LDEMO_REVESBY', 'bankstown' => 'LDEMO_BANKSTOWN', 'roselands' => 'LDEMO_ROSELANDS', 'planned' => 'LPRIVATE' ),
	'square_live_location_map' => array( 'revesby' => 'LLIVE_REVESBY' ),
) );
db_integration_assert( 3 === count( $square_map_saved['square_test_location_map'] ) && ! isset( $square_map_saved['square_test_location_map']['planned'] ), 'admin mapping sanitation excludes private and unknown shops' );
update_option( DoughBoss_Settings::OPTION_KEY, $square_map_saved );
$square_map_omitted = $square_map_admin->sanitize_settings( array() );
db_integration_assert( $square_map_saved['square_test_location_map'] === $square_map_omitted['square_test_location_map'] && $square_map_saved['square_live_location_map'] === $square_map_omitted['square_live_location_map'], 'a settings save without mapping fields preserves existing sandbox and live bindings' );
$square_map_conflict = $square_map_admin->sanitize_settings( array( 'square_test_location_map' => array( 'revesby' => 'LDUPLICATE', 'bankstown' => 'LDUPLICATE', 'roselands' => 'LDEMO_ROSELANDS' ) ) );
db_integration_assert( array( 'roselands' => 'LDEMO_ROSELANDS' ) === $square_map_conflict['square_test_location_map'], 'admin save leaves both conflicting provider IDs unmapped' );
$square_map_clear = $square_map_admin->sanitize_settings( array( 'square_test_location_map' => array( 'revesby' => '', 'bankstown' => '', 'roselands' => '' ) ) );
db_integration_assert( array() === $square_map_clear['square_test_location_map'] && array( 'revesby' => 'LLIVE_REVESBY' ) === $square_map_clear['square_live_location_map'], 'explicit blank map clears only its own environment' );
update_option( DoughBoss_Settings::OPTION_KEY, $square_map_original );

// SSR menu fallback uses real published posts and taxonomy terms; it must not
// expose drafts, raw HTML, dietary assertions, or purchase controls.
$ssr_category = wp_insert_term( 'SSR browse fallback', DoughBoss_Post_Types::TAXONOMY );
$ssr_term_id  = is_wp_error( $ssr_category ) ? 0 : (int) $ssr_category['term_id'];
$ssr_first_title = 'SSR first <b>published</b> & "quoted"';
$ssr_second_title = 'SSR second published';
$ssr_first_id = wp_insert_post(
	array(
		'post_type'    => DoughBoss_Post_Types::POST_TYPE,
		'post_status'  => 'publish',
		'post_title'   => $ssr_first_title,
		'post_excerpt' => 'SSR <em>plain</em> description',
		'menu_order'   => 198,
	)
);
$ssr_second_id = wp_insert_post(
	array(
		'post_type'   => DoughBoss_Post_Types::POST_TYPE,
		'post_status' => 'publish',
		'post_title'  => $ssr_second_title,
		'menu_order'  => 199,
	)
);
$ssr_draft_id = wp_insert_post(
	array(
		'post_type'   => DoughBoss_Post_Types::POST_TYPE,
		'post_status' => 'draft',
		'post_title'  => 'SSR hidden draft',
		'menu_order'  => 1,
	)
);
foreach ( array( $ssr_first_id, $ssr_second_id, $ssr_draft_id ) as $ssr_item_id ) {
	wp_set_object_terms( $ssr_item_id, $ssr_term_id, DoughBoss_Post_Types::TAXONOMY );
}
update_post_meta( $ssr_first_id, DoughBoss_Post_Types::META_PRICE, '12.5' );
update_post_meta( $ssr_second_id, DoughBoss_Post_Types::META_PRICE, '13' );
update_post_meta( $ssr_draft_id, DoughBoss_Post_Types::META_PRICE, '99' );
$ssr_menu_html = ( new DoughBoss_Shortcodes() )->menu();
$ssr_first_escaped = esc_html( $ssr_first_title );
db_integration_assert( false !== strpos( $ssr_menu_html, 'data-doughboss-menu-fallback' ) && false !== strpos( $ssr_menu_html, 'SSR browse fallback' ), 'menu shortcode emits a server-rendered browse fallback from real category terms' );
db_integration_assert( false !== strpos( $ssr_menu_html, $ssr_first_escaped ) && false !== strpos( $ssr_menu_html, 'SSR plain description' ) && false !== strpos( $ssr_menu_html, DoughBoss_Settings::format_price( 12.5 ) ), 'SSR fallback emits escaped title, plain description and exact formatted approved price' );
db_integration_assert( false === strpos( $ssr_menu_html, '<b>published</b>' ) && false === strpos( $ssr_menu_html, '<em>plain</em>' ) && false === strpos( $ssr_menu_html, 'SSR hidden draft' ), 'SSR fallback escapes markup and excludes unpublished menu rows' );
db_integration_assert( strpos( $ssr_menu_html, $ssr_first_escaped ) < strpos( $ssr_menu_html, esc_html( $ssr_second_title ) ), 'SSR fallback preserves menu order within a category' );
db_integration_assert( false === strpos( $ssr_menu_html, 'dietary' ) && false === strpos( $ssr_menu_html, '<button' ) && false === strpos( $ssr_menu_html, '<form' ), 'SSR fallback remains browse-only without dietary claims or purchase controls' );

// Real menu responses must connect the new variants, not just package them.
$responsive_item_id = wp_insert_post( array(
	'post_type' => DoughBoss_Post_Types::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Spinach Pie',
) );
update_post_meta( $responsive_item_id, DoughBoss_Post_Types::META_PRICE, '7' );
$responsive_menu_rows = $rest_controller->get_menu()->get_data();
$responsive_row = null;
foreach ( $responsive_menu_rows as $responsive_menu_row ) {
	if ( (int) $responsive_menu_row['id'] === (int) $responsive_item_id ) { $responsive_row = $responsive_menu_row; break; }
}
db_integration_assert( is_array( $responsive_row ) && is_array( $responsive_row['image_responsive'] ), 'real menu exposes responsive metadata for newly covered Spinach Pie' );
db_integration_same( $responsive_row['image'], $responsive_row['image_responsive']['original'], 'real menu candidates match the selected original exactly' );
db_integration_assert( false !== strpos( $responsive_row['image_responsive']['sources'][0]['srcset'], '/responsive/spinach-pie-480.avif 480w' ), 'real menu connects actual AVIF candidates' );
$responsive_attachment_id = wp_insert_attachment( array( 'post_title' => 'Synthetic featured image', 'post_mime_type' => 'image/jpeg', 'guid' => 'http://doughboss.local.test/wp-content/uploads/synthetic-featured.jpg' ), false, $responsive_item_id );
update_post_meta( $responsive_attachment_id, '_wp_attached_file', 'synthetic-featured.jpg' );
update_post_meta( $responsive_attachment_id, '_wp_attachment_metadata', array( 'width' => 640, 'height' => 480, 'file' => 'synthetic-featured.jpg' ) );
set_post_thumbnail( $responsive_item_id, $responsive_attachment_id );
$responsive_featured_rows = $rest_controller->get_menu()->get_data();
foreach ( $responsive_featured_rows as $responsive_menu_row ) {
	if ( (int) $responsive_menu_row['id'] === (int) $responsive_item_id ) { $responsive_row = $responsive_menu_row; break; }
}
db_integration_assert( false !== strpos( $responsive_row['image'], 'synthetic-featured.jpg' ) && null === $responsive_row['image_responsive'], 'featured upload wins and never inherits bundled product derivatives' );
delete_post_thumbnail( $responsive_item_id );
wp_update_post( array( 'ID' => $responsive_item_id, 'post_title' => 'Choco Banana' ) );
$responsive_unsupported_rows = $rest_controller->get_menu()->get_data();
foreach ( $responsive_unsupported_rows as $responsive_menu_row ) {
	if ( (int) $responsive_menu_row['id'] === (int) $responsive_item_id ) { $responsive_row = $responsive_menu_row; break; }
}
db_integration_assert( false !== strpos( $responsive_row['image'], 'choco-banana.jpg' ) && null === $responsive_row['image_responsive'], 'unsupported product preserves its original menu image' );

// Leave one synthetic approved/unpaid fixture for the explicit multi-process
// pre-order start/completion harness that the PowerShell runner launches next.
$preorder_race_settings = DoughBoss_Settings::all();
$preorder_race_settings['payments_enabled']          = 1;
$preorder_race_settings['payment_gateway']           = 'stripe';
$preorder_race_settings['stripe_mode']               = 'test';
$preorder_race_settings['stripe_test_sk']            = 'sk_test_localSyntheticIntegrationOnly000000';
$preorder_race_settings['stripe_test_whsec']         = 'whsec_localSyntheticIntegrationOnly000000';
$preorder_race_settings['currency_code']             = 'AUD';
$preorder_race_settings['ordering_open']             = 0;
$preorder_race_settings['preorder_approver_user_id'] = $preorder_approver_id;
$preorder_race_settings['pospal_order_push_enabled'] = 0;
update_option( 'doughboss_settings', $preorder_race_settings, false );
$wpdb->update( $wpdb->prefix . 'doughboss_locations', array( 'online_payment_enabled' => 1, 'pickup_enabled' => 1, 'is_active' => 1 ), array( 'id' => 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$preorder_race_order = db_integration_create_preorder_request( 'approval-process-race', 31.00 );
$preorder_race_id = is_array( $preorder_race_order ) ? (int) $preorder_race_order['order_id'] : 0;
$preorder_race_pending = $preorder_race_id ? DoughBoss_Preorder_Approval::ensure_pending( $preorder_race_id ) : new WP_Error( 'integration_fixture', 'Missing race request.' );
$preorder_race_source = $preorder_race_id ? DoughBoss_Order::get( $preorder_race_id ) : null;
$preorder_race_approval = $preorder_race_id ? DoughBoss_Preorder_Approval::decide( $preorder_race_id, 'approve', $preorder_approver_id, 'Integration Approver', 'integration-preorder-process-race-0001', '', $preorder_race_source ? (int) $preorder_race_source->version : 0 ) : new WP_Error( 'integration_fixture', 'Missing race request.' );
$preorder_race_token = is_array( $preorder_race_approval ) && isset( $preorder_race_approval['capability_token'] ) ? (string) $preorder_race_approval['capability_token'] : '';
$preorder_race_checkout_key = is_array( $preorder_race_approval ) && isset( $preorder_race_approval['payment_checkout_key'] ) ? (string) $preorder_race_approval['payment_checkout_key'] : '';
$preorder_race_fixture = array(
	'request_id'  => $preorder_race_id,
	'token'       => $preorder_race_token,
	'checkout_key'=> $preorder_race_checkout_key,
	'session_id'  => 'cs_test_' . substr( hash( 'sha256', 'preorder-process-race|' . $preorder_race_checkout_key ), 0, 24 ),
	'amount'      => 3100,
	'currency'    => 'AUD',
);
update_option( 'doughboss_preorder_race_fixture', $preorder_race_fixture, false );
db_integration_assert( is_array( $preorder_race_pending ) && is_array( $preorder_race_approval ) && $preorder_race_id > 0 && '' !== $preorder_race_token && 1 === preg_match( '/^[a-f0-9]{64}$/', $preorder_race_checkout_key ), 'multi-process pre-order fixture is approved, unpaid and exact-bound before workers start' );

// Owner dashboard is a protected read-only route, including its demo mode.
$dashboard_previous_user = get_current_user_id();
$dashboard_owner = wp_insert_user( array( 'user_login' => uniqid( 'db-dashboard-owner-' ), 'user_pass' => wp_generate_password( 32, true, true ), 'role' => 'administrator' ) );
$dashboard_staff = wp_insert_user( array( 'user_login' => uniqid( 'db-dashboard-staff-' ), 'user_pass' => wp_generate_password( 32, true, true ), 'role' => 'subscriber' ) );
db_integration_assert( ! is_wp_error( $dashboard_owner ) && ! is_wp_error( $dashboard_staff ), 'dashboard role fixtures created in disposable database' );
foreach ( array( 0, is_wp_error( $dashboard_staff ) ? 0 : (int) $dashboard_staff ) as $dashboard_denied_user ) {
	wp_set_current_user( $dashboard_denied_user );
	$dashboard_request = new WP_REST_Request( 'GET', '/doughboss/v1/admin/square-dashboard' );
	$dashboard_request->set_param( 'mode', 'demo' );
	$dashboard_denied = rest_do_request( $dashboard_request );
	db_integration_assert( 403 === $dashboard_denied->get_status() && ! isset( $dashboard_denied->get_data()['orders'] ), 'anonymous and non-manager dashboard access denied without data' );
}
if ( ! is_wp_error( $dashboard_owner ) ) {
	wp_set_current_user( (int) $dashboard_owner );
	$dashboard_request = new WP_REST_Request( 'GET', '/doughboss/v1/admin/square-dashboard' );
	$dashboard_status_response = rest_do_request( $dashboard_request );
	$dashboard_status_data = $dashboard_status_response->get_data();
	db_integration_assert( 200 === $dashboard_status_response->get_status() && 'not_connected' === $dashboard_status_data['data_state'] && ! isset( $dashboard_status_data['orders'] ), 'authorized dashboard defaults to connection status without fabricated figures' );
	db_integration_assert( false !== strpos( $dashboard_status_response->get_headers()['Cache-Control'], 'no-store' ), 'private dashboard response prohibits cache storage' );
	$dashboard_request->set_param( 'mode', 'demo' );
	$dashboard_request->set_param( 'location', 'bankstown' );
	$dashboard_demo_response = rest_do_request( $dashboard_request );
	$dashboard_demo_data = $dashboard_demo_response->get_data();
	db_integration_assert( 200 === $dashboard_demo_response->get_status() && 'synthetic' === $dashboard_demo_data['source'] && 4 === count( $dashboard_demo_data['orders'] ), 'manager can explicitly preview isolated Bankstown demo' );
	$dashboard_request->set_param( 'location', 'planned' );
	db_integration_assert( 400 === rest_do_request( $dashboard_request )->get_status(), 'unannounced unmapped location cannot be selected in API' );
	$dashboard_request->set_param( 'location', 'all' );
	$dashboard_request->set_param( 'mode', 'live' );
	db_integration_assert( 400 === rest_do_request( $dashboard_request )->get_status(), 'live data cannot be requested from demo contract' );
}
wp_set_current_user( $dashboard_previous_user );

$failed = count( $GLOBALS['db_integration_failed'] );
if ( $failed ) {
	echo "FAILURES:\n";
	foreach ( $GLOBALS['db_integration_failed'] as $failure ) {
		echo ' - ' . $failure . "\n";
	}
}
echo sprintf( "%d WordPress/MySQL assertions: %d passed, %d failed\n", $GLOBALS['db_integration_passed'] + $failed, $GLOBALS['db_integration_passed'], $failed );
exit( $failed ? 1 : 0 );
