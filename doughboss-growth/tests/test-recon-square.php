<?php
/**
 * Timesheet reconciliation Square client tests (WP-11). Square is never called: every request goes to the
 * harness's fake transport, which FAILS the test on any undeclared call.
 *
 * @package DoughBoss_Growth
 */

DoughBoss_Growth::load_module( 'recon' );

/**
 * Configure env + token for one test.
 *
 * @param string|null $env   Environment or null.
 * @param bool        $token Whether the token is set.
 * @return void
 */
function dbgr_recon_square_config( $env, $token ) {
	dbgr_recon_env( 'DOUGHBOSS_GROWTH_SQUARE_ENV', $env );
	dbgr_recon_env( 'DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN', $token ? 'dbgr-test-labour-token-not-real-0001' : null );
}

/**
 * N synthetic closed timecards at LREV1 starting from 2026-10-14T20:00Z, one minute apart per id.
 *
 * @param int    $n      Count.
 * @param string $prefix Id prefix.
 * @return array
 */
function dbgr_recon_many_cards( $n, $prefix = 'TCP' ) {
	$out = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$start = gmmktime( 20, 0, 0, 10, 14, 2026 ) + $i * 60;
		$out[] = dbgr_recon_tc( sprintf( '%s%04d', $prefix, $i ), 'TM' . ( $i % 7 ), 'LREV1', DoughBoss_Growth_Recon_Square::rfc3339( $start ), DoughBoss_Growth_Recon_Square::rfc3339( $start + 3600 ) );
	}
	return $out;
}

$dbgr_recon_from = gmmktime( 13, 0, 0, 10, 13, 2026 );
$dbgr_recon_to   = gmmktime( 13, 0, 0, 10, 17, 2026 );

db_test(
	'recon square: RFC 3339 parsing is exact and strict (no timezone database involved)',
	function () {
		assert_same( gmmktime( 22, 0, 0, 10, 14, 2026 ), DoughBoss_Growth_Recon_Square::parse_time( '2026-10-14T22:00:00Z' ), 'Z form' );
		assert_same( gmmktime( 22, 0, 0, 10, 14, 2026 ), DoughBoss_Growth_Recon_Square::parse_time( '2026-10-15T09:00:00+11:00' ), 'AEDT offset form' );
		assert_same( gmmktime( 12, 0, 0, 10, 3, 2026 ), DoughBoss_Growth_Recon_Square::parse_time( '2026-10-03T22:00:00+10:00' ), 'AEST offset form' );
		assert_same( gmmktime( 22, 0, 0, 10, 14, 2026 ), DoughBoss_Growth_Recon_Square::parse_time( '2026-10-14T22:00:00.123456Z' ), 'fraction dropped' );
		assert_same( gmmktime( 22, 0, 0, 10, 14, 2026 ), DoughBoss_Growth_Recon_Square::parse_time( '2026-10-14T22:00Z' ), 'minute precision' );
		foreach ( array( '2026-02-30T00:00:00Z', '2026-10-15 09:00:00', '2026-10-15T24:00:00Z', '2026-10-15T09:00:00', '2026-10-15T09:00:60Z', '2026-10-15T09:00:00+1100', '', null, 1790000000, "2026-10-15T09:00:00Z\n" ) as $bad ) {
			assert_same( null, DoughBoss_Growth_Recon_Square::parse_time( $bad ), 'refused: ' . wp_json_encode( $bad ) );
		}
	}
);

db_test(
	'recon square: a timecard is normalised to ids, instants and breaks; wage, tips and names are dropped',
	function () {
		$raw = dbgr_recon_tc(
			'TCA1',
			'TM11',
			'LREV1',
			'2026-10-14T22:00:00Z',
			'2026-10-15T06:00:00Z',
			array(
				'breaks'      => array(
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
				'team_member' => array( 'given_name' => 'Should Not Survive' ),
			)
		);
		$card = DoughBoss_Growth_Recon_Square::normalise_timecard( $raw );
		assert_false( is_wp_error( $card ), 'valid timecard accepted' );
		assert_same( array( 'id', 'team_member_id', 'location_id', 'timezone', 'start', 'end', 'status', 'version', 'updated_at', 'breaks' ), array_keys( $card ), 'only the reconciliation fields are kept' );
		assert_same( array( 'id', 'start', 'end', 'is_paid' ), array_keys( $card['breaks'][0] ), 'break keeps no name' );
		assert_not_contains( 'Should Not Survive', wp_json_encode( $card ), 'no name passes through' );
		assert_not_contains( 'Synthetic', wp_json_encode( $card ), 'no wage title passes through' );
	}
);

db_test(
	'recon square: malformed timecards are rejected (negative controls)',
	function () {
		$base = dbgr_recon_tc( 'TCA1', 'TM11', 'LREV1', '2026-10-14T22:00:00Z', '2026-10-15T06:00:00Z' );
		$bad  = array(
			'missing id'           => array( 'id' => null ),
			'id with a space'      => array( 'id' => 'TC A1' ),
			'long id'              => array( 'team_member_id' => str_repeat( 'x', 65 ) ),
			'unknown status'       => array( 'status' => 'DONE' ),
			'closed without end'   => array( 'end_at' => null ),
			'open with an end'     => array( 'status' => 'OPEN' ),
			'end before start'     => array( 'end_at' => '2026-10-14T21:00:00Z' ),
			'bad start'            => array( 'start_at' => '2026-10-14 22:00:00' ),
			'bad timezone'         => array( 'timezone' => '../etc' ),
			'string version'       => array( 'version' => '2' ),
			'negative version'     => array( 'version' => -1 ),
			'bad updated_at'       => array( 'updated_at' => 'yesterday' ),
			'breaks not a list'    => array( 'breaks' => 'none' ),
			'break without is_paid' => array( 'breaks' => array( array( 'id' => 'B1', 'start_at' => '2026-10-15T01:00:00Z', 'end_at' => '2026-10-15T01:10:00Z' ) ) ),
			'is_paid as a string'  => array( 'breaks' => array( array( 'id' => 'B1', 'start_at' => '2026-10-15T01:00:00Z', 'end_at' => '2026-10-15T01:10:00Z', 'is_paid' => 'false' ) ) ),
			'break ends early'     => array( 'breaks' => array( array( 'id' => 'B1', 'start_at' => '2026-10-15T01:00:00Z', 'end_at' => '2026-10-15T00:10:00Z', 'is_paid' => false ) ) ),
		);
		foreach ( $bad as $label => $override ) {
			$result = DoughBoss_Growth_Recon_Square::normalise_timecard( array_merge( $base, $override ) );
			assert_true( is_wp_error( $result ) && 'square_response_invalid' === $result->get_error_code(), 'rejected: ' . $label );
		}
		assert_true( is_wp_error( DoughBoss_Growth_Recon_Square::normalise_timecard( 'TCA1' ) ), 'rejected: not an object' );
	}
);

db_test(
	'recon square: not configured means no request at all',
	function () use ( $dbgr_recon_from, $dbgr_recon_to ) {
		foreach ( array( array( null, true, 'square_env_missing' ), array( 'staging', true, 'square_env_missing' ), array( 'production', false, 'square_token_missing' ) ) as $setup ) {
			dbgr_recon_square_config( $setup[0], $setup[1] );
			$result = DoughBoss_Growth_Recon_Square::search_timecards( array( 'LREV1' ), $dbgr_recon_from, $dbgr_recon_to );
			assert_true( is_wp_error( $result ) && $setup[2] === $result->get_error_code(), 'refused with ' . $setup[2] );
		}
		assert_count( 0, dbgr_test_http_calls(), 'zero HTTP calls were attempted' );
		dbgr_recon_square_config( null, false );
	}
);

db_test(
	'recon square: request shape, pinned version, bearer token sent but never logged',
	function () use ( $dbgr_recon_from, $dbgr_recon_to ) {
		dbgr_recon_square_config( 'production', true );
		$lines = array();
		add_action(
			'doughboss_growth_log',
			function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			}
		);
		dbgr_recon_fake_square( dbgr_recon_many_cards( 3 ) );
		$result = DoughBoss_Growth_Recon_Square::search_timecards( array( 'LREV1', 'LBNK2', 'LREV1' ), $dbgr_recon_from, $dbgr_recon_to );
		assert_false( is_wp_error( $result ), 'search succeeds' );
		assert_count( 3, $result['timecards'], 'three timecards' );
		$seen = $GLOBALS['dbgr_recon_square']['seen'];
		assert_count( 2, $seen, 'one start-window search and one open-timecard search' );
		assert_same( 'Bearer dbgr-test-labour-token-not-real-0001', $seen[0]['headers']['Authorization'], 'bearer token sent to Square' );
		assert_same( '2026-09-16', $seen[0]['headers']['Square-Version'], 'API version pinned' );
		assert_true( strcmp( DoughBoss_Growth_Recon_Square::SQUARE_VERSION, '2025-05-21' ) >= 0, 'pinned version has the Timecards API' );
		assert_same( array( 'LBNK2', 'LREV1' ), $seen[0]['body']['query']['filter']['location_ids'], 'locations de-duplicated and sorted' );
		assert_same( '2026-10-13T13:00:00Z', $seen[0]['body']['query']['filter']['start']['start_at'], 'window start in UTC' );
		assert_same( '2026-10-17T13:00:00Z', $seen[0]['body']['query']['filter']['start']['end_at'], 'window end in UTC' );
		assert_same( 40, $seen[0]['body']['limit'], 'page size' );
		assert_same( 'OPEN', $seen[1]['body']['query']['filter']['status'], 'second search is open timecards only' );
		assert_false( isset( $seen[1]['body']['query']['filter']['start'] ), 'open search has no start window' );
		foreach ( dbgr_test_http_calls() as $call ) {
			assert_same( 0, strpos( $call['url'], 'https://connect.squareup.com/v2/labor/timecards/search' ), 'production host, timecards endpoint only' );
			assert_same( 'POST', $call['method'], 'POST search' );
		}
		$all = implode( "\n", $lines );
		assert_not_contains( 'dbgr-test-labour-token', $all, 'token never appears in a log line' );
		assert_not_contains( 'dbgr-test-labour-token', wp_json_encode( $result ), 'token never appears in the result' );
		dbgr_recon_square_config( null, false );
	}
);

db_test(
	'recon square: sandbox environment uses the sandbox host',
	function () use ( $dbgr_recon_from, $dbgr_recon_to ) {
		dbgr_recon_square_config( 'sandbox', true );
		dbgr_test_http_expect( 'https://connect.squareupsandbox.com/v2/labor/timecards/search', dbgr_test_http_response( 200, '{}' ), array( 'times' => 2 ) );
		$result = DoughBoss_Growth_Recon_Square::search_timecards( array( 'LREV1' ), $dbgr_recon_from, $dbgr_recon_to );
		assert_same( array(), $result['timecards'], 'empty result is a valid result' );
		dbgr_test_http_assert_done( 'both sandbox calls made' );
		dbgr_recon_square_config( null, false );
	}
);

db_test(
	'recon square: pagination over 200 timecards follows the cursor to the end',
	function () use ( $dbgr_recon_from, $dbgr_recon_to ) {
		dbgr_recon_square_config( 'production', true );
		$cards   = dbgr_recon_many_cards( 230 );
		$cards[] = dbgr_recon_tc( 'TCOPEN', 'TM1', 'LREV1', '2026-10-01T22:00:00Z', null );
		dbgr_recon_fake_square( $cards );
		$result = DoughBoss_Growth_Recon_Square::search_timecards( array( 'LREV1' ), $dbgr_recon_from, $dbgr_recon_to );
		assert_false( is_wp_error( $result ), 'paged search succeeds' );
		assert_count( 231, $result['timecards'], 'all 230 in-window timecards plus the old open one' );
		assert_same( 7, $result['pages'], '6 pages of 40 for 230, then 1 page of open timecards' );
		$seen = $GLOBALS['dbgr_recon_square']['seen'];
		assert_false( isset( $seen[0]['body']['cursor'] ), 'first page has no cursor' );
		assert_same( 'cursor-40', $seen[1]['body']['cursor'], 'second page sends the returned cursor' );
		dbgr_recon_square_config( null, false );
	}
);

db_test(
	'recon square: 429, 5xx, 401 and transport errors fail the whole search (no partial result)',
	function () use ( $dbgr_recon_from, $dbgr_recon_to ) {
		dbgr_recon_square_config( 'production', true );
		foreach ( array( array( 1, 429, 'square_http_429' ), array( 3, 503, 'square_http_5xx' ), array( 2, 500, 'square_http_5xx' ), array( 1, 401, 'square_http_401' ), array( 7, 429, 'square_http_429' ) ) as $setup ) {
			$GLOBALS['dbgr_http'] = array( 'expect' => array(), 'calls' => array() );
			dbgr_recon_fake_square( dbgr_recon_many_cards( 230 ), array( 'status_on' => array( $setup[0] => $setup[1] ) ) );
			$result = DoughBoss_Growth_Recon_Square::search_timecards( array( 'LREV1' ), $dbgr_recon_from, $dbgr_recon_to );
			assert_true( is_wp_error( $result ) && $setup[2] === $result->get_error_code(), 'HTTP ' . $setup[1] . ' on call ' . $setup[0] . ' gives ' . $setup[2] );
			assert_same( $setup[0], $GLOBALS['dbgr_recon_square']['calls'], 'no request after the failure' );
		}
		$GLOBALS['dbgr_http'] = array( 'expect' => array(), 'calls' => array() );
		dbgr_test_http_expect( 'https://connect.squareup.com/v2/labor/timecards/search', new WP_Error( 'http_request_failed', 'timeout' ) );
		$result = DoughBoss_Growth_Recon_Square::search_timecards( array( 'LREV1' ), $dbgr_recon_from, $dbgr_recon_to );
		assert_true( is_wp_error( $result ) && 'square_transport' === $result->get_error_code(), 'transport error' );
		dbgr_recon_square_config( null, false );
	}
);

db_test(
	'recon square: bad bodies fail closed (not JSON, errors in a 200, truncated, malformed cursor or list)',
	function () use ( $dbgr_recon_from, $dbgr_recon_to ) {
		dbgr_recon_square_config( 'production', true );
		$bodies = array(
			'<html>gateway</html>'                                   => 'square_response_unparseable',
			'{"errors":[{"code":"INVALID_REQUEST_ERROR"}]}'          => 'square_response_errors',
			'{"timecards":"nope"}'                                   => 'square_response_invalid',
			'{"timecards":[],"cursor":["x"]}'                        => 'square_response_invalid',
			'{"timecards":[{"id":"TC1"}]}'                           => 'square_response_invalid',
			str_repeat( ' ', DoughBoss_Growth_Http::MAX_BODY_BYTES ) => 'square_response_too_large',
		);
		foreach ( $bodies as $body => $code ) {
			$GLOBALS['dbgr_http'] = array( 'expect' => array(), 'calls' => array() );
			dbgr_recon_fake_square( array(), array( 'body_on' => array( 1 => $body ) ) );
			$result = DoughBoss_Growth_Recon_Square::search_timecards( array( 'LREV1' ), $dbgr_recon_from, $dbgr_recon_to );
			assert_true( is_wp_error( $result ) && $code === $result->get_error_code(), 'body gives ' . $code . ' (got ' . ( is_wp_error( $result ) ? $result->get_error_code() : 'success' ) . ')' );
		}
		dbgr_recon_square_config( null, false );
	}
);

db_test(
	'recon square: wrong-filter responses are rejected (Square ignores an invalid filter instead of failing)',
	function () use ( $dbgr_recon_from, $dbgr_recon_to ) {
		dbgr_recon_square_config( 'production', true );
		$in_window = dbgr_recon_tc( 'TC1', 'TM11', 'LREV1', '2026-10-14T22:00:00Z', '2026-10-15T06:00:00Z' );
		$cases     = array(
			'other location' => array( array( $in_window, dbgr_recon_tc( 'TC2', 'TM11', 'LOTHER', '2026-10-14T22:00:00Z', '2026-10-15T06:00:00Z' ) ), 'square_scope_location' ),
			'outside window' => array( array( $in_window, dbgr_recon_tc( 'TC3', 'TM11', 'LREV1', '2026-09-01T22:00:00Z', '2026-09-02T06:00:00Z' ) ), 'square_scope_window' ),
			'closed in open' => array( array( dbgr_recon_tc( 'TC4', 'TM11', 'LREV1', '2026-09-01T22:00:00Z', '2026-09-02T06:00:00Z' ) ), 'square_scope_window' ),
		);
		foreach ( $cases as $label => $setup ) {
			$GLOBALS['dbgr_http'] = array( 'expect' => array(), 'calls' => array() );
			dbgr_recon_fake_square( $setup[0], array( 'ignore_filter' => true ) );
			$result = DoughBoss_Growth_Recon_Square::search_timecards( array( 'LREV1' ), $dbgr_recon_from, $dbgr_recon_to );
			assert_true( is_wp_error( $result ) && $setup[1] === $result->get_error_code(), $label . ' gives ' . $setup[1] . ' (got ' . ( is_wp_error( $result ) ? $result->get_error_code() : 'success' ) . ')' );
		}
		// A closed timecard returned to the OPEN-only search (window search answered correctly).
		$GLOBALS['dbgr_http'] = array( 'expect' => array(), 'calls' => array() );
		dbgr_recon_fake_square( array( $in_window ), array( 'body_on' => array( 2 => wp_json_encode( array( 'timecards' => array( $in_window ) ) ) ) ) );
		$result = DoughBoss_Growth_Recon_Square::search_timecards( array( 'LREV1' ), $dbgr_recon_from, $dbgr_recon_to );
		assert_true( is_wp_error( $result ) && 'square_scope_status' === $result->get_error_code(), 'closed timecard in the open search is rejected' );
		dbgr_recon_square_config( null, false );
	}
);

db_test(
	'recon square: a cursor loop and an endless cursor are detected',
	function () use ( $dbgr_recon_from, $dbgr_recon_to ) {
		dbgr_recon_square_config( 'production', true );
		dbgr_recon_fake_square( dbgr_recon_many_cards( 120 ), array( 'loop_cursor' => true ) );
		$result = DoughBoss_Growth_Recon_Square::search_timecards( array( 'LREV1' ), $dbgr_recon_from, $dbgr_recon_to );
		assert_true( is_wp_error( $result ) && 'square_pagination_loop' === $result->get_error_code(), 'repeated cursor detected' );

		$GLOBALS['dbgr_http'] = array( 'expect' => array(), 'calls' => array() );
		$GLOBALS['dbgr_recon_endless'] = 0;
		dbgr_test_http_expect(
			'https://connect.squareup.com/v2/labor/timecards/search',
			function () {
				$GLOBALS['dbgr_recon_endless']++;
				return dbgr_test_http_response( 200, wp_json_encode( array( 'timecards' => array(), 'cursor' => 'c' . $GLOBALS['dbgr_recon_endless'] ) ) );
			},
			array( 'times' => 0 )
		);
		$result = DoughBoss_Growth_Recon_Square::search_timecards( array( 'LREV1' ), $dbgr_recon_from, $dbgr_recon_to );
		assert_true( is_wp_error( $result ) && 'square_pagination_cap' === $result->get_error_code(), 'page cap stops an endless search' );
		assert_same( DoughBoss_Growth_Recon_Square::MAX_PAGES, $GLOBALS['dbgr_recon_endless'], 'stopped exactly at the cap' );
		dbgr_recon_square_config( null, false );
	}
);

db_test(
	'recon square: duplicates are merged by version or refused when they cannot be reconciled',
	function () use ( $dbgr_recon_from, $dbgr_recon_to ) {
		dbgr_recon_square_config( 'production', true );
		$open_v1 = dbgr_recon_tc( 'TC1', 'TM11', 'LREV1', '2026-10-14T22:00:00Z', null, array( 'version' => 1 ) );
		dbgr_recon_fake_square( array( $open_v1 ) );
		$result = DoughBoss_Growth_Recon_Square::search_timecards( array( 'LREV1' ), $dbgr_recon_from, $dbgr_recon_to );
		assert_count( 1, $result['timecards'], 'an open timecard found by both searches is kept once' );

		$closed_v2 = dbgr_recon_tc( 'TC1', 'TM11', 'LREV1', '2026-10-14T22:00:00Z', '2026-10-15T06:00:00Z', array( 'version' => 2 ) );
		$GLOBALS['dbgr_http'] = array( 'expect' => array(), 'calls' => array() );
		dbgr_recon_fake_square( array(), array( 'body_on' => array( 1 => wp_json_encode( array( 'timecards' => array( $closed_v2 ) ) ), 2 => wp_json_encode( array( 'timecards' => array( $open_v1 ) ) ) ) ) );
		$result = DoughBoss_Growth_Recon_Square::search_timecards( array( 'LREV1' ), $dbgr_recon_from, $dbgr_recon_to );
		assert_same( 'CLOSED', $result['timecards'][0]['status'], 'the newer version wins' );

		$open_v2_other = dbgr_recon_tc( 'TC1', 'TM11', 'LREV1', '2026-10-14T22:30:00Z', null, array( 'version' => 2 ) );
		$GLOBALS['dbgr_http'] = array( 'expect' => array(), 'calls' => array() );
		dbgr_recon_fake_square( array(), array( 'body_on' => array( 1 => wp_json_encode( array( 'timecards' => array( $closed_v2 ) ) ), 2 => wp_json_encode( array( 'timecards' => array( $open_v2_other ) ) ) ) ) );
		$result = DoughBoss_Growth_Recon_Square::search_timecards( array( 'LREV1' ), $dbgr_recon_from, $dbgr_recon_to );
		assert_true( is_wp_error( $result ) && 'square_pagination_inconsistent' === $result->get_error_code(), 'same version, different content is refused' );

		$GLOBALS['dbgr_http'] = array( 'expect' => array(), 'calls' => array() );
		dbgr_recon_fake_square( array(), array( 'body_on' => array( 1 => wp_json_encode( array( 'timecards' => array( $closed_v2, $closed_v2 ) ) ) ) ) );
		$result = DoughBoss_Growth_Recon_Square::search_timecards( array( 'LREV1' ), $dbgr_recon_from, $dbgr_recon_to );
		assert_true( is_wp_error( $result ) && 'square_pagination_inconsistent' === $result->get_error_code(), 'a duplicate inside one search is refused' );
		dbgr_recon_square_config( null, false );
	}
);

db_test(
	'recon square: invalid requests are refused before any call',
	function () use ( $dbgr_recon_from, $dbgr_recon_to ) {
		dbgr_recon_square_config( 'production', true );
		foreach ( array( array( array(), $dbgr_recon_from, $dbgr_recon_to ), array( array( 'L REV' ), $dbgr_recon_from, $dbgr_recon_to ), array( array( 'LREV1' ), $dbgr_recon_to, $dbgr_recon_from ) ) as $args ) {
			$result = DoughBoss_Growth_Recon_Square::search_timecards( $args[0], $args[1], $args[2] );
			assert_true( is_wp_error( $result ) && 'square_request_invalid' === $result->get_error_code(), 'refused locally' );
		}
		assert_count( 0, dbgr_test_http_calls(), 'no call made' );
		dbgr_recon_square_config( null, false );
	}
);

db_test(
	'recon square: team-member search returns id and email only and asserts the active filter',
	function () {
		dbgr_recon_square_config( 'production', true );
		dbgr_test_http_expect(
			'https://connect.squareup.com/v2/team-members/search',
			dbgr_test_http_response( 200, wp_json_encode( array( 'team_members' => array( array( 'id' => 'TM11', 'status' => 'ACTIVE', 'email_address' => ' Staff.One@Example.COM ', 'given_name' => 'Staff', 'phone_number' => '+61400000000' ) ) ) ) )
		);
		$members = DoughBoss_Growth_Recon_Square::search_team_members( array( 'LREV1' ) );
		assert_same( array( array( 'id' => 'TM11', 'email' => 'staff.one@example.com' ) ), $members, 'reduced to id and normalised email' );
		$GLOBALS['dbgr_http'] = array( 'expect' => array(), 'calls' => array() );
		dbgr_test_http_expect( 'https://connect.squareup.com/v2/team-members/search', dbgr_test_http_response( 200, wp_json_encode( array( 'team_members' => array( array( 'id' => 'TM11', 'status' => 'INACTIVE' ) ) ) ) ) );
		$members = DoughBoss_Growth_Recon_Square::search_team_members( array( 'LREV1' ) );
		assert_true( is_wp_error( $members ) && 'square_scope_status' === $members->get_error_code(), 'inactive member in an active-only search is refused' );
		dbgr_recon_square_config( null, false );
	}
);

db_test(
	'recon square negative control: an undeclared Square call fails the test (the fake transport is armed)',
	function () use ( $dbgr_recon_from, $dbgr_recon_to ) {
		dbgr_recon_square_config( 'production', true );
		dbgr_test_expect_failure(
			function () use ( $dbgr_recon_from, $dbgr_recon_to ) {
				DoughBoss_Growth_Recon_Square::search_timecards( array( 'LREV1' ), $dbgr_recon_from, $dbgr_recon_to );
			},
			'a Square call with no expectation is caught'
		);
		dbgr_recon_square_config( null, false );
	}
);
