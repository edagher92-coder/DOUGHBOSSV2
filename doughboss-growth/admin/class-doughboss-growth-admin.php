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
		add_action( 'admin_init', array( 'DoughBoss_Growth_Activator', 'maybe_upgrade' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_storage_notice' ) );
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
	 * Handle the settings form. Capability AND nonce first; sanitising and the dependency rules are
	 * applied by DoughBoss_Growth_Settings::apply_save().
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
			'server_conversions_requires_attribution' => __( 'Server-side conversions need attribution on first, so they were left off.', 'doughboss-growth' ),
			'server_conversions_requires_destination' => __( 'Server-side conversions need at least one fully configured destination (an id with its secret, or a webhook URL), so they were left off.', 'doughboss-growth' ),
			'waitlist_requires_sender_legal_name'    => __( 'The waitlist needs the sender legal name first, so it was left off.', 'doughboss-growth' ),
			'waitlist_requires_privacy_policy_url'   => __( 'The waitlist needs the privacy-policy URL first, so it was left off.', 'doughboss-growth' ),
		);
	}

	/**
	 * One-line, neutral description of each feature (admin-only text).
	 *
	 * @return array
	 */
	public static function feature_labels() {
		return array(
			'consent_banner'     => array( __( 'Consent banner', 'doughboss-growth' ), __( 'Asks visitors for measurement and advertising consent.', 'doughboss-growth' ) ),
			'gtm'                => array( __( 'Tag Manager', 'doughboss-growth' ), __( 'Loads one Tag Manager container. Needs the consent banner.', 'doughboss-growth' ) ),
			'attribution'        => array( __( 'Attribution capture', 'doughboss-growth' ), __( 'Records where an enquiry or order came from, with consent.', 'doughboss-growth' ) ),
			'server_conversions' => array( __( 'Server-side conversions', 'doughboss-growth' ), __( 'Sends consented conversion events from the server.', 'doughboss-growth' ) ),
			'landing_pages'      => array( __( 'Landing pages', 'doughboss-growth' ), __( 'Pages built only from confirmed claims.', 'doughboss-growth' ) ),
			'seo_head'           => array( __( 'Search metadata', 'doughboss-growth' ), __( 'Title, description and structured data on companion pages.', 'doughboss-growth' ) ),
			'lead_form'          => array( __( 'Lead form', 'doughboss-growth' ), __( 'Enquiry form that posts to the existing catering enquiry.', 'doughboss-growth' ) ),
			'party_sizer'        => array( __( 'Quantity sizer', 'doughboss-growth' ), __( 'Helper that uses live catering packages.', 'doughboss-growth' ) ),
			'coming_soon'        => array( __( 'Coming-soon section', 'doughboss-growth' ), __( 'A neutral teaser section.', 'doughboss-growth' ) ),
			'waitlist'           => array( __( 'VIP waitlist', 'doughboss-growth' ), __( 'Collects consented sign-ups. Needs sender name and privacy-policy URL.', 'doughboss-growth' ) ),
			'timesheet_recon'    => array( __( 'Timesheet reconciliation', 'doughboss-growth' ), __( 'Read-only comparison of staff clock records.', 'doughboss-growth' ) ),
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
		$saved = isset( $_GET['dbgr_saved'] ) ? sanitize_key( wp_unslash( $_GET['dbgr_saved'] ) ) : '';
		$errs  = isset( $_GET['dbgr_err'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['dbgr_err'] ) ) ) : array();
		// phpcs:enable

		if ( '1' === $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'doughboss-growth' ) . '</p></div>';
		} elseif ( '0' === $saved ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Settings could not be saved.', 'doughboss-growth' ) . '</p></div>';
		}
		foreach ( $errs as $code ) {
			if ( isset( $messages[ $code ] ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( $messages[ $code ] ) . '</p></div>';
			}
		}

		echo '<h2>' . esc_html__( 'Status', 'doughboss-growth' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:640px"><tbody>';
		self::status_row( __( 'Companion version', 'doughboss-growth' ), DOUGHBOSS_GROWTH_VERSION );
		self::status_row( __( 'DoughBoss version', 'doughboss-growth' ), DoughBoss_Growth::core_version() );
		self::status_row( __( 'Database tables', 'doughboss-growth' ), DoughBoss_Growth_Activator::storage_ready() ? __( 'Ready', 'doughboss-growth' ) : __( 'Not confirmed', 'doughboss-growth' ) );
		self::status_row( __( 'Kill switch (DOUGHBOSS_GROWTH_DISABLE)', 'doughboss-growth' ), DoughBoss_Growth_Settings::kill_switch() ? __( 'On: everything is stopped', 'doughboss-growth' ) : __( 'Off', 'doughboss-growth' ) );
		echo '</tbody></table>';

		$gaps = DoughBoss_Growth_Settings::confirm_gaps();
		if ( array() !== $gaps ) {
			echo '<h2>' . esc_html__( 'Owner decisions still outstanding', 'doughboss-growth' ) . '</h2>';
			echo '<p>' . esc_html__( 'These do not stop the plugin installing. Each blocks enabling the feature that needs it.', 'doughboss-growth' ) . '</p><ul class="ul-disc">';
			foreach ( $gaps as $text ) {
				echo '<li>' . esc_html( $text ) . '</li>';
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
			echo DoughBoss_Growth_Settings::enabled( $feature ) ? esc_html__( '(Currently active.)', 'doughboss-growth' ) : esc_html__( '(Currently inactive.)', 'doughboss-growth' );
			echo '</span></p>';
		}
		echo '</fieldset>';

		echo '<h2>' . esc_html__( 'Settings', 'doughboss-growth' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::text_row( 'gtm_container_id', __( 'Tag Manager container id', 'doughboss-growth' ), $settings['gtm_container_id'], 'GTM-XXXXXXX' );
		self::text_row( 'ga4_measurement_id', __( 'GA4 measurement id', 'doughboss-growth' ), $settings['ga4_measurement_id'], 'G-XXXXXXXXXX' );
		self::text_row( 'meta_pixel_id', __( 'Meta pixel id', 'doughboss-growth' ), $settings['meta_pixel_id'], '' );
		self::text_row( 'consent_text_version', __( 'Consent wording version', 'doughboss-growth' ), $settings['consent_text_version'], '' );
		echo '<tr><th scope="row"><label for="dbgr-consent_default">' . esc_html__( 'Consent default', 'doughboss-growth' ) . '</label></th><td><select id="dbgr-consent_default" name="dbgr[consent_default]">';
		foreach ( DoughBoss_Growth_Settings::CONSENT_DEFAULTS as $value ) {
			echo '<option value="' . esc_attr( $value ) . '"' . ( $value === $settings['consent_default'] ? ' selected="selected"' : '' ) . '>' . esc_html( $value ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Shipped as deny: nothing is measured until the visitor chooses.', 'doughboss-growth' ) . '</p></td></tr>';
		self::text_row( 'sender_legal_name', __( 'Sender legal name', 'doughboss-growth' ), $settings['sender_legal_name'], '' );
		self::text_row( 'privacy_policy_url', __( 'Privacy-policy URL', 'doughboss-growth' ), $settings['privacy_policy_url'], 'https://' );
		self::text_row( 'notify_webhook_url', __( 'Notification webhook URL (https only)', 'doughboss-growth' ), $settings['notify_webhook_url'], 'https://' );
		self::check_row( 'send_hashed_identifiers', __( 'Send hashed identifiers', 'doughboss-growth' ), (bool) $settings['send_hashed_identifiers'], __( 'Leave off until the privacy policy covers it.', 'doughboss-growth' ) );
		self::check_row( 'seo_jsonld_with_seo_plugin', __( 'Structured data alongside an SEO plugin', 'doughboss-growth' ), (bool) $settings['seo_jsonld_with_seo_plugin'], '' );
		self::text_row( 'retention_pending_days', __( 'Delete unconfirmed waitlist rows after (days)', 'doughboss-growth' ), (string) $settings['retention_pending_days'], '' );
		self::text_row( 'retention_confirmed_months', __( 'Delete confirmed waitlist rows after (months)', 'doughboss-growth' ), null === $settings['retention_confirmed_months'] ? '' : (string) $settings['retention_confirmed_months'], '' );
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
	 * @return void
	 */
	private static function text_row( $key, $label, $value, $placeholder ) {
		$id = 'dbgr-' . $key;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="dbgr[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off" /></td></tr>';
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
