<?php
/**
 * Stripe payment gateway (optional, off by default).
 *
 * A thin, dependency-free wrapper over the Stripe REST API used to create and
 * verify PaymentIntents for online card payments. No SDK is bundled — calls go
 * through `wp_remote_*`. When payments are disabled or unconfigured the whole
 * feature is dormant and the storefront behaves exactly as a pay-on-pickup site.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal Stripe PaymentIntents client.
 */
class DoughBoss_Stripe {

	const API_BASE = 'https://api.stripe.com/v1';

	/**
	 * Whether card payments are switched on AND fully configured for the
	 * current (test/live) mode. The single gate the rest of the plugin checks.
	 *
	 * @return bool
	 */
	public static function ready() {
		return DoughBoss_Settings::stripe_ready();
	}

	/**
	 * Active mode: 'test' or 'live'.
	 *
	 * @return string
	 */
	public static function mode() {
		return DoughBoss_Settings::stripe_mode();
	}

	/**
	 * Publishable key for the active mode (safe to expose to the browser).
	 *
	 * @return string
	 */
	public static function publishable_key() {
		return DoughBoss_Settings::stripe_publishable_key();
	}

	/**
	 * Secret key for the active mode (server-side only — never sent to a client).
	 *
	 * @return string
	 */
	private static function secret_key() {
		return DoughBoss_Settings::stripe_secret_key();
	}

	/**
	 * Convert a major-unit amount (e.g. dollars) to the smallest currency unit
	 * (e.g. cents) Stripe expects.
	 *
	 * @param float $amount Major-unit amount.
	 * @return int
	 */
	public static function to_minor_units( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * The id that should be persisted (order row / dedup lookups). Stripe's
	 * PaymentIntent id already IS the canonical, storable reference — no
	 * composite-id scheme like Tyro's — so this is a passthrough. Exists so
	 * DoughBoss_Payment::canonical_id() can dispatch identically regardless of
	 * the active gateway.
	 *
	 * @param string $id PaymentIntent id.
	 * @return string
	 */
	public static function canonical_id( $id ) {
		return sanitize_text_field( (string) $id );
	}

	/**
	 * Create a PaymentIntent for the given amount.
	 *
	 * @param int    $amount_minor Amount in the smallest currency unit (cents).
	 * @param string $currency     ISO currency code (e.g. AUD).
	 * @param array  $metadata     Optional key/value metadata to attach.
	 * @return array|WP_Error { id, client_secret, amount, currency } or error.
	 */
	public static function create_payment_intent( $amount_minor, $currency, array $metadata = array() ) {
		$amount_minor = absint( $amount_minor );
		$currency     = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $currency ) );
		$checkout_key = isset( $metadata['checkout_key'] ) ? strtolower( sanitize_text_field( (string) $metadata['checkout_key'] ) ) : '';
		$location_id  = isset( $metadata['location_id'] ) ? absint( $metadata['location_id'] ) : 0;
		$table_id     = isset( $metadata['table_id'] ) ? absint( $metadata['table_id'] ) : 0;
		$qr_code_id   = isset( $metadata['qr_code_id'] ) ? absint( $metadata['qr_code_id'] ) : 0;

		if ( $amount_minor < 1 || 3 !== strlen( $currency ) || ! preg_match( '/^[a-f0-9]{64}$/', $checkout_key ) || ! $location_id ) {
			return new WP_Error( 'doughboss_pay_request', __( 'The payment request is incomplete.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$safe_metadata = $metadata;
		unset( $safe_metadata['attempt_key'] );
		$attempt = DoughBoss_Payment_Attempts::create_or_find(
			array(
				'attempt_key'     => hash( 'sha256', 'stripe|' . $checkout_key ),
				'checkout_key'    => $checkout_key,
				'provider'        => 'stripe',
				'purpose'         => isset( $metadata['purpose'] ) ? $metadata['purpose'] : 'order',
				'context'         => isset( $metadata['context'] ) ? $metadata['context'] : 'web',
				'local_reference' => isset( $metadata['local_reference'] ) ? $metadata['local_reference'] : '',
				'location_id'     => $location_id,
				'table_id'        => $table_id,
				'qr_code_id'      => $qr_code_id,
				'amount_minor'    => $amount_minor,
				'currency'        => $currency,
				'status'          => 'created',
				'safe_metadata'   => $safe_metadata,
			)
		);
		if ( ! $attempt ) {
			return new WP_Error( 'doughboss_pay_storage', __( 'The payment could not be recorded safely.', 'doughboss' ), array( 'status' => 503 ) );
		}
		if (
			'stripe' !== (string) $attempt['provider']
			|| (int) $attempt['amount_minor'] !== $amount_minor
			|| strtoupper( (string) $attempt['currency'] ) !== $currency
			|| (int) $attempt['location_id'] !== $location_id
			|| (int) $attempt['table_id'] !== $table_id
			|| (int) $attempt['qr_code_id'] !== $qr_code_id
		) {
			return new WP_Error( 'doughboss_pay_attempt_changed', __( 'Your order changed while payment was being prepared. Please start payment again.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$payment_intent_id = isset( $attempt['provider_reference'] ) ? (string) $attempt['provider_reference'] : '';
		if ( '' !== $payment_intent_id ) {
			$response = self::request( 'GET', '/payment_intents/' . rawurlencode( $payment_intent_id ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			return self::intent_payload( $response, $attempt, $amount_minor, $currency );
		}

		if ( ! DoughBoss_Payment_Attempts::claim_creation( (int) $attempt['id'] ) ) {
			$attempt = DoughBoss_Payment_Attempts::find( (int) $attempt['id'] );
			if ( $attempt && ! empty( $attempt['provider_reference'] ) ) {
				$response = self::request( 'GET', '/payment_intents/' . rawurlencode( (string) $attempt['provider_reference'] ) );
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				return self::intent_payload( $response, $attempt, $amount_minor, $currency );
			}
			return new WP_Error( 'doughboss_pay_provisioning', __( 'Your secure payment session is still being prepared. Please wait a moment and try again.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$body = array(
			'amount'                  => $amount_minor,
			'currency'                => strtolower( $currency ),
			'payment_method_types[0]' => 'card',
		);
		foreach ( $metadata as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$body[ 'metadata[' . sanitize_key( $key ) . ']' ] = (string) $value;
			}
		}

		$response = self::request(
			'POST',
			'/payment_intents',
			$body,
			self::idempotency_key( 'pi', $checkout_key )
		);
		if ( is_wp_error( $response ) ) {
			// The same Stripe idempotency key makes a later replay safe even if
			// the network failed after Stripe accepted the original request.
			DoughBoss_Payment_Attempts::release_creation( (int) $attempt['id'], $response->get_error_code() );
			return $response;
		}

		$payload = self::intent_payload( $response, $attempt, $amount_minor, $currency );
		if ( is_wp_error( $payload ) ) {
			DoughBoss_Payment_Attempts::release_creation( (int) $attempt['id'], $payload->get_error_code() );
			return $payload;
		}

		$bound = DoughBoss_Payment_Attempts::bind_provider_reference(
			(int) $attempt['id'],
			(string) $payload['id'],
			'processing',
			isset( $response['status'] ) ? (string) $response['status'] : 'requires_payment_method'
		);
		if ( ! $bound ) {
			$bound = DoughBoss_Payment_Attempts::find( (int) $attempt['id'] );
			if ( ! $bound || (string) $bound['provider_reference'] !== (string) $payload['id'] ) {
				DoughBoss_Payment_Attempts::release_creation( (int) $attempt['id'], 'doughboss_pay_binding' );
				return new WP_Error( 'doughboss_pay_binding', __( 'The payment reference could not be bound safely.', 'doughboss' ), array( 'status' => 409 ) );
			}
		}

		$payload['attempt_id'] = (int) $bound['id'];
		return $payload;
	}

	/**
	 * Retrieve a PaymentIntent so its status/amount can be verified server-side
	 * before an order is trusted as paid.
	 *
	 * @param string $id PaymentIntent id.
	 * @return array|WP_Error
	 */
	public static function retrieve_payment_intent( $id ) {
		$id = sanitize_text_field( $id );
		if ( '' === $id || 0 !== strpos( $id, 'pi_' ) ) {
			return new WP_Error( 'doughboss_pay_id', __( 'Invalid payment reference.', 'doughboss' ), array( 'status' => 400 ) );
		}
		return self::request( 'GET', '/payment_intents/' . rawurlencode( $id ) );
	}

	/**
	 * Refund a PaymentIntent, in full or in part.
	 *
	 * @param string   $payment_intent_id PaymentIntent id.
	 * @param int|null $amount_minor      Amount in cents, or null for a full refund.
	 * @return array|WP_Error
	 */
	public static function create_refund( $payment_intent_id, $amount_minor = null ) {
		$payment_intent_id = sanitize_text_field( $payment_intent_id );
		if ( '' === $payment_intent_id || 0 !== strpos( $payment_intent_id, 'pi_' ) ) {
			return new WP_Error( 'doughboss_pay_id', __( 'Invalid payment reference.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$body = array( 'payment_intent' => $payment_intent_id );
		if ( null !== $amount_minor ) {
			$body['amount'] = max( 1, (int) $amount_minor );
		}

		$refund_scope = null === $amount_minor ? 'full' : (string) max( 1, (int) $amount_minor );
		return self::request(
			'POST',
			'/refunds',
			$body,
			self::idempotency_key( 'refund', $payment_intent_id . '|' . $refund_scope )
		);
	}

	/**
	 * Validate and normalise a PaymentIntent returned by create or replay.
	 *
	 * @param array  $response     Stripe response.
	 * @param array  $attempt      Durable attempt row.
	 * @param int    $amount_minor Expected amount in cents.
	 * @param string $currency     Expected ISO currency.
	 * @return array|WP_Error
	 */
	private static function intent_payload( array $response, array $attempt, $amount_minor, $currency ) {
		$id                = isset( $response['id'] ) ? sanitize_text_field( (string) $response['id'] ) : '';
		$client_secret     = isset( $response['client_secret'] ) ? (string) $response['client_secret'] : '';
		$returned_amount   = isset( $response['amount'] ) ? (int) $response['amount'] : -1;
		$returned_currency = isset( $response['currency'] ) ? strtoupper( (string) $response['currency'] ) : '';

		if (
			! preg_match( '/^pi_[A-Za-z0-9_]{8,191}$/', $id )
			|| '' === $client_secret
			|| $returned_amount !== (int) $amount_minor
			|| $returned_currency !== strtoupper( (string) $currency )
		) {
			return new WP_Error( 'doughboss_pay_create', __( 'Could not start the card payment. Please try again.', 'doughboss' ), array( 'status' => 502 ) );
		}

		return array(
			'id'            => $id,
			'client_secret' => $client_secret,
			'attempt_id'    => isset( $attempt['id'] ) ? (int) $attempt['id'] : 0,
			'amount'        => $returned_amount,
			'currency'      => strtolower( $returned_currency ),
		);
	}

	/**
	 * Build a provider-safe idempotency key without exposing checkout identity.
	 *
	 * @param string $operation Short operation name.
	 * @param string $identity  Stable server-owned operation identity.
	 * @return string
	 */
	private static function idempotency_key( $operation, $identity ) {
		// Identity is already a non-reversible server checkout key or provider
		// reference. Do not depend on rotating WordPress salts for provider replay.
		return 'doughboss-' . sanitize_key( $operation ) . '-' . hash( 'sha256', sanitize_key( $operation ) . '|' . (string) $identity );
	}

	/**
	 * Webhook signing secret for the active mode (server-side only).
	 *
	 * @return string
	 */
	public static function webhook_secret() {
		return DoughBoss_Settings::stripe_webhook_secret();
	}

	/**
	 * Verify a Stripe webhook signature (the `Stripe-Signature` header) against
	 * the configured signing secret, with the same scheme Stripe's SDK uses.
	 *
	 * @param string $payload    Raw request body, exactly as received.
	 * @param string $sig_header The Stripe-Signature header value.
	 * @param int    $tolerance  Max age in seconds (0 to skip the timestamp check).
	 * @return bool
	 */
	public static function verify_webhook_signature( $payload, $sig_header, $tolerance = 300 ) {
		$secret = self::webhook_secret();
		if ( '' === $secret || '' === (string) $sig_header ) {
			return false;
		}

		$timestamp = '';
		$signatures = array();
		foreach ( explode( ',', (string) $sig_header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}

		if ( '' === $timestamp || empty( $signatures ) ) {
			return false;
		}
		if ( $tolerance > 0 && abs( time() - (int) $timestamp ) > $tolerance ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Perform an authenticated request to the Stripe API.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   API path beginning with '/'.
	 * @param array  $body   Form-encoded body for write calls.
	 * @return array|WP_Error Decoded JSON, or an error.
	 */
	private static function request( $method, $path, array $body = array(), $idempotency_key = '' ) {
		$secret = self::secret_key();
		if ( '' === $secret ) {
			return new WP_Error( 'doughboss_pay_config', __( 'Card payments are not configured.', 'doughboss' ), array( 'status' => 503 ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $secret,
				'Stripe-Version' => '2024-06-20',
			),
		);
		if ( 'POST' === strtoupper( (string) $method ) && '' !== (string) $idempotency_key ) {
			$args['headers']['Idempotency-Key'] = substr( sanitize_text_field( (string) $idempotency_key ), 0, 255 );
		}
		if ( 'GET' !== $method ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'doughboss_pay_network', __( 'Could not reach the payment service. Please try again.', 'doughboss' ), array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			return $data;
		}

		$message = ( is_array( $data ) && isset( $data['error']['message'] ) )
			? $data['error']['message']
			: __( 'The payment service returned an error.', 'doughboss' );

		// Log only the status + Stripe's short error type/code for the operator —
		// never the response body or 'message' (both can carry customer PII such
		// as receipt_email, name, address, or decline details).
		if ( function_exists( 'error_log' ) ) {
			$error_type = ( is_array( $data ) && isset( $data['error']['type'] ) && is_scalar( $data['error']['type'] ) )
				? (string) $data['error']['type']
				: '';
			$error_code = ( is_array( $data ) && isset( $data['error']['code'] ) && is_scalar( $data['error']['code'] ) )
				? (string) $data['error']['code']
				: '';

			if ( '' !== $error_type || '' !== $error_code ) {
				error_log( 'DoughBoss Stripe error: HTTP ' . $code . ' type=' . $error_type . ' code=' . $error_code ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				error_log( 'DoughBoss Stripe error: HTTP ' . $code ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		return new WP_Error( 'doughboss_pay_api', $message, array( 'status' => 502 ) );
	}
}
