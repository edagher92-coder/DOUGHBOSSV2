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
		add_filter( 'rest_post_dispatch', array( $this, 'protect_tracking_response' ), 10, 3 );
	}

	/**
	 * Supplement WordPress's default CORS handling with the staff console's
	 * origin on doughboss/v1 routes. Deliberately does NOT remove WordPress's
	 * own 'rest_send_cors_headers' callback â€” this plugin previously did, which
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
	 * (Application Password auth). Scoped â€” never site-wide wildcard, and never
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
		// /mercure/test) stay registered â€” the Settings screen uses them.
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
				'methods'    çžuÒÚ$z{-®éÜj×æWfW"ÆæFVB  ’òò6fWG’æWBÂW†7FÇ’Æ–¶RF†R7G&—RvV&†öö²à ’Fö&¢Ò'&’€ ’v–BrÓâF÷&FW%ö–BÀ ’vÖ÷VçBrÓâFÖ÷VçEöÖ–æ÷"À ’v7W'&Væ7’rÓâF7W'&Væ7’À ’“°  —&WGW&â'&’‚vö&¢rÓâFö&¢ÂvÖWFrÓâFÖWF“° —Ð  ’ò¢  ’¢–FV×÷FVçFÇ’Ö&²F†R–BÆVrf÷"7V66VVFVB6FW&–ær–ÖVçD–çFVçBà ’  ’¢6†&VB'’&÷F‚vV&†öö²VæGö–çG26òV—F†W"öæR6â&RF†R6—FRw26–ævÆP ’¢&Vv—7FW&VB7G&—RVæGö–çB…7G&—R76–vç2öæR6–væ–ær6V7&WBW  ’¢VæGö–çC²F†RÇVv–â7F÷&W2öæR6V7&WBW"ÖöFR’à ’  ’¢&Ò'&“Ç7G&–ærÆÖ—†VCâFö&¢–ÖVçD–çFVçBö&¦V7Bg&öÒF†RWfVçBà ’¢&Ò'&“Ç7G&–ærÆÖ—†VCâFÖWF–ÖVçD–çFVçBÖWFFFà ’¢&WGW&â&ööÀ ’¢ð —&—fFRgVæ7F–öâ&V6öæ6–ÆUö6FW&–æuö–çFVçB‚'&’Fö&¢Â'&’FÖWF’° ’G&uöÆVrÒ—76WB‚FÖWF²vÆVruÒ’ò6æ—F—¦Uö¶W’‚‡7G&–ær’FÖWF²vÆVruÒ’¢rs° ––b‚–åö'&’‚G&uöÆVrÂ'&’‚F÷Vv„&÷75ô6FW&–æs£¤ÄTuôDUõ4•BÂF÷Vv„&÷75ô6FW&–æs£¤ÄTuô$Ää4R’ÂG'VR’’° —&WGW&âfÇ6S° —Ð ’FÆVrÒ6VÆc£¦6FW&–æuöÆVr‚G&uöÆVr“° ’F–çFVçEö–BÒV×G’‚Fö&¥²v–BuÒ’ò6æ—F—¦U÷FW‡Eöf–VÆB‚‡7G&–ær’Fö&¥²v–BuÒ’¢rs° ’FVçV—'’ÒrrÓÒF–çFVçEö–BòF÷Vv„&÷75ô6FW&–æs£¦f–æEö'•ö–çFVçB‚F–çFVçEö–B’¢çVÆÃ° ––b‚FVçV—'’bbV×G’‚FÖWF²vVçV—'•ö–BuÒ’’° ’FVçV—'’ÒF÷Vv„&÷75ô6FW&–æs£¦vWB‚†–çB’FÖWF²vVçV—'•ö–BuÒ“° —Ð ––b‚FVçV—'’ÇÂGF†—2Óæ6FW&–æu÷–ÖVçEöÖF6†W2‚FVçV—'’ÂFÆVrÂF–çFVçEö–BÂFö&¢’’° —&WGW&âfÇ6S° —Ð —&WGW&âF÷Vv„&÷75ô6FW&–æs£¦Ö&µ÷–B‚†–çB’FVçV—'•²v–BuÒÂFÆVr“° —Ð  ’ò¢  ’¢fW&–g’6FW&–ær–ÖVçBv–ç7B&÷F‚F†RVçV—'’æB—G2–Ö×WF&ÆP ’¢GW&&ÆR–ÖVçBGFV×B&Vf÷&Rç’–B×7FFRG&ç6—F–öâ—2ÆÆ÷vVBà ’  ’¢F†R6–væVB&÷f–FW"ö&¦V7B—2æV6W76'’'WBæ÷B7Vff–6–VçC¢F†RÖ÷VçBÀ ’¢7W'&Væ7’ÂÆVrÂVçV—'’ÂÆö6F–öâæB7F÷&VB&÷f–FW"&VfW&Væ6R×W7BÆÂ&P ’¢F†RW†7BfÇVW2F÷Vv„&÷72&V6÷&FVBv†VâF†R–ÖVçBv27&VFVBà ’  ’¢&Ò'&“Ç7G&–ærÆÖ—†VCâFVçV—'’6FW&–ærVçV—'’&÷rà ’¢&Ò7G&–ærFÆVrFW÷6—B÷"&Ææ6Rà ’¢&Ò7G&–ærG&÷f–FW%÷&VfW&Væ6R6æöæ–6Â&÷f–FW"&VfW&Væ6Rà ’¢&Ò'&“Ç7G&–ærÆÖ—†VCâG–ÖVçB&WG&–WfVB÷6–væVB&÷f–FW"ö&¦V7Bà ’¢&WGW&â&ööÀ ’¢ð —&—fFRgVæ7F–öâ6FW&–æu÷–ÖVçEöÖF6†W2‚'&’FVçV—'’ÂFÆVrÂG&÷f–FW%÷&VfW&Væ6RÂ'&’G–ÖVçB’° ’FÆVrÒ6VÆc£¦6FW&–æuöÆVr‚FÆVr“° ’G&÷f–FW%÷&VfW&Væ6RÒ6æ—F—¦U÷FW‡Eöf–VÆB‚‡7G&–ær’G&÷f–FW%÷&VfW&Væ6R“° ’G7F÷&VE÷&VfW&Væ6RÒF÷Vv„&÷75ô6FW&–æs£¤ÄTuô$Ää4RÓÓÒFÆVp “ò‡7G&–ær’FVçV—'•²v&Ææ6Uö–çFVçEö–BuÐ “¢‡7G&–ær’FVçV—'•²vFW÷6—Eö–çFVçEö–BuÓ° ’FW‡V7FVEöÖ÷VçBÒF÷Vv„&÷75õ–ÖVçC£§FõöÖ–æ÷%÷Væ—G2‚F÷Vv„&÷75ô6FW&–æs£¦ÆVuöÖ÷VçB‚FVçV—'’ÂFÆVr’“° ’FW‡V7FVEö7W'&Væ7’Ò7G'F÷WW"‚&Vu÷&WÆ6R‚rõµäÕ¦×¥ÒòrÂrrÂ‡7G&–ær’FVçV—'•²v7W'&Væ7’uÒ’“° ’F—5÷7V66VVFVBÒ—76WB‚G–ÖVçE²w7FGW2uÒ’bbw7V66VVFVBrÓÓÒ6æ—F—¦Uö¶W’‚‡7G&–ær’G–ÖVçE²w7FGW2uÒ“° ’FÖ÷VçBÒF—5÷7V66VVFVBbb—76WB‚G–ÖVçE²vÖ÷VçE÷&V6V—fVBuÒ “ò'6–çB‚G–ÖVçE²vÖ÷VçE÷&V6V—fVBuÒ “¢‚—76WB‚G–ÖVçE²vÖ÷VçBuÒ’ò'6–çB‚G–ÖVçE²vÖ÷VçBuÒ’¢Ó“° ’F7W'&Væ7’Ò7G'F÷WW"‚&Vu÷&WÆ6R‚rõµäÕ¦×¥ÒòrÂrrÂ‡7G&–ær’‚—76WB‚G–ÖVçE²v7W'&Væ7’uÒ’òG–ÖVçE²v7W'&Væ7’uÒ¢rr’’“° ’FÖWFFFÒ—76WB‚G–ÖVçE²vÖWFFFuÒ’bb—5ö'&’‚G–ÖVçE²vÖWFFFuÒ’òG–ÖVçE²vÖWFFFuÒ¢'&’‚“° ’FGFV×BÒF÷Vv„&÷75õ–ÖVçEôGFV×G3£¦f–æEö'•÷&÷f–FW%÷&VfW&Væ6R‚G&÷f–FW%÷&VfW&Væ6R“°  ––b€ ’rrÓÓÒG&÷f–FW%÷&VfW&Væ6P —ÇÂ†6…öWVÇ2‚G7F÷&VE÷&VfW&Væ6RÂG&÷f–FW%÷&VfW&Væ6R —ÇÂFW‡V7FVEöÖ÷VçBÂ —ÇÂFÖ÷VçBÓÒFW‡V7FVEöÖ÷Vç@ —ÇÂF7W'&Væ7’ÓÒFW‡V7FVEö7W'&Væ7 —ÇÂFGFV×@ —ÇÂ†–çB’FGFV×E²vÖ÷VçEöÖ–æ÷"uÒÓÒFW‡V7FVEöÖ÷Vç@ —ÇÂ7G'F÷WW"‚‡7G&–ær’FGFV×E²v7W'&Væ7’uÒ’ÓÒFW‡V7FVEö7W'&Væ7 —ÇÂ†–çB’FGFV×E²vÆö6F–öåö–BuÒÓÒ†–çB’FVçV—'•²vÆö6F–öåö–BuÐ —ÇÂv6FW&–ærrÓÒ‡7G&–ær’FGFV×E²v6öçFW‡BuÐ —ÇÂ‚F÷Vv„&÷75ô6FW&–æs£¤ÄTuô$Ää4RÓÓÒFÆVròv6FW&–æuö&Ææ6Rr¢v6FW&–æuöFW÷6—Br’ÓÒ‡7G&–ær’FGFV×E²wW'÷6RuÐ —ÇÂ†–çB’‚—76WB‚FÖWFFF²vVçV—'•ö–BuÒ’òFÖWFFF²vVçV—'•ö–BuÒ¢’ÓÒ†–çB’FVçV—'•²v–BuÐ —ÇÂ6æ—F—¦U÷FW‡Eöf–VÆB‚‡7G&–ær’‚—76WB‚FÖWFFF²vVçV—'•öçVÖ&W"uÒ’òFÖWFFF²vVçV—'•öçVÖ&W"uÒ¢rr’’ÓÒ‡7G&–ær’FVçV—'•²vVçV—'•öçVÖ&W"uÐ —ÇÂ6æ—F—¦Uö¶W’‚‡7G&–ær’‚—76WB‚FÖWFFF²v6öçFW‡BuÒ’òFÖWFFF²v6öçFW‡BuÒ¢rr’’ÓÒv6FW&–ærp —ÇÂ6æ—F—¦Uö¶W’‚‡7G&–ær’‚—76WB‚FÖWFFF²vÆVruÒ’òFÖWFFF²vÆVruÒ¢rr’’ÓÒFÆVp ’’° —&WGW&âfÇ6S° —Ð  —&WGW&âG'VS° —Ð  ’ò¢  ’¢&V6÷&B7V66VVFVB7F÷&Vg&öçB–ÖVçD–çFVçBF†B†2æòÖF6†–ær÷&FW"à ’  ’¢¶WB–â6ÖÆÂ6VB÷F–öâ†WFöÆöBöfb’6òF†R÷&FW'267&VVâ6à ’¢7W&f6R—Bf÷"‡VÖâFV6—6–öââF†—2vV&†öö²W7VÆÇ’&6W2F†P ’¢7–æ6‡&öæ÷W2ö6†V6¶÷WB6ÆÂF†B7&VFW2F†R÷&FW"Â6òÖ÷7BVçG&–W2&P ’¢&V6öæ6–ÆVB6V6öæG2gFW"&V–ær&V6÷&FVC²F†RFÖ–â7W&f6R&RÖ6†V6·0 ’¢–ÖVçEö–çFVçE÷W6VB‚’æB'VæW2&V6öæ6–ÆVBVçG&–W2&Vf÷&R6†÷v–æp ’¢ç—F†–ærâFVÆ–&W&FVÇ’æòWFò×&VgVæBà ’  ’¢&Ò7G&–ærG•ö–B–ÖVçD–çFVçB–Bà ’¢&Ò'&“Ç7G&–ærÆÖ—†VCâFö&¢–ÖVçD–çFVçBö&¦V7Bg&öÒF†RWfVçBà ’¢&WGW&â&ööÀ ’¢ð —&—fFRgVæ7F–öâ&V6÷&E÷Vç&V6öæ6–ÆVE÷–ÖVçB‚G•ö–BÂ'&’Fö&¢’° –vÆö&ÂGwF#° ’FÖ÷VçBÒ—76WB‚Fö&¥²vÖ÷VçBuÒ’ò'6–çB‚Fö&¥²vÖ÷VçBuÒ’¢° ’F7W'&Væ7’Ò—76WB‚Fö&¥²v7W'&Væ7’uÒ’ò6æ—F—¦Uö¶W’‚Fö&¥²v7W'&Væ7’uÒ’¢rs°  ’òò6W&–Æ—¦Rw&—FW'2v—F‚F†RFÖ–â'VæW"‡6ÖRæÖVBÖÆö6²GFW&â0 ’òò&FUöÆ–Ö—FVB“¢&÷F‚Fò&VBÖÖöF–g’×w&—FRöâF†—2÷F–öâÂæBÆ÷7@ ’òòWFFR†W&Rv÷VÆBW&ÖæVçFÇ’G&÷ÖöæW’×F¶VâÖæòÖ÷&FW"fÆr(	@ ’òò7G&—Rv–ÆÂæ÷B&WG'’FVÆ—fW'’F†BÇ&VG’v÷B#â–bF†RÆö6° ’òò6âwB&RF¶VâÂVæBç—v“¢&&RGWÆ–6FRö6Æö&&W&VBVçG'’&VG0 ’òò6–ÆVçFÇ’Æ÷7BöæRÂæBF†RW'&÷%öÆörÆ–æR&VÆ÷rÇv—2f—&W2à ’FÆö6¶VBÒ‚ÓÓÒ†–çB’GwF"ÓævWE÷f"‚GwF"Óç&W&R‚u4TÄT5BtUEôÄô4²‚W2ÂVB’rÂvF÷Vv†&÷75÷Vç&V5÷’rÂ2’’“²òò‡73¦–væ÷&Rv÷&E&W72äD"äF—&V7DFF&6UVW'  —wö66†UöFVÆWFR‚vF÷Vv†&÷75÷Vç&V6öæ6–ÆVE÷–ÖVçG2rÂv÷F–öç2r“° ’FÆ—7BÒvWEö÷F–öâ‚vF÷Vv†&÷75÷Vç&V6öæ6–ÆVE÷–ÖVçG2rÂ'&’‚’“° ––b‚—5ö'&’‚FÆ—7B’’° ’FÆ—7BÒ'&’‚“° —Ð  ’FÇ&VG’ÒfÇ6S° –f÷&V6‚‚FÆ—7B2FVçG'’’° ––b‚—76WB‚FVçG'•²v–BuÒ’bbFVçG'•²v–BuÒÓÓÒG•ö–B’° ’FÇ&VG’ÒG'VS²òò7G&—R&WG&–W2FVÆ—fW&–W3²&V6÷&BV6‚–çFVçBöæ6Rà –'&V³° —Ð —Ð  ––b‚FÇ&VG’’° ’FÆ—7EµÒÒ'&’€ ’v–BrÓâG•ö–BÀ ’vÖ÷VçBrÓâFÖ÷VçBÀ ’v7W'&Væ7’rÓâF7W'&Væ7’À ’wF–ÖRrÓâF–ÖR‚’À ’“°  ––b‚6÷VçB‚FÆ—7B’âS’° ’FÆ—7BÒ'&•÷6Æ–6R‚FÆ—7BÂÓS“° —Ð ’G6fVBÒWFFUö÷F–öâ‚vF÷Vv†&÷75÷Vç&V6öæ6–ÆVE÷–ÖVçG2rÂFÆ—7BÂfÇ6R“° ––b‚G6fVB’° —wö66†UöFVÆWFR‚vF÷Vv†&÷75÷Vç&V6öæ6–ÆVE÷–ÖVçG2rÂv÷F–öç2r“° ’G7F÷&VBÒvWEö÷F–öâ‚vF÷Vv†&÷75÷Vç&V6öæ6–ÆVE÷–ÖVçG2rÂ'&’‚’“° ’G6fVBÒ—5ö'&’‚G7F÷&VB’bb†&ööÂ’'&•öf–ÇFW"€ ’G7F÷&VBÀ —7FF–2gVæ7F–öâ‚FVçG'’’W6R‚G•ö–B’° —&WGW&â—76WB‚FVçG'•²v–BuÒ’bbFVçG'•²v–BuÒÓÓÒG•ö–C° —Ð ’“° —Ð —ÒVÇ6R° ’G6fVBÒG'VS° —Ð  ––b‚FÆö6¶VB’° ’GwF"ÓçVW'’‚GwF"Óç&W&R‚u4TÄT5B$TÄT4UôÄô4²‚W2’rÂvF÷Vv†&÷75÷Vç&V5÷’r’“²òò‡73¦–væ÷&Rv÷&E&W72äD"äF—&V7DFF&6UVW' —Ð  ––b‚FÇ&VG’’° —&WGW&âG'VS° —Ð  ––b‚G6fVBbbgVæ7F–öåöW†—7G2‚vW'&÷%öÆörr’’° –W'&÷%öÆör‚7&–çFb‚tF÷Vv„&÷73¢Vç&V6öæ6–ÆVB–ÖVçD–çFVçBW2‚VBW2’7V66VVFVBv—F‚æòÖF6†–ær÷&FW"ârÂG•ö–BÂFÖ÷VçBÂF7W'&Væ7’’“²òò‡73¦–væ÷&Rv÷&E&W72å…äFWfVÆ÷ÖVçDgVæ7F–öç2æW'&÷%öÆöuöW'&÷%öÆör(	BFVÆ–&W&FRw&W&ÆRVF—BG&–Âf÷"ÖöæW’&V6öæ6–Æ–F–öâà —Ð —&WGW&â†&ööÂ’G6fVC° —Ð  ’ò¢  ’¢&W6öÇfR6FW&–ærVçV—'’g&öÒ&WVW7BÂ&WV—&–ærF†RçVÖ&W"²VÖ–ÂFð ’¢ÖF6‚â&WGW&ç2F†R6ÖRæ÷BÖf÷VæBW'&÷"f÷"Ö—6ÖF6‚Fòfö–BÆV¶–æp ’¢v†–6‚VçV—&–W2W†—7Bà ’  ’¢&Òuõ$U5Eõ&WVW7BG&WVW7B&WVW7Bà ’¢&WGW&â'&“Ç7G&–ærÆÖ—†VCçÅuôW'&÷  ’¢ð —&—fFRgVæ7F–öâ&W6öÇfUö6FW&–æuöVçV—'’‚uõ$U5Eõ&WVW7BG&WVW7B’° ’FçVÖ&W"Ò6æ—F—¦U÷FW‡Eöf–VÆB‚G&WVW7BÓævWE÷&Ò‚vVçV—'•öçVÖ&W"r’“° ’FVÖ–ÂÒ6æ—F—¦UöVÖ–Â‚G&WVW7BÓævWE÷&Ò‚vVÖ–Âr’“° ’FVçV—'’ÒF÷Vv„&÷75ô6FW&–æs£¦vWEö'•öçVÖ&W"‚FçVÖ&W"“°  ––b‚FVçV—'’ÇÂ7G'FöÆ÷vW"‚FVçV—'•²v7W7FöÖW%öVÖ–ÂuÒ’ÓÒ7G'FöÆ÷vW"‚FVÖ–Â’’° —&WGW&âæWruôW'&÷"‚vF÷Vv†&÷75öæ÷Eöf÷VæBrÂõò‚tæòÖF6†–ærVçV—'’f÷VæBâ6†V6²–÷W"&VfW&Væ6RæBVÖ–ÂârÂvF÷Vv†&÷72r’Â'&’‚w7FGW2rÓâCB’“° —Ð —&WGW&âFVçV—'“° —Ð  ’ò¢  ’¢æ÷&ÖÆ—6R–ÖVçBÖÆVr7G&–ærFò¶æ÷vâfÇVRà ’  ’¢&Ò7G&–ærFÆVr&rÆVrà ’¢&WGW&â7G&–ærvFW÷6—Br÷"v&Ææ6Rrà ’¢ð —&—fFR7FF–2gVæ7F–öâ6FW&–æuöÆVr‚FÆVr’° —&WGW&âF÷Vv„&÷75ô6FW&–æs£¤ÄTuô$Ää4RÓÓÒFÆVròF÷Vv„&÷75ô6FW&–æs£¤ÄTuô$Ää4R¢F÷Vv„&÷75ô6FW&–æs£¤ÄTuôDUõ4•C° —Ð  ’ò¢  ’¢VÖ–ÂF†R7W7FöÖW"F†V—"6FW&–ærVçV—'’7VÖÖ'’ÂæBæ÷F–g’F†R6†÷à ’  ’¢&Ò'&“Ç7G&–ærÆÖ—†VCâFVçV—'’7F÷&VBVçV—'’&÷rà ’¢&WGW&âfö–@ ’¢ð —&—fFRgVæ7F–öâ6VæEö6FW&–æuöæ÷F–f–6F–öâ‚FVçV—'’’° ––b‚—5ö'&’‚FVçV—'’’’° —&WGW&ã° —Ð  ’F&ÆörÒw÷7V6–Æ6†'5öFV6öFR‚vWEö÷F–öâ‚v&ÆövæÖRr’ÂTåEõTõDU2“° ’ò¢G&ç6ÆF÷'3¢¢6—FRæÖRÂ#¢VçV—'’çVÖ&W"â¢ð ’G7V&¦V7BÒ7&–çFb‚õò‚u²SG5Ò6FW&–ærVçV—'’S"G2&V6V—fVBrÂvF÷Vv†&÷72r’ÂF&ÆörÂFVçV—'•²vVçV—'•öçVÖ&W"uÒ“°  ’òòÆ–â×FW‡BVÖ–Â&öG’Â6ÖR&V6öæ–ær2F&Æör&÷fS¢FV6öFRVçF—F–W0 ’òòvWE÷F†U÷F—FÆR‚’FG2f÷"…DÔÂF—7Æ’6ò"b"FöW6âwB6†÷r2"b33ƒ²"à ’G6¶vRÒ†–çB’FVçV—'•²w6¶vUö–BuÒòw÷7V6–Æ6†'5öFV6öFR‚vWE÷F†U÷F—FÆR‚†–çB’FVçV—'•²w6¶vUö–BuÒ’ÂTåEõTõDU2’¢õò‚t7W7FöÒrÂvF÷Vv†&÷72r“°  ’F&öG’Ò7&–çFb€ ’ò¢G&ç6ÆF÷'3¢¢æÖRÂ#¢VçV—'’çVÖ&W"Â3¢6¶vRÂC¢wVW7G2ÂS¢WfVçBFFRÂc¢FW÷6—Bâ¢ð •õò‚$†’SÂG2ÅÆåÆåF†æ·2f÷"–÷W"6FW&–ærVçV—'’S%ÂG2åÆåÆå6¶vS¢S5ÂG5ÆäwVW7G3¢SEÂFEÆäWfVçBFFS¢SUÂG5Æä–æF–6F—fRFW÷6—C¢SeÂG5ÆåÆåvRvÆÂ6öæf—&ÒF†RFWF–Ç2æB6VæB–÷W"FW÷6—BÆ–æ²6†÷'FÇ’åÆâ"ÂvF÷Vv†&÷72r’À ’FVçV—'•²v7W7FöÖW%öæÖRuÒÀ ’FVçV—'•²vVçV—'•öçVÖ&W"uÒÀ ’G6¶vRÀ ’†–çB’FVçV—'•²vwVW7Eö6÷VçBuÒÀ ’rrÓÒFVçV—'•²vWfVçEöFFRuÒòFVçV—'•²vWfVçEöFFRuÒ¢õò‚wFò&R6öæf—&ÖVBrÂvF÷Vv†&÷72r’À ”F÷Vv„&÷75õ6WGF–æw3£¦f÷&ÖE÷&–6R‚FVçV—'•²vFW÷6—EöÖ÷VçBuÒ ’“°  ––b‚—5öVÖ–Â‚FVçV—'•²v7W7FöÖW%öVÖ–ÂuÒ’bbfÇ6RÓÓÒwöÖ–Â‚FVçV—'•²v7W7FöÖW%öVÖ–ÂuÒÂG7V&¦V7BÂF&öG’’’° –W'&÷%öÆör‚tF÷Vv„&÷72Ö–Ã¢6FW&–ærVçV—'’VÖ–ÂFò7W7FöÖW"f–ÆVBf÷"râFVçV—'•²vVçV—'•öçVÖ&W"uÒ“²òò‡73¦–væ÷&Rv÷&E&W72å…äFWfVÆ÷ÖVçDgVæ7F–öç2æW'&÷%öÆöuöW'&÷%öÆöp —Ð  ’F6FW&–æuöVÖ–ÂÒF÷Vv„&÷75õ6WGF–æw3£¦6FW&–æuöVÖ–Â‚“° ––b‚—5öVÖ–Â‚F6FW&–æuöVÖ–Â’bbfÇ6RÓÓÒwöÖ–Â‚F6FW&–æuöVÖ–ÂÂG7V&¦V7BÂF&öG’’’° –W'&÷%öÆör‚tF÷Vv„&÷72Ö–Ã¢6FW&–ærVçV—'’VÖ–ÂFò6†÷f–ÆVBf÷"râFVçV—'•²vVçV—'•öçVÖ&W"uÒ“²òò‡73¦–væ÷&Rv÷&E&W72å…äFWfVÆ÷ÖVçDgVæ7F–öç2æW'&÷%öÆöuöW'&÷%öÆöp —Ð —Ð  ’ò¢  ’¢6VæBF†RFVÆ–&W&FVÇ’æöâÖ6öæf—&Ö–ærgFW"Ö†÷W'2&WVW7BVÖ–Âà ’  ’¢F†—2†26W&FRv÷&F–ærg&öÒ6VæEö6öæf—&ÖF–öâ‚“¢&WVW7B×W7BæWfW  ’¢6÷VæBÆ–¶R–BÂF–ÖVB÷"¶—F6†VâÖ66WFVB÷&FW"à ’  ’¢&Òö&¦V7BF÷&FW"&WVW7B&÷rà ’¢&WGW&âfö–@ ’¢ð —&—fFRgVæ7F–öâ6VæE÷&V÷&FW%÷&WVW7Eöæ÷F–f–6F–öâ‚F÷&FW"’° ’F&ÆörÒw÷7V6–Æ6†'5öFV6öFR‚vWEö÷F–öâ‚v&ÆövæÖRr’ÂTåEõTõDU2“° ’G7V&¦V7BÒ7&–çFb‚õò‚u²SG5Ò&WfW6'’&RÖ÷&FW"&WVW7B&V6V—fVB(	BVæF–ær6öæf—&ÖF–öârÂvF÷Vv†&÷72r’ÂF&Æör“° ’F&öG’Ò7&–çFb€ ’ò¢G&ç6ÆF÷'3¢¢7W7FöÖW"æÖRÂ#¢÷&FW"çVÖ&W"Â3¢f÷&ÖGFVBÖ÷VçBâ¢ð •õò‚$†’SÂG2ÅÆåÆåvR&V6V—fVB–÷W"&WfW6'’&RÖ÷&FW"&WVW7B‚S%ÂG2’f÷"S5ÂG2âF†—2—2æ÷B6öæf—&ÖVB÷&FW"æBæò–ÖVçB†2&VVâF¶VâåÆåÆå&WfW6'’v–ÆÂ&Wf–Wr—Bf—'7BF†–ær–âF†RÖ÷&æ–æræB6ÆÂ–÷RFòw&VR–6·WF–Ö–ær&Vf÷&R6öæf—&Ö–ærf–Æ&–Æ—G’âÆV6RFòæ÷BG&fVÂFòF†R6†÷VçF–Â—B—26öæf—&ÖVBåÆåÆåF†æ²–÷RÅÆäF÷Vv‚&÷72"ÂvF÷Vv†&÷72r’À ’F÷&FW"Óæ7W7FöÖW%öæÖRÀ ’F÷&FW"Óæ÷&FW%öçVÖ&W"À ”F÷Vv„&÷75õ6WGF–æw3£¦f÷&ÖE÷&–6R‚F÷&FW"ÓçF÷FÂ ’“°  ––b‚—5öVÖ–Â‚F÷&FW"Óæ7W7FöÖW%öVÖ–Â’bbfÇ6RÓÓÒwöÖ–Â‚F÷&FW"Óæ7W7FöÖW%öVÖ–ÂÂG7V&¦V7BÂF&öG’’’° –W'&÷%öÆör‚tF÷Vv„&÷72Ö–Ã¢&V÷&FW"&WVW7BVÖ–ÂFò7W7FöÖW"f–ÆVBf÷"2râF÷&FW"Óæ÷&FW%öçVÖ&W"“²òò‡73¦–væ÷&Rv÷&E&W72å…äFWfVÆ÷ÖVçDgVæ7F–öç2æW'&÷%öÆöuöW'&÷%öÆöp —Ð  ’F÷&FW'5öVÖ–ÂÒF÷Vv„&÷75õ6WGF–æw3£¦÷&FW'5öVÖ–Â‚“° ––b‚—5öVÖ–Â‚F÷&FW'5öVÖ–Â’bbfÇ6RÓÓÒwöÖ–Â‚F÷&FW'5öVÖ–ÂÂG7V&¦V7BÂF&öG’’’° –W'&÷%öÆör‚tF÷Vv„&÷72Ö–Ã¢&V÷&FW"&WVW7BVÖ–ÂFò6†÷f–ÆVBf÷"2râF÷&FW"Óæ÷&FW%öçVÖ&W"“²òò‡73¦–væ÷&Rv÷&E&W72å…äFWfVÆ÷ÖVçDgVæ7F–öç2æW'&÷%öÆöuöW'&÷%öÆöp —Ð —Ð  ’ò¢  ’¢6VæBÆ–â6öæf—&ÖF–öâVÖ–ÂFòF†R7W7FöÖW"æB6÷’FòF†RFÖ–âà ’  ’¢&Òö&¦V7BF÷&FW"÷&FW"&÷rà ’¢&WGW&âfö–@ ’¢ð —&—fFRgVæ7F–öâ6VæEö6öæf—&ÖF–öâ‚F÷&FW"’° ’F&ÆörÒw÷7V6–Æ6†'5öFV6öFR‚vWEö÷F–öâ‚v&ÆövæÖRr’ÂTåEõTõDU2“°  ’FÆ–æW2Ò'&’‚“° –f÷&V6‚‚F÷Vv„&÷75ô÷&FW#£¦vWEö—FV×2‚F÷&FW"Óæ–B’2F—FVÒ’° ’FÆ–æW5µÒÒ7&–çFb‚rVB‚W2(	BW2rÂF—FVÕ²wVçF—G’uÒÂF—FVÕ²væÖRuÒÂF÷Vv„&÷75õ6WGF–æw3£¦f÷&ÖE÷&–6R‚F—FVÕ²vÆ–æU÷F÷FÂuÒ’“° —Ð ––b‚—76WB‚F÷&FW"ÓæF—66÷VçB’bb†fÆöB’F÷&FW"ÓæF—66÷VçBâ’° ’FÆ–æW5µÒÒ7&–çFb€ ’ò¢G&ç6ÆF÷'3¢¢f÷V6†W"6öFR†Ö’&R&Ææ²’Â#¢F—66÷VçBÖ÷VçBâ¢ð •õò‚uf÷V6†W"SG3¢ÒS"G2rÂvF÷Vv†&÷72r’À ’rrÓÒ‡7G&–ær’F÷&FW"Óçf÷V6†W%ö6öFRòF÷&FW"Óçf÷V6†W%ö6öFR¢rrÀ ”F÷Vv„&÷75õ6WGF–æw3£¦f÷&ÖE÷&–6R‚†fÆöB’F÷&FW"ÓæF—66÷VçB ’“° —Ð  ’òò7V&¦V7Bö&öG’6öÖRg&öÒF†R÷væW"ÖVF—F&ÆRFV×ÆFW2„F÷Vv„&÷72(i  ’òòÖW76vRFV×ÆFW2’ÂfÆÆ–ær&6²FòF†R'V–ÇBÖ–âFVfVÇB6÷’à ’Gf'2Ò'&’€ ’w6—FUöæÖRrÓâF&ÆörÀ ’v÷&FW%öçVÖ&W"rÓâF÷&FW"Óæ÷&FW%öçVÖ&W"À ’v7W7FöÖW%öæÖRrÓâF÷&FW"Óæ7W7FöÖW%öæÖRÀ ’v—FV×2rÓâ–×ÆöFR‚%Æâ"ÂFÆ–æW2’À ’wF÷FÂrÓâF÷Vv„&÷75õ6WGF–æw3£¦f÷&ÖE÷&–6R‚F÷&FW"ÓçF÷FÂ’À ’wG&6¶–æu÷W&ÂrÓâF÷Vv„&÷75õ6WGF–æw3£§G&6¶–æu÷vU÷W&Â‚F÷&FW"Óæ÷&FW%öçVÖ&W"’À ’wG&6¶–æuö–ç7G'V7F–öç2rÓâF÷Vv„&÷75õ6WGF–æw3£§G&6¶–æuö–ç7G'V7F–öç2‚F÷&FW"Óæ÷&FW%öçVÖ&W"’À ’“° ’G7V&¦V7BÒF÷Vv„&÷75õ6WGF–æw3£§&VæFW%÷FV×ÆFR‚F÷Vv„&÷75õ6WGF–æw3£§GÅö÷&FW%öVÖ–Å÷7V&¦V7B‚’ÂGf'2“° ’F&öG’ÒF÷Vv„&÷75õ6WGF–æw3£§&VæFW%÷FV×ÆFR‚F÷Vv„&÷75õ6WGF–æw3£§GÅö÷&FW%öVÖ–Åö&öG’‚’ÂGf'2“°  ––b‚—5öVÖ–Â‚F÷&FW"Óæ7W7FöÖW%öVÖ–Â’bbfÇ6RÓÓÒwöÖ–Â‚F÷&FW"Óæ7W7FöÖW%öVÖ–ÂÂG7V&¦V7BÂF&öG’’’° –W'&÷%öÆör‚tF÷Vv„&÷72Ö–Ã¢÷&FW"6öæf—&ÖF–öâVÖ–ÂFò7W7FöÖW"f–ÆVBf÷"2râF÷&FW"Óæ÷&FW%öçVÖ&W"“²òò‡73¦–væ÷&Rv÷&E&W72å…äFWfVÆ÷ÖVçDgVæ7F–öç2æW'&÷%öÆöuöW'&÷%öÆöp —Ð  ’F÷&FW'5öVÖ–ÂÒF÷Vv„&÷75õ6WGF–æw3£¦÷&FW'5öVÖ–Â‚“° ––b‚—5öVÖ–Â‚F÷&FW'5öVÖ–Â’bbfÇ6RÓÓÒwöÖ–Â‚F÷&FW'5öVÖ–ÂÂG7V&¦V7BÂF&öG’’’° –W'&÷%öÆör‚tF÷Vv„&÷72Ö–Ã¢÷&FW"6öæf—&ÖF–öâVÖ–ÂFò6†÷f–ÆVBf÷"2râF÷&FW"Óæ÷&FW%öçVÖ&W"“²òò‡73¦–væ÷&Rv÷&E&W72å…äFWfVÆ÷ÖVçDgVæ7F–öç2æW'&÷%öÆöuöW'&÷%öÆöp —Ð —Ð§Ð