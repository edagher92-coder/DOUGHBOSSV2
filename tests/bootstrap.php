<?php
/**
 * Test bootstrap — a minimal WordPress-function shim.
 *
 * There is no WordPress and no MySQL in the test environment, so this file
 * provides just enough of the WordPress surface for the pure, side-effect-free
 * parts of the plugin to be exercised directly: an in-memory option store, the
 * sanitisers the settings/gateway classes call, and WP_Error.
 *
 * Anything that would touch the database, the network or a real WordPress
 * runtime is deliberately NOT shimmed — a test that needed it would be testing
 * the shim rather than the plugin. Those paths are called out as unverified in
 * the accompanying report instead of being faked.
 *
 * Run with: php tests/run.php
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'DOUGHBOSS_REST_NAMESPACE' ) ) {
	define( 'DOUGHBOSS_REST_NAMESPACE', 'doughboss/v1' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/* -------------------------------------------------------------------------- */
/* In-memory option store                                                      */
/* -------------------------------------------------------------------------- */

$GLOBALS['doughboss_test_options'] = array();

/**
 * Reset every stored option between tests.
 *
 * @return void
 */
function doughboss_test_reset_options() {
	$GLOBALS['doughboss_test_options'] = array();
}

/**
 * Replace the stored DoughBoss settings wholesale.
 *
 * @param array $settings Settings to store.
 * @return void
 */
function doughboss_test_set_settings( array $settings ) {
	$GLOBALS['doughboss_test_options']['doughboss_settings'] = $settings;
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $key     Option name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['doughboss_test_options'] )
			? $GLOBALS['doughboss_test_options'][ $key ]
			: $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $key      Option name.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Ignored.
	 * @return bool
	 */
	function update_option( $key, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['doughboss_test_options'][ $key ] = $value;
		return true;
	}
}

/* -------------------------------------------------------------------------- */
/* WordPress helpers                                                           */
/* -------------------------------------------------------------------------- */

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * @param array $args     Supplied args.
	 * @param array $defaults Defaults.
	 * @return array
	 */
	function wp_parse_args( $args, $defaults = array() ) {
		if ( ! is_array( $args ) ) {
			$args = array();
		}
		return array_merge( $defaults, $args );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Close enough to WordPress for these tests: strip tags, drop control
	 * characters, collapse whitespace, trim.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	function sanitize_text_field( $value ) {
		$value = (string) $value;
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[\r\n\t]+/', ' ', $value );
		$value = preg_replace( '/[\x00-\x1f\x7f]/', '', $value );
		return trim( preg_replace( '/ +/', ' ', $value ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param string $value Raw value.
	 * @return string
	 */
	function wp_strip_all_tags( $value ) {
		return strip_tags( (string) $value );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Raw key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $value Raw value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * @param string $url Raw URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * @param string $value Raw value.
	 * @return string
	 */
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '/' ) {
		return 'https://doughboss.test' . $path;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * @param string $path REST path.
	 * @return string
	 */
	function rest_url( $path = '' ) {
		return 'https://doughboss.test/wp-json/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/** Escape synthetic markup without loading WordPress or accessing storage. */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		unset( $hook );
		return $value;
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * @param string $email Email.
	 * @return string|false
	 */
	function is_email( $email ) {
		return filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? (string) $email : false;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * @param string $type Format type.
	 * @param bool   $gmt  GMT flag.
	 * @return string
	 */
	function current_time( $type = 'mysql', $gmt = false ) {
		unset( $type, $gmt );
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stand-in.
	 */
	class WP_Error {

		/**
		 * Error code.
		 *
		 * @var string
		 */
		private $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private $message;

		/**
		 * Error data.
		 *
		 * @var mixed
		 */
		private $data;

		/**
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Value.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

/* -------------------------------------------------------------------------- */
/* Plugin classes under test                                                   */
/* -------------------------------------------------------------------------- */

require_once dirname( __DIR__ ) . '/includes/class-doughboss-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-square.php';

/* -------------------------------------------------------------------------- */
/* Assertions                                                                  */
/* -------------------------------------------------------------------------- */

$GLOBALS['doughboss_test_results'] = array(
	'passed'   => 0,
	'failed'   => 0,
	'failures' => array(),
	'current'  => '',
);

/**
 * Declare and run one test case.
 *
 * @param string   $name Test name.
 * @param callable $body Test body.
 * @return void
 */
function db_test( $name, $body ) {
	$GLOBALS['doughboss_test_results']['current'] = $name;
	doughboss_test_reset_options();
	try {
		call_user_func( $body );
	} catch ( Exception $e ) {
		db_fail( 'threw ' . get_class( $e ) . ': ' . $e->getMessage() );
	} catch ( Error $e ) {
		db_fail( 'fatal ' . get_class( $e ) . ': ' . $e->getMessage() );
	}
	$GLOBALS['doughboss_test_results']['current'] = '';
}

/**
 * Record a failure against the current test.
 *
 * @param string $detail Failure detail.
 * @return void
 */
function db_fail( $detail ) {
	$GLOBALS['doughboss_test_results']['failed']++;
	$GLOBALS['doughboss_test_results']['failures'][] = $GLOBALS['doughboss_test_results']['current'] . ' — ' . $detail;
}

/**
 * Record a pass.
 *
 * @return void
 */
function db_pass() {
	$GLOBALS['doughboss_test_results']['passed']++;
}

/**
 * Assert strict equality.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Assertion label.
 * @return void
 */
function assert_same( $expected, $actual, $message ) {
	if ( $expected === $actual ) {
		db_pass();
		return;
	}
	db_fail( $message . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
}

/**
 * Assert a truthy value.
 *
 * @param mixed  $actual  Actual value.
 * @param string $message Assertion label.
 * @return void
 */
function assert_true( $actual, $message ) {
	assert_same( true, (bool) $actual, $message );
}

/**
 * Assert a falsy value.
 *
 * @param mixed  $actual  Actual value.
 * @param string $message Assertion label.
 * @return void
 */
function assert_false( $actual, $message ) {
	assert_same( false, (bool) $actual, $message );
}
