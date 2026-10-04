<?php
/**
 * Tyro Connect Pay API adapter.
 *
 * Uses OAuth client credentials, server-created Pay Requests and Tyro.js.
 * Card data and paySecret never enter durable DoughBoss storage or logs.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DoughBoss_Tyro {
	const AUTH_URL = 'https://auth.connect.tyro.com/oauth/token';
	const API_URL  = 'https://api.tyro.com/connect/pay';

	/** @return bool */
	public static function ready() {
		return DoughBoss_Settings::tyro_ready();
	}

	/** @return string */
	public static function mode() {
		return DoughBoss_Settings::tyro_mode();
	}

	/** Tyro.js uses a paySecret, not a static public key. */
	public static function publishable_key() {
		return 'tyro-connect';
	}

	/** @param float $amount Major units. @return int */
	public static function to_minor_units( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Create or resume one durable Tyro Pay Request.
	 *
	 * Required metadata: checkout_key (64 hex), location_id. The caller binds
	 * checkout_key to the cart/context and a browser idempotency value.
	 *
	 * @return array|WP_Error
	 */
	public static function create_payment_intent( $amount_minor, $currency, array $metadata = array() ) {
		$amount_minor = absint( $amount_minor );
		$currency     = strtoupper( sanitize_key( $currency ) );
		$checkout_key = isset( $metadata['checkout_key'] ) ? strtolower( (string) $metadata['checkout_key'] ) : '';
		$location_id  = isset( $metadata['location_id'] ) ? absint( $metadata['location_id'] ) : 0;
		if ( $amount_minor < 1 || 3 !== strlen( $currency ) || ! preg_match( '/^[a-f0-9]{64}$/', $checkout_key ) || ! $location_id ) {
			return new WP_Error( 'doughboss_pay_request', __( 'The payment request is incomplete.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$tyro_location = DoughBoss_Locations::tyro_location_id( $location_id );
		if ( is_wp_error( $tyro_location ) ) {
			return $tyro_location;
		}

		$safe_metadata = $metadata;
		unset( $safe_metadata['checkout_key'], $safe_metadata['attempt_key'] );
		$safe_metadata['tyro_location_id'] = $tyro_location;
		$attempt = DoughBoss_Payment_Attempts::create_or_find(
			array(
				'attempt_key'     => hash( 'sha256', 'tyro|' . $checkout_key ),
				'checkout_key'    => $checkout_key,
				'provider'        => 'tyro',
				'purpose'         => isset( $metadata['purpose'] ) ? $metadata['purpose'] : 'order',
				'context'         => isset( $metadata['context'] ) ? $metadata['context'] : ( ! empty( $metadata['table_id'] ) ? 'table_qr' : 'web' ),
				'local_reference' => isset( $metadata['local_reference'] ) ? $metadata['local_reference'] : '',
				'location_id'     => $location_id,
				'table_id'        => isset( $metadata['table_id'] ) ? absint( $metadata['table_id'] ) : 0,
				'qr_code_id'      => isset( $metadata['qr_code_id'] ) ? absint( $metadata['qr_code_id'] ) : 0,
				'amount_minor'    => $amount_minor,
				'currency'        => $currency,
				'status'          => 'created',
				'safe_metadata'   => $safe_metadata,
			)
		);
		if ( ! $attempt ) {
			return new WP_Error( 'doughboss_pay_storage', __( 'The payment could not be recorded safely.', 'doughboss' ), array( 'status' => 503 ) );
		}
		if ( (int) $attempt['amount_minor'] !== $amount_minor || strtoupper( $attempt['currency'] ) !== $currency || (int) $attempt['location_id'] !== $location_id ) {
			return new WP_Error( 'doughboss_pay_attempt_changed', __( 'Your order changed while payment was being prepared. Please start payment again.', 'doughboss' ), array( 'status' => 409 ) );
		}

		if ( ! empty( $attempt['provider_reference'] ) ) {
			$existing = self::retrieve_pay_request( $attempt['provider_reference'] );
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
			return self::creation_result( $existing, $attempt );
		}
		if ( ! DoughBoss_Payment_Attempts::claim_creation( $attempt['id'] ) ) {
			$attempt = DoughBoss_Payment_Attempts::find( $attempt['id'] );
			if ( $attempt && ! empty( $attempt['provider_reference'] ) ) {
				$existing = self::retrieve_pay_request( $attempt['provider_reference'] );
				return is_wp_error( $existing ) ? $existing : self::creation_result( $existing, $attempt );
			}
			return new WP_Error( 'doughboss_pay_provisioning', __( 'Your secure payment session is still being prepared. Please wait a moment and try again.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$origin_id = 'db-attempt-' . (int) $attempt['id'];
		$result = self::request(
			'POST',
			'/requests',
			array(
				'locationId' => $tyro_location,
				'provider'   => array( 'name' => 'TYRO', 'method' => 'CARD' ),
				'origin'     => array(
					'orderId'        => $origin_id,
					'orderReference' => 'DoughBoss ' . (int) $attempt['id'],
					'name'           => 'DoughBoss',
				),
				'total'      => array( 'amount' => $amount_minor, 'currency' => $currency ),
			)
		);
		if ( is_wp_error( $result ) ) {
			DoughBoss_Payment_Attempts::update( $attempt['id'], array( 'status' => 'unknown', 'last_error' => $result->get_error_code() ) );
			return $result;
		}

		$reference = isset( $result['id'] ) ? sanitize_text_field( $result['id'] ) : '';
		if ( '' === $reference ) {
			DoughBoss_Payment_Attempts::update( $attempt['id'], array( 'status' => 'unknown', 'last_error' => 'missing_provider_reference' ) );
			return new WP_Error( 'doughboss_pay_create', __( 'Tyro did not return a payment reference.', 'doughboss' ), array( 'status' => 502 ) );
		}
		$attempt = DoughBoss_Payment_Attempts::bind_provider_reference(
			$attempt['id'],
			$reference,
			self::normalised_status( isset( $result['status'] ) ? $result['status'] : '' ),
			isset( $result['status'] ) ? $result['status'] : ''
		);
		if ( ! $attempt ) {
			return new WP_Error( 'doughboss_pay_binding', __( 'The payment reference could not be bound safely. Do not pay again until its status is checked.', 'doughboss' ), array( 'status' => 409 ) );
		}
		return self::creation_result( $result, $attempt );
	}

	/** @return array|WP_Error */
	private static function creation_result( array $pay_request, array $attempt ) {
		$secret = isset( $pay_request['paySecret'] ) ? (string) $pay_request['paySecret'] : '';
		if ( '' === $secret ) {
			return new WP_Error( 'doughboss_pay_secret', __( 'The secure payment session is no longer available. Please start a new payment.', 'doughboss' ), array( 'status' => 409 ) );
		}
		return array(
			'id'            => (string) $pay_request['id'],
			'client_secret' => $secret,
			'attempt_id'    => (int) $attempt['id'],
			'amount'        => (int) $attempt['amount_minor'],
			'currency'      => strtolower( $attempt['currency'] ),
		);
	}

	/** @return array|WP_Error */
	public static function retrieve_payment_intent( $id ) {
		$id      = self::canonical_id( $id );
		$attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $id );
		if ( ! $attempt ) {
			return new WP_Error( 'doughboss_pay_attempt', __( 'The payment attempt could not be reconciled.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$result = self::retrieve_pay_request( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$provider_status = isset( $result['status'] ) ? strtoupper( sanitize_key( $result['status'] ) ) : '';
		$status          = self::normalised_status( $provider_status );
		$total           = isset( $result['total'] ) && is_array( $result['total'] ) ? $result['total'] : array();
		$origin          = isset( $result['origin'] ) && is_array( $result['origin'] ) ? $result['origin'] : array();
		$location        = isset( $result['locationId'] ) ? (string) $result['locationId'] : '';
		$metadata        = json_decode( (string) $attempt['safe_metadata_json'], true );
		$metadata        = is_array( $metadata ) ? $metadata : array();
		$metadata['checkout_key'] = (string) $attempt['checkout_key'];
		$valid = isset( $origin['orderId'] )
			&& 'db-attempt-' . (int) $attempt['id'] === (string) $origin['orderId']
			&& isset( $total['amount'], $total['currency'] )
			&& (int) $total['amount'] === (int) $attempt['amount_minor']
			&& strtoupper( (string) $total['currency'] ) === strtoupper( $attempt['currency'] )
			&& isset( $metadata['tyro_location_id'] )
			&& (string) $metadata['tyro_location_id'] === $location;
		if ( ! $valid ) {
			DoughBoss_Payment_Attempts::update( $attempt['id'], array( 'status' => 'mismatch', 'provider_status' => $provider_status, 'last_error' => 'provider_binding_mismatch' ) );
			return new WP_Error( 'doughboss_pay_mismatch', __( 'The payment did not match this order.', 'doughboss' ), array( 'status' => 409 ) );
		}
		DoughBoss_Payment_Attempts::update(
			$attempt['id'],
			array(
				'status'          => $status,
				'provider_status' => $provider_status,
				'verified_at'     => 'succeeded' === $status ? current_time( 'mysql', true ) : '',
			)
		);
		return array(
			'id'              => $id,
			'status'          => $status,
			'provider_status' => $provider_status,
			'amount'          => (int) $total['amount'],
			'currency'        => strtolower( (string) $total['currency'] ),
			'metadata'        => $metadata,
		);
	}

	/** @return array|WP_Error */
	public static function retrieve_pay_request( $id ) {
		$id = self::canonical_id( $id );
		if ( '' === $id ) {
			return new WP_Error( 'doughboss_pay_id', __( 'Invalid payment reference.', 'doughboss' ), array( 'status' => 400 ) );
		}
		return self::request( 'GET', '/requests/' . rawurlencode( $id ) );
	}

	/** @return string */
	public static function canonical_id( $id ) {
		$id = sanitize_text_field( (string) $id );
		return preg_match( '/^[A-Za-z0-9._:-]{1,191}$/', $id ) ? $id : '';
	}

	/** @return array|WP_Error */
	public static function create_refund( $id, $amount_minor = null ) {
		$body = array( 'payRequestId' => self::canonical_id( $id ) );
		if ( '' === $body['payRequestId'] ) {
			return new WP_Error( 'doughboss_pay_id', __( 'Invalid payment reference.', 'doughboss' ), array( 'status' => 400 ) );
		}
		if ( null !== $amount_minor ) {
			$attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $body['payRequestId'] );
			if ( ! $attempt || (int) $amount_minor < 1 || (int) $amount_minor > (int) $attempt['amount_minor'] ) {
				return new WP_Error( 'doughboss_pay_refund', __( 'Invalid refund amount.', 'doughboss' ), array( 'status' => 400 ) );
			}
			$body['total'] = array( 'amount' => (int) $amount_minor, 'currency' => strtoupper( $attempt['currency'] ) );
		}
		return self::request( 'POST', '/refunds', $body );
	}

	/** Read-only OAuth authentication check. @return array|WP_Error */
	public static function test_connection() {
		$token = self::access_token();
		return is_wp_error( $token ) ? $token : array( 'connected' => true, 'mode' => self::mode() );
	}

	/** @return string */
	public static function webhook_secret() {
		return DoughBoss_Settings::tyro_webhook_secret();
	}

	/** @return bool */
	public static function verify_webhook_signature( $payload, $sig_header ) {
		$secret = self::webhook_secret();
		if ( '' === $secret || '' === (string) $payload || '' === (string) $sig_header ) {
			return false;
		}
		$candidate = trim( (string) $sig_header );
		if ( 0 === stripos( $candidate, 'sha256=' ) ) {
			$candidate = substr( $candidate, 7 );
		}
		return preg_match( '/^[a-fA-F0-9]{64}$/', $candidate ) && hash_equals( hash_hmac( 'sha256', (string) $payload, $secret ), strtolower( $candidate ) );
	}

	/** @return string */
	private static function normalised_status( $status ) {
		switch ( strtoupper( (string) $status ) ) {
			case 'SUCCESS': return 'succeeded';
			case 'FAILED': return 'failed';
			case 'VOIDED': return 'voided';
			case 'REFUNDED': return 'refunded';
			case 'PARTIALLY_REFUNDED': return 'partially_refunded';
			case 'PROCESSING':
			case 'AWAITING_AUTHENTICATION':
			case 'AWAITING_PAYMENT_INPUT':
			case 'AWAITING_CAPTURE': return 'processing';
			default: return 'unknown';
		}
	}

	/** @return string|WP_Error */
	private static function access_token() {
		$client_id     = DoughBoss_Settings::tyro_client_id();
		$client_secret = DoughBoss_Settings::tyro_client_secret();
		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error( 'doughboss_pay_config', __( 'Tyro Connect credentials are not configured.', 'doughboss' ), array( 'status' => 503 ) );
		}
		$cache_key = 'doughboss_tyro_oauth_' . substr( hash( 'sha256', self::mode() . '|' . $client_id ), 0, 32 );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$response = wp_remote_post(
			self::AUTH_URL,
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'grant_type'    => 'client_credentials',
					'audience'      => 'https://app.connect.tyro',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'doughboss_pay_network', __( 'Could not reach Tyro. Please try again.', 'doughboss' ), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			return new WP_Error( 'doughboss_pay_auth', __( 'Tyro could not authenticate these credentials.', 'doughboss' ), array( 'status' => 502, 'http_status' => $code ) );
		}
		$ttl = isset( $data['expires_in'] ) ? max( 300, (int) $data['expires_in'] - 300 ) : 11 * HOUR_IN_SECONDS;
		set_transient( $cache_key, (string) $data['access_token'], $ttl );
		return (string) $data['access_token'];
	}

	/** @return array|WP_Error */
	private static function request( $method, $path, array $body = array() ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
		);
		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( self::API_URL . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'doughboss_pay_network', __( 'Could not reach Tyro. Do not retry payment until its status is checked.', 'doughboss' ), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			return $data;
		}
		return new WP_Error( 'doughboss_pay_api', __( 'Tyro returned an error. No card details were stored.', 'doughboss' ), array( 'status' => 502, 'http_status' => $code ) );
	}
}
