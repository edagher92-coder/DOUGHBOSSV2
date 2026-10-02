<?php
/**
 * WP-16 security review: regression tests for the defects the independent security and error-handling review found and
 * fixed (web/docs/wp/08-security-review.md).
 *
 *  S1  outbound URL policy: obfuscated IPv4 hosts ("127.1", "0x7f.0.0.1", "0177.0.0.1", "0xa9.254.169.254") are refused;
 *      carrier-grade NAT, link-local, benchmarking, documentation and multicast addresses are not public;
 *  S2  a host name that resolves to a non-public address is refused before any request is made (core's own safe-URL
 *      check does not cover 169.254.0.0/16 or 100.64.0.0/10);
 *  S3  the notification webhook setting cannot be saved with such a host.
 *
 * Every test has a negative control (a value that must still be accepted) so a policy that simply refuses everything
 * cannot pass. No test resolves a name or reaches a host: the harness transport fails the test on any undeclared call,
 * and the resolver is replaced through the doughboss_growth_http_resolve filter.
 *
 * @package DoughBoss_Growth
 */

/**
 * Resolver stub state: host => list of IP strings. A host that is not listed resolves to nothing.
 *
 * @param array|null $map New map, or null to read.
 * @return array
 */
function dbgr_sec_resolver_map( $map = null ) {
	static $current = array();
	if ( null !== $map ) {
		$current = $map;
	}
	return $current;
}

/**
 * Filter callback: the declared map.
 *
 * @param mixed  $supplied Value so far.
 * @param string $host     Host.
 * @return array
 */
function dbgr_sec_resolver( $supplied, $host ) {
	unset( $supplied );
	$map = dbgr_sec_resolver_map();
	return isset( $map[ $host ] ) ? $map[ $host ] : array();
}

db_test(
	'S1 url policy: shorthand, hex and octal IPv4 spellings are refused (the resolver would read them as addresses)',
	function () {
		foreach ( array(
			'https://127.1/x'                => 'loopback shorthand',
			'https://0x7f.0.0.1/x'           => 'loopback in hex',
			'https://0177.0.0.1/x'           => 'loopback in octal',
			'https://0300.0250.0.1/x'        => '192.168.0.1 in octal',
			'https://0xa9.254.169.254/x'     => 'cloud metadata address in hex',
			'https://1.2.3/x'                => 'three-part address',
			'https://foo.bar.0x7f/x'         => 'hex last label',
			'https://a.b.c.1/x'              => 'numeric last label',
		) as $url => $why ) {
			assert_false( DoughBoss_Growth_Http::url_allowed( $url ), 'refused: ' . $why );
		}
		// Negative controls: ordinary names and public literals are still allowed.
		foreach ( array(
			'https://hooks.example.org/x',
			'https://api.example-receiver.com.au/v1/events',
			'https://xn--e1afmkfd.xn--p1ai/x',
			'https://8.8.8.8/x',
			'https://1.1.1.1/x',
		) as $url ) {
			assert_true( DoughBoss_Growth_Http::url_allowed( $url ), 'still allowed: ' . $url );
		}
	}
);

db_test(
	'S1 public IP check: link-local, carrier-grade NAT, benchmarking, documentation, multicast, unique-local and mapped addresses are not public',
	function () {
		foreach ( array(
			'169.254.169.254', '100.64.0.1', '100.127.255.254', '198.18.0.1', '198.19.255.255', '192.0.0.8', '192.0.2.1', '198.51.100.7',
			'203.0.113.5', '224.0.0.1', '239.255.255.250', '255.255.255.255', '0.0.0.0', '127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1',
			'::1', '::', 'fc00::1', 'fd12:3456::1', 'fe80::1', 'ff02::1', '2001:db8::1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '::ffff:127.0.0.1',
		) as $ip ) {
			assert_false( DoughBoss_Growth_Http::is_public_ip( $ip ), 'not public: ' . $ip );
		}
		foreach ( array( '8.8.8.8', '1.1.1.1', '104.20.26.136', '100.63.255.255', '100.128.0.1', '198.17.255.255', '198.20.0.1', '2606:4700:4700::1111', '::ffff:8.8.8.8' ) as $ip ) {
			assert_true( DoughBoss_Growth_Http::is_public_ip( $ip ), 'public: ' . $ip );
		}
		foreach ( array( '', 'not-an-ip', '1.2.3', '256.1.1.1', null, 7, array( '8.8.8.8' ) ) as $bad ) {
			assert_false( DoughBoss_Growth_Http::is_public_ip( $bad ), 'not an address is never public: ' . var_export( $bad, true ) );
		}
		assert_false( DoughBoss_Growth_Http::url_allowed( 'https://100.64.0.1/x' ), 'a CGNAT literal is refused as a destination' );
		assert_false( DoughBoss_Growth_Http::url_allowed( 'https://198.18.0.1/x' ), 'a benchmarking literal is refused as a destination' );
		assert_true( DoughBoss_Growth_Http::url_allowed( 'https://104.20.26.136/x' ), 'a public literal is still allowed' );
	}
);

db_test(
	'S2 request: a name that resolves to a non-public address never reaches the transport',
	function () {
		add_filter( 'doughboss_growth_http_resolve', 'dbgr_sec_resolver', 10, 2 );
		dbgr_sec_resolver_map(
			array(
				'metadata.attacker.example.org' => array( '169.254.169.254' ),
				'cgnat.attacker.example.org'    => array( '100.64.0.9' ),
				'loop.attacker.example.org'     => array( '127.0.0.1' ),
				'v6.attacker.example.org'       => array( 'fd00::5' ),
				'mixed.attacker.example.org'    => array( '8.8.8.8', '10.0.0.5' ),
				'good.example.org'              => array( '8.8.8.8', '2606:4700:4700::1111' ),
			)
		);
		$calls = count( dbgr_test_http_calls() );
		foreach ( array( 'metadata', 'cgnat', 'loop', 'v6', 'mixed' ) as $label ) {
			$result = DoughBoss_Growth_Http::request( 'POST', 'https://' . $label . '.attacker.example.org/hook', array( 'json' => array( 'a' => 1 ) ) );
			assert_false( $result['ok'], $label . ': not ok' );
			assert_same( 'host_not_public', $result['error'], $label . ': reason' );
			assert_same( 0, $result['status'], $label . ': no status because nothing was sent' );
			assert_true( $result['retryable'], $label . ': retryable, so a transient bad DNS answer does not park a conversion for good (the outbox caps the attempts)' );
		}
		assert_same( $calls, count( dbgr_test_http_calls() ), 'no refused request reached the transport' );

		// Negative control: the same request to a name that resolves only to public addresses is sent.
		dbgr_test_http_expect( 'https://good.example.org/hook', dbgr_test_http_response( 200, 'ok' ) );
		$result = DoughBoss_Growth_Http::request( 'POST', 'https://good.example.org/hook', array( 'json' => array( 'a' => 1 ) ) );
		assert_true( $result['ok'], 'a public name is delivered' );
		dbgr_test_http_assert_done( 'the public request reached the transport' );

		// A name that resolves to nothing is left to the transport (it cannot connect either); it is not a refusal here.
		dbgr_test_http_expect( 'https://unresolved.example.org/hook', dbgr_test_http_response( 200, 'ok' ) );
		$result = DoughBoss_Growth_Http::request( 'POST', 'https://unresolved.example.org/hook', array( 'json' => array( 'a' => 1 ) ) );
		assert_true( $result['ok'], 'an unresolved name is deferred to the transport' );
		dbgr_test_http_assert_done( 'the unresolved request reached the transport' );
		remove_filter( 'doughboss_growth_http_resolve', 'dbgr_sec_resolver', 10 );
		dbgr_sec_resolver_map( array() );
	}
);

db_test(
	'S2 host_is_public: literals are judged by address, names by what they resolve to; a resolver that returns junk is ignored safely',
	function () {
		assert_false( DoughBoss_Growth_Http::host_is_public( 'https://169.254.169.254/latest' ), 'metadata literal' );
		assert_true( DoughBoss_Growth_Http::host_is_public( 'https://8.8.8.8/x' ), 'public literal' );
		assert_false( DoughBoss_Growth_Http::host_is_public( 'not a url' ), 'unparseable input is refused' );
		assert_false( DoughBoss_Growth_Http::host_is_public( '' ), 'empty input is refused' );
		add_filter( 'doughboss_growth_http_resolve', 'dbgr_sec_resolver', 10, 2 );
		dbgr_sec_resolver_map( array( 'junk.example.org' => array( 'zzz', '', '10.0.0.1' ) ) );
		assert_false( DoughBoss_Growth_Http::host_is_public( 'https://junk.example.org/x' ), 'a non-address answer is not public, and a private answer beside it refuses the host' );
		remove_filter( 'doughboss_growth_http_resolve', 'dbgr_sec_resolver', 10 );
		dbgr_sec_resolver_map( array() );
		assert_same( array(), DoughBoss_Growth_Http::resolve_host( 'anything.example.org' ), 'inside the harness nothing is looked up unless the filter says so' );
	}
);

db_test(
	'S3 settings: the notification webhook cannot be saved with an obfuscated or non-public host',
	function () {
		foreach ( array(
			'https://127.1/hook',
			'https://0x7f.0.0.1/hook',
			'https://0xa9.254.169.254/latest/meta-data',
			'https://169.254.169.254/latest/meta-data',
			'https://100.64.0.1/hook',
			'http://hooks.example.org/hook',
			'https://user:pw@hooks.example.org/hook',
		) as $url ) {
			$clean = DoughBoss_Growth_Settings::sanitize( array( 'notify_webhook_url' => $url ) );
			assert_same( '', $clean['notify_webhook_url'], 'refused: ' . $url );
		}
		$clean = DoughBoss_Growth_Settings::sanitize( array( 'notify_webhook_url' => 'https://hooks.example.org/hook' ) );
		assert_same( 'https://hooks.example.org/hook', $clean['notify_webhook_url'], 'negative control: an ordinary https URL is kept' );
	}
);
