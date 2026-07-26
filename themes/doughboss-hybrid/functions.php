<?php
/**
 * DoughBoss Hybrid theme setup.
 *
 * This theme intentionally delegates ordering, payments and live status to the
 * DoughBoss plugin. It must not replace plugin data, REST routes or checkout UI.
 *
 * @package DoughBossHybrid
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function doughboss_hybrid_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
	register_nav_menus( array( 'primary' => __( 'Primary menu', 'doughboss-hybrid' ) ) );
}
add_action( 'after_setup_theme', 'doughboss_hybrid_setup' );

function doughboss_hybrid_assets() {
	wp_enqueue_style( 'doughboss-hybrid', get_stylesheet_uri(), array(), '0.1.1' );
}
add_action( 'wp_enqueue_scripts', 'doughboss_hybrid_assets' );

/**
 * A useful no-configuration fallback while the navigation menu is being built.
 *
 * @return void
 */
function doughboss_hybrid_menu_fallback() {
	$items = array(
		__( 'Home', 'doughboss-hybrid' ) => home_url( '/' ),
		__( 'Order', 'doughboss-hybrid' ) => home_url( '/order/' ),
		__( 'Track order', 'doughboss-hybrid' ) => home_url( '/track-order/' ),
		__( 'Catering', 'doughboss-hybrid' ) => home_url( '/catering/' ),
	);

	echo '<ul>';
	foreach ( $items as $label => $url ) {
		echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
	}
	echo '</ul>';
}

/** Force plugin assets for template-rendered shortcodes, outside post content. */
function doughboss_hybrid_load_storefront_assets( $load ) {
	return $load || is_front_page() || is_page( array( 'menu', 'order', 'track-order', 'catering' ) );
}
add_filter( 'doughboss_load_assets', 'doughboss_hybrid_load_storefront_assets' );

function doughboss_hybrid_load_hero_assets( $load ) {
	return $load || is_front_page();
}
add_filter( 'doughboss_load_manoush_hero_assets', 'doughboss_hybrid_load_hero_assets' );

function doughboss_hybrid_plugin_notice() {
	if ( ! shortcode_exists( 'doughboss_menu' ) ) {
		return '<p class="db-notice" role="status">' . esc_html__( 'Online ordering is being prepared. Please check back shortly.', 'doughboss-hybrid' ) . '</p>';
	}
	return '';
}
