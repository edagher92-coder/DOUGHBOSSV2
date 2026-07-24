<?php
/**
 * Focused Tyro Connect contract tests.
 *
 * Uses local WordPress stubs and an in-memory database double only. No network
 * calls or gateway credentials are required.
 *
 * Run: php tests/tyro-contract.php
 *
 * @package DoughBoss\Tests
 */

require __DIR__ . '/wp-stubs.php';

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $data ) {
		return $data instanceof WP_REST_Response ? $data : new WP_REST_Response( $data );
	}
}

/** Small persistence double for payment-attempt contracts. */
class DoughBoss_Tyro_Contract_DB extends DB_Stub {
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
			return isset( $this->events[ stripslashes( $match[1] ) ] ) ? 1 : null;
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
		if ( preg_match( "/SET status = 'provisioning'.*WHERE id = (\d+) AND status = 'created' AND provider_reference IS NULL/", (string) $query, $match ) ) {
			$id = (int) $match[1];
			if ( ! isset( $this->rows[ $id ] ) || 'created' !== $this->rows[ $id ]['status'] || null !== $this->rows[ $id ]['provider_reference'] ) {
				return 0;
			}
			$this->rows[ $id ]['status'] = 'provisioning';
			return 1;
		}
		if ( preg_match( "/SET provider_reference = '([^']+)', status = '([^']+)', provider_status = '([^']+)'.*WHERE id = (\d+) AND status = 'provisioning' AND provider_reference IS NULL/", (string) $query, $match ) ) {
			$id = (int) $match[4];
			if ( ! isset( $this->rows[ $id ] ) || 'provisioning' !== $this->rows[ $id ]['status'] || null !== $this->rows[ $id ]['provider_reference'] ) {
				return 0;
			}
			$this->rows[ $id ]['provider_reference'] = stripslashes( $match[1] );
			$this->rows[ $id ]['status'] = stripslashes( $match[2] );
			$this->rows[ $id ]['provider_status'] = stripslashes( $match[3] );
			return 1;
		}
		return 0;
	}
}

/** Add raw-body support without changing the shared test stub. */
class DoughBoss_Tyro_Contract_Request extends WP_REST_Request {
	private $raw_body;
	public function __construct( $params, $headers, $raw_body ) {
		parent::__construct( $params, $headers );
		$this->raw_body = $raw_body;
	}
	public function get_body() { return $this->raw_body; }
}

$GLOBALS['wpdb'] = new DoughBoss_Tyro_Contract_DB();

require __DIR__ . '/../includes/class-doughboss-settings.php';
require __DIR__ . '/../includes/class-doughboss-payment-attempts.php';
require __DIR__ . '/../includes/class-doughboss-tyro.php';
require __DIR__ . '/../includes/class-doughboss-cart.php';
require __DIR__ . '/../includes/class-doughboss-rest-controller.php';

$fail = 0;
$pass = 0;
function tyro_ok( $condition, $label ) {
	global $fail, $pass;
	if ( $condition ) {
		$pass++;
		echo "  ok   $label\n";
	} else {
		$fail++;
		echo "  FAIL $label\n";
	}
}
function tyro_section( $title ) { echo "\n== $title ==\n"; }

echo "=== DoughBoss Tyro Connect contract tests ===\n";

tyro_section( 'Fail-closed configuration' );
$GLOBALS['__db_options'][ DoughBoss_Settings::OPTION_KEY ] = array();
putenv( 'DOUGHBOSS_TYRO_TEST_CLIENT_SECRET' );
putenv( 'DOUGHBOSS_TYRO_LIVE_CLIENT_SECRET' );
tyro_ok( ! DoughBoss_Settings::tyro_ready(), 'Tyro is off with default settings' );
$GLOBALS['__db_options'][ DoughBoss_Settings::OPTION_KEY ] = array(
	'payments_enabled' => 1,
	'payment_gateway' => 'tyro',
	'tyro_mode' => 'test',
);
tyro_ok( ! DoughBoss_Settings::tyro_ready(), 'Tyro stays off without both test credentials' );
$GLOBALS['__db_options'][ DoughBoss_Settings::OPTION_KEY ]['tyro_test_client_id'] = 'test-client';
$GLOBALS['__db_options'][ DoughBoss_Settings::OPTION_KEY ]['tyro_test_client_secret'] = 'test-secret';
tyro_ok( DoughBoss_Settings::tyro_ready(), 'Tyro can be ready only in configured test mode' );
$GLOBALS['__db_options'][ DoughBoss_Settings::OPTION_KEY ]['tyro_mode'] = 'live';
$GLOBALS['__db_options'][ DoughBoss_Settings::OPTION_KEY ]['tyro_live_client_id'] = 'live-client';
$GLOBALS['__db_options'][ DoughBoss_Settings::OPTION_KEY ]['tyro_live_client_secret'] = 'live-secret';
tyro_ok( ! DoughBoss_Settings::tyro_ready(), 'live Tyro stays off until certification is recorded' );
$GLOBALS['__db_options'][ DoughBoss_Settings::OPTION_KEY ]['tyro_live_certified'] = 1;
tyro_ok( DoughBoss_Settings::tyro_ready(), 'live Tyro requires credentials plus certification' );

tyro_section( 'Webhook signature and status contract' );
$GLOBALS['__db_options'][ DoughBoss_Settings::OPTION_KEY ]['tyro_mode'] = 'test';
putenv( 'DOUGHBOSS_TYRO_TEST_WHSEC=tyro-contract-webhook-secret' );
$raw = '{"type":"PAY_REQUEST_UPDATED","data":{"id":"pay_123","resource":"payrequest"}}';
$signature = hash_hmac( 'sha256', $raw, 'tyro-contract-webhook-secret' );
tyro_ok( DoughBoss_Tyro::verify_webhook_signature( $raw, $signature ), 'accepts an exact HMAC-SHA256 raw-body signature' );
tyro_ok( DoughBoss_Tyro::verify_webhook_signature( $raw, 'sha256=' . $signature ), 'accepts the optional sha256= signature prefix' );
tyro_ok( ! DoughBoss_Tyro::verify_webhook_signature( $raw . ' ', $signature ), 'rejects a changed raw body' );
tyro_ok( ! DoughBoss_Tyro::verify_webhook_signature( $raw, str_repeat( '0', 64 ) ), 'rejects an invalid signature' );
$normalise = new ReflectionMethod( 'DoughBoss_Tyro', 'normalised_status' );
$normalise->setAccessible( true );
foreach ( array( 'SUCCESS' => 'succeeded', 'FAILED' => 'failed', 'VOIDED' => 'voided', 'REFUNDED' => 'refunded', 'PARTIALLY_REFUNDED' => 'partially_refunded', 'AWAITING_PAYMENT_INPUT' => 'processing', 'AWAITING_AUTHENTICATION' => 'processing', 'PROCESSING' => 'processing', 'AWAITING_CAPTURE' => 'processing', 'unexpected' => 'unknown' ) as $provider => $expected ) {
	tyro_ok( $expected === $normalise->invoke( null, $provider ), "maps $provider to $expected" );
}
tyro_ok( '' === DoughBoss_Tyro::canonical_id( 'pay/unsafe' ), 'rejects unsafe provider references' );

tyro_section( 'Durable payment storage redaction and idempotency' );
$attempt_key = hash( 'sha256', 'attempt-contract' );
$checkout_key = hash( 'sha256', 'checkout-contract' );
$attempt = DoughBoss_Payment_Attempts::create_or_find(
	array(
		'attempt_key' => $attempt_key,
		'checkout_key' => $checkout_key,
		'provider' => 'tyro',
		'provider_reference' => 'pay_safe_reference',
		'location_id' => 7,
		'amount_minor' => 2495,
		'currency' => 'AUD',
		'safe_metadata' => array(
			'context' => 'table_qr',
			'location_name' => 'Revesby',
			'paySecret' => 'must-not-persist',
			'card' => array( 'number' => '4111111111111111' ),
			'nested' => array( 'token' => 'must-not-persist', 'table_label' => 'T4' ),
			'customer_note' => '4111 1111 1111 1111',
		),
	)
);
$stored = $GLOBALS['wpdb']->rows[ (int) $attempt['id'] ];
tyro_ok( is_array( $attempt ) && (int) $attempt['amount_minor'] === 2495, 'stores a valid durable attempt' );
tyro_ok( false === strpos( $stored['safe_metadata_json'], 'must-not-persist' ), 'does not persist pay secrets or tokens' );
tyro_ok( false === strpos( $stored['safe_metadata_json'], '4111111111111111' ), 'does not persist PAN-shaped data' );
tyro_ok( false !== strpos( $stored['safe_metadata_json'], 'table_label' ), 'retains non-sensitive reconciliation metadata' );
$replayed = DoughBoss_Payment_Attempts::create_or_find(
	array( 'attempt_key' => $attempt_key, 'checkout_key' => $checkout_key, 'provider' => 'tyro', 'amount_minor' => 2495, 'currency' => 'AUD' )
);
tyro_ok( (int) $attempt['id'] === (int) $replayed['id'] && 1 === count( $GLOBALS['wpdb']->rows ), 'same durable checkout key replays one attempt' );

$creation_attempt = DoughBoss_Payment_Attempts::create_or_find(
	array(
		'attempt_key' => hash( 'sha256', 'atomic-create-attempt' ),
		'checkout_key' => hash( 'sha256', 'atomic-create-checkout' ),
		'provider' => 'tyro',
		'location_id' => 7,
		'amount_minor' => 2495,
		'currency' => 'AUD',
		'status' => 'created',
	)
);
tyro_ok( DoughBoss_Payment_Attempts::claim_creation( $creation_attempt['id'] ), 'one caller atomically owns upstream Pay Request creation' );
tyro_ok( ! DoughBoss_Payment_Attempts::claim_creation( $creation_attempt['id'] ), 'a concurrent caller cannot own the same upstream creation' );
$bound_attempt = DoughBoss_Payment_Attempts::bind_provider_reference( $creation_attempt['id'], 'pay_atomic_123', 'processing', 'AWAITING_PAYMENT_INPUT' );
tyro_ok( is_array( $bound_attempt ) && 'pay_atomic_123' === $bound_attempt['provider_reference'], 'owned creation binds one immutable provider reference' );
tyro_ok( false === DoughBoss_Payment_Attempts::bind_provider_reference( $creation_attempt['id'], 'pay_changed_456', 'succeeded', 'SUCCESS' ), 'provider reference cannot be rebound' );

$tyro_source = file_get_contents( __DIR__ . '/../includes/class-doughboss-tyro.php' );
$rest_source = file_get_contents( __DIR__ . '/../includes/class-doughboss-rest-controller.php' );
$browser_source = file_get_contents( __DIR__ . '/../public/js/doughboss.js' );
tyro_ok( false === strpos( $tyro_source, "'pay_secret'" ), 'Tyro result does not duplicate the browser pay secret' );
tyro_ok( false !== strpos( $rest_source, 'hash_equals( $expected_checkout, $meta_checkout )' ), 'final checkout compares the immutable payment checkout key' );
tyro_ok( false !== strpos( $browser_source, 'payload.payment_attempt_key = paymentAttemptKey' ), 'browser carries the same payment-attempt key into final checkout' );

tyro_section( 'Server-bound checkout key and duplicate webhook event' );
$controller = new DoughBoss_REST_Controller( new DoughBoss_Cart() );
$checkout_key_method = new ReflectionMethod( 'DoughBoss_REST_Controller', 'payment_checkout_key' );
$checkout_key_method->setAccessible( true );
$payment_request = new WP_REST_Request( array( 'payment_attempt_key' => 'browser-attempt-1234' ) );
$snapshot = array( 'amount_minor' => 2495, 'currency' => 'AUD', 'location_id' => 7 );
$bound_one = $checkout_key_method->invoke( $controller, $payment_request, 'order', $snapshot );
$bound_two = $checkout_key_method->invoke( $controller, $payment_request, 'order', $snapshot );
$changed = $checkout_key_method->invoke( $controller, $payment_request, 'order', array_merge( $snapshot, array( 'amount_minor' => 2595 ) ) );
tyro_ok( is_string( $bound_one ) && preg_match( '/^[a-f0-9]{64}$/', $bound_one ), 'creates a server-bound SHA-256 checkout key' );
tyro_ok( $bound_one === $bound_two && $bound_one !== $changed, 'checkout key is repeatable only for the same server snapshot' );
$invalid_key = $checkout_key_method->invoke( $controller, new WP_REST_Request( array( 'payment_attempt_key' => 'short' ) ), 'order', $snapshot );
tyro_ok( is_wp_error( $invalid_key ) && 'doughboss_pay_attempt_key' === $invalid_key->get_error_code(), 'fails closed for an invalid browser payment key' );
$event_key = hash( 'sha256', 'PAY_REQUEST_UPDATED|payrequest|pay_123' );
tyro_ok( DoughBoss_Payment_Attempts::claim_event( $event_key, 'tyro', 'pay_123', 'PAY_REQUEST_UPDATED' ), 'first webhook event claim succeeds' );
tyro_ok( ! DoughBoss_Payment_Attempts::claim_event( $event_key, 'tyro', 'pay_123', 'PAY_REQUEST_UPDATED' ), 'same webhook event cannot be claimed twice' );
$webhook_request = new DoughBoss_Tyro_Contract_Request( array(), array( 'tyro_connect_signature' => $signature ), $raw );
$webhook_response = $controller->tyro_webhook( $webhook_request );
tyro_ok( $webhook_response instanceof WP_REST_Response && ! empty( $webhook_response->data['duplicate'] ), 'duplicate webhook exits before any provider retrieval' );

putenv( 'DOUGHBOSS_TYRO_TEST_WHSEC' );
echo "\n=== RESULT: $pass passed · $fail failed ===\n";
exit( $fail > 0 ? 1 : 0 );
