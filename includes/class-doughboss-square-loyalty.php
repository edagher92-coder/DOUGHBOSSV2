<?php
/**
 * Square Loyalty accrual for paid web orders.
 *
 * After a Square-paid web order is created, a background job (never the
 * checkout request) looks up the customer's Square Loyalty account by phone
 * and accumulates points against the linked Square Order, so the points rules
 * configured in Square Dashboard (incl. promotions) apply exactly as in-store.
 *
 * Only customers who already have a Square Loyalty account (enrolled in-store
 * or via an explicit opt-in) earn points: this class never enrols anyone.
 * Off unless `square_loyalty_accrual` is on; a missing Square program is a
 * safe no-op. Idempotent: Square idempotency key + one-time option guard.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DoughBoss_Square_Loyalty {

	const CRON_HOOK  = 'doughboss_square_loyalty_accrue';
	const DONE_PREFIX = 'doughboss_sqloy_';
	const MAX_TRIES  = 4;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'doughboss_order_created', array( __CLASS__, 'on_order_created' ), 30, 2 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ), 10, 2 );
	}

	/** @return bool */
	public static function enabled() {
		return (bool) DoughBoss_Settings::get( 'square_loyalty_accrual', 1 );
	}

	/**
	 * Queue accrual for a Square-paid order.
	 *
	 * @param int   $order_id Order id.
	 * @param array $data     Order data.
	 * @return void
	 */
	public static function on_order_created( $order_id, $data = array() ) {
		if ( ! self::enabled() || ! is_array( $data ) ) {
			return;
		}
		if ( ! self::eligible_data( $data ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( (int) $order_id, 1 ) ) ) {
			wp_schedule_single_event( time() + 20, self::CRON_HOOK, array( (int) $order_id, 1 ) );
		}
	}

	/**
	 * Pure gate: paid, Square-referenced, with a usable phone.
	 *
	 * @param array $data Order data or row.
	 * @return bool
	 */
	public static function eligible_data( array $data ) {
		$paid  = isset( $data['payment_status'] ) && 'paid' === $data['payment_status'];
		$ref   = isset( $data['payment_intent_id'] ) ? (string) $data['payment_intent_id'] : '';
		$phone = isset( $data['customer_phone'] ) ? (string) $data['customer_phone'] : '';
		return $paid && '' !== $ref && 0 !== strpos( $ref, 'pi_' ) && '' !== self::e164( $phone );
	}

	/**
	 * Australian phone → E.164 (Square Loyalty is keyed by phone).
	 *
	 * @param string $phone Raw.
	 * @return string
	 */
	public static function e164( $phone ) {
		return class_exists( 'DoughBoss_Square_Invoices' ) ? DoughBoss_Square_Invoices::e164_au( $phone ) : '';
	}

	/**
	 * Deterministic accumulate idempotency key (≤45 chars).
	 *
	 * @param string $square_order_id Square order id.
	 * @return string
	 */
	public static function idempotency_key( $square_order_id ) {
		return 'dbloy-' . substr( hash( 'sha256', 'doughboss-loyalty|' . $square_order_id ), 0, 36 );
	}

	/**
	 * Background job.
	 *
	 * @param int $order_id Order id.
	 * @param int $try      Attempt number.
	 * @return string Outcome (for tests/logs): done|skipped|retry|gave_up.
	 */
	public static function run( $order_id, $try = 1 ) {
		$order_id = absint( $order_id );
		$try      = max( 1, (int) $try );
		if ( ! $order_id || ! self::enabled() ) {
			return 'skipped';
		}
		if ( get_option( self::DONE_PREFIX . $order_id ) ) {
			return 'skipped';
		}
		$outcome = self::accrue( $order_id );
		if ( 'retry' === $outcome ) {
			if ( $try >= self::MAX_TRIES ) {
				self::log( 'gave up on order #' . $order_id );
				return 'gave_up';
			}
			wp_schedule_single_event( time() + 300 * $try, self::CRON_HOOK, array( $order_id, $try + 1 ) );
			return 'retry';
		}
		add_option( self::DONE_PREFIX . $order_id, $outcome . '|' . gmdate( 'c' ), '', 'no' );
		return $outcome;
	}

	/**
	 * One accrual pass.
	 *
	 * @param int $order_id Order id.
	 * @return string done|skipped|retry
	 */
	private static function accrue( $order_id ) {
		$order = DoughBoss_Order::get( $order_id );
		if ( ! $order ) {
			return 'skipped';
		}
		$row = (array) $order;
		if ( ! self::eligible_data( $row ) ) {
			return 'skipped';
		}
		$attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( (string) $row['payment_intent_id'] );
		if ( ! is_array( $attempt ) || 'square' !== $attempt['provider'] || 'succeeded' !== $attempt['status']
			|| empty( $attempt['provider_order_reference'] ) ) {
			return 'skipped';
		}
		$location = (int) $attempt['location_id'];
		$binding  = DoughBoss_Square::commerce_binding( $location );
		if ( is_wp_error( $binding ) ) {
			return 'skipped';
		}

		$program = self::program_id( $location );
		if ( null === $program ) {
			return 'retry';
		}
		if ( '' === $program ) {
			return 'skipped';
		}

		$found = DoughBoss_Square::commerce_request(
			'POST',
			'/v2/loyalty/accounts/search',
			array( 'query' => array( 'mappings' => array( array( 'phone_number' => self::e164( $row['customer_phone'] ) ) ) ), 'limit' => 1 ),
			$location
		);
		if ( is_wp_error( $found ) ) {
			return 'retry';
		}
		$account = isset( $found['loyalty_accounts'][0]['id'] ) ? (string) $found['loyalty_accounts'][0]['id'] : '';
		if ( '' === $account || ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $account ) ) {
			return 'skipped';
		}

		$square_order = (string) $attempt['provider_order_reference'];
		$res          = DoughBoss_Square::commerce_request(
			'POST',
			'/v2/loyalty/accounts/' . $account . '/accumulate',
			array(
				'accumulate_points' => array( 'order_id' => $square_order ),
				'idempotency_key'   => self::idempotency_key( $square_order ),
				'location_id'       => $binding['square_location_id'],
			),
			$location
		);
		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();
			$http = is_array( $data ) && isset( $data['square_http'] ) ? (int) $data['square_http'] : 0;
			// 4xx = Square says no (already accrued, order not eligible): stop.
			return ( $http >= 400 && $http < 500 && 429 !== $http ) ? 'skipped' : 'retry';
		}
		self::log( 'points accrued for order #' . $order_id );
		return 'done';
	}

	/**
	 * Main loyalty program id, cached 12h. '' = no program; null = unknown.
	 *
	 * @param int $location Native location id.
	 * @return string|null
	 */
	private static function program_id( $location ) {
		$key    = 'doughboss_sqloy_program_' . DoughBoss_Square::mode() . '_' . (int) $location;
		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return 'none' === $cached ? '' : $cached;
		}
		$res = DoughBoss_Square::commerce_request( 'GET', '/v2/loyalty/programs/main', array(), $location );
		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();
			if ( is_array( $data ) && isset( $data['square_http'] ) && 404 === (int) $data['square_http'] ) {
				set_transient( $key, 'none', HOUR_IN_SECONDS );
				return '';
			}
			return null;
		}
		$id = isset( $res['program']['id'], $res['program']['status'] ) && 'ACTIVE' === $res['program']['status'] ? (string) $res['program']['id'] : '';
		set_transient( $key, '' === $id ? 'none' : $id, 12 * HOUR_IN_SECONDS );
		return $id;
	}

	/**
	 * @param string $message Message.
	 * @return void
	 */
	private static function log( $message ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[DoughBoss Square Loyalty] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
