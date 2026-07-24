<?php
/**
 * Contract and projection checks for after-hours Revesby pre-order requests.
 *
 * The integration path needs a live WordPress/MariaDB environment, but these
 * dependency-light checks protect the safety boundaries that must never drift:
 * no card gateway, no capacity allocation, explicit customer wording, staff
 * contact confirmation, and no early printer/POSPal side effect.
 *
 * @package DoughBossTests
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-order.php';

$root  = dirname( __DIR__ );
$files = array(
	'order'      => file_get_contents( $root . '/includes/class-doughboss-order.php' ),
	'rest'       => file_get_contents( $root . '/includes/class-doughboss-rest-controller.php' ),
	'settings'   => file_get_contents( $root . '/includes/class-doughboss-settings.php' ),
	'javascript' => file_get_contents( $root . '/public/js/doughboss.js' ),
	'board'      => file_get_contents( $root . '/public/js/doughboss-orderboard.js' ),
	'admin'      => file_get_contents( $root . '/admin/class-doughboss-admin.php' ),
	'pospal'     => file_get_contents( $root . '/includes/class-doughboss-pospal-orders.php' ),
	'printer'    => file_get_contents( $root . '/includes/class-doughboss-printer.php' ),
);
$passed = 0;
$failed = 0;

function preorder_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		++$passed;
		echo "  ok   {$label}\n";
		return;
	}
	++$failed;
	echo "  FAIL {$label}\n";
}

function preorder_method( $source, $name ) {
	$start = strpos( $source, 'function ' . $name . '(' );
	if ( false === $start ) {
		return '';
	}
	$next = strpos( $source, "\n\t/**", $start + 1 );
	return false === $next ? substr( $source, $start ) : substr( $source, $start, $next - $start );
}

echo "=== DoughBoss after-hours pre-order contract ===\n";

$pending = (object) array( 'order_source' => 'preorder_request', 'status' => 'pending', 'order_type' => 'pickup' );
$projection = DoughBoss_Order::customer_projection( $pending );
$timing     = DoughBoss_Order::timing_projection( $pending );
preorder_ok( DoughBoss_Order::is_preorder_request( $pending ), 'pending request is recognisable as a pre-order request' );
preorder_ok( 'preorder_pending_review' === $projection['status'] && false !== strpos( $projection['label'], 'not confirmed or paid' ), 'customer tracking is explicitly unconfirmed and unpaid' );
preorder_ok( 'preorder_pending_review' === $timing['status'], 'timing projection keeps a request out of ordinary kitchen timing' );

$request_method = preorder_method( $files['rest'], 'preorder_request' );
preorder_ok( false !== strpos( $files['rest'], "'/preorder-request'" ), 'nonce-protected customer request route is registered' );
preorder_ok( false === strpos( $request_method, 'DoughBoss_Payment::ready' ) && false === strpos( $request_method, 'verify_payment(' ) && false === strpos( $request_method, "'/payment-intent'" ), 'request path cannot initialise or verify a card payment' );
preorder_ok( false !== strpos( $request_method, "'payment_status'    => 'unpaid'") && false !== strpos( $request_method, "'payment_method'    => ''" ), 'request persistence is explicitly unpaid and has no payment method' );
preorder_ok( false !== strpos( $files['order'], 'doughboss_preorder_payment_forbidden' ) && false !== strpos( $files['order'], 'capacity_hold_token' ), 'model rejects payment references and capacity holds for a pre-order request' );
preorder_ok( false !== strpos( $files['order'], "'order_source'] = 'web'") && false !== strpos( $files['order'], "'confirmed' === \$status" ), 'only staff confirmation promotes the request to the normal order channel' );
preorder_ok( false !== strpos( $files['rest'], 'doughboss_preorder_contact_required' ) && false !== strpos( $files['rest'], 'contact_confirmed' ), 'staff acceptance requires phone/timing confirmation' );
preorder_ok( false !== strpos( $files['rest'], 'doughboss_preorder_decision_required' ), 'generic staff status actions cannot bypass the pre-order review gate' );
preorder_ok( false !== strpos( $files['rest'], "'/admin/preorder-requests'") && false !== strpos( $files['rest'], "'/admin/preorder/(?P<id>\\d+)/decision'" ), 'staff review queue and decision routes exist' );
preorder_ok( false !== strpos( $files['order'], "order_source <> 'preorder_request'") && false !== strpos( $files['order'], 'preorder_requests( $limit = 100' ), 'unreviewed requests stay out of the live KDS feed and have a dedicated queue' );
preorder_ok( false !== strpos( $files['pospal'], 'push deferred for unpaid pre-order request' ), 'POSPal push is deferred before staff review' );
preorder_ok( false !== strpos( $files['printer'], "'preorder_request' ===") && false !== strpos( $files['rest'], 'kitchen_ticket_required' ), 'printer bypass and manual-ticket obligation are explicit' );
preorder_ok( false !== strpos( $files['settings'], 'after_hours_preorders_enabled' ) && false !== strpos( $files['settings'], 'after_hours_preorders_message' ), 'owner-controlled request gate and customer copy are configurable' );
preorder_ok( false !== strpos( $files['javascript'], "request('/preorder-request'") && false !== strpos( $files['javascript'], 'No payment has been taken.' ), 'WordPress storefront calls the separate request route with explicit unpaid copy' );
preorder_ok( false !== strpos( $files['admin'], 'id="db-preorder-review"' ) && false !== strpos( $files['board'], "'/admin/preorder-requests?per_page=100'") && false !== strpos( $files['board'], "'/decision'") , 'KDS has a dedicated morning review panel wired to the review APIs' );
preorder_ok( false !== strpos( $files['board'], 'I called the customer and agreed pickup timing.') && false !== strpos( $files['board'], 'POSPal is deferred') && false !== strpos( $files['board'], 'manual kitchen ticket' ), 'staff UI requires contact acknowledgement and exposes the unpaid/POS/manual-ticket obligations' );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
