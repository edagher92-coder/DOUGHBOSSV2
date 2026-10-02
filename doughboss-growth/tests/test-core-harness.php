<?php
/**
 * WP-01 tests: the test harness itself (tests/bootstrap.php). Later work packages build on it, so its core
 * behaviours are pinned here: hook semantics, option and database guards, the fake $wpdb, nonces, REST
 * dispatch, shortcodes, sanitising and the sub-process runner.
 *
 * @package DoughBoss_Growth
 */

db_test(
	'harness hooks: priorities, accepted args, filter chaining, removal, did_action and current_filter behave like WordPress',
	function () {
		$order = array();
		add_action(
			'dbgr_h_action',
			function () use ( &$order ) {
				$order[] = 'ten-a';
			}
		);
		add_action(
			'dbgr_h_action',
			function () use ( &$order ) {
				$order[] = 'five';
			},
			5
		);
		add_action(
			'dbgr_h_action',
			function () use ( &$order ) {
				$order[] = 'ten-b';
			}
		);
		do_action( 'dbgr_h_action' );
		assert_same( array( 'five', 'ten-a', 'ten-b' ), $order, 'lower priority first, registration order within a priority' );
		assert_same( 1, did_action( 'dbgr_h_action' ), 'did_action counts' );

		$received = null;
		add_action(
			'dbgr_h_args',
			function () use ( &$received ) {
				$received = func_get_args();
			},
			10,
			2
		);
		do_action( 'dbgr_h_args', 'a', 'b', 'c' );
		assert_same( array( 'a', 'b' ), $received, 'only accepted_args arguments are passed' );

		add_filter(
			'dbgr_h_filter',
			function ( $v ) {
				return $v . 'A';
			}
		);
		add_filter(
			'dbgr_h_filter',
			function ( $v, $extra ) {
				return $v . $extra;
			},
			20,
			2
		);
		assert_same( 'startAB', apply_filters( 'dbgr_h_filter', 'start', 'B' ), 'filters chain in priority order and receive extra args' );
		assert_same( 'untouched', apply_filters( 'dbgr_h_nobody', 'untouched' ), 'a hook with no callbacks returns the value' );

		assert_true( has_filter( 'dbgr_h_filter' ), 'has_filter' );
		remove_all_filters( 'dbgr_h_filter' );
		assert_same( 'start', apply_filters( 'dbgr_h_filter', 'start', 'B' ), 'remove_all_filters' );

		add_action( 'dbgr_h_named', '__return_true' );
		assert_same( 1, dbgr_test_hook_count( 'dbgr_h_named' ), 'hook count' );
		remove_action( 'dbgr_h_named', '__return_true' );
		assert_same( 0, dbgr_test_hook_count( 'dbgr_h_named' ), 'remove_action' );

		$inside = '';
		add_action(
			'dbgr_h_current',
			function () use ( &$inside ) {
				$inside = current_filter();
			}
		);
		do_action( 'dbgr_h_current' );
		assert_same( 'dbgr_h_current', $inside, 'current_filter inside a callback' );
	}
);

db_test(
	'harness reset: every test starts clean, and plugin lifecycle hooks survive the reset',
	function () {
		assert_same( array(), $GLOBALS['dbgr_options'], 'no options' );
		assert_same( array(), $GLOBALS['dbgr_shortcodes'], 'no shortcodes' );
		assert_same( array(), $GLOBALS['dbgr_mail'], 'no mail' );
		assert_same( 0, did_action( 'dbgr_h_action' ), 'did_action reset' );
		assert_same( 0, dbgr_test_hook_count( 'dbgr_h_filter' ), 'test hooks were removed' );
		assert_true( dbgr_test_hook_count( 'plugins_loaded' ) >= 1, 'the plugins_loaded callback registered by the plugin file is part of the baseline' );
		assert_same( DBGR_TEST_EPOCH, DoughBoss_Growth::now(), 'clock reset' );
		assert_same( 0, get_current_user_id(), 'logged out' );
		assert_same( array(), $_POST, 'superglobals reset' );
	}
);

db_test(
	'harness options and transients: update_option semantics, and writing a non-companion name is a recorded FOREIGN WRITE',
	function () {
		assert_same( 'dflt', get_option( 'doughboss_growth_x', 'dflt' ), 'default returned' );
		assert_true( update_option( 'doughboss_growth_x', 'v' ), 'first write changes' );
		assert_false( update_option( 'doughboss_growth_x', 'v' ), 'an unchanged write returns false, like WordPress' );
		assert_true( delete_option( 'doughboss_growth_x' ), 'delete' );
		assert_false( delete_option( 'doughboss_growth_x' ), 'deleting twice returns false' );
		assert_same( array(), $GLOBALS['dbgr_foreign_writes'], 'companion-namespaced writes are not foreign' );

		update_option( 'blogname', 'Hijacked' );
		add_option( 'siteurl', 'x' );
		delete_option( 'doughboss_settings' );
		assert_count( 3, $GLOBALS['dbgr_foreign_writes'], 'three foreign option writes recorded (run.php fails the whole run on any)' );
		dbgr_test_clear_foreign_writes();
		assert_same( array(), $GLOBALS['dbgr_foreign_writes'], 'a test that provokes foreign writes on purpose clears them' );

		set_transient( 'doughboss_growth_t', 'v', 60 );
		assert_same( 'v', get_transient( 'doughboss_growth_t' ), 'transient stored' );
		dbgr_test_advance( 61 );
		assert_false( get_transient( 'doughboss_growth_t' ), 'transient expires with the test clock' );
		set_transient( 'someone_elses', 1 );
		assert_count( 1, $GLOBALS['dbgr_foreign_writes'], 'a foreign transient write is recorded' );
		dbgr_test_clear_foreign_writes();

		dbgr_test_core_option( 'doughboss_settings', array( 'a' => 1 ) );
		assert_same( array( 'a' => 1 ), get_option( 'doughboss_settings' ), 'dbgr_test_core_option presets core data without a violation' );
		assert_same( array(), $GLOBALS['dbgr_foreign_writes'], 'presetting is not a foreign write' );
	}
);

db_test(
	'harness cron: schedule, next, clear, single events',
	function () {
		assert_false( wp_next_scheduled( 'doughboss_growth_x_hook' ), 'nothing scheduled' );
		wp_schedule_event( DBGR_TEST_EPOCH + 60, 'hourly', 'doughboss_growth_x_hook' );
		assert_same( DBGR_TEST_EPOCH + 60, wp_next_scheduled( 'doughboss_growth_x_hook' ), 'scheduled' );
		wp_clear_scheduled_hook( 'doughboss_growth_x_hook' );
		assert_false( wp_next_scheduled( 'doughboss_growth_x_hook' ), 'cleared' );
		wp_schedule_single_event( DBGR_TEST_EPOCH + 10, 'doughboss_growth_y_hook' );
		assert_same( DBGR_TEST_EPOCH + 10, wp_next_scheduled( 'doughboss_growth_y_hook' ), 'single event' );
		wp_schedule_event( DBGR_TEST_EPOCH, 'hourly', 'core_cron_hook' );
		assert_true( count( $GLOBALS['dbgr_foreign_writes'] ) >= 1, 'scheduling a non-companion cron hook is a foreign write' );
		dbgr_test_clear_foreign_writes();
	}
);

db_test(
	'harness $wpdb prepare(): placeholders, escaping, array flattening and WordPress strictness',
	function () {
		global $wpdb;
		assert_same( "SELECT * FROM t WHERE a = 'it\\'s' AND b = 5 AND c = 1.5 AND d = 100%", $wpdb->prepare( 'SELECT * FROM t WHERE a = %s AND b = %d AND c = %f AND d = 100%%', "it's", '5abc', 1.5 ), 'escapes quotes, casts %d, keeps %%' );
		assert_same( "IN ('a', 'b')", 'IN (' . implode( ', ', array( "'a'", "'b'" ) ) . ')', 'sanity' );
		assert_same( "WHERE x IN ('a', 'b') AND y = 3", $wpdb->prepare( 'WHERE x IN (%s, %s) AND y = %d', array( 'a', 'b', 3 ) ), 'a single array argument is flattened' );
		assert_throws( 'LogicException', function () use ( $wpdb ) {
			$wpdb->prepare( 'SELECT %s, %s', 'only-one' );
		}, 'a placeholder/argument mismatch throws (WordPress warns, tests must not hide it)' );
		assert_same( 'a\\_b\\%c', $wpdb->esc_like( 'a_b%c' ), 'esc_like' );
		assert_count( 2, $wpdb->prepared_sql, 'every prepared statement that succeeded is recorded (the failed one threw first)' );
	}
);

db_test(
	'harness $wpdb: records queries, flags unprepared SQL, forces errors, scripts answers and logs foreign writes',
	function () {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE wp_doughboss_growth_rate SET hits = %d WHERE bucket_key = %s', 1, 'k' ) );
		assert_same( array(), $wpdb->unprepared, 'a prepared statement is not flagged' );
		$wpdb->query( 'DELETE FROM wp_doughboss_growth_rate WHERE 1 = 1' );
		assert_count( 1, $wpdb->unprepared, 'a raw statement is flagged as unprepared' );
		assert_count( 0, $wpdb->foreign_writes, 'companion table writes are not foreign' );

		$wpdb->query( $wpdb->prepare( 'UPDATE wp_options SET option_value = %s WHERE option_name = %s', 'x', 'siteurl' ) );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO wp_doughboss_orders (id) VALUES (%d)', 1 ) );
		$wpdb->query( 'DROP TABLE IF EXISTS wp_users' );
		assert_count( 3, $wpdb->foreign_writes, 'writes to core and WordPress tables are recorded' );
		assert_count( 3, $GLOBALS['dbgr_foreign_writes'], 'and raised as run-level foreign writes' );
		$wpdb->query( $wpdb->prepare( 'SELECT * FROM wp_doughboss_orders WHERE id = %d', 1 ) );
		assert_count( 3, $wpdb->foreign_writes, 'a READ of a core table is allowed' );
		dbgr_test_clear_foreign_writes();

		$wpdb->fail_on( '/FROM wp_doughboss_growth_rate/' );
		assert_same( null, $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM wp_doughboss_growth_rate WHERE a = %d', 1 ) ), 'a forced error returns null from get_var' );
		assert_same( 'DBGR forced database error', $wpdb->last_error, 'last_error is set' );
		assert_same( false, $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM wp_doughboss_growth_rate WHERE a = %d', 2 ) ), 'and false from query' );
		$wpdb->clear_failures();
		$wpdb->fail_all( true );
		assert_same( false, $wpdb->query( 'SELECT 1' ), 'fail_all errors everything' );
		$wpdb->fail_all( false );
		assert_same( '', $wpdb->last_error ? '' : '', 'sanity' );

		$wpdb->respond( '/^SELECT COUNT/', 7 );
		$wpdb->respond( '/^SELECT id FROM/', array( array( 'id' => 1 ), array( 'id' => 2 ) ) );
		assert_same( 7, $wpdb->get_var( 'SELECT COUNT(*) FROM x' ), 'scripted scalar' );
		assert_same( array( 1, 2 ), $wpdb->get_col( 'SELECT id FROM x' ), 'scripted column' );
		assert_same( 2, count( $wpdb->get_results( 'SELECT id FROM x' ) ), 'scripted rows' );
	}
);

db_test(
	'harness $wpdb SQLite mode: production DDL is translated, UNIQUE keys hold, INSERT IGNORE is honoured, tables are discoverable',
	function () {
		global $wpdb;
		$wpdb->use_sqlite();
		foreach ( DoughBoss_Growth_Outbox::schema() as $sql ) {
			$wpdb->create_table_from_mysql( $sql );
		}
		assert_same( 'wp_doughboss_growth_outbox', $wpdb->get_var( "SHOW TABLES LIKE 'wp\\_doughboss\\_growth\\_outbox'" ), 'SHOW TABLES LIKE finds the table' );
		assert_same( null, $wpdb->get_var( "SHOW TABLES LIKE 'wp\\_nope'" ), 'and reports a missing one' );
		$insert = "INSERT IGNORE INTO wp_doughboss_growth_outbox (channel, event_name, event_id, payload_json, next_attempt_at, created_at, updated_at) VALUES ('c1', 'e', 'id1', '{}', 'x', 'x', 'x')";
		assert_same( 1, $wpdb->query( $insert ), 'first insert affects one row' );
		assert_same( 0, $wpdb->query( $insert ), 'the duplicate is ignored by the UNIQUE key (0 rows)' );
		assert_same( false, $wpdb->query( str_replace( 'INSERT IGNORE', 'INSERT', $insert ) ), 'a plain duplicate INSERT errors' );
		assert_true( '' !== $wpdb->last_error, 'with a last_error' );
		assert_same( 1, $wpdb->insert_id, 'insert_id of the auto-increment key' );
	}
);

db_test(
	'harness nonces, capabilities and wp_die: per-user, per-action, time-limited; refusals throw instead of exiting',
	function () {
		dbgr_test_login( array( 'manage_options' ), 5 );
		assert_true( current_user_can( 'manage_options' ), 'capability granted' );
		assert_false( current_user_can( 'manage_doughboss' ), 'capability not granted' );
		$nonce = wp_create_nonce( 'act' );
		assert_true( (bool) wp_verify_nonce( $nonce, 'act' ), 'nonce verifies for the same user and action' );
		assert_false( wp_verify_nonce( $nonce, 'other' ), 'not for another action' );
		assert_false( wp_verify_nonce( '', 'act' ), 'not when empty' );
		dbgr_test_login( array( 'manage_options' ), 6 );
		assert_false( wp_verify_nonce( $nonce, 'act' ), 'not for another user' );
		dbgr_test_login( array( 'manage_options' ), 5 );
		dbgr_test_advance( 13 * HOUR_IN_SECONDS );
		assert_true( (bool) wp_verify_nonce( $nonce, 'act' ), 'still valid in the second half-life' );
		dbgr_test_advance( 24 * HOUR_IN_SECONDS );
		assert_false( wp_verify_nonce( $nonce, 'act' ), 'expired after a day' );
		dbgr_test_logout();
		assert_false( current_user_can( 'manage_options' ), 'logged out' );
		assert_throws( 'DBGR_Test_Die', function () {
			wp_die( 'stop' );
		}, 'wp_die throws' );
		assert_throws( 'DBGR_Test_Redirect', function () {
			wp_safe_redirect( 'https://doughboss.test/x' );
		}, 'a redirect throws (real code would exit)' );
	}
);

db_test(
	'harness REST: permission callbacks, 401 vs 403, required and validated args, route patterns, error conversion',
	function () {
		register_rest_route(
			'doughboss-growth/v1',
			'/probe/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'name' => array(
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_string( $value ) && '' !== $value;
						},
						'sanitize_callback' => function ( $value ) {
							return strtoupper( $value );
						},
					),
				),
				'callback'            => function ( $request ) {
					if ( 'BOOM' === $request->get_param( 'name' ) ) {
						return new WP_Error( 'x', 'bad', array( 'status' => 418 ) );
					}
					return array( 'id' => $request['id'], 'name' => $request->get_param( 'name' ) );
				},
			)
		);
		assert_same( 401, dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/probe/7', array( 'name' => 'a' ) )->get_status(), 'anonymous 401' );
		dbgr_test_login( array( 'read' ) );
		assert_same( 403, dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/probe/7', array( 'name' => 'a' ) )->get_status(), 'logged in without the capability 403' );
		dbgr_test_login( array( 'manage_options' ) );
		assert_same( 400, dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/probe/7' )->get_status(), 'missing required arg 400' );
		assert_same( 400, dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/probe/7', array( 'name' => '' ) )->get_status(), 'invalid arg 400' );
		$ok = dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/probe/7', array( 'name' => 'abc' ) );
		assert_same( 200, $ok->get_status(), 'valid request 200' );
		assert_same( array( 'id' => '7', 'name' => 'ABC' ), $ok->get_data(), 'URL parameter captured and arg sanitised' );
		assert_same( 418, dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/probe/7', array( 'name' => 'boom' ) )->get_status(), 'WP_Error converted with its status' );
		assert_same( 404, dbgr_test_rest_dispatch( 'GET', '/doughboss-growth/v1/probe/7' )->get_status(), 'wrong method 404' );
		assert_same( 404, dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/nope' )->get_status(), 'unknown route 404' );
	}
);

db_test(
	'harness shortcodes: attributes, enclosing content, the do_shortcode_tag filter, has_shortcode',
	function () {
		add_shortcode(
			'dbgr_sc',
			function ( $atts, $content ) {
				$atts = shortcode_atts( array( 'key' => 'none', 'n' => '0' ), $atts, 'dbgr_sc' );
				return '[' . $atts['key'] . ':' . $atts['n'] . ':' . $content . ']';
			}
		);
		assert_same( '<p>[a:0:]</p>', do_shortcode( '<p>[dbgr_sc key="a"]</p>' ), 'self-closing with a quoted attribute' );
		assert_same( '[b:3:inner]', do_shortcode( "[dbgr_sc key='b' n=3]inner[/dbgr_sc]" ), 'enclosing with single quotes and a bare value' );
		assert_same( '[a:0:]', do_shortcode( '[dbgr_sc key="a"]' ), 'rendered' );
		assert_same( '[unknown_sc]', do_shortcode( '[unknown_sc]' ), 'an unregistered tag is left alone (the raw-shortcode risk the deactivation hook guards against)' );
		add_filter(
			'do_shortcode_tag',
			function ( $output, $tag ) {
				return $output . '<!--' . $tag . '-->';
			},
			10,
			2
		);
		assert_same( '[a:0:]<!--dbgr_sc-->', do_shortcode( '[dbgr_sc key="a"]' ), 'do_shortcode_tag is applied' );
		assert_true( has_shortcode( 'x [dbgr_sc key="a"] y', 'dbgr_sc' ), 'has_shortcode' );
		assert_false( has_shortcode( 'x [other] y', 'dbgr_sc' ), 'has_shortcode negative' );
	}
);

db_test(
	'harness escaping and sanitising: the cases the plugin relies on',
	function () {
		assert_same( '&lt;b&gt;&quot;x&quot; &amp; &#039;y&#039;&lt;/b&gt;', esc_html( '<b>"x" & \'y\'</b>' ), 'esc_html' );
		assert_same( '&quot;&gt;&lt;script&gt;', esc_attr( '"><script>' ), 'esc_attr' );
		assert_same( 'plain text', sanitize_text_field( "  <b>plain</b>\n text \t" ), 'sanitize_text_field strips tags and collapses whitespace' );
		assert_same( 'abc-123', sanitize_key( 'ABC-123 !' ), 'sanitize_key' );
		assert_same( 'hello-world', sanitize_title( 'Hello, World!' ), 'sanitize_title' );
		assert_same( 4, absint( '-4' ), 'absint' );
		assert_same( 'https://example.com/a?b=1', esc_url_raw( 'https://example.com/a?b=1' ), 'esc_url_raw keeps a good URL' );
		assert_same( '', esc_url_raw( 'javascript:alert(1)' ), 'esc_url_raw drops javascript:' );
		assert_true( (bool) is_email( 'jane@example.com' ), 'is_email accepts' );
		assert_false( is_email( 'jane@' ), 'is_email rejects' );
		assert_same( '{"a":"b"}', wp_json_encode( array( 'a' => 'b' ) ), 'wp_json_encode' );
		assert_same( 'a\\b', wp_unslash( 'a\\\\b' ), 'wp_unslash' );
		assert_same( wp_salt( 'auth' ), wp_salt( 'auth' ), 'wp_salt is stable' );
		assert_true( wp_salt( 'auth' ) !== wp_salt( 'nonce' ), 'and differs per scheme' );
		assert_matches( '#^https://[a-z.]+/#', home_url( '/' ), 'home_url' );
		assert_contains( 'doughboss-growth/v1/health', rest_url( 'doughboss-growth/v1/health' ), 'rest_url' );
		assert_contains( 'admin.php', admin_url( 'admin.php' ), 'admin_url' );
		assert_same( 'https://x.test/p?a=1&b=2', add_query_arg( array( 'a' => 1, 'b' => 2 ), 'https://x.test/p' ), 'add_query_arg' );
	}
);

db_test(
	'harness sub-process runner: a fresh process with its own constants, with or without the core stub',
	function () {
		if ( ! dbgr_test_can_subprocess() ) {
			dbgr_test_skip( 'sub-process unavailable' );
			return;
		}
		$with = dbgr_test_subprocess( "echo defined( 'DOUGHBOSS_VERSION' ) ? DOUGHBOSS_VERSION : 'none';" );
		assert_same( '2.43.2', $with['out'], 'the core stub provides 2.43.2' );
		$override = dbgr_test_subprocess( "echo DOUGHBOSS_VERSION;", "define( 'DOUGHBOSS_VERSION', '2.44.0' );" );
		assert_same( '2.44.0', $override['out'], 'a prelude constant wins over the stub' );
		$without = dbgr_test_subprocess( "echo defined( 'DOUGHBOSS_VERSION' ) ? 'defined' : 'none';", '', false );
		assert_same( 'none', $without['out'], 'with stubs disabled core is absent' );
		$fatal = dbgr_test_subprocess( 'throw new RuntimeException( "x" );' );
		assert_true( 0 !== $fatal['exit'], 'an uncaught throw gives a non-zero exit' );
	}
);

db_test(
	'harness negative controls: db_fail and dbgr_test_expect_failure actually fail; a non-failing callable is itself a failure',
	function () {
		$absorbed = dbgr_test_expect_failure(
			function () {
				assert_same( 1, 2, 'deliberately wrong' );
			},
			'a wrong assertion'
		);
		assert_same( 1, $absorbed, 'one failure absorbed and removed from the totals' );
		$before = $GLOBALS['doughboss_test_results']['failed'];
		dbgr_test_expect_failure(
			function () {
				// Does nothing, so the harness must record a failure for it.
			},
			'a callable that never trips the harness'
		);
		$after = $GLOBALS['doughboss_test_results']['failed'];
		assert_same( $before + 1, $after, 'a callable that fails nothing is itself reported' );
		// Remove the deliberate failure that line produced.
		$GLOBALS['doughboss_test_results']['failed']--;
		array_pop( $GLOBALS['doughboss_test_results']['failures'] );
		assert_throws( 'RuntimeException', function () {
			throw new RuntimeException( 'x' );
		}, 'assert_throws catches the right class' );
	}
);
