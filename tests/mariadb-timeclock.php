<?php
/**
 * Real MariaDB acceptance test for location-bound staff attendance.
 *
 * Run through WP-CLI against a temporary WordPress database. State-changing
 * HTTP handlers redirect and exit, so each is exercised in a short child
 * process with its own database connection while the parent verifies the
 * committed database truth.
 *
 * @package DoughBoss\Tests
 */

$plugin = dirname( __DIR__ );
require_once $plugin . '/doughboss.php';
require_once $plugin . '/includes/class-doughboss-settings.php';
require_once $plugin . '/includes/class-doughboss-migrations.php';
require_once $plugin . '/includes/class-doughboss-locations.php';
require_once $plugin . '/includes/class-doughboss-staff-scope.php';
require_once $plugin . '/includes/class-doughboss-timeclock.php';
require_once $plugin . '/includes/class-doughboss-staff-badge.php';

global $wpdb;
$GLOBALS['doughboss_timeclock_passed'] = 0;
$GLOBALS['doughboss_timeclock_failed'] = 0;

/** Record one acceptance assertion. */
function timeclock_db_ok( $condition, $label ) {
	if ( $condition ) {
		++$GLOBALS['doughboss_timeclock_passed'];
		echo "  ok   {$label}\n";
	} else {
		++$GLOBALS['doughboss_timeclock_failed'];
		echo "  FAIL {$label}\n";
	}
}

/** Run schema-changing SQL and fail immediately if the fixture cannot proceed. */
function timeclock_db_sql( $sql ) {
	global $wpdb;
	$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( false === $result ) {
		throw new RuntimeException( 'SQL failed: ' . $wpdb->last_error . ' :: ' . $sql );
	}
	return $result;
}

/**
 * Fork one real redirecting time-clock handler.
 *
 * @return int Child PID.
 */
function timeclock_fork_handler( $method, $user_id, $nonce_action, array $post = array(), $gate = '', $ready = '' ) {
	global $wpdb;
	$pid = pcntl_fork();
	if ( -1 === $pid ) {
		throw new RuntimeException( 'pcntl_fork failed' );
	}
	if ( 0 === $pid ) {
		$child_db         = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$child_db->set_prefix( $wpdb->prefix );
		$child_db->query( 'SET SESSION innodb_lock_wait_timeout = 10' );
		$GLOBALS['wpdb'] = $child_db;
		wp_set_current_user( (int) $user_id );
		$post['_wpnonce'] = wp_create_nonce( $nonce_action );
		$_GET             = array();
		$_POST            = $post;
		$_REQUEST         = $post;
		if ( $ready ) {
			file_put_contents( $ready, 'ready' );
		}
		if ( $gate ) {
			$deadline = microtime( true ) + 10;
			while ( ! file_exists( $gate ) && microtime( true ) < $deadline ) {
				usleep( 10000 );
			}
		}
		$clock = new DoughBoss_Timeclock();
		$clock->{$method}();
		exit( 90 ); // The production handler must redirect and exit first.
	}
	return $pid;
}

/** Fork a denied handler boundary and capture whether execution got past it. */
function timeclock_fork_denied_handler( $method, $user_id, array $post, $result_file, $nonce_action = '' ) {
	global $wpdb;
	$pid = pcntl_fork();
	if ( -1 === $pid ) {
		throw new RuntimeException( 'pcntl_fork failed' );
	}
	if ( 0 === $pid ) {
		$child_db         = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$child_db->set_prefix( $wpdb->prefix );
		$GLOBALS['wpdb'] = $child_db;
		wp_set_current_user( (int) $user_id );
		$post['_wpnonce'] = $nonce_action ? wp_create_nonce( $nonce_action ) : 'intentionally-invalid';
		$_GET             = array();
		$_POST            = $post;
		$_REQUEST         = $post;
		register_shutdown_function(
			static function () use ( $result_file ) {
				file_put_contents( $result_file, 'stopped' );
			}
		);
		$clock = new DoughBoss_Timeclock();
		$clock->{$method}();
		file_put_contents( $result_file, 'mutated' );
		exit( 91 );
	}
	return $pid;
}

/** Wait for a child and report whether its redirecting handler exited normally. */
function timeclock_wait_handler( $pid ) {
	$status = 0;
	pcntl_waitpid( $pid, $status );
	return pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status );
}

if ( ! function_exists( 'pcntl_fork' ) ) {
	echo "FAIL pcntl is required to exercise redirecting time-clock handlers\n";
	exit( 1 );
}

echo "=== DoughBoss MariaDB staff-clock acceptance ===\n";

$shifts        = $wpdb->prefix . 'doughboss_staff_shifts';
$events        = $wpdb->prefix . 'doughboss_staff_shift_events';
$breaks        = $wpdb->prefix . 'doughboss_staff_breaks';
$badges        = $wpdb->prefix . 'doughboss_staff_badges';
$locations     = $wpdb->prefix . 'doughboss_locations';
$audit_fail_trigger = $wpdb->prefix . 'doughboss_staff_audit_fail';

// Build and migrate the real DB 1.21 contract. A failed InnoDB readiness check
// must stop the version checkpoint and leave an operator-visible explanation.
DoughBoss_Activator::create_tables();
timeclock_db_ok( '2.37.0' === DOUGHBOSS_VERSION && '1.21.0' === DOUGHBOSS_DB_VERSION, 'test is running against plugin 2.37.0 and DB contract 1.21.0' );
timeclock_db_ok( DoughBoss_Activator::timeclock_storage_ready(), 'fresh staff shifts and audit tables satisfy the exact readiness contract' );

timeclock_db_sql( "ALTER TABLE {$events} ENGINE=MyISAM" );
update_option( 'doughboss_db_version', '1.19.0' );
delete_option( 'doughboss_migration_lock' );
delete_option( 'doughboss_migration_error' );
DoughBoss_Migrations::run();
timeclock_db_ok( '1.19.0' === get_option( 'doughboss_db_version' ), 'non-transactional audit storage blocks the DB 1.20 checkpoint' );
timeclock_db_ok( false !== strpos( (string) get_option( 'doughboss_migration_error' ), 'Staff attendance tables' ), 'failed readiness records an operator-visible migration error' );

timeclock_db_sql( "ALTER TABLE {$events} ENGINE=InnoDB" );
delete_option( 'doughboss_migration_lock' );
delete_option( 'doughboss_migration_error' );
DoughBoss_Migrations::run();
timeclock_db_ok( '1.21.0' === get_option( 'doughboss_db_version' ) && DoughBoss_Activator::timeclock_storage_ready(), 'repaired InnoDB storage advances through DB 1.21' );

// Once a legacy row receives its immutable shop/timezone evidence, a failed
// later migration retry must not rewrite that history from mutable shop data.
$snapshot_user = 987654321;
$wpdb->insert(
	$locations,
	array(
		'name'       => 'Immutable Snapshot Shop',
		'slug'       => 'db-timeclock-snapshot-shop',
		'timezone'   => 'Australia/Sydney',
		'is_active'  => 1,
		'sort_order' => 999,
	),
	array( '%s', '%s', '%s', '%d', '%d' )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$snapshot_location = (int) $wpdb->insert_id;
timeclock_db_sql(
	$wpdb->prepare(
		"INSERT INTO {$shifts} (user_id, staff_name, staff_login, location_id, location_name, timezone_snapshot, clock_in_utc, clock_out_utc, open_guard, source, created_at, updated_at)
		VALUES (%d, '', '', %d, '', 'Australia/Sydney', UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, 'legacy', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
		$snapshot_user,
		$snapshot_location
	)
);
update_option( 'doughboss_db_version', '1.19.0' );
delete_option( 'doughboss_migration_lock' );
DoughBoss_Migrations::run();
$snapshot_once = $wpdb->get_row( $wpdb->prepare( "SELECT id, location_name, timezone_snapshot FROM {$shifts} WHERE user_id = %d ORDER BY id DESC LIMIT 1", $snapshot_user ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
timeclock_db_sql( $wpdb->prepare( "UPDATE {$locations} SET name = %s, timezone = %s WHERE id = %d", 'Changed Current Shop', 'Pacific/Auckland', $snapshot_location ) );
update_option( 'doughboss_db_version', '1.19.0' );
delete_option( 'doughboss_migration_lock' );
DoughBoss_Migrations::run();
$snapshot_retry = $wpdb->get_row( $wpdb->prepare( "SELECT location_name, timezone_snapshot FROM {$shifts} WHERE id = %d", $snapshot_once->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
timeclock_db_ok(
	$snapshot_once->location_name === $snapshot_retry->location_name && $snapshot_once->timezone_snapshot === $snapshot_retry->timezone_snapshot,
	'migration retry never rewrites an established location or timezone snapshot'
);
timeclock_db_sql( $wpdb->prepare( "DELETE FROM {$shifts} WHERE user_id = %d", $snapshot_user ) );
timeclock_db_sql( $wpdb->prepare( "DELETE FROM {$locations} WHERE id = %d", $snapshot_location ) );

$counts_before = array(
	'shifts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$shifts}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	'events' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
);
DoughBoss_Migrations::run();
$counts_after = array(
	'shifts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$shifts}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	'events' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
);
timeclock_db_ok( $counts_before === $counts_after, 'migration rerun is idempotent' );

// A same-named non-unique index is not the one-open-shift contract.
timeclock_db_sql( "ALTER TABLE {$shifts} DROP INDEX user_open_guard, ADD KEY user_open_guard (user_id,open_guard)" );
timeclock_db_ok( ! DoughBoss_Activator::timeclock_storage_ready(), 'wrong uniqueness under the expected guard index name fails readiness' );
timeclock_db_sql( "ALTER TABLE {$shifts} DROP INDEX user_open_guard, ADD UNIQUE KEY user_open_guard (user_id,open_guard)" );
timeclock_db_ok( DoughBoss_Activator::timeclock_storage_ready(), 'exact unique open-shift guard restores readiness' );

// Use stable fixture identities so an interrupted run can be safely repeated in
// the same ephemeral WordPress database.
$staff_login   = 'db_timeclock_acceptance_staff';
$manager_login = 'db_timeclock_acceptance_manager';
$staff         = get_user_by( 'login', $staff_login );
$manager       = get_user_by( 'login', $manager_login );
$staff_id      = $staff ? (int) $staff->ID : (int) wp_insert_user(
	array(
		'user_login'   => $staff_login,
		'user_pass'    => wp_generate_password( 32, true, true ),
		'user_email'   => 'db-timeclock-staff@example.invalid',
		'display_name' => 'Acceptance Staff Original',
		'role'         => 'doughboss_staff',
	)
);
$manager_id = $manager ? (int) $manager->ID : (int) wp_insert_user(
	array(
		'user_login'   => $manager_login,
		'user_pass'    => wp_generate_password( 32, true, true ),
		'user_email'   => 'db-timeclock-manager@example.invalid',
		'display_name' => 'Acceptance Manager',
		'role'         => 'doughboss_manager',
	)
);
$staff_user = new WP_User( $staff_id );
$staff_user->set_role( 'doughboss_staff' );
$manager_user = new WP_User( $manager_id );
$manager_user->set_role( 'doughboss_manager' );
wp_update_user( array( 'ID' => $staff_id, 'display_name' => 'Acceptance Staff Original' ) );

// Remove only prior rows owned by this named acceptance fixture.
$wpdb->query( $wpdb->prepare( "DELETE b FROM {$breaks} b INNER JOIN {$shifts} s ON s.id = b.shift_id WHERE s.user_id = %d", $staff_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( $wpdb->prepare( "DELETE e FROM {$events} e INNER JOIN {$shifts} s ON s.id = e.shift_id WHERE s.user_id = %d", $staff_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( $wpdb->prepare( "DELETE FROM {$shifts} WHERE user_id = %d", $staff_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( $wpdb->prepare( "DELETE FROM {$badges} WHERE user_id = %d", $staff_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$fixture_slugs = array( 'db-clock-acceptance-one', 'db-clock-acceptance-two', 'db-clock-acceptance-three' );
$wpdb->query( "DELETE FROM {$locations} WHERE slug IN ('db-clock-acceptance-one','db-clock-acceptance-two','db-clock-acceptance-three')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
delete_user_meta( $staff_id, DoughBoss_Staff_Scope::LOCATION_META );

$location_ids = array();
foreach ( $fixture_slugs as $index => $slug ) {
	$wpdb->insert(
		$locations,
		array(
			'name'       => 'Acceptance Shop ' . ( $index + 1 ),
			'slug'       => $slug,
			'timezone'   => 'Australia/Sydney',
			'is_active'  => 1,
			'sort_order' => 900 + $index,
		),
		array( '%s', '%s', '%s', '%d', '%d' )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$location_ids[] = (int) $wpdb->insert_id;
}
$location_id = $location_ids[0];

// The public assignment seam fails closed across multiple active stores, then
// accepts only a real active assignment. The private handler resolver is
// invoked to prove a clock-only employee cannot forge a different POSTed shop.
$unassigned = DoughBoss_Staff_Scope::assigned_location_id( $staff_id );
timeclock_db_ok( is_wp_error( $unassigned ) && 'doughboss_staff_location_required' === $unassigned->get_error_code(), 'unassigned multi-shop staff fail closed' );
update_user_meta( $staff_id, DoughBoss_Staff_Scope::LOCATION_META, $location_id );
timeclock_db_ok( $location_id === DoughBoss_Staff_Scope::assigned_location_id( $staff_id ), 'assigned active shop is authoritative' );
$wpdb->update( $locations, array( 'is_active' => 0 ), array( 'id' => $location_id ), array( '%d' ), array( '%d' ) );
$inactive = DoughBoss_Staff_Scope::assigned_location_id( $staff_id );
timeclock_db_ok( is_wp_error( $inactive ) && 'doughboss_staff_location_required' === $inactive->get_error_code(), 'inactive assignment fails closed while other active shops exist' );
$wpdb->update( $locations, array( 'is_active' => 1 ), array( 'id' => $location_id ), array( '%d' ), array( '%d' ) );

wp_set_current_user( $staff_id );
$_POST = array( 'location_id' => $location_ids[1] );
$resolver = new ReflectionMethod( DoughBoss_Timeclock::class, 'resolve_clock_in_location' );
$resolver->setAccessible( true );
$resolved = $resolver->invoke( new DoughBoss_Timeclock() );
timeclock_db_ok( $resolved && $location_id === (int) $resolved->id, 'clock-in ignores a forged location and resolves the employee assignment' );
$_POST = array();

// WordPress must stop both an unprivileged account and a bad CSRF token before
// either reaches a database mutation. These exercise the real handler boundary.
$subscriber = get_user_by( 'login', 'db_timeclock_acceptance_subscriber' );
$subscriber_id = $subscriber ? (int) $subscriber->ID : wp_insert_user(
	array(
		'user_login' => 'db_timeclock_acceptance_subscriber',
		'user_pass'  => wp_generate_password( 32, true, true ),
		'user_email' => 'db-timeclock-subscriber@example.invalid',
		'role'       => 'subscriber',
	)
);
$subscriber_id = is_wp_error( $subscriber_id ) ? 0 : (int) $subscriber_id;
$denied_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$shifts}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$denied_file = sys_get_temp_dir() . '/doughboss-timeclock-denied-' . getmypid();
$denied_pid  = timeclock_fork_denied_handler( 'handle_clock_in', $subscriber_id, array( 'action' => 'doughboss_clock_in', 'location_id' => $location_id ), $denied_file, 'doughboss_clock_in' );
timeclock_wait_handler( $denied_pid );
timeclock_db_ok( file_exists( $denied_file ) && $denied_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$shifts}" ), 'account without staff-clock capability is denied before mutation' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
@unlink( $denied_file );
$nonce_file = sys_get_temp_dir() . '/doughboss-timeclock-nonce-' . getmypid();
$nonce_pid  = timeclock_fork_denied_handler( 'handle_clock_in', $staff_id, array( 'action' => 'doughboss_clock_in', 'location_id' => $location_id ), $nonce_file );
timeclock_wait_handler( $nonce_pid );
timeclock_db_ok( file_exists( $nonce_file ) && $denied_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$shifts}" ), 'invalid clock-in nonce is denied before mutation' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
@unlink( $nonce_file );

// Two real clock-in handlers contend at once. The database lock serializes the
// domain transition, and the unique nullable guard independently rejects a
// second open record.
$roster_now   = new DateTimeImmutable( 'now', new DateTimeZone( 'Australia/Sydney' ) );
$roster_start = $roster_now->modify( '-30 minutes' );
update_user_meta(
	$staff_id,
	DoughBoss_Staff_Scope::ROSTER_META,
	array( $roster_now->format( 'w' ) => array( 'start' => $roster_start->format( 'H:i' ), 'grace' => 5 ) )
);
$base = sys_get_temp_dir() . '/doughboss-timeclock-acceptance-' . getmypid();
$gate = $base . '.go';
$pids = array(
	timeclock_fork_handler( 'handle_clock_in', $staff_id, 'doughboss_clock_in', array( 'action' => 'doughboss_clock_in', 'location_id' => $location_ids[1] ), $gate, $base . '.ready.1' ),
	timeclock_fork_handler( 'handle_clock_in', $staff_id, 'doughboss_clock_in', array( 'action' => 'doughboss_clock_in', 'location_id' => $location_ids[2] ), $gate, $base . '.ready.2' ),
);
$deadline = microtime( true ) + 10;
while ( ( ! file_exists( $base . '.ready.1' ) || ! file_exists( $base . '.ready.2' ) ) && microtime( true ) < $deadline ) {
	usleep( 10000 );
}
timeclock_db_ok( file_exists( $base . '.ready.1' ) && file_exists( $base . '.ready.2' ), 'both clock-in contenders reached the release gate' );
file_put_contents( $gate, 'go' );
$child_results = array( timeclock_wait_handler( $pids[0] ), timeclock_wait_handler( $pids[1] ) );
$children_ok   = ! in_array( false, $child_results, true );
timeclock_db_ok( $children_ok, 'both real clock-in handlers completed normally' );
$open_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$shifts} WHERE user_id = %d AND clock_out_utc IS NULL AND open_guard = 1", $staff_id ) );
$first_shift = DoughBoss_Timeclock::open_shift( $staff_id );
timeclock_db_ok( 1 === $open_count && $first_shift, 'concurrent clock-in creates exactly one open shift' );
timeclock_db_ok( $first_shift && $location_id === (int) $first_shift->location_id, 'created shift is bound to the assigned shop, not either forged POST value' );
timeclock_db_ok( $first_shift && $roster_start->format( 'H:i' ) === $first_shift->scheduled_start_local && 5 === (int) $first_shift->late_grace_minutes && (int) $first_shift->late_minutes >= 24 && (int) $first_shift->late_minutes <= 26, 'clock-in snapshots roster start, grace and calculated late minutes' );

// Recorded breaks are the only deduction. The unique nullable guard prevents
// a duplicate open break, and clock-out fails closed until the break ends.
timeclock_db_sql( $wpdb->prepare( "UPDATE {$shifts} SET clock_in_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE) WHERE id = %d", (int) $first_shift->id ) );
$first_shift = DoughBoss_Timeclock::open_shift( $staff_id );
$start_break = new ReflectionMethod( DoughBoss_Staff_Badge::class, 'start_break' );
$start_break->setAccessible( true );
$end_break = new ReflectionMethod( DoughBoss_Staff_Badge::class, 'end_break' );
$end_break->setAccessible( true );
timeclock_db_ok( 'break-start' === $start_break->invoke( null, $first_shift, $staff_id ), 'staff can start one recorded break' );
timeclock_db_ok( 'already-break' === $start_break->invoke( null, $first_shift, $staff_id ), 'a duplicate open break is rejected' );
timeclock_db_sql( $wpdb->prepare( "UPDATE {$breaks} SET break_start_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE shift_id = %d AND open_guard = 1", (int) $first_shift->id ) );
timeclock_db_ok( 'break-open' === DoughBoss_Timeclock::clock_out_for_user( $staff_id ), 'clock-out is rejected while a recorded break is open' );
timeclock_db_ok( 'break-end' === $end_break->invoke( null, $first_shift, $staff_id ), 'staff can end the recorded break' );
$break_minutes = DoughBoss_Staff_Badge::break_minutes_for_shift( $first_shift );
$worked_minutes = DoughBoss_Timeclock::worked_minutes( $first_shift );
timeclock_db_ok( $break_minutes >= 9 && $break_minutes <= 11 && $worked_minutes >= 9 && $worked_minutes <= 11, 'worked time subtracts the actual recorded break and no assumed break' );

$wpdb->suppress_errors( true );
$duplicate = $wpdb->insert(
	$shifts,
	array(
		'user_id'           => $staff_id,
		'staff_name'        => 'Duplicate',
		'staff_login'       => $staff_login,
		'location_id'       => $location_id,
		'location_name'     => 'Duplicate',
		'timezone_snapshot' => 'Australia/Sydney',
		'clock_in_utc'       => current_time( 'mysql', true ),
		'open_guard'        => 1,
		'source'            => 'acceptance_duplicate',
	),
	array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$duplicate_error = (string) $wpdb->last_error;
$wpdb->suppress_errors( false );
timeclock_db_ok( false === $duplicate && '' !== $duplicate_error, 'MariaDB rejects a direct duplicate open shift even outside the handler lock' );
$wpdb->delete( $shifts, array( 'user_id' => $staff_id, 'source' => 'acceptance_duplicate' ), array( '%d', '%s' ) ); // Cleanup only if a broken guard allowed it.

$first_snapshot = $wpdb->get_row( $wpdb->prepare( "SELECT staff_name,staff_login,location_id,location_name,timezone_snapshot,clock_in_utc FROM {$shifts} WHERE id = %d", (int) $first_shift->id ), ARRAY_A );
$clock_out_pid = timeclock_fork_handler( 'handle_clock_out', $staff_id, 'doughboss_clock_out', array( 'action' => 'doughboss_clock_out' ) );
timeclock_db_ok( timeclock_wait_handler( $clock_out_pid ), 'real clock-out handler completed normally' );
$closed_first = $wpdb->get_row( $wpdb->prepare( "SELECT clock_out_utc,open_guard FROM {$shifts} WHERE id = %d", (int) $first_shift->id ) );
timeclock_db_ok( $closed_first && $closed_first->clock_out_utc && null === $closed_first->open_guard && ! DoughBoss_Timeclock::open_shift( $staff_id ), 'clock-out timestamps the shift and clears open_guard' );

// Directory/shop changes affect the next shift only; the completed attendance
// evidence remains the original immutable snapshot.
wp_update_user( array( 'ID' => $staff_id, 'display_name' => 'Acceptance Staff Renamed' ) );
$wpdb->update(
	$locations,
	array( 'name' => 'Acceptance Shop Renamed', 'timezone' => 'UTC' ),
	array( 'id' => $location_id ),
	array( '%s', '%s' ),
	array( '%d' )
);
$second_in_pid = timeclock_fork_handler( 'handle_clock_in', $staff_id, 'doughboss_clock_in', array( 'action' => 'doughboss_clock_in', 'location_id' => $location_ids[1] ) );
timeclock_db_ok( timeclock_wait_handler( $second_in_pid ), 'a subsequent real clock-in handler completes normally' );
$second_shift = DoughBoss_Timeclock::open_shift( $staff_id );
$first_after_directory_change = $wpdb->get_row( $wpdb->prepare( "SELECT staff_name,staff_login,location_id,location_name,timezone_snapshot,clock_in_utc FROM {$shifts} WHERE id = %d", (int) $first_shift->id ), ARRAY_A );
timeclock_db_ok( $second_shift && (int) $second_shift->id !== (int) $first_shift->id, 'cleared guard permits a new shift after clock-out' );
timeclock_db_ok( $first_snapshot === $first_after_directory_change, 'completed shift snapshots are unchanged by later staff and shop edits' );
timeclock_db_ok( $second_shift && 'Acceptance Staff Renamed' === $second_shift->staff_name && 'Acceptance Shop Renamed' === $second_shift->location_name && 'UTC' === $second_shift->timezone_snapshot, 'new shift captures fresh immutable staff, shop and timezone snapshots' );

// A manager closure must finish a recorded break in the same audited
// transaction. If the audit write fails, both the close and break finish roll
// back together rather than changing payroll evidence halfway through.
timeclock_db_ok( 'break-start' === $start_break->invoke( null, $second_shift, $staff_id ), 'a manager correction can safely close an active recorded break' );
$second_open_break = DoughBoss_Staff_Badge::open_break( (int) $second_shift->id );
timeclock_db_ok( $second_open_break && (int) $second_open_break->shift_id === (int) $second_shift->id, 'second shift has exactly one active break before correction' );

// Force the audit INSERT to fail after the manager UPDATE while the table
// remains structurally ready. The production transaction must roll back the
// close, then succeed atomically once the temporary failure is removed.
timeclock_db_sql( "DROP TRIGGER IF EXISTS {$audit_fail_trigger}" );
timeclock_db_sql( "CREATE TRIGGER {$audit_fail_trigger} BEFORE INSERT ON {$events} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Acceptance audit insert failure'" );
$reason = 'Acceptance test: employee forgot to clock out';
$failed_correction_pid = timeclock_fork_handler(
	'handle_correction',
	$manager_id,
	'doughboss_correct_shift_' . (int) $second_shift->id,
	array( 'action' => 'doughboss_correct_shift', 'shift_id' => (int) $second_shift->id, 'reason' => $reason )
);
timeclock_db_ok( timeclock_wait_handler( $failed_correction_pid ), 'manager handler returns safely when audit persistence fails' );
timeclock_db_sql( "DROP TRIGGER IF EXISTS {$audit_fail_trigger}" );
$after_failed_correction = DoughBoss_Timeclock::open_shift( $staff_id );
timeclock_db_ok( $after_failed_correction && (int) $second_shift->id === (int) $after_failed_correction->id, 'audit database error rolls back the manager close' );
timeclock_db_ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE shift_id = %d", (int) $second_shift->id ) ), 'failed manager transaction leaves no partial audit event' );
timeclock_db_ok( DoughBoss_Staff_Badge::open_break( (int) $second_shift->id ), 'audit failure also rolls back the attempted break finish' );

$correction_pid = timeclock_fork_handler(
	'handle_correction',
	$manager_id,
	'doughboss_correct_shift_' . (int) $second_shift->id,
	array( 'action' => 'doughboss_correct_shift', 'shift_id' => (int) $second_shift->id, 'reason' => $reason )
);
timeclock_db_ok( timeclock_wait_handler( $correction_pid ), 'audited manager-close handler completed normally' );
$corrected = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$shifts} WHERE id = %d", (int) $second_shift->id ) );
$event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$events} WHERE shift_id = %d ORDER BY id DESC LIMIT 1", (int) $second_shift->id ) );
$before_json = $event ? json_decode( (string) $event->before_json, true ) : array();
$after_json  = $event ? json_decode( (string) $event->after_json, true ) : array();
timeclock_db_ok( $corrected && $corrected->clock_out_utc && null === $corrected->open_guard && ! DoughBoss_Timeclock::open_shift( $staff_id ), 'manager close and guard clear commit together' );
timeclock_db_ok( $event && 'manager_closed' === $event->event_type && $manager_id === (int) $event->actor_user_id && $reason === $event->reason, 'manager close records actor, reason and event type' );
timeclock_db_ok( isset( $before_json['open_guard'], $before_json['location_name'], $after_json['location_name'] ) && 1 === (int) $before_json['open_guard'] && empty( $before_json['clock_out_utc'] ) && empty( $after_json['open_guard'] ) && ! empty( $after_json['clock_out_utc'] ) && $before_json['location_name'] === $after_json['location_name'], 'audit before/after evidence describes only the close transition and preserves snapshots' );
timeclock_db_ok( ! DoughBoss_Staff_Badge::open_break( (int) $second_shift->id ), 'successful manager correction finishes the open break with the shift' );

foreach ( glob( $base . '.*' ) as $file ) {
	unlink( $file );
}
wp_set_current_user( 0 );

$passed = (int) $GLOBALS['doughboss_timeclock_passed'];
$failed = (int) $GLOBALS['doughboss_timeclock_failed'];
echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
