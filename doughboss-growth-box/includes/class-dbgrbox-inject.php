<?php
/**
 * Hooks that act on other pages: the bands under the heroes, the site-wide strip, the stylesheet, noindex and the
 * sitemap exclusion. None of this runs while the kill switch is set, and each part runs only while its switch is on.
 *
 * @package DoughBoss_Growth_Box
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injection hooks.
 */
final class DoughBoss_Growth_Box_Inject {

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_for_post' ), 20 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 20 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_args' ), 10, 2 );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'filter_hero' ), 20, 4 );
		add_action( 'wp_footer', array( __CLASS__, 'print_strip' ), 5 );
		// The home hero video. It is NOT part of page_has_concept() on purpose: the owner decided (3 October 2026) that the
		// visible "Concept preview" label carries the honesty and that the home page is not noindexed. See class-dbgrbox-hero.php.
		DoughBoss_Growth_Box_Hero::register();
	}

	/**
	 * Which hero surface the current request is, when its band switch is on: "home", "catering" or an empty string.
	 *
	 * @return string
	 */
	public static function auto_band_surface() {
		if ( is_front_page() && DoughBoss_Growth_Box_Settings::on( 'home_band' ) ) {
			return 'home';
		}
		if ( is_page( 'catering' ) && DoughBoss_Growth_Box_Settings::on( 'catering_band' ) ) {
			return 'catering';
		}
		return '';
	}

	/**
	 * Whether the current singular post holds one of this plugin's shortcodes.
	 *
	 * @param array<int, string> $tags Shortcode tags; empty means any of ours.
	 * @return bool
	 */
	private static function post_has( array $tags = array() ) {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) ) {
			return false;
		}
		$tags = empty( $tags ) ? DoughBoss_Growth_Box_Shortcodes::tags() : $tags;
		foreach ( $tags as $tag ) {
			if ( has_shortcode( (string) $post->post_content, $tag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Load the stylesheet only on a page that will print one of the styled blocks.
	 *
	 * The strip uses its own inline style, so a page that only gets the site-wide strip loads no stylesheet.
	 *
	 * @return void
	 */
	public static function enqueue_for_post() {
		if ( is_admin() ) {
			return;
		}
		if ( self::post_has() || '' !== self::auto_band_surface() ) {
			DoughBoss_Growth_Box_Render::enqueue_css();
		}
	}

	/**
	 * Whether this request will print a concept image (or, for the story page, might).
	 *
	 * The story page fails closed: it is noindex unless every slot it can show is a real photograph. A band is noindex
	 * only when it will actually print a concept image.
	 *
	 * @return bool
	 */
	public static function page_has_concept() {
		if ( is_admin() ) {
			return false;
		}
		if ( self::post_has( array( 'doughboss_growth_box_story' ) ) && ! DoughBoss_Growth_Box_Manifest::all_real( DoughBoss_Growth_Box_Shortcodes::story_slots() ) ) {
			return true;
		}
		$band = DoughBoss_Growth_Box_Settings::on( 'band_image' ) && DoughBoss_Growth_Box_Manifest::renders_concept( 'closed_band' );
		if ( $band && ( '' !== self::auto_band_surface() || self::post_has( array( 'doughboss_growth_box_band' ) ) ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Add noindex to a page that shows a concept image.
	 *
	 * @param array<string, mixed> $robots Robots directives.
	 * @return array<string, mixed>
	 */
	public static function robots( $robots ) {
		if ( ! is_array( $robots ) ) {
			return $robots;
		}
		if ( self::page_has_concept() ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	/**
	 * Page ids kept out of the core XML sitemap while they show a concept image.
	 *
	 * Note: when the site shows its latest posts on the front, core adds the home URL to the sitemap itself and offers
	 * no way to remove it. The home page is still sent with noindex, which search engines honour over the sitemap.
	 *
	 * @return array<int, int>
	 */
	public static function sitemap_excluded_ids() {
		$ids     = array();
		$story   = (int) get_option( DoughBoss_Growth_Box_Settings::PAGE_OPTION, 0 );
		$stories = DoughBoss_Growth_Box_Shortcodes::story_slots();
		if ( $story > 0 && ! DoughBoss_Growth_Box_Manifest::all_real( $stories ) ) {
			$ids[] = $story;
		}
		if ( DoughBoss_Growth_Box_Settings::on( 'band_image' ) && DoughBoss_Growth_Box_Manifest::renders_concept( 'closed_band' ) ) {
			if ( DoughBoss_Growth_Box_Settings::on( 'home_band' ) ) {
				$front = (int) get_option( 'page_on_front', 0 );
				if ( $front > 0 ) {
					$ids[] = $front;
				}
			}
			if ( DoughBoss_Growth_Box_Settings::on( 'catering_band' ) ) {
				$catering = get_page_by_path( 'catering' );
				if ( $catering instanceof WP_Post ) {
					$ids[] = (int) $catering->ID;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Keep those pages out of the sitemap.
	 *
	 * @param array<string, mixed> $args      Query args.
	 * @param string               $post_type Post type.
	 * @return array<string, mixed>
	 */
	public static function sitemap_args( $args, $post_type = '' ) {
		if ( 'page' !== $post_type || ! is_array( $args ) ) {
			return $args;
		}
		$ids = self::sitemap_excluded_ids();
		if ( ! empty( $ids ) ) {
			$existing             = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
			$args['post__not_in'] = array_merge( $existing, $ids );
		}
		return $args;
	}

	/**
	 * Add the band after the DoughBoss hero shortcode on the home and catering pages.
	 *
	 * Yields to the DoughBoss Growth coming-soon ribbon on the home page: one strip under the hero at a time.
	 *
	 * @param mixed $output Shortcode output.
	 * @param mixed $tag    Shortcode tag.
	 * @param mixed $attr   Shortcode attributes.
	 * @param mixed $m      Regex match (unused).
	 * @return mixed
	 */
	public static function filter_hero( $output, $tag = '', $attr = array(), $m = array() ) {
		unset( $m );
		if ( 'doughboss_manoush_hero' !== $tag || ! is_string( $output ) || is_admin() ) {
			return $output;
		}
		if ( ! is_array( $attr ) || ! isset( $attr['variant'] ) || ! in_array( $attr['variant'], array( 'home', 'catering' ), true ) ) {
			return $output;
		}
		$surface = (string) $attr['variant'];
		$key     = ( 'catering' === $surface ) ? 'catering_band' : 'home_band';
		if ( ! DoughBoss_Growth_Box_Settings::on( $key ) ) {
			return $output;
		}
		try {
			if ( 'home' === $surface && self::ribbon_active() ) {
				return $output;
			}
			$band = DoughBoss_Growth_Box_Shortcodes::band_html( $surface, false );
			if ( '' === $band ) {
				return $output;
			}
			DoughBoss_Growth_Box_Render::enqueue_css();
			return $output . $band;
		} catch ( Throwable $e ) {
			return $output;
		}
	}

	/**
	 * Whether the DoughBoss Growth coming-soon ribbon is switched on.
	 *
	 * @return bool
	 */
	private static function ribbon_active() {
		return class_exists( 'DoughBoss_Growth_Coming_Soon', false )
			&& is_callable( array( 'DoughBoss_Growth_Coming_Soon', 'enabled' ) )
			&& is_callable( array( 'DoughBoss_Growth_Coming_Soon', 'ribbon_enabled' ) )
			&& DoughBoss_Growth_Coming_Soon::enabled()
			&& DoughBoss_Growth_Coming_Soon::ribbon_enabled();
	}

	/**
	 * Print the site-wide strip with its tiny inline style and the 3-line script that moves it above the footer.
	 * A theme with no footer hook prints it after the footer element otherwise, which is acceptable with JavaScript off.
	 *
	 * @return void
	 */
	public static function print_strip() {
		if ( is_admin() || ! DoughBoss_Growth_Box_Settings::on( 'strip' ) || DoughBoss_Growth_Box_Render::strip_printed() ) {
			return;
		}
		if ( is_feed() || is_embed() || is_404() ) {
			return;
		}
		$story = (int) get_option( DoughBoss_Growth_Box_Settings::PAGE_OPTION, 0 );
		if ( $story > 0 && is_page( $story ) ) {
			return; // The story page has its own headline.
		}
		/**
		 * Filter whether the automatic strip shows on this page.
		 *
		 * @param bool $show Whether to show the strip.
		 */
		if ( ! apply_filters( 'doughboss_growth_box_strip_show', true ) ) {
			return;
		}
		$html = DoughBoss_Growth_Box_Shortcodes::strip_html();
		if ( '' === $html ) {
			return;
		}
		DoughBoss_Growth_Box_Render::mark_strip_printed();
		echo '<style id="dbgr-strip-css">' . DoughBoss_Growth_Box_Render::strip_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static plugin stylesheet, no user data.
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped when built.
		echo '<script>(function(){var s=document.querySelector("[data-dbgr-strip]"),f=document.querySelector(".dbf-footer");if(s&&f&&f.parentNode){f.parentNode.insertBefore(s,f);}})();</script>';
	}
}
