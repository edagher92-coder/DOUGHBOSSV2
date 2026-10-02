<?php
/**
 * WP-04 test suite body: attribution capture and side records. Not picked up by run.php on its own (the name does not
 * start with "test-"): tests/test-attribution.php runs it in a separate PHP process and replays the results, because
 * these tests load the real consent module and WP-03's test asserts that module is NOT loaded at the point it runs.
 *
 * Covers the sanitiser against the TypeScript oracle (tests/fixtures/attribution-cases.json), consent gating on the
 * server, the two tables (real CREATE TABLE statements on the harness's in-memory SQLite database), the catering
 * enquiry hook driven through the harness's REAL REST dispatcher (so rest_request_before_callbacks, the permission
 * callback, core's callback and rest_post_dispatch run in WordPress order), payment references, order inheritance,
 * every "never break the enquiry" failure path, the script enqueue and the admin tab. The ES5 browser behaviour is
 * covered by attribution.test.js; the real rendered page and the real core catering form by
 * web/scripts/wp-local/growth/wp04-attribution.mjs.
 *
 * @package DoughBoss_Growth
 */

// The module files define classes only; load them once so the pure-function tests need no boot.
DoughBoss_Growth::load_module( 'consent' );
DoughBoss_Growth::load_module( 'attribution' );

/** A consent cookie value for the given choice (wording version 1). */
function dbgr_at_consent_cookie( $m, $a ) {
	return wp_json_encode(
		array(
			'v'  => '1',
			'm'  => $m,
			'a'  => $a,
			'ts' => DBGR_TEST_EPOCH,
		)
	);
}

/** An attribution cookie value. */
function dbgr_at_cookie( array $attr ) {
	return wp_json_encode( $attr );
}

/** The campaign the visitor landed with. */
function dbgr_at_campaign() {
	return array(
		'utmSource'    => 'test',
		'utmMedium'    => 'cpc',
		'utmCampaign'  => 'eofy',
		'gclid'        => 'x',
		'referrerHost' => 'www.google.com',
		'landingPath'  => '/catering/',
		'firstSeenAt'  => '2026-10-01T01:02:03.456Z',
	);
}

/**
 * Boot the module on SQLite with its real tables.
 *
 * @param array $features Feature overrides (default: attribution and consent_banner on).
 * @param array $extra    Extra settings.
 * @return void
 */
function dbgr_at_boot( array $features = array(), array $extra = array() ) {
	$GLOBALS['wpdb']->use_sqlite();
	DoughBoss_Growth::load_module( 'consent' );
	DoughBoss_Growth::load_module( 'attribution' );
	foreach ( DoughBoss_Growth_Attribution::schema() as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
	update_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION, DOUGHBOSS_GROWTH_DB_VERSION );
	update_option(
		DoughBoss_Growth_Settings::OPTION,
		array_merge(
			array(
				'features'           => array_merge(
					array(
						'attribution'    => true,
						'consent_banner' => true,
					),
					$features
				),
				'privacy_policy_url' => 'https://example.com.au/privacy/',
			),
			$extra
		)
	);
	DoughBoss_Growth_Attribution::reset_state();
	DoughBoss_Growth_Consent::reset_state();
	DoughBoss_Order::$rows  = array();
	DoughBoss_Order::$throw = false;
	$GLOBALS['dbgr_at_enquiry_seq'] = 0;
	$GLOBALS['dbgr_at_hook_calls']  = array();
}

/** Set what the visitor's browser sends. */
function dbgr_at_visitor( $consent_m, $consent_a, $attr = null ) {
	// WordPress adds slashes to $_COOKIE (wp_magic_quotes), and the module un-slashes it again.
	$_COOKIE = array();
	if ( null !== $consent_m ) {
		$_COOKIE['dbgr_consent'] = wp_slash( dbgr_at_consent_cookie( $consent_m, $consent_a ) );
	}
	if ( null !== $attr ) {
		$_COOKIE['dbgr_attr'] = wp_slash( is_string( $attr ) ? $attr : dbgr_at_cookie( $attr ) );
	}
}

/** Stand in for core's REST routes: enquiry (honeypot, nonce) and payment-intent. Registers the companion hooks first. */
function dbgr_at_register_core_routes() {
	DoughBoss_Growth_Attribution::init();
	register_rest_route(
		'doughboss/v1',
		'/catering/enquiry',
		array(
			'methods'             => 'POST',
			'permission_callback' => function ( $request ) {
				return 'good' === $request->get_param( 'nonce' );
			},
			'callback'            => function ( $request ) {
				if ( '' !== (string) $request->get_param( 'hp' ) ) {
					return new WP_Error( 'honeypot', 'Rejected', array( 'status' => 400 ) );
				}
				if ( 'limit' === $request->get_param( 'scenario' ) ) {
					return new WP_Error( 'rate_limited', 'Too many requests', array( 'status' => 429 ) );
				}
				$GLOBALS['dbgr_at_enquiry_seq']++;
				$id  = 100 + $GLOBALS['dbgr_at_enquiry_seq'];
				$row = array(
					'enquiry_number' => 'E' . $id,
					'customer_email' => sanitize_email( (string) $request->get_param( 'customer_email' ) ),
				);
				do_action( 'doughboss_catering_enquiry_created', $id, $row );
				return array( 'enquiry_number' => 'E' . $id );
			},
		)
	);
	register_rest_route(
		'doughboss/v1',
		'/payment-intent',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => function ( $request ) {
				$scenario = (string) $request->get_param( 'scenario' );
				if ( 'declined' === $scenario ) {
					return new WP_Error( 'declined', 'No', array( 'status' => 402 ) );
				}
				if ( 'stripe' === $scenario ) {
					return rest_ensure_response( array( 'checkout_session' => 'cs_test_1', 'gateway' => 'stripe' ) );
				}
				$id = ( 'weird' === $scenario ) ? 'a b<c>' : 'sq_pay_123';
				return rest_ensure_response( array( 'payment_intent' => $id, 'gateway' => 'square' ) );
			},
		)
	);
}

/** POST an enquiry the way core's catering form does. */
function dbgr_at_enquire( array $params = array() ) {
	return dbgr_test_rest_dispatch(
		'POST',
		'/doughboss/v1/catering/enquiry',
		array_merge(
			array(
				'nonce'          => 'good',
				'customer_name'  => 'Test Person',
				'customer_email' => 'person@example.com',
			),
			$params
		)
	);
}

/** @return array Rows of a table as arrays. */
function dbgr_at_rows( $table ) {
	return $GLOBALS['wpdb']->sqlite_raw( 'SELECT * FROM ' . $GLOBALS['wpdb']->prefix . $table . ' ORDER BY id' );
}

/** @return array The first row of a table where a column equals a value, or array(). */
function dbgr_at_row( $table, $column, $value ) {
	foreach ( dbgr_at_rows( $table ) as $row ) {
		if ( (string) $row[ $column ] === (string) $value ) {
			return $row;
		}
	}
	return array();
}

/* ---------------------------------------------------------------------------------------------------------- */
/* Oracle parity                                                                                               */
/* ---------------------------------------------------------------------------------------------------------- */

$GLOBALS['dbgr_at_fixture'] = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/attribution-cases.json' ), true );

db_test(
	'oracle: the fixture is well formed',
	function () {
		$fixture = $GLOBALS['dbgr_at_fixture'];
		assert_true( is_array( $fixture ), 'fixture decodes' );
		assert_same( 1, $fixture['schema_version'], 'schema version' );
		assert_true( count( $fixture['cases'] ) >= 100, 'at least 100 cases (got ' . count( $fixture['cases'] ) . ')' );
		assert_matches( '/^[0-9a-f]{64}$/', $fixture['source_sha256'], 'source hash recorded' );
		assert_matches( '/^\d+\.\d+\.\d+/', $fixture['zod_version'], 'zod version recorded' );
		$names = array();
		foreach ( $fixture['cases'] as $case ) {
			$names[] = $case['name'];
		}
		assert_same( count( $names ), count( array_unique( $names ) ), 'case names are unique' );
	}
);

db_test(
	'oracle: the PHP sanitiser returns the TypeScript result for every case, key order included',
	function () {
		$mismatch = array();
		foreach ( $GLOBALS['dbgr_at_fixture']['cases'] as $case ) {
			$actual = DoughBoss_Growth_Attribution::sanitise( $case['input'] );
			if ( $actual !== $case['expected'] ) {
				$mismatch[] = $case['name'];
			}
			// Never touches the input and never throws for any shape (a hostile cookie must not break an enquiry).
		}
		assert_same( array(), $mismatch, 'cases where PHP differs from the oracle' );
	}
);

db_test(
	'oracle: the only cases where the companion differs from the TypeScript schema are the host-rule cases',
	function () {
		$differing = array();
		foreach ( $GLOBALS['dbgr_at_fixture']['cases'] as $case ) {
			if ( $case['ts_expected'] !== $case['expected'] ) {
				$differing[] = $case['name'];
				$dropped     = array_keys( array_diff_key( $case['ts_expected'], $case['expected'] ) );
				assert_same( array( 'referrerHost' ), $dropped, $case['name'] . ' differs only by the referrer host' );
			}
		}
		assert_same( $GLOBALS['dbgr_at_fixture']['host_rule_cases'], $differing, 'differing cases are exactly the listed host-rule cases' );
	}
);

db_test(
	'oracle: negative control, a sanitiser with a rule missing fails the fixture (so the parity test can fail)',
	function () {
		$naive = function ( $raw ) {
			// No control-character scan, no cap, no path or host rule: only "string, trimmed, non-empty".
			$out = array();
			if ( ! is_array( $raw ) ) {
				return $out;
			}
			foreach ( DoughBoss_Growth_Attribution::FIELDS as $field ) {
				if ( isset( $raw[ $field ] ) && is_string( $raw[ $field ] ) && '' !== trim( $raw[ $field ] ) ) {
					$out[ $field ] = trim( $raw[ $field ] );
				}
			}
			return $out;
		};
		dbgr_test_expect_failure(
			function () use ( $naive ) {
				foreach ( $GLOBALS['dbgr_at_fixture']['cases'] as $case ) {
					assert_same( $case['expected'], $naive( $case['input'] ), 'naive sanitiser on ' . $case['name'] );
				}
			},
			'a naive sanitiser'
		);
	}
);

db_test(
	'oracle: the fixture records the hash of attribution-schema.ts; a changed schema makes this test fail',
	function () {
		$schema = dirname( __DIR__, 2 ) . '/web/src/lib/attribution-schema.ts';
		if ( ! is_file( $schema ) ) {
			dbgr_test_skip( 'web/src/lib/attribution-schema.ts not found next to the plugin' );
			db_pass();
			return;
		}
		assert_same( $GLOBALS['dbgr_at_fixture']['source_sha256'], hash( 'sha256', (string) file_get_contents( $schema ) ), 'attribution-schema.ts changed: regenerate the fixture with export-attribution-fixtures.ts' );
	}
);

db_test(
	'sanitise: hostile and odd input never throws and never warns (invalid UTF-8, huge strings, nested arrays, objects)',
	function () {
		$weird = array(
			'utmSource'    => "bad\xC3\x28utf8",
			'utmMedium'    => str_repeat( 'x', 100000 ),
			'utmCampaign'  => array( 'nested' => array( 'deep' ) ),
			'utmTerm'      => new stdClass(),
			'gclid'        => 1.5,
			'referrerHost' => "\xFF\xFE",
			'landingPath'  => "/\xC0\xAF",
			'firstSeenAt'  => str_repeat( '9', 5000 ),
			'ok'           => 'ignored',
		);
		assert_same( array(), DoughBoss_Growth_Attribution::sanitise( $weird ), 'everything dropped' );
		foreach ( array( null, true, 5, 'text', 1.5 ) as $not_an_array ) {
			assert_same( array(), DoughBoss_Growth_Attribution::sanitise( $not_an_array ), 'non-array input' );
		}
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Registration: everything off by default                                                                      */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'inert: with every flag off the module registers no hook, no script, no tab',
	function () {
		DoughBoss_Growth::load_module( 'attribution' );
		DoughBoss_Growth_Attribution::init();
		foreach ( array( 'rest_request_before_callbacks', 'rest_post_dispatch', 'doughboss_catering_enquiry_created', 'doughboss_order_created', 'wp_enqueue_scripts', 'doughboss_growth_admin_tabs' ) as $hook ) {
			assert_same( 0, dbgr_test_hook_count( $hook ), $hook . ' has no callback' );
		}
	}
);

db_test(
	'registry: the attribution flag boots the module through the plugin bootstrap and registers its hooks',
	function () {
		$GLOBALS['wpdb']->use_sqlite();
		update_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION, DOUGHBOSS_GROWTH_DB_VERSION );
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'attribution' => true, 'consent_banner' => true ) ) );
		DoughBoss_Growth::init();
		assert_true( dbgr_test_hook_count( 'doughboss_catering_enquiry_created' ) >= 1, 'enquiry hook registered' );
		assert_true( dbgr_test_hook_count( 'doughboss_order_created' ) >= 1, 'order hook registered' );
		assert_true( dbgr_test_hook_count( 'rest_request_before_callbacks' ) >= 1, 'stash filter registered' );
		assert_true( dbgr_test_hook_count( 'rest_post_dispatch' ) >= 1, 'post-dispatch filter registered' );
		$health = DoughBoss_Growth::health();
		assert_true( $health['modules_active']['attribution'], 'module reported active' );
	}
);

db_test(
	'registry: storage not ready means the module stays inert (fail closed)',
	function () {
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'attribution' => true, 'consent_banner' => true ) ) );
		DoughBoss_Growth::init();
		assert_same( 0, dbgr_test_hook_count( 'doughboss_catering_enquiry_created' ), 'no hook without the schema' );
	}
);

db_test(
	'lead_form alone keeps only the lead-record hook: no order hook, no script, no payment stash, the cookie is never read',
	function () {
		dbgr_at_boot( array( 'attribution' => false, 'lead_form' => true ) );
		dbgr_at_register_core_routes();
		assert_true( dbgr_test_hook_count( 'doughboss_catering_enquiry_created' ) >= 1, 'lead hook registered' );
		assert_same( 0, dbgr_test_hook_count( 'doughboss_order_created' ), 'no order hook' );
		assert_same( 0, dbgr_test_hook_count( 'wp_enqueue_scripts' ), 'no script' );
		dbgr_at_visitor( 1, 1, dbgr_at_campaign() );
		dbgr_at_enquire( array( 'dbgr_company' => 'Acme Pty Ltd', 'dbgr_segment' => 'corporate' ) );
		$lead = dbgr_at_row( 'doughboss_growth_lead_meta', 'enquiry_id', 101 );
		assert_same( 'Acme Pty Ltd', $lead['company_name'], 'company recorded' );
		assert_same( '{}', $lead['attribution_json'], 'no attribution read while the attribution flag is off' );
		assert_same( array(), dbgr_at_rows( 'doughboss_growth_attribution' ), 'no attribution row' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Schema                                                                                                      */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'schema: two tables with the frozen names and the unique keys, installable by dbDelta, listed in the uninstall plan',
	function () {
		DoughBoss_Growth::load_module( 'attribution' );
		$schemas = DoughBoss_Growth_Attribution::schema();
		assert_count( 2, $schemas, 'two CREATE TABLE statements' );
		assert_same( array( 'wp_doughboss_growth_attribution', 'wp_doughboss_growth_lead_meta' ), DoughBoss_Growth_Activator::expected_tables( $schemas ), 'table names' );
		assert_contains( 'UNIQUE KEY subject (subject_type,subject_id)', $schemas[0], 'unique (subject_type, subject_id)' );
		assert_contains( 'UNIQUE KEY enquiry (enquiry_id)', $schemas[1], 'unique enquiry_id' );
		assert_contains( 'lead_score int(11) DEFAULT NULL', $schemas[1], 'lead_score is NULL by default' );
		assert_contains( 'PRIMARY KEY  (id)', $schemas[0], 'dbDelta two-space PRIMARY KEY' );
		assert_true( in_array( 'doughboss_growth_attribution', DoughBoss_Growth_Activator::TABLE_SUFFIXES, true ) && in_array( 'doughboss_growth_lead_meta', DoughBoss_Growth_Activator::TABLE_SUFFIXES, true ), 'both names are in the uninstall plan' );
		assert_true( in_array( 'wp_doughboss_growth_attribution', DoughBoss_Growth_Activator::expected_tables(), true ), 'the activator collects the module schema while its flag is off' );
		$GLOBALS['wpdb']->use_sqlite();
		foreach ( $schemas as $sql ) {
			$GLOBALS['wpdb']->create_table_from_mysql( $sql );
		}
		assert_same( array(), dbgr_at_rows( 'doughboss_growth_attribution' ), 'attribution table usable' );
		assert_same( array(), dbgr_at_rows( 'doughboss_growth_lead_meta' ), 'lead table usable' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Consent on the server                                                                                        */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'consent: closed while the banner is off, whatever the cookies say',
	function () {
		dbgr_at_boot( array( 'consent_banner' => false ) );
		dbgr_at_visitor( 1, 1, dbgr_at_campaign() );
		$snapshot = DoughBoss_Growth_Attribution::consent_snapshot();
		assert_same( false, $snapshot['measurement'], 'no measurement' );
		assert_same( false, $snapshot['advertising'], 'no advertising' );
		assert_same( array(), DoughBoss_Growth_Attribution::current_attribution(), 'no attribution without the banner' );
	}
);

db_test(
	'consent: no cookie, deny default: nothing; measurement only: no click id; advertising only: only the click id; both: all',
	function () {
		dbgr_at_boot();
		$attr = dbgr_at_campaign();
		dbgr_at_visitor( null, null, $attr );
		assert_same( array(), DoughBoss_Growth_Attribution::current_attribution(), 'before a choice (deny default)' );
		dbgr_at_visitor( 0, 0, $attr );
		assert_same( array(), DoughBoss_Growth_Attribution::current_attribution(), 'rejected' );
		dbgr_at_visitor( 1, 0, $attr );
		$measure = DoughBoss_Growth_Attribution::current_attribution();
		assert_true( isset( $measure['utmSource'], $measure['landingPath'], $measure['referrerHost'], $measure['firstSeenAt'] ), 'measurement fields kept' );
		assert_false( isset( $measure['gclid'] ), 'no click id without advertising consent' );
		dbgr_at_visitor( 0, 1, $attr );
		assert_same( array( 'gclid' => 'x' ), DoughBoss_Growth_Attribution::current_attribution(), 'advertising only keeps only the click id' );
		dbgr_at_visitor( 1, 1, $attr );
		assert_same( $attr, DoughBoss_Growth_Attribution::current_attribution(), 'both consents keep everything' );
	}
);

db_test(
	'consent: notice-and-opt-out before a choice grants measurement only, and a stored choice still wins',
	function () {
		dbgr_at_boot( array(), array( 'consent_default' => 'opt_out' ) );
		dbgr_at_visitor( null, null, dbgr_at_campaign() );
		$kept = DoughBoss_Growth_Attribution::current_attribution();
		assert_true( isset( $kept['utmSource'] ) && ! isset( $kept['gclid'] ), 'opt-out: measurement yes, advertising no' );
		dbgr_at_visitor( 0, 0, dbgr_at_campaign() );
		assert_same( array(), DoughBoss_Growth_Attribution::current_attribution(), 'a rejection wins over the default' );
	}
);

db_test(
	'consent: a signed-in manager (staff keying an order or enquiry) is never treated as a visitor, so their own cookies are not attributed',
	function () {
		dbgr_at_boot();
		dbgr_at_register_core_routes();
		dbgr_at_visitor( 1, 1, dbgr_at_campaign() );
		dbgr_test_login( array( 'manage_doughboss' ) );
		$snapshot = DoughBoss_Growth_Attribution::consent_snapshot();
		assert_same( false, $snapshot['measurement'] || $snapshot['advertising'], 'closed for a manager' );
		assert_same( array(), DoughBoss_Growth_Attribution::current_attribution(), 'no attribution for a manager' );
		dbgr_at_pay();
		dbgr_at_enquire();
		DoughBoss_Order::$rows[ 90 ] = (object) array( 'payment_intent_id' => '' );
		do_action( 'doughboss_order_created', 90, array() );
		assert_same( array(), dbgr_at_rows( 'doughboss_growth_attribution' ), 'no attribution row of any kind for staff-made payments, enquiries or orders' );
		assert_same( '{}', dbgr_at_rows( 'doughboss_growth_lead_meta' )[0]['attribution_json'], 'the lead record holds no source data' );
		dbgr_test_logout();
		assert_same( dbgr_at_campaign(), DoughBoss_Growth_Attribution::current_attribution(), 'a visitor (not signed in) is attributed as normal' );
		dbgr_test_login( array( 'read' ) );
		assert_same( dbgr_at_campaign(), DoughBoss_Growth_Attribution::current_attribution(), 'a signed-in customer without manager rights is attributed as normal' );
	}
);

db_test(
	'consent: a consent cookie from an older wording version is not a choice',
	function () {
		dbgr_at_boot();
		$_COOKIE['dbgr_consent'] = wp_slash( wp_json_encode( array( 'v' => '0', 'm' => 1, 'a' => 1, 'ts' => DBGR_TEST_EPOCH ) ) );
		$_COOKIE['dbgr_attr']    = wp_slash( dbgr_at_cookie( dbgr_at_campaign() ) );
		assert_same( array(), DoughBoss_Growth_Attribution::current_attribution(), 'old wording: still denied' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Reading the cookie                                                                                           */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'cookie: malformed, oversized and hostile values give an empty record and no warning',
	function () {
		dbgr_at_boot();
		foreach ( array( 'not json', '{"utmSource":', '[1,2,3]', '"a string"', '5', 'null', '', str_repeat( 'a', 1501 ), '{"utmSource":"' . str_repeat( 'a', 1500 ) . '"}' ) as $raw ) {
			dbgr_at_visitor( 1, 1, $raw );
			assert_same( array(), DoughBoss_Growth_Attribution::current_attribution(), 'malformed cookie ' . substr( $raw, 0, 20 ) );
		}
		foreach ( array( array( 'a' ), 5, null ) as $not_a_string ) {
			assert_same( array(), DoughBoss_Growth_Attribution::parse_cookie( $not_a_string ), 'not a string' );
		}
	}
);

db_test(
	'cookie: only known, valid fields survive; extra keys, control characters and a query-string path are dropped',
	function () {
		dbgr_at_boot();
		dbgr_at_visitor(
			1,
			1,
			array(
				'utmSource'   => 'ok',
				'utmMedium'   => "bad\x00",
				'landingPath' => '/x?y=1',
				'gclid'       => 'g',
				'ip'          => '203.0.113.9',
				'email'       => 'a@b.co',
			)
		);
		assert_same( array( 'utmSource' => 'ok', 'gclid' => 'g' ), DoughBoss_Growth_Attribution::current_attribution(), 'cleaned' );
	}
);

db_test(
	'cookie: a value with quotes and backslashes survives the WordPress slashing of $_COOKIE',
	function () {
		dbgr_at_boot();
		dbgr_at_visitor( 1, 0, dbgr_at_cookie( array( 'utmSource' => 'qu"ote', 'landingPath' => '/a' ) ) );
		assert_same( array( 'utmSource' => 'qu"ote', 'landingPath' => '/a' ), DoughBoss_Growth_Attribution::current_attribution(), 'unslashed' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Subjects                                                                                                     */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'subjects: write and read back; the first write wins; one row per subject',
	function () {
		dbgr_at_boot();
		$consent = array( 'measurement' => true, 'advertising' => true, 'chosen' => true, 'version' => '1' );
		assert_true( DoughBoss_Growth_Attribution::write_subject( 'payment_ref', 'sq_pay_9', dbgr_at_campaign(), $consent ), 'written' );
		assert_true( DoughBoss_Growth_Attribution::write_subject( 'payment_ref', 'sq_pay_9', array( 'utmSource' => 'second' ), $consent ), 'a second write is accepted but changes nothing' );
		assert_count( 1, dbgr_at_rows( 'doughboss_growth_attribution' ), 'one row' );
		$read = DoughBoss_Growth_Attribution::for_subject( 'payment_ref', 'sq_pay_9' );
		assert_same( dbgr_at_campaign(), $read['attribution'], 'first write kept' );
		assert_same( array( 'measurement' => true, 'advertising' => true, 'chosen' => true, 'version' => '1' ), $read['consent'], 'consent snapshot' );
		assert_same( '2026-10-02 00:00:00', $read['captured_at'], 'captured at the (test) clock, UTC' );
		assert_same( null, DoughBoss_Growth_Attribution::for_subject( 'payment_ref', 'nope' ), 'unknown subject is null' );
	}
);

db_test(
	'subjects: invalid types and ids are refused and nothing is written',
	function () {
		dbgr_at_boot();
		$consent = array( 'measurement' => true, 'advertising' => true, 'chosen' => true, 'version' => '1' );
		foreach ( array( array( 'bogus', '1' ), array( 'order', 'abc' ), array( 'order', '0' ), array( 'order', -5 ), array( 'order', '01' ), array( 'enquiry', '1; DROP TABLE x' ), array( 'payment_ref', '' ), array( 'payment_ref', 'has space' ), array( 'payment_ref', str_repeat( 'a', 101 ) ), array( 'payment_ref', array( 'a' ) ), array( 'waitlist', 1.5 ) ) as $pair ) {
			assert_false( DoughBoss_Growth_Attribution::write_subject( $pair[0], $pair[1], array( 'utmSource' => 'a' ), $consent ), 'refused: ' . $pair[0] . ' / ' . wp_json_encode( $pair[1] ) );
			assert_same( null, DoughBoss_Growth_Attribution::for_subject( $pair[0], $pair[1] ), 'no read for an invalid subject' );
		}
		assert_same( array(), dbgr_at_rows( 'doughboss_growth_attribution' ), 'nothing written' );
		assert_true( DoughBoss_Growth_Attribution::write_subject( 'waitlist', 42, array( 'utmSource' => 'a' ), $consent ), 'an int id is fine' );
		assert_true( DoughBoss_Growth_Attribution::write_subject( 'payment_ref', str_repeat( 'a', 100 ), array( 'utmSource' => 'a' ), $consent ), '100 characters is the limit' );
	}
);

db_test(
	'subjects: what is written is re-sanitised and cut to the consent given (a caller cannot smuggle a click id)',
	function () {
		dbgr_at_boot();
		$measure_only = array( 'measurement' => true, 'advertising' => false, 'chosen' => true, 'version' => '1' );
		DoughBoss_Growth_Attribution::write_subject( 'order', 7, array_merge( dbgr_at_campaign(), array( 'email' => 'a@b.co', 'utmTerm' => "bad\x00" ) ), $measure_only );
		$read = DoughBoss_Growth_Attribution::for_subject( 'order', 7 );
		assert_false( isset( $read['attribution']['gclid'] ), 'click id removed without advertising consent' );
		assert_false( isset( $read['attribution']['email'] ), 'unknown key removed' );
		assert_false( isset( $read['attribution']['utmTerm'] ), 'control character value removed' );
		assert_same( 'test', $read['attribution']['utmSource'], 'measurement field kept' );
	}
);

db_test(
	'subjects: a dirty stored row is cleaned on read (storage is not trusted)',
	function () {
		dbgr_at_boot();
		$GLOBALS['wpdb']->sqlite_raw( "INSERT INTO wp_doughboss_growth_attribution (subject_type, subject_id, attribution_json, consent_json, captured_at) VALUES ('order', '5', '{\"utmSource\":\"ok\",\"gclid\":\"a\\u0000b\",\"x\":\"y\"}', '{\"measurement\":\"yes\",\"advertising\":1}', '2026-10-02 00:00:00')" );
		$read = DoughBoss_Growth_Attribution::for_subject( 'order', 5 );
		assert_same( array( 'utmSource' => 'ok' ), $read['attribution'], 'cleaned' );
		assert_same( false, $read['consent']['measurement'], 'a non-boolean consent value is false' );
		assert_same( false, $read['consent']['advertising'], 'only a strict true counts' );
		$GLOBALS['wpdb']->sqlite_raw( "INSERT INTO wp_doughboss_growth_attribution (subject_type, subject_id, attribution_json, consent_json, captured_at) VALUES ('order', '6', 'not json', 'also not', '2026-10-02 00:00:00')" );
		$bad = DoughBoss_Growth_Attribution::for_subject( 'order', 6 );
		assert_same( array(), $bad['attribution'], 'garbage JSON reads as empty' );
		assert_same( false, $bad['consent']['measurement'], 'garbage consent reads as closed' );
	}
);

db_test(
	'subjects: a storage error is reported as false and never throws',
	function () {
		dbgr_at_boot();
		$GLOBALS['wpdb']->fail_all( true );
		$consent = array( 'measurement' => true, 'advertising' => false, 'chosen' => true, 'version' => '1' );
		assert_false( DoughBoss_Growth_Attribution::write_subject( 'order', 1, array( 'utmSource' => 'a' ), $consent ), 'write fails closed' );
		assert_same( false, DoughBoss_Growth_Attribution::for_subject( 'order', 1 ), 'a read error is false (not null, which means "no row"), so no caller can mistake a storage fault for "no consent"' );
	}
);

db_test(
	'record_current: stores the consented cookie for a subject and writes nothing without consent',
	function () {
		dbgr_at_boot();
		dbgr_at_visitor( 0, 0, dbgr_at_campaign() );
		assert_false( DoughBoss_Growth_Attribution::record_current( 'waitlist', 9 ), 'no consent, no row' );
		assert_same( array(), dbgr_at_rows( 'doughboss_growth_attribution' ), 'nothing stored' );
		dbgr_at_visitor( 1, 0, dbgr_at_campaign() );
		assert_true( DoughBoss_Growth_Attribution::record_current( 'waitlist', 9 ), 'consent given' );
		$read = DoughBoss_Growth_Attribution::for_subject( 'waitlist', 9 );
		assert_same( 'test', $read['attribution']['utmSource'], 'stored' );
		assert_false( isset( $read['attribution']['gclid'] ), 'no click id without advertising consent' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Catering enquiries                                                                                           */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'enquiry: consent given writes exactly one lead_meta row and one enquiry attribution row, with the source data',
	function () {
		dbgr_at_boot();
		dbgr_at_register_core_routes();
		dbgr_at_visitor( 1, 1, dbgr_at_campaign() );
		$response = dbgr_at_enquire();
		assert_same( 200, $response->get_status(), 'core answered normally' );
		assert_same( array( 'enquiry_number' => 'E101' ), $response->get_data(), 'the response is core\'s, untouched' );
		$leads = dbgr_at_rows( 'doughboss_growth_lead_meta' );
		assert_count( 1, $leads, 'one lead row' );
		assert_same( '101', (string) $leads[0]['enquiry_id'], 'keyed by the enquiry id' );
		$stored = json_decode( $leads[0]['attribution_json'], true );
		assert_same( 'test', $stored['utmSource'], 'utm_source stored' );
		assert_same( 'x', $stored['gclid'], 'click id stored (advertising consented)' );
		assert_same( 0, (int) $leads[0]['consent_marketing'], 'no marketing consent without the lead form' );
		assert_same( null, $leads[0]['lead_score'], 'lead score is NULL' );
		$row = DoughBoss_Growth_Attribution::for_subject( 'enquiry', 101 );
		assert_same( dbgr_at_campaign(), $row['attribution'], 'enquiry attribution row' );
		assert_same( true, $row['consent']['measurement'] && $row['consent']['advertising'], 'consent snapshot kept for the conversions module' );
		assert_not_contains( 'person@example.com', wp_json_encode( dbgr_at_rows( 'doughboss_growth_lead_meta' ) ) . wp_json_encode( dbgr_at_rows( 'doughboss_growth_attribution' ) ), 'no email in the side records' );
	}
);

db_test(
	'enquiry: consent rejected keeps the lead row but holds no source data and no attribution row',
	function () {
		dbgr_at_boot();
		dbgr_at_register_core_routes();
		dbgr_at_visitor( 0, 0, dbgr_at_campaign() );
		dbgr_at_enquire();
		$leads = dbgr_at_rows( 'doughboss_growth_lead_meta' );
		assert_count( 1, $leads, 'one lead row' );
		assert_same( '{}', $leads[0]['attribution_json'], 'no source data' );
		assert_same( array(), dbgr_at_rows( 'doughboss_growth_attribution' ), 'no attribution row' );
	}
);

db_test(
	'enquiry: measurement only stores the UTM fields and leaves the click id out',
	function () {
		dbgr_at_boot();
		dbgr_at_register_core_routes();
		dbgr_at_visitor( 1, 0, dbgr_at_campaign() );
		dbgr_at_enquire();
		$stored = json_decode( dbgr_at_rows( 'doughboss_growth_lead_meta' )[0]['attribution_json'], true );
		assert_same( 'test', $stored['utmSource'], 'utm kept' );
		assert_false( isset( $stored['gclid'] ), 'click id not kept' );
	}
);

db_test(
	'enquiry: no cookie at all still writes one empty lead row (the enquiry is never blocked)',
	function () {
		dbgr_at_boot();
		dbgr_at_register_core_routes();
		dbgr_at_visitor( null, null, null );
		$response = dbgr_at_enquire();
		assert_same( 200, $response->get_status(), 'enquiry fine' );
		assert_count( 1, dbgr_at_rows( 'doughboss_growth_lead_meta' ), 'one row' );
	}
);

db_test(
	'enquiry: when core rejects the enquiry (honeypot, limit, bad nonce) nothing is stored',
	function () {
		dbgr_at_boot();
		dbgr_at_register_core_routes();
		dbgr_at_visitor( 1, 1, dbgr_at_campaign() );
		$honeypot = dbgr_at_enquire( array( 'hp' => 'i-am-a-bot' ) );
		assert_same( 400, $honeypot->get_status(), 'honeypot rejected by core' );
		$limited = dbgr_at_enquire( array( 'scenario' => 'limit' ) );
		assert_same( 429, $limited->get_status(), 'limit hit' );
		$nonce = dbgr_at_enquire( array( 'nonce' => 'bad' ) );
		assert_same( 401, $nonce->get_status(), 'permission callback refused' );
		assert_same( array(), dbgr_at_rows( 'doughboss_growth_lead_meta' ), 'no lead row' );
		assert_same( array(), dbgr_at_rows( 'doughboss_growth_attribution' ), 'no attribution row' );
		// And the very next real enquiry is not contaminated by the rejected request's fields.
		dbgr_at_enquire( array( 'dbgr_company' => 'Good Co' ) );
		assert_same( 'Good Co', dbgr_at_rows( 'doughboss_growth_lead_meta' )[0]['company_name'], 'only the accepted enquiry\'s fields' );
	}
);

db_test(
	'enquiry: the hook is idempotent (exactly one row per enquiry id) and the recorded action fires once',
	function () {
		dbgr_at_boot();
		DoughBoss_Growth_Attribution::init();
		add_action(
			'doughboss_growth_lead_recorded',
			function ( $id, $meta ) {
				$GLOBALS['dbgr_at_hook_calls'][] = array( $id, $meta );
			},
			10,
			2
		);
		dbgr_at_visitor( 1, 1, dbgr_at_campaign() );
		do_action( 'doughboss_catering_enquiry_created', 55, array( 'customer_email' => 'a@example.com' ) );
		do_action( 'doughboss_catering_enquiry_created', 55, array( 'customer_email' => 'a@example.com' ) );
		assert_count( 1, dbgr_at_rows( 'doughboss_growth_lead_meta' ), 'one lead row' );
		assert_count( 1, dbgr_at_rows( 'doughboss_growth_attribution' ), 'one attribution row' );
		assert_count( 1, $GLOBALS['dbgr_at_hook_calls'], 'doughboss_growth_lead_recorded fired once' );
		assert_same( 55, $GLOBALS['dbgr_at_hook_calls'][0][0], 'with the enquiry id' );
		assert_not_contains( 'a@example.com', wp_json_encode( $GLOBALS['dbgr_at_hook_calls'] ), 'the action carries no email' );
	}
);

db_test(
	'enquiry: invalid enquiry ids write nothing',
	function () {
		dbgr_at_boot();
		DoughBoss_Growth_Attribution::init();
		dbgr_at_visitor( 1, 1, dbgr_at_campaign() );
		foreach ( array( 0, -3, 'abc', null, array( 1 ), '' ) as $bad ) {
			do_action( 'doughboss_catering_enquiry_created', $bad, array() );
		}
		assert_same( array(), dbgr_at_rows( 'doughboss_growth_lead_meta' ), 'nothing written' );
	}
);

db_test(
	'enquiry: lead-form fields are recorded, sanitised and capped; marketing consent needs the box AND the wording version',
	function () {
		dbgr_at_boot( array( 'lead_form' => true ) );
		dbgr_at_register_core_routes();
		dbgr_at_visitor( 1, 1, dbgr_at_campaign() );
		dbgr_at_enquire(
			array(
				'dbgr_company'              => '  <b>Acme</b> ' . str_repeat( 'x', 200 ),
				'dbgr_segment'              => 'Office_Breakfast!!',
				'dbgr_landing_key'          => 'catering-office-breakfast',
				'dbgr_consent_marketing'    => '1',
				'dbgr_consent_text_version' => 'v2026-10',
			)
		);
		$lead = dbgr_at_rows( 'doughboss_growth_lead_meta' )[0];
		assert_same( 120, strlen( $lead['company_name'] ), 'company capped at 120' );
		assert_not_contains( '<b>', $lead['company_name'], 'tags stripped' );
		assert_same( 'office_breakfast', $lead['segment'], 'segment as a key' );
		assert_same( 'catering-office-breakfast', $lead['landing_key'], 'landing key' );
		assert_same( 1, (int) $lead['consent_marketing'], 'marketing consent recorded' );
		assert_same( 'v2026-10', $lead['consent_text_version'], 'with its wording version' );
		assert_same( '2026-10-02 00:00:00', $lead['consent_at_utc'], 'and its UTC time' );
	}
);

db_test(
	'enquiry: marketing consent without a wording version, unticked, or not exactly "1" is stored as NOT given (negative controls)',
	function () {
		dbgr_at_boot();
		dbgr_at_register_core_routes();
		dbgr_at_visitor( 1, 1, dbgr_at_campaign() );
		$cases = array(
			array( 'dbgr_consent_marketing' => '1' ),
			array( 'dbgr_consent_marketing' => '1', 'dbgr_consent_text_version' => 'has spaces' ),
			array( 'dbgr_consent_marketing' => '1', 'dbgr_consent_text_version' => str_repeat( 'v', 21 ) ),
			array( 'dbgr_consent_marketing' => '0', 'dbgr_consent_text_version' => 'v1' ),
			array( 'dbgr_consent_marketing' => '', 'dbgr_consent_text_version' => 'v1' ),
			array( 'dbgr_consent_marketing' => 'true', 'dbgr_consent_text_version' => 'v1' ),
			array( 'dbgr_consent_marketing' => 'on', 'dbgr_consent_text_version' => 'v1' ),
			array( 'dbgr_consent_text_version' => 'v1' ),
		);
		foreach ( $cases as $index => $params ) {
			dbgr_at_enquire( array_merge( array( 'customer_email' => 'p' . $index . '@example.com' ), $params ) );
		}
		foreach ( dbgr_at_rows( 'doughboss_growth_lead_meta' ) as $index => $lead ) {
			assert_same( 0, (int) $lead['consent_marketing'], 'case ' . $index . ' stored as not given' );
			assert_same( null, $lead['consent_at_utc'], 'case ' . $index . ' has no consent time' );
			assert_same( '', $lead['consent_text_version'], 'case ' . $index . ' stores no wording version' );
		}
		assert_count( count( $cases ), dbgr_at_rows( 'doughboss_growth_lead_meta' ), 'every enquiry still has its row' );
	}
);

db_test(
	'enquiry: dbgr_* fields are only taken from the catering enquiry route, and only for the matching email',
	function () {
		dbgr_at_boot();
		DoughBoss_Growth_Attribution::init();
		$request = new WP_REST_Request( 'POST', '/doughboss/v1/order' );
		$request->set_param( 'customer_email', 'a@example.com' );
		$request->set_param( 'dbgr_company', 'Elsewhere Co' );
		$passed = DoughBoss_Growth_Attribution::stash_enquiry_params( null, array(), $request );
		assert_same( null, $passed, 'the response is returned untouched' );
		do_action( 'doughboss_catering_enquiry_created', 61, array( 'customer_email' => 'a@example.com' ) );
		assert_same( '', dbgr_at_row( 'doughboss_growth_lead_meta', 'enquiry_id', 61 )['company_name'], 'another route\'s field is ignored' );

		$request = new WP_REST_Request( 'POST', '/doughboss/v1/catering/enquiry' );
		$request->set_param( 'customer_email', 'a@example.com' );
		$request->set_param( 'dbgr_company', 'Alice Co' );
		DoughBoss_Growth_Attribution::stash_enquiry_params( null, array(), $request );
		do_action( 'doughboss_catering_enquiry_created', 62, array( 'customer_email' => 'someone.else@example.com' ) );
		assert_same( '', dbgr_at_row( 'doughboss_growth_lead_meta', 'enquiry_id', 62 )['company_name'], 'another enquirer\'s email: the stash is not applied' );

		$get = new WP_REST_Request( 'GET', '/doughboss/v1/catering/enquiry' );
		$get->set_param( 'customer_email', 'a@example.com' );
		$get->set_param( 'dbgr_company', 'Get Co' );
		DoughBoss_Growth_Attribution::stash_enquiry_params( null, array(), $get );
		do_action( 'doughboss_catering_enquiry_created', 63, array( 'customer_email' => 'a@example.com' ) );
		assert_same( '', dbgr_at_row( 'doughboss_growth_lead_meta', 'enquiry_id', 63 )['company_name'], 'a GET is ignored' );

		$errored = new WP_Error( 'rest_invalid_param', 'bad', array( 'status' => 400 ) );
		$request = new WP_REST_Request( 'POST', '/doughboss/v1/catering/enquiry' );
		$request->set_param( 'customer_email', 'a@example.com' );
		$request->set_param( 'dbgr_company', 'Invalid Co' );
		$same = DoughBoss_Growth_Attribution::stash_enquiry_params( $errored, array(), $request );
		assert_true( $same === $errored, 'an argument error is returned untouched' );
		do_action( 'doughboss_catering_enquiry_created', 64, array( 'customer_email' => 'a@example.com' ) );
		assert_same( '', dbgr_at_row( 'doughboss_growth_lead_meta', 'enquiry_id', 64 )['company_name'], 'a request with argument errors stashes nothing' );
	}
);

db_test(
	'enquiry: array or object values for dbgr_* fields are ignored, not coerced',
	function () {
		dbgr_at_boot();
		dbgr_at_register_core_routes();
		dbgr_at_visitor( 1, 1, null );
		dbgr_at_enquire( array( 'dbgr_company' => array( 'a' ), 'dbgr_segment' => array( 'b' ), 'dbgr_consent_marketing' => array( '1' ) ) );
		$lead = dbgr_at_rows( 'doughboss_growth_lead_meta' )[0];
		assert_same( '', $lead['company_name'], 'no company' );
		assert_same( '', $lead['segment'], 'no segment' );
		assert_same( 0, (int) $lead['consent_marketing'], 'no consent' );
	}
);

db_test(
	'enquiry: a malformed attribution cookie never breaks the enquiry',
	function () {
		dbgr_at_boot();
		dbgr_at_register_core_routes();
		foreach ( array( 'not json', '{"utmSource":', str_repeat( '{', 5000 ), "\xFF\xFE" ) as $index => $junk ) {
			dbgr_at_visitor( 1, 1, $junk );
			$response = dbgr_at_enquire( array( 'customer_email' => 'm' . $index . '@example.com' ) );
			assert_same( 200, $response->get_status(), 'enquiry succeeds with a malformed cookie' );
		}
		assert_count( 4, dbgr_at_rows( 'doughboss_growth_lead_meta' ), 'a row for each' );
		foreach ( dbgr_at_rows( 'doughboss_growth_lead_meta' ) as $lead ) {
			assert_same( '{}', $lead['attribution_json'], 'empty record' );
		}
	}
);

db_test(
	'enquiry: a storage failure, a throwing listener and a throwing consent read never escape into core',
	function () {
		dbgr_at_boot();
		DoughBoss_Growth_Attribution::init();
		$logged = array();
		add_action(
			'doughboss_growth_log',
			function ( $line ) use ( &$logged ) {
				$logged[] = $line;
			}
		);
		dbgr_at_visitor( 1, 1, dbgr_at_campaign() );

		$GLOBALS['wpdb']->fail_all( true );
		do_action( 'doughboss_catering_enquiry_created', 71, array( 'customer_email' => 'secret.person@example.com' ) );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( array(), dbgr_at_rows( 'doughboss_growth_lead_meta' ), 'nothing stored while the database fails' );
		assert_contains( 'attribution_write_failed', implode( ' ', $logged ), 'a storage failure is logged (event and stage only)' );
		$logged = array();

		add_action(
			'doughboss_growth_lead_recorded',
			function () {
				throw new RuntimeException( 'listener exploded for secret.person@example.com' );
			}
		);
		do_action( 'doughboss_catering_enquiry_created', 72, array( 'customer_email' => 'secret.person@example.com' ) );
		assert_count( 1, dbgr_at_rows( 'doughboss_growth_lead_meta' ), 'the row is kept even though a listener threw' );
		assert_true( count( $logged ) >= 1, 'the failure was logged' );
		foreach ( $logged as $line ) {
			assert_not_contains( 'secret.person', $line, 'no email in the log' );
			assert_not_contains( 'test', str_replace( 'attribution_failed', '', $line ), 'no cookie content in the log' );
		}
		assert_contains( 'RuntimeException', implode( ' ', $logged ), 'only the exception class is logged' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Payment references and orders                                                                                */
/* ---------------------------------------------------------------------------------------------------------- */

/** POST /payment-intent with an optional scenario. */
function dbgr_at_pay( $scenario = '', $method = 'POST' ) {
	return dbgr_test_rest_dispatch( $method, '/doughboss/v1/payment-intent', ( '' === $scenario ) ? array() : array( 'scenario' => $scenario ) );
}

db_test(
	'payment: a successful payment-intent stores the cookie\'s attribution under the returned reference; the response is untouched',
	function () {
		dbgr_at_boot();
		dbgr_at_register_core_routes();
		dbgr_at_visitor( 1, 1, dbgr_at_campaign() );
		$response = dbgr_at_pay();
		assert_same( 200, $response->get_status(), 'core status' );
		assert_same( array( 'payment_intent' => 'sq_pay_123', 'gateway' => 'square' ), $response->get_data(), 'core data untouched' );
		$row = DoughBoss_Growth_Attribution::for_subject( 'payment_ref', 'sq_pay_123' );
		assert_same( dbgr_at_campaign(), $row['attribution'], 'stored under the payment reference' );
	}
);

db_test(
	'payment: no row for a declined payment, a Stripe redirect (no payment_intent), a GET, an unusable reference or no consent',
	function () {
		dbgr_at_boot();
		dbgr_at_register_core_routes();
		dbgr_at_visitor( 1, 1, dbgr_at_campaign() );
		assert_same( 402, dbgr_at_pay( 'declined' )->get_status(), 'declined' );
		assert_same( 200, dbgr_at_pay( 'stripe' )->get_status(), 'stripe redirect answers 200 without payment_intent' );
		assert_same( 200, dbgr_at_pay( 'weird' )->get_status(), 'weird reference answers 200' );
		assert_same( 404, dbgr_at_pay( '', 'GET' )->get_status(), 'a GET has no route' );
		assert_same( array(), dbgr_at_rows( 'doughboss_growth_attribution' ), 'nothing stored for any of those' );
		dbgr_at_visitor( 0, 0, dbgr_at_campaign() );
		dbgr_at_pay();
		assert_same( array(), dbgr_at_rows( 'doughboss_growth_attribution' ), 'nothing stored without consent' );
		dbgr_at_visitor( 1, 1, null );
		dbgr_at_pay();
		assert_same( array(), dbgr_at_rows( 'doughboss_growth_attribution' ), 'nothing stored when there is no attribution cookie' );
	}
);

db_test(
	'payment: other routes are never touched (the filter only reacts to POST /doughboss/v1/payment-intent)',
	function () {
		dbgr_at_boot();
		DoughBoss_Growth_Attribution::init();
		dbgr_at_visitor( 1, 1, dbgr_at_campaign() );
		$response = new WP_REST_Response( array( 'payment_intent' => 'sq_pay_other' ), 200 );
		foreach ( array( '/doughboss/v1/catering/payment-intent', '/doughboss-growth/v1/payment-intent', '/doughboss/v1/payment-intent/extra', '/wp/v2/posts' ) as $route ) {
			$out = DoughBoss_Growth_Attribution::on_rest_post_dispatch( $response, null, new WP_REST_Request( 'POST', $route ) );
			assert_true( $out === $response, 'response returned as is for ' . $route );
		}
		assert_same( array(), dbgr_at_rows( 'doughboss_growth_attribution' ), 'nothing stored' );
		assert_true( $response === DoughBoss_Growth_Attribution::on_rest_post_dispatch( $response, null, null ), 'no request object is not an error' );
		assert_true( 'x' === DoughBoss_Growth_Attribution::on_rest_post_dispatch( 'x', null, new WP_REST_Request( 'POST', '/doughboss/v1/payment-intent' ) ), 'a non-response is returned as is' );
	}
);

db_test(
	'order: an order whose payment_intent_id matches a stored payment_ref inherits it, including the consent snapshot of that moment',
	function () {
		dbgr_at_boot();
		dbgr_at_register_core_routes();
		dbgr_at_visitor( 1, 1, dbgr_at_campaign() );
		dbgr_at_pay();
		// Later, in the /checkout request the visitor's cookies differ (a different landing, advertising withdrawn).
		dbgr_at_visitor( 1, 0, array( 'utmSource' => 'direct-later', 'landingPath' => '/menu/' ) );
		DoughBoss_Order::$rows[ 33 ] = (object) array( 'payment_intent_id' => 'sq_pay_123' );
		do_action( 'doughboss_order_created', 33, array( 'payment_intent_id' => 'ignored-because-the-order-row-wins' ) );
		$order = DoughBoss_Growth_Attribution::for_subject( 'order', 33 );
		assert_same( dbgr_at_campaign(), $order['attribution'], 'inherited from the payment reference, not from the later cookie' );
		assert_same( true, $order['consent']['advertising'], 'with the consent snapshot of the payment' );
	}
);

db_test(
	'order: webhook-recovered order (no cookie at all) still inherits through the payment reference',
	function () {
		dbgr_at_boot();
		DoughBoss_Growth_Attribution::init();
		DoughBoss_Growth_Attribution::write_subject( 'payment_ref', 'sq_pay_777', dbgr_at_campaign(), array( 'measurement' => true, 'advertising' => true, 'chosen' => true, 'version' => '1' ) );
		dbgr_at_visitor( null, null, null );
		DoughBoss_Order::$rows[ 34 ] = (object) array( 'payment_intent_id' => 'sq_pay_777' );
		do_action( 'doughboss_order_created', 34, array() );
		assert_same( dbgr_at_campaign(), DoughBoss_Growth_Attribution::for_subject( 'order', 34 )['attribution'], 'inherited' );
	}
);

db_test(
	'order: no stored payment reference falls back to the cookie; no consent and no cookie write nothing',
	function () {
		dbgr_at_boot();
		DoughBoss_Growth_Attribution::init();
		dbgr_at_visitor( 1, 0, dbgr_at_campaign() );
		DoughBoss_Order::$rows[ 35 ] = (object) array( 'payment_intent_id' => 'sq_pay_unknown' );
		do_action( 'doughboss_order_created', 35, array() );
		$fallback = DoughBoss_Growth_Attribution::for_subject( 'order', 35 );
		assert_same( 'test', $fallback['attribution']['utmSource'], 'cookie fallback' );
		assert_false( isset( $fallback['attribution']['gclid'] ), 'consent respected in the fallback' );
		dbgr_at_visitor( 0, 0, dbgr_at_campaign() );
		do_action( 'doughboss_order_created', 36, array() );
		dbgr_at_visitor( null, null, null );
		do_action( 'doughboss_order_created', 37, array() );
		assert_same( null, DoughBoss_Growth_Attribution::for_subject( 'order', 36 ), 'rejected: no row' );
		assert_same( null, DoughBoss_Growth_Attribution::for_subject( 'order', 37 ), 'no cookie: no row' );
	}
);

db_test(
	'order: the payment reference can come from the action data when core\'s order lookup fails; invalid ids and a failing lookup never throw',
	function () {
		dbgr_at_boot();
		DoughBoss_Growth_Attribution::init();
		DoughBoss_Growth_Attribution::write_subject( 'payment_ref', 'sq_pay_data', dbgr_at_campaign(), array( 'measurement' => true, 'advertising' => true, 'chosen' => true, 'version' => '1' ) );
		dbgr_at_visitor( null, null, null );
		DoughBoss_Order::$throw = true;
		do_action( 'doughboss_order_created', 38, array( 'payment_intent_id' => 'sq_pay_data' ) );
		assert_same( null, DoughBoss_Growth_Attribution::for_subject( 'order', 38 ), 'a throwing lookup is caught; nothing is written' );
		DoughBoss_Order::$throw = false;
		do_action( 'doughboss_order_created', 38, array( 'payment_intent_id' => 'sq_pay_data' ) );
		assert_same( dbgr_at_campaign(), DoughBoss_Growth_Attribution::for_subject( 'order', 38 )['attribution'], 'from the action data' );
		foreach ( array( 0, -1, 'x', null ) as $bad ) {
			do_action( 'doughboss_order_created', $bad, array() );
		}
		assert_count( 2, dbgr_at_rows( 'doughboss_growth_attribution' ), 'only the two valid subjects exist' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Script and admin                                                                                             */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'script: enqueued after the consent script, in the footer, only with attribution AND the consent banner on',
	function () {
		dbgr_at_boot();
		DoughBoss_Growth_Consent::init();
		DoughBoss_Growth_Attribution::init();
		do_action( 'wp_enqueue_scripts' );
		$script = $GLOBALS['dbgr_assets']['scripts']['dbgr-attribution'];
		assert_true( $script['enqueued'], 'enqueued' );
		assert_same( array( 'dbgr-consent' ), $script['deps'], 'depends on the consent script' );
		assert_true( $script['footer'], 'in the footer' );
		assert_matches( '#/public/js/dbgr-attribution\.js$#', $script['src'], 'script file' );
		assert_same( DOUGHBOSS_GROWTH_VERSION, $script['ver'], 'versioned' );
	}
);

db_test(
	'script: not enqueued while the consent banner is off, or while the attribution flag is off',
	function () {
		dbgr_at_boot( array( 'consent_banner' => false ) );
		DoughBoss_Growth_Attribution::init();
		do_action( 'wp_enqueue_scripts' );
		assert_false( isset( $GLOBALS['dbgr_assets']['scripts']['dbgr-attribution'] ), 'no consent banner, no script' );
		dbgr_test_reset();
		dbgr_at_boot( array( 'attribution' => false ) );
		DoughBoss_Growth_Attribution::enqueue();
		assert_false( isset( $GLOBALS['dbgr_assets']['scripts']['dbgr-attribution'] ), 'attribution off, no script' );
	}
);

db_test(
	'admin tab: counts and the latest lead records; values are escaped; no email or click id is printed; owner gaps listed',
	function () {
		dbgr_at_boot();
		dbgr_at_register_core_routes();
		dbgr_at_visitor( 1, 1, array_merge( dbgr_at_campaign(), array( 'utmCampaign' => '<script>alert(1)</script>' ) ) );
		dbgr_at_enquire( array( 'dbgr_segment' => 'corporate' ) );
		do_action( 'doughboss_growth_admin_tabs' );
		ob_start();
		DoughBoss_Growth_Attribution::render_tab();
		$html = (string) ob_get_clean();
		assert_contains( '&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'campaign value escaped' );
		assert_not_contains( '<script>', $html, 'no raw script' );
		assert_contains( 'corporate', $html, 'segment shown' );
		assert_contains( 'Latest lead records', $html, 'recent leads heading' );
		assert_not_contains( 'person@example.com', $html, 'no email' );
		assert_not_contains( '>x<', $html, 'the click id itself is not printed' );
		assert_contains( '[CONFIRM: how long attribution and lead records are kept', $html, 'retention gap' );
		assert_contains( 'Attribution rows: enquiry', $html, 'counts per subject type' );
	}
);

db_test(
	'admin tab (silence rule): a count or list that could not be read says so; it is never printed as 0 or "None yet."',
	function () {
		dbgr_at_boot();
		dbgr_at_register_core_routes();
		dbgr_at_visitor( 1, 1, dbgr_at_campaign() );
		dbgr_at_enquire( array( 'dbgr_segment' => 'corporate' ) );
		$render = function () {
			ob_start();
			DoughBoss_Growth_Attribution::render_tab();
			return (string) ob_get_clean();
		};
		$healthy = $render();
		assert_contains( 'Attribution rows: enquiry</th><td>1</td>', $healthy, 'control: the enquiry row is counted' );
		assert_contains( 'Lead records</th><td>1</td>', $healthy, 'control: the lead record is counted' );
		assert_not_contains( 'could not be read', strtolower( $healthy ), 'control: nothing is reported unread' );

		$GLOBALS['wpdb']->fail_on( '/SELECT COUNT\(\*\) FROM wp_doughboss_growth_attribution/' );
		$html = $render();
		$GLOBALS['wpdb']->clear_failures();
		foreach ( DoughBoss_Growth_Attribution::SUBJECT_TYPES as $type ) {
			assert_contains( 'Attribution rows: ' . $type . '</th><td>Could not be read</td>', $html, 'a failed count for ' . $type . ' says it could not be read' );
			assert_not_contains( 'Attribution rows: ' . $type . '</th><td>0</td>', $html, 'and is not printed as 0 (' . $type . ')' );
		}
		assert_contains( 'Lead records</th><td>1</td>', $html, 'a count that worked is still shown' );

		$GLOBALS['wpdb']->fail_on( '/SELECT COUNT\(\*\) FROM wp_doughboss_growth_lead_meta/' );
		$html = $render();
		$GLOBALS['wpdb']->clear_failures();
		assert_contains( 'Lead records</th><td>Could not be read</td>', $html, 'a failed lead count says it could not be read' );
		assert_not_contains( 'Lead records</th><td>0</td>', $html, 'and is not printed as 0' );

		$GLOBALS['wpdb']->fail_on( '/SELECT enquiry_id, segment, attribution_json, created_at FROM wp_doughboss_growth_lead_meta/' );
		$html = $render();
		$GLOBALS['wpdb']->clear_failures();
		assert_contains( 'The latest lead records could not be read.', $html, 'a failed list says it could not be read' );
		assert_not_contains( 'None yet.', $html, 'and is not "None yet."' );
		assert_not_contains( '<table class="widefat striped" style="max-width:900px">', $html, 'no half-read table' );

		// Control: a genuinely empty store still says so.
		$GLOBALS['wpdb']->sqlite_raw( 'DELETE FROM wp_doughboss_growth_lead_meta' );
		$GLOBALS['wpdb']->sqlite_raw( 'DELETE FROM wp_doughboss_growth_attribution' );
		$html = $render();
		assert_contains( 'None yet.', $html, 'control: an empty list is "None yet."' );
		assert_contains( 'Lead records</th><td>0</td>', $html, 'control: an empty count is 0' );
	}
);

db_test(
	'order (silence rule): a failed payment-reference read is listed, and the order still falls back to the visitor\'s own cookie',
	function () {
		dbgr_at_boot();
		DoughBoss_Growth_Attribution::init();
		DoughBoss_Growth_Attribution::write_subject( 'payment_ref', 'sq_pay_data', dbgr_at_campaign(), array( 'measurement' => true, 'advertising' => true, 'chosen' => true, 'version' => '1' ) );
		dbgr_at_visitor( 1, 0, array( 'utmSource' => 'fromcookie' ) );
		DoughBoss_Order::$rows[ 41 ] = (object) array( 'payment_intent_id' => 'sq_pay_data' );
		$GLOBALS['wpdb']->fail_on( '/FROM wp_doughboss_growth_attribution WHERE subject_type = \'payment_ref\'/' );
		do_action( 'doughboss_order_created', 41, array() );
		$GLOBALS['wpdb']->clear_failures();
		$codes = array_column( DoughBoss_Growth_Failures::all(), 'code' );
		assert_true( in_array( 'attribution_read_failed', $codes, true ), 'the failed read is on the owner-visible failure list' );
		$order = DoughBoss_Growth_Attribution::for_subject( 'order', 41 );
		assert_same( 'fromcookie', $order['attribution']['utmSource'], 'the order still takes the visitor\'s own cookie, as when no reference is stored' );
		assert_same( false, $order['consent']['advertising'], 'with the consent they hold now' );
		DoughBoss_Growth_Failures::clear();

		// Control: a reference that is simply not stored is not a failure.
		DoughBoss_Order::$rows[ 42 ] = (object) array( 'payment_intent_id' => 'sq_pay_unknown' );
		do_action( 'doughboss_order_created', 42, array() );
		assert_same( array(), array_column( DoughBoss_Growth_Failures::all(), 'code' ), 'control: no stored reference is not a failure' );
	}
);

db_test(
	'admin tab: registers through the shared tab hook, and reports the consent-banner dependency while the banner is off',
	function () {
		dbgr_at_boot( array( 'consent_banner' => false ) );
		DoughBoss_Growth_Attribution::init();
		do_action( 'doughboss_growth_admin_tabs' );
		assert_true( dbgr_test_hook_count( 'doughboss_growth_admin_tabs' ) >= 1, 'the tab hook is registered' );
		$gaps = DoughBoss_Growth_Attribution::confirm_gaps();
		assert_true( isset( $gaps['consent_banner'] ), 'the missing banner is a visible gap' );
		assert_contains( '[CONFIRM:', $gaps['consent_banner'], 'in the house gap format' );
		foreach ( $gaps as $text ) {
			assert_contains( '[CONFIRM:', $text, 'every gap is marked' );
		}
	}
);

db_test(
	'scope: the module writes only doughboss_growth_ tables, and the file has an ABSPATH guard and no network call',
	function () {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/attribution/class-doughboss-growth-attribution.php' );
		assert_matches( "/if \( ! defined\( 'ABSPATH' \) \) \{\s+exit;/", $source, 'ABSPATH guard' );
		assert_not_contains( 'wp_remote_', $source, 'no outbound request' );
		assert_not_contains( 'update_option', $source, 'no option writes' );
		assert_not_contains( 'add_role', $source, 'no roles' );
		assert_not_contains( 'register_post_type', $source, 'no post types' );
		assert_not_contains( 'setcookie', $source, 'the server never sets the cookie (the browser script does)' );
		assert_not_contains( 'REMOTE_ADDR', $source, 'no IP address' );
		assert_not_contains( 'HTTP_USER_AGENT', $source, 'no user agent' );
		assert_false( 1 === preg_match( '/minis/i', $source . (string) file_get_contents( dirname( __DIR__ ) . '/public/js/dbgr-attribution.js' ) ), 'teaser word absent' );
	}
);
