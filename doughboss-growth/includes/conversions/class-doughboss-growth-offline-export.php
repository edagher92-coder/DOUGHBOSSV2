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
 * Each page of that table is read WITH the stored attribution and consent (one query per page of 200, newest first), so
 * an enquiry without advertising consent or a Google click id costs no further query and core is never asked about it.
 *
 * A file that only looks complete is worse than no file: the operator uploads it and concludes nothing converted. So the
 * download is REFUSED (with a message saying why, nothing sent) when the build could not read what it needs: core's
 * lookup is gone or throws, core gave back nothing usable for any enquiry, or the file was cut short at the row or page
 * cap (the cap drops the oldest enquiries, and the operator is told to choose a shorter window). The same failures are
 * listed in DoughBoss_Growth_Failures. The ordinary reasons for leaving an enquiry out (no consent, no click id, outside
 * the window, lost) are counted in build()'s "skipped" and are never a refusal.
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
	 * @return array|null array( csv => string, rows => int, skipped => array reason => count, failed => array reason =>
	 *                    count, truncated => bool ), or null when the input is invalid or storage cannot be read (nothing is
	 *                    exported). "failed" holds the system failures (no_core_accessor, read_failed, core_unusable): when it
	 *                    is not empty, or the file is truncated, the file must not be handed over (see refusal_message()).
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
		$table     = DoughBoss_Growth_Attribution::attribution_table();
		$rows      = array();
		$skipped   = array();
		$meta      = array(
			'reached' => 0, // Enquiries core's lookup answered for.
			'usable'  => 0, // Of those, the ones with a number, AUD currency and a readable total.
		);
		$cursor    = PHP_INT_MAX; // Newest first: a cap drops the oldest enquiries, never the newest.
		$truncated = false;
		$done      = false;
		for ( $page = 0; $page < self::MAX_PAGES && ! $done; $page++ ) {
			$entries = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT id, subject_id, attribution_json, consent_json FROM {$table} WHERE subject_type = %s AND id < %d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the WordPress prefix and a constant.
					'enquiry',
					$cursor,
					self::PAGE
				),
				ARRAY_A
			);
			if ( ! is_array( $entries ) || '' !== (string) $wpdb->last_error ) {
				return null;
			}
			foreach ( $entries as $entry ) {
				$cursor = (int) $entry['id'];
				self::collect( $entry, $stage, $from, $to, $rows, $skipped, $meta );
				if ( count( $rows ) >= self::MAX_ROWS ) {
					$skipped['truncated'] = 1;
					$truncated            = true;
					$done                 = true;
					break;
				}
			}
			if ( count( $entries ) < self::PAGE ) {
				$done = true; // A short page is the last page.
			}
		}
		if ( ! $done ) {
			$skipped['truncated'] = 1;
			$truncated            = true;
		}

		// System failures: what the operator must be told about instead of being handed a file that looks complete.
		$failed = array();
		foreach ( array( 'no_core_accessor', 'read_failed' ) as $reason ) {
			if ( ! empty( $skipped[ $reason ] ) ) {
				$failed[ $reason ] = (int) $skipped[ $reason ];
			}
		}
		if ( $meta['reached'] > 0 && 0 === $meta['usable'] ) {
			$failed['core_unusable'] = $meta['reached']; // Core answered, but never with a usable enquiry: its row shape has changed.
		}
		foreach ( $failed as $reason => $count ) {
			self::note_failure( 'offline_export_' . $reason, array( 'count' => $count ) );
		}

		usort( $rows, array( __CLASS__, 'compare_rows' ) );

		$lines = array( self::csv_line( self::HEADER ) );
		foreach ( $rows as $row ) {
			$lines[] = self::csv_line( array( $row['gclid'], $row['name'], $row['time'], $row['value'], 'AUD', $row['order_id'] ) );
		}
		return array(
			'csv'       => implode( '', $lines ),
			'rows'      => count( $rows ),
			'skipped'   => $skipped,
			'failed'    => $failed,
			'truncated' => $truncated,
		);
	}

	/**
	 * Why a built export must NOT be downloaded, in words for the operator, or '' when it is safe to hand over. A result
	 * with a system failure (core's lookup gone, failing, or never returning a usable enquiry) is refused even when other
	 * rows were written: a file that quietly misses enquiries looks complete. So is a result cut short at the cap.
	 *
	 * @param array $result Result of build().
	 * @return string Message (plain text; escape it on output), or ''.
	 */
	public static function refusal_message( array $result ) {
		$failed = ( isset( $result['failed'] ) && is_array( $result['failed'] ) ) ? $result['failed'] : array();
		if ( array() !== $failed ) {
			$parts = array();
			foreach ( $failed as $reason => $count ) {
				$count = max( 1, (int) $count );
				if ( 'no_core_accessor' === $reason ) {
					/* translators: %d: number of enquiries. */
					$parts[] = sprintf( _n( 'DoughBoss no longer offers the enquiry lookup this export uses (%d enquiry affected)', 'DoughBoss no longer offers the enquiry lookup this export uses (%d enquiries affected)', $count, 'doughboss-growth' ), $count );
				} elseif ( 'read_failed' === $reason ) {
					/* translators: %d: number of enquiries. */
					$parts[] = sprintf( _n( '%d enquiry could not be read from DoughBoss', '%d enquiries could not be read from DoughBoss', $count, 'doughboss-growth' ), $count );
				} else {
					/* translators: %d: number of enquiries. */
					$parts[] = sprintf( _n( 'DoughBoss gave back no usable details for the %d enquiry checked', 'DoughBoss gave back no usable details for any of the %d enquiries checked', $count, 'doughboss-growth' ), $count );
				}
			}
			return __( 'The export was stopped because DoughBoss could not supply the enquiries it needs: ', 'doughboss-growth' ) . implode( '; ', $parts ) . '. ' . __( 'A file made now would look complete but would be missing enquiries. Nothing was downloaded. See Recent failures on the DoughBoss, Growth screen, then try again.', 'doughboss-growth' );
		}
		if ( ! empty( $result['truncated'] ) ) {
			return __( 'There are more enquiries than one file can safely hold, so a file made now would be cut short without any sign of it. Nothing was downloaded. Enter a shorter date range (the From and To boxes) and try again.', 'doughboss-growth' );
		}
		return '';
	}

	/**
	 * Sort order: time, then enquiry number, then conversion name, then the attribution row id (only ever decides between
	 * two enquiries that share a number and a time, so the file is the same whichever way the table was walked).
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
		if ( 0 !== $by_number ) {
			return $by_number;
		}
		$by_name = strcmp( $a['name'], $b['name'] );
		if ( 0 !== $by_name ) {
			return $by_name;
		}
		$seq_a = isset( $a['seq'] ) ? (int) $a['seq'] : 0;
		$seq_b = isset( $b['seq'] ) ? (int) $b['seq'] : 0;
		return ( $seq_a < $seq_b ) ? -1 : ( ( $seq_a > $seq_b ) ? 1 : 0 );
	}

	/**
	 * Add the rows of one enquiry (zero, one or two).
	 *
	 * @param array  $entry   Attribution row: id, subject_id, attribution_json, consent_json (already read with the page).
	 * @param string $stage   both, quoted or paid.
	 * @param int    $from    Window start.
	 * @param int    $to      Window end.
	 * @param array  $rows    Rows so far (by reference).
	 * @param array  $skipped Skip counts so far (by reference).
	 * @param array  $meta    Counters of what core's lookup answered (by reference): reached, usable.
	 * @return void
	 */
	private static function collect( array $entry, $stage, $from, $to, array &$rows, array &$skipped, array &$meta ) {
		$id  = isset( $entry['subject_id'] ) ? (int) $entry['subject_id'] : 0;
		$seq = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
		if ( $id < 1 ) {
			self::skip( $skipped, 'bad_subject' );
			return;
		}
		$stored = DoughBoss_Growth_Attribution::decode_row( $entry ); // The same decoding for_subject() applies; no further query.
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
		try {
			$enquiry = call_user_func( array( 'DoughBoss_Catering', 'get' ), $id );
		} catch ( Throwable $e ) {
			self::skip( $skipped, 'read_failed' ); // Core's lookup failed: counted, listed and the download refused, never skipped as "no enquiry".
			return;
		}
		$meta['reached']++;
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
		$meta['usable']++;
		$value = ( $cents > 0 ) ? sprintf( '%d.%02d', intdiv( $cents, 100 ), $cents % 100 ) : '';

		if ( 'both' === $stage || 'quoted' === $stage ) {
			$at = self::parse_site_time( isset( $enquiry['quoted_at'] ) ? $enquiry['quoted_at'] : '' );
			if ( null === $at ) {
				self::skip( $skipped, 'not_quoted' );
			} elseif ( $at >= $from && $at <= $to ) {
				$rows[] = self::row( $gclid, self::NAME_QUOTED, $at, $value, $number, $seq );
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
				$rows[] = self::row( $gclid, self::NAME_WON, $at, $value, $number, $seq );
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
	 * @param int    $seq      Attribution row id (the last tie-break when sorting).
	 * @return array
	 */
	private static function row( $gclid, $name, $ts, $value, $order_id, $seq = 0 ) {
		return array(
			'gclid'    => $gclid,
			'name'     => $name,
			'ts'       => $ts,
			'time'     => gmdate( 'Y-m-d H:i:s', $ts ) . '+0000',
			'value'    => $value,
			'order_id' => $order_id,
			'seq'      => $seq,
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

	/**
	 * List a failure for the owner (the Recent failures list on the Growth settings screen). Never throws.
	 *
	 * @param string $code    Failure code.
	 * @param array  $context Scalar facts without personal data.
	 * @return void
	 */
	private static function note_failure( $code, array $context ) {
		if ( class_exists( 'DoughBoss_Growth_Failures', false ) ) {
			DoughBoss_Growth_Failures::record( $code, $context );
		}
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
	 * Download handler. Capability AND nonce first, then the feature flag; a bad date, a storage problem, a lookup that
	 * failed or a file that would be cut short downloads nothing and says why.
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
		$refusal = self::refusal_message( $result );
		if ( '' !== $refusal ) {
			wp_die( esc_html( $refusal ), '', array( 'response' => 422 ) ); // A file that only looks complete is worse than none.
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
		echo '<p>' . esc_html__( 'A file of catering enquiries that were quoted or paid, with the Google click id, for upload in Google Ads under offline conversions. Only enquiries whose visitor agreed to advertising measurement and who arrived with a Google click id are included. Enquiries with no click id, with only a gbraid or wbraid id, or that were lost are left out. The value is the enquiry total held in DoughBoss. If the file could not be made complete, nothing is downloaded and the page says why. Nothing is uploaded for you.', 'doughboss-growth' ) . '</p>';
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
