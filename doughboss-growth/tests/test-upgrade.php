<?php
/**
 * Upgrade-safety and owner-facing admin tests (WP-16 follow-up).
 *
 *  1. A release that raises the schema version must not switch the public duties off until a manager opens wp-admin
 *     (storage_ready() compares with DOUGHBOSS_GROWTH_DB_MIN_COMPAT; maybe_upgrade() is the repair step).
 *  2. The settings option carries its own version, keeps keys a newer release stored when an older one saves, and has a
 *     once-only, idempotent migration hook.
 *  3. The owner gates are printed on the Settings tab, under each switch, in plain words.
 *  4. The waitlist purge is scheduled by install(), so deactivate and reactivate with the waitlist off keeps the promise.
 *  5. get_all() sanitises once per change of the option, not once per call.
 *  6. Owner copy: no raw codes, no developer bracket marker, no personal name; consent labels, help text and the
 *     Plugins-screen Settings link.
 *  7. The plugin header names a Domain Path only when that folder exists.
 *
 * Self-contained: the helpers are named dbgr_up_*. Sub-process cases are skipped, visibly, where a sub-process cannot run.
 *
 * @package DoughBoss_Growth
 */

/**
 * Create every real companion table on the harness's SQLite database and, optionally, store a schema version.
 *
 * @param string|null $version Stored schema version, or null to store none.
 * @return void
 */
function dbgr_up_storage( $version = null ) {
	$GLOBALS['wpdb']->use_sqlite();
	foreach ( DoughBoss_Growth::schemas() as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
	dbgr_test_describe_tables();
	if ( null !== $version ) {
		update_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION, $version );
	}
}

/**
 * How many cron events exist for a hook.
 *
 * @param string $hook Hook.
 * @return int
 */
function dbgr_up_events( $hook ) {
	$n = 0;
	foreach ( $GLOBALS['dbgr_cron'] as $event ) {
		if ( $event['hook'] === $hook ) {
			++$n;
		}
	}
	return $n;
}

/**
 * Failure codes currently held.
 *
 * @return array
 */
function dbgr_up_codes() {
	return array_column( DoughBoss_Growth_Failures::all(), 'code' );
}

/**
 * Render the Growth admin page as a manager.
 *
 * @param array $get Query string.
 * @return string
 */
function dbgr_up_render( array $get = array() ) {
	$_GET = $get;
	dbgr_test_login( array( 'manage_doughboss' ) );
	ob_start();
	DoughBoss_Growth_Admin::render_page();
	return ob_get_clean();
}

/**
 * Run code in a sub-process in which the named option cannot be written (update_option() returns false, nothing changes).
 *
 * @param string $option Option name.
 * @param string $code   Code to run after the bootstrap; it must echo one JSON document.
 * @return array|null Decoded output, or null when no sub-process can run (a visible skip is recorded).
 */
function dbgr_up_run_refusing( $option, $code ) {
	if ( ! dbgr_test_can_subprocess() ) {
		dbgr_test_skip( 'sub-process unavailable: the refused-write case is not exercised' );
		return null;
	}
	$prelude = "function update_option( \$name, \$value, \$autoload = null ) {\n"
		. "\tif ( " . var_export( $option, true ) . " === \$name ) { return false; }\n"
		. "\tdbgr_option_guard( \$name, 'update' );\n"
		. "\tif ( array_key_exists( \$name, \$GLOBALS['dbgr_options'] ) && \$GLOBALS['dbgr_options'][ \$name ] === \$value ) { return false; }\n"
		. "\t\$GLOBALS['dbgr_options'][ \$name ] = \$value;\n"
		. "\treturn true;\n}\n";
	$result  = dbgr_test_subprocess( $code, $prelude );
	$data    = json_decode( $result['out'], true );
	assert_true( is_array( $data ), 'the sub-process answered (' . $result['err'] . ' / ' . $result['out'] . ')' );
	return is_array( $data ) ? $data : null;
}

/* ---------------------------------------------------------------------------------------------------------- */
/* 1. A schema-bumping release keeps the public duties running (finding 1)                                      */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'schema floor: DOUGHBOSS_GROWTH_DB_MIN_COMPAT is defined, well formed and never above the schema version; a bad floor falls back to the strict reading',
	function () {
		assert_true( defined( 'DOUGHBOSS_GROWTH_DB_MIN_COMPAT' ), 'the compatibility floor constant exists' );
		assert_matches( '/^\d+\.\d+\.\d+$/D', DOUGHBOSS_GROWTH_DB_MIN_COMPAT, 'a three-part version (the build script and the repair step compare it with version_compare)' );
		assert_true( version_compare( DOUGHBOSS_GROWTH_DB_MIN_COMPAT, DOUGHBOSS_GROWTH_DB_VERSION, '<=' ), 'the floor is never above the schema version' );
		assert_same( DOUGHBOSS_GROWTH_DB_MIN_COMPAT, DoughBoss_Growth_Activator::min_compat_version(), 'the accessor reads the constant' );
		assert_same( DOUGHBOSS_GROWTH_DB_VERSION, DoughBoss_Growth_Activator::db_version(), 'and the schema version' );

		DoughBoss_Growth_Activator::set_version_override( '1.2.0', '1.1.0' );
		assert_same( '1.1.0', DoughBoss_Growth_Activator::min_compat_version(), 'a floor below the schema version is used as it is' );
		foreach ( array( '9.9.9', '', 'x', '1.1', '1.1.0-beta', "1.1.0\n" ) as $bad ) {
			DoughBoss_Growth_Activator::set_version_override( '1.2.0', $bad );
			assert_same( '1.2.0', DoughBoss_Growth_Activator::min_compat_version(), 'a floor of ' . var_export( $bad, true ) . ' is ignored: the schema version is the floor (fail closed)' );
		}
		DoughBoss_Growth_Activator::set_version_override( null, null );
		assert_same( DOUGHBOSS_GROWTH_DB_VERSION, DoughBoss_Growth_Activator::db_version(), 'the seam is released' );
	}
);

db_test(
	'finding 1: a plugin replaced by a release with a higher schema version keeps opt-out, export, erasure, purge, the lead record and the shortcode stubs working before any manager opens wp-admin',
	function () {
		dbgr_up_storage( '1.1.0' ); // What the previous release stored.
		DoughBoss_Growth_Activator::set_version_override( '1.2.0', '1.1.0' ); // The new release: schema 1.2.0, runs on 1.1.0 tables.
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'lead_form' => true ) ) );
		assert_true( DoughBoss_Growth_Activator::storage_ready(), 'storage is ready on the older, compatible tables' );
		assert_false( DoughBoss_Growth_Activator::schema_current(), 'although the schema is not at the new version yet' );

		dbgr_test_set_admin( false ); // A public request: no manager has opened wp-admin since the upload.
		DoughBoss_Growth::init();
		do_action( 'rest_api_init' );

		assert_true( DoughBoss_Growth::module_running( 'waitlist' ), 'the always-on waitlist module runs (opt-out, exporter, eraser, purge)' );
		assert_true( in_array( '/doughboss-growth/v1/waitlist/unsubscribe', array_keys( $GLOBALS['dbgr_rest_routes'] ), true ), 'the opt-out route exists' );
		assert_true( dbgr_test_hook_count( 'wp_privacy_personal_data_exporters' ) > 0, 'the privacy exporter is registered' );
		assert_true( dbgr_test_hook_count( 'wp_privacy_personal_data_erasers' ) > 0, 'the privacy eraser is registered' );
		assert_true( has_action( DoughBoss_Growth_Activator::PURGE_HOOK ) > 0, 'the purge has its callback' );
		assert_true( DoughBoss_Growth::module_running( 'leads' ), 'the lead form module runs' );
		assert_true( DoughBoss_Growth::module_running( 'attribution' ), 'and the module that records a lead runs' );
		assert_true( false !== has_filter( 'doughboss_catering_enquiry_created', array( 'DoughBoss_Growth_Attribution', 'on_enquiry_created' ) ), 'the enquiry is recorded' );
		assert_true( shortcode_exists( 'doughboss_growth_party_sizer' ), 'a hand-made page holding a companion shortcode prints nothing instead of the raw tag' );
		assert_same( '', do_shortcode( '[doughboss_growth_party_sizer]' ) === '[doughboss_growth_party_sizer]' ? 'raw tag leaked' : '', 'the raw tag is not printed' );

		// Control: a release whose code CANNOT run on the old tables raises the floor, and then the modules wait (fail closed).
		dbgr_test_reset();
		dbgr_up_storage( '1.1.0' );
		DoughBoss_Growth_Activator::set_version_override( '1.2.0', '1.2.0' );
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'lead_form' => true ) ) );
		assert_false( DoughBoss_Growth_Activator::storage_ready(), 'floor raised: not ready on 1.1.0 tables' );
		DoughBoss_Growth::init();
		do_action( 'rest_api_init' );
		assert_false( DoughBoss_Growth::module_running( 'waitlist' ), 'floor raised: the modules wait for the upgrade' );
		assert_false( shortcode_exists( 'doughboss_growth_party_sizer' ), 'and no stub is registered on an unconfirmed schema' );
		assert_false( in_array( '/doughboss-growth/v1/waitlist/unsubscribe', array_keys( $GLOBALS['dbgr_rest_routes'] ), true ), 'floor raised: no opt-out route yet' );

		// A rollback: tables newer than the code are fine (a newer release only adds nullable or defaulted columns).
		dbgr_test_reset();
		dbgr_up_storage( '1.3.0' );
		DoughBoss_Growth_Activator::set_version_override( '1.1.0', '1.1.0' );
		assert_true( DoughBoss_Growth_Activator::storage_ready(), 'older code on newer tables is ready' );
		assert_true( DoughBoss_Growth_Activator::schema_current(), 'and the schema counts as current' );
		dbgr_test_login( array( 'manage_options' ) );
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_same( '1.3.0', get_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION ), 'the repair step never lowers the stored version' );
	}
);

db_test(
	'finding 1: the repair step (maybe_upgrade) brings a compatible but older schema up to the new version on the first manager request, even when no table or column is missing',
	function () {
		dbgr_up_storage( '1.1.0' );
		DoughBoss_Growth_Activator::set_version_override( '1.2.0', '1.1.0' );
		assert_same( array(), DoughBoss_Growth_Activator::schema_problems(), 'set-up: nothing is missing (only an index or a default changed in this release)' );

		dbgr_test_login( array( 'read' ) );
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_same( '1.1.0', get_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION ), 'a subscriber triggers nothing' );
		assert_same( array(), $GLOBALS['dbgr_dbdelta'], 'no dbDelta for a subscriber' );

		dbgr_test_login( array( 'manage_options' ) );
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_same( '1.2.0', get_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION ), 'the first manager request records the new schema version' );
		assert_true( DoughBoss_Growth_Activator::schema_current(), 'the schema is now current' );
		assert_count( count( DoughBoss_Growth::schemas() ), $GLOBALS['dbgr_dbdelta'], 'dbDelta ran once per statement' );
		assert_same( array(), dbgr_up_codes(), 'no failure is held' );

		$GLOBALS['dbgr_dbdelta'] = array();
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_same( array(), $GLOBALS['dbgr_dbdelta'], 'the next request has nothing to do (the hourly throttle holds)' );
	}
);

db_test(
	'finding 1: the version write is read back against the NEW schema version, so a refused write on a compatible old version is a recorded failure, not a success (sub-process)',
	function () {
		$code = <<<'CODE'
DoughBoss_Growth_Activator::set_version_override( '1.2.0', '1.1.0' );
$GLOBALS['wpdb']->use_sqlite();
foreach ( DoughBoss_Growth::schemas() as $sql ) {
	$GLOBALS['wpdb']->create_table_from_mysql( $sql );
}
dbgr_test_describe_tables();
$GLOBALS['dbgr_options']['doughboss_growth_db_version'] = '1.1.0';
$ok = DoughBoss_Growth_Activator::install();
echo json_encode( array(
	'ok'      => $ok,
	'stored'  => get_option( 'doughboss_growth_db_version' ),
	'ready'   => DoughBoss_Growth_Activator::storage_ready(),
	'current' => DoughBoss_Growth_Activator::schema_current(),
	'codes'   => array_column( DoughBoss_Growth_Failures::all(), 'code' ),
) );
CODE;
		$data = dbgr_up_run_refusing( 'doughboss_growth_db_version', $code );
		if ( null === $data ) {
			return;
		}
		assert_same( false, $data['ok'], 'install() says it did not finish' );
		assert_same( '1.1.0', $data['stored'], 'the old version is still stored' );
		assert_same( true, $data['ready'], 'the modules keep running on the compatible tables' );
		assert_same( false, $data['current'], 'but the schema is not current' );
		assert_same( array( 'schema_version_save_failed' ), $data['codes'], 'and the owner can see why' );
	}
);

db_test(
	'finding 1: Status "Database tables" says an unfinished upgrade is unfinished (working, not Ready)',
	function () {
		dbgr_up_storage( '1.1.0' );
		DoughBoss_Growth_Activator::set_version_override( '1.2.0', '1.1.0' );
		DoughBoss_Growth::init();
		$html = dbgr_up_render(); // Rendering opens no repair: the repair runs on admin_init, not here.
		preg_match( '#<th scope="row">Database tables</th><td>([^<]*)</td>#', $html, $m );
		$text = isset( $m[1] ) ? html_entity_decode( $m[1], ENT_QUOTES ) : '';
		assert_contains( 'Working, but the upgrade of the database tables has not finished', $text, 'not "Ready"' );
		assert_not_contains( 'Ready', $text, 'never called Ready before the upgrade is recorded' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 2. Settings version, roll back then save, migration hook (finding 2)                                          */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'finding 2: a save records settings_version inside the existing option, and the runtime view always carries this code\'s version',
	function () {
		$result = DoughBoss_Growth_Settings::apply_save( array( 'features' => array( 'coming_soon' => '1' ) ) );
		assert_true( $result['saved'], 'saved' );
		$stored = get_option( DoughBoss_Growth_Settings::OPTION );
		assert_same( DoughBoss_Growth_Settings::SETTINGS_VERSION, $stored['settings_version'], 'the version is stored (an int) inside the settings option' );
		assert_true( is_int( $stored['settings_version'] ), 'as an int' );
		assert_same( array( 'doughboss_growth_settings' ), array_keys( $GLOBALS['dbgr_options'] ), 'no other option was created' );
		assert_same( DoughBoss_Growth_Settings::SETTINGS_VERSION, DoughBoss_Growth_Settings::get_all()['settings_version'], 'the sanitised view carries the code\'s version' );
		assert_same( DoughBoss_Growth_Settings::SETTINGS_VERSION, DoughBoss_Growth_Settings::sanitize( array( 'settings_version' => 99 ) )['settings_version'], 'a stored value never changes what the code believes' );
		assert_same( 0, DoughBoss_Growth_Settings::stored_version( array( 'features' => array() ) ), 'an option without the key reads as version 0' );
		foreach ( array( 3, '3' ) as $v ) {
			assert_same( 3, DoughBoss_Growth_Settings::stored_version( array( 'settings_version' => $v ) ), 'stored version ' . var_export( $v, true ) );
		}
		foreach ( array( -1, 'x', '1.5', array( 1 ), null, true, 1.5, '' ) as $v ) {
			assert_same( 0, DoughBoss_Growth_Settings::stored_version( array( 'settings_version' => $v ) ), 'an unusable stored version ' . var_export( $v, true ) . ' reads as 0' );
		}
		assert_same( 0, DoughBoss_Growth_Settings::stored_version( 'garbage' ), 'a non-array option reads as 0' );
	}
);

db_test(
	'finding 2: saving after a rollback keeps what a newer release stored (settings and feature flags), drops what the form posts that this version does not define, and records this version',
	function () {
		// A newer release (settings version 2) stored a setting and a feature flag this code has never heard of.
		update_option(
			DoughBoss_Growth_Settings::OPTION,
			array(
				'settings_version'  => 2,
				'future_setting'    => array( 'a' => 1, 'b' => 'two' ),
				'future_text'       => 'kept as written',
				'features'          => array( 'coming_soon' => true, 'future_flag' => true, 'future_flag_off' => false, 'not_a_bool' => 'yes' ),
				'sender_legal_name' => 'Old Name Pty Ltd',
			)
		);
		$result = DoughBoss_Growth_Settings::apply_save(
			array(
				'features'                        => array( 'coming_soon' => '1', 'posted_flag' => '1' ),
				'sender_legal_name'               => 'New Name Pty Ltd',
				'posted_setting'                  => 'must not be stored',
				'DOUGHBOSS_GROWTH_GA4_API_SECRET' => 'must-not-be-stored',
				'future_text'                     => 'the form cannot overwrite a key this code does not define',
			)
		);
		$stored = get_option( DoughBoss_Growth_Settings::OPTION );
		assert_true( $result['saved'], 'saved (read back identical to what was sanitised)' );
		assert_same( array( 'a' => 1, 'b' => 'two' ), $stored['future_setting'], 'the newer setting survives the save' );
		assert_same( 'kept as written', $stored['future_text'], 'and is not overwritten by the form' );
		assert_same( true, $stored['features']['future_flag'], 'a newer feature flag that was on stays on' );
		assert_same( false, $stored['features']['future_flag_off'], 'one that was off stays off' );
		assert_false( array_key_exists( 'not_a_bool', $stored['features'] ), 'only boolean flags are carried over' );
		assert_false( array_key_exists( 'posted_flag', $stored['features'] ), 'a posted flag this code does not define is dropped' );
		assert_false( array_key_exists( 'posted_setting', $stored ), 'a posted setting this code does not define is dropped' );
		assert_not_contains( 'must-not-be-stored', serialize( $stored ), 'secret-looking input is still dropped' );
		assert_same( 'New Name Pty Ltd', $stored['sender_legal_name'], 'known settings take the new value' );
		assert_same( DoughBoss_Growth_Settings::SETTINGS_VERSION, $stored['settings_version'], 'this code\'s version is recorded (the migration steps are idempotent, so the newer release re-runs them)' );
		assert_false( array_key_exists( 'future_setting', DoughBoss_Growth_Settings::get_all() ), 'the runtime view still shows known keys only' );
		assert_false( array_key_exists( 'future_setting', $result['settings'] ), 'and so does the save result' );
		assert_same( DoughBoss_Growth_Settings::FEATURES, array_keys( DoughBoss_Growth_Settings::get_all()['features'] ), 'only the frozen flags are in the runtime view' );

		// Key names that are not plain snake_case are never carried over.
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'Bad Key' => 1, '__proto__' => 2, 'ok_key' => 3, 'features' => array() ) );
		DoughBoss_Growth_Settings::apply_save( array() );
		$stored = get_option( DoughBoss_Growth_Settings::OPTION );
		assert_same( array( true, false, false ), array( array_key_exists( 'ok_key', $stored ), array_key_exists( 'Bad Key', $stored ), array_key_exists( '__proto__', $stored ) ), 'only a plain snake_case key survives' );

		// A corrupt option is not carried over and does not break the save.
		update_option( DoughBoss_Growth_Settings::OPTION, 'garbage' );
		$result = DoughBoss_Growth_Settings::apply_save( array( 'features' => array( 'coming_soon' => '1' ) ) );
		assert_true( $result['saved'], 'a corrupt option is replaced by a clean one' );
		assert_true( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'and the save took effect' );
	}
);

db_test(
	'finding 2: migrate() is a no-op that can run any number of times, and the pattern for a real step is documented in its comment',
	function () {
		$before = array( 'features' => array( 'coming_soon' => true ), 'future_key' => 'x' );
		update_option( DoughBoss_Growth_Settings::OPTION, $before );
		assert_same( true, DoughBoss_Growth_Activator::migrate( 0 ), 'from 0: succeeds' );
		assert_same( true, DoughBoss_Growth_Activator::migrate( 0 ), 'again: succeeds (idempotent)' );
		assert_same( true, DoughBoss_Growth_Activator::migrate( 7 ), 'from any version: succeeds' );
		assert_same( $before, get_option( DoughBoss_Growth_Settings::OPTION ), 'and changes nothing today' );
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-growth-activator.php' );
		assert_contains( 'IDEMPOTENT', $source, 'the pattern documents the idempotency rule' );
		assert_contains( 'RAW option', $source, 'and that a step works on the raw option so unknown keys survive' );
	}
);

db_test(
	'finding 2: the settings option is brought to this version once, on the first manager request, with every other key untouched; nothing else is written',
	function () {
		$migrated = array();
		add_action(
			'doughboss_growth_settings_migrated',
			function ( $from, $to ) use ( &$migrated ) {
				$migrated[] = array( $from, $to );
			},
			10,
			2
		);
		dbgr_up_storage( DOUGHBOSS_GROWTH_DB_VERSION );
		$raw = array( 'features' => array( 'coming_soon' => true, 'future_flag' => true ), 'future_key' => array( 1, 2 ), 'sender_legal_name' => 'Example Pty Ltd' ); // Saved by 0.1.0: no version key.
		update_option( DoughBoss_Growth_Settings::OPTION, $raw );

		dbgr_test_login( array( 'read' ) );
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_same( array(), $migrated, 'a subscriber triggers nothing' );
		assert_same( $raw, get_option( DoughBoss_Growth_Settings::OPTION ), 'and nothing is written for a subscriber' );

		dbgr_test_login( array( 'manage_options' ) );
		DoughBoss_Growth_Activator::maybe_upgrade();
		$stored = get_option( DoughBoss_Growth_Settings::OPTION );
		assert_same( array( array( 0, DoughBoss_Growth_Settings::SETTINGS_VERSION ) ), $migrated, 'the migration ran once, from version 0' );
		assert_same( DoughBoss_Growth_Settings::SETTINGS_VERSION, $stored['settings_version'], 'the stamp is written inside the option' );
		unset( $stored['settings_version'] );
		assert_same( $raw, $stored, 'every other key, including the ones this code does not know, is untouched' );
		assert_true( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'the settings still work' );

		DoughBoss_Growth_Activator::maybe_upgrade();
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_same( 1, count( $migrated ), 'later requests do not run it again' );
		assert_same( 'current', DoughBoss_Growth_Activator::maybe_migrate_settings(), 'the state is "current"' );
		assert_same( array(), array_values( array_diff( array_keys( $GLOBALS['dbgr_options'] ), array( 'doughboss_growth_settings', 'doughboss_growth_db_version' ) ) ), 'no new option was created' );
	}
);

db_test(
	'finding 2: a fresh install, a corrupt option and an option saved by a NEWER release are left alone; a failing or throwing migrator is recorded and retried; activation also migrates',
	function () {
		dbgr_up_storage( DOUGHBOSS_GROWTH_DB_VERSION );
		dbgr_test_login( array( 'manage_options' ) );

		// Fresh install: no settings option, nothing is written.
		assert_same( 'none', DoughBoss_Growth_Activator::maybe_migrate_settings(), 'no option: nothing to migrate' );
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_false( array_key_exists( DoughBoss_Growth_Settings::OPTION, $GLOBALS['dbgr_options'] ), 'a fresh install does not create the settings option' );

		// Corrupt option: the sanitiser already reads it as defaults.
		update_option( DoughBoss_Growth_Settings::OPTION, 'garbage' );
		assert_same( 'none', DoughBoss_Growth_Activator::maybe_migrate_settings(), 'a non-array option is left alone' );
		assert_same( 'garbage', get_option( DoughBoss_Growth_Settings::OPTION ), 'untouched' );

		// A newer release stored it: never lowered.
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'settings_version' => 5, 'features' => array() ) );
		assert_same( 'newer', DoughBoss_Growth_Activator::maybe_migrate_settings(), 'a newer stamp is recognised' );
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_same( 5, get_option( DoughBoss_Growth_Settings::OPTION )['settings_version'], 'the stamp is not lowered' );

		// A migrator that fails (or throws) leaves the stamp unwritten, is held in the failure list and does not throw.
		$raw = array( 'features' => array() );
		update_option( DoughBoss_Growth_Settings::OPTION, $raw );
		assert_same( 'failed', DoughBoss_Growth_Activator::run_settings_migration( '__return_false' ), 'a migrator that returns false fails' );
		assert_same( $raw, get_option( DoughBoss_Growth_Settings::OPTION ), 'the stamp is not written' );
		assert_true( in_array( 'settings_migrate_failed', dbgr_up_codes(), true ), 'and the failure is held' );
		assert_same(
			'failed',
			DoughBoss_Growth_Activator::run_settings_migration(
				function () {
					throw new RuntimeException( 'secret detail jane@example.com' );
				}
			),
			'a migrator that throws fails without throwing'
		);
		assert_not_contains( 'jane@example.com', serialize( get_option( DoughBoss_Growth_Failures::OPTION ) ), 'no exception text is stored' );
		assert_same( 'failed', DoughBoss_Growth_Activator::run_settings_migration( '__return_null' ), 'only a strict true counts as success' );
		assert_same( $raw, get_option( DoughBoss_Growth_Settings::OPTION ), 'still not stamped' );

		// The next request with a working migrator succeeds and clears the failure.
		assert_same( 'migrated', DoughBoss_Growth_Activator::maybe_migrate_settings(), 'retried and migrated' );
		assert_false( in_array( 'settings_migrate_failed', dbgr_up_codes(), true ), 'the failure no longer stands' );

		// Activation migrates too (a host that deactivates and reactivates on upgrade).
		update_option( DoughBoss_Growth_Settings::OPTION, $raw );
		DoughBoss_Growth_Activator::activate();
		assert_same( DoughBoss_Growth_Settings::SETTINGS_VERSION, get_option( DoughBoss_Growth_Settings::OPTION )['settings_version'], 'activation brings the settings up to this version' );
	}
);

db_test(
	'finding 2: a stamp that cannot be written is recorded and not reported as migrated (sub-process)',
	function () {
		$code = <<<'CODE'
$GLOBALS['dbgr_options']['doughboss_growth_settings'] = array( 'features' => array( 'coming_soon' => true ) );
$state = DoughBoss_Growth_Activator::maybe_migrate_settings();
echo json_encode( array(
	'state'   => $state,
	'version' => DoughBoss_Growth_Settings::stored_version(),
	'codes'   => array_column( DoughBoss_Growth_Failures::all(), 'code' ),
) );
CODE;
		$data = dbgr_up_run_refusing( 'doughboss_growth_settings', $code );
		if ( null === $data ) {
			return;
		}
		assert_same( 'failed', $data['state'], 'not "migrated"' );
		assert_same( 0, $data['version'], 'the option still has no stamp' );
		assert_same( array( 'settings_version_save_failed' ), $data['codes'], 'the owner can see it' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 3. Owner gates on the Settings tab (finding 3)                                                                */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'finding 3: every switch carries its "Before you switch this on" note, in plain words, in the plugin itself (the runbook is not in the zip)',
	function () {
		DoughBoss_Growth::init();
		$labels = DoughBoss_Growth_Admin::feature_labels();
		assert_same( DoughBoss_Growth_Settings::FEATURES, array_keys( $labels ), 'a label for each of the eleven flags, in order' );
		foreach ( $labels as $feature => $copy ) {
			assert_same( 3, count( $copy ), $feature . ': name, description and the note' );
			assert_true( '' !== trim( $copy[2] ), $feature . ': the note is not empty' );
			assert_same( 0, preg_match( '/[a-z]+_[a-z_]+|`|\[|CONFIRM|Elie|\bF-\d|lint|dbgr/', $copy[2] ), $feature . ': no setting name, code, marker, personal name or tool jargon in the note' );
			assert_not_contains( 'hero', strtolower( $copy[2] ), $feature . ': no cancelled-feature wording' );
			assert_not_contains( 'minis', strtolower( $copy[2] ), $feature . ': no working name' );
		}

		$html = dbgr_up_render();
		assert_same( 11, substr_count( $html, 'Before you switch this on:' ), 'the note is printed under each of the eleven checkboxes' );
		foreach ( $labels as $feature => $copy ) {
			assert_contains( esc_html( $copy[2] ), $html, $feature . ': its note is on the page' );
		}
		// The status sentence that other code reads sits first and is not changed by the note.
		assert_same( 1, preg_match( '#<strong>Tag Manager</strong></label><br /><span class="description">Loads one Tag Manager container\. Needs the consent banner\. \(Currently inactive\.\)</span><br /><span class="description"><strong>Before you switch this on:</strong>#', $html ), 'the description and state come first, then the note, in a separate line' );

		// The gates that carry a legal or privacy consequence say so.
		foreach ( array( 'attribution', 'lead_form' ) as $feature ) {
			assert_contains( 'privacy policy', $labels[ $feature ][2], $feature . ': the privacy-policy item' );
			assert_contains( 'how long', $labels[ $feature ][2], $feature . ': the retention decision' );
			assert_contains( 'not covered by the WordPress personal-data tools', $labels[ $feature ][2], $feature . ': the erasure gap' );
		}
		foreach ( array( 'lead_form', 'party_sizer' ) as $feature ) {
			assert_contains( 'page caching', $labels[ $feature ][2], $feature . ': the page-cache exclusion' );
		}
		assert_contains( 'sender legal name', $labels['lead_form'][2], 'lead_form: the sender name gate for the marketing box' );
		assert_contains( 'never deleted automatically', $labels['waitlist'][2], 'waitlist: the retention default' );
		assert_contains( 'Square staff data', $labels['timesheet_recon'][2], 'timesheet_recon: the approval gate' );
	}
);

db_test(
	'finding 3: the list is titled "To decide before switching on", has no developer marker, and its wording matches what really blocks a switch',
	function () {
		DoughBoss_Growth::init();
		$html = dbgr_up_render();
		assert_contains( '<h2>To decide before switching on</h2>', $html, 'the heading' );
		assert_not_contains( 'Owner decisions still outstanding', $html, 'the old heading is gone' );
		assert_not_contains( '[CONFIRM', $html, 'no bracket marker' );
		assert_not_contains( 'Each blocks enabling', $html, 'the untrue "each blocks" claim is gone' );
		assert_contains( 'must be filled in before the waitlist will switch on', $html, 'what does block' );
		assert_contains( '<li>Sender legal name, and ABN if shown. Required before the waitlist can be enabled.</li>', $html, 'an item that blocks says so' );
		assert_contains( '<li>Retention period for confirmed waitlist rows. Until set, confirmed rows are never deleted automatically.</li>', $html, 'an item that does not block says what happens instead' );
		assert_same( count( DoughBoss_Growth_Settings::confirm_gaps() ), preg_match_all( '#<h2>To decide before switching on</h2>.*?</ul>#s', $html, $block ) ? substr_count( $block[0][0], '<li>' ) : -1, 'every outstanding decision is listed' );
		assert_same( array( 'sender_legal_name', 'privacy_policy_url', 'retention_confirmed_months', 'gtm_container_id', 'ga4_measurement_id', 'meta_pixel_id' ), array_keys( DoughBoss_Growth_Settings::confirm_gaps() ), 'the stored gap data is unchanged (health() reads its codes)' );
		assert_matches( '/^\[CONFIRM: /', DoughBoss_Growth_Settings::confirm_gaps()['gtm_container_id'], 'and keeps its marker' );

		// The words are true: only the waitlist items stop a switch. Everything else lets the box stay ticked.
		$result = DoughBoss_Growth_Settings::apply_save( array( 'features' => array( 'waitlist' => '1' ) ) );
		assert_false( $result['settings']['features']['waitlist'], 'the waitlist is refused without the sender name and privacy URL (blocks)' );
		$result = DoughBoss_Growth_Settings::apply_save( array( 'features' => array( 'consent_banner' => '1', 'gtm' => '1' ) ) );
		assert_true( $result['settings']['features']['gtm'], 'Tag Manager stays ticked with no container id (does not block; it waits)' );
		$result = DoughBoss_Growth_Settings::apply_save(
			array(
				'features'           => array( 'waitlist' => '1' ),
				'sender_legal_name'  => 'Example Pty Ltd',
				'privacy_policy_url' => 'https://example.com.au/privacy/',
			)
		);
		assert_true( $result['settings']['features']['waitlist'], 'the waitlist switches on without a confirmed-retention period (does not block)' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 4. The waitlist purge is scheduled by install() (finding 4)                                                   */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'finding 4: deactivate then reactivate with the waitlist OFF schedules the purge again, once, daily, under the waitlist module\'s own hook name',
	function () {
		DoughBoss_Growth::load_module( 'waitlist' );
		assert_same( DoughBoss_Growth_Waitlist::PURGE_HOOK, DoughBoss_Growth_Activator::PURGE_HOOK, 'the same hook name as the waitlist module (do not rename)' );
		assert_true( in_array( DoughBoss_Growth_Activator::PURGE_HOOK, DoughBoss_Growth_Activator::CRON_HOOKS, true ), 'and it is in the deactivate and uninstall list' );

		dbgr_up_storage();
		assert_same( array(), array_filter( DoughBoss_Growth_Settings::configured_features() ), 'set-up: every flag off' );
		DoughBoss_Growth_Activator::activate();
		assert_same( 1, dbgr_up_events( DoughBoss_Growth_Activator::PURGE_HOOK ), 'activation schedules the purge' );
		$event = null;
		foreach ( $GLOBALS['dbgr_cron'] as $e ) {
			if ( DoughBoss_Growth_Activator::PURGE_HOOK === $e['hook'] ) {
				$event = $e;
			}
		}
		assert_same( 'daily', $event['recurrence'], 'daily' );
		assert_same( DBGR_TEST_EPOCH + HOUR_IN_SECONDS, $event['timestamp'], 'first run an hour on, as the waitlist module schedules it' );

		DoughBoss_Growth_Activator::deactivate();
		assert_same( 0, dbgr_up_events( DoughBoss_Growth_Activator::PURGE_HOOK ), 'deactivation clears it' );
		DoughBoss_Growth_Activator::activate();
		assert_same( 1, dbgr_up_events( DoughBoss_Growth_Activator::PURGE_HOOK ), 'reactivation puts it back (waitlist still off)' );
		assert_same( array(), array_filter( DoughBoss_Growth_Settings::configured_features() ), 'and no flag was switched on to do it' );

		DoughBoss_Growth_Activator::install();
		DoughBoss_Growth_Activator::install();
		assert_same( 1, dbgr_up_events( DoughBoss_Growth_Activator::PURGE_HOOK ), 'installing again never adds a second event' );
	}
);

db_test(
	'finding 4: a schedule that cannot be written is held in the failure list and is put right when it can; a lost event is restored by the hourly self-check',
	function () {
		dbgr_up_storage();
		$no_daily = function ( $schedules ) {
			unset( $schedules['daily'] );
			return $schedules;
		};
		add_filter( 'cron_schedules', $no_daily );
		assert_true( DoughBoss_Growth_Activator::install(), 'the schema is installed whatever happens to the schedule' );
		assert_same( 0, dbgr_up_events( DoughBoss_Growth_Activator::PURGE_HOOK ), 'nothing was scheduled' );
		assert_true( in_array( 'purge_schedule_failed', dbgr_up_codes(), true ), 'the failure is recorded' );
		assert_false( DoughBoss_Growth_Activator::ensure_purge_scheduled(), 'and reported by the call itself' );

		remove_filter( 'cron_schedules', $no_daily );
		assert_true( DoughBoss_Growth_Activator::ensure_purge_scheduled(), 'once WordPress accepts it, it is scheduled' );
		assert_same( 1, dbgr_up_events( DoughBoss_Growth_Activator::PURGE_HOOK ), 'exactly one event' );
		assert_false( in_array( 'purge_schedule_failed', dbgr_up_codes(), true ), 'and the failure no longer stands' );

		// A lost event (another plugin cleared the cron list) comes back on the next hourly check, and at once on the Growth page.
		$GLOBALS['dbgr_cron'] = array();
		update_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION, DOUGHBOSS_GROWTH_DB_VERSION );
		dbgr_test_login( array( 'manage_options' ) );
		$_GET['page'] = 'doughboss-growth';
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_same( 1, dbgr_up_events( DoughBoss_Growth_Activator::PURGE_HOOK ), 'the self-check restores a lost purge event' );
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_same( 1, dbgr_up_events( DoughBoss_Growth_Activator::PURGE_HOOK ), 'and does not add another' );

		// Nothing outside the companion namespace was touched.
		assert_same( array(), $GLOBALS['dbgr_foreign_writes'], 'no foreign write' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 5. get_all() is memoised on the raw option (finding 5)                                                        */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'finding 5: two reads of an unchanged option sanitise once; a changed option is re-read; a save and the test reset drop the memo',
	function () {
		$passes = function () {
			return DoughBoss_Growth_Settings::sanitise_passes();
		};
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'coming_soon' => true ), 'sender_legal_name' => 'A Pty Ltd' ) );
		$start = $passes();
		$a     = DoughBoss_Growth_Settings::get_all();
		$b     = DoughBoss_Growth_Settings::get_all();
		assert_same( 1, $passes() - $start, 'two reads of an unchanged option are one pass' );
		assert_same( $a, $b, 'and return the same settings' );
		foreach ( DoughBoss_Growth_Settings::FEATURES as $feature ) {
			DoughBoss_Growth_Settings::enabled( $feature );
		}
		DoughBoss_Growth_Settings::get( 'sender_legal_name' );
		DoughBoss_Growth_Settings::configured_features();
		assert_same( 1, $passes() - $start, 'eleven flag checks and two more reads add none' );
		assert_true( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'and the answer is right' );

		// A changed option is noticed at once, through the option API...
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'coming_soon' => false ), 'sender_legal_name' => 'B Pty Ltd' ) );
		assert_false( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'the flag read afresh' );
		assert_same( 'B Pty Ltd', DoughBoss_Growth_Settings::get( 'sender_legal_name' ), 'and so is a text setting' );
		assert_same( 2, $passes() - $start, 'one more pass for the change' );
		// ...and when something rewrites the stored value behind the API's back.
		$GLOBALS['dbgr_options'][ DoughBoss_Growth_Settings::OPTION ]['sender_legal_name'] = 'C Pty Ltd';
		assert_same( 'C Pty Ltd', DoughBoss_Growth_Settings::get( 'sender_legal_name' ), 'a value changed in place is re-read' );
		assert_same( 3, $passes() - $start, 'one more pass' );

		// A corrupt value reads as defaults (everything off) and is memoised like any other.
		update_option( DoughBoss_Growth_Settings::OPTION, 'garbage' );
		assert_false( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'corrupt: off' );
		assert_false( DoughBoss_Growth_Settings::enabled( 'waitlist' ), 'corrupt: off' );
		assert_same( 4, $passes() - $start, 'one pass for the corrupt value' );

		// A save drops the memo, and its read-back is a real pass over what was stored.
		DoughBoss_Growth_Settings::apply_save( array( 'features' => array( 'coming_soon' => '1' ) ) );
		$mark = $passes();
		assert_true( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'the save took effect at once' );
		assert_same( 0, $passes() - $mark, 'and the read after a save is served from the read-back pass' );
		DoughBoss_Growth_Settings::reset_cache();
		DoughBoss_Growth_Settings::get_all();
		assert_same( 1, $passes() - $mark, 'reset_cache() forces one fresh pass' );

		// A returned array is a copy: changing it never changes what the next read returns.
		$all             = DoughBoss_Growth_Settings::get_all();
		$all['features'] = array( 'coming_soon' => false );
		assert_true( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'a caller cannot corrupt the memo' );
	}
);

db_test(
	'finding 5: a request with every flag off sanitises the settings once, not once per module and flag',
	function () {
		update_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION, DOUGHBOSS_GROWTH_DB_VERSION );
		$start = DoughBoss_Growth_Settings::sanitise_passes();
		DoughBoss_Growth::init();
		$used = DoughBoss_Growth_Settings::sanitise_passes() - $start;
		assert_true( $used <= 2, 'init() with every flag off ran ' . $used . ' sanitising passes (was about fifteen)' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 6. Owner copy (finding 6)                                                                                     */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'finding 6: plain_gap() removes the bracket marker and the personal name, and never loses the sentence',
	function () {
		$cases = array(
			'[CONFIRM: sender legal name, and ABN if shown. Required before the waitlist can be enabled.]' => 'Sender legal name, and ABN if shown. Required before the waitlist can be enabled.',
			"[CONFIRM: Elie's approval to read Square staff data before the first run.]"                      => 'Your approval to read Square staff data before the first run.',
			"[CONFIRM: Elie\xE2\x80\x99s approval to read data.]"                                              => 'Your approval to read data.',
			'[CONFIRM: Elie (and a solicitor) to review the banner wording.]'                                 => 'You (and a solicitor) to review the banner wording.',
			'[CONFIRM: start time tolerance. Until Elie sets it, this check is shown as UNRATED.]'            => 'Start time tolerance. Until you set it, this check is shown as UNRATED.',
			'[CONFIRM: GA4 measurement id from the property owned by the right entity.]'                      => 'GA4 measurement id from the property owned by the right business.',
			'[CONFIRM: Meta pixel id from the account owned by the right entity.]'                            => 'Meta pixel id from the account owned by the right business.',
			'[CONFIRM: a list [x] inside]'                                                                    => 'A list [x] inside.',
			'no marker at all'                                                                                => 'No marker at all.',
			''                                                                                                => '',
		);
		foreach ( $cases as $in => $out ) {
			assert_same( $out, DoughBoss_Growth_Admin::plain_gap( $in ), 'plain_gap( ' . $in . ' )' );
		}
		assert_same( '', DoughBoss_Growth_Admin::plain_gap( null ), 'null' );
		assert_same( '', DoughBoss_Growth_Admin::plain_gap( array( 'x' ) ), 'an array' );
	}
);

db_test(
	'finding 6: the consent default is shown as "Ask first" and "Notice and opt-out"; the stored values stay deny and opt_out; the fields carry plain help text',
	function () {
		DoughBoss_Growth::init();
		$html = dbgr_up_render();
		assert_contains( '<option value="deny" selected="selected">Ask first (recommended)</option>', $html, 'deny is shown as Ask first (recommended)' );
		assert_contains( '<option value="opt_out">Notice and opt-out (needs legal advice)</option>', $html, 'opt_out is shown as Notice and opt-out' );
		assert_not_contains( '>deny<', $html, 'the raw word deny is not shown' );
		assert_not_contains( '>opt_out<', $html, 'nor opt_out' );
		assert_same( array( 'deny', 'opt_out' ), DoughBoss_Growth_Settings::CONSENT_DEFAULTS, 'the stored values are unchanged' );
		DoughBoss_Growth_Settings::apply_save( array( 'consent_default' => 'opt_out' ) );
		assert_same( 'opt_out', DoughBoss_Growth_Settings::get( 'consent_default' ), 'saving the second choice stores opt_out' );
		assert_contains( '<option value="opt_out" selected="selected">Notice and opt-out (needs legal advice)</option>', dbgr_up_render(), 'and it shows as selected' );
		assert_contains( 'so "Ask first" is used', DoughBoss_Growth_Admin::refusal_messages()['consent_default'], 'the refusal sentence says Ask first, not deny' );
		assert_not_contains( 'deny', DoughBoss_Growth_Admin::refusal_messages()['consent_default'], 'and not the raw word' );

		foreach ( array( 'consent_text_version', 'retention_pending_days', 'retention_confirmed_months', 'notify_webhook_url' ) as $field ) {
			assert_contains( 'aria-describedby="dbgr-' . $field . '-help"', $html, $field . ': the box points at its help' );
			assert_matches( '#<p class="description" id="dbgr-' . $field . '-help">[^<]{40,}</p>#', $html, $field . ': help text is printed' );
		}
		assert_contains( 'whenever you change the banner wording', $html, 'consent version: when to raise it' );
		assert_contains( 'never deleted automatically', $html, 'confirmed retention: what blank means' );
		assert_contains( 'no name or email address in it', $html, 'webhook: what is sent' );
		assert_not_contains( 'name="dbgr[sender_legal_name]" value="" placeholder="" autocomplete="off" aria-describedby', $html, 'fields without help are unchanged' );
		assert_not_contains( 'hero', strtolower( $html ), 'no cancelled-feature wording on the page' );
		assert_not_contains( 'minis', strtolower( $html ), 'no working name on the page' );
	}
);

db_test(
	'finding 6: the Plugins screen row gets a Settings link (managers, inside wp-admin only)',
	function () {
		$filter = 'plugin_action_links_' . plugin_basename( DOUGHBOSS_GROWTH_FILE );
		assert_same( 'plugin_action_links_doughboss-growth/doughboss-growth.php', $filter, 'the row of this plugin' );

		dbgr_test_set_admin( false );
		DoughBoss_Growth::init();
		assert_same( 0, dbgr_test_hook_count( $filter ), 'a public request registers nothing for it' );

		dbgr_test_reset();
		dbgr_test_set_admin( true );
		DoughBoss_Growth::init();
		assert_same( 1, dbgr_test_hook_count( $filter ), 'wp-admin registers it once' );
		$links = array( '<a href="plugins.php?action=deactivate">Deactivate</a>' );
		assert_same( $links, apply_filters( $filter, $links ), 'a user who cannot manage the companion sees the row as it was' );
		dbgr_test_login( array( 'manage_options' ) );
		$out = apply_filters( $filter, $links );
		assert_count( 2, $out, 'a manager sees one more link' );
		assert_matches( '#^<a href="[^"]*page=doughboss-growth">Settings</a>$#', $out[0], 'Settings comes first and opens the Growth page' );
		assert_same( $links[0], $out[1], 'the existing links follow' );
		assert_same( 'unexpected', DoughBoss_Growth_Admin::plugin_action_links( 'unexpected' ), 'a non-array is passed through' );
	}
);

db_test(
	'finding 6: the timesheet check shows plain sentences, never a raw code, and does not name a person in its headings',
	function () {
		dbgr_recon_setup();
		dbgr_test_core_option( 'doughboss_db_version', '1.0.0' ); // The staff clock data is too old to read.
		$page = function ( array $get ) {
			$_GET = $get;
			dbgr_test_login( array( 'manage_doughboss' ), 2 );
			ob_start();
			DoughBoss_Growth_Recon_Admin::render_page();
			return ob_get_clean();
		};

		$html = $page( array() );
		assert_contains( 'The DoughBoss staff clock data needs updating before it can be read.', $html, 'the clock status is a sentence' );
		assert_not_contains( 'plugin_db_too_old', $html, 'not the code' );
		assert_contains( 'Parameters (you decide every number; blank means not set)', $html, 'the parameters heading does not name a person' );
		assert_not_contains( 'Elie decides', $html, 'no personal name in the heading' );

		$html = $page( array( 'dbgr_recon_status' => 'NOT_RUN', 'dbgr_recon_reason' => 'feature_off' ) );
		assert_contains( 'Run result: Not run. The timesheet check is switched off.', $html, 'the run result is plain words' );
		assert_not_contains( 'NOT_RUN', $html, 'no status code' );
		assert_not_contains( 'feature_off', $html, 'no reason code' );
		$html = $page( array( 'dbgr_recon_status' => 'COMPLETE' ) );
		assert_contains( 'Run result: Finished.', $html, 'a clean run' );
		$html = $page( array( 'dbgr_recon_status' => 'FAILED', 'dbgr_recon_reason' => 'square_http_401' ) );
		assert_contains( 'Run result: Failed. Square refused the request.', $html, 'a Square refusal, by pattern' );
		$html = $page( array( 'dbgr_recon_status' => 'FAILED', 'dbgr_recon_reason' => 'zz_brand_new_code' ) );
		assert_contains( 'Run result: Failed. The check could not run, for a reason this screen does not recognise.', $html, 'an unknown reason gets the generic sentence' );
		assert_not_contains( 'zz_brand_new_code', $html, 'and is never printed' );
		$html = $page( array( 'dbgr_recon_status' => 'WEIRD' ) );
		assert_contains( 'Run result: The result was not recognised.', $html, 'an unknown status is not printed either' );
		assert_not_contains( 'WEIRD', strtoupper( str_replace( 'weird', 'WEIRD', '' ) ) . $html === '' ? '' : str_replace( 'The result was not recognised.', '', $html ) . '', 'sanity' );

		$html = $page( array( 'dbgr_recon_map' => 'unknown_local' ) );
		assert_contains( 'Mapping: That staff member or shop was not found, so nothing was changed.', $html, 'a mapping result is a sentence' );
		assert_not_contains( 'unknown_local', $html, 'not the code' );
		$html = $page( array( 'dbgr_recon_map' => 'zz_unknown_map' ) );
		assert_contains( 'Mapping: The mapping could not be changed.', $html, 'an unknown mapping code gets the generic sentence' );
		assert_not_contains( 'zz_unknown_map', $html, 'and is never printed' );

		// Every code the report can give has a sentence of its own, and none of them contains a code.
		foreach ( array( 'feature_off', 'companion_storage_not_ready', 'run_in_progress', 'storage_write_failed', 'storage_read_failed', 'internal_error', 'no_location_mapping', 'invalid_dates', 'timezone_error', 'unmapped_items', 'plugin_timeclock_missing', 'plugin_version_too_old', 'plugin_db_too_old', 'plugin_storage_not_ready', 'plugin_read_failed', 'plugin_row_cap', 'plugin_data_invalid', 'square_env_missing', 'square_token_missing', 'square_transport', 'square_request_refused', 'square_http_429', 'square_http_5xx', 'square_http_403', 'square_response_invalid', 'square_scope_status', 'square_pagination_loop', 'square_request_invalid', 'core_location_map_missing', 'core_location_map_unreadable' ) as $code ) {
			$sentence = DoughBoss_Growth_Recon_Admin::reason_text( $code );
			assert_same( 0, preg_match( '/[a-z]+_[a-z_]+/', $sentence ), $code . ': the sentence contains no code' );
			assert_not_contains( 'does not recognise', $sentence, $code . ': it has its own sentence (not the generic one)' );
		}
		assert_same( '', DoughBoss_Growth_Recon_Admin::reason_text( '' ), 'a clean run has no reason' );
		assert_same( '', DoughBoss_Growth_Recon_Admin::reason_text( array( 'x' ) ) === '' ? '' : 'not empty', 'a non-string is no reason' );
		dbgr_recon_teardown();
	}
);

db_test(
	'finding 6: the landing pages tab says "was not accepted because it does not meet the wording rules" instead of "fails the lint", and never prints a raw reason code',
	function () {
		dbgr_landing_boot();
		dbgr_test_set_admin( true );
		DoughBoss_Growth_Landing::init();
		dbgr_landing_package( 301, 'Box One', '45', 10, 12 );
		dbgr_landing_core_form( '<div class="db-catering"><p>Join the ' . dbgr_landing_banned_word() . ' list</p></div>' );
		dbgr_test_login( array( 'manage_doughboss' ) );
		ob_start();
		DoughBoss_Growth_Landing::render_admin_tab();
		$html = ob_get_clean();
		assert_contains( 'was not accepted: it does not meet the wording rules', $html, 'the blocked core form is explained in plain words' );
		assert_not_contains( 'lint', strtolower( $html ), 'the word lint is not on the owner screen' );
		assert_not_contains( 'product_name', $html, 'and no code from the reason' );

		$reason = new ReflectionMethod( 'DoughBoss_Growth_Landing', 'reason_text' );
		$reason->setAccessible( true );
		foreach ( array( 'core_location_missing', 'core_form_copy_blocked:product_name', 'claim_not_publishable', 'ledger_invalid', 'error' ) as $code ) {
			assert_not_contains( 'lint', strtolower( $reason->invoke( null, $code ) ), $code . ': no developer word' );
		}
		assert_contains( 'was not accepted because it does not meet the wording rules', $reason->invoke( null, 'core_location_missing' ), 'a shop name or address that fails the rules' );
		$unknown = $reason->invoke( null, 'zz_new_reason_code' );
		assert_not_contains( 'zz_new_reason_code', $unknown, 'an unknown reason is never printed raw' );
		assert_contains( 'for a reason this screen does not recognise', $unknown, 'it gets a generic sentence' );
		dbgr_landing_reset();
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* 7. Manifest (finding 7)                                                                                       */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'finding 7: the plugin header names a Domain Path only when that folder exists (no translations ship, and nothing loads one)',
	function () {
		$plugin    = (string) file_get_contents( DOUGHBOSS_GROWTH_FILE );
		$declares  = 1 === preg_match( '/^[ \t\/*#@]*Domain Path:\s*\S+/mi', $plugin );
		$has_dir   = is_dir( DOUGHBOSS_GROWTH_DIR . 'languages' );
		assert_same( $has_dir, $declares, 'Domain Path is declared exactly when languages/ exists (declared: ' . var_export( $declares, true ) . ', folder: ' . var_export( $has_dir, true ) . ')' );
		if ( ! $has_dir ) {
			$loads = false;
			foreach ( array_merge( glob( DOUGHBOSS_GROWTH_DIR . 'includes/*.php' ), glob( DOUGHBOSS_GROWTH_DIR . 'includes/*/*.php' ), glob( DOUGHBOSS_GROWTH_DIR . 'admin/*.php' ), array( DOUGHBOSS_GROWTH_FILE ) ) as $file ) {
				if ( false !== strpos( (string) file_get_contents( $file ), 'load_plugin_textdomain' ) ) {
					$loads = true;
				}
			}
			assert_false( $loads, 'and with no folder nothing tries to load a translation' );
		}
		assert_matches( '/^[ \t\/*#@]*Text Domain:\s*doughboss-growth\s*$/mi', $plugin, 'the text domain is still declared' );
		// The four release version places still agree (the build script enforces this; the header edit must not have broken it).
		preg_match( '/^ \* Version:\s*(\S+)\s*$/m', $plugin, $header );
		assert_same( DOUGHBOSS_GROWTH_VERSION, $header[1], 'header version equals the constant' );
	}
);
