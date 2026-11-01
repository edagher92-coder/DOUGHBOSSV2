<?php
/** Focused Home read-only regression tests. */
require_once dirname( __DIR__ ) . '/includes/class-doughboss-catering.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-crm.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-home.php';

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) { return in_array( $capability, isset( $GLOBALS['db_home_caps'] ) ? $GLOBALS['db_home_caps'] : array(), true ); }
}

if ( ! function_exists( 'wp_date' ) ) {
	/** The same deterministic stand-in the notifications suite uses: UTC, so the shown time is predictable. */
	function wp_date( $format, $timestamp = null ) {
		return gmdate( $format, (int) $timestamp );
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

/** The markup of the nth home card (0-based), so a check on one card never reads another card's numbers. */
function db_home_card_html( $html, $index ) {
	$parts = explode( '<div class="db-home-card">', $html );
	if ( ! isset( $parts[ $index + 1 ] ) ) {
		return '';
	}
	$end = strpos( $parts[ $index + 1 ], '</div>' );
	return false === $end ? $parts[ $index + 1 ] : substr( $parts[ $index + 1 ], 0, $end );
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
	$old_options = $GLOBALS['doughboss_test_options'];
	$db = new DoughBoss_Test_Home_DB();
	$GLOBALS['wpdb'] = $db;
	db_home_set_caps( array( 'manage_doughboss' ) );
	doughboss_test_set_settings( array( 'crm_home_enabled' => 1 ) );
	try { $callback( $db ); } finally {
		if ( $had_db ) { $GLOBALS['wpdb'] = $old_db; } else { unset( $GLOBALS['wpdb'] ); }
		if ( $had_caps ) { $GLOBALS['db_home_caps'] = $old_caps; } else { unset( $GLOBALS['db_home_caps'] ); }
		if ( $had_crm_caps ) { $GLOBALS['db_crm_caps'] = $old_crm_caps; } else { unset( $GLOBALS['db_crm_caps'] ); }
		$GLOBALS['doughboss_test_options'] = $old_options;
	}
}

db_test( 'Home switch is off by default and on only when the real setting says so', function () {
	$old_options = $GLOBALS['doughboss_test_options'];
	try {
		// Through the real DoughBoss_Settings (bootstrap.php loads it): the shipped default is off.
		assert_same( 0, DoughBoss_Settings::defaults()['crm_home_enabled'], 'the shipped default is 0' );
		doughboss_test_set_settings( array() );
		assert_false( DoughBoss_Home::is_enabled(), 'nothing stored means off' );
		doughboss_test_set_settings( array( 'crm_home_enabled' => 0 ) );
		assert_false( DoughBoss_Home::is_enabled(), '0 is off' );
		doughboss_test_set_settings( array( 'crm_home_enabled' => 1 ) );
		assert_true( DoughBoss_Home::is_enabled(), '1 is on' );
	} finally {
		$GLOBALS['doughboss_test_options'] = $old_options;
	}
} );

db_test( 'Home section is reachable only while the switch is on; other values keep their sections', function () {
	$old_options = $GLOBALS['doughboss_test_options'];
	try {
		doughboss_test_set_settings( array( 'crm_home_enabled' => 0 ) );
		assert_same( 'overview', DoughBoss_Home::management_section( 'home' ), 'off: ?section=home falls back to the overview' );
		assert_same( 'crm', DoughBoss_Home::management_section( 'crm' ), 'off: the CRM stays reachable' );
		assert_same( 'overview', DoughBoss_Home::management_section( null ), 'off: no section is the overview' );
		doughboss_test_set_settings( array( 'crm_home_enabled' => 1 ) );
		assert_same( 'home', DoughBoss_Home::management_section( 'home' ), 'on: ?section=home is Home' );
		assert_same( 'crm', DoughBoss_Home::management_section( 'crm' ), 'on: the CRM stays reachable' );
		assert_same( 'overview', DoughBoss_Home::management_section( null ), 'on: the default section is still the overview' );
		foreach ( array( 'HOME', 'home ', 'Home', '', 'overview', 'crm2' ) as $other ) {
			assert_same( 'overview', DoughBoss_Home::management_section( $other ), 'only exact values count: ' . var_export( $other, true ) );
		}
		assert_same( 'overview', DoughBoss_Home::management_section( array( 'home' ) ), 'an array is not a section' );
	} finally {
		$GLOBALS['doughboss_test_options'] = $old_options;
	}
} );

db_test( 'The management portal takes its section from management_section and shows the Home link only when on', function () {
	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-portals.php' );
	$start  = strpos( $source, 'private function render_management()' );
	assert_true( false !== $start, 'render_management exists' );
	$body = substr( $source, $start, 6000 );
	assert_true( false !== strpos( $body, '$home_enabled = class_exists( \'DoughBoss_Home\' ) && DoughBoss_Home::is_enabled();' ), 'the nav guard reads the switch' );
	assert_true( false !== strpos( $body, 'DoughBoss_Home::management_section( $requested )' ), 'the section comes from management_section' );
	assert_same( 0, substr_count( $body, "'home' === \$_GET" ) + substr_count( $body, "'home' === \$requested" ), 'no other, unguarded path to the home section' );
	$link = strpos( $body, "home_url( '/management/?section=home' )" );
	$guard = strrpos( substr( $body, 0, $link ), '<?php if ( $home_enabled ) : ?>' );
	$end   = strpos( $body, '<?php endif; ?>', $guard );
	assert_true( false !== $link && false !== $guard && $end > $link, 'the Home nav link sits inside if ( $home_enabled )' );
	assert_true( (bool) preg_match( "/if \\( 'home' === \\\$section \\) \\{\\s*DoughBoss_Home::render\\(\\);/", $body ), 'Home renders only for the home section' );
	assert_same( 1, substr_count( $body, 'DoughBoss_Home::render()' ), 'Home renders in one place only' );
} );

db_test( 'Home card is live with a real count and reads only new enquiries', function () {
	db_home_with_fixture( function ( $db ) {
		$db->total = 3;
		$card = DoughBoss_Home::card_new_enquiries();
		assert_same( 'live', $card['state'], 'successful read is live' );
		assert_same( 3, $card['count'], 'count comes from the real total' );
		assert_true( isset( $card['read_at'] ), 'read time recorded' );
		assert_same( 1, count( $db->reads ), 'one count read, no enquiry rows (no customer details) read' );
		assert_true( false !== stripos( $db->reads[0], 'SELECT COUNT(*)' ), 'it is a count query' );
		assert_true( false !== strpos( $db->reads[0], 'WHERE status = %s' ), 'count SQL filters on status' );
		assert_same( array( 'new' ), $db->prepared[0][1], 'status parameter is new' );
	} );
} );

db_test( 'Home card is unseen on read failure without leaking errors or numbers', function () {
	db_home_with_fixture( function ( $db ) {
		foreach ( array( 'fail_count', 'null_count' ) as $failure ) {
			$db->reads = array();
			if ( 'fail_count' === $failure ) { $db->fail_count = true; } else { $db->total = null; }
			$card = DoughBoss_Home::card_new_enquiries();
			assert_same( 'unseen', $card['state'], $failure . ' is unseen' );
			assert_false( array_key_exists( 'count', $card ), $failure . ' has no count key' );
			ob_start();
			DoughBoss_Home::render();
			$html = ob_get_clean();
			$enquiries = db_home_card_html( $html, 0 );
			assert_true( false !== strpos( $enquiries, 'New catering enquiries' ), $failure . ' first card is the enquiries card' );
			assert_true( false !== strpos( $enquiries, 'Unseen' ), $failure . ' render says Unseen' );
			assert_false( false !== strpos( $html, 'SQL secret' ), $failure . ' render hides database details' );
			// Visible text of this card only: tag names such as h2 carry digits of their own, and the
			// production card beside it may genuinely be live with a count and read time.
			assert_false( (bool) preg_match( '/[0-9]/', wp_strip_all_tags_for_home_test( $enquiries ) ), $failure . ' render shows no digit count' );
			$db->fail_count = false;
			$db->total      = 0;
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

db_test( 'Production card is live with the real committed-job count', function () {
	db_home_with_fixture( function ( $db ) {
		$db->total = 5;
		$card = DoughBoss_Home::card_production_queue();
		assert_same( 'live', $card['state'], 'successful read is live' );
		assert_same( 5, $card['count'], 'count comes from the real count' );
		assert_true( isset( $card['read_at'] ), 'read time recorded' );
		assert_same( 1, count( $db->reads ), 'one count read, no row list' );
		assert_true( false !== stripos( $db->reads[0], 'SELECT COUNT(*)' ), 'it is a count query' );
		assert_true( false !== strpos( $db->reads[0], 'status IN (%s, %s, %s, %s)' ), 'filtered to the production statuses' );
		assert_same( array( 'deposit_paid', 'confirmed', 'balance_due', 'paid' ), $db->prepared[0][1], 'new, quoted, fulfilled and lost are not production' );
	} );
} );

db_test( 'Production card is live with 0 only when there are genuinely no jobs', function () {
	db_home_with_fixture( function ( $db ) {
		$db->total = 0;
		$card = DoughBoss_Home::card_production_queue();
		assert_same( 'live', $card['state'], 'a real zero is live' );
		assert_same( 0, $card['count'], 'real zero count is 0' );
	} );
} );

db_test( 'Production card is unseen, never 0, when the count fails', function () {
	db_home_with_fixture( function ( $db ) {
		$db->fail_count = true;
		$card = DoughBoss_Home::card_production_queue();
		assert_same( 'unseen', $card['state'], 'failed count is unseen' );
		assert_same( 'doughboss_production_queue_unavailable', $card['reason'], 'reason names the queue' );
		assert_false( array_key_exists( 'count', $card ), 'no count key' );
		$db->fail_count = false;
		$db->total      = null;
		$card = DoughBoss_Home::card_production_queue();
		assert_same( 'unseen', $card['state'], 'a null count with no error text is unseen too' );
		ob_start();
		DoughBoss_Home::render();
		$queue = db_home_card_html( ob_get_clean(), 1 );
		assert_true( false !== strpos( $queue, 'Catering production queue' ), 'second card is the production card' );
		assert_true( false !== strpos( $queue, 'Unseen' ), 'render says Unseen' );
		assert_false( (bool) preg_match( '/[0-9]/', wp_strip_all_tags_for_home_test( $queue ) ), 'render shows no digit count' );
	} );
} );

db_test( 'Production card is unseen with zero reads when denied', function () {
	db_home_with_fixture( function ( $db ) {
		db_home_set_caps( array() );
		$card = DoughBoss_Home::card_production_queue();
		assert_same( 'unseen', $card['state'], 'denied read is unseen' );
		assert_same( 'doughboss_crm_forbidden', $card['reason'], 'denial reason is the CRM forbidden code' );
		assert_same( 0, count( $db->reads ), 'denial performs no database read' );
	} );
} );

db_test( 'The old production count still returns 0 on failure for its existing callers', function () {
	db_home_with_fixture( function ( $db ) {
		$db->fail_count = true;
		assert_same( 0, DoughBoss_Catering::production_queue_count( 0 ), 'unchanged behaviour of the legacy method' );
		assert_true( is_wp_error( DoughBoss_Catering::production_queue_count_checked( 0 ) ), 'the checked method reports the failure' );
	} );
} );

db_test( 'A live card shows its real count, including a visible 0, with its read time and link', function () {
	db_home_with_fixture( function ( $db ) {
		foreach ( array( 0, 7 ) as $count ) {
			$db->total = $count;
			ob_start();
			DoughBoss_Home::render();
			$html = ob_get_clean();
			foreach ( array( 0 => 'New catering enquiries', 1 => 'Catering production queue' ) as $index => $title ) {
				$card = db_home_card_html( $html, $index );
				assert_true( false !== strpos( $card, $title ), $title . ' is card ' . $index );
				assert_true( false !== strpos( $card, '<p>' . $count . '</p>' ), $title . ' shows ' . $count . ' as a number, never blank' );
				assert_true( (bool) preg_match( '/Read at <time datetime="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[^"]*">[^<]+<\/time>/', $card ), $title . ' shows when it was read' );
				assert_false( false !== strpos( $card, 'Unseen' ), $title . ' live card never says Unseen' );
				assert_true( false !== strpos( $card, '<a href=' ), $title . ' links to its list' );
			}
		}
	} );
} );

db_test( 'A card escapes its title, link and text', function () {
	$method = new ReflectionMethod( 'DoughBoss_Home', 'render_card' );
	$method->setAccessible( true );
	$hostile = '<script>x</script>';
	foreach ( array(
		array( 'state' => 'live', 'count' => 1, 'read_at' => '<b>t</b>' ),
		array( 'state' => 'unseen', 'reason' => 'x' ),
	) as $card ) {
		ob_start();
		$method->invoke( null, $hostile, $card, 'javascript:alert(1)', $hostile, $hostile );
		$html = ob_get_clean();
		assert_false( false !== strpos( $html, '<script>' ), $card['state'] . ': no raw script tag' );
		assert_false( false !== strpos( $html, '<b>t</b>' ), $card['state'] . ': the read time is escaped' );
	}
	// The test esc_url() stand-in does not strip schemes the way WordPress does, so pin the call itself.
	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-home.php' );
	assert_true( false !== strpos( $source, 'esc_url( $link_url )' ), 'the link is run through esc_url' );
	assert_same( 1, substr_count( $source, '<a href=' ), 'the card has one link, the escaped one' );
} );

db_test( 'Home switch is on only for the documented 1, never for other truthy text', function () {
	$old_options = $GLOBALS['doughboss_test_options'];
	try {
		foreach ( array( 1, '1', true ) as $on ) {
			doughboss_test_set_settings( array( 'crm_home_enabled' => $on ) );
			assert_true( DoughBoss_Home::is_enabled(), 'on: ' . var_export( $on, true ) );
		}
		foreach ( array( 0, '0', '', null, false, 'false', 'no', 'off', 'yes', 2, '2', '1 ', array( 1 ) ) as $off ) {
			doughboss_test_set_settings( array( 'crm_home_enabled' => $off ) );
			assert_false( DoughBoss_Home::is_enabled(), 'off: ' . var_export( $off, true ) );
		}
	} finally {
		$GLOBALS['doughboss_test_options'] = $old_options;
	}
} );

db_test( 'A live card shows its read time for people, with the exact time kept in datetime', function () {
	db_home_with_fixture( function ( $db ) {
		$db->total = 2;
		ob_start();
		DoughBoss_Home::render();
		$card = db_home_card_html( ob_get_clean(), 0 );
		$matched = preg_match( '/Read at <time datetime="([^"]+)">([^<]+)<\/time>/', $card, $m );
		assert_true( (bool) $matched, 'the read time is a <time> with a datetime attribute' );
		if ( $matched ) {
			$at = strtotime( $m[1] );
			assert_true( false !== $at && abs( time() - $at ) < 120, 'datetime is the real read time, ISO 8601' );
			assert_same( wp_date( 'D j M, g:i a', $at ), $m[2], 'the shown time is the site-local short form' );
			assert_false( false !== strpos( $m[2], 'T' ) && false !== strpos( $m[2], '+00:00' ), 'not a raw ISO string' );
		}
	} );
} );

db_test( 'The old production count reads through the checked one: same count when it reads', function () {
	db_home_with_fixture( function ( $db ) {
		$db->total = 4;
		assert_same( 4, DoughBoss_Catering::production_queue_count( 0 ), 'same number as the checked count' );
		assert_same( 1, count( $db->reads ), 'one query, the checked one' );
	} );
	// One query in one place: the old method wraps the checked one, so the two can never drift apart.
	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-catering.php' );
	$start  = strpos( $source, 'public static function production_queue_count(' );
	$body   = substr( $source, $start, strpos( $source, 'public static function', $start + 10 ) - $start );
	assert_true( false !== strpos( $body, 'self::production_queue_count_checked(' ), 'the old count calls the checked one' );
	assert_false( false !== stripos( $body, 'SELECT' ), 'and has no query of its own' );
} );
