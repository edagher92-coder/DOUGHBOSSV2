'use strict';

const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const themeRoot = path.join(root, 'themes', 'doughboss-final');
const imageRoot = path.join(root, 'public', 'images');

function walk(directory, extensions) {
	return fs.readdirSync(directory, { withFileTypes: true }).flatMap(function (entry) {
		const file = path.join(directory, entry.name);
		if (entry.isDirectory()) { return walk(file, extensions); }
		return extensions.includes(path.extname(entry.name)) ? [file] : [];
	});
}

test('every DoughBoss Final theme image reference exists in the plugin asset set', function () {
	const references = new Set();
	walk(themeRoot, ['.php', '.css', '.js']).forEach(function (file) {
		const source = fs.readFileSync(file, 'utf8');
		for (const match of source.matchAll(/doughboss_final_asset_url\(\s*['"]([^'"]+)['"]\s*\)/g)) {
			references.add(match[1]);
		}
	});
	assert.ok(references.size >= 5, 'theme exposes an auditable image-reference set');
	for (const reference of references) {
		assert.equal(fs.existsSync(path.join(imageRoot, reference)), true, reference + ' is packaged');
	}
});

test('production templates contain no rejected generated v4/v5 food references', function () {
	const production = walk(themeRoot, ['.php', '.css', '.js'])
		.concat(walk(path.join(root, 'includes'), ['.php']))
		.concat(walk(path.join(root, 'public'), ['.php', '.css', '.js']))
		.map(function (file) { return fs.readFileSync(file, 'utf8'); })
		.join('\n');
	assert.doesNotMatch(production, /(?:hero|home|menu)[^'"\s)]*-v[45]\.webp/i);
});

test('the order hero uses approved authentic merchant photography', function () {
	const order = fs.readFileSync(path.join(themeRoot, 'page-order.php'), 'utf8');
	assert.match(order, /menu\/real-v1\/sujuk-deluxe\.jpg/);
	assert.doesNotMatch(order, /doughboss-hero-premium-v1\.webp/);
});
