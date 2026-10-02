<?php
/**
 * WP-01 tests: the fail-closed rate limiter.
 *
 * Most cases run against the harness's in-memory SQLite database using the limiter's REAL CREATE TABLE
 * statement, so the atomic conditional UPDATE and INSERT IGNORE behave for real. Failure paths use the fake
 * $wpdb's forced errors.
 *
 * @package DoughBoss_Growth
 */

/**
 * Switch the fake $wpdb to SQLite and create the limiter table from the production DDL.
 *
 * @return void
 */
function dbgr_limiter_sqlite() {
	$GLOBALS['wpdb']->use_sqlite();
	foreach ( DoughBoss_Growth_Rate_Limit::schema() as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
}

db_test(
	'limiter: allows up to the limit inside a window, then refuses with a retry-after',
	function () {
		dbgr_limiter_sqlite();
		for ( $i = 1; $i <= 5; $i++ ) {
			$r = DoughBoss_Growth_Rate_Limit::hit( 'ip:abc', 5, HOUR_IN_SECONDS );
			assert_true( $r['allowed'], 'hit ' . $i . ' of 5 allowed' );
			assert_same( 'ok', $r['reason'], 'reason ok' );
		}
		$r = DoughBoss_Growth_Rate_Limit::hit( 'ip:abc', 5, HOUR_IN_SECONDS );
		assert_false( $r['allowed'], 'the sixth hit is refused' );
		assert_same( 'limited', $r['reason'], 'reason limited' );
		assert_true( $r['retry_after'] >= 1 && $r['retry_after'] <= HOUR_IN_SECONDS, 'retry_after within the window' );
		$rows = $GLOBALS['wpdb']->sqlite_raw( 'SELECT hits FROM wp_doughboss_growth_rate' );
		assert_same( 5, (int) $rows[0]['hits'], 'the counter never passes the limit' );
		// Hammering a full bucket never over-counts.
		for ( $i = 0; $i < 10; $i++ ) {
			DoughBoss_Growth_Rate_Limit::hit( 'ip:abc', 5, HOUR_IN_SECONDS );
		}
		$rows = $GLOBALS['wpdb']->sqlite_raw( 'SELECT hits FROM wp_doughboss_growth_rate' );
		assert_same( 5, (int) $rows[0]['hits'], 'still 5 after ten more refused hits' );
	}
);

db_test(
	'limiter: the window rolls over, and buckets are independent',
	function () {
		dbgr_limiter_sqlite();
		dbgr_test_set_time( 1790899200 ); // Exactly on an hour boundary.
		for ( $i = 0; $i < 3; $i++ ) {
			DoughBoss_Growth_Rate_Limit::hit( 'email:one', 3, HOUR_IN_SECONDS );
		}
		assert_false( DoughBoss_Growth_Rate_Limit::hit( 'email:one', 3, HOUR_IN_SECONDS )['allowed'], 'full' );
		assert_true( DoughBoss_Growth_Rate_Limit::hit( 'email:two', 3, HOUR_IN_SECONDS )['allowed'], 'another bucket is unaffected' );
		dbgr_test_set_time( 1790899200 + HOUR_IN_SECONDS - 1 );
		assert_false( DoughBoss_Growth_Rate_Limit::hit( 'email:one', 3, HOUR_IN_SECONDS )['allowed'], 'one second before the boundary: still full' );
		dbgr_test_set_time( 1790899200 + HOUR_IN_SECONDS );
		$r = DoughBoss_Growth_Rate_Limit::hit( 'email:one', 3, HOUR_IN_SECONDS );
		assert_true( $r['allowed'], 'a new window resets the bucket' );
		$rows = $GLOBALS['wpdb']->sqlite_raw( "SELECT hits, window_start FROM wp_doughboss_growth_rate WHERE bucket_key = 'email:one'" );
		assert_same( 1, (int) $rows[0]['hits'], 'reset to exactly one hit' );
		assert_same( 1790899200 + HOUR_IN_SECONDS, (int) $rows[0]['window_start'], 'window moved forward' );
		// Different window lengths on the same key use their own boundary maths.
		assert_true( DoughBoss_Growth_Rate_Limit::hit( 'global', 1, DAY_IN_SECONDS )['allowed'], 'daily bucket first hit' );
		assert_false( DoughBoss_Growth_Rate_Limit::hit( 'global', 1, DAY_IN_SECONDS )['allowed'], 'daily bucket second hit refused' );
	}
);

db_test(
	'limiter: the last slot has a single winner (two requests, limit 1)',
	function () {
		dbgr_limiter_sqlite();
		$first  = DoughBoss_Growth_Rate_Limit::hit( 'race', 1, MINUTE_IN_SECONDS );
		$second = DoughBoss_Growth_Rate_Limit::hit( 'race', 1, MINUTE_IN_SECONDS );
		assert_same( array( true, false ), array( $first['allowed'], $second['allowed'] ), 'exactly one of two is allowed' );

		// The race between "reset" and "insert": another request creates the bucket first. Simulated with
		// scripted answers: increment 0 rows, reset 0 rows, INSERT IGNORE 0 rows (lost), retry increment 1 row.
		$GLOBALS['wpdb'] = new DBGR_Test_WPDB();
		$calls           = array();
		$GLOBALS['wpdb']->respond(
			'/^UPDATE .*SET hits = hits \+ 1/',
			function () use ( &$calls ) {
				$calls[] = 'increment';
				return ( 1 === count( array_keys( $calls, 'increment', true ) ) ) ? 0 : 1;
			}
		);
		$GLOBALS['wpdb']->respond(
			'/^UPDATE .*SET window_start/',
			function () use ( &$calls ) {
				$calls[] = 'reset';
				return 0;
			}
		);
		$GLOBALS['wpdb']->respond(
			'/^INSERT IGNORE/',
			function () use ( &$calls ) {
				$calls[] = 'insert';
				return 0;
			}
		);
		$r = DoughBoss_Growth_Rate_Limit::hit( 'race2', 5, MINUTE_IN_SECONDS );
		assert_true( $r['allowed'], 'when the insert is lost to a concurrent request, the retried increment wins a slot' );
		assert_same( array( 'increment', 'reset', 'insert', 'increment' ), $calls, 'the four steps ran in order' );

		// And when the retried increment finds the bucket full, the request is limited, not allowed.
		$GLOBALS['wpdb'] = new DBGR_Test_WPDB();
		$GLOBALS['wpdb']->respond( '/^UPDATE /', 0 );
		$GLOBALS['wpdb']->respond( '/^INSERT IGNORE/', 0 );
		$r = DoughBoss_Growth_Rate_Limit::hit( 'race3', 5, MINUTE_IN_SECONDS );
		assert_false( $r['allowed'], 'nothing took a slot: refused' );
		assert_same( 'limited', $r['reason'], 'reported as limited' );
	}
);

db_test(
	'limiter FAILS CLOSED: a forced database error always denies, never allows',
	function () {
		dbgr_limiter_sqlite();
		assert_true( DoughBoss_Growth_Rate_Limit::hit( 'fc', 5, HOUR_IN_SECONDS )['allowed'], 'control: healthy storage allows' );

		$GLOBALS['wpdb']->fail_all( true );
		$r = DoughBoss_Growth_Rate_Limit::hit( 'fc', 5, HOUR_IN_SECONDS );
		assert_false( $r['allowed'], 'DENIED on a forced $wpdb error' );
		assert_same( 'storage_error', $r['reason'], 'reason storage_error' );
		$GLOBALS['wpdb']->clear_failures();

		// Failure at each individual step of the algorithm also denies.
		foreach ( array(
			'step 1 (increment)' => '/^UPDATE .*SET hits = hits/',
			'step 2 (reset)'     => '/^UPDATE .*SET window_start/',
			'step 3 (insert)'    => '/^INSERT IGNORE/',
		) as $label => $pattern ) {
			$GLOBALS['wpdb'] = new DBGR_Test_WPDB();
			$GLOBALS['wpdb']->respond( '/^UPDATE .*SET hits = hits/', 0 );
			$GLOBALS['wpdb']->respond( '/^UPDATE .*SET window_start/', 0 );
			$GLOBALS['wpdb']->respond( '/^INSERT IGNORE/', 0 );
			$GLOBALS['wpdb']->fail_on( $pattern );
			$r = DoughBoss_Growth_Rate_Limit::hit( 'fc-step', 5, HOUR_IN_SECONDS );
			assert_false( $r['allowed'], 'denied when ' . $label . ' errors' );
			assert_same( 'storage_error', $r['reason'], $label . ' reported as storage_error' );
		}

		// Missing table (never installed) is a storage error too, so the request is denied.
		$GLOBALS['wpdb'] = new DBGR_Test_WPDB();
		$GLOBALS['wpdb']->use_sqlite();
		$r = DoughBoss_Growth_Rate_Limit::hit( 'no-table', 5, HOUR_IN_SECONDS );
		assert_false( $r['allowed'], 'denied when the table does not exist' );
		assert_same( 'storage_error', $r['reason'], 'missing table is storage_error' );

		// No $wpdb at all.
		$saved           = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = null;
		$r               = DoughBoss_Growth_Rate_Limit::hit( 'no-db', 5, HOUR_IN_SECONDS );
		$GLOBALS['wpdb'] = $saved;
		assert_false( $r['allowed'], 'denied with no database object' );
	}
);

db_test(
	'limiter negative control: a fail-OPEN limiter would have let the same errors through',
	function () {
		dbgr_limiter_sqlite();
		$GLOBALS['wpdb']->fail_all( true );
		// What a fail-open limiter (the core limiter style) does: treat an unreadable counter as "no hits yet".
		$fail_open = function ( $bucket ) {
			global $wpdb;
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM wp_doughboss_growth_rate WHERE bucket_key = %s', $bucket ) );
			return null === $count || (int) $count < 5;
		};
		assert_true( $fail_open( 'x' ), 'control: the fail-open pattern allows on a storage error' );
		assert_false( DoughBoss_Growth_Rate_Limit::hit( 'x', 5, HOUR_IN_SECONDS )['allowed'], 'the real limiter denies the same error' );
	}
);

db_test(
	'limiter: invalid arguments are denied before any query runs',
	function () {
		dbgr_limiter_sqlite();
		foreach ( array(
			array( '', 5, 60 ),
			array( null, 5, 60 ),
			array( array( 'x' ), 5, 60 ),
			array( 'k', 0, 60 ),
			array( 'k', -1, 60 ),
			array( 'k', 5, 0 ),
			array( 'k', 5, -60 ),
			array( 'k', 'many', 60 ),
		) as $args ) {
			$GLOBALS['wpdb']->reset_log();
			$r = DoughBoss_Growth_Rate_Limit::hit( $args[0], $args[1], $args[2] );
			assert_false( $r['allowed'], 'denied for ' . var_export( $args, true ) );
			assert_same( 'invalid', $r['reason'], 'reason invalid' );
			assert_count( 0, $GLOBALS['wpdb']->queries, 'no query ran for invalid arguments' );
		}
	}
);

db_test(
	'limiter: long keys are hashed to the column width, consistently; queries are always prepared',
	function () {
		dbgr_limiter_sqlite();
		$long = 'ip:' . str_repeat( 'a', 300 );
		assert_true( DoughBoss_Growth_Rate_Limit::hit( $long, 1, HOUR_IN_SECONDS )['allowed'], 'first hit with a long key' );
		assert_false( DoughBoss_Growth_Rate_Limit::hit( $long, 1, HOUR_IN_SECONDS )['allowed'], 'the same long key maps to the same bucket' );
		$rows = $GLOBALS['wpdb']->sqlite_raw( 'SELECT bucket_key FROM wp_doughboss_growth_rate' );
		assert_same( 1, count( $rows ), 'one bucket row' );
		assert_true( strlen( $rows[0]['bucket_key'] ) <= DoughBoss_Growth_Rate_Limit::MAX_KEY, 'stored key fits the varchar(100) column' );
		assert_same( array(), $GLOBALS['wpdb']->unprepared, 'every statement went through $wpdb->prepare()' );

		// A hostile key cannot break the SQL.
		$evil = "x'; DROP TABLE wp_doughboss_growth_rate; --";
		assert_true( DoughBoss_Growth_Rate_Limit::hit( $evil, 5, HOUR_IN_SECONDS )['allowed'], 'a hostile key is just a key' );
		$rows = $GLOBALS['wpdb']->sqlite_raw( 'SELECT COUNT(*) AS n FROM wp_doughboss_growth_rate' );
		assert_same( 2, (int) $rows[0]['n'], 'the table still exists and holds both buckets' );
	}
);

db_test(
	'limiter: hit_all() needs every bucket to allow and stops at the first denial',
	function () {
		dbgr_limiter_sqlite();
		$buckets = array(
			array( 'ip:h', 5, HOUR_IN_SECONDS ),
			array( 'email:h', 1, DAY_IN_SECONDS ),
			array( 'global', 300, DAY_IN_SECONDS ),
		);
		assert_true( DoughBoss_Growth_Rate_Limit::hit_all( $buckets )['allowed'], 'all three allow' );
		$r = DoughBoss_Growth_Rate_Limit::hit_all( $buckets );
		assert_false( $r['allowed'], 'the per-email bucket is now full' );
		assert_same( 'limited', $r['reason'], 'reason from the denying bucket' );
		$rows = $GLOBALS['wpdb']->sqlite_raw( "SELECT hits FROM wp_doughboss_growth_rate WHERE bucket_key = 'global'" );
		assert_same( 1, (int) $rows[0]['hits'], 'the later bucket was not charged after the denial' );
		assert_false( DoughBoss_Growth_Rate_Limit::hit_all( array( array( 'k', 1 ) ) )['allowed'], 'a malformed spec is denied' );
		assert_false( DoughBoss_Growth_Rate_Limit::hit_all( array() )['allowed'], 'an empty list is denied (nothing was checked)' );
	}
);

db_test(
	'limiter: IP hashing is salted, daily-rotating and never stores or logs the raw address',
	function () {
		$ip = '203.0.113.9';
		$h1 = DoughBoss_Growth_Rate_Limit::hash_ip( $ip );
		assert_matches( '/^[a-f0-9]{32}$/', $h1, '32 hex characters' );
		assert_not_contains( $ip, $h1, 'no raw IP in the hash' );
		assert_same( $h1, DoughBoss_Growth_Rate_Limit::hash_ip( $ip ), 'stable within a day' );
		assert_true( $h1 !== DoughBoss_Growth_Rate_Limit::hash_ip( '203.0.113.10' ), 'different addresses differ' );
		dbgr_test_advance( DAY_IN_SECONDS );
		assert_true( $h1 !== DoughBoss_Growth_Rate_Limit::hash_ip( $ip ), 'the hash rotates the next day' );
		assert_same( 'unknown', DoughBoss_Growth_Rate_Limit::hash_ip( 'not-an-ip' ), 'invalid address' );
		assert_same( 'unknown', DoughBoss_Growth_Rate_Limit::hash_ip( '' ), 'empty address' );
		assert_same( 'unknown', DoughBoss_Growth_Rate_Limit::hash_ip( null ), 'null address' );
		assert_matches( '/^[a-f0-9]{32}$/', DoughBoss_Growth_Rate_Limit::hash_ip( '2001:db8::1' ), 'IPv6 hashes too' );

		dbgr_limiter_sqlite();
		DoughBoss_Growth_Rate_Limit::hit( 'ip:' . $h1, 5, HOUR_IN_SECONDS );
		$dump = wp_json_encode( $GLOBALS['wpdb']->sqlite_raw( 'SELECT * FROM wp_doughboss_growth_rate' ) );
		assert_not_contains( $ip, $dump, 'the raw IP is not in the table' );
	}
);

db_test(
	'limiter: client_ip() trusts REMOTE_ADDR only; forwarded headers are ignored unless a filter supplies them',
	function () {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.77';
		$_SERVER['HTTP_CLIENT_IP']       = '198.51.100.78';
		assert_same( '203.0.113.9', DoughBoss_Growth_Rate_Limit::client_ip(), 'REMOTE_ADDR wins; spoofable headers ignored' );
		$_SERVER['REMOTE_ADDR'] = 'garbage';
		assert_same( '', DoughBoss_Growth_Rate_Limit::client_ip(), 'invalid REMOTE_ADDR gives an empty address' );
		unset( $_SERVER['REMOTE_ADDR'] );
		assert_same( '', DoughBoss_Growth_Rate_Limit::client_ip(), 'missing REMOTE_ADDR gives an empty address' );
		add_filter(
			'doughboss_growth_client_ip',
			function () {
				return '192.0.2.44';
			}
		);
		assert_same( '192.0.2.44', DoughBoss_Growth_Rate_Limit::client_ip(), 'a site behind a known proxy can supply the address by filter' );
		add_filter(
			'doughboss_growth_client_ip',
			function () {
				return 'not-an-ip';
			},
			20
		);
		assert_same( '', DoughBoss_Growth_Rate_Limit::client_ip(), 'a filter returning garbage is ignored' );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CLIENT_IP'] );
	}
);

db_test(
	'limiter: purge_expired() removes old buckets only and tolerates an error',
	function () {
		dbgr_limiter_sqlite();
		dbgr_test_set_time( 1790899200 );
		DoughBoss_Growth_Rate_Limit::hit( 'old', 5, HOUR_IN_SECONDS );
		dbgr_test_set_time( 1790899200 + 3 * DAY_IN_SECONDS );
		DoughBoss_Growth_Rate_Limit::hit( 'new', 5, HOUR_IN_SECONDS );
		assert_same( 1, DoughBoss_Growth_Rate_Limit::purge_expired(), 'one expired bucket removed' );
		$rows = $GLOBALS['wpdb']->sqlite_raw( 'SELECT bucket_key FROM wp_doughboss_growth_rate' );
		assert_same( array( array( 'bucket_key' => 'new' ) ), $rows, 'the live bucket remains' );
		$GLOBALS['wpdb']->fail_all( true );
		assert_same( 0, DoughBoss_Growth_Rate_Limit::purge_expired(), 'an error removes nothing and does not throw' );
	}
);

db_test(
	'limiter schema: the table is {prefix}doughboss_growth_rate with the frozen columns and only companion DDL',
	function () {
		$sql = DoughBoss_Growth_Rate_Limit::schema();
		assert_count( 1, $sql, 'one statement' );
		assert_matches( '/^CREATE TABLE wp_doughboss_growth_rate \(/', $sql[0], 'table name' );
		foreach ( array( 'bucket_key varchar(100) NOT NULL', 'window_start int(10) unsigned NOT NULL', 'hits int(10) unsigned NOT NULL DEFAULT 0', 'PRIMARY KEY  (bucket_key)' ) as $fragment ) {
			assert_contains( $fragment, $sql[0], 'column/key: ' . $fragment );
		}
		assert_same( 'wp_doughboss_growth_rate', DoughBoss_Growth_Rate_Limit::table(), 'table() uses the prefix' );
	}
);
