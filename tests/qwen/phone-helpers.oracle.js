'use strict';

// Lead-written oracle for the Qwen task `phone-helpers` (ops/qwen/tasks/phone-helpers.md).
// phoneTel/phoneDisplay in public/js/doughboss.js must match the server's
// DoughBoss_Settings::phone_tel/phone_display for every valid Australian number,
// and must refuse to build a tel: link for anything else.
// Run with: node tests/qwen/phone-helpers.oracle.js

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const { storefront, extractFunction, functionSource } = require('./harness');

const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures', 'phone-cases.json'), 'utf8'));
// Both helpers share one context, so phoneDisplay may call phoneTel (and nothing else).
const load = name => {
	const ctx = {};
	ctx.phoneTel = extractFunction('phoneTel', ctx);
	ctx.phoneDisplay = extractFunction('phoneDisplay', ctx);
	return ctx[name];
};

test('the storefront script still parses', () => {
	assert.doesNotThrow(() => new vm.Script(fs.readFileSync(storefront, 'utf8')));
});

test('phoneTel matches the server for every valid Australian number', () => {
	const phoneTel = load('phoneTel');
	for (const c of fixture.parity) assert.equal(phoneTel(c.input), c.tel, JSON.stringify(c.input));
});

test('phoneDisplay matches the server for every valid Australian number', () => {
	const phoneDisplay = load('phoneDisplay');
	for (const c of fixture.parity) assert.equal(phoneDisplay(c.input), c.display, JSON.stringify(c.input));
});

test('phoneTel gives no link for anything that is not a valid Australian number', () => {
	const phoneTel = load('phoneTel');
	for (const c of fixture.reject) assert.equal(phoneTel(c.input), '', JSON.stringify(c.input));
});

test('phoneDisplay leaves anything else as typed, trimmed', () => {
	const phoneDisplay = load('phoneDisplay');
	for (const c of fixture.reject) assert.equal(phoneDisplay(c.input), c.display, JSON.stringify(c.input));
});

test('the 1300 number the server mis-handles is not turned into a foreign number', () => {
	assert.equal(load('phoneTel')('1300 123 456'), '');
	assert.equal(load('phoneDisplay')('1300 123 456'), '1300 123 456');
});

test('missing or odd values never throw and give empty strings', () => {
	const phoneTel = load('phoneTel');
	const phoneDisplay = load('phoneDisplay');
	for (const value of [null, undefined, {}, [], true, NaN]) {
		assert.equal(phoneTel(value), '', String(value));
		assert.equal(phoneDisplay(value), '', String(value));
	}
});

test('a number value is read as its digits', () => {
	assert.equal(load('phoneTel')(297742286), '');
	assert.equal(load('phoneDisplay')(297742286), '297742286');
});

test('both helpers stand alone: no DOM, network, storage or other helpers', () => {
	for (const name of ['phoneTel', 'phoneDisplay']) {
		const source = functionSource(name);
		assert.doesNotMatch(source, /\b(document|window|fetch|XMLHttpRequest|localStorage|sessionStorage|el\(|DATA|I18N)\b/, name);
	}
});
