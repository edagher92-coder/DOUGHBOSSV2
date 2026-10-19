<?php
/**
 * Square partial-refund helpers (pure). The webhook transport, payment
 * re-read and wp_mail() need a real WordPress + Square sandbox.
 *
 * @package DoughBoss
 */

/**
 * @return array Synthetic attempt row.
 */
function db_test_refund_attempt() {
	return array( 'provider' => 'square', 'provider_reference' => 'sq-payment-synthetic-1', 'amount_minor' => 2500, 'currency' => 'AUD' );
}

db_test(
	'payment refund state: partial, accumulated full and refusals',
	function () {
		$attempt = db_test_refund_attempt();
		$payment = array( 'id' => 'sq-payment-synthetic-1', 'status' => 'COMPLETED', 'refunded_money' => array( 'amount' => 1000, 'currency' => 'AUD' ) );
		assert_same( array( 'state' => 'partial', 'refunded_minor' => 1000 ), DoughBoss_Square::payment_refund_state( $payment, $attempt ), 'less than paid stays partial' );

		$payment['refunded_money']['amount'] = 2500;
		assert_same( array( 'state' => 'full', 'refunded_minor' => 2500 ), DoughBoss_Square::payment_refund_state( $payment, $attempt ), 'several partials adding up to the attempt amount are full' );
		$payment['refunded_money']['amount'] = 2600;
		assert_same( 'full', DoughBoss_Square::payment_refund_state( $payment, $attempt )['state'], 'more than paid is full' );

		$refusals = array(
			'other payment'      => array_merge( $payment, array( 'id' => 'sq-payment-other' ) ),
			'not completed'      => array_merge( $payment, array( 'status' => 'APPROVED' ) ),
			'wrong currency'     => array_merge( $payment, array( 'refunded_money' => array( 'amount' => 2500, 'currency' => 'USD' ) ) ),
			'string amount'      => array_merge( $payment, array( 'refunded_money' => array( 'amount' => '2500', 'currency' => 'AUD' ) ) ),
			'no refunded money'  => array( 'id' => 'sq-payment-synthetic-1', 'status' => 'COMPLETED' ),
			'zero refunded'      => array_merge( $payment, array( 'refunded_money' => array( 'amount' => 0, 'currency' => 'AUD' ) ) ),
		);
		foreach ( $refusals as $label => $candidate ) {
			assert_same( 'none', DoughBoss_Square::payment_refund_state( $candidate, $attempt )['state'], 'refused: ' . $label );
		}
		$bad_attempt = $attempt;
		$bad_attempt['amount_minor'] = 0;
		assert_same( 'none', DoughBoss_Square::payment_refund_state( $payment, $bad_attempt )['state'], 'a zero attempt amount can never look fully refunded' );
	}
);

db_test(
	'partial refund staff notice names the order and amounts without customer data',
	function () {
		$notice = DoughBoss_Square::partial_refund_notice( 'DB-261015-ABC123', 500, 1000, 2500, 'aud', 'sq-payment-synthetic-1' );
		assert_same( '[DoughBoss] Partial refund on order DB-261015-ABC123: $5.00', $notice['subject'], 'subject' );
		assert_true( false !== strpos( $notice['body'], 'This refund: $5.00 AUD' ), 'refund amount' );
		assert_true( false !== strpos( $notice['body'], 'Refunded so far: $10.00 of $25.00 paid' ), 'running total' );
		assert_true( false !== strpos( $notice['body'], 'Order: DB-261015-ABC123' ), 'order number' );
		assert_true( false !== strpos( $notice['body'], 'stays marked as paid' ), 'states the order is unchanged' );
		$missing = DoughBoss_Square::partial_refund_notice( '', 500, 500, 2500, 'AUD', 'sq-payment-synthetic-1' );
		assert_true( false !== strpos( $missing['subject'], 'Square payment sq-payment-synthetic-1' ), 'falls back to the payment id' );
		assert_true( false !== strpos( $missing['body'], 'Order: not found' ), 'missing order is explicit' );
	}
);

db_test(
	'existing refund settlement keeps full refunds and flags partials',
	function () {
		$attempt = db_test_refund_attempt();
		$refund  = array( 'status' => 'COMPLETED', 'payment_id' => 'sq-payment-synthetic-1', 'amount_money' => array( 'amount' => 500, 'currency' => 'AUD' ) );
		assert_same( 'partial', DoughBoss_Square::refund_settlement( $refund, $attempt ), 'partial refund' );
		$refund['amount_money']['amount'] = 2500;
		assert_same( 'full', DoughBoss_Square::refund_settlement( $refund, $attempt ), 'full refund' );
	}
);
