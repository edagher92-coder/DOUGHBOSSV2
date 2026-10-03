<?php
/**
 * Renderer: the <picture> builder, the concept chip, captions and small shared helpers.
 *
 * @package DoughBoss_Growth_Box
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Markup helpers. Every dynamic value is escaped where it is printed.
 */
final class DoughBoss_Growth_Box_Render {

	/**
	 * Stylesheet handle.
	 */
	const STYLE_HANDLE = 'dbgr-box';

	/**
	 * Whether the one fetchpriority="high" image of this request has been used.
	 *
	 * @var bool
	 */
	private static $priority_used = false;

	/**
	 * Slots that were asked for but not printed: id => state.
	 *
	 * @var array<string, string>
	 */
	private static $refused = array();

	/**
	 * Whether the strip has already been printed on this request.
	 *
	 * @var bool
	 */
	private static $strip_printed = false;

	/**
	 * Forget per-request state (tests only).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$priority_used = false;
		self::$refused       = array();
		self::$strip_printed = false;
	}

	/**
	 * Slots refused or missing on this request.
	 *
	 * @return array<string, string>
	 */
	public static function refused() {
		return self::$refused;
	}

	/**
	 * Whether the strip has been printed on this request.
	 *
	 * @return bool
	 */
	public static function strip_printed() {
		return self::$strip_printed;
	}

	/**
	 * Mark the strip as printed.
	 *
	 * @return void
	 */
	public static function mark_strip_printed() {
		self::$strip_printed = true;
	}

	/**
	 * Queue the stylesheet. Normally called early by the enqueue hook when a shortcode is on the page; the shortcodes
	 * call it again so a shortcode run inside a template or widget still gets its styles (WordPress prints a late
	 * style in the footer).
	 *
	 * @return void
	 */
	public static function enqueue_css() {
		wp_enqueue_style( self::STYLE_HANDLE, DBGRBOX_URL . 'public/css/dbgr-box.css', array(), DBGRBOX_VERSION );
	}

	/**
	 * The small inline stylesheet for the strip (no extra request). Comments and whitespace are trimmed.
	 *
	 * @return string
	 */
	public static function strip_css() {
		$path = DBGRBOX_DIR . 'public/css/dbgr-strip.css';
		$raw  = is_readable( $path ) ? file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$raw = preg_replace( '#/\*.*?\*/#s', '', $raw );
		$raw = preg_replace( '/\s+/', ' ', (string) $raw );
		$raw = preg_replace( '/\s*([{};:,>])\s*/', '$1', (string) $raw );
		return trim( (string) $raw );
	}

	/**
	 * The text of a copy line, or the given fallback for the chip only.
	 *
	 * @param string $id Line id.
	 * @return string
	 */
	public static function copy( $id ) {
		return DoughBoss_Growth_Box_Copy::text( $id );
	}

	/**
	 * Headline markup: the line, escaped, with the final full stop in the ember colour.
	 *
	 * @param string $line_id Copy line id.
	 * @return string
	 */
	public static function headline_html( $line_id = 'headline' ) {
		$text = self::copy( $line_id );
		if ( '' === $text ) {
			return '';
		}
		if ( '.' === substr( $text, -1 ) ) {
			return esc_html( substr( $text, 0, -1 ) ) . '<span class="dbgr-dot">.</span>';
		}
		return esc_html( $text );
	}

	/**
	 * The catering page URL.
	 *
	 * @return string
	 */
	public static function catering_url() {
		return home_url( '/catering/' );
	}

	/**
	 * The catering enquiry anchor URL.
	 *
	 * @return string
	 */
	public static function enquiry_url() {
		return home_url( '/catering/#catering-enquiry' );
	}

	/**
	 * The URL of the story page, or an empty string unless it is published.
	 *
	 * @return string
	 */
	public static function story_url() {
		$page_id = (int) get_option( DoughBoss_Growth_Box_Settings::PAGE_OPTION, 0 );
		if ( $page_id < 1 || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}
		$url = get_permalink( $page_id );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Build the <picture> element for a resolved slot.
	 *
	 * @param array<string, mixed> $pic   Picture description from the manifest.
	 * @param string               $sizes The sizes attribute.
	 * @param bool                 $high  Whether this image may take fetchpriority="high".
	 * @param string               $class Class for the img element.
	 * @return string
	 */
	public static function picture( array $pic, $sizes, $high = false, $class = 'dbgr-img' ) {
		$use_high = ( $high && ! self::$priority_used );
		if ( $use_high ) {
			self::$priority_used = true;
		}
		$html = '<picture>';
		foreach ( $pic['sources'] as $source ) {
			$html .= '<source type="' . esc_attr( $source['type'] ) . '" srcset="' . esc_attr( $source['srcset'] ) . '" sizes="' . esc_attr( $sizes ) . '">';
		}
		$html .= '<img class="' . esc_attr( $class ) . '" src="' . esc_url( $pic['src'] ) . '" srcset="' . esc_attr( $pic['srcset'] ) . '" sizes="' . esc_attr( $sizes ) . '"'
			. ' width="' . (int) $pic['width'] . '" height="' . (int) $pic['height'] . '" alt="' . esc_attr( $pic['alt'] ) . '"';
		if ( $use_high ) {
			$html .= ' loading="eager" decoding="async" fetchpriority="high"';
		} else {
			$html .= ' loading="lazy" decoding="async"';
		}
		$html .= '></picture>';
		return $html;
	}

	/**
	 * A figure for one slot: the picture, the "Concept preview" chip when the slot is a concept, and any approved
	 * captions. Returns an empty string (and records why) when the slot is blocked, missing or invalid, so the caller
	 * prints a text-only section.
	 *
	 * @param string               $slot_id Slot id.
	 * @param array<string, mixed> $opts    sizes (string), priority (bool), class (string), captions (array of copy ids).
	 * @return string
	 */
	public static function figure( $slot_id, array $opts = array() ) {
		$resolved = DoughBoss_Growth_Box_Manifest::resolve( $slot_id );
		if ( DoughBoss_Growth_Box_Manifest::OK !== $resolved['state'] ) {
			self::$refused[ $slot_id ] = (string) $resolved['state'];
			return '';
		}
		$opts    = array_merge(
			array(
				'sizes'    => '100vw',
				'priority' => false,
				'class'    => '',
				'captions' => array(),
			),
			$opts
		);
		$concept = ( 'concept' === $resolved['status'] );
		$classes = 'dbgr-fig dbgr-fig--' . sanitize_html_class( '' !== $resolved['kind'] ? $resolved['kind'] : 'image' );
		if ( '' !== $opts['class'] ) {
			$classes .= ' ' . $opts['class'];
		}
		$html = '<figure class="' . esc_attr( $classes ) . '" data-dbgr-slot="' . esc_attr( $slot_id ) . '" data-dbgr-status="' . esc_attr( $resolved['status'] ) . '">';
		$html .= '<div class="dbgr-media">' . self::picture( $resolved['picture'], (string) $opts['sizes'], (bool) $opts['priority'] );
		if ( $concept ) {
			$chip  = self::copy( 'chip' );
			$chip  = ( '' === $chip ) ? 'Concept preview' : $chip;
			$html .= '<span class="dbgr-chip">' . esc_html( $chip ) . '</span>';
		}
		$html .= '</div>';
		$caps  = '';
		foreach ( (array) $opts['captions'] as $caption_id ) {
			$text = self::copy( (string) $caption_id );
			if ( '' !== $text ) {
				$caps .= '<span class="dbgr-cap-line">' . esc_html( $text ) . '</span>';
			}
		}
		if ( '' !== $caps ) {
			$html .= '<figcaption class="dbgr-cap">' . $caps . '</figcaption>';
		}
		return $html . '</figure>';
	}

	/**
	 * HTML comments listing slots that were not printed. Shown to administrators only.
	 *
	 * @return string
	 */
	public static function refusal_comments() {
		if ( empty( self::$refused ) || ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		$html = '';
		foreach ( self::$refused as $slot_id => $state ) {
			$html .= '<!-- DoughBoss Growth Catering Box: slot "' . esc_html( str_replace( '--', '-', (string) $slot_id ) ) . '" not shown (' . esc_html( str_replace( '--', '-', (string) $state ) ) . ') -->';
		}
		return $html;
	}
}
