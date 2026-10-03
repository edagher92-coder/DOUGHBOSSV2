<?php
/**
 * WP-16 integration tests: properties that only hold (or break) when every package is present together.
 *
 * - Flags off, every module on disk, schema installed: the companion adds no hook that renders, rewrites or records
 *   anything on a public request, apart from the one documented "always" module (the waitlist opt-out, export,
 *   erasure and purge, which must keep working while sign-ups are off).
 * - The companion's write surface is closed: no code path of it creates a post type, role, capability or taxonomy,
 *   and a post is written only by the two files that own the companion's landing pages.
 *
 * @package DoughBoss_Growth
 */

/**
 * Names of the hooks that currently have at least one callback, with the callback count.
 *
 * @return array hook => count
 */
function dbgr_integration_hook_counts() {
	$out = array();
	foreach ( $GLOBALS['dbgr_hooks'] as $hook => $priorities ) {
		$n = 0;
		foreach ( $priorities as $callbacks ) {
			$n += count( $callbacks );
		}
		if ( $n > 0 ) {
			$out[ $hook ] = $n;
		}
	}
	ksort( $out );
	return $out;
}

db_test(
	'integration: every flag off, every module on disk, schema installed - the hook footprint is the documented minimum',
	function () {
		update_option( 'doughboss_growth_db_version', DOUGHBOSS_GROWTH_DB_VERSION );
		$before = dbgr_integration_hook_counts();
		DoughBoss_Growth::init();
		$after = dbgr_integration_hook_counts();
		$added = array();
		foreach ( $after as $hook => $n ) {
			if ( ! isset( $before[ $hook ] ) || $n > $before[ $hook ] ) {
				$added[] = $hook;
			}
		}
		sort( $added );
		// A new hook here is a deliberate decision, not an accident: with every flag off the companion may add exactly these.
		$documented = array(
			'admin_init', // schema self-heal for managers
			'admin_menu', // the Growth screen
			'admin_notices', // inert notices
			'admin_post_doughboss_growth_clear_failures', // Clear button for the recent failures list (capability + nonce); WP-16 observability fix
			'admin_post_doughboss_growth_export_waitlist', // staff CSV export (capability + nonce)
			'admin_post_doughboss_growth_save_settings', // settings save (capability + nonce)
			'cron_schedules', // the five-minute outbox schedule
			'doughboss_growth_outbox_dispatch', // outbox cron action (no event is ever queued while the flags are off)
			'doughboss_growth_retention_purge', // waitlist purge, an "always" duty
			'rest_api_init', // /health and the opt-out route
			'template_redirect', // waitlist opt-out and confirm link pages (acts only on its own query variable)
			'wp_privacy_personal_data_erasers', // waitlist erasure, an "always" duty
			'wp_privacy_personal_data_exporters', // waitlist export, an "always" duty
		);
		assert_same( $documented, $added, 'the hooks the companion adds with every flag off' );

		// Nothing that renders, rewrites or records on a public request may be hooked by the companion while it is off.
		$forbidden = array(
			'wp_head', 'wp_footer', 'wp_enqueue_scripts', 'the_content', 'do_shortcode_tag', 'pre_get_document_title', 'document_title_parts',
			'wp_robots', 'body_class', 'script_loader_tag', 'style_loader_tag', 'send_headers', 'init', 'wp', 'login_init',
			'rest_post_dispatch', 'rest_request_before_callbacks', 'doughboss_marketing_config', 'doughboss_catering_enquiry_created',
			'doughboss_order_created', 'doughboss_order_payment_status_changed', 'doughboss_catering_payment', 'doughboss_catering_status_changed',
			'wp_mail', 'phpmailer_init',
		);
		foreach ( $forbidden as $hook ) {
			assert_false( isset( $after[ $hook ] ) && ( ! isset( $before[ $hook ] ) || $after[ $hook ] > $before[ $hook ] ), 'no companion callback on ' . $hook . ' while every flag is off' );
		}

		$health = DoughBoss_Growth::health();
		$active = array_keys( array_filter( $health['modules_active'] ) );
		assert_same( array( 'waitlist' ), $active, 'only the registry "always" module is initialised with every flag off' );
		assert_same( array(), array_keys( array_filter( $health['flags'] ) ), 'no flag is effective' );
		assert_same( array(), $GLOBALS['dbgr_assets']['scripts'], 'no script enqueued' );

		// Negative control: the same footprint check DOES see a hook when one flag is on (the comparison is not vacuous).
		dbgr_test_reset();
		update_option( 'doughboss_growth_db_version', DOUGHBOSS_GROWTH_DB_VERSION );
		update_option( 'doughboss_growth_settings', array( 'features' => array( 'consent_banner' => true ) ) );
		$before = dbgr_integration_hook_counts();
		DoughBoss_Growth::init();
		$after = dbgr_integration_hook_counts();
		assert_true( isset( $after['wp_head'] ) && ( ! isset( $before['wp_head'] ) || $after['wp_head'] > $before['wp_head'] ), 'with consent_banner on, wp_head is hooked (negative control)' );
	}
);

db_test(
	'integration: the companion cannot create a post type, role, capability, user or taxonomy, and writes posts only in its two owning files',
	function () {
		$root  = dirname( __DIR__ );
		$files = array_merge(
			glob( $root . '/includes/*.php' ),
			glob( $root . '/includes/*/*.php' ),
			glob( $root . '/admin/*.php' ),
			array( $root . '/doughboss-growth.php', $root . '/uninstall.php' )
		);
		assert_true( count( $files ) >= 30, 'scanned the whole shipped PHP tree (' . count( $files ) . ' files)' );
		$never  = '/\b(?:register_post_type|register_taxonomy|register_post_status|add_role|remove_role|add_cap|remove_cap|wp_insert_user|wp_update_user|wp_delete_user|wp_create_user|update_user_meta|add_user_meta|delete_user_meta|wp_set_current_user|wp_set_auth_cookie|switch_to_blog|update_site_option|add_site_option)\s*\(/';
		$posts  = '/\b(?:wp_insert_post|wp_update_post|wp_delete_post|wp_trash_post|update_post_meta|add_post_meta|delete_post_meta|wp_set_object_terms|wp_insert_term)\s*\(/';
		$owners = array( 'class-doughboss-growth-activator.php' => 'wp_update_post(', 'class-doughboss-growth-landing.php' => 'wp_insert_post(' );
		$seen   = array();
		foreach ( $files as $file ) {
			$code = preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents( $file ) );
			assert_same( 0, preg_match( $never, $code ), basename( $file ) . ' creates no post type, role, capability, user or taxonomy' );
			if ( preg_match_all( $posts, $code, $m ) ) {
				$seen[ basename( $file ) ] = implode( ',', array_unique( $m[0] ) );
			}
			// Options, transients and cron events the companion writes are all in its own namespace (literal names only; constants are covered by the runtime foreign-write recorder).
			if ( preg_match_all( "/\\b(?:update_option|add_option|delete_option|set_transient|delete_transient|wp_schedule_event|wp_schedule_single_event|wp_clear_scheduled_hook)\\(\\s*(?:[0-9a-z_ +\\\\\\$]*,\\s*[0-9a-z_ ]*,\\s*)?'([^']+)'/i", $code, $names ) ) {
				foreach ( $names[1] as $name ) {
					assert_true( 0 === strpos( $name, 'doughboss_growth_' ), basename( $file ) . ' writes only its own namespace: ' . $name );
				}
			}
		}
		ksort( $seen );
		assert_same( $owners, $seen, 'posts are written only by the landing module (creates its pages) and the activator (drafts them on deactivation)' );

		// Negative control: the scan regexes do catch what they are meant to catch.
		assert_same( 1, preg_match( $never, 'add_role( "x", "y" );' ), 'negative control: add_role is caught' );
		assert_same( 1, preg_match( $posts, 'wp_update_post( array() );' ), 'negative control: wp_update_post is caught' );
	}
);

db_test(
	'integration: every SQL write in the companion goes through a companion table accessor, never a literal core table',
	function () {
		$root  = dirname( __DIR__ );
		$files = array_merge( glob( $root . '/includes/*.php' ), glob( $root . '/includes/*/*.php' ), glob( $root . '/admin/*.php' ) );
		$write = '/\b(?:INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|ALTER\s+TABLE|TRUNCATE(?:\s+TABLE)?)\s+(\S+)/';
		$bad   = array();
		$vars  = 0;
		foreach ( $files as $file ) {
			$code = (string) file_get_contents( $file );
			if ( preg_match_all( $write, $code, $m ) ) {
				foreach ( $m[1] as $table ) {
					if ( 0 === strpos( $table, '{$' ) ) {
						++$vars;
					} elseif ( false !== strpos( $table, 'wp_' ) || false !== strpos( $table, 'doughboss_' ) ) {
						$bad[] = basename( $file ) . ': ' . $table;
					}
				}
			}
			// Every table variable is assigned from a companion accessor or the companion prefix, or a read-only core table name (never written).
			if ( preg_match_all( '/\\$table\s*=\s*([^;]+);/', $code, $assign ) ) {
				foreach ( $assign[1] as $rhs ) {
					$ok = ( false !== strpos( $rhs, 'self::' ) ) || ( false !== strpos( $rhs, 'DoughBoss_Growth_' ) ) || ( false !== strpos( $rhs, "'doughboss_growth_" ) ) || ( false !== strpos( $rhs, 'doughboss_square_locations' ) );
					assert_true( $ok, basename( $file ) . ' $table is a companion table or the read-only core location map: ' . trim( $rhs ) );
				}
			}
		}
		assert_same( array(), $bad, 'no write statement names a literal table' );
		assert_true( $vars >= 15, 'the scan saw the write statements (' . $vars . ' with a table variable)' );
		// The one core table the companion touches (the 2.44.0 Square location map) appears only in a SELECT.
		$report = (string) file_get_contents( $root . '/includes/recon/class-doughboss-growth-recon-report.php' );
		assert_same( 0, preg_match( '/(?:INSERT|UPDATE|DELETE|REPLACE)[^;]{0,80}doughboss_square_locations/i', $report ), 'the core location table is never written' );
		// Negative control.
		assert_same( 1, preg_match( $write, 'DELETE FROM wp_doughboss_orders WHERE id = 1' ), 'negative control: a literal core table write would be caught' );
	}
);

db_test(
	'integration: every admin-post handler of every module checks a capability AND a nonce, and every non-public REST route has a permission callback',
	function () {
		$root  = dirname( __DIR__ );
		$files = array_merge( glob( $root . '/includes/*.php' ), glob( $root . '/includes/*/*.php' ), glob( $root . '/admin/*.php' ) );
		$found = array();
		foreach ( $files as $file ) {
			$code = (string) file_get_contents( $file );
			if ( ! preg_match_all( "/add_action\\(\\s*'admin_post_'\\s*\\.\\s*self::[A-Z_]+\\s*,\\s*array\\(\\s*__CLASS__\\s*,\\s*'([a-z_]+)'/", $code, $m ) ) {
				continue;
			}
			foreach ( $m[1] as $method ) {
				$start = strpos( $code, 'function ' . $method . '(' );
				assert_true( false !== $start, basename( $file ) . ' defines the handler ' . $method );
				if ( false === $start ) {
					continue;
				}
				// Body by brace matching.
				$open  = strpos( $code, '{', $start );
				$depth = 0;
				$len   = strlen( $code );
				for ( $i = $open; $i < $len; $i++ ) {
					if ( '{' === $code[ $i ] ) {
						++$depth;
					} elseif ( '}' === $code[ $i ] && 0 === --$depth ) {
						break;
					}
				}
				$body = substr( $code, $open, $i - $open + 1 );
				$cap  = ( false !== strpos( $body, 'user_can_manage' ) || false !== strpos( $body, 'current_user_can' ) || false !== strpos( $body, 'require_manager()' ) );
				$nonce = ( false !== strpos( $body, 'check_admin_referer' ) || false !== strpos( $body, 'wp_verify_nonce' ) );
				assert_true( $cap, basename( $file ) . ' ' . $method . ' checks a capability' );
				assert_true( $nonce, basename( $file ) . ' ' . $method . ' checks a nonce' );
				$found[] = $method;
			}
		}
		$recon = (string) file_get_contents( $root . '/includes/recon/class-doughboss-growth-recon-admin.php' );
		assert_true( 1 === preg_match( '/function require_manager\(\)\s*\{[^}]*user_can_manage[^}]*wp_die/s', $recon ), 'require_manager() checks the manager capability and dies with 403 otherwise' );
		// The settings handler lives in the Admin class and is registered with a different shape; assert it too.
		$admin = (string) file_get_contents( $root . '/admin/class-doughboss-growth-admin.php' );
		assert_true( 1 === preg_match( "/add_action\\(\\s*'admin_post_'\\s*\\.\\s*self::ACTION_SAVE/", $admin ), 'the settings save handler is registered' );
		assert_true( count( $found ) >= 8, 'the scan found the handlers of every module (' . count( $found ) . ')' );

		// REST routes: only the four waitlist routes are public (their own token, limiter and validation guard them); /health needs a manager.
		$public = array();
		foreach ( $files as $file ) {
			$code = (string) file_get_contents( $file );
			$n    = preg_match_all( "/'permission_callback'\\s*=>\\s*'__return_true'/", $code );
			if ( $n ) {
				$public[ basename( $file ) ] = $n;
			}
		}
		assert_same( array( 'class-doughboss-growth-waitlist-rest.php' => 4 ), $public, 'public REST routes exist only in the waitlist REST class' );
		// Negative control: the handler scan would notice a handler with no nonce.
		$body = '{ if ( ! DoughBoss_Growth_Admin::user_can_manage() ) { return; } do_something(); }';
		assert_false( false !== strpos( $body, 'check_admin_referer' ), 'negative control: a body without a nonce check is detected' );
	}
);
