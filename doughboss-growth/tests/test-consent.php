<?php
/**
 * WP-03 tests: consent banner, Consent Mode v2 default, Tag Manager loader, server-side consent reading,
 * the core marketing-config filter and the typed-event list. The ES5 behaviour is covered by consent.test.js
 * and datalayer.test.js.
 *
 * @package DoughBoss_Growth
 */

/**
 * Store settings the way a saved option would be.
 *
 * @param array $features Feature => bool.
 * @param array $extra    Other settings.
 * @return void
 */
function dbgr_consent_store( array $features, array $extra = array() ) {
	update_option( 'doughboss_growth_settings', array_merge( array( 'features' => $features ), $extra ) );
}

/**
 * Run an action and return what it printed.
 *
 * @param string $hook Hook.
 * @return string
 */
function dbgr_consent_capture( $hook ) {
	ob_start();
	do_action( $hook );
	return (string) ob_get_clean();
}

/**
 * Boot the companion with the given settings and run the front-end hooks in WordPress order.
 *
 * @param array $features Feature => bool.
 * @param array $extra    Other settings.
 * @return array { head: string, footer: string }
 */
function dbgr_consent_page( array $features, array $extra = array() ) {
	dbgr_consent_store( $features, $extra );
	DoughBoss_Growth::init();
	do_action( 'wp_enqueue_scripts' );
	return array(
		'head'   => dbgr_consent_capture( 'wp_head' ),
		'footer' => dbgr_consent_capture( 'wp_footer' ),
	);
}

/**
 * The inline config object printed before dbgr-consent.js, decoded.
 *
 * @return array|null
 */
function dbgr_consent_config() {
	$inline = isset( $GLOBALS['dbgr_assets']['inline']['dbgr-consent'][0]['data'] ) ? $GLOBALS['dbgr_assets']['inline']['dbgr-consent'][0]['data'] : '';
	if ( 1 !== preg_match( '/^window\.DoughBossGrowthConfig = (\{.*\});$/s', $inline, $m ) ) {
		return null;
	}
	$decoded = json_decode( $m[1], true );
	return is_array( $decoded ) ? $decoded : null;
}

/**
 * A temporary events.json copy with a change applied.
 *
 * @param callable $mutate Receives the decoded array by value and returns the changed array (or a raw string).
 * @return string File path.
 */
function dbgr_consent_events_copy( $mutate ) {
	$data   = json_decode( file_get_contents( DOUGHBOSS_GROWTH_DIR . 'content/events.json' ), true );
	$data   = call_user_func( $mutate, $data );
	$path   = tempnam( sys_get_temp_dir(), 'dbgr-events-' );
	file_put_contents( $path, is_string( $data ) ? $data : json_encode( $data ) );
	return $path;
}

/* ---------------------------------------------------------------------------------------------------------- */
/* Everything off: inert                                                                                       */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'flags off: nothing printed, nothing enqueued, no hook added, core config untouched',
	function () {
		$page = dbgr_consent_page( array() );
		assert_same( '', $page['head'], 'wp_head prints nothing' );
		assert_same( '', $page['footer'], 'wp_footer prints nothing' );
		assert_same( array(), $GLOBALS['dbgr_assets']['scripts'], 'no script enqueued' );
		assert_same( array(), $GLOBALS['dbgr_assets']['styles'], 'no style enqueued' );
		if ( dbgr_test_can_subprocess() ) { // Where tests run in an isolated process the class cannot have been loaded by an earlier test; in-process it may have been.
			assert_false( class_exists( 'DoughBoss_Growth_Consent', false ), 'the module file is not even loaded' );
		}
		$config = array( 'enabled' => false, 'metaPixelId' => '123456789012', 'tiktokPixelId' => 'abc' );
		assert_same( $config, apply_filters( 'doughboss_marketing_config', $config ), 'core marketing config is passed through untouched' );
		assert_false( has_filter( 'doughboss_marketing_config' ), 'no filter registered on core config' );
	}
);

db_test(
	'direct init() with the flag off adds no hook (idempotent guard inside the module)',
	function () {
		DoughBoss_Growth::load_module( 'consent' );
		$before = dbgr_test_hook_names();
		DoughBoss_Growth_Consent::init();
		assert_same( $before, dbgr_test_hook_names(), 'no hook added when consent_banner is off' );
		assert_same( array( 'measurement' => false, 'advertising' => false, 'chosen' => false, 'version' => '' ), DoughBoss_Growth_Consent::current(), 'current() is the closed shape while the banner is off' );
	}
);

db_test(
	'gtm saved on without the banner: both stay off and nothing prints (dependency enforced by settings)',
	function () {
		$page = dbgr_consent_page( array( 'gtm' => true ), array( 'gtm_container_id' => 'GTM-ABCD123' ) );
		assert_same( '', $page['head'], 'nothing in wp_head' );
		assert_same( '', $page['footer'], 'nothing in wp_footer' );
		assert_false( DoughBoss_Growth_Settings::enabled( 'gtm' ), 'gtm is not effective' );
	}
);

db_test(
	'the disable-only filter switches the banner off; a filter cannot switch it on',
	function () {
		add_filter( 'doughboss_growth_feature_enabled', '__return_false' );
		$page = dbgr_consent_page( array( 'consent_banner' => true ) );
		assert_same( '', $page['footer'], 'filtered off: no banner' );

		dbgr_test_reset();
		add_filter( 'doughboss_growth_feature_enabled', '__return_true' );
		$page = dbgr_consent_page( array() );
		assert_same( '', $page['footer'], 'filter true does not enable a saved-off feature' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Banner on, Tag Manager off                                                                                  */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'banner only: banner and Privacy choices button render, scripts enqueue, no Tag Manager anywhere',
	function () {
		$page = dbgr_consent_page( array( 'consent_banner' => true ) );
		assert_same( '', $page['head'], 'wp_head prints nothing without gtm' );
		assert_contains( 'id="dbgr-consent"', $page['footer'], 'banner container' );
		assert_contains( 'role="dialog"', $page['footer'], 'dialog role' );
		assert_contains( 'aria-labelledby="dbgr-consent-title"', $page['footer'], 'named by its title' );
		assert_contains( 'aria-describedby="dbgr-consent-desc"', $page['footer'], 'described by its text' );
		assert_contains( 'data-dbgr-action="accept"', $page['footer'], 'Accept' );
		assert_contains( 'data-dbgr-action="reject"', $page['footer'], 'Reject' );
		assert_contains( 'data-dbgr-action="choose"', $page['footer'], 'Choose' );
		assert_contains( 'data-dbgr-action="save"', $page['footer'], 'Save choices' );
		assert_contains( 'id="dbgr-consent-reopen"', $page['footer'], 'Privacy choices button' );
		assert_matches( '/id="dbgr-consent"[^>]*\shidden>/', $page['footer'], 'the banner starts hidden (the script decides)' );
		assert_matches( '/id="dbgr-consent-reopen"[^>]*\shidden>/', $page['footer'], 'the reopen button starts hidden' );
		assert_not_contains( 'googletagmanager', $page['head'] . $page['footer'], 'no Tag Manager reference' );
		assert_true( wp_script_is( 'dbgr-consent' ), 'the consent script is enqueued' );
		assert_false( wp_script_is( 'dbgr-datalayer' ), 'the dataLayer script is NOT enqueued while Tag Manager is off' );
		assert_true( $GLOBALS['dbgr_assets']['scripts']['dbgr-consent']['footer'], 'consent script in the footer' );
		assert_true( isset( $GLOBALS['dbgr_assets']['styles']['dbgr-consent'] ), 'style enqueued' );
		$config = dbgr_consent_config();
		assert_true( is_array( $config ), 'config object printed and decodes' );
		assert_false( $config['gtm'], 'config: gtm false' );
		assert_same( 'deny', $config['mode'], 'config: deny mode by default' );
		assert_same( '2', $config['consentVersion'], 'config: wording version' );
		assert_false( array_key_exists( 'locations', $config ), 'config: no shop map without gtm' );
		assert_false( array_key_exists( 'events', $config ), 'config: no event taxonomy without gtm' );
		assert_not_contains( 'generate_lead', (string) $GLOBALS['dbgr_assets']['inline']['dbgr-consent'][0]['data'], 'no events JSON in the inline script' );
	}
);

db_test(
	'the three choices have identical markup (equal prominence): same class, same element, no extras',
	function () {
		$page = dbgr_consent_page( array( 'consent_banner' => true ) );
		foreach ( array( 'accept', 'reject', 'choose' ) as $action ) {
			assert_matches( '/<button type="button" class="dbgr-btn" data-dbgr-action="' . $action . '"/', $page['footer'], $action . ' button uses the shared class only' );
		}
		assert_false( (bool) preg_match( '/class="dbgr-btn[^"]*(primary|secondary|link|muted|ghost)/', $page['footer'] ), 'no button is styled differently' );
		$css = file_get_contents( DOUGHBOSS_GROWTH_DIR . 'public/css/dbgr-consent.css' );
		assert_false( (bool) preg_match( '/\.dbgr-btn[^{]*\[data-dbgr-action/', $css ), 'the stylesheet has no per-button rule' );
	}
);

db_test(
	'banner copy: neutral, no product, price, dietary or word Minis; privacy link only when a URL is saved and escaped',
	function () {
		$page = dbgr_consent_page( array( 'consent_banner' => true ) );
		assert_not_contains( 'dbgr-consent__link', $page['footer'], 'no policy link while the URL is unset' );
		$visible = strtolower( strip_tags( $page['footer'] ) );
		foreach ( array( 'minis', 'halal', 'price', '$', '%', 'pizza', 'manoush', 'discount' ) as $banned ) {
			assert_false( false !== strpos( $visible, $banned ), 'banner text does not mention ' . $banned );
		}

		dbgr_test_reset();
		$page = dbgr_consent_page( array( 'consent_banner' => true ), array( 'privacy_policy_url' => 'https://example.org/privacy?a=1&b=2' ) );
		assert_contains( 'class="dbgr-consent__link" href="https://example.org/privacy?a=1&#038;b=2"', $page['footer'], 'link printed with the ampersand escaped' );

		dbgr_test_reset();
		$page = dbgr_consent_page( array( 'consent_banner' => true ), array( 'privacy_policy_url' => 'javascript:alert(1)' ) );
		assert_not_contains( 'javascript:', $page['footer'], 'NEGATIVE CONTROL: a javascript: URL never reaches the page' );
		assert_not_contains( 'dbgr-consent__link', $page['footer'], 'and no link at all' );
	}
);

db_test(
	'the banner and every tag stay out of wp-admin',
	function () {
		dbgr_test_set_admin( true );
		$page = dbgr_consent_page( array( 'consent_banner' => true, 'gtm' => true ), array( 'gtm_container_id' => 'GTM-ABCD123' ) );
		assert_same( '', $page['head'], 'no tags in admin' );
		assert_same( '', $page['footer'], 'no banner in admin' );
	}
);

db_test(
	'init() twice does not double-register any hook',
	function () {
		dbgr_consent_store( array( 'consent_banner' => true, 'gtm' => true ), array( 'gtm_container_id' => 'GTM-ABCD123' ) );
		DoughBoss_Growth::init();
		$once = array( dbgr_test_hook_count( 'wp_head' ), dbgr_test_hook_count( 'wp_footer' ), dbgr_test_hook_count( 'wp_enqueue_scripts' ), dbgr_test_hook_count( 'doughboss_marketing_config' ) );
		DoughBoss_Growth_Consent::init();
		DoughBoss_Growth_Consent::init();
		$twice = array( dbgr_test_hook_count( 'wp_head' ), dbgr_test_hook_count( 'wp_footer' ), dbgr_test_hook_count( 'wp_enqueue_scripts' ), dbgr_test_hook_count( 'doughboss_marketing_config' ) );
		assert_same( $once, $twice, 'hook counts unchanged' );
		assert_same( array( 2, 1, 1, 1 ), $once, 'two head callbacks (default, loader), one of each other' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Consent Mode default and Tag Manager                                                                         */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'banner + gtm + valid id: the Consent Mode default prints BEFORE the container, with all four signals denied',
	function () {
		$page = dbgr_consent_page( array( 'consent_banner' => true, 'gtm' => true ), array( 'gtm_container_id' => 'GTM-ABCD123' ) );
		$head = $page['head'];
		$at_default = strpos( $head, 'id="dbgr-consent-default"' );
		$at_gtm     = strpos( $head, 'id="dbgr-gtm"' );
		assert_true( false !== $at_default && false !== $at_gtm && $at_default < $at_gtm, 'default before loader' );
		assert_matches( "/gtag\\('consent', 'default', \\{ ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied', wait_for_update: 500 \\}\\)/", $head, 'four signals denied, wait_for_update 500' );
		$before_replay = substr( $head, $at_default, strpos( $head, "w.gtag('consent', 'update'", $at_default ) - $at_default );
		assert_not_contains( "'granted'", $before_replay, 'nothing is granted before the replay branch' );
		assert_contains( "'GTM-ABCD123'", $head, 'container id passed to the loader' );
		assert_contains( 'https://www.googletagmanager.com/gtm.js?id=', $head, 'the one loader' );
		assert_same( 1, substr_count( $head, 'googletagmanager.com' ), 'exactly one vendor loader' );
		assert_not_contains( '<noscript', $head . $page['footer'], 'no noscript frame (it would load the container for visitors who cannot consent)' );
		assert_not_contains( '<iframe', $head . $page['footer'], 'no iframe' );
		assert_not_contains( 'fbq', $head, 'no Meta pixel call from the plugin' );
		assert_not_contains( 'google-analytics.com', $head, 'no direct GA call' );
		$config = dbgr_consent_config();
		assert_true( true === $config['gtm'], 'config: gtm true' );
		assert_true( isset( $config['events']['generate_lead']['form'] ), 'config carries the event allow-list' );
		assert_true( wp_script_is( 'dbgr-datalayer' ), 'banner + Tag Manager ready: the dataLayer script is enqueued' );
		assert_same( array( 'dbgr-consent' ), $GLOBALS['dbgr_assets']['scripts']['dbgr-datalayer']['deps'], 'datalayer depends on consent' );
		assert_true( array_key_exists( 'locations', $config ), 'and the shop map key is present' );
	}
);

db_test(
	'replay: the default script restores a stored choice only for the current wording version (logic proven in consent.test.js)',
	function () {
		$js = DoughBoss_Growth_Tags::consent_default_script( '7', 'deny' );
		assert_contains( 'var V = "7";', $js, 'version embedded as a JSON string' );
		assert_contains( 'o.v === V', $js, 'version compared strictly' );
		assert_contains( "w.gtag('consent', 'update'", $js, 'update call exists' );
		assert_contains( 'dbgr_consent=', $js, 'reads the dbgr_consent cookie' );
	}
);

db_test(
	'consent_default mode: deny keeps analytics_storage denied; opt_out grants only analytics_storage; unknown value = deny',
	function () {
		$deny = DoughBoss_Growth_Tags::consent_default_script( '1', 'deny' );
		assert_contains( "analytics_storage: 'denied', wait_for_update: 500", $deny, 'deny mode' );
		$opt = DoughBoss_Growth_Tags::consent_default_script( '1', 'opt_out' );
		assert_contains( "ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'granted', wait_for_update: 500", $opt, 'opt_out grants measurement only' );
		assert_same( 'deny', DoughBoss_Growth_Tags::mode(), 'default setting' );
		dbgr_consent_store( array( 'consent_banner' => true ), array( 'consent_default' => 'allow_all' ) );
		assert_same( 'deny', DoughBoss_Growth_Tags::mode(), 'unknown value falls back to deny' );
		dbgr_consent_store( array( 'consent_banner' => true ), array( 'consent_default' => 'opt_out' ) );
		assert_same( 'opt_out', DoughBoss_Growth_Tags::mode(), 'opt_out accepted' );
		assert_same( 'deny', DoughBoss_Growth_Tags::consent_default_script( '1', 'banana' ) === $deny ? 'deny' : 'differs', 'an unknown mode in the script generator also means deny' );
	}
);

db_test(
	'the inline default cannot be broken out of: a hostile version string is escaped',
	function () {
		$js = DoughBoss_Growth_Tags::consent_default_script( '</script><script>alert(1)//"\'&', 'deny' );
		assert_not_contains( '</script>', $js, 'no closing tag' );
		assert_not_contains( '<script>', $js, 'no opening tag' );
		assert_contains( '\\u003C\\/script\\u003E', $js, 'markup characters hex-escaped' );
	}
);

db_test(
	'container id validation: only GTM- plus 4 to 10 capitals or digits; every near miss is refused',
	function () {
		foreach ( array( 'GTM-ABCD', 'GTM-ABCD123', 'GTM-0123456789', 'GTM-A1B2C3' ) as $good ) {
			assert_true( DoughBoss_Growth_Tags::valid_container_id( $good ), 'valid: ' . $good );
			assert_contains( $good, DoughBoss_Growth_Tags::gtm_script( $good ), 'script built for ' . $good );
		}
		foreach (
			array(
				'', 'GTM-', 'GTM-ABC', 'GTM-ABCDEFGHIJK', 'gtm-abcd123', 'GTM-abcd123', 'GTM_ABCD123', ' GTM-ABCD123', "GTM-ABCD123\n",
				"GTM-ABCD123'); alert(1);//", 'GTM-ABCD123</script>', 'G-ABCD1234', 'GTM-ÄBCD123', 'GTM-ABCD 123', null, 12345, array( 'GTM-ABCD123' ),
			) as $bad
		) {
			assert_false( DoughBoss_Growth_Tags::valid_container_id( $bad ), 'NEGATIVE: refused ' . var_export( $bad, true ) );
			assert_same( '', is_string( $bad ) ? DoughBoss_Growth_Tags::gtm_script( $bad ) : '', 'no script for ' . var_export( $bad, true ) );
		}
		// Through the saved settings: a bad id is dropped by the sanitiser, so nothing prints and Tag Manager stays off.
		$page = dbgr_consent_page( array( 'consent_banner' => true, 'gtm' => true ), array( 'gtm_container_id' => "GTM-ABCD123');alert(1);//" ) );
		assert_same( '', $page['head'], 'bad saved id: nothing printed' );
		assert_not_contains( 'alert', $page['head'] . $page['footer'] . wp_json_encode( $GLOBALS['dbgr_assets']['inline'] ), 'the hostile value appears nowhere' );
		assert_false( DoughBoss_Growth_Tags::ready(), 'not ready' );
		$config = dbgr_consent_config();
		assert_true( false === $config['gtm'], 'config reports gtm false' );
	}
);

db_test(
	'banner + gtm but no container id saved: nothing printed in the head; the admin tab says so',
	function () {
		$page = dbgr_consent_page( array( 'consent_banner' => true, 'gtm' => true ) );
		assert_same( '', $page['head'], 'nothing in wp_head' );
		assert_contains( 'id="dbgr-consent"', $page['footer'], 'banner itself still works' );
		ob_start();
		DoughBoss_Growth_Consent::render_tab();
		$tab = ob_get_clean();
		assert_contains( 'Not loading', $tab, 'tab reports Tag Manager not loading' );
		assert_contains( 'A valid Tag Manager container id', $tab, 'tab names the gap' );
	}
);

db_test(
	'an unreadable events.json keeps Tag Manager off entirely (fail closed) and the dispatcher list empty',
	function () {
		dbgr_consent_store( array( 'consent_banner' => true, 'gtm' => true ), array( 'gtm_container_id' => 'GTM-ABCD123' ) );
		DoughBoss_Growth::init();
		assert_true( DoughBoss_Growth_Tags::ready(), 'control: ready with the real file' );
		foreach (
			array(
				'missing file' => '/nonexistent/events.json',
				'not json'     => dbgr_consent_events_copy(
					function () {
						return '{not json';
					}
				),
				'wrong schema' => dbgr_consent_events_copy(
					function ( $d ) {
						$d['schema_version'] = 2;
						return $d;
					}
				),
			) as $label => $path
		) {
			DoughBoss_Growth_Consent::set_events_path_override( $path );
			assert_same( array(), DoughBoss_Growth_Consent::events(), $label . ': empty event list' );
			assert_false( DoughBoss_Growth_Tags::ready(), $label . ': Tag Manager not ready' );
			assert_same( '', dbgr_consent_capture( 'wp_head' ), $label . ': nothing printed' );
		}
		DoughBoss_Growth_Consent::set_events_path_override( null );
		assert_true( DoughBoss_Growth_Tags::ready(), 'restored' );
	}
);

db_test(
	'validate_events rejects every structural defect and a reserved or personal-looking parameter name',
	function () {
		$real = json_decode( file_get_contents( DOUGHBOSS_GROWTH_DIR . 'content/events.json' ), true );
		assert_count( 13, DoughBoss_Growth_Consent::validate_events( $real ), 'control: the real file yields all 13 events' );
		$cases = array(
			'no events'            => function ( $d ) { unset( $d['events'] ); return $d; },
			'empty names'          => function ( $d ) { $d['event_names'] = array(); return $d; },
			'name missing in map'  => function ( $d ) { $d['event_names'][] = 'ghost_event'; return $d; },
			'extra event in map'   => function ( $d ) { $d['events']['ghost_event'] = array( 'params' => array() ); return $d; },
			'bad event name'       => function ( $d ) { $d['event_names'][0] = 'Select-Store'; return $d; },
			'reserved param event' => function ( $d ) { $d['events']['select_store']['params']['event'] = array( 'type' => 'string' ); return $d; },
			'reserved param gtm'   => function ( $d ) { $d['events']['select_store']['params']['gtm'] = array( 'type' => 'string' ); return $d; },
			'unknown type'         => function ( $d ) { $d['events']['select_store']['params']['store']['type'] = 'html'; return $d; },
			'empty enum'           => function ( $d ) { $d['events']['select_store']['params']['store']['values'] = array(); return $d; },
			'enum with an object'  => function ( $d ) { $d['events']['select_store']['params']['store']['values'] = array( array( 'x' ) ); return $d; },
			'params not an array'  => function ( $d ) { $d['events']['select_store']['params'] = 'x'; return $d; },
		);
		foreach ( $cases as $label => $mutate ) {
			assert_same( array(), DoughBoss_Growth_Consent::validate_events( call_user_func( $mutate, $real ) ), 'NEGATIVE: ' . $label );
		}
		assert_same( array(), DoughBoss_Growth_Consent::validate_events( null ), 'null' );
		assert_same( array(), DoughBoss_Growth_Consent::validate_events( 'x' ), 'string' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Core bridge filter                                                                                          */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'doughboss_marketing_config: enabled true, both pixel ids emptied, every other core key kept',
	function () {
		dbgr_consent_page( array( 'consent_banner' => true ) );
		$core   = array(
			'enabled'            => false,
			'metaPixelId'        => '123456789012345',
			'tiktokPixelId'      => 'CABCDEFG',
			'consentVersion'     => '2026-07',
			'adpilotServerReady' => false,
		);
		$result = apply_filters( 'doughboss_marketing_config', $core );
		assert_same( true, $result['enabled'], 'enabled' );
		assert_same( '', $result['metaPixelId'], 'Meta pixel id emptied (core never calls fbq)' );
		assert_same( '', $result['tiktokPixelId'], 'TikTok pixel id emptied (core never calls ttq)' );
		assert_same( '2026-07', $result['consentVersion'], 'other keys kept' );
		assert_same( false, $result['adpilotServerReady'], 'other keys kept (2)' );
		assert_same( array( 'enabled' => true, 'metaPixelId' => '', 'tiktokPixelId' => '' ), apply_filters( 'doughboss_marketing_config', null ), 'a non-array input still yields a safe config' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Cookie parsing and the server-side reading                                                                  */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'parse_cookie: accepts exactly {v,m,a,ts} with the current version, integers only',
	function () {
		$good = '{"v":"1","m":1,"a":0,"ts":1790899200}';
		assert_same( array( 'm' => 1, 'a' => 0, 'ts' => 1790899200 ), DoughBoss_Growth_Consent::parse_cookie( $good, '1' ), 'valid cookie parsed' );
		assert_same( array( 'm' => 1, 'a' => 0, 'ts' => 1790899200 ), DoughBoss_Growth_Consent::parse_cookie( addslashes( $good ), '1' ), 'WordPress-slashed cookie parsed' );
		$bad = array(
			'wrong version'          => '{"v":"2","m":1,"a":0,"ts":1790899200}',
			'numeric version'        => '{"v":1,"m":1,"a":0,"ts":1790899200}',
			'm is 2'                 => '{"v":"1","m":2,"a":0,"ts":1790899200}',
			'm is a string'          => '{"v":"1","m":"1","a":0,"ts":1790899200}',
			'a is true'              => '{"v":"1","m":1,"a":true,"ts":1790899200}',
			'a is a float'           => '{"v":"1","m":1,"a":1.0,"ts":1790899200}',
			'ts zero'                => '{"v":"1","m":1,"a":0,"ts":0}',
			'ts negative'            => '{"v":"1","m":1,"a":0,"ts":-5}',
			'ts string'              => '{"v":"1","m":1,"a":0,"ts":"1790899200"}',
			'missing a'              => '{"v":"1","m":1,"ts":1790899200}',
			'a list, not an object'  => '[1,0,1790899200]',
			'not json'               => 'm=1&a=1',
			'empty'                  => '',
			'too long'               => '{"v":"1","m":1,"a":0,"ts":1790899200,"pad":"' . str_repeat( 'x', 500 ) . '"}',
		);
		foreach ( $bad as $label => $raw ) {
			assert_same( null, DoughBoss_Growth_Consent::parse_cookie( $raw, '1' ), 'NEGATIVE: ' . $label );
		}
		assert_same( null, DoughBoss_Growth_Consent::parse_cookie( $good, '' ), 'an empty configured version never matches' );
		assert_same( null, DoughBoss_Growth_Consent::parse_cookie( array( $good ), '1' ), 'an array cookie' );
		assert_same( null, DoughBoss_Growth_Consent::parse_cookie( null, '1' ), 'null' );
	}
);

db_test(
	'current(): closed when the banner is off; deny until chosen; opt_out measures before a choice; a stored choice wins; an old-wording cookie is ignored',
	function () {
		$_COOKIE['dbgr_consent'] = '{"v":"2","m":1,"a":1,"ts":1790899200}';
		assert_same( array( 'measurement' => false, 'advertising' => false, 'chosen' => false, 'version' => '' ), DoughBoss_Growth_Consent::current(), 'banner off: closed even with a cookie present' );

		dbgr_consent_store( array( 'consent_banner' => true ) );
		unset( $_COOKIE['dbgr_consent'] );
		assert_same( array( 'measurement' => false, 'advertising' => false, 'chosen' => false, 'version' => '2' ), DoughBoss_Growth_Consent::current(), 'deny mode, no cookie' );

		$_COOKIE['dbgr_consent'] = '{"v":"2","m":1,"a":0,"ts":1790899200}';
		assert_same( array( 'measurement' => true, 'advertising' => false, 'chosen' => true, 'version' => '2' ), DoughBoss_Growth_Consent::current(), 'stored measurement-only choice' );
		$_COOKIE['dbgr_consent'] = '{"v":"2","m":0,"a":0,"ts":1790899200}';
		assert_same( array( 'measurement' => false, 'advertising' => false, 'chosen' => true, 'version' => '2' ), DoughBoss_Growth_Consent::current(), 'stored reject' );
		$_COOKIE['dbgr_consent'] = '{"v":"0","m":1,"a":1,"ts":1790899200}';
		assert_same( array( 'measurement' => false, 'advertising' => false, 'chosen' => false, 'version' => '2' ), DoughBoss_Growth_Consent::current(), 'old wording version: not a valid choice, asked again' );
		$_COOKIE['dbgr_consent'] = 'garbage';
		assert_same( false, DoughBoss_Growth_Consent::current()['measurement'], 'garbage cookie: denied' );

		dbgr_consent_store( array( 'consent_banner' => true ), array( 'consent_default' => 'opt_out' ) );
		unset( $_COOKIE['dbgr_consent'] );
		assert_same( array( 'measurement' => true, 'advertising' => false, 'chosen' => false, 'version' => '2' ), DoughBoss_Growth_Consent::current(), 'opt_out: measurement on, advertising off, not chosen' );
		$_COOKIE['dbgr_consent'] = '{"v":"2","m":0,"a":0,"ts":1790899200}';
		assert_same( false, DoughBoss_Growth_Consent::current()['measurement'], 'opt_out: a stored reject beats the default' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Shop map and browser config                                                                                 */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'location_map: only shops whose core slug is exactly a taxonomy store slug; objects or arrays; unmapped shops carry no store',
	function () {
		dbgr_consent_store( array( 'consent_banner' => true ) );
		DoughBoss_Locations::$rows = array(
			(object) array( 'id' => '1', 'slug' => 'revesby' ),
			(object) array( 'id' => 2, 'slug' => 'roselands-centro' ),
			array( 'id' => 3, 'slug' => 'bankstown' ),
			(object) array( 'id' => 0, 'slug' => 'roselands' ),
			(object) array( 'id' => 5, 'slug' => 'Revesby' ),
			(object) array( 'id' => 6 ),
			'not a row',
		);
		assert_same( array( '1' => 'revesby', '3' => 'bankstown' ), DoughBoss_Growth_Consent::location_map(), 'only exact slugs with a positive id' );

		DoughBoss_Locations::$throw = true;
		assert_same( array(), DoughBoss_Growth_Consent::location_map(), 'a database error yields an empty map' );
		DoughBoss_Locations::$throw = false;
		DoughBoss_Locations::$rows  = null;
		assert_same( array(), DoughBoss_Growth_Consent::location_map(), 'a null result yields an empty map' );
		DoughBoss_Locations::$rows = array();
	}
);

db_test(
	'browser config carries the shop map only when Tag Manager is live, and survives a hostile slug',
	function () {
		DoughBoss_Locations::$rows = array( (object) array( 'id' => 1, 'slug' => 'revesby' ), (object) array( 'id' => 2, 'slug' => '</script><script>alert(1)</script>' ) );
		dbgr_consent_page( array( 'consent_banner' => true, 'gtm' => true ), array( 'gtm_container_id' => 'GTM-ABCD123' ) );
		$config = dbgr_consent_config();
		assert_same( array( '1' => 'revesby' ), $config['locations'], 'shop map present and the hostile slug is not in it' );
		assert_not_contains( '</script>', $GLOBALS['dbgr_assets']['inline']['dbgr-consent'][0]['data'], 'the inline config can never close the script tag' );
		DoughBoss_Locations::$rows = array();
	}
);

db_test(
	'the printed event list equals content/events.json: 13 names (no hero event), each parameter list present, no personal-data field',
	function () {
		dbgr_consent_page( array( 'consent_banner' => true, 'gtm' => true ), array( 'gtm_container_id' => 'GTM-ABCD123' ) );
		$config = dbgr_consent_config();
		$file   = json_decode( file_get_contents( DOUGHBOSS_GROWTH_DIR . 'content/events.json' ), true );
		assert_same( $file['event_names'], array_keys( $config['events'] ), 'same names in the same order' );
		assert_count( 13, $config['events'], 'thirteen events' );
		assert_false( isset( $config['events']['hero_explore'] ), 'the cancelled hero event is not in the allow-list' );
		assert_false( (bool) preg_match( '/web' . 'gl|sprites|hero/i', wp_json_encode( $config['events'] ) ), 'no hero or 3D renderer value anywhere in the list' );
		foreach ( $file['events'] as $name => $event ) {
			assert_same( array_keys( $event['params'] ), array_keys( $config['events'][ $name ] ), 'params of ' . $name );
		}
		assert_same( array( 'revesby', 'bankstown', 'roselands' ), DoughBoss_Growth_Consent::store_slugs(), 'store slugs from the taxonomy' );
		assert_same( array( 'SQUARE', 'PAY_AT_SHOP' ), $config['events']['begin_checkout']['payment_method']['values'], 'payment_method union (Square)' );
		foreach ( $config['events'] as $name => $params ) {
			foreach ( array_keys( $params ) as $param ) {
				assert_false( (bool) preg_match( '/email|phone|mobile|address|first_name|last_name|full_name|customer/', $param ), 'no personal-data parameter: ' . $name . '.' . $param );
			}
		}
	}
);

db_test(
	'events.json is current against web/src/lib/analytics/events.ts (source hash); skipped when the web tree is absent',
	function () {
		$ts = dirname( DOUGHBOSS_GROWTH_DIR ) . '/web/src/lib/analytics/events.ts';
		if ( ! is_file( $ts ) ) {
			dbgr_test_skip( 'web/src/lib/analytics/events.ts not found next to the plugin: the export cannot be compared here (vitest and export-events.ts --check do it in the repository)' );
			return;
		}
		$file = json_decode( file_get_contents( DOUGHBOSS_GROWTH_DIR . 'content/events.json' ), true );
		assert_same( hash_file( 'sha256', $ts ), $file['source_sha256'], 'the recorded hash is the hash of events.ts (re-run export-events.ts if this fails)' );
		assert_contains( '"SQUARE" | "PAY_AT_SHOP"', file_get_contents( $ts ), 'events.ts carries the Square payment_method union' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Admin tab                                                                                                   */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'admin tab: registered through doughboss_growth_admin_tabs, shows status and the [CONFIRM] gaps, escapes everything',
	function () {
		dbgr_test_set_admin( true );
		dbgr_test_login( array( 'manage_options' ) );
		dbgr_consent_store( array( 'consent_banner' => true, 'gtm' => true ), array( 'gtm_container_id' => 'GTM-ABCD123', 'consent_default' => 'opt_out' ) );
		DoughBoss_Growth::init();
		$_GET['tab'] = 'consent';
		ob_start();
		DoughBoss_Growth_Admin::render_page();
		$html = ob_get_clean();
		assert_contains( 'Consent and tags', $html, 'tab label' );
		assert_contains( 'Loading container GTM-ABCD123', $html, 'status row' );
		assert_contains( 'Notice and opt-out', $html, 'opt_out explained' );
		assert_contains( 'Notice-and-opt-out is selected', $html, 'opt_out needs confirmation' );
		assert_contains( 'Privacy-policy URL', $html, 'privacy gap' );
		assert_contains( 'You (and a solicitor) to review the banner wording', $html, 'wording gap' );
		assert_contains( 'Inside the Tag Manager container', $html, 'container wiring gap' );
		assert_contains( 'Core 2.43.2 does not tell the browser which payment method', $html, 'begin_checkout gap' );
		assert_not_contains( '[CONFIRM', $html, 'no bracket marker reaches the owner' );
		assert_not_contains( 'Elie', $html, 'the owner is not named in the tab' );
		assert_not_contains( '<script', $html, 'no script in the tab' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Static guarantees over the shipped files                                                                    */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'shipped files: no outbound call, no pixel call, no innerHTML, no Minis, no vendor host outside the one loader',
	function () {
		$root  = DOUGHBOSS_GROWTH_DIR;
		$files = array(
			'includes/consent/class-doughboss-growth-consent.php',
			'includes/consent/class-doughboss-growth-tags.php',
			'public/js/dbgr-consent.js',
			'public/js/dbgr-datalayer.js',
			'public/css/dbgr-consent.css',
			'content/events.json',
		);
		foreach ( $files as $file ) {
			$text = file_get_contents( $root . $file );
			assert_true( is_string( $text ) && '' !== $text, $file . ' exists' );
			assert_false( (bool) preg_match( '/minis/i', $text ), $file . ' never says Minis' );
			assert_false( (bool) preg_match( '/wp_remote_|curl_|file_get_contents\(\s*[\'"]https?:/', $text ), $file . ' makes no outbound request' );
			assert_false( (bool) preg_match( '/\binnerHTML\b|\bouterHTML\b|insertAdjacentHTML|document\.write|\beval\(|new Function/', $text ), $file . ' uses no dynamic markup API' );
		}
		foreach ( array( 'public/js/dbgr-consent.js', 'public/js/dbgr-datalayer.js' ) as $file ) {
			$text = file_get_contents( $root . $file );
			assert_false( (bool) preg_match( '/googletagmanager|google-analytics|facebook|fbq\(|ttq\.|XMLHttpRequest|fetch\(|sendBeacon|new Image/', $text ), $file . ' contacts no vendor and sends nothing itself' );
			assert_matches( "/^\\/\\*\\*.*?\\*\\/\\n\\(function \\(\\) \\{\\n\\t'use strict';/s", $text, $file . ' is an IIFE that starts with use strict' );
		}
		$tags = file_get_contents( $root . 'includes/consent/class-doughboss-growth-tags.php' );
		assert_same( 1, substr_count( preg_replace( '#/\*.*?\*/#s', '', $tags ), 'googletagmanager.com' ), 'the one Tag Manager host appears once in executable code' );
		foreach ( array( 'consent', 'tags' ) as $name ) {
			$source = file_get_contents( $root . 'includes/consent/class-doughboss-growth-' . $name . '.php' );
			assert_matches( "/^<\\?php\\n\\/\\*\\*.*?\\*\\/\\n\\n\\/\\/ Exit if accessed directly\\.\\nif \\( ! defined\\( 'ABSPATH' \\) \\) \\{\\n\\texit;\\n\\}/s", $source, $name . ' has the ABSPATH guard' );
			assert_matches( '/\nfinal class DoughBoss_Growth_/', $source, $name . ' is a final class' );
			assert_false( (bool) preg_match( '/update_option|add_option|delete_option|set_transient|\$wpdb|wp_insert_post|add_role|add_cap/', $source ), $name . ' writes nothing (no option, transient, table, post, role or capability)' );
		}
	}
);
