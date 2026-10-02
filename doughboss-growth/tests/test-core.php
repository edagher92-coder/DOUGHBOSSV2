<?php
/**
 * WP-01 core tests: feature flags, settings, the core gate, the module registry, health and the admin shell.
 *
 * Related WP-01 files: test-core-http.php, test-core-limiter.php, test-core-outbox.php,
 * test-core-lifecycle.php, test-core-scripts.php, test-core-harness.php.
 *
 * @package DoughBoss_Growth
 */

/**
 * Store raw settings the way a saved option would be, bypassing the save-time dependency rules.
 *
 * @param array $features Feature => bool.
 * @param array $extra    Other setting keys.
 * @return void
 */
function dbgr_core_store( array $features, array $extra = array() ) {
	update_option( 'doughboss_growth_settings', array_merge( array( 'features' => $features ), $extra ) );
}

/**
 * Create a throwaway module directory with the given files. Returns the directory with trailing slash.
 *
 * @param array $files Relative path => PHP source.
 * @return string
 */
function dbgr_core_module_dir( array $files ) {
	$dir = sys_get_temp_dir() . '/dbgr-modules-' . bin2hex( random_bytes( 4 ) ) . '/';
	foreach ( $files as $relative => $source ) {
		$path = $dir . $relative;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $source );
	}
	return $dir;
}

/* ---------------------------------------------------------------------------------------------------------- */
/* Feature flags                                                                                               */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'flags: exactly the eleven frozen names, in order, with no hero flag',
	function () {
		assert_same(
			array( 'consent_banner', 'gtm', 'attribution', 'server_conversions', 'landing_pages', 'seo_head', 'lead_form', 'party_sizer', 'coming_soon', 'waitlist', 'timesheet_recon' ),
			DoughBoss_Growth_Settings::FEATURES,
			'the eleven feature flags'
		);
		assert_count( 11, DoughBoss_Growth_Settings::FEATURES, 'eleven flags' );
		assert_false( in_array( 'hero' . '_enhanced', DoughBoss_Growth_Settings::FEATURES, true ), 'the cancelled hero flag does not exist' );
		assert_count( 11, DoughBoss_Growth_Settings::defaults()['features'], 'defaults carry eleven flags' );
	}
);

db_test(
	'flags: every feature is OFF by default (no option stored)',
	function () {
		foreach ( DoughBoss_Growth_Settings::FEATURES as $feature ) {
			assert_false( DoughBoss_Growth_Settings::enabled( $feature ), $feature . ' is off with no option' );
			assert_same( false, DoughBoss_Growth_Settings::defaults()['features'][ $feature ], $feature . ' defaults to false' );
		}
		assert_same( array(), array_filter( DoughBoss_Growth_Settings::configured_features() ), 'no configured flag is on' );
	}
);

db_test(
	'flags: unknown features and non-strings are never enabled',
	function () {
		dbgr_core_store( array( 'made_up' => true ) );
		assert_false( DoughBoss_Growth_Settings::enabled( 'made_up' ), 'unknown feature name' );
		assert_false( DoughBoss_Growth_Settings::enabled( '' ), 'empty name' );
		assert_false( DoughBoss_Growth_Settings::enabled( null ), 'null name' );
		assert_false( DoughBoss_Growth_Settings::enabled( array( 'gtm' ) ), 'array name' );
		assert_false( DoughBoss_Growth_Settings::enabled( 'hero' . '_enhanced' ), 'cancelled hero flag name' );
	}
);

db_test(
	'flags: a corrupted option fails closed to everything off',
	function () {
		foreach ( array( 'garbage', 42, true, null, array( 'features' => 'on' ), array( 'features' => array( 'coming_soon' => 'yes please' ) ), array( 'features' => array( 'coming_soon' => 2 ) ) ) as $bad ) {
			update_option( 'doughboss_growth_settings', $bad );
			assert_false( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'coming_soon stays off for ' . var_export( $bad, true ) );
		}
		// Only true, 1, '1' and 'on' count as on.
		foreach ( array( true, 1, '1', 'on' ) as $good ) {
			dbgr_core_store( array( 'coming_soon' => $good ) );
			assert_true( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'coming_soon on for ' . var_export( $good, true ) );
		}
		foreach ( array( 'true', 'yes', '0', 0, false, null, 'ON ', array() ) as $off ) {
			dbgr_core_store( array( 'coming_soon' => $off ) );
			assert_false( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'coming_soon off for ' . var_export( $off, true ) );
		}
	}
);

db_test(
	'filter doughboss_growth_feature_enabled can only DISABLE a feature',
	function () {
		// Filter tries to enable a feature that is off: stays off.
		add_filter( 'doughboss_growth_feature_enabled', '__return_true', 10, 2 );
		assert_false( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'filter returning true cannot enable an off feature' );
		remove_filter( 'doughboss_growth_feature_enabled', '__return_true', 10 );

		dbgr_core_store( array( 'coming_soon' => true ) );
		assert_true( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'on with no filter' );

		add_filter( 'doughboss_growth_feature_enabled', '__return_true', 10, 2 );
		assert_true( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'filter returning true leaves it on' );
		remove_filter( 'doughboss_growth_feature_enabled', '__return_true', 10 );

		add_filter( 'doughboss_growth_feature_enabled', '__return_false', 10, 2 );
		assert_false( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'filter returning false disables' );
		remove_filter( 'doughboss_growth_feature_enabled', '__return_false', 10 );

		// A careless callback returning null or a truthy non-bool also disables (fails closed).
		foreach ( array( null, 1, 'yes', array( 'x' ) ) as $odd ) {
			add_filter(
				'doughboss_growth_feature_enabled',
				function () use ( $odd ) {
					return $odd;
				},
				10,
				2
			);
			assert_false( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'filter returning ' . var_export( $odd, true ) . ' disables' );
			remove_all_filters( 'doughboss_growth_feature_enabled' );
		}

		// The filter is told which feature, so it can disable one and not another.
		dbgr_core_store( array( 'coming_soon' => true, 'lead_form' => true ) );
		add_filter(
			'doughboss_growth_feature_enabled',
			function ( $enabled, $feature ) {
				return 'lead_form' === $feature ? false : $enabled;
			},
			10,
			2
		);
		assert_true( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'untargeted feature stays on' );
		assert_false( DoughBoss_Growth_Settings::enabled( 'lead_form' ), 'targeted feature is off' );
	}
);

db_test(
	'kill switch DOUGHBOSS_GROWTH_DISABLE turns every feature off and keeps the companion inert',
	function () {
		if ( ! dbgr_test_can_subprocess() ) {
			dbgr_test_skip( 'sub-process unavailable' );
			return;
		}
		$code   = <<<'CODE'
update_option( 'doughboss_growth_settings', array( 'features' => array( 'coming_soon' => true, 'lead_form' => true ) ) );
$before = DoughBoss_Growth_Settings::kill_switch();
DoughBoss_Growth::init();
$rest = array_key_exists( 'rest_api_init', $GLOBALS['dbgr_hooks'] ) ? count( $GLOBALS['dbgr_hooks']['rest_api_init'] ) : 0;
echo json_encode( array(
	'kill'      => $before,
	'coming'    => DoughBoss_Growth_Settings::enabled( 'coming_soon' ),
	'lead'      => DoughBoss_Growth_Settings::enabled( 'lead_form' ),
	'reason'    => DoughBoss_Growth::inert_reason(),
	'hooks'     => dbgr_test_hook_names(),
	'rest_hook' => $rest,
) );
CODE;
		$result = dbgr_test_subprocess( $code, "define( 'DOUGHBOSS_GROWTH_DISABLE', true );" );
		assert_same( 0, $result['exit'], 'sub-process ran: ' . $result['err'] );
		$data = json_decode( $result['out'], true );
		assert_true( is_array( $data ) && true === $data['kill'], 'kill switch detected' );
		assert_false( $data['coming'], 'coming_soon off despite the saved flag' );
		assert_false( $data['lead'], 'lead_form off despite the saved flag' );
		assert_same( 'kill_switch', $data['reason'], 'inert reason' );
		assert_same( 0, $data['rest_hook'], 'no REST routes registered' );
		assert_same( array( 'admin_notices' ), array_values( array_diff( $data['hooks'], array( 'plugins_loaded' ) ) ), 'only the admin notice hook (plus the loader itself) is registered' );

		// Negative control: without the constant the same saved flags are live.
		$control = dbgr_test_subprocess( $code );
		$live    = json_decode( $control['out'], true );
		assert_true( is_array( $live ) && true === $live['coming'], 'control: without the kill switch the flag is on' );
		assert_same( '', $live['reason'], 'control: companion boots without the kill switch' );
	}
);

db_test(
	'kill switch values: any truthy constant stops the companion, false does not',
	function () {
		if ( ! dbgr_test_can_subprocess() ) {
			dbgr_test_skip( 'sub-process unavailable' );
			return;
		}
		$code = "echo json_encode( DoughBoss_Growth_Settings::kill_switch() );";
		foreach ( array( 'true' => 'true', "'1'" => 'true', "'false'" => 'true', 'false' => 'false', '0' => 'false' ) as $literal => $expect ) {
			$result = dbgr_test_subprocess( $code, "define( 'DOUGHBOSS_GROWTH_DISABLE', {$literal} );" );
			assert_same( $expect, trim( $result['out'] ), 'kill switch for constant ' . $literal );
		}
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Dependency rules                                                                                            */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'dependencies: seo_head needs landing_pages, at save time and at runtime (WP-16 integration)',
	function () {
		$result = DoughBoss_Growth_Settings::apply_save( array( 'features' => array( 'seo_head' => '1' ) ) );
		assert_false( $result['settings']['features']['seo_head'], 'seo_head forced off without landing_pages' );
		assert_contains( 'seo_head_requires_landing_pages', implode( ',', $result['errors'] ), 'reported' );
		assert_true( isset( DoughBoss_Growth_Admin::error_messages()['seo_head_requires_landing_pages'] ), 'the error code has admin wording' );

		$result = DoughBoss_Growth_Settings::apply_save( array( 'features' => array( 'seo_head' => '1', 'landing_pages' => '1' ) ) );
		assert_true( $result['settings']['features']['seo_head'], 'seo_head accepted with landing_pages' );
		assert_same( array(), $result['errors'], 'no errors' );

		// Negative control: a stored seo_head flag with landing_pages OFF is inert even if the option was edited directly.
		dbgr_core_store( array( 'seo_head' => true, 'landing_pages' => false ) );
		assert_false( DoughBoss_Growth_Settings::enabled( 'seo_head' ), 'runtime re-check: seo_head off when landing_pages is off' );
	}
);

db_test(
	'dependencies: gtm needs the consent banner, at save time and at runtime',
	function () {
		$result = DoughBoss_Growth_Settings::apply_save( array( 'features' => array( 'gtm' => '1' ) ) );
		assert_false( $result['settings']['features']['gtm'], 'gtm forced off without consent_banner' );
		assert_contains( 'gtm_requires_consent_banner', implode( ',', $result['errors'] ), 'reported' );

		$result = DoughBoss_Growth_Settings::apply_save( array( 'features' => array( 'gtm' => '1', 'consent_banner' => '1' ) ) );
		assert_true( $result['settings']['features']['gtm'], 'gtm accepted with the banner' );
		assert_same( array(), $result['errors'], 'no errors' );
		assert_true( DoughBoss_Growth_Settings::enabled( 'gtm' ), 'gtm effectively on' );

		// A stored gtm flag with the banner OFF is inert at runtime even if someone edited the option directly.
		dbgr_core_store( array( 'gtm' => true, 'consent_banner' => false ) );
		assert_false( DoughBoss_Growth_Settings::enabled( 'gtm' ), 'runtime re-check: gtm off when the banner is off' );

		// The kill filter on the banner cascades to gtm.
		dbgr_core_store( array( 'gtm' => true, 'consent_banner' => true ) );
		add_filter(
			'doughboss_growth_feature_enabled',
			function ( $enabled, $feature ) {
				return 'consent_banner' === $feature ? false : $enabled;
			},
			10,
			2
		);
		assert_false( DoughBoss_Growth_Settings::enabled( 'gtm' ), 'disabling the banner by filter also stops gtm' );
	}
);

db_test(
	'dependencies: server_conversions needs attribution and a configured destination',
	function () {
		$result = DoughBoss_Growth_Settings::apply_save( array( 'features' => array( 'server_conversions' => '1' ) ) );
		assert_false( $result['settings']['features']['server_conversions'], 'forced off with nothing configured' );
		$errors = implode( ',', $result['errors'] );
		assert_contains( 'server_conversions_requires_attribution', $errors, 'attribution required' );
		assert_contains( 'server_conversions_requires_destination', $errors, 'destination required' );

		// A webhook URL is a destination.
		$result = DoughBoss_Growth_Settings::apply_save(
			array(
				'features'           => array( 'server_conversions' => '1', 'attribution' => '1' ),
				'notify_webhook_url' => 'https://hooks.example-receiver.com.au/path',
			)
		);
		assert_true( $result['settings']['features']['server_conversions'], 'accepted with attribution and a webhook URL' );

		// An id without its secret is NOT a destination; the secret is read from the environment, presence only.
		$result = DoughBoss_Growth_Settings::apply_save(
			array(
				'features'           => array( 'server_conversions' => '1', 'attribution' => '1' ),
				'ga4_measurement_id' => 'G-ABCD1234',
			)
		);
		assert_false( $result['settings']['features']['server_conversions'], 'GA4 id without a secret is not enough' );
		putenv( 'DOUGHBOSS_GROWTH_GA4_API_SECRET=test-secret-value' );
		$result = DoughBoss_Growth_Settings::apply_save(
			array(
				'features'           => array( 'server_conversions' => '1', 'attribution' => '1' ),
				'ga4_measurement_id' => 'G-ABCD1234',
			)
		);
		putenv( 'DOUGHBOSS_GROWTH_GA4_API_SECRET' );
		assert_true( $result['settings']['features']['server_conversions'], 'GA4 id plus an environment secret is a destination' );
		assert_not_contains( 'test-secret-value', serialize( $GLOBALS['dbgr_options'] ), 'the secret value is never stored in an option' );
	}
);

db_test(
	'dependencies: waitlist needs sender_legal_name and privacy_policy_url',
	function () {
		$result = DoughBoss_Growth_Settings::apply_save( array( 'features' => array( 'waitlist' => '1' ) ) );
		assert_false( $result['settings']['features']['waitlist'], 'off with neither' );
		assert_contains( 'waitlist_requires_sender_legal_name', implode( ',', $result['errors'] ), 'sender name required' );
		assert_contains( 'waitlist_requires_privacy_policy_url', implode( ',', $result['errors'] ), 'privacy URL required' );

		$result = DoughBoss_Growth_Settings::apply_save( array( 'features' => array( 'waitlist' => '1' ), 'sender_legal_name' => 'Example Pty Ltd' ) );
		assert_false( $result['settings']['features']['waitlist'], 'still off with only the name' );

		$result = DoughBoss_Growth_Settings::apply_save( array( 'features' => array( 'waitlist' => '1' ), 'privacy_policy_url' => '/privacy/' ) );
		assert_false( $result['settings']['features']['waitlist'], 'still off with only the URL' );

		$result = DoughBoss_Growth_Settings::apply_save(
			array(
				'features'           => array( 'waitlist' => '1' ),
				'sender_legal_name'  => 'Example Pty Ltd',
				'privacy_policy_url' => 'https://example.com.au/privacy/',
			)
		);
		assert_true( $result['settings']['features']['waitlist'], 'accepted with both' );
		assert_true( DoughBoss_Growth_Settings::enabled( 'waitlist' ), 'effective once saved' );

		// Runtime guard: blank the name straight in the option and the feature is inert.
		$stored                          = get_option( 'doughboss_growth_settings' );
		$stored['sender_legal_name']     = '';
		update_option( 'doughboss_growth_settings', $stored );
		assert_false( DoughBoss_Growth_Settings::enabled( 'waitlist' ), 'runtime re-check: off when the name is blank' );
	}
);

db_test(
	'dependencies: forced-off prerequisites cascade to dependants in one save',
	function () {
		// consent_banner is fine, gtm depends on it; server_conversions depends on attribution which is off.
		$result = DoughBoss_Growth_Settings::apply_save(
			array(
				'features'           => array( 'gtm' => '1', 'server_conversions' => '1' ),
				'notify_webhook_url' => 'https://hooks.example-receiver.com.au/x',
			)
		);
		assert_false( $result['settings']['features']['gtm'], 'gtm off (no banner)' );
		assert_false( $result['settings']['features']['server_conversions'], 'server_conversions off (no attribution)' );
		assert_same( array(), array_filter( DoughBoss_Growth_Settings::configured_features() ), 'nothing saved as on' );
	}
);

db_test(
	'save: ignores unknown keys, secrets and the cancelled flag, and round-trips cleanly',
	function () {
		$result = DoughBoss_Growth_Settings::apply_save(
			array(
				'features'                         => array( 'coming_soon' => '1', 'hero' . '_enhanced' => '1', 'bogus' => '1' ),
				'DOUGHBOSS_GROWTH_GA4_API_SECRET'  => 'must-not-be-stored',
				'api_secret'                       => 'must-not-be-stored',
				'unknown_key'                      => 'x',
			)
		);
		assert_true( $result['saved'], 'saved and read back identical' );
		$stored = get_option( 'doughboss_growth_settings' );
		assert_same( array_keys( DoughBoss_Growth_Settings::defaults() ), array_keys( $stored ), 'only the frozen setting keys are stored' );
		assert_same( DoughBoss_Growth_Settings::FEATURES, array_keys( $stored['features'] ), 'only the frozen flags are stored' );
		assert_not_contains( 'must-not-be-stored', serialize( $stored ), 'secret-looking input is dropped' );
		assert_true( $stored['features']['coming_soon'], 'the ticked flag is kept' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Sanitising                                                                                                  */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'sanitise: identifiers, URLs and numbers fall back to safe values',
	function () {
		$out = DoughBoss_Growth_Settings::sanitize(
			array(
				'gtm_container_id'           => ' gtm-abcd123 ',
				'ga4_measurement_id'         => 'g-xyz12345',
				'meta_pixel_id'              => '1234567890123',
				'privacy_policy_url'         => 'javascript:alert(1)',
				'notify_webhook_url'         => 'http://hooks.example-receiver.com.au/x',
				'consent_default'            => 'allow_all',
				'retention_pending_days'     => 99999,
				'retention_confirmed_months' => '-4',
				'send_hashed_identifiers'    => 'yes',
			)
		);
		assert_same( 'GTM-ABCD123', $out['gtm_container_id'], 'GTM id upper-cased and trimmed' );
		assert_same( 'G-XYZ12345', $out['ga4_measurement_id'], 'GA4 id upper-cased' );
		assert_same( '1234567890123', $out['meta_pixel_id'], 'pixel id digits' );
		assert_same( '', $out['privacy_policy_url'], 'javascript: URL rejected' );
		assert_same( '', $out['notify_webhook_url'], 'http webhook rejected (https only)' );
		assert_same( 'deny', $out['consent_default'], 'unknown consent default falls back to deny' );
		assert_same( 365, $out['retention_pending_days'], 'pending retention capped' );
		assert_same( null, $out['retention_confirmed_months'], 'confirmed retention stays unset for a negative value' );
		assert_same( 0, $out['send_hashed_identifiers'], 'hashed identifiers off unless truthy by the strict rule' );

		foreach ( array( 'GTM-', 'GTM-AB', 'GTM-ABCDEFGHIJK', 'gtm_abcd1', "GTM-ABC\nD123", '<script>' ) as $bad ) {
			$out = DoughBoss_Growth_Settings::sanitize( array( 'gtm_container_id' => $bad ) );
			assert_same( '', $out['gtm_container_id'], 'bad GTM id rejected: ' . var_export( $bad, true ) );
		}
		foreach ( array( 'https://localhost/x', 'https://10.0.0.5/x', 'https://user:pw@host.example-receiver.com.au/', 'https://host.example-receiver.com.au:8443/', 'ftp://x.example-receiver.com.au/' ) as $bad ) {
			$out = DoughBoss_Growth_Settings::sanitize( array( 'notify_webhook_url' => $bad ) );
			assert_same( '', $out['notify_webhook_url'], 'bad webhook rejected: ' . $bad );
		}
		$out = DoughBoss_Growth_Settings::sanitize( array( 'privacy_policy_url' => '/privacy/' ) );
		assert_same( '/privacy/', $out['privacy_policy_url'], 'site-relative privacy path accepted' );
		$out = DoughBoss_Growth_Settings::sanitize( array( 'privacy_policy_url' => '//evil.example/x' ) );
		assert_same( '', $out['privacy_policy_url'], 'protocol-relative URL rejected' );
	}
);

db_test(
	'teaser direction: default text is neutral and the working name can never be saved into it',
	function () {
		$defaults = DoughBoss_Growth_Settings::defaults();
		assert_same( 'Something exciting is coming', $defaults['coming_soon_headline'], 'default headline' );
		assert_same( 'Be first to know', $defaults['coming_soon_body'], 'default body' );
		$all_text = strtolower( wp_json_encode( $defaults ) );
		assert_not_contains( 'mini', $all_text, 'no "mini" anywhere in the shipped defaults' );
		foreach ( array( 'halal', 'vegan', 'gluten', 'price', '$', 'dozen', 'launch' ) as $claim ) {
			assert_not_contains( $claim, $all_text, 'no "' . $claim . '" claim in the defaults' );
		}
		foreach ( array( 'Minis are coming', 'MINIS', 'the minis', 'MiNiS drop' ) as $bad ) {
			$out = DoughBoss_Growth_Settings::sanitize( array( 'coming_soon_headline' => $bad, 'coming_soon_body' => $bad ) );
			assert_same( 'Something exciting is coming', $out['coming_soon_headline'], 'headline rejects: ' . $bad );
			assert_same( 'Be first to know', $out['coming_soon_body'], 'body rejects: ' . $bad );
		}
		$out = DoughBoss_Growth_Settings::sanitize( array( 'coming_soon_headline' => 'Watch this space', 'coming_soon_body' => 'Join the VIP list' ) );
		assert_same( 'Watch this space', $out['coming_soon_headline'], 'a neutral headline is accepted' );
		assert_same( 'Join the VIP list', $out['coming_soon_body'], 'a neutral body is accepted' );
		assert_same( 'coming-soon', $defaults['coming_soon_page_slug'], 'default slug' );
	}
);

db_test(
	'defaults: consent is denied, hashed identifiers and SEO-plugin JSON-LD are off, confirmed retention is unset',
	function () {
		$d = DoughBoss_Growth_Settings::defaults();
		assert_same( 'deny', $d['consent_default'], 'consent_default deny' );
		assert_same( 0, $d['send_hashed_identifiers'], 'no hashed identifiers' );
		assert_same( 0, $d['seo_jsonld_with_seo_plugin'], 'no JSON-LD beside an SEO plugin' );
		assert_same( null, $d['retention_confirmed_months'], 'confirmed retention unset (no automatic deletion)' );
		assert_same( 30, $d['retention_pending_days'], 'pending retention 30 days' );
		assert_same( '', $d['sender_legal_name'], 'sender name unset' );
		assert_same( '', $d['privacy_policy_url'], 'privacy URL unset' );
	}
);

db_test(
	'confirm gaps: unset owner decisions are listed as [CONFIRM] and shrink as they are supplied',
	function () {
		$gaps = DoughBoss_Growth_Settings::confirm_gaps();
		foreach ( array( 'sender_legal_name', 'privacy_policy_url', 'retention_confirmed_months', 'gtm_container_id', 'ga4_measurement_id', 'meta_pixel_id' ) as $code ) {
			assert_true( isset( $gaps[ $code ] ), 'gap listed: ' . $code );
			assert_matches( '/^\[CONFIRM: /', $gaps[ $code ], 'gap text is a [CONFIRM] marker: ' . $code );
		}
		DoughBoss_Growth_Settings::apply_save( array( 'sender_legal_name' => 'Example Pty Ltd', 'retention_confirmed_months' => '12' ) );
		$gaps = DoughBoss_Growth_Settings::confirm_gaps();
		assert_false( isset( $gaps['sender_legal_name'] ), 'sender gap closed' );
		assert_false( isset( $gaps['retention_confirmed_months'] ), 'retention gap closed' );
		assert_true( isset( $gaps['privacy_policy_url'] ), 'privacy gap still open' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Secrets                                                                                                     */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'secrets: environment first, then constant; unknown names are refused; presence-only reporting',
	function () {
		assert_same( '', DoughBoss_Growth_Settings::secret( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN' ), 'absent secret is empty' );
		assert_false( DoughBoss_Growth_Settings::has_secret( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN' ), 'has_secret false when absent' );
		putenv( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN=  env-token-1  ' );
		assert_same( 'env-token-1', DoughBoss_Growth_Settings::secret( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN' ), 'environment value, trimmed' );
		assert_true( DoughBoss_Growth_Settings::has_secret( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN' ), 'has_secret true' );
		putenv( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN' );

		putenv( 'HOME_TEST_NOT_ALLOWED=abc' );
		assert_same( '', DoughBoss_Growth_Settings::secret( 'HOME_TEST_NOT_ALLOWED' ), 'a name outside the allow-list is refused' );
		assert_same( '', DoughBoss_Growth_Settings::secret( 'PATH' ), 'PATH is refused' );
		assert_same( '', DoughBoss_Growth_Settings::secret( null ), 'null name refused' );
		putenv( 'HOME_TEST_NOT_ALLOWED' );

		if ( dbgr_test_can_subprocess() ) {
			$result = dbgr_test_subprocess( "echo DoughBoss_Growth_Settings::secret( 'DOUGHBOSS_GROWTH_WEBHOOK_SECRET' );", "define( 'DOUGHBOSS_GROWTH_WEBHOOK_SECRET', 'const-secret-2' );" );
			assert_same( 'const-secret-2', $result['out'], 'constant fallback' );
			$result = dbgr_test_subprocess( "echo DoughBoss_Growth_Settings::secret( 'DOUGHBOSS_GROWTH_WEBHOOK_SECRET' );", "putenv( 'DOUGHBOSS_GROWTH_WEBHOOK_SECRET=env-wins' ); define( 'DOUGHBOSS_GROWTH_WEBHOOK_SECRET', 'const-secret-2' );" );
			assert_same( 'env-wins', $result['out'], 'environment beats the constant' );
		} else {
			dbgr_test_skip( 'sub-process unavailable: constant fallback not exercised' );
		}
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Core gate                                                                                                   */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'core gate: boots with core 2.43.2 and registers REST, admin and the loaded action',
	function () {
		assert_true( DoughBoss_Growth::core_ready(), 'stub core is ready' );
		DoughBoss_Growth::init();
		assert_same( '', DoughBoss_Growth::inert_reason(), 'not inert' );
		assert_same( 1, did_action( 'doughboss_growth_loaded' ), 'doughboss_growth_loaded fired once' );
		assert_true( dbgr_test_hook_count( 'rest_api_init' ) >= 1, 'REST routes hooked' );
		assert_true( dbgr_test_hook_count( 'admin_menu' ) >= 1, 'admin menu hooked' );
		DoughBoss_Growth::init();
		assert_same( 1, did_action( 'doughboss_growth_loaded' ), 'a second init() is a no-op' );
	}
);

db_test(
	'core gate: inert (admin notice only) when core is absent, too old or lacks its settings class',
	function () {
		if ( ! dbgr_test_can_subprocess() ) {
			dbgr_test_skip( 'sub-process unavailable' );
			return;
		}
		$probe = <<<'CODE'
DoughBoss_Growth::init();
echo json_encode( array(
	'ready'  => DoughBoss_Growth::core_ready(),
	'reason' => DoughBoss_Growth::inert_reason(),
	'hooks'  => array_values( array_diff( dbgr_test_hook_names(), array( 'plugins_loaded' ) ) ),
	'loaded' => did_action( 'doughboss_growth_loaded' ),
	'ver'    => DoughBoss_Growth::core_version(),
) );
CODE;
		$cases = array(
			'core absent'           => array( '', false, 'core' ),
			'core 2.40.9 (too old)' => array( "define( 'DOUGHBOSS_VERSION', '2.40.9' ); class DoughBoss_Settings {}", false, 'core' ),
			'core 2.41.0 (minimum)' => array( "define( 'DOUGHBOSS_VERSION', '2.41.0' ); class DoughBoss_Settings {}", true, '' ),
			'core 2.44.0'           => array( "define( 'DOUGHBOSS_VERSION', '2.44.0' ); class DoughBoss_Settings {}", true, '' ),
			'version but no class'  => array( "define( 'DOUGHBOSS_VERSION', '2.43.2' );", false, 'core' ),
		);
		foreach ( $cases as $label => $case ) {
			$result = dbgr_test_subprocess( $probe, $case[0], false );
			$data   = json_decode( $result['out'], true );
			assert_true( is_array( $data ), $label . ': sub-process produced JSON (' . $result['err'] . ')' );
			if ( ! is_array( $data ) ) {
				continue;
			}
			assert_same( $case[1], $data['ready'], $label . ': core_ready()' );
			assert_same( $case[2], $data['reason'], $label . ': inert reason' );
			if ( ! $case[1] ) {
				assert_same( array( 'admin_notices' ), $data['hooks'], $label . ': nothing but the admin notice is hooked' );
				assert_same( 0, $data['loaded'], $label . ': doughboss_growth_loaded never fired' );
			} else {
				assert_same( 1, $data['loaded'], $label . ': booted' );
			}
		}
	}
);

db_test(
	'core gate: the inert notice names the minimum version and is shown only to plugin managers',
	function () {
		if ( ! dbgr_test_can_subprocess() ) {
			dbgr_test_skip( 'sub-process unavailable' );
			return;
		}
		$code   = <<<'CODE'
DoughBoss_Growth::init();
ob_start(); DoughBoss_Growth::render_inert_notice(); $anon = ob_get_clean();
dbgr_test_login( array( 'activate_plugins' ) );
ob_start(); DoughBoss_Growth::render_inert_notice(); $admin = ob_get_clean();
echo json_encode( array( 'anon' => $anon, 'admin' => $admin ) );
CODE;
		$result = dbgr_test_subprocess( $code, '', false );
		$data   = json_decode( $result['out'], true );
		assert_same( '', $data['anon'], 'no notice for a visitor' );
		assert_contains( '2.41.0', $data['admin'], 'notice names the minimum version' );
		assert_contains( 'notice-warning', $data['admin'], 'rendered as an admin notice' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Inert by default                                                                                            */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'inert: with every flag off, init() hooks nothing that can change public output',
	function () {
		DoughBoss_Growth::init();
		foreach ( array( 'wp_head', 'wp_footer', 'wp_enqueue_scripts', 'the_content', 'do_shortcode_tag', 'document_title_parts', 'template_redirect', 'rest_post_dispatch', 'rest_request_before_callbacks', 'doughboss_marketing_config', 'wp_privacy_personal_data_exporters', 'wp_privacy_personal_data_erasers', 'wp_robots', 'body_class', 'login_head' ) as $hook ) {
			assert_same( 0, dbgr_test_hook_count( $hook ), $hook . ' has no companion callback' );
		}
		assert_same( array(), $GLOBALS['dbgr_shortcodes'], 'no shortcode registered' );
		assert_same( array(), $GLOBALS['dbgr_assets']['scripts'], 'no script enqueued' );
		assert_same( array(), $GLOBALS['dbgr_assets']['styles'], 'no style enqueued' );
		assert_same( array(), $GLOBALS['dbgr_options'], 'init() wrote no option' );
		assert_same( array(), $GLOBALS['dbgr_cron'], 'init() scheduled nothing' );
		assert_same( array(), $GLOBALS['wpdb']->queries, 'init() ran no database query' );
		assert_same( array(), $GLOBALS['dbgr_http']['calls'], 'init() made no outbound request' );

		// Negative control: hooking a public hook IS visible to the same probe.
		add_action( 'wp_head', '__return_true' );
		assert_same( 1, dbgr_test_hook_count( 'wp_head' ), 'control: the probe sees a wp_head callback' );
	}
);

db_test(
	'inert: with every flag on, a module whose file is absent is simply not active (no error, no partial state)',
	function () {
		dbgr_core_store( array_fill_keys( DoughBoss_Growth_Settings::FEATURES, true ), array( 'sender_legal_name' => 'X Pty Ltd', 'privacy_policy_url' => '/p/', 'notify_webhook_url' => 'https://hooks.example-receiver.com.au/x' ) );
		update_option( 'doughboss_growth_db_version', DOUGHBOSS_GROWTH_DB_VERSION );
		DoughBoss_Growth::init();
		$health = DoughBoss_Growth::health();
		foreach ( $health['modules_active'] as $key => $active ) {
			if ( ! $health['modules_present'][ $key ] ) {
				assert_false( $active, 'module without a file is not active: ' . $key );
			}
		}
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Module registry                                                                                             */
/* ---------------------------------------------------------------------------------------------------------- */

/**
 * Registry entry builder for the registry tests.
 *
 * @param string $file    File.
 * @param string $class   Class.
 * @param array  $features Features.
 * @param array  $extra   Overrides.
 * @return array
 */
function dbgr_core_entry( $file, $class, array $features, array $extra = array() ) {
	return array_merge(
		array(
			'file'          => $file,
			'class'         => $class,
			'files'         => array(),
			'features'      => $features,
			'admin_always'  => false,
			'needs_storage' => false,
		),
		$extra
	);
}

db_test(
	'registry: init() runs only when the file exists AND its flag is on',
	function () {
		$dir = dbgr_core_module_dir(
			array(
				'm/a.php' => "<?php class DBGR_Reg_A { public static \$ran = 0; public static function init() { self::\$ran++; } }",
				'm/b.php' => "<?php class DBGR_Reg_B { public static \$ran = 0; public static function init() { self::\$ran++; } }",
			)
		);
		DoughBoss_Growth::set_registry_override(
			array(
				'a'       => dbgr_core_entry( 'm/a.php', 'DBGR_Reg_A', array( 'lead_form' ) ),
				'b'       => dbgr_core_entry( 'm/b.php', 'DBGR_Reg_B', array( 'coming_soon' ) ),
				'missing' => dbgr_core_entry( 'm/missing.php', 'DBGR_Reg_Missing', array( 'lead_form' ) ),
			),
			$dir
		);
		dbgr_core_store( array( 'lead_form' => true ) );
		DoughBoss_Growth::init();
		assert_same( 1, DBGR_Reg_A::$ran, 'module A: file present and flag on, init ran once' );
		assert_false( class_exists( 'DBGR_Reg_B', false ), 'module B: flag off, its file was never even required' );
		assert_false( class_exists( 'DBGR_Reg_Missing', false ), 'module with no file is skipped without error' );
		$health = DoughBoss_Growth::health();
		assert_same( array( 'a' => true, 'b' => false, 'missing' => false ), $health['modules_active'], 'health reports active modules' );
		assert_same( array( 'a' => true, 'b' => true, 'missing' => false ), $health['modules_present'], 'health reports which files exist' );
		dbgr_test_rmdir( $dir );
	}
);

db_test(
	'registry: a module is required only when wanted, extra files load before the entry file, admin_always follows is_admin()',
	function () {
		$dir = dbgr_core_module_dir(
			array(
				'm/helper.php' => "<?php class DBGR_Reg_Helper { const OK = 1; }",
				'm/c.php'      => "<?php class DBGR_Reg_C { public static \$saw_helper = null; public static \$ran = 0; public static function init() { self::\$saw_helper = class_exists( 'DBGR_Reg_Helper', false ); self::\$ran++; } }",
				'm/d.php'      => "<?php class DBGR_Reg_D { public static \$ran = 0; public static function init() { self::\$ran++; } }",
			)
		);
		DoughBoss_Growth::set_registry_override(
			array(
				'c' => dbgr_core_entry( 'm/c.php', 'DBGR_Reg_C', array( 'coming_soon' ), array( 'files' => array( 'm/helper.php', 'm/nope.php' ) ) ),
				'd' => dbgr_core_entry( 'm/d.php', 'DBGR_Reg_D', array( 'waitlist' ), array( 'admin_always' => true ) ),
			),
			$dir
		);
		dbgr_core_store( array( 'coming_soon' => true ) );
		DoughBoss_Growth::init();
		assert_same( 1, DBGR_Reg_C::$ran, 'C ran' );
		assert_true( DBGR_Reg_C::$saw_helper, 'the helper file was loaded before the entry file ran' );
		assert_false( class_exists( 'DBGR_Reg_D', false ), 'front end: admin_always module with its flag off is not loaded' );

		DoughBoss_Growth::reset_state();
		DoughBoss_Growth::set_time_override( DBGR_TEST_EPOCH );
		dbgr_test_set_admin( true );
		DoughBoss_Growth::set_registry_override(
			array( 'd' => dbgr_core_entry( 'm/d.php', 'DBGR_Reg_D', array( 'waitlist' ), array( 'admin_always' => true ) ) ),
			$dir
		);
		DoughBoss_Growth::init();
		assert_same( 1, DBGR_Reg_D::$ran, 'wp-admin: admin_always module runs although its flag is off' );
		dbgr_test_rmdir( $dir );
	}
);

db_test(
	'registry: needs_storage modules wait for the schema, then run',
	function () {
		$dir = dbgr_core_module_dir( array( 'm/e.php' => "<?php class DBGR_Reg_E { public static \$ran = 0; public static function init() { self::\$ran++; } }" ) );
		DoughBoss_Growth::set_registry_override( array( 'e' => dbgr_core_entry( 'm/e.php', 'DBGR_Reg_E', array( 'lead_form' ), array( 'needs_storage' => true ) ) ), $dir );
		dbgr_core_store( array( 'lead_form' => true ) );
		DoughBoss_Growth::init();
		assert_false( class_exists( 'DBGR_Reg_E', false ), 'schema not installed: the module file is not even loaded' );

		DoughBoss_Growth::reset_state();
		DoughBoss_Growth::set_time_override( DBGR_TEST_EPOCH );
		DoughBoss_Growth::set_registry_override( array( 'e' => dbgr_core_entry( 'm/e.php', 'DBGR_Reg_E', array( 'lead_form' ), array( 'needs_storage' => true ) ) ), $dir );
		update_option( 'doughboss_growth_db_version', DOUGHBOSS_GROWTH_DB_VERSION );
		DoughBoss_Growth::init();
		assert_same( 1, DBGR_Reg_E::$ran, 'schema installed: the module runs' );
		dbgr_test_rmdir( $dir );
	}
);

db_test(
	'registry: a module that throws is contained (fail closed) and the others still run; the log has no message text',
	function () {
		$dir = dbgr_core_module_dir(
			array(
				'm/bad.php'  => "<?php class DBGR_Reg_Bad { public static function init() { throw new RuntimeException( 'secret-detail jane@example.com' ); } }",
				'm/fatal.php' => "<?php class DBGR_Reg_Fatal { public static function init() { return intdiv( 1, 0 ); } }",
				'm/good.php' => "<?php class DBGR_Reg_Good { public static \$ran = 0; public static function init() { self::\$ran++; } }",
			)
		);
		DoughBoss_Growth::set_registry_override(
			array(
				'bad'   => dbgr_core_entry( 'm/bad.php', 'DBGR_Reg_Bad', array( 'lead_form' ) ),
				'fatal' => dbgr_core_entry( 'm/fatal.php', 'DBGR_Reg_Fatal', array( 'lead_form' ) ),
				'good'  => dbgr_core_entry( 'm/good.php', 'DBGR_Reg_Good', array( 'lead_form' ) ),
			),
			$dir
		);
		dbgr_core_store( array( 'lead_form' => true ) );
		$lines = array();
		add_action(
			'doughboss_growth_log',
			function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			}
		);
		DoughBoss_Growth::init();
		assert_same( 1, DBGR_Reg_Good::$ran, 'the healthy module still ran' );
		assert_same( array( 'bad' => false, 'fatal' => false, 'good' => true ), DoughBoss_Growth::health()['modules_active'], 'failed modules are not active' );
		assert_same( 2, count( $lines ), 'one log line per failed module' );
		assert_not_contains( 'secret-detail', implode( "\n", $lines ), 'exception text is not logged' );
		assert_not_contains( 'jane@example.com', implode( "\n", $lines ), 'no personal data is logged' );
		assert_contains( 'RuntimeException', implode( "\n", $lines ), 'only the exception class is logged' );
		dbgr_test_rmdir( $dir );
	}
);

db_test(
	'registry: schemas() gathers the shared tables plus every PRESENT module schema(), even with its flag off',
	function () {
		$dir = dbgr_core_module_dir(
			array(
				'm/s.php'     => "<?php class DBGR_Reg_S { public static function schema() { global \$wpdb; return array( 'CREATE TABLE ' . \$wpdb->prefix . 'doughboss_growth_waitlist (\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY  (id)\n);' ); } }",
				'm/boom.php'  => "<?php class DBGR_Reg_Boom { public static function schema() { throw new RuntimeException( 'x' ); } }",
			)
		);
		DoughBoss_Growth::set_registry_override(
			array(
				's'       => dbgr_core_entry( 'm/s.php', 'DBGR_Reg_S', array( 'waitlist' ) ),
				'boom'    => dbgr_core_entry( 'm/boom.php', 'DBGR_Reg_Boom', array( 'waitlist' ) ),
				'missing' => dbgr_core_entry( 'm/none.php', 'DBGR_Reg_None', array( 'waitlist' ) ),
			),
			$dir
		);
		$tables = DoughBoss_Growth_Activator::expected_tables();
		assert_same( array( 'wp_doughboss_growth_rate', 'wp_doughboss_growth_outbox', 'wp_doughboss_growth_waitlist' ), $tables, 'shared tables first, then the module table (a throwing schema() is skipped)' );
		dbgr_test_rmdir( $dir );
	}
);

db_test(
	'registry: the shipped registry covers every module file in the architecture and names no hero module',
	function () {
		$modules = DoughBoss_Growth::modules();
		assert_same( array( 'ledger', 'consent', 'attribution', 'waitlist', 'coming_soon', 'landing', 'leads', 'conversions', 'recon' ), array_keys( $modules ), 'module keys' );
		$files = array();
		foreach ( $modules as $key => $module ) {
			assert_matches( '#^includes/[a-z]+/class-doughboss-growth-[a-z0-9-]+\.php$#', $module['file'], $key . ': entry file path shape' );
			$files[] = $module['file'];
			foreach ( $module['files'] as $extra ) {
				assert_matches( '#^includes/[a-z]+/class-doughboss-growth-[a-z0-9-]+\.php$#', $extra, $key . ': extra file path shape' );
				$files[] = $extra;
			}
			foreach ( $module['features'] as $feature ) {
				assert_true( in_array( $feature, DoughBoss_Growth_Settings::FEATURES, true ), $key . ': feature ' . $feature . ' is a real flag' );
			}
		}
		assert_same( count( $files ), count( array_unique( $files ) ), 'no file is listed twice' );
		// Every flag is served by at least one module.
		$served = array();
		foreach ( $modules as $module ) {
			$served = array_merge( $served, $module['features'] );
		}
		foreach ( DoughBoss_Growth_Settings::FEATURES as $feature ) {
			assert_true( in_array( $feature, $served, true ), 'flag ' . $feature . ' has a module' );
		}
		assert_not_contains( 'hero', strtolower( wp_json_encode( $modules ) ), 'no hero module' );
		// The files named by the work breakdown for each package are all reachable through the registry.
		foreach ( array(
			'includes/ledger/class-doughboss-growth-ledger.php',
			'includes/consent/class-doughboss-growth-consent.php',
			'includes/consent/class-doughboss-growth-tags.php',
			'includes/attribution/class-doughboss-growth-attribution.php',
			'includes/waitlist/class-doughboss-growth-waitlist.php',
			'includes/waitlist/class-doughboss-growth-waitlist-rest.php',
			'includes/waitlist/class-doughboss-growth-waitlist-privacy.php',
			'includes/waitlist/class-doughboss-growth-coming-soon.php',
			'includes/landing/class-doughboss-growth-landing.php',
			'includes/landing/class-doughboss-growth-landing-seo.php',
			'includes/landing/class-doughboss-growth-landing-schema.php',
			'includes/leads/class-doughboss-growth-leads.php',
			'includes/leads/class-doughboss-growth-party-sizer.php',
			'includes/conversions/class-doughboss-growth-conversions.php',
			'includes/conversions/class-doughboss-growth-ga4.php',
			'includes/conversions/class-doughboss-growth-meta.php',
			'includes/conversions/class-doughboss-growth-offline-export.php',
			'includes/recon/class-doughboss-growth-recon-reader.php',
			'includes/recon/class-doughboss-growth-recon-square.php',
			'includes/recon/class-doughboss-growth-recon-matcher.php',
			'includes/recon/class-doughboss-growth-recon-report.php',
			'includes/recon/class-doughboss-growth-recon-admin.php',
		) as $expected_file ) {
			assert_true( in_array( $expected_file, $files, true ), 'registry reaches ' . $expected_file );
		}
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Health endpoint                                                                                             */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'health: GET /doughboss-growth/v1/health is manager-only, no-store, and reports every flag false by default',
	function () {
		DoughBoss_Growth::init();
		do_action( 'rest_api_init' );
		$route = '/doughboss-growth/v1/health';
		assert_true( isset( $GLOBALS['dbgr_rest_routes'][ $route ] ), 'route registered' );

		$response = dbgr_test_rest_dispatch( 'GET', $route );
		assert_same( 401, $response->get_status(), 'anonymous is refused (401)' );

		dbgr_test_login( array( 'read' ) );
		$response = dbgr_test_rest_dispatch( 'GET', $route );
		assert_same( 403, $response->get_status(), 'a logged-in non-manager is refused (403)' );

		dbgr_test_login( array( 'manage_options' ) );
		$response = dbgr_test_rest_dispatch( 'GET', $route );
		assert_same( 200, $response->get_status(), 'manage_options falls back like the core admin' );
		$data = $response->get_data();
		assert_same( DoughBoss_Growth_Settings::FEATURES, array_keys( $data['flags'] ), 'all eleven flags reported' );
		foreach ( $data['flags'] as $feature => $on ) {
			assert_same( false, $on, 'flag ' . $feature . ' false in /health' );
		}
		foreach ( $data['flags_configured'] as $feature => $on ) {
			assert_same( false, $on, 'configured flag ' . $feature . ' false in /health' );
		}
		assert_same( '0.1.0', $data['plugin_version'], 'plugin version' );
		assert_true( $data['core_ready'], 'core ready in the stubbed run' );
		assert_false( $data['kill_switch'], 'kill switch off' );
		assert_same( 'no-store', $response->get_headers()['Cache-Control'], 'Cache-Control no-store' );

		dbgr_test_login( array( 'manage_doughboss' ) );
		assert_same( 200, dbgr_test_rest_dispatch( 'GET', $route )->get_status(), 'manage_doughboss is allowed' );

		// Only GET exists.
		assert_same( 404, dbgr_test_rest_dispatch( 'POST', $route )->get_status(), 'POST has no route' );
	}
);

db_test(
	'health: never contains a secret value, a token or personal data',
	function () {
		putenv( 'DOUGHBOSS_GROWTH_GA4_API_SECRET=ga4-secret-zzz' );
		putenv( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN=meta-token-zzz' );
		DoughBoss_Growth_Settings::apply_save( array( 'sender_legal_name' => 'Secret Name Pty Ltd', 'privacy_policy_url' => 'https://example.com.au/private-path/', 'gtm_container_id' => 'GTM-ABCD123' ) );
		DoughBoss_Growth::init();
		$json = wp_json_encode( DoughBoss_Growth::health() );
		putenv( 'DOUGHBOSS_GROWTH_GA4_API_SECRET' );
		putenv( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN' );
		foreach ( array( 'ga4-secret-zzz', 'meta-token-zzz', 'Secret Name', 'private-path', 'GTM-ABCD123' ) as $needle ) {
			assert_not_contains( $needle, $json, 'health does not leak: ' . $needle );
		}
		$gaps = DoughBoss_Growth::health()['confirm_gaps'];
		assert_false( in_array( 'sender_legal_name', $gaps, true ), 'gap codes only, and the supplied one is gone' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Admin shell                                                                                                 */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'admin save: capability AND nonce are both required, and every refusal changes nothing',
	function () {
		DoughBoss_Growth::init();
		$_POST = array( 'dbgr' => array( 'features' => array( 'coming_soon' => '1' ) ) );

		// No login.
		assert_throws( 'DBGR_Test_Die', array( 'DoughBoss_Growth_Admin', 'handle_save' ), 'anonymous refused' );
		// Logged in without the capability, WITH a valid nonce.
		dbgr_test_login( array( 'read' ) );
		$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_save_settings' );
		$die                  = assert_throws( 'DBGR_Test_Die', array( 'DoughBoss_Growth_Admin', 'handle_save' ), 'subscriber with a valid nonce refused' );
		assert_same( 403, $die->args['response'], 'status 403' );
		// Capability but no nonce.
		dbgr_test_login( array( 'manage_options' ) );
		unset( $_REQUEST['_wpnonce'] );
		assert_throws( 'DBGR_Test_Die', array( 'DoughBoss_Growth_Admin', 'handle_save' ), 'manager without a nonce refused' );
		// Capability, wrong nonce.
		$_REQUEST['_wpnonce'] = 'deadbeef00';
		assert_throws( 'DBGR_Test_Die', array( 'DoughBoss_Growth_Admin', 'handle_save' ), 'manager with a forged nonce refused' );
		// Capability, nonce for a different action.
		$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'some_other_action' );
		assert_throws( 'DBGR_Test_Die', array( 'DoughBoss_Growth_Admin', 'handle_save' ), 'manager with another action\'s nonce refused' );

		assert_same( array(), $GLOBALS['dbgr_options'], 'no refused request wrote anything' );

		// Both present: saved, then redirected to the settings page.
		$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_save_settings' );
		$redirect             = assert_throws( 'DBGR_Test_Redirect', array( 'DoughBoss_Growth_Admin', 'handle_save' ), 'valid request redirects' );
		assert_contains( 'page=doughboss-growth', $redirect->url, 'back to the Growth page' );
		assert_contains( 'dbgr_saved=1', $redirect->url, 'reports success' );
		assert_true( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'the flag was saved' );
	}
);

db_test(
	'admin save: a dependency failure is reported and the feature stays off',
	function () {
		DoughBoss_Growth::init();
		dbgr_test_login( array( 'manage_doughboss' ) );
		$_POST                = array( 'dbgr' => array( 'features' => array( 'gtm' => '1', 'waitlist' => '1' ) ) );
		$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_save_settings' );
		$redirect             = assert_throws( 'DBGR_Test_Redirect', array( 'DoughBoss_Growth_Admin', 'handle_save' ), 'redirects' );
		assert_contains( 'dbgr_err=', $redirect->url, 'errors passed to the page' );
		assert_contains( 'gtm_requires_consent_banner', $redirect->url, 'gtm error code' );
		assert_contains( 'waitlist_requires_sender_legal_name', $redirect->url, 'waitlist error code' );
		assert_same( array(), array_filter( DoughBoss_Growth_Settings::configured_features() ), 'nothing was switched on' );
	}
);

db_test(
	'admin page: manager-only, escapes stored values, never prints a secret, lists eleven features and no hero',
	function () {
		putenv( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN=do-not-print-me' );
		DoughBoss_Growth_Settings::apply_save( array( 'sender_legal_name' => 'A&B "Q" <b>Pty</b> Ltd' ) );
		DoughBoss_Growth::init();

		assert_throws( 'DBGR_Test_Die', array( 'DoughBoss_Growth_Admin', 'render_page' ), 'anonymous cannot view' );
		dbgr_test_login( array( 'manage_doughboss' ) );
		ob_start();
		DoughBoss_Growth_Admin::render_page();
		$html = ob_get_clean();
		putenv( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN' );

		assert_not_contains( 'value="A&B "Q"', $html, 'stored value is not echoed raw into the attribute' );
		assert_contains( 'value="A&amp;B &quot;Q&quot; Pty Ltd"', $html, 'stored value is sanitised on save and escaped on output' );
		assert_not_contains( '<b>', $html, 'markup in a stored value never reaches the page' );
		assert_not_contains( 'do-not-print-me', $html, 'secret value never printed' );
		assert_contains( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN', $html, 'secret NAME is listed' );
		assert_contains( 'name="_wpnonce"', $html, 'form carries a nonce' );
		assert_contains( 'value="doughboss_growth_save_settings"', $html, 'form posts to the save action' );
		assert_same( 11, preg_match_all( '/name="dbgr\[features\]\[[a-z_]+\]"/', $html ), 'eleven feature checkboxes' );
		assert_not_contains( 'checked="checked"', substr( $html, strpos( $html, '<fieldset>' ), strpos( $html, '</fieldset>' ) - strpos( $html, '<fieldset>' ) ), 'no feature box is ticked by default' );
		assert_not_contains( 'hero', strtolower( $html ), 'no hero text on the page' );
		assert_contains( '[CONFIRM:', $html, 'outstanding owner decisions are shown' );
		assert_contains( 'Currently inactive', $html, 'features report inactive' );
		assert_not_contains( 'minis', strtolower( $html ), 'no working name on the admin page' );
	}
);

db_test(
	'admin tabs: modules add tabs through doughboss_growth_admin_tabs; bad slugs are refused; unknown tab falls back',
	function () {
		DoughBoss_Growth::init();
		assert_false( DoughBoss_Growth_Admin::add_tab( 'Bad Slug', 'x', '__return_true' ), 'uppercase/space slug refused' );
		assert_false( DoughBoss_Growth_Admin::add_tab( 'settings', 'x', '__return_true' ), 'cannot shadow the settings tab' );
		assert_false( DoughBoss_Growth_Admin::add_tab( 'ok', 'x', 'not_a_callable_fn' ), 'non-callable refused' );
		assert_false( DoughBoss_Growth_Admin::add_tab( '../x', 'x', '__return_true' ), 'path-like slug refused' );

		add_action(
			'doughboss_growth_admin_tabs',
			function () {
				DoughBoss_Growth_Admin::add_tab(
					'claims',
					'Claims <b>',
					function () {
						echo '<p id="claims-body">claims tab</p>';
					}
				);
			}
		);
		dbgr_test_login( array( 'manage_doughboss' ) );
		$_GET['tab'] = 'claims';
		ob_start();
		DoughBoss_Growth_Admin::render_page();
		$html = ob_get_clean();
		assert_contains( 'id="claims-body"', $html, 'the module tab body rendered' );
		assert_contains( 'Claims &lt;b&gt;', $html, 'the tab label is escaped' );
		assert_contains( 'nav-tab-active', $html, 'active tab marked' );
		assert_not_contains( 'name="dbgr[', $html, 'the settings form is not on the module tab' );

		$_GET['tab'] = 'does-not-exist';
		ob_start();
		DoughBoss_Growth_Admin::render_page();
		$html = ob_get_clean();
		assert_contains( 'name="dbgr[features]', $html, 'an unknown tab falls back to Settings' );
	}
);

db_test(
	'admin menu: a submenu of the core "doughboss" menu using the core capability with manage_options fallback',
	function () {
		DoughBoss_Growth::init();
		do_action( 'admin_menu' );
		assert_count( 1, $GLOBALS['dbgr_menus'], 'one menu entry' );
		$menu = $GLOBALS['dbgr_menus'][0];
		assert_same( 'doughboss', $menu['parent'], 'under the core menu' );
		assert_same( 'doughboss-growth', $menu['slug'], 'slug' );
		assert_same( 'manage_options', $menu['cap'], 'falls back to manage_options for an owner without the core capability' );
		dbgr_test_login( array( 'manage_doughboss' ) );
		assert_same( 'manage_doughboss', DoughBoss_Growth_Admin::cap(), 'core capability preferred' );
	}
);

db_test(
	'clock: now() follows the harness clock and moves with dbgr_test_advance()',
	function () {
		assert_same( DBGR_TEST_EPOCH, DoughBoss_Growth::now(), 'fixed epoch' );
		assert_same( DBGR_TEST_EPOCH + 90, dbgr_test_advance( 90 ), 'advance' );
		DoughBoss_Growth::set_time_override( null );
		assert_true( abs( time() - DoughBoss_Growth::now() ) <= 2, 'released clock follows real time' );
	}
);
