<?php
/**
 * Mastercard Payment Gateway Services (MPGS) Hosted Checkout adapter.
 *
 * Card details are collected on Mastercard's hosted payment page. DoughBoss
 * sends only order amount/currency and later retrieves the order server-side
 * before recording it as paid.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DoughBoss_MPGS {

	/** @return bool */
	public static function ready() {
		return DoughBoss_Settings::mpgs_ready();
	}

	/** @return string */
	public static function mode() {
		return DoughBoss_Settings::mpgs_mode();
	}

	/** Public marker used by the browser bootstrap. */
	public static function publishable_key() {
		return 'mpgs-hosted-checkout';
	}

	/** @param float $amount Major units. @return int */
	public static function to_minor_units( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/** @return string */
	public static function checkout_script_url() {
		$host = DoughBoss_Settings::mpgs_host();
		return $host ? $host . '/checkout/version/' . DoughBoss_Settings::mpgs_api_version() . '/checkout.js' : '';
	}

	/**
	 * Create a durable attempt and an ephemeral Mastercard Hosted Checkout
	 * session. The session ID is returned to the browser but never persisted.
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

		$safe_metadata = $metadata;
		unset( $safe_metadata['checkout_key'], $safe_metadata['attempt_key'] );
		$attempt = DoughBoss_Payment_Attempts::create_or_find(
			array(
				'attempt_key'   => hash( 'sha256', 'mpgs|' . $checkout_key ),
				'checkout_key'  => $checkout_key,
				'provider'      => 'mpgs',
				'purpose'       => isset( $metadata['purpose'] ) ? $metadata['purpose'] : 'order',
				'context'       => isset( $metadata['context'] ) ? $metadata['context'] : 'web',
				'location_id'   => $location_id,
				'table_id'      => isset( $metadata['table_id'] ) ? absint( $metadata['table_id'] ) : 0,
				'qr_code_id'    => isset( $metadata['qr_code_id'] ) ? absint( $metadata['qr_code_id'] ) : 0,
				'amount_minor'  => $amount_minor,
				'currency'      => $currency,
				'status'        => 'created',
				'safe_metadata' => $safe_metadata,
			)
		);
		if ( ! $attempt ) {
			return new WP_Error( 'doughboss_pay_storage', __( 'The payment could not be recorded safely.', 'doughboss' ), array( 'status' => 503 ) );
		}
		if ( (int) $attempt['amount_minor'] !== $amount_minor || strtoupper( $attempt['currency'] ) !== $currency || (int) $attempt['location_id'] !== $location_id ) {
			return new WP_Error( 'doughboss_pay_attempt_changed', __( 'Your order changed while payment was being prepared. Please start payment again.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$order_id = isset( $attempt['provider_reference'] ) ? (string) $attempt['provider_reference'] : '';
		if ( '' === $order_id ) {
			if ( ! DoughBoss_Payment_Attempts::claim_creation( $attempt['id'] ) ) {
				return new WP_Error( 'doughboss_pay_provisioning', __( 'Your secure payment session is still being prepared. Please wait a moment and try again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			$order_id = 'DB-' . (int) $attempt['id'] . '-' . substr( $checkout_key, 0, 12 );
			$attempt = DoughBoss_Payment_Attempts::bind_provider_reference( $attempt['id'], $order_id, 'created', 'SESSION_PENDING' );
			if ( ! $attempt ) {
				return new WP_Error( 'doughboss_pay_binding', __( 'The payment reference could not be bound safely.', 'doughboss' ), array( 'status' => 409 ) );
			}
		}

		$return_base = isset( $metadata['return_url'] ) ? esc_url_raw( (string) $metadata['return_url'] ) : home_url( '/' );
		$return_url = add_query_arg(
			array(
				'doughboss_mpgs_return' => '1',
				'doughboss_mpgs_order'  => $order_id,
			),
			$return_base
		);
		$result = self::request(
			'POST',
			'/session',
			array(
				'apiOperation' => 'INITIATE_CHECKOUT',
				'checkoutMode' => 'WEBSITE',
				'interaction'  => array(
					'operation'  => 'PURCHASE',
					'returnUrl'  => $return_url,
					'merchant'   => array(
						'name' => 'Dough Boss',
						'url'  => home_url( '/' ),
					),
				),
				'order'        => array(
					'id'          => $order_id,
					'amount'      => number_format( $amount_minor / 100, 2, '.', '' ),
					'currency'    => $currency,
					'description' => 'Dough Boss online order',
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			DoughBoss_Payment_Attempts::update( $attempt['id'], array( 'status' => 'unknown', 'last_error' => $result->get_error_code() ) );
			return $result;
		}
		$session_id = isset( $result['session']['id'] ) ? (string) $result['session']['id'] : '';
		if ( 'SUCCESS' !== strtoupper( isset( $result['result'] ) ? $result['result'] : '' ) || ! preg_match( '/^[\x20-\x7E]{20,80}$/', $session_id ) ) {
			return new WP_Error( 'doughboss_pay_create', __( 'Mastercard did not return a secure checkout session.', 'doughboss' ), array( 'status' => 502 ) );
		}
		DoughBoss_Payment_Attempts::update( $attempt['id'], array( 'status' => 'processing', 'provider_status' => 'SESSION_READY' ) );
		return array(
			'id'            => $order_id,
			'client_secret' => $session_id,
			'attempt_id'    => (int) $attempt['id'],
			'amount'        => $amount_minor,
			'currency'      => strtolower( $currency ),
		);
	}

	/** @return array|WP_Error */
	public static function retrieve_payment_intent( $id ) {
		$id      = self::canonical_id( $id );
		$attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $id );
		if ( '' === $id || ! $attempt || 'mpgs' !== $attempt['provider'] ) {
			return new WP_Error( 'doughboss_pay_attempt', __( 'The payment attempt could not be reconciled.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$result = self::request( 'GET', '/order/' . rawurlencode( $id ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$order          = isset( $result['order'] ) && is_array( $result['order'] ) ? $result['order'] : array();
		$provider_state = strtoupper( isset( $order['status'] ) ? $order['status'] : ( isset( $result['result'] ) ? $result['result'] : '' ) );
		$amount_minor   = isset( $order['amount'] ) ? self::to_minor_units( $order['amount'] ) : 0;
		$currency       = strtoupper( isset( $order['currency'] ) ? $order['currency'] : '' );
		$paid_states    = array( 'CAPTURED', 'PARTIALLY_CAPTURED', 'FUNDED', 'PURCHASED' );
		$status         = in_array( $provider_state, $paid_states, true ) ? 'succeeded' : ( 'FAILED' === $provider_state ? 'failed' : 'processing' );
		$metadata       = json_decode( (string) $attempt['safe_metadata_json'], true );
		$metadata       = is_array( $metadata ) ? $metadata : array();
		$metadata['checkout_key'] = (string) $attempt['checkout_key'];

		if ( isset( $order['id'] ) && (string) $order['id'] !== $id ) {
			return new WP_Error( 'doughboss_pay_mismatch', __( 'The payment did not match this order.', 'doughboss' ), array( 'status' => 409 ) );
		}
		DoughBoss_Payment_Attempts::update(
			$attempt['id'],
			array(
				'status'          => $status,
				'provider_status' => $provider_state,
				'verified_at'     => 'succeeded' === $status ? current_time( 'mysql', true ) : '',
			)
		);
		return array(
			'id'              => $id,
			'status'          => $status,
			'provider_status' => $provider_state,
			'amount'          => $amount_minor,
			'currency'        => strtolower( $currency ),
			'metadata'        => $metadata,
		);
	}

	/** @return string */
	public static function canonical_id( $id ) {
		$id = sanitize_text_field( (string) $id );
		return preg_match( '/^DB-[0-9]+-[a-f0-9]{12}$/', $id ) ? $id : '';
	}

	/** @return array|WP_Error */
	public static function create_refund( $id, $amount_minor = null ) {
		unset( $amount_minor );
		return new WP_Error( 'doughboss_mpgs_refund_manual', __( 'Mastercard refunds require an operator review in the gateway portal.', 'doughboss' ), array( 'status' => 501 ) );
	}

	/** Read-only credential/API check. @return array|WP_Error */
	public static function test_connection() {
		$result = self::request( 'GET', '/order/DOUGHBOSS-CONNECTION-CHECK-NOT-AN-ORDER' );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$http_status = is_array( $data ) && isset( $data['http_status'] ) ? (int) $data['http_status'] : 0;
			// An authenticated lookup for a deliberately impossible order may be
			// reported as 400 or 404 depending on the acquirer profile. Invalid
			// credentials remain 401/403 and must never be accepted here.
			if ( in_array( $http_status, array( 400, 404 ), true ) ) {
				return array( 'connected' => true, 'mode' => self::mode() );
			}
		}
		return is_wp_error( $result ) ? $result : array( 'connected' => true, 'mode' => self::mode() );
	}

	/** @return array|WP_Error */
	private static function request( $method, $path, array $body = array() ) {
		$merchant = DoughBoss_Settings::mpgs_merchant_id();
		$password = DoughBoss_Settings::mpgs_api_password();
		$host     = DoughBoss_Settings::mpgs_host();
		if ( '' === $merchant || '' === $password || '' === $host ) {
			return new WP_Error( 'doughboss_pay_config', __( 'Mastercard Payment Gateway is not configured.', 'doughboss' ), array( 'status' => 503 ) );
		}
		$url = $host . '/api/rest/version/' . DoughBoss_Settings::mpgs_api_version() . '/merchant/' . rawurlencode( $merchant ) . $path;
		$args = array(
			'method'      => $method,
			'timeout'     => 30,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Basic ' . base64_encode( 'merchant.' . $merchant . ':' . $password ),
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
		);
		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'doughboss_pay_network', __( 'Could not reach Mastercard Payment Gateway.', 'doughboss' ), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			return $data;
		}
		return new WP_Error(
			'doughboss_pay_api',
			__( 'Mastercard Payment Gateway returned an error.', 'doughboss' ),
			array( 'status' => 502, 'http_status' => $code )
		);
	}
}
