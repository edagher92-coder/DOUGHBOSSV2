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
	public static function query_coupon_promotions() {
		return self::call( 'promotionOpenApi', 'queryCouponPromotions' );
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
	public static function member_by_tel( $phone ) {
		$phone = preg_replace( '/[^0-9+]/', '', (string) $phone );
		if ( '' === $phone ) {
			return new WP_Error( 'doughboss_pospal_member', __( 'A phone number is required.', 'doughboss' ), array( 'status' => 400 ) );
		}

		return self::call(
			'customerOpenApi',
			'queryByTel',
			array(
				'customerTel' => $phone,
			)
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
	public static function add_member( $phone, $name = '' ) {
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
			)
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
	public static function ensure_member( $phone, $name = '' ) {
		$existing = self::member_by_tel( $phone );
		if ( ! is_wp_error( $existing ) ) {
			$uid = self::extract_customer_uid( $existing );
			if ( '' !== $uid ) {
				return $uid;
			}
		}

		$created = self::add_member( $phone, $name );
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
	 * Grant (issue) a coupon to a member.
	 *
	 * Endpoint (assumed): promotionOpenApi/addCustomerPassProduct. POSPal's coupon
	 * (优惠券) docs gate the exact grant-to-member method behind authenticated
	 * developer access; `addCustomerPassProduct` is the documented name for issuing
	 * a "pass product"/coupon instance to a member, taking the member `customerUid`
	 * and the coupon rule's `passProductUid`. **This name MUST be confirmed against
	 * the live 优惠券 docs before go-live** — it is isolated here so only this one
	 * method changes if the real name differs. The grant is dormant until POSPal is
	 * configured *and* a coupon rule UID is mapped in settings, so shipping this
	 * with the placeholder name is safe.
	 *
	 * @param string $customer_uid    POSPal member uid (from ensure_member()).
	 * @param string $coupon_rule_uid The coupon rule's passProductUid (mapped in settings).
	 * @param int    $qty             How many to grant (default 1).
	 * @return array|WP_Error The granted-coupon payload (used to derive a ref) or error.
	 */
	public static function grant_coupon( $customer_uid, $coupon_rule_uid, $qty = 1 ) {
		$customer_uid    = sanitize_text_field( (string) $customer_uid );
		$coupon_rule_uid = sanitize_text_field( (string) $coupon_rule_uid );
		$qty             = max( 1, (int) $qty );
		if ( '' === $customer_uid || '' === $coupon_rule_uid ) {
			return new WP_Error( 'doughboss_pospal_grant', __( 'A member and a coupon rule are required.', 'doughboss' ), array( 'status' => 400 ) );
		}

		return self::call(
			'promotionOpenApi',
			'addCustomerPassProduct',
			array(
				'customerUid'    => $customer_uid,
				'passProductUid' => $coupon_rule_uid,
				'quantity'       => $qty,
			)
		);
	}

	/**
	 * Best-effort revoke/void of a coupon previously granted to a member, so a
	 * voucher redeemed online can't also be used in-store.
	 *
	 * Endpoint (assumed): promotionOpenApi/useCustomerPassProduct — POSPal's coupon
	 * model has no clean "delete a granted coupon" call; the documented way to take
	 * a coupon out of circulation is to mark it **used** (核销), which is what the
	 * in-store till does on redemption. We reuse that here as the revoke primitive:
	 * marking the member's coupon used removes it from their available coupons.
	 *
	 * The exact method (`useCustomerPassProduct`) and the reference field
	 * (`passProductUid` vs a per-instance `customerPassProductUid`) **MUST be
	 * confirmed against the live docs**. If no usable endpoint exists, this stays a
	 * safe no-op: it logs intent and returns success without touching POSPal.
	 *
	 * @param string $customer_uid POSPal member uid.
	 * @param string $coupon_ref   The granted coupon reference stored at grant time.
	 * @return array|WP_Error
	 */
	public static function revoke_coupon( $customer_uid, $coupon_ref ) {
		$customer_uid = sanitize_text_field( (string) $customer_uid );
		$coupon_ref   = sanitize_text_field( (string) $coupon_ref );
		if ( '' === $customer_uid || '' === $coupon_ref ) {
			return new WP_Error( 'doughboss_pospal_revoke', __( 'A member and a coupon reference are required.', 'doughboss' ), array( 'status' => 400 ) );
		}

		/**
		 * Allow the revoke leg to be turned into a no-op (logs intent only) while the
		 * exact POSPal endpoint is being confirmed, without changing call sites.
		 *
		 * @param bool $noop Whether to skip the POSPal call. Default false.
		 */
		if ( apply_filters( 'doughboss_pospal_revoke_noop', false ) ) {
			if ( function_exists( 'error_log' ) ) {
				error_log( 'DoughBoss POSPal: revoke_coupon no-op (endpoint unconfirmed) for a member coupon.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return array( 'noop' => true );
		}

		return self::call(
			'promotionOpenApi',
			'useCustomerPassProduct',
			array(
				'customerUid'    => $customer_uid,
				'passProductUid' => $coupon_ref,
			)
		);
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
	public static function call( $module, $method, array $payload = array() ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'doughboss_pospal_config', __( 'POSPal is not configured.', 'doughboss' ), array( 'status' => 503 ) );
		}

		$module = sanitize_key( $module );
		$method = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $method );
		if ( '' === $module || '' === $method ) {
			return new WP_Error( 'doughboss_pospal_request', __( 'Invalid POSPal request.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$payload['appId'] = self::app_id();
		$raw_body         = wp_json_encode( $payload );
		if ( false === $raw_body ) {
			return new WP_Error( 'doughboss_pospal_encode', __( 'Could not encode the POSPal request.', 'doughboss' ), array( 'status' => 500 ) );
		}

		$url = self::host() . self::API_PREFIX . $module . '/' . $method;

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
					'data-signature' => self::sign( $raw_body ),
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

		return new WP_Error( 'doughboss_pospal_api', $message, array( 'status' => 502 ) );
	}
}
