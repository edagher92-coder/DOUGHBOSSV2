'use strict';

var fs = require('fs');
var path = require('path');
var root = path.resolve(__dirname, '..');
var html = fs.readFileSync(path.join(root, 'demo', 'index.html'), 'utf8');
var body = html.slice(html.indexOf('<body'));
var order = fs.readFileSync(path.join(root, 'demo', 'menu-order.js'), 'utf8');
var seed = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-menu-seeder.php'), 'utf8');
var schema = fs.readFileSync(path.join(root, 'demo', 'menu-schema.js'), 'utf8');
var failures = 0;

function ok(condition, label) {
	if (condition) { process.stdout.write('  ok   ' + label + '\n'); }
	else { failures += 1; process.stdout.write('  FAIL ' + label + '\n'); }
}

function hasAll(text, values) {
	return values.every(function (value) { return text.indexOf(value) !== -1; });
}

process.stdout.write('=== Menu photo contract ===\n');
ok(order.indexOf("OPT_ZAATAR_STYLE") !== -1 && order.indexOf("{ label: 'Flat', delta: 0.5 }") !== -1, 'flat Zaatar style costs 50c extra');
ok(order.indexOf('Mixed zaatar & cheese') !== -1, 'mixed Zaatar and cheese choice is restored');
ok(hasAll(body, ['Zaatar Veggie Pizza', 'Labneh Veggie Pizza', 'Labneh Veggie Wrap']), 'veggie pizza and wrap products are visible');
ok(order.indexOf("{ label: 'No sesame seeds', sum: 'No sesame', delta: 0, def: true }") !== -1, 'no sesame is the pie default');
ok(body.indexOf('Dough Boss Pie') !== -1 && body.indexOf('>Chicken Pie<') === -1, 'Dough Boss Pie replaces the incorrect Chicken Pie label');
ok(order.indexOf("if (catId === 'cat-pies') { return [OPT_SAUCE_TOP, OPT_SESAME, OPT_LEMON]; }") !== -1, 'pies offer topper sauce without base-sauce or pizza-topping controls');
ok(hasAll(order, ["label: 'Base Sauce'", "Tomato (Pizza Sauce)", "label: 'Garlic'", "label: 'BBQ'", "label: 'No Sauce'"]), 'pizza sauce menu is available with a default');
ok(order.indexOf("{ label: 'Classic', delta: 0 }") !== -1 && order.indexOf("{ label: 'Thin', delta: 0 }") === -1, 'duplicate Thin crust is renamed Classic');
ok(hasAll(body, ['Sujuk &amp; Cheese', 'Spinach Pie', 'Half Meat &amp; Cheese', 'Cheese, Tomato &amp; Olives', 'Cheese Kaak']), 'all handwritten missing products are visible');
ok(order.indexOf("label: 'Remove ingredients'") !== -1 && hasAll(order, ['No cheese', 'No tomato', 'No olives', 'No pickles']), 'manoush, pizza and wraps support ingredient removals');
ok(hasAll(seed, ['Zaatar Veggie Pizza', 'Labneh Veggie Pizza', 'Labneh Veggie Wrap', 'Dough Boss Pie', 'Spinach Pie', 'Sujuk & Cheese', 'Half Meat & Cheese', 'Cheese, Tomato & Olives', 'Cheese Kaak']), 'WordPress menu importer contains the corrected catalogue');
ok(html.indexOf('doughboss-legacy-menu-schema" type="application/json') !== -1 && schema.indexOf("querySelectorAll('#view-menu .mn-cat')") !== -1, 'structured menu data is generated from the corrected visible catalogue');

process.stdout.write('\n' + (12 - failures) + ' passed, ' + failures + ' failed\n');
process.exit(failures ? 1 : 0);
