<?php
/** Regression test: an empty fresh install receives the Revesby seed row. */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-locations.php';

class DoughBoss_Location_Seed_DB extends DB_Stub {
	public $locations = array();
	public $insert_id = 0;
	public function get_var( $query = null ) {
		if ( false !== strpos( $query, 'COUNT(*)' ) ) { return count( $this->locations ); }
		return null;
	}
	public function insert( $table, $data, $formats = null ) {
		$data['id'] = ++$this->insert_id;
		$this->locations[] = $data;
		return 1;
	}
}

$passed = 0;
$failed = 0;
function location_seed_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) { ++$passed; echo "  ok   {$label}\n"; }
	else { ++$failed; echo "  FAIL {$label}\n"; }
}

echo "=== DoughBoss fresh activation location seed test ===\n";
$db = new DoughBoss_Location_Seed_DB();
$GLOBALS['wpdb'] = $db;
update_option( 'blogname', 'Dough Boss Lebanese Bakery' );
update_option( DoughBoss_Settings::OPTION_KEY, array( 'enable_pickup' => 1, 'enable_delivery' => 0 ) );
DoughBoss_Locations::ensure_default();
location_seed_ok( 1 === count( $db->locations ), 'empty location storage receives exactly one location' );
$seed = $db->locations[0];
location_seed_ok( 'Revesby' === $seed['name'] && 'Revesby' === $seed['suburb'], 'Dough Boss installs seed the canonical Revesby shop' );
location_seed_ok( 0 === strpos( $seed['address'], '12/25 Selems Parade' ) && '(02) 9774 2286' === $seed['phone'], 'seed includes the reviewed address and phone' );
location_seed_ok( 1 === $seed['pickup_enabled'] && 0 === $seed['delivery_enabled'], 'seed inherits the safe pickup-only defaults' );
DoughBoss_Locations::ensure_default();
location_seed_ok( 1 === count( $db->locations ), 'seeding is idempotent and never duplicates a location' );

echo "=== RESULT: {$passed} passed · {$failed} failed ===\n";
exit( $failed ? 1 : 0 );
