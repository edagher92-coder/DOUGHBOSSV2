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
	 * created_at column. Full "Y-m-d H:i:s" datetimes (e.g. from
	 * today_bounds()) pass through unchanged.
	 *
	 * @param string $from Start date (Y-m-d) or datetime.
	 * @param string $to   End date (Y-m-d) or datetime.
	 * @return array{0:string,1:string} Start/end datetimes.
	 */
	private static function bounds( $from, $to ) {
		if ( strtotime( $from ) > strtotime( $to ) ) {
			list( $from, $to ) = array( $to, $from );
		}
		$start = strlen( $from ) > 10 ? $from : $from . ' 00:00:00';
		$end   = strlen( $to ) > 10 ? $to : $to . ' 23:59:59';
		return array( $start, $end );
	}

	/**
	 * UTC datetime bounds for "today" in the site's timezone, so the Today
	 * summary rolls over at local midnight rather than at the UTC boundary.
	 *
	 * @return array{0:string,1:string} Start/end datetimes (UTC).
	 */
	public static function today_bounds() {
		try {
			$tz = new DateTimeZone( wp_timezone_string() );
		} catch ( Exception $e ) {
			$tz = new DateTimeZone( 'UTC' );
		}
		$utc   = new DateTimeZone( 'UTC' );
		$start = new DateTimeImmutable( 'today', $tz );
		$end   = $start->modify( '+1 day' )->modify( '-1 second' );
		return array(
			$start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Shared WHERE fragment: non-cancelled orders in range, optionally pinned
	 * to one shop/location. Callers append the returned SQL after their own
	 * "WHERE " and merge the params into their prepare() args.
	 *
	 * @param string $start       Start datetime (UTC).
	 * @param string $end         End datetime (UTC).
	 * @param int    $location_id Location ID (0 = all shops).
	 * @param string $prefix      Optional column prefix, e.g. 'o.'.
	 * @return array{0:string,1:array} SQL fragment and its params.
	 */
	private static function scope_where( $start, $end, $location_id = 0, $prefix = '' ) {
		$sql    = "{$prefix}status != 'cancelled' AND {$prefix}created_at BETWEEN %s AND %s";
		$params = array( $start, $end );
		if ( (int) $location_id > 0 ) {
			$sql     .= " AND {$prefix}location_id = %d";
			$params[] = (int) $location_id;
		}
		return array( $sql, $params );
	}

	/**
	 * Revenue, order count and average order value for a date range.
	 *
	 * "revenue" is GROSS sales — every non-cancelled order's total, whether or
	 * not the money has actually been collected. "paid_revenue" counts only
	 * orders whose card payment is verified (payment_status = 'paid');
	 * refunded and unpaid/pay-in-store orders are excluded from it.
	 *
	 * @param string $from        Start date (Y-m-d) or datetime.
	 * @param string $to          End date (Y-m-d) or datetime.
	 * @param int    $location_id Location ID (0 = all shops).
	 * @return array{revenue:float,orders:int,aov:float,paid_revenue:float,paid_orders:int}
	 */
	public static function summary( $from, $to, $location_id = 0 ) {
		global $wpdb;
		$table = self::orders_table();
		list( $start, $end ) = self::bounds( $from, $to );
		list( $where, $params ) = self::scope_where( $start, $end, $location_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is plugin-owned; WHERE fragment is built from placeholders only.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS orders,
					COALESCE( SUM( total ), 0 ) AS revenue,
					COALESCE( SUM( CASE WHEN payment_status = 'paid' THEN total ELSE 0 END ), 0 ) AS paid_revenue,
					COALESCE( SUM( CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END ), 0 ) AS paid_orders
				FROM {$table} WHERE {$where}",
				$params
			)
		);

		$orders  = $row ? (int) $row->orders : 0;
		$revenue = $row ? (float) $row->revenue : 0.0;

		return array(
			'revenue'      => $revenue,
			'orders'       => $orders,
			'aov'          => $orders > 0 ? $revenue / $orders : 0.0,
			'paid_revenue' => $row ? (float) $row->paid_revenue : 0.0,
			'paid_orders'  => $row ? (int) $row->paid_orders : 0,
		);
	}

	/**
	 * Order count and gross revenue split by payment status (paid / unpaid /
	 * refunded), so collected card money is never conflated with money still
	 * to be taken at the counter.
	 *
	 * @param string $from        Start date (Y-m-d) or datetime.
	 * @param string $to          End date (Y-m-d) or datetime.
	 * @param int    $location_id Location ID (0 = all shops).
	 * @return array<string,array{orders:int,revenue:float}> Keyed by payment_status.
	 */
	public static function payment_mix( $from, $to, $location_id = 0 ) {
		global $wpdb;
		$table = self::orders_table();
		list( $start, $end ) = self::bounds( $from, $to );
		list( $where, $params ) = self::scope_where( $start, $end, $location_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is plugin-owned; WHERE fragment is built from placeholders only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT payment_status, COUNT(*) AS orders, COALESCE( SUM( total ), 0 ) AS revenue FROM {$table} WHERE {$where} GROUP BY payment_status",
				$params
			)
		);

		$mix = array();
		foreach ( (array) $rows as $row ) {
			$mix[ (string) $row->payment_status ] = array(
				'orders'  => (int) $row->orders,
				'revenue' => (float) $row->revenue,
			);
		}

		return $mix;
	}

	/**
	 * Per-shop order count, gross revenue and collected (paid) revenue.
	 *
	 * @param string $from Start date (Y-m-d) or datetime.
	 * @param string $to   End date (Y-m-d) or datetime.
	 * @return array<int,array{location_id:int,orders:int,revenue:float,paid_revenue:float}>
	 */
	public static function location_breakdown( $from, $to ) {
		global $wpdb;
		$table = self::orders_table();
		list( $start, $end ) = self::bounds( $from, $to );
		list( $where, $params ) = self::scope_where( $start, $end );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is plugin-owned; WHERE fragment is built from placeholders only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT location_id, COUNT(*) AS orders,
					COALESCE( SUM( total ), 0 ) AS revenue,
					COALESCE( SUM( CASE WHEN payment_status = 'paid' THEN total ELSE 0 END ), 0 ) AS paid_revenue
				FROM {$table} WHERE {$where}
				GROUP BY location_id
				ORDER BY revenue DESC",
				$params
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'location_id'  => (int) $row->location_id,
				'orders'       => (int) $row->orders,
				'revenue'      => (float) $row->revenue,
				'paid_revenue' => (float) $row->paid_revenue,
			);
		}

		return $out;
	}

	/**
	 * Order count and revenue split by order type (pickup / delivery).
	 *
	 * @param string $from        Start date (Y-m-d) or datetime.
	 * @param string $to          End date (Y-m-d) or datetime.
	 * @param int    $location_id Location ID (0 = all shops).
	 * @return array<string,array{orders:int,revenue:float}> Keyed by order_type.
	 */
	public static function order_type_mix( $from, $to, $location_id = 0 ) {
		global $wpdb;
		$table = self::orders_table();
		list( $start, $end ) = self::bounds( $from, $to );
		list( $where, $params ) = self::scope_where( $start, $end, $location_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is plugin-owned; WHERE fragment is built from placeholders only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_type, COUNT(*) AS orders, COALESCE( SUM( total ), 0 ) AS revenue FROM {$table} WHERE {$where} GROUP BY order_type",
				$params
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
	 * @param string $from        Start date (Y-m-d) or datetime.
	 * @param string $to          End date (Y-m-d) or datetime.
	 * @param int    $limit       Maximum rows.
	 * @param int    $location_id Location ID (0 = all shops).
	 * @return array<int,array{name:string,quantity:int,revenue:float}>
	 */
	public static function top_items( $from, $to, $limit = 10, $location_id = 0 ) {
		global $wpdb;
		$orders = self::orders_table();
		$items  = self::items_table();
		$limit  = max( 1, min( 50, (int) $limit ) );
		list( $start, $end ) = self::bounds( $from, $to );
		list( $where, $params ) = self::scope_where( $start, $end, $location_id, 'o.' );
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table names are plugin-owned; WHERE fragment is built from placeholders only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.name, SUM( i.quantity ) AS quantity, COALESCE( SUM( i.line_total ), 0 ) AS revenue
				FROM {$items} i
				INNER JOIN {$orders} o ON o.id = i.order_id
				WHERE {$where}
				GROUP BY i.name
				ORDER BY quantity DESC, revenue DESC
				LIMIT %d",
				$params
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
	 * @param string $from        Start date (Y-m-d) or datetime.
	 * @param string $to          End date (Y-m-d) or datetime.
	 * @param int    $location_id Location ID (0 = all shops).
	 * @return object[]
	 */
	public static function orders_for_export( $from, $to, $location_id = 0 ) {
		global $wpdb;
		$table = self::orders_table();
		list( $start, $end ) = self::bounds( $from, $to );
		list( $where, $params ) = self::scope_where( $start, $end, $location_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is plugin-owned; WHERE fragment is built from placeholders only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_number, created_at, order_type, status, location_id, customer_name, customer_email, subtotal, tax, delivery_fee, discount, voucher_code, total, currency, payment_status FROM {$table} WHERE {$where} ORDER BY created_at ASC",
				$params
			)
		);

		return $rows ? $rows : array();
	}
}
