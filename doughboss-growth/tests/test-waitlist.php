<?php
/**
 * WP-05 tests: VIP waitlist (storage, double opt-in, limits, privacy, retention, export, webhook) and the coming-soon
 * section (neutral copy, lint fallback, cards from confirmed claims only, ribbon).
 *
 * Runs against the harness's in-memory SQLite database with the module's REAL CREATE TABLE statements, so the UNIQUE
 * key, the conditional UPDATEs and the INSERT IGNORE race handling behave for real. No test makes a real request:
 * mail goes to the harness's mail log and the one webhook test uses the fake transport. The ES5 browser behaviour is
 * covered by waitlist.test.js and tilt-cards.test.js; the rendered page by web/scripts/wp-local/growth/wp05-waitlist.mjs.
 *
 * @package DoughBoss_Growth
 */

/** Placeholder sender used by every test. Not a real company; the plugin hard-codes no name at all. */
const DBGR_WL_SENDER = 'Example Trading Pty Ltd';

/**
 * Boot the module: SQLite with the real tables, settings saved as the owner would, shops, the module classes loaded.
 *
 * @param array $extra     Extra settings merged over the defaults.
 * @param array $features  Feature overrides (default: waitlist and coming_soon on).
 * @return void
 */
function dbgr_wl_boot( array $extra = array(), array $features = array() ) {
	$GLOBALS['wpdb']->use_sqlite();
	DoughBoss_Growth::load_module( 'ledger' );
	DoughBoss_Growth::load_module( 'waitlist' );
	DoughBoss_Growth::load_module( 'coming_soon' );
	foreach ( DoughBoss_Growth_Waitlist::schema() as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
	foreach ( DoughBoss_Growth_Rate_Limit::schema() as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
	foreach ( DoughBoss_Growth_Outbox::schema() as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
	update_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION, DOUGHBOSS_GROWTH_DB_VERSION );
	update_option(
		DoughBoss_Growth_Settings::OPTION,
		array_merge(
			array(
				'features'           => array_merge(
					array(
						'waitlist'    => true,
						'coming_soon' => true,
					),
					$features
				),
				'sender_legal_name'  => DBGR_WL_SENDER,
				'privacy_policy_url' => 'https://example.com.au/privacy/',
			),
			$extra
		)
	);
	DoughBoss_Locations::$throw = false;
	DoughBoss_Locations::$rows  = array(
		(object) array(
			'id'   => 1,
			'name' => 'Revesby',
			'slug' => 'revesby',
		),
		(object) array(
			'id'   => 2,
			'name' => 'Bankstown',
			'slug' => 'bankstown',
		),
		(object) array(
			'id'   => 3,
			'name' => 'Roselands',
			'slug' => 'roselands',
		),
	);
	$GLOBALS['dbgr_pages_by_path'] = array();
	DoughBoss_Growth_Waitlist::reset_state();
	DoughBoss_Growth_Ledger::reset();
	DoughBoss_Growth_Waitlist_Rest::register_routes();
	$GLOBALS['dbgr_wl_pin_ip'] = false;
	$GLOBALS['dbgr_wl_ip_n']   = 0;
}

/**
 * A form token that is old enough to be accepted.
 *
 * @return string
 */
function dbgr_wl_token() {
	$token = DoughBoss_Growth_Waitlist::issue_form_token();
	dbgr_test_advance( 4 );
	return $token;
}

/**
 * POST /waitlist with a valid token and consent unless overridden.
 *
 * @param array       $fields Fields to merge over the good defaults.
 * @param string|null $token  Token (null = a fresh valid one).
 * @return WP_REST_Response
 */
function dbgr_wl_post( array $fields = array(), $token = null ) {
	if ( empty( $GLOBALS['dbgr_wl_pin_ip'] ) ) {
		// A different visitor address for each request keeps the per-address limit out of the way of tests that are about something else.
		$GLOBALS['dbgr_wl_ip_n']++;
		$_SERVER['REMOTE_ADDR'] = '198.51.' . intdiv( $GLOBALS['dbgr_wl_ip_n'], 250 ) . '.' . ( 1 + ( $GLOBALS['dbgr_wl_ip_n'] % 250 ) );
	}
	$params = array_merge(
		array(
			'email'           => 'jordan@example.com',
			'first_name'      => '',
			'mobile'          => '',
			'store'           => '',
			'consent'         => 1,
			'consent_version' => DoughBoss_Growth_Waitlist::consent_version(),
			'website'         => '',
			'token'           => ( null === $token ) ? dbgr_wl_token() : $token,
			'path'            => '/coming-soon/',
		),
		$fields
	);
	return dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/waitlist', $params );
}

/**
 * Every waitlist row.
 *
 * @return array
 */
function dbgr_wl_rows() {
	return $GLOBALS['wpdb']->sqlite_raw( 'SELECT * FROM wp_doughboss_growth_waitlist ORDER BY id ASC' );
}

/**
 * Every suppression row.
 *
 * @return array
 */
function dbgr_wl_suppressed() {
	return $GLOBALS['wpdb']->sqlite_raw( 'SELECT * FROM wp_doughboss_growth_suppression ORDER BY email_hash ASC' );
}

/**
 * Pull the "i" and "t" query arguments out of the first link of a given kind in the last mail.
 *
 * @param string $kind confirm or unsubscribe.
 * @param int    $mail Index into the mail log.
 * @return array { i: string, t: string }
 */
function dbgr_wl_link_args( $kind, $mail = 0 ) {
	$body = $GLOBALS['dbgr_mail'][ $mail ]['message'];
	if ( 1 !== preg_match( '#https://doughboss\.test/\?dbgr_wl=' . $kind . '&i=([0-9]+)&t=([a-f0-9]+)#', $body, $m ) ) {
		return array(
			'i' => '',
			't' => '',
		);
	}
	return array(
		'i' => $m[1],
		't' => $m[2],
	);
}

/**
 * Sign someone up (and optionally confirm them), returning the link arguments from the mail.
 *
 * @param string $email   Address.
 * @param bool   $confirm Also confirm.
 * @return array { i, t, unsub_t }
 */
function dbgr_wl_join( $email, $confirm = false ) {
	$before = count( $GLOBALS['dbgr_mail'] );
	$r      = dbgr_wl_post( array( 'email' => $email ) );
	$out    = array(
		'status' => $r->get_status(),
		'i'      => '',
		't'      => '',
		'u'      => '',
	);
	if ( count( $GLOBALS['dbgr_mail'] ) > $before ) {
		$args     = dbgr_wl_link_args( 'confirm', $before );
		$unsub    = dbgr_wl_link_args( 'unsubscribe', $before );
		$out['i'] = $args['i'];
		$out['t'] = $args['t'];
		$out['u'] = $unsub['t'];
		if ( $confirm ) {
			DoughBoss_Growth_Waitlist::confirm( $args['i'], $args['t'] );
		}
	}
	return $out;
}

/**
 * Strip tags and entities so the visible words of rendered markup can be checked.
 *
 * @param string $html Markup.
 * @return string
 */
function dbgr_wl_visible_text( $html ) {
	return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
}

/* ---------------------------------------------------------------------------------------------------------- */
/* Gating: owner inputs, flag, storage                                                                         */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'gating: every feature is off by default and the waitlist and coming-soon module are inert with no settings',
	function () {
		DoughBoss_Growth::load_module( 'waitlist' );
		DoughBoss_Growth::load_module( 'coming_soon' );
		assert_false( DoughBoss_Growth_Settings::enabled( 'waitlist' ), 'waitlist off by default' );
		assert_false( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'coming soon off by default' );
		assert_false( DoughBoss_Growth_Waitlist::enabled(), 'waitlist module not enabled' );
		assert_same( '', DoughBoss_Growth_Waitlist::shortcode( array() ), 'form renders nothing while off' );
		assert_same( '', DoughBoss_Growth_Coming_Soon::shortcode( array() ), 'section renders nothing while off' );
		assert_same( '', DoughBoss_Growth_Waitlist::consent_text(), 'no consent wording without a sender name' );
	}
);

db_test(
	'gating: enabling the waitlist is refused while the sender legal name or the privacy-policy URL is empty (and no company name is built in)',
	function () {
		DoughBoss_Growth::load_module( 'waitlist' );
		$base    = array( 'features' => array( 'waitlist' => '1' ) );
		$none    = DoughBoss_Growth_Settings::apply_save( $base );
		assert_false( $none['settings']['features']['waitlist'], 'refused with neither' );
		assert_contains( 'waitlist_requires_sender_legal_name', implode( ',', $none['errors'] ), 'sender name is reported' );
		assert_contains( 'waitlist_requires_privacy_policy_url', implode( ',', $none['errors'] ), 'privacy URL is reported' );
		$name_only = DoughBoss_Growth_Settings::apply_save( array_merge( $base, array( 'sender_legal_name' => DBGR_WL_SENDER ) ) );
		assert_false( $name_only['settings']['features']['waitlist'], 'refused with only the name' );
		$url_only = DoughBoss_Growth_Settings::apply_save( array_merge( $base, array( 'privacy_policy_url' => 'https://example.com.au/privacy/' ) ) );
		assert_false( $url_only['settings']['features']['waitlist'], 'refused with only the URL' );
		$both = DoughBoss_Growth_Settings::apply_save( array_merge( $base, array( 'sender_legal_name' => DBGR_WL_SENDER, 'privacy_policy_url' => 'https://example.com.au/privacy/' ) ) );
		assert_true( $both['settings']['features']['waitlist'], 'accepted with both' );

		// The shipped plugin names no company anywhere in the module files.
		$dir = dirname( __DIR__ ) . '/includes/waitlist';
		foreach ( glob( $dir . '/*.php' ) as $file ) {
			$text = (string) file_get_contents( $file );
			assert_same( 0, preg_match( '/\b(gabelia|pty\.? ltd|dough boss express|charbel)\b/i', $text ), 'no hard-coded company or person in ' . basename( $file ) );
		}
	}
);

db_test(
	'gating: the module stays closed when the saved flag is on but the owner inputs or the tables are missing, or the sender name carries the working name',
	function () {
		dbgr_wl_boot();
		assert_true( DoughBoss_Growth_Waitlist::enabled(), 'control: complete configuration is enabled' );

		// Tables not confirmed.
		delete_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION );
		assert_false( DoughBoss_Growth_Waitlist::enabled(), 'no confirmed tables means closed' );
		update_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION, DOUGHBOSS_GROWTH_DB_VERSION );

		// A sender name holding the unannounced product's working name (built in two parts so this file carries no copy).
		$bad = 'Acme ' . 'Mi' . 'nis' . ' Pty Ltd';
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'waitlist' => true ), 'sender_legal_name' => $bad, 'privacy_policy_url' => 'https://example.com.au/privacy/' ) );
		assert_same( '', DoughBoss_Growth_Waitlist::sender_name(), 'a sender name with the working name is unusable' );
		assert_false( DoughBoss_Growth_Waitlist::enabled(), 'so the waitlist stays closed' );
		assert_same( '', DoughBoss_Growth_Waitlist::render_form(), 'and renders nothing' );

		// Kill switch (sub-process: a constant cannot be undefined).
		if ( dbgr_test_can_subprocess() ) {
			$run = dbgr_test_subprocess(
				"DoughBoss_Growth::load_module( 'waitlist' ); \$GLOBALS['wpdb']->use_sqlite(); update_option( 'doughboss_growth_db_version', DOUGHBOSS_GROWTH_DB_VERSION ); update_option( 'doughboss_growth_settings', array( 'features' => array( 'waitlist' => true ), 'sender_legal_name' => 'Example Trading Pty Ltd', 'privacy_policy_url' => 'https://example.com.au/privacy/' ) ); echo DoughBoss_Growth_Waitlist::enabled() ? 'ON' : 'OFF';",
				"define( 'DOUGHBOSS_GROWTH_DISABLE', true );"
			);
			assert_same( 'OFF', trim( $run['out'] ), 'the kill switch stops the waitlist' );
		} else {
			dbgr_test_skip( 'cannot start a sub-process here' );
		}
	}
);

db_test(
	'consent wording: names the sender, changes version when the sender changes, carries no digit, currency or product word',
	function () {
		dbgr_wl_boot();
		$text = DoughBoss_Growth_Waitlist::consent_text();
		assert_contains( DBGR_WL_SENDER, $text, 'names the sender' );
		assert_contains( 'withdraw', $text, 'says how to withdraw' );
		assert_matches( '/^wl-[a-f0-9]{12}$/D', DoughBoss_Growth_Waitlist::consent_version(), 'version shape' );
		assert_same( hash( 'sha256', $text ), DoughBoss_Growth_Waitlist::consent_hash(), 'hash is of the exact wording' );
		assert_same( array(), DoughBoss_Growth_Ledger::lint_public( $text, null ), 'passes the public-copy lint' );
		$before = DoughBoss_Growth_Waitlist::consent_version();
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'waitlist' => true ), 'sender_legal_name' => 'Another Trading Pty Ltd', 'privacy_policy_url' => 'https://example.com.au/privacy/' ) );
		assert_true( $before !== DoughBoss_Growth_Waitlist::consent_version(), 'a different sender is a new wording version' );
		assert_true( strlen( DoughBoss_Growth_Waitlist::consent_version() ) <= 20, 'fits the 20 character column' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* The form                                                                                                    */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'form: consent box is unticked and required, separate from everything else; honeypot, hidden version and token exist; no product-specific picker',
	function () {
		dbgr_wl_boot();
		$html = DoughBoss_Growth_Waitlist::render_form( array() );
		assert_true( '' !== $html, 'form renders' );
		assert_matches( '/<input type="checkbox"[^>]*name="consent"[^>]*value="1"[^>]*required/', $html, 'consent checkbox is required' );
		assert_same( 0, preg_match( '/<input type="checkbox"[^>]*checked/i', $html ), 'no checkbox is pre-ticked' );
		assert_same( 1, preg_match_all( '/type="checkbox"/', $html ), 'exactly one checkbox: consent only, no interest picker' );
		assert_contains( DBGR_WL_SENDER, $html, 'the consent wording names the sender' );
		assert_matches( '/name="website"[^>]*tabindex="-1"/', $html, 'honeypot field' );
		assert_matches( '/name="consent_version" value="wl-[a-f0-9]{12}"/', $html, 'consent version carried' );
		assert_matches( '/name="token"/', $html, 'token field (filled by script)' );
		assert_matches( '/type="email"[^>]*required/', $html, 'email required' );
		assert_matches( '/name="store"/', $html, 'store preference select' );
		assert_contains( 'No preference', $html, 'store preference is optional' );
		assert_same( 0, preg_match( '/interest|what are you|flavour|flavor|menu|price|\$|%/i', dbgr_wl_visible_text( $html ) ), 'no interest picker, product, menu or price words' );
		assert_same( 0, stripos( $html, 'mini' . 's' ) === false ? 0 : 1, 'the working name is nowhere in the form' );
		assert_contains( 'https://example.com.au/privacy/', $html, 'privacy link' );
		assert_contains( 'noscript', $html, 'a no-script notice' );
		assert_true( wp_script_is( 'dbgr-waitlist', 'enqueued' ), 'script enqueued' );
		assert_same( 'https://doughboss.test/wp-json/doughboss-growth/v1/form-token', $GLOBALS['dbgr_assets']['localized']['dbgr-waitlist']['DoughBossGrowthWaitlist']['tokenUrl'], 'token URL is localised' ) ;
	}
);

db_test(
	'form: a pre-selected store comes from the shortcode; shops core cannot supply remove the select and a posted store is refused',
	function () {
		dbgr_wl_boot();
		$html = DoughBoss_Growth_Waitlist::render_form( array( 'store' => 'bankstown' ) );
		assert_matches( '/<option value="2" data-slug="bankstown" selected="selected">Bankstown/', $html, 'bankstown pre-selected' );
		DoughBoss_Locations::$throw = true;
		$html = DoughBoss_Growth_Waitlist::render_form( array() );
		assert_same( 0, preg_match( '/<select/', $html ), 'no select when the shop list cannot be read' );
		$r = dbgr_wl_post( array( 'store' => '1' ) );
		assert_same( 400, $r->get_status(), 'a posted store is refused when the list is unreadable' );
		assert_same( array(), dbgr_wl_rows(), 'nothing stored' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Form token                                                                                                  */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'form token: younger than 3 seconds, older than 24 hours, forged and malformed tokens are refused; the window between is accepted',
	function () {
		dbgr_wl_boot();
		$token = DoughBoss_Growth_Waitlist::issue_form_token();
		assert_same( 'early', DoughBoss_Growth_Waitlist::check_form_token( $token ), 'issued just now: early' );
		dbgr_test_advance( 2 );
		assert_same( 'early', DoughBoss_Growth_Waitlist::check_form_token( $token ), '2 seconds: still early' );
		dbgr_test_advance( 1 );
		assert_same( 'ok', DoughBoss_Growth_Waitlist::check_form_token( $token ), '3 seconds: accepted' );
		dbgr_test_advance( 86400 - 3 );
		assert_same( 'ok', DoughBoss_Growth_Waitlist::check_form_token( $token ), 'exactly 24 hours: accepted' );
		dbgr_test_advance( 1 );
		assert_same( 'expired', DoughBoss_Growth_Waitlist::check_form_token( $token ), 'over 24 hours: expired' );

		// Negative controls.
		$parts  = explode( '.', DoughBoss_Growth_Waitlist::issue_form_token() );
		$forged = ( (int) $parts[0] - 100 ) . '.' . $parts[1];
		assert_same( 'invalid', DoughBoss_Growth_Waitlist::check_form_token( $forged ), 'a changed time with the old signature is invalid' );
		assert_same( 'invalid', DoughBoss_Growth_Waitlist::check_form_token( $parts[0] . '.' . str_repeat( '0', 32 ) ), 'a wrong signature is invalid' );
		foreach ( array( '', 'abc', '123.456', array( 'x' ), null, 12345, ( DBGR_TEST_EPOCH + 100 ) . '.' . $parts[1] ) as $bad ) {
			assert_same( 'invalid', DoughBoss_Growth_Waitlist::check_form_token( $bad ), 'malformed token refused: ' . var_export( $bad, true ) );
		}
		// A token from the future (clock skew or a forgery signed with the right key) is never accepted.
		$future = DoughBoss_Growth::now() + 500;
		$mac    = substr( hash_hmac( 'sha256', 'dbgr-wl-form|' . $future, wp_salt( 'nonce' ) ), 0, 32 );
		assert_same( 'early', DoughBoss_Growth_Waitlist::check_form_token( $future . '.' . $mac ), 'a correctly signed future token counts as too young' );
	}
);

db_test(
	'GET /form-token: public, no-store, a fresh token; unavailable (503) while the waitlist is closed',
	function () {
		dbgr_wl_boot();
		$r = dbgr_test_rest_dispatch( 'GET', '/doughboss-growth/v1/form-token' );
		assert_same( 200, $r->get_status(), '200' );
		$data = $r->get_data();
		assert_matches( '/^[0-9]{10}\.[a-f0-9]{32}$/D', $data['token'], 'token shape' );
		$headers = $r->get_headers();
		assert_same( 'no-store', $headers['Cache-Control'], 'never cached' );
		delete_option( DoughBoss_Growth_Settings::OPTION );
		$r = dbgr_test_rest_dispatch( 'GET', '/doughboss-growth/v1/form-token' );
		assert_same( 503, $r->get_status(), 'closed waitlist: 503' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* POST /waitlist                                                                                              */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'sign-up: stores one pending row with the consent evidence, sends one double opt-in email that names the sender and carries working links, stores no IP or user agent',
	function () {
		dbgr_wl_boot();
		$r = dbgr_wl_post(
			array(
				'email'      => '  Jordan@Example.com ',
				'first_name' => 'Jordan',
				'mobile'     => '0412 345 678',
				'store'      => '2',
			)
		);
		assert_same( 200, $r->get_status(), '200' );
		$data = $r->get_data();
		assert_true( $data['success'], 'success flag' );
		assert_same( 'no-store', $r->get_headers()['Cache-Control'], 'never cached' );

		$rows = dbgr_wl_rows();
		assert_count( 1, $rows, 'one row' );
		$row = $rows[0];
		assert_same( 'jordan@example.com', $row['email'], 'email lower-cased and trimmed' );
		assert_same( hash( 'sha256', 'jordan@example.com' ), $row['email_hash'], 'hash is sha256 of the normalised email' );
		assert_same( 'Jordan', $row['first_name'], 'first name' );
		assert_same( '+61412345678', $row['mobile_e164'], 'mobile normalised to E.164' );
		assert_same( 2, (int) $row['store_pref'], 'store preference' );
		assert_same( 1, (int) $row['consent_marketing'], 'consent recorded' );
		assert_same( DoughBoss_Growth_Waitlist::consent_version(), $row['consent_text_version'], 'consent version recorded' );
		assert_same( DoughBoss_Growth_Waitlist::consent_hash(), $row['consent_text_hash'], 'consent wording hash recorded' );
		assert_same( gmdate( 'Y-m-d H:i:s', DBGR_TEST_EPOCH + 4 ), $row['consent_at_utc'], 'consent time (UTC)' );
		assert_same( '/coming-soon/', $row['consent_source_path'], 'path only' );
		assert_same( 'pending', $row['status'], 'pending until confirmed' );
		assert_same( null, $row['interests_json'], 'no interests are collected' );
		assert_true( is_string( $row['confirm_token_hash'] ) && 64 === strlen( $row['confirm_token_hash'] ), 'only a hash of the confirm token is stored' );
		foreach ( array_keys( $row ) as $column ) {
			assert_same( 0, preg_match( '/^(ip|ip_address|user_agent|ua|remote_addr)$/', $column ), 'no IP or user-agent column: ' . $column );
		}
		assert_not_contains( '203.0.113.9', json_encode( $row ), 'the visitor address is nowhere in the row' );

		assert_count( 1, $GLOBALS['dbgr_mail'], 'one email' );
		$mail = $GLOBALS['dbgr_mail'][0];
		assert_same( 'jordan@example.com', $mail['to'], 'sent to the address' );
		assert_contains( DBGR_WL_SENDER, $mail['message'], 'names the sender' );
		assert_contains( 'https://example.com.au/privacy/', $mail['message'], 'privacy policy link' );
		$confirm = dbgr_wl_link_args( 'confirm' );
		assert_same( (string) $row['id'], $confirm['i'], 'confirm link carries the row id' );
		assert_same( hash( 'sha256', $confirm['t'] ), $row['confirm_token_hash'], 'confirm link token matches the stored hash' );
		assert_same( 40, strlen( $confirm['t'] ), 'a 160-bit token' );
		$unsub = dbgr_wl_link_args( 'unsubscribe' );
		assert_same( DoughBoss_Growth_Waitlist::unsubscribe_token( $row['id'], $row['email_hash'] ), $unsub['t'], 'opt-out link carries the derived token' );
		assert_contains( 'List-Unsubscribe: <https://doughboss.test/?dbgr_wl=unsubscribe', implode( "\n", $mail['headers'] ), 'List-Unsubscribe header' );
		assert_contains( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', implode( "\n", $mail['headers'] ), 'one-click header' );
		assert_same( 0, preg_match( '/mini' . 's|\$|price|menu/i', $mail['subject'] . ' ' . $mail['message'] ), 'no product, menu or price in the email' );
		assert_same( array(), $GLOBALS['wpdb']->unprepared, 'every statement was prepared' );
	}
);

db_test(
	'sign-up: optional fields are stored as NULL when left blank',
	function () {
		dbgr_wl_boot();
		dbgr_wl_post();
		$row = dbgr_wl_rows()[0];
		assert_same( null, $row['first_name'], 'no name stored' );
		assert_same( null, $row['mobile_e164'], 'no mobile stored' );
		assert_same( null, $row['store_pref'], 'no store stored' );
	}
);

db_test(
	'sign-up: honeypot filled gives the SAME success body, stores nothing, sends nothing and does not use a limiter slot',
	function () {
		dbgr_wl_boot();
		$real = dbgr_wl_post( array( 'email' => 'real@example.com' ) );
		$trap = dbgr_wl_post( array( 'email' => 'bot@example.com', 'website' => 'http://spam.example' ) );
		assert_same( 200, $trap->get_status(), 'fake success status' );
		assert_same( $real->get_data(), $trap->get_data(), 'identical body: a bot cannot tell' );
		assert_count( 1, dbgr_wl_rows(), 'only the real sign-up was stored' );
		assert_count( 1, $GLOBALS['dbgr_mail'], 'no email for the bot' );
		$buckets = $GLOBALS['wpdb']->sqlite_raw( "SELECT hits FROM wp_doughboss_growth_rate WHERE bucket_key LIKE 'ip:%'" );
		assert_same( 1, (int) $buckets[0]['hits'], 'the honeypot hit did not count against the address' );
		$r = dbgr_wl_post( array( 'email' => 'bot2@example.com', 'website' => '0' ) );
		assert_same( $real->get_data(), $r->get_data(), 'even the value "0" trips the trap' );
		assert_count( 1, dbgr_wl_rows(), 'still one row' );
	}
);

db_test(
	'sign-up: a token that is too young, too old, forged or missing is refused with 400 and nothing is stored',
	function () {
		dbgr_wl_boot();
		$young = DoughBoss_Growth_Waitlist::issue_form_token();
		$r     = dbgr_wl_post( array(), $young );
		assert_same( 400, $r->get_status(), 'younger than 3 s: 400' );
		assert_same( 'dbgr_token_early', $r->get_data()['code'], 'code' );
		$old = DoughBoss_Growth_Waitlist::issue_form_token();
		dbgr_test_advance( 86400 + 5 );
		$r = dbgr_wl_post( array(), $old );
		assert_same( 400, $r->get_status(), 'older than 24 h: 400' );
		assert_same( 'dbgr_token_invalid', $r->get_data()['code'], 'expired maps to the reload message' );
		$r = dbgr_wl_post( array(), 'forged' );
		assert_same( 400, $r->get_status(), 'forged: 400' );
		$r = dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/waitlist', array( 'email' => 'a@example.com', 'consent' => 1 ) );
		assert_same( 400, $r->get_status(), 'missing token: 400' );
		assert_same( array(), dbgr_wl_rows(), 'nothing stored' );
		assert_same( array(), $GLOBALS['dbgr_mail'], 'nothing sent' );
	}
);

db_test(
	'sign-up: consent must be an explicit 1; absent, 0, "on", "yes", "true", an array and a stale wording version are all refused',
	function () {
		dbgr_wl_boot();
		foreach ( array( 'absent' => null, 'zero' => 0, 'zero string' => '0', 'on' => 'on', 'yes' => 'yes', 'true string' => 'true', 'array' => array( 1 ), 'empty' => '' ) as $label => $value ) {
			$r = dbgr_wl_post( array( 'consent' => $value, 'email' => 'c' . md5( $label ) . '@example.com' ) );
			assert_same( 400, $r->get_status(), 'refused: ' . $label );
			assert_same( 'dbgr_consent_required', $r->get_data()['code'], 'consent message: ' . $label );
		}
		foreach ( array( 1, '1', true ) as $ok ) {
			assert_true( DoughBoss_Growth_Waitlist::consent_given( $ok ), 'accepted form of consent: ' . var_export( $ok, true ) );
		}
		$r = dbgr_wl_post( array( 'consent_version' => 'wl-000000000000' ) );
		assert_same( 400, $r->get_status(), 'stale wording refused' );
		assert_same( 'dbgr_consent_changed', $r->get_data()['code'], 'wording-changed code' );
		$r = dbgr_wl_post( array( 'consent_version' => null ) );
		assert_same( 400, $r->get_status(), 'missing wording version refused' );
		assert_same( array(), dbgr_wl_rows(), 'nothing stored without consent' );
		assert_same( array(), $GLOBALS['dbgr_mail'], 'nothing sent without consent' );
	}
);

db_test(
	'sign-up: email validation refuses empty, malformed, header-injection, over-long and non-string input',
	function () {
		dbgr_wl_boot();
		$bad = array(
			'empty'      => '',
			'no at'      => 'jordan.example.com',
			'no domain'  => 'jordan@',
			'newline'    => "a@example.com\nBcc: x@evil.test",
			'crlf'       => "a@example.com\r\nBcc: x@evil.test",
			'space'      => 'a b@example.com',
			'angle'      => '<a@example.com>',
			'comma'      => 'a@example.com,b@example.com',
			'semicolon'  => 'a@example.com;b@example.com',
			'quote'      => '"a"@example.com',
			'too long'   => str_repeat( 'a', 190 ) . '@example.com',
			'array'      => array( 'a@example.com' ),
		);
		foreach ( $bad as $label => $value ) {
			$r = dbgr_wl_post( array( 'email' => $value ) );
			assert_same( 400, $r->get_status(), 'refused: ' . $label );
			assert_same( 'dbgr_invalid_email', $r->get_data()['code'], 'email message: ' . $label );
		}
		assert_same( array(), dbgr_wl_rows(), 'nothing stored' );
		assert_same( array(), $GLOBALS['dbgr_mail'], 'nothing sent' );
		assert_same( 'jordan@example.com', DoughBoss_Growth_Waitlist::normalise_email( 'JORDAN@Example.COM' ), 'control: a normal address passes' );
	}
);

db_test(
	'sign-up: mobile is normalised to +61 E.164 or refused; store must be an active core shop',
	function () {
		dbgr_wl_boot();
		$good = array(
			'0412345678'      => '+61412345678',
			'0412 345 678'    => '+61412345678',
			'+61 412 345 678' => '+61412345678',
			'61412345678'     => '+61412345678',
			'412345678'       => '+61412345678',
			'(04) 1234-5678'  => '+61412345678',
			''                => '',
		);
		foreach ( $good as $in => $out ) {
			assert_same( $out, DoughBoss_Growth_Waitlist::normalise_mobile( (string) $in ), 'mobile ' . $in );
		}
		foreach ( array( '0212345678', '12345', '+1 415 555 0100', '0512345678', 'abc', '04123456789', array( '0412345678' ) ) as $bad ) {
			assert_same( false, DoughBoss_Growth_Waitlist::normalise_mobile( $bad ), 'refused mobile ' . var_export( $bad, true ) );
		}
		$r = dbgr_wl_post( array( 'mobile' => '0212345678' ) );
		assert_same( 'dbgr_invalid_mobile', $r->get_data()['code'], 'landline refused through the route' );

		$r = dbgr_wl_post( array( 'store' => '99' ) );
		assert_same( 400, $r->get_status(), 'an unknown shop is refused' );
		$r = dbgr_wl_post( array( 'store' => 'revesby' ) );
		assert_same( 400, $r->get_status(), 'a slug is not an id: refused' );
		$r = dbgr_wl_post( array( 'store' => '1; DROP TABLE x' ) );
		assert_same( 400, $r->get_status(), 'injection text refused' );
		assert_same( array(), dbgr_wl_rows(), 'nothing stored by any refused request' );
		$r = dbgr_wl_post( array( 'store' => 'none', 'email' => 'n@example.com' ) );
		assert_same( 200, $r->get_status(), '"none" means no preference' );
		assert_same( null, dbgr_wl_rows()[0]['store_pref'], 'stored as NULL' );
	}
);

db_test(
	'no account enumeration: a new address, a pending one, a confirmed one, an opted-out one and a suppressed one all get the identical answer; only new and pending are emailed',
	function () {
		dbgr_wl_boot();
		$new = dbgr_wl_post( array( 'email' => 'new@example.com' ) );
		assert_count( 1, $GLOBALS['dbgr_mail'], 'new: one email' );
		$pending = dbgr_wl_post( array( 'email' => 'new@example.com' ) );
		assert_count( 2, $GLOBALS['dbgr_mail'], 'pending: a fresh link is sent' );

		$c = dbgr_wl_join( 'confirmed@example.com', true );
		$mails = count( $GLOBALS['dbgr_mail'] );
		$confirmed = dbgr_wl_post( array( 'email' => 'confirmed@example.com' ) );
		assert_count( $mails, $GLOBALS['dbgr_mail'], 'confirmed: nothing sent' );

		$u = dbgr_wl_join( 'leaver@example.com', true );
		DoughBoss_Growth_Waitlist::unsubscribe( $u['i'], $u['u'] );
		$mails = count( $GLOBALS['dbgr_mail'] );
		$left = dbgr_wl_post( array( 'email' => 'leaver@example.com' ) );
		assert_count( $mails, $GLOBALS['dbgr_mail'], 'opted out: nothing sent' );

		$GLOBALS['wpdb']->sqlite_raw( "INSERT INTO wp_doughboss_growth_suppression (email_hash, reason, created_at) VALUES ('" . hash( 'sha256', 'blocked@example.com' ) . "', 'unsubscribed', '2026-01-01 00:00:00')" );
		$mails   = count( $GLOBALS['dbgr_mail'] );
		$blocked = dbgr_wl_post( array( 'email' => 'blocked@example.com' ) );
		assert_count( $mails, $GLOBALS['dbgr_mail'], 'suppressed: nothing sent' );

		foreach ( array( 'pending' => $pending, 'confirmed' => $confirmed, 'opted out' => $left, 'suppressed' => $blocked ) as $label => $response ) {
			assert_same( 200, $response->get_status(), $label . ' status equals a new sign-up' );
			assert_same( $new->get_data(), $response->get_data(), $label . ' body equals a new sign-up' );
		}
		$count = $GLOBALS['wpdb']->sqlite_raw( "SELECT COUNT(*) AS n FROM wp_doughboss_growth_waitlist WHERE email = 'blocked@example.com'" );
		assert_same( 0, (int) $count[0]['n'], 'a suppressed address is not stored again' );
		assert_same( 3, count( dbgr_wl_rows() ), 'three rows: new, confirmed, opted out' );
		unset( $c );
	}
);

db_test(
	'rate limits: 5 an hour per address, 3 a day per email, 300 a day for the whole form; each resets with its window',
	function () {
		dbgr_wl_boot();
		$GLOBALS['dbgr_wl_pin_ip'] = true;
		$_SERVER['REMOTE_ADDR']    = '203.0.113.50';
		for ( $i = 1; $i <= 5; $i++ ) {
			assert_same( 200, dbgr_wl_post( array( 'email' => 'u' . $i . '@example.com' ) )->get_status(), 'address attempt ' . $i . ' allowed' );
		}
		$sixth = dbgr_wl_post( array( 'email' => 'u6@example.com' ) );
		assert_same( 429, $sixth->get_status(), 'sixth in the hour: 429' );
		assert_same( 'dbgr_rate_limited', $sixth->get_data()['code'], 'code' );
		assert_true( (int) $sixth->get_headers()['Retry-After'] > 0, 'Retry-After is sent' );
		assert_count( 5, dbgr_wl_rows(), 'the sixth stored nothing' );
		dbgr_test_advance( 3700 );
		assert_same( 200, dbgr_wl_post( array( 'email' => 'u6@example.com' ) )->get_status(), 'a new hour allows it again' );

		// Per email: 3 a day (different addresses so the per-address limit does not interfere).
		$GLOBALS['wpdb']->sqlite_raw( 'DELETE FROM wp_doughboss_growth_rate' );
		for ( $i = 1; $i <= 3; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( 60 + $i );
			assert_same( 200, dbgr_wl_post( array( 'email' => 'same@example.com' ) )->get_status(), 'email attempt ' . $i );
		}
		$_SERVER['REMOTE_ADDR'] = '203.0.113.70';
		assert_same( 429, dbgr_wl_post( array( 'email' => 'same@example.com' ) )->get_status(), 'fourth for one email in a day: 429' );
		dbgr_test_advance( 86400 + 10 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.71';
		assert_same( 200, dbgr_wl_post( array( 'email' => 'same@example.com' ) )->get_status(), 'next day allowed' );

		// Global circuit breaker: 300 a day. Fill the bucket, then the next request from a fresh address is refused.
		$GLOBALS['wpdb']->sqlite_raw( 'DELETE FROM wp_doughboss_growth_rate' );
		$now          = DoughBoss_Growth::now();
		$window_start = $now - ( $now % 86400 );
		$GLOBALS['wpdb']->sqlite_raw( "INSERT INTO wp_doughboss_growth_rate (bucket_key, window_start, hits) VALUES ('wl:global', {$window_start}, 300)" );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.80';
		$tripped = dbgr_wl_post( array( 'email' => 'breaker@example.com' ) );
		assert_same( 429, $tripped->get_status(), 'breaker tripped: 429' );
		assert_count( 0, array_filter( dbgr_wl_rows(), function ( $r ) {
			return 'breaker@example.com' === $r['email'];
		} ), 'nothing stored while the breaker is open' );
	}
);

db_test(
	'fail closed: a limiter storage error, a failed insert and a failed email each give 503 and leave no half-made sign-up',
	function () {
		dbgr_wl_boot();
		// Limiter storage error.
		$GLOBALS['wpdb']->fail_on( '/doughboss_growth_rate/' );
		$r = dbgr_wl_post( array( 'email' => 'a@example.com' ) );
		assert_same( 503, $r->get_status(), 'limiter error: 503' );
		assert_same( 'dbgr_unavailable', $r->get_data()['code'], 'unavailable code' );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( array(), dbgr_wl_rows(), 'nothing stored after a limiter error' );

		// Insert failure.
		$GLOBALS['wpdb']->fail_on( '/INSERT IGNORE INTO wp_doughboss_growth_waitlist/' );
		$r = dbgr_wl_post( array( 'email' => 'b@example.com' ) );
		assert_same( 503, $r->get_status(), 'insert error: 503' );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( array(), dbgr_wl_rows(), 'nothing stored after an insert error' );
		assert_same( array(), $GLOBALS['dbgr_mail'], 'no email after an insert error' );

		// Email failure: the new row is removed again.
		$GLOBALS['dbgr_mail_fails'] = true;
		$r = dbgr_wl_post( array( 'email' => 'c@example.com' ) );
		assert_same( 503, $r->get_status(), 'mail error: 503' );
		assert_same( array(), dbgr_wl_rows(), 'the row is rolled back when the confirmation email cannot be sent' );
		$GLOBALS['dbgr_mail_fails'] = false;

		// A lookup error on the suppression table.
		$GLOBALS['wpdb']->fail_on( '/FROM wp_doughboss_growth_suppression/' );
		$r = dbgr_wl_post( array( 'email' => 'd@example.com' ) );
		assert_same( 503, $r->get_status(), 'suppression lookup error: 503' );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( array(), dbgr_wl_rows(), 'nothing stored without a safe suppression check' );

		// Control: the same request succeeds when storage is healthy.
		assert_same( 200, dbgr_wl_post( array( 'email' => 'e@example.com' ) )->get_status(), 'healthy storage works' );
		assert_count( 1, dbgr_wl_rows(), 'one row' );
	}
);

db_test(
	'the unique email key: a second concurrent insert for the same address is treated as a duplicate, never an error or a second row',
	function () {
		dbgr_wl_boot();
		$clean = DoughBoss_Growth_Waitlist::validate(
			array(
				'email'           => 'race@example.com',
				'consent'         => 1,
				'consent_version' => DoughBoss_Growth_Waitlist::consent_version(),
			)
		);
		assert_true( $clean['ok'], 'valid' );
		// Another request inserts the same address between our lookup and our insert.
		$GLOBALS['wpdb']->respond(
			'/^SELECT \* FROM wp_doughboss_growth_waitlist WHERE email_hash/',
			function () {
				static $first = true;
				if ( $first ) {
					$first = false;
					$GLOBALS['wpdb']->sqlite_raw( "INSERT INTO wp_doughboss_growth_waitlist (email, email_hash, consent_at_utc, status, created_at, updated_at) VALUES ('race@example.com', '" . hash( 'sha256', 'race@example.com' ) . "', '2026-10-02 00:00:00', 'pending', '2026-10-02 00:00:00', '2026-10-02 00:00:00')" );
				}
				return array();
			}
		);
		$result = DoughBoss_Growth_Waitlist::signup( $clean['clean'] );
		assert_same( 'noop', $result['result'], 'lost the race: a duplicate, not an error' );
		assert_count( 1, dbgr_wl_rows(), 'still exactly one row' );
		assert_same( array(), $GLOBALS['dbgr_mail'], 'the other request sends the email, not this one' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Confirm, opt-out and the email-link pages                                                                    */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'confirm: POST only; the token is single use; a wrong token, wrong id and an expired row are invalid; the confirmed hook and the row update happen once',
	function () {
		dbgr_wl_boot();
		$join = dbgr_wl_join( 'jordan@example.com' );
		$hits = 0;
		add_action(
			'doughboss_growth_waitlist_confirmed',
			function () use ( &$hits ) {
				$hits++;
			}
		);

		$get = dbgr_test_rest_dispatch( 'GET', '/doughboss-growth/v1/waitlist/confirm', array( 'id' => $join['i'], 'token' => $join['t'] ) );
		assert_same( 404, $get->get_status(), 'a GET cannot confirm: there is no GET route' );
		assert_same( 'pending', dbgr_wl_rows()[0]['status'], 'still pending after a GET' );

		$bad = dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/waitlist/confirm', array( 'id' => $join['i'], 'token' => str_repeat( 'a', 40 ) ) );
		assert_same( 400, $bad->get_status(), 'wrong token: 400' );
		$bad = dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/waitlist/confirm', array( 'id' => '9999', 'token' => $join['t'] ) );
		assert_same( 400, $bad->get_status(), 'unknown id: 400' );
		$bad = dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/waitlist/confirm', array( 'id' => $join['i'], 'token' => 'short' ) );
		assert_same( 400, $bad->get_status(), 'malformed token: 400' );
		$bad = dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/waitlist/confirm', array( 'id' => array( '1' ), 'token' => $join['t'] ) );
		assert_same( 400, $bad->get_status(), 'array id: 400' );
		assert_same( 'pending', dbgr_wl_rows()[0]['status'], 'still pending after every refusal' );
		assert_same( 0, $hits, 'hook not fired by refusals' );

		$ok = dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/waitlist/confirm', array( 'id' => $join['i'], 'token' => $join['t'] ) );
		assert_same( 200, $ok->get_status(), 'confirmed' );
		$row = dbgr_wl_rows()[0];
		assert_same( 'confirmed', $row['status'], 'status confirmed' );
		assert_same( null, $row['confirm_token_hash'], 'token cleared (single use)' );
		assert_same( gmdate( 'Y-m-d H:i:s', DoughBoss_Growth::now() ), $row['confirmed_at_utc'], 'confirmation time recorded' );
		assert_same( 1, $hits, 'hook fired once' );

		$again = dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/waitlist/confirm', array( 'id' => $join['i'], 'token' => $join['t'] ) );
		assert_same( 400, $again->get_status(), 'the same link a second time is refused' );
		assert_same( 1, $hits, 'hook still fired once' );
	}
);

db_test(
	'confirm: a resent link replaces the old one (the first link stops working); a database error never confirms',
	function () {
		dbgr_wl_boot();
		$first = dbgr_wl_join( 'jordan@example.com' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
		dbgr_wl_post( array( 'email' => 'jordan@example.com' ) );
		$second = dbgr_wl_link_args( 'confirm', 1 );
		assert_true( $first['t'] !== $second['t'], 'a new token' );
		assert_same( 'invalid', DoughBoss_Growth_Waitlist::confirm( $first['i'], $first['t'] )['result'], 'the first link no longer works' );
		$GLOBALS['wpdb']->fail_on( '/^UPDATE wp_doughboss_growth_waitlist SET status = \'confirmed\'/' );
		assert_same( 'error', DoughBoss_Growth_Waitlist::confirm( $second['i'], $second['t'] )['result'], 'a failed update is an error' );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( 'pending', dbgr_wl_rows()[0]['status'], 'not confirmed after the error' );
		assert_same( 'confirmed', DoughBoss_Growth_Waitlist::confirm( $second['i'], $second['t'] )['result'] === 'confirmed' ? 'confirmed' : 'x', 'the second link works once storage is healthy' );
	}
);

db_test(
	'email-link page: a GET only shows a button and changes nothing (a mail scanner is harmless); the POST confirms; bad links show a plain message',
	function () {
		dbgr_wl_boot();
		$join = dbgr_wl_join( 'jordan@example.com' );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array( 'dbgr_wl' => 'confirm', 'i' => $join['i'], 't' => $join['t'] );
		ob_start();
		DoughBoss_Growth_Waitlist::maybe_handle_link();
		$page = (string) ob_get_clean();
		assert_contains( 'Confirm', $page, 'a Confirm button is shown' );
		assert_contains( '<form method="post"', $page, 'the button posts' );
		assert_contains( DBGR_WL_SENDER, $page, 'the page names the sender' );
		assert_contains( 'noindex', $page, 'never indexed' );
		assert_contains( 'no-referrer', $page, 'sends no referrer (the link carries a secret)' );
		assert_same( 'pending', dbgr_wl_rows()[0]['status'], 'a GET changed nothing' );
		assert_same( 0, preg_match( '/mini' . 's/i', $page ), 'no working name on the page' );

		// HEAD behaves like GET.
		$_SERVER['REQUEST_METHOD'] = 'HEAD';
		ob_start();
		DoughBoss_Growth_Waitlist::maybe_handle_link();
		ob_end_clean();
		assert_same( 'pending', dbgr_wl_rows()[0]['status'], 'a HEAD changed nothing' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array( 'dbgr_wl' => 'confirm', 'i' => $join['i'], 't' => $join['t'] );
		ob_start();
		DoughBoss_Growth_Waitlist::maybe_handle_link();
		$done = (string) ob_get_clean();
		assert_contains( 'You are on the VIP list', $done, 'the POST confirms' );
		assert_same( 'confirmed', dbgr_wl_rows()[0]['status'], 'status confirmed by the POST' );

		$_POST = array( 'dbgr_wl' => 'confirm', 'i' => $join['i'], 't' => $join['t'] );
		ob_start();
		DoughBoss_Growth_Waitlist::maybe_handle_link();
		$again = (string) ob_get_clean();
		assert_contains( 'not valid or has already been used', $again, 'a second use is refused' );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		$_GET                      = array( 'dbgr_wl' => 'confirm', 'i' => 'x', 't' => 'y' );
		ob_start();
		DoughBoss_Growth_Waitlist::maybe_handle_link();
		$junk = (string) ob_get_clean();
		assert_contains( 'not valid', $junk, 'malformed link: plain message' );
		assert_not_contains( '<form', $junk, 'and no button' );

		$_GET = array( 'dbgr_wl' => 'something-else', 'i' => '1', 't' => 'a' );
		ob_start();
		DoughBoss_Growth_Waitlist::maybe_handle_link();
		assert_same( '', (string) ob_get_clean(), 'an unknown action prints nothing and is ignored' );
		$_GET = array();
		ob_start();
		DoughBoss_Growth_Waitlist::maybe_handle_link();
		assert_same( '', (string) ob_get_clean(), 'an ordinary request prints nothing' );
	}
);

db_test(
	'opt-out: works by POST on the link page and by the mail client one-click POST, is idempotent, records a suppression, and a wrong token for a real row looks the same as an unknown row',
	function () {
		dbgr_wl_boot();
		$join = dbgr_wl_join( 'leaver@example.com', true );

		// GET shows a button only.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array( 'dbgr_wl' => 'unsubscribe', 'i' => $join['i'], 't' => $join['u'] );
		ob_start();
		DoughBoss_Growth_Waitlist::maybe_handle_link();
		$page = (string) ob_get_clean();
		assert_contains( 'Unsubscribe', $page, 'button shown' );
		assert_same( 'confirmed', dbgr_wl_rows()[0]['status'], 'a GET does not unsubscribe' );

		// One-click POST: the arguments are in the URL, the body is only List-Unsubscribe=One-Click.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array( 'List-Unsubscribe' => 'One-Click' );
		ob_start();
		DoughBoss_Growth_Waitlist::maybe_handle_link();
		$done = (string) ob_get_clean();
		assert_contains( 'You have been unsubscribed', $done, 'one-click opt-out works' );
		$row = dbgr_wl_rows()[0];
		assert_same( 'unsubscribed', $row['status'], 'status unsubscribed' );
		assert_true( null !== $row['unsubscribed_at_utc'], 'time recorded' );
		assert_count( 1, dbgr_wl_suppressed(), 'a suppression hash exists' );
		assert_same( $row['email_hash'], dbgr_wl_suppressed()[0]['email_hash'], 'for this address' );

		// Idempotent.
		ob_start();
		DoughBoss_Growth_Waitlist::maybe_handle_link();
		assert_contains( 'You have been unsubscribed', (string) ob_get_clean(), 'a second opt-out is fine' );
		assert_count( 1, dbgr_wl_suppressed(), 'still one suppression row' );

		// Wrong token for a real row and any token for an unknown row give the same answer (no probing).
		$real_wrong = DoughBoss_Growth_Waitlist::unsubscribe( $join['i'], str_repeat( 'b', 32 ) );
		$unknown    = DoughBoss_Growth_Waitlist::unsubscribe( '9999', str_repeat( 'b', 32 ) );
		assert_same( $real_wrong, $unknown, 'identical result for a wrong token and an unknown id' );
		assert_same( 'invalid', $real_wrong['result'], 'invalid' );
		$r1 = dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/waitlist/unsubscribe', array( 'id' => $join['i'], 'token' => str_repeat( 'b', 32 ) ) );
		$r2 = dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/waitlist/unsubscribe', array( 'id' => '9999', 'token' => str_repeat( 'b', 32 ) ) );
		assert_same( $r1->get_data(), $r2->get_data(), 'the REST answers match too' );
		assert_same( 400, $r1->get_status(), '400' );
	}
);

db_test(
	'opt-out works while the waitlist flag is OFF (a person must always be able to leave) and a confirmation does not',
	function () {
		dbgr_wl_boot();
		$join = dbgr_wl_join( 'leaver@example.com', true );
		$second = dbgr_wl_join( 'pending@example.com' );
		// Switch the feature off. The module is still initialised (registry setting "always", see the hand-off note).
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'waitlist' => false ), 'sender_legal_name' => DBGR_WL_SENDER, 'privacy_policy_url' => 'https://example.com.au/privacy/' ) );
		$GLOBALS['dbgr_rest_routes'] = array();
		DoughBoss_Growth_Waitlist::init();
		do_action( 'rest_api_init' );
		assert_true( isset( $GLOBALS['dbgr_rest_routes']['/doughboss-growth/v1/waitlist/unsubscribe'] ), 'the opt-out route is registered with the flag off' );
		assert_false( isset( $GLOBALS['dbgr_rest_routes']['/doughboss-growth/v1/waitlist'] ), 'the sign-up route is not' );
		assert_false( isset( $GLOBALS['dbgr_rest_routes']['/doughboss-growth/v1/waitlist/confirm'] ), 'the confirm route is not' );
		$r = dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/waitlist/unsubscribe', array( 'id' => $join['i'], 'token' => $join['u'] ) );
		assert_same( 200, $r->get_status(), 'opt-out works with the flag off' );
		assert_same( 'unsubscribed', dbgr_wl_rows()[0]['status'], 'row opted out' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET                      = array();
		$_POST                     = array( 'dbgr_wl' => 'confirm', 'i' => $second['i'], 't' => $second['t'] );
		ob_start();
		DoughBoss_Growth_Waitlist::maybe_handle_link();
		$page = (string) ob_get_clean();
		assert_contains( 'not available', $page, 'confirming is refused while sign-ups are off' );
		assert_same( 'pending', dbgr_wl_rows()[1]['status'], 'the pending row stays pending' );
	}
);

db_test(
	'opt-out: an opted-out person who signs up again is not stored or emailed; the link page also fails closed on a storage error',
	function () {
		dbgr_wl_boot();
		$join = dbgr_wl_join( 'leaver@example.com', true );
		DoughBoss_Growth_Waitlist::unsubscribe( $join['i'], $join['u'] );
		$mails = count( $GLOBALS['dbgr_mail'] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.120';
		dbgr_wl_post( array( 'email' => 'leaver@example.com' ) );
		assert_count( $mails, $GLOBALS['dbgr_mail'], 'no email to someone who opted out' );
		assert_same( 'unsubscribed', dbgr_wl_rows()[0]['status'], 'still opted out' );

		$GLOBALS['wpdb']->fail_on( '/INSERT IGNORE INTO wp_doughboss_growth_suppression/' );
		$fresh = dbgr_wl_join( 'fresh@example.com', true );
		$r     = DoughBoss_Growth_Waitlist::unsubscribe( $fresh['i'], $fresh['u'] );
		assert_same( 'error', $r['result'], 'no opt-out is claimed unless the suppression is recorded' );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( 'confirmed', dbgr_wl_rows()[1]['status'], 'the row was not changed when the suppression failed' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Privacy: exporter and eraser                                                                                 */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'privacy: the exporter returns the stored data with no hash or token; the eraser removes every personal field; both are registered even with the flag off',
	function () {
		dbgr_wl_boot();
		$join = dbgr_wl_join( 'jordan@example.com', true );
		$GLOBALS['wpdb']->sqlite_raw( "UPDATE wp_doughboss_growth_waitlist SET first_name = 'Jordan', mobile_e164 = '+61412345678' WHERE id = " . (int) $join['i'] );

		update_option( DoughBoss_Growth_Settings::OPTION, array() ); // Flag off: the tooling must stay.
		DoughBoss_Growth_Waitlist::init();
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		assert_true( isset( $exporters['doughboss-growth-waitlist'] ), 'exporter registered with the flag off' );
		assert_true( isset( $erasers['doughboss-growth-waitlist'] ), 'eraser registered with the flag off' );

		$export = call_user_func( $exporters['doughboss-growth-waitlist']['callback'], 'JORDAN@example.com', 1 );
		assert_true( $export['done'], 'export done' );
		assert_count( 1, $export['data'], 'one group' );
		$flat = json_encode( $export['data'] );
		assert_contains( 'jordan@example.com', $flat, 'email exported' );
		assert_contains( '+61412345678', $flat, 'mobile exported' );
		assert_contains( 'confirmed', $flat, 'status exported' );
		assert_not_contains( hash( 'sha256', 'jordan@example.com' ), $flat, 'the hash is not exported' );
		assert_not_contains( 'confirm_token', $flat, 'no token field' );
		$none = call_user_func( $exporters['doughboss-growth-waitlist']['callback'], 'nobody@example.com', 1 );
		assert_same( array(), $none['data'], 'an unknown address exports nothing' );
		$junk = call_user_func( $exporters['doughboss-growth-waitlist']['callback'], "x@example.com\nBcc: y", 1 );
		assert_same( array(), $junk['data'], 'a malformed address exports nothing' );

		$erased = call_user_func( $erasers['doughboss-growth-waitlist']['callback'], 'jordan@example.com', 1 );
		assert_true( $erased['items_removed'], 'items removed' );
		assert_false( $erased['items_retained'], 'nothing retained for a person who never opted out' );
		assert_same( array(), dbgr_wl_rows(), 'the row, and every personal field in it, is gone' );
		assert_same( array(), dbgr_wl_suppressed(), 'no suppression for a person who never opted out (default)' );
		$nothing = call_user_func( $erasers['doughboss-growth-waitlist']['callback'], 'jordan@example.com', 1 );
		assert_false( $nothing['items_removed'], 'erasing again removes nothing' );
	}
);

db_test(
	'privacy: an opt-out is honoured after erasure (the hash stays and is reported as retained); the owner filter can also keep an erased person off the list',
	function () {
		dbgr_wl_boot();
		$join = dbgr_wl_join( 'leaver@example.com', true );
		DoughBoss_Growth_Waitlist::unsubscribe( $join['i'], $join['u'] );
		$erased = DoughBoss_Growth_Waitlist_Privacy::erase( 'leaver@example.com', 1 );
		assert_true( $erased['items_removed'], 'row removed' );
		assert_true( $erased['items_retained'], 'the opt-out hash is retained and reported' );
		assert_count( 1, $erased['messages'], 'with an explanation' );
		assert_same( array(), dbgr_wl_rows(), 'no personal row' );
		assert_count( 1, dbgr_wl_suppressed(), 'the suppression hash remains' );
		$mails = count( $GLOBALS['dbgr_mail'] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.130';
		dbgr_wl_post( array( 'email' => 'leaver@example.com' ) );
		assert_count( $mails, $GLOBALS['dbgr_mail'], 'suppression honoured after erasure: no email' );
		assert_same( array(), dbgr_wl_rows(), 'and nothing stored' );

		// Owner decision (default off): keep an erased person suppressed too.
		$other = dbgr_wl_join( 'erased@example.com', true );
		add_filter( 'doughboss_growth_waitlist_suppress_on_erase', '__return_true' );
		$erased = DoughBoss_Growth_Waitlist_Privacy::erase( 'erased@example.com', 1 );
		assert_true( $erased['items_retained'], 'retained when the owner filter says so' );
		assert_count( 2, dbgr_wl_suppressed(), 'two suppression rows' );
		assert_same( 'erased', dbgr_wl_suppressed()[0]['reason'] === 'erased' || dbgr_wl_suppressed()[1]['reason'] === 'erased' ? 'erased' : 'none', 'recorded with the reason erased' );
		unset( $other );
	}
);

db_test(
	'privacy: an eraser database error erases nothing and says so (never a false "removed")',
	function () {
		dbgr_wl_boot();
		dbgr_wl_join( 'jordan@example.com', true );
		$GLOBALS['wpdb']->fail_on( '/^DELETE FROM wp_doughboss_growth_waitlist/' );
		$r = DoughBoss_Growth_Waitlist_Privacy::erase( 'jordan@example.com', 1 );
		assert_false( $r['items_removed'], 'not removed' );
		assert_true( $r['items_retained'], 'reported as retained' );
		assert_count( 1, $r['messages'], 'with a message' );
		$GLOBALS['wpdb']->clear_failures();
		assert_count( 1, dbgr_wl_rows(), 'row still there' );
		$GLOBALS['wpdb']->fail_on( '/^SELECT \* FROM wp_doughboss_growth_waitlist WHERE email_hash/' );
		$r = DoughBoss_Growth_Waitlist_Privacy::erase( 'jordan@example.com', 1 );
		assert_false( $r['items_removed'], 'a lookup error removes nothing' );
		assert_true( $r['items_retained'], 'and is reported' );
		$GLOBALS['wpdb']->clear_failures();
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Retention                                                                                                    */
/* ---------------------------------------------------------------------------------------------------------- */

/**
 * Insert a row directly with chosen dates.
 *
 * @param string      $email     Address.
 * @param string      $status    Status.
 * @param string      $created   Created (UTC datetime).
 * @param string|null $stamp     confirmed or unsubscribed time (UTC datetime).
 * @return void
 */
function dbgr_wl_seed( $email, $status, $created, $stamp = null ) {
	$hash = hash( 'sha256', $email );
	$conf = ( 'confirmed' === $status && null !== $stamp ) ? "'" . $stamp . "'" : 'NULL';
	$unsub = ( 'unsubscribed' === $status && null !== $stamp ) ? "'" . $stamp . "'" : 'NULL';
	$GLOBALS['wpdb']->sqlite_raw( "INSERT INTO wp_doughboss_growth_waitlist (email, email_hash, consent_marketing, consent_at_utc, status, confirmed_at_utc, unsubscribed_at_utc, created_at, updated_at) VALUES ('{$email}', '{$hash}', 1, '{$created}', '{$status}', {$conf}, {$unsub}, '{$created}', '{$created}')" );
}

db_test(
	'retention: pending rows go after the configured days; opted-out rows become a suppression hash after 30 days; confirmed rows are NEVER deleted until the owner sets months',
	function () {
		dbgr_wl_boot();
		$now = DoughBoss_Growth::now();
		$ago = function ( $days ) use ( $now ) {
			return gmdate( 'Y-m-d H:i:s', $now - ( $days * 86400 ) );
		};
		dbgr_wl_seed( 'old-pending@example.com', 'pending', $ago( 31 ) );
		dbgr_wl_seed( 'new-pending@example.com', 'pending', $ago( 29 ) );
		dbgr_wl_seed( 'old-left@example.com', 'unsubscribed', $ago( 100 ), $ago( 31 ) );
		dbgr_wl_seed( 'new-left@example.com', 'unsubscribed', $ago( 100 ), $ago( 29 ) );
		dbgr_wl_seed( 'ancient@example.com', 'confirmed', $ago( 4000 ), $ago( 3999 ) );
		$out = DoughBoss_Growth_Waitlist::purge();
		assert_same( 1, $out['pending'], 'one old pending row purged' );
		assert_same( 1, $out['unsubscribed'], 'one old opted-out row reduced' );
		assert_same( 0, $out['confirmed'], 'no confirmed row is deleted while months is unset' );
		$emails = array_column( dbgr_wl_rows(), 'email' );
		sort( $emails );
		assert_same( array( 'ancient@example.com', 'new-left@example.com', 'new-pending@example.com' ), $emails, 'the right rows remain' );
		$sup = array_column( dbgr_wl_suppressed(), 'email_hash' );
		assert_same( array( hash( 'sha256', 'old-left@example.com' ) ), $sup, 'the reduced row left a suppression hash' );

		// A shorter pending window comes from the setting.
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'waitlist' => true ), 'sender_legal_name' => DBGR_WL_SENDER, 'privacy_policy_url' => 'https://example.com.au/privacy/', 'retention_pending_days' => 7 ) );
		$out = DoughBoss_Growth_Waitlist::purge();
		assert_same( 1, $out['pending'], 'a 29-day-old pending row goes when the window is 7 days' );

		// Months set: old confirmed rows go, recent ones stay.
		dbgr_wl_seed( 'recent-confirmed@example.com', 'confirmed', $ago( 10 ), $ago( 9 ) );
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'waitlist' => true ), 'sender_legal_name' => DBGR_WL_SENDER, 'privacy_policy_url' => 'https://example.com.au/privacy/', 'retention_confirmed_months' => 12 ) );
		$out = DoughBoss_Growth_Waitlist::purge();
		assert_same( 1, $out['confirmed'], 'only the confirmed row older than 12 months goes' );
		assert_true( in_array( 'recent-confirmed@example.com', array_column( dbgr_wl_rows(), 'email' ), true ), 'the recent confirmed row stays' );
		assert_same( array(), $GLOBALS['wpdb']->unprepared, 'every statement was prepared' );
	}
);

db_test(
	'retention: a database error purges nothing and throws nothing; an opted-out row is never dropped unless its suppression hash was recorded',
	function () {
		dbgr_wl_boot();
		$now = DoughBoss_Growth::now();
		$ago = function ( $days ) use ( $now ) {
			return gmdate( 'Y-m-d H:i:s', $now - ( $days * 86400 ) );
		};
		dbgr_wl_seed( 'old-pending@example.com', 'pending', $ago( 40 ) );
		dbgr_wl_seed( 'old-left@example.com', 'unsubscribed', $ago( 100 ), $ago( 40 ) );
		$GLOBALS['wpdb']->fail_all();
		$out = DoughBoss_Growth_Waitlist::purge();
		$GLOBALS['wpdb']->clear_failures();
		assert_same( array( 'pending' => 0, 'unsubscribed' => 0, 'confirmed' => 0 ), $out, 'all zero on error' );
		assert_count( 2, dbgr_wl_rows(), 'nothing deleted on error' );

		$GLOBALS['wpdb']->fail_on( '/INSERT IGNORE INTO wp_doughboss_growth_suppression/' );
		$out = DoughBoss_Growth_Waitlist::purge();
		$GLOBALS['wpdb']->clear_failures();
		assert_same( 0, $out['unsubscribed'], 'not reduced when the hash could not be recorded' );
		assert_true( in_array( 'old-left@example.com', array_column( dbgr_wl_rows(), 'email' ), true ), 'the row (and so the opt-out) is kept' );
		assert_same( 1, $out['pending'], 'the pending purge is independent' );
	}
);

db_test(
	'retention: the daily purge is scheduled and hooked when the waitlist is on, and the callback stays hooked afterwards',
	function () {
		dbgr_wl_boot();
		DoughBoss_Growth_Waitlist::init();
		assert_true( false !== wp_next_scheduled( 'doughboss_growth_retention_purge' ), 'scheduled' );
		assert_true( has_action( 'doughboss_growth_retention_purge', array( 'DoughBoss_Growth_Waitlist', 'purge' ) ) !== false, 'callback hooked' );
		assert_true( has_action( 'template_redirect', array( 'DoughBoss_Growth_Waitlist', 'maybe_handle_link' ) ) !== false, 'link handler hooked' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Staff export                                                                                                 */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'CSV export: only the chosen status, no hash or token, and a spreadsheet formula is neutralised',
	function () {
		dbgr_wl_boot();
		$a = dbgr_wl_join( 'a@example.com', true );
		dbgr_wl_join( 'b@example.com', false );
		$GLOBALS['wpdb']->sqlite_raw( "UPDATE wp_doughboss_growth_waitlist SET first_name = '=HYPERLINK(\"x\")', mobile_e164 = '+61412345678' WHERE id = " . (int) $a['i'] );
		$csv = DoughBoss_Growth_Waitlist::build_csv( 'confirmed' );
		assert_true( is_string( $csv ), 'built' );
		$lines = array_filter( explode( "\n", trim( $csv ) ) );
		assert_count( 2, $lines, 'header plus one confirmed row' );
		assert_contains( 'a@example.com', $csv, 'the confirmed address' );
		assert_not_contains( 'b@example.com', $csv, 'pending rows are not in the confirmed export' );
		assert_not_contains( hash( 'sha256', 'a@example.com' ), $csv, 'no email hash' );
		assert_not_contains( 'confirm_token', $csv, 'no token column' );
		assert_contains( "'=HYPERLINK", $csv, 'formula neutralised with a leading quote' );
		assert_contains( "'+61412345678", $csv, 'a leading plus is neutralised too' );
		$all = DoughBoss_Growth_Waitlist::build_csv( 'all' );
		assert_count( 3, array_filter( explode( "\n", trim( $all ) ) ), 'all: header plus two rows' );
		assert_same( null, DoughBoss_Growth_Waitlist::build_csv( 'nonsense' ), 'an unknown status is refused' );
		$GLOBALS['wpdb']->fail_all();
		assert_same( null, DoughBoss_Growth_Waitlist::build_csv( 'all' ), 'a database error builds nothing' );
		$GLOBALS['wpdb']->clear_failures();
		foreach ( array( '=1+1', '+1', '-1', '@x', "\tx", "\rx" ) as $cell ) {
			assert_same( "'" . $cell, DoughBoss_Growth_Waitlist::csv_cell( $cell ), 'neutralised: ' . json_encode( $cell ) );
		}
		assert_same( 'plain', DoughBoss_Growth_Waitlist::csv_cell( 'plain' ), 'control: plain text unchanged' );
	}
);

db_test(
	'CSV export handler: capability AND nonce on the way in; nothing is printed to a visitor or with a bad nonce',
	function () {
		dbgr_wl_boot();
		dbgr_wl_join( 'a@example.com', true );
		$_POST    = array( 'status' => 'confirmed' );
		$_REQUEST = $_POST;
		assert_throws( 'DBGR_Test_Die', function () {
			DoughBoss_Growth_Waitlist::handle_export();
		}, 'a visitor is refused' );
		dbgr_test_login( array( 'edit_posts' ) );
		assert_throws( 'DBGR_Test_Die', function () {
			DoughBoss_Growth_Waitlist::handle_export();
		}, 'a user without the capability is refused' );
		dbgr_test_login( array( 'manage_options' ) );
		assert_throws( 'DBGR_Test_Die', function () {
			DoughBoss_Growth_Waitlist::handle_export();
		}, 'the right capability but no nonce is refused' );
		$_POST    = array( 'status' => 'confirmed', '_wpnonce' => 'forged' );
		$_REQUEST = $_POST;
		assert_throws( 'DBGR_Test_Die', function () {
			DoughBoss_Growth_Waitlist::handle_export();
		}, 'a forged nonce is refused' );
		$_POST    = array( 'status' => 'confirmed', '_wpnonce' => dbgr_test_nonce( 'doughboss_growth_export_waitlist' ) );
		$_REQUEST = $_POST;
		ob_start();
		DoughBoss_Growth_Waitlist::handle_export();
		$csv = (string) ob_get_clean();
		assert_contains( 'a@example.com', $csv, 'a manager with a valid nonce gets the file' );
		assert_contains( 'id,email,first_name', $csv, 'with its header row' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Notification webhook                                                                                         */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'webhook: off unless a URL and a secret exist; when on, a confirmation queues id, store and time only (no email, no name); delivery is signed over the exact body',
	function () {
		dbgr_wl_boot( array( 'notify_webhook_url' => 'https://hooks.example-receiver.com.au/waitlist' ) );
		$join = dbgr_wl_join( 'jordan@example.com', false );
		DoughBoss_Growth_Waitlist::confirm( $join['i'], $join['t'] );
		$rows = $GLOBALS['wpdb']->sqlite_raw( 'SELECT * FROM wp_doughboss_growth_outbox' );
		assert_count( 0, $rows, 'no secret configured: nothing queued' );

		putenv( 'DOUGHBOSS_GROWTH_WEBHOOK_SECRET=test-secret-value-1234' );
		$join2 = dbgr_wl_join( 'second@example.com', false );
		$GLOBALS['wpdb']->sqlite_raw( 'UPDATE wp_doughboss_growth_waitlist SET store_pref = 2 WHERE id = ' . (int) $join2['i'] );
		assert_same( 'confirmed', DoughBoss_Growth_Waitlist::confirm( $join2['i'], $join2['t'] )['result'], 'confirmed' );
		$rows = $GLOBALS['wpdb']->sqlite_raw( 'SELECT * FROM wp_doughboss_growth_outbox' );
		assert_count( 1, $rows, 'one outbox row' );
		assert_same( 'waitlist_hook', $rows[0]['channel'], 'its own channel' );
		assert_same( 'waitlist:' . $join2['i'], $rows[0]['event_id'], 'idempotent event id' );
		$payload = json_decode( $rows[0]['payload_json'], true );
		assert_same( array( 'event', 'id', 'store_pref', 'confirmed_at' ), array_keys( $payload ), 'exactly these fields' );
		assert_same( 'waitlist.confirmed', $payload['event'], 'event name' );
		assert_same( 2, $payload['store_pref'], 'store' );
		assert_not_contains( '@', $rows[0]['payload_json'], 'no email in the payload' );
		assert_not_contains( 'second', $rows[0]['payload_json'], 'no name or address fragment in the payload' );

		// Delivery through the fake transport; the signature covers the exact body.
		$body = wp_json_encode( $payload );
		dbgr_test_http_expect( 'https://hooks.example-receiver.com.au/waitlist', dbgr_test_http_response( 200, '{}' ) );
		$row            = $rows[0];
		$row['payload'] = $payload;
		assert_same( true, DoughBoss_Growth_Waitlist::deliver_webhook( $row ), 'delivered' );
		dbgr_test_http_assert_done( 'the webhook call was made' );
		$calls = dbgr_test_http_calls();
		assert_count( 1, $calls, 'exactly one outbound call' );
		assert_same( 'POST', $calls[0]['method'], 'POST' );
		assert_same( $body, $calls[0]['args']['body'], 'the exact body is sent' );
		assert_same( 'sha256=' . hash_hmac( 'sha256', $body, 'test-secret-value-1234' ), $calls[0]['args']['headers']['X-DoughBoss-Growth-Signature'], 'HMAC signature header' );
		assert_same( 'application/json', $calls[0]['args']['headers']['Content-Type'], 'JSON' );
		assert_not_contains( 'test-secret-value-1234', json_encode( $calls[0]['args'] ), 'the secret itself is never sent' );
		putenv( 'DOUGHBOSS_GROWTH_WEBHOOK_SECRET' );
	}
);

db_test(
	'webhook: a client error parks the row, a server error retries, a missing secret or URL is terminal, and an unsafe URL is never called',
	function () {
		dbgr_wl_boot( array( 'notify_webhook_url' => 'https://hooks.example-receiver.com.au/waitlist' ) );
		putenv( 'DOUGHBOSS_GROWTH_WEBHOOK_SECRET=test-secret-value-1234' );
		$row = array( 'payload' => array( 'event' => 'waitlist.confirmed', 'id' => 1, 'store_pref' => null, 'confirmed_at' => '2026-10-02T00:00:00Z' ) );

		dbgr_test_http_expect( 'https://hooks.example-receiver.com.au/waitlist', dbgr_test_http_response( 400, '{}' ) );
		$r = DoughBoss_Growth_Waitlist::deliver_webhook( $row );
		assert_true( is_wp_error( $r ) && true === $r->get_error_data()['terminal'], '400 is terminal' );

		dbgr_test_http_expect( 'https://hooks.example-receiver.com.au/waitlist', dbgr_test_http_response( 503, '{}' ) );
		$r = DoughBoss_Growth_Waitlist::deliver_webhook( $row );
		assert_true( is_wp_error( $r ) && false === $r->get_error_data()['terminal'], '503 is retried' );

		dbgr_test_http_expect( 'https://hooks.example-receiver.com.au/waitlist', dbgr_test_http_response( 429, '{}' ) );
		$r = DoughBoss_Growth_Waitlist::deliver_webhook( $row );
		assert_true( is_wp_error( $r ) && false === $r->get_error_data()['terminal'], '429 is retried' );

		putenv( 'DOUGHBOSS_GROWTH_WEBHOOK_SECRET' );
		$r = DoughBoss_Growth_Waitlist::deliver_webhook( $row );
		assert_true( is_wp_error( $r ) && true === $r->get_error_data()['terminal'], 'no secret: terminal, and no request was made' );
		putenv( 'DOUGHBOSS_GROWTH_WEBHOOK_SECRET=test-secret-value-1234' );
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'waitlist' => true ), 'sender_legal_name' => DBGR_WL_SENDER, 'privacy_policy_url' => 'https://example.com.au/privacy/' ) );
		$r = DoughBoss_Growth_Waitlist::deliver_webhook( $row );
		assert_true( is_wp_error( $r ) && true === $r->get_error_data()['terminal'], 'no URL: terminal' );
		putenv( 'DOUGHBOSS_GROWTH_WEBHOOK_SECRET' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Coming-soon section                                                                                          */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'coming soon: the default section is neutral, names no product, price, size, date or location, and "grep -i minis" finds nothing in any rendered output',
	function () {
		dbgr_wl_boot();
		$section = DoughBoss_Growth_Coming_Soon::shortcode( array() );
		assert_contains( 'Something exciting is coming', $section, 'default headline' );
		assert_contains( 'Be first to know', $section, 'default text' );
		$everything = $section . DoughBoss_Growth_Coming_Soon::render_ribbon() . DoughBoss_Growth_Waitlist::render_form( array() );
		assert_same( 0, preg_match( '/mini' . 's/i', $everything ), 'grep -i minis finds nothing in the section, ribbon and form' );
		$visible = dbgr_wl_visible_text( DoughBoss_Growth_Coming_Soon::shortcode( array( 'form' => '0' ) ) );
		assert_same( '', trim( str_replace( array( 'Something exciting is coming', 'Be first to know' ), '', $visible ) ), 'with no form the only words are the two neutral lines' );
		assert_same( 0, preg_match( '/[0-9$%]|halal|vegan|gluten|certified|best|price|menu|pack|size|box|dozen/i', $visible ), 'no digit, currency, dietary, price, size or pack word' );
		assert_matches( '/<h2[^>]*>Something exciting is coming<\/h2>/', $section, 'a real heading' );
		assert_matches( '/aria-labelledby="dbgr-cs-[0-9]+-title"/', $section, 'labelled region' );
		assert_same( 0, preg_match( '/<li class="dbgr-card"/', $section ), 'no card without a confirmed claim' );
		assert_true( wp_script_is( 'dbgr-waitlist', 'enqueued' ), 'the section script is enqueued' );
		assert_false( wp_script_is( 'dbgr-tilt-cards', 'enqueued' ), 'the tilt script is NOT loaded when there are no cards' );
	}
);

db_test(
	'coming soon: admin-edited headline and text are used when they pass the public-copy lint, and fall back to the neutral default when they name a product, a price, a size, a date, a number or a dietary claim',
	function () {
		dbgr_wl_boot( array( 'coming_soon_headline' => 'Watch this space', 'coming_soon_body' => 'Join the list for a first look' ) );
		assert_same( 'Watch this space', DoughBoss_Growth_Coming_Soon::headline(), 'a clean edit is used' );
		assert_same( 'Join the list for a first look', DoughBoss_Growth_Coming_Soon::body(), 'a clean edit is used (text)' );
		$bad = array(
			'a number'     => 'Launching in 2 weeks',
			'a price'      => 'Only $5 each',
			'a percent'    => '50% more',
			'halal'        => 'Halal and fresh',
			'vegan'        => 'Vegan friendly',
			'gluten'       => 'Gluten aware',
			'best'         => 'The best is coming',
			'certified'    => 'Certified fresh',
			'number one'   => 'Number one in Sydney',
			'working name' => 'Our new ' . 'Mi' . 'nis' . ' are here',
		);
		foreach ( $bad as $label => $text ) {
			update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'coming_soon' => true ), 'coming_soon_headline' => $text, 'coming_soon_body' => $text ) );
			// The settings sanitiser already refuses the working name; the lint catches the rest at render time.
			assert_same( 'Something exciting is coming', DoughBoss_Growth_Coming_Soon::headline(), 'headline falls back: ' . $label );
			assert_same( 'Be first to know', DoughBoss_Growth_Coming_Soon::body(), 'text falls back: ' . $label );
		}
	}
);

db_test(
	'coming soon: a stored setting that bypasses the sanitiser (a direct database edit) still cannot put a banned word on the page',
	function () {
		dbgr_wl_boot();
		// Write the option raw so only the render-time lint stands between the text and the page.
		$GLOBALS['dbgr_options']['doughboss_growth_settings']['coming_soon_headline'] = 'Prices from $4';
		$GLOBALS['dbgr_options']['doughboss_growth_settings']['coming_soon_body']     = 'Open 7 days';
		$html = DoughBoss_Growth_Coming_Soon::shortcode( array() );
		assert_contains( 'Something exciting is coming', $html, 'neutral headline' );
		assert_not_contains( '$4', $html, 'no price' );
		assert_not_contains( '7 days', $html, 'no number' );
	}
);

db_test(
	'coming soon: cards render only for confirmed, sourced, lint-clean ledger claims; an unconfirmed claim and a missing claim render nothing; an invalid ledger switches the whole section off',
	function () {
		dbgr_wl_boot();
		$dir  = sys_get_temp_dir() . '/dbgr-wl-' . getmypid() . '-' . mt_rand();
		mkdir( $dir );
		$file = $dir . '/claims.json';
		file_put_contents(
			$file,
			json_encode(
				array(
					'version' => 1,
					'claims'  => array(
						array(
							'id'        => 'first-look',
							'text'      => 'A first look for the list',
							'confirmed' => true,
							'source'    => array( 'kind' => 'owner-confirmed', 'ref' => 'Elie, in person', 'confirmedOn' => '2026-10-02' ),
						),
						array(
							'id'        => 'unconfirmed-claim',
							'text'      => 'Not yet confirmed',
							'confirmed' => false,
						),
						array(
							'id'        => 'dietary-claim',
							'text'      => 'Halal certified',
							'confirmed' => true,
							'source'    => array( 'kind' => 'owner-confirmed', 'ref' => 'Elie', 'confirmedOn' => '2026-10-02' ),
						),
					),
				)
			)
		);
		DoughBoss_Growth_Ledger::set_file_override( $file );
		DoughBoss_Growth_Ledger::init();
		DoughBoss_Growth_Coming_Soon::init();

		$none = DoughBoss_Growth_Coming_Soon::shortcode( array() );
		assert_same( 0, preg_match( '/dbgr-card/', $none ), 'no cards unless claim ids are supplied' );

		$html = DoughBoss_Growth_Coming_Soon::shortcode( array( 'cards' => 'first-look,unconfirmed-claim,dietary-claim,not-in-ledger,Bad_Id' ) );
		assert_same( 1, preg_match_all( '/<li class="dbgr-card" data-dbgr-tilt>/', $html ), 'exactly one card: the confirmed, clean claim' );
		assert_contains( 'A first look for the list', $html, 'its text' );
		assert_not_contains( 'Not yet confirmed', $html, 'an unconfirmed claim is not shown' );
		assert_not_contains( 'Halal', $html, 'a claim failing the lint is not shown even when confirmed' );
		assert_same( 0, preg_match( '/style="[^"]*(transform|rotate)/', $html ), 'no inline motion in the markup' );
		assert_true( wp_script_is( 'dbgr-tilt-cards', 'enqueued' ), 'the tilt script loads when a card exists' );

		add_filter(
			'doughboss_growth_coming_soon_claim_ids',
			function () {
				return array( 'unconfirmed-claim' );
			}
		);
		$blocks = DoughBoss_Growth_Ledger::hidden_blocks();
		assert_count( 1, $blocks, 'the ledger tab can list the hidden card block' );
		assert_same( 'coming-soon', $blocks[0]['page'], 'page' );
		assert_same( array( 'unconfirmed-claim' ), $blocks[0]['missing'], 'the missing claim' );

		// An invalid ledger (duplicate id) switches coming_soon off at runtime.
		file_put_contents( $file, json_encode( array( 'version' => 1, 'claims' => array( array( 'id' => 'dup', 'text' => 'A', 'confirmed' => false ), array( 'id' => 'dup', 'text' => 'B', 'confirmed' => false ) ) ) ) );
		DoughBoss_Growth_Ledger::reset();
		assert_false( DoughBoss_Growth_Coming_Soon::enabled(), 'invalid ledger: the feature is off' );
		assert_same( '', DoughBoss_Growth_Coming_Soon::shortcode( array() ), 'and renders nothing' );
		DoughBoss_Growth_Ledger::set_file_override( null );
		unlink( $file );
		rmdir( $dir );
	}
);

db_test(
	'coming soon: the section embeds the waitlist form only when the waitlist is on, and form="0" leaves it out',
	function () {
		dbgr_wl_boot();
		assert_contains( 'data-dbgr-waitlist', DoughBoss_Growth_Coming_Soon::shortcode( array() ), 'form inside the section' );
		assert_not_contains( 'data-dbgr-waitlist', DoughBoss_Growth_Coming_Soon::shortcode( array( 'form' => '0' ) ), 'form="0" leaves it out' );
		dbgr_wl_boot( array(), array( 'waitlist' => false ) );
		$html = DoughBoss_Growth_Coming_Soon::shortcode( array() );
		assert_not_contains( 'data-dbgr-waitlist', $html, 'no form while the waitlist is off' );
		assert_contains( 'Something exciting is coming', $html, 'the teaser still shows' );
	}
);

db_test(
	'coming soon: surface "home" marks the section for coming_soon_view; any other value does not',
	function () {
		dbgr_wl_boot();
		assert_contains( 'data-dbgr-surface="home"', DoughBoss_Growth_Coming_Soon::shortcode( array( 'surface' => 'home' ) ), 'home marked' );
		assert_not_contains( 'data-dbgr-surface', DoughBoss_Growth_Coming_Soon::shortcode( array( 'surface' => 'elsewhere' ) ), 'other values ignored' );
		assert_not_contains( 'data-dbgr-surface', DoughBoss_Growth_Coming_Soon::shortcode( array() ), 'default: not marked' );
	}
);

db_test(
	'ribbon: off by default (the home hero output is untouched); when switched on it is appended after the home hero only, never to another variant or tag',
	function () {
		dbgr_wl_boot();
		DoughBoss_Growth_Coming_Soon::init();
		$hero = '<section class="db-manoush-hero">HERO</section>';
		add_shortcode(
			'doughboss_manoush_hero',
			function ( $atts ) use ( $hero ) {
				return $hero;
			}
		);
		$out = do_shortcode( '[doughboss_manoush_hero variant="home"]' );
		assert_same( $hero, $out, 'ribbon off: the hero output is byte-identical' );

		update_option( DoughBoss_Growth_Coming_Soon::OPTION, array( 'ribbon' => 1 ) );
		$GLOBALS['dbgr_hooks'] = $GLOBALS['dbgr_hooks_baseline'];
		DoughBoss_Growth_Coming_Soon::init();
		$out = do_shortcode( '[doughboss_manoush_hero variant="home"]' );
		assert_true( 0 === strpos( $out, $hero ), 'the hero comes first, unchanged' );
		assert_contains( 'dbgr-cs--ribbon', $out, 'the ribbon follows' );
		assert_contains( 'data-dbgr-surface="home"', $out, 'marked for coming_soon_view' );
		assert_contains( 'Something exciting is coming', $out, 'neutral headline' );
		assert_same( 0, preg_match( '/mini' . 's/i', $out ), 'no working name in the ribbon' );
		assert_same( 0, preg_match( '/<a /', substr( $out, strlen( $hero ) ) ), 'no link while there is no published coming-soon page' );
		assert_same( $hero, do_shortcode( '[doughboss_manoush_hero variant="catering"]' ), 'other variants are untouched' );
		assert_same( $hero, do_shortcode( '[doughboss_manoush_hero]' ), 'no variant: untouched' );

		// Link only when the waitlist is on and the page is published.
		$GLOBALS['dbgr_pages_by_path']['coming-soon'] = (object) array( 'post_status' => 'draft', 'link' => 'https://doughboss.test/coming-soon/' );
		assert_same( 0, preg_match( '/<a /', substr( do_shortcode( '[doughboss_manoush_hero variant="home"]' ), strlen( $hero ) ) ), 'a draft page is not linked' );
		$GLOBALS['dbgr_pages_by_path']['coming-soon']->post_status = 'publish';
		assert_contains( 'href="https://doughboss.test/coming-soon/"', do_shortcode( '[doughboss_manoush_hero variant="home"]' ), 'a published page is linked' );

		// The ribbon never breaks the hero: a flag turned off removes it again.
		delete_option( DoughBoss_Growth_Settings::OPTION );
		assert_same( $hero, do_shortcode( '[doughboss_manoush_hero variant="home"]' ), 'features off: only the hero' );
		assert_same( $hero, DoughBoss_Growth_Coming_Soon::filter_hero( $hero, 'doughboss_manoush_hero', 'not-an-array' ), 'a non-array attribute value is tolerated' );
		assert_same( 'plain', DoughBoss_Growth_Coming_Soon::filter_hero( 'plain', 'other_tag', array( 'variant' => 'home' ) ), 'another shortcode is untouched' );
	}
);

db_test(
	'ribbon switch: capability AND nonce are checked; a valid save writes only the companion option and redirects',
	function () {
		dbgr_wl_boot();
		$_POST    = array( 'ribbon' => '1' );
		$_REQUEST = $_POST;
		assert_throws( 'DBGR_Test_Die', function () {
			DoughBoss_Growth_Coming_Soon::handle_save();
		}, 'a visitor is refused' );
		dbgr_test_login( array( 'manage_options' ) );
		assert_throws( 'DBGR_Test_Die', function () {
			DoughBoss_Growth_Coming_Soon::handle_save();
		}, 'no nonce is refused' );
		assert_false( DoughBoss_Growth_Coming_Soon::ribbon_enabled(), 'still off' );
		$_POST    = array( 'ribbon' => '1', '_wpnonce' => dbgr_test_nonce( 'doughboss_growth_save_coming_soon' ) );
		$_REQUEST = $_POST;
		assert_throws( 'DBGR_Test_Redirect', function () {
			DoughBoss_Growth_Coming_Soon::handle_save();
		}, 'a valid save redirects' );
		assert_true( DoughBoss_Growth_Coming_Soon::ribbon_enabled(), 'now on' );
		$_POST    = array( '_wpnonce' => dbgr_test_nonce( 'doughboss_growth_save_coming_soon' ) );
		$_REQUEST = $_POST;
		assert_throws( 'DBGR_Test_Redirect', function () {
			DoughBoss_Growth_Coming_Soon::handle_save();
		}, 'an unticked box redirects' );
		assert_false( DoughBoss_Growth_Coming_Soon::ribbon_enabled(), 'switched off again' );
		foreach ( array( 'yes', 'on', '2', 1, true ) as $junk ) {
			update_option( DoughBoss_Growth_Coming_Soon::OPTION, array( 'ribbon' => $junk ) );
			$expected = ( 1 === $junk || true === $junk );
			assert_same( $expected, DoughBoss_Growth_Coming_Soon::ribbon_enabled(), 'stored value ' . var_export( $junk, true ) );
		}
		update_option( DoughBoss_Growth_Coming_Soon::OPTION, 'garbage' );
		assert_false( DoughBoss_Growth_Coming_Soon::ribbon_enabled(), 'a corrupt option is off' );
	}
);

db_test(
	'admin tabs: the VIP waitlist and Coming soon tabs render for a manager, name every [CONFIRM] gap, and show no personal data',
	function () {
		dbgr_wl_boot();
		dbgr_wl_join( 'jordan@example.com', true );
		dbgr_test_set_admin( true );
		dbgr_test_login( array( 'manage_options' ) );
		DoughBoss_Growth_Waitlist::init();
		DoughBoss_Growth_Coming_Soon::init();
		do_action( 'doughboss_growth_admin_tabs' );
		ob_start();
		DoughBoss_Growth_Waitlist::render_tab();
		$tab = (string) ob_get_clean();
		assert_contains( 'VIP waitlist', $tab, 'tab heading' );
		assert_contains( DBGR_WL_SENDER, $tab, 'sender shown' );
		assert_contains( '[CONFIRM: sender contact details', $tab, 'the contact-details gap is listed' );
		assert_contains( '[CONFIRM: how long confirmed sign-ups are kept', $tab, 'the retention gap is listed' );
		assert_contains( 'name="action" value="doughboss_growth_export_waitlist"', $tab, 'export form' );
		assert_contains( '_wpnonce', $tab, 'export form carries a nonce' );
		assert_not_contains( 'jordan@example.com', $tab, 'no email on the screen' );
		ob_start();
		DoughBoss_Growth_Coming_Soon::render_tab();
		$tab2 = (string) ob_get_clean();
		assert_contains( 'Home ribbon', $tab2, 'ribbon switch' );
		assert_contains( 'name="action" value="doughboss_growth_save_coming_soon"', $tab2, 'ribbon form' );
		assert_same( 0, preg_match( '/mini' . 's/i', preg_replace( '/administrator|administration/i', '', $tab . $tab2 ) ), 'no working name in the admin tabs' );
		$gaps = DoughBoss_Growth_Waitlist::confirm_gaps();
		foreach ( $gaps as $code => $text ) {
			assert_matches( '/^\[CONFIRM: /', $text, 'gap text starts with [CONFIRM: ' . $code );
		}
		assert_true( isset( $gaps['sender_contact'], $gaps['consent_wording'], $gaps['suppression'], $gaps['client_ip'], $gaps['resubscribe'] ), 'every owner decision is listed' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Registry and schema                                                                                          */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'registry: the two module entries load, expose schema() for both tables, and the real CREATE TABLE statements run (dbDelta format)',
	function () {
		$modules = DoughBoss_Growth::modules();
		assert_same( 'DoughBoss_Growth_Waitlist', $modules['waitlist']['class'], 'waitlist entry class' );
		assert_same( 'DoughBoss_Growth_Coming_Soon', $modules['coming_soon']['class'], 'coming soon entry class' );
		assert_true( DoughBoss_Growth::load_module( 'waitlist' ), 'waitlist loads (with its two extra files)' );
		assert_true( class_exists( 'DoughBoss_Growth_Waitlist_Rest' ) && class_exists( 'DoughBoss_Growth_Waitlist_Privacy' ), 'extra classes loaded' );
		assert_true( DoughBoss_Growth::load_module( 'coming_soon' ), 'coming soon loads' );
		$schemas = DoughBoss_Growth::schemas();
		$tables  = DoughBoss_Growth_Activator::expected_tables( $schemas );
		assert_true( in_array( 'wp_doughboss_growth_waitlist', $tables, true ), 'waitlist table is in the schema set' );
		assert_true( in_array( 'wp_doughboss_growth_suppression', $tables, true ), 'suppression table is in the schema set' );
		foreach ( DoughBoss_Growth_Waitlist::schema() as $sql ) {
			assert_matches( '/PRIMARY KEY  \(/', $sql, 'dbDelta format: two spaces after PRIMARY KEY' );
		}
		assert_matches( '/UNIQUE KEY email_hash \(email_hash\)/', DoughBoss_Growth_Waitlist::schema()[0], 'unique email hash' );
		assert_same( array(), array_diff( $tables, array_map( function ( $s ) {
			return 'wp_' . $s;
		}, DoughBoss_Growth_Activator::TABLE_SUFFIXES ) ), 'every table name is on the activator whitelist (uninstall plan safe)' );
	}
);

db_test(
	'registry end to end: through DoughBoss_Growth::init() with the flags on, the shortcodes, routes and hooks exist; with them off nothing changes on the page',
	function () {
		dbgr_wl_boot();
		$GLOBALS['dbgr_rest_routes'] = array();
		DoughBoss_Growth::init();
		do_action( 'rest_api_init' );
		assert_true( shortcode_exists( 'doughboss_growth_waitlist' ), 'waitlist shortcode' );
		assert_true( shortcode_exists( 'doughboss_growth_coming_soon' ), 'coming-soon shortcode' );
		assert_true( isset( $GLOBALS['dbgr_rest_routes']['/doughboss-growth/v1/waitlist'] ), 'sign-up route' );
		assert_true( isset( $GLOBALS['dbgr_rest_routes']['/doughboss-growth/v1/form-token'] ), 'token route' );
		$page = do_shortcode( '[doughboss_growth_coming_soon]' );
		assert_contains( 'Something exciting is coming', $page, 'the section renders through the shortcode' );
		assert_same( 0, preg_match( '/mini' . 's/i', $page ), 'grep -i minis on the rendered page finds nothing' );
	}
);

db_test(
	'inert: with both flags off a page holding the shortcodes shows nothing (never the raw tag), and the module registers no sign-up route or form',
	function () {
		$GLOBALS['wpdb']->use_sqlite();
		DoughBoss_Growth::load_module( 'waitlist' );
		DoughBoss_Growth::load_module( 'coming_soon' );
		$GLOBALS['dbgr_rest_routes'] = array();
		DoughBoss_Growth_Waitlist::init();
		do_action( 'rest_api_init' );
		assert_same( '', do_shortcode( 'A [doughboss_growth_waitlist] B [doughboss_growth_coming_soon] C' ) === 'A  B  C' ? '' : 'raw tag leaked', 'both shortcodes vanish rather than print raw text' );
		assert_false( isset( $GLOBALS['dbgr_rest_routes']['/doughboss-growth/v1/waitlist'] ), 'no sign-up route' );
		assert_false( isset( $GLOBALS['dbgr_rest_routes']['/doughboss-growth/v1/form-token'] ), 'no token route' );
		assert_same( false, wp_next_scheduled( 'doughboss_growth_retention_purge' ), 'no cron scheduled' );
	}
);

db_test(
	'hygiene: no module file contains the working name, a hard-coded provider host, a direct HTTP call or a 3D, canvas or frame-sprite construct',
	function () {
		$dir   = dirname( __DIR__ );
		$files = array_merge( glob( $dir . '/includes/waitlist/*.php' ), array( $dir . '/public/js/dbgr-waitlist.js', $dir . '/public/js/dbgr-tilt-cards.js', $dir . '/public/css/dbgr-coming-soon.css' ) );
		assert_same( 7, count( $files ), 'the seven shipped WP-05 files exist' );
		foreach ( $files as $file ) {
			$text = (string) file_get_contents( $file );
			assert_same( 0, preg_match( '/mini' . 's/i', $text ), 'no working name in ' . basename( $file ) );
			assert_same( 0, preg_match( '/\bwp_(safe_)?remote_/', $text ), 'no direct HTTP in ' . basename( $file ) );
			assert_same( 0, preg_match( '/<canvas|getContext|' . 'web' . 'gl|three' . '\.js|sprite/i', $text ), 'no canvas, 3D or sprite code in ' . basename( $file ) );
			assert_same( 0, preg_match( '/https?:\/\/(www\.)?(google|facebook|googletagmanager|square)/i', $text ), 'no provider host in ' . basename( $file ) );
			if ( '.php' === substr( $file, -4 ) ) {
				assert_matches( "/defined\\(\\s*'ABSPATH'\\s*\\)/", $text, 'ABSPATH guard in ' . basename( $file ) );
			}
		}
		$css = (string) file_get_contents( $dir . '/public/css/dbgr-coming-soon.css' );
		assert_matches( '/@media \(prefers-reduced-motion: reduce\)\s*\{[^}]*\.dbgr-card__face\s*\{[^}]*transform:\s*none/s', $css, 'tilt cards are flat under reduced motion (CSS)' );
		$tilt = (string) file_get_contents( $dir . '/public/js/dbgr-tilt-cards.js' );
		assert_contains( 'prefers-reduced-motion: reduce', $tilt, 'and the script checks the setting live' );
	}
);
