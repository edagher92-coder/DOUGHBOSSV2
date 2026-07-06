<?php
/**
 * POSPal REST sub-controller.
 *
 * Owns the /pospal/* diagnostic + connection routes (and their handlers),
 * extracted verbatim from DoughBoss_REST_Controller so the POSPal domain is a
 * self-contained unit (release slice 4). Non-money: admin/owner-gated
 * (verify_manage) connection + coupon-grant diagnostics; touches no cart/checkout.
 *
 * @package DoughBoss
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves the POSPal REST endpoints.
 */
class DoughBoss_POSPal_Controller {
	use DoughBoss_REST_Auth;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the POSPal routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ns = DOUGHBOSS_REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/pospal/connect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pospal_connect' ),
				'permission_callback' => array( $this, 'verify_manage' ),
				'args'                => array(
					'enabled' => array(
						'default'           => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'host'    => array(
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
					),
					'app_id'  => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'app_key' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/pospal/test',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'pospal_test' ),
				'permission_callback' => array( $this, 'verify_manage' ),
			)
		);

		register_rest_route(
			$ns,
			'/pospal/verify-coupons',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'pospal_verify_coupons' ),
				'permission_callback' => array( $this, 'verify_manage' ),
			)
		);

		// Dev-only POSPal diagnostics (grant/revoke real coupons on the till;
		// probe-grant brute-forces candidate POSPal endpoints). Registered only
		// under WP_DEBUG so they are not part of the production API surface. The
		// read-only handshake checks (/pospal/test, /pospal/verify-coupons,
		// /mercure/test) stay registered — the Settings screen uses them.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			register_rest_route(
				$ns,
				'/pospal/test-grant',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'pospal_test_grant' ),
					'permission_callback' => array( $this, 'verify_manage' ),
					'args'                => array(
						'phone' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'value' => array(
							'default'           => 5,
							'sanitize_callback' => 'absint',
						),
					),
				)
			);

			register_rest_route(
				$ns,
				'/pospal/test-revoke',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'pospal_test_revoke' ),
					'permission_callback' => array( $this, 'verify_manage' ),
					'args'                => array(
						'customer_uid' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'coupon_ref'   => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);

			register_rest_route(
				$ns,
				'/pospal/probe-grant',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'pospal_probe_grant' ),
					'permission_callback' => array( $this, 'verify_manage' ),
					'args'                => array(
						'phone' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'value' => array(
							'default'           => 5,
							'sanitize_callback' => 'absint',
						),
					),
				)
			);
		}
	}

	/**
	 * POST /pospal/connect — owner action: save the POSPal connection (host,
	 * App ID, App Key, enabled) then immediately run the read-only handshake and
	 * report the account's coupon rules. The secret App Key is stored as a
	 * fallback (env-first is preferred) and is never echoed back in the response.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pospal_connect( WP_REST_Request $request ) {
		$partial = array(
			'pospal_enabled' => $request->get_param( 'enabled' ) ? 1 : 0,
			'pospal_host'    => (string) $request->get_param( 'host' ),
			'pospal_app_id'  => (string) $request->get_param( 'app_id' ),
		);
		$app_key = (string) $request->get_param( 'app_key' );
		if ( '' !== $app_key ) {
			$partial['pospal_app_key'] = $app_key;
		}
		DoughBoss_Settings::update( $partial );

		return $this->pospal_test( $request );
	}

	/**
	 * GET /pospal/test — owner action: read-only POSPal handshake. Confirms the
	 * host + appId/appKey signing are accepted and lists the account's coupon
	 * promotion rules, without changing anything in POSPal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function pospal_test( WP_REST_Request $request ) {
		unset( $request );
		if ( ! DoughBoss_POSPal::ready() ) {
			return rest_ensure_response(
				array(
					'ready'   => false,
					'ok'      => false,
					'message' => __( 'POSPal is not fully configured — enable it and set the host, App ID and App Key.', 'doughboss' ),
				)
			);
		}
		$result = DoughBoss_POSPal::test_connection();
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'ready'   => true,
					'ok'      => false,
					'message' => $result->get_error_message(),
				)
			);
		}
		return rest_ensure_response(
			array(
				'ready'   => true,
				'ok'      => true,
				'message' => __( 'POSPal reachable and the signature was accepted.', 'doughboss' ),
				'rules'   => $result,
			)
		);
	}

	/**
	 * GET /pospal/verify-coupons — owner action: read-only check that the connection
	 * works and that the configured $5 coupon-rule UID matches a real rule
	 * in the POSPal account. No side effects (no member or coupon is created).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function pospal_verify_coupons( WP_REST_Request $request ) {
		$store = DoughBoss_Settings::pospal_store( max( 1, (int) $request->get_param( 'store' ) ) );
		if ( '' === $store['host'] || '' === $store['app_id'] || '' === $store['app_key'] ) {
			/* translators: %s: store label. */
			return rest_ensure_response( array( 'ok' => false, 'message' => sprintf( __( '%s is not configured — set its host, App ID and App Key, then save.', 'doughboss' ), $store['label'] ) ) );
		}
		$creds  = array(
			'host'    => $store['host'],
			'app_id'  => $store['app_id'],
			'app_key' => $store['app_key'],
		);
		$result = DoughBoss_POSPal::verify_coupon_rules( $creds, $store['uid5'] );
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'ok'      => false,
					'message' => $result->get_error_message(),
				)
			);
		}

		$parts = array();
		if ( '' !== $result['uid5'] ) {
			$parts[] = $result['found5'] ? __( '$5 UID matches a rule ✓', 'doughboss' ) : __( '$5 UID NOT found in rules', 'doughboss' );
		}
		if ( empty( $parts ) ) {
			$parts[] = __( 'No coupon UID set yet.', 'doughboss' );
		}

		$all_ok = '' !== $result['uid5'] && $result['found5'];

		/* translators: %d: number of coupon rules returned by POSPal. */
		$prefix = sprintf( _n( 'Connected — %d coupon rule found. ', 'Connected — %d coupon rules found. ', (int) $result['rules_count'], 'doughboss' ), (int) $result['rules_count'] );

		return rest_ensure_response(
			array(
				'ok'      => (bool) $all_ok,
				'message' => $prefix . implode( ' · ', $parts ),
			)
		);
	}

	/**
	 * Build a one-line diagnostic for a failed POSPal test call: stage, endpoint,
	 * HTTP code and a short raw-body snippet, so a method-not-found (404) is
	 * distinguishable from a parameter error at a glance.
	 *
	 * @param string   $stage Human label for the call that failed.
	 * @param WP_Error $err   Error from the POSPal client.
	 * @return string
	 */
	private function pospal_error_detail( $stage, $err ) {
		$data     = (array) $err->get_error_data();
		$http     = isset( $data['http_code'] ) ? (int) $data['http_code'] : 0;
		$endpoint = isset( $data['endpoint'] ) ? (string) $data['endpoint'] : '';
		$body     = isset( $data['body'] ) ? trim( (string) $data['body'] ) : '';
		$out      = $stage;
		if ( '' !== $endpoint ) {
			$out .= ' → ' . $endpoint;
		}
		$out .= ': ' . $err->get_error_message();
		if ( $http ) {
			$out .= ' (HTTP ' . $http . ')';
		}
		if ( '' !== $body ) {
			$out .= ' · raw: ' . substr( $body, 0, 240 );
		}
		return $out;
	}

	/**
	 * POST /pospal/test-grant — owner diagnostic: ensure a (test) member by phone and
	 * grant the coupon mapped to the given dollar value, returning POSPal's RAW
	 * response. This is how the exact coupon-grant method is confirmed: a real grant
	 * surfaces either success or POSPal's own error message. Use a throwaway phone,
	 * then call /pospal/test-revoke. Capability-gated (verify_manage).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function pospal_test_grant( WP_REST_Request $request ) {
		$phone = preg_replace( '/[^0-9+]/', '', (string) $request->get_param( 'phone' ) );
		$value = (int) $request->get_param( 'value' );
		$store = DoughBoss_Settings::pospal_store( max( 1, (int) $request->get_param( 'store' ) ) );
		if ( '' === $phone ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => __( 'Enter a test phone number first.', 'doughboss' ) ) );
		}
		if ( ! DoughBoss_Settings::pospal_enabled() || '' === $store['host'] || '' === $store['app_id'] || '' === $store['app_key'] ) {
			/* translators: %s: store label. */
			return rest_ensure_response( array( 'ok' => false, 'message' => sprintf( __( 'Enable POSPal and configure %s (host, App ID, App Key) first.', 'doughboss' ), $store['label'] ) ) );
		}
		$rule_uid = ( 5 === $value ) ? $store['uid5'] : '';
		if ( '' === $rule_uid ) {
			/* translators: %s: store label. */
			return rest_ensure_response( array( 'ok' => false, 'message' => sprintf( __( 'No coupon UID is mapped for that value at %s — set it and save.', 'doughboss' ), $store['label'] ) ) );
		}
		$creds = array(
			'host'    => $store['host'],
			'app_id'  => $store['app_id'],
			'app_key' => $store['app_key'],
		);

		$member = DoughBoss_POSPal::ensure_member( $phone, 'DoughBoss Test', $creds );
		if ( is_wp_error( $member ) ) {
			return rest_ensure_response(
				array(
					'ok'       => false,
					'stage'    => 'ensure_member',
					'message'  => $this->pospal_error_detail( 'ensure_member (member lookup/create)', $member ),
					'response' => $member->get_error_data(),
				)
			);
		}

		// A throwaway, unique coupon code for the test (POSPal requires the code be
		// globally unique per store). The real flow uses the voucher's own code.
		$code = 'DBTEST' . strtoupper( wp_generate_password( 12, false, false ) );

		$grant = DoughBoss_POSPal::grant_coupon( $member, $rule_uid, $code, $creds );
		if ( is_wp_error( $grant ) ) {
			return rest_ensure_response(
				array(
					'ok'         => false,
					'stage'      => 'grant_coupon',
					'member_uid' => $member,
					'message'    => $this->pospal_error_detail( 'grant_coupon', $grant ),
					'response'   => $grant->get_error_data(),
				)
			);
		}

		return rest_ensure_response(
			array(
				'ok'         => true,
				'stage'      => 'grant_coupon',
				'member_uid' => $member,
				'coupon_ref' => $code,
				'message'    => __( 'Grant accepted by POSPal — coupon code created and attached to the test member.', 'doughboss' ),
				'response'   => $grant,
			)
		);
	}

	/**
	 * POST /pospal/test-revoke — owner diagnostic: revoke a coupon granted by a prior
	 * test-grant so the test member is left clean. Capability-gated (verify_manage).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function pospal_test_revoke( WP_REST_Request $request ) {
		$uid   = sanitize_text_field( (string) $request->get_param( 'customer_uid' ) );
		$ref   = sanitize_text_field( (string) $request->get_param( 'coupon_ref' ) );
		$store = DoughBoss_Settings::pospal_store( max( 1, (int) $request->get_param( 'store' ) ) );
		if ( '' === $ref ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => __( 'Run a test grant first.', 'doughboss' ) ) );
		}
		$creds = array(
			'host'    => $store['host'],
			'app_id'  => $store['app_id'],
			'app_key' => $store['app_key'],
		);
		$res = DoughBoss_POSPal::revoke_coupon( $uid, $ref, $creds );
		if ( is_wp_error( $res ) ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => $res->get_error_message() ) );
		}
		return rest_ensure_response(
			array(
				'ok'       => true,
				'message'  => __( 'Revoke call returned OK.', 'doughboss' ),
				'response' => $res,
			)
		);
	}

	/**
	 * POST /pospal/probe-grant — owner diagnostic: try a list of candidate POSPal
	 * coupon-grant methods against the live account and report which is NOT a 404
	 * (i.e. exists). The assumed `addCustomerPassProduct` 404s (it's the membership-
	 * pass method, not the coupon one); this finds the real coupon method without the
	 * region-blocked docs. A 404 attempt has no side effect; the first non-404 may
	 * create a coupon — revoke via /pospal/test-revoke. Capability-gated.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function pospal_probe_grant( WP_REST_Request $request ) {
		$phone = preg_replace( '/[^0-9+]/', '', (string) $request->get_param( 'phone' ) );
		$value = (int) $request->get_param( 'value' );
		if ( '' === $phone ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => __( 'Enter a test phone number first.', 'doughboss' ) ) );
		}
		if ( ! DoughBoss_POSPal::ready() ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => __( 'Configure + enable POSPal first.', 'doughboss' ) ) );
		}
		$rule_uid = DoughBoss_Settings::pospal_coupon_uid_for( $value );
		if ( '' === $rule_uid ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => __( 'No coupon UID is mapped for that value — set it above and save.', 'doughboss' ) ) );
		}

		$member = DoughBoss_POSPal::ensure_member( $phone, 'DoughBoss Test' );
		if ( is_wp_error( $member ) ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => $this->pospal_error_detail( 'ensure_member', $member ) ) );
		}

		// Candidate coupon-grant methods. A 404 means the method does not exist; the
		// first non-404 exists (and its response then reveals the body fields).
		$candidates = array(
			array( 'promotionOpenApi', 'addCustomerPromotionCoupon' ),
			array( 'promotionOpenApi', 'addCustomerCoupon' ),
			array( 'promotionOpenApi', 'addMemberCoupon' ),
			array( 'promotionOpenApi', 'addMemberPromotionCoupon' ),
			array( 'promotionOpenApi', 'issueCustomerCoupon' ),
			array( 'promotionOpenApi', 'issueCoupon' ),
			array( 'promotionOpenApi', 'sendCustomerCoupon' ),
			array( 'promotionOpenApi', 'addCustomerCouponByPromotion' ),
			array( 'couponOpenApi', 'addCustomerCoupon' ),
			array( 'couponOpenApi', 'addMemberCoupon' ),
			array( 'couponOpenApi', 'issueCoupon' ),
		);

		$body = array(
			'customerUid'  => $member,
			'promotionUid' => $rule_uid,
			'quantity'     => 1,
		);

		$results = array();
		$hit     = null;
		foreach ( $candidates as $c ) {
			$resp = DoughBoss_POSPal::call( $c[0], $c[1], $body );
			if ( is_wp_error( $resp ) ) {
				$d    = (array) $resp->get_error_data();
				$code = isset( $d['http_code'] ) ? (int) $d['http_code'] : 0;
				$note = $resp->get_error_message();
			} else {
				$code = 200;
				$note = 'SUCCESS';
			}
			$row       = array(
				'endpoint' => $c[0] . '/' . $c[1],
				'http'     => $code,
				'note'     => substr( (string) $note, 0, 100 ),
			);
			$results[] = $row;
			if ( 404 !== $code ) {
				$hit = $row;
				break;
			}
		}

		$lines = array();
		foreach ( $results as $r ) {
			$lines[] = $r['endpoint'] . ' -> HTTP ' . $r['http'] . ( '' !== $r['note'] ? ' (' . $r['note'] . ')' : '' );
		}

		return rest_ensure_response(
			array(
				'ok'         => (bool) $hit,
				'member_uid' => $member,
				'hit'        => $hit,
				'message'    => ( $hit
					? 'Method exists: ' . $hit['endpoint'] . ' — HTTP ' . $hit['http'] . '. ' . $hit['note']
					: 'All candidates returned 404 — send me this list and I will expand it.' )
					. "\n" . implode( "\n", $lines ),
			)
		);
	}
}
