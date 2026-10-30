'use strict';

// Shared by the lead-written Qwen oracles: pull named functions out of the
// storefront script (as tests/pickup-storefront-2-51.test.js does) and a fake
// DOM just big enough for el().

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..', '..');
const storefront = path.join(root, 'public', 'js', 'doughboss.js');

function functionSource(name) {
	const source = fs.readFileSync(storefront, 'utf8');
	const start = source.indexOf('function ' + name + '(');
	assert.notEqual(start, -1, name + ' must exist in doughboss.js');
	assert.equal(source.indexOf('function ' + name + '(', start + 1), -1, name + ' must be defined once');
	let depth = 0;
	let started = false;
	for (let index = start; index < source.length; index += 1) {
		if (source[index] === '{') { started = true; depth += 1; }
		else if (source[index] === '}' && started) {
			depth -= 1;
			if (depth === 0) return source.slice(start, index + 1);
		}
	}
	throw new Error(name + ' has no complete body');
}

// Compiles the function inside `context`, so it sees exactly what the test gives it.
function extractFunction(name, context) {
	return vm.runInNewContext('(' + functionSource(name) + ')', context);
}

function fakeDocument() {
	function node(tag) {
		return {
			tagName: String(tag).toUpperCase(), children: [], attributes: {}, className: '', textContent: '',
			appendChild(child) { this.children.push(child); return child; },
			setAttribute(key, value) { this.attributes[key] = String(value); },
			getAttribute(key) { return Object.prototype.hasOwnProperty.call(this.attributes, key) ? this.attributes[key] : null; }
		};
	}
	return {
		createElement: node,
		createTextNode: text => ({ tagName: '#text', textContent: String(text), children: [] })
	};
}

module.exports = { root, storefront, functionSource, extractFunction, fakeDocument };
