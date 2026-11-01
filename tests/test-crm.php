<?php
/** Focused CRM read-only regression tests. */
require_once dirname( __DIR__ ) . '/includes/class-doughboss-catering.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-crm.php';

if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) { return in_array( $capability, isset( $GLOBALS['db_crm_caps'] ) ? $GLOBALS['db_crm_caps'] : array(), true ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( $value ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $value, $domain = 'default' ) { echo esc_html( $value ); }
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $value, $domain = 'default' ) { echo esc_attr( $value ); }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { return 'https://doughboss.test/wp-admin/' . $path; }
}

/** Minimal query recorder for the real catering query. No writes or network. */
class DoughBoss_Test_CRM_DB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $error = '';
	public $fail_count = false;
	public $fail_list = false;
	public $reads = array();
	public $prepared = array();
	public $rows = array();
	public $total = 0;
	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	public function prepare( $sql, $args = array() ) {
		$this->prepared[] = array( $sql, $args );
		return $sql;
	}
	public function get_var( $sql ) { $this->reads[] = $sql; $this->last_error = $this->fail_count ? 'count SQL secret' : $this->error; return $this->total; }
	public function get_results( $sql, $format = null ) { $this->reads[] = $sql; $this->last_error = $this->fail_list ? 'list SQL secret' : $this->error; return $this->rows; }
}

/** Keep the global database and capabilities isolated from other suites. */
function db_crm_with_fixture( $callback ) {
	$had_db = array_key_exists( 'wpdb', $GLOBALS );
	$old_db = $had_db ? $GLOBALS['wpdb'] : null;
	$had_caps = array_key_exists( 'db_crm_caps', $GLOBALS );
	$old_caps = $had_caps ? $GLOBALS['db_crm_caps'] : null;
	$db = new DoughBoss_Test_CRM_DB();
	$GLOBALS['wpdb'] = $db;
	$GLOBALS['db_crm_caps'] = array( 'manage_doughboss' );
	try { $callback( $db ); } finally {
		if ( $had_db ) { $GLOBALS['wpdb'] = $old_db; } else { unset( $GLOBALS['wpdb'] ); }
		if ( $had_caps ) { $GLOBALS['db_crm_caps'] = $old_caps; } else { unset( $GLOBALS['db_crm_caps'] ); }
	}
}
function db_crm_error_status( $result, $status, $label ) {
	assert_true( is_wp_error( $result ), $label . ' returns error' );
	if ( is_wp_error( $result ) ) { assert_same( $status, $result->get_error_data()['status'], $label . ' status' ); }
}

db_test( 'CRM enforces each supported capability before reads', function () {
	db_crm_with_fixture( function ( $db ) {
		foreach ( array( array(), array( 'read' ) ) as $caps ) {
			$GLOBALS['db_crm_caps'] = $caps;
			assert_false( DoughBoss_CRM::can_access(), 'unprivileged user denied' );
			db_crm_error_status( DoughBoss_CRM::inbox(), 403, 'unprivileged inbox' );
			ob_start();
			DoughBoss_CRM::render();
			$result = ob_get_clean();
			assert_false( false !== strpos( $result, 'db-crm-card' ), 'unprivileged render does not disclose cards' );
			assert_same( 0, count( $db->reads ), 'denial performs no database read' );
		}
		foreach ( array( 'manage_doughboss', 'manage_options' ) as $cap ) {
			$GLOBALS['db_crm_caps'] = array( $cap );
			assert_true( DoughBoss_CRM::can_access(), $cap . ' grants access' );
			assert_false( is_wp_error( DoughBoss_CRM::inbox() ), $cap . ' can read inbox' );
		}
	} );
} );

db_test( 'CRM rejects invalid filters before database reads', function () {
	db_crm_with_fixture( function ( $db ) {
		$invalid = array(
			array( 'crm_status' => null ), array( 'crm_status' => array( 'new' ) ), array( 'crm_status' => 'NEW' ), array( 'crm_status' => 'unknown' ),
			array( 'crm_search' => array( 'x' ) ), array( 'crm_search' => null ), array( 'crm_search' => str_repeat( 'x', 201 ) ),
			array( 'crm_page' => null ), array( 'crm_page' => array( 1 ) ), array( 'crm_page' => 0 ), array( 'crm_page' => -1 ), array( 'crm_page' => '01' ),
			array( 'crm_page' => '1e2' ), array( 'crm_page' => '10001' ), array( 'crm_page' => str_repeat( '9', 80 ) ), array( 'crm_page' => true ),
		);
		foreach ( $invalid as $request ) {
			$db->reads = array();
			db_crm_error_status( DoughBoss_CRM::inbox( $request ), 400, 'invalid ' . key( $request ) );
			assert_same( 0, count( $db->reads ), 'invalid filter performs no read' );
		}
	} );
} );

db_test( 'CRM uses canonical catering query and bounded pagination', function () {
	db_crm_with_fixture( function ( $db ) {
		$db->rows = array( array( 'enquiry_number' => 'ENQ-123', 'customer_name' => 'Test' ) );
		$db->total = 41;
		$view = DoughBoss_CRM::inbox( array( 'crm_status' => 'new', 'crm_search' => "O\\'Brien %_", 'crm_page' => '2' ) );
		assert_false( is_wp_error( $view ), 'valid request accepted' );
		if ( is_wp_error( $view ) ) { return; }
		assert_same( array( 'status' => 'new', 'search' => "O'Brien %_", 'page' => 2 ), $view['filters'], 'filters are unslashed and normalised' );
		assert_same( $db->rows, $view['items'], 'canonical rows returned' );
		assert_same( 41, $view['total'], 'total preserved' );
		assert_same( 3, $view['total_pages'], 'total pages rounded up' );
		assert_same( 2, count( $db->reads ), 'one count and one list read' );
		$params = $db->prepared[ count( $db->prepared ) - 1 ][1];
		assert_same( array( 'new', "%O'Brien \\%\\_%", "%O'Brien \\%\\_%", "%O'Brien \\%\\_%", 20, 20 ), $params, 'search is escaped and bound with fixed page size and offset' );
		$attack = "' OR 1=1 -- %_";
		DoughBoss_CRM::inbox( array( 'crm_search' => $attack ) );
		$bound = $db->prepared[ count( $db->prepared ) - 1 ];
		assert_false( false !== strpos( $bound[0], $attack ), 'injection text is not interpolated into SQL' );
		assert_same( '%' . $db->esc_like( $attack ) . '%', $bound[1][0], 'injection text is passed as an escaped search parameter' );
		foreach ( array_keys( DoughBoss_Catering::statuses() ) as $status ) {
			assert_false( is_wp_error( DoughBoss_CRM::inbox( array( 'crm_status' => $status ) ) ), 'known status accepted: ' . $status );
		}
		assert_false( is_wp_error( DoughBoss_CRM::inbox( array( 'crm_page' => '10000', 'crm_search' => str_repeat( 'x', 200 ) ) ) ), 'upper valid bounds accepted' );
	} );
} );

db_test( 'CRM distinguishes an empty inbox from a database failure', function () {
	db_crm_with_fixture( function ( $db ) {
		$empty = DoughBoss_CRM::inbox();
		assert_same( array(), $empty['items'], 'empty inbox has no rows' );
		assert_same( 0, $empty['total'], 'empty inbox has zero total' );
		foreach ( array( 'fail_count', 'fail_list' ) as $failure ) {
			$db->reads = array();
			$db->$failure = true;
			$error = DoughBoss_CRM::inbox();
			db_crm_error_status( $error, 500, $failure );
			if ( is_wp_error( $error ) ) { assert_false( false !== strpos( $error->get_error_message(), 'SQL secret' ), 'database details are not exposed' ); }
			assert_same( 'fail_count' === $failure ? 1 : 2, count( $db->reads ), 'failed count stops before list read' );
			$db->$failure = false;
		}
	} );
} );

db_test( 'CRM escapes customer content and links to the existing enquiry search', function () {
	db_crm_with_fixture( function ( $db ) {
		$db->rows = array( array( 'enquiry_number' => 'ENQ-123', 'customer_name' => '<script>alert(1)</script>', 'customer_email' => 'a@example.test', 'status' => 'new', 'event_date' => '2026-12-01', 'guest_count' => 10, 'created_at' => '2026-10-30 10:00:00' ) );
		$db->total = 1;
		ob_start();
		DoughBoss_CRM::render();
		$html = ob_get_clean();
		assert_false( false !== strpos( $html, '<script>alert(1)</script>' ), 'customer markup is escaped' );
		assert_true( false !== strpos( $html, '&lt;script&gt;alert(1)&lt;/script&gt;' ), 'customer name remains visible as text' );
		assert_true( false !== strpos( $html, 'admin.php?page=doughboss-catering' ), 'existing catering admin page linked' );
		assert_true( false !== strpos( $html, 's=ENQ-123' ), 'link searches by enquiry number' );
		assert_true( false !== strpos( $html, 'method="get"' ) || false !== strpos( $html, "method='get'" ), 'filter form uses GET' );
	} );
} );
