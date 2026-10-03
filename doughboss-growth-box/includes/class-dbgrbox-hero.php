<?php
/**
 * The looping hero video on the HOME page hero only.
 *
 * Owner decision, 3 October 2026 (Elie, in so many words: "Add the video to the website hero"). It supersedes the
 * 2 October "keep the photo hero" ruling for the home hero video ONLY. The catering hero, every other page and every
 * other part of this plugin are unchanged.
 *
 * What it does, and only while ALL of these hold: the "Home hero video" switch is on, the kill switch is not set, the
 * request is the front page, the 1080 WebP poster and at least one video were found (see
 * DoughBoss_Growth_Box_Hero_Media), and the shortcode is [doughboss_manoush_hero variant="home"]:
 *
 * 1. shortcode_atts_doughboss_manoush_hero sets the core attribute background_image to the 1080 WebP poster, so the
 *    poster (first frame of the video) is what paints first and is the LCP image;
 * 2. wp_head prints a high-priority preload of that poster;
 * 3. do_shortcode_tag edits the core markup: adds the class has-dbgr-video to the section, puts an inert <video> INSIDE
 *    the .db-mh-backdrop (so core's scroll parallax moves the video with the poster), removes the steam element
 *    (owner rule: no steam or smoke) and adds the "Concept preview" label when its setting is on;
 * 4. a small stylesheet and a deferred script are loaded. The script starts the video after the window load event and
 *    only on a capable connection; until then, and for ever on a slow or data-saving connection or with reduced motion,
 *    the poster stays.
 *
 * No <source> tags: the video file addresses sit in data attributes, so nothing downloads before the script decides.
 *
 * HONESTY (deliberate, owner-approved):
 * - The video and poster show AI-generated food. That is a concept, not a photograph of a product. The visible
 *   "Concept preview" label (default ON) carries the honesty.
 * - This hero does NOT make the home page noindex. The 0.1.0 rule "a page that prints a concept image is noindex" still
 *   applies to the box images, but the owner will not noindex the home page, so this feature is deliberately not added
 *   to DoughBoss_Growth_Box_Inject::page_has_concept(). If the "Concept image in the bands" switch is also on, that
 *   existing rule still noindexes the home page (see INSTALL.md).
 *
 * Fails closed: if the core markup is not what this expects (a newer core draws the hero backdrop as an <img>, for
 * example), the output is returned as core made it, with the poster address put back to the original, so a core update
 * can never break the hero or leave an unlabelled concept picture on the page. The photo hero then simply stays.
 *
 * @package DoughBoss_Growth_Box
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hero video hooks.
 */
final class DoughBoss_Growth_Box_Hero {

	/**
	 * Handles for the stylesheet and the script.
	 */
	const HANDLE = 'dbgr-hero';

	/**
	 * Core's shortcode tag.
	 */
	const TAG = 'doughboss_manoush_hero';

	/**
	 * The background address core was given before this class replaced it, kept so a failed edit can put it back.
	 *
	 * @var string
	 */
	private static $original_background = '';

	/**
	 * The poster address this class put into the shortcode attributes for the shortcode now being rendered; empty when
	 * no replacement is waiting for its markup edit.
	 *
	 * @var string
	 */
	private static $pending_poster = '';

	/**
	 * True while core's shortcode is being run a second time without this class's attribute change (the fail-closed path).
	 *
	 * @var bool
	 */
	private static $bypass = false;

	/**
	 * Register the hooks (called only when the kill switch is not set).
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'shortcode_atts_' . self::TAG, array( __CLASS__, 'atts' ), 20, 4 );
		add_action( 'wp_head', array( __CLASS__, 'print_preload' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		// Before the band filter (priority 20), so the band is added after our edit and never inside it.
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'filter_output' ), 15, 4 );
	}

	/**
	 * Whether the hero video is live on this request: the switch, the front page and the media, and no earlier
	 * request found the core markup in a shape this class does not recognise.
	 *
	 * @return bool
	 */
	public static function active() {
		if ( is_admin() || ! DoughBoss_Growth_Box_Settings::on( 'home_hero_video' ) ) {
			return false;
		}
		if ( ! is_front_page() || is_feed() || is_embed() ) {
			return false;
		}
		return DoughBoss_Growth_Box_Hero_Media::ready();
	}

	/**
	 * The poster address for this request, or an empty string.
	 *
	 * @return string
	 */
	private static function poster_url() {
		$found = DoughBoss_Growth_Box_Hero_Media::find();
		return isset( $found['posters']['webp-1080']['url'] ) ? (string) $found['posters']['webp-1080']['url'] : '';
	}

	/**
	 * Step 1: set core's background_image attribute to the poster for the home variant.
	 *
	 * The attribute name was checked against core: [doughboss_manoush_hero] declares background_image in its
	 * shortcode_atts() defaults and passes the shortcode tag as the third argument, which is what makes this filter fire.
	 *
	 * @param mixed $out       Merged attributes.
	 * @param mixed $pairs     Defaults (unused).
	 * @param mixed $atts      Attributes as written (unused).
	 * @param mixed $shortcode Shortcode tag (unused).
	 * @return mixed
	 */
	public static function atts( $out, $pairs = array(), $atts = array(), $shortcode = '' ) {
		unset( $pairs, $atts, $shortcode );
		if ( self::$bypass || ! is_array( $out ) || ! isset( $out['variant'] ) || 'home' !== $out['variant'] || ! self::active() ) {
			return $out;
		}
		$poster = self::poster_url();
		if ( '' === $poster ) {
			return $out;
		}
		self::$original_background = isset( $out['background_image'] ) ? (string) $out['background_image'] : '';
		self::$pending_poster      = $poster;
		$out['background_image']   = $poster;
		return $out;
	}

	/**
	 * Step 2: preload the poster. It is a CSS background, so without this the browser finds it only after the
	 * stylesheet. Printed only on the front page while the feature is live.
	 *
	 * @return void
	 */
	public static function print_preload() {
		if ( ! self::active() ) {
			return;
		}
		$poster = self::poster_url();
		if ( '' === $poster ) {
			return;
		}
		echo '<link rel="preload" as="image" href="' . esc_url( $poster ) . '" fetchpriority="high" type="image/webp">' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the address is escaped with esc_url().
	}

	/**
	 * Load the small stylesheet and the deferred script, only while the feature is live.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::active() ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, DBGRBOX_URL . 'public/css/dbgr-hero.css', array(), DBGRBOX_VERSION );
		wp_enqueue_script(
			self::HANDLE,
			DBGRBOX_URL . 'public/js/dbgr-hero.js',
			array(),
			DBGRBOX_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Step 3: edit the core markup.
	 *
	 * @param mixed $output Shortcode output.
	 * @param mixed $tag    Shortcode tag.
	 * @param mixed $attr   Shortcode attributes as written.
	 * @param mixed $m      Regex match (element 5 is the enclosed content, none here).
	 * @return mixed
	 */
	public static function filter_output( $output, $tag = '', $attr = array(), $m = array() ) {
		if ( self::$bypass || self::TAG !== $tag || ! is_string( $output ) || '' === self::$pending_poster ) {
			return $output;
		}
		$poster                    = self::$pending_poster;
		$original                  = self::$original_background;
		self::$pending_poster      = '';
		self::$original_background = '';
		$content = ( is_array( $m ) && isset( $m[5] ) ) ? (string) $m[5] : '';
		if ( ! is_array( $attr ) || ! isset( $attr['variant'] ) || 'home' !== $attr['variant'] ) {
			return self::original( $tag, $attr, $content, $output, $poster, $original );
		}
		try {
			$found = DoughBoss_Growth_Box_Hero_Media::find();
			$chip  = '';
			if ( DoughBoss_Growth_Box_Settings::on( 'hero_chip' ) ) {
				$text = DoughBoss_Growth_Box_Render::copy( 'chip' );
				$chip = '<span class="dbgr-hero-chip">' . esc_html( '' === $text ? 'Concept preview' : $text ) . '</span>';
			}
			$edited = self::transform( $output, self::video_html( $found, $poster ), $chip );
			if ( null !== $edited ) {
				return $edited;
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		// Fail closed: hand back core's output untouched, and remember it so the next requests skip the whole feature
		// (no preload, no stylesheet, no script) and the settings screen can say why.
		DoughBoss_Growth_Box_Hero_Media::mark_markup_failed();
		return self::original( $tag, $attr, $content, $output, $poster, $original );
	}

	/**
	 * Core's own output for this shortcode, as if this class had never touched it. Core's callback is run again with the
	 * attribute change switched off; if that cannot be done, the poster address in the output is put back instead.
	 *
	 * @param string $tag      Shortcode tag.
	 * @param mixed  $attr     Attributes as written.
	 * @param string $content  Enclosed content (none for this shortcode).
	 * @param string $output   Output made with the poster in it.
	 * @param string $poster   The poster address that was injected.
	 * @param string $original The address core was given.
	 * @return string
	 */
	private static function original( $tag, $attr, $content, $output, $poster, $original ) {
		global $shortcode_tags;
		if ( isset( $shortcode_tags[ $tag ] ) && is_callable( $shortcode_tags[ $tag ] ) ) {
			self::$bypass = true;
			try {
				$again = call_user_func( $shortcode_tags[ $tag ], $attr, $content, $tag );
			} catch ( Throwable $e ) {
				$again = null;
			}
			self::$bypass = false;
			if ( is_string( $again ) && '' !== $again ) {
				return $again;
			}
		}
		return self::revert( $output, $poster, $original );
	}

	/**
	 * Put the original background address back into output that this class could not edit, so the page is exactly what
	 * core made without us. Both addresses are escaped the way core escapes them (esc_url).
	 *
	 * @param string $output   Core's output.
	 * @param string $poster   The poster address that was injected.
	 * @param string $original The address core was given.
	 * @return string
	 */
	private static function revert( $output, $poster, $original ) {
		if ( '' === $original || '' === $poster ) {
			return $output;
		}
		return str_replace( esc_url( $poster ), esc_url( $original ), $output );
	}

	/**
	 * The inert video element. Every address is in a data attribute: there is no <source>, so nothing downloads until
	 * the script has decided.
	 *
	 * @param array<string, mixed> $found  Result of DoughBoss_Growth_Box_Hero_Media::find().
	 * @param string               $poster Poster address.
	 * @return string
	 */
	public static function video_html( array $found, $poster ) {
		$data = '';
		foreach ( DoughBoss_Growth_Box_Hero_Media::video_stems() as $key => $stem ) {
			if ( ! empty( $found['videos'][ $key ]['url'] ) ) {
				$data .= ' data-' . $key . '="' . esc_url( $found['videos'][ $key ]['url'] ) . '"';
			}
		}
		return '<video class="dbgr-hero-video" muted playsinline loop preload="none" disablepictureinpicture disableremoteplayback aria-hidden="true" tabindex="-1" poster="' . esc_url( $poster ) . '"' . $data . '></video>';
	}

	/**
	 * Edit the core hero markup. Pure string work, no WordPress calls, so it can be tested on its own.
	 *
	 * Inside the first <section class="db-manoush-hero ..."> only:
	 * - adds has-dbgr-video to that section;
	 * - puts $video_html inside the EMPTY <div class="db-mh-backdrop" ...></div> (the live markup draws the poster as a
	 *   CSS background, so the div is empty; a backdrop drawn as an <img>, or with anything in it, is not recognised);
	 * - removes the .db-mh-steam element (strict: only that element and its empty spans);
	 * - adds $chip_html (the label, already escaped, or an empty string for no label) straight after the backdrop.
	 *
	 * @param string $html       Core's output.
	 * @param string $video_html The video element.
	 * @param string $chip_html  The label markup, or an empty string.
	 * @return string|null The edited output, or null when the markup is not found (the caller then fails closed).
	 */
	public static function transform( $html, $video_html, $chip_html ) {
		if ( ! is_string( $html ) || '' === $html || '' === $video_html ) {
			return null;
		}
		$open = '/<section\b(?=[^>]*\sclass="(?:[^"]*\s)?db-manoush-hero(?:\s[^"]*)?")[^>]*>/';
		if ( 1 !== preg_match( $open, $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$start = (int) $m[0][1];
		$tag   = (string) $m[0][0];
		$end   = strpos( $html, '</section>', $start + strlen( $tag ) );
		if ( false === $end || false !== strpos( substr( $html, $start + strlen( $tag ), $end - $start - strlen( $tag ) ), '<section' ) ) {
			return null; // No clean single section to work inside.
		}
		$end += strlen( '</section>' );
		if ( false !== strpos( substr( $html, $start, $end - $start ), 'dbgr-hero-video' ) || false !== strpos( $tag, 'has-dbgr-video' ) ) {
			return null; // Already edited: leave it alone.
		}

		$new_tag = preg_replace( '/(\sclass=")([^"]*)(")/', '$1$2 has-dbgr-video$3', $tag, 1, $n_class );
		if ( 1 !== $n_class || ! is_string( $new_tag ) ) {
			return null;
		}
		$inner = substr( $html, $start + strlen( $tag ), $end - $start - strlen( $tag ) );

		$backdrop = '/(<div\b[^>]*\sclass="(?:[^"]*\s)?db-mh-backdrop(?:\s[^"]*)?"[^>]*>)(\s*)(<\/div>)/';
		if ( 1 !== preg_match_all( $backdrop, $inner ) ) {
			return null; // None, more than one, or a backdrop with something in it.
		}
		$inner = preg_replace_callback(
			$backdrop,
			static function ( $b ) use ( $video_html, $chip_html ) {
				return $b[1] . $b[2] . $video_html . $b[3] . $chip_html;
			},
			$inner,
			1,
			$n_back
		);
		if ( 1 !== $n_back || ! is_string( $inner ) ) {
			return null;
		}

		// Owner rule: no steam or smoke. Strict on purpose; the stylesheet also hides it.
		$steam = '/<div\b[^>]*\sclass="(?:[^"]*\s)?db-mh-steam(?:\s[^"]*)?"[^>]*>(?:\s*<span\b[^>]*>\s*<\/span>)*\s*<\/div>/';
		$inner = preg_replace( $steam, '', $inner, 1 );
		if ( ! is_string( $inner ) ) {
			return null;
		}

		return substr( $html, 0, $start ) . $new_tag . $inner . substr( $html, $end );
	}
}
