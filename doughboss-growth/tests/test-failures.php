<?php
/**
 * WP-16 observability tests: failures must be visible to the owner and success messages must tell the truth.
 *
 *  1. The failure list (DoughBoss_Growth_Failures) and its feed from DoughBoss_Growth_Http::log(), the Status row, the
 *     admin notice and the Clear action (finding 3).
 *  2. Schema confirmation: a missing column is not "installed", a failed repair is throttled and visible (finding 14).
 *  3. The settings save notice and the "(Currently active.)" label (finding 8).
 *  4. The landing "create pages" and coming-soon "save" notices (finding 13).
 *
 * Failed writes cannot be simulated inside the fake WordPress (its option functions always succeed), so those cases run
 * in a sub-process whose prelude defines failing option functions. They are skipped, visibly, where a sub-process
 * cannot be started.
 *
 * @package DoughBoss_Growth
 */

/* ---------------------------------------------------------------------------------------------------------- */
/* Helpers                                                                                                     */
/* ---------------------------------------------------------------------------------------------------------- */

/**
 * The stored failure list as the option holds it (false when the option does not exist).
 *
 * @return mixed
 */
function dbgr_fail_stored() {
	return get_option( DoughBoss_Growth_Failures::OPTION, false );
}

/**
 * Codes currently held, newest first.
 *
 * @return array
 */
function dbgr_fail_codes() {
	return array_column( DoughBoss_Growth_Failures::all(), 'code' );
}

/**
 * Render the Growth settings page as a manager and return the HTML.
 *
 * @param array $get Query string of the request.
 * @return string
 */
function dbgr_fail_render( array $get = array() ) {
	$_GET = $get;
	dbgr_test_login( array( 'manage_doughboss' ) );
	ob_start();
	DoughBoss_Growth_Admin::render_page();
	return ob_get_clean();
}

/**
 * The sentence printed after a feature's description on the settings page ("(Currently active.)" and friends).
 *
 * @param string $html  Page HTML.
 * @param string $label Feature label as printed.
 * @return string Empty when the feature row is not found.
 */
function dbgr_fail_feature_text( $html, $label ) {
	if ( 1 !== preg_match( '#<strong>' . preg_quote( $label, '#' ) . '</strong></label><br /><span class="description">([^<]*)</span>#', $html, $m ) ) {
		return '';
	}
	return html_entity_decode( $m[1], ENT_QUOTES );
}

/**
 * Save the settings through the real admin handler and return the redirect query as the next request would see it.
 *
 * @param array $dbgr Posted settings (the dbgr[...] array).
 * @return array Query string values.
 */
function dbgr_fail_save( array $dbgr ) {
	dbgr_test_login( array( 'manage_doughboss' ) );
	$_POST                = array( 'dbgr' => $dbgr );
	$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_save_settings' );
	$redirect             = assert_throws( 'DBGR_Test_Redirect', array( 'DoughBoss_Growth_Admin', 'handle_save' ), 'the save redirects' );
	parse_str( (string) parse_url( $redirect->url, PHP_URL_QUERY ), $query );
	return $query;
}

/**
 * Prelude for a sub-process in which the named options cannot be written or deleted (update_option() and
 * delete_option() return false and change nothing), the way a full disk or a locked table behaves.
 *
 * @param array  $names Option names that cannot be written.
 * @param string $extra More prelude code.
 * @return string
 */
function dbgr_fail_prelude( array $names, $extra = '' ) {
	$code = <<<'PRE'
define( 'DBGR_FAIL_WRITES', __NAMES__ );
function update_option( $name, $value, $autoload = null ) {
	if ( in_array( $name, DBGR_FAIL_WRITES, true ) ) {
		return false;
	}
	dbgr_option_guard( $name, 'update' );
	if ( array_key_exists( $name, $GLOBALS['dbgr_options'] ) && $GLOBALS['dbgr_options'][ $name ] === $value ) {
		return false;
	}
	$GLOBALS['dbgr_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	if ( in_array( $name, DBGR_FAIL_WRITES, true ) ) {
		return false;
	}
	dbgr_option_guard( $name, 'delete' );
	if ( ! array_key_exists( $name, $GLOBALS['dbgr_options'] ) ) {
		return false;
	}
	unset( $GLOBALS['dbgr_options'][ $name ] );
	return true;
}
PRE;
	return str_replace( '__NAMES__', var_export( $names, true ), $code ) . "\n" . $extra;
}

/**
 * Run code in a sub-process and decode the JSON it prints. Null (and a visible skip) where no sub-process can run.
 *
 * @param string $code    Code to run; it must echo one JSON document.
 * @param string $prelude Code to run before the harness loads.
 * @return array|null
 */
function dbgr_fail_run( $code, $prelude = '' ) {
	if ( ! dbgr_test_can_subprocess() ) {
		dbgr_test_skip( 'sub-process unavailable: the failed-write case is not exercised' );
		return null;
	}
	$result = dbgr_test_subprocess( $code, $prelude );
	$data   = json_decode( $result['out'], true );
	if ( ! is_array( $data ) ) {
		db_fail( 'sub-process printed no JSON: ' . $result['out'] . ' / ' . $result['err'] );
		return null;
	}
	return $data;
}

/**
 * Write a throw-away module directory and return it (with a trailing slash).
 *
 * @param array $files Relative path => PHP source.
 * @return string
 */
function dbgr_fail_module_dir( array $files ) {
	$dir = sys_get_temp_dir() . '/dbgr-fail-' . bin2hex( random_bytes( 6 ) ) . '/';
	foreach ( $files as $path => $source ) {
		if ( ! is_dir( dirname( $dir . $path ) ) ) {
			mkdir( dirname( $dir . $path ), 0777, true );
		}
		file_put_contents( $dir . $path, $source );
	}
	return $dir;
}

/* ---------------------------------------------------------------------------------------------------------- */
/* 1. The failure list                                                                                         */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'failures API: record() keeps a redacted record, all() is newest first, count() counts distinct codes, clear() forgets one or all',
	function () {
		assert_same( array(), DoughBoss_Growth_Failures::all(), 'nothing recorded on a fresh site' );
		assert_same( 0, DoughBoss_Growth_Failures::count(), 'count is zero' );
		assert_same( false, dbgr_fail_stored(), 'a fresh site has no failure option at all' );
		assert_same( 'doughboss_growth_failures', DoughBoss_Growth_Failures::OPTION, 'the option name is frozen' );

		assert_true( DoughBoss_Growth_Failures::record( 'module_failed', array( 'module' => 'waitlist', 'error' => 'TypeError' ) ), 'recorded' );
		$all = DoughBoss_Growth_Failures::all();
		assert_same(
			array(
				array(
					'code'       => 'module_failed',
					'count'      => 1,
					'first_seen' => DBGR_TEST_EPOCH,
					'last_seen'  => DBGR_TEST_EPOCH,
					'context'    => array( 'module' => 'waitlist', 'error' => 'TypeError' ),
				),
			),
			$all,
			'the record shape is {code, count, first_seen, last_seen, context}'
		);

		dbgr_test_advance( 120 );
		DoughBoss_Growth_Failures::record( 'schema_failed', array( 'module' => 'leads' ) );
		assert_same( array( 'schema_failed', 'module_failed' ), dbgr_fail_codes(), 'newest first' );
		assert_same( 2, DoughBoss_Growth_Failures::count(), 'two distinct codes' );

		dbgr_test_advance( 120 );
		DoughBoss_Growth_Failures::record( 'module_failed', array( 'module' => 'leads', 'error' => 'Error' ) );
		$all = DoughBoss_Growth_Failures::all();
		assert_same( 'module_failed', $all[0]['code'], 'a repeat moves the code to the front' );
		assert_same( 2, $all[0]['count'], 'and counts it' );
		assert_same( DBGR_TEST_EPOCH, $all[0]['first_seen'], 'first_seen stays' );
		assert_same( DBGR_TEST_EPOCH + 240, $all[0]['last_seen'], 'last_seen moves' );
		assert_same( array( 'module' => 'leads', 'error' => 'Error' ), $all[0]['context'], 'the context is the latest occurrence' );
		assert_same( 2, DoughBoss_Growth_Failures::count(), 'still two distinct codes' );

		assert_true( DoughBoss_Growth_Failures::clear( 'schema_failed' ), 'clearing one code' );
		assert_same( array( 'module_failed' ), dbgr_fail_codes(), 'only that code went' );
		$before = dbgr_fail_stored();
		assert_true( DoughBoss_Growth_Failures::clear( 'never_recorded' ), 'clearing a code that is not held is fine' );
		assert_same( $before, dbgr_fail_stored(), 'and changes nothing' );
		assert_false( DoughBoss_Growth_Failures::clear( '!!!' ), 'a code with no usable characters cannot be cleared' );
		assert_true( DoughBoss_Growth_Failures::clear(), 'clearing everything' );
		assert_same( false, dbgr_fail_stored(), 'the option is gone, not left empty' );
		assert_same( 0, DoughBoss_Growth_Failures::count(), 'count is zero again' );
		assert_true( DoughBoss_Growth_Failures::clear(), 'clearing an empty list is fine' );
		assert_same( false, dbgr_fail_stored(), 'and writes nothing' );
	}
);

db_test(
	'failures API: the context is redacted again on the way in (personal keys dropped, text redacted, URL reduced to its host, 5 short keys)',
	function () {
		DoughBoss_Growth_Failures::record(
			'conversion_enqueue_failed',
			array(
				'email'      => 'jane@example.com',
				'user_agent' => 'Mozilla/5.0',
				'api_key'    => 'abcdef',
				'stage'      => 'order for jane@example.com on 0412 345 678 from 203.0.113.9',
				'url'        => 'https://hooks.example-receiver.com.au/services/T000/B000/secret-path?token=zzz',
				'channel'    => 'ga4',
				'long'       => str_repeat( 'x', 300 ),
				'nested'     => array( 'a' => 1 ),
				'status'     => 503,
				'one'        => 'extra key one',
				'two'        => 'extra key two',
			)
		);
		$context = DoughBoss_Growth_Failures::all()[0]['context'];
		assert_true( count( $context ) <= DoughBoss_Growth_Failures::MAX_CONTEXT_KEYS, 'at most five keys are kept' );
		foreach ( array( 'email', 'user_agent', 'api_key', 'nested' ) as $dropped ) {
			assert_false( array_key_exists( $dropped, $context ), 'the key ' . $dropped . ' is dropped' );
		}
		assert_same( 'hooks.example-receiver.com.au', $context['host'], 'a URL is reduced to its host (a path or query can carry a token)' );
		assert_false( array_key_exists( 'url', $context ), 'and the key says so' );
		assert_same( 'order for [email] on [number] from [ip]', $context['stage'], 'free text is redacted' );
		assert_same( 'ga4', $context['channel'], 'a plain fact is kept' );
		foreach ( $context as $value ) {
			assert_true( strlen( $value ) <= DoughBoss_Growth_Failures::MAX_VALUE_LENGTH, 'a value is at most 80 characters' );
		}
		$stored = serialize( dbgr_fail_stored() );
		foreach ( array( 'jane@example.com', '0412 345 678', '203.0.113.9', 'secret-path', 'token=zzz', 'Mozilla', 'abcdef' ) as $needle ) {
			assert_not_contains( $needle, $stored, 'the stored option never holds ' . $needle );
		}
	}
);

db_test(
	'failures API: a code is normalised, and an unusable one is still recorded (a failure is never dropped for its name)',
	function () {
		DoughBoss_Growth_Failures::record( 'Module Failed!' );
		DoughBoss_Growth_Failures::record( null );
		DoughBoss_Growth_Failures::record( array() );
		DoughBoss_Growth_Failures::record( '###' );
		DoughBoss_Growth_Failures::record( str_repeat( 'a', 200 ) );
		$codes = dbgr_fail_codes();
		assert_true( in_array( 'modulefailed', $codes, true ), 'letters and digits only, lower case' );
		assert_true( in_array( 'unknown_failure', $codes, true ), 'an unusable code becomes unknown_failure' );
		assert_same( 3, count( $codes ), 'three distinct codes (all the unusable ones share one)' );
		assert_true( in_array( str_repeat( 'a', DoughBoss_Growth_Failures::MAX_CODE_LENGTH ), $codes, true ), 'a long code is cut to 64 characters' );
	}
);

db_test(
	'failures API: at most 20 distinct codes; a new code displaces the one seen longest ago and never the newest',
	function () {
		for ( $i = 1; $i <= 25; $i++ ) {
			DoughBoss_Growth_Failures::record( sprintf( 'code_%02d', $i ) );
			dbgr_test_advance( 5 );
		}
		assert_same( 20, DoughBoss_Growth_Failures::count(), 'capped at twenty' );
		assert_same( 20, count( dbgr_fail_stored() ), 'and the stored option itself never holds more than twenty (storage is bounded, not just the reading of it)' );
		$codes = dbgr_fail_codes();
		assert_same( 'code_25', $codes[0], 'the newest is kept and first' );
		assert_false( in_array( 'code_01', $codes, true ), 'the oldest was displaced' );
		assert_false( in_array( 'code_05', $codes, true ), 'so were the next four' );
		assert_true( in_array( 'code_06', $codes, true ), 'the sixth, now the oldest held, survived' );
		// Recording a code that is already held never displaces another.
		dbgr_test_advance( 100 );
		DoughBoss_Growth_Failures::record( 'code_06' );
		assert_same( 20, DoughBoss_Growth_Failures::count(), 'still twenty' );
		assert_true( in_array( 'code_07', dbgr_fail_codes(), true ), 'a repeat of a held code displaced nothing' );
	}
);

db_test(
	'failures API: the same failure repeating inside 60 seconds is folded in without a write (a failure on every page view costs one write a minute); a different context is a new occurrence',
	function () {
		DoughBoss_Growth_Failures::record( 'module_failed', array( 'module' => 'a' ) );
		$first = dbgr_fail_stored();
		for ( $i = 0; $i < 50; $i++ ) {
			dbgr_test_advance( 1 );
			assert_true( DoughBoss_Growth_Failures::record( 'module_failed', array( 'module' => 'a' ) ), 'a repeat is reported as held' );
		}
		assert_same( $first, dbgr_fail_stored(), 'fifty identical repeats inside the window left the stored option byte for byte as it was (no write)' );
		assert_same( 1, DoughBoss_Growth_Failures::all()[0]['count'], 'one count' );

		// The same code with another fact (another stage or module) is not the same failure: it is kept at once, so a
		// run that fails in three steps within one second loses none of them.
		DoughBoss_Growth_Failures::record( 'module_failed', array( 'module' => 'b' ) );
		$record = DoughBoss_Growth_Failures::all()[0];
		assert_same( 2, $record['count'], 'a different context counts at once' );
		assert_same( array( 'module' => 'b' ), $record['context'], 'and is the context shown' );

		// After the window an identical repeat counts again.
		dbgr_test_advance( 70 );
		DoughBoss_Growth_Failures::record( 'module_failed', array( 'module' => 'b' ) );
		assert_same( 3, DoughBoss_Growth_Failures::all()[0]['count'], 'after the window the next one counts' );

		// Exactly on the boundary counts (the window is "less than 60 seconds").
		dbgr_test_advance( DoughBoss_Growth_Failures::REPEAT_WINDOW );
		DoughBoss_Growth_Failures::record( 'module_failed', array( 'module' => 'b' ) );
		assert_same( 4, DoughBoss_Growth_Failures::all()[0]['count'], 'sixty seconds on the dot counts' );

		// A last_seen in the future (a clock that went backwards, or a corrupt value) must not silence the code for ever.
		update_option(
			DoughBoss_Growth_Failures::OPTION,
			array( array( 'code' => 'module_failed', 'count' => 1, 'first_seen' => 1, 'last_seen' => DoughBoss_Growth::now() + 99999, 'context' => array() ) ),
			'no'
		);
		DoughBoss_Growth_Failures::record( 'module_failed' );
		assert_same( 2, DoughBoss_Growth_Failures::all()[0]['count'], 'a future last_seen is not folded for ever' );
	}
);

db_test(
	'failures API: a corrupt option is survived: never throws, reads as empty or repaired, and the next record() or clear() recovers it',
	function () {
		$good = array( 'code' => 'module_failed', 'count' => 3, 'first_seen' => 100, 'last_seen' => 200, 'context' => array( 'module' => 'x' ) );
		$bad  = array(
			'a string'            => 'not an array',
			'an int'              => 5,
			'a bool'              => true,
			'junk items'          => array( 'x', 5, null, array(), array( 'code' => '' ), array( 'code' => array( 'nested' ) ) ),
			'unserialisable-ish'  => array( 'a' => array( 'b' => array( 'c' => 'd' ) ) ),
		);
		foreach ( $bad as $label => $value ) {
			update_option( DoughBoss_Growth_Failures::OPTION, $value, 'no' );
			assert_same( array(), DoughBoss_Growth_Failures::all(), $label . ': reads as empty' );
			assert_same( 0, DoughBoss_Growth_Failures::count(), $label . ': count is zero' );
			assert_true( DoughBoss_Growth_Failures::record( 'schema_failed' ), $label . ': record() recovers' );
			assert_same( array( 'schema_failed' ), dbgr_fail_codes(), $label . ': the list is clean again' );
			assert_true( DoughBoss_Growth_Failures::clear(), $label . ': clear() works' );
		}

		// Half-good lists are repaired item by item.
		update_option(
			DoughBoss_Growth_Failures::OPTION,
			array(
				$good,
				array( 'code' => 'Bad Count!', 'count' => -4, 'first_seen' => 'x', 'last_seen' => 'y', 'context' => 'a string' ),
				array( 'code' => 'huge_count', 'count' => PHP_INT_MAX, 'first_seen' => 1, 'last_seen' => 2, 'context' => array( 'email' => 'jane@example.com' ) ),
				'garbage',
			),
			'no'
		);
		$all   = DoughBoss_Growth_Failures::all();
		$byKey = array_column( $all, null, 'code' );
		assert_same( 3, count( $all ), 'three usable items survive' );
		assert_same( 3, $byKey['module_failed']['count'], 'a good item is kept as it is' );
		assert_same( 1, $byKey['badcount']['count'], 'a negative count is repaired to 1' );
		assert_same( 0, $byKey['badcount']['last_seen'], 'a non-integer time is repaired to 0' );
		assert_same( array(), $byKey['badcount']['context'], 'a non-array context is emptied' );
		assert_same( DoughBoss_Growth_Failures::MAX_COUNT, $byKey['huge_count']['count'], 'an absurd count is capped' );
		assert_same( array(), $byKey['huge_count']['context'], 'a stored personal key is dropped on read' );

		// More than twenty stored items: only the newest twenty are read.
		$many = array();
		for ( $i = 1; $i <= 30; $i++ ) {
			$many[] = array( 'code' => 'c' . $i, 'count' => 1, 'first_seen' => $i, 'last_seen' => $i, 'context' => array() );
		}
		update_option( DoughBoss_Growth_Failures::OPTION, $many, 'no' );
		assert_same( 20, DoughBoss_Growth_Failures::count(), 'a tampered list longer than twenty is cut' );
		assert_same( 'c30', dbgr_fail_codes()[0], 'keeping the newest' );
	}
);

db_test(
	'failures API: the option is written with autoload "no" everywhere (a request with no failure pays nothing for it)',
	function () {
		$code = (string) file_get_contents( DOUGHBOSS_GROWTH_DIR . 'includes/class-doughboss-growth-failures.php' );
		$code = preg_replace( '#/\*.*?\*/#s', '', $code );
		assert_same( 1, preg_match_all( '/update_option\(\s*self::OPTION\s*,/', $code ), 'one place writes the list' );
		assert_same( 1, preg_match( "/update_option\(\s*self::OPTION\s*,\s*\\\$list\s*,\s*'no'\s*\)/", $code ), 'and it passes autoload "no"' );
		assert_same( 0, preg_match( '/add_option\(/', $code ), 'there is no second write path' );
	}
);

db_test(
	'failures API: record() never throws, even when the storage layer throws or refuses the write (sub-process)',
	function () {
		$prelude = dbgr_fail_prelude(
			array( 'doughboss_growth_failures' ),
			"function get_option( \$name, \$default = false ) { if ( 'doughboss_growth_failures' === \$name && ! empty( \$GLOBALS['dbgr_fail_throw'] ) ) { throw new RuntimeException( 'storage down' ); } return array_key_exists( \$name, \$GLOBALS['dbgr_options'] ) ? \$GLOBALS['dbgr_options'][ \$name ] : \$default; }"
		);
		$code = <<<'CODE'
$out = array();
$out['refused_write'] = DoughBoss_Growth_Failures::record( 'module_failed', array( 'module' => 'a' ) );
$out['count_after']   = DoughBoss_Growth_Failures::count();
$GLOBALS['dbgr_fail_throw'] = true;
try {
	$out['throwing_record'] = DoughBoss_Growth_Failures::record( 'module_failed' );
	$out['throwing_all']    = DoughBoss_Growth_Failures::all();
	$out['throwing_count']  = DoughBoss_Growth_Failures::count();
	$out['throwing_clear']  = DoughBoss_Growth_Failures::clear();
	$out['throwing_log']    = strlen( DoughBoss_Growth_Http::log( 'module_failed', array( 'module' => 'a' ) ) ) > 0;
	$out['threw']           = false;
} catch ( Throwable $e ) {
	$out['threw'] = get_class( $e );
}
echo json_encode( $out );
CODE;
		$data = dbgr_fail_run( $code, $prelude );
		if ( null === $data ) {
			return;
		}
		assert_same( false, $data['refused_write'], 'a refused write is reported as false, not as success' );
		assert_same( 0, $data['count_after'], 'and nothing is held' );
		assert_same( false, $data['threw'], 'nothing escaped when the storage layer threw' );
		assert_same( false, $data['throwing_record'], 'record() reports false' );
		assert_same( array(), $data['throwing_all'], 'all() reads as empty' );
		assert_same( 0, $data['throwing_count'], 'count() reads as zero' );
		assert_same( false, $data['throwing_clear'], 'clear() reports false' );
		assert_true( $data['throwing_log'], 'and Http::log() still returns its line' );
	}
);

db_test(
	'failures: which Http::log() events are failures (a table of every existing event, success lines included)',
	function () {
		$cases = array(
			array( 'module_failed', array( 'module' => 'a', 'error' => 'TypeError' ), 'module_failed' ),
			array( 'module_load_failed', array( 'module' => 'a' ), 'module_load_failed' ),
			array( 'schema_failed', array( 'module' => 'a' ), 'schema_failed' ),
			array( 'schema_install_failed', array( 'problems' => 1 ), 'schema_install_failed' ),
			array( 'landing_compose_failed', array( 'error' => 'Error' ), 'landing_compose_failed' ),
			array( 'landing_create_failed', array( 'error' => 'Error' ), 'landing_create_failed' ),
			array( 'recon_failed', array( 'error' => 'Error' ), 'recon_failed' ),
			array( 'attribution_write_failed', array( 'stage' => 'subject' ), 'attribution_write_failed' ),
			array( 'attribution_failed', array( 'stage' => 'stash', 'error' => 'Error' ), 'attribution_failed' ),
			array( 'conversions_failed', array( 'stage' => 'order_created', 'error' => 'Error' ), 'conversions_failed' ),
			array( 'conversion_payload_refused', array( 'channel' => 'ga4' ), 'conversion_payload_refused' ),
			array( 'conversion_enqueue_failed', array( 'channel' => 'ga4' ), 'conversion_enqueue_failed' ),
			array( 'waitlist_webhook_enqueue', array( 'result' => 'error' ), 'waitlist_webhook_enqueue' ),
			array( 'http_refused', array( 'method' => 'POST', 'error' => 'url_not_allowed' ), 'http_refused' ),
			array( 'http', array( 'method' => 'POST', 'error' => 'transport_error' ), 'http_transport_error' ),
			array( 'http', array( 'method' => 'POST', 'status' => '503' ), 'http_status_error' ),
			array( 'http', array( 'method' => 'POST', 'status' => '404' ), 'http_status_error' ),
			array( 'http', array( 'method' => 'POST', 'status' => '301' ), 'http_status_error' ),
			array( 'recon_run', array( 'status' => 'FAILED', 'reason' => 'x' ), 'recon_run_failed' ),
			array( 'Odd Event_failed', array(), 'oddevent_failed' ),
			// Information, never a failure.
			array( 'http', array( 'method' => 'POST', 'status' => '200' ), '' ),
			array( 'http', array( 'method' => 'GET', 'status' => '204' ), '' ),
			array( 'http', array( 'method' => 'GET' ), '' ),
			array( 'recon_run', array( 'status' => 'COMPLETE' ), '' ),
			array( 'recon_run', array( 'status' => 'NOT_RUN', 'reason' => 'feature_off' ), '' ),
			array( 'something_happened', array(), '' ),
			array( '', array(), '' ),
		);
		foreach ( $cases as $case ) {
			assert_same( $case[2], DoughBoss_Growth_Failures::code_for_event( $case[0], $case[1] ), 'event ' . $case[0] . ' ' . wp_json_encode( $case[1] ) );
		}
	}
);

db_test(
	'Http::log() feeds the failure list for every failure event, keeps its line and its action, and records nothing for information lines',
	function () {
		$lines = array();
		add_action(
			'doughboss_growth_log',
			function ( $line, $event ) use ( &$lines ) {
				$lines[] = $event;
			},
			10,
			2
		);
		// Information lines first: nothing may be written.
		DoughBoss_Growth_Http::log( 'http', array( 'method' => 'POST', 'url' => 'https://api.example-receiver.com.au/x', 'status' => 200 ) );
		DoughBoss_Growth_Http::log( 'recon_run', array( 'run' => 4, 'status' => 'COMPLETE', 'reason' => '' ) );
		assert_same( false, dbgr_fail_stored(), 'success and information lines write nothing' );
		assert_same( array( 'http', 'recon_run' ), $lines, 'but the action still fires for them' );

		$line = DoughBoss_Growth_Http::log( 'module_failed', array( 'module' => 'waitlist', 'error' => 'TypeError', 'note' => array( 'x' ) ) );
		assert_matches( '/^DoughBoss Growth \[module_failed\] \{"module":"waitlist","error":"TypeError"\}$/', $line, 'the existing line format is unchanged' );
		assert_same( array( 'http', 'recon_run', 'module_failed' ), $lines, 'the action fired for the failure too' );
		$record = DoughBoss_Growth_Failures::all()[0];
		assert_same( 'module_failed', $record['code'], 'the failure is in the list' );
		assert_same( array( 'module' => 'waitlist', 'error' => 'TypeError' ), $record['context'], 'with its redacted context' );

		DoughBoss_Growth_Http::log( 'http', array( 'method' => 'POST', 'url' => 'https://api.example-receiver.com.au/x?api_secret=TOPSECRET', 'status' => 503 ) );
		DoughBoss_Growth_Http::log( 'attribution_write_failed', array( 'stage' => 'lead_meta' ) );
		DoughBoss_Growth_Http::log( 'conversion_enqueue_failed', array( 'channel' => 'meta', 'event' => 'purchase' ) );
		DoughBoss_Growth_Http::log( 'waitlist_webhook_enqueue', array( 'result' => 'error' ) );
		DoughBoss_Growth_Http::log( 'recon_run', array( 'run' => 5, 'status' => 'FAILED', 'reason' => 'internal_error' ) );
		$codes = dbgr_fail_codes();
		sort( $codes );
		assert_same(
			array( 'attribution_write_failed', 'conversion_enqueue_failed', 'http_status_error', 'module_failed', 'recon_run_failed', 'waitlist_webhook_enqueue' ),
			$codes,
			'each failure event is held under its code'
		);
		$byCode = array_column( DoughBoss_Growth_Failures::all(), null, 'code' );
		assert_same( 'api.example-receiver.com.au', $byCode['http_status_error']['context']['host'], 'an HTTP failure keeps only the host' );
		assert_same( '503', $byCode['http_status_error']['context']['status'], 'and the status' );
		assert_not_contains( 'TOPSECRET', serialize( dbgr_fail_stored() ), 'a secret in a URL never reaches the list' );
	}
);

db_test(
	'transport failures are visible: a timeout, a refused URL and a bad status each land in the list through the real Http::request()',
	function () {
		dbgr_test_http_expect( 'https://api.example-receiver.com.au/e', new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out with jane@example.com' ) );
		$result = DoughBoss_Growth_Http::request( 'POST', 'https://api.example-receiver.com.au/e', array( 'json' => array( 'a' => 1 ) ) );
		assert_same( 'transport_error', $result['error'], 'the request failed' );
		assert_same( array( 'http_transport_error' ), dbgr_fail_codes(), 'the timeout is held' );

		$result = DoughBoss_Growth_Http::request( 'POST', 'http://api.example-receiver.com.au/e' );
		assert_same( 'url_not_allowed', $result['error'], 'a non-https URL is refused' );
		assert_true( in_array( 'http_refused', dbgr_fail_codes(), true ), 'and the refusal is held' );

		dbgr_test_http_expect( 'https://api.example-receiver.com.au/bad', dbgr_test_http_response( 500, 'oops' ) );
		DoughBoss_Growth_Http::request( 'GET', 'https://api.example-receiver.com.au/bad' );
		assert_true( in_array( 'http_status_error', dbgr_fail_codes(), true ), 'a 500 is held' );

		dbgr_test_http_expect( 'https://api.example-receiver.com.au/good', dbgr_test_http_response( 200, 'ok' ) );
		DoughBoss_Growth_Http::request( 'GET', 'https://api.example-receiver.com.au/good' );
		assert_same( 3, DoughBoss_Growth_Failures::count(), 'a 200 added nothing' );
		assert_not_contains( 'jane@example.com', serialize( dbgr_fail_stored() ), 'no personal data from the transport error text' );
		foreach ( DoughBoss_Growth_Failures::all() as $record ) {
			assert_same( 'api.example-receiver.com.au', $record['context']['host'], $record['code'] . ' keeps only the host' );
		}
	}
);

db_test(
	'a request in which nothing fails writes no failure option: every flag off, the front end, a full public cycle',
	function () {
		update_option( 'doughboss_growth_db_version', DOUGHBOSS_GROWTH_DB_VERSION );
		DoughBoss_Growth::init();
		foreach ( array( 'init', 'wp_loaded', 'template_redirect', 'wp_enqueue_scripts', 'wp_head', 'wp_footer', 'shutdown' ) as $hook ) {
			do_action( $hook );
		}
		assert_same( false, dbgr_fail_stored(), 'no failure, no failure option' );
		assert_same( array(), DoughBoss_Growth::health()['failures'], 'health reports no failures' );
	}
);

db_test(
	'a module that throws (a TypeError from a core update) is held in the list, and Settings no longer calls its feature active',
	function () {
		$dir = dbgr_fail_module_dir(
			array(
				'm/consent.php' => "<?php class DBGR_Fail_Consent { public static function init() { throw new TypeError( 'secret detail jane@example.com' ); } }",
			)
		);
		DoughBoss_Growth::set_registry_override(
			array(
				'consent' => array(
					'file'          => 'm/consent.php',
					'class'         => 'DBGR_Fail_Consent',
					'files'         => array(),
					'features'      => array( 'consent_banner' ),
					'admin_always'  => false,
					'needs_storage' => false,
				),
			),
			$dir
		);
		update_option( 'doughboss_growth_settings', array( 'features' => array( 'consent_banner' => true ) ) );
		DoughBoss_Growth::init();

		assert_same( false, DoughBoss_Growth::module_running( 'consent' ), 'the module is not running' );
		$all = DoughBoss_Growth_Failures::all();
		assert_same( 'module_failed', $all[0]['code'], 'the failure is held (it used to go nowhere)' );
		assert_same( array( 'module' => 'consent', 'error' => 'TypeError' ), $all[0]['context'], 'with the module and the exception class only' );
		assert_not_contains( 'secret detail', serialize( dbgr_fail_stored() ), 'never the exception message' );
		assert_not_contains( 'jane@example.com', serialize( dbgr_fail_stored() ), 'nor personal data' );
		assert_same( array( 'module_failed' => 1 ), DoughBoss_Growth::health()['failures'], 'health lists the code and its count' );

		$html = dbgr_fail_render( array( 'page' => 'doughboss-growth' ) );
		assert_true( DoughBoss_Growth_Settings::enabled( 'consent_banner' ), 'the flag-derived state still says on (this is the old, wrong label)' );
		assert_contains( '(On, but not running: see Recent failures above.)', dbgr_fail_feature_text( $html, 'Consent banner' ), 'the label says on, but not running' );
		assert_not_contains( 'Currently active', dbgr_fail_feature_text( $html, 'Consent banner' ), 'it is not called active' );
		assert_contains( '<code>module_failed</code>', $html, 'the Recent failures row names the code' );
		assert_contains( 'module: consent, error: TypeError', $html, 'and the module and error class' );
		dbgr_test_rmdir( $dir );
	}
);

db_test(
	'a module whose file is missing while its flag is on is a failure too, not a silent no-op',
	function () {
		$dir = dbgr_fail_module_dir( array( 'm/placeholder.txt' => 'x' ) );
		DoughBoss_Growth::set_registry_override(
			array(
				'consent' => array(
					'file'          => 'm/consent.php',
					'class'         => 'DBGR_Fail_Absent',
					'files'         => array(),
					'features'      => array( 'consent_banner' ),
					'admin_always'  => false,
					'needs_storage' => false,
				),
			),
			$dir
		);
		update_option( 'doughboss_growth_settings', array( 'features' => array( 'consent_banner' => true ) ) );
		DoughBoss_Growth::init();
		assert_same( array( 'module_load_failed' ), dbgr_fail_codes(), 'a wanted module that cannot be loaded is held' );
		assert_same( array( 'module' => 'consent' ), DoughBoss_Growth_Failures::all()[0]['context'], 'with the module name' );
		$html = dbgr_fail_render();
		assert_contains( 'On, but not running', dbgr_fail_feature_text( $html, 'Consent banner' ), 'and the feature is not called active' );
		dbgr_test_rmdir( $dir );
	}
);

db_test(
	'a healthy module is reported active (control: the label is not simply always "not running")',
	function () {
		$dir = dbgr_fail_module_dir( array( 'm/consent.php' => '<?php class DBGR_Fail_Healthy { public static function init() {} }' ) );
		DoughBoss_Growth::set_registry_override(
			array(
				'consent' => array(
					'file'          => 'm/consent.php',
					'class'         => 'DBGR_Fail_Healthy',
					'files'         => array(),
					'features'      => array( 'consent_banner' ),
					'admin_always'  => false,
					'needs_storage' => false,
				),
			),
			$dir
		);
		update_option( 'doughboss_growth_settings', array( 'features' => array( 'consent_banner' => true ) ) );
		DoughBoss_Growth::init();
		$html = dbgr_fail_render();
		assert_contains( '(Currently active.)', dbgr_fail_feature_text( $html, 'Consent banner' ), 'running module: active' );
		assert_contains( 'None recorded', $html, 'and the failure row says so' );
		assert_contains( '(Currently inactive.)', dbgr_fail_feature_text( $html, 'Tag Manager' ), 'an unticked feature is inactive' );
		dbgr_test_rmdir( $dir );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 1b. Admin: Status row, notice, Clear                                                                         */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'Status: the Recent failures row says "None recorded" when empty and lists code, count and last seen when not',
	function () {
		DoughBoss_Growth::init();
		$html = dbgr_fail_render();
		assert_contains( 'Recent failures', $html, 'the row exists' );
		assert_contains( 'None recorded', $html, 'empty: a plain statement' );
		assert_not_contains( 'name="action" value="doughboss_growth_clear_failures"', $html, 'and no Clear button with nothing to clear' );

		DoughBoss_Growth_Failures::record( 'schema_failed', array( 'module' => 'leads' ) );
		dbgr_test_advance( 3600 );
		DoughBoss_Growth_Failures::record( 'module_failed', array( 'module' => 'waitlist', 'error' => 'TypeError' ) );
		dbgr_test_advance( 100 );
		DoughBoss_Growth_Failures::record( 'module_failed', array( 'module' => 'waitlist', 'error' => 'TypeError' ) );
		$html = dbgr_fail_render();
		assert_not_contains( 'None recorded', $html, 'not empty any more' );
		assert_contains( '<code>module_failed</code> 2 times, last seen ' . gmdate( 'j M Y, g:i a', DBGR_TEST_EPOCH + 3700 ), $html, 'code, count and last seen' );
		assert_contains( '<code>schema_failed</code> 1 time, last seen ' . gmdate( 'j M Y, g:i a', DBGR_TEST_EPOCH ), $html, 'the older one, singular' );
		assert_true( strpos( $html, 'module_failed</code>' ) < strpos( $html, 'schema_failed</code>' ), 'newest first' );
		assert_contains( 'name="action" value="doughboss_growth_clear_failures"', $html, 'a Clear button' );
		assert_matches( '/doughboss_growth_clear_failures.*name="_wpnonce"|name="_wpnonce".*doughboss_growth_clear_failures/s', $html, 'with a nonce' );
		assert_not_contains( '<script', $html, 'nothing active in the output' );
	}
);

db_test(
	'Status: stored failure text is escaped on the page',
	function () {
		DoughBoss_Growth::init();
		update_option(
			DoughBoss_Growth_Failures::OPTION,
			array( array( 'code' => 'module_failed', 'count' => 1, 'first_seen' => DBGR_TEST_EPOCH, 'last_seen' => DBGR_TEST_EPOCH, 'context' => array( 'module' => '<script>alert(1)</script>' ) ) ),
			'no'
		);
		$html = dbgr_fail_render();
		assert_not_contains( '<script>alert(1)', $html, 'markup never reaches the page' );
	}
);

db_test(
	'notice: shown to a manager on Growth screens while failures are held; never to others, elsewhere, or when there are none',
	function () {
		DoughBoss_Growth::init();
		assert_true( false !== has_action( 'admin_notices', array( 'DoughBoss_Growth_Admin', 'render_failures_notice' ) ), 'the notice is hooked' );
		$notice = function ( array $get, array $caps ) {
			$_GET = $get;
			if ( array() === $caps ) {
				dbgr_test_logout();
			} else {
				dbgr_test_login( $caps );
			}
			ob_start();
			do_action( 'admin_notices' );
			return ob_get_clean();
		};
		assert_not_contains( 'Recent failures', $notice( array( 'page' => 'doughboss-growth' ), array( 'manage_doughboss' ) ), 'nothing recorded: no notice' );

		DoughBoss_Growth_Failures::record( 'module_failed', array( 'module' => 'a' ) );
		DoughBoss_Growth_Failures::record( 'schema_failed', array( 'module' => 'b' ) );
		$html = $notice( array( 'page' => 'doughboss-growth' ), array( 'manage_doughboss' ) );
		assert_contains( 'notice-error', $html, 'a manager on the Growth page sees an error notice' );
		assert_contains( '2 problems are listed under Recent failures', $html, 'with the number of problems' );
		assert_contains( 'value="doughboss_growth_clear_failures"', $html, 'and a Clear button' );
		assert_contains( 'name="_wpnonce"', $html, 'with a nonce' );
		assert_contains( 'Recent failures', $notice( array( 'page' => 'doughboss-growth-recon' ), array( 'manage_options' ) ), 'a module screen of the Growth family shows it too' );
		assert_not_contains( 'Recent failures', $notice( array( 'page' => 'doughboss' ), array( 'manage_doughboss' ) ), 'not on another plugin screen' );
		assert_not_contains( 'Recent failures', $notice( array(), array( 'manage_doughboss' ) ), 'not on the dashboard' );
		assert_not_contains( 'Recent failures', $notice( array( 'page' => 'doughboss-growth' ), array( 'read' ) ), 'not to a user who cannot manage' );
		assert_not_contains( 'Recent failures', $notice( array( 'page' => 'doughboss-growth' ), array() ), 'not to a visitor' );
		DoughBoss_Growth_Failures::record( 'one_more_failed' );
		assert_contains( '3 problems', $notice( array( 'page' => 'doughboss-growth' ), array( 'manage_doughboss' ) ), 'the number follows the list' );
		DoughBoss_Growth_Failures::clear();
		DoughBoss_Growth_Failures::record( 'only_failed' );
		assert_contains( '1 problem is listed', $notice( array( 'page' => 'doughboss-growth' ), array( 'manage_doughboss' ) ), 'singular' );
	}
);

db_test(
	'Clear: capability AND nonce are both required, every refusal leaves the list alone, and the result shown is read back from storage',
	function () {
		DoughBoss_Growth::init();
		DoughBoss_Growth_Failures::record( 'module_failed', array( 'module' => 'a' ) );
		$before = dbgr_fail_stored();
		assert_true( false !== has_action( 'admin_post_doughboss_growth_clear_failures', array( 'DoughBoss_Growth_Admin', 'handle_clear_failures' ) ), 'the admin-post action is registered' );

		assert_throws( 'DBGR_Test_Die', array( 'DoughBoss_Growth_Admin', 'handle_clear_failures' ), 'a visitor is refused' );
		dbgr_test_login( array( 'read' ) );
		$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_clear_failures' );
		$die                  = assert_throws( 'DBGR_Test_Die', array( 'DoughBoss_Growth_Admin', 'handle_clear_failures' ), 'a subscriber with a valid nonce is refused' );
		assert_same( 403, $die->args['response'], 'status 403' );
		dbgr_test_login( array( 'manage_doughboss' ) );
		unset( $_REQUEST['_wpnonce'] );
		assert_throws( 'DBGR_Test_Die', array( 'DoughBoss_Growth_Admin', 'handle_clear_failures' ), 'a manager without a nonce is refused' );
		$_REQUEST['_wpnonce'] = 'deadbeef00';
		assert_throws( 'DBGR_Test_Die', array( 'DoughBoss_Growth_Admin', 'handle_clear_failures' ), 'a forged nonce is refused' );
		$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_save_settings' );
		assert_throws( 'DBGR_Test_Die', array( 'DoughBoss_Growth_Admin', 'handle_clear_failures' ), 'another action\'s nonce is refused' );
		assert_same( $before, dbgr_fail_stored(), 'no refusal cleared anything' );

		$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_clear_failures' );
		$redirect             = assert_throws( 'DBGR_Test_Redirect', array( 'DoughBoss_Growth_Admin', 'handle_clear_failures' ), 'a manager with the nonce clears and is redirected' );
		assert_contains( 'page=doughboss-growth', $redirect->url, 'back to the Growth page' );
		assert_contains( 'dbgr_cleared=1', $redirect->url, 'saying cleared' );
		assert_same( 0, DoughBoss_Growth_Failures::count(), 'and the list really is empty' );

		parse_str( (string) parse_url( $redirect->url, PHP_URL_QUERY ), $query );
		$html = dbgr_fail_render( $query );
		assert_contains( 'Recent failures cleared.', $html, 'the notice says so' );
		$html = dbgr_fail_render( array( 'page' => 'doughboss-growth', 'dbgr_cleared' => '0' ) );
		assert_contains( 'could not be cleared', $html, 'and a "0" says it failed' );
	}
);

db_test(
	'Clear: when the list cannot really be removed the redirect says 0, not "cleared" (sub-process)',
	function () {
		$seed = <<<'CODE'
$GLOBALS['dbgr_options']['doughboss_growth_failures'] = array( array( 'code' => 'module_failed', 'count' => 1, 'first_seen' => 1, 'last_seen' => 1, 'context' => array() ) );
DoughBoss_Growth::init();
dbgr_test_login( array( 'manage_doughboss' ) );
$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_clear_failures' );
$url = '';
try {
	DoughBoss_Growth_Admin::handle_clear_failures();
} catch ( DBGR_Test_Redirect $r ) {
	$url = $r->url;
}
echo json_encode( array( 'url' => $url, 'count' => DoughBoss_Growth_Failures::count() ) );
CODE;
		$data = dbgr_fail_run( $seed, dbgr_fail_prelude( array( 'doughboss_growth_failures' ) ) );
		if ( null === $data ) {
			return;
		}
		assert_same( 1, $data['count'], 'the delete really failed' );
		assert_contains( 'dbgr_cleared=0', $data['url'], 'so the redirect does not claim success' );
	}
);

db_test(
	'health(): lists failure codes and counts only (never context, never a secret)',
	function () {
		DoughBoss_Growth_Failures::record( 'module_failed', array( 'module' => 'a', 'error' => 'TypeError' ) );
		dbgr_test_advance( 100 );
		DoughBoss_Growth_Failures::record( 'module_failed', array( 'module' => 'a', 'error' => 'TypeError' ) );
		DoughBoss_Growth::init();
		$health = DoughBoss_Growth::health();
		assert_same( array( 'module_failed' => 2 ), $health['failures'], 'code => count' );
		assert_not_contains( 'TypeError', wp_json_encode( $health ), 'no context in health' );
	}
);

db_test(
	'uninstall: the failure list is in the plan, passes the prefix rule and is removed by a data-deleting uninstall',
	function () {
		$plan = DoughBoss_Growth_Activator::uninstall_plan( 'wp_' );
		assert_true( in_array( 'doughboss_growth_failures', $plan['options'], true ), 'planned' );
		assert_same( array(), $plan['violations'], 'no violation' );
		DoughBoss_Growth_Failures::record( 'module_failed' );
		assert_true( false !== dbgr_fail_stored(), 'held before uninstall' );
		DoughBoss_Growth_Activator::run_uninstall( $GLOBALS['wpdb'] );
		assert_same( false, dbgr_fail_stored(), 'gone after a data-deleting uninstall' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 2. Schema confirmation (finding 14)                                                                          */
/* ---------------------------------------------------------------------------------------------------------- */

/**
 * Make the fake database report every declared column except the ones named (a failed ALTER on an existing table).
 * The list can be changed during the test through $GLOBALS['dbgr_fail_missing_columns'] to model the repair working.
 *
 * @param array $missing Full table name => list of columns the database lacks.
 * @return void
 */
function dbgr_fail_describe_without( array $missing ) {
	$GLOBALS['dbgr_fail_missing_columns'] = $missing;
	$GLOBALS['wpdb']->respond(
		'/^SHOW COLUMNS FROM `([A-Za-z0-9_]+)`/',
		function ( $sql ) {
			preg_match( '/^SHOW COLUMNS FROM `([A-Za-z0-9_]+)`/', $sql, $m );
			$expected = DoughBoss_Growth_Activator::expected_columns();
			$columns  = isset( $expected[ $m[1] ] ) ? $expected[ $m[1] ] : array();
			$drop     = isset( $GLOBALS['dbgr_fail_missing_columns'][ $m[1] ] ) ? $GLOBALS['dbgr_fail_missing_columns'][ $m[1] ] : array();
			return array_values( array_diff( $columns, $drop ) );
		}
	);
}

db_test(
	'install(): a table that exists but lacks a declared column is NOT installed (a failed ALTER): no version is recorded and the failure is held',
	function () {
		dbgr_fail_describe_without( array( 'wp_doughboss_growth_outbox' => array( 'payload_json' ) ) );
		assert_false( DoughBoss_Growth_Activator::install(), 'install reports failure although every table exists' );
		assert_same( false, get_option( 'doughboss_growth_db_version', false ), 'the new schema version is not recorded' );
		assert_false( DoughBoss_Growth_Activator::storage_ready(), 'storage is not ready' );
		assert_same( array(), DoughBoss_Growth_Activator::missing_tables(), 'the table-existence check alone would have passed (the old behaviour)' );
		assert_same( array( 'wp_doughboss_growth_outbox.payload_json' ), DoughBoss_Growth_Activator::schema_problems(), 'the missing column is named' );
		$failure = DoughBoss_Growth_Failures::all()[0];
		assert_same( 'schema_install_failed', $failure['code'], 'the failure is held' );
		assert_same( '1', $failure['context']['problems'], 'one problem' );
		assert_same( 'outbox.payload_json', $failure['context']['first'], 'naming the column without the table prefix' );

		// Control: the same install with every column present records the version and removes the stale failure.
		$GLOBALS['dbgr_fail_missing_columns'] = array();
		assert_true( DoughBoss_Growth_Activator::install(), 'a complete schema installs' );
		assert_same( DOUGHBOSS_GROWTH_DB_VERSION, get_option( 'doughboss_growth_db_version' ), 'and the version is recorded' );
		assert_same( array(), dbgr_fail_codes(), 'the schema failure no longer stands' );
	}
);

db_test(
	'install(): columns that cannot be read are not confirmed (fail closed): an error or an empty answer is a problem, never a pass',
	function () {
		$GLOBALS['wpdb']->fail_on( '/^SHOW COLUMNS/' );
		assert_false( DoughBoss_Growth_Activator::install(), 'a database error while reading columns fails the install' );
		assert_same( false, get_option( 'doughboss_growth_db_version', false ), 'no version' );
		$problems = DoughBoss_Growth_Activator::schema_problems();
		assert_same( count( DoughBoss_Growth::schemas() ), count( $problems ), 'every table is reported' );
		assert_matches( '/ \(columns unreadable\)$/', $problems[0], 'as unreadable' );

		dbgr_test_reset();
		assert_false( DoughBoss_Growth_Activator::install(), 'an empty answer is not a pass either' );
		assert_false( DoughBoss_Growth_Activator::storage_ready(), 'not ready' );
	}
);

db_test(
	'install(): the stored version is read back, so a refused write is not reported as success (sub-process)',
	function () {
		$code = <<<'CODE'
dbgr_test_describe_tables();
$ok = DoughBoss_Growth_Activator::install();
echo json_encode( array( 'ok' => $ok, 'ready' => DoughBoss_Growth_Activator::storage_ready(), 'codes' => array_column( DoughBoss_Growth_Failures::all(), 'code' ) ) );
CODE;
		$data = dbgr_fail_run( $code, dbgr_fail_prelude( array( 'doughboss_growth_db_version' ) ) );
		if ( null === $data ) {
			return;
		}
		assert_same( false, $data['ok'], 'install is false when the version could not be stored' );
		assert_same( false, $data['ready'], 'storage is not ready' );
		assert_same( array( 'schema_version_save_failed' ), $data['codes'], 'and the failure is held' );
	}
);

db_test(
	'self-heal: a repair that fails is throttled (no SHOW TABLES and dbDelta on every admin request), reported, retried on the Growth page and cleared once it works',
	function () {
		dbgr_fail_describe_without( array( 'wp_doughboss_growth_outbox' => array( 'payload_json' ) ) );
		foreach ( DoughBoss_Growth::schemas() as $sql ) {
			$GLOBALS['wpdb']->create_table_from_mysql( $sql );
		}
		update_option( 'doughboss_growth_db_version', DOUGHBOSS_GROWTH_DB_VERSION ); // A site that recorded the version earlier.
		dbgr_test_login( array( 'manage_options' ) );
		$n = count( DoughBoss_Growth::schemas() );
		assert_true( DoughBoss_Growth_Activator::storage_ready(), 'the stored version still says ready (the old blind spot)' );

		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_count( $n, $GLOBALS['dbgr_dbdelta'], 'the first ordinary admin request notices the missing column and repairs' );
		assert_true( false !== get_transient( 'doughboss_growth_install_retry' ), 'the failed repair sets the retry throttle' );
		assert_false( false !== get_transient( 'doughboss_growth_schema_ok' ), 'and no "all fine" marker' );
		assert_true( in_array( 'schema_install_failed', dbgr_fail_codes(), true ), 'the failure is held' );

		$GLOBALS['dbgr_dbdelta'] = array();
		$GLOBALS['wpdb']->reset_log();
		for ( $i = 0; $i < 3; $i++ ) {
			DoughBoss_Growth_Activator::maybe_upgrade();
		}
		assert_same( array(), $GLOBALS['dbgr_dbdelta'], 'three more ordinary requests: no dbDelta' );
		assert_same( array(), $GLOBALS['wpdb']->queries_matching( '/^SHOW/' ), 'and no SHOW TABLES or SHOW COLUMNS' );

		$_GET['page'] = 'doughboss-growth';
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_count( $n, $GLOBALS['dbgr_dbdelta'], 'opening the Growth page is an explicit retry' );

		unset( $_GET['page'] );
		$GLOBALS['dbgr_fail_missing_columns'] = array(); // The column is there again (a manual fix, or the host finished the ALTER).
		$GLOBALS['dbgr_dbdelta']              = array();
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_same( array(), $GLOBALS['dbgr_dbdelta'], 'still inside the five minute window: nothing yet' );
		assert_true( in_array( 'schema_install_failed', dbgr_fail_codes(), true ), 'the failure is still held inside the window' );
		dbgr_test_advance( 301 );
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_same( array(), $GLOBALS['dbgr_dbdelta'], 'after the window the check finds a complete schema, so no repair is needed' );
		assert_true( false !== get_transient( 'doughboss_growth_schema_ok' ), 'a clean check is remembered for an hour' );
		assert_false( false !== get_transient( 'doughboss_growth_install_retry' ), 'and the retry throttle is released' );
		assert_same( array(), dbgr_fail_codes(), 'the stale failure is removed' );
	}
);

db_test(
	'declared_columns(): keys, indexes and constraints are not columns; quoted names and decimal(10,2) are handled',
	function () {
		$sql = "CREATE TABLE wp_doughboss_growth_x (\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `name_col` varchar(64) NOT NULL DEFAULT '',\n  amount decimal(10,2) NOT NULL DEFAULT 0,\n  key_hash char(64) NOT NULL,\n  index_no int NOT NULL,\n  PRIMARY KEY  (id),\n  UNIQUE KEY uniq_name (name_col, amount),\n  KEY idx_hash (key_hash(10)),\n  CONSTRAINT fk FOREIGN KEY (id) REFERENCES wp_y (id)\n) DEFAULT CHARACTER SET utf8mb4;";
		assert_same( array( 'id', 'name_col', 'amount', 'key_hash', 'index_no' ), DoughBoss_Growth_Activator::declared_columns( $sql ), 'columns only, in order' );
		assert_same( array(), DoughBoss_Growth_Activator::declared_columns( 'not sql' ), 'junk gives nothing' );
		assert_same( array(), DoughBoss_Growth_Activator::declared_columns( null ), 'null gives nothing' );
		// Every real schema parses to the columns the harness database builds (a regression guard on the parser itself).
		$GLOBALS['wpdb']->use_sqlite();
		foreach ( DoughBoss_Growth::schemas() as $real ) {
			$GLOBALS['wpdb']->create_table_from_mysql( $real );
		}
		foreach ( DoughBoss_Growth_Activator::expected_columns() as $table => $columns ) {
			$built = array();
			foreach ( $GLOBALS['wpdb']->sqlite_raw( 'PRAGMA table_info(' . $table . ')' ) as $row ) {
				$built[] = $row['name'];
			}
			assert_same( $built, $columns, $table . ': the parsed columns are the table\'s columns' );
		}
	}
);

db_test(
	'Status "Database tables": Ready only when every table and column is there; says which are missing otherwise',
	function () {
		DoughBoss_Growth::init();
		$row = function () {
			$html = dbgr_fail_render();
			preg_match( '#<th scope="row">Database tables</th><td>([^<]*)</td>#', $html, $m );
			return isset( $m[1] ) ? html_entity_decode( $m[1], ENT_QUOTES ) : '';
		};
		assert_contains( 'Not complete: wp_doughboss_growth_rate', $row(), 'tables missing: they are named' );
		assert_contains( 'wp_doughboss_growth_outbox', $row(), 'every missing table is named' );

		dbgr_fail_describe_without( array() );
		DoughBoss_Growth_Activator::install();
		assert_same( 'Ready', $row(), 'complete schema and stored version: Ready' );

		$GLOBALS['dbgr_fail_missing_columns'] = array( 'wp_doughboss_growth_outbox' => array( 'payload_json', 'status' ) );
		assert_contains( 'Not complete: wp_doughboss_growth_outbox.payload_json, wp_doughboss_growth_outbox.status', $row(), 'a missing column is named although the version is stored' );
		assert_contains( 'stay off until this is fixed', $row(), 'with what it means' );

		$GLOBALS['dbgr_fail_missing_columns'] = array();
		delete_option( 'doughboss_growth_db_version' );
		assert_same( 'Not confirmed', $row(), 'everything present but the version not stored: Not confirmed' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 3. Settings: save notice and the active label (finding 8)                                                    */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'apply_save(): every typed value that sanitising drops is reported as refused, saved is still true, and the stored value is the blank or default',
	function () {
		$working_name = 'Join the Mi' . 'nis list';
		$cases        = array(
			array( 'gtm_container_id', 'GTM-ABC', 'gtm_container_id', '' ),
			array( 'gtm_container_id', 'not-an-id', 'gtm_container_id', '' ),
			array( 'ga4_measurement_id', 'G-12', 'ga4_measurement_id', '' ),
			array( 'meta_pixel_id', 'abc', 'meta_pixel_id', '' ),
			array( 'meta_pixel_id', '12345', 'meta_pixel_id', '' ),
			array( 'consent_text_version', 'bad version!', 'consent_text_version', '2' ),
			array( 'consent_default', 'allow', 'consent_default', 'deny' ),
			array( 'privacy_policy_url', 'privacy-policy', 'privacy_policy_url', '' ),
			array( 'privacy_policy_url', 'example.com.au/privacy', 'privacy_policy_url', '' ),
			array( 'privacy_policy_url', 'ftp://example.com.au/privacy', 'privacy_policy_url', '' ),
			array( 'notify_webhook_url', 'http://hooks.example-receiver.com.au/x', 'notify_webhook_url', '' ),
			array( 'notify_webhook_url', 'https://127.0.0.1/hook', 'notify_webhook_url', '' ),
			array( 'notify_webhook_url', 'https://hooks.example-receiver.com.au:8443/x', 'notify_webhook_url', '' ),
			array( 'retention_pending_days', '0', 'retention_pending_days', 30 ),
			array( 'retention_pending_days', 'abc', 'retention_pending_days', 30 ),
			array( 'retention_pending_days', '-3', 'retention_pending_days', 30 ),
			array( 'retention_pending_days', '1.5', 'retention_pending_days', 30 ),
			array( 'retention_confirmed_months', '-4', 'retention_confirmed_months', null ),
			array( 'retention_confirmed_months', '0', 'retention_confirmed_months', null ),
			array( 'retention_confirmed_months', 'abc', 'retention_confirmed_months', null ),
			array( 'coming_soon_headline', $working_name, 'coming_soon_headline', DoughBoss_Growth_Settings::DEFAULT_COMING_SOON_HEADLINE ),
			array( 'coming_soon_body', $working_name, 'coming_soon_body', DoughBoss_Growth_Settings::DEFAULT_COMING_SOON_BODY ),
			array( 'coming_soon_page_slug', '!!!', 'coming_soon_page_slug', 'coming-soon' ),
			array( 'sender_legal_name', '<b></b>', 'sender_legal_name', '' ),
			array( 'gtm_container_id', array( 'GTM-ABCD123' ), 'gtm_container_id', '' ),
		);
		foreach ( $cases as $case ) {
			dbgr_test_reset();
			$result = DoughBoss_Growth_Settings::apply_save( array( $case[0] => $case[1] ) );
			$label  = $case[0] . ' = ' . wp_json_encode( $case[1] );
			assert_same( array( $case[2] ), $result['refused'], $label . ': reported as refused, and nothing else is' );
			assert_same( array(), $result['adjusted'], $label . ': not "adjusted"' );
			assert_same( $case[3], $result['settings'][ $case[0] ], $label . ': the stored value is the blank or default' );
			assert_true( $result['saved'], $label . ': saved is still true (it only says what was stored read back identical)' );
		}
	}
);

db_test(
	'apply_save(): what is NOT a refusal: valid values, normalising (case, spaces), blank boxes, unknown keys and secrets',
	function () {
		$result = DoughBoss_Growth_Settings::apply_save(
			array(
				'gtm_container_id'           => '  gtm-abcd123  ',
				'ga4_measurement_id'         => 'g-abcd1234',
				'meta_pixel_id'              => '1234567890',
				'consent_text_version'       => 'v2.1',
				'consent_default'            => 'opt_out',
				'sender_legal_name'          => "  Example   Pty\n Ltd ",
				'privacy_policy_url'         => 'https://example.com.au/privacy/',
				'notify_webhook_url'         => 'https://hooks.example-receiver.com.au/x',
				'retention_pending_days'     => ' 45 ',
				'retention_confirmed_months' => '12',
				'coming_soon_headline'       => 'Fresh news soon',
				'coming_soon_body'           => 'Be first to know',
				'coming_soon_page_slug'      => 'Coming-Soon',
				'unknown_key'                => 'x',
				'DOUGHBOSS_GROWTH_GA4_API_SECRET' => 'must-not-matter',
			)
		);
		assert_same( array(), $result['refused'], 'nothing refused' );
		assert_same( array(), $result['adjusted'], 'case and spacing are normalised without a warning' );
		assert_same( 'GTM-ABCD123', $result['settings']['gtm_container_id'], 'the id was normalised' );
		assert_same( 'Example Pty Ltd', $result['settings']['sender_legal_name'], 'white space collapsed' );

		dbgr_test_reset();
		$blank = array_fill_keys( DoughBoss_Growth_Settings::NOTE_FIELDS, '' );
		$result = DoughBoss_Growth_Settings::apply_save( $blank );
		assert_same( array(), $result['refused'], 'an empty box is not a refusal (retention, ids, teaser lines and all)' );
		assert_same( array(), $result['adjusted'], 'nor an adjustment' );
		assert_same( array(), DoughBoss_Growth_Settings::apply_save( array() )['refused'], 'an empty save refuses nothing' );
	}
);

db_test(
	'apply_save(): a value that is kept but changed is reported as adjusted (capped, cleaned, shortened)',
	function () {
		$result = DoughBoss_Growth_Settings::apply_save(
			array(
				'retention_pending_days'     => '400',
				'retention_confirmed_months' => '200',
				'sender_legal_name'          => 'Example <b>Pty</b> Ltd',
				'coming_soon_headline'       => str_repeat( 'a', 100 ),
				'coming_soon_page_slug'      => 'Coming Soon',
			)
		);
		assert_same( array(), $result['refused'], 'none dropped' );
		$adjusted = $result['adjusted'];
		sort( $adjusted );
		assert_same( array( 'coming_soon_headline', 'coming_soon_page_slug', 'retention_confirmed_months', 'retention_pending_days', 'sender_legal_name' ), $adjusted, 'each changed value is listed' );
		assert_same( 365, $result['settings']['retention_pending_days'], 'capped at 365' );
		assert_same( 120, $result['settings']['retention_confirmed_months'], 'capped at 120' );
		assert_same( 'Example Pty Ltd', $result['settings']['sender_legal_name'], 'markup removed' );
		assert_same( 80, strlen( $result['settings']['coming_soon_headline'] ), 'shortened to 80' );
		assert_same( 'coming-soon', $result['settings']['coming_soon_page_slug'], 'cleaned' );
	}
);

db_test(
	'save notice: "Settings saved." is shown only when everything typed was kept; otherwise a warning lists what was not, in plain words, without repeating the rejected wording',
	function () {
		DoughBoss_Growth::init();
		$working_name = 'Join the Mi' . 'nis list';
		$query        = dbgr_fail_save(
			array(
				'gtm_container_id'     => 'GTM-ABC',
				'privacy_policy_url'   => 'privacy',
				'retention_pending_days' => '0',
				'retention_confirmed_months' => '-4',
				'coming_soon_headline' => $working_name,
				'coming_soon_body'     => $working_name,
				'sender_legal_name'    => 'Example <b>Pty</b> Ltd',
			)
		);
		assert_same( '1', $query['dbgr_saved'], 'saved says 1' );
		assert_same( 'gtm_container_id,privacy_policy_url,retention_pending_days,retention_confirmed_months,coming_soon_headline,coming_soon_body', $query['dbgr_refused'], 'the redirect carries the refused fields' );
		assert_same( 'sender_legal_name', $query['dbgr_adjusted'], 'and the adjusted one' );

		$query['page'] = 'doughboss-growth';
		$html          = dbgr_fail_render( $query );
		assert_not_contains( 'notice-success', $html, 'no plain success notice' );
		assert_contains( 'Settings saved, but not everything you typed was kept', $html, 'the warning says it' );
		assert_contains( 'Tag Manager container id: that is not a valid id', $html, 'the id is named, with what to do' );
		assert_contains( 'Privacy-policy URL: that is not a usable address', $html, 'so is the URL' );
		assert_contains( 'Delete unconfirmed waitlist rows after (days): enter a whole number of 1 or more, so the default of 30 days is used', $html, 'the retention default is stated' );
		assert_contains( 'confirmed rows are never deleted automatically', $html, 'and the never-delete consequence' );
		assert_contains( 'Coming-soon headline: that wording is not allowed', $html, 'the headline wording message' );
		assert_contains( 'Coming-soon text: that wording is not allowed', $html, 'and the text one' );
		assert_contains( 'Sender legal name: the value was shortened, capped or cleaned', $html, 'an adjusted value is reported' );
		assert_not_contains( 'minis', strtolower( $html ), 'the working name is never repeated' );
		assert_not_contains( 'GTM-ABC', $html, 'nor the rejected value' );

		// A clean save still gets the plain success notice.
		dbgr_test_reset();
		DoughBoss_Growth::init();
		$query         = dbgr_fail_save( array( 'gtm_container_id' => 'GTM-ABCD123', 'retention_pending_days' => '45' ) );
		$query['page'] = 'doughboss-growth';
		assert_false( isset( $query['dbgr_refused'] ), 'nothing refused' );
		$html = dbgr_fail_render( $query );
		assert_contains( 'notice-success', $html, 'a clean save: the success notice' );
		assert_contains( 'Settings saved.', $html, 'with its text' );
		assert_not_contains( 'not everything you typed was kept', $html, 'and no warning' );

		// The query string is only believed for the names apply_save() can return.
		$html = dbgr_fail_render( array( 'page' => 'doughboss-growth', 'dbgr_saved' => '1', 'dbgr_refused' => 'bogus,<script>alert(1)</script>,gtm_container_id' ) );
		assert_not_contains( 'bogus', $html, 'an unknown field name prints nothing' );
		assert_not_contains( '<script>alert', $html, 'and no markup' );
		assert_contains( 'Tag Manager container id', $html, 'a known name is honoured' );
	}
);

db_test(
	'the finding\'s scenario: tick Tag Manager, type GTM-ABC, save. No "Settings saved." and no "Currently active"; the label says what it waits for; a real id turns it active',
	function () {
		DoughBoss_Growth::init();
		$query = dbgr_fail_save(
			array(
				'features'         => array( 'consent_banner' => '1', 'gtm' => '1' ),
				'gtm_container_id' => 'GTM-ABC',
			)
		);
		assert_same( 'gtm_container_id', $query['dbgr_refused'], 'the id was refused' );
		assert_true( DoughBoss_Growth_Settings::enabled( 'gtm' ), 'and Settings::enabled() says gtm is on (the flag-derived answer that used to be shown)' );

		DoughBoss_Growth::reset_state();
		DoughBoss_Growth::set_time_override( DBGR_TEST_EPOCH );
		DoughBoss_Growth::init();
		$query['page'] = 'doughboss-growth';
		$html          = dbgr_fail_render( $query );
		assert_not_contains( 'notice-success', $html, 'no success notice' );
		assert_same( 'Loads one Tag Manager container. Needs the consent banner. (On, waiting for: a valid Tag Manager container id.)', dbgr_fail_feature_text( $html, 'Tag Manager' ), 'the label says what is missing' );
		assert_not_contains( 'Currently active', dbgr_fail_feature_text( $html, 'Tag Manager' ), 'not active' );
		assert_contains( '(Currently active.)', dbgr_fail_feature_text( $html, 'Consent banner' ), 'the banner, which really runs, is active (control)' );

		// A valid id with the real event list: active.
		$query         = dbgr_fail_save( array( 'features' => array( 'consent_banner' => '1', 'gtm' => '1' ), 'gtm_container_id' => 'GTM-ABCD123' ) );
		$query['page'] = 'doughboss-growth';
		DoughBoss_Growth::reset_state();
		DoughBoss_Growth::set_time_override( DBGR_TEST_EPOCH );
		DoughBoss_Growth::init();
		$html = dbgr_fail_render( $query );
		assert_contains( 'notice-success', $html, 'now a plain success' );
		assert_contains( '(Currently active.)', dbgr_fail_feature_text( $html, 'Tag Manager' ), 'and Tag Manager is active' );

		// An unreadable event list keeps Tag Manager off at run time, so the label must say so.
		$bad = sys_get_temp_dir() . '/dbgr-fail-events-' . bin2hex( random_bytes( 4 ) ) . '.json';
		file_put_contents( $bad, '{ not json' );
		DoughBoss_Growth_Consent::set_events_path_override( $bad );
		$html = dbgr_fail_render( $query );
		assert_contains( 'waiting for: the event list file, which is missing or invalid', dbgr_fail_feature_text( $html, 'Tag Manager' ), 'a broken event list: waiting' );
		DoughBoss_Growth_Consent::set_events_path_override( null );
		unlink( $bad );
	}
);

db_test(
	'the active label tells the truth for each real runtime condition: prerequisites, storage, sender-name lint, attribution without the banner, lead recording, Square token, safety filters',
	function () {
		$set = function ( array $features, array $extra = array() ) {
			dbgr_test_reset();
			dbgr_fail_describe_without( array() );
			DoughBoss_Growth_Activator::install();
			update_option( 'doughboss_growth_settings', array_merge( array( 'features' => $features ), $extra ) );
			if ( class_exists( 'DoughBoss_Growth_Consent', false ) ) {
				DoughBoss_Growth_Consent::reset_state();
			}
			DoughBoss_Growth::init();
			return dbgr_fail_render();
		};

		// A stored flag whose prerequisite is off (edited straight into the option): waiting, with the reason.
		$html = $set( array( 'gtm' => true ) );
		assert_contains( '(On, waiting for: the consent banner to be on.)', dbgr_fail_feature_text( $html, 'Tag Manager' ), 'gtm without the banner' );

		// Waitlist: the sender name passes the dependency rule (non-empty) but fails the public-copy lint.
		$bad_name = 'Mi' . 'nis Pty Ltd';
		$html     = $set( array( 'waitlist' => true ), array( 'sender_legal_name' => $bad_name, 'privacy_policy_url' => '/privacy/' ) );
		assert_true( DoughBoss_Growth_Settings::enabled( 'waitlist' ), 'the flag-derived answer is on' );
		assert_contains( '(On, waiting for: a sender legal name that passes the wording check, and a privacy-policy URL.)', dbgr_fail_feature_text( $html, 'VIP waitlist' ), 'the waitlist cannot run with that sender name' );
		$html = $set( array( 'waitlist' => true ), array( 'sender_legal_name' => 'Example Pty Ltd', 'privacy_policy_url' => '/privacy/' ) );
		assert_contains( '(Currently active.)', dbgr_fail_feature_text( $html, 'VIP waitlist' ), 'a good sender name: active (control)' );

		// Storage not ready.
		dbgr_test_reset();
		update_option( 'doughboss_growth_settings', array( 'features' => array( 'waitlist' => true ), 'sender_legal_name' => 'Example Pty Ltd', 'privacy_policy_url' => '/privacy/' ) );
		DoughBoss_Growth::init();
		$html = dbgr_fail_render();
		assert_contains( 'waiting for: the database tables (see Database tables above)', dbgr_fail_feature_text( $html, 'VIP waitlist' ), 'no tables: waiting for them' );

		// Attribution does nothing without the banner.
		$html = $set( array( 'attribution' => true ) );
		assert_contains( '(On, waiting for: the consent banner to be on (nothing is captured without it).)', dbgr_fail_feature_text( $html, 'Attribution capture' ), 'attribution without the banner' );
		$html = $set( array( 'attribution' => true, 'consent_banner' => true ) );
		assert_contains( '(Currently active.)', dbgr_fail_feature_text( $html, 'Attribution capture' ), 'with the banner: active (control)' );

		// Lead form: the lead record hook must be wired.
		$html = $set( array( 'lead_form' => true ) );
		assert_contains( '(Currently active.)', dbgr_fail_feature_text( $html, 'Lead form' ), 'lead form with its recording wired: active (control)' );
		remove_action( 'doughboss_catering_enquiry_created', array( 'DoughBoss_Growth_Attribution', 'on_enquiry_created' ), 20 );
		$html = dbgr_fail_render();
		assert_contains( '(On, waiting for: the lead record to be wired up, which did not start.)', dbgr_fail_feature_text( $html, 'Lead form' ), 'recording not wired: waiting' );

		// Square labour token.
		putenv( 'DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN' );
		$html = $set( array( 'timesheet_recon' => true ) );
		assert_contains( 'waiting for: a Square labour token', dbgr_fail_feature_text( $html, 'Timesheet reconciliation' ), 'no token: waiting' );
		putenv( 'DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN=dbgr-test-labour-token-not-real-0001' );
		$html = $set( array( 'timesheet_recon' => true ) );
		putenv( 'DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN' );
		assert_contains( '(Currently active.)', dbgr_fail_feature_text( $html, 'Timesheet reconciliation' ), 'with a token: active (control)' );

		// A safety filter that switches a feature off.
		dbgr_test_reset();
		update_option( 'doughboss_growth_settings', array( 'features' => array( 'coming_soon' => true ) ) );
		add_filter(
			'doughboss_growth_feature_enabled',
			function ( $enabled, $feature ) {
				return 'coming_soon' === $feature ? false : $enabled;
			},
			10,
			2
		);
		DoughBoss_Growth::init();
		$html = dbgr_fail_render();
		assert_contains( 'waiting for: a safety check that is holding it off', dbgr_fail_feature_text( $html, 'Coming-soon section' ), 'held off by a rule: waiting, with a plain reason' );

		// Off stays inactive.
		assert_contains( '(Currently inactive.)', dbgr_fail_feature_text( $html, 'Landing pages' ), 'an unticked feature is inactive' );
	}
);

db_test(
	'the active label under the kill switch says so (sub-process)',
	function () {
		$code = <<<'CODE'
update_option( 'doughboss_growth_settings', array( 'features' => array( 'consent_banner' => true ) ) );
echo json_encode( DoughBoss_Growth_Admin::feature_status( 'consent_banner' ) );
CODE;
		$data = dbgr_fail_run( $code, "define( 'DOUGHBOSS_GROWTH_DISABLE', true );" );
		if ( null === $data ) {
			return;
		}
		assert_same( 'waiting', $data['state'], 'a ticked feature under the kill switch is waiting' );
		assert_contains( 'DOUGHBOSS_GROWTH_DISABLE', $data['reasons'][0], 'and the reason names the switch' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 4. Landing "create pages" and coming-soon "save" (finding 13)                                                */
/* ---------------------------------------------------------------------------------------------------------- */

/**
 * Render the Landing pages tab as a manager with a result flag in the query string.
 *
 * @param string $flag dbgr_lp value.
 * @return string
 */
function dbgr_fail_landing_tab( $flag ) {
	dbgr_test_login( array( 'manage_doughboss' ) );
	$_GET = array( 'page' => 'doughboss-growth', 'tab' => 'landing', 'dbgr_lp' => $flag );
	ob_start();
	DoughBoss_Growth_Landing::render_admin_tab();
	return ob_get_clean();
}

db_test(
	'landing notice: the "error" and "record_failed" results print a visible error notice, and only known codes print anything',
	function () {
		dbgr_landing_boot();
		assert_true( in_array( 'error', DoughBoss_Growth_Landing::RESULT_CODES, true ), 'error is a result code' );
		assert_true( in_array( 'record_failed', DoughBoss_Growth_Landing::RESULT_CODES, true ), 'so is record_failed' );

		$html = dbgr_fail_landing_tab( 'error' );
		assert_contains( 'notice-error', $html, 'the error redirect used to print nothing; it now prints an error notice' );
		assert_contains( 'Something went wrong while creating the pages', $html, 'in plain words' );
		assert_contains( 'Nothing was published', $html, 'saying nothing went live' );
		assert_contains( 'Recent failures', $html, 'and where the detail is' );

		$html = dbgr_fail_landing_tab( 'catering-corporate.record_failed,locations-revesby.created' );
		assert_contains( 'notice-error', $html, 'a page that exists but could not be recorded is an error' );
		assert_contains( 'could not save the record of it', $html, 'with its sentence' );
		assert_contains( 'locations/revesby: Draft created.', $html, 'and the other page keeps its own line' );

		$html = dbgr_fail_landing_tab( 'catering-corporate.insert_failed' );
		assert_contains( 'notice-error', $html, 'insert_failed is an error' );

		$html = dbgr_fail_landing_tab( 'catering-corporate.created' );
		assert_contains( 'notice-info', $html, 'control: a plain success stays informational' );
		assert_not_contains( 'notice-error', $html, 'and is not an error' );

		foreach ( array( 'bogus', 'error.extra', 'no-such-page.created', 'catering-corporate.nope', '<script>alert(1)</script>' ) as $junk ) {
			$html = dbgr_fail_landing_tab( $junk );
			assert_not_contains( 'class="notice', substr( $html, 0, strpos( $html, '<table' ) ?: strlen( $html ) ), 'a forged value "' . $junk . '" prints no notice' );
		}
		dbgr_landing_reset();
	}
);

db_test(
	'landing create: an exception redirects with "error" and is held (class only); an unrecorded page is reported, held, and adopted on the next click (sub-process)',
	function () {
		$boom = <<<'CODE'
dbgr_landing_boot();
dbgr_test_set_admin( true );
DoughBoss_Growth_Landing::init();
dbgr_test_login( array( 'manage_doughboss' ) );
$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_create_pages' );
$url = '';
try {
	DoughBoss_Growth_Landing::handle_create_pages();
} catch ( DBGR_Test_Redirect $r ) {
	$url = $r->url;
}
parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
$all = DoughBoss_Growth_Failures::all();
$_GET = array( 'page' => 'doughboss-growth', 'tab' => 'landing', 'dbgr_lp' => isset( $q['dbgr_lp'] ) ? $q['dbgr_lp'] : '' );
ob_start();
DoughBoss_Growth_Landing::render_admin_tab();
$html = ob_get_clean();
echo json_encode( array( 'lp' => isset( $q['dbgr_lp'] ) ? $q['dbgr_lp'] : null, 'codes' => array_column( $all, 'code' ), 'context' => $all ? $all[0]['context'] : null, 'stored' => serialize( get_option( 'doughboss_growth_failures' ) ), 'error_notice' => false !== strpos( $html, 'notice-error' ) && false !== strpos( $html, 'Something went wrong while creating the pages' ) ) );
CODE;
		$data = dbgr_fail_run( $boom, 'function wp_insert_post( $fields, $wp_error = false ) { throw new RuntimeException( "boom jane@example.com" ); }' );
		if ( null === $data ) {
			return;
		}
		assert_same( 'error', $data['lp'], 'the redirect carries "error"' );
		assert_same( array( 'landing_create_failed' ), $data['codes'], 'the failure is held' );
		assert_same( array( 'error' => 'RuntimeException' ), $data['context'], 'with the exception class only' );
		assert_not_contains( 'jane@example.com', $data['stored'], 'and no message text' );
		assert_true( $data['error_notice'], 'the tab shows the error notice' );

		$norecord = <<<'CODE'
dbgr_landing_boot();
dbgr_test_set_admin( true );
DoughBoss_Growth_Landing::init();
dbgr_test_login( array( 'manage_doughboss' ) );
$result = DoughBoss_Growth_Landing::create_pages();
$first  = array( 'result' => $result, 'recorded' => get_option( 'doughboss_growth_pages', 'none' ), 'codes' => array_column( DoughBoss_Growth_Failures::all(), 'code' ), 'posts' => count( $GLOBALS['dbgr_insert_calls'] ) );
$_GET = array( 'page' => 'doughboss-growth', 'tab' => 'landing', 'dbgr_lp' => implode( ',', array_map( function ( $k, $c ) { return $k . '.' . $c; }, array_keys( $result ), $result ) ) );
ob_start();
DoughBoss_Growth_Landing::render_admin_tab();
$html = ob_get_clean();
$first['notice'] = false !== strpos( $html, 'notice-error' ) && false !== strpos( $html, 'could not save the record of it' );
echo json_encode( $first );
CODE;
		$data = dbgr_fail_run( $norecord, dbgr_fail_prelude( array( 'doughboss_growth_pages' ) ) );
		if ( null === $data ) {
			return;
		}
		dbgr_landing_boot();
		$keys = array_keys( DoughBoss_Growth_Landing::definitions() );
		assert_same( 6, count( $keys ), 'six page definitions' );
		assert_same( array_fill_keys( $keys, 'record_failed' ), $data['result'], 'every page was made but none could be recorded: each says so (the old code said "created")' );
		assert_same( 'none', $data['recorded'], 'and the option really holds nothing' );
		assert_same( 6, $data['posts'], 'the six drafts exist' );
		assert_true( in_array( 'landing_record_failed', $data['codes'], true ), 'the failure is held' );
		assert_true( $data['notice'], 'and the tab shows an error notice' );

		// The notice tells the owner to click again: with the write working, the unrecorded drafts (they hold exactly their
		// own shortcode) are adopted and recorded.
		assert_same( array_fill_keys( $keys, 'created' ), DoughBoss_Growth_Landing::create_pages(), 'control: a normal run reports created and records them' );
		assert_same( 6, count( get_option( 'doughboss_growth_pages' ) ), 'six recorded' );
		assert_same( array(), dbgr_fail_codes(), 'with no failure held' );
		delete_option( 'doughboss_growth_pages' ); // The state the failed write left behind.
		assert_same( array_fill_keys( $keys, 'adopted' ), DoughBoss_Growth_Landing::create_pages(), 'the next click adopts the orphaned drafts' );
		assert_same( 6, count( get_option( 'doughboss_growth_pages' ) ), 'and records them' );
		dbgr_landing_reset();
	}
);

/**
 * Render the Coming soon tab as a manager with a result flag.
 *
 * @param string $flag dbgr_saved value ('' for none).
 * @return string
 */
function dbgr_fail_coming_soon_tab( $flag ) {
	dbgr_test_login( array( 'manage_doughboss' ) );
	$_GET = array( 'page' => 'doughboss-growth', 'tab' => 'coming-soon' );
	if ( '' !== $flag ) {
		$_GET['dbgr_saved'] = $flag;
	}
	ob_start();
	DoughBoss_Growth_Coming_Soon::render_tab();
	return ob_get_clean();
}

db_test(
	'coming soon: the save is read back and the tab renders the result (it used to redirect to a flag nothing on that tab read)',
	function () {
		update_option( 'doughboss_growth_settings', array( 'features' => array( 'coming_soon' => true ) ) );
		DoughBoss_Growth::init();
		dbgr_test_set_admin( true );
		assert_true( class_exists( 'DoughBoss_Growth_Coming_Soon', false ), 'the module is loaded' );

		assert_not_contains( 'class="notice', dbgr_fail_coming_soon_tab( '' ), 'no flag, no notice' );
		assert_contains( 'Home ribbon (saved)</th><td>Off', dbgr_fail_coming_soon_tab( '' ), 'the saved state is shown' );

		dbgr_test_login( array( 'manage_doughboss' ) );
		$_POST                = array( 'ribbon' => '1' );
		$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_save_coming_soon' );
		$redirect             = assert_throws( 'DBGR_Test_Redirect', array( 'DoughBoss_Growth_Coming_Soon', 'handle_save' ), 'save redirects' );
		assert_contains( 'tab=coming-soon', $redirect->url, 'to the coming-soon tab' );
		assert_contains( 'dbgr_saved=1', $redirect->url, 'saying saved' );
		assert_true( DoughBoss_Growth_Coming_Soon::ribbon_enabled(), 'and the ribbon really is on' );
		$html = dbgr_fail_coming_soon_tab( '1' );
		assert_contains( 'notice-success', $html, 'the tab shows a success notice' );
		assert_contains( 'Coming-soon settings saved.', $html, 'with its text' );
		assert_contains( 'Home ribbon (saved)</th><td>On', $html, 'and the saved state' );

		// Switching it off is a change too and reads back.
		$_POST                = array();
		$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_save_coming_soon' );
		$redirect             = assert_throws( 'DBGR_Test_Redirect', array( 'DoughBoss_Growth_Coming_Soon', 'handle_save' ), 'save redirects' );
		assert_contains( 'dbgr_saved=1', $redirect->url, 'off is saved' );
		assert_false( DoughBoss_Growth_Coming_Soon::ribbon_enabled(), 'and the ribbon is off' );
		assert_contains( 'Home ribbon (saved)</th><td>Off', dbgr_fail_coming_soon_tab( '1' ), 'the saved state follows' );

		$html = dbgr_fail_coming_soon_tab( '0' );
		assert_contains( 'notice-error', $html, 'a failure flag shows an error notice' );
		assert_contains( 'could not be saved', $html, 'in plain words' );
		assert_not_contains( 'notice-success', $html, 'and no success' );
		assert_not_contains( 'class="notice', dbgr_fail_coming_soon_tab( 'junk' ), 'a forged flag prints nothing' );
	}
);

db_test(
	'coming soon: a save that does not persist redirects with 0, is held, and leaves the ribbon as it was (sub-process)',
	function () {
		$code = <<<'CODE'
update_option( 'doughboss_growth_settings', array( 'features' => array( 'coming_soon' => true ) ) );
DoughBoss_Growth::init();
dbgr_test_set_admin( true );
dbgr_test_login( array( 'manage_doughboss' ) );
$_POST                = array( 'ribbon' => '1' );
$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_save_coming_soon' );
$url = '';
try {
	DoughBoss_Growth_Coming_Soon::handle_save();
} catch ( DBGR_Test_Redirect $r ) {
	$url = $r->url;
}
$_GET = array( 'page' => 'doughboss-growth', 'tab' => 'coming-soon', 'dbgr_saved' => '0' );
ob_start();
DoughBoss_Growth_Coming_Soon::render_tab();
$html = ob_get_clean();
$all = DoughBoss_Growth_Failures::all();
echo json_encode( array( 'url' => $url, 'ribbon' => DoughBoss_Growth_Coming_Soon::ribbon_enabled(), 'codes' => array_column( $all, 'code' ), 'error_notice' => false !== strpos( $html, 'notice-error' ), 'row_off' => false !== strpos( $html, 'Home ribbon (saved)</th><td>Off' ) ) );
CODE;
		$data = dbgr_fail_run( $code, dbgr_fail_prelude( array( 'doughboss_growth_coming_soon' ) ) );
		if ( null === $data ) {
			return;
		}
		assert_contains( 'dbgr_saved=0', $data['url'], 'the redirect says it was not saved (it used to say 1 regardless)' );
		assert_same( false, $data['ribbon'], 'the ribbon is unchanged' );
		assert_same( array( 'coming_soon_save_failed' ), $data['codes'], 'the failure is held' );
		assert_true( $data['error_notice'], 'the tab shows the error' );
		assert_true( $data['row_off'], 'and the real saved state' );
	}
);
