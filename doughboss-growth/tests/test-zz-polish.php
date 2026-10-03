<?php
/**
 * Polish pass tests (0.1.0 release candidate): static cards (no 3D anywhere), the consent Close button and wording
 * guard, the ribbon landmark, div wrappers for form fields, the stylesheet printing early and once, owner wording for
 * gap lists and failures, and the conversion-destination rule. The ES5 behaviour is in consent.test.js. Runs after the
 * other files (name order), so it may use their helpers (dbgr_wl_boot, dbgr_consent_page, dbgr_fail_render).
 *
 * @package DoughBoss_Growth
 */

/**
 * The visible text of a chunk of HTML: tags dropped, whitespace collapsed.
 *
 * @param string $html HTML.
 * @return string
 */
function dbgr_polish_text( $html ) {
	return trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/<[^>]*>/', ' ', $html ) ) );
}

/* ---------------------------------------------------------------------------------------------------------- */
/* 1. No 3D anywhere that ships                                                                                */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'static cards: no shipped file contains perspective, rotateX, rotateY, data-dbgr-tilt or preserve-3d, and the tilt script is gone',
	function () {
		$dir   = dirname( __DIR__ );
		$files = array();
		foreach ( array( 'includes', 'admin', 'public', 'content' ) as $sub ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir . '/' . $sub, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( $file->isFile() ) {
					$files[] = $file->getPathname();
				}
			}
		}
		$files = array_merge( $files, glob( $dir . '/*.php' ), glob( $dir . '/readme.txt' ) );
		assert_true( count( $files ) > 40, 'the scan covers the shipped tree (' . count( $files ) . ' files)' );
		foreach ( $files as $file ) {
			$text = (string) file_get_contents( $file );
			assert_same( 0, preg_match( '/perspective|rotateX|rotateY|data-dbgr-tilt|preserve-3d/i', $text ), 'no 3D construct in ' . substr( $file, strlen( $dir ) + 1 ) );
		}
		assert_false( file_exists( $dir . '/public/js/dbgr-tilt-cards.js' ), 'the tilt script is not in the tree' );
		assert_false( file_exists( $dir . '/tests/tilt-cards.test.js' ), 'nor its test' );
		// Negative control: the same scan DOES see a planted construct.
		assert_same( 1, preg_match( '/perspective|rotateX|rotateY|data-dbgr-tilt|preserve-3d/i', '.a{transform:rotateX(1deg)}' ), 'the pattern is not vacuous' );
		dbgr_wl_boot();
		assert_not_contains( 'data-dbgr-tilt', DoughBoss_Growth_Coming_Soon::shortcode( array( 'cards' => 'whatever' ) ), 'the markup carries no tilt attribute' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 3. Ribbon is not a landmark                                                                                 */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'ribbon: a plain div with no aria-label (it repeats the visible text and would add a complementary landmark to every page)',
	function () {
		dbgr_wl_boot();
		$ribbon = DoughBoss_Growth_Coming_Soon::render_ribbon();
		assert_same( 0, preg_match( '/<aside|<\/aside>|aria-label/', $ribbon ), 'no aside, no aria-label' );
		assert_matches( '/^<div class="dbgr-cs dbgr-cs--ribbon" data-dbgr-coming-soon data-dbgr-surface="home"><p class="dbgr-cs__ribbon-text">/', $ribbon, 'a div with the same classes and data attributes' );
		assert_matches( '/<\/p><\/div>$/', $ribbon, 'closed as a div' );
		assert_contains( 'Something exciting is coming', $ribbon, 'the visible text is unchanged' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 4. Div wrappers (waitlist, consent)                                                                         */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'wrappers: waitlist form fields are divs with the same classes, balanced, and their margins come from class rules',
	function () {
		dbgr_wl_boot();
		$html = DoughBoss_Growth_Waitlist::render_form( array() );
		assert_same( 0, preg_match( '/<p class="dbgr-wl__(field|consent|privacy|actions)/', $html ), 'no paragraph wrapper left' );
		assert_same( substr_count( $html, '<p' ), substr_count( $html, '</p>' ), 'paragraphs left are closed' );
		assert_same( substr_count( $html, '<div' ), substr_count( $html, '</div>' ), 'divs are closed' );
		foreach ( array( 'field', 'consent', 'privacy', 'actions' ) as $cls ) {
			assert_contains( '<div class="dbgr-wl__' . $cls . '">', $html, 'a div carries dbgr-wl__' . $cls );
		}
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/public/css/dbgr-coming-soon.css' );
		foreach ( array( 'field', 'consent', 'privacy', 'actions' ) as $cls ) {
			assert_matches( '/\.dbgr-wl__' . $cls . '\s*\{[^}]*margin:/', $css, 'a class rule sets the margin of .dbgr-wl__' . $cls );
		}
	}
);

db_test(
	'wrappers: the consent category rows are divs with the same class',
	function () {
		$page = dbgr_consent_page( array( 'consent_banner' => true ) );
		$html = $page['footer'];
		assert_same( 0, preg_match( '/<p class="dbgr-consent__row"/', $html ), 'no paragraph row left' );
		assert_same( 3, substr_count( $html, '<div class="dbgr-consent__row">' ), 'three div rows' );
		assert_same( substr_count( $html, '<div' ), substr_count( $html, '</div>' ), 'divs balanced' );
		assert_same( substr_count( $html, '<p' ), substr_count( $html, '</p>' ), 'paragraphs balanced' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 2. Consent Close button, wording guard                                                                       */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'consent: the Close button is in the banner, hidden until the script shows it, and the three equal choices are untouched',
	function () {
		$html = dbgr_consent_page( array( 'consent_banner' => true ) )['footer'];
		assert_contains( '<button type="button" class="dbgr-btn dbgr-consent__close" data-dbgr-action="close" hidden>Close</button>', $html, 'Close button, hidden by default' );
		assert_same( 1, preg_match( '/<div class="dbgr-consent__actions">(<button[^>]*data-dbgr-action="accept"[^>]*>Accept all<\/button><button[^>]*data-dbgr-action="reject"[^>]*>Reject all<\/button><button[^>]*data-dbgr-action="choose"[^>]*>Settings<\/button>)<\/div>/', $html ), 'the grid holds exactly the three choices' );
		assert_true( strpos( $html, 'consent__actions' ) < strpos( $html, 'consent__close' ), 'Close sits after the grid, on its own row' );
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/public/css/dbgr-consent.css' );
		assert_matches( '/\.dbgr-consent__close\[hidden\]/', $css, 'hidden wins over the display rule' );
		assert_matches( '/\.dbgr-consent__close\s*\{[^}]*width:\s*100%/', $css, 'Close has its own full-width row on a phone' );
	}
);

db_test(
	'consent: banner wording is guarded - the visible text is pinned to the default wording version, so a change cannot ship without a bump',
	function () {
		$html = dbgr_consent_page( array( 'consent_banner' => true ) )['footer'];
		$text = dbgr_polish_text( $html );
		assert_contains( 'We use cookies to keep this site working, to understand how it is used and, if you agree, to measure our advertising. You can change your choice at any time with the Privacy choices button.', $text, 'the shortened sentence' );
		assert_not_contains( 'similar technologies', $text, 'the longer wording is gone' );
		assert_not_contains( 'Choose', $text, 'the button is called Settings' );
		$pinned = array( '2' => '3484f7a5a7385a0b3e688f6ab48fefc3e04b2feb' );
		$version = (string) DoughBoss_Growth_Settings::get( 'consent_text_version', '' );
		assert_true( isset( $pinned[ $version ] ), 'the default wording version (' . $version . ') has a pinned wording' );
		assert_same( $pinned[ $version ], sha1( $text ), 'WORDING CHANGED: raise the default consent_text_version in Settings (and get legal review), then pin the new hash' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 5. Early stylesheet (waitlist, coming soon)                                                                  */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'early style: after wp_head the waitlist stylesheet link prints once, before the form; before wp_head nothing is inlined',
	function () {
		dbgr_wl_boot();
		$before = DoughBoss_Growth_Waitlist::render_form( array() );
		assert_not_contains( '<link', $before, 'before wp_head the head prints it' );

		dbgr_test_reset();
		dbgr_wl_boot();
		$GLOBALS['dbgr_done']['wp_head'] = 1;
		$one = DoughBoss_Growth_Waitlist::render_form( array() );
		$two = DoughBoss_Growth_Waitlist::render_form( array() );
		assert_same( 1, substr_count( $one, "id='dbgr-coming-soon-css'" ), 'the link is printed with the first form' );
		assert_true( strpos( $one, '<link' ) < strpos( $one, '<form' ), 'before the form markup' );
		assert_not_contains( '<link', $two, 'two forms print one link' );
		assert_true( wp_style_is( 'dbgr-coming-soon', 'done' ), 'marked printed, so the footer will not print it again' );
	}
);

db_test(
	'early style: the coming-soon section and the ribbon print the link once, before their own markup',
	function () {
		dbgr_wl_boot();
		$GLOBALS['dbgr_done']['wp_head'] = 1;
		$section = DoughBoss_Growth_Coming_Soon::shortcode( array() );
		assert_same( 1, substr_count( $section, '<link' ), 'one link for the section and the form inside it' );
		assert_true( strpos( $section, '<link' ) < strpos( $section, '<section' ), 'before the section' );
		assert_not_contains( '<link', DoughBoss_Growth_Coming_Soon::render_ribbon(), 'the ribbon after it prints none' );

		dbgr_test_reset();
		dbgr_wl_boot();
		$GLOBALS['dbgr_done']['wp_head'] = 1;
		$ribbon = DoughBoss_Growth_Coming_Soon::render_ribbon();
		assert_true( 0 === strpos( $ribbon, '<link' ), 'a ribbon on its own prints the link first' );
	}
);

db_test(
	'early style: a single page holding a waitlist or coming-soon shortcode gets the stylesheet for the head; others do not',
	function () {
		dbgr_wl_boot();
		DoughBoss_Growth_Waitlist::init();
		DoughBoss_Growth_Coming_Soon::init();
		assert_true( has_action( 'wp_enqueue_scripts', array( 'DoughBoss_Growth_Waitlist', 'enqueue_style_for_post' ) ), 'waitlist hooked' );
		assert_true( has_action( 'wp_enqueue_scripts', array( 'DoughBoss_Growth_Coming_Soon', 'enqueue_style_for_post' ) ), 'coming soon hooked' );
		$wl   = dbgr_test_add_post( array( 'ID' => 4201, 'post_content' => 'A [doughboss_growth_waitlist store="revesby"] B' ) );
		$cs   = dbgr_test_add_post( array( 'ID' => 4202, 'post_content' => '[doughboss_growth_coming_soon]' ) );
		$none = dbgr_test_add_post( array( 'ID' => 4203, 'post_content' => 'nothing' ) );
		dbgr_landing_query( $none );
		do_action( 'wp_enqueue_scripts' );
		assert_false( wp_style_is( 'dbgr-coming-soon', 'enqueued' ), 'no shortcode: no style' );
		dbgr_landing_query( $wl );
		do_action( 'wp_enqueue_scripts' );
		assert_true( wp_style_is( 'dbgr-coming-soon', 'enqueued' ), 'waitlist shortcode: style for the head' );
		assert_false( wp_script_is( 'dbgr-waitlist', 'enqueued' ), 'only the style, never the script' );
		$GLOBALS['dbgr_assets']['styles'] = array();
		dbgr_landing_query( $cs );
		do_action( 'wp_enqueue_scripts' );
		assert_true( wp_style_is( 'dbgr-coming-soon', 'enqueued' ), 'coming-soon shortcode: style for the head' );
		$GLOBALS['dbgr_assets']['styles'] = array();
		dbgr_landing_query( 0 );
		do_action( 'wp_enqueue_scripts' );
		assert_false( wp_style_is( 'dbgr-coming-soon', 'enqueued' ), 'not singular: nothing' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 6. Consent: dataLayer only with Tag Manager (the pins live in test-consent.php)                              */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'consent: banner on and Tag Manager off sends no dataLayer script and no events JSON; with Tag Manager ready both are there',
	function () {
		dbgr_consent_page( array( 'consent_banner' => true ) );
		assert_true( wp_script_is( 'dbgr-consent' ), 'consent script enqueued' );
		assert_false( wp_script_is( 'dbgr-datalayer', 'registered' ), 'dataLayer script not even registered' );
		$inline = (string) $GLOBALS['dbgr_assets']['inline']['dbgr-consent'][0]['data'];
		assert_not_contains( '"events"', $inline, 'no events key' );
		assert_not_contains( 'select_store', $inline, 'no taxonomy in the page' );
		assert_not_contains( '"locations"', $inline, 'no shop map' );

		dbgr_test_reset();
		dbgr_consent_page( array( 'consent_banner' => true, 'gtm' => true ), array( 'gtm_container_id' => 'GTM-ABCD123' ) );
		assert_true( wp_script_is( 'dbgr-datalayer' ), 'dataLayer script enqueued when Tag Manager is ready' );
		assert_contains( '"events"', (string) $GLOBALS['dbgr_assets']['inline']['dbgr-consent'][0]['data'], 'events JSON present' );

		dbgr_test_reset();
		dbgr_consent_page( array( 'consent_banner' => true, 'gtm' => true ), array() ); // No container id: not ready.
		assert_false( wp_script_is( 'dbgr-datalayer', 'registered' ), 'gtm flag without a container id is not ready: no dataLayer script' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 7. Owner wording                                                                                            */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'owner wording: the stored recon gap says "the owner", never Elie, and the data still carries the CONFIRM marker',
	function () {
		DoughBoss_Growth::load_module( 'recon' );
		$gaps = DoughBoss_Growth_Recon_Report::unset_params( array( 1 ) );
		assert_contains( 'Until the owner sets it, this check is shown as UNRATED', $gaps['start_tolerance_minutes'], 'the owner, not a name' );
		foreach ( $gaps as $key => $text ) {
			assert_not_contains( 'Elie', $text, 'no name in ' . $key );
			assert_same( '[CONFIRM: ', substr( $text, 0, 10 ), 'the stored gap data keeps its marker: ' . $key );
		}
	}
);

db_test(
	'owner wording: plain_gap turns every stored gap of every module into a sentence with no bracket marker and no name',
	function () {
		$all = array();
		DoughBoss_Growth::load_module( 'consent' );
		DoughBoss_Growth::load_module( 'waitlist' );
		DoughBoss_Growth::load_module( 'attribution' );
		DoughBoss_Growth::load_module( 'conversions' );
		DoughBoss_Growth::load_module( 'leads' );
		DoughBoss_Growth::load_module( 'recon' );
		foreach ( array( 'DoughBoss_Growth_Consent', 'DoughBoss_Growth_Waitlist', 'DoughBoss_Growth_Attribution', 'DoughBoss_Growth_Conversions', 'DoughBoss_Growth_Leads' ) as $class ) {
			if ( class_exists( $class, false ) ) {
				$all = array_merge( $all, array_values( call_user_func( array( $class, 'confirm_gaps' ) ) ) );
			}
		}
		$all = array_merge( $all, array_values( DoughBoss_Growth_Recon_Report::unset_params( array( 1 ) ) ) );
		assert_true( count( $all ) >= 10, 'a good spread of gaps was read (' . count( $all ) . ')' );
		foreach ( $all as $text ) {
			$plain = DoughBoss_Growth_Admin::plain_gap( $text );
			assert_not_contains( '[CONFIRM', $plain, 'no marker: ' . substr( $plain, 0, 50 ) );
			assert_not_contains( 'Elie', $plain, 'no name: ' . substr( $plain, 0, 50 ) );
			assert_same( 1, preg_match( '/^[A-Z0-9].*[.!?]$/Ds', $plain ), 'capital letter and full stop: ' . substr( $plain, 0, 50 ) );
		}
	}
);

db_test(
	'recent failures: a plain sentence per known code, a generic one for an unknown code, the raw code kept as small text',
	function () {
		DoughBoss_Growth::init();
		DoughBoss_Growth_Failures::record( 'module_failed', array( 'module' => 'waitlist' ) );
		DoughBoss_Growth_Failures::record( 'waitlist_signup_mail_failed', array( 'stage' => 'send' ) );
		DoughBoss_Growth_Failures::record( 'zz_made_up_failed', array() );
		$html = dbgr_fail_render();
		assert_contains( 'A part of the plugin hit an error and was stopped', $html, 'known code: sentence' );
		assert_contains( 'A confirmation email for a VIP list sign-up could not be sent.', $html, 'known code: its own sentence' );
		assert_contains( 'Something went wrong in the background.', $html, 'unknown code: generic sentence' );
		assert_contains( '<span class="description"><code>zz_made_up_failed</code>', $html, 'the raw code stays as secondary text' );
		assert_matches( '#<li>[A-Z][^<]*\.\s<span class="description"><code>module_failed</code>#', $html, 'the sentence comes first, the code after' );
		// Every known sentence is a plain sentence: capital, full stop, no code-like words.
		foreach ( array( 'module_load_failed', 'schema_failed', 'settings_save_failed', 'waitlist_purge_failed', 'conversion_enqueue_failed', 'http_transport_error', 'recon_run_failed', 'square_http_429', 'outbox_read_failed', '', 'x' ) as $code ) {
			$sentence = DoughBoss_Growth_Admin::failure_sentence( $code );
			assert_same( 1, preg_match( '/^[A-Z].*\.$/Ds', $sentence ), 'plain sentence for "' . $code . '"' );
			assert_not_contains( '_', $sentence, 'no snake_case in the sentence for "' . $code . '"' );
		}
		assert_same( 'Something went wrong with the queue of messages waiting to be sent.', DoughBoss_Growth_Admin::failure_sentence( 'outbox_read_failed' ), 'a family sentence for an unlisted code' );
		assert_same( DoughBoss_Growth_Admin::failure_sentence( 'x' ), DoughBoss_Growth_Admin::failure_sentence( array() ), 'a non-string code gets the generic sentence' );
	}
);

db_test(
	'destination: only GA4 or Meta credentials are a conversion destination; the waitlist webhook never is (reproduced: it used to count)',
	function () {
		$webhook = array( 'features' => array( 'server_conversions' => '1', 'attribution' => '1' ), 'notify_webhook_url' => 'https://hooks.example-receiver.com.au/path' );
		$result  = DoughBoss_Growth_Settings::apply_save( $webhook );
		assert_false( $result['settings']['features']['server_conversions'], 'webhook alone: server_conversions is forced off' );
		assert_contains( 'server_conversions_requires_destination', implode( ',', $result['errors'] ), 'and says why' );
		assert_false( DoughBoss_Growth_Settings::destination_configured( DoughBoss_Growth_Settings::get_all() ), 'nothing configured: not a destination' );

		$settings                       = DoughBoss_Growth_Settings::defaults();
		$settings['notify_webhook_url'] = 'https://hooks.example-receiver.com.au/path';
		assert_false( DoughBoss_Growth_Settings::destination_configured( $settings ), 'the webhook URL alone is not a destination' );
		$settings['meta_pixel_id'] = '123456789012';
		assert_false( DoughBoss_Growth_Settings::destination_configured( $settings ), 'a Meta id without its token is not one either' );
		putenv( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN=test-token-value' );
		$meta = DoughBoss_Growth_Settings::destination_configured( $settings );
		putenv( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN' );
		assert_true( $meta, 'a Meta id with its token is a destination' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 8. Copy                                                                                                      */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'copy: the waitlist legend is "Your details", the working strings carry no ASCII ellipsis, the form still has one join button',
	function () {
		dbgr_wl_boot();
		$html = DoughBoss_Growth_Waitlist::render_form( array() );
		assert_matches( '#<p class="dbgr-wl__legend" id="dbgr-wl-[0-9]+-legend">Your details</p>#', $html, 'legend' );
		assert_same( 1, substr_count( dbgr_polish_text( $html ), 'Join the VIP list' ), 'the phrase appears once on the form (the button)' );
		$config = DoughBoss_Growth_Waitlist::browser_config();
		assert_same( 'One moment', $config['strings']['wait'], 'no ellipsis' );
		assert_same( 'Sending', $config['strings']['sending'], 'no ellipsis' );
		foreach ( glob( dirname( __DIR__ ) . '/includes/*/*.php' ) as $file ) {
			assert_same( 0, preg_match( "/__\\( '[^']*\\.\\.\\.'/", (string) file_get_contents( $file ) ), 'no translated string ends in "..." in ' . basename( $file ) );
		}
	}
);
