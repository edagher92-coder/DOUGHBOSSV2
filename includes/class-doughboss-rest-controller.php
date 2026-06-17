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
			'/cart/clear',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear_cart' ),
				'permission_callback' => array( $this, 'verify_nonce' ),
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
			),
			$lines
		);

		if ( is_wp_error( $order_id ) ) {
			return $order_id;
		}

		$order = DoughBoss_Order::get( $order_id );
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
