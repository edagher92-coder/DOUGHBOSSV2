<?php
/**
 * Static guardrails for the launch demo.
 *
 * The public customer demo may link to Uber Eats, but Dough Boss's direct
 * ordering and staff workflow must stay Revesby pickup-only for launch.
 */

$root = dirname(__DIR__);
$customer = file_get_contents($root . '/demo/index.html');
$staff = file_get_contents($root . '/demo/backend.html');
$owner = file_get_contents($root . '/demo/owner.html');
$ordering = file_get_contents($root . '/demo/menu-order.js');
$offers = file_get_contents($root . '/demo/offers.js');
$pages = file_get_contents($root . '/.github/workflows/pages.yml');

if ($customer === false || $staff === false || $owner === false || $ordering === false || $offers === false || $pages === false) {
	fwrite(STDERR, "FAIL: Could not read the demo files.\n");
	exit(1);
}

$checks = array(
	'customer copy names Revesby pickup' => strpos($customer, 'Pickup from Revesby') !== false,
	'customer primary navigation uses Offers and News' => strpos($customer, 'href="#offers">Offers &amp; News</a>') !== false,
	'customer primary navigation does not expose Snow Boss' => strpos($customer, '>Snow Boss</a>') === false,
	'staff location is Revesby pickup-only' => strpos($staff, 'Revesby · pickup only') !== false,
	'staff launch workflow ends at collected' => strpos($staff, 'Collected · done') !== false,
	'staff fixtures do not contain Bankstown' => stripos($staff, 'Bankstown') === false,
	'staff fixtures do not contain Roselands' => stripos($staff, 'Roselands') === false,
	'staff fixtures do not contain direct delivery orders' => !preg_match('/type:\s*[\'\"]Delivery[\'\"]/', $staff),
	'staff workflow does not contain delivery status' => stripos($staff, 'out_for_delivery') === false,
	'staff fixtures do not present Snow Boss' => stripos($staff, 'Snow Boss') === false,
	'owner location is Revesby pickup-only' => strpos($owner, 'Revesby · pickup only') !== false,
	'owner fixtures do not contain Bankstown' => stripos($owner, 'Bankstown') === false,
	'owner fixtures do not contain Roselands' => stripos($owner, 'Roselands') === false,
	'owner fixtures do not contain direct delivery orders' => !preg_match('/type:\s*[\'\"]Delivery[\'\"]/', $owner),
	'owner fixtures do not present Snow Boss' => stripos($owner, 'Snow Boss') === false,
	'ordering confirms no payment or real order' => strpos($ordering, 'No payment was taken and no real order was sent.') !== false,
	'ordering makes no network request' => stripos($ordering, 'fetch(') === false && stripos($ordering, 'XMLHttpRequest') === false,
	'offers form makes no network request' => stripos($offers, 'fetch(') === false && stripos($offers, 'XMLHttpRequest') === false,
	'offers form does not submit to an external endpoint' => strpos($customer, 'id="sb-voucher-form" action="#"') !== false,
	'Pages deploys from the current integration branch' => strpos($pages, 'claude/doughboss-website-design-fixes-li6dqa') !== false,
	'Pages no longer deploys from the obsolete branch' => strpos($pages, 'claude/funny-goodall-gsoog4') === false,
);

$failed = 0;
foreach ($checks as $label => $passed) {
	if ($passed) {
		echo "PASS: {$label}\n";
		continue;
	}

	$failed++;
	fwrite(STDERR, "FAIL: {$label}\n");
}

printf("\nDemo scope: %d passed, %d failed.\n", count($checks) - $failed, $failed);
exit($failed === 0 ? 0 : 1);
