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
$profile = file_get_contents($root . '/demo/config/profiles/doughboss-revesby-launch.js');
$runtime = file_get_contents($root . '/demo/demo-runtime.js');
$fixtures = file_get_contents($root . '/demo/demo-fixtures.js');
$pages = file_get_contents($root . '/.github/workflows/pages.yml');

if ($customer === false || $staff === false || $owner === false || $ordering === false || $offers === false || $profile === false || $runtime === false || $fixtures === false || $pages === false) {
	fwrite(STDERR, "FAIL: Could not read the demo files.\n");
	exit(1);
}

$checks = array(
	'customer copy names Revesby pickup' => strpos($customer, 'Pickup from Revesby') !== false,
	'customer copy names all three operating shops' => strpos($customer, 'Now baking across Revesby, Bankstown and Roselands') !== false,
	'customer copy limits online ordering to Revesby' => strpos($customer, 'online pickup ordering is launching from Revesby only for now') !== false,
	'Bankstown and Roselands remain visit or call only' => substr_count($customer, 'visit or call &middot; online ordering later') === 2,
	'customer primary navigation uses Offers and News' => strpos($customer, 'href="#offers">Offers &amp; News</a>') !== false,
	'customer primary navigation does not expose Snow Boss' => strpos($customer, '>Snow Boss</a>') === false,
	'launch profile is explicitly versioned' => strpos($profile, "profileId: 'doughboss-revesby-launch'") !== false,
	'launch profile defaults to Revesby' => strpos($profile, "defaultLocationId: 'revesby'") !== false,
	'launch profile enables pickup' => preg_match('/pickup:\s*\{\s*enabled:\s*true/', $profile) === 1,
	'launch profile disables direct delivery' => preg_match('/delivery:\s*\{\s*enabled:\s*false/', $profile) === 1,
	'launch profile disables online payments' => preg_match('/payments:\s*\{\s*enabled:\s*false/', $profile) === 1,
	'launch profile selects MPGS and keeps Stripe and Tyro available as fallbacks' => strpos($profile, "allowedProviders: ['mpgs', 'stripe', 'tyro']") !== false && strpos($profile, "selectedProvider: 'mpgs'") !== false,
	'staff consumes the universal runtime' => strpos($staff, 'demo-runtime.js') !== false && strpos($staff, 'demo-fixtures.js') !== false,
	'owner consumes the universal runtime' => strpos($owner, 'demo-runtime.js') !== false && strpos($owner, 'demo-fixtures.js') !== false,
	'staff does not duplicate an inline fixture array' => strpos($staff, 'var ORDERS = [') === false,
	'owner does not duplicate an inline fixture array' => strpos($owner, 'var ORDERS=[') === false,
	'runtime validates payment-provider selection' => strpos($runtime, 'selectedProvider') !== false,
	'shared fixtures route through configured locations' => strpos($fixtures, 'enabledFulfilments') !== false,
	'ordering confirms no payment or real order through translations' => strpos($ordering, "DBDemo.t('checkout.demoNotice')") !== false,
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
