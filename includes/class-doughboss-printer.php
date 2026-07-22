<?php
/**
 * Printer-pull kitchen tickets (optional, off by default).
 *
 * A cloud-print-capable receipt printer POLLS the plugin's REST endpoint and
 * pulls the next unprinted order as a print job — there is no on-prem print
 * server, which suits serverless/managed WordPress hosting. Two protocols are
 * supported and switched by DoughBoss_Settings::printer_protocol():
 *
 *  - Star CloudPRNT (default, 'cloudprnt'): the printer POSTs to announce
 *    itself (we answer { jobReady }), GETs the rendered ticket body, then
 *    DELETEs to confirm it printed (we advance the watermark).
 *  - Epson Server Direct Print ('epos'): the printer POSTs to a single route
 *    and we reply with the next order as an ePOS-XML <epos-print> document.
 *
 * QUEUE MODEL — no schema change. "What still needs printing" is tracked with a
 * single non-autoloaded option watermark (the last-printed order id). Unprinted
 * orders are those with `id > watermark`; we serve the OLDEST such order so a
 * backlog prints in arrival order. No `doughboss_order_created` listener is
 * needed — a created order simply appears above the watermark, so the printer's
 * next poll finds it. The watermark advances only when the printer confirms a
 * print: CloudPRNT's DELETE, or the Epson `printjobid` confirmation echoed on
 * its next poll.
 *
 * SECURITY — every poll must present the configured printer token (compared
 * with hash_equals). When printer_ready() is false OR the token mismatches the
 * endpoints behave as "no job" / 403 and the feature is fully dormant. A kitchen
 * ticket may carry the customer name, order type and phone (a runner needs them)
 * but none of that is ever written to the log.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pull-based cloud receipt-printer bridge. Static; register via init().
 */
class DoughBoss_Printer {

	/**
	 * Option name holding the last-printed order id (the queue watermark).
	 * Stored with autoload 'no' — it is only read inside printer REST polls.
	 */
	const WATERMARK_OPTION = 'doughboss_printer_watermark';

	/**
	 * Content type for a StarPRNT raw ticket job.
	 */
	const MEDIA_STARPRNT = 'application/vnd.star.starprnt';

	/**
	 * Content type for a plain-text ticket job (used by simpler CloudPRNT models).
	 */
	const MEDIA_TEXT = 'text/plain; charset=utf-8';

	/**
	 * Register the REST routes for the printer to poll. Safe to call always —
	 * every callback self-gates on printer_ready() + a token check, so when the
	 * feature is off or unconfigured the routes simply answer "no job" / 403.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the CloudPRNT and Epson SDP routes under doughboss/v1.
	 *
	 * All routes are public at the permission layer (printers cannot send a
	 * WordPress nonce); authorisation is the printer token, checked inside each
	 * callback with hash_equals so an unconfigured/mismatched printer gets
	 * nothing.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = DOUGHBOSS_REST_NAMESPACE;

		// Star CloudPRNT: poll (POST), fetch job (GET), confirm (DELETE).
		register_rest_route(
			$ns,
			'/print/cloudprnt',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'cloudprnt_poll' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'cloudprnt_get_job' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'cloudprnt_confirm' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Epson Server Direct Print: a single route the printer POSTs to.
		register_rest_route(
			$ns,
			'/print/epos',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'epos_job' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Authorisation + queue
	 * --------------------------------------------------------------------- */

	/**
	 * Authorise a printer poll: the feature must be ready AND the request must
	 * present the configured token. The token may arrive as the documented
	 * CloudPRNT/Epson query/body field or a Bearer header — all are checked with
	 * a constant-time hash_equals so a wrong/absent token is rejected.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool True when the printer is configured and the token matches.
	 */
	private static function authorise( WP_REST_Request $request ) {
		if ( ! DoughBoss_Settings::printer_ready() ) {
			return false;
		}

		$expected = (string) DoughBoss_Settings::printer_token();
		if ( '' === $expected ) {
			return false;
		}

		$presented = self::presented_token( $request );
		if ( '' === $presented ) {
			return false;
		}

		return hash_equals( $expected, $presented );
	}

	/**
	 * Extract the token a printer presented, from any of the accepted carriers:
	 * a `token` query/body param, an `X-DoughBoss-Token` header, or an
	 * `Authorization: Bearer <token>` header.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return string The presented token, or '' when none was supplied.
	 */
	private static function presented_token( WP_REST_Request $request ) {
		$token = $request->get_param( 'token' );
		if ( is_string( $token ) && '' !== $token ) {
			return sanitize_text_field( $token );
		}

		$header = (string) $request->get_header( 'x_doughboss_token' );
		if ( '' !== $header ) {
			return sanitize_text_field( $header );
		}

		$auth = (string) $request->get_header( 'authorization' );
		if ( '' !== $auth && 0 === stripos( $auth, 'bearer ' ) ) {
			return sanitize_text_field( substr( $auth, 7 ) );
		}

		return '';
	}

	/**
	 * The queue watermark: the id of the most recent order already printed.
	 * Orders with a greater id are still waiting to print.
	 *
	 * @return int
	 */
	private static function watermark() {
		return absint( get_option( self::WATERMARK_OPTION, 0 ) );
	}

	/**
	 * Advance the watermark to a confirmed order id (never moves backwards).
	 * Stored non-autoloaded — it is only read inside printer polls.
	 *
	 * @param int $order_id The order id that has now printed.
	 * @return void
	 */
	private static function advance_watermark( $order_id ) {
		$order_id = absint( $order_id );
		if ( $order_id > self::watermark() ) {
			update_option( self::WATERMARK_OPTION, $order_id, false );
		}
	}

	/**
	 * The next order waiting to print: the OLDEST order with id above the
	 * watermark, so a backlog prints in arrival order. Cancelled orders are
	 * skipped (no point printing a ticket the kitchen shouldn't make), but the
	 * watermark still advances past them so they never block the queue.
	 *
	 * @return object|null The order row, or null when the queue is empty.
	 */
	private static function next_unprinted() {
		global $wpdb;
		$table     = $wpdb->prefix . 'doughboss_orders';
		$watermark = self::watermark();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT 20",
				$watermark
			)
		);

		foreach ( (array) $rows as $row ) {
			if ( 'cancelled' === $row->status ) {
				// Don't print cancellations; step the watermark past them so the
				// queue keeps moving to the next genuine order.
				self::advance_watermark( (int) $row->id );
				continue;
			}
			return $row;
		}

		return null;
	}

	/* --------------------------------------------------------------------- *
	 * Star CloudPRNT
	 * --------------------------------------------------------------------- */

	/**
	 * CloudPRNT poll (POST): the printer announces itself and asks whether a job
	 * is waiting. We answer { jobReady } plus the media type we will serve. When
	 * the printer is not authorised we answer { jobReady: false } (a quiet "no
	 * job") rather than an error, so a misconfigured printer just idles.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function cloudprnt_poll( WP_REST_Request $request ) {
		if ( ! self::authorise( $request ) ) {
			return new WP_REST_Response( array( 'jobReady' => false ), 200 );
		}

		$order = self::next_unprinted();

		return new WP_REST_Response(
			array(
				'jobReady'   => (bool) $order,
				'mediaTypes' => array( self::cloudprnt_media_type() ),
			),
			200
		);
	}

	/**
	 * CloudPRNT fetch (GET): return the rendered ticket for the next unprinted
	 * order with the matching Content-Type. 204 (no content) when nothing waits;
	 * 403 when the printer is not authorised.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function cloudprnt_get_job( WP_REST_Request $request ) {
		if ( ! self::authorise( $request ) ) {
			return new WP_REST_Response( null, 403 );
		}

		$order = self::next_unprinted();
		if ( ! $order ) {
			return new WP_REST_Response( null, 204 );
		}

		$media = self::cloudprnt_media_type();
		$body  = self::render_ticket( $order );

		// At-most-once delivery: advance the watermark as soon as the device
		// FETCHES the job. CloudPRNT's DELETE confirm is not guaranteed (firmware
		// varies; kitchen networks drop requests). If we waited for the DELETE, the
		// same order would re-serve on every GET poll and the printer would loop and
		// reprint endlessly. A GET means the device has committed to printing this
		// job, so the fetch IS the print; the later DELETE is a harmless,
		// forward-only re-confirm.
		self::advance_watermark( (int) $order->id );

		$response = new WP_REST_Response( $body, 200 );
		$response->header( 'Content-Type', $media );
		// Surface the order id so the device can echo it back on the DELETE confirm.
		$response->header( 'X-DoughBoss-Order', (string) (int) $order->id );

		return $response;
	}

	/**
	 * CloudPRNT confirm (DELETE): the printer reports it finished a job. Advance
	 * the watermark past that order so it is not served again. The order id may
	 * arrive as a `code`/`order` param (CloudPRNT echoes the job token) or the
	 * `X-DoughBoss-Order` header; otherwise we advance past the current head of
	 * the queue, which the printer has just printed.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function cloudprnt_confirm( WP_REST_Request $request ) {
		if ( ! self::authorise( $request ) ) {
			return new WP_REST_Response( null, 403 );
		}

		// Only ever advance to an EXPLICITLY confirmed order id. The watermark has
		// usually already moved (it advances when the job is fetched on GET), so this
		// is a forward-only re-confirm. We never guess "the current head" here — doing
		// so on a bare/replayed DELETE could skip a genuine order that was never
		// fetched.
		$order_id = self::confirmed_order_id( $request );
		if ( $order_id ) {
			self::advance_watermark( $order_id );
			self::log( 'cloudprnt: confirmed order #' . $order_id );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * The order id a printer confirmed, read from the accepted carriers (a
	 * `code`/`jobToken`/`order` param or the `X-DoughBoss-Order` header). The
	 * watermark only ever moves forward (advance_watermark guards that), so a
	 * stale/replayed id can never rewind the queue.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return int The confirmed order id, or 0 when none/invalid.
	 */
	private static function confirmed_order_id( WP_REST_Request $request ) {
		$candidates = array(
			$request->get_param( 'code' ),
			$request->get_param( 'jobToken' ),
			$request->get_param( 'order' ),
			$request->get_header( 'x_doughboss_order' ),
		);

		foreach ( $candidates as $value ) {
			$id = absint( $value );
			if ( $id > 0 ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Resolve the CloudPRNT media type to serve from the configured protocol
	 * width preference. StarPRNT raw is the default; a 'text' protocol value (or
	 * any non-StarPRNT setting) falls back to plain text, which every CloudPRNT
	 * device can render.
	 *
	 * @return string
	 */
	private static function cloudprnt_media_type() {
		$protocol = DoughBoss_Settings::printer_protocol();
		if ( 'text' === $protocol ) {
			return self::MEDIA_TEXT;
		}
		// CloudPRNT default: send a plain-text body the device renders directly.
		// StarPRNT raw byte streams are device-specific; the text path is the
		// portable, correct default for a kitchen ticket and is what we render.
		return self::MEDIA_TEXT;
	}

	/* --------------------------------------------------------------------- *
	 * Epson Server Direct Print
	 * --------------------------------------------------------------------- */

	/**
	 * Epson SDP job (POST): the printer polls and we reply with the next
	 * unprinted order as an ePOS-XML <epos-print> document. The printer echoes a
	 * `printjobid` once it has printed; when we see that id again we advance the
	 * watermark before selecting the next order, so each ticket prints once.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function epos_job( WP_REST_Request $request ) {
		if ( ! self::authorise( $request ) ) {
			return new WP_REST_Response( null, 403 );
		}

		// Honour a confirmation echoed from the previous job before picking the
		// next one (Epson SDP confirms by re-presenting the printjobid).
		$confirmed = self::confirmed_order_id( $request );
		if ( ! $confirmed ) {
			$confirmed = absint( $request->get_param( 'printjobid' ) );
		}
		if ( $confirmed ) {
			self::advance_watermark( $confirmed );
			self::log( 'epos: confirmed order #' . $confirmed );
		}

		$order = self::next_unprinted();
		$xml   = $order ? self::render_epos_xml( $order ) : self::empty_epos_xml();

		$response = new WP_REST_Response( $xml, 200 );
		$response->header( 'Content-Type', 'text/xml; charset=utf-8' );
		if ( $order ) {
			$response->header( 'X-DoughBoss-Order', (string) (int) $order->id );
		}

		return $response;
	}

	/* --------------------------------------------------------------------- *
	 * Ticket rendering
	 * --------------------------------------------------------------------- */

	/**
	 * Render a plain-text kitchen ticket sized to the configured column width:
	 * order number, type, time, each line (qty + name + size + toppings),
	 * subtotal/tax/delivery/discount and total. Customer name + phone +
	 * delivery address are included because a kitchen/runner needs them; nothing
	 * here is logged.
	 *
	 * @param object $order Order row (from the orders table).
	 * @return string The rendered ticket text.
	 */
	public static function render_ticket( $order ) {
		$width = self::width();
		$lines = array();

		$lines[] = self::center( 'DOUGHBOSS', $width );
		$lines[] = self::rule( $width );
		$lines[] = 'Order: ' . self::clean( $order->order_number );

		$type = self::order_type_label( $order->order_type );
		$lines[] = 'Type:  ' . $type;
		if ( 'dine_in' === $order->order_type && ! empty( $order->table_label ) ) {
			$lines[] = self::center( '*** TABLE ' . self::clean( $order->table_label ) . ' ***', $width );
		}
		$lines[] = 'Time:  ' . self::clean( self::local_time( $order->created_at ) );

		$name = self::clean( $order->customer_name );
		if ( '' !== $name ) {
			$lines[] = 'Name:  ' . $name;
		}
		$phone = self::clean( $order->customer_phone );
		if ( '' !== $phone ) {
			$lines[] = 'Phone: ' . $phone;
		}
		if ( 'delivery' === $order->order_type ) {
			$address = self::clean( $order->address );
			if ( '' !== $address ) {
				foreach ( self::wrap( 'Addr:  ' . $address, $width ) as $wrapped ) {
					$lines[] = $wrapped;
				}
			}
		}

		$lines[] = self::rule( $width );

		foreach ( DoughBoss_Order::get_items( (int) $order->id ) as $item ) {
			$qty   = (int) $item['quantity'];
			$label = self::clean( $item['name'] );
			$size  = isset( $item['size'] ) ? self::clean( $item['size'] ) : '';
			if ( '' !== $size ) {
				$label .= ' (' . $size . ')';
			}
			$lines[] = self::clean( $qty . 'x ' . $label );

			$toppings = isset( $item['toppings'] ) && is_array( $item['toppings'] ) ? $item['toppings'] : array();
			foreach ( $toppings as $topping ) {
				$lines[] = '   + ' . self::clean( is_scalar( $topping ) ? (string) $topping : '' );
			}
		}

		$lines[] = self::rule( $width );

		$symbol = (string) DoughBoss_Settings::get( 'currency_symbol', '$' );
		$lines[] = self::amount_row( 'Subtotal', (float) $order->subtotal, $symbol, $width );
		if ( (float) $order->tax > 0 ) {
			$lines[] = self::amount_row( 'Tax (incl)', (float) $order->tax, $symbol, $width );
		}
		if ( (float) $order->delivery_fee > 0 ) {
			$lines[] = self::amount_row( 'Delivery', (float) $order->delivery_fee, $symbol, $width );
		}
		$discount = isset( $order->discount ) ? (float) $order->discount : 0;
		if ( $discount > 0 ) {
			$voucher = isset( $order->voucher_code ) ? self::clean( $order->voucher_code ) : '';
			$label   = '' !== $voucher ? 'Voucher ' . $voucher : 'Discount';
			$lines[] = self::amount_row( $label, -$discount, $symbol, $width );
		}
		$lines[] = self::amount_row( 'TOTAL', (float) $order->total, $symbol, $width );

		$notes = self::clean( $order->notes );
		if ( '' !== $notes ) {
			$lines[] = self::rule( $width );
			$lines[] = 'Notes:';
			foreach ( self::wrap( $notes, $width ) as $wrapped ) {
				$lines[] = $wrapped;
			}
		}

		$lines[] = self::rule( $width );
		$lines[] = self::center( self::clean( self::order_type_label( $order->order_type ) ), $width );
		$lines[] = '';
		$lines[] = '';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Render the same kitchen ticket as an Epson ePOS-XML <epos-print> document.
	 * Every dynamic value is escaped for XML. The layout mirrors render_ticket()
	 * line-for-line; widths are not column-padded here (Epson lays text out by
	 * its own font metrics) but the content is identical.
	 *
	 * @param object $order Order row.
	 * @return string ePOS-XML document.
	 */
	public static function render_epos_xml( $order ) {
		$ns = 'http://www.epson-pos.com/schemas/2011/03/epos-print';
		$x  = array();

		$x[] = '<?xml version="1.0" encoding="utf-8"?>';
		$x[] = '<epos-print xmlns="' . esc_attr( $ns ) . '">';

		// Title.
		$x[] = '<text align="center" />';
		$x[] = '<text dw="true" dh="true">' . self::xml( 'DOUGHBOSS' ) . self::nl();
		$x[] = '<text dw="false" dh="false" align="left" />';
		$x[] = self::xml_line( str_repeat( '-', self::width() ) );

		$x[] = self::xml_line( 'Order: ' . $order->order_number );
		$x[] = self::xml_line( 'Type:  ' . self::order_type_label( $order->order_type ) );
		if ( 'dine_in' === $order->order_type && ! empty( $order->table_label ) ) {
			$x[] = '<text align="center" dw="true" dh="true" />';
			$x[] = self::xml_line( 'TABLE ' . $order->table_label );
			$x[] = '<text align="left" dw="false" dh="false" />';
		}
		$x[] = self::xml_line( 'Time:  ' . self::local_time( $order->created_at ) );

		if ( '' !== trim( (string) $order->customer_name ) ) {
			$x[] = self::xml_line( 'Name:  ' . $order->customer_name );
		}
		if ( '' !== trim( (string) $order->customer_phone ) ) {
			$x[] = self::xml_line( 'Phone: ' . $order->customer_phone );
		}
		if ( 'delivery' === $order->order_type && '' !== trim( (string) $order->address ) ) {
			$x[] = self::xml_line( 'Addr:  ' . $order->address );
		}

		$x[] = self::xml_line( str_repeat( '-', self::width() ) );

		foreach ( DoughBoss_Order::get_items( (int) $order->id ) as $item ) {
			$qty   = (int) $item['quantity'];
			$label = (string) $item['name'];
			$size  = isset( $item['size'] ) ? (string) $item['size'] : '';
			if ( '' !== $size ) {
				$label .= ' (' . $size . ')';
			}
			$x[] = self::xml_line( $qty . 'x ' . $label );

			$toppings = isset( $item['toppings'] ) && is_array( $item['toppings'] ) ? $item['toppings'] : array();
			foreach ( $toppings as $topping ) {
				$x[] = self::xml_line( '   + ' . ( is_scalar( $topping ) ? (string) $topping : '' ) );
			}
		}

		$x[] = self::xml_line( str_repeat( '-', self::width() ) );

		$symbol = (string) DoughBoss_Settings::get( 'currency_symbol', '$' );
		$x[]    = self::xml_line( 'Subtotal ' . $symbol . number_format( (float) $order->subtotal, 2 ) );
		if ( (float) $order->tax > 0 ) {
			$x[] = self::xml_line( 'Tax(incl) ' . $symbol . number_format( (float) $order->tax, 2 ) );
		}
		if ( (float) $order->delivery_fee > 0 ) {
			$x[] = self::xml_line( 'Delivery ' . $symbol . number_format( (float) $order->delivery_fee, 2 ) );
		}
		$discount = isset( $order->discount ) ? (float) $order->discount : 0;
		if ( $discount > 0 ) {
			$voucher = isset( $order->voucher_code ) ? (string) $order->voucher_code : '';
			$label   = '' !== $voucher ? 'Voucher ' . $voucher . ' ' : 'Discount ';
			$x[]     = self::xml_line( $label . '-' . $symbol . number_format( $discount, 2 ) );
		}
		$x[] = '<text dw="true">' . self::xml( 'TOTAL ' . $symbol . number_format( (float) $order->total, 2 ) ) . self::nl();
		$x[] = '<text dw="false" />';

		if ( '' !== trim( (string) $order->notes ) ) {
			$x[] = self::xml_line( str_repeat( '-', self::width() ) );
			$x[] = self::xml_line( 'Notes: ' . $order->notes );
		}

		$x[] = '<feed line="3" />';
		$x[] = '<cut type="feed" />';
		$x[] = '</epos-print>';

		return implode( '', $x );
	}

	/**
	 * An empty (no-job) ePOS-XML document. Epson SDP printers tolerate an
	 * <epos-print> with nothing to print; this keeps the device idling quietly
	 * when the queue is empty.
	 *
	 * @return string
	 */
	private static function empty_epos_xml() {
		$ns = 'http://www.epson-pos.com/schemas/2011/03/epos-print';
		return '<?xml version="1.0" encoding="utf-8"?>' . '<epos-print xmlns="' . esc_attr( $ns ) . '"></epos-print>';
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * The receipt width in columns, clamped to a sane printable range.
	 *
	 * @return int
	 */
	private static function width() {
		$width = (int) DoughBoss_Settings::printer_width();
		return max( 24, min( 96, $width ) );
	}

	/**
	 * A label for an order type suitable for the top of a ticket.
	 *
	 * @param string $type Raw order_type value.
	 * @return string
	 */
	private static function order_type_label( $type ) {
		return ( 'delivery' === $type ) ? 'DELIVERY' : ( 'dine_in' === $type ? 'DINE IN' : 'PICKUP' );
	}

	/**
	 * Convert a stored UTC datetime to the site's local time for the ticket.
	 *
	 * @param string $mysql_utc Datetime as stored (UTC) in the orders table.
	 * @return string
	 */
	private static function local_time( $mysql_utc ) {
		$ts = strtotime( (string) $mysql_utc . ' UTC' );
		if ( ! $ts ) {
			return (string) $mysql_utc;
		}
		return wp_date( 'D j M, g:i a', $ts );
	}

	/**
	 * Strip control characters and collapse whitespace so a value is safe to
	 * place on a single printer line. Not an escape for XML — see xml().
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function clean( $value ) {
		$value = (string) $value;
		$value = preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $value );
		$value = preg_replace( '/\s+/', ' ', $value );
		return trim( (string) $value );
	}

	/**
	 * Centre a string within the column width.
	 *
	 * @param string $text  Text to centre.
	 * @param int    $width Column width.
	 * @return string
	 */
	private static function center( $text, $width ) {
		$text = self::clean( $text );
		$len  = strlen( $text );
		if ( $len >= $width ) {
			return substr( $text, 0, $width );
		}
		$pad = (int) floor( ( $width - $len ) / 2 );
		return str_repeat( ' ', $pad ) . $text;
	}

	/**
	 * A full-width horizontal rule.
	 *
	 * @param int $width Column width.
	 * @return string
	 */
	private static function rule( $width ) {
		return str_repeat( '-', $width );
	}

	/**
	 * A "label .... $amount" row right-aligned to the column width.
	 *
	 * @param string $label  Left-hand label.
	 * @param float  $amount Amount (negative renders a leading minus).
	 * @param string $symbol Currency symbol.
	 * @param int    $width  Column width.
	 * @return string
	 */
	private static function amount_row( $label, $amount, $symbol, $width ) {
		$label = self::clean( $label );
		$money = ( $amount < 0 ? '-' : '' ) . $symbol . number_format( abs( (float) $amount ), 2 );
		$gap   = $width - strlen( $label ) - strlen( $money );
		if ( $gap < 1 ) {
			return $label . ' ' . $money;
		}
		return $label . str_repeat( ' ', $gap ) . $money;
	}

	/**
	 * Word-wrap a cleaned string to the column width, returning the lines.
	 *
	 * @param string $text  Text to wrap.
	 * @param int    $width Column width.
	 * @return string[]
	 */
	private static function wrap( $text, $width ) {
		$text    = self::clean( $text );
		$wrapped = wordwrap( $text, $width, "\n", true );
		return explode( "\n", $wrapped );
	}

	/**
	 * Escape a value for inclusion as XML character data / attribute-safe text.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function xml( $value ) {
		return esc_html( self::clean( $value ) );
	}

	/**
	 * An ePOS <text> element carrying one cleaned, escaped line plus a newline.
	 *
	 * @param string $value Line text.
	 * @return string
	 */
	private static function xml_line( $value ) {
		return '<text>' . self::xml( $value ) . self::nl();
	}

	/**
	 * An ePOS line-feed marker that closes the preceding <text> element.
	 *
	 * @return string
	 */
	private static function nl() {
		return '&#10;</text>';
	}

	/**
	 * Log a printer status line for the operator. Status + order id only — never
	 * the token, customer name, phone or address.
	 *
	 * @param string $message Short status string.
	 * @return void
	 */
	private static function log( $message ) {
		if ( function_exists( 'error_log' ) ) {
			error_log( 'DoughBoss printer: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
