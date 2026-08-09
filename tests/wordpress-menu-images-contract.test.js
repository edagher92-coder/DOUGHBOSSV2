'use strict';

const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const rest = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-rest-controller.php'), 'utf8');

const authentic = [
	'zaatar.jpg', 'zaatar-cheese.jpg', 'cheese.jpg', 'meat.jpg',
	'meat-cheese.jpg', 'sujuk-cheese.jpg', 'cheese-kaak.jpg',
	'sujuk-deluxe.jpg', 'spinach-deluxe.jpg', 'veggie-plus.jpg',
	'pepperoni-cheese.jpg', 'chicken-cheese.jpg', 'bbq-chicken.jpg',
	'peri-peri-chicken.jpg', 'spinach-pie.jpg', 'haloumi-pie.jpg',
	'dough-boss-pie.jpg', 'aged-cheese-pie.jpg', 'zaatar-veggie-wrap.jpg',
	'labneh-veggie-wrap.jpg', 'chicken-delight-wrap.jpg', 'choco-banana.jpg',
	'spring-water.jpg', 'juice.jpg'
];

test('WordPress menu ships and maps only approved authentic merchant photography', function () {
	assert.match(rest, /\$this->menu_image_url\( \$post->post_title, \$category \)/);
	assert.match(rest, /public\/images\/menu\//);
	authentic.forEach(function (file) {
		assert.equal(fs.existsSync(path.join(root, 'public', 'images', 'menu', 'real-v1', file)), true, file + ' is packaged');
		assert.match(rest, new RegExp("'real-v1/" + file.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + "'"));
	});
	assert.doesNotMatch(rest, /-v5\.webp/);
});

test('unverified products use an honest no-photo state rather than lookalikes', function () {
	[
		'half-meat-cheese', 'cheese-tomato-olives', 'zaatar-veggie-pizza',
		'labneh-veggie-pizza', 'all-meat', 'sujuk-special', 'garlic-prawns',
		'ultimate-chicken', 'dough-boss-wrap', 'soft-drinks-600ml'
	].forEach(function (key) {
		assert.match(rest, new RegExp("'" + key + "'\\s*=>\\s*''"), key + ' has no misleading substitute');
	});
	['manoush', 'pizza', 'pies', 'wraps', 'desserts', 'drinks'].forEach(function (category) {
		assert.match(rest, new RegExp("'" + category + "'\\s*=>\\s*''"), category + ' does not repeat a fallback image');
	});
	assert.match(rest, /return \$encoded_file \? DOUGHBOSS_PLUGIN_URL[^:]+: '';/s);
});
