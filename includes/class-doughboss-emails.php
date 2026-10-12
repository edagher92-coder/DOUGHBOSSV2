<?php
/**
 * Customer emails (built-in, via wp_mail).
 *
 * Emails the customer at two order milestones: an "we're on it" note when the
 * kitchen accepts the order (with the ETA when one was given) and a "ready for
 * pickup" note when the order moves to the `ready` status. Modeled on
 * DoughBoss_SMS: a static class registered via init(), where every handler
 * self-gates on its per-stage toggle and returns immediately when that stage
 * is switched off — no external service is involved, so plain wp_mail() with
 * no extra configuration is enough. Successfully claimed vouchers are also
 * sent to the validated claim email, with the on-screen code retained as the
 * fallback.
 *
 * Idempotency: kitchen board undo/redo can re-fire the accept/status hooks for
 * the same order, so a small stage log (option `doughboss_email_stage_log`,
 * autoload off) records which stages have already been emailed per order and
 * is pruned to the most recent orders. A short atomic dispatch lock also
 * suppresses two concurrent PHP workers for the same order and stage. Voucher
 * claims use one permanent autoload-off, voucher-id-only option as an atomic
 * exactly-once attempt marker.
 *
 * Privacy: log lines carry only an order/voucher id and stage — never the
 * customer email address, name, voucher code or message body.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Native customer email dispatcher. Static; register via init().
 */
class DoughBoss_Emails {

	/**
	 * Option holding the per-order stage log (order_id => array of stage keys
	 * already emailed). Stored with autoload off.
	 */
	const STAGE_LOG_OPTION = 'doughboss_email_stage_log';

	/**
	 * Maximum number of orders kept in the stage log before the oldest entries
	 * are pruned.
	 */
	const STAGE_LOG_MAX = 300;

	/**
	 * Short atomic option lock used to stop two PHP workers from dispatching
	 * the same order/stage email at the same time.
	 */
	const DELIVERY_LOCK_PREFIX = 'doughboss_email_delivery_lock_';

	/**
	 * A crashed worker's lock can be reclaimed after five minutes.
	 */
	const DELIVERY_LOCK_TTL = 300;

	/**
	 * One permanent, autoload-off dispatch marker per claimed voucher. The
	 * voucher id is the only identifier in the option name/value; no customer
	 * data or voucher code is stored here.
	 */
	const VOUCHER_DELIVERY_OPTION_PREFIX = 'doughboss_voucher_email_attempted_';

	/**
	 * Register the email hooks. Always safe to call: order-stage handlers
	 * self-gate on their toggles, while a successful voucher claim uses native
	 * wp_mail() with no external-service setting.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'doughboss_order_accepted', array( __CLASS__, 'on_order_accepted' ), 10, 2 );
		add_action( 'doughboss_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 2 );
		add_action( 'doughboss_voucher_claimed', array( __CLASS__, 'on_voucher_claimed' ), 10, 4 );
	}

	/**
	 * Whether at least one stage email is switched on. Native wp_mail() needs
	 * no external configuration, so the toggles are the whole gate.
	 *
	 * @return bool
	 */
	public static function emails_ready() {
		return DoughBoss_Settings::email_on_accepted() || DoughBoss_Settings::email_on_ready();
	}

	/**
	 * When the kitchen accepts an order, email the customer that it's being
	 * prepared (including the ETA when one was given). Dormant unless the
	 * "on accepted" toggle is on.
	 *
	 * The accept hook passes only the order id + the ETA, so the row (and the
	 * customer email) is loaded fresh from the orders table.
	 *
	 * @param int $order_id    Accepted order id.
	 * @param int $eta_minutes Estimated minutes until ready (0 = none given).
	 * @return void
	 */
	public static function on_order_accepted( $order_id, $eta_minutes = 0 ) {
		if ( ! DoughBoss_Settings::email_on_accepted() ) {
			return;
		}

		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return;
		}
		if ( self::already_sent( $order_id, 'accepted' ) ) {
			return;
		}

		$order = DoughBoss_Order::get( $order_id );
		if ( ! is_object( $order ) ) {
			return;
		}

		$eta  = max( 0, (int) $eta_minutes );
		$vars = self::template_vars( $order, $eta, __( 'Confirmed', 'doughboss' ) );

		$subject = DoughBoss_Settings::render_template( DoughBoss_Settings::tpl_accepted_email_subject(), $vars );
		$body    = DoughBoss_Settings::render_template( DoughBoss_Settings::tpl_accepted_email_body( $eta > 0 ), $vars );

		self::deliver( $order, $order_id, 'accepted', $subject, $body );
	}

	/**
	 * On an order status change, email the customer when the order is ready
	 * for pickup. Dormant unless the "on ready" toggle is on.
	 *
	 * @param int    $order_id Changed order id.
	 * @param string $status   The order's new status.
	 * @return void
	 */
	public static function on_status_changed( $order_id, $status ) {
		if ( ! DoughBoss_Settings::email_on_ready() ) {
			return;
		}
		if ( 'ready' !== (string) $status ) {
			return;
		}

		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return;
		}
		if ( self::already_sent( $order_id, 'ready' ) ) {
			return;
		}

		$order = DoughBoss_Order::get( $order_id );
		if ( ! is_object( $order ) ) {
			return;
		}

		$eta        = isset( $order->eta_minutes ) ? max( 0, (int) $order->eta_minutes ) : 0;
		$projection = DoughBoss_Order::customer_projection( $order );
		$vars       = self::template_vars( $order, $eta, $projection['label'] );

		$subject = DoughBoss_Settings::render_template( DoughBoss_Settings::tpl_ready_email_subject(), $vars );
		$body    = DoughBoss_Settings::render_template( DoughBoss_Settings::tpl_ready_email_body(), $vars );

		self::deliver( $order, $order_id, 'ready', $subject, $body );
	}

	/**
	 * Email a successfully claimed voucher to the validated claim address.
	 *
	 * The recipient comes only from the claim hook arguments and must also match
	 * the address persisted on the newly issued voucher. A permanent atomic
	 * option marker is claimed before wp_mail() so concurrent/replayed hooks can
	 * never dispatch the same voucher twice. Mail delivery is deliberately
	 * fire-and-forget: the claim and its on-screen code remain successful even
	 * when the local mailer returns false or throws.
	 *
	 * @param int    $voucher_id New voucher id.
	 * @param string $code       New voucher code.
	 * @param string $slug       Campaign slug.
	 * @param array  $args       Extra claim arguments, including customer_email.
	 * @return void
	 */
	public static function on_voucher_claimed( $voucher_id, $code, $slug, $args = array() ) {
		$voucher_id = absint( $voucher_id );
		$code       = trim( (string) $code );
		if ( ! $voucher_id || '' === $code || ! is_array( $args ) || empty( $args['customer_email'] ) ) {
			return;
		}

		$email = sanitize_email( wp_unslash( (string) $args['customer_email'] ) );
		if ( ! is_email( $email ) ) {
			return;
		}

		$voucher = DoughBoss_Voucher::find_by_code( $code );
		if ( ! is_object( $voucher ) || ! isset( $voucher->id ) || $voucher_id !== absint( $voucher->id ) ) {
			return;
		}

		$stored_email = isset( $voucher->customer_email )
			? sanitize_email( (string) $voucher->customer_email )
			: '';
		if ( ! is_email( $stored_email ) || ! hash_equals( strtolower( $stored_email ), strtolower( $email ) ) ) {
			return;
		}

		$campaign       = self::voucher_campaign( $slug );
		$meta           = ! empty( $voucher->meta ) ? json_decode( (string) $voucher->meta, true ) : array();
		$campaign_label = is_array( $meta ) && ! empty( $meta['label'] )
			? sanitize_text_field( (string) $meta['label'] )
			: ( isset( $campaign['label'] ) ? sanitize_text_field( (string) $campaign['label'] ) : '' );
		if ( '' === $campaign_label ) {
			$campaign_label = __( 'Voucher offer', 'doughboss' );
		}

		$type        = isset( $voucher->type )
			? sanitize_key( $voucher->type )
			: ( isset( $campaign['type'] ) ? sanitize_key( $campaign['type'] ) : 'amount' );
		$value       = isset( $voucher->value )
			? (float) $voucher->value
			: ( isset( $campaign['value'] ) ? (float) $campaign['value'] : 0 );
		$value_label = self::voucher_value_label( $type, $value );
		$site_name   = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		if ( '' === $site_name ) {
			$site_name = __( 'Dough Boss', 'doughboss' );
		}

		$valid_to = isset( $voucher->valid_to ) ? (string) $voucher->valid_to : '';
		$scope    = isset( $voucher->scope ) ? sanitize_key( $voucher->scope ) : 'both';
		$online   = 'instore' === $scope
			? __( 'Online redemption: This voucher is for in-store use only and cannot be applied online.', 'doughboss' )
			: __( 'Online redemption: Enter the code in the Voucher code field at checkout and choose Apply before paying.', 'doughboss' );

		$subject = sprintf(
			/* translators: %s: site/store name. */
			__( '[%s] Your voucher code', 'doughboss' ),
			$site_name
		);
		$body    = implode(
			"\n",
			array(
				sprintf(
					/* translators: %s: site/store name. */
					__( 'Your voucher from %s is ready.', 'doughboss' ),
					$site_name
				),
				sprintf(
					/* translators: %s: site/store name. */
					__( 'Store: %s', 'doughboss' ),
					$site_name
				),
				sprintf(
					/* translators: %s: campaign label. */
					__( 'Offer: %s', 'doughboss' ),
					$campaign_label
				),
				sprintf(
					/* translators: %s: formatted discount value. */
					__( 'Value: %s', 'doughboss' ),
					$value_label
				),
				sprintf(
					/* translators: %s: voucher code. */
					__( 'Voucher code: %s', 'doughboss' ),
					$code
				),
				'',
				__( 'Single-use: This code can be redeemed once only. Do not share it.', 'doughboss' ),
				self::voucher_expiry_guidance( $valid_to ),
				$online,
				__( 'In store: Show this code or the on-screen QR code at the till.', 'doughboss' ),
				__( 'Keep the on-screen code as a backup in case this email is delayed.', 'doughboss' ),
			)
		);

		// add_option() is an atomic insert and the marker remains after either a
		// success or failure, making this an exactly-once delivery attempt.
		if ( ! add_option( self::VOUCHER_DELIVERY_OPTION_PREFIX . $voucher_id, time(), '', 'no' ) ) {
			return;
		}

		try {
			$sent = wp_mail( $email, $subject, $body );
		} catch ( Throwable $exception ) {
			// Never include the exception: mailer errors can contain recipient/body data.
			$sent = false;
		}

		if ( false === $sent ) {
			self::log( 'voucher: customer email failed for voucher #' . $voucher_id );
			return;
		}

		self::log( 'voucher: customer email dispatched for voucher #' . $voucher_id );
	}

	/**
	 * Find the campaign definition for a claim slug.
	 *
	 * @param string $slug Campaign slug.
	 * @return array
	 */
	private static function voucher_campaign( $slug ) {
		$slug      = sanitize_key( $slug );
		$campaigns = DoughBoss_Voucher::campaigns();
		if ( isset( $campaigns[ $slug ] ) && is_array( $campaigns[ $slug ] ) ) {
			return $campaigns[ $slug ];
		}
		foreach ( (array) $campaigns as $campaign ) {
			if ( is_array( $campaign ) && isset( $campaign['slug'] ) && $slug === sanitize_key( $campaign['slug'] ) ) {
				return $campaign;
			}
		}
		return array();
	}

	/**
	 * Format an amount or percentage campaign value for plain-text email.
	 *
	 * @param string $type  amount|percent.
	 * @param float  $value Discount value.
	 * @return string
	 */
	private static function voucher_value_label( $type, $value ) {
		if ( 'percent' === $type ) {
			$percent = rtrim( rtrim( number_format( max( 0, (float) $value ), 2, '.', '' ), '0' ), '.' );
			return sprintf(
				/* translators: %s: percentage number. */
				__( '%s%% off', 'doughboss' ),
				$percent
			);
		}
		return sprintf(
			/* translators: %s: formatted currency amount. */
			__( '%s off', 'doughboss' ),
			DoughBoss_Settings::format_price( max( 0, (float) $value ) )
		);
	}

	/**
	 * Build explicit expiry guidance without inventing an expiry for open-ended
	 * campaign vouchers.
	 *
	 * @param string $valid_to Stored voucher expiry, when set.
	 * @return string
	 */
	private static function voucher_expiry_guidance( $valid_to ) {
		$valid_to = sanitize_text_field( (string) $valid_to );
		if ( '' === $valid_to ) {
			return __( 'Expiry: No fixed expiry date is listed. Use it promptly and while the campaign terms remain valid.', 'doughboss' );
		}

		$display   = $valid_to;
		$timestamp = strtotime( $valid_to );
		if ( false !== $timestamp ) {
			$date_format = (string) get_option( 'date_format', 'F j, Y' );
			$date_format = '' !== $date_format ? $date_format : 'F j, Y';
			$display     = function_exists( 'date_i18n' )
				? date_i18n( $date_format, $timestamp )
				: gmdate( $date_format, $timestamp );
		}

		return sprintf(
			/* translators: %s: voucher expiry date. */
			__( 'Expiry: Use this voucher by %s.', 'doughboss' ),
			$display
		);
	}

	/**
	 * Build the placeholder map shared by both stage templates.
	 *
	 * @param object $order        Order row.
	 * @param int    $eta_minutes  ETA in minutes (0 = none).
	 * @param string $status_label Human status label for {status_label}.
	 * @return array
	 */
	private static function template_vars( $order, $eta_minutes, $status_label ) {
		return array(
			'customer_name' => isset( $order->customer_name ) ? (string) $order->customer_name : '',
			'order_number'  => isset( $order->order_number ) ? (string) $order->order_number : (string) $order->id,
			'eta_minutes'   => (string) max( 0, (int) $eta_minutes ),
			'total'         => DoughBoss_Settings::format_price( isset( $order->total ) ? $order->total : 0 ),
			'status_label'  => (string) $status_label,
			'table_label'   => isset( $order->table_label ) ? (string) $order->table_label : '',
			'tracking_url'  => DoughBoss_Settings::tracking_page_url( isset( $order->order_number ) ? $order->order_number : $order->id ),
			'tracking_instructions' => DoughBoss_Settings::tracking_instructions( isset( $order->order_number ) ? $order->order_number : $order->id ),
			'handoff_message' => isset( $order->order_type ) && 'dine_in' === $order->order_type
				? __( 'We will bring it to your table.', 'doughboss' )
				: ( isset( $order->order_type ) && 'delivery' === $order->order_type ? __( 'It is ready for delivery.', 'doughboss' ) : __( 'Please collect it from the shop.', 'doughboss' ) ),
		);
	}

	/**
	 * Send a stage email to the order's customer (and optionally a copy to the
	 * shop inbox), recording the stage on success so board undo/redo can never
	 * email the same stage twice.
	 *
	 * Skips silently when the order carries no usable customer email — the
	 * order itself is unaffected. Fire-and-forget: wp_mail hands the message
	 * to the local mailer; failures are logged (order id + stage only, no
	 * PII) and never bubble into the kitchen's request.
	 *
	 * @param object $order    Order row.
	 * @param int    $order_id Order id.
	 * @param string $stage    Stage key ('accepted' or 'ready').
	 * @param string $subject  Rendered subject.
	 * @param string $body     Rendered body.
	 * @return void
	 */
	private static function deliver( $order, $order_id, $stage, $subject, $body ) {
		$email = isset( $order->customer_email ) ? (string) $order->customer_email : '';
		if ( ! is_email( $email ) ) {
			// No usable address — nothing to email; the order is unaffected.
			return;
		}

		if ( ! self::claim_delivery( $order_id, $stage ) ) {
			return;
		}

		try {
			// A concurrent handler may have passed its earlier already_sent()
			// check before this worker won the lock. Re-check inside the lock.
			if ( self::already_sent( $order_id, $stage ) ) {
				return;
			}

			if ( false === wp_mail( $email, $subject, $body ) ) {
				self::log( $stage . ': customer email failed for order #' . $order_id );
				return;
			}

			self::mark_sent( $order_id, $stage );
			self::log( $stage . ': customer email dispatched for order #' . $order_id );

			if ( DoughBoss_Settings::email_staff_copy() ) {
				$staff = DoughBoss_Settings::orders_email();
				if ( is_email( $staff ) && false === wp_mail( $staff, $subject, $body ) ) {
					self::log( $stage . ': staff copy failed for order #' . $order_id );
				}
			}
		} finally {
			self::release_delivery( $order_id, $stage );
		}
	}

	/**
	 * Atomically reserve one order/stage email dispatch.
	 *
	 * WordPress's add_option() uses a unique option-name insert, so only one
	 * concurrent PHP worker can win. A stale lock is reclaimed after
	 * DELIVERY_LOCK_TTL to recover from a crashed request.
	 *
	 * @param int    $order_id Order id.
	 * @param string $stage    Stage key.
	 * @return bool
	 */
	private static function claim_delivery( $order_id, $stage ) {
		$key = self::delivery_lock_key( $order_id, $stage );
		if ( add_option( $key, time(), '', 'no' ) ) {
			return true;
		}

		$started = (int) get_option( $key, 0 );
		if ( $started > 0 && ( time() - $started ) > self::DELIVERY_LOCK_TTL ) {
			delete_option( $key );
			return add_option( $key, time(), '', 'no' );
		}
		return false;
	}

	/**
	 * Release a completed or failed dispatch reservation.
	 *
	 * @param int    $order_id Order id.
	 * @param string $stage    Stage key.
	 * @return void
	 */
	private static function release_delivery( $order_id, $stage ) {
		delete_option( self::delivery_lock_key( $order_id, $stage ) );
	}

	/**
	 * Build a bounded, non-PII lock option name.
	 *
	 * @param int    $order_id Order id.
	 * @param string $stage    Stage key.
	 * @return string
	 */
	private static function delivery_lock_key( $order_id, $stage ) {
		return self::DELIVERY_LOCK_PREFIX . absint( $order_id ) . '_' . sanitize_key( $stage );
	}

	/**
	 * Whether a stage has already been emailed for an order.
	 *
	 * @param int    $order_id Order id.
	 * @param string $stage    Stage key.
	 * @return bool
	 */
	private static function already_sent( $order_id, $stage ) {
		$log = get_option( self::STAGE_LOG_OPTION, array() );
		if ( ! is_array( $log ) || ! isset( $log[ $order_id ] ) ) {
			return false;
		}
		return in_array( $stage, (array) $log[ $order_id ], true );
	}

	/**
	 * Record a stage as emailed for an order, pruning the log to the most
	 * recent STAGE_LOG_MAX orders. The option is created with autoload off so
	 * the log never rides along on every page load.
	 *
	 * @param int    $order_id Order id.
	 * @param string $stage    Stage key.
	 * @return void
	 */
	private static function mark_sent( $order_id, $stage ) {
		$log = get_option( self::STAGE_LOG_OPTION, false );
		if ( false === $log ) {
			// First write: create the option explicitly with autoload off.
			add_option( self::STAGE_LOG_OPTION, array(), '', 'no' );
			$log = array();
		}
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$order_id = (int) $order_id;
		$stages   = isset( $log[ $order_id ] ) ? (array) $log[ $order_id ] : array();
		if ( ! in_array( $stage, $stages, true ) ) {
			$stages[] = $stage;
		}

		// Re-append so the most recently emailed orders sit at the tail, then
		// prune the oldest entries (head) beyond the cap, preserving keys.
		unset( $log[ $order_id ] );
		$log[ $order_id ] = $stages;
		if ( count( $log ) > self::STAGE_LOG_MAX ) {
			$log = array_slice( $log, -self::STAGE_LOG_MAX, null, true );
		}

		update_option( self::STAGE_LOG_OPTION, $log, 'no' );
	}

	/**
	 * Log an email status line for the operator. Status + order id + stage
	 * only — never the customer address, name or message body.
	 *
	 * @param string $message Short status string.
	 * @return void
	 */
	private static function log( $message ) {
		if ( function_exists( 'error_log' ) ) {
			error_log( 'DoughBoss Emails: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
