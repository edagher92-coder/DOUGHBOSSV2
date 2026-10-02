<?php
/**
 * DoughBoss Growth offline-conversion export: the weekly Google Ads upload file for won catering leads.
 *
 * admin-post action doughboss_growth_export_offline_conversions (manager capability AND nonce, feature
 * server_conversions on). It builds a CSV of the catering enquiries that reached "quoted" or "paid" inside a date
 * window, for upload in Google Ads under offline conversions. Nothing is sent anywhere by this class: it only
 * produces a file for a person to review and upload.
 *
 * A row is written ONLY when all of these hold, so nothing the visitor did not consent to ever leaves the site:
 *  - the enquiry has an attribution record (WP-04) whose consent snapshot has advertising consent;
 *  - that record holds a Google click id (gclid) that passes a strict allow-list (letters, digits, underscore and
 *    hyphen, starting with a letter, digit or underscore). A click id that does not pass (this is what stops a planted
 *    spreadsheet formula) leaves the row out, as do rows that only have a gbraid or wbraid id;
 *  - core still has the enquiry, in AUD, with an enquiry number that is safe as an order id;
 *  - the event time is inside the window: quoted_at for "Catering quote sent", balance_paid_at for "Catering order won"
 *    (a 100 percent deposit also sets it). A lost enquiry is never exported as won.
 *
 * Time is written in UTC with an explicit offset ("2026-10-14 04:30:00+0000"). Core stores enquiry times in the site
 * timezone, so they are converted from it. The value is the enquiry total from core (quote_total) as AUD with two
 * decimals, left empty when the total is zero. The order id is the enquiry number, so a repeated upload of the same
 * lead is recognised as a duplicate by the platform. Every text cell is neutralised so a spreadsheet cannot run it
 * as a formula.
 *
 * Enquiry times and totals are read through core's own accessor DoughBoss_Catering::get(). The list of enquiry ids
 * comes from the companion's own attribution table, so only enquiries with a stored consent record are ever looked at.
 *
 * Entry file: classes only, no side effects at include time.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offline conversion CSV.
 */
final class DoughBoss_Growth_Offline_Export {

	/**
	 * admin-post action and nonce action (name frozen by the architecture).
	 */
	const ACTION = 'doughboss_growth_export_offline_conversions';

	/**
	 * Conversion action names (conversion-plan.md section 2, rows 6 and 7). [CONFIRM: they must match the names in
	 * the Google Ads account exactly.]
	 */
	const NAME_QUOTED = 'Catering quote sent';
	const NAME_WON    = 'Catering order won';

	/**
	 * Stage choices.
	 */
	const STAGES = array( 'both', 'quoted', 'paid' );

	/**
	 * Enquiry ids read per query, pages read at most, rows written at most.
	 */
	const PAGE      = 200;
	const MAX_PAGES = 100;
	const MAX_ROWS  = 5000;

	/**
	 * Default window when no dates are given, in days back from now.
	 */
	const DEFAULT_WINDOW_DAYS = 7;

	/**
	 * CSV header.
	 */
	const HEADER = array( 'Google Click ID', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency', 'Order ID' );

	/**
	 * Hook the admin-post handler. Re-checks the flag itself.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! DoughBoss_Growth_Settings::enabled( DoughBoss_Growth_Conversions::FEATURE ) ) {
			return;
		}
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_export' ) );
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Building the file                                                                            */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Build the export.
	 *
	 * @param string $stage both, quoted or paid.
	 * @param int    $from  Window start (UNIX time, inclusive).
	 * @param int    $to    Window end (UNIX time, inclusive).
	 * @return array|null array( csv => string, rows => int, skipped => array reason => count ), or null when the
	 *                    input is invalid or storage cannot be read (nothing is exported).
	 */
	public static function build( $stage, $from, $to ) {
		global $wpdb;
		if ( ! is_string( $stage ) || ! in_array( $stage, self::STAGES, true ) || ! is_int( $from ) || ! is_int( $to ) || $from > $to ) {
			return null;
		}
		if ( ! DoughBoss_Growth_Activator::storage_ready() ) {
			return null;
		}
		if ( ! class_exists( 'DoughBoss_Growth_Attribution', false ) && ! DoughBoss_Growth::load_module( 'attribution' ) ) {
			return null;
		}
		$table   = DoughBoss_Growth_Attribution::attribution_table();
		$rows    = array();
		$skipped = array();
		$last    = 0;
		$done    = false;
		for ( $page = 0; $page < self::MAX_PAGES && ! $done; $page++ ) {
			$ids = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT id, subject_id FROM {$table} WHERE subject_type = %s AND id > %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the WordPress prefix and a constant.
					'enquiry',
					$last,
					self::PAGE
				),
				ARRAY_A
			);
			if ( ! is_array( $ids ) || '' !== (string) $wpdb->last_error ) {
				return null;
			}
			if ( array() === $ids ) {
				$done = true;
				break;
			}
			foreach ( $ids as $entry ) {
				$last = (int) $entry['id'];
				self::collect( (int) $entry['subject_id'], $stage, $from, $to, $rows, $skipped );
				if ( count( $rows ) >= self::MAX_ROWS ) {
					$skipped['truncated'] = 1;
					$done                 = true;
					break;
				}
			}
		}
		if ( ! $done ) {
			$skipped['truncated'] = 1;
		}
		usort( $rows, array( __CLASS__, 'compare_rows' ) );

		$lines = array( self::csv_line( self::HEADER ) );
		foreach ( $rows as $row ) {
			$lines[] = self::csv_line( array( $row['gclid'], $row['name'], $row['time'], $row['value'], 'AUD', $row['order_id'] ) );
		}
		return array(
			'csv'     => implode( '', $lines ),
			'rows'    => count( $rows ),
			'skipped' => $skipped,
		);
	}

	/**
	 * Sort order: time, then enquiry number, then conversion name.
	 *
	 * @param array $a Row.
	 * @param array $b Row.
	 * @return int
	 */
	public static function compare_rows( $a, $b ) {
		if ( $a['ts'] !== $b['ts'] ) {
			return ( $a['ts'] < $b['ts'] ) ? -1 : 1;
		}
		$by_number = strcmp( $a['order_id'], $b['order_id'] );
		return ( 0 !== $by_number ) ? $by_number : strcmp( $a['name'], $b['name'] );
	}

	/**
	 * Add the rows of one enquiry (zero, one or two).
	 *
	 * @param int    $id      Enquiry id.
	 * @param string $stage   both, quoted or paid.
	 * @param int    $from    Window start.
	 * @param int    $to      Window end.
	 * @param array  $rows    Rows so far (by reference).
	 * @param array  $skipped Skip counts so far (by reference).
	 * @return void
	 */
	private static function collect( $id, $stage, $from, $to, array &$rows, array &$skipped ) {
		if ( $id < 1 ) {
			self::skip( $skipped, 'bad_subject' );
			return;
		}
		$stored = DoughBoss_Growth_Attribution::for_subject( 'enquiry', $id );
		if ( ! is_array( $stored ) ) {
			self::skip( $skipped, 'no_record' );
			return;
		}
		if ( true !== ( isset( $stored['consent']['advertising'] ) ? $stored['consent']['advertising'] : false ) ) {
			self::skip( $skipped, 'no_advertising_consent' );
			return;
		}
		$gclid = ( isset( $stored['attribution']['gclid'] ) && is_string( $stored['attribution']['gclid'] ) ) ? $stored['attribution']['gclid'] : '';
		if ( '' === $gclid ) {
			self::skip( $skipped, 'no_click_id' );
			return;
		}
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_][A-Za-z0-9_\-]{9,119}\z/', $gclid ) ) {
			self::skip( $skipped, 'click_id_not_accepted' );
			return;
		}
		if ( ! class_exists( 'DoughBoss_Catering' ) || ! is_callable( array( 'DoughBoss_Catering', 'get' ) ) ) {
			self::skip( $skipped, 'no_core_accessor' );
			return;
		}
		$enquiry = call_user_func( array( 'DoughBoss_Catering', 'get' ), $id );
		if ( ! is_array( $enquiry ) ) {
			self::skip( $skipped, 'no_enquiry' );
			return;
		}
		$number   = DoughBoss_Growth_Conversions::clean_ref( isset( $enquiry['enquiry_number'] ) ? $enquiry['enquiry_number'] : '' );
		$currency = ( isset( $enquiry['currency'] ) && is_string( $enquiry['currency'] ) ) ? strtoupper( trim( $enquiry['currency'] ) ) : '';
		$cents    = DoughBoss_Growth_Conversions::to_cents( isset( $enquiry['quote_total'] ) ? $enquiry['quote_total'] : null );
		if ( '' === $number ) {
			self::skip( $skipped, 'no_enquiry_number' );
			return;
		}
		if ( DoughBoss_Growth_Conversions::CURRENCY !== $currency ) {
			self::skip( $skipped, 'currency_not_aud' );
			return;
		}
		if ( null === $cents ) {
			self::skip( $skipped, 'no_valid_amount' );
			return;
		}
		$value = ( $cents > 0 ) ? sprintf( '%d.%02d', intdiv( $cents, 100 ), $cents % 100 ) : '';

		if ( 'both' === $stage || 'quoted' === $stage ) {
			$at = self::parse_site_time( isset( $enquiry['quoted_at'] ) ? $enquiry['quoted_at'] : '' );
			if ( null === $at ) {
				self::skip( $skipped, 'not_quoted' );
			} elseif ( $at >= $from && $at <= $to ) {
				$rows[] = self::row( $gclid, self::NAME_QUOTED, $at, $value, $number );
			} else {
				self::skip( $skipped, 'outside_window' );
			}
		}
		if ( 'both' === $stage || 'paid' === $stage ) {
			$status = ( isset( $enquiry['status'] ) && is_string( $enquiry['status'] ) ) ? $enquiry['status'] : '';
			$at     = self::parse_site_time( isset( $enquiry['balance_paid_at'] ) ? $enquiry['balance_paid_at'] : '' );
			if ( 'lost' === $status ) {
				self::skip( $skipped, 'lost' );
			} elseif ( null === $at ) {
				self::skip( $skipped, 'not_paid' );
			} elseif ( $at >= $from && $at <= $to ) {
				$rows[] = self::row( $gclid, self::NAME_WON, $at, $value, $number );
			} else {
				self::skip( $skipped, 'outside_window' );
			}
		}
	}

	/**
	 * One export row.
	 *
	 * @param string $gclid    Click id.
	 * @param string $name     Conversion name.
	 * @param int    $ts       Event time.
	 * @param string $value    Value with two decimals, or ''.
	 * @param string $order_id Enquiry number.
	 * @return array
	 */
	private static function row( $gclid, $name, $ts, $value, $order_id ) {
		return array(
			'gclid'    => $gclid,
			'name'     => $name,
			'ts'       => $ts,
			'time'     => gmdate( 'Y-m-d H:i:s', $ts ) . '+0000',
			'value'    => $value,
			'order_id' => $order_id,
		);
	}

	/**
	 * Count a skipped enquiry.
	 *
	 * @param array  $skipped Counts (by reference).
	 * @param string $reason  Reason code.
	 * @return void
	 */
	private static function skip( array &$skipped, $reason ) {
		$skipped[ $reason ] = isset( $skipped[ $reason ] ) ? $skipped[ $reason ] + 1 : 1;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Time                                                                                         */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * The site timezone, or null when WordPress cannot say (nothing is exported then).
	 *
	 * @return DateTimeZone|null
	 */
	private static function site_timezone() {
		if ( ! function_exists( 'wp_timezone' ) ) {
			return null;
		}
		$zone = wp_timezone();
		return ( $zone instanceof DateTimeZone ) ? $zone : null;
	}

	/**
	 * A core enquiry time (site timezone, "Y-m-d H:i:s") as a UNIX timestamp. Null for an empty, zero or malformed value.
	 *
	 * @param mixed $text Stored value.
	 * @return int|null
	 */
	public static function parse_site_time( $text ) {
		if ( ! is_string( $text ) || 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $text ) || 0 === strpos( $text, '0000-00-00' ) ) {
			return null;
		}
		$zone = self::site_timezone();
		if ( null === $zone ) {
			return null;
		}
		$time   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $text, $zone );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $time || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}
		return $time->getTimestamp();
	}

	/**
	 * A date "YYYY-MM-DD" in the site timezone as the UNIX time of its first or last second.
	 *
	 * @param mixed $text       Date text.
	 * @param bool  $end_of_day True for 23:59:59, false for 00:00:00.
	 * @return int|null
	 */
	public static function parse_date( $text, $end_of_day ) {
		if ( ! is_string( $text ) || 1 !== preg_match( '/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $text, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		$zone = self::site_timezone();
		if ( null === $zone ) {
			return null;
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d', $text, $zone );
		if ( false === $time ) {
			return null;
		}
		return $time->getTimestamp() + ( $end_of_day ? 86399 : 0 );
	}

	/* ------------------------------------------------------------------------------------------ */
	/* CSV                                                                                          */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Neutralise a CSV cell so a spreadsheet cannot run it as a formula: a cell that starts with = + - @ or a tab or
	 * carriage return gets a leading apostrophe.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$text = ( null === $value ) ? '' : (string) $value;
		if ( '' !== $text && 1 === preg_match( '/^[=+\-@\t\r]/', $text ) ) {
			return "'" . $text;
		}
		return $text;
	}

	/**
	 * One CSV line (RFC 4180 quoting, CRLF end), every cell neutralised first.
	 *
	 * @param array $cells Cells.
	 * @return string
	 */
	public static function csv_line( array $cells ) {
		$out = array();
		foreach ( $cells as $cell ) {
			$text = self::csv_cell( $cell );
			if ( 1 === preg_match( '/[",\r\n]/', $text ) ) {
				$text = '"' . str_replace( '"', '""', $text ) . '"';
			}
			$out[] = $text;
		}
		return implode( ',', $out ) . "\r\n";
	}

	/* ------------------------------------------------------------------------------------------ */
	/* admin-post handler and form                                                                  */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * One posted field, unslashed and trimmed ('' when absent or not text).
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private static function posted( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked by check_admin_referer() in handle_export() before this is called.
		if ( ! isset( $_POST[ $name ] ) || ! is_string( $_POST[ $name ] ) ) {
			return '';
		}
		return trim( sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Download handler. Capability AND nonce first, then the feature flag; a bad date or a storage problem downloads
	 * nothing.
	 *
	 * @return void
	 */
	public static function handle_export() {
		if ( ! DoughBoss_Growth_Admin::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to export this file.', 'doughboss-growth' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		if ( ! DoughBoss_Growth_Settings::enabled( DoughBoss_Growth_Conversions::FEATURE ) ) {
			wp_die( esc_html__( 'Server-side conversions are switched off.', 'doughboss-growth' ), '', array( 'response' => 403 ) );
		}
		$stage = sanitize_key( self::posted( 'stage' ) );
		if ( '' === $stage ) {
			$stage = 'both';
		}
		if ( ! in_array( $stage, self::STAGES, true ) ) {
			wp_die( esc_html__( 'Choose quotes, paid, or both.', 'doughboss-growth' ), '', array( 'response' => 400 ) );
		}
		$now      = DoughBoss_Growth::now();
		$from_raw = self::posted( 'from' );
		$to_raw   = self::posted( 'to' );
		$from     = ( '' === $from_raw ) ? ( $now - ( self::DEFAULT_WINDOW_DAYS * DAY_IN_SECONDS ) ) : self::parse_date( $from_raw, false );
		$to       = ( '' === $to_raw ) ? $now : self::parse_date( $to_raw, true );
		if ( null === $from || null === $to || $from > $to ) {
			wp_die( esc_html__( 'Enter the dates as YYYY-MM-DD, with the start on or before the end.', 'doughboss-growth' ), '', array( 'response' => 400 ) );
		}
		$result = self::build( $stage, (int) $from, (int) $to );
		if ( null === $result ) {
			wp_die( esc_html__( 'The export could not be built. Nothing was downloaded.', 'doughboss-growth' ), '', array( 'response' => 500 ) );
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="doughboss-offline-conversions-' . gmdate( 'Ymd', $now ) . '.csv"' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		echo $result['csv']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download: click ids, numbers, fixed names; every cell is formula-neutralised.
		self::terminate();
	}

	/**
	 * Stop the request. Inside the test harness this returns instead, so a test can read the output.
	 *
	 * @return void
	 */
	public static function terminate() {
		if ( defined( 'DBGR_TESTING' ) ) {
			return;
		}
		exit;
	}

	/**
	 * The export form for the Conversions tab.
	 *
	 * @return void
	 */
	public static function render_form() {
		if ( ! DoughBoss_Growth_Admin::user_can_manage() ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Google Ads offline conversions (CSV)', 'doughboss-growth' ) . '</h3>';
		echo '<p>' . esc_html__( 'A file of catering enquiries that were quoted or paid, with the Google click id, for upload in Google Ads under offline conversions. Only enquiries whose visitor agreed to advertising measurement and who arrived with a Google click id are included. Enquiries with no click id, with only a gbraid or wbraid id, or that were lost are left out. The value is the enquiry total held in DoughBoss. Nothing is uploaded for you.', 'doughboss-growth' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::ACTION );
		echo '<p><label for="dbgr-oc-stage">' . esc_html__( 'Include', 'doughboss-growth' ) . '</label> <select id="dbgr-oc-stage" name="stage">';
		echo '<option value="both">' . esc_html__( 'Quotes sent and orders won', 'doughboss-growth' ) . '</option>';
		echo '<option value="quoted">' . esc_html__( 'Quotes sent only', 'doughboss-growth' ) . '</option>';
		echo '<option value="paid">' . esc_html__( 'Orders won (paid) only', 'doughboss-growth' ) . '</option>';
		echo '</select></p>';
		echo '<p><label for="dbgr-oc-from">' . esc_html__( 'From (YYYY-MM-DD, blank for the last seven days)', 'doughboss-growth' ) . '</label> <input type="text" id="dbgr-oc-from" name="from" value="" placeholder="2026-10-01" autocomplete="off" /></p>';
		echo '<p><label for="dbgr-oc-to">' . esc_html__( 'To (YYYY-MM-DD, blank for now)', 'doughboss-growth' ) . '</label> <input type="text" id="dbgr-oc-to" name="to" value="" placeholder="2026-10-08" autocomplete="off" /></p>';
		submit_button( __( 'Download CSV', 'doughboss-growth' ), 'secondary', 'submit', false );
		echo '</form>';
	}
}
