<?php
/**
 * DoughBoss Growth GA4 delivery: the outbox handler for channel "ga4" (Google Analytics 4 Measurement Protocol).
 *
 * Called by the shared outbox for each claimed "ga4" row. It re-checks everything before a request is made: the
 * feature is on, the payload still passes the allow-list, the payload carries measurement consent, and the destination
 * is configured. The secret comes from the environment or wp-config only (DOUGHBOSS_GROWTH_GA4_API_SECRET), the
 * measurement id from settings. The request goes through DoughBoss_Growth_Http (https, public host, ten seconds, no
 * redirects, redacted logs); the secret is part of the Measurement Protocol URL and is never logged (the wrapper
 * logs the URL without its query).
 *
 * Money arrives as integer cents and is converted to a decimal AUD number here, once.
 *
 * Entry file: classes only, no side effects at include time.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GA4 Measurement Protocol handler.
 */
final class DoughBoss_Growth_Ga4 {

	/**
	 * Measurement Protocol host (a bare host, never a full URL: the endpoint is built in endpoint()).
	 */
	const HOST = 'www.google-analytics.com';

	/**
	 * Path of the collect endpoint.
	 */
	const PATH = '/mp/collect';

	/**
	 * Events older than this are sent without a timestamp (the platform rejects timestamps more than 72 hours old, so
	 * a margin of one hour is kept).
	 */
	const MAX_TIMESTAMP_AGE = 255600;

	/**
	 * The request URL for a measurement id and secret.
	 *
	 * @param string $measurement_id GA4 measurement id (G-...).
	 * @param string $secret         Measurement Protocol secret.
	 * @return string Empty string when the id is not well formed or the secret is empty.
	 */
	public static function endpoint( $measurement_id, $secret ) {
		if ( ! is_string( $measurement_id ) || 1 !== preg_match( '/\AG-[A-Z0-9]{4,20}\z/', $measurement_id ) || ! is_string( $secret ) || '' === $secret ) {
			return '';
		}
		return 'https://' . self::HOST . self::PATH . '?measurement_id=' . rawurlencode( $measurement_id ) . '&api_secret=' . rawurlencode( $secret );
	}

	/**
	 * A pseudonymous client id derived from the event id. It identifies nothing about the visitor: it is the same for
	 * the same event on every retry and different for every order or lead. (Linking a server event to the visitor's
	 * browser session needs the browser's own GA client id, which the attribution module does not capture.)
	 *
	 * @param string $event_id Event id.
	 * @return string Two numbers joined by a dot, the shape GA4 uses.
	 */
	public static function client_id( $event_id ) {
		return sprintf( '%u.%u', crc32( 'dbgr-a|' . $event_id ), crc32( 'dbgr-b|' . $event_id ) );
	}

	/**
	 * The Measurement Protocol body for a validated payload.
	 *
	 * @param array $payload A payload that passed DoughBoss_Growth_Conversions::validate_payload( $payload, 'ga4' ).
	 * @return array|null Null when the payload is not a GA4 event.
	 */
	public static function build_body( array $payload ) {
		$event = isset( $payload['event'] ) ? $payload['event'] : '';
		if ( 'purchase' === $event || 'refund' === $event ) {
			$params = array(
				'transaction_id' => $payload['transaction_id'],
				'value'          => DoughBoss_Growth_Conversions::cents_to_value( $payload['value_cents'] ),
				'currency'       => $payload['currency'],
			);
		} elseif ( 'generate_lead' === $event ) {
			$params = array(
				'form' => $payload['form'],
			);
		} else {
			return null;
		}
		$body = array(
			'client_id'            => self::client_id( $payload['event_id'] ),
			'non_personalized_ads' => ( 1 !== $payload['consent']['a'] ),
			'events'               => array(
				array(
					'name'   => $event,
					'params' => $params,
				),
			),
		);
		$age  = DoughBoss_Growth::now() - $payload['event_time'];
		if ( $age >= 0 && $age <= self::MAX_TIMESTAMP_AGE ) {
			$body['timestamp_micros'] = $payload['event_time'] * 1000000;
		}
		return $body;
	}

	/**
	 * Outbox handler. Returns true when delivered, or a WP_Error: retryable by default, terminal when retrying cannot
	 * help (a payload that fails the allow-list, missing consent, a rejected request).
	 *
	 * @param array $row Claimed outbox row with the decoded payload under "payload".
	 * @return true|WP_Error
	 */
	public static function deliver( array $row ) {
		if ( ! DoughBoss_Growth_Settings::enabled( DoughBoss_Growth_Conversions::FEATURE ) ) {
			return new WP_Error( 'feature_off' );
		}
		$payload = DoughBoss_Growth_Conversions::validate_payload( isset( $row['payload'] ) ? $row['payload'] : null, 'ga4' );
		if ( null === $payload ) {
			return new WP_Error( 'bad_payload', '', array( 'terminal' => true ) );
		}
		if ( 1 !== $payload['consent']['m'] ) {
			return new WP_Error( 'consent_missing', '', array( 'terminal' => true ) );
		}
		$measurement_id = DoughBoss_Growth_Settings::get( 'ga4_measurement_id', '' );
		$url            = self::endpoint( is_string( $measurement_id ) ? $measurement_id : '', DoughBoss_Growth_Settings::secret( 'DOUGHBOSS_GROWTH_GA4_API_SECRET' ) );
		if ( '' === $url ) {
			return new WP_Error( 'not_configured' );
		}
		$body = self::build_body( $payload );
		if ( null === $body ) {
			return new WP_Error( 'bad_payload', '', array( 'terminal' => true ) );
		}
		$json = DoughBoss_Growth_Conversions::encode_json( $body );
		if ( '' === $json ) {
			return new WP_Error( 'encode_failed', '', array( 'terminal' => true ) );
		}
		$result = DoughBoss_Growth_Http::request(
			'POST',
			$url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => $json,
			)
		);
		if ( $result['ok'] ) {
			return true;
		}
		$code = ( '' !== $result['error'] ) ? $result['error'] : 'http_failed';
		if ( $result['retryable'] ) {
			return new WP_Error( $code );
		}
		return new WP_Error( $code, '', array( 'terminal' => true ) );
	}
}
