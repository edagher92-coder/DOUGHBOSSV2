<?php
/**
 * Staff clock-in and clock-out records.
 *
 * This deliberately uses each employee's normal WordPress staff account.
 * A shared kitchen password would make a timesheet impossible to trust, so it
 * is not offered as a shortcut. The public portal is login protected and the
 * records live locally in WordPress; no payroll provider or customer data is
 * involved.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DoughBoss_Timeclock {

	/** @return string */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_staff_shifts';
	}

	/** @return void */
	public function init() {
		add_action( 'admin_post_doughboss_clock_in', array( $this, 'clock_in' ) );
		add_action( 'admin_post_doughboss_clock_out', array( $this, 'clock_out' ) );
		add_action( 'wp_head', array( $this, 'noindex_portal' ) );
	}

	/** @return bool */
	public static function can_clock() {
		return current_user_can( 'clock_doughboss_staff' );
	}

	/** Render the manager-only timesheet, including currently active shifts. */
	public static function render_timesheet() {
		if ( ! current_user_can( 'manage_doughboss' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view the timesheet.', 'doughboss' ), 403 );
		}
		global $wpdb;
		$table  = self::table();
		$users  = $wpdb->users;
		$days   = isset( $_GET['days'] ) ? max( 1, min( 90, absint( $_GET['days'] ) ) ) : 14; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$since  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT s.*, u.display_name, u.user_login FROM {$table} s LEFT JOIN {$users} u ON u.ID = s.user_id WHERE s.clock_in_utc >= %s OR s.clock_out_utc IS NULL ORDER BY s.clock_out_utc IS NULL DESC, s.clock_in_utc DESC LIMIT 500", $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Staff timesheet', 'doughboss' ); ?></h1><p class="description"><?php esc_html_e( 'Clock records are stored in WordPress in UTC and displayed in the site timezone. This is an operational record, not payroll calculation.', 'doughboss' ); ?></p>
			<form method="get" style="margin:16px 0;"><input type="hidden" name="page" value="doughboss-timeclock" /><label for="db-timeclock-days"><?php esc_html_e( 'Show', 'doughboss' ); ?></label> <select id="db-timeclock-days" name="days"><option value="7" <?php selected( $days, 7 ); ?>><?php esc_html_e( 'Last 7 days', 'doughboss' ); ?></option><option value="14" <?php selected( $days, 14 ); ?>><?php esc_html_e( 'Last 14 days', 'doughboss' ); ?></option><option value="30" <?php selected( $days, 30 ); ?>><?php esc_html_e( 'Last 30 days', 'doughboss' ); ?></option><option value="90" <?php selected( $days, 90 ); ?>><?php esc_html_e( 'Last 90 days', 'doughboss' ); ?></option></select> <button class="button"><?php esc_html_e( 'Apply', 'doughboss' ); ?></button></form>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Staff member', 'doughboss' ); ?></th><th><?php esc_html_e( 'Clock in', 'doughboss' ); ?></th><th><?php esc_html_e( 'Clock out', 'doughboss' ); ?></th><th><?php esc_html_e( 'Worked', 'doughboss' ); ?></th><th><?php esc_html_e( 'Status', 'doughboss' ); ?></th></tr></thead><tbody>
			<?php if ( empty( $rows ) ) : ?><tr><td colspan="5"><?php esc_html_e( 'No clock records yet.', 'doughboss' ); ?></td></tr><?php else : foreach ( $rows as $row ) : $in = strtotime( $row->clock_in_utc . ' UTC' ); $out = $row->clock_out_utc ? strtotime( $row->clock_out_utc . ' UTC' ) : null; ?>
				<tr><td><strong><?php echo esc_html( $row->display_name ? $row->display_name : $row->user_login ); ?></strong></td><td><?php echo esc_html( wp_date( 'D j M, g:ia', $in ) ); ?></td><td><?php echo esc_html( $out ? wp_date( 'D j M, g:ia', $out ) : '—' ); ?></td><td><?php echo esc_html( self::duration_label( max( 0, (int) floor( ( ( $out ? $out : time() ) - $in ) / 60 ) ) ) ); ?></td><td><?php echo esc_html( $out ? __( 'Complete', 'doughboss' ) : __( 'Clocked in', 'doughboss' ) ); ?></td></tr>
			<?php endforeach; endif; ?>
			</tbody></table><p><a class="button button-primary" href="<?php echo esc_url( self::portal_url() ); ?>"><?php esc_html_e( 'Open staff clock', 'doughboss' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Render [doughboss_staff_clock].
	 *
	 * @return string
	 */
	public static function render_portal() {
		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<section class="db-timeclock db-timeclock--login"><h2>%1$s</h2><p>%2$s</p><a class="db-timeclock__button" href="%3$s">%4$s</a></section>',
				esc_html__( 'Staff clock', 'doughboss' ),
				esc_html__( 'Sign in with your own DoughBoss staff account to start or finish your shift.', 'doughboss' ),
				esc_url( wp_login_url( self::portal_url() ) ),
				esc_html__( 'Staff sign in', 'doughboss' )
			);
		}

		if ( ! self::can_clock() ) {
			return '<section class="db-timeclock"><h2>' . esc_html__( 'Staff access required', 'doughboss' ) . '</h2><p>' . esc_html__( 'Ask a manager to give your account the DoughBoss Kitchen or DoughBoss Manager role.', 'doughboss' ) . '</p></section>';
		}

		$user  = wp_get_current_user();
		$shift = self::open_shift( get_current_user_id() );
		$notice = isset( $_GET['db_clock'] ) ? sanitize_key( wp_unslash( $_GET['db_clock'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect notice.
		$minutes = $shift ? max( 0, (int) floor( ( time() - strtotime( $shift->clock_in_utc . ' UTC' ) ) / 60 ) ) : 0;

		ob_start();
		?>
		<section class="db-timeclock" aria-labelledby="db-clock-title">
			<div class="db-timeclock__head"><p class="db-timeclock__eyebrow"><?php esc_html_e( 'DoughBoss staff', 'doughboss' ); ?></p><h2 id="db-clock-title"><?php esc_html_e( 'Your shift', 'doughboss' ); ?></h2><p><?php echo esc_html( sprintf( __( 'Hi %s', 'doughboss' ), $user->display_name ) ); ?></p></div>
			<?php if ( 'in' === $notice ) : ?><p class="db-timeclock__notice" role="status"><?php esc_html_e( 'You are clocked in. Have a great shift.', 'doughboss' ); ?></p><?php endif; ?>
			<?php if ( 'out' === $notice ) : ?><p class="db-timeclock__notice" role="status"><?php esc_html_e( 'You are clocked out. Thank you for today.', 'doughboss' ); ?></p><?php endif; ?>
			<?php if ( 'error' === $notice ) : ?><p class="db-timeclock__error" role="alert"><?php esc_html_e( 'We could not update your shift. Please try again or ask a manager.', 'doughboss' ); ?></p><?php endif; ?>
			<div class="db-timeclock__card <?php echo $shift ? 'is-clocked-in' : 'is-clocked-out'; ?>">
				<p class="db-timeclock__status"><?php echo esc_html( $shift ? __( 'Clocked in', 'doughboss' ) : __( 'Not clocked in', 'doughboss' ) ); ?></p>
				<?php if ( $shift ) : ?>
					<p class="db-timeclock__time"><?php echo esc_html( sprintf( __( 'Started %1$s · %2$s', 'doughboss' ), wp_date( get_option( 'time_format' ), strtotime( $shift->clock_in_utc . ' UTC' ) ), self::duration_label( $minutes ) ) ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="doughboss_clock_out" /><?php wp_nonce_field( 'doughboss_clock_out' ); ?><input type="hidden" name="redirect_to" value="<?php echo esc_url( self::portal_url() ); ?>" /><button class="db-timeclock__button db-timeclock__button--out" type="submit"><?php esc_html_e( 'Clock out', 'doughboss' ); ?></button></form>
				<?php else : ?>
					<p class="db-timeclock__time"><?php esc_html_e( 'Tap once when you arrive.', 'doughboss' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="doughboss_clock_in" /><?php wp_nonce_field( 'doughboss_clock_in' ); ?><input type="hidden" name="redirect_to" value="<?php echo esc_url( self::portal_url() ); ?>" /><button class="db-timeclock__button" type="submit"><?php esc_html_e( 'Clock in', 'doughboss' ); ?></button></form>
				<?php endif; ?>
			</div>
			<p class="db-timeclock__help"><?php esc_html_e( 'Use your own account only. This records start and finish times for the manager timesheet; it does not process payroll.', 'doughboss' ); ?></p>
		</section>
		<?php
		return ob_get_clean();
	}

	/** @return void */
	public function clock_in() {
		$this->verify_request( 'doughboss_clock_in' );
		$user_id = get_current_user_id();
		if ( self::open_shift( $user_id ) ) {
			$this->redirect( 'in' );
		}
		global $wpdb;
		$lock = 'doughboss_clock_' . $user_id;
		// Serialize the only state transition that matters: one open shift per person.
		$locked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== $locked ) {
			$this->redirect( 'error' );
		}
		try {
			if ( ! self::open_shift( $user_id ) ) {
				$wpdb->insert( self::table(), array( 'user_id' => $user_id, 'location_id' => self::default_location_id(), 'clock_in_utc' => current_time( 'mysql', true ), 'source' => 'staff_portal', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%s', '%s', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->redirect( 'in' );
	}

	/** @return void */
	public function clock_out() {
		$this->verify_request( 'doughboss_clock_out' );
		$shift = self::open_shift( get_current_user_id() );
		if ( ! $shift ) {
			$this->redirect( 'out' );
		}
		global $wpdb;
		$updated = $wpdb->update( self::table(), array( 'clock_out_utc' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $shift->id, 'user_id' => get_current_user_id(), 'clock_out_utc' => null ), array( '%s', '%s' ), array( '%d', '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->redirect( false === $updated ? 'error' : 'out' );
	}

	/** @return void */
	private function verify_request( $action ) {
		if ( ! is_user_logged_in() || ! self::can_clock() ) {
			wp_die( esc_html__( 'You do not have permission to use the staff clock.', 'doughboss' ), 403 );
		}
		check_admin_referer( $action );
	}

	/** @return object|null */
	public static function open_shift( $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND clock_out_utc IS NULL ORDER BY id DESC LIMIT 1', $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return int */
	private static function default_location_id() {
		$locations = class_exists( 'DoughBoss_Locations' ) ? DoughBoss_Locations::all( true ) : array();
		return ! empty( $locations[0]->id ) ? (int) $locations[0]->id : 0;
	}

	/** @return string */
	public static function portal_url() {
		$page = get_page_by_path( 'staff-clock' );
		return $page ? get_permalink( $page ) : home_url( '/staff-clock/' );
	}

	/** @return void */
	private function redirect( $status ) {
		$target = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : self::portal_url(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by caller.
		wp_safe_redirect( add_query_arg( 'db_clock', $status, wp_validate_redirect( $target, self::portal_url() ) ) );
		exit;
	}

	/** @return string */
	private static function duration_label( $minutes ) {
		$hours = floor( $minutes / 60 );
		$mins  = $minutes % 60;
		return $hours ? sprintf( _n( '%1$dh %2$dm', '%1$dh %2$dm', $hours, 'doughboss' ), $hours, $mins ) : sprintf( __( '%dm', 'doughboss' ), $mins );
	}

	/** @return void */
	public function noindex_portal() {
		if ( is_page( 'staff-clock' ) ) {
			echo "<meta name=\"robots\" content=\"noindex,nofollow\" />\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant safe markup.
		}
	}
}
