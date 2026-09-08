<?php
/** CLI-only WordPress presentation boundary for the static visual preview. */

if ( PHP_SAPI !== 'cli' || empty( $GLOBALS['doughboss_preview_repo_root'] ) ) {
	throw new RuntimeException( 'The preview shim may only be loaded by build-visual-preview.php.' );
}

$preview_repo = $GLOBALS['doughboss_preview_repo_root'];
$preview_base = $GLOBALS['doughboss_preview_base_path'];

define( 'ABSPATH', $preview_repo . '/' );
define( 'DOUGHBOSS_PLUGIN_DIR', $preview_repo . '/' );
define( 'DOUGHBOSS_PLUGIN_URL', $preview_base );
define( 'WP_PLUGIN_DIR', $preview_repo );

function add_action() {}
function add_filter() {}
function __return_true() { return true; }
function __( $text ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $url ) { return esc_attr( $url ); }
function esc_url_raw( $url ) { return (string) $url; }
function esc_html__( $text ) { return esc_html( $text ); }
function esc_attr__( $text ) { return esc_attr( $text ); }
function esc_html_e( $text ) { echo esc_html( $text ); }
function esc_attr_e( $text ) { echo esc_attr( $text ); }
function trailingslashit( $value ) { return rtrim( (string) $value, "/\\" ) . '/'; }
function wp_getimagesize( $file ) { return getimagesize( $file ); }
function attachment_url_to_postid() { return 0; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function shortcode_atts( $defaults, $attributes ) { return array_merge( $defaults, is_array( $attributes ) ? $attributes : array() ); }
function language_attributes() { echo 'lang="en-AU"'; }
function bloginfo( $field ) { echo 'charset' === $field ? 'UTF-8' : 'Dough Boss'; }
function wp_body_open() {
	$base = esc_attr( $GLOBALS['doughboss_preview_base_path'] );
	echo '<aside class="db-preview-banner" role="note"><strong>Visual preview</strong><span>Sample menu and prices only. No real orders, payments, enquiries, calls, emails or bookings. Demo settings never reach the live site.</span><nav aria-label="Preview pages"><a href="' . $base . 'index.html">Home</a><a href="' . $base . 'order.html">Sample menu</a><a href="' . $base . 'paused.html">Paused state</a></nav><span id="db-preview-action" role="status" aria-live="polite"></span></aside>';
}
function wp_date( $format ) { return gmdate( $format ); }
function get_option( $key, $default = false ) {
	if ( 'doughboss_settings' !== $key ) {
		return $default;
	}
	return array(
		'ordering_open'    => ! empty( $GLOBALS['doughboss_preview_ordering_open'] ) ? '1' : '0',
		'google_review_url' => '#preview-unavailable',
	);
}
function home_url( $path = '/' ) {
	$base = $GLOBALS['doughboss_preview_base_path'];
	if ( '/' === $path || '' === $path ) {
		return $base . 'index.html';
	}
	if ( 0 === strpos( $path, '/order/' ) ) {
		$hash = parse_url( $path, PHP_URL_FRAGMENT );
		return $base . 'order.html' . ( $hash ? '#' . rawurlencode( $hash ) : '' );
	}
	return '#preview-unavailable';
}
function body_class() {
	$classes = function_exists( 'doughboss_final_body_class' ) ? doughboss_final_body_class( array() ) : array( 'dbf-site' );
	if ( 'page-order.php' === $GLOBALS['doughboss_preview_template'] ) {
		$classes[] = 'doughboss-order-page';
		if ( empty( $GLOBALS['doughboss_preview_ordering_open'] ) ) {
			$classes[] = 'doughboss-ordering-closed';
		}
	}
	echo 'class="' . esc_attr( implode( ' ', $classes ) ) . '"';
}
function wp_nav_menu( $arguments ) {
	if ( isset( $arguments['fallback_cb'] ) && is_callable( $arguments['fallback_cb'] ) ) {
		call_user_func( $arguments['fallback_cb'] );
	}
}
function get_header() { require $GLOBALS['doughboss_preview_repo_root'] . '/themes/doughboss-final/header.php'; }
function get_footer() { require $GLOBALS['doughboss_preview_repo_root'] . '/themes/doughboss-final/footer.php'; }
function get_template_part( $slug ) {
	$file = $GLOBALS['doughboss_preview_repo_root'] . '/themes/doughboss-final/' . ltrim( $slug, '/' ) . '.php';
	if ( ! is_file( $file ) ) {
		throw new RuntimeException( 'Preview template part not found: ' . $slug );
	}
	require $file;
}
function wp_head() {
	$base  = esc_attr( $GLOBALS['doughboss_preview_base_path'] );
	$title = esc_html( $GLOBALS['doughboss_preview_title'] );
	echo '<title>' . $title . '</title>';
	echo '<meta name="robots" content="noindex,nofollow,noarchive,nosnippet">';
	echo '<meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; connect-src &#39;none&#39;; form-action &#39;none&#39;; object-src &#39;none&#39;; base-uri &#39;none&#39;; img-src &#39;self&#39; data:; font-src &#39;self&#39;; style-src &#39;self&#39; &#39;unsafe-inline&#39;; script-src &#39;self&#39;">';
	echo '<link rel="stylesheet" href="' . $base . 'public/css/doughboss-manoush-hero.css">';
	echo '<link rel="stylesheet" href="' . $base . 'public/css/doughboss.css">';
	if ( 'page-order.php' === $GLOBALS['doughboss_preview_template'] ) {
		echo '<link rel="stylesheet" href="' . $base . 'public/css/doughboss-order-page.css">';
	}
	echo '<link rel="stylesheet" href="' . $base . 'themes/doughboss-final/style.css">';
	echo '<link rel="stylesheet" href="' . $base . 'preview/preview.css">';
}
function wp_footer() {
	$base = esc_attr( $GLOBALS['doughboss_preview_base_path'] );
	echo '<script src="' . $base . 'preview/menu-options.js"></script>';
	echo '<script src="' . $base . 'preview/preview-api.js"></script>';
	echo '<script src="' . $base . 'public/js/doughboss-shop-status.js"></script>';
	echo '<script src="' . $base . 'public/js/doughboss.js"></script>';
	echo '<script src="' . $base . 'public/js/doughboss-manoush-hero.js"></script>';
	echo '<script src="' . $base . 'themes/doughboss-final/assets/theme.js"></script>';
}

class DoughBoss_Settings {
	public static function ordering_open() { return ! empty( $GLOBALS['doughboss_preview_ordering_open'] ); }
	public static function ordering_closed_message() { return 'Sample paused-state message. Check a shop for current availability.'; }
}

require $preview_repo . '/themes/doughboss-final/functions.php';
require $preview_repo . '/includes/class-doughboss-shortcodes.php';
require $preview_repo . '/includes/class-doughboss-menu-options.php';

$GLOBALS['doughboss_preview_shortcodes'] = new DoughBoss_Shortcodes();

function shortcode_exists( $tag ) {
	return in_array( $tag, array( 'doughboss_manoush_hero', 'doughboss_ordering_status', 'doughboss_shop_picker', 'doughboss_menu', 'doughboss_builder', 'doughboss_cart' ), true );
}
function do_shortcode( $source ) {
	if ( ! preg_match( '/^\[([a-z0-9_]+)(.*?)\]$/s', trim( $source ), $match ) ) {
		return '';
	}
	$tag        = $match[1];
	$attributes = array();
	if ( preg_match_all( '/([a-z0-9_]+)="([^"]*)"/', $match[2], $pairs, PREG_SET_ORDER ) ) {
		foreach ( $pairs as $pair ) {
			$attributes[ $pair[1] ] = html_entity_decode( $pair[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
	}
	$shortcodes = $GLOBALS['doughboss_preview_shortcodes'];
	if ( 'doughboss_manoush_hero' === $tag ) {
		return $shortcodes->manoush_hero( $attributes );
	}
	if ( 'doughboss_ordering_status' === $tag ) {
		return $shortcodes->ordering_status();
	}
	if ( 'doughboss_shop_picker' === $tag ) {
		return $shortcodes->shop_picker();
	}
	if ( 'doughboss_menu' === $tag ) {
		return $shortcodes->menu( $attributes );
	}
	if ( 'doughboss_builder' === $tag ) {
		return '<p class="dbf-system-notice" role="status">Pizza builder is not available in this visual preview.</p>';
	}
	if ( 'doughboss_cart' === $tag ) {
		return '<p class="dbf-system-notice" role="status">Cart and checkout are not available in this visual preview.</p>';
	}
	return '';
}

function doughboss_preview_render_page( $template, $ordering_open, $title ) {
	$allowed = array( 'front-page.php', 'page-order.php' );
	if ( ! in_array( $template, $allowed, true ) ) {
		throw new RuntimeException( 'Preview template is not allowlisted: ' . $template );
	}
	$GLOBALS['doughboss_preview_template']      = $template;
	$GLOBALS['doughboss_preview_ordering_open'] = (bool) $ordering_open;
	$GLOBALS['doughboss_preview_title']         = $title;
	ob_start();
	require $GLOBALS['doughboss_preview_repo_root'] . '/themes/doughboss-final/' . $template;
	$html = ob_get_clean();
	// No-JS and alternate-click safety: retain labels but make every external,
	// phone and email destination inert in the exported artifact itself.
	return preg_replace( '/\shref=("|\')(?:https?:\/\/|mailto:|tel:)[^"\']*\1/i', ' href=$1#preview-unavailable$1', $html );
}

function doughboss_preview_fixture() {
	return array(
		'menu' => array(
			array(
				'id' => 1001, 'name' => 'Zaatar & Cheese', 'description' => 'Sample menu description for visual review.',
				'price' => 8.5, 'type' => 'menu', 'image' => 'public/images/menu/real-v1/zaatar-cheese.jpg',
				'category' => 'Manoush', 'available' => true, 'dietary' => array( 'vegetarian' ),
				'options' => DoughBoss_Menu_Options::for_item( 'Manoush', 'Zaatar & Cheese' ),
			),
			array(
				'id' => 1002, 'name' => 'Sujuk Deluxe', 'description' => 'Sample pizza description using approved bundled photography.',
				'price' => 16, 'type' => 'menu', 'image' => 'public/images/menu/real-v1/sujuk-deluxe.jpg',
				'category' => 'Pizza', 'available' => true, 'dietary' => array( 'halal' ),
				'options' => DoughBoss_Menu_Options::for_item( 'Pizza', 'Sujuk Deluxe' ),
			),
			array(
				'id' => 1003, 'name' => 'Spinach Pie', 'description' => 'Sample oven-baked pie description.',
				'price' => 7, 'type' => 'menu', 'image' => 'public/images/menu/real-v1/spinach-pie.jpg',
				'category' => 'Pies', 'available' => true, 'dietary' => array( 'vegetarian' ),
				'options' => DoughBoss_Menu_Options::for_item( 'Pies', 'Spinach Pie' ),
			),
			array(
				'id' => 1004, 'name' => 'Labneh Veggie Wrap', 'description' => 'Sample fresh wrap description.',
				'price' => 12, 'type' => 'menu', 'image' => 'public/images/menu/real-v1/labneh-veggie-wrap.jpg',
				'category' => 'Wraps', 'available' => false, 'dietary' => array( 'vegetarian' ),
				'options' => DoughBoss_Menu_Options::for_item( 'Wraps', 'Labneh Veggie Wrap' ),
			),
			array(
				'id' => 1005, 'name' => 'Choco Banana', 'description' => 'Sample dessert description.',
				'price' => 9, 'type' => 'menu', 'image' => 'public/images/menu/real-v1/choco-banana.jpg',
				'category' => 'Desserts', 'available' => true, 'dietary' => array(), 'options' => array(),
			),
		),
	);
}
