<?php
/**
 * ClickSend transactional SMS to customers (optional, off by default).
 *
 * A thin, dependency-free wrapper over the ClickSend v3 REST API used to text
 * customers two transactional messages: an "your order is ready for pickup"
 * note when an order moves to the `ready` status, and an optional "voucher
 * claimed" note carrying the claimed code. No SDK is bundled — calls go through
 * `wp_remote_post`. When SMS is disabled or unconfigured the whole feature is
 * dormant: every handler returns immediately and the plugin behaves exactly as
 * it does today.
 *
 * Auth is HTTP Basic with the ClickSend username + API key
 * (`Authorization: Basic base64(username:apiKey)`). The API key is read
 * env-first via DoughBoss_Settings and is only ever used to build the header —
 * it never appears in the body, the URL or any log. Privacy: this client never
 * logs message bodies or customer phone numbers; status lines reference only the
 * order id (or a generic label for the voucher leg).
 *
 * Built for AU / AUD shops: numbers are best-effort normalised to E.164 with an
 * Australian (+61) default before sending; anything that can't be normalised is
 * skipped rather than sent as garbage.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal ClickSend SMS client + order/voucher hook wiring. Static; register
 * via init().
 */
class DoughBoss_SMS {

	const API_SEND = 'https://rest.clicksend.com/v3/sms/send';

	/**
	 * Register the SMS hooks. Always safe to call — every handler self-gates on
	 * DoughBoss_Settings::sms_ready() (and its per-message toggle) and does
	 * nothing when SMS is off or unconfigured.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'doughboss_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 2 );
		add_action( 'doughboss_voucher_claimed', array( __CLASS__, 'on_voucher_claimed' ), 10, 4 );
	}

	/**
	 * Whether SMS is switched on AND fully configured. The single gate every
	 * handler checks before doing any work.
	 *
	 * @return bool
	 */
	public static function ready() {
		return DoughBoss_Settings::sms_ready();
	}

	/**
	 * On an order status change, text the customer when the order is ready for
	 * pickup. Dormant unless SMS is configured AND the "on ready" toggle is on.
	 *
	 * The status-changed hook passes only the order id + the new status, so the
	 * row (and the customer phone) is loaded fresh from the orders table.
	 *
	 * @param int    $order_id New/changed order id.
	 * @param string $status   The order's new status.
	 * @return void
	 */
	public static function on_status_changed( $order_id, $status ) {
		if ( ! self::ready() ) {
			return;
		}
		if ( ! DoughBoss_Settings::sms_on_ready() ) {
			return;
		}
		if ( 'ready' !== (string) $status ) {
			return;
		}

		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return;
		}

		$order = DoughBoss_Order::get( $order_id );
		if ( ! is_object( $order ) ) {
			return;
		}

		$phone = self::normalize_phone( isset( $order->customer_phone ) ? $order->customer_phone : '' );
		if ( '' === $phone ) {
			// No usable number — nothing to text; the order is unaffected.
			return;
		}

		$number  = isset( $order->order_number ) ? (string) $order->order_number : (string) $order_id;
		$message = DoughBoss_Settings::render_template(
			DoughBoss_Settings::tpl_sms_ready(),
			array( 'order_number' => $number )
		);

		$result = self::send( $phone, $message );
		if ( is_wp_error( $result ) ) {
			self::log( 'ready: send failed (' . $result->get_error_code() . ') for order #' . $order_id );
		}
	}

	/**
	 * On a successful voucher claim, optionally text the claimed code to the
	 * voucher's customer phone. Dormant unless SMS is configured AND the
	 * "on voucher claim" toggle is on (it defaults off).
	 *
	 * The claim hook passes the new code directly, but the customer phone lives
	 * on the voucher row, so the row is loaded by id (the same way
	 * DoughBoss_POSPal_Sync does).
	 *
	 * @param int    $voucher_id New voucher id.
	 * @param string $code       New voucher code.
	 * @param string $slug       Campaign slug (unused).
	 * @param array  $args       Extra issue args passed to claim() (unused).
	 * @return void
	 */
	public static function on_voucher_claimed( $voucher_id, $code = '', $slug = '', $args = array() ) {
		unset( $slug, $args );

		if ( ! self::ready() ) {
			return;
		}
		if ( ! DoughBoss_Settings::sms_on_voucher_claim() ) {
			return;
		}

		$voucher_id = absint( $voucher_id );
		if ( ! $voucher_id ) {
			return;
		}

		$row = self::get_voucher( $voucher_id );
		if ( ! is_object( $row ) ) {
			return;
		}

		$phone = self::normalize_phone( isset( $row->customer_phone ) ? $row->customer_phone : '' );
		if ( '' === $phone ) {
			// No usable number on the voucher — skip; the voucher itself is unaffected.
			return;
		}

		// Prefer the code passed by the hook; fall back to the stored row code.
		$code = sanitize_text_field( (string) $code );
		if ( '' === $code && isset( $row->code ) ) {
			$code = sanitize_text_field( (string) $row->code );
		}
		if ( '' === $code ) {
			return;
		}

		$message = DoughBoss_Settings::render_template(
			DoughBoss_Settings::tpl_sms_voucher(),
			array( 'code' => $code )
		);

		$result = self::send( $phone, $message );
		if ( is_wp_error( $result ) ) {
			self::log( 'voucher: send failed (' . $result->get_error_code() . ') for voucher #' . $voucher_id );
		}
	}

	/**
	 * Send one SMS via the ClickSend v3 REST API.
	 *
	 * Dependency-free: builds an HTTP Basic auth header from the configured
	 * username + API key and POSTs a single-message batch as JSON. The body shape
	 * follows ClickSend's documented `/v3/sms/send` request:
	 * `{ "messages": [ { "source": "php", "from": <from>, "to": <E.164>,
	 * "body": <text> } ] }`. The optional `from` (sender id) is omitted when not
	 * configured so ClickSend uses the account default.
	 *
	 * Errors are swallowed and logged as a status line only (HTTP code) — never
	 * the API key, the destination number or the message body — and returned as a
	 * WP_Error so callers can record a status line of their own.
	 *
	 * @param string $to      Destination number in E.164 (already normalised).
	 * @param string $message Message text.
	 * @return true|WP_Error True on a successful send, WP_Error otherwise.
	 */
	public static function send( $to, $message ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'doughboss_sms_config', __( 'SMS is not configured.', 'doughboss' ), array( 'status' => 503 ) );
		}

		$to      = (string) $to;
		$message = (string) $message;
		if ( '' === $to || '' === $message ) {
			return new WP_Error( 'doughboss_sms_request', __( 'A destination number and a message are required.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$msg = array(
			'source' => 'php',
			'to'     => $to,
			'body'   => $message,
		);
		$from = DoughBoss_Settings::sms_from();
		if ( '' !== (string) $from ) {
			$msg['from'] = (string) $from;
		}

		$raw_body = wp_json_encode( array( 'messages' => array( $msg ) ) );
		if ( false === $raw_body ) {
			return new WP_Error( 'doughboss_sms_encode', __( 'Could not encode the SMS request.', 'doughboss' ), array( 'status' => 500 ) );
		}

		$auth = base64_encode( DoughBoss_Settings::clicksend_username() . ':' . DoughBoss_Settings::clicksend_api_key() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$response = wp_remote_post(
			self::API_SEND,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . $auth,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => $raw_body,
			)
		);

		if ( is_wp_error( $response ) ) {
			// Network-level failure; do not echo the underlying message (could leak the URL/headers).
			self::log( 'send: network error' );
			return new WP_Error( 'doughboss_sms_network', __( 'Could not reach the SMS service.', 'doughboss' ), array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		// Log the HTTP status only — never the API key, destination number or body.
		self::log( 'send: HTTP ' . $code );
		return new WP_Error( 'doughboss_sms_api', __( 'The SMS service returned an error.', 'doughboss' ), array( 'status' => 502 ) );
	}

	/**
	 * Best-effort Australian normalisation of a raw phone number to E.164.
	 *
	 * Rules (applied in order):
	 *   1. Strip everything except digits and a single leading '+'.
	 *   2. A leading '+' is kept as-is (already E.164) — e.g. '+61412345678'.
	 *   3. A leading '0' (national trunk prefix) becomes '+61' — e.g.
	 *      '0412 345 678' -> '+61412345678'.
	 *   4. A leading '61' (country code without '+') becomes '+61' — e.g.
	 *      '61412345678' -> '+61412345678'.
	 *   5. Anything else is treated as a bare national number and prefixed with
	 *      '+61'.
	 *
	 * Obviously invalid input (no digits, or fewer than 8 / more than 15 digits
	 * after normalisation, per E.164's max length) returns '' so the caller skips
	 * the send rather than texting garbage.
	 *
	 * @param string $raw Raw, possibly formatted phone number.
	 * @return string Normalised E.164 number (e.g. '+61412345678'), or '' if unusable.
	 */
	public static function normalize_phone( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		// Note whether the caller already gave an international '+' prefix, then
		// reduce to digits only for the country-code logic below.
		$has_plus = ( '+' === substr( $raw, 0, 1 ) );
		$digits   = preg_replace( '/\D+/', '', $raw );
		if ( '' === $digits ) {
			return '';
		}

		if ( $has_plus ) {
			$e164 = '+' . $digits;
		} elseif ( '0' === substr( $digits, 0, 1 ) ) {
			// National format: drop the trunk '0' and apply the AU country code.
			$e164 = '+61' . ltrim( substr( $digits, 1 ), '0' );
		} elseif ( '61' === substr( $digits, 0, 2 ) ) {
			// Country code present without the '+'.
			$e164 = '+' . $digits;
		} else {
			// Bare national number — assume Australian.
			$e164 = '+61' . $digits;
		}

		// Validate length against E.164 (max 15 digits; require a sane minimum).
		$count = strlen( preg_replace( '/\D+/', '', $e164 ) );
		if ( $count < 8 || $count > 15 ) {
			return '';
		}

		return $e164;
	}

	/**
	 * Fetch a voucher row by id (the claim hook passes only the id).
	 *
	 * @param int $voucher_id Voucher id.
	 * @return object|null
	 */
	private static function get_voucher( $voucher_id ) {
		global $wpdb;
		$table = DoughBoss_Voucher::table();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $voucher_id ) )
		);
	}

	/**
	 * Log an SMS status line for the operator. Status + an order/voucher id (or a
	 * generic label) only — never the destination number, the message body, the
	 * username or the API key.
	 *
	 * @param string $message Short status string.
	 * @return void
	 */
	private static function log( $message ) {
		if ( function_exists( 'error_log' ) ) {
			error_log( 'DoughBoss SMS: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
