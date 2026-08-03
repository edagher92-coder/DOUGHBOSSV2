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

		$user_id = get_current_user_id();
		$assigned = $user_id ? absint( get_user_meta( $user_id, self::LOCATION_META, true ) ) : 0;
		if ( $assigned && DoughBoss_Locations::is_valid( $assigned ) ) {
			return $assigned;
		}

		$single = DoughBoss_Locations::single_location_id();
		if ( $single ) {
			return (int) $single;
		}

		return new WP_Error(
			'doughboss_staff_location_required',
			__( 'This kitchen account needs an active shop assignment before it can view or change orders.', 'doughboss' ),
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
		if ( ! current_user_can( 'manage_options' ) || ! $user || ! user_can( $user, 'manage_doughboss_kds' ) ) {
			return;
		}
		$current = absint( get_user_meta( $user->ID, self::LOCATION_META, true ) );
		$locations = DoughBoss_Locations::all( true );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<h2><?php esc_html_e( 'DoughBoss kitchen assignment', 'doughboss' ); ?></h2>
		<table class="form-table" role="presentation"><tr>
			<th><label for="doughboss-location-id"><?php esc_html_e( 'Assigned shop', 'doughboss' ); ?></label></th>
			<td><select id="doughboss-location-id" name="doughboss_location_id">
				<option value="0"><?php esc_html_e( 'No assignment', 'doughboss' ); ?></option>
				<?php foreach ( $locations as $location ) : ?>
					<option value="<?php echo esc_attr( $location->id ); ?>" <?php selected( $current, (int) $location->id ); ?>><?php echo esc_html( $location->name ); ?></option>
				<?php endforeach; ?>
			</select><p class="description"><?php esc_html_e( 'Required for KDS-only accounts when more than one active shop exists. Managers keep an all-store view.', 'doughboss' ); ?></p></td>
		</tr></table>
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
	}
}
