<?php
/**
 * Outbound HTTP wrapper: https only, public hosts only, 10 second timeout, no redirects,
 * and redaction of everything that reaches a log line.
 *
 * Nothing in this plugin calls wp_remote_* directly; modules go through this class so the
 * policy and the redaction cannot be bypassed by accident. Request and response bodies are
 * never logged: only method, redacted URL, status and an error code.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP policy and redaction.
 */
final class DoughBoss_Growth_Http {

	/**
	 * Hard ceiling for any request, in seconds.
	 */
	const TIMEOUT = 10;

	/**
	 * Response bytes kept in memory.
	 */
	const MAX_BODY_BYTES = 65536;

	/**
	 * Methods modules may use.
	 */
	const METHODS = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD' );

	/**
	 * Whether a URL may be called: https, a public FQDN or public IP, no credentials, port 443.
	 *
	 * @param mixed $url URL.
	 * @return bool
	 */
	public static function url_allowed( $url ) {
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 2000 || preg_match( '/[\x00-\x20"<>\\\\]/', $url ) ) {
			return false;
		}
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( 'https' !== strtolower( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return false;
		}
		$host = strtolower( rtrim( $parts['host'], '.' ) );
		if ( '' === $host || '[' === substr( $host, 0, 1 ) ) {
			return false;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_public_ip( $host );
		}
		if ( false === strpos( $host, '.' ) || 1 !== preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/D', $host ) ) {
			return false;
		}
		// A real top-level domain starts with a letter. This refuses the shorthand and base-8/16 spellings of an IPv4
		// address that inet_aton() (and so the operating system's resolver) reads as an address but filter_var() does not:
		// "127.1", "0x7f.0.0.1", "0177.0.0.1", "0xa9.254.169.254".
		$labels = explode( '.', $host );
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]*$/D', (string) end( $labels ) ) ) {
			return false;
		}
		if ( 1 === preg_match( '/(^|\.)(localhost|local|internal|lan|home|corp|test|invalid|example)$/D', $host ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether an IP address is a public, routable one. Refuses loopback, private, link-local (the cloud metadata
	 * address 169.254.169.254), carrier-grade NAT (100.64.0.0/10), documentation, benchmarking and multicast ranges,
	 * the unspecified address, unique-local and link-local IPv6, and IPv4-mapped IPv6 whose IPv4 part is not public.
	 * WordPress core's own safe-URL check only covers 127/8, 10/8, 0/8, 172.16/12 and 192.168/16.
	 *
	 * @param mixed $ip IP address text.
	 * @return bool
	 */
	public static function is_public_ip( $ip ) {
		if ( ! is_string( $ip ) || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		$packed = function_exists( 'inet_pton' ) ? @inet_pton( $ip ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the address was validated above.
		if ( ! is_string( $packed ) ) {
			return false;
		}
		if ( 16 === strlen( $packed ) ) {
			if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
				$v4 = inet_ntop( substr( $packed, 12 ) );
				return is_string( $v4 ) && self::is_public_ip( $v4 );
			}
			$first = ord( $packed[0] );
			if ( $first >= 0xfc && $first <= 0xfd ) {
				return false; // fc00::/7 unique local.
			}
			if ( 0xfe === $first && 0x80 === ( ord( $packed[1] ) & 0xc0 ) ) {
				return false; // fe80::/10 link local.
			}
			if ( 0xff === $first ) {
				return false; // ff00::/8 multicast.
			}
			if ( "\x20\x01\x0d\xb8" === substr( $packed, 0, 4 ) ) {
				return false; // 2001:db8::/32 documentation.
			}
			return true;
		}
		$long = ip2long( $ip );
		if ( false === $long ) {
			return false;
		}
		foreach ( self::NON_PUBLIC_V4 as $range ) {
			$base = ip2long( $range[0] );
			$mask = ( 0 === $range[1] ) ? 0 : ( ~( ( 1 << ( 32 - $range[1] ) ) - 1 ) & 0xffffffff );
			if ( false !== $base && ( ( $long & $mask ) === ( $base & $mask ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * IPv4 ranges that are never a public destination (on top of PHP's private and reserved flags).
	 */
	const NON_PUBLIC_V4 = array(
		array( '0.0.0.0', 8 ),
		array( '100.64.0.0', 10 ),
		array( '169.254.0.0', 16 ),
		array( '192.0.0.0', 24 ),
		array( '192.0.2.0', 24 ),
		array( '198.18.0.0', 15 ),
		array( '198.51.100.0', 24 ),
		array( '203.0.113.0', 24 ),
		array( '224.0.0.0', 3 ),
	);

	/**
	 * The addresses a host name resolves to. The doughboss_growth_http_resolve filter may supply them (tests, or a
	 * site with its own resolver). Inside the test harness nothing is resolved unless the filter supplies a list, so no
	 * test performs a DNS lookup.
	 *
	 * @param string $host Host name (not an IP literal).
	 * @return array IP address strings; empty when nothing could be resolved.
	 */
	public static function resolve_host( $host ) {
		$supplied = apply_filters( 'doughboss_growth_http_resolve', null, $host );
		if ( is_array( $supplied ) ) {
			return array_values( array_filter( $supplied, 'is_string' ) );
		}
		if ( defined( 'DBGR_TESTING' ) ) {
			return array();
		}
		$ips = array();
		$v4  = function_exists( 'gethostbynamel' ) ? gethostbynamel( $host ) : false;
		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}
		if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
			$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed AAAA lookup is not an error here.
			if ( is_array( $v6 ) ) {
				foreach ( $v6 as $record ) {
					if ( is_array( $record ) && isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}
		return $ips;
	}

	/**
	 * Whether a URL's host may be called: an IP literal must be public, and a name must not resolve to any
	 * non-public address (a public-looking name that points at 169.254.169.254, 10.x or loopback is refused). A name
	 * that resolves to nothing is left to the transport, which cannot connect either. The check cannot stop DNS
	 * rebinding between this lookup and the connection; the destinations are few and owner-configured.
	 *
	 * @param string $url A URL that already passed url_allowed().
	 * @return bool
	 */
	public static function host_is_public( $url ) {
		$parts = parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( rtrim( $parts['host'], '.' ) );
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_public_ip( $host );
		}
		foreach ( self::resolve_host( $host ) as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Make a request through the WordPress safe HTTP API.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    URL.
	 * @param array  $args   headers (array), body (string|array), json (mixed, JSON-encoded),
	 *                       timeout (int, capped at TIMEOUT).
	 * @return array { ok: bool, status: int, body: string, error: string, retryable: bool }
	 */
	public static function request( $method, $url, array $args = array() ) {
		$method = strtoupper( (string) $method );
		$result = array(
			'ok'        => false,
			'status'    => 0,
			'body'      => '',
			'error'     => '',
			'retryable' => false,
		);
		if ( ! in_array( $method, self::METHODS, true ) ) {
			$result['error'] = 'method_not_allowed';
			return $result;
		}
		if ( ! self::url_allowed( $url ) ) {
			$result['error'] = 'url_not_allowed';
			self::log( 'http_refused', array( 'method' => $method, 'url' => self::redact_url( $url ), 'error' => 'url_not_allowed' ) );
			return $result;
		}

		if ( ! self::host_is_public( $url ) ) {
			$result['error']     = 'host_not_public';
			$result['retryable'] = true; // Nothing was sent; a bad DNS answer may be transient, and the outbox caps the retries.
			self::log( 'http_refused', array( 'method' => $method, 'url' => self::redact_url( $url ), 'error' => 'host_not_public' ) );
			return $result;
		}

		$headers = array();
		$given   = ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) ? $args['headers'] : array();
		foreach ( $given as $name => $value ) {
			// A header name must be a plain token and a value a scalar without line breaks (no header injection).
			if ( ! is_string( $name ) || 1 !== preg_match( '/^[A-Za-z0-9-]{1,64}$/D', $name ) || ! is_scalar( $value ) ) {
				$result['error'] = 'header_not_allowed';
				self::log( 'http_refused', array( 'method' => $method, 'url' => self::redact_url( $url ), 'error' => 'header_not_allowed' ) );
				return $result;
			}
			$headers[ $name ] = str_replace( array( "\r", "\n", "\0" ), '', (string) $value );
		}
		$body    = isset( $args['body'] ) ? $args['body'] : null;
		if ( array_key_exists( 'json', $args ) ) {
			$encoded = wp_json_encode( $args['json'] );
			if ( ! is_string( $encoded ) ) {
				$result['error'] = 'json_encode_failed';
				return $result;
			}
			$body                   = $encoded;
			$headers['Content-Type'] = 'application/json';
		}
		$timeout = isset( $args['timeout'] ) ? (int) $args['timeout'] : self::TIMEOUT;
		$timeout = max( 1, min( self::TIMEOUT, $timeout ) );

		$request = array(
			'method'              => $method,
			'timeout'             => $timeout,
			'redirection'         => 0,
			'sslverify'           => true,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => self::MAX_BODY_BYTES,
			'user-agent'          => 'DoughBoss-Growth/' . ( defined( 'DOUGHBOSS_GROWTH_VERSION' ) ? DOUGHBOSS_GROWTH_VERSION : '0' ),
			'headers'             => $headers,
		);
		if ( null !== $body ) {
			$request['body'] = $body;
		}

		$response = wp_safe_remote_request( $url, $request );
		if ( is_wp_error( $response ) ) {
			$result['error']     = 'transport_error';
			$result['retryable'] = true;
			self::log( 'http', array( 'method' => $method, 'url' => self::redact_url( $url ), 'error' => 'transport_error' ) );
			return $result;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$text   = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $text ) > self::MAX_BODY_BYTES ) {
			$text = substr( $text, 0, self::MAX_BODY_BYTES );
		}
		$result['status']    = $status;
		$result['body']      = $text;
		$result['ok']        = ( $status >= 200 && $status < 300 );
		$result['retryable'] = ( 0 === $status || 408 === $status || 429 === $status || $status >= 500 );
		$result['error']     = $result['ok'] ? '' : 'http_' . $status;

		self::log( 'http', array( 'method' => $method, 'url' => self::redact_url( $url ), 'status' => $status ) );
		return $result;
	}

	/**
	 * Redact a URL for logging: no credentials, no query, no fragment, token-like path segments hidden.
	 *
	 * @param mixed $url URL.
	 * @return string
	 */
	public static function redact_url( $url ) {
		if ( ! is_string( $url ) ) {
			return '[invalid-url]';
		}
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '[invalid-url]';
		}
		$scheme   = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		$segments = array();
		if ( isset( $parts['path'] ) && '' !== $parts['path'] ) {
			foreach ( explode( '/', trim( $parts['path'], '/' ) ) as $segment ) {
				$segments[] = strlen( $segment ) >= 16 ? '[redacted]' : $segment;
			}
		}
		return $scheme . '://' . strtolower( $parts['host'] ) . '/' . implode( '/', $segments );
	}

	/**
	 * Redact request or response headers.
	 *
	 * @param array $headers Header name => value.
	 * @return array
	 */
	public static function redact_headers( array $headers ) {
		$out = array();
		foreach ( $headers as $name => $value ) {
			$lower = strtolower( (string) $name );
			if ( 1 === preg_match( '/^(authorization|proxy-authorization|cookie|set-cookie)$/D', $lower )
				|| 1 === preg_match( '/(signature|secret|token|key|auth|password)/', $lower ) ) {
				$out[ $name ] = '[redacted]';
			} else {
				$out[ $name ] = is_scalar( $value ) ? self::redact_text( (string) $value ) : '[complex]';
			}
		}
		return $out;
	}

	/**
	 * Redact a body: JSON bodies are masked by key, anything else by pattern.
	 *
	 * @param mixed $body Body.
	 * @return string
	 */
	public static function redact_body( $body ) {
		if ( is_array( $body ) ) {
			return self::redact_text( (string) wp_json_encode( self::redact_value( $body ) ) );
		}
		$body = (string) $body;
		$data = json_decode( $body, true );
		if ( is_array( $data ) ) {
			return self::redact_text( (string) wp_json_encode( self::redact_value( $data ) ) );
		}
		return self::redact_text( $body );
	}

	/**
	 * Redact free text: bearer tokens, secret-looking query parameters, JSON secrets and personal
	 * data (emails, phone-like numbers, IPv4 addresses) and long opaque strings.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function redact_text( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [redacted]', $text );
		$text = preg_replace( '/\b(authorization|api[_-]?secret|api[_-]?key|apikey|access[_-]?token|token|secret|password|passwd|signature|sig|auth)=[^&\s"\']+/i', '$1=[redacted]', $text );
		$text = preg_replace( '/"(authorization|api[_-]?secret|api[_-]?key|access[_-]?token|token|secret|password|signature|email|phone|mobile|first_name|last_name|full_name|customer_name|address|ip|ip_address|user_agent)"\s*:\s*"[^"]*"/i', '"$1":"[redacted]"', $text );
		$text = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text );
		$text = preg_replace( '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[ip]', $text );
		$text = preg_replace( '/\+?\d[\d ()-]{7,}\d/', '[number]', $text );
		$text = preg_replace( '/[A-Za-z0-9_-]{32,}/', '[token]', $text );
		return $text;
	}

	/**
	 * Write a redacted log line. Fires doughboss_growth_log with the line, writes to error_log only when WP_DEBUG_LOG
	 * is on, and, when the event is a failure (see DoughBoss_Growth_Failures::code_for_event()), notes it in the failure
	 * list the owner sees on the Growth settings screen. Without that third sink a default production site would lose
	 * every failure. Values are scalars, redacted and truncated; bodies are never logged.
	 *
	 * @param string $event   Short event name.
	 * @param array  $context Scalar context.
	 * @return string The redacted line.
	 */
	public static function log( $event, array $context = array() ) {
		$safe = array();
		foreach ( $context as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$text = self::redact_text( (string) $value );
			if ( strlen( $text ) > 200 ) {
				$text = substr( $text, 0, 200 ) . '...';
			}
			$safe[ preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $key ) ) ] = $text;
		}
		$line = 'DoughBoss Growth [' . preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $event ) ) . '] ' . wp_json_encode( $safe, JSON_UNESCAPED_SLASHES );
		if ( class_exists( 'DoughBoss_Growth_Failures', false ) ) {
			DoughBoss_Growth_Failures::from_log( (string) $event, $safe ); // Never throws; writes only when this event is a failure.
		}
		do_action( 'doughboss_growth_log', $line, $event, $safe );
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- redacted line, opt-in via WP_DEBUG_LOG.
		}
		return $line;
	}

	/**
	 * Recursively mask sensitive keys.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function redact_value( $value ) {
		if ( ! is_array( $value ) ) {
			return is_string( $value ) ? self::redact_text( $value ) : $value;
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && 1 === preg_match( '/^(authorization|api[_-]?secret|api[_-]?key|access[_-]?token|token|secret|password|signature|email|phone|mobile|first_name|last_name|full_name|customer_name|address|ip|ip_address|user_agent|em|ph|fn|ln)$/Di', $key ) ) {
				$out[ $key ] = '[redacted]';
			} else {
				$out[ $key ] = self::redact_value( $item );
			}
		}
		return $out;
	}
}
