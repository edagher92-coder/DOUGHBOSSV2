<?php
/**
 * Square Payments API gateway (optional, off by default).
 *
 * A thin, dependency-free wrapper over the Square Payments API used to take and
 * verify a single card payment for one storefront order. No SDK is bundled —
 * calls go through `wp_remote_*`, exactly like DoughBoss_Stripe and
 * DoughBoss_Tyro. When payments are disabled, when Square is not the selected
 * gateway, or when any credential for the active mode is missing, the whole
 * feature is dormant and the storefront behaves exactly as a pay-on-pickup site.
 *
 * Flow (Square Web Payments SDK → this class):
 *
 *   browser tokenises the card  ─▶  source_id (single-use payment token)
 *   POST /payment-intent (source_id) ─▶ DoughBoss_Square::create_payment_intent()
 *   ─▶ Square POST /v2/payments (idempotency_key, amount, currency, location)
 *   ─▶ payment id ─▶ POST /checkout ─▶ REST verify_payment()
 *   ─▶ DoughBoss_Square::retrieve_payment_intent() re-reads the payment from
 *      Square and refuses anything that is not COMPLETED for the exact amount.
 *
 * Square's Payments API has no arbitrary metadata map (only a 40-character
 * `reference_id` and a free-text `note`), so — exactly like DoughBoss_Tyro —
 * the immutable checkout metadata lives in this site's own
 * doughboss_payment_attempts row and is reconstructed from there on retrieval.
 * The provider is only ever asked for money facts: status, amount, currency and
 * the binding reference. Nothing a client sends is trusted.
 *
 * UNVERIFIED AGAINST A LIVE SQUARE ACCOUNT. This code was written from the
 * published Square API shapes and could not be exercised against the real
 * service in the environment it was built in. Every response field is therefore
 * read defensively and every unexpected shape fails closed (no order is ever
 * marked paid on a value this class could not positively confirm).
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal Square Payments client.
 */
class DoughBoss_Square {

	/**
	 * Square sandbox API origin.
	 */
	const SANDBOX_HOST = 'https://connect.squareupsandbox.com';

	/**
	 * Square production API origin.
	 */
	const PRODUCTION_HOST = 'https://connect.squareup.com';

	/**
	 * Square Web Payments SDK browser bundle (sandbox).
	 */
	const SANDBOX_SDK_URL = 'https://sandbox.web.squarecdn.com/v1/square.js';

	/**
	 * Square Web Payments SDK browser bundle (production).
	 */
	const PRODUCTION_SDK_URL = 'https://web.squarecdn.com/v1/square.js';

	/**
	 * Square rejects an idempotency_key longer than this.
	 */
	const IDEMPOTENCY_KEY_MAX = 45;

	/**
	 * Square rejects a reference_id longer than this.
	 */
	const REFERENCE_ID_MAX = 40;

	/**
	 * Whether card payments are switched on AND Square is both the selected
	 * gateway and fully configured for the current (test/live) mode. The single
	 * gate the rest of the plugin checks.
	 *
	 * @return bool
	 */
	public static function ready() {
		return DoughBoss_Settings::square_ready();
	}

	/**
	 * Active mode: 'test' (Square sandbox) or 'live' (Square production).
	 *
	 * @return string
	 */
	public static function mode() {
		return DoughBoss_Settings::square_mode();
	}

	/**
	 * Whether the active mode is Square production.
	 *
	 * @return bool
	 */
	public static function live_mode() {
		return 'live' === self::mode();
	}

	/**
	 * API origin for the active mode.
	 *
	 * @return string
	 */
	public static function api_host() {
		return self::live_mode() ? self::PRODUCTION_HOST : self::SANDBOX_HOST;
	}

	/**
	 * Web Payments SDK URL for the active mode. Square requires this file to be
	 * loaded from Square's own CDN — never bundle, proxy or self-host it.
	 *
	 * @return string
	 */
	public static function sdk_url() {
		return self::live_mode() ? self::PRODUCTION_SDK_URL : self::SANDBOX_SDK_URL;
	}

	/**
	 * Browser bootstrap identifier. Square's application id is public by design
	 * (it is required by the Web Payments SDK in the page). The access token is
	 * never exposed here.
	 *
	 * @return string
	 */
	public static function publishable_key() {
		return DoughBoss_Settings::square_application_id();
	}

	/**
	 * Square location id for the active mode. Public by design — the Web
	 * Payments SDK needs it alongside the application id.
	 *
	 * @return string
	 */
	public static function location_id() {
		return DoughBoss_Settings::square_location_id();
	}

	/**
	 * Access token for the active mode (server-side only — never sent to a client).
	 *
	 * @return string
	 */
	private static function access_token() {
		return DoughBoss_Settings::square_access_token();
	}

	/**
	 * Convert a major-unit amount (dollars) to the smallest currency unit
	 * (cents) Square expects. AUD prices in this plugin are already
	 * GST-inclusive, so this is a pure unit conversion — never a tax step.
	 *
	 * @param float $amount Major-unit amount.
	 * @return int
	 */
	public static function to_minor_units( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * The id persisted on the order row and used for dedup/webhook lookups.
	 * A Square payment id already IS the canonical storable reference, so this
	 * validates rather than transforms. An id that does not match Square's
	 * documented shape returns '' so every caller fails closed.
	 *
	 * @param string $id Square payment id.
	 * @return string
	 */
	public static function canonical_id( $id ) {
		$id = sanitize_text_field( (string) $id );
		return preg_match( '/^[A-Za-z0-9_-]{8,192}$/', $id ) ? $id : '';
	}

	/**
	 * Build a Square-safe idempotency key.
	 *
	 * Square caps idempotency_key at 45 characters, so the Stripe helper's
	 * "prefix + full sha256" form (77 characters) would be rejected outright.
	 * A 40-hex-character prefix of the same digest keeps this inside the limit
	 * while staying collision-safe for this purpose.
	 *
	 * The identity deliberately excludes the card token: a retry of the SAME
	 * checkout must replay the SAME Square payment rather than charge twice.
	 *
	 * @param string $operation Short operation name ('pay' / 'refund').
	 * @param string $identity  Stable server-owned operation identity.
	 * @return string
	 */
	public static function idempotency_key( $operation, $identity ) {
		$operation = sanitize_key( $operation );
		$key       = 'db-' . substr( $operation, 0, 6 ) . '-' . substr( hash( 'sha256', $operation . '|' . (string) $identity ), 0, 32 );
		return substr( $key, 0, self::IDEMPOTENCY_KEY_MAX );
	}

	/**
	 * Map a Square payment status onto the plugin's gateway-agnostic vocabulary.
	 * Anything unrecognised becomes 'unknown', which never satisfies
	 * verify_payment().
	 *
	 * @param string $status Square status.
	 * @return string
	 */
	public static function normalised_status( $status ) {
		switch ( strtoupper( trim( (string) $status ) ) ) {
			case 'COMPLETED':
				return 'succeeded';
			case 'APPROVED':
			case 'PENDING':
				return 'processing';
			case 'CANCELED':
			case 'CANCELLED':
				return 'voided';
			case 'FAILED':
				return 'failed';
			default:
				return 'unknown';
		}
	}

	/**
	 * The immutable binding written into Square's `reference_id` so a retrieved
	 * payment can be proven to belong to this site's attempt row.
	 *
	 * @param int $attempt_id Durable attempt row id.
	 * @return string
	 */
	public static function attempt_reference( $attempt_id ) {
		return substr( 'db-attempt-' . absint( $attempt_id ), 0, self::REFERENCE_ID_MAX );
	}

	/**
	 * Take a card payment for the given amount.
	 *
	 * Required metadata: `checkout_key` (64 hex), `location_id` (DoughBoss shop
	 * id) and `source_id` (the single-use token the Web Payments SDK produced in
	 * the browser). `verification_token` is optional 3-D Secure evidence.
	 *
	 * The amount is whatever the REST controller computed from the stored cart —
	 * this class never reads an amount from the browser.
	 *
	 * @param int    $amount_minor Amount in cents.
	 * @param string $currency     ISO currency code (e.g. AUD).
	 * @param array  $metadata     Server-owned checkout metadata (+ source_id).
	 * @return array|WP_Error { id, status, amount, currency, attempt_id } or error.
	 */
	public static function create_payment_intent( $amount_minor, $currency, array $metadata = array() ) {
		$amount_minor = absint( $amount_minor );
		$currency     = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $currency ) );
		$checkout_key = isset( $metadata['checkout_key'] ) ? strtolower( sanitize_text_field( (string) $metadata['checkout_key'] ) ) : '';
		$location_id  = isset( $metadata['location_id'] ) ? absint( $metadata['location_id'] ) : 0;
		$table_id     = isset( $metadata['table_id'] ) ? absint( $metadata['table_id'] ) : 0;
		$qr_code_id   = isset( $metadata['qr_code_id'] ) ? absint( $metadata['qr_code_id'] ) : 0;
		$source_id    = isset( $metadata['source_id'] ) ? sanitize_text_field( (string) $metadata['source_id'] ) : '';
		$verification = isset( $metadata['verification_token'] ) ? sanitize_text_field( (string) $metadata['verification_token'] ) : '';
		$square_loc   = self::location_id();

		if (
			$amount_minor < 1
			|| 3 !== strlen( $currency )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $checkout_key )
			|| ! $location_id
			|| '' === $square_loc
		) {
			return new WP_Error( 'doughboss_pay_request', __( 'The payment request is incomplete.', 'doughboss' ), array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_:.-]{8,1024}$/', $source_id ) ) {
			return new WP_Error( 'doughboss_pay_source', __( 'The card details could not be read securely. Please re-enter your card and try again.', 'doughboss' ), array( 'status' => 400 ) );
		}
		if ( '' !== $verification && ! preg_match( '/^[A-Za-z0-9_:.-]{8,1024}$/', $verification ) ) {
			return new WP_Error( 'doughboss_pay_source', __( 'The card verification could not be read securely. Please try again.', 'doughboss' ), array( 'status' => 400 ) );
		}

		// The single-use card token and 3DS evidence must never reach durable
		// storage or a log line — strip them before the attempt row is written.
		$safe_metadata = $metadata;
		unset(
			$safe_metadata['attempt_key'],
			$safe_metadata['checkout_key'],
			$safe_metadata['source_id'],
			$safe_metadata['verification_token']
		);
		$safe_metadata['square_location_id'] = $square_loc;

		$attempt = DoughBoss_Payment_Attempts::create_or_find(
			array(
				'attempt_key'     => hash( 'sha256', 'square|' . $checkout_key ),
				'checkout_key'    => $checkout_key,
				'provider'        => 'square',
				'purpose'         => isset( $metadata['purpose'] ) ? $metadata['purpose'] : 'order',
				'context'         => isset( $metadata['context'] ) ? $metadata['context'] : ( $table_id ? 'table_qr' : 'web' ),
				'local_reference' => isset( $metadata['local_reference'] ) ? $metadata['local_reference'] : '',
				'location_id'     => $location_id,
				'table_id'        => $table_id,
				'qr_code_id'      => $qr_code_id,
				'amount_minor'    => $amount_minor,
				'currency'        => $currency,
				'status'          => 'created',
				'safe_metadata'   => $safe_metadata,
			)
		);
		if ( ! $attempt ) {
			return new WP_Error( 'doughboss_pay_storage', __( 'The payment could not be recorded safely.', 'doughboss' ), array( 'status' => 503 ) );
		}
		if (
			'square' !== (string) $attempt['provider']
			|| (int) $attempt['amount_minor'] !== $amount_minor
			|| strtoupper( (string) $attempt['currency'] ) !== $currency
			|| (int) $attempt['location_id'] !== $location_id
			|| (int) $attempt['table_id'] !== $table_id
			|| (int) $attempt['qr_code_id'] !== $qr_code_id
		) {
			return new WP_Error( 'doughboss_pay_attempt_changed', __( 'Your order changed while payment was being prepared. Please start payment again.', 'doughboss' ), array( 'status' => 409 ) );
		}

		// This checkout already produced a Square payment. Never charge again —
		// re-read the existing one and let the caller verify it.
		$existing_reference = isset( $attempt['provider_reference'] ) ? (string) $attempt['provider_reference'] : '';
		if ( '' !== $existing_reference ) {
			return self::replay( $existing_reference, $attempt, $amount_minor, $currency );
		}

		if ( ! DoughBoss_Payment_Attempts::claim_creation( (int) $attempt['id'] ) ) {
			$attempt = DoughBoss_Payment_Attempts::find( (int) $attempt['id'] );
			if ( $attempt && ! empty( $attempt['provider_reference'] ) ) {
				return self::replay( (string) $attempt['provider_reference'], $attempt, $amount_minor, $currency );
			}
			return new WP_Error( 'doughboss_pay_provisioning', __( 'Your secure payment session is still being prepared. Please wait a moment and try again.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$body = array(
			'idempotency_key' => self::idempotency_key( 'pay', $checkout_key ),
			'source_id'       => $source_id,
			'amount_money'    => array(
				'amount'   => $amount_minor,
				'currency' => $currency,
			),
			'location_id'     => $square_loc,
			'reference_id'    => self::attempt_reference( (int) $attempt['id'] ),
			'autocomplete'    => true,
		);
		if ( '' !== $verification ) {
			$body['verification_token'] = $verification;
		}

		$response = self::request( 'POST', '/v2/payments', $body );
		if ( is_wp_error( $response ) ) {
			// Releasing the creation claim is safe: the idempotency key is derived
			// from the server-owned checkout key, so a replay of this same
			// checkout returns Square's original payment instead of charging
			// again — even if the network failed after Square accepted the call.
			DoughBoss_Payment_Attempts::release_creation( (int) $attempt['id'], $response->get_error_code() );
			return $response;
		}

		$payment = isset( $response['payment'] ) && is_array( $response['payment'] ) ? $response['payment'] : array();
		$payload = self::payment_payload( $payment, $attempt, $amount_minor, $currency );
		if ( is_wp_error( $payload ) ) {
			DoughBoss_Payment_Attempts::release_creation( (int) $attempt['id'], $payload->get_error_code() );
			return $payload;
		}

		$bound = DoughBoss_Payment_Attempts::bind_provider_reference(
			(int) $attempt['id'],
			(string) $payload['id'],
			$payload['status'],
			isset( $payment['status'] ) ? (string) $payment['status'] : ''
		);
		if ( ! $bound ) {
			$bound = DoughBoss_Payment_Attempts::find( (int) $attempt['id'] );
			if ( ! $bound || (string) $bound['provider_reference'] !== (string) $payload['id'] ) {
				// Unlike Stripe's create step, this one has ALREADY moved money.
				// Leave a greppable audit line naming the Square payment id (no
				// PII, no card data) so the operator can reconcile it even if the
				// webhook path below never fires.
				if ( function_exists( 'error_log' ) ) {
					error_log( 'DoughBoss Square: payment ' . (string) $payload['id'] . ' succeeded but could not be bound to attempt ' . (int) $attempt['id'] . ' — reconcile manually.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log — deliberate money-reconciliation audit trail.
				}
				DoughBoss_Payment_Attempts::release_creation( (int) $attempt['id'], 'doughboss_pay_binding' );
				return new WP_Error( 'doughboss_pay_binding', __( 'The payment reference could not be bound safely. Do not pay again — please contact the shop and we will confirm it for you.', 'doughboss' ), array( 'status' => 409 ) );
			}
		}

		$payload['attempt_id'] = (int) $bound['id'];
		return $payload;
	}

	/**
	 * Re-read a payment this checkout already created rather than charging again.
	 *
	 * A terminal failure (declined / cancelled) is reported as its own error so
	 * the storefront starts a genuinely NEW checkout attempt — reusing the same
	 * Square idempotency key with a different card would be rejected by Square.
	 *
	 * @param string $reference    Stored Square payment id.
	 * @param array  $attempt      Durable attempt row.
	 * @param int    $amount_minor Expected amount in cents.
	 * @param string $currency     Expected ISO currency.
	 * @return array|WP_Error
	 */
	private static function replay( $reference, array $attempt, $amount_minor, $currency ) {
		$response = self::retrieve_payment( $reference );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$payload = self::payment_payload( $response, $attempt, $amount_minor, $currency );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		if ( in_array( $payload['status'], array( 'failed', 'voided' ), true ) ) {
			return new WP_Error(
				'doughboss_pay_declined',
				__( 'That card payment was not approved. Please start payment again to try another card.', 'doughboss' ),
				array( 'status' => 402 )
			);
		}
		$payload['attempt_id'] = isset( $attempt['id'] ) ? (int) $attempt['id'] : 0;
		return $payload;
	}

	/**
	 * Fetch a raw Square payment object.
	 *
	 * @param string $id Square payment id.
	 * @return array|WP_Error The `payment` object, or an error.
	 */
	public static function retrieve_payment( $id ) {
		$id = self::canonical_id( $id );
		if ( '' === $id ) {
			return new WP_Error( 'doughboss_pay_id', __( 'Invalid payment reference.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$response = self::request( 'GET', '/v2/payments/' . rawurlencode( $id ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! isset( $response['payment'] ) || ! is_array( $response['payment'] ) ) {
			return new WP_Error( 'doughboss_pay_shape', __( 'The payment could not be read from Square.', 'doughboss' ), array( 'status' => 502 ) );
		}
		return $response['payment'];
	}

	/**
	 * Verify a payment server-side before an order is trusted as paid.
	 *
	 * Returns the gateway-agnostic shape verify_payment() asserts against:
	 * { id, status, amount, currency, metadata }. The metadata is reconstructed
	 * from this site's own attempt row (Square carries no metadata map), and the
	 * payment is refused unless Square's own reference_id, amount, currency and
	 * location all still match that row.
	 *
	 * @param string $id Square payment id.
	 * @return array|WP_Error
	 */
	public static function retrieve_payment_intent( $id ) {
		$id = self::canonical_id( $id );
		if ( '' === $id ) {
			return new WP_Error( 'doughboss_pay_id', __( 'Invalid payment reference.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $id );
		if ( ! $attempt ) {
			return new WP_Error( 'doughboss_pay_attempt', __( 'The payment attempt could not be reconciled.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$payment = self::retrieve_payment( $id );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		$provider_status = isset( $payment['status'] ) ? strtoupper( sanitize_text_field( (string) $payment['status'] ) ) : '';
		$status          = self::normalised_status( $provider_status );
		$money           = isset( $payment['amount_money'] ) && is_array( $payment['amount_money'] ) ? $payment['amount_money'] : array();
		$amount          = isset( $money['amount'] ) ? (int) $money['amount'] : -1;
		$money_currency  = isset( $money['currency'] ) ? strtoupper( sanitize_text_field( (string) $money['currency'] ) ) : '';
		$payment_loc     = isset( $payment['location_id'] ) ? sanitize_text_field( (string) $payment['location_id'] ) : '';

		$metadata = json_decode( (string) $attempt['safe_metadata_json'], true );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$expected_location = isset( $metadata['square_location_id'] ) ? (string) $metadata['square_location_id'] : '';
		$metadata['checkout_key'] = (string) $attempt['checkout_key'];

		// The amount is re-asserted against this site's own attempt row (which the
		// REST controller wrote from the server-computed cart total), never
		// against anything the browser sent. The Square location is checked too,
		// so a payment taken through a different location of the same Square
		// account can never satisfy this order.
		$valid = self::payment_matches(
			$payment,
			(int) $attempt['amount_minor'],
			(string) $attempt['currency'],
			self::attempt_reference( (int) $attempt['id'] )
		)
			&& '' !== $expected_location
			&& hash_equals( $expected_location, $payment_loc );

		if ( ! $valid ) {
			DoughBoss_Payment_Attempts::update(
				(int) $attempt['id'],
				array(
					'status'          => 'mismatch',
					'provider_status' => $provider_status,
					'last_error'      => 'provider_binding_mismatch',
				)
			);
			return new WP_Error( 'doughboss_pay_mismatch', __( 'The payment did not match this order.', 'doughboss' ), array( 'status' => 409 ) );
		}

		DoughBoss_Payment_Attempts::update(
			(int) $attempt['id'],
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
			'amount'          => $amount,
			'currency'        => strtolower( $money_currency ),
			'metadata'        => $metadata,
		);
	}

	/**
	 * Money facts for a payment that has NO attempt row but is provably this
	 * site's — the "orphan" case.
	 *
	 * It exists for one narrow, real failure: the charge succeeded but binding
	 * the Square payment id onto the attempt row afterwards did not, so
	 * find_by_provider_reference() can never match it. Without this, that money
	 * would be invisible to the webhook and lost to the customer.
	 *
	 * Ownership is proved from Square's own copy of the payment: it must be on
	 * this shop's configured Square location AND carry a reference_id in this
	 * plugin's `db-attempt-N` form. Anything else — another application on the
	 * same Square account, another location, a hand-made payment — is refused.
	 *
	 * Deliberately NOT enough to mark an order paid: the caller may only record
	 * it for a human to reconcile.
	 *
	 * @param string $id Square payment id.
	 * @return array|WP_Error { id, status, amount, currency } or an error.
	 */
	public static function orphan_payment( $id ) {
		$id = self::canonical_id( $id );
		if ( '' === $id ) {
			return new WP_Error( 'doughboss_pay_id', __( 'Invalid payment reference.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$payment = self::retrieve_payment( $id );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		$expected_location = self::location_id();
		$payment_location  = isset( $payment['location_id'] ) && is_scalar( $payment['location_id'] ) ? (string) $payment['location_id'] : '';
		$reference         = isset( $payment['reference_id'] ) && is_scalar( $payment['reference_id'] ) ? (string) $payment['reference_id'] : '';
		$money             = isset( $payment['amount_money'] ) && is_array( $payment['amount_money'] ) ? $payment['amount_money'] : array();
		$amount            = isset( $money['amount'] ) && is_numeric( $money['amount'] ) ? (int) $money['amount'] : 0;
		$currency          = isset( $money['currency'] ) && is_scalar( $money['currency'] ) ? strtolower( sanitize_text_field( (string) $money['currency'] ) ) : '';

		if (
			'' === $expected_location
			|| ! hash_equals( $expected_location, $payment_location )
			|| 0 !== strpos( $reference, 'db-attempt-' )
			|| $amount < 1
			|| 3 !== strlen( $currency )
		) {
			return new WP_Error( 'doughboss_pay_foreign', __( 'This payment does not belong to this shop.', 'doughboss' ), array( 'status' => 409 ) );
		}

		return array(
			'id'       => $id,
			'status'   => self::normalised_status( isset( $payment['status'] ) ? $payment['status'] : '' ),
			'amount'   => $amount,
			'currency' => $currency,
		);
	}

	/**
	 * Whether a Square payment object binds to exactly the money and attempt
	 * this server expects.
	 *
	 * This is the single amount/currency/binding assertion used by BOTH the
	 * create path and the verification path, so there is one place where "the
	 * gateway agrees with our own figures" is decided. Every comparison is
	 * strict and every missing or malformed field yields false — a payment is
	 * only ever accepted on values positively confirmed here.
	 *
	 * A client-supplied amount never reaches this function: $expected_amount_minor
	 * is always the server's own recomputed cart total in minor units.
	 *
	 * @param array  $payment                Square payment object.
	 * @param int    $expected_amount_minor  Server-computed amount in cents.
	 * @param string $expected_currency      Server-computed ISO currency.
	 * @param string $expected_reference     Expected Square reference_id.
	 * @return bool
	 */
	public static function payment_matches( array $payment, $expected_amount_minor, $expected_currency, $expected_reference ) {
		$id       = isset( $payment['id'] ) ? self::canonical_id( $payment['id'] ) : '';
		$money    = isset( $payment['amount_money'] ) && is_array( $payment['amount_money'] ) ? $payment['amount_money'] : array();
		$amount   = isset( $money['amount'] ) && is_scalar( $money['amount'] ) && is_numeric( $money['amount'] ) ? (int) $money['amount'] : -1;
		$currency = isset( $money['currency'] ) && is_scalar( $money['currency'] ) ? strtoupper( (string) $money['currency'] ) : '';
		$returned_reference = isset( $payment['reference_id'] ) && is_scalar( $payment['reference_id'] ) ? (string) $payment['reference_id'] : '';
		$expected_reference = (string) $expected_reference;

		return '' !== $id
			&& $amount >= 1
			&& $amount === (int) $expected_amount_minor
			&& '' !== $currency
			&& $currency === strtoupper( (string) $expected_currency )
			&& '' !== $expected_reference
			&& hash_equals( $expected_reference, $returned_reference );
	}

	/**
	 * Validate a payment object returned by create or replay against the amount
	 * and currency this server computed. Any divergence fails closed.
	 *
	 * @param array  $payment      Square payment object.
	 * @param array  $attempt      Durable attempt row.
	 * @param int    $amount_minor Expected amount in cents.
	 * @param string $currency     Expected ISO currency.
	 * @return array|WP_Error
	 */
	private static function payment_payload( array $payment, array $attempt, $amount_minor, $currency ) {
		$id       = isset( $payment['id'] ) ? self::canonical_id( $payment['id'] ) : '';
		$money    = isset( $payment['amount_money'] ) && is_array( $payment['amount_money'] ) ? $payment['amount_money'] : array();
		$amount   = isset( $money['amount'] ) ? (int) $money['amount'] : -1;
		$returned = isset( $money['currency'] ) ? strtoupper( (string) $money['currency'] ) : '';

		if ( ! self::payment_matches( $payment, $amount_minor, $currency, self::attempt_reference( isset( $attempt['id'] ) ? (int) $attempt['id'] : 0 ) ) ) {
			return new WP_Error( 'doughboss_pay_create', __( 'We could not confirm the card payment. Please try again or contact the shop.', 'doughboss' ), array( 'status' => 502 ) );
		}

		return array(
			'id'              => $id,
			'status'          => self::normalised_status( isset( $payment['status'] ) ? $payment['status'] : '' ),
			'provider_status' => isset( $payment['status'] ) ? strtoupper( sanitize_text_field( (string) $payment['status'] ) ) : '',
			'amount'          => $amount,
			'currency'        => strtolower( $returned ),
			'attempt_id'      => isset( $attempt['id'] ) ? (int) $attempt['id'] : 0,
		);
	}

	/**
	 * Refund a Square payment, in full or in part.
	 *
	 * @param string   $id           Square payment id.
	 * @param int|null $amount_minor Amount in cents, or null for a full refund.
	 * @return array|WP_Error
	 */
	public static function create_refund( $id, $amount_minor = null ) {
		$id = self::canonical_id( $id );
		if ( '' === $id ) {
			return new WP_Error( 'doughboss_pay_id', __( 'Invalid payment reference.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $id );
		if ( ! $attempt ) {
			return new WP_Error( 'doughboss_pay_refund', __( 'This payment has no matching record to refund against.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$full     = (int) $attempt['amount_minor'];
		$currency = strtoupper( (string) $attempt['currency'] );
		$refund   = null === $amount_minor ? $full : (int) $amount_minor;
		if ( $refund < 1 || $refund > $full || 3 !== strlen( $currency ) ) {
			return new WP_Error( 'doughboss_pay_refund', __( 'Invalid refund amount.', 'doughboss' ), array( 'status' => 400 ) );
		}

		return self::request(
			'POST',
			'/v2/refunds',
			array(
				'idempotency_key' => self::idempotency_key( 'refund', $id . '|' . $refund ),
				'payment_id'      => $id,
				'amount_money'    => array(
					'amount'   => $refund,
					'currency' => $currency,
				),
			)
		);
	}

	/**
	 * Read-only credential check for the admin connectivity action.
	 *
	 * @return array|WP_Error
	 */
	public static function test_connection() {
		$location = self::location_id();
		if ( '' === $location ) {
			return new WP_Error( 'doughboss_pay_config', __( 'Square is not configured for this mode.', 'doughboss' ), array( 'status' => 503 ) );
		}
		$response = self::request( 'GET', '/v2/locations/' . rawurlencode( $location ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'connected' => true,
			'mode'      => self::mode(),
		);
	}

	/**
	 * Webhook signature key for the active mode (server-side only).
	 *
	 * @return string
	 */
	public static function webhook_secret() {
		return DoughBoss_Settings::square_webhook_key();
	}

	/**
	 * The exact notification URL Square must be configured to POST to. Square
	 * signs the URL together with the body, so this string has to match the one
	 * registered in the Square dashboard byte for byte. An operator override is
	 * available in Settings for sites whose public REST URL differs from what
	 * rest_url() computes (reverse proxies, plain-permalink `?rest_route=`).
	 *
	 * @return string
	 */
	public static function notification_url() {
		return DoughBoss_Settings::square_webhook_url();
	}

	/**
	 * Verify a Square webhook signature.
	 *
	 * Square computes base64( HMAC-SHA256( notification_url + raw_body,
	 * signature_key ) ) and sends it in the
	 * `x-square-hmacsha256-signature` header. Both the URL and the body are
	 * covered, so a replay against a different endpoint fails too.
	 *
	 * Fails closed on a missing key, a missing header, an empty body or any
	 * mismatch. Compared with hash_equals to avoid a timing oracle.
	 *
	 * @param string $payload          Raw request body, exactly as received.
	 * @param string $signature_header The x-square-hmacsha256-signature value.
	 * @param string $notification_url Registered notification URL (defaults to
	 *                                 the configured/derived one).
	 * @return bool
	 */
	public static function verify_webhook_signature( $payload, $signature_header, $notification_url = null ) {
		$secret = self::webhook_secret();
		$url    = null === $notification_url ? self::notification_url() : (string) $notification_url;
		$sig    = trim( (string) $signature_header );

		if ( '' === $secret || '' === $url || '' === $sig || '' === (string) $payload ) {
			return false;
		}

		$expected = base64_encode( hash_hmac( 'sha256', $url . (string) $payload, $secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode — Square's documented signature encoding, not obfuscation.

		return hash_equals( $expected, $sig );
	}

	/**
	 * The WordPress-normalized form of Square's signature header name.
	 *
	 * @return string
	 */
	public static function webhook_signature_header() {
		return 'x_square_hmacsha256_signature';
	}

	/**
	 * Perform an authenticated request to the Square API.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   API path beginning with '/'.
	 * @param array  $body   JSON body for write calls.
	 * @return array|WP_Error Decoded JSON, or an error.
	 */
	private static function request( $method, $path, array $body = array() ) {
		if ( ! DoughBoss_Settings::square_access_token_valid() || '' === self::location_id() ) {
			return new WP_Error( 'doughboss_pay_config', __( 'Card payments are not configured.', 'doughboss' ), array( 'status' => 503 ) );
		}

		$args = array(
			'method'  => strtoupper( (string) $method ),
			'timeout' => 25,
			'headers' => array(
				'Authorization'  => 'Bearer ' . self::access_token(),
				'Square-Version' => DoughBoss_Settings::square_api_version(),
				'Content-Type'   => 'application/json',
				'Accept'         => 'application/json',
			),
		);
		if ( 'GET' !== $args['method'] && ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::api_host() . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'doughboss_pay_network', __( 'Could not reach the payment service. Do not pay again until its status is checked.', 'doughboss' ), array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			return $data;
		}

		// Log only the HTTP status and Square's short error category/code for the
		// operator — never the response body or `detail`, both of which can carry
		// customer PII or decline specifics.
		if ( function_exists( 'error_log' ) ) {
			$category = '';
			$error_code = '';
			if ( is_array( $data ) && isset( $data['errors'][0] ) && is_array( $data['errors'][0] ) ) {
				$first      = $data['errors'][0];
				$category   = isset( $first['category'] ) && is_scalar( $first['category'] ) ? substr( sanitize_key( (string) $first['category'] ), 0, 64 ) : '';
				$error_code = isset( $first['code'] ) && is_scalar( $first['code'] ) ? substr( sanitize_key( (string) $first['code'] ), 0, 64 ) : '';
			}
			error_log( 'DoughBoss Square error: HTTP ' . $code . ' category=' . $category . ' code=' . $error_code ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return new WP_Error( 'doughboss_pay_api', __( 'We could not complete the card payment. Please try again or contact the shop.', 'doughboss' ), array( 'status' => 502 ) );
	}
}
