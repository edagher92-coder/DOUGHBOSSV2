<?php
/**
 * POSPal (银豹) Open Platform client (optional, off by default).
 *
 * A thin, dependency-free wrapper over the POSPal Open Platform REST API, used
 * to connect the shops' POSPal POS for orders, catering and discount-coupon
 * vouchers. No SDK is bundled — calls go through `wp_remote_*`. When POSPal is
 * disabled or unconfigured the whole feature is dormant and the plugin behaves
 * exactly as before.
 *
 * Auth: every request carries a `time-stamp` (milliseconds) header and a
 * `data-signature` header, where the signature is
 * `strtoupper( md5( appKey . rawJsonBody ) )`. The body itself carries the
 * public `appId`; the secret `appKey` is only ever used to sign and never
 * leaves the server (not in the body, the URL or any log).
 *
 * Foundation milestone (M1): signing + transport + a read-only test call.
 * Voucher/order/catering endpoints are layered on top in later milestones.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal POSPal Open Platform client.
 */
class DoughBoss_POSPal {

	const API_PREFIX = '/pospal-api2/openapi/v1/';

	/**
	 * Legacy openapi paths for the coupon-code (优惠券号 / 核销码) endpoints. The coupon
	 * GRANT and USE methods live under this older `/pospal-api/api/auth/openapi/` path,
	 * not the pospal-api2 module path the query methods use. Confirmed from the live
	 * POSPal coupon API docs (addCouponcode / promotioncouponcode/use).
	 */
	const PATH_ADD_COUPONCODE = '/pospal-api/api/auth/openapi/promotion/addCouponcode/';
	const PATH_USE_COUPONCODE = '/pospal-api/api/auth/openapi/promotioncouponcode/use/';

	/**
	 * Whether POSPal is switched on AND fully configured. The single gate the
	 * rest of the plugin checks before making any call.
	 *
	 * @return bool
	 */
	public static function ready() {
		return DoughBoss_Settings::pospal_ready();
	}

	/**
	 * Configured area host (e.g. https://area28-win.pospal.cn:443), no trailing slash.
	 *
	 * @return string
	 */
	public static function host() {
		return DoughBoss_Settings::pospal_host();
	}

	/**
	 * Public application id (sent in the request body).
	 *
	 * @return string
	 */
	private static function app_id() {
		return DoughBoss_Settings::pospal_app_id();
	}

	/**
	 * Secret application key (used only to sign — never sent in the body).
	 *
	 * @return string
	 */
	private static function app_key() {
		return DoughBoss_Settings::pospal_app_key();
	}

	/**
	 * Resolve the credentials a call() should sign with. An explicit per-store set
	 * (host/app_id/app_key) is used as-is for multi-store grants; otherwise the
	 * default/legacy single-store settings are used, preserving prior behaviour.
	 *
	 * @param array|null $creds Optional { host, app_id, app_key }.
	 * @return array { host, app_id, app_key }.
	 */
	private static function resolve_creds( $creds ) {
		if ( is_array( $creds ) && isset( $creds['host'], $creds['app_id'], $creds['app_key'] ) ) {
			return array(
				'host'    => untrailingslashit( (string) $creds['host'] ),
				'app_id'  => (string) $creds['app_id'],
				'app_key' => (string) $creds['app_key'],
			);
		}
		return array(
			'host'    => self::host(),
			'app_id'  => self::app_id(),
			'app_key' => self::app_key(),
		);
	}

	/**
	 * Current time in milliseconds, as POSPal expects for the time-stamp header.
	 *
	 * @return string
	 */
	private static function timestamp_ms() {
		return (string) (int) round( microtime( true ) * 1000 );
	}

	/**
	 * Build POSPal's request signature: strtoupper( md5( appKey . rawBody ) ).
	 *
	 * @param string $raw_body The exact JSON string that will be sent as the body.
	 * @return string
	 */
	private static function sign( $raw_body ) {
		return strtoupper( md5( self::app_key() . $raw_body ) );
	}

	/**
	 * Read-only connectivity check: query the account's coupon promotion rules.
	 * Safe (no side effects); used by the admin "test connection" action.
	 *
	 * @return array|WP_Error
	 */
	public static function test_connection() {
		return self::query_coupon_promotions();
	}

	/**
	 * List the account's available coupon promotion rules (read-only).
	 *
	 * @return array|WP_Error
	 */
	public static function query_coupon_promotions( $creds = null ) {
		return self::call( 'promotionOpenApi', 'queryCouponPromotions', array(), '', $creds );
	}

	/**
	 * Read-only validation for the admin "Verify coupon setup" action: confirm the
	 * connection works and that the configured $5 / $10 coupon-rule UIDs each match a
	 * real rule in the account. No side effects — it only queries the rule list and
	 * searches the response for the configured UIDs (field-name agnostic).
	 *
	 * @return array|WP_Error { rules_count:int, uid5, uid10, found5:bool, found10:bool }
	 *                        or a WP_Error when POSPal is off or the query fails.
	 */
	public static function verify_coupon_rules( $creds = null, $uid5 = null, $uid10 = null ) {
		// Default to the legacy single-store coupon UIDs when no store context given.
		if ( null === $uid5 ) {
			$uid5 = DoughBoss_Settings::pospal_coupon_uid_5();
		}
		if ( null === $uid10 ) {
			$uid10 = DoughBoss_Settings::pospal_coupon_uid_10();
		}
		$rules = self::query_coupon_promotions( $creds );
		if ( is_wp_error( $rules ) ) {
			return $rules;
		}
		$blob  = (string) wp_json_encode( $rules );
		$uid5  = (string) $uid5;
		$uid10 = (string) $uid10;
		return array(
			'rules_count' => is_array( $rules ) ? count( $rules ) : 0,
			'uid5'        => $uid5,
			'uid10'       => $uid10,
			'found5'      => '' !== $uid5 && false !== strpos( $blob, $uid5 ),
			'found10'     => '' !== $uid10 && false !== strpos( $blob, $uid10 ),
		);
	}

	/**
	 * Look up a POSPal member by phone number.
	 *
	 * Endpoint: customerOpenApi/queryByTel. Confirmed against the ledccn/ledc-pospal
	 * PHP SDK (and corroborated by minms/pospal + daigou-kf/daigou), which call
	 * `/pospal-api2/openapi/v1/customerOpenapi/queryBytel` with body field
	 * `customerTel`. POSPal's path is case-insensitive in practice; we keep the
	 * documented module/method casing used elsewhere in this client and let the
	 * existing `call()` preserve the method casing.
	 *
	 * @param string $phone Member phone number (the agreed member key).
	 * @return array|WP_Error Member record(s) on success — POSPal returns a list of
	 *                        matching customers, each carrying a `customerUid`.
	 */
	public static function member_by_tel( $phone, $creds = null ) {
		$phone = preg_replace( '/[^0-9+]/', '', (string) $phone );
		if ( '' === $phone ) {
			return new WP_Error( 'doughboss_pospal_member', __( 'A phone number is required.', 'doughboss' ), array( 'status' => 400 ) );
		}

		return self::call(
			'customerOpenApi',
			'queryByTel',
			array(
				'customerTel' => $phone,
			),
			'',
			$creds
		);
	}

	/**
	 * Create a POSPal member by phone (and optional name).
	 *
	 * Endpoint: customerOpenApi/add. Per the ledccn/ledc-pospal SDK the body wraps
	 * the member fields in a `customerInfo` object; POSPal returns the new member's
	 * `customerUid`. The field names inside `customerInfo` (`number`, `name`,
	 * `phone`) follow the documented member schema — confirm against the live
	 * 会员 (member) API docs before enabling in production.
	 *
	 * @param string $phone Member phone number.
	 * @param string $name  Optional member display name.
	 * @return array|WP_Error Created member payload (incl. `customerUid`) or error.
	 */
	public static function add_member( $phone, $name = '', $creds = null ) {
		$phone = preg_replace( '/[^0-9+]/', '', (string) $phone );
		if ( '' === $phone ) {
			return new WP_Error( 'doughboss_pospal_member', __( 'A phone number is required.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$name = sanitize_text_field( (string) $name );

		// POSPal requires a member `number`; reuse the phone as a stable, unique key.
		$customer_info = array(
			'number' => $phone,
			'phone'  => $phone,
		);
		if ( '' !== $name ) {
			$customer_info['name'] = $name;
		}

		return self::call(
			'customerOpenApi',
			'add',
			array(
				'customerInfo' => $customer_info,
			),
			'',
			$creds
		);
	}

	/**
	 * Resolve a member's `customerUid` by phone, creating the member if absent.
	 *
	 * Queries first; if no member exists, adds one and returns the new uid. The uid
	 * is pulled from whichever shape POSPal returns (a list of customers from the
	 * query, or the created member object from add).
	 *
	 * @param string $phone Member phone number.
	 * @param string $name  Optional member display name (used only on create).
	 * @return string|WP_Error The POSPal `customerUid`, or an error.
	 */
	public static function ensure_member( $phone, $name = '', $creds = null ) {
		$existing = self::member_by_tel( $phone, $creds );
		if ( ! is_wp_error( $existing ) ) {
			$uid = self::extract_customer_uid( $existing );
			if ( '' !== $uid ) {
				return $uid;
			}
		}

		$created = self::add_member( $phone, $name, $creds );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$uid = self::extract_customer_uid( $created );
		if ( '' === $uid ) {
			return new WP_Error( 'doughboss_pospal_member_uid', __( 'POSPal did not return a member id.', 'doughboss' ), array( 'status' => 502 ) );
		}
		return $uid;
	}

	/**
	 * Coerce a POSPal Long id (given as a numeric string) to an int so it JSON-encodes
	 * unquoted, as POSPal's Long fields (promotionCouponUid, customerUid) expect.
	 * Pure-digit ids up to 64-bit are preserved exactly; anything non-numeric is left
	 * as a string so nothing is silently mangled.
	 *
	 * @param string $value Numeric id string.
	 * @return int|string
	 */
	private static function numeric( $value ) {
		$value = (string) $value;
		return ( '' !== $value && ctype_digit( $value ) ) ? (int) $value : $value;
	}

	/**
	 * Normalise a coupon code for POSPal: trim and cap at POSPal's 50-char limit. The
	 * voucher's own code is used as the POSPal code, so the same code resolves at the
	 * WP scanner and the POSPal till.
	 *
	 * @param string $raw Raw code.
	 * @return string
	 */
	private static function coupon_code( $raw ) {
		return substr( sanitize_text_field( (string) $raw ), 0, 50 );
	}

	/**
	 * Grant a coupon to a member by creating a coupon code (核销码) attached to that
	 * member, via POSPal's promotion/addCouponcode endpoint.
	 *
	 * Confirmed from the live coupon API docs: the rule is referenced by
	 * `promotionCouponUid`, the member by `customerUid`, and each grant creates one
	 * unique `code` (≤50 chars, globally unique per store) — we pass the voucher's own
	 * code so the SAME code works at the WP scanner and the POSPal till. Uses the older
	 * /pospal-api/api/auth/openapi/ path (PATH_ADD_COUPONCODE), not the pospal-api2
	 * module path. The body is filterable via 'doughboss_pospal_grant_body'.
	 *
	 * @param string $customer_uid    POSPal member uid (from ensure_member()).
	 * @param string $coupon_rule_uid The coupon rule's promotionCouponUid (from settings).
	 * @param string $code            The coupon code to create (the voucher code).
	 * @return array|WP_Error The POSPal response, or an error.
	 */
	public static function grant_coupon( $customer_uid, $coupon_rule_uid, $code, $creds = null ) {
		$customer_uid = sanitize_text_field( (string) $customer_uid );
		$rule_uid     = sanitize_text_field( (string) $coupon_rule_uid );
		$code         = self::coupon_code( $code );
		if ( '' === $customer_uid || '' === $rule_uid || '' === $code ) {
			return new WP_Error( 'doughboss_pospal_grant', __( 'A member, coupon rule and code are required.', 'doughboss' ), array( 'status' => 400 ) );
		}

		/**
		 * Filter the addCouponcode request body before it is signed and sent. POSPal
		 * expects promotionCouponUid + customerUid as numeric (Long) and code as a
		 * string; adjust here if your account's field shape differs.
		 *
		 * @param array  $body         Request body.
		 * @param string $customer_uid Member uid.
		 * @param string $rule_uid     Coupon-rule uid (promotionCouponUid).
		 * @param string $code         Coupon code being created.
		 */
		$body = apply_filters(
			'doughboss_pospal_grant_body',
			array(
				'promotionCouponUid' => self::numeric( $rule_uid ),
				'code'               => $code,
				'customerUid'        => self::numeric( $customer_uid ),
			),
			$customer_uid,
			$rule_uid,
			$code
		);

		return self::call( 'promotion', 'addCouponcode', (array) $body, self::PATH_ADD_COUPONCODE, $creds );
	}

	/**
	 * Mark a member's coupon code used (核销) so a voucher redeemed online can't also
	 * be used in-store. Uses POSPal's promotioncouponcode/use endpoint with the same
	 * `code` created at grant time (the voucher code). Confirmed from the live docs.
	 * A 'doughboss_pospal_revoke_noop' filter can disable it; the body is filterable
	 * via 'doughboss_pospal_revoke_body'.
	 *
	 * @param string $customer_uid POSPal member uid (optional for the call).
	 * @param string $code         The coupon code created at grant (the voucher code).
	 * @return array|WP_Error
	 */
	public static function revoke_coupon( $customer_uid, $code, $creds = null ) {
		$customer_uid = sanitize_text_field( (string) $customer_uid );
		$code         = self::coupon_code( $code );
		if ( '' === $code ) {
			return new WP_Error( 'doughboss_pospal_revoke', __( 'A coupon code is required.', 'doughboss' ), array( 'status' => 400 ) );
		}

		/**
		 * Allow the revoke/use leg to be turned into a no-op (logs intent only), e.g.
		 * if a site prefers POSPal coupons to expire naturally rather than be voided.
		 *
		 * @param bool $noop Whether to skip the POSPal call. Default false.
		 */
		if ( apply_filters( 'doughboss_pospal_revoke_noop', false ) ) {
			if ( function_exists( 'error_log' ) ) {
				error_log( 'DoughBoss POSPal: revoke_coupon no-op (disabled by filter).' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return array( 'noop' => true );
		}

		$body = array( 'code' => $code );
		if ( '' !== $customer_uid ) {
			$body['customerUid'] = self::numeric( $customer_uid );
		}

		/**
		 * Filter the promotioncouponcode/use request body before it is signed/sent.
		 *
		 * @param array  $body         Request body ({ code, customerUid? }).
		 * @param string $customer_uid Member uid.
		 * @param string $code         Coupon code being used/voided.
		 */
		$body = apply_filters( 'doughboss_pospal_revoke_body', $body, $customer_uid, $code );

		return self::call( 'promotioncouponcode', 'use', (array) $body, self::PATH_USE_COUPONCODE, $creds );
	}

	/**
	 * Pull a member `customerUid` out of a POSPal customer-query or add response,
	 * which may be a single member object or a list of matching members.
	 *
	 * @param mixed $data Decoded POSPal `data` payload.
	 * @return string The first non-empty customerUid found, or '' when none.
	 */
	private static function extract_customer_uid( $data ) {
		if ( is_array( $data ) ) {
			// Single member object: { customerUid: ... }.
			if ( isset( $data['customerUid'] ) && '' !== (string) $data['customerUid'] ) {
				return (string) $data['customerUid'];
			}
			// List of members: [ { customerUid: ... }, ... ].
			foreach ( $data as $row ) {
				if ( is_array( $row ) && isset( $row['customerUid'] ) && '' !== (string) $row['customerUid'] ) {
					return (string) $row['customerUid'];
				}
			}
		}
		return '';
	}

	/**
	 * Perform a signed POSPal Open Platform call.
	 *
	 * The JSON body is built once and the same exact bytes are both signed and
	 * sent — re-encoding the body would break the signature.
	 *
	 * @param string $module  API module, e.g. 'promotionOpenApi', 'customerOpenApi'.
	 * @param string $method  API method, e.g. 'queryCouponPromotions'.
	 * @param array  $payload Request fields ('appId' is added automatically).
	 * @return array|WP_Error Decoded `data` payload on success, or an error.
	 */
	public static function call( $module, $method, array $payload = array(), $override_path = '', $creds = null ) {
		// Resolve which store's credentials to use: an explicit per-store set (multi-
		// store), or the default/legacy single-store settings when none is passed.
		$creds = self::resolve_creds( $creds );
		if ( ! DoughBoss_Settings::pospal_enabled() || '' === $creds['host'] || '' === $creds['app_id'] || '' === $creds['app_key'] ) {
			return new WP_Error( 'doughboss_pospal_config', __( 'POSPal is not configured.', 'doughboss' ), array( 'status' => 503 ) );
		}

		$module = sanitize_key( $module );
		$method = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $method );
		if ( '' === $module || '' === $method ) {
			return new WP_Error( 'doughboss_pospal_request', __( 'Invalid POSPal request.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$payload['appId'] = $creds['app_id'];
		$raw_body         = wp_json_encode( $payload );
		if ( false === $raw_body ) {
			return new WP_Error( 'doughboss_pospal_encode', __( 'Could not encode the POSPal request.', 'doughboss' ), array( 'status' => 500 ) );
		}

		$url = $creds['host'] . self::API_PREFIX . $module . '/' . $method;
		// Some endpoints (the coupon GRANT/USE methods) live under a different,
		// older openapi path; callers pass it explicitly to bypass the module prefix.
		if ( '' !== (string) $override_path ) {
			$url = $creds['host'] . (string) $override_path;
		}

		$response = wp_remote_post(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => 25,
				// Let WP manage Accept-Encoding so it auto-decompresses the reply;
				// a gzip fallback below covers servers that gzip regardless.
				'headers' => array(
					'User-Agent'     => 'openApi',
					'Content-Type'   => 'application/json; charset=utf-8',
					'time-stamp'     => self::timestamp_ms(),
					'data-signature' => strtoupper( md5( $creds['app_key'] . $raw_body ) ),
				),
				'body'    => $raw_body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'doughboss_pospal_network', __( 'Could not reach POSPal. Please try again.', 'doughboss' ), array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		// Fallback: decode gzip if the host returned it without WP inflating it.
		if ( '' !== $raw && 0 === strpos( $raw, "\x1f\x8b" ) && function_exists( 'gzdecode' ) ) {
			$decoded = gzdecode( $raw );
			if ( false !== $decoded ) {
				$raw = $decoded;
			}
		}
		$data = json_decode( $raw, true );

		if ( $code >= 200 && $code < 300 && is_array( $data ) && isset( $data['status'] ) && 'success' === $data['status'] ) {
			return isset( $data['data'] ) ? $data['data'] : array();
		}

		$message = ( is_array( $data ) && ! empty( $data['messages'] ) && is_array( $data['messages'] ) )
			? implode( ' ', array_map( 'strval', $data['messages'] ) )
			: __( 'POSPal returned an error.', 'doughboss' );

		// Log only the status + endpoint for the operator — never the response body
		// (it can carry member PII) and never the appKey or the signed headers.
		if ( function_exists( 'error_log' ) ) {
			error_log( 'DoughBoss POSPal error: HTTP ' . $code . ' on ' . $module . '/' . $method ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// Attach diagnostics (HTTP code, endpoint, a short raw-body snippet) so the
		// admin test-grant can surface exactly what POSPal said — e.g. a method-not-
		// found 404 vs a JSON parameter error. Live callers ignore this data; only
		// the code + endpoint are ever logged (never the appKey, never member PII).
		return new WP_Error(
			'doughboss_pospal_api',
			$message,
			array(
				'status'    => 502,
				'http_code' => $code,
				'endpoint'  => $module . '/' . $method,
				'body'      => substr( (string) $raw, 0, 600 ),
			)
		);
	}
}
