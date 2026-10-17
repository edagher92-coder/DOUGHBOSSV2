<?php
/**
 * Customer pickup-time scheduling: live ASAP / scheduled pickup options, hold
 * and release routes, and the pure checkout contract used by the payment and
 * order writers.
 *
 * Registers its own REST routes so the main REST controller is untouched. All
 * behaviour is gated per shop by the "live" capacity mode; "off" (the default)
 * leaves the storefront and checkout exactly as before.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pickup option, hold and checkout-selection helpers (static, stateless).
 */
class DoughBoss_Pickup {

	/** Schema version that introduced the live pickup columns. */
	const MIN_DB_VERSION = '1.30.0';

	/** Hold requests allowed per client within RATE_WINDOW seconds. */
	const RATE_LIMIT = 40;

	/** Rate-limit window in seconds. */
	const RATE_WINDOW = 600;

	/** Hard cap on slots returned by one options response. */
	const MAX_SLOTS = 600;

	/**
	 * Server-side cart (set by init()).
	 *
	 * @var DoughBoss_Cart|null
	 */
	private static $cart = null;

	/**
	 * Register hooks.
	 *
	 * @param DoughBoss_Cart $cart Shared cart.
	 * @return void
	 */
	public static function init( DoughBoss_Cart $cart ) {
		self::$cart = $cart;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'doughboss_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 10, 2 );
	}

	/**
	 * Register the pickup routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = defined( 'DOUGHBOSS_REST_NAMESPACE' ) ? DOUGHBOSS_REST_NAMESPACE : 'doughboss/v1';
		register_rest_route(
			$ns,
			'/pickup/options',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_options' ),
				'permission_callback' => array( __CLASS__, 'verify_nonce' ),
				'args'                => array( 'location_id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
		register_rest_route(
			$ns,
			'/pickup/hold',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_hold' ),
				'permission_callback' => array( __CLASS__, 'verify_nonce' ),
				'args'                => array(
					'location_id'     => array( 'sanitize_callback' => 'absint' ),
					'pickup_mode'     => array( 'sanitize_callback' => 'sanitize_key' ),
					'ready_from_utc'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'idempotency_key' => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'release_token'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/pickup/release',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'release' ),
				'permission_callback' => array( __CLASS__, 'verify_nonce' ),
				'args'                => array( 'hold_token' => array( 'sanitize_callback' => 'sanitize_text_field' ) ),
			)
		);
	}

	/**
	 * Same nonce rule as DoughBoss_REST_Controller::verify_nonce().
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function verify_nonce( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}
		return new WP_Error( 'doughboss_bad_nonce', __( 'Session expired. Please refresh the page.', 'doughboss' ), array( 'status' => 403 ) );
	}

	// ---------------------------------------------------------------------
	// Mode helpers
	// ---------------------------------------------------------------------

	/**
	 * Whether the database has the 1.30.0 pickup columns.
	 *
	 * @return bool
	 */
	public static function storage_ready() {
		return version_compare( (string) get_option( 'doughboss_db_version', '0' ), self::MIN_DB_VERSION, '>=' );
	}

	/**
	 * Effective pickup mode for a location row: off | shadow | live.
	 *
	 * Live only counts for an active, pickup-enabled shop on a migrated schema;
	 * anything else behaves exactly like today's checkout ("off").
	 *
	 * @param object|null $loc Location row.
	 * @return string
	 */
	public static function mode_for( $loc ) {
		if ( ! $loc || ! isset( $loc->capacity_mode ) ) {
			return 'off';
		}
		$mode = (string) $loc->capacity_mode;
		if ( 'shadow' === $mode ) {
			return 'shadow';
		}
		if ( 'live' === $mode && ! empty( $loc->is_active ) && ! empty( $loc->pickup_enabled ) && self::storage_ready() ) {
			return 'live';
		}
		return 'off';
	}

	/**
	 * Checkout requirement for a location and order type.
	 *
	 * @param int    $location_id       Location id.
	 * @param string $order_type        pickup | delivery | dine_in.
	 * @param bool   $has_table_context Whether this is a table-QR order.
	 * @return array{mode:string,required:bool,paused:bool}
	 */
	public static function requirement( $location_id, $order_type, $has_table_context ) {
		$loc    = DoughBoss_Locations::get( $location_id );
		$mode   = self::mode_for( $loc );
		$paused = $loc && ! empty( $loc->ordering_paused );
		return array(
			'mode'     => $mode,
			'required' => ( 'live' === $mode && 'pickup' === (string) $order_type && ! $has_table_context ),
			'paused'   => (bool) $paused,
		);
	}

	/**
	 * Validate and normalise the customer's pickup choice for checkout.
	 *
	 * Returns an empty array when nothing is required (mode not live, delivery,
	 * dine-in) so callers add nothing and today's behaviour is unchanged.
	 *
	 * @param DoughBoss_Cart $cart             Cart whose contents the hold must match.
	 * @param int            $location_id      Resolved location.
	 * @param string         $order_type       Order type.
	 * @param array          $input            pickup_mode, capacity_hold_token.
	 * @param bool           $payment_required Whether the order will be paid online.
	 * @return array|WP_Error
	 */
	public static function prepare_checkout_selection( DoughBoss_Cart $cart, $location_id, $order_type, array $input, $payment_required ) {
		$requirement = self::requirement( $location_id, $order_type, false );
		if ( 'live' !== $requirement['mode'] || ! $requirement['required'] ) {
			return array();
		}
		if ( $requirement['paused'] ) {
			return new WP_Error( 'doughboss_location_paused', __( 'This shop is not taking online orders right now.', 'doughboss' ), array( 'status' => 503, 'retry_safe' => true ) );
		}

		$mode  = isset( $input['pickup_mode'] ) ? sanitize_key( $input['pickup_mode'] ) : '';
		$token = isset( $input['capacity_hold_token'] ) ? trim( (string) $input['capacity_hold_token'] ) : '';
		if ( ! in_array( $mode, array( 'asap', 'scheduled' ), true ) || ! preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
			return new WP_Error( 'doughboss_pickup_required', __( 'Please choose when you would like to pick up your order.', 'doughboss' ), array( 'status' => 400, 'retry_safe' => true ) );
		}

		$cart_hash = self::cart_hash_for( $cart, $location_id );
		$peek      = DoughBoss_Capacity::peek_hold( $token, $cart_hash, (int) $location_id );
		if ( 'missing' === $peek['status'] ) {
			return new WP_Error( 'doughboss_pickup_required', __( 'Please choose when you would like to pick up your order.', 'doughboss' ), array( 'status' => 400, 'retry_safe' => true ) );
		}
		if ( 'converted' === $peek['status'] ) {
			return new WP_Error( 'doughboss_hold_used', __( 'That pickup time was already used for another order. Please choose a time again.', 'doughboss' ), array( 'status' => 409, 'retry_safe' => true ) );
		}
		if ( 'held' !== $peek['status'] ) {
			return new WP_Error( 'doughboss_hold_expired', __( 'Your pickup-time hold has expired. Please choose a time again.', 'doughboss' ), array( 'status' => 409, 'retry_safe' => true ) );
		}
		if ( ! $peek['cart_matches'] || ! $peek['location_matches'] ) {
			return new WP_Error( 'doughboss_hold_stale', __( 'Your cart changed after you chose a pickup time. Please choose a time again.', 'doughboss' ), array( 'status' => 409, 'retry_safe' => true ) );
		}

		$label = class_exists( 'DoughBoss_Store_Hours' ) ? DoughBoss_Store_Hours::format_pickup_time( $peek['ready_from_utc'], $peek['timezone'] ) : '';
		return array(
			'pickup_mode'             => $mode,
			'capacity_hold_token'     => $token,
			'pickup_slot_id'          => (int) $peek['slot_id'],
			'pickup_ready_from_utc'   => self::iso_to_mysql( $peek['ready_from_utc'] ),
			'pickup_ready_by_utc'     => self::iso_to_mysql( $peek['ready_by_utc'] ),
			'pickup_label'            => $label,
			'capacity_unpaid_allowed' => ! $payment_required,
		);
	}

	/**
	 * Cart hash exactly as the order writer recomputes it.
	 *
	 * @param DoughBoss_Cart $cart        Cart.
	 * @param int            $location_id Location id.
	 * @return string Empty when the cart is empty or invalid.
	 */
	private static function cart_hash_for( DoughBoss_Cart $cart, $location_id ) {
		$totals = $cart->totals( 'pickup' );
		return DoughBoss_Capacity::cart_hash(
			$cart->get_lines(),
			array(
				'location_id' => (int) $location_id,
				'order_type'  => 'pickup',
				'voucher'     => isset( $totals['voucher_code'] ) ? (string) $totals['voucher_code'] : '',
				'total'       => isset( $totals['total'] ) ? $totals['total'] : 0,
			)
		);
	}

	// ---------------------------------------------------------------------
	// Pure helpers
	// ---------------------------------------------------------------------

	/**
	 * Choose the ASAP window: the first available window today, only while the
	 * shop is open. A full first window therefore pushes ASAP to the next one;
	 * no per-order minutes are invented.
	 *
	 * @param array[]                $windows   Output of DoughBoss_Capacity::windows().
	 * @param bool                   $open_now  Whether the shop is open at $now.
	 * @param DateTimeImmutable|null $now       Clock.
	 * @param DateTimeZone|string    $timezone  Shop timezone.
	 * @return array{ready_from_utc:string,ready_by_utc:string,eta_minutes:int,label:string}|null
	 */
	public static function asap_from_windows( array $windows, $open_now, $now, $timezone ) {
		if ( ! $open_now || ! $now instanceof DateTimeImmutable ) {
			return null;
		}
		try {
			$zone = $timezone instanceof DateTimeZone ? $timezone : new DateTimeZone( (string) $timezone );
		} catch ( Exception $e ) {
			return null;
		}
		$today = $now->setTimezone( $zone )->format( 'Y-m-d' );
		foreach ( $windows as $window ) {
			if ( ! is_array( $window ) || ! isset( $window['local_date'], $window['ready_by_utc'], $window['ready_from_utc'] ) ) {
				continue;
			}
			if ( $window['local_date'] !== $today || ( isset( $window['availability'] ) && 'available' !== $window['availability'] ) ) {
				continue;
			}
			$by = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', (string) $window['ready_by_utc'], new DateTimeZone( 'UTC' ) );
			if ( ! $by ) {
				continue;
			}
			$eta = (int) ceil( ( $by->getTimestamp() - $now->getTimestamp() ) / 60 );
			$eta = max( 0, $eta );
			return array(
				'ready_from_utc' => (string) $window['ready_from_utc'],
				'ready_by_utc'   => (string) $window['ready_by_utc'],
				'eta_minutes'    => $eta,
				/* translators: %d: minutes until the order is ready. */
				'label'          => sprintf( __( 'ASAP — ready in about %d min', 'doughboss' ), $eta ),
			);
		}
		return null;
	}

	/**
	 * Whether the shop is open at an instant according to the live config hours.
	 *
	 * @param array             $config Live capacity config.
	 * @param DateTimeImmutable $now    UTC clock.
	 * @return bool
	 */
	public static function shop_open_now( array $config, DateTimeImmutable $now ) {
		try {
			$zone = new DateTimeZone( isset( $config['timezone'] ) ? (string) $config['timezone'] : '' );
		} catch ( Exception $e ) {
			return false;
		}
		$keys      = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );
		$stamp     = $now->getTimestamp();
		$today     = $now->setTimezone( $zone )->setTime( 0, 0, 0 );
		$blackouts = array_flip( isset( $config['blackout_dates'] ) ? (array) $config['blackout_dates'] : array() );
		for ( $offset = -1; $offset <= 0; ++$offset ) {
			$day  = $today->modify( $offset . ' days' );
			$date = $day->format( 'Y-m-d' );
			if ( isset( $blackouts[ $date ] ) ) {
				continue;
			}
			$weekday = $keys[ (int) $day->format( 'w' ) ];
			$ranges  = isset( $config['hours'][ $weekday ] ) ? (array) $config['hours'][ $weekday ] : array();
			foreach ( $ranges as $range ) {
				if ( ! is_array( $range ) || 2 !== count( $range ) ) {
					continue;
				}
				$close_date = strcmp( (string) $range[1], (string) $range[0] ) <= 0 ? $day->modify( '+1 day' )->format( 'Y-m-d' ) : $date;
				$open       = self::local_instant( $date, (string) $range[0], $zone );
				$close      = self::local_instant( $close_date, (string) $range[1], $zone );
				if ( $open && $close && $open->getTimestamp() <= $stamp && $stamp < $close->getTimestamp() ) {
					return true;
				}
			}
		}
		return false;
	}

	/** @return DateTimeImmutable|false */
	private static function local_instant( $date, $time, DateTimeZone $zone ) {
		$instant = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, $zone );
		return ( $instant && $instant->format( 'Y-m-d H:i' ) === $date . ' ' . $time ) ? $instant : false;
	}

	/**
	 * Build the options payload for a live shop. Pure given its inputs.
	 *
	 * @param array             $config Live capacity config.
	 * @param array             $usage  Orders used keyed by UTC slot start (MySQL).
	 * @param DateTimeImmutable $now    UTC clock.
	 * @return array
	 */
	public static function compose_options( array $config, array $usage, DateTimeImmutable $now, $demand = 1 ) {
		$zone    = new DateTimeZone( (string) $config['timezone'] );
		$windows = DoughBoss_Capacity::windows( $config, $demand, $usage, $now );
		$has_hours = false;
		foreach ( (array) $config['hours'] as $ranges ) {
			if ( ! empty( $ranges ) ) {
				$has_hours = true;
				break;
			}
		}
		$open_now = self::shop_open_now( $config, $now );

		$today_key    = $now->setTimezone( $zone )->format( 'Y-m-d' );
		$tomorrow_key = $now->setTimezone( $zone )->modify( '+1 day' )->format( 'Y-m-d' );
		$days         = array();
		$count        = 0;
		foreach ( $windows as $window ) {
			if ( $count >= self::MAX_SLOTS ) {
				break;
			}
			$date = $window['local_date'];
			if ( ! isset( $days[ $date ] ) ) {
				if ( $date === $today_key ) {
					$label = __( 'Today', 'doughboss' );
				} elseif ( $date === $tomorrow_key ) {
					$label = __( 'Tomorrow', 'doughboss' );
				} else {
					$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $zone );
					$label  = $parsed ? $parsed->format( 'D j M' ) : $date;
				}
				$days[ $date ] = array( 'date' => $date, 'label' => $label, 'slots' => array() );
			}
			$days[ $date ]['slots'][] = array(
				'ready_from_utc' => $window['ready_from_utc'],
				'ready_by_utc'   => $window['ready_by_utc'],
				'local_from'     => $window['local_from'],
				'local_by'       => $window['local_by'],
				'label'          => class_exists( 'DoughBoss_Store_Hours' ) ? DoughBoss_Store_Hours::format_clock( $window['local_from'] ) : $window['local_from'],
				'available'      => 'available' === $window['availability'],
				'remaining'      => (int) $window['remaining_units'],
			);
			++$count;
		}

		$reason = '';
		if ( ! $has_hours ) {
			$reason = 'no_hours';
		} elseif ( ! $windows ) {
			$reason = 'no_slots';
		} elseif ( ! $open_now ) {
			$reason = 'closed_now';
		}

		$prep = isset( $config['prep_minutes'] ) ? (int) $config['prep_minutes'] : 0;
		return array(
			'mode'          => 'live',
			'enforce'       => true,
			'paused'        => false,
			'closed_reason' => $reason,
			'timezone'      => (string) $config['timezone'],
			'server_now_utc' => $now->format( 'Y-m-d\TH:i:s\Z' ),
			'prep_minutes'  => $prep,
			'lead_minutes'  => (int) $config['notice_minutes'],
			'slot_minutes'  => (int) $config['slot_minutes'],
			'hold_minutes'  => isset( $config['hold_minutes'] ) ? (int) $config['hold_minutes'] : 10,
			'days_ahead'    => max( 0, (int) $config['horizon_days'] - 1 ),
			'asap'          => self::asap_from_windows( $windows, $open_now, $now, $zone ),
			'days'          => array_values( $days ),
		);
	}

	/**
	 * Minimal payload for shops that do not enforce pickup times.
	 *
	 * @param string            $mode   off | shadow | live.
	 * @param bool              $paused Whether ordering is paused.
	 * @param DateTimeImmutable $now    Clock.
	 * @return array
	 */
	public static function inactive_options( $mode, $paused, DateTimeImmutable $now ) {
		return array(
			'mode'           => (string) $mode,
			'enforce'        => false,
			'paused'         => (bool) $paused,
			'closed_reason'  => $paused ? 'paused' : '',
			'server_now_utc' => $now->format( 'Y-m-d\TH:i:s\Z' ),
			'asap'           => null,
			'days'           => array(),
		);
	}

	// ---------------------------------------------------------------------
	// REST handlers
	// ---------------------------------------------------------------------

	/**
	 * Resolve the location id from a request (single-shop fallback).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int
	 */
	/** Server-owned persisted request context; old requests cannot opt in. */
	public static function preorder_context( $order, $locking = false ) {
		global $wpdb;
		if ( ! $order || 1 !== (int) ( isset( $order->preorder_workflow_version ) ? $order->preorder_workflow_version : 0 )
			|| 'preorder_request' !== $order->order_source || 'pending' !== $order->status || 'unpaid' !== $order->payment_status || 'pickup' !== $order->order_type ) {
			return new WP_Error( 'doughboss_preorder_pickup_ineligible', __( 'This request does not support staff-selected pickup.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$table = $wpdb->prefix . 'doughboss_order_items';
		$lines = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC" . ( $locking ? ' FOR UPDATE' : '' ), (int) $order->id ), ARRAY_A );
		foreach ( $lines as &$line ) {
			$line['toppings'] = ! empty( $line['toppings'] ) ? json_decode( $line['toppings'], true ) : array();
			if ( ! is_array( $line['toppings'] ) ) { return new WP_Error( 'doughboss_preorder_cart_invalid', __( 'This request cart requires staff review.', 'doughboss' ), array( 'status' => 409 ) ); }
		}
		unset( $line );
		$context = array( 'location_id' => (int) $order->location_id, 'order_type' => 'pickup', 'voucher' => (string) $order->voucher_code, 'total' => $order->total );
		$demand = DoughBoss_Capacity::demand_units( $lines );
		$hash = DoughBoss_Capacity::cart_hash( $lines, $context );
		if ( ! $demand || ! $hash ) { return new WP_Error( 'doughboss_preorder_cart_invalid', __( 'This request cart requires staff review.', 'doughboss' ), array( 'status' => 409 ) ); }
		return array( 'lines' => $lines, 'context' => $context, 'demand_units' => $demand, 'cart_hash' => $hash );
	}

	public static function preorder_options( $order, DateTimeImmutable $now ) {
		$cart = self::preorder_context( $order );
		if ( is_wp_error( $cart ) ) { return $cart; }
		$options = self::options_for_location( DoughBoss_Locations::get( (int) $order->location_id ), $now, $cart['demand_units'] );
		$config = DoughBoss_Locations::capacity_live_config( (int) $order->location_id );
		$options['config_key'] = $config ? hash( 'sha256', wp_json_encode( $config ) ) : '';
		$options['request_version'] = (int) $order->version;
		$options['demand_units'] = $cart['demand_units'];
		return $options;
	}

	/** Caller owns transaction. Configuration -> slot -> hold precede source locks. */
	public static function prepare_preorder_slot( $order, array $selection, array $cart, DateTimeImmutable $now ) {
		$candidate = self::preorder_candidate( $order, $selection, $cart, $now );
		if ( is_wp_error( $candidate ) ) { return $candidate; }
		return DoughBoss_Capacity::materialise_slot( $candidate['config'], $candidate['window'], $now );
	}

	private static function preorder_candidate( $order, array $selection, array $cart, DateTimeImmutable $now ) {
		$mode = isset( $selection['pickup_mode'] ) ? (string) $selection['pickup_mode'] : '';
		$from = isset( $selection['ready_from_utc'] ) ? (string) $selection['ready_from_utc'] : '';
		$key = isset( $selection['pickup_config_key'] ) ? (string) $selection['pickup_config_key'] : '';
		if ( ! in_array( $mode, array( 'asap', 'scheduled' ), true ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $from ) || ! preg_match( '/^[a-f0-9]{64}$/D', $key ) ) {
			return new WP_Error( 'doughboss_preorder_pickup_required', __( 'Choose an available pickup time after confirming it with the customer.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$loc = DoughBoss_Locations::get( (int) $order->location_id );
		$config = DoughBoss_Locations::capacity_live_config( (int) $order->location_id );
		if ( 'live' !== self::mode_for( $loc ) || ! empty( $loc->ordering_paused ) || ! $config || ! hash_equals( $key, hash( 'sha256', wp_json_encode( $config ) ) ) ) {
			return new WP_Error( 'doughboss_preorder_pickup_stale', __( 'Pickup settings changed. Refresh available times and confirm the selection again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$usage = DoughBoss_Capacity::usage_for_location( (int) $order->location_id, $now->format( 'Y-m-d H:i:s' ), $now->modify( '+' . ( (int) $config['horizon_days'] + 1 ) . ' days' )->format( 'Y-m-d H:i:s' ), $now );
		$windows = DoughBoss_Capacity::windows( $config, $cart['demand_units'], $usage, $now );
		$window = null;
		foreach ( $windows as $candidate ) {
			if ( $from === $candidate['ready_from_utc'] && 'available' === $candidate['availability'] ) { $window = $candidate; break; }
		}
		$asap = self::asap_from_windows( $windows, self::shop_open_now( $config, $now ), $now, new DateTimeZone( $config['timezone'] ) );
		if ( ! $window || ( 'asap' === $mode && ( ! $asap || ! isset( $asap['ready_from_utc'] ) || $from !== $asap['ready_from_utc'] ) ) ) {
			return new WP_Error( 'doughboss_slot_unavailable', __( 'That pickup time is no longer available for this request.', 'doughboss' ), array( 'status' => 409 ) );
		}
		return array( 'config' => $config, 'window' => $window );
	}

	public static function reserve_preorder_locked( $order, array $selection, array $cart, $advisory_slot_id, DateTimeImmutable $now ) {
		global $wpdb;
		$locations = $wpdb->prefix . 'doughboss_locations';
		$loc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$locations} WHERE id = %d FOR UPDATE", (int) $order->location_id ) );
		foreach ( array( 'doughboss_location_hours', 'doughboss_schedule_exceptions' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE location_id = %d AND order_type = 'pickup' FOR UPDATE", (int) $order->location_id ) );
		}
		$slots = $wpdb->prefix . 'doughboss_capacity_slots';
		$mutex = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$slots} WHERE id = %d FOR UPDATE", absint( $advisory_slot_id ) ) );
		if ( ! $mutex || (int) $mutex->location_id !== (int) $order->location_id ) {
			return new WP_Error( 'doughboss_slot_unavailable', __( 'That pickup time is no longer available.', 'doughboss' ), array( 'status' => 409 ) );
		}
		// First consistent read occurs only after the slot mutex: concurrent public
		// holds are included by the existing capacity admission query in this transaction.
		$candidate = self::preorder_candidate( $order, $selection, $cart, $now );
		if ( is_wp_error( $candidate ) ) { return $candidate; }
		$config = $candidate['config'];
		$window = $candidate['window'];
		$mode = (string) $selection['pickup_mode'];
		$slot = DoughBoss_Capacity::materialise_slot( $config, $window, $now );
		if ( is_wp_error( $slot ) ) { return $slot; }
		$context = $cart['context'];
		$context['hold_minutes'] = isset( $config['hold_minutes'] ) ? (int) $config['hold_minutes'] : 10;
		$hold = DoughBoss_Capacity::create_hold_locked( $slot, $cart['lines'], $context, self::preorder_hold_key( $order ), $now );
		if ( is_wp_error( $hold ) ) { return $hold; }
		$from_mysql = self::iso_to_mysql( $window['ready_from_utc'] );
		return array(
			'capacity_hold_id' => (int) $hold['hold_id'], 'capacity_units' => (int) $hold['capacity_units'],
			'pickup_mode' => $mode, 'promised_ready_from_utc' => $from_mysql,
			'promised_ready_by_utc' => self::iso_to_mysql( $window['ready_by_utc'] ),
			'timezone_snapshot' => (string) $config['timezone'],
			'fire_at_utc' => gmdate( 'Y-m-d H:i:s', strtotime( $from_mysql . ' UTC' ) - ( (int) $config['prep_minutes'] * 60 ) ),
			'planning_version' => (int) $config['planning_version'],
			'payment_expires_at_utc' => self::iso_to_mysql( $hold['expires_at_utc'] ),
			'cart_hash' => $cart['cart_hash'],
		);
	}

	public static function preorder_hold_key( $order ) {
		return 'preorder-source-' . hash( 'sha256', (string) (int) $order->id );
	}


	private static function request_location_id( $request ) {
		$id = absint( $request->get_param( 'location_id' ) );
		if ( ! $id ) {
			$id = (int) DoughBoss_Locations::single_location_id();
		}
		return $id;
	}

	/**
	 * GET /pickup/options.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_options( $request ) {
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$id  = self::request_location_id( $request );
		$loc = $id ? DoughBoss_Locations::get( $id ) : null;
		$out = self::options_for_location( $loc, $now );
		$response = rest_ensure_response( $out );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}

	/**
	 * Options payload for a location row.
	 *
	 * @param object|null       $loc Location row.
	 * @param DateTimeImmutable $now Clock.
	 * @return array
	 */
	public static function options_for_location( $loc, DateTimeImmutable $now, $demand = 1 ) {
		$mode   = self::mode_for( $loc );
		$paused = $loc && ! empty( $loc->ordering_paused );
		if ( 'live' !== $mode || $paused ) {
			return self::inactive_options( $mode, $paused, $now );
		}
		$config = DoughBoss_Locations::capacity_live_config( (int) $loc->id );
		if ( ! $config ) {
			return self::inactive_options( 'off', false, $now );
		}
		$from  = $now->format( 'Y-m-d H:i:s' );
		$to    = $now->modify( '+' . ( (int) $config['horizon_days'] + 1 ) . ' days' )->format( 'Y-m-d H:i:s' );
		$usage = DoughBoss_Capacity::usage_for_location( (int) $loc->id, $from, $to, $now );
		return self::compose_options( $config, $usage, $now, $demand );
	}

	/**
	 * POST /pickup/hold.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_hold( $request ) {
		$limited = self::rate_limit();
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$id  = self::request_location_id( $request );
		$loc = $id ? DoughBoss_Locations::get( $id ) : null;
		if ( 'live' !== self::mode_for( $loc ) || ! empty( $loc->ordering_paused ) ) {
			return new WP_Error( 'doughboss_pickup_off', __( 'Pickup times are not available for this shop right now.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$cart = self::$cart;
		if ( ! $cart instanceof DoughBoss_Cart || $cart->is_empty() ) {
			return new WP_Error( 'doughboss_empty', __( 'Your cart is empty.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$mode = sanitize_key( (string) $request->get_param( 'pickup_mode' ) );
		$from = trim( (string) $request->get_param( 'ready_from_utc' ) );
		if ( ! in_array( $mode, array( 'asap', 'scheduled' ), true ) || ! DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $from, new DateTimeZone( 'UTC' ) ) ) {
			return new WP_Error( 'doughboss_pickup_invalid', __( 'Please choose a valid pickup time.', 'doughboss' ), array( 'status' => 400 ) );
		}

		// Best effort: free the caller's previous hold first so re-holding the same
		// slot after a cart change cannot be refused by the caller's own old hold.
		$release_token = trim( (string) $request->get_param( 'release_token' ) );
		if ( '' !== $release_token ) {
			DoughBoss_Capacity::release_hold( $release_token );
		}

		$config = DoughBoss_Locations::capacity_live_config( (int) $loc->id );
		if ( ! $config ) {
			return new WP_Error( 'doughboss_pickup_off', __( 'Pickup times are not available for this shop right now.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$usage   = DoughBoss_Capacity::usage_for_location( (int) $loc->id, $now->format( 'Y-m-d H:i:s' ), $now->modify( '+' . ( (int) $config['horizon_days'] + 1 ) . ' days' )->format( 'Y-m-d H:i:s' ), $now );
		$windows = DoughBoss_Capacity::windows( $config, 1, $usage, $now );
		$window  = null;
		foreach ( $windows as $candidate ) {
			if ( $candidate['ready_from_utc'] === $from ) {
				$window = $candidate;
				break;
			}
		}
		if ( ! $window || ( 'asap' === $mode && ! self::shop_open_now( $config, $now ) ) ) {
			return new WP_Error( 'doughboss_slot_unavailable', __( 'That pickup time is no longer available.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( 'available' !== $window['availability'] ) {
			return new WP_Error( 'doughboss_slot_full', __( 'That pickup time has just filled up. Please choose another time.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$slot_id = DoughBoss_Capacity::materialise_slot( $config, $window, $now );
		if ( is_wp_error( $slot_id ) ) {
			return $slot_id;
		}
		$totals = $cart->totals( 'pickup' );
		$hold   = DoughBoss_Capacity::create_hold(
			$slot_id,
			$cart->get_lines(),
			array(
				'location_id'  => (int) $loc->id,
				'order_type'   => 'pickup',
				'voucher'      => isset( $totals['voucher_code'] ) ? (string) $totals['voucher_code'] : '',
				'total'        => isset( $totals['total'] ) ? $totals['total'] : 0,
				'hold_minutes' => isset( $config['hold_minutes'] ) ? (int) $config['hold_minutes'] : 10,
			),
			(string) $request->get_param( 'idempotency_key' ),
			$now
		);
		if ( is_wp_error( $hold ) ) {
			return $hold;
		}

		$by  = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $window['ready_by_utc'], new DateTimeZone( 'UTC' ) );
		$eta = $by ? max( 0, (int) ceil( ( $by->getTimestamp() - $now->getTimestamp() ) / 60 ) ) : 0;
		$out = array(
			'hold_token'     => $hold['hold_token'],
			'slot_id'        => (int) $hold['slot_id'],
			'expires_at_utc' => $hold['expires_at_utc'],
			'ready_from_utc' => $window['ready_from_utc'],
			'ready_by_utc'   => $window['ready_by_utc'],
			'label'          => class_exists( 'DoughBoss_Store_Hours' ) ? DoughBoss_Store_Hours::format_pickup_time( $window['ready_from_utc'], $config['timezone'], $now ) : '',
			'eta_minutes'    => $eta,
			'pickup_mode'    => $mode,
		);
		$response = rest_ensure_response( $out );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}

	/**
	 * POST /pickup/release (best effort).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function release( $request ) {
		$released = DoughBoss_Capacity::release_hold( (string) $request->get_param( 'hold_token' ) );
		return rest_ensure_response( array( 'released' => (bool) $released ) );
	}

	/**
	 * Per-client limiter for hold creation. Fails open on any storage problem.
	 *
	 * @return true|WP_Error
	 */
	private static function rate_limit() {
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			return true;
		}
		try {
			$ip    = self::client_ip();
			$key   = 'doughboss_pk_hold_' . md5( $ip );
			$state = get_transient( $key );
			$now   = time();
			if ( ! is_array( $state ) || ! isset( $state['n'], $state['t'] ) || $now - (int) $state['t'] >= self::RATE_WINDOW ) {
				$state = array( 'n' => 0, 't' => $now );
			}
			if ( (int) $state['n'] >= self::RATE_LIMIT ) {
				return new WP_Error( 'doughboss_rate_limited', __( 'Too many pickup-time requests. Please wait a few minutes.', 'doughboss' ), array( 'status' => 429 ) );
			}
			$state['n'] = (int) $state['n'] + 1;
			set_transient( $key, $state, max( 1, self::RATE_WINDOW - ( $now - (int) $state['t'] ) ) );
		} catch ( Throwable $e ) {
			return true;
		}
		return true;
	}

	/**
	 * Client address for the limiter, resolved the same way as the REST
	 * controller's limiters: REMOTE_ADDR unless the operator opted into a
	 * trusted reverse-proxy header. Behind Cloudflare/nginx without that
	 * resolution every customer would share one 40-holds bucket.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$clean  = static function ( $value ) {
			$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : stripslashes( (string) $value );
			return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( (string) $value );
		};
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? $clean( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! class_exists( 'DoughBoss_Settings' ) || ! method_exists( 'DoughBoss_Settings', 'behind_reverse_proxy' ) || ! DoughBoss_Settings::behind_reverse_proxy() ) {
			return $remote;
		}
		$server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', DoughBoss_Settings::trusted_proxy_header() ) );
		if ( empty( $_SERVER[ $server_key ] ) ) {
			return $remote;
		}
		$forwarded = $clean( $_SERVER[ $server_key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$parts     = explode( ',', $forwarded );
		$candidate = trim( (string) reset( $parts ) );
		if ( '' === $candidate || ! filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
			return $remote;
		}
		return $candidate;
	}

	/**
	 * Free the slot when an order is cancelled.
	 *
	 * @param int    $order_id Order id.
	 * @param string $status   New status.
	 * @return void
	 */
	public static function on_order_status_changed( $order_id, $status ) {
		if ( 'cancelled' === (string) $status ) {
			DoughBoss_Capacity::release_order_hold( (int) $order_id );
		}
	}

	/** @return string MySQL UTC timestamp, or '' when invalid. */
	private static function iso_to_mysql( $iso ) {
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', (string) $iso, new DateTimeZone( 'UTC' ) );
		return $parsed ? $parsed->format( 'Y-m-d H:i:s' ) : '';
	}
}
