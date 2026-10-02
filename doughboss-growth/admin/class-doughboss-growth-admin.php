<?php
/**
 * DoughBoss Growth admin shell: settings page under the core DoughBoss menu.
 *
 * Every handler checks a capability AND a nonce. Modules add their own tabs by calling
 * DoughBoss_Growth_Admin::add_tab() from a callback hooked to the doughboss_growth_admin_tabs action.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI.
 */
final class DoughBoss_Growth_Admin {

	/**
	 * Menu slug of the settings page (a submenu of the core "doughboss" menu).
	 */
	const PAGE_SLUG = 'doughboss-growth';

	/**
	 * admin-post action and nonce action for saving settings.
	 */
	const ACTION_SAVE = 'doughboss_growth_save_settings';

	/**
	 * admin-post action and nonce action for clearing the recent failures list.
	 */
	const ACTION_CLEAR_FAILURES = 'doughboss_growth_clear_failures';

	/**
	 * Tabs registered by modules: slug => array( label, callback ).
	 *
	 * @var array
	 */
	private static $tabs = array();

	/**
	 * Hook the admin screens.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_CLEAR_FAILURES, array( __CLASS__, 'handle_clear_failures' ) );
		add_action( 'admin_init', array( 'DoughBoss_Growth_Activator', 'maybe_upgrade' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_storage_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_failures_notice' ) );
		if ( is_admin() ) {
			// The Settings link on the Plugins screen row. Registered inside wp-admin only: it has nothing to do on a public request.
			add_filter( 'plugin_action_links_' . plugin_basename( DOUGHBOSS_GROWTH_FILE ), array( __CLASS__, 'plugin_action_links' ) );
		}
	}

	/**
	 * Put a Settings link first in this plugin's row on the Plugins screen, for people who can manage it.
	 *
	 * @param mixed $links The row's existing action links.
	 * @return mixed
	 */
	public static function plugin_action_links( $links ) {
		if ( ! is_array( $links ) || ! self::user_can_manage() ) {
			return $links;
		}
		$url = add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'doughboss-growth' ) . '</a>' );
		return $links;
	}

	/**
	 * An owner-facing sentence from a stored "[CONFIRM: ...]" gap text: the bracket marker removed, the first letter
	 * capitalised, the owner's name replaced by "you" (a gap is written for the owner to read, not about him), and a full stop at
	 * the end. The stored text is left as it is (health() and the tests read it); only what is shown changes. A module's tab
	 * that lists its own gaps can run each one through this.
	 *
	 * @param mixed $text A gap text.
	 * @return string
	 */
	public static function plain_gap( $text ) {
		$text = trim( is_scalar( $text ) ? (string) $text : '' );
		if ( 1 === preg_match( '/^\[CONFIRM:?\s*(.*?)\]?$/Ds', $text, $match ) ) {
			$text = trim( $match[1] );
		}
		$text = (string) preg_replace( "/\bElie(?:'|\x{2019})s\b/u", 'your', $text );
		$text = (string) preg_replace( '/\bElie\b/u', 'you', $text );
		$text = (string) preg_replace( '/\byou (set|decide|approve|confirm|supply)s\b/', 'you $1', $text ); // "Elie sets it" reads "you set it".
		$text = str_replace( 'owned by the right entity', 'owned by the right business', $text );
		if ( '' === $text ) {
			return '';
		}
		$text = strtoupper( substr( $text, 0, 1 ) ) . substr( $text, 1 );
		return ( 1 === preg_match( '/[.!?]$/D', $text ) ) ? $text : $text . '.';
	}

	/**
	 * The capability required: core's manage_doughboss, falling back to manage_options exactly as
	 * the core admin does. No capability is created here.
	 *
	 * @return string
	 */
	public static function cap() {
		return current_user_can( 'manage_doughboss' ) ? 'manage_doughboss' : 'manage_options';
	}

	/**
	 * Whether the current user may manage the companion.
	 *
	 * @return bool
	 */
	public static function user_can_manage() {
		return current_user_can( self::cap() );
	}

	/**
	 * Register a tab. Call from a callback on the doughboss_growth_admin_tabs action.
	 *
	 * @param string   $slug     Tab slug (a-z, 0-9, dash, underscore).
	 * @param string   $label    Tab label.
	 * @param callable $callback Renders the tab body (echoes escaped output).
	 * @return bool
	 */
	public static function add_tab( $slug, $label, $callback ) {
		if ( ! is_string( $slug ) || 1 !== preg_match( '/^[a-z0-9_-]{1,30}$/D', $slug ) || 'settings' === $slug || ! is_callable( $callback ) ) {
			return false;
		}
		self::$tabs[ $slug ] = array(
			'label'    => (string) $label,
			'callback' => $callback,
		);
		return true;
	}

	/**
	 * Forget registered tabs (tests only).
	 *
	 * @return void
	 */
	public static function reset_tabs() {
		self::$tabs = array();
	}

	/**
	 * Add the submenu page.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			'doughboss',
			__( 'DoughBoss Growth', 'doughboss-growth' ),
			__( 'Growth', 'doughboss-growth' ),
			self::cap(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Notice when the schema is not installed (features that need storage stay inert).
	 *
	 * @return void
	 */
	public static function render_storage_notice() {
		if ( ! self::user_can_manage() || DoughBoss_Growth_Activator::storage_ready() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'DoughBoss Growth could not confirm its database tables. Features that store data stay off until the tables exist. Open DoughBoss, Growth to retry.', 'doughboss-growth' ) . '</p></div>';
	}

	/**
	 * Whether the current admin request is one of the Growth screens (the settings page or a module page whose slug starts
	 * with the settings page slug).
	 *
	 * @return bool
	 */
	private static function on_growth_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check, no state change.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return 0 === strpos( $page, self::PAGE_SLUG );
	}

	/**
	 * Notice on every Growth screen while failures are recorded, with a Clear button. Without it a module that threw, a
	 * table that could not be completed or a refused request left no trace on a site without WP_DEBUG_LOG.
	 *
	 * @return void
	 */
	public static function render_failures_notice() {
		if ( ! self::user_can_manage() || ! self::on_growth_screen() ) {
			return;
		}
		$count = DoughBoss_Growth_Failures::count();
		if ( $count < 1 ) {
			return;
		}
		$url = add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'DoughBoss Growth recorded problems that it could not show you at the time.', 'doughboss-growth' ) . '</strong> ';
		/* translators: %d: number of different problems recorded. */
		echo esc_html( sprintf( _n( '%d problem is listed under Recent failures.', '%d problems are listed under Recent failures.', $count, 'doughboss-growth' ), $count ) );
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Open the Settings tab', 'doughboss-growth' ) . '</a></p>';
		self::render_clear_form();
		echo '</div>';
	}

	/**
	 * The Clear button for the recent failures list: a POST form with a nonce.
	 *
	 * @return void
	 */
	private static function render_clear_form() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 .5em">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CLEAR_FAILURES ) . '" />';
		wp_nonce_field( self::ACTION_CLEAR_FAILURES );
		submit_button( __( 'Clear', 'doughboss-growth' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Handle the Clear button. Capability AND nonce first. The result shown afterwards is read back from storage, so
	 * "cleared" is only said when the list really is empty.
	 *
	 * @return void
	 */
	public static function handle_clear_failures() {
		if ( ! self::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'doughboss-growth' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_CLEAR_FAILURES );
		DoughBoss_Growth_Failures::clear();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE_SLUG,
					'dbgr_cleared' => ( 0 === DoughBoss_Growth_Failures::count() ) ? '1' : '0',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the settings form. Capability AND nonce first; sanitising and the dependency rules are
	 * applied by DoughBoss_Growth_Settings::apply_save(). The redirect carries what was saved, which
	 * dependency rules switched a feature off, and which typed values were refused or adjusted, so the
	 * notice that follows says what really happened rather than what was intended.
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! self::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'doughboss-growth' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_SAVE );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised field by field in DoughBoss_Growth_Settings::sanitize().
		$raw    = ( isset( $_POST['dbgr'] ) && is_array( $_POST['dbgr'] ) ) ? wp_unslash( $_POST['dbgr'] ) : array();
		$result = DoughBoss_Growth_Settings::apply_save( $raw );

		$args = array(
			'page'       => self::PAGE_SLUG,
			'dbgr_saved' => $result['saved'] ? '1' : '0',
		);
		if ( array() !== $result['errors'] ) {
			$args['dbgr_err'] = implode( ',', $result['errors'] );
		}
		if ( array() !== $result['refused'] ) {
			$args['dbgr_refused'] = implode( ',', $result['refused'] );
		}
		if ( array() !== $result['adjusted'] ) {
			$args['dbgr_adjusted'] = implode( ',', $result['adjusted'] );
		}
		if ( ! $result['saved'] ) {
			DoughBoss_Growth_Http::log( 'settings_save_failed', array( 'stage' => 'read_back' ) );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the page: the Settings tab plus any tabs modules added.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! self::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss-growth' ), '', array( 'response' => 403 ) );
		}
		do_action( 'doughboss_growth_admin_tabs' );

		$tabs = array( 'settings' => __( 'Settings', 'doughboss-growth' ) );
		foreach ( self::$tabs as $slug => $tab ) {
			$tabs[ $slug ] = $tab['label'];
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only tab selection.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( ! isset( $tabs[ $current ] ) ) {
			$current = 'settings';
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'DoughBoss Growth', 'doughboss-growth' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Growth sections', 'doughboss-growth' ) . '">';
		foreach ( $tabs as $slug => $label ) {
			$url   = add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => $slug,
				),
				admin_url( 'admin.php' )
			);
			$class = 'nav-tab' . ( $slug === $current ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '"' . ( $slug === $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		if ( 'settings' === $current ) {
			self::render_settings_tab();
		} else {
			call_user_func( self::$tabs[ $current ]['callback'] );
		}
		echo '</div>';
	}

	/**
	 * Human text for each dependency error code.
	 *
	 * @return array
	 */
	public static function error_messages() {
		return array(
			'gtm_requires_consent_banner'            => __( 'Tag Manager needs the consent banner on first, so it was left off.', 'doughboss-growth' ),
			'seo_head_requires_landing_pages'        => __( 'Search metadata needs the landing pages on first, so it was left off.', 'doughboss-growth' ),
			'server_conversions_requires_attribution' => __( 'Server-side conversions need attribution on first, so they were left off.', 'doughboss-growth' ),
			'server_conversions_requires_destination' => __( 'Server-side conversions need at least one fully configured destination (a GA4 or Meta id with its secret), so they were left off.', 'doughboss-growth' ),
			'waitlist_requires_sender_legal_name'    => __( 'The waitlist needs the sender legal name first, so it was left off.', 'doughboss-growth' ),
			'waitlist_requires_privacy_policy_url'   => __( 'The waitlist needs the privacy-policy URL first, so it was left off.', 'doughboss-growth' ),
		);
	}

	/**
	 * Labels of the fields apply_save() can refuse or adjust (the same wording as the form).
	 *
	 * @return array Field => label.
	 */
	public static function field_labels() {
		return array(
			'gtm_container_id'           => __( 'Tag Manager container id', 'doughboss-growth' ),
			'ga4_measurement_id'         => __( 'GA4 measurement id', 'doughboss-growth' ),
			'meta_pixel_id'              => __( 'Meta pixel id', 'doughboss-growth' ),
			'consent_text_version'       => __( 'Consent wording version', 'doughboss-growth' ),
			'consent_default'            => __( 'Consent default', 'doughboss-growth' ),
			'sender_legal_name'          => __( 'Sender legal name', 'doughboss-growth' ),
			'privacy_policy_url'         => __( 'Privacy-policy URL', 'doughboss-growth' ),
			'notify_webhook_url'         => __( 'Notification webhook URL', 'doughboss-growth' ),
			'retention_pending_days'     => __( 'Delete unconfirmed waitlist rows after (days)', 'doughboss-growth' ),
			'retention_confirmed_months' => __( 'Delete confirmed waitlist rows after (months)', 'doughboss-growth' ),
			'coming_soon_headline'       => __( 'Coming-soon headline', 'doughboss-growth' ),
			'coming_soon_body'           => __( 'Coming-soon text', 'doughboss-growth' ),
			'coming_soon_page_slug'      => __( 'Coming-soon page slug', 'doughboss-growth' ),
		);
	}

	/**
	 * What happened to a value that was typed but dropped, field by field. None of these says what the value was; the
	 * teaser lines never repeat a rejected word.
	 *
	 * @return array Field => sentence.
	 */
	public static function refusal_messages() {
		return array(
			'gtm_container_id'           => __( 'Tag Manager container id: that is not a valid id (it should look like GTM-XXXXXXX), so it was left blank. Tag Manager cannot load without one.', 'doughboss-growth' ),
			'ga4_measurement_id'         => __( 'GA4 measurement id: that is not a valid id (it should look like G-XXXXXXXXXX), so it was left blank.', 'doughboss-growth' ),
			'meta_pixel_id'              => __( 'Meta pixel id: that is not a valid id (digits only, 8 to 20 of them), so it was left blank.', 'doughboss-growth' ),
			'consent_text_version'       => __( 'Consent wording version: use letters, numbers, dots, dashes and underscores only, so the default (2) is used.', 'doughboss-growth' ),
			'consent_default'            => __( 'Consent default: that choice is not recognised, so "Ask first" is used.', 'doughboss-growth' ),
			'sender_legal_name'          => __( 'Sender legal name: that text was not accepted, so it was left blank.', 'doughboss-growth' ),
			'privacy_policy_url'         => __( 'Privacy-policy URL: that is not a usable address (use a full http or https address, or a path that starts with a slash), so it was left blank.', 'doughboss-growth' ),
			'notify_webhook_url'         => __( 'Notification webhook URL: that is not accepted (it must be a public https address), so it was left blank.', 'doughboss-growth' ),
			'retention_pending_days'     => __( 'Delete unconfirmed waitlist rows after (days): enter a whole number of 1 or more, so the default of 30 days is used.', 'doughboss-growth' ),
			'retention_confirmed_months' => __( 'Delete confirmed waitlist rows after (months): enter a whole number of 1 or more. Until you do, confirmed rows are never deleted automatically.', 'doughboss-growth' ),
			'coming_soon_headline'       => __( 'Coming-soon headline: that wording is not allowed, so the neutral headline is used.', 'doughboss-growth' ),
			'coming_soon_body'           => __( 'Coming-soon text: that wording is not allowed, so the neutral text is used.', 'doughboss-growth' ),
			'coming_soon_page_slug'      => __( 'Coming-soon page slug: that is not a usable address, so the default (coming-soon) is used.', 'doughboss-growth' ),
		);
	}

	/**
	 * The save notices that follow a redirect from handle_save(): what was refused, what was adjusted. Only field names
	 * apply_save() can return are honoured, whatever the query string says.
	 *
	 * @param array $refused  Field names.
	 * @param array $adjusted Field names.
	 * @return array List of sentences.
	 */
	private static function save_change_lines( array $refused, array $adjusted ) {
		$lines    = array();
		$known    = DoughBoss_Growth_Settings::NOTE_FIELDS;
		$messages = self::refusal_messages();
		$labels   = self::field_labels();
		foreach ( $refused as $field ) {
			if ( in_array( $field, $known, true ) && isset( $messages[ $field ] ) ) {
				$lines[] = $messages[ $field ];
			}
		}
		foreach ( $adjusted as $field ) {
			if ( in_array( $field, $known, true ) && isset( $labels[ $field ] ) ) {
				/* translators: %s: the name of a setting. */
				$lines[] = sprintf( __( '%s: the value was shortened, capped or cleaned to fit what is allowed. Check that the box shows what you meant.', 'doughboss-growth' ), $labels[ $field ] );
			}
		}
		return $lines;
	}

	/**
	 * Whether a feature is really working, not merely ticked.
	 *
	 * "off": the box is not ticked. "waiting": ticked, but something it needs is missing or a safety rule is holding it
	 * off (reasons say what). "not_running": ticked and allowed, yet its module did not start in this request (it threw or
	 * its file is missing: the Recent failures list has the detail). "active": ticked, allowed, its cheap runtime checks
	 * pass and every module that serves it is running. The checks are those the modules apply themselves; they read
	 * settings and hooks only and never start a module.
	 *
	 * @param string $feature Feature key.
	 * @return array { state: string, reasons: string[] }
	 */
	public static function feature_status( $feature ) {
		$settings = DoughBoss_Growth_Settings::get_all();
		if ( ! isset( $settings['features'][ $feature ] ) || true !== $settings['features'][ $feature ] ) {
			return array(
				'state'   => 'off',
				'reasons' => array(),
			);
		}
		if ( ! DoughBoss_Growth_Settings::enabled( $feature ) ) {
			return array(
				'state'   => 'waiting',
				'reasons' => self::blocked_reasons( $feature, $settings ),
			);
		}
		$gaps = self::runtime_gaps( $feature, $settings );
		if ( array() !== $gaps ) {
			return array(
				'state'   => 'waiting',
				'reasons' => $gaps,
			);
		}
		foreach ( DoughBoss_Growth::modules() as $key => $module ) {
			if ( in_array( $feature, $module['features'], true ) && ! DoughBoss_Growth::module_running( $key ) ) {
				return array(
					'state'   => 'not_running',
					'reasons' => array( (string) $key ),
				);
			}
		}
		return array(
			'state'   => 'active',
			'reasons' => array(),
		);
	}

	/**
	 * Why a ticked feature is not allowed to run: the kill switch, an unmet prerequisite, or another safety rule.
	 *
	 * @param string $feature  Feature key.
	 * @param array  $settings Sanitised settings.
	 * @return array Sentences (no full stop).
	 */
	private static function blocked_reasons( $feature, array $settings ) {
		if ( DoughBoss_Growth_Settings::kill_switch() ) {
			return array( __( 'the DOUGHBOSS_GROWTH_DISABLE switch in wp-config.php to be removed', 'doughboss-growth' ) );
		}
		$texts   = array(
			'gtm_requires_consent_banner'             => __( 'the consent banner to be on', 'doughboss-growth' ),
			'seo_head_requires_landing_pages'         => __( 'landing pages to be on', 'doughboss-growth' ),
			'server_conversions_requires_attribution' => __( 'attribution to be on', 'doughboss-growth' ),
			'server_conversions_requires_destination' => __( 'a fully configured destination (a GA4 or Meta id with its secret)', 'doughboss-growth' ),
			'waitlist_requires_sender_legal_name'     => __( 'the sender legal name', 'doughboss-growth' ),
			'waitlist_requires_privacy_policy_url'    => __( 'the privacy-policy URL', 'doughboss-growth' ),
		);
		$reasons = array();
		foreach ( DoughBoss_Growth_Settings::unmet_requirements( $feature, $settings, array( 'DoughBoss_Growth_Settings', 'enabled' ) ) as $code ) {
			if ( isset( $texts[ $code ] ) ) {
				$reasons[] = $texts[ $code ];
			}
		}
		if ( array() === $reasons ) {
			$reasons[] = __( 'a safety check that is holding it off (for example the claims ledger being valid)', 'doughboss-growth' );
		}
		return $reasons;
	}

	/**
	 * What an allowed feature still needs before it does anything visible. Each check is the one the feature's own module
	 * makes at run time, using settings, hooks and the module's class when it is already loaded.
	 *
	 * @param string $feature  Feature key.
	 * @param array  $settings Sanitised settings.
	 * @return array Sentences (no full stop).
	 */
	private static function runtime_gaps( $feature, array $settings ) {
		$gaps = array();
		foreach ( DoughBoss_Growth::modules() as $module ) {
			if ( ! empty( $module['needs_storage'] ) && in_array( $feature, $module['features'], true ) && ! DoughBoss_Growth_Activator::storage_ready() ) {
				$gaps[] = __( 'the database tables (see Database tables above)', 'doughboss-growth' );
				break;
			}
		}
		switch ( $feature ) {
			case 'gtm':
				if ( '' === $settings['gtm_container_id'] ) {
					$gaps[] = __( 'a valid Tag Manager container id', 'doughboss-growth' );
				} elseif ( class_exists( 'DoughBoss_Growth_Tags', false ) && ! DoughBoss_Growth_Tags::ready() ) {
					$gaps[] = __( 'the event list file, which is missing or invalid', 'doughboss-growth' );
				}
				break;
			case 'attribution':
				if ( ! DoughBoss_Growth_Settings::enabled( 'consent_banner' ) ) {
					$gaps[] = __( 'the consent banner to be on (nothing is captured without it)', 'doughboss-growth' );
				}
				break;
			case 'lead_form':
				$recording = array( 'DoughBoss_Growth_Attribution', 'on_enquiry_created' );
				if ( ! class_exists( 'DoughBoss_Growth_Attribution', false ) || false === has_filter( 'doughboss_catering_enquiry_created', $recording ) ) {
					$gaps[] = __( 'the lead record to be wired up, which did not start', 'doughboss-growth' );
				}
				break;
			case 'waitlist':
				if ( class_exists( 'DoughBoss_Growth_Waitlist', false ) && ! DoughBoss_Growth_Waitlist::configured() ) {
					$gaps[] = __( 'a sender legal name that passes the wording check, and a privacy-policy URL', 'doughboss-growth' );
				}
				break;
			case 'timesheet_recon':
				if ( ! DoughBoss_Growth_Settings::has_secret( 'DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN' ) ) {
					$gaps[] = __( 'a Square labour token (set DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN in wp-config.php)', 'doughboss-growth' );
				}
				break;
		}
		return $gaps;
	}

	/**
	 * The label after a feature description: what is true now, not what is ticked.
	 *
	 * @param string $feature Feature key.
	 * @return string
	 */
	private static function feature_state_text( $feature ) {
		$status = self::feature_status( $feature );
		if ( 'active' === $status['state'] ) {
			return __( '(Currently active.)', 'doughboss-growth' );
		}
		if ( 'waiting' === $status['state'] ) {
			/* translators: %s: what the feature is waiting for. */
			return sprintf( __( '(On, waiting for: %s.)', 'doughboss-growth' ), implode( '; ', $status['reasons'] ) );
		}
		if ( 'not_running' === $status['state'] ) {
			return __( '(On, but not running: see Recent failures above.)', 'doughboss-growth' );
		}
		return __( '(Currently inactive.)', 'doughboss-growth' );
	}

	/**
	 * The Database tables status: Ready only when the version is stored AND every expected table and column is there.
	 * This runs a check per table, so it runs only while the Settings tab is open.
	 *
	 * @return string
	 */
	private static function database_status() {
		$problems = DoughBoss_Growth_Activator::schema_problems();
		if ( array() !== $problems ) {
			/* translators: %s: names of the missing or incomplete database tables. */
			return sprintf( __( 'Not complete: %s. Features that store data stay off until this is fixed. Open this page again to retry.', 'doughboss-growth' ), implode( ', ', array_slice( $problems, 0, 8 ) ) );
		}
		if ( DoughBoss_Growth_Activator::storage_ready() && ! DoughBoss_Growth_Activator::schema_current() ) {
			// An older release's tables, still usable, whose upgrade has not been recorded yet (it runs when this page is opened).
			return __( 'Working, but the upgrade of the database tables has not finished. Open this page again to retry.', 'doughboss-growth' );
		}
		return DoughBoss_Growth_Activator::storage_ready() ? __( 'Ready', 'doughboss-growth' ) : __( 'Not confirmed', 'doughboss-growth' );
	}

	/**
	 * A plain sentence for a recorded failure code, for the Recent failures row. Known codes get their own sentence, a
	 * family of codes (the same module or kind of problem) gets a shared one, and anything else gets a generic sentence:
	 * the row never shows a bare code with no explanation. The sentences say what could not be done, never what was in
	 * the data, and promise nothing about what happened next.
	 *
	 * @param mixed $code A failure code from DoughBoss_Growth_Failures.
	 * @return string
	 */
	public static function failure_sentence( $code ) {
		$code  = is_string( $code ) ? $code : '';
		$exact = array(
			'module_load_failed'              => __( 'A part of the plugin could not start, so the feature it serves is not running. The rest of the site is not affected.', 'doughboss-growth' ),
			'module_failed'                   => __( 'A part of the plugin hit an error and was stopped, so the feature it serves is not running. The rest of the site is not affected.', 'doughboss-growth' ),
			'schema_failed'                   => __( 'The plugin\'s database tables could not be created or updated, so features that store data stay off.', 'doughboss-growth' ),
			'schema_install_failed'           => __( 'The plugin\'s database tables could not be created or updated, so features that store data stay off.', 'doughboss-growth' ),
			'schema_version_save_failed'      => __( 'The plugin could not note that its database tables are up to date, so it will check them again.', 'doughboss-growth' ),
			'settings_version_save_failed'    => __( 'The plugin could not note which version its settings are on.', 'doughboss-growth' ),
			'settings_migrate_failed'         => __( 'Settings saved by an older version could not be brought up to date.', 'doughboss-growth' ),
			'settings_save_failed'            => __( 'Your settings could not be saved. Please try again.', 'doughboss-growth' ),
			'coming_soon_save_failed'         => __( 'The coming-soon ribbon switch could not be saved.', 'doughboss-growth' ),
			'waitlist_signup_storage_failed'  => __( 'A VIP list sign-up could not be saved.', 'doughboss-growth' ),
			'waitlist_signup_mail_failed'     => __( 'A confirmation email for a VIP list sign-up could not be sent.', 'doughboss-growth' ),
			'waitlist_signup_token_failed'    => __( 'The VIP list form could not prepare its spam check.', 'doughboss-growth' ),
			'waitlist_optout_failed'          => __( 'A request to leave the VIP list could not be processed.', 'doughboss-growth' ),
			'waitlist_limiter_failed'         => __( 'The spam limit on the VIP list form could not be checked.', 'doughboss-growth' ),
			'waitlist_purge_failed'           => __( 'The daily clean-up of old VIP list entries did not finish.', 'doughboss-growth' ),
			'waitlist_csv_export_failed'      => __( 'The VIP list export could not be produced.', 'doughboss-growth' ),
			'waitlist_erase_failed'           => __( 'A privacy request to erase a VIP list entry did not complete.', 'doughboss-growth' ),
			'waitlist_privacy_export_failed'  => __( 'A privacy request to export a VIP list entry did not complete.', 'doughboss-growth' ),
			'waitlist_webhook_enqueue'        => __( 'A VIP list notification could not be queued.', 'doughboss-growth' ),
			'landing_record_failed'           => __( 'A landing page could not be recorded.', 'doughboss-growth' ),
			'landing_create_failed'           => __( 'A landing page could not be created.', 'doughboss-growth' ),
			'landing_compose_failed'          => __( 'A landing page could not be put together, so it was not shown.', 'doughboss-growth' ),
			'conversion_enqueue_failed'       => __( 'A conversion could not be queued for sending.', 'doughboss-growth' ),
			'conversion_read_failed'          => __( 'A conversion record could not be read.', 'doughboss-growth' ),
			'conversion_payload_refused'      => __( 'A conversion was held back because it did not pass the plugin\'s safety checks.', 'doughboss-growth' ),
			'attribution_read_failed'         => __( 'Source tracking records could not be read.', 'doughboss-growth' ),
			'attribution_write_failed'        => __( 'A source tracking record could not be saved.', 'doughboss-growth' ),
			'recon_run_failed'                => __( 'A clock-in reconciliation run did not finish.', 'doughboss-growth' ),
			'recon_failed'                    => __( 'A clock-in reconciliation step did not finish.', 'doughboss-growth' ),
			'http_transport_error'            => __( 'The site could not reach an outside service.', 'doughboss-growth' ),
			'http_status_error'               => __( 'An outside service answered with an error.', 'doughboss-growth' ),
			'http_failed'                     => __( 'A request to an outside service failed.', 'doughboss-growth' ),
			'http_refused'                    => __( 'A request to an outside service was not sent because it did not pass the plugin\'s safety checks.', 'doughboss-growth' ),
		);
		if ( isset( $exact[ $code ] ) ) {
			return $exact[ $code ];
		}
		$families = array(
			'waitlist_'    => __( 'Something went wrong with the VIP list.', 'doughboss-growth' ),
			'landing_'     => __( 'Something went wrong with a landing page.', 'doughboss-growth' ),
			'conversion'   => __( 'Something went wrong with server-side conversions.', 'doughboss-growth' ),
			'outbox_'      => __( 'Something went wrong with the queue of messages waiting to be sent.', 'doughboss-growth' ),
			'attribution_' => __( 'Something went wrong with source tracking.', 'doughboss-growth' ),
			'recon_'       => __( 'Something went wrong with clock-in reconciliation.', 'doughboss-growth' ),
			'square_'      => __( 'Square did not answer a request as expected.', 'doughboss-growth' ),
			'schema_'      => __( 'Something went wrong with the plugin\'s database tables.', 'doughboss-growth' ),
			'settings_'    => __( 'Something went wrong saving settings.', 'doughboss-growth' ),
			'http_'        => __( 'A request to an outside service did not go through.', 'doughboss-growth' ),
		);
		foreach ( $families as $prefix => $sentence ) {
			if ( 0 === strpos( $code, $prefix ) ) {
				return $sentence;
			}
		}
		return __( 'Something went wrong in the background. The code beside this message is what to quote if you ask for help.', 'doughboss-growth' );
	}

	/**
	 * The Recent failures status row, with a Clear button when there is something to clear.
	 *
	 * @return void
	 */
	private static function render_failures_row() {
		$failures = DoughBoss_Growth_Failures::all();
		echo '<tr><th scope="row">' . esc_html__( 'Recent failures', 'doughboss-growth' ) . '</th><td>';
		if ( array() === $failures ) {
			echo esc_html__( 'None recorded', 'doughboss-growth' ) . '</td></tr>';
			return;
		}
		echo '<ul style="margin:0 0 .5em">';
		foreach ( $failures as $failure ) {
			$when    = ( $failure['last_seen'] > 0 ) ? wp_date( 'j M Y, g:i a', $failure['last_seen'] ) : __( 'an unknown time', 'doughboss-growth' );
			$details = array();
			foreach ( $failure['context'] as $key => $value ) {
				$details[] = $key . ': ' . $value;
			}
			/* translators: 1: number of times, 2: date and time the failure was last seen. */
			$line = sprintf( _n( '%1$d time, last seen %2$s', '%1$d times, last seen %2$s', $failure['count'], 'doughboss-growth' ), $failure['count'], $when );
			// A plain sentence first; the raw code and the counts stay as small secondary text for whoever supports the site.
			echo '<li>' . esc_html( self::failure_sentence( $failure['code'] ) ) . ' <span class="description"><code>' . esc_html( $failure['code'] ) . '</code> ' . esc_html( $line ) . ( array() !== $details ? ' (' . esc_html( implode( ', ', $details ) ) . ')' : '' ) . '</span></li>';
		}
		echo '</ul>';
		self::render_clear_form();
		echo '</td></tr>';
	}

	/**
	 * One-line, neutral description of each feature (admin-only text), plus what to settle before switching it on.
	 *
	 * Element 0 is the name, 1 the description and 2 the "Before you switch this on" note shown under the checkbox on the
	 * Settings tab. The notes are the owner gates from the release runbook (docs/RELEASE-0.1.0.md, section 4) put where the
	 * owner works: a module's own tab only exists after its feature is on, and the docs folder is not in the plugin zip.
	 * Written for the owner: no setting names, codes or file names that mean nothing to them.
	 *
	 * @return array Feature => array( name, description, before-you-switch-on note ).
	 */
	public static function feature_labels() {
		return array(
			'consent_banner'     => array(
				__( 'Consent banner', 'doughboss-growth' ),
				__( 'Asks visitors for measurement and advertising consent.', 'doughboss-growth' ),
				__( 'Add your privacy-policy URL below and make sure the policy describes the cookies and tags your site uses. Have a solicitor check the banner wording and the three choices. Whenever you change the wording, raise the consent wording version so everyone is asked again. Leave the consent default on "Ask first" unless you have legal advice.', 'doughboss-growth' ),
			),
			'gtm'                => array(
				__( 'Tag Manager', 'doughboss-growth' ),
				__( 'Loads one Tag Manager container. Needs the consent banner.', 'doughboss-growth' ),
				__( 'You need a Tag Manager container id from an account owned by the right business. Inside the container, Google Analytics must wait for analytics consent, and the Google Ads and Meta tags must wait for advertising consent. This plugin sends the consent signals; the container decides what fires. Your privacy policy must describe the tags.', 'doughboss-growth' ),
			),
			'attribution'        => array(
				__( 'Attribution capture', 'doughboss-growth' ),
				__( 'Records where an enquiry or order came from, with consent.', 'doughboss-growth' ),
				__( 'Decide first how long these records are kept, and whether you need a way to export or erase a person\'s records. Nothing is deleted automatically, and these records are not covered by the WordPress personal-data tools, so removing one today needs someone with database access. Talk to your accountant or solicitor. Your privacy policy must describe the cookie that remembers where a visitor came from (kept for 90 days) and say that the source of an enquiry or order is stored with it. Nothing is recorded unless the consent banner is on.', 'doughboss-growth' ),
			),
			'server_conversions' => array(
				__( 'Server-side conversions', 'doughboss-growth' ),
				__( 'Sends consented conversion events from the server.', 'doughboss-growth' ),
				__( 'Add the account ids on this page and the secret keys in wp-config.php (never on this page), from accounts owned by the right business. Your privacy policy must say that order and enquiry details, and advertising identifiers where the visitor agreed, are sent from the server to Google and Meta. Choose one place to count leads in Google Analytics: the server and the Tag Manager tag both send them, so leads are counted twice unless you drop one. Check each provider\'s own documentation before relying on it, and leave "Send hashed identifiers" off until your privacy policy covers it.', 'doughboss-growth' ),
			),
			'landing_pages'      => array(
				__( 'Landing pages', 'doughboss-growth' ),
				__( 'Pages built only from confirmed claims.', 'doughboss-growth' ),
				__( 'Decide which claims you are happy to publish (lead time, service area, delivery or drop-off, dietary status, catering phone line, reviews, how the food is made). Until you confirm them the catering pages carry no claims and are hidden from search engines. The plugin only creates drafts; you publish each page yourself. If a shop is not found the page shows nothing and the Landing pages tab says why.', 'doughboss-growth' ),
			),
			'seo_head'           => array(
				__( 'Search metadata', 'doughboss-growth' ),
				__( 'Title, description and structured data on companion pages.', 'doughboss-growth' ),
				__( 'Check each page\'s title and description under the Landing pages tab before you publish it. If an SEO plugin such as Yoast or Rank Math is active, this plugin prints no search tags unless you tick the structured-data option below, so type the title and description into the SEO plugin instead.', 'doughboss-growth' ),
			),
			'lead_form'          => array(
				__( 'Lead form', 'doughboss-growth' ),
				__( 'Enquiry form that posts to the existing catering enquiry.', 'doughboss-growth' ),
				__( 'Enter your sender legal name first: until it is set, the marketing tick box is not shown and no marketing consent can be collected (the enquiry itself still works). Have a solicitor approve the marketing consent wording. Ticking the box only records consent; whatever sends marketing later must honour it and give a working unsubscribe. Update your privacy policy to say what an enquiry stores (company, type of customer, where it came from, and consent). Decide how long lead records are kept and whether people can ask for them to be erased: nothing is deleted automatically, and these records are not covered by the WordPress personal-data tools. Exclude the pages that hold the form from page caching on your host before switching on, or a visitor can be asked to refresh the page.', 'doughboss-growth' ),
			),
			'party_sizer'        => array(
				__( 'Quantity sizer', 'doughboss-growth' ),
				__( 'Helper that uses live catering packages.', 'doughboss-growth' ),
				__( 'Exclude the pages that use the sizer from page caching on your host before switching it on: a cached copy that is too old makes a visitor see a "please refresh" message. The sizer shows only the packages, serve ranges and prices that DoughBoss holds. Guidance on pieces per guest stays hidden until a confirmed claim is in the claims ledger.', 'doughboss-growth' ),
			),
			'coming_soon'        => array(
				__( 'Coming-soon section', 'doughboss-growth' ),
				__( 'A neutral teaser section.', 'doughboss-growth' ),
				__( 'Needs a valid claims ledger. Keep the headline and text general: no product, price, size, dietary claim, date or place. Wording like that is refused and the neutral default is used instead.', 'doughboss-growth' ),
			),
			'waitlist'           => array(
				__( 'VIP waitlist', 'doughboss-growth' ),
				__( 'Collects consented sign-ups. Needs sender name and privacy-policy URL.', 'doughboss-growth' ),
				__( 'Enter the sender legal name (and ABN if shown) and the privacy-policy URL first: the waitlist will not switch on without them. Decide how long confirmed sign-ups are kept: until you do, they are never deleted automatically. Have a solicitor approve the consent wording; it covers email, and text messages only if a mobile number is given (this plugin sends no text messages). Check that the confirmation email really arrives, because mail settings on your host can send it to spam. If your host puts every visitor behind one address, the limit of five sign-ups an hour is shared by everyone; ask your developer to supply the real visitor address.', 'doughboss-growth' ),
			),
			'timesheet_recon'    => array(
				__( 'Timesheet reconciliation', 'doughboss-growth' ),
				__( 'Read-only comparison of staff clock records.', 'doughboss-growth' ),
				__( 'Before you add the Square labour token, confirm that you approve reading Square staff data (it is personal data) and who owns the Square merchant account. Set every tolerance on the Timesheet check screen: none is built in, and until you set them each check shows as unrated and never as matched. Decide which record counts for pay when the two disagree; the report never decides.', 'doughboss-growth' ),
			),
		);
	}

	/**
	 * Settings tab.
	 *
	 * @return void
	 */
	private static function render_settings_tab() {
		$settings = DoughBoss_Growth_Settings::get_all();
		$messages = self::error_messages();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only result flags after a redirect.
		$saved    = isset( $_GET['dbgr_saved'] ) ? sanitize_key( wp_unslash( $_GET['dbgr_saved'] ) ) : '';
		$errs     = isset( $_GET['dbgr_err'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['dbgr_err'] ) ) ) : array();
		$refused  = isset( $_GET['dbgr_refused'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['dbgr_refused'] ) ) ) : array();
		$adjusted = isset( $_GET['dbgr_adjusted'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['dbgr_adjusted'] ) ) ) : array();
		$cleared  = isset( $_GET['dbgr_cleared'] ) ? sanitize_key( wp_unslash( $_GET['dbgr_cleared'] ) ) : '';
		// phpcs:enable

		$changes = self::save_change_lines( $refused, $adjusted );
		if ( '1' === $saved && array() === $changes ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'doughboss-growth' ) . '</p></div>';
		} elseif ( '1' === $saved ) {
			// Saved, but not everything typed survived: say so instead of a plain "Settings saved."
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Settings saved, but not everything you typed was kept:', 'doughboss-growth' ) . '</strong></p><ul class="ul-disc">';
			foreach ( $changes as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul></div>';
		} elseif ( '0' === $saved ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Settings could not be saved.', 'doughboss-growth' ) . '</p></div>';
		}
		foreach ( $errs as $code ) {
			if ( isset( $messages[ $code ] ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( $messages[ $code ] ) . '</p></div>';
			}
		}
		if ( '1' === $cleared ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Recent failures cleared.', 'doughboss-growth' ) . '</p></div>';
		} elseif ( '0' === $cleared ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The recent failures could not be cleared.', 'doughboss-growth' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Status', 'doughboss-growth' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:640px"><tbody>';
		self::status_row( __( 'Companion version', 'doughboss-growth' ), DOUGHBOSS_GROWTH_VERSION );
		self::status_row( __( 'DoughBoss version', 'doughboss-growth' ), DoughBoss_Growth::core_version() );
		self::status_row( __( 'Database tables', 'doughboss-growth' ), self::database_status() );
		self::status_row( __( 'Kill switch (DOUGHBOSS_GROWTH_DISABLE)', 'doughboss-growth' ), DoughBoss_Growth_Settings::kill_switch() ? __( 'On: everything is stopped', 'doughboss-growth' ) : __( 'Off', 'doughboss-growth' ) );
		self::render_failures_row();
		echo '</tbody></table>';

		$gaps = DoughBoss_Growth_Settings::confirm_gaps();
		if ( array() !== $gaps ) {
			echo '<h2>' . esc_html__( 'To decide before switching on', 'doughboss-growth' ) . '</h2>';
			echo '<p>' . esc_html__( 'The sender legal name and the privacy-policy URL must be filled in before the waitlist will switch on. Nothing else here stops a feature switching on, but each item is a decision to make before you rely on the feature it belongs to. The note under each switch below says what else to settle first.', 'doughboss-growth' ) . '</p><ul class="ul-disc">';
			foreach ( $gaps as $text ) {
				echo '<li>' . esc_html( self::plain_gap( $text ) ) . '</li>';
			}
			echo '</ul>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '" />';
		wp_nonce_field( self::ACTION_SAVE );

		echo '<h2>' . esc_html__( 'Features', 'doughboss-growth' ) . '</h2>';
		echo '<p>' . esc_html__( 'Every feature is off until you switch it on. A feature whose prerequisites are missing is left off when you save.', 'doughboss-growth' ) . '</p>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Features', 'doughboss-growth' ) . '</legend>';
		foreach ( self::feature_labels() as $feature => $copy ) {
			$id = 'dbgr-feature-' . $feature;
			echo '<p><label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="dbgr[features][' . esc_attr( $feature ) . ']" value="1"' . ( true === $settings['features'][ $feature ] ? ' checked="checked"' : '' ) . ' /> <strong>' . esc_html( $copy[0] ) . '</strong></label>';
			echo '<br /><span class="description">' . esc_html( $copy[1] ) . ' ';
			echo esc_html( self::feature_state_text( $feature ) );
			echo '</span>';
			if ( isset( $copy[2] ) && '' !== $copy[2] ) {
				echo '<br /><span class="description"><strong>' . esc_html__( 'Before you switch this on:', 'doughboss-growth' ) . '</strong> ' . esc_html( $copy[2] ) . '</span>';
			}
			echo '</p>';
		}
		echo '</fieldset>';

		echo '<h2>' . esc_html__( 'Settings', 'doughboss-growth' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::text_row( 'gtm_container_id', __( 'Tag Manager container id', 'doughboss-growth' ), $settings['gtm_container_id'], 'GTM-XXXXXXX' );
		self::text_row( 'ga4_measurement_id', __( 'GA4 measurement id', 'doughboss-growth' ), $settings['ga4_measurement_id'], 'G-XXXXXXXXXX' );
		self::text_row( 'meta_pixel_id', __( 'Meta pixel id', 'doughboss-growth' ), $settings['meta_pixel_id'], '' );
		self::text_row( 'consent_text_version', __( 'Consent wording version', 'doughboss-growth' ), $settings['consent_text_version'], '', __( 'Raise this (for example from 2 to 3) whenever you change the banner wording, so every visitor is asked again.', 'doughboss-growth' ) );
		$consent_labels = self::consent_default_labels();
		echo '<tr><th scope="row"><label for="dbgr-consent_default">' . esc_html__( 'Consent default', 'doughboss-growth' ) . '</label></th><td><select id="dbgr-consent_default" name="dbgr[consent_default]">';
		foreach ( DoughBoss_Growth_Settings::CONSENT_DEFAULTS as $value ) {
			$label = isset( $consent_labels[ $value ] ) ? $consent_labels[ $value ] : __( 'Another choice', 'doughboss-growth' );
			echo '<option value="' . esc_attr( $value ) . '"' . ( $value === $settings['consent_default'] ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Ask first is the shipped choice: nothing is measured until the visitor chooses. Notice and opt-out measures visits before the visitor chooses, so get legal advice before using it.', 'doughboss-growth' ) . '</p></td></tr>';
		self::text_row( 'sender_legal_name', __( 'Sender legal name', 'doughboss-growth' ), $settings['sender_legal_name'], '' );
		self::text_row( 'privacy_policy_url', __( 'Privacy-policy URL', 'doughboss-growth' ), $settings['privacy_policy_url'], 'https://' );
		self::text_row( 'notify_webhook_url', __( 'Notification webhook URL (https only)', 'doughboss-growth' ), $settings['notify_webhook_url'], 'https://', __( 'Optional. A secure (https) address that is sent a short signed note, with no name or email address in it, each time someone confirms their waitlist sign-up. It also needs the webhook secret set in wp-config.php. Leave it blank if you do not use one.', 'doughboss-growth' ) );
		self::check_row( 'send_hashed_identifiers', __( 'Send hashed identifiers', 'doughboss-growth' ), (bool) $settings['send_hashed_identifiers'], __( 'Leave off until the privacy policy covers it.', 'doughboss-growth' ) );
		self::check_row( 'seo_jsonld_with_seo_plugin', __( 'Structured data alongside an SEO plugin', 'doughboss-growth' ), (bool) $settings['seo_jsonld_with_seo_plugin'], '' );
		self::text_row( 'retention_pending_days', __( 'Delete unconfirmed waitlist rows after (days)', 'doughboss-growth' ), (string) $settings['retention_pending_days'], '', __( 'How many days someone who has not confirmed their email stays on the list before they are deleted. A whole number from 1 to 365; the starting value is 30.', 'doughboss-growth' ) );
		self::text_row( 'retention_confirmed_months', __( 'Delete confirmed waitlist rows after (months)', 'doughboss-growth' ), null === $settings['retention_confirmed_months'] ? '' : (string) $settings['retention_confirmed_months'], '', __( 'How many months a confirmed sign-up is kept, from 1 to 120. Leave it blank and confirmed sign-ups are never deleted automatically. Talk to your accountant or solicitor before choosing.', 'doughboss-growth' ) );
		self::text_row( 'coming_soon_headline', __( 'Coming-soon headline', 'doughboss-growth' ), $settings['coming_soon_headline'], '' );
		self::text_row( 'coming_soon_body', __( 'Coming-soon text', 'doughboss-growth' ), $settings['coming_soon_body'], '' );
		self::text_row( 'coming_soon_page_slug', __( 'Coming-soon page slug', 'doughboss-growth' ), $settings['coming_soon_page_slug'], '' );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Secrets', 'doughboss-growth' ) . '</h2>';
		echo '<p>' . esc_html__( 'Secrets are read from environment variables or wp-config.php constants only. They are never stored here and never shown. Presence only:', 'doughboss-growth' ) . '</p><ul class="ul-disc">';
		foreach ( DoughBoss_Growth_Settings::SECRET_NAMES as $name ) {
			echo '<li><code>' . esc_html( $name ) . '</code>: ' . ( DoughBoss_Growth_Settings::has_secret( $name ) ? esc_html__( 'set', 'doughboss-growth' ) : esc_html__( 'not set', 'doughboss-growth' ) ) . '</li>';
		}
		echo '</ul>';

		submit_button( __( 'Save settings', 'doughboss-growth' ) );
		echo '</form>';
	}

	/**
	 * One read-only status row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return void
	 */
	private static function status_row( $label, $value ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( '' === $value ? __( 'Not loaded', 'doughboss-growth' ) : $value ) . '</td></tr>';
	}

	/**
	 * One text input row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Label.
	 * @param string $value       Value.
	 * @param string $placeholder Placeholder.
	 * @param string $help        Optional plain-words help shown under the box.
	 * @return void
	 */
	private static function text_row( $key, $label, $value, $placeholder, $help = '' ) {
		$id   = 'dbgr-' . $key;
		$desc = ( '' !== $help ) ? ' aria-describedby="' . esc_attr( $id . '-help' ) . '"' : '';
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="dbgr[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off"' . $desc . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $desc is built from an escaped id.
		if ( '' !== $help ) {
			echo '<p class="description" id="' . esc_attr( $id . '-help' ) . '">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * What the owner sees for each stored consent default. The stored values stay as they are ("deny", "opt_out").
	 *
	 * @return array Stored value => label.
	 */
	public static function consent_default_labels() {
		return array(
			'deny'    => __( 'Ask first (recommended)', 'doughboss-growth' ),
			'opt_out' => __( 'Notice and opt-out (needs legal advice)', 'doughboss-growth' ),
		);
	}

	/**
	 * One checkbox row.
	 *
	 * @param string $key     Setting key.
	 * @param string $label   Label.
	 * @param bool   $checked Whether ticked.
	 * @param string $help    Help text.
	 * @return void
	 */
	private static function check_row( $key, $label, $checked, $help ) {
		$id = 'dbgr-' . $key;
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="dbgr[' . esc_attr( $key ) . ']" value="1"' . ( $checked ? ' checked="checked"' : '' ) . ' /> ' . esc_html( $label ) . '</label>';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}
}
