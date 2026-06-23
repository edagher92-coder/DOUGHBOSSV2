<?php
/**
 * POSPal (银豹) Open Platform client (optional, off by default).
 *
 * A thin, dependency-free wrapper over the POSPal Open Platform REST API, used
 * to connect the shops' POSPal POS for orders, catering and discount-coupon
 * vouchers. No SDK is bundled — calls go through `wp_remote_*`. When POSPal is
 * disabled or unconfigured the whole feature is dormant and the plugin behaves
 * exactly as before.
 *
 * Auth: every request carries a `time-stamp` (milliseconds) header and a
 * `data-signature` header, where the signature is
 * `strtoupper( md5( appKey . rawJsonBody ) )`. The body itself carries the
 * public `appId`; the secret `appKey` is only ever used to sign and never
 * leaves the server (not in the body, the URL or any log).
 *
 * Foundation milestone (M1): signing + transport + a read-only test call.
 * Voucher/order/catering endpoints are layered on top in later milestones.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal POSPal Open Platform client.
 */
class DoughBoss_POSPal {

	const API_PREFIX = '/pospal-api2/openapi/v1/';

	/**
	 * Whether POSPal is switched on AND fully configured. The single gate the
	 * rest of the plugin checks before making any call.
	 *
	 * @return bool
	 */
	public static function ready() {
		return DoughBoss_Settings::pospal_ready();
	}

	/**
	 * Configured area host (e.g. https://area28-win.pospal.cn:443), no trailing slash.
	 *
	 * @return string
	 */
	public static function host() {
		return DoughBoss_Settings::pospal_host();
	}

	/**
	 * Public application id (sent in the request body).
	 *
	 * @return string
	 */
	private static function app_id() {
		return DoughBoss_Settings::pospal_app_id();
	}

	/**
	 * Secret application key (used only to sign — never sent in the body).
	 *
	 * @return string
	 */
	private static function app_key() {
		return DoughBoss_Settings::pospal_app_key();
	}

	/**
	 * Current time in milliseconds, as POSPal expects for the time-stamp header.
	 *
	 * @return string
	 */
	private static function timestamp_ms() {
		return (string) (int) round( microtime( true ) * 1000 );
	}

	/**
	 * Build POSPal's request signature: strtoupper( md5( appKey . rawBody ) ).
	 *
	 * @param string $raw_body The exact JSON string that will be sent as the body.
	 * @return string
	 */
	private static function sign( $raw_body ) {
		return strtoupper( md5( self::app_key() . $raw_body ) );
	}

	/**
	 * Read-only connectivity check: query the account's coupon promotion rules.
	 * Safe (no side effects); used by the admin "test connection" action.
	 *
	 * @return array|WP_Error
	 */
	public static function test_connection() {
		return self::query_coupon_promotions();
	}

	/**
	 * List the account's available coupon promotion rules (read-only).
	 *
	 * @return array|WP_Error
	 */
	public static function query_coupon_promotions() {
		return self::call( 'promotionOpenApi', 'queryCouponPromotions' );
	}

	/**
	 * Perform a signed POSPal Open Platform call.
	 *
	 * The JSON body is built once and the same exact bytes are both signed and
	 * sent — re-encoding the body would break the signature.
	 *
	 * @param string $module  API module, e.g. 'promotionOpenApi', 'customerOpenApi'.
	 * @param string $method  API method, e.g. 'queryCouponPromotions'.
	 * @param array  $payload Request fields ('appId' is added automatically).
	 * @return array|WP_Error Decoded `data` payload on success, or an error.
	 */
	public static function call( $module, $method, array $payload = array() ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'doughboss_pospal_config', __( 'POSPal is not configured.', 'doughboss' ), array( 'status' => 503 ) );
		}

		$module = sanitize_key( $module );
		$method = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $method );
		if ( '' === $module || '' === $method ) {
			return new WP_Error( 'doughboss_pospal_request', __( 'Invalid POSPal request.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$payload['appId'] = self::app_id();
		$raw_body         = wp_json_encode( $payload );
		if ( false === $raw_body ) {
			return new WP_Error( 'doughboss_pospal_encode', __( 'Could not encode the POSPal request.', 'doughboss' ), array( 'status' => 500 ) );
		}

		$url = self::host() . self::API_PREFIX . $module . '/' . $method;

		$response = wp_remote_post(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => 25,
				// Let WP manage Accept-Encoding so it auto-decompresses the reply;
				// a gzip fallback below covers servers that gzip regardless.
				'headers' => array(
					'User-Agent'     => 'openApi',
					'Content-Type'   => 'application/json; charset=utf-8',
					'time-stamp'     => self::timestamp_ms(),
					'data-signature' => self::sign( $raw_body ),
				),
				'body'    => $raw_body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'doughboss_pospal_network', __( 'Could not reach POSPal. Please try again.', 'doughboss' ), array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		// Fallback: decode gzip if the host returned it without WP inflating it.
		if ( '' !== $raw && 0 === strpos( $raw, "\x1f\x8b" ) && function_exists( 'gzdecode' ) ) {
			$decoded = gzdecode( $raw );
			if ( false !== $decoded ) {
				$raw = $decoded;
			}
		}
		$data = json_decode( $raw, true );

		if ( $code >= 200 && $code < 300 && is_array( $data ) && isset( $data['status'] ) && 'success' === $data['status'] ) {
			return isset( $data['data'] ) ? $data['data'] : array();
		}

		$message = ( is_array( $data ) && ! empty( $data['messages'] ) && is_array( $data['messages'] ) )
			? implode( ' ', array_map( 'strval', $data['messages'] ) )
			: __( 'POSPal returned an error.', 'doughboss' );

		// Log only the status + endpoint for the operator — never the response body
		// (it can carry member PII) and never the appKey or the signed headers.
		if ( function_exists( 'error_log' ) ) {
			error_log( 'DoughBoss POSPal error: HTTP ' . $code . ' on ' . $module . '/' . $method ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return new WP_Error( 'doughboss_pospal_api', $message, array( 'status' => 502 ) );
	}
}
