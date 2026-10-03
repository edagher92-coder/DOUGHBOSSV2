<?php
/**
 * Silence-rule tests for the conversions, outbox and offline-export code.
 *
 * House rule: a failed read or a dropped event must be visible to the owner, never rendered as zero, "None yet",
 * "Nothing has been queued", an empty queue or a header-only export that looks complete. These tests drive the real
 * module (outbox, conversions, offline export, attribution reads) against the harness's in-memory SQLite database and
 * force the failures a real host produces: a database error on one statement, a core accessor that throws or has gone,
 * a core row of the wrong shape, a cron schedule that cannot be written.
 *
 * What they pin, by finding of the 2026-10 review:
 *  - 6: the offline export refuses to download a file that would be incomplete (a core lookup that failed or returned
 *    nothing usable, or a file cut short at the row cap) and says why; the page query carries the attribution data, so
 *    one read per page replaces one read per enquiry, and a cap drops the OLDEST rows;
 *  - 9: the outbox notes a failed state write, a failed read and a cron event that could not be written; a failed read
 *    never clears the cron; the Conversions tab lists every channel with rows that were given up on or have waited
 *    over an hour, with the (already redacted) last error;
 *  - 10: abnormal skip reasons and read failures reach the Recent failures list, never "no consent"; the benign
 *    business reasons (not paid, no consent, no match keys) stay silent;
 *  - WP-5: a dispatch run claims at most ten rows and stops claiming after its time budget;
 *  - WP-9: the admin-side resume check costs managers one count per fifteen minutes and everyone else nothing.
 *
 * Self-contained (own helpers, prefix dbgr_cs_) so `php tests/run.php conversions-silence` runs on its own. The file name
 * sorts before test-conversions.php, so nothing here may rely on that file's helpers.
 *
 * @package DoughBoss_Growth
 */

DoughBoss_Growth::load_module( 'attribution' );
DoughBoss_Growth::load_module( 'conversions' );

define( 'DBGR_CS_GA4_SECRET', 'TESTONLYGA4SECRETVALUE' );
define( 'DBGR_CS_META_TOKEN', 'TESTONLYMETATOKENVALUE' );

/* ---------------------------------------------------------------------------------------------------------- */
/* Helpers                                                                                                     */
/* ---------------------------------------------------------------------------------------------------------- */

/** Set (or clear) the secret environment variables. */
function dbgr_cs_env( array $values ) {
	foreach ( DoughBoss_Growth_Settings::SECRET_NAMES as $name ) {
		putenv( $name );
	}
	foreach ( $values as $name => $value ) {
		putenv( $name . '=' . $value );
	}
}

/** SQLite with the real outbox and attribution tables, the feature on, both destinations configured. Calls Outbox::init(). */
function dbgr_cs_boot( array $o = array() ) {
	$GLOBALS['wpdb']->use_sqlite();
	foreach ( DoughBoss_Growth_Outbox::schema() as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
	foreach ( DoughBoss_Growth_Attribution::schema() as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
	update_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION, DOUGHBOSS_GROWTH_DB_VERSION );
	dbgr_cs_env(
		array(
			'DOUGHBOSS_GROWTH_GA4_API_SECRET'  => DBGR_CS_GA4_SECRET,
			'DOUGHBOSS_GROWTH_META_CAPI_TOKEN' => DBGR_CS_META_TOKEN,
		)
	);
	update_option(
		DoughBoss_Growth_Settings::OPTION,
		array(
			'features'                => array(
				'attribution'        => true,
				'server_conversions' => true,
			),
			'ga4_measurement_id'      => 'G-TEST1234',
			'meta_pixel_id'           => '123456789012345',
			'send_hashed_identifiers' => 0,
		)
	);
	if ( empty( $o['no_outbox_init'] ) ) {
		DoughBoss_Growth_Outbox::init();
	}
	DoughBoss_Order::$rows     = array();
	DoughBoss_Order::$throw    = false;
	DoughBoss_Catering::$rows  = array();
	DoughBoss_Catering::$throw = false;
}

/** Boot, then init the conversions module (registers the channels and the core hooks). */
function dbgr_cs_start() {
	dbgr_cs_boot();
	DoughBoss_Growth_Conversions::init();
}

/** A paid order in the core stub. */
function dbgr_cs_order( $id, array $over = array() ) {
	DoughBoss_Order::$rows[ $id ] = (object) array_merge(
		array(
			'id'             => $id,
			'order_number'   => 'DB-261002-ABC123',
			'total'          => '35.99',
			'currency'       => 'AUD',
			'payment_status' => 'paid',
			'customer_email' => 'jane.citizen@example.net',
			'customer_phone' => '0412 345 678',
		),
		$over
	);
}

/** A catering enquiry in the core stub. */
function dbgr_cs_enquiry( $id, array $over = array() ) {
	DoughBoss_Catering::$rows[ $id ] = array_merge(
		array(
			'id'              => $id,
			'enquiry_number'  => sprintf( 'DB-Q-%05d', $id ),
			'status'          => 'quoted',
			'customer_email'  => 'jane.citizen@example.net',
			'customer_phone'  => '0412 345 678',
			'quote_total'     => '1250.00',
			'currency'        => 'AUD',
			'quoted_at'       => '2026-09-30 10:00:00',
			'balance_paid_at' => null,
		),
		$over
	);
}

/** Store the attribution and consent record for a subject, as WP-04 does. */
function dbgr_cs_subject( $type, $id, $m, $a, $attr = null ) {
	return DoughBoss_Growth_Attribution::write_subject(
		$type,
		$id,
		null === $attr ? array( 'utmSource' => 'test', 'gclid' => 'Cj0KCQjwTESTCLICKID1234', 'fbclid' => 'AbCdEfGhIj1234567890', 'firstSeenAt' => '2026-10-01T00:00:00.000Z' ) : $attr,
		array(
			'measurement' => (bool) $m,
			'advertising' => (bool) $a,
			'chosen'      => true,
			'version'     => '1',
		)
	);
}

/** The recorded failure codes, sorted. */
function dbgr_cs_codes() {
	$codes = array_column( DoughBoss_Growth_Failures::all(), 'code' );
	sort( $codes );
	return $codes;
}

/** One recorded failure by code, or null. */
function dbgr_cs_failure( $code ) {
	foreach ( DoughBoss_Growth_Failures::all() as $record ) {
		if ( $code === $record['code'] ) {
			return $record;
		}
	}
	return null;
}

/** Outbox rows. */
function dbgr_cs_rows() {
	return $GLOBALS['wpdb']->sqlite_raw( 'SELECT * FROM wp_doughboss_growth_outbox ORDER BY id ASC' );
}

/** Queue one harmless event. */
function dbgr_cs_add( $channel = 'ga4', $event_id = 'order:1001' ) {
	return DoughBoss_Growth_Outbox::enqueue( $channel, 'purchase', $event_id, 'order', '1001', array( 'value_cents' => 1250, 'currency' => 'AUD' ) );
}

/** Call the export handler as a manager with a good nonce. Returns the DBGR_Test_Die it ended with, or the CSV text. */
function dbgr_cs_export( array $post = array() ) {
	dbgr_test_login( array( 'manage_doughboss' ) );
	$_POST = $_REQUEST = array_merge( array( '_wpnonce' => wp_create_nonce( DoughBoss_Growth_Offline_Export::ACTION ), 'stage' => 'quoted' ), $post );
	ob_start();
	try {
		DoughBoss_Growth_Offline_Export::handle_export();
	} catch ( DBGR_Test_Die $e ) {
		ob_end_clean();
		return $e;
	}
	return (string) ob_get_clean();
}

/** The Conversions tab as a manager sees it. */
function dbgr_cs_tab() {
	dbgr_test_login( array( 'manage_doughboss' ) );
	ob_start();
	DoughBoss_Growth_Conversions::render_tab();
	return (string) ob_get_clean();
}

/** The export window of the tests: all of September to the end of October 2026. */
function dbgr_cs_window() {
	return array(
		DoughBoss_Growth_Offline_Export::parse_date( '2026-09-01', false ),
		DoughBoss_Growth_Offline_Export::parse_date( '2026-10-31', true ),
	);
}

/* ---------------------------------------------------------------------------------------------------------- */
/* Finding 6: the offline export never hands over a file that only looks complete                              */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'finding 6: a core lookup that throws is a refusal that names the cause, never a header-only download; the failure is listed',
	function () {
		dbgr_cs_start();
		dbgr_cs_enquiry( 5 );
		dbgr_cs_enquiry( 6 );
		dbgr_cs_subject( 'enquiry', 5, 1, 1 );
		dbgr_cs_subject( 'enquiry', 6, 1, 1 );
		list( $from, $to ) = dbgr_cs_window();

		DoughBoss_Catering::$throw = true;
		$result                    = DoughBoss_Growth_Offline_Export::build( 'quoted', $from, $to );
		assert_true( is_array( $result ), 'build() still returns what it found' );
		assert_same( 0, $result['rows'], 'no row could be written' );
		assert_same( 2, $result['skipped']['read_failed'], 'both enquiries are counted as unread' );
		assert_same( array( 'read_failed' => 2 ), $result['failed'], 'and reported as a system failure' );
		assert_false( $result['truncated'], 'not truncated' );
		assert_true( null !== dbgr_cs_failure( 'offline_export_read_failed' ), 'the owner-visible failure list has it' );

		DoughBoss_Growth_Failures::clear();
		$e = dbgr_cs_export();
		assert_true( $e instanceof DBGR_Test_Die, 'the download is refused (no CSV was sent)' );
		assert_same( 422, $e instanceof DBGR_Test_Die ? $e->args['response'] : 0, 'with a clear status' );
		assert_contains( 'Nothing was downloaded', $e instanceof DBGR_Test_Die ? $e->getMessage() : '', 'the message says nothing was downloaded' );
		assert_contains( '2', $e instanceof DBGR_Test_Die ? $e->getMessage() : '', 'and how many enquiries could not be read' );

		DoughBoss_Catering::$throw = false;
		DoughBoss_Growth_Failures::clear();
		$csv = dbgr_cs_export();
		assert_true( is_string( $csv ), 'control: once the lookup works the same request downloads' );
		assert_same( 3, count( array_filter( explode( "\r\n", (string) $csv ), 'strlen' ) ), 'control: header and two rows' );
		assert_same( array(), dbgr_cs_codes(), 'control: nothing is recorded as a failure' );
	}
);

db_test(
	'finding 6: when core hands back nothing usable for every enquiry (its row shape changed) the download is refused; one odd row among good ones does not block it',
	function () {
		dbgr_cs_start();
		list( $from, $to ) = dbgr_cs_window();
		foreach ( array( 1, 2, 3 ) as $id ) {
			dbgr_cs_enquiry( $id, array( 'enquiry_number' => '' ) ); // Core stopped sending the number.
			dbgr_cs_subject( 'enquiry', $id, 1, 1 );
		}
		$result = DoughBoss_Growth_Offline_Export::build( 'quoted', $from, $to );
		assert_same( 0, $result['rows'], 'nothing could be written' );
		assert_same( 3, $result['skipped']['no_enquiry_number'], 'every enquiry lacked a number' );
		assert_same( array( 'core_unusable' => 3 ), $result['failed'], 'that is a system failure, not a quiet week' );
		assert_true( null !== dbgr_cs_failure( 'offline_export_core_unusable' ), 'listed for the owner' );
		$e = dbgr_cs_export();
		assert_true( $e instanceof DBGR_Test_Die && 422 === $e->args['response'], 'the download is refused' );

		// Every lookup returning nothing at all (core no longer finds any of them) is the same.
		DoughBoss_Catering::$rows = array();
		$none                     = DoughBoss_Growth_Offline_Export::build( 'quoted', $from, $to );
		assert_same( array( 'core_unusable' => 3 ), $none['failed'], 'core finding no enquiry at all is a system failure too' );

		// Control: one malformed number among good enquiries is ordinary data, and the file is still produced.
		DoughBoss_Growth_Failures::clear();
		dbgr_cs_enquiry( 2, array( 'enquiry_number' => '=SUM(A1)' ) );
		dbgr_cs_enquiry( 1 );
		dbgr_cs_enquiry( 3 );
		$mixed = DoughBoss_Growth_Offline_Export::build( 'quoted', $from, $to );
		assert_same( 2, $mixed['rows'], 'control: the two good enquiries are exported' );
		assert_same( 1, $mixed['skipped']['no_enquiry_number'], 'control: the odd one is counted' );
		assert_same( array(), $mixed['failed'], 'control: no system failure' );
		assert_same( array(), dbgr_cs_codes(), 'control: nothing recorded' );
		assert_true( is_string( dbgr_cs_export() ), 'control: the download works' );
	}
);

db_test(
	'finding 6: a legitimately empty window is still a header-only download (control: the refusal is not a blanket)',
	function () {
		dbgr_cs_start();
		dbgr_cs_enquiry( 5, array( 'quoted_at' => '2026-01-05 09:00:00' ) ); // Quoted months ago: outside the default window.
		dbgr_cs_subject( 'enquiry', 5, 1, 1 );
		$csv = dbgr_cs_export();
		assert_true( is_string( $csv ), 'downloads' );
		assert_same( 1, count( array_filter( explode( "\r\n", (string) $csv ), 'strlen' ) ), 'the header only' );
		assert_same( array(), dbgr_cs_codes(), 'and no failure is recorded' );
		DoughBoss_Growth_Failures::clear();

		// No attribution rows at all is the same quiet answer.
		$GLOBALS['wpdb']->sqlite_raw( 'DELETE FROM wp_doughboss_growth_attribution' );
		assert_true( is_string( dbgr_cs_export() ), 'an empty table downloads the header' );
	}
);

db_test(
	'finding 6 and WP-10: past the row cap the file is refused, not cut short, and a cap drops the OLDEST enquiries, not the newest',
	function () {
		dbgr_cs_start();
		$N = 5001;
		$GLOBALS['wpdb']->sqlite_raw( 'BEGIN' );
		$GLOBALS['wpdb']->sqlite_raw(
			"INSERT INTO wp_doughboss_growth_attribution (subject_type, subject_id, attribution_json, consent_json, captured_at) WITH RECURSIVE c(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM c WHERE x < {$N}) SELECT 'enquiry', CAST(x AS TEXT), '{\"gclid\":\"Cj0KCQjwTESTCLICKID1234\"}', '{\"measurement\":true,\"advertising\":true,\"chosen\":true,\"version\":\"1\"}', '2026-10-01 00:00:00' FROM c"
		);
		$GLOBALS['wpdb']->sqlite_raw( 'COMMIT' );
		for ( $i = 1; $i <= $N; $i++ ) {
			dbgr_cs_enquiry( $i );
		}
		list( $from, $to ) = dbgr_cs_window();
		$result            = DoughBoss_Growth_Offline_Export::build( 'quoted', $from, $to );
		assert_same( 5000, $result['rows'], 'the cap is 5000 rows' );
		assert_true( true === $result['truncated'], 'the result says it was cut short' );
		assert_same( 1, $result['skipped']['truncated'], 'and so does the skip list (kept for existing readers)' );
		assert_contains( 'DB-Q-05001', $result['csv'], 'the newest enquiry is in the file' );
		assert_not_contains( 'DB-Q-00001', $result['csv'], 'the oldest is the one the cap dropped' );

		$e = dbgr_cs_export();
		assert_true( $e instanceof DBGR_Test_Die, 'the download is refused: a file cut short would look complete' );
		assert_same( 422, $e instanceof DBGR_Test_Die ? $e->args['response'] : 0, 'with a clear status' );
		assert_contains( 'shorter', $e instanceof DBGR_Test_Die ? $e->getMessage() : '', 'the message tells the operator to choose a shorter window' );
		assert_contains( 'Nothing was downloaded', $e instanceof DBGR_Test_Die ? $e->getMessage() : '', 'and that nothing was downloaded' );

		// Control: under the cap the same code downloads.
		$GLOBALS['wpdb']->sqlite_raw( 'DELETE FROM wp_doughboss_growth_attribution WHERE CAST(subject_id AS INTEGER) > 10' );
		assert_true( is_string( dbgr_cs_export() ), 'control: ten enquiries download normally' );
	}
);

db_test(
	'WP-10: the export reads attribution once per page (no query per enquiry) and asks core about no enquiry that lacks consent or a click id',
	function () {
		dbgr_cs_start();
		dbgr_cs_enquiry( 1 );
		dbgr_cs_enquiry( 2 );
		dbgr_cs_enquiry( 3 );
		dbgr_cs_subject( 'enquiry', 1, 1, 0 );                         // No advertising consent.
		dbgr_cs_subject( 'enquiry', 2, 1, 1, array( 'utmSource' => 'x' ) ); // Consent but no click id.
		dbgr_cs_subject( 'enquiry', 3, 1, 1, array( 'gbraid' => 'Cj0KCQjwTESTGBRAID1234' ) ); // Only a gbraid.
		DoughBoss_Catering::$throw = true; // Any call to core's lookup would be reported as a failure.
		list( $from, $to )         = dbgr_cs_window();
		$GLOBALS['wpdb']->reset_log();
		$result = DoughBoss_Growth_Offline_Export::build( 'both', $from, $to );
		assert_same( 0, $result['rows'], 'nothing to export' );
		assert_same( array(), $result['failed'], 'core was never asked about an enquiry that could not be exported' );
		assert_same( 1, $result['skipped']['no_advertising_consent'], 'counted: no consent' );
		assert_same( 2, $result['skipped']['no_click_id'], 'counted: no click id, and gbraid only' );
		assert_same( array(), $GLOBALS['wpdb']->queries_matching( '/FROM wp_doughboss_growth_attribution WHERE subject_type = \'enquiry\' AND subject_id/' ), 'no per-enquiry attribution read' );
		assert_same( 1, count( $GLOBALS['wpdb']->queries_matching( '/FROM wp_doughboss_growth_attribution/' ) ), 'a short page is the last page: one attribution read in all' );
	}
);

db_test(
	'finding 6 (no_record): a failed per-enquiry attribution read can no longer turn eligible enquiries into silent "no_record" skips',
	function () {
		dbgr_cs_start();
		dbgr_cs_enquiry( 5 );
		dbgr_cs_subject( 'enquiry', 5, 1, 1 );
		list( $from, $to ) = dbgr_cs_window();
		$GLOBALS['wpdb']->fail_on( '/SELECT attribution_json, consent_json, captured_at FROM wp_doughboss_growth_attribution WHERE subject_type/' );
		$result = DoughBoss_Growth_Offline_Export::build( 'quoted', $from, $to );
		assert_same( 1, $result['rows'], 'the enquiry is exported from the page read' );
		assert_false( isset( $result['skipped']['no_record'] ), 'and nothing is counted as having no record' );
		$GLOBALS['wpdb']->clear_failures();

		// A failed page read is still no file at all (and the handler says so).
		$GLOBALS['wpdb']->fail_on( '/FROM wp_doughboss_growth_attribution WHERE subject_type = \'enquiry\' AND id/' );
		assert_same( null, DoughBoss_Growth_Offline_Export::build( 'quoted', $from, $to ), 'a page read error produces no file' );
		$e = dbgr_cs_export();
		assert_true( $e instanceof DBGR_Test_Die && 500 === $e->args['response'], 'the handler refuses with a server error' );
	}
);

db_test(
	'finding 6: the refusal decision is a pure function of the build result',
	function () {
		$ok = array( 'rows' => 3, 'skipped' => array( 'outside_window' => 4 ), 'failed' => array(), 'truncated' => false );
		assert_same( '', DoughBoss_Growth_Offline_Export::refusal_message( $ok ), 'a complete result is never refused' );
		$empty = array( 'rows' => 0, 'skipped' => array(), 'failed' => array(), 'truncated' => false );
		assert_same( '', DoughBoss_Growth_Offline_Export::refusal_message( $empty ), 'an empty but complete result is never refused' );
		foreach ( array( 'no_core_accessor', 'read_failed', 'core_unusable' ) as $reason ) {
			$bad = array_merge( $ok, array( 'failed' => array( $reason => 7 ) ) );
			assert_true( '' !== DoughBoss_Growth_Offline_Export::refusal_message( $bad ), $reason . ' is refused even when other rows were written' );
		}
		$cut = array_merge( $ok, array( 'truncated' => true ) );
		assert_true( '' !== DoughBoss_Growth_Offline_Export::refusal_message( $cut ), 'a truncated result is refused' );
		assert_same( '', DoughBoss_Growth_Offline_Export::refusal_message( array() ), 'a result with no such keys is not refused (old callers)' );
	}
);

db_test(
	'finding 6: with core\'s enquiry lookup gone (sub-process: the class has no get()) the export is refused and the failure is listed',
	function () {
		if ( ! dbgr_test_can_subprocess() ) {
			dbgr_test_skip( 'sub-process unavailable: the missing-accessor path cannot be reproduced in-process (a class cannot lose a method)' );
			return;
		}
		$prelude = 'class DoughBoss_Catering {}'; // Core changed: the lookup is gone. The test stub only defines the class when it does not exist.
		$code    = <<<'CODE'
DoughBoss_Growth::load_module( 'attribution' );
DoughBoss_Growth::load_module( 'conversions' );
$GLOBALS['wpdb']->use_sqlite();
foreach ( array_merge( DoughBoss_Growth_Outbox::schema(), DoughBoss_Growth_Attribution::schema() ) as $sql ) { $GLOBALS['wpdb']->create_table_from_mysql( $sql ); }
update_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION, DOUGHBOSS_GROWTH_DB_VERSION );
putenv( 'DOUGHBOSS_GROWTH_GA4_API_SECRET=TESTONLYGA4SECRETVALUE' );
update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'attribution' => true, 'server_conversions' => true ), 'ga4_measurement_id' => 'G-TEST1234' ) );
DoughBoss_Growth_Attribution::write_subject( 'enquiry', 5, array( 'gclid' => 'Cj0KCQjwTESTCLICKID1234' ), array( 'measurement' => true, 'advertising' => true, 'chosen' => true, 'version' => '1' ) );
$from = DoughBoss_Growth_Offline_Export::parse_date( '2026-09-01', false );
$to   = DoughBoss_Growth_Offline_Export::parse_date( '2026-10-31', true );
$built = DoughBoss_Growth_Offline_Export::build( 'both', $from, $to );
dbgr_test_login( array( 'manage_doughboss' ) );
$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( DoughBoss_Growth_Offline_Export::ACTION ) );
$status = 'downloaded';
ob_start();
try { DoughBoss_Growth_Offline_Export::handle_export(); } catch ( DBGR_Test_Die $e ) { $status = 'refused ' . $e->args['response']; }
ob_end_clean();
echo json_encode( array( 'rows' => $built['rows'], 'skipped' => $built['skipped'], 'failed' => $built['failed'], 'status' => $status, 'codes' => array_column( DoughBoss_Growth_Failures::all(), 'code' ) ) );
CODE;
		$run     = dbgr_test_subprocess( $code, $prelude );
		$out     = json_decode( $run['out'], true );
		assert_true( is_array( $out ), 'the sub-process answered (' . substr( $run['err'] . $run['out'], 0, 300 ) . ')' );
		if ( ! is_array( $out ) ) {
			return;
		}
		assert_same( 0, $out['rows'], 'no row' );
		assert_same( 1, $out['skipped']['no_core_accessor'], 'counted as no_core_accessor' );
		assert_same( array( 'no_core_accessor' => 1 ), $out['failed'], 'reported as a system failure' );
		assert_same( 'refused 422', $out['status'], 'the download is refused, not a header-only 200' );
		assert_true( in_array( 'offline_export_no_core_accessor', $out['codes'], true ), 'and listed for the owner' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Finding 10: skipped events and failed reads are visible; benign business reasons are not                    */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'finding 10: abnormal skip reasons (no order, no order number, no usable amount) are listed as failures; each hook drops the event but never throws',
	function () {
		dbgr_cs_start();
		// Core changed the shape of its order row: the number is gone.
		dbgr_cs_order( 17, array( 'order_number' => '' ) );
		dbgr_cs_subject( 'order', 17, 1, 1 );
		do_action( 'doughboss_order_created', 17, array() );
		assert_true( null !== dbgr_cs_failure( 'conversion_skipped_no_order_number' ), 'a missing order number is listed' );
		assert_same( 0, count( dbgr_cs_rows() ), 'and nothing was queued' );
		assert_same( 'purchase', dbgr_cs_failure( 'conversion_skipped_no_order_number' )['context']['event'], 'with the event it was for' );

		// Core no longer finds an order that its own hook just announced.
		do_action( 'doughboss_order_created', 9999, array() );
		assert_true( null !== dbgr_cs_failure( 'conversion_skipped_no_order' ), 'an order that cannot be found is listed' );

		// An amount that cannot be read.
		dbgr_cs_order( 18, array( 'order_number' => 'DB-261002-AMT001', 'total' => 'n/a' ) );
		dbgr_cs_subject( 'order', 18, 1, 1 );
		do_action( 'doughboss_order_payment_status_changed', 18, 'pending', 'paid' );
		assert_true( null !== dbgr_cs_failure( 'conversion_skipped_no_valid_amount' ), 'an unreadable total is listed' );

		// The same on the lead side.
		dbgr_cs_enquiry( 5, array( 'enquiry_number' => '' ) );
		dbgr_cs_subject( 'enquiry', 5, 1, 1 );
		do_action( 'doughboss_catering_enquiry_created', 5, array() );
		assert_true( null !== dbgr_cs_failure( 'conversion_skipped_no_enquiry_number' ), 'a missing enquiry number is listed' );
		do_action( 'doughboss_catering_enquiry_created', 8888, array() );
		assert_true( null !== dbgr_cs_failure( 'conversion_skipped_no_enquiry' ), 'an enquiry that cannot be found is listed' );

		foreach ( DoughBoss_Growth_Failures::all() as $record ) {
			$text = wp_json_encode( $record );
			foreach ( array( 'jane.citizen', 'example.net', '0412', 'DB-261002', 'DB-Q-' ) as $needle ) {
				assert_not_contains( $needle, $text, $record['code'] . ': no personal data or reference in the failure record (' . $needle . ')' );
			}
		}
	}
);

db_test(
	'finding 10: benign business reasons stay silent (not paid, a genuine zero total, no consent, no match keys, no stored consent record, a duplicate)',
	function () {
		dbgr_cs_start();
		dbgr_cs_order( 17, array( 'payment_status' => 'pending' ) ); // Pay at the shop.
		dbgr_cs_subject( 'order', 17, 1, 1 );
		do_action( 'doughboss_order_created', 17, array() );

		dbgr_cs_order( 18, array( 'order_number' => 'DB-261002-FREE01', 'total' => '0.00' ) ); // A fully discounted order.
		dbgr_cs_subject( 'order', 18, 1, 1 );
		do_action( 'doughboss_order_created', 18, array() );

		dbgr_cs_order( 19, array( 'order_number' => 'DB-261002-NOCONS' ) );
		dbgr_cs_subject( 'order', 19, 0, 0 ); // The visitor refused.
		do_action( 'doughboss_order_created', 19, array() );

		dbgr_cs_order( 20, array( 'order_number' => 'DB-261002-NOCONS2' ) ); // No record at all.
		do_action( 'doughboss_order_created', 20, array() );

		dbgr_cs_order( 21, array( 'order_number' => 'DB-261002-NOKEYS' ) );
		dbgr_cs_subject( 'order', 21, 1, 1, array( 'utmSource' => 'x' ) ); // Advertising consent, but nothing Meta could match.
		$result = DoughBoss_Growth_Conversions::process_order( 21, 'purchase', '' );
		assert_same( 'queued', $result['ga4'], 'GA4 is queued' );
		assert_same( 'no_match_keys', $result['meta'], 'Meta has nothing to match' );
		do_action( 'doughboss_order_created', 21, array() ); // A replay: duplicate.

		assert_same( array(), dbgr_cs_codes(), 'none of those is recorded as a failure' );
		assert_same( 'no_consent', DoughBoss_Growth_Conversions::process_order( 19, 'purchase', '' )['ga4'], 'a refusal is still reported as no_consent' );
		assert_same( 'no_consent', DoughBoss_Growth_Conversions::process_order( 20, 'purchase', '' )['ga4'], 'no stored record is still no_consent' );
		assert_same( array( 'skipped' => 'not_paid' ), DoughBoss_Growth_Conversions::process_order( 17, 'purchase', '' ), 'not paid is still skipped with its reason' );
		assert_same( array(), dbgr_cs_codes(), 'and still silent' );
	}
);

db_test(
	'finding 10: a failed attribution read is read_failed on every channel, never no_consent; it is listed and nothing is queued',
	function () {
		dbgr_cs_start();
		dbgr_cs_order( 17 );
		dbgr_cs_subject( 'order', 17, 1, 1 );
		$GLOBALS['wpdb']->fail_on( '/FROM wp_doughboss_growth_attribution WHERE subject_type = \'order\'/' );
		assert_same( false, DoughBoss_Growth_Attribution::for_subject( 'order', 17 ), 'for_subject() reports the database error as false' );
		$result = DoughBoss_Growth_Conversions::process_order( 17, 'purchase', '' );
		assert_same( array( 'ga4' => 'read_failed', 'meta' => 'read_failed' ), $result, 'both channels say the read failed' );
		assert_same( 0, count( dbgr_cs_rows() ), 'nothing queued' );
		assert_true( null !== dbgr_cs_failure( 'conversion_read_failed' ), 'the dropped event is on the failure list' );
		assert_true( null !== dbgr_cs_failure( 'attribution_read_failed' ), 'and so is the failed read itself' );

		// A hook run (no direct call) behaves the same and never throws into core.
		DoughBoss_Growth_Failures::clear();
		do_action( 'doughboss_order_created', 17, array() );
		assert_true( null !== dbgr_cs_failure( 'conversion_read_failed' ), 'through the core hook too' );

		// Control: the same order, reads working, queues; and a missing row is null (no record), not false.
		$GLOBALS['wpdb']->clear_failures();
		DoughBoss_Growth_Failures::clear();
		assert_same( 'queued', DoughBoss_Growth_Conversions::process_order( 17, 'purchase', '' )['ga4'], 'control: queued' );
		assert_same( null, DoughBoss_Growth_Attribution::for_subject( 'order', 4040 ), 'control: no row is null' );
		assert_same( null, DoughBoss_Growth_Attribution::for_subject( 'order', 'x' ), 'control: an invalid subject is null' );
		assert_same( array(), dbgr_cs_codes(), 'control: nothing recorded' );
	}
);

db_test(
	'finding 10: the same read failure on a lead is read_failed, not no_consent',
	function () {
		dbgr_cs_start();
		dbgr_cs_enquiry( 5 );
		dbgr_cs_subject( 'enquiry', 5, 1, 1 );
		$GLOBALS['wpdb']->fail_on( '/FROM wp_doughboss_growth_attribution WHERE subject_type = \'enquiry\'/' );
		$result = DoughBoss_Growth_Conversions::process_enquiry( 5 );
		assert_same( array( 'ga4' => 'read_failed', 'meta' => 'read_failed' ), $result, 'both channels say the read failed' );
		assert_same( 0, count( dbgr_cs_rows() ), 'nothing queued' );
	}
);

db_test(
	'finding 10: a payload that fails its own validation is listed (rejected is a dropped event, never a quiet outcome); a clean one records nothing',
	function () {
		dbgr_cs_start();
		dbgr_cs_order( 17 );
		// A stored consent wording version with characters the payload schema does not allow.
		DoughBoss_Growth_Attribution::write_subject( 'order', 17, array( 'utmSource' => 'x' ), array( 'measurement' => true, 'advertising' => true, 'chosen' => true, 'version' => 'v 1 !' ) );
		$result = DoughBoss_Growth_Conversions::process_order( 17, 'purchase', '' );
		assert_same( 'rejected', $result['ga4'], 'the event is refused by validation' );
		assert_same( 0, count( dbgr_cs_rows() ), 'and nothing is queued' );
		$failure = dbgr_cs_failure( 'conversion_payload_rejected' );
		assert_true( null !== $failure, 'the refusal is on the failure list' );
		assert_same( 'purchase', $failure['context']['event'], 'with the event' );
		assert_same( 'validate', $failure['context']['stage'], 'and the stage' );

		DoughBoss_Growth_Failures::clear();
		dbgr_cs_order( 18, array( 'order_number' => 'DB-261002-CLEAN1' ) );
		dbgr_cs_subject( 'order', 18, 1, 1, array( 'utmSource' => 'x' ) );
		assert_same( 'queued', DoughBoss_Growth_Conversions::process_order( 18, 'purchase', '' )['ga4'], 'control: a clean payload is queued' );
		assert_same( array(), dbgr_cs_codes(), 'control: nothing is recorded' );
	}
);

db_test(
	'finding 10: with core\'s order and enquiry lookups gone (sub-process) the skip is no_core_accessor and it is listed',
	function () {
		if ( ! dbgr_test_can_subprocess() ) {
			dbgr_test_skip( 'sub-process unavailable: a class cannot lose a method in-process' );
			return;
		}
		$prelude = 'class DoughBoss_Order {} class DoughBoss_Catering {}';
		$code    = <<<'CODE'
DoughBoss_Growth::load_module( 'attribution' );
DoughBoss_Growth::load_module( 'conversions' );
$GLOBALS['wpdb']->use_sqlite();
foreach ( array_merge( DoughBoss_Growth_Outbox::schema(), DoughBoss_Growth_Attribution::schema() ) as $sql ) { $GLOBALS['wpdb']->create_table_from_mysql( $sql ); }
update_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION, DOUGHBOSS_GROWTH_DB_VERSION );
putenv( 'DOUGHBOSS_GROWTH_GA4_API_SECRET=TESTONLYGA4SECRETVALUE' );
update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'attribution' => true, 'server_conversions' => true ), 'ga4_measurement_id' => 'G-TEST1234' ) );
$order  = DoughBoss_Growth_Conversions::process_order( 17, 'purchase', '' );
$lead   = DoughBoss_Growth_Conversions::process_enquiry( 5 );
echo json_encode( array( 'order' => $order, 'lead' => $lead, 'codes' => array_column( DoughBoss_Growth_Failures::all(), 'code' ) ) );
CODE;
		$run     = dbgr_test_subprocess( $code, $prelude );
		$out     = json_decode( $run['out'], true );
		assert_true( is_array( $out ), 'the sub-process answered (' . substr( $run['err'] . $run['out'], 0, 300 ) . ')' );
		if ( ! is_array( $out ) ) {
			return;
		}
		assert_same( array( 'skipped' => 'no_core_accessor' ), $out['order'], 'order: no_core_accessor, not no_order' );
		assert_same( array( 'skipped' => 'no_core_accessor' ), $out['lead'], 'lead: no_core_accessor, not no_enquiry' );
		assert_true( in_array( 'conversion_skipped_no_core_accessor', $out['codes'], true ), 'listed for the owner' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Finding 9: the outbox never loses a state change, a read failure or a cron event quietly                    */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'finding 9a: a failed write after a successful send is listed; the row is left in flight (and later parked as ambiguous, which the Conversions tab now shows)',
	function () {
		dbgr_cs_boot();
		DoughBoss_Growth_Outbox::register_channel( 'ga4', '__return_true' );
		dbgr_cs_add( 'ga4', 'order:1001' );
		dbgr_test_advance( 120 );
		$GLOBALS['wpdb']->fail_on( '/^UPDATE wp_doughboss_growth_outbox SET status = \'sent\'/' );
		$summary = DoughBoss_Growth_Outbox::dispatch();
		assert_same( 1, $summary['sent'], 'the send itself happened' );
		assert_same( 'in_flight', dbgr_cs_rows()[0]['status'], 'but the row could not be marked sent' );
		$failure = dbgr_cs_failure( 'outbox_update_failed' );
		assert_true( null !== $failure, 'the failed write is on the failure list' );
		assert_same( 'ga4', $failure['context']['channel'], 'for the channel' );
		assert_same( 'sent', $failure['context']['state'], 'and the state it was meant to record' );
		$GLOBALS['wpdb']->clear_failures();

		// What the owner would otherwise discover ten minutes later: a delivered conversion parked as ambiguous.
		dbgr_test_advance( DoughBoss_Growth_Outbox::LEASE_SECONDS + 5 );
		DoughBoss_Growth_Outbox::dispatch();
		assert_same( 'ambiguous_in_flight', dbgr_cs_rows()[0]['last_error'], 'the row is parked as ambiguous' );
	}
);

db_test(
	'finding 9a: a failed write of a retry or a terminal outcome is listed too, and a write that works records nothing (control)',
	function () {
		dbgr_cs_boot();
		DoughBoss_Growth_Outbox::register_channel( 'ga4', function ( $row ) {
			return ( 'order:T' === $row['event_id'] ) ? new WP_Error( 'bad_request', 'No', array( 'terminal' => true ) ) : new WP_Error( 'upstream_503', 'No' );
		} );
		dbgr_cs_add( 'ga4', 'order:T' );
		dbgr_test_advance( 120 );
		$GLOBALS['wpdb']->fail_on( '/^UPDATE wp_doughboss_growth_outbox SET status = \'failed_terminal\', attempts/' );
		DoughBoss_Growth_Outbox::dispatch();
		assert_same( 'terminal', dbgr_cs_failure( 'outbox_update_failed' )['context']['state'], 'terminal write failure is listed' );
		$GLOBALS['wpdb']->clear_failures();
		DoughBoss_Growth_Failures::clear();

		dbgr_cs_add( 'ga4', 'order:R' );
		dbgr_test_advance( 120 );
		$GLOBALS['wpdb']->fail_on( '/^UPDATE wp_doughboss_growth_outbox SET status = \'pending\', attempts/' );
		DoughBoss_Growth_Outbox::dispatch();
		assert_same( 'retry', dbgr_cs_failure( 'outbox_update_failed' )['context']['state'], 'retry write failure is listed' );
		$GLOBALS['wpdb']->clear_failures();
		DoughBoss_Growth_Failures::clear();

		dbgr_cs_add( 'ga4', 'order:OK' );
		dbgr_test_advance( 5000 );
		DoughBoss_Growth_Outbox::dispatch();
		assert_same( array(), dbgr_cs_codes(), 'control: working writes record nothing' );
	}
);

db_test(
	'finding 9b: a failed pending-count after a sweep never clears the cron (the rows would stall) and is listed',
	function () {
		dbgr_cs_boot();
		DoughBoss_Growth_Outbox::register_channel( 'ga4', function () {
			return new WP_Error( 'upstream_503', 'No' ); // The row stays pending (retry).
		} );
		dbgr_cs_add( 'ga4', 'order:1001' );
		assert_true( false !== wp_next_scheduled( DoughBoss_Growth_Outbox::CRON_HOOK ), 'set-up: scheduled by the enqueue' );
		dbgr_test_advance( 120 );
		$GLOBALS['wpdb']->fail_on( '/^SELECT COUNT\(\*\) FROM wp_doughboss_growth_outbox WHERE status = \'pending\'/' );
		DoughBoss_Growth_Outbox::dispatch();
		assert_same( 'pending', dbgr_cs_rows()[0]['status'], 'the row waits for its retry' );
		assert_true( false !== wp_next_scheduled( DoughBoss_Growth_Outbox::CRON_HOOK ), 'the cron is NOT cleared on a failed count' );
		$failure = dbgr_cs_failure( 'outbox_read_failed' );
		assert_true( null !== $failure, 'the failed read is listed' );
		assert_same( 'waiting_count', $failure['context']['stage'], 'with the stage' );
		assert_same( null, DoughBoss_Growth_Outbox::waiting_count(), 'waiting_count() says "could not be read" (null), not zero' );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( 1, DoughBoss_Growth_Outbox::waiting_count(), 'control: a working read counts the row' );

		// Control: with nothing pending the cron is cleared, as before.
		$GLOBALS['wpdb']->sqlite_raw( "UPDATE wp_doughboss_growth_outbox SET status = 'sent'" );
		DoughBoss_Growth_Outbox::dispatch();
		assert_false( wp_next_scheduled( DoughBoss_Growth_Outbox::CRON_HOOK ), 'control: an empty queue clears the cron' );
	}
);

db_test(
	'finding 9b: a failed candidate read is listed (an empty answer is not "nothing is due"), and the cron stays',
	function () {
		dbgr_cs_boot();
		DoughBoss_Growth_Outbox::register_channel( 'ga4', '__return_true' );
		dbgr_cs_add( 'ga4', 'order:1001' );
		dbgr_test_advance( 120 );
		$GLOBALS['wpdb']->fail_on( '/^SELECT id FROM wp_doughboss_growth_outbox/' );
		$summary = DoughBoss_Growth_Outbox::dispatch();
		assert_same( 0, $summary['claimed'], 'nothing claimed' );
		assert_same( 'pending', dbgr_cs_rows()[0]['status'], 'the row is untouched' );
		assert_true( null !== dbgr_cs_failure( 'outbox_read_failed' ), 'the failed read is listed' );
		assert_same( 'due_rows', dbgr_cs_failure( 'outbox_read_failed' )['context']['stage'], 'with its stage' );
		assert_true( false !== wp_next_scheduled( DoughBoss_Growth_Outbox::CRON_HOOK ), 'the cron stays' );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( 1, DoughBoss_Growth_Outbox::dispatch()['sent'], 'control: the next sweep delivers' );
	}
);

db_test(
	'finding 9b: a claim whose row cannot be read back is released to pending (never sent), not left to be parked as ambiguous',
	function () {
		dbgr_cs_boot();
		$called = 0;
		DoughBoss_Growth_Outbox::register_channel( 'ga4', function () use ( &$called ) {
			$called++;
			return true;
		} );
		dbgr_cs_add( 'ga4', 'order:1001' );
		dbgr_test_advance( 120 );
		$GLOBALS['wpdb']->fail_on( '/^SELECT \* FROM wp_doughboss_growth_outbox WHERE id/' );
		$summary = DoughBoss_Growth_Outbox::dispatch();
		assert_same( 0, $called, 'the handler never ran' );
		assert_same( 0, $summary['claimed'], 'the row was not delivered' );
		assert_same( 'pending', dbgr_cs_rows()[0]['status'], 'and went back to pending instead of sitting in flight' );
		assert_true( null !== dbgr_cs_failure( 'outbox_read_failed' ), 'the failed read is listed' );
		assert_same( 'claim', dbgr_cs_failure( 'outbox_read_failed' )['context']['stage'], 'with its stage' );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( 1, DoughBoss_Growth_Outbox::dispatch()['sent'], 'control: delivered once reads work' );
	}
);

db_test(
	'finding 9c: a cron event that cannot be written is listed; the row is still queued',
	function () {
		dbgr_cs_boot( array( 'no_outbox_init' => true ) ); // The five-minute schedule is not registered, so wp_schedule_event() refuses.
		assert_same( 'queued', dbgr_cs_add( 'ga4', 'order:1001' ), 'the row is queued' );
		assert_false( wp_next_scheduled( DoughBoss_Growth_Outbox::CRON_HOOK ), 'but nothing was scheduled' );
		$failure = dbgr_cs_failure( 'outbox_schedule_failed' );
		assert_true( null !== $failure, 'the failed schedule is listed' );

		// Control: with the schedule registered nothing is recorded.
		DoughBoss_Growth_Failures::clear();
		DoughBoss_Growth_Outbox::init();
		assert_same( 'queued', dbgr_cs_add( 'ga4', 'order:1002' ), 'queued' );
		assert_true( false !== wp_next_scheduled( DoughBoss_Growth_Outbox::CRON_HOOK ), 'scheduled' );
		assert_same( array(), dbgr_cs_codes(), 'control: nothing recorded' );
	}
);

db_test(
	'finding 9d: the Conversions tab lists every channel with rows given up on, with the last error, including the waitlist channel; a healthy queue shows no notice',
	function () {
		dbgr_cs_start();
		dbgr_cs_add( 'ga4', 'order:1' );
		dbgr_cs_add( 'meta', 'order:2' );
		dbgr_cs_add( 'waitlist_hook', 'wl:3' );
		$healthy = dbgr_cs_tab();
		assert_not_contains( 'data-dbgr-queue-problems', $healthy, 'control: a fresh queue shows no notice' );

		$GLOBALS['wpdb']->sqlite_raw( "UPDATE wp_doughboss_growth_outbox SET status = 'failed_terminal', last_error = 'ambiguous_in_flight' WHERE channel = 'ga4'" );
		$GLOBALS['wpdb']->sqlite_raw( "UPDATE wp_doughboss_growth_outbox SET status = 'failed_terminal', last_error = 'http_status_error' WHERE channel = 'meta'" );
		$GLOBALS['wpdb']->sqlite_raw( "UPDATE wp_doughboss_growth_outbox SET status = 'failed_terminal', last_error = 'bad_payload' WHERE channel = 'waitlist_hook'" );
		$html = dbgr_cs_tab();
		assert_contains( 'data-dbgr-queue-problems', $html, 'the notice is shown' );
		foreach ( array( 'ga4', 'meta', 'waitlist_hook', 'ambiguous_in_flight', 'http_status_error', 'bad_payload' ) as $needle ) {
			assert_contains( $needle, $html, 'the notice names ' . $needle );
		}
		assert_contains( 'will not be sent again', $html, 'and says they will not be sent again' );
		assert_not_contains( DBGR_CS_GA4_SECRET, $html, 'no secret' );

		// Two different errors on one channel are both shown.
		dbgr_cs_add( 'ga4', 'order:4' );
		$GLOBALS['wpdb']->sqlite_raw( "UPDATE wp_doughboss_growth_outbox SET status = 'failed_terminal', last_error = 'bad_request' WHERE event_id = 'order:4'" );
		$html = dbgr_cs_tab();
		assert_contains( 'bad_request', $html, 'a second error on the same channel is shown' );
		assert_contains( 'ambiguous_in_flight', $html, 'next to the first' );
	}
);

db_test(
	'finding 9d: a row that has waited over an hour is shown with its last error; a fresh retry is not',
	function () {
		dbgr_cs_start();
		dbgr_cs_add( 'ga4', 'order:1' );
		$GLOBALS['wpdb']->sqlite_raw( "UPDATE wp_doughboss_growth_outbox SET last_error = 'upstream_503', attempts = 2" );
		dbgr_test_advance( 3000 );
		assert_not_contains( 'data-dbgr-queue-problems', dbgr_cs_tab(), 'control: 50 minutes is not yet a problem' );
		dbgr_test_advance( 700 );
		$html = dbgr_cs_tab();
		assert_contains( 'data-dbgr-queue-problems', $html, 'past an hour it is shown' );
		assert_contains( 'upstream_503', $html, 'with the last error' );
		assert_contains( 'over an hour', $html, 'and the reason' );

		// A fresh row on another channel is not listed.
		dbgr_cs_add( 'meta', 'order:2' );
		$html = dbgr_cs_tab();
		assert_not_contains( '<code>meta</code>', $html, 'a fresh pending row on another channel is not listed' );
	}
);

db_test(
	'finding 9d: when the queue problems cannot be read the tab says so (it never shows a quiet queue)',
	function () {
		dbgr_cs_start();
		dbgr_cs_add( 'ga4', 'order:1' );
		$GLOBALS['wpdb']->sqlite_raw( "UPDATE wp_doughboss_growth_outbox SET status = 'failed_terminal', last_error = 'bad_payload'" );
		$GLOBALS['wpdb']->fail_on( '/GROUP BY channel, status, last_error/' );
		$html = dbgr_cs_tab();
		assert_contains( 'could not be checked', $html, 'the notice says the problems could not be checked' );
		assert_not_contains( 'will not be sent again', $html, 'and does not pretend to list them' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* WP-5: a dispatch run is bounded                                                                             */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'WP-5: one dispatch run claims at most ten rows; the rest stay pending, untouched',
	function () {
		dbgr_cs_boot();
		$called = 0;
		DoughBoss_Growth_Outbox::register_channel( 'ga4', function () use ( &$called ) {
			$called++;
			return true;
		} );
		for ( $i = 1; $i <= 12; $i++ ) {
			dbgr_cs_add( 'ga4', 'order:' . $i );
		}
		dbgr_test_advance( 120 );
		$summary = DoughBoss_Growth_Outbox::dispatch();
		assert_same( 10, $summary['claimed'], 'ten claimed' );
		assert_same( 10, $called, 'ten delivered' );
		$pending = $GLOBALS['wpdb']->sqlite_raw( "SELECT COUNT(*) AS n, MAX(attempts) AS a FROM wp_doughboss_growth_outbox WHERE status = 'pending'" );
		assert_same( 2, (int) $pending[0]['n'], 'two are still pending' );
		assert_same( 0, (int) $pending[0]['a'], 'with no attempt burned' );
		assert_true( false !== wp_next_scheduled( DoughBoss_Growth_Outbox::CRON_HOOK ), 'and the cron stays' );
		assert_same( 2, DoughBoss_Growth_Outbox::dispatch()['sent'], 'the next run takes the rest' );
	}
);

db_test(
	'WP-5: a run that has used its time budget stops claiming; unclaimed rows stay pending and nothing is left in flight',
	function () {
		dbgr_cs_boot();
		DoughBoss_Growth_Outbox::set_time_budget_override( 100 );
		$called = 0;
		$shrink = true;
		DoughBoss_Growth_Outbox::register_channel( 'ga4', function () use ( &$called, &$shrink ) {
			$called++;
			if ( $shrink ) {
				DoughBoss_Growth_Outbox::set_time_budget_override( 0 ); // As if this delivery had used the whole budget.
			}
			return true;
		} );
		for ( $i = 1; $i <= 5; $i++ ) {
			dbgr_cs_add( 'ga4', 'order:' . $i );
		}
		dbgr_test_advance( 120 );
		$summary = DoughBoss_Growth_Outbox::dispatch();
		assert_same( 1, $summary['claimed'], 'only the first row was claimed' );
		assert_same( 1, $called, 'only one delivery' );
		$by_status = array();
		foreach ( dbgr_cs_rows() as $row ) {
			$by_status[ $row['status'] ] = isset( $by_status[ $row['status'] ] ) ? $by_status[ $row['status'] ] + 1 : 1;
		}
		assert_same( array( 'sent' => 1, 'pending' => 4 ), $by_status, 'one sent, four still pending, none in flight' );
		assert_true( false !== wp_next_scheduled( DoughBoss_Growth_Outbox::CRON_HOOK ), 'the cron stays so the rest go out next run' );

		DoughBoss_Growth_Outbox::set_time_budget_override( null );
		$shrink = false;
		$called = 0;
		DoughBoss_Growth_Outbox::dispatch();
		assert_same( 4, $called, 'control: with the default budget the next run takes the other four' );
	}
);

db_test(
	'WP-5: the in-flight quarantine rule is unchanged (an old claim is still parked, never re-sent)',
	function () {
		dbgr_cs_boot();
		$sent = 0;
		DoughBoss_Growth_Outbox::register_channel( 'ga4', function () use ( &$sent ) {
			$sent++;
			return true;
		} );
		dbgr_cs_add( 'ga4', 'order:1' );
		dbgr_test_advance( 120 );
		DoughBoss_Growth_Outbox::claim( (int) dbgr_cs_rows()[0]['id'] );
		dbgr_test_advance( DoughBoss_Growth_Outbox::LEASE_SECONDS + 10 );
		assert_same( 1, DoughBoss_Growth_Outbox::dispatch()['quarantined'], 'quarantined after the lease' );
		assert_same( 0, $sent, 'never re-sent' );
		assert_same( 600, DoughBoss_Growth_Outbox::LEASE_SECONDS, 'the lease is still 600 seconds' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* WP-9: the admin-side resume check is a safety net, not a per-request query                                  */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'WP-9: maybe_resume() runs no query for a visitor or a non-manager, and no transient is written for them',
	function () {
		dbgr_cs_boot();
		DoughBoss_Growth_Outbox::register_channel( 'ga4', '__return_true' );
		dbgr_cs_add( 'ga4', 'order:1' );
		wp_clear_scheduled_hook( DoughBoss_Growth_Outbox::CRON_HOOK ); // The normal idle state: a waiting row, no cron.
		$GLOBALS['wpdb']->reset_log();
		DoughBoss_Growth_Outbox::maybe_resume(); // Logged out (admin-ajax for a visitor).
		dbgr_test_login( array( 'read' ), 5 );
		DoughBoss_Growth_Outbox::maybe_resume(); // A subscriber.
		assert_same( array(), $GLOBALS['wpdb']->queries_matching( '/SELECT COUNT/' ), 'no count query for anyone who cannot manage the plugin' );
		assert_false( wp_next_scheduled( DoughBoss_Growth_Outbox::CRON_HOOK ), 'nothing was scheduled' );
		assert_false( get_transient( 'doughboss_growth_outbox_resume' ), 'and no transient was written' );
	}
);

db_test(
	'WP-9: for a manager maybe_resume() counts at most once per fifteen minutes (transient doughboss_growth_outbox_resume) and still restores the schedule',
	function () {
		dbgr_cs_boot();
		DoughBoss_Growth_Outbox::register_channel( 'ga4', '__return_true' );
		dbgr_cs_add( 'ga4', 'order:1' );
		wp_clear_scheduled_hook( DoughBoss_Growth_Outbox::CRON_HOOK );
		dbgr_test_login( array( 'manage_doughboss' ) );
		$GLOBALS['wpdb']->reset_log();
		DoughBoss_Growth_Outbox::maybe_resume();
		assert_same( 1, count( $GLOBALS['wpdb']->queries_matching( '/SELECT COUNT/' ) ), 'one count' );
		assert_true( false !== wp_next_scheduled( DoughBoss_Growth_Outbox::CRON_HOOK ), 'the schedule is restored for the waiting row' );
		assert_true( false !== get_transient( 'doughboss_growth_outbox_resume' ), 'the timing transient is set' );
		assert_same( 900, $GLOBALS['dbgr_transients']['doughboss_growth_outbox_resume']['expires'] - dbgr_test_now(), 'for fifteen minutes' );

		wp_clear_scheduled_hook( DoughBoss_Growth_Outbox::CRON_HOOK );
		$GLOBALS['wpdb']->reset_log();
		DoughBoss_Growth_Outbox::maybe_resume();
		DoughBoss_Growth_Outbox::maybe_resume();
		assert_same( array(), $GLOBALS['wpdb']->queries_matching( '/SELECT COUNT/' ), 'inside the fifteen minutes there is no further count' );
		dbgr_test_advance( 901 );
		DoughBoss_Growth_Outbox::maybe_resume();
		assert_same( 1, count( $GLOBALS['wpdb']->queries_matching( '/SELECT COUNT/' ) ), 'after fifteen minutes it counts again' );
		assert_true( false !== wp_next_scheduled( DoughBoss_Growth_Outbox::CRON_HOOK ), 'and restores the schedule again' );

		// Already scheduled: no count and no transient churn.
		$GLOBALS['wpdb']->reset_log();
		DoughBoss_Growth_Outbox::maybe_resume();
		assert_same( array(), $GLOBALS['wpdb']->queries_matching( '/SELECT COUNT/' ), 'a scheduled outbox is never counted' );
	}
);

db_test(
	'WP-9 and 9b: a failed count in maybe_resume() is listed, not read as "nothing is waiting"',
	function () {
		dbgr_cs_boot();
		DoughBoss_Growth_Outbox::register_channel( 'ga4', '__return_true' );
		dbgr_cs_add( 'ga4', 'order:1' );
		wp_clear_scheduled_hook( DoughBoss_Growth_Outbox::CRON_HOOK );
		dbgr_test_login( array( 'manage_doughboss' ) );
		$GLOBALS['wpdb']->fail_on( '/^SELECT COUNT\(\*\) FROM wp_doughboss_growth_outbox WHERE status = \'pending\'/' );
		DoughBoss_Growth_Outbox::maybe_resume();
		assert_false( wp_next_scheduled( DoughBoss_Growth_Outbox::CRON_HOOK ), 'nothing scheduled (the count is unknown)' );
		assert_true( null !== dbgr_cs_failure( 'outbox_read_failed' ), 'but the failed read is listed' );
	}
);

db_test(
	'WP-9: the resume check is still hooked on admin_init, and the transient name stays inside the companion namespace',
	function () {
		DoughBoss_Growth_Outbox::init();
		assert_true( false !== has_action( 'admin_init', array( 'DoughBoss_Growth_Outbox', 'maybe_resume' ) ), 'maybe_resume is on admin_init' );
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-growth-outbox.php' );
		assert_matches( "/'doughboss_growth_outbox_resume'/", $source, 'the transient is named doughboss_growth_outbox_resume' );
	}
);

// Leave the process environment clean for the tests that run after this file.
dbgr_cs_env( array() );
