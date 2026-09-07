<?php
/**
 * ntfy.sh staff push notifications (optional, off by default).
 *
 * A near-zero-cost "new order" push to the kitchen's phones via ntfy.sh (or a
 * self-hosted ntfy server). When an order is created and ntfy is configured,
 * this posts a short kitchen alert to the configured topic so staff get an
 * instant heads-up without watching the Live Order Board.
 *
 * The transport is a dependency-free `wp_remote_post()` to ntfy's HTTP API:
 * the message is the request BODY and the metadata (title, priority, tags) ride
 * in headers. The request is short-timeout and non-blocking so a slow or down
 * ntfy server never delays or breaks the order checkout.
 *
 * FULLY DORMANT unless `DoughBoss_Settings::ntfy_ready()` is true (ntfy enabled
 * AND a topic set). When dormant the handler returns immediately and the plugin
 * behaves exactly as it does today. The alert is a kitchen heads-up only — it
 * deliberately carries NO customer phone/email/address, and the bearer token is
 * never logged (status code only).
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal ntfy.sh push client + order hook. Static; register via init().
 */
class DoughBoss_Ntfy {

	/**
	 * Register the order-created hook. Always safe to call — the handler
	 * self-gates on ntfy_ready() and does nothing when ntfy is off/unconfigured.
	 *
	 * `doughboss_order_created` fires with two args ( $order_id, $data ); we only
	 * need the id.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'doughboss_order_created', array( __CLASS__, 'on_order_created' ), 10, 2 );
	}

	/**
	 * On a new order, push a kitchen alert to ntfy. Self-gated and fail-safe:
	 * does nothing unless ntfy is configured, and never fatals the checkout.
	 *
	 * @param int   $order_id New order id.
	 * @param array $data     Order data (unused; kept for the hook contract).
	 * @return void
	 */
	public static function on_order_created( $order_id, $data = array() ) {
		unset( $data );

		// Guard: fully dormant unless ntfy is enabled AND a topic is set.
		if ( ! DoughBoss_Settings::ntfy_ready() ) {
			return;
		}

		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return;
		}

		$order = DoughBoss_Order::get( $order_id );
		if ( ! $order ) {
			return;
		}

		self::notify( $order );
	}

	/**
	 * POST a short kitchen alert to the configured ntfy topic.
	 *
	 * ntfy's API is `POST https://server/<topic>` with the message in the BODY
	 * and metadata in headers (Title, Priority, Tags). When a token is set we
	 * add a Bearer Authorization header. The body is a one-liner built only from
	 * sanitized/escaped order fields — order number, type, total and shop name —
	 * with NO customer PII (kitchen alert only). Non-blocking + short timeout;
	 * WP_Error is swallowed and only the HTTP status is ever logged.
	 *
	 * @param object $order Order row (from DoughBoss_Order::get()).
	 * @return void
	 */
	private static function notify( $order ) {
		$server = trailingslashit( DoughBoss_Settings::ntfy_server() );
		$topic  = rawurlencode( DoughBoss_Settings::ntfy_topic() );
		if ( '' === $topic ) {
			return;
		}
		$url = $server . $topic;

		$body = self::build_message( $order );

		$headers = array(
			'Content-Type' => 'text/plain; charset=utf-8',
			'Title'        => 'DoughBoss: new order',
			'Priority'     => sanitize_text_field( DoughBoss_Settings::ntfy_priority() ),
			'Tags'         => 'bell,pizza',
		);

		$token = DoughBoss_Settings::ntfy_token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_post(
			$url,
			array(
				'method'   => 'POST',
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => $headers,
				'body'     => $body,
			)
		);

		// Non-blocking requests return immediately with no usable status; only
		// log a transport-level WP_Error. Never log the token or any PII.
		if ( is_wp_error( $response ) ) {
			self::log( 'push failed (' . $response->get_error_code() . ')' );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code && ( $code < 200 || $code >= 300 ) ) {
			self::log( 'push returned HTTP ' . $code );
		}
	}

	/**
	 * Build the kitchen alert line, e.g.
	 * "New order #1234 — pickup — $42.00 (Revesby)". Only non-sensitive order
	 * fields are used; the shop name is resolved from the order's location_id.
	 *
	 * @param object $order Order row.
	 * @return string
	 */
	private static function build_message( $order ) {
		$number = isset( $order->order_number ) ? sanitize_text_field( (string) $order->order_number ) : '';
		$type   = isset( $order->order_type ) ? sanitize_text_field( (string) $order->order_type ) : '';
		$total  = DoughBoss_Settings::format_price( isset( $order->total ) ? (float) $order->total : 0 );

		$parts = array();

		$parts[] = '' !== $number
			/* translators: %s: order number. */
			? sprintf( __( 'New order #%s', 'doughboss' ), $number )
			: __( 'New order', 'doughboss' );

		if ( '' !== $type ) {
			$parts[] = $type;
		}

		$parts[] = $total;

		$line = implode( ' — ', $parts );

		$shop = self::location_name( isset( $order->location_id ) ? (int) $order->location_id : 0 );
		if ( '' !== $shop ) {
			$line .= ' (' . $shop . ')';
		}

		return $line;
	}

	/**
	 * Resolve a shop display name from its location id, or '' when unknown.
	 *
	 * @param int $location_id Location id.
	 * @return string
	 */
	private static function location_name( $location_id ) {
		$location_id = absint( $location_id );
		if ( ! $location_id || ! class_exists( 'DoughBoss_Locations' ) ) {
			return '';
		}
		$loc = DoughBoss_Locations::get( $location_id );
		if ( ! $loc || ! isset( $loc->name ) ) {
			return '';
		}
		return sanitize_text_field( (string) $loc->name );
	}

	/**
	 * Log a push status line for the operator. Status only — never the token, the
	 * topic auth, or any customer detail.
	 *
	 * @param string $message Short status string.
	 * @return void
	 */
	private static function log( $message ) {
		if ( function_exists( 'error_log' ) ) {
			error_log( 'DoughBoss ntfy: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
