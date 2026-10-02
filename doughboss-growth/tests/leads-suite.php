<?php
/**
 * WP-07 test suite body: corporate lead form and party-pack sizer. Not picked up by run.php on its own (the name does not
 * start with "test-"): tests/test-leads.php runs it in a separate PHP process and replays the results. The suite loads the
 * real attribution module (a class cannot be unloaded, and the WP-03 consent test asserts the consent module is NOT loaded
 * when it starts), so it must not share a process with the other test files.
 *
 * Covers: everything off by default; the form markup (core's field names, unticked marketing box, honeypot, no product
 * word, no currency); the consent wording and its version; the assets and the browser configuration; the lead record
 * written through the harness's REAL REST dispatcher against a stand-in for core's enquiry route (a rejected enquiry
 * leaves no lead_meta row, consent is stored with its version and UTC time only when ticked); the sizer's package list
 * and guidance rules; the admin tab. The ES5 browser behaviour is covered by lead-form.test.js and party-sizer.test.js;
 * the real rendered page and the real core form by web/scripts/wp-local/growth/wp07-leads.mjs.
 *
 * @package DoughBoss_Growth
 */

/** Placeholder sender used by the tests. Not a real company; the plugin hard-codes no name at all. */
const DBGR_LD_SENDER = 'Example Trading Pty Ltd';

DoughBoss_Growth::load_module( 'ledger' );
DoughBoss_Growth::load_module( 'landing' );
DoughBoss_Growth::load_module( 'attribution' );
DoughBoss_Growth::load_module( 'leads' );

/**
 * Boot the module: SQLite with the real lead_meta table, settings saved as the owner would, shops and real packages.
 *
 * @param array $features Feature overrides (default: lead_form on).
 * @param array $extra    Extra settings.
 * @param bool  $storage  Whether the storage version is recorded (false proves the fail-closed path).
 * @return void
 */
function dbgr_ld_boot( array $features = array(), array $extra = array(), $storage = true ) {
	$GLOBALS['wpdb']->use_sqlite();
	foreach ( DoughBoss_Growth_Attribution::schema() as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
	if ( $storage ) {
		update_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION, DOUGHBOSS_GROWTH_DB_VERSION );
	}
	update_option(
		DoughBoss_Growth_Settings::OPTION,
		array_merge(
			array(
				'features'           => array_merge( array( 'lead_form' => true ), $features ),
				'sender_legal_name'  => DBGR_LD_SENDER,
				'privacy_policy_url' => 'https://example.com.au/privacy/',
			),
			$extra
		)
	);
	DoughBoss_Locations::$throw = false;
	DoughBoss_Locations::$rows  = array(
		(object) array( 'id' => 1, 'name' => 'Revesby', 'slug' => 'revesby' ),
		(object) array( 'id' => 2, 'name' => 'Bankstown', 'slug' => 'bankstown' ),
		(object) array( 'id' => 3, 'name' => 'Roselands', 'slug' => 'roselands' ),
	);
	$GLOBALS['dbgr_posts']     = array();
	$GLOBALS['dbgr_post_meta'] = array();
	dbgr_landing_package( 11, 'Small Platter', 120, 8, 12 );
	dbgr_landing_package( 12, 'Large Platter', 300, 25, 40 );
	dbgr_landing_package( 13, 'Medium Platter', 200, 13, 24 );
	DoughBoss_Growth_Landing::reset_cache();
	DoughBoss_Growth_Ledger::set_file_override( null );
	DoughBoss_Growth_Ledger::reset();
	DoughBoss_Growth_Leads::reset_state();
	DoughBoss_Growth_Party_Sizer::reset_state();
	DoughBoss_Growth_Attribution::reset_state();
	$GLOBALS['dbgr_ld_seq']   = 0;
	$GLOBALS['dbgr_ld_fired'] = array();
	add_action(
		'doughboss_growth_lead_recorded',
		function ( $id, $meta ) {
			$GLOBALS['dbgr_ld_fired'][] = array( $id, $meta );
		},
		10,
		2
	);
}

/** Render the lead form shortcode. */
function dbgr_ld_form( $atts = '' ) {
	DoughBoss_Growth_Leads::init();
	return do_shortcode( '[doughboss_growth_lead_form' . ( '' === $atts ? '' : ' ' . $atts ) . ']' );
}

/** Stand in for core's enquiry route: nonce, honeypot, rate limit, validation, then the created action. */
function dbgr_ld_register_core_route() {
	register_rest_route(
		'doughboss/v1',
		'/catering/enquiry',
		array(
			'methods'             => 'POST',
			'permission_callback' => function ( $request ) {
				return 'good' === $request->get_header( 'X-WP-Nonce' )
					? true
					: new WP_Error( 'doughboss_bad_nonce', 'Session expired.', array( 'status' => 403 ) );
			},
			'callback'            => function ( $request ) {
				if ( 'limit' === $request->get_param( 'scenario' ) ) {
					return new WP_Error( 'doughboss_rate_limit', 'Too many requests.', array( 'status' => 429 ) );
				}
				if ( '' !== trim( (string) $request->get_param( 'hp' ) ) ) {
					return rest_ensure_response( array( 'success' => true, 'enquiry_number' => '' ) );
				}
				$email = sanitize_email( (string) $request->get_param( 'customer_email' ) );
				if ( '' === trim( (string) $request->get_param( 'customer_name' ) ) || '' === $email ) {
					return new WP_Error( 'doughboss_catering_invalid', 'A name and a valid email are required.', array( 'status' => 400 ) );
				}
				$GLOBALS['dbgr_ld_seq']++;
				$id  = 200 + $GLOBALS['dbgr_ld_seq'];
				$row = array(
					'enquiry_number' => 'E' . $id,
					'customer_email' => $email,
				);
				do_action( 'doughboss_catering_enquiry_created', $id, $row );
				return rest_ensure_response( array( 'success' => true, 'enquiry_number' => 'E' . $id ) );
			},
		)
	);
}

/** POST an enquiry as the lead form does. */
function dbgr_ld_enquire( array $params = array(), $nonce = 'good' ) {
	return dbgr_test_rest_dispatch(
		'POST',
		'/doughboss/v1/catering/enquiry',
		array_merge(
			array(
				'customer_name'    => 'Test Person',
				'customer_email'   => 'person@example.com',
				'dbgr_company'     => 'Acme Pty Ltd',
				'dbgr_segment'     => 'corporate',
				'dbgr_landing_key' => 'catering-corporate',
			),
			$params
		),
		array( 'X-WP-Nonce' => $nonce )
	);
}

/** @return array Rows of the lead_meta table. */
function dbgr_ld_rows() {
	return $GLOBALS['wpdb']->sqlite_raw( 'SELECT * FROM ' . $GLOBALS['wpdb']->prefix . 'doughboss_growth_lead_meta ORDER BY id' );
}

/** The wording version the form currently carries. */
function dbgr_ld_version() {
	return DoughBoss_Growth_Leads::consent_version();
}

/** Digit runs in a string. */
function dbgr_ld_numbers( $text ) {
	preg_match_all( '/[0-9]+(?:\.[0-9]+)?/', $text, $m );
	return $m[0];
}

/* ---------------------------------------------------------------------------------------------------------- */
/* Off by default                                                                                              */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'defaults: every flag is off, so init registers nothing and no shortcode renders',
	function () {
		$GLOBALS['wpdb']->use_sqlite();
		assert_false( DoughBoss_Growth_Settings::enabled( 'lead_form' ), 'lead_form is off by default' );
		assert_false( DoughBoss_Growth_Settings::enabled( 'party_sizer' ), 'party_sizer is off by default' );
		DoughBoss_Growth_Leads::init();
		assert_false( shortcode_exists( 'doughboss_growth_lead_form' ), 'lead form shortcode not registered' );
		assert_false( shortcode_exists( 'doughboss_growth_party_sizer' ), 'sizer shortcode not registered' );
		assert_false( has_filter( 'doughboss_catering_enquiry_created', array( 'DoughBoss_Growth_Attribution', 'on_enquiry_created' ) ), 'no enquiry hook' );
		assert_same( array(), $GLOBALS['dbgr_assets']['scripts'], 'no script queued' );
	}
);

db_test(
	'registry: the leads module is wired to lead_form and party_sizer and needs storage',
	function () {
		$modules = DoughBoss_Growth::modules();
		assert_true( isset( $modules['leads'] ), 'leads module registered' );
		assert_same( array( 'lead_form', 'party_sizer' ), $modules['leads']['features'], 'flags' );
		assert_true( $modules['leads']['needs_storage'], 'needs storage' );
		assert_true( is_file( DOUGHBOSS_GROWTH_DIR . $modules['leads']['file'] ), 'entry file exists' );
		foreach ( $modules['leads']['files'] as $extra ) {
			assert_true( is_file( DOUGHBOSS_GROWTH_DIR . $extra ), 'extra file exists: ' . $extra );
		}
	}
);

db_test(
	'storage not ready: the lead form renders nothing (fail closed)',
	function () {
		dbgr_ld_boot( array(), array(), false );
		assert_same( '', dbgr_ld_form(), 'no form without storage' );
	}
);

db_test(
	'flag off: the form renders nothing',
	function () {
		dbgr_ld_boot( array( 'lead_form' => false ) );
		assert_same( '', DoughBoss_Growth_Leads::shortcode( array( 'variant' => 'corporate' ) ), 'flag off' );
		assert_false( shortcode_exists( 'doughboss_growth_lead_form' ), 'not registered' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* The form                                                                                                    */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'form: carries core field names, the segment, the honeypot and an UNTICKED marketing box',
	function () {
		dbgr_ld_boot();
		$html = dbgr_ld_form( 'variant="corporate"' );
		assert_contains( 'data-dbgr-lead-form', $html, 'form marker' );
		foreach ( array( 'customer_name', 'customer_email', 'customer_phone', 'package_id', 'guest_count', 'order_type', 'location_id', 'event_date', 'notes', 'address', 'hp', 'dbgr_company', 'dbgr_consent_marketing', 'dbgr_consent_text_version' ) as $name ) {
			assert_contains( 'name="' . $name . '"', $html, 'field ' . $name );
		}
		assert_contains( 'data-segment="corporate"', $html, 'segment' );
		assert_contains( 'data-landing="catering-corporate"', $html, 'landing key' );
		assert_matches( '/<input type="checkbox"[^>]*name="dbgr_consent_marketing" value="1" \/>/', $html, 'marketing checkbox present' );
		assert_not_contains( 'checked', $html, 'nothing is pre-ticked' );
		assert_not_contains( ' required', substr( $html, strpos( $html, 'name="dbgr_consent_marketing"' ) - 80, 200 ), 'the marketing box is optional' );
		assert_not_contains( 'name="dbgr_segment"', $html, 'the segment is not a visitor-editable field' );
	}
);

db_test(
	'form: variants map to segments and landing keys; unknown variant and bad landing attribute are handled',
	function () {
		dbgr_ld_boot();
		assert_contains( 'data-segment="office_breakfast"', dbgr_ld_form( 'variant="office_breakfast"' ), 'office_breakfast' );
		assert_contains( 'data-landing="catering-office-breakfast"', dbgr_ld_form( 'variant="office_breakfast"' ), 'office landing' );
		assert_contains( 'data-segment="events"', dbgr_ld_form( 'variant="events"' ), 'events' );
		assert_contains( 'data-company-required="0"', dbgr_ld_form( 'variant="events"' ), 'events: company optional' );
		assert_contains( 'data-company-required="1"', dbgr_ld_form( 'variant="corporate"' ), 'corporate: company required' );
		assert_contains( 'data-segment="corporate"', dbgr_ld_form(), 'default variant is corporate' );
		assert_same( '', dbgr_ld_form( 'variant="wholesale"' ), 'unknown variant renders nothing' );
		assert_same( '', dbgr_ld_form( 'variant="CORPORATE\'><script>"' ), 'hostile variant renders nothing' );
		assert_contains( 'data-landing="custom-page"', dbgr_ld_form( 'variant="events" landing="custom-page"' ), 'valid landing attribute accepted' );
		$bad = dbgr_ld_form( 'variant="events" landing="a b<c>"' );
		assert_contains( 'data-landing="catering-events"', $bad, 'invalid landing attribute ignored' );
		assert_not_contains( '<c>', $bad, 'no markup injection' );
	}
);

db_test(
	'form: package list holds only real lint-clean packages and "not sure yet"; shops come from core',
	function () {
		dbgr_ld_boot();
		dbgr_landing_package( 14, 'Product ' . dbgr_landing_banned_word() . ' Box', 99, 5, 10 );
		dbgr_landing_package( 15, 'Unpriced Platter', '', 5, 10 );
		dbgr_landing_package( 16, 'Draft Platter', 100, 5, 10, '', 'draft' );
		DoughBoss_Growth_Landing::reset_cache();
		$html = dbgr_ld_form();
		assert_contains( '<option value="0">Not sure yet</option>', $html, 'not sure yet' );
		assert_contains( 'Small Platter', $html, 'real package' );
		assert_contains( 'Large Platter', $html, 'real package' );
		assert_not_contains( dbgr_landing_banned_word(), $html, 'working-name package left out' );
		assert_not_contains( 'Unpriced', $html, 'package without a real price left out' );
		assert_not_contains( 'Draft Platter', $html, 'unpublished package left out' );
		assert_contains( 'data-slug="roselands"', $html, 'shops listed' );
		assert_contains( '>Revesby<', $html, 'shop name' );
		DoughBoss_Locations::$throw = true;
		assert_not_contains( 'name="location_id"', dbgr_ld_form(), 'core failure: no shop choice, the form still renders' );
	}
);

db_test(
	'form: generic copy only (no product word, no currency, no claim words, no digits in visible text)',
	function () {
		dbgr_ld_boot();
		foreach ( array( 'corporate', 'office_breakfast', 'events' ) as $variant ) {
			$html = dbgr_ld_form( 'variant="' . $variant . '"' );
			$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES, 'UTF-8' );
			assert_same( array(), DoughBoss_Growth_Ledger::lint_public( $text, null ), $variant . ': the visible text passes the public-copy lint' );
			assert_not_contains( '$', $html, $variant . ': no currency symbol' );
			assert_false( false !== stripos( $html, dbgr_landing_banned_word() ), $variant . ': no working name' );
		}
	}
);

db_test(
	'negative control: the lint and the product-word check really do reject bad copy',
	function () {
		$bad = 'Order our ' . dbgr_landing_banned_word() . ' for $5';
		assert_true( in_array( 'product_name', DoughBoss_Growth_Ledger::lint_public( $bad, null ), true ), 'the lint flags the working name' );
		assert_true( in_array( 'currency', DoughBoss_Growth_Ledger::lint_public( $bad, null ), true ), 'the lint flags a currency symbol' );
		dbgr_test_expect_failure(
			function () use ( $bad ) {
				assert_not_contains( dbgr_landing_banned_word(), $bad, 'bad copy must trip the product-word assertion' );
			},
			'the product-word assertion fails on bad copy'
		);
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Consent wording                                                                                             */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'consent: the wording names the sender and how to withdraw; the version is derived from the wording',
	function () {
		dbgr_ld_boot();
		$text = DoughBoss_Growth_Leads::consent_text();
		assert_contains( DBGR_LD_SENDER, $text, 'names the sender' );
		assert_contains( 'withdraw', $text, 'says how to withdraw' );
		assert_contains( 'unsubscribe', $text, 'unsubscribe' );
		$version = dbgr_ld_version();
		assert_matches( '/^ld-[0-9a-f]{12}$/', $version, 'version shape' );
		assert_true( strlen( $version ) <= 20, 'within the attribution module\'s 20 character cap' );
		assert_contains( 'value="' . $version . '"', dbgr_ld_form(), 'the form carries the version' );
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'lead_form' => true ), 'sender_legal_name' => 'Another Name Pty Ltd' ) );
		assert_true( dbgr_ld_version() !== $version, 'a different sender is a different version' );
	}
);

db_test(
	'consent: no sender name (or one carrying the working name) means NO marketing box, and the form still renders',
	function () {
		dbgr_ld_boot( array(), array( 'sender_legal_name' => '' ) );
		$html = dbgr_ld_form();
		assert_contains( 'data-dbgr-lead-form', $html, 'the enquiry form still renders' );
		assert_not_contains( 'dbgr_consent_marketing', $html, 'no marketing box' );
		assert_not_contains( 'dbgr_consent_text_version', $html, 'no version field' );
		assert_same( '', DoughBoss_Growth_Leads::consent_version(), 'no version' );
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'lead_form' => true ), 'sender_legal_name' => dbgr_landing_banned_word() . ' Pty Ltd' ) );
		assert_same( '', DoughBoss_Growth_Leads::sender_name(), 'a sender name carrying the working name is refused' );
		assert_not_contains( 'dbgr_consent_marketing', dbgr_ld_form(), 'no marketing box for a refused name' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Assets and browser configuration                                                                            */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'assets: script and style are queued only when a form renders; config holds core\'s URL and nonce and no secret or price',
	function () {
		dbgr_ld_boot();
		assert_same( array(), $GLOBALS['dbgr_assets']['scripts'], 'nothing queued before a form renders' );
		dbgr_ld_form();
		$assets = $GLOBALS['dbgr_assets'];
		assert_true( ! empty( $assets['scripts']['dbgr-lead-form']['enqueued'] ), 'script enqueued' );
		assert_true( ! empty( $assets['styles']['dbgr-leads']['enqueued'] ), 'style enqueued' );
		assert_contains( 'public/js/dbgr-lead-form.js', $assets['scripts']['dbgr-lead-form']['src'], 'script path' );
		$cfg = $assets['localized']['dbgr-lead-form']['DoughBossGrowthLeads'];
		assert_same( 'https://doughboss.test/wp-json/doughboss/v1/catering/enquiry', $cfg['enquiryUrl'], 'core enquiry route' );
		assert_same( wp_create_nonce( 'wp_rest' ), $cfg['nonce'], 'core wp_rest nonce' );
		$json = json_encode( $cfg );
		assert_not_contains( 'secret', strtolower( $json ), 'no secret' );
		assert_not_contains( 'token', strtolower( $json ), 'no token' );
		assert_not_contains( '"price"', strtolower( $json ), 'no price' );
		// A second form on the page does not print the configuration twice.
		dbgr_ld_form( 'variant="events"' );
		assert_count( 1, $assets['localized']['dbgr-lead-form'], 'configuration printed once' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* The lead record: written by the attribution module in core's own hook                                       */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'recording: with lead_form alone the enquiry hook is live (the registry would not start the attribution module)',
	function () {
		dbgr_ld_boot();
		assert_false( DoughBoss_Growth_Settings::enabled( 'attribution' ), 'attribution flag is off' );
		assert_false( has_filter( 'doughboss_catering_enquiry_created', array( 'DoughBoss_Growth_Attribution', 'on_enquiry_created' ) ), 'hook not yet present' );
		DoughBoss_Growth_Leads::init();
		assert_true( has_filter( 'doughboss_catering_enquiry_created', array( 'DoughBoss_Growth_Attribution', 'on_enquiry_created' ) ), 'hook present after init' );
		assert_true( DoughBoss_Growth_Leads::ensure_recording(), 'ensure_recording is idempotent' );
	}
);

db_test(
	'lead record: a core-accepted enquiry gives one lead_meta row with segment corporate, company and landing key',
	function () {
		dbgr_ld_boot();
		DoughBoss_Growth_Leads::init();
		dbgr_ld_register_core_route();
		$response = dbgr_ld_enquire();
		assert_same( 200, $response->get_status(), 'core accepted' );
		$rows = dbgr_ld_rows();
		assert_count( 1, $rows, 'exactly one lead_meta row' );
		assert_same( '201', (string) $rows[0]['enquiry_id'], 'linked to the enquiry id core created' );
		assert_same( 'corporate', $rows[0]['segment'], 'segment corporate' );
		assert_same( 'Acme Pty Ltd', $rows[0]['company_name'], 'company' );
		assert_same( 'catering-corporate', $rows[0]['landing_key'], 'landing key' );
		assert_same( '0', (string) $rows[0]['consent_marketing'], 'no consent unless asked' );
		assert_count( 1, $GLOBALS['dbgr_ld_fired'], 'doughboss_growth_lead_recorded fired once' );
		assert_same( 201, (int) $GLOBALS['dbgr_ld_fired'][0][0], 'with the enquiry id' );
		assert_same( 'corporate', $GLOBALS['dbgr_ld_fired'][0][1]['segment'], 'with the segment' );
		assert_not_contains( 'person@example.com', json_encode( $GLOBALS['dbgr_ld_fired'] ), 'the action carries no email' );
	}
);

db_test(
	'lead record: marketing consent is stored with the wording version and the UTC time ONLY when ticked',
	function () {
		dbgr_ld_boot();
		DoughBoss_Growth_Leads::init();
		dbgr_ld_register_core_route();
		$version = dbgr_ld_version();
		dbgr_ld_enquire( array( 'customer_email' => 'a@example.com', 'dbgr_consent_marketing' => '1', 'dbgr_consent_text_version' => $version ) );
		dbgr_ld_enquire( array( 'customer_email' => 'b@example.com' ) );
		dbgr_ld_enquire( array( 'customer_email' => 'c@example.com', 'dbgr_consent_text_version' => $version ) );
		dbgr_ld_enquire( array( 'customer_email' => 'd@example.com', 'dbgr_consent_marketing' => '1' ) );
		dbgr_ld_enquire( array( 'customer_email' => 'e@example.com', 'dbgr_consent_marketing' => 'yes', 'dbgr_consent_text_version' => $version ) );
		$rows = dbgr_ld_rows();
		assert_count( 5, $rows, 'five enquiries, five lead rows' );
		assert_same( '1', (string) $rows[0]['consent_marketing'], 'ticked with a version: consent given' );
		assert_same( $version, $rows[0]['consent_text_version'], 'the wording version is stored' );
		assert_same( gmdate( 'Y-m-d H:i:s', DBGR_TEST_EPOCH ), $rows[0]['consent_at_utc'], 'the consent time is the UTC time of the enquiry' );
		foreach ( array( 1 => 'not ticked', 2 => 'version without a tick', 3 => 'tick without a version', 4 => 'a tick that is not exactly 1' ) as $i => $label ) {
			assert_same( '0', (string) $rows[ $i ]['consent_marketing'], $label . ': NOT consent' );
			assert_same( '', (string) $rows[ $i ]['consent_text_version'], $label . ': no version stored' );
			assert_true( null === $rows[ $i ]['consent_at_utc'] || '' === $rows[ $i ]['consent_at_utc'], $label . ': no consent time' );
		}
	}
);

db_test(
	'lead record: a core-REJECTED enquiry leaves NO lead_meta row (bad nonce, rate limit, validation, honeypot) and fires no action',
	function () {
		dbgr_ld_boot();
		DoughBoss_Growth_Leads::init();
		dbgr_ld_register_core_route();
		$version = dbgr_ld_version();
		$ticked  = array( 'dbgr_consent_marketing' => '1', 'dbgr_consent_text_version' => $version );

		$bad_nonce = dbgr_ld_enquire( $ticked, 'stale' );
		assert_same( 403, $bad_nonce->get_status(), 'expired nonce is refused by core' );
		$limited = dbgr_ld_enquire( array_merge( $ticked, array( 'scenario' => 'limit' ) ) );
		assert_same( 429, $limited->get_status(), 'rate limit is refused by core' );
		$invalid = dbgr_ld_enquire( array_merge( $ticked, array( 'customer_name' => '' ) ) );
		assert_same( 400, $invalid->get_status(), 'validation failure is refused by core' );
		$honey = dbgr_ld_enquire( array_merge( $ticked, array( 'hp' => 'bot' ) ) );
		assert_same( 200, $honey->get_status(), 'core answers a honeypot with a silent success' );
		assert_same( '', $honey->get_data()['enquiry_number'], '...and no enquiry number' );

		assert_same( array(), dbgr_ld_rows(), 'no lead_meta row for any of the four' );
		assert_same( array(), $GLOBALS['dbgr_ld_fired'], 'doughboss_growth_lead_recorded never fired' );

		// A rejected request must not leak its fields into the next enquiry.
		dbgr_ld_enquire( array( 'customer_email' => 'next@example.com', 'dbgr_company' => '', 'dbgr_segment' => 'events' ) );
		$rows = dbgr_ld_rows();
		assert_count( 1, $rows, 'only the accepted enquiry has a row' );
		assert_same( 'events', $rows[0]['segment'], 'the accepted enquiry carries its own segment' );
		assert_same( '0', (string) $rows[0]['consent_marketing'], 'no consent leaked from a rejected, ticked request' );
	}
);

db_test(
	'lead record: lead_form off means nothing is recorded even when an enquiry arrives',
	function () {
		dbgr_ld_boot( array( 'lead_form' => false ) );
		DoughBoss_Growth_Leads::init();
		dbgr_ld_register_core_route();
		assert_same( 200, dbgr_ld_enquire()->get_status(), 'core still accepts its own enquiry' );
		assert_same( array(), dbgr_ld_rows(), 'no lead row while the form is off' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* The sizer                                                                                                   */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'sizer: shortcode renders only when the flag is on and a real package exists',
	function () {
		dbgr_ld_boot( array( 'lead_form' => false, 'party_sizer' => true ) );
		DoughBoss_Growth_Leads::init();
		assert_true( shortcode_exists( 'doughboss_growth_party_sizer' ), 'registered with the flag on' );
		$html = do_shortcode( '[doughboss_growth_party_sizer]' );
		assert_contains( 'data-dbgr-sizer', $html, 'renders' );
		assert_contains( 'name="guests"', $html, 'head count field' );
		assert_not_contains( '$', $html, 'the server markup carries no price' );
		assert_true( ! empty( $GLOBALS['dbgr_assets']['scripts']['dbgr-party-sizer']['enqueued'] ), 'script enqueued' );

		$GLOBALS['dbgr_posts']     = array();
		$GLOBALS['dbgr_post_meta'] = array();
		DoughBoss_Growth_Landing::reset_cache();
		assert_same( '', do_shortcode( '[doughboss_growth_party_sizer]' ), 'no real package: no sizer' );
	}
);

db_test(
	'sizer: flag off renders nothing and registers nothing',
	function () {
		dbgr_ld_boot( array( 'lead_form' => false, 'party_sizer' => false ) );
		DoughBoss_Growth_Leads::init();
		assert_false( shortcode_exists( 'doughboss_growth_party_sizer' ), 'not registered' );
		assert_same( '', DoughBoss_Growth_Party_Sizer::shortcode(), 'direct call renders nothing' );
	}
);

db_test(
	'sizer: package list is real, lint-clean, needs a serve range, smallest first, and carries NO price',
	function () {
		dbgr_ld_boot( array( 'lead_form' => false, 'party_sizer' => true ) );
		dbgr_landing_package( 14, 'Product ' . dbgr_landing_banned_word() . ' Box', 99, 5, 10 );
		dbgr_landing_package( 15, 'No Range Platter', 100, 0, 0 );
		dbgr_landing_package( 16, 'Free Platter', 0, 5, 10 );
		DoughBoss_Growth_Landing::reset_cache();
		$packages = DoughBoss_Growth_Party_Sizer::packages();
		$names    = array();
		foreach ( $packages as $p ) {
			$names[] = $p['name'];
			assert_same( array( 'id', 'name', 'serves_min', 'serves_max' ), array_keys( $p ), 'only id, name and serve range' );
		}
		assert_same( array( 'Small Platter', 'Medium Platter', 'Large Platter' ), $names, 'real packages only, smallest serve range first' );
		DoughBoss_Growth_Leads::init();
		do_shortcode( '[doughboss_growth_party_sizer]' );
		$cfg = $GLOBALS['dbgr_assets']['localized']['dbgr-party-sizer']['DoughBossGrowthSizer'];
		assert_same( 'https://doughboss.test/wp-json/doughboss/v1/catering/quote', $cfg['quoteUrl'], 'core quote route' );
		assert_not_contains( '"price"', strtolower( json_encode( $cfg ) ), 'no price field in the browser configuration' );
		assert_not_contains( '300', json_encode( $cfg['packages'] ), 'package base prices are not handed over' );
		assert_not_contains( '120', json_encode( $cfg['packages'] ), 'package base prices are not handed over' );
		assert_not_contains( dbgr_landing_banned_word(), json_encode( $cfg ), 'working name never reaches the browser' );
	}
);

db_test(
	'sizer: core unavailable gives no packages (fail closed)',
	function () {
		dbgr_ld_boot( array( 'lead_form' => false, 'party_sizer' => true ) );
		DoughBoss_Growth_Landing::reset_cache();
		$GLOBALS['dbgr_posts'] = array();
		assert_same( array(), DoughBoss_Growth_Party_Sizer::packages(), 'no published package' );
	}
);

db_test(
	'sizer: pieces-per-guest guidance is hidden unless a confirmed, sourced, lint-clean ledger claim exists',
	function () {
		dbgr_ld_boot( array( 'lead_form' => false, 'party_sizer' => true ) );
		$id = DoughBoss_Growth_Party_Sizer::GUIDANCE_CLAIM;
		assert_same( '', DoughBoss_Growth_Party_Sizer::guidance(), 'shipped ledger: no such claim, nothing shown' );

		$dir = dbgr_landing_ledger( array( dbgr_landing_claim( $id, 'Plan on a few pieces each', false ) ) );
		assert_same( '', DoughBoss_Growth_Party_Sizer::guidance(), 'unconfirmed claim: hidden' );
		dbgr_test_rmdir( $dir );

		$dir = dbgr_landing_ledger( array( dbgr_landing_claim( $id, 'Plan on a few pieces each' ) ) );
		assert_same( 'Plan on a few pieces each', DoughBoss_Growth_Party_Sizer::guidance(), 'confirmed and sourced: shown' );
		DoughBoss_Growth_Leads::init();
		do_shortcode( '[doughboss_growth_party_sizer]' );
		assert_same( 'Plan on a few pieces each', $GLOBALS['dbgr_assets']['localized']['dbgr-party-sizer']['DoughBossGrowthSizer']['guidance'], 'the browser gets the confirmed text only' );
		dbgr_test_rmdir( $dir );

		$dir = dbgr_landing_ledger( array( dbgr_landing_claim( $id, 'About ' . dbgr_landing_banned_word() . ' per person' ) ) );
		assert_same( '', DoughBoss_Growth_Party_Sizer::guidance(), 'confirmed but carrying the working name: hidden' );
		dbgr_test_rmdir( $dir );

		$claim = dbgr_landing_claim( $id, 'Plan on a few pieces each' );
		unset( $claim['source'] );
		$dir = dbgr_landing_ledger( array( $claim ) );
		assert_same( '', DoughBoss_Growth_Party_Sizer::guidance(), 'confirmed without a source: hidden' );
		dbgr_test_rmdir( $dir );
	}
);

db_test(
	'sizer: the optional enquiry link is escaped and an unsafe scheme is dropped',
	function () {
		dbgr_ld_boot( array( 'lead_form' => false, 'party_sizer' => true ) );
		DoughBoss_Growth_Leads::init();
		$html = do_shortcode( '[doughboss_growth_party_sizer enquiry_url="/catering/corporate/"]' );
		assert_contains( 'href="/catering/corporate/"', $html, 'link rendered' );
		$bad = do_shortcode( '[doughboss_growth_party_sizer enquiry_url="javascript:alert(1)"]' );
		assert_not_contains( 'javascript:', $bad, 'unsafe scheme dropped' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Admin tab                                                                                                   */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'admin tab: registered under the doughboss_growth_admin_tabs action and lists the [CONFIRM] gaps',
	function () {
		dbgr_ld_boot( array( 'party_sizer' => true ), array( 'sender_legal_name' => '', 'privacy_policy_url' => '' ) );
		dbgr_test_set_admin( true );
		DoughBoss_Growth_Leads::init();
		assert_true( has_action( 'doughboss_growth_admin_tabs', array( 'DoughBoss_Growth_Leads', 'register_tab' ) ), 'tab hook registered' );
		ob_start();
		DoughBoss_Growth_Leads::render_tab();
		$out = (string) ob_get_clean();
		assert_contains( 'CONFIRM: sender legal name', $out, 'sender gap shown' );
		assert_contains( 'CONFIRM: privacy-policy URL', $out, 'privacy gap shown' );
		assert_contains( 'CONFIRM: pieces-per-guest guidance', $out, 'guidance gap shown' );
		assert_contains( 'Not shown (no sender name)', $out, 'opt-in box status' );
		assert_contains( 'Hidden (no confirmed claim)', $out, 'guidance status' );
		assert_not_contains( 'person@example.com', $out, 'no personal data' );
	}
);

db_test(
	'no foreign writes: rendering, enquiring and the tab write only doughboss_growth names',
	function () {
		dbgr_ld_boot( array( 'party_sizer' => true ) );
		DoughBoss_Growth_Leads::init();
		dbgr_ld_register_core_route();
		dbgr_ld_form();
		dbgr_ld_enquire();
		ob_start();
		DoughBoss_Growth_Leads::render_tab();
		ob_end_clean();
		assert_same( array(), $GLOBALS['dbgr_foreign_writes'], 'no foreign write' );
		foreach ( array_keys( $GLOBALS['dbgr_options'] ) as $name ) {
			assert_true( 0 === strpos( $name, 'doughboss_growth_' ), 'option written under the companion namespace: ' . $name );
		}
	}
);

db_test(
	'admin tab: latest lead records show segment, company and consent, escape output and never show an email address',
	function () {
		dbgr_ld_boot();
		DoughBoss_Growth_Leads::init();
		dbgr_ld_register_core_route();
		$version = dbgr_ld_version();
		ob_start();
		DoughBoss_Growth_Leads::render_tab();
		assert_contains( 'Lead records: 0', (string) ob_get_clean(), 'empty at first' );
		dbgr_ld_enquire( array( 'customer_email' => 'secret@example.com', 'dbgr_company' => 'Smith & Sons <script>x</script>', 'dbgr_consent_marketing' => '1', 'dbgr_consent_text_version' => $version ) );
		dbgr_ld_enquire( array( 'customer_email' => 'other@example.com', 'dbgr_segment' => 'events' ) );
		ob_start();
		DoughBoss_Growth_Leads::render_tab();
		$out = (string) ob_get_clean();
		assert_contains( 'Lead records: 2', $out, 'count' );
		assert_contains( 'Smith &amp; Sons', $out, 'company escaped' );
		assert_not_contains( '<script>', $out, 'no raw markup' );
		assert_contains( '<td>Yes</td>', $out, 'consent yes' );
		assert_contains( '<td>No</td>', $out, 'consent no' );
		assert_contains( $version, $out, 'wording version shown' );
		assert_not_contains( 'secret@example.com', $out, 'no email address' );
		assert_not_contains( 'other@example.com', $out, 'no email address' );
	}
);
