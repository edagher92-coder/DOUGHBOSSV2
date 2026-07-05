<?php
/**
 * Revenue reporting queries for the admin Reports page.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only aggregation over the orders / order-items tables.
 *
 * All methods take a Y-m-d date range (inclusive) and exclude cancelled
 * orders — every other status represents money taken (or committed), so it
 * counts toward revenue.
 */
class DoughBoss_Reports {

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
	 * Validate a Y-m-d date string, falling back when it is malformed.
	 *
	 * @param mixed  $value    Raw (already-unslashed) input.
	 * @param string $fallback Date to use when $value is not a valid Y-m-d.
	 * @return string
	 */
	public static function sanitize_date( $value, $fallback ) {
		$value = sanitize_text_field( (string) $value );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && false !== strtotime( $value ) ) {
			return $value;
		}
		return $fallback;
	}

	/**
	 * Expand a Y-m-d pair into inclusive datetime bounds matching the UTC
	 * created_at column.
	 *
	 * @param string $from Start date (Y-m-d).
	 * @param string $to   End date (Y-m-d).
	 * @return array{0:string,1:string} Start/end datetimes.
	 */
	private static function bounds( $from, $to ) {
		if ( strtotime( $from ) > strtotime( $to ) ) {
			list( $from, $to ) = array( $to, $from );
		}
		return array( $from . ' 00:00:00', $to . ' 23:59:59' );
	}

	/**
	 * Revenue, order count and average order value for a date range.
	 *
	 * @param string $from Start date (Y-m-d).
	 * @param string $to   End date (Y-m-d).
	 * @return array{revenue:float,orders:int,aov:float}
	 */
	public static function summary( $from, $to ) {
		global $wpdb;
		$table = self::orders_table();
		list( $start, $end ) = self::bounds( $from, $to );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS orders, COALESCE( SUM( total ), 0 ) AS revenue FROM {$table} WHERE status != 'cancelled' AND created_at BETWEEN %s AND %s",
				$start,
				$end
			)
		);

		$orders  = $row ? (int) $row->orders : 0;
		$revenue = $row ? (float) $row->revenue : 0.0;

		return array(
			'revenue' => $revenue,
			'orders'  => $orders,
			'aov'     => $orders > 0 ? $revenue / $orders : 0.0,
		);
	}

	/**
	 * Order count and revenue split by order type (pickup / delivery).
	 *
	 * @param string $from Start date (Y-m-d).
	 * @param string $to   End date (Y-m-d).
	 * @return array<string,array{orders:int,revenue:float}> Keyed by order_type.
	 */
	public static function order_type_mix( $from, $to ) {
		global $wpdb;
		$table = self::orders_table();
		list( $start, $end ) = self::bounds( $from, $to );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_type, COUNT(*) AS orders, COALESCE( SUM( total ), 0 ) AS revenue FROM {$table} WHERE status != 'cancelled' AND created_at BETWEEN %s AND %s GROUP BY order_type",
				$start,
				$end
			)
		);

		$mix = array();
		foreach ( (array) $rows as $row ) {
			$mix[ (string) $row->order_type ] = array(
				'orders'  => (int) $row->orders,
				'revenue' => (float) $row->revenue,
			);
		}

		return $mix;
	}

	/**
	 * Top-selling items by units sold within a date range.
	 *
	 * @param string $from  Start date (Y-m-d).
	 * @param string $to    End date (Y-m-d).
	 * @param int    $limit Maximum rows.
	 * @return array<int,array{name:string,quantity:int,revenue:float}>
	 */
	public static function top_items( $from, $to, $limit = 10 ) {
		global $wpdb;
		$orders = self::orders_table();
		$items  = self::items_table();
		$limit  = max( 1, min( 50, (int) $limit ) );
		list( $start, $end ) = self::bounds( $from, $to );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.name, SUM( i.quantity ) AS quantity, COALESCE( SUM( i.line_total ), 0 ) AS revenue
				FROM {$items} i
				INNER JOIN {$orders} o ON o.id = i.order_id
				WHERE o.status != 'cancelled' AND o.created_at BETWEEN %s AND %s
				GROUP BY i.name
				ORDER BY quantity DESC, revenue DESC
				LIMIT %d",
				$start,
				$end,
				$limit
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'name'     => (string) $row->name,
				'quantity' => (int) $row->quantity,
				'revenue'  => (float) $row->revenue,
			);
		}

		return $out;
	}

	/**
	 * Per-order rows for the CSV export, oldest first.
	 *
	 * @param string $from Start date (Y-m-d).
	 * @param string $to   End date (Y-m-d).
	 * @return object[]
	 */
	public static function orders_for_export( $from, $to ) {
		global $wpdb;
		$table = self::orders_table();
		list( $start, $end ) = self::bounds( $from, $to );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_number, created_at, order_type, status, customer_name, customer_email, subtotal, tax, delivery_fee, discount, voucher_code, total, currency, payment_status FROM {$table} WHERE status != 'cancelled' AND created_at BETWEEN %s AND %s ORDER BY created_at ASC",
				$start,
				$end
			)
		);

		return $rows ? $rows : array();
	}
}
