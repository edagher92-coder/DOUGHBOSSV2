<?php
/**
 * WP-16 adversarial review: regression tests for the defects the independent review found and fixed
 * (web/docs/wp/07-adversarial-review.md). Each test drives the REAL entry points (the module registry through
 * DoughBoss_Growth::init(), the deactivation hook, the render path, the limiter) rather than calling a module directly,
 * because every defect here was invisible to tests that loaded a module by hand.
 *
 *  R1  waitlist opt-out, exporter, eraser and purge must survive the waitlist flag being switched off;
 *  R2  a companion shortcode on a published page must never print as raw text when its feature is off;
 *  R3  deactivation drafts the hand-made coming-soon page (found by its configured slug), shortcode-only pages only;
 *  R4  admin-editable teaser text may not carry a date, price, location, product, size or dietary claim in words;
 *  R5  the limiter's address-hash buckets are purged by the daily retention run;
 *  R6  IPv6 visitors are rate-limited per /64, so rotating addresses inside one network gains no allowance.
 *
 * Self-contained (no helper from another test file), SQLite-backed where storage matters, no network.
 *
 * @package DoughBoss_Growth
 */

/** Placeholder sender. Not a real company. */
const DBGR_ADV_SENDER = 'Example Trading Pty Ltd';

/**
 * Install every real companion table in the harness's SQLite database and mark the schema as installed.
 *
 * @return void
 */
function dbgr_adv_storage() {
	$GLOBALS['wpdb']->use_sqlite();
	DoughBoss_Growth::load_module( 'waitlist' );
	foreach ( array_merge( DoughBoss_Growth_Rate_Limit::schema(), DoughBoss_Growth_Outbox::schema(), DoughBoss_Growth_Waitlist::schema() ) as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
	update_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION, DOUGHBOSS_GROWTH_DB_VERSION );
	$GLOBALS['dbgr_pages_by_path'] = array();
	DoughBoss_Growth_Waitlist::reset_state();
}

/**
 * Save settings as the owner would.
 *
 * @param array $features Feature => bool.
 * @param array $extra    Extra settings.
 * @return void
 */
function dbgr_adv_settings( array $features, array $extra = array() ) {
	update_option(
		DoughBoss_Growth_Settings::OPTION,
		array_merge(
			array(
				'features'           => $features,
				'sender_legal_name'  => DBGR_ADV_SENDER,
				'privacy_policy_url' => 'https://example.com.au/privacy/',
			),
			$extra
		)
	);
}

/**
 * Store one confirmed waitlist sign-up directly and return its row.
 *
 * @param string $email Address.
 * @return array
 */
function dbgr_adv_seed_confirmed( $email ) {
	$hash = hash( 'sha256', $email );
	$now  = gmdate( 'Y-m-d H:i:s', DoughBoss_Growth::now() );
	$GLOBALS['wpdb']->sqlite_raw( "INSERT INTO wp_doughboss_growth_waitlist (email, email_hash, consent_marketing, consent_text_version, consent_at_utc, status, confirmed_at_utc, created_at, updated_at) VALUES ('{$email}', '{$hash}', 1, 'wl-x', '{$now}', 'confirmed', '{$now}', '{$now}', '{$now}')" );
	$rows = $GLOBALS['wpdb']->sqlite_raw( "SELECT * FROM wp_doughboss_growth_waitlist WHERE email_hash = '{$hash}'" );
	return $rows[0];
}

/* ---------------------------------------------------------------------------------------------------------- */
/* R1 + R2: the registry with every flag off (front end, schema installed)                                     */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'R1: with the waitlist switched OFF after sign-ups exist, the opt-out link, exporter, eraser and purge still work through the real registry; nothing that signs people up is exposed',
	function () {
		dbgr_adv_storage();
		$row = dbgr_adv_seed_confirmed( 'leaver@example.com' );
		dbgr_adv_settings( array() ); // Every flag off: the owner switched the waitlist off.
		dbgr_test_set_admin( false );

		DoughBoss_Growth::init();
		do_action( 'rest_api_init' );

		$routes = array_keys( $GLOBALS['dbgr_rest_routes'] );
		assert_true( in_array( '/doughboss-growth/v1/waitlist/unsubscribe', $routes, true ), 'the opt-out route exists with the flag off' );
		assert_false( in_array( '/doughboss-growth/v1/waitlist', $routes, true ), 'no sign-up route with the flag off' );
		assert_false( in_array( '/doughboss-growth/v1/form-token', $routes, true ), 'no form-token route with the flag off' );
		assert_false( in_array( '/doughboss-growth/v1/waitlist/confirm', $routes, true ), 'no confirm route with the flag off' );
		assert_same( false, wp_next_scheduled( DoughBoss_Growth_Waitlist::PURGE_HOOK ), 'nothing newly scheduled while off' );
		assert_same( array(), $GLOBALS['dbgr_assets']['scripts'], 'no script enqueued' );
		assert_true( has_action( DoughBoss_Growth_Waitlist::PURGE_HOOK ) > 0, 'an already scheduled purge still has its callback' );
		assert_true( has_action( 'template_redirect' ) > 0, 'the email-link page (opt-out button) is still answered' );

		// The opt-out link in an email sent while the list was on still works.
		$token = DoughBoss_Growth_Waitlist::unsubscribe_token( (int) $row['id'], $row['email_hash'] );
		$bad   = dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/waitlist/unsubscribe', array( 'id' => (string) $row['id'], 'token' => str_repeat( 'c', 32 ) ) );
		assert_same( 400, $bad->get_status(), 'negative control: a wrong token is refused' );
		$ok = dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/waitlist/unsubscribe', array( 'id' => (string) $row['id'], 'token' => $token ) );
		assert_same( 200, $ok->get_status(), 'opt-out accepted with the flag off' );
		$after = $GLOBALS['wpdb']->sqlite_raw( 'SELECT status FROM wp_doughboss_growth_waitlist WHERE id = ' . (int) $row['id'] );
		assert_same( 'unsubscribed', $after[0]['status'], 'the row is opted out' );
		$sup = $GLOBALS['wpdb']->sqlite_raw( 'SELECT email_hash FROM wp_doughboss_growth_suppression' );
		assert_same( $row['email_hash'], $sup[0]['email_hash'], 'the opt-out is recorded on the suppression list' );

		// Privacy tooling stays registered and works.
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		assert_true( isset( $exporters['doughboss-growth-waitlist'], $erasers['doughboss-growth-waitlist'] ), 'exporter and eraser registered with the flag off' );
		$export = call_user_func( $exporters['doughboss-growth-waitlist']['callback'], 'leaver@example.com', 1 );
		assert_contains( 'leaver@example.com', json_encode( $export['data'] ), 'the stored data can still be exported' );
		$erase = call_user_func( $erasers['doughboss-growth-waitlist']['callback'], 'leaver@example.com', 1 );
		assert_true( $erase['items_removed'], 'the stored data can still be erased' );
	}
);

db_test(
	'R2: with every flag off and the schema installed, every companion shortcode on a page renders nothing (never the raw tag); a fresh install with no schema and no pages registers nothing',
	function () {
		dbgr_adv_storage();
		dbgr_adv_settings( array() );
		dbgr_test_set_admin( false );
		DoughBoss_Growth::init();
		foreach ( DoughBoss_Growth::SHORTCODES as $tag ) {
			$out = do_shortcode( '<p>before</p>[' . $tag . ' key="catering-corporate" variant="corporate"]<p>after</p>' );
			assert_same( '<p>before</p><p>after</p>', $out, 'no raw tag for ' . $tag );
			assert_not_contains( '[doughboss_growth_', $out, 'no companion tag text for ' . $tag );
		}
		// Negative control: a shortcode that is not the companion's is left exactly as WordPress would leave it.
		assert_same( '[gallery]', do_shortcode( '[gallery]' ), 'control: a foreign unregistered tag is untouched' );

		// A fresh install (no schema, no recorded pages) stays strictly inert: no shortcode at all.
		dbgr_test_reset();
		dbgr_adv_settings( array() );
		DoughBoss_Growth::init();
		assert_same( array(), $GLOBALS['dbgr_shortcodes'], 'fresh install: nothing registered' );

		// Recorded pages without a schema are enough to need the guard.
		dbgr_test_reset();
		update_option( DoughBoss_Growth_Activator::PAGES_OPTION, array( 'catering-corporate' => 321 ) );
		DoughBoss_Growth::init();
		assert_same( '', do_shortcode( '[doughboss_growth_landing key="catering-corporate"]' ), 'recorded pages: the landing tag renders nothing' );
	}
);

db_test(
	'R2: a module that is ON keeps its real shortcode (the stub never replaces it)',
	function () {
		dbgr_adv_storage();
		dbgr_adv_settings( array( 'waitlist' => true, 'coming_soon' => true ) );
		dbgr_test_set_admin( false );
		DoughBoss_Growth::init();
		assert_same( array( 'DoughBoss_Growth_Waitlist', 'shortcode' ), $GLOBALS['dbgr_shortcodes']['doughboss_growth_waitlist'], 'real waitlist shortcode' );
		assert_same( array( 'DoughBoss_Growth_Coming_Soon', 'shortcode' ), $GLOBALS['dbgr_shortcodes']['doughboss_growth_coming_soon'], 'real coming-soon shortcode' );
		assert_contains( 'Something exciting is coming', do_shortcode( '[doughboss_growth_coming_soon]' ), 'the section renders' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* R3: deactivation drafts the hand-made coming-soon page                                                      */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'R3: deactivation drafts the published page at the coming-soon slug when it holds only a companion shortcode, and leaves an edited page alone',
	function () {
		$page = dbgr_test_add_post( array( 'ID' => 301, 'post_name' => 'coming-soon', 'post_content' => '<!-- wp:shortcode -->[doughboss_growth_coming_soon]<!-- /wp:shortcode -->' ) );
		DoughBoss_Growth_Activator::deactivate();
		assert_same( 'draft', get_post( $page )->post_status, 'the coming-soon page (not in doughboss_growth_pages) is drafted' );
		assert_same( array( 'ID', 'post_status' ), array_keys( $GLOBALS['dbgr_post_writes'][0]['fields'] ), 'only the status is written' );

		// Negative control: the owner added their own text, so the page is not the companion's to hide.
		dbgr_test_reset();
		$edited = dbgr_test_add_post( array( 'ID' => 302, 'post_name' => 'coming-soon', 'post_content' => '[doughboss_growth_coming_soon] Our own words.' ) );
		DoughBoss_Growth_Activator::deactivate();
		assert_same( 'publish', get_post( $edited )->post_status, 'an edited page is left alone' );
		assert_same( array(), $GLOBALS['dbgr_post_writes'], 'no write' );

		// A custom slug from settings is honoured; the default slug is then not touched.
		dbgr_test_reset();
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'coming_soon_page_slug' => 'vip' ) );
		$vip   = dbgr_test_add_post( array( 'ID' => 303, 'post_name' => 'vip', 'post_content' => '[doughboss_growth_waitlist]' ) );
		$other = dbgr_test_add_post( array( 'ID' => 304, 'post_name' => 'coming-soon', 'post_content' => '[doughboss_growth_coming_soon]' ) );
		DoughBoss_Growth_Activator::deactivate();
		assert_same( 'draft', get_post( $vip )->post_status, 'the page at the configured slug is drafted' );
		assert_same( 'publish', get_post( $other )->post_status, 'a page at another slug and not recorded is left alone' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* R4: teaser text claims in words                                                                             */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'R4: admin teaser text carrying a date, price, location, product, size or dietary claim in words falls back to the neutral default; neutral text is kept',
	function () {
		dbgr_adv_storage();
		$claims = array(
			'Coming to Bankstown soon',
			'Launching this Friday',
			'Opening in October',
			'Five dollars each',
			'Free for everyone',
			'Mini pizza packs are coming',
			'Something bite sized is coming',
			'Vegetarian treats are coming',
			'Dairy and egg friendly treats',
			"\u{062D}\u{0644}\u{0627}\u{0644}",
			"Fri\u{200B}day",
			'ＲＥＶＥＳＢＹ',
			"M\u{0456}nis are coming", // Cyrillic look-alike letter: passes the shared lint, refused here.
			'Mini’s are coming',
		);
		foreach ( $claims as $text ) {
			dbgr_adv_settings( array( 'coming_soon' => true ), array( 'coming_soon_headline' => $text, 'coming_soon_body' => $text ) );
			assert_same( DoughBoss_Growth_Settings::DEFAULT_COMING_SOON_HEADLINE, DoughBoss_Growth_Coming_Soon::headline(), 'headline refused: ' . $text );
			assert_same( DoughBoss_Growth_Settings::DEFAULT_COMING_SOON_BODY, DoughBoss_Growth_Coming_Soon::body(), 'body refused: ' . $text );
		}
		foreach ( array( 'Watch this space', 'Join the list for a first look', 'Something new is on the way', 'Be the first to hear' ) as $text ) {
			dbgr_adv_settings( array( 'coming_soon' => true ), array( 'coming_soon_headline' => $text ) );
			assert_same( $text, DoughBoss_Growth_Coming_Soon::headline(), 'negative control, neutral text kept: ' . $text );
		}
		assert_same( array(), DoughBoss_Growth_Coming_Soon::teaser_violations( DoughBoss_Growth_Settings::DEFAULT_COMING_SOON_HEADLINE ), 'the default headline passes' );
		assert_same( array(), DoughBoss_Growth_Coming_Soon::teaser_violations( DoughBoss_Growth_Settings::DEFAULT_COMING_SOON_BODY ), 'the default body passes' );
		assert_same( array( 'encoding' ), DoughBoss_Growth_Coming_Soon::teaser_violations( "\xff\xfe" ), 'invalid UTF-8 fails closed' );

		// Through the rendered section and the ribbon, not only the accessor.
		dbgr_adv_settings( array( 'coming_soon' => true ), array( 'coming_soon_headline' => 'Coming to Revesby this Friday', 'coming_soon_body' => 'Mini packs for five dollars' ) );
		DoughBoss_Growth::load_module( 'coming_soon' );
		$html = DoughBoss_Growth_Coming_Soon::shortcode( array( 'form' => '0' ) ) . DoughBoss_Growth_Coming_Soon::render_ribbon();
		foreach ( array( 'Revesby', 'Friday', 'Mini', 'dollars' ) as $word ) {
			assert_not_contains( $word, $html, 'rendered output carries no "' . $word . '"' );
		}
		assert_contains( 'Something exciting is coming', $html, 'the neutral default is rendered instead' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* R5: limiter buckets are purged                                                                              */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'R5: the daily retention run deletes limiter buckets (including the per-address email hash) once their window is over a day old, and keeps current ones',
	function () {
		dbgr_adv_storage();
		dbgr_adv_settings( array( 'waitlist' => true ) );
		$hash = hash( 'sha256', 'someone@example.com' );
		$old  = DoughBoss_Growth::now() - ( 3 * DAY_IN_SECONDS );
		$GLOBALS['wpdb']->sqlite_raw( "INSERT INTO wp_doughboss_growth_rate (bucket_key, window_start, hits) VALUES ('wl:email:{$hash}', {$old}, 1)" );
		DoughBoss_Growth_Waitlist::limit_email( hash( 'sha256', 'current@example.com' ) ); // A bucket in the current window.
		DoughBoss_Growth_Waitlist::purge();
		$keys = array_column( $GLOBALS['wpdb']->sqlite_raw( 'SELECT bucket_key FROM wp_doughboss_growth_rate' ), 'bucket_key' );
		assert_false( in_array( 'wl:email:' . $hash, $keys, true ), 'the stale email-hash bucket is gone' );
		assert_true( in_array( 'wl:email:' . hash( 'sha256', 'current@example.com' ), $keys, true ), 'negative control: the current bucket is kept' );
		assert_same( array(), $GLOBALS['wpdb']->unprepared, 'every statement was prepared' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* R6: IPv6 per-/64 buckets                                                                                    */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'R6: IPv6 addresses in one /64 share a limiter bucket; other /64s and IPv4 keep their own; an IPv4-mapped address counts as its IPv4 address',
	function () {
		$a = DoughBoss_Growth_Rate_Limit::hash_ip( '2001:db8:1:2::1' );
		$b = DoughBoss_Growth_Rate_Limit::hash_ip( '2001:db8:1:2:ffff:abcd:1234:5678' );
		$c = DoughBoss_Growth_Rate_Limit::hash_ip( '2001:db8:1:3::1' );
		assert_same( $a, $b, 'two addresses in the same /64 are one visitor' );
		assert_true( $a !== $c, 'negative control: a different /64 is a different visitor' );
		assert_same( DoughBoss_Growth_Rate_Limit::hash_ip( '203.0.113.7' ), DoughBoss_Growth_Rate_Limit::hash_ip( '::ffff:203.0.113.7' ), 'IPv4-mapped equals IPv4' );
		assert_true( DoughBoss_Growth_Rate_Limit::hash_ip( '::ffff:203.0.113.7' ) !== DoughBoss_Growth_Rate_Limit::hash_ip( '::ffff:198.51.100.7' ), 'two IPv4-mapped visitors stay apart (not one shared /64)' );
		assert_true( DoughBoss_Growth_Rate_Limit::hash_ip( '203.0.113.7' ) !== DoughBoss_Growth_Rate_Limit::hash_ip( '203.0.113.8' ), 'IPv4 is still per address' );

		// End to end: rotating the address inside one /64 gains no extra sign-up attempts.
		dbgr_adv_storage();
		dbgr_adv_settings( array( 'waitlist' => true ) );
		$allowed = 0;
		for ( $i = 1; $i <= DoughBoss_Growth_Waitlist::LIMIT_IP + 3; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::' . dechex( $i );
			$limit = DoughBoss_Growth_Waitlist::limit_visitor();
			if ( $limit['allowed'] ) {
				++$allowed;
			}
		}
		assert_same( DoughBoss_Growth_Waitlist::LIMIT_IP, $allowed, 'only the per-address allowance is granted across rotating /64 addresses' );
	}
);
