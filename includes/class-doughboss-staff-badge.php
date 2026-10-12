<?php
/**
 * Touch-first QR badge and PIN attendance kiosk.
 *
 * QR badge tokens are bearer links, so only their SHA-256 hashes are stored.
 * A short employee PIN is separately required and is stored with WordPress's
 * password hashing API. The public staff-clock route never creates a WordPress
 * login cookie, keeping it safe to use beside an always-on KDS browser.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DoughBoss_Staff_Badge {

	const COOKIE           = 'doughboss_staff_badge';
	const SESSION_PREFIX   = 'doughboss_staff_badge_session_';
	const LOCK_PREFIX      = 'doughboss_staff_badge_locked_';
	const ATTEMPT_PREFIX   = 'doughboss_staff_badge_attempts_';
	const SESSION_TTL      = 120;
	const MAX_PIN_ATTEMPTS = 5;
	const LOCK_TTL         = 900;

	/** Register public kiosk handlers and manager controls. */
	public function init() {
		add_action( 'init', array( $this, 'capture_badge_scan' ), 2 );
		add_action( 'admin_post_doughboss_staff_badge_pin', array( $this, 'handle_pin' ) );
		add_action( 'admin_post_nopriv_doughboss_staff_badge_pin', array( $this, 'handle_pin' ) );
		add_action( 'admin_post_doughboss_staff_badge_action', array( $this, 'handle_badge_action' ) );
		add_action( 'admin_post_nopriv_doughboss_staff_badge_action', array( $this, 'handle_badge_action' ) );
		add_action( 'admin_post_doughboss_issue_staff_badge', array( $this, 'handle_issue_badge' ) );
		add_action( 'admin_post_doughboss_revoke_staff_badge', array( $this, 'handle_revoke_badge' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ), 31 );
	}

	/** @return string */
	public static function badges_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_staff_badges';
	}

	/** @return string */
	public static function breaks_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_staff_breaks';
	}

	/**
	 * Consume a QR bearer token before a page is rendered. The raw token is
	 * removed from the address bar with a 303 and never enters an attendance row.
	 */
	public function capture_badge_scan() {
		if ( is_admin() || ! isset( $_GET['staff_badge'] ) ) {
			return;
		}
		$request_path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		$clock_path   = wp_parse_url( home_url( '/staff-clock/' ), PHP_URL_PATH );
		if ( rtrim( (string) $request_path, '/' ) !== rtrim( (string) $clock_path, '/' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a QR scan is a one-time navigation, not a state-changing form.
		$token = trim( (string) wp_unslash( $_GET['staff_badge'] ) );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{40,120}$/', $token ) ) {
			self::redirect_clock( 'badge' );
		}
		global $wpdb;
		$badge = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::badges_table() . ' WHERE token_hash = %s AND status = %s AND active_guard = 1 LIMIT 1', hash( 'sha256', $token ), 'active' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $badge || get_transient( self::lock_key( $badge ? $badge->id : 0 ) ) ) {
			self::redirect_clock( 'badge' );
		}
		$user = get_userdata( (int) $badge->user_id );
		if ( ! $user || ! user_can( $user, DoughBoss_Timeclock::CAPABILITY ) || ! DoughBoss_Timeclock::storage_ready() ) {
			self::redirect_clock( 'badge' );
		}

		$session = self::random_token();
		$data    = array(
			'badge_id' => (int) $badge->id,
			'user_id'  => (int) $badge->user_id,
			'verified' => false,
			'nonce'    => self::random_token(),
		);
		set_transient( self::session_key( $session ), $data, self::SESSION_TTL );
		self::set_cookie( $session, time() + self::SESSION_TTL );
		self::redirect_clock( '' );
	}

	/** Add the manager-only QR issue/revoke workspace. */
	public function register_admin_page() {
		add_submenu_page(
			'doughboss',
			__( 'Staff QR badges', 'doughboss' ),
			__( 'Staff QR badges', 'doughboss' ),
			'manage_doughboss',
			'doughboss-staff-badges',
			array( $this, 'render_admin_page' )
		);
	}

	/** Render the manager badge issue page. */
	public function render_admin_page() {
		self::verify_manager();
		$staff = self::clockable_users();
		global $wpdb;
		$badges = (array) $wpdb->get_results( "SELECT b.*, u.display_name, u.user_login FROM " . self::badges_table() . " b LEFT JOIN " . $wpdb->users . " u ON u.ID = b.user_id ORDER BY CASE WHEN b.status = 'active' THEN 0 ELSE 1 END, b.updated_at DESC LIMIT 300" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		?>
		<div class="wrap doughboss-staff-badges">
			<h1><?php esc_html_e( 'Staff QR badges', 'doughboss' ); ?></h1>
			<p><?php esc_html_e( 'Issue one personal QR badge and short PIN to each employee. Scanning a badge never signs the kitchen screen into that employee’s WordPress account.', 'doughboss' ); ?></p>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'A QR badge is not enough on its own: the employee must also enter their personal 4–8 digit PIN. Reissuing immediately revokes the old badge. PINs cannot be viewed or recovered.', 'doughboss' ); ?></p></div>
			<h2><?php esc_html_e( 'Issue or replace a badge', 'doughboss' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'doughboss_issue_staff_badge' ); ?>
				<input type="hidden" name="action" value="doughboss_issue_staff_badge" />
				<table class="form-table" role="presentation"><tbody>
				<tr><th><label for="db-badge-user"><?php esc_html_e( 'Employee', 'doughboss' ); ?></label></th><td><select id="db-badge-user" name="user_id" required><option value=""><?php esc_html_e( 'Choose an employee', 'doughboss' ); ?></option><?php foreach ( $staff as $user ) : ?><option value="<?php echo esc_attr( $user->ID ); ?>"><?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'The employee must be assigned to an active DoughBoss shop first.', 'doughboss' ); ?></p></td></tr>
				<tr><th><label for="db-badge-pin"><?php esc_html_e( 'New PIN', 'doughboss' ); ?></label></th><td><input id="db-badge-pin" name="pin" inputmode="numeric" autocomplete="new-password" pattern="[0-9]{4,8}" minlength="4" maxlength="8" required type="password" /><p class="description"><?php esc_html_e( 'Choose a unique 4–8 digit PIN and give it to the employee privately. It is not printed on the badge.', 'doughboss' ); ?></p></td></tr>
				</tbody></table>
				<?php submit_button( __( 'Create secure QR badge', 'doughboss' ) ); ?>
			</form>
			<h2><?php esc_html_e( 'Issued badges', 'doughboss' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Employee', 'doughboss' ); ?></th><th><?php esc_html_e( 'Status', 'doughboss' ); ?></th><th><?php esc_html_e( 'Issued', 'doughboss' ); ?></th><th><?php esc_html_e( 'Last used', 'doughboss' ); ?></th><th><?php esc_html_e( 'Action', 'doughboss' ); ?></th></tr></thead><tbody>
			<?php if ( ! $badges ) : ?><tr><td colspan="5"><?php esc_html_e( 'No staff QR badges have been issued yet.', 'doughboss' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $badges as $badge ) : ?><tr><td><strong><?php echo esc_html( $badge->display_name ? $badge->display_name : ( 'Staff #' . $badge->user_id ) ); ?></strong><br><code><?php echo esc_html( $badge->user_login ); ?></code></td><td><?php echo esc_html( ucfirst( $badge->status ) ); ?></td><td><?php echo esc_html( $badge->created_at ); ?></td><td><?php echo esc_html( $badge->last_used_at ? $badge->last_used_at : '—' ); ?></td><td><?php if ( 'active' === $badge->status ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this badge? It will stop working immediately.', 'doughboss' ) ); ?>');"><?php wp_nonce_field( 'doughboss_revoke_staff_badge_' . (int) $badge->id ); ?><input type="hidden" name="action" value="doughboss_revoke_staff_badge"><input type="hidden" name="badge_id" value="<?php echo esc_attr( $badge->id ); ?>"><button class="button" type="submit"><?php esc_html_e( 'Revoke', 'doughboss' ); ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}

	/** Issue a badge and immediately render its one-time print page. */
	public function handle_issue_badge() {
		self::verify_manager();
		check_admin_referer( 'doughboss_issue_staff_badge' );
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$pin     = isset( $_POST['pin'] ) ? trim( (string) wp_unslash( $_POST['pin'] ) ) : '';
		$user    = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user || ! user_can( $user, DoughBoss_Timeclock::CAPABILITY ) || ! preg_match( '/^\d{4,8}$/', $pin ) ) {
			wp_die( esc_html__( 'Choose an eligible employee and a 4–8 digit PIN.', 'doughboss' ), esc_html__( 'Badge not issued', 'doughboss' ), array( 'response' => 400 ) );
		}
		$location_id = DoughBoss_Staff_Scope::assigned_location_id( $user_id );
		if ( is_wp_error( $location_id ) || ! $location_id ) {
			wp_die( esc_html__( 'Assign this employee to an active shop before issuing a badge.', 'doughboss' ), esc_html__( 'Shop assignment required', 'doughboss' ), array( 'response' => 400 ) );
		}

		$token = self::random_token();
		$now   = current_time( 'mysql', true );
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_die( esc_html__( 'The badge could not be issued. Please try again.', 'doughboss' ), esc_html__( 'Badge not issued', 'doughboss' ), array( 'response' => 500 ) );
		}
		$revoked = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::badges_table() . ' SET status = %s, active_guard = NULL, revoked_at = %s, updated_at = %s WHERE user_id = %d AND status = %s', 'revoked', $now, $now, $user_id, 'active' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$created = false !== $revoked ? $wpdb->insert(
			self::badges_table(),
			array(
				'user_id'   => $user_id,
				'token_hash'=> hash( 'sha256', $token ),
				'pin_hash'  => wp_hash_password( $pin ),
				'status'    => 'active',
				'active_guard' => 1,
				'issued_by' => get_current_user_id(),
				'created_at'=> $now,
				'updated_at'=> $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		) : false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $created || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_die( esc_html__( 'The badge could not be issued. No active badge was changed.', 'doughboss' ), esc_html__( 'Badge not issued', 'doughboss' ), array( 'response' => 500 ) );
		}
		self::render_issued_badge( $user, add_query_arg( 'staff_badge', rawurlencode( $token ), home_url( '/staff-clock/' ) ) );
		exit;
	}

	/** Revoke a lost or retired badge. */
	public function handle_revoke_badge() {
		self::verify_manager();
		$badge_id = isset( $_POST['badge_id'] ) ? absint( $_POST['badge_id'] ) : 0;
		check_admin_referer( 'doughboss_revoke_staff_badge_' . $badge_id );
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::badges_table() . ' SET status = %s, active_guard = NULL, revoked_at = %s, updated_at = %s WHERE id = %d AND status = %s AND active_guard = 1', 'revoked', $now, $now, $badge_id, 'active' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		wp_safe_redirect( admin_url( 'admin.php?page=doughboss-staff-badges' ) );
		exit;
	}

	/** Render a badge session if one exists; otherwise leave the normal portal alone. */
	public static function maybe_render_portal() {
		$session = self::session();
		if ( ! $session ) {
			return false;
		}
		if ( empty( $session['verified'] ) ) {
			self::render_pin_screen( $session );
			return true;
		}
		$badge = self::active_badge( (int) $session['badge_id'], (int) $session['user_id'] );
		$user  = $badge ? get_userdata( (int) $badge->user_id ) : false;
		if ( ! $badge || ! $user || ! user_can( $user, DoughBoss_Timeclock::CAPABILITY ) ) {
			self::destroy_session();
			self::render_scan_landing( 'badge' );
			return true;
		}
		$location_id = DoughBoss_Staff_Scope::assigned_location_id( $user->ID );
		$location    = ! is_wp_error( $location_id ) ? DoughBoss_Locations::get( $location_id ) : null;
		if ( ! $location || 1 !== (int) $location->is_active ) {
			self::destroy_session();
			self::render_scan_landing( 'location' );
			return true;
		}
		$shift = DoughBoss_Timeclock::open_shift( $user->ID );
		$break = $shift ? self::open_break( $shift->id ) : null;
		$nonce = self::session_nonce( $session );
		?>
		<main class="db-timeclock-shell" id="main">
			<section class="db-timeclock-card db-timeclock-badge" aria-labelledby="db-timeclock-title">
				<p class="db-timeclock-kicker"><?php esc_html_e( 'Dough Boss staff', 'doughboss' ); ?></p>
				<h1 id="db-timeclock-title"><?php esc_html_e( 'Ready to record time', 'doughboss' ); ?></h1>
				<p class="db-timeclock-badge-shop"><?php echo esc_html( $location->name ); ?></p>
				<?php if ( $shift ) : ?>
					<div class="db-timeclock-state is-in"><span><?php echo $break ? esc_html__( 'On break', 'doughboss' ) : esc_html__( 'Clocked in', 'doughboss' ); ?></span><strong><?php echo esc_html( DoughBoss_Timeclock::duration_label( DoughBoss_Timeclock::worked_minutes( $shift ) ) ); ?></strong><small><?php echo esc_html__( 'Worked time so far', 'doughboss' ); ?></small></div>
				<?php else : ?>
					<p class="db-timeclock-badge-copy"><?php esc_html_e( 'Tap once to start your shift.', 'doughboss' ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="doughboss_staff_badge_action" />
					<input type="hidden" name="db_staff_badge_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
					<?php if ( ! $shift ) : ?>
						<button class="db-timeclock-button" name="clock_action" type="submit" value="in"><?php esc_html_e( 'Clock in', 'doughboss' ); ?></button>
					<?php elseif ( $break ) : ?>
						<button class="db-timeclock-button" name="clock_action" type="submit" value="break-end"><?php esc_html_e( 'End break', 'doughboss' ); ?></button>
					<?php else : ?>
						<button class="db-timeclock-button db-timeclock-button-break" name="clock_action" type="submit" value="break-start"><?php esc_html_e( 'Start break', 'doughboss' ); ?></button>
						<button class="db-timeclock-button db-timeclock-button-out" name="clock_action" type="submit" value="out"><?php esc_html_e( 'Clock out', 'doughboss' ); ?></button>
					<?php endif; ?>
				</form>
				<p class="db-timeclock-help"><?php esc_html_e( 'This shared screen clears your badge after every action.', 'doughboss' ); ?></p>
			</section>
		</main>
		<?php
		return true;
	}

	/** Render the scanner-first signed-out landing. */
	public static function render_scan_landing( $result = '' ) {
		?>
		<main class="db-timeclock-shell" id="main">
			<section class="db-timeclock-card db-timeclock-login db-timeclock-scan" aria-labelledby="db-timeclock-title">
				<p class="db-timeclock-kicker"><?php esc_html_e( 'Dough Boss staff', 'doughboss' ); ?></p>
				<h1 id="db-timeclock-title"><?php esc_html_e( 'Staff clock', 'doughboss' ); ?></h1>
				<?php self::render_result( $result ); ?>
				<p><?php esc_html_e( 'Scan your personal QR badge, then enter your PIN.', 'doughboss' ); ?></p>
				<label class="screen-reader-text" for="db-badge-scan"><?php esc_html_e( 'Scan QR badge', 'doughboss' ); ?></label>
				<input class="db-timeclock-scan-input" id="db-badge-scan" autocomplete="off" inputmode="none" placeholder="Scan QR badge here" />
				<p class="db-timeclock-help"><?php esc_html_e( 'If the scanner does not open your badge automatically, scan into this box and press Enter.', 'doughboss' ); ?></p>
				<details class="db-timeclock-fallback"><summary><?php esc_html_e( 'Use staff account instead', 'doughboss' ); ?></summary><a class="db-timeclock-button" href="<?php echo esc_url( wp_login_url( home_url( '/staff-clock/' ) ) ); ?>"><?php esc_html_e( 'Staff sign in', 'doughboss' ); ?></a></details>
			</section>
		</main>
		<script src="<?php echo esc_url( DOUGHBOSS_PLUGIN_URL . 'public/js/doughboss-staff-badge.js?ver=' . rawurlencode( DOUGHBOSS_VERSION ) ); ?>"></script>
		<?php
	}

	/** Verify a PIN through a badge session. */
	public function handle_pin() {
		$session = self::session();
		if ( ! $session || ! self::verify_session_nonce( $session ) || ! empty( $session['verified'] ) ) {
			self::destroy_session();
			self::redirect_clock( 'badge' );
		}
		$badge = self::active_badge( (int) $session['badge_id'], (int) $session['user_id'] );
		if ( ! $badge || get_transient( self::lock_key( $badge ? $badge->id : 0 ) ) ) {
			self::destroy_session();
			self::redirect_clock( 'badge' );
		}
		$pin = isset( $_POST['pin'] ) ? trim( (string) wp_unslash( $_POST['pin'] ) ) : '';
		if ( ! preg_match( '/^\d{4,8}$/', $pin ) || ! wp_check_password( $pin, $badge->pin_hash, (int) $badge->user_id ) ) {
			$attempts = (int) get_transient( self::attempt_key( $badge->id ) ) + 1;
			if ( $attempts >= self::MAX_PIN_ATTEMPTS ) {
				delete_transient( self::attempt_key( $badge->id ) );
				set_transient( self::lock_key( $badge->id ), 1, self::LOCK_TTL );
				self::destroy_session();
				self::redirect_clock( 'locked' );
			}
			set_transient( self::attempt_key( $badge->id ), $attempts, self::LOCK_TTL );
			self::redirect_clock( 'pin' );
		}
		delete_transient( self::attempt_key( $badge->id ) );
		$session['verified'] = true;
		self::save_session( $session );
		wp_safe_redirect( home_url( '/staff-clock/' ) );
		exit;
	}

	/** Apply one QR verified attendance transition, then discard the kiosk session. */
	public function handle_badge_action() {
		$session = self::session();
		if ( ! $session || empty( $session['verified'] ) || ! self::verify_session_nonce( $session ) ) {
			self::destroy_session();
			self::redirect_clock( 'badge' );
		}
		$badge = self::active_badge( (int) $session['badge_id'], (int) $session['user_id'] );
		$user  = $badge ? get_userdata( (int) $badge->user_id ) : false;
		if ( ! $badge || ! $user || ! user_can( $user, DoughBoss_Timeclock::CAPABILITY ) || ! DoughBoss_Timeclock::storage_ready() ) {
			self::destroy_session();
			self::redirect_clock( 'error' );
		}
		$location_id = DoughBoss_Staff_Scope::assigned_location_id( $user->ID );
		$location    = ! is_wp_error( $location_id ) ? DoughBoss_Locations::get( $location_id ) : null;
		if ( ! $location || 1 !== (int) $location->is_active ) {
			self::destroy_session();
			self::redirect_clock( 'location' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the synchronizer nonce above.
		$action = isset( $_POST['clock_action'] ) ? sanitize_key( wp_unslash( $_POST['clock_action'] ) ) : '';
		$status = DoughBoss_Timeclock::with_user_lock(
			$user->ID,
			static function () use ( $action, $user, $location ) {
				$shift = DoughBoss_Timeclock::open_shift( $user->ID );
				if ( 'in' === $action ) {
					return $shift ? 'already-in' : DoughBoss_Timeclock::clock_in_for_user( $user->ID, $location, 'staff_badge', false );
				}
				if ( ! $shift ) {
					return 'already-out';
				}
				if ( 'break-start' === $action ) {
					return self::start_break( $shift, $user->ID );
				}
				if ( 'break-end' === $action ) {
					return self::end_break( $shift, $user->ID );
				}
				if ( 'out' === $action ) {
					return self::open_break( $shift->id ) ? 'break-open' : DoughBoss_Timeclock::clock_out_for_user( $user->ID, false );
				}
				return 'error';
			}
		);
		if ( in_array( $status, array( 'in', 'out', 'break-start', 'break-end' ), true ) ) {
			global $wpdb;
			$wpdb->update( self::badges_table(), array( 'last_used_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $badge->id ), array( '%s', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		self::destroy_session();
		self::redirect_clock( $status );
	}

	/** @return object|null */
	public static function open_break( $shift_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::breaks_table() . ' WHERE shift_id = %d AND break_end_utc IS NULL AND open_guard = 1 ORDER BY id DESC LIMIT 1', absint( $shift_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Calculate only actually-recorded break minutes; there is no auto deduction. */
	public static function break_minutes_for_shift( $shift, $until = 0 ) {
		if ( ! $shift || empty( $shift->id ) ) {
			return 0;
		}
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT break_start_utc, break_end_utc FROM ' . self::breaks_table() . ' WHERE shift_id = %d ORDER BY id ASC', (int) $shift->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$end  = $until ? (int) $until : time();
		$total = 0;
		foreach ( $rows as $row ) {
			$start = strtotime( $row->break_start_utc . ' UTC' );
			$finish = $row->break_end_utc ? strtotime( $row->break_end_utc . ' UTC' ) : $end;
			if ( $start && $finish > $start ) {
				$total += (int) floor( ( $finish - $start ) / 60 );
			}
		}
		return max( 0, $total );
	}

	/** @return string */
	private static function start_break( $shift, $user_id ) {
		if ( self::open_break( $shift->id ) ) {
			return 'already-break';
		}
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert( self::breaks_table(), array( 'shift_id' => (int) $shift->id, 'user_id' => absint( $user_id ), 'break_start_utc' => $now, 'open_guard' => 1, 'source' => 'staff_badge', 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $ok && self::open_break( $shift->id ) ? 'break-start' : 'error';
	}

	/** @return string */
	private static function end_break( $shift, $user_id ) {
		$break = self::open_break( $shift->id );
		if ( ! $break ) {
			return 'no-break';
		}
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::breaks_table() . ' SET break_end_utc = %s, open_guard = NULL, updated_at = %s WHERE id = %d AND shift_id = %d AND user_id = %d AND break_end_utc IS NULL AND open_guard = 1', $now, $now, (int) $break->id, (int) $shift->id, absint( $user_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return 1 === $ok ? 'break-end' : 'error';
	}

	/** @return array|false */
	private static function session() {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{40,120}$/', $token ) ) {
			return false;
		}
		$data = get_transient( self::session_key( $token ) );
		return is_array( $data ) && ! empty( $data['badge_id'] ) && ! empty( $data['user_id'] ) && ! empty( $data['nonce'] ) ? $data : false;
	}

	/** @return void */
	private static function save_session( $data ) {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		if ( $token ) {
			set_transient( self::session_key( $token ), $data, self::SESSION_TTL );
			self::set_cookie( $token, time() + self::SESSION_TTL );
		}
	}

	/** @return bool */
	private static function verify_session_nonce( $session ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- synchronizer token is the CSRF defence for unauthenticated kiosk posts.
		$provided = isset( $_POST['db_staff_badge_nonce'] ) ? (string) wp_unslash( $_POST['db_staff_badge_nonce'] ) : '';
		return isset( $session['nonce'] ) && is_string( $provided ) && hash_equals( (string) $session['nonce'], $provided );
	}

	/** @return string */
	private static function session_nonce( $session ) {
		return isset( $session['nonce'] ) ? (string) $session['nonce'] : '';
	}

	/** @return object|null */
	private static function active_badge( $badge_id, $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::badges_table() . ' WHERE id = %d AND user_id = %d AND status = %s AND active_guard = 1 LIMIT 1', absint( $badge_id ), absint( $user_id ), 'active' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Clear this browser's short-lived kiosk identity. */
	private static function destroy_session() {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		if ( $token ) {
			delete_transient( self::session_key( $token ) );
		}
		self::set_cookie( '', time() - HOUR_IN_SECONDS );
	}

	/** @return string */
	private static function session_key( $token ) {
		return self::SESSION_PREFIX . hash( 'sha256', (string) $token );
	}

	/** @return string */
	private static function lock_key( $badge_id ) {
		return self::LOCK_PREFIX . absint( $badge_id );
	}

	/** @return string */
	private static function attempt_key( $badge_id ) {
		return self::ATTEMPT_PREFIX . absint( $badge_id );
	}

	/** @return string */
	private static function random_token() {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	/** @return void */
	private static function set_cookie( $value, $expires ) {
		$options = array(
			'expires'  => (int) $expires,
			'path'     => '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Strict',
		);
		setcookie( self::COOKIE, $value, $options );
	}

	/** @return void */
	private static function redirect_clock( $result ) {
		$url = home_url( '/staff-clock/' );
		if ( '' !== $result ) {
			$url = add_query_arg( 'db_badge', sanitize_key( $result ), $url );
		}
		wp_safe_redirect( $url, 303 );
		exit;
	}

	/** Render generic non-sensitive scan / action feedback. */
	private static function render_result( $result ) {
		$messages = array(
			'in'          => array( 'success', __( 'Clock-in recorded. You can hand the screen to the next team member.', 'doughboss' ) ),
			'out'         => array( 'success', __( 'Clock-out recorded. Thank you for today.', 'doughboss' ) ),
			'break-start' => array( 'success', __( 'Break started. Scan your badge again when you return.', 'doughboss' ) ),
			'break-end'   => array( 'success', __( 'Break ended. Your worked time continues now.', 'doughboss' ) ),
			'already-in'  => array( 'success', __( 'You were already clocked in. No duplicate shift was created.', 'doughboss' ) ),
			'already-out' => array( 'success', __( 'No open shift was found. Nothing was changed.', 'doughboss' ) ),
			'already-break'=> array( 'success', __( 'Your break was already started. No duplicate break was created.', 'doughboss' ) ),
			'no-break'    => array( 'success', __( 'There was no open break to end. Nothing was changed.', 'doughboss' ) ),
			'break-open'  => array( 'error', __( 'End your break before clocking out.', 'doughboss' ) ),
			'pin'         => array( 'error', __( 'PIN not accepted. Please try again.', 'doughboss' ) ),
			'locked'      => array( 'error', __( 'This badge is temporarily locked. Please ask a manager.', 'doughboss' ) ),
			'badge'       => array( 'error', __( 'Badge not accepted. Please scan your current personal badge or ask a manager.', 'doughboss' ) ),
			'location'    => array( 'error', __( 'Your shop assignment needs attention. Please ask a manager.', 'doughboss' ) ),
			'error'       => array( 'error', __( 'Nothing was recorded. Please ask a manager for help.', 'doughboss' ) ),
		);
		if ( isset( $messages[ $result ] ) ) {
			printf( '<p class="db-timeclock-%1$s" role="%2$s">%3$s</p>', esc_attr( $messages[ $result ][0] ), 'success' === $messages[ $result ][0] ? 'status' : 'alert', esc_html( $messages[ $result ][1] ) );
		}
	}

	/** PIN screen after a valid scan. */
	private static function render_pin_screen( $session ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only scan result.
		$result = isset( $_GET['db_badge'] ) ? sanitize_key( wp_unslash( $_GET['db_badge'] ) ) : '';
		?>
		<main class="db-timeclock-shell" id="main"><section class="db-timeclock-card db-timeclock-pin-card" aria-labelledby="db-timeclock-title"><p class="db-timeclock-kicker"><?php esc_html_e( 'Dough Boss staff', 'doughboss' ); ?></p><h1 id="db-timeclock-title"><?php esc_html_e( 'Enter your PIN', 'doughboss' ); ?></h1><?php self::render_result( $result ); ?><p><?php esc_html_e( 'Your badge was recognised. Enter your personal PIN to continue.', 'doughboss' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="doughboss_staff_badge_pin"><input type="hidden" name="db_staff_badge_nonce" value="<?php echo esc_attr( self::session_nonce( $session ) ); ?>"><label class="screen-reader-text" for="db-badge-pin-entry"><?php esc_html_e( 'Personal PIN', 'doughboss' ); ?></label><input class="db-timeclock-pin-input" id="db-badge-pin-entry" name="pin" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{4,8}" minlength="4" maxlength="8" required type="password" autofocus><div class="db-timeclock-keypad" data-target="db-badge-pin-entry"><?php foreach ( array( 1, 2, 3, 4, 5, 6, 7, 8, 9, 'clear', 0, 'back' ) as $key ) : ?><button type="button" data-key="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( 'back' === $key ? '⌫' : ( 'clear' === $key ? __( 'Clear', 'doughboss' ) : $key ) ); ?></button><?php endforeach; ?></div><button class="db-timeclock-button" type="submit"><?php esc_html_e( 'Continue', 'doughboss' ); ?></button></form><p class="db-timeclock-help"><?php esc_html_e( 'Five incorrect PIN attempts temporarily lock this badge for 15 minutes.', 'doughboss' ); ?></p></section></main><script src="<?php echo esc_url( DOUGHBOSS_PLUGIN_URL . 'public/js/doughboss-staff-badge.js?ver=' . rawurlencode( DOUGHBOSS_VERSION ) ); ?>"></script>
		<?php
	}

	/** One-time manager print page. The bearer URL is not stored after this response. */
	private static function render_issued_badge( $user, $url ) {
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Print staff QR badge now', 'doughboss' ); ?></h1><div class="notice notice-warning"><p><?php esc_html_e( 'This is the only time this QR link can be shown. Print or save it now. The PIN is deliberately not printed—give it to the employee privately.', 'doughboss' ); ?></p></div><section id="doughboss-staff-badge-print" style="background:#fff;border:2px solid #111;max-width:460px;padding:28px;text-align:center"><p style="font-size:14px;font-weight:800;letter-spacing:.12em">DOUGH BOSS STAFF</p><h2 style="font-size:30px"><?php echo esc_html( $user->display_name ); ?></h2><p><?php esc_html_e( 'Scan this badge at the Staff Clock, then enter your personal PIN.', 'doughboss' ); ?></p><div id="doughboss-staff-badge-qr" data-url="<?php echo esc_attr( $url ); ?>" style="display:flex;justify-content:center;margin:20px"></div><p style="font-size:12px;word-break:break-all"><code><?php echo esc_html( $url ); ?></code></p></section><p><button class="button button-primary" onclick="window.print()"><?php esc_html_e( 'Print / save as PDF', 'doughboss' ); ?></button> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=doughboss-staff-badges' ) ); ?>"><?php esc_html_e( 'Back to badges', 'doughboss' ); ?></a></p></div><style media="print">#wpadminbar,#adminmenumain,#wpfooter,.notice,.wrap>h1,.wrap>p{display:none!important}#wpcontent{margin:0!important}</style><script src="<?php echo esc_url( DOUGHBOSS_PLUGIN_URL . 'public/vendor/qrcode-generator/qrcode.js?ver=' . rawurlencode( DOUGHBOSS_VERSION ) ); ?>"></script><script>document.addEventListener('DOMContentLoaded',function(){var m=document.getElementById('doughboss-staff-badge-qr');if(m&&typeof qrcode==='function'){var q=qrcode(0,'M');q.addData(m.getAttribute('data-url'),'Byte');q.make();m.innerHTML=q.createSvgTag({cellSize:6,margin:4,scalable:true});}});</script>
		<?php
	}

	/** @return WP_User[] */
	private static function clockable_users() {
		$users = get_users( array( 'number' => 500, 'orderby' => 'display_name', 'order' => 'ASC' ) );
		return array_values( array_filter( $users, static function ( $user ) { return user_can( $user, DoughBoss_Timeclock::CAPABILITY ); } ) );
	}

	/** @return void */
	private static function verify_manager() {
		if ( ! current_user_can( 'manage_doughboss' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage staff QR badges.', 'doughboss' ), esc_html__( 'Manager access required', 'doughboss' ), array( 'response' => 403 ) );
		}
		if ( ! DoughBoss_Timeclock::storage_ready() ) {
			wp_die( esc_html__( 'Attendance storage is not ready. No badge was changed.', 'doughboss' ), esc_html__( 'Staff clock unavailable', 'doughboss' ), array( 'response' => 503 ) );
		}
	}
}
