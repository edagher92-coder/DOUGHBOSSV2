'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'doughboss-marketing.js'), 'utf8');
const dispatched = [];
const meta = [];
const tiktok = [];
const listeners = {};

function CustomEvent(type, options) {
	this.type = type;
	this.detail = options && options.detail;
}

const document = {
	addEventListener(type, callback) { listeners[type] = callback; },
	dispatchEvent(event) { dispatched.push(event); return true; },
};

const window = {
	DoughBossMarketingConfig: {
		enabled: true,
		metaPixelId: 'meta-test',
		tiktokPixelId: 'tiktok-test',
		consentVersion: '2026-07',
	},
	crypto: { randomUUID: (() => { let id = 0; return () => `event-${++id}`; })() },
	location: { search: '?utm_source=meta&utm_medium=paid_social&utm_campaign=winter&utm_content=video' },
	fbq() { meta.push(Array.from(arguments)); },
	ttq: { track() { tiktok.push(Array.from(arguments)); } },
};

vm.runInNewContext(source, {
	window,
	document,
	CustomEvent,
	URLSearchParams,
	Date,
	Math,
	Object,
	Array,
	String,
	Number,
	Boolean,
	RegExp,
});

assert.ok(window.DoughBossMarketing, 'bridge is exposed');

const firstId = window.DoughBossMarketing.track('purchase', {
	content_ids: ['manoush-1'],
	content_name: 'Zaatar',
	value: 8.5,
	currency: 'AUD',
	name: 'Customer Name',
	email: 'customer@example.com',
	phone: '0400000000',
	address: '1 Example Street',
	order_number: 'DB-123',
	table_number: '12',
	voucher_code: 'SECRET',
});
assert.strictEqual(firstId, 'event-1');
assert.strictEqual(meta.length, 0, 'Meta receives nothing before consent');
assert.strictEqual(tiktok.length, 0, 'TikTok receives nothing before consent');

const firstEnvelope = dispatched.find((event) => event.type === 'doughboss:marketing-event').detail;
assert.deepStrictEqual(Object.keys(firstEnvelope.properties).sort(), ['content_ids', 'content_name', 'currency', 'value']);
assert.strictEqual(Object.keys(firstEnvelope.attribution).length, 0, 'UTM data is not read before measurement consent');

window.DoughBossMarketing.setConsent({ measurement: true, advertising: false, version: '2026-07' });
window.DoughBossMarketing.track('add_to_cart', { content_ids: ['pie-1'], value: 10, currency: 'AUD', quantity: 1 });
assert.strictEqual(meta.length, 0, 'measurement consent alone does not enable Meta');
assert.strictEqual(tiktok.length, 0, 'measurement consent alone does not enable TikTok');
assert.strictEqual(window.DoughBossMarketing.getAttribution().campaign, 'winter');

window.DoughBossMarketing.setConsent({ measurement: true, advertising: true, version: '2026-07' });
window.DoughBossMarketing.track('purchase', { content_ids: ['pie-1'], value: 10, currency: 'AUD', num_items: 1 });
assert.strictEqual(meta.length, 1, 'Meta receives one consented purchase');
assert.strictEqual(meta[0][1], 'Purchase');
assert.strictEqual(tiktok.length, 1, 'TikTok receives one consented payment');
assert.strictEqual(tiktok[0][0], 'CompletePayment');

window.DoughBossMarketing.track('purchase_simulated', { value: 10, currency: 'AUD', simulated: true });
assert.strictEqual(meta.length, 1, 'simulated purchase never reaches Meta');
assert.strictEqual(tiktok.length, 1, 'simulated purchase never reaches TikTok');

const socialLink = {
	getAttribute(name) {
		return {
			'data-doughboss-engagement': 'social_engagement',
			'data-content-name': 'Instagram',
			'data-channel': 'homepage',
		}[name] || '';
	},
};
listeners.click({ target: { closest() { return socialLink; } } });
const socialEnvelope = dispatched.find((event) => event.type === 'doughboss:marketing-event' && event.detail.event_type === 'social_engagement').detail;
assert.strictEqual(socialEnvelope.properties.content_name, 'Instagram');
assert.strictEqual(socialEnvelope.properties.channel, 'homepage');
assert.strictEqual(meta.length, 1, 'social engagement stays first-party and never becomes a misleading Meta commerce event');
assert.strictEqual(tiktok.length, 1, 'social engagement stays first-party and never becomes a misleading TikTok commerce event');

console.log('Marketing consent contract: PII allowlist, consent gates, attribution, and demo isolation passed.');
