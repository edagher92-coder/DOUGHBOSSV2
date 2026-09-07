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

define( 'DOUGHBOSS_FINAL_VERSION', '1.4.0' );

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

/**
 * Opt template-rendered public pages into the plugin's metadata and JSON-LD.
 *
 * The final theme renders its DoughBoss shortcodes from PHP templates rather
 * than storing them in post_content. Without this filter, the plugin cannot
 * discover those experiences when deciding whether to emit metadata.
 *
 * @param bool    $relevant Existing decision.
 * @param WP_Post $post     Current singular post.
 * @return bool
 */
function doughboss_final_seo_relevant_page( $relevant, $post ) {
	if ( $relevant || ! $post instanceof WP_Post ) {
		return (bool) $relevant;
	}

	return is_front_page() || is_page(
		array(
			'about-us',
			'catering',
			'locations',
			'menu',
			'order',
			'track-order',
			'vouchers',
		)
	);
}
add_filter( 'doughboss_seo_relevant_page', 'doughboss_final_seo_relevant_page', 10, 2 );

/**
 * Pages the DoughBoss plugin's own SEO fallback already covers.
 *
 * Kept as one list so doughboss_final_seo_relevant_page() (which opts them in)
 * and doughboss_final_thin_page_meta() (which fills the gap for everything
 * else) can never disagree and emit two description tags.
 *
 * @return string[]
 */
function doughboss_final_seo_covered_pages() {
	return array( 'about-us', 'catering', 'locations', 'menu', 'order', 'track-order', 'vouchers' );
}

/**
 * Per-page metadata for the pages the plugin does not describe.
 *
 * /wholesale/, /franchising/, /privacy-policy/ and /terms-conditions/ shipped
 * with no meta description and no Open Graph tags at all, so a share of any of
 * them rendered as a bare URL and search engines had to invent a snippet.
 *
 * @return array{title:string,description:string}|null
 */
function doughboss_final_thin_page_copy() {
	if ( is_page( 'wholesale' ) ) {
		return array(
			'title'       => __( 'Dough Boss Wholesale | Manoush & Pies Supply Sydney', 'doughboss-final' ),
			'description' => __( 'Wholesale supply from Dough Boss — manoush, pies and bakery lines for Sydney cafes, grocers and venues. Talk to us about product fit and volumes.', 'doughboss-final' ),
		);
	}
	if ( is_page( 'franchising' ) ) {
		return array(
			'title'       => __( 'Dough Boss Partnerships & Franchising | Sydney', 'doughboss-final' ),
			'description' => __( 'Explore a Dough Boss partnership — the brand, the operating model and what running a contemporary Lebanese bakery involves.', 'doughboss-final' ),
		);
	}
	if ( is_page( 'privacy-policy' ) ) {
		return array(
			'title'       => __( 'Privacy Policy | Dough Boss', 'doughboss-final' ),
			'description' => __( 'How Dough Boss collects, uses and protects the personal information you provide when ordering, claiming a voucher or contacting us.', 'doughboss-final' ),
		);
	}
	if ( is_page( array( 'terms-conditions', 'terms-and-conditions' ) ) ) {
		return array(
			'title'       => __( 'Terms & Conditions | Dough Boss', 'doughboss-final' ),
			'description' => __( 'The terms that apply to Dough Boss online orders, pickup, vouchers and catering enquiries.', 'doughboss-final' ),
		);
	}
	return null;
}

/**
 * Emit description and Open Graph tags for pages the plugin's SEO skips.
 *
 * Deliberately mirrors DoughBoss_SEO::relevant_page() so exactly one of the two
 * ever writes a description for a given request.
 *
 * @return void
 */
function doughboss_final_thin_page_meta() {
	if ( ! is_singular() || is_admin() ) {
		return;
	}
	if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) ) {
		return;
	}
	$post = get_post();
	if ( ! $post instanceof WP_Post ) {
		return;
	}
	// If the plugin will describe this page, stay out of its way.
	if ( (bool) apply_filters( 'doughboss_seo_relevant_page', false, $post ) ) {
		return;
	}
	foreach ( array( 'doughboss_menu', 'doughboss_builder', 'doughboss_cart', 'doughboss_order_tracking', 'doughboss_shop_picker', 'doughboss_catering', 'doughboss_voucher_claim', 'doughboss_manoush_hero', 'doughboss_ordering_status' ) as $shortcode ) {
		if ( has_shortcode( $post->post_content, $shortcode ) ) {
			return;
		}
	}

	$copy = doughboss_final_thin_page_copy();
	if ( null === $copy ) {
		return;
	}
	$image = doughboss_final_asset_url( 'doughboss-social-card.jpg' );
	printf(
		'<meta name="description" content="%1$s" />' . "\n"
		. '<meta property="og:type" content="website" />' . "\n"
		. '<meta property="og:locale" content="en_AU" />' . "\n"
		. '<meta property="og:site_name" content="%2$s" />' . "\n"
		. '<meta property="og:title" content="%3$s" />' . "\n"
		. '<meta property="og:description" content="%1$s" />' . "\n"
		. '<meta property="og:url" content="%4$s" />' . "\n"
		. '<meta property="og:image" content="%5$s" />' . "\n"
		. '<meta name="twitter:card" content="summary_large_image" />' . "\n"
		. '<meta name="twitter:title" content="%3$s" />' . "\n"
		. '<meta name="twitter:description" content="%1$s" />' . "\n"
		. '<meta name="twitter:image" content="%5$s" />' . "\n",
		esc_attr( $copy['description'] ),
		esc_attr( get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : 'Dough Boss' ),
		esc_attr( $copy['title'] ),
		esc_url( get_permalink() ),
		esc_url( $image )
	);
}
add_action( 'wp_head', 'doughboss_final_thin_page_meta', 4 );

/**
 * Give the thin pages a distinct document title too.
 *
 * @param array<string,string> $parts Title parts.
 * @return array<string,string>
 */
function doughboss_final_thin_page_title( $parts ) {
	$copy = is_singular() ? doughboss_final_thin_page_copy() : null;
	if ( null !== $copy ) {
		$parts['title'] = $copy['title'];
		unset( $parts['tagline'] );
	}
	return $parts;
}
add_filter( 'document_title_parts', 'doughboss_final_thin_page_title', 20 );

/**
 * Consolidate the duplicate menu page onto the ordering page.
 *
 * page-menu.php is literally `require page-order.php`, so /menu/ and /order/
 * render byte-identical HTML with identical titles and descriptions but
 * self-referential canonicals — two indexable URLs competing for one page.
 * Pointing /menu/ at /order/ keeps the friendly URL working for customers and
 * gives search engines one address. Filterable so this can be reversed, or
 * flipped to make /menu/ the primary, without another deploy.
 *
 * @param string $canonical Canonical URL WordPress resolved.
 * @return string
 */
function doughboss_final_canonical_url( $canonical ) {
	if ( ! is_page( 'menu' ) ) {
		return $canonical;
	}
	return (string) apply_filters( 'doughboss_final_menu_canonical', home_url( '/order/' ) );
}
add_filter( 'get_canonical_url', 'doughboss_final_canonical_url', 20 );

/**
 * Drop Contact Form 7's stylesheet and two scripts on pages with no CF7 form.
 *
 * Measured on the live pages: ~29KB of CSS+JS loaded on every request while no
 * CF7 form exists anywhere in this theme's templates or the plugin's
 * shortcodes. Content-driven, so a real CF7 form on any page keeps its assets.
 *
 * @return void
 */
function doughboss_final_dequeue_unused_cf7() {
	if ( is_admin() ) {
		return;
	}
	$post    = is_singular() ? get_post() : null;
	$content = $post instanceof WP_Post ? (string) $post->post_content : '';
	if ( '' !== $content && ( has_shortcode( $content, 'contact-form-7' ) || false !== strpos( $content, 'wpcf7' ) ) ) {
		return;
	}
	if ( ! apply_filters( 'doughboss_final_dequeue_cf7', true ) ) {
		return;
	}
	foreach ( array( 'contact-form-7', 'contact-form-7-rtl' ) as $handle ) {
		wp_dequeue_style( $handle );
		wp_deregister_style( $handle );
	}
	foreach ( array( 'contact-form-7', 'swv', 'wpcf7-recaptcha' ) as $handle ) {
		wp_dequeue_script( $handle );
		wp_deregister_script( $handle );
	}
}
add_action( 'wp_enqueue_scripts', 'doughboss_final_dequeue_unused_cf7', 100 );

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
		__( 'Vouchers', 'doughboss-final' ) => home_url( '/vouchers/' ),
		__( 'Track order', 'doughboss-final' ) => home_url( '/track-order/' ),
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
