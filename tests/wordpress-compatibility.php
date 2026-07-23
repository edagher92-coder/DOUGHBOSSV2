<?php
/**
 * Real WordPress compatibility smoke test, executed by WP-CLI in CI.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$passed = 0;

function doughboss_wp_compat_assert( $condition, $label ) {
	global $passed;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$label}\n" );
		exit( 1 );
	}
	++$passed;
	echo "PASS: {$label}\n";
}

doughboss_wp_compat_assert( defined( 'DOUGHBOSS_VERSION' ), 'plugin bootstrap is active' );
doughboss_wp_compat_assert( '1.15.0' === get_option( 'doughboss_db_version' ), 'database schema activated at 1.15.0' );
doughboss_wp_compat_assert( ! DoughBoss_Settings::ordering_open(), 'fresh WordPress install starts in browse-only mode' );
doughboss_wp_compat_assert( false !== stripos( DoughBoss_Settings::ordering_closed_message(), 'coming soon' ), 'Coming Soon copy is available' );
doughboss_wp_compat_assert( shortcode_exists( 'doughboss_ordering_status' ), 'ordering-status shortcode is registered' );
doughboss_wp_compat_assert( shortcode_exists( 'doughboss_menu' ) && shortcode_exists( 'doughboss_cart' ), 'menu and cart shortcodes are registered' );

$notice = do_shortcode( '[doughboss_ordering_status]' );
doughboss_wp_compat_assert( false !== stripos( $notice, 'Online ordering coming soon' ), 'server-rendered page shows the launch notice' );
doughboss_wp_compat_assert( false !== stripos( $notice, 'role="status"' ), 'launch notice is accessible' );

$seed = DoughBoss_Menu_Seeder::seed();
doughboss_wp_compat_assert( 0 < (int) $seed['total'], 'corrected menu imports into WordPress' );
doughboss_wp_compat_assert( 0 < (int) wp_count_posts( DoughBoss_Post_Types::POST_TYPE )->publish, 'published menu products exist' );
doughboss_wp_compat_assert( 0 < (int) get_page_by_title( 'Dough Boss Pie', OBJECT, DoughBoss_Post_Types::POST_TYPE )->ID, 'Dough Boss Pie exists in WordPress' );
doughboss_wp_compat_assert( 0 < (int) get_page_by_title( 'Zaatar Veggie Pizza', OBJECT, DoughBoss_Post_Types::POST_TYPE )->ID, 'Zaatar Veggie Pizza exists in WordPress' );

$config = rest_do_request( new WP_REST_Request( 'GET', '/doughboss/v1/config' ) );
doughboss_wp_compat_assert( 200 === $config->get_status(), 'WordPress REST configuration endpoint responds' );
$config_data = $config->get_data();
doughboss_wp_compat_assert( empty( $config_data['ordering_open'] ), 'REST configuration reports ordering closed' );
doughboss_wp_compat_assert( empty( $config_data['payments_enabled'] ), 'REST configuration keeps payments unavailable while closed' );
doughboss_wp_compat_assert( false !== stripos( $config_data['ordering_closed_message'], 'coming soon' ), 'REST configuration supplies customer launch copy' );

$checkout = rest_do_request( new WP_REST_Request( 'POST', '/doughboss/v1/checkout' ) );
doughboss_wp_compat_assert( 503 === $checkout->get_status(), 'direct checkout is blocked while ordering is closed' );
doughboss_wp_compat_assert( 'doughboss_closed' === $checkout->get_data()['code'], 'closed checkout returns the expected safe error' );

DoughBoss_Settings::update( array( 'ordering_open' => 1 ) );
doughboss_wp_compat_assert( '' === do_shortcode( '[doughboss_ordering_status]' ), 'notice disappears only after ordering is explicitly opened' );
DoughBoss_Settings::update( array( 'ordering_open' => 0 ) );

echo "WordPress compatibility: {$passed} passed, 0 failed.\n";
