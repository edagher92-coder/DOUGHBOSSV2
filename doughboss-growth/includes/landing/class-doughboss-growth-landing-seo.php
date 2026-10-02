<?php
/**
 * DoughBoss Growth landing pages: the search head (title, description, social tags, JSON-LD).
 *
 * Ownership, from the architecture (00 section 3.2): the companion owns head output ONLY on its own landing
 * pages (pages recorded in the doughboss_growth_pages option whose content is still exactly its shortcode), and
 * only while a dedicated SEO plugin is not active.
 *
 *  - title:        document_title_parts at priority 30 (after the theme's 20).
 *  - description, Open Graph, Twitter, JSON-LD: wp_head at priority 6 (core's SEO class is at 5 and stays silent on
 *                  these pages because their content holds no core shortcode).
 *  - canonical:    NOT printed. WordPress already prints rel="canonical" for a singular page.
 *  - robots:       no extra tag. A page with no content of its own (see DoughBoss_Growth_Landing::is_indexable())
 *                  gets "noindex" through the wp_robots filter, which adjusts the one tag WordPress prints.
 *  - SEO plugin:   with Yoast, Rank Math, All in One SEO or SEOPress active the companion prints nothing, except
 *                  the JSON-LD graph when the setting seo_jsonld_with_seo_plugin is on. The Landing pages admin tab
 *                  shows each page's title and description for the operator to paste into that plugin.
 *
 * Both flags must be on for any head output: seo_head and landing_pages (metadata for a page that renders nothing
 * would be wrong). Any missing piece of data means no output for that page (fail closed).
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Head output for landing pages.
 */
final class DoughBoss_Growth_Landing_SEO {

	/**
	 * The brand name appended to every title and used as the Open Graph site name.
	 */
	const BRAND = 'Dough Boss';

	/**
	 * Hook priorities.
	 */
	const TITLE_PRIORITY = 30;
	const HEAD_PRIORITY  = 6;

	/**
	 * Longest title and description printed (characters). The authoring guideline is 60 and 155; these are the
	 * hard limits at which a page prints nothing instead.
	 */
	const MAX_TITLE       = 70;
	const MAX_DESCRIPTION = 200;

	/**
	 * Hook the head. Output is decided at call time, so a change of state needs no re-registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'document_title_parts', array( __CLASS__, 'filter_title_parts' ), self::TITLE_PRIORITY );
		add_action( 'wp_head', array( __CLASS__, 'print_head' ), self::HEAD_PRIORITY );
		add_filter( 'wp_robots', array( __CLASS__, 'filter_robots' ), 20 );
	}

	/**
	 * Whether head output is allowed at all: seo_head AND landing_pages are effectively on.
	 *
	 * @return bool
	 */
	public static function head_enabled() {
		return DoughBoss_Growth_Settings::enabled( 'seo_head' ) && DoughBoss_Growth_Settings::enabled( 'landing_pages' );
	}

	/**
	 * Whether a dedicated SEO plugin owns the head (the same four constants core's SEO class checks).
	 *
	 * @return bool
	 */
	public static function seo_plugin_active() {
		return defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * Whether the JSON-LD graph may be printed next to an SEO plugin (setting, default off).
	 *
	 * @return bool
	 */
	public static function jsonld_with_seo_plugin() {
		return 1 === (int) DoughBoss_Growth_Settings::get( 'seo_jsonld_with_seo_plugin', 0 );
	}

	/**
	 * The title and description for a page key, expanded from core data and checked. Needs no created page, so
	 * the admin tab can preview it.
	 *
	 * @param string $key Landing page key.
	 * @return array|null array( title, description, crumb ) or null when the page cannot render.
	 */
	public static function texts( $key ) {
		$def = DoughBoss_Growth_Landing::definition( $key );
		if ( null === $def ) {
			return null;
		}
		$composed = DoughBoss_Growth_Landing::compose( $key );
		if ( true !== $composed['ok'] ) {
			return null;
		}
		$values = $composed['placeholders'];
		$title  = DoughBoss_Growth_Landing::expand( $def['title'], $values );
		$desc   = DoughBoss_Growth_Landing::expand( $def['description'], $values );
		$crumb  = DoughBoss_Growth_Landing::expand( $def['crumb'], $values );
		if ( null === $title || null === $desc || null === $crumb ) {
			return null;
		}
		$title = $title . ' | ' . self::BRAND;
		foreach ( array( $title, $desc, $crumb ) as $text ) {
			if ( array() !== DoughBoss_Growth_Landing::lint_text( $text, 'core-data' ) ) {
				return null;
			}
		}
		if ( self::length( $title ) > self::MAX_TITLE || self::length( $desc ) > self::MAX_DESCRIPTION ) {
			return null;
		}
		return array(
			'title'       => $title,
			'description' => $desc,
			'crumb'       => $crumb,
		);
	}

	/**
	 * Everything the head prints for one page, or null when the page must print nothing.
	 *
	 * @param string $key Landing page key.
	 * @return array|null array( title, description, url, image, site_name ).
	 */
	public static function metadata( $key ) {
		$page_id = DoughBoss_Growth_Landing::page_id( $key );
		if ( $page_id < 1 ) {
			return null;
		}
		$texts = self::texts( $key );
		if ( null === $texts ) {
			return null;
		}
		$url = get_permalink( $page_id );
		if ( ! is_string( $url ) || '' === $url || 1 !== preg_match( '#^https?://#i', $url ) ) {
			return null;
		}
		return array(
			'title'       => $texts['title'],
			'description' => $texts['description'],
			'url'         => $url,
			'image'       => self::social_image(),
			'site_name'   => self::BRAND,
		);
	}

	/**
	 * Core's 1200 by 630 social card, when core exposes its URL. Empty string otherwise (no image tags).
	 *
	 * @return string
	 */
	public static function social_image() {
		if ( ! defined( 'DOUGHBOSS_PLUGIN_URL' ) ) {
			return '';
		}
		$base = constant( 'DOUGHBOSS_PLUGIN_URL' );
		return is_string( $base ) && '' !== $base ? $base . 'public/images/doughboss-social-card.jpg' : '';
	}

	/**
	 * The JSON-LD document for one page, or null.
	 *
	 * @param string $key Landing page key.
	 * @return array|null
	 */
	public static function graph( $key ) {
		$def      = DoughBoss_Growth_Landing::definition( $key );
		$meta     = self::metadata( $key );
		$composed = DoughBoss_Growth_Landing::compose( $key );
		$texts    = self::texts( $key );
		if ( null === $def || null === $meta || null === $texts || true !== $composed['ok'] ) {
			return null;
		}
		$home   = trailingslashit( home_url( '/' ) );
		$crumbs = array( array( __( 'Home', 'doughboss-growth' ), $home ) );
		$parent = DoughBoss_Growth_Landing::parent_page( $def );
		if ( null === $parent ) {
			return null;
		}
		$crumbs[] = array( $parent['title'], $parent['url'] );
		$crumbs[] = array( $texts['crumb'], $meta['url'] );

		$ctx = array(
			'home'   => $home,
			'url'    => $meta['url'],
			'crumbs' => $crumbs,
			'kind'   => $def['kind'],
			'faqs'   => $composed['faqs'],
		);
		if ( 'catering' === $def['kind'] ) {
			$ctx['service']  = array( 'name' => $def['service_name'] );
			$ctx['packages'] = $composed['packages'];
			$ctx['areas']    = $composed['areas'];
		} else {
			$ctx['location'] = $composed['location'];
		}
		return DoughBoss_Growth_Landing_Schema::build( $ctx );
	}

	/**
	 * Filter callback for document_title_parts: one full title on a companion page, nothing elsewhere.
	 *
	 * @param mixed $parts Title parts.
	 * @return mixed
	 */
	public static function filter_title_parts( $parts ) {
		if ( ! is_array( $parts ) || ! self::head_enabled() || self::seo_plugin_active() ) {
			return $parts;
		}
		$key = DoughBoss_Growth_Landing::current_key();
		if ( '' === $key ) {
			return $parts;
		}
		$meta = self::metadata( $key );
		if ( null === $meta ) {
			return $parts;
		}
		$parts['title'] = $meta['title'];
		unset( $parts['tagline'], $parts['site'] );
		return $parts;
	}

	/**
	 * wp_head callback (priority 6): description, Open Graph, Twitter and the JSON-LD graph.
	 *
	 * @return void
	 */
	public static function print_head() {
		if ( ! self::head_enabled() ) {
			return;
		}
		$plugin = self::seo_plugin_active();
		if ( $plugin && ! self::jsonld_with_seo_plugin() ) {
			return;
		}
		$key = DoughBoss_Growth_Landing::current_key();
		if ( '' === $key ) {
			return;
		}
		$meta = self::metadata( $key );
		if ( null === $meta ) {
			return;
		}
		if ( ! $plugin ) {
			echo self::meta_tags( $meta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside meta_tags().
		}
		$graph = self::graph( $key );
		if ( null !== $graph ) {
			$json = DoughBoss_Growth_Landing_Schema::encode( $graph );
			if ( '' !== $json ) {
				echo '<script type="application/ld+json">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with tag, ampersand and quote characters hex escaped.
			}
		}
	}

	/**
	 * The meta tag block for a page.
	 *
	 * @param array $meta Output of metadata().
	 * @return string
	 */
	public static function meta_tags( array $meta ) {
		$out  = '<meta name="description" content="' . esc_attr( $meta['description'] ) . '" />' . "\n";
		$out .= '<meta property="og:type" content="website" />' . "\n";
		$out .= '<meta property="og:locale" content="en_AU" />' . "\n";
		$out .= '<meta property="og:site_name" content="' . esc_attr( $meta['site_name'] ) . '" />' . "\n";
		$out .= '<meta property="og:title" content="' . esc_attr( $meta['title'] ) . '" />' . "\n";
		$out .= '<meta property="og:description" content="' . esc_attr( $meta['description'] ) . '" />' . "\n";
		$out .= '<meta property="og:url" content="' . esc_url( $meta['url'] ) . '" />' . "\n";
		if ( '' !== $meta['image'] ) {
			$out .= '<meta property="og:image" content="' . esc_url( $meta['image'] ) . '" />' . "\n";
			$out .= '<meta property="og:image:width" content="1200" />' . "\n";
			$out .= '<meta property="og:image:height" content="630" />' . "\n";
		}
		$out .= '<meta name="twitter:card" content="' . ( '' !== $meta['image'] ? 'summary_large_image' : 'summary' ) . '" />' . "\n";
		$out .= '<meta name="twitter:title" content="' . esc_attr( $meta['title'] ) . '" />' . "\n";
		$out .= '<meta name="twitter:description" content="' . esc_attr( $meta['description'] ) . '" />' . "\n";
		if ( '' !== $meta['image'] ) {
			$out .= '<meta name="twitter:image" content="' . esc_url( $meta['image'] ) . '" />' . "\n";
		}
		return $out;
	}

	/**
	 * Filter callback for wp_robots: a landing page with no content of its own, or one that cannot render, is
	 * marked noindex. Adds nothing elsewhere and never when an SEO plugin owns the head.
	 *
	 * @param mixed $robots Robots directives.
	 * @return mixed
	 */
	public static function filter_robots( $robots ) {
		if ( ! is_array( $robots ) || ! self::head_enabled() || self::seo_plugin_active() ) {
			return $robots;
		}
		$key = DoughBoss_Growth_Landing::current_key();
		if ( '' === $key ) {
			return $robots;
		}
		if ( ! DoughBoss_Growth_Landing::is_indexable( $key ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		return $robots;
	}

	/**
	 * Length in characters.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}
}
