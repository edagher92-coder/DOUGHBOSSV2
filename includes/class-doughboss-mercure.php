<?php
/**
 * Mercure SSE real-time transport (optional, off by default).
 *
 * Replaces the Live Order Board's ~7s poll with instant Server-Sent Events: when
 * an order is created or its status changes, this publishes a tiny "something
 * changed, refresh" notification to a Mercure hub. Subscribers (the in-page board
 * and the standalone Console) receive it over an EventSource and re-fetch the
 * authoritative board over the existing authenticated REST route. Polling is kept
 * as a graceful fallback, so when Mercure is unconfigured the board behaves
 * exactly as it does today.
 *
 * SECURITY — the published payload is a NON-PII change signal only. It carries
 * just { type, action, id, order_number, status } so it can ride a public topic
 * without leaking customer details. It is NOT the source of truth: every
 * subscriber re-pulls the real order data over the authenticated admin REST
 * endpoint, which enforces the capability check. The publish JWT is sent only in
 * the server-to-hub Authorization header and is never logged or echoed to a
 * client; only the harmless subscribe JWT is ever exposed to the browser.
 *
 * FULLY DORMANT unless `DoughBoss_Settings::mercure_ready()` is true (Mercure
 * enabled AND a hub URL AND a publish JWT are set). When dormant, init() returns
 * before registering any hook and nothing is ever published.
 *
 * Dependency-free: publishes via `wp_remote_post()` — no SDK, no Composer.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes order-change notifications to a Mercure hub. Static; register via init().
 */
class DoughBoss_Mercure {

	/**
	 * Register the order-lifecycle hooks that publish change notifications.
	 *
	 * Self-gating: when Mercure is not configured + enabled this returns
	 * immediately and adds no hooks, leaving the board on its existing poll.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! DoughBoss_Settings::mercure_ready() ) {
			return;
		}

		// Verified hook names against includes/class-doughboss-order.php:
		//  - doughboss_order_created( $order_id, $data )
		//  - doughboss_order_status_changed( $order_id, $status )
		add_action( 'doughboss_order_created', array( __CLASS__, 'on_order_created' ), 10, 2 );
		add_action( 'doughboss_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 10, 2 );
	}

	/**
	 * Publish a "created" notification for a new order.
	 *
	 * @param int   $order_id The new order ID.
	 * @param array $data     The order data (unused; the row is re-read for safety).
	 * @return void
	 */
	public static function on_order_created( $order_id, $data = array() ) {
		unset( $data );
		self::publish( 'created', absint( $order_id ) );
	}

	/**
	 * Publish an "updated" notification when an order's status changes.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status   New status string (unused; the row is re-read).
	 * @return void
	 */
	public static function on_order_status_changed( $order_id, $status = '' ) {
		unset( $status );
		self::publish( 'updated', absint( $order_id ) );
	}

	/**
	 * The single public topic the board subscribes to. A board-wide topic is fine
	 * because the payload carries no PII and the real data is fetched over the
	 * authenticated REST route.
	 *
	 * @return string
	 */
	public static function topic() {
		return DoughBoss_Settings::mercure_topic_prefix() . '/orders';
	}

	/**
	 * Publish a minimal, non-PII change notification to the Mercure hub.
	 *
	 * The body is a bare "refresh" signal: { type:'order', action, id,
	 * order_number, status }. No customer name, phone, address, notes or totals are
	 * ever included — subscribers re-fetch the authoritative board over the
	 * existing authenticated admin REST endpoint. The publish JWT goes only in the
	 * Authorization header and is never logged.
	 *
	 * Best-effort + non-blocking: any transport failure is swallowed (status logged
	 * only, never the JWT) so a hub hiccup can never block or fail an order.
	 *
	 * @param string   $action   Change kind: 'created' or 'updated'.
	 * @param int      $order_id Order ID to look up for the notification.
	 * @return void
	 */
	public static function publish( $action, $order_id ) {
		// Re-check the gate: publish() can be called directly, not just via hooks.
		if ( ! DoughBoss_Settings::mercure_ready() ) {
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

		$action = ( 'created' === $action ) ? 'created' : 'updated';

		// MINIMAL, NON-PII payload — a "refresh" signal only.
		$data = wp_json_encode(
			array(
				'type'         => 'order',
				'action'       => $action,
				'id'           => (int) $order->id,
				'order_number' => isset( $order->order_number ) ? (string) $order->order_number : '',
				'status'       => isset( $order->status ) ? (string) $order->status : '',
			)
		);
		if ( false === $data ) {
			return;
		}

		$response = wp_remote_post(
			DoughBoss_Settings::mercure_hub_url(),
			array(
				'timeout'  => 5,
				// Fire-and-forget: the order flow must not wait on the hub.
				'blocking' => false,
				'headers'  => array(
					'Authorization' => 'Bearer ' . DoughBoss_Settings::mercure_publish_jwt(),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'     => array(
					'topic' => self::topic(),
					'data'  => $data,
				),
			)
		);

		// With 'blocking' => false there is usually no body to inspect; only a
		// transport-level WP_Error surfaces. Log the status only — NEVER the JWT,
		// the hub URL credentials or any payload.
		if ( is_wp_error( $response ) && function_exists( 'error_log' ) ) {
			error_log( 'DoughBoss Mercure publish failed (' . $response->get_error_code() . ') for order #' . $order_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Client-side config the orchestrator localizes for the board / Console so they
	 * can open an EventSource. Exposes only the public hub URL, topic and the
	 * harmless subscribe JWT — never the publish JWT.
	 *
	 * @return array { enabled, url, topic, subscribe_jwt }.
	 */
	public static function js_config() {
		return array(
			'enabled'       => DoughBoss_Settings::mercure_ready(),
			'url'           => DoughBoss_Settings::mercure_hub_url(),
			'topic'         => self::topic(),
			'subscribe_jwt' => DoughBoss_Settings::mercure_subscribe_jwt(),
		);
	}

	/**
	 * Diagnostic: a BLOCKING test publish to the hub that surfaces the HTTP
	 * status, used by the owner "Test connection" action. Unlike publish() (which
	 * is fire-and-forget so it can never block an order), this waits for the hub's
	 * response, so a rejected publish JWT or an unreachable hub becomes a clear,
	 * actionable error instead of failing silently. The JWT is sent only in the
	 * Authorization header and is never returned or logged.
	 *
	 * @return array|WP_Error array{ status:int } on a 2xx publish, or a WP_Error
	 *                        describing the transport failure / rejected status.
	 */
	public static function test() {
		$url = DoughBoss_Settings::mercure_hub_url();
		$jwt = DoughBoss_Settings::mercure_publish_jwt();
		if ( '' === $url || '' === $jwt ) {
			return new WP_Error(
				'doughboss_mercure_unconfigured',
				__( 'Set the Mercure hub URL and publish JWT first.', 'doughboss' )
			);
		}

		$payload = wp_json_encode(
			array(
				'type'   => 'test',
				'action' => 'ping',
			)
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout'  => 8,
				// Diagnostic: we DO want to wait for the status here.
				'blocking' => true,
				'headers'  => array(
					'Authorization' => 'Bearer ' . $jwt,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'     => array(
					'topic' => self::topic(),
					'data'  => ( false === $payload ) ? '{}' : $payload,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Don't forward the raw transport message to the browser: a cURL error
			// string can echo the host (and any credentials embedded in the URL).
			// Return a generic, URL-free message; log only the error CODE server-side
			// as a breadcrumb, mirroring publish()'s "status/code only" pattern.
			if ( function_exists( 'error_log' ) ) {
				error_log( 'DoughBoss Mercure test: transport failure (' . $response->get_error_code() . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return new WP_Error(
				'doughboss_mercure_unreachable',
				__( 'Could not reach the Mercure hub. Check the hub URL is correct and the hub is running and reachable from this server.', 'doughboss' )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			// Mercure returns 401 for a bad/expired publish JWT, 403 for a topic the
			// token may not publish, etc. Surface the status so a silent misconfig
			// becomes visible. Never echo the JWT or any credential.
			return new WP_Error(
				'doughboss_mercure_http',
				sprintf(
					/* translators: %d: HTTP status code returned by the Mercure hub. */
					__( 'The hub rejected the publish (HTTP %d). Check the hub URL, and that the publish JWT is valid and allowed to publish this topic.', 'doughboss' ),
					$code
				)
			);
		}

		return array( 'status' => $code );
	}
}
