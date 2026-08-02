<?php
/**
 * DoughBoss Final theme integration.
 *
 * The theme owns presentation only. The DoughBoss plugin remains authoritative
 * for menu data, availability, prices, cart totals, payments and orders.
 *
 * @package DoughBossFinal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOUGHBOSS_FINAL_VERSION', '1.0.0' );

function doughboss_final_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
	register_nav_menus( array( 'primary' => __( 'Primary menu', 'doughboss-final' ) ) );
}
add_action( 'after_setup_theme', 'doughboss_final_setup' );

/**
 * Return a bundled DoughBoss plugin asset URL.
 *
 * @param string $path Path relative to public/images.
 * @return string
 */
function doughboss_final_asset_url( $path ) {
	$base = defined( 'DOUGHBOSS_PLUGIN_URL' ) ? DOUGHBOSS_PLUGIN_URL : content_url( '/plugins/doughboss/' );
	return trailingslashit( $base ) . 'public/images/' . ltrim( $path, '/' );
}

/**
 * Read a public, non-secret DoughBoss setting.
 *
 * @param string $key Setting key.
 * @param mixed  $default Fallback.
 * @return mixed
 */
function doughboss_final_setting( $key, $default = '' ) {
	$settings = get_option( 'doughboss_settings', array() );
	return is_array( $settings ) && array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
}

function doughboss_final_ordering_open() {
	return '1' === (string) doughboss_final_setting( 'ordering_open', '0' );
}

function doughboss_final_assets() {
	$style_path = get_stylesheet_directory() . '/style.css';
	$script_path = get_stylesheet_directory() . '/assets/theme.js';
	$version = file_exists( $style_path ) ? (string) filemtime( $style_path ) : DOUGHBOSS_FINAL_VERSION;

	wp_enqueue_style( 'doughboss-final', get_stylesheet_uri(), array(), $version );

	$font_base = defined( 'DOUGHBOSS_PLUGIN_URL' ) ? trailingslashit( DOUGHBOSS_PLUGIN_URL ) . 'public/fonts/' : content_url( '/plugins/doughboss/public/fonts/' );
	$font_css = "@font-face{font-family:'Bebas Neue';font-style:normal;font-weight:400;font-display:swap;src:url('" . esc_url_raw( $font_base . 'bebasneue-400.woff2' ) . "') format('woff2')}";
	foreach ( array( 400, 500, 600, 700 ) as $weight ) {
		$font_css .= "@font-face{font-family:'Barlow';font-style:normal;font-weight:" . $weight . ";font-display:swap;src:url('" . esc_url_raw( $font_base . 'barlow-' . $weight . '.woff2' ) . "') format('woff2')}";
	}
	wp_add_inline_style( 'doughboss-final', $font_css );

	if ( file_exists( $script_path ) ) {
		wp_enqueue_script( 'doughboss-final', get_stylesheet_directory_uri() . '/assets/theme.js', array(), (string) filemtime( $script_path ), true );
	}
}
add_action( 'wp_enqueue_scripts', 'doughboss_final_assets', 99 );

/** Load the real plugin storefront assets on theme-rendered pages. */
function doughboss_final_load_storefront_assets( $load ) {
	return $load || is_front_page() || is_page( array( 'menu', 'order', 'track-order', 'catering' ) );
}
add_filter( 'doughboss_load_assets', 'doughboss_final_load_storefront_assets' );

function doughboss_final_load_catering_assets( $load ) {
	return $load || is_page( 'catering' );
}
add_filter( 'doughboss_load_catering_assets', 'doughboss_final_load_catering_assets' );

function doughboss_final_load_hero_assets( $load ) {
	return $load || is_front_page() || is_page( array( 'order', 'menu', 'catering', 'about-us' ) );
}
add_filter( 'doughboss_load_manoush_hero_assets', 'doughboss_final_load_hero_assets' );

function doughboss_final_body_class( $classes ) {
	$classes[] = 'dbf-site';
	$classes[] = doughboss_final_ordering_open() ? 'dbf-ordering-open' : 'dbf-ordering-paused';
	return $classes;
}
add_filter( 'body_class', 'doughboss_final_body_class' );

function doughboss_final_menu_fallback() {
	$links = array(
		__( 'About', 'doughboss-final' )    => home_url( '/about-us/' ),
		__( 'Menu', 'doughboss-final' )     => home_url( '/order/' ),
		__( 'Catering', 'doughboss-final' ) => home_url( '/catering/' ),
		__( 'Locations', 'doughboss-final' ) => home_url( '/locations/' ),
		__( 'Partners', 'doughboss-final' ) => home_url( '/franchising/' ),
	);

	echo '<ul class="dbf-nav-list">';
	foreach ( $links as $label => $url ) {
		echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
	}
	echo '</ul>';
}

function doughboss_final_shortcode_or_notice( $shortcode, $message ) {
	$tag = strtok( trim( $shortcode, '[]' ), ' ' );
	if ( $tag && shortcode_exists( $tag ) ) {
		return do_shortcode( $shortcode );
	}
	return '<p class="dbf-system-notice" role="status">' . esc_html( $message ) . '</p>';
}
