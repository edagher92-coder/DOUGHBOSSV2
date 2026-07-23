<?php
/**
 * Payment gateway dispatcher.
 *
 * A thin facade over whichever gateway is active (DoughBoss_Stripe or
 * DoughBoss_Tyro), selected by the `payment_gateway` setting. Every method
 * mirrors DoughBoss_Stripe's original public surface exactly — checkout call
 * sites written against DoughBoss_Payment::method() work unchanged regardless
 * of which gateway is behind it.
 *
 * This class exists BECAUSE there are now two real gateway implementations to
 * route between — introducing it earlier (with only Stripe ever implemented)
 * would have been premature abstraction with nothing to prove it against.
 *
 * Default is 'stripe', so a site that never touches the new `payment_gateway`
 * setting behaves exactly as before this class was introduced.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes payment calls to the active gateway.
 */
class DoughBoss_Payment {

	/**
	 * Which gateway class backs the currently-selected payment_gateway setting.
	 *
	 * @return string Fully-qualified class name.
	 */
	public static function active_class() {
		return 'tyro' === DoughBoss_Settings::payment_gateway() ? 'DoughBoss_Tyro' : 'DoughBoss_Stripe';
	}

	/**
	 * Human-readable label for the active gateway (admin UI copy).
	 *
	 * @return string
	 */
	public static function gateway_label() {
		return 'tyro' === DoughBoss_Settings::payment_gateway() ? 'Tyro' : 'Stripe';
	}

	/**
	 * Whether the active gateway is switched on AND fully configured.
	 *
	 * @return bool
	 */
	public static function ready() {
		return (bool) call_user_func( array( self::active_class(), 'ready' ) );
	}

	/**
	 * Convert a major-unit amount to the active gateway's minor unit.
	 *
	 * @param float $amount Major-unit amount.
	 * @return int
	 */
	public static function to_minor_units( $amount ) {
		return (int) call_user_func( array( self::active_class(), 'to_minor_units' ), $amount );
	}

	/**
	 * Browser bootstrap identifier (Stripe publishable key / Tyro Connect marker).
	 * Tyro Connect returns the per-payment paySecret only after server creation.
	 *
	 * @return string
	 */
	public static function publishable_key() {
		return (string) call_user_func( array( self::active_class(), 'publishable_key' ) );
	}

	/**
	 * Start a payment for the given amount.
	 *
	 * @param int    $amount_minor Amount in the active gateway's minor unit.
	 * @param string $currency     ISO currency code.
	 * @param array  $metadata     Optional key/value metadata.
	 * @return array|WP_Error
	 */
	public static function create_payment_intent( $amount_minor, $currency, array $metadata = array() ) {
		return call_user_func( array( self::active_class(), 'create_payment_intent' ), $amount_minor, $currency, $metadata );
	}

	/**
	 * Verify (and, for gateways that require it, finalise) a payment before an
	 * order is trusted as paid.
	 *
	 * @param string $id Gateway-specific payment reference.
	 * @return array|WP_Error
	 */
	public static function retrieve_payment_intent( $id ) {
		return call_user_func( array( self::active_class(), 'retrieve_payment_intent' ), $id );
	}

	/**
	 * The id that should be persisted (order row / dedup lookups / webhook
	 * reconciliation) for whichever gateway is active. See
	 * DoughBoss_Tyro::canonical_id() for why this can differ from the raw id a
	 * gateway's create_payment_intent()/client round-trip uses.
	 *
	 * @param string $id Gateway-specific payment reference.
	 * @return string
	 */
	public static function canonical_id( $id ) {
		return (string) call_user_func( array( self::active_class(), 'canonical_id' ), $id );
	}

	/**
	 * Refund a payment, in full or in part.
	 *
	 * @param string   $id           Gateway-specific payment reference.
	 * @param int|null $amount_minor Amount in the active gateway's minor unit,
	 *                               or null for a full refund.
	 * @return array|WP_Error
	 */
	public static function create_refund( $id, $amount_minor = null ) {
		return call_user_func( array( self::active_class(), 'create_refund' ), $id, $amount_minor );
	}

	/**
	 * Refund a payment through the SPECIFIC gateway that processed it — used
	 * by the admin refund action, where an already-paid order's stored
	 * `payment_method` ('stripe' or 'tyro') may differ from whichever gateway
	 * is active in Settings today (e.g. the owner switched gateways after the
	 * order was placed). Refunding must always target the gateway that
	 * actually holds the money, never the currently-active one.
	 *
	 * @param string   $gateway      'stripe' or 'tyro' — from the order's
	 *                               stored payment_method column.
	 * @param string   $id           Gateway-specific payment reference.
	 * @param int|null $amount_minor Amount in minor units, or null for a full
	 *                               refund.
	 * @return array|WP_Error
	 */
	public static function refund_via( $gateway, $id, $amount_minor = null ) {
		$class = 'tyro' === $gateway ? 'DoughBoss_Tyro' : 'DoughBoss_Stripe';
		if ( ! class_exists( $class ) ) {
			return new WP_Error( 'doughboss_pay_gateway', __( 'Unknown payment gateway.', 'doughboss' ), array( 'status' => 500 ) );
		}
		return call_user_func( array( $class, 'create_refund' ), $id, $amount_minor );
	}
}
