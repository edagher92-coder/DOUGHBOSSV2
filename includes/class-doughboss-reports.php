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
	 * Current payment-attempt states updated in a period.
	 *
	 * Orders deliberately retain only paid / unpaid / refunded because a failed
	 * card attempt must never create an order. The durable payment-attempt table
	 * is therefore the source of truth for failed, unknown and mismatched card
	 * attempts shown to managers.
	 *
	 * @param string $from        Start date (Y-m-d) or datetime.
	 * @param string $to          End date (Y-m-d) or datetime.
	 * @param int    $location_id Location ID (0 = all shops).
	 * @return array{available:bool,statuses:array<string,int>}
	 */
	public static function payment_attempt_statuses( $from, $to, $location_id = 0 ) {
		if ( ! class_exists( 'DoughBoss_Activator' ) || ! DoughBoss_Activator::payment_storage_ready() ) {
			return array(
				'available' => false,
				'statuses'  => array(),
			);
		}

		global $wpdb;
		$table = DoughBoss_Payment_Attempts::table();
		list( $start, $end ) = self::bounds( $from, $to );
		$where  = "purpose = 'order' AND updated_at BETWEEN %s AND %s";
		$params = array( $start, $end );
		if ( (int) $location_id > 0 ) {
			$where    .= ' AND location_id = %d';
			$params[] = (int) $location_id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- plugin-owned table; all values use placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS attempts FROM {$table} WHERE {$where} GROUP BY status",
				$params
			)
		);

		$statuses = array();
		foreach ( (array) $rows as $row ) {
			$statuses[ (string) $row->status ] = (int) $row->attempts;
		}

		return array(
			'available' => true,
			'statuses'  => $statuses,
		);
	}

	/**
	 * Average active cooking duration for orders made ready in a period.
	 *
	 * A timing figure is returned only when both timestamps were actually
	 * written by the kitchen workflow. This avoids presenting an ETA, an order
	 * age, or a guessed prep time as a measured kitchen result.
	 *
	 * @param string $from        Start date (Y-m-d) or datetime.
	 * @param string $to          End date (Y-m-d) or datetime.
	 * @param int    $location_id Location ID (0 = all shops).
	 * @return array{available:bool,samples:int,average_minutes:float}
	 */
	public static function kitchen_timing( $from, $to, $location_id = 0 ) {
		if ( ! class_exists( 'DoughBoss_Activator' ) || ! DoughBoss_Activator::lifecycle_storage_ready() ) {
			return array(
				'available'       => false,
				'samples'         => 0,
				'average_minutes' => 0.0,
			);
		}

		global $wpdb;
		$table = self::orders_table();
		list( $start, $end ) = self::bounds( $from, $to );
		$where  = "status != 'cancelled' AND cooking_started_at IS NOT NULL AND ready_at IS NOT NULL AND ready_at BETWEEN %s AND %s";
		$params = array( $start, $end );
		if ( (int) $location_id > 0 ) {
			$where    .= ' AND location_id = %d';
			$params[] = (int) $location_id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- plugin-owned table; all values use placeholders.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS samples, COALESCE( AVG( TIMESTAMPDIFF( SECOND, cooking_started_at, ready_at ) ), 0 ) AS average_seconds FROM {$table} WHERE {$where}",
				$params
			)
		);

		return array(
			'available'       => true,
			'samples'         => $row ? (int) $row->samples : 0,
			'average_minutes' => $row ? max( 0.0, (float) $row->average_seconds / 60 ) : 0.0,
		);
	}

	/**
	 * Active catering work, grouped by its real lifecycle status.
	 *
	 * @return array{available:bool,statuses:array<string,int>}
	 */
	public static function catering_pipeline() {
		global $wpdb;
		$table = DoughBoss_Catering::table();

		// A partial/manual plugin upgrade should show an honest unavailable state
		// instead of turning an absent table into a misleading zero-enquiry count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is derived from the site prefix.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table !== $exists ) {
			return array(
				'available' => false,
				'statuses'  => array(),
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, no external values.
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS enquiries FROM {$table} WHERE status NOT IN ( 'fulfilled', 'lost' ) GROUP BY status"
		);
		$statuses = array();
		foreach ( (array) $rows as $row ) {
			$statuses[ (string) $row->status ] = (int) $row->enquiries;
		}

		return array(
			'available' => true,
			'statuses'  => $statuses,
		);
	}

	/**
	 * Read the local POSPal mirror state without calling POSPal.
	 *
	 * This intentionally reports configuration and the durable local outbox,
	 * rather than claiming that a remote till is online from a dashboard load.
	 *
	 * @return array{state:string,stores:int,queued:int,terminal:int,retrying:int,ambiguous:int}
	 */
	public static function pospal_sync_snapshot() {
		if ( ! DoughBoss_Settings::pospal_enabled() ) {
			return array( 'state' => 'not_configured', 'stores' => 0, 'queued' => 0, 'terminal' => 0, 'retrying' => 0, 'ambiguous' => 0 );
		}

		$stores = DoughBoss_Settings::pospal_stores();
		if ( empty( $stores ) ) {
			return array( 'state' => 'not_configured', 'stores' => 0, 'queued' => 0, 'terminal' => 0, 'retrying' => 0, 'ambiguous' => 0 );
		}
		if ( ! DoughBoss_Settings::pospal_push_enabled() ) {
			return array( 'state' => 'push_disabled', 'stores' => count( $stores ), 'queued' => 0, 'terminal' => 0, 'retrying' => 0, 'ambiguous' => 0 );
		}
		if ( ! class_exists( 'DoughBoss_Activator' ) || ! DoughBoss_Activator::pospal_outbox_storage_ready() ) {
			return array( 'state' => 'outbox_unavailable', 'stores' => count( $stores ), 'queued' => 0, 'terminal' => 0, 'retrying' => 0, 'ambiguous' => 0 );
		}

		global $wpdb;
		$table = DoughBoss_POSPal_Outbox::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, no external values.
		$queued = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ( 'pending', 'in_flight' )" );
		$alerts = DoughBoss_POSPal_Outbox::counts_for_alert();

		return array(
			'state'     => ( (int) $alerts['terminal'] > 0 || (int) $alerts['retrying'] > 0 ) ? 'attention' : 'monitoring',
			'stores'    => count( $stores ),
			'queued'    => $queued,
			'terminal'  => (int) $alerts['terminal'],
			'retrying'  => (int) $alerts['retrying'],
			'ambiguous' => (int) $alerts['ambiguous'],
		);
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
	 * Order count and revenue split by order type (pickup / delivery / dine-in).
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
				"SELECT order_number, created_at, order_type, order_source, table_label, status, location_id, customer_name, customer_email, subtotal, tax, delivery_fee, discount, voucher_code, total, currency, payment_status FROM {$table} WHERE {$where} ORDER BY created_at ASC",
				$params
			)
		);

		return $rows ? $rows : array();
	}
}
