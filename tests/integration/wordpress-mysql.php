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
$GLOBALS['db_fail_guard_once']    = false;
$GLOBALS['db_catering_mail']        = array();
$GLOBALS['db_catering_mail_result'] = true;
$GLOBALS['db_stripe_post_count']    = 0;
$GLOBALS['db_stripe_session_gets']  = 0;
$GLOBALS['db_stripe_intent_gets']   = 0;
$GLOBALS['db_stripe_network_once']  = false;
$GLOBALS['db_stripe_post_bodies']   = array();
$GLOBALS['db_stripe_idempotency']   = array();
$GLOBALS['db_stripe_sessions']      = array();
$GLOBALS['db_stripe_intents']       = array();
$GLOBALS['db_fail_snapshot_complete_once'] = false;
$GLOBALS['db_fail_attempt_succeed_once']   = false;

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

/** Prevent the disposable checkout fixture from invoking a local mail transport. */
function db_integration_block_mail() {
	return true;
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
	if ( 'POST' === $method ) {
		++$GLOBALS['db_square_post_count'];
		if ( 'network_error' === $GLOBALS['db_square_http_mode'] ) {
			return new WP_Error( 'integration_network', 'Synthetic network failure.' );
		}
		$body      = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : array();
		$reference = isset( $body['reference_id'] ) ? (string) $body['reference_id'] : '';
		$payment_id = 'sqpay' . substr( hash( 'sha256', $reference ), 0, 28 );
		$payment = array(
			'id'           => $payment_id,
			'status'       => 'failed' === $GLOBALS['db_square_http_mode'] ? 'FAILED' : ( 'pending' === $GLOBALS['db_square_http_mode'] ? 'PENDING' : 'COMPLETED' ),
			'location_id'  => DoughBoss_Settings::square_location_id(),
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
		$payment = array(
			'id'           => $payment_id,
			'status'       => 'failed' === $GLOBALS['db_square_http_mode'] ? 'FAILED' : ( 'pending' === $GLOBALS['db_square_http_mode'] ? 'PENDING' : 'COMPLETED' ),
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

// Isolate the payment protocol fixtures from activation evidence.
	foreach ( array( 'doughboss_order_items', 'doughboss_order_events', 'doughboss_orders', 'doughboss_payment_events', 'doughboss_payment_attempts', 'doughboss_checkout_snapshots', 'doughboss_voucher_redemptions', 'doughboss_voucher_audit', 'doughboss_vouchers', 'doughboss_catering_enquiries' ) as $suffix ) {
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
$settings['square_test_app_id']       = 'sandbox-sq0idb-INTEGRATIONAPP000000';
$settings['square_test_access_token'] = 'EAAAlocalSyntheticIntegrationToken000000';
$settings['square_test_location_id']  = 'LINTEGRATIONLOCATION';
$settings['square_test_webhook_key']  = 'local-synthetic-webhook-key-000000';
$settings['square_webhook_url']       = 'https://example.invalid/wp-json/doughboss/v1/square-webhook';
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
db_integration_assert( ! isset( $first_stored_meta['source_id'], $first_stored_meta['verification_token'] ), 'single-use card and verification tokens are never persisted' );
db_integration_same( false, DoughBoss_Payment_Attempts::update( (int) $first['attempt_id'], array( 'status' => 'processing' ) ), 'generic update cannot regress Square v2 state' );
db_integration_same( false, DoughBoss_Payment_Attempts::update( (int) $first['attempt_id'], array( 'safe_metadata' => array( 'protocol_version' => 'square-v2' ) ) ), 'generic update cannot replace the immutable Square v2 binding' );

$replay = DoughBoss_Square::create_payment_intent( 2750, 'AUD', $first_meta );
db_integration_assert( ! is_wp_error( $replay ), 'same attempt replays successfully' );
db_integration_same( 1, $GLOBALS['db_square_post_count'], 'same attempt never performs a second provider POST' );
db_integration_same( 1, $GLOBALS['db_square_get_count'], 'same attempt reconciles through a provider GET' );
db_integration_same( $first['id'], $replay['id'], 'replay returns the original payment reference' );

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
foreach ( array( 'doughboss_order_items', 'doughboss_order_events', 'doughboss_orders', 'doughboss_payment_events', 'doughboss_payment_attempts', 'doughboss_checkout_snapshots' ) as $suffix ) {
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

remove_filter( 'pre_http_request', 'db_integration_square_http', 10 );

$failed = count( $GLOBALS['db_integration_failed'] );
if ( $failed ) {
	echo "FAILURES:\n";
	foreach ( $GLOBALS['db_integration_failed'] as $failure ) {
		echo ' - ' . $failure . "\n";
	}
}
echo sprintf( "%d WordPress/MySQL assertions: %d passed, %d failed\n", $GLOBALS['db_integration_passed'] + $failed, $GLOBALS['db_integration_passed'], $failed );
exit( $failed ? 1 : 0 );
