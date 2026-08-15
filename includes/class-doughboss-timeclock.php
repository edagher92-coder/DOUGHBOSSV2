<?php
/**
 * Secure staff clock-in / clock-out and manager timesheets.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records one location-bound shift per individual WordPress staff account.
 */
final class DoughBoss_Timeclock {

	const CAPABILITY = 'clock_doughboss_staff';

	/** Register state-changing handlers and the manager report. */
	public function init() {
		add_action( 'admin_post_doughboss_clock_in', array( $this, 'handle_clock_in' ) );
		add_action( 'admin_post_doughboss_clock_out', array( $this, 'handle_clock_out' ) );
		add_action( 'admin_post_doughboss_export_timesheet', array( $this, 'handle_export' ) );
		add_action( 'admin_post_doughboss_correct_shift', array( $this, 'handle_correction' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ), 30 );
	}

	/** @return string */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_staff_shifts';
	}

	/** @return string */
	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_staff_shift_events';
	}

	/** @return bool */
	public static function can_clock() {
		return is_user_logged_in() && current_user_can( self::CAPABILITY ) && self::storage_ready();
	}

	/**
	 * Fail closed until the 1.21 attendance schema and its invariants have been
	 * durably verified. Capabilities can exist before a failed migration is
	 * repaired, so authorization alone is not a sufficient runtime gate.
	 *
	 * @return bool
	 */
	public static function storage_ready() {
		return version_compare( (string) get_option( 'doughboss_db_version', '0' ), '1.21.0', '>=' )
			&& DoughBoss_Activator::timeclock_storage_ready();
	}

	/** Add the manager-only timesheet beneath the main DoughBoss menu. */
	public function register_admin_page() {
		add_submenu_page(
			'doughboss',
			__( 'Staff Timesheet', 'doughboss' ),
			__( 'Staff Timesheet', 'doughboss' ),
			'manage_doughboss',
			'doughboss-timeclock',
			array( $this, 'render_admin_page' )
		);
	}

	/** Render the standalone /staff-clock/ portal body. */
	public static function render_portal() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- safe, display-only result code after a completed action.
		$result = isset( $_GET['db_clock'] ) ? sanitize_key( wp_unslash( $_GET['db_clock'] ) ) : '';
		if ( ! self::storage_ready() ) {
			if ( is_user_logged_in() ) {
				wp_logout();
			}
			?>
			<main class="db-timeclock-shell" id="main">
				<section class="db-timeclock-card db-timeclock-login" aria-labelledby="db-timeclock-title">
					<p class="db-timeclock-kicker"><?php esc_html_e( 'Dough Boss staff', 'doughboss' ); ?></p>
					<h1 id="db-timeclock-title"><?php esc_html_e( 'Staff clock unavailable', 'doughboss' ); ?></h1>
					<p class="db-timeclock-error" role="alert"><?php esc_html_e( 'Attendance storage is not ready. No shift was changed and this shared screen has been signed out. Please ask a manager.', 'doughboss' ); ?></p>
				</section>
			</main>
			<?php
			return;
		}
		if ( class_exists( 'DoughBoss_Staff_Badge' ) && DoughBoss_Staff_Badge::maybe_render_portal() ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			if ( class_exists( 'DoughBoss_Staff_Badge' ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- safe display-only result from a completed badge action.
				$badge_result = isset( $_GET['db_badge'] ) ? sanitize_key( wp_unslash( $_GET['db_badge'] ) ) : $result;
				DoughBoss_Staff_Badge::render_scan_landing( $badge_result );
			} else {
				?>
				<main class="db-timeclock-shell" id="main"><section class="db-timeclock-card db-timeclock-login"><h1><?php esc_html_e( 'Staff clock', 'doughboss' ); ?></h1><a class="db-timeclock-button" href="<?php echo esc_url( wp_login_url( home_url( '/staff-clock/' ) ) ); ?>"><?php esc_html_e( 'Staff sign in', 'doughboss' ); ?></a></section></main>
				<?php
			}
			return;
		}

		if ( ! self::can_clock() ) {
			wp_die( esc_html__( 'This account does not have staff-clock access.', 'doughboss' ), esc_html__( 'Staff access required', 'doughboss' ), array( 'response' => 403 ) );
		}

		$user      = wp_get_current_user();
		$shift     = self::open_shift( get_current_user_id() );
		$locations = DoughBoss_Locations::all( true );
		$manager   = current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' );
		$assigned  = $manager ? 0 : DoughBoss_Staff_Scope::assigned_location_id( get_current_user_id() );
		$location  = ! is_wp_error( $assigned ) && $assigned ? DoughBoss_Locations::get( $assigned ) : null;
		$minutes   = $shift ? self::worked_minutes( $shift ) : 0;
		?>
		<main class="db-timeclock-shell" id="main">
			<section class="db-timeclock-card" aria-labelledby="db-timeclock-title">
				<p class="db-timeclock-kicker"><?php esc_html_e( 'Dough Boss staff', 'doughboss' ); ?></p>
				<h1 id="db-timeclock-title"><?php echo esc_html( sprintf( __( 'Hi, %s', 'doughboss' ), $user->display_name ) ); ?></h1>
				<?php if ( 'error' === $result ) : ?><p class="db-timeclock-error" role="alert"><?php esc_html_e( 'The shift could not be updated. Please try again or ask a manager.', 'doughboss' ); ?></p><?php endif; ?>
				<?php if ( 'location' === $result ) : ?><p class="db-timeclock-error" role="alert"><?php esc_html_e( 'Choose an active DoughBoss shop before clocking in.', 'doughboss' ); ?></p><?php endif; ?>
				<?php if ( 'break-open' === $result ) : ?><p class="db-timeclock-error" role="alert"><?php esc_html_e( 'End the recorded break on the staff kiosk before clocking out.', 'doughboss' ); ?></p><?php endif; ?>

				<?php if ( $shift ) : ?>
					<div class="db-timeclock-state is-in">
						<span><?php esc_html_e( 'Clocked in', 'doughboss' ); ?></span>
						<strong><?php echo esc_html( $shift->location_name ); ?></strong>
						<small><?php echo esc_html( sprintf( __( 'Started %1$s · %2$s', 'doughboss' ), wp_date( get_option( 'time_format' ), strtotime( $shift->clock_in_utc . ' UTC' ) ), self::duration_label( $minutes ) ) ); ?></small>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="doughboss_clock_out" />
						<?php wp_nonce_field( 'doughboss_clock_out' ); ?>
						<button class="db-timeclock-button db-timeclock-button-out" type="submit"><?php esc_html_e( 'Clock out', 'doughboss' ); ?></button>
					</form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="doughboss_clock_in" />
						<?php wp_nonce_field( 'doughboss_clock_in' ); ?>
						<div class="db-timeclock-location">
							<label for="db-timeclock-location"><?php esc_html_e( 'Check in at', 'doughboss' ); ?></label>
							<?php if ( $manager && count( $locations ) > 1 ) : ?>
								<select id="db-timeclock-location" name="location_id" required>
									<option value=""><?php esc_html_e( 'Choose a shop', 'doughboss' ); ?></option>
									<?php foreach ( $locations as $shop ) : ?><option value="<?php echo esc_attr( $shop->id ); ?>"><?php echo esc_html( $shop->name ); ?></option><?php endforeach; ?>
								</select>
							<?php elseif ( $manager && 1 === count( $locations ) ) : ?>
								<strong><?php echo esc_html( $locations[0]->name ); ?></strong><input type="hidden" name="location_id" value="<?php echo esc_attr( $locations[0]->id ); ?>" />
							<?php elseif ( $location ) : ?>
								<strong><?php echo esc_html( $location->name ); ?></strong><input type="hidden" name="location_id" value="<?php echo esc_attr( $location->id ); ?>" />
							<?php else : ?>
								<strong class="db-timeclock-location-missing"><?php echo esc_html( is_wp_error( $assigned ) ? $assigned->get_error_message() : __( 'No active shop is available.', 'doughboss' ) ); ?></strong>
							<?php endif; ?>
						</div>
						<button class="db-timeclock-button" type="submit" <?php disabled( ( ! $manager && ! $location ) || empty( $locations ) ); ?>><?php esc_html_e( 'Clock in', 'doughboss' ); ?></button>
					</form>
				<?php endif; ?>
				<p class="db-timeclock-help"><?php esc_html_e( 'Your time and confirmed shop are recorded. Worked time subtracts only breaks you record on the QR kiosk.', 'doughboss' ); ?></p>
			</section>
		</main>
		<script>
		(function(){var seconds=90,shown=false,timer=setInterval(function(){seconds-=1;if(seconds<=0){clearInterval(timer);window.location.href=<?php echo wp_json_encode( wp_logout_url( home_url( '/staff-clock/' ) ) ); ?>;}else if(seconds<=30&&!shown){shown=true;var note=document.createElement('p');note.className='db-timeclock-idle';note.setAttribute('role','status');note.textContent=<?php echo wp_json_encode( __( 'For privacy, this screen will sign out automatically after inactivity.', 'doughboss' ) ); ?>;document.querySelector('.db-timeclock-card').appendChild(note);}},1000);['pointerdown','keydown'].forEach(function(name){document.addEventListener(name,function(){seconds=90;shown=false;var note=document.querySelector('.db-timeclock-idle');if(note){note.remove();}},{passive:true});});}());
		</script>
		<?php
	}

	/** Store a location-bound clock-in once, then sign the shared device out. */
	public function handle_clock_in() {
		$this->verify_action( 'doughboss_clock_in' );
		$user_id    = get_current_user_id();
		$location   = $this->resolve_clock_in_location();
		if ( is_wp_error( $location ) ) {
			$this->redirect_back( 'location' );
		}

		$status = self::clock_in_for_user( $user_id, $location, 'staff_portal' );
		$this->redirect_back( $status );
	}

	/** Close the current user's open shift once, then sign the shared device out. */
	public function handle_clock_out() {
		$this->verify_action( 'doughboss_clock_out' );
		$user_id = get_current_user_id();
		$status  = self::clock_out_for_user( $user_id );
		$this->redirect_back( $status );
	}

	/** Verify staff identity, capability and CSRF token. */
	private function verify_action( $action ) {
		if ( ! is_user_logged_in() || ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to use the staff clock.', 'doughboss' ), esc_html__( 'Staff access required', 'doughboss' ), array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
		if ( ! self::storage_ready() ) {
			$this->redirect_back( 'unavailable' );
		}
	}

	/** Resolve a real active shop; location zero is never valid for attendance. */
	private function resolve_clock_in_location() {
		if ( current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by handle_clock_in() immediately before this call.
			$location_id = isset( $_POST['location_id'] ) ? absint( $_POST['location_id'] ) : 0;
			$active      = DoughBoss_Locations::all( true );
			if ( ! $location_id && 1 === count( $active ) ) {
				$location_id = (int) $active[0]->id;
			}
		} else {
			$location_id = DoughBoss_Staff_Scope::assigned_location_id( get_current_user_id() );
			if ( is_wp_error( $location_id ) ) {
				return $location_id;
			}
		}
		$location = DoughBoss_Locations::get( $location_id );
		return $location && 1 === (int) $location->is_active ? $location : new WP_Error( 'doughboss_clock_location_required', __( 'Choose an active DoughBoss shop.', 'doughboss' ) );
	}

	/**
	 * Create one attendance shift with immutable staff/shop/roster snapshots.
	 *
	 * Badge login calls this inside its own named lock, hence $acquire_lock.
	 * The standard WordPress staff portal uses the safe default and owns its lock.
	 *
	 * @param int    $user_id Employee WordPress ID.
	 * @param object $location Verified active DoughBoss location.
	 * @param string $source Attendance source label.
	 * @param bool   $acquire_lock Whether this method must acquire the user lock.
	 * @return string Transition result.
	 */
	public static function clock_in_for_user( $user_id, $location, $source = 'staff_portal', $acquire_lock = true ) {
		$work = static function () use ( $user_id, $location, $source ) {
			if ( self::open_shift( $user_id ) ) {
				return 'already-in';
			}
			global $wpdb;
			$now      = current_time( 'mysql', true );
			$user     = get_userdata( $user_id );
			$timezone = self::valid_timezone_name( isset( $location->timezone ) ? $location->timezone : '' );
			if ( ! $user || ! $location || empty( $location->id ) ) {
				return 'error';
			}
			$roster = DoughBoss_Staff_Scope::roster_snapshot( $user_id, $timezone, strtotime( $now . ' UTC' ) );
			$ok     = $wpdb->insert(
				self::table(),
				array(
					'user_id'               => absint( $user_id ),
					'staff_name'            => (string) $user->display_name,
					'staff_login'           => (string) $user->user_login,
					'location_id'           => (int) $location->id,
					'location_name'         => (string) $location->name,
					'timezone_snapshot'     => $timezone,
					'clock_in_utc'           => $now,
					'scheduled_start_local' => (string) $roster['start'],
					'late_grace_minutes'    => (int) $roster['grace'],
					'late_minutes'          => (int) $roster['late'],
					'open_guard'            => 1,
					'source'                => sanitize_key( $source ),
					'created_at'            => $now,
					'updated_at'            => $now,
				),
				array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return false !== $ok && self::open_shift( $user_id ) ? 'in' : 'error';
		};
		return $acquire_lock ? self::with_user_lock( $user_id, $work ) : (string) call_user_func( $work );
	}

	/** Close the employee's one open shift, retaining immutable shift evidence. */
	public static function clock_out_for_user( $user_id, $acquire_lock = true ) {
		$work = static function () use ( $user_id ) {
			$shift = self::open_shift( $user_id );
			if ( ! $shift ) {
				return 'already-out';
			}
			if ( class_exists( 'DoughBoss_Staff_Badge' ) && DoughBoss_Staff_Badge::open_break( (int) $shift->id ) ) {
				return 'break-open';
			}
			global $wpdb;
			$now = current_time( 'mysql', true );
			$ok  = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET clock_out_utc = %s, open_guard = NULL, updated_at = %s WHERE id = %d AND user_id = %d AND clock_out_utc IS NULL AND open_guard = 1', $now, $now, (int) $shift->id, absint( $user_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			return 1 === $ok ? 'out' : 'error';
		};
		return $acquire_lock ? self::with_user_lock( $user_id, $work ) : (string) call_user_func( $work );
	}

	/** Run a transition under a database-server lock scoped to this site/user. */
	public static function with_user_lock( $user_id, $callback ) {
		global $wpdb;
		$lock = 'doughboss_clock_' . get_current_blog_id() . '_' . absint( $user_id );
		$held = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== $held ) {
			return 'error';
		}
		try {
			return (string) call_user_func( $callback );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/** @return object|null */
	public static function open_shift( $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND clock_out_utc IS NULL AND open_guard = 1 ORDER BY id DESC LIMIT 1', absint( $user_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Redirect to the protected clock screen and always release the kiosk identity. */
	private function redirect_back( $status ) {
		wp_logout();
		wp_safe_redirect( add_query_arg( 'db_clock', sanitize_key( $status ), home_url( '/staff-clock/' ) ) );
		exit;
	}

	/** Require manager authority and verified attendance storage. */
	private static function verify_manager_access() {
		if ( ! current_user_can( 'manage_doughboss' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage staff timesheets.', 'doughboss' ), esc_html__( 'Timesheet access required', 'doughboss' ), array( 'response' => 403 ) );
		}
		if ( ! self::storage_ready() ) {
			wp_die( esc_html__( 'Attendance storage is not ready. No timesheet operation was performed.', 'doughboss' ), esc_html__( 'Staff clock unavailable', 'doughboss' ), array( 'response' => 503 ) );
		}
	}

	/** Manager timesheet screen. */
	public function render_admin_page() {
		self::verify_manager_access();
		$days        = self::requested_days();
		$location_id = self::requested_location();
		$rows        = self::timesheet_rows( $days, $location_id, 1000 );
		$locations   = DoughBoss_Locations::all( false );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only result from a nonce-protected manager action.
		$correction  = isset( $_GET['db_shift'] ) ? sanitize_key( wp_unslash( $_GET['db_shift'] ) ) : '';
		?>
		<div class="wrap doughboss-timesheet"><h1><?php esc_html_e( 'Staff Timesheet', 'doughboss' ); ?></h1>
			<?php if ( 'corrected' === $correction ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The open shift was closed and the manager reason was added to the permanent audit trail.', 'doughboss' ); ?></p></div><?php endif; ?>
			<?php if ( 'error' === $correction ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'The shift was not changed. Please review it and try again.', 'doughboss' ); ?></p></div><?php endif; ?>
			<p class="description"><?php esc_html_e( 'Active shifts appear first. Times are stored in UTC and displayed in each recorded shop timezone. Worked time subtracts only recorded breaks; roster lateness is snapshotted at clock-in. Manager closures require a reason and are permanently audited.', 'doughboss' ); ?></p>
			<?php if ( count( $rows ) >= 1000 ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'This screen shows the newest 1,000 matching shifts. CSV export includes up to the newest 5,000 matching shifts; narrow the filters if that limit is reached.', 'doughboss' ); ?></p></div><?php endif; ?>
			<form method="get" style="margin:16px 0;"><input type="hidden" name="page" value="doughboss-timeclock" />
				<label for="db-timesheet-days"><?php esc_html_e( 'Period', 'doughboss' ); ?></label> <select id="db-timesheet-days" name="days"><option value="7" <?php selected( $days, 7 ); ?>><?php esc_html_e( '7 days', 'doughboss' ); ?></option><option value="14" <?php selected( $days, 14 ); ?>><?php esc_html_e( '14 days', 'doughboss' ); ?></option><option value="30" <?php selected( $days, 30 ); ?>><?php esc_html_e( '30 days', 'doughboss' ); ?></option><option value="90" <?php selected( $days, 90 ); ?>><?php esc_html_e( '90 days', 'doughboss' ); ?></option></select>
				<label for="db-timesheet-location"><?php esc_html_e( 'Shop', 'doughboss' ); ?></label> <select id="db-timesheet-location" name="location"><option value="0"><?php esc_html_e( 'All shops', 'doughboss' ); ?></option><?php foreach ( $locations as $shop ) : ?><option value="<?php echo esc_attr( $shop->id ); ?>" <?php selected( $location_id, (int) $shop->id ); ?>><?php echo esc_html( $shop->name ); ?></option><?php endforeach; ?></select>
				<button class="button"><?php esc_html_e( 'Apply', 'doughboss' ); ?></button>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'doughboss_export_timesheet', 'days' => $days, 'location' => $location_id ), admin_url( 'admin-post.php' ) ), 'doughboss_export_timesheet' ) ); ?>"><?php esc_html_e( 'Export CSV', 'doughboss' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=doughboss-staff-badges' ) ); ?>"><?php esc_html_e( 'Staff QR badges', 'doughboss' ); ?></a>
				<a class="button button-primary" href="<?php echo esc_url( home_url( '/staff-clock/' ) ); ?>"><?php esc_html_e( 'Open staff clock', 'doughboss' ); ?></a>
			</form>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Staff member', 'doughboss' ); ?></th><th><?php esc_html_e( 'Shop', 'doughboss' ); ?></th><th><?php esc_html_e( 'Clock in', 'doughboss' ); ?></th><th><?php esc_html_e( 'Clock out', 'doughboss' ); ?></th><th><?php esc_html_e( 'Breaks', 'doughboss' ); ?></th><th><?php esc_html_e( 'Worked', 'doughboss' ); ?></th><th><?php esc_html_e( 'Late', 'doughboss' ); ?></th><th><?php esc_html_e( 'Status / correction', 'doughboss' ); ?></th></tr></thead><tbody>
			<?php if ( empty( $rows ) ) : ?><tr><td colspan="8"><?php esc_html_e( 'No shift records in this period.', 'doughboss' ); ?></td></tr><?php else : foreach ( $rows as $row ) : self::render_row( $row ); endforeach; endif; ?>
			</tbody></table>
		</div>
		<?php
	}

	/**
	 * Close a forgotten open shift as one transaction with a mandatory audit
	 * event. Managers cannot silently rewrite the employee's original record.
	 */
	public function handle_correction() {
		self::verify_manager_access();
		$shift_id = isset( $_POST['shift_id'] ) ? absint( $_POST['shift_id'] ) : 0;
		check_admin_referer( 'doughboss_correct_shift_' . $shift_id );
		$reason = isset( $_POST['reason'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) ) : '';
		$reason = substr( $reason, 0, 500 );
		if ( ! $shift_id || '' === $reason ) {
			wp_die( esc_html__( 'A clear correction reason is required.', 'doughboss' ), esc_html__( 'Reason required', 'doughboss' ), array( 'response' => 400 ) );
		}

		global $wpdb;
		$initial = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $shift_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $initial ) {
			wp_die( esc_html__( 'The staff shift could not be found.', 'doughboss' ), esc_html__( 'Shift not found', 'doughboss' ), array( 'response' => 404 ) );
		}
		$actor_id = get_current_user_id();
		$status   = $this->with_user_lock(
			(int) $initial->user_id,
			static function () use ( $shift_id, $actor_id, $reason ) {
				global $wpdb;
				if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return 'error';
				}
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d FOR UPDATE', $shift_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $row || $row->clock_out_utc || 1 !== (int) $row->open_guard ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return 'error';
				}
				$before = wp_json_encode( $row );
				$now    = current_time( 'mysql', true );
				if ( class_exists( 'DoughBoss_Staff_Badge' ) && DoughBoss_Staff_Badge::open_break( $shift_id ) ) {
					$closed_break = $wpdb->query( $wpdb->prepare( 'UPDATE ' . DoughBoss_Staff_Badge::breaks_table() . ' SET break_end_utc = %s, open_guard = NULL, updated_at = %s WHERE shift_id = %d AND break_end_utc IS NULL AND open_guard = 1', $now, $now, $shift_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
					if ( 1 !== $closed_break ) {
						$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						return 'error';
					}
				}
				$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET clock_out_utc = %s, open_guard = NULL, updated_at = %s WHERE id = %d AND clock_out_utc IS NULL AND open_guard = 1', $now, $now, $shift_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				if ( 1 !== $updated ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return 'error';
				}
				$row->clock_out_utc = $now;
				$row->open_guard    = null;
				$row->updated_at    = $now;
				$event = $wpdb->insert(
					self::events_table(),
					array(
						'shift_id'        => $shift_id,
						'event_type'      => 'manager_closed',
						'actor_user_id'   => $actor_id,
						'reason'          => $reason,
						'before_json'     => $before,
						'after_json'      => wp_json_encode( $row ),
						'occurred_at_utc' => $now,
					),
					array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( false === $event ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return 'error';
				}
				if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return 'error';
				}
				return 'corrected';
			}
		);
		wp_safe_redirect( add_query_arg( 'db_shift', $status, admin_url( 'admin.php?page=doughboss-timeclock' ) ) );
		exit;
	}

	/** Download the filtered manager timesheet as CSV. */
	public function handle_export() {
		self::verify_manager_access();
		check_admin_referer( 'doughboss_export_timesheet' );
		$rows = self::timesheet_rows( self::requested_days(), self::requested_location(), 5000 );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=doughboss-timesheet-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			wp_die( esc_html__( 'The timesheet export could not be opened.', 'doughboss' ), esc_html__( 'Export failed', 'doughboss' ), array( 'response' => 500 ) );
		}
		fputcsv( $out, array( 'Staff member', 'Username', 'Shop', 'Clock in', 'Clock out', 'Break minutes', 'Worked minutes', 'Scheduled start', 'Late grace minutes', 'Late minutes', 'Status' ) );
		foreach ( $rows as $row ) {
			$in  = strtotime( $row->clock_in_utc . ' UTC' );
			$out_time = $row->clock_out_utc ? strtotime( $row->clock_out_utc . ' UTC' ) : 0;
			$timezone = self::timezone( $row->timezone_snapshot );
			$breaks = self::break_minutes( $row, $out_time ? $out_time : time() );
			$worked = self::worked_minutes( $row );
			fputcsv( $out, array( self::csv_cell( $row->staff_name ), self::csv_cell( $row->staff_login ), self::csv_cell( $row->location_name ), wp_date( 'Y-m-d H:i:s T', $in, $timezone ), $out_time ? wp_date( 'Y-m-d H:i:s T', $out_time, $timezone ) : '', $breaks, $worked, self::csv_cell( $row->scheduled_start_local ), (int) $row->late_grace_minutes, (int) $row->late_minutes, $out_time ? 'Complete' : 'Clocked in' ) );
		}
		fclose( $out );
		exit;
	}

	/** @return object[] */
	private static function timesheet_rows( $days, $location_id, $limit ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
		$sql   = "SELECT s.* FROM {$table} s WHERE (s.clock_in_utc >= %s OR s.clock_out_utc IS NULL)";
		$args  = array( $since );
		if ( $location_id ) {
			$sql   .= ' AND s.location_id = %d';
			$args[] = $location_id;
		}
		$sql   .= ' ORDER BY s.clock_out_utc IS NULL DESC, s.clock_in_utc DESC LIMIT %d';
		$args[] = max( 1, min( 5000, (int) $limit ) );
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Render one escaped manager report row. */
	private static function render_row( $row ) {
		$in       = strtotime( $row->clock_in_utc . ' UTC' );
		$out      = $row->clock_out_utc ? strtotime( $row->clock_out_utc . ' UTC' ) : 0;
		$breaks   = self::break_minutes( $row, $out ? $out : time() );
		$minutes  = self::worked_minutes( $row );
		$timezone = self::timezone( $row->timezone_snapshot );
		$late = empty( $row->scheduled_start_local ) ? __( 'No roster', 'doughboss' ) : ( (int) $row->late_minutes > 0 ? sprintf( __( '%d min', 'doughboss' ), (int) $row->late_minutes ) : __( 'On time', 'doughboss' ) );
		?><tr><td><strong><?php echo esc_html( $row->staff_name ? $row->staff_name : $row->staff_login ); ?></strong></td><td><?php echo esc_html( $row->location_name ); ?></td><td><?php echo esc_html( wp_date( 'D j M, g:ia', $in, $timezone ) ); ?></td><td><?php echo esc_html( $out ? wp_date( 'D j M, g:ia', $out, $timezone ) : '—' ); ?></td><td><?php echo esc_html( self::duration_label( $breaks ) ); ?></td><td><?php echo esc_html( self::duration_label( $minutes ) ); ?></td><td><?php echo esc_html( $late ); ?></td><td><?php if ( $out ) : echo esc_html__( 'Complete', 'doughboss' ); else : ?><strong><?php esc_html_e( 'Clocked in', 'doughboss' ); ?></strong><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px"><input type="hidden" name="action" value="doughboss_correct_shift"><input type="hidden" name="shift_id" value="<?php echo esc_attr( $row->id ); ?>"><?php wp_nonce_field( 'doughboss_correct_shift_' . (int) $row->id ); ?><label class="screen-reader-text" for="db-shift-reason-<?php echo esc_attr( $row->id ); ?>"><?php esc_html_e( 'Required correction reason', 'doughboss' ); ?></label><input id="db-shift-reason-<?php echo esc_attr( $row->id ); ?>" name="reason" maxlength="500" required placeholder="<?php esc_attr_e( 'Required reason', 'doughboss' ); ?>"><button class="button" type="submit"><?php esc_html_e( 'Close now', 'doughboss' ); ?></button></form><?php endif; ?></td></tr><?php
	}

	/** Neutralize spreadsheet formula injection in operator-controlled CSV cells. */
	private static function csv_cell( $value ) {
		$value = str_replace( array( "\r", "\n", "\t" ), ' ', (string) $value );
		if ( preg_match( '/^\s*[=+\-@]/', $value ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/** Return a valid immutable shift timezone, falling back to the site zone. */
	private static function timezone( $name ) {
		try {
			return new DateTimeZone( (string) $name );
		} catch ( Exception $e ) {
			return wp_timezone();
		}
	}

	/** Return a storable timezone identifier, never a blank or invalid value. */
	private static function valid_timezone_name( $name ) {
		try {
			return ( new DateTimeZone( (string) $name ) )->getName();
		} catch ( Exception $e ) {
			return wp_timezone()->getName();
		}
	}

	/** @return int */
	private static function requested_days() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report filter; export verifies its action nonce.
		$days = isset( $_REQUEST['days'] ) ? absint( $_REQUEST['days'] ) : 14;
		return in_array( $days, array( 7, 14, 30, 90 ), true ) ? $days : 14;
	}

	/** @return int */
	private static function requested_location() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report filter; export verifies its action nonce.
		$location_id = isset( $_REQUEST['location'] ) ? absint( $_REQUEST['location'] ) : 0;
		return $location_id && DoughBoss_Locations::get( $location_id ) ? $location_id : 0;
	}

	/** @return string */
	public static function duration_label( $minutes ) {
		$hours = (int) floor( $minutes / 60 );
		$mins  = (int) $minutes % 60;
		return $hours ? sprintf( __( '%1$dh %2$dm', 'doughboss' ), $hours, $mins ) : sprintf( __( '%dm', 'doughboss' ), $mins );
	}

	/** Return elapsed shift time less only verified, recorded breaks. */
	public static function worked_minutes( $shift ) {
		if ( ! $shift || empty( $shift->clock_in_utc ) ) {
			return 0;
		}
		$in    = strtotime( $shift->clock_in_utc . ' UTC' );
		$until = ! empty( $shift->clock_out_utc ) ? strtotime( $shift->clock_out_utc . ' UTC' ) : time();
		return max( 0, (int) floor( ( $until - $in ) / 60 ) - self::break_minutes( $shift, $until ) );
	}

	/** Return only stored break minutes; no automatic or assumed deduction exists. */
	private static function break_minutes( $shift, $until ) {
		return class_exists( 'DoughBoss_Staff_Badge' ) ? DoughBoss_Staff_Badge::break_minutes_for_shift( $shift, $until ) : 0;
	}
}
