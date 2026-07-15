<?php
/**
 * Deterministic tests for the pure capacity-window calculator.
 *
 * @package DoughBoss\Tests
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-capacity.php';

$passed = 0;
$failed = 0;
function capacity_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) { ++$passed; echo "  ok   {$label}\n"; }
	else { ++$failed; echo "  FAIL {$label}\n"; }
}

function capacity_config() {
	return array(
		'location_id'     => 1,
		'planning_version'=> 1,
		'enabled'         => true,
		'active'          => true,
		'pickup_enabled'  => true,
		'timezone'        => 'Australia/Sydney',
		'slot_minutes'    => 15,
		'notice_minutes'  => 20,
		'horizon_days'    => 1,
		'capacity_units'  => 10,
		'blackout_dates'  => array(),
		'hours'           => array(
			'sun' => array(), 'mon' => array(), 'tue' => array(),
			'wed' => array( array( '11:00', '21:00' ) ),
			'thu' => array(), 'fri' => array(), 'sat' => array(),
		),
	);
}

echo "=== DoughBoss capacity window test ===\n";
$config = capacity_config();
$winter = DoughBoss_Capacity::windows( $config, 2, array(), new DateTimeImmutable( '2026-07-15 00:30:00', new DateTimeZone( 'UTC' ) ) );
capacity_ok( 40 === count( $winter ), 'Wednesday produces 40 fifteen-minute windows' );
capacity_ok( '2026-07-15T01:00:00Z' === $winter[0]['ready_from_utc'], 'notice boundary offers 11:00 Sydney in winter' );
capacity_ok( '2026-07-15T11:00:00Z' === $winter[39]['ready_by_utc'], 'no window extends beyond closing time' );
capacity_ok( '+10:00' === $winter[0]['utc_offset'], 'winter offset is Australia/Sydney +10' );

$config['hours']['wed'] = array();
$config['hours']['thu'] = array( array( '18:00', '18:30' ) );
$config['horizon_days'] = 2;
$summer = DoughBoss_Capacity::windows( $config, 1, array(), new DateTimeImmutable( '2026-01-14 00:00:00', new DateTimeZone( 'UTC' ) ) );
capacity_ok( '2026-01-15T07:00:00Z' === $summer[0]['ready_from_utc'], '18:00 Sydney in summer converts to 07:00Z' );
capacity_ok( '+11:00' === $summer[0]['utc_offset'], 'summer offset is Australia/Sydney +11' );

$config = capacity_config();
$usage  = array( '2026-07-15 01:00:00' => 8, '2026-07-15 01:15:00' => 9 );
$slots  = DoughBoss_Capacity::windows( $config, 2, $usage, new DateTimeImmutable( '2026-07-15 00:30:00Z' ) );
capacity_ok( 'available' === $slots[0]['availability'], 'demand equal to remaining capacity is allowed' );
capacity_ok( 'full' === $slots[1]['availability'], 'demand above remaining capacity is full' );

$config['blackout_dates'] = array( '2026-07-15' );
capacity_ok( array() === DoughBoss_Capacity::windows( $config, 1, array(), new DateTimeImmutable( '2026-07-15 00:00:00Z' ) ), 'blackout date offers no windows' );
$config = capacity_config();
$config['enabled'] = false;
capacity_ok( array() === DoughBoss_Capacity::windows( $config, 1, array(), new DateTimeImmutable( '2026-07-15 00:00:00Z' ) ), 'disabled scheduling fails closed' );
$config = capacity_config();
$config['timezone'] = 'Not/A_Zone';
capacity_ok( array() === DoughBoss_Capacity::windows( $config, 1, array(), new DateTimeImmutable( '2026-07-15 00:00:00Z' ) ), 'invalid IANA zone fails closed' );
$config = capacity_config();
$config['hours']['wed'] = array( array( '11:00', '15:00' ), array( '14:00', '18:00' ) );
capacity_ok( array() === DoughBoss_Capacity::windows( $config, 1, array(), new DateTimeImmutable( '2026-07-15 00:00:00Z' ) ), 'overlapping hours fail closed' );
$config = capacity_config();
capacity_ok( array() === DoughBoss_Capacity::windows( $config, 0, array(), new DateTimeImmutable( '2026-07-15 00:00:00Z' ) ), 'zero demand is rejected' );

$config = capacity_config();
$config['horizon_days'] = 2;
$config['hours'] = array(
	'sun' => array( array( '01:00', '04:00' ) ), 'mon' => array(), 'tue' => array(), 'wed' => array(),
	'thu' => array(), 'fri' => array(), 'sat' => array(),
);
$fallback = DoughBoss_Capacity::windows( $config, 1, array(), new DateTimeImmutable( '2026-04-04 12:00:00Z' ) );
$ids = array_column( $fallback, 'slot_id' );
capacity_ok( count( $ids ) === count( array_unique( $ids ) ), 'DST fallback produces unique opaque slot ids' );
capacity_ok( count( $fallback ) > 12, 'DST fallback preserves the repeated real-time hour' );

$config['hours']['sun'] = array( array( '01:00', '04:00' ) );
$spring = DoughBoss_Capacity::windows( $config, 1, array(), new DateTimeImmutable( '2026-10-03 12:00:00Z' ) );
capacity_ok( 8 === count( $spring ), 'DST spring gap omits the nonexistent local hour' );
capacity_ok( ! in_array( '02:00', array_column( $spring, 'local_from' ), true ), 'no nonexistent 02:xx wall slot is emitted' );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
