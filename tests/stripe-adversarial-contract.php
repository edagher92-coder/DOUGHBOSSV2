<?php
/**
 * Stripe release contract: adversarial, offline checks only.
 *
 * This test deliberately records fake Stripe requests instead of contacting
 * Stripe. It guards the invariants that prevent retry-created duplicate charges
 * or refunds, unauthenticated webhook state changes, and client-side amount
 * tampering.
 *
 * Run: php tests/stripe-adversarial-contract.php
 *
 * @package DoughBoss\Tests
 */

require __DIR__ . '/wp-stubs.php';

/** In-memory persistence double for durable payment and webhook contracts. */
class DoughBoss_Stripe_Contract_DB extends DB_Stub {
	public $rows = array();
	public $events = array();
	private $next_id = 1;

	public function prepare( $query, ...$args ) {
		$index = 0;
		return preg_replace_callback(
			'/%[ds]/',
			function ( $match ) use ( &$args, &$index ) {
				$value = $args[ $index++ ] ?? '';
				return '%d' === $match[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function get_row( $query = null, $output = OBJECT, $offset = 0 ) {
		if ( preg_match( '/WHERE (id|attempt_key|checkout_key|provider_reference) = (?:\'([^\']*)\'|(\d+))/', (string) $query, $match ) ) {
			$field = $match[1];
			$value = '' !== ( $match[2] ?? '' ) ? stripslashes( $match[2] ) : ( $match[3] ?? '' );
			foreach ( $this->rows as $row ) {
				if ( (string) ( $row[ $field ] ?? '' ) === (string) $value ) {
					return ARRAY_A === $output ? $row : (object) $row;
				}
			}
		}
		return null;
	}

	public function get_var( $query = null ) {
		if ( preg_match( "/WHERE event_key = '([^']+)'/", (string) $query, $match ) ) {
			$key = stripslashes( $match[1] );
			if ( ! isset( $this->events[ $key ] ) ) {
				return null;
			}
			return false !== strpos( (string) $query, 'SELECT outcome' ) ? $this->events[ $key ]['outcome'] : 1;
		}
		return null;
	}

	public function insert( $table, $data, $formats = null ) {
		if ( false !== strpos( (string) $table, 'payment_events' ) ) {
			$key = $data['event_key'];
			if ( isset( $this->events[ $key ] ) ) {
				return false;
			}
			$this->events[ $key ] = $data;
			return 1;
		}
		foreach ( $this->rows as $row ) {
			foreach ( array( 'attempt_key', 'checkout_key', 'provider_reference' ) as $field ) {
				if ( null !== ( $data[ $field ] ?? null ) && (string) $data[ $field ] === (string) ( $row[ $field ] ?? '' ) ) {
					return false;
				}
			}
		}
		$data['id'] = $this->next_id++;
		$this->insert_id = $data['id'];
		$this->rows[ $data['id'] ] = $data;
		return 1;
	}

	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		if ( false !== strpos( (string) $table, 'payment_events' ) ) {
			$key = $where['event_key'] ?? '';
			if ( ! isset( $this->events[ $key ] ) ) {
				return 0;
			}
			$this->events[ $key ] = array_merge( $this->events[ $key ], $data );
			return 1;
		}
		$id = (int) ( $where['id'] ?? 0 );
		if ( ! isset( $this->rows[ $id ] ) ) {
			return 0;
		}
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
		return 1;
	}

	public function query( $query ) {
		$query = (string) $query;
		if ( preg_match( "/SET status = 'provisioning'.*WHERE id = (\d+) AND provider_reference IS NULL/", $query, $match ) ) {
			$id = (int) $match[1];
			if ( ! isset( $this->rows[ $id ] ) || null !== $this->rows[ $id ]['provider_reference'] ) {
				return 0;
			}
			$claimable = 'created' === $this->rows[ $id ]['status'];
			if ( ! $claimable && 'provisioning' === $this->rows[ $id ]['status'] && preg_match( "/updated_at < '([^']+)'/", $query, $cutoff ) ) {
				$claimable = (string) $this->rows[ $id ]['updated_at'] < stripslashes( $cutoff[1] );
			}
			if ( ! $claimable ) {
				return 0;
			}
			$this->rows[ $id ]['status'] = 'provisioning';
			return 1;
		}
		if ( preg_match( "/SET status = 'created', last_error = '([^']*)'.*WHERE id = (\d+) AND status = 'provisioning' AND provider_reference IS NULL/", $query, $match ) ) {
			$id = (int) $match[2];
			if ( ! isset( $this->rows[ $id ] ) || 'provisioning' !== $this->rows[ $id ]['status'] || null !== $this->rows[ $id ]['provider_reference'] ) {
				return 0;
			}
			$this->rows[ $id ]['status'] = 'created';
			$this->rows[ $id ]['last_error'] = stripslashes( $match[1] );
			return 1;
		}
		if ( preg_match( "/SET provider_reference = '([^']+)', status = '([^']+)', provider_status = '([^']+)'.*WHERE id = (\d+) AND status = 'provisioning' AND provider_reference IS NULL/", $query, $match ) ) {
			$id = (int) $match[4];
			if ( ! isset( $this->rows[ $id ] ) || 'provisioning' !== $this->rows[ $id ]['status'] || null !== $this->rows[ $id ]['provider_reference'] ) {
				return 0;
			}
			$this->rows[ $id ]['provider_reference'] = stripslashes( $match[1] );
			$this->rows[ $id ]['status'] = stripslashes( $match[2] );
			$this->rows[ $id ]['provider_status'] = stripslashes( $match[3] );
			return 1;
		}
		if ( preg_match( "/payment_events.*SET outcome = 'received'.*WHERE event_key = '([^']+)'.*updated_at < '([^']+)'/", $query, $match ) ) {
			$key = stripslashes( $match[1] );
			if ( ! isset( $this->events[ $key ] ) ) {
				return 0;
			}
			$reclaimable = 'retry' === $this->events[ $key ]['outcome'] || ( 'received' === $this->events[ $key ]['outcome'] && (string) $this->events[ $key ]['updated_at'] < stripslashes( $match[2] ) );
			if ( ! $reclaimable ) {
				return 0;
			}
			$this->events[ $key ]['outcome'] = 'received';
			return 1;
		}
		return 0;
	}
}

$GLOBALS['wpdb'] = new DoughBoss_Stripe_Contract_DB();

/** Capture Stripe HTTP writes without a network dependency. */
$GLOBALS['doughboss_stripe_contract_requests'] = array();
function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['doughboss_stripe_contract_requests'][] = array( 'url' => $url, 'args' => $args );
	$is_refund = false !== strpos( $url, '/refunds' );
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode(
			$is_refund
				? array( 'id' => 're_contract_123', 'status' => 'succeeded' )
				: array( 'id' => 'pi_contract_123', 'client_secret' => 'pi_contract_123_secret', 'amount' => 2495, 'currency' => 'aud' )
		),
	);
}

require __DIR__ . '/../includes/class-doughboss-settings.php';
require __DIR__ . '/../includes/class-doughboss-payment-attempts.php';
require __DIR__ . '/../includes/class-doughboss-stripe.php';
require __DIR__ . '/../includes/class-doughboss-voucher.php';

$pass = 0;
$fail = 0;
function stripe_contract_ok( $condition, $label ) {
	global $pass, $fail;
	if ( $condition ) {
		++$pass;
		echo "  ok   {$label}\n";
	} else {
		++$fail;
		echo "  FAIL {$label}\n";
	}
}
function stripe_contract_section( $title ) { echo "\n== {$title} ==\n"; }

/** Extract one method body for source-level boundary contracts. */
function stripe_contract_method( $source, $name ) {
	$start = strpos( $source, 'function ' . $name . '(' );
	if ( false === $start ) {
		return '';
	}
	$open = strpos( $source, '{', $start );
	if ( false === $open ) {
		return '';
	}
	$depth = 0;
	$length = strlen( $source );
	for ( $i = $open; $i < $length; ++$i ) {
		if ( '{' === $source[ $i ] ) { ++$depth; }
		if ( '}' === $source[ $i ] && 0 === --$depth ) { return substr( $source, $start, $i - $start + 1 ); }
	}
	return '';
}

echo "=== DoughBoss Stripe adversarial release contract ===\n";

stripe_contract_section( 'Live readiness and signed webhook boundary' );
putenv( 'DOUGHBOSS_STRIPE_TEST_WHSEC' );
$GLOBALS['__db_options'][ DoughBoss_Settings::OPTION_KEY ] = array(
	'payments_enabled' => 1,
	'payment_gateway'  => 'stripe',
	'stripe_mode'      => 'test',
	'stripe_test_pk'   => 'pk_test_contract',
	'stripe_test_sk'   => 'sk_test_contract',
);
stripe_contract_ok( DoughBoss_Settings::stripe_ready(), 'Stripe test mode can exercise synchronous checkout before the webhook is configured' );
stripe_contract_ok( ! DoughBoss_Settings::stripe_webhook_configured(), 'test status reports that the Stripe recovery webhook is not configured' );
putenv( 'DOUGHBOSS_STRIPE_TEST_WHSEC=stripe-contract-webhook-secret' );
stripe_contract_ok( DoughBoss_Settings::stripe_webhook_configured(), 'configured Stripe test mode reports its webhook recovery path' );

$payload   = '{"id":"evt_contract_123","type":"payment_intent.succeeded","data":{"object":{"id":"pi_contract_123"}}}';
$timestamp = time();
$signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, 'stripe-contract-webhook-secret' );
stripe_contract_ok( DoughBoss_Stripe::verify_webhook_signature( $payload, 't=' . $timestamp . ',v1=' . $signature ), 'accepts a valid Stripe raw-body signature' );
stripe_contract_ok( ! DoughBoss_Stripe::verify_webhook_signature( $payload . ' ', 't=' . $timestamp . ',v1=' . $signature ), 'rejects a changed raw webhook body' );
stripe_contract_ok( ! DoughBoss_Stripe::verify_webhook_signature( $payload, 't=' . $timestamp . ',v1=' . str_repeat( '0', 64 ) ), 'rejects a forged Stripe webhook signature' );

stripe_contract_section( 'Upstream write idempotency' );
$metadata = array(
	'checkout_key' => hash( 'sha256', 'stable-checkout-contract' ),
	'attempt_key'  => hash( 'sha256', 'stable-attempt-contract' ),
	'location_id'  => 1,
);
DoughBoss_Stripe::create_payment_intent( 2495, 'AUD', $metadata );
DoughBoss_Stripe::create_payment_intent( 2495, 'AUD', $metadata );
$intent_requests = array_values( array_filter( $GLOBALS['doughboss_stripe_contract_requests'], static function ( $request ) { return false !== strpos( $request['url'], '/payment_intents' ) && 'POST' === $request['args']['method']; } ) );
$intent_one = isset( $intent_requests[0]['args']['headers']['Idempotency-Key'] ) ? $intent_requests[0]['args']['headers']['Idempotency-Key'] : '';
stripe_contract_ok( 1 === count( $intent_requests ) && '' !== $intent_one, 'duplicate checkout requests reuse one durable PaymentIntent and its initial POST has a stable idempotency key' );

$lease_attempt = DoughBoss_Payment_Attempts::create_or_find(
	array(
		'attempt_key'  => hash( 'sha256', 'lease-attempt' ),
		'checkout_key' => hash( 'sha256', 'lease-checkout' ),
		'provider'     => 'stripe',
		'location_id'  => 1,
		'amount_minor' => 1000,
		'currency'     => 'AUD',
		'status'       => 'provisioning',
	)
);
$GLOBALS['wpdb']->rows[ $lease_attempt['id'] ]['updated_at'] = gmdate( 'Y-m-d H:i:s', time() - 10 * 60 );
stripe_contract_ok( DoughBoss_Payment_Attempts::claim_creation( $lease_attempt['id'] ), 'stale unbound PaymentIntent provisioning lease can be reclaimed with the same upstream idempotency key' );

$event_key = hash( 'sha256', 'stripe|evt_contract_lease' );
stripe_contract_ok(
	DoughBoss_Payment_Attempts::claim_event( $event_key, 'stripe', 'pi_contract_123', 'payment_intent.succeeded' )
		&& ! DoughBoss_Payment_Attempts::claim_event( $event_key, 'stripe', 'pi_contract_123', 'payment_intent.succeeded' ),
	'fresh webhook claim cannot be processed twice'
);
$GLOBALS['wpdb']->events[ $event_key ]['updated_at'] = gmdate( 'Y-m-d H:i:s', time() - 10 * 60 );
stripe_contract_ok(
	DoughBoss_Payment_Attempts::claim_event( $event_key, 'stripe', 'pi_contract_123', 'payment_intent.succeeded' ),
	'stale received webhook lease can be reclaimed after an interrupted worker'
);
stripe_contract_ok( 'received' === DoughBoss_Payment_Attempts::event_outcome( $event_key ), 'webhook claim outcome remains safely queryable for duplicate versus storage-failure handling' );

DoughBoss_Stripe::create_refund( 'pi_contract_123', 2495 );
DoughBoss_Stripe::create_refund( 'pi_contract_123', 2495 );
$refund_requests = array_values( array_filter( $GLOBALS['doughboss_stripe_contract_requests'], static function ( $request ) { return false !== strpos( $request['url'], '/refunds' ); } ) );
$refund_one = isset( $refund_requests[0]['args']['headers']['Idempotency-Key'] ) ? $refund_requests[0]['args']['headers']['Idempotency-Key'] : '';
$refund_two = isset( $refund_requests[1]['args']['headers']['Idempotency-Key'] ) ? $refund_requests[1]['args']['headers']['Idempotency-Key'] : '';
stripe_contract_ok( 2 === count( $refund_requests ) && '' !== $refund_one && $refund_one === $refund_two, 'same full-or-partial refund retry carries one stable Stripe idempotency key' );

stripe_contract_section( 'Durable attempts and webhook event de-duplication' );
$stripe_source = file_get_contents( __DIR__ . '/../includes/class-doughboss-stripe.php' );
$rest_source   = file_get_contents( __DIR__ . '/../includes/class-doughboss-rest-controller.php' );
$stripe_webhook = stripe_contract_method( $rest_source, 'stripe_webhook' );
$catering_webhook = stripe_contract_method( $rest_source, 'catering_stripe_webhook' );
stripe_contract_ok(
	false !== strpos( $stripe_source, 'DoughBoss_Payment_Attempts::create_or_find' )
		&& false !== strpos( $stripe_source, 'DoughBoss_Payment_Attempts::claim_creation' )
		&& false !== strpos( $stripe_source, 'DoughBoss_Payment_Attempts::bind_provider_reference' ),
	'Stripe creates or reuses one durable attempt before a provider-side PaymentIntent write'
);
stripe_contract_ok(
	false !== strpos( $stripe_source, 'provider_reference' )
		&& false !== strpos( $stripe_source, "'GET', '/payment_intents/'" ),
	'Stripe retry path reuses the durable provider reference rather than starting a second intent'
);
stripe_contract_ok(
	false !== strpos( $stripe_source, "'payment_method_types[0]'" )
		&& false !== strpos( $stripe_source, "=> 'card'" ),
	'Stripe limits synchronous DoughBoss checkout to immediate card and card-wallet rails'
);
stripe_contract_ok(
	false !== strpos( $stripe_source, 'function create_checkout_session' )
		&& false !== strpos( $stripe_source, "'/checkout/sessions'" )
		&& false !== strpos( $stripe_source, "self::idempotency_key( 'checkout', \$checkout_key )" )
		&& false !== strpos( $stripe_source, 'payment_intent_data[metadata]' ),
	'storefront creates one idempotent hosted Checkout Session with the immutable binding copied to its PaymentIntent'
);
stripe_contract_ok(
	false !== strpos( $stripe_source, 'function stripe_secret_key_valid' )
		|| false !== strpos( file_get_contents( __DIR__ . '/../includes/class-doughboss-settings.php' ), 'function stripe_secret_key_valid' ),
	'Stripe rejects unrelated non-key credentials before an API request'
);
stripe_contract_ok(
	false === strpos( $stripe_source, "\$data['error']['message']" )
		&& false !== strpos( $stripe_source, 'We could not start payment. Please try again or contact the shop.' ),
	'provider error bodies and secret-bearing messages are never returned to the customer'
);
stripe_contract_ok(
	false !== strpos( $stripe_webhook, 'checkout.session.completed' )
		&& false !== strpos( $stripe_webhook, 'payment_intent.succeeded' ),
	'storefront webhook reconciles both hosted Checkout completion and the canonical PaymentIntent success'
);
foreach ( array( 'storefront' => $stripe_webhook, 'catering' => $catering_webhook ) as $label => $handler ) {
	stripe_contract_ok(
		false !== strpos( $handler, "['id']" )
			&& false !== strpos( $handler, 'DoughBoss_Payment_Attempts::claim_event' )
			&& false !== strpos( $handler, 'DoughBoss_Payment_Attempts::complete_event' ),
		"{$label} Stripe webhook validates a signed event ID and de-duplicates it before reconciliation"
	);
}

stripe_contract_section( 'Server-authoritative money and fulfilment' );
$verify_payment = stripe_contract_method( $rest_source, 'verify_payment' );
$checkout       = stripe_contract_method( $rest_source, 'checkout' );
stripe_contract_ok(
	false !== strpos( $verify_payment, "'succeeded' !== \$status" )
		&& false !== strpos( $verify_payment, '$amount !== $expected' )
		&& false !== strpos( $verify_payment, '$cur !== $currency' )
		&& false !== strpos( $verify_payment, '$session_amount !== $expected' )
		&& false !== strpos( $verify_payment, 'hash_equals( $expected_checkout, $session_reference )' )
		&& false !== strpos( $verify_payment, 'hash_equals( $expected_checkout, $meta_checkout )' ),
	'checkout verifies hosted session, canonical payment, amount, currency, and immutable order binding on the server'
);
stripe_contract_ok(
	false !== strpos( $checkout, '$totals = $this->cart->totals( $order_type )' )
		&& false !== strpos( $checkout, '$this->verify_payment( $request, $totals[\'total\']' ),
	'final payment verification uses the cart-computed total rather than a browser supplied amount'
);

$voucher = (object) array(
	'id' => 9, 'status' => 'issued', 'scope' => 'both', 'valid_from' => '', 'valid_to' => '',
	'min_spend' => 0, 'type' => 'fixed', 'value' => 50, 'location_id' => 0,
);
$fixed = DoughBoss_Voucher::evaluate( $voucher, 24.95, 'online' );
$voucher->type  = 'percent';
$voucher->value = 10;
$percent = DoughBoss_Voucher::evaluate( $voucher, 24.95, 'online' );
stripe_contract_ok( $fixed['valid'] && 24.95 === $fixed['amount'] && 0.0 === max( 0.0, 24.95 - $fixed['amount'] ), 'voucher discount is capped at the server subtotal so the final amount cannot go negative' );
stripe_contract_ok( $percent['valid'] && 2.5 === $percent['amount'] && 22.45 === round( 24.95 - $percent['amount'], 2 ), 'percentage voucher final amount is calculated from the server subtotal with cents rounding' );

$order_source  = file_get_contents( __DIR__ . '/../includes/class-doughboss-order.php' );
$pospal_source = file_get_contents( __DIR__ . '/../includes/class-doughboss-pospal-orders.php' );
$core_source   = file_get_contents( __DIR__ . '/../includes/class-doughboss.php' );
$pospal_handler = stripe_contract_method( $pospal_source, 'on_order_created' );
stripe_contract_ok(
	false !== strpos( $order_source, "do_action( 'doughboss_order_created'" )
		&& false !== strpos( $core_source, 'DoughBoss_POSPal_Orders::init()' )
		&& false !== strpos( $pospal_handler, 'DoughBoss_POSPal_Outbox::enqueue_order_push' )
		&& false === strpos( $pospal_handler, 'payment_method' ),
	'paid or unpaid orders follow the same provider-neutral order-created to POSPal outbox/KDS chain'
);

putenv( 'DOUGHBOSS_STRIPE_TEST_WHSEC' );
echo "\n=== RESULT: {$pass} passed · {$fail} failed ===\n";
exit( $fail ? 1 : 0 );
