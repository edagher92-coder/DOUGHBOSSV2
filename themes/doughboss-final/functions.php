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

define( 'DOUGHBOSS_FINAL_VERSION', '1.8.0' );

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
 * Return intrinsic dimensions for a bundled DoughBoss image when available.
 *
 * Bundled assets do not have Media Library attachment IDs, so WordPress cannot
 * generate a genuine srcset for them. Their original dimensions still reserve
 * the correct layout space without advertising the same source as fake variants.
 *
 * @param string $path Path relative to public/images.
 * @return array{0:int,1:int}|false
 */
function doughboss_final_asset_dimensions( $path ) {
	static $dimensions = array();

	$path = ltrim( str_replace( '\\', '/', (string) $path ), '/' );
	if ( false !== strpos( $path, '..' ) ) {
		return false;
	}
	if ( isset( $dimensions[ $path ] ) ) {
		return $dimensions[ $path ];
	}

	$base = defined( 'DOUGHBOSS_PLUGIN_DIR' ) ? DOUGHBOSS_PLUGIN_DIR : WP_PLUGIN_DIR . '/doughboss/';
	$file = trailingslashit( $base ) . 'public/images/' . $path;
	$size = is_readable( $file ) ? wp_getimagesize( $file ) : false;
	if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
		$dimensions[ $path ] = false;
		return false;
	}

	$dimensions[ $path ] = array( (int) $size[0], (int) $size[1] );
	return $dimensions[ $path ];
}

/**
 * Render a bundled DoughBoss image with its actual intrinsic dimensions.
 *
 * @param string                $path       Path relative to public/images.
 * @param string                $alt        Alternative text; use an empty string for decoration.
 * @param array<string,string> $attributes Supported image attributes.
 * @return string
 */
function doughboss_final_asset_image( $path, $alt, $attributes = array() ) {
	$dimensions = doughboss_final_asset_dimensions( $path );
	$allowed    = array( 'class', 'loading', 'decoding', 'fetchpriority' );
	$parts      = array(
		'src="' . esc_url( doughboss_final_asset_url( $path ) ) . '"',
		'alt="' . esc_attr( $alt ) . '"',
	);

	if ( is_array( $dimensions ) ) {
		$parts[] = 'width="' . (int) $dimensions[0] . '"';
		$parts[] = 'height="' . (int) $dimensions[1] . '"';
	}
	foreach ( $allowed as $attribute ) {
		if ( isset( $attributes[ $attribute ] ) && '' !== (string) $attributes[ $attribute ] ) {
			$parts[] = $attribute . '="' . esc_attr( $attributes[ $attribute ] ) . '"';
		}
	}

	$image = '<img ' . implode( ' ', $parts ) . '>';
	// Heroes fill the viewport; ordinary story/catering panels become half-width
	// above 1000px. Food cards pass their narrower grid sizes explicitly.
	$sizes = isset( $attributes['sizes'] ) ? $attributes['sizes'] :
		( isset( $attributes['class'] ) && 'dbf-page-hero-bg' === $attributes['class'] ? '108vw' : '(max-width: 1000px) 100vw, 50vw' );
	return class_exists( 'DoughBoss_Images' ) ? DoughBoss_Images::picture( $path, $image, $sizes ) : $image;
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

/**
 * Whether online ordering is open. The plugin's DoughBoss_Settings is the
 * source of truth; the raw option read keeps the theme working without it.
 *
 * @return bool
 */
function doughboss_final_ordering_open() {
	if ( class_exists( 'DoughBoss_Settings' ) && method_exists( 'DoughBoss_Settings', 'ordering_open' ) ) {
		return (bool) DoughBoss_Settings::ordering_open();
	}
	return '1' === (string) doughboss_final_setting( 'ordering_open', '0' );
}

/**
 * Public "call to order" number (Revesby by default; plugin setting order_phone).
 *
 * @return array{display:string,tel:string}
 */
function doughboss_final_order_phone() {
	if ( class_exists( 'DoughBoss_Settings' ) && method_exists( 'DoughBoss_Settings', 'order_phone' ) ) {
		$phone = DoughBoss_Settings::order_phone();
		return array(
			'display' => DoughBoss_Settings::phone_display( $phone ),
			'tel'     => DoughBoss_Settings::phone_tel( $phone ),
		);
	}
	return array( 'display' => '(02) 9774 2286', 'tel' => '+61297742286' );
}

/**
 * Whether a nav item is the "Order Online" link to the ordering page.
 *
 * @param object $item Nav menu item.
 * @return bool
 */
function doughboss_final_is_order_nav_item( $item ) {
	if ( ! is_object( $item ) || empty( $item->url ) ) {
		return false;
	}
	$path  = (string) wp_parse_url( (string) $item->url, PHP_URL_PATH );
	$title = strtolower( trim( wp_strip_all_tags( (string) $item->title ) ) );
	return '/order' === untrailingslashit( $path ) && false === strpos( $title, 'menu' );
}

/**
 * While ordering is paused, the "Order Online" nav link becomes "Call to
 * order" (tel:). Both variants ride along as data attributes so the plugin's
 * live /config read switches it back as soon as ordering opens.
 *
 * @param array $items Nav menu items.
 * @return array
 */
function doughboss_final_order_nav_items( $items ) {
	$open  = doughboss_final_ordering_open();
	$phone = doughboss_final_order_phone();
	foreach ( (array) $items as $item ) {
		if ( ! doughboss_final_is_order_nav_item( $item ) ) {
			continue;
		}
		$item->dbf_order_swap = array(
			'open_href'    => (string) $item->url,
			'open_label'   => wp_strip_all_tags( (string) $item->title ),
			'paused_href'  => 'tel:' . $phone['tel'],
			'paused_label' => __( 'Call to order', 'doughboss-final' ),
		);
		if ( ! $open ) {
			$item->url     = $item->dbf_order_swap['paused_href'];
			$item->title   = $item->dbf_order_swap['paused_label'];
			$item->classes = array_merge( (array) $item->classes, array( 'dbf-nav-call' ) );
		}
	}
	return $items;
}
add_filter( 'wp_nav_menu_objects', 'doughboss_final_order_nav_items', 20 );

/**
 * Whether a stored primary-menu item still exposes the paused franchising page.
 *
 * The page is a draft, so WordPress can serialise its old menu item URL as a
 * page-id query string. Its saved label or page slug remains available here.
 *
 * @param object $item Nav menu item.
 * @return bool
 */
function doughboss_final_is_franchising_nav_item( $item ) {
	if ( ! is_object( $item ) ) {
		return false;
	}

	$url       = (string) ( $item->url ?? '' );
	$path      = '' !== $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
	$host      = '' !== $url ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '';
	$site_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	$title     = strtolower( trim( wp_strip_all_tags( (string) ( $item->title ?? '' ) ) ) );
	$is_local  = '' === $host || strtolower( $host ) === strtolower( $site_host );
	if ( $is_local && ( '/franchising' === untrailingslashit( $path ) || false !== strpos( $title, 'franchis' ) ) ) {
		return true;
	}

	if ( 'page' !== (string) ( $item->object ?? '' ) || empty( $item->object_id ) || ! function_exists( 'get_post_field' ) ) {
		return false;
	}

	return 'franchising' === (string) get_post_field( 'post_name', (int) $item->object_id );
}

/**
 * Keep configured menus aligned with the paused franchising page: visitors can
 * still reach the existing wholesale enquiry form through the Partners link.
 *
 * @param array $items Nav menu items.
 * @return array
 */
function doughboss_final_partners_nav_items( $items ) {
	foreach ( (array) $items as $item ) {
		if ( ! doughboss_final_is_franchising_nav_item( $item ) ) {
			continue;
		}

		$item->url     = home_url( '/wholesale/' );
		$item->title   = __( 'Partners', 'doughboss-final' );
		$item->classes = array_merge( (array) ( $item->classes ?? array() ), array( 'dbf-nav-partners' ) );
		// This is now a custom wholesale link, not the paused franchise page.
		// Clear only the in-memory identity before the separate pause filter runs.
		$item->type      = 'custom';
		$item->object    = 'custom';
		$item->object_id = 0;
	}
	return $items;
}
add_filter( 'wp_nav_menu_objects', 'doughboss_final_partners_nav_items', 9 );

/**
 * Emit the swap attributes for the "Order Online" / "Call to order" link.
 *
 * @param array  $atts Link attributes.
 * @param object $item Nav item.
 * @return array
 */
function doughboss_final_order_nav_link_attributes( $atts, $item ) {
	if ( is_object( $item ) && ! empty( $item->dbf_order_swap ) && is_array( $item->dbf_order_swap ) ) {
		$swap                         = $item->dbf_order_swap;
		$atts['data-db-ordering-swap'] = '';
		$atts['data-db-open-href']     = $swap['open_href'];
		$atts['data-db-open-label']    = $swap['open_label'];
		$atts['data-db-paused-href']   = $swap['paused_href'];
		$atts['data-db-paused-label']  = $swap['paused_label'];
	}
	return $atts;
}
add_filter( 'nav_menu_link_attributes', 'doughboss_final_order_nav_link_attributes', 10, 2 );

/** The wholesale and franchising templates render [doughboss_b2b_form]. */
function doughboss_final_load_b2b_assets( $load ) {
	return $load || is_page( array( 'wholesale', 'franchising' ) );
}
add_filter( 'doughboss_load_b2b_assets', 'doughboss_final_load_b2b_assets' );

/**
 * Store cards for the home and locations pages. Addresses, phones and the
 * published fallback hours are the shop facts already on the site; when the
 * plugin is active, stored weekly hours (DoughBoss > Locations) take over.
 *
 * @return array<int,array<string,mixed>>
 */
function doughboss_final_store_cards() {
	$stores = array(
		array(
			'slug'     => 'revesby',
			'name'     => 'Revesby',
			'eyebrow'  => __( 'Revesby bakery', 'doughboss-final' ),
			'address'  => array( 'Shop 12/25 Selems Parade', 'Revesby NSW 2212' ),
			'phone'    => '(02) 9774 2286',
			'tel'      => '+61297742286',
			'maps'     => 'https://www.google.com/maps/search/?api=1&query=Dough+Boss+12+25+Selems+Parade+Revesby+NSW+2212',
			'note'     => __( 'Check the pickup selector for current online availability', 'doughboss-final' ),
			'featured' => true,
			'hours'    => array( 1 => array( array( '06:30', '14:30' ) ), 2 => array( array( '06:30', '14:30' ) ), 3 => array( array( '06:30', '14:30' ) ), 4 => array( array( '06:30', '14:30' ) ), 5 => array( array( '06:30', '14:30' ) ), 6 => array( array( '06:30', '14:30' ) ), 7 => array( array( '06:30', '14:30' ) ) ),
			'hours_label' => array( '7 days · 6:30am–2:30pm' ),
		),
		array(
			'slug'     => 'bankstown',
			'name'     => 'Bankstown',
			'eyebrow'  => __( 'Bankstown bakery', 'doughboss-final' ),
			'address'  => array( '462 Chapel Road', 'Bankstown NSW 2200' ),
			'phone'    => '(02) 8764 6783',
			'tel'      => '+61287646783',
			'maps'     => 'https://www.google.com/maps/search/?api=1&query=Dough+Boss+462+Chapel+Road+Bankstown+NSW+2200',
			'note'     => __( 'Visit or call for in-store availability', 'doughboss-final' ),
			'featured' => false,
			'hours'    => array( 1 => array( array( '07:00', '14:00' ) ), 2 => array( array( '07:00', '14:00' ) ), 3 => array( array( '07:00', '14:00' ) ), 4 => array( array( '07:00', '14:00' ) ), 5 => array( array( '07:00', '14:00' ) ) ),
			'hours_label' => array( 'Mon–Fri · 7am–2pm' ),
		),
		array(
			'slug'     => 'roselands',
			'name'     => 'Roselands Centro',
			'eyebrow'  => __( 'Roselands bakery', 'doughboss-final' ),
			'address'  => array( 'Shop MM03, Roselands Drive', 'Roselands NSW 2196' ),
			'phone'    => '0466 353 133',
			'tel'      => '+61466353133',
			'maps'     => 'https://www.google.com/maps/search/?api=1&query=Dough+Boss+Roselands+Centro+NSW+2196',
			'note'     => __( 'Visit or call for in-store availability', 'doughboss-final' ),
			'featured' => false,
			'hours'    => array( 1 => array( array( '08:00', '15:00' ) ), 2 => array( array( '08:00', '15:00' ) ), 3 => array( array( '08:00', '15:00' ) ), 4 => array( array( '08:00', '15:00' ) ), 5 => array( array( '08:00', '15:00' ) ), 6 => array( array( '08:00', '15:00' ) ), 7 => array( array( '08:00', '15:00' ) ) ),
			'hours_label' => array( '7 days · 8am–3pm' ),
		),
	);

	foreach ( $stores as &$store ) {
		$store['badge']       = '';
		$store['badge_state'] = '';
		if ( ! class_exists( 'DoughBoss_Store_Hours' ) ) {
			$store['hours_json'] = (string) wp_json_encode( $store['hours'] );
			continue;
		}
		$schedule = DoughBoss_Store_Hours::schedule_for( $store['slug'], DoughBoss_Store_Hours::stored_ranges_for_slug( $store['slug'] ) );
		if ( 'none' === $schedule['source'] ) {
			$schedule['week'] = DoughBoss_Store_Hours::normalise_week( $store['hours'] );
		}
		$store['hours']       = $schedule['week'];
		$store['hours_label'] = DoughBoss_Store_Hours::summary_lines( $schedule['week'] );
		$store['hours_json']  = DoughBoss_Store_Hours::to_json( $schedule['week'] );
		$state                = DoughBoss_Store_Hours::state( $schedule['week'] );
		$store['badge']       = DoughBoss_Store_Hours::badge_label( $state );
		$store['badge_state'] = $state['known'] ? ( $state['open'] ? 'open' : 'closed' ) : '';
	}
	unset( $store );
	return $stores;
}

/**
 * Build the public, online-only manifest from verified Site Icon image data.
 * Only HTTPS uploads on the canonical site's origin are allowed; no URL may
 * carry credentials, a query or fragment. Missing exact icon sizes fail closed.
 *
 * @param string $home Canonical home URL.
 * @param string $uploads Approved WordPress uploads base URL.
 * @param array $icons Site Icon URL and dimensions, keyed by required size.
 * @return array|null
 */
function doughboss_final_manifest_data( $home, $uploads, $icons ) {
	if ( ! is_string( $home ) || '' === trim( $home ) || ! is_string( $uploads ) || '' === trim( $uploads ) || ! is_array( $icons ) ) {
		return null;
	}
	$origin = wp_parse_url( $home );
	$base   = wp_parse_url( $uploads );
	if ( ! is_array( $origin ) || ! is_array( $base ) ) {
		return null;
	}
	foreach ( array( $origin, $base ) as $url ) {
		if ( ( $url['scheme'] ?? '' ) !== 'https' || empty( $url['host'] ) || array_intersect( array( 'user', 'pass', 'query', 'fragment' ), array_keys( $url ) ) ) {
			return null;
		}
	}
	if ( strtolower( $origin['host'] ) !== strtolower( $base['host'] ) || ( $origin['port'] ?? 443 ) !== ( $base['port'] ?? 443 ) || empty( $base['path'] ) || '/' === $base['path'] ) {
		return null;
	}
	$manifest_icons = array();
	foreach ( array( 192, 512 ) as $size ) {
		$icon = $icons[ $size ] ?? null;
		if ( ! is_array( $icon ) || ( $icon['width'] ?? 0 ) !== $size || ( $icon['height'] ?? 0 ) !== $size || ! is_string( $icon['src'] ?? null ) || preg_match( '/[\x00-\x20\\\\]/', $icon['src'] ) ) {
			return null;
		}
		$url  = wp_parse_url( $icon['src'] );
		$path = is_array( $url ) ? ( $url['path'] ?? '' ) : '';
		if ( ! is_array( $url ) || ( $url['scheme'] ?? '' ) !== 'https' || strtolower( $url['host'] ?? '' ) !== strtolower( $origin['host'] ) || ( $url['port'] ?? 443 ) !== ( $origin['port'] ?? 443 ) || array_intersect( array( 'user', 'pass', 'query', 'fragment' ), array_keys( $url ) ) || 0 !== strpos( $path, rtrim( $base['path'], '/' ) . '/' ) || preg_match( '/%|(?:^|\/)\.{1,2}(?:\/|$)/', $path ) || ! preg_match( '/\.png$/i', $path ) ) {
			return null;
		}
		$manifest_icons[] = array( 'src' => $icon['src'], 'sizes' => $size . 'x' . $size, 'type' => 'image/png', 'purpose' => 'any' );
	}
	return array(
		'id' => $home, 'name' => 'Dough Boss', 'short_name' => 'Dough Boss',
		'start_url' => rtrim( $home, '/' ) . '/order/', 'scope' => $origin['path'] ?? '/', 'display' => 'standalone',
		'background_color' => '#f7f5f0', 'theme_color' => '#e2231a',
		'prefer_related_applications' => false, 'icons' => $manifest_icons,
	);
}

/** Disable this integration when another plugin already supplies a manifest. */
function doughboss_final_public_manifest() {
	if ( ! apply_filters( 'doughboss_final_install_enabled', true ) ) {
		return null;
	}
	$uploads = wp_upload_dir( null, false );
	$icons   = array();
	$icon_id = (int) get_option( 'site_icon', 0 );
	foreach ( array( 192, 512 ) as $size ) {
		$src   = get_site_icon_url( $size );
		$image = $icon_id ? wp_get_attachment_image_src( $icon_id, array( $size, $size ) ) : false;
		if ( ! is_array( $image ) || $src !== $image[0] ) {
			return null;
		}
		$icons[ $size ] = array( 'src' => $src, 'width' => (int) $image[1], 'height' => (int) $image[2] );
	}
	return doughboss_final_manifest_data( home_url( '/' ), $uploads['baseurl'] ?? '', $icons );
}

/** Accept only the exact root GET query, never an order/account URL. */
function doughboss_final_is_manifest_request( $method, $request_uri, $home ) {
	$uri = wp_parse_url( $request_uri );
	return 'GET' === $method && is_array( $uri ) && ! array_intersect( array( 'scheme', 'host', 'user', 'pass', 'fragment' ), array_keys( $uri ) ) && ( $uri['path'] ?? '' ) === ( wp_parse_url( $home, PHP_URL_PATH ) ?: '/' ) && ( $uri['query'] ?? '' ) === 'db_manifest=1';
}

function doughboss_final_serve_manifest() {
	if ( ! doughboss_final_is_manifest_request( $_SERVER['REQUEST_METHOD'] ?? '', $_SERVER['REQUEST_URI'] ?? '', home_url( '/' ) ) ) {
		return;
	}
	$manifest = doughboss_final_public_manifest();
	status_header( $manifest ? 200 : 404 );
	header( 'Content-Type: application/manifest+json; charset=UTF-8' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Cache-Control: no-store' );
	echo wp_json_encode( $manifest ?: array( 'error' => 'Install manifest unavailable' ) );
	exit;
}

function doughboss_final_manifest_head() {
	if ( ! is_admin() && doughboss_final_public_manifest() ) {
		echo '<link rel="manifest" href="' . esc_url( home_url( '/' ) . '?db_manifest=1' ) . '">' . "\n";
	}
}

function doughboss_final_install_assets() {
	if ( ! is_page( 'members-events' ) || ! doughboss_final_public_manifest() ) {
		return;
	}
	$path = get_stylesheet_directory() . '/assets/install.js';
	if ( file_exists( $path ) ) {
		wp_enqueue_script( 'doughboss-final-install', get_stylesheet_directory_uri() . '/assets/install.js', array(), (string) filemtime( $path ), true );
	}
}

add_action( 'template_redirect', 'doughboss_final_serve_manifest', 0 );
add_action( 'wp_head', 'doughboss_final_manifest_head', 2 );
add_action( 'wp_enqueue_scripts', 'doughboss_final_install_assets' );

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

/** A small public-read script powers the sitewide pickup selector. */
add_filter( 'doughboss_load_shop_status_assets', '__return_true' );

/** Identify the theme's template-rendered order journey for plugin body classes. */
function doughboss_final_is_order_page( $is_order_page ) {
	return $is_order_page || is_page( array( 'menu', 'order' ) );
}
add_filter( 'doughboss_is_order_page', 'doughboss_final_is_order_page' );

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

	return is_front_page() || is_page( doughboss_final_seo_covered_pages() );
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
	return array( 'about-us', 'catering', 'locations', 'menu', 'order', 'track-order', 'vouchers', 'members-events' );
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
	if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) ) {
		return $parts;
	}
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
		__( 'Partners', 'doughboss-final' ) => home_url( '/wholesale/' ),
	);

	echo '<ul class="dbf-nav-list">';
	foreach ( $links as $label => $url ) {
		echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
	}
	echo '</ul>';
}

/**
 * Catering pickup and notice summary, e.g. "Pickup from Revesby · minimum 2
 * business days' notice". The day count follows the plugin's catering
 * setting when the plugin is active (default 2).
 *
 * @return string
 */
function doughboss_final_catering_notice_line() {
	$days = class_exists( 'DoughBoss_Catering' ) && method_exists( 'DoughBoss_Catering', 'min_notice_business_days' )
		? (int) DoughBoss_Catering::min_notice_business_days()
		: 2;
	if ( $days < 1 ) {
		return __( 'Pickup from Revesby', 'doughboss-final' );
	}
	return sprintf(
		/* translators: %d: minimum business days of notice. */
		_n( 'Pickup from Revesby · minimum %d business day’s notice', 'Pickup from Revesby · minimum %d business days’ notice', $days, 'doughboss-final' ),
		$days
	);
}

function doughboss_final_shortcode_or_notice( $shortcode, $message ) {
	$tag = strtok( trim( $shortcode, '[]' ), ' ' );
	if ( $tag && shortcode_exists( $tag ) ) {
		return do_shortcode( $shortcode );
	}
	return '<p class="dbf-system-notice" role="status">' . esc_html( $message ) . '</p>';
}
