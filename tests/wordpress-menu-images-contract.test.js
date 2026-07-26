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
		'zaatar.jpg',
		'zaatar-cheese.jpg',
		'cheese.jpg',
		'meat.jpg',
		'all-meat.jpg',
		'dough-boss-special.jpg',
		'spinach-cheese-pie.jpg',
		'aged-cheese-pie.jpg',
		'zaatar-veggie-wrap.jpg',
		'choco-banana.jpg',
		'soft-drinks.jpg'
	].forEach(function (file) {
		assert.equal(fs.existsSync(path.join(root, 'public', 'images', 'menu', file)), true, file + ' is packaged locally');
	});
});
