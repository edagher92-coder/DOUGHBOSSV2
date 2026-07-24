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
	assert.match(mpgs, /\/checkout\/version\//);
	assert.match(client, /window\.Checkout\.showPaymentPage/);
	assert.doesNotMatch(client, /mpgs.*(?:pan|cvv|cvc|cardNumber)/i);
});

test('paid order creation requires server retrieval and immutable checkout binding', function () {
	assert.match(mpgs, /'GET', '\/order\/'/);
	assert.match(mpgs, /DoughBoss_Payment_Attempts::find_by_provider_reference/);
	assert.match(mpgs, /metadata\['checkout_key'\]/);
	assert.match(rest, /hash_equals\( \$expected_checkout, \$meta_checkout \)/);
	assert.match(rest, /'succeeded'\s*!==\s*\$status/);
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
