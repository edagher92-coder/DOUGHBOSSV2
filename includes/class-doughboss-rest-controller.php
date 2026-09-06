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

	const MAX_TOPPINGS = 12;

	/**
	 * Cart instance.
	 *
	 * @var DoughBoss_Cart
	 */
	private $cart;

	/**
	 * Orders whose confirmation emails are queued for after the response.
	 *
	 * @var int[]
	 */
	private $pending_emails = array();

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
		add_action( 'doughboss_send_order_emails', array( $this, 'send_confirmation_for' ) );
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
			'/nonce',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_nonce' ),
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
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'item_id'  => array(
						'default'           => 0,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'size'     => array(
						'default'           => '',
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'toppings' => array(
						'default' => array(),
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
					),
					'quantity' => array(
						'default'           => 1,
						'type'              => 'integer',
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
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'quantity' => array(
						'required'          => true,
						'type'              => 'integer',
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
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
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

		$text_arg = array(
			'default'           => '',
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);
		$area_arg = array(
			'default'           => '',
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
		);

		register_rest_route(
			$ns,
			'/checkout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'checkout' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
				'args'                => array(
					'order_type'      => array(
						'default'           => 'pickup',
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'customer_name'   => $text_arg,
					'customer_email'  => array(
						'default'           => '',
						'type'              => 'string',
						'sanitize_callback' => array( __CLASS__, 'sanitize_email_arg' ),
					),
					'customer_phone'  => $text_arg,
					'address'         => $area_arg,
					'notes'           => $area_arg,
					'idempotency_key' => array(
						'default'           => '',
						'type'              => 'string',
						'sanitize_callback' => array( __CLASS__, 'sanitize_idempotency_key' ),
					),
				),
			)
		);

		// Order tracking: POST keeps the email out of query strings and logs.
		register_rest_route(
			$ns,
			'/order/track',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'track_order' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'number' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => array( __CLASS__, 'sanitize_email_arg' ),
					),
				),
			)
		);

		// Back-compat GET tracking route (used by the 2.0 storefront).
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
						'type'              => 'string',
						'sanitize_callback' => array( __CLASS__, 'sanitize_email_arg' ),
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
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/admin/orders/new-count',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'admin_new_count' ),
				'permission_callback' => array( $this, 'verify_admin' ),
				'args'                => array(
					'since' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Sanitisers & permissions                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Array-safe email sanitiser. Core's sanitize_email() calls strlen() on its
	 * argument, so `?email[]=x` raised a TypeError (HTTP 500) on PHP 8.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_email_arg( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return strtolower( sanitize_email( $value ) );
	}

	/**
	 * Idempotency keys are opaque client tokens; keep them short and plain.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_idempotency_key( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $value ), 0, 64 );
	}

	/**
	 * Permission check: valid REST nonce required for state-changing calls.
	 *
	 * Note: for a logged-out visitor this is CSRF protection only, not
	 * authentication (the anonymous nonce is the same for every visitor).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function verify_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}
		return new WP_Error( 'doughboss_bad_nonce', __( 'Session expired. Please try again.', 'doughboss' ), array( 'status' => 403 ) );
	}

	/**
	 * Permission check: require the management capability.
	 *
	 * @return bool|WP_Error
	 */
	public function verify_admin() {
		if ( current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'doughboss_forbidden', __( 'You are not allowed to do that.', 'doughboss' ), array( 'status' => 403 ) );
	}

	/* ------------------------------------------------------------------ */
	/* Rate limiting                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Best-effort client IP (filterable for sites behind a proxy that sets a
	 * trusted header — do NOT trust X-Forwarded-For by default).
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		/**
		 * Filter the client IP used for rate limiting.
		 *
		 * @param string $ip Detected IP.
		 */
		return (string) apply_filters( 'doughboss_client_ip', $ip );
	}

	/**
	 * Fixed-window rate limiter backed by transients. Returns a WP_Error when
	 * the window is exhausted.
	 *
	 * @param string $bucket    Stable bucket name (never derived from user input).
	 * @param string $subject   Who is being limited (IP, email, …).
	 * @param int    $limit     Max hits per window.
	 * @param int    $window    Window in seconds.
	 * @return true|WP_Error
	 */
	private function rate_limit( $bucket, $subject, $limit, $window ) {
		$key   = 'doughboss_rl_' . $bucket . '_' . md5( (string) $subject );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return new WP_Error(
				'doughboss_rate_limited',
				__( 'Too many attempts. Please wait a minute and try again.', 'doughboss' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Public read endpoints                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * GET /config — storefront configuration for the JS app.
	 *
	 * @return WP_REST_Response
	 */
	public function get_config() {
		$response = rest_ensure_response( DoughBoss_Settings::public_config() );
		$response->header( 'Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=600' );
		return $response;
	}

	/**
	 * GET /nonce — a fresh REST nonce, never cached. The storefront calls this
	 * when a page-embedded nonce has gone stale behind a full-page cache.
	 *
	 * @return WP_REST_Response
	 */
	public function get_nonce() {
		$response = rest_ensure_response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * GET /menu — published menu items grouped by category (cached).
	 *
	 * @return WP_REST_Response
	 */
	public function get_menu() {
		$response = rest_ensure_response( self::menu_items() );
		$response->header( 'Cache-Control', 'public, max-age=60, s-maxage=600, stale-while-revalidate=3600' );
		return $response;
	}

	/**
	 * The menu payload, cached in a transient keyed by the menu version (which
	 * DoughBoss_Post_Types bumps whenever an item or category changes).
	 *
	 * @return array[]
	 */
	public static function menu_items() {
		$key   = 'doughboss_menu_v' . DoughBoss_Post_Types::menu_version();
		$items = get_transient( $key );
		if ( is_array( $items ) ) {
			return $items;
		}

		$items = self::build_menu_items();
		set_transient( $key, $items, 12 * HOUR_IN_SECONDS );
		return $items;
	}

	/**
	 * Build the menu payload from the database.
	 *
	 * @return array[]
	 */
	private static function build_menu_items() {
		$posts = get_posts(
			array(
				'post_type'      => DoughBoss_Post_Types::POST_TYPE,
				'post_status'    => 'publish',
				'numberposts'    => 500,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);

		// Prime the attachment posts + meta in one go; without this every
		// thumbnail cost two queries (403 queries for a 200-item menu).
		$thumb_ids = array();
		foreach ( $posts as $post ) {
			$tid = (int) get_post_thumbnail_id( $post );
			if ( $tid ) {
				$thumb_ids[] = $tid;
			}
		}
		if ( $thumb_ids ) {
			_prime_post_caches( array_unique( $thumb_ids ), false, true );
		}

		$items = array();
		foreach ( $posts as $post ) {
			$terms    = get_the_terms( $post->ID, DoughBoss_Post_Types::TAXONOMY );
			$category = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : __( 'Menu', 'doughboss' );
			$tid      = (int) get_post_thumbnail_id( $post );
			$image    = '';
			$srcset   = '';
			$width    = 0;
			$height   = 0;
			if ( $tid ) {
				$src = wp_get_attachment_image_src( $tid, 'medium_large' );
				if ( $src ) {
					$image  = $src[0];
					$width  = (int) $src[1];
					$height = (int) $src[2];
					$set    = wp_get_attachment_image_srcset( $tid, 'medium_large' );
					$srcset = $set ? $set : '';
				}
			}

			$raw_desc = $post->post_excerpt ? $post->post_excerpt : $post->post_content;

			$items[] = array(
				'id'           => $post->ID,
				'name'         => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'description'  => wp_trim_words( wp_strip_all_tags( strip_shortcodes( $raw_desc ) ), 25, '…' ),
				'price'        => (float) get_post_meta( $post->ID, DoughBoss_Post_Types::META_PRICE, true ),
				'type'         => get_post_meta( $post->ID, DoughBoss_Post_Types::META_TYPE, true ),
				'image'        => $image,
				'srcset'       => $srcset,
				'image_width'  => $width,
				'image_height' => $height,
				'category'     => $category,
				'available'    => DoughBoss_Post_Types::is_available( $post->ID ),
			);
		}

		return $items;
	}

	/**
	 * GET /cart — current cart contents. Per-visitor: never cacheable.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_cart( WP_REST_Request $request ) {
		$order_type = $this->normalise_order_type( $request->get_param( 'order_type' ) );
		return $this->cart_response( $this->cart->to_array( $order_type ) );
	}

	/**
	 * Wrap a cart payload with the headers that stop an edge cache ever
	 * serving one customer's cart to another.
	 *
	 * @param array $payload Cart payload.
	 * @param int   $status  HTTP status.
	 * @return WP_REST_Response
	 */
	private function cart_response( array $payload, $status = 200 ) {
		$response = rest_ensure_response( $payload );
		$response->set_status( $status );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * Clamp an order type to the two supported values.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function normalise_order_type( $value ) {
		return ( 'delivery' === sanitize_key( (string) $value ) ) ? 'delivery' : 'pickup';
	}

	/* ------------------------------------------------------------------ */
	/* Cart mutations                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * POST /cart/add — add a menu item or custom pizza to the cart.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_to_cart( WP_REST_Request $request ) {
		$limited = $this->rate_limit( 'cart_add', $this->client_ip(), 120, MINUTE_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$type     = $request->get_param( 'type' );
		$quantity = max( 1, (int) $request->get_param( 'quantity' ) );

		if ( 'custom' === $type ) {
			$line = $this->build_custom_line( $request->get_param( 'size' ), (array) $request->get_param( 'toppings' ) );
		} else {
			$line = $this->build_menu_line( absint( $request->get_param( 'item_id' ) ) );
		}

		if ( is_wp_error( $line ) ) {
			return $line;
		}

		$line['quantity'] = $quantity;
		$result           = $this->cart->add( $line );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payload          = $this->cart->to_array();
		$payload['added'] = $result;
		return $this->cart_response( $payload );
	}

	/**
	 * Build a cart line from a published, available menu item.
	 *
	 * @param int $item_id Menu item post ID.
	 * @return array|WP_Error
	 */
	private function build_menu_line( $item_id ) {
		$post = get_post( $item_id );

		if ( ! $post || DoughBoss_Post_Types::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error( 'doughboss_no_item', __( 'That item is not available.', 'doughboss' ), array( 'status' => 404 ) );
		}
		if ( ! DoughBoss_Post_Types::is_available( $item_id ) ) {
			return new WP_Error( 'doughboss_sold_out', __( 'Sorry, that item is sold out today.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$price = (float) get_post_meta( $item_id, DoughBoss_Post_Types::META_PRICE, true );

		return array(
			'type'       => 'menu',
			'item_id'    => $item_id,
			'name'       => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'size'       => '',
			'size_slug'  => '',
			'toppings'   => array(),
			'unit_price' => round( $price, 2 ),
		);
	}

	/**
	 * Build a cart line for a custom-built pizza, pricing it server-side.
	 * Duplicate topping slugs are collapsed and the list is capped, so a
	 * client cannot send ["pepperoni"] x 40.
	 *
	 * @param string $size_slug     Size slug.
	 * @param array  $topping_slugs Requested topping slugs.
	 * @return array|WP_Error
	 */
	private function build_custom_line( $size_slug, array $topping_slugs ) {
		$size_slug = sanitize_key( (string) $size_slug );
		$size      = DoughBoss_Settings::find_size( $size_slug );

		if ( ! $size ) {
			return new WP_Error( 'doughboss_no_size', __( 'Please choose a valid size.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$price             = (float) $size['price'];
		$selected_toppings = array();
		$slugs             = array();
		foreach ( $topping_slugs as $slug ) {
			if ( is_string( $slug ) ) {
				$slugs[] = sanitize_key( $slug );
			}
		}
		$slugs = array_slice( array_values( array_unique( array_filter( $slugs ) ) ), 0, self::MAX_TOPPINGS );

		foreach ( $slugs as $slug ) {
			$topping = DoughBoss_Settings::find_topping( $slug );
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
			'size_slug'  => $size['slug'],
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
		return $this->cart_response( $this->cart->to_array( $this->normalise_order_type( $request->get_param( 'order_type' ) ) ) );
	}

	/**
	 * POST /cart/remove — remove a line.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_from_cart( WP_REST_Request $request ) {
		$this->cart->remove( $request->get_param( 'key' ) );
		return $this->cart_response( $this->cart->to_array( $this->normalise_order_type( $request->get_param( 'order_type' ) ) ) );
	}

	/**
	 * POST /cart/clear — empty the cart.
	 *
	 * @return WP_REST_Response
	 */
	public function clear_cart() {
		$this->cart->clear();
		return $this->cart_response( $this->cart->to_array() );
	}

	/* ------------------------------------------------------------------ */
	/* Checkout                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Re-price and re-validate every stored line against the live menu and
	 * settings. A cart line can be up to 24 hours old; prices may have moved
	 * and items may have sold out or been unpublished in that time.
	 *
	 * @return array{changed:bool,lines:array}
	 */
	private function revalidate_cart() {
		$changed = false;
		$fresh   = array();

		foreach ( $this->cart->raw_lines() as $key => $line ) {
			if ( 'custom' === $line['type'] ) {
				$size_slug = ! empty( $line['size_slug'] ) ? $line['size_slug'] : '';
				if ( '' === $size_slug ) {
					// Pre-2.5 line: match the size by its stored label.
					foreach ( DoughBoss_Settings::sizes() as $size ) {
						if ( isset( $size['label'] ) && $size['label'] === $line['size'] ) {
							$size_slug = $size['slug'];
							break;
						}
					}
				}
				$slugs   = isset( $line['toppings'] ) ? wp_list_pluck( $line['toppings'], 'slug' ) : array();
				$rebuilt = $this->build_custom_line( $size_slug, $slugs );
			} else {
				$rebuilt = $this->build_menu_line( (int) $line['item_id'] );
			}

			if ( is_wp_error( $rebuilt ) ) {
				$changed = true; // Item gone or sold out: drop it.
				continue;
			}

			if ( abs( (float) $rebuilt['unit_price'] - (float) $line['unit_price'] ) >= 0.005 ) {
				$changed = true;
			}

			$line['unit_price'] = $rebuilt['unit_price'];
			$line['name']       = $rebuilt['name'];
			$line['size']       = $rebuilt['size'];
			$line['size_slug']  = $rebuilt['size_slug'];
			$line['toppings']   = $rebuilt['toppings'];
			$line['available']  = true;
			$fresh[ $key ]      = $line;
		}

		if ( $changed ) {
			$this->cart->replace_lines( $fresh );
		}

		return array(
			'changed' => $changed,
			'lines'   => $fresh,
		);
	}

	/**
	 * POST /checkout — validate, create the order, clear the cart.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function checkout( WP_REST_Request $request ) {
		$ip      = $this->client_ip();
		$limited = $this->rate_limit( 'checkout_ip', $ip, 6, 10 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		if ( ! DoughBoss_Settings::ordering_open() ) {
			return new WP_Error( 'doughboss_closed', __( 'Online ordering is currently closed.', 'doughboss' ), array( 'status' => 503 ) );
		}

		if ( ! $this->cart->has_token() || $this->cart->is_empty() ) {
			return new WP_Error( 'doughboss_cart_expired', __( 'Your cart is empty or has expired. Please add your items again.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$order_type = $this->normalise_order_type( $request->get_param( 'order_type' ) );

		if ( 'delivery' === $order_type && ! DoughBoss_Settings::get( 'enable_delivery', 0 ) ) {
			return new WP_Error( 'doughboss_no_delivery', __( 'Delivery is not available.', 'doughboss' ), array( 'status' => 400 ) );
		}
		if ( 'pickup' === $order_type && ! DoughBoss_Settings::get( 'enable_pickup', 1 ) ) {
			return new WP_Error( 'doughboss_no_pickup', __( 'Pickup is not available.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$name  = (string) $request->get_param( 'customer_name' );
		$email = (string) $request->get_param( 'customer_email' );
		$phone = (string) $request->get_param( 'customer_phone' );
		$notes = (string) $request->get_param( 'notes' );
		$addr  = (string) $request->get_param( 'address' );
		$idem  = (string) $request->get_param( 'idempotency_key' );

		// Per-field errors so the storefront can attach each one to its input.
		$errors = array();
		if ( '' === $name ) {
			$errors['customer_name'] = __( 'Please enter your name.', 'doughboss' );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			$errors['customer_email'] = __( 'Please enter a valid email address.', 'doughboss' );
		}
		if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 6 ) {
			$errors['customer_phone'] = __( 'Please enter a phone number we can reach you on.', 'doughboss' );
		}
		if ( 'delivery' === $order_type && strlen( trim( $addr ) ) < 8 ) {
			$errors['address'] = __( 'Please enter the full delivery address.', 'doughboss' );
		}
		if ( $errors ) {
			return new WP_Error(
				'doughboss_invalid',
				__( 'Please check the highlighted fields.', 'doughboss' ),
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}

		$limited = $this->rate_limit( 'checkout_email', $email, 6, 10 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		// Prices may have changed since the item entered the cart.
		$revalidated = $this->revalidate_cart();
		if ( $revalidated['changed'] ) {
			return new WP_Error(
				'doughboss_prices_changed',
				__( 'Some items or prices have changed. Please review your order.', 'doughboss' ),
				array(
					'status' => 409,
					'cart'   => $this->cart->to_array( $order_type ),
				)
			);
		}
		if ( $this->cart->is_empty() ) {
			return new WP_Error( 'doughboss_cart_expired', __( 'Your cart is empty. Please add your items again.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$totals = $this->cart->totals( $order_type );
		if ( ! $totals['min_order_met'] ) {
			return new WP_Error(
				'doughboss_min_order',
				sprintf(
					/* translators: %s: formatted minimum order amount. */
					__( 'The minimum order is %s.', 'doughboss' ),
					DoughBoss_Settings::format_price( $totals['min_order'] )
				),
				array( 'status' => 400 )
			);
		}

		// One checkout per cart at a time. add_option() is atomic (unique key on
		// option_name), so two concurrent submits cannot both pass.
		$lock_key = 'doughboss_lock_' . $this->cart->get_token();
		if ( ! add_option( $lock_key, time(), '', 'no' ) ) {
			$held = (int) get_option( $lock_key );
			if ( $held && ( time() - $held ) < 30 ) {
				return new WP_Error( 'doughboss_in_progress', __( 'Your order is already being placed. Please wait a moment.', 'doughboss' ), array( 'status' => 409 ) );
			}
			delete_option( $lock_key ); // Stale lock from a crashed request.
			add_option( $lock_key, time(), '', 'no' );
		}

		try {
			$lines    = $this->cart->get_lines();
			$order_id = DoughBoss_Order::create(
				array(
					'order_type'      => $order_type,
					'customer_name'   => $name,
					'customer_email'  => $email,
					'customer_phone'  => $phone,
					'address'         => 'delivery' === $order_type ? $addr : '',
					'notes'           => $notes,
					'subtotal'        => $totals['subtotal'],
					'tax'             => $totals['tax'],
					'tax_rate'        => $totals['tax_rate'],
					'tax_inclusive'   => $totals['tax_inclusive'],
					'delivery_fee'    => $totals['delivery_fee'],
					'total'           => $totals['total'],
					'idempotency_key' => $idem,
				),
				$lines
			);
		} finally {
			delete_option( $lock_key );
		}

		if ( is_wp_error( $order_id ) ) {
			return $order_id;
		}

		$order = DoughBoss_Order::get( $order_id );
		$this->cart->clear();
		$this->queue_confirmation( $order_id );

		return $this->cart_response(
			array(
				'success'      => true,
				'order_number' => $order->order_number,
				'total'        => (float) $order->total,
				'message'      => __( 'Thanks! Your order has been received.', 'doughboss' ),
				'email_sent'   => true, // Queued; the storefront copy says "we've emailed you".
				'tracking_url' => self::tracking_url( $order ),
			),
			201
		);
	}

	/**
	 * Build a deep link to the tracking page for an order, if one is configured.
	 *
	 * @param object $order Order row.
	 * @return string
	 */
	public static function tracking_url( $order ) {
		$base = (string) DoughBoss_Settings::get( 'tracking_page_url', '' );
		if ( '' === $base ) {
			return '';
		}
		return add_query_arg(
			array(
				'number' => rawurlencode( $order->order_number ),
				'email'  => rawurlencode( $order->customer_email ),
			),
			$base
		);
	}

	/* ------------------------------------------------------------------ */
	/* Order tracking                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * GET /order/{number}?email= and POST /order/track — customer tracking.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function track_order( WP_REST_Request $request ) {
		$limited = $this->rate_limit( 'track', $this->client_ip(), 20, 10 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$number = strtoupper( sanitize_text_field( (string) $request->get_param( 'number' ) ) );
		$email  = (string) $request->get_param( 'email' );
		$order  = DoughBoss_Order::get_by_number( $number );

		// Same error for "not found" and "email mismatch" to avoid leaking which orders exist.
		if ( ! $order || ! hash_equals( strtolower( (string) $order->customer_email ), strtolower( $email ) ) ) {
			return new WP_Error( 'doughboss_not_found', __( 'No matching order found. Check your order number and email.', 'doughboss' ), array( 'status' => 404 ) );
		}

		return $this->cart_response( DoughBoss_Order::public_view( $order ) );
	}

	/* ------------------------------------------------------------------ */
	/* Admin                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * POST /admin/order/{id}/status — staff status update.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_update_status( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'id' ) );
		$status   = sanitize_key( $request->get_param( 'status' ) );

		if ( ! DoughBoss_Order::get( $order_id ) ) {
			return new WP_Error( 'doughboss_no_order', __( 'That order does not exist.', 'doughboss' ), array( 'status' => 404 ) );
		}
		if ( ! DoughBoss_Order::update_status( $order_id, $status ) ) {
			return new WP_Error( 'doughboss_status', __( 'Could not update that order.', 'doughboss' ), array( 'status' => 400 ) );
		}

		return $this->cart_response(
			array(
				'success' => true,
				'status'  => $status,
			)
		);
	}

	/**
	 * GET /admin/orders/new-count?since= — how many orders arrived after a
	 * UTC timestamp. Polled by the Orders screen for the new-order alert.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function admin_new_count( WP_REST_Request $request ) {
		$since = (string) $request->get_param( 'since' );
		$ts    = strtotime( $since . ' UTC' );
		if ( ! $ts ) {
			$ts = time();
		}
		$since_gmt = gmdate( 'Y-m-d H:i:s', $ts );
		return $this->cart_response(
			array(
				'count' => DoughBoss_Order::count_since( $since_gmt ),
				'now'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Email                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Queue the confirmation emails to run AFTER the HTTP response has been
	 * sent. Two synchronous wp_mail() calls used to sit inside the checkout
	 * request — on an SMTP host that was 0.3–1.2 s of the customer staring at
	 * a spinner, and the Friday-night ceiling on concurrent checkouts.
	 *
	 * On PHP-FPM the response is flushed with fastcgi_finish_request(); on other
	 * SAPIs the mail still goes out on shutdown, after output. A WP-Cron event is
	 * scheduled as a belt-and-braces fallback in case shutdown never runs.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	private function queue_confirmation( $order_id ) {
		$this->pending_emails[] = (int) $order_id;

		if ( ! has_action( 'shutdown', array( $this, 'flush_pending_emails' ) ) ) {
			add_action( 'shutdown', array( $this, 'flush_pending_emails' ), 1 );
		}

		if ( ! wp_next_scheduled( 'doughboss_send_order_emails', array( (int) $order_id ) ) ) {
			wp_schedule_single_event( time() + 120, 'doughboss_send_order_emails', array( (int) $order_id ) );
		}
	}

	/**
	 * Shutdown handler: release the client, then send.
	 *
	 * @return void
	 */
	public function flush_pending_emails() {
		if ( empty( $this->pending_emails ) ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		foreach ( $this->pending_emails as $order_id ) {
			$this->send_confirmation_for( $order_id );
		}
		$this->pending_emails = array();
	}

	/**
	 * Send the confirmation emails for an order, once. Safe to call from the
	 * shutdown handler and from the cron fallback: the second caller finds
	 * email_sent already set and returns.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function send_confirmation_for( $order_id ) {
		$order = DoughBoss_Order::get( (int) $order_id );
		if ( ! $order || (int) $order->email_sent ) {
			return;
		}

		$last_error = '';
		$capture    = function ( $wp_error ) use ( &$last_error ) {
			$last_error = $wp_error instanceof WP_Error ? $wp_error->get_error_message() : 'wp_mail failed';
		};
		add_action( 'wp_mail_failed', $capture );

		$sent = $this->send_confirmation( $order );

		remove_action( 'wp_mail_failed', $capture );
		DoughBoss_Order::mark_email_result( (int) $order_id, $sent, $last_error );

		$hook_args = array( (int) $order_id );
		$scheduled = wp_next_scheduled( 'doughboss_send_order_emails', $hook_args );
		if ( $scheduled ) {
			wp_unschedule_event( $scheduled, 'doughboss_send_order_emails', $hook_args );
		}
	}

	/**
	 * Compose and send the customer confirmation and the store copy.
	 *
	 * @param object $order Order row.
	 * @return bool Whether the customer email was accepted by wp_mail.
	 */
	private function send_confirmation( $order ) {
		$blog     = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$statuses = DoughBoss_Order::statuses();
		/* translators: 1: site name, 2: order number. */
		$subject = sprintf( __( '[%1$s] Order %2$s received', 'doughboss' ), $blog, $order->order_number );

		$lines = array();
		foreach ( DoughBoss_Order::get_items( $order->id ) as $item ) {
			$detail = array();
			if ( ! empty( $item['size'] ) ) {
				$detail[] = $item['size'];
			}
			if ( ! empty( $item['toppings'] ) ) {
				$detail[] = implode( ', ', wp_list_pluck( $item['toppings'], 'label' ) );
			}
			$lines[] = sprintf(
				'%d x %s%s — %s',
				(int) $item['quantity'],
				$item['name'],
				$detail ? ' (' . implode( ' · ', $detail ) . ')' : '',
				DoughBoss_Settings::format_price( $item['line_total'] )
			);
		}

		$type_label = 'delivery' === $order->order_type ? __( 'Delivery', 'doughboss' ) : __( 'Pickup', 'doughboss' );
		$totals     = array();
		$totals[]   = sprintf( '%s: %s', __( 'Subtotal', 'doughboss' ), DoughBoss_Settings::format_price( $order->subtotal ) );
		if ( (float) $order->delivery_fee > 0 ) {
			$totals[] = sprintf( '%s: %s', __( 'Delivery', 'doughboss' ), DoughBoss_Settings::format_price( $order->delivery_fee ) );
		}
		if ( (float) $order->tax > 0 ) {
			$totals[] = (int) $order->tax_inclusive
				/* translators: 1: tax label, 2: amount. */
				? sprintf( __( 'Includes %1$s: %2$s', 'doughboss' ), DoughBoss_Settings::tax_label(), DoughBoss_Settings::format_price( $order->tax ) )
				: sprintf( '%s: %s', DoughBoss_Settings::tax_label(), DoughBoss_Settings::format_price( $order->tax ) );
		}
		/* translators: 1: order type label. */
		$totals[] = sprintf( __( 'Total due on %1$s: ', 'doughboss' ), strtolower( $type_label ) ) . DoughBoss_Settings::format_price( $order->total );

		$body  = sprintf( __( 'Hi there,', 'doughboss' ) ) . "\n\n";
		/* translators: %s: order number. */
		$body .= sprintf( __( 'Thanks for your order %s. Here is what we got:', 'doughboss' ), $order->order_number ) . "\n\n";
		$body .= implode( "\n", $lines ) . "\n\n";
		$body .= implode( "\n", $totals ) . "\n\n";
		$body .= sprintf( '%s: %s', __( 'Order type', 'doughboss' ), $type_label ) . "\n";
		if ( 'delivery' === $order->order_type && '' !== trim( (string) $order->address ) ) {
			$body .= sprintf( '%s: %s', __( 'Delivery address', 'doughboss' ), $order->address ) . "\n";
		}
		if ( '' !== trim( (string) $order->notes ) ) {
			$body .= sprintf( '%s: %s', __( 'Notes', 'doughboss' ), $order->notes ) . "\n";
		}
		$body .= sprintf( '%s: %s', __( 'Status', 'doughboss' ), isset( $statuses[ $order->status ] ) ? $statuses[ $order->status ] : $order->status ) . "\n";

		$tracking = self::tracking_url( $order );
		if ( $tracking ) {
			$body .= "\n" . __( 'Track your order:', 'doughboss' ) . ' ' . $tracking . "\n";
		}
		$phone = (string) DoughBoss_Settings::get( 'store_phone', '' );
		if ( '' !== $phone ) {
			/* translators: %s: store phone number. */
			$body .= "\n" . sprintf( __( 'Questions? Call us on %s.', 'doughboss' ), $phone ) . "\n";
		}
		$body .= "\n" . $blog . "\n";

		$customer_ok = false;
		if ( is_email( $order->customer_email ) ) {
			$customer_ok = (bool) wp_mail( $order->customer_email, $subject, $body );
		}

		// The store copy carries everything the kitchen needs, name and phone included.
		$store_body  = sprintf( '%s: %s', __( 'Customer', 'doughboss' ), $order->customer_name ) . "\n";
		$store_body .= sprintf( '%s: %s', __( 'Phone', 'doughboss' ), $order->customer_phone ) . "\n";
		$store_body .= sprintf( '%s: %s', __( 'Email', 'doughboss' ), $order->customer_email ) . "\n\n";
		$store_body .= $body;

		$admin_email = get_option( 'admin_email' );
		if ( is_email( $admin_email ) ) {
			/* translators: 1: order type label, 2: order number. */
			wp_mail( $admin_email, sprintf( __( 'New %1$s order %2$s', 'doughboss' ), strtolower( $type_label ), $order->order_number ), $store_body );
		}

		return $customer_ok;
	}
}
