<?php
/**
 * WP-01 tests: the shared outbox (enqueue, idempotency, single-winner claim, retries and backoff, quarantine).
 *
 * Runs against the harness's in-memory SQLite database with the outbox's REAL CREATE TABLE statement, so the
 * UNIQUE (channel, event_id) key and the conditional UPDATE claim behave for real. No handler here makes a real
 * request: the one that uses the HTTP wrapper goes through the fake transport.
 *
 * @package DoughBoss_Growth
 */

/**
 * SQLite database with the outbox table, the outbox booted (cron schedule filter registered).
 *
 * @return void
 */
function dbgr_outbox_sqlite() {
	$GLOBALS['wpdb']->use_sqlite();
	foreach ( DoughBoss_Growth_Outbox::schema() as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
	DoughBoss_Growth_Outbox::init();
}

/**
 * All outbox rows.
 *
 * @return array
 */
function dbgr_outbox_rows() {
	return $GLOBALS['wpdb']->sqlite_raw( 'SELECT * FROM wp_doughboss_growth_outbox ORDER BY id ASC' );
}

/**
 * Queue one harmless event.
 *
 * @param string $channel  Channel.
 * @param string $event_id Event id.
 * @return string Enqueue result.
 */
function dbgr_outbox_add( $channel = 'ga4', $event_id = 'order:1001' ) {
	return DoughBoss_Growth_Outbox::enqueue( $channel, 'purchase', $event_id, 'order', '1001', array( 'value_cents' => 1250, 'currency' => 'AUD' ) );
}

db_test(
	'outbox enqueue: queues once; the same (channel, event_id) is a duplicate; another channel is separate',
	function () {
		dbgr_outbox_sqlite();
		assert_same( 'queued', dbgr_outbox_add( 'ga4', 'order:1001' ), 'first enqueue' );
		assert_same( 'duplicate', dbgr_outbox_add( 'ga4', 'order:1001' ), 'replay is a duplicate' );
		assert_same( 'duplicate', dbgr_outbox_add( 'ga4', 'order:1001' ), 'replayed again' );
		assert_same( 'queued', dbgr_outbox_add( 'meta', 'order:1001' ), 'same event id on another channel is allowed' );
		assert_same( 'queued', dbgr_outbox_add( 'ga4', 'order:1002' ), 'another event id is allowed' );
		$rows = dbgr_outbox_rows();
		assert_count( 3, $rows, 'three rows, not five' );
		assert_same( 'pending', $rows[0]['status'], 'new rows are pending' );
		assert_same( 0, (int) $rows[0]['attempts'], 'no attempts yet' );
		assert_same( '{"value_cents":1250,"currency":"AUD"}', $rows[0]['payload_json'], 'payload stored as JSON' );
		assert_same( array(), $GLOBALS['wpdb']->unprepared, 'every statement was prepared' );
	}
);

db_test(
	'outbox enqueue: invalid identifiers, personal data and oversize payloads are rejected and nothing is stored',
	function () {
		dbgr_outbox_sqlite();
		$bad = array(
			'newline in channel' => array( "ga4\n", 'purchase', 'order:1', 'order', '1', array( 'a' => 1 ) ),
			'newline in event id' => array( 'ga4', 'purchase', "order:1\n", 'order', '1', array( 'a' => 1 ) ),
			'bad channel'        => array( 'GA4', 'purchase', 'order:1', 'order', '1', array( 'a' => 1 ) ),
			'channel too short'  => array( 'x', 'purchase', 'order:1', 'order', '1', array( 'a' => 1 ) ),
			'event name spaces'  => array( 'ga4', 'my event', 'order:1', 'order', '1', array( 'a' => 1 ) ),
			'event id too long'  => array( 'ga4', 'purchase', str_repeat( 'x', 121 ), 'order', '1', array( 'a' => 1 ) ),
			'event id sql chars' => array( 'ga4', 'purchase', "o';--", 'order', '1', array( 'a' => 1 ) ),
			'subject type caps'  => array( 'ga4', 'purchase', 'order:1', 'ORDER', '1', array( 'a' => 1 ) ),
			'non-string id'      => array( 'ga4', 'purchase', 1001, 'order', '1', array( 'a' => 1 ) ),
			'email key'          => array( 'ga4', 'purchase', 'order:1', 'order', '1', array( 'email' => 'jane@example.com' ) ),
			'name key'           => array( 'ga4', 'purchase', 'order:1', 'order', '1', array( 'customer_name' => 'Jane' ) ),
			'phone key'          => array( 'ga4', 'purchase', 'order:1', 'order', '1', array( 'phone' => '0412345678' ) ),
			'address key'        => array( 'ga4', 'purchase', 'order:1', 'order', '1', array( 'address' => '1 Example St' ) ),
			'ip key'             => array( 'ga4', 'purchase', 'order:1', 'order', '1', array( 'ip_address' => '203.0.113.9' ) ),
			'user agent key'     => array( 'ga4', 'purchase', 'order:1', 'order', '1', array( 'user_agent' => 'Mozilla' ) ),
			'nested email key'   => array( 'ga4', 'purchase', 'order:1', 'order', '1', array( 'user' => array( 'email' => 'x@y.com' ) ) ),
			'email-shaped value' => array( 'ga4', 'purchase', 'order:1', 'order', '1', array( 'note' => 'contact jane@example.com' ) ),
			'plain hash key'     => array( 'meta', 'purchase', 'order:1', 'order', '1', array( 'em' => 'jane@example.com' ) ),
			'hash while barred'  => array( 'meta', 'purchase', 'order:1', 'order', '1', array( 'em' => str_repeat( 'a', 64 ) ) ),
			'oversize'           => array( 'ga4', 'purchase', 'order:1', 'order', '1', array( 'blob' => str_repeat( 'x', 61000 ) ) ),
		);
		foreach ( $bad as $label => $args ) {
			assert_same( 'rejected', call_user_func_array( array( 'DoughBoss_Growth_Outbox', 'enqueue' ), $args ), 'rejected: ' . $label );
		}
		assert_count( 0, dbgr_outbox_rows(), 'nothing was stored by any rejected enqueue' );
		assert_same( array(), $GLOBALS['dbgr_cron'], 'a rejected enqueue schedules nothing' );
	}
);

db_test(
	'outbox payload PII: hashed identifiers pass only when send_hashed_identifiers is on and the value is a 64-char hex digest',
	function () {
		dbgr_outbox_sqlite();
		$hash = hash( 'sha256', 'jane@example.com' );
		assert_same( 'rejected', DoughBoss_Growth_Outbox::enqueue( 'meta', 'purchase', 'order:7', 'order', '7', array( 'em' => $hash ) ), 'a hash is refused while the setting is off' );
		DoughBoss_Growth_Settings::apply_save( array( 'send_hashed_identifiers' => '1' ) );
		assert_same( 'queued', DoughBoss_Growth_Outbox::enqueue( 'meta', 'purchase', 'order:7', 'order', '7', array( 'em' => $hash ) ), 'a real digest is accepted once enabled' );
		assert_same( 'rejected', DoughBoss_Growth_Outbox::enqueue( 'meta', 'purchase', 'order:8', 'order', '8', array( 'em' => 'jane@example.com' ) ), 'a plain address is still refused' );
		assert_same( 'rejected', DoughBoss_Growth_Outbox::enqueue( 'meta', 'purchase', 'order:9', 'order', '9', array( 'em' => 'ABCDEF' ) ), 'a short or upper-case value is refused' );
		assert_same( 'rejected', DoughBoss_Growth_Outbox::enqueue( 'meta', 'purchase', 'order:10', 'order', '10', array( 'ph' => hash( 'sha256', '0412345678' ), 'email' => 'x@y.com' ) ), 'a hash does not excuse a raw email key beside it' );
		assert_true( DoughBoss_Growth_Outbox::payload_has_pii( new stdClass() ), 'an object payload counts as personal data (cannot be inspected)' );
		assert_false( DoughBoss_Growth_Outbox::payload_has_pii( array( 'value_cents' => 100, 'items' => array( array( 'id' => 'x', 'qty' => 2 ) ) ) ), 'a clean nested payload passes' );
	}
);

db_test(
	'outbox enqueue: a storage error returns "error" (nothing is claimed delivered) and schedules nothing',
	function () {
		dbgr_outbox_sqlite();
		$GLOBALS['wpdb']->fail_all( true );
		assert_same( 'error', dbgr_outbox_add(), 'a database error is reported' );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( array(), $GLOBALS['dbgr_cron'], 'no cron event was scheduled' );
		assert_count( 0, dbgr_outbox_rows(), 'no row' );
	}
);

db_test(
	'outbox cron: enqueue schedules doughboss_growth_outbox_dispatch on the custom 5-minute schedule, once',
	function () {
		dbgr_outbox_sqlite();
		dbgr_outbox_add( 'ga4', 'order:1' );
		dbgr_outbox_add( 'ga4', 'order:2' );
		assert_same( DBGR_TEST_EPOCH + 60, wp_next_scheduled( 'doughboss_growth_outbox_dispatch' ), 'first run one minute after the first enqueue' );
		assert_count( 1, $GLOBALS['dbgr_cron'], 'a single event, not one per enqueue' );
		$schedules = apply_filters( 'cron_schedules', array() );
		assert_same( 300, $schedules['doughboss_growth_5min']['interval'], 'the schedule is five minutes' );
		assert_same( 'doughboss_growth_5min', DoughBoss_Growth_Outbox::CRON_SCHEDULE, 'schedule name' );
		assert_same( 300, apply_filters( 'cron_schedules', array( 'hourly' => array( 'interval' => 3600, 'display' => 'x' ) ) )['doughboss_growth_5min']['interval'], 'existing schedules are kept' );
		assert_same( array( 'interval' => 300, 'display' => 'Every five minutes (DoughBoss Growth)' ), apply_filters( 'cron_schedules', 'not-an-array' )['doughboss_growth_5min'], 'a broken filter input is repaired' );
	}
);

db_test(
	'outbox backoff: 60, 300, 1800, 1800, 1800 seconds, capped at the last value',
	function () {
		assert_same( array( 60, 300, 1800, 1800, 1800 ), DoughBoss_Growth_Outbox::BACKOFF_SECONDS, 'frozen backoff table' );
		assert_same( 5, DoughBoss_Growth_Outbox::MAX_ATTEMPTS, 'five attempts' );
		foreach ( array( 1 => 60, 2 => 300, 3 => 1800, 4 => 1800, 5 => 1800, 9 => 1800, 0 => 60, -3 => 60 ) as $attempts => $seconds ) {
			assert_same( $seconds, DoughBoss_Growth_Outbox::backoff_for( $attempts ), 'backoff after ' . $attempts . ' failed attempts' );
		}
	}
);

db_test(
	'outbox channel registration validates its arguments',
	function () {
		assert_false( DoughBoss_Growth_Outbox::register_channel( 'GA4', '__return_true' ), 'upper case refused' );
		assert_false( DoughBoss_Growth_Outbox::register_channel( 'g', '__return_true' ), 'too short refused' );
		assert_false( DoughBoss_Growth_Outbox::register_channel( 'has space', '__return_true' ), 'space refused' );
		assert_false( DoughBoss_Growth_Outbox::register_channel( 'ga4', 'no_such_function_here' ), 'non-callable refused' );
		assert_same( array(), DoughBoss_Growth_Outbox::channels(), 'nothing was registered by the refusals' );
		assert_true( DoughBoss_Growth_Outbox::register_channel( 'ga4', '__return_true' ), 'valid registration' );
		assert_same( array( 'ga4' ), DoughBoss_Growth_Outbox::channels(), 'listed' );
	}
);

db_test(
	'outbox dispatch: a handler that returns true marks the row sent; sent rows are never re-sent',
	function () {
		dbgr_outbox_sqlite();
		$seen = array();
		DoughBoss_Growth_Outbox::register_channel(
			'ga4',
			function ( $row ) use ( &$seen ) {
				$seen[] = $row;
				return true;
			}
		);
		dbgr_outbox_add( 'ga4', 'order:1001' );
		dbgr_test_advance( 120 );
		$summary = DoughBoss_Growth_Outbox::dispatch();
		assert_same( array( 'claimed' => 1, 'sent' => 1, 'retried' => 0, 'terminal' => 0, 'quarantined' => 0 ), $summary, 'summary' );
		assert_count( 1, $seen, 'handler called once' );
		assert_same( 'order:1001', $seen[0]['event_id'], 'handler receives the row' );
		assert_same( array( 'value_cents' => 1250, 'currency' => 'AUD' ), $seen[0]['payload'], 'handler receives the DECODED payload' );
		assert_false( isset( $seen[0]['payload_json'] ), 'the raw JSON column is not passed on' );
		$rows = dbgr_outbox_rows();
		assert_same( 'sent', $rows[0]['status'], 'row is sent' );
		assert_same( 1, (int) $rows[0]['attempts'], 'one attempt recorded' );
		// Another sweep sends nothing and clears the cron.
		$again = DoughBoss_Growth_Outbox::dispatch();
		assert_same( 0, $again['claimed'], 'nothing left to claim' );
		assert_count( 1, $seen, 'the sent row is never re-sent' );
		assert_false( wp_next_scheduled( 'doughboss_growth_outbox_dispatch' ), 'the cron is cleared once nothing is waiting' );
		// A replay of the same event is a duplicate even after delivery (no double count).
		assert_same( 'duplicate', dbgr_outbox_add( 'ga4', 'order:1001' ), 'replay after delivery is still a duplicate' );
	}
);

db_test(
	'outbox retries: failures back off 60, 300, 1800, 1800 and park as failed_terminal on the fifth attempt',
	function () {
		dbgr_outbox_sqlite();
		$calls = 0;
		DoughBoss_Growth_Outbox::register_channel(
			'ga4',
			function () use ( &$calls ) {
				$calls++;
				return new WP_Error( 'upstream_503', 'Service unavailable for jane@example.com' );
			}
		);
		dbgr_outbox_add( 'ga4', 'order:1001' );
		$t0 = DBGR_TEST_EPOCH;

		$expected_waits = array( 1 => 60, 2 => 300, 3 => 1800, 4 => 1800 );
		for ( $attempt = 1; $attempt <= 4; $attempt++ ) {
			dbgr_test_set_time( $t0 + 100000 * $attempt );
			$summary = DoughBoss_Growth_Outbox::dispatch();
			assert_same( 1, $summary['retried'], 'attempt ' . $attempt . ' is retried' );
			$row = dbgr_outbox_rows()[0];
			assert_same( 'pending', $row['status'], 'attempt ' . $attempt . ': back to pending' );
			assert_same( $attempt, (int) $row['attempts'], 'attempt ' . $attempt . ': counter' );
			assert_same( gmdate( 'Y-m-d H:i:s', $t0 + 100000 * $attempt + $expected_waits[ $attempt ] ), $row['next_attempt_at'], 'attempt ' . $attempt . ': next attempt after ' . $expected_waits[ $attempt ] . ' s' );
			assert_same( 'upstream_503', $row['last_error'], 'error code recorded without the message text' );
			// Not due yet: a sweep one second before the retry time does nothing.
			dbgr_test_set_time( $t0 + 100000 * $attempt + $expected_waits[ $attempt ] - 1 );
			assert_same( 0, DoughBoss_Growth_Outbox::dispatch()['claimed'], 'attempt ' . $attempt . ': not claimed before it is due' );
		}
		assert_same( 4, $calls, 'four calls so far' );

		dbgr_test_set_time( $t0 + 100000 * 5 );
		$summary = DoughBoss_Growth_Outbox::dispatch();
		assert_same( 1, $summary['terminal'], 'the fifth failure is terminal' );
		$row = dbgr_outbox_rows()[0];
		assert_same( 'failed_terminal', $row['status'], 'parked for an operator' );
		assert_same( 5, (int) $row['attempts'], 'five attempts' );
		assert_not_contains( 'jane@example.com', $row['last_error'], 'no personal data in the stored error' );
		dbgr_test_advance( 10 * DAY_IN_SECONDS );
		DoughBoss_Growth_Outbox::dispatch();
		assert_same( 5, $calls, 'a terminal row is never retried' );
	}
);

db_test(
	'outbox outcomes: terminal errors park at once; exceptions, Errors, bad returns and a missing handler are retryable and never throw',
	function () {
		dbgr_outbox_sqlite();
		DoughBoss_Growth_Outbox::register_channel(
			'ga4',
			function ( $row ) {
				switch ( $row['event_id'] ) {
					case 'terminal':
						return new WP_Error( 'bad_request', 'No', array( 'terminal' => true ) );
					case 'exception':
						throw new RuntimeException( 'boom jane@example.com' );
					case 'error':
						return intdiv( 1, 0 );
					case 'falsy':
						return false;
					case 'string':
						return 'sent';
					default:
						return true;
				}
			}
		);
		foreach ( array( 'terminal', 'exception', 'error', 'falsy', 'string', 'fine' ) as $id ) {
			DoughBoss_Growth_Outbox::enqueue( 'ga4', 'purchase', $id, 'order', '1', array( 'a' => 1 ) );
		}
		dbgr_test_advance( 120 );
		$summary = DoughBoss_Growth_Outbox::dispatch();
		assert_same( 6, $summary['claimed'], 'six claimed' );
		assert_same( 1, $summary['sent'], 'only the handler that returned true is sent' );
		assert_same( 1, $summary['terminal'], 'terminal error parked immediately' );
		assert_same( 4, $summary['retried'], 'exception, Error, false and a non-true value are retryable' );
		$by_id = array();
		foreach ( dbgr_outbox_rows() as $row ) {
			$by_id[ $row['event_id'] ] = $row;
		}
		assert_same( 'failed_terminal', $by_id['terminal']['status'], 'terminal parked' );
		assert_same( 'bad_request', $by_id['terminal']['last_error'], 'its error code' );
		assert_same( 'handler_exception', $by_id['exception']['last_error'], 'exception recorded by class of failure only' );
		assert_same( 'handler_error', $by_id['error']['last_error'], 'Error recorded' );
		assert_same( 'handler_failed', $by_id['falsy']['last_error'], 'false recorded' );
		assert_same( 'sent', $by_id['fine']['status'], 'good row sent' );
		foreach ( $by_id as $row ) {
			assert_not_contains( 'jane@example.com', $row['last_error'], 'no personal data stored' );
		}
	}
);

db_test(
	'outbox claim: exactly one worker wins a row (single-winner), including a re-entrant sweep from inside the handler',
	function () {
		dbgr_outbox_sqlite();
		dbgr_outbox_add( 'ga4', 'order:1001' );
		dbgr_test_advance( 120 );
		$id    = (int) dbgr_outbox_rows()[0]['id'];
		$first = DoughBoss_Growth_Outbox::claim( $id );
		assert_true( is_array( $first ) && 'in_flight' === $first['status'], 'the first claim wins and sees an in_flight row' );
		assert_same( null, DoughBoss_Growth_Outbox::claim( $id ), 'the second claim of the same row loses' );
		assert_same( null, DoughBoss_Growth_Outbox::claim( $id ), 'and the third' );
		assert_same( null, DoughBoss_Growth_Outbox::claim( 99999 ), 'a missing row cannot be claimed' );

		// Fresh row; a second worker sweeps while the first is inside the handler.
		$GLOBALS['wpdb'] = new DBGR_Test_WPDB();
		dbgr_outbox_sqlite();
		DoughBoss_Growth_Outbox::reset_handlers();
		$deliveries = 0;
		$nested     = null;
		DoughBoss_Growth_Outbox::register_channel(
			'ga4',
			function () use ( &$deliveries, &$nested ) {
				$deliveries++;
				$nested = DoughBoss_Growth_Outbox::dispatch(); // A concurrent cron run.
				return true;
			}
		);
		dbgr_outbox_add( 'ga4', 'order:2002' );
		dbgr_test_advance( 120 );
		DoughBoss_Growth_Outbox::dispatch();
		assert_same( 1, $deliveries, 'the handler ran exactly once' );
		assert_same( 0, $nested['claimed'], 'the concurrent sweep claimed nothing' );
		assert_same( 'sent', dbgr_outbox_rows()[0]['status'], 'row ends sent' );
	}
);

db_test(
	'outbox claim: scripted database proves the claim is a conditional UPDATE keyed on status and due time',
	function () {
		$GLOBALS['wpdb']->respond( '/^UPDATE .* SET status = \'in_flight\'/', 0 );
		assert_same( null, DoughBoss_Growth_Outbox::claim( 5 ), 'zero rows affected means the claim was lost' );
		$sql = $GLOBALS['wpdb']->queries_matching( '/in_flight/' );
		assert_contains( "status = 'pending'", $sql[0], 'only pending rows can be claimed' );
		assert_contains( 'next_attempt_at <=', $sql[0], 'only due rows can be claimed' );
		assert_contains( 'id = 5', $sql[0], 'by id' );
		assert_count( 0, $GLOBALS['wpdb']->queries_matching( '/^SELECT \* FROM/' ), 'the row is not even read when the claim is lost' );
	}
);

db_test(
	'outbox quarantine: a stale in_flight row is parked as ambiguous and is NEVER re-sent automatically',
	function () {
		dbgr_outbox_sqlite();
		$sent = 0;
		DoughBoss_Growth_Outbox::register_channel(
			'ga4',
			function () use ( &$sent ) {
				$sent++;
				return true;
			}
		);
		dbgr_outbox_add( 'ga4', 'order:1001' );
		dbgr_test_advance( 120 );
		DoughBoss_Growth_Outbox::claim( (int) dbgr_outbox_rows()[0]['id'] ); // A worker takes it, then dies.
		dbgr_test_advance( DoughBoss_Growth_Outbox::LEASE_SECONDS - 10 );
		$summary = DoughBoss_Growth_Outbox::dispatch();
		assert_same( 0, $summary['quarantined'], 'inside the lease the claim is honoured' );
		assert_same( 'in_flight', dbgr_outbox_rows()[0]['status'], 'still in flight' );
		dbgr_test_advance( 30 );
		$summary = DoughBoss_Growth_Outbox::dispatch();
		assert_same( 1, $summary['quarantined'], 'past the lease it is quarantined' );
		$row = dbgr_outbox_rows()[0];
		assert_same( 'failed_terminal', $row['status'], 'parked for an operator' );
		assert_same( 'ambiguous_in_flight', $row['last_error'], 'reason recorded' );
		assert_same( 0, $sent, 'the handler never ran: an ambiguous send is not repeated (it could double-count a conversion)' );
		dbgr_test_advance( DAY_IN_SECONDS );
		DoughBoss_Growth_Outbox::dispatch();
		assert_same( 0, $sent, 'and a later sweep does not resurrect it either' );
	}
);

db_test(
	'outbox dispatch: rows of channels with no registered handler are left alone and the cron stops',
	function () {
		dbgr_outbox_sqlite();
		dbgr_outbox_add( 'ga4', 'order:1001' );
		assert_true( false !== wp_next_scheduled( 'doughboss_growth_outbox_dispatch' ), 'scheduled by the enqueue' );
		dbgr_test_advance( 120 );
		$summary = DoughBoss_Growth_Outbox::dispatch();
		assert_same( 0, $summary['claimed'], 'no handler: nothing claimed' );
		assert_same( 'pending', dbgr_outbox_rows()[0]['status'], 'the row is untouched' );
		assert_same( 0, (int) dbgr_outbox_rows()[0]['attempts'], 'no attempt burned' );
		assert_false( wp_next_scheduled( 'doughboss_growth_outbox_dispatch' ), 'the cron is cleared while no feature uses the outbox' );

		// A different channel's handler does not touch the ga4 row.
		DoughBoss_Growth_Outbox::register_channel( 'meta', '__return_true' );
		DoughBoss_Growth_Outbox::dispatch();
		assert_same( 'pending', dbgr_outbox_rows()[0]['status'], 'a meta handler leaves the ga4 row alone' );

		// maybe_resume(): once a handler exists and a row waits, the schedule comes back.
		DoughBoss_Growth_Outbox::reset_handlers();
		DoughBoss_Growth_Outbox::register_channel( 'ga4', '__return_true' );
		DoughBoss_Growth_Outbox::maybe_resume();
		assert_true( false !== wp_next_scheduled( 'doughboss_growth_outbox_dispatch' ), 'schedule restored for a waiting row' );
	}
);

db_test(
	'outbox dispatch: respects the kill switch (sub-process) and a storage error mid-sweep',
	function () {
		dbgr_outbox_sqlite();
		DoughBoss_Growth_Outbox::register_channel( 'ga4', '__return_true' );
		dbgr_outbox_add( 'ga4', 'order:1001' );
		dbgr_test_advance( 120 );
		$GLOBALS['wpdb']->fail_all( true );
		$summary = DoughBoss_Growth_Outbox::dispatch();
		assert_same( 0, $summary['claimed'], 'a database error during the sweep delivers nothing' );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( 'pending', dbgr_outbox_rows()[0]['status'], 'the row stays pending' );

		if ( ! dbgr_test_can_subprocess() ) {
			dbgr_test_skip( 'sub-process unavailable: kill switch not exercised' );
			return;
		}
		$code   = <<<'CODE'
$GLOBALS['wpdb']->use_sqlite();
foreach ( DoughBoss_Growth_Outbox::schema() as $sql ) { $GLOBALS['wpdb']->create_table_from_mysql( $sql ); }
$called = 0;
DoughBoss_Growth_Outbox::register_channel( 'ga4', function () use ( &$called ) { $called++; return true; } );
$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( "INSERT INTO wp_doughboss_growth_outbox (channel,event_name,event_id,subject_type,subject_id,payload_json,status,attempts,next_attempt_at,last_error,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,'pending',0,%s,'',%s,%s)", 'ga4', 'purchase', 'o:1', 'order', '1', '{}', '2000-01-01 00:00:00', '2000-01-01 00:00:00', '2000-01-01 00:00:00' ) );
$summary = DoughBoss_Growth_Outbox::dispatch();
echo json_encode( array( 'called' => $called, 'claimed' => $summary['claimed'] ) );
CODE;
		$result = dbgr_test_subprocess( $code, "define( 'DOUGHBOSS_GROWTH_DISABLE', true );" );
		assert_same( '{"called":0,"claimed":0}', $result['out'], 'with the kill switch nothing is delivered (' . $result['err'] . ')' );
		$control = dbgr_test_subprocess( $code );
		assert_same( '{"called":1,"claimed":1}', $control['out'], 'control: without the kill switch the same row is delivered' );
	}
);

db_test(
	'outbox + http: a real channel handler goes through the wrapper and the FAKE transport; zero real requests',
	function () {
		dbgr_outbox_sqlite();
		DoughBoss_Growth_Outbox::register_channel(
			'webhook',
			function ( $row ) {
				$result = DoughBoss_Growth_Http::request( 'POST', 'https://hooks.example-receiver.com.au/in', array( 'json' => $row['payload'] ) );
				if ( $result['ok'] ) {
					return true;
				}
				return new WP_Error( $result['error'], 'failed', array( 'terminal' => ! $result['retryable'] ) );
			}
		);
		DoughBoss_Growth_Outbox::enqueue( 'webhook', 'waitlist_confirmed', 'w:1', 'waitlist', '1', array( 'event' => 'waitlist.confirmed', 'id' => 1 ) );
		DoughBoss_Growth_Outbox::enqueue( 'webhook', 'waitlist_confirmed', 'w:2', 'waitlist', '2', array( 'event' => 'waitlist.confirmed', 'id' => 2 ) );
		dbgr_test_http_expect( 'https://hooks.example-receiver.com.au/in', dbgr_test_http_response( 200, 'ok' ) );
		dbgr_test_http_expect( 'https://hooks.example-receiver.com.au/in', dbgr_test_http_response( 400, 'rejected' ) );
		dbgr_test_advance( 120 );
		$summary = DoughBoss_Growth_Outbox::dispatch();
		assert_same( 1, $summary['sent'], 'the 200 is sent' );
		assert_same( 1, $summary['terminal'], 'the 400 is terminal (not retryable)' );
		dbgr_test_http_assert_done( 'both declared calls were made, nothing else' );
		assert_count( 2, dbgr_test_http_calls(), 'exactly two transport calls' );
		foreach ( dbgr_test_http_calls() as $call ) {
			assert_false( $call['unexpected'], 'no unexpected call' );
		}
	}
);

db_test(
	'outbox schema: frozen columns, UNIQUE (channel, event_id) and a due-time index, using only the companion table',
	function () {
		$sql = DoughBoss_Growth_Outbox::schema();
		assert_count( 1, $sql, 'one statement' );
		assert_matches( '/^CREATE TABLE wp_doughboss_growth_outbox \(/', $sql[0], 'table name' );
		foreach ( array( 'channel varchar(20)', 'event_name varchar(64)', 'event_id varchar(120)', 'subject_type varchar(20)', 'subject_id varchar(64)', 'payload_json longtext', 'status varchar(20)', 'attempts smallint(5) unsigned', 'next_attempt_at datetime', 'last_error varchar(190)', 'UNIQUE KEY channel_event (channel,event_id)', 'KEY due (status,next_attempt_at)', 'PRIMARY KEY  (id)' ) as $fragment ) {
			assert_contains( $fragment, $sql[0], 'schema has: ' . $fragment );
		}
		dbgr_outbox_sqlite();
		assert_same( 'queued', dbgr_outbox_add(), 'the production DDL accepts a row' );
		$GLOBALS['wpdb']->reset_log();
		assert_same( 0, count( $GLOBALS['wpdb']->foreign_writes ), 'no write outside the companion tables' );
	}
);
