<?php
/**
 * Read-only pickup schedule status tests.
 *
 * @package DoughBoss
 */

require_once dirname( __DIR__ ) . '/includes/class-doughboss-shortcodes.php';

db_test(
	'paused ordering uses truthful defaults without replacing a saved business message',
	function () {
		doughboss_test_set_settings( array() );
		assert_false( DoughBoss_Settings::ordering_open(), 'fresh settings never enable ordering' );
		assert_same( 'Online ordering is paused. Browse the menu and check your preferred shop before visiting.', DoughBoss_Settings::ordering_closed_message(), 'fresh default makes no future launch promise' );
		$shortcodes = new DoughBoss_Shortcodes();
		assert_true( false !== strpos( $shortcodes->ordering_status(), '<strong>Online ordering is paused</strong>' ), 'server-rendered notice matches the theme status' );
		doughboss_test_set_settings( array( 'ordering_open' => 0, 'ordering_closed_message' => ' ' ) );
		assert_same( DoughBoss_Settings::defaults()['ordering_closed_message'], DoughBoss_Settings::ordering_closed_message(), 'empty saved copy uses the same neutral fallback' );
		doughboss_test_set_settings( array( 'ordering_open' => 0, 'ordering_closed_message' => 'Kitchen maintenance until Friday.' ) );
		assert_same( 'Kitchen maintenance until Friday.', DoughBoss_Settings::ordering_closed_message(), 'custom business copy is preserved' );
		assert_true( false !== strpos( $shortcodes->ordering_status(), 'Kitchen maintenance until Friday.' ), 'custom copy remains in the server-rendered notice' );
		doughboss_test_set_settings( array( 'ordering_open' => 1 ) );
		assert_same( '', $shortcodes->ordering_status(), 'open ordering has no paused notice' );
	}
);

class DoughBoss_Test_Store_Hours_DB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $hours = array();
	public $blackouts = array();
	public $reads = 0;
	public $writes = 0;
	public $fail_reads = false;
	public $fail_exception_reads = false;

	public function prepare( $query ) {
		$args = func_get_args();
		array_shift( $args );
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'", $query, 1 );
		}
		return $query;
	}

	public function get_results( $query ) {
		++$this->reads;
		if ( false === stripos( $query, 'SELECT' ) ) {
			++$this->writes;
		}
		if ( $this->fail_reads ) {
			$this->last_error = 'test query failure';
			return null;
		}
		return $this->hours;
	}

	public function get_col( $query ) {
		++$this->reads;
		if ( false === stripos( $query, 'SELECT' ) ) {
			++$this->writes;
		}
		if ( $this->fail_reads || $this->fail_exception_reads ) {
			$this->last_error = 'test query failure';
			return null;
		}
		return $this->blackouts;
	}
}

$GLOBALS['wpdb'] = new DoughBoss_Test_Store_Hours_DB();
require_once dirname( __DIR__ ) . '/includes/class-doughboss-locations.php';

/** @return object */
function db_store_hours_location( array $overrides = array() ) {
	return (object) array_merge(
		array(
			'id'                  => 7,
			'name'                => 'Test Shop',
			'slug'                => 'test-shop',
			'suburb'              => '',
			'address'             => '',
			'phone'               => '',
			'pickup_enabled'      => 1,
			'delivery_enabled'    => 0,
			'prep_time_default'   => 20,
			'timezone'            => 'Australia/Sydney',
			'booking_horizon_days'=> 8,
			'capacity_mode'       => 'off',
		),
		$overrides
	);
}

/** @return object */
function db_store_hours_row( $weekday, $opens_at, $closes_at ) {
	return (object) array( 'weekday' => $weekday, 'opens_at' => $opens_at, 'closes_at' => $closes_at );
}

/** @return array */
function db_pickup_status( $location, $clock, array $hours = array(), array $blackouts = array() ) {
	$db = $GLOBALS['wpdb'];
	$db->last_error = '';
	$db->hours = $hours;
	$db->blackouts = $blackouts;
	$db->reads = 0;
	$db->writes = 0;
	$db->fail_reads = false;
	$db->fail_exception_reads = false;
	return DoughBoss_Locations::pickup_status( $location, new DateTimeImmutable( $clock, new DateTimeZone( 'UTC' ) ) );
}

db_test(
	'pickup status is open until the configured close boundary and short lived',
	function () {
		$status = db_pickup_status( db_store_hours_location(), '2026-09-07T01:00:00Z', array( db_store_hours_row( 1, '09:00:00', '17:00:00' ) ) );
		assert_same( 'open', $status['state'], 'Monday noon Sydney is open' );
		assert_same( '2026-09-07T07:00:00Z', $status['closes_at_utc'], 'close instant is UTC' );
		assert_same( '2026-09-07T01:01:00Z', $status['expires_at_utc'], 'freshness is bounded to sixty seconds' );
		assert_same( 'Australia/Sydney', $status['timezone'], 'configured timezone is retained' );
		assert_same( 0, $GLOBALS['wpdb']->writes, 'status calculation uses no database writes' );
	}
);

db_test(
	'pickup status expires at the closes-soon transition and includes its boundary',
	function () {
		$hours = array( db_store_hours_row( 1, '09:00:00', '17:00:00' ) );
		$open = db_pickup_status( db_store_hours_location(), '2026-09-07T06:29:00Z', $hours );
		assert_same( 'open', $open['state'], 'more than thirty minutes before close remains open' );
		assert_same( '2026-09-07T06:30:00Z', $open['expires_at_utc'], 'open status expires when it changes to closes soon' );
		$soon = db_pickup_status( db_store_hours_location(), '2026-09-07T06:30:00Z', $hours );
		assert_same( 'closes_soon', $soon['state'], 'thirty minutes before close is closes soon' );
		assert_same( '2026-09-07T06:31:00Z', $soon['expires_at_utc'], 'closes-soon result remains short lived' );
	}
);

db_test(
	'pickup status is close-exclusive and gives the next configured opening',
	function () {
		$status = db_pickup_status( db_store_hours_location(), '2026-09-07T07:00:00Z', array( db_store_hours_row( 1, '09:00:00', '17:00:00' ), db_store_hours_row( 2, '09:00:00', '17:00:00' ) ) );
		assert_same( 'closed', $status['state'], 'the exact close instant is closed' );
		assert_same( '2026-09-07T23:00:00Z', $status['next_open_at_utc'], 'Tuesday opening is supplied' );
		assert_same( 'Tuesday 9:00am', $status['next_open_label'], 'next opening has a local friendly label' );
		assert_same( null, $status['closes_at_utc'], 'closed status has no close instant' );
	}
);

db_test(
	'pickup status reports a daytime split as closed until its next opening',
	function () {
		$status = db_pickup_status( db_store_hours_location(), '2026-09-07T02:30:00Z', array( db_store_hours_row( 1, '09:00:00', '12:00:00' ), db_store_hours_row( 1, '13:00:00', '17:00:00' ) ) );
		assert_same( 'closed', $status['state'], 'midday split is not treated as continuous service' );
		assert_same( '2026-09-07T03:00:00Z', $status['next_open_at_utc'], 'next split segment becomes the next opening' );
		assert_same( '2026-09-07T02:31:00Z', $status['expires_at_utc'], 'split-gap closed result remains short lived' );
		$before_open = db_pickup_status( db_store_hours_location(), '2026-09-06T22:59:30Z', array( db_store_hours_row( 1, '09:00:00', '17:00:00' ) ) );
		assert_same( 'closed', $before_open['state'], 'before the first opening is closed' );
		assert_same( '2026-09-06T23:00:00Z', $before_open['expires_at_utc'], 'imminent opening bounds closed freshness' );
	}
);

db_test(
	'overnight and overlapping hours remain continuously open',
	function () {
		$overnight = db_pickup_status( db_store_hours_location(), '2026-09-06T15:00:00Z', array( db_store_hours_row( 7, '18:00:00', '02:00:00' ) ) );
		assert_same( 'open', $overnight['state'], 'previous-day overnight spill is open' );
		assert_same( '2026-09-06T16:00:00Z', $overnight['closes_at_utc'], 'overnight close is next local day' );
		$overnight_blackout = db_pickup_status( db_store_hours_location(), '2026-09-06T09:00:00Z', array( db_store_hours_row( 7, '18:00:00', '02:00:00' ) ), array( '2026-09-07' ) );
		assert_same( 'closed', $overnight_blackout['state'], 'a blackout on the overnight end date suppresses the spill conservatively' );
		$overlap = db_pickup_status( db_store_hours_location(), '2026-09-07T06:20:00Z', array( db_store_hours_row( 1, '09:00:00', '16:00:00' ), db_store_hours_row( 1, '15:00:00', '17:00:00' ) ) );
		assert_same( 'open', $overlap['state'], 'merged overlap does not falsely close soon at the first segment end' );
		assert_same( '2026-09-07T07:00:00Z', $overlap['closes_at_utc'], 'merged overlap keeps the later close' );
	}
);

db_test(
	'configured blackouts and malformed schedules fail conservatively',
	function () {
		$blackout = db_pickup_status( db_store_hours_location(), '2026-09-07T01:00:00Z', array( db_store_hours_row( 1, '09:00:00', '17:00:00' ), db_store_hours_row( 2, '09:00:00', '17:00:00' ) ), array( '2026-09-07' ) );
		assert_same( 'closed', $blackout['state'], 'an exception blackouts its configured day' );
		assert_same( '2026-09-07T23:00:00Z', $blackout['next_open_at_utc'], 'blackout does not invent an override' );
		$malformed = db_pickup_status( db_store_hours_location(), '2026-09-07T01:00:00Z', array( db_store_hours_row( 1, '9:00:00', '17:00:00' ) ) );
		assert_same( 'unknown', $malformed['state'], 'malformed stored time is not reported as closed' );
		$db = $GLOBALS['wpdb'];
		$db->hours = array( db_store_hours_row( 1, '09:00:00', '17:00:00' ) );
		$db->fail_reads = true;
		$db_error = DoughBoss_Locations::pickup_status( db_store_hours_location(), new DateTimeImmutable( '2026-09-07T01:00:00Z', new DateTimeZone( 'UTC' ) ) );
		assert_same( 'unknown', $db_error['state'], 'schedule storage errors are unknown' );
		$db->last_error = '';
		$db->fail_reads = false;
		$db->fail_exception_reads = true;
		$exception_error = DoughBoss_Locations::pickup_status( db_store_hours_location(), new DateTimeImmutable( '2026-09-07T01:00:00Z', new DateTimeZone( 'UTC' ) ) );
		assert_same( 'unknown', $exception_error['state'], 'exception storage errors are unknown' );
	}
);

db_test(
	'missing hours invalid timezone and DST gaps are unknown while pickup off is unavailable',
	function () {
		$missing = db_pickup_status( db_store_hours_location(), '2026-09-07T01:00:00Z' );
		assert_same( 'unknown', $missing['state'], 'unconfigured hours are unknown' );
		$bad_timezone = db_pickup_status( db_store_hours_location( array( 'timezone' => 'Not/AZone' ) ), '2026-09-07T01:00:00Z' );
		assert_same( 'unknown', $bad_timezone['state'], 'invalid timezone is unknown' );
		$dst_gap = db_pickup_status( db_store_hours_location(), '2026-10-03T15:30:00Z', array( db_store_hours_row( 7, '02:30:00', '04:00:00' ) ) );
		assert_same( 'unknown', $dst_gap['state'], 'Sydney DST gap is not normalised into a false schedule' );
		$dst_repeat = db_pickup_status( db_store_hours_location(), '2026-04-04T14:30:00Z', array( db_store_hours_row( 7, '02:30:00', '04:00:00' ) ) );
		assert_same( 'unknown', $dst_repeat['state'], 'Sydney repeated DST hour is not assigned an arbitrary instant' );
		$unavailable = db_pickup_status( db_store_hours_location( array( 'pickup_enabled' => 0 ) ), '2026-09-07T01:00:00Z' );
		assert_same( 'unavailable', $unavailable['state'], 'pickup disabled is unavailable' );
		assert_same( 0, $GLOBALS['wpdb']->reads, 'pickup-disabled status does not read schedule storage' );
		assert_same( 0, $GLOBALS['wpdb']->writes, 'status calculation never writes storage' );
		$inactive = db_pickup_status( db_store_hours_location( array( 'is_active' => 0 ) ), '2026-09-07T01:00:00Z' );
		assert_same( 'unavailable', $inactive['state'], 'inactive locations are unavailable for direct callers' );
		assert_same( 0, $GLOBALS['wpdb']->reads, 'inactive status does not read schedule storage' );
	}
);
