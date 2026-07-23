<?php
/**
 * Customer stage-transition emails (built-in, via wp_mail).
 *
 * Emails the customer at two order milestones: an "we're on it" note when the
 * kitchen accepts the order (with the ETA when one was given) and a "ready for
 * pickup" note when the order moves to the `ready` status. Modeled on
 * DoughBoss_SMS: a static class registered via init(), where every handler
 * self-gates on its per-stage toggle and returns immediately when that stage
 * is switched off — no external service is involved, so plain wp_mail() with
 * no extra configuration is enough.
 *
 * Idempotency: kitchen board undo/redo can re-fire the accept/status hooks for
 * the same order, so a small stage log (option `doughboss_email_stage_log`,
 * autoload off) records which stages have already been emailed per order and
 * is pruned to the most recent orders. The same stage is never emailed twice
 * for one order.
 *
 * Privacy: log lines carry only the order id and the stage — never the
 * customer email address, name or message body.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stage-transition email dispatcher. Static; register via init().
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
	 * Register the email hooks. Always safe to call — every handler self-gates
	 * on its per-stage toggle and does nothing when that stage is switched off.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'doughboss_order_accepted', array( __CLASS__, 'on_order_accepted' ), 10, 2 );
		add_action( 'doughboss_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 2 );
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
