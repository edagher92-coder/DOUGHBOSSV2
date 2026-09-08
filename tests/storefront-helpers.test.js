'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function extractFunction(file, name, context) {
	const source = fs.readFileSync(file, 'utf8');
	const marker = 'function ' + name + '(';
	const start = source.indexOf(marker);
	assert.notEqual(start, -1, name + ' must exist in ' + path.basename(file));
	let depth = 0;
	let bodyStarted = false;
	let end = -1;
	for (let index = start; index < source.length; index += 1) {
		if (source[index] === '{') {
			bodyStarted = true;
			depth += 1;
		} else if (source[index] === '}' && bodyStarted) {
			depth -= 1;
			if (depth === 0) {
				end = index + 1;
				break;
			}
		}
	}
	assert.notEqual(end, -1, name + ' must have a complete function body');
	return vm.runInNewContext('(' + source.slice(start, end) + ')', context || {});
}

const storefront = path.resolve(__dirname, '..', 'public', 'js', 'doughboss.js');
const square = path.resolve(__dirname, '..', 'public', 'js', 'doughboss-square.js');

const prettyUrl = extractFunction(storefront, 'restRequestUrl', {
	DATA: { restUrl: 'https://example.test/wp-json/doughboss/v1' }
});
assert.equal(
	prettyUrl('/cart?order_type=pickup'),
	'https://example.test/wp-json/doughboss/v1/cart?order_type=pickup',
	'pretty REST routes retain their first query separator'
);

const plainUrl = extractFunction(storefront, 'restRequestUrl', {
	DATA: { restUrl: 'https://example.test/index.php?rest_route=/doughboss/v1' }
});
assert.equal(
	plainUrl('/cart?order_type=pickup'),
	'https://example.test/index.php?rest_route=/doughboss/v1/cart&order_type=pickup',
	'plain-permalink REST routes append endpoint parameters with an ampersand'
);
assert.equal(
	plainUrl('/locations'),
	'https://example.test/index.php?rest_route=/doughboss/v1/locations',
	'plain-permalink REST routes without endpoint parameters remain unchanged'
);

const retrySafe = extractFunction(square, 'isRetrySafeOutcome');
assert.equal(retrySafe({ retry_safe: true, payment_pending: false }), true, 'validated terminal no-charge outcomes unlock retry');
assert.equal(retrySafe({ retry_safe: true, payment_pending: true }), false, 'contradictory pending evidence remains locked');
assert.equal(retrySafe({ retry_safe: false, payment_pending: false }), false, 'unknown provider outcomes remain locked');
assert.equal(retrySafe({}), false, 'missing provider evidence remains locked');

console.log('6 storefront helper assertions passed.');
