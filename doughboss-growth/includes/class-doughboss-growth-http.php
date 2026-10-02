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
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		if ( false === strpos( $host, '.' ) || 1 !== preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host ) ) {
			return false;
		}
		if ( 1 === preg_match( '/(^|\.)(localhost|local|internal|lan|home|corp|test|invalid|example)$/', $host ) ) {
			return false;
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

		$headers = ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) ? $args['headers'] : array();
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
			if ( 1 === preg_match( '/^(authorization|proxy-authorization|cookie|set-cookie)$/', $lower )
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
	 * Write a redacted log line. Fires doughboss_growth_log with the line and writes to error_log
	 * only when WP_DEBUG_LOG is on. Values are scalars, redacted and truncated; bodies are never logged.
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
		$line = 'DoughBoss Growth [' . preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $event ) ) . '] ' . wp_json_encode( $safe );
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
			if ( is_string( $key ) && 1 === preg_match( '/^(authorization|api[_-]?secret|api[_-]?key|access[_-]?token|token|secret|password|signature|email|phone|mobile|first_name|last_name|full_name|customer_name|address|ip|ip_address|user_agent|em|ph|fn|ln)$/i', $key ) ) {
				$out[ $key ] = '[redacted]';
			} else {
				$out[ $key ] = self::redact_value( $item );
			}
		}
		return $out;
	}
}
