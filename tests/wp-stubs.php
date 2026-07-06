<?php
/**
 * Minimal WordPress kernel stub for the DoughBoss boot smoke test.
 *
 * This is NOT a WordPress test suite. It defines just enough of the WP runtime
 * (hooks, options, REST/route registration, CPT/shortcode registration, escaping
 * and sanitisation helpers) for the plugin to load and boot without fatals, so we
 * can prove every domain's classes wire up. Hooks and routes are *recorded* so the
 * smoke test can fire them and assert the plugin registered what it should.
 *
 * @package DoughBoss\Tests
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );

/** Recorded runtime state the smoke test inspects. */
$GLOBALS['__db_hooks']      = array();
$GLOBALS['__db_rest']       = array();
$GLOBALS['__db_shortcodes'] = array();
$GLOBALS['__db_posttypes']  = array();
$GLOBALS['__db_options']    = array();

/* ---- Hooks ---- */
function add_action( $hook, $cb = null, $prio = 10, $args = 1 ) { $GLOBALS['__db_hooks'][ $hook ][] = $cb; return true; }
function add_filter( $hook, $cb = null, $prio = 10, $args = 1 ) { $GLOBALS['__db_hooks'][ $hook ][] = $cb; return true; }
function do_action( $hook, ...$a ) { foreach ( $GLOBALS['__db_hooks'][ $hook ] ?? array() as $cb ) { if ( is_callable( $cb ) ) { call_user_func_array( $cb, $a ); } } }
function apply_filters( $hook, $value = null, ...$a ) { return $value; }
function remove_action( ...$a ) { return true; }
function remove_filter( ...$a ) { return true; }
function did_action( $h ) { return 0; }
function register_activation_hook( $f, $cb ) {}
function register_deactivation_hook( $f, $cb ) {}
function register_uninstall_hook( $f, $cb ) {}

/* ---- Plugin path helpers ---- */
function plugin_dir_path( $f ) { return rtrim( dirname( $f ), '/' ) . '/'; }
function plugin_dir_url( $f ) { return 'http://example.test/wp-content/plugins/doughboss/'; }
function plugin_basename( $f ) { return 'doughboss/' . basename( $f ); }
function plugins_url( $p = '', $f = '' ) { return 'http://example.test/wp-content/plugins/doughboss/' . ltrim( $p, '/' ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }

/* ---- Options / transients ---- */
function get_option( $k, $d = false ) { return $GLOBALS['__db_options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__db_options'][ $k ] = $v; return true; }
function add_option( $k, $v = '', $x = '', $a = null ) { $GLOBALS['__db_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__db_options'][ $k ] ); return true; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function delete_transient( $k ) { return true; }
function wp_using_ext_object_cache() { return false; }

/* ---- REST ---- */
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST, PUT, PATCH'; const DELETABLE = 'DELETE'; const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE'; }
function register_rest_route( $ns, $route, $args = array(), $override = false ) { $GLOBALS['__db_rest'][] = rtrim( $ns, '/' ) . '/' . ltrim( $route, '/' ); return true; }
function rest_url( $p = '' ) { return 'http://example.test/wp-json/' . ltrim( $p, '/' ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error { public $errors = array(); public function __construct( $c = '', $m = '', $d = null ) { if ( $c ) { $this->errors[ $c ][] = $m; } } public function get_error_message() { return ''; } public function get_error_code() { return ''; } }
class WP_REST_Response { public $data; public $status; public function __construct( $d = null, $s = 200 ) { $this->data = $d; $this->status = $s; } public function set_status( $s ) { $this->status = $s; } }
class WP_REST_Request implements ArrayAccess {
	private $p = array();
	public function __construct( $p = array() ) { $this->p = $p; }
	public function get_param( $k ) { return $this->p[ $k ] ?? null; }
	public function get_params() { return $this->p; }
	public function get_json_params() { return $this->p; }
	public function get_header( $k ) { return ''; }
	// No return types + E_DEPRECATED suppressed → works on PHP 7.4 and 8.x alike.
	public function offsetExists( $o ) { return isset( $this->p[ $o ] ); }
	public function offsetGet( $o ) { return $this->p[ $o ] ?? null; }
	public function offsetSet( $o, $v ) { $this->p[ $o ] = $v; }
	public function offsetUnset( $o ) { unset( $this->p[ $o ] ); }
}

/* ---- CPT / taxonomy / shortcodes ---- */
function register_post_type( $t, $a = array() ) { $GLOBALS['__db_posttypes'][] = $t; return (object) array( 'name' => $t ); }
function register_taxonomy( $t, $o = null, $a = array() ) { return true; }
function register_post_meta( $t, $k, $a = array() ) { return true; }
function register_meta( $t, $k, $a = array() ) { return true; }
function register_rest_field( $t, $a, $x = array() ) { return true; }
function get_post_meta( $id, $k = '', $s = false ) { return $s ? '' : array(); }
function update_post_meta( $id, $k, $v, $p = '' ) { return true; }
function add_post_meta( $id, $k, $v, $u = false ) { return 1; }
function delete_post_meta( $id, $k, $v = '' ) { return true; }
function get_the_title( $id = 0 ) { return 'Item'; }
function get_post_status( $id = 0 ) { return 'publish'; }
function wp_insert_post( $a, $e = false ) { return 1; }
function wp_update_post( $a, $e = false ) { return 1; }
function wp_delete_post( $id, $f = false ) { return true; }
function get_terms( $a = array() ) { return array(); }
function wp_set_object_terms( $id, $t, $tax, $ap = false ) { return array(); }
function get_the_terms( $id, $tax ) { return array(); }
function has_post_thumbnail( $id = 0 ) { return false; }
function get_the_post_thumbnail_url( $id = 0, $s = 'post-thumbnail' ) { return ''; }
function add_meta_box( ...$x ) {}
function wp_nonce_field( ...$x ) { return ''; }
function post_type_exists( $t ) { return in_array( $t, $GLOBALS['__db_posttypes'], true ); }
function taxonomy_exists( $t ) { return true; }
function flush_rewrite_rules( $h = true ) {}
function add_shortcode( $tag, $cb ) { $GLOBALS['__db_shortcodes'][ $tag ] = $cb; }
function shortcode_atts( $defaults, $atts, $sc = '' ) { return array_merge( $defaults, (array) $atts ); }

/* ---- Capabilities / users ---- */
function current_user_can( $c ) { return true; }
function is_user_logged_in() { return false; }
function wp_get_current_user() { return (object) array( 'ID' => 0, 'roles' => array() ); }
function get_current_user_id() { return 0; }
function add_role( $r, $d, $c = array() ) { return null; }
function remove_role( $r ) {}
function get_role( $r ) { return null; }
function wp_verify_nonce( $n, $a = -1 ) { return 1; }
function wp_create_nonce( $a = -1 ) { return 'nonce'; }
function check_ajax_referer( ...$x ) { return true; }

/* ---- i18n ---- */
function __( $t, $d = 'default' ) { return $t; }
function _e( $t, $d = 'default' ) { echo $t; }
function esc_html__( $t, $d = 'default' ) { return $t; }
function esc_attr__( $t, $d = 'default' ) { return $t; }
function esc_html_e( $t, $d = 'default' ) { echo $t; }
function _n( $s, $p, $n, $d = 'default' ) { return $n == 1 ? $s : $p; }
function _x( $t, $c, $d = 'default' ) { return $t; }
function load_plugin_textdomain( ...$x ) { return true; }

/* ---- Escaping / sanitising ---- */
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $t ) { return (string) $t; }
function esc_url_raw( $t ) { return (string) $t; }
function esc_js( $t ) { return (string) $t; }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function wp_kses_post( $t ) { return (string) $t; }
function wp_kses( $t, $a = array(), $p = array() ) { return (string) $t; }
function sanitize_text_field( $t ) { return is_string( $t ) ? trim( $t ) : $t; }
function sanitize_textarea_field( $t ) { return is_string( $t ) ? trim( $t ) : $t; }
function sanitize_email( $t ) { return $t; }
function sanitize_key( $t ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $t ) ); }
function sanitize_title( $t ) { return preg_replace( '/[^a-z0-9\-]/', '-', strtolower( (string) $t ) ); }
function sanitize_html_class( $t ) { return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $t ); }
function sanitize_file_name( $t ) { return preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $t ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_unslash( $v ) { return $v; }
function wp_slash( $v ) { return $v; }
function stripslashes_deep( $v ) { return $v; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); }
function sanitize_hex_color( $c ) { return $c; }

/* ---- Misc runtime ---- */
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . ltrim( $p, '/' ); }
function home_url( $p = '' ) { return 'http://example.test/' . ltrim( $p, '/' ); }
function site_url( $p = '' ) { return 'http://example.test/' . ltrim( $p, '/' ); }
function get_bloginfo( $k = '' ) { return 'DoughBoss Test'; }
function wp_json_encode( $d, $o = 0, $depth = 512 ) { return json_encode( $d, $o, $depth ); }
function wp_parse_args( $a, $d = array() ) { return array_merge( (array) $d, (array) $a ); }
function wp_generate_password( $len = 12, $s = true, $x = false ) { return substr( str_repeat( 'a1B2c3D4', 8 ), 0, $len ); }
function wp_rand( $min = 0, $max = 0 ) { return $min; }
function wp_hash( $d, $s = 'auth' ) { return md5( (string) $d ); }
function current_time( $type = 'mysql', $gmt = 0 ) { return $type === 'timestamp' ? 1750000000 : '2026-07-06 00:00:00'; }
function wp_timezone_string() { return 'Australia/Sydney'; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function wp_next_scheduled( ...$x ) { return false; }
function wp_schedule_event( ...$x ) { return true; }
function wp_clear_scheduled_hook( ...$x ) { return true; }
function wp_remote_post( $url, $args = array() ) { return array( 'response' => array( 'code' => 200 ), 'body' => '{}' ); }
function wp_remote_get( $url, $args = array() ) { return array( 'response' => array( 'code' => 200 ), 'body' => '{}' ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
function wp_safe_redirect( $l, $s = 302 ) { return true; }
function wp_redirect( $l, $s = 302 ) { return true; }
function get_permalink( $id = 0 ) { return 'http://example.test/?p=' . $id; }
function get_post( $id = 0 ) { return null; }
function get_posts( $a = array() ) { return array(); }
function wp_enqueue_script( ...$x ) {}
function wp_enqueue_style( ...$x ) {}
function wp_register_script( ...$x ) {}
function wp_register_style( ...$x ) {}
function wp_localize_script( ...$x ) { return true; }
function wp_add_inline_script( ...$x ) { return true; }
function wp_create_nonce_field( ...$x ) { return ''; }
function selected( $a, $b = true, $e = true ) { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $e ) { echo $r; } return $r; }
function checked( $a, $b = true, $e = true ) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ( $e ) { echo $r; } return $r; }
function add_menu_page( ...$x ) { return ''; }
function add_submenu_page( ...$x ) { return ''; }
function add_settings_section( ...$x ) {}
function add_settings_field( ...$x ) {}
function register_setting( ...$x ) {}
function settings_fields( ...$x ) {}
function do_settings_sections( ...$x ) {}
function submit_button( ...$x ) {}
function wp_die( $m = '' ) { throw new RuntimeException( 'wp_die: ' . ( is_string( $m ) ? $m : 'error' ) ); }
function get_transient_timeout( $k ) { return 0; }
function maybe_serialize( $d ) { return is_array( $d ) || is_object( $d ) ? serialize( $d ) : $d; }
function maybe_unserialize( $d ) { return is_serialized( $d ) ? @unserialize( $d ) : $d; }
function is_serialized( $d, $s = true ) { return is_string( $d ) && preg_match( '/^[aOs]:\d+:/', $d ) === 1; }

/* ---- $wpdb ---- */
class DB_Stub {
	public $prefix = 'wp_';
	public $insert_id = 1;
	public $last_error = '';
	public function get_charset_collate() { return ''; }
	public function prepare( $q, ...$a ) { return $q; }
	public function query( $q ) { return 0; }
	public function get_var( $q = null ) { return null; }
	public function get_row( $q = null, $o = OBJECT, $y = 0 ) { return null; }
	public function get_col( $q = null ) { return array(); }
	public function get_results( $q = null, $o = OBJECT ) { return array(); }
	public function insert( $t, $d, $f = null ) { return 1; }
	public function update( $t, $d, $w, $f = null, $wf = null ) { return 1; }
	public function delete( $t, $w, $wf = null ) { return 1; }
	public function __get( $name ) { return $this->prefix . $name; }
}
$GLOBALS['wpdb'] = new DB_Stub();

function dbDelta( $q ) { return array(); }
