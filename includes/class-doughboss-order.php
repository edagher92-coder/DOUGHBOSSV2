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
	 * Generate a unique, human-friendly order number.
	 *
	 * @return string
	 */
	private static function generate_order_number() {
		global $wpdb;
		$table = self::orders_table();

		do {
			$number = 'DB-' . gmdate( 'ymd' ) . '-' . strtoupper( wp_generate_password( 4, false, false ) );
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
	 * @return int|WP_Error New order ID or error.
	 */
	public static function create( array $data, array $lines ) {
		global $wpdb;

		if ( empty( $lines ) ) {
			return new WP_Error( 'doughboss_empty', __( 'Cannot create an empty order.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$now    = current_time( 'mysql', true );
		$number = self::generate_order_number();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			self::orders_table(),
			array(
				'order_number'  => $number,
				'status'        => 'pending',
				'order_type'    => $data['order_type'],
				'customer_name' => $data['customer_name'],
				'customer_email'=> $data['customer_email'],
				'customer_phone'=> $data['customer_phone'],
				'address'       => $data['address'],
				'notes'         => $data['notes'],
				'subtotal'      => $data['subtotal'],
				'tax'           => $data['tax'],
				'delivery_fee'  => $data['delivery_fee'],
				'total'         => $data['total'],
				'currency'      => DoughBoss_Settings::get( 'currency_code', 'USD' ),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'doughboss_db_error', __( 'Could not save your order. Please try again.', 'doughboss' ), array( 'status' => 500 ) );
		}

		$order_id = (int) $wpdb->insert_id;

		foreach ( $lines as $line ) {
			$toppings = isset( $line['toppings'] ) ? wp_json_encode( array_values( $line['toppings'] ) ) : '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				self::items_table(),
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
		}

		/**
		 * Fires after an order has been created and all items stored.
		 *
		 * @param int   $order_id The new order ID.
		 * @param array $data     The order data.
		 */
		do_action( 'doughboss_order_created', $order_id, $data );

		return $order_id;
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
	 * Update the status of an order.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status   New status (must be a known status).
	 * @return bool
	 */
	public static function update_status( $order_id, $status ) {
		global $wpdb;

		if ( ! array_key_exists( $status, self::statuses() ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			self::orders_table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $order_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false !== $updated ) {
			do_action( 'doughboss_order_status_changed', $order_id, $status );
			return true;
		}
		return false;
	}

	/**
	 * Query orders for the admin list.
	 *
	 * @param array $args { Optional. Query arguments.
	 *     @type string $status  Filter by status.
	 *     @type string $search  Search order number / name / email.
	 *     @type int    $per_page Results per page.
	 *     @type int    $page    Current page (1-based).
	 *     @type string $orderby Column to order by.
	 *     @type string $order   ASC or DESC.
	 * }
	 * @return array{items:object[],total:int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = self::orders_table();

		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'search'   => '',
				'per_page' => 20,
				'page'     => 1,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			)
		);

		$where  = 'WHERE 1=1';
		$params = array();

		if ( $args['status'] && array_key_exists( $args['status'], self::statuses() ) ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
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
		$statuses = self::statuses();
		return array(
			'order_number' => $order->order_number,
			'status'       => $order->status,
			'status_label' => isset( $statuses[ $order->status ] ) ? $statuses[ $order->status ] : $order->status,
			'order_type'   => $order->order_type,
			'total'        => (float) $order->total,
			'currency'     => $order->currency,
			'created_at'   => $order->created_at,
			'items'        => self::get_items( $order->id ),
		);
	}
}
