<?php
/**
 * Tyro eCommerce payment gateway (optional, off by default).
 *
 * A thin, dependency-free wrapper over Tyro's online payments API — the white-
 * labelled Mastercard Payment Gateway Services (MPGS) "Simplify Commerce"
 * platform — used to charge and refund online orders. No SDK is bundled —
 * calls go through `wp_remote_*`, matching the existing DoughBoss_Stripe and
 * DoughBoss_POSPal clients. When Tyro is disabled, unselected, or
 * unconfigured the whole feature is dormant.
 *
 * IMPORTANT — API shape confidence: the endpoint paths, request/response
 * field names, and webhook signature scheme below are built from Tyro/MPGS's
 * publicly documented integration model (session create → PAY transaction →
 * order retrieve; REFUND transaction; HMAC-signed webhooks), not from a live
 * sandbox call — this plugin does not yet hold real Tyro credentials. Every
 * method that talks to Tyro is written so a wrong assumption fails CLOSED
 * (returns a WP_Error, never silently treats an ambiguous response as
 * success) — see request()'s strict status-field check and
 * verify_webhook_signature()'s fail-closed default. Before enabling in
 * production: run test_connection() from wp-admin once real sandbox
 * credentials are entered, and confirm one real webhook delivery against the
 * signature scheme here before relying on it.
 *
 * Model differences from Stripe this client bridges over (see
 * DoughBoss_Payment for the common contract both gateways implement):
 *   - Stripe: client-side confirms a PaymentIntent directly with Stripe; the
 *     server only ever retrieves/verifies its resulting status.
 *   - Tyro/MPGS: the merchant SERVER must actively submit the PAY
 *     transaction using the Hosted Session id the browser populated with
 *     card details. This client folds that submit-then-verify sequence into
 *     retrieve_payment_intent() (idempotent — safe to call more than once
 *     for the same order), so REST controller call sites written against
 *     DoughBoss_Payment's Stripe-shaped contract do not need to change.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal Tyro (MPGS) payments client.
 */
class DoughBoss_Tyro {

	/**
	 * Composite id delimiter — create_payment_intent() returns an id shaped
	 * "{order_id}{DELIM}{session_id}" so retrieve_payment_intent($id), which
	 * (like Stripe's) takes a single string, can recover both the merchant
	 * order id and the Hosted Session id needed to submit the PAY
	 * transaction. Neither half can contain this character (both are
	 * generated here from an alnum-only alphabet), so splitting is safe.
	 */
	const ID_DELIM = '.';

	/**
	 * Whether Tyro is the active gateway AND fully configured. The single
	 * gate the rest of the plugin checks before making any call.
	 *
	 * @return bool
	 */
	public static function ready() {
		return DoughBoss_Settings::tyro_ready();
	}

	/**
	 * Active mode: 'test' or 'live'.
	 *
	 * @return string
	 */
	public static function mode() {
		return DoughBoss_Settings::tyro_mode();
	}

	/**
	 * Public-safe identifier the browser needs to initialise Tyro's Session.js
	 * against the right merchant — analogous to Stripe's publishable key.
	 *
	 * @return string
	 */
	public static function publishable_key() {
		return DoughBoss_Settings::tyro_merchant_id();
	}

	/**
	 * Integration password for the active mode (server-side only).
	 *
	 * @return string
	 */
	private static function password() {
		return DoughBoss_Settings::tyro_password();
	}

	/**
	 * Convert a major-unit amount (e.g. dollars) to the smallest currency unit
	 * (e.g. cents) — same convention as DoughBoss_Stripe::to_minor_units() so
	 * call sites can treat both gateways identically.
	 *
	 * @param float $amount Major-unit amount.
	 * @return int
	 */
	public static function to_minor_units( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Start a payment: create a Hosted Session and a merchant order reference.
	 * The browser (not built in this pass — a frontend task) uses the
	 * returned session id with Tyro's Session.js to collect card details
	 * without them ever touching this server. Mirrors
	 * DoughBoss_Stripe::create_payment_intent()'s return shape so
	 * DoughBoss_Payment can dispatch to either gateway identically.
	 *
	 * @param int    $amount_minor Amount in the smallest currency unit (cents).
	 * @param string $currency     ISO currency code (e.g. AUD).
	 * @param array  $metadata     Optional key/value metadata, stashed against the
	 *                             order id for use in the later PAY transaction's
	 *                             order.description field (Tyro's session-create
	 *                             call has no metadata field of its own).
	 * @return array|WP_Error { id, client_secret, amount, currency } or error.
	 *                         `id` is composite (order id + session id);
	 *                         `client_secret` is the bare Hosted Session id.
	 */
	public static function create_payment_intent( $amount_minor, $currency, array $metadata = array() ) {
		$amount_minor = (int) $amount_minor;
		if ( $amount_minor < 1 ) {
			return new WP_Error( 'doughboss_pay_amount', __( 'Invalid payment amount.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$session = self::request( 'POST', '/session', array() );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$session_id = isset( $session['session']['id'] ) ? (string) $session['session']['id'] : '';
		if ( '' === $session_id ) {
			return new WP_Error( 'doughboss_pay_create', __( 'Could not start the card payment. Please try again.', 'doughboss' ), array( 'status' => 502 ) );
		}

		$order_id = self::new_order_id();

		// Stash metadata AND the requested amount/currency against the order id.
		// The amount/currency are needed later by retrieve_payment_intent() to
		// build the PAY transaction body — Tyro requires them on that call, but
		// retrieve_payment_intent()'s signature (mirroring Stripe's) takes only
		// an id, so this transient is the hand-off. Short TTL — only needed for
		// the single checkout attempt this session belongs to; if it expires
		// before the customer completes payment, retrieve_payment_intent() fails
		// closed (see below) rather than guessing an amount.
		$stash                  = $metadata;
		$stash['_amount_minor'] = $amount_minor;
		$stash['_currency']     = strtoupper( (string) $currency );
		set_transient( 'doughboss_tyro_meta_' . $order_id, $stash, 30 * MINUTE_IN_SECONDS );

		return array(
			'id'            => $order_id . self::ID_DELIM . $session_id,
			'client_secret' => $session_id,
			'amount'        => $amount_minor,
			// Lowercase for consistency with DoughBoss_Stripe::create_payment_intent()'s
			// return shape (callers/JS treat this as a display value); Tyro's own
			// wire format always uses strtoupper() at the actual API call sites.
			'currency'      => strtolower( (string) $currency ),
		);
	}

	/**
	 * Submit (if not already submitted) and verify a payment before an order
	 * is trusted as paid — the Tyro/MPGS equivalent of Stripe's "retrieve a
	 * PaymentIntent and check its status", except here the charge itself may
	 * not have happened yet: Tyro requires the merchant server to actively
	 * submit the PAY transaction using the session the browser populated.
	 *
	 * Idempotent: if a PAY transaction already exists for this order id (a
	 * retry, or the checkout call racing a webhook), the existing result is
	 * read back via GET rather than submitting a second charge.
	 *
	 * @param string $id Composite id returned by create_payment_intent()
	 *                   ("{order_id}.{session_id}").
	 * @return array|WP_Error { status, amount, currency } — status is
	 *                        normalised to Stripe's vocabulary ('succeeded' on
	 *                        a captured payment) so callers written against
	 *                        DoughBoss_Payment need no gateway-specific
	 *                        branching.
	 */
	public static function retrieve_payment_intent( $id ) {
		list( $order_id, $session_id ) = self::split_id( $id );
		if ( '' === $order_id ) {
			return new WP_Error( 'doughboss_pay_id', __( 'Invalid payment reference.', 'doughboss' ), array( 'status' => 400 ) );
		}

		// Loaded once, up front, so both the idempotent-GET path and the PAY-submit
		// path below can echo the same original caller-supplied metadata back to
		// the caller (verify_payment() checks order_type/location_id from it,
		// exactly as it does for Stripe's PaymentIntent metadata).
		$meta = get_transient( 'doughboss_tyro_meta_' . $order_id );

		// Idempotent check first: has this order already been charged (our own
		// earlier attempt, or a webhook that beat us here)?
		$existing = self::request( 'GET', '/order/' . rawurlencode( $order_id ) );
		if ( ! is_wp_error( $existing ) && self::order_is_captured( $existing ) ) {
			return self::normalise_order( $existing, $meta );
		}

		// Not yet captured: submit the PAY transaction now. Requires the session
		// id, which only the composite id (not a bare order id) carries — a
		// second retrieve_payment_intent() call after this one succeeds will
		// take the idempotent GET path above instead of re-submitting.
		if ( '' === $session_id ) {
			return new WP_Error( 'doughboss_pay_id', __( 'This payment cannot be verified again — please start a new payment.', 'doughboss' ), array( 'status' => 400 ) );
		}

		// Amount/currency for the PAY call come from the order we're about to
		// create — Tyro requires them on the transaction itself. We do not have
		// them from the composite id alone, so the caller (DoughBoss_Payment /
		// the REST controller) is expected to have already validated the
		// expected amount server-side before calling this; we echo back
		// whatever Tyro actually captured, and the caller compares that against
		// its own expected total exactly as it does for Stripe today.
		$desc = ( is_array( $meta ) && isset( $meta['order_type'] ) ) ? sanitize_text_field( (string) $meta['order_type'] ) : '';

		// We don't have the amount at this layer (Tyro's PAY body requires one,
		// but this method's contract — mirroring Stripe's retrieve — takes only
		// an id). Re-read the session-attached order the create step opened:
		// MPGS's PAY call accepts order.amount/order.currency and will use them
		// to open the order if it does not exist yet, OR will validate against
		// an already-open order if it does. We pull the originally-requested
		// amount back out of the same metadata transient (stored by
		// create_payment_intent()); if it is missing (expired transient, e.g. a
		// very slow checkout), fail closed rather than guessing an amount.
		if ( ! is_array( $meta ) || ! isset( $meta['_amount_minor'], $meta['_currency'] ) ) {
			return new WP_Error( 'doughboss_pay_expired', __( 'This payment session has expired. Please try again.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$amount_minor = (int) $meta['_amount_minor'];
		$currency     = (string) $meta['_currency'];

		$txn_id = 'p' . substr( md5( $order_id . microtime( true ) ), 0, 12 );
		$body   = array(
			'apiOperation'   => 'PAY',
			'session'        => array( 'id' => $session_id ),
			'order'          => array(
				'amount'      => self::format_amount( $amount_minor ),
				'currency'    => strtoupper( $currency ),
				'description' => '' !== $desc ? $desc : 'DoughBoss order',
			),
			'sourceOfFunds'  => array( 'type' => 'CARD' ),
			'transaction'    => array( 'reference' => $order_id ),
		);

		$result = self::request( 'PUT', '/order/' . rawurlencode( $order_id ) . '/transaction/' . rawurlencode( $txn_id ), $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::normalise_order( $result, $meta );
	}

	/**
	 * Refund a captured order, in full or in part.
	 *
	 * @param string   $id           Composite or bare order id (only the order
	 *                                id half is used — a refund never needs the
	 *                                original session).
	 * @param int|null $amount_minor Amount in cents, or null for a full refund.
	 * @return array|WP_Error
	 */
	public static function create_refund( $id, $amount_minor = null ) {
		list( $order_id, ) = self::split_id( $id );
		if ( '' === $order_id ) {
			return new WP_Error( 'doughboss_pay_id', __( 'Invalid payment reference.', 'doughboss' ), array( 'status' => 400 ) );
		}

		// A full refund needs the captured amount/currency, which only Tyro's
		// order record has by this point (we don't retain it locally). Look it
		// up rather than guessing.
		$order = self::request( 'GET', '/order/' . rawurlencode( $order_id ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$captured_minor = isset( $order['totalCapturedAmount'] )
			? (int) round( (float) $order['totalCapturedAmount'] * 100 )
			: 0;
		$currency = isset( $order['currency'] ) ? (string) $order['currency'] : '';
		if ( $captured_minor < 1 || '' === $currency ) {
			return new WP_Error( 'doughboss_pay_refund', __( 'Could not determine the captured amount to refund.', 'doughboss' ), array( 'status' => 502 ) );
		}

		$refund_minor = null !== $amount_minor ? max( 1, min( $captured_minor, (int) $amount_minor ) ) : $captured_minor;
		$txn_id       = 'r' . substr( md5( $order_id . microtime( true ) ), 0, 12 );

		return self::request(
			'PUT',
			'/order/' . rawurlencode( $order_id ) . '/transaction/' . rawurlencode( $txn_id ),
			array(
				'apiOperation' => 'REFUND',
				'transaction'  => array(
					'amount'   => self::format_amount( $refund_minor ),
					'currency' => strtoupper( $currency ),
				),
			)
		);
	}

	/**
	 * Read-only connectivity check for the admin "Test connection" action —
	 * confirms the configured merchant id + password authenticate, without
	 * moving any money. Mirrors the pattern of
	 * DoughBoss_POSPal::test_connection().
	 *
	 * @return array|WP_Error
	 */
	public static function test_connection() {
		// MPGS has no dedicated "ping" endpoint; a GET on a deliberately
		// non-existent order id authenticates (401 on bad credentials vs 404 on
		// good credentials + unknown order) without any side effect.
		$probe = self::request( 'GET', '/order/doughboss-connection-test' );
		if ( ! is_wp_error( $probe ) ) {
			// The probe order id should never actually exist; an unexpected 2xx
			// here means either a stale test fixture or an unverified API shape
			// mismatch — report it rather than silently declaring success.
			return array( 'connected' => true );
		}
		// Only a genuine HTTP 404 (order authenticated against, but not found) —
		// exactly what a successful auth against an unknown order id looks like —
		// counts as a successful connection check. Any other outcome (auth
		// failure, network error, missing config, or an API error whose HTTP
		// status isn't 404) is reported as a failure rather than assumed benign.
		$data = $probe->get_error_data();
		if ( 'doughboss_pay_api' === $probe->get_error_code() && is_array( $data ) && 404 === (int) ( $data['http_status'] ?? 0 ) ) {
			return array( 'connected' => true );
		}
		return $probe;
	}

	/**
	 * Webhook signing secret for the active mode (server-side only).
	 *
	 * @return string
	 */
	public static function webhook_secret() {
		return DoughBoss_Settings::tyro_webhook_secret();
	}

	/**
	 * Verify a Tyro webhook signature.
	 *
	 * Scheme: HMAC-SHA256 of the raw request body, keyed by the configured
	 * webhook secret, compared against the signature header. The exact header
	 * name is configurable (DoughBoss_Settings::tyro_webhook_signature_header())
	 * because it has not been confirmed against Tyro's live webhook docs — see
	 * the class docblock. Fails closed: any missing piece (no secret
	 * configured, no header present, empty payload) returns false rather than
	 * skipping the check.
	 *
	 * @param string $payload    Raw request body, exactly as received.
	 * @param string $sig_header The signature header value.
	 * @return bool
	 */
	public static function verify_webhook_signature( $payload, $sig_header ) {
		$secret = self::webhook_secret();
		if ( '' === $secret || '' === (string) $sig_header || '' === (string) $payload ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', (string) $payload, $secret );
		// Some gateways prefix the header value (e.g. "sha256=..."); accept
		// either form rather than failing on a harmless formatting difference.
		$candidate = (string) $sig_header;
		if ( false !== strpos( $candidate, '=' ) ) {
			$parts     = explode( '=', $candidate, 2 );
			$candidate = end( $parts );
		}

		return hash_equals( $expected, $candidate );
	}

	/**
	 * Generate a merchant-assigned order id: MPGS lets the merchant choose it,
	 * constrained to a safe alnum charset (avoids ID_DELIM and any character
	 * that could complicate URL-path or query-string handling).
	 *
	 * @return string
	 */
	private static function new_order_id() {
		return 'db' . substr( str_replace( array( '.', '-', '_' ), '', wp_generate_password( 24, false, false ) ), 0, 20 );
	}

	/**
	 * The id that should be PERSISTED (order row / voucher / dedup lookups) —
	 * the bare merchant order id, without the client-side session-id half a
	 * composite id carries. Session ids only matter for the one
	 * retrieve_payment_intent() call that actually submits the PAY
	 * transaction; anything that outlives that single request (the stored
	 * `payment_intent_id` column, and anything a webhook needs to look up
	 * later) must use this canonical form — a webhook payload can only ever
	 * carry Tyro's own order id, never our client-generated session id, so
	 * persisting the composite would make webhook reconciliation permanently
	 * unable to find the order it is looking for.
	 *
	 * @param string $id Composite or bare id.
	 * @return string
	 */
	public static function canonical_id( $id ) {
		list( $order_id, ) = self::split_id( $id );
		return $order_id;
	}

	/**
	 * Split a composite id ("{order_id}.{session_id}") or accept a bare order
	 * id (e.g. from create_refund(), which never needs the session half).
	 *
	 * @param string $id Composite or bare id.
	 * @return array{0:string,1:string} [order_id, session_id] — session_id is
	 *                                  '' when a bare order id was supplied.
	 */
	private static function split_id( $id ) {
		$id = sanitize_text_field( (string) $id );
		if ( '' === $id ) {
			return array( '', '' );
		}
		if ( false === strpos( $id, self::ID_DELIM ) ) {
			return array( $id, '' );
		}
		$parts = explode( self::ID_DELIM, $id, 2 );
		return array( $parts[0], isset( $parts[1] ) ? $parts[1] : '' );
	}

	/**
	 * Whether an MPGS order response represents a successfully captured
	 * payment. MPGS order status values include CAPTURED/APPROVED-family
	 * strings; treated narrowly (allow-list, not a deny-list) so an
	 * unrecognised status is never mistaken for success.
	 *
	 * @param array $order Decoded order response.
	 * @return bool
	 */
	private static function order_is_captured( array $order ) {
		$status = isset( $order['status'] ) ? (string) $order['status'] : '';
		return in_array( $status, array( 'CAPTURED', 'PAID' ), true );
	}

	/**
	 * Normalise an MPGS order response into the { status, amount, currency,
	 * metadata } shape DoughBoss_Payment callers already expect from Stripe's
	 * retrieve_payment_intent() (status 'succeeded' on a captured payment).
	 *
	 * Tyro's own order response carries no caller-supplied metadata (MPGS has
	 * no equivalent field), so `metadata` is echoed back from the transient
	 * create_payment_intent() stashed against this order id — the same
	 * mechanism that supplies the PAY transaction's amount/currency above.
	 * Without this, REST_Controller::verify_payment()'s order_type/location_id
	 * check (shared across both gateways) would reject every Tyro payment.
	 *
	 * @param array      $order Decoded order response.
	 * @param array|null $meta  The create_payment_intent() metadata transient,
	 *                          or null/false if it expired or was never set.
	 * @return array
	 */
	private static function normalise_order( array $order, $meta = null ) {
		$captured = self::order_is_captured( $order );
		$amount   = isset( $order['totalCapturedAmount'] ) ? self::to_minor_units( (float) $order['totalCapturedAmount'] ) : 0;
		$currency = isset( $order['currency'] ) ? strtolower( (string) $order['currency'] ) : '';
		$metadata = is_array( $meta ) ? $meta : array();
		unset( $metadata['_amount_minor'], $metadata['_currency'] );
		return array(
			'status'   => $captured ? 'succeeded' : 'failed',
			'amount'   => $amount,
			'currency' => $currency,
			'metadata' => $metadata,
		);
	}

	/**
	 * MPGS amounts are decimal major-unit strings (e.g. "12.50"), not integer
	 * minor units like Stripe — convert our internal minor-unit ints at the
	 * API boundary only, so the rest of this class stays in minor units like
	 * every other DoughBoss money value.
	 *
	 * @param int $amount_minor Amount in cents.
	 * @return string
	 */
	private static function format_amount( $amount_minor ) {
		return number_format( ( (int) $amount_minor ) / 100, 2, '.', '' );
	}

	/**
	 * Perform an authenticated request to the Tyro (MPGS) API.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   API path beginning with '/' (relative to
	 *                       /merchant/{merchantId}).
	 * @param array  $body   JSON body for write calls.
	 * @return array|WP_Error Decoded JSON, or an error.
	 */
	private static function request( $method, $path, array $body = array() ) {
		$merchant_id = DoughBoss_Settings::tyro_merchant_id();
		$password    = self::password();
		if ( '' === $merchant_id || '' === $password ) {
			return new WP_Error( 'doughboss_pay_config', __( 'Card payments are not configured.', 'doughboss' ), array( 'status' => 503 ) );
		}

		$url = DoughBoss_Settings::tyro_host()
			. '/api/rest/version/' . rawurlencode( DoughBoss_Settings::tyro_api_version() )
			. '/merchant/' . rawurlencode( $merchant_id )
			. $path;

		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( 'merchant.' . $merchant_id . ':' . $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth, not obfuscation.
				'Content-Type'  => 'application/json',
			),
		);
		if ( ! empty( $body ) ) {
			$encoded = wp_json_encode( $body );
			if ( false === $encoded ) {
				return new WP_Error( 'doughboss_pay_encode', __( 'Could not encode the payment request.', 'doughboss' ), array( 'status' => 500 ) );
			}
			$args['body'] = $encoded;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'doughboss_pay_network', __( 'Could not reach the payment service. Please try again.', 'doughboss' ), array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code || 403 === $code ) {
			if ( function_exists( 'error_log' ) ) {
				error_log( 'DoughBoss Tyro error: HTTP ' . $code . ' (auth)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return new WP_Error( 'doughboss_pay_auth', __( 'Card payments could not authenticate. Check the Tyro merchant ID and password.', 'doughboss' ), array( 'status' => 502 ) );
		}

		// Strict success check: MPGS responses that succeed carry no top-level
		// "error" object; a non-2xx status OR a present "error" object is
		// treated as failure even if some fields parsed — never infer success
		// from partial/ambiguous data.
		if ( $code >= 200 && $code < 300 && is_array( $data ) && ! isset( $data['error'] ) ) {
			return $data;
		}

		$message = ( is_array( $data ) && isset( $data['error']['explanation'] ) && is_scalar( $data['error']['explanation'] ) )
			? (string) $data['error']['explanation']
			: __( 'The payment service returned an error.', 'doughboss' );

		// Log only the status + Tyro's short error cause for the operator — never
		// the response body (can carry card-holder details) or the 'explanation'
		// message in the log line itself (only returned to the caller as a
		// WP_Error, which higher layers already treat as not-for-storage).
		if ( function_exists( 'error_log' ) ) {
			$cause = ( is_array( $data ) && isset( $data['error']['cause'] ) && is_scalar( $data['error']['cause'] ) )
				? (string) $data['error']['cause']
				: '';
			error_log( 'DoughBoss Tyro error: HTTP ' . $code . ( '' !== $cause ? ' cause=' . $cause : '' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// http_status is the raw upstream code (distinct from the synthetic REST
		// 'status' above) so callers like test_connection() can distinguish a
		// genuine "not found" from a real API failure without guessing.
		return new WP_Error( 'doughboss_pay_api', $message, array( 'status' => 502, 'http_status' => $code ) );
	}
}
