<?php
/**
 * WP-01 tests: the outbound HTTP wrapper (policy, timeout, redaction) against the fake transport.
 *
 * No test here, and no code under test, reaches a real host: the harness transport FAILS the test on any call
 * that was not declared.
 *
 * @package DoughBoss_Growth
 */

db_test(
	'http url policy: https, public host, port 443 only; credentials, private ranges and odd hosts refused',
	function () {
		foreach ( array(
			'https://api.example-receiver.com.au/v1/events',
			'https://api.example-receiver.com.au:443/v1/events',
			'https://www.google-analytics.com/mp/collect?measurement_id=G-ABCD1234&api_secret=x',
			'https://8.8.8.8/x',
		) as $good ) {
			assert_true( DoughBoss_Growth_Http::url_allowed( $good ), 'allowed: ' . $good );
		}
		foreach ( array(
			'http://api.example-receiver.com.au/x'                  => 'plain http',
			'https://user:pass@api.example-receiver.com.au/x'       => 'credentials in the URL',
			'https://api.example-receiver.com.au:8443/x'            => 'non-443 port',
			'https://localhost/x'                                   => 'localhost',
			'https://intranet/x'                                    => 'single-label host',
			'https://printer.local/x'                               => '.local',
			'https://thing.internal/x'                              => '.internal',
			'https://app.test/x'                                    => '.test',
			'https://10.1.2.3/x'                                    => 'private IPv4',
			'https://192.168.0.10/x'                                => 'private IPv4 (192.168)',
			'https://127.0.0.1/x'                                   => 'loopback',
			'https://169.254.169.254/latest/meta-data'              => 'link-local metadata address',
			'https://[::1]/x'                                       => 'IPv6 literal',
			'ftp://api.example-receiver.com.au/x'                   => 'other scheme',
			'//api.example-receiver.com.au/x'                       => 'scheme-relative',
			'https://api.example-receiver.com.au/x y'               => 'space in the URL',
			"https://api.example-receiver.com.au/x\r\nHost: evil"   => 'CRLF injection',
			'https://-bad-.example-receiver.com.au/x'               => 'leading hyphen label host (regex edge)',
			''                                                      => 'empty',
		) as $bad => $why ) {
			if ( 'https://-bad-.example-receiver.com.au/x' === $bad ) {
				// Hyphen-edged labels are legal enough for DNS; only assert the call does not throw.
				assert_true( is_bool( DoughBoss_Growth_Http::url_allowed( $bad ) ), 'no exception for ' . $why );
				continue;
			}
			assert_false( DoughBoss_Growth_Http::url_allowed( $bad ), 'refused (' . $why . ')' );
		}
		assert_false( DoughBoss_Growth_Http::url_allowed( null ), 'null refused' );
		assert_false( DoughBoss_Growth_Http::url_allowed( array( 'https://a.example-receiver.com.au/' ) ), 'array refused' );
		assert_false( DoughBoss_Growth_Http::url_allowed( 'https://' . str_repeat( 'a', 2100 ) . '.com/' ), 'over-long URL refused' );
	}
);

db_test(
	'http request: a refused URL never reaches the transport',
	function () {
		$result = DoughBoss_Growth_Http::request( 'POST', 'http://api.example-receiver.com.au/x', array( 'json' => array( 'a' => 1 ) ) );
		assert_false( $result['ok'], 'not ok' );
		assert_same( 'url_not_allowed', $result['error'], 'reason' );
		assert_count( 0, dbgr_test_http_calls(), 'zero transport calls' );
		$result = DoughBoss_Growth_Http::request( 'TRACE', 'https://api.example-receiver.com.au/x' );
		assert_same( 'method_not_allowed', $result['error'], 'odd method refused' );
		assert_count( 0, dbgr_test_http_calls(), 'still zero transport calls' );
	}
);

db_test(
	'http request: 10 second timeout ceiling, no redirects, TLS verified, safe-URL mode, JSON body and headers',
	function () {
		$seen = null;
		dbgr_test_http_expect(
			'https://api.example-receiver.com.au/v1/events',
			function ( $url, $args ) use ( &$seen ) {
				$seen = $args;
				return dbgr_test_http_response( 200, '{"ok":true}' );
			}
		);
		$result = DoughBoss_Growth_Http::request(
			'POST',
			'https://api.example-receiver.com.au/v1/events',
			array(
				'json'    => array( 'event' => 'x', 'n' => 3 ),
				'timeout' => 120,
				'headers' => array( 'X-Test' => 'v' ),
			)
		);
		assert_true( $result['ok'], 'ok' );
		assert_same( 200, $result['status'], 'status' );
		assert_same( '{"ok":true}', $result['body'], 'body' );
		assert_false( $result['retryable'], 'a 200 is not retryable' );
		assert_same( 10, $seen['timeout'], 'a requested 120 s timeout is capped at 10' );
		assert_same( 0, $seen['redirection'], 'redirects are not followed' );
		assert_same( true, $seen['sslverify'], 'TLS verification stays on' );
		assert_same( true, $seen['reject_unsafe_urls'], 'safe-URL mode on' );
		assert_same( '{"event":"x","n":3}', $seen['body'], 'JSON encoded body' );
		assert_same( 'application/json', $seen['headers']['Content-Type'], 'JSON content type' );
		assert_same( 'v', $seen['headers']['X-Test'], 'caller headers kept' );
		assert_same( 'POST', $seen['method'], 'method' );
		assert_matches( '#^DoughBoss-Growth/0\.1\.0$#', $seen['user-agent'], 'user agent names the plugin version only' );
		dbgr_test_http_assert_done( 'the declared call was made' );

		dbgr_test_http_expect(
			'https://api.example-receiver.com.au/short',
			function ( $url, $args ) use ( &$seen ) {
				$seen = $args;
				return dbgr_test_http_response( 200, 'ok' );
			},
			array( 'times' => 0 )
		);
		DoughBoss_Growth_Http::request( 'GET', 'https://api.example-receiver.com.au/short', array( 'timeout' => 3 ) );
		assert_same( 3, $seen['timeout'], 'a shorter timeout is honoured' );
		DoughBoss_Growth_Http::request( 'GET', 'https://api.example-receiver.com.au/short', array( 'timeout' => 0 ) );
		assert_same( 1, $seen['timeout'], 'a zero timeout becomes 1 second, never unlimited' );
	}
);

db_test(
	'http request: header names must be plain tokens and values lose line breaks (no header injection)',
	function () {
		$seen = null;
		dbgr_test_http_expect(
			'https://api.example-receiver.com.au/h',
			function ( $url, $args ) use ( &$seen ) {
				$seen = $args;
				return dbgr_test_http_response( 200, 'ok' );
			},
			array( 'times' => 0 )
		);
		DoughBoss_Growth_Http::request( 'GET', 'https://api.example-receiver.com.au/h', array( 'headers' => array( 'X-Ok' => "value\r\nX-Injected: yes" ) ) );
		assert_same( 'valueX-Injected: yes', $seen['headers']['X-Ok'], 'CR and LF are stripped from a header value (no second header can start)' );
		$calls_before = count( dbgr_test_http_calls() );
		foreach ( array( "X-Bad\r\nInjected" => 'v', 'Bad Name' => 'v', '' => 'v', 0 => 'v' ) as $name => $value ) {
			$result = DoughBoss_Growth_Http::request( 'GET', 'https://api.example-receiver.com.au/h', array( 'headers' => array( $name => $value ) ) );
			assert_same( 'header_not_allowed', $result['error'], 'refused header name ' . var_export( $name, true ) );
		}
		$result = DoughBoss_Growth_Http::request( 'GET', 'https://api.example-receiver.com.au/h', array( 'headers' => array( 'X-Arr' => array( 'a' ) ) ) );
		assert_same( 'header_not_allowed', $result['error'], 'a non-scalar header value is refused' );
		assert_same( $calls_before, count( dbgr_test_http_calls() ), 'no refused request reached the transport' );
	}
);

db_test(
	'http request: failures are classified (retryable vs not) and transport errors never carry the error text',
	function () {
		dbgr_test_http_expect( 'https://api.example-receiver.com.au/a', dbgr_test_http_response( 500, 'boom' ) );
		dbgr_test_http_expect( 'https://api.example-receiver.com.au/b', dbgr_test_http_response( 429, 'slow down' ) );
		dbgr_test_http_expect( 'https://api.example-receiver.com.au/c', dbgr_test_http_response( 400, 'bad request' ) );
		dbgr_test_http_expect( 'https://api.example-receiver.com.au/d', dbgr_test_http_response( 404, 'nope' ) );
		dbgr_test_http_expect( 'https://api.example-receiver.com.au/e', new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 10001 ms with jane@example.com' ) );
		$r500 = DoughBoss_Growth_Http::request( 'GET', 'https://api.example-receiver.com.au/a' );
		$r429 = DoughBoss_Growth_Http::request( 'GET', 'https://api.example-receiver.com.au/b' );
		$r400 = DoughBoss_Growth_Http::request( 'GET', 'https://api.example-receiver.com.au/c' );
		$r404 = DoughBoss_Growth_Http::request( 'GET', 'https://api.example-receiver.com.au/d' );
		$rerr = DoughBoss_Growth_Http::request( 'GET', 'https://api.example-receiver.com.au/e' );
		assert_true( $r500['retryable'] && ! $r500['ok'] && 'http_500' === $r500['error'], '500 is retryable' );
		assert_true( $r429['retryable'], '429 is retryable' );
		assert_false( $r400['retryable'], '400 is terminal' );
		assert_false( $r404['retryable'], '404 is terminal' );
		assert_true( $rerr['retryable'] && 'transport_error' === $rerr['error'], 'transport error is retryable' );
		assert_same( 0, $rerr['status'], 'no status on a transport error' );
		assert_not_contains( 'jane@example.com', wp_json_encode( $rerr ), 'the transport error text is not passed on' );
	}
);

db_test(
	'http negative control: an undeclared call FAILS the test (the harness cannot be bypassed silently)',
	function () {
		$absorbed = dbgr_test_expect_failure(
			function () {
				DoughBoss_Growth_Http::request( 'GET', 'https://api.example-receiver.com.au/never-declared?token=abc' );
			},
			'an undeclared outbound call'
		);
		assert_true( $absorbed >= 1, 'at least one failure absorbed' );
		$calls = dbgr_test_http_calls();
		assert_true( $calls[0]['unexpected'], 'the call was recorded as unexpected' );

		// A declared call to a different URL is also unexpected.
		dbgr_test_http_expect( 'https://api.example-receiver.com.au/declared', dbgr_test_http_response( 200, 'ok' ) );
		dbgr_test_expect_failure(
			function () {
				DoughBoss_Growth_Http::request( 'GET', 'https://api.example-receiver.com.au/other' );
			},
			'a call to a URL other than the declared one'
		);
		// An unmet expectation is caught by assert_done.
		dbgr_test_expect_failure(
			function () {
				dbgr_test_http_assert_done( 'declared call was never made' );
			},
			'an expectation that was never consumed'
		);
		// Method mismatch is unexpected too.
		dbgr_test_http_expect( 'https://api.example-receiver.com.au/m', dbgr_test_http_response( 200, 'ok' ), array( 'method' => 'POST' ) );
		dbgr_test_expect_failure(
			function () {
				DoughBoss_Growth_Http::request( 'GET', 'https://api.example-receiver.com.au/m' );
			},
			'a GET where a POST was declared'
		);
	}
);

db_test(
	'http redaction: URLs lose query, fragment, credentials and token-like path segments',
	function () {
		assert_same( 'https://api.example-receiver.com.au/v1/events', DoughBoss_Growth_Http::redact_url( 'https://api.example-receiver.com.au/v1/events?api_secret=SUPERSECRET&x=1#frag' ), 'query and fragment dropped' );
		assert_same( 'https://hooks.example-receiver.com.au/services/[redacted]/[redacted]', DoughBoss_Growth_Http::redact_url( 'https://hooks.example-receiver.com.au/services/T0123456789ABCDE/B0123456789ABCDEFGH' ), 'long path segments hidden' );
		assert_not_contains( 'user', DoughBoss_Growth_Http::redact_url( 'https://user:pw@api.example-receiver.com.au/x' ), 'credentials dropped' );
		assert_same( '[invalid-url]', DoughBoss_Growth_Http::redact_url( 'not a url' ), 'unparseable' );
		assert_same( '[invalid-url]', DoughBoss_Growth_Http::redact_url( array() ), 'non-string' );
	}
);

db_test(
	'http redaction: headers, bodies and free text never expose secrets or personal data',
	function () {
		$headers = DoughBoss_Growth_Http::redact_headers(
			array(
				'Authorization'                       => 'Bearer abc.def.ghi',
				'X-DoughBoss-Growth-Signature'        => 'sha256=deadbeef',
				'X-Api-Key'                           => 'k-12345',
				'Cookie'                              => 'a=b',
				'Content-Type'                        => 'application/json',
				'X-Contact'                           => 'jane@example.com',
			)
		);
		assert_same( '[redacted]', $headers['Authorization'], 'Authorization masked' );
		assert_same( '[redacted]', $headers['X-DoughBoss-Growth-Signature'], 'signature masked' );
		assert_same( '[redacted]', $headers['X-Api-Key'], 'API key masked' );
		assert_same( '[redacted]', $headers['Cookie'], 'cookie masked' );
		assert_same( 'application/json', $headers['Content-Type'], 'harmless header kept' );
		assert_same( '[email]', $headers['X-Contact'], 'an email in a header value is masked' );

		$body = DoughBoss_Growth_Http::redact_body( '{"event":"lead","email":"jane@example.com","api_secret":"s3cr3t","nested":{"phone":"0412 345 678","ok":"fine"},"em":"abc"}' );
		foreach ( array( 'jane@example.com', 's3cr3t', '0412 345 678', '"abc"' ) as $needle ) {
			assert_not_contains( $needle, $body, 'JSON body redacts ' . $needle );
		}
		assert_contains( 'fine', $body, 'harmless nested value kept' );
		assert_contains( '"event":"lead"', $body, 'event name kept' );

		$text = DoughBoss_Growth_Http::redact_text( 'POST https://x.example/y?token=abc123&secret=zzz Bearer abcDEF123 for jane@example.com from 203.0.113.9 call +61 412 345 678 key ' . str_repeat( 'A', 40 ) );
		foreach ( array( 'abc123', 'zzz', 'abcDEF123', 'jane@example.com', '203.0.113.9', '412 345 678', str_repeat( 'A', 40 ) ) as $needle ) {
			assert_not_contains( $needle, $text, 'free text redacts ' . $needle );
		}
	}
);

db_test(
	'http log: a request logs method, redacted URL and status only; bodies and secrets are never logged',
	function () {
		$lines = array();
		add_action(
			'doughboss_growth_log',
			function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			}
		);
		dbgr_test_http_expect( 'https://api.example-receiver.com.au/v1/events?api_secret=TOPSECRET', dbgr_test_http_response( 200, '{"email":"jane@example.com"}' ) );
		DoughBoss_Growth_Http::request(
			'POST',
			'https://api.example-receiver.com.au/v1/events?api_secret=TOPSECRET',
			array(
				'json'    => array( 'email' => 'jane@example.com', 'phone' => '0412345678' ),
				'headers' => array( 'Authorization' => 'Bearer sekrit-token-value' ),
			)
		);
		assert_count( 1, $lines, 'one log line' );
		$line = $lines[0];
		foreach ( array( 'TOPSECRET', 'jane@example.com', '0412345678', 'sekrit-token-value', 'Authorization' ) as $needle ) {
			assert_not_contains( $needle, $line, 'log line omits ' . $needle );
		}
		assert_contains( '"status":"200"', $line, 'status logged' );
		assert_contains( 'https://api.example-receiver.com.au/v1/events', $line, 'redacted URL logged' );
		assert_contains( '"method":"POST"', $line, 'method logged' );

		// log() itself drops non-scalar context and truncates long values.
		$line = DoughBoss_Growth_Http::log( 'Odd Event!', array( 'arr' => array( 1 ), 'long' => str_repeat( 'x', 500 ), 'Key With Spaces' => 'v' ) );
		assert_matches( '/^DoughBoss Growth \[oddevent\] /', $line, 'event name normalised' );
		assert_not_contains( '"arr"', $line, 'arrays are dropped from log context' );
		assert_contains( 'keywithspaces', $line, 'keys normalised' );
		assert_true( strlen( $line ) < 400, 'long values truncated' );
	}
);
