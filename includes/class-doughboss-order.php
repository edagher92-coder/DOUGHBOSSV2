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
	 * Characters used in the random part of an order number. No 0/O, 1/I so a
	 * number read over the phone or typed off a receipt is unambiguous.
	 */
	const NUMBER_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	const NUMBER_LENGTH   = 6;

	/**
	 * Valid order statuses mapped to human labels.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'pending'          => __( 'Pending', 'doughboss' ),
			'confirmed'        => __( 'Confirmed', 'doughboss' ),
			'preparing'        => __( 'Preparing', 'doughboss' ),
			'baking'           => __( 'In the Oven', 'doughboss' ),
			'ready'            => __( 'Ready for Pickup', 'doughboss' ),
			'out_for_delivery' => __( 'Out for Delivery', 'doughboss' ),
			'completed'        => __( 'Completed', 'doughboss' ),
			'cancelled'        => __( 'Cancelled', 'doughboss' ),
		);
	}

	/**
	 * Statuses that are "live" from the kitchen's point of view.
	 *
	 * @return string[]
	 */
	public static function active_statuses() {
		return array( 'pending', 'confirmed', 'preparing', 'baking', 'ready', 'out_for_delivery' );
	}

	/**
	 * Orders table name.
	 *
	 * @return string
	 */
	public static function orders_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_orders';
	}

	/**
	 * Order items table name.
	 *
	 * @return string
	 */
	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_order_items';
	}

	/**
	 * Generate a candidate order number: DB-<local ymd>-<6 unambiguous chars>.
	 *
	 * The date uses the site's timezone so numbers sort into the shop's trading
	 * days (2.0 used UTC, so an 8am Sydney order carried yesterday's date).
	 * Uniqueness is enforced by the UNIQUE KEY on insert, with a retry — there
	 * is no check-then-insert race any more.
	 *
	 * @return string
	 */
	public static function generate_order_number() {
		$alphabet = self::NUMBER_ALPHABET;
		$max      = strlen( $alphabet ) - 1;
		$suffix   = '';
		for ( $i = 0; $i < self::NUMBER_LENGTH; $i++ ) {
			$suffix .= $alphabet[ wp_rand( 0, $max ) ];
		}
		return 'DB-' . wp_date( 'ymd' ) . '-' . $suffix;
	}

	/**
	 * Whether the last $wpdb error was a duplicate-key violation.
	 *
	 * @return bool
	 */
	private static function last_error_is_duplicate() {
		global $wpdb;
		return false !== stripos( (string) $wpdb->last_error, 'Duplicate entry' );
	}

	/**
	 * Create an order from validated data and cart lines.
	 *
	 * The order row and every item row are written inside one transaction and
	 * every insert is checked, so a partial order (a priced order with some of
	 * its food missing) can no longer be committed. Requires InnoDB, which has
	 * been the MySQL default since 5.5.
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

		$idempotency_key = isset( $data['idempotency_key'] ) && '' !== $data['idempotency_key']
			? substr( (string) $data['idempotency_key'], 0, 64 )
			: null;

		// A retried checkout (flaky connection, double tap across tabs) must return
		// the order that already exists rather than creating a second one.
		if ( $idempotency_key ) {
			$existing = self::get_by_idempotency_key( $idempotency_key );
			if ( $existing ) {
				return (int) $existing->id;
			}
		}

		$now      = current_time( 'mysql', true );
		$order_id = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'START TRANSACTION' );

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$number = self::generate_order_number();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$inserted = $wpdb->insert(
				self::orders_table(),
				array(
					'order_number'    => $number,
					'idempotency_key' => $idempotency_key,
					'status'          => 'pending',
					'order_type'      => $data['order_type'],
					'customer_name'   => $data['customer_name'],
					'customer_email'  => $data['customer_email'],
					'customer_phone'  => $data['customer_phone'],
					'address'         => $data['address'],
					'notes'           => $data['notes'],
					'subtotal'        => $data['subtotal'],
					'tax'             => $data['tax'],
					'tax_rate'        => isset( $data['tax_rate'] ) ? $data['tax_rate'] : 0,
					'tax_inclusive'   => ! empty( $data['tax_inclusive'] ) ? 1 : 0,
					'delivery_fee'    => $data['delivery_fee'],
					'total'           => $data['total'],
					'currency'        => DoughBoss_Settings::get( 'currency_code', 'AUD' ),
					'created_at'      => $now,
					'updated_at'      => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%d', '%f', '%f', '%s', '%s', '%s' )
			);

			if ( false !== $inserted ) {
				$order_id = (int) $wpdb->insert_id;
				break;
			}

			if ( ! self::last_error_is_duplicate() ) {
				break; // A real error, not a number collision.
			}

			// Duplicate key: if it was the idempotency key, another request won.
			if ( $idempotency_key ) {
				$existing = self::get_by_idempotency_key( $idempotency_key );
				if ( $existing ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->query( 'ROLLBACK' );
					return (int) $existing->id;
				}
			}
			// Otherwise it was an order-number collision: loop and try a new one.
		}

		if ( ! $order_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'doughboss_db_error', __( 'Could not save your order. Please try again.', 'doughboss' ), array( 'status' => 500 ) );
		}

		// Items: one multi-row INSERT, checked.
		$placeholders = array();
		$values       = array();
		foreach ( $lines as $line ) {
			$placeholders[] = '(%d, %d, %s, %s, %s, %d, %f, %f)';
			$values[]       = $order_id;
			$values[]       = (int) $line['item_id'];
			$values[]       = (string) $line['name'];
			$values[]       = isset( $line['size'] ) ? (string) $line['size'] : '';
			$values[]       = isset( $line['toppings'] ) ? wp_json_encode( array_values( $line['toppings'] ) ) : '';
			$values[]       = (int) $line['quantity'];
			$values[]       = (float) $line['unit_price'];
			$values[]       = (float) $line['line_total'];
		}

		$items_table = self::items_table();
		$sql         = "INSERT INTO {$items_table} (order_id, item_id, name, size, toppings, quantity, unit_price, line_total) VALUES " . implode( ', ', $placeholders );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$items_ok = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		if ( false === $items_ok ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'doughboss_db_error', __( 'Could not save your order. Please try again.', 'doughboss' ), array( 'status' => 500 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'COMMIT' );

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
	 * Fetch an order by the client's idempotency key.
	 *
	 * @param string $key Idempotency key.
	 * @return object|null
	 */
	public static function get_by_idempotency_key( $key ) {
		global $wpdb;
		$table = self::orders_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $key ) );
	}

	/**
	 * Decode a raw items row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function decode_item( array $row ) {
		$row['toppings'] = $row['toppings'] ? json_decode( $row['toppings'], true ) : array();
		if ( ! is_array( $row['toppings'] ) ) {
			$row['toppings'] = array();
		}
		return $row;
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
		return $rows ? array_map( array( __CLASS__, 'decode_item' ), $rows ) : array();
	}

	/**
	 * Fetch items for many orders in ONE query, grouped by order id. The admin
	 * list used to run one query per row (20+ per page load).
	 *
	 * @param int[] $order_ids Order IDs.
	 * @return array<int,array[]> order_id => items.
	 */
	public static function get_items_for_orders( array $order_ids ) {
		global $wpdb;
		$order_ids = array_values( array_filter( array_map( 'absint', $order_ids ) ) );
		if ( empty( $order_ids ) ) {
			return array();
		}
		$table        = self::items_table();
		$placeholders = implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id IN ({$placeholders}) ORDER BY order_id, id", $order_ids ), ARRAY_A );

		$grouped = array_fill_keys( $order_ids, array() );
		foreach ( (array) $rows as $row ) {
			$grouped[ (int) $row['order_id'] ][] = self::decode_item( $row );
		}
		return $grouped;
	}

	/**
	 * Update the status of an order.
	 *
	 * Returns false for an unknown status OR an unknown order. 2.0 returned
	 * true for a non-existent order because $wpdb->update() returns 0 (not
	 * false) when no row matches, and fired the status-changed action for a
	 * phantom order.
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

		$order = self::get( $order_id );
		if ( ! $order ) {
			return false;
		}
		if ( $order->status === $status ) {
			return true; // No-op, but not a failure.
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			self::orders_table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $order_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		/**
		 * Fires after an order's status genuinely changed.
		 *
		 * @param int    $order_id   Order ID.
		 * @param string $status     New status.
		 * @param string $old_status Previous status.
		 */
		do_action( 'doughboss_order_status_changed', (int) $order_id, $status, $order->status );
		return true;
	}

	/**
	 * Record the outcome of the confirmation email so a failed send is visible
	 * in the admin list instead of vanishing.
	 *
	 * @param int    $order_id Order ID.
	 * @param bool   $sent     Whether wp_mail reported success.
	 * @param string $error    Error text when it failed.
	 * @return void
	 */
	public static function mark_email_result( $order_id, $sent, $error = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::orders_table(),
			array(
				'email_sent'  => $sent ? 1 : 0,
				'email_error' => $sent ? null : substr( (string) $error, 0, 1000 ),
			),
			array( 'id' => (int) $order_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Count orders created after a timestamp (drives the new-order badge).
	 *
	 * @param string $since_gmt MySQL datetime (UTC).
	 * @return int
	 */
	public static function count_since( $since_gmt ) {
		global $wpdb;
		$table = self::orders_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at > %s", $since_gmt ) );
	}

	/**
	 * Count orders by status (for the admin views bar).
	 *
	 * @return array<string,int>
	 */
	public static function counts_by_status() {
		global $wpdb;
		$table = self::orders_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * Query orders for the admin list.
	 *
	 * @param array $args { Optional. Query arguments.
	 *     @type string $status   Filter by status, or 'active' for every live status.
	 *     @type string $search   Search order number / name / email / phone.
	 *     @type int    $per_page Results per page.
	 *     @type int    $page     Current page (1-based).
	 *     @type string $orderby  Column to order by.
	 *     @type string $order    ASC or DESC.
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

		if ( 'active' === $args['status'] ) {
			$active       = self::active_statuses();
			$placeholders = implode( ', ', array_fill( 0, count( $active ), '%s' ) );
			$where       .= " AND status IN ({$placeholders})";
			$params       = array_merge( $params, $active );
		} elseif ( $args['status'] && array_key_exists( $args['status'], self::statuses() ) ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}

		$search = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			// The overwhelmingly common search is an order number pasted from a
			// customer's phone, or an email address. Both have unique/indexed
			// columns, so use equality instead of a leading-wildcard LIKE that
			// would scan the whole table.
			if ( preg_match( '/^DB-\d{6}-[A-Z0-9]{4,8}$/i', $search ) ) {
				$where   .= ' AND order_number = %s';
				$params[] = strtoupper( $search );
			} elseif ( is_email( $search ) ) {
				$where   .= ' AND customer_email = %s';
				$params[] = strtolower( $search );
			} else {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$where   .= ' AND ( order_number LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s )';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}
		}

		// Whitelist orderby to avoid SQL injection via column names.
		$allowed_orderby = array( 'created_at', 'total', 'status', 'id', 'order_number' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		// Total count.
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		// Page of results.
		$query    = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
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
	 * Deliberately excludes name, email, phone, address and notes.
	 *
	 * @param object $order Order row.
	 * @return array
	 */
	public static function public_view( $order ) {
		$statuses = self::statuses();
		$items    = array();
		foreach ( self::get_items( $order->id ) as $item ) {
			$items[] = array(
				'name'       => $item['name'],
				'quantity'   => (int) $item['quantity'],
				'size'       => $item['size'],
				'toppings'   => $item['toppings'],
				'line_total' => (float) $item['line_total'],
			);
		}
		return array(
			'order_number' => $order->order_number,
			'status'       => $order->status,
			'status_label' => isset( $statuses[ $order->status ] ) ? $statuses[ $order->status ] : $order->status,
			'order_type'   => $order->order_type,
			'total'        => (float) $order->total,
			'currency'     => $order->currency,
			'created_at'   => $order->created_at,
			'items'        => $items,
		);
	}
}
