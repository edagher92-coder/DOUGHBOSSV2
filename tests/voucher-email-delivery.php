<?php
/**
 * Claimed-voucher native email delivery contract.
 *
 * Exercises the hook handler without a WordPress install or real mail server.
 *
 * Run: php tests/voucher-email-delivery.php
 */

require_once __DIR__ . '/wp-stubs.php';

class DoughBoss_Settings {
	public static function format_price( $amount ) {
		return '$' . number_format( (float) $amount, 2 );
	}
}

class DoughBoss_Voucher {
	public static $rows = array();

	public static function find_by_code( $code ) {
		return isset( self::$rows[ $code ] ) ? self::$rows[ $code ] : null;
	}

	public static function campaigns() {
		return array(
			'dough5'  => array(
				'slug'  => 'dough5',
				'label' => '$5 Student Voucher',
				'type'  => 'amount',
				'value' => 5,
			),
			'campus15' => array(
				'slug'  => 'campus15',
				'label' => 'Campus Fifteen',
				'type'  => 'percent',
				'value' => 15.5,
			),
		);
	}
}

$GLOBALS['__db_mail_calls']  = array();
$GLOBALS['__db_mail_result'] = true;

function wp_mail( $to, $subject, $message ) {
	$GLOBALS['__db_mail_calls'][] = array(
		'to'      => $to,
		'subject' => $subject,
		'message' => $message,
	);
	if ( 'throw' === $GLOBALS['__db_mail_result'] ) {
		throw new RuntimeException( 'Simulated mailer error containing sensitive transport context.' );
	}
	return $GLOBALS['__db_mail_result'];
}

require_once dirname( __DIR__ ) . '/includes/class-doughboss-emails.php';

$passed = 0;
$failed = 0;

function voucher_email_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "PASS: {$label}\n";
		return;
	}
	$failed++;
	echo "FAIL: {$label}\n";
}

function voucher_email_row( $id, $code, $email, $type, $value, $label, $valid_to = '', $scope = 'both' ) {
	return (object) array(
		'id'             => $id,
		'code'           => $code,
		'customer_email' => $email,
		'type'           => $type,
		'value'          => $value,
		'single_use'     => 1,
		'valid_to'       => $valid_to,
		'scope'          => $scope,
		'meta'           => wp_json_encode( array( 'label' => $label ) ),
	);
}

echo "== Claimed voucher email delivery ==\n";

$source     = file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-emails.php' );
$assets     = file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-assets.php' );
$javascript = file_get_contents( dirname( __DIR__ ) . '/public/js/doughboss-voucher.js' );
$shortcodes = file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-shortcodes.php' );
$uninstall  = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );

DoughBoss_Emails::init();
voucher_email_ok(
	isset( $GLOBALS['__db_hooks']['doughboss_voucher_claimed'] )
		&& in_array( array( 'DoughBoss_Emails', 'on_voucher_claimed' ), $GLOBALS['__db_hooks']['doughboss_voucher_claimed'], true )
		&& false !== strpos( $source, "add_action( 'doughboss_voucher_claimed', array( __CLASS__, 'on_voucher_claimed' ), 10, 4 )" ),
	'email service registers the existing claim hook with all four arguments'
);
voucher_email_ok(
	false !== strpos( $source, "add_option( self::VOUCHER_DELIVERY_OPTION_PREFIX . \$voucher_id, time(), '', 'no' )" ),
	'voucher dispatch marker is an atomic autoload-off option'
);
voucher_email_ok(
	false !== strpos( $uninstall, "'doughboss_voucher_email_attempted_'" )
		&& false !== strpos( $uninstall, '$wpdb->esc_like( $voucher_email_marker_prefix )' ),
	'uninstall removes every per-voucher email marker safely by escaped prefix'
);

$amount_code = 'DOUGH-ABCD-EFGH';
DoughBoss_Voucher::$rows[ $amount_code ] = voucher_email_row(
	101,
	$amount_code,
	'student@example.edu.au',
	'amount',
	5,
	'$5 Student Voucher (Dough Boss)'
);
$amount_args = array( 'customer_email' => 'Student@example.edu.au' );
do_action( 'doughboss_voucher_claimed', 101, $amount_code, 'dough5', $amount_args );

voucher_email_ok( 1 === count( $GLOBALS['__db_mail_calls'] ), 'a valid claimed voucher dispatches one native email' );
$amount_mail = $GLOBALS['__db_mail_calls'][0];
voucher_email_ok(
	$amount_args['customer_email'] === $amount_mail['to'],
	'the recipient is the validated address supplied by the claim hook'
);
voucher_email_ok(
	false !== strpos( $amount_mail['subject'], 'DoughBoss Test' )
		&& false !== strpos( $amount_mail['message'], 'Store: DoughBoss Test' )
		&& false !== strpos( $amount_mail['message'], '$5 Student Voucher (Dough Boss)' )
		&& false !== strpos( $amount_mail['message'], 'Value: $5.00 off' )
		&& false !== strpos( $amount_mail['message'], 'Voucher code: ' . $amount_code ),
	'amount email includes site, campaign label, formatted value and code'
);
voucher_email_ok(
	false !== strpos( $amount_mail['message'], 'Single-use:' )
		&& false !== strpos( $amount_mail['message'], 'Expiry: No fixed expiry date is listed.' )
		&& false !== strpos( $amount_mail['message'], 'Voucher code field at checkout' )
		&& false !== strpos( $amount_mail['message'], 'choose Apply before paying' ),
	'email explains single use, open-ended expiry and online redemption'
);

$marker_key = DoughBoss_Emails::VOUCHER_DELIVERY_OPTION_PREFIX . '101';
voucher_email_ok(
	isset( $GLOBALS['__db_options'][ $marker_key ] )
		&& false === strpos( $marker_key . (string) $GLOBALS['__db_options'][ $marker_key ], $amount_code )
		&& false === strpos( $marker_key . (string) $GLOBALS['__db_options'][ $marker_key ], 'student@' ),
	'deduplication state contains only the voucher id and timestamp, not PII or code'
);

do_action( 'doughboss_voucher_claimed', 101, $amount_code, 'dough5', $amount_args );
voucher_email_ok( 1 === count( $GLOBALS['__db_mail_calls'] ), 'a replay of the same voucher id cannot send twice' );

$invalid_code = 'DOUGH-INVALID-MAIL';
DoughBoss_Voucher::$rows[ $invalid_code ] = voucher_email_row( 102, $invalid_code, 'not-an-email', 'amount', 5, '$5 Student Voucher' );
DoughBoss_Emails::on_voucher_claimed( 102, $invalid_code, 'dough5', array( 'customer_email' => 'not-an-email' ) );
voucher_email_ok(
	1 === count( $GLOBALS['__db_mail_calls'] )
		&& ! isset( $GLOBALS['__db_options'][ DoughBoss_Emails::VOUCHER_DELIVERY_OPTION_PREFIX . '102' ] ),
	'an invalid claim address is never mailed or marked as dispatched'
);

$mismatch_code = 'DOUGH-WRONG-ADDRESS';
DoughBoss_Voucher::$rows[ $mismatch_code ] = voucher_email_row( 103, $mismatch_code, 'owner@example.edu.au', 'amount', 5, '$5 Student Voucher' );
DoughBoss_Emails::on_voucher_claimed( 103, $mismatch_code, 'dough5', array( 'customer_email' => 'other@example.edu.au' ) );
voucher_email_ok( 1 === count( $GLOBALS['__db_mail_calls'] ), 'a valid but mismatched hook address cannot receive another voucher' );

$percent_code = 'CAMPUS-PCT-OFF';
DoughBoss_Voucher::$rows[ $percent_code ] = voucher_email_row(
	104,
	$percent_code,
	'percent@example.edu.au',
	'percent',
	15.5,
	'Campus Fifteen',
	'2026-07-31 23:59:59'
);
DoughBoss_Emails::on_voucher_claimed( 104, $percent_code, 'campus15', array( 'customer_email' => 'percent@example.edu.au' ) );
$percent_mail = $GLOBALS['__db_mail_calls'][1];
voucher_email_ok(
	false !== strpos( $percent_mail['message'], 'Offer: Campus Fifteen' )
		&& false !== strpos( $percent_mail['message'], 'Value: 15.5% off' )
		&& false !== strpos( $percent_mail['message'], 'Expiry: Use this voucher by July 31, 2026.' ),
	'percent email formats its value and explicit expiry date correctly'
);

$previous_error_log = ini_get( 'error_log' );
$privacy_log        = tempnam( sys_get_temp_dir(), 'doughboss-voucher-mail-' );
ini_set( 'error_log', $privacy_log );

$failed_code = 'DOUGH-FAIL-NATIVE';
$failed_mail = 'failure-private@example.edu.au';
DoughBoss_Voucher::$rows[ $failed_code ] = voucher_email_row( 105, $failed_code, $failed_mail, 'amount', 5, '$5 Student Voucher' );
$GLOBALS['__db_mail_result'] = false;
$failure_result = DoughBoss_Emails::on_voucher_claimed( 105, $failed_code, 'dough5', array( 'customer_email' => $failed_mail ) );
$calls_after_failure = count( $GLOBALS['__db_mail_calls'] );
DoughBoss_Emails::on_voucher_claimed( 105, $failed_code, 'dough5', array( 'customer_email' => $failed_mail ) );

$throw_code = 'DOUGH-THROW-NATIVE';
$throw_mail = 'throw-private@example.edu.au';
DoughBoss_Voucher::$rows[ $throw_code ] = voucher_email_row( 106, $throw_code, $throw_mail, 'amount', 5, '$5 Student Voucher' );
$GLOBALS['__db_mail_result'] = 'throw';
$throw_result = DoughBoss_Emails::on_voucher_claimed( 106, $throw_code, 'dough5', array( 'customer_email' => $throw_mail ) );

clearstatcache( true, $privacy_log );
$failure_log = file_get_contents( $privacy_log );
ini_set( 'error_log', $previous_error_log );
unlink( $privacy_log );

voucher_email_ok(
	null === $failure_result
		&& null === $throw_result
		&& $calls_after_failure === count( $GLOBALS['__db_mail_calls'] ) - 1
		&& isset( $GLOBALS['__db_options'][ DoughBoss_Emails::VOUCHER_DELIVERY_OPTION_PREFIX . '105' ] )
		&& isset( $GLOBALS['__db_options'][ DoughBoss_Emails::VOUCHER_DELIVERY_OPTION_PREFIX . '106' ] ),
	'false and thrown mail failures remain fire-and-forget and keep exactly-once markers'
);
voucher_email_ok(
	false !== strpos( $failure_log, 'voucher #105' )
		&& false !== strpos( $failure_log, 'voucher #106' )
		&& false === strpos( $failure_log, $failed_code )
		&& false === strpos( $failure_log, $failed_mail )
		&& false === strpos( $failure_log, $throw_code )
		&& false === strpos( $failure_log, $throw_mail )
		&& false === strpos( $failure_log, 'sensitive transport context' ),
	'failure logs contain voucher ids only, never recipient, code, body or exception details'
);

voucher_email_ok(
	false !== strpos( $assets, 'We are emailing this code to your student email.' )
		&& false !== strpos( $javascript, 'Keep this screen as a backup' )
		&& false !== strpos( $javascript, 'c.textContent = code' )
		&& false !== strpos( $shortcodes, 'also show it here as a backup' ),
	'customer UI says the code is emailed while retaining the on-screen code fallback'
);

echo "\nVoucher email delivery: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
