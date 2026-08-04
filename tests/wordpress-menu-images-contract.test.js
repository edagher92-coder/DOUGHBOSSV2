'use strict';

var fs = require('fs');
var path = require('path');
var test = require('node:test');
var assert = require('node:assert/strict');
var root = path.resolve(__dirname, '..');
var rest = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-rest-controller.php'), 'utf8');

test('WordPress menu uses a distinct high-resolution local photo for every canonical product', function () {
	assert.match(rest, /\$this->menu_image_url\( \$post->post_title, \$category \)/);
	assert.match(rest, /DOUGHBOSS_PLUGIN_URL \. 'public\/images\/menu\/'/);
	[
		'zaatar-v5.webp',
		'zaatar-cheese-v5.webp',
		'cheese-v5.webp',
		'meat-v5.webp',
		'meat-cheese-v5.webp',
		'sujuk-cheese-v5.webp',
		'half-meat-cheese-v5.webp',
		'cheese-tomato-olives-v5.webp',
		'cheese-kaak-v5.webp',
		'zaatar-veggie-pizza-v5.webp',
		'labneh-veggie-pizza-v5.webp',
		'all-meat-v5.webp',
		'sujuk-deluxe-v5.webp',
		'spinach-deluxe-v5.webp',
		'veggie-plus-v5.webp',
		'pepperoni-cheese-v5.webp',
		'sujuk-special-menu-v5.webp',
		'chicken-cheese-v5.webp',
		'bbq-chicken-v5.webp',
		'peri-peri-chicken-v5.webp',
		'garlic-prawns-v5.webp',
		'spinach-pie-v5.webp',
		'haloumi-v5.webp',
		'dough-boss-pie-v5.webp',
		'aged-cheese-v5.webp',
		'zaatar-veggie-wrap-v5.webp',
		'labneh-veggie-wrap-v5.webp',
		'chicken-delight-v5.webp',
		'ultimate-chicken-v5.webp',
		'dough-boss-wrap-v5.webp',
		'choco-banana-v5.webp',
		'spring-water-v5.webp',
		'soft-drinks-v5.webp',
		'juice-v5.webp'
	].forEach(function (file) {
		assert.equal(fs.existsSync(path.join(root, 'public', 'images', 'menu', file)), true, file + ' is packaged locally');
		assert.match(rest, new RegExp("'" + file.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + "'"));
	});
	assert.doesNotMatch(rest, /'sujuk-cheese'\s*=>\s*'sujuk-deluxe\.webp'/);
	assert.doesNotMatch(rest, /'half-meat-cheese'\s*=>\s*'meat-cheese\.webp'/);
});
