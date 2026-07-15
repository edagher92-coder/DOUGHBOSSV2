<?php
/**
 * Real MariaDB migration and empty-slot concurrency test, run through WP-CLI.
 *
 * @package DoughBoss\Tests
 */

$plugin = dirname( __DIR__ );
require_once $plugin . '/doughboss.php';

global $wpdb;
$passed = 0;
$failed = 0;
function mariadb_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) { ++$passed; echo "  ok   {$label}\n"; }
	else { ++$failed; echo "  FAIL {$label}\n"; }
}
function mariadb_sql( $sql ) {
	global $wpdb;
	$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( false === $result ) {
		throw new RuntimeException( 'SQL failed: ' . $wpdb->last_error . ' :: ' . $sql );
	}
	return $result;
}

echo "=== DoughBoss MariaDB capacity integration ===\n";
$orders     = $wpdb->prefix . 'doughboss_orders';
$events     = $wpdb->prefix . 'doughboss_order_events';
$locations  = $wpdb->prefix . 'doughboss_locations';
$hours      = $wpdb->prefix . 'doughboss_location_hours';
$exceptions = $wpdb->prefix . 'doughboss_schedule_exceptions';
$slots      = $wpdb->prefix . 'doughboss_capacity_slots';
$holds      = $wpdb->prefix . 'doughboss_capacity_holds';

// Build the current schema once, then strip Phase 3 to produce a real 1.11 fixture.
DoughBoss_Activator::create_tables();
$wpdb->insert( $locations, array( 'name' => 'Migration Shop', 'slug' => 'migration-shop', 'is_active' => 1 ) );
$location_id = (int) $wpdb->insert_id;
$wpdb->insert(
	$orders,
	array(
		'order_number' => 'DB-MIGRATION-1', 'location_id' => $location_id, 'status' => 'confirmed',
		'version' => 3, 'total' => 42.50, 'payment_intent_id' => 'pi_preserve_me',
	)
);
$order_id = (int) $wpdb->insert_id;
$wpdb->insert(
	$events,
	array(
		'order_id' => $order_id, 'order_version' => 3, 'event_type' => 'status_changed',
		'from_status' => 'pending', 'to_status' => 'confirmed', 'event_key' => 'migration:preserve:3',
	)
);
$snapshot = $wpdb->get_row( $wpdb->prepare( "SELECT order_number,status,version,total,payment_intent_id FROM {$orders} WHERE id = %d", $order_id ), ARRAY_A );

foreach ( array( $holds, $slots, $exceptions, $hours ) as $table ) {
	mariadb_sql( "DROP TABLE IF EXISTS {$table}" );
}
mariadb_sql( "ALTER TABLE {$orders} DROP INDEX fire_time, DROP COLUMN capacity_hold_id, DROP COLUMN capacity_units, DROP COLUMN fire_at_utc, DROP COLUMN planning_version" );
mariadb_sql( "ALTER TABLE {$locations} DROP COLUMN timezone, DROP COLUMN capacity_mode, DROP COLUMN slot_minutes, DROP COLUMN minimum_notice_minutes, DROP COLUMN booking_horizon_days, DROP COLUMN hold_minutes, DROP COLUMN slot_order_capacity, DROP COLUMN slot_unit_capacity, DROP COLUMN planning_version" );
update_option( 'doughboss_db_version', '1.11.0' );
delete_option( 'doughboss_migration_lock' );
delete_option( 'doughboss_migration_error' );

DoughBoss_Migrations::run();
mariadb_ok( '1.12.0' === get_option( 'doughboss_db_version' ), '1.11 fixture migrates to 1.12' );
mariadb_ok( DoughBoss_Activator::capacity_storage_ready(), 'all capacity storage invariants are ready' );
$after = $wpdb->get_row( $wpdb->prepare( "SELECT order_number,status,version,total,payment_intent_id FROM {$orders} WHERE id = %d", $order_id ), ARRAY_A );
mariadb_ok( $snapshot === $after, 'existing order truth and payment reference are unchanged' );
mariadb_ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE order_id = %d", $order_id ) ), 'existing lifecycle event is unchanged' );

$counts_before = array();
foreach ( array( $orders, $events, $locations, $hours, $exceptions, $slots, $holds ) as $table ) {
	$counts_before[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
DoughBoss_Migrations::run();
$counts_after = array();
foreach ( array_keys( $counts_before ) as $table ) {
	$counts_after[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
mariadb_ok( $counts_before === $counts_after, 'migration rerun is idempotent' );

mariadb_sql( "ALTER TABLE {$slots} DROP COLUMN unit_capacity" );
mariadb_ok( ! DoughBoss_Activator::capacity_storage_ready(), 'partial slot schema fails readiness' );
DoughBoss_Activator::create_tables();
mariadb_ok( DoughBoss_Activator::capacity_storage_ready(), 'dbDelta repairs a missing required column' );

mariadb_sql( "ALTER TABLE {$holds} ENGINE=MyISAM" );
update_option( 'doughboss_db_version', '1.11.0' );
delete_option( 'doughboss_migration_lock' );
delete_option( 'doughboss_migration_error' );
DoughBoss_Migrations::run();
mariadb_ok( '1.11.0' === get_option( 'doughboss_db_version' ), 'non-transactional hold storage blocks the version checkpoint' );
mariadb_ok( (bool) get_option( 'doughboss_migration_error' ), 'failed readiness records an operator-visible migration error' );
mariadb_sql( "ALTER TABLE {$holds} ENGINE=InnoDB" );
DoughBoss_Activator::create_tables();
update_option( 'doughboss_db_version', '1.12.0' );
delete_option( 'doughboss_migration_error' );

// Prove the mutex works when the slot begins with zero hold rows.
$now   = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
$start = $now->modify( '+2 hours' )->format( 'Y-m-d H:i:s' );
$end   = $now->modify( '+2 hours 15 minutes' )->format( 'Y-m-d H:i:s' );
$wpdb->insert(
	$slots,
	array(
		'location_id' => $location_id, 'order_type' => 'pickup', 'starts_at_utc' => $start,
		'ends_at_utc' => $end, 'timezone_snapshot' => 'Australia/Sydney', 'order_capacity' => 1,
		'unit_capacity' => 1, 'planning_version' => 1, 'accepting_holds' => 1,
		'created_at' => $now->format( 'Y-m-d H:i:s' ), 'updated_at' => $now->format( 'Y-m-d H:i:s' ),
	)
);
$slot_id = (int) $wpdb->insert_id;
$locker = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$locker->prefix = $wpdb->prefix;
$locker->query( 'START TRANSACTION' );
$locker->get_var( $locker->prepare( "SELECT id FROM {$slots} WHERE id = %d FOR UPDATE", $slot_id ) );

$base = sys_get_temp_dir() . '/doughboss-race-' . getmypid();
$children = array();
for ( $i = 1; $i <= 2; ++$i ) {
	$pid = pcntl_fork();
	if ( -1 === $pid ) { throw new RuntimeException( 'pcntl_fork failed' ); }
	if ( 0 === $pid ) {
		$child_db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$child_db->prefix = $wpdb->prefix;
		$child_db->query( 'SET SESSION innodb_lock_wait_timeout = 10' );
		$GLOBALS['wpdb'] = $child_db;
		file_put_contents( $base . '.ready.' . $i, 'ready' );
		$lines = array( array( 'type' => 'menu', 'item_id' => 1, 'size' => '', 'toppings' => array(), 'quantity' => 1, 'unit_price' => 10 ) );
		$context = array( 'location_id' => $location_id, 'order_type' => 'pickup', 'total' => 10, 'hold_minutes' => 10 );
		$result = DoughBoss_Capacity::create_hold( $slot_id, $lines, $context, 'mariadb-race-key-000' . $i, $now );
		$out = is_wp_error( $result ) ? array( 'ok' => false, 'code' => $result->get_error_code() ) : array( 'ok' => true, 'token' => $result['hold_token'] );
		file_put_contents( $base . '.result.' . $i, wp_json_encode( $out ) );
		exit( 0 );
	}
	$children[] = $pid;
}

$deadline = microtime( true ) + 10;
while ( ( ! file_exists( $base . '.ready.1' ) || ! file_exists( $base . '.ready.2' ) ) && microtime( true ) < $deadline ) {
	usleep( 10000 );
}
mariadb_ok( file_exists( $base . '.ready.1' ) && file_exists( $base . '.ready.2' ), 'both contenders reached the pre-held empty-slot mutex' );
$locker->query( 'COMMIT' );
foreach ( $children as $pid ) { pcntl_waitpid( $pid, $status ); }
$results = array(
	json_decode( (string) file_get_contents( $base . '.result.1' ), true ),
	json_decode( (string) file_get_contents( $base . '.result.2' ), true ),
);
$successes = array_values( array_filter( $results, static function ( $row ) { return ! empty( $row['ok'] ); } ) );
$full = array_values( array_filter( $results, static function ( $row ) { return empty( $row['ok'] ) && isset( $row['code'] ) && 'doughboss_slot_full' === $row['code']; } ) );
mariadb_ok( 1 === count( $successes ) && 1 === count( $full ), 'exactly one final-unit contender succeeds and one sees full' );
$usage = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS holds, COALESCE(SUM(capacity_units),0) AS units FROM {$holds} WHERE slot_id = %d AND status = 'held'", $slot_id ) );
mariadb_ok( 1 === (int) $usage->holds && 1 === (int) $usage->units, 'committed capacity never exceeds the final unit' );

foreach ( glob( $base . '.*' ) as $file ) { unlink( $file ); }
echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
