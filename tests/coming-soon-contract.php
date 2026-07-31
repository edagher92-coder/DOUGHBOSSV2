<?php
/**
 * Dependency-light contract for the WordPress Coming Soon launch gate.
 *
 * @package DoughBoss
 */

$root   = dirname( __DIR__ );
$files  = array(
	'settings'    => file_get_contents( $root . '/includes/class-doughboss-settings.php' ),
	'activator'   => file_get_contents( $root . '/includes/class-doughboss-activator.php' ),
	'assets'      => file_get_contents( $root . '/includes/class-doughboss-assets.php' ),
	'shortcodes'  => file_get_contents( $root . '/includes/class-doughboss-shortcodes.php' ),
	'rest'        => file_get_contents( $root . '/includes/class-doughboss-rest-controller.php' ),
	'javascript'  => file_get_contents( $root . '/public/js/doughboss.js' ),
);
$passed = 0;
$failed = 0;

function contract_check( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		++$passed;
		echo "  ok   {$label}\n";
		return;
	}
	++$failed;
	echo "  FAIL {$label}\n";
}

echo "=== WordPress Coming Soon contract ===\n";
contract_check( false !== strpos( $files['settings'], "'ordering_open'   => 0" ), 'fresh installs default to ordering closed' );
contract_check( false !== strpos( $files['settings'], "'after_hours_preorders_enabled' => 0" ), 'after-hours request collection is owner opt-in on fresh installs' );
contract_check( false !== strpos( $files['activator'], "'ordering_open'   => 0" ), 'activation seed is browse-only' );
contract_check( false !== strpos( $files['shortcodes'], "add_shortcode( 'doughboss_ordering_status'" ), 'server-rendered status shortcode exists' );
contract_check( false !== strpos( $files['assets'], 'DoughBoss_Settings::ordering_open() && DoughBoss_Payment::ready()' ), 'payment libraries stay unloaded while closed' );
contract_check(
	false !== strpos( $files['javascript'], 'if (!orderingOpen) {' )
	&& false !== strpos( $files['javascript'], 'if (cfg.after_hours_preorders_enabled && !tableContext)' )
	&& false !== strpos( $files['javascript'], 'checkoutEl = preorderRequestForm(' ),
	'browser omits payment checkout while closed and only mounts the opt-in unpaid request form'
);
contract_check( 2 === substr_count( $files['rest'], "new WP_Error( 'doughboss_closed', DoughBoss_Settings::ordering_closed_message()" ), 'payment intent and checkout fail closed with launch copy' );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
