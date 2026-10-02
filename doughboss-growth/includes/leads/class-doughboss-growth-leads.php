<?php
/**
 * DoughBoss Growth corporate lead form.
 *
 * Feature flag: lead_form (off by default). Shortcode [doughboss_growth_lead_form variant="corporate|office_breakfast|events"
 * landing=""]. Entry file of the "leads" module (the party-pack sizer is the second file of the same module).
 *
 * The form is a segmented front door to core's ONE enquiry writer: it posts to core's POST /doughboss/v1/catering/enquiry
 * with core's own field names and core's wp_rest nonce, plus four optional extras that the attribution module (WP-04)
 * reads on that route only: dbgr_company, dbgr_segment, dbgr_landing_key and the marketing-consent pair. Nothing is
 * stored by this class. The lead_meta row is written by the attribution module in the same hook as core's insert
 * (doughboss_catering_enquiry_created), so an enquiry core rejects (honeypot, rate limit, validation, nonce) leaves no
 * lead record at all. The action doughboss_growth_lead_recorded( $enquiry_id, $meta ) is fired by that module.
 *
 * Marketing consent:
 *  - The box is separate, UNTICKED and optional. Its wording names the sender (setting sender_legal_name) and says how
 *    to withdraw. The wording carries a version derived from the wording itself; the form sends the version only when the
 *    box is ticked, and the attribution module stores consent (with that version and the UTC time) only for the exact
 *    value 1 plus a valid version.
 *  - No sender legal name (an owner decision, still [CONFIRM]) means no box: the enquiry still works, consent cannot be
 *    given, and nothing says otherwise.
 *
 * Claims: the form's text is generic and holds no product, price, size, dietary, date or location claim. The package
 * choice lists the lint-clean real packages from core (the same reader the landing pages use) plus "not sure yet".
 *
 * Must stay free of side effects at include time.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Corporate lead form.
 */
final class DoughBoss_Growth_Leads {

	/**
	 * Shortcode tag.
	 */
	const SHORTCODE = 'doughboss_growth_lead_form';

	/**
	 * Script and style handles. The style is shared with the sizer.
	 */
	const HANDLE_SCRIPT = 'dbgr-lead-form';
	const HANDLE_STYLE  = 'dbgr-leads';

	/**
	 * Variants. The segment is what the attribution module stores in lead_meta.segment; the landing key matches the
	 * WP-06 page definitions (content/landing/catering-*.json).
	 */
	const VARIANTS = array(
		'corporate'        => array(
			'segment'          => 'corporate',
			'landing_key'      => 'catering-corporate',
			'company_required' => true,
		),
		'office_breakfast' => array(
			'segment'          => 'office_breakfast',
			'landing_key'      => 'catering-office-breakfast',
			'company_required' => true,
		),
		'events'           => array(
			'segment'          => 'events',
			'landing_key'      => 'catering-events',
			'company_required' => false,
		),
	);

	/**
	 * Largest sizes (they match the attribution module's caps and core's limits).
	 */
	const MAX_NAME    = 100;
	const MAX_EMAIL   = 100;
	const MAX_PHONE   = 30;
	const MAX_COMPANY = 120;
	const MAX_NOTES   = 1500;
	const MAX_GUESTS  = 1000;

	/**
	 * Whether the browser configuration was already printed for this request.
	 *
	 * @var bool
	 */
	private static $config_done = false;

	/* ------------------------------------------------------------------------------------------ */
	/* Wiring                                                                                       */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Hook everything. Re-checks the flags itself. The sizer registers itself.
	 *
	 * @return void
	 */
	public static function init() {
		if ( class_exists( 'DoughBoss_Growth_Party_Sizer', false ) ) {
			DoughBoss_Growth_Party_Sizer::init();
		}
		if ( DoughBoss_Growth_Settings::enabled( 'lead_form' ) ) {
			self::ensure_recording();
			add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_style_for_post' ) );
		}
		if ( is_admin() ) {
			add_action( 'doughboss_growth_admin_tabs', array( __CLASS__, 'register_tab' ) );
		}
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
	 * Whether the form is effectively on: the flag (with the kill switch and every dependency) and the storage the
	 * lead record needs.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return DoughBoss_Growth_Settings::enabled( 'lead_form' ) && DoughBoss_Growth_Activator::storage_ready();
	}

	/**
	 * Make sure the attribution module's enquiry hooks are live. The registry starts that module only for its own
	 * flag, so with lead_form alone nothing would write the lead record (company, segment, consent). Its init() already
	 * supports lead_form on its own. True when the hook is in place.
	 *
	 * @return bool
	 */
	public static function ensure_recording() {
		try {
			if ( ! class_exists( 'DoughBoss_Growth_Attribution', false ) && ! DoughBoss_Growth::load_module( 'attribution' ) ) {
				return false;
			}
			$callback = array( 'DoughBoss_Growth_Attribution', 'on_enquiry_created' );
			if ( false === has_filter( 'doughboss_catering_enquiry_created', $callback ) ) {
				DoughBoss_Growth_Attribution::init();
			}
			return false !== has_filter( 'doughboss_catering_enquiry_created', $callback );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Consent wording                                                                              */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * The ledger class (the public-copy lint), loaded on demand.
	 *
	 * @return bool
	 */
	private static function ledger_available() {
		if ( ! class_exists( 'DoughBoss_Growth_Ledger', false ) && class_exists( 'DoughBoss_Growth' ) ) {
			DoughBoss_Growth::load_module( 'ledger' );
		}
		return class_exists( 'DoughBoss_Growth_Ledger', false );
	}

	/**
	 * The sender's legal name, or '' when missing or unusable (checked with the shared public-copy lint; when the lint
	 * is unavailable the answer is "unusable", so the box is not shown).
	 *
	 * @return string
	 */
	public static function sender_name() {
		$name = DoughBoss_Growth_Settings::get( 'sender_legal_name', '' );
		if ( ! is_string( $name ) || '' === trim( $name ) || ! self::ledger_available() ) {
			return '';
		}
		$name  = trim( $name );
		$codes = DoughBoss_Growth_Ledger::lint_public( $name, 'owner-confirmed' );
		if ( in_array( 'product_name', $codes, true ) || in_array( 'encoding', $codes, true ) ) {
			return '';
		}
		return $name;
	}

	/**
	 * The exact wording shown beside the unticked box. Names the sender, says what it is for and how to withdraw. No
	 * product, price or date. Empty when the sender name is missing (no box).
	 *
	 * @return string
	 */
	public static function consent_text() {
		$name = self::sender_name();
		if ( '' === $name ) {
			return '';
		}
		/* translators: %1$s: the sender's legal name. */
		return sprintf( __( 'I agree that %1$s may email me news and offers about catering. I can withdraw this at any time using the unsubscribe link in any message, or by contacting %1$s.', 'doughboss-growth' ), $name );
	}

	/**
	 * The wording version, derived from the wording itself so any change (including the sender name) is a new version.
	 *
	 * @return string "ld-" and 12 hex characters (15 characters: the attribution module accepts up to 20), or '' with no wording.
	 */
	public static function consent_version() {
		$text = self::consent_text();
		return ( '' === $text ) ? '' : 'ld-' . substr( hash( 'sha256', $text ), 0, 12 );
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Data from core                                                                               */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Active core shops: id => array( name, slug ). Empty when core cannot be read (the form then omits the shop choice
	 * and core assigns its default shop).
	 *
	 * @return array
	 */
	public static function stores() {
		if ( ! class_exists( 'DoughBoss_Locations' ) || ! is_callable( array( 'DoughBoss_Locations', 'all' ) ) ) {
			return array();
		}
		try {
			$rows = call_user_func( array( 'DoughBoss_Locations', 'all' ), true );
		} catch ( Throwable $e ) {
			return array();
		}
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$row  = is_object( $row ) ? get_object_vars( $row ) : ( is_array( $row ) ? $row : array() );
			$id   = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$name = ( isset( $row['name'] ) && is_string( $row['name'] ) ) ? sanitize_text_field( $row['name'] ) : '';
			if ( $id < 1 || '' === $name ) {
				continue;
			}
			$slug        = ( isset( $row['slug'] ) && is_string( $row['slug'] ) ) ? $row['slug'] : '';
			$out[ $id ] = array(
				'name' => $name,
				'slug' => in_array( $slug, array( 'revesby', 'bankstown', 'roselands' ), true ) ? $slug : '',
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Output                                                                                       */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * wp_enqueue_scripts: on a single post or page that holds the form shortcode (or the party sizer's, which shares this
	 * stylesheet), put the stylesheet in the head so the form is styled the moment it paints.
	 *
	 * @return void
	 */
	public static function enqueue_style_for_post() {
		if ( is_admin() || ! function_exists( 'is_singular' ) || ! is_singular() || ! function_exists( 'get_queried_object_id' ) ) {
			return;
		}
		$post = get_post( get_queried_object_id() );
		if ( ! is_object( $post ) || ! isset( $post->post_content ) || ! is_string( $post->post_content ) ) {
			return;
		}
		if ( has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			wp_register_style( self::HANDLE_STYLE, DOUGHBOSS_GROWTH_URL . 'public/css/dbgr-leads.css', array(), DOUGHBOSS_GROWTH_VERSION );
			wp_enqueue_style( self::HANDLE_STYLE );
		}
	}

	/**
	 * The stylesheet link to print right here, once, when the head is already past (see DoughBoss_Growth_Waitlist::early_style()).
	 * The party sizer shares this handle, so the two never print it twice.
	 *
	 * @return string
	 */
	private static function early_style() {
		if ( ! did_action( 'wp_head' ) || wp_style_is( self::HANDLE_STYLE, 'done' ) ) {
			return '';
		}
		ob_start();
		wp_print_styles( self::HANDLE_STYLE );
		return (string) ob_get_clean();
	}

	/**
	 * Register and enqueue the style and script, and print the browser configuration once.
	 *
	 * @return void
	 */
	private static function enqueue_assets() {
		wp_register_style( self::HANDLE_STYLE, DOUGHBOSS_GROWTH_URL . 'public/css/dbgr-leads.css', array(), DOUGHBOSS_GROWTH_VERSION );
		wp_register_script( self::HANDLE_SCRIPT, DOUGHBOSS_GROWTH_URL . 'public/js/dbgr-lead-form.js', array(), DOUGHBOSS_GROWTH_VERSION, true );
		wp_enqueue_style( self::HANDLE_STYLE );
		wp_enqueue_script( self::HANDLE_SCRIPT );
		if ( ! self::$config_done ) {
			self::$config_done = true;
			wp_localize_script( self::HANDLE_SCRIPT, 'DoughBossGrowthLeads', self::browser_config() );
		}
	}

	/**
	 * Browser configuration: the core enquiry URL, core's wp_rest nonce and the messages. No secret and no personal data.
	 *
	 * @return array
	 */
	public static function browser_config() {
		$namespace = defined( 'DOUGHBOSS_REST_NAMESPACE' ) ? trim( (string) constant( 'DOUGHBOSS_REST_NAMESPACE' ), '/' ) : 'doughboss/v1';
		return array(
			'enquiryUrl' => rest_url( $namespace . '/catering/enquiry' ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'maxGuests'  => self::MAX_GUESTS,
			'strings'    => array(
				'name'      => __( 'Please enter your name.', 'doughboss-growth' ),
				'email'     => __( 'Please enter a valid email address.', 'doughboss-growth' ),
				'company'   => __( 'Please enter your company name.', 'doughboss-growth' ),
				'guests'    => __( 'Please enter how many guests, as a whole number.', 'doughboss-growth' ),
				'sending'   => __( 'Sending', 'doughboss-growth' ),
				'sent'      => __( 'Thank you. Your enquiry has been received and the catering team will be in touch.', 'doughboss-growth' ),
				'number'    => __( 'Your enquiry number is', 'doughboss-growth' ),
				'refresh'   => __( 'This page has expired. Please refresh it and try again.', 'doughboss-growth' ),
				'limit'     => __( 'Too many enquiries just now. Please try again later.', 'doughboss-growth' ),
				'network'   => __( 'We could not reach the server. Please try again.', 'doughboss-growth' ),
				'generic'   => __( 'Something went wrong. Please try again later.', 'doughboss-growth' ),
			),
		);
	}

	/**
	 * Shortcode [doughboss_growth_lead_form variant="" landing=""]. Returns an empty string unless the form is on and
	 * the lead record is wired (fail closed), or the variant is unknown.
	 *
	 * @param mixed $atts Attributes: variant (corporate, office_breakfast, events), landing (landing page key).
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		$atts    = is_array( $atts ) ? $atts : array();
		$variant = isset( $atts['variant'] ) && is_string( $atts['variant'] ) && '' !== $atts['variant'] ? sanitize_key( $atts['variant'] ) : 'corporate';
		if ( ! isset( self::VARIANTS[ $variant ] ) ) {
			return '';
		}
		$landing = self::VARIANTS[ $variant ]['landing_key'];
		if ( isset( $atts['landing'] ) && is_string( $atts['landing'] ) && 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $atts['landing'] ) && strlen( $atts['landing'] ) <= 64 ) {
			$landing = $atts['landing'];
		}
		return self::render_form( $variant, $landing );
	}

	/**
	 * Build the form markup. The marketing box is separate and unticked; nothing here names a product.
	 *
	 * @param string $variant Variant key.
	 * @param string $landing Landing page key.
	 * @return string
	 */
	public static function render_form( $variant, $landing ) {
		if ( ! isset( self::VARIANTS[ $variant ] ) || ! self::enabled() || ! self::ensure_recording() ) {
			return '';
		}
		$def = self::VARIANTS[ $variant ];
		self::enqueue_assets();

		static $instance = 0;
		++$instance;
		$uid     = 'dbgr-ld-' . $instance;
		$stores  = self::stores();
		$pkgs    = class_exists( 'DoughBoss_Growth_Party_Sizer', false ) ? DoughBoss_Growth_Party_Sizer::packages() : array();
		$consent = self::consent_text();
		$req     = $def['company_required'];

		$h  = self::early_style();
		$h .= '<form class="dbgr-lead" id="' . esc_attr( $uid ) . '" data-dbgr-lead-form data-segment="' . esc_attr( $def['segment'] ) . '" data-landing="' . esc_attr( $landing ) . '"';
		$h .= ' data-company-required="' . ( $req ? '1' : '0' ) . '" method="post" action="#" novalidate aria-labelledby="' . esc_attr( $uid ) . '-legend">';
		$h .= '<div data-dbgr-lead-fields>';
		$h .= '<p class="dbgr-lead__legend" id="' . esc_attr( $uid ) . '-legend">' . esc_html__( 'Catering enquiry', 'doughboss-growth' ) . '</p>';

		$h .= self::field( $uid, 'customer_name', __( 'Your name', 'doughboss-growth' ), 'text', true, array( 'autocomplete' => 'name', 'maxlength' => (string) self::MAX_NAME ) );
		$h .= self::field( $uid, 'dbgr_company', __( 'Company', 'doughboss-growth' ), 'text', $req, array( 'autocomplete' => 'organization', 'maxlength' => (string) self::MAX_COMPANY ) );
		$h .= self::field( $uid, 'customer_email', __( 'Email', 'doughboss-growth' ), 'email', true, array( 'autocomplete' => 'email', 'inputmode' => 'email', 'maxlength' => (string) self::MAX_EMAIL ) );
		$h .= self::field( $uid, 'customer_phone', __( 'Phone', 'doughboss-growth' ), 'tel', false, array( 'autocomplete' => 'tel', 'inputmode' => 'tel', 'maxlength' => (string) self::MAX_PHONE ) );

		if ( array() !== $pkgs ) {
			$h .= '<div class="dbgr-lead__field"><label for="' . esc_attr( $uid ) . '-package_id">' . esc_html__( 'Package', 'doughboss-growth' ) . ' <span class="dbgr-lead__opt">' . esc_html__( '(optional)', 'doughboss-growth' ) . '</span></label>';
			$h .= '<select id="' . esc_attr( $uid ) . '-package_id" name="package_id"><option value="0">' . esc_html__( 'Not sure yet', 'doughboss-growth' ) . '</option>';
			foreach ( $pkgs as $pkg ) {
				$h .= '<option value="' . esc_attr( (string) $pkg['id'] ) . '">' . esc_html( $pkg['name'] ) . '</option>';
			}
			$h .= '</select></div>';
		}

		$h .= self::field( $uid, 'guest_count', __( 'Number of guests', 'doughboss-growth' ), 'number', true, array( 'min' => '1', 'max' => (string) self::MAX_GUESTS, 'step' => '1', 'inputmode' => 'numeric' ) );
		$h .= self::field( $uid, 'event_date', __( 'Event date', 'doughboss-growth' ), 'date', false, array() );

		$h .= '<div class="dbgr-lead__field"><label for="' . esc_attr( $uid ) . '-order_type">' . esc_html__( 'Pickup or delivery', 'doughboss-growth' ) . '</label>';
		$h .= '<select id="' . esc_attr( $uid ) . '-order_type" name="order_type"><option value="pickup">' . esc_html__( 'Pickup', 'doughboss-growth' ) . '</option><option value="delivery">' . esc_html__( 'Delivery', 'doughboss-growth' ) . '</option></select></div>';

		$h .= '<div class="dbgr-lead__field" data-dbgr-lead-address hidden><label for="' . esc_attr( $uid ) . '-address">' . esc_html__( 'Delivery address', 'doughboss-growth' ) . '</label>';
		$h .= '<textarea id="' . esc_attr( $uid ) . '-address" name="address" rows="2" maxlength="300" autocomplete="street-address"></textarea></div>';

		if ( array() !== $stores ) {
			$h .= '<div class="dbgr-lead__field"><label for="' . esc_attr( $uid ) . '-location_id">' . esc_html__( 'Shop', 'doughboss-growth' ) . ' <span class="dbgr-lead__opt">' . esc_html__( '(optional)', 'doughboss-growth' ) . '</span></label>';
			$h .= '<select id="' . esc_attr( $uid ) . '-location_id" name="location_id"><option value="0" data-slug="none">' . esc_html__( 'No preference', 'doughboss-growth' ) . '</option>';
			foreach ( $stores as $id => $store ) {
				$h .= '<option value="' . esc_attr( (string) $id ) . '" data-slug="' . esc_attr( '' !== $store['slug'] ? $store['slug'] : 'none' ) . '">' . esc_html( $store['name'] ) . '</option>';
			}
			$h .= '</select></div>';
		}

		$h .= '<div class="dbgr-lead__field"><label for="' . esc_attr( $uid ) . '-notes">' . esc_html__( 'Anything else we should know', 'doughboss-growth' ) . ' <span class="dbgr-lead__opt">' . esc_html__( '(optional)', 'doughboss-growth' ) . '</span></label>';
		$h .= '<textarea id="' . esc_attr( $uid ) . '-notes" name="notes" rows="3" maxlength="' . esc_attr( (string) self::MAX_NOTES ) . '"></textarea></div>';

		// Honeypot: real visitors never see or reach it. Core accepts a filled value silently and saves nothing.
		$h .= '<div class="dbgr-lead__hp" aria-hidden="true"><label for="' . esc_attr( $uid ) . '-hp">' . esc_html__( 'Leave this field empty', 'doughboss-growth' ) . '</label>';
		$h .= '<input type="text" id="' . esc_attr( $uid ) . '-hp" name="hp" tabindex="-1" autocomplete="off" value="" /></div>';

		// The marketing box is separate, optional and UNTICKED. Without the sender's legal name there is no box.
		if ( '' !== $consent ) {
			$h .= '<div class="dbgr-lead__consent"><label for="' . esc_attr( $uid ) . '-consent"><input type="checkbox" id="' . esc_attr( $uid ) . '-consent" name="dbgr_consent_marketing" value="1" /> <span>' . esc_html( $consent ) . '</span></label></div>';
			$h .= '<input type="hidden" name="dbgr_consent_text_version" value="' . esc_attr( self::consent_version() ) . '" />';
		}
		$privacy = DoughBoss_Growth_Settings::get( 'privacy_policy_url', '' );
		if ( is_string( $privacy ) && '' !== $privacy ) {
			$h .= '<div class="dbgr-lead__privacy"><a href="' . esc_url( $privacy ) . '">' . esc_html__( 'Privacy policy', 'doughboss-growth' ) . '</a></div>';
		}

		$h .= '<div class="dbgr-lead__actions"><button type="submit" class="dbgr-lead__button">' . esc_html__( 'Send enquiry', 'doughboss-growth' ) . '</button></div>';
		$h .= '</div>';
		$h .= '<p class="dbgr-lead__status" data-dbgr-lead-status role="status" aria-live="polite"></p>';
		$h .= '<noscript><p class="dbgr-lead__noscript">' . esc_html__( 'This form needs JavaScript to be switched on.', 'doughboss-growth' ) . '</p></noscript>';
		$h .= '</form>';
		return $h;
	}

	/**
	 * One labelled input in a paragraph.
	 *
	 * @param string $uid      Form id.
	 * @param string $name     Field name.
	 * @param string $label    Label text.
	 * @param string $type     Input type.
	 * @param bool   $required Required.
	 * @param array  $attrs    Extra attributes (name => value).
	 * @return string
	 */
	private static function field( $uid, $name, $label, $type, $required, array $attrs ) {
		$id   = $uid . '-' . $name;
		$flag = $required ? esc_html__( '(required)', 'doughboss-growth' ) : esc_html__( '(optional)', 'doughboss-growth' );
		$out  = '<div class="dbgr-lead__field" data-dbgr-field="' . esc_attr( $name ) . '"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ' <span class="' . ( $required ? 'dbgr-lead__req' : 'dbgr-lead__opt' ) . '">' . $flag . '</span></label>';
		$out .= '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"';
		foreach ( $attrs as $key => $value ) {
			$out .= ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
		}
		if ( $required ) {
			$out .= ' required';
		}
		return $out . ' /></div>';
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Admin tab                                                                                    */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Add the "Leads" tab.
	 *
	 * @return void
	 */
	public static function register_tab() {
		if ( class_exists( 'DoughBoss_Growth_Admin' ) ) {
			DoughBoss_Growth_Admin::add_tab( 'leads', __( 'Leads', 'doughboss-growth' ), array( __CLASS__, 'render_tab' ) );
		}
	}

	/**
	 * The [CONFIRM] gaps for this module (admin-only text).
	 *
	 * @return array Code => text.
	 */
	public static function confirm_gaps() {
		$gaps = array();
		if ( '' === self::sender_name() ) {
			$gaps['sender_legal_name'] = '[CONFIRM: sender legal name (and ABN if shown). Until it is set the marketing opt-in box is NOT shown on the lead form, so no marketing consent can be collected.]';
		}
		if ( '' === (string) DoughBoss_Growth_Settings::get( 'privacy_policy_url', '' ) ) {
			$gaps['privacy_policy_url'] = '[CONFIRM: privacy-policy URL. The form links to it only when it is set; the policy must describe what an enquiry stores (name, email, phone, company, source).]';
		}
		$gaps['guidance_claim']   = '[CONFIRM: pieces-per-guest guidance for the sizer. It is shown only once the claims ledger has a confirmed, sourced claim with the id ' . DoughBoss_Growth_Party_Sizer::GUIDANCE_CLAIM . '. Until then the sizer shows package, serve range and price only.]';
		$gaps['full_page_cache']  = '[CONFIRM: the form uses core\'s wp_rest nonce. If the host or a cache plugin serves a cached copy of the page for longer than the nonce lifetime, a submission can be refused with "This page has expired". Exclude the catering pages from full-page caching.]';
		$gaps['marketing_follow'] = '[CONFIRM: ticking the box records consent only. Nothing here sends marketing; a sending process must honour it and give a working unsubscribe.]';
		return $gaps;
	}

	/**
	 * Tab body: status and gaps. Admin-only; shows no personal data.
	 *
	 * @return void
	 */
	public static function render_tab() {
		echo '<h2>' . esc_html__( 'Leads and sizer', 'doughboss-growth' ) . '</h2>';
		$rows = array(
			array( __( 'Lead form', 'doughboss-growth' ), DoughBoss_Growth_Settings::enabled( 'lead_form' ) ? __( 'On', 'doughboss-growth' ) : __( 'Off', 'doughboss-growth' ) ),
			array( __( 'Storage', 'doughboss-growth' ), DoughBoss_Growth_Activator::storage_ready() ? __( 'Ready', 'doughboss-growth' ) : __( 'Not ready', 'doughboss-growth' ) ),
			array( __( 'Marketing opt-in box', 'doughboss-growth' ), '' !== self::consent_text() ? __( 'Shown (wording version', 'doughboss-growth' ) . ' ' . self::consent_version() . ')' : __( 'Not shown (no sender name)', 'doughboss-growth' ) ),
			array( __( 'Quantity sizer', 'doughboss-growth' ), DoughBoss_Growth_Settings::enabled( 'party_sizer' ) ? __( 'On', 'doughboss-growth' ) : __( 'Off', 'doughboss-growth' ) ),
			array( __( 'Packages the sizer can use', 'doughboss-growth' ), (string) count( DoughBoss_Growth_Party_Sizer::packages() ) ),
			array( __( 'Pieces-per-guest guidance', 'doughboss-growth' ), '' !== DoughBoss_Growth_Party_Sizer::guidance() ? __( 'Confirmed claim present', 'doughboss-growth' ) : __( 'Hidden (no confirmed claim)', 'doughboss-growth' ) ),
		);
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><th scope="row">' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( DoughBoss_Growth_Activator::storage_ready() ) {
			self::render_recent_leads();
		}
		echo '<p>' . esc_html__( 'Shortcodes: [doughboss_growth_lead_form variant="corporate|office_breakfast|events"] and [doughboss_growth_party_sizer].', 'doughboss-growth' ) . '</p>';
		echo '<h3>' . esc_html__( 'Owner decisions still outstanding', 'doughboss-growth' ) . '</h3><ul class="ul-disc">';
		foreach ( self::confirm_gaps() as $text ) {
			echo '<li>' . esc_html( DoughBoss_Growth_Admin::plain_gap( $text ) ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * The latest lead records: enquiry number, segment, company, whether marketing consent was given, the wording
	 * version and the UTC time. No email address, name, phone number or notes (those live in core's enquiry only).
	 *
	 * @return void
	 */
	private static function render_recent_leads() {
		global $wpdb;
		if ( ! class_exists( 'DoughBoss_Growth_Attribution', false ) && ! DoughBoss_Growth::load_module( 'attribution' ) ) {
			return;
		}
		$table = DoughBoss_Growth_Attribution::lead_meta_table();
		// Each read is judged straight after it runs: a database error makes get_var() return null (which (int) prints as a calm
		// 0) and get_results() an empty list (which would print "None yet."), neither of which is true.
		$total        = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- no variables; table name built from the WordPress prefix and a constant.
		$total_failed = ( null === $total || '' !== (string) $wpdb->last_error );
		$rows         = $wpdb->get_results( "SELECT enquiry_id, segment, company_name, consent_marketing, consent_text_version, created_at FROM {$table} ORDER BY id DESC LIMIT 10", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- no variables; table name built from the WordPress prefix and a constant.
		$rows_failed  = ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) );
		echo '<h3>' . esc_html__( 'Latest lead records', 'doughboss-growth' ) . '</h3>';
		if ( $total_failed ) {
			echo '<p data-dbgr-lead-count>' . esc_html__( 'Lead records: could not be read', 'doughboss-growth' ) . '</p>';
		} else {
			echo '<p data-dbgr-lead-count>' . esc_html( sprintf( /* translators: %d: number of lead records. */ __( 'Lead records: %d', 'doughboss-growth' ), (int) $total ) ) . '</p>';
		}
		if ( $rows_failed ) {
			echo '<p>' . esc_html__( 'The latest lead records could not be read.', 'doughboss-growth' ) . '</p>';
			return;
		}
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'None yet.', 'doughboss-growth' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		foreach ( array( __( 'Enquiry', 'doughboss-growth' ), __( 'Segment', 'doughboss-growth' ), __( 'Company', 'doughboss-growth' ), __( 'Marketing consent', 'doughboss-growth' ), __( 'Wording version', 'doughboss-growth' ), __( 'Recorded (UTC)', 'doughboss-growth' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['enquiry_id'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['segment'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['company_name'] ) . '</td>';
			echo '<td>' . esc_html( 1 === (int) $row['consent_marketing'] ? __( 'Yes', 'doughboss-growth' ) : __( 'No', 'doughboss-growth' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['consent_text_version'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
