<?php
/** Real InnoDB pickup hold contention; disposable local fixture only. */
if (
	'local' !== wp_get_environment_type()
	|| 'doughboss_wp_test' !== DB_NAME
	|| 'http://doughboss.local.test' !== untrailingslashit( home_url() )
	|| ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || true !== WP_HTTP_BLOCK_EXTERNAL
) {
	fwrite( STDERR, "Refusing pickup race outside the isolated synthetic site.\n" );
	exit( 2 );
}
$mode = getenv( 'DOUGHBOSS_PICKUP_RACE_MODE' );
$key = 'doughboss_synthetic_pickup_race';
if ( 'prepare' === $mode ) {
	$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	$from = $now->modify( '+2 days' );
	$slot = DoughBoss_Capacity::materialise_slot(
		array( 'location_id' => 1, 'capacity_units' => 6, 'timezone' => 'Australia/Sydney', 'planning_version' => 1 ),
		array( 'ready_from_utc' => $from->format( 'Y-m-d\TH:i:s\Z' ), 'ready_by_utc' => $from->modify( '+15 minutes' )->format( 'Y-m-d\TH:i:s\Z' ) ),
		$now
	);
	if ( is_wp_error( $slot ) || ! add_option( $key, array( 'slot_id' => $slot, 'now' => $now->format( 'Y-m-d\TH:i:s\Z' ) ), '', false ) ) {
		fwrite( STDERR, "Pickup race preparation failed or was already run.\n" );
		exit( 1 );
	}
	echo "PICKUP_PREPARE_OK\n";
	return;
}
$fixture = get_option( $key );
if ( ! is_array( $fixture ) || empty( $fixture['slot_id'] ) || empty( $fixture['now'] ) ) {
	fwrite( STDERR, "Pickup race preparation missing.\n" );
	exit( 2 );
}
if ( 'verify' === $mode ) {
	global $wpdb;
	$table = $wpdb->prefix . 'doughboss_capacity_holds';
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE slot_id = %d", $fixture['slot_id'] ), ARRAY_A );
	if ( 6 !== count( $rows ) || 6 !== count( array_unique( array_column( $rows, 'token_hash' ) ) ) || 6 !== count( array_filter( $rows, static function ( $row ) { return 'held' === $row['status'] && null === $row['order_id']; } ) ) ) {
		fwrite( STDERR, "Pickup race durable allocation count or identity mismatch.\n" );
		exit( 1 );
	}
	echo "PICKUP_VERIFY_OK: 6 unique held allocations, no orders\n";
	return;
}
if ( 'hold' !== $mode ) {
	fwrite( STDERR, "Unknown pickup race mode.\n" );
	exit( 2 );
}
$scratch = getenv( 'DOUGHBOSS_PICKUP_RACE_SCRATCH' );
if ( ! is_string( $scratch ) || ! is_dir( $scratch ) || 'pickup-race' !== basename( $scratch ) ) {
	fwrite( STDERR, "Pickup race barrier directory missing.\n" );
	exit( 2 );
}
$ready = $scratch . DIRECTORY_SEPARATOR . 'ready-' . getmypid() . '.json';
if ( false === file_put_contents( $ready, wp_json_encode( array( 'pid' => getmypid() ) ), LOCK_EX ) ) {
	fwrite( STDERR, "Pickup race readiness signal failed.\n" );
	exit( 1 );
}
$deadline = microtime( true ) + 30;
while ( ! is_file( $scratch . DIRECTORY_SEPARATOR . 'go' ) ) {
	if ( microtime( true ) > $deadline ) {
		fwrite( STDERR, "Pickup race explicit start barrier timed out.\n" );
		exit( 1 );
	}
	usleep( 20000 );
}
$result = DoughBoss_Capacity::create_hold(
	$fixture['slot_id'],
	array( array( 'item_id' => 9851, 'name' => 'Synthetic race item', 'quantity' => 1, 'unit_price' => 12.0, 'line_total' => 12.0 ) ),
	array( 'location_id' => 1, 'order_type' => 'pickup', 'voucher' => '', 'total' => 12.0 ),
	'pickup-race-' . getmypid() . '-hold-0001',
	new DateTimeImmutable( $fixture['now'], new DateTimeZone( 'UTC' ) )
);
if ( is_array( $result ) && empty( $result['replayed'] ) ) {
	echo "PICKUP_HOLD_OK\n";
	return;
}
if ( is_wp_error( $result ) && 'doughboss_slot_full' === $result->get_error_code() ) {
	echo "PICKUP_FULL_OK\n";
	return;
}
fwrite( STDERR, 'Unexpected pickup race result: ' . ( is_wp_error( $result ) ? $result->get_error_code() : 'invalid_shape' ) . "\n" );
exit( 1 );
