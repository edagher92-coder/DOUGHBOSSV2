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
