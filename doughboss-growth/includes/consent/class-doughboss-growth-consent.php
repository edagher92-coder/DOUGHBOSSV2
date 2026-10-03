<?php
/**
 * DoughBoss Growth consent: the one consent source for the whole site.
 *
 * Feature flag: consent_banner (the gtm flag needs it). Everything is inert while the flag is off.
 *
 * What this module does when the flag is on:
 *  - prints the consent banner (Accept all, Reject all, Settings, equal prominence) and a persistent
 *    "Privacy choices" button in wp_footer, and enqueues public/js/dbgr-consent.js (the banner, the
 *    dbgr_consent cookie, Consent Mode updates, the core doughboss:consent event) and
 *    public/js/dbgr-datalayer.js (typed dataLayer events, DoughBossGrowth.track) only when Tag Manager is on and ready;
 *  - sets core's doughboss_marketing_config to enabled = true with empty Meta and TikTok pixel ids so core's
 *    bridge keeps emitting doughboss:marketing-event but never calls fbq or ttq itself;
 *  - exposes current(), the server-side reading of the same cookie, for attribution and conversions;
 *  - adds a "Consent and tags" tab to the Growth admin page with status and [CONFIRM] gaps.
 * The Tag Manager loader and the Consent Mode default live in class-doughboss-growth-tags.php.
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
 * Consent banner, configuration for the browser scripts, and the server-side consent reading.
 */
final class DoughBoss_Growth_Consent {

	/**
	 * Name of the consent cookie.
	 */
	const COOKIE_NAME = 'dbgr_consent';

	/**
	 * Script and style handles.
	 */
	const HANDLE_CONSENT   = 'dbgr-consent';
	const HANDLE_DATALAYER = 'dbgr-datalayer';

	/**
	 * Largest cookie value accepted by parse_cookie(), in characters.
	 */
	const COOKIE_MAX_LENGTH = 400;

	/**
	 * Parsed content/events.json for this request (null = not read yet, array() = unusable).
	 *
	 * @var array|null
	 */
	private static $events_cache = null;

	/**
	 * Test seam: read events.json from another path. Production code never sets this.
	 *
	 * @var string|null
	 */
	private static $events_path_override = null;

	/**
	 * Hook everything. Re-checks the flag itself.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! DoughBoss_Growth_Settings::enabled( 'consent_banner' ) ) {
			return;
		}
		if ( class_exists( 'DoughBoss_Growth_Tags' ) ) {
			DoughBoss_Growth_Tags::init();
		}
		add_filter( 'doughboss_marketing_config', array( __CLASS__, 'filter_marketing_config' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_banner' ), 20 );
		add_action( 'doughboss_growth_admin_tabs', array( __CLASS__, 'register_tab' ) );
	}

	/**
	 * Forget the per-request events cache (tests only).
	 *
	 * @return void
	 */
	public static function reset_state() {
		self::$events_cache         = null;
		self::$events_path_override = null;
	}

	/**
	 * Read events.json from another file (tests only; ignored outside the test harness).
	 *
	 * @param string|null $path File path or null to restore the real one.
	 * @return void
	 */
	public static function set_events_path_override( $path ) {
		if ( ! defined( 'DBGR_TESTING' ) ) {
			return;
		}
		self::$events_path_override = ( is_string( $path ) && '' !== $path ) ? $path : null;
		self::$events_cache         = null;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Core bridge                                                                                  */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Keep core's measurement bridge emitting events but stop it calling any pixel itself.
	 *
	 * @param mixed $config Core's marketing config.
	 * @return array
	 */
	public static function filter_marketing_config( $config ) {
		if ( ! is_array( $config ) ) {
			$config = array();
		}
		if ( ! DoughBoss_Growth_Settings::enabled( 'consent_banner' ) ) {
			return $config;
		}
		$config['enabled']       = true;
		$config['metaPixelId']   = '';
		$config['tiktokPixelId'] = '';
		return $config;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Server-side reading of the consent cookie                                                    */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Validate a raw dbgr_consent cookie value. The same rules as the browser code: a JSON object with the
	 * wording version v equal to the current one, m and a each the integer 0 or 1, and ts a positive integer.
	 *
	 * @param mixed  $raw     Raw cookie value (PHP has already URL-decoded it; WordPress may have slashed it).
	 * @param string $version Current consent wording version.
	 * @return array|null array( 'm' => int, 'a' => int, 'ts' => int ) or null when absent or invalid.
	 */
	public static function parse_cookie( $raw, $version ) {
		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > self::COOKIE_MAX_LENGTH ) {
			return null;
		}
		$data = json_decode( wp_unslash( $raw ), true );
		if ( ! is_array( $data ) || ! isset( $data['v'], $data['m'], $data['a'], $data['ts'] ) ) {
			return null;
		}
		if ( ! is_string( $data['v'] ) || '' === $version || $data['v'] !== $version ) {
			return null;
		}
		if ( ! is_int( $data['m'] ) || ! is_int( $data['a'] ) || ! is_int( $data['ts'] ) ) {
			return null;
		}
		if ( ( 0 !== $data['m'] && 1 !== $data['m'] ) || ( 0 !== $data['a'] && 1 !== $data['a'] ) || $data['ts'] < 1 ) {
			return null;
		}
		return array(
			'm'  => $data['m'],
			'a'  => $data['a'],
			'ts' => $data['ts'],
		);
	}

	/**
	 * The visitor's current consent as the server can see it. Fails closed: with the banner off, or with no
	 * valid cookie in deny mode, everything is false.
	 *
	 * Keys: measurement (bool), advertising (bool), chosen (bool: a valid stored choice exists) and version
	 * (the consent wording version, "" when the banner is off). In notice-and-opt-out mode and before any
	 * choice, measurement is true and advertising false, exactly what the browser code applies.
	 *
	 * @return array
	 */
	public static function current() {
		$closed = array(
			'measurement' => false,
			'advertising' => false,
			'chosen'      => false,
			'version'     => '',
		);
		if ( ! DoughBoss_Growth_Settings::enabled( 'consent_banner' ) ) {
			return $closed;
		}
		$version = DoughBoss_Growth_Settings::get( 'consent_text_version', '' );
		if ( ! is_string( $version ) || '' === $version ) {
			return $closed;
		}
		$closed['version'] = $version;

		$raw    = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? $_COOKIE[ self::COOKIE_NAME ] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated field by field in parse_cookie().
		$stored = self::parse_cookie( $raw, $version );
		if ( null !== $stored ) {
			$closed['measurement'] = ( 1 === $stored['m'] );
			$closed['advertising'] = ( 1 === $stored['a'] );
			$closed['chosen']      = true;
			return $closed;
		}
		if ( 'opt_out' === DoughBoss_Growth_Settings::get( 'consent_default', 'deny' ) ) {
			$closed['measurement'] = true;
		}
		return $closed;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Browser configuration                                                                        */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Read and validate content/events.json (the export of web/src/lib/analytics/events.ts). Any problem
	 * returns an empty array, which leaves the dispatcher refusing every event (fail closed).
	 *
	 * @return array Event name => ( param name => array( type, values? ) ), or array().
	 */
	public static function events() {
		if ( null !== self::$events_cache ) {
			return self::$events_cache;
		}
		self::$events_cache = array();
		$path               = ( null !== self::$events_path_override ) ? self::$events_path_override : DOUGHBOSS_GROWTH_DIR . 'content/events.json';
		if ( ! is_file( $path ) ) {
			return self::$events_cache;
		}
		$text = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file inside the plugin.
		$data = is_string( $text ) ? json_decode( $text, true ) : null;
		self::$events_cache = self::validate_events( $data );
		return self::$events_cache;
	}

	/**
	 * Validate decoded events.json and reduce it to the browser shape.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array Event name => ( param => array( 'type' => ..., 'values' => ... ) ), or array() when invalid.
	 */
	public static function validate_events( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['schema_version'], $data['event_names'], $data['events'] ) || 1 !== $data['schema_version'] ) {
			return array();
		}
		if ( ! is_array( $data['event_names'] ) || ! is_array( $data['events'] ) || array() === $data['event_names'] ) {
			return array();
		}
		$name_pattern = '/^[a-z][a-z0-9_]{0,63}$/D';
		$reserved     = array( 'event', 'event_id', 'consent', 'gtm', 'eventCallback', 'eventTimeout' );
		$out          = array();
		foreach ( $data['event_names'] as $name ) {
			if ( ! is_string( $name ) || 1 !== preg_match( $name_pattern, $name ) || ! isset( $data['events'][ $name ]['params'] ) || ! is_array( $data['events'][ $name ]['params'] ) ) {
				return array();
			}
			$params = array();
			foreach ( $data['events'][ $name ]['params'] as $param => $spec ) {
				if ( ! is_string( $param ) || 1 !== preg_match( $name_pattern, $param ) || in_array( $param, $reserved, true ) || ! is_array( $spec ) || ! isset( $spec['type'] ) ) {
					return array();
				}
				if ( 'string' === $spec['type'] || 'integer' === $spec['type'] ) {
					$params[ $param ] = array( 'type' => $spec['type'] );
				} elseif ( 'enum' === $spec['type'] && isset( $spec['values'] ) && is_array( $spec['values'] ) && array() !== $spec['values'] ) {
					foreach ( $spec['values'] as $value ) {
						if ( ! is_string( $value ) && ! is_int( $value ) ) {
							return array();
						}
					}
					$params[ $param ] = array(
						'type'   => 'enum',
						'values' => array_values( $spec['values'] ),
					);
				} else {
					return array();
				}
			}
			$out[ $name ] = $params;
		}
		if ( count( $data['events'] ) !== count( $out ) ) {
			return array();
		}
		return $out;
	}

	/**
	 * Map of core shop ids to the analytics store slug (revesby, bankstown, roselands), only where the core
	 * shop's own slug is exactly one of those. A shop with any other slug stays unmapped and its events carry
	 * no store parameter (never a guess).
	 *
	 * @return array Id (string) => slug.
	 */
	public static function location_map() {
		$allowed = self::store_slugs();
		if ( array() === $allowed || ! class_exists( 'DoughBoss_Locations' ) || ! is_callable( array( 'DoughBoss_Locations', 'all' ) ) ) {
			return array();
		}
		$map = array();
		try {
			$rows = call_user_func( array( 'DoughBoss_Locations', 'all' ), true );
		} catch ( Throwable $e ) {
			return array();
		}
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as $row ) {
			$row  = is_object( $row ) ? get_object_vars( $row ) : ( is_array( $row ) ? $row : array() );
			$id   = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$slug = ( isset( $row['slug'] ) && is_string( $row['slug'] ) ) ? $row['slug'] : '';
			if ( $id > 0 && in_array( $slug, $allowed, true ) ) {
				$map[ (string) $id ] = $slug;
			}
		}
		return $map;
	}

	/**
	 * The store slugs defined by the taxonomy (the select_store.store enum).
	 *
	 * @return array
	 */
	public static function store_slugs() {
		$events = self::events();
		if ( isset( $events['select_store']['store']['values'] ) && is_array( $events['select_store']['store']['values'] ) ) {
			return array_values( array_filter( $events['select_store']['store']['values'], 'is_string' ) );
		}
		return array();
	}

	/**
	 * The configuration object printed before the browser scripts. No secret and no personal data.
	 *
	 * @return array
	 */
	public static function browser_config() {
		$version = DoughBoss_Growth_Settings::get( 'consent_text_version', '1' );
		$ready   = DoughBoss_Growth_Tags::ready();
		$config  = array(
			'consentVersion' => is_string( $version ) ? $version : '1',
			'mode'           => DoughBoss_Growth_Tags::mode(),
			'gtm'            => $ready,
		);
		// The event taxonomy and the shop map are only for the dataLayer script, which is only loaded when Tag Manager is on.
		if ( $ready ) {
			$config['events']    = (object) self::events();
			$config['locations'] = (object) self::location_map();
		}
		return $config;
	}

	/**
	 * Enqueue the banner style and the consent script, plus the dataLayer script only when Tag Manager is on and ready
	 * (front end only: this runs on wp_enqueue_scripts).
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! DoughBoss_Growth_Settings::enabled( 'consent_banner' ) ) {
			return;
		}
		$flags  = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		$config = json_encode( self::browser_config(), $flags );
		if ( ! is_string( $config ) ) {
			return; // Fail closed: no configuration, no scripts.
		}
		wp_enqueue_style( self::HANDLE_CONSENT, DOUGHBOSS_GROWTH_URL . 'public/css/dbgr-consent.css', array(), DOUGHBOSS_GROWTH_VERSION );
		wp_register_script( self::HANDLE_CONSENT, DOUGHBOSS_GROWTH_URL . 'public/js/dbgr-consent.js', array(), DOUGHBOSS_GROWTH_VERSION, true );
		wp_add_inline_script( self::HANDLE_CONSENT, 'window.DoughBossGrowthConfig = ' . $config . ';', 'before' );
		wp_enqueue_script( self::HANDLE_CONSENT );
		if ( DoughBoss_Growth_Tags::ready() ) {
			wp_enqueue_script( self::HANDLE_DATALAYER, DOUGHBOSS_GROWTH_URL . 'public/js/dbgr-datalayer.js', array( self::HANDLE_CONSENT ), DOUGHBOSS_GROWTH_VERSION, true );
		}
	}

	/* ------------------------------------------------------------------------------------------ */
	/* The banner                                                                                   */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Print the banner and the "Privacy choices" button (wp_footer). Hidden until the script decides.
	 *
	 * @return void
	 */
	public static function render_banner() {
		if ( is_admin() || ! DoughBoss_Growth_Settings::enabled( 'consent_banner' ) ) {
			return;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return;
		}
		$privacy = DoughBoss_Growth_Settings::get( 'privacy_policy_url', '' );

		echo '<div id="dbgr-consent" class="dbgr-consent" role="dialog" aria-modal="false" aria-labelledby="dbgr-consent-title" aria-describedby="dbgr-consent-desc" tabindex="-1" hidden>';
		echo '<div class="dbgr-consent__inner">';
		echo '<h2 id="dbgr-consent-title" class="dbgr-consent__title">' . esc_html__( 'Your privacy choices', 'doughboss-growth' ) . '</h2>';
		echo '<p id="dbgr-consent-desc" class="dbgr-consent__text">' . esc_html__( 'We use cookies to keep this site working, to understand how it is used and, if you agree, to measure our advertising. You can change your choice at any time with the Privacy choices button.', 'doughboss-growth' );
		if ( is_string( $privacy ) && '' !== $privacy ) {
			echo ' <a class="dbgr-consent__link" href="' . esc_url( $privacy ) . '">' . esc_html__( 'Read our privacy policy', 'doughboss-growth' ) . '</a>';
		}
		echo '</p>';

		echo '<div id="dbgr-consent-panel" class="dbgr-consent__panel" hidden>';
		echo '<fieldset class="dbgr-consent__fieldset"><legend class="dbgr-consent__legend">' . esc_html__( 'Cookie categories', 'doughboss-growth' ) . '</legend>';
		echo '<div class="dbgr-consent__row"><label class="dbgr-consent__label"><input type="checkbox" class="dbgr-consent__check" checked="checked" disabled="disabled" /> ' . esc_html__( 'Necessary: keeps the site working. Always on.', 'doughboss-growth' ) . '</label></div>';
		echo '<div class="dbgr-consent__row"><label class="dbgr-consent__label" for="dbgr-consent-m"><input type="checkbox" class="dbgr-consent__check" id="dbgr-consent-m" /> ' . esc_html__( 'Measurement: helps us understand how the site is used.', 'doughboss-growth' ) . '</label></div>';
		echo '<div class="dbgr-consent__row"><label class="dbgr-consent__label" for="dbgr-consent-a"><input type="checkbox" class="dbgr-consent__check" id="dbgr-consent-a" /> ' . esc_html__( 'Advertising: lets advertising services measure how our ads perform and tailor the ads they show.', 'doughboss-growth' ) . '</label></div>';
		echo '</fieldset>';
		echo '<button type="button" class="dbgr-btn" data-dbgr-action="save">' . esc_html__( 'Save choices', 'doughboss-growth' ) . '</button>';
		echo '</div>';

		echo '<div class="dbgr-consent__actions">';
		echo '<button type="button" class="dbgr-btn" data-dbgr-action="accept">' . esc_html__( 'Accept all', 'doughboss-growth' ) . '</button>';
		echo '<button type="button" class="dbgr-btn" data-dbgr-action="reject">' . esc_html__( 'Reject all', 'doughboss-growth' ) . '</button>';
		echo '<button type="button" class="dbgr-btn" data-dbgr-action="choose" aria-expanded="false" aria-controls="dbgr-consent-panel">' . esc_html__( 'Settings', 'doughboss-growth' ) . '</button>';
		echo '</div>';
		echo '<button type="button" class="dbgr-btn dbgr-consent__close" data-dbgr-action="close" hidden>' . esc_html__( 'Close', 'doughboss-growth' ) . '</button>';
		echo '</div></div>';

		echo '<button type="button" id="dbgr-consent-reopen" class="dbgr-consent-reopen" data-dbgr-consent-open="1" hidden>' . esc_html__( 'Privacy choices', 'doughboss-growth' ) . '</button>';
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Admin tab                                                                                    */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Add the "Consent and tags" tab. Called from the doughboss_growth_admin_tabs action.
	 *
	 * @return void
	 */
	public static function register_tab() {
		if ( class_exists( 'DoughBoss_Growth_Admin' ) ) {
			DoughBoss_Growth_Admin::add_tab( 'consent', __( 'Consent and tags', 'doughboss-growth' ), array( __CLASS__, 'render_tab' ) );
		}
	}

	/**
	 * The [CONFIRM] gaps and wiring notes for this module (admin-only text).
	 *
	 * @return array Code => text.
	 */
	public static function confirm_gaps() {
		$gaps = array();
		if ( '' === (string) DoughBoss_Growth_Settings::get( 'privacy_policy_url', '' ) ) {
			$gaps['privacy_policy_url'] = '[CONFIRM: privacy-policy URL. The banner shows no link to a policy until one is saved, and the policy must describe the tags before Tag Manager is switched on.]';
		}
		$gaps['wording'] = '[CONFIRM: Elie (and a solicitor) to review the banner wording and the three categories. Raise the consent wording version after any change so everyone is asked again.]';
		if ( DoughBoss_Growth_Settings::configured_features()['gtm'] && '' === DoughBoss_Growth_Tags::container_id() ) {
			$gaps['gtm_container_id'] = '[CONFIRM: a valid Tag Manager container id (GTM- followed by 4 to 10 capital letters or digits). Tag Manager stays off until one is saved.]';
		}
		if ( DoughBoss_Growth_Tags::MODE_OPT_OUT === DoughBoss_Growth_Tags::mode() ) {
			$gaps['opt_out'] = '[CONFIRM: notice-and-opt-out is selected. Before a visitor chooses, measurement is on and advertising stays off. Confirm this with your accountant or solicitor and the privacy policy.]';
		}
		$gaps['container'] = '[CONFIRM: inside the Tag Manager container, make GA4 require analytics_storage and the Google Ads and Meta tags require ad_storage, ad_user_data and ad_personalization. The plugin sets the signals; the container decides what fires.]';
		$gaps['begin_checkout'] = '[CONFIRM: core 2.43.2 does not tell the browser which payment method is chosen, so begin_checkout carries no payment_method until core sends one (a core change for 2.44.0).]';
		return $gaps;
	}

	/**
	 * Render the tab body.
	 *
	 * @return void
	 */
	public static function render_tab() {
		$tags     = DoughBoss_Growth_Tags::ready();
		$events   = self::events();
		$map      = self::location_map();
		$rows     = array();
		$rows[]   = array( __( 'Consent banner', 'doughboss-growth' ), DoughBoss_Growth_Settings::enabled( 'consent_banner' ) ? __( 'On', 'doughboss-growth' ) : __( 'Off', 'doughboss-growth' ) );
		$rows[]   = array( __( 'Tag Manager', 'doughboss-growth' ), $tags ? __( 'Loading container', 'doughboss-growth' ) . ' ' . DoughBoss_Growth_Tags::container_id() : __( 'Not loading', 'doughboss-growth' ) );
		$rows[]   = array( __( 'Consent default', 'doughboss-growth' ), DoughBoss_Growth_Tags::MODE_OPT_OUT === DoughBoss_Growth_Tags::mode() ? __( 'Notice and opt-out (measurement on, advertising off until chosen)', 'doughboss-growth' ) : __( 'Deny until chosen', 'doughboss-growth' ) );
		$rows[]   = array( __( 'Consent wording version', 'doughboss-growth' ), (string) DoughBoss_Growth_Settings::get( 'consent_text_version', '' ) );
		$rows[]   = array( __( 'Typed events available', 'doughboss-growth' ), array() === $events ? __( 'None (events.json is missing or invalid, so no event is sent)', 'doughboss-growth' ) : (string) count( $events ) );
		$rows[]   = array( __( 'Shops mapped to a store parameter', 'doughboss-growth' ), (string) count( $map ) );

		echo '<h2>' . esc_html__( 'Consent and tags', 'doughboss-growth' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><th scope="row">' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p>' . esc_html__( 'The noscript Tag Manager frame is deliberately not printed: visitors without JavaScript cannot be asked for consent.', 'doughboss-growth' ) . '</p>';
		echo '<h3>' . esc_html__( 'Owner decisions and wiring still outstanding', 'doughboss-growth' ) . '</h3><ul class="ul-disc">';
		foreach ( self::confirm_gaps() as $text ) {
			echo '<li>' . esc_html( DoughBoss_Growth_Admin::plain_gap( $text ) ) . '</li>';
		}
		echo '</ul>';
	}
}
