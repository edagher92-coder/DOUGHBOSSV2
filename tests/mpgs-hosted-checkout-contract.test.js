'use strict';

var fs = require('fs');
var path = require('path');
var test = require('node:test');
var assert = require('node:assert/strict');
var root = path.resolve(__dirname, '..');

function read(file) {
	return fs.readFileSync(path.join(root, file), 'utf8');
}

var mpgs = read('includes/class-doughboss-mpgs.php');
var settings = read('includes/class-doughboss-settings.php');
var payment = read('includes/class-doughboss-payment.php');
var assets = read('includes/class-doughboss-assets.php');
var client = read('public/js/doughboss.js');
var rest = read('includes/class-doughboss-rest-controller.php');
var admin = read('admin/class-doughboss-admin.php');
var launch = read('demo/config/profiles/doughboss-revesby-launch.js');

test('MPGS is a separate gateway and never aliases Tyro Connect credentials', function () {
	assert.match(payment, /'mpgs'\s*=>\s*'DoughBoss_MPGS'/);
	assert.match(settings, /DOUGHBOSS_MPGS_TEST_API_PASSWORD/);
	assert.doesNotMatch(mpgs, /DOUGHBOSS_TYRO|auth\.connect\.tyro/);
	assert.match(admin, /value="mpgs"/);
});

test('MPGS endpoints are HTTPS Mastercard hosts and credentials stay server-side', function () {
	assert.match(settings, /'https'\s*!==\s*strtolower/);
	assert.match(settings, /gateway\\\.mastercard\\\.com/);
	assert.match(settings, /https:\/\/test-tyro\.mtf\.gateway\.mastercard\.com/);
	assert.match(settings, /'mpgs_api_version'\s*=>\s*100/);
	assert.match(mpgs, /'Authorization'\s*=>\s*'Basic '/);
	assert.doesNotMatch(client, /api_password|API password|merchant\./i);
	assert.doesNotMatch(assets, /mpgs_api_password|DOUGHBOSS_MPGS/);
});

test('Hosted Checkout owns card entry and the browser stores no card fields', function () {
	assert.match(mpgs, /'apiOperation'\s*=>\s*'INITIATE_CHECKOUT'/);
	assert.match(mpgs, /'operation'\s*=>\s*'PURCHASE'/);
	assert.match(mpgs, /\/static\/checkout\/checkout\.min\.js/);
	assert.doesNotMatch(mpgs, /\/checkout\/version\/.*\/checkout\.js/);
	assert.match(client, /window\.Checkout\.showPaymentPage/);
	assert.doesNotMatch(client, /mpgs.*(?:pan|cvv|cvc|cardNumber)/i);
});

test('paid order creation requires server retrieval and immutable checkout binding', function () {
	assert.match(mpgs, /'GET', '\/order\/'/);
	assert.match(mpgs, /DoughBoss_Payment_Attempts::find_by_provider_reference/);
	assert.match(mpgs, /metadata\['checkout_key'\]/);
	assert.match(mpgs, /\? \$result\['order'\] : \$result/);
	assert.match(mpgs, /'CAPTURED' === \$provider_state/);
	assert.doesNotMatch(mpgs, /'PARTIALLY_CAPTURED'.*'succeeded'/);
	assert.match(rest, /hash_equals\( \$expected_checkout, \$meta_checkout \)/);
	assert.match(rest, /'succeeded'\s*!==\s*\$status/);
});

test('Hosted Checkout return keeps only safe WordPress routing state', function () {
	assert.match(client, /return_url: mpgsReturnUrl\(\)/);
	assert.match(client, /\['page_id', 'p'\]/);
	assert.match(client, /searchParams\.get\('preview'\) === 'true'/);
	assert.match(rest, /array\( 'page_id', 'p' \)/);
	assert.match(rest, /\$safe_query\['preview'\] = 'true'/);
	assert.doesNotMatch(rest, /\$safe_query\['preview_nonce'\]/);
	assert.match(client, /'checkoutVersion'/);
});

test('secondary POSPal stores can be deliberately returned to a dormant state', function () {
	assert.match(admin, /_clear_app_key/);
	assert.match(admin, /Clear the stored App Key and leave this store dormant/);
});

test('MPGS enforces per-shop payment permission and reconciles notification callbacks server-side', function () {
	var locations = read('includes/class-doughboss-locations.php');
	assert.match(locations, /function online_payment_location/);
	assert.match(rest, /DoughBoss_Locations::online_payment_location\( \$location_id \)/);
	assert.match(mpgs, /'notificationUrl'\s*=>\s*\$notification_url/);
	assert.match(mpgs, /function reconcile_notification/);
	assert.match(mpgs, /DoughBoss_Order::payment_intent_used/);
	assert.match(rest, /\/mpgs-notification/);
	assert.match(rest, /function mpgs_notification/);
	assert.match(admin, /recovery_required|recovery-needed/);
});

test('public ordering stays independently gated and live MPGS is fail-closed', function () {
	assert.match(rest, /DoughBoss_Settings::ordering_open\(\)/);
	assert.match(settings, /'test'\s*===\s*self::mpgs_mode\(\)\s*\|\|\s*\(bool\) self::get\( 'mpgs_live_approved'/);
	assert.match(rest, /\/pay\/mpgs-test/);
});

test('Revesby acceptance profile selects MPGS while real payments remain disabled', function () {
	assert.match(launch, /allowedProviders:\s*\['mpgs', 'stripe', 'tyro'\]/);
	assert.match(launch, /selectedProvider:\s*'mpgs'/);
	assert.match(launch, /payments:\s*\{\s*enabled:\s*false/);
});
