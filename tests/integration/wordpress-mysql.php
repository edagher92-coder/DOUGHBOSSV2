<?php
/**
 * Real WordPress + MySQL integration checks.
 *
 * Run through WP-CLI after copying the plugin into the disposable site:
 * php wp-cli.phar eval-file tests/integration/wordpress-mysql.php --path=...
 *
 * The hard environment guard prevents this destructive fixture reset from ever
 * running against a real site. Square HTTP is intercepted by pre_http_request;
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

$GLOBALS['db_integration_passed'] = 0;
$GLOBALS['db_integration_failed'] = array();
$GLOBALS['db_square_http_mode']   = 'success';
$GLOBALS['db_square_post_count']  = 0;
$GLOBALS['db_square_get_count']   = 0;
$GLOBALS['db_fail_guard_once']    = false;
$GLOBALS['db_catering_mail']        = array();
$GLOBALS['db_catering_mail_result'] = true;

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

global $wpdb;

// Fresh activation contract and reactivation idempotency.
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
