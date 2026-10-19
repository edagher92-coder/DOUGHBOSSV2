<?php
/**
 * DoughBoss › Square accounts — connect each shop's own Square seller account.
 *
 * Owner-only (manage_options): this page handles money credentials. Every
 * action is a nonce-checked POST to admin-post.php except the OAuth return,
 * which arrives as a GET bounce from the REST callback and is verified by the
 * signed, single-use state instead.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI for DoughBoss_Square_OAuth and DoughBoss_Square_Catalog_Copy.
 */
class DoughBoss_Square_Accounts_Admin {

	const PAGE = 'doughboss-square-accounts';

	/** @return void */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		foreach ( array( 'start', 'location', 'disconnect', 'secret', 'detect', 'dismiss', 'catalog_preview', 'catalog_apply' ) as $action ) {
			add_action( 'admin_post_doughboss_square_oauth_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_action( 'admin_post_doughboss_square_oauth_finish', array( __CLASS__, 'handle_finish' ) );
		add_action( 'admin_post_nopriv_doughboss_square_oauth_finish', array( __CLASS__, 'handle_finish_logged_out' ) );
		add_action( 'admin_notices', array( __CLASS__, 'background_notices' ) );
	}

	/** @return void */
	public static function menu() {
		add_submenu_page( 'doughboss', __( 'Square accounts', 'doughboss' ), __( 'Square accounts', 'doughboss' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	/** @return string */
	private static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/** Owner gate + nonce for one action. */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ), 403 );
		}
		check_admin_referer( 'doughboss_square_oauth_' . $action );
	}

	/** @return string Posted shop slug or ''. */
	private static function posted_shop() {
		$shop = isset( $_POST['shop'] ) ? sanitize_key( wp_unslash( $_POST['shop'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- guard() ran first.
		return DoughBoss_Square_OAuth::valid_shop( $shop ) ? $shop : '';
	}

	/** Flash a one-time result message for the current user and redirect back. */
	private static function done( $type, $message, array $args = array() ) {
		set_transient( 'doughboss_sqacct_msg_' . get_current_user_id(), array( 'type' => $type, 'message' => (string) $message ), 300 );
		wp_safe_redirect( self::page_url( $args ) );
		exit;
	}

	/** @return void */
	private static function done_result( $result, $success, array $args = array() ) {
		if ( is_wp_error( $result ) ) {
			self::done( 'error', $result->get_error_message(), $args );
		}
		self::done( 'success', $success, $args );
	}

	/* ------------------------------------------------------------------ */
	/* Handlers                                                            */
	/* ------------------------------------------------------------------ */

	/** Begin OAuth: redirect the owner's browser to Square. */
	public static function handle_start() {
		self::guard( 'start' );
		$url = DoughBoss_Square_OAuth::start( self::posted_shop() );
		if ( is_wp_error( $url ) || '' === $url ) {
			self::done( 'error', is_wp_error( $url ) ? $url->get_error_message() : __( 'Could not build the Square authorization link.', 'doughboss' ) );
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed Square host built by authorize_url().
		exit;
	}

	/** Return from Square (bounced by the REST callback). */
	public static function handle_finish() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sign in as the site owner to finish connecting Square.', 'doughboss' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the signed single-use OAuth state.
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? (string) wp_unslash( $_GET['code'] ) : '';
		$state = isset( $_GET['state'] ) ? (string) wp_unslash( $_GET['state'] ) : '';
		// phpcs:enable
		if ( '' !== $error ) {
			self::done( 'warning', 'access_denied' === $error ? __( 'The Square account owner declined the connection. Nothing was changed.', 'doughboss' ) : __( 'Square did not complete the connection. Nothing was changed.', 'doughboss' ) );
		}
		$shop = DoughBoss_Square_OAuth::finish( $code, $state );
		if ( is_wp_error( $shop ) ) {
			self::done( 'error', $shop->get_error_message() );
		}
		self::done( 'success', sprintf( /* translators: %s: shop */ __( '%s is connected to its Square account. Now pick its Square location below.', 'doughboss' ), ucfirst( $shop ) ), array( 'pick' => $shop ) );
	}

	/** Logged-out return: sign in, then come back to the same URL (state is still single-use). */
	public static function handle_finish_logged_out() {
		auth_redirect();
	}

	/** @return void */
	public static function handle_location() {
		self::guard( 'location' );
		$shop     = self::posted_shop();
		$location = isset( $_POST['location_id'] ) ? sanitize_text_field( wp_unslash( $_POST['location_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		self::done_result( DoughBoss_Square_OAuth::select_location( $shop, $location ), __( 'Square location saved for this shop.', 'doughboss' ) );
	}

	/** @return void */
	public static function handle_disconnect() {
		self::guard( 'disconnect' );
		$shop = self::posted_shop();
		if ( '' === $shop ) {
			self::done( 'error', __( 'Unknown shop.', 'doughboss' ) );
		}
		$result = DoughBoss_Square_OAuth::disconnect( $shop );
		if ( is_wp_error( $result ) ) {
			self::done( 'warning', $result->get_error_message() );
		}
		self::done( 'success', __( 'Disconnected. Square revoked this site’s access to that account.', 'doughboss' ) );
	}

	/** Write-only application secret for the current mode. */
	public static function handle_secret() {
		self::guard( 'secret' );
		$mode   = DoughBoss_Settings::square_mode();
		$clear  = ! empty( $_POST['clear'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$secret = isset( $_POST['secret'] ) ? trim( (string) wp_unslash( $_POST['secret'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by pattern in save_application_secret().
		if ( ! $clear && '' === $secret ) {
			self::done( 'warning', __( 'No secret entered; the stored value was kept.', 'doughboss' ) );
		}
		$ok = DoughBoss_Square_OAuth::save_application_secret( $mode, $clear ? '' : $secret );
		self::done( $ok ? 'success' : 'error', $ok ? __( 'Application secret saved.', 'doughboss' ) : __( 'That does not look like a Square application secret.', 'doughboss' ) );
	}

	/** @return void */
	public static function handle_detect() {
		self::guard( 'detect' );
		$merchant = DoughBoss_Square_OAuth::detect_legacy_merchant();
		self::done_result( $merchant, __( 'Single-account (settings) Square merchant detected. Webhooks from sellers this site does not serve are now ignored.', 'doughboss' ) );
	}

	/** @return void */
	public static function handle_dismiss() {
		self::guard( 'dismiss' );
		DoughBoss_Square_OAuth::clear_notices();
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : self::page_url() );
		exit;
	}

	/** Dry run: read both sellers, store a summary for the owner to approve. */
	public static function handle_catalog_preview() {
		self::guard( 'catalog_preview' );
		$shop    = self::posted_shop();
		$preview = DoughBoss_Square_Catalog_Copy::preview( $shop );
		if ( is_wp_error( $preview ) ) {
			self::done( 'error', $preview->get_error_message() );
		}
		set_transient( 'doughboss_sqcopy_' . get_current_user_id(), array( 'shop' => $shop, 'hash' => $preview['hash'], 'counts' => $preview['plan']['counts'], 'warnings' => $preview['plan']['warnings'], 'at' => time() ), HOUR_IN_SECONDS );
		self::done( 'info', __( 'Preview ready — nothing has been written to Square yet. Review it below.', 'doughboss' ) );
	}

	/** Apply exactly the previewed plan. */
	public static function handle_catalog_apply() {
		self::guard( 'catalog_apply' );
		$shop = self::posted_shop();
		$hash = isset( $_POST['hash'] ) ? sanitize_key( wp_unslash( $_POST['hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		delete_transient( 'doughboss_sqcopy_' . get_current_user_id() );
		$result = DoughBoss_Square_Catalog_Copy::apply( $shop, $hash );
		if ( is_wp_error( $result ) ) {
			self::done( 'error', $result->get_error_message() );
		}
		$message = sprintf( /* translators: 1: objects, 2: shop */ __( '%1$d Square catalogue objects created for %2$s.', 'doughboss' ), $result['created'], ucfirst( $shop ) );
		if ( $result['errors'] ) {
			self::done( 'warning', $message . ' ' . implode( ' ', $result['errors'] ) . ' ' . __( 'Preview again to copy what is still missing.', 'doughboss' ) );
		}
		self::done( 'success', $message );
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	/** Revocation / refresh-failure notices on DoughBoss screens. */
	public static function background_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false === strpos( (string) $screen->id, 'doughboss' ) && 'dashboard' !== $screen->id ) {
			return;
		}
		$notices = DoughBoss_Square_OAuth::notices();
		$stale   = DoughBoss_Square_OAuth::stale_shops();
		if ( ! $notices && ! $stale ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Square accounts', 'doughboss' ) . '</strong></p>';
		foreach ( $notices as $notice ) {
			echo '<p>' . esc_html( gmdate( 'Y-m-d H:i', (int) $notice['at'] ) . ' UTC — ' . $notice['message'] ) . '</p>';
		}
		if ( $stale ) {
			/* translators: %s: shop list */
			echo '<p>' . esc_html( sprintf( __( 'Square access tokens for %s have not refreshed for over 8 days. Check WP-Cron and the application secret on DoughBoss › Square accounts.', 'doughboss' ), implode( ', ', array_map( 'ucfirst', $stale ) ) ) ) . '</p>';
		}
		if ( $notices ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'doughboss_square_oauth_dismiss' );
			echo '<input type="hidden" name="action" value="doughboss_square_oauth_dismiss" /><p><button class="button">' . esc_html__( 'Dismiss', 'doughboss' ) . '</button> <a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Open Square accounts', 'doughboss' ) . '</a></p></form>';
		}
		echo '</div>';
	}

	/** Small POST button form. */
	private static function button( $action, $label, array $fields = array(), $class = 'button', $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 6px 0;"' . ( '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . '>';
		wp_nonce_field( 'doughboss_square_oauth_' . $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( 'doughboss_square_oauth_' . $action ) . '" />';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** @return void */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'doughboss' ), 403 );
		}
		$mode     = DoughBoss_Settings::square_mode();
		$live     = 'live' === $mode;
		$flash    = get_transient( 'doughboss_sqacct_msg_' . get_current_user_id() );
		$preview  = get_transient( 'doughboss_sqcopy_' . get_current_user_id() );
		$pick     = isset( $_GET['pick'] ) ? sanitize_key( wp_unslash( $_GET['pick'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$app_id   = DoughBoss_Settings::square_application_id();
		$secret   = '' !== DoughBoss_Square_OAuth::application_secret( $mode );
		$from_env = DoughBoss_Square_OAuth::application_secret_from_env( $mode );
		delete_transient( 'doughboss_sqacct_msg_' . get_current_user_id() );

		echo '<div class="wrap"><h1>' . esc_html__( 'Square accounts', 'doughboss' ) . '</h1>';
		if ( is_array( $flash ) ) {
			echo '<div class="notice notice-' . esc_attr( in_array( $flash['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $flash['type'] : 'info' ) . '"><p>' . esc_html( $flash['message'] ) . '</p></div>';
		}
		echo '<p style="max-width:860px;">' . esc_html__( 'Each shop takes online orders, catering invoices and loyalty through its OWN Square account. Connect a shop by signing in to that shop’s Square account. A shop that is not connected keeps using the single-account Square settings (Settings › Payments) — that is how Revesby works today, with no change needed.', 'doughboss' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Square mode:', 'doughboss' ) . '</strong> ' . ( $live ? '<span style="color:#b32d2e;font-weight:600;">' . esc_html__( 'LIVE (production)', 'doughboss' ) . '</span>' : esc_html__( 'Sandbox (test)', 'doughboss' ) ) . ' — ' . esc_html__( 'connections are kept separately for sandbox and live.', 'doughboss' ) . '</p>';

		echo '<h2>' . esc_html__( '1. Square application', 'doughboss' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Application ID', 'doughboss' ) . '</th><td>' . ( '' !== $app_id ? '<code>' . esc_html( $app_id ) . '</code>' : '<span style="color:#b32d2e;">' . esc_html__( 'Not set for this mode — set it under Settings › Payments.', 'doughboss' ) . '</span>' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Redirect URL', 'doughboss' ) . '</th><td><input type="text" readonly class="large-text code" onclick="this.select();" value="' . esc_attr( DoughBoss_Square_OAuth::redirect_url() ) . '" /><p class="description">' . esc_html__( 'Paste this exact URL into Square Developer Console › your application › OAuth › Redirect URL, for BOTH Sandbox and Production.', 'doughboss' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Application secret', 'doughboss' ) . '</th><td>';
		if ( $from_env ) {
			echo esc_html__( 'Set from wp-config.php / environment (', 'doughboss' ) . '<code>' . esc_html( $live ? 'DOUGHBOSS_SQUARE_LIVE_APP_SECRET' : 'DOUGHBOSS_SQUARE_TEST_APP_SECRET' ) . '</code>).';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'doughboss_square_oauth_secret' );
			echo '<input type="hidden" name="action" value="doughboss_square_oauth_secret" />';
			echo '<input type="password" name="secret" class="regular-text code" autocomplete="new-password" placeholder="' . esc_attr( $secret ? __( 'Saved — enter a new value to replace it', 'doughboss' ) : __( 'Not set', 'doughboss' ) ) . '" /> ';
			echo '<label><input type="checkbox" name="clear" value="1" /> ' . esc_html__( 'Clear', 'doughboss' ) . '</label> <button class="button">' . esc_html__( 'Save secret', 'doughboss' ) . '</button>';
			echo '<p class="description">' . esc_html__( 'From Square Developer Console › OAuth › Application secret (for the current mode). Write-only: it is never shown again. Prefer defining DOUGHBOSS_SQUARE_TEST_APP_SECRET / DOUGHBOSS_SQUARE_LIVE_APP_SECRET in wp-config.php.', 'doughboss' ) . '</p></form>';
		}
		echo '</td></tr>';
		$legacy = DoughBoss_Square_OAuth::legacy_merchant( $mode );
		echo '<tr><th>' . esc_html__( 'Single-account seller', 'doughboss' ) . '</th><td>' . ( '' !== $legacy ? '<code>' . esc_html( $legacy ) . '</code> ' : esc_html__( 'Not detected yet.', 'doughboss' ) . ' ' );
		self::button( 'detect', __( 'Detect from settings token', 'doughboss' ) );
		echo '<p class="description">' . esc_html__( 'The Square merchant behind the Settings › Payments access token (Revesby). Used to ignore webhooks from sellers this site does not serve.', 'doughboss' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Token encryption', 'doughboss' ) . '</th><td>' . ( defined( 'DOUGHBOSS_SQUARE_TOKEN_KEY' ) || false !== getenv( 'DOUGHBOSS_SQUARE_TOKEN_KEY' ) ? esc_html__( 'On (DOUGHBOSS_SQUARE_TOKEN_KEY is set). Changing or removing that key requires reconnecting every shop.', 'doughboss' ) : esc_html__( 'Off — tokens are stored in a non-autoloaded option. Define a long random DOUGHBOSS_SQUARE_TOKEN_KEY in wp-config.php BEFORE connecting shops to encrypt them at rest.', 'doughboss' ) ) . '</td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( '2. Shops', 'doughboss' ) . '</h2>';
		echo '<p class="description" style="max-width:860px;">' . esc_html__( 'Square shows the sign-in for whichever Square account the browser is signed in to. Before clicking Connect for a shop, sign out of Square (squareup.com) or use a private window, then sign in with THAT shop’s Square email.', 'doughboss' ) . ( $live ? '' : ' ' . esc_html__( 'Sandbox: first open the shop’s sandbox seller test account from Developer Console › Sandbox test accounts › Open in Square Dashboard, and keep that tab open.', 'doughboss' ) ) . '</p>';
		echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr><th>' . esc_html__( 'Shop', 'doughboss' ) . '</th><th>' . esc_html__( 'Square account', 'doughboss' ) . '</th><th>' . esc_html__( 'Location', 'doughboss' ) . '</th><th>' . esc_html__( 'Token', 'doughboss' ) . '</th><th>' . esc_html__( 'Actions', 'doughboss' ) . '</th></tr></thead><tbody>';
		foreach ( DoughBoss_Square_OAuth::SHOPS as $shop ) {
			$record    = DoughBoss_Square_OAuth::record( $shop, $mode );
			$connected = DoughBoss_Square_OAuth::is_connected( $shop, $mode );
			echo '<tr><td><strong>' . esc_html( ucfirst( $shop ) ) . '</strong></td><td>';
			if ( $connected ) {
				echo esc_html__( 'Connected (own account)', 'doughboss' ) . '<br /><code>' . esc_html( $record['merchant_id'] ) . '</code>';
				$missing = DoughBoss_Square_OAuth::missing_scopes( $record );
				if ( $missing ) {
					echo '<br /><span style="color:#b32d2e;">' . esc_html__( 'Missing permissions:', 'doughboss' ) . ' ' . esc_html( implode( ', ', $missing ) ) . '</span>';
				}
			} elseif ( is_array( $record ) && isset( $record['status'] ) && 'revoked' === $record['status'] ) {
				echo '<span style="color:#b32d2e;">' . esc_html__( 'Access revoked — reconnect.', 'doughboss' ) . '</span>';
			} else {
				echo esc_html__( 'Not connected — uses Settings › Payments credentials', 'doughboss' );
			}
			echo '</td><td>';
			$current = DoughBoss_Settings::square_shop_location_id( $shop );
			echo '' !== $current ? '<code>' . esc_html( $current ) . '</code>' : '<em>' . esc_html__( 'none', 'doughboss' ) . '</em>';
			if ( $connected && ( '' === DoughBoss_Square_OAuth::location_id( $shop, $mode ) || $pick === $shop ) ) {
				$locations = DoughBoss_Square_OAuth::list_locations( $shop );
				if ( is_wp_error( $locations ) ) {
					echo '<br /><span style="color:#b32d2e;">' . esc_html( $locations->get_error_message() ) . '</span>';
				} else {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:6px;">';
					wp_nonce_field( 'doughboss_square_oauth_location' );
					echo '<input type="hidden" name="action" value="doughboss_square_oauth_location" /><input type="hidden" name="shop" value="' . esc_attr( $shop ) . '" /><select name="location_id">';
					foreach ( $locations as $location ) {
						echo '<option value="' . esc_attr( $location['id'] ) . '"' . selected( $location['id'], $current, false ) . ( 'ACTIVE' !== $location['status'] ? ' disabled' : '' ) . '>' . esc_html( $location['name'] . ' (' . $location['id'] . ', ' . $location['status'] . ', ' . $location['currency'] . ')' ) . '</option>';
					}
					echo '</select> <button class="button button-primary">' . esc_html__( 'Use this location', 'doughboss' ) . '</button></form>';
				}
			}
			echo '</td><td>';
			if ( $connected ) {
				echo esc_html__( 'Expires', 'doughboss' ) . ' ' . esc_html( gmdate( 'Y-m-d', (int) $record['expires_at'] ) ) . '<br />' . esc_html__( 'Refreshed', 'doughboss' ) . ' ' . esc_html( gmdate( 'Y-m-d H:i', (int) $record['refreshed_at'] ) ) . ' UTC';
				if ( ! empty( $record['last_error'] ) ) {
					echo '<br /><span style="color:#b32d2e;">' . esc_html( $record['last_error'] ) . '</span>';
				}
			} else {
				echo '—';
			}
			echo '</td><td>';
			self::button( 'start', $connected ? __( 'Reconnect', 'doughboss' ) : __( 'Connect Square account', 'doughboss' ), array( 'shop' => $shop ), $connected ? 'button' : 'button button-primary' );
			if ( $connected ) {
				if ( '' === $pick || $pick !== $shop ) {
					echo '<a class="button" style="margin:0 6px 6px 0;" href="' . esc_url( self::page_url( array( 'pick' => $shop ) ) ) . '">' . esc_html__( 'Change location', 'doughboss' ) . '</a>';
				}
				self::button( 'disconnect', __( 'Disconnect', 'doughboss' ), array( 'shop' => $shop ), 'button button-link-delete', __( 'Disconnect this shop from its Square account? Online payments, invoices and loyalty for this shop stop until it is reconnected.', 'doughboss' ) );
				if ( DoughBoss_Square_Catalog_Copy::SOURCE !== $shop ) {
					self::button( 'catalog_preview', __( 'Preview: copy Revesby menu here', 'doughboss' ), array( 'shop' => $shop ) );
				}
			} elseif ( is_array( $record ) ) {
				self::button( 'disconnect', __( 'Forget', 'doughboss' ), array( 'shop' => $shop ), 'button button-link-delete' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		if ( is_array( $preview ) && isset( $preview['shop'], $preview['hash'], $preview['counts'] ) ) {
			echo '<h2>' . esc_html( sprintf( /* translators: %s: shop */ __( '3. Menu copy preview — Revesby → %s', 'doughboss' ), ucfirst( $preview['shop'] ) ) ) . '</h2>';
			echo '<p>' . esc_html__( 'Dry run only. Nothing has been written. Copying ADDS the missing objects to the target Square account; it never edits or deletes anything there.', 'doughboss' ) . '</p><ul style="list-style:disc;margin-left:20px;">';
			foreach ( $preview['counts'] as $key => $value ) {
				echo '<li>' . esc_html( str_replace( '_', ' ', $key ) . ': ' . (int) $value ) . '</li>';
			}
			echo '</ul>';
			if ( ! empty( $preview['warnings'] ) ) {
				echo '<div class="notice notice-warning inline"><ul style="list-style:disc;margin-left:20px;">';
				foreach ( $preview['warnings'] as $warning ) {
					echo '<li>' . esc_html( $warning ) . '</li>';
				}
				echo '</ul></div>';
			}
			self::button( 'catalog_apply', __( 'Copy now (writes to the target Square account)', 'doughboss' ), array( 'shop' => $preview['shop'], 'hash' => $preview['hash'] ), 'button button-primary', __( 'Create these catalogue objects in the target shop’s Square account?', 'doughboss' ) );
		}

		echo '<h2>' . esc_html__( 'Webhooks', 'doughboss' ) . '</h2><p style="max-width:860px;">' . esc_html__( 'Keep the ONE existing webhook subscription on the DoughBoss application (same URL and signature key). Square sends every connected shop’s events to it; this site routes each event by its merchant ID. Add the event “oauth.authorization.revoked” to that subscription so a shop that removes the app is disconnected here immediately.', 'doughboss' ) . '</p>';
		echo '</div>';
	}
}
