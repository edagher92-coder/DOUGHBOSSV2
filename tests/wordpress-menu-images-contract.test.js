'use strict';

const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const rest = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-rest-controller.php'), 'utf8');

const verifiedImages = {
	'zaatar': 'real-v1/zaatar.jpg',
	'zaatar-cheese': 'real-v1/zaatar-cheese.jpg',
	'cheese': 'real-v1/cheese.jpg',
	'cheese-kaak': 'real-v1/cheese-kaak.jpg',
	'meat': 'real-v1/meat.jpg',
	'bbq-chicken': 'real-v1/bbq-chicken.jpg',
	'spinach-pie': 'real-v1/spinach-pie.jpg',
	'labneh-veggie-wrap': 'real-v1/labneh-veggie-wrap.jpg'
};

const coverageImages = {
	'cheese-tomato-olives': 'veggie-plus.webp',
	'half-meat-cheese': 'meat-cheese.webp',
	'all-meat': 'all-meat.webp',
	'zaatar-veggie-pizza': 'veggie-plus.webp',
	'labneh-veggie-pizza': 'veggie-plus.webp',
	'sujuk-special': 'dough-boss-special.webp',
	'garlic-prawns': 'garlic-prawns.webp',
	'ultimate-chicken': 'ultimate-chicken.webp',
	'dough-boss-wrap': 'dough-boss-wrap.webp',
	'soft-drinks-600ml': 'soft-drinks.webp'
};

test('WordPress menu retains verified merchant photography and fills every missing catalogue image', function () {
	assert.match(rest, /\$this->menu_image_url\( \$post->post_title, \$category \)/);
	assert.match(rest, /public\/images\/menu\//);
	Object.entries(Object.assign({}, verifiedImages, coverageImages)).forEach(function ([item, file]) {
		assert.equal(fs.existsSync(path.join(root, 'public', 'images', 'menu', file)), true, file + ' is packaged');
		assert.match(rest, new RegExp("'" + item + "'\\s*=>\\s*'" + file.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + "'"));
	});
});

test('missing featured images no longer produce empty menu cards', function () {
	const imageMap = rest.slice(rest.indexOf('$images = array('), rest.indexOf('$fallbacks = array('));
	const fallbackMap = rest.slice(rest.indexOf('$fallbacks = array('), rest.indexOf('$key      = sanitize_title'));
	assert.doesNotMatch(imageMap, /=>\s*''/);
	assert.doesNotMatch(fallbackMap, /=>\s*''/);
	['manoush', 'pizza', 'pies', 'wraps', 'desserts', 'drinks'].forEach(function (category) {
		assert.match(fallbackMap, new RegExp("'" + category + "'\\s*=>\\s*'[^']+\\.(?:jpg|webp)'"));
	});
	assert.match(rest, /return \$encoded_file \? DOUGHBOSS_PLUGIN_URL[^:]+: '';/s);
});
