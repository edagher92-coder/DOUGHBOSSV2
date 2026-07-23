<?php
/**
 * Order data model and persistence.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes orders to the custom tables.
 */
class DoughBoss_Order {

	/**
	 * Valid order statuses mapped to human labels.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'pending'         => __( 'Pending', 'doughboss' ),
			'confirmed'       => __( 'Confirmed', 'doughboss' ),
			'preparing'       => __( 'Preparing', 'doughboss' ),
			'baking'          => __( 'In the Oven', 'doughboss' ),
			'ready'           => __( 'Ready for Pickup', 'doughboss' ),
			'out_for_delivery'=> __( 'Out for Delivery', 'doughboss' ),
			'completed'       => __( 'Completed', 'doughboss' ),
			'cancelled'       => __( 'Cancelled', 'doughboss' ),
		);
	}

	/**
	 * Order-aware staff label for fulfilment-sensitive lifecycle states.
	 *
	 * @param object $order Order row.
	 * @return string
	 */
	public static function status_label_for( $order ) {
		if ( self::is_preorder_request( $order ) ) {
			return __( 'Pre-order request — pending morning review', 'doughboss' );
		}
		if ( isset( $order->order_type ) && 'dine_in' === $order->order_type ) {
			if ( 'ready' === $order->status ) {
				return __( 'Ready to Serve', 'doughboss' );
			}
			if ( 'completed' === $order->status ) {
				return __( 'Served', 'doughboss' );
			}
		}
		$statuses = self::statuses();
		return isset( $statuses[ $order->status ] ) ? $statuses[ $order->status ] : $order->status;
	}

	/**
	 * Whether an order row is an unpaid after-hours request awaiting staff review.
	 *
	 * @param object|array $order Order row.
	 * @return bool
	 */
	public static function is_preorder_request( $order ) {
		$row = (array) $order;
		return isset( $row['order_source'], $row['status'] )
			&& 'preorder_request' === $row['order_source']
			&& 'pending' === $row['status'];
	}

	/**
	 * Valid next states for an order.
	 *
	 * The persisted legacy status names remain in place for compatibility, but
	 * every write now follows this single forward-only graph.
	 *
	 * @param string $status     Current status.
	 * @param string $order_type pickup, delivery, or dine_in.
	 * @return string[]
	 */
	public static function allowed_transitions( $status, $order_type = 'pickup' ) {
		$map = array(
			'pending'          => array( 'confirmed', 'cancelled' ),
			'confirmed'        => array( 'preparing', 'cancelled' ),
			'preparing'        => array( 'baking', 'ready', 'cancelled' ),
			'baking'           => array( 'ready', 'cancelled' ),
			'ready'            => 'delivery' === $order_type ? array( 'out_for_delivery' ) : array( 'completed' ),
			'out_for_delivery' => array( 'completed' ),
			'completed'        => array(),
			'cancelled'        => array(),
		);

		return isset( $map[ $status ] ) ? $map[ $status ] : array();
	}

	/**
	 * Whether one lifecycle edge is valid.
	 *
	 * @param string $from       Current status.
	 * @param string $to         Requested status.
	 * @param string $order_type pickup or delivery.
	 * @return bool
	 */
	public static function can_transition( $from, $to, $order_type = 'pickup' ) {
		return in_array( $to, self::allowed_transitions( $from, $order_type ), true );
	}

	/**
	 * Next states currently available for this specific order.
	 *
	 * Paid orders must be refunded before cancellation, so that action is not
	 * advertised to staff while payment still says paid.
	 *
	 * @param object $order Order row.
	 * @return string[]
	 */
	private static function available_transitions( $order ) {
		$allowed = self::allowed_transitions( $order->status, $order->order_type );
		if ( isset( $order->payment_status ) && 'paid' === $order->payment_status ) {
			$allowed = array_values( array_diff( $allowed, array( 'cancelled' ) ) );
		}
		return $allowed;
	}

	/**
	 * Translate internal kitchen states into truthful customer language.
	 *
	 * @param object|array $order Order row or a status/order_type pair.
	 * @return array{status:string,label:string}
	 */
	public static function customer_projection( $order ) {
		$row        = (array) $order;
		$status     = isset( $row['status'] ) ? $row['status'] : 'pending';
		$order_type = isset( $row['order_type'] ) ? $row['order_type'] : 'pickup';
		if ( self::is_preorder_request( $row ) ) {
			return array(
				'preorder_pending_review',
				__( 'Pre-order request received — Revesby will review it first thing in the morning. It is not confirmed or paid.', 'doughboss' )
			);
		}
		$map        = array(
			'pending'          => array( 'received', __( 'Order received — waiting for the shop to accept', 'doughboss' ) ),
			'confirmed'        => array( 'confirmed', __( 'Accepted by the shop', 'doughboss' ) ),
			'preparing'        => array( 'preparing', __( 'Being prepared', 'doughboss' ) ),
			'baking'           => array( 'preparing', __( 'Being prepared', 'doughboss' ) ),
			'ready'            => 'delivery' === $order_type
				? array( 'ready_for_delivery', __( 'Ready for delivery', 'doughboss' ) )
				: ( 'dine_in' === $order_type
					? array( 'ready_to_serve', __( 'Ready to be served', 'doughboss' ) )
					: array( 'ready_for_pickup', __( 'Ready for pickup', 'doughboss' ) ) ),
			'out_for_delivery' => array( 'out_for_delivery', __( 'On its way', 'doughboss' ) ),
			'completed'        => 'delivery' === $order_type
				? array( 'delivered', __( 'Delivered', 'doughboss' ) )
				: ( 'dine_in' === $order_type
					? array( 'served', __( 'Served', 'doughboss' ) )
					: array( 'collected', __( 'Collected', 'doughboss' ) ) ),
			'cancelled'        => array( 'cancelled', __( 'Cancelled', 'doughboss' ) ),
		);
		$value = isset( $map[ $status ] ) ? $map[ $status ] : array( 'received', __( 'Order received', 'doughboss' ) );

		return array( 'status' => $value[0], 'label' => $value[1] );
	}

	/**
	 * Derive a display-only timing state. This never advances an order.
	 *
	 * @param object|array $order Order row.
	 * @param string       $now   Optional UTC MySQL timestamp for tests.
	 * @return array{status:string,label:string}
	 */
	public static function timing_projection( $order, $now = '' ) {
		$row    = (array) $order;
		$status = isset( $row['status'] ) ? $row['status'] : 'pending';
		$now    = $now ? $now : current_time( 'mysql', true );

		if ( 'cancelled' === $status ) {
			return array( 'status' => 'cancelled', 'label' => __( 'Order cancelled', 'doughboss' ) );
		}
		if ( 'completed' === $status ) {
			return array( 'status' => 'complete', 'label' => __( 'Order complete', 'doughboss' ) );
		}
		if ( in_array( $status, array( 'ready', 'out_for_delivery' ), true ) ) {
			return array( 'status' => 'ready', 'label' => __( 'Ready', 'doughboss' ) );
		}
		if ( 'pending' === $status ) {
			if ( self::is_preorder_request( $row ) ) {
				return array( 'status' => 'preorder_pending_review', 'label' => __( 'Pre-order request pending morning review', 'doughboss' ) );
			}
			return array( 'status' => 'awaiting_acceptance', 'label' => __( 'Awaiting staff acceptance', 'doughboss' ) );
		}

		$promised_by = isset( $row['promised_ready_by_utc'] ) ? $row['promised_ready_by_utc'] : '';
		if ( $promised_by && $now > $promised_by ) {
			return array( 'status' => 'estimate_passed', 'label' => __( 'Estimate passed — check this order', 'doughboss' ) );
		}

		return array( 'status' => 'on_track', 'label' => __( 'In progress', 'doughboss' ) );
	}

	/**
	 * Convert a stored UTC MySQL datetime to an ISO-8601 UTC string.
	 *
	 * @param string|null $value Stored datetime.
	 * @return string
	 */
	private static function utc_iso( $value ) {
		return $value ? str_replace( ' ', 'T', (string) $value ) . 'Z' : '';
	}

	/**
	 * Orders table name.
	 *
	 * @return string
	 */
	private static function orders_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_orders';
	}

	/**
	 * Order items table name.
	 *
	 * @return string
	 */
	private static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_order_items';
	}

	/**
	 * Order lifecycle events table name.
	 *
	 * @return string
	 */
	private static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_order_events';
	}

	/**
	 * Generate a unique, human-friendly order number.
	 *
	 * @return string
	 */
	private static function generate_order_number() {
		global $wpdb;
		$table = self::orders_table();

		do {
			$number = 'DB-' . gmdate( 'ymd' ) . '-' . strtoupper( wp_generate_password( 6, false, false ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_number = %s", $number ) );
		} while ( $exists );

		return $number;
	}

	/**
	 * Create an order from validated data and cart lines.
	 *
	 * @param array   $data  Customer/order fields and totals.
	 * @param array[] $lines Cart lines.
	 * @return array|WP_Error Creation result with order_id/replayed, or error.
	 */
	public static function create( array $data, array $lines ) {
		global $wpdb;
		if ( version_compare( (string) get_option( 'doughboss_db_version', '0' ), '1.13.0', '<' ) ) {
			return new WP_Error( 'doughboss_checkout_storage_unavailable', __( 'Online ordering is temporarily unavailable while checkout storage is upgraded.', 'doughboss' ), array( 'status' => 503 ) );
		}

		if ( empty( $lines ) ) {
			return new WP_Error( 'doughboss_empty', __( 'Cannot create an empty order.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$checkout_key = isset( $data['checkout_key'] ) ? strtolower( sanitize_text_field( $data['checkout_key'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $checkout_key ) ) {
			return new WP_Error( 'doughboss_checkout_key_required', __( 'This checkout attempt is missing its safety key. Please refresh and try again.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$payment_intent_id = isset( $data['payment_intent_id'] ) ? sanitize_text_field( $data['payment_intent_id'] ) : '';
		$payment_intent_id = '' === $payment_intent_id ? null : $payment_intent_id;
		$is_preorder_request = isset( $data['order_source'] ) && 'preorder_request' === sanitize_key( $data['order_source'] );
		if ( $is_preorder_request ) {
			$payment_status = isset( $data['payment_status'] ) ? sanitize_key( $data['payment_status'] ) : 'unpaid';
			$payment_method = isset( $data['payment_method'] ) ? sanitize_text_field( $data['payment_method'] ) : '';
			if ( 'unpaid' !== $payment_status || '' !== $payment_method || null !== $payment_intent_id || ! empty( $data['capacity_hold_token'] ) ) {
				return new WP_Error( 'doughboss_preorder_payment_forbidden', __( 'Pre-order requests are not confirmed orders and cannot take payment or reserve capacity.', 'doughboss' ), array( 'status' => 400 ) );
			}
		}

		$now    = current_time( 'mysql', true );
		$orders = self::orders_table();
		$items  = self::items_table();

		// Wrap the order row and its items in a single transaction so a partial
		// failure can never leave an order whose stored total doesn't match the
		// line items actually saved. (Requires InnoDB; a no-op on MyISAM.)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'doughboss_db_error', __( 'Could not start your order safely. Please try again.', 'doughboss' ), array( 'status' => 500 ) );
		}

		$capacity = null;
		if ( ! empty( $data['capacity_hold_token'] ) ) {
			if ( 'paid' !== ( isset( $data['payment_status'] ) ? $data['payment_status'] : 'unpaid' ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return new WP_Error( 'doughboss_capacity_payment_required', __( 'A verified payment is required before a scheduled pickup can be confirmed.', 'doughboss' ), array( 'status' => 409 ) );
			}
			if ( version_compare( (string) get_option( 'doughboss_db_version', '0' ), '1.12.0', '<' ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return new WP_Error( 'doughboss_capacity_unavailable', __( 'Pickup-time scheduling is temporarily unavailable.', 'doughboss' ), array( 'status' => 503 ) );
			}
			$cart_hash = DoughBoss_Capacity::cart_hash(
				$lines,
				array(
					'location_id' => isset( $data['location_id'] ) ? (int) $data['location_id'] : 0,
					'order_type'  => isset( $data['order_type'] ) ? $data['order_type'] : 'pickup',
					'voucher'     => isset( $data['voucher_code'] ) ? $data['voucher_code'] : '',
					'total'       => isset( $data['total'] ) ? $data['total'] : 0,
				)
			);
			$capacity = DoughBoss_Capacity::lock_hold_for_order( $data['capacity_hold_token'], $cart_hash, isset( $data['location_id'] ) ? (int) $data['location_id'] : 0 );
			if ( is_wp_error( $capacity ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $capacity;
			}
			if ( ! empty( $capacity['replayed_order_id'] ) ) {
				if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return new WP_Error( 'doughboss_db_error', __( 'Could not safely replay your order. Please try again.', 'doughboss' ), array( 'status' => 500 ) );
				}
				return array( 'order_id' => (int) $capacity['replayed_order_id'], 'replayed' => true, 'replayed_by' => 'capacity_hold' );
			}
		}

		// Insert the order, retrying on the (rare) order-number collision that
		// the UNIQUE key would otherwise reject under concurrent checkouts.
		$order_id = 0;
		$attempts = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$inserted = $wpdb->insert(
				$orders,
				array(
					'order_number'  => self::generate_order_number(),
					'location_id'   => isset( $data['location_id'] ) ? (int) $data['location_id'] : 0,
					'status'        => 'pending',
					'order_type'    => $data['order_type'],
					'table_id'      => isset( $data['table_id'] ) ? (int) $data['table_id'] : 0,
					'table_label'   => isset( $data['table_label'] ) ? sanitize_text_field( $data['table_label'] ) : '',
					'table_qr_code_id' => isset( $data['table_qr_code_id'] ) ? (int) $data['table_qr_code_id'] : 0,
					'table_session_id' => isset( $data['table_session_id'] ) ? (int) $data['table_session_id'] : 0,
					'order_source'  => isset( $data['order_source'] ) ? sanitize_key( $data['order_source'] ) : 'web',
					'customer_name' => $data['customer_name'],
					'customer_email'=> $data['customer_email'],
					'customer_phone'=> $data['customer_phone'],
					'address'       => $data['address'],
					'notes'         => $data['notes'],
					'subtotal'      => $data['subtotal'],
					'tax'           => $data['tax'],
					'delivery_fee'  => $data['delivery_fee'],
					'total'         => $data['total'],
					'discount'      => isset( $data['discount'] ) ? $data['discount'] : 0,
					'voucher_code'  => isset( $data['voucher_code'] ) ? $data['voucher_code'] : '',
					'currency'      => DoughBoss_Settings::get( 'currency_code', 'AUD' ),
					'payment_status'    => isset( $data['payment_status'] ) ? $data['payment_status'] : 'unpaid',
					'payment_method'    => isset( $data['payment_method'] ) ? $data['payment_method'] : '',
					'payment_intent_id' => $payment_intent_id,
					'checkout_key'      => $checkout_key,
					'capacity_hold_id' => $capacity ? $capacity['hold_id'] : 0,
					'capacity_units' => $capacity ? $capacity['capacity_units'] : 0,
					'promised_ready_from_utc' => $capacity ? $capacity['promised_ready_from_utc'] : null,
					'promised_ready_by_utc' => $capacity ? $capacity['promised_ready_by_utc'] : null,
					'timezone_snapshot' => $capacity ? $capacity['timezone_snapshot'] : '',
					'fire_at_utc' => $capacity ? $capacity['fire_at_utc'] : null,
					'planning_version' => $capacity ? $capacity['planning_version'] : 0,
					'created_at'    => $now,
					'updated_at'    => $now,
				),
				array( '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);

			if ( false !== $inserted ) {
				$order_id = (int) $wpdb->insert_id;
				break;
			}

			// A unique-key loser is a replay, not a failed checkout. The conflicting
			// INSERT waits for the winner's transaction, so these lookups can return
			// the authoritative order without firing hooks or writing items twice.
			$replay_id = self::find_id_by_checkout_key( $checkout_key, true );
			$replayed_by = 'checkout_key';
			if ( ! $replay_id && null !== $payment_intent_id ) {
				$replay_id   = self::find_id_by_payment_intent( $payment_intent_id, true );
				$replayed_by = 'payment_intent_id';
			}
			if ( $replay_id ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return array( 'order_id' => $replay_id, 'replayed' => true, 'replayed_by' => $replayed_by );
			}
			++$attempts;
		} while ( $attempts < 5 );

		if ( ! $order_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'doughboss_db_error', __( 'Could not save your order. Please try again.', 'doughboss' ), array( 'status' => 500 ) );
		}

		foreach ( $lines as $line ) {
			$toppings = isset( $line['toppings'] ) ? wp_json_encode( array_values( $line['toppings'] ) ) : '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$line_ok = $wpdb->insert(
				$items,
				array(
					'order_id'   => $order_id,
					'item_id'    => (int) $line['item_id'],
					'name'       => $line['name'],
					'size'       => isset( $line['size'] ) ? $line['size'] : '',
					'toppings'   => $toppings,
					'quantity'   => (int) $line['quantity'],
					'unit_price' => (float) $line['unit_price'],
					'line_total' => (float) $line['line_total'],
				),
				array( '%d', '%d', '%s', '%s', '%s', '%d', '%f', '%f' )
			);

			if ( false === $line_ok ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'doughboss_db_error', __( 'Could not save your order. Please try again.', 'doughboss' ), array( 'status' => 500 ) );
			}
		}

		$event_ok = self::insert_event(
			array(
				'order_id'      => $order_id,
				'order_version' => 1,
				'event_type'    => 'created',
				'from_status'   => '',
				'to_status'     => 'pending',
				'actor_type'    => 'customer',
				'actor_id'      => 0,
				'reason_code'   => '',
				'event_key'     => 'order-created:' . $order_id,
				'occurred_at'   => $now,
			)
		);
		if ( ! $event_ok ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'doughboss_db_error', __( 'Could not save your order. Please try again.', 'doughboss' ), array( 'status' => 500 ) );
		}

		if ( $capacity && ! DoughBoss_Capacity::convert_locked_hold( $capacity['hold_id'], $order_id, $now ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_hold_conversion', __( 'The pickup time could not be attached to the order. Please try again.', 'doughboss' ), array( 'status' => 409 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_db_error', __( 'Could not finish saving your order. Please try again.', 'doughboss' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after an order has been created and all items stored.
		 *
		 * @param int   $order_id The new order ID.
		 * @param array $data     The order data.
		 */
		do_action( 'doughboss_order_created', $order_id, $data );

		return array( 'order_id' => $order_id, 'replayed' => false, 'replayed_by' => '' );
	}

	/**
	 * Active orders for the live kitchen board (excludes completed/cancelled),
	 * oldest first, each with its line items.
	 *
	 * @param int $limit       Maximum rows to return.
	 * @param int $location_id Optional shop filter (0 = all shops).
	 * @return array[]
	 */
	public static function active_orders( $limit = 100, $location_id = 0 ) {
		global $wpdb;
		$table       = self::orders_table();
		$limit       = max( 1, min( 200, (int) $limit ) );
		$location_id = absint( $location_id );
		$statuses    = self::statuses();

		if ( $location_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status NOT IN ( 'completed', 'cancelled' ) AND order_source <> 'preorder_request' AND location_id = %d ORDER BY created_at ASC LIMIT %d",
					$location_id,
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status NOT IN ( 'completed', 'cancelled' ) AND order_source <> 'preorder_request' ORDER BY created_at ASC LIMIT %d",
					$limit
				)
			);
		}

		$items_by_order = self::get_items_for_orders( wp_list_pluck( (array) $rows, 'id' ) );

		$out = array();
		foreach ( (array) $rows as $order ) {
			$out[] = self::shape_board_row( $order, $items_by_order, $statuses );
		}

		return $out;
	}

	/**
	 * Pending after-hours requests for the staff morning-review queue.
	 *
	 * Accepted requests change to the normal web channel atomically in
	 * transition(), so they disappear from this queue and enter the KDS only
	 * after staff action.
	 *
	 * @param int $limit       Maximum rows to return.
	 * @param int $location_id Optional shop filter (0 = all shops).
	 * @return array[]
	 */
	public static function preorder_requests( $limit = 100, $location_id = 0 ) {
		global $wpdb;
		$table       = self::orders_table();
		$limit       = max( 1, min( 200, (int) $limit ) );
		$location_id = absint( $location_id );
		$statuses    = self::statuses();

		if ( $location_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = 'pending' AND order_source = 'preorder_request' AND payment_status = 'unpaid' AND location_id = %d ORDER BY created_at ASC LIMIT %d",
					$location_id,
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = 'pending' AND order_source = 'preorder_request' AND payment_status = 'unpaid' ORDER BY created_at ASC LIMIT %d",
					$limit
				)
			);
		}

		$items_by_order = self::get_items_for_orders( wp_list_pluck( (array) $rows, 'id' ) );
		$out            = array();
		foreach ( (array) $rows as $order ) {
			$out[] = self::shape_board_row( $order, $items_by_order, $statuses );
		}

		return $out;
	}

	/**
	 * Shape a raw order row into the board/list representation shared by
	 * active_orders() and query_board() so both surfaces return an identical
	 * per-order field shape.
	 *
	 * @param object   $order          Raw order row.
	 * @param array    $items_by_order Map of order id => line items.
	 * @param string[] $statuses       Status slug => label map.
	 * @return array
	 */
	private static function shape_board_row( $order, array $items_by_order, array $statuses ) {
		$timing = self::timing_projection( $order );
		$status_label = self::status_label_for( $order );
		return array(
			'id'             => (int) $order->id,
			'order_number'   => $order->order_number,
			'location_id'    => (int) $order->location_id,
			'status'         => $order->status,
			'status_label'   => $status_label,
			'version'        => isset( $order->version ) ? (int) $order->version : 1,
			'allowed_next_statuses' => self::available_transitions( $order ),
			'order_type'     => $order->order_type,
			'table_id'       => isset( $order->table_id ) ? (int) $order->table_id : 0,
			'table_label'    => isset( $order->table_label ) ? $order->table_label : '',
			'order_source'   => isset( $order->order_source ) ? $order->order_source : 'web',
			'customer_name'  => $order->customer_name,
			'customer_phone' => $order->customer_phone,
			'address'        => $order->address,
			'notes'          => $order->notes,
			'total'          => (float) $order->total,
			'payment_status' => isset( $order->payment_status ) ? $order->payment_status : 'unpaid',
			'eta_minutes'    => (int) $order->eta_minutes,
			'promised_ready_from_utc' => self::utc_iso( isset( $order->promised_ready_from_utc ) ? $order->promised_ready_from_utc : '' ),
			'promised_ready_by_utc'   => self::utc_iso( isset( $order->promised_ready_by_utc ) ? $order->promised_ready_by_utc : '' ),
			'timezone'       => ! empty( $order->timezone_snapshot ) ? $order->timezone_snapshot : self::valid_timezone(),
			'timing_status'  => $timing['status'],
			'timing_label'   => $timing['label'],
			'acknowledged'   => ! empty( $order->acknowledged_at ),
			'accepted'       => ! empty( $order->accepted_at ),
			'accepted_at'    => self::utc_iso( isset( $order->accepted_at ) ? $order->accepted_at : '' ),
			'cooking_started_at' => self::utc_iso( isset( $order->cooking_started_at ) ? $order->cooking_started_at : '' ),
			'ready_at'       => self::utc_iso( isset( $order->ready_at ) ? $order->ready_at : '' ),
			'created_at'     => $order->created_at,
			'items'          => isset( $items_by_order[ (int) $order->id ] ) ? $items_by_order[ (int) $order->id ] : array(),
		);
	}

	/**
	 * Paginated order query returning rows in the same shape as active_orders()
	 * (id, order_number, status, items, …), used by the admin orders/history
	 * REST view. Unlike active_orders() this can reach completed/cancelled orders
	 * via the status filter supported by query().
	 *
	 * @param array $args Same arguments as query() (status, search, page, per_page).
	 * @return array{items:array[],total:int}
	 */
	public static function query_board( array $args = array() ) {
		$result         = self::query( $args );
		$rows           = $result['items'];
		$items_by_order = self::get_items_for_orders( wp_list_pluck( (array) $rows, 'id' ) );
		$statuses       = self::statuses();

		$out = array();
		foreach ( (array) $rows as $order ) {
			$out[] = self::shape_board_row( $order, $items_by_order, $statuses );
		}

		return array(
			'items' => $out,
			'total' => (int) $result['total'],
		);
	}

	/**
	 * Mark an order as acknowledged by staff (silences the new-order alert).
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public static function acknowledge( $order_id ) {
		global $wpdb;
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			self::orders_table(),
			array(
				'acknowledged_at' => current_time( 'mysql', true ),
				'seen_at'         => current_time( 'mysql', true ),
			),
			array( 'id' => $order_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Accept an order, moving it to "confirmed" and recording an ETA.
	 *
	 * @param int $order_id    Order ID.
	 * @param int $eta_minutes Estimated minutes until ready (0 = none given).
	 * @return bool
	 */
	public static function accept( $order_id, $eta_minutes = 0 ) {
		$order = self::get( $order_id );
		if ( ! $order ) {
			return false;
		}

		$result = self::transition(
			$order_id,
			'confirmed',
			array(
				'expected_version' => isset( $order->version ) ? (int) $order->version : 1,
				'event_key'       => self::legacy_event_key( $order_id, 'confirmed' ),
				'actor_type'      => 'staff',
				'actor_id'        => get_current_user_id(),
				'eta_minutes'     => $eta_minutes,
			)
		);

		return ! is_wp_error( $result );
	}

	/**
	 * Atomically move an order through the lifecycle and append its audit event.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status   Target status.
	 * @param array  $context  expected_version, event_key, actor_type, actor_id,
	 *                         reason_code and eta_minutes.
	 * @return array|WP_Error Transition result.
	 */
	public static function transition( $order_id, $status, array $context = array() ) {
		global $wpdb;
		if ( version_compare( (string) get_option( 'doughboss_db_version', '0' ), '1.11.0', '<' ) ) {
			return new WP_Error( 'doughboss_lifecycle_unavailable', __( 'Order updates are temporarily unavailable while the order system is upgraded.', 'doughboss' ), array( 'status' => 503 ) );
		}

		$order_id         = absint( $order_id );
		$status           = sanitize_key( $status );
		$expected_version = isset( $context['expected_version'] ) ? absint( $context['expected_version'] ) : 0;
		$event_key        = isset( $context['event_key'] ) ? substr( sanitize_text_field( $context['event_key'] ), 0, 191 ) : '';
		$actor_type       = isset( $context['actor_type'] ) ? substr( sanitize_key( $context['actor_type'] ), 0, 20 ) : 'staff';
		$actor_id         = isset( $context['actor_id'] ) ? absint( $context['actor_id'] ) : 0;
		$reason_code      = isset( $context['reason_code'] ) ? substr( sanitize_key( $context['reason_code'] ), 0, 32 ) : '';
		$eta_minutes      = isset( $context['eta_minutes'] ) ? min( 240, absint( $context['eta_minutes'] ) ) : 0;

		if ( ! $order_id || ! isset( self::statuses()[ $status ] ) || ! $expected_version || '' === $event_key ) {
			return new WP_Error( 'doughboss_transition_invalid', __( 'The order update was incomplete. Refresh and try again.', 'doughboss' ), array( 'status' => 400 ) );
		}
		if ( 'cancelled' === $status && '' === $reason_code ) {
			return new WP_Error( 'doughboss_cancel_reason', __( 'Choose a cancellation reason before cancelling this order.', 'doughboss' ), array( 'status' => 400 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'doughboss_transition_db', __( 'Could not start the order update.', 'doughboss' ), array( 'status' => 500 ) );
		}

		$events = self::events_table();
		// The idempotency lookup occurs inside the transaction. A duplicate key is
		// only a replay when it belongs to the same order and target state.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing_event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$events} WHERE event_key = %s LIMIT 1", $event_key ) );
		if ( $existing_event ) {
			$order = self::get( $order_id );
			if ( (int) $existing_event->order_id === $order_id && $existing_event->to_status === $status && $order ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return self::transition_result( $order, true );
			}
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_event_key_conflict', __( 'That update key has already been used.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$order = self::get( $order_id );
		if ( ! $order ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_order_not_found', __( 'That order no longer exists.', 'doughboss' ), array( 'status' => 404 ) );
		}

		$current_version = isset( $order->version ) ? (int) $order->version : 1;
		if ( $current_version !== $expected_version ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_stale_order', __( 'This order changed on another screen. It has been refreshed.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( ! self::can_transition( $order->status, $status, $order->order_type ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_invalid_transition', __( 'That step is not available from the order’s current state.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( 'cancelled' === $status && isset( $order->payment_status ) && 'paid' === $order->payment_status ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_refund_required', __( 'Refund the paid order before marking it cancelled.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$now          = current_time( 'mysql', true );
		$new_version  = $current_version + 1;
		$data         = array(
			'status'            => $status,
			'version'           => $new_version,
			'status_changed_at' => $now,
			'updated_at'        => $now,
		);
		$formats      = array( '%s', '%d', '%s', '%s' );
		if ( self::is_preorder_request( $order ) && 'confirmed' === $status ) {
			// A request becomes an operational order only after a staff acceptance.
			// The status-change event preserves that audit trail; the regular source
			// then lets normal KDS and POS flows process it without a parallel path.
			$data['order_source'] = 'web';
			$formats[]            = '%s';
		}

		if ( 'confirmed' === $status ) {
			$data['accepted_at']     = $now;
			$data['acknowledged_at'] = $now;
			$data['eta_minutes']     = $eta_minutes;
			$formats[]               = '%s';
			$formats[]               = '%s';
			$formats[]               = '%d';
			if ( $eta_minutes > 0 ) {
				$ready_from                       = strtotime( $now . ' UTC' ) + ( $eta_minutes * MINUTE_IN_SECONDS );
				$data['promised_ready_from_utc']  = gmdate( 'Y-m-d H:i:s', $ready_from );
				$data['promised_ready_by_utc']    = gmdate( 'Y-m-d H:i:s', $ready_from + ( 15 * MINUTE_IN_SECONDS ) );
				$data['timezone_snapshot']        = self::valid_timezone();
				$formats[]                         = '%s';
				$formats[]                         = '%s';
				$formats[]                         = '%s';
			}
		} elseif ( 'preparing' === $status ) {
			$data['cooking_started_at'] = $now;
			$formats[]                   = '%s';
		} elseif ( 'ready' === $status ) {
			$data['ready_at'] = $now;
			$formats[]        = '%s';
		} elseif ( 'completed' === $status ) {
			$data['completed_at'] = $now;
			$formats[]            = '%s';
		} elseif ( 'cancelled' === $status ) {
			$data['cancelled_at'] = $now;
			$formats[]            = '%s';
		}

		// Compare-and-set prevents two staff screens from both winning the same
		// transition. Zero affected rows is a conflict, never a success.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			self::orders_table(),
			$data,
			array(
				'id'      => $order_id,
				'status'  => $order->status,
				'version' => $current_version,
			),
			$formats,
			array( '%d', '%s', '%d' )
		);
		if ( false === $updated || 1 !== (int) $updated ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$code = false === $updated ? 'doughboss_transition_db' : 'doughboss_stale_order';
			$http = false === $updated ? 500 : 409;
			return new WP_Error( $code, __( 'This order could not be updated. Refresh and try again.', 'doughboss' ), array( 'status' => $http ) );
		}

		$event_ok = self::insert_event(
			array(
				'order_id'      => $order_id,
				'order_version' => $new_version,
				'event_type'    => 'status_changed',
				'from_status'   => $order->status,
				'to_status'     => $status,
				'actor_type'    => $actor_type ? $actor_type : 'staff',
				'actor_id'      => $actor_id,
				'reason_code'   => $reason_code,
				'event_key'     => $event_key,
				'occurred_at'   => $now,
			)
		);
		if ( ! $event_ok || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_transition_db', __( 'The order update could not be recorded safely.', 'doughboss' ), array( 'status' => 500 ) );
		}

		foreach ( $data as $field => $value ) {
			$order->$field = $value;
		}
		if ( 'confirmed' === $status ) {
			do_action( 'doughboss_order_accepted', $order_id, $eta_minutes );
		}
		do_action( 'doughboss_order_status_changed', $order_id, $status );

		return self::transition_result( $order, false );
	}

	/**
	 * Persist one non-PII lifecycle event.
	 *
	 * @param array $event Event columns.
	 * @return bool
	 */
	private static function insert_event( array $event ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			self::events_table(),
			$event,
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		return false !== $inserted;
	}

	/**
	 * Fetch recent lifecycle events for a staff-only surface.
	 *
	 * @param int $order_id Order ID.
	 * @param int $limit    Maximum events.
	 * @return array[]
	 */
	public static function events( $order_id, $limit = 100 ) {
		global $wpdb;
		$table = self::events_table();
		$limit = max( 1, min( 200, absint( $limit ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY order_version ASC LIMIT %d", absint( $order_id ), $limit ),
			ARRAY_A
		);
		return $rows ? $rows : array();
	}

	/**
	 * Build the REST-safe result returned after a transition or replay.
	 *
	 * @param object $order    Updated order row.
	 * @param bool   $replayed Whether an event-key replay was detected.
	 * @return array
	 */
	private static function transition_result( $order, $replayed ) {
		$customer = self::customer_projection( $order );
		$timing   = self::timing_projection( $order );
		return array(
			'success'                   => true,
			'replayed'                  => (bool) $replayed,
			'id'                        => (int) $order->id,
			'status'                    => $order->status,
			'status_label'              => self::status_label_for( $order ),
			'version'                   => isset( $order->version ) ? (int) $order->version : 1,
			'allowed_next_statuses'     => self::available_transitions( $order ),
			'customer_status'           => $customer['status'],
			'customer_status_label'     => $customer['label'],
			'timing_status'             => $timing['status'],
			'timing_label'              => $timing['label'],
			'promised_ready_from_utc'   => self::utc_iso( isset( $order->promised_ready_from_utc ) ? $order->promised_ready_from_utc : '' ),
			'promised_ready_by_utc'     => self::utc_iso( isset( $order->promised_ready_by_utc ) ? $order->promised_ready_by_utc : '' ),
			'timezone'                  => ! empty( $order->timezone_snapshot ) ? $order->timezone_snapshot : self::valid_timezone(),
		);
	}

	/**
	 * Return the site's timezone only when it is an IANA identifier.
	 *
	 * @return string
	 */
	private static function valid_timezone() {
		$timezone = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '';
		if ( '' === $timezone ) {
			return '';
		}
		try {
			new DateTimeZone( $timezone );
			return $timezone;
		} catch ( Throwable $e ) {
			return '';
		}
	}

	/**
	 * Generate an event key for backwards-compatible internal callers.
	 *
	 * New REST clients supply their own key so network retries are idempotent.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status   Target status.
	 * @return string
	 */
	private static function legacy_event_key( $order_id, $status ) {
		return 'legacy:' . absint( $order_id ) . ':' . sanitize_key( $status ) . ':' . md5( uniqid( '', true ) );
	}

	/**
	 * Fetch a single order row (without items).
	 *
	 * @param int $order_id Order ID.
	 * @return object|null
	 */
	public static function get( $order_id ) {
		global $wpdb;
		$table = self::orders_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $order_id ) );
	}

	/**
	 * Fetch an order by its order number.
	 *
	 * @param string $number Order number.
	 * @return object|null
	 */
	public static function get_by_number( $number ) {
		global $wpdb;
		$table = self::orders_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_number = %s", $number ) );
	}

	/**
	 * Whether a Stripe PaymentIntent has already been recorded against an order.
	 *
	 * Used to stop a single succeeded payment being replayed across multiple
	 * checkouts (one paid PaymentIntent → at most one order).
	 *
	 * @param string $payment_intent_id Stripe PaymentIntent id.
	 * @return bool
	 */
	public static function payment_intent_used( $payment_intent_id ) {
		return 0 !== self::find_id_by_payment_intent( $payment_intent_id );
	}

	/**
	 * Resolve an order from its durable checkout replay key.
	 *
	 * @param string $checkout_key Server-bound SHA-256 key.
	 * @param bool   $locking      Use a current locking read inside a transaction.
	 * @return int Order ID or 0.
	 */
	public static function find_id_by_checkout_key( $checkout_key, $locking = false ) {
		global $wpdb;
		$checkout_key = strtolower( sanitize_text_field( $checkout_key ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $checkout_key ) ) {
			return 0;
		}
		$table = self::orders_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$sql = "SELECT id FROM {$table} WHERE checkout_key = %s LIMIT 1" . ( $locking ? ' FOR UPDATE' : '' );
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $checkout_key ) );
	}

	/**
	 * Resolve the order that owns a canonical payment reference.
	 *
	 * @param string $payment_intent_id Canonical provider reference.
	 * @param bool   $locking           Use a current locking read inside a transaction.
	 * @return int Order ID or 0.
	 */
	public static function find_id_by_payment_intent( $payment_intent_id, $locking = false ) {
		global $wpdb;
		$payment_intent_id = sanitize_text_field( $payment_intent_id );
		if ( '' === $payment_intent_id ) {
			return 0;
		}
		$table = self::orders_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$sql = "SELECT id FROM {$table} WHERE payment_intent_id = %s LIMIT 1" . ( $locking ? ' FOR UPDATE' : '' );
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $payment_intent_id ) );
	}

	/**
	 * Fetch the items belonging to an order.
	 *
	 * @param int $order_id Order ID.
	 * @return array[]
	 */
	public static function get_items( $order_id ) {
		global $wpdb;
		$table = self::items_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", $order_id ), ARRAY_A );

		foreach ( $rows as &$row ) {
			$row['toppings'] = $row['toppings'] ? json_decode( $row['toppings'], true ) : array();
		}
		unset( $row );

		return $rows ? $rows : array();
	}

	/**
	 * Fetch the items for many orders in one query, grouped by order ID.
	 *
	 * Batch counterpart to get_items() — same row shape per item — used by the
	 * live board and admin list to avoid one query per order.
	 *
	 * @param int[] $order_ids Order IDs.
	 * @return array<int,array[]> Map of order_id => item rows (missing = no items).
	 */
	public static function get_items_for_orders( array $order_ids ) {
		global $wpdb;
		$order_ids = array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) );
		if ( empty( $order_ids ) ) {
			return array();
		}

		$table        = self::items_table();
		$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id IN ({$placeholders}) ORDER BY id ASC", $order_ids ),
			ARRAY_A
		);

		$grouped = array();
		foreach ( (array) $rows as $row ) {
			$row['toppings']                     = $row['toppings'] ? json_decode( $row['toppings'], true ) : array();
			$grouped[ (int) $row['order_id'] ][] = $row;
		}

		return $grouped;
	}

	/**
	 * Update the status of an order.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status   New status (must be a known status).
	 * @return bool
	 */
	public static function update_status( $order_id, $status ) {
		$order = self::get( $order_id );
		if ( ! $order ) {
			return false;
		}

		$result = self::transition(
			$order_id,
			$status,
			array(
				'expected_version' => isset( $order->version ) ? (int) $order->version : 1,
				'event_key'       => self::legacy_event_key( $order_id, $status ),
				'actor_type'      => 'staff',
				'actor_id'        => get_current_user_id(),
				'reason_code'     => 'cancelled' === $status ? 'staff_cancelled' : '',
			)
		);

		return ! is_wp_error( $result );
	}

	/**
	 * Update the payment status of an order (e.g. after an admin refund).
	 *
	 * @param int    $order_id       Order ID.
	 * @param string $payment_status New payment status (must be a known value).
	 * @return bool
	 */
	public static function update_payment_status( $order_id, $payment_status ) {
		global $wpdb;

		if ( ! in_array( $payment_status, array( 'unpaid', 'paid', 'refunded' ), true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			self::orders_table(),
			array(
				'payment_status' => $payment_status,
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => $order_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Query orders for the admin list.
	 *
	 * @param array $args { Optional. Query arguments.
	 *     @type string $status      Filter by status.
	 *     @type string $search      Search order number / name / email.
	 *     @type int    $location_id Filter by shop/location ID (0 = all shops).
	 *     @type int    $per_page    Results per page.
	 *     @type int    $page        Current page (1-based).
	 *     @type string $orderby     Column to order by.
	 *     @type string $order       ASC or DESC.
	 * }
	 * @return array{items:object[],total:int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = self::orders_table();

		$args = wp_parse_args(
			$args,
			array(
				'status'      => '',
				'search'      => '',
				'location_id' => 0,
				'per_page'    => 20,
				'page'        => 1,
				'orderby'     => 'created_at',
				'order'       => 'DESC',
			)
		);

		$where  = 'WHERE 1=1';
		$params = array();

		if ( $args['status'] && array_key_exists( $args['status'], self::statuses() ) ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}

		if ( (int) $args['location_id'] > 0 ) {
			$where   .= ' AND location_id = %d';
			$params[] = (int) $args['location_id'];
		}

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where   .= ' AND ( order_number LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		// Whitelist orderby to avoid SQL injection via column names.
		$allowed_orderby = array( 'created_at', 'total', 'status', 'id' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		// Total count.
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		// Page of results.
		$query    = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$all_args = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$items = $wpdb->get_results( $wpdb->prepare( $query, $all_args ) );

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Build a public-safe representation of an order for the customer.
	 *
	 * @param object $order Order row.
	 * @return array
	 */
	public static function public_view( $order ) {
		$customer = self::customer_projection( $order );
		$timing   = self::timing_projection( $order );
		return array(
			'order_number' => $order->order_number,
			'status'       => $order->status,
			'status_label' => self::status_label_for( $order ),
			'customer_status'       => $customer['status'],
			'customer_status_label' => $customer['label'],
			'order_type'   => $order->order_type,
			'table_id'     => isset( $order->table_id ) ? (int) $order->table_id : 0,
			'table_label'  => isset( $order->table_label ) ? $order->table_label : '',
			'order_source' => isset( $order->order_source ) ? $order->order_source : 'web',
			'total'        => (float) $order->total,
			'discount'     => isset( $order->discount ) ? (float) $order->discount : 0,
			'voucher_code' => isset( $order->voucher_code ) ? $order->voucher_code : '',
			'currency'     => $order->currency,
			'payment_status' => isset( $order->payment_status ) ? $order->payment_status : 'unpaid',
			'eta_minutes'  => isset( $order->eta_minutes ) ? (int) $order->eta_minutes : 0,
			'promised_ready_from_utc' => self::utc_iso( isset( $order->promised_ready_from_utc ) ? $order->promised_ready_from_utc : '' ),
			'promised_ready_by_utc'   => self::utc_iso( isset( $order->promised_ready_by_utc ) ? $order->promised_ready_by_utc : '' ),
			'timezone'      => ! empty( $order->timezone_snapshot ) ? $order->timezone_snapshot : self::valid_timezone(),
			'timing_status' => $timing['status'],
			'timing_label'  => $timing['label'],
			'ready_at'      => self::utc_iso( isset( $order->ready_at ) ? $order->ready_at : '' ),
			'created_at'   => $order->created_at,
			'items'        => self::get_items( $order->id ),
		);
	}
}
