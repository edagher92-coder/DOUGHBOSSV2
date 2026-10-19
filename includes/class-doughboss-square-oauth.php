<?php
/**
 * Square OAuth (code flow) — one website, one Square seller account per shop.
 *
 * Each Dough Boss shop is its own legal entity with its own Square seller
 * account. Square cannot merge seller accounts, so the website acts as an OAuth
 * application (the existing DoughBoss Square developer application) that each
 * shop's seller authorizes. Per shop and per Square environment this stores:
 *
 *   { merchant_id, access_token, refresh_token, expires_at, scopes,
 *     location_id, status, connected_at, refreshed_at, failures, ... }
 *
 * in its own non-autoloaded option. Tokens are never rendered, logged or
 * returned to a browser. When DOUGHBOSS_SQUARE_TOKEN_KEY (wp-config constant or
 * environment variable) is set, tokens are additionally encrypted at rest with
 * libsodium secretbox; without it they are stored as-is (not autoloaded).
 *
 * A shop that is not connected keeps using the legacy single-account settings
 * (application id + access token + location map), so Revesby keeps working
 * with zero configuration.
 *
 * Square references (checked 2026-10):
 *  - Code flow for confidential server apps; code-flow refresh tokens do not
 *    expire: https://developer.squareup.com/docs/oauth-api/overview
 *  - Authorize parameters (scope space-separated, state, session=false has no
 *    effect in Sandbox): https://developer.squareup.com/reference/square/oauth-api/authorize
 *  - ObtainToken / refresh body, refresh "every 7 days or less", RevokeToken
 *    with `Authorization: Client <secret>`:
 *    https://developer.squareup.com/docs/oauth-api/refresh-revoke-limit-scope
 *  - Store tokens encrypted, keep the application secret out of source:
 *    https://developer.squareup.com/docs/oauth-api/best-practices
 *  - oauth.authorization.revoked payload (top-level merchant_id):
 *    https://developer.squareup.com/reference/square/oauth-api/webhooks/oauth.authorization.revoked
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-shop Square OAuth connections.
 */
class DoughBoss_Square_OAuth {

	/** Non-autoloaded connection store: [ mode => [ shop => record ], 'legacy_merchant' => [ mode => id ] ]. */
	const OPTION = 'doughboss_square_oauth';

	/** Non-autoloaded application secret store (write-only from the admin UI). */
	const SECRET_OPTION = 'doughboss_square_oauth_secret';

	/** Non-autoloaded owner notices raised by background events (revocation, refresh failure). */
	const NOTICE_OPTION = 'doughboss_square_oauth_notices';

	/** WP-Cron hook that keeps tokens fresh. */
	const REFRESH_HOOK = 'doughboss_square_oauth_refresh';

	/** Shops that may be connected. */
	const SHOPS = array( 'revesby', 'bankstown', 'roselands' );

	/**
	 * Permissions requested from each seller. Exactly the API families this
	 * plugin calls (payments/refunds, orders, customers, invoices, loyalty,
	 * catalogue, subscriptions, locations) — Square's least-privilege guidance.
	 */
	const SCOPES = array(
		'PAYMENTS_WRITE',
		'PAYMENTS_READ',
		'ORDERS_WRITE',
		'ORDERS_READ',
		'CUSTOMERS_WRITE',
		'CUSTOMERS_READ',
		'INVOICES_WRITE',
		'INVOICES_READ',
		'LOYALTY_READ',
		'LOYALTY_WRITE',
		'ITEMS_READ',
		'ITEMS_WRITE',
		'SUBSCRIPTIONS_READ',
		'MERCHANT_PROFILE_READ',
	);

	/** Square code-flow access tokens live 30 days; refresh once a token is ~7 days old. */
	const REFRESH_WINDOW = 1987200; // 23 days of remaining life.

	/** Refresh at least this often regardless of expiry. */
	const REFRESH_MAX_AGE = 604800; // 7 days.

	/** On-demand refresh threshold inside a request. */
	const URGENT_WINDOW = 86400; // 1 day.

	/** Owner warning when a token has not refreshed for this long (Square: alert after 8 days). */
	const STALE_AGE = 691200; // 8 days.

	/** Signed state lifetime. Square's authorization code itself lives 5 minutes. */
	const STATE_TTL = 900;

	/* ------------------------------------------------------------------ */
	/* Hooks                                                               */
	/* ------------------------------------------------------------------ */

	/** @return void */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( self::REFRESH_HOOK, array( __CLASS__, 'refresh_all' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/** Keep the refresh event booked only while at least one shop is connected. */
	public static function schedule() {
		// Connections only change in wp-admin, the webhook or cron; skip storefront requests.
		if ( current_action() === 'init' && ! is_admin() && ! ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			return;
		}
		$next = wp_next_scheduled( self::REFRESH_HOOK );
		if ( ! self::any_connection() ) {
			if ( $next ) {
				wp_unschedule_event( $next, self::REFRESH_HOOK );
			}
			return;
		}
		if ( ! $next ) {
			wp_schedule_event( time() + 300, 'twicedaily', self::REFRESH_HOOK );
		}
	}

	/** Public OAuth redirect target. It only bounces to wp-admin, where the owner's session is checked. */
	public static function register_routes() {
		register_rest_route(
			DOUGHBOSS_REST_NAMESPACE,
			'/square-oauth/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_callback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /square-oauth/callback — Square's redirect URL. WordPress REST cookie
	 * authentication needs a REST nonce Square cannot send, so nothing is
	 * exchanged here: the shape-checked parameters are bounced to
	 * admin-post.php, where the logged-in owner, the signed state and its
	 * single-use nonce are all verified before the code is exchanged.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_callback( $request ) {
		$args  = array( 'action' => 'doughboss_square_oauth_finish' );
		$code  = (string) $request->get_param( 'code' );
		$state = (string) $request->get_param( 'state' );
		$error = (string) $request->get_param( 'error' );
		if ( 1 === preg_match( '/^[A-Za-z0-9_-]{1,512}$/D', $code ) ) {
			$args['code'] = $code;
		}
		if ( 1 === preg_match( '/^[A-Za-z0-9_.-]{1,2048}$/D', $state ) ) {
			$args['state'] = $state;
		}
		if ( '' !== $error ) {
			$args['error'] = substr( preg_replace( '/[^a-z_]/', '', strtolower( $error ) ), 0, 64 );
		}
		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin-post.php' ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Referrer-Policy', 'no-referrer' );
		return $response;
	}

	/** Exact redirect URL to register in the Square Developer Console (OAuth page). */
	public static function redirect_url() {
		return function_exists( 'rest_url' ) ? (string) rest_url( DOUGHBOSS_REST_NAMESPACE . '/square-oauth/callback' ) : '';
	}

	/* ------------------------------------------------------------------ */
	/* Pure helpers (unit tested)                                          */
	/* ------------------------------------------------------------------ */

	/** @return bool */
	public static function valid_shop( $shop ) {
		return is_string( $shop ) && in_array( $shop, self::SHOPS, true );
	}

	/** @return bool */
	public static function valid_mode( $mode ) {
		return is_string( $mode ) && in_array( $mode, array( 'test', 'live' ), true );
	}

	/** @return bool Square ids (merchant, location) are short opaque tokens. */
	public static function valid_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_-]{4,64}$/D', $id );
	}

	/** @return bool Bearer-token shape; mirrors DoughBoss_Settings::square_access_token_valid(). */
	public static function valid_token( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[A-Za-z0-9_.-]{16,1024}$/D', $token );
	}

	/** Authorization endpoint host for a mode. */
	public static function host( $mode ) {
		return 'live' === $mode ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
	}

	/**
	 * Build the Square authorization page URL.
	 *
	 * `session=false` forces the seller to sign in to Square even with an
	 * active session, which is what makes connecting three different seller
	 * accounts from one browser safe. Square documents that it has no effect in
	 * the Sandbox, so it is only sent in production.
	 *
	 * @param string $mode           test|live.
	 * @param string $application_id Square application id (client_id).
	 * @param string $state          Signed state.
	 * @return string Empty when inputs are invalid.
	 */
	public static function authorize_url( $mode, $application_id, $state ) {
		if ( ! self::valid_mode( $mode ) || ! is_string( $application_id ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{8,191}$/D', $application_id )
			|| ! is_string( $state ) || 1 !== preg_match( '/^[A-Za-z0-9_.-]{16,2048}$/D', $state ) ) {
			return '';
		}
		$query = 'client_id=' . rawurlencode( $application_id )
			. '&scope=' . rawurlencode( implode( ' ', self::SCOPES ) );
		if ( 'live' === $mode ) {
			$query .= '&session=false';
		}
		$query .= '&state=' . rawurlencode( $state );
		return self::host( $mode ) . '/oauth2/authorize?' . $query;
	}

	/** @return string */
	private static function b64url_encode( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/** @return string|false */
	private static function b64url_decode( $text ) {
		if ( ! is_string( $text ) || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/D', $text ) ) {
			return false;
		}
		$pad = strlen( $text ) % 4;
		return base64_decode( strtr( $text, '-_', '+/' ) . ( $pad ? str_repeat( '=', 4 - $pad ) : '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Create a signed, expiring state bound to one shop, one WordPress user and
	 * one Square environment. The nonce is additionally recorded server-side
	 * and consumed on first use (see start()/finish()).
	 *
	 * @param string $shop    Shop slug.
	 * @param string $mode    test|live.
	 * @param int    $user_id WordPress user id.
	 * @param string $nonce   Random single-use nonce (hex).
	 * @param int    $now     Unix time.
	 * @param string $key     HMAC key.
	 * @return string Empty on invalid input.
	 */
	public static function create_state( $shop, $mode, $user_id, $nonce, $now, $key ) {
		if ( ! self::valid_shop( $shop ) || ! self::valid_mode( $mode ) || ! is_int( $user_id ) || $user_id < 1
			|| ! is_string( $nonce ) || 1 !== preg_match( '/^[a-f0-9]{32,64}$/D', $nonce ) || ! is_int( $now ) || ! is_string( $key ) || '' === $key ) {
			return '';
		}
		$payload = self::b64url_encode( (string) wp_json_encode( array( 'v' => 1, 's' => $shop, 'm' => $mode, 'u' => $user_id, 'e' => $now + self::STATE_TTL, 'n' => $nonce ) ) );
		return $payload . '.' . self::b64url_encode( hash_hmac( 'sha256', 'doughboss-square-oauth|' . $payload, $key, true ) );
	}

	/**
	 * Verify a returned state. Signature first, then shape, expiry, user and
	 * environment. Never trusts any field before the MAC matches.
	 *
	 * @param mixed  $state        Returned state.
	 * @param int    $user_id      Current WordPress user.
	 * @param string $mode         Current Square environment.
	 * @param int    $now          Unix time.
	 * @param string $key          HMAC key.
	 * @return array|WP_Error { shop, mode, user, nonce }.
	 */
	public static function verify_state( $state, $user_id, $mode, $now, $key ) {
		$error = new WP_Error( 'doughboss_square_oauth_state', __( 'The Square connection request expired or did not come from this site. Start again from DoughBoss › Square accounts.', 'doughboss' ), array( 'status' => 400 ) );
		if ( ! is_string( $state ) || strlen( $state ) > 2048 || 1 !== substr_count( $state, '.' ) || ! is_string( $key ) || '' === $key ) {
			return $error;
		}
		list( $payload, $mac ) = explode( '.', $state );
		$given = self::b64url_decode( $mac );
		if ( false === $given || '' === $payload || ! hash_equals( hash_hmac( 'sha256', 'doughboss-square-oauth|' . $payload, $key, true ), $given ) ) {
			return $error;
		}
		$json = self::b64url_decode( $payload );
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['v'], $data['s'], $data['m'], $data['u'], $data['e'], $data['n'] ) || 1 !== $data['v']
			|| ! self::valid_shop( $data['s'] ) || ! self::valid_mode( $data['m'] ) || ! is_int( $data['u'] ) || ! is_int( $data['e'] )
			|| ! is_string( $data['n'] ) || 1 !== preg_match( '/^[a-f0-9]{32,64}$/D', $data['n'] ) ) {
			return $error;
		}
		if ( $data['e'] < $now || $data['e'] > $now + self::STATE_TTL || $data['u'] !== $user_id || $data['m'] !== $mode ) {
			return $error;
		}
		return array( 'shop' => $data['s'], 'mode' => $data['m'], 'user' => $data['u'], 'nonce' => $data['n'] );
	}

	/**
	 * Decide whether a stored connection needs a token refresh now.
	 *
	 * @param array $record Connection record.
	 * @param int   $now    Unix time.
	 * @return string skip|refresh|backoff
	 */
	public static function refresh_decision( array $record, $now ) {
		if ( ! isset( $record['status'] ) || 'active' !== $record['status'] || empty( $record['refresh_token'] ) ) {
			return 'skip';
		}
		$failures = isset( $record['failures'] ) ? max( 0, (int) $record['failures'] ) : 0;
		$last     = isset( $record['last_attempt_at'] ) ? (int) $record['last_attempt_at'] : 0;
		$expires  = isset( $record['expires_at'] ) ? (int) $record['expires_at'] : 0;
		$due      = $expires <= 0
			|| $expires - $now <= self::REFRESH_WINDOW
			|| $now - ( isset( $record['refreshed_at'] ) ? (int) $record['refreshed_at'] : 0 ) >= self::REFRESH_MAX_AGE;
		if ( ! $due ) {
			return 'skip';
		}
		if ( $failures > 0 ) {
			$wait = min( 6 * 3600, 900 * ( 1 << min( $failures, 5 ) ) );
			// An already-expired or nearly expired token is never held back for long.
			if ( $expires - $now > self::URGENT_WINDOW && $now - $last < $wait ) {
				return 'backoff';
			}
			if ( $now - $last < 60 ) {
				return 'backoff';
			}
		}
		return 'refresh';
	}

	/**
	 * Route a verified webhook event by its top-level merchant_id.
	 *
	 * @param mixed  $merchant_id     Payload merchant_id.
	 * @param array  $connections     [ shop => record ] for the active mode.
	 * @param string $legacy_merchant Detected merchant of the single-account settings token ('' unknown).
	 * @return array { route: shop|legacy|ignore, shop: string }
	 */
	public static function route_merchant( $merchant_id, array $connections, $legacy_merchant ) {
		if ( ! is_string( $merchant_id ) || '' === $merchant_id ) {
			// Square always sends merchant_id; an older/odd payload keeps 2.49 behaviour.
			return array( 'route' => 'legacy', 'shop' => '' );
		}
		if ( ! self::valid_id( $merchant_id ) ) {
			return array( 'route' => 'ignore', 'shop' => '' );
		}
		$known_inactive = false;
		foreach ( self::SHOPS as $shop ) {
			if ( ! isset( $connections[ $shop ] ) || ! is_array( $connections[ $shop ] ) || empty( $connections[ $shop ]['merchant_id'] ) ) {
				continue;
			}
			if ( hash_equals( (string) $connections[ $shop ]['merchant_id'], $merchant_id ) ) {
				if ( isset( $connections[ $shop ]['status'] ) && 'active' === $connections[ $shop ]['status'] ) {
					return array( 'route' => 'shop', 'shop' => $shop );
				}
				$known_inactive = true;
			}
		}
		if ( is_string( $legacy_merchant ) && '' !== $legacy_merchant ) {
			return hash_equals( $legacy_merchant, $merchant_id ) ? array( 'route' => 'legacy', 'shop' => '' ) : array( 'route' => 'ignore', 'shop' => '' );
		}
		// Legacy merchant not yet detected: a disconnected shop's events are
		// ignored; anything else may be the single-account (Revesby) seller.
		return $known_inactive ? array( 'route' => 'ignore', 'shop' => '' ) : array( 'route' => 'legacy', 'shop' => '' );
	}

	/**
	 * Validate an ObtainToken response (authorization_code or refresh_token).
	 *
	 * @param mixed  $data              Decoded JSON.
	 * @param string $expected_merchant Required merchant on refresh ('' on first exchange).
	 * @return array|WP_Error { access_token, refresh_token, merchant_id, expires_at }
	 */
	public static function token_response( $data, $expected_merchant = '' ) {
		$error = new WP_Error( 'doughboss_square_oauth_token', __( 'Square returned an unexpected token response. Nothing was saved.', 'doughboss' ), array( 'status' => 502 ) );
		if ( ! is_array( $data ) || isset( $data['errors'] ) || ! isset( $data['access_token'], $data['merchant_id'], $data['expires_at'], $data['refresh_token'] )
			|| ! self::valid_token( $data['access_token'] ) || ! self::valid_token( $data['refresh_token'] ) || ! self::valid_id( $data['merchant_id'] )
			|| ! is_string( $data['expires_at'] ) || ( isset( $data['token_type'] ) && 'bearer' !== strtolower( (string) $data['token_type'] ) )
			|| ( isset( $data['short_lived'] ) && true === $data['short_lived'] ) ) {
			return $error;
		}
		$expires = strtotime( $data['expires_at'] );
		if ( false === $expires || $expires < 1 ) {
			return $error;
		}
		if ( '' !== $expected_merchant && ! hash_equals( (string) $expected_merchant, $data['merchant_id'] ) ) {
			return $error;
		}
		return array( 'access_token' => $data['access_token'], 'refresh_token' => $data['refresh_token'], 'merchant_id' => $data['merchant_id'], 'expires_at' => (int) $expires );
	}

	/* ------------------------------------------------------------------ */
	/* Token sealing                                                       */
	/* ------------------------------------------------------------------ */

	/** @return string 32-byte key or '' when no at-rest key is configured / libsodium missing. */
	private static function seal_key() {
		$raw = '';
		if ( defined( 'DOUGHBOSS_SQUARE_TOKEN_KEY' ) && '' !== (string) constant( 'DOUGHBOSS_SQUARE_TOKEN_KEY' ) ) {
			$raw = (string) constant( 'DOUGHBOSS_SQUARE_TOKEN_KEY' );
		} else {
			$env = getenv( 'DOUGHBOSS_SQUARE_TOKEN_KEY' );
			$raw = false === $env ? '' : (string) $env;
		}
		if ( strlen( $raw ) < 16 || ! function_exists( 'sodium_crypto_secretbox' ) || ! function_exists( 'sodium_crypto_generichash' ) ) {
			return '';
		}
		return sodium_crypto_generichash( 'doughboss-square-oauth-v1|' . $raw, '', 32 );
	}

	/** @return string Stored form: "s1:<b64>" sealed, or "p1:<token>" plain. */
	public static function seal( $token ) {
		$key = self::seal_key();
		if ( '' === $key ) {
			return 'p1:' . $token;
		}
		$nonce = random_bytes( 24 );
		return 's1:' . self::b64url_encode( $nonce . sodium_crypto_secretbox( $token, $nonce, $key ) );
	}

	/** @return string Token, or '' when it cannot be opened (missing/rotated key, corrupt value). */
	public static function open( $stored ) {
		if ( ! is_string( $stored ) ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'p1:' ) ) {
			return substr( $stored, 3 );
		}
		if ( 0 !== strpos( $stored, 's1:' ) ) {
			return '';
		}
		$key = self::seal_key();
		$raw = self::b64url_decode( substr( $stored, 3 ) );
		if ( '' === $key || false === $raw || strlen( $raw ) < 25 ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, 24 ), substr( $raw, 0, 24 ), $key );
		return false === $plain ? '' : $plain;
	}

	/* ------------------------------------------------------------------ */
	/* Storage                                                             */
	/* ------------------------------------------------------------------ */

	/** @return array */
	private static function store() {
		$store = get_option( self::OPTION, array() );
		return is_array( $store ) ? $store : array();
	}

	/** @return void */
	private static function save_store( array $store ) {
		update_option( self::OPTION, $store, false );
	}

	/** Current Square environment. */
	private static function mode() {
		return DoughBoss_Settings::square_mode();
	}

	/**
	 * Raw connection records for a mode (tokens still sealed).
	 *
	 * @param string|null $mode Mode, default active.
	 * @return array [ shop => record ]
	 */
	public static function connections( $mode = null ) {
		$mode  = null === $mode ? self::mode() : $mode;
		$store = self::store();
		$rows  = isset( $store[ $mode ] ) && is_array( $store[ $mode ] ) ? $store[ $mode ] : array();
		$out   = array();
		foreach ( self::SHOPS as $shop ) {
			if ( isset( $rows[ $shop ] ) && is_array( $rows[ $shop ] ) ) {
				$out[ $shop ] = $rows[ $shop ];
			}
		}
		return $out;
	}

	/** @return array|null */
	public static function record( $shop, $mode = null ) {
		if ( ! self::valid_shop( $shop ) ) {
			return null;
		}
		$rows = self::connections( $mode );
		return isset( $rows[ $shop ] ) ? $rows[ $shop ] : null;
	}

	/** @return void */
	private static function put_record( $shop, $mode, $record ) {
		$store = self::store();
		if ( ! isset( $store[ $mode ] ) || ! is_array( $store[ $mode ] ) ) {
			$store[ $mode ] = array();
		}
		if ( null === $record ) {
			unset( $store[ $mode ][ $shop ] );
		} else {
			$store[ $mode ][ $shop ] = $record;
		}
		self::save_store( $store );
	}

	/** @return bool Whether any shop in any mode is connected (drives cron booking). */
	public static function any_connection() {
		foreach ( array( 'test', 'live' ) as $mode ) {
			foreach ( self::connections( $mode ) as $record ) {
				if ( isset( $record['status'] ) && 'active' === $record['status'] ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether this shop uses its own OAuth connection in the active mode. When
	 * true, its location and credentials come ONLY from the connection (never
	 * from the legacy settings), even if the token is currently unusable.
	 */
	public static function is_connected( $shop, $mode = null ) {
		$record = self::record( $shop, $mode );
		return is_array( $record ) && isset( $record['status'], $record['merchant_id'] ) && 'active' === $record['status'] && self::valid_id( $record['merchant_id'] );
	}

	/** @return string Connected merchant id or ''. */
	public static function merchant_id( $shop, $mode = null ) {
		return self::is_connected( $shop, $mode ) ? (string) self::record( $shop, $mode )['merchant_id'] : '';
	}

	/** @return string Picked Square location id or '' (connected but not yet picked = not ready). */
	public static function location_id( $shop, $mode = null ) {
		if ( ! self::is_connected( $shop, $mode ) ) {
			return '';
		}
		$record = self::record( $shop, $mode );
		return isset( $record['location_id'] ) && self::valid_id( $record['location_id'] ) ? $record['location_id'] : '';
	}

	/**
	 * Access token for a connected shop. Server-side only.
	 *
	 * @param string $shop          Shop slug.
	 * @param bool   $allow_refresh Refresh synchronously when within a day of expiry.
	 * @return string '' when not connected or unusable.
	 */
	public static function access_token( $shop, $allow_refresh = false ) {
		if ( ! self::is_connected( $shop ) ) {
			return '';
		}
		$record = self::record( $shop );
		if ( $allow_refresh && isset( $record['expires_at'] ) && (int) $record['expires_at'] - time() <= self::URGENT_WINDOW
			&& 'refresh' === self::refresh_decision( $record, time() ) ) {
			self::refresh( $shop, self::mode() );
			$record = self::record( $shop );
			if ( ! is_array( $record ) || 'active' !== ( isset( $record['status'] ) ? $record['status'] : '' ) ) {
				return '';
			}
		}
		if ( isset( $record['expires_at'] ) && (int) $record['expires_at'] <= time() ) {
			return '';
		}
		$token = self::open( isset( $record['access_token'] ) ? $record['access_token'] : '' );
		return self::valid_token( $token ) ? $token : '';
	}

	/** @return bool */
	public static function credential_valid( $shop ) {
		return '' !== self::access_token( $shop );
	}

	/** Detected merchant of the legacy single-account settings token for a mode ('' unknown). */
	public static function legacy_merchant( $mode = null ) {
		$mode  = null === $mode ? self::mode() : $mode;
		$store = self::store();
		$value = isset( $store['legacy_merchant'][ $mode ] ) ? $store['legacy_merchant'][ $mode ] : '';
		return self::valid_id( $value ) ? $value : '';
	}

	/* ------------------------------------------------------------------ */
	/* Application secret (env-first, write-only)                          */
	/* ------------------------------------------------------------------ */

	/** @return string */
	public static function application_secret( $mode = null ) {
		$mode  = null === $mode ? self::mode() : $mode;
		$const = 'live' === $mode ? 'DOUGHBOSS_SQUARE_LIVE_APP_SECRET' : 'DOUGHBOSS_SQUARE_TEST_APP_SECRET';
		if ( defined( $const ) && '' !== (string) constant( $const ) ) {
			return (string) constant( $const );
		}
		$env = getenv( $const );
		if ( false !== $env && '' !== $env ) {
			return (string) $env;
		}
		$stored = get_option( self::SECRET_OPTION, array() );
		return is_array( $stored ) && isset( $stored[ $mode ] ) && is_string( $stored[ $mode ] ) ? $stored[ $mode ] : '';
	}

	/** @return bool Whether the secret comes from wp-config/environment (the UI then hides the field). */
	public static function application_secret_from_env( $mode ) {
		$const = 'live' === $mode ? 'DOUGHBOSS_SQUARE_LIVE_APP_SECRET' : 'DOUGHBOSS_SQUARE_TEST_APP_SECRET';
		$env   = getenv( $const );
		return ( defined( $const ) && '' !== (string) constant( $const ) ) || ( false !== $env && '' !== $env );
	}

	/** @return bool */
	public static function save_application_secret( $mode, $secret ) {
		if ( ! self::valid_mode( $mode ) || ! is_string( $secret ) || ( '' !== $secret && 1 !== preg_match( '/^[A-Za-z0-9_-]{16,256}$/D', $secret ) ) ) {
			return false;
		}
		$stored = get_option( self::SECRET_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$stored[ $mode ] = $secret;
		update_option( self::SECRET_OPTION, $stored, false );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Square OAuth endpoints                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * One OAuth API call. Bodies and responses are never logged.
	 *
	 * @param string $mode    Mode.
	 * @param string $path    /oauth2/token | /oauth2/revoke | /v2/locations.
	 * @param array  $body    JSON body (POST).
	 * @param array  $headers Extra headers.
	 * @param string $method  HTTP method.
	 * @return array { code:int, data:array|null }|WP_Error
	 */
	private static function call( $mode, $path, array $body, array $headers, $method = 'POST' ) {
		$args = array(
			'method'      => $method,
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => array_merge(
				array(
					'Square-Version' => DoughBoss_Settings::square_api_version(),
					'Content-Type'   => 'application/json',
					'Accept'         => 'application/json',
				),
				$headers
			),
		);
		if ( 'POST' === $method && ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( self::host( $mode ) . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'doughboss_square_oauth_network', __( 'Could not reach Square. Try again shortly.', 'doughboss' ), array( 'status' => 502 ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array( 'code' => (int) wp_remote_retrieve_response_code( $response ), 'data' => is_array( $data ) ? $data : null );
	}

	/** Short, non-sensitive Square error code from a response for notices. */
	private static function error_code( $data ) {
		if ( is_array( $data ) && isset( $data['errors'][0]['code'] ) && is_scalar( $data['errors'][0]['code'] ) ) {
			return substr( preg_replace( '/[^A-Z0-9_]/', '', strtoupper( (string) $data['errors'][0]['code'] ) ), 0, 64 );
		}
		if ( is_array( $data ) && isset( $data['type'] ) && is_scalar( $data['type'] ) ) {
			return substr( preg_replace( '/[^A-Za-z0-9_.]/', '', (string) $data['type'] ), 0, 64 );
		}
		return '';
	}

	/**
	 * Begin a connection: returns the Square authorization URL with a fresh
	 * signed state whose nonce is recorded for single use.
	 *
	 * @param string $shop Shop slug.
	 * @return string|WP_Error
	 */
	public static function start( $shop ) {
		$mode = self::mode();
		$app  = DoughBoss_Settings::square_application_id();
		if ( ! self::valid_shop( $shop ) || '' === $app || '' === self::application_secret( $mode ) ) {
			return new WP_Error( 'doughboss_square_oauth_config', __( 'Set the Square application ID (Settings › Payments) and the application secret (this page) for the current Square mode first.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$user  = (int) get_current_user_id();
		$nonce = bin2hex( random_bytes( 16 ) );
		$state = self::create_state( $shop, $mode, $user, $nonce, time(), self::state_key() );
		if ( '' === $state ) {
			return new WP_Error( 'doughboss_square_oauth_state', __( 'Could not start the Square connection.', 'doughboss' ), array( 'status' => 500 ) );
		}
		set_transient( 'doughboss_sqoauth_' . $nonce, $user . '|' . $shop . '|' . $mode, self::STATE_TTL );
		return self::authorize_url( $mode, $app, $state );
	}

	/** HMAC key for state signing (site secret, never stored with the state). */
	private static function state_key() {
		return function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) . '|doughboss-square-oauth-state' : '';
	}

	/**
	 * Complete a connection after the owner returns from Square.
	 *
	 * @param string $code  Authorization code.
	 * @param string $state Signed state.
	 * @return string|WP_Error Shop slug on success.
	 */
	public static function finish( $code, $state ) {
		$mode     = self::mode();
		$verified = self::verify_state( $state, (int) get_current_user_id(), $mode, time(), self::state_key() );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$transient = 'doughboss_sqoauth_' . $verified['nonce'];
		$expected  = $verified['user'] . '|' . $verified['shop'] . '|' . $verified['mode'];
		$recorded  = get_transient( $transient );
		delete_transient( $transient );
		if ( ! is_string( $recorded ) || ! hash_equals( $expected, $recorded ) ) {
			return new WP_Error( 'doughboss_square_oauth_state', __( 'This Square connection link was already used or has expired. Start again.', 'doughboss' ), array( 'status' => 400 ) );
		}
		if ( ! is_string( $code ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{1,512}$/D', $code ) ) {
			return new WP_Error( 'doughboss_square_oauth_code', __( 'Square did not return an authorization code.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$shop   = $verified['shop'];
		$app    = DoughBoss_Settings::square_application_id();
		$secret = self::application_secret( $mode );
		if ( '' === $app || '' === $secret ) {
			return new WP_Error( 'doughboss_square_oauth_config', __( 'The Square application ID or application secret for the current mode is missing.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$result = self::call( $mode, '/oauth2/token', array( 'client_id' => $app, 'client_secret' => $secret, 'code' => $code, 'grant_type' => 'authorization_code' ), array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$token = 200 === $result['code'] ? self::token_response( $result['data'] ) : new WP_Error( 'doughboss_square_oauth_token', sprintf( /* translators: %s: Square error code */ __( 'Square refused the authorization code (%s). Start again.', 'doughboss' ), self::error_code( $result['data'] ) ), array( 'status' => 502 ) );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		if ( '' === self::legacy_merchant( $mode ) ) {
			self::detect_legacy_merchant();
		}
		$owner = self::duplicate_owner( $shop, $token['merchant_id'], self::connections( $mode ), self::legacy_merchant( $mode ) );
		if ( '' !== $owner ) {
			// Drop only this freshly issued token; never the other shop's authorization.
			self::call( $mode, '/oauth2/revoke', array( 'client_id' => $app, 'access_token' => $token['access_token'], 'revoke_only_access_token' => true ), array( 'Authorization' => 'Client ' . $secret ) );
			return new WP_Error( 'doughboss_square_oauth_duplicate', sprintf( /* translators: %s: shop name */ __( 'That Square account already belongs to %s. Each shop must connect its own Square account — sign out of Square (or use a private window) and sign in as the correct shop.', 'doughboss' ), ucfirst( $owner ) ), array( 'status' => 409 ) );
		}
		$previous = self::record( $shop, $mode );
		$keep     = is_array( $previous ) && isset( $previous['merchant_id'], $previous['location_id'] ) && hash_equals( (string) $previous['merchant_id'], $token['merchant_id'] ) ? (string) $previous['location_id'] : '';
		$scopes   = self::token_scopes( $mode, $token['access_token'] );
		$now      = time();
		self::put_record( $shop, $mode, array(
			'status'          => 'active',
			'merchant_id'     => $token['merchant_id'],
			'access_token'    => self::seal( $token['access_token'] ),
			'refresh_token'   => self::seal( $token['refresh_token'] ),
			'expires_at'      => $token['expires_at'],
			'scopes'          => $scopes,
			'location_id'     => self::valid_id( $keep ) ? $keep : '',
			'connected_at'    => $now,
			'connected_by'    => (int) get_current_user_id(),
			'refreshed_at'    => $now,
			'last_attempt_at' => $now,
			'failures'        => 0,
			'last_error'      => '',
		) );
		self::forget_shop_caches( $shop );
		self::schedule();
		return $shop;
	}

	/**
	 * Which other shop already owns a merchant (another active connection, or
	 * the legacy single-account seller while Revesby still uses it).
	 *
	 * @param string $shop            Shop being connected.
	 * @param string $merchant_id     Merchant just authorized.
	 * @param array  $connections     [ shop => record ] for the mode.
	 * @param string $legacy_merchant Legacy merchant ('' unknown).
	 * @return string Owning shop slug, or '' when the merchant is free for $shop.
	 */
	public static function duplicate_owner( $shop, $merchant_id, array $connections, $legacy_merchant ) {
		foreach ( $connections as $other_shop => $other ) {
			if ( $other_shop !== $shop && is_array( $other ) && isset( $other['status'], $other['merchant_id'] ) && 'active' === $other['status'] && hash_equals( (string) $other['merchant_id'], (string) $merchant_id ) ) {
				return (string) $other_shop;
			}
		}
		$revesby_on_settings = ! isset( $connections['revesby']['status'] ) || 'active' !== $connections['revesby']['status'];
		if ( 'revesby' !== $shop && $revesby_on_settings && is_string( $legacy_merchant ) && '' !== $legacy_merchant && hash_equals( $legacy_merchant, (string) $merchant_id ) ) {
			return 'revesby';
		}
		return '';
	}

	/** Granted scopes via RetrieveTokenStatus; [] when it cannot be read (non-fatal). */
	private static function token_scopes( $mode, $access_token ) {
		$result = self::call( $mode, '/oauth2/token/status', array(), array( 'Authorization' => 'Bearer ' . $access_token ) );
		if ( is_wp_error( $result ) || 200 !== $result['code'] || ! isset( $result['data']['scopes'] ) || ! is_array( $result['data']['scopes'] ) ) {
			return array();
		}
		$scopes = array();
		foreach ( $result['data']['scopes'] as $scope ) {
			if ( is_string( $scope ) && 1 === preg_match( '/^[A-Z_]{3,64}$/D', $scope ) ) {
				$scopes[] = $scope;
			}
		}
		sort( $scopes );
		return $scopes;
	}

	/** @return string[] Requested scopes missing from a record's granted list ([] when unknown). */
	public static function missing_scopes( array $record ) {
		if ( empty( $record['scopes'] ) || ! is_array( $record['scopes'] ) ) {
			return array();
		}
		return array_values( array_diff( self::SCOPES, $record['scopes'] ) );
	}

	/**
	 * Refresh one shop's access token (code flow: the refresh token is reused).
	 * A refusal that means the authorization is gone disconnects the shop and
	 * raises an owner notice; transient failures back off and retry.
	 *
	 * @param string $shop Shop.
	 * @param string $mode Mode.
	 * @return bool
	 */
	public static function refresh( $shop, $mode ) {
		$lock = 'doughboss_sqoauth_lock_' . $mode . '_' . $shop;
		if ( ! add_option( $lock, time(), '', 'no' ) ) {
			$held = (int) get_option( $lock, 0 );
			if ( $held > time() - 120 ) {
				return false;
			}
			update_option( $lock, time(), false );
		}
		try {
			$record = self::record( $shop, $mode );
			if ( ! is_array( $record ) || 'active' !== ( isset( $record['status'] ) ? $record['status'] : '' ) ) {
				return false;
			}
			$refresh = self::open( isset( $record['refresh_token'] ) ? $record['refresh_token'] : '' );
			$app     = self::application_id_for( $mode );
			$secret  = self::application_secret( $mode );
			$now     = time();
			$record['last_attempt_at'] = $now;
			if ( ! self::valid_token( $refresh ) || '' === $app || '' === $secret ) {
				$record['failures']   = (int) ( isset( $record['failures'] ) ? $record['failures'] : 0 ) + 1;
				$record['last_error'] = '' === $secret ? 'APPLICATION_SECRET_MISSING' : ( '' === $app ? 'APPLICATION_ID_MISSING' : 'REFRESH_TOKEN_UNREADABLE' );
				self::put_record( $shop, $mode, $record );
				return false;
			}
			$result = self::call( $mode, '/oauth2/token', array( 'client_id' => $app, 'client_secret' => $secret, 'grant_type' => 'refresh_token', 'refresh_token' => $refresh ), array() );
			$token  = ! is_wp_error( $result ) && 200 === $result['code'] ? self::token_response( $result['data'], (string) $record['merchant_id'] ) : null;
			if ( is_array( $token ) ) {
				$record['access_token']  = self::seal( $token['access_token'] );
				$record['refresh_token'] = self::seal( $token['refresh_token'] );
				$record['expires_at']    = $token['expires_at'];
				$record['refreshed_at']  = $now;
				$record['failures']      = 0;
				$record['last_error']    = '';
				self::put_record( $shop, $mode, $record );
				return true;
			}
			$code = is_wp_error( $result ) ? 'NETWORK' : self::error_code( $result['data'] );
			$gone = ! is_wp_error( $result ) && in_array( $result['code'], array( 400, 401 ), true )
				&& in_array( $code, array( 'ACCESS_TOKEN_REVOKED', 'UNAUTHORIZED', 'INVALID_GRANT', 'REFRESH_TOKEN_REVOKED', 'REFRESH_TOKEN_EXPIRED' ), true );
			if ( $gone ) {
				self::mark_disconnected( $shop, $mode, 'refresh_refused' );
				return false;
			}
			$record['failures']   = (int) ( isset( $record['failures'] ) ? $record['failures'] : 0 ) + 1;
			$record['last_error'] = '' !== $code ? $code : 'HTTP_' . ( is_wp_error( $result ) ? 0 : (int) $result['code'] );
			self::put_record( $shop, $mode, $record );
			return false;
		} finally {
			delete_option( $lock );
		}
	}

	/** Application id for an explicit mode (the settings accessor follows the active mode). */
	private static function application_id_for( $mode ) {
		$value = trim( (string) DoughBoss_Settings::get( 'live' === $mode ? 'square_live_app_id' : 'square_test_app_id', '' ) );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{8,191}$/D', $value ) || ( 'live' === $mode ) === ( 0 === strpos( $value, 'sandbox-' ) ) ) {
			return '';
		}
		return $value;
	}

	/** Cron: refresh every due connection in both environments. */
	public static function refresh_all() {
		$now = time();
		foreach ( array( 'test', 'live' ) as $mode ) {
			foreach ( self::connections( $mode ) as $shop => $record ) {
				if ( 'refresh' === self::refresh_decision( $record, $now ) ) {
					self::refresh( $shop, $mode );
				}
			}
		}
	}

	/**
	 * Owner disconnect: RevokeToken for the whole authorization (by merchant),
	 * then forget the connection locally whatever Square answered.
	 *
	 * @param string $shop Shop.
	 * @return true|WP_Error WP_Error only to report that Square-side revocation was not confirmed.
	 */
	public static function disconnect( $shop ) {
		$mode   = self::mode();
		$record = self::record( $shop, $mode );
		if ( ! is_array( $record ) ) {
			return true;
		}
		if ( 'active' !== ( isset( $record['status'] ) ? $record['status'] : '' ) ) {
			// Already revoked in Square: just forget the local record.
			self::put_record( $shop, $mode, null );
			self::schedule();
			return true;
		}
		$app    = DoughBoss_Settings::square_application_id();
		$secret = self::application_secret( $mode );
		$ok     = false;
		if ( '' !== $app && '' !== $secret && isset( $record['merchant_id'] ) && self::valid_id( $record['merchant_id'] ) ) {
			$result = self::call( $mode, '/oauth2/revoke', array( 'client_id' => $app, 'merchant_id' => $record['merchant_id'] ), array( 'Authorization' => 'Client ' . $secret ) );
			$ok     = ! is_wp_error( $result ) && 200 === $result['code'] && isset( $result['data']['success'] ) && true === $result['data']['success'];
		}
		self::put_record( $shop, $mode, null );
		self::forget_shop_caches( $shop );
		self::schedule();
		return $ok ? true : new WP_Error( 'doughboss_square_oauth_revoke', __( 'The shop was disconnected here, but Square did not confirm the revocation. Also remove “DoughBoss” under Square Dashboard › Account & Settings › Apps for that shop.', 'doughboss' ) );
	}

	/**
	 * Mark a connection unusable (revoked in Square or refresh refused). Tokens
	 * are wiped; merchant id is kept so late webhooks are recognised and ignored.
	 */
	public static function mark_disconnected( $shop, $mode, $reason ) {
		$record = self::record( $shop, $mode );
		if ( ! is_array( $record ) ) {
			return;
		}
		$record['status']        = 'revoked';
		$record['access_token']  = '';
		$record['refresh_token'] = '';
		$record['revoked_at']    = time();
		$record['last_error']    = substr( preg_replace( '/[^a-z_]/', '', (string) $reason ), 0, 40 );
		self::put_record( $shop, $mode, $record );
		self::forget_shop_caches( $shop );
		self::add_notice( sprintf( /* translators: 1: shop name, 2: Square environment */ __( 'Square access for %1$s (%2$s) was revoked or refused. Online payments, invoices and loyalty for that shop are off until it is reconnected under DoughBoss › Square accounts.', 'doughboss' ), ucfirst( $shop ), 'live' === $mode ? 'live' : 'sandbox' ) );
	}

	/**
	 * Webhook oauth.authorization.revoked: disconnect every shop of that merchant.
	 *
	 * @param string $merchant_id Payload merchant id.
	 * @return int Shops disconnected.
	 */
	public static function handle_revocation( $merchant_id ) {
		if ( ! self::valid_id( $merchant_id ) ) {
			return 0;
		}
		$mode  = self::mode();
		$count = 0;
		foreach ( self::connections( $mode ) as $shop => $record ) {
			if ( isset( $record['status'], $record['merchant_id'] ) && 'active' === $record['status'] && hash_equals( (string) $record['merchant_id'], $merchant_id ) ) {
				self::mark_disconnected( $shop, $mode, 'webhook_revoked' );
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Route a verified webhook payload to a shop, the legacy single-account
	 * path, or nowhere.
	 *
	 * @param array $event Decoded verified event.
	 * @return array { route, shop }
	 */
	public static function route_event( array $event ) {
		$merchant = isset( $event['merchant_id'] ) ? $event['merchant_id'] : '';
		return self::route_merchant( $merchant, self::connections(), self::legacy_merchant() );
	}

	/**
	 * GET /v2/locations for a connected shop (location picker).
	 *
	 * @param string $shop Shop.
	 * @return array|WP_Error [ { id, name, status, currency, country } ]
	 */
	public static function list_locations( $shop ) {
		$token = self::access_token( $shop, true );
		if ( '' === $token ) {
			return new WP_Error( 'doughboss_square_oauth_token', __( 'This shop is not connected to Square (or its access needs reconnecting).', 'doughboss' ) );
		}
		return self::locations_with_token( self::mode(), $token, self::merchant_id( $shop ) );
	}

	/** @return array|WP_Error */
	private static function locations_with_token( $mode, $token, $merchant_id ) {
		$result = self::call( $mode, '/v2/locations', array(), array( 'Authorization' => 'Bearer ' . $token ), 'GET' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 200 !== $result['code'] || ! isset( $result['data']['locations'] ) || ! is_array( $result['data']['locations'] ) ) {
			return new WP_Error( 'doughboss_square_oauth_locations', sprintf( /* translators: %s: Square error code */ __( 'Square locations could not be read (%s).', 'doughboss' ), self::error_code( $result['data'] ) ) );
		}
		$out = array();
		foreach ( $result['data']['locations'] as $location ) {
			if ( ! is_array( $location ) || ! isset( $location['id'], $location['merchant_id'] ) || ! self::valid_id( $location['id'] ) || ! is_string( $location['merchant_id'] )
				|| ( '' !== $merchant_id && ! hash_equals( $merchant_id, $location['merchant_id'] ) ) ) {
				continue;
			}
			$out[] = array(
				'id'          => $location['id'],
				'merchant_id' => $location['merchant_id'],
				'name'        => isset( $location['name'] ) && is_string( $location['name'] ) ? sanitize_text_field( $location['name'] ) : $location['id'],
				'status'      => isset( $location['status'] ) && is_string( $location['status'] ) ? $location['status'] : '',
				'currency'    => isset( $location['currency'] ) && is_string( $location['currency'] ) ? $location['currency'] : '',
				'country'     => isset( $location['country'] ) && is_string( $location['country'] ) ? $location['country'] : '',
			);
		}
		return $out;
	}

	/**
	 * Map a connected shop to one ACTIVE location of ITS merchant.
	 *
	 * @param string $shop        Shop.
	 * @param string $location_id Square location id.
	 * @return true|WP_Error
	 */
	public static function select_location( $shop, $location_id ) {
		$mode = self::mode();
		if ( ! self::is_connected( $shop, $mode ) || ! self::valid_id( $location_id ) ) {
			return new WP_Error( 'doughboss_square_oauth_location', __( 'Pick one of the listed Square locations.', 'doughboss' ) );
		}
		$locations = self::list_locations( $shop );
		if ( is_wp_error( $locations ) ) {
			return $locations;
		}
		$currency = strtoupper( (string) DoughBoss_Settings::get( 'currency_code', 'AUD' ) );
		$match    = null;
		foreach ( $locations as $location ) {
			if ( $location['id'] === $location_id ) {
				$match = $location;
			}
		}
		if ( null === $match || 'ACTIVE' !== $match['status'] || $currency !== $match['currency'] ) {
			return new WP_Error( 'doughboss_square_oauth_location', __( 'That location is not an active location of this shop’s Square account in the site currency.', 'doughboss' ) );
		}
		foreach ( self::SHOPS as $other ) {
			if ( $other !== $shop && DoughBoss_Settings::square_shop_location_id( $other ) === $location_id ) {
				return new WP_Error( 'doughboss_square_oauth_location', __( 'That Square location is already mapped to another shop.', 'doughboss' ) );
			}
		}
		$record                = self::record( $shop, $mode );
		$record['location_id'] = $location_id;
		self::put_record( $shop, $mode, $record );
		self::forget_shop_caches( $shop );
		return true;
	}

	/**
	 * Learn the merchant behind the legacy single-account token (ListLocations,
	 * which Square recommends for checking a token) so webhooks from unknown
	 * sellers can be ignored precisely.
	 *
	 * @return string|WP_Error Merchant id.
	 */
	public static function detect_legacy_merchant() {
		$mode  = self::mode();
		$token = DoughBoss_Settings::square_access_token();
		if ( ! DoughBoss_Settings::square_access_token_valid() ) {
			return new WP_Error( 'doughboss_square_oauth_legacy', __( 'No single-account Square access token is configured for this mode.', 'doughboss' ) );
		}
		$locations = self::locations_with_token( $mode, $token, '' );
		if ( is_wp_error( $locations ) ) {
			return $locations;
		}
		$merchants = array_values( array_unique( array_column( $locations, 'merchant_id' ) ) );
		if ( 1 !== count( $merchants ) || ! self::valid_id( $merchants[0] ) ) {
			return new WP_Error( 'doughboss_square_oauth_legacy', __( 'The single-account token did not identify exactly one Square merchant.', 'doughboss' ) );
		}
		$store                             = self::store();
		$store['legacy_merchant']          = isset( $store['legacy_merchant'] ) && is_array( $store['legacy_merchant'] ) ? $store['legacy_merchant'] : array();
		$store['legacy_merchant'][ $mode ] = $merchants[0];
		self::save_store( $store );
		return $merchants[0];
	}

	/* ------------------------------------------------------------------ */
	/* Housekeeping                                                        */
	/* ------------------------------------------------------------------ */

	/** Native DoughBoss location id for a shop slug (0 when absent). */
	public static function native_location_id( $shop ) {
		if ( ! class_exists( 'DoughBoss_Locations' ) ) {
			return 0;
		}
		foreach ( DoughBoss_Locations::all( false ) as $location ) {
			if ( isset( $location->slug, $location->id ) && $shop === $location->slug ) {
				return (int) $location->id;
			}
		}
		return 0;
	}

	/** Per-location Square caches that belong to the previous seller account. */
	private static function forget_shop_caches( $shop ) {
		$native = self::native_location_id( $shop );
		if ( ! $native ) {
			return;
		}
		foreach ( array( 'test', 'live' ) as $mode ) {
			delete_transient( 'doughboss_sqloy_program_' . $mode . '_' . $native );
		}
		if ( class_exists( 'DoughBoss_Square_Tiers' ) && defined( 'DoughBoss_Square_Tiers::GROUP_OPTION' ) ) {
			$cache = get_option( DoughBoss_Square_Tiers::GROUP_OPTION, array() );
			if ( is_array( $cache ) ) {
				foreach ( array_keys( $cache ) as $key ) {
					if ( 1 === preg_match( '/^(?:test|live):' . $native . ':/', (string) $key ) ) {
						unset( $cache[ $key ] );
					}
				}
				update_option( DoughBoss_Square_Tiers::GROUP_OPTION, $cache );
			}
		}
	}

	/** Queue an owner-facing admin notice (rendered by the Square accounts admin). */
	public static function add_notice( $message ) {
		$notices   = get_option( self::NOTICE_OPTION, array() );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array( 'at' => time(), 'message' => (string) $message );
		update_option( self::NOTICE_OPTION, array_slice( $notices, -10 ), false );
	}

	/** @return array */
	public static function notices() {
		$notices = get_option( self::NOTICE_OPTION, array() );
		return is_array( $notices ) ? $notices : array();
	}

	/** @return void */
	public static function clear_notices() {
		delete_option( self::NOTICE_OPTION );
	}

	/** @return string[] Shops whose active token has not refreshed for over 8 days (Square's alert threshold). */
	public static function stale_shops( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$out = array();
		foreach ( self::connections() as $shop => $record ) {
			if ( isset( $record['status'] ) && 'active' === $record['status'] && $now - ( isset( $record['refreshed_at'] ) ? (int) $record['refreshed_at'] : 0 ) > self::STALE_AGE ) {
				$out[] = $shop;
			}
		}
		return $out;
	}
}
