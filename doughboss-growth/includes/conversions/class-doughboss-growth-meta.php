<?php
/**
 * DoughBoss Growth Meta delivery: the outbox handler for channel "meta" (Meta Conversions API).
 *
 * Called by the shared outbox for each claimed "meta" row. It re-checks everything before a request is made: the
 * feature is on, the payload still passes the allow-list, the payload carries advertising consent, the event is not
 * too old for the platform, and the destination is configured. The access token comes from the environment or
 * wp-config only (DOUGHBOSS_GROWTH_META_CAPI_TOKEN), the pixel id from settings. The request goes through
 * DoughBoss_Growth_Http.
 *
 * What identifies the event to the platform, and only with advertising consent:
 *  - the click id (fbclid) captured by the attribution module, written in the platform's click-id format;
 *  - a hashed email and a hashed phone number, ONLY when the send_hashed_identifiers setting is on. Hashing happens in
 *    memory at queue time; the plain values are never stored, queued or logged.
 * With neither, nothing is queued for Meta (an event with nothing to match is not sent).
 *
 * Meta has no refund event: refunds are GA4 only.
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
 * Meta Conversions API handler.
 */
final class DoughBoss_Growth_Meta {

	/**
	 * Graph API host (a bare host, never a full URL: the endpoint is built in endpoint()).
	 */
	const HOST = 'graph.facebook.com';

	/**
	 * Graph API version used for the request path. [CONFIRM: check against the current Meta documentation before
	 * enabling; it was not verified against a live account.]
	 */
	const API_VERSION = 'v22.0';

	/**
	 * Events older than this are not sent (the platform accepts events up to seven days old; one half day of margin).
	 */
	const MAX_EVENT_AGE = 561600;

	/**
	 * Our event name => the platform's standard event name.
	 */
	const EVENT_NAMES = array(
		'purchase'      => 'Purchase',
		'generate_lead' => 'Lead',
	);

	/**
	 * The request URL for a pixel id.
	 *
	 * @param string $pixel_id Pixel id (digits).
	 * @return string Empty string when the id is not well formed.
	 */
	public static function endpoint( $pixel_id ) {
		if ( ! is_string( $pixel_id ) || 1 !== preg_match( '/\A[0-9]{8,20}\z/', $pixel_id ) ) {
			return '';
		}
		return 'https://' . self::HOST . '/' . self::API_VERSION . '/' . $pixel_id . '/events';
	}

	/**
	 * The identifiers an event may carry to Meta, given what the visitor consented to and what the settings allow.
	 *
	 * @param array $stored   Result of DoughBoss_Growth_Attribution::for_subject().
	 * @param array $contact  email and phone of the customer, used only to compute hashes in memory.
	 * @param array $settings Sanitised settings.
	 * @return array fbc, em, ph (each only when permitted and available); empty when nothing may be sent.
	 */
	public static function match_keys( array $stored, array $contact, array $settings ) {
		$consent = isset( $stored['consent'] ) && is_array( $stored['consent'] ) ? $stored['consent'] : array();
		if ( true !== ( isset( $consent['advertising'] ) ? $consent['advertising'] : false ) ) {
			return array();
		}
		$keys        = array();
		$attribution = isset( $stored['attribution'] ) && is_array( $stored['attribution'] ) ? $stored['attribution'] : array();
		$fbclid      = ( isset( $attribution['fbclid'] ) && is_string( $attribution['fbclid'] ) ) ? $attribution['fbclid'] : '';
		if ( 1 === preg_match( '/\A[A-Za-z0-9_\-]{10,120}\z/', $fbclid ) ) {
			$seconds = self::click_time( $attribution, isset( $stored['captured_at'] ) ? $stored['captured_at'] : '' );
			if ( null !== $seconds ) {
				$keys['fbc'] = 'fb.1.' . ( $seconds * 1000 ) . '.' . $fbclid;
			}
		}
		if ( isset( $settings['send_hashed_identifiers'] ) && 1 === (int) $settings['send_hashed_identifiers'] ) {
			$em = self::hash_email( isset( $contact['email'] ) ? $contact['email'] : '' );
			if ( '' !== $em ) {
				$keys['em'] = $em;
			}
			$ph = self::hash_phone( isset( $contact['phone'] ) ? $contact['phone'] : '' );
			if ( '' !== $ph ) {
				$keys['ph'] = $ph;
			}
		}
		return $keys;
	}

	/**
	 * When the visitor first arrived, in seconds: the first-seen time from attribution (only present with measurement
	 * consent), else the time the record was captured (UTC).
	 *
	 * @param array  $attribution Sanitised attribution.
	 * @param string $captured_at UTC "Y-m-d H:i:s".
	 * @return int|null
	 */
	private static function click_time( array $attribution, $captured_at ) {
		$candidates = array();
		if ( isset( $attribution['firstSeenAt'] ) && is_string( $attribution['firstSeenAt'] ) ) {
			$candidates[] = $attribution['firstSeenAt'];
		}
		if ( is_string( $captured_at ) && '' !== $captured_at ) {
			$candidates[] = $captured_at . ' UTC';
		}
		foreach ( $candidates as $text ) {
			try {
				$time    = new DateTimeImmutable( $text );
				$seconds = (int) $time->format( 'U' );
			} catch ( Exception $e ) {
				continue;
			}
			if ( $seconds >= 946684800 && $seconds <= 4102444800 ) {
				return $seconds;
			}
		}
		return null;
	}

	/**
	 * SHA-256 of a trimmed, lower-cased email address; '' when it is not an email address.
	 *
	 * @param mixed $email Email.
	 * @return string
	 */
	public static function hash_email( $email ) {
		if ( ! is_string( $email ) ) {
			return '';
		}
		$email = strtolower( trim( $email ) );
		if ( '' === $email || strlen( $email ) > 191 || ! is_email( $email ) ) {
			return '';
		}
		return hash( 'sha256', $email );
	}

	/**
	 * SHA-256 of an Australian phone number in international form without the plus (61 and nine digits); '' for any
	 * number that cannot be read as an Australian one (a number is never guessed).
	 *
	 * @param mixed $phone Phone.
	 * @return string
	 */
	public static function hash_phone( $phone ) {
		if ( ! is_string( $phone ) || strlen( $phone ) > 40 ) {
			return '';
		}
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( ! is_string( $digits ) ) {
			return '';
		}
		if ( 1 === preg_match( '/\A0([2-478][0-9]{8})\z/', $digits, $m ) ) {
			$digits = '61' . $m[1];
		} elseif ( 1 !== preg_match( '/\A61[2-478][0-9]{8}\z/', $digits ) ) {
			return '';
		}
		return hash( 'sha256', $digits );
	}

	/**
	 * The Conversions API body for a validated payload.
	 *
	 * @param array  $payload A payload that passed DoughBoss_Growth_Conversions::validate_payload( $payload, 'meta' ).
	 * @param string $token   Access token (from the environment; never stored).
	 * @return array|null Null when the payload is not a Meta event.
	 */
	public static function build_body( array $payload, $token ) {
		$event = isset( $payload['event'] ) ? $payload['event'] : '';
		if ( ! isset( self::EVENT_NAMES[ $event ] ) ) {
			return null;
		}
		$user_data = array();
		if ( isset( $payload['fbc'] ) ) {
			$user_data['fbc'] = $payload['fbc'];
		}
		if ( isset( $payload['em'] ) ) {
			$user_data['em'] = array( $payload['em'] );
		}
		if ( isset( $payload['ph'] ) ) {
			$user_data['ph'] = array( $payload['ph'] );
		}
		if ( array() === $user_data ) {
			return null;
		}
		$item = array(
			'event_name'       => self::EVENT_NAMES[ $event ],
			'event_time'       => $payload['event_time'],
			'event_id'         => $payload['event_id'],
			'action_source'    => 'website',
			'event_source_url' => home_url( '/' ),
			'user_data'        => $user_data,
		);
		if ( 'purchase' === $event ) {
			$item['custom_data'] = array(
				'currency' => $payload['currency'],
				'value'    => DoughBoss_Growth_Conversions::cents_to_value( $payload['value_cents'] ),
				'order_id' => $payload['transaction_id'],
			);
		}
		return array(
			'data'         => array( $item ),
			'access_token' => $token,
		);
	}

	/**
	 * Outbox handler. Returns true when delivered, or a WP_Error: retryable by default, terminal when retrying cannot
	 * help (a payload that fails the allow-list, missing consent, an event too old, a rejected request).
	 *
	 * @param array $row Claimed outbox row with the decoded payload under "payload".
	 * @return true|WP_Error
	 */
	public static function deliver( array $row ) {
		if ( ! DoughBoss_Growth_Settings::enabled( DoughBoss_Growth_Conversions::FEATURE ) ) {
			return new WP_Error( 'feature_off' );
		}
		$payload = DoughBoss_Growth_Conversions::validate_payload( isset( $row['payload'] ) ? $row['payload'] : null, 'meta' );
		if ( null === $payload ) {
			return new WP_Error( 'bad_payload', '', array( 'terminal' => true ) );
		}
		if ( 1 !== $payload['consent']['a'] ) {
			return new WP_Error( 'consent_missing', '', array( 'terminal' => true ) );
		}
		$age = DoughBoss_Growth::now() - $payload['event_time'];
		if ( $age > self::MAX_EVENT_AGE ) {
			return new WP_Error( 'event_too_old', '', array( 'terminal' => true ) );
		}
		$pixel_id = DoughBoss_Growth_Settings::get( 'meta_pixel_id', '' );
		$token    = DoughBoss_Growth_Settings::secret( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN' );
		$url      = self::endpoint( is_string( $pixel_id ) ? $pixel_id : '' );
		if ( '' === $url || '' === $token ) {
			return new WP_Error( 'not_configured' );
		}
		$body = self::build_body( $payload, $token );
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
