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

/** Separate ledger-only branch: no provider dispatch or fulfillment. */
$split_mode = getenv( 'DOUGHBOSS_SPLIT_LEDGER_MODE' );
if ( is_string( $split_mode ) && '' !== $split_mode ) {
	$case = getenv( 'DOUGHBOSS_SPLIT_LEDGER_CASE' );
	if ( ! in_array( $case, array( 'prepare', 'claim', 'remainder', 'cancel' ), true ) ) { exit( 2 ); }
	add_filter( 'pre_http_request', static function () use ( $race_log ) {
		file_put_contents( $race_log, "PROVIDER\n", FILE_APPEND | LOCK_EX );
		return new WP_Error( 'split_test_http_denied', 'Ledger tests never call a provider.' );
	}, 999 );
	add_filter( 'pre_wp_mail', '__return_true', 999 );
	add_action( 'doughboss_order_created', static function () use ( $race_log ) { file_put_contents( $race_log, "FULFILL\n", FILE_APPEND | LOCK_EX ); }, 999 );
	$key = hash( 'sha256', 'split-race|' . $race_log );
	$attempt_key = hash( 'sha256', 'split-race-attempt|' . $race_log );
	if ( 'setup' === $split_mode ) {
		$binding = hash( 'sha256', 'split-race-binding|' . $key );
		$payload = array( 'binding_hash' => $binding, 'order' => array( 'location_id' => 1, 'currency' => 'AUD', 'subtotal' => '14.00', 'total' => '14.00', 'discount' => '0.00', 'tax' => '0.00', 'delivery_fee' => '0.00',
			'accounting_basis' => array( 'version' => 1, 'tax_inclusive' => true, 'tax_rate' => 0.0, 'discount_policy' => 'goods_before_tax', 'delivery_taxable' => true ) ),
			'lines' => array( array( 'name' => 'Synthetic split ledger item', 'quantity' => 1, 'unit_price' => '14.00', 'line_total' => '14.00' ) ) );
		$snapshot = DoughBoss_Checkout_Snapshots::store( $key, $payload );
		if ( ! is_array( $snapshot ) ) { fwrite( STDERR, "Split race snapshot setup failed.\n" ); exit( 1 ); }
		$parent = DoughBoss_Payment_Attempts::create( array( 'attempt_key' => $attempt_key, 'checkout_key' => $key, 'provider' => 'square', 'purpose' => 'order', 'context' => 'web', 'status' => 'prepared', 'location_id' => 1, 'amount_minor' => 1400, 'currency' => 'AUD', 'local_reference' => hash( 'sha256', 'split-guard|' . $key ),
			'safe_metadata' => array( 'protocol_version' => 'square-v2', 'orders_protocol' => 'square-split-v1', 'orders_snapshot_hash' => $snapshot['payload_hash'], 'binding_hash' => $binding,
				'square_shop_slug' => 'revesby', 'square_location_id' => 'LSPLITRACELOCATION', 'square_mode' => 'test', 'square_tender_kind' => 'gift_card' ) ) );
		if ( ! is_array( $parent ) || ! DoughBoss_Payment_Attempts::claim_irreversible_creation( (int) $parent['id'] ) ) { fwrite( STDERR, "Split race parent setup failed.\n" ); exit( 1 ); }
		$parent = DoughBoss_Payment_Attempts::find( (int) $parent['id'] );
		$parent = DoughBoss_Payment_Attempts::bind_split_square_order( (int) $parent['id'], 'splitRaceOrder' . $parent['id'], $parent );
		if ( ! is_array( $parent ) ) { fwrite( STDERR, "Split race Order bind failed.\n" ); exit( 1 ); }
		if ( 'prepare' !== $case && ! is_array( DoughBoss_Payment_Legs::prepare( $parent ) ) ) { exit( 1 ); }
		if ( 'remainder' === $case ) {
			$payment = array( 'id' => 'splitRacePayment' . $parent['id'], 'order_id' => $parent['provider_order_reference'], 'location_id' => 'LSPLITRACELOCATION', 'reference_id' => DoughBoss_Payment_Legs::leg_reference( (int) $parent['id'], 0 ),
				'status' => 'APPROVED', 'source_type' => 'CARD', 'amount_money' => array( 'amount' => 900, 'currency' => 'AUD' ), 'card_details' => array( 'status' => 'AUTHORIZED', 'card' => array( 'card_brand' => 'SQUARE_GIFT_CARD' ) ) );
			if ( ! is_array( DoughBoss_Payment_Legs::claim( $parent, 1, 0 ) ) || ! is_array( DoughBoss_Payment_Legs::observe( $parent, 2, 0, $payment ) ) ) { exit( 1 ); }
		}
		echo "SPLIT_SETUP_OK\n"; exit( 0 );
	}
	$parent = DoughBoss_Payment_Attempts::find_by_attempt_key( $attempt_key );
	if ( ! is_array( $parent ) || 'dispatching' !== $parent['status'] || null !== $parent['provider_reference'] ) { exit( 1 ); }
	if ( 'race' === $split_mode ) {
		file_put_contents( $race_log, "READY\n", FILE_APPEND | LOCK_EX );
		$deadline = microtime( true ) + 10;
		do {
			$lines = file( $race_log, FILE_IGNORE_NEW_LINES );
			$ready = count( array_filter( $lines, static function ( $line ) { return 'READY' === $line; } ) );
			if ( $ready >= 2 ) { break; }
			usleep( 20000 );
		} while ( microtime( true ) < $deadline );
		if ( $ready < 2 ) { fwrite( STDERR, "Split race barrier timed out.\n" ); exit( 1 ); }
		if ( 'prepare' === $case ) { $result = DoughBoss_Payment_Legs::prepare( $parent ); }
		elseif ( 'remainder' === $case ) {
			$plan = DoughBoss_Payment_Legs::prepare( $parent );
			$result = is_array( $plan ) ? DoughBoss_Payment_Legs::prepare_remainder( $parent, 3, $plan['legs'][0] ) : false;
		} elseif ( 'cancel' === $case && 'cancel' === getenv( 'DOUGHBOSS_SPLIT_LEDGER_ACTION' ) ) { $result = DoughBoss_Payment_Legs::request_cancellation( $parent, 1 ); }
		else { $result = DoughBoss_Payment_Legs::claim( $parent, 1, 0 ); }
		file_put_contents( $race_log, is_array( $result ) ? "SUCCESS\n" : "CONFLICT\n", FILE_APPEND | LOCK_EX );
		echo "SPLIT_WORKER_OK\n"; exit( 0 );
	}
	if ( 'verify' === $split_mode ) {
		$plan = DoughBoss_Payment_Legs::prepare( $parent );
		$counts = array_count_values( file( $race_log, FILE_IGNORE_NEW_LINES ) );
		if ( ! is_array( $plan ) || 2 !== ( isset( $counts['READY'] ) ? $counts['READY'] : 0 ) || isset( $counts['PROVIDER'] ) || isset( $counts['FULFILL'] )
			|| ( 'prepare' === $case ? 2 : 1 ) !== ( isset( $counts['SUCCESS'] ) ? $counts['SUCCESS'] : 0 ) ) { exit( 1 ); }
		$expected_version = 'prepare' === $case ? 1 : ( 'remainder' === $case ? 4 : 2 );
		if ( $expected_version !== (int) $plan['set']['version'] || ( 'remainder' === $case ? 2 : 1 ) !== count( $plan['legs'] ) ) { exit( 1 ); }
		if ( 'remainder' === $case && 500 !== (int) $plan['legs'][1]['requested_amount_minor'] ) { exit( 1 ); }
		if ( 'claim' === $case && 'claimed' !== $plan['legs'][0]['state'] ) { exit( 1 ); }
		if ( 'cancel' === $case ) {
			$plan = DoughBoss_Payment_Legs::request_cancellation( $parent, (int) $plan['set']['version'] );
			if ( ! is_array( $plan ) || 'canceling' !== $plan['set']['state'] || false !== DoughBoss_Payment_Legs::claim( $parent, (int) $plan['set']['version'], 0 ) ) { exit( 1 ); }
		}
		if ( DoughBoss_Payment_Attempts::find( (int) $parent['id'] ) !== $parent ) { fwrite( STDERR, "Ledger race mutated the parent.\n" ); exit( 1 ); }
		echo "SPLIT_VERIFY_OK\n"; exit( 0 );
	}
	exit( 2 );
}

/** Stop a real PHP process only after asserting the committed crash boundary. */
function db_square_race_crash( $stage, $attempt_id ) {
	if ( $stage !== getenv( 'DOUGHBOSS_SQUARE_CRASH_STAGE' ) || '1' === getenv( 'DOUGHBOSS_SQUARE_CRASH_REPLAY' ) ) { return; }
	$attempt = DoughBoss_Payment_Attempts::find( $attempt_id );
	$metadata = is_array( $attempt ) ? DoughBoss_Payment_Attempts::metadata( $attempt ) : array();
	$has_order = ! empty( $attempt['provider_order_reference'] );
	$has_payment = ! empty( $attempt['provider_reference'] );
	$expected_order = ! in_array( $stage, array( 'before_order_post', 'after_order_post' ), true );
	$expected_payment = 'after_payment_bind' === $stage;
	if ( ! is_array( $attempt ) || $has_order !== $expected_order || $has_payment !== $expected_payment
		|| ( $expected_payment ? 'succeeded' : 'dispatching' ) !== $attempt['status']
		|| null !== $attempt['verified_at'] || 'square-orders-v1' !== ( isset( $metadata['orders_protocol'] ) ? $metadata['orders_protocol'] : '' ) ) {
		fwrite( STDERR, "Crash point does not match committed provider identities/state.\n" ); exit( 1 );
	}
	if ( 'approved_preorder' === $attempt['context'] ) {
		$request_id = isset( $metadata['approval_id'] ) ? (int) $metadata['approval_id'] : 0;
		$request = DoughBoss_Order::get( $request_id );
		$approval = DoughBoss_Preorder_Approval::get( $request_id );
		if ( ! $request || 'unpaid' !== $request->payment_status || DoughBoss_Order::find_id_by_source_request( $request_id )
			|| ! is_array( $approval ) || 'payment_active' !== $approval['state'] || (int) $approval['payment_attempt_id'] !== $attempt_id ) {
			fwrite( STDERR, "Crash point has lost approval linkage or prematurely released food.\n" ); exit( 1 );
		}
	}
	$identity = array();
	foreach ( array( 'id', 'attempt_key', 'checkout_key', 'local_reference', 'amount_minor', 'currency', 'provider_order_reference', 'provider_reference' ) as $key ) {
		$identity[ $key ] = $attempt[ $key ];
	}
	$identity['orders_snapshot_hash'] = $metadata['orders_snapshot_hash'];
	file_put_contents( getenv( 'DOUGHBOSS_RACE_LOG' ) . '.boundary.json', wp_json_encode( $identity ), LOCK_EX );
	file_put_contents( getenv( 'DOUGHBOSS_RACE_LOG' ), 'CRASH:' . $stage . "\n", FILE_APPEND | LOCK_EX );
	echo 'CRASH_POINT ' . $stage . "\n";
	exit( 86 );
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
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( '/v2/orders' === $path || 0 === strpos( $path, '/v2/orders/' ) ) {
			if ( 'POST' === $method ) {
				$body = json_decode( (string) $args['body'], true );
				$attempt_id = (int) substr( $body['order']['reference_id'], strlen( 'db-attempt-' ) );
				db_square_race_crash( 'before_order_post', $attempt_id );
				file_put_contents( $race_log, "ORDER_POST\n", FILE_APPEND | LOCK_EX );
				usleep( 750000 );
				$attempt = DoughBoss_Payment_Attempts::find( $attempt_id );
				$snapshot = $attempt ? DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( $attempt['checkout_key'] ) : null;
				$binding = $attempt ? DoughBoss_Square::shop_binding( $attempt['location_id'] ) : new WP_Error( 'missing' );
				$projection = $snapshot && ! is_wp_error( $binding ) ? DoughBoss_Square_Orders::project( $snapshot['payload'], $binding, $attempt_id ) : new WP_Error( 'missing' );
				if ( is_wp_error( $projection ) || $projection['order'] !== $body['order'] || 'dispatching' !== $attempt['status'] ) {
					return new WP_Error( 'integration_order_projection', 'Synthetic Order projection or committed owner mismatch.' );
				}
				$order = $body['order'];
				$order['id'] = 'sqorder' . substr( hash( 'sha256', $order['reference_id'] ), 0, 28 );
				$order['version'] = 1;
				$money = function ( $value ) use ( $projection ) { return array( 'amount' => $value, 'currency' => $projection['currency'] ); };
				$discount_left = $projection['amounts']['discount'];
				$tax_left = $projection['amounts']['tax'];
				foreach ( $order['line_items'] as &$line ) {
					$gross = (int) $line['quantity'] * $line['base_price_money']['amount'];
					$discount = 'db-delivery' === $line['uid'] ? 0 : min( $gross, $discount_left );
					$discount_left -= $discount;
					$tax = empty( $line['applied_taxes'] ) ? 0 : $tax_left;
					$tax_left -= $tax;
					$line['gross_sales_money'] = $money( $gross );
					$line['variation_total_price_money'] = $money( $gross );
					$line['total_tax_money'] = $money( $tax );
					$line['total_discount_money'] = $money( $discount );
					$line['total_money'] = $money( $gross - $discount + ( $projection['tax_inclusive'] ? 0 : $tax ) );
					if ( ! empty( $line['applied_taxes'] ) ) { $line['applied_taxes'][0]['applied_money'] = $money( $tax ); }
					if ( ! empty( $order['discounts'] ) && 'db-delivery' !== $line['uid'] ) {
						$line['applied_discounts'] = array( array( 'discount_uid' => 'db-discount', 'applied_money' => $money( $discount ) ) );
					}
				}
				unset( $line );
				foreach ( array( 'tax' => 'total_tax_money', 'discount' => 'total_discount_money', 'total' => 'total_money' ) as $key => $field ) { $order[ $field ] = $money( $projection['amounts'][ $key ] ); }
				foreach ( array( 'taxes' => 'tax', 'discounts' => 'discount' ) as $field => $key ) { if ( ! empty( $order[ $field ] ) ) { $order[ $field ][0]['applied_money'] = $money( $projection['amounts'][ $key ] ); } }
				file_put_contents( $race_log . '.' . $order['id'] . '.json', wp_json_encode( $order ), LOCK_EX );
				db_square_race_crash( 'after_order_post', $attempt_id );
			} else {
				$id = basename( $path );
				$file = $race_log . '.' . $id . '.json';
				$order = is_file( $file ) ? json_decode( file_get_contents( $file ), true ) : null;
				if ( ! is_array( $order ) ) { return new WP_Error( 'integration_order_missing', 'Synthetic Order missing.' ); }
				$attempt_id = (int) substr( $order['reference_id'], strlen( 'db-attempt-' ) );
				$attempt = DoughBoss_Payment_Attempts::find( $attempt_id );
				db_square_race_crash( empty( $attempt['provider_reference'] ) ? 'after_order_bind' : 'after_payment_bind', $attempt_id );
				$paid_file = $race_log . '.' . $id . '.paid.json';
				$paid = is_file( $paid_file ) ? json_decode( file_get_contents( $paid_file ), true ) : null;
				if ( is_array( $paid ) ) {
					$order['state'] = 'COMPLETED';
					$order['tenders'] = array( array( 'type' => 'CARD', 'payment_id' => $paid['id'], 'amount_money' => $paid['amount_money'] ) );
					$order['net_amount_due_money'] = array( 'amount' => 0, 'currency' => $paid['amount_money']['currency'] );
				}
			}
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'order' => $order ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		if ( 'POST' === $method ) {
			file_put_contents( $race_log, "PAYMENT_POST\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
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
			if ( isset( $body['order_id'] ) ) {
				$payment['order_id'] = $body['order_id'];
				file_put_contents( $race_log . '.' . $body['order_id'] . '.paid.json', wp_json_encode( $payment ), LOCK_EX );
				db_square_race_crash( 'after_payment_post', (int) substr( $reference, strlen( 'db-attempt-' ) ) );
			}
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
			if ( ! empty( $attempt['provider_order_reference'] ) ) { $payment['order_id'] = $attempt['provider_order_reference']; }
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
$crash_stage = getenv( 'DOUGHBOSS_SQUARE_CRASH_STAGE' );
$case_seed = $crash_stage ? 'crash-' . $crash_stage : 'v1';
if ( 'approved_preorder' === $race_mode ) {
	$request_seed = $crash_stage ? 'square-crash-' . $crash_stage : 'square-two-process-race';
	$request_id = DoughBoss_Order::find_id_by_checkout_key( hash( 'sha256', 'preorder-request|' . $request_seed ) );
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
		'attempt_identity' => hash( 'sha256', $crash_stage ? 'approved-square-crash-' . $crash_stage : 'approved-square-two-process-race' ),
		'binding_hash' => $snapshot['payload']['binding_hash'],
		'cart_guard' => hash( 'sha256', $crash_stage ? 'approved-square-cart-crash-' . $crash_stage : 'approved-square-cart-two-process-race' ),
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
global $wpdb;
$snapshot_errors = $wpdb->suppress_errors( true );
$snapshot = DoughBoss_Checkout_Snapshots::store( hash( 'sha256', 'race-checkout-' . $case_seed ), array(
	'binding_hash' => hash( 'sha256', 'race-binding-' . $case_seed ),
	'order' => array( 'location_id' => 1, 'currency' => 'AUD', 'subtotal' => '32.00', 'discount' => '0.00',
		'tax' => '2.91', 'delivery_fee' => '0.00', 'total' => '32.00',
		'accounting_basis' => array( 'version' => 1, 'tax_inclusive' => true, 'tax_rate' => 10,
			'discount_policy' => 'goods_before_tax', 'delivery_taxable' => true ) ),
	'lines' => array( array( 'name' => 'Synthetic race pizza', 'quantity' => 1, 'unit_price' => '32.00', 'line_total' => '32.00' ) ),
) );
$wpdb->suppress_errors( $snapshot_errors );
if ( ! is_array( $snapshot ) ) { fwrite( STDERR, "Web Square race snapshot missing.\n" ); exit( 2 ); }
$result = DoughBoss_Square::create_payment_intent(
	3200,
	'AUD',
	array(
		'checkout_key'      => hash( 'sha256', 'race-checkout-' . $case_seed ),
		'protocol_version'   => 'square-v2',
		'attempt_identity'   => hash( 'sha256', 'race-attempt-' . $case_seed ),
		'binding_hash'       => hash( 'sha256', 'race-binding-' . $case_seed ),
		'cart_guard'         => hash( 'sha256', 'race-cart-' . $case_seed ),
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

if ( 'approved_preorder' === $race_mode && ( ! $crash_stage || 'after_payment_bind' === $crash_stage ) ) {
	// Only a bound payment can converge on kitchen release. Earlier crash
	// boundaries must remain unpaid and are asserted as held below.
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

if ( $crash_stage && '1' === getenv( 'DOUGHBOSS_SQUARE_CRASH_REPLAY' ) ) {
	$checkout_key = 'approved_preorder' === $race_mode ? $approval['payment_checkout_key'] : hash( 'sha256', 'race-checkout-' . $case_seed );
	$held = DoughBoss_Payment_Attempts::find_by_checkout_key( $checkout_key );
	$stored = is_array( $held ) ? DoughBoss_Payment_Attempts::metadata( $held ) : array();
	$boundary_file = $race_log . '.boundary.json';
	$identity = is_file( $boundary_file ) ? json_decode( file_get_contents( $boundary_file ), true ) : null;
	if ( ! is_array( $identity ) || ! is_array( $held ) ) { fwrite( STDERR, "Crash identity evidence is missing.\n" ); exit( 1 ); }
	foreach ( array( 'id', 'attempt_key', 'checkout_key', 'local_reference', 'amount_minor', 'currency', 'provider_order_reference', 'provider_reference' ) as $key ) {
		if ( $identity[ $key ] !== $held[ $key ] ) { fwrite( STDERR, "Crash replay replaced an immutable identity.\n" ); exit( 1 ); }
	}
	if ( $identity['orders_snapshot_hash'] !== $stored['orders_snapshot_hash'] ) { fwrite( STDERR, "Crash replay changed snapshot linkage.\n" ); exit( 1 ); }
	$has_order = ! empty( $held['provider_order_reference'] );
	$has_payment = ! empty( $held['provider_reference'] );
	$expected_order = ! in_array( $crash_stage, array( 'before_order_post', 'after_order_post' ), true );
	$recoverable = 'after_payment_bind' === $crash_stage;
	if ( ! is_array( $held ) || $has_order !== $expected_order || $has_payment !== $recoverable
		|| 'square-orders-v1' !== ( isset( $stored['orders_protocol'] ) ? $stored['orders_protocol'] : '' )
		|| ( $recoverable ? ( is_wp_error( $result ) || 'succeeded' !== $result['status'] ) : ( ! is_wp_error( $result ) || 'dispatching' !== $held['status'] ) ) ) {
		fwrite( STDERR, "Crash replay changed identities or reclaimed an unresolved dispatch.\n" ); exit( 1 );
	}
	if ( ! $recoverable ) {
		if ( 'approved_preorder' === $race_mode && DoughBoss_Order::find_id_by_source_request( $request_id ) ) {
			fwrite( STDERR, "Unresolved crash reached kitchen fulfillment.\n" ); exit( 1 );
		}
		echo "WORKER_OK\n"; exit( 0 );
	}
}
echo "WORKER_OK\n";
exit( 0 );
