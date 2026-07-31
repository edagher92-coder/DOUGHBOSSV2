'use strict';

var fs = require('fs');
var path = require('path');
var test = require('node:test');
var assert = require('node:assert/strict');
var root = path.resolve(__dirname, '..');
var client = fs.readFileSync(path.join(root, 'public', 'js', 'doughboss.js'), 'utf8');
var controller = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-rest-controller.php'), 'utf8');

test('storefront renders canonical option controls and sends only selected slugs', function () {
	assert.match(client, /var options = Array\.isArray\(item\.options\)/);
	assert.match(client, /fieldset.*db-menu-option-group/);
	assert.match(client, /options: selectedOptions\(\)/);
	assert.match(client, /input\.checked.*\[choice\.slug\]/);
});

test('storefront price preview is backed by the REST server resolver', function () {
	assert.match(client, /function refreshPrice\(\)/);
	assert.match(client, /total \+= Number\(choice\.price \|\| 0\)/);
	assert.match(controller, /DoughBoss_Menu_Options::resolve\( \$groups, \$request->get_param\( 'options' \) \)/);
	assert.match(controller, /'unit_price' => round\( \$price \+ \$resolved\['delta'\], 2 \)/);
});
