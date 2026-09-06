<?php
/**
 * Minimal WordPress shim so the pure-logic classes (settings, cart, order
 * numbering, REST sanitisers) can be exercised without a WordPress install
 * or a database. Only the functions those classes touch are defined, and
 * every store is in-memory.
 *
 * Run: php tests/run.php
 *
 * @package DoughBoss
 */

declare( strict_types=0 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DOUGHBOSS_REST_NAMESPACE', 'doughboss/v1' );

$GLOBALS['db_test_options']    = array();
$GLOBALS['db_test_transients'] = array();
$GLOBALS['db_test_timezone']   = 'Australia/Sydney';
$GLOBALS['db_test_actions']    = array();

/**
 * Reset every in-memory store between tests.
 *
 * @return void
 */
function db_test_reset() {
	$GLOBALS['db_test_options']    = array();
	$GLOBALS['db_test_transients'] = array();
	$GLOBALS['db_test_actions']    = array();
	$_COOKIE                       = array();
	DoughBoss_Settings::flush();
}

// phpcs:disable -- test doubles.
class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function __( $text, $domain = 'default' ) {
	return $text;
}
function _x( $text, $context, $domain = 'default' ) {
	return $text;
}
function esc_html__( $text, $domain = 'default' ) {
	return $text;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['db_test_actions'][ $hook ][] = $callback;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {}
function do_action( $hook, ...$args ) {
	if ( empty( $GLOBALS['db_test_actions'][ $hook ] ) ) {
		return;
	}
	foreach ( $GLOBALS['db_test_actions'][ $hook ] as $cb ) {
		call_user_func_array( $cb, $args );
	}
}
function apply_filters( $hook, $value, ...$args ) {
	return $value;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['db_test_options'] ) ? $GLOBALS['db_test_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['db_test_options'][ $name ] = $value;
	do_action( 'update_option_' . $name, null, $value, $name );
	return true;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, $GLOBALS['db_test_options'] ) ) {
		return false;
	}
	$GLOBALS['db_test_options'][ $name ] = $value;
	do_action( 'add_option_' . $name, $name, $value );
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['db_test_options'][ $name ] );
	return true;
}

function get_transient( $key ) {
	if ( ! isset( $GLOBALS['db_test_transients'][ $key ] ) ) {
		return false;
	}
	list( $value, $expires ) = $GLOBALS['db_test_transients'][ $key ];
	if ( $expires && $expires < time() ) {
		unset( $GLOBALS['db_test_transients'][ $key ] );
		return false;
	}
	return $value;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['db_test_transients'][ $key ] = array( $value, $ttl ? time() + $ttl : 0 );
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['db_test_transients'][ $key ] );
	return true;
}

function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) {
		$args = get_object_vars( $args );
	} elseif ( ! is_array( $args ) ) {
		parse_str( (string) $args, $args );
	}
	return array_merge( $defaults, $args );
}
function wp_list_pluck( $list, $field ) {
	$out = array();
	foreach ( (array) $list as $k => $row ) {
		if ( is_array( $row ) && isset( $row[ $field ] ) ) {
			$out[ $k ] = $row[ $field ];
		} elseif ( is_object( $row ) && isset( $row->$field ) ) {
			$out[ $k ] = $row->$field;
		}
	}
	return $out;
}
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function wp_rand( $min = 0, $max = 0 ) {
	return random_int( (int) $min, (int) $max );
}
function wp_date( $format, $timestamp = null ) {
	$tz = new DateTimeZone( $GLOBALS['db_test_timezone'] );
	$dt = new DateTime( 'now', $tz );
	if ( null !== $timestamp ) {
		$dt->setTimestamp( (int) $timestamp );
	}
	return $dt->format( $format );
}
function current_time( $type, $gmt = 0 ) {
	if ( 'mysql' === $type ) {
		return $gmt ? gmdate( 'Y-m-d H:i:s' ) : wp_date( 'Y-m-d H:i:s' );
	}
	if ( 'timestamp' === $type ) {
		return time();
	}
	return wp_date( $type );
}
function is_ssl() {
	return false;
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $str ) {
	return trim( strip_tags( (string) $str ) );
}
function sanitize_textarea_field( $str ) {
	return trim( strip_tags( (string) $str ) );
}
function sanitize_email( $email ) {
	$email = (string) $email;
	$email = preg_replace( '/[^a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~\.\-@\[\]]/', '', $email );
	return is_email( $email ) ? $email : '';
}
function is_email( $email ) {
	return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
}
function absint( $n ) {
	return abs( (int) $n );
}
function wp_cache_get( $key, $group = '' ) {
	return false;
}
function wp_cache_set( $key, $data, $group = '', $ttl = 0 ) {
	return true;
}
function wp_cache_add( $key, $data, $group = '', $ttl = 0 ) {
	return true;
}
function wp_cache_delete( $key, $group = '' ) {
	return true;
}
function register_rest_route( ...$args ) {}
function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( $path, '/' );
}
function esc_url_raw( $url ) {
	return $url;
}
function wp_create_nonce( $action ) {
	return 'testnonce';
}
function current_user_can( $cap ) {
	return false;
}
// phpcs:enable

// Cart::set_cookie() checks headers_sent() before calling setcookie(); in the
// CLI nothing has been output yet, so the cookie path is reached. Emit a byte
// so headers_sent() is true and the CLI never tries to send a cookie header.
echo '';


$root = dirname( __DIR__ );
require_once $root . '/includes/class-doughboss-settings.php';
require_once $root . '/includes/class-doughboss-cart.php';
require_once $root . '/includes/class-doughboss-order.php';
require_once $root . '/includes/class-doughboss-rest-controller.php';
