<?php
/**
 * Shortcodes: story, band, strip, badge, divider.
 *
 * @package DoughBoss_Growth_Box
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode renderers. A shortcode prints nothing for a visitor while its switch is off; an administrator sees a
 * preview with a short note, so the draft page can be checked before anything goes live.
 */
final class DoughBoss_Growth_Box_Shortcodes {

	/**
	 * Shortcode tag => switch key.
	 *
	 * @var array<string, string>
	 */
	private static $tags = array(
		'doughboss_growth_box_story'   => 'story',
		'doughboss_growth_box_band'    => 'band',
		'doughboss_growth_box_strip'   => 'strip',
		'doughboss_growth_box_badge'   => 'strip',
		'doughboss_growth_box_divider' => 'strip',
	);

	/**
	 * The slots the story page can show (a story page is noindex until all of them are real photographs).
	 *
	 * @return array<int, string>
	 */
	public static function story_slots() {
		return array( 'closed_art', 'closed', 'seal_art', 'seal_macro' );
	}

	/**
	 * The shortcode tags.
	 *
	 * @return array<int, string>
	 */
	public static function tags() {
		return array_keys( self::$tags );
	}

	/**
	 * Register the shortcodes.
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( 'doughboss_growth_box_story', array( __CLASS__, 'story' ) );
		add_shortcode( 'doughboss_growth_box_band', array( __CLASS__, 'band' ) );
		add_shortcode( 'doughboss_growth_box_strip', array( __CLASS__, 'strip' ) );
		add_shortcode( 'doughboss_growth_box_badge', array( __CLASS__, 'badge' ) );
		add_shortcode( 'doughboss_growth_box_divider', array( __CLASS__, 'divider' ) );
	}

	/**
	 * Wrap output for a switch. Returns an empty string when the visitor may not see it. For an administrator whose
	 * switch is off, a short operator note is added in front.
	 *
	 * @param string $switch  Switch key.
	 * @param string $html    The markup.
	 * @return string
	 */
	private static function gate( $switch, $html ) {
		if ( '' === $html || ! DoughBoss_Growth_Box_Settings::can_render( $switch ) ) {
			return '';
		}
		$note = '';
		if ( ! DoughBoss_Growth_Box_Settings::on( $switch ) ) {
			$switches = DoughBoss_Growth_Box_Settings::switches();
			$label    = isset( $switches[ $switch ][0] ) ? $switches[ $switch ][0] : $switch;
			/* translators: %s: switch name. */
			$note = '<p class="dbgr-admin-note">' . esc_html( sprintf( __( 'Administrator preview. The "%s" switch is off, so visitors see nothing here.', 'doughboss-growth-box' ), $label ) ) . '</p>';
		}
		DoughBoss_Growth_Box_Render::enqueue_css();
		return $note . $html . DoughBoss_Growth_Box_Render::refusal_comments();
	}

	/**
	 * The liner pattern as an inline custom property, or an empty string when the tile is missing.
	 *
	 * @return string
	 */
	public static function liner_style() {
		$url = DoughBoss_Growth_Box_Manifest::file_url( 'liner_tile', 'png' );
		if ( '' === $url ) {
			return '';
		}
		return ' style="--dbgr-liner:url(\'' . esc_url( $url ) . '\')"';
	}

	/**
	 * [doughboss_growth_box_story]. Attribute sections="intro,closed,seal,order,info" drops sections.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 * @return string
	 */
	public static function story( $atts = array() ) {
		$atts     = shortcode_atts(
			array(
				'sections' => 'intro,closed,seal,order,info',
			),
			is_array( $atts ) ? $atts : array(),
			'doughboss_growth_box_story'
		);
		$wanted   = array_filter( array_map( 'sanitize_key', explode( ',', (string) $atts['sections'] ) ) );
		$sections = array();
		$divider  = '<div class="dbgr-divider" role="presentation"' . self::liner_style() . '></div>';

		// [CONFIRM: "FRESH FROM THE OVEN." and "OVEN-BAKED, BAKED TO ORDER" are Tier B (marketing.md), so the inside-lid artwork is left out of Phase 1.]
		// [CONFIRM: live step bodies, because step 2 says "minis". Only the three step titles are used.]
		// [CONFIRM: "A CONTEMPORARY LEBANESE BAKERY." is listed for the back wall, about page and apron only, so it is not used here.]

		if ( in_array( 'intro', $wanted, true ) ) {
			$art   = DoughBoss_Growth_Box_Render::figure(
				'closed_art',
				array(
					'sizes'    => '(max-width: 720px) 92vw, 560px',
					'priority' => true,
					'class'    => 'dbgr-fig--wide',
					'captions' => array( 'caption_artwork' ),
				)
			);
			$lead  = DoughBoss_Growth_Box_Render::copy( 'tagline' );
			$intro = '';
			if ( '' !== $lead ) {
				$intro .= '<h2 class="dbgr-h dbgr-h--lead">' . esc_html( $lead ) . '</h2>';
			}
			$intro .= $art;
			if ( '' !== $intro ) {
				$sections[] = '<section class="dbgr-sec dbgr-sec--intro">' . $intro . '</section>';
			}
		}

		if ( in_array( 'closed', $wanted, true ) ) {
			$fig = DoughBoss_Growth_Box_Render::figure(
				'closed',
				array(
					'sizes'    => '(max-width: 720px) 92vw, 460px',
					'priority' => true,
					'class'    => 'dbgr-fig--portrait',
					'captions' => array( 'caption_render', 'caption_sample' ),
				)
			);
			if ( '' !== $fig ) {
				$sections[] = $divider;
				$sections[] = '<section class="dbgr-sec dbgr-sec--closed">' . $fig . '</section>';
			}
		}

		if ( in_array( 'seal', $wanted, true ) ) {
			$seal  = DoughBoss_Growth_Box_Render::figure(
				'seal_art',
				array(
					'sizes'    => '(max-width: 720px) 60vw, 300px',
					'class'    => 'dbgr-fig--seal',
					'captions' => array( 'caption_artwork' ),
				)
			);
			$macro = DoughBoss_Growth_Box_Render::figure(
				'seal_macro',
				array(
					'sizes'    => '(max-width: 720px) 92vw, 460px',
					'class'    => 'dbgr-fig--macro',
					'captions' => array( 'caption_render', 'caption_sample' ),
				)
			);
			if ( '' !== $seal . $macro ) {
				$sections[] = '<section class="dbgr-sec dbgr-sec--seal"><div class="dbgr-pair">' . $seal . $macro . '</div></section>';
			}
		}

		if ( in_array( 'order', $wanted, true ) ) {
			$order = '';
			$plan  = DoughBoss_Growth_Box_Render::copy( 'plan_line' );
			if ( '' !== $plan ) {
				$order .= '<p class="dbgr-lead">' . esc_html( $plan ) . '</p>';
			}
			$steps = '';
			foreach ( array( 'step_1', 'step_2', 'step_3' ) as $step ) {
				$text = DoughBoss_Growth_Box_Render::copy( $step );
				if ( '' !== $text ) {
					$steps .= '<li>' . esc_html( $text ) . '</li>';
				}
			}
			if ( '' !== $steps ) {
				$order .= '<ol class="dbgr-steps">' . $steps . '</ol>';
			}
			$actions = '';
			$cta     = DoughBoss_Growth_Box_Render::copy( 'cta_plan' );
			if ( '' !== $cta ) {
				$actions .= '<a class="dbgr-btn" href="' . esc_url( DoughBoss_Growth_Box_Render::enquiry_url() ) . '">' . esc_html( $cta ) . '</a>';
			}
			$email = DoughBoss_Growth_Box_Render::copy( 'email' );
			if ( '' !== $email && is_email( $email ) ) {
				$actions .= '<a class="dbgr-link" href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
			}
			if ( '' !== $actions ) {
				$order .= '<div class="dbgr-actions">' . $actions . '</div>';
			}
			if ( '' !== $order ) {
				$sections[] = $divider;
				$sections[] = '<section class="dbgr-sec dbgr-sec--order">' . $order . '</section>';
			}
		}

		if ( in_array( 'info', $wanted, true ) ) {
			$info = '';
			foreach ( array( 'shops', 'allergen' ) as $line ) {
				$text = DoughBoss_Growth_Box_Render::copy( $line );
				if ( '' !== $text ) {
					$info .= '<p class="dbgr-fine dbgr-fine--' . esc_attr( $line ) . '">' . esc_html( $text ) . '</p>';
				}
			}
			if ( '' !== $info ) {
				$sections[] = '<section class="dbgr-sec dbgr-sec--info">' . $info . '</section>';
			}
		}

		if ( empty( $sections ) ) {
			return '';
		}
		return self::gate( 'story', '<div class="dbgr-story">' . implode( '', $sections ) . '</div>' );
	}

	/**
	 * The band markup for a surface, with no switch check (the caller decides). Includes the concept picture only
	 * when the "Concept image in the bands" switch is on.
	 *
	 * @param string $surface home or catering.
	 * @param bool   $high    Whether the image may take fetchpriority="high".
	 * @return string
	 */
	public static function band_html( $surface, $high = false ) {
		$surface = ( 'catering' === $surface ) ? 'catering' : 'home';
		$head    = DoughBoss_Growth_Box_Render::headline_html( 'headline' );
		if ( '' === $head ) {
			return '';
		}
		$figure = '';
		if ( DoughBoss_Growth_Box_Settings::on( 'band_image' ) ) {
			$figure = DoughBoss_Growth_Box_Render::figure(
				'closed_band',
				array(
					'sizes'    => '(max-width: 900px) 92vw, 460px',
					'priority' => $high,
					'class'    => 'dbgr-fig--band',
					'captions' => array( 'caption_render' ),
				)
			);
		}
		$id   = 'dbgr-band-h-' . $surface;
		$html = '<section class="dbgr-band dbgr-band--' . esc_attr( $surface ) . '" aria-labelledby="' . esc_attr( $id ) . '"><div class="dbgr-band-in' . ( '' !== $figure ? ' dbgr-band-in--img' : '' ) . '"><div class="dbgr-band-copy">';
		$html .= '<h2 class="dbgr-h" id="' . esc_attr( $id ) . '">' . $head . '</h2>';
		$lead  = DoughBoss_Growth_Box_Render::copy( 'tagline' );
		if ( '' !== $lead ) {
			$html .= '<p class="dbgr-lead">' . esc_html( $lead ) . '</p>';
		}
		$actions = '';
		$cta     = DoughBoss_Growth_Box_Render::copy( 'cta_plan' );
		if ( '' !== $cta ) {
			$plan_url = ( 'catering' === $surface ) ? '#catering-enquiry' : DoughBoss_Growth_Box_Render::catering_url();
			$actions .= '<a class="dbgr-btn" href="' . esc_url( $plan_url ) . '">' . esc_html( $cta ) . '</a>';
		}
		$concept = DoughBoss_Growth_Box_Render::copy( 'cta_concept' );
		$story   = DoughBoss_Growth_Box_Render::story_url();
		if ( '' !== $concept && '' !== $story ) {
			$actions .= '<a class="dbgr-btn dbgr-btn--ghost" href="' . esc_url( $story ) . '">' . esc_html( $concept ) . '</a>';
		}
		if ( '' !== $actions ) {
			$html .= '<div class="dbgr-actions">' . $actions . '</div>';
		}
		$html .= '</div>' . $figure . '</div></section>';
		return $html;
	}

	/**
	 * [doughboss_growth_box_band surface="home|catering" priority="1"].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 * @return string
	 */
	public static function band( $atts = array() ) {
		$atts    = shortcode_atts(
			array(
				'surface'  => 'home',
				'priority' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'doughboss_growth_box_band'
		);
		$surface = ( 'catering' === $atts['surface'] ) ? 'catering' : 'home';
		$key     = ( 'catering' === $surface ) ? 'catering_band' : 'home_band';
		$html    = self::band_html( $surface, '1' === (string) $atts['priority'] );
		return self::gate( $key, $html );
	}

	/**
	 * The strip markup (no switch check, no relocation script).
	 *
	 * @return string
	 */
	public static function strip_html() {
		$head = DoughBoss_Growth_Box_Render::headline_html( 'headline' );
		if ( '' === $head ) {
			return '';
		}
		$html = '<aside class="dbgr-strip" data-dbgr-strip aria-label="' . esc_attr( DoughBoss_Growth_Box_Render::copy( 'headline' ) ) . '"' . self::liner_style() . '><div class="dbgr-strip-in">';
		$html .= '<p class="dbgr-strip-head">' . $head . '</p>';
		$cta   = DoughBoss_Growth_Box_Render::copy( 'cta_plan' );
		if ( '' !== $cta ) {
			$html .= '<a class="dbgr-btn" href="' . esc_url( DoughBoss_Growth_Box_Render::catering_url() ) . '">' . esc_html( $cta ) . '</a>';
		}
		return $html . '</div></aside>';
	}

	/**
	 * [doughboss_growth_box_strip]. A manual strip inside the page. The automatic site-wide strip is skipped on a
	 * page that already holds one.
	 *
	 * @return string
	 */
	public static function strip() {
		$html = self::strip_html();
		if ( '' === $html || ! DoughBoss_Growth_Box_Settings::can_render( 'strip' ) ) {
			return '';
		}
		DoughBoss_Growth_Box_Render::mark_strip_printed();
		return self::gate( 'strip', '<style id="dbgr-strip-css">' . DoughBoss_Growth_Box_Render::strip_css() . '</style>' . $html );
	}

	/**
	 * [doughboss_growth_box_badge href=""]. The kraft seal badge with a "Plan catering" label.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 * @return string
	 */
	public static function badge( $atts = array() ) {
		$atts     = shortcode_atts(
			array(
				'href' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'doughboss_growth_box_badge'
		);
		$label    = DoughBoss_Growth_Box_Render::copy( 'cta_plan' );
		$resolved = DoughBoss_Growth_Box_Manifest::resolve( 'seal_badge' );
		if ( '' === $label || DoughBoss_Growth_Box_Manifest::OK !== $resolved['state'] ) {
			DoughBoss_Growth_Box_Render::figure( 'seal_badge' ); // Records why, for the admin comment.
			return self::gate( 'strip', '' );
		}
		$href = ( '' !== (string) $atts['href'] ) ? (string) $atts['href'] : DoughBoss_Growth_Box_Render::catering_url();
		$html = '<a class="dbgr-badge" href="' . esc_url( $href ) . '">'
			. DoughBoss_Growth_Box_Render::picture( $resolved['picture'], '112px', false, 'dbgr-badge-img' )
			. '<span class="dbgr-badge-text">' . esc_html( $label ) . '</span></a>';
		return self::gate( 'strip', $html );
	}

	/**
	 * [doughboss_growth_box_divider]. A band of the liner pattern.
	 *
	 * @return string
	 */
	public static function divider() {
		$style = self::liner_style();
		if ( '' === $style ) {
			return '';
		}
		return self::gate( 'strip', '<div class="dbgr-divider" role="presentation"' . $style . '></div>' );
	}
}
