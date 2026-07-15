<?php
/**
 * Real MariaDB rehearsal for the Phase 2 lifecycle migration.
 *
 * Run through WP-CLI against a temporary WordPress database. This deliberately
 * constructs a 1.10 fixture before invoking the real migration runner.
 *
 * @package DoughBoss\Tests
 */

$plugin = dirname( __DIR__ );
require_once $plugin . '/doughboss.php';
require_once $plugin . '/includes/class-doughboss-settings.php';
require_once $plugin . '/includes/class-doughboss-migrations.php';
require_once $plugin . '/includes/class-doughboss-order.php';

global $wpdb;
$GLOBALS['doughboss_mariadb_passed'] = 0;
$GLOBALS['doughboss_mariadb_failed'] = 0;
function lifecycle_db_ok( $condition, $label ) {
	if ( $condition ) { ++$GLOBALS['doughboss_mariadb_passed']; echo "  ok   {$label}\n"; }
	else { ++$GLOBALS['doughboss_mariadb_failed']; echo "  FAIL {$label}\n"; }
}
function lifecycle_db_sql( $sql ) {
	global $wpdb;
	$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( false === $result ) {
		throw new RuntimeException( 'SQL failed: ' . $wpdb->last_error . ' :: ' . $sql );
	}
	return $result;
}

echo "=== DoughBoss MariaDB lifecycle migration rehearsal ===\n";
$orders  = $wpdb->prefix . 'doughboss_orders';
$items   = $wpdb->prefix . 'doughboss_order_items';
$events  = $wpdb->prefix . 'doughboss_order_events';
$locations = $wpdb->prefix . 'doughboss_locations';

// Start from the current definitions so the fixture tracks every legacy field,
// seed real data, then remove only the 1.11 lifecycle additions.
DoughBoss_Activator::create_tables();
$wpdb->insert( $locations, array( 'name' => 'Migration Shop', 'slug' => 'migration-shop', 'is_active' => 1 ) );
$location_id = (int) $wpdb->insert_id;
$wpdb->insert(
	$orders,
	array(
		'order_number' => 'DB-LIFECYCLE-MIGRATION-1', 'location_id' => $location_id,
		'status' => 'pending', 'order_type' => 'pickup', 'customer_name' => 'Preserved Customer',
		'customer_email' => 'preserved@example.test', 'customer_phone' => '0400000000',
		'subtotal' => 42.50, 'tax' => 3.86, 'total' => 42.50,
		'payment_status' => 'paid', 'payment_method' => 'stripe', 'payment_intent_id' => 'pi_preserve_phase2',
		'created_at' => '2026-07-01 08:00:00', 'updated_at' => '2026-07-01 08:00:00',
	)
);
$order_id = (int) $wpdb->insert_id;
$wpdb->insert(
	$items,
	array(
		'order_id' => $order_id, 'item_id' => 17, 'name' => 'Preserved Pizza',
		'quantity' => 2, 'unit_price' => 21.25, 'line_total' => 42.50,
	)
);
$snapshot = $wpdb->get_row( $wpdb->prepare( "SELECT order_number,location_id,status,order_type,customer_name,customer_email,customer_phone,subtotal,tax,total,payment_status,payment_method,payment_intent_id,created_at,updated_at FROM {$orders} WHERE id = %d", $order_id ), ARRAY_A );
$item_snapshot = $wpdb->get_row( $wpdb->prepare( "SELECT item_id,name,quantity,unit_price,line_total FROM {$items} WHERE order_id = %d", $order_id ), ARRAY_A );

lifecycle_db_sql( "DROP TABLE IF EXISTS {$events}" );
lifecycle_db_sql( "ALTER TABLE {$orders} DROP INDEX promised_ready_from" );
lifecycle_db_sql( "ALTER TABLE {$orders} DROP COLUMN version, DROP COLUMN status_changed_at, DROP COLUMN promised_ready_from_utc, DROP COLUMN promised_ready_by_utc, DROP COLUMN timezone_snapshot, DROP COLUMN cooking_started_at, DROP COLUMN ready_at, DROP COLUMN completed_at, DROP COLUMN cancelled_at" );
update_option( 'doughboss_db_version', '1.10.0' );
delete_option( 'doughboss_migration_lock' );
delete_option( 'doughboss_migration_error' );

DoughBoss_Migrations::run();
lifecycle_db_ok( '1.11.0' === get_option( 'doughboss_db_version' ), '1.10 fixture migrates to database contract 1.11' );
lifecycle_db_ok( DoughBoss_Activator::lifecycle_storage_ready(), 'orders, items and events satisfy lifecycle storage invariants' );
$after = $wpdb->get_row( $wpdb->prepare( "SELECT order_number,location_id,status,order_type,customer_name,customer_email,customer_phone,subtotal,tax,total,payment_status,payment_method,payment_intent_id,created_at,updated_at FROM {$orders} WHERE id = %d", $order_id ), ARRAY_A );
$item_after = $wpdb->get_row( $wpdb->prepare( "SELECT item_id,name,quantity,unit_price,line_total FROM {$items} WHERE order_id = %d", $order_id ), ARRAY_A );
lifecycle_db_ok( $snapshot === $after, 'existing order, customer, totals and payment reference remain byte-for-byte unchanged' );
lifecycle_db_ok( $item_snapshot === $item_after, 'existing order item remains byte-for-byte unchanged' );
lifecycle_db_ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT version FROM {$orders} WHERE id = %d", $order_id ) ), 'existing order receives the neutral initial version' );
lifecycle_db_ok( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" ), 'migration does not fabricate historical lifecycle events' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$counts_before = array(
	'orders' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	'items'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$items}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	'events' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
);
DoughBoss_Migrations::run();
$counts_after = array(
	'orders' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	'items'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$items}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	'events' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
);
lifecycle_db_ok( $counts_before === $counts_after, 'migration rerun is idempotent' );

// A missing required column/index must fail readiness rather than silently
// checkpointing a partially applied schema.
lifecycle_db_sql( "ALTER TABLE {$events} DROP INDEX event_key, DROP COLUMN event_key" );
lifecycle_db_ok( ! DoughBoss_Activator::lifecycle_storage_ready(), 'partial event schema fails readiness' );
DoughBoss_Activator::create_tables();
lifecycle_db_ok( DoughBoss_Activator::lifecycle_storage_ready(), 'dbDelta repairs the missing event contract' );

// A same-named but wrongly-shaped unique index must not satisfy readiness.
lifecycle_db_sql( "ALTER TABLE {$events} DROP INDEX order_version, ADD UNIQUE KEY order_version (order_id)" );
lifecycle_db_ok( ! DoughBoss_Activator::lifecycle_storage_ready(), 'wrong ordered columns under the expected index name fail readiness' );
lifecycle_db_sql( "ALTER TABLE {$events} DROP INDEX order_version, ADD UNIQUE KEY order_version (order_id,order_version)" );
lifecycle_db_ok( DoughBoss_Activator::lifecycle_storage_ready(), 'exact event uniqueness contract restores readiness' );

// A prefix-only idempotency key can still collide and is not the same contract.
lifecycle_db_sql( "ALTER TABLE {$events} DROP INDEX event_key, ADD UNIQUE KEY event_key (event_key(16))" );
lifecycle_db_ok( ! DoughBoss_Activator::lifecycle_storage_ready(), 'prefix-only event key fails readiness' );
lifecycle_db_sql( "ALTER TABLE {$events} DROP INDEX event_key, ADD UNIQUE KEY event_key (event_key)" );
lifecycle_db_ok( DoughBoss_Activator::lifecycle_storage_ready(), 'full event idempotency key restores readiness' );

// A present column with an unsafe default must also fail readiness.
lifecycle_db_sql( "ALTER TABLE {$orders} MODIFY version bigint(20) unsigned NOT NULL DEFAULT 2" );
lifecycle_db_ok( ! DoughBoss_Activator::lifecycle_storage_ready(), 'wrong order-version default fails readiness' );
lifecycle_db_sql( "ALTER TABLE {$orders} MODIFY version bigint(20) unsigned NOT NULL DEFAULT 1" );
lifecycle_db_ok( DoughBoss_Activator::lifecycle_storage_ready(), 'exact lifecycle column contract restores readiness' );

// Integer display width is not storage semantics and is omitted by MySQL 8.
lifecycle_db_sql( "ALTER TABLE {$orders} MODIFY version bigint(19) unsigned NOT NULL DEFAULT 1" );
lifecycle_db_ok( DoughBoss_Activator::lifecycle_storage_ready(), 'integer display-width variation remains compatible' );
lifecycle_db_sql( "ALTER TABLE {$orders} MODIFY version bigint(20) NOT NULL DEFAULT 1" );
lifecycle_db_ok( ! DoughBoss_Activator::lifecycle_storage_ready(), 'signed lifecycle version fails readiness' );
lifecycle_db_sql( "ALTER TABLE {$orders} MODIFY version bigint(20) unsigned NOT NULL DEFAULT 1" );
lifecycle_db_ok( DoughBoss_Activator::lifecycle_storage_ready(), 'unsigned lifecycle version restores readiness' );

// NULL and an empty default must never be treated as equivalent.
lifecycle_db_sql( "ALTER TABLE {$orders} ALTER timezone_snapshot DROP DEFAULT" );
lifecycle_db_ok( ! DoughBoss_Activator::lifecycle_storage_ready(), 'missing timezone default fails readiness' );
lifecycle_db_sql( "ALTER TABLE {$orders} ALTER timezone_snapshot SET DEFAULT ''" );
lifecycle_db_ok( DoughBoss_Activator::lifecycle_storage_ready(), 'empty timezone default restores readiness' );

// Prove the real InnoDB transaction rolls the order update back when its event
// cannot be written.
$wpdb->insert( $orders, array( 'order_number' => 'DB-LIFECYCLE-ROLLBACK-1', 'location_id' => $location_id, 'status' => 'pending', 'version' => 1, 'order_type' => 'pickup' ) );
$rollback_order_id = (int) $wpdb->insert_id;
lifecycle_db_sql( "DROP TABLE {$events}" );
$failed_transition = DoughBoss_Order::transition(
	$rollback_order_id,
	'confirmed',
	array( 'expected_version' => 1, 'event_key' => 'mariadb:rollback:confirmed', 'actor_type' => 'staff', 'actor_id' => 7, 'eta_minutes' => 20 )
);
$rolled_back = $wpdb->get_row( $wpdb->prepare( "SELECT status,version,accepted_at,promised_ready_from_utc FROM {$orders} WHERE id = %d", $rollback_order_id ), ARRAY_A );
lifecycle_db_ok( is_wp_error( $failed_transition ), 'missing event storage makes the transition fail' );
lifecycle_db_ok( 'pending' === $rolled_back['status'] && 1 === (int) $rolled_back['version'] && null === $rolled_back['accepted_at'] && null === $rolled_back['promised_ready_from_utc'], 'failed event insert rolls the order transition back completely' );
DoughBoss_Activator::create_tables();

// A non-transactional participant must block the migration checkpoint.
lifecycle_db_sql( "ALTER TABLE {$events} ENGINE=MyISAM" );
update_option( 'doughboss_db_version', '1.10.0' );
delete_option( 'doughboss_migration_lock' );
delete_option( 'doughboss_migration_error' );
DoughBoss_Migrations::run();
lifecycle_db_ok( '1.10.0' === get_option( 'doughboss_db_version' ), 'MyISAM lifecycle storage blocks the version checkpoint' );
lifecycle_db_ok( (bool) get_option( 'doughboss_migration_error' ), 'failed migration records an operator-visible error' );
lifecycle_db_sql( "ALTER TABLE {$events} ENGINE=InnoDB" );
DoughBoss_Activator::create_tables();
lifecycle_db_ok( DoughBoss_Activator::lifecycle_storage_ready(), 'restored InnoDB lifecycle storage is ready' );

$passed = (int) $GLOBALS['doughboss_mariadb_passed'];
$failed = (int) $GLOBALS['doughboss_mariadb_failed'];
echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
