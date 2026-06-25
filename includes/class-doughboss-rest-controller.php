<?php
/**
 * REST API controller for menu, cart, checkout and order tracking.
 *
 * Routes live under the `doughboss/v1` namespace. All pricing is computed
 * server-side; the client's reported prices are never trusted.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves the plugin's REST endpoints.
 */
class DoughBoss_REST_Controller {

	/**
	 * Cart instance.
	 *
	 * @var DoughBoss_Cart
	 */
	private $cart;

	/**
	 * Constructor.
	 *
	 * @param DoughBoss_Cart $cart Cart service.
	 */
	public function __construct( DoughBoss_Cart $cart ) {
		$this->cart = $cart;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// Allow the standalone staff console (separate origin) to call our routes.
		add_action( 'rest_api_init', array( $this, 'enable_cors' ), 15 );
	}

	/**
	 * Swap WordPress's permissive default CORS handling for a scoped one: only
	 * the configured console origin, only this plugin's namespace.
	 *
	 * @return void
	 */
	public function enable_cors() {
		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		add_filter( 'rest_pre_serve_request', array( $this, 'send_cors_headers' ), 10, 4 );
	}

	/**
	 * Send CORS headers for the staff console origin on doughboss/v1 routes
	 * (Application Password auth). Scoped — never site-wide wildcard.
	 *
	 * @param bool             $served  Whether the request has been served.
	 * @param WP_HTTP_Response $result  Result to send.
	 * @param WP_REST_Request  $request Request.
	 * @param WP_REST_Server   $server  Server instance.
	 * @return bool
	 */
	public function send_cors_headers( $served, $result, $request, $server ) {
		unset( $result, $server );
		$origin  = get_http_origin();
		$allowed = DoughBoss_Settings::app_origin();
		$route   = (string) $request->get_route();
		if ( $origin && $allowed && $origin === $allowed && 0 === strpos( $route, '/' . DOUGHBOSS_REST_NAMESPACE ) ) {
			header( 'Access-Control-Allow-Origin: ' . $allowed );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
			header( 'Vary: Origin' );
		}
		return $served;
	}

	/**
	 * Register all routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ns = DOUGHBOSS_REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/config',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_config' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$ns,
			'/menu',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_menu' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$ns,
			'/locations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_locations' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$ns,
			'/cart',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_cart' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$ns,
			'/cart/add',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_to_cart' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'type'     => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'item_id'  => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'size'     => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'toppings' => array(
						'default' => array(),
					),
					'quantity' => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/cart/update',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_cart' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'key'      => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'quantity' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/cart/remove',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'remove_from_cart' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'key' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/voucher/validate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'voucher_validate' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'code' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/voucher/redeem',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'voucher_redeem' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'code'            => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'idempotency_key' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/voucher/claim',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'voucher_claim' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'campaign'       => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'customer_phone' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'customer_email' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_email',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/voucher/issue',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'voucher_issue' ),
				'permission_callback' => array( $this, 'verify_manage' ),
			)
		);

		register_rest_route(
			$ns,
			'/voucher/scan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'voucher_scan' ),
				'permission_callback' => array( $this, 'verify_redeem' ),
				'args'                => array(
					'code'            => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'subtotal'        => array(
						'default'           => 0,
						// Wrap the cast: WordPress passes 3 args to sanitize
						// callbacks and the built-in floatval() accepts exactly 1
						// (fatal ArgumentCountError on PHP 8).
						'sanitize_callback' => static function ( $value ) {
							return (float) $value;
						},
					),
					'idempotency_key' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/voucher/activity',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'voucher_activity' ),
				'permission_callback' => array( $this, 'verify_redeem' ),
			)
		);

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

		register_rest_route(
			$ns,
			'/mercure/test',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'mercure_test' ),
				'permission_callback' => array( $this, 'verify_manage' ),
			)
		);

		register_rest_route(
			$ns,
			'/auth/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'auth_me' ),
				'permission_callback' => array( $this, 'verify_staff' ),
			)
		);

		register_rest_route(
			$ns,
			'/admin/vouchers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'admin_vouchers' ),
				'permission_callback' => array( $this, 'verify_manage' ),
				'args'                => array(
					'limit' => array(
						'default'           => 100,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/voucher/void',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_void_voucher' ),
				'permission_callback' => array( $this, 'verify_manage' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/cart/clear',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear_cart' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
			)
		);

		register_rest_route(
			$ns,
			'/cart/apply-voucher',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cart_apply_voucher' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'code'       => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'order_type' => array(
						'default'           => 'pickup',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/cart/remove-voucher',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cart_remove_voucher' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'order_type' => array(
						'default'           => 'pickup',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/payment-intent',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_payment_intent' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'order_type' => array(
						'default'           => 'pickup',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/checkout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'checkout' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
			)
		);

		register_rest_route(
			$ns,
			'/order/(?P<number>[A-Za-z0-9\-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'track_order' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/admin/order/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_update_status' ),
				'permission_callback' => array( $this, 'verify_admin' ),
				'args'                => array(
					'status' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Live kitchen order board: incremental feed of active orders.
		register_rest_route(
			$ns,
			'/admin/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'admin_orders' ),
				'permission_callback' => array( $this, 'verify_admin' ),
			)
		);

		// Acknowledge a new order (silences the board alert).
		register_rest_route(
			$ns,
			'/admin/order/(?P<id>\d+)/ack',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_acknowledge' ),
				'permission_callback' => array( $this, 'verify_admin' ),
			)
		);

		// Accept an order and set an ETA.
		register_rest_route(
			$ns,
			'/admin/order/(?P<id>\d+)/accept',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_accept' ),
				'permission_callback' => array( $this, 'verify_admin' ),
				'args'                => array(
					'eta' => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Catering — published packages for the catering page (public).
		register_rest_route(
			$ns,
			'/catering/packages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_catering_packages' ),
				'permission_callback' => '__return_true',
			)
		);

		// Catering — indicative quote for a package + headcount (public, read-only).
		register_rest_route(
			$ns,
			'/catering/quote',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_catering_quote' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'package_id'  => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'guest_count' => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'order_type'  => array(
						'default'           => 'pickup',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Catering — submit an enquiry (lead capture). Nonce-gated like cart routes.
		register_rest_route(
			$ns,
			'/catering/enquiry',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_catering_enquiry' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'customer_name'  => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'customer_email' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
					'customer_phone' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'package_id'     => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'guest_count'    => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'order_type'     => array(
						'default'           => 'pickup',
						'sanitize_callback' => 'sanitize_key',
					),
					'event_date'     => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'event_time'     => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'address'        => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'dietary'        => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'notes'          => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'location_id'    => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'hp'             => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Catering — staff status update for an enquiry.
		register_rest_route(
			$ns,
			'/admin/catering/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_update_catering_status' ),
				'permission_callback' => array( $this, 'verify_admin' ),
				'args'                => array(
					'status' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Catering — create a PaymentIntent for a deposit or balance leg. Gated by
		// a valid REST nonce AND the enquiry number + email matching.
		register_rest_route(
			$ns,
			'/catering/payment-intent',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'catering_payment_intent' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'enquiry_number' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'          => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
					'leg'            => array(
						'default'           => 'deposit',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Catering — verify a deposit/balance payment server-side and record it.
		register_rest_route(
			$ns,
			'/catering/confirm-payment',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'catering_confirm_payment' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'enquiry_number'    => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'             => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
					'leg'               => array(
						'default'           => 'deposit',
						'sanitize_callback' => 'sanitize_key',
					),
					'payment_intent_id' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Catering — Stripe webhook (authoritative payment source-of-truth).
		// Public route, gated by the Stripe-Signature HMAC, not a nonce.
		register_rest_route(
			$ns,
			'/catering/stripe-webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'catering_stripe_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Permission check: valid REST nonce required for state-changing calls.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function verify_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}
		return new WP_Error( 'doughboss_bad_nonce', __( 'Session expired. Please refresh the page.', 'doughboss' ), array( 'status' => 403 ) );
	}

	/**
	 * Permission check: require the management capability.
	 *
	 * @return bool|WP_Error
	 */
	public function verify_admin() {
		if ( current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_doughboss_kds' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'doughboss_forbidden', __( 'You are not allowed to do that.', 'doughboss' ), array( 'status' => 403 ) );
	}

	/**
	 * Permission check: require the owner management capability only (not the
	 * lower kitchen/KDS cap). Used for issuing/managing vouchers so a till
	 * device can never create value.
	 *
	 * @return bool|WP_Error
	 */
	public function verify_manage() {
		if ( current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'doughboss_forbidden', __( 'You are not allowed to do that.', 'doughboss' ), array( 'status' => 403 ) );
	}

	/**
	 * Permission check for the in-store voucher scan dashboard: the dedicated
	 * redeem capability (granted to the owner and the kitchen role on shop
	 * tablets) or full management. A till device can redeem but never issue
	 * value — issuing stays behind verify_manage().
	 *
	 * @return bool|WP_Error
	 */
	public function verify_redeem() {
		if ( current_user_can( 'redeem_doughboss_vouchers' ) || current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'doughboss_forbidden', __( 'You are not allowed to do that.', 'doughboss' ), array( 'status' => 403 ) );
	}

	/**
	 * Permission check for the staff console's login probe: any DoughBoss staff
	 * role (redeem, board, or management). Returns 401 so the console can prompt
	 * for valid credentials.
	 *
	 * @return bool|WP_Error
	 */
	public function verify_staff() {
		if ( current_user_can( 'redeem_doughboss_vouchers' ) || current_user_can( 'manage_doughboss_kds' ) || current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'doughboss_unauthorized', __( 'Sign in with a DoughBoss staff account.', 'doughboss' ), array( 'status' => 401 ) );
	}

	/**
	 * Mask a customer phone for the shared till feed — show only the last 3
	 * digits so staff can still match a customer without exposing the full
	 * number on a counter-facing screen.
	 *
	 * @param string $phone Raw phone.
	 * @return string
	 */
	private function mask_phone( $phone ) {
		$phone = trim( (string) $phone );
		$len   = strlen( $phone );
		if ( $len <= 3 ) {
			return $phone;
		}
		return str_repeat( '•', $len - 3 ) . substr( $phone, -3 );
	}

	/**
	 * Simple per-IP transient rate limiter for mutation endpoints.
	 *
	 * @param string $bucket Logical bucket name (e.g. 'checkout').
	 * @param int    $max    Max requests allowed within the window.
	 * @param int    $window Window length in seconds.
	 * @return bool True when the caller is over the limit.
	 */
	private function rate_limited( $bucket, $max, $window ) {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key  = 'doughboss_rl_' . $bucket . '_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= $max ) {
			return true;
		}
		set_transient( $key, $hits + 1, $window );
		return false;
	}

	/**
	 * Server-computed cart subtotal used for voucher maths. Never trusts a
	 * browser-reported amount.
	 *
	 * @return float
	 */
	private function cart_subtotal() {
		$totals = $this->cart->totals();
		if ( isset( $totals['subtotal'] ) ) {
			return (float) $totals['subtotal'];
		}
		if ( isset( $totals['total'] ) ) {
			return (float) $totals['total'];
		}
		return 0.0;
	}

	/**
	 * POST /voucher/validate — preview a voucher's discount against the current
	 * cart. Purely local (no POSPal call); stays opaque about why a code fails.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function voucher_validate( WP_REST_Request $request ) {
		if ( $this->rate_limited( 'voucher_validate', 12, 600 ) ) {
			return new WP_Error( 'doughboss_rate', __( 'Too many attempts. Please wait a moment.', 'doughboss' ), array( 'status' => 429 ) );
		}
		$subtotal = $this->cart_subtotal();
		$row      = DoughBoss_Voucher::find_by_code( (string) $request->get_param( 'code' ) );
		$eval     = DoughBoss_Voucher::evaluate( $row, $subtotal, 'online' );

		if ( ! $row || ! $eval['valid'] ) {
			$message = ( $row && 'min_spend' === $eval['reason'] )
				? __( 'Your order doesn’t meet this voucher’s minimum spend.', 'doughboss' )
				: __( 'This voucher code isn’t valid.', 'doughboss' );
			return rest_ensure_response(
				array(
					'valid'   => false,
					'message' => $message,
				)
			);
		}

		return rest_ensure_response(
			array(
				'valid'   => true,
				'amount'  => $eval['amount'],
				'message' => sprintf(
					/* translators: %s: formatted discount amount. */
					__( '%s off applied.', 'doughboss' ),
					DoughBoss_Settings::format_price( $eval['amount'] )
				),
			)
		);
	}

	/**
	 * POST /voucher/redeem — atomically redeem a voucher against the current
	 * cart and return the applied discount.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function voucher_redeem( WP_REST_Request $request ) {
		if ( $this->rate_limited( 'voucher_redeem', 6, 3600 ) ) {
			return new WP_Error( 'doughboss_rate', __( 'Too many attempts. Please wait a moment.', 'doughboss' ), array( 'status' => 429 ) );
		}
		$result = DoughBoss_Voucher::redeem(
			(string) $request->get_param( 'code' ),
			$this->cart_subtotal(),
			'online',
			array( 'idempotency_key' => (string) $request->get_param( 'idempotency_key' ) )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'redeemed' => true,
				'code'     => $result['code'],
				'amount'   => $result['amount'],
			)
		);
	}

	/**
	 * POST /voucher/scan — in-store staff redeem (atomic single-use).
	 *
	 * Unlike the online /voucher/redeem (which reads the guest cart), this takes
	 * the code a staff member scans or keys at the till plus the order subtotal,
	 * and commits against the 'instore' channel. The same atomic lock means the
	 * voucher dies on the first scan and can no longer be used online.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function voucher_scan( WP_REST_Request $request ) {
		if ( $this->rate_limited( 'voucher_scan', 240, 600 ) ) {
			return new WP_Error( 'doughboss_rate', __( 'Too many scans. Please wait a moment.', 'doughboss' ), array( 'status' => 429 ) );
		}
		$code     = (string) $request->get_param( 'code' );
		$subtotal = (float) $request->get_param( 'subtotal' );

		// A busy till may not key the order total. A flat amount voucher with no
		// minimum spend can safely apply its full value without one; but a
		// percent voucher (which needs the total to compute) or any voucher with
		// a minimum spend requires the real total — never fabricate one, since a
		// made-up subtotal would mis-price a percent discount and silently skip
		// the minimum-spend check.
		if ( $subtotal <= 0 ) {
			$row = DoughBoss_Voucher::find_by_code( $code );
			if ( $row ) {
				if ( 'percent' === $row->type || (float) $row->min_spend > 0 ) {
					return new WP_Error( 'doughboss_need_total', __( 'Enter the order total to redeem this voucher.', 'doughboss' ), array( 'status' => 400 ) );
				}
				$subtotal = (float) $row->value;
			}
			// Unknown code: leave subtotal at 0 so redeem() returns the same
			// opaque "not valid" error without revealing the code doesn't exist.
		}

		$result = DoughBoss_Voucher::redeem(
			$code,
			$subtotal,
			'instore',
			array( 'idempotency_key' => (string) $request->get_param( 'idempotency_key' ) )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'redeemed' => true,
				'code'     => $result['code'],
				'amount'   => $result['amount'],
				'message'  => sprintf(
					/* translators: %s: formatted discount amount. */
					__( '%s applied — voucher redeemed.', 'doughboss' ),
					DoughBoss_Settings::format_price( $result['amount'] )
				),
			)
		);
	}

	/**
	 * GET /voucher/activity — live dashboard payload for staff: today's campaign
	 * release counts (with shared-pool usage), status tiles and the most recent
	 * vouchers with their redemption state.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function voucher_activity( WP_REST_Request $request ) {
		unset( $request );
		$campaigns = array();
		foreach ( DoughBoss_Voucher::campaigns() as $c ) {
			$cap         = (int) ( isset( $c['daily_cap'] ) ? $c['daily_cap'] : 0 );
			$used        = DoughBoss_Voucher::claimed_today_for( $c );
			$campaigns[] = array(
				'slug'      => isset( $c['slug'] ) ? $c['slug'] : '',
				'label'     => isset( $c['label'] ) ? $c['label'] : '',
				'value'     => isset( $c['value'] ) ? (float) $c['value'] : 0,
				'type'      => isset( $c['type'] ) ? $c['type'] : 'amount',
				'cap'       => $cap,
				'claimed'   => DoughBoss_Voucher::claimed_today( isset( $c['slug'] ) ? $c['slug'] : '' ),
				'pool_used' => $used,
				'remaining' => $cap > 0 ? max( 0, $cap - $used ) : -1,
				'shared'    => ! empty( $c['cap_group'] ),
				'active'    => ! empty( $c['active'] ),
			);
		}

		$recent = array();
		foreach ( DoughBoss_Voucher::query( 30 ) as $r ) {
			$recent[] = array(
				'id'          => (int) $r->id,
				'code'        => $r->code,
				'value'       => (float) $r->value,
				'type'        => $r->type,
				'status'      => $r->status,
				'campaign'    => $r->campaign,
				'phone'       => $this->mask_phone( $r->customer_phone ),
				'redeemed_at' => isset( $r->redeemed_at ) ? $r->redeemed_at : null,
				'channel'     => isset( $r->redeemed_channel ) ? $r->redeemed_channel : '',
				'amount'      => isset( $r->amount_applied ) ? (float) $r->amount_applied : 0,
				'created_at'  => $r->created_at,
			);
		}

		return rest_ensure_response(
			array(
				'campaigns' => $campaigns,
				'recent'    => $recent,
				'totals'    => array(
					'issued'   => DoughBoss_Voucher::count_status( 'issued' ),
					'redeemed' => DoughBoss_Voucher::count_status( 'redeemed' ),
					'voided'   => DoughBoss_Voucher::count_status( 'voided' ),
				),
				'currency'  => DoughBoss_Settings::get( 'currency_symbol', '$' ),
			)
		);
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
	 * works and that the configured $5 / $10 coupon-rule UIDs each match a real rule
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
		$result = DoughBoss_POSPal::verify_coupon_rules( $creds, $store['uid5'], $store['uid10'] );
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
		if ( '' !== $result['uid10'] ) {
			$parts[] = $result['found10'] ? __( '$10 UID matches a rule ✓', 'doughboss' ) : __( '$10 UID NOT found in rules', 'doughboss' );
		}
		if ( empty( $parts ) ) {
			$parts[] = __( 'No coupon UIDs set yet.', 'doughboss' );
		}

		$all_ok = ( '' === $result['uid5'] || $result['found5'] )
			&& ( '' === $result['uid10'] || $result['found10'] )
			&& ( '' !== $result['uid5'] || '' !== $result['uid10'] );

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
		$rule_uid = ( 5 === $value ) ? $store['uid5'] : ( ( 10 === $value ) ? $store['uid10'] : '' );
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

	/**
	 * GET /mercure/test — owner action: a blocking test publish to the Mercure
	 * hub. Confirms the hub is reachable and the publish JWT is accepted, turning
	 * the otherwise fire-and-forget publish path into something diagnosable. Uses
	 * the stored hub URL + JWT (server-side); never returns the credential.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function mercure_test( WP_REST_Request $request ) {
		unset( $request );
		$configured = '' !== DoughBoss_Settings::mercure_hub_url() && '' !== DoughBoss_Settings::mercure_publish_jwt();
		if ( ! $configured ) {
			return rest_ensure_response(
				array(
					'ready'   => false,
					'ok'      => false,
					'message' => __( 'Mercure is not configured — set the hub URL and publish JWT, then save before testing.', 'doughboss' ),
				)
			);
		}
		$result = DoughBoss_Mercure::test();
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'ready'   => true,
					'ok'      => false,
					'message' => $result->get_error_message(),
				)
			);
		}
		// A passing test only proves connectivity; live publishing still requires
		// the Enable Mercure toggle (mercure_ready() also checks it). Say so when it
		// is off, so "test passed" is never misread as "real-time is live".
		$message = DoughBoss_Settings::mercure_enabled()
			? __( 'Hub reachable and the publish JWT was accepted. Real-time is live.', 'doughboss' )
			: __( 'Hub reachable and the publish JWT was accepted. Tick “Enable Mercure” and save to use it live.', 'doughboss' );
		return rest_ensure_response(
			array(
				'ready'   => true,
				'ok'      => true,
				'message' => $message,
			)
		);
	}

	/**
	 * GET /auth/me — who the console is signed in as, and which screens they may
	 * use. Lets the standalone app verify credentials and show the right tabs.
	 *
	 * @return WP_REST_Response
	 */
	public function auth_me() {
		$user = wp_get_current_user();
		return rest_ensure_response(
			array(
				'name'       => $user ? $user->display_name : '',
				'can_redeem' => current_user_can( 'redeem_doughboss_vouchers' ) || current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ),
				'can_manage' => current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ),
				'can_board'  => current_user_can( 'manage_doughboss_kds' ) || current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ),
				'currency'   => DoughBoss_Settings::get( 'currency_symbol', '$' ),
			)
		);
	}

	/**
	 * GET /admin/vouchers — list recent vouchers with redemption state for the
	 * console's Vouchers screen (owner only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function admin_vouchers( WP_REST_Request $request ) {
		$limit = (int) $request->get_param( 'limit' );
		$rows  = DoughBoss_Voucher::query( $limit > 0 ? $limit : 100 );
		$out   = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'          => (int) $r->id,
				'code'        => $r->code,
				'type'        => $r->type,
				'value'       => (float) $r->value,
				'status'      => $r->status,
				'campaign'    => $r->campaign,
				'phone'       => $r->customer_phone,
				'email'       => $r->customer_email,
				'redeemed_at' => isset( $r->redeemed_at ) ? $r->redeemed_at : null,
				'amount'      => isset( $r->amount_applied ) ? (float) $r->amount_applied : 0,
				'channel'     => isset( $r->redeemed_channel ) ? $r->redeemed_channel : '',
				'created_at'  => $r->created_at,
			);
		}
		return rest_ensure_response(
			array(
				'vouchers'  => $out,
				'campaigns' => DoughBoss_Voucher::campaigns(),
			)
		);
	}

	/**
	 * POST /voucher/void — void an issued voucher by id (owner only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_void_voucher( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );
		if ( ! DoughBoss_Voucher::void( $id ) ) {
			return new WP_Error( 'doughboss_void', __( 'Could not void — not found or already used.', 'doughboss' ), array( 'status' => 409 ) );
		}
		return rest_ensure_response(
			array(
				'voided' => true,
				'id'     => $id,
			)
		);
	}

	/**
	 * POST /voucher/issue — create a voucher (owner/admin only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function voucher_issue( WP_REST_Request $request ) {
		$result = DoughBoss_Voucher::issue(
			array(
				'type'           => $request->get_param( 'type' ),
				'value'          => $request->get_param( 'value' ),
				'prefix'         => $request->get_param( 'prefix' ),
				'min_spend'      => $request->get_param( 'min_spend' ),
				'scope'          => $request->get_param( 'scope' ),
				'location_id'    => $request->get_param( 'location_id' ),
				'customer_phone' => $request->get_param( 'customer_phone' ),
				'customer_email' => $request->get_param( 'customer_email' ),
				'valid_from'     => $request->get_param( 'valid_from' ),
				'valid_to'       => $request->get_param( 'valid_to' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'issued' => true,
				'id'     => $result['id'],
				'code'   => $result['code'],
			)
		);
	}

	/**
	 * POST /voucher/claim — claim a voucher from a daily-capped campaign (e.g.
	 * the Dough Boss × Snow Boss $5 or $10 student vouchers, which share one
	 * daily pool of 100). Public (nonce) + rate-limited; the per-day cap is
	 * enforced server-side, pooled across the campaign's cap_group.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function voucher_claim( WP_REST_Request $request ) {
		if ( $this->rate_limited( 'voucher_claim', 8, 600 ) ) {
			return new WP_Error( 'doughboss_rate', __( 'Too many attempts. Please wait a moment.', 'doughboss' ), array( 'status' => 429 ) );
		}
		$result = DoughBoss_Voucher::claim(
			(string) $request->get_param( 'campaign' ),
			array(
				'customer_phone' => $request->get_param( 'customer_phone' ),
				'customer_email' => $request->get_param( 'customer_email' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'claimed' => true,
				'code'    => $result['code'],
			)
		);
	}

	/**
	 * GET /config — storefront configuration for the JS app.
	 *
	 * @return WP_REST_Response
	 */
	public function get_config() {
		return rest_ensure_response(
			array(
				'currency_symbol' => DoughBoss_Settings::get( 'currency_symbol', '$' ),
				'currency_code'   => DoughBoss_Settings::get( 'currency_code', 'AUD' ),
				'tax_rate'        => (float) DoughBoss_Settings::get( 'tax_rate', 0 ),
				'gst_inclusive'   => DoughBoss_Settings::gst_inclusive(),
				'delivery_fee'    => (float) DoughBoss_Settings::get( 'delivery_fee', 0 ),
				'enable_pickup'   => (bool) DoughBoss_Settings::get( 'enable_pickup', 1 ),
				'enable_delivery' => (bool) DoughBoss_Settings::get( 'enable_delivery', 0 ),
				'ordering_open'   => DoughBoss_Settings::ordering_open(),
				'sizes'           => DoughBoss_Settings::sizes(),
				'toppings'        => DoughBoss_Settings::toppings(),
				'payments_enabled' => DoughBoss_Stripe::ready(),
				'stripe_pk'        => DoughBoss_Stripe::ready() ? DoughBoss_Stripe::publishable_key() : '',
				// Mercure real-time config for the standalone Console (no secrets —
				// only the public hub URL + topic; the publish JWT never leaves the
				// server, and the board topic is publicly readable).
				'mercure'          => array(
					'enabled' => DoughBoss_Settings::mercure_ready(),
					'url'     => DoughBoss_Settings::mercure_hub_url(),
					'topic'   => DoughBoss_Mercure::topic(),
				),
			)
		);
	}

	/**
	 * POST /payment-intent — create a Stripe PaymentIntent for the current cart.
	 *
	 * Returns the client secret the browser needs to confirm the card payment.
	 * The amount is computed server-side from the cart; it is re-verified against
	 * the PaymentIntent again at checkout, so a tampered client cannot underpay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_payment_intent( WP_REST_Request $request ) {
		if ( ! DoughBoss_Stripe::ready() ) {
			return new WP_Error( 'doughboss_pay_off', __( 'Card payments are not available right now.', 'doughboss' ), array( 'status' => 400 ) );
		}

		if ( $this->cart->is_empty() ) {
			return new WP_Error( 'doughboss_empty', __( 'Your cart is empty.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$order_type = sanitize_key( $request->get_param( 'order_type' ) );
		$order_type = ( 'delivery' === $order_type ) ? 'delivery' : 'pickup';

		$totals   = $this->cart->totals( $order_type );
		$currency = DoughBoss_Settings::get( 'currency_code', 'AUD' );
		$amount   = DoughBoss_Stripe::to_minor_units( $totals['total'] );

		$intent = DoughBoss_Stripe::create_payment_intent(
			$amount,
			$currency,
			array(
				'order_type' => $order_type,
				'site'       => home_url(),
			)
		);

		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		return rest_ensure_response(
			array(
				'client_secret'   => $intent['client_secret'],
				'payment_intent'  => $intent['id'],
				'publishable_key' => DoughBoss_Stripe::publishable_key(),
				'amount'          => $intent['amount'],
				'currency'        => $intent['currency'],
			)
		);
	}

	/**
	 * GET /locations — active shops for the storefront shop picker.
	 *
	 * @return WP_REST_Response
	 */
	public function get_locations() {
		$out = array();
		foreach ( DoughBoss_Locations::all( true ) as $loc ) {
			$out[] = DoughBoss_Locations::public_view( $loc );
		}
		return rest_ensure_response( $out );
	}

	/**
	 * GET /menu — published menu items grouped by category.
	 *
	 * @return WP_REST_Response
	 */
	public function get_menu() {
		$posts = get_posts(
			array(
				'post_type'      => DoughBoss_Post_Types::POST_TYPE,
				'post_status'    => 'publish',
				'numberposts'    => 200,
				'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			$terms      = get_the_terms( $post->ID, DoughBoss_Post_Types::TAXONOMY );
			$category   = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : __( 'Menu', 'doughboss' );
			$thumb      = get_the_post_thumbnail_url( $post->ID, 'medium' );
			$items[]    = array(
				'id'          => $post->ID,
				'name'        => get_the_title( $post ),
				'description' => wp_strip_all_tags( $post->post_excerpt ? $post->post_excerpt : $post->post_content ),
				'price'       => (float) get_post_meta( $post->ID, DoughBoss_Post_Types::META_PRICE, true ),
				'type'        => get_post_meta( $post->ID, DoughBoss_Post_Types::META_TYPE, true ),
				'image'       => $thumb ? $thumb : '',
				'category'    => $category,
				'available'   => DoughBoss_Post_Types::is_available( $post->ID ),
				'dietary'     => DoughBoss_Post_Types::dietary( $post->ID ),
			);
		}

		return rest_ensure_response( $items );
	}

	/**
	 * GET /cart — current cart contents.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_cart( WP_REST_Request $request ) {
		$order_type = sanitize_key( $request->get_param( 'order_type' ) );
		$order_type = ( 'delivery' === $order_type ) ? 'delivery' : 'pickup';
		return rest_ensure_response( $this->cart->to_array( $order_type ) );
	}

	/**
	 * POST /cart/add — add a menu item or custom pizza to the cart.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_to_cart( WP_REST_Request $request ) {
		$type     = $request->get_param( 'type' );
		$quantity = max( 1, (int) $request->get_param( 'quantity' ) );

		if ( 'custom' === $type ) {
			$line = $this->build_custom_line( $request );
		} else {
			$line = $this->build_menu_line( $request );
		}

		if ( is_wp_error( $line ) ) {
			return $line;
		}

		$line['quantity'] = $quantity;
		$result           = $this->cart->add( $line );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'added' => $result,
				'cart'  => $this->cart->to_array(),
			)
		);
	}

	/**
	 * Build a cart line from a published menu item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private function build_menu_line( WP_REST_Request $request ) {
		$item_id = absint( $request->get_param( 'item_id' ) );
		$post    = get_post( $item_id );

		if ( ! $post || DoughBoss_Post_Types::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error( 'doughboss_no_item', __( 'That item is not available.', 'doughboss' ), array( 'status' => 404 ) );
		}

		if ( ! DoughBoss_Post_Types::is_available( $item_id ) ) {
			/* translators: %s: menu item name. */
			return new WP_Error( 'doughboss_sold_out', sprintf( __( 'Sorry, %s is sold out right now.', 'doughboss' ), get_the_title( $post ) ), array( 'status' => 409 ) );
		}

		$price = (float) get_post_meta( $item_id, DoughBoss_Post_Types::META_PRICE, true );

		return array(
			'type'       => 'menu',
			'item_id'    => $item_id,
			'name'       => get_the_title( $post ),
			'size'       => '',
			'toppings'   => array(),
			'unit_price' => $price,
		);
	}

	/**
	 * Build a cart line for a custom-built pizza, pricing it server-side.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private function build_custom_line( WP_REST_Request $request ) {
		$size_slug = sanitize_key( $request->get_param( 'size' ) );
		$size      = DoughBoss_Settings::find_size( $size_slug );

		if ( ! $size ) {
			return new WP_Error( 'doughboss_no_size', __( 'Please choose a valid size.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$price             = (float) $size['price'];
		$selected_toppings = array();
		$raw_toppings      = (array) $request->get_param( 'toppings' );

		foreach ( $raw_toppings as $slug ) {
			$topping = DoughBoss_Settings::find_topping( sanitize_key( $slug ) );
			if ( $topping ) {
				$price              += (float) $topping['price'];
				$selected_toppings[] = array(
					'slug'  => $topping['slug'],
					'label' => $topping['label'],
					'price' => (float) $topping['price'],
				);
			}
		}

		return array(
			'type'       => 'custom',
			'item_id'    => 0,
			/* translators: %s: pizza size label. */
			'name'       => sprintf( __( 'Custom Pizza (%s)', 'doughboss' ), $size['label'] ),
			'size'       => $size['label'],
			'toppings'   => $selected_toppings,
			'unit_price' => round( $price, 2 ),
		);
	}

	/**
	 * POST /cart/update — change a line's quantity.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_cart( WP_REST_Request $request ) {
		$ok = $this->cart->update_quantity( $request->get_param( 'key' ), (int) $request->get_param( 'quantity' ) );
		if ( ! $ok ) {
			return new WP_Error( 'doughboss_no_line', __( 'That cart item no longer exists.', 'doughboss' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->cart->to_array() );
	}

	/**
	 * POST /cart/remove — remove a line.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_from_cart( WP_REST_Request $request ) {
		$this->cart->remove( $request->get_param( 'key' ) );
		return rest_ensure_response( $this->cart->to_array() );
	}

	/**
	 * POST /cart/clear — empty the cart.
	 *
	 * @return WP_REST_Response
	 */
	public function clear_cart() {
		$this->cart->clear();
		return rest_ensure_response( $this->cart->to_array() );
	}

	/**
	 * POST /cart/apply-voucher — hold a voucher code on the cart and preview the
	 * discount. Preview only: nothing is redeemed until checkout, so an
	 * abandoned cart never burns the customer's single-use voucher.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cart_apply_voucher( WP_REST_Request $request ) {
		if ( $this->rate_limited( 'voucher_apply', 15, 600 ) ) {
			return new WP_Error( 'doughboss_rate', __( 'Too many attempts. Please wait a moment.', 'doughboss' ), array( 'status' => 429 ) );
		}
		$order_type = ( 'delivery' === $request->get_param( 'order_type' ) ) ? 'delivery' : 'pickup';
		$code       = strtoupper( trim( (string) $request->get_param( 'code' ) ) );
		if ( '' === $code ) {
			return new WP_Error( 'doughboss_voucher_invalid', __( 'This voucher code isn’t valid.', 'doughboss' ), array( 'status' => 422 ) );
		}

		$totals = $this->cart->totals( $order_type );
		$row    = DoughBoss_Voucher::find_by_code( $code );
		$eval   = DoughBoss_Voucher::evaluate( $row, $totals['subtotal'], 'online' );
		if ( empty( $eval['valid'] ) ) {
			if ( $row && 'min_spend' === $eval['reason'] ) {
				return new WP_Error( 'doughboss_voucher_min', __( 'Your order doesn’t meet this voucher’s minimum spend.', 'doughboss' ), array( 'status' => 422 ) );
			}
			return new WP_Error( 'doughboss_voucher_invalid', __( 'This voucher code isn’t valid.', 'doughboss' ), array( 'status' => 422 ) );
		}

		$this->cart->set_voucher_code( $code );
		return rest_ensure_response(
			array(
				'applied' => true,
				'cart'    => $this->cart->to_array( $order_type ),
				'message' => sprintf(
					/* translators: %s: formatted discount amount. */
					__( '%s off applied.', 'doughboss' ),
					DoughBoss_Settings::format_price( $eval['amount'] )
				),
			)
		);
	}

	/**
	 * POST /cart/remove-voucher — drop the held voucher code from the cart.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function cart_remove_voucher( WP_REST_Request $request ) {
		$order_type = ( 'delivery' === $request->get_param( 'order_type' ) ) ? 'delivery' : 'pickup';
		$this->cart->set_voucher_code( '' );
		return rest_ensure_response(
			array(
				'applied' => false,
				'cart'    => $this->cart->to_array( $order_type ),
			)
		);
	}

	/**
	 * POST /checkout — validate, create the order, clear the cart.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function checkout( WP_REST_Request $request ) {
		// Idempotency: if the client supplies a key and we've already processed
		// it, return the original result instead of creating a duplicate order.
		// Checked first so a replay still succeeds after the cart was cleared.
		$idem = $this->idempotency_key( $request );
		if ( '' !== $idem ) {
			$cached = get_transient( 'doughboss_idem_' . $idem );
			if ( is_array( $cached ) ) {
				return rest_ensure_response( $cached );
			}
		}

		if ( $this->rate_limited( 'checkout', 8, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'doughboss_rate_limit', __( 'Too many requests. Please wait a few minutes and try again.', 'doughboss' ), array( 'status' => 429 ) );
		}

		if ( ! DoughBoss_Settings::ordering_open() ) {
			return new WP_Error( 'doughboss_closed', __( 'Online ordering is currently closed.', 'doughboss' ), array( 'status' => 503 ) );
		}

		if ( $this->cart->is_empty() ) {
			return new WP_Error( 'doughboss_empty', __( 'Your cart is empty.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$order_type = sanitize_key( $request->get_param( 'order_type' ) );
		$order_type = ( 'delivery' === $order_type ) ? 'delivery' : 'pickup';

		if ( 'delivery' === $order_type && ! DoughBoss_Settings::get( 'enable_delivery', 0 ) ) {
			return new WP_Error( 'doughboss_no_delivery', __( 'Delivery is not available.', 'doughboss' ), array( 'status' => 400 ) );
		}
		if ( 'pickup' === $order_type && ! DoughBoss_Settings::get( 'enable_pickup', 1 ) ) {
			return new WP_Error( 'doughboss_no_pickup', __( 'Pickup is not available.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$name  = sanitize_text_field( $request->get_param( 'customer_name' ) );
		$email = sanitize_email( $request->get_param( 'customer_email' ) );
		$phone = sanitize_text_field( $request->get_param( 'customer_phone' ) );
		$notes = sanitize_textarea_field( $request->get_param( 'notes' ) );
		$addr  = sanitize_textarea_field( $request->get_param( 'address' ) );

		$errors = array();
		if ( '' === $name ) {
			$errors[] = __( 'Name is required.', 'doughboss' );
		}
		if ( ! is_email( $email ) ) {
			$errors[] = __( 'A valid email is required.', 'doughboss' );
		}
		if ( '' === $phone ) {
			$errors[] = __( 'Phone number is required.', 'doughboss' );
		}
		if ( 'delivery' === $order_type && '' === $addr ) {
			$errors[] = __( 'A delivery address is required.', 'doughboss' );
		}

		if ( $errors ) {
			return new WP_Error( 'doughboss_invalid', implode( ' ', $errors ), array( 'status' => 400 ) );
		}

		// Resolve which shop the order is for. When shops are configured, accept
		// a valid one or fall back to the default; single-shop sites use 0.
		$location_id = absint( $request->get_param( 'location_id' ) );
		if ( DoughBoss_Locations::count() > 0 && ! DoughBoss_Locations::is_valid( $location_id ) ) {
			$location_id = DoughBoss_Locations::default_id();
		}

		$totals = $this->cart->totals( $order_type );
		$lines  = $this->cart->get_lines();

		// When Stripe is configured, the order is only accepted once a matching
		// PaymentIntent has actually succeeded. The amount/currency are verified
		// against this order's server-computed total, and each PaymentIntent can
		// be used for at most one order.
		$payment_status    = 'unpaid';
		$payment_method    = '';
		$payment_intent_id = '';
		if ( DoughBoss_Stripe::ready() ) {
			$verified = $this->verify_payment( $request, $totals['total'] );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			$payment_status    = 'paid';
			$payment_method    = 'stripe';
			$payment_intent_id = $verified;
		}

		// Redeem a held voucher before the order row is written. The redeem is
		// idempotent (a retry replays the same result), so the single-use voucher
		// is consumed exactly once even if order creation is retried. If it can no
		// longer be redeemed and nothing was paid, reject so the customer can
		// review. (When Stripe is on, verify_payment above already rejects a
		// price mismatch; the paid branch below only survives the razor-thin race
		// where the code is consumed between pricing and redeem — there we keep
		// the discount the customer already paid rather than failing a paid order.)
		$discount     = isset( $totals['discount'] ) ? (float) $totals['discount'] : 0.0;
		$voucher_code = isset( $totals['voucher_code'] ) ? (string) $totals['voucher_code'] : '';
		$voucher_idem = '';
		if ( '' !== $voucher_code && $discount > 0 ) {
			$voucher_idem = 'order_' . ( '' !== $idem ? $idem : md5( $this->cart->get_token() . '|' . $voucher_code ) );
			$redeem       = DoughBoss_Voucher::redeem( $voucher_code, $totals['subtotal'], 'online', array( 'idempotency_key' => $voucher_idem ) );
			if ( is_wp_error( $redeem ) ) {
				if ( 'unpaid' === $payment_status ) {
					$this->cart->set_voucher_code( '' );
					return $redeem;
				}
			} else {
				$discount = (float) $redeem['amount'];
			}
		} else {
			$discount     = 0.0;
			$voucher_code = '';
		}

		$order_id = DoughBoss_Order::create(
			array(
				'order_type'     => $order_type,
				'location_id'    => $location_id,
				'customer_name'  => $name,
				'customer_email' => $email,
				'customer_phone' => $phone,
				'address'        => $addr,
				'notes'          => $notes,
				'subtotal'       => $totals['subtotal'],
				'tax'            => $totals['tax'],
				'delivery_fee'   => $totals['delivery_fee'],
				'total'          => $totals['total'],
				'discount'       => $discount,
				'voucher_code'   => $voucher_code,
				'payment_status'    => $payment_status,
				'payment_method'    => $payment_method,
				'payment_intent_id' => $payment_intent_id,
			),
			$lines
		);

		if ( is_wp_error( $order_id ) ) {
			// The voucher was already redeemed above; undo it so the customer
			// keeps it (and a retry can redeem it again) rather than losing it to
			// a failed order insert.
			if ( '' !== $voucher_idem ) {
				DoughBoss_Voucher::revert_redemption( $voucher_idem );
			}
			return $order_id;
		}

		$order = DoughBoss_Order::get( $order_id );
		if ( '' !== $voucher_idem ) {
			DoughBoss_Voucher::link_redemption_to_order( $voucher_idem, $order_id );
		}
		$this->cart->clear();
		$this->send_confirmation( $order );

		$payload = array(
			'success'      => true,
			'order_number' => $order->order_number,
			'total'        => (float) $order->total,
			'message'      => __( 'Thanks! Your order has been received.', 'doughboss' ),
		);

		if ( '' !== $idem ) {
			set_transient( 'doughboss_idem_' . $idem, $payload, 6 * HOUR_IN_SECONDS );
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * Read and normalise the checkout idempotency key (header or param).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string Hashed key, or '' when none supplied.
	 */
	private function idempotency_key( WP_REST_Request $request ) {
		$key = $request->get_header( 'Idempotency-Key' );
		if ( ! $key ) {
			$key = $request->get_param( 'idempotency_key' );
		}
		$key = is_string( $key ) ? trim( $key ) : '';
		return '' !== $key ? md5( $key ) : '';
	}

	/**
	 * Verify a Stripe PaymentIntent before an order is trusted as paid.
	 *
	 * Confirms the intent succeeded, that its amount and currency match this
	 * order's server-computed total, and that it has not already been used for
	 * another order. Returns the PaymentIntent id on success.
	 *
	 * @param WP_REST_Request $request        Request.
	 * @param float           $expected_total Server-computed order total.
	 * @return string|WP_Error PaymentIntent id, or an error.
	 */
	private function verify_payment( WP_REST_Request $request, $expected_total ) {
		$pi_id = sanitize_text_field( $request->get_param( 'payment_intent_id' ) );
		if ( '' === $pi_id ) {
			return new WP_Error( 'doughboss_pay_required', __( 'Payment is required to place this order.', 'doughboss' ), array( 'status' => 402 ) );
		}

		if ( DoughBoss_Order::payment_intent_used( $pi_id ) ) {
			return new WP_Error( 'doughboss_pay_used', __( 'This payment has already been used for an order.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$intent = DoughBoss_Stripe::retrieve_payment_intent( $pi_id );
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		$expected = DoughBoss_Stripe::to_minor_units( $expected_total );
		$currency = strtolower( (string) DoughBoss_Settings::get( 'currency_code', 'AUD' ) );
		$status   = isset( $intent['status'] ) ? $intent['status'] : '';
		$amount   = isset( $intent['amount'] ) ? (int) $intent['amount'] : 0;
		$cur      = isset( $intent['currency'] ) ? strtolower( $intent['currency'] ) : '';

		if ( 'succeeded' !== $status || $amount !== $expected || $cur !== $currency ) {
			return new WP_Error( 'doughboss_pay_unverified', __( 'We could not verify your card payment. If you were charged it will be reversed automatically.', 'doughboss' ), array( 'status' => 402 ) );
		}

		return $pi_id;
	}

	/**
	 * GET /order/{number} — customer order tracking (email must match).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function track_order( WP_REST_Request $request ) {
		$number = sanitize_text_field( $request->get_param( 'number' ) );
		$email  = sanitize_email( $request->get_param( 'email' ) );
		$order  = DoughBoss_Order::get_by_number( $number );

		// Same error for "not found" and "email mismatch" to avoid leaking which orders exist.
		if ( ! $order || strtolower( $order->customer_email ) !== strtolower( $email ) ) {
			return new WP_Error( 'doughboss_not_found', __( 'No matching order found. Check your order number and email.', 'doughboss' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( DoughBoss_Order::public_view( $order ) );
	}

	/**
	 * POST /admin/order/{id}/status — staff status update.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_update_status( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'id' ) );
		$status   = sanitize_key( $request->get_param( 'status' ) );

		if ( ! DoughBoss_Order::update_status( $order_id, $status ) ) {
			return new WP_Error( 'doughboss_status', __( 'Could not update that order.', 'doughboss' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'success' => true, 'status' => $status ) );
	}

	/**
	 * GET /admin/orders — active orders for the live kitchen board.
	 *
	 * @return WP_REST_Response
	 */
	public function admin_orders( WP_REST_Request $request ) {
		$location_id = absint( $request->get_param( 'location_id' ) );
		return rest_ensure_response(
			array(
				'data'        => DoughBoss_Order::active_orders( 100, $location_id ),
				'server_time' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * POST /admin/order/{id}/ack — acknowledge a new order (silence the alert).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function admin_acknowledge( WP_REST_Request $request ) {
		DoughBoss_Order::acknowledge( absint( $request->get_param( 'id' ) ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * POST /admin/order/{id}/accept — accept the order and set an ETA.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_accept( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'id' ) );
		$eta      = absint( $request->get_param( 'eta' ) );

		if ( ! DoughBoss_Order::accept( $order_id, $eta ) ) {
			return new WP_Error( 'doughboss_accept', __( 'Could not accept that order.', 'doughboss' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'success' => true, 'status' => 'confirmed', 'eta' => $eta ) );
	}

	/**
	 * GET /catering/packages — published catering packages for the catering page.
	 *
	 * @return WP_REST_Response
	 */
	public function get_catering_packages() {
		$posts = get_posts(
			array(
				'post_type'   => DoughBoss_Catering_Package::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => 50,
				'orderby'     => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);

		$out = array();
		foreach ( $posts as $post ) {
			$id    = $post->ID;
			$thumb = get_the_post_thumbnail_url( $id, 'large' );
			$out[] = array(
				'id'          => $id,
				'name'        => get_the_title( $post ),
				'description' => wp_strip_all_tags( $post->post_excerpt ? $post->post_excerpt : $post->post_content ),
				'serves_min'  => (int) get_post_meta( $id, DoughBoss_Catering_Package::META_SERVES_MIN, true ),
				'serves_max'  => (int) get_post_meta( $id, DoughBoss_Catering_Package::META_SERVES_MAX, true ),
				'price'       => (float) get_post_meta( $id, DoughBoss_Catering_Package::META_BASE_PRICE, true ),
				'per_head'    => (float) get_post_meta( $id, DoughBoss_Catering_Package::META_PER_HEAD, true ),
				'deposit_pct' => DoughBoss_Catering_Package::deposit_pct( $id ),
				'lead_days'   => DoughBoss_Catering_Package::lead_days( $id ),
				'includes'    => (string) get_post_meta( $id, DoughBoss_Catering_Package::META_INCLUDES, true ),
				'image'       => $thumb ? $thumb : '',
			);
		}

		return rest_ensure_response( $out );
	}

	/**
	 * GET /catering/quote — indicative, server-computed quote for a package.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_catering_quote( WP_REST_Request $request ) {
		$quote = DoughBoss_Catering::quote(
			absint( $request->get_param( 'package_id' ) ),
			absint( $request->get_param( 'guest_count' ) ),
			sanitize_key( $request->get_param( 'order_type' ) )
		);
		return rest_ensure_response( $quote );
	}

	/**
	 * POST /catering/enquiry — capture a catering enquiry and route it to a shop.
	 *
	 * Pricing is recomputed server-side; the delivery fee and final total are
	 * confirmed by staff at the quote stage (status: new → quoted), after which
	 * the deposit is collected.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_catering_enquiry( WP_REST_Request $request ) {
		if ( $this->rate_limited( 'catering', 5, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'doughboss_rate_limit', __( 'Too many requests. Please try again later.', 'doughboss' ), array( 'status' => 429 ) );
		}

		// Honeypot: bots fill the hidden field; accept silently without saving.
		if ( '' !== trim( (string) $request->get_param( 'hp' ) ) ) {
			return rest_ensure_response(
				array(
					'success'        => true,
					'enquiry_number' => '',
					'message'        => __( "Thanks! We'll be in touch shortly.", 'doughboss' ),
				)
			);
		}

		// Route the enquiry to a shop: a valid selected location, else the default.
		$location_id = absint( $request->get_param( 'location_id' ) );
		if ( DoughBoss_Locations::count() > 0 && ! DoughBoss_Locations::is_valid( $location_id ) ) {
			$location_id = DoughBoss_Locations::default_id();
		}

		$id = DoughBoss_Catering::create(
			array(
				'customer_name'  => $request->get_param( 'customer_name' ),
				'customer_email' => $request->get_param( 'customer_email' ),
				'customer_phone' => $request->get_param( 'customer_phone' ),
				'package_id'     => $request->get_param( 'package_id' ),
				'guest_count'    => $request->get_param( 'guest_count' ),
				'order_type'     => $request->get_param( 'order_type' ),
				'event_date'     => $request->get_param( 'event_date' ),
				'event_time'     => $request->get_param( 'event_time' ),
				'address'        => $request->get_param( 'address' ),
				'dietary'        => $request->get_param( 'dietary' ),
				'notes'          => $request->get_param( 'notes' ),
				'location_id'    => $location_id,
			)
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$enquiry = DoughBoss_Catering::get( $id );
		$this->send_catering_notification( $enquiry );

		return rest_ensure_response(
			array(
				'success'        => true,
				'enquiry_number' => $enquiry['enquiry_number'],
				'deposit'        => (float) $enquiry['deposit_amount'],
				'total'          => (float) $enquiry['quote_total'],
				'message'        => __( "Thanks! Your catering enquiry is in — we'll confirm the details and send your deposit link shortly.", 'doughboss' ),
			)
		);
	}

	/**
	 * POST /admin/catering/{id}/status — staff lifecycle update for an enquiry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_update_catering_status( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$status = sanitize_key( $request->get_param( 'status' ) );

		if ( ! DoughBoss_Catering::update_status( $id, $status ) ) {
			return new WP_Error( 'doughboss_catering_status', __( 'Could not update that enquiry.', 'doughboss' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'status'  => $status,
			)
		);
	}

	/**
	 * POST /catering/payment-intent — start a deposit or balance card payment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function catering_payment_intent( WP_REST_Request $request ) {
		if ( ! DoughBoss_Stripe::ready() ) {
			return new WP_Error( 'doughboss_pay_off', __( 'Card payments are not available right now.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$enquiry = $this->resolve_catering_enquiry( $request );
		if ( is_wp_error( $enquiry ) ) {
			return $enquiry;
		}

		$leg    = self::catering_leg( $request->get_param( 'leg' ) );
		$amount = DoughBoss_Catering::leg_amount( $enquiry, $leg );

		if ( DoughBoss_Catering::is_paid( $enquiry, $leg ) ) {
			return new WP_Error( 'doughboss_pay_done', __( 'That payment has already been made.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( DoughBoss_Catering::LEG_BALANCE === $leg && ! DoughBoss_Catering::is_paid( $enquiry, DoughBoss_Catering::LEG_DEPOSIT ) ) {
			return new WP_Error( 'doughboss_pay_seq', __( 'The deposit must be paid before the balance.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( $amount <= 0 ) {
			return new WP_Error( 'doughboss_pay_amount', __( "There's nothing to pay yet — we'll confirm your quote first.", 'doughboss' ), array( 'status' => 400 ) );
		}

		$currency = DoughBoss_Settings::get( 'currency_code', 'AUD' );
		$intent   = DoughBoss_Stripe::create_payment_intent(
			DoughBoss_Stripe::to_minor_units( $amount ),
			$currency,
			array(
				'context'        => 'catering',
				'enquiry_id'     => (int) $enquiry['id'],
				'enquiry_number' => $enquiry['enquiry_number'],
				'leg'            => $leg,
				'site'           => home_url(),
			)
		);
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		DoughBoss_Catering::set_intent( (int) $enquiry['id'], $leg, $intent['id'] );

		return rest_ensure_response(
			array(
				'client_secret'   => $intent['client_secret'],
				'payment_intent'  => $intent['id'],
				'publishable_key' => DoughBoss_Stripe::publishable_key(),
				'amount'          => $intent['amount'],
				'currency'        => $intent['currency'],
				'leg'             => $leg,
			)
		);
	}

	/**
	 * POST /catering/confirm-payment — verify a card payment and record the leg.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function catering_confirm_payment( WP_REST_Request $request ) {
		if ( ! DoughBoss_Stripe::ready() ) {
			return new WP_Error( 'doughboss_pay_off', __( 'Card payments are not available right now.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$enquiry = $this->resolve_catering_enquiry( $request );
		if ( is_wp_error( $enquiry ) ) {
			return $enquiry;
		}

		$leg = self::catering_leg( $request->get_param( 'leg' ) );

		// Already recorded (e.g. the webhook beat us here): treat as success.
		if ( DoughBoss_Catering::is_paid( $enquiry, $leg ) ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'leg'     => $leg,
					'status'  => $enquiry['status'],
				)
			);
		}

		$pi_id  = sanitize_text_field( $request->get_param( 'payment_intent_id' ) );
		$stored = DoughBoss_Catering::LEG_BALANCE === $leg ? $enquiry['balance_intent_id'] : $enquiry['deposit_intent_id'];
		if ( '' === $pi_id || $pi_id !== $stored ) {
			return new WP_Error( 'doughboss_pay_mismatch', __( 'We could not match that payment.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$intent = DoughBoss_Stripe::retrieve_payment_intent( $pi_id );
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		$expected = DoughBoss_Stripe::to_minor_units( DoughBoss_Catering::leg_amount( $enquiry, $leg ) );
		$currency = strtolower( (string) DoughBoss_Settings::get( 'currency_code', 'AUD' ) );
		$status   = isset( $intent['status'] ) ? $intent['status'] : '';
		$amount   = isset( $intent['amount'] ) ? (int) $intent['amount'] : 0;
		$cur      = isset( $intent['currency'] ) ? strtolower( $intent['currency'] ) : '';

		if ( 'succeeded' !== $status || $amount !== $expected || $cur !== $currency ) {
			return new WP_Error( 'doughboss_pay_unverified', __( 'We could not verify your payment. If you were charged it will be reversed automatically.', 'doughboss' ), array( 'status' => 402 ) );
		}

		DoughBoss_Catering::mark_paid( (int) $enquiry['id'], $leg );
		$fresh = DoughBoss_Catering::get( (int) $enquiry['id'] );

		return rest_ensure_response(
			array(
				'success' => true,
				'leg'     => $leg,
				'status'  => $fresh ? $fresh['status'] : '',
				'message' => DoughBoss_Catering::LEG_BALANCE === $leg
					? __( 'Paid in full — thank you! Your booking is confirmed.', 'doughboss' )
					: __( "Deposit received — your date is secured. We'll be in touch with the details.", 'doughboss' ),
			)
		);
	}

	/**
	 * POST /catering/stripe-webhook — authoritative payment confirmation.
	 *
	 * Verifies the Stripe signature, then on payment_intent.succeeded marks the
	 * matching catering enquiry's deposit/balance leg paid (idempotently). This
	 * covers the asynchronous balance leg and any client that never returns.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function catering_stripe_webhook( WP_REST_Request $request ) {
		$payload = $request->get_body();
		$sig     = $request->get_header( 'stripe_signature' );

		if ( ! DoughBoss_Stripe::verify_webhook_signature( $payload, $sig ) ) {
			return new WP_Error( 'doughboss_wh_sig', __( 'Invalid signature.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return rest_ensure_response( array( 'received' => true ) );
		}

		if ( 'payment_intent.succeeded' === $event['type'] ) {
			$obj  = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();
			$meta = isset( $obj['metadata'] ) && is_array( $obj['metadata'] ) ? $obj['metadata'] : array();

			if ( isset( $meta['context'] ) && 'catering' === $meta['context'] ) {
				$leg     = self::catering_leg( isset( $meta['leg'] ) ? $meta['leg'] : 'deposit' );
				$enquiry = ! empty( $obj['id'] ) ? DoughBoss_Catering::find_by_intent( $obj['id'] ) : null;
				if ( ! $enquiry && ! empty( $meta['enquiry_id'] ) ) {
					$enquiry = DoughBoss_Catering::get( (int) $meta['enquiry_id'] );
				}
				if ( $enquiry ) {
					DoughBoss_Catering::mark_paid( (int) $enquiry['id'], $leg );
				}
			}
		}

		return rest_ensure_response( array( 'received' => true ) );
	}

	/**
	 * Resolve a catering enquiry from a request, requiring the number + email to
	 * match. Returns the same not-found error for a mismatch to avoid leaking
	 * which enquiries exist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	private function resolve_catering_enquiry( WP_REST_Request $request ) {
		$number  = sanitize_text_field( $request->get_param( 'enquiry_number' ) );
		$email   = sanitize_email( $request->get_param( 'email' ) );
		$enquiry = DoughBoss_Catering::get_by_number( $number );

		if ( ! $enquiry || strtolower( $enquiry['customer_email'] ) !== strtolower( $email ) ) {
			return new WP_Error( 'doughboss_not_found', __( 'No matching enquiry found. Check your reference and email.', 'doughboss' ), array( 'status' => 404 ) );
		}
		return $enquiry;
	}

	/**
	 * Normalise a payment-leg string to a known value.
	 *
	 * @param string $leg Raw leg.
	 * @return string 'deposit' or 'balance'.
	 */
	private static function catering_leg( $leg ) {
		return DoughBoss_Catering::LEG_BALANCE === $leg ? DoughBoss_Catering::LEG_BALANCE : DoughBoss_Catering::LEG_DEPOSIT;
	}

	/**
	 * Email the customer their catering enquiry summary, and notify the shop.
	 *
	 * @param array<string,mixed> $enquiry Stored enquiry row.
	 * @return void
	 */
	private function send_catering_notification( $enquiry ) {
		if ( ! is_array( $enquiry ) ) {
			return;
		}

		$blog = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		/* translators: 1: site name, 2: enquiry number. */
		$subject = sprintf( __( '[%1$s] Catering enquiry %2$s received', 'doughboss' ), $blog, $enquiry['enquiry_number'] );

		$package = (int) $enquiry['package_id'] ? get_the_title( (int) $enquiry['package_id'] ) : __( 'Custom', 'doughboss' );

		$body = sprintf(
			/* translators: 1: name, 2: enquiry number, 3: package, 4: guests, 5: event date, 6: deposit. */
			__( "Hi %1\$s,\n\nThanks for your catering enquiry %2\$s.\n\nPackage: %3\$s\nGuests: %4\$d\nEvent date: %5\$s\nIndicative deposit: %6\$s\n\nWe'll confirm the details and send your deposit link shortly.\n", 'doughboss' ),
			$enquiry['customer_name'],
			$enquiry['enquiry_number'],
			$package,
			(int) $enquiry['guest_count'],
			'' !== $enquiry['event_date'] ? $enquiry['event_date'] : __( 'to be confirmed', 'doughboss' ),
			DoughBoss_Settings::format_price( $enquiry['deposit_amount'] )
		);

		if ( is_email( $enquiry['customer_email'] ) ) {
			wp_mail( $enquiry['customer_email'], $subject, $body );
		}

		$admin_email = get_option( 'admin_email' );
		if ( is_email( $admin_email ) ) {
			wp_mail( $admin_email, $subject, $body );
		}
	}

	/**
	 * Send a plain confirmation email to the customer and a copy to the admin.
	 *
	 * @param object $order Order row.
	 * @return void
	 */
	private function send_confirmation( $order ) {
		$blog  = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		/* translators: 1: site name, 2: order number. */
		$subject = sprintf( __( '[%1$s] Order %2$s received', 'doughboss' ), $blog, $order->order_number );

		$lines = array();
		foreach ( DoughBoss_Order::get_items( $order->id ) as $item ) {
			$lines[] = sprintf( '%d x %s — %s', $item['quantity'], $item['name'], DoughBoss_Settings::format_price( $item['line_total'] ) );
		}
		if ( isset( $order->discount ) && (float) $order->discount > 0 ) {
			$lines[] = sprintf(
				/* translators: 1: voucher code (may be blank), 2: discount amount. */
				__( 'Voucher %1$s: -%2$s', 'doughboss' ),
				'' !== (string) $order->voucher_code ? $order->voucher_code : '',
				DoughBoss_Settings::format_price( (float) $order->discount )
			);
		}

		$body = sprintf(
			/* translators: 1: customer name, 2: order number, 3: items list, 4: total. */
			__( "Hi %1\$s,\n\nThanks for your order %2\$s. Here's what we got:\n\n%3\$s\n\nTotal: %4\$s\n\nWe'll let you know as it progresses.\n", 'doughboss' ),
			$order->customer_name,
			$order->order_number,
			implode( "\n", $lines ),
			DoughBoss_Settings::format_price( $order->total )
		);

		if ( is_email( $order->customer_email ) ) {
			wp_mail( $order->customer_email, $subject, $body );
		}

		$admin_email = get_option( 'admin_email' );
		if ( is_email( $admin_email ) ) {
			wp_mail( $admin_email, $subject, $body );
		}
	}
}
