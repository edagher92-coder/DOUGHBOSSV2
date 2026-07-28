'use strict';

var fs = require('fs');
var path = require('path');
var test = require('node:test');
var assert = require('node:assert/strict');
var root = path.resolve(__dirname, '..');

function read(file) {
	return fs.readFileSync(path.join(root, file), 'utf8');
}

var client = read('public/js/doughboss.js');
var css = read('public/css/doughboss.css');
var shortcodes = read('includes/class-doughboss-shortcodes.php');
var assets = read('includes/class-doughboss-assets.php');

test('UPLIFT-1 category navigation stays inside the rendered menu and respects motion preference', function () {
	assert.match(client, /class: 'db-jumpbar'/);
	assert.match(client, /root\.querySelector\('#' \+ targetId\)/);
	assert.match(client, /prefers-reduced-motion: reduce/);
	assert.match(css, /\.db-app \.db-jumpbar/);
	assert.match(css, /\.db-app \.db-category \{ scroll-margin-top: 64px;/);
});

test('UPLIFT-1 cart cue is a navigation aid backed by the existing cart endpoint', function () {
	assert.match(shortcodes, /'cart_url' => ''/);
	assert.match(shortcodes, /data-cart-url=/);
	assert.match(client, /function initCartFab\(root\)/);
	assert.match(client, /request\('\/cart'\)/);
	assert.match(client, /document\.addEventListener\('doughboss:cart-updated'/);
	assert.match(client, /cartRoot\.scrollIntoView/);
	assert.match(client, /fab\.remove\(\);/);
	assert.match(assets, /'viewCart'/);
	assert.match(css, /\.db-app \.db-cart-fab/);
	assert.match(css, /\.db-app\.db-menu--has-cart-fab/);
});

test('UPLIFT-1 cart cue preserves scoped reduced-motion behaviour', function () {
	assert.match(css, /\.db-app \.db-cart-fab--bump \.db-cart-fab-total/);
	assert.match(css, /\.db-app \.db-cart-fab,\n\t\.db-app \.db-cart-fab--bump/);
});

test('connected WordPress menu is contained and ordered like the approved demo', function () {
	assert.match(client, /preferredCategories = \['Manoush', 'Pizza', 'Pies', 'Wraps', 'Desserts', 'Drinks'\]/);
	assert.match(css, /repeat\(auto-fill, minmax\(min\(220px, 100%\), 1fr\)\)/);
	assert.match(css, /@media \(max-width: 640px\)/);
	assert.match(css, /\.db-app\.db-menu \{/);
	assert.match(css, /\.db-app \{[\s\S]*?box-sizing: border-box/);
	assert.match(css, /body\.page-id-402 \.entry-content/);
	assert.match(css, /body\.page-id-402 \.bgcontent/);
	assert.match(css, /body\.page-id-402 #primary-menu > li > a/);
	assert.match(css, /overflow-x: clip/);
});

test('small-screen cart, voucher and tracking controls remain usable', function () {
	assert.match(css, /\.db-fulfilment \{[\s\S]*?flex-wrap: wrap/);
	assert.match(css, /\.db-voucher-row \{ display: flex; flex-wrap: wrap/);
	assert.match(css, /\.db-voucher-input \{[\s\S]*?min-width: 0/);
	assert.match(css, /@media \(max-width: 360px\)[\s\S]*?\.db-stage-tracker/);
	assert.match(css, /\.db-app\.db-menu--has-cart-fab \{ padding-bottom: calc\(84px/);
	assert.match(css, /\.db-app \.db-jump \{[\s\S]*?min-height: 44px/);
});

test('UPLIFT-2 guides the complete menu, checkout, payment and tracking journey', function () {
	assert.match(client, /class: 'db-menu-search'/);
	assert.match(client, /function orderJourney\(\)/);
	assert.match(client, /labels = \['Review order', 'Your details', 'Secure payment'\]/);
	assert.match(client, /class: 'db-qty-control'/);
	assert.match(client, /class: 'db-checkout-summary'/);
	assert.match(client, /Secure checkout powered by Stripe/);
	assert.match(client, /class: 'db-confirm-number-wrap'/);
	assert.match(client, /class: 'db-confirm-facts'/);
	assert.match(client, /Payment received\. We are securely matching it to your order/);
	assert.match(client, /updates automatically/);
	assert.match(css, /\.db-order-shell \{[\s\S]*?grid-template-columns/);
	assert.match(css, /\.db-checkout-region \{[\s\S]*?position: sticky/);
	assert.match(css, /@media \(max-width: 900px\)[\s\S]*?\.db-order-shell/);
	assert.match(css, /@media \(max-width: 560px\)[\s\S]*?\.db-checkout-fields/);
});

test('UPLIFT-2 customer fields use mobile-friendly browser autofill', function () {
	assert.match(client, /customer_name', 'Name', true, \{ autocomplete: 'name' \}/);
	assert.match(client, /customer_email', 'Email', true, \{ autocomplete: 'email'/);
	assert.match(client, /customer_phone', 'Mobile', true, \{ autocomplete: 'tel', inputmode: 'tel' \}/);
	assert.match(client, /address', 'Delivery address'[\s\S]{0,120}?autocomplete: 'street-address'/);
	assert.match(shortcodes, /Check live status/);
	assert.match(shortcodes, /autocomplete="email"/);
});

test('specialist assets are not loaded by the broad storefront filter', function () {
	assert.match(assets, /apply_filters\( 'doughboss_load_catering_assets', false \)/);
	assert.match(assets, /apply_filters\( 'doughboss_load_voucher_assets', false \)/);
	assert.doesNotMatch(assets, /current_post_has\( 'doughboss_catering' \) \|\| apply_filters\( 'doughboss_load_assets'/);
	assert.doesNotMatch(assets, /current_post_has\( 'doughboss_voucher_claim' \) \|\| apply_filters\( 'doughboss_load_assets'/);
});
