<?php
/**
 * 2.49.0 front-end upgrade: entity decoding, store open-now calculator,
 * wholesale/franchising enquiry validation and combined catering packages.
 *
 * Named to run last: the WordPress shims below are guarded, and running after
 * the other suites means they never replace another file's own shim.
 *
 * @package DoughBoss
 */

require_once dirname( __DIR__ ) . '/includes/class-doughboss-post-types.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-store-hours.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-b2b.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-catering-package.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-catering.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-locations.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-rest-controller.php';

if ( ! function_exists( 'sanitize_email' ) ) {
	/** @return string */
	function sanitize_email( $email ) {
		return trim( preg_replace( '/[^a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~@-]/', '', (string) $email ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/** @return string */
	function sanitize_textarea_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}
if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	/** @return string */
	function wp_specialchars_decode( $value, $quote_style = ENT_NOQUOTES ) {
		return htmlspecialchars_decode( (string) $value, ENT_QUOTES );
	}
}
if ( ! function_exists( 'wp_rand' ) ) {
	/** @return int */
	function wp_rand( $min = 0, $max = 0 ) {
		return random_int( (int) $min, (int) $max );
	}
}
if ( ! function_exists( 'do_action' ) ) {
	/** @return void */
	function do_action( $hook ) {}
}
if ( ! function_exists( 'mysql2date' ) ) {
	/** @return string */
	function mysql2date( $format, $date ) {
		return gmdate( $format, strtotime( $date ) );
	}
}

$GLOBALS['db_fe_posts'] = array();
$GLOBALS['db_fe_meta']  = array();
$GLOBALS['db_fe_mail']  = array();
if ( ! function_exists( 'get_post' ) ) {
	/** @return object|null */
	function get_post( $id ) {
		return isset( $GLOBALS['db_fe_posts'][ (int) $id ] ) ? $GLOBALS['db_fe_posts'][ (int) $id ] : null;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	/** @return mixed */
	function get_post_meta( $id, $key = '', $single = false ) {
		return isset( $GLOBALS['db_fe_meta'][ (int) $id ][ $key ] ) ? $GLOBALS['db_fe_meta'][ (int) $id ][ $key ] : '';
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	/** @return string */
	function get_the_title( $id ) {
		$post = get_post( $id );
		return $post ? (string) $post->post_title : '';
	}
}
if ( ! function_exists( 'wp_mail' ) ) {
	/** @return bool */
	function wp_mail( $to, $subject, $message, $headers = array() ) {
		$GLOBALS['db_fe_mail'][] = compact( 'to', 'subject', 'message', 'headers' );
		return ! isset( $GLOBALS['db_fe_mail_result'] ) || $GLOBALS['db_fe_mail_result'];
	}
}

/** Register a synthetic catering package. */
function db_fe_package( $id, $title, $price, $serves_max = 0, $per_head = 0, $deposit_pct = 0, $status = 'publish' ) {
	$GLOBALS['db_fe_posts'][ $id ] = (object) array(
		'ID'          => $id,
		'post_title'  => $title,
		'post_type'   => DoughBoss_Catering_Package::POST_TYPE,
		'post_status' => $status,
	);
	$GLOBALS['db_fe_meta'][ $id ] = array(
		DoughBoss_Catering_Package::META_BASE_PRICE  => (string) $price,
		DoughBoss_Catering_Package::META_SERVES_MAX  => (string) $serves_max,
		DoughBoss_Catering_Package::META_PER_HEAD    => (string) $per_head,
		DoughBoss_Catering_Package::META_DEPOSIT_PCT => (string) $deposit_pct,
	);
}

/** Minimal $wpdb that records the catering insert. */
class DoughBoss_Test_Frontend_DB {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $last_error = '';
	public $rows = array();
	public function prepare( $query ) { return $query; }
	public function get_var( $query ) { return 0; }
	public function get_results( $query ) { return array(); }
	public function insert( $table, $row, $formats = null ) {
		$this->rows[] = $row;
		$this->insert_id = count( $this->rows );
		return 1;
	}
}

/* -------------------------------------------------------------------------- */
/* Entity decoding                                                            */
/* -------------------------------------------------------------------------- */

db_test(
	'menu text decodes a double-escaped standalone ampersand without interpreting entity markup',
	function () {
		assert_same(
			'Sujuk, tomato, mushroom, capsicum, onion, black olives & cheese on a tomato base.',
			DoughBoss_Post_Types::plain_menu_description( 'Sujuk, tomato, mushroom, capsicum, onion, black olives &amp;amp; cheese on a tomato base.' ),
			'Sujuk Special double-escaped "&amp;" renders as "&"'
		);
		assert_same( 'black olives & cheese', DoughBoss_Post_Types::plain_menu_description( 'black olives &amp; cheese' ), 'single-escaped ampersand decodes once' );
		assert_same( 'Lemon & Mint', DoughBoss_Post_Types::plain_menu_description( 'Lemon & Mint' ), 'plain text is unchanged' );
		assert_same( 'Lemon & Mint', DoughBoss_Post_Types::plain_menu_description( 'Lemon &#038; Mint' ), 'WordPress numeric ampersand decodes' );
		assert_same( 'Lemon & Mint', DoughBoss_Post_Types::plain_menu_description( 'Lemon &amp;#038; Mint' ), 'double-escaped numeric ampersand standing alone decodes' );
		assert_same( 'Literal &lt;em&gt;herbs&lt;/em&gt;', DoughBoss_Post_Types::plain_menu_description( 'Literal &amp;lt;em&amp;gt;herbs&amp;lt;/em&amp;gt;' ), 'entity markup is never interpreted (single decode kept)' );
		assert_same( 'R&amp;D kitchen', DoughBoss_Post_Types::plain_menu_description( 'R&amp;amp;D kitchen' ), 'an embedded (non-standalone) entity is left as text' );
		assert_same( '& more', DoughBoss_Post_Types::plain_menu_description( '&amp;amp; more' ), 'string-start standalone ampersand decodes' );
		assert_same( 'Cheese, Tomato & Olives', DoughBoss_Post_Types::plain_menu_title( 'Cheese, Tomato &amp; Olives' ), 'kses-encoded titles decode for textContent' );
		assert_same( 'Chicken & Cheese', DoughBoss_Post_Types::plain_menu_title( ' Chicken &#038; Cheese ' ), 'titles are trimmed plain text' );
		assert_same( '', DoughBoss_Post_Types::plain_menu_description( null ), 'non-strings are empty' );
	}
);

/* -------------------------------------------------------------------------- */
/* Store hours                                                                */
/* -------------------------------------------------------------------------- */

db_test(
	'store open-now calculator evaluates published hours in Sydney time across daylight saving',
	function () {
		$revesby   = DoughBoss_Store_Hours::schedule_for( 'revesby' );
		$bankstown = DoughBoss_Store_Hours::schedule_for( 'bankstown' );
		$roselands = DoughBoss_Store_Hours::schedule_for( 'roselands' );
		assert_same( 'published', $revesby['source'], 'no stored hours uses the published fallback' );
		assert_same( array( '7 days · 6:30am–2:30pm' ), DoughBoss_Store_Hours::summary_lines( $revesby['week'] ), 'Revesby summary' );
		assert_same( array( 'Mon–Fri · 7am–2pm' ), DoughBoss_Store_Hours::summary_lines( $bankstown['week'] ), 'Bankstown summary' );
		assert_same( array( '7 days · 8am–3pm' ), DoughBoss_Store_Hours::summary_lines( $roselands['week'] ), 'Roselands summary' );

		$utc = new DateTimeZone( 'UTC' );
		// Monday 4 Jan 2027 is AEDT (UTC+11): 06:29 local = 19:29Z Sunday.
		$before = new DateTimeImmutable( '2027-01-03 19:29:00', $utc );
		$open   = new DateTimeImmutable( '2027-01-03 19:30:00', $utc );
		$state  = DoughBoss_Store_Hours::state( $revesby['week'], $before );
		assert_false( $state['open'], 'Revesby is closed at 6:29am AEDT' );
		assert_same( 'Closed now · opens 6:30am', DoughBoss_Store_Hours::badge_label( $state, $before ), 'same-day opening label' );
		$state = DoughBoss_Store_Hours::state( $revesby['week'], $open );
		assert_true( $state['open'], 'Revesby opens exactly at 6:30am AEDT' );
		assert_same( 'Open now · until 2:30pm', DoughBoss_Store_Hours::badge_label( $state, $open ), 'open label shows closing time' );
		$closing = new DateTimeImmutable( '2027-01-04 03:30:00', $utc ); // 14:30 AEDT Monday.
		$state   = DoughBoss_Store_Hours::state( $revesby['week'], $closing );
		assert_false( $state['open'], 'closing time is exclusive' );
		assert_same( 'Closed now · opens Tue 6:30am', DoughBoss_Store_Hours::badge_label( $state, $closing ), 'next opening is tomorrow' );

		// Friday 10 Jul 2026 is AEST (UTC+10).
		$friday_open  = new DateTimeImmutable( '2026-07-10 03:59:00', $utc ); // 13:59 AEST.
		$friday_close = new DateTimeImmutable( '2026-07-10 04:00:00', $utc ); // 14:00 AEST.
		assert_true( DoughBoss_Store_Hours::is_open( $bankstown['week'], $friday_open ), 'Bankstown open 1:59pm Friday AEST' );
		$state = DoughBoss_Store_Hours::state( $bankstown['week'], $friday_close );
		assert_false( $state['open'], 'Bankstown closed 2pm Friday' );
		assert_same( 'Closed now · opens Mon 7am', DoughBoss_Store_Hours::badge_label( $state, $friday_close ), 'weekend closure points to Monday' );
		$saturday = new DateTimeImmutable( '2026-07-11 00:00:00', $utc ); // 10:00 AEST Saturday.
		assert_false( DoughBoss_Store_Hours::is_open( $bankstown['week'], $saturday ), 'Bankstown closed Saturday' );
		assert_true( DoughBoss_Store_Hours::is_open( $roselands['week'], $saturday ), 'Roselands open Saturday 10am' );
		$sunday_eight = new DateTimeImmutable( '2026-07-11 22:00:00', $utc ); // 08:00 AEST Sunday.
		assert_true( DoughBoss_Store_Hours::is_open( $roselands['week'], $sunday_eight ), 'Roselands opens 8am Sunday' );
	}
);

db_test(
	'store hours prefer valid stored weekly hours and reject malformed or overnight ranges',
	function () {
		$stored = DoughBoss_Store_Hours::schedule_for( 'revesby', array( 'mon' => '07:00-12:00, 13:00-15:00', 'tue' => '25:00-26:00', 'wed' => '18:00-02:00', 'sat' => '08:00-12:00' ) );
		assert_same( 'stored', $stored['source'], 'stored hours win when at least one range is valid' );
		assert_same( array( array( '07:00', '12:00' ), array( '13:00', '15:00' ) ), $stored['week'][1], 'split shifts are kept in order' );
		assert_same( array(), $stored['week'][2], 'invalid clock dropped' );
		assert_same( array(), $stored['week'][3], 'overnight range dropped' );
		assert_same( array( 'Mon · 7am–12pm, 1pm–3pm', 'Sat · 8am–12pm' ), DoughBoss_Store_Hours::summary_lines( $stored['week'] ), 'summary groups days and shifts' );
		$lunch = new DateTimeImmutable( '2026-07-13 02:30:00', new DateTimeZone( 'UTC' ) ); // Mon 12:30 AEST.
		$state = DoughBoss_Store_Hours::state( $stored['week'], $lunch );
		assert_false( $state['open'], 'closed between split shifts' );
		assert_same( 'Closed now · opens 1pm', DoughBoss_Store_Hours::badge_label( $state, $lunch ), 'reopens the same afternoon' );
		$empty = DoughBoss_Store_Hours::schedule_for( 'revesby', array( 'mon' => '' ) );
		assert_same( 'published', $empty['source'], 'blank stored hours fall back to published hours' );
		$unknown = DoughBoss_Store_Hours::schedule_for( 'parramatta' );
		assert_same( 'none', $unknown['source'], 'unknown shops have no invented hours' );
		assert_same( '', DoughBoss_Store_Hours::badge_label( DoughBoss_Store_Hours::state( $unknown['week'] ) ), 'no badge without hours' );
		assert_same( '{"1":[["07:00","12:00"],["13:00","15:00"]],"6":[["08:00","12:00"]]}', DoughBoss_Store_Hours::to_json( $stored['week'] ), 'compact JSON for the live badge' );
		assert_same( '12pm', DoughBoss_Store_Hours::format_clock( '12:00' ), 'noon formats as 12pm' );
		assert_same( '12:15am', DoughBoss_Store_Hours::format_clock( '00:15' ), 'after midnight formats as 12:15am' );
	}
);

/* -------------------------------------------------------------------------- */
/* Wholesale / franchising enquiries                                          */
/* -------------------------------------------------------------------------- */

db_test(
	'b2b enquiry validation is server-side and rejects header injection',
	function () {
		$valid = array(
			'type'     => 'wholesale',
			'name'     => '  Sam  Haddad ',
			'business' => 'Metro Grocer <b>&</b> Cafe',
			'email'    => 'sam@example.com.au',
			'phone'    => '0400 000 000',
			'focus'    => 'Par-baked bases',
			'message'  => "We would like to stock\r\nyour pies weekly.",
		);
		$clean = DoughBoss_B2B::validate( $valid );
		assert_false( is_wp_error( $clean ), 'valid wholesale enquiry passes' );
		assert_same( 'Sam Haddad', $clean['name'], 'whitespace collapsed' );
		assert_same( 'Metro Grocer & Cafe', $clean['business'], 'markup stripped' );
		assert_same( "We would like to stock\nyour pies weekly.", $clean['message'], 'newlines normalised' );

		$franchise = DoughBoss_B2B::validate( array_merge( $valid, array( 'type' => 'franchising', 'business' => '' ) ) );
		assert_false( is_wp_error( $franchise ), 'business is optional for franchising' );
		assert_same( 'franchise', $franchise['type'], 'franchising normalises to franchise' );

		$missing = DoughBoss_B2B::validate( array_merge( $valid, array( 'business' => '', 'name' => 'S', 'message' => 'Hi' ) ) );
		assert_true( is_wp_error( $missing ), 'missing fields fail' );
		$data = $missing->get_error_data();
		assert_same( 400, $data['status'], 'validation is a 400' );
		assert_same( array( 'name', 'business', 'message' ), array_keys( $data['fields'] ), 'each failing field is reported' );

		foreach ( array( "sam@example.com\r\nBcc: victim@example.com", 'not-an-email', 'a@b', '' ) as $email ) {
			$result = DoughBoss_B2B::validate( array_merge( $valid, array( 'email' => $email ) ) );
			assert_true( is_wp_error( $result ) && isset( $result->get_error_data()['fields']['email'] ), 'invalid email rejected: ' . json_encode( $email ) );
		}
		foreach ( array( 'call me', '123', '0400 000 000 000 000 0' ) as $phone ) {
			$result = DoughBoss_B2B::validate( array_merge( $valid, array( 'phone' => $phone ) ) );
			assert_true( is_wp_error( $result ) && isset( $result->get_error_data()['fields']['phone'] ), 'invalid phone rejected: ' . $phone );
		}
		assert_true( is_wp_error( DoughBoss_B2B::validate( array_merge( $valid, array( 'message' => str_repeat( 'a', 2001 ) ) ) ) ), 'overlong message rejected' );
		assert_true( is_wp_error( DoughBoss_B2B::validate( array_merge( $valid, array( 'name' => array( 'x' ) ) ) ) ), 'non-scalar input rejected' );
	}
);

db_test(
	'b2b email goes to the configured inbox with Reply-To set to the enquirer',
	function () {
		doughboss_test_set_settings( array( 'orders_email' => 'orders@example.com.au', 'b2b_email' => '' ) );
		assert_same( 'orders@example.com.au', DoughBoss_B2B::recipient(), 'blank b2b_email falls back to the orders inbox' );
		doughboss_test_set_settings( array( 'orders_email' => 'orders@example.com.au', 'b2b_email' => 'not an email' ) );
		assert_same( 'orders@example.com.au', DoughBoss_B2B::recipient(), 'invalid b2b_email falls back too' );
		doughboss_test_set_settings( array( 'orders_email' => 'orders@example.com.au', 'b2b_email' => 'partners@example.com.au' ) );
		assert_same( 'partners@example.com.au', DoughBoss_B2B::recipient(), 'configured b2b inbox is used' );

		$clean = DoughBoss_B2B::validate(
			array(
				'type'    => 'franchise',
				'name'    => 'Sam "Haddad", Jr; <b>x</b>',
				'email'   => 'sam@example.com.au',
				'phone'   => '+61 400 000 000',
				'message' => 'Interested in a Parramatta partnership.',
			)
		);
		$mail = DoughBoss_B2B::email_parts( $clean );
		assert_same( 'partners@example.com.au', $mail['to'], 'mail destination' );
		assert_same( array( 'Reply-To: Sam Haddad Jr x <sam@example.com.au>' ), $mail['headers'], 'Reply-To uses a sanitised display name' );
		assert_true( 0 === strpos( $mail['subject'], 'Franchising & partnerships enquiry: ' ), 'subject names the stream' );
		assert_true( false !== strpos( $mail['body'], 'Interested in a Parramatta partnership.' ), 'message included' );
		assert_same( 'Website enquiry', DoughBoss_B2B::header_name( "\r\n<>" ), 'empty display name falls back' );

		$GLOBALS['db_fe_mail'] = array();
		assert_true( DoughBoss_B2B::send( $clean ), 'send reports wp_mail success' );
		assert_same( 1, count( $GLOBALS['db_fe_mail'] ), 'exactly one email' );
		doughboss_test_set_settings( array() );
	}
);

db_test(
	'order phone helpers format the call-to-order number',
	function () {
		doughboss_test_set_settings( array() );
		assert_same( '0297742286', DoughBoss_Settings::order_phone(), 'default is Revesby' );
		assert_same( '+61297742286', DoughBoss_Settings::phone_tel( DoughBoss_Settings::order_phone() ), 'tel: form' );
		assert_same( '(02) 9774 2286', DoughBoss_Settings::phone_display( '0297742286' ), 'landline display' );
		assert_same( '0466 353 133', DoughBoss_Settings::phone_display( '+61466353133' ), 'mobile display' );
		doughboss_test_set_settings( array( 'order_phone' => 'call us' ) );
		assert_same( '0297742286', DoughBoss_Settings::order_phone(), 'invalid stored phone falls back' );
		doughboss_test_set_settings( array() );
	}
);

/* -------------------------------------------------------------------------- */
/* Catering combined packages                                                 */
/* -------------------------------------------------------------------------- */

db_test(
	'catering package selections are validated and merged',
	function () {
		assert_same( array(), DoughBoss_Catering::normalise_packages( null ), 'nothing selected' );
		assert_same( array(), DoughBoss_Catering::normalise_packages( '' ), 'empty query' );
		assert_same(
			array( array( 'id' => 12, 'qty' => 3 ), array( 'id' => 14, 'qty' => 1 ) ),
			DoughBoss_Catering::normalise_packages( '12:2,14:1,12:1' ),
			'query form merges duplicates'
		);
		assert_same(
			array( array( 'id' => 7, 'qty' => 2 ) ),
			DoughBoss_Catering::normalise_packages( array( array( 'id' => '7', 'qty' => 2, 'unit_price' => 0.01 ), (object) array( 'id' => 8, 'qty' => 0 ) ) ),
			'JSON form ignores client prices and zero quantities'
		);
		foreach ( array( '12', '12:-1', 'a:1', '12:1;DROP', array( array( 'id' => 0, 'qty' => 1 ) ), array( array( 'id' => 1.5, 'qty' => 1 ) ), array( array( 'qty' => 1 ) ), 42 ) as $bad ) {
			$result = DoughBoss_Catering::normalise_packages( $bad );
			assert_true( is_wp_error( $result ) && 'doughboss_catering_packages_invalid' === $result->get_error_code(), 'malformed selection rejected: ' . json_encode( $bad ) );
		}
		$too_many = DoughBoss_Catering::normalise_packages( '5:30,5:30' );
		assert_true( is_wp_error( $too_many ) && 'doughboss_catering_packages_quantity' === $too_many->get_error_code(), 'per-package quantity cap' );
		$lines = array();
		for ( $i = 1; $i <= DoughBoss_Catering::MAX_PACKAGE_LINES + 1; $i++ ) {
			$lines[] = $i . ':1';
		}
		assert_true( is_wp_error( DoughBoss_Catering::normalise_packages( implode( ',', $lines ) ) ), 'line count cap' );
	}
);

db_test(
	'combined catering quotes are priced from package posts with the server deposit percentage',
	function () {
		doughboss_test_set_settings( array( 'currency_code' => 'AUD' ) );
		db_fe_package( 538, 'Morning Tea — 2 Dozen Minis', 46, 12 );
		db_fe_package( 539, 'Office Platter — 3 Dozen Minis', 72, 18 );
		db_fe_package( 541, 'Fried Sambousek &amp; Dips — 2 Dozen', 56, 12, 0, 40 );
		db_fe_package( 600, 'Draft package', 99, 10, 0, 0, 'draft' );
		db_fe_package( 601, 'Per-head package', 100, 10, 5 );

		$quote = DoughBoss_Catering::quote_packages( array( array( 'id' => 539, 'qty' => 2 ), array( 'id' => 538, 'qty' => 1 ) ), 30, 'pickup' );
		assert_false( is_wp_error( $quote ), 'combined selection quotes' );
		assert_same( 190.0, $quote['subtotal'], '2 × 72 + 1 × 46' );
		assert_same( 30, $quote['deposit_pct'], 'default server deposit percentage' );
		assert_same( 57.0, $quote['deposit'], 'deposit computed server-side' );
		assert_same( 133.0, $quote['balance'], 'balance is the remainder' );
		assert_same( 48, $quote['serves_max'], 'combined capacity 2 × 18 + 12' );
		assert_same( 'Office Platter — 3 Dozen Minis', $quote['lines'][0]['name'], 'line names come from the posts' );
		assert_same( 144.0, $quote['lines'][0]['line_total'], 'line total' );

		$mixed = DoughBoss_Catering::quote_packages( array( array( 'id' => 538, 'qty' => 1 ), array( 'id' => 541, 'qty' => 1 ) ), 0, 'pickup' );
		assert_same( 40, $mixed['deposit_pct'], 'highest selected deposit percentage applies' );
		assert_same( 40.8, $mixed['deposit'], '40% of 102' );
		assert_same( 'Fried Sambousek & Dips — 2 Dozen', $mixed['lines'][1]['name'], 'stored entities decode in line names' );

		$single        = DoughBoss_Catering::quote_packages( array( array( 'id' => 601, 'qty' => 1 ) ), 14, 'pickup' );
		$legacy        = DoughBoss_Catering::quote( 601, 14, 'pickup' );
		assert_same( $legacy['total'], $single['total'], 'one package at quantity one is priced exactly like the legacy quote' );
		assert_same( 120.0, $single['subtotal'], 'legacy per-head overflow kept for a single package' );

		$draft = DoughBoss_Catering::quote_packages( array( array( 'id' => 600, 'qty' => 1 ) ), 0, 'pickup' );
		assert_true( is_wp_error( $draft ) && 'doughboss_catering_package_unavailable' === $draft->get_error_code(), 'unpublished packages are refused' );
		$missing = DoughBoss_Catering::quote_packages( array( array( 'id' => 538, 'qty' => 1 ), array( 'id' => 999, 'qty' => 1 ) ), 0, 'pickup' );
		assert_true( is_wp_error( $missing ), 'unknown packages are refused' );

		$note = DoughBoss_Catering::packages_note( $quote );
		assert_same( "Packages selected:\n2 × Office Platter — 3 Dozen Minis — $144.00\n1 × Morning Tea — 2 Dozen Minis — $46.00\nPackage subtotal: $190.00", $note, 'readable staff note' );
		assert_same( '2 × Office Platter; 1 × Morning Tea', DoughBoss_Catering::package_label( array( 'package_id' => 0, 'notes' => $note . "\n\nUse the side door" ) ), 'label summarises the stored list' );
		assert_same( 'Office Platter — 3 Dozen Minis', DoughBoss_Catering::package_label( array( 'package_id' => 539, 'notes' => 'No list' ) ), 'single package label' );
		assert_same( 'Custom', DoughBoss_Catering::package_label( array( 'package_id' => 0, 'notes' => '' ) ), 'custom label' );
		doughboss_test_set_settings( array() );
	}
);

db_test(
	'catering enquiries store a combined selection without trusting client totals and keep package_id compatible',
	function () {
		doughboss_test_set_settings( array( 'currency_code' => 'AUD', 'catering_min_notice_business_days' => 0 ) );
		db_fe_package( 538, 'Morning Tea — 2 Dozen Minis', 46, 12 );
		db_fe_package( 539, 'Office Platter — 3 Dozen Minis', 72, 18 );
		$previous = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		$db       = new DoughBoss_Test_Frontend_DB();
		$GLOBALS['wpdb'] = $db;

		$base = array(
			'customer_name'  => 'Synthetic Customer',
			'customer_email' => 'synthetic@example.com.au',
			'guest_count'    => 20,
			'order_type'     => 'pickup',
			'notes'          => 'Side door please',
			'subtotal'       => 1,
			'quote_total'    => 1,
		);
		$id = DoughBoss_Catering::create( array_merge( $base, array( 'packages' => array( array( 'id' => 539, 'qty' => 2 ), array( 'id' => 538, 'qty' => 1, 'price' => 0 ) ) ) ) );
		assert_same( 1, $id, 'combined enquiry saved' );
		$row = $db->rows[0];
		assert_same( 0, $row['package_id'], 'two package types store package_id 0' );
		assert_same( 190.0, $row['subtotal'], 'server subtotal ignores client totals' );
		assert_same( 57.0, $row['deposit_amount'], 'server deposit' );
		assert_true( 0 === strpos( $row['notes'], "Packages selected:\n2 × Office Platter" ), 'package list leads the notes' );
		assert_true( false !== strpos( $row['notes'], "\n\nSide door please" ), 'customer notes kept after the list' );

		DoughBoss_Catering::create( array_merge( $base, array( 'packages' => '539:3' ) ) );
		assert_same( 539, $db->rows[1]['package_id'], 'one package type keeps its package_id' );
		assert_same( 216.0, $db->rows[1]['subtotal'], '3 × 72' );

		DoughBoss_Catering::create( array_merge( $base, array( 'package_id' => 538 ) ) );
		assert_same( 538, $db->rows[2]['package_id'], 'legacy single package_id still works' );
		assert_same( 46.0, $db->rows[2]['subtotal'], 'legacy pricing unchanged' );
		assert_same( 'Side door please', $db->rows[2]['notes'], 'legacy notes untouched' );

		$bad = DoughBoss_Catering::create( array_merge( $base, array( 'packages' => 'nope' ) ) );
		assert_true( is_wp_error( $bad ), 'malformed selection rejected before saving' );
		assert_same( 3, count( $db->rows ), 'nothing saved for a rejected selection' );

		$GLOBALS['wpdb'] = $previous;
		doughboss_test_set_settings( array() );
	}
);

/** Location lookup fixture for the private acknowledgement sender; never a real DB. */
class DoughBoss_Test_Catering_Mail_DB extends DoughBoss_Test_Frontend_DB {
	public $lookup_count = 0;
	public $prepared_location_ids = array();
	public $location_exists = true;
	public function prepare( $query ) {
		$args = func_get_args();
		$this->prepared_location_ids[] = isset( $args[1] ) ? $args[1] : null;
		return $query;
	}
	public function get_row( $query ) {
		$this->lookup_count++;
		return $this->location_exists ? (object) array( 'id' => 41, 'name' => 'Saved Revesby Bakery' ) : null;
	}
}

/** Exercise real sender with a saved-row fixture and intercepted mail only. */
function db_fe_catering_notification( array $changes = array(), $mail_result = true, $location_exists = true ) {
	$row = array_merge( array(
		'enquiry_number' => 'CAT-SAVED-0041',
		'location_id' => 41,
		'package_id' => 0,
		'customer_name' => 'Saved Synthetic Customer',
		'customer_email' => 'saved.customer@example.invalid',
		'customer_phone' => '0400000041',
		'guest_count' => 18,
		'event_date' => '2031-11-04',
		'event_time' => '18:45',
		'order_type' => 'pickup',
		'address' => '',
		'dietary' => 'Saved dietary request',
		'notes' => 'Saved side-door note',
		'currency' => 'AUD',
		'subtotal' => 200,
		'delivery_fee' => 12.4,
		'quote_total' => 212.4,
		'deposit_amount' => 63.72,
		'balance_amount' => 148.68,
	), $changes );
	doughboss_test_set_settings( array( 'catering_email' => 'configured.catering@example.invalid', 'currency_code' => 'AUD' ) );
	$previous_db = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
	$previous_mail = $GLOBALS['db_fe_mail'];
	$previous_result = isset( $GLOBALS['db_fe_mail_result'] ) ? $GLOBALS['db_fe_mail_result'] : null;
	$previous_post = $_POST;
	$db = new DoughBoss_Test_Catering_Mail_DB();
	$db->location_exists = $location_exists;
	$GLOBALS['wpdb'] = $db;
	$GLOBALS['db_fe_mail'] = array();
	$GLOBALS['db_fe_mail_result'] = $mail_result;
	$_POST = array( 'location_id' => 999, 'event_time' => '11:11', 'quote_total' => 1, 'customer_email' => 'request.only@example.invalid' );
	try {
		$class = new ReflectionClass( 'DoughBoss_REST_Controller' );
		$sender = $class->getMethod( 'send_catering_notification' );
		$sender->setAccessible( true );
		$returned = $sender->invoke( $class->newInstanceWithoutConstructor(), $row );
		return array( 'row' => $row, 'mail' => $GLOBALS['db_fe_mail'], 'db' => $db, 'returned' => $returned );
	} finally {
		$GLOBALS['wpdb'] = $previous_db;
		$GLOBALS['db_fe_mail'] = $previous_mail;
		if ( null === $previous_result ) { unset( $GLOBALS['db_fe_mail_result'] ); }
		else { $GLOBALS['db_fe_mail_result'] = $previous_result; }
		$_POST = $previous_post;
	}
}

db_test(
	'catering acknowledgements are replyable plain text sourced from the saved row without confirming a booking',
	function () {
		$result = db_fe_catering_notification();
		assert_same( 2, count( $result['mail'] ), 'customer and configured staff receive one intercepted mail each' );
		$customer = $result['mail'][0];
		$staff = $result['mail'][1];
		assert_same( $result['row']['customer_email'], $customer['to'], 'customer recipient comes from saved row' );
		assert_same( 'configured.catering@example.invalid', $staff['to'], 'staff recipient uses configured catering address' );
		assert_same( array( 'Content-Type: text/plain; charset=UTF-8', 'Reply-To: configured.catering@example.invalid' ), $customer['headers'], 'customer reply goes to the configured catering team' );
		assert_same( array( 'Content-Type: text/plain; charset=UTF-8', 'Reply-To: saved.customer@example.invalid' ), $staff['headers'], 'staff reply goes only to the validated saved enquirer' );
		assert_same( 1, $result['db']->lookup_count, 'both messages reuse one location lookup' );
		assert_same( array( 41 ), $result['db']->prepared_location_ids, 'lookup uses saved location, not request location' );
		foreach ( array( 'CAT-SAVED-0041', 'Saved Revesby Bakery (ID 41)', 'Package: Custom', 'Guests: 18', 'Event date: 2031-11-04', 'Event time: 18:45', 'Order type: Pickup', 'Indicative total: AUD $212.40', 'Indicative deposit: AUD $63.72' ) as $saved_fact ) {
			assert_true( false !== strpos( $customer['message'], $saved_fact ), 'customer summary includes saved ' . $saved_fact );
			assert_true( false !== strpos( $staff['message'], $saved_fact ), 'staff summary includes saved ' . $saved_fact );
		}
		assert_true( false !== strpos( $customer['message'], 'Status: Awaiting staff confirmation' ), 'acknowledgement is not acceptance' );
		assert_true( false !== strpos( $customer['message'], 'not a confirmed booking' ), 'customer booking status is explicit' );
		assert_true( false !== strpos( $customer['message'], 'No payment has been taken by submitting this enquiry' ), 'submission does not imply a charge' );
		assert_true( false !== strpos( $customer['message'], 'Reply to this email' ), 'customer knows how to continue the enquiry' );
		foreach ( $result['mail'] as $mail ) {
			assert_same( 0, preg_match( '/your booking is confirmed|confirmed once.*deposit|secure Square payment link/i', $mail['message'] ), 'no automatic booking or future provider promise' );
			assert_same( false, strpos( $mail['message'], 'request.only@example.invalid' ), 'request-only contact data is not mixed into saved mail' );
			assert_same( false, strpos( $mail['message'], '11:11' ), 'request-only event time is ignored' );
		}
	}
);

db_test(
	'catering mail rejects CRLF and malformed enquirer addresses rather than constructing injected reply headers',
	function () {
		$name_only = db_fe_catering_notification( array( 'customer_name' => "Synthetic\r\nBcc: name@example.invalid" ) );
		assert_same( 2, count( $name_only['mail'] ), 'plain body name does not alter validated recipients' );
		assert_same( array( 'Content-Type: text/plain; charset=UTF-8', 'Reply-To: saved.customer@example.invalid' ), $name_only['mail'][1]['headers'], 'display-name input is never interpolated into reply headers' );
		foreach ( array( "saved.customer@example.invalid\r\nBcc: injected@example.invalid", "saved.customer@example.invalid\nBcc: injected@example.invalid", "saved.customer@example.invalid\rBcc: injected@example.invalid", 'not-an-email', 'One <saved.customer@example.invalid>' ) as $unsafe ) {
			$result = db_fe_catering_notification( array( 'customer_email' => $unsafe, 'customer_name' => "Synthetic\r\nBcc: name@example.invalid" ) );
			assert_same( 1, count( $result['mail'] ), 'unsafe customer address is not a recipient' );
			$staff = $result['mail'][0];
			assert_same( 'configured.catering@example.invalid', $staff['to'], 'staff still sees the saved enquiry' );
			assert_same( array( 'Content-Type: text/plain; charset=UTF-8' ), $staff['headers'], 'unsafe enquirer address is omitted, not repaired or invented' );
			foreach ( $staff['headers'] as $header ) {
				assert_same( false, strpos( $header, "\r" ), 'no CR can enter headers' );
				assert_same( false, strpos( $header, "\n" ), 'no LF can enter headers' );
			}
		}
	}
);

db_test(
	'catering optional fields and mail failure remain non-confirming without changing saved-enquiry semantics',
	function () {
		$fallback = db_fe_catering_notification( array( 'location_id' => 0, 'event_date' => '', 'event_time' => '' ) );
		assert_same( 0, $fallback['db']->lookup_count, 'missing saved location does not invent a fallback query' );
		foreach ( $fallback['mail'] as $mail ) {
			assert_true( false !== strpos( $mail['message'], 'Unavailable (saved location ID 0)' ), 'saved missing-location state remains honest' );
			assert_true( false !== strpos( $mail['message'], 'Event date: To be confirmed' ), 'unknown date is not manufactured' );
			assert_true( false !== strpos( $mail['message'], 'Event time: To be confirmed' ), 'unknown time is not manufactured' );
		}
		$missing = db_fe_catering_notification( array(), true, false );
		assert_true( false !== strpos( $missing['mail'][0]['message'], 'Unavailable (saved location ID 41)' ), 'deleted saved shop does not become another shop' );
		$failed = db_fe_catering_notification( array(), false );
		assert_same( 2, count( $failed['mail'] ), 'a customer mail failure does not prevent the staff attempt' );
		assert_same( null, $failed['returned'], 'notification failures do not become enquiry errors' );
		assert_same( array(), $failed['db']->rows, 'notification never rewrites or recreates an enquiry' );
	}
);
