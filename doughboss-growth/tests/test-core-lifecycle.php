<?php
/**
 * WP-01 tests: activation, schema install, self-heal, deactivation (page drafting) and uninstall.
 *
 * @package DoughBoss_Growth
 */

/**
 * Preload "core" data that the companion must never touch.
 *
 * @return array Snapshot of the core names set up.
 */
function dbgr_lifecycle_core_state() {
	dbgr_test_core_option( 'doughboss_settings', array( 'ordering_open' => 1 ) );
	dbgr_test_core_option( 'doughboss_db_version', '1.23.0' );
	dbgr_test_core_option( 'blogname', 'Dough Boss' );
	$GLOBALS['wpdb']->tables_created[] = 'wp_doughboss_orders';
	$GLOBALS['wpdb']->tables_created[] = 'wp_doughboss_catering_enquiries';
	return array(
		'options' => array( 'doughboss_settings', 'doughboss_db_version', 'blogname' ),
		'tables'  => array( 'wp_doughboss_orders', 'wp_doughboss_catering_enquiries' ),
	);
}

db_test(
	'install: dbDelta runs for every registered schema, then the DB version is stored only if every table exists',
	function () {
		assert_false( DoughBoss_Growth_Activator::storage_ready(), 'not ready before install' );
		assert_true( DoughBoss_Growth_Activator::install(), 'install succeeds' );
		assert_same( array( 'wp_doughboss_growth_rate', 'wp_doughboss_growth_outbox' ), DoughBoss_Growth_Activator::expected_tables(), 'the shared tables are the registered schema (modules add theirs by shipping a file)' );
		assert_count( 2, $GLOBALS['dbgr_dbdelta'], 'dbDelta ran once per statement' );
		assert_same( '1.0.0', get_option( 'doughboss_growth_db_version' ), 'DB version stored' );
		assert_true( DoughBoss_Growth_Activator::storage_ready(), 'ready after install' );
		assert_same( array(), DoughBoss_Growth_Activator::missing_tables(), 'no table missing' );
	}
);

db_test(
	'install FAILS CLOSED: if a table does not exist afterwards the version is not recorded and storage stays not-ready',
	function () {
		$GLOBALS['wpdb']->fail_on( '/^SHOW TABLES/' );
		assert_false( DoughBoss_Growth_Activator::install(), 'install reports failure when tables cannot be confirmed' );
		assert_same( false, get_option( 'doughboss_growth_db_version', false ), 'no version stored' );
		assert_false( DoughBoss_Growth_Activator::storage_ready(), 'not ready' );

		$GLOBALS['wpdb']->clear_failures();
		// dbDelta that creates nothing (a failing CREATE) leaves tables missing.
		$GLOBALS['wpdb']->tables_created = array();
		$GLOBALS['wpdb']->respond( '/^SHOW TABLES/', array() );
		assert_false( DoughBoss_Growth_Activator::install(), 'missing tables fail the install' );
		assert_false( DoughBoss_Growth_Activator::storage_ready(), 'still not ready' );
	}
);

db_test(
	'storage_ready(): needs a stored version at or above the plugin DB version',
	function () {
		foreach ( array( false, '', '0', '0.9.9', 5, null ) as $stored ) {
			update_option( 'doughboss_growth_db_version', $stored );
			assert_false( DoughBoss_Growth_Activator::storage_ready(), 'not ready for stored ' . var_export( $stored, true ) );
		}
		foreach ( array( '1.0.0', '1.0.1', '2.0.0' ) as $stored ) {
			update_option( 'doughboss_growth_db_version', $stored );
			assert_true( DoughBoss_Growth_Activator::storage_ready(), 'ready for stored ' . $stored );
		}
	}
);

db_test(
	'activation: installs when core is ready; does nothing when core is absent (sub-process)',
	function () {
		DoughBoss_Growth_Activator::activate();
		assert_true( DoughBoss_Growth_Activator::storage_ready(), 'core present: schema installed on activation' );

		if ( ! dbgr_test_can_subprocess() ) {
			dbgr_test_skip( 'sub-process unavailable: the core-absent activation is not exercised' );
			return;
		}
		$code   = "DoughBoss_Growth_Activator::activate(); echo json_encode( array( 'ready' => DoughBoss_Growth_Activator::storage_ready(), 'dbdelta' => count( \$GLOBALS['dbgr_dbdelta'] ), 'options' => array_keys( \$GLOBALS['dbgr_options'] ) ) );";
		$result = dbgr_test_subprocess( $code, '', false );
		assert_same( '{"ready":false,"dbdelta":0,"options":[]}', $result['out'], 'core absent: nothing installed, nothing written (' . $result['err'] . ')' );
	}
);

db_test(
	'self-heal maybe_upgrade(): managers only; installs when not ready; throttles retries; repairs a missing table',
	function () {
		// A non-manager (or anonymous admin-ajax caller) never triggers an install.
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_same( array(), $GLOBALS['dbgr_dbdelta'], 'anonymous: nothing installed' );
		dbgr_test_login( array( 'read' ) );
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_same( array(), $GLOBALS['dbgr_dbdelta'], 'subscriber: nothing installed' );

		dbgr_test_login( array( 'manage_options' ) );
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_true( DoughBoss_Growth_Activator::storage_ready(), 'a manager request installs the schema' );
		assert_true( false !== get_transient( 'doughboss_growth_schema_ok' ), 'a clean check is remembered for an hour' );

		// The hourly throttle: a missing table is NOT noticed on an ordinary admin page inside the hour...
		$GLOBALS['wpdb']->tables_created = array();
		$GLOBALS['dbgr_dbdelta']         = array();
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_same( array(), $GLOBALS['dbgr_dbdelta'], 'throttled: no work on an ordinary page' );
		// ...but opening the Growth page repairs it at once.
		$_GET['page'] = 'doughboss-growth';
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_count( 2, $GLOBALS['dbgr_dbdelta'], 'opening the Growth page re-runs dbDelta for the missing tables' );
		assert_same( array(), DoughBoss_Growth_Activator::missing_tables(), 'tables restored' );
	}
);

db_test(
	'self-heal: a failing install is retried at most every five minutes unless the Growth page is opened',
	function () {
		dbgr_test_login( array( 'manage_doughboss' ) );
		$GLOBALS['wpdb']->respond( '/^SHOW TABLES/', array() ); // Tables can never be confirmed.
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_count( 2, $GLOBALS['dbgr_dbdelta'], 'first attempt ran' );
		assert_true( false !== get_transient( 'doughboss_growth_install_retry' ), 'retry throttle set' );
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_count( 2, $GLOBALS['dbgr_dbdelta'], 'throttled: no second attempt on an ordinary page' );
		$_GET['page'] = 'doughboss-growth-anything';
		DoughBoss_Growth_Activator::maybe_upgrade();
		assert_count( 4, $GLOBALS['dbgr_dbdelta'], 'opening a Growth page is an explicit retry' );
		assert_false( DoughBoss_Growth_Activator::storage_ready(), 'still not ready because the tables never appeared' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Deactivation                                                                                                */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'deactivation: drafts live companion pages whose content is exactly one companion shortcode; deletes no data',
	function () {
		$landing  = dbgr_test_add_post( array( 'ID' => 201, 'post_content' => '[doughboss_growth_landing key="catering-corporate"]' ) );
		$coming   = dbgr_test_add_post( array( 'ID' => 202, 'post_content' => "\n [doughboss_growth_coming_soon] \n" ) );
		$block    = dbgr_test_add_post( array( 'ID' => 203, 'post_content' => '<!-- wp:shortcode -->[doughboss_growth_landing key="locations-revesby"]<!-- /wp:shortcode -->' ) );
		$edited   = dbgr_test_add_post( array( 'ID' => 204, 'post_content' => '[doughboss_growth_landing key="x"] and some text the owner added' ) );
		$other    = dbgr_test_add_post( array( 'ID' => 205, 'post_content' => '[gallery]' ) );
		$draft    = dbgr_test_add_post( array( 'ID' => 206, 'post_status' => 'draft', 'post_content' => '[doughboss_growth_landing key="y"]' ) );
		$post     = dbgr_test_add_post( array( 'ID' => 207, 'post_type' => 'post', 'post_content' => '[doughboss_growth_landing key="z"]' ) );
		$private  = dbgr_test_add_post( array( 'ID' => 208, 'post_status' => 'private', 'post_content' => '[doughboss_growth_waitlist]' ) );
		$core_pg  = dbgr_test_add_post( array( 'ID' => 209, 'post_content' => '[doughboss_catering]' ) );
		update_option( 'doughboss_growth_pages', array( 'catering-corporate' => $landing, 'coming-soon' => array( 'id' => (string) $coming ), 'locations-revesby' => $block, 'x' => $edited, 'g' => $other, 'd' => $draft, 'p' => $post, 'priv' => $private, 'missing' => 9999, 'junk' => 'abc', 'zero' => 0 ) );
		update_option( 'doughboss_growth_settings', array( 'features' => array( 'coming_soon' => true ) ) );
		wp_schedule_event( DBGR_TEST_EPOCH + 60, 'hourly', 'doughboss_growth_outbox_dispatch' );
		$before_options = $GLOBALS['dbgr_options'];

		DoughBoss_Growth_Activator::deactivate();

		assert_same( 'draft', get_post( $landing )->post_status, 'landing page drafted' );
		assert_same( 'draft', get_post( $coming )->post_status, 'coming-soon page drafted (whitespace tolerated)' );
		assert_same( 'draft', get_post( $block )->post_status, 'block-editor shortcode wrapper tolerated' );
		assert_same( 'publish', get_post( $edited )->post_status, 'a page the owner edited is left alone' );
		assert_same( 'publish', get_post( $other )->post_status, 'a page with a non-companion shortcode is left alone' );
		assert_same( 'draft', get_post( $draft )->post_status, 'already a draft: unchanged' );
		assert_same( 'publish', get_post( $post )->post_status, 'a post (not a page) is left alone' );
		assert_same( 'draft', get_post( $private )->post_status, 'a private companion page is drafted too' );
		assert_same( 'publish', get_post( $core_pg )->post_status, 'a page not listed in the option is left alone' );
		$ids = array_map(
			function ( $write ) {
				return $write['id'];
			},
			$GLOBALS['dbgr_post_writes']
		);
		sort( $ids );
		assert_same( array( 201, 202, 203, 208 ), $ids, 'exactly four post writes, each only post_status' );
		foreach ( $GLOBALS['dbgr_post_writes'] as $write ) {
			assert_same( array( 'ID', 'post_status' ), array_keys( $write['fields'] ), 'only the status is written' );
		}
		assert_same( $before_options, $GLOBALS['dbgr_options'], 'no option was changed or deleted on deactivation' );
		assert_false( wp_next_scheduled( 'doughboss_growth_outbox_dispatch' ), 'the cron event is cleared' );
	}
);

db_test(
	'is_companion_shortcode_only(): exact-shortcode table',
	function () {
		$yes = array(
			'[doughboss_growth_landing key="a"]',
			"  [doughboss_growth_landing key='a']\n",
			'[doughboss_growth_coming_soon]',
			'[doughboss_growth_waitlist store="3"]',
			'[doughboss_growth_lead_form variant="corporate"]',
			'[doughboss_growth_party_sizer]',
			'<!-- wp:shortcode -->[doughboss_growth_landing key="a"]<!-- /wp:shortcode -->',
		);
		foreach ( $yes as $content ) {
			assert_true( DoughBoss_Growth_Activator::is_companion_shortcode_only( $content ), 'companion-only: ' . $content );
		}
		$no = array(
			'',
			'[doughboss_growth_landing key="a"] extra',
			'extra [doughboss_growth_landing key="a"]',
			'[doughboss_growth_landing][doughboss_growth_waitlist]',
			'[doughboss_catering]',
			'[doughboss_growth_unknown]',
			'[doughboss_growth_landing key="a]b"]',
			'<p>[doughboss_growth_landing key="a"]</p>',
			'[doughboss_growth_hero]',
			'[gallery ids="1"]',
		);
		foreach ( $no as $content ) {
			assert_false( DoughBoss_Growth_Activator::is_companion_shortcode_only( $content ), 'not companion-only: ' . $content );
		}
	}
);

db_test(
	'page_ids(): accepts a list, key => id and key => array(id), ignores junk, de-duplicates',
	function () {
		assert_same( array( 5, 6, 7, 8 ), DoughBoss_Growth_Activator::page_ids( array( 5, '6', array( 'id' => 7 ), 'k' => array( 'id' => '8' ), 'bad' => 'abc', 'neg' => -3, 'zero' => 0, 'dup' => 5, 'f' => 1.5 ) ), 'ids' );
		assert_same( array(), DoughBoss_Growth_Activator::page_ids( 'nope' ), 'a non-array option gives none' );
		assert_same( array(), DoughBoss_Growth_Activator::page_ids( false ), 'false gives none' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Uninstall                                                                                                   */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'uninstall dry-run: the plan lists only doughboss_growth_* names (options, tables, transients, cron) and no violations',
	function () {
		$plan = DoughBoss_Growth_Activator::uninstall_plan( 'wp_' );
		assert_same( array(), $plan['violations'], 'no violations' );
		assert_same( array( 'doughboss_growth_settings', 'doughboss_growth_db_version', 'doughboss_growth_pages', 'doughboss_growth_recon' ), $plan['options'], 'the four frozen options' );
		assert_same( array( 'doughboss_growth_install_retry', 'doughboss_growth_schema_ok' ), $plan['transients'], 'companion transients' );
		assert_same(
			array( 'wp_doughboss_growth_waitlist', 'wp_doughboss_growth_suppression', 'wp_doughboss_growth_attribution', 'wp_doughboss_growth_lead_meta', 'wp_doughboss_growth_outbox', 'wp_doughboss_growth_rate', 'wp_doughboss_growth_recon_run', 'wp_doughboss_growth_recon_row', 'wp_doughboss_growth_recon_xref' ),
			$plan['tables'],
			'the nine frozen tables'
		);
		assert_same( array( 'doughboss_growth_outbox_dispatch', 'doughboss_growth_retention_purge', 'doughboss_growth_recon_daily' ), $plan['cron'], 'three cron hooks' );
		foreach ( array_merge( $plan['options'], $plan['transients'], $plan['cron'] ) as $name ) {
			assert_matches( '/^doughboss_growth_[a-z0-9_]+$/', $name, 'namespaced: ' . $name );
		}
		foreach ( $plan['tables'] as $name ) {
			assert_matches( '/^wp_doughboss_growth_[a-z_]+$/', $name, 'namespaced table: ' . $name );
		}
		// Multisite style prefix.
		$plan = DoughBoss_Growth_Activator::uninstall_plan( 'wp_7_' );
		assert_same( array(), $plan['violations'], 'a blog prefix is fine' );
		assert_same( 'wp_7_doughboss_growth_outbox', $plan['tables'][4], 'prefix applied' );
	}
);

db_test(
	'uninstall dry-run: a hostile or odd table prefix is a violation and the plan refuses to run',
	function () {
		foreach ( array( "wp_`; DROP TABLE wp_users; --", 'wp-', 'wp_ ', "wp_\n", 'a.b_' ) as $prefix ) {
			$plan = DoughBoss_Growth_Activator::uninstall_plan( $prefix );
			assert_true( array() !== $plan['violations'], 'violation for prefix ' . var_export( $prefix, true ) );
			assert_same( array(), $plan['tables'], 'no tables planned for a bad prefix' );
		}
		$GLOBALS['wpdb']->prefix = "wp_`; DROP TABLE wp_users; --";
		$result                  = DoughBoss_Growth_Activator::run_uninstall( $GLOBALS['wpdb'] );
		assert_same( array(), $result['tables'], 'nothing executed' );
		assert_true( array() !== $result['violations'], 'violations returned' );
		assert_same( array(), $GLOBALS['wpdb']->queries, 'not a single query ran' );
	}
);

db_test(
	'uninstall run: drops only companion tables and options and never touches a core table, option, role or capability',
	function () {
		$core = dbgr_lifecycle_core_state();
		DoughBoss_Growth_Activator::install();
		update_option( 'doughboss_growth_pages', array( 'a' => 1 ) );
		update_option( 'doughboss_growth_recon', array( 'x' => 1 ) );
		set_transient( 'doughboss_growth_install_retry', 1, 300 );
		wp_schedule_event( DBGR_TEST_EPOCH + 60, 'hourly', 'doughboss_growth_retention_purge' );

		$result = DoughBoss_Growth_Activator::run_uninstall( $GLOBALS['wpdb'] );
		assert_same( array(), $result['violations'], 'no violations' );

		$drops = $GLOBALS['wpdb']->queries_matching( '/^DROP TABLE/' );
		assert_count( 9, $drops, 'nine tables dropped' );
		foreach ( $drops as $sql ) {
			assert_matches( '/^DROP TABLE IF EXISTS wp_doughboss_growth_[a-z_]+$/', $sql, 'a companion drop: ' . $sql );
		}
		foreach ( $GLOBALS['wpdb']->queries as $query ) {
			assert_matches( '/^DROP TABLE IF EXISTS wp_doughboss_growth_/', $query['sql'], 'every statement is a companion drop' );
		}
		foreach ( $core['options'] as $name ) {
			assert_true( array_key_exists( $name, $GLOBALS['dbgr_options'] ), 'core option untouched: ' . $name );
		}
		foreach ( $core['tables'] as $table ) {
			assert_true( in_array( $table, $GLOBALS['wpdb']->tables_created, true ), 'core table untouched: ' . $table );
		}
		foreach ( array( 'doughboss_growth_settings', 'doughboss_growth_db_version', 'doughboss_growth_pages', 'doughboss_growth_recon' ) as $name ) {
			assert_false( array_key_exists( $name, $GLOBALS['dbgr_options'] ), 'companion option removed: ' . $name );
		}
		assert_false( get_transient( 'doughboss_growth_install_retry' ), 'companion transient removed' );
		assert_false( wp_next_scheduled( 'doughboss_growth_retention_purge' ), 'companion cron cleared' );
		assert_same( array(), $GLOBALS['dbgr_foreign_writes'], 'the harness recorded no write outside the companion namespace' );
		assert_same( array(), $GLOBALS['wpdb']->foreign_writes, 'no foreign SQL write' );
	}
);

db_test(
	'uninstall.php: deletes NOTHING unless DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA is exactly true (sub-process)',
	function () {
		if ( ! dbgr_test_can_subprocess() ) {
			dbgr_test_skip( 'sub-process unavailable' );
			return;
		}
		$uninstall = var_export( dirname( __DIR__ ) . '/uninstall.php', true );
		$code      = <<<CODE
update_option( 'doughboss_growth_settings', array( 'features' => array( 'coming_soon' => true ) ) );
update_option( 'doughboss_growth_db_version', '1.0.0' );
dbgr_test_core_option( 'doughboss_settings', array( 'a' => 1 ) );
define( 'WP_UNINSTALL_PLUGIN', 'doughboss-growth/doughboss-growth.php' );
\$GLOBALS['wpdb']->reset_log();
require {$uninstall};
echo json_encode( array(
	'queries' => count( \$GLOBALS['wpdb']->queries ),
	'growth'  => array_key_exists( 'doughboss_growth_settings', \$GLOBALS['dbgr_options'] ),
	'core'    => array_key_exists( 'doughboss_settings', \$GLOBALS['dbgr_options'] ),
) );
CODE;
		$cases     = array(
			'constant not defined'            => array( '', '{"queries":0,"growth":true,"core":true}' ),
			'constant false'                  => array( "define( 'DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA', false );", '{"queries":0,"growth":true,"core":true}' ),
			'constant the string "true"'      => array( "define( 'DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA', 'true' );", '{"queries":0,"growth":true,"core":true}' ),
			'constant 1'                      => array( "define( 'DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA', 1 );", '{"queries":0,"growth":true,"core":true}' ),
			'constant exactly true (opt-in)'  => array( "define( 'DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA', true );", '{"queries":9,"growth":false,"core":true}' ),
		);
		foreach ( $cases as $label => $case ) {
			$result = dbgr_test_subprocess( $code, $case[0] );
			assert_same( $case[1], $result['out'], 'uninstall with ' . $label . ' (' . $result['err'] . ')' );
		}

		// Called outside WordPress's uninstall flow it must refuse to do anything at all.
		$direct = dbgr_test_subprocess( "define( 'DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA', true ); try { require {$uninstall}; echo 'returned'; } catch ( Throwable \$e ) { echo 'threw'; }", '', true );
		assert_same( '', $direct['out'], 'without WP_UNINSTALL_PLUGIN the file exits silently before doing anything' );
	}
);

db_test(
	'frozen names: constants of the activator match the architecture (options, tables, cron hooks)',
	function () {
		assert_same( 9, count( DoughBoss_Growth_Activator::TABLE_SUFFIXES ), 'nine tables' );
		assert_same( 4, count( DoughBoss_Growth_Activator::OPTIONS ), 'four options' );
		assert_same( 3, count( DoughBoss_Growth_Activator::CRON_HOOKS ), 'three cron hooks' );
		assert_same( 'doughboss_growth_outbox_dispatch', DoughBoss_Growth_Outbox::CRON_HOOK, 'outbox cron hook matches the activator list' );
		assert_true( in_array( DoughBoss_Growth_Outbox::CRON_HOOK, DoughBoss_Growth_Activator::CRON_HOOKS, true ), 'outbox hook is in the uninstall list' );
		assert_true( in_array( 'doughboss_growth_rate', DoughBoss_Growth_Activator::TABLE_SUFFIXES, true ), 'rate table is listed' );
		assert_true( in_array( 'doughboss_growth_outbox', DoughBoss_Growth_Activator::TABLE_SUFFIXES, true ), 'outbox table is listed' );
		assert_same( 'doughboss_growth_settings', DoughBoss_Growth_Settings::OPTION, 'settings option name' );
		assert_same( 'doughboss_growth_db_version', DoughBoss_Growth_Activator::DB_VERSION_OPTION, 'DB version option name' );
		assert_same( 'doughboss_growth_pages', DoughBoss_Growth_Activator::PAGES_OPTION, 'pages option name' );
	}
);
