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

	/** Current PHP request only: never persist or accept release authority from a caller. */
	private static $approved_release_attestations = array();

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
	 * Resolve the active Square location for one native DoughBoss shop.
	 *
	 * Square's legacy global location setting is deliberately not considered
	 * here. A v2 payment must be bound to the exact active native shop and its
	 * exact active-mode provider mapping, otherwise it cannot be sent or read.
	 *
	 * @param int $location_id Native DoughBoss location id.
	 * @return array|WP_Error { shop_slug, square_location_id, square_mode }.
	 */
	public static function shop_binding( $location_id ) {
		$location_id = absint( $location_id );
		if ( ! $location_id || ! class_exists( 'DoughBoss_Locations' ) ) {
			return self::shop_binding_error();
		}

		return self::shop_binding_from_location( DoughBoss_Locations::get( $location_id ), $location_id );
	}

	/**
	 * Validate a native shop row and derive its active-mode Square binding.
	 * Kept separate from the database lookup so the exact native-row contract is
	 * independently testable without a WordPress database fixture.
	 *
	 * @param object|null $location    Native location row.
	 * @param int         $location_id Expected native location id.
	 * @return array|WP_Error { shop_slug, square_location_id, square_mode }.
	 */
	private static function shop_binding_from_location( $location, $location_id ) {
		$location_id = absint( $location_id );
		$native_id   = is_object( $location ) && isset( $location->id ) ? absint( $location->id ) : 0;
		$slug        = is_object( $location ) && isset( $location->slug ) && is_scalar( $location->slug ) ? (string) $location->slug : '';
		$is_active   = is_object( $location ) && isset( $location->is_active ) && 1 === (int) $location->is_active;

		if (
			! $location_id
			|| $native_id !== $location_id
			|| ! $is_active
			|| ! in_array( $slug, array( 'revesby', 'bankstown', 'roselands' ), true )
		) {
			return self::shop_binding_error();
		}

		$square_location_id = DoughBoss_Settings::square_shop_location_id( $slug );
		$mode               = self::mode();
		if ( '' === $square_location_id || ! preg_match( '/^[A-Za-z0-9_-]{4,64}$/', $square_location_id ) || ! in_array( $mode, array( 'test', 'live' ), true ) ) {
			return self::shop_binding_error();
		}

		return array(
			'shop_slug'          => $slug,
			'square_location_id' => $square_location_id,
			'square_mode'        => $mode,
		);
	}

	/**
	 * Re-validate the immutable v2 shop binding stored on an attempt against
	 * the active native shop row and active-mode settings map.
	 *
	 * @param array $attempt Durable payment attempt row.
	 * @return array|WP_Error { shop_slug, square_location_id, square_mode }.
	 */
	private static function attempt_shop_binding( array $attempt ) {
		$metadata          = self::attempt_metadata( $attempt );
		$saved_shop        = isset( $metadata['square_shop_slug'] ) && is_string( $metadata['square_shop_slug'] ) ? $metadata['square_shop_slug'] : '';
		$saved_square_loc  = isset( $metadata['square_location_id'] ) && is_string( $metadata['square_location_id'] ) ? $metadata['square_location_id'] : '';
		$saved_mode        = isset( $metadata['square_mode'] ) && is_string( $metadata['square_mode'] ) ? $metadata['square_mode'] : '';
		$native_location_id = isset( $attempt['location_id'] ) ? absint( $attempt['location_id'] ) : 0;

		if (
			! $native_location_id
			|| ! in_array( $saved_shop, array( 'revesby', 'bankstown', 'roselands' ), true )
			|| ! preg_match( '/^[A-Za-z0-9_-]{4,64}$/', $saved_square_loc )
			|| ! in_array( $saved_mode, array( 'test', 'live' ), true )
		) {
			return self::shop_binding_error();
		}

		$current = self::shop_binding( $native_location_id );
		if (
			is_wp_error( $current )
			|| ! hash_equals( $saved_shop, (string) $current['shop_slug'] )
			|| ! hash_equals( $saved_square_loc, (string) $current['square_location_id'] )
			|| ! hash_equals( $saved_mode, (string) $current['square_mode'] )
		) {
			return self::shop_binding_error();
		}

		return $current;
	}

	/**
	 * Decode attempt metadata without accepting a malformed durable value.
	 *
	 * @param array $attempt Durable payment attempt row.
	 * @return array
	 */
	private static function attempt_metadata( array $attempt ) {
		$metadata = isset( $attempt['safe_metadata_json'] ) ? json_decode( (string) $attempt['safe_metadata_json'], true ) : array();
		return is_array( $metadata ) ? $metadata : array();
	}

	/** 0 means historical payment-only; 1 means itemized; -1 must be held. */
	private static function orders_protocol( array $attempt ) {
		$metadata = self::attempt_metadata( $attempt );
		if ( ! array_key_exists( 'orders_protocol', $metadata ) && ! array_key_exists( 'orders_snapshot_hash', $metadata ) ) {
			return empty( $attempt['provider_order_reference'] ) ? 0 : -1;
		}
		return isset( $metadata['orders_protocol'], $metadata['orders_snapshot_hash'] )
			&& 'square-orders-v1' === $metadata['orders_protocol'] && is_string( $metadata['orders_snapshot_hash'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}\\z/', $metadata['orders_snapshot_hash'] ) ? 1 : -1;
	}

	/** Load only the retained exact snapshot; never reprice from the current menu. */
	private static function order_projection( array $attempt, array $binding ) {
		$metadata = self::attempt_metadata( $attempt );
		$snapshot = DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( $attempt['checkout_key'] );
		if ( 1 !== self::orders_protocol( $attempt ) || ! is_array( $snapshot )
			|| ! isset( $snapshot['payload'], $snapshot['payload']['order']['location_id'], $snapshot['payload']['binding_hash'] )
			|| 'prepared' !== $snapshot['status'] || 0 !== (int) $snapshot['order_id']
			|| ! hash_equals( $metadata['orders_snapshot_hash'], (string) $snapshot['payload_hash'] )
			|| ! hash_equals( (string) $metadata['binding_hash'], (string) $snapshot['payload']['binding_hash'] )
			|| (string) $snapshot['payload']['order']['location_id'] !== (string) $attempt['location_id'] ) {
			return self::pending_error( $attempt, 'doughboss_pay_order_snapshot' );
		}
		$projection = DoughBoss_Square_Orders::project( $snapshot['payload'], $binding, (int) $attempt['id'] );
		if ( is_wp_error( $projection ) || $projection['amounts']['total'] !== (int) $attempt['amount_minor']
			|| $projection['currency'] !== (string) $attempt['currency'] ) {
			return self::pending_error( $attempt, 'doughboss_pay_order_snapshot' );
		}
		return $projection;
	}

	/** Compound dispatch owner creates and binds once. No automatic POST recovery. */
	private static function create_linked_order( array $attempt, array $binding ) {
		// Web claims update the database, not the caller's prepared array. Read
		// the committed claim and verify every immutable fact before any HTTP.
		$fresh = DoughBoss_Payment_Attempts::find( (int) $attempt['id'] );
		if ( ! is_array( $fresh ) ) { return self::pending_error( $attempt, 'doughboss_pay_order_dispatch' ); }
		foreach ( array( 'id', 'attempt_key', 'checkout_key', 'provider', 'purpose', 'context', 'local_reference', 'location_id', 'table_id', 'qr_code_id', 'amount_minor', 'currency', 'safe_metadata_json' ) as $field ) {
			if ( ! array_key_exists( $field, $attempt ) || ! array_key_exists( $field, $fresh ) || $attempt[ $field ] !== $fresh[ $field ] ) {
				return self::pending_error( $attempt, 'doughboss_pay_order_dispatch' );
			}
		}
		$attempt = $fresh;
		if ( 'dispatching' !== $attempt['status'] || ! empty( $attempt['provider_reference'] ) || ! empty( $attempt['provider_order_reference'] ) ) {
			return self::pending_error( $attempt, 'doughboss_pay_order_dispatch' );
		}
		$projection = self::order_projection( $attempt, $binding );
		if ( is_wp_error( $projection ) ) { return $projection; }
		$response = self::request( 'POST', '/v2/orders', array(
			'idempotency_key' => self::idempotency_key( 'ord', $attempt['checkout_key'] ),
			'order' => $projection['order'],
		), $binding );
		if ( is_wp_error( $response ) ) { return $response; }
		$facts = DoughBoss_Square_Orders::validate_unpaid_response( $response, $projection );
		if ( is_wp_error( $facts ) ) { return $facts; }
		$bound = DoughBoss_Payment_Attempts::bind_square_order( (int) $attempt['id'], $facts['id'], $attempt );
		if ( ! is_array( $bound ) ) { return self::pending_error( $attempt, 'doughboss_pay_order_binding' ); }
		// Re-read provider facts after durable binding, then the local tuple/map.
		$current_binding = self::attempt_shop_binding( $bound );
		if ( is_wp_error( $current_binding ) ) { return $current_binding; }
		$projection = self::order_projection( $bound, $current_binding );
		if ( is_wp_error( $projection ) ) { return $projection; }
		$response = self::request( 'GET', '/v2/orders/' . rawurlencode( $facts['id'] ), array(), $current_binding );
		if ( is_wp_error( $response ) ) { return $response; }
		$checked = DoughBoss_Square_Orders::validate_unpaid_response( $response, $projection );
		if ( is_wp_error( $checked ) || $checked['id'] !== $facts['id'] ) {
			return self::pending_error( $bound, 'doughboss_pay_order_changed' );
		}
		$fresh = DoughBoss_Payment_Attempts::find( (int) $bound['id'] );
		if ( ! is_array( $fresh ) || 'dispatching' !== $fresh['status'] || null !== $fresh['provider_reference']
			|| $fresh['provider_order_reference'] !== $facts['id']
			|| $fresh['checkout_key'] !== $bound['checkout_key'] || $fresh['amount_minor'] !== $bound['amount_minor']
			|| $fresh['currency'] !== $bound['currency'] || $fresh['location_id'] !== $bound['location_id']
			|| $fresh['safe_metadata_json'] !== $bound['safe_metadata_json'] ) {
			return self::pending_error( $bound, 'doughboss_pay_order_changed' );
		}
		$binding = self::attempt_shop_binding( $fresh );
		if ( is_wp_error( $binding ) ) { return $binding; }
		$projection = self::order_projection( $fresh, $binding );
		return is_wp_error( $projection ) ? $projection : $fresh;
	}

	/** Payment identity must refer to exactly the separately bound provider Order. */
	private static function payment_order_matches( array $payment, array $attempt ) {
		$protocol = self::orders_protocol( $attempt );
		if ( 0 === $protocol ) { return true; }
		return 1 === $protocol && isset( $attempt['provider_order_reference'], $payment['order_id'] )
			&& is_string( $attempt['provider_order_reference'] )
			&& 1 === preg_match( '/^[A-Za-z0-9_-]{8,191}\\z/', $attempt['provider_order_reference'] )
			&& $attempt['provider_order_reference'] === $payment['order_id'];
	}

	/** Paid-but-drifted/unreadable orders stay paid and held, never recharged. */
	private static function verify_linked_order( array $payment, array $attempt, array $binding ) {
		if ( ! self::payment_order_matches( $payment, $attempt ) ) {
			return self::pending_error( $attempt, 'doughboss_pay_order_mismatch' );
		}
		if ( 0 === self::orders_protocol( $attempt ) || 'order_committed' === $attempt['status'] ) {
			// Existing committed receipts retain their snapshot-purge compatibility.
			return true;
		}
		$projection = self::order_projection( $attempt, $binding );
		if ( is_wp_error( $projection ) ) { return $projection; }
		$response = self::request( 'GET', '/v2/orders/' . rawurlencode( $attempt['provider_order_reference'] ), array(), $binding );
		if ( is_wp_error( $response ) ) { return self::pending_error( $attempt, 'doughboss_pay_order_unavailable' ); }
		$facts = DoughBoss_Square_Orders::validate_paid_response( $response, $projection, $payment, $attempt['provider_order_reference'] );
		if ( is_wp_error( $facts ) ) { return self::pending_error( $attempt, 'doughboss_pay_order_changed' ); }
		$fresh = DoughBoss_Payment_Attempts::find( (int) $attempt['id'] );
		if ( ! is_array( $fresh ) || ! in_array( $fresh['status'], array( 'processing', 'unknown', 'succeeded', 'order_committed' ), true )
			|| $fresh['provider_reference'] !== $payment['id'] || $fresh['provider_order_reference'] !== $attempt['provider_order_reference']
			|| $fresh['checkout_key'] !== $attempt['checkout_key'] || $fresh['amount_minor'] !== $attempt['amount_minor']
			|| $fresh['currency'] !== $attempt['currency'] || $fresh['location_id'] !== $attempt['location_id']
			|| $fresh['safe_metadata_json'] !== $attempt['safe_metadata_json'] ) {
			return self::pending_error( $attempt, 'doughboss_pay_order_changed' );
		}
		$current_binding = self::attempt_shop_binding( $fresh );
		if ( is_wp_error( $current_binding ) ) { return $current_binding; }
		if ( 'order_committed' === $fresh['status'] ) { return true; }
		$fresh_projection = self::order_projection( $fresh, $current_binding );
		return is_wp_error( $fresh_projection ) ? $fresh_projection : true;
	}

	/** Failed linked checks must not leave a prior cached release authority. */
	private static function held_order_error( $error, array $attempt ) {
		self::forget_approved_release_attestation( isset( $attempt['provider_reference'] ) ? $attempt['provider_reference'] : '' );
		if ( 1 === self::orders_protocol( $attempt ) && ! empty( $attempt['provider_reference'] )
			&& ! DoughBoss_Payment_Attempts::hold_square_order_verification( $attempt ) ) {
			return self::pending_error( $attempt, 'doughboss_pay_order_hold_storage' );
		}
		return $error;
	}

	/** Exact server-observed facts that a NEW marked approved release must retain. */
	private static function approved_release_tuple( array $attempt ) {
		if ( 1 !== self::orders_protocol( $attempt ) || 'square' !== $attempt['provider']
			|| 'order' !== $attempt['purpose'] || 'approved_preorder' !== $attempt['context']
			|| 'succeeded' !== $attempt['status'] || 'COMPLETED' !== $attempt['provider_status']
			|| empty( $attempt['verified_at'] ) || empty( $attempt['provider_reference'] ) || empty( $attempt['provider_order_reference'] ) ) {
			return null;
		}
		$tuple = array();
		foreach ( array( 'id', 'attempt_key', 'checkout_key', 'provider', 'purpose', 'context', 'local_reference', 'location_id', 'table_id', 'qr_code_id', 'amount_minor', 'currency', 'provider_reference', 'provider_order_reference', 'provider_status', 'status', 'safe_metadata_json', 'verified_at' ) as $field ) {
			if ( ! isset( $attempt[ $field ] ) || ! is_scalar( $attempt[ $field ] ) ) { return null; }
			$tuple[ $field ] = (string) $attempt[ $field ];
		}
		return $tuple;
	}

	/** Any later verification attempt invalidates earlier in-process authority. */
	private static function forget_approved_release_attestation( $reference ) {
		foreach ( self::$approved_release_attestations as $id => $tuple ) {
			if ( $tuple['provider_reference'] === $reference ) { unset( self::$approved_release_attestations[ $id ] ); }
		}
	}

	/**
	 * Consume only authority minted by successful server-side retrieval in THIS
	 * request. Called with the current locked row, after all approval/snapshot
	 * checks; never with browser metadata. Historical payment-only rows keep
	 * their existing contract. Receipt replay never enters this new-release path.
	 *
	 * @param array $attempt Current locking-read attempt.
	 * @return bool
	 */
	public static function consume_approved_release_attestation( array $attempt ) {
		$protocol = self::orders_protocol( $attempt );
		if ( 0 === $protocol ) { return true; }
		$id = isset( $attempt['id'] ) ? (int) $attempt['id'] : 0;
		$saved = isset( self::$approved_release_attestations[ $id ] ) ? self::$approved_release_attestations[ $id ] : null;
		unset( self::$approved_release_attestations[ $id ] );
		$current = self::approved_release_tuple( $attempt );
		return 1 === $protocol && is_array( $saved ) && is_array( $current ) && $saved === $current;
	}

	/**
	 * Generic, fail-closed error for native-shop and provider-map drift.
	 *
	 * @return WP_Error
	 */
	private static function shop_binding_error() {
		return new WP_Error( 'doughboss_pay_shop_binding', __( 'Card payments are unavailable for this shop configuration. Please contact the shop before trying again.', 'doughboss' ), array( 'status' => 409 ) );
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
		global $wpdb;
		$amount_minor = absint( $amount_minor );
		$currency     = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $currency ) );
		$checkout_key = isset( $metadata['checkout_key'] ) ? strtolower( sanitize_text_field( (string) $metadata['checkout_key'] ) ) : '';
		$location_id  = isset( $metadata['location_id'] ) ? absint( $metadata['location_id'] ) : 0;
		$table_id     = isset( $metadata['table_id'] ) ? absint( $metadata['table_id'] ) : 0;
		$qr_code_id   = isset( $metadata['qr_code_id'] ) ? absint( $metadata['qr_code_id'] ) : 0;
		$source_id    = isset( $metadata['source_id'] ) ? sanitize_text_field( (string) $metadata['source_id'] ) : '';
		$verification = isset( $metadata['verification_token'] ) ? sanitize_text_field( (string) $metadata['verification_token'] ) : '';
		$protocol     = isset( $metadata['protocol_version'] ) ? sanitize_key( (string) $metadata['protocol_version'] ) : '';
		$attempt_identity = isset( $metadata['attempt_identity'] ) ? strtolower( sanitize_text_field( (string) $metadata['attempt_identity'] ) ) : '';
		$binding_hash = isset( $metadata['binding_hash'] ) ? strtolower( sanitize_text_field( (string) $metadata['binding_hash'] ) ) : '';
		$cart_guard   = isset( $metadata['cart_guard'] ) ? strtolower( sanitize_text_field( (string) $metadata['cart_guard'] ) ) : '';
		$voucher_code = isset( $metadata['voucher_code'] ) ? sanitize_text_field( (string) $metadata['voucher_code'] ) : '';
		$voucher_key  = isset( $metadata['voucher_reservation_key'] ) ? strtolower( sanitize_text_field( (string) $metadata['voucher_reservation_key'] ) ) : '';
		$purpose      = isset( $metadata['purpose'] ) ? substr( sanitize_key( (string) $metadata['purpose'] ), 0, 32 ) : 'order';
		$context      = isset( $metadata['context'] ) ? substr( sanitize_key( (string) $metadata['context'] ), 0, 32 ) : ( $table_id ? 'table_qr' : 'web' );
		$preorder_token = isset( $metadata['preorder_payment_token'] ) ? trim( (string) $metadata['preorder_payment_token'] ) : '';

		if (
			$amount_minor < 1
			|| 3 !== strlen( $currency )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $checkout_key )
			|| ! $location_id
			|| 'square-v2' !== $protocol
			|| ! preg_match( '/^[a-f0-9]{64}$/', $attempt_identity )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $binding_hash )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $cart_guard )
			|| ( '' === $voucher_code && '' !== $voucher_key )
			|| ( '' !== $voucher_code && ! preg_match( '/^[a-f0-9]{64}$/', $voucher_key ) )
		) {
			return new WP_Error( 'doughboss_pay_request', __( 'The payment request is incomplete.', 'doughboss' ), array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_:.-]{8,1024}$/', $source_id ) ) {
			return new WP_Error( 'doughboss_pay_source', __( 'The card details could not be read securely. Please re-enter your card and try again.', 'doughboss' ), array( 'status' => 400, 'retry_safe' => true, 'payment_pending' => false ) );
		}
		if ( '' !== $verification && ! preg_match( '/^[A-Za-z0-9_:.-]{8,1024}$/', $verification ) ) {
			return new WP_Error( 'doughboss_pay_source', __( 'The card verification could not be read securely. Please try again.', 'doughboss' ), array( 'status' => 400, 'retry_safe' => true, 'payment_pending' => false ) );
		}
		$shop_binding = self::shop_binding( $location_id );
		if ( is_wp_error( $shop_binding ) ) {
			return $shop_binding;
		}

		// The single-use card token and 3DS evidence must never reach durable
		// storage or a log line — strip them before the attempt row is written.
		$safe_metadata = $metadata;
		unset(
			$safe_metadata['attempt_key'],
			$safe_metadata['checkout_key'],
			$safe_metadata['source_id'],
			$safe_metadata['verification_token'],
			$safe_metadata['preorder_payment_token'],
			$safe_metadata['orders_protocol'],
			$safe_metadata['orders_snapshot_hash']
		);
		if ( 'approved_preorder' === $context ) {
			// Payment attempts deliberately discard metadata keys containing
			// "source" or "request". Preserve the server-owned approval tuple
			// under neutral durable names, while keeping the browser-path names
			// transient and out of storage.
			unset( $safe_metadata['source_request_id'], $safe_metadata['request_fingerprint'] );
			$safe_metadata['approval_id']          = isset( $metadata['source_request_id'] ) ? absint( $metadata['source_request_id'] ) : 0;
			$safe_metadata['approval_fingerprint'] = isset( $metadata['request_fingerprint'] ) ? strtolower( sanitize_text_field( (string) $metadata['request_fingerprint'] ) ) : '';
		}
		$safe_metadata['square_shop_slug']   = $shop_binding['shop_slug'];
		$safe_metadata['square_location_id'] = $shop_binding['square_location_id'];
		$safe_metadata['square_mode']        = $shop_binding['square_mode'];

		$attempt_key = hash( 'sha256', 'square-v2|' . $attempt_identity );
		if ( ! DoughBoss_Payment_Attempts::acquire_local_reference_lock( $cart_guard ) ) {
			return new WP_Error( 'doughboss_pay_busy', __( 'This cart payment is busy. Please wait a moment and try again.', 'doughboss' ), array( 'status' => 503 ) );
		}
		try {
			$legacy = DoughBoss_Payment_Attempts::find_blocking_legacy_square();
			if ( is_wp_error( $legacy ) ) {
				return $legacy;
			}
			if ( $legacy ) {
				return new WP_Error(
					'doughboss_pay_legacy_pending',
					__( 'Card payments are temporarily unavailable while an earlier Square payment is reconciled. Please contact the shop before paying again.', 'doughboss' ),
					array( 'status' => 409, 'payment_pending' => true, 'retry_safe' => false )
				);
			}
			$blocking = DoughBoss_Payment_Attempts::find_blocking_by_local_reference( 'square', $cart_guard, $attempt_key );
			if ( is_wp_error( $blocking ) ) {
				return $blocking;
			}
			if ( $blocking ) {
				return self::pending_error( $blocking, 'doughboss_pay_cart_pending' );
			}
			// Existing rows, including prepared unmarked v2 retries, keep their
			// original protocol. A failed lookup must never select new allocation.
			$attempt = DoughBoss_Payment_Attempts::find_by_attempt_key( $attempt_key );
			if ( '' !== (string) $wpdb->last_error ) {
				return new WP_Error( 'doughboss_pay_storage', __( 'The payment identity could not be checked safely.', 'doughboss' ), array( 'status' => 503 ) );
			}
			if ( ! $attempt ) {
				$attempt = DoughBoss_Payment_Attempts::find_by_checkout_key( $checkout_key );
				if ( '' !== (string) $wpdb->last_error ) {
					return new WP_Error( 'doughboss_pay_storage', __( 'The payment identity could not be checked safely.', 'doughboss' ), array( 'status' => 503 ) );
				}
			}
			if ( ! $attempt ) {
				if ( ! DoughBoss_Activator::payment_storage_ready() ) {
					return new WP_Error( 'doughboss_pay_storage', __( 'The payment storage is not ready.', 'doughboss' ), array( 'status' => 503 ) );
				}
				$snapshot = DoughBoss_Checkout_Snapshots::find( $checkout_key );
				if ( '' !== (string) $wpdb->last_error || ! is_array( $snapshot )
					|| 'prepared' !== $snapshot['status'] || 0 !== (int) $snapshot['order_id']
					|| ! isset( $snapshot['payload']['binding_hash'], $snapshot['payload']['order']['location_id'] )
					|| ! is_string( $snapshot['payload']['binding_hash'] )
					|| ! hash_equals( $binding_hash, $snapshot['payload']['binding_hash'] )
					|| (string) $location_id !== (string) $snapshot['payload']['order']['location_id'] ) {
					return new WP_Error( 'doughboss_pay_order_snapshot', __( 'The saved itemized order could not be verified. Please contact the shop.', 'doughboss' ), array( 'status' => 409 ) );
				}
				// This pure preflight uses a provisional reference only to validate
				// economics. No provider sees it; dispatch projects the persisted ID.
				$projection = DoughBoss_Square_Orders::project( $snapshot['payload'], $shop_binding, 1 );
				if ( is_wp_error( $projection ) || $projection['amounts']['total'] !== $amount_minor || $projection['currency'] !== $currency ) {
					return new WP_Error( 'doughboss_pay_order_snapshot', __( 'The saved itemized order could not be verified. Please contact the shop.', 'doughboss' ), array( 'status' => 409 ) );
				}
				$safe_metadata['orders_protocol'] = 'square-orders-v1';
				$safe_metadata['orders_snapshot_hash'] = $snapshot['payload_hash'];
				$attempt = DoughBoss_Payment_Attempts::create_or_find( array(
				'attempt_key'     => $attempt_key,
				'checkout_key'    => $checkout_key,
				'provider'        => 'square',
				'purpose'         => $purpose,
				'context'         => $context,
				'local_reference' => $cart_guard,
				'location_id'     => $location_id,
				'table_id'        => $table_id,
				'qr_code_id'      => $qr_code_id,
				'amount_minor'    => $amount_minor,
				'currency'        => $currency,
				'status'          => 'prepared',
				'safe_metadata'   => $safe_metadata,
				) );
				// A concurrent winner cannot silently downgrade a new checkout to
				// payment-only. Never append markers to that winner or replace it.
				$stored = is_array( $attempt ) ? self::attempt_metadata( $attempt ) : array();
				if ( ! is_array( $attempt ) || 1 !== self::orders_protocol( $attempt )
					|| $safe_metadata['orders_snapshot_hash'] !== $stored['orders_snapshot_hash'] ) {
					return new WP_Error( 'doughboss_pay_order_protocol', __( 'The itemized payment identity could not be recorded safely.', 'doughboss' ), array( 'status' => 503 ) );
				}
			}
		} finally {
			DoughBoss_Payment_Attempts::release_local_reference_lock( $cart_guard );
		}
		if ( ! $attempt ) {
			return new WP_Error( 'doughboss_pay_storage', __( 'The payment could not be recorded safely.', 'doughboss' ), array( 'status' => 503 ) );
		}
		if (
			'square' !== (string) $attempt['provider']
			|| (int) $attempt['amount_minor'] !== $amount_minor
			|| strtoupper( (string) $attempt['currency'] ) !== $currency
			|| ! hash_equals( (string) $attempt['attempt_key'], $attempt_key )
			|| ! hash_equals( (string) $attempt['checkout_key'], $checkout_key )
			|| ! hash_equals( (string) $attempt['purpose'], $purpose )
			|| ! hash_equals( (string) $attempt['context'], $context )
			|| ! hash_equals( (string) $attempt['local_reference'], $cart_guard )
			|| (int) $attempt['location_id'] !== $location_id
			|| (int) $attempt['table_id'] !== $table_id
			|| (int) $attempt['qr_code_id'] !== $qr_code_id
		) {
			return new WP_Error( 'doughboss_pay_attempt_changed', __( 'Your order changed while payment was being prepared. Please start payment again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		// Choose the dispatch path only from the persisted immutable attempt
		// after the normalized caller input has matched it exactly.
		$purpose         = (string) $attempt['purpose'];
		$context         = (string) $attempt['context'];
		$stored_metadata = self::attempt_metadata( $attempt );
		$stored_binding  = self::attempt_shop_binding( $attempt );
		if (
			'square-v2' !== ( isset( $stored_metadata['protocol_version'] ) ? (string) $stored_metadata['protocol_version'] : '' )
			|| empty( $stored_metadata['binding_hash'] )
			|| ! hash_equals( $binding_hash, (string) $stored_metadata['binding_hash'] )
			|| is_wp_error( $stored_binding )
		) {
			return new WP_Error( 'doughboss_pay_legacy_locked', __( 'This payment attempt cannot be retried automatically. Please contact the shop before paying again.', 'doughboss' ), array( 'status' => 409, 'payment_pending' => true, 'attempt_id' => (int) $attempt['id'] ) );
		}

		// This checkout already produced a Square payment. Never charge again —
		// re-read the existing one and let the caller verify it.
		$existing_reference = isset( $attempt['provider_reference'] ) ? (string) $attempt['provider_reference'] : '';
		if ( '' !== $existing_reference ) {
			return self::replay( $existing_reference, $attempt, $amount_minor, $currency );
		}
		$orders_protocol = self::orders_protocol( $attempt );
		if ( -1 === $orders_protocol ) {
			return self::pending_error( $attempt, 'doughboss_pay_order_protocol' );
		}
		if ( 1 === $orders_protocol ) {
			$projection = self::order_projection( $attempt, $stored_binding );
			if ( is_wp_error( $projection ) ) { return $projection; }
		}

		if ( 'approved_preorder' === $context ) {
			$request_id = isset( $metadata['source_request_id'] ) ? absint( $metadata['source_request_id'] ) : 0;
			if ( ! $request_id || '' === $preorder_token || ! class_exists( 'DoughBoss_Preorder_Approval' ) ) {
				return new WP_Error( 'doughboss_preorder_payment_token_invalid', __( 'A valid pre-order payment capability is required.', 'doughboss' ), array( 'status' => 403 ) );
			}
			$claimed = DoughBoss_Preorder_Approval::claim_square_dispatch( $request_id, $preorder_token, (int) $attempt['id'] );
			if ( is_wp_error( $claimed ) ) {
				return $claimed;
			}
			$attempt = isset( $claimed['attempt'] ) && is_array( $claimed['attempt'] ) ? $claimed['attempt'] : $attempt;
		} elseif ( '' !== $voucher_code ) {
			$claimed = DoughBoss_Voucher::claim_payment_dispatch( $voucher_code, $voucher_key, (int) $attempt['id'] );
		} else {
			$claimed = DoughBoss_Payment_Attempts::claim_irreversible_creation( (int) $attempt['id'] );
		}
		if ( isset( $claimed ) && is_wp_error( $claimed ) ) {
			return $claimed;
		}
		if ( 'approved_preorder' !== $context && true !== $claimed ) {
			$attempt = DoughBoss_Payment_Attempts::find( (int) $attempt['id'] );
			if ( $attempt && ! empty( $attempt['provider_reference'] ) ) {
				return self::replay( (string) $attempt['provider_reference'], $attempt, $amount_minor, $currency );
			}
			return self::pending_error( $attempt, 'doughboss_pay_provisioning' );
		}

		// Re-read the native shop and active-mode map immediately before the
		// irreversible provider dispatch. A drift remains blocked rather than
		// ever risking a second charge under another shop's provider location.
		$dispatch_binding = self::attempt_shop_binding( $attempt );
		if ( is_wp_error( $dispatch_binding ) ) {
			DoughBoss_Payment_Attempts::mark_irreversible_unknown( (int) $attempt['id'], $dispatch_binding->get_error_code() );
			return self::pending_error( DoughBoss_Payment_Attempts::find( (int) $attempt['id'] ), $dispatch_binding->get_error_code() );
		}
		$orders_protocol = self::orders_protocol( $attempt );
		if ( -1 === $orders_protocol ) {
			DoughBoss_Payment_Attempts::mark_irreversible_unknown( (int) $attempt['id'], 'doughboss_pay_order_protocol' );
			return self::pending_error( $attempt, 'doughboss_pay_order_protocol' );
		}
		if ( 1 === $orders_protocol ) {
			$linked = self::create_linked_order( $attempt, $dispatch_binding );
			if ( is_wp_error( $linked ) ) {
				DoughBoss_Payment_Attempts::mark_irreversible_unknown( (int) $attempt['id'], $linked->get_error_code() );
				return self::pending_error( DoughBoss_Payment_Attempts::find( (int) $attempt['id'] ), $linked->get_error_code() );
			}
			$attempt = $linked;
			$dispatch_binding = self::attempt_shop_binding( $attempt );
			if ( is_wp_error( $dispatch_binding ) ) {
				DoughBoss_Payment_Attempts::mark_irreversible_unknown( (int) $attempt['id'], $dispatch_binding->get_error_code() );
				return self::pending_error( $attempt, $dispatch_binding->get_error_code() );
			}
			if ( 'approved_preorder' === $context ) {
				// The Order round trips are not permission to charge. Recheck the
				// exact approval capability and source request after those waits.
				$approval = DoughBoss_Preorder_Approval::verify_payment_token( $request_id, $preorder_token, true );
				$request = DoughBoss_Order::get( $request_id );
				$stored = self::attempt_metadata( $attempt );
				if ( is_wp_error( $approval ) || ! is_array( $approval ) || ! $request
					|| 'payment_active' !== $approval['state'] || (int) $approval['payment_attempt_id'] !== (int) $attempt['id']
					|| $approval['payment_checkout_key'] !== $attempt['checkout_key']
					|| (int) $approval['approved_revision'] !== (int) $request->version
					|| 'preorder_request' !== $request->order_source || 'unpaid' !== $request->payment_status
					|| (int) $request->location_id !== (int) $attempt['location_id']
					|| ! isset( $stored['approval_id'], $stored['approved_revision'], $stored['approval_fingerprint'] )
					|| (int) $stored['approval_id'] !== $request_id
					|| (int) $stored['approved_revision'] !== (int) $approval['approved_revision']
					|| $stored['approval_fingerprint'] !== $approval['request_fingerprint'] ) {
					DoughBoss_Payment_Attempts::mark_irreversible_unknown( (int) $attempt['id'], 'doughboss_pay_order_approval_changed' );
					return self::pending_error( $attempt, 'doughboss_pay_order_approval_changed' );
				}
			}
		}

		$body = array(
			'idempotency_key' => self::idempotency_key( 'pay', $checkout_key ),
			'source_id'       => $source_id,
			'amount_money'    => array(
				'amount'   => $amount_minor,
				'currency' => $currency,
			),
			'location_id'     => $dispatch_binding['square_location_id'],
			'reference_id'    => self::attempt_reference( (int) $attempt['id'] ),
			'autocomplete'    => true,
		);
		if ( 1 === $orders_protocol ) {
			$body['order_id'] = $attempt['provider_order_reference'];
		}
		if ( '' !== $verification ) {
			$body['verification_token'] = $verification;
		}

		$response = self::request( 'POST', '/v2/payments', $body, $dispatch_binding );
		if ( is_wp_error( $response ) ) {
			DoughBoss_Payment_Attempts::mark_irreversible_unknown( (int) $attempt['id'], $response->get_error_code() );
			return self::pending_error( DoughBoss_Payment_Attempts::find( (int) $attempt['id'] ), $response->get_error_code() );
		}

		$payment = isset( $response['payment'] ) && is_array( $response['payment'] ) ? $response['payment'] : array();
		$payload = self::payment_payload( $payment, $attempt, $amount_minor, $currency );
		if ( is_wp_error( $payload ) ) {
			$reference       = isset( $payment['id'] ) ? self::canonical_id( $payment['id'] ) : '';
			$provider_status = isset( $payment['status'] ) ? strtoupper( sanitize_text_field( (string) $payment['status'] ) ) : '';
			$bound           = '' !== $reference
				? DoughBoss_Payment_Attempts::bind_irreversible_reference( (int) $attempt['id'], $reference, 'mismatch', $provider_status )
				: false;
			if ( ! $bound ) {
				DoughBoss_Payment_Attempts::mark_irreversible_unknown( (int) $attempt['id'], $payload->get_error_code() );
			}
			return self::pending_error( $bound ? $bound : DoughBoss_Payment_Attempts::find( (int) $attempt['id'] ), $payload->get_error_code(), $reference );
		}

		$bound = DoughBoss_Payment_Attempts::bind_irreversible_reference(
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
				DoughBoss_Payment_Attempts::mark_irreversible_unknown( (int) $attempt['id'], 'doughboss_pay_binding' );
				return self::pending_error( DoughBoss_Payment_Attempts::find( (int) $attempt['id'] ), 'doughboss_pay_binding', (string) $payload['id'] );
			}
		}

		$payload['attempt_id'] = (int) $bound['id'];
		if ( 1 === $orders_protocol && 'succeeded' === $payload['status'] ) {
			$verified = self::verify_linked_order( $payment, $bound, $dispatch_binding );
			if ( is_wp_error( $verified ) ) { return self::held_order_error( $verified, $bound ); }
		}
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
		$shop_binding = self::attempt_shop_binding( $attempt );
		if ( is_wp_error( $shop_binding ) ) {
			return $shop_binding;
		}
		if ( 'mismatch' === (string) $attempt['status'] ) {
			return self::pending_error( $attempt, 'doughboss_pay_mismatch', $reference );
		}
		if ( in_array( (string) $attempt['status'], array( 'failed', 'voided' ), true ) ) {
			return array(
				'id'         => $reference,
				'status'     => (string) $attempt['status'],
				'amount'     => (int) $attempt['amount_minor'],
				'currency'   => strtolower( (string) $attempt['currency'] ),
				'attempt_id' => (int) $attempt['id'],
			);
		}
		$response = self::retrieve_mapped_payment( $reference, $shop_binding );
		if ( is_wp_error( $response ) ) {
			DoughBoss_Payment_Attempts::mark_irreversible_unknown( (int) $attempt['id'], $response->get_error_code() );
			return self::pending_error( DoughBoss_Payment_Attempts::find( (int) $attempt['id'] ), $response->get_error_code(), $reference );
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
		if ( 'succeeded' === $payload['status'] ) {
			$verified = self::verify_linked_order( $response, $attempt, $shop_binding );
			if ( is_wp_error( $verified ) ) { return self::held_order_error( $verified, $attempt ); }
		}
		return $payload;
	}

	/**
	 * Return a structured, fail-closed Square outcome without exposing card data.
	 *
	 * @param array|null $attempt   Durable attempt row.
	 * @param string     $code      Safe error code.
	 * @param string     $reference Optional known Square payment id.
	 * @return WP_Error
	 */
	private static function pending_error( $attempt, $code, $reference = '' ) {
		$attempt   = is_array( $attempt ) ? $attempt : array();
		$reference = self::canonical_id( '' !== $reference ? $reference : ( isset( $attempt['provider_reference'] ) ? $attempt['provider_reference'] : '' ) );
		$data      = array(
			'status'          => 409,
			'outcome'         => 'unknown',
			'payment_pending' => true,
			'retry_safe'      => false,
			'attempt_id'      => isset( $attempt['id'] ) ? (int) $attempt['id'] : 0,
		);
		if ( '' !== $reference ) {
			$data['payment_intent'] = $reference;
		}
		return new WP_Error( sanitize_key( $code ) ?: 'doughboss_pay_pending', __( 'Your payment may already be complete. Please do not pay again — contact the shop so we can confirm it for you.', 'doughboss' ), $data );
	}

	/**
	 * Fetch a raw Square payment object.
	 *
	 * @param string $id Square payment id.
	 * @return array|WP_Error The `payment` object, or an error.
	 */
	public static function retrieve_payment( $id ) {
		return self::retrieve_payment_with_binding( $id );
	}

	/**
	 * Retrieve a v2 payment with its already validated mapped shop binding.
	 *
	 * @param string $id           Square payment id.
	 * @param array  $shop_binding Validated v2 shop binding.
	 * @return array|WP_Error The `payment` object, or an error.
	 */
	private static function retrieve_mapped_payment( $id, array $shop_binding ) {
		return self::retrieve_payment_with_binding( $id, $shop_binding );
	}

	/**
	 * Shared payment retrieval implementation. A null binding deliberately
	 * retains the legacy global-location transport guard.
	 *
	 * @param string     $id           Square payment id.
	 * @param array|null $shop_binding Validated v2 shop binding, when present.
	 * @return array|WP_Error The `payment` object, or an error.
	 */
	private static function retrieve_payment_with_binding( $id, $shop_binding = null ) {
		$id = self::canonical_id( $id );
		if ( '' === $id ) {
			return new WP_Error( 'doughboss_pay_id', __( 'Invalid payment reference.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$response = self::request( 'GET', '/v2/payments/' . rawurlencode( $id ), array(), $shop_binding );
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
		self::forget_approved_release_attestation( $id );
		if ( '' === $id ) {
			return new WP_Error( 'doughboss_pay_id', __( 'Invalid payment reference.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $id );
		if ( ! $attempt ) {
			return new WP_Error( 'doughboss_pay_attempt', __( 'The payment attempt could not be reconciled.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$attempt_metadata = self::attempt_metadata( $attempt );
		$is_v2            = 'square-v2' === ( isset( $attempt_metadata['protocol_version'] ) ? (string) $attempt_metadata['protocol_version'] : '' );
		if ( $is_v2 && 'mismatch' === $attempt['status'] ) {
			return self::pending_error( $attempt, 'doughboss_pay_mismatch', $id );
		}
		$shop_binding     = null;
		if ( $is_v2 ) {
			// Preserve the established mode-drift contract before applying the
			// stricter native-shop/map binding checks below.
			if ( empty( $attempt_metadata['square_mode'] ) || ! hash_equals( self::mode(), (string) $attempt_metadata['square_mode'] ) ) {
				return new WP_Error( 'doughboss_pay_mode_changed', __( 'This payment cannot be verified with the shop\'s current Square configuration. Please contact the shop before trying again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			$shop_binding = self::attempt_shop_binding( $attempt );
			if ( is_wp_error( $shop_binding ) ) {
				return $shop_binding;
			}
		}

		$payment = $is_v2 ? self::retrieve_mapped_payment( $id, $shop_binding ) : self::retrieve_payment( $id );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		$provider_status = isset( $payment['status'] ) ? strtoupper( sanitize_text_field( (string) $payment['status'] ) ) : '';
		$status          = self::normalised_status( $provider_status );
		$money           = isset( $payment['amount_money'] ) && is_array( $payment['amount_money'] ) ? $payment['amount_money'] : array();
		$amount          = isset( $money['amount'] ) ? (int) $money['amount'] : -1;
		$money_currency  = isset( $money['currency'] ) ? strtoupper( sanitize_text_field( (string) $money['currency'] ) ) : '';
		$payment_loc     = isset( $payment['location_id'] ) ? sanitize_text_field( (string) $payment['location_id'] ) : '';

		$metadata          = $attempt_metadata;
		$expected_location = $is_v2 ? (string) $shop_binding['square_location_id'] : ( isset( $metadata['square_location_id'] ) ? (string) $metadata['square_location_id'] : '' );
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
			&& hash_equals( $expected_location, $payment_loc )
			&& self::payment_order_matches( $payment, $attempt );

		if ( ! $valid ) {
			if ( $is_v2 ) {
				DoughBoss_Payment_Attempts::reconcile_irreversible_reference( $id, 'mismatch', $provider_status );
			} else {
				DoughBoss_Payment_Attempts::update( (int) $attempt['id'], array( 'status' => 'mismatch', 'provider_status' => $provider_status, 'last_error' => 'provider_binding_mismatch' ) );
			}
			return new WP_Error( 'doughboss_pay_mismatch', __( 'The payment did not match this order.', 'doughboss' ), array( 'status' => 409 ) );
		}

		if ( $is_v2 ) {
			// Do not persist verified_at until both payment and linked Order facts
			// pass. Otherwise a failed Order read could leave release authority.
			if ( 'succeeded' === $status ) {
				$verified = self::verify_linked_order( $payment, $attempt, $shop_binding );
				if ( is_wp_error( $verified ) ) { return self::held_order_error( $verified, $attempt ); }
			}
			$reconciled = DoughBoss_Payment_Attempts::reconcile_irreversible_reference( $id, $status, $provider_status );
			if ( false === $reconciled ) {
				return new WP_Error( 'doughboss_pay_reconcile', __( 'The payment state could not be reconciled safely.', 'doughboss' ), array( 'status' => 503 ) );
			}
			$durable_status = isset( $reconciled['status'] ) ? (string) $reconciled['status'] : '';
			if ( 'mismatch' === $durable_status ) {
				return new WP_Error( 'doughboss_pay_mismatch', __( 'The payment remains quarantined because it did not match this order.', 'doughboss' ), array( 'status' => 409 ) );
			}
			$status = 'order_committed' === $durable_status ? 'succeeded' : $durable_status;
			$release_tuple = self::approved_release_tuple( $reconciled );
			if ( is_array( $release_tuple ) ) {
				foreach ( $release_tuple as $field => $value ) {
					if ( in_array( $field, array( 'status', 'provider_status', 'verified_at' ), true ) ) { continue; }
					if ( ! isset( $attempt[ $field ] ) || (string) $attempt[ $field ] !== $value ) {
						return self::held_order_error( self::pending_error( $reconciled, 'doughboss_pay_order_changed' ), $reconciled );
					}
				}
				self::$approved_release_attestations[ (int) $reconciled['id'] ] = $release_tuple;
			}
		} else {
			// Preserve legacy webhook/manual-reconciliation inspection without
			// allowing legacy attempts to satisfy the v2 automatic checkout path.
			DoughBoss_Payment_Attempts::update(
				(int) $attempt['id'],
				array(
					'status'          => $status,
					'provider_status' => $provider_status,
					'verified_at'     => 'succeeded' === $status ? current_time( 'mysql', true ) : '',
				)
			);
		}

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
		$metadata = self::attempt_metadata( $attempt );
		$saved_shop = isset( $metadata['square_shop_slug'] ) && is_string( $metadata['square_shop_slug'] ) ? $metadata['square_shop_slug'] : '';
		$saved_location = isset( $metadata['square_location_id'] ) && is_string( $metadata['square_location_id'] ) ? $metadata['square_location_id'] : '';
		$saved_mode = isset( $metadata['square_mode'] ) && is_string( $metadata['square_mode'] ) ? $metadata['square_mode'] : '';
		$payment_location = isset( $payment['location_id'] ) && is_scalar( $payment['location_id'] ) ? (string) $payment['location_id'] : '';

		if (
			! self::payment_matches( $payment, $amount_minor, $currency, self::attempt_reference( isset( $attempt['id'] ) ? (int) $attempt['id'] : 0 ) )
			|| ! in_array( $saved_shop, array( 'revesby', 'bankstown', 'roselands' ), true )
			|| ! preg_match( '/^[A-Za-z0-9_-]{4,64}$/', $saved_location )
			|| ! in_array( $saved_mode, array( 'test', 'live' ), true )
			|| ! hash_equals( $saved_location, $payment_location )
			|| ! self::payment_order_matches( $payment, $attempt )
		) {
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
		if ( ! $attempt || 'square' !== (string) $attempt['provider'] ) {
			return new WP_Error( 'doughboss_pay_refund', __( 'This payment has no matching record to refund against.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$full     = (int) $attempt['amount_minor'];
		$currency = strtoupper( (string) $attempt['currency'] );
		$refund   = null === $amount_minor ? $full : (int) $amount_minor;
		if ( $refund < 1 || $refund > $full || 3 !== strlen( $currency ) ) {
			return new WP_Error( 'doughboss_pay_refund', __( 'Invalid refund amount.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$response = self::request(
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
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return self::validate_refund_response( $response, $id, $refund, $currency );
	}

	/**
	 * Only a completed, exactly bound refund may update the local paid state.
	 * HTTP success means Square accepted the request, not that funds were returned.
	 * Pending refunds keep the existing idempotency key and require reconciliation.
	 *
	 * @param array  $response     Decoded Square response.
	 * @param string $payment_id   Stored payment reference.
	 * @param int    $amount_minor Server-owned refund amount in cents.
	 * @param string $currency     Stored payment currency.
	 * @return array|WP_Error
	 */
	public static function validate_refund_response( array $response, $payment_id, $amount_minor, $currency ) {
		$refund = isset( $response['refund'] ) && is_array( $response['refund'] ) ? $response['refund'] : array();
		$money  = isset( $refund['amount_money'] ) && is_array( $refund['amount_money'] ) ? $refund['amount_money'] : array();
		if (
			! empty( $response['errors'] )
			|| ! isset( $refund['id'], $refund['payment_id'], $refund['status'], $money['amount'], $money['currency'] )
			|| ! is_string( $refund['id'] )
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]{8,192}$/', $refund['id'] )
			|| ! is_string( $refund['payment_id'] )
			|| ! hash_equals( (string) $payment_id, $refund['payment_id'] )
			|| ! is_string( $refund['status'] )
			|| ! is_int( $money['amount'] )
			|| $money['amount'] !== $amount_minor
			|| ! is_string( $money['currency'] )
			|| $money['currency'] !== $currency
		) {
			return new WP_Error( 'doughboss_refund_unverified', __( 'Square refund could not be verified. Check this payment in Square before taking further action; this order has not been marked refunded.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( 'PENDING' === $refund['status'] ) {
			return new WP_Error( 'doughboss_refund_pending', __( 'Square is still processing this refund. Check its status in Square; this order has not been marked refunded.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( 'COMPLETED' !== $refund['status'] ) {
			return new WP_Error( 'doughboss_refund_not_completed', __( 'Square has not completed this refund. Check its status in Square; this order has not been marked refunded.', 'doughboss' ), array( 'status' => 409 ) );
		}
		return $response;
	}

	/**
	 * Observe a Square-owned gift card without changing its balance or any order.
	 *
	 * This internal adapter has no public route. The native shop is a validated
	 * lookup context, not proof that a merchant-wide gift card belongs to a shop.
	 * A returned ACTIVE state/balance is an observation, never tender authority.
	 * GANs, linked customers and the raw provider payload are deliberately omitted.
	 *
	 * @param string $id Square-assigned gift card ID, not a GAN or browser nonce.
	 * @param int $location_id Exact server-owned native location ID.
	 * @return array|WP_Error Minimal provider observation, or a fail-closed error.
	 */
	public static function retrieve_gift_card( $id, $location_id ) {
		if ( ! self::valid_gift_card_id( $id ) || ! is_int( $location_id ) || $location_id <= 0 ) {
			return new WP_Error( 'doughboss_gift_card_input', __( 'Invalid gift card lookup reference.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$binding = self::shop_binding( $location_id );
		if ( is_wp_error( $binding ) ) {
			return $binding;
		}
		$currency = DoughBoss_Settings::get( 'currency_code', 'AUD' );
		$token    = self::access_token();
		if ( ! is_string( $currency ) || 1 !== preg_match( '/^[A-Z]{3}\z/', $currency ) ) {
			return new WP_Error( 'doughboss_gift_card_config', __( 'Gift card lookup is not configured.', 'doughboss' ), array( 'status' => 503 ) );
		}
		$response = self::request( 'GET', '/v2/gift-cards/' . rawurlencode( $id ), array(), $binding );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'doughboss_gift_card_unavailable', __( 'The gift card balance could not be checked. No gift card was changed.', 'doughboss' ), array( 'status' => 502 ) );
		}
		$current = self::shop_binding( $location_id );
		if ( is_wp_error( $current ) || $current !== $binding || ! hash_equals( $token, self::access_token() )
			|| $currency !== DoughBoss_Settings::get( 'currency_code', 'AUD' ) ) {
			return new WP_Error( 'doughboss_gift_card_context_changed', __( 'Gift card lookup configuration changed. Check the balance again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$observation = self::gift_card_observation( $response, $id, $currency );
		if ( is_wp_error( $observation ) ) {
			return $observation;
		}
		$observation['square_mode'] = $binding['square_mode'];
		return $observation;
	}

	/** Validate only a bounded provider ID; never normalize untrusted characters. */
	private static function valid_gift_card_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^gftc:[A-Za-z0-9_-]{1,128}\z/', $id );
	}

	/** Validate and minimize a provider balance response (integer cents only). */
	private static function gift_card_observation( array $response, $id, $currency ) {
		$card  = isset( $response['gift_card'] ) && is_array( $response['gift_card'] ) ? $response['gift_card'] : array();
		$money = isset( $card['balance_money'] ) && is_array( $card['balance_money'] ) ? $card['balance_money'] : array();
		if ( ( isset( $response['errors'] ) && ( ! is_array( $response['errors'] ) || ! empty( $response['errors'] ) ) )
			|| ! self::valid_gift_card_id( $id )
			|| ! isset( $card['id'], $card['type'], $card['state'], $money['amount'], $money['currency'] )
			|| ! is_string( $card['id'] ) || ! hash_equals( $id, $card['id'] )
			|| ! in_array( $card['type'], array( 'DIGITAL', 'PHYSICAL' ), true )
			|| ! in_array( $card['state'], array( 'ACTIVE', 'DEACTIVATED', 'BLOCKED', 'PENDING' ), true )
			|| ! is_int( $money['amount'] ) || $money['amount'] < 0 || $money['amount'] > 9007199254740991
			|| ! is_string( $currency ) || 1 !== preg_match( '/^[A-Z]{3}\z/', $currency )
			|| ! is_string( $money['currency'] ) || ! hash_equals( $currency, $money['currency'] ) ) {
			return new WP_Error( 'doughboss_gift_card_response', __( 'The gift card balance response could not be verified.', 'doughboss' ), array( 'status' => 502 ) );
		}
		return array( 'id' => $card['id'], 'type' => $card['type'], 'state' => $card['state'], 'balance_minor' => $money['amount'], 'currency' => $money['currency'] );
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
	 * @param array      $body         JSON body for write calls.
	 * @param array|null $shop_binding Validated v2 mapping context, when present.
	 * @return array|WP_Error Decoded JSON, or an error.
	 */
	private static function request( $method, $path, array $body = array(), $shop_binding = null ) {
		if (
			! DoughBoss_Settings::square_access_token_valid()
			|| ( null === $shop_binding && '' === self::location_id() )
			|| ( null !== $shop_binding && ! self::mapped_binding_current( $shop_binding ) )
		) {
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

	/**
	 * Confirm a v2 transport context still names the current active-mode map.
	 * Native location activity and the exact attempt-to-shop relationship are
	 * checked by attempt_shop_binding() immediately before this request path.
	 *
	 * @param mixed $shop_binding Candidate mapped v2 binding.
	 * @return bool
	 */
	private static function mapped_binding_current( $shop_binding ) {
		if ( ! is_array( $shop_binding ) ) {
			return false;
		}
		$shop_slug          = isset( $shop_binding['shop_slug'] ) && is_string( $shop_binding['shop_slug'] ) ? $shop_binding['shop_slug'] : '';
		$square_location_id = isset( $shop_binding['square_location_id'] ) && is_string( $shop_binding['square_location_id'] ) ? $shop_binding['square_location_id'] : '';
		$square_mode        = isset( $shop_binding['square_mode'] ) && is_string( $shop_binding['square_mode'] ) ? $shop_binding['square_mode'] : '';
		$current_location   = in_array( $shop_slug, array( 'revesby', 'bankstown', 'roselands' ), true ) ? DoughBoss_Settings::square_shop_location_id( $shop_slug ) : '';

		return '' !== $current_location
			&& '' !== $square_location_id
			&& hash_equals( $current_location, $square_location_id )
			&& in_array( $square_mode, array( 'test', 'live' ), true )
			&& hash_equals( self::mode(), $square_mode );
	}
}
