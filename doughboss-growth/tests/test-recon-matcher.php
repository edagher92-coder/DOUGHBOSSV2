<?php
/**
 * Timesheet reconciliation matcher tests (WP-11): every case of 03 section 2.10 from the hand-worked
 * fixture tests/fixtures/recon-cases.json, Sydney DST gap and fold arithmetic (version independent),
 * order independence, row identity, and negative controls.
 *
 * @package DoughBoss_Growth
 */

DoughBoss_Growth::load_module( 'recon' );

/**
 * Turn a fixture shift into the reader's normalised shape.
 *
 * @param array $s Fixture shift.
 * @return array
 */
function dbgr_recon_fixture_shift( array $s ) {
	$breaks = array();
	foreach ( $s['breaks'] as $index => $b ) {
		$breaks[] = array(
			'break_id' => (int) $s['id'] * 100 + $index,
			'start'    => DoughBoss_Growth_Recon_Reader::parse_utc( $b[0] ),
			'end'      => null === $b[1] ? null : DoughBoss_Growth_Recon_Reader::parse_utc( $b[1] ),
			'source'   => 'staff_badge',
		);
	}
	return array(
		'shift_id'       => (int) $s['id'],
		'user_id'        => (int) $s['user'],
		'location_id'    => (int) $s['loc'],
		'timezone'       => $s['tz'],
		'in'             => DoughBoss_Growth_Recon_Reader::parse_utc( $s['in'] ),
		'out'            => null === $s['out'] ? null : DoughBoss_Growth_Recon_Reader::parse_utc( $s['out'] ),
		'source'         => 'staff_badge',
		'manager_closed' => (bool) $s['mc'],
		'updated_at'     => null,
		'breaks'         => $breaks,
	);
}

/**
 * Build matcher input for one fixture case.
 *
 * @param array $fx   Whole fixture.
 * @param array $case Case.
 * @return array
 */
function dbgr_recon_fixture_input( array $fx, array $case ) {
	$d      = $fx['defaults'];
	$shifts = array();
	foreach ( $case['shifts'] as $s ) {
		$shifts[] = dbgr_recon_fixture_shift( $s );
	}
	$cards = array();
	foreach ( $case['timecards'] as $t ) {
		$cards[] = DoughBoss_Growth_Recon_Square::normalise_timecard( $t );
	}
	$int_keys = function ( $map ) {
		$out = array();
		foreach ( $map as $k => $v ) {
			$out[ (int) $k ] = $v;
		}
		return $out;
	};
	return array(
		'now'               => DoughBoss_Growth_Recon_Square::parse_time( isset( $case['now'] ) ? $case['now'] : $d['now'] ),
		'report_from'       => isset( $case['report_from'] ) ? $case['report_from'] : $d['report_from'],
		'report_to'         => isset( $case['report_to'] ) ? $case['report_to'] : $d['report_to'],
		'params'            => DoughBoss_Growth_Recon_Report::sanitize_params( isset( $case['params'] ) ? $case['params'] : $d['params'] ),
		'employees'         => $int_keys( $d['employees'] ),
		'locations'         => $int_keys( $d['locations'] ),
		'blocked_locations' => $int_keys( isset( $case['blocked_locations'] ) ? $case['blocked_locations'] : $d['blocked_locations'] ),
		'shifts'            => $shifts,
		'timecards'         => $cards,
	);
}

/**
 * Differences between matcher rows and a case's expectations (empty = identical).
 *
 * @param array $rows   Rows.
 * @param array $expect Expected row subsets.
 * @return array
 */
function dbgr_recon_fixture_diff( array $rows, array $expect ) {
	$diff = array();
	if ( count( $rows ) !== count( $expect ) ) {
		$diff[] = 'row count ' . count( $rows ) . ' expected ' . count( $expect );
	}
	foreach ( $expect as $i => $e ) {
		if ( ! isset( $rows[ $i ] ) ) {
			break;
		}
		foreach ( $e as $key => $value ) {
			if ( ! array_key_exists( $key, $rows[ $i ] ) || $rows[ $i ][ $key ] !== $value ) {
				$diff[] = 'row ' . $i . ' ' . $key . ' = ' . wp_json_encode( isset( $rows[ $i ][ $key ] ) ? $rows[ $i ][ $key ] : null ) . ', expected ' . wp_json_encode( $value );
			}
		}
	}
	return $diff;
}

/**
 * Assert every value is null.
 *
 * @param array  $values  Values.
 * @param string $message Label.
 * @return void
 */
function dbgr_recon_assert_null_all( array $values, $message ) {
	foreach ( $values as $index => $value ) {
		assert_same( null, $value, $message . ' #' . $index );
	}
}

$dbgr_recon_fx = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/recon-cases.json' ), true );

db_test(
	'recon matcher: fixture loads and covers every acceptance case of 03 section 2.10',
	function () use ( $dbgr_recon_fx ) {
		assert_true( is_array( $dbgr_recon_fx ) && isset( $dbgr_recon_fx['cases'] ) && count( $dbgr_recon_fx['cases'] ) >= 40, 'fixture has the case catalogue' );
		$names = array();
		foreach ( $dbgr_recon_fx['cases'] as $case ) {
			$names[] = $case['name'];
		}
		$all = implode( "\n", $names );
		foreach ( array( 'perfect match', 'equal to the tolerance', 'one minute over the tolerance', 'tolerance unset', 'missing in Square', 'missing in the plugin', 'open on the plugin side', 'open on the Square side', 'manager closed', 'crossing midnight', 'DST gap', 'DST fold', 'repeated hour', 'split', 'two shops', 'unmapped staff', 'unmapped shop', 'paid', 'near miss' ) as $needle ) {
			assert_contains( $needle, $all, 'fixture covers: ' . $needle );
		}
		$codes = array();
		foreach ( $dbgr_recon_fx['cases'] as $case ) {
			foreach ( $case['expect'] as $row ) {
				foreach ( $row['codes'] as $code ) {
					$codes[ $code ] = true;
				}
			}
		}
		foreach ( array_keys( DoughBoss_Growth_Recon_Matcher::codes() ) as $code ) {
			assert_true( isset( $codes[ $code ] ), 'some fixture case expects code ' . $code );
		}
	}
);

foreach ( $dbgr_recon_fx['cases'] as $dbgr_recon_case ) {
	db_test(
		'recon matcher case: ' . $dbgr_recon_case['name'],
		function () use ( $dbgr_recon_fx, $dbgr_recon_case ) {
			$input = dbgr_recon_fixture_input( $dbgr_recon_fx, $dbgr_recon_case );
			foreach ( $input['timecards'] as $card ) {
				assert_false( is_wp_error( $card ), 'fixture timecard is valid Square data' );
			}
			$rows = DoughBoss_Growth_Recon_Matcher::match( $input );
			assert_same( array(), dbgr_recon_fixture_diff( $rows, $dbgr_recon_case['expect'] ), $dbgr_recon_case['why'] );

			// Order independence: the same evidence in any input order gives the identical report.
			$input['shifts']    = array_reverse( $input['shifts'] );
			$input['timecards'] = array_reverse( $input['timecards'] );
			assert_same( $rows, DoughBoss_Growth_Recon_Matcher::match( $input ), 'input order does not change the result' );

			foreach ( $rows as $row ) {
				assert_true( 'MATCHED' !== $row['state'] || ( ! $row['has_open'] && array() === $row['codes'] ), 'MATCHED only for a closed, fully rated, unflagged row' );
				assert_matches( '/^[0-9a-f]{64}$/D', $row['stable_key'], 'row has a stable key' );
			}
		}
	);
}

db_test(
	'recon matcher negative control: a wrong expectation is detected by the fixture comparison',
	function () use ( $dbgr_recon_fx ) {
		$case                          = $dbgr_recon_fx['cases'][0];
		$rows                          = DoughBoss_Growth_Recon_Matcher::match( dbgr_recon_fixture_input( $dbgr_recon_fx, $case ) );
		$tampered                      = $case['expect'];
		$tampered[0]['net_delta']      = 1;
		$tampered[0]['state']          = 'REVIEW';
		assert_count( 2, dbgr_recon_fixture_diff( $rows, $tampered ), 'two planted differences are both reported' );
		$extra                         = $case['expect'];
		$extra[]                       = array( 'kind' => 'pair' );
		assert_true( array() !== dbgr_recon_fixture_diff( $rows, $extra ), 'a missing row is reported' );
	}
);

db_test(
	'recon matcher negative control: a non-integer tolerance is never trusted (unrated, not passed)',
	function () use ( $dbgr_recon_fx ) {
		$input                                      = dbgr_recon_fixture_input( $dbgr_recon_fx, $dbgr_recon_fx['cases'][0] );
		$input['params']['start_tolerance_minutes'] = '5';
		$input['params']['net_tolerance_minutes']   = 5.0;
		$rows                                       = DoughBoss_Growth_Recon_Matcher::match( $input );
		assert_same( array( 'UNRATED_START', 'UNRATED_NET' ), $rows[0]['codes'], 'string and float tolerances are treated as unset' );
		assert_same( 'UNRATED', $rows[0]['state'], 'row is unrated, not matched' );
	}
);

db_test(
	'recon matcher: Sydney local time helpers are exact at the DST gap and fold (no getTimestamp on a local object)',
	function () {
		// Fold, 2026-04-05: 02:30 happens twice.
		assert_same( 39600, DoughBoss_Growth_Recon_Matcher::offset( 1775316600 ), 'first 02:30 is AEDT (+11)' );
		assert_same( 36000, DoughBoss_Growth_Recon_Matcher::offset( 1775320200 ), 'second 02:30 is AEST (+10)' );
		assert_same( '2026-04-05 02:30 AEDT', DoughBoss_Growth_Recon_Matcher::format_local( 1775316600 ), 'first fold instant displayed as AEDT' );
		assert_same( '2026-04-05 02:30 AEST', DoughBoss_Growth_Recon_Matcher::format_local( 1775320200 ), 'second fold instant displayed as AEST' );
		assert_same( 1775316600, DoughBoss_Growth_Recon_Matcher::local_to_utc( '2026-04-05', '02:30' ), 'an ambiguous local time maps to the EARLIER instant' );
		assert_same( 1775318400 - 3600, DoughBoss_Growth_Recon_Matcher::local_to_utc( '2026-04-05', '02:00' ), '02:00 on the fold day is also ambiguous: earlier one' );
		assert_same( 1775320200 + 1800, DoughBoss_Growth_Recon_Matcher::local_to_utc( '2026-04-05', '03:00' ), '03:00 exists once (AEST)' );

		// Gap, 2026-10-04: 02:00-02:59 does not exist.
		assert_same( 1791043200, DoughBoss_Growth_Recon_Matcher::local_to_utc( '2026-10-04', '02:30' ), 'a time in the gap maps to the end of the gap (03:00 AEDT)' );
		assert_same( 1791043200, DoughBoss_Growth_Recon_Matcher::local_to_utc( '2026-10-04', '02:00' ), 'the first missing minute maps to the end of the gap' );
		assert_same( 1791043200 - 60, DoughBoss_Growth_Recon_Matcher::local_to_utc( '2026-10-04', '01:59' ), '01:59 AEST exists' );
		assert_same( '2026-10-04 03:00 AEDT', DoughBoss_Growth_Recon_Matcher::format_local( 1791043200 ), 'gap end shown as 03:00 AEDT' );
		assert_same( '2026-10-04 01:59 AEST', DoughBoss_Growth_Recon_Matcher::format_local( 1791043200 - 60 ), 'last minute before the gap shown as 01:59 AEST' );

		// Ordinary days on both sides of the year.
		assert_same( gmmktime( 22, 0, 0, 10, 14, 2026 ), DoughBoss_Growth_Recon_Matcher::local_to_utc( '2026-10-15', '09:00' ), 'October 09:00 AEDT = 22:00Z the day before' );
		assert_same( gmmktime( 23, 0, 0, 6, 30, 2026 ), DoughBoss_Growth_Recon_Matcher::local_to_utc( '2026-07-01', '09:00' ), 'July 09:00 AEST = 23:00Z the day before' );

		// Business date with a cutoff that falls inside the gap.
		assert_same( '2026-10-04', DoughBoss_Growth_Recon_Matcher::business_date( 1791043200 + 900, '02:30' ), '03:15 AEDT is after a 02:30 cutoff' );
		assert_same( '2026-10-03', DoughBoss_Growth_Recon_Matcher::business_date( 1791043200 - 900, '02:30' ), '01:45 AEST is before a 02:30 cutoff' );
		assert_same( '2026-04-04', DoughBoss_Growth_Recon_Matcher::business_date( 1775320200, '03:00' ), 'second 02:30 (AEST) is still before a 03:00 cutoff' );

		// Calendar arithmetic and validation.
		assert_same( '2026-03-01', DoughBoss_Growth_Recon_Matcher::add_days( '2026-02-28', 1 ), 'add_days crosses a month' );
		assert_same( '2027-01-01', DoughBoss_Growth_Recon_Matcher::add_days( '2026-12-31', 1 ), 'add_days crosses a year' );
		dbgr_recon_assert_null_all(
			array(
				DoughBoss_Growth_Recon_Matcher::local_to_utc( '2026-02-30', '09:00' ),
				DoughBoss_Growth_Recon_Matcher::local_to_utc( '2026-10-15', '24:00' ),
				DoughBoss_Growth_Recon_Matcher::local_to_utc( '2026-10-15', '9:00' ),
				DoughBoss_Growth_Recon_Matcher::local_to_utc( "2026-10-15\n", '09:00' ),
			),
			'invalid local dates and times are refused (negative controls)'
		);
	}
);

db_test(
	'recon matcher: the PHP 7.4 repeated-hour pitfall is avoided on this runtime',
	function () {
		$tz        = new DateTimeZone( 'Australia/Sydney' );
		$collapsed = array();
		foreach ( array( 1775316600, 1775320200 ) as $ts ) {
			$local = new DateTime( '@' . $ts );
			$local->setTimezone( $tz );
			$collapsed[] = $local->getTimestamp();
		}
		// On PHP 7.4 the naive conversion collapses both fold instants (documented in core
		// docs/STOREFRONT-COMPLETION-20260908.md); on 8.x it does not. Record which, then prove the
		// companion's own helpers stay distinct either way.
		if ( $collapsed[0] === $collapsed[1] ) {
			assert_same( true, version_compare( PHP_VERSION, '8.0.0', '<' ), 'only PHP 7.x collapses the fold' );
		} else {
			assert_same( array( 1775316600, 1775320200 ), $collapsed, 'this PHP keeps fold instants distinct' );
		}
		$a = DoughBoss_Growth_Recon_Matcher::local_parts( 1775316600 );
		$b = DoughBoss_Growth_Recon_Matcher::local_parts( 1775320200 );
		assert_same( $a, $b, 'both fold instants read 02:30 on the wall clock' );
		assert_true( DoughBoss_Growth_Recon_Matcher::abbreviation( 1775316600 ) !== DoughBoss_Growth_Recon_Matcher::abbreviation( 1775320200 ), 'but they are labelled apart (AEDT / AEST)' );
	}
);

db_test(
	'recon matcher: net and break minutes use the core formula (each break floored, open break runs to the end)',
	function () {
		$in     = gmmktime( 22, 0, 20, 10, 14, 2026 );
		$out    = gmmktime( 6, 0, 40, 10, 15, 2026 );
		$breaks = array(
			array( 'start' => $in + 3600, 'end' => $in + 3600 + 119 ),
			array( 'start' => $in + 7200, 'end' => $in + 7200 + 119 ),
		);
		assert_same( 2, DoughBoss_Growth_Recon_Matcher::break_minutes( $breaks, $out ), 'two 1 min 59 s breaks floor to 1 + 1, not 3' );
		assert_same( 478, DoughBoss_Growth_Recon_Matcher::net_minutes( $in, $out, $breaks ), '480 gross - 2' );
		$open = array( array( 'start' => $out - 600, 'end' => null ) );
		assert_same( 10, DoughBoss_Growth_Recon_Matcher::break_minutes( $open, $out ), 'an open break runs to the shift end' );
		$backwards = array( array( 'start' => $in + 600, 'end' => $in + 300 ) );
		assert_same( 0, DoughBoss_Growth_Recon_Matcher::break_minutes( $backwards, $out ), 'a break that ends before it starts counts zero (core rule)' );
		assert_same( 0, DoughBoss_Growth_Recon_Matcher::net_minutes( $in, $in + 120, array( array( 'start' => $in, 'end' => $in + 600 ) ) ), 'net never goes below zero' );
	}
);

db_test(
	'recon matcher: row identity is stable across runs and the content hash reacts to a source edit',
	function () use ( $dbgr_recon_fx ) {
		$input = dbgr_recon_fixture_input( $dbgr_recon_fx, $dbgr_recon_fx['cases'][0] );
		$first = DoughBoss_Growth_Recon_Matcher::match( $input );
		$input['timecards'][0]['version'] = 4;
		$input['timecards'][0]['end']    -= 60;
		$second = DoughBoss_Growth_Recon_Matcher::match( $input );
		assert_same( $first[0]['stable_key'], $second[0]['stable_key'], 'same pair keeps its key' );
		assert_true( $first[0]['content_hash'] !== $second[0]['content_hash'], 'an edit in Square changes the content hash' );
		$input = dbgr_recon_fixture_input( $dbgr_recon_fx, $dbgr_recon_fx['cases'][0] );
		$input['shifts'][0]['manager_closed'] = true;
		$third = DoughBoss_Growth_Recon_Matcher::match( $input );
		assert_true( $first[0]['content_hash'] !== $third[0]['content_hash'], 'a manager close in the plugin changes the content hash' );
	}
);

db_test(
	'recon matcher: rows carry ids and minutes only (no name, login, email or reason field exists)',
	function () use ( $dbgr_recon_fx ) {
		$keys = array();
		foreach ( $dbgr_recon_fx['cases'] as $case ) {
			foreach ( DoughBoss_Growth_Recon_Matcher::match( dbgr_recon_fixture_input( $dbgr_recon_fx, $case ) ) as $row ) {
				$keys += array_flip( array_keys( $row ) );
			}
		}
		foreach ( array_keys( $keys ) as $key ) {
			assert_true( 1 !== preg_match( '/name|login|email|reason|wage|tip|note/i', $key ), 'row field ' . $key . ' is not personal data' );
		}
	}
);

db_test(
	'recon matcher: summary counts per business day and shop',
	function () use ( $dbgr_recon_fx ) {
		$case = null;
		foreach ( $dbgr_recon_fx['cases'] as $c ) {
			if ( 'one employee in two shops at different times' === $c['name'] ) {
				$case = $c;
			}
		}
		$summary = DoughBoss_Growth_Recon_Matcher::summarise( DoughBoss_Growth_Recon_Matcher::match( dbgr_recon_fixture_input( $dbgr_recon_fx, $case ) ) );
		assert_same( 2, $summary['total']['MATCHED'], 'two matched rows' );
		assert_same( array( '2026-10-15|1', '2026-10-15|2' ), array_keys( $summary['by_day_shop'] ), 'one cell per day and shop' );
		assert_same( 1, $summary['by_day_shop']['2026-10-15|2']['MATCHED'], 'shop 2 has one matched row' );
	}
);
