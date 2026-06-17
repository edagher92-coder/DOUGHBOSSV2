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
	 * Create a PaymentIntent for the given amount.
	 *
	 * @param int    $amount_minor Amount in the smallest currency unit (cents).
	 * @param string $currency     ISO currency code (e.g. AUD).
	 * @param array  $metadata     Optional key/value metadata to attach.
	 * @return array|WP_Error { id, client_secret, amount, currency } or error.
	 */
	public static function create_payment_intent( $amount_minor, $currency, array $metadata = array() ) {
		$amount_minor = (int) $amount_minor;
		if ( $amount_minor < 1 ) {
			return new WP_Error( 'doughboss_pay_amount', __( 'Invalid payment amount.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$body = array(
			'amount'                             => $amount_minor,
			'currency'                           => strtolower( $currency ),
			'automatic_payment_methods[enabled]' => 'true',
		);
		foreach ( $metadata as $key => $value ) {
			$body[ 'metadata[' . $key . ']' ] = (string) $value;
		}

		$response = self::request( 'POST', '/payment_intents', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['id'] ) || empty( $response['client_secret'] ) ) {
			return new WP_Error( 'doughboss_pay_create', __( 'Could not start the card payment. Please try again.', 'doughboss' ), array( 'status' => 502 ) );
		}

		return array(
			'id'            => $response['id'],
			'client_secret' => $response['client_secret'],
			'amount'        => isset( $response['amount'] ) ? (int) $response['amount'] : $amount_minor,
			'currency'      => isset( $response['currency'] ) ? $response['currency'] : strtolower( $currency ),
		);
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
	 * Perform an authenticated request to the Stripe API.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   API path beginning with '/'.
	 * @param array  $body   Form-encoded body for write calls.
	 * @return array|WP_Error Decoded JSON, or an error.
	 */
	private static function request( $method, $path, array $body = array() ) {
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

		// Log the detail for the operator; return a clean message to the customer.
		if ( function_exists( 'error_log' ) ) {
			error_log( 'DoughBoss Stripe error (' . $code . '): ' . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return new WP_Error( 'doughboss_pay_api', $message, array( 'status' => 502 ) );
	}
}
