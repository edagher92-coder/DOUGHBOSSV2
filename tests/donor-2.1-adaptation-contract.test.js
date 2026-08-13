'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const rest = read('includes/class-doughboss-rest-controller.php');
const cart = read('includes/class-doughboss-cart.php');
const shortcodes = read('includes/class-doughboss-shortcodes.php');
const client = read('public/js/doughboss.js');
const css = read('public/css/doughboss.css');

test('cart mutation abuse ceilings reuse the trusted concurrency-safe limiter', () => {
	assert.match(rest, /private function cart_mutation_rate_error/);
	assert.match(rest, /rate_limited\( 'cart_' \. \$bucket, \$max, MINUTE_IN_SECONDS \)/);
	assert.match(rest, /add_to_cart[\s\S]{0,500}?cart_mutation_rate_error\( 'add', 60 \)/);
	assert.match(rest, /update_cart[\s\S]{0,500}?cart_mutation_rate_error\( 'update', 120 \)/);
	assert.match(rest, /remove_from_cart[\s\S]{0,500}?cart_mutation_rate_error\( 'remove', 60 \)/);
	assert.match(rest, /clear_cart[\s\S]{0,500}?cart_mutation_rate_error\( 'clear', 20 \)/);
	assert.match(rest, /SELECT GET_LOCK\(%s, %d\)/);
	assert.match(rest, /DoughBoss_Settings::behind_reverse_proxy\(\)/);
	assert.match(rest, /doughboss_cart_rate_limit/);
});

test('menu loading state is visual, accessible and motion-safe', () => {
	assert.match(shortcodes, /class="db-loading-label"/);
	assert.match(shortcodes, /class="db-menu-skeleton" aria-hidden="true"/);
	assert.match(shortcodes, /class="db-menu-skeleton-card"/);
	assert.match(css, /@keyframes db-menu-shimmer/);
	assert.match(css, /\.db-menu-skeleton-card \{ animation: none !important; \}/);
});

test('price feedback runs only after customer option changes and respects reduced motion', () => {
	assert.match(client, /function dbPulsePrice\(node\)/);
	assert.match(client, /prefers-reduced-motion: reduce/);
	assert.match(client, /function refreshPriceAnimated\(\)/);
	assert.match(css, /@keyframes db-price-pulse/);
	assert.match(css, /\.db-app \.db-price-pulse/);
});

test('cart storage keeps one transient authority instead of duplicating cache state', () => {
	assert.match(cart, /get_transient\( \$this->transient_key\(\) \)/);
	assert.match(cart, /set_transient\( \$this->transient_key\(\), \$lines, self::TTL \)/);
	assert.doesNotMatch(cart, /wp_cache_(?:get|set)\(/);
});
