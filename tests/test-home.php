<?php
/** Focused Home read-only regression tests. */
require_once dirname( __DIR__ ) . '/includes/class-doughboss-catering.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-crm.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-home.php';

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) { return in_array( $capability, isset( $GLOBALS['db_home_caps'] ) ? $GLOBALS['db_home_caps'] : array(), true ); }
}
if ( ! class_exists( 'DoughBoss_Settings' ) ) {
	class DoughBoss_Settings {
		public static function get( $key, $default = null ) { return isset( $GLOBALS['db_home_settings'][ $key ] ) ? $GLOBALS['db_home_settings'][ $key ] : $default; }
	}
}

/** Minimal query recorder for the real catering query. No writes or network. */
class DoughBoss_Test_Home_DB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $error = '';
	public $fail_count = false;
	public $fail_list = false;
	public $reads = array();
	public $prepared = array();
	public $rows = array();
	public $total = 0;
	public $inserts = array();
	public $updates = array();
	public $deletes = array();
	public $replaces = array();
	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	public function prepare( $sql, $args = array() ) {
		$this->prepared[] = array( $sql, $args );
		return $sql;
	}
	public function get_var( $sql ) { $this->reads[] = $sql; $this->last_error = $this->fail_count ? 'count SQL secret' : $this->error; return $this->total; }
	public function get_results( $sql, $format = null ) { $this->reads[] = $sql; $this->last_error = $this->fail_list ? 'list SQL secret' : $this->error; return $this->rows; }
	public function insert( $table, $data ) { $this->inserts[] = $table; return 1; }
	public function update( $table, $data, $where ) { $this->updates[] = $table; return 1; }
	public function delete( $table, $where ) { $this->deletes[] = $table; return 1; }
	public function replace( $table, $data ) { $this->replaces[] = $table; return 1; }
	public function query( $sql ) { $this->reads[] = $sql; return 0; }
}

/** The text a person would read, without markup. */
function wp_strip_all_tags_for_home_test( $html ) {
	return html_entity_decode( strip_tags( $html ), ENT_QUOTES, 'UTF-8' );
}

/** Set the capabilities for whichever current_user_can() shim is live. */
function db_home_set_caps( array $caps ) {
	$GLOBALS['db_home_caps'] = $caps;
	$GLOBALS['db_crm_caps']  = $caps;
}

/** Keep the global database, capabilities and settings isolated from other suites. */
function db_home_with_fixture( $callback ) {
	$had_db = array_key_exists( 'wpdb', $GLOBALS );
	$old_db = $had_db ? $GLOBALS['wpdb'] : null;
	// test-crm.php loads first in tests/run.php, and whichever current_user_can() shim is defined first reads
	// its own global, so both capability globals are set and restored together.
	$had_caps = array_key_exists( 'db_home_caps', $GLOBALS );
	$old_caps = $had_caps ? $GLOBALS['db_home_caps'] : null;
	$had_crm_caps = array_key_exists( 'db_crm_caps', $GLOBALS );
	$old_crm_caps = $had_crm_caps ? $GLOBALS['db_crm_caps'] : null;
	$had_settings = array_key_exists( 'db_home_settings', $GLOBALS );
	$old_settings = $had_settings ? $GLOBALS['db_home_settings'] : null;
	$db = new DoughBoss_Test_Home_DB();
	$GLOBALS['wpdb'] = $db;
	db_home_set_caps( array( 'manage_doughboss' ) );
	$GLOBALS['db_home_settings'] = array( 'crm_home_enabled' => 1 );
	try { $callback( $db ); } finally {
		if ( $had_db ) { $GLOBALS['wpdb'] = $old_db; } else { unset( $GLOBALS['wpdb'] ); }
		if ( $had_caps ) { $GLOBALS['db_home_caps'] = $old_caps; } else { unset( $GLOBALS['db_home_caps'] ); }
		if ( $had_crm_caps ) { $GLOBALS['db_crm_caps'] = $old_crm_caps; } else { unset( $GLOBALS['db_crm_caps'] ); }
		if ( $had_settings ) { $GLOBALS['db_home_settings'] = $old_settings; } else { unset( $GLOBALS['db_home_settings'] ); }
	}
}

db_test( 'Home switch is off by default', function () {
	$had_settings = array_key_exists( 'db_home_settings', $GLOBALS );
	$old_settings = $had_settings ? $GLOBALS['db_home_settings'] : null;
	unset( $GLOBALS['db_home_settings'] );
	assert_false( DoughBoss_Home::is_enabled(), 'no setting means the home section is off' );
	if ( $had_settings ) { $GLOBALS['db_home_settings'] = $old_settings; }
} );

db_test( 'Home card is live with a real count and reads only new enquiries', function () {
	db_home_with_fixture( function ( $db ) {
		$db->total = 3;
		$card = DoughBoss_Home::card_new_enquiries();
		assert_same( 'live', $card['state'], 'successful read is live' );
		assert_same( 3, $card['count'], 'count comes from the real total' );
		assert_true( isset( $card['read_at'] ), 'read time recorded' );
		assert_same( 2, count( $db->reads ), 'one count and one list read' );
		assert_true( false !== strpos( $db->reads[0], "WHERE 1=1 AND status = %s" ), 'count SQL filters on status' );
		assert_same( array( 'new' ), $db->prepared[0][1], 'status parameter is new' );
	} );
} );

db_test( 'Home card is unseen on read failure without leaking errors or numbers', function () {
	db_home_with_fixture( function ( $db ) {
		foreach ( array( 'fail_count', 'fail_list' ) as $failure ) {
			$db->reads = array();
			$db->$failure = true;
			$card = DoughBoss_Home::card_new_enquiries();
			assert_same( 'unseen', $card['state'], $failure . ' is unseen' );
			assert_false( array_key_exists( 'count', $card ), $failure . ' has no count key' );
			ob_start();
			DoughBoss_Home::render();
			$html = ob_get_clean();
			assert_true( false !== strpos( $html, 'Unseen' ), $failure . ' render says Unseen' );
			assert_false( false !== strpos( $html, 'SQL secret' ), $failure . ' render hides database details' );
			// Visible text only: tag names such as h1/h2 carry digits of their own.
			assert_false( (bool) preg_match( '/[0-9]/', wp_strip_all_tags_for_home_test( $html ) ), $failure . ' render shows no digit count' );
			$db->$failure = false;
		}
	} );
} );

db_test( 'Home card is unseen with a forbidden reason and zero reads when denied', function () {
	db_home_with_fixture( function ( $db ) {
		db_home_set_caps( array() );
		$card = DoughBoss_Home::card_new_enquiries();
		assert_same( 'unseen', $card['state'], 'denied read is unseen' );
		assert_same( 'doughboss_crm_forbidden', $card['reason'], 'denial reason is the CRM forbidden code' );
		assert_same( 0, count( $db->reads ), 'denial performs no database read' );
	} );
} );

db_test( 'Home card is live with count 0 when there are genuinely no enquiries', function () {
	db_home_with_fixture( function ( $db ) {
		$db->total = 0;
		$card = DoughBoss_Home::card_new_enquiries();
		assert_same( 'live', $card['state'], 'a real zero is live' );
		assert_same( 0, $card['count'], 'real zero count is 0' );
	} );
} );

db_test( 'Home performs no database writes', function () {
	db_home_with_fixture( function ( $db ) {
		DoughBoss_Home::card_new_enquiries();
		ob_start();
		DoughBoss_Home::render();
		ob_get_clean();
		assert_same( array(), $db->inserts, 'no inserts' );
		assert_same( array(), $db->updates, 'no updates' );
		assert_same( array(), $db->deletes, 'no deletes' );
		assert_same( array(), $db->replaces, 'no replaces' );
		assert_true( count( $db->reads ) > 0, 'the card did read' );
		assert_same( array(), array_values( array_filter( $db->reads, function ( $sql ) { return 0 !== stripos( ltrim( $sql ), 'select' ); } ) ), 'every query is a SELECT' );
	} );
} );
