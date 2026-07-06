<?php
/**
 * Shared REST permission callbacks for DoughBoss controllers.
 *
 * Extracted verbatim from DoughBoss_REST_Controller so per-domain sub-controllers
 * (POSPal, etc.) share one audited copy of the auth checks. Behaviour unchanged.
 *
 * @package DoughBoss
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Permission-callback kit used by the main controller and every sub-controller.
 */
trait DoughBoss_REST_Auth {
	/**
	 * Permission check: valid REST nonce required for state-changing calls.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function verify_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}
		return new WP_Error( 'doughboss_bad_nonce', __( 'Session expired. Please refresh the page.', 'doughboss' ), array( 'status' => 403 ) );
	}

	/**
	 * Permission check: require the management capability.
	 *
	 * @return bool|WP_Error
	 */
	public function verify_admin() {
		if ( current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_doughboss_kds' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'doughboss_forbidden', __( 'You are not allowed to do that.', 'doughboss' ), array( 'status' => 403 ) );
	}

	/**
	 * Permission check: require the owner management capability only (not the
	 * lower kitchen/KDS cap). Used for issuing/managing vouchers so a till
	 * device can never create value.
	 *
	 * @return bool|WP_Error
	 */
	public function verify_manage() {
		if ( current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'doughboss_forbidden', __( 'You are not allowed to do that.', 'doughboss' ), array( 'status' => 403 ) );
	}

	/**
	 * Permission check for the in-store voucher scan dashboard: the dedicated
	 * redeem capability (granted to the owner and the kitchen role on shop
	 * tablets) or full management. A till device can redeem but never issue
	 * value — issuing stays behind verify_manage().
	 *
	 * @return bool|WP_Error
	 */
	public function verify_redeem() {
		if ( current_user_can( 'redeem_doughboss_vouchers' ) || current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'doughboss_forbidden', __( 'You are not allowed to do that.', 'doughboss' ), array( 'status' => 403 ) );
	}

	/**
	 * Permission check for the staff console's login probe: any DoughBoss staff
	 * role (redeem, board, or management). Returns 401 so the console can prompt
	 * for valid credentials.
	 *
	 * @return bool|WP_Error
	 */
	public function verify_staff() {
		if ( current_user_can( 'redeem_doughboss_vouchers' ) || current_user_can( 'manage_doughboss_kds' ) || current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'doughboss_unauthorized', __( 'Sign in with a DoughBoss staff account.', 'doughboss' ), array( 'status' => 401 ) );
	}
}
