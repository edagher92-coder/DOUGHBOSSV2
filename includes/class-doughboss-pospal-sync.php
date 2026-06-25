<?php
/**
 * POSPal voucher sync — grant on claim, revoke on redeem (off by default).
 *
 * Bridges the plugin's voucher lifecycle to the POSPal member-coupon system:
 *
 *  - On `doughboss_voucher_claimed`: ensure the customer exists as a POSPal
 *    member (by phone), grant the matching coupon rule to them, and stamp the
 *    voucher row with the POSPal member uid + granted-coupon reference so the
 *    redeem leg can later revoke it.
 *  - On `doughboss_voucher_redeemed`: best-effort revoke/use the mirrored POSPal
 *    coupon so a voucher spent online can't also be used in-store.
 *
 * FULLY DORMANT unless `DoughBoss_Settings::pospal_grant_enabled()` is true
 * (POSPal configured AND at least one coupon-rule UID mapped). When dormant,
 * both handlers return immediately and voucher claims/redemptions behave exactly
 * as they do today. Every POSPal call is checked for WP_Error — the sync never
 * fatals and never blocks the voucher flow; it logs status only (no PII/secrets).
 *
 * Method names on the POSPal side (grant/revoke) are still being confirmed
 * against the live 优惠券 docs — they are isolated in DoughBoss_POSPal, so this
 * orchestration is stable regardless of the final endpoint names.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires voucher events to the POSPal connector. Static; register via init().
 */
class DoughBoss_POSPal_Sync {

	/**
	 * Register the voucher-lifecycle hooks. Safe to call always — the handlers
	 * self-gate on pospal_grant_enabled() and do nothing when POSPal/grant is off.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'doughboss_voucher_claimed', array( __CLASS__, 'on_voucher_claimed' ), 10, 4 );
		add_action( 'doughboss_voucher_redeemed', array( __CLASS__, 'on_voucher_redeemed' ), 10, 3 );
	}

	/**
	 * On a successful voucher claim, mirror it into POSPal as a member coupon.
	 *
	 * Looks up the voucher row (the claim hook passes only the id), resolves the
	 * coupon-rule UID from the voucher's dollar value, ensures the member exists by
	 * phone, grants the coupon, and stores `pospal_customer_uid` +
	 * `pospal_coupon_ref` on the row. Any failure is logged (status only) and
	 * swallowed — a POSPal hiccup must never undo the claim the customer just made.
	 *
	 * @param int    $voucher_id New voucher id.
	 * @param string $code       New voucher code (unused; kept for the hook contract).
	 * @param string $slug       Campaign slug (unused).
	 * @param array  $args       Extra issue args passed to claim() (unused).
	 * @return void
	 */
	public static function on_voucher_claimed( $voucher_id, $code = '', $slug = '', $args = array() ) {
		unset( $code, $slug, $args );

		// Guard: fully dormant unless POSPal grant is configured + enabled.
		if ( ! DoughBoss_Settings::pospal_grant_enabled() ) {
			return;
		}

		$voucher_id = absint( $voucher_id );
		if ( ! $voucher_id ) {
			return;
		}

		$row = self::get_voucher( $voucher_id );
		if ( ! $row ) {
			return;
		}

		$phone = isset( $row->customer_phone ) ? trim( (string) $row->customer_phone ) : '';
		if ( '' === $phone ) {
			// No member key — nothing to grant in POSPal; the online voucher still works.
			return;
		}

		// Only grant once: if a coupon ref is already stored, this is a re-entry.
		if ( isset( $row->pospal_coupon_ref ) && '' !== (string) $row->pospal_coupon_ref ) {
			return;
		}

		$rule_uid = DoughBoss_Settings::pospal_coupon_uid_for( isset( $row->value ) ? $row->value : 0 );
		if ( '' === $rule_uid ) {
			// This voucher value isn't mapped to a POSPal coupon rule — skip quietly.
			return;
		}

		$customer_uid = DoughBoss_POSPal::ensure_member( $phone );
		if ( is_wp_error( $customer_uid ) ) {
			self::log( 'grant: ensure_member failed (' . $customer_uid->get_error_code() . ') for voucher #' . $voucher_id );
			return;
		}

		$granted = DoughBoss_POSPal::grant_coupon( $customer_uid, $rule_uid );
		if ( is_wp_error( $granted ) ) {
			self::log( 'grant: grant_coupon failed (' . $granted->get_error_code() . ') for voucher #' . $voucher_id );
			// Still record the member uid we resolved, so a later retry can reuse it.
			self::update_voucher( $voucher_id, array( 'pospal_customer_uid' => $customer_uid ) );
			return;
		}

		$coupon_ref = self::coupon_ref_from( $granted, $rule_uid );

		self::update_voucher(
			$voucher_id,
			array(
				'pospal_customer_uid' => $customer_uid,
				'pospal_coupon_ref'   => $coupon_ref,
			)
		);
		self::log( 'grant: ok for voucher #' . $voucher_id );
	}

	/**
	 * On redemption, best-effort revoke the mirrored POSPal coupon so it can't be
	 * reused in-store. No-op when nothing was granted (no stored coupon ref).
	 *
	 * @param object $row     Voucher row (passed by the redeem hook).
	 * @param float  $amount  Amount applied (unused).
	 * @param string $channel Redemption channel (unused).
	 * @return void
	 */
	public static function on_voucher_redeemed( $row, $amount = 0, $channel = '' ) {
		unset( $amount, $channel );

		// Guard: fully dormant unless POSPal grant is configured + enabled.
		if ( ! DoughBoss_Settings::pospal_grant_enabled() ) {
			return;
		}

		if ( ! is_object( $row ) ) {
			return;
		}

		$customer_uid = isset( $row->pospal_customer_uid ) ? (string) $row->pospal_customer_uid : '';
		$coupon_ref   = isset( $row->pospal_coupon_ref ) ? (string) $row->pospal_coupon_ref : '';
		if ( '' === $customer_uid || '' === $coupon_ref ) {
			// Nothing was mirrored into POSPal for this voucher — nothing to revoke.
			return;
		}

		$result = DoughBoss_POSPal::revoke_coupon( $customer_uid, $coupon_ref );
		if ( is_wp_error( $result ) ) {
			$vid = isset( $row->id ) ? absint( $row->id ) : 0;
			self::log( 'revoke: revoke_coupon failed (' . $result->get_error_code() . ') for voucher #' . $vid );
			return;
		}
		$vid = isset( $row->id ) ? absint( $row->id ) : 0;
		self::log( 'revoke: ok for voucher #' . $vid );
	}

	/**
	 * Derive a stable coupon reference from POSPal's grant response, falling back
	 * to the rule UID when the response shape doesn't carry an instance id. The
	 * stored ref is what the revoke leg passes back to POSPal.
	 *
	 * @param mixed  $granted  Decoded POSPal grant response.
	 * @param string $rule_uid The coupon-rule UID that was granted.
	 * @return string
	 */
	private static function coupon_ref_from( $granted, $rule_uid ) {
		if ( is_array( $granted ) ) {
			foreach ( array( 'customerPassProductUid', 'passProductUid', 'uid', 'id' ) as $key ) {
				if ( isset( $granted[ $key ] ) && '' !== (string) $granted[ $key ] ) {
					return substr( (string) $granted[ $key ], 0, 64 );
				}
			}
		}
		// Fall back to the rule UID so the row still records what was granted.
		return substr( (string) $rule_uid, 0, 64 );
	}

	/**
	 * Fetch a voucher row by id.
	 *
	 * @param int $voucher_id Voucher id.
	 * @return object|null
	 */
	private static function get_voucher( $voucher_id ) {
		global $wpdb;
		$table = DoughBoss_Voucher::table();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $voucher_id ) )
		);
	}

	/**
	 * Stamp POSPal reference fields onto a voucher row. Only the two POSPal columns
	 * are ever written here, both as strings.
	 *
	 * @param int   $voucher_id Voucher id.
	 * @param array $fields     Subset of { pospal_customer_uid, pospal_coupon_ref }.
	 * @return void
	 */
	private static function update_voucher( $voucher_id, array $fields ) {
		global $wpdb;

		$data    = array();
		$formats = array();
		if ( isset( $fields['pospal_customer_uid'] ) ) {
			$data['pospal_customer_uid'] = substr( sanitize_text_field( (string) $fields['pospal_customer_uid'] ), 0, 64 );
			$formats[]                   = '%s';
		}
		if ( isset( $fields['pospal_coupon_ref'] ) ) {
			$data['pospal_coupon_ref'] = substr( sanitize_text_field( (string) $fields['pospal_coupon_ref'] ), 0, 64 );
			$formats[]                 = '%s';
		}
		if ( empty( $data ) ) {
			return;
		}
		$data['updated_at'] = current_time( 'mysql' );
		$formats[]          = '%s';

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			DoughBoss_Voucher::table(),
			$data,
			array( 'id' => absint( $voucher_id ) ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Log a sync status line for the operator. Status + voucher id only — never the
	 * phone, member uid, coupon details, appKey or any response body.
	 *
	 * @param string $message Short status string.
	 * @return void
	 */
	private static function log( $message ) {
		if ( function_exists( 'error_log' ) ) {
			error_log( 'DoughBoss POSPal sync: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
