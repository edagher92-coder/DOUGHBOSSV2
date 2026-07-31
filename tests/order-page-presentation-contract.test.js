'use strict';

var fs = require('fs');
var path = require('path');
var test = require('node:test');
var assert = require('node:assert/strict');
var root = path.resolve(__dirname, '..');

function read(file) {
	return fs.readFileSync(path.join(root, file), 'utf8');
}

var assets = read('includes/class-doughboss-assets.php');
var client = read('public/js/doughboss.js');
var motion = read('public/js/doughboss-order-page.js');
var css = read('public/css/doughboss-order-page.css');

test('order-page integration is shortcode-portable and isolated from the active theme', function () {
	assert.match(assets, /private function is_order_page\(\)/);
	assert.match(assets, /current_post_has\( 'doughboss_menu' \)/);
	assert.match(assets, /current_post_has\( 'doughboss_cart' \)/);
	assert.match(assets, /'doughboss-order-page'/);
	assert.match(assets, /'doughboss-ordering-closed'/);
	assert.match(assets, /public\/css\/doughboss-order-page\.css/);
	assert.match(assets, /public\/js\/doughboss-order-page\.js/);
	assert.match(css, /body\.doughboss-order-page #masthead/);
	assert.match(css, /body\.doughboss-order-page \.bgheader/);
	assert.match(css, /body\.doughboss-order-page \.bgtext/);
});

test('browse-only mode disables menu mutation controls and hides checkout builders', function () {
	assert.match(client, /Promise\.all\(\[request\('\/menu'\), getConfig\(\)\]\)/);
	assert.match(client, /menuCard\(item, orderingOpen\)/);
	assert.match(client, /disabled: !orderingOpen/);
	assert.match(client, /db-btn--coming-soon/);
	assert.match(client, /class: 'db-card-customize-note'/);
	assert.match(client, /class: 'db-menu-customize'/);
	assert.match(client, /data-ordering-open/);
	assert.match(client, /initCartFab\(root\)/);
	assert.match(css, /doughboss-ordering-closed \.db-builder/);
	assert.match(css, /doughboss-ordering-closed \.db-cart/);
});

test('menu blowout motion reverses on up/down scroll and respects reduced motion', function () {
	assert.match(motion, /IntersectionObserver/);
	assert.match(motion, /data-db-scroll-state/);
	assert.match(motion, /entry\.boundingClientRect\.bottom <= 0 \? 'above' : 'below'/);
	assert.match(motion, /MutationObserver/);
	assert.match(motion, /prefers-reduced-motion: reduce/);
	assert.match(css, /data-db-scroll-state="above"/);
	assert.match(css, /data-db-scroll-state="below"/);
	assert.match(css, /is-scroll-visible/);
	assert.match(css, /@media \(prefers-reduced-motion: reduce\)/);
});
