<?php
/**
 * Customer notification, secure tracking-link and staff-guide contract.
 *
 * Static assertions complement the behavioural lifecycle suite without
 * contacting a mail server, payment provider, WordPress site or customer.
 *
 * Run: php tests/customer-notification-tracking-contract.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-settings.php';

$root   = dirname( __DIR__ );
$passed = 0;
$failed = 0;

function notification_contract_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "PASS: {$label}\n";
		return;
	}
	$failed++;
	echo "FAIL: {$label}\n";
}

function notification_contract_source( $path ) {
	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		throw new RuntimeException( 'Unable to read ' . $path );
	}
	return $contents;
}

echo "== Customer email and tracking contract ==\n";

notification_contract_ok(
	'' === DoughBoss_Settings::tracking_page_url( 'DB-1001' ),
	'tracking link remains dormant until an owner publishes and configures the page'
);

$settings                      = DoughBoss_Settings::defaults();
$settings['tracking_page_url'] = home_url( '/track-order/' );
DoughBoss_Settings::update( $settings );

$tracking_url = DoughBoss_Settings::tracking_page_url( 'DB-1001' );
notification_contract_ok(
	home_url( '/track-order/?order=DB-1001' ) === $tracking_url,
	'configured same-site tracking page receives only the order number'
);
notification_contract_ok(
	false === strpos( $tracking_url, 'email' ) && false === strpos( $tracking_url, '@' ),
	'tracking URLs never expose the customer email address'
);

$settings['tracking_page_url'] = 'https://external.example/track-order/';
DoughBoss_Settings::update( $settings );
notification_contract_ok(
	'' === DoughBoss_Settings::tracking_page_url( 'DB-1001' ),
	'external tracking URLs are rejected before an order number can be disclosed'
);

$settings['tracking_page_url'] = home_url( '/track-order/' );
DoughBoss_Settings::update( $settings );
notification_contract_ok(
	false !== strpos( DoughBoss_Settings::tracking_instructions( 'DB-1001' ), home_url( '/track-order/?order=DB-1001' ) )
		&& false !== strpos( DoughBoss_Settings::tracking_instructions( 'DB-1001' ), 'same email address' ),
	'email instructions link to tracking and require the matching checkout email'
);

notification_contract_ok(
	false !== strpos( DoughBoss_Settings::tpl_order_email_body(), '{tracking_instructions}' )
		&& false !== strpos( DoughBoss_Settings::tpl_accepted_email_body( true ), '{tracking_instructions}' )
		&& false !== strpos( DoughBoss_Settings::tpl_ready_email_body(), '{tracking_instructions}' ),
	'confirmation, accepted and ready email defaults all include tracking instructions'
);

$rest       = notification_contract_source( $root . '/includes/class-doughboss-rest-controller.php' );
$emails     = notification_contract_source( $root . '/includes/class-doughboss-emails.php' );
$shortcodes = notification_contract_source( $root . '/includes/class-doughboss-shortcodes.php' );
$javascript = notification_contract_source( $root . '/public/js/doughboss.js' );
$admin      = notification_contract_source( $root . '/admin/class-doughboss-admin.php' );
$guide      = notification_contract_source( $root . '/docs/DoughBoss-Staff-Management-Quick-Guide-2026-07-24.html' );

notification_contract_ok(
	false !== strpos( $rest, 'if ( ! $replayed )' )
		&& false !== strpos( $rest, '$this->send_confirmation( $order );' ),
	'checkout sends the immediate confirmation once and suppresses idempotent replays'
);
notification_contract_ok(
	false !== strpos( $rest, "'tracking_url' => DoughBoss_Settings::tracking_page_url" )
		&& false !== strpos( $rest, "'tracking_instructions' => DoughBoss_Settings::tracking_instructions" ),
	'checkout response and confirmation template receive the configured tracking details'
);
notification_contract_ok(
	false !== strpos( $emails, "self::already_sent( \$order_id, 'accepted' )" )
		&& false !== strpos( $emails, "self::already_sent( \$order_id, 'ready' )" )
		&& false !== strpos( $emails, 'self::claim_delivery( $order_id, $stage )' )
		&& false !== strpos( $emails, 'self::mark_sent( $order_id, $stage )' ),
	'accepted and ready milestone emails suppress normal replays and concurrent workers'
);
notification_contract_ok(
	false !== strpos( $shortcodes, 'id="track-order"' )
		&& false !== strpos( $shortcodes, 'name="email"' ),
	'tracking shortcode exposes a stable anchor and requires the checkout email'
);
notification_contract_ok(
	false !== strpos( $javascript, "new URLSearchParams(window.location.search).get('order')" )
		&& false === strpos( $javascript, "params.get('email')" )
		&& false !== strpos( $javascript, "request('/order/track', { method: 'POST', body:" )
		&& false === strpos( $javascript, "'?email='" ),
	'tracking prefills only the order number and submits email in a POST body'
);
notification_contract_ok(
	false !== strpos( $rest, "'/order/track'" )
		&& false !== strpos( $rest, "'Cache-Control', 'no-store, private'" )
		&& false !== strpos( $rest, "'Referrer-Policy', 'no-referrer'" ),
	'tracking endpoint is POST-only and marks success and failure responses private'
);
notification_contract_ok(
	false !== strpos( $admin, '[tracking_page_url]' )
		&& false !== strpos( $admin, '[doughboss_order_tracking]' ),
	'management can configure the published Track My Order page in WordPress'
);
notification_contract_ok(
	false !== strpos( $guide, 'Use email and tracking together' )
		&& false !== strpos( $guide, 'Revesby online-ordering rollout' )
		&& false !== strpos( $guide, 'DB-06-order-tracking.png' )
		&& false !== strpos( $guide, 'DB-08-kitchen-ipad.png' )
		&& false !== strpos( $guide, 'DB-10-dashboard.png' ),
	'illustrated guide documents the decision, rollout scope and marked-up staff screens'
);

echo "\nCustomer notification/tracking: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
