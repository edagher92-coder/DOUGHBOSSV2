<?php
/**
 * Catering payment receipts (customer + catering inbox).
 *
 * Enquiry acknowledgements are sent by the REST controller and Square emails
 * the invoice itself, so this class only covers "payment received". Each
 * enquiry/leg sends at most once (add_option unique-insert guard).
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DoughBoss_Catering_Emails {

	const SENT_PREFIX = 'doughboss_cat_mail_';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'doughboss_catering_payment', array( __CLASS__, 'on_payment' ), 10, 2 );
	}

	/**
	 * A deposit or balance was paid (any gateway): receipt + staff alert.
	 *
	 * @param int    $id  Enquiry id.
	 * @param string $leg deposit|balance.
	 * @return void
	 */
	public static function on_payment( $id, $leg ) {
		$enquiry = DoughBoss_Catering::get( absint( $id ) );
		if ( ! $enquiry ) {
			return;
		}
		$leg    = DoughBoss_Catering::LEG_BALANCE === $leg ? DoughBoss_Catering::LEG_BALANCE : DoughBoss_Catering::LEG_DEPOSIT;
		$amount = DoughBoss_Settings::format_price( DoughBoss_Catering::leg_amount( $enquiry, $leg ) );
		$label  = DoughBoss_Catering::LEG_BALANCE === $leg ? 'balance' : 'deposit';

		if ( self::claim( $enquiry['id'], 'staff_paid_' . $leg ) ) {
			wp_mail(
				DoughBoss_Settings::catering_email(),
				sprintf( 'Catering %s paid — %s (%s)', $label, $enquiry['enquiry_number'], $amount ),
				sprintf( "%s paid the %s of %s for %s.\n\n%s", $enquiry['customer_name'], $label, $amount, $enquiry['enquiry_number'], implode( "\n", self::summary_lines( $enquiry ) ) )
			);
		}

		if ( is_email( $enquiry['customer_email'] ) && self::claim( $enquiry['id'], 'customer_paid_' . $leg ) ) {
			$next = DoughBoss_Catering::LEG_DEPOSIT === $leg && (float) $enquiry['balance_amount'] > 0
				? sprintf( "Your booking is confirmed. The remaining balance of %s will be invoiced before your event.\n\n", DoughBoss_Settings::format_price( $enquiry['balance_amount'] ) )
				: "You’re all paid up — see you at pickup!\n\n";
			wp_mail(
				$enquiry['customer_email'],
				sprintf( 'Payment received — catering %s (%s)', $enquiry['enquiry_number'], $amount ),
				'Hi ' . self::first_name( $enquiry['customer_name'] ) . ",\n\n"
					. sprintf( "We’ve received your %s payment of %s. Thank you!\n\n", $label, $amount )
					. $next
					. implode( "\n", self::summary_lines( $enquiry ) )
					. "\n\nPickup: DoughBoss Revesby, 12/25 Selems Parade, Revesby NSW.\n"
					. 'Questions? Call ' . DoughBoss_Settings::catering_phone() . ".\n\nDoughBoss",
				array( 'Reply-To: ' . DoughBoss_Settings::catering_email() )
			);
		}
	}

	/**
	 * Plain-text summary of the enquiry.
	 *
	 * @param array $enquiry Row.
	 * @return string[]
	 */
	public static function summary_lines( array $enquiry ) {
		$lines = array( 'Reference: ' . $enquiry['enquiry_number'], 'Event: ' . self::event_label( $enquiry ) );
		if ( (int) $enquiry['guest_count'] > 0 ) {
			$lines[] = 'Guests: ' . (int) $enquiry['guest_count'];
		}
		$lines[] = 'Collection: ' . ( 'delivery' === $enquiry['order_type'] ? 'Delivery' : 'Pickup (Revesby)' );
		if ( (float) $enquiry['quote_total'] > 0 ) {
			$lines[] = 'Quote total: ' . DoughBoss_Settings::format_price( $enquiry['quote_total'] );
		}
		return $lines;
	}

	/**
	 * "Fri 24 Oct 2026, 11:00" style label.
	 *
	 * @param array $enquiry Row.
	 * @return string
	 */
	public static function event_label( array $enquiry ) {
		$date = (string) $enquiry['event_date'];
		$ts   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? strtotime( $date . ' 00:00:00 UTC' ) : false;
		$out  = $ts ? gmdate( 'D j M Y', $ts ) : ( '' !== $date ? $date : 'date TBC' );
		return '' !== (string) $enquiry['event_time'] ? $out . ', ' . $enquiry['event_time'] : $out;
	}

	/**
	 * @param string $name Full name.
	 * @return string
	 */
	public static function first_name( $name ) {
		$parts = preg_split( '/\s+/', trim( (string) $name ) );
		return ! empty( $parts[0] ) ? $parts[0] : 'there';
	}

	/**
	 * Claim a one-time send slot. add_option is a unique insert, so only one
	 * worker can win even under concurrent webhook + browser confirmation.
	 *
	 * @param int    $id    Enquiry id.
	 * @param string $stage Stage key.
	 * @return bool
	 */
	private static function claim( $id, $stage ) {
		return (bool) add_option( self::SENT_PREFIX . absint( $id ) . '_' . sanitize_key( $stage ), gmdate( 'c' ), '', 'no' );
	}
}
