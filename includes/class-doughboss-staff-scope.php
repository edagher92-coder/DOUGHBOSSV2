<?php
/**
 * Server-enforced store assignment for operational staff accounts.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps KDS-only accounts inside their assigned shop while managers retain the
 * deliberate all-store view.
 */
final class DoughBoss_Staff_Scope {

	const LOCATION_META = 'doughboss_location_id';
	const ROSTER_META   = 'doughboss_staff_roster';
	const NONCE_ACTION  = 'doughboss_staff_location';
	const NONCE_NAME    = 'doughboss_staff_location_nonce';

	/** @return void */
	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_field' ) );
	}

	/**
	 * Resolve the current user's authoritative KDS shop.
	 *
	 * Managers/administrators return 0, meaning an intentional all-store scope.
	 * A KDS account without an assignment inherits the sole active location for
	 * migration-safe single-store installs. Once multiple stores exist, missing
	 * or inactive assignments fail closed.
	 *
	 * @return int|WP_Error Location ID, 0 for manager override, or an error.
	 */
	public static function current_location_id() {
		if ( current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ) ) {
			return 0;
		}
		if ( ! current_user_can( 'manage_doughboss_kds' ) ) {
			return new WP_Error( 'doughboss_staff_location_forbidden', __( 'This account is not allowed to use the kitchen board.', 'doughboss' ), array( 'status' => 403 ) );
		}

		return self::assigned_location_id( get_current_user_id() );
	}

	/**
	 * Resolve an operational staff member's assigned active shop.
	 *
	 * Unlike current_location_id(), this method is also valid for clock-only
	 * staff who must never receive kitchen-board permissions. A single active
	 * shop is migration-safe; a multi-shop site fails closed until management
	 * assigns the employee explicitly.
	 *
	 * @param int $user_id WordPress user ID; zero means the current user.
	 * @return int|WP_Error Active location ID or an assignment error.
	 */
	public static function assigned_location_id( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'doughboss_staff_login_required', __( 'Staff sign-in is required.', 'doughboss' ), array( 'status' => 401 ) );
		}

		$assigned = absint( get_user_meta( $user_id, self::LOCATION_META, true ) );
		if ( $assigned && DoughBoss_Locations::is_valid( $assigned ) ) {
			return $assigned;
		}

		$single = DoughBoss_Locations::single_location_id();
		if ( $single ) {
			return (int) $single;
		}

		return new WP_Error(
			'doughboss_staff_location_required',
			__( 'This staff account needs an active shop assignment before it can check in.', 'doughboss' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Enforce the current KDS user's location against a requested filter.
	 *
	 * @param int $requested Requested location; 0 means caller did not choose.
	 * @return int|WP_Error Effective location, or 0 for a manager all-store view.
	 */
	public static function effective_location_id( $requested = 0 ) {
		$scope = self::current_location_id();
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		$requested = absint( $requested );
		if ( 0 === $scope ) {
			return $requested;
		}
		if ( $requested && $requested !== $scope ) {
			return new WP_Error( 'doughboss_staff_location_forbidden', __( 'This kitchen account cannot access orders from that shop.', 'doughboss' ), array( 'status' => 403 ) );
		}
		return $scope;
	}

	/**
	 * Verify that an order belongs to the current KDS user's shop.
	 *
	 * @param object|null $order Order row.
	 * @return true|WP_Error
	 */
	public static function can_access_order( $order ) {
		$scope = self::current_location_id();
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		if ( 0 === $scope ) {
			return true;
		}
		if ( ! $order || (int) $order->location_id !== (int) $scope ) {
			// Use a not-found response so a staff device cannot enumerate another
			// shop's order IDs.
			return new WP_Error( 'doughboss_order_not_found', __( 'Order not found for this kitchen.', 'doughboss' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * Add the shop selector to WordPress user profiles for administrators.
	 *
	 * @param WP_User $user Profile user.
	 * @return void
	 */
	public static function render_profile_field( $user ) {
		if ( ! current_user_can( 'manage_options' ) || ! $user || ( ! user_can( $user, 'manage_doughboss_kds' ) && ! user_can( $user, 'clock_doughboss_staff' ) ) ) {
			return;
		}
		$current = absint( get_user_meta( $user->ID, self::LOCATION_META, true ) );
		$locations = DoughBoss_Locations::all( true );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<h2><?php esc_html_e( 'DoughBoss staff assignment', 'doughboss' ); ?></h2>
		<table class="form-table" role="presentation"><tr>
			<th><label for="doughboss-location-id"><?php esc_html_e( 'Assigned shop', 'doughboss' ); ?></label></th>
			<td><select id="doughboss-location-id" name="doughboss_location_id">
				<option value="0"><?php esc_html_e( 'No assignment', 'doughboss' ); ?></option>
				<?php foreach ( $locations as $location ) : ?>
					<option value="<?php echo esc_attr( $location->id ); ?>" <?php selected( $current, (int) $location->id ); ?>><?php echo esc_html( $location->name ); ?></option>
				<?php endforeach; ?>
			</select><p class="description"><?php esc_html_e( 'Required for staff clock-in and KDS access when more than one active shop exists. Managers select the shop when clocking in.', 'doughboss' ); ?></p></td>
		</tr></table>
		<?php $roster = self::roster( $user->ID ); ?>
		<h2><?php esc_html_e( 'DoughBoss roster & lateness', 'doughboss' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Optional. A roster start time records lateness at clock-in using this shop’s timezone. Leave a day blank when no roster is set; no lateness is assumed. Break time is only deducted when the employee records a real break on the kiosk.', 'doughboss' ); ?></p>
		<table class="widefat striped" style="max-width:720px"><thead><tr><th><?php esc_html_e( 'Day', 'doughboss' ); ?></th><th><?php esc_html_e( 'Roster start', 'doughboss' ); ?></th><th><?php esc_html_e( 'Grace (minutes)', 'doughboss' ); ?></th></tr></thead><tbody>
		<?php foreach ( self::weekdays() as $day => $label ) : $entry = isset( $roster[ $day ] ) ? $roster[ $day ] : array( 'start' => '', 'grace' => 0 ); ?>
			<tr><td><strong><?php echo esc_html( $label ); ?></strong></td><td><input type="time" name="doughboss_staff_roster[<?php echo esc_attr( $day ); ?>][start]" value="<?php echo esc_attr( $entry['start'] ); ?>"></td><td><input type="number" min="0" max="120" step="1" name="doughboss_staff_roster[<?php echo esc_attr( $day ); ?>][grace]" value="<?php echo esc_attr( $entry['grace'] ); ?>"></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	/**
	 * Save an administrator-reviewed location assignment.
	 *
	 * @param int $user_id Profile user ID.
	 * @return void
	 */
	public static function save_profile_field( $user_id ) {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$nonce = isset( $_POST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}
		$location_id = isset( $_POST['doughboss_location_id'] ) ? absint( $_POST['doughboss_location_id'] ) : 0;
		if ( $location_id && ! DoughBoss_Locations::is_valid( $location_id ) ) {
			return;
		}
		if ( $location_id ) {
			update_user_meta( $user_id, self::LOCATION_META, $location_id );
		} else {
			delete_user_meta( $user_id, self::LOCATION_META );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each nested value is validated below.
		$posted_roster = isset( $_POST['doughboss_staff_roster'] ) && is_array( $_POST['doughboss_staff_roster'] ) ? wp_unslash( $_POST['doughboss_staff_roster'] ) : array();
		$roster        = array();
		foreach ( array_keys( self::weekdays() ) as $day ) {
			$entry = isset( $posted_roster[ $day ] ) && is_array( $posted_roster[ $day ] ) ? $posted_roster[ $day ] : array();
			$start = isset( $entry['start'] ) ? trim( sanitize_text_field( $entry['start'] ) ) : '';
			$grace = isset( $entry['grace'] ) ? absint( $entry['grace'] ) : 0;
			if ( '' === $start ) {
				continue;
			}
			if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start ) ) {
				continue;
			}
			$roster[ (string) $day ] = array( 'start' => $start, 'grace' => min( 120, $grace ) );
		}
		if ( $roster ) {
			update_user_meta( $user_id, self::ROSTER_META, $roster );
		} else {
			delete_user_meta( $user_id, self::ROSTER_META );
		}
	}

	/** Return validated weekly roster data for a staff profile. */
	public static function roster( $user_id ) {
		$raw    = get_user_meta( absint( $user_id ), self::ROSTER_META, true );
		$roster = array();
		if ( ! is_array( $raw ) ) {
			return $roster;
		}
		foreach ( array_keys( self::weekdays() ) as $day ) {
			$entry = isset( $raw[ $day ] ) && is_array( $raw[ $day ] ) ? $raw[ $day ] : array();
			$start = isset( $entry['start'] ) ? (string) $entry['start'] : '';
			if ( preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start ) ) {
				$roster[ $day ] = array( 'start' => $start, 'grace' => min( 120, absint( isset( $entry['grace'] ) ? $entry['grace'] : 0 ) ) );
			}
		}
		return $roster;
	}

	/** Snapshot the current roster decision so later profile edits cannot rewrite history. */
	public static function roster_snapshot( $user_id, $timezone, $timestamp ) {
		try {
			$zone = new DateTimeZone( (string) $timezone );
		} catch ( Exception $e ) {
			$zone = wp_timezone();
		}
		$local  = ( new DateTimeImmutable( '@' . max( 1, (int) $timestamp ) ) )->setTimezone( $zone );
		$day    = $local->format( 'w' );
		$roster = self::roster( $user_id );
		$entry  = isset( $roster[ $day ] ) ? $roster[ $day ] : null;
		if ( ! $entry ) {
			return array( 'start' => '', 'grace' => 0, 'late' => 0 );
		}
		list( $hour, $minute ) = array_map( 'intval', explode( ':', $entry['start'] ) );
		$scheduled = ( $hour * 60 ) + $minute;
		$actual    = ( (int) $local->format( 'G' ) * 60 ) + (int) $local->format( 'i' );
		return array(
			'start' => $entry['start'],
			'grace' => (int) $entry['grace'],
			'late'  => max( 0, $actual - $scheduled - (int) $entry['grace'] ),
		);
	}

	/** @return string[] Sunday through Saturday for the WordPress profile form. */
	private static function weekdays() {
		return array(
			'0' => __( 'Sunday', 'doughboss' ),
			'1' => __( 'Monday', 'doughboss' ),
			'2' => __( 'Tuesday', 'doughboss' ),
			'3' => __( 'Wednesday', 'doughboss' ),
			'4' => __( 'Thursday', 'doughboss' ),
			'5' => __( 'Friday', 'doughboss' ),
			'6' => __( 'Saturday', 'doughboss' ),
		);
	}
}
