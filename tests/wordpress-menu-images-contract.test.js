'use strict';

var fs = require('fs');
var path = require('path');
var test = require('node:test');
var assert = require('node:assert/strict');
var root = path.resolve(__dirname, '..');
var rest = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-rest-controller.php'), 'utf8');

test('WordPress menu uses approved local photography before the branded fallback', function () {
	assert.match(rest, /\$this->menu_image_url\( \$post->post_title, \$category \)/);
	assert.match(rest, /DOUGHBOSS_PLUGIN_URL \. 'public\/images\/menu\/'/);
	[
		'zaatar.webp',
		'zaatar-cheese.webp',
		'cheese.webp',
		'meat.webp',
		'all-meat.webp',
		'dough-boss-special.webp',
		'spinach-cheese-pie.webp',
		'aged-cheese-pie.webp',
		'zaatar-veggie-wrap.webp',
		'choco-banana.webp',
		'soft-drinks.webp'
	].forEach(function (file) {
		assert.equal(fs.existsSync(path.join(root, 'public', 'images', 'menu', file)), true, file + ' is packaged locally');
	});
});
