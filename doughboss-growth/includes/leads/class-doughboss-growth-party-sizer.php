<?php
/**
 * DoughBoss Growth catering party-pack sizer.
 *
 * Feature flag: party_sizer (off by default). Shortcode [doughboss_growth_party_sizer enquiry_url=""].
 *
 * The sizer computes NOTHING itself. The visitor types a head count; the browser script asks core for an indicative
 * quote (GET /doughboss/v1/catering/quote) for the published catering package that covers that count, and shows the
 * package name, its serve range and the price core returned. Rules:
 *
 *  - Only real packages: the list handed to the browser is core's published catering packages that have a name, a real
 *    price and a serve range, with their text passed through the public-copy lint (a package whose name carries the
 *    unannounced product's working name, a dietary word, a claim word and so on is left out). Not one package, no sizer.
 *  - Only core's numbers: every price on screen is a figure from a core quote response. The one other figure is the head
 *    count the visitor typed. Pieces-per-guest guidance (web/src/lib/packs.ts) is a claim: it is sent to the browser
 *    only when the claims ledger has a confirmed, sourced, lint-clean claim with the id GUIDANCE_CLAIM (there is none by
 *    default, so none is shown).
 *  - Always overquote, never underquote: a head count between two packages gets the next package up. Above the largest
 *    package the largest is used and core's own per-head overflow pricing applies.
 *  - Nothing here names a product, a dietary status, a lead time or a date.
 *
 * Output classes and files: public/js/dbgr-party-sizer.js, public/css/dbgr-leads.css. Must stay free of side effects at
 * include time (the module registry requires it before any feature check).
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Party-pack sizer.
 */
final class DoughBoss_Growth_Party_Sizer {

	/**
	 * Shortcode tag.
	 */
	const SHORTCODE = 'doughboss_growth_party_sizer';

	/**
	 * Script and style handles. The style is shared with the lead form.
	 */
	const HANDLE_SCRIPT = 'dbgr-party-sizer';
	const HANDLE_STYLE  = 'dbgr-leads';

	/**
	 * Ledger claim id of the pieces-per-guest guidance. Not in the shipped ledger: shown only once Elie confirms it.
	 */
	const GUIDANCE_CLAIM = 'catering-pieces-per-guest';

	/**
	 * Most packages sent to the browser, and the head-count ceiling (core refuses more than 1000 online).
	 */
	const MAX_PACKAGES = 50;
	const MAX_GUESTS   = 1000;

	/**
	 * Whether the browser configuration was already printed for this request.
	 *
	 * @var bool
	 */
	private static $config_done = false;

	/**
	 * Hook everything. Re-checks the flag itself.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Forget per-request state (tests only).
	 *
	 * @return void
	 */
	public static function reset_state() {
		self::$config_done = false;
	}

	/**
	 * Whether the sizer is effectively on: the flag (with the kill switch and every dependency).
	 *
	 * @return bool
	 */
	public static function enabled() {
		return DoughBoss_Growth_Settings::enabled( 'party_sizer' );
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Data from core                                                                               */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * The real, publishable packages: core's published catering packages with a name, a real price and a serve range,
	 * lint-clean, smallest serve range first. Empty when core or the landing reader is unavailable (fail closed).
	 *
	 * The prices are NOT given to the browser: the sizer shows the figure core's quote returns.
	 *
	 * @return array List of array( id (int), name (string), serves_min (int), serves_max (int) ).
	 */
	public static function packages() {
		try {
			if ( ! class_exists( 'DoughBoss_Growth_Landing', false ) && ! DoughBoss_Growth::load_module( 'landing' ) ) {
				return array();
			}
			if ( ! is_callable( array( 'DoughBoss_Growth_Landing', 'core_packages' ) ) ) {
				return array();
			}
			$rows = call_user_func( array( 'DoughBoss_Growth_Landing', 'core_packages' ) );
		} catch ( Throwable $e ) {
			return array();
		}
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'], $row['name'], $row['serves_min'], $row['serves_max'] ) || ! is_string( $row['name'] ) ) {
				continue;
			}
			$id  = (int) $row['id'];
			$min = (int) $row['serves_min'];
			$max = (int) $row['serves_max'];
			if ( $id < 1 || '' === $row['name'] || $max < 1 || $max > 100000 || $min < 0 || $min > $max ) {
				continue; // No serve range, nothing to size against.
			}
			$out[] = array(
				'id'         => $id,
				'name'       => $row['name'],
				'serves_min' => $min,
				'serves_max' => $max,
			);
			if ( count( $out ) >= self::MAX_PACKAGES ) {
				break;
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['serves_max'] !== $b['serves_max'] ) {
					return ( $a['serves_max'] < $b['serves_max'] ) ? -1 : 1;
				}
				return ( $a['id'] < $b['id'] ) ? -1 : 1;
			}
		);
		return $out;
	}

	/**
	 * The confirmed pieces-per-guest guidance, or an empty string. Shown only when the ledger is valid and holds a
	 * confirmed, sourced, lint-clean claim with GUIDANCE_CLAIM as its id.
	 *
	 * @return string
	 */
	public static function guidance() {
		try {
			if ( ! class_exists( 'DoughBoss_Growth_Ledger', false ) && ! DoughBoss_Growth::load_module( 'ledger' ) ) {
				return '';
			}
			$text = DoughBoss_Growth_Ledger::text( self::GUIDANCE_CLAIM );
		} catch ( Throwable $e ) {
			return '';
		}
		return ( is_string( $text ) && '' !== trim( $text ) ) ? $text : '';
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Output                                                                                       */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Register and enqueue the style and script, and print the browser configuration once.
	 *
	 * @param array $packages Packages for the browser.
	 * @return void
	 */
	private static function enqueue_assets( array $packages ) {
		wp_register_style( self::HANDLE_STYLE, DOUGHBOSS_GROWTH_URL . 'public/css/dbgr-leads.css', array(), DOUGHBOSS_GROWTH_VERSION );
		wp_register_script( self::HANDLE_SCRIPT, DOUGHBOSS_GROWTH_URL . 'public/js/dbgr-party-sizer.js', array(), DOUGHBOSS_GROWTH_VERSION, true );
		wp_enqueue_style( self::HANDLE_STYLE );
		wp_enqueue_script( self::HANDLE_SCRIPT );
		if ( ! self::$config_done ) {
			self::$config_done = true;
			wp_localize_script( self::HANDLE_SCRIPT, 'DoughBossGrowthSizer', self::browser_config( $packages ) );
		}
	}

	/**
	 * Browser configuration. No secret, no personal data and no price (prices come from core's quote answers).
	 *
	 * @param array $packages Packages.
	 * @return array
	 */
	public static function browser_config( array $packages ) {
		$namespace = defined( 'DOUGHBOSS_REST_NAMESPACE' ) ? trim( (string) constant( 'DOUGHBOSS_REST_NAMESPACE' ), '/' ) : 'doughboss/v1';
		return array(
			'quoteUrl'  => rest_url( $namespace . '/catering/quote' ),
			'maxGuests' => self::MAX_GUESTS,
			'packages'  => $packages,
			'guidance'  => self::guidance(),
			'strings'   => array(
				'guests'      => __( 'Please enter how many guests, as a whole number.', 'doughboss-growth' ),
				'tooMany'     => __( 'For a group this size, please send an enquiry and the catering team will quote.', 'doughboss-growth' ),
				'loading'     => __( 'Checking the current price...', 'doughboss-growth' ),
				'unavailable' => __( 'We could not get a price just now. Please send an enquiry and the catering team will quote.', 'doughboss-growth' ),
				'recommended' => __( 'Suggested package', 'doughboss-growth' ),
				'next'        => __( 'Next size up', 'doughboss-growth' ),
				'serves'      => __( 'Serves', 'doughboss-growth' ),
				'to'          => __( 'to', 'doughboss-growth' ),
				'forGuests'   => __( 'For', 'doughboss-growth' ),
				'guestsWord'  => __( 'guests', 'doughboss-growth' ),
				'indicative'  => __( 'Indicative price. The final price is confirmed when your enquiry is quoted.', 'doughboss-growth' ),
			),
		);
	}

	/**
	 * Shortcode [doughboss_growth_party_sizer enquiry_url=""]. Returns an empty string unless the sizer is on and at
	 * least one real package exists.
	 *
	 * @param mixed $atts Attributes: enquiry_url (optional link to the enquiry form).
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		if ( ! self::enabled() ) {
			return '';
		}
		$atts     = is_array( $atts ) ? $atts : array();
		$packages = self::packages();
		if ( array() === $packages ) {
			return '';
		}
		self::enqueue_assets( $packages );

		static $instance = 0;
		++$instance;
		$uid = 'dbgr-sz-' . $instance;

		$h  = '<div class="dbgr-sizer" id="' . esc_attr( $uid ) . '" data-dbgr-sizer>';
		$h .= '<form class="dbgr-sizer__form" novalidate method="post" action="#" aria-labelledby="' . esc_attr( $uid ) . '-title">';
		$h .= '<p class="dbgr-sizer__title" id="' . esc_attr( $uid ) . '-title">' . esc_html__( 'Find the right package', 'doughboss-growth' ) . '</p>';
		$h .= '<p class="dbgr-lead__field"><label for="' . esc_attr( $uid ) . '-guests">' . esc_html__( 'How many guests?', 'doughboss-growth' ) . '</label>';
		$h .= '<input type="number" id="' . esc_attr( $uid ) . '-guests" name="guests" min="1" max="' . esc_attr( (string) self::MAX_GUESTS ) . '" step="1" inputmode="numeric" required /></p>';
		$h .= '<p class="dbgr-lead__actions"><button type="submit" class="dbgr-lead__button">' . esc_html__( 'Show package', 'doughboss-growth' ) . '</button></p>';
		$h .= '</form>';
		$h .= '<div class="dbgr-sizer__result" data-dbgr-sizer-result role="status" aria-live="polite"></div>';
		$link = isset( $atts['enquiry_url'] ) && is_string( $atts['enquiry_url'] ) ? esc_url( $atts['enquiry_url'] ) : '';
		if ( '' !== $link ) {
			$h .= '<p class="dbgr-sizer__cta"><a href="' . $link . '">' . esc_html__( 'Send an enquiry', 'doughboss-growth' ) . '</a></p>';
		}
		$h .= '<noscript><p class="dbgr-sizer__noscript">' . esc_html__( 'This tool needs JavaScript to be switched on.', 'doughboss-growth' ) . '</p></noscript>';
		$h .= '</div>';
		return $h;
	}
}
