<?php
/**
 * Dough Boss local runtime: post-activation setup. Runs INSIDE WordPress (Playground blueprint
 * runPHP step), after the theme and plugin were activated. Idempotent: safe to run repeatedly.
 *
 * What it does (and nothing else):
 *   1. proves the plugin activator ran (tables + db version),
 *   2. sets site title / timezone / pretty permalinks (local-only options),
 *   3. creates the pages the theme expects by slug (order, menu, locations, ...),
 *   4. seeds the menu with the plugin's OWN seeder (DoughBoss_CLI::seed_menu, the same code that
 *      `wp doughboss seed-menu` and scripts/seed-menu.php call),
 *   5. never touches any payment setting. Payments stay at the plugin default (off).
 *
 * Expects: $GLOBALS['wpl_ordering_open'] (bool) set by the caller.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wpl_report = array(
	'php'            => PHP_VERSION,
	'wp'             => get_bloginfo( 'version' ),
	'plugin_version' => defined( 'DOUGHBOSS_VERSION' ) ? DOUGHBOSS_VERSION : null,
	'theme'          => get_option( 'stylesheet' ),
);

/* 1. Activator ran? (create_tables + DoughBoss_Locations::ensure_default + db version) */
global $wpdb;
$wpl_expected = array(
	'doughboss_orders',
	'doughboss_order_items',
	'doughboss_order_events',
	'doughboss_locations',
	'doughboss_location_hours',
	'doughboss_catering_enquiries',
	'doughboss_vouchers',
	'doughboss_payment_attempts',
	'doughboss_checkout_snapshots',
	'doughboss_pospal_outbox',
	'doughboss_staff_shifts',
	'doughboss_staff_shift_events',
	'doughboss_staff_badges',
	'doughboss_staff_breaks',
);
$wpl_found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'doughboss_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpl_found = array_map(
	function ( $t ) use ( $wpdb ) {
		return substr( $t, strlen( $wpdb->prefix ) );
	},
	$wpl_found
);
sort( $wpl_found );
$wpl_report['tables_found_count']    = count( $wpl_found );
$wpl_report['tables_found']          = $wpl_found;
$wpl_report['tables_expected_missing'] = array_values( array_diff( $wpl_expected, $wpl_found ) );
$wpl_report['doughboss_db_version'] = get_option( 'doughboss_db_version' );
$wpl_report['doughboss_migration_error'] = get_option( 'doughboss_migration_error' );

/* 2. Local-only site options. */
update_option( 'blogname', 'Dough Boss (local runtime)' );
update_option( 'blogdescription', 'Local WordPress runtime for extension work. Not the live site.' );
update_option( 'timezone_string', 'Australia/Sydney' );
update_option( 'blog_public', '0' );
update_option( 'permalink_structure', '/%postname%/' );

/* 3. Pages by slug (the theme keys templates and enqueues off these). */
$wpl_pages = array(
	'order'       => 'Order',
	'menu'        => 'Menu',
	'locations'   => 'Locations',
	'track-order' => 'Track order',
	'catering'    => 'Catering',
	'about-us'    => 'About us',
	'vouchers'    => 'Vouchers',
);
$wpl_page_ids = array();
foreach ( $wpl_pages as $slug => $title ) {
	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	if ( $existing ) {
		$wpl_page_ids[ $slug ] = (int) $existing->ID;
		continue;
	}
	$wpl_page_ids[ $slug ] = (int) wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => '',
		)
	);
}
$wpl_report['pages'] = $wpl_page_ids;

/* 4. Menu seed via the plugin's own seeder. The seeder is a WP-CLI command class and logs through
 *    WP_CLI::log/success/warning/error. We provide a minimal in-process stand-in for the WP_CLI
 *    class (output captured, error() throws) and define WP_CLI only for the duration of this script,
 *    so the plugin's real DoughBoss_CLI class loads. */
if ( ! class_exists( 'WP_CLI', false ) ) {
	class WP_CLI { // phpcs:ignore
		public static $lines = array();
		public static function add_command() {}
		public static function log( $m ) { self::$lines[] = (string) $m; }
		public static function success( $m ) { self::$lines[] = 'SUCCESS: ' . $m; }
		public static function warning( $m ) { self::$lines[] = 'WARNING: ' . $m; }
		public static function error( $m ) { throw new RuntimeException( (string) $m ); }
	}
}
if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}
if ( ! class_exists( 'DoughBoss_CLI', false ) ) {
	// class-doughboss-cli.php returns early when WP_CLI is undefined at plugin load, so include again.
	include DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss-cli.php';
}
try {
	DoughBoss_CLI::seed_menu( array(), array() );
	$wpl_report['seed'] = 'ok';
} catch ( Throwable $e ) {
	$wpl_report['seed'] = 'FAILED: ' . $e->getMessage();
}
$wpl_report['seed_log_tail'] = array_slice( WP_CLI::$lines, -3 );
$wpl_counts = wp_count_posts( DoughBoss_Post_Types::POST_TYPE );
$wpl_report['menu_items_published'] = isset( $wpl_counts->publish ) ? (int) $wpl_counts->publish : 0;

/* 5. Optional: open online ordering so the cart UI renders. Payments are NOT touched. */
if ( ! empty( $GLOBALS['wpl_ordering_open'] ) ) {
	$s                  = get_option( DoughBoss_Settings::OPTION_KEY, array() );
	$s                  = is_array( $s ) ? $s : array();
	$s['ordering_open'] = 1;
	update_option( DoughBoss_Settings::OPTION_KEY, $s );
}
$wpl_report['ordering_open']    = DoughBoss_Settings::ordering_open();
$wpl_report['payments_enabled'] = DoughBoss_Settings::payments_enabled();

flush_rewrite_rules( false );

$wpl_json = wp_json_encode( $wpl_report, JSON_PRETTY_PRINT );
echo "WPL_REPORT " . $wpl_json . "\n"; // phpcs:ignore
if ( is_dir( '/internal/wpl-out' ) ) {
	file_put_contents( '/internal/wpl-out/seed-report.json', $wpl_json . "\n" ); // phpcs:ignore
}
