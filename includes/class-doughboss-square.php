<?php
/**
 * Square Payments API gateway (optional, off by default).
 *
 * A thin, dependency-free wrapper over the Square Payments API used to take and
 * verify one full-value card or Square Gift Card payment per storefront order.
 * Split tender is not supported by this lifecycle. No SDK is bundled —
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
	/** Catalogue only: one location response plus at most five list responses, each at most 4 MiB. */
	const CATALOGUE_MAX_TOP_LEVEL = 2048;
	const CATALOGUE_MAX_OCCURRENCES = 4096;
	const CATALOGUE_MAX_RESPONSE_BYTES = 4194304;
	const CATALOGUE_MAX_PAGES = 5;
	/** Local batch bound matching approved target capacity, not API-pin certification. */
	const CATALOGUE_MAX_REFERENCES = 1000;

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
	 * Local configuration readiness for one server-resolved native shop.
	 * Callers separately enforce online_payment_location(); this does not
	 * attest merchant ownership or replace dispatch-time binding validation.
	 */
	public static function ready_for_location( $location_id ) {
		if ( ! is_int( $location_id ) || $location_id < 1 ) {
			return false;
		}
		$binding = self::shop_binding( $location_id );
		return ! is_wp_error( $binding ) && DoughBoss_Settings::square_shop_ready( $binding['shop_slug'] );
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

	/** Explicit owner-only account observation. No mapping, approval or provider write is performed. */
	public static function observe_locations( callable $authorize ) {
		$permission = self::profile_authorized( $authorize );
		if ( is_wp_error( $permission ) ) { return $permission; }
		$context = self::profile_context();
		$permission = self::profile_authorized( $authorize );
		if ( is_wp_error( $permission ) ) { return $permission; }
		if ( is_wp_error( $context ) ) { return $context; }
		$identity = null;
		$current = static function () use ( $authorize, $context, &$identity ) {
			$permission = self::profile_authorized( $authorize );
			if ( is_wp_error( $permission ) ) { return $permission; }
			$fresh = self::profile_context();
			$permission = self::profile_authorized( $authorize );
			if ( is_wp_error( $permission ) ) { return $permission; }
			if ( is_wp_error( $fresh ) || $context !== $fresh ) { return self::profile_error( 409 ); }
			// The second owner callback may itself change configuration/native rows.
			// Re-read after that work before dispatch or disclosure; a pre-callback
			// snapshot is not evidence that the captured context is still current.
			$terminal = self::profile_context();
			if ( is_wp_error( $terminal ) || $context !== $terminal ) { return self::profile_error( 409 ); }
			// Keep expiry last: both owner checks and locking reads can cross it.
			if ( null !== $identity ) { return self::validate_locations_observation_expiry( array( 'application_id' => $identity['application_id'], 'merchant_id' => $identity['merchant_id'], 'credential_scopes' => $identity['scopes'], 'credential_expires_at' => $identity['expires_at'] ) ); }
			return true;
		};
		foreach ( array( array( 'POST', '/oauth2/token/status' ), array( 'GET', '/v2/locations' ), array( 'POST', '/oauth2/token/status' ) ) as $index => $call ) {
			$permission = $current();
			if ( is_wp_error( $permission ) ) { return $permission; }
			$response = self::profile_request( $call[0], $call[1], $context );
			$permission = $current();
			if ( is_wp_error( $permission ) ) { return $permission; }
			if ( is_wp_error( $response ) ) { return self::profile_error(); }
			if ( 1 === $index ) {
				$locations = self::profile_locations( $response, $identity['merchant_id'] );
				if ( is_wp_error( $locations ) ) { return $locations; }
			} else {
				$observed = self::catalogue_identity( $response, $context['application_id'] );
				if ( is_wp_error( $observed ) || ! in_array( 'MERCHANT_PROFILE_READ', $observed['scopes'], true ) || ( 2 === $index && $identity !== $observed ) ) { return self::profile_error(); }
				$identity = $observed;
			}
		}
		$view = self::profile_view( $identity, $locations, $context );
		$permission = $current();
		return is_wp_error( $permission ) ? $permission : $view;
	}

	/** Last server-clock expiry check only; never authorizes a mapping or provider action. */
	public static function validate_locations_observation_expiry( array $view ) {
		if ( ! isset( $view['application_id'], $view['merchant_id'], $view['credential_scopes'], $view['credential_expires_at'] ) ) { return self::profile_error(); }
		$identity = self::catalogue_identity( array( 'client_id' => $view['application_id'], 'merchant_id' => $view['merchant_id'], 'scopes' => $view['credential_scopes'], 'expires_at' => $view['credential_expires_at'] ), DoughBoss_Settings::square_application_id() );
		return is_wp_error( $identity ) ? self::profile_error() : true;
	}

	private static function profile_error( $status = 502 ) {
		return new WP_Error( 'doughboss_square_locations_unavailable', __( 'Current Square locations could not be observed safely. No mapping or payment permission has been changed.', 'doughboss' ), array( 'status' => $status ) );
	}

	private static function profile_authorized( callable $authorize ) {
		try { $permission = $authorize(); } catch ( Throwable $error ) { $permission = false; }
		if ( is_wp_error( $permission ) ) { return $permission; }
		return true === $permission ? true : new WP_Error( 'doughboss_forbidden', __( 'Current owner browser authority could not be verified.', 'doughboss' ), array( 'status' => 403 ) );
	}

	/** Current locking reads bypass open REPEATABLE READ snapshots; nothing is persisted. */
	private static function profile_context() {
		global $wpdb;
		try {
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1 FOR UPDATE", DoughBoss_Settings::OPTION_KEY ) );
			if ( ! empty( $wpdb->last_error ) || ! is_string( $raw ) ) { return self::profile_error( 409 ); }
			$stored = maybe_unserialize( $raw );
			if ( ! is_array( $stored ) || $stored !== get_option( DoughBoss_Settings::OPTION_KEY, array() ) ) { return self::profile_error( 409 ); }
			$mode = DoughBoss_Settings::square_mode();
			if ( array_key_exists( 'square_mode', $stored ) && ! in_array( $stored['square_mode'], array( 'test', 'live' ), true ) ) { return self::profile_error( 409 ); }
			$map_key = 'square_' . $mode . '_location_map';
			$map = array_key_exists( $map_key, $stored ) ? $stored[ $map_key ] : array();
			if ( ! is_array( $map ) ) { return self::profile_error( 409 ); }
			$rows = $wpdb->get_results( "SELECT id,slug,is_active FROM {$wpdb->prefix}doughboss_locations WHERE slug IN ('revesby','bankstown','roselands') ORDER BY id FOR UPDATE" );
			if ( ! empty( $wpdb->last_error ) || ! is_array( $rows ) ) { return self::profile_error( 409 ); }
			$shops = self::profile_shops( $rows, $map );
			$currency = DoughBoss_Settings::get( 'currency_code', 'AUD' );
			$app = DoughBoss_Settings::square_application_id();
			$pin = DoughBoss_Settings::square_api_version();
			if ( is_wp_error( $shops ) || ! DoughBoss_Settings::square_access_token_valid() || '' === $app || ! is_string( $currency ) || ! preg_match( '/^[A-Z]{3}$/D', strtoupper( $currency ) ) || ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $pin, $date ) || ! checkdate( (int) $date[2], (int) $date[3], (int) $date[1] ) ) { return self::profile_error( 409 ); }
			return array( 'settings_version' => hash( 'sha256', $raw ), 'token' => self::access_token(), 'application_id' => $app, 'environment' => $mode, 'api_version' => $pin, 'currency' => $currency, 'website_shops' => $shops );
		} catch ( Throwable $error ) { return self::profile_error( 409 ); }
	}

	/** Validate raw maps before any normalizing getter can erase ambiguity. */
	private static function profile_shops( array $rows, array $map ) {
		if ( 3 !== count( $rows ) ) { return self::profile_error( 409 ); }
		$native = array(); $native_ids = array(); $provider_ids = array(); $shops = array();
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->id, $row->slug, $row->is_active ) || ! is_string( $row->slug ) || ! in_array( $row->slug, array( 'revesby', 'bankstown', 'roselands' ), true ) || ! in_array( $row->is_active, array( 1, '1' ), true ) || ( ! is_int( $row->id ) && ( ! is_string( $row->id ) || ! preg_match( '/^[1-9][0-9]{0,15}$/D', $row->id ) ) ) ) { return self::profile_error( 409 ); }
			$id = (int) $row->id;
			if ( $id < 1 || $id > 9007199254740991 || isset( $native[ $row->slug ] ) || isset( $native_ids[ $id ] ) ) { return self::profile_error( 409 ); }
			$native[ $row->slug ] = $id; $native_ids[ $id ] = true;
		}
		foreach ( array( 'revesby', 'bankstown', 'roselands' ) as $slug ) {
			$configured = array_key_exists( $slug, $map ) ? $map[ $slug ] : '';
			if ( ! is_string( $configured ) || ( '' !== $configured && ( ! preg_match( '/^[A-Za-z0-9_-]{4,64}$/D', $configured ) || isset( $provider_ids[ $configured ] ) ) ) ) { return self::profile_error( 409 ); }
			if ( '' !== $configured ) { $provider_ids[ $configured ] = true; }
			$shops[] = array( 'slug' => $slug, 'native_location_id' => $native[ $slug ], 'configured_location_id' => '' === $configured ? null : $configured );
		}
		return $shops;
	}

	/** Dedicated account reader: mapped payment transport remains deliberately unchanged. */
	private static function profile_request( $method, $path, array $context ) {
		if ( ! ( ( 'POST' === $method && '/oauth2/token/status' === $path ) || ( 'GET' === $method && '/v2/locations' === $path ) ) ) { return self::profile_error(); }
		try {
			$host = 'live' === $context['environment'] ? self::PRODUCTION_HOST : self::SANDBOX_HOST;
			$response = wp_remote_request( $host . $path, array( 'method' => $method, 'timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => self::CATALOGUE_MAX_RESPONSE_BYTES + 1, 'headers' => array( 'Authorization' => 'Bearer ' . $context['token'], 'Square-Version' => $context['api_version'], 'Content-Type' => 'application/json', 'Accept' => 'application/json' ) ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { return self::profile_error(); }
			return self::profile_decode( wp_remote_retrieve_body( $response ) );
		} catch ( Throwable $error ) { return self::profile_error(); }
	}

	/** Preserve wire object/list distinctions and reject duplicate keys at every nesting level. */
	private static function profile_decode( $body ) {
		if ( ! is_string( $body ) || strlen( $body ) > self::CATALOGUE_MAX_RESPONSE_BYTES ) { return self::profile_error(); }
		$wire = json_decode( $body, false, 128 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! $wire instanceof stdClass || property_exists( $wire, 'errors' ) ) { return self::profile_error(); }
		if ( ! preg_match_all( '/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]/s', $body, $matches ) ) { return self::profile_error(); }
		$stack = array(); $tokens = $matches[0];
		foreach ( $tokens as $index => $token ) {
			if ( '{' === $token || '[' === $token ) { $stack[] = array( 'object' => '{' === $token, 'keys' => array() ); }
			elseif ( '}' === $token || ']' === $token ) { array_pop( $stack ); }
			elseif ( '"' === $token[0] && isset( $tokens[ $index + 1 ] ) && ':' === $tokens[ $index + 1 ] ) {
				$top = count( $stack ) - 1; $key = '#' . json_decode( $token );
				if ( $top < 0 || ! $stack[ $top ]['object'] || isset( $stack[ $top ]['keys'][ $key ] ) ) { return self::profile_error(); }
				$stack[ $top ]['keys'][ $key ] = true;
			}
		}
		if ( property_exists( $wire, 'locations' ) ) {
			if ( ! is_array( $wire->locations ) || count( $wire->locations ) > 128 ) { return self::profile_error(); }
			foreach ( $wire->locations as $location ) {
				if ( ! $location instanceof stdClass || ( property_exists( $location, 'capabilities' ) && ! is_array( $location->capabilities ) ) ) { return self::profile_error(); }
			}
		}
		$decoded = self::catalogue_decode( $body );
		return is_wp_error( $decoded ) ? self::profile_error() : $decoded;
	}

	private static function profile_locations( $response, $merchant_id ) {
		if ( ! is_array( $response ) || array_key_exists( 'errors', $response ) || ! array_key_exists( 'locations', $response ) || ! is_array( $response['locations'] ) || array_values( $response['locations'] ) !== $response['locations'] || count( $response['locations'] ) > 128 || array_key_exists( 'cursor', $response ) ) { return self::profile_error(); }
		$locations = array(); $seen = array();
		foreach ( $response['locations'] as $location ) {
			if ( ! is_array( $location ) || ! isset( $location['id'], $location['merchant_id'], $location['status'], $location['currency'] ) || ! self::catalogue_id( $location['id'] ) || isset( $seen[ $location['id'] ] ) || ! is_string( $location['merchant_id'] ) || ! hash_equals( $merchant_id, $location['merchant_id'] ) || ! in_array( $location['status'], array( 'ACTIVE', 'INACTIVE' ), true ) || ! is_string( $location['currency'] ) || ! preg_match( '/^[A-Z]{3}$/D', $location['currency'] ) ) { return self::profile_error(); }
			$name = array_key_exists( 'name', $location ) ? $location['name'] : null;
			if ( array_key_exists( 'name', $location ) && ( ! is_string( $name ) || '' === $name || strlen( $name ) > 1024 || 1 !== preg_match( '//u', $name ) || preg_match_all( '/./us', $name, $characters ) + preg_match_all( '/[\x{10000}-\x{10FFFF}]/u', $name, $surrogates ) > 200 ) ) { return self::profile_error(); }
			$country = array_key_exists( 'country', $location ) ? $location['country'] : null;
			if ( null !== $country && ( ! is_string( $country ) || ! preg_match( '/^[A-Z]{2}$/D', $country ) ) ) { return self::profile_error(); }
			$present = array_key_exists( 'capabilities', $location ); $capabilities = $present ? $location['capabilities'] : array(); $unique = array();
			if ( ! is_array( $capabilities ) || array_values( $capabilities ) !== $capabilities || count( $capabilities ) > 32 ) { return self::profile_error(); }
			foreach ( $capabilities as $capability ) {
				if ( ! is_string( $capability ) || ! preg_match( '/^[A-Z][A-Z0-9_]{0,63}$/D', $capability ) || isset( $unique[ $capability ] ) ) { return self::profile_error(); }
				$unique[ $capability ] = true;
			}
			sort( $capabilities, SORT_STRING ); $seen[ $location['id'] ] = true;
			$locations[] = array( 'id' => $location['id'], 'name' => $name, 'merchant_id' => $merchant_id, 'status' => $location['status'], 'currency' => $location['currency'], 'country' => $country, 'capabilities_present' => $present, 'capabilities' => $capabilities );
		}
		return $locations;
	}

	private static function profile_view( array $identity, array $locations, array $context ) {
		$ids = array(); foreach ( $locations as $location ) { $ids[ $location['id'] ] = true; }
		$shops = $context['website_shops'];
		foreach ( $shops as &$shop ) { $shop['presence'] = null === $shop['configured_location_id'] ? 'unmapped' : ( isset( $ids[ $shop['configured_location_id'] ] ) ? 'configured_id_observed' : 'configured_id_not_observed' ); } unset( $shop );
		return array( 'schema_version' => 1, 'source' => 'square', 'data_state' => 'location_observation', 'observed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'application_id' => $identity['application_id'], 'merchant_id' => $identity['merchant_id'], 'environment' => $context['environment'], 'api_version' => $context['api_version'], 'credential_scopes' => $identity['scopes'], 'credential_expires_at' => $identity['expires_at'], 'provider_locations' => $locations, 'website_shops' => $shops, 'limitations' => array( 'merchant_ownership' => 'unapproved', 'store_access' => 'unverified', 'settlement' => 'unverified', 'payment_enablement' => 'unverified', 'website_availability' => 'unverified' ), 'provider_writes_allowed' => false, 'mapping_approved' => false, 'dispatch_allowed' => false );
	}

	/** Internal credential introspection only; no approval, import readiness or provider mutation. */
	public static function observe_catalogue_identity( $location_id ) {
		$error = self::catalogue_identity_error();
		if ( ! is_int( $location_id ) || $location_id < 1 || ! DoughBoss_Settings::square_access_token_valid() || '' === DoughBoss_Settings::square_application_id() ) { return $error; }
		$binding = self::shop_binding( $location_id );
		if ( is_wp_error( $binding ) ) { return $error; }
		$token = self::access_token();
		$app = DoughBoss_Settings::square_application_id();
		$pin = DoughBoss_Settings::square_api_version();
		$currency = DoughBoss_Settings::get( 'currency_code', 'AUD' );
		if ( ! is_string( $currency ) ) { return $error; }
		$currency = strtoupper( $currency );
		if ( ! preg_match( '/^[A-Z]{3}$/D', $currency ) || ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $pin, $date ) || ! checkdate( (int) $date[2], (int) $date[3], (int) $date[1] ) ) { return $error; }
		$scope_current = static function () use ( $location_id, $binding, $token, $app, $pin, $currency ) {
			$current_currency = DoughBoss_Settings::get( 'currency_code', 'AUD' );
			return $binding === self::shop_binding( $location_id ) && DoughBoss_Settings::square_access_token_valid()
				&& hash_equals( $token, self::access_token() ) && hash_equals( $app, DoughBoss_Settings::square_application_id() )
				&& hash_equals( $pin, DoughBoss_Settings::square_api_version() ) && is_string( $current_currency ) && $currency === strtoupper( $current_currency );
		};
		if ( ! $scope_current() ) { return $error; }
		// Square documents this POST as read-only introspection with bearer auth and no body.
		$response = self::request( 'POST', '/oauth2/token/status', array(), $binding, true );
		if ( is_wp_error( $response ) || ! $scope_current() ) { return $error; }
		$identity = self::catalogue_identity( $response, $app );
		if ( is_wp_error( $identity ) || ! $scope_current() ) { return $error; }
		return array_merge( $identity, array(
			'schema_version' => 1,
			'data_state' => 'credential_identity_observed',
			'environment' => $binding['square_mode'],
			// This is the configured/sent pin, not an attestation of import-field compatibility.
			'api_version' => $pin,
			'shop' => array( 'id' => $location_id, 'slug' => $binding['shop_slug'], 'square_location_id' => $binding['square_location_id'] ),
			'observed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		) );
	}

	/** Internal exact current reference readback; no authenticity or tax-applicability approval. */
	public static function observe_catalogue_references( $location_id, array $references ) {
		return self::observe_catalogue_reference_view( $location_id, $references, false );
	}

	/** Explicit owner-review opt-in; safe IMAGE metadata is provider-reported, not authenticated. */
	public static function observe_catalogue_review_references( $location_id, array $references ) {
		return self::observe_catalogue_reference_view( $location_id, $references, true );
	}

	private static function observe_catalogue_reference_view( $location_id, array $references, $owner_review ) {
		$error = self::catalogue_reference_error();
		$body = self::catalogue_reference_request( $references );
		if ( is_wp_error( $body ) || ! is_int( $location_id ) || $location_id < 1 || ! DoughBoss_Settings::square_access_token_valid() || '' === DoughBoss_Settings::square_application_id() ) { return $error; }
		$binding = self::shop_binding( $location_id );
		if ( is_wp_error( $binding ) ) { return $error; }
		$token = self::access_token();
		$app = DoughBoss_Settings::square_application_id();
		$pin = DoughBoss_Settings::square_api_version();
		$currency = DoughBoss_Settings::get( 'currency_code', 'AUD' );
		if ( ! is_string( $currency ) ) { return $error; }
		$currency = strtoupper( $currency );
		if ( ! preg_match( '/^[A-Z]{3}$/D', $currency ) || ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $pin, $date ) || ! checkdate( (int) $date[2], (int) $date[3], (int) $date[1] ) ) { return $error; }
		$scope_current = static function () use ( $location_id, $binding, $token, $app, $pin, $currency ) {
			$current_currency = DoughBoss_Settings::get( 'currency_code', 'AUD' );
			return $binding === self::shop_binding( $location_id ) && DoughBoss_Settings::square_access_token_valid()
				&& hash_equals( $token, self::access_token() ) && hash_equals( $app, DoughBoss_Settings::square_application_id() )
				&& hash_equals( $pin, DoughBoss_Settings::square_api_version() ) && is_string( $current_currency ) && $currency === strtoupper( $current_currency );
		};
		if ( ! $scope_current() ) { return $error; }
		$objects = array();
		if ( count( $references ) > 0 ) {
			$response = self::request( 'POST', '/v2/catalog/batch-retrieve', $body, $binding, true );
			if ( is_wp_error( $response ) || ! $scope_current() ) { return $error; }
			$objects = self::catalogue_references( $response, $references, $currency, $owner_review );
			if ( is_wp_error( $objects ) ) { return $error; }
		}
		if ( ! $scope_current() ) { return $error; }
		return array( 'schema_version' => 1, 'data_state' => 'exact_references_observed', 'environment' => $binding['square_mode'],
			'api_version' => $pin, 'shop' => array( 'id' => $location_id, 'slug' => $binding['shop_slug'], 'square_location_id' => $binding['square_location_id'] ),
			'observed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'objects' => $objects );
	}

	/** Explicit owner-requested current catalogue observation; GET only, no sync. */
	public static function observe_catalogue( $location_id ) {
		$error = self::catalogue_error();
		if ( ! is_int( $location_id ) || $location_id < 1 || ! DoughBoss_Settings::square_access_token_valid() || '' === DoughBoss_Settings::square_application_id() ) { return $error; }
		$binding = self::shop_binding( $location_id );
		if ( is_wp_error( $binding ) ) { return $error; }
		$token = self::access_token();
		$app = DoughBoss_Settings::square_application_id();
		$pin = DoughBoss_Settings::square_api_version();
		$currency = DoughBoss_Settings::get( 'currency_code', 'AUD' );
		if ( ! is_string( $currency ) ) { return $error; }
		$currency = strtoupper( $currency );
		if ( ! preg_match( '/^[A-Z]{3}$/D', $currency ) ) { return $error; }
		$scope_current = static function () use ( $location_id, $binding, $token, $app, $pin, $currency ) {
			$current_currency = DoughBoss_Settings::get( 'currency_code', 'AUD' );
			return $binding === self::shop_binding( $location_id ) && DoughBoss_Settings::square_access_token_valid()
				&& hash_equals( $token, self::access_token() ) && hash_equals( $app, DoughBoss_Settings::square_application_id() )
				&& hash_equals( $pin, DoughBoss_Settings::square_api_version() ) && is_string( $current_currency ) && $currency === strtoupper( $current_currency );
		};
		if ( ! $scope_current() ) { return $error; }
		$response = self::request( 'GET', '/v2/locations/' . rawurlencode( $binding['square_location_id'] ), array(), $binding, true );
		if ( is_wp_error( $response ) || ! $scope_current() || array_key_exists( 'errors', $response ) || ! isset( $response['location'] ) ) { return $error; }
		$location = self::catalogue_location( $response['location'], $binding['square_location_id'], $currency );
		if ( is_wp_error( $location ) ) { return $error; }
		$types = array( 'ITEM', 'ITEM_VARIATION', 'MODIFIER_LIST', 'MODIFIER', 'IMAGE', 'TAX', 'CATEGORY' );
		$objects = array();
		$ids = array();
		$identities = array();
		$cursors = array();
		$cursor = '';
		$count = 0;
		$top_level_count = 0;
		for ( $page = 1; $page <= self::CATALOGUE_MAX_PAGES; ++$page ) {
			if ( ! $scope_current() ) { return $error; }
			$path = '/v2/catalog/list?types=' . rawurlencode( implode( ',', $types ) );
			if ( '' !== $cursor ) { $path .= '&cursor=' . rawurlencode( $cursor ); }
			$response = self::request( 'GET', $path, array(), $binding, true );
			if ( is_wp_error( $response ) || ! $scope_current() || array_key_exists( 'errors', $response ) ) { return $error; }
			// ListCatalog omits objects for an empty library. Only an absent
			// objects field on a terminal successful JSON object means empty.
			if ( ! array_key_exists( 'objects', $response ) && ( ! array_key_exists( 'cursor', $response ) || '' === $response['cursor'] ) ) { $response['objects'] = array(); }
			if ( ! isset( $response['objects'] ) || ! is_array( $response['objects'] )
				|| array_values( $response['objects'] ) !== $response['objects'] || $top_level_count + count( $response['objects'] ) > self::CATALOGUE_MAX_TOP_LEVEL ) { return $error; }
			$top_level_count += count( $response['objects'] );
			foreach ( $response['objects'] as $object ) {
				$projected = self::catalogue_object( $object, $currency, $count );
				if ( is_wp_error( $projected ) || ! self::catalogue_register_identity( $projected, $identities ) ) { return $error; }
				if ( isset( $ids[ $projected['id'] ] ) ) { continue; }
				$ids[ $projected['id'] ] = true;
				$objects[] = $projected;
			}
			if ( ! array_key_exists( 'cursor', $response ) || '' === $response['cursor'] ) {
				if ( ! $scope_current() ) { return $error; }
				$counts = array_fill_keys( $types, 0 );
				foreach ( $objects as $object ) { ++$counts[ $object['type'] ]; }
				return array( 'schema_version' => 1, 'source' => 'square', 'data_state' => 'catalogue_observed', 'observed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'environment' => $binding['square_mode'], 'shop' => array( 'id' => $location_id, 'slug' => $binding['shop_slug'] ), 'location' => $location,
					'reconciliation' => array( 'merchant_identity' => 'observed_owner_reconciliation_required', 'catalogue' => 'current_listing_only', 'pricing' => 'base_price_and_raw_overrides_only', 'inventory' => 'not_observed' ),
					'catalogue' => array( 'types' => $types, 'pages' => $page, 'object_count' => count( $objects ), 'counts' => $counts, 'objects' => $objects ) );
			}
			$cursor = $response['cursor'];
			if ( ! is_string( $cursor ) || strlen( $cursor ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $cursor ) || isset( $cursors[ $cursor ] ) || self::CATALOGUE_MAX_PAGES === $page ) { return $error; }
			$cursors[ $cursor ] = true;
		}
		return $error;
	}

	/** Exact reference intent only; never ask for a historical catalogue or related objects. */
	private static function catalogue_reference_request( array $references ) {
		$error = self::catalogue_reference_error();
		if ( array_values( $references ) !== $references || count( $references ) > self::CATALOGUE_MAX_REFERENCES ) { return $error; }
		$ids = array();
		foreach ( $references as $reference ) {
			if ( ! is_array( $reference ) ) { return $error; }
			$keys = array_keys( $reference ); sort( $keys, SORT_STRING );
			if ( array( 'id', 'type', 'version' ) !== $keys || ! self::catalogue_id( $reference['id'] )
				|| ! in_array( $reference['type'], array( 'IMAGE', 'TAX' ), true ) || ! is_int( $reference['version'] )
				|| $reference['version'] <= 0 || $reference['version'] > 9007199254740991 || isset( $ids[ $reference['id'] ] ) ) { return $error; }
			$ids[ $reference['id'] ] = $reference['id'];
		}
		$object_ids = array_values( $ids );
		$body = array( 'object_ids' => $object_ids, 'include_related_objects' => false, 'include_deleted_objects' => true );
		$json = wp_json_encode( $body );
		return is_string( $json ) && strlen( $json ) <= self::CATALOGUE_MAX_RESPONSE_BYTES ? $body : $error;
	}

	/** Reconcile every exact requested reference before returning any minimized evidence. */
	private static function catalogue_references( $response, array $references, $currency, $owner_review = false ) {
		$error = self::catalogue_reference_error();
		if ( is_wp_error( self::catalogue_reference_request( $references ) ) || ! is_array( $response ) || array_key_exists( 'errors', $response )
			|| ! isset( $response['objects'] ) || ! is_array( $response['objects'] ) || array_values( $response['objects'] ) !== $response['objects']
			|| count( $response['objects'] ) !== count( $references ) || ( array_key_exists( 'related_objects', $response ) && array() !== $response['related_objects'] ) ) { return $error; }
		$expected = array();
		foreach ( $references as $reference ) { $expected[ $reference['id'] ] = $reference; }
		$objects = array(); $count = 0;
		foreach ( $response['objects'] as $object ) {
			$projection_input = $object;
			if ( true === $owner_review && is_array( $projection_input ) && isset( $projection_input['type'] ) && in_array( $projection_input['type'], array( 'IMAGE', 'TAX' ), true ) ) {
				$field = strtolower( $projection_input['type'] ) . '_data';
				if ( isset( $projection_input[ $field ] ) && is_array( $projection_input[ $field ] ) ) { unset( $projection_input[ $field ]['name'] ); }
			}
			$projected = self::catalogue_object( $projection_input, $currency, $count );
			if ( is_wp_error( $projected ) || ! isset( $expected[ $projected['id'] ] ) || isset( $objects[ $projected['id'] ] )
				|| $expected[ $projected['id'] ]['type'] !== $projected['type'] || $expected[ $projected['id'] ]['version'] !== $projected['version']
				|| false !== $projected['is_deleted'] || count( $projected['embedded_objects'] ) > 0 ) { return $error; }
			$data = array();
			if ( 'TAX' === $projected['type'] ) {
				foreach ( array( 'percentage', 'calculation_phase', 'inclusion_type', 'enabled', 'applies_to_custom_amounts', 'applies_to_product_set_id' ) as $fact ) {
					if ( is_array( $projected['data'] ) && array_key_exists( $fact, $projected['data'] ) ) { $data[ $fact ] = $projected['data'][ $fact ]; }
				}
			}
			if ( true === $owner_review ) {
				$data['name'] = self::catalogue_review_name( isset( $object[ strtolower( $projected['type'] ) . '_data' ]['name'] ) ? $object[ strtolower( $projected['type'] ) . '_data' ]['name'] : null );
				if ( 'IMAGE' === $projected['type'] ) {
					$data['url'] = self::catalogue_review_image_url( isset( $object['image_data']['url'] ) ? $object['image_data']['url'] : null );
					$data['evidence_available'] = null !== $data['url'] && null !== $data['name'];
					$data['evidence_source'] = 'provider_reported';
					$data['authenticity_verified'] = false;
					$data['licence_verified'] = false;
				}
			}
			$objects[ $projected['id'] ] = array( 'id' => $projected['id'], 'type' => $projected['type'], 'version' => $projected['version'], 'is_deleted' => false, 'data' => count( $data ) ? $data : new stdClass() );
		}
		ksort( $objects, SORT_STRING );
		return array_values( $objects );
	}

	/** No URL fetching, DNS, proxying, or preload. Conservative public-looking HTTPS only. */
	private static function catalogue_review_image_url( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 2048 || preg_match( '/[^\x21-\x7e]/', $value ) || false !== strpos( $value, '\\' ) || false !== strpos( $value, '@' ) || ! preg_match( '~^https://([A-Za-z0-9.-]+)(?::443)?(/[^?#]*)?$~D', $value, $parts ) ) { return null; }
		$host = strtolower( $parts[1] ); $labels = explode( '.', $host );
		if ( strlen( $host ) > 253 || count( $labels ) < 2 || ! preg_match( '/^[a-z]{2,63}$/D', end( $labels ) ) || preg_match( '/(?:^|\.)(?:localhost|local|internal|lan|home|test|invalid|example|onion)$/D', $host ) ) { return null; }
		foreach ( $labels as $label ) { if ( strlen( $label ) > 63 || ! preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label ) ) { return null; } }
		$path = isset( $parts[2] ) ? $parts[2] : '';
		if ( preg_match( '/%(?![0-9A-Fa-f]{2})/', $path ) || preg_match( '/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c|25|2f|3a|40|23|3f)/i', $path ) ) { return null; }
		return $value;
	}

	private static function catalogue_review_name( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 200 || '' === trim( $value ) || 1 !== preg_match( '//u', $value ) || preg_match( '/[\p{C}]/u', $value ) ) { return null; }
		$name = sanitize_text_field( $value );
		return '' === trim( $name ) ? null : $name;
	}

	private static function catalogue_reference_error() {
		return new WP_Error( 'doughboss_square_catalogue_reference_unavailable', __( 'Exact Square catalogue references could not be observed safely. No catalogue preparation was authorized.', 'doughboss' ), array( 'status' => 502 ) );
	}

	/** Validate only documented token-status facts; no credential or readiness evidence is inferred. */
	private static function catalogue_identity( $response, $application_id ) {
		$error = self::catalogue_identity_error();
		if ( ! is_array( $response ) || array_key_exists( 'errors', $response )
			|| ! isset( $response['client_id'], $response['merchant_id'], $response['scopes'], $response['expires_at'] )
			|| ! self::catalogue_id( $response['client_id'] ) || ! self::catalogue_id( $response['merchant_id'] )
			|| strlen( $response['merchant_id'] ) < 8 || strlen( $response['merchant_id'] ) > 191
			|| ! is_string( $application_id ) || ! hash_equals( $application_id, $response['client_id'] )
			|| ! is_array( $response['scopes'] ) || array_values( $response['scopes'] ) !== $response['scopes'] || count( $response['scopes'] ) > 128
			|| ! is_string( $response['expires_at'] ) ) { return $error; }
		$scopes = array();
		foreach ( $response['scopes'] as $scope ) {
			if ( ! is_string( $scope ) || ! preg_match( '/^[A-Z][A-Z0-9_]{0,127}$/D', $scope ) || isset( $scopes[ $scope ] ) ) { return $error; }
			$scopes[ $scope ] = true;
		}
		$expiry = $response['expires_at'];
		// Validate the original grammar before removing any fractional bytes;
		// otherwise a malformed double fraction could be normalized into a valid date.
		if ( '' !== $expiry && ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,9})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $expiry ) ) { return $error; }
		// PHP 7.4 cannot parse every RFC3339 fractional precision. Validate the
		// calendar/zone and compare whole seconds conservatively; retain wire evidence.
		$clock_expiry = preg_replace( '/\.[0-9]{1,9}(?=Z|[+-])/', '', $expiry );
		if ( '' !== $expiry && ( ! self::catalogue_timestamp( $clock_expiry ) || strtotime( $clock_expiry ) <= time() ) ) { return $error; }
		$scopes = array_keys( $scopes );
		sort( $scopes, SORT_STRING );
		return array( 'application_id' => $response['client_id'], 'merchant_id' => $response['merchant_id'], 'scopes' => $scopes, 'expires_at' => $expiry );
	}

	private static function catalogue_identity_error() {
		return new WP_Error( 'doughboss_square_catalogue_identity_unavailable', __( 'Square credential identity could not be observed safely. No catalogue preparation was authorized.', 'doughboss' ), array( 'status' => 502 ) );
	}

	/** Never expose provider errors, headers, credentials or partial observations. */
	private static function catalogue_error() {
		return new WP_Error( 'doughboss_square_catalogue_unavailable', __( 'Square catalogue observation could not be completed safely. Check the exact shop mapping and read permissions, then explicitly retry. No partial catalogue was returned.', 'doughboss' ), array( 'status' => 502 ) );
	}

	/** Keep wire container identity before associative decoding can erase it. Catalogue only. */
	private static function catalogue_decode( $body ) {
		if ( ! is_string( $body ) || strlen( $body ) > self::CATALOGUE_MAX_RESPONSE_BYTES ) { return self::catalogue_error(); }
		$wire = json_decode( $body );
		if ( ! $wire instanceof stdClass ) { return self::catalogue_error(); }
		// Introspection scopes are a JSON list, never an object erased by associative decoding.
		if ( property_exists( $wire, 'scopes' ) && ! is_array( $wire->scopes ) ) { return self::catalogue_error(); }
		if ( property_exists( $wire, 'related_objects' ) && ( ! is_array( $wire->related_objects ) || count( $wire->related_objects ) > 0 ) ) { return self::catalogue_error(); }
		if ( property_exists( $wire, 'location' ) && ( ! $wire->location instanceof stdClass
			|| ( property_exists( $wire->location, 'capabilities' ) && ! is_array( $wire->location->capabilities ) ) ) ) { return self::catalogue_error(); }
		if ( property_exists( $wire, 'objects' ) ) {
			if ( ! is_array( $wire->objects ) || count( $wire->objects ) > self::CATALOGUE_MAX_TOP_LEVEL ) { return self::catalogue_error(); }
			$count = 0;
			foreach ( $wire->objects as $object ) { if ( ! self::catalogue_wire_object( $object, $count ) ) { return self::catalogue_error(); } }
		}
		return json_decode( $body, true );
	}

	/** Validate the catalogue's actual JSON object/list containers, including embedded facts. */
	private static function catalogue_wire_object( $object, &$count, $nested = false ) {
		if ( ++$count > self::CATALOGUE_MAX_OCCURRENCES || ! $object instanceof stdClass || ! isset( $object->type ) || ! is_string( $object->type ) ) { return false; }
		foreach ( array( 'present_at_location_ids', 'absent_at_location_ids' ) as $field ) {
			if ( property_exists( $object, $field ) && ! is_array( $object->{$field} ) ) { return false; }
		}
		$field = strtolower( $object->type ) . '_data';
		if ( ! property_exists( $object, $field ) ) { return isset( $object->is_deleted ) && true === $object->is_deleted; }
		if ( ! $object->{$field} instanceof stdClass ) { return false; }
		$data = $object->{$field};
		if ( property_exists( $data, 'price_money' ) && ! $data->price_money instanceof stdClass ) { return false; }
		foreach ( array( 'image_ids', 'tax_ids', 'categories', 'location_overrides', 'modifier_list_info', 'variations', 'modifiers' ) as $list ) {
			if ( ! property_exists( $data, $list ) ) { continue; }
			if ( ! is_array( $data->{$list} ) || count( $data->{$list} ) > 500 ) { return false; }
			if ( in_array( $list, array( 'image_ids', 'tax_ids' ), true ) ) { continue; }
			foreach ( $data->{$list} as $entry ) {
				if ( ! $entry instanceof stdClass ) { return false; }
				if ( in_array( $list, array( 'variations', 'modifiers' ), true ) ) {
					if ( $nested || ! self::catalogue_wire_object( $entry, $count, true ) ) { return false; }
				} elseif ( 'location_overrides' === $list && property_exists( $entry, 'price_money' ) && ! $entry->price_money instanceof stdClass ) { return false; }
				elseif ( 'modifier_list_info' === $list && property_exists( $entry, 'modifier_overrides' ) ) {
					if ( ! is_array( $entry->modifier_overrides ) || count( $entry->modifier_overrides ) > 100 ) { return false; }
					foreach ( $entry->modifier_overrides as $override ) { if ( ! $override instanceof stdClass ) { return false; } }
				}
			}
		}
		return true;
	}

	/** One exact fact set per provider identity across pages, parents and children. */
	private static function catalogue_register_identity( array $object, array &$identities ) {
		$facts = wp_json_encode( $object );
		if ( false === $facts || ( isset( $identities[ $object['id'] ] ) && ! hash_equals( $identities[ $object['id'] ], $facts ) ) ) { return false; }
		$identities[ $object['id'] ] = $facts;
		foreach ( $object['embedded_objects'] as $child ) { if ( ! self::catalogue_register_identity( $child, $identities ) ) { return false; } }
		return true;
	}

	/** Validate location evidence without asserting a trusted business mapping. */
	private static function catalogue_location( $location, $expected_id, $currency ) {
		if ( ! is_array( $location ) || ! isset( $location['id'], $location['status'], $location['currency'], $location['merchant_id'] )
			|| ! is_string( $location['id'] ) || ! hash_equals( $expected_id, $location['id'] )
			|| ! in_array( $location['status'], array( 'ACTIVE', 'INACTIVE' ), true ) || $currency !== $location['currency']
			|| ! self::catalogue_id( $location['merchant_id'] ) ) { return self::catalogue_error(); }
		$capabilities = array_key_exists( 'capabilities', $location ) ? $location['capabilities'] : array();
		if ( ! is_array( $capabilities ) || array_values( $capabilities ) !== $capabilities || count( $capabilities ) > 32 ) { return self::catalogue_error(); }
		foreach ( $capabilities as $capability ) {
			if ( ! is_string( $capability ) || ! preg_match( '/^[A-Z][A-Z0-9_]{0,63}$/D', $capability ) ) { return self::catalogue_error(); }
		}
		return array( 'id' => $location['id'], 'status' => $location['status'], 'currency' => $location['currency'], 'merchant_id' => $location['merchant_id'],
			'capabilities_present' => array_key_exists( 'capabilities', $location ), 'capabilities' => $capabilities );
	}

	private static function catalogue_id( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9:_-]{1,192}$/D', $value );
	}

	private static function catalogue_ids( $values ) {
		if ( ! is_array( $values ) || array_values( $values ) !== $values || count( $values ) > 500 ) { return false; }
		foreach ( $values as $id ) { if ( ! self::catalogue_id( $id ) ) { return false; } }
		return $values;
	}

	private static function catalogue_money( $value, $currency ) {
		return is_array( $value ) && isset( $value['amount'], $value['currency'] ) && is_int( $value['amount'] ) && $value['amount'] >= 0 && $value['amount'] <= 9007199254740991 && $currency === $value['currency']
			? array( 'amount' => $value['amount'], 'currency' => $currency ) : false;
	}

	private static function catalogue_timestamp( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,9})?(Z|[+-](\d{2}):(\d{2}))$/D', $value, $parts ) ) { return false; }
		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) && (int) $parts[4] <= 23 && (int) $parts[5] <= 59 && (int) $parts[6] <= 59
			&& ( 'Z' === $parts[7] || ( (int) $parts[8] <= 23 && (int) $parts[9] <= 59 ) ) && false !== strtotime( $value );
	}

	/** Minimise provider objects; preserve raw presence/override facts, not prices inferred from them. */
	private static function catalogue_object( $object, $currency, &$count, $nested = false ) {
		$error = self::catalogue_error();
		if ( ++$count > self::CATALOGUE_MAX_OCCURRENCES || ! is_array( $object ) || ! isset( $object['id'], $object['type'] ) || ! self::catalogue_id( $object['id'] )
			|| ! in_array( $object['type'], array( 'ITEM', 'ITEM_VARIATION', 'MODIFIER_LIST', 'MODIFIER', 'IMAGE', 'TAX', 'CATEGORY' ), true ) ) { return $error; }
		$out = array( 'id' => $object['id'], 'type' => $object['type'], 'version' => null, 'updated_at' => null, 'is_deleted' => null, 'presence' => array(), 'data' => array(), 'embedded_objects' => array() );
		if ( array_key_exists( 'version', $object ) ) {
			if ( ! is_int( $object['version'] ) || $object['version'] < 0 || $object['version'] > 9007199254740991 ) { return $error; }
			$out['version'] = $object['version'];
		}
		if ( array_key_exists( 'updated_at', $object ) ) {
			if ( ! self::catalogue_timestamp( $object['updated_at'] ) ) { return $error; }
			$out['updated_at'] = $object['updated_at'];
		}
		foreach ( array( 'is_deleted', 'present_at_all_locations' ) as $field ) {
			if ( array_key_exists( $field, $object ) && ! is_bool( $object[ $field ] ) ) { return $error; }
		}
		$out['is_deleted'] = isset( $object['is_deleted'] ) ? $object['is_deleted'] : null;
		$out['presence']['present_at_all_locations'] = isset( $object['present_at_all_locations'] ) ? $object['present_at_all_locations'] : null;
		foreach ( array( 'present_at_location_ids', 'absent_at_location_ids' ) as $field ) {
			$out['presence'][ $field ] = array_key_exists( $field, $object ) ? self::catalogue_ids( $object[ $field ] ) : null;
			if ( false === $out['presence'][ $field ] ) { return $error; }
		}
		$field = strtolower( $object['type'] ) . '_data';
		if ( ! isset( $object[ $field ] ) || ! is_array( $object[ $field ] ) ) {
			if ( true !== $out['is_deleted'] ) { return $error; }
			$out['data'] = new stdClass();
			return $out;
		}
		$data = $object[ $field ];
		// Square documents SKU as an optional string, with no field-specific maximum.
		// The existing local whole-response byte ceiling bounds this exact observation.
		if ( 'ITEM_VARIATION' === $object['type'] && array_key_exists( 'sku', $data ) ) {
			if ( ! is_string( $data['sku'] ) || strlen( $data['sku'] ) > self::CATALOGUE_MAX_RESPONSE_BYTES || 1 !== preg_match( '//u', $data['sku'] ) ) { return $error; }
			$out['data']['sku'] = $data['sku'];
		}
		if ( array_key_exists( 'name', $data ) ) {
			if ( ! is_string( $data['name'] ) || strlen( $data['name'] ) > 1024 || 1 !== preg_match( '//u', $data['name'] ) ) { return $error; }
			$out['data']['name'] = $data['name'];
		}
		if ( 'MODIFIER_LIST' === $object['type'] && array_key_exists( 'selection_type', $data ) ) {
			if ( ! in_array( $data['selection_type'], array( 'SINGLE', 'MULTIPLE' ), true ) ) { return $error; }
			$out['data']['selection_type'] = $data['selection_type'];
		}
		if ( 'MODIFIER' === $object['type'] && array_key_exists( 'ordinal', $data ) ) {
			if ( ! is_int( $data['ordinal'] ) || $data['ordinal'] < -2147483648 || $data['ordinal'] > 2147483647 ) { return $error; }
			$out['data']['ordinal'] = $data['ordinal'];
		}
		if ( 'TAX' === $object['type'] ) {
			if ( array_key_exists( 'percentage', $data ) ) {
				if ( ! is_string( $data['percentage'] ) || strlen( $data['percentage'] ) < 1 || strlen( $data['percentage'] ) > 32 || ! preg_match( '/^[0-9]+(?:\.[0-9]+)?$/D', $data['percentage'] ) ) { return $error; }
				$out['data']['percentage'] = $data['percentage'];
			}
			foreach ( array( 'calculation_phase' => array( 'TAX_SUBTOTAL_PHASE', 'TAX_TOTAL_PHASE' ), 'inclusion_type' => array( 'ADDITIVE', 'INCLUSIVE' ) ) as $fact => $allowed ) {
				if ( array_key_exists( $fact, $data ) ) {
					if ( ! in_array( $data[ $fact ], $allowed, true ) ) { return $error; }
					$out['data'][ $fact ] = $data[ $fact ];
				}
			}
			foreach ( array( 'enabled', 'applies_to_custom_amounts' ) as $fact ) {
				if ( array_key_exists( $fact, $data ) ) {
					if ( ! is_bool( $data[ $fact ] ) ) { return $error; }
					$out['data'][ $fact ] = $data[ $fact ];
				}
			}
			if ( array_key_exists( 'applies_to_product_set_id', $data ) ) {
				// Optional nullable provider fact: null declares no ID, not unrestricted tax.
				if ( null !== $data['applies_to_product_set_id'] && ! self::catalogue_id( $data['applies_to_product_set_id'] ) ) { return $error; }
				$out['data']['applies_to_product_set_id'] = $data['applies_to_product_set_id'];
			}
		}
		foreach ( array( 'item_id', 'modifier_list_id', 'category_id' ) as $ref ) {
			if ( array_key_exists( $ref, $data ) ) {
				if ( ! self::catalogue_id( $data[ $ref ] ) ) { return $error; }
				$out['data'][ $ref ] = $data[ $ref ];
			}
		}
		foreach ( array( 'image_ids', 'tax_ids' ) as $ref ) {
			if ( array_key_exists( $ref, $data ) ) {
				$out['data'][ $ref ] = self::catalogue_ids( $data[ $ref ] );
				if ( false === $out['data'][ $ref ] ) { return $error; }
			}
		}
		if ( array_key_exists( 'categories', $data ) ) {
			if ( ! is_array( $data['categories'] ) || array_values( $data['categories'] ) !== $data['categories'] || count( $data['categories'] ) > 500 ) { return $error; }
			$out['data']['category_ids'] = array();
			foreach ( $data['categories'] as $category ) {
				if ( ! is_array( $category ) || ! isset( $category['id'] ) || ! self::catalogue_id( $category['id'] ) ) { return $error; }
				$out['data']['category_ids'][] = $category['id'];
			}
		}
		foreach ( array( 'price_money', 'pricing_type', 'on_by_default', 'track_inventory', 'sold_out' ) as $fact ) {
			if ( ! array_key_exists( $fact, $data ) ) { continue; }
			$value = $data[ $fact ];
			if ( 'price_money' === $fact ) { $value = self::catalogue_money( $value, $currency ); if ( false === $value ) { return $error; } }
			elseif ( 'pricing_type' === $fact ) { if ( ! in_array( $value, array( 'FIXED_PRICING', 'VARIABLE_PRICING' ), true ) ) { return $error; } }
			elseif ( ! is_bool( $value ) ) { return $error; }
			$out['data'][ $fact ] = $value;
		}
		if ( array_key_exists( 'location_overrides', $data ) ) {
			if ( ! is_array( $data['location_overrides'] ) || array_values( $data['location_overrides'] ) !== $data['location_overrides'] || count( $data['location_overrides'] ) > 100 ) { return $error; }
			$out['data']['location_overrides'] = array();
			foreach ( $data['location_overrides'] as $override ) {
				if ( ! is_array( $override ) || ! isset( $override['location_id'] ) || ! self::catalogue_id( $override['location_id'] ) ) { return $error; }
				$raw = array( 'location_id' => $override['location_id'] );
				foreach ( array( 'price_money', 'pricing_type', 'track_inventory', 'sold_out' ) as $fact ) {
					if ( ! array_key_exists( $fact, $override ) ) { continue; }
					$value = $override[ $fact ];
					if ( 'price_money' === $fact ) { $value = self::catalogue_money( $value, $currency ); if ( false === $value ) { return $error; } }
					elseif ( 'pricing_type' === $fact ) { if ( ! in_array( $value, array( 'FIXED_PRICING', 'VARIABLE_PRICING' ), true ) ) { return $error; } }
					elseif ( ! is_bool( $value ) ) { return $error; }
					$raw[ $fact ] = $value;
				}
				if ( array_key_exists( 'sold_out_valid_until', $override ) ) {
					if ( ! self::catalogue_timestamp( $override['sold_out_valid_until'] ) ) { return $error; }
					$raw['sold_out_valid_until'] = $override['sold_out_valid_until'];
				}
				if ( array_key_exists( 'inventory_alert_type', $override ) ) {
					if ( ! is_string( $override['inventory_alert_type'] ) || ! preg_match( '/^[A-Z_]{1,64}$/D', $override['inventory_alert_type'] ) ) { return $error; }
					$raw['inventory_alert_type'] = $override['inventory_alert_type'];
				}
				if ( array_key_exists( 'inventory_alert_threshold', $override ) ) {
					if ( ! is_int( $override['inventory_alert_threshold'] ) || $override['inventory_alert_threshold'] < 0 || $override['inventory_alert_threshold'] > 9007199254740991 ) { return $error; }
					$raw['inventory_alert_threshold'] = $override['inventory_alert_threshold'];
				}
				$out['data']['location_overrides'][] = $raw;
			}
		}
		if ( array_key_exists( 'modifier_list_info', $data ) ) {
			if ( ! is_array( $data['modifier_list_info'] ) || array_values( $data['modifier_list_info'] ) !== $data['modifier_list_info'] || count( $data['modifier_list_info'] ) > 100 ) { return $error; }
			$out['data']['modifier_list_info'] = array();
			foreach ( $data['modifier_list_info'] as $info ) {
				if ( ! is_array( $info ) || ! isset( $info['modifier_list_id'] ) || ! self::catalogue_id( $info['modifier_list_id'] ) ) { return $error; }
				$raw = array( 'modifier_list_id' => $info['modifier_list_id'] );
				foreach ( array( 'min_selected_modifiers', 'max_selected_modifiers', 'enabled' ) as $fact ) {
					if ( ! array_key_exists( $fact, $info ) ) { continue; }
					if ( 'enabled' === $fact ? ! is_bool( $info[ $fact ] ) : ( ! is_int( $info[ $fact ] ) || $info[ $fact ] < -1 || $info[ $fact ] > 9007199254740991 ) ) { return $error; }
					$raw[ $fact ] = $info[ $fact ];
				}
				if ( array_key_exists( 'modifier_overrides', $info ) ) {
					if ( ! is_array( $info['modifier_overrides'] ) || array_values( $info['modifier_overrides'] ) !== $info['modifier_overrides'] || count( $info['modifier_overrides'] ) > 100 ) { return $error; }
					$raw['modifier_overrides'] = array();
					foreach ( $info['modifier_overrides'] as $override ) {
						if ( ! is_array( $override ) || ! isset( $override['modifier_id'] ) || ! self::catalogue_id( $override['modifier_id'] ) || ( array_key_exists( 'on_by_default', $override ) && ! is_bool( $override['on_by_default'] ) ) ) { return $error; }
						$entry = array( 'modifier_id' => $override['modifier_id'] );
						if ( isset( $override['on_by_default'] ) ) { $entry['on_by_default'] = $override['on_by_default']; }
						$raw['modifier_overrides'][] = $entry;
					}
				}
				$out['data']['modifier_list_info'][] = $raw;
			}
		}
		foreach ( array( 'variations' => 'ITEM_VARIATION', 'modifiers' => 'MODIFIER' ) as $embedded => $type ) {
			if ( ! array_key_exists( $embedded, $data ) ) { continue; }
			if ( $nested || ! is_array( $data[ $embedded ] ) || array_values( $data[ $embedded ] ) !== $data[ $embedded ] || count( $data[ $embedded ] ) > 100 ) { return $error; }
			$out['data'][ 'variations' === $embedded ? 'variation_ids' : 'modifier_ids' ] = array();
			foreach ( $data[ $embedded ] as $child ) {
				if ( ! is_array( $child ) || ! isset( $child['type'] ) || $type !== $child['type'] ) { return $error; }
				$projected = self::catalogue_object( $child, $currency, $count, true );
				if ( is_wp_error( $projected ) || ( 'variations' === $embedded && ( ! is_array( $projected['data'] ) || ! isset( $projected['data']['item_id'] ) || $object['id'] !== $projected['data']['item_id'] ) ) ) { return $error; }
				$out['data'][ 'variations' === $embedded ? 'variation_ids' : 'modifier_ids' ][] = $projected['id'];
				$out['embedded_objects'][] = $projected;
			}
		}
		if ( array() === $out['data'] ) { $out['data'] = new stdClass(); }
		return $out;
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
		$metadata = self::attempt_metadata( $attempt );
		$expected_tender = isset( $metadata['square_tender_kind'] ) && 'gift_card' === $metadata['square_tender_kind'] ? 'SQUARE_GIFT_CARD' : 'CARD';
		$facts = DoughBoss_Square_Orders::validate_paid_response( $response, $projection, $payment, $attempt['provider_order_reference'], $expected_tender );
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
	 * Strict public tender selection. Omitted input preserves card checkout.
	 *
	 * @param mixed $value Exact card/gift_card selection, or null.
	 * @return string|WP_Error
	 */
	public static function tender_kind( $value ) {
		if ( null === $value || 'card' === $value ) { return 'card'; }
		if ( 'gift_card' === $value ) { return 'gift_card'; }
		return new WP_Error( 'doughboss_pay_tender', __( 'Choose card or Square gift card payment.', 'doughboss' ), array( 'status' => 400, 'retry_safe' => true, 'payment_pending' => false ) );
	}

	/**
	 * Gift-card intent is immutable, but is not evidence of funding. Require
	 * Square's own tender facts on every create/replay/read. Historical card
	 * attempts retain their existing contract; no provider card data is stored.
	 */
	private static function payment_tender_matches( array $payment, array $attempt ) {
		$metadata = self::attempt_metadata( $attempt );
		$details = isset( $payment['card_details'] ) && is_array( $payment['card_details'] ) ? $payment['card_details'] : array();
		$card = isset( $details['card'] ) && is_array( $details['card'] ) ? $details['card'] : array();
		$kind = array_key_exists( 'square_tender_kind', $metadata ) ? $metadata['square_tender_kind'] : 'card';
		if ( 'card' === $kind ) {
			// Preserve older sparse CARD responses, but never accept explicit
			// contradictory source or Square gift-card evidence as bank-card proof.
			return ( ! array_key_exists( 'source_type', $payment ) || 'CARD' === $payment['source_type'] )
				&& ( ! isset( $card['card_brand'] ) || 'SQUARE_GIFT_CARD' !== $card['card_brand'] );
		}
		if ( 'gift_card' !== $metadata['square_tender_kind'] ) { return false; }
		$money = isset( $payment['amount_money'] ) && is_array( $payment['amount_money'] ) ? $payment['amount_money'] : array();
		return isset( $payment['source_type'], $card['card_brand'], $money['amount'], $money['currency'] )
			&& 'CARD' === $payment['source_type'] && 'SQUARE_GIFT_CARD' === $card['card_brand']
			&& is_int( $money['amount'] ) && $money['amount'] === (int) $attempt['amount_minor']
			&& $money['currency'] === strtoupper( (string) $attempt['currency'] )
			&& ( 'COMPLETED' !== ( isset( $payment['status'] ) ? $payment['status'] : '' )
				|| ( isset( $details['status'] ) && 'CAPTURED' === $details['status'] ) );
	}

	/**
	 * Take one full-value card or Square Gift Card payment. All economics and
	 * shop/approval bindings come from the server; only the SDK nonce and tender
	 * selection are browser inputs. Neither nonce nor provider card data persists.
	 *
	 * @param int    $amount_minor Server-computed cents.
	 * @param string $currency     Server-computed ISO currency.
	 * @param array  $metadata     Checkout binding plus transient SDK input.
	 * @return array|WP_Error
	 */
	public static function create_payment_intent( $amount_minor, $currency, array $metadata = array() ) {
		global $wpdb;
		$tender_kind = self::tender_kind( array_key_exists( 'tender_kind', $metadata ) ? $metadata['tender_kind'] : null );
		if ( is_wp_error( $tender_kind ) ) { return $tender_kind; }
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
			$safe_metadata['tender_kind'],
			$safe_metadata['square_tender_kind'],
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
		$safe_metadata['square_tender_kind'] = $tender_kind;

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
		$stored_tender = array_key_exists( 'square_tender_kind', $stored_metadata ) ? $stored_metadata['square_tender_kind'] : 'card';
		if ( $stored_tender !== $tender_kind ) {
			return self::pending_error( $attempt, 'doughboss_pay_tender_changed' );
		}
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
			if ( is_wp_error( $linked ) && 'approved_preorder' !== $context && 'doughboss_pay_api' === $linked->get_error_code() ) {
				// CreateOrder runs before any payment request: Square refused it,
				// so no money can have moved. Close the attempt and let the
				// customer retry with a fresh attempt key.
				if ( DoughBoss_Payment_Attempts::mark_irreversible_failed( (int) $attempt['id'], 'doughboss_pay_order_create' ) ) {
					return self::no_charge_error( $attempt, 'doughboss_pay_order_create', __( 'We could not start the card payment. No payment was taken — please try again.', 'doughboss' ) );
				}
			}
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
		// Customer email from the retained, hash-verified checkout snapshot so
		// Square can attach the buyer to the payment (receipts, disputes, CRM).
		$buyer_email = self::buyer_email( $attempt, $orders_protocol );
		if ( '' !== $buyer_email ) {
			$body['buyer_email_address'] = $buyer_email;
		}
		if ( 'gift_card' === $tender_kind ) {
			// This slice is full-value only. Never create an untracked partial leg.
			$body['accept_partial_authorization'] = false;
		}
		if ( '' !== $verification ) {
			$body['verification_token'] = $verification;
		}

		$response = self::request( 'POST', '/v2/payments', $body, $dispatch_binding );
		if ( is_wp_error( $response ) && self::is_payment_method_refusal( $response ) ) {
			// Square answered with a definitive card/payment-method refusal
			// (declined, bad CVV, expired, insufficient funds). No payment
			// completed, so the attempt closes as failed and the customer may
			// retry with a new attempt key (and therefore a new idempotency key).
			if ( DoughBoss_Payment_Attempts::mark_irreversible_failed( (int) $attempt['id'], 'doughboss_pay_declined' ) ) {
				return self::no_charge_error( $attempt, 'doughboss_pay_declined', __( 'Your card was declined. Please check the details or try another card. You have not been charged.', 'doughboss' ) );
			}
		}
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
	 * Whether a Square API error is a definitive payment-method refusal.
	 *
	 * Only Square's PAYMENT_METHOD_ERROR category on HTTP 400/402 qualifies —
	 * timeouts, 5xx, rate limits, idempotency conflicts and malformed replies
	 * stay "unknown" because money may have moved.
	 *
	 * @param WP_Error $error Error from request().
	 * @return bool
	 */
	/**
	 * Pure: what a re-read Square refund means for the bound payment attempt.
	 * 'full' only when COMPLETED, same payment, same currency, full amount.
	 *
	 * @param array $refund  Square refund object.
	 * @param array $attempt Attempt row.
	 * @return string full|partial|none
	 */
	public static function refund_settlement( array $refund, array $attempt ) {
		$money = isset( $refund['amount_money'] ) && is_array( $refund['amount_money'] ) ? $refund['amount_money'] : array();
		if ( ! isset( $refund['status'], $refund['payment_id'], $money['amount'], $money['currency'], $attempt['provider_reference'], $attempt['amount_minor'], $attempt['currency'] )
			|| 'COMPLETED' !== $refund['status']
			|| ! hash_equals( (string) $attempt['provider_reference'], (string) $refund['payment_id'] )
			|| strtoupper( (string) $attempt['currency'] ) !== strtoupper( (string) $money['currency'] )
			|| (int) $money['amount'] < 1 ) {
			return 'none';
		}
		return (int) $money['amount'] >= (int) $attempt['amount_minor'] ? 'full' : 'partial';
	}

	/**
	 * Webhook: a refund was created/updated (in WP, Square Dashboard or POS).
	 * Re-reads the refund from Square and marks the WP order refunded only for
	 * a COMPLETED full refund of a payment this site owns. Partial refunds are
	 * acknowledged and left for staff (the order keeps its paid state).
	 *
	 * @param string $refund_id      Square refund id.
	 * @param string $payment_hint   payment_id from the (untrusted) payload.
	 * @return string processed|ignored|partial|pending|retry
	 */
	public static function reconcile_refund_event( $refund_id, $payment_hint ) {
		$refund_id    = self::canonical_id( $refund_id );
		$payment_hint = self::canonical_id( $payment_hint );
		if ( '' === $refund_id || '' === $payment_hint ) {
			return 'ignored';
		}
		$attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $payment_hint );
		if ( ! is_array( $attempt ) || 'square' !== (string) $attempt['provider'] ) {
			return 'ignored';
		}
		$binding = self::attempt_shop_binding( $attempt );
		if ( is_wp_error( $binding ) ) {
			return 'retry';
		}
		$response = self::request( 'GET', '/v2/refunds/' . rawurlencode( $refund_id ), array(), $binding );
		if ( is_wp_error( $response ) || ! isset( $response['refund'] ) || ! is_array( $response['refund'] ) ) {
			return 'retry';
		}
		$refund = $response['refund'];
		if ( ! isset( $refund['id'] ) || ! hash_equals( $refund_id, (string) $refund['id'] ) ) {
			return 'ignored';
		}
		$settled = self::refund_settlement( $refund, $attempt );
		if ( 'none' === $settled ) {
			return isset( $refund['status'] ) && in_array( $refund['status'], array( 'PENDING', 'APPROVED' ), true ) ? 'pending' : 'ignored';
		}
		if ( 'partial' === $settled ) {
			return 'partial';
		}
		$order_id = DoughBoss_Order::find_id_by_payment_intent( $payment_hint );
		if ( ! $order_id ) {
			return 'ignored';
		}
		$order = DoughBoss_Order::get( $order_id );
		if ( $order && 'paid' === (string) $order->payment_status ) {
			DoughBoss_Order::update_payment_status( $order_id, 'refunded' );
		}
		return 'processed';
	}

	/**
	 * Webhook: a card dispute (chargeback) was opened or changed. Re-reads it
	 * and alerts the orders inbox for a payment this site owns. No money or
	 * order state changes — evidence must be submitted in Square Dashboard.
	 *
	 * @param string $dispute_id   Square dispute id.
	 * @param string $payment_hint payment_id from the payload.
	 * @param string $type         Event type.
	 * @return string processed|ignored|retry
	 */
	public static function reconcile_dispute_event( $dispute_id, $payment_hint, $type ) {
		$dispute_id   = self::canonical_id( $dispute_id );
		$payment_hint = self::canonical_id( $payment_hint );
		if ( '' === $dispute_id || '' === $payment_hint ) {
			return 'ignored';
		}
		$attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $payment_hint );
		if ( ! is_array( $attempt ) || 'square' !== (string) $attempt['provider'] ) {
			return 'ignored';
		}
		$binding = self::attempt_shop_binding( $attempt );
		if ( is_wp_error( $binding ) ) {
			return 'retry';
		}
		$response = self::request( 'GET', '/v2/disputes/' . rawurlencode( $dispute_id ), array(), $binding );
		if ( is_wp_error( $response ) || ! isset( $response['dispute'] ) || ! is_array( $response['dispute'] ) ) {
			return 'retry';
		}
		$d       = $response['dispute'];
		$paid_id = isset( $d['disputed_payment']['payment_id'] ) ? (string) $d['disputed_payment']['payment_id'] : '';
		if ( ! hash_equals( $payment_hint, $paid_id ) ) {
			return 'ignored';
		}
		$order_id = DoughBoss_Order::find_id_by_payment_intent( $payment_hint );
		$amount   = isset( $d['amount_money']['amount'] ) ? number_format( ( (int) $d['amount_money']['amount'] ) / 100, 2 ) : '?';
		$lines    = array(
			'A customer has disputed a card payment (chargeback). Respond in Square Dashboard > Payments > Disputes before the deadline.',
			'',
			'Event: ' . sanitize_text_field( (string) $type ),
			'Dispute state: ' . ( isset( $d['state'] ) ? sanitize_text_field( (string) $d['state'] ) : 'unknown' ),
			'Reason: ' . ( isset( $d['reason'] ) ? sanitize_text_field( (string) $d['reason'] ) : 'unknown' ),
			'Amount: $' . $amount,
			'Respond by: ' . ( isset( $d['due_at'] ) ? sanitize_text_field( (string) $d['due_at'] ) : 'see Square' ),
			'WordPress order: ' . ( $order_id ? '#' . $order_id : 'not found' ),
			'Square payment: ' . $payment_hint,
		);
		$to = DoughBoss_Settings::orders_email();
		if ( is_email( $to ) ) {
			wp_mail( $to, sprintf( '[DoughBoss] Card dispute %s — $%s', $order_id ? 'order #' . $order_id : $payment_hint, $amount ), implode( "\n", $lines ) );
		}
		return 'processed';
	}

	/**
	 * Buyer email for an itemised attempt, read only from its verified snapshot.
	 *
	 * @param array $attempt         Attempt row.
	 * @param int   $orders_protocol Orders protocol (1 = itemised).
	 * @return string Valid email or ''.
	 */
	private static function buyer_email( array $attempt, $orders_protocol ) {
		if ( 1 !== $orders_protocol || ! class_exists( 'DoughBoss_Checkout_Snapshots' ) || empty( $attempt['checkout_key'] ) ) {
			return '';
		}
		$snapshot = DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( $attempt['checkout_key'] );
		$metadata = self::attempt_metadata( $attempt );
		if ( ! is_array( $snapshot ) || ! isset( $snapshot['payload']['order']['customer_email'], $snapshot['payload_hash'], $metadata['orders_snapshot_hash'] )
			|| ! hash_equals( (string) $metadata['orders_snapshot_hash'], (string) $snapshot['payload_hash'] ) ) {
			return '';
		}
		return self::safe_buyer_email( $snapshot['payload']['order']['customer_email'] );
	}

	/**
	 * Square caps buyer_email_address at 255 chars; anything odd is dropped.
	 *
	 * @param mixed $email Candidate.
	 * @return string
	 */
	public static function safe_buyer_email( $email ) {
		$email = is_string( $email ) ? trim( $email ) : '';
		return '' !== $email && strlen( $email ) <= 255 && is_email( $email ) ? $email : '';
	}

	public static function is_payment_method_refusal( $error ) {
		if ( ! is_wp_error( $error ) || 'doughboss_pay_api' !== $error->get_error_code() ) {
			return false;
		}
		$data = $error->get_error_data();
		return is_array( $data )
			&& isset( $data['square_category'], $data['square_http'] )
			&& 'PAYMENT_METHOD_ERROR' === $data['square_category']
			&& in_array( (int) $data['square_http'], array( 400, 402 ), true );
	}

	/**
	 * Retry-safe error for an attempt that provably moved no money.
	 *
	 * @param array  $attempt Attempt row.
	 * @param string $code    Error code.
	 * @param string $message Customer message.
	 * @return WP_Error
	 */
	private static function no_charge_error( array $attempt, $code, $message ) {
		return new WP_Error(
			$code,
			$message,
			array(
				'status'          => 402,
				'outcome'         => 'failed',
				'payment_pending' => false,
				'retry_safe'      => true,
				'attempt_id'      => isset( $attempt['id'] ) ? (int) $attempt['id'] : 0,
			)
		);
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
	 * Recover exact APPROVED split-leg facts by known ID, never charge or release.
	 * Internal only: no public route, source token, capture or fulfillment proof.
	 * A held set may return to funding after exact observation; that is storage
	 * state, not current provider/approval authority. Cancellation stays closed.
	 *
	 * @return array|WP_Error Minimized durable plan, never raw provider card data.
	 */
	public static function reconcile_split_authorization( $attempt_id, $version, $index, $payment_id ) {
		if ( ! is_int( $attempt_id ) || $attempt_id <= 0 || ! is_int( $version ) || $version <= 0 || $version >= PHP_INT_MAX
			|| ! is_int( $index ) || ! in_array( $index, array( 0, 1 ), true )
			|| ! is_string( $payment_id ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{8,191}\\z/', $payment_id )
			|| 1 === preg_match( '/^[0-9]{13,19}\\z/', $payment_id ) ) {
			return self::split_recovery_error();
		}
		$attempt = DoughBoss_Payment_Attempts::find( $attempt_id );
		$plan = is_array( $attempt ) ? DoughBoss_Payment_Legs::read( $attempt ) : false;
		if ( ! is_array( $plan ) || $version !== (int) $plan['set']['version'] || ! isset( $plan['legs'][ $index ] ) ) {
			return self::split_recovery_error();
		}
		$leg = $plan['legs'][ $index ];
		if ( ! in_array( $leg['state'], array( 'claimed', 'unknown', 'authorized' ), true )
			|| ( 'authorized' === $leg['state'] && $payment_id !== $leg['provider_payment_reference'] ) ) {
			return self::split_recovery_error();
		}
		$binding = self::attempt_shop_binding( $attempt );
		$configuration = self::settings_context_version();
		if ( is_wp_error( $binding ) || is_wp_error( $configuration ) || ! DoughBoss_Settings::square_access_token_valid() ) {
			return self::split_recovery_error();
		}
		$token = self::access_token();
		// The locking read has committed: provider I/O must never hold ledger locks.
		$payment = self::retrieve_mapped_payment( $payment_id, $binding );
		if ( is_wp_error( $payment ) || ! isset( $payment['id'] ) || $payment_id !== $payment['id']
			|| false === DoughBoss_Payment_Legs::validate_observation( $attempt, $leg, $payment ) ) {
			return self::split_recovery_error();
		}
		// Pre/post-read context consistency, NOT an atomic seller/capture lock.
		$current_configuration = self::settings_context_version();
		$current_binding = self::attempt_shop_binding( $attempt );
		if ( is_wp_error( $current_configuration ) || ! hash_equals( $configuration, $current_configuration )
			|| is_wp_error( $current_binding ) || $binding !== $current_binding || ! hash_equals( $token, self::access_token() ) ) {
			return self::split_recovery_error();
		}
		// Reacquire parent/snapshot/set/leg locks and recheck exact version/identity.
		$result = DoughBoss_Payment_Legs::observe( $attempt, $version, $index, $payment );
		return is_array( $result ) ? $result : self::split_recovery_error();
	}

	private static function split_recovery_error() {
		return new WP_Error( 'doughboss_split_recovery_pending', __( 'The authorization could not be verified. Do not pay again; contact the shop.', 'doughboss' ),
			array( 'status' => 409, 'payment_pending' => true, 'retry_safe' => false ) );
	}

	/** Retrieve a v2 payment using an already validated mapped shop binding. */
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
			&& self::payment_tender_matches( $payment, $attempt )
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
			|| ! self::payment_tender_matches( $payment, $attempt )
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
	 * A refund cannot use a request-local option cache as current authority.
	 * Read the stored configuration directly and require the getters used by
	 * dispatch to observe those same values. Only a digest leaves this helper;
	 * credentials and raw options are never persisted, returned or logged.
	 * Existing environment-secret precedence remains in the normal accessors.
	 *
	 * @return string|WP_Error Current stored configuration identity.
	 */
	private static function settings_context_version() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", DoughBoss_Settings::OPTION_KEY ) );
		$stored = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
		if ( ! is_string( $raw ) || ! is_array( $stored ) || $stored !== get_option( DoughBoss_Settings::OPTION_KEY, array() ) ) {
			return new WP_Error( 'doughboss_refund_context_changed', __( 'The original payment configuration could not be verified. Check Square before issuing another refund; this order has not been marked refunded.', 'doughboss' ), array( 'status' => 409 ) );
		}
		return hash( 'sha256', $raw );
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
		$configuration_version = self::settings_context_version();
		if ( is_wp_error( $configuration_version ) ) {
			return $configuration_version;
		}
		$binding = self::attempt_shop_binding( $attempt );
		if ( is_wp_error( $binding ) ) {
			return $binding;
		}
		$token = self::access_token();

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
			),
			$binding
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		// The provider may already have acted. A changed original-payment or
		// seller/environment context is an unknown outcome, never permission to
		// mark the order refunded or dispatch a differently bound retry.
		$current_configuration = self::settings_context_version();
		$current_attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $id );
		$current_binding = is_array( $current_attempt ) ? self::attempt_shop_binding( $current_attempt ) : self::shop_binding_error();
		$unchanged = ! is_wp_error( $current_configuration ) && hash_equals( $configuration_version, $current_configuration )
			&& ! is_wp_error( $current_binding ) && $binding === $current_binding && hash_equals( $token, self::access_token() );
		foreach ( array( 'id', 'provider', 'provider_reference', 'location_id', 'amount_minor', 'currency', 'safe_metadata_json' ) as $field ) {
			if ( ! isset( $attempt[ $field ], $current_attempt[ $field ] ) || (string) $attempt[ $field ] !== (string) $current_attempt[ $field ] ) {
				$unchanged = false;
			}
		}
		if ( ! $unchanged ) {
			return new WP_Error( 'doughboss_refund_context_changed', __( 'The Square refund outcome needs reconciliation because the original payment configuration changed. Check Square before issuing another refund; this order has not been marked refunded.', 'doughboss' ), array( 'status' => 409 ) );
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
	 * Square APIs that operator-initiated commerce calls (catering invoices,
	 * customer lookup and loyalty accrual) may reach. Payments and refunds keep
	 * their dedicated, attempt-bound methods above and are deliberately absent.
	 */
	const COMMERCE_PATH_PREFIXES = array( '/v2/customers', '/v2/orders', '/v2/invoices', '/v2/loyalty' );

	/**
	 * Location-bound Square call for catering invoices and loyalty.
	 *
	 * Uses the same activation gate as card payments for the exact native shop
	 * (payments on, Square selected, mapped location, valid token, live approval
	 * in live mode) and only reaches allow-listed API families.
	 *
	 * @param string $method      HTTP method.
	 * @param string $path        API path, e.g. '/v2/invoices'.
	 * @param array  $body        JSON body for write calls.
	 * @param int    $location_id Native DoughBoss location id.
	 * @return array|WP_Error
	 */
	public static function commerce_request( $method, $path, array $body, $location_id ) {
		$path    = (string) $path;
		$allowed = false;
		foreach ( self::COMMERCE_PATH_PREFIXES as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed || false !== strpos( $path, '..' ) || ! preg_match( '#^/v2/[A-Za-z0-9/_-]+$#', $path ) ) {
			return new WP_Error( 'doughboss_pay_config', __( 'That Square operation is not available.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$binding = self::commerce_binding( $location_id );
		if ( is_wp_error( $binding ) ) {
			return $binding;
		}
		return self::request( $method, $path, $body, $binding );
	}

	/**
	 * Active-mode Square binding for a shop, but only when that shop is fully
	 * Square-ready. Callers use square_location_id to build request bodies.
	 *
	 * @param int $location_id Native DoughBoss location id.
	 * @return array|WP_Error { shop_slug, square_location_id, square_mode }.
	 */
	public static function commerce_binding( $location_id ) {
		$binding = self::shop_binding( $location_id );
		if ( is_wp_error( $binding ) ) {
			return $binding;
		}
		if ( ! DoughBoss_Settings::square_shop_ready( $binding['shop_slug'] ) ) {
			return new WP_Error( 'doughboss_pay_config', __( 'Square is not configured for this shop.', 'doughboss' ), array( 'status' => 503 ) );
		}
		return $binding;
	}

	/**
	 * Perform an authenticated request to the Square API.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   API path beginning with '/'.
	 * @param array      $body         JSON body for write calls.
	 * @param array|null $shop_binding Validated v2 mapping context, when present.
	 * @param bool $require_object Validate wire object/list containers for catalogue reads only.
	 * @return array|WP_Error Decoded JSON, or an error.
	 */
	private static function request( $method, $path, array $body = array(), $shop_binding = null, $require_object = false ) {
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
		if ( $require_object ) { $args['limit_response_size'] = self::CATALOGUE_MAX_RESPONSE_BYTES + 1; }
		if ( 'GET' !== $args['method'] && ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::api_host() . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'doughboss_pay_network', __( 'Could not reach the payment service. Do not pay again until its status is checked.', 'doughboss' ), array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		if ( $require_object ) {
			if ( ! is_string( $response_body ) || strlen( $response_body ) > self::CATALOGUE_MAX_RESPONSE_BYTES ) { return self::catalogue_error(); }
			$data = self::catalogue_decode( $response_body );
			if ( is_wp_error( $data ) ) { return $data; }
		} else { $data = json_decode( $response_body, true ); }

		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			return $data;
		}

		// Log only the HTTP status and Square's short error category/code for the
		// operator — never the response body or `detail`, both of which can carry
		// customer PII or decline specifics.
		$category   = '';
		$error_code = '';
		if ( is_array( $data ) && isset( $data['errors'][0] ) && is_array( $data['errors'][0] ) ) {
			$first      = $data['errors'][0];
			$category   = isset( $first['category'] ) && is_scalar( $first['category'] ) ? substr( strtoupper( preg_replace( '/[^A-Za-z_]/', '', (string) $first['category'] ) ), 0, 64 ) : '';
			$error_code = isset( $first['code'] ) && is_scalar( $first['code'] ) ? substr( strtoupper( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $first['code'] ) ), 0, 64 ) : '';
		}
		if ( function_exists( 'error_log' ) ) {
			error_log( 'DoughBoss Square error: HTTP ' . $code . ' category=' . strtolower( $category ) . ' code=' . strtolower( $error_code ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return new WP_Error(
			'doughboss_pay_api',
			__( 'We could not complete the card payment. Please try again or contact the shop.', 'doughboss' ),
			array(
				'status'          => 502,
				'square_http'     => $code,
				'square_category' => $category,
				'square_code'     => $error_code,
			)
		);
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
