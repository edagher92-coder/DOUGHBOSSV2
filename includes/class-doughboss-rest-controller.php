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
		// Square-hosted checkout safety net: retire abandoned links and finish
		// any paid checkout whose browser return and webhook were both missed.
		add_action( DoughBoss_Square_Hosted::SWEEP_HOOK, array( $this, 'square_hosted_sweep' ) );
		// Allow the standalone staff console (separate origin) to call our routes.
		add_action( 'rest_api_init', array( $this, 'enable_cors' ), 15 );
		add_filter( 'rest_post_dispatch', array( $this, 'protect_tracking_response' ), 10, 3 );
		// Fail-closed backstop for the read-only reports account: on this plugin's
		// namespace it may call only the five whitelisted GET routes.
		add_filter( 'rest_pre_dispatch', array( $this, 'guard_reports_reader' ), 10, 3 );
	}

	/**
	 * Supplement WordPress's default CORS handling with the staff console's
	 * origin on doughboss/v1 routes. Deliberately does NOT remove WordPress's
	 * own 'rest_send_cors_headers' callback — this plugin previously did, which
	 * disabled default CORS handling for every REST route on the site (core
	 * `/wp/v2/*`, any other plugin's namespace), not just its own. Both
	 * callbacks now run: WordPress's own default first, this one layered on top
	 * only for the configured console origin on this plugin's namespace.
	 *
	 * @return void
	 */
	public function enable_cors() {
		add_filter( 'rest_pre_serve_request', array( $this, 'send_cors_headers' ), 10, 4 );
	}

	/**
	 * Send CORS headers for the staff console origin on doughboss/v1 routes
	 * (Application Password auth). Scoped — never site-wide wildcard, and never
	 * removes WordPress's own default CORS handling for other routes.
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
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-DoughBoss-Board-Key' );
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
		register_rest_route( $ns, '/admin/square-catalogue-prepare', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'prepare_square_catalogue' ),
			'permission_callback' => array( $this, 'verify_square_catalogue_reader' ),
		) );
		register_rest_route( $ns, '/admin/square-catalogue-source', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_square_catalogue_source' ),
			'permission_callback' => array( $this, 'verify_square_catalogue_reader' ),
		) );
		register_rest_route( $ns, '/admin/square-catalogue-operation-status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_square_catalogue_operation_status' ),
			'permission_callback' => array( $this, 'verify_square_catalogue_reader' ),
		) );
		register_rest_route( $ns, '/admin/square-catalogue-review-context', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_square_catalogue_review_context' ),
			'permission_callback' => array( $this, 'verify_square_catalogue_reader' ),
		) );

		register_rest_route(
			$ns,
			'/admin/square-catalogue',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_square_catalogue' ),
				'permission_callback' => array( $this, 'verify_square_catalogue_reader' ),
				'args' => array( 'location' => array( 'type' => 'string', 'enum' => array( 'revesby', 'bankstown', 'roselands' ), 'required' => true ) ),
			)
		);
		register_rest_route( $ns, '/admin/square-locations', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'get_square_locations' ),
			'permission_callback' => array( $this, 'verify_square_catalogue_reader' ),
		) );

		register_rest_route(
			$ns,
			'/admin/square-dashboard',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_square_dashboard' ),
				'permission_callback' => array( $this, 'verify_manage' ),
				'args' => array(
					'mode' => array( 'type' => 'string', 'enum' => array( 'status', 'demo' ), 'default' => 'status' ),
					'location' => array( 'type' => 'string', 'enum' => array( 'all', 'revesby', 'bankstown', 'roselands' ), 'default' => 'all' ),
				),
			)
		);

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
				'args'               => array( 'location_id' => array( 'validate_callback' => array( $this, 'validate_staff_approval_location_param' ) ) ),
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
			'/table/context',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_table_context' ),
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
					'options'  => array(
						'default' => array(),
					),
					'location_id' => array( 'validate_callback' => array( $this, 'validate_staff_approval_location_param' ) ),
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
				// Legacy endpoint retained for integrations, but no longer public:
				// storefront redemption happens only inside paid/order checkout.
				'permission_callback' => array( $this, 'verify_manage' ),
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
					'customer_email_confirmation' => array(
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
				'args'                => array(
					'type'           => array(
						'sanitize_callback' => 'sanitize_key',
					),
					'value'          => array(
						// Wrap the cast: WordPress passes 3 args to sanitize
						// callbacks and the built-in floatval() accepts exactly 1
						// (fatal ArgumentCountError on PHP 8).
						'sanitize_callback' => static function ( $value ) {
							return (float) $value;
						},
					),
					'prefix'         => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'min_spend'      => array(
						'sanitize_callback' => static function ( $value ) {
							return (float) $value;
						},
					),
					'scope'          => array(
						'sanitize_callback' => 'sanitize_key',
					),
					'location_id'    => array(
						'sanitize_callback' => 'absint',
					),
					'customer_phone' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'customer_email' => array(
						'sanitize_callback' => 'sanitize_email',
					),
					'valid_from'     => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'valid_to'       => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
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
					'transaction_ref' => array(
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
			'/pay/tyro-test',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'tyro_test' ),
				'permission_callback' => array( $this, 'verify_manage' ),
			)
		);

		register_rest_route(
			$ns,
			'/pay/mpgs-test',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'mpgs_test' ),
				'permission_callback' => array( $this, 'verify_manage' ),
			)
		);

		register_rest_route(
			$ns,
			'/pospal/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'pospal_products' ),
				'permission_callback' => array( $this, 'verify_manage' ),
			)
		);

		register_rest_route(
			$ns,
			'/pospal/product-map',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pospal_save_product_map' ),
				'permission_callback' => array( $this, 'verify_manage' ),
				'args'                => array(
					'map' => array(
						'required' => true,
					),
				),
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

		// Ops/monitoring snapshot: versions + per-integration readiness booleans.
		// Staff-gated; never returns keys, secrets or URLs.
		register_rest_route(
			$ns,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'verify_admin_or_reports_read' ),
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
					'reason' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/voucher/reverse',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_reverse_voucher' ),
				'permission_callback' => array( $this, 'verify_manage' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'reason' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// 2.51: after a shop change, drop menu lines the new shop does not sell.
		register_rest_route(
			$ns,
			'/cart/revalidate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cart_revalidate' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'location_id' => array(
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
					'location_id' => array(
						'validate_callback' => array( $this, 'validate_staff_approval_location_param' ),
					),
					'payment_attempt_key' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'customer_name' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'customer_email' => array(
						'sanitize_callback' => 'sanitize_email',
					),
					'customer_phone' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'address' => array(
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'notes' => array(
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'return_url' => array(
						'sanitize_callback' => 'esc_url_raw',
					),
					// Square only: the single-use card token the Web Payments SDK
					// produced in the browser, plus optional 3-D Secure evidence.
					// Neither is a secret and neither is ever stored — they are
					// passed straight through to Square and discarded.
					'source_id' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'verification_token' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					// 2.51 pickup scheduling: validated server-side by
					// DoughBoss_Pickup; never trusted as-is.
					'pickup_mode' => array(
						'sanitize_callback' => 'sanitize_key',
					),
					'pickup_slot_id' => array(
						'sanitize_callback' => 'absint',
					),
					'capacity_hold_token' => array(
						'sanitize_callback' => 'sanitize_text_field',
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
				'args'                => array(
					'order_type'        => array(
						'sanitize_callback' => 'sanitize_key',
					),
					'customer_name'     => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'customer_email'    => array(
						'sanitize_callback' => 'sanitize_email',
					),
					'customer_phone'    => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'notes'             => array(
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'address'           => array(
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'location_id'       => array(
						'validate_callback' => array( $this, 'validate_staff_approval_location_param' ),
					),
					'payment_intent_id' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'payment_attempt_key' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'idempotency_key'   => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'pickup_mode'       => array(
						'sanitize_callback' => 'sanitize_key',
					),
					'pickup_slot_id'    => array(
						'sanitize_callback' => 'absint',
					),
					'capacity_hold_token' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Unpaid, staff-reviewed fallback while normal checkout is closed. This is
		// intentionally a separate endpoint: it never creates a PaymentIntent,
		// never reserves capacity and never enters the KDS before acceptance.
		register_rest_route(
			$ns,
			'/preorder-request',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preorder_request' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'customer_name'  => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'customer_email' => array( 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
					'customer_phone' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'notes'          => array( 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
					// Validate, rather than sanitize, this public native identifier. The
					// approval-required resolver must see the original representation so
					// booleans, floats, aliases, whitespace and leading zeroes cannot be
					// coerced into a different shop authority.
					'location_id'    => array( 'validate_callback' => array( $this, 'validate_staff_approval_location_param' ) ),
					'idempotency_key'=> array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		// Public capability-token endpoints for an already approved request. A
		// link GET never mutates state; the browser posts the opaque token after
		// load, and only the explicit start call may create/replay one Stripe
		// Checkout Session for the stored approved revision.
		foreach ( array( 'view', 'start', 'complete' ) as $preorder_payment_action ) {
			register_rest_route(
				$ns,
				'/preorder-payment/' . $preorder_payment_action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'preorder_payment_' . $preorder_payment_action ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'request_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
						'token'      => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
						'session_id' => array( 'sanitize_callback' => 'sanitize_text_field' ),
					),
				)
			);
		}

		register_rest_route(
			$ns,
			'/order/track',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'track_order' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'number' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
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
				'permission_callback' => array( $this, 'verify_board_access' ),
				'args'                => array(
					'status' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'expected_version' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'event_key' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'reason_code' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Live kitchen order board: incremental feed of active orders. Adding a
		// 'status' or 'history' param switches it to a paginated history view
		// (completed/cancelled reachable); with neither it is the live board.
		register_rest_route(
			$ns,
			'/admin/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'admin_orders' ),
				'permission_callback' => array( $this, 'verify_board_read_access' ),
				'args'                => array(
					'status'   => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'history'  => array(
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'search'   => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Compact, PII-free workload for the MAKE / PASS / CATERING switcher.
		// It is intentionally separate from the 100-card feeds so its counts stay
		// exact during a busy service.
		register_rest_route(
			$ns,
			'/admin/board-summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'admin_board_summary' ),
				'permission_callback' => array( $this, 'verify_board_read_access' ),
				'args'                => array(
					'location_id' => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Acknowledge a new order (silences the board alert).
		register_rest_route(
			$ns,
			'/admin/order/(?P<id>\d+)/ack',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_acknowledge' ),
				'permission_callback' => array( $this, 'verify_board_access' ),
			)
		);

		// Accept an order and set an ETA.
		register_rest_route(
			$ns,
			'/admin/order/(?P<id>\d+)/accept',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_accept' ),
				'permission_callback' => array( $this, 'verify_board_access' ),
				'args'                => array(
					'eta' => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'expected_version' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'event_key' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Staff "+N min": push a timed order's promise back (CAS on version).
		register_rest_route(
			$ns,
			'/admin/order/(?P<id>\d+)/delay',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_delay' ),
				'permission_callback' => array( $this, 'verify_board_access' ),
				'args'                => array(
					'minutes' => array(
						'default'           => 10,
						'sanitize_callback' => 'absint',
					),
					'expected_version' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'event_key' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// The review queue is intentionally separate from the KDS. Approval keeps
		// the request unpaid and outside fulfilment while it creates one private
		// Stripe capability; rejection cancels it without kitchen side effects.
		register_rest_route(
			$ns,
			'/admin/preorder-requests',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'admin_preorder_requests' ),
				'permission_callback' => array( $this, 'verify_board_read_access' ),
				'args'                => array(
					'location_id' => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
					'per_page'    => array( 'default' => 100, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/admin/preorder/(?P<id>\\d+)/pickup-options',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'admin_preorder_pickup_options' ),
				'permission_callback' => array( $this, 'verify_preorder_approver' ),
				'args' => array(
					'expected_version' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'contact_confirmed' => array( 'required' => true, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				),
			)
		);


		register_rest_route(
			$ns,
			'/admin/preorder/(?P<id>\d+)/decision',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_preorder_decision' ),
				'permission_callback' => array( $this, 'verify_preorder_approver' ),
				'args'                => array(
					'decision'         => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
					'eta'              => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
					'contact_confirmed'=> array( 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
					'pickup_mode' => array( 'sanitize_callback' => 'sanitize_key' ),
					'ready_from_utc' => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'pickup_config_key' => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'expected_version' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'event_key'        => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/admin/preorder/(?P<id>\d+)/payment-link',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_preorder_payment_link' ),
				'permission_callback' => array( $this, 'verify_preorder_approver' ),
				'args'                => array( 'mode' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ) ),
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
					// Combined selection as "id:qty,id:qty"; prices are always
					// read from the package posts, never from the browser.
					'packages'    => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
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
					// Combined selection: a list of {id, qty}. Validated and priced
					// server-side by DoughBoss_Catering::normalise_packages() and
					// quote_packages(); package_id alone stays supported.
					'packages'       => array(
						'default'           => array(),
						'validate_callback' => array( $this, 'validate_catering_packages_arg' ),
					),
				),
			)
		);

		// Wholesale / franchising enquiry form ([doughboss_b2b_form]). Public,
		// nonce-gated and rate limited; emails the B2B inbox, stores nothing.
		register_rest_route(
			$ns,
			'/b2b/enquiry',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_b2b_enquiry' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'type'     => array( 'default' => 'wholesale', 'sanitize_callback' => 'sanitize_key' ),
					'name'     => array( 'default' => '' ),
					'business' => array( 'default' => '' ),
					'email'    => array( 'default' => '' ),
					'phone'    => array( 'default' => '' ),
					'focus'    => array( 'default' => '' ),
					'message'  => array( 'default' => '' ),
					'website'  => array( 'default' => '' ),
				),
			)
		);

		// Catering — staff status update for an enquiry. Owner-only: a status
		// change can mark an enquiry PAID/CONFIRMED/LOST (real financial/business
		// state), so this uses verify_manage (not verify_admin) — the same "a
		// till device can never create value" boundary already enforced for
		// vouchers. The kitchen/KDS role only gets order-board actions.
		// Catering — list enquiries for the staff admin screen (filter/search/paginate).
		register_rest_route(
			$ns,
			'/admin/catering',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'admin_catering' ),
				'permission_callback' => array( $this, 'verify_manage' ),
				'args'                => array(
					'status'   => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'search'   => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Read-only, shop-scoped catering production feed. It intentionally
		// shares the KDS access boundary but not the manager-only lifecycle
		// mutation endpoint below.
		register_rest_route(
			$ns,
			'/admin/catering-board',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'admin_catering_board' ),
				'permission_callback' => array( $this, 'verify_board_read_access' ),
				'args'                => array(
					'location_id' => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/admin/catering/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_update_catering_status' ),
				'permission_callback' => array( $this, 'verify_manage' ),
				'args'                => array(
					'status' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/admin/catering/(?P<id>\d+)/quote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_update_catering_quote' ),
				'permission_callback' => array( $this, 'verify_manage' ),
				'args'                => array(
					'subtotal'     => array( 'required' => true ),
					'delivery_fee' => array( 'required' => true ),
					'deposit_pct'  => array( 'required' => true ),
				),
			)
		);

		// Member tiers — add/remove a verified customer to a Square customer group.
		register_rest_route(
			$ns,
			'/admin/square-tier',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_square_tier' ),
				'permission_callback' => array( $this, 'verify_manage' ),
			)
		);

		// Catering — send (or re-send) a Square invoice for one leg. Manager-only;
		// Square emails the customer a hosted pay page, so no card data touches WP.
		register_rest_route(
			$ns,
			'/admin/catering/(?P<id>\d+)/square-invoice',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_catering_square_invoice' ),
				'permission_callback' => array( $this, 'verify_manage' ),
				'args'                => array(
					'leg' => array(
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
					'payment_attempt_key' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
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

		// Storefront — Stripe webhook. A signed paid event can fulfil the
		// immutable server snapshot when the customer never returns from hosted
		// Checkout; otherwise it safely replays the browser-created order.
		register_rest_route(
			$ns,
			'/stripe-webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'stripe_webhook' ),
				'permission_callback' => '__return_true',
			)
		);

		// Catering — Tyro webhook, mirrors /catering/stripe-webhook above.
		// Public route, gated by DoughBoss_Tyro::verify_webhook_signature(), not
		// a nonce.
		register_rest_route(
			$ns,
			'/catering/tyro-webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'catering_tyro_webhook' ),
				'permission_callback' => '__return_true',
			)
		);

		// Storefront — Tyro webhook, mirrors /stripe-webhook above. Public
		// route, gated by DoughBoss_Tyro::verify_webhook_signature(), not a nonce.
		register_rest_route(
			$ns,
			'/tyro-webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'tyro_webhook' ),
				'permission_callback' => '__return_true',
			)
		);

		// Canonical Tyro Connect endpoint. The older paths above remain aliases
		// so an already-configured sandbox does not silently lose deliveries.
		register_rest_route(
			$ns,
			'/payments/tyro/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'tyro_webhook' ),
				'permission_callback' => '__return_true',
			)
		);

		// MPGS Hosted Checkout order notifications are authenticated with a
		// per-attempt HMAC token and always re-retrieve the gateway order. Raw
		// notification data is neither trusted nor stored.
		register_rest_route(
			$ns,
			'/mpgs-notification',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mpgs_notification' ),
				'permission_callback' => '__return_true',
			)
		);

		// Storefront — Square webhook, mirroring /tyro-webhook. Public route,
		// gated by DoughBoss_Square::verify_webhook_signature() (HMAC-SHA256 over
		// the notification URL + the raw body), not a nonce. Like the Tyro
		// webhook it never creates orders and never refunds: it only surfaces a
		// payment that has no order for a human decision.
		register_rest_route(
			$ns,
			'/square-webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'square_webhook' ),
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
	 * Permission check for live Order Board data and actions.
	 *
	 * WordPress authentication and the KDS capability remain the primary gate.
	 * When the optional board key is configured, the client must also present it
	 * on every order read/write request; gating only the HTML shell would leave
	 * the underlying customer data and status actions reachable through REST.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function verify_board_access( WP_REST_Request $request ) {
		$allowed = $this->verify_admin();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		// Owners/managers already hold the broader management capability and use
		// these endpoints from ordinary wp-admin screens that do not carry the
		// kitchen bookmark. The optional second gate is for KDS-only staff accounts.
		if ( current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		$location_scope = DoughBoss_Staff_Scope::current_location_id();
		if ( is_wp_error( $location_scope ) ) {
			return $location_scope;
		}
		if ( '' === DoughBoss_Settings::board_access_key() ) {
			return true;
		}

		$supplied = sanitize_text_field( (string) $request->get_header( 'X-DoughBoss-Board-Key' ) );
		if ( DoughBoss_Settings::verify_board_access_key( $supplied ) ) {
			return true;
		}
		return new WP_Error( 'doughboss_board_key_required', __( 'This Order Board request needs the current staff access key.', 'doughboss' ), array( 'status' => 403 ) );
	}

	/**
	 * The only routes the read-only reports account may call. All are GET.
	 *
	 * @return string[]
	 */
	private static function reports_read_routes() {
		$base = '/' . DOUGHBOSS_REST_NAMESPACE;
		return array(
			$base . '/status',
			$base . '/admin/board-summary',
			$base . '/admin/preorder-requests',
			$base . '/admin/orders',
			$base . '/admin/catering-board',
		);
	}

	/**
	 * Whether a request is a whitelisted read for the reports account.
	 *
	 * Fails closed: anything other than a plain GET to one of the five routes
	 * returns false, including HEAD, OPTIONS and every write method.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function is_reports_read_request( WP_REST_Request $request ) {
		if ( 'GET' !== strtoupper( (string) $request->get_method() ) ) {
			return false;
		}
		return in_array( untrailingslashit( (string) $request->get_route() ), self::reports_read_routes(), true );
	}

	/**
	 * Permission check for GET /status: the existing staff check, plus the
	 * read-only reports account on a GET request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function verify_admin_or_reports_read( WP_REST_Request $request ) {
		$allowed = $this->verify_admin();
		if ( ! is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( DoughBoss_Staff_Scope::is_reports_reader() && $this->is_reports_read_request( $request ) ) {
			return true;
		}
		return $allowed;
	}

	/**
	 * Permission check for the four read-only board feeds.
	 *
	 * Every other account goes through verify_board_access() unchanged,
	 * including the shop assignment and the optional board key for kitchen-only
	 * users. The read-only reports account is an API (Application Password)
	 * account that never uses the board bookmark, so the board key is not
	 * required for it; it is allowed on a whitelisted GET only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function verify_board_read_access( WP_REST_Request $request ) {
		if ( DoughBoss_Staff_Scope::is_reports_reader() ) {
			if ( $this->is_reports_read_request( $request ) ) {
				return true;
			}
			return new WP_Error( 'doughboss_forbidden', __( 'You are not allowed to do that.', 'doughboss' ), array( 'status' => 403 ) );
		}
		return $this->verify_board_access( $request );
	}

	/**
	 * rest_pre_dispatch backstop: on this plugin's namespace the read-only
	 * reports account may call only the whitelisted GET routes. This refuses any
	 * other route or method before routing, including public POST routes and any
	 * route added in future with a permissive permission callback.
	 *
	 * @param mixed           $result  Short-circuit value; null to continue.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed|WP_Error
	 */
	public function guard_reports_reader( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}
		if ( 0 !== strpos( (string) $request->get_route(), '/' . DOUGHBOSS_REST_NAMESPACE . '/' ) ) {
			return $result;
		}
		if ( ! DoughBoss_Staff_Scope::is_reports_reader() || $this->is_reports_read_request( $request ) ) {
			return $result;
		}
		return new WP_Error( 'doughboss_reports_read_only', __( 'This account is read-only and cannot use this endpoint.', 'doughboss' ), array( 'status' => 403 ) );
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

	/** Explicit owner-only provider read, requiring both management capability and REST nonce. */
	public function verify_square_catalogue_reader( WP_REST_Request $request ) {
		$permission = $this->verify_manage();
		return is_wp_error( $permission ) ? $permission : $this->verify_nonce( $request );
	}

	/** Explicit owner browser action; never accepts caller-selected account or location context. */
	public function get_square_locations( WP_REST_Request $request ) {
		$permission = $this->verify_square_catalogue_reader( $request );
		if ( is_wp_error( $permission ) ) { return $permission; }
		$url = $request->get_url_params();
		$json = $request->get_json_params();
		if ( 'GET' !== $request->get_method() || array() !== $request->get_query_params() || ( null !== $request->get_body() && '' !== $request->get_body() ) || array() !== $request->get_body_params() || array() !== $request->get_file_params() || ( null !== $json && array() !== $json ) || ( array() !== $url && array( 'namespace' => DOUGHBOSS_REST_NAMESPACE ) !== $url ) ) {
			return new WP_Error( 'doughboss_square_locations_input', __( 'Square location observation does not accept caller context.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$actor = get_current_user_id(); $session_token = wp_get_session_token();
		$authorize = function () use ( $request, $actor, $session_token ) {
			if ( $actor !== get_current_user_id() || ! is_string( $session_token ) || '' === $session_token || ! hash_equals( $session_token, wp_get_session_token() ) ) { return new WP_Error( 'doughboss_forbidden', __( 'Current owner browser authority could not be verified.', 'doughboss' ), array( 'status' => 403 ) ); }
			$permission = $this->verify_square_catalogue_reader( $request );
			if ( is_wp_error( $permission ) ) { return $permission; }
			$permission = $this->square_catalogue_current_authority( $actor, $session_token );
			if ( is_wp_error( $permission ) ) { return $permission; }
			if ( $actor !== get_current_user_id() || ! hash_equals( $session_token, wp_get_session_token() ) ) { return new WP_Error( 'doughboss_forbidden', __( 'Current owner browser authority could not be verified.', 'doughboss' ), array( 'status' => 403 ) ); }
			return $this->verify_square_catalogue_reader( $request );
		};
		$view = DoughBoss_Square::observe_locations( $authorize );
		if ( is_wp_error( $view ) ) { return $view; }
		// The service's terminal guard performs this exact owner/session callback,
		// then rechecks its full captured context and expiry before returning.
		// No extra owner callback may invalidate that evidence after the guard.
		return new WP_REST_Response( $view, 200, array( 'Cache-Control' => 'private, no-store', 'Vary' => 'Cookie, Authorization' ) );
	}

	public function get_square_catalogue( WP_REST_Request $request ) {
		$permission = $this->verify_square_catalogue_reader( $request );
		if ( is_wp_error( $permission ) ) { return $permission; }
		$query = $request->get_query_params(); $body = $request->get_body();
		if ( array( 'location' ) !== array_keys( $query ) || ( null !== $body && '' !== $body )
			|| ! is_string( $query['location'] ) || ! in_array( $query['location'], array( 'revesby', 'bankstown', 'roselands' ), true ) ) {
			return new WP_Error( 'doughboss_square_catalogue_input', __( 'Choose only one supported shop for this read.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$actor = get_current_user_id();
		$session_token = wp_get_session_token();
		$authorize = function () use ( $request, $actor, $session_token ) {
			if ( $actor !== get_current_user_id() || ! hash_equals( $session_token, wp_get_session_token() ) ) { return new WP_Error( 'doughboss_forbidden', __( 'You are not allowed to do that.', 'doughboss' ), array( 'status' => 403 ) ); }
			$permission = $this->verify_square_catalogue_reader( $request );
			return is_wp_error( $permission ) ? $permission : $this->square_catalogue_current_authority( $actor, $session_token );
		};
		$permission = $authorize(); if ( is_wp_error( $permission ) ) { return $permission; }
		$menu = $this->read_square_catalogue_menu(); if ( is_wp_error( $menu ) ) { return $menu; }
		$source = DoughBoss_Square_Catalogue_Source::snapshot( $menu ); if ( is_wp_error( $source ) ) { return $source; }
		$permission = $authorize(); if ( is_wp_error( $permission ) ) { return $permission; }
		$view = DoughBoss_Square_Dashboard::observe_catalogue( $query['location'] );
		if ( is_wp_error( $view ) ) { return $view; }
		$permission = $authorize(); if ( is_wp_error( $permission ) ) { return $permission; }
		$menu = $this->read_square_catalogue_menu(); if ( is_wp_error( $menu ) ) { return $menu; }
		$after = DoughBoss_Square_Catalogue_Source::snapshot( $menu );
		if ( is_wp_error( $after ) || $source !== $after ) {
			return new WP_Error( 'doughboss_square_catalogue_source_changed', __( 'The complete current source changed during this read. No comparison was returned.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$comparison = DoughBoss_Square_Catalogue_Comparison::compare( $source, $view );
		if ( is_wp_error( $comparison ) ) { return $comparison; }
		$permission = $authorize(); if ( is_wp_error( $permission ) ) { return $permission; }
		$view['source_comparison'] = $comparison;
		return new WP_REST_Response( $view, 200, array( 'Cache-Control' => 'private, no-store', 'Vary' => 'Cookie, Authorization' ) );
	}

	/** Owner-approved preparation only; never dispatches a provider mutation. */
	public function prepare_square_catalogue( WP_REST_Request $request ) {
		$permission = $this->verify_square_catalogue_reader( $request );
		if ( is_wp_error( $permission ) ) { return $permission; }
		$approval = $request->get_json_params();
		if ( ! $request->is_json_content_type() || ! is_array( $approval ) || array_values( $approval ) === $approval ) {
			return new WP_Error( 'doughboss_square_preparation_invalid', __( 'A complete catalogue approval is required.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$actor = get_current_user_id();
		$session_token = wp_get_session_token();
		$authorize = function () use ( $request, $actor, $session_token ) {
			if ( $actor !== get_current_user_id() ) { return new WP_Error( 'doughboss_forbidden', __( 'You are not allowed to do that.', 'doughboss' ), array( 'status' => 403 ) ); }
			$permission = $this->verify_square_catalogue_reader( $request );
			if ( is_wp_error( $permission ) ) { return $permission; }
			return $this->square_catalogue_current_authority( $actor, $session_token );
		};
		$read_menu = function () {
			return $this->read_square_catalogue_menu();
		};
		$result = DoughBoss_Square_Catalogue_Preparation::prepare( $approval, $read_menu, $authorize );
		if ( is_wp_error( $result ) ) { return $result; }
		$permission = $authorize();
		if ( is_wp_error( $permission ) ) { return $permission; }
		return new WP_REST_Response( $result, 200, array( 'Cache-Control' => 'private, no-store', 'Vary' => 'Cookie, Authorization' ) );
	}

	/** Owner review observation only; no durable approval or provider dispatch. */
	public function get_square_catalogue_review_context( WP_REST_Request $request ) {
		$permission = $this->verify_square_catalogue_reader( $request );
		if ( is_wp_error( $permission ) ) { return $permission; }
		$query = $request->get_query_params();
		// Programmatically constructed WP REST requests leave an absent body null.
		// Admit only absent or exact empty bytes, never a nonempty/coerced body.
		$body = $request->get_body();
		if ( array( 'shops' ) !== array_keys( $query ) || ( null !== $body && '' !== $body ) ) {
			return new WP_Error( 'doughboss_square_catalogue_review_input', __( 'Choose only the supported shops for owner review.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$slugs = DoughBoss_Square_Catalogue_Review::shops( $query['shops'] );
		if ( is_wp_error( $slugs ) ) { return $slugs; }
		$actor = get_current_user_id(); $session_token = wp_get_session_token();
		$authorize = function () use ( $request, $actor, $session_token ) {
			if ( $actor !== get_current_user_id() ) { return new WP_Error( 'doughboss_forbidden', __( 'You are not allowed to do that.', 'doughboss' ), array( 'status' => 403 ) ); }
			$permission = $this->verify_square_catalogue_reader( $request );
			if ( is_wp_error( $permission ) ) { return $permission; }
			$permission = $this->square_catalogue_current_authority( $actor, $session_token );
			if ( is_wp_error( $permission ) ) { return $permission; }
			if ( $actor !== get_current_user_id() || ! hash_equals( $session_token, wp_get_session_token() ) ) { return new WP_Error( 'doughboss_forbidden', __( 'You are not allowed to do that.', 'doughboss' ), array( 'status' => 403 ) ); }
			return $this->verify_square_catalogue_reader( $request );
		};
		$read_menu = function () { return $this->read_square_catalogue_menu(); };
		$result = DoughBoss_Square_Catalogue_Review::observe( $slugs, $read_menu, $authorize );
		if ( is_wp_error( $result ) ) { return $result; }
		$permission = $authorize();
		if ( is_wp_error( $permission ) ) { return $permission; }
		return new WP_REST_Response( $result, 200, array( 'Cache-Control' => 'private, no-store', 'Vary' => 'Cookie, Authorization' ) );
	}

	/** Original-owner recovery only. Absence is not proof that no operation is in flight. */
	public function get_square_catalogue_operation_status( WP_REST_Request $request ) {
		$permission = $this->verify_square_catalogue_reader( $request );
		if ( is_wp_error( $permission ) ) { return $permission; }
		$headers = array( 'Cache-Control' => 'private, no-store', 'Vary' => 'Cookie, Authorization' );
		$key = $request->get_param( 'operation_key' );
		if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{64}$/D', $key ) ) {
			return new WP_REST_Response( array( 'code' => 'doughboss_square_operation_key_invalid', 'same_key_replay_required' => true, 'new_operation_key_allowed' => false, 'dispatch_allowed' => false ), 400, $headers );
		}
		$actor = get_current_user_id();
		$session_token = wp_get_session_token();
		$authorize = function () use ( $request, $actor, $session_token ) {
			if ( $actor !== get_current_user_id() ) { return new WP_Error( 'doughboss_forbidden', __( 'You are not allowed to do that.', 'doughboss' ), array( 'status' => 403 ) ); }
			$permission = $this->verify_square_catalogue_reader( $request );
			if ( is_wp_error( $permission ) ) { return $permission; }
			$permission = $this->square_catalogue_current_authority( $actor, $session_token );
			if ( is_wp_error( $permission ) ) { return $permission; }
			if ( $actor !== get_current_user_id() || ! hash_equals( $session_token, wp_get_session_token() ) ) {
				return new WP_Error( 'doughboss_forbidden', __( 'You are not allowed to do that.', 'doughboss' ), array( 'status' => 403 ) );
			}
			return $this->verify_square_catalogue_reader( $request );
		};
		$permission = $authorize();
		if ( is_wp_error( $permission ) ) { return $permission; }
		$status = 503;
		$view = array( 'code' => 'doughboss_square_operation_status_unavailable', 'same_key_replay_required' => true, 'new_operation_key_allowed' => false, 'dispatch_allowed' => false );
		try {
			$row = DoughBoss_Square_Catalogue_Operations::find( $key );
			if ( ! is_wp_error( $row ) && ( null === $row || $row['operation_key'] === $key ) ) {
				$view = $this->square_catalogue_operation_status_view( $row, $actor );
				$status = isset( $view['code'] ) ? 404 : 200;
			}
		} catch ( Throwable $error ) { /* No storage or provider internals are disclosed. */ }
		$permission = $authorize();
		if ( is_wp_error( $permission ) ) { return $permission; }
		return new WP_REST_Response( $view, $status, $headers );
	}

	/** Project only rows already validated by Operations::find; never return stored payloads. */
	private function square_catalogue_operation_status_view( $row, $actor ) {
		if ( null === $row || (int) $row['approved_by'] !== $actor ) {
			return array( 'code' => 'operation_not_observed', 'same_key_replay_required' => true, 'new_operation_key_allowed' => false, 'dispatch_allowed' => false );
		}
		return array( 'schema_version' => 1, 'operation_key' => $row['operation_key'], 'state' => $row['state'], 'version' => (int) $row['version'],
			'approved_at' => $row['approved_at_utc'], 'updated_at' => $row['updated_at_utc'],
			'source_hash' => $row['source_hash'], 'target_hash' => $row['target_hash'], 'request_hash' => $row['request_hash'],
			'receipt_present' => null !== $row['receipt_json'], 'verified_at' => $row['verified_at_utc'],
			// Eligibility only: preparation must still revalidate the identical approval.
			'preparation_replay_available' => 'prepared' === $row['state'], 'same_key_replay_required' => 'prepared' === $row['state'],
			'dispatch_allowed' => false, 'new_operation_key_allowed' => false );
	}

	/** Trusted native menu read; never accepts caller-supplied items, prices or hashes. */
	public function get_square_catalogue_source( WP_REST_Request $request ) {
		$permission = $this->verify_square_catalogue_reader( $request );
		if ( is_wp_error( $permission ) ) { return $permission; }
		$actor = get_current_user_id();
		$menu = $this->read_square_catalogue_menu();
		if ( is_wp_error( $menu ) ) { return $menu; }
		$snapshot = DoughBoss_Square_Catalogue_Source::snapshot( $menu );
		if ( is_wp_error( $snapshot ) ) { return $snapshot; }
		$permission = $this->verify_square_catalogue_reader( $request );
		if ( is_wp_error( $permission ) ) { return $permission; }
		if ( $actor !== get_current_user_id() ) {
			return new WP_Error( 'doughboss_forbidden', __( 'The operator identity changed during the source read.', 'doughboss' ), array( 'status' => 403 ) );
		}
		$snapshot['source'] = 'wordpress_menu';
		$snapshot['global_availability_only'] = true;
		$snapshot['provider_writes_allowed'] = false;
		return new WP_REST_Response( $snapshot, 200, array( 'Cache-Control' => 'private, no-store', 'Vary' => 'Cookie, Authorization' ) );
	}

	/** Complete trusted native menu shared by observation and preparation. */
	private function read_square_catalogue_menu() {
		global $wpdb;
		$menu_response = $this->get_menu();
		if ( is_wp_error( $menu_response ) ) { return $menu_response; }
		if ( ! ( $menu_response instanceof WP_REST_Response ) || ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'doughboss_square_source_unavailable', __( 'The website menu could not be read completely.', 'doughboss' ), array( 'status' => 503 ) );
		}
		$menu = $menu_response->get_data();
		// get_menu currently caps published posts at 200. Never attest a capped read.
		if ( ! is_array( $menu ) || count( $menu ) >= 200 ) {
			return new WP_Error( 'doughboss_square_source_limit', __( 'The website menu source needs a complete uncapped read before transfer.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( ! current_user_can( 'manage_doughboss' ) && ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'doughboss_forbidden', __( 'You are not allowed to do that.', 'doughboss' ), array( 'status' => 403 ) ); }
		try { $this->square_catalogue_source_cache_parity( $menu );
		} catch ( Throwable $error ) { return new WP_Error( 'doughboss_square_source_unavailable', __( 'The current database menu could not be verified completely. No preparation was authorized.', 'doughboss' ), array( 'status' => 503 ) ); }
		return $menu;
	}

	/** Keep the native producer; prove its caches agree with current locking database reads. */
	private function square_catalogue_source_cache_parity( array $menu ) {
		global $wpdb;
		$tables = array( $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->options );
		$statuses = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name IN (' . implode( ',', array_fill( 0, count( $tables ), '%s' ) ) . ')', $tables ), ARRAY_A );
		$this->square_source_need( empty( $wpdb->last_error ) && is_array( $statuses ) && count( $statuses ) === count( $tables ) );
		foreach ( $statuses as $table ) { $this->square_source_need( isset( $table['Engine'] ) && 'INNODB' === strtoupper( $table['Engine'] ) ); }
		$errors = $wpdb->suppress_errors( true );
		try {
			$isolation = $wpdb->get_var( 'SELECT @@SESSION.transaction_isolation' );
			if ( ! empty( $wpdb->last_error ) ) { $isolation = $wpdb->get_var( 'SELECT @@SESSION.tx_isolation' ); }
			$this->square_source_need( empty( $wpdb->last_error ) && in_array( strtoupper( str_replace( ' ', '-', (string) $isolation ) ), array( 'REPEATABLE-READ', 'SERIALIZABLE' ), true ) );
		} finally { $wpdb->suppress_errors( $errors ); }
		// These current reads do not reuse a transaction's older consistent-read view.
		// InnoDB RR/SERIALIZABLE next-key locks also protect missing keys/membership inserts.
		$fields = 'ID,post_title,post_excerpt,post_content,post_status,post_type,post_parent,post_mime_type,guid,menu_order';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$fields} FROM {$wpdb->posts} WHERE post_type=%s AND post_status='publish' ORDER BY menu_order ASC,post_title ASC LIMIT 200 FOR UPDATE", DoughBoss_Post_Types::POST_TYPE ), ARRAY_A );
		$this->square_source_need( empty( $wpdb->last_error ) && is_array( $rows ) && count( $rows ) < 200 && count( $rows ) === count( $menu ) );
		$cached = get_posts( array( 'post_type' => DoughBoss_Post_Types::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 200, 'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC' ) ) );
		$this->square_source_need( is_array( $cached ) && count( $cached ) === count( $rows ) );
		$ids = array(); $cached_by_id = array(); $menu_ids = array();
		foreach ( $cached as $post ) { $this->square_source_need( is_object( $post ) && isset( $post->ID ) ); $cached_by_id[ (int) $post->ID ] = $post; }
		foreach ( $menu as $item ) { $this->square_source_need( is_array( $item ) && isset( $item['id'] ) && is_int( $item['id'] ) ); $menu_ids[] = $item['id']; }
		foreach ( $rows as $row ) {
			$id = (int) $row['ID']; $this->square_source_need( isset( $cached_by_id[ $id ] ) );
			foreach ( $row as $field => $value ) { $this->square_source_need( isset( $cached_by_id[ $id ]->$field ) && (string) $value === (string) $cached_by_id[ $id ]->$field ); }
			$ids[] = $id;
		}
		$ordered_ids = $ids; sort( $ordered_ids, SORT_NUMERIC ); sort( $menu_ids, SORT_NUMERIC );
		$this->square_source_need( $ordered_ids === $menu_ids && count( $cached_by_id ) === count( $ids ) );
		$this->square_source_meta_parity( $ids, array( DoughBoss_Post_Types::META_PRICE, DoughBoss_Post_Types::META_TYPE, DoughBoss_Post_Types::META_AVAILABLE, DoughBoss_Post_Types::META_DIETARY, '_thumbnail_id' ) );
		$attachments = array();
		foreach ( $ids as $id ) {
			$thumbnail = (int) get_post_thumbnail_id( $id );
			if ( $thumbnail > 0 ) { $attachments[ $thumbnail ] = $thumbnail; }
		}
		if ( count( $attachments ) ) {
			$rows = $wpdb->get_results( "SELECT {$fields} FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', $attachments ) . ') ORDER BY ID FOR UPDATE', ARRAY_A );
			$this->square_source_need( empty( $wpdb->last_error ) && is_array( $rows ) );
			$existing = array_column( $rows, null, 'ID' );
			foreach ( $attachments as $id ) {
				$post = get_post( $id );
				if ( ! isset( $existing[ $id ] ) ) { $this->square_source_need( null === $post ); continue; }
				$this->square_source_need( is_object( $post ) );
				foreach ( $existing[ $id ] as $field => $value ) { $this->square_source_need( isset( $post->$field ) && (string) $value === (string) $post->$field ); }
			}
			$this->square_source_meta_parity( array_values( $attachments ), array( '_wp_attached_file', '_wp_attachment_metadata' ) );
		}
		if ( count( $ids ) ) {
			$terms = $wpdb->get_results( $wpdb->prepare( "SELECT tr.object_id,t.term_id,t.name FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE tr.object_id IN (" . implode( ',', $ids ) . ') AND tt.taxonomy=%s ORDER BY tr.object_id,t.term_id LIMIT 4097 FOR UPDATE', DoughBoss_Post_Types::TAXONOMY ), ARRAY_A );
			$this->square_source_need( empty( $wpdb->last_error ) && is_array( $terms ) && count( $terms ) <= 4096 );
			$expected = array(); foreach ( $terms as $term ) { $expected[ (int) $term['object_id'] ][ (int) $term['term_id'] ] = $term['name']; }
			foreach ( $ids as $id ) {
				$actual_terms = get_the_terms( $id, DoughBoss_Post_Types::TAXONOMY );
				$this->square_source_need( ! is_wp_error( $actual_terms ) );
				$actual = array(); foreach ( is_array( $actual_terms ) ? $actual_terms : array() as $term ) { $actual[ (int) $term->term_id ] = $term->name; }
				$current = isset( $expected[ $id ] ) ? $expected[ $id ] : array(); ksort( $current ); ksort( $actual );
				$this->square_source_need( $current === $actual );
			}
		}
		$option_names = array( 'siteurl', 'home', 'upload_path', 'upload_url_path', 'uploads_use_yearmonth_folders', 'blog_charset' );
		$options = $wpdb->get_results( $wpdb->prepare( "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name IN (" . implode( ',', array_fill( 0, count( $option_names ), '%s' ) ) . ') ORDER BY option_name FOR UPDATE', $option_names ), ARRAY_A );
		$this->square_source_need( empty( $wpdb->last_error ) && is_array( $options ) );
		$values = array_column( $options, 'option_value', 'option_name' );
		foreach ( $option_names as $name ) { $expected = isset( $values[ $name ] ) ? maybe_unserialize( $values[ $name ] ) : false; $this->square_source_need( $expected === get_option( $name, false ) ); }
	}

	private function square_source_meta_parity( array $ids, array $keys ) {
		if ( ! count( $ids ) ) { return; }
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', $ids ) . ') AND meta_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ') ORDER BY meta_id LIMIT 4097 FOR UPDATE', $keys ), ARRAY_A );
		$this->square_source_need( empty( $wpdb->last_error ) && is_array( $rows ) && count( $rows ) <= 4096 );
		$expected = array();
		foreach ( $rows as $row ) { if ( ! array_key_exists( $row['meta_key'], isset( $expected[ (int) $row['post_id'] ] ) ? $expected[ (int) $row['post_id'] ] : array() ) ) { $expected[ (int) $row['post_id'] ][ $row['meta_key'] ] = maybe_unserialize( $row['meta_value'] ); } }
		// The locking query proves absence, but WordPress may supply a registered
		// default for an absent row (for example type=standard, availability=1).
		foreach ( $ids as $id ) { foreach ( $keys as $key ) { $value = isset( $expected[ $id ] ) && array_key_exists( $key, $expected[ $id ] ) ? $expected[ $id ][ $key ] : get_metadata_default( 'post', $id, $key, true ); $this->square_source_need( $value === get_post_meta( $id, $key, true ) ); } }
	}

	private function square_source_need( $condition ) { if ( ! $condition ) { throw new RuntimeException( 'Current native source not proven.' ); } }

	/** Browser preparation requires current DB-backed cookie, capability and role state. */
	private function square_catalogue_current_authority( $actor, $session_token ) {
		global $wpdb;
		try {
			// Alternate authentication/session stores and multisite authority are not
			// attested by this narrow seam. There is deliberately no CLI bypass.
			$this->square_source_need( ! is_multisite() && is_int( $actor ) && $actor > 0 && is_string( $session_token ) && '' !== $session_token && hash_equals( $session_token, wp_get_session_token() ) );
			$this->square_source_need( get_class( WP_Session_Tokens::get_instance( $actor ) ) === 'WP_User_Meta_Session_Tokens' );
			$tables = array( $wpdb->users, $wpdb->usermeta, $wpdb->options );
			$statuses = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name IN (' . implode( ',', array_fill( 0, count( $tables ), '%s' ) ) . ')', $tables ), ARRAY_A );
			$this->square_source_need( empty( $wpdb->last_error ) && is_array( $statuses ) && count( $statuses ) === count( $tables ) );
			foreach ( $statuses as $table ) { $this->square_source_need( isset( $table['Engine'] ) && 'INNODB' === strtoupper( $table['Engine'] ) ); }
			$errors = $wpdb->suppress_errors( true );
			try {
				$isolation = $wpdb->get_var( 'SELECT @@SESSION.transaction_isolation' );
				if ( ! empty( $wpdb->last_error ) ) { $isolation = $wpdb->get_var( 'SELECT @@SESSION.tx_isolation' ); }
				$this->square_source_need( empty( $wpdb->last_error ) && in_array( strtoupper( str_replace( ' ', '-', (string) $isolation ) ), array( 'REPEATABLE-READ', 'SERIALIZABLE' ), true ) );
			} finally { $wpdb->suppress_errors( $errors ); }
			$user = wp_get_current_user();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID,user_login,user_pass,user_status FROM {$wpdb->users} WHERE ID=%d FOR UPDATE", $actor ), ARRAY_A );
			$this->square_source_need( empty( $wpdb->last_error ) && is_array( $row ) );
			foreach ( $row as $field => $value ) { $this->square_source_need( isset( $user->data->$field ) && (string) $value === (string) $user->data->$field ); }
			$cap_key = $wpdb->get_blog_prefix() . 'capabilities';
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key,meta_value FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key IN (%s,%s) ORDER BY umeta_id LIMIT 3 FOR UPDATE", $actor, $cap_key, 'session_tokens' ), ARRAY_A );
			$this->square_source_need( empty( $wpdb->last_error ) && is_array( $rows ) && 2 === count( $rows ) );
			$values = array();
			foreach ( $rows as $meta ) { $this->square_source_need( ! array_key_exists( $meta['meta_key'], $values ) ); $values[ $meta['meta_key'] ] = maybe_unserialize( $meta['meta_value'] ); }
			$this->square_source_need( isset( $values[ $cap_key ], $values['session_tokens'] ) && is_array( $values[ $cap_key ] ) && is_array( $values['session_tokens'] ) && $values[ $cap_key ] === $user->caps && $values[ $cap_key ] === get_user_meta( $actor, $cap_key, true ) && $values['session_tokens'] === get_user_meta( $actor, 'session_tokens', true ) );
			$verifier = hash( 'sha256', $session_token );
			$this->square_source_need( isset( $values['session_tokens'][ $verifier ]['expiration'] ) && is_int( $values['session_tokens'][ $verifier ]['expiration'] ) && $values['session_tokens'][ $verifier ]['expiration'] >= time() );
			$roles = wp_roles();
			$raw_roles = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE", $roles->role_key ) );
			$this->square_source_need( empty( $wpdb->last_error ) && is_string( $raw_roles ) && maybe_unserialize( $raw_roles ) === $roles->roles && maybe_unserialize( $raw_roles ) === get_option( $roles->role_key ) );
			$this->square_source_need( $actor === wp_validate_auth_cookie( '', 'logged_in' ) && ( current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ) ) );
			return true;
		} catch ( Throwable $error ) {
			return new WP_Error( 'doughboss_forbidden', __( 'Current owner browser authority could not be verified. Sign in again before preparing.', 'doughboss' ), array( 'status' => 403 ) );
		}
	}

	/** Owner-only preview. This status/demo route never requests provider data. */
	public function get_square_dashboard( $request ) {
		$permission = $this->verify_manage();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$mode = $request->get_param( 'mode' );
		$location = $request->get_param( 'location' );
		$view = DoughBoss_Square_Dashboard::view( null === $mode ? 'status' : $mode, null === $location ? 'all' : $location );
		if ( is_wp_error( $view ) ) {
			return $view;
		}
		return new WP_REST_Response( $view, 200, array( 'Cache-Control' => 'private, no-store', 'Vary' => 'Cookie, Authorization' ) );
	}

	/**
	 * Permission check for releasing one request to the payment stage.
	 *
	 * This is intentionally narrower than Order Board access: KDS accounts may
	 * operate the kitchen, but only the specifically configured authenticated
	 * approver may authorize a customer to pay. No configured user means the
	 * flow remains safely locked.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function verify_preorder_approver( WP_REST_Request $request ) {
		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}
		$allowed = $this->verify_manage();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$policy = DoughBoss_Settings::staff_approval_policy();
		$approver_id = isset( $policy['approver_id'] ) ? (int) $policy['approver_id'] : 0;
		if ( ! DoughBoss_Settings::staff_approval_actor_valid( $policy ) ) {
			return new WP_Error( 'doughboss_preorder_approver_unconfigured', __( 'Choose the named payment approver in DoughBoss settings before approving requests.', 'doughboss' ), array( 'status' => 503 ) );
		}
		if ( (int) get_current_user_id() !== $approver_id ) {
			return new WP_Error( 'doughboss_preorder_approver_required', __( 'Only the named payment approver can approve or reject this request.', 'doughboss' ), array( 'status' => 403 ) );
		}
		return true;
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
	 * Resolve the client IP used to bucket the rate limiter.
	 *
	 * By default this is REMOTE_ADDR verbatim — the safe choice for a site that
	 * talks to visitors directly, because REMOTE_ADDR is set by the web server
	 * and cannot be spoofed by the client.
	 *
	 * Behind a reverse proxy/CDN/load balancer, REMOTE_ADDR is the proxy's own
	 * address for every visitor, so a single shared bucket would let one burst
	 * lock all customers out of checkout. When the operator opts in via the
	 * 'behind_reverse_proxy' setting we instead read the first entry of the
	 * configured forwarded header (e.g. X-Forwarded-For), which the proxy
	 * appends the real client IP to.
	 *
	 * TRUST ASSUMPTION: enabling 'behind_reverse_proxy' is only safe when the
	 * admin has confirmed their actual proxy/CDN strips or overwrites any
	 * client-supplied copy of that header before appending its own value. If the
	 * origin were reachable directly, a caller could send a fake header and pick
	 * their own bucket, evading the limiter entirely — which is why this is a
	 * scoped, admin-opt-in fix for the common single-reverse-proxy case, not a
	 * general spoofing-proof multi-hop resolver. Every failure mode here
	 * (setting off, header missing, empty, or malformed) falls back to the
	 * unspoofable REMOTE_ADDR.
	 *
	 * @return string Sanitised client IP, or '' when none is available.
	 */
	private function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( ! DoughBoss_Settings::behind_reverse_proxy() ) {
			return $remote;
		}

		// Map the configured header name to its $_SERVER key: HTTP_ prefix, upper
		// case, dashes to underscores (e.g. 'X-Forwarded-For' => HTTP_X_FORWARDED_FOR).
		$server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', DoughBoss_Settings::trusted_proxy_header() ) );
		if ( empty( $_SERVER[ $server_key ] ) ) {
			return $remote;
		}

		$forwarded = sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) );
		// The header may carry a comma-separated chain (client, proxy1, proxy2, …);
		// the left-most entry is the originating client the proxy recorded.
		$parts     = explode( ',', $forwarded );
		$candidate = trim( (string) reset( $parts ) );

		if ( '' === $candidate || ! filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
			return $remote; // Missing or malformed — fall back to the unspoofable address.
		}

		return $candidate;
	}

	/**
	 * Simple per-IP transient rate limiter for mutation endpoints.
	 *
	 * The bucket key is derived from client_ip(), which is REMOTE_ADDR unless the
	 * operator has opted into trusting a reverse-proxy forwarded header.
	 *
	 * @param string $bucket Logical bucket name (e.g. 'checkout').
	 * @param int    $max    Max requests allowed within the window.
	 * @param int    $window Window length in seconds.
	 * @return bool True when the caller is over the limit.
	 */
	private function rate_limited( $bucket, $max, $window ) {
		global $wpdb;
		$ip  = $this->client_ip();
		$key = 'doughboss_rl_' . $bucket . '_' . md5( $ip );

		// Serialize the read-increment-write per bucket+IP with a named DB lock
		// (same pattern as DoughBoss_Voucher::claim) so concurrent requests can't
		// both read the same count and under-increment. If the lock can't be
		// taken, fail open — availability beats strictness for a rate limiter.
		$lock = 'dbrl_' . md5( $bucket . '|' . $ip );
		$got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== $got ) {
			return false;
		}

		$hits    = (int) get_transient( $key );
		$limited = $hits >= $max;
		if ( ! $limited ) {
			set_transient( $key, $hits + 1, $window );
		}
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $limited;
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
	 * Whether a required-policy request intake may create a new durable request.
	 *
	 * @param array $policy Authoritative, server-owned policy context.
	 * @return bool
	 */
	private function staff_approval_requests_enabled( array $policy ) {
		return 'required' === ( isset( $policy['mode'] ) ? (string) $policy['mode'] : '' )
			&& DoughBoss_Settings::staff_approval_actor_valid( $policy )
			&& ! empty( $policy['pickup_enabled'] )
			&& ( ! empty( $policy['ordering_open'] ) || ! empty( $policy['after_hours'] ) );
	}

	/**
	 * Fail closed before an ordinary payment attempt or checkout can dispatch.
	 *
	 * @return true|WP_Error
	 */
	private function ordinary_checkout_policy_error() {
		$policy = DoughBoss_Settings::staff_approval_policy();
		if ( 'legacy' === ( isset( $policy['mode'] ) ? (string) $policy['mode'] : '' ) ) {
			return true;
		}
		if ( 'required' === ( isset( $policy['mode'] ) ? (string) $policy['mode'] : '' ) ) {
			return new WP_Error( 'doughboss_staff_approval_required', __( 'This order needs staff approval before payment. Submit a pickup request instead.', 'doughboss' ), array( 'status' => 409 ) );
		}
		return new WP_Error( 'doughboss_staff_approval_unavailable', __( 'Online checkout is temporarily unavailable while staff approval is configured.', 'doughboss' ), array( 'status' => 503 ) );
	}

	/**
	 * A corrupt/unreadable policy may not start a fresh approved-link payment.
	 * Existing irreversible completion/recovery stays on its separate path.
	 *
	 * @return true|WP_Error
	 */
	private function approved_payment_policy_error() {
		$policy = DoughBoss_Settings::staff_approval_policy();
		if ( 'unavailable' === ( isset( $policy['mode'] ) ? (string) $policy['mode'] : '' ) ) {
			return new WP_Error( 'doughboss_preorder_payment_policy_unavailable', __( 'Approved payment is temporarily unavailable while staff approval is configured. Please contact the shop and do not try another payment link.', 'doughboss' ), array( 'status' => 503 ) );
		}
		return true;
	}

	/**
	 * Return one exact positive native integer ID, or false without coercion.
	 *
	 * @param mixed $value Raw REST parameter.
	 * @return int|false
	 */
	private function canonical_native_location_id( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : false;
		}
		if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return false;
		}
		$max = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) > 0 ) ) {
			return false;
		}
		return (int) $value;
	}

	/**
	 * REST schema validation for one public native approval-request location.
	 *
	 * @param mixed           $value Submitted value.
	 * @param WP_REST_Request $request REST request.
	 * @param string          $param Parameter name.
	 * @return bool
	 */
	public function validate_staff_approval_location_param( $value, $request = null, $param = '' ) {
		return false !== $this->canonical_native_location_id( $value );
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
		$transaction_ref = substr( sanitize_text_field( (string) $request->get_param( 'transaction_ref' ) ), 0, 64 );
		$owner_id = absint( DoughBoss_Settings::get( 'voucher_reconciliation_owner_id', 0 ) );
		$owner = $owner_id ? get_userdata( $owner_id ) : false;
		if ( ! $owner || ! ( user_can( $owner, 'manage_doughboss' ) || user_can( $owner, 'manage_options' ) ) ) {
			return new WP_Error( 'doughboss_voucher_owner', __( 'Voucher scans are paused until a manager is assigned as the reconciliation owner.', 'doughboss' ), array( 'status' => 503 ) );
		}
		if ( '' === $transaction_ref ) {
			return new WP_Error( 'doughboss_voucher_reconciliation', __( 'Complete the till sale first, then enter its receipt reference.', 'doughboss' ), array( 'status' => 422 ) );
		}
		$cashier = wp_get_current_user();
		if ( ! $cashier || ! $cashier->exists() ) {
			return new WP_Error( 'doughboss_voucher_cashier', __( 'Sign in again before redeeming a voucher.', 'doughboss' ), array( 'status' => 401 ) );
		}

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
			array(
				'idempotency_key' => (string) $request->get_param( 'idempotency_key' ),
				'pospal_ticket_no' => $transaction_ref,
				'redeemed_by_user_id' => (int) $cashier->ID,
				'redeemed_by_name' => $cashier->display_name ? $cashier->display_name : $cashier->user_login,
			)
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
	 * GET /pay/tyro-test — owner action: read-only Tyro Connect OAuth check.
	 * No money moves and no order is created.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function tyro_test( WP_REST_Request $request ) {
		unset( $request );
		if ( ! class_exists( 'DoughBoss_Tyro' ) || '' === DoughBoss_Settings::tyro_merchant_id() || '' === DoughBoss_Settings::tyro_password() ) {
			return rest_ensure_response(
				array(
					'ready'   => false,
					'ok'      => false,
					'message' => __( 'Tyro is not fully configured — set the merchant ID and password for the active mode.', 'doughboss' ),
				)
			);
		}
		$result = DoughBoss_Tyro::test_connection();
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
				'message' => __( 'Tyro reachable and the credentials were accepted.', 'doughboss' ),
			)
		);
	}

	/**
	 * GET /pay/mpgs-test — owner-only, read-only MPGS authentication check.
	 * No session, payment, card data or order is created.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function mpgs_test( WP_REST_Request $request ) {
		unset( $request );
		if ( ! class_exists( 'DoughBoss_MPGS' ) || '' === DoughBoss_Settings::mpgs_merchant_id() || '' === DoughBoss_Settings::mpgs_api_password() || '' === DoughBoss_Settings::mpgs_host() ) {
			return rest_ensure_response(
				array(
					'ready'   => false,
					'ok'      => false,
					'message' => __( 'Mastercard Gateway is not fully configured for the active mode.', 'doughboss' ),
				)
			);
		}
		$result = DoughBoss_MPGS::test_connection();
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
				'message' => __( 'Mastercard Gateway is reachable and the credentials were accepted.', 'doughboss' ),
			)
		);
	}

	/**
	 * POST /mpgs-notification â€” receive a Hosted Checkout order update and
	 * reconcile it by retrieving the authoritative gateway order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function mpgs_notification( WP_REST_Request $request ) {
		$result = DoughBoss_MPGS::reconcile_notification(
			$request->get_param( 'order' ),
			$request->get_param( 'token' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'received' => true ) );
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
	 * GET /pospal/products — list the selected store's POSPal products, for the
	 * admin "Product mapping" screen. Read-only, paginates through
	 * queryProductPages itself (capped) so the browser gets one flat list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function pospal_products( WP_REST_Request $request ) {
		$store = DoughBoss_Settings::pospal_store( max( 1, (int) $request->get_param( 'store' ) ) );
		if ( '' === $store['host'] || '' === $store['app_id'] || '' === $store['app_key'] ) {
			/* translators: %s: store label. */
			return rest_ensure_response( array( 'ok' => false, 'message' => sprintf( __( '%s is not configured — set its host, App ID and App Key, then save.', 'doughboss' ), $store['label'] ) ) );
		}
		$creds = array(
			'host'    => $store['host'],
			'app_id'  => $store['app_id'],
			'app_key' => $store['app_key'],
		);

		$products = array();
		$params   = array();
		// POSPal paginates via an echoed-back postBackParameter; cap at 20 pages
		// (a few thousand products) so a misbehaving account can't hang the request.
		for ( $page = 0; $page < 20; $page++ ) {
			$result = DoughBoss_POSPal::query_products( $creds, $params );
			if ( is_wp_error( $result ) ) {
				if ( empty( $products ) ) {
					return rest_ensure_response( array( 'ok' => false, 'message' => $result->get_error_message() ) );
				}
				break; // Later page failed — return what we already have.
			}
			$rows = isset( $result['result'] ) && is_array( $result['result'] ) ? $result['result'] : array();
			foreach ( $rows as $row ) {
				if ( empty( $row['uid'] ) || ! isset( $row['name'] ) ) {
					continue;
				}
				$products[] = array(
					'uid'   => (string) $row['uid'],
					'name'  => (string) $row['name'],
					'price' => isset( $row['sellPrice'] ) ? (float) $row['sellPrice'] : null,
				);
			}
			$next = isset( $result['postBackParameter'] ) ? $result['postBackParameter'] : '';
			if ( '' === $next || empty( $rows ) ) {
				break;
			}
			$params = array( 'postBackParameter' => $next );
		}

		return rest_ensure_response(
			array(
				'ok'       => true,
				'products' => $products,
				'message'  => sprintf(
					/* translators: %d: number of products found. */
					_n( '%d product found.', '%d products found.', count( $products ), 'doughboss' ),
					count( $products )
				),
			)
		);
	}

	/**
	 * POST /pospal/product-map — save the menu-item -> POSPal product uid map used
	 * by order push (DoughBoss_POSPal_Orders::build_body()). Same setting the
	 * `wp doughboss pospal-map` CLI command writes; this is the point-and-click
	 * equivalent for operators without shell access.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function pospal_save_product_map( WP_REST_Request $request ) {
		$raw = $request->get_param( 'map' );
		if ( ! is_array( $raw ) ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => __( 'No mapping data received.', 'doughboss' ) ) );
		}

		$clean = array();
		foreach ( $raw as $key => $uid ) {
			$key = DoughBoss_POSPal_Orders::norm( sanitize_text_field( (string) $key ) );
			$uid = sanitize_text_field( (string) $uid );
			if ( '' === $key || '' === $uid ) {
				continue; // Blank selection ("— not mapped —") — leave that item unmapped.
			}
			$clean[ $key ] = DoughBoss_POSPal::long_id( $uid );
		}

		DoughBoss_Settings::update( array( 'pospal_product_map' => $clean ) );

		return rest_ensure_response(
			array(
				'ok'      => true,
				'count'   => count( $clean ),
				'message' => sprintf(
					/* translators: %d: number of menu items mapped. */
					_n( 'Saved — %d item mapped.', 'Saved — %d items mapped.', count( $clean ), 'doughboss' ),
					count( $clean )
				),
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
		$location_scope = current_user_can( 'manage_doughboss_kds' ) ? DoughBoss_Staff_Scope::current_location_id() : 0;
		return rest_ensure_response(
			array(
				'name'       => $user ? $user->display_name : '',
				'can_redeem' => current_user_can( 'redeem_doughboss_vouchers' ) || current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ),
				'can_manage' => current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ),
				'can_board'  => current_user_can( 'manage_doughboss_kds' ) || current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ),
				'location_id'=> is_wp_error( $location_scope ) ? 0 : (int) $location_scope,
				'location_assignment_required' => is_wp_error( $location_scope ),
				'currency'   => DoughBoss_Settings::get( 'currency_symbol', '$' ),
			)
		);
	}

	/**
	 * GET /status — health/version snapshot for uptime monitoring (admin-gated).
	 *
	 * Reports the stored vs. code DB schema version (a mismatch means a pending
	 * or stuck migration) and each integration's ready() boolean. Booleans only —
	 * no keys, secrets or endpoint URLs ever leave the server here.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		return rest_ensure_response(
			array(
				'plugin_version'    => DOUGHBOSS_VERSION,
				'db_version_code'   => DOUGHBOSS_DB_VERSION,
				'db_version_stored' => (string) get_option( 'doughboss_db_version', '0' ),
				'integrations'      => array(
					'stripe'         => (bool) DoughBoss_Stripe::ready(),
					'stripe_webhook' => (bool) DoughBoss_Settings::stripe_webhook_configured(),
					'tyro'           => (bool) DoughBoss_Tyro::ready(),
					'pospal'         => (bool) DoughBoss_POSPal::ready(),
					'mercure'        => (bool) DoughBoss_Settings::mercure_ready(),
					'ntfy'    => (bool) DoughBoss_Settings::ntfy_ready(),
					'sms'     => (bool) DoughBoss_SMS::ready(),
					'printer' => (bool) DoughBoss_Settings::printer_ready(),
				),
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
		$id     = absint( $request->get_param( 'id' ) );
		$reason = trim( (string) $request->get_param( 'reason' ) );
		$actor  = wp_get_current_user();
		if ( strlen( $reason ) < 5 ) {
			return new WP_Error( 'doughboss_void_reason', __( 'Give a short reason before voiding a voucher.', 'doughboss' ), array( 'status' => 400 ) );
		}
		if ( ! DoughBoss_Voucher::void( $id, $reason, (int) $actor->ID, $actor->display_name ? $actor->display_name : $actor->user_login ) ) {
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
	/**
	 * POST /voucher/reverse - re-open a genuine in-store mis-scan (owner only).
	 * Online or linked payment redemptions are refused; use the order/payment
	 * refund path for those transactions instead.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_reverse_voucher( WP_REST_Request $request ) {
		$actor  = wp_get_current_user();
		$result = DoughBoss_Voucher::reverse_redemption(
			absint( $request->get_param( 'id' ) ),
			(string) $request->get_param( 'reason' ),
			(int) $actor->ID,
			$actor->display_name ? $actor->display_name : $actor->user_login
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'reversed'      => true,
				'voucher_id'    => $result['voucher_id'],
				'redemption_id' => $result['redemption_id'],
			)
		);
	}

	/**
	 * POST /voucher/issue - create a voucher (owner/admin only).
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
	 * the dough5 $5 student launch voucher with its DOUGH- codes and a daily
	 * pool of 100).
	 * Public (nonce) + rate-limited; the per-day cap is enforced server-side,
	 * pooled across the campaign's cap_group.
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
				'customer_email_confirmation' => $request->get_param( 'customer_email_confirmation' ),
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
		$single_location_id = DoughBoss_Locations::single_location_id();
		$gateway = DoughBoss_Settings::payment_gateway();
		$staff_approval_policy = DoughBoss_Settings::staff_approval_policy();
		$staff_approval_required = 'legacy' !== ( isset( $staff_approval_policy['mode'] ) ? (string) $staff_approval_policy['mode'] : '' );
		$staff_approval_requests_enabled = 'required' === $staff_approval_policy['mode']
			&& DoughBoss_Settings::staff_approval_actor_valid( $staff_approval_policy )
			&& ! empty( $staff_approval_policy['pickup_enabled'] )
			&& ( ! empty( $staff_approval_policy['ordering_open'] ) || ! empty( $staff_approval_policy['after_hours'] ) );
		$ordinary_checkout_allowed = 'legacy' === ( isset( $staff_approval_policy['mode'] ) ? (string) $staff_approval_policy['mode'] : '' );
		$payment_ready = $ordinary_checkout_allowed && DoughBoss_Payment::ready();
		if ( 'square' === $gateway ) {
			// Multi-shop pages have no server-selected ordinary SDK shop.
			// Approved links use their separate persisted order location.
			$payment_ready = $ordinary_checkout_allowed && DoughBoss_Settings::ordering_open() && $single_location_id
				&& ! is_wp_error( DoughBoss_Locations::online_payment_location( $single_location_id ) )
				&& DoughBoss_Square::ready_for_location( $single_location_id );
		}
		return rest_ensure_response(
			array(
				'currency_symbol' => DoughBoss_Settings::get( 'currency_symbol', '$' ),
				'currency_code'   => DoughBoss_Settings::get( 'currency_code', 'AUD' ),
				'tax_rate'        => (float) DoughBoss_Settings::get( 'tax_rate', 0 ),
				'gst_inclusive'   => DoughBoss_Settings::gst_inclusive(),
				'delivery_fee'    => (float) DoughBoss_Settings::get( 'delivery_fee', 0 ),
				'enable_pickup'   => (bool) DoughBoss_Settings::get( 'enable_pickup', 1 ),
				// 2.51: this build can schedule pickups (per-shop mode decides).
				'pickup_scheduling' => true,
				'enable_delivery' => $single_location_id ? false : (bool) DoughBoss_Settings::get( 'enable_delivery', 0 ),
				// Single-location / pickup-only mode. When on, the storefront should
				// hide the shop picker + delivery toggle and auto-select the single
				// active location — matches the "For now, pickup only from Revesby"
				// scope in the client discovery doc.
				'single_location_mode' => (bool) $single_location_id,
				'single_location_id'   => $single_location_id,
				'ordering_open'   => DoughBoss_Settings::ordering_open(),
				'ordering_closed_message' => DoughBoss_Settings::ordering_closed_message(),
				// Public copy only; this does not expose, initialise or imply any
				// payment capability. The browser may offer the request form only
				// while normal checkout is closed and this explicit owner gate is on.
				'after_hours_preorders_enabled' => ! DoughBoss_Settings::ordering_open() && DoughBoss_Settings::after_hours_preorders_enabled(),
				'after_hours_preorders_message' => DoughBoss_Settings::after_hours_preorders_message(),
				// These booleans intentionally reveal mode availability only. The
				// configured approver identity and policy internals remain server-only.
				'staff_approval_required' => (bool) $staff_approval_required,
				'staff_approval_requests_enabled' => (bool) $staff_approval_requests_enabled,
				'sizes'           => DoughBoss_Settings::sizes(),
				'toppings'        => DoughBoss_Settings::toppings(),
				'payments_enabled' => DoughBoss_Settings::ordering_open() && $payment_ready,
				// 'stripe_pk' kept as the field name for backward compatibility with
				// existing storefront JS — it now carries whichever gateway's public
				// identifier is active (Stripe publishable key or Tyro Connect marker),
			// per 'payment_gateway' below.
				'stripe_pk'        => $payment_ready ? DoughBoss_Payment::publishable_key() : '',
				'payment_gateway'  => $gateway,
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
	/**
	 * Set once a payment provider request may have been sent for the current
	 * /payment-intent call. Errors raised before that point are retry-safe.
	 *
	 * @var bool
	 */
	private $payment_dispatch_reached = false;

	/**
	 * /payment-intent route. Wraps the implementation so every refusal raised
	 * BEFORE any provider call tells the storefront it may simply retry, instead
	 * of freezing the checkout on "payment pending" when no money can have moved.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_payment_intent( WP_REST_Request $request ) {
		$this->payment_dispatch_reached = false;
		$result = $this->create_payment_intent_inner( $request );
		if ( is_wp_error( $result ) && ! $this->payment_dispatch_reached ) {
			$data = $result->get_error_data();
			$data = is_array( $data ) ? $data : array( 'status' => is_int( $data ) ? $data : 400 );
			if ( ! array_key_exists( 'payment_pending', $data ) ) {
				$data['payment_pending'] = false;
				$data['retry_safe']      = true;
				$result->add_data( $data );
			}
		}
		return $result;
	}

	/**
	 * Implementation of /payment-intent (see create_payment_intent()).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	private function create_payment_intent_inner( WP_REST_Request $request ) {
		if ( version_compare( (string) get_option( 'doughboss_db_version', '0' ), '1.25.0', '<' ) || ! DoughBoss_Activator::checkout_storage_ready() || ! DoughBoss_Activator::payment_storage_ready() ) {
			return new WP_Error( 'doughboss_checkout_storage_unavailable', __( 'Card payments are paused while checkout storage is upgraded.', 'doughboss' ), array( 'status' => 503 ) );
		}
		$ordinary_policy_error = $this->ordinary_checkout_policy_error();
		if ( is_wp_error( $ordinary_policy_error ) ) {
			return $ordinary_policy_error;
		}
		if ( 'square' !== DoughBoss_Settings::payment_gateway() && ! DoughBoss_Payment::ready() ) {
			return new WP_Error( 'doughboss_pay_off', __( 'Card payments are not available right now.', 'doughboss' ), array( 'status' => 400 ) );
		}

		if ( $this->rate_limited( 'payment_intent', 12, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'doughboss_rate_limit', __( 'Too many requests. Please wait a few minutes and try again.', 'doughboss' ), array( 'status' => 429 ) );
		}

		// Mirror checkout()'s business-rule gates here — a card must never be
		// charged for an order the shop will go on to refuse (closed, or an
		// order type it doesn't offer). Charging first and discovering the
		// refusal only at /checkout leaves a captured payment with no order.
		if ( ! DoughBoss_Settings::ordering_open() ) {
			return new WP_Error( 'doughboss_closed', DoughBoss_Settings::ordering_closed_message(), array( 'status' => 503 ) );
		}

		if ( $this->cart->is_empty() ) {
			return new WP_Error( 'doughboss_empty', __( 'Your cart is empty.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$table_context = $this->table_order_context();
		if ( is_wp_error( $table_context ) ) {
			return $table_context;
		}
		if ( $table_context ) {
			$order_type = 'dine_in';
		} else {
			$order_type = sanitize_key( $request->get_param( 'order_type' ) );
			$order_type = ( 'delivery' === $order_type ) ? 'delivery' : 'pickup';
			if ( DoughBoss_Locations::single_location_id() ) {
				$order_type = 'pickup';
			}
		}

		if ( 'delivery' === $order_type && ! DoughBoss_Settings::get( 'enable_delivery', 0 ) ) {
			return new WP_Error( 'doughboss_no_delivery', __( 'Delivery is not available.', 'doughboss' ), array( 'status' => 400 ) );
		}
		if ( 'pickup' === $order_type && ! DoughBoss_Settings::get( 'enable_pickup', 1 ) ) {
			return new WP_Error( 'doughboss_no_pickup', __( 'Pickup is not available.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$location_id = $table_context ? (int) $table_context['location_id'] : $this->resolve_order_location( $request->get_param( 'location_id' ), $order_type );
		if ( is_wp_error( $location_id ) ) {
			return $location_id;
		}
		if ( 'square' === DoughBoss_Settings::payment_gateway() || DoughBoss_Payment::ready() ) {
			$payment_location = DoughBoss_Locations::online_payment_location( $location_id );
			if ( is_wp_error( $payment_location ) ) {
				return $payment_location;
			}
		}

		$gateway = DoughBoss_Settings::payment_gateway();
		$name    = sanitize_text_field( $request->get_param( 'customer_name' ) );
		if ( 'square' === $gateway && ! DoughBoss_Square::ready_for_location( $location_id ) ) {
			return new WP_Error( 'doughboss_pay_off', __( 'Card payments are not available right now.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$email   = sanitize_email( $request->get_param( 'customer_email' ) );
		$phone   = sanitize_text_field( $request->get_param( 'customer_phone' ) );
		$address = sanitize_textarea_field( $request->get_param( 'address' ) );
		$notes   = sanitize_textarea_field( $request->get_param( 'notes' ) );
		$tender_kind = 'card';
		// Square-hosted checkout (owner setting) redirects to Square's own page.
		// The embedded Web Payments SDK path below is unchanged when this is off.
		$square_hosted = 'square' === $gateway && DoughBoss_Square_Hosted::enabled();
		if ( 'square' === $gateway && ! $square_hosted ) {
			$tender_kind = DoughBoss_Square::tender_kind( $request->get_param( 'tender_kind' ) );
			if ( is_wp_error( $tender_kind ) ) { return $tender_kind; }
		}
		// Stripe and Square both need the contact details BEFORE the money moves:
		// Stripe because the hosted session is created from an immutable
		// snapshot, Square because this call actually charges the card, and a
		// card must never be charged for an order /checkout will then refuse for
		// missing details.
		if ( 'stripe' === $gateway || 'square' === $gateway ) {
			if ( '' === $name || ! is_email( $email ) || '' === $phone || ( 'delivery' === $order_type && '' === $address ) ) {
				return new WP_Error( 'doughboss_invalid', __( 'Complete your contact and fulfilment details before continuing to payment.', 'doughboss' ), array( 'status' => 400 ) );
			}
		}

		// 2.51: a live shop needs a held pickup time before money moves. Empty
		// (mode off/shadow, dine-in, delivery) leaves every key below unchanged.
		$pickup = $this->pickup_selection_for_checkout( $request, $location_id, $order_type, true );
		if ( is_wp_error( $pickup ) ) {
			return $pickup;
		}

		$totals   = $this->cart->totals( $order_type );
		$accounting_basis = $this->accounting_basis_from_totals( $totals );
		if ( is_wp_error( $accounting_basis ) ) {
			return $accounting_basis;
		}
		$currency = DoughBoss_Settings::get( 'currency_code', 'AUD' );
		$amount   = DoughBoss_Payment::to_minor_units( $totals['total'] );

		$square_binding = null;
		$attempt_identity = '';
		$cart_guard       = '';
		if ( 'square' === $gateway ) {
			$square_binding = $this->square_binding_payload( $order_type, $location_id, $table_context, $name, $email, $phone, $address, $notes, $pickup );
			$checkout_key   = $this->payment_checkout_key( $request, 'square-order-v2', $square_binding );
			$attempt_identity = $this->payment_attempt_identity( $request, 'square-order-v2' );
			$cart_guard       = $this->square_cart_guard();
		} else {
			// Preserve the shipped Stripe/other-gateway identity contract exactly.
			$checkout_key = $this->payment_checkout_key(
				$request,
				'order',
				$this->pickup_checkout_key_binding(
					array( 'cart' => $this->cart->to_array( $order_type ), 'location_id' => $location_id, 'order_type' => $order_type, 'table' => $table_context ),
					$pickup
				)
			);
		}
		if ( is_wp_error( $checkout_key ) ) {
			return $checkout_key;
		}
		if ( is_wp_error( $attempt_identity ) || is_wp_error( $cart_guard ) ) {
			return is_wp_error( $attempt_identity ) ? $attempt_identity : $cart_guard;
		}
		$availability_error = $this->payment_availability_error( $this->cart->get_lines(), $location_id, $checkout_key, $totals, $currency );
		if ( is_wp_error( $availability_error ) ) { return $availability_error; }
		if ( $square_hosted ) {
			// A new attempt key for the same cart supersedes an earlier, unpaid
			// hosted link (e.g. a lost tab). Retiring deletes that link first and
			// never touches a tendered Square Order, so nothing can be paid twice.
			$superseded = DoughBoss_Payment_Attempts::find_blocking_by_local_reference( 'square', $cart_guard, hash( 'sha256', 'square-v2|' . $attempt_identity ) );
			if ( is_array( $superseded ) && DoughBoss_Square_Hosted::is_hosted_attempt( $superseded )
				&& 'dispatching' === (string) $superseded['status'] && empty( $superseded['provider_reference'] ) ) {
				DoughBoss_Square_Hosted::retire_unpaid( $superseded );
			}
		}

		$voucher_reservation_key  = '';
		$reserved_voucher_code    = '';
		$priced_voucher_code      = isset( $totals['voucher_code'] ) ? (string) $totals['voucher_code'] : '';
		$priced_voucher_discount  = isset( $totals['discount'] ) ? (float) $totals['discount'] : 0.0;
		if ( in_array( $gateway, array( 'stripe', 'square' ), true ) && '' !== $priced_voucher_code && $priced_voucher_discount > 0 ) {
			$reservation = DoughBoss_Voucher::reserve(
				$priced_voucher_code,
				(float) $totals['subtotal'],
				'online',
				$checkout_key,
				DoughBoss_Voucher::RESERVATION_TTL_SECONDS,
				$email
			);
			if ( is_wp_error( $reservation ) ) {
				return $reservation;
			}
			$voucher_reservation_key = $checkout_key;
			$reserved_voucher_code   = (string) $reservation['code'];
			// Pricing and leasing both recompute from the database. Refuse payment
			// if an administrative change somehow made those two results diverge.
			if ( abs( (float) $reservation['amount'] - $priced_voucher_discount ) > 0.009 ) {
				return new WP_Error( 'doughboss_voucher_changed', __( 'This voucher changed while payment was being prepared. Please review your cart and try again.', 'doughboss' ), array( 'status' => 409 ) );
			}
		}

		$metadata = array(
			'checkout_key' => $checkout_key,
			'purpose'      => 'order',
			'context'      => $table_context ? 'table_qr' : 'web',
			'order_type'   => $order_type,
			'location_id'  => $location_id,
			'table_id'     => $table_context ? (int) $table_context['table_id'] : 0,
			'qr_code_id'   => $table_context ? (int) $table_context['qr_code_id'] : 0,
			'site'         => home_url(),
		);
		if ( in_array( $gateway, array( 'stripe', 'square' ), true ) ) {
			$snapshot_payload = array(
					'order' => array(
						'order_type'        => $order_type,
						'location_id'       => $location_id,
						'table_id'          => $table_context ? (int) $table_context['table_id'] : 0,
						'table_label'       => $table_context ? (string) $table_context['table_label'] : '',
						'table_qr_code_id'  => $table_context ? (int) $table_context['qr_code_id'] : 0,
						'table_session_id'  => $table_context ? (int) $table_context['session_id'] : 0,
						'order_source'      => $table_context ? 'table_qr' : 'web',
						'customer_name'     => $name,
						'customer_email'    => $email,
						'customer_phone'    => $phone,
						'address'           => $address,
						'notes'             => $notes,
						'subtotal'          => (float) $totals['subtotal'],
						'tax'               => (float) $totals['tax'],
						'delivery_fee'      => (float) $totals['delivery_fee'],
						'total'             => (float) $totals['total'],
						'discount'          => isset( $totals['discount'] ) ? (float) $totals['discount'] : 0.0,
						'voucher_code'      => '' !== $reserved_voucher_code ? $reserved_voucher_code : $priced_voucher_code,
						'voucher_reservation_key' => $voucher_reservation_key,
						'accounting_basis'   => $accounting_basis,
						'currency'          => strtoupper( (string) $currency ),
						'checkout_key'      => $checkout_key,
					),
					'lines' => array_values( $this->cart->get_lines() ),
				);
			if ( ! empty( $pickup ) ) {
				// After checkout_key, so every snapshot-driven order writer inherits
				// the pickup facts (and legacy payload bytes are untouched when empty).
				$snapshot_payload['order'] = array_merge( $snapshot_payload['order'], $this->pickup_snapshot_fields( $pickup, $location_id ) );
			}
			if ( 'square' === $gateway ) {
				if ( ! $square_hosted ) {
					$snapshot_payload['order'] = $this->native_pickup_snapshot_order( $snapshot_payload['order'], $checkout_key, $location_id );
				}
				$snapshot_payload['binding_hash'] = $this->square_binding_hash( $square_binding );
			}
			$snapshot = $this->store_ordinary_payment_snapshot( $checkout_key, $snapshot_payload );
			if ( is_wp_error( $snapshot ) ) {
				// Do not release here: this checkout key may already own a live
				// Stripe Session from an earlier response. Snapshot conflicts and
				// unknown storage outcomes must retain the lease until it expires.
				return $snapshot;
			}
		}
		if ( 'stripe' === $gateway ) {
			$return_urls = $this->stripe_checkout_return_urls( $request->get_param( 'return_url' ) );
			$intent      = DoughBoss_Stripe::create_checkout_session(
				$amount,
				$currency,
				$metadata,
				$return_urls['success'],
				$return_urls['cancel'],
				$email
			);
		} elseif ( $square_hosted ) {
			// Square-hosted checkout: no card data or token exists here. The same
			// immutable snapshot and itemised Order projection back a Square
			// payment link; /checkout later proves the payment from Square itself.
			$metadata['tender_kind']        = 'card';
			$metadata['protocol_version']   = 'square-v2';
			$metadata['attempt_identity']   = $attempt_identity;
			$metadata['binding_hash']       = $this->square_binding_hash( $square_binding );
			$metadata['cart_guard']         = $cart_guard;
			$metadata['voucher_code']       = $reserved_voucher_code;
			$metadata['voucher_reservation_key'] = $voucher_reservation_key;
			$metadata['subtotal_minor']     = DoughBoss_Payment::to_minor_units( $totals['subtotal'] );
			$metadata['tax_minor']          = DoughBoss_Payment::to_minor_units( $totals['tax'] );
			$metadata['delivery_fee_minor'] = DoughBoss_Payment::to_minor_units( $totals['delivery_fee'] );
			$metadata['discount_minor']     = DoughBoss_Payment::to_minor_units( $priced_voucher_discount );
			$metadata['return_url']         = $this->same_site_return_url( $request->get_param( 'return_url' ) );
			$this->payment_dispatch_reached = true;
			$intent = DoughBoss_Square_Hosted::create_checkout( $amount, $currency, $metadata );
			if ( is_wp_error( $intent ) ) {
				$error_data = $intent->get_error_data();
				if ( is_array( $error_data ) && ! empty( $error_data['retry_safe'] ) && empty( $error_data['retry_same_attempt'] ) && '' !== $reserved_voucher_code ) {
					// Only a closed (failed) attempt releases its voucher binding.
					if ( ! empty( $error_data['attempt_id'] ) ) {
						DoughBoss_Voucher::release_payment_reservation( $reserved_voucher_code, $voucher_reservation_key, (int) $error_data['attempt_id'] );
					}
					DoughBoss_Voucher::release_reservation( $reserved_voucher_code, $voucher_reservation_key );
				}
			}
		} elseif ( 'square' === $gateway ) {
			// Square charges synchronously from a single-use browser card token.
			// The token and its optional 3-D Secure evidence are passed straight
			// through to Square and never stored (DoughBoss_Square strips both
			// before the attempt row is written). The AMOUNT is the one this
			// server just computed from the stored cart — never a client value.
			$metadata['source_id']          = sanitize_text_field( (string) $request->get_param( 'source_id' ) );
			$metadata['tender_kind']        = $tender_kind;
			$metadata['verification_token'] = sanitize_text_field( (string) $request->get_param( 'verification_token' ) );
			$metadata['protocol_version']   = 'square-v2';
			$metadata['attempt_identity']   = $attempt_identity;
			$metadata['binding_hash']       = $this->square_binding_hash( $square_binding );
			$metadata['cart_guard']         = $cart_guard;
			$metadata['voucher_code']       = $reserved_voucher_code;
			$metadata['voucher_reservation_key'] = $voucher_reservation_key;
			$metadata['subtotal_minor']     = DoughBoss_Payment::to_minor_units( $totals['subtotal'] );
			$metadata['tax_minor']          = DoughBoss_Payment::to_minor_units( $totals['tax'] );
			$metadata['delivery_fee_minor'] = DoughBoss_Payment::to_minor_units( $totals['delivery_fee'] );
			$metadata['discount_minor']     = DoughBoss_Payment::to_minor_units( $priced_voucher_discount );
			$this->payment_dispatch_reached = true;
			$intent = DoughBoss_Square::create_payment_intent( $amount, $currency, $metadata );
			if ( is_wp_error( $intent ) ) {
				$error_data = $intent->get_error_data();
				if ( is_array( $error_data ) && ! empty( $error_data['retry_safe'] ) && '' !== $reserved_voucher_code ) {
					// A declined card leaves the reservation bound to the failed attempt
					// (payment_pending); release that binding first so the customer can
					// retry with the same voucher, then clear any plain reservation.
					if ( ! empty( $error_data['attempt_id'] ) ) {
						DoughBoss_Voucher::release_payment_reservation( $reserved_voucher_code, $voucher_reservation_key, (int) $error_data['attempt_id'] );
					}
					DoughBoss_Voucher::release_reservation( $reserved_voucher_code, $voucher_reservation_key );
				}
			} elseif ( in_array( (string) $intent['status'], array( 'failed', 'voided' ), true ) ) {
				$release_error = null;
				if ( '' !== $reserved_voucher_code ) {
					$released = DoughBoss_Voucher::release_payment_reservation( $reserved_voucher_code, $voucher_reservation_key, (int) $intent['attempt_id'] );
					$release_error = is_wp_error( $released ) ? $released : null;
				}
				$intent = $release_error ? new WP_Error(
					'doughboss_voucher_payment_storage',
					__( 'The card was not charged, but the voucher is still being released. Please wait and try again.', 'doughboss' ),
					array( 'status' => 503, 'outcome' => 'terminal_no_charge', 'retry_safe' => false, 'payment_pending' => false, 'attempt_id' => (int) $intent['attempt_id'] )
				) : new WP_Error(
					'doughboss_pay_declined',
					__( 'That card payment was not approved. Please try another card.', 'doughboss' ),
					array( 'status' => 402, 'outcome' => 'terminal_no_charge', 'retry_safe' => true, 'payment_pending' => false, 'attempt_id' => (int) $intent['attempt_id'] )
				);
			} elseif ( 'succeeded' !== (string) $intent['status'] ) {
				$intent = new WP_Error(
					'doughboss_pay_incomplete',
					__( 'Your payment is still being confirmed. Please do not pay again; use this page to check it or contact the shop.', 'doughboss' ),
					array( 'status' => 409, 'outcome' => 'processing', 'retry_safe' => false, 'payment_pending' => true, 'payment_intent' => (string) $intent['id'], 'attempt_id' => (int) $intent['attempt_id'] )
				);
			}
		} else {
			$metadata['return_url'] = $this->same_site_return_url( $request->get_param( 'return_url' ) );
			$this->payment_dispatch_reached = true;
			$intent = DoughBoss_Payment::create_payment_intent( $amount, $currency, $metadata );
		}

		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		if ( 'stripe' === $gateway ) {
			return rest_ensure_response(
				array(
					'checkout_session' => $intent['checkout_session'],
					'checkout_url'     => $intent['checkout_url'],
					'amount'           => $intent['amount'],
					'currency'         => $intent['currency'],
					'gateway'          => 'stripe',
					'live_mode'        => 'live' === DoughBoss_Settings::stripe_mode(),
					'attempt_id'       => isset( $intent['attempt_id'] ) ? (int) $intent['attempt_id'] : 0,
				)
			);
		}

		if ( $square_hosted ) {
			// Only Square's own checkout URL and this checkout's opaque reference
			// reach the browser. 'confirm' means a payment already exists: the
			// browser must confirm through /checkout and never open a new page.
			return rest_ensure_response(
				array(
					'gateway'            => 'square',
					'hosted'             => true,
					'state'              => (string) $intent['state'],
					'checkout_url'       => (string) $intent['checkout_url'],
					'checkout_reference' => $checkout_key,
					'amount'             => $amount,
					'currency'           => strtolower( (string) $currency ),
					'live_mode'          => DoughBoss_Settings::square_live_mode(),
					'attempt_id'         => isset( $intent['attempt_id'] ) ? (int) $intent['attempt_id'] : 0,
				)
			);
		}

		if ( 'square' === $gateway ) {
			// No client secret exists for Square: the card was tokenised in the
			// browser and charged here. The browser receives only the payment id,
			// which /checkout re-verifies against Square before any order row.
			return rest_ensure_response(
				array(
					'payment_intent' => $intent['id'],
					'amount'         => $intent['amount'],
					'currency'       => $intent['currency'],
					'gateway'        => 'square',
					'live_mode'      => DoughBoss_Settings::square_live_mode(),
					'attempt_id'     => isset( $intent['attempt_id'] ) ? (int) $intent['attempt_id'] : 0,
				)
			);
		}

		return rest_ensure_response(
			array(
				'client_secret'   => $intent['client_secret'],
				'payment_intent'  => $intent['id'],
				'publishable_key' => DoughBoss_Payment::publishable_key(),
				'amount'          => $intent['amount'],
				'currency'        => $intent['currency'],
				'gateway'         => $gateway,
				'live_mode'       => ( 'tyro' === $gateway && DoughBoss_Settings::tyro_live_mode() ) || ( 'mpgs' === $gateway && DoughBoss_Settings::mpgs_live_mode() ),
				'attempt_id'      => isset( $intent['attempt_id'] ) ? (int) $intent['attempt_id'] : 0,
			)
		);
	}

	/**
	 * Build same-site Stripe success and cancellation return URLs.
	 *
	 * Stripe replaces the literal Checkout Session placeholder after payment.
	 * It must therefore be appended after WordPress has encoded the other query
	 * parameters.
	 *
	 * @param mixed $candidate Browser-provided current URL.
	 * @return array{success:string,cancel:string}
	 */
	private function stripe_checkout_return_urls( $candidate ) {
		$base = $this->same_site_return_url( $candidate );
		return array(
			// Keep Stripe's provider identifier out of the HTTP request target.
			// Some managed WordPress firewalls reject Checkout Session ids in
			// query strings before WordPress can render the confirmation page.
			// URL fragments remain browser-local and are still available to the
			// storefront JavaScript for the server-verified completion request.
			'success' => $base . '#doughboss_stripe_return=1&session_id={CHECKOUT_SESSION_ID}',
			'cancel'  => $base . '#doughboss_stripe_cancel=1',
		);
	}

	/**
	 * Keep Hosted Checkout returns on this WordPress site. Only the minimum
	 * WordPress routing query is retained for authenticated draft acceptance;
	 * arbitrary parameters, preview nonces and fragments are discarded.
	 *
	 * @param mixed $candidate Browser-provided current URL.
	 * @return string
	 */
	private function same_site_return_url( $candidate ) {
		$candidate = esc_url_raw( (string) $candidate );
		$home      = wp_parse_url( home_url( '/' ) );
		$parts     = wp_parse_url( $candidate );
		if ( ! is_array( $parts ) || ! is_array( $home ) || empty( $parts['host'] ) || empty( $home['host'] ) || strtolower( $parts['host'] ) !== strtolower( $home['host'] ) ) {
			return home_url( '/' );
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		if ( 'https' !== $scheme ) {
			return home_url( '/' );
		}
		$return_url = 'https://' . strtolower( $parts['host'] ) . ( isset( $parts['path'] ) ? $parts['path'] : '/' );
		if ( empty( $parts['query'] ) ) {
			return $return_url;
		}

		parse_str( $parts['query'], $query );
		$safe_query = array();
		foreach ( array( 'page_id', 'p' ) as $numeric_key ) {
			if ( isset( $query[ $numeric_key ] ) && is_scalar( $query[ $numeric_key ] ) && (int) $query[ $numeric_key ] > 0 ) {
				$safe_query[ $numeric_key ] = (int) $query[ $numeric_key ];
			}
		}
		if ( isset( $query['preview'] ) && 'true' === (string) $query['preview'] ) {
			$safe_query['preview'] = 'true';
		}
		return $safe_query ? add_query_arg( $safe_query, $return_url ) : $return_url;
	}

	/**
	 * Bind a browser retry key to an immutable server-owned payment snapshot.
	 *
	 * @return string|WP_Error SHA-256 key.
	 */
	private function payment_checkout_key( WP_REST_Request $request, $scope, array $snapshot ) {
		$client_key = sanitize_text_field( $request->get_param( 'payment_attempt_key' ) );
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,128}$/', $client_key ) ) {
			return new WP_Error( 'doughboss_pay_attempt_key', __( 'The payment session is missing. Please refresh and try again.', 'doughboss' ), array( 'status' => 400 ) );
		}
		return hash_hmac( 'sha256', sanitize_key( $scope ) . '|' . $client_key . '|' . wp_json_encode( $snapshot ), wp_salt( 'auth' ) );
	}

	/**
	 * Derive a stable Square attempt identity separately from its order binding.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $scope   Protocol scope.
	 * @return string|WP_Error
	 */
	private function payment_attempt_identity( WP_REST_Request $request, $scope ) {
		$client_key = sanitize_text_field( $request->get_param( 'payment_attempt_key' ) );
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,128}$/', $client_key ) ) {
			return new WP_Error( 'doughboss_pay_attempt_key', __( 'The payment session is missing. Please refresh and try again.', 'doughboss' ), array( 'status' => 400 ) );
		}
		return hash_hmac( 'sha256', sanitize_key( $scope ) . '|' . $this->cart->get_token() . '|' . $client_key, wp_salt( 'auth' ) );
	}

	/**
	 * Cart-session guard preventing a second Square nonce while money is unresolved.
	 *
	 * @return string SHA-256 HMAC.
	 */
	private function square_cart_guard() {
		return hash_hmac( 'sha256', 'square-cart-v2|' . $this->cart->get_token(), wp_salt( 'auth' ) );
	}

	/**
	 * Exact normalized customer/order facts that one Square attempt owns.
	 *
	 * Financial values remain server-owned in the snapshot and attempt row. This
	 * binding deliberately includes the raw cart voucher identity but not a later
	 * voucher evaluation, so a paid retry still matches after redemption.
	 *
	 * @return array
	 */
	private function square_binding_payload( $order_type, $location_id, $table_context, $name, $email, $phone, $address, $notes, array $pickup = array() ) {
		$binding = array(
			'protocol_version' => 'square-v2',
			'square_mode'      => DoughBoss_Settings::square_mode(),
			'order_type'       => sanitize_key( $order_type ),
			'location_id'      => absint( $location_id ),
			'table_id'         => $table_context ? (int) $table_context['table_id'] : 0,
			'qr_code_id'       => $table_context ? (int) $table_context['qr_code_id'] : 0,
			'table_session_id' => $table_context ? (int) $table_context['session_id'] : 0,
			'table_label'      => $table_context ? sanitize_text_field( (string) $table_context['table_label'] ) : '',
			'customer_name'    => sanitize_text_field( $name ),
			'customer_email'   => strtolower( sanitize_email( $email ) ),
			'customer_phone'   => sanitize_text_field( $phone ),
			'address'          => sanitize_textarea_field( $address ),
			'notes'            => sanitize_textarea_field( $notes ),
			'voucher_code'     => strtoupper( sanitize_text_field( $this->cart->get_voucher_code() ) ),
			'lines'            => array_values( $this->cart->get_lines() ),
		);
		// Only when a pickup time was chosen: omitting the keys otherwise keeps the
		// 2.50 binding bytes (and therefore in-flight checkout hashes) unchanged.
		if ( ! empty( $pickup['pickup_mode'] ) ) {
			$binding['pickup_mode']    = sanitize_key( $pickup['pickup_mode'] );
			$binding['pickup_slot_id'] = isset( $pickup['pickup_slot_id'] ) ? absint( $pickup['pickup_slot_id'] ) : 0;
		}
		return $binding;
	}

	/**
	 * Pickup selection for a checkout step, or an empty array when the shop does
	 * not schedule pickups (mode off/shadow, dine-in, delivery, or the
	 * foundation is not loaded).
	 *
	 * @param WP_REST_Request $request          Request carrying pickup_mode / capacity_hold_token.
	 * @param int             $location_id      Resolved shop.
	 * @param string          $order_type       pickup|delivery|dine_in.
	 * @param bool            $payment_required Whether an online payment backs this order.
	 * @return array|WP_Error
	 */
	private function pickup_selection_for_checkout( WP_REST_Request $request, $location_id, $order_type, $payment_required ) {
		if ( ! class_exists( 'DoughBoss_Pickup' ) || ! method_exists( 'DoughBoss_Pickup', 'prepare_checkout_selection' ) ) {
			return array();
		}
		$selection = DoughBoss_Pickup::prepare_checkout_selection(
			$this->cart,
			(int) $location_id,
			(string) $order_type,
			array(
				'pickup_mode'         => sanitize_key( (string) $request->get_param( 'pickup_mode' ) ),
				'capacity_hold_token' => sanitize_text_field( (string) $request->get_param( 'capacity_hold_token' ) ),
			),
			(bool) $payment_required
		);
		if ( is_wp_error( $selection ) ) {
			return $selection;
		}
		return is_array( $selection ) ? $selection : array();
	}

	/**
	 * Pickup facts that ride in the immutable snapshot's order payload.
	 *
	 * @param array $pickup      prepare_checkout_selection() result.
	 * @param int   $location_id Shop (for the label timezone).
	 * @return array
	 */
	private function pickup_snapshot_fields( array $pickup, $location_id ) {
		$out = array();
		foreach ( array( 'pickup_mode', 'pickup_slot_id', 'capacity_hold_token', 'pickup_ready_from_utc', 'pickup_ready_by_utc', 'pickup_label' ) as $key ) {
			if ( array_key_exists( $key, $pickup ) ) {
				$out[ $key ] = $pickup[ $key ];
			}
		}
		if ( empty( $out['pickup_mode'] ) ) {
			return array();
		}
		$loc = absint( $location_id ) ? DoughBoss_Locations::get( absint( $location_id ) ) : null;
		$out['pickup_timezone'] = $loc && ! empty( $loc->timezone ) ? (string) $loc->timezone : 'Australia/Sydney';
		return $out;
	}

	/**
	 * Add the chosen slot to the Stripe/other checkout-key binding, only when
	 * one exists so unscheduled keys stay byte-identical to 2.50.
	 *
	 * @param array $binding Existing binding array.
	 * @param array $pickup  Selection.
	 * @return array
	 */
	private function pickup_checkout_key_binding( array $binding, array $pickup ) {
		if ( ! empty( $pickup['pickup_mode'] ) ) {
			$binding['pickup_mode']    = sanitize_key( $pickup['pickup_mode'] );
			$binding['pickup_slot_id'] = isset( $pickup['pickup_slot_id'] ) ? absint( $pickup['pickup_slot_id'] ) : 0;
		}
		return $binding;
	}

	/**
	 * Pickup facts for a verified paid order that has no checkout snapshot
	 * (Tyro / MPGS). The payment already proved the request's pickup binding;
	 * the hold token rides along so DoughBoss_Order::create() can attach the
	 * slot (or keep the paid order untimed with a [SYSTEM] note). Empty when
	 * the shop does not schedule pickups or the request carries no selection.
	 *
	 * @param WP_REST_Request $request     Request.
	 * @param int             $location_id Resolved shop.
	 * @param string          $order_type  pickup|delivery|dine_in.
	 * @return array
	 */
	private function pickup_fields_from_paid_request( WP_REST_Request $request, $location_id, $order_type ) {
		if ( ! class_exists( 'DoughBoss_Pickup' ) || ! method_exists( 'DoughBoss_Pickup', 'requirement' ) ) {
			return array();
		}
		$requirement = DoughBoss_Pickup::requirement( (int) $location_id, (string) $order_type, false );
		if ( ! is_array( $requirement ) || empty( $requirement['required'] ) ) {
			return array();
		}
		$mode  = DoughBoss_Order::sanitize_pickup_mode( $request->get_param( 'pickup_mode' ) );
		$token = trim( (string) $request->get_param( 'capacity_hold_token' ) );
		if ( '' === $mode || ! preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
			return array();
		}
		$selection = $this->pickup_selection_for_checkout( $request, $location_id, $order_type, true );
		if ( is_wp_error( $selection ) || empty( $selection ) ) {
			// Money has moved: a lapsed or stale hold is the order writer's call.
			$selection = array(
				'pickup_mode'         => $mode,
				'capacity_hold_token' => $token,
				'pickup_slot_id'      => absint( $request->get_param( 'pickup_slot_id' ) ),
				'pickup_label'        => '',
			);
		}
		return $this->pickup_snapshot_fields( $selection, $location_id );
	}

	/**
	 * Pickup facts to copy from a paid snapshot's order payload into the order.
	 *
	 * @param array $saved Snapshot payload.order.
	 * @return array
	 */
	private function pickup_fields_from_snapshot( array $saved ) {
		if ( empty( $saved['pickup_mode'] ) ) {
			return array();
		}
		$out = array();
		foreach ( array( 'pickup_mode', 'pickup_slot_id', 'capacity_hold_token', 'pickup_ready_from_utc', 'pickup_ready_by_utc', 'pickup_label', 'pickup_timezone', 'square_orders_protocol', 'square_pickup_prep_minutes' ) as $key ) {
			if ( array_key_exists( $key, $saved ) ) {
				$out[ $key ] = $saved[ $key ];
			}
		}
		return $out;
	}

	/** Freeze the existing website preparation policy once, only for native pickup. */
	private function native_pickup_snapshot_order( array $order, $checkout_key, $location_id ) {
		$existing = DoughBoss_Checkout_Snapshots::find( $checkout_key );
		if ( ! is_array( $existing ) ) { $existing = DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( $checkout_key ); }
		if ( is_array( $existing ) && isset( $existing['payload']['order'] ) ) {
			// Never upgrade old snapshots. Replay exact retained pickup facts.
			foreach ( array( 'pickup_mode', 'pickup_slot_id', 'capacity_hold_token', 'pickup_ready_from_utc', 'pickup_ready_by_utc', 'pickup_label', 'pickup_timezone', 'square_orders_protocol', 'square_pickup_prep_minutes', 'square_api_version' ) as $key ) {
				if ( array_key_exists( $key, $existing['payload']['order'] ) ) {
					$order[ $key ] = $existing['payload']['order'][ $key ];
				} else { unset( $order[ $key ] ); }
			}
			return $order;
		}
		if ( 'pickup' === $order['order_type'] && ! empty( $order['pickup_mode'] ) ) {
			$options = DoughBoss_Order::capacity_lock_options( $location_id, true );
			if ( ! empty( $order['source_request_id'] ) ) {
				$source = DoughBoss_Order::get( (int) $order['source_request_id'] );
				if ( ! $source || 1 !== (int) ( isset( $source->preorder_workflow_version ) ? $source->preorder_workflow_version : 0 ) || empty( $source->capacity_hold_id ) || empty( $source->fire_at_utc )
					|| ! isset( $order['pickup_ready_from_utc'], $order['pickup_ready_by_utc'], $order['pickup_timezone'] )
					|| (string) $source->promised_ready_from_utc !== $order['pickup_ready_from_utc'] || (string) $source->promised_ready_by_utc !== $order['pickup_ready_by_utc'] || (string) $source->timezone_snapshot !== $order['pickup_timezone'] ) {
					return $order;
				}
				$options['prep_minutes'] = ( strtotime( $source->promised_ready_from_utc . ' UTC' ) - strtotime( $source->fire_at_utc . ' UTC' ) ) / 60;
			}
			$order['square_orders_protocol'] = 'square-orders-v2-pickup';
			$order['square_pickup_prep_minutes'] = max( 0, min( 1440, (int) $options['prep_minutes'] ) );
			$order['square_api_version'] = DoughBoss_Settings::square_api_version();
		}
		return $order;
	}

	/**
	 * Binding facts for recomputing a Square checkout hash from a later request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function pickup_binding_from_request( WP_REST_Request $request ) {
		$mode = sanitize_key( (string) $request->get_param( 'pickup_mode' ) );
		$slot = absint( $request->get_param( 'pickup_slot_id' ) );
		return '' !== $mode && $slot > 0 ? array( 'pickup_mode' => $mode, 'pickup_slot_id' => $slot ) : array();
	}

	/**
	 * Keyed digest of a normalized Square checkout binding.
	 *
	 * @param array $binding Normalized binding facts.
	 * @return string
	 */
	private function square_binding_hash( array $binding ) {
		return hash_hmac( 'sha256', wp_json_encode( $binding ), wp_salt( 'auth' ) );
	}

	/**
	 * Read the accounting facts captured by the cart calculation.
	 *
	 * @param array $totals Server-calculated cart totals.
	 * @return array|WP_Error
	 */
	private function accounting_basis_from_totals( array $totals ) {
		if ( ! array_key_exists( 'accounting_basis', $totals ) ) {
			return new WP_Error( 'doughboss_accounting_basis_missing', __( 'The order accounting details could not be recorded. Please refresh and try again.', 'doughboss' ), array( 'status' => 503 ) );
		}
		return DoughBoss_Order::normalize_accounting_basis( $totals['accounting_basis'] );
	}

	/**
	 * Copy a recorded basis from an immutable snapshot order payload.
	 *
	 * @param array $source_order Immutable source order payload.
	 * @param array $order_data   Destination payload.
	 * @return true|WP_Error
	 */
	private function copy_accounting_basis_from_snapshot( array $source_order, array &$order_data ) {
		$basis = DoughBoss_Order::accounting_basis_from_payload( $source_order );
		if ( is_wp_error( $basis ) ) {
			return $basis;
		}
		if ( null === $basis ) {
			unset( $order_data['accounting_basis'] );
		} else {
			// Replace in place: reinserting a key changes immutable JSON/hash bytes.
			$order_data['accounting_basis'] = $basis;
		}
		return true;
	}

	/**
	 * Reuse only the recorded accounting facts when a protected checkout retry
	 * already owns this payment identity. The snapshot store still performs the
	 * exact payload-hash comparison for every other immutable fact.
	 *
	 * @param string $checkout_key Checkout identity.
	 * @param array  $payload Candidate immutable payload.
	 * @return array|WP_Error
	 */
	private function store_ordinary_payment_snapshot( $checkout_key, array $payload ) {
		$existing = DoughBoss_Checkout_Snapshots::find( $checkout_key );
		if ( ! is_array( $existing ) ) {
			$existing = DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( $checkout_key );
		}
		if ( is_array( $existing ) ) {
			if ( ! isset( $existing['payload']['order'] ) || ! is_array( $existing['payload']['order'] ) || ! isset( $payload['order'] ) || ! is_array( $payload['order'] ) ) {
				return new WP_Error( 'doughboss_pay_snapshot_missing', __( 'The paid order details require manual confirmation. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			$copied = $this->copy_accounting_basis_from_snapshot( $existing['payload']['order'], $payload['order'] );
			if ( is_wp_error( $copied ) ) {
				return $copied;
			}
		}
		return DoughBoss_Checkout_Snapshots::store( $checkout_key, $payload );
	}

	/** @return bool Whether a value is a canonical SHA-256 fingerprint. */
	private function valid_fingerprint( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value );
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
		$response = rest_ensure_response( $out );
		// Pickup-hour status expires within a minute; do not allow a store or CDN
		// response cache to outlive that schedule-only observation.
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}

	/**
	 * GET /table/context — safe display details for a scanned table session.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_table_context() {
		$context = $this->table_order_context();
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( ! $context ) {
			$response = rest_ensure_response( array( 'active' => false ) );
			$response->header( 'Cache-Control', 'no-store, private' );
			$response->header( 'Vary', 'Cookie' );
			return $response;
		}
		$response = rest_ensure_response(
			array(
				'active'     => true,
				'order_type' => 'dine_in',
				'location'   => array(
					'id'   => (int) $context['location_id'],
					'name' => $context['location_name'],
				),
				'table'      => array(
					'id'    => (int) $context['table_id'],
					'label' => $context['table_label'],
				),
				'expires_at' => $context['expires_at'],
			)
		);
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Vary', 'Cookie' );
		return $response;
	}

	/**
	 * GET /menu — published menu items grouped by category.
	 *
	 * @return WP_REST_Response
	 */
	public function get_menu( WP_REST_Request $request = null ) {
		$location_id = null;
		if ( $request && null !== $request->get_param( 'location_id' ) ) {
			$location_id = $this->resolve_menu_location( $request->get_param( 'location_id' ) );
			if ( is_wp_error( $location_id ) ) { return $location_id; }
		}
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
			$image = $thumb ? $thumb : $this->menu_image_url( $post->post_title, $category );
			$items[]    = array(
				'id'          => $post->ID,
				// Raw title, not get_the_title() — this is a JSON API field the
				// front-end inserts via textContent (XSS-safe already), so an
				// HTML-entity-encoded title (e.g. "Chicken &#038; Cheese") would
				// render literally instead of decoding to "&".
				// plain_menu_title() decodes a kses "&amp;" once (plus a stranded
				// double-escaped "&amp;" conjunction) so textContent shows "&".
				'name'        => DoughBoss_Post_Types::plain_menu_title( $post->post_title ),
				'description' => DoughBoss_Post_Types::plain_menu_description( $post->post_excerpt ? $post->post_excerpt : $post->post_content ),
				'price'       => (float) get_post_meta( $post->ID, DoughBoss_Post_Types::META_PRICE, true ),
				'type'        => get_post_meta( $post->ID, DoughBoss_Post_Types::META_TYPE, true ),
				'image'       => $image,
				'image_responsive' => DoughBoss_Images::responsive_for_url( $image ),
				'category'    => $category,
				'available'   => null === $location_id ? DoughBoss_Post_Types::is_available( $post->ID ) : DoughBoss_Post_Types::is_available_at( $post->ID, $location_id ),
				'dietary'     => DoughBoss_Post_Types::dietary( $post->ID ),
				'options'     => DoughBoss_Menu_Options::for_item( $category, $post->post_title ),
			);
		}

		$response = rest_ensure_response( $items );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}

	/**
	 * Use the approved, local demo photography when a menu item has not yet
	 * received a WordPress featured image. A featured image always wins, so
	 * management can replace these per item without a code release.
	 *
	 * @param string $name     Menu item name.
	 * @param string $category Menu category name.
	 * @return string
	 */
	private function menu_image_url( $name, $category ) {
		$images = array(
			'cheese-tomato-olives'     => 'generated-v1/cheese-tomato-olives.webp',
			'half-meat-cheese'         => 'generated-v1/half-meat-cheese.webp',
			'labneh-veggie-pizza'      => 'generated-v1/labneh-veggie-pizza.webp',
			'sujuk-special'           => 'generated-v1/sujuk-special.webp',
			'zaatar-veggie-pizza'      => 'generated-v1/zaatar-veggie-pizza.webp',
			'coke-600ml'              => 'brand-v1/coca-cola-classic-600ml.jpg',
			'coke-zero-600ml'         => 'brand-v1/coca-cola-zero-600ml.png',
			'coke-vanilla-600ml'      => 'brand-v1/coca-cola-vanilla-600ml.jpg',
			'sprite-600ml'            => 'brand-v1/sprite-600ml.jpg',
			'fanta-600ml'             => 'brand-v1/fanta-orange-600ml.jpg',
			'apple-juice'             => 'brand-v1/juice-world-apple-500ml.jpg',
			'lemon-juice'             => 'brand-v1/juice-world-lemon-500ml.jpg',
			'lemon-mint-juice'        => 'brand-v1/juice-world-lemon-mint-500ml.jpg',
			'orange-juice'            => 'brand-v1/juice-world-orange-500ml.jpg',
			'orange-mango-juice'      => 'brand-v1/juice-world-orange-mango-500ml.jpg',
			'orange-passion-juice'    => 'brand-v1/juice-world-orange-passionfruit-500ml.jpg',
			'zaatar'                  => 'real-v1/zaatar.jpg',
			'zaatar-cheese'           => 'real-v1/zaatar-cheese.jpg',
			'cheese'                  => 'real-v1/cheese.jpg',
			'meat'                    => 'real-v1/meat.jpg',
			'meat-cheese'             => 'real-v1/meat-cheese.jpg',
			'sujuk-cheese'            => 'real-v1/sujuk-cheese.jpg',
			'cheese-kaak'             => 'real-v1/cheese-kaak.jpg',
			'all-meat'                => 'generated-v1/all-meat.webp',
			'sujuk-deluxe'            => 'real-v1/sujuk-deluxe.jpg',
			'spinach-deluxe'          => 'real-v1/spinach-deluxe.jpg',
			'veggie-plus'             => 'real-v1/veggie-plus.jpg',
			'pepperoni-cheese'        => 'real-v1/pepperoni-cheese.jpg',
			'dough-boss-special'      => 'dough-boss-special.webp',
			'chicken-cheese'          => 'real-v1/chicken-cheese.jpg',
			'bbq-chicken'             => 'real-v1/bbq-chicken.jpg',
			'peri-peri-chicken'       => 'real-v1/peri-peri-chicken.jpg',
			'garlic-prawns'           => 'generated-v1/garlic-prawns.webp',
			'spinach-pie'             => 'real-v1/spinach-pie.jpg',
			'haloumi'                 => 'real-v1/haloumi-pie.jpg',
			'halloumi'                => 'real-v1/haloumi-pie.jpg',
			'dough-boss-pie'          => 'real-v1/dough-boss-pie.jpg',
			'aged-cheese'             => 'real-v1/aged-cheese-pie.jpg',
			'zaatar-veggie'           => 'real-v1/zaatar-veggie-wrap.jpg',
			'labneh-veggie-wrap'      => 'real-v1/labneh-veggie-wrap.jpg',
			'chicken-delight'         => 'real-v1/chicken-delight-wrap.jpg',
			'ultimate-chicken'        => 'generated-v1/ultimate-chicken.webp',
			'dough-boss-wrap'         => 'generated-v1/dough-boss-wrap.webp',
			'choco-banana'            => 'real-v1/choco-banana.jpg',
			'spring-water'            => 'real-v1/spring-water.jpg',
			'soft-drinks-600ml'       => 'soft-drinks.webp',
			'soft-drinks'             => 'soft-drinks.webp',
			'juice'                   => 'real-v1/juice.jpg',
		);
		$key      = sanitize_title( $name );
		$file     = isset( $images[ $key ] ) ? $images[ $key ] : '';
		$encoded_file = $file ? implode( '/', array_map( 'rawurlencode', explode( '/', $file ) ) ) : '';
		return $encoded_file ? DOUGHBOSS_PLUGIN_URL . 'public/images/menu/' . $encoded_file : '';
	}

	/**
	 * GET /cart — current cart contents.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_cart( WP_REST_Request $request ) {
		$table_context = $this->table_order_context();
		if ( is_wp_error( $table_context ) ) {
			return $table_context;
		}
		if ( $table_context ) {
			$order_type = 'dine_in';
		} else {
			$order_type = sanitize_key( $request->get_param( 'order_type' ) );
			$order_type = ( 'delivery' === $order_type ) ? 'delivery' : 'pickup';
			$single_location_id = DoughBoss_Locations::single_location_id();
			if ( $single_location_id ) {
				$order_type = 'pickup';
			}
		}
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
			return new WP_Error( 'doughboss_sold_out', sprintf( __( 'Sorry, %s is sold out right now.', 'doughboss' ), $post->post_title ), array( 'status' => 409 ) );
		}
		if ( null !== $request->get_param( 'location_id' ) ) {
			$location_id = $this->resolve_menu_location( $request->get_param( 'location_id' ) );
			if ( is_wp_error( $location_id ) ) { return $location_id; }
			$availability_error = $this->menu_lines_availability_error( array( array( 'type' => 'menu', 'item_id' => $item_id ) ), $location_id );
			if ( is_wp_error( $availability_error ) ) { return $availability_error; }
		}

		$price = (float) get_post_meta( $item_id, DoughBoss_Post_Types::META_PRICE, true );
		$terms = get_the_terms( $item_id, DoughBoss_Post_Types::TAXONOMY );
		$category = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';
		$groups   = DoughBoss_Menu_Options::for_item( $category, $post->post_title );
		$resolved = DoughBoss_Menu_Options::resolve( $groups, $request->get_param( 'options' ) );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		return array(
			'type'       => 'menu',
			'item_id'    => $item_id,
			// Raw title — see the /menu handler's comment above for why.
			'name'       => $post->post_title,
			'size'       => '',
			'toppings'   => $resolved['modifiers'],
			'unit_price' => round( $price + $resolved['delta'], 2 ),
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
	 * POST /cart/revalidate — remove menu lines the chosen shop does not sell.
	 *
	 * Reuses the checkout availability rule (published menu item available at
	 * the shop). Custom lines are kept. Never changes prices or quantities.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cart_revalidate( WP_REST_Request $request ) {
		$location = $this->resolve_menu_location( $request->get_param( 'location_id' ) );
		if ( is_wp_error( $location ) ) {
			return $location;
		}
		$removed = array();
		foreach ( $this->cart->get_lines() as $line ) {
			if ( ! is_array( $line ) || ! isset( $line['key'] ) ) {
				continue;
			}
			$type = isset( $line['type'] ) ? $line['type'] : ( isset( $line['item_id'] ) && (int) $line['item_id'] > 0 ? 'menu' : 'custom' );
			if ( 'menu' !== $type ) {
				continue;
			}
			$item_id = isset( $line['item_id'] ) ? $this->canonical_native_location_id( $line['item_id'] ) : false;
			$post    = false !== $item_id ? get_post( $item_id ) : null;
			if ( ! $post || DoughBoss_Post_Types::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status
				|| ! DoughBoss_Post_Types::is_available_at( $post->ID, $location ) ) {
				if ( $this->cart->remove( $line['key'] ) ) {
					$removed[] = isset( $line['name'] ) ? (string) $line['name'] : '';
				}
			}
		}
		return rest_ensure_response(
			array(
				'cart'    => $this->cart->to_array( 'pickup' ),
				'removed' => array_values( array_filter( $removed, 'strlen' ) ),
			)
		);
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

		// Persist the canonical database code, not the user's recoverable spelling,
		// so payment snapshots, orders and redemption audit all share one identity.
		$this->cart->set_voucher_code( (string) $row->code );
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
	 * POST /preorder-request — collect an unpaid, unconfirmed Revesby request.
	 *
	 * This route is deliberately unavailable while normal ordering is open, and
	 * deliberately does not call the payment, capacity or voucher-redemption
	 * paths. A staff decision is required before the row changes from the
	 * preorder_request channel to a normal web order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preorder_request( WP_REST_Request $request ) {
		foreach ( array( 'preorder_workflow_version', 'capacity_hold_id', 'capacity_hold_token', 'pickup_mode', 'ready_from_utc', 'pickup_slot_id' ) as $forbidden ) {
			if ( null !== $request->get_param( $forbidden ) ) {
				return new WP_Error( 'doughboss_preorder_marker_forbidden', __( 'Pickup timing is selected by staff after customer confirmation.', 'doughboss' ), array( 'status' => 400 ) );
			}
		}
		$idem = $this->idempotency_key( $request );
		if ( is_wp_error( $idem ) ) {
			return $idem;
		}
		if ( version_compare( (string) get_option( 'doughboss_db_version', '0' ), '1.25.0', '<' ) || ! DoughBoss_Activator::checkout_storage_ready() ) {
			return new WP_Error( 'doughboss_checkout_storage_unavailable', __( 'Pre-order requests are temporarily unavailable while order storage is upgraded.', 'doughboss' ), array( 'status' => 503 ) );
		}
		if ( $this->rate_limited( 'preorder_request', 4, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'doughboss_rate_limit', __( 'Too many requests. Please wait a few minutes and try again.', 'doughboss' ), array( 'status' => 429 ) );
		}

		$existing_id = DoughBoss_Order::find_id_by_checkout_key( $idem );
		if ( $existing_id ) {
			$existing = DoughBoss_Order::get( $existing_id );
			if ( $existing && DoughBoss_Order::is_preorder_request( $existing ) ) {
				$approval = DoughBoss_Preorder_Approval::ensure_pending( (int) $existing->id );
				if ( is_wp_error( $approval ) ) {
					return $approval;
				}
				if ( ! empty( $approval['created'] ) ) {
					$this->send_preorder_request_notification( $existing );
					do_action( 'doughboss_preorder_request_created', (int) $existing->id );
				}
				$existing_policy = DoughBoss_Settings::staff_approval_policy();
				return rest_ensure_response( $this->preorder_payload( $existing, true, 'required' === (string) $existing_policy['mode'] ) );
			}
			return new WP_Error( 'doughboss_preorder_conflict', __( 'This request key has already been used for another order. Refresh and try again.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$policy = DoughBoss_Settings::staff_approval_policy();
		$staff_approval_required = 'required' === (string) $policy['mode'];
		if ( $staff_approval_required ) {
			if ( ! $this->staff_approval_requests_enabled( $policy ) ) {
				return new WP_Error( 'doughboss_preorder_unavailable', __( 'Pre-order requests are not available right now.', 'doughboss' ), array( 'status' => 503 ) );
			}
		} elseif ( 'legacy' !== (string) $policy['mode'] || ! empty( $policy['ordering_open'] ) || empty( $policy['after_hours'] ) ) {
			return new WP_Error( 'doughboss_preorder_unavailable', __( 'Pre-order requests are not available right now.', 'doughboss' ), array( 'status' => 503 ) );
		}

		if ( $this->cart->is_empty() ) {
			return new WP_Error( 'doughboss_empty', __( 'Your cart is empty.', 'doughboss' ), array( 'status' => 400 ) );
		}
		if ( DoughBoss_Table_QR::has_session_cookie() ) {
			return new WP_Error( 'doughboss_preorder_table_unavailable', __( 'Table orders need to be placed while the shop is accepting orders.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( '' !== $this->cart->get_voucher_code() ) {
			return new WP_Error( 'doughboss_preorder_voucher_unavailable', __( 'Vouchers can be applied after Revesby confirms your pre-order request.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$name  = sanitize_text_field( $request->get_param( 'customer_name' ) );
		$email = sanitize_email( $request->get_param( 'customer_email' ) );
		$phone = sanitize_text_field( $request->get_param( 'customer_phone' ) );
		$notes = sanitize_textarea_field( $request->get_param( 'notes' ) );
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
		if ( $errors ) {
			return new WP_Error( 'doughboss_invalid', implode( ' ', $errors ), array( 'status' => 400 ) );
		}

		$location_id = $staff_approval_required
			? $this->resolve_staff_approval_request_location( $request->get_param( 'location_id' ) )
			: $this->resolve_revesby_preorder_location( $request->get_param( 'location_id' ) );
		if ( is_wp_error( $location_id ) ) {
			return $location_id;
		}
		$availability_error = $this->menu_lines_availability_error( $this->cart->get_lines(), $location_id );
		if ( is_wp_error( $availability_error ) ) { return $availability_error; }
		$totals  = $this->cart->totals( 'pickup' );
		$accounting_basis = $this->accounting_basis_from_totals( $totals );
		if ( is_wp_error( $accounting_basis ) ) {
			return $accounting_basis;
		}
		// Re-read the authoritative policy immediately before durable creation.
		$routing_error = $this->large_order_routing_error( $totals, $location_id, DoughBoss_Settings::get( 'currency_code', 'AUD' ) );
		if ( is_wp_error( $routing_error ) ) { return $routing_error; }
		// A shop change must be explicitly selected and revalidated, never inferred.
		// The order layer owns the final transactional authority check.
		if ( $staff_approval_required && ! $this->staff_approval_requests_enabled( DoughBoss_Settings::staff_approval_policy() ) ) {
			return new WP_Error( 'doughboss_preorder_unavailable', __( 'Pre-order requests are not available right now.', 'doughboss' ), array( 'status' => 503 ) );
		}
		$created = DoughBoss_Order::create(
			array(
				'order_type'        => 'pickup',
				'location_id'       => $location_id,
				'order_source'      => 'preorder_request',
				'customer_name'     => $name,
				'customer_email'    => $email,
				'customer_phone'    => $phone,
				'address'           => '',
				'notes'             => $notes,
				'subtotal'          => $totals['subtotal'],
				'tax'               => $totals['tax'],
				'delivery_fee'      => 0,
				'total'             => $totals['total'],
				'discount'          => 0,
				'voucher_code'      => '',
				'payment_status'    => 'unpaid',
				'payment_method'    => '',
				'payment_intent_id' => '',
				'checkout_key'      => $idem,
				'accounting_basis'   => $accounting_basis,
			),
			$this->cart->get_lines()
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$order = DoughBoss_Order::get( (int) $created['order_id'] );
		if ( ! $order ) {
			return new WP_Error( 'doughboss_order_replay_missing', __( 'The saved pre-order request could not be loaded. Please contact Revesby before trying again.', 'doughboss' ), array( 'status' => 500 ) );
		}
		$approval = DoughBoss_Preorder_Approval::ensure_pending( (int) $order->id );
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}
		$replayed = ! empty( $created['replayed'] );
		$this->cart->clear();
		if ( ! $replayed ) {
			$this->send_preorder_request_notification( $order );
			do_action( 'doughboss_preorder_request_created', (int) $order->id );
		}

		return rest_ensure_response( $this->preorder_payload( $order, $replayed, $staff_approval_required ) );
	}

	/**
	 * Build the stable, deliberately non-confirming customer response.
	 *
	 * @param object $order Order row.
	 * @param bool   $replayed Whether this is an idempotent replay.
	 * @return array
	 */
	private function preorder_payload( $order, $replayed, $staff_approval_required = false ) {
		return array(
			'success'          => true,
			'order_number'     => $order->order_number,
			'total'            => (float) $order->total,
			'replayed'         => (bool) $replayed,
			'preorder_request' => true,
			'confirmed'        => false,
			'payment_required' => false,
			'payment_status'   => 'unpaid',
			'timing'           => array(
				'mode'             => 'phone',
				'label'            => $staff_approval_required
					? __( 'Our team will review this pickup request before payment is made available.', 'doughboss' )
					: __( 'Pickup timing will be arranged by phone before Revesby confirms this request.', 'doughboss' ),
				'contact_required' => true,
			),
			'message'          => $staff_approval_required
				? __( 'Your pickup request is awaiting review. Payment is not due until it is approved.', 'doughboss' )
				: DoughBoss_Settings::after_hours_preorders_message(),
		);
	}

	/**
	 * Read an approved request without creating payment or fulfilment work.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preorder_payment_view( WP_REST_Request $request ) {
		if ( $this->rate_limited( 'preorder_payment_view', 30, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'doughboss_rate_limit', __( 'Too many requests. Please wait a few minutes and try again.', 'doughboss' ), array( 'status' => 429 ) );
		}
		$request_id = absint( $request->get_param( 'request_id' ) );
		$token      = sanitize_text_field( $request->get_param( 'token' ) );
		// Expiry prevents a new charge, not confirmation of an exact linked
		// Square attempt that may already have taken money.
		$approval   = DoughBoss_Preorder_Approval::verify_payment_token( $request_id, $token, true, 'square' === DoughBoss_Settings::payment_gateway() );
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}
		$order = DoughBoss_Order::get( $request_id );
		if ( ! $order || 'preorder_request' !== (string) $order->order_source ) {
			return new WP_Error( 'doughboss_preorder_not_found', __( 'That pre-order request could not be found.', 'doughboss' ), array( 'status' => 404 ) );
		}
		if ( 'square' === DoughBoss_Settings::payment_gateway() && 'released' !== (string) $approval['state'] ) {
			$fingerprint = DoughBoss_Preorder_Approval::fingerprint_request( $request_id );
			if ( is_wp_error( $fingerprint ) || ! $this->valid_fingerprint( $fingerprint ) || ! isset( $approval['request_fingerprint'] ) || ! $this->valid_fingerprint( $approval['request_fingerprint'] ) || (int) $order->version !== (int) $approval['approved_revision'] || ! hash_equals( $approval['request_fingerprint'], $fingerprint ) ) {
				return new WP_Error( 'doughboss_preorder_payment_changed', __( 'This approved request changed and requires staff review. Please do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
			}
		}
		$payload = $this->preorder_payment_payload( $order, $approval );
		if ( is_wp_error( $this->approved_payment_policy_error() ) ) {
			$payload['payment_ready'] = false;
			$payload['can_start_payment'] = false;
			$payload['payable'] = false;
			$payload['presentation_state'] = 'unavailable';
			$payload['square'] = null;
			$payload['message'] = __( 'Approved payment is temporarily unavailable while staff approval is configured. Please contact the shop and do not try another payment link.', 'doughboss' );
		}
		return rest_ensure_response( $payload );
	}

	/**
	 * Create or replay the one Stripe Session owned by an approved request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preorder_payment_start( WP_REST_Request $request ) {
		if (
			version_compare( (string) get_option( 'doughboss_db_version', '0' ), '1.25.0', '<' )
			|| ! DoughBoss_Activator::checkout_storage_ready()
			|| ! DoughBoss_Activator::payment_storage_ready()
		) {
			return new WP_Error( 'doughboss_preorder_payment_storage', __( 'Approved payments are paused while secure order storage is upgraded.', 'doughboss' ), array( 'status' => 503 ) );
		}
		$policy_error = $this->approved_payment_policy_error();
		if ( is_wp_error( $policy_error ) ) {
			return $policy_error;
		}
		if ( 'square' === DoughBoss_Settings::payment_gateway() ) {
			return $this->preorder_payment_start_square( $request );
		}
		if ( 'stripe' !== DoughBoss_Settings::payment_gateway() || ! DoughBoss_Payment::ready() ) {
			return new WP_Error( 'doughboss_pay_off', __( 'Stripe payment is not available right now. Please contact the shop and do not try another payment link.', 'doughboss' ), array( 'status' => 503 ) );
		}
		if ( $this->rate_limited( 'preorder_payment_start', 8, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'doughboss_rate_limit', __( 'Too many requests. Please wait a few minutes and try again.', 'doughboss' ), array( 'status' => 429 ) );
		}

		$request_id = absint( $request->get_param( 'request_id' ) );
		$token      = sanitize_text_field( $request->get_param( 'token' ) );
		$verified_approval = DoughBoss_Preorder_Approval::verify_payment_token( $request_id, $token, true );
		if ( is_wp_error( $verified_approval ) ) {
			return $verified_approval;
		}
		$order = DoughBoss_Order::get( $request_id );
		$lines = $order ? DoughBoss_Order::get_items( $request_id ) : array();
		if ( ! $order || empty( $lines ) || 'preorder_request' !== (string) $order->order_source || 'unpaid' !== (string) $order->payment_status ) {
			DoughBoss_Preorder_Approval::hold_payment( $request_id );
			return new WP_Error( 'doughboss_preorder_payment_changed', __( 'This approved request changed and requires staff review. Please do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$accounting_basis = DoughBoss_Order::accounting_basis_from_order( $order );
		if ( is_wp_error( $accounting_basis ) ) {
			DoughBoss_Preorder_Approval::hold_payment( $request_id );
			return new WP_Error( 'doughboss_preorder_payment_changed', __( 'This approved request changed and requires staff review. Please do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$payment_location = DoughBoss_Locations::online_payment_location( (int) $order->location_id );
		if ( is_wp_error( $payment_location ) ) {
			return $payment_location;
		}
		$availability_error = $this->payment_availability_error( $lines, (int) $order->location_id, (string) $verified_approval['payment_checkout_key'], array( 'total' => $order->total, 'delivery_fee' => $order->delivery_fee ), (string) $order->currency );
		if ( is_wp_error( $availability_error ) ) { return $availability_error; }
		$approval = DoughBoss_Preorder_Approval::claim_payment( $request_id, $token );
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}

		$checkout_key = isset( $approval['payment_checkout_key'] ) ? (string) $approval['payment_checkout_key'] : '';
		$fingerprint  = DoughBoss_Preorder_Approval::fingerprint_request( $request_id );
		if (
			1 !== preg_match( '/^[a-f0-9]{64}$/', $checkout_key )
			|| is_wp_error( $fingerprint )
			|| ! $this->valid_fingerprint( $fingerprint )
			|| ! isset( $approval['request_fingerprint'] )
			|| ! $this->valid_fingerprint( $approval['request_fingerprint'] )
			|| ! hash_equals( $approval['request_fingerprint'], $fingerprint )
		) {
			DoughBoss_Preorder_Approval::hold_payment( $request_id );
			return new WP_Error( 'doughboss_preorder_payment_changed', __( 'This approved request changed and requires staff review. Please do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$currency = strtoupper( (string) $order->currency );
		$order_data = array(
			'order_type'        => (string) $order->order_type,
			'location_id'       => (int) $order->location_id,
			'table_id'          => 0,
			'table_label'       => '',
			'table_qr_code_id'  => 0,
			'table_session_id'  => 0,
			'order_source'      => 'web',
			'source_request_id' => $request_id,
			'customer_name'     => (string) $order->customer_name,
			'customer_email'    => (string) $order->customer_email,
			'customer_phone'    => (string) $order->customer_phone,
			'address'           => (string) $order->address,
			'notes'             => (string) $order->notes,
			'subtotal'          => (float) $order->subtotal,
			'tax'               => (float) $order->tax,
			'delivery_fee'      => (float) $order->delivery_fee,
			'total'             => (float) $order->total,
			'discount'          => 0.0,
			'voucher_code'      => '',
			'currency'          => $currency,
			'checkout_key'      => $checkout_key,
		);
		if ( null !== $accounting_basis ) {
			$order_data['accounting_basis'] = $accounting_basis;
		}
		$snapshot = DoughBoss_Checkout_Snapshots::store(
			$checkout_key,
			array(
				'order'    => $order_data,
				'lines'    => array_values( $lines ),
				'approval' => array(
					'request_id'          => $request_id,
					'approved_revision'   => isset( $approval['approved_revision'] ) ? (int) $approval['approved_revision'] : 0,
					'request_fingerprint' => (string) $approval['request_fingerprint'],
				),
			),
			isset( $approval['payment_token_expires_at_utc'] ) ? (string) $approval['payment_token_expires_at_utc'] : ''
		);
		if ( is_wp_error( $snapshot ) ) {
			DoughBoss_Preorder_Approval::hold_payment( $request_id );
			return $snapshot;
		}

		$return_urls = $this->preorder_payment_return_urls( $request_id, $token );
		$intent = DoughBoss_Stripe::create_checkout_session(
			DoughBoss_Payment::to_minor_units( (float) $order->total ),
			$currency,
			array(
				'checkout_key'        => $checkout_key,
				'purpose'             => 'order',
				'context'             => 'approved_preorder',
				'order_type'          => (string) $order->order_type,
				'location_id'         => (int) $order->location_id,
				'table_id'            => 0,
				'qr_code_id'          => 0,
				'source_request_id'   => $request_id,
				'approved_revision'   => isset( $approval['approved_revision'] ) ? (int) $approval['approved_revision'] : 0,
				'request_fingerprint' => (string) $approval['request_fingerprint'],
				'site'                => home_url(),
			),
			$return_urls['success'],
			$return_urls['cancel'],
			(string) $order->customer_email,
			isset( $approval['payment_token_expires_at_utc'] ) ? strtotime( (string) $approval['payment_token_expires_at_utc'] . ' UTC' ) : 0
		);
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}
		$linked = DoughBoss_Preorder_Approval::link_payment_attempt( $request_id, isset( $intent['attempt_id'] ) ? (int) $intent['attempt_id'] : 0 );
		if ( is_wp_error( $linked ) ) {
			return new WP_Error( 'doughboss_preorder_payment_link_pending', __( 'Your Stripe session is being linked. Wait a moment and retry this same payment link; do not start another payment.', 'doughboss' ), array( 'status' => 503, 'payment_pending' => true ) );
		}
		return rest_ensure_response(
			array(
				'checkout_session' => $intent['checkout_session'],
				'checkout_url'     => $intent['checkout_url'],
				'amount'           => $intent['amount'],
				'currency'         => $intent['currency'],
				'gateway'          => 'stripe',
				'live_mode'        => 'live' === DoughBoss_Settings::stripe_mode(),
			)
		);
	}

	/**
	 * Start the approved-request Square path. All checkout facts come from the
	 * approved source record; browser input supplies SDK tokenization material
	 * only and is never retained in the durable attempt metadata.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private function preorder_payment_start_square( WP_REST_Request $request ) {
		$policy_error = $this->approved_payment_policy_error();
		if ( is_wp_error( $policy_error ) ) {
			return $policy_error;
		}
		$tender_kind = DoughBoss_Square::tender_kind( $request->get_param( 'tender_kind' ) );
		if ( is_wp_error( $tender_kind ) ) { return $tender_kind; }
		if ( $this->rate_limited( 'preorder_payment_start', 8, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'doughboss_rate_limit', __( 'Too many requests. Please wait a few minutes and try again.', 'doughboss' ), array( 'status' => 429 ) );
		}
		$request_id = absint( $request->get_param( 'request_id' ) );
		$token      = sanitize_text_field( $request->get_param( 'token' ) );
		$approval   = DoughBoss_Preorder_Approval::verify_payment_token( $request_id, $token, true );
		$order      = $request_id ? DoughBoss_Order::get( $request_id ) : null;
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}
		if ( ! $order || 'preorder_request' !== (string) $order->order_source || 'unpaid' !== (string) $order->payment_status ) {
			DoughBoss_Preorder_Approval::hold_payment( $request_id );
			return new WP_Error( 'doughboss_preorder_payment_changed', __( 'This approved request changed and requires staff review. Please do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$accounting_basis = DoughBoss_Order::accounting_basis_from_order( $order );
		if ( is_wp_error( $accounting_basis ) ) {
			DoughBoss_Preorder_Approval::hold_payment( $request_id );
			return new WP_Error( 'doughboss_preorder_payment_changed', __( 'This approved request changed and requires staff review. Please do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$payment_location = DoughBoss_Locations::online_payment_location( (int) $order->location_id );
		$binding          = DoughBoss_Square::shop_binding( (int) $order->location_id );
		if ( is_wp_error( $payment_location ) ) {
			return $payment_location;
		}
		if ( is_wp_error( $binding ) ) {
			return $binding;
		}
		if ( ! DoughBoss_Square::ready_for_location( (int) $order->location_id ) ) {
			return new WP_Error( 'doughboss_pay_off', __( 'Card payment is not available right now. Please contact the shop and do not try another payment link.', 'doughboss' ), array( 'status' => 503 ) );
		}
		$availability_error = $this->payment_availability_error( DoughBoss_Order::get_items( $request_id ), (int) $order->location_id, (string) $approval['payment_checkout_key'], array( 'total' => $order->total, 'delivery_fee' => $order->delivery_fee ), (string) $order->currency );
		if ( is_wp_error( $availability_error ) ) { return $availability_error; }
		$approval = DoughBoss_Preorder_Approval::claim_payment( $request_id, $token, true );
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}
		$checkout_key = isset( $approval['payment_checkout_key'] ) ? (string) $approval['payment_checkout_key'] : '';
		$fingerprint  = DoughBoss_Preorder_Approval::fingerprint_request( $request_id );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $checkout_key ) || is_wp_error( $fingerprint ) || ! $this->valid_fingerprint( $fingerprint ) || ! isset( $approval['request_fingerprint'] ) || ! $this->valid_fingerprint( $approval['request_fingerprint'] ) || ! hash_equals( $approval['request_fingerprint'], $fingerprint ) ) {
			DoughBoss_Preorder_Approval::hold_payment( $request_id );
			return new WP_Error( 'doughboss_preorder_payment_changed', __( 'This approved request changed and requires staff review. Please do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$client_key = sanitize_text_field( (string) $request->get_param( 'payment_attempt_key' ) );
		if ( '' === $client_key || strlen( $client_key ) > 191 ) {
			return new WP_Error( 'doughboss_pay_attempt_key', __( 'The payment session is missing. Please refresh and try again.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$order_data = array(
			'order_type'        => (string) $order->order_type,
			'location_id'       => (int) $order->location_id,
			'table_id'          => 0,
			'table_label'       => '',
			'table_qr_code_id'  => 0,
			'table_session_id'  => 0,
			'order_source'      => 'web',
			'source_request_id' => $request_id,
			'customer_name'     => (string) $order->customer_name,
			'customer_email'    => (string) $order->customer_email,
			'customer_phone'    => (string) $order->customer_phone,
			'address'           => (string) $order->address,
			'notes'             => (string) $order->notes,
			'subtotal'          => (float) $order->subtotal,
			'tax'               => (float) $order->tax,
			'delivery_fee'      => (float) $order->delivery_fee,
			'total'             => (float) $order->total,
			'discount'          => (float) $order->discount,
			'voucher_code'      => (string) $order->voucher_code,
			'currency'          => strtoupper( (string) $order->currency ),
			'checkout_key'      => $checkout_key,
		);
		if ( null !== $accounting_basis ) {
			$order_data['accounting_basis'] = $accounting_basis;
		}
		if ( 'pickup' === $order_data['order_type'] && isset( $order->pickup_mode, $order->promised_ready_from_utc ) && in_array( $order->pickup_mode, array( 'asap', 'scheduled' ), true ) ) {
			$order_data['pickup_mode'] = $order->pickup_mode;
			$order_data['pickup_ready_from_utc'] = (string) $order->promised_ready_from_utc;
			$order_data['pickup_ready_by_utc'] = isset( $order->promised_ready_by_utc ) ? (string) $order->promised_ready_by_utc : '';
			$order_data['pickup_timezone'] = isset( $order->timezone_snapshot ) ? (string) $order->timezone_snapshot : 'Australia/Sydney';
		}
		$order_data = $this->native_pickup_snapshot_order( $order_data, $checkout_key, (int) $order->location_id );
		$binding_hash = hash( 'sha256', wp_json_encode( array( 'approved-square', $checkout_key, $binding ) ) );
		$snapshot = DoughBoss_Checkout_Snapshots::store(
			$checkout_key,
			array(
				'order'          => $order_data,
				'lines'          => array_values( DoughBoss_Order::get_items( $request_id ) ),
				'approval'       => array( 'request_id' => $request_id, 'approved_revision' => (int) $approval['approved_revision'], 'request_fingerprint' => (string) $approval['request_fingerprint'] ),
				'square_binding' => $binding,
				'binding_hash'   => $binding_hash,
			),
			isset( $approval['payment_token_expires_at_utc'] ) ? (string) $approval['payment_token_expires_at_utc'] : ''
		);
		if ( is_wp_error( $snapshot ) ) {
			DoughBoss_Preorder_Approval::hold_payment( $request_id );
			return $snapshot;
		}
		$intent = DoughBoss_Square::create_payment_intent(
			DoughBoss_Payment::to_minor_units( (float) $order->total ),
			(string) $order_data['currency'],
			array(
				'checkout_key'            => $checkout_key,
				'purpose'                 => 'order',
				'context'                 => 'approved_preorder',
				'location_id'             => (int) $order->location_id,
				'amount_minor'            => DoughBoss_Payment::to_minor_units( (float) $order->total ),
				'protocol_version'        => 'square-v2',
				'attempt_identity'        => hash( 'sha256', 'approved-square|' . $request_id . '|' . $checkout_key . '|' . $client_key ),
				'binding_hash'            => $binding_hash,
				'cart_guard'              => hash( 'sha256', 'approved-square-cart|' . $request_id . '|' . $checkout_key ),
				'source_request_id'       => $request_id,
				'approved_revision'       => (int) $approval['approved_revision'],
				'request_fingerprint'     => (string) $approval['request_fingerprint'],
				'preorder_payment_token'  => $token,
				'source_id'               => sanitize_text_field( (string) $request->get_param( 'source_id' ) ),
				'tender_kind'             => $tender_kind,
				'verification_token'      => sanitize_text_field( (string) $request->get_param( 'verification_token' ) ),
			)
		);
		if ( is_wp_error( $intent ) ) {
			// A definitive card refusal took no money: reopen this approval so the
			// customer can try another card (bounded), instead of stranding it.
			if ( 'doughboss_pay_declined' === $intent->get_error_code() ) {
				$declined = $intent->get_error_data();
				$reopened = DoughBoss_Preorder_Approval::reopen_after_decline( $request_id, is_array( $declined ) && isset( $declined['attempt_id'] ) ? (int) $declined['attempt_id'] : 0 );
				if ( is_wp_error( $reopened ) ) {
					return new WP_Error( 'doughboss_pay_declined', __( 'Your card was declined and you have not been charged. Please contact the shop to finish this order.', 'doughboss' ), array( 'status' => 402, 'payment_pending' => false, 'retry_safe' => false ) );
				}
			}
			return $intent;
		}
		return rest_ensure_response( array( 'gateway' => 'square', 'payment_intent' => (string) $intent['id'], 'amount' => (int) $intent['amount'], 'currency' => (string) $intent['currency'], 'live_mode' => DoughBoss_Square::live_mode() ) );
	}

	/**
	 * Verify the returned Stripe Session and release one paid order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preorder_payment_complete( WP_REST_Request $request ) {
		if ( 'square' === DoughBoss_Settings::payment_gateway() ) {
			return $this->preorder_payment_complete_square( $request );
		}
		if ( $this->rate_limited( 'preorder_payment_complete', 12, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'doughboss_rate_limit', __( 'Too many requests. Please wait a few minutes and try again.', 'doughboss' ), array( 'status' => 429 ) );
		}
		$request_id = absint( $request->get_param( 'request_id' ) );
		$token      = sanitize_text_field( $request->get_param( 'token' ) );
		$approval   = DoughBoss_Preorder_Approval::verify_payment_token( $request_id, $token, true, true );
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}
		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
		$prefix     = 'live' === DoughBoss_Settings::stripe_mode() ? 'cs_live_' : 'cs_test_';
		if ( 0 !== strpos( $session_id, $prefix ) ) {
			return new WP_Error( 'doughboss_pay_unverified', __( 'That Stripe payment session does not match this shop. Please contact us and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$checkout_key = isset( $approval['payment_checkout_key'] ) ? (string) $approval['payment_checkout_key'] : '';
		$attempt      = 1 === preg_match( '/^[a-f0-9]{64}$/', $checkout_key ) ? DoughBoss_Payment_Attempts::find_by_checkout_key( $checkout_key ) : null;
		if ( ! $attempt || 'stripe' !== (string) $attempt['provider'] || ! hash_equals( (string) $attempt['provider_reference'], $session_id ) ) {
			return new WP_Error( 'doughboss_pay_unverified', __( 'That payment session is not the one created for this approved request. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$intent = DoughBoss_Stripe::retrieve_checkout_payment( $session_id );
		if ( is_wp_error( $intent ) ) {
			DoughBoss_Preorder_Approval::hold_payment( $request_id );
			return $intent;
		}
		if ( 'succeeded' !== ( isset( $intent['status'] ) ? (string) $intent['status'] : '' ) ) {
			DoughBoss_Preorder_Approval::hold_payment( $request_id );
			return new WP_Error( 'doughboss_pay_unverified', __( 'Stripe has not confirmed this payment. Please do not pay again; contact the shop if it remains pending.', 'doughboss' ), array( 'status' => 402 ) );
		}
		$metadata = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
		if (
			'approved_preorder' !== ( isset( $metadata['context'] ) ? sanitize_key( (string) $metadata['context'] ) : '' )
			|| $request_id !== ( isset( $metadata['source_request_id'] ) ? absint( $metadata['source_request_id'] ) : 0 )
			|| ! $attempt
			|| 'stripe' !== (string) $attempt['provider']
			|| ! hash_equals( (string) $attempt['provider_reference'], $session_id )
			|| ! isset( $intent['checkout_session_id'], $intent['checkout_session_reference'], $intent['checkout_session_amount'], $intent['checkout_session_currency'] )
			|| ! hash_equals( $session_id, (string) $intent['checkout_session_id'] )
			|| ! hash_equals( $checkout_key, (string) $intent['checkout_session_reference'] )
			|| (int) $intent['checkout_session_amount'] !== (int) $attempt['amount_minor']
			|| strtoupper( (string) $intent['checkout_session_currency'] ) !== strtoupper( (string) $attempt['currency'] )
		) {
			DoughBoss_Preorder_Approval::hold_payment( $request_id );
			return new WP_Error( 'doughboss_pay_unverified', __( 'That payment is not bound to this approved request. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$pi_id     = isset( $intent['id'] ) ? DoughBoss_Stripe::canonical_id( $intent['id'] ) : '';
		$recovered = $this->recover_stripe_order( $pi_id, $intent, $metadata );
		if ( is_wp_error( $recovered ) ) {
			DoughBoss_Preorder_Approval::hold_payment( $request_id );
			return $recovered;
		}
		$order_id = DoughBoss_Order::find_id_by_source_request( $request_id );
		$order    = $order_id ? DoughBoss_Order::get( $order_id ) : null;
		if ( ! $recovered || ! $order || 'paid' !== (string) $order->payment_status ) {
			DoughBoss_Preorder_Approval::hold_payment( $request_id );
			return new WP_Error( 'doughboss_pay_finalize_pending', __( 'Stripe confirmed payment, but the kitchen release is still being linked. Please retry this confirmation or call the shop; do not pay again.', 'doughboss' ), array( 'status' => 503 ) );
		}
		return rest_ensure_response( $this->checkout_payload( $order, true ) );
	}

	/** @return WP_REST_Response|WP_Error */
	private function preorder_payment_complete_square( WP_REST_Request $request ) {
		if ( $this->rate_limited( 'preorder_payment_complete', 12, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'doughboss_rate_limit', __( 'Too many requests. Please wait a few minutes and try again.', 'doughboss' ), array( 'status' => 429 ) );
		}
		$request_id = absint( $request->get_param( 'request_id' ) );
		$token      = sanitize_text_field( $request->get_param( 'token' ) );
		$approval   = DoughBoss_Preorder_Approval::verify_payment_token( $request_id, $token, true, true );
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}
		$attempt = $this->approved_square_attempt( $approval, $request_id );
		if ( ! is_array( $attempt ) ) {
			return new WP_Error( 'doughboss_preorder_payment_binding', __( 'This payment is not bound to the approved request. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$reference = isset( $attempt['provider_reference'] ) ? DoughBoss_Square::canonical_id( $attempt['provider_reference'] ) : '';
		if ( '' === $reference ) {
			return new WP_Error( 'doughboss_preorder_payment_pending', __( 'Your payment is still being confirmed. Please do not pay again.', 'doughboss' ), array( 'status' => 409, 'payment_pending' => true, 'retry_safe' => false ) );
		}
		$intent = DoughBoss_Square::retrieve_payment_intent( $reference );
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}
		if ( 'succeeded' !== (string) $intent['status'] ) {
			return new WP_Error( 'doughboss_preorder_payment_pending', __( 'Your payment is still being confirmed. Please do not pay again.', 'doughboss' ), array( 'status' => 409, 'payment_pending' => true, 'retry_safe' => false ) );
		}
		$recovered = $this->recover_approved_square_order( $attempt, $reference );
		if ( is_wp_error( $recovered ) ) {
			return $recovered;
		}
		if ( ! is_array( $recovered ) ) {
			return new WP_Error( 'doughboss_pay_snapshot_missing', __( 'The paid order details require manual confirmation. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		return rest_ensure_response( $recovered );
	}

	/** Resolve one exact approval-linked attempt without trusting browser IDs. @return array|null */
	private function approved_square_attempt( array $approval, $request_id ) {
		$attempt_id = isset( $approval['payment_attempt_id'] ) ? (int) $approval['payment_attempt_id'] : 0;
		$attempt    = $attempt_id ? DoughBoss_Payment_Attempts::find( $attempt_id ) : null;
		$order      = DoughBoss_Order::get( (int) $request_id );
		if ( ! is_array( $attempt ) || ! $order || 'preorder_request' !== (string) $order->order_source ) {
			return null;
		}
		$metadata = DoughBoss_Payment_Attempts::metadata( $attempt );
		$binding  = DoughBoss_Square::shop_binding( (int) $order->location_id );
		if ( is_wp_error( $binding ) || 'square' !== (string) $attempt['provider'] || 'order' !== (string) $attempt['purpose'] || 'approved_preorder' !== (string) $attempt['context']
			|| (int) $attempt['location_id'] !== (int) $order->location_id
			|| (int) $attempt['amount_minor'] !== DoughBoss_Payment::to_minor_units( (float) $order->total )
			|| strtoupper( (string) $attempt['currency'] ) !== strtoupper( (string) $order->currency )
			|| empty( $approval['payment_checkout_key'] ) || ! hash_equals( (string) $approval['payment_checkout_key'], (string) $attempt['checkout_key'] )
			|| ! isset( $metadata['approval_id'], $metadata['approved_revision'], $metadata['approval_fingerprint'], $metadata['square_shop_slug'], $metadata['square_location_id'], $metadata['square_mode'] )
			|| (int) $metadata['approval_id'] !== (int) $request_id || (int) $metadata['approved_revision'] !== (int) $approval['approved_revision']
			|| ! isset( $approval['request_fingerprint'] ) || ! $this->valid_fingerprint( $approval['request_fingerprint'] ) || ! $this->valid_fingerprint( $metadata['approval_fingerprint'] )
			|| ! hash_equals( $approval['request_fingerprint'], $metadata['approval_fingerprint'] )
			|| ! hash_equals( (string) $binding['shop_slug'], (string) $metadata['square_shop_slug'] )
			|| ! hash_equals( (string) $binding['square_location_id'], (string) $metadata['square_location_id'] )
			|| ! hash_equals( (string) $binding['square_mode'], (string) $metadata['square_mode'] ) ) {
			return null;
		}
		return $attempt;
	}

	/** Shared browser/webhook recovery; the order transaction rechecks locked authority. @return array|false|WP_Error */
	private function recover_approved_square_order( array $attempt, $reference ) {
		$metadata = DoughBoss_Payment_Attempts::metadata( $attempt );
		$request_id = isset( $metadata['approval_id'] ) ? absint( $metadata['approval_id'] ) : 0;
		$approval = $request_id ? DoughBoss_Preorder_Approval::get( $request_id ) : new WP_Error( 'doughboss_preorder_payment_binding' );
		$linked = ! is_wp_error( $approval ) && $request_id ? $this->approved_square_attempt( $approval, $request_id ) : null;
		if ( ! is_array( $linked ) || (int) $linked['id'] !== (int) $attempt['id'] || ! hash_equals( (string) $linked['provider_reference'], (string) $reference ) ) {
			return false;
		}
		// The immutable snapshot may have been consumed by the first successful
		// transaction. A lost browser reply must replay that exact paid receipt,
		// not create another order or require the customer to pay again.
		$existing_id = DoughBoss_Order::find_id_by_source_request( $request_id );
		if ( $existing_id ) {
			$existing = DoughBoss_Order::get( $existing_id );
			if ( ! $existing || 'released' !== (string) $approval['state'] || (int) $approval['fulfillment_order_id'] !== $existing_id
				|| 'square' !== (string) $existing->payment_method || 'paid' !== (string) $existing->payment_status
				|| ! hash_equals( (string) $reference, (string) $existing->payment_intent_id )
				|| ! hash_equals( (string) $attempt['checkout_key'], (string) $existing->checkout_key )
				|| (int) $existing->location_id !== (int) $attempt['location_id']
				|| DoughBoss_Payment::to_minor_units( (float) $existing->total ) !== (int) $attempt['amount_minor']
				|| strtoupper( (string) $existing->currency ) !== strtoupper( (string) $attempt['currency'] ) ) {
				return new WP_Error( 'doughboss_preorder_payment_binding', __( 'The paid receipt requires staff review. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			// Revalidate the locked source fingerprint and both historical bases even
			// when the original snapshot was consumed. This performs no new release.
			$released = DoughBoss_Preorder_Approval::release( $request_id, $existing_id );
			if ( is_wp_error( $released ) ) {
				return $released;
			}
			return $this->checkout_payload( $existing, true );
		}
		$snapshot = DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( (string) $attempt['checkout_key'] );
		if ( ! is_array( $snapshot ) || empty( $snapshot['payload']['order'] ) || empty( $snapshot['payload']['lines'] ) ) {
			return false;
		}
		$order_data = $snapshot['payload']['order'];
		$copied_basis = $this->copy_accounting_basis_from_snapshot( $snapshot['payload']['order'], $order_data );
		if ( is_wp_error( $copied_basis ) ) {
			return $copied_basis;
		}
		$order_data['payment_status'] = 'paid';
		$order_data['payment_method'] = 'square';
		$order_data['payment_intent_id'] = $reference;
		$order_data['checkout_key'] = (string) $attempt['checkout_key'];
		$created = DoughBoss_Order::create( $order_data, array_values( $snapshot['payload']['lines'] ) );
		if ( is_wp_error( $created ) ) {
			if ( 'doughboss_paid_reconciliation_required' === $created->get_error_code() ) {
				DoughBoss_Preorder_Approval::hold_payment( $request_id );
				DoughBoss_Payment_Attempts::hold_square_order_verification( $attempt );
			}
			return $created;
		}
		DoughBoss_Payment_Attempts::mark_order_committed( $reference, (int) $created['order_id'] );
		$order = DoughBoss_Order::get( (int) $created['order_id'] );
		if ( ! $order ) {
			return new WP_Error( 'doughboss_preorder_payment_order_missing', __( 'The paid order could not be loaded. Please contact the shop.', 'doughboss' ), array( 'status' => 500 ) );
		}
		if ( empty( $created['replayed'] ) ) {
			$this->send_confirmation( $order );
		}
		return $this->checkout_payload( $order, ! empty( $created['replayed'] ) );
	}

	/**
	 * Safe public summary for an approved request capability.
	 *
	 * @param object $order    Original request.
	 * @param array  $approval Approval state row.
	 * @return array
	 */
	private function preorder_payment_payload( $order, array $approval ) {
		$items = array();
		foreach ( DoughBoss_Order::get_items( (int) $order->id ) as $item ) {
			$items[] = array(
				'name'       => (string) $item['name'],
				'size'       => (string) $item['size'],
				'toppings'   => is_array( $item['toppings'] ) ? array_values( $item['toppings'] ) : array(),
				'quantity'   => (int) $item['quantity'],
				'line_total' => (float) $item['line_total'],
			);
		}
		$state       = isset( $approval['state'] ) ? (string) $approval['state'] : '';
		$released_id = isset( $approval['fulfillment_order_id'] ) ? (int) $approval['fulfillment_order_id'] : 0;
		$released    = $released_id ? DoughBoss_Order::get( $released_id ) : null;
		$location    = DoughBoss_Locations::get( (int) $order->location_id );
		$expires_at  = isset( $approval['payment_token_expires_at_utc'] ) ? (string) $approval['payment_token_expires_at_utc'] : '';
		$unexpired   = '' !== $expires_at && current_time( 'mysql', true ) < $expires_at;
		$payment_location = DoughBoss_Locations::online_payment_location( (int) $order->location_id );
		$stripe_ready = 'stripe' === DoughBoss_Settings::payment_gateway()
			&& DoughBoss_Payment::ready()
			&& ! is_wp_error( $payment_location );
		$square_binding = 'square' === DoughBoss_Settings::payment_gateway() ? DoughBoss_Square::shop_binding( (int) $order->location_id ) : new WP_Error( 'doughboss_pay_off' );
		$square_ready = 'square' === DoughBoss_Settings::payment_gateway() && DoughBoss_Square::ready_for_location( (int) $order->location_id ) && ! is_wp_error( $payment_location ) && ! is_wp_error( $square_binding );
		$linked_attempt_id = isset( $approval['payment_attempt_id'] ) ? (int) $approval['payment_attempt_id'] : 0;
		$linked_attempt = $square_ready && $linked_attempt_id ? $this->approved_square_attempt( $approval, (int) $order->id ) : null;
		$square_confirm = $square_ready && in_array( $state, array( 'payment_active', 'payment_held' ), true ) && is_array( $linked_attempt );
		$timed_square = $square_ready && 1 === (int) ( isset( $order->preorder_workflow_version ) ? $order->preorder_workflow_version : 0 );
		$start_window = $unexpired && ( $timed_square || ( $stripe_ready && strtotime( $expires_at . ' UTC' ) >= time() + ( 35 * MINUTE_IN_SECONDS ) ) );
		$can_start   = ( $stripe_ready || $square_ready ) && $unexpired && ( 'payment_active' === $state || ( 'approved_unpaid' === $state && $start_window ) ) && ! ( $square_ready && $linked_attempt_id );
		$can_start = $can_start && ( ! $square_ready || $timed_square );
		$payable     = $can_start;
		$phone       = $location && isset( $location->phone ) ? sanitize_text_field( (string) $location->phone ) : '';
		$normal_phone = class_exists( 'DoughBoss_SMS' ) ? DoughBoss_SMS::normalize_phone( $phone ) : '';
		$presentation = $released ? 'complete' : ( 'payment_held' === $state ? 'held' : ( ! $unexpired || ( 'approved_unpaid' === $state && ! $start_window ) ? 'expired' : ( ! ( $stripe_ready || $square_ready ) ? 'payment_disabled' : ( $can_start ? 'ready' : 'unavailable' ) ) ) );
		if ( $released ) {
			$message = __( 'Payment is verified and your order has been sent to DoughBoss.', 'doughboss' );
		} elseif ( 'payment_held' === $state ) {
			$message = __( 'Payment needs staff review. Do not pay again; contact DoughBoss.', 'doughboss' );
		} elseif ( ! $unexpired || ( 'approved_unpaid' === $state && ! $start_window ) ) {
			$message = __( 'This approved payment link can no longer start a new payment. Please contact DoughBoss and do not pay again.', 'doughboss' );
		} elseif ( ! ( $stripe_ready || $square_ready ) ) {
			$message = __( 'Online payment is not enabled for this shop right now. Please contact DoughBoss and do not pay.', 'doughboss' );
		} else {
			$message = __( 'This request is approved for payment. It is not sent to the kitchen until the server verifies payment.', 'doughboss' );
			if ( $timed_square ) {
				$message = __( 'Pickup is reserved only for the short payment window stated in your approval email. Complete payment before that reservation expires. A late payment needs staff review; do not pay again if confirmation is pending.', 'doughboss' );
			}
		}
		return array(
			'success'            => true,
			'request_id'         => (int) $order->id,
			'order_number'       => (string) $order->order_number,
			'location_name'      => $location && isset( $location->name ) ? (string) $location->name : '',
			'items'              => $items,
			'total'              => (float) $order->total,
			'currency'           => (string) $order->currency,
			'state'              => $state,
			'presentation_state' => $presentation,
			'gateway'            => DoughBoss_Settings::payment_gateway(),
			'can_start_payment'  => $can_start,
			'payment_expires_at_utc' => $expires_at,
			'can_confirm_payment'=> $square_confirm,
			'square'             => $square_ready ? array( 'application_id' => DoughBoss_Square::publishable_key(), 'location_id' => (string) $square_binding['square_location_id'], 'live_mode' => DoughBoss_Square::live_mode() ) : null,
			'contact_phone'      => $phone,
			'contact_tel'        => '' === $normal_phone ? '' : 'tel:' . $normal_phone,
			'payable'            => $payable && ( $stripe_ready || $square_ready ),
			'complete'           => $released && 'paid' === (string) $released->payment_status,
			'tracking_url'       => $released ? DoughBoss_Settings::tracking_page_url( $released->order_number ) : '',
			'fulfilment_number'  => $released ? (string) $released->order_number : '',
			'message'            => $message,
		);
	}

	/**
	 * Same-site fragment URLs for the approved request Stripe redirect.
	 *
	 * @param int    $request_id Request id.
	 * @param string $token      Opaque capability token.
	 * @return array{success:string,cancel:string}
	 */
	private function preorder_payment_return_urls( $request_id, $token ) {
		$link     = $this->preorder_payment_link( $request_id, $token );
		$parts    = explode( '#', $link, 2 );
		$base     = $parts[0];
		$fragment = isset( $parts[1] ) ? $parts[1] : '';
		return array(
			'success' => $base . '#' . $fragment . '&doughboss_preorder_return=1&session_id={CHECKOUT_SESSION_ID}',
			'cancel'  => $base . '#' . $fragment . '&doughboss_preorder_cancel=1',
		);
	}

	/**
	 * Public page link carrying its opaque capability only in the URL fragment.
	 *
	 * @param int    $request_id Request id.
	 * @param string $token      Opaque capability token.
	 * @return string
	 */
	private function preorder_payment_link( $request_id, $token ) {
		$base = (string) apply_filters( 'doughboss_preorder_payment_page_url', home_url( '/menu/' ) );
		return $base . '#doughboss_request=' . absint( $request_id ) . '&doughboss_token=' . rawurlencode( (string) $token );
	}

	/**
	 * Read the state of an approved request without mutating it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function checkout( WP_REST_Request $request ) {
		// The raw browser key is required, validated, then bound to this cart token
		// with an HMAC. The stored key is therefore useless outside this session.
		$idem = $this->idempotency_key( $request );
		if ( is_wp_error( $idem ) ) {
			return $idem;
		}
		if ( version_compare( (string) get_option( 'doughboss_db_version', '0' ), '1.25.0', '<' ) || ! DoughBoss_Activator::checkout_storage_ready() ) {
			return new WP_Error( 'doughboss_checkout_storage_unavailable', __( 'Online ordering is temporarily unavailable while checkout storage is upgraded.', 'doughboss' ), array( 'status' => 503 ) );
		}
		$hosted_checkout_key = DoughBoss_Square_Hosted::returned_checkout_key( $request->get_param( 'square_checkout' ) );
		if ( '' !== $hosted_checkout_key || null !== $request->get_param( 'square_checkout' ) ) {
			return $this->square_hosted_return( $request, $idem, $hosted_checkout_key );
		}
		$returned_reference = $request->get_param( 'payment_intent_id' );
		$returned_reference = is_scalar( $returned_reference ) ? sanitize_text_field( (string) $returned_reference ) : '';
		$stripe_return      = 1 === preg_match( '/^cs_(?:test|live)_[A-Za-z0-9_]{8,191}$/', $returned_reference );
		if ( $stripe_return ) {
			// Paid returns must repair interrupted finalization before a cached or
			// durable browser reply can hide it. Gate the provider lookup once.
			if ( $this->rate_limited( 'checkout', 8, 10 * MINUTE_IN_SECONDS ) ) {
				return new WP_Error( 'doughboss_rate_limit', __( 'Too many requests. Please wait a few minutes and try again.', 'doughboss' ), array( 'status' => 429 ) );
			}
			$stripe_replay = $this->stripe_paid_order_replay( $request, $idem );
			if ( is_wp_error( $stripe_replay ) ) {
				return $stripe_replay;
			}
			if ( $stripe_replay ) {
				$payload = $this->checkout_payload( $stripe_replay, true );
				$this->cart->clear();
				set_transient( 'doughboss_idem_' . $idem, $payload, 6 * HOUR_IN_SECONDS );
				return rest_ensure_response( $payload );
			}
		}

		// Durable replay check precedes cart validation: a response may be lost
		// after the winning request commits and clears the shared cart.
		$existing_id = DoughBoss_Order::find_id_by_checkout_key( $idem );
		if ( ! $stripe_return && $existing_id ) {
			$existing = DoughBoss_Order::get( $existing_id );
			if ( $existing ) {
				$receipt_valid = $this->validate_square_receipt_basis( $existing );
				if ( is_wp_error( $receipt_valid ) ) {
					return $receipt_valid;
				}
				$payload = $this->checkout_payload( $existing, true );
				set_transient( 'doughboss_idem_' . $idem, $payload, 6 * HOUR_IN_SECONDS );
				return rest_ensure_response( $payload );
			}
		}

		// Existing paid orders above may safely replay bookkeeping after a policy
		// transition. Everything below can begin a new ordinary fulfilment path.
		$ordinary_policy_error = $this->ordinary_checkout_policy_error();
		if ( ! $stripe_return && '' !== $returned_reference ) {
			$returned_attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $returned_reference );
			$returned_metadata = is_array( $returned_attempt ) ? DoughBoss_Payment_Attempts::metadata( $returned_attempt ) : array();
			if ( is_wp_error( $ordinary_policy_error ) || isset( $returned_metadata['reconciliation_hold'] ) ) {
				$square_hold = $this->hold_square_return_for_reconciliation( $request, $returned_attempt );
				if ( is_wp_error( $square_hold ) ) { return $square_hold; }
			}
		}
		if ( is_wp_error( $ordinary_policy_error ) ) {
			if ( $stripe_return ) {
				$held = $this->hold_stripe_return_for_reconciliation( $returned_reference );
				if ( is_wp_error( $held ) ) {
					return $held;
				}
				return new WP_Error( 'doughboss_paid_reconciliation_required', __( 'Your paid order is awaiting secure staff reconciliation. Please do not pay again.', 'doughboss' ), array( 'status' => 409, 'payment_pending' => true ) );
			}
			return $ordinary_policy_error;
		}

		// Gate the provider lookup below as well as ordinary checkout work. A
		// returned Stripe Session id is still untrusted input, and resolving it
		// must not provide an unauthenticated amplification path to Stripe.
		if ( ! $stripe_return && $this->rate_limited( 'checkout', 8, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'doughboss_rate_limit', __( 'Too many requests. Please wait a few minutes and try again.', 'doughboss' ), array( 'status' => 429 ) );
		}

		if ( ! DoughBoss_Settings::ordering_open() ) {
			return new WP_Error( 'doughboss_closed', DoughBoss_Settings::ordering_closed_message(), array( 'status' => 503 ) );
		}

		if ( $this->cart->is_empty() ) {
			return new WP_Error( 'doughboss_empty', __( 'Your cart is empty.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$table_context = $this->table_order_context();
		if ( is_wp_error( $table_context ) ) {
			return $table_context;
		}
		if ( $table_context ) {
			$order_type = 'dine_in';
		} else {
			$order_type = sanitize_key( $request->get_param( 'order_type' ) );
			$order_type = ( 'delivery' === $order_type ) ? 'delivery' : 'pickup';
			$single_location_id = DoughBoss_Locations::single_location_id();
			if ( $single_location_id ) {
				$order_type = 'pickup';
			}
		}

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

		// Resolve the shop before charging or creating an order. Multi-shop sites
		// must receive an explicit active location; silently choosing the first
		// row can send a paid order to the wrong kitchen.
		$location_id = $table_context ? (int) $table_context['location_id'] : $this->resolve_order_location( $request->get_param( 'location_id' ), $order_type );
		if ( is_wp_error( $location_id ) ) {
			return $location_id;
		}

		$totals = $this->cart->totals( $order_type );
		$lines  = $this->cart->get_lines();
		$captured_accounting_basis = $this->accounting_basis_from_totals( $totals );
		if ( is_wp_error( $captured_accounting_basis ) ) {
			return $captured_accounting_basis;
		}

		// When a payment gateway is configured, the order is only accepted once a
		// matching payment has actually succeeded. The amount/currency are
		// verified against this order's server-computed total, and each payment
		// reference can be used for at most one order. The gateway that actually
		// processed the payment is recorded on the order (not just whichever is
		// "active" today) so a later refund always targets the right one even if
		// the owner switches gateways afterwards — see DoughBoss_Payment::refund_via().
		$payment_status    = 'unpaid';
		$payment_method    = '';
		$payment_intent_id = '';
		$paid_snapshot     = null;
		$returned_payment_reference = $request->get_param( 'payment_intent_id' );
		$returned_payment_reference = is_scalar( $returned_payment_reference ) ? sanitize_text_field( (string) $returned_payment_reference ) : '';
		$payment_return             = '' !== $returned_payment_reference;
		$returned_attempt = $payment_return ? DoughBoss_Payment_Attempts::find_by_provider_reference( $returned_payment_reference ) : null;
		if ( ! $this->availability_replay_attempt( $returned_attempt, $location_id ) ) {
			$availability_error = $this->menu_lines_availability_error( $lines, $location_id );
			if ( is_wp_error( $availability_error ) ) { return $availability_error; }
		}
		$returned_stripe_session    = 1 === preg_match( '/^cs_(?:test|live)_[A-Za-z0-9_]{8,191}$/', $returned_payment_reference );
		// Payment policy is not initiation readiness: losing a Square mapping
		// or credential must not reinterpret enabled payments as pay-at-shop.
		// Preserve every legacy gateway requirement and returned-reference guard.
		$payment_required = DoughBoss_Payment::ready()
			|| ( 'square' === DoughBoss_Settings::payment_gateway() && DoughBoss_Settings::payments_enabled() );
		if ( $payment_required || $payment_return ) {
			// A hosted payment can return after an operator closes card acceptance.
			// The switch must stop new charges, but it must never reinterpret an
			// already-paid return as pay-on-pickup. Any returned provider reference
			// therefore forces verification (or a safe error) before order creation.
			$payment_location = DoughBoss_Locations::online_payment_location( $location_id );
			if ( is_wp_error( $payment_location ) ) {
				return $payment_location;
			}
			$verified = $this->verify_payment( $request, $totals['total'], $order_type, $location_id, $table_context );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			$payment_status = 'paid';
			if ( is_array( $verified ) && isset( $verified['id'], $verified['snapshot'] ) ) {
				$payment_method    = isset( $verified['provider'] ) ? sanitize_key( $verified['provider'] ) : '';
				$payment_intent_id = sanitize_text_field( (string) $verified['id'] );
				$paid_snapshot     = $verified['snapshot'];
			} else {
				$payment_method    = $returned_stripe_session ? 'stripe' : DoughBoss_Settings::payment_gateway();
				$payment_intent_id = $verified;
			}
		}
		$order_accounting_basis = $captured_accounting_basis;
		// Verified paid returns retain their original seller/snapshot. Only a new
		// unpaid order is subject to shop correction; never migrate paid money.
		if ( 'unpaid' === $payment_status ) {
			$routing_error = $this->large_order_routing_error( $totals, $location_id, DoughBoss_Settings::get( 'currency_code', 'AUD' ) );
			if ( is_wp_error( $routing_error ) ) { return $routing_error; }
		}
		if ( 'paid' === $payment_status && is_array( $paid_snapshot ) && isset( $paid_snapshot['payload']['order'] ) && is_array( $paid_snapshot['payload']['order'] ) ) {
			$order_accounting_basis = DoughBoss_Order::accounting_basis_from_payload( $paid_snapshot['payload']['order'] );
			if ( is_wp_error( $order_accounting_basis ) ) {
				return $order_accounting_basis;
			}
		}

		// 2.51 pickup facts. A paid order always uses what its immutable snapshot
		// recorded (never re-validated, so money already taken cannot be refused);
		// an unpaid order validates its held slot now, before any voucher redeem.
		$pickup_fields = array();
		if ( 'paid' === $payment_status ) {
			if ( is_array( $paid_snapshot ) && isset( $paid_snapshot['payload']['order'] ) && is_array( $paid_snapshot['payload']['order'] ) ) {
				$pickup_fields = $this->pickup_fields_from_snapshot( $paid_snapshot['payload']['order'] );
			} else {
				// Gateways without an immutable snapshot (Tyro, MPGS): the held time
				// travels in the verified request instead. The order writer attaches
				// it with paid guarantees and never refuses the paid order over it.
				$pickup_fields = $this->pickup_fields_from_paid_request( $request, $location_id, $order_type );
			}
		} else {
			$pickup_selection = $this->pickup_selection_for_checkout( $request, $location_id, $order_type, false );
			if ( is_wp_error( $pickup_selection ) ) {
				return $pickup_selection;
			}
			if ( ! empty( $pickup_selection ) ) {
				$pickup_fields = $this->pickup_snapshot_fields( $pickup_selection, $location_id );
				if ( ! empty( $pickup_fields ) ) {
					$pickup_fields['capacity_unpaid_allowed'] = true;
				}
			}
		}

		if ( 'square' === $payment_method && is_array( $paid_snapshot ) && isset( $paid_snapshot['payload']['order'], $paid_snapshot['payload']['lines'] ) ) {
			$saved_order = $paid_snapshot['payload']['order'];
			$name        = (string) $saved_order['customer_name'];
			$email       = (string) $saved_order['customer_email'];
			$phone       = (string) $saved_order['customer_phone'];
			$notes       = (string) $saved_order['notes'];
			$addr        = (string) $saved_order['address'];
			$lines       = array_values( $paid_snapshot['payload']['lines'] );
			$totals      = array(
				'subtotal'     => (float) $saved_order['subtotal'],
				'tax'          => (float) $saved_order['tax'],
				'delivery_fee' => (float) $saved_order['delivery_fee'],
				'total'        => (float) $saved_order['total'],
				'discount'     => (float) $saved_order['discount'],
				'voucher_code' => (string) $saved_order['voucher_code'],
			);
			if ( null !== $order_accounting_basis ) {
				$totals['accounting_basis'] = $order_accounting_basis;
			} else {
				unset( $totals['accounting_basis'] );
			}
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
		$voucher_reservation_key = '';
		if ( '' !== $voucher_code && $discount > 0 ) {
			if ( 'paid' === $payment_status && 'square' === $payment_method && is_array( $paid_snapshot ) ) {
				$voucher_reservation_key = isset( $paid_snapshot['payload']['order']['voucher_reservation_key'] )
					? strtolower( sanitize_text_field( (string) $paid_snapshot['payload']['order']['voucher_reservation_key'] ) )
					: '';
			} elseif ( 'paid' === $payment_status && 'stripe' === $payment_method ) {
				$voucher_reservation_key = $this->payment_checkout_key(
					$request,
					'order',
					$this->pickup_checkout_key_binding(
						array( 'cart' => $this->cart->to_array( $order_type ), 'location_id' => $location_id, 'order_type' => $order_type, 'table' => $table_context ),
						$this->pickup_binding_from_request( $request )
					)
				);
				if ( is_wp_error( $voucher_reservation_key ) ) {
					return $voucher_reservation_key;
				}
			}
			$voucher_idem = 'order_' . ( '' !== $voucher_reservation_key ? $voucher_reservation_key : ( '' !== $idem ? $idem : md5( $this->cart->get_token() . '|' . $voucher_code ) ) );
			$redeem       = DoughBoss_Voucher::redeem(
				$voucher_code,
				$totals['subtotal'],
				'online',
				array(
					'idempotency_key' => $voucher_idem,
					'reservation_key' => $voucher_reservation_key,
				)
			);
			if ( is_wp_error( $redeem ) ) {
				if ( 'unpaid' === $payment_status ) {
					$this->cart->set_voucher_code( '' );
					return $redeem;
				}

				// Paid order that lost the redeem race: we keep the discount the
				// customer already paid (see the note above) rather than failing a
				// paid order, but that leaves an order carrying a discount with no
				// backing voucher_redemptions row. Leave a reconciliation trail —
				// an operator-visible order note plus a safe log line — so the gap
				// is auditable rather than silent. Customer-facing behaviour is
				// unchanged; only the stored notes gain a [SYSTEM] annotation.
				$system_note = sprintf(
					/* translators: 1: formatted discount amount, 2: voucher code, 3: redemption error message. */
					'[SYSTEM] Discount of %1$s from voucher "%2$s" applied to this paid order, but redemption failed post-payment (%3$s) — no matching voucher_redemptions row exists. Reconcile manually.',
					DoughBoss_Settings::format_price( $discount ),
					$voucher_code,
					$redeem->get_error_message()
				);
				$notes = '' !== $notes ? $notes . "\n\n" . $system_note : $system_note;

				if ( function_exists( 'error_log' ) ) {
					// Safe diagnostic only: voucher code + idempotency key + label.
					// No WP_Error object, PII, or payment details.
					error_log( 'DoughBoss voucher: unbacked discount on paid order — code ' . $voucher_code . ' idem ' . $voucher_idem ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			} else {
				$discount = (float) $redeem['amount'];
			}
		} else {
			$discount     = 0.0;
			// Totals already reject invalid vouchers. Preserve a valid code whose
			// discount rounds to zero so the paid snapshot and pickup hold match.
		}

		$order_data = array(
				'order_type'     => $order_type,
				'location_id'    => $location_id,
				'table_id'       => $table_context ? (int) $table_context['table_id'] : 0,
				'table_label'    => $table_context ? $table_context['table_label'] : '',
				'table_qr_code_id' => $table_context ? (int) $table_context['qr_code_id'] : 0,
				'table_session_id' => $table_context ? (int) $table_context['session_id'] : 0,
				'order_source'   => $table_context ? 'table_qr' : 'web',
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
				'checkout_key'      => $idem,
		);
		if ( ! empty( $pickup_fields ) ) {
			$order_data = array_merge( $order_data, $pickup_fields );
		}
		if ( null !== $order_accounting_basis ) {
			$order_data['accounting_basis'] = $order_accounting_basis;
		}
		$created = DoughBoss_Order::create( $order_data, $lines );

		if ( is_wp_error( $created ) ) {
			// An unpaid or legacy unreserved attempt can safely be compensated.
			// A verified paid Stripe checkout with a reservation must stay
			// consumed: restoring it here opens a race where another checkout can
			// claim the voucher before the paid retry creates and links its order.
			if ( '' !== $voucher_idem && ( 'unpaid' === $payment_status || '' === $voucher_reservation_key ) ) {
				DoughBoss_Voucher::revert_redemption( $voucher_idem, $voucher_reservation_key, $payment_intent_id );
			}
			return $created;
		}
		$order_id = (int) $created['order_id'];
		$replayed = ! empty( $created['replayed'] );
		$replayed_by = isset( $created['replayed_by'] ) ? $created['replayed_by'] : '';

		$order = DoughBoss_Order::get( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'doughboss_order_replay_missing', __( 'The saved order could not be loaded. Please contact the shop before trying again.', 'doughboss' ), array( 'status' => 500 ) );
		}
		if ( 'square' === $payment_method && is_array( $paid_snapshot ) ) {
			DoughBoss_Checkout_Snapshots::complete( (string) $paid_snapshot['checkout_key'], $order_id );
			DoughBoss_Payment_Attempts::mark_order_committed( $payment_intent_id, $order_id );
		}
		if ( '' !== $voucher_idem && '' !== $voucher_reservation_key && (string) $order->voucher_code === $voucher_code ) {
			// A failed twin worker may have reverted the unlinked audit after this
			// worker replayed redeem but before it committed the paid order. Now that
			// the order exists, re-run the same owner/idempotency claim; it either
			// replays the audit or safely recreates it from the restored lease.
			DoughBoss_Voucher::redeem(
				$voucher_code,
				$totals['subtotal'],
				'online',
				array(
					'idempotency_key' => $voucher_idem,
					'reservation_key' => $voucher_reservation_key,
				)
			);
		}
		if ( $replayed && 'checkout_key' !== $replayed_by && '' !== $voucher_idem ) {
			if ( '' !== $voucher_reservation_key ) {
				// A signed Stripe webhook can create the exact snapshot order before
				// the browser returns. The lease proves this redemption belongs to
				// that payment, so link it to the winning order instead of reverting
				// the webhook's audit in the narrow pre-link race.
				DoughBoss_Voucher::link_redemption_to_order( $voucher_idem, $order_id );
			} else {
				// Legacy/unreserved payment reuse must not consume a second voucher.
				DoughBoss_Voucher::revert_redemption( $voucher_idem );
			}
		} elseif ( '' !== $voucher_idem ) {
			DoughBoss_Voucher::link_redemption_to_order( $voucher_idem, $order_id );
		}
		$this->cart->clear();
		if ( ! $replayed ) {
			$this->send_confirmation( $order );
		}
		if ( 'stripe' === $payment_method && is_array( $paid_snapshot ) ) {
			// Attempt the new order's notification before fallible bookkeeping.
			// A retry repairs the same order but must not send a second message.
			$finalized = $this->finalize_stripe_order( $paid_snapshot, $order, $payment_intent_id );
			if ( is_wp_error( $finalized ) ) {
				return $finalized;
			}
		}

		$payload = $this->checkout_payload( $order, $replayed );
		set_transient( 'doughboss_idem_' . $idem, $payload, 6 * HOUR_IN_SECONDS );

		return rest_ensure_response( $payload );
	}

	/**
	 * Validate ordinary Square receipt facts before returning a durable replay.
	 *
	 * @param object $order Authoritative persisted receipt.
	 * @return true|WP_Error
	 */
	private function validate_square_receipt_basis( $order ) {
		if ( 'square' !== (string) $order->payment_method ) {
			return true;
		}
		$error = new WP_Error( 'doughboss_receipt_basis_unverified', __( 'The saved receipt requires staff review. Please do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		$basis = DoughBoss_Order::accounting_basis_from_order( $order );
		if ( is_wp_error( $basis ) || 'paid' !== (string) $order->payment_status || empty( $order->payment_intent_id ) ) {
			return $error;
		}
		$attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( (string) $order->payment_intent_id );
		global $wpdb;
		if ( ! empty( $wpdb->last_error ) ) {
			return $error;
		}
		if ( ! $attempt ) {
			// Pre-protocol receipts may have no attempt, but never recorded a basis.
			return null === $basis ? true : $error;
		}
		if ( 'square' !== (string) $attempt['provider'] || 'order' !== (string) $attempt['purpose']
			|| (int) $attempt['amount_minor'] !== DoughBoss_Payment::to_minor_units( $order->total )
			|| ! hash_equals( (string) $attempt['currency'], (string) $order->currency ) ) {
			return $error;
		}
		$snapshot = DoughBoss_Checkout_Snapshots::find_for_receipt( (string) $attempt['checkout_key'], (int) $order->id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( is_array( $snapshot ) ) {
			return DoughBoss_Order::accounting_basis_matches_payload( $order, $snapshot['payload']['order'] ) ? true : $error;
		}
		$metadata = DoughBoss_Payment_Attempts::metadata( $attempt );
		// Committed receipts are eligible for retention expiry/purge. Protected,
		// unfinished recovery is not: missing facts there remain a hold.
		if ( 'order_committed' === (string) $attempt['status']
			&& isset( $metadata['committed_order'] ) && (int) $metadata['committed_order'] === (int) $order->id ) {
			return true;
		}
		return null === $basis && '' === ( isset( $metadata['protocol_version'] ) ? (string) $metadata['protocol_version'] : '' ) ? true : $error;
	}

	/**
	 * Preserve an exact session-owned ordinary Square return after policy drift.
	 * Uses retained provider-validated facts only; never charges or fulfils.
	 *
	 * @return WP_Error|null Null only when this is not an ordinary Square attempt.
	 */
	private function hold_square_return_for_reconciliation( WP_REST_Request $request, $attempt ) {
		if ( ! is_array( $attempt ) || 'square' !== (string) $attempt['provider']
			|| 'order' !== (string) $attempt['purpose'] || ! in_array( (string) $attempt['context'], array( 'web', 'table_qr' ), true ) ) {
			return null;
		}
		$invalid = new WP_Error( 'doughboss_pay_attempt', __( 'This payment return could not be verified. Please contact the shop before trying again.', 'doughboss' ), array( 'status' => 409 ) );
		$metadata = DoughBoss_Payment_Attempts::metadata( $attempt );
		$identity = $this->payment_attempt_identity( $request, 'square-order-v2' );
		if ( 'square-v2' !== ( isset( $metadata['protocol_version'] ) ? $metadata['protocol_version'] : '' )
			|| ! hash_equals( (string) $attempt['provider_reference'], sanitize_text_field( (string) $request->get_param( 'payment_intent_id' ) ) )
			|| is_wp_error( $identity ) || ! isset( $metadata['attempt_identity'], $metadata['cart_guard'], $metadata['binding_hash'], $metadata['square_mode'] )
			|| ! hash_equals( (string) $metadata['attempt_identity'], $identity )
			|| ! hash_equals( (string) $metadata['cart_guard'], $this->square_cart_guard() ) ) {
			return $invalid;
		}
		$table = $this->table_order_context();
		if ( is_wp_error( $table ) ) { return $invalid; }
		$type = $table ? 'dine_in' : ( 'delivery' === sanitize_key( $request->get_param( 'order_type' ) ) ? 'delivery' : 'pickup' );
		$location = $table ? (int) $table['location_id'] : $this->canonical_native_location_id( $request->get_param( 'location_id' ) );
		if ( null === $request->get_param( 'location_id' ) && ! $table ) { $location = DoughBoss_Locations::single_location_id(); }
		$binding = $this->square_binding_payload( $type, $location, $table,
			sanitize_text_field( $request->get_param( 'customer_name' ) ), sanitize_email( $request->get_param( 'customer_email' ) ),
			sanitize_text_field( $request->get_param( 'customer_phone' ) ), sanitize_textarea_field( $request->get_param( 'address' ) ), sanitize_textarea_field( $request->get_param( 'notes' ) ),
			$this->pickup_binding_from_request( $request ) );
		// Configuration drift cannot erase already captured payment evidence.
		$binding['square_mode'] = (string) $metadata['square_mode'];
		if ( ! hash_equals( (string) $metadata['binding_hash'], $this->square_binding_hash( $binding ) ) ) { return $invalid; }
		$pending = new WP_Error( 'doughboss_pay_incomplete', __( 'Your payment is awaiting secure confirmation or staff reconciliation. Please do not pay again; retry this same page or contact the shop.', 'doughboss' ), array( 'status' => 503, 'payment_pending' => true, 'retry_safe' => false ) );
		$snapshot = DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( (string) $attempt['checkout_key'] );
		if ( ! is_array( $snapshot ) || ! isset( $snapshot['payload']['order'], $snapshot['payload']['lines'], $snapshot['payload']['binding_hash'] ) ) { return $pending; }
		$saved = $snapshot['payload']['order'];
		if ( ! is_array( $saved ) || ! is_array( $snapshot['payload']['lines'] )
			|| ! isset( $saved['checkout_key'], $saved['total'], $saved['currency'], $saved['order_type'], $saved['location_id'], $saved['table_id'], $saved['table_qr_code_id'] )
			|| ! hash_equals( (string) $attempt['checkout_key'], (string) $snapshot['checkout_key'] )
			|| ! hash_equals( (string) $attempt['checkout_key'], (string) $saved['checkout_key'] )
			|| ! hash_equals( (string) $metadata['binding_hash'], (string) $snapshot['payload']['binding_hash'] )
			|| (int) $attempt['amount_minor'] !== DoughBoss_Payment::to_minor_units( $saved['total'] )
			|| strtoupper( (string) $attempt['currency'] ) !== strtoupper( (string) $saved['currency'] )
			|| (int) $attempt['location_id'] !== (int) $saved['location_id'] || (int) $saved['location_id'] !== $location
			|| (string) $saved['order_type'] !== $type
			|| (int) $attempt['table_id'] !== (int) $saved['table_id'] || (int) $attempt['qr_code_id'] !== (int) $saved['table_qr_code_id']
			|| is_wp_error( DoughBoss_Order::accounting_basis_from_payload( $saved ) ) ) { return $pending; }
		if ( 'succeeded' !== (string) $attempt['status'] || 'COMPLETED' !== strtoupper( (string) $attempt['provider_status'] ) ) { return $pending; }

		// Serialize with the final ordinary order writer. Once saved, the hold is
		// terminal even if an operator later restores legacy checkout policy.
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $pending; }
		$held = false;
		try {
			$locked = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'doughboss_payment_attempts WHERE id = %d LIMIT 1 FOR UPDATE', (int) $attempt['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			if ( ! is_array( $locked ) || ! empty( $wpdb->last_error ) ) { return $pending; }
			foreach ( array( 'provider', 'purpose', 'context', 'checkout_key', 'provider_reference', 'amount_minor', 'currency', 'location_id', 'table_id', 'qr_code_id', 'safe_metadata_json', 'status', 'provider_status' ) as $field ) {
				if ( (string) $locked[ $field ] !== (string) $attempt[ $field ] ) { return $pending; }
			}
			if ( DoughBoss_Order::payment_intent_used( (string) $attempt['provider_reference'] ) || ! empty( $wpdb->last_error ) ) { return $pending; }
			if ( isset( $metadata['reconciliation_hold'] ) && 'staff_approval_review_required' !== $metadata['reconciliation_hold'] ) { return $pending; }
			$metadata['reconciliation_hold'] = 'staff_approval_review_required';
			$updated = DoughBoss_Payment_Attempts::metadata( $locked ) === $metadata ? $locked : DoughBoss_Payment_Attempts::update( (int) $attempt['id'], array( 'safe_metadata' => $metadata ) );
			if ( ! is_array( $updated ) || DoughBoss_Payment_Attempts::metadata( $updated ) !== $metadata ) { return $pending; }
			$held = false !== $wpdb->query( 'COMMIT' );
		} finally {
			if ( ! $held ) { $wpdb->query( 'ROLLBACK' ); }
		}
		if ( ! $held || ! $this->record_unreconciled_payment( (string) $attempt['provider_reference'], array( 'amount' => (int) $attempt['amount_minor'], 'currency' => strtolower( (string) $attempt['currency'] ) ) ) ) { return $pending; }
		return new WP_Error( 'doughboss_paid_reconciliation_required', __( 'Your paid order is awaiting secure staff reconciliation. Please do not pay again.', 'doughboss' ), array( 'status' => 409, 'payment_pending' => true, 'retry_safe' => false ) );
	}

	/**
	 * Browser return from Square-hosted checkout. The checkout reference is a
	 * lookup key only: the attempt must belong to this cart session, and the
	 * payment is proven by re-reading the bound Square Order and Payment.
	 * Unpaid returns are a pending response the storefront polls; nothing here
	 * can start or repeat a payment.
	 *
	 * @param WP_REST_Request $request      Request.
	 * @param string          $idem         Server-bound browser checkout key.
	 * @param string          $checkout_key Returned checkout reference.
	 * @return WP_REST_Response|WP_Error
	 */
	private function square_hosted_return( WP_REST_Request $request, $idem, $checkout_key ) {
		if ( $this->rate_limited( 'square_hosted_return', 40, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'doughboss_rate_limit', __( 'Too many requests. Please wait a few minutes and try again.', 'doughboss' ), array( 'status' => 429, 'payment_pending' => true, 'retry_safe' => false ) );
		}
		$unmatched = new WP_Error( 'doughboss_square_hosted_unmatched', __( 'This Square checkout could not be matched to this browser. If you paid, please do not pay again — the shop will confirm your order by email.', 'doughboss' ), array( 'status' => 409, 'payment_pending' => true, 'retry_safe' => false ) );
		if ( '' === $checkout_key ) {
			return $unmatched;
		}
		$attempt  = DoughBoss_Payment_Attempts::find_by_checkout_key( $checkout_key );
		$metadata = is_array( $attempt ) ? DoughBoss_Payment_Attempts::metadata( $attempt ) : array();
		if ( ! DoughBoss_Square_Hosted::is_hosted_attempt( $attempt ) || 'order' !== (string) $attempt['purpose']
			|| ! in_array( (string) $attempt['context'], array( 'web', 'table_qr' ), true )
			|| ! isset( $metadata['cart_guard'] ) || ! hash_equals( (string) $metadata['cart_guard'], $this->square_cart_guard() ) ) {
			return $unmatched;
		}
		$settled = $this->square_hosted_settle( $attempt );
		if ( is_wp_error( $settled ) ) {
			return $settled;
		}
		if ( 'paid' === $settled['state'] ) {
			$payload = $this->checkout_payload( $settled['order'], $settled['replayed'] );
			$this->cart->clear();
			set_transient( 'doughboss_idem_' . $idem, $payload, 6 * HOUR_IN_SECONDS );
			return rest_ensure_response( $payload );
		}
		if ( 'failed' === $settled['state'] ) {
			return new WP_Error( 'doughboss_square_hosted_closed', __( 'That Square checkout closed without payment. You have not been charged — please start payment again.', 'doughboss' ), array( 'status' => 409, 'outcome' => 'terminal_no_charge', 'payment_pending' => false, 'retry_safe' => true, 'hosted_state' => 'failed', 'attempt_id' => (int) $attempt['id'] ) );
		}
		$data = array( 'status' => 409, 'payment_pending' => true, 'retry_safe' => false, 'hosted_state' => (string) $settled['state'], 'attempt_id' => (int) $attempt['id'] );
		$url  = isset( $metadata['hosted_checkout_url'] ) ? (string) $metadata['hosted_checkout_url'] : '';
		if ( 'unpaid' === $settled['state'] && DoughBoss_Square_Hosted::checkout_url_valid( $url, isset( $metadata['square_mode'] ) ? (string) $metadata['square_mode'] : '' ) ) {
			$data['checkout_url'] = $url;
		}
		$message = 'pending' === $settled['state']
			? __( 'Square is still confirming your payment. Please keep this page open and do not pay again.', 'doughboss' )
			: __( 'We have not received a completed Square payment for this order yet. If you have just paid, keep this page open — it will check again. Otherwise you can return to the same secure Square checkout.', 'doughboss' );
		return new WP_Error( 'pending' === $settled['state'] ? 'doughboss_square_hosted_pending' : 'doughboss_square_hosted_unpaid', $message, $data );
	}

	/**
	 * Shared browser/webhook/sweep settlement of one hosted attempt.
	 *
	 * @param array $attempt Hosted attempt.
	 * @return array|WP_Error { state: paid (order, replayed)|unpaid|pending|failed }
	 */
	private function square_hosted_settle( array $attempt ) {
		$result = DoughBoss_Square_Hosted::confirm( $attempt );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 'paid' === $result['state'] ) {
			$finalized = $this->finalize_square_hosted_order( $result['attempt'], (string) $result['payment_id'] );
			if ( is_wp_error( $finalized ) ) {
				return $finalized;
			}
			return array( 'state' => 'paid', 'order' => $finalized['order'], 'replayed' => $finalized['replayed'] );
		}
		if ( 'failed' === $result['state'] ) {
			$current = $result['attempt'];
			if ( ! empty( $result['order_canceled'] ) && 'dispatching' === (string) $current['status'] && empty( $current['provider_reference'] ) ) {
				// Square cancelled the unpaid Order (link deleted or expired).
				DoughBoss_Payment_Attempts::mark_irreversible_failed( (int) $current['id'], 'square_hosted_canceled' );
			}
			$closed   = DoughBoss_Payment_Attempts::find( (int) $current['id'] );
			$metadata = is_array( $closed ) ? DoughBoss_Payment_Attempts::metadata( $closed ) : array();
			if ( ! is_array( $closed ) || ! in_array( (string) $closed['status'], array( 'failed', 'voided' ), true ) ) {
				return array( 'state' => 'pending' );
			}
			if ( ! empty( $metadata['voucher_code'] ) && ! empty( $metadata['voucher_reservation_key'] ) ) {
				$released = DoughBoss_Voucher::release_payment_reservation( (string) $metadata['voucher_code'], (string) $metadata['voucher_reservation_key'], (int) $closed['id'] );
				if ( is_wp_error( $released ) ) {
					return $released;
				}
			}
			return array( 'state' => 'failed' );
		}
		return array( 'state' => in_array( $result['state'], array( 'unpaid', 'pending' ), true ) ? $result['state'] : 'pending' );
	}

	/**
	 * Create (or replay) the one paid order for a proven hosted Square payment.
	 * Mirrors the Stripe recovery path: the immutable snapshot is the only
	 * source of items, contacts and money; DoughBoss_Order::create's unique
	 * checkout/payment keys make browser, webhook and sweep races idempotent,
	 * so the kitchen receives exactly one order and one confirmation.
	 *
	 * @param array  $attempt    Verified attempt (status succeeded/order_committed).
	 * @param string $payment_id Proven Square payment id.
	 * @return array|WP_Error { order, replayed }
	 */
	private function finalize_square_hosted_order( array $attempt, $payment_id ) {
		$pending = new WP_Error( 'doughboss_pay_finalize_pending', __( 'Your Square payment is confirmed and your order is being saved. Please do not pay again; this page will retry.', 'doughboss' ), array( 'status' => 503, 'payment_pending' => true, 'retry_safe' => false ) );
		$attempt = DoughBoss_Payment_Attempts::find( (int) $attempt['id'] );
		$payment_id = DoughBoss_Square::canonical_id( $payment_id );
		if ( ! is_array( $attempt ) || ! DoughBoss_Square_Hosted::is_hosted_attempt( $attempt ) || '' === $payment_id
			|| ! hash_equals( (string) $attempt['provider_reference'], $payment_id )
			|| ! in_array( (string) $attempt['status'], array( 'succeeded', 'order_committed' ), true )
			|| ( 'succeeded' === (string) $attempt['status'] && empty( $attempt['verified_at'] ) ) ) {
			return $pending;
		}
		$checkout_key = (string) $attempt['checkout_key'];
		$metadata     = DoughBoss_Payment_Attempts::metadata( $attempt );
		$existing_id  = DoughBoss_Order::find_id_by_payment_intent( $payment_id );
		if ( $existing_id ) {
			$existing = DoughBoss_Order::get( $existing_id );
			if ( ! $existing || 'paid' !== (string) $existing->payment_status || 'square' !== (string) $existing->payment_method
				|| ! hash_equals( (string) $existing->payment_intent_id, $payment_id )
				|| DoughBoss_Payment::to_minor_units( (float) $existing->total ) !== (int) $attempt['amount_minor']
				|| strtoupper( (string) $existing->currency ) !== strtoupper( (string) $attempt['currency'] )
				|| (int) $existing->location_id !== (int) $attempt['location_id'] ) {
				return new WP_Error( 'doughboss_receipt_basis_unverified', __( 'The saved receipt requires staff review. Please do not pay again.', 'doughboss' ), array( 'status' => 409, 'payment_pending' => true, 'retry_safe' => false ) );
			}
			DoughBoss_Checkout_Snapshots::complete( $checkout_key, (int) $existing->id );
			DoughBoss_Payment_Attempts::mark_order_committed( $payment_id, (int) $existing->id );
			return array( 'order' => $existing, 'replayed' => true );
		}
		$snapshot = DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( $checkout_key );
		$saved    = is_array( $snapshot ) && isset( $snapshot['payload']['order'] ) && is_array( $snapshot['payload']['order'] ) ? $snapshot['payload']['order'] : array();
		$lines    = is_array( $snapshot ) && isset( $snapshot['payload']['lines'] ) && is_array( $snapshot['payload']['lines'] ) ? array_values( $snapshot['payload']['lines'] ) : array();
		if ( ! $saved || ! $lines || 'prepared' !== (string) $snapshot['status'] || 0 !== (int) $snapshot['order_id']
			|| ! isset( $snapshot['payload']['binding_hash'], $metadata['binding_hash'], $metadata['orders_snapshot_hash'], $saved['checkout_key'], $saved['total'], $saved['currency'], $saved['location_id'], $saved['table_id'], $saved['table_qr_code_id'] )
			|| ! hash_equals( (string) $metadata['binding_hash'], (string) $snapshot['payload']['binding_hash'] )
			|| ! hash_equals( (string) $metadata['orders_snapshot_hash'], (string) $snapshot['payload_hash'] )
			|| ! hash_equals( $checkout_key, (string) $saved['checkout_key'] )
			|| (int) $attempt['amount_minor'] !== DoughBoss_Payment::to_minor_units( (float) $saved['total'] )
			|| strtoupper( (string) $attempt['currency'] ) !== strtoupper( (string) $saved['currency'] )
			|| (int) $attempt['location_id'] !== (int) $saved['location_id']
			|| (int) $attempt['table_id'] !== (int) $saved['table_id']
			|| (int) $attempt['qr_code_id'] !== (int) $saved['table_qr_code_id'] ) {
			return new WP_Error( 'doughboss_pay_snapshot_missing', __( 'The paid order details require manual confirmation. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409, 'payment_pending' => true, 'retry_safe' => false ) );
		}
		$order_data = $saved;
		$copied     = $this->copy_accounting_basis_from_snapshot( $saved, $order_data );
		if ( is_wp_error( $copied ) ) {
			return $copied;
		}
		$policy_error = $this->ordinary_checkout_policy_error();
		if ( is_wp_error( $policy_error ) || isset( $metadata['reconciliation_hold'] ) ) {
			// Ordering policy changed while the customer was on Square's page:
			// keep the verified money visible for staff, never auto-fulfil.
			if ( ! isset( $metadata['reconciliation_hold'] ) ) {
				$metadata['reconciliation_hold'] = 'staff_approval_review_required';
				DoughBoss_Payment_Attempts::update( (int) $attempt['id'], array( 'safe_metadata' => $metadata ) );
			}
			$this->record_unreconciled_payment( $payment_id, array( 'amount' => (int) $attempt['amount_minor'], 'currency' => strtolower( (string) $attempt['currency'] ) ) );
			return new WP_Error( 'doughboss_paid_reconciliation_required', __( 'Your paid order is awaiting secure staff reconciliation. Please do not pay again.', 'doughboss' ), array( 'status' => 409, 'payment_pending' => true, 'retry_safe' => false ) );
		}
		$voucher_code            = isset( $saved['voucher_code'] ) ? (string) $saved['voucher_code'] : '';
		$discount                = isset( $saved['discount'] ) ? (float) $saved['discount'] : 0.0;
		$voucher_reservation_key = isset( $saved['voucher_reservation_key'] ) ? strtolower( sanitize_text_field( (string) $saved['voucher_reservation_key'] ) ) : '';
		if ( '' !== $voucher_reservation_key && ! hash_equals( $checkout_key, $voucher_reservation_key ) ) {
			return new WP_Error( 'doughboss_pay_snapshot_missing', __( 'The paid order details require manual confirmation. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409, 'payment_pending' => true, 'retry_safe' => false ) );
		}
		$voucher_idem = '';
		if ( '' !== $voucher_code && $discount > 0 ) {
			$voucher_idem = 'order_' . ( '' !== $voucher_reservation_key ? $voucher_reservation_key : $checkout_key );
			$redeem       = DoughBoss_Voucher::redeem( $voucher_code, isset( $saved['subtotal'] ) ? (float) $saved['subtotal'] : 0.0, 'online', array( 'idempotency_key' => $voucher_idem, 'reservation_key' => $voucher_reservation_key ) );
			if ( is_wp_error( $redeem ) ) {
				// Keep the discount the customer already paid; leave an audit trail.
				$system_note = sprintf(
					/* translators: 1: formatted discount amount, 2: voucher code. */
					'[SYSTEM] Discount of %1$s from voucher "%2$s" was preserved on this paid Square checkout order, but its redemption needs manual reconciliation.',
					DoughBoss_Settings::format_price( $discount ),
					$voucher_code
				);
				$order_data['notes'] = ! empty( $order_data['notes'] ) ? $order_data['notes'] . "\n\n" . $system_note : $system_note;
				$voucher_idem        = '';
			} else {
				$discount = (float) $redeem['amount'];
			}
		} else {
			$discount     = 0.0;
			// A zero-value discount does not change the retained voucher identity.
		}
		$order_data['discount']          = $discount;
		$order_data['voucher_code']      = $voucher_code;
		$order_data['payment_status']    = 'paid';
		$order_data['payment_method']    = 'square';
		$order_data['payment_intent_id'] = $payment_id;
		$order_data['checkout_key']      = $checkout_key;
		$created = DoughBoss_Order::create( $order_data, $lines );
		if ( is_wp_error( $created ) ) {
			if ( '' !== $voucher_idem && '' === $voucher_reservation_key ) {
				DoughBoss_Voucher::revert_redemption( $voucher_idem, $voucher_reservation_key, $payment_id );
			}
			return $created;
		}
		$order_id = (int) $created['order_id'];
		DoughBoss_Checkout_Snapshots::complete( $checkout_key, $order_id );
		DoughBoss_Payment_Attempts::mark_order_committed( $payment_id, $order_id );
		if ( '' !== $voucher_idem && '' !== $voucher_reservation_key ) {
			DoughBoss_Voucher::redeem( $voucher_code, isset( $saved['subtotal'] ) ? (float) $saved['subtotal'] : 0.0, 'online', array( 'idempotency_key' => $voucher_idem, 'reservation_key' => $voucher_reservation_key ) );
		}
		if ( '' !== $voucher_idem ) {
			DoughBoss_Voucher::link_redemption_to_order( $voucher_idem, $order_id );
		}
		$order = DoughBoss_Order::get( $order_id );
		if ( ! $order ) {
			return $pending;
		}
		$replayed = ! empty( $created['replayed'] );
		if ( ! $replayed ) {
			$this->send_confirmation( $order );
		}
		return array( 'order' => $order, 'replayed' => $replayed );
	}

	/**
	 * WP-Cron: settle stale hosted checkouts. Paid ones are fulfilled exactly
	 * once; unpaid ones have their link deleted, attempt closed and voucher
	 * released. Anything uncertain is left for the next run or for staff.
	 *
	 * @return void
	 */
	public function square_hosted_sweep() {
		if ( ! DoughBoss_Activator::payment_storage_ready() ) {
			return;
		}
		foreach ( DoughBoss_Square_Hosted::stale_attempts() as $attempt ) {
			$settled = $this->square_hosted_settle( $attempt );
			if ( is_array( $settled ) && 'unpaid' === $settled['state'] ) {
				if ( 'retired' !== DoughBoss_Square_Hosted::retire_unpaid( $attempt ) ) {
					DoughBoss_Payment_Attempts::update( (int) $attempt['id'], array( 'last_error' => 'square_hosted_sweep_retry' ) );
				}
			} elseif ( is_wp_error( $settled ) ) {
				$held = DoughBoss_Payment_Attempts::find( (int) $attempt['id'] );
				if ( is_array( $held ) && in_array( (string) $held['status'], array( 'dispatching', 'unknown' ), true ) ) {
					// Rotate to the back of the queue so one stuck row cannot starve the sweep.
					DoughBoss_Payment_Attempts::update( (int) $held['id'], array( 'last_error' => 'square_hosted_sweep_retry' ) );
				}
				if ( is_array( $held ) && ! empty( $held['provider_reference'] ) && ! DoughBoss_Order::payment_intent_used( (string) $held['provider_reference'] ) ) {
					$this->record_unreconciled_payment( (string) $held['provider_reference'], array( 'amount' => (int) $held['amount_minor'], 'currency' => strtolower( (string) $held['currency'] ) ) );
				}
			}
		}
	}

	/**
	 * Preserve a verified ordinary Stripe return as a durable manual hold.
	 *
	 * This is entered only after ordinary checkout is blocked by the current
	 * policy. It verifies the exact Checkout Session and canonical PaymentIntent
	 * before reusing the signed-event recovery hold boundary; it never creates an
	 * ordinary order, sends confirmation, or treats the payment as unpaid.
	 *
	 * @param string $session_id Stripe Checkout Session id.
	 * @return true|WP_Error
	 */
	private function hold_stripe_return_for_reconciliation( $session_id ) {
		$expected_prefix = 'live' === DoughBoss_Settings::stripe_mode() ? 'cs_live_' : 'cs_test_';
		if ( 'stripe' !== DoughBoss_Settings::payment_gateway() || 0 !== strpos( (string) $session_id, $expected_prefix ) ) {
			return new WP_Error( 'doughboss_pay_mode_changed', __( 'This payment session cannot be verified with the shop\'s current payment configuration. Please contact the shop before trying again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$intent = DoughBoss_Stripe::retrieve_checkout_payment( $session_id );
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}
		$pi_id = isset( $intent['id'] ) ? DoughBoss_Stripe::canonical_id( $intent['id'] ) : '';
		$metadata = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
		$checkout_key = isset( $metadata['checkout_key'] ) ? strtolower( sanitize_text_field( (string) $metadata['checkout_key'] ) ) : '';
		if ( 'succeeded' !== ( isset( $intent['status'] ) ? (string) $intent['status'] : '' ) || '' === $pi_id || 1 !== preg_match( '/^[a-f0-9]{64}$/', $checkout_key ) ) {
			return new WP_Error( 'doughboss_stripe_hold_pending', __( 'The paid order is awaiting secure reconciliation. Please do not pay again.', 'doughboss' ), array( 'status' => 503 ) );
		}
		$snapshot = $this->stripe_order_snapshot( $checkout_key, $session_id, true );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$held = $this->recover_stripe_order( $pi_id, $intent, $metadata );
		if ( is_wp_error( $held ) ) {
			return $held;
		}
		if ( ! $held ) {
			return new WP_Error( 'doughboss_stripe_hold_pending', __( 'The paid order is awaiting secure reconciliation. Please do not pay again.', 'doughboss' ), array( 'status' => 503 ) );
		}
		return true;
	}

	/**
	 * Resolve a paid Stripe order without trusting now-stale cart totals.
	 *
	 * @param WP_REST_Request $request Checkout request containing the returned Session id.
	 * @param string          $idem    Server-bound browser checkout key.
	 * @return object|null|WP_Error Verified order, no existing order, or a safe error.
	 */
	private function stripe_paid_order_replay( WP_REST_Request $request, $idem ) {
		$session_id = sanitize_text_field( $request->get_param( 'payment_intent_id' ) );
		$expected_prefix = 'live' === DoughBoss_Settings::stripe_mode() ? 'cs_live_' : 'cs_test_';
		if ( 'stripe' !== DoughBoss_Settings::payment_gateway() || 0 !== strpos( $session_id, $expected_prefix ) ) {
			return new WP_Error( 'doughboss_pay_mode_changed', __( 'This payment session cannot be verified with the shop\'s current payment configuration. Please contact the shop before trying again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$intent = DoughBoss_Stripe::retrieve_checkout_payment( $session_id );
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}
		if ( 'succeeded' !== ( isset( $intent['status'] ) ? (string) $intent['status'] : '' ) ) {
			return new WP_Error( 'doughboss_pay_unverified', __( 'The payment is not confirmed. Please contact the shop before paying again.', 'doughboss' ), array( 'status' => 402 ) );
		}
		$pi_id = isset( $intent['id'] ) ? DoughBoss_Stripe::canonical_id( $intent['id'] ) : '';
		$order_id = '' !== $pi_id ? DoughBoss_Order::find_id_by_payment_intent( $pi_id ) : 0;
		$order    = $order_id ? DoughBoss_Order::get( $order_id ) : null;
		if ( ! $order || 'paid' !== (string) $order->payment_status || 'stripe' !== (string) $order->payment_method || ! hash_equals( (string) $order->payment_intent_id, $pi_id ) ) {
			return null;
		}

		$metadata     = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
		$checkout_key = isset( $metadata['checkout_key'] ) ? strtolower( sanitize_text_field( (string) $metadata['checkout_key'] ) ) : '';
		$snapshot     = $this->stripe_order_snapshot( $checkout_key, $session_id, true );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$order_data = $snapshot['payload']['order'];
		// Webhook orders use the payment key; browser orders retain their own
		// response key. A completed snapshot also proves that durable linkage.
		if ( ! hash_equals( (string) $order->checkout_key, $checkout_key ) && ! hash_equals( (string) $order->checkout_key, $idem ) && (int) $snapshot['order_id'] !== (int) $order->id ) {
			return new WP_Error( 'doughboss_pay_unverified', __( 'The paid order requires manual confirmation. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$expected_amount   = DoughBoss_Payment::to_minor_units( (float) $order_data['total'] );
		$expected_currency = strtolower( (string) $order_data['currency'] );
		$intent_amount     = isset( $intent['amount'] ) ? (int) $intent['amount'] : -1;
		$session_amount    = isset( $intent['checkout_session_amount'] ) ? (int) $intent['checkout_session_amount'] : -1;
		$intent_currency   = isset( $intent['currency'] ) ? strtolower( (string) $intent['currency'] ) : '';
		$session_currency  = isset( $intent['checkout_session_currency'] ) ? strtolower( (string) $intent['checkout_session_currency'] ) : '';
		$session_reference = isset( $intent['checkout_session_reference'] ) ? strtolower( (string) $intent['checkout_session_reference'] ) : '';
		if (
			$intent_amount !== $expected_amount
			|| $session_amount !== $expected_amount
			|| $intent_currency !== $expected_currency
			|| $session_currency !== $expected_currency
			|| ! hash_equals( $checkout_key, $session_reference )
			|| 'order' !== ( isset( $metadata['purpose'] ) ? sanitize_key( (string) $metadata['purpose'] ) : '' )
			|| (string) $order_data['order_type'] !== ( isset( $metadata['order_type'] ) ? (string) $metadata['order_type'] : '' )
			|| (int) $order_data['location_id'] !== ( isset( $metadata['location_id'] ) ? (int) $metadata['location_id'] : -1 )
			|| (int) $order_data['table_id'] !== ( isset( $metadata['table_id'] ) ? (int) $metadata['table_id'] : 0 )
			|| (int) $order_data['table_qr_code_id'] !== ( isset( $metadata['qr_code_id'] ) ? (int) $metadata['qr_code_id'] : 0 )
			|| (string) $order->order_type !== (string) $order_data['order_type']
			|| (int) $order->location_id !== (int) $order_data['location_id']
			|| (int) $order->table_id !== (int) $order_data['table_id']
		) {
			return new WP_Error( 'doughboss_pay_unverified', __( 'The paid order requires manual confirmation. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$contacts = $this->verify_stripe_snapshot_contacts( $request, $snapshot );
		if ( is_wp_error( $contacts ) ) {
			return $contacts;
		}
		$finalized = $this->finalize_stripe_order( $snapshot, $order, $pi_id );
		if ( is_wp_error( $finalized ) ) {
			return $finalized;
		}
		return $order;
	}

	/**
	 * Load Stripe's existing immutable snapshot and its matching attempt.
	 * Ordinary reads enforce snapshot expiry. A caller that has already verified
	 * an irreversible Stripe result may opt into the bounded seven-day recovery
	 * lookup; that path still requires the exact durable Stripe Session binding.
	 *
	 * @param string $checkout_key Payment checkout identity.
	 * @param string $session_id   Verified browser Session, when available.
	 * @param bool   $paid_recovery Whether an irreversible paid result was verified.
	 * @return array|WP_Error
	 */
	private function stripe_order_snapshot( $checkout_key, $session_id = '', $paid_recovery = false ) {
		$invalid = new WP_Error( 'doughboss_pay_snapshot_missing', __( 'The paid order details require manual confirmation. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		$attempt = DoughBoss_Payment_Attempts::find_by_checkout_key( $checkout_key );
		$snapshot = $paid_recovery
			? DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( $checkout_key )
			: DoughBoss_Checkout_Snapshots::find( $checkout_key );
		if ( ! is_array( $attempt ) || ! is_array( $snapshot ) || empty( $snapshot['payload']['order'] ) || ! is_array( $snapshot['payload']['order'] ) || empty( $snapshot['payload']['lines'] ) || ! is_array( $snapshot['payload']['lines'] ) ) {
			return $invalid;
		}
		$saved = $snapshot['payload']['order'];
		$basis = DoughBoss_Order::accounting_basis_from_payload( $saved );
		if ( is_wp_error( $basis ) ) {
			return $invalid;
		}
		foreach ( array( 'checkout_key', 'order_type', 'location_id', 'table_id', 'table_qr_code_id', 'total', 'currency', 'customer_name', 'customer_email', 'customer_phone', 'address', 'notes' ) as $field ) {
			if ( ! isset( $saved[ $field ] ) || ! is_scalar( $saved[ $field ] ) ) {
				return $invalid;
			}
		}
		if (
			'stripe' !== (string) $attempt['provider'] || 'order' !== (string) $attempt['purpose']
			|| ! hash_equals( (string) $attempt['checkout_key'], $checkout_key )
			|| ! hash_equals( (string) $snapshot['checkout_key'], $checkout_key )
			|| ! hash_equals( (string) $saved['checkout_key'], $checkout_key )
			|| ( '' !== $session_id && ! hash_equals( (string) $attempt['provider_reference'], $session_id ) )
			|| ! is_numeric( $saved['total'] ) || (float) $saved['total'] <= 0
			|| (int) $attempt['amount_minor'] !== DoughBoss_Payment::to_minor_units( (float) $saved['total'] )
			|| 1 !== preg_match( '/^[A-Za-z]{3}$/', (string) $saved['currency'] )
			|| strtoupper( (string) $attempt['currency'] ) !== strtoupper( (string) $saved['currency'] )
			|| (int) $attempt['location_id'] !== (int) $saved['location_id']
			|| (int) $attempt['table_id'] !== (int) $saved['table_id']
			|| (int) $attempt['qr_code_id'] !== (int) $saved['table_qr_code_id']
		) {
			return $invalid;
		}
		return $snapshot;
	}

	/**
	 * Reject post-payment contact changes without rewriting the paid snapshot.
	 *
	 * @param WP_REST_Request $request  Browser return.
	 * @param array           $snapshot Validated Stripe snapshot.
	 * @return true|WP_Error
	 */
	private function verify_stripe_snapshot_contacts( WP_REST_Request $request, array $snapshot ) {
		$contacts = array(
			'customer_name'  => sanitize_text_field( $request->get_param( 'customer_name' ) ),
			'customer_email' => strtolower( sanitize_email( $request->get_param( 'customer_email' ) ) ),
			'customer_phone' => sanitize_text_field( $request->get_param( 'customer_phone' ) ),
			'address'        => sanitize_textarea_field( $request->get_param( 'address' ) ),
			'notes'          => sanitize_textarea_field( $request->get_param( 'notes' ) ),
		);
		foreach ( $contacts as $field => $value ) {
			$saved = (string) $snapshot['payload']['order'][ $field ];
			$saved = 'customer_email' === $field ? strtolower( $saved ) : $saved;
			if ( ! hash_equals( $saved, $value ) ) {
				return new WP_Error( 'doughboss_pay_attempt_changed', __( 'The order details changed after payment started. Restore the original details or contact the shop; do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
			}
		}
		return true;
	}

	/**
	 * Repair Stripe bookkeeping for one verified, durable paid order.
	 * Never creates an order, calls a provider, or sends a notification.
	 *
	 * @param array  $snapshot Validated payment snapshot.
	 * @param object $order    Durable order row.
	 * @param string $pi_id    Verified canonical PaymentIntent identity.
	 * @return true|WP_Error Retryable storage/binding failure.
	 */
	private function finalize_stripe_order( array $snapshot, $order, $pi_id ) {
		$pending = new WP_Error( 'doughboss_pay_finalize_pending', __( 'Your paid order is saved but its confirmation records are still being linked. Retry this confirmation or contact the shop; do not pay again.', 'doughboss' ), array( 'status' => 503 ) );
		$key     = (string) $snapshot['checkout_key'];
		$saved   = $snapshot['payload']['order'];
		$attempt = DoughBoss_Payment_Attempts::find_by_checkout_key( $key );
		if (
			! $order || ! $attempt || 'stripe' !== (string) $attempt['provider'] || 'order' !== (string) $attempt['purpose']
			|| 'paid' !== (string) $order->payment_status || 'stripe' !== (string) $order->payment_method
			|| '' === $pi_id || ! hash_equals( (string) $order->payment_intent_id, $pi_id )
			|| DoughBoss_Payment::to_minor_units( (float) $order->total ) !== DoughBoss_Payment::to_minor_units( (float) $saved['total'] )
			|| strtoupper( (string) $order->currency ) !== strtoupper( (string) $saved['currency'] )
			|| (string) $order->order_type !== (string) $saved['order_type']
			|| (int) $order->location_id !== (int) $saved['location_id'] || (int) $order->table_id !== (int) $saved['table_id']
			|| (int) $order->table_qr_code_id !== (int) $saved['table_qr_code_id']
			|| ! DoughBoss_Order::accounting_basis_matches_payload( $order, $saved )
			|| ( isset( $saved['source_request_id'] ) && (int) $order->source_request_id !== (int) $saved['source_request_id'] )
			|| ( ! empty( $snapshot['order_id'] ) && (int) $snapshot['order_id'] !== (int) $order->id )
			|| ( ! empty( $attempt['local_reference'] ) && ! hash_equals( (string) $attempt['local_reference'], (string) $order->order_number ) )
		) {
			return $pending;
		}
		if ( ! DoughBoss_Checkout_Snapshots::complete( $key, (int) $order->id ) ) {
			return $pending;
		}
		// A verified payment may be finalizing after the ordinary customer
		// deadline. Re-read through the same bounded irreversible-payment path
		// used to recover it, rather than mistaking the completed row for missing.
		$completed = DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( $key );
		if ( ! $completed || 'completed' !== (string) $completed['status'] || (int) $completed['order_id'] !== (int) $order->id ) {
			return $pending;
		}
		$updated = DoughBoss_Payment_Attempts::update(
			(int) $attempt['id'],
			array( 'status' => 'succeeded', 'provider_status' => 'succeeded', 'local_reference' => (string) $order->order_number, 'verified_at' => true )
		);
		if ( ! $updated || 'succeeded' !== (string) $updated['status'] || 'succeeded' !== (string) $updated['provider_status'] || empty( $updated['verified_at'] ) || ! hash_equals( (string) $updated['local_reference'], (string) $order->order_number ) ) {
			return $pending;
		}
		if ( ! empty( $saved['source_request_id'] ) ) {
			$approval = DoughBoss_Preorder_Approval::get( (int) $saved['source_request_id'] );
			if ( is_wp_error( $approval ) || 'released' !== (string) $approval['state'] || (int) $approval['fulfillment_order_id'] !== (int) $order->id ) {
				return $pending;
			}
		}
		return true;
	}

	/**
	 * Read and normalise the checkout idempotency key (header or param).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|WP_Error Server-bound key or validation error.
	 */
	private function idempotency_key( WP_REST_Request $request ) {
		$key = $request->get_header( 'Idempotency-Key' );
		if ( ! $key ) {
			$key = $request->get_param( 'idempotency_key' );
		}
		$key = is_string( $key ) ? trim( $key ) : '';
		$length = strlen( $key );
		if ( $length < 16 || $length > 191 ) {
			return new WP_Error( 'doughboss_idempotency_required', __( 'Checkout needs a valid attempt key. Refresh the page and try again.', 'doughboss' ), array( 'status' => 400 ) );
		}
		return hash_hmac( 'sha256', $this->cart->get_token() . '|' . $key, wp_salt( 'auth' ) );
	}

	/**
	 * Build the stable response used for both a new order and a replay.
	 *
	 * @param object $order    Order row.
	 * @param bool   $replayed Whether this response replayed an existing order.
	 * @return array
	 */
	private function checkout_payload( $order, $replayed ) {
		$payload = array(
			'success'      => true,
			'order_number' => $order->order_number,
			'total'        => (float) $order->total,
			'replayed'     => (bool) $replayed,
			'message'      => __( 'Thanks! Your order has been received.', 'doughboss' ),
			'tracking_url' => DoughBoss_Settings::tracking_page_url( $order->order_number ),
		);
		if ( isset( $order->order_type ) && 'dine_in' === $order->order_type ) {
			$location                 = isset( $order->location_id ) ? DoughBoss_Locations::get( (int) $order->location_id ) : null;
			$payload['table_label']   = isset( $order->table_label ) ? $order->table_label : '';
			$payload['location_id']   = isset( $order->location_id ) ? (int) $order->location_id : 0;
			$payload['location_name'] = $location && isset( $location->name ) ? $location->name : '';
			$payload['message']       = __( 'Thanks! We received your table order and will bring it to you.', 'doughboss' );
		}
		if ( isset( $order->pickup_mode ) && '' !== DoughBoss_Order::sanitize_pickup_mode( $order->pickup_mode ) ) {
			$pickup                           = DoughBoss_Order::pickup_fields( $order, true );
			$payload['pickup_mode']           = $pickup['pickup_mode'];
			$payload['pickup_label']          = $pickup['pickup_label'];
			$payload['location_name']         = $pickup['location_name'];
			$payload['promised_ready_from_utc'] = ! empty( $order->promised_ready_from_utc ) ? str_replace( ' ', 'T', (string) $order->promised_ready_from_utc ) . 'Z' : '';
			$payload['timezone']              = ! empty( $order->timezone_snapshot ) ? (string) $order->timezone_snapshot : 'Australia/Sydney';
		}
		return $payload;
	}

	/**
	 * Resolve the authoritative table context from its HttpOnly session cookie.
	 *
	 * No cookie means an ordinary pickup/delivery visit. An invalid, expired,
	 * rotated, or inactive context fails closed instead of silently falling back.
	 *
	 * @return array|null|WP_Error
	 */
	private function table_order_context() {
		if ( ! DoughBoss_Table_QR::has_session_cookie() ) {
			return null;
		}
		return DoughBoss_Table_QR::current_context( $this->cart->get_token() );
	}

	/**
	 * Resolve one public native pickup location for approval-required intake.
	 *
	 * This deliberately does not reuse the ordinary resolver: a multi-shop
	 * approval request cannot infer a branch, coerce an alias, or route to an
	 * unannounced future location. The persisted integer is then protected by
	 * the order layer's transactional active/pickup recheck.
	 *
	 * @param mixed $requested_id Submitted native location id.
	 * @return int|WP_Error
	 */
	private function resolve_staff_approval_request_location( $requested_id ) {
		$location_id = $this->canonical_native_location_id( $requested_id );
		if ( false === $location_id ) {
			return new WP_Error( 'doughboss_location_required', __( 'Please choose an active pickup shop.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$location = null;
		foreach ( DoughBoss_Locations::all( true ) as $candidate ) {
			if ( $candidate && isset( $candidate->id ) && $location_id === (int) $candidate->id ) {
				$location = $candidate;
				break;
			}
		}
		if ( ! $location || empty( $location->pickup_enabled ) ) {
			return new WP_Error( 'doughboss_location_required', __( 'Please choose an active pickup shop.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$slug = isset( $location->slug ) ? (string) $location->slug : '';
		if ( ! in_array( $slug, array( 'revesby', 'bankstown', 'roselands' ), true ) ) {
			return new WP_Error( 'doughboss_location_required', __( 'Please choose an active pickup shop.', 'doughboss' ), array( 'status' => 400 ) );
		}
		return $location_id;
	}

	/**
	 * Resolve the launch-only location for an after-hours request.
	 *
	 * The normal location resolver still guards active-store routing. This extra
	 * name/slug check prevents a closed-site request from silently reaching a
	 * future location before its own overnight review process is configured.
	 *
	 * @param mixed $requested_id Requested location id.
	 * @return int|WP_Error
	 */
	private function resolve_revesby_preorder_location( $requested_id ) {
		$location_id = $this->resolve_order_location( $requested_id, 'pickup' );
		if ( is_wp_error( $location_id ) ) {
			return $location_id;
		}
		$location = DoughBoss_Locations::get( $location_id );
		$slug     = $location && isset( $location->slug ) ? sanitize_title( $location->slug ) : '';
		$name     = $location && isset( $location->name ) ? sanitize_title( $location->name ) : '';
		if ( 'revesby' !== $slug && 'revesby' !== $name ) {
			return new WP_Error( 'doughboss_preorder_location', __( 'After-hours pre-order requests are available from Revesby only.', 'doughboss' ), array( 'status' => 409 ) );
		}
		return $location_id;
	}

	/**
	 * Resolve and validate the active shop for a customer order.
	 *
	 * A sole active shop is selected automatically. Sites with multiple active
	 * shops must send an explicit location id so a stale client cannot route a
	 * paid order to an arbitrary kitchen. Per-location fulfilment flags are
	 * enforced in addition to the global settings.
	 *
	 * @param mixed  $requested_id Requested location id.
	 * @param string $order_type   pickup or delivery.
	 * @return int|WP_Error
	 */
	private function resolve_order_location( $requested_id, $order_type ) {
		$active = DoughBoss_Post_Types::availability_locations();
		$count  = count( $active );

		if ( 0 === $count ) {
			return new WP_Error( 'doughboss_location_required', __( 'Please choose an active shop.', 'doughboss' ), array( 'status' => 400 ) );
		}

		if ( 1 === $count && null === $requested_id ) {
			$location = $active[0];
		} else {
			$requested_id = $this->resolve_menu_location( $requested_id );
			if ( is_wp_error( $requested_id ) ) { return $requested_id; }
			$location = DoughBoss_Locations::get( $requested_id );
		}

		if ( 'delivery' === $order_type && empty( $location->delivery_enabled ) ) {
			return new WP_Error( 'doughboss_location_no_delivery', __( 'Delivery is not available from that location.', 'doughboss' ), array( 'status' => 400 ) );
		}
		if ( 'pickup' === $order_type && empty( $location->pickup_enabled ) ) {
			return new WP_Error( 'doughboss_location_no_pickup', __( 'Pickup is not available from that location.', 'doughboss' ), array( 'status' => 400 ) );
		}

		return (int) $location->id;
	}

	/** Resolve only an exact native ID in the active public shop set. */
	private function resolve_menu_location( $requested_id ) {
		$id = $this->canonical_native_location_id( $requested_id );
		foreach ( DoughBoss_Post_Types::availability_locations() as $location ) {
			if ( false !== $id && $id === (int) $location->id ) { return $id; }
		}
		return new WP_Error( 'doughboss_location_required', __( 'Please choose an active shop.', 'doughboss' ), array( 'status' => 400 ) );
	}

	/** Recheck saved menu lines; never remove a line or change its shared price. */
	private function menu_lines_availability_error( array $lines, $location_id ) {
		$location = $this->resolve_menu_location( $location_id );
		if ( is_wp_error( $location ) ) { return $location; }
		if ( empty( $lines ) ) { return new WP_Error( 'doughboss_empty', __( 'Your cart is empty.', 'doughboss' ), array( 'status' => 400 ) ); }
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) || ! isset( $line['item_id'] ) ) {
				return new WP_Error( 'doughboss_no_item', __( 'Please review your cart before ordering.', 'doughboss' ), array( 'status' => 409 ) );
			}
			// Persisted order item rows do not retain the transient cart's type.
			$type = isset( $line['type'] ) ? $line['type'] : ( (int) $line['item_id'] > 0 ? 'menu' : 'custom' );
			if ( 'custom' === $type && in_array( $line['item_id'], array( 0, '0' ), true ) && ! empty( $line['size'] ) ) { continue; }
			$item_id = $this->canonical_native_location_id( $line['item_id'] );
			$post = 'menu' === $type && false !== $item_id ? get_post( $item_id ) : null;
			if ( ! $post || DoughBoss_Post_Types::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status
				|| ! DoughBoss_Post_Types::is_available_at( $post->ID, $location ) ) {
				return new WP_Error( 'doughboss_sold_out', __( 'An item in your cart is unavailable at the selected shop. Please review your cart or choose another shop.', 'doughboss' ), array( 'status' => 409 ) );
			}
		}
		return null;
	}

	/** A recorded initiated attempt may reconcile, never authorize a fresh charge. */
	private function availability_replay_attempt( $attempt, $location_id ) {
		return is_int( $location_id ) && $location_id > 0 && is_array( $attempt ) && isset( $attempt['status'], $attempt['location_id'] )
			&& $this->canonical_native_location_id( $attempt['location_id'] ) === $location_id
			&& in_array( $attempt['status'], array( 'provisioning', 'dispatching', 'processing', 'unknown', 'succeeded', 'mismatch', 'order_committed' ), true );
	}

	/**
	 * New ordinary orders at A$300+ in discounted GST-inclusive food/drink
	 * require an explicitly selected Revesby shop. The server total already
	 * includes GST and discounts; remove fees, not tax or payment tenders.
	 *
	 * @return WP_Error|null Never changes the chosen shop or any stored facts.
	 */
	private function large_order_routing_error( array $totals, $location_id, $currency = 'AUD' ) {
		$invalid = new WP_Error( 'doughboss_order_routing_amount', __( 'The order amount could not be checked. Please refresh your cart before continuing.', 'doughboss' ), array( 'status' => 409 ) );
		if ( ! is_string( $currency ) || 'AUD' !== strtoupper( trim( $currency ) ) ) {
			return new WP_Error( 'doughboss_order_routing_currency', __( 'Order routing is available in Australian dollars only. Please contact the shop before continuing.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( PHP_INT_SIZE < 8 ) { return $invalid; }
		$amounts = array();
		foreach ( array( 'total', 'delivery_fee', 'service_fee' ) as $field ) {
			if ( ! array_key_exists( $field, $totals ) ) {
				if ( 'service_fee' === $field ) { $amounts[$field] = 0; continue; }
				return $invalid;
			}
			$value = $totals[$field];
			if ( is_int( $value ) ) {
				$value = (string) $value;
			} elseif ( is_float( $value ) ) {
				if ( ! is_finite( $value ) || $value < 0 || $value > 99999999.99 ) { return $invalid; }
				$decimal = sprintf( '%.2F', $value );
				if ( $value !== (float) $decimal ) { return $invalid; }
				$value = $decimal;
			}
			if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?\z/', $value, $parts ) ) { return $invalid; }
			$amounts[$field] = (int) $parts[1] * 100 + ( isset( $parts[2] ) ? (int) str_pad( $parts[2], 2, '0' ) : 0 );
		}
		$fees = $amounts['delivery_fee'] + $amounts['service_fee'];
		if ( $fees > $amounts['total'] ) { return $invalid; }
		if ( $amounts['total'] - $fees < 30000 ) { return null; }
		$id = $this->canonical_native_location_id( $location_id );
		$location = false !== $id ? DoughBoss_Locations::get( $id ) : null;
		if ( $location && isset( $location->id, $location->slug ) && $id === (int) $location->id && ! empty( $location->is_active ) && 'revesby' === $location->slug ) { return null; }
		return new WP_Error( 'doughboss_large_order_revesby', __( 'Orders with A$300 or more in food and drink after discounts, including GST but excluding delivery and service fees, must go through Revesby. Please choose Revesby and review your pickup details, or contact the shop if it is unavailable.', 'doughboss' ), array( 'status' => 409 ) );
	}

	private function payment_availability_error( array $lines, $location_id, $checkout_key, array $totals, $currency = 'AUD' ) {
		$attempt = DoughBoss_Payment_Attempts::find_by_checkout_key( $checkout_key );
		if ( $this->availability_replay_attempt( $attempt, $location_id ) ) { return null; }
		$routing_error = $this->large_order_routing_error( $totals, $location_id, $currency );
		if ( is_wp_error( $routing_error ) ) { return $routing_error; }
		return $this->menu_lines_availability_error( $lines, $location_id );
	}

	/**
	 * Verify a payment before an order is trusted as paid.
	 *
	 * Confirms the payment succeeded (for gateways that require it — e.g.
	 * Tyro — this is also where the charge is actually submitted; see
	 * DoughBoss_Tyro::retrieve_payment_intent()), that its amount and currency
	 * match this order's server-computed total, that its order_type/location_id
	 * metadata match the order actually being placed (a stale client can't reuse
	 * a payment verified for one fulfilment type/shop against another), and that
	 * it has not already been used for another order. Returns the payment
	 * reference id on success.
	 *
	 * @param WP_REST_Request $request        Request.
	 * @param float           $expected_total Server-computed order total.
	 * @param string          $order_type     Expected fulfilment type.
	 * @param int             $location_id    Expected shop id.
	 * @param array|null      $table_context  Authoritative table session, if any.
	 * @return string|array|WP_Error Payment reference, verified snapshot context, or an error.
	 */
	private function verify_payment( WP_REST_Request $request, $expected_total, $order_type, $location_id, $table_context = null ) {
		$raw_id = $request->get_param( 'payment_intent_id' );
		$raw_id = is_scalar( $raw_id ) ? sanitize_text_field( (string) $raw_id ) : '';
		if ( '' === $raw_id ) {
			return new WP_Error( 'doughboss_pay_required', __( 'Payment is required to place this order.', 'doughboss' ), array( 'status' => 402 ) );
		}

		// The value stored on the order row (and checked for reuse) is the
		// gateway's CANONICAL reference — for Stripe this equals $raw_id; for
		// Tyro, $raw_id additionally carries a client-side session id needed
		// only to actually submit the charge below. A webhook payload can never
		// carry that session id, so storing anything other than the canonical
		// id here would make webhook-based reconciliation permanently unable to
		// find this order. See DoughBoss_Tyro::canonical_id().
		$is_stripe_checkout = 1 === preg_match( '/^cs_(?:test|live)_[A-Za-z0-9_]{8,191}$/', $raw_id );
		$stored_attempt     = class_exists( 'DoughBoss_Payment_Attempts' ) ? DoughBoss_Payment_Attempts::find_by_provider_reference( $raw_id ) : null;
		if ( is_array( $stored_attempt ) ) {
			$stored_metadata = DoughBoss_Payment_Attempts::metadata( $stored_attempt );
			if ( isset( $stored_metadata['reconciliation_hold'] ) && 'staff_approval_review_required' === (string) $stored_metadata['reconciliation_hold'] ) {
				return new WP_Error( 'doughboss_paid_reconciliation_required', __( 'This paid order is awaiting secure staff reconciliation. Please do not pay again.', 'doughboss' ), array( 'status' => 409, 'payment_pending' => true ) );
			}
		}
		$is_square_attempt  = is_array( $stored_attempt ) && 'square' === (string) $stored_attempt['provider'];
		if ( $is_stripe_checkout ) {
			$expected_prefix = 'live' === DoughBoss_Settings::stripe_mode() ? 'cs_live_' : 'cs_test_';
			if ( 'stripe' !== DoughBoss_Settings::payment_gateway() || 0 !== strpos( $raw_id, $expected_prefix ) ) {
				return new WP_Error( 'doughboss_pay_mode_changed', __( 'This payment session cannot be verified with the shop\'s current payment configuration. Please contact the shop before trying again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			$intent = DoughBoss_Stripe::retrieve_checkout_payment( $raw_id );
			$pi_id  = ! is_wp_error( $intent ) && isset( $intent['id'] ) ? DoughBoss_Stripe::canonical_id( $intent['id'] ) : '';
		} elseif ( $is_square_attempt ) {
			$pi_id  = DoughBoss_Square::canonical_id( $raw_id );
			$intent = DoughBoss_Square::retrieve_payment_intent( $raw_id );
		} else {
			if ( ! DoughBoss_Payment::ready() ) {
				return new WP_Error( 'doughboss_pay_off', __( 'This payment cannot be verified while card payments are unavailable. Please contact the shop before trying again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			$pi_id  = DoughBoss_Payment::canonical_id( $raw_id );
			$intent = DoughBoss_Payment::retrieve_payment_intent( $raw_id );
		}
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}
		if ( '' === $pi_id ) {
			return new WP_Error( 'doughboss_pay_unverified', __( 'We could not verify your card payment. If you were charged, please contact us and we will sort out a refund.', 'doughboss' ), array( 'status' => 402 ) );
		}

		$expected = DoughBoss_Payment::to_minor_units( $expected_total );
		$currency = strtolower( (string) DoughBoss_Settings::get( 'currency_code', 'AUD' ) );
		$status   = isset( $intent['status'] ) ? $intent['status'] : '';
		$amount   = isset( $intent['amount'] ) ? (int) $intent['amount'] : 0;
		$cur      = isset( $intent['currency'] ) ? strtolower( $intent['currency'] ) : '';
		$metadata      = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
		$meta_type     = isset( $metadata['order_type'] ) ? sanitize_key( $metadata['order_type'] ) : '';
		$meta_location = isset( $metadata['location_id'] ) ? absint( $metadata['location_id'] ) : -1;
		$meta_table    = isset( $metadata['table_id'] ) ? absint( $metadata['table_id'] ) : 0;
		$meta_qr       = isset( $metadata['qr_code_id'] ) ? absint( $metadata['qr_code_id'] ) : 0;
		$meta_checkout = isset( $metadata['checkout_key'] ) ? strtolower( sanitize_text_field( $metadata['checkout_key'] ) ) : '';
		$expected_table = $table_context ? (int) $table_context['table_id'] : 0;
		$expected_qr    = $table_context ? (int) $table_context['qr_code_id'] : 0;
		$square_snapshot = null;
		if ( $is_square_attempt ) {
			$attempt_metadata = DoughBoss_Payment_Attempts::metadata( $stored_attempt );
			if ( in_array( $status, array( 'failed', 'voided' ), true ) ) {
				if ( ! empty( $attempt_metadata['voucher_code'] ) && ! empty( $attempt_metadata['voucher_reservation_key'] ) ) {
					$released = DoughBoss_Voucher::release_payment_reservation(
						(string) $attempt_metadata['voucher_code'],
						(string) $attempt_metadata['voucher_reservation_key'],
						(int) $stored_attempt['id']
					);
					if ( is_wp_error( $released ) ) {
						return new WP_Error( 'doughboss_voucher_payment_storage', __( 'The card was not charged, but the voucher is still being released. Please wait and try again.', 'doughboss' ), array( 'status' => 503, 'outcome' => 'terminal_no_charge', 'retry_safe' => false, 'payment_pending' => false, 'attempt_id' => (int) $stored_attempt['id'] ) );
					}
				}
				return new WP_Error(
					'doughboss_pay_declined',
					__( 'That card payment was not approved. Please try another card.', 'doughboss' ),
					array( 'status' => 402, 'outcome' => 'terminal_no_charge', 'retry_safe' => true, 'payment_pending' => false, 'attempt_id' => (int) $stored_attempt['id'] )
				);
			}
			if ( in_array( $status, array( 'processing', 'unknown' ), true ) ) {
				return new WP_Error(
					'doughboss_pay_incomplete',
					__( 'Your payment is still being confirmed. Please do not pay again; use this page to check it or contact the shop.', 'doughboss' ),
					array( 'status' => 409, 'outcome' => $status, 'retry_safe' => false, 'payment_pending' => true, 'payment_intent' => $pi_id, 'attempt_id' => (int) $stored_attempt['id'] )
				);
			}
			if ( 'square-v2' !== ( isset( $attempt_metadata['protocol_version'] ) ? (string) $attempt_metadata['protocol_version'] : '' ) ) {
				return new WP_Error( 'doughboss_pay_legacy_locked', __( 'This payment cannot be completed automatically. Please contact the shop before paying again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			$square_snapshot = DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( (string) $stored_attempt['checkout_key'] );
			if ( ! is_array( $square_snapshot ) || ! isset( $square_snapshot['payload']['order'], $square_snapshot['payload']['lines'], $square_snapshot['payload']['binding_hash'] ) ) {
				return new WP_Error( 'doughboss_pay_snapshot_missing', __( 'The paid order details require manual confirmation. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			$current_binding = $this->square_binding_payload(
				$order_type,
				$location_id,
				$table_context,
				sanitize_text_field( $request->get_param( 'customer_name' ) ),
				sanitize_email( $request->get_param( 'customer_email' ) ),
				sanitize_text_field( $request->get_param( 'customer_phone' ) ),
				sanitize_textarea_field( $request->get_param( 'address' ) ),
				sanitize_textarea_field( $request->get_param( 'notes' ) ),
				$this->pickup_binding_from_request( $request )
			);
			$current_hash = $this->square_binding_hash( $current_binding );
			$stored_hash  = isset( $attempt_metadata['binding_hash'] ) ? (string) $attempt_metadata['binding_hash'] : '';
			if ( '' === $stored_hash || ! hash_equals( $stored_hash, $current_hash ) || ! hash_equals( $stored_hash, (string) $square_snapshot['payload']['binding_hash'] ) ) {
				return new WP_Error( 'doughboss_pay_attempt_changed', __( 'The order details changed after payment started. Restore the original details or contact the shop; do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			$saved_order      = $square_snapshot['payload']['order'];
			$snapshot_basis   = DoughBoss_Order::accounting_basis_from_payload( $saved_order );
			if ( is_wp_error( $snapshot_basis ) ) {
				return new WP_Error( 'doughboss_pay_snapshot_missing', __( 'The paid order details require manual confirmation. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			$expected         = DoughBoss_Payment::to_minor_units( isset( $saved_order['total'] ) ? $saved_order['total'] : 0 );
			$snapshot_currency = strtolower( isset( $saved_order['currency'] ) ? (string) $saved_order['currency'] : '' );
			if ( '' === $snapshot_currency || ! hash_equals( $currency, $snapshot_currency ) ) {
				return new WP_Error( 'doughboss_pay_currency_changed', __( 'This payment cannot be completed after the shop currency changed. Please contact the shop before trying again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			$expected_checkout = (string) $stored_attempt['checkout_key'];
		} else {
			$expected_checkout = $this->payment_checkout_key(
				$request,
				'order',
				$this->pickup_checkout_key_binding(
					array( 'cart' => $this->cart->to_array( $order_type ), 'location_id' => $location_id, 'order_type' => $order_type, 'table' => $table_context ),
					$this->pickup_binding_from_request( $request )
				)
			);
			if ( is_wp_error( $expected_checkout ) ) {
				return $expected_checkout;
			}
		}
		$session_amount    = $is_stripe_checkout && isset( $intent['checkout_session_amount'] ) ? (int) $intent['checkout_session_amount'] : $expected;
		$session_currency  = $is_stripe_checkout && isset( $intent['checkout_session_currency'] ) ? strtolower( (string) $intent['checkout_session_currency'] ) : $currency;
		$session_reference = $is_stripe_checkout && isset( $intent['checkout_session_reference'] ) ? strtolower( (string) $intent['checkout_session_reference'] ) : $expected_checkout;

		if ( 'succeeded' !== $status || $amount !== $expected || $cur !== $currency || $session_amount !== $expected || $session_currency !== $currency || ! hash_equals( $expected_checkout, $session_reference ) || $meta_type !== $order_type || $meta_location !== (int) $location_id || $meta_table !== $expected_table || $meta_qr !== $expected_qr || ! hash_equals( $expected_checkout, $meta_checkout ) ) {
			// Do not claim an automatic reversal: nothing in this plugin refunds a
			// payment automatically today. Point the customer at a human instead
			// of a false promise.
			return new WP_Error( 'doughboss_pay_unverified', __( 'We could not verify your card payment. If you were charged, please contact us and we will sort out a refund.', 'doughboss' ), array( 'status' => 402 ) );
		}

		// A signed webhook can commit the paid order before the customer's
		// browser returns from Hosted Checkout. Do not reject that legitimate
		// race here: after all immutable Stripe bindings above pass,
		// DoughBoss_Order::create() uses its unique checkout/payment keys to
		// replay the one existing order without firing notifications twice.
		if ( $is_square_attempt ) {
			return array(
				'id'       => $pi_id,
				'provider' => 'square',
				'snapshot' => $square_snapshot,
			);
		}
		if ( $is_stripe_checkout ) {
			$stripe_snapshot = $this->stripe_order_snapshot( $expected_checkout, $raw_id, true );
			if ( is_wp_error( $stripe_snapshot ) ) {
				return $stripe_snapshot;
			}
			$saved = $stripe_snapshot['payload']['order'];
			if ( 'order' !== ( isset( $metadata['purpose'] ) ? (string) $metadata['purpose'] : '' ) || DoughBoss_Payment::to_minor_units( (float) $saved['total'] ) !== $expected || strtolower( (string) $saved['currency'] ) !== $currency || (string) $saved['order_type'] !== $order_type || (int) $saved['location_id'] !== (int) $location_id || (int) $saved['table_id'] !== $expected_table || (int) $saved['table_qr_code_id'] !== $expected_qr ) {
				return new WP_Error( 'doughboss_pay_unverified', __( 'The paid order requires manual confirmation. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			$contacts = $this->verify_stripe_snapshot_contacts( $request, $stripe_snapshot );
			if ( is_wp_error( $contacts ) ) {
				return $contacts;
			}
			return array( 'id' => $pi_id, 'provider' => 'stripe', 'snapshot' => $stripe_snapshot );
		}
		return $pi_id;
	}

	/**
	 * POST /order/track — customer order tracking (email must match).
	 *
	 * The email travels in the JSON body, never a URL query string that can be
	 * retained in browser, proxy or access logs.
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
	 * Prevent tracking responses from being cached or used as a referrer.
	 *
	 * Runs after WordPress converts both successful values and WP_Error
	 * failures into a response, so valid and anti-enumeration responses receive
	 * the same privacy headers.
	 *
	 * @param WP_HTTP_Response $response REST response.
	 * @param WP_REST_Server   $server   REST server.
	 * @param WP_REST_Request  $request  REST request.
	 * @return WP_HTTP_Response
	 */
	public function protect_tracking_response( $response, $server, $request ) {
		unset( $server );
		if ( '/' . DOUGHBOSS_REST_NAMESPACE . '/order/track' !== (string) $request->get_route() ) {
			return $response;
		}
		if ( is_object( $response ) && method_exists( $response, 'header' ) ) {
			$response->header( 'Cache-Control', 'no-store, private' );
			$response->header( 'Referrer-Policy', 'no-referrer' );
		}
		return $response;
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
		// Kitchen accounts can never cancel an order, regardless of shop scope.
		// Enforce that role boundary before resolving an order so an unassigned
		// device cannot receive a weaker location error for a forbidden action.
		if ( 'cancelled' === $status && ! current_user_can( 'manage_doughboss' ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'doughboss_cancel_forbidden', __( 'A manager must cancel an order.', 'doughboss' ), array( 'status' => 403 ) );
		}
		$order    = DoughBoss_Order::get( $order_id );
		$location_access = DoughBoss_Staff_Scope::can_access_order( $order );
		if ( is_wp_error( $location_access ) ) {
			return $location_access;
		}
		if ( $order && DoughBoss_Order::is_preorder_request( $order ) ) {
			return new WP_Error( 'doughboss_preorder_decision_required', __( 'Use the pre-order review decision after calling the customer to arrange pickup timing.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$result   = DoughBoss_Order::transition(
			$order_id,
			$status,
			array(
				'expected_version' => absint( $request->get_param( 'expected_version' ) ),
				'event_key'       => sanitize_text_field( $request->get_param( 'event_key' ) ),
				'actor_type'      => 'staff',
				'actor_id'        => get_current_user_id(),
				'reason_code'     => sanitize_key( $request->get_param( 'reason_code' ) ),
			)
		);

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * GET /admin/orders — active orders for the live kitchen board.
	 *
	 * @return WP_REST_Response
	 */
	public function admin_orders( WP_REST_Request $request ) {
		$status  = sanitize_key( (string) $request->get_param( 'status' ) );
		$history = rest_sanitize_boolean( $request->get_param( 'history' ) );

		// No status/history filter: preserve the existing live-board behaviour
		// exactly (active orders only, oldest first, location-scoped).
		if ( '' === $status && ! $history ) {
			$location_id = DoughBoss_Staff_Scope::effective_read_location_id( $request->get_param( 'location_id' ) );
			if ( is_wp_error( $location_id ) ) {
				return $location_id;
			}
			return rest_ensure_response(
				array(
					'data'        => DoughBoss_Order::active_orders( 100, $location_id ),
					'server_time' => current_time( 'mysql', true ),
				)
			);
		}

		// History / status-filtered view: paginated query that can reach every
		// order, not just the live board — a KDS/kitchen tablet (verify_admin,
		// checked above for the live-board path) has no business paging
		// through completed/cancelled customer history, only the owner tier
		// does. Re-check with the stricter capability before running it.
		$manage = $this->verify_manage();
		if ( is_wp_error( $manage ) ) {
			return $manage;
		}

		$result = DoughBoss_Order::query_board(
			array(
				'status'   => $status,
				'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'page'     => max( 1, absint( $request->get_param( 'page' ) ) ),
				'per_page' => min( 200, max( 1, absint( $request->get_param( 'per_page' ) ) ) ),
			)
		);

		return rest_ensure_response(
			array(
				'data'        => $result['items'],
				'total'       => $result['total'],
				'server_time' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * GET /admin/board-summary â€” exact, PII-free live workload counts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_board_summary( WP_REST_Request $request ) {
		$location_id = DoughBoss_Staff_Scope::effective_read_location_id( $request->get_param( 'location_id' ) );
		if ( is_wp_error( $location_id ) ) {
			return $location_id;
		}

		$orders = DoughBoss_Order::kitchen_workload_counts( $location_id );
		$response = rest_ensure_response(
			array(
				'data'        => array(
					'make'            => (int) $orders['make'],
					'upcoming'        => isset( $orders['upcoming'] ) ? (int) $orders['upcoming'] : 0,
					'pass'            => (int) $orders['pass'],
					'preorder_review' => (int) $orders['preorder_review'],
					'catering'        => DoughBoss_Catering::production_queue_count( $location_id ),
				),
				'server_time' => current_time( 'mysql', true ),
			)
		);
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Vary', 'Cookie' );
		return $response;
	}

	/**
	 * POST /admin/order/{id}/ack — acknowledge a new order (silence the alert).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function admin_acknowledge( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'id' ) );
		$access = DoughBoss_Staff_Scope::can_access_order( DoughBoss_Order::get( $order_id ) );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		DoughBoss_Order::acknowledge( $order_id );
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
		$order    = DoughBoss_Order::get( $order_id );
		$location_access = DoughBoss_Staff_Scope::can_access_order( $order );
		if ( is_wp_error( $location_access ) ) {
			return $location_access;
		}
		if ( $order && DoughBoss_Order::is_preorder_request( $order ) ) {
			return new WP_Error( 'doughboss_preorder_decision_required', __( 'Use the pre-order review decision after calling the customer to arrange pickup timing.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$result   = DoughBoss_Order::transition(
			$order_id,
			'confirmed',
			array(
				'expected_version' => absint( $request->get_param( 'expected_version' ) ),
				'event_key'       => sanitize_text_field( $request->get_param( 'event_key' ) ),
				'actor_type'      => 'staff',
				'actor_id'        => get_current_user_id(),
				'eta_minutes'     => absint( $request->get_param( 'eta' ) ),
			)
		);

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /admin/order/{id}/delay — add 5-60 minutes to a timed order's promise.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_delay( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'id' ) );
		$order    = DoughBoss_Order::get( $order_id );
		$access   = DoughBoss_Staff_Scope::can_access_order( $order );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$minutes = $request->get_param( 'minutes' );
		$result  = DoughBoss_Order::delay_promise(
			$order_id,
			null === $minutes ? 10 : absint( $minutes ),
			array(
				'expected_version' => absint( $request->get_param( 'expected_version' ) ),
				'event_key'        => sanitize_text_field( (string) $request->get_param( 'event_key' ) ),
				'actor_type'       => 'staff',
				'actor_id'         => get_current_user_id(),
			)
		);

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * GET /admin/preorder-requests — unpaid requests awaiting morning review.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function admin_preorder_requests( WP_REST_Request $request ) {
		$location_id = DoughBoss_Staff_Scope::effective_read_location_id( $request->get_param( 'location_id' ) );
		if ( is_wp_error( $location_id ) ) {
			return $location_id;
		}
		$per_page    = min( 200, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$requests = DoughBoss_Order::preorder_requests( $per_page, $location_id );
		foreach ( $requests as &$preorder ) {
			$approval = DoughBoss_Preorder_Approval::get( (int) $preorder['id'] );
			$approval_state = is_wp_error( $approval ) ? 'pending' : (string) $approval['state'];
			$policy = DoughBoss_Settings::staff_approval_policy();
			$approver_id = isset( $policy['approver_id'] ) ? (int) $policy['approver_id'] : 0;
			$configured_approver = $approver_id ? get_userdata( $approver_id ) : null;
			$can_decide = DoughBoss_Settings::staff_approval_actor_valid( $policy )
				&& (int) get_current_user_id() === $approver_id
				&& ( current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ) );
			$preorder['approval_state'] = $approval_state;
			$preorder['approver_name'] = ! is_wp_error( $approval ) && '' !== trim( (string) $approval['approver_name'] )
				? (string) $approval['approver_name']
				: ( $configured_approver && isset( $configured_approver->display_name ) ? (string) $configured_approver->display_name : '' );
			$preorder['payment_link_delivery_status'] = ! is_wp_error( $approval ) ? (string) $approval['payment_link_delivery_status'] : 'not_attempted';
			$preorder['payment_link_recovery_available'] = ! is_wp_error( DoughBoss_Preorder_Approval::recover_payment_token( (int) $preorder['id'] ) );
			$preorder['decision_available'] = 'pending' === $approval_state && $can_decide;
			$preorder['pickup_selection_available'] = $preorder['decision_available'] && isset( $preorder['preorder_workflow_version'] ) && 1 === (int) $preorder['preorder_workflow_version'];
			$preorder['decision_recorded']  = 'pending' !== $approval_state;
			$preorder['timing'] = array(
				'mode'             => 'phone',
				'label'            => 'pending' === $approval_state
					? __( 'Call the customer to agree pickup timing before approving payment.', 'doughboss' )
					: __( 'This request remains outside the kitchen until Stripe payment is verified.', 'doughboss' ),
				'contact_required' => true,
			);
			$preorder['payment_action_required'] = in_array( $approval_state, array( 'approved_unpaid', 'payment_active', 'payment_held' ), true );
			$preorder['pospal_sync_deferred']     = true;
		}
		unset( $preorder );
		return rest_ensure_response(
			array(
				'data'        => $requests,
				'server_time' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * POST /admin/preorder/{id}/decision — accept or reject a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	/** Staff-only options; no capacity is held until the final decision commits. */
	public function admin_preorder_pickup_options( WP_REST_Request $request ) {
		$permission = $this->verify_preorder_approver( $request );
		if ( is_wp_error( $permission ) ) { return $permission; }
		$id = absint( $request->get_param( 'id' ) );
		$order = DoughBoss_Order::get( $id );
		$scope = DoughBoss_Staff_Scope::can_access_order( $order );
		if ( is_wp_error( $scope ) ) { return $scope; }
		$expected = absint( $request->get_param( 'expected_version' ) );
		if ( ! $order || ! $expected || (int) $order->version !== $expected || ! rest_sanitize_boolean( $request->get_param( 'contact_confirmed' ) ) ) {
			return new WP_Error( 'doughboss_preorder_revision_conflict', __( 'Refresh this request and confirm pickup timing with the customer.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$approval = DoughBoss_Preorder_Approval::get( $id );
		if ( is_wp_error( $approval ) ) { return $approval; }
		if ( 'pending' !== $approval['state'] ) {
			return new WP_Error( 'doughboss_preorder_already_decided', __( 'This request was already decided.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$options = DoughBoss_Pickup::preorder_options( $order, new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
		if ( is_wp_error( $options ) ) { return $options; }
		$response = rest_ensure_response( $options );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		return $response;
	}


	public function admin_preorder_decision( WP_REST_Request $request ) {
		// Permission callbacks run before dispatch; re-read the exact actor policy
		// immediately before a durable accept/reject attempt as well.
		$permission = $this->verify_preorder_approver( $request );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$order_id = absint( $request->get_param( 'id' ) );
		$decision = sanitize_key( $request->get_param( 'decision' ) );
		$order    = DoughBoss_Order::get( $order_id );
		$location_access = DoughBoss_Staff_Scope::can_access_order( $order );
		if ( is_wp_error( $location_access ) ) {
			return $location_access;
		}
		if ( ! $order || 'preorder_request' !== (string) $order->order_source ) {
			return new WP_Error( 'doughboss_preorder_not_found', __( 'That pre-order request is no longer awaiting review.', 'doughboss' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $decision, array( 'accept', 'reject' ), true ) ) {
			return new WP_Error( 'doughboss_preorder_decision_invalid', __( 'Choose whether to accept or reject the pre-order request.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$accepted = 'accept' === $decision;
		if ( $accepted && ! rest_sanitize_boolean( $request->get_param( 'contact_confirmed' ) ) ) {
			return new WP_Error( 'doughboss_preorder_contact_required', __( 'Call the customer and agree pickup timing before approving this unpaid pre-order request.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$actor  = wp_get_current_user();
		$result = DoughBoss_Preorder_Approval::decide(
			$order_id,
			$accepted ? 'approve' : 'reject',
			get_current_user_id(),
			$actor && isset( $actor->display_name ) ? (string) $actor->display_name : '',
			sanitize_text_field( $request->get_param( 'event_key' ) ),
			$accepted ? 'preorder_phone_confirmed' : 'preorder_rejected',
			absint( $request->get_param( 'expected_version' ) ),
			array(
				'contact_confirmed' => rest_sanitize_boolean( $request->get_param( 'contact_confirmed' ) ),
				'pickup_mode' => sanitize_key( (string) $request->get_param( 'pickup_mode' ) ),
				'ready_from_utc' => sanitize_text_field( (string) $request->get_param( 'ready_from_utc' ) ),
				'pickup_config_key' => sanitize_text_field( (string) $request->get_param( 'pickup_config_key' ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$payment_url = '';
		if ( $accepted && ! empty( $result['capability_token'] ) ) {
			$payment_url = $this->preorder_payment_link( $order_id, (string) $result['capability_token'] );
		}
		$notification = array( 'customer_status' => 'not_attempted', 'shop_status' => 'not_attempted' );
		if ( empty( $result['replayed'] ) ) {
			$notification = $this->send_preorder_decision_notification( $order, $accepted, $payment_url, isset( $result['approver_name'] ) ? (string) $result['approver_name'] : '', isset( $result['payment_token_expires_at_utc'] ) ? (string) $result['payment_token_expires_at_utc'] : '' );
		}
		$response = rest_ensure_response(
			array(
				'success'  => true,
				'decision' => $decision,
				'order'    => DoughBoss_Order::get( $order_id ),
				'approval' => $result,
				'payment_url' => $payment_url,
				'notification' => $notification,
				'payment_action_required' => $accepted,
				'kitchen_ticket_required' => false,
				'pospal_sync_deferred'    => $accepted,
				'message'  => $accepted
					? __( 'Pickup is reserved for the payment link\'s stated short window. Payment must complete before that reservation expires; the request remains unpaid and outside the kitchen until server verification.', 'doughboss' )
					: __( 'Pre-order request rejected. No payment was taken.', 'doughboss' ),
			)
		);
		if ( $accepted && '' !== $payment_url ) {
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		}
		return $response;
	}

	/**
	 * Recover or resend the existing payment capability without deciding or starting payment.
	 */
	public function admin_preorder_payment_link( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'id' ) );
		$mode     = sanitize_key( $request->get_param( 'mode' ) );
		$order    = DoughBoss_Order::get( $order_id );
		if ( ! $order || 'preorder_request' !== (string) $order->order_source || ! in_array( $mode, array( 'view', 'resend' ), true ) ) {
			return new WP_Error( 'doughboss_preorder_payment_unavailable', __( 'Payment-link recovery is not available for this request.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$token = DoughBoss_Preorder_Approval::recover_payment_token( $order_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$url = $this->preorder_payment_link( $order_id, $token );
		$approval = DoughBoss_Preorder_Approval::get( $order_id );
		$notification = array( 'customer_status' => 'not_attempted', 'shop_status' => 'not_attempted' );
		if ( 'resend' === $mode ) {
			$notification = $this->send_preorder_decision_notification( $order, true, $url, ! is_wp_error( $approval ) ? (string) $approval['approver_name'] : '', ! is_wp_error( $approval ) ? (string) $approval['payment_token_expires_at_utc'] : '' );
		}
		$response = rest_ensure_response( array( 'success' => true, 'payment_url' => $url, 'notification' => $notification ) );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		return $response;
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
				// Raw title — see the /menu handler's comment for why.
				'name'        => DoughBoss_Post_Types::plain_menu_title( $post->post_title ),
				'description' => DoughBoss_Post_Types::plain_menu_description( $post->post_excerpt ? $post->post_excerpt : $post->post_content ),
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
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_catering_quote( WP_REST_Request $request ) {
		$lines = DoughBoss_Catering::normalise_packages( (string) $request->get_param( 'packages' ) );
		if ( is_wp_error( $lines ) ) {
			return $lines;
		}
		$quote = $lines
			? DoughBoss_Catering::quote_packages(
				$lines,
				absint( $request->get_param( 'guest_count' ) ),
				sanitize_key( $request->get_param( 'order_type' ) )
			)
			: DoughBoss_Catering::quote(
				absint( $request->get_param( 'package_id' ) ),
				absint( $request->get_param( 'guest_count' ) ),
				sanitize_key( $request->get_param( 'order_type' ) )
			);
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}
		return rest_ensure_response( $quote );
	}

	/**
	 * validate_callback for the enquiry packages[] argument.
	 *
	 * @param mixed $value Raw value.
	 * @return true|WP_Error
	 */
	public function validate_catering_packages_arg( $value ) {
		$lines = DoughBoss_Catering::normalise_packages( $value );
		return is_wp_error( $lines ) ? $lines : true;
	}

	/**
	 * POST /b2b/enquiry — validate a wholesale/franchising enquiry and email it
	 * to the B2B inbox with Reply-To set to the enquirer. Nothing is stored.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_b2b_enquiry( WP_REST_Request $request ) {
		$thanks = __( 'Thanks — your enquiry has been sent. Our team will be in touch soon.', 'doughboss' );

		// Honeypot: bots fill the hidden field; accept silently without sending.
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return rest_ensure_response( array( 'success' => true, 'message' => $thanks ) );
		}
		if ( $this->rate_limited( 'b2b', 5, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'doughboss_rate_limit', __( 'Too many requests. Please try again later, or call us.', 'doughboss' ), array( 'status' => 429 ) );
		}

		$clean = DoughBoss_B2B::validate(
			array(
				'type'     => $request->get_param( 'type' ),
				'name'     => $request->get_param( 'name' ),
				'business' => $request->get_param( 'business' ),
				'email'    => $request->get_param( 'email' ),
				'phone'    => $request->get_param( 'phone' ),
				'focus'    => $request->get_param( 'focus' ),
				'message'  => $request->get_param( 'message' ),
			)
		);
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( ! DoughBoss_B2B::send( $clean ) ) {
			return new WP_Error( 'doughboss_b2b_mail', __( 'Your enquiry could not be sent right now. Please call or email us directly.', 'doughboss' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'message' => $thanks ) );
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

		// Catering is pickup only (DoughBoss_Catering::delivery_enabled()).
		$order_type = sanitize_key( (string) $request->get_param( 'order_type' ) );
		if ( 'delivery' === $order_type && ! DoughBoss_Catering::delivery_enabled() ) {
			return new WP_Error(
				'doughboss_catering_pickup_only',
				__( 'Catering is pickup from Revesby only. Please choose pickup, or call us to discuss other arrangements.', 'doughboss' ),
				array( 'status' => 400 )
			);
		}

		// Preserve intentional legacy omission (0), but never silently reroute an
		// explicitly selected location that has since disappeared or been disabled.
		$location_id = absint( $request->get_param( 'location_id' ) );
		if ( $location_id > 0 && ! DoughBoss_Locations::is_valid( $location_id ) ) {
			return new WP_Error(
				'doughboss_catering_location_unavailable',
				__( 'That location is no longer available. Please choose another location.', 'doughboss' ),
				array( 'status' => 400 )
			);
		}
		// Catering is prepared only at the catering shops (Revesby). Omission
		// routes there; another shop is refused rather than silently rerouted.
		$catering_locations = DoughBoss_Catering::catering_location_ids();
		$revesby_location_id = 0;
		foreach ( $catering_locations as $catering_location_id ) {
			$catering_location = DoughBoss_Locations::get( $catering_location_id );
			if ( $catering_location && ! empty( $catering_location->is_active ) && 'revesby' === sanitize_key( (string) $catering_location->slug ) ) {
				$revesby_location_id = (int) $catering_location->id;
				break;
			}
		}
		if ( $revesby_location_id < 1 || ( $location_id > 0 && $location_id !== $revesby_location_id ) ) {
			return new WP_Error(
				'doughboss_catering_location_unavailable',
				__( 'Catering is pickup from Revesby only. Please send your enquiry for Revesby.', 'doughboss' ),
				array( 'status' => 400 )
			);
		}
		$location_id = $revesby_location_id;

		$id = DoughBoss_Catering::create(
			array(
				'customer_name'  => $request->get_param( 'customer_name' ),
				'customer_email' => $request->get_param( 'customer_email' ),
				'customer_phone' => $request->get_param( 'customer_phone' ),
				'package_id'     => $request->get_param( 'package_id' ),
				'packages'       => $request->get_param( 'packages' ),
				'guest_count'    => $request->get_param( 'guest_count' ),
				'order_type'     => $order_type,
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
	 * GET /admin/catering — list catering enquiries for the staff screen.
	 * Mirrors the admin catering page's DoughBoss_Catering::query() call
	 * (status filter + search + pagination).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function admin_catering( WP_REST_Request $request ) {
		$result = DoughBoss_Catering::query(
			array(
				'status'   => (string) $request->get_param( 'status' ),
				'search'   => (string) $request->get_param( 'search' ),
				'page'     => max( 1, absint( $request->get_param( 'page' ) ) ),
				'per_page' => max( 1, absint( $request->get_param( 'per_page' ) ) ),
			)
		);

		return rest_ensure_response(
			array(
				'data'        => $result['items'],
				'total'       => $result['total'],
				'server_time' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * GET /admin/catering-board — committed jobs for the catering display.
	 *
	 * The response omits email addresses and provider identifiers. KDS-only
	 * accounts are forced to their assigned shop by the same server-side scope
	 * used for ordinary kitchen orders.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_catering_board( WP_REST_Request $request ) {
		$location_id = DoughBoss_Staff_Scope::effective_read_location_id( $request->get_param( 'location_id' ) );
		if ( is_wp_error( $location_id ) ) {
			return $location_id;
		}

		$statuses = DoughBoss_Catering::statuses();
		$rows     = DoughBoss_Catering::production_queue( $location_id, 100 );
		$data     = array_map(
			static function ( $row ) use ( $statuses ) {
				$package = (int) $row['package_id'] || 0 === strpos( (string) $row['notes'], DoughBoss_Catering::PACKAGES_NOTE_HEADING ) ? DoughBoss_Catering::package_label( $row ) : __( 'Custom catering', 'doughboss' );
				$status  = sanitize_key( (string) $row['status'] );
				return array(
					'id'              => (int) $row['id'],
					'enquiry_number'  => sanitize_text_field( (string) $row['enquiry_number'] ),
					'location_id'     => (int) $row['location_id'],
					'package_name'    => sanitize_text_field( (string) $package ),
					'status'          => $status,
					'status_label'    => isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status,
					'customer_name'   => sanitize_text_field( (string) $row['customer_name'] ),
					'customer_phone'  => sanitize_text_field( (string) $row['customer_phone'] ),
					'event_date'      => sanitize_text_field( (string) $row['event_date'] ),
					'event_time'      => sanitize_text_field( (string) $row['event_time'] ),
					'guest_count'     => (int) $row['guest_count'],
					'order_type'      => sanitize_key( (string) $row['order_type'] ),
					'address'         => sanitize_textarea_field( (string) $row['address'] ),
					'dietary'         => sanitize_textarea_field( (string) $row['dietary'] ),
					'notes'           => sanitize_textarea_field( (string) $row['notes'] ),
					'quote_total'     => (float) $row['quote_total'],
					'deposit_amount'  => (float) $row['deposit_amount'],
					'balance_amount'  => (float) $row['balance_amount'],
					'currency'        => sanitize_text_field( (string) $row['currency'] ),
					'created_at'      => sanitize_text_field( (string) $row['created_at'] ),
					'updated_at'      => sanitize_text_field( (string) $row['updated_at'] ),
				);
			},
			$rows
		);

		return rest_ensure_response(
			array(
				'data'        => $data,
				'server_time' => current_time( 'mysql', true ),
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
	 * POST /admin/catering/{id}/quote — manager-only custom quote update.
	 *
	 * The client supplies staff-authored components only. DoughBoss validates
	 * them and derives the locked total, deposit, balance and AUD currency.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_update_catering_quote( WP_REST_Request $request ) {
		$result = DoughBoss_Catering::set_custom_quote(
			absint( $request->get_param( 'id' ) ),
			$request->get_param( 'subtotal' ),
			$request->get_param( 'delivery_fee' ),
			$request->get_param( 'deposit_pct' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success'        => true,
				'status'         => (string) $result['status'],
				'subtotal'       => (float) $result['subtotal'],
				'delivery_fee'   => (float) $result['delivery_fee'],
				'total'          => (float) $result['quote_total'],
				'deposit_amount' => (float) $result['deposit_amount'],
				'balance_amount' => (float) $result['balance_amount'],
				'currency'       => (string) $result['currency'],
			)
		);
	}

	/**
	 * POST /admin/square-tier — add/remove a customer to Student/Tradie/Club.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_square_tier( WP_REST_Request $request ) {
		$result = DoughBoss_Square_Tiers::assign(
			(string) $request->get_param( 'tier' ),
			array(
				'name'  => sanitize_text_field( (string) $request->get_param( 'name' ) ),
				'email' => sanitize_email( (string) $request->get_param( 'email' ) ),
				'phone' => sanitize_text_field( (string) $request->get_param( 'phone' ) ),
			),
			'remove' !== (string) $request->get_param( 'action' )
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( array_merge( array( 'success' => true ), $result ) );
	}

	/**
	 * POST /admin/catering/{id}/square-invoice — publish a Square invoice for a leg.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_catering_square_invoice( WP_REST_Request $request ) {
		$leg = (string) $request->get_param( 'leg' );
		if ( ! in_array( $leg, array( DoughBoss_Catering::LEG_DEPOSIT, DoughBoss_Catering::LEG_BALANCE ), true ) ) {
			return new WP_Error( 'doughboss_catering_leg', __( 'Choose deposit or balance.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$result = DoughBoss_Square_Invoices::send( absint( $request->get_param( 'id' ) ), $leg );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array_merge( array( 'success' => true, 'leg' => $leg ), $result ) );
	}

	/**
	 * POST /catering/payment-intent — start a deposit or balance card payment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function catering_payment_intent( WP_REST_Request $request ) {
		if ( 'mpgs' === DoughBoss_Settings::payment_gateway() ) {
			return new WP_Error( 'doughboss_mpgs_catering_unavailable', __( 'Mastercard Hosted Checkout is currently available for menu orders only.', 'doughboss' ), array( 'status' => 400 ) );
		}
		if ( ! DoughBoss_Payment::ready() ) {
			return new WP_Error( 'doughboss_pay_off', __( 'Card payments are not available right now.', 'doughboss' ), array( 'status' => 400 ) );
		}

		if ( $this->rate_limited( 'catering_payment_intent', 12, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'doughboss_rate_limit', __( 'Too many requests. Please wait a few minutes and try again.', 'doughboss' ), array( 'status' => 429 ) );
		}

		$enquiry = $this->resolve_catering_enquiry( $request );
		if ( is_wp_error( $enquiry ) ) {
			return $enquiry;
		}
		$enquiry_id = (int) $enquiry['id'];
		if ( ! DoughBoss_Catering::acquire_quote_lock( $enquiry_id ) ) {
			return new WP_Error( 'doughboss_catering_payment_busy', __( 'This enquiry is busy. Please wait a moment and try again.', 'doughboss' ), array( 'status' => 503 ) );
		}

		try {
			return $this->catering_payment_intent_locked( $request, $enquiry_id );
		} finally {
			DoughBoss_Catering::release_quote_lock( $enquiry_id );
		}
	}

	/**
	 * Prepare a catering payment while holding the enquiry quote lock.
	 *
	 * @param WP_REST_Request $request    Request.
	 * @param int             $enquiry_id Verified enquiry ID.
	 * @return WP_REST_Response|WP_Error
	 */
	private function catering_payment_intent_locked( WP_REST_Request $request, $enquiry_id ) {
		$enquiry = DoughBoss_Catering::get( $enquiry_id );
		if ( ! $enquiry ) {
			return new WP_Error( 'doughboss_catering_missing', __( 'That catering enquiry could not be found.', 'doughboss' ), array( 'status' => 404 ) );
		}

		$leg    = self::catering_leg( $request->get_param( 'leg' ) );
		$amount = DoughBoss_Catering::leg_amount( $enquiry, $leg );
		$stored = DoughBoss_Catering::LEG_BALANCE === $leg ? (string) $enquiry['balance_intent_id'] : (string) $enquiry['deposit_intent_id'];

		if ( DoughBoss_Catering::is_paid( $enquiry, $leg ) ) {
			return new WP_Error( 'doughboss_pay_done', __( 'That payment has already been made.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( '' !== $stored ) {
			if ( 'stripe' === DoughBoss_Settings::payment_gateway() ) {
				$existing = DoughBoss_Payment::retrieve_payment_intent( $stored );
				if ( is_wp_error( $existing ) ) {
					return $existing;
				}
				if ( empty( $existing['client_secret'] ) || ! $this->catering_payment_matches( $enquiry, $leg, $stored, $existing ) ) {
					return new WP_Error( 'doughboss_pay_mismatch', __( 'The prepared payment no longer matches this quote.', 'doughboss' ), array( 'status' => 409 ) );
				}
				$attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $stored );
				return rest_ensure_response(
					array(
						'client_secret'   => (string) $existing['client_secret'],
						'payment_intent'  => $stored,
						'publishable_key' => DoughBoss_Payment::publishable_key(),
						'amount'          => isset( $existing['amount'] ) ? (int) $existing['amount'] : 0,
						'currency'        => isset( $existing['currency'] ) ? strtolower( (string) $existing['currency'] ) : '',
						'leg'             => $leg,
						'gateway'         => 'stripe',
						'live_mode'       => false,
						'attempt_id'      => $attempt ? (int) $attempt['id'] : 0,
					)
				);
			}
			return new WP_Error( 'doughboss_pay_prepared', __( 'That payment is already prepared. Please contact us if you need a new payment session.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( DoughBoss_Catering::LEG_BALANCE === $leg && ! DoughBoss_Catering::is_paid( $enquiry, DoughBoss_Catering::LEG_DEPOSIT ) ) {
			return new WP_Error( 'doughboss_pay_seq', __( 'The deposit must be paid before the balance.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( $amount <= 0 ) {
			return new WP_Error( 'doughboss_pay_amount', __( "There's nothing to pay yet — we'll confirm your quote first.", 'doughboss' ), array( 'status' => 400 ) );
		}

		$currency = DoughBoss_Settings::get( 'currency_code', 'AUD' );
		$checkout_key = $this->payment_checkout_key(
			$request,
			'catering-' . $leg,
			array( 'enquiry_id' => (int) $enquiry['id'], 'leg' => $leg, 'amount' => $amount, 'location_id' => (int) $enquiry['location_id'] )
		);
		if ( is_wp_error( $checkout_key ) ) {
			return $checkout_key;
		}
		$intent   = DoughBoss_Payment::create_payment_intent(
			DoughBoss_Payment::to_minor_units( $amount ),
			$currency,
			array(
				'checkout_key'   => $checkout_key,
				'purpose'        => DoughBoss_Catering::LEG_BALANCE === $leg ? 'catering_balance' : 'catering_deposit',
				'context'        => 'catering',
				'location_id'    => (int) $enquiry['location_id'],
				'local_reference'=> $enquiry['enquiry_number'] . ':' . $leg,
				'enquiry_id'     => (int) $enquiry['id'],
				'enquiry_number' => $enquiry['enquiry_number'],
				'leg'            => $leg,
				'site'           => home_url(),
			)
		);
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		// Store the CANONICAL reference, not the raw id: for Tyro, $intent['id']
		// is a composite ("{order_id}.{session_id}") and a webhook can only ever
		// carry the bare order id, so storing the composite here would make
		// reconcile_catering_intent()'s webhook-based lookup permanently unable
		// to match this leg once the short-lived metadata transient expires. See
		// DoughBoss_Tyro::canonical_id() and the same fix in verify_payment().
		if ( ! DoughBoss_Catering::set_intent( (int) $enquiry['id'], $leg, DoughBoss_Payment::canonical_id( $intent['id'] ) ) ) {
			return new WP_Error( 'doughboss_pay_store', __( 'Payment preparation could not be stored safely. Please contact us before retrying.', 'doughboss' ), array( 'status' => 503 ) );
		}

		return rest_ensure_response(
			array(
				'client_secret'   => $intent['client_secret'],
				'payment_intent'  => $intent['id'],
				'publishable_key' => DoughBoss_Payment::publishable_key(),
				'amount'          => $intent['amount'],
				'currency'        => $intent['currency'],
				'leg'             => $leg,
				'gateway'         => DoughBoss_Settings::payment_gateway(),
				'live_mode'       => ( 'tyro' === DoughBoss_Settings::payment_gateway() && DoughBoss_Settings::tyro_live_mode() ) || ( 'mpgs' === DoughBoss_Settings::payment_gateway() && DoughBoss_Settings::mpgs_live_mode() ),
				'attempt_id'      => isset( $intent['attempt_id'] ) ? (int) $intent['attempt_id'] : 0,
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
		if ( ! DoughBoss_Payment::ready() ) {
			return new WP_Error( 'doughboss_pay_off', __( 'Card payments are not available right now.', 'doughboss' ), array( 'status' => 400 ) );
		}

		if ( $this->rate_limited( 'catering_confirm_payment', 12, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'doughboss_rate_limit', __( 'Too many requests. Please wait a few minutes and try again.', 'doughboss' ), array( 'status' => 429 ) );
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

		// $raw_id is what the client actually round-tripped (carries the Tyro
		// session id needed below); $stored is the canonical reference recorded
		// at intent-creation time, so the match must compare canonical-to-canonical.
		$raw_id = sanitize_text_field( $request->get_param( 'payment_intent_id' ) );
		$stored = DoughBoss_Catering::LEG_BALANCE === $leg ? $enquiry['balance_intent_id'] : $enquiry['deposit_intent_id'];
		if ( '' === $raw_id || DoughBoss_Payment::canonical_id( $raw_id ) !== $stored ) {
			return new WP_Error( 'doughboss_pay_mismatch', __( 'We could not match that payment.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$intent = DoughBoss_Payment::retrieve_payment_intent( $raw_id );
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		$status   = isset( $intent['status'] ) ? $intent['status'] : '';

		if ( 'succeeded' !== $status || ! $this->catering_payment_matches( $enquiry, $leg, $stored, $intent ) ) {
			// Do not claim an automatic reversal: nothing in this plugin refunds a
			// payment automatically today. Point the customer at a human instead
			// of a false promise.
			return new WP_Error( 'doughboss_pay_unverified', __( 'We could not verify your payment. If you were charged, please contact us and we will sort out a refund.', 'doughboss' ), array( 'status' => 402 ) );
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
			return new WP_Error( 'doughboss_wh_event', __( 'Invalid payment event.', 'doughboss' ), array( 'status' => 400 ) );
		}
		if ( 'payment_intent.succeeded' !== $event['type'] ) {
			return rest_ensure_response( array( 'received' => true, 'ignored' => true ) );
		}

		$obj      = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();
		$event_id = isset( $event['id'] ) ? sanitize_text_field( (string) $event['id'] ) : '';
		$pi_id    = isset( $obj['id'] ) ? sanitize_text_field( (string) $obj['id'] ) : '';
		if ( ! preg_match( '/^evt_[A-Za-z0-9_]{8,191}$/', $event_id ) || ! preg_match( '/^pi_[A-Za-z0-9_]{8,191}$/', $pi_id ) ) {
			return new WP_Error( 'doughboss_wh_reference', __( 'Invalid payment event reference.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$meta = isset( $obj['metadata'] ) && is_array( $obj['metadata'] ) ? $obj['metadata'] : array();
		if ( ! isset( $meta['context'] ) || 'catering' !== $meta['context'] ) {
			return rest_ensure_response( array( 'received' => true, 'ignored' => true ) );
		}

		$event_key = hash( 'sha256', 'stripe|' . $event_id );
		if ( ! DoughBoss_Payment_Attempts::claim_event( $event_key, 'stripe', $pi_id, 'payment_intent.succeeded' ) ) {
			$event_outcome = DoughBoss_Payment_Attempts::event_outcome( $event_key );
			if ( null === $event_outcome || 'retry' === $event_outcome ) {
				return new WP_Error( 'doughboss_wh_storage', __( 'Payment event storage is temporarily unavailable.', 'doughboss' ), array( 'status' => 503 ) );
			}
			return rest_ensure_response( array( 'received' => true, 'duplicate' => true ) );
		}

		if ( ! $this->reconcile_catering_intent( $obj, $meta ) ) {
			DoughBoss_Payment_Attempts::complete_event( $event_key, 'retry' );
			return new WP_Error( 'doughboss_wh_retry', __( 'Payment reconciliation will be retried.', 'doughboss' ), array( 'status' => 500 ) );
		}
		if ( ! DoughBoss_Payment_Attempts::complete_event( $event_key, 'processed' ) ) {
			DoughBoss_Payment_Attempts::complete_event( $event_key, 'retry' );
			return new WP_Error( 'doughboss_wh_store', __( 'Payment event storage will be retried.', 'doughboss' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'received' => true, 'processed' => true ) );
	}

	/**
	 * POST /stripe-webhook — storefront payment reconciliation safety-net.
	 *
	 * Verifies the Stripe signature, then on payment_intent.succeeded checks
	 * whether the PaymentIntent has produced an order. The common case is
	 * handled synchronously by /checkout; this webhook exists so a payment
	 * whose checkout call never lands (browser crash, network drop) is
	 * surfaced to the owner instead of silently keeping the customer's money.
	 * A validated snapshot can recover the paid order or finish its bookkeeping.
	 * Missing snapshots are surfaced for review; refunds remain a human decision.
	 *
	 * Catering-context events are delegated to the same idempotent handling as
	 * /catering/stripe-webhook: Stripe issues one signing secret per endpoint
	 * and the plugin stores a single secret per mode, so this endpoint must be
	 * able to serve as the site's only registered webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function stripe_webhook( WP_REST_Request $request ) {
		$payload = $request->get_body();
		$sig     = $request->get_header( 'stripe_signature' );
		if ( ! DoughBoss_Stripe::verify_webhook_signature( $payload, $sig ) ) {
			return new WP_Error( 'doughboss_wh_sig', __( 'Invalid signature.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new WP_Error( 'doughboss_wh_event', __( 'Invalid payment event.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$event_type = sanitize_text_field( (string) $event['type'] );
		if ( ! in_array( $event_type, array( 'payment_intent.succeeded', 'checkout.session.completed' ), true ) ) {
			return rest_ensure_response( array( 'received' => true, 'ignored' => true ) );
		}

		$obj      = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();
		$meta     = isset( $obj['metadata'] ) && is_array( $obj['metadata'] ) ? $obj['metadata'] : array();
		$event_id = isset( $event['id'] ) ? sanitize_text_field( (string) $event['id'] ) : '';
		if ( 'checkout.session.completed' === $event_type ) {
			if ( 'paid' !== ( isset( $obj['payment_status'] ) ? (string) $obj['payment_status'] : '' ) ) {
				return rest_ensure_response( array( 'received' => true, 'ignored' => true ) );
			}
			$session_id = isset( $obj['id'] ) && is_scalar( $obj['id'] ) ? sanitize_text_field( (string) $obj['id'] ) : '';
			$session_reference = isset( $obj['client_reference_id'] ) && is_scalar( $obj['client_reference_id'] ) ? strtolower( sanitize_text_field( (string) $obj['client_reference_id'] ) ) : '';
			$pi_id = isset( $obj['payment_intent'] ) && is_scalar( $obj['payment_intent'] ) ? sanitize_text_field( (string) $obj['payment_intent'] ) : '';
			$obj   = array(
				'id'                         => $pi_id,
				'amount'                     => isset( $obj['amount_total'] ) ? absint( $obj['amount_total'] ) : 0,
				'currency'                   => isset( $obj['currency'] ) ? sanitize_key( (string) $obj['currency'] ) : '',
				'metadata'                   => $meta,
				'checkout_session_id'        => $session_id,
				'checkout_session_reference' => $session_reference,
				'checkout_session_amount'    => isset( $obj['amount_total'] ) ? absint( $obj['amount_total'] ) : 0,
				'checkout_session_currency'  => isset( $obj['currency'] ) ? sanitize_key( (string) $obj['currency'] ) : '',
			);
		} else {
			$pi_id = isset( $obj['id'] ) ? sanitize_text_field( (string) $obj['id'] ) : '';
		}
		if ( ! preg_match( '/^evt_[A-Za-z0-9_]{8,191}$/', $event_id ) || ! preg_match( '/^pi_[A-Za-z0-9_]{8,191}$/', $pi_id ) ) {
			return new WP_Error( 'doughboss_wh_reference', __( 'Invalid payment event reference.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$event_key = hash( 'sha256', 'stripe|' . $event_id );
		if ( ! DoughBoss_Payment_Attempts::claim_event( $event_key, 'stripe', $pi_id, $event_type ) ) {
			$event_outcome = DoughBoss_Payment_Attempts::event_outcome( $event_key );
			if ( null === $event_outcome || 'retry' === $event_outcome ) {
				return new WP_Error( 'doughboss_wh_storage', __( 'Payment event storage is temporarily unavailable.', 'doughboss' ), array( 'status' => 503 ) );
			}
			return rest_ensure_response( array( 'received' => true, 'duplicate' => true ) );
		}

		$processed = true;
		if ( isset( $meta['context'] ) && 'catering' === $meta['context'] ) {
			$processed = $this->reconcile_catering_intent( $obj, $meta );
		} else {
			$recovered = $this->recover_stripe_order( $pi_id, $obj, $meta );
			$is_approved_preorder = isset( $meta['context'] ) && 'approved_preorder' === sanitize_key( (string) $meta['context'] );
			if ( $is_approved_preorder && ( is_wp_error( $recovered ) || ! $recovered ) ) {
				$source_request_id = isset( $meta['source_request_id'] ) ? absint( $meta['source_request_id'] ) : 0;
				if ( $source_request_id ) {
					DoughBoss_Preorder_Approval::hold_payment( $source_request_id );
				}
				$processed = false;
			} elseif ( is_wp_error( $recovered ) ) {
				$processed = false;
			} elseif ( ! $recovered && ! DoughBoss_Order::payment_intent_used( $pi_id ) ) {
				$processed = $this->record_unreconciled_payment( $pi_id, $obj );
			}
		}

		if ( ! $processed ) {
			DoughBoss_Payment_Attempts::complete_event( $event_key, 'retry' );
			return new WP_Error( 'doughboss_wh_retry', __( 'Payment reconciliation will be retried.', 'doughboss' ), array( 'status' => 500 ) );
		}
		if ( ! DoughBoss_Payment_Attempts::complete_event( $event_key, 'processed' ) ) {
			DoughBoss_Payment_Attempts::complete_event( $event_key, 'retry' );
			return new WP_Error( 'doughboss_wh_store', __( 'Payment event storage will be retried.', 'doughboss' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'received' => true, 'processed' => true ) );
	}

	/**
	 * Bind paid recovery evidence to the attempt's exact hosted Session.
	 * A PaymentIntent-only event needs a read of that retained Session; matching
	 * checkout metadata and money alone cannot establish their association.
	 *
	 * @return true|WP_Error
	 */
	private function validate_stripe_recovery_session( array $attempt, $pi_id, array $obj, $checkout_key, $approved_request = false ) {
		$error_code = $approved_request ? 'doughboss_preorder_payment_binding' : 'doughboss_stripe_payment_binding';
		$provider_session_id = isset( $attempt['provider_reference'] ) ? sanitize_text_field( (string) $attempt['provider_reference'] ) : '';
		if ( 1 !== preg_match( '/^cs_(?:test|live)_[A-Za-z0-9_]{8,191}$/', $provider_session_id ) ) {
			return new WP_Error( $error_code, __( 'The paid order is missing its exact Stripe session link. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$session_check = $obj;
		if ( ! isset( $session_check['checkout_session_id'], $session_check['checkout_session_reference'], $session_check['checkout_session_amount'], $session_check['checkout_session_currency'] ) ) {
			$session_check = DoughBoss_Stripe::retrieve_checkout_payment( $provider_session_id );
			if ( is_wp_error( $session_check ) ) { return $session_check; }
		}
		$session_pi = isset( $session_check['id'] ) ? DoughBoss_Stripe::canonical_id( $session_check['id'] ) : '';
		if (
			! isset( $session_check['checkout_session_id'], $session_check['checkout_session_reference'], $session_check['checkout_session_amount'], $session_check['checkout_session_currency'] )
			|| ! hash_equals( $provider_session_id, (string) $session_check['checkout_session_id'] )
			|| ! hash_equals( $checkout_key, strtolower( (string) $session_check['checkout_session_reference'] ) )
			|| ! hash_equals( $pi_id, $session_pi )
			|| (int) $session_check['checkout_session_amount'] !== (int) $attempt['amount_minor']
			|| strtoupper( (string) $session_check['checkout_session_currency'] ) !== strtoupper( (string) $attempt['currency'] )
		) {
			return new WP_Error( $error_code, __( 'The paid order does not match its exact Stripe session. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * Fulfil a signed, paid Stripe event from the immutable server snapshot.
	 *
	 * This is the browser-close recovery path. Stripe metadata contains only the
	 * opaque checkout key; all customer/cart facts are read from DoughBoss's own
	 * database and checked against the durable payment attempt before an order is
	 * created. The order/payment unique indexes remain the final race authority.
	 *
	 * @param string $pi_id Canonical Stripe PaymentIntent id.
	 * @param array  $obj   Normalised Stripe payment object.
	 * @param array  $meta  Signed Stripe metadata.
	 * @return bool|WP_Error True when fulfilled/replayed, false when no safe snapshot exists.
	 */
	private function recover_stripe_order( $pi_id, array $obj, array $meta ) {
		$checkout_key = isset( $meta['checkout_key'] ) ? strtolower( sanitize_text_field( (string) $meta['checkout_key'] ) ) : '';
		if (
			1 !== preg_match( '/^[a-f0-9]{64}$/', $checkout_key )
			|| 'order' !== ( isset( $meta['purpose'] ) ? sanitize_key( (string) $meta['purpose'] ) : '' )
		) {
			return false;
		}

		$attempt  = DoughBoss_Payment_Attempts::find_by_checkout_key( $checkout_key );
		$snapshot = $this->stripe_order_snapshot( $checkout_key, '', true );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( ! $attempt ) {
			return false;
		}

		$order_data = is_array( $snapshot['payload']['order'] ) ? $snapshot['payload']['order'] : array();
		$lines      = is_array( $snapshot['payload']['lines'] ) ? array_values( $snapshot['payload']['lines'] ) : array();
		$copied_basis = $this->copy_accounting_basis_from_snapshot( $snapshot['payload']['order'], $order_data );
		if ( is_wp_error( $copied_basis ) ) {
			return $copied_basis;
		}
		$context    = isset( $meta['context'] ) ? sanitize_key( (string) $meta['context'] ) : '';
		$source_request_id = isset( $order_data['source_request_id'] ) ? absint( $order_data['source_request_id'] ) : 0;
		if ( 'approved_preorder' === $context ) {
			$session_binding = $this->validate_stripe_recovery_session( $attempt, $pi_id, $obj, $checkout_key, true );
			if ( is_wp_error( $session_binding ) ) { return $session_binding; }
			$approval_snapshot = isset( $snapshot['payload']['approval'] ) && is_array( $snapshot['payload']['approval'] ) ? $snapshot['payload']['approval'] : array();
			$approval = $source_request_id ? DoughBoss_Preorder_Approval::get( $source_request_id ) : new WP_Error( 'doughboss_preorder_payment_binding' );
			$current_fingerprint = $source_request_id ? DoughBoss_Preorder_Approval::fingerprint_request( $source_request_id ) : '';
			if (
				is_wp_error( $approval )
				|| is_wp_error( $current_fingerprint )
				|| ! $this->valid_fingerprint( $current_fingerprint )
				|| ! in_array( (string) $approval['state'], array( 'payment_active', 'payment_held', 'released' ), true )
				|| ! isset( $approval['payment_checkout_key'], $approval['request_fingerprint'], $approval['approved_revision'] )
				|| ! $this->valid_fingerprint( $approval['request_fingerprint'] )
				|| ! hash_equals( (string) $approval['payment_checkout_key'], $checkout_key )
				|| ! hash_equals( $approval['request_fingerprint'], $current_fingerprint )
				|| $source_request_id !== ( isset( $meta['source_request_id'] ) ? absint( $meta['source_request_id'] ) : 0 )
				|| $source_request_id !== ( isset( $approval_snapshot['request_id'] ) ? absint( $approval_snapshot['request_id'] ) : 0 )
				|| (int) $approval['approved_revision'] !== ( isset( $meta['approved_revision'] ) ? (int) $meta['approved_revision'] : 0 )
				|| (int) $approval['approved_revision'] !== ( isset( $approval_snapshot['approved_revision'] ) ? (int) $approval_snapshot['approved_revision'] : 0 )
				|| ! isset( $meta['request_fingerprint'], $approval_snapshot['request_fingerprint'] )
				|| ! $this->valid_fingerprint( $meta['request_fingerprint'] )
				|| ! $this->valid_fingerprint( $approval_snapshot['request_fingerprint'] )
				|| ! hash_equals( $approval['request_fingerprint'], $meta['request_fingerprint'] )
				|| ! hash_equals( $approval['request_fingerprint'], $approval_snapshot['request_fingerprint'] )
				|| empty( $approval['payment_attempt_id'] )
				|| (int) $approval['payment_attempt_id'] !== (int) $attempt['id']
			) {
				return new WP_Error( 'doughboss_preorder_payment_binding', __( 'This paid request requires manual confirmation. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
			}
		} elseif ( $source_request_id ) {
			return false;
		}
		$amount     = isset( $obj['amount_received'] ) ? absint( $obj['amount_received'] ) : ( isset( $obj['amount'] ) ? absint( $obj['amount'] ) : 0 );
		$currency   = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( isset( $obj['currency'] ) ? $obj['currency'] : '' ) ) );
		$expected   = isset( $order_data['total'] ) ? DoughBoss_Payment::to_minor_units( (float) $order_data['total'] ) : -1;

		if (
			'stripe' !== (string) $attempt['provider']
			|| 'order' !== (string) $attempt['purpose']
			|| $amount !== (int) $attempt['amount_minor']
			|| $amount !== $expected
			|| $currency !== strtoupper( (string) $attempt['currency'] )
			|| $currency !== strtoupper( (string) ( isset( $order_data['currency'] ) ? $order_data['currency'] : '' ) )
			|| (int) $attempt['location_id'] !== (int) ( isset( $order_data['location_id'] ) ? $order_data['location_id'] : 0 )
			|| (int) $attempt['table_id'] !== (int) ( isset( $order_data['table_id'] ) ? $order_data['table_id'] : 0 )
			|| (int) $attempt['qr_code_id'] !== (int) ( isset( $order_data['table_qr_code_id'] ) ? $order_data['table_qr_code_id'] : 0 )
			|| $checkout_key !== ( isset( $order_data['checkout_key'] ) ? (string) $order_data['checkout_key'] : '' )
		) {
			if ( 'approved_preorder' === $context ) {
				return new WP_Error( 'doughboss_preorder_payment_binding', __( 'This paid request does not match its retained order details. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			return false;
		}

		// A prior browser/webhook worker may have committed the order but lost
		// one bookkeeping write. Repair it without redeeming or notifying again.
		$existing_id = DoughBoss_Order::find_id_by_payment_intent( $pi_id );
		if ( $existing_id ) {
			$existing = DoughBoss_Order::get( $existing_id );
			if ( $source_request_id && ( ! $existing || (int) $existing->source_request_id !== $source_request_id ) ) {
				return new WP_Error( 'doughboss_preorder_payment_binding', __( 'This payment is already linked to a different order. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			return $this->finalize_stripe_order( $snapshot, $existing, $pi_id );
		}
		$attempt_metadata = DoughBoss_Payment_Attempts::metadata( $attempt );
		$previously_held = isset( $attempt_metadata['reconciliation_hold'] )
			&& 'staff_approval_review_required' === (string) $attempt_metadata['reconciliation_hold'];
		if ( ! $source_request_id && 'approved_preorder' !== $context && $previously_held ) {
			// A durable ordinary paid hold remains a human-reconciliation outcome
			// even if an operator later enables legacy checkout. Re-persist its exact
			// identity for a retryable signed event, but never fulfil it here.
			$session_binding = $this->validate_stripe_recovery_session( $attempt, $pi_id, $obj, $checkout_key );
			if ( is_wp_error( $session_binding ) ) { return $session_binding; }
			$held = DoughBoss_Payment_Attempts::hold_paid_stripe_order(
				$checkout_key,
				isset( $attempt['provider_reference'] ) ? (string) $attempt['provider_reference'] : '',
				$pi_id,
				(int) $attempt['amount_minor'],
				(string) $attempt['currency'],
				'staff_approval_review_required'
			);
			if ( ! $held ) {
				return new WP_Error( 'doughboss_stripe_hold_pending', __( 'The paid order is awaiting secure reconciliation. Please do not pay again.', 'doughboss' ), array( 'status' => 503 ) );
			}
			return true;
		}
		// A signed Stripe webhook can arrive for a legacy ordinary Checkout after
		// the operator changes policy. Preserve the verified paid evidence through
		// the dedicated durable hold before acknowledging the event, but never turn
		// it into a new kitchen order or confirmation. Existing orders above remain
		// eligible only for their bounded bookkeeping replay.
		if ( ! $source_request_id && 'approved_preorder' !== $context ) {
			$policy = DoughBoss_Settings::staff_approval_policy();
			if ( 'legacy' !== ( isset( $policy['mode'] ) ? (string) $policy['mode'] : '' ) ) {
				$session_binding = $this->validate_stripe_recovery_session( $attempt, $pi_id, $obj, $checkout_key );
				if ( is_wp_error( $session_binding ) ) { return $session_binding; }
				$held = DoughBoss_Payment_Attempts::hold_paid_stripe_order(
					$checkout_key,
					isset( $attempt['provider_reference'] ) ? (string) $attempt['provider_reference'] : '',
					$pi_id,
					(int) $attempt['amount_minor'],
					(string) $attempt['currency'],
					'staff_approval_review_required'
				);
				if ( ! $held ) {
					return new WP_Error( 'doughboss_stripe_hold_pending', __( 'The paid order is awaiting secure reconciliation. Please do not pay again.', 'doughboss' ), array( 'status' => 503 ) );
				}
				return true;
			}
		}
		if ( $source_request_id ) {
			$existing_source_id = DoughBoss_Order::find_id_by_source_request( $source_request_id );
			if ( $existing_source_id ) {
				return new WP_Error( 'doughboss_preorder_payment_binding', __( 'This approved request is already linked to a different payment. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
			}
		}

		$voucher_code = isset( $order_data['voucher_code'] ) ? (string) $order_data['voucher_code'] : '';
		$discount     = isset( $order_data['discount'] ) ? (float) $order_data['discount'] : 0.0;
		$voucher_reservation_key = isset( $order_data['voucher_reservation_key'] ) ? strtolower( sanitize_text_field( (string) $order_data['voucher_reservation_key'] ) ) : '';
		if ( '' !== $voucher_reservation_key && ! hash_equals( $checkout_key, $voucher_reservation_key ) ) {
			if ( 'approved_preorder' === $context ) {
				return new WP_Error( 'doughboss_preorder_payment_binding', __( 'This paid request does not match its retained voucher reservation. Please contact the shop and do not pay again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			return false;
		}
		$voucher_idem = '';
		if ( '' !== $voucher_code && $discount > 0 ) {
			$voucher_idem = 'order_' . $checkout_key;
			$redeem       = DoughBoss_Voucher::redeem(
				$voucher_code,
				isset( $order_data['subtotal'] ) ? (float) $order_data['subtotal'] : 0.0,
				'online',
				array(
					'idempotency_key' => $voucher_idem,
					'reservation_key' => $voucher_reservation_key,
				)
			);
			if ( is_wp_error( $redeem ) ) {
				$system_note = sprintf(
					/* translators: 1: formatted discount amount, 2: voucher code. */
					'[SYSTEM] Discount of %1$s from voucher "%2$s" was preserved on this paid webhook-recovered order, but its redemption needs manual reconciliation.',
					DoughBoss_Settings::format_price( $discount ),
					$voucher_code
				);
				$order_data['notes'] = ! empty( $order_data['notes'] ) ? $order_data['notes'] . "\n\n" . $system_note : $system_note;
				$voucher_idem        = '';
			} else {
				$discount = (float) $redeem['amount'];
			}
		} else {
			$discount     = 0.0;
			// Recovery must preserve the same voucher identity as the paid snapshot.
		}

		$order_data['discount']          = $discount;
		$order_data['voucher_code']      = $voucher_code;
		$order_data['payment_status']    = 'paid';
		$order_data['payment_method']    = 'stripe';
		$order_data['payment_intent_id'] = $pi_id;
		$order_data['checkout_key']      = $checkout_key;

		$created = DoughBoss_Order::create( $order_data, $lines );
		if ( is_wp_error( $created ) ) {
			if ( $source_request_id && 'doughboss_paid_reconciliation_required' === $created->get_error_code() ) {
				DoughBoss_Preorder_Approval::hold_payment( $source_request_id );
			}
			// This is a verified paid recovery. Keep a reserved redemption
			// consumed and unlinked so a signed webhook/browser retry can replay
			// it, create the order, and link the audit without a rival claim race.
			if ( '' !== $voucher_idem && '' === $voucher_reservation_key ) {
				DoughBoss_Voucher::revert_redemption( $voucher_idem, $voucher_reservation_key, $pi_id );
			}
			return $created;
		}

		$order_id = (int) $created['order_id'];
		if ( '' !== $voucher_idem && '' !== $voucher_reservation_key ) {
			DoughBoss_Voucher::redeem(
				$voucher_code,
				isset( $order_data['subtotal'] ) ? (float) $order_data['subtotal'] : 0.0,
				'online',
				array(
					'idempotency_key' => $voucher_idem,
					'reservation_key' => $voucher_reservation_key,
				)
			);
		}
		if ( '' !== $voucher_idem ) {
			DoughBoss_Voucher::link_redemption_to_order( $voucher_idem, $order_id );
		}
		$order = DoughBoss_Order::get( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'doughboss_webhook_order_missing', __( 'The recovered order could not be loaded.', 'doughboss' ), array( 'status' => 500 ) );
		}
		if ( empty( $created['replayed'] ) ) {
			$this->send_confirmation( $order );
		}
		return $this->finalize_stripe_order( $snapshot, $order, $pi_id );
	}

	/**
	 * POST /catering/tyro-webhook — Tyro equivalent of catering_stripe_webhook().
	 *
	 * UNVERIFIED against live Tyro webhook docs (see DoughBoss_Tyro's class
	 * docblock) — the exact event/order field names below are best-effort. This
	 * handler is deliberately conservative: it only ever marks a leg paid on an
	 * unambiguous captured-order payload, and — like the Stripe webhooks —
	 * never refunds or creates orders. Confirm the real payload shape against
	 * one live test delivery before relying on this in production.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function catering_tyro_webhook( WP_REST_Request $request ) {
		return $this->tyro_webhook( $request );
	}

	/**
	 * POST /tyro-webhook — storefront payment reconciliation safety-net, the
	 * Tyro equivalent of stripe_webhook(). Same invariants: never creates
	 * orders, never refunds — only ever surfaces a payment-with-no-order for a
	 * human to review from the Orders screen. UNVERIFIED against live Tyro
	 * webhook docs — see the class docblock on DoughBoss_Tyro and the note on
	 * catering_tyro_webhook() above.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function tyro_webhook( WP_REST_Request $request ) {
		$payload = $request->get_body();
		$sig     = $request->get_header( DoughBoss_Settings::tyro_webhook_signature_header() );

		if ( ! DoughBoss_Tyro::verify_webhook_signature( $payload, $sig ) ) {
			return new WP_Error( 'doughboss_wh_sig', __( 'Invalid signature.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) || empty( $event['data']['id'] ) ) {
			return rest_ensure_response( array( 'received' => true ) );
		}

		$type      = strtoupper( sanitize_key( $event['type'] ) );
		$reference = DoughBoss_Tyro::canonical_id( $event['data']['id'] );
		$resource  = isset( $event['data']['resource'] ) ? sanitize_key( $event['data']['resource'] ) : 'payrequest';
		if ( '' === $reference || ! in_array( $resource, array( 'payrequest', 'pay_request' ), true ) ) {
			return rest_ensure_response( array( 'received' => true ) );
		}
		$event_key = hash( 'sha256', $type . '|' . $resource . '|' . $reference );
		if ( ! DoughBoss_Payment_Attempts::claim_event( $event_key, 'tyro', $reference, $type ) ) {
			$event_outcome = DoughBoss_Payment_Attempts::event_outcome( $event_key );
			if ( null === $event_outcome || 'retry' === $event_outcome ) {
				return new WP_Error( 'doughboss_wh_storage', __( 'Payment event storage is temporarily unavailable.', 'doughboss' ), array( 'status' => 503 ) );
			}
			return rest_ensure_response( array( 'received' => true, 'duplicate' => true ) );
		}

		$intent = DoughBoss_Tyro::retrieve_payment_intent( $reference );
		if ( is_wp_error( $intent ) ) {
			if ( 'doughboss_pay_attempt' === $intent->get_error_code() ) {
				DoughBoss_Payment_Attempts::complete_event( $event_key, 'unknown_reference' );
				return rest_ensure_response( array( 'received' => true, 'ignored' => true ) );
			}
			DoughBoss_Payment_Attempts::complete_event( $event_key, 'retry' );
			return new WP_Error( 'doughboss_tyro_webhook_retry', __( 'Tyro payment retrieval failed.', 'doughboss' ), array( 'status' => 500 ) );
		}

		$meta = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
		if ( 'succeeded' === $intent['status'] ) {
			if ( isset( $meta['context'] ) && 'catering' === $meta['context'] ) {
				$this->reconcile_catering_intent( $intent, $meta );
			} elseif ( ! DoughBoss_Order::payment_intent_used( $reference ) ) {
				$this->record_unreconciled_payment( $reference, $intent );
			}
		}
		DoughBoss_Payment_Attempts::complete_event( $event_key, 'processed' );

		return rest_ensure_response( array( 'received' => true ) );
	}

	/**
	 * POST /square-webhook — storefront payment reconciliation safety-net, the
	 * Square equivalent of tyro_webhook().
	 *
	 * Ordinary cart payments retain reconciliation-only behavior. Their flow
	 * charges the card synchronously and the browser's /checkout is the only path
	 * that can create an order, so the one failure this endpoint exists to catch
	 * is "Square took the money but /checkout never landed". That is surfaced on
	 * the Orders screen for a human decision rather than auto-fulfilled — money
	 * movement without a verified cart is never automated here. The narrow
	 * approved-request exception reuses its immutable approval-linked snapshot
	 * and the same transactional paid fulfillment as browser confirmation.
	 * This endpoint never initiates a charge or refund.
	 *
	 * Nothing in the delivered payload is trusted: the signature is checked
	 * first, then the payment is re-read from Square and re-bound to this site's
	 * own attempt row before anything is recorded.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function square_webhook( WP_REST_Request $request ) {
		$payload = $request->get_body();
		$sig     = $request->get_header( DoughBoss_Square::webhook_signature_header() );

		if ( ! DoughBoss_Square::verify_webhook_signature( $payload, $sig ) ) {
			return new WP_Error( 'doughboss_wh_sig', __( 'Invalid signature.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) || empty( $event['event_id'] ) ) {
			return rest_ensure_response( array( 'received' => true ) );
		}

		$type     = strtolower( sanitize_text_field( (string) $event['type'] ) );
		$event_id = sanitize_text_field( (string) $event['event_id'] );
		$data     = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();
		$resource = isset( $data['type'] ) ? sanitize_key( (string) $data['type'] ) : '';
		$reference = isset( $data['id'] ) ? DoughBoss_Square::canonical_id( $data['id'] ) : '';

		// 2.50.0 multi-account: the one application webhook subscription
		// receives every authorized seller's events (signed with the same
		// app-level key). Route by the payload's merchant_id; a seller this site
		// does not serve is acknowledged and ignored.
		$merchant_id = isset( $event['merchant_id'] ) && is_string( $event['merchant_id'] ) ? $event['merchant_id'] : '';
		if ( 'oauth.authorization.revoked' === $type ) {
			$revoked = class_exists( 'DoughBoss_Square_OAuth' ) ? DoughBoss_Square_OAuth::handle_revocation( $merchant_id ) : 0;
			return rest_ensure_response( array( 'received' => true, 'outcome' => $revoked > 0 ? 'disconnected' : 'ignored' ) );
		}
		$route = class_exists( 'DoughBoss_Square_OAuth' ) ? DoughBoss_Square_OAuth::route_event( $event ) : array( 'route' => 'legacy', 'shop' => '' );
		if ( 'ignore' === $route['route'] ) {
			return rest_ensure_response( array( 'received' => true, 'ignored' => true ) );
		}
		$route_binding = null;
		if ( 'shop' === $route['route'] ) {
			$route_native  = DoughBoss_Square_OAuth::native_location_id( $route['shop'] );
			$route_binding = $route_native ? DoughBoss_Square::shop_binding( $route_native ) : null;
			$route_binding = is_array( $route_binding ) ? $route_binding : null;
		}

		// Square Subscriptions (paid Club): keep the Club customer group in step.
		// The Club lives in the home shop's seller account only.
		if ( '' !== $reference && '' !== $event_id && 'subscription' === $resource && 0 === strpos( $type, 'subscription.' ) && class_exists( 'DoughBoss_Square_Tiers' )
			&& ! $this->square_event_is_home_seller( $route ) ) {
			return rest_ensure_response( array( 'received' => true, 'ignored' => true ) );
		}
		if ( '' !== $reference && '' !== $event_id && 'subscription' === $resource && 0 === strpos( $type, 'subscription.' ) && class_exists( 'DoughBoss_Square_Tiers' ) ) {
			$event_key = hash( 'sha256', 'square|' . $event_id );
			if ( ! DoughBoss_Payment_Attempts::claim_event( $event_key, 'square', $reference, $type ) ) {
				$event_outcome = DoughBoss_Payment_Attempts::event_outcome( $event_key );
				if ( null === $event_outcome || 'retry' === $event_outcome ) {
					return new WP_Error( 'doughboss_wh_storage', __( 'Payment event storage is temporarily unavailable.', 'doughboss' ), array( 'status' => 503 ) );
				}
				return rest_ensure_response( array( 'received' => true, 'duplicate' => true ) );
			}
			$outcome = DoughBoss_Square_Tiers::reconcile_subscription( $reference );
			DoughBoss_Payment_Attempts::complete_event( $event_key, $outcome );
			if ( 'retry' === $outcome ) {
				return new WP_Error( 'doughboss_wh_retry', __( 'Will retry.', 'doughboss' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'received' => true, 'outcome' => $outcome ) );
		}

		// Refunds (from WP, Square Dashboard or POS) and card disputes. Both are
		// re-read from Square and bound to a payment this site owns first.
		if ( '' !== $reference && '' !== $event_id && ( ( 'refund' === $resource && 0 === strpos( $type, 'refund.' ) ) || ( 'dispute' === $resource && 0 === strpos( $type, 'dispute.' ) ) ) ) {
			$object = isset( $data['object'] ) && is_array( $data['object'] ) ? $data['object'] : array();
			$hint   = 'refund' === $resource
				? ( isset( $object['refund']['payment_id'] ) ? (string) $object['refund']['payment_id'] : '' )
				: ( isset( $object['dispute']['disputed_payment']['payment_id'] ) ? (string) $object['dispute']['disputed_payment']['payment_id'] : '' );
			$event_key = hash( 'sha256', 'square|' . $event_id );
			if ( ! DoughBoss_Payment_Attempts::claim_event( $event_key, 'square', $reference, $type ) ) {
				$event_outcome = DoughBoss_Payment_Attempts::event_outcome( $event_key );
				if ( null === $event_outcome || 'retry' === $event_outcome ) {
					return new WP_Error( 'doughboss_wh_storage', __( 'Payment event storage is temporarily unavailable.', 'doughboss' ), array( 'status' => 503 ) );
				}
				return rest_ensure_response( array( 'received' => true, 'duplicate' => true ) );
			}
			$outcome = 'refund' === $resource
				? DoughBoss_Square::reconcile_refund_event( $reference, $hint )
				: DoughBoss_Square::reconcile_dispute_event( $reference, $hint, $type );
			DoughBoss_Payment_Attempts::complete_event( $event_key, $outcome );
			if ( 'retry' === $outcome ) {
				return new WP_Error( 'doughboss_wh_retry', __( 'Will retry.', 'doughboss' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'received' => true, 'outcome' => $outcome ) );
		}

		// Catering Square invoices: re-read the invoice from Square and mark the
		// leg paid only when Square says PAID for the exact amount at our location.
		if ( 'invoice' === $resource && 0 === strpos( $type, 'invoice.' ) && '' !== $event_id && class_exists( 'DoughBoss_Square_Invoices' ) ) {
			$invoice_ref = DoughBoss_Square_Invoices::canonical_invoice_id( isset( $data['id'] ) ? $data['id'] : '' );
			if ( '' === $invoice_ref ) {
				return rest_ensure_response( array( 'received' => true, 'ignored' => true ) );
			}
			$event_key = hash( 'sha256', 'square|' . $event_id );
			if ( ! DoughBoss_Payment_Attempts::claim_event( $event_key, 'square', $invoice_ref, $type ) ) {
				$event_outcome = DoughBoss_Payment_Attempts::event_outcome( $event_key );
				if ( null === $event_outcome || 'retry' === $event_outcome ) {
					return new WP_Error( 'doughboss_wh_storage', __( 'Payment event storage is temporarily unavailable.', 'doughboss' ), array( 'status' => 503 ) );
				}
				return rest_ensure_response( array( 'received' => true, 'duplicate' => true ) );
			}
			$outcome = DoughBoss_Square_Invoices::reconcile( $invoice_ref );
			DoughBoss_Payment_Attempts::complete_event( $event_key, $outcome );
			if ( 'retry' === $outcome ) {
				return new WP_Error( 'doughboss_wh_retry', __( 'Invoice reconciliation will be retried.', 'doughboss' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'received' => true, 'outcome' => $outcome ) );
		}

		// Square-hosted checkout: payment.* and order.updated events are only
		// lookup hints. The attempt is resolved by its bound Order/Payment and
		// settled through the same verification and order path as the browser
		// return, so both racing paths create one order at most.
		$hosted_event = '' !== $reference && '' !== $event_id
			&& ( ( 'payment' === $resource && 0 === strpos( $type, 'payment.' ) ) || ( 'order_updated' === $resource && 'order.updated' === $type ) );
		$hosted_attempt = $hosted_event ? DoughBoss_Square_Hosted::attempt_for_event( $resource, $reference, $data ) : null;
		if ( is_array( $hosted_attempt ) ) {
			$event_key = hash( 'sha256', 'square|' . $event_id );
			if ( ! DoughBoss_Payment_Attempts::claim_event( $event_key, 'square', $reference, $type ) ) {
				$event_outcome = DoughBoss_Payment_Attempts::event_outcome( $event_key );
				if ( null === $event_outcome || 'retry' === $event_outcome ) {
					return new WP_Error( 'doughboss_wh_storage', __( 'Payment event storage is temporarily unavailable.', 'doughboss' ), array( 'status' => 503 ) );
				}
				return rest_ensure_response( array( 'received' => true, 'duplicate' => true ) );
			}
			$settled = $this->square_hosted_settle( $hosted_attempt );
			if ( is_wp_error( $settled ) ) {
				$held = DoughBoss_Payment_Attempts::find( (int) $hosted_attempt['id'] );
				$terminal_hold = in_array( $settled->get_error_code(), array( 'doughboss_paid_reconciliation_required', 'doughboss_pay_mismatch', 'doughboss_square_hosted_review', 'doughboss_receipt_basis_unverified', 'doughboss_pay_snapshot_missing' ), true );
				if ( $terminal_hold && is_array( $held ) && ! empty( $held['provider_reference'] ) && ! DoughBoss_Order::payment_intent_used( (string) $held['provider_reference'] )
					&& $this->record_unreconciled_payment( (string) $held['provider_reference'], array( 'amount' => (int) $held['amount_minor'], 'currency' => strtolower( (string) $held['currency'] ) ) ) ) {
					DoughBoss_Payment_Attempts::complete_event( $event_key, 'processed' );
					return rest_ensure_response( array( 'received' => true, 'flagged' => true ) );
				}
				DoughBoss_Payment_Attempts::complete_event( $event_key, 'retry' );
				return new WP_Error( 'doughboss_square_webhook_retry', __( 'Square checkout reconciliation will be retried.', 'doughboss' ), array( 'status' => 500 ) );
			}
			DoughBoss_Payment_Attempts::complete_event( $event_key, 'processed' );
			return rest_ensure_response( array( 'received' => true, 'outcome' => $settled['state'] ) );
		}

		// Only payment events carry a payment id worth reconciling. Everything
		// else (refunds, disputes, catalog, …) is acknowledged and ignored so
		// Square does not retry a delivery this site will never act on.
		if ( '' === $reference || '' === $event_id || 'payment' !== $resource || 0 !== strpos( $type, 'payment.' ) ) {
			return rest_ensure_response( array( 'received' => true, 'ignored' => true ) );
		}

		$event_key = hash( 'sha256', 'square|' . $event_id );
		if ( ! DoughBoss_Payment_Attempts::claim_event( $event_key, 'square', $reference, $type ) ) {
			$event_outcome = DoughBoss_Payment_Attempts::event_outcome( $event_key );
			if ( null === $event_outcome || 'retry' === $event_outcome ) {
				return new WP_Error( 'doughboss_wh_storage', __( 'Payment event storage is temporarily unavailable.', 'doughboss' ), array( 'status' => 503 ) );
			}
			return rest_ensure_response( array( 'received' => true, 'duplicate' => true ) );
		}

		// Re-read the payment from Square rather than believing the delivered
		// object; retrieve_payment_intent() also re-binds it to this site's
		// attempt row (amount, currency, reference, Square location).
		$intent = DoughBoss_Square::retrieve_payment_intent( $reference );
		if ( is_wp_error( $intent ) ) {
			$code = $intent->get_error_code();
			if ( 'doughboss_pay_attempt' === $code ) {
				// No attempt row matches. Usually this is simply another
				// application on the same Square account. But it is ALSO how a
				// charge that succeeded and then failed to bind looks — real
				// money with no local record. Ask Square whether the payment is
				// provably ours (our location + our db-attempt- reference); if it
				// is, flag it for a human rather than dropping it.
				$orphan = DoughBoss_Square::orphan_payment( $reference, $route_binding );
				if (
					! is_wp_error( $orphan )
					&& 'succeeded' === $orphan['status']
					&& ! DoughBoss_Order::payment_intent_used( $reference )
				) {
					$this->record_unreconciled_payment( $reference, $orphan );
					DoughBoss_Payment_Attempts::complete_event( $event_key, 'processed' );
					return rest_ensure_response( array( 'received' => true, 'flagged' => true ) );
				}
				DoughBoss_Payment_Attempts::complete_event( $event_key, 'unknown_reference' );
				return rest_ensure_response( array( 'received' => true, 'ignored' => true ) );
			}
			if ( in_array( $code, array( 'doughboss_pay_mismatch', 'doughboss_pay_id' ), true ) ) {
				// A payment that no longer binds to its attempt. retrieve_payment_intent()
				// has already marked the attempt 'mismatch' for the operator.
				DoughBoss_Payment_Attempts::complete_event( $event_key, 'unknown_reference' );
				return rest_ensure_response( array( 'received' => true, 'ignored' => true ) );
			}
			DoughBoss_Payment_Attempts::complete_event( $event_key, 'retry' );
			return new WP_Error( 'doughboss_square_webhook_retry', __( 'Square payment retrieval failed.', 'doughboss' ), array( 'status' => 500 ) );
		}

		if ( in_array( (string) $intent['status'], array( 'failed', 'voided' ), true ) ) {
			$attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $reference );
			$metadata = is_array( $attempt ) ? DoughBoss_Payment_Attempts::metadata( $attempt ) : array();
			if ( is_array( $attempt ) && ! empty( $metadata['voucher_code'] ) && ! empty( $metadata['voucher_reservation_key'] ) ) {
				// release_payment_reservation() re-checks the durable terminal state,
				// Square v2 binding and exact voucher owner under the voucher lock.
				$released = DoughBoss_Voucher::release_payment_reservation(
					(string) $metadata['voucher_code'],
					(string) $metadata['voucher_reservation_key'],
					(int) $attempt['id']
				);
				if ( is_wp_error( $released ) ) {
					DoughBoss_Payment_Attempts::complete_event( $event_key, 'retry' );
					return new WP_Error( 'doughboss_square_webhook_retry', __( 'Square payment reconciliation is waiting for voucher storage.', 'doughboss' ), array( 'status' => 500 ) );
				}
			}
		} elseif ( 'succeeded' === $intent['status'] && ! DoughBoss_Order::payment_intent_used( $reference ) ) {
			$attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $reference );
			if ( is_array( $attempt ) && 'approved_preorder' === ( isset( $attempt['context'] ) ? (string) $attempt['context'] : '' ) ) {
				$recovered = $this->recover_approved_square_order( $attempt, $reference );
				if ( is_wp_error( $recovered ) ) {
					DoughBoss_Payment_Attempts::complete_event( $event_key, 'retry' );
					return $recovered;
				}
				if ( ! $recovered ) {
					$this->record_unreconciled_payment( $reference, $intent );
				}
			} else {
				$this->record_unreconciled_payment( $reference, $intent );
			}
		}
		DoughBoss_Payment_Attempts::complete_event( $event_key, 'processed' );

		return rest_ensure_response( array( 'received' => true ) );
	}

	/**
	 * Whether a routed Square event comes from the seller account that owns
	 * the Club (the home shop): that shop's OAuth seller when it is connected,
	 * otherwise the legacy single-account seller.
	 *
	 * @param array $route { route, shop } from DoughBoss_Square_OAuth::route_event().
	 * @return bool
	 */
	private function square_event_is_home_seller( array $route ) {
		if ( ! class_exists( 'DoughBoss_Square_OAuth' ) || ! class_exists( 'DoughBoss_Square_Tiers' ) ) {
			return true;
		}
		$home      = (int) DoughBoss_Square_Tiers::home_location();
		$home_slug = '';
		foreach ( DoughBoss_Square_OAuth::SHOPS as $shop ) {
			if ( $home && DoughBoss_Square_OAuth::native_location_id( $shop ) === $home ) {
				$home_slug = $shop;
			}
		}
		if ( '' !== $home_slug && DoughBoss_Square_OAuth::is_connected( $home_slug ) ) {
			return 'shop' === $route['route'] && $home_slug === $route['shop'];
		}
		return 'legacy' === $route['route'];
	}

	/**
	 * Legacy webhook helper retained for migration compatibility. The active Tyro
	 * Connect webhook verifies the signed event then retrieves the Pay Request by
	 * reference; it does not parse this older payload shape.
	 *
	 * @param array<string,mixed> $event Decoded webhook payload.
	 * @return array{obj:array,meta:array}|null
	 */
	private function reconcile_tyro_event( array $event ) {
		$order_id = '';
		if ( isset( $event['order']['id'] ) && is_scalar( $event['order']['id'] ) ) {
			$order_id = (string) $event['order']['id'];
		} elseif ( isset( $event['orderId'] ) && is_scalar( $event['orderId'] ) ) {
			$order_id = (string) $event['orderId'];
		} elseif ( isset( $event['id'] ) && is_scalar( $event['id'] ) ) {
			$order_id = (string) $event['id'];
		}
		$order_id = sanitize_text_field( $order_id );
		if ( '' === $order_id ) {
			return null;
		}

		$status = '';
		if ( isset( $event['order']['status'] ) && is_scalar( $event['order']['status'] ) ) {
			$status = (string) $event['order']['status'];
		} elseif ( isset( $event['status'] ) && is_scalar( $event['status'] ) ) {
			$status = (string) $event['status'];
		}
		if ( ! in_array( $status, array( 'CAPTURED', 'PAID' ), true ) ) {
			return null; // Not an unambiguous captured-payment event — do nothing.
		}

		$meta = get_transient( 'doughboss_tyro_meta_' . $order_id );
		$meta = is_array( $meta ) ? $meta : array();

		$amount_minor = isset( $event['order']['totalCapturedAmount'] )
			? (int) round( (float) $event['order']['totalCapturedAmount'] * 100 )
			: ( isset( $meta['_amount_minor'] ) ? (int) $meta['_amount_minor'] : 0 );
		$currency = isset( $event['order']['currency'] ) ? strtolower( (string) $event['order']['currency'] ) : '';

		// The composite id our own create_payment_intent()/checkout flow uses is
		// "{order_id}.{session_id}"; reconstruct enough of it for
		// payment_intent_used()/record_unreconciled_payment() to match the same
		// value stored on the order row. The session id half is not present in
		// a webhook payload and is not needed for a payment_intent_id match —
		// DoughBoss_Order::payment_intent_used() does an exact-string lookup, so
		// this webhook path only recognises orders that were, in fact, created
		// with the full composite id already resolved by /checkout — i.e. it
		// exists purely as the "payment succeeded but /checkout never landed"
		// safety net, exactly like the Stripe webhook.
		$obj = array(
			'id'       => $order_id,
			'amount'   => $amount_minor,
			'currency' => $currency,
		);

		return array( 'obj' => $obj, 'meta' => $meta );
	}

	/**
	 * Idempotently mark the paid leg for a succeeded catering PaymentIntent.
	 *
	 * Shared by both webhook endpoints so either one can be the site's single
	 * registered Stripe endpoint (Stripe assigns one signing secret per
	 * endpoint; the plugin stores one secret per mode).
	 *
	 * @param array<string,mixed> $obj  PaymentIntent object from the event.
	 * @param array<string,mixed> $meta PaymentIntent metadata.
	 * @return bool
	 */
	private function reconcile_catering_intent( array $obj, array $meta ) {
		$raw_leg = isset( $meta['leg'] ) ? sanitize_key( (string) $meta['leg'] ) : '';
		if ( ! in_array( $raw_leg, array( DoughBoss_Catering::LEG_DEPOSIT, DoughBoss_Catering::LEG_BALANCE ), true ) ) {
			return false;
		}
		$leg     = self::catering_leg( $raw_leg );
		$intent_id = ! empty( $obj['id'] ) ? sanitize_text_field( (string) $obj['id'] ) : '';
		$enquiry = '' !== $intent_id ? DoughBoss_Catering::find_by_intent( $intent_id ) : null;
		if ( ! $enquiry && ! empty( $meta['enquiry_id'] ) ) {
			$enquiry = DoughBoss_Catering::get( (int) $meta['enquiry_id'] );
		}
		if ( ! $enquiry || ! $this->catering_payment_matches( $enquiry, $leg, $intent_id, $obj ) ) {
			return false;
		}
		return DoughBoss_Catering::mark_paid( (int) $enquiry['id'], $leg );
	}

	/**
	 * Verify a catering payment against both the enquiry and its immutable
	 * durable payment attempt before any paid-state transition is allowed.
	 *
	 * The signed provider object is necessary but not sufficient: the amount,
	 * currency, leg, enquiry, location and stored provider reference must all be
	 * the exact values DoughBoss recorded when the payment was created.
	 *
	 * @param array<string,mixed> $enquiry           Catering enquiry row.
	 * @param string              $leg               deposit or balance.
	 * @param string              $provider_reference Canonical provider reference.
	 * @param array<string,mixed> $payment            Retrieved/signed provider object.
	 * @return bool
	 */
	private function catering_payment_matches( array $enquiry, $leg, $provider_reference, array $payment ) {
		$leg                = self::catering_leg( $leg );
		$provider_reference = sanitize_text_field( (string) $provider_reference );
		$stored_reference   = DoughBoss_Catering::LEG_BALANCE === $leg
			? (string) $enquiry['balance_intent_id']
			: (string) $enquiry['deposit_intent_id'];
		$expected_amount    = DoughBoss_Payment::to_minor_units( DoughBoss_Catering::leg_amount( $enquiry, $leg ) );
		$expected_currency  = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $enquiry['currency'] ) );
		$is_succeeded       = isset( $payment['status'] ) && 'succeeded' === sanitize_key( (string) $payment['status'] );
		$amount             = $is_succeeded && isset( $payment['amount_received'] )
			? absint( $payment['amount_received'] )
			: ( isset( $payment['amount'] ) ? absint( $payment['amount'] ) : -1 );
		$currency           = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( isset( $payment['currency'] ) ? $payment['currency'] : '' ) ) );
		$metadata           = isset( $payment['metadata'] ) && is_array( $payment['metadata'] ) ? $payment['metadata'] : array();
		$attempt            = DoughBoss_Payment_Attempts::find_by_provider_reference( $provider_reference );

		if (
			'' === $provider_reference
			|| ! hash_equals( $stored_reference, $provider_reference )
			|| $expected_amount < 1
			|| $amount !== $expected_amount
			|| $currency !== $expected_currency
			|| ! $attempt
			|| (int) $attempt['amount_minor'] !== $expected_amount
			|| strtoupper( (string) $attempt['currency'] ) !== $expected_currency
			|| (int) $attempt['location_id'] !== (int) $enquiry['location_id']
			|| 'catering' !== (string) $attempt['context']
			|| ( DoughBoss_Catering::LEG_BALANCE === $leg ? 'catering_balance' : 'catering_deposit' ) !== (string) $attempt['purpose']
			|| (int) ( isset( $metadata['enquiry_id'] ) ? $metadata['enquiry_id'] : 0 ) !== (int) $enquiry['id']
			|| sanitize_text_field( (string) ( isset( $metadata['enquiry_number'] ) ? $metadata['enquiry_number'] : '' ) ) !== (string) $enquiry['enquiry_number']
			|| sanitize_key( (string) ( isset( $metadata['context'] ) ? $metadata['context'] : '' ) ) !== 'catering'
			|| sanitize_key( (string) ( isset( $metadata['leg'] ) ? $metadata['leg'] : '' ) ) !== $leg
		) {
			return false;
		}

		return true;
	}

	/**
	 * Record a succeeded storefront PaymentIntent that has no matching order.
	 *
	 * Kept in a small capped option (autoload off) so the Orders screen can
	 * surface it for a human decision. This webhook usually races the
	 * synchronous /checkout call that creates the order, so most entries are
	 * reconciled seconds after being recorded; the admin surface re-checks
	 * payment_intent_used() and prunes reconciled entries before showing
	 * anything. Deliberately no auto-refund.
	 *
	 * @param string              $pi_id PaymentIntent id.
	 * @param array<string,mixed> $obj   PaymentIntent object from the event.
	 * @return bool
	 */
	private function record_unreconciled_payment( $pi_id, array $obj ) {
		global $wpdb;
		$amount   = isset( $obj['amount'] ) ? absint( $obj['amount'] ) : 0;
		$currency = isset( $obj['currency'] ) ? sanitize_key( $obj['currency'] ) : '';

		// Serialize writers with the admin pruner (same named-lock pattern as
		// rate_limited): both do read-modify-write on this option, and a lost
		// update here would permanently drop a money-taken-no-order flag —
		// Stripe will not retry a delivery that already got a 200. If the lock
		// can't be taken, append anyway: a rare duplicate/clobbered entry beats
		// a silently lost one, and the error_log line below always fires.
		$locked = ( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', 'doughboss_unrec_pay', 3 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		wp_cache_delete( 'doughboss_unreconciled_payments', 'options' );
		$list = get_option( 'doughboss_unreconciled_payments', array() );
		if ( ! is_array( $list ) ) {
			$list = array();
		}

		$already = false;
		foreach ( $list as $entry ) {
			if ( isset( $entry['id'] ) && $entry['id'] === $pi_id ) {
				$already = true; // Stripe retries deliveries; record each intent once.
				break;
			}
		}

		if ( ! $already ) {
			$list[] = array(
				'id'       => $pi_id,
				'amount'   => $amount,
				'currency' => $currency,
				'time'     => time(),
			);

			if ( count( $list ) > 50 ) {
				$list = array_slice( $list, -50 );
			}
			$saved = update_option( 'doughboss_unreconciled_payments', $list, false );
			if ( ! $saved ) {
				wp_cache_delete( 'doughboss_unreconciled_payments', 'options' );
				$stored = get_option( 'doughboss_unreconciled_payments', array() );
				$saved  = is_array( $stored ) && (bool) array_filter(
					$stored,
					static function ( $entry ) use ( $pi_id ) {
						return isset( $entry['id'] ) && $entry['id'] === $pi_id;
					}
				);
			}
		} else {
			$saved = true;
		}

		if ( $locked ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'doughboss_unrec_pay' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		if ( $already ) {
			return true;
		}

		if ( $saved && function_exists( 'error_log' ) ) {
			error_log( sprintf( 'DoughBoss: unreconciled PaymentIntent %s (%d %s) succeeded with no matching order.', $pi_id, $amount, $currency ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log — deliberate greppable audit trail for money reconciliation.
		}
		return (bool) $saved;
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

		// Plain-text email body, same reasoning as $blog above: decode entities
		// get_the_title() adds for HTML display so "&" doesn't show as "&#038;".
		$package = DoughBoss_Catering::package_label( $enquiry );
		$catering_email = DoughBoss_Settings::catering_email();
		$customer_email = (string) $enquiry['customer_email'];
		$customer_email_valid = false === strpos( $customer_email, "\r" ) && false === strpos( $customer_email, "\n" ) && is_email( $customer_email );
		$catering_email_valid = false === strpos( $catering_email, "\r" ) && false === strpos( $catering_email, "\n" ) && is_email( $catering_email );
		$customer_headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$staff_headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( $catering_email_valid ) { $customer_headers[] = 'Reply-To: ' . $catering_email; }
		if ( $customer_email_valid ) { $staff_headers[] = 'Reply-To: ' . $customer_email; }

		$location_id = absint( $enquiry['location_id'] );
		$location = $location_id ? DoughBoss_Locations::get( $location_id ) : null;
		$location_label = $location
			? sprintf( '%1$s (ID %2$d)', (string) $location->name, $location_id )
			: sprintf( __( 'Unavailable (saved location ID %d)', 'doughboss' ), $location_id );
		$not_provided = __( 'Not provided', 'doughboss' );
		$to_confirm = __( 'To be confirmed', 'doughboss' );
		$currency = sanitize_text_field( (string) $enquiry['currency'] );

		$customer_body = sprintf(
			/* translators: saved enquiry name, reference, location, package, guests, event date/time, order type and indicative total/deposit. */
			__( "Hi %1\$s,\n\nThanks for your catering enquiry %2\$s.\n\nStatus: Awaiting staff confirmation\nLocation: %3\$s\nPackage: %4\$s\nGuests: %5\$d\nEvent date: %6\$s\nEvent time: %7\$s\nOrder type: %8\$s\nIndicative total: %9\$s\nIndicative deposit: %10\$s\n\nThis is an enquiry, not a confirmed booking. Pricing is indicative. No payment has been taken by submitting this enquiry.\n\nReply to this email to update your request. Our catering team must confirm availability, the menu, final pricing, collection details and any payment requirements before accepting a booking.\n", 'doughboss' ),
			(string) $enquiry['customer_name'],
			(string) $enquiry['enquiry_number'],
			$location_label,
			$package,
			(int) $enquiry['guest_count'],
			'' !== (string) $enquiry['event_date'] ? (string) $enquiry['event_date'] : $to_confirm,
			'' !== (string) $enquiry['event_time'] ? (string) $enquiry['event_time'] : $to_confirm,
			ucfirst( (string) $enquiry['order_type'] ),
			$currency . ' ' . DoughBoss_Settings::format_price( $enquiry['quote_total'] ),
			$currency . ' ' . DoughBoss_Settings::format_price( $enquiry['deposit_amount'] )
		);

		if ( $customer_email_valid && false === wp_mail( $customer_email, $subject, $customer_body, $customer_headers ) ) {
			error_log( 'DoughBoss mail: catering enquiry email to customer failed for ' . $enquiry['enquiry_number'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$staff_body = sprintf(
			/* translators: staff catering notification assembled exclusively from the saved enquiry row. */
			__( "A new catering enquiry was saved and is awaiting staff confirmation. Pricing is indicative. No booking has been confirmed and no payment was taken by submitting this enquiry.\n\nReference: %1\$s\nLocation: %2\$s\nPackage: %3\$s\nGuests: %4\$d\nEvent date: %5\$s\nEvent time: %6\$s\nOrder type: %7\$s\nAddress: %8\$s\n\nCustomer name: %9\$s\nCustomer email: %10\$s\nCustomer phone: %11\$s\nDietary notes: %12\$s\nNotes: %13\$s\n\nIndicative subtotal: %14\$s\nIndicative delivery fee: %15\$s\nIndicative total: %16\$s\nIndicative deposit: %17\$s\nIndicative balance: %18\$s\n", 'doughboss' ),
			(string) $enquiry['enquiry_number'],
			$location_label,
			$package,
			(int) $enquiry['guest_count'],
			'' !== (string) $enquiry['event_date'] ? (string) $enquiry['event_date'] : $to_confirm,
			'' !== (string) $enquiry['event_time'] ? (string) $enquiry['event_time'] : $to_confirm,
			ucfirst( (string) $enquiry['order_type'] ),
			'' !== (string) $enquiry['address'] ? (string) $enquiry['address'] : $not_provided,
			(string) $enquiry['customer_name'],
			$customer_email,
			'' !== (string) $enquiry['customer_phone'] ? (string) $enquiry['customer_phone'] : $not_provided,
			'' !== (string) $enquiry['dietary'] ? (string) $enquiry['dietary'] : $not_provided,
			'' !== (string) $enquiry['notes'] ? (string) $enquiry['notes'] : $not_provided,
			$currency . ' ' . DoughBoss_Settings::format_price( $enquiry['subtotal'] ),
			$currency . ' ' . DoughBoss_Settings::format_price( $enquiry['delivery_fee'] ),
			$currency . ' ' . DoughBoss_Settings::format_price( $enquiry['quote_total'] ),
			$currency . ' ' . DoughBoss_Settings::format_price( $enquiry['deposit_amount'] ),
			$currency . ' ' . DoughBoss_Settings::format_price( $enquiry['balance_amount'] )
		);
		if ( $catering_email_valid && false === wp_mail( $catering_email, $subject, $staff_body, $staff_headers ) ) {
			error_log( 'DoughBoss mail: catering enquiry email to shop failed for ' . $enquiry['enquiry_number'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Send the deliberately non-confirming after-hours request email.
	 *
	 * This has separate wording from send_confirmation(): a request must never
	 * sound like a paid, timed or kitchen-accepted order.
	 *
	 * @param object $order Request row.
	 * @return void
	 */
	private function send_preorder_request_notification( $order ) {
		$blog    = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$shop = DoughBoss_Locations::get( (int) $order->location_id );
		$shop_label = $shop ? sanitize_text_field( (string) $shop->name ) : __( 'Selected pickup shop', 'doughboss' );
		$policy = DoughBoss_Settings::staff_approval_policy();
		$generic_review = ! $shop || 'revesby' !== (string) $shop->slug || 'required' === $policy['mode'];
		$subject = sprintf( __( '[%1$s] Revesby pre-order request received — pending confirmation', 'doughboss' ), $blog );
		$body    = sprintf(
			/* translators: 1: customer name, 2: order number, 3: formatted amount. */
			__( "Hi %1\$s,\n\nWe received your Revesby pre-order request (%2\$s) for %3\$s. This is not a confirmed order and no payment has been taken.\n\nRevesby will review it first thing in the morning and call you to agree pickup timing before confirming availability. Please do not travel to the shop until it is confirmed.\n\nThank you,\nDough Boss", 'doughboss' ),
			$order->customer_name,
			$order->order_number,
			DoughBoss_Settings::format_price( $order->total )
		);
		if ( $generic_review ) {
			$subject = sprintf( __( '[%1$s] Pickup request received — awaiting review', 'doughboss' ), $blog );
			$body = sprintf( __( "Hi %1\$s,\n\nWe received your pickup request %2\$s for %3\$s. It is unpaid and awaiting review; this is not a confirmed order.\n\nThe team must approve the request before payment is available. No pickup time or kitchen preparation is confirmed. Please do not travel to the shop until your order is confirmed.\n\nThank you,\nDough Boss", 'doughboss' ), (string) $order->customer_name, (string) $order->order_number, $shop_label );
		}

		if ( is_email( $order->customer_email ) && false === wp_mail( $order->customer_email, $subject, $body ) ) {
			error_log( 'DoughBoss mail: preorder request email to customer failed for #' . $order->order_number ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$orders_email = DoughBoss_Settings::orders_email();
		$item_lines = array();
		foreach ( DoughBoss_Order::get_items( (int) $order->id ) as $item ) {
			$item_lines[] = sprintf( '%d x %s — %s', (int) $item['quantity'], (string) $item['name'], DoughBoss_Settings::format_price( (float) $item['line_total'] ) );
		}
		$review_url = add_query_arg( 'page', 'doughboss-board', admin_url( 'admin.php' ) );
		$staff_body = sprintf(
			"New unpaid pre-order request %1\$s\n\nCustomer: %2\$s\nEmail: %3\$s\nPhone: %4\$s\n\nItems:\n%5\$s\n\nTotal if approved: %6\$s\nNotes: %7\$s\n\nThis request is not paid, confirmed, printed or sent to the POS. The named approver must sign in and approve or reject it:\n%8\$s",
			(string) $order->order_number,
			(string) $order->customer_name,
			(string) $order->customer_email,
			(string) $order->customer_phone,
			implode( "\n", $item_lines ),
			DoughBoss_Settings::format_price( (float) $order->total ),
			'' !== (string) $order->notes ? (string) $order->notes : __( 'None provided', 'doughboss' ),
			$review_url
		);
		$staff_body = sprintf( __( 'Pickup shop: %s', 'doughboss' ), $shop_label ) . "\n\n" . $staff_body;
		$approver = get_userdata( DoughBoss_Settings::preorder_approver_user_id() );
		$recipients = array( $orders_email, 'info@doughboss.com.au', $approver && ! empty( $approver->user_email ) ? (string) $approver->user_email : '' );
		$recipients = array_values( array_unique( array_filter( array_map( 'strtolower', $recipients ), 'is_email' ) ) );
		foreach ( $recipients as $recipient ) {
			if ( ! wp_mail( $recipient, $subject, $staff_body ) ) {
				error_log( 'DoughBoss mail: preorder request operational alert failed for #' . $order->order_number ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		$approver_phone = DoughBoss_SMS::normalize_phone( DoughBoss_Settings::preorder_approver_phone() );
		if ( '' !== $approver_phone && DoughBoss_SMS::ready() ) {
			$sms_result = DoughBoss_SMS::send(
				$approver_phone,
				sprintf( 'DoughBoss request %1$s (%2$s) is unpaid and needs your approval: %3$s', (string) $order->order_number, DoughBoss_Settings::format_price( (float) $order->total ), $review_url ),
				false
			);
			if ( is_wp_error( $sms_result ) ) {
				error_log( 'DoughBoss SMS: preorder approver alert failed for order #' . (int) $order->id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Send the distinct approved-for-payment or rejected notification.
	 *
	 * @param object $order         Request row.
	 * @param bool   $approved      Whether payment was approved.
	 * @param string $payment_url   Private payment capability URL.
	 * @param string $approver_name Authenticated approver display name.
	 * @param string $expires_at     Original UTC approval deadline.
	 * @return array Mail handoff statuses.
	 */
	private function send_preorder_decision_notification( $order, $approved, $payment_url = '', $approver_name = '', $expires_at = '' ) {
		$blog = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		if ( $approved ) {
			$approver_name = '' !== trim( $approver_name ) ? sanitize_text_field( $approver_name ) : __( 'The DoughBoss approver', 'doughboss' );
			$deadline_timestamp = strtotime( (string) $expires_at . ' UTC' );
			$deadline = false === $deadline_timestamp
				? __( 'the deadline shown on the secure payment page', 'doughboss' )
				: get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $deadline_timestamp ), 'D j M Y, g:ia' );
			$subject = sprintf( __( '[%1$s] Request %2$s approved for secure payment', 'doughboss' ), $blog, (string) $order->order_number );
			$body = sprintf(
				"Hi %1\$s,\n\n%5\$s has approved request %2\$s for %3\$s. It is still unpaid and has not been sent to the kitchen.\n\nUse this private link and start payment before the original deadline, %6\$s:\n%4\$s\n\nFor staff-selected pickup, the payment deadline is also the reservation expiry. Payment must complete before that expiry; a late payment needs staff review and does not guarantee the original pickup slot. Resending this email does not extend that deadline. DoughBoss will send the order to the kitchen only after the payment provider confirms payment on the server. If the page says payment is pending, do not pay again — contact the shop.\n\nThank you,\nDough Boss",
				(string) $order->customer_name,
				(string) $order->order_number,
				DoughBoss_Settings::format_price( (float) $order->total ),
				(string) $payment_url,
				$approver_name,
				$deadline
			);
		} else {
			$subject = sprintf( __( '[%1$s] Update for request %2$s', 'doughboss' ), $blog, (string) $order->order_number );
			$body = sprintf(
				"Hi %1\$s,\n\nDoughBoss could not accept request %2\$s. It has been cancelled and no payment was taken. Please call the shop if you would like help with another order.\n\nThank you,\nDough Boss",
				(string) $order->customer_name,
				(string) $order->order_number
			);
		}
		$customer_accepted = is_email( $order->customer_email ) && wp_mail( $order->customer_email, $subject, $body );
		if ( ! $customer_accepted && is_email( $order->customer_email ) ) {
			error_log( 'DoughBoss mail: preorder decision email to customer failed for order #' . (int) $order->id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		$shop_recipients = array_values( array_unique( array_filter( array_map( 'strtolower', array( DoughBoss_Settings::orders_email(), 'info@doughboss.com.au' ) ), 'is_email' ) ) );
		$shop_accepted = ! empty( $shop_recipients );
		foreach ( $shop_recipients as $shop_recipient ) {
			if ( ! wp_mail( $shop_recipient, $subject, $body ) ) {
				$shop_accepted = false;
				error_log( 'DoughBoss mail: preorder decision copy to shop failed for order #' . (int) $order->id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
		$persisted = $approved ? DoughBoss_Preorder_Approval::record_payment_link_delivery( (int) $order->id, $customer_accepted ) : true;
		$result = array( 'customer_status' => $customer_accepted ? 'accepted' : 'failed', 'shop_status' => $shop_accepted ? 'accepted' : 'failed' );
		if ( ! $persisted ) {
			$result['warning'] = 'delivery_status_not_persisted';
		}
		return $result;
	}

	private function send_confirmation( $order ) {
		$blog = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

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

		// Subject/body come from the owner-editable templates (DoughBoss →
		// Message Templates), falling back to the built-in default copy.
		$vars = array(
			'site_name'    => $blog,
			'order_number' => $order->order_number,
			'customer_name' => $order->customer_name,
			'items'        => implode( "\n", $lines ),
			'total'        => DoughBoss_Settings::format_price( $order->total ),
			'tracking_url' => DoughBoss_Settings::tracking_page_url( $order->order_number ),
			'tracking_instructions' => DoughBoss_Settings::tracking_instructions( $order->order_number ),
		);
		$subject = DoughBoss_Settings::render_template( DoughBoss_Settings::tpl_order_email_subject(), $vars );
		$body    = DoughBoss_Settings::render_template( DoughBoss_Settings::tpl_order_email_body(), $vars );

		// A paid order already received the itemised "placed" confirmation from
		// DoughBoss_Emails (doughboss_order_created). Do not email the customer
		// twice; this template copy remains the fallback when that send failed
		// or the toggle is off. The shop inbox copy below is unchanged.
		$placed_sent = class_exists( 'DoughBoss_Emails' ) && DoughBoss_Emails::stage_sent( (int) $order->id, 'placed' );
		if ( ! $placed_sent && is_email( $order->customer_email ) && false === wp_mail( $order->customer_email, $subject, $body ) ) {
			error_log( 'DoughBoss mail: order confirmation email to customer failed for #' . $order->order_number ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$orders_email = DoughBoss_Settings::orders_email();
		if ( is_email( $orders_email ) && false === wp_mail( $orders_email, $subject, $body ) ) {
			error_log( 'DoughBoss mail: order confirmation email to shop failed for #' . $order->order_number ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
