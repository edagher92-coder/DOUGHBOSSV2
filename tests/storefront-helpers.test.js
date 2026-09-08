'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

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
const shopStatus = path.resolve(__dirname, '..', 'public', 'js', 'doughboss-shop-status.js');
const catering = path.resolve(__dirname, '..', 'public', 'js', 'doughboss-catering.js');

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

const dietaryBadges = extractFunction(storefront, 'dietaryBadges');
function localBadges(value) {
	return JSON.parse(JSON.stringify(dietaryBadges(value)));
}
assert.deepEqual(localBadges(null), [], 'missing dietary flags render no badges');
assert.deepEqual(localBadges({ vegan: true }), [], 'invalid dietary input renders no badges');
assert.deepEqual(
	localBadges(['vegetarian', 'VEGAN', 'halal', 'gluten_free', 'vegetarian', 'unknown']),
	[
		{ value: 'vegetarian', label: 'V Vegetarian' },
		{ value: 'vegan', label: 'VG Vegan' },
		{ value: 'halal', label: 'Halal' },
		{ value: 'gluten_free', label: 'GF Gluten-free' }
	],
	'allowlisted dietary flags are normalised and deduplicated'
);
assert.deepEqual(localBadges(['gluten_free']), [{ value: 'gluten_free', label: 'GF Gluten-free' }], 'gluten-free is shown only when explicitly supplied as an item flag');
assert.deepEqual(localBadges(['vegetarian']), [{ value: 'vegetarian', label: 'V Vegetarian' }], 'vegetarian remains distinct from gluten-free');
assert.deepEqual(localBadges(['__proto__', 'constructor', 'toString', 3, false, '  VEGAN ']), [{ value: 'vegan', label: 'VG Vegan' }], 'prototype names and non-string flags cannot become claims');

const pickupStatusText = extractFunction(shopStatus, 'pickupStatusText');
const observed = Date.parse('2026-09-08T01:00:00Z');
const openConfig = { ordering_open: true, enable_pickup: true };
function shop(state, changes) {
	return { pickup_enabled: true, pickup_status: Object.assign({ state, observed_at_utc: '2026-09-08T01:00:00Z', expires_at_utc: '2026-09-08T01:01:00Z' }, changes) };
}
assert.match(pickupStatusText(shop('open'), openConfig, observed), /open now/, 'fresh configured pickup hours are open');
assert.match(pickupStatusText(shop('closes_soon'), openConfig, observed), /closing soon/, 'closing soon is distinct from open');
assert.match(pickupStatusText(shop('open'), { ordering_open: false }, observed), /ordering is paused/, 'global pause takes precedence over open hours');
assert.match(pickupStatusText({ pickup_enabled: false }, openConfig, observed), /unavailable/, 'disabled pickup cannot be offered');
assert.match(pickupStatusText(shop('closed', { next_open_label: 'Wednesday 6:30am' }), openConfig, observed), /Wednesday 6:30am\. Not a pre-order booking/, 'next opening must not promise a booked pre-order');
assert.match(pickupStatusText(shop('open'), openConfig, observed + 60000), /unconfirmed/, 'expired hours fail closed at the exact expiry');
assert.match(pickupStatusText(shop('open', { expires_at_utc: 'invalid' }), openConfig, observed), /unconfirmed/, 'malformed expiry fails closed');
assert.match(pickupStatusText(shop('open', { expires_at_utc: '2026-09-08T01:05:00Z' }), openConfig, observed), /unconfirmed/, 'long-lived hours evidence is rejected');
assert.match(pickupStatusText(shop('open'), openConfig, observed - 60000), /unconfirmed/, 'future-dated evidence is not treated as current');
assert.match(pickupStatusText(shop('invented'), openConfig, observed), /unconfirmed/, 'unknown state does not imply an open shop');

let expiryDelay;
let clearedTimer;
const scheduleTick = extractFunction(shopStatus, 'scheduleTick', {
	tickTimer: 19,
	watches: [{ location: shop('open', { expires_at_utc: '2026-09-08T01:00:05Z' }) }],
	Date: { now: () => observed, parse: Date.parse },
	clearTimeout: id => { clearedTimer = id; },
	setTimeout: (callback, delay) => { expiryDelay = delay; return 20; },
	tick: () => {}
});
scheduleTick();
assert.equal(clearedTimer, 19, 'newly attached short-lived evidence reschedules the existing timer');
assert.equal(expiryDelay, 5020, 'the status expires near its deadline rather than waiting for the next 15-second refresh');

test('public shop reads retain signed cookies without imposing a stale WP nonce', async () => {
	let requested;
	const readShop = extractFunction(shopStatus, 'read', {
		data: { restUrl: 'https://example.test/?rest_route=/doughboss/v1', nonce: 'synthetic-expired-nonce' },
		fetch: (url, options) => { requested = { url, options }; return Promise.resolve({ ok: true, json: () => Promise.resolve({ active: false }) }); }
	});
	await readShop('/table/context');
	assert.equal(requested.url, 'https://example.test/?rest_route=/doughboss/v1/table/context');
	assert.equal(requested.options.credentials, 'same-origin');
	assert.equal(requested.options.cache, 'no-store');
	assert.equal(requested.options.headers, undefined, 'public-only reads must not send an expired X-WP-Nonce');
});

const retrySafe = extractFunction(square, 'isRetrySafeOutcome');
assert.equal(retrySafe({ retry_safe: true, payment_pending: false }), true, 'validated terminal no-charge outcomes unlock retry');
assert.equal(retrySafe({ retry_safe: true, payment_pending: true }), false, 'contradictory pending evidence remains locked');
assert.equal(retrySafe({ retry_safe: false, payment_pending: false }), false, 'unknown provider outcomes remain locked');
assert.equal(retrySafe({}), false, 'missing provider evidence remains locked');

const validCateringQuote = extractFunction(catering, 'validCateringQuote');
function cateringQuote(total) {
	return { subtotal: total, delivery_fee: 0, total, deposit_pct: 30, deposit: total * 0.3, balance: total * 0.7, lead_days: 2, currency: 'AUD' };
}

test('catering quotes reject error objects and invalid display fields', () => {
	assert.equal(validCateringQuote(cateringQuote(100)), true);
	assert.equal(validCateringQuote(cateringQuote(0)), true);
	assert.equal(validCateringQuote(Object.assign(cateringQuote(72), { currency: 'aud' })), true, 'stored lowercase currency remains a valid server quote');
	assert.equal(validCateringQuote(Object.assign(cateringQuote(72), { currency: ' aUd ' })), true, 'display normalisation accepts surrounding whitespace and mixed case');
	for (const invalid of [null, [], {}, { code: 'error' }, Object.assign(cateringQuote(1), { currency: '<b>' }), Object.assign(cateringQuote(1), { deposit_pct: 101 }), Object.assign(cateringQuote(1), { lead_days: 1.5 })]) {
		assert.equal(validCateringQuote(invalid), false);
	}
	for (const field of ['subtotal', 'delivery_fee', 'total', 'deposit_pct', 'deposit', 'balance', 'lead_days']) {
		for (const value of [undefined, null, '10', NaN, Infinity, -1]) {
			assert.equal(validCateringQuote(Object.assign(cateringQuote(100), { [field]: value })), false, field + ' rejects ' + value);
		}
	}
});

function cateringRefreshHarness() {
	const state = { selectedId: 1, guests: 12, orderType: 'pickup', quote: cateringQuote(56), quoteStatus: 'ready', quoteGeneration: 0 };
	const requests = [];
	const paints = [];
	const refresh = extractFunction(catering, 'refreshQuote', {
		state,
		root: { querySelector: () => ({}) },
		updateQuoteBox: () => paints.push({ status: state.quoteStatus, total: state.quote && state.quote.total }),
		validCateringQuote,
		get: url => new Promise((resolve, reject) => requests.push({ url, resolve, reject }))
	});
	return { state, requests, paints, refresh };
}

test('one catering generation protects package, headcount and fulfilment from stale success', async () => {
	const h = cateringRefreshHarness();
	const first = h.refresh();
	assert.equal(h.state.quote, null, 'previous total clears before any network response');
	assert.equal(h.state.quoteStatus, 'loading');
	h.state.selectedId = 2;
	h.state.guests = 30;
	h.state.orderType = 'delivery';
	const second = h.refresh();
	assert.match(h.requests[1].url, /package_id=2&guest_count=30&order_type=delivery/);
	h.requests[1].resolve(cateringQuote(124));
	await second;
	h.requests[0].resolve(cateringQuote(56));
	await first;
	assert.equal(h.state.quote.total, 124);
	assert.equal(h.state.quoteStatus, 'ready');
	assert.equal(h.paints.length, 3, 'superseded success cannot paint');
});

test('a stale failure cannot clear a fresh quote and a current failure removes stale totals', async () => {
	const h = cateringRefreshHarness();
	const first = h.refresh();
	const second = h.refresh();
	h.requests[1].resolve(cateringQuote(72));
	await second;
	h.requests[0].reject(new Error('old request failed'));
	await first;
	assert.equal(h.state.quote.total, 72);
	const third = h.refresh();
	assert.equal(h.state.quote, null);
	h.requests[2].reject(new Error('offline'));
	await third;
	assert.equal(h.state.quote, null);
	assert.equal(h.state.quoteStatus, 'error');
});

test('malformed current quotes fail closed and custom selection invalidates an in-flight quote', async () => {
	const h = cateringRefreshHarness();
	const first = h.refresh();
	h.requests[0].resolve({ code: 'rest_error', total: '56' });
	await first;
	assert.equal(h.state.quoteStatus, 'error');
	assert.equal(h.state.quote, null);
	const second = h.refresh();
	h.state.selectedId = 0;
	h.refresh();
	h.requests[1].resolve(cateringQuote(56));
	await second;
	assert.equal(h.state.quote, null);
	assert.equal(h.state.quoteStatus, 'idle');
	assert.equal(h.requests.length, 2, 'custom selection does not send a quote request');
});

test('catering selection updates only summary and card states without replacing form inputs', () => {
	const state = { selectedId: 1, guests: 22, orderType: 'delivery' };
	const summary = {};
	const cards = [1, 2].map(id => ({ getAttribute: () => String(id), classList: { toggle: (key, value) => { cards[id - 1].selected = value; } }, setAttribute: (key, value) => { cards[id - 1][key] = value; } }));
	let refreshed = 0;
	const root = {
		querySelector: selector => { assert.equal(selector, '.dbc-selected'); return summary; },
		querySelectorAll: selector => { assert.equal(selector, '[data-pick]'); return cards; }
	};
	Object.defineProperty(root, 'innerHTML', { set: () => assert.fail('selection must not replace the form DOM') });
	const select = extractFunction(catering, 'selectPackage', {
		state, root, selectedPackage: () => ({ name: 'Synthetic package', price: 72 }), esc: value => value,
		money: value => '$' + value, refreshQuote: () => { refreshed++; }
	});
	select(2);
	assert.equal(refreshed, 1);
	assert.equal(state.selectedId, 2);
	assert.equal(state.guests, 22);
	assert.equal(state.orderType, 'delivery');
	assert.equal(cards[0]['aria-pressed'], 'false');
	assert.equal(cards[1]['aria-pressed'], 'true');
});

test('catering estimate states never render old totals while pending or unavailable', () => {
	const state = { quoteStatus: 'loading', quote: null, orderType: 'pickup' };
	const box = { setAttribute: (name, value) => { box[name] = value; } };
	const form = { querySelector: () => box };
	const paint = extractFunction(catering, 'updateQuoteBox', { state, esc: value => value });
	paint(form);
	assert.match(box.innerHTML, /Updating estimate/);
	assert.equal(box['aria-busy'], 'true');
	state.quoteStatus = 'error';
	paint(form);
	assert.match(box.innerHTML, /Estimate unavailable/);
	assert.equal(box['aria-busy'], 'false');
	state.quoteStatus = 'ready';
	state.quote = cateringQuote(72);
	state.quote.currency = ' aUd ';
	paint(form);
	assert.match(box.innerHTML, /AUD 72\.00/);
	assert.doesNotMatch(box.innerHTML, /Updating estimate|Estimate unavailable/);
});

test('catering public reads use plain-permalink-safe URLs without stale nonces and reject HTTP failures', async () => {
	const url = extractFunction(catering, 'cateringRequestUrl', { API: 'https://example.test/?rest_route=/doughboss/v1' });
	assert.equal(url('/catering/quote?package_id=1&guest_count=12'), 'https://example.test/?rest_route=/doughboss/v1/catering/quote&package_id=1&guest_count=12');
	let requested;
	let successful = true;
	const get = extractFunction(catering, 'get', {
		cateringRequestUrl: url, setTimeout, clearTimeout,
		fetch: (requestUrl, options) => { requested = { requestUrl, options }; return Promise.resolve({ ok: successful, json: () => Promise.resolve(cateringQuote(56)) }); }
	});
	assert.equal((await get('/catering/quote?package_id=1')).total, 56);
	assert.equal(requested.options.cache, 'no-store');
	assert.equal(requested.options.credentials, 'same-origin');
	assert.equal(requested.options.headers, undefined);
	successful = false;
	await assert.rejects(get('/catering/packages'), /Catering request failed/);
});

test('catering reads time out even without browser abort support', async () => {
	let timeout;
	let cleared;
	const get = extractFunction(catering, 'get', {
		cateringRequestUrl: value => value,
		setTimeout: (callback, delay) => { assert.equal(delay, 8000); timeout = callback; return 7; },
		clearTimeout: handle => { cleared = handle; },
		fetch: () => new Promise(() => {})
	});
	const pending = get('/catering/quote');
	timeout();
	await assert.rejects(pending, /timed out/);
	assert.equal(cleared, 7);
});

const cateringLocationId = extractFunction(catering, 'cateringLocationId');
const syntheticShops = [{ id: 1, name: 'Synthetic shop A' }, { id: 2, name: 'Synthetic shop B' }];

test('header initial selection preserves early form events when storage is blocked', () => {
	const choose = extractFunction(shopStatus, 'chosenId', {
		latestLocationId: 2,
		locationById: (locations, id) => locations.find(location => location.id === id),
		window: { localStorage: { getItem: () => { throw new Error('Storage blocked'); } } }
	});
	assert.equal(choose(syntheticShops), 2);
	assert.equal(choose([syntheticShops[0]]), 1, 'an event cannot select a shop missing from the header response');
});

function cateringLocationHarness(saved) {
	const state = { locations: [], locationId: 0, locationStatus: 'loading', requestedLocationId: null, locationLocked: false, submitting: false };
	const requests = [];
	const context = {
		state, cateringLocationId, root: { querySelector: () => ({}) }, updateCateringLocation: () => {},
		window: { localStorage: { getItem: () => { if (saved instanceof Error) throw saved; return saved; } } },
		get: url => new Promise((resolve, reject) => requests.push({ url, resolve, reject }))
	};
	const load = extractFunction(catering, 'loadCateringLocations', context);
	const apply = extractFunction(catering, 'applyCateringLocation', context);
	return { state, requests, load, apply };
}

test('catering location identifiers reject malformed values', () => {
	for (const value of [null, undefined, true, {}, '', '2.5', '1e2', '-2', 2.5, Infinity, 9007199254740992]) assert.equal(cateringLocationId(value), 0);
	assert.equal(cateringLocationId('2'), 2);
	assert.equal(cateringLocationId(2), 2);
});

test('catering initial shop preference matches configured shops and storage is optional', async () => {
	for (const [saved, expected] of [['2', 2], [null, 1], [new Error('Storage blocked'), 1], ['99', 0]]) {
		const h = cateringLocationHarness(saved);
		const pending = h.load();
		h.requests[0].resolve(syntheticShops);
		h.requests[1].resolve({ active: false });
		await pending;
		assert.equal(h.state.locationStatus, 'ready');
		assert.equal(h.state.locationId, expected, 'a stale explicit preference must not silently select another shop');
	}
});

test('header changes before and after shop loading remain current without local storage', async () => {
	const h = cateringLocationHarness(new Error('Storage blocked'));
	const pending = h.load();
	h.apply(2);
	h.requests[0].resolve(syntheticShops);
	h.requests[1].resolve({ active: false });
	await pending;
	assert.equal(h.state.locationId, 2);
	h.apply(1);
	assert.equal(h.state.locationId, 1);
	h.apply(99);
	assert.equal(h.state.locationId, 0, 'unknown event cannot route to a default shop');
});

test('shop loading failure recovers without changing the queued preference', async () => {
	const h = cateringLocationHarness('1');
	h.apply(2);
	const failed = h.load();
	h.requests[0].reject(new Error('Offline'));
	h.requests[1].resolve({ active: false });
	await failed;
	assert.equal(h.state.locationStatus, 'error');
	const retry = h.load();
	h.requests[2].resolve(syntheticShops);
	h.requests[3].resolve({ active: false });
	await retry;
	assert.equal(h.state.locationStatus, 'ready');
	assert.equal(h.state.locationId, 2);
});

test('verified empty shops retain legacy custom capture; invalid table context fails closed', async () => {
	for (const [shops, table, expected, locked, id] of [
		[[], { active: false }, 'ready', false, 0],
		[syntheticShops, { active: true, location: { id: 2 }, table: { label: 'Synthetic table' } }, 'ready', true, 2],
		[syntheticShops, { active: true, location: { id: 99 }, table: {} }, 'error', true, 0],
		[syntheticShops, {}, 'error', false, 0]
	]) {
		const h = cateringLocationHarness(table.active ? '99' : '1');
		const pending = h.load();
		h.requests[0].resolve(shops);
		h.requests[1].resolve(table);
		await pending;
		assert.equal(h.state.locationStatus, expected);
		assert.equal(h.state.locationLocked, locked);
		assert.equal(h.state.locationId, id);
	}
});

test('pending catering or signed table context vetoes sitewide shop changes', () => {
	const state = { submitting: false, locationLocked: false };
	let vetoes = 0;
	const guard = extractFunction(catering, 'guardCateringShopChange', { state });
	const event = { preventDefault: () => { vetoes++; } };
	guard(event);
	assert.equal(vetoes, 0);
	state.submitting = true;
	guard(event);
	state.submitting = false;
	state.locationLocked = true;
	guard(event);
	assert.equal(vetoes, 2);
});

test('shop UI changes only its controls, keeping other form values untouched', () => {
	const state = { locations: syntheticShops, locationId: 2, locationStatus: 'ready', submitting: false };
	const select = {};
	const note = {};
	const retry = {};
	const form = { querySelector: selector => ({ '[name="location_id"]': select, '.dbc-location-note': note, '[data-catering-locations-retry]': retry })[selector] };
	Object.defineProperty(form, 'innerHTML', { set: () => assert.fail('shop change must not rebuild enquiry inputs') });
	const selected = extractFunction(catering, 'selectedCateringLocation', { state });
	const update = extractFunction(catering, 'updateCateringLocation', { state, selectedCateringLocation: selected, esc: value => value });
	update(form);
	assert.equal(select.value, '2');
	assert.match(note.textContent, /Synthetic shop B/);
	state.submitting = true;
	state.submittedLocationName = 'Synthetic shop B';
	update(form);
	assert.equal(select.disabled, true);
	assert.match(note.textContent, /Sending enquiry for Synthetic shop B/);
});

test('enquiry submits the exact shop and form snapshot once, retaining fields after server errors', async () => {
	const state = { locations: syntheticShops, locationId: 2, locationStatus: 'ready', submitting: false, selectedId: 9, orderType: 'delivery' };
	const values = { customer_name: 'Synthetic customer', customer_email: 'synthetic@example.invalid', customer_phone: '0400000000', guest_count: '12', event_date: '2099-10-10', event_time: '12:30', address: 'Synthetic venue', dietary: 'Synthetic dietary note', notes: 'Retain this note', hp: '' };
	const error = {};
	const button = { textContent: 'Request booking & quote', disabled: false };
	const form = { classList: { contains: () => true }, querySelector: selector => selector === '.dbc-error' ? error : button };
	const sends = [];
	const selected = extractFunction(catering, 'selectedCateringLocation', { state });
	const submit = extractFunction(catering, 'submitCateringEnquiry', {
		state, selectedCateringLocation: selected, updateCateringLocation: () => {},
		FormData: function () { this.get = key => values[key]; },
		post: (url, body) => new Promise(resolve => sends.push({ url, body, resolve })), showSuccess: () => assert.fail('failed response cannot show success')
	});
	const event = { target: form, preventDefault: () => {} };
	const pending = submit(event);
	submit(event);
	assert.equal(sends.length, 1);
	assert.equal(sends[0].url, '/catering/enquiry');
	assert.equal(sends[0].body.location_id, 2);
	for (const field of ['customer_phone', 'event_date', 'event_time', 'address', 'dietary', 'notes']) assert.equal(sends[0].body[field], values[field]);
	assert.equal(state.submittedLocationName, 'Synthetic shop B');
	values.notes = 'Edited while pending';
	values.event_time = '13:45';
	assert.equal(sends[0].body.notes, 'Retain this note', 'in-flight payload does not reread edited fields');
	assert.equal(sends[0].body.event_time, '12:30');
	sends[0].resolve({ ok: false, data: { message: 'That catering package is no longer available.' } });
	await pending;
	assert.equal(state.submitting, false);
	assert.equal(button.disabled, false);
	assert.match(error.textContent, /package is no longer available/);
	assert.equal(values.notes, 'Edited while pending', 'failure preserves the current form rather than restoring old inputs');
	state.locationId = 0;
	submit(event);
	assert.equal(sends.length, 1, 'unavailable location must not POST legacy zero');
	assert.match(error.textContent, /Choose an available shop/);
});

const themeScript = path.resolve(__dirname, '..', 'themes', 'doughboss-final', 'assets', 'theme.js');

// Minimal attribute/tree fixtures for the existing theme helpers, not a browser.
function themeNode(attributes, children) {
	const attrs = Object.assign({}, attributes);
	const classes = new Set();
	const element = {
		children: children || [],
		getAttribute: name => Object.hasOwn(attrs, name) ? attrs[name] : null,
		setAttribute: (name, value) => { attrs[name] = String(value); },
		removeAttribute: name => { delete attrs[name]; },
		classList: {
			add: name => classes.add(name), remove: name => classes.delete(name), contains: name => classes.has(name),
			toggle: (name, on) => { if (on) classes.add(name); else classes.delete(name); }
		},
		contains: target => target === element || element.children.some(child => child.contains(target))
	};
	return element;
}

function themeNavigationHarness() {
	const frames = [];
	const toggle = themeNode({ 'aria-expanded': 'false' });
	const link = themeNode();
	const nav = themeNode({ 'aria-hidden': 'true' }, [link]);
	const priorInert = themeNode({ inert: 'inert', 'aria-hidden': 'false' });
	const priorHidden = themeNode({ 'aria-hidden': 'true' });
	const main = themeNode();
	const headerInner = themeNode({}, [toggle, nav, priorInert]);
	const header = themeNode({}, [headerInner]);
	const body = themeNode({}, [header, main, priorHidden]);
	const document = { body, documentElement: themeNode({}, [body]), activeElement: toggle };
	const context = {
		document, toggle, nav, mobileQuery: { matches: true }, isolatedNavigationBackground: [], menuReturnFocus: toggle,
		menuFocusRevision: 0, focusableMenuItems: () => [link],
		window: { requestAnimationFrame: callback => frames.push(callback), setTimeout: callback => frames.push(callback) }
	};
	for (const name of ['menuOpen', 'navigationIsolationTargets', 'isolateNavigationBackground', 'restoreNavigationBackground', 'queueNavigationInitialFocus', 'openMenu', 'closeMenu', 'syncMenuMode']) {
		context[name] = extractFunction(themeScript, name, context);
	}
	link.focus = () => {
		assert.equal(nav.getAttribute('aria-hidden'), null, 'navigation is exposed before focus enters it');
		assert.equal(toggle.getAttribute('inert'), null, 'move focus before hiding the previously focused trigger');
		document.activeElement = link;
	};
	toggle.focus = () => {
		assert.equal(toggle.getAttribute('inert'), null, 'restore background before returning keyboard focus');
		document.activeElement = toggle;
	};
	return { context, document, toggle, nav, link, priorInert, priorHidden, main, header, headerInner, frames };
}

test('mobile navigation isolates only background branches and restores exact prior attributes', () => {
	const h = themeNavigationHarness();
	const c = h.context;
	const targets = c.navigationIsolationTargets(h.document.body, h.nav);
	assert.deepEqual(Array.from(targets), [h.toggle, h.priorInert, h.main, h.priorHidden]);
	c.openMenu();
	assert.equal(h.document.activeElement, h.toggle, 'focus waits until the newly visible drawer reaches layout');
	assert.equal(h.main.getAttribute('inert'), null, 'background waits for focus to enter the drawer');
	h.frames.shift()();
	assert.equal(h.document.activeElement, h.link);
	for (const target of targets) {
		assert.equal(target.getAttribute('inert'), '');
		assert.equal(target.getAttribute('aria-hidden'), 'true');
	}
	for (const visible of [h.header, h.headerInner, h.nav, h.link]) assert.equal(visible.getAttribute('inert'), null);
	c.isolateNavigationBackground();
	c.closeMenu(true);
	assert.equal(h.document.activeElement, h.toggle);
	assert.equal(h.toggle.getAttribute('aria-expanded'), 'false');
	assert.equal(h.nav.getAttribute('aria-hidden'), 'true');
	assert.equal(h.document.body.classList.contains('dbf-menu-open'), false);
	assert.equal(h.main.getAttribute('inert'), null);
	assert.equal(h.main.getAttribute('aria-hidden'), null);
	assert.equal(h.priorInert.getAttribute('inert'), 'inert');
	assert.equal(h.priorInert.getAttribute('aria-hidden'), 'false');
	assert.equal(h.priorHidden.getAttribute('aria-hidden'), 'true');
	assert.equal(h.priorHidden.getAttribute('inert'), null);
	c.restoreNavigationBackground();
	assert.equal(h.priorInert.getAttribute('inert'), 'inert', 'repeated restoration is harmless');
});

test('desktop breakpoint releases mobile isolation without hiding navigation or moving focus', () => {
	const h = themeNavigationHarness();
	h.context.openMenu();
	h.frames.shift()();
	h.context.mobileQuery.matches = false;
	h.context.syncMenuMode();
	assert.equal(h.nav.getAttribute('aria-hidden'), null);
	assert.equal(h.main.getAttribute('inert'), null);
	assert.equal(h.priorInert.getAttribute('inert'), 'inert');
	assert.equal(h.document.activeElement, h.link);
	h.context.openMenu();
	assert.equal(h.main.getAttribute('inert'), null, 'desktop cannot reopen the mobile isolation path');
});

test('queued mobile navigation focus cannot jump into a closed or desktop drawer', () => {
	const closed = themeNavigationHarness();
	closed.context.openMenu();
	const afterClose = closed.frames.shift();
	closed.context.closeMenu(true);
	afterClose();
	assert.equal(closed.document.activeElement, closed.toggle);
	assert.equal(closed.main.getAttribute('inert'), null);
	assert.equal(closed.nav.getAttribute('aria-hidden'), 'true');

	const desktop = themeNavigationHarness();
	desktop.context.openMenu();
	const afterBreakpoint = desktop.frames.shift();
	desktop.context.mobileQuery.matches = false;
	desktop.context.syncMenuMode();
	afterBreakpoint();
	assert.equal(desktop.document.activeElement, desktop.toggle);
	assert.equal(desktop.main.getAttribute('inert'), null);
	assert.equal(desktop.nav.getAttribute('aria-hidden'), null);
});

test('runtime reduced-motion changes disconnect reveals and late callbacks remain safe', () => {
	const reveal = themeNode({ 'data-dbf-scroll-state': 'below' });
	const observers = [];
	function Observer(callback) {
		this.callback = callback;
		this.observe = element => { this.observed = element; };
		this.unobserve = element => { this.unobserved = element; };
		this.disconnect = () => { this.disconnected = true; };
		observers.push(this);
	}
	const context = {
		motionQuery: { matches: false }, reveals: [reveal], revealObserver: null,
		document: { documentElement: themeNode() }, window: { IntersectionObserver: Observer }, IntersectionObserver: Observer
	};
	for (const name of ['showAllReveals', 'stopRevealObserver', 'startRevealObserver', 'syncMotionPreference']) context[name] = extractFunction(themeScript, name, context);
	context.syncMotionPreference();
	assert.equal(observers.length, 1);
	assert.equal(observers[0].observed, reveal);
	context.motionQuery.matches = true;
	context.syncMotionPreference();
	assert.equal(observers[0].disconnected, true);
	assert.equal(context.revealObserver, null);
	assert.equal(context.document.documentElement.classList.contains('dbf-motion-ok'), false);
	assert.equal(reveal.classList.contains('is-visible'), true);
	assert.equal(reveal.getAttribute('data-dbf-scroll-state'), null);
	assert.doesNotThrow(() => observers[0].callback([{ target: reveal, isIntersecting: true }]));
	context.motionQuery.matches = false;
	context.syncMotionPreference();
	assert.equal(observers.length, 2);
	assert.equal(reveal.classList.contains('is-visible'), true, 'relaxing the preference never hides previously visible content');
	delete context.window.IntersectionObserver;
	context.syncMotionPreference();
	assert.equal(context.revealObserver, null);
	assert.equal(reveal.classList.contains('is-visible'), true, 'unsupported observers retain readable content');
});

test('ordering presentation has opaque tools, static reduced motion and no retired card-motion path', () => {
	const css = fs.readFileSync(path.resolve(__dirname, '..', 'public', 'css', 'doughboss.css'), 'utf8');
	const orderCss = fs.readFileSync(path.resolve(__dirname, '..', 'public', 'css', 'doughboss-order-page.css'), 'utf8');
	const themeCss = fs.readFileSync(path.resolve(__dirname, '..', 'themes', 'doughboss-final', 'style.css'), 'utf8');
	const reducedMotion = themeCss.slice(themeCss.lastIndexOf('@media (prefers-reduced-motion: reduce)'));
	// A tiny duration on the default transition-property:all can delay inherited
	// visibility and reject the drawer's first-frame focus. Browser proof is separate.
	assert.match(reducedMotion, /\*, \*::before, \*::after\s*\{[^}]*transition:\s*none\s*!important;/);
	assert.doesNotMatch(reducedMotion, /transition-duration:\s*\.01ms/);
	const assets = fs.readFileSync(path.resolve(__dirname, '..', 'includes', 'class-doughboss-assets.php'), 'utf8');
	const source = fs.readFileSync(storefront, 'utf8');
	assert.match(css, /\.db-app \.db-menu-tools\s*\{[^}]*background: var\(--db-paper, #f7f5f0\);/);
	assert.match(orderCss, /body\.doughboss-order-page \.db-menu-tools\s*\{[^}]*background: var\(--db-order-paper\);/);
	assert.doesNotMatch(css, /backdrop-filter|db-cardin|--db-spring/);
	assert.doesNotMatch(orderCss, /db-order-page-motion|data-db-scroll|rotateX/);
	assert.doesNotMatch(source, /setProperty\('--db-i'/);
	assert.doesNotMatch(assets, /public\/js\/doughboss-order-page\.js/);
	assert.match(assets, /public\/css\/doughboss-order-page\.css/, 'order-page compatibility styles remain enqueued');
	assert.equal(fs.existsSync(path.resolve(__dirname, '..', 'public', 'js', 'doughboss-order-page.js')), false);
});

test('paused client notice uses neutral copy and preserves configured messages', () => {
	const render = extractFunction(storefront, 'orderingClosedNotice', {
		I18N: {}, el: (tag, attrs, children) => ({ tag, attrs, children })
	});
	const defaultNotice = render('');
	assert.equal(defaultNotice.children[0].attrs.text, 'Online ordering is paused');
	assert.equal(defaultNotice.children[1].attrs.text, 'Browse the menu and check your preferred shop before visiting.');
	assert.equal(render('Kitchen maintenance until Friday.').children[1].attrs.text, 'Kitchen maintenance until Friday.');
	const assets = fs.readFileSync(path.resolve(__dirname, '..', 'includes', 'class-doughboss-assets.php'), 'utf8');
	assert.match(assets, /'comingSoonShort' => __\( 'Ordering paused'/, 'existing localization key stays compatible');
	assert.match(assets, /'orderingComingSoon' => __\( 'Online ordering is paused'/);
	assert.doesNotMatch(fs.readFileSync(storefront, 'utf8'), /'Coming soon'|'Online ordering coming soon'/);
});

function previewAdapter(paused) {
	const window = { DoughBossPreviewOptions: { menu: [{ id: 1001, image: 'public/images/menu/real-v1/zaatar-cheese.jpg' }] } };
	const listeners = {};
	const document = {
		currentScript: { src: 'https://example.test/DOUGHBOSSV2/preview/preview-api.js' },
		baseURI: 'https://example.test/DOUGHBOSSV2/order.html',
		body: { classList: { contains: name => paused && name === 'dbf-ordering-paused' } },
		addEventListener: (type, handler) => { listeners[type] = handler; },
		getElementById: () => null
	};
	vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '..', 'scripts/visual-preview/preview-api.js'), 'utf8'), {
		window, document, URL, Request, Response, Date
	});
	return { window, listeners };
}

test('visual preview permits only four synthetic reads and rejects every write or external route', async () => {
	const { window, listeners } = previewAdapter(false);
	const apiRoot = 'https://preview.invalid/doughboss/v1';
	for (const route of ['/config', '/locations', '/table/context', '/menu']) {
		assert.equal((await window.fetch(apiRoot + route)).status, 200, route);
	}
	for (const method of ['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD']) {
		assert.equal((await window.fetch(apiRoot + '/config', { method })).status, 405, method);
	}
	assert.equal((await window.fetch(new Request(apiRoot + '/config', { method: 'POST' }))).status, 405, 'Request method cannot become GET');
	for (const url of [apiRoot + '/cart', apiRoot + '/checkout', apiRoot + '/catering/enquiry']) {
		assert.equal((await window.fetch(url)).status, 404, url);
	}
	assert.equal((await window.fetch('https://preview.invalid/config')).status, 403, 'paths outside the exact API prefix are forbidden');
	assert.equal((await window.fetch('https://doughboss.com.au/wp-json/doughboss/v1/config')).status, 403);
	assert.equal((await window.fetch('https://api.stripe.com/v1/checkout/sessions')).status, 403);
	const config = await (await window.fetch(apiRoot + '/config')).json();
	assert.equal(config.payments_enabled, false);
	assert.equal(config.stripe_pk, '');
	assert.equal(config.ordering_open, true, 'sample open state only');
	assert.equal(window.DoughBossData.payments.enabled, false);
	let prevented = false;
	listeners.submit({ preventDefault: () => { prevented = true; } });
	assert.equal(prevented, true);
});

test('visual preview keeps paused state separate and sample hours short-lived', async () => {
	const { window } = previewAdapter(true);
	const apiRoot = 'https://preview.invalid/doughboss/v1';
	assert.equal((await (await window.fetch(apiRoot + '/config')).json()).ordering_open, false);
	const locations = await (await window.fetch(apiRoot + '/locations')).json();
	assert.equal(locations.length, 2);
	for (const location of locations) {
		assert.match(location.name, /^Sample /);
		assert.equal(location.phone, '');
		const status = location.pickup_status;
		assert.equal(Date.parse(status.expires_at_utc) - Date.parse(status.observed_at_utc), 55000);
	}
	const menu = await (await window.fetch(apiRoot + '/menu')).json();
	assert.equal(menu[0].image, 'https://example.test/DOUGHBOSSV2/public/images/menu/real-v1/zaatar-cheese.jpg');
});

console.log('Storefront helper regression checks passed.');
