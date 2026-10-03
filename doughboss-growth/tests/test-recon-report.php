<?php
/**
 * Timesheet reconciliation report tests (WP-11): run model end to end on SQLite with the real DDL, fail-closed
 * statuses (NOT_RUN, FAILED) that keep the previous result, run lock, change detection, business days, CSV,
 * data minimisation, zero writes to core tables, and the core 2.44.0 location map rule.
 *
 * @package DoughBoss_Growth
 */

DoughBoss_Growth::load_module( 'recon' );

/**
 * Parameters used by these tests (synthetic test values; the plugin ships none).
 *
 * @return array
 */
function dbgr_recon_test_params() {
	return array(
		'start_tolerance_minutes'   => '5',
		'end_tolerance_minutes'     => '5',
		'break_tolerance_minutes'   => '5',
		'net_tolerance_minutes'     => '10',
		'open_alert_after_minutes'  => '720',
		'max_shift_minutes'         => '720',
		'near_miss_search_minutes'  => '120',
		'lookback_days'             => '0',
		'business_day_cutoff_local' => array(
			1 => '04:00',
			2 => '04:00',
		),
	);
}

/**
 * The standard world: two mapped shops, two mapped staff, one unmapped staff member optional, a clean pair,
 * a missing-in-Square shift, and a Square-only timecard. Clock at 2026-10-16 12:00 Sydney.
 *
 * @param bool $with_unmapped Add a shift by an unmapped staff member.
 * @return array Raw Square timecards.
 */
function dbgr_recon_world( $with_unmapped = false ) {
	dbgr_recon_setup();
	dbgr_test_set_time( gmmktime( 1, 0, 0, 10, 16, 2026 ) );
	DoughBoss_Growth_Recon_Report::save_params( dbgr_recon_test_params() );
	dbgr_recon_map( 'location', 1, 'LREV1' );
	dbgr_recon_map( 'location', 2, 'LBNK2' );
	dbgr_recon_map( 'employee', 11, 'TM11' );
	dbgr_recon_map( 'employee', 12, 'TM12' );
	dbgr_recon_seed_shift( array( 'id' => 101, 'user' => 11, 'loc' => 1, 'in' => '2026-10-14 22:00:20', 'out' => '2026-10-15 06:00:40', 'breaks' => array( array( '2026-10-15 01:00:10', '2026-10-15 01:30:05' ) ) ) );
	dbgr_recon_seed_shift( array( 'id' => 102, 'user' => 12, 'loc' => 2, 'in' => '2026-10-14 21:00:00', 'out' => '2026-10-15 01:00:00', 'mc' => true ) );
	if ( $with_unmapped ) {
		dbgr_recon_seed_shift( array( 'id' => 103, 'user' => 13, 'loc' => 1, 'in' => '2026-10-14 23:00:00', 'out' => '2026-10-15 03:00:00' ) );
	}
	$cards = array(
		dbgr_recon_tc(
			'TCA1',
			'TM11',
			'LREV1',
			'2026-10-15T09:00:00+11:00',
			'2026-10-15T17:00:00+11:00',
			array(
				'breaks'     => array(
					array(
						'id'                => 'BK1',
						'start_at'          => '2026-10-15T01:00:00Z',
						'end_at'            => '2026-10-15T01:29:00Z',
						'is_paid'           => false,
						'break_type_id'     => 'BT1',
						'name'              => 'Lunch',
						'expected_duration' => 'PT30M',
					),
				),
				'updated_at' => '2026-10-15T06:00:20Z',
			)
		),
		dbgr_recon_tc( 'TCB2', 'TM12', 'LBNK2', '2026-10-15T04:00:00Z', '2026-10-15T06:00:00Z' ),
	);
	$GLOBALS['wpdb']->reset_log();
	return $cards;
}

db_test(
	'recon report: feature off means nothing happens at all',
	function () {
		dbgr_recon_world();
		update_option( 'doughboss_growth_settings', array( 'features' => array( 'timesheet_recon' => false ) ) );
		$GLOBALS['wpdb']->reset_log();
		$result = DoughBoss_Growth_Recon_Report::run( 'manual' );
		assert_same( array( 'status' => 'NOT_RUN', 'reason' => 'feature_off', 'run_id' => 0 ), $result, 'NOT_RUN feature_off' );
		assert_count( 0, $GLOBALS['wpdb']->queries, 'no query of any kind' );
		assert_count( 0, dbgr_test_http_calls(), 'no Square call' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon report: missing preconditions give NOT_RUN, recorded, with zero Square calls',
	function () {
		$cases = array(
			'square_token_missing'     => function () {
				dbgr_recon_env( 'DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN', null );
			},
			'square_env_missing'       => function () {
				dbgr_recon_env( 'DOUGHBOSS_GROWTH_SQUARE_ENV', 'live' );
			},
			'plugin_storage_not_ready' => function () {
				$GLOBALS['dbgr_recon_storage_ready'] = false;
			},
			'plugin_db_too_old'        => function () {
				dbgr_test_core_option( 'doughboss_db_version', '1.19.0' );
			},
			'no_location_mapping'      => function () {
				$GLOBALS['wpdb']->sqlite_raw( "DELETE FROM wp_doughboss_growth_recon_xref WHERE kind = 'location'" );
			},
			'invalid_dates'            => null,
		);
		foreach ( $cases as $reason => $break ) {
			dbgr_recon_world();
			if ( null !== $break ) {
				call_user_func( $break );
				$result = DoughBoss_Growth_Recon_Report::run( 'manual' );
			} else {
				$result = DoughBoss_Growth_Recon_Report::run( 'manual', '2026-10-15', '2026-10-14' );
			}
			assert_same( 'NOT_RUN', $result['status'], $reason . ': NOT_RUN' );
			assert_same( $reason, $result['reason'], $reason . ': reason recorded' );
			$run = $GLOBALS['wpdb']->sqlite_raw( 'SELECT status, reason_code, running_guard FROM wp_doughboss_growth_recon_run WHERE id = ' . (int) $result['run_id'] );
			assert_same( array( array( 'status' => 'NOT_RUN', 'reason_code' => $reason, 'running_guard' => null ) ), $run, $reason . ': run row finished and lock released' );
			assert_count( 0, dbgr_test_http_calls(), $reason . ': no Square call' );
			assert_same( null, DoughBoss_Growth_Recon_Report::latest_run(), $reason . ': never shown as a result' );
			$GLOBALS['dbgr_recon_storage_ready'] = true;
			dbgr_recon_teardown();
		}

		dbgr_recon_world();
		delete_option( 'doughboss_growth_db_version' );
		$result = DoughBoss_Growth_Recon_Report::run( 'manual' );
		assert_same( 'companion_storage_not_ready', $result['reason'], 'companion schema not installed: NOT_RUN without writing' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon report: a full run stores evidence rows, writes no core table and stores no personal data',
	function () {
		$cards = dbgr_recon_world();
		dbgr_recon_fake_square( $cards );
		$lines = array();
		add_action(
			'doughboss_growth_log',
			function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			}
		);
		$completed = array();
		add_action(
			'doughboss_growth_recon_completed',
			function ( $run_id, $status ) use ( &$completed ) {
				$completed[] = array( $run_id, $status );
			},
			10,
			2
		);
		$result = DoughBoss_Growth_Recon_Report::run( 'manual', null, null, 7 );
		assert_same( 'COMPLETE', $result['status'], 'run complete' );
		assert_same( array( array( $result['run_id'], 'COMPLETE' ) ), $completed, 'completion action fired once with id and status' );

		$run = DoughBoss_Growth_Recon_Report::latest_run();
		assert_same( (string) $result['run_id'], (string) $run['id'], 'latest finished run is this run' );
		assert_same( '2026-10-15', $run['report_from'], 'previous business day reported' );
		assert_same( '2026-10-15', $run['report_to'], 'lookback 0' );
		assert_same( 'production', $run['environment'], 'environment recorded' );
		assert_same( '7', (string) $run['actor_user_id'], 'actor id recorded (id only)' );

		$rows  = DoughBoss_Growth_Recon_Report::rows( $result['run_id'] );
		$state = array();
		foreach ( $rows as $row ) {
			$state[ $row['plugin_ids'] . '|' . $row['square_ids'] ] = $row['state'] . ':' . $row['codes'];
		}
		assert_same(
			array(
				'101|TCA1' => 'MATCHED:',
				'102|'     => 'REVIEW:MISSING_IN_SQUARE,MANAGER_CLOSED',
				'|TCB2'    => 'REVIEW:MISSING_IN_PLUGIN',
			),
			$state,
			'expected evidence rows'
		);
		$counts = json_decode( $run['counts_json'], true );
		assert_same( 1, $counts['total']['MATCHED'], 'summary counts stored' );

		assert_same( array(), dbgr_recon_core_writes(), 'zero writes to any core table (query log)' );
		assert_same( array(), $GLOBALS['wpdb']->foreign_writes, 'harness recorded no foreign write' );
		$dump = dbgr_recon_dump();
		$csv  = DoughBoss_Growth_Recon_Report::csv( $result['run_id'] );
		foreach ( dbgr_recon_forbidden_strings() as $needle ) {
			assert_not_contains( $needle, $dump, 'not stored in recon tables: ' . $needle );
			assert_not_contains( $needle, $csv, 'not in the CSV: ' . $needle );
			assert_not_contains( $needle, implode( "\n", $lines ), 'not in any log line: ' . $needle );
		}
		dbgr_recon_teardown();
	}
);

db_test(
	'recon report: an unmapped staff member makes the run INCOMPLETE, never clean',
	function () {
		$cards = dbgr_recon_world( true );
		dbgr_recon_fake_square( $cards );
		$result = DoughBoss_Growth_Recon_Report::run( 'cron' );
		assert_same( 'INCOMPLETE', $result['status'], 'INCOMPLETE' );
		assert_same( 'unmapped_items', $result['reason'], 'reason' );
		$unmapped = DoughBoss_Growth_Recon_Report::rows( $result['run_id'], 'UNMAPPED' );
		assert_count( 1, $unmapped, 'one blocker row' );
		assert_same( 'UNMAPPED_EMPLOYEE', $unmapped[0]['codes'], 'unmapped employee code' );
		assert_same( '13', (string) $unmapped[0]['user_id'], 'identified by id only' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon report: 429 and 5xx and wrong-filter responses give FAILED and keep the previous result',
	function () {
		$cards = dbgr_recon_world();
		dbgr_recon_fake_square( $cards );
		$first = DoughBoss_Growth_Recon_Report::run( 'manual' );
		assert_same( 'COMPLETE', $first['status'], 'first run complete' );
		$rows_before = count( DoughBoss_Growth_Recon_Report::rows( $first['run_id'] ) );

		foreach ( array( array( array( 'status_on' => array( 1 => 429 ) ), 'square_http_429' ), array( array( 'status_on' => array( 2 => 502 ) ), 'square_http_5xx' ), array( array( 'ignore_filter' => true ), 'square_scope_location' ) ) as $setup ) {
			$GLOBALS['dbgr_http'] = array( 'expect' => array(), 'calls' => array() );
			$extra                = $cards;
			$extra[]              = dbgr_recon_tc( 'TCZ9', 'TM11', 'LOTHERSHOP', '2026-10-15T00:00:00Z', '2026-10-15T01:00:00Z' );
			dbgr_recon_fake_square( $extra, $setup[0] );
			$failed = DoughBoss_Growth_Recon_Report::run( 'manual' );
			assert_same( 'FAILED', $failed['status'], $setup[1] . ': FAILED' );
			assert_same( $setup[1], $failed['reason'], $setup[1] . ': reason' );
			assert_count( 0, DoughBoss_Growth_Recon_Report::rows( $failed['run_id'] ), $setup[1] . ': the failed run left no rows' );
			$latest = DoughBoss_Growth_Recon_Report::latest_run();
			assert_same( (string) $first['run_id'], (string) $latest['id'], $setup[1] . ': the previous result is still the one shown' );
			assert_count( $rows_before, DoughBoss_Growth_Recon_Report::rows( $first['run_id'] ), $setup[1] . ': previous rows intact' );
			$last = DoughBoss_Growth_Recon_Report::last_attempt();
			assert_same( 'FAILED', $last['status'], $setup[1] . ': the failed attempt is visible as FAILED' );
			assert_same( null, $last['running_guard'], $setup[1] . ': lock released' );
		}
		dbgr_recon_teardown();
	}
);

db_test(
	'recon report: storage write failure and an internal error fail closed with no partial rows',
	function () {
		$cards = dbgr_recon_world();
		dbgr_recon_fake_square( $cards );
		$GLOBALS['wpdb']->fail_on( '/^INSERT INTO wp_doughboss_growth_recon_row/' );
		$result = DoughBoss_Growth_Recon_Report::run( 'manual' );
		assert_same( 'FAILED', $result['status'], 'row insert error: FAILED' );
		assert_same( 'storage_write_failed', $result['reason'], 'reason' );
		$GLOBALS['wpdb']->clear_failures();
		assert_count( 0, $GLOBALS['wpdb']->sqlite_raw( 'SELECT id FROM wp_doughboss_growth_recon_row' ), 'no partial rows' );

		$GLOBALS['dbgr_http'] = array( 'expect' => array(), 'calls' => array() );
		dbgr_recon_fake_square( $cards );
		$GLOBALS['wpdb']->respond(
			'/FROM wp_doughboss_staff_shifts s /',
			function () {
				throw new RuntimeException( 'simulated fault' );
			}
		);
		$result  = DoughBoss_Growth_Recon_Report::run( 'manual' );
		$attempt = DoughBoss_Growth_Recon_Report::last_attempt();
		assert_same( array( 'FAILED', 'internal_error' ), array( $result['status'], $result['reason'] ), 'a thrown fault is contained and the run FAILED internal_error' );
		assert_same( 'FAILED', $attempt['status'], 'recorded as FAILED' );
		assert_same( null, $attempt['running_guard'], 'lock released after the fault' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon report: one run at a time; a crashed run is recovered after the stale limit',
	function () {
		$cards = dbgr_recon_world();
		dbgr_recon_fake_square( $cards );
		$now = DoughBoss_Growth::now();
		$GLOBALS['wpdb']->sqlite_raw( "INSERT INTO wp_doughboss_growth_recon_run (status, trigger_type, running_guard, started_at) VALUES ('RUNNING', 'cron', 1, '" . gmdate( 'Y-m-d H:i:s', $now - 60 ) . "')" );
		$busy = DoughBoss_Growth_Recon_Report::run( 'manual' );
		assert_same( array( 'status' => 'NOT_RUN', 'reason' => 'run_in_progress', 'run_id' => 0 ), $busy, 'a live run blocks a second one' );
		assert_true( DoughBoss_Growth_Recon_Report::STALE_RUN_SECONDS > 2 * DoughBoss_Growth_Recon_Square::MAX_PAGES * DoughBoss_Growth_Http::TIMEOUT, 'the stale limit outlasts the longest possible run, so a slow run is never taken over' );
		assert_count( 0, dbgr_test_http_calls(), 'the blocked run made no Square call' );

		$GLOBALS['wpdb']->sqlite_raw( "UPDATE wp_doughboss_growth_recon_run SET started_at = '" . gmdate( 'Y-m-d H:i:s', $now - DoughBoss_Growth_Recon_Report::STALE_RUN_SECONDS - 1 ) . "'" );
		$ok = DoughBoss_Growth_Recon_Report::run( 'manual' );
		assert_same( 'COMPLETE', $ok['status'], 'a stale lock is recovered and the run proceeds' );
		$stale = $GLOBALS['wpdb']->sqlite_raw( "SELECT status, reason_code FROM wp_doughboss_growth_recon_run WHERE trigger_type = 'cron'" );
		assert_same( array( array( 'status' => 'FAILED', 'reason_code' => 'stale_run' ) ), $stale, 'the crashed run is marked FAILED stale_run' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon report: a later edit in either system marks the row as changed',
	function () {
		$cards = dbgr_recon_world();
		dbgr_recon_fake_square( $cards );
		$first = DoughBoss_Growth_Recon_Report::run( 'manual' );
		foreach ( DoughBoss_Growth_Recon_Report::rows( $first['run_id'] ) as $row ) {
			assert_same( '0', (string) $row['changed'], 'nothing changed on the first run' );
		}
		$cards[0]['version'] = 2;
		$cards[0]['end_at']  = '2026-10-15T17:05:00+11:00';
		$GLOBALS['dbgr_http'] = array( 'expect' => array(), 'calls' => array() );
		dbgr_recon_fake_square( $cards );
		$second  = DoughBoss_Growth_Recon_Report::run( 'manual' );
		$changed = array();
		foreach ( DoughBoss_Growth_Recon_Report::rows( $second['run_id'] ) as $row ) {
			$changed[ $row['square_ids'] . '|' . $row['plugin_ids'] ] = (string) $row['changed'];
		}
		assert_same( '1', $changed['TCA1|101'], 'the edited pair is flagged as changed' );
		assert_same( '0', $changed['TCB2|'], 'untouched rows are not flagged' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon report: business days, manual ranges and the fetch window',
	function () {
		$params = DoughBoss_Growth_Recon_Report::sanitize_params( dbgr_recon_test_params() );
		// 12:00 Sydney on 2026-10-16: the previous complete business day is 2026-10-15.
		assert_same( array( 'from' => '2026-10-15', 'to' => '2026-10-15' ), DoughBoss_Growth_Recon_Report::default_days( gmmktime( 1, 0, 0, 10, 16, 2026 ), $params, array( 1, 2 ) ), 'midday run' );
		// 03:30 Sydney on 2026-10-16 is still business day 2026-10-15 at a 04:00 cutoff, so it is not complete.
		assert_same( array( 'from' => '2026-10-14', 'to' => '2026-10-14' ), DoughBoss_Growth_Recon_Report::default_days( gmmktime( 16, 30, 0, 10, 15, 2026 ), $params, array( 1, 2 ) ), 'before-cutoff run' );
		$params['lookback_days'] = 2;
		assert_same( array( 'from' => '2026-10-13', 'to' => '2026-10-15' ), DoughBoss_Growth_Recon_Report::default_days( gmmktime( 1, 0, 0, 10, 16, 2026 ), $params, array( 1, 2 ) ), 'look-back re-checks earlier days' );
		$unset = DoughBoss_Growth_Recon_Report::sanitize_params( array() );
		assert_same( array( 'from' => '2026-10-15', 'to' => '2026-10-15' ), DoughBoss_Growth_Recon_Report::default_days( gmmktime( 1, 0, 0, 10, 16, 2026 ), $unset, array( 1 ) ), 'unset look-back means the previous day only' );

		$now = gmmktime( 1, 0, 0, 10, 16, 2026 );
		assert_same( array( 'from' => '2026-10-01', 'to' => '2026-10-15' ), DoughBoss_Growth_Recon_Report::manual_days( '2026-10-01', '2026-10-15', $now ), 'valid manual range' );
		assert_same( array( 'from' => '2026-09-16', 'to' => '2026-10-16' ), DoughBoss_Growth_Recon_Report::manual_days( '2026-09-16', '2026-10-16', $now ), '31 days up to today is allowed' );
		foreach ( array( array( '2026-09-15', '2026-10-16' ), array( '2026-10-15', '2026-10-14' ), array( '2026-10-15', '2026-10-17' ), array( '2026-10-15', 'today' ), array( "2026-10-15\n", '2026-10-15' ) ) as $bad ) {
			assert_same( null, DoughBoss_Growth_Recon_Report::manual_days( $bad[0], $bad[1], $now ), 'refused range ' . wp_json_encode( $bad ) );
		}

		$window = DoughBoss_Growth_Recon_Report::fetch_window( '2026-10-15', '2026-10-15', $params );
		assert_same( gmmktime( 13, 0, 0, 10, 14, 2026 ) - 86400 - 7200, $window['from'], 'from = local midnight, minus one day and the near-miss distance' );
		assert_same( gmmktime( 13, 0, 0, 10, 16, 2026 ) + 86400 + 7200, $window['to'], 'to = local midnight two days later, plus the margin' );
		$gap = DoughBoss_Growth_Recon_Report::fetch_window( '2026-10-03', '2026-10-03', $unset );
		assert_same( gmmktime( 14, 0, 0, 10, 2, 2026 ) - 86400, $gap['from'], 'AEST midnight before the DST change' );
		assert_same( gmmktime( 13, 0, 0, 10, 4, 2026 ) + 86400, $gap['to'], 'AEDT midnight after it' );
	}
);

db_test(
	'recon report: parameters are never shipped, never guessed and never clamped',
	function () {
		$defaults = DoughBoss_Growth_Recon_Report::sanitize_params( get_option( 'doughboss_growth_recon', false ) );
		foreach ( DoughBoss_Growth_Recon_Report::INT_PARAMS as $key => $range ) {
			assert_same( null, $defaults[ $key ], 'no shipped value for ' . $key );
		}
		assert_same( null, $defaults['run_time_local'], 'no shipped run time' );
		assert_same( array(), $defaults['business_day_cutoff_local'], 'no shipped cutoff' );
		$clean = DoughBoss_Growth_Recon_Report::sanitize_params(
			array(
				'start_tolerance_minutes'   => '0',
				'end_tolerance_minutes'     => '1441',
				'break_tolerance_minutes'   => '-1',
				'net_tolerance_minutes'     => '7.5',
				'max_shift_minutes'         => '0',
				'lookback_days'             => ' 3 ',
				'run_time_local'            => '25:00',
				'business_day_cutoff_local' => array(
					'1'  => '04:00',
					'2'  => '4:00',
					'x'  => '04:00',
					'0'  => '04:00',
				),
				'unknown'                   => '5',
			)
		);
		assert_same( 0, $clean['start_tolerance_minutes'], 'zero is a legal, chosen tolerance' );
		assert_same( null, $clean['end_tolerance_minutes'], 'above range refused, not clamped' );
		assert_same( null, $clean['break_tolerance_minutes'], 'negative refused, not made positive' );
		assert_same( null, $clean['net_tolerance_minutes'], 'fraction refused' );
		assert_same( null, $clean['max_shift_minutes'], 'zero longest-shift refused' );
		assert_same( 3, $clean['lookback_days'], 'whitespace trimmed' );
		assert_same( null, $clean['run_time_local'], 'invalid time refused' );
		assert_same( array( 1 => '04:00' ), $clean['business_day_cutoff_local'], 'only valid shop ids and HH:MM kept' );
		assert_false( array_key_exists( 'unknown', $clean ), 'unknown key dropped' );
		$gaps = DoughBoss_Growth_Recon_Report::unset_params( array( 1, 2 ) );
		assert_true( isset( $gaps['start_tolerance_minutes'], $gaps['run_time_local'], $gaps['cutoff_1'], $gaps['cutoff_2'] ), 'every unset parameter is listed for Elie' );
		foreach ( $gaps as $text ) {
			assert_matches( '/^\[CONFIRM: /', $text, 'each gap is a [CONFIRM] line' );
		}
	}
);

db_test(
	'recon report: rows of old runs are pruned after RETAIN_RUNS finished runs',
	function () {
		$cards = dbgr_recon_world();
		dbgr_recon_fake_square( $cards );
		$ids = array();
		for ( $i = 0; $i < DoughBoss_Growth_Recon_Report::RETAIN_RUNS + 2; $i++ ) {
			$result = DoughBoss_Growth_Recon_Report::run( 'manual' );
			$ids[]  = $result['run_id'];
		}
		assert_count( 0, DoughBoss_Growth_Recon_Report::rows( $ids[0] ), 'oldest run rows pruned' );
		assert_count( 0, DoughBoss_Growth_Recon_Report::rows( $ids[1] ), 'second-oldest run rows pruned' );
		assert_count( 3, DoughBoss_Growth_Recon_Report::rows( $ids[2] ), 'kept runs still have their rows' );
		assert_count( 3, DoughBoss_Growth_Recon_Report::rows( end( $ids ) ), 'newest run has its rows' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon report: CSV has a fixed header, ids and minutes only, and neutralises formula cells',
	function () {
		$cards = dbgr_recon_world();
		dbgr_recon_fake_square( $cards );
		$result = DoughBoss_Growth_Recon_Report::run( 'manual' );
		$csv    = DoughBoss_Growth_Recon_Report::csv( $result['run_id'] );
		$lines  = explode( "\n", trim( $csv ) );
		assert_same( implode( ',', DoughBoss_Growth_Recon_Report::csv_columns() ), $lines[0], 'header row' );
		assert_count( 4, $lines, 'header plus three rows' );
		assert_same( null, DoughBoss_Growth_Recon_Report::csv( 999 ), 'unknown run gives no CSV' );
		assert_same( "'=HYPERLINK(1)", DoughBoss_Growth_Recon_Report::csv_cell( '=HYPERLINK(1)' ), 'formula neutralised' );
		assert_same( "'@SUM(1)", DoughBoss_Growth_Recon_Report::csv_cell( '@SUM(1)' ), 'at-formula neutralised' );
		assert_same( "'+1+1", DoughBoss_Growth_Recon_Report::csv_cell( '+1+1' ), 'plus-formula neutralised' );
		assert_same( '-6', DoughBoss_Growth_Recon_Report::csv_cell( -6 ), 'a negative minute delta stays a number' );
		assert_same( 'a b', DoughBoss_Growth_Recon_Report::csv_cell( "a\nb" ), 'line breaks flattened' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon report: mappings are one-to-one, environment-scoped and revocable',
	function () {
		dbgr_recon_setup();
		assert_same( 'confirmed', DoughBoss_Growth_Recon_Report::confirm_mapping( 'employee', 'production', 11, 'TM11', 1 ), 'confirm' );
		assert_same( 'exists', DoughBoss_Growth_Recon_Report::confirm_mapping( 'employee', 'production', 11, 'TM11', 1 ), 'same mapping again' );
		assert_same( 'conflict', DoughBoss_Growth_Recon_Report::confirm_mapping( 'employee', 'production', 11, 'TM99', 1 ), 'second Square id for one user refused' );
		assert_same( 'conflict', DoughBoss_Growth_Recon_Report::confirm_mapping( 'employee', 'production', 12, 'TM11', 1 ), 'one Square id for two users refused' );
		assert_same( 'confirmed', DoughBoss_Growth_Recon_Report::confirm_mapping( 'employee', 'sandbox', 12, 'TM11', 1 ), 'sandbox mappings are separate' );
		assert_same( array( 11 => 'TM11' ), DoughBoss_Growth_Recon_Report::mappings( 'employee', 'production' ), 'production map' );
		assert_same( 'invalid', DoughBoss_Growth_Recon_Report::confirm_mapping( 'employee', 'production', 13, 'TM 13', 1 ), 'malformed Square id refused' );
		assert_same( 'invalid', DoughBoss_Growth_Recon_Report::confirm_mapping( 'payroll', 'production', 13, 'TM13', 1 ), 'unknown kind refused' );
		assert_same( 'invalid', DoughBoss_Growth_Recon_Report::confirm_mapping( 'employee', 'live', 13, 'TM13', 1 ), 'unknown environment refused' );
		assert_same( 'revoked', DoughBoss_Growth_Recon_Report::revoke_mapping( 'employee', 'production', 11, 2 ), 'revoke' );
		assert_same( 'absent', DoughBoss_Growth_Recon_Report::revoke_mapping( 'employee', 'production', 11, 2 ), 'nothing left to revoke' );
		assert_same( array(), DoughBoss_Growth_Recon_Report::mappings( 'employee', 'production' ), 'revoked mapping no longer used' );
		assert_same( 'confirmed', DoughBoss_Growth_Recon_Report::confirm_mapping( 'employee', 'production', 11, 'TM99', 1 ), 'after a revoke a new mapping can be confirmed' );
		$history = $GLOBALS['wpdb']->sqlite_raw( "SELECT status FROM wp_doughboss_growth_recon_xref WHERE kind = 'employee' AND environment = 'production' AND local_id = 11 ORDER BY id" );
		assert_same( array( array( 'status' => 'revoked' ), array( 'status' => 'confirmed' ) ), $history, 'revoked mapping kept as history' );
		assert_same( array(), dbgr_recon_core_writes(), 'mapping writes stay in companion tables' );
		dbgr_recon_teardown();
	}
);

db_test(
	'recon report: core 2.44.0 location map is authoritative; a disagreement blocks the shop (sub-process)',
	function () {
		if ( ! dbgr_test_can_subprocess() ) {
			dbgr_test_skip( 'this PHP cannot start a sub-process' );
			return;
		}
		$code   = <<<'PHP'
DoughBoss_Growth::load_module( 'recon' );
dbgr_recon_setup();
dbgr_recon_map( 'location', 1, 'LREV1' );
dbgr_recon_map( 'location', 2, 'LBNK2' );
$out = array();
$missing = DoughBoss_Growth_Recon_Report::effective_locations( 'production' );
$out['missing'] = is_wp_error( $missing ) ? $missing->get_error_code() : 'ok';
$GLOBALS['wpdb']->sqlite_raw( "CREATE TABLE wp_doughboss_square_locations (id INTEGER PRIMARY KEY, environment TEXT, wp_location_id INTEGER, square_location_id TEXT, status TEXT)" );
$GLOBALS['wpdb']->sqlite_raw( "INSERT INTO wp_doughboss_square_locations (environment, wp_location_id, square_location_id, status) VALUES ('live', 1, 'LREV1', 'verified'), ('live', 2, 'LCORE2', 'verified'), ('live', 3, 'LROS3', 'verified'), ('test', 2, 'LBNK2', 'verified'), ('live', 3, 'LIGNORED', 'unverified')" );
$map = DoughBoss_Growth_Recon_Report::effective_locations( 'production' );
$out['locations'] = $map['locations'];
$out['blocked'] = $map['blocked'];
$out['core'] = $map['core'];
echo json_encode( $out );
PHP;
		$result = dbgr_test_subprocess( $code, "define( 'DOUGHBOSS_VERSION', '2.44.0' );" );
		$data   = json_decode( trim( $result['out'] ), true );
		assert_true( is_array( $data ), 'sub-process result (' . trim( substr( $result['err'], 0, 300 ) ) . ')' );
		if ( ! is_array( $data ) ) {
			return;
		}
		assert_same( 'core_location_map_missing', $data['missing'], 'core 2.44.0 without its table fails closed' );
		assert_same( array( '1' => 'LREV1', '2' => 'LCORE2', '3' => 'LROS3' ), $data['locations'], 'verified core rows of the live environment win' );
		assert_same( array( '2' => 'core_map_disagrees' ), $data['blocked'], 'the shop where core and companion disagree is blocked' );
		assert_true( $data['core'], 'core map was used' );
		dbgr_recon_teardown();
	}
);
