'use strict';

// Lead-written oracle for the Qwen task `shop-contact-phone` (ops/qwen/tasks/shop-contact-phone.md).
// The shop card's phone must read like the rest of the site ("(02) 8764 6783")
// and dial in +61 form; anything that is not a valid Australian number shows
// as plain text with no link. Everything else shopContact renders is unchanged.
// Run with: node tests/qwen/shop-contact-phone.oracle.js

const assert = require('node:assert/strict');
const test = require('node:test');
const { extractFunction, fakeDocument } = require('./harness');

function render(loc, cfg) {
	const ctx = { document: fakeDocument(), window: {}, PAY: { enabled: false } };
	ctx.el = extractFunction('el', ctx);
	ctx.phoneTel = extractFunction('phoneTel', ctx);
	ctx.phoneDisplay = extractFunction('phoneDisplay', ctx);
	return extractFunction('shopContact', ctx)(loc, cfg);
}
const phoneOf = info => info.children.filter(child => child.className === 'db-shop-phone');
const classes = info => info.children.map(child => child.className);

test('a Bankstown landline shows formatted and dials +61', () => {
	const [phone] = phoneOf(render({ phone: '0287646783' }, null));
	assert.equal(phone.tagName, 'A');
	assert.equal(phone.textContent, '(02) 8764 6783');
	assert.equal(phone.href, 'tel:+61287646783');
});

test('a mobile already in +61 form shows the local way', () => {
	const [phone] = phoneOf(render({ phone: '+61 466 353 133' }, null));
	assert.equal(phone.tagName, 'A');
	assert.equal(phone.textContent, '0466 353 133');
	assert.equal(phone.href, 'tel:+61466353133');
});

test('a number that is not a valid Australian phone is text, never a link', () => {
	for (const raw of ['1300 123 456', '+44 20 7946 0958', 'call the shop']) {
		const [phone] = phoneOf(render({ phone: raw }, null));
		assert.equal(phone.tagName, 'SPAN', raw);
		assert.equal(phone.textContent, raw, raw);
		assert.ok(!phone.href, raw + ' has no href');
	}
});

test('no phone, no phone element', () => {
	assert.equal(phoneOf(render({ phone: '' }, null)).length, 0);
	assert.equal(phoneOf(render({ address: '1 Example St' }, null)).length, 0);
	assert.equal(phoneOf(render(null, null)).length, 0);
});

test('address, phone and the ordering context keep their order and content', () => {
	const info = render({ address: '1 Example St, Revesby NSW 2212', phone: '0297742286' }, { ordering_open: false });
	assert.equal(info.className, 'db-shop-info');
	assert.deepEqual(classes(info), ['db-shop-addr', 'db-shop-phone', 'db-shop-context']);
	assert.equal(info.children[0].textContent, '1 Example St, Revesby NSW 2212');
	const context = JSON.stringify(info.children[2], (key, value) => key === 'attributes' ? undefined : value);
	assert.match(context, /Online ordering paused/);
	assert.match(context, /Contact the shop/);
});

test('the shop status line is still attached first when the status script is present', () => {
	const ctx = { document: fakeDocument(), PAY: { enabled: false }, attached: [] };
	ctx.window = { DoughBossShopStatus: { attach: (node, loc) => ctx.attached.push(loc) } };
	ctx.el = extractFunction('el', ctx);
	ctx.phoneTel = extractFunction('phoneTel', ctx);
	ctx.phoneDisplay = extractFunction('phoneDisplay', ctx);
	const loc = { phone: '0297742286' };
	const info = extractFunction('shopContact', ctx)(loc, null);
	assert.equal(info.children[0].className, 'db-pickup-status');
	assert.equal(ctx.attached.length, 1);
	assert.equal(phoneOf(info)[0].textContent, '(02) 9774 2286');
});
