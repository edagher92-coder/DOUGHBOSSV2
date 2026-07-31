<?php
/**
 * Offline MPGS Retrieve Order normalisation contract.
 *
 * @package DoughBoss\Tests
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../includes/class-doughboss-settings.php';
require __DIR__ . '/../includes/class-doughboss-mpgs.php';

$method = new ReflectionMethod( 'DoughBoss_MPGS', 'normalise_retrieved_order' );
$method->setAccessible( true );
$id      = 'DB-2-5c324ad15b33';
$attempt = array(
	'amount_minor'      => 1000,
	'checkout_key'      => hash( 'sha256', 'checkout' ),
	'safe_metadata_json' => wp_json_encode( array( 'purpose' => 'order' ) ),
);
$pass = 0;
$fail = 0;
function mpgs_ok( $condition, $label ) {
	global $pass, $fail;
	if ( $condition ) {
		++$pass;
		echo "  ok   $label\n";
	} else {
		++$fail;
		echo "  FAIL $label\n";
	}
}

echo "=== DoughBoss MPGS Retrieve Order contract ===\n";
$top = $method->invoke(
	null,
	array(
		'id'                  => $id,
		'status'              => 'CAPTURED',
		'amount'              => '10.00',
		'currency'            => 'AUD',
		'totalCapturedAmount' => '10.00',
		'result'              => 'SUCCESS',
	),
	$attempt,
	$id
);
mpgs_ok( 'succeeded' === $top['status'] && 1000 === $top['amount'] && 'aud' === $top['currency'], 'accepts the REST v100 top-level CAPTURED shape' );
mpgs_ok( $attempt['checkout_key'] === $top['metadata']['checkout_key'], 'restores the immutable server checkout key' );

$legacy = $method->invoke( null, array( 'order' => array( 'id' => $id, 'status' => 'CAPTURED', 'amount' => '10.00', 'currency' => 'AUD' ) ), $attempt, $id );
mpgs_ok( 'succeeded' === $legacy['status'], 'retains legacy nested response compatibility' );

$partial = $method->invoke( null, array( 'id' => $id, 'status' => 'PARTIALLY_CAPTURED', 'amount' => '10.00', 'currency' => 'AUD' ), $attempt, $id );
mpgs_ok( 'unknown' === $partial['status'], 'never accepts PARTIALLY_CAPTURED as paid' );

$short_capture = $method->invoke( null, array( 'id' => $id, 'status' => 'CAPTURED', 'amount' => '10.00', 'currency' => 'AUD', 'totalCapturedAmount' => '9.00' ), $attempt, $id );
mpgs_ok( is_wp_error( $short_capture ) && 'doughboss_pay_partial' === $short_capture->get_error_code(), 'rejects a captured total below the stored attempt amount' );

$mismatch = $method->invoke( null, array( 'id' => 'DB-9-aaaaaaaaaaaa', 'status' => 'CAPTURED', 'amount' => '10.00', 'currency' => 'AUD' ), $attempt, $id );
mpgs_ok( is_wp_error( $mismatch ) && 'doughboss_pay_mismatch' === $mismatch->get_error_code(), 'rejects a different provider order id' );

$failed = $method->invoke( null, array( 'id' => $id, 'status' => 'FAILED', 'amount' => '10.00', 'currency' => 'AUD' ), $attempt, $id );
mpgs_ok( 'failed' === $failed['status'], 'surfaces a failed gateway order' );

echo "\n=== RESULT: $pass passed · $fail failed ===\n";
exit( $fail ? 1 : 0 );
