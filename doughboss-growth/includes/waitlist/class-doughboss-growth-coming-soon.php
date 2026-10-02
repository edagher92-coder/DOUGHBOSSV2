<?php
/**
 * DoughBoss Growth coming-soon section: a neutral "something exciting is coming" teaser.
 *
 * Feature flag: coming_soon (switched off while the claims ledger is invalid, see the ledger module). The teaser
 * follows web/docs/site/teaser-direction.md: the headline and text are neutral, admin-editable and pass the public-copy
 * lint (a failing edit falls back to the neutral default); there is NO product name, category, price, size, dietary or
 * halal claim, ingredient, date or location, and NO product-specific interest picker.
 *
 * Output:
 *  - shortcode [doughboss_growth_coming_soon cards="" surface="" form="1"]: headline, text, optional tilt cards and, when
 *    the waitlist is on and complete, the waitlist form;
 *  - an optional slim ribbon appended to the home hero (do_shortcode_tag on the hero shortcode), controlled by its own
 *    switch (option doughboss_growth_coming_soon, off by default);
 *  - tilt cards: shown only for ledger claims that are confirmed and sourced (none by default, so none render).
 *
 * Entry file for the module registry: it must stay free of side effects at include time.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coming-soon section.
 */
final class DoughBoss_Growth_Coming_Soon {

	/**
	 * Option holding the ribbon switch (its own option: the settings sanitiser keeps only the keys WP-01 knows).
	 */
	const OPTION = 'doughboss_growth_coming_soon';

	/**
	 * admin-post action and nonce action for the ribbon switch.
	 */
	const SAVE_ACTION = 'doughboss_growth_save_coming_soon';

	/**
	 * Tilt script handle.
	 */
	const HANDLE_TILT = 'dbgr-tilt-cards';

	/**
	 * Most cards one section shows.
	 */
	const MAX_CARDS = 4;

	/**
	 * Claim id shape (kebab-case, as the ledger requires).
	 */
	const KEBAB = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

	/**
	 * Hook everything. Re-checks the flag itself.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		add_shortcode( 'doughboss_growth_coming_soon', array( __CLASS__, 'shortcode' ) );
		add_filter( 'doughboss_growth_ledger_blocks', array( __CLASS__, 'declare_blocks' ) );
		if ( self::ribbon_enabled() ) {
			add_filter( 'do_shortcode_tag', array( __CLASS__, 'filter_hero' ), 10, 4 );
		}
		add_action( 'admin_post_' . self::SAVE_ACTION, array( __CLASS__, 'handle_save' ) );
		if ( is_admin() ) {
			add_action( 'doughboss_growth_admin_tabs', array( __CLASS__, 'register_tab' ) );
		}
	}

	/**
	 * Whether the coming-soon feature is effectively on (flag, kill switch, ledger guard, dependencies).
	 *
	 * @return bool
	 */
	public static function enabled() {
		return DoughBoss_Growth_Settings::enabled( 'coming_soon' );
	}

	/**
	 * Whether the home-hero ribbon is switched on. Off unless the option says exactly "on".
	 *
	 * @return bool
	 */
	public static function ribbon_enabled() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || ! isset( $stored['ribbon'] ) ) {
			return false;
		}
		return ( 1 === $stored['ribbon'] || '1' === $stored['ribbon'] || true === $stored['ribbon'] );
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Neutral copy                                                                                 */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * The ledger class (the public-copy lint), loaded on demand. Null when it cannot be loaded.
	 *
	 * @return bool Whether DoughBoss_Growth_Ledger is available.
	 */
	private static function ledger_available() {
		if ( ! class_exists( 'DoughBoss_Growth_Ledger', false ) && class_exists( 'DoughBoss_Growth' ) ) {
			DoughBoss_Growth::load_module( 'ledger' );
		}
		return class_exists( 'DoughBoss_Growth_Ledger', false );
	}

	/**
	 * One piece of admin-editable teaser text, falling back to the neutral default when it is empty, not a string
	 * or fails the public-copy lint (no source: any digit, currency, percent, dietary word or the working name fails).
	 *
	 * @param string $key     Setting key.
	 * @param string $default Neutral default.
	 * @return string
	 */
	private static function copy( $key, $default ) {
		$value = DoughBoss_Growth_Settings::get( $key, $default );
		if ( ! is_string( $value ) || '' === trim( $value ) || ! self::ledger_available() ) {
			return $default;
		}
		if ( array() !== self::teaser_violations( $value ) ) {
			return $default;
		}
		return ( array() === DoughBoss_Growth_Ledger::lint_public( $value, null ) ) ? $value : $default;
	}

	/**
	 * Teaser-only words (teaser-direction.md rule 2: no product name or category, price, size, pack, dietary claim,
	 * ingredient, launch date or location). The shared public-copy lint catches digits, currency, the working name and
	 * four dietary words; this adds the word forms of the same claims for the free-text teaser lines. It is a deny-list,
	 * so it cannot be complete: a false positive only brings back the neutral default (fail closed).
	 *
	 * @param mixed $text Text.
	 * @return string[] Violation codes: encoding, date, price, location, product, dietary, script (empty = clean).
	 */
	public static function teaser_violations( $text ) {
		if ( ! is_string( $text ) || 1 !== preg_match( '//u', $text ) ) {
			return array( 'encoding' );
		}
		$clean = preg_replace( '/[\x{00AD}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $text );
		if ( ! is_string( $clean ) ) {
			return array( 'encoding' );
		}
		if ( ! self::ledger_available() ) {
			return array( 'encoding' );
		}
		$clean = DoughBoss_Growth_Ledger::fold_compat( $clean );
		if ( null === $clean ) {
			return array( 'encoding' );
		}
		$rules = array(
			'date'     => '/\b(?:mon|tues|wednes|thurs|fri|satur|sun)days?\b|\b(?:today|tomorrow|tonight|weekend|january|february|march|april|may|june|july|august|september|october|november|december)\b/iu',
			'price'    => '/\b(?:dollars?|bucks|cents?|prices?|priced|pricing|costs?|cheap\w*|free|discount\w*|sale|specials?|half|dozen|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|twenty|thirty|forty|fifty|hundred|thousand)\b/iu',
			'location' => '/\b(?:revesby|bankstown|roselands|sydney)\b/iu',
			'product'  => '/\b(?:mini\w*|pizza\w*|manoush\w*|mana\W?ee?sh\w*|manakish|pies?|pastr\w*|bites?|packs?|platters?|trays?|box(?:es)?|sizes?|sized|small|large|flavou?rs?)\b/iu',
			'dietary'  => '/\b(?:vegetarian|dairy|lactose|eggs?|kosher|organic|keto|sugar|allergen\w*|preservatives?|plant\W?based)\b|\x{062D}\x{0644}\x{0627}\x{0644}/iu',
			// Cyrillic or Greek letters in English teaser text are look-alikes that would slip a word past every rule.
			'script'   => '/[\p{Cyrillic}\p{Greek}]/u',
		);
		$out = array();
		foreach ( $rules as $code => $pattern ) {
			if ( 1 === preg_match( $pattern, $clean ) ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/**
	 * The headline.
	 *
	 * @return string
	 */
	public static function headline() {
		return self::copy( 'coming_soon_headline', DoughBoss_Growth_Settings::DEFAULT_COMING_SOON_HEADLINE );
	}

	/**
	 * The body text.
	 *
	 * @return string
	 */
	public static function body() {
		return self::copy( 'coming_soon_body', DoughBoss_Growth_Settings::DEFAULT_COMING_SOON_BODY );
	}

	/**
	 * Whether a saved setting differs from what is shown (the saved text failed the lint).
	 *
	 * @param string $key     Setting key.
	 * @param string $shown   Text shown.
	 * @return bool
	 */
	private static function was_replaced( $key, $shown ) {
		$value = DoughBoss_Growth_Settings::get( $key, '' );
		return is_string( $value ) && '' !== trim( $value ) && $value !== $shown;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Cards                                                                                        */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Claim ids offered by code through the doughboss_growth_coming_soon_claim_ids filter (none by default).
	 *
	 * @return array
	 */
	public static function filter_card_ids() {
		$ids = apply_filters( 'doughboss_growth_coming_soon_claim_ids', array() );
		return self::clean_ids( $ids );
	}

	/**
	 * Keep only kebab-case claim ids, unique, at most MAX_CARDS.
	 *
	 * @param mixed $ids List of ids.
	 * @return array
	 */
	private static function clean_ids( $ids ) {
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$out = array();
		foreach ( $ids as $id ) {
			if ( is_string( $id ) && 1 === preg_match( self::KEBAB, $id ) && strlen( $id ) <= 80 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
			if ( count( $out ) >= self::MAX_CARDS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Tell the claims ledger which claims the cards need, so its admin tab can list a hidden block.
	 *
	 * @param mixed $blocks Blocks declared so far.
	 * @return array
	 */
	public static function declare_blocks( $blocks ) {
		if ( ! is_array( $blocks ) ) {
			$blocks = array();
		}
		$ids = self::filter_card_ids();
		if ( array() !== $ids ) {
			$blocks[] = array(
				'page'   => 'coming-soon',
				'block'  => 'cards',
				'claims' => $ids,
			);
		}
		return $blocks;
	}

	/**
	 * Cards for the given claim ids. A card is drawn only for a claim that is confirmed, sourced and passes the
	 * public-copy lint; anything else is left out. With no publishable claim the result is an empty string.
	 *
	 * @param array $ids Claim ids.
	 * @return string
	 */
	public static function render_cards( array $ids ) {
		if ( array() === $ids || ! self::ledger_available() ) {
			return '';
		}
		$items = array();
		foreach ( self::clean_ids( $ids ) as $id ) {
			try {
				$text = DoughBoss_Growth_Ledger::text( $id );
			} catch ( Throwable $e ) {
				$text = null;
			}
			if ( is_string( $text ) && '' !== $text ) {
				$items[] = '<li class="dbgr-card" data-dbgr-tilt><div class="dbgr-card__face"><p class="dbgr-card__text">' . esc_html( $text ) . '</p></div></li>';
			}
		}
		if ( array() === $items ) {
			return '';
		}
		return '<ul class="dbgr-cards" role="list">' . implode( '', $items ) . '</ul>';
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Assets                                                                                       */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Enqueue the section style and the script that sends coming_soon_view (the waitlist module owns both handles).
	 *
	 * @param bool $with_tilt Also enqueue the tilt script.
	 * @return void
	 */
	private static function enqueue( $with_tilt ) {
		if ( ! class_exists( 'DoughBoss_Growth_Waitlist', false ) && class_exists( 'DoughBoss_Growth' ) ) {
			DoughBoss_Growth::load_module( 'waitlist' );
		}
		if ( ! class_exists( 'DoughBoss_Growth_Waitlist', false ) ) {
			return;
		}
		DoughBoss_Growth_Waitlist::enqueue_assets( false );
		if ( $with_tilt ) {
			wp_enqueue_script( self::HANDLE_TILT, DOUGHBOSS_GROWTH_URL . 'public/js/dbgr-tilt-cards.js', array(), DOUGHBOSS_GROWTH_VERSION, true );
		}
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Shortcode and ribbon                                                                         */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Shortcode [doughboss_growth_coming_soon]. Returns an empty string unless the feature is on.
	 *
	 * @param mixed $atts Attributes: cards (comma-separated claim ids), surface ("home" on the home page only),
	 *                    form ("0" to leave the waitlist form out).
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		if ( ! self::enabled() ) {
			return '';
		}
		$atts = shortcode_atts(
			array(
				'cards'   => '',
				'surface' => '',
				'form'    => '1',
			),
			is_array( $atts ) ? $atts : array(),
			'doughboss_growth_coming_soon'
		);
		static $count = 0;
		$count++;
		$uid = 'dbgr-cs-' . $count;

		$ids = self::filter_card_ids();
		if ( is_string( $atts['cards'] ) && '' !== $atts['cards'] ) {
			$ids = self::clean_ids( array_merge( explode( ',', str_replace( ' ', '', $atts['cards'] ) ), $ids ) );
		}
		$cards   = self::render_cards( $ids );
		$surface = ( 'home' === $atts['surface'] ) ? ' data-dbgr-surface="home"' : '';

		self::enqueue( '' !== $cards );
		$h  = '<section class="dbgr-cs" id="' . esc_attr( $uid ) . '" data-dbgr-coming-soon' . $surface . ' aria-labelledby="' . esc_attr( $uid ) . '-title"><div class="dbgr-cs__inner">';
		$h .= '<h2 class="dbgr-cs__title" id="' . esc_attr( $uid ) . '-title">' . esc_html( self::headline() ) . '</h2>';
		$h .= '<p class="dbgr-cs__body">' . esc_html( self::body() ) . '</p>';
		$h .= $cards;
		if ( '0' !== $atts['form'] && class_exists( 'DoughBoss_Growth_Waitlist', false ) ) {
			$h .= DoughBoss_Growth_Waitlist::render_form( array() );
		}
		$h .= '</div></section>';
		return $h;
	}

	/**
	 * do_shortcode_tag: append the ribbon after the home hero. Never changes anything else and never breaks the hero.
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
		if ( ! is_array( $attr ) || ! isset( $attr['variant'] ) || 'home' !== $attr['variant'] ) {
			return $output;
		}
		try {
			if ( ! self::enabled() || ! self::ribbon_enabled() ) {
				return $output;
			}
			return $output . self::render_ribbon();
		} catch ( Throwable $e ) {
			return $output;
		}
	}

	/**
	 * The URL of the published coming-soon page, or an empty string when there is none.
	 *
	 * @return string
	 */
	public static function page_url() {
		$slug = DoughBoss_Growth_Settings::get( 'coming_soon_page_slug', 'coming-soon' );
		if ( ! is_string( $slug ) || '' === $slug || ! function_exists( 'get_page_by_path' ) ) {
			return '';
		}
		$page = get_page_by_path( $slug );
		if ( ! is_object( $page ) || ! isset( $page->post_status ) || 'publish' !== $page->post_status ) {
			return '';
		}
		$url = get_permalink( $page );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * The slim ribbon: neutral headline and text, and a link to the coming-soon page when the waitlist is on and the
	 * page is published.
	 *
	 * @return string
	 */
	public static function render_ribbon() {
		self::enqueue( false );
		$headline = self::headline();
		$link     = '';
		if ( class_exists( 'DoughBoss_Growth_Waitlist', false ) && DoughBoss_Growth_Waitlist::enabled() ) {
			$url = self::page_url();
			if ( '' !== $url ) {
				$link = ' <a class="dbgr-cs__link" href="' . esc_url( $url ) . '">' . esc_html__( 'Join the VIP list', 'doughboss-growth' ) . '</a>';
			}
		}
		return '<aside class="dbgr-cs dbgr-cs--ribbon" data-dbgr-coming-soon data-dbgr-surface="home" aria-label="' . esc_attr( $headline ) . '"><p class="dbgr-cs__ribbon-text"><strong>' . esc_html( $headline ) . '</strong> <span>' . esc_html( self::body() ) . '</span>' . $link . '</p></aside>';
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Admin                                                                                        */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Add the "Coming soon" tab.
	 *
	 * @return void
	 */
	public static function register_tab() {
		if ( class_exists( 'DoughBoss_Growth_Admin' ) ) {
			DoughBoss_Growth_Admin::add_tab( 'coming-soon', __( 'Coming soon', 'doughboss-growth' ), array( __CLASS__, 'render_tab' ) );
		}
	}

	/**
	 * admin-post handler for the ribbon switch. Capability AND nonce first. The switch is read back after the write and the
	 * redirect says whether it really holds the value that was asked for (dbgr_saved 1 or 0); a failed write is also
	 * noted under Recent failures.
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! DoughBoss_Growth_Admin::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'doughboss-growth' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SAVE_ACTION );
		$on = isset( $_POST['ribbon'] ) && '1' === wp_unslash( $_POST['ribbon'] );
		update_option( self::OPTION, array( 'ribbon' => $on ? 1 : 0 ), true );
		// update_option() returns false for "unchanged" as well as for "failed", so the truth is what reads back.
		$saved = ( self::ribbon_enabled() === $on );
		if ( ! $saved ) {
			DoughBoss_Growth_Http::log( 'coming_soon_save_failed', array( 'stage' => 'ribbon_switch' ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => DoughBoss_Growth_Admin::PAGE_SLUG,
					'tab'        => 'coming-soon',
					'dbgr_saved' => $saved ? '1' : '0',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the tab body.
	 *
	 * @return void
	 */
	public static function render_tab() {
		$headline = self::headline();
		$body     = self::body();
		echo '<h2>' . esc_html__( 'Coming soon', 'doughboss-growth' ) . '</h2>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only result flag after a redirect.
		$saved = isset( $_GET['dbgr_saved'] ) ? sanitize_key( wp_unslash( $_GET['dbgr_saved'] ) ) : '';
		if ( '1' === $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Coming-soon settings saved.', 'doughboss-growth' ) . '</p></div>';
		} elseif ( '0' === $saved ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The coming-soon settings could not be saved, so the home ribbon is unchanged. The problem is listed under Recent failures on the Settings tab.', 'doughboss-growth' ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'The section says only that something is coming. Edit the two lines on the Settings tab. Product names, prices, sizes, dietary or halal wording, numbers and dates are not allowed and fall back to the neutral wording.', 'doughboss-growth' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Headline shown', 'doughboss-growth' ) . '</th><td>' . esc_html( $headline ) . ( self::was_replaced( 'coming_soon_headline', $headline ) ? ' ' . esc_html__( '(your saved headline failed the check, so the neutral one is used)', 'doughboss-growth' ) : '' ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Text shown', 'doughboss-growth' ) . '</th><td>' . esc_html( $body ) . ( self::was_replaced( 'coming_soon_body', $body ) ? ' ' . esc_html__( '(your saved text failed the check, so the neutral one is used)', 'doughboss-growth' ) : '' ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Home ribbon (saved)', 'doughboss-growth' ) . '</th><td>' . ( self::ribbon_enabled() ? esc_html__( 'On', 'doughboss-growth' ) : esc_html__( 'Off', 'doughboss-growth' ) ) . '</td></tr>';
		$url = self::page_url();
		echo '<tr><th scope="row">' . esc_html__( 'Coming-soon page', 'doughboss-growth' ) . '</th><td>' . ( '' !== $url ? esc_html( $url ) : esc_html__( 'No published page with that slug. Create a page with the shortcode [doughboss_growth_coming_soon] and publish it.', 'doughboss-growth' ) ) . '</td></tr>';
		echo '</tbody></table>';

		$hidden = array();
		if ( self::ledger_available() ) {
			foreach ( DoughBoss_Growth_Ledger::hidden_blocks() as $block ) {
				if ( 'coming-soon' === $block['page'] ) {
					$hidden[] = $block;
				}
			}
		}
		if ( array() !== $hidden ) {
			echo '<h3>' . esc_html__( 'Cards hidden because a claim is not confirmed', 'doughboss-growth' ) . '</h3><ul class="ul-disc">';
			foreach ( $hidden as $block ) {
				echo '<li>' . esc_html( $block['block'] . ': ' . implode( ', ', $block['missing'] ) ) . '</li>';
			}
			echo '</ul>';
		}

		echo '<h3>' . esc_html__( 'Home ribbon', 'doughboss-growth' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<p><label for="dbgr-cs-ribbon"><input type="checkbox" id="dbgr-cs-ribbon" name="ribbon" value="1"' . ( self::ribbon_enabled() ? ' checked="checked"' : '' ) . ' /> ' . esc_html__( 'Show a slim ribbon under the home hero', 'doughboss-growth' ) . '</label></p>';
		submit_button( __( 'Save', 'doughboss-growth' ) );
		echo '</form>';
	}
}
