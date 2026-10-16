'use strict';

// 2.51.0 storefront: store-first chooser, pickup picker and checkout wiring.
// Pure helpers are extracted by name (as frontend-upgrade.test.js does); the
// picker itself runs against a tiny fake DOM so the off-mode guarantees (renders
// nothing, sends nothing) are exercised for real.
// Run with: node tests/pickup-storefront-2-51.test.js

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const storefront = path.join(root, 'public', 'js', 'doughboss.js');
const square = path.join(root, 'public', 'js', 'doughboss-square.js');
const storefrontSource = fs.readFileSync(storefront, 'utf8');
const squareSource = fs.readFileSync(square, 'utf8');

function extractFunction(file, name, context) {
	const source = fs.readFileSync(file, 'utf8');
	const start = source.indexOf('function ' + name + '(');
	assert.notEqual(start, -1, name + ' must exist in ' + path.basename(file));
	let depth = 0;
	let started = false;
	for (let index = start; index < source.length; index += 1) {
		if (source[index] === '{') { started = true; depth += 1; }
		else if (source[index] === '}' && started) {
			depth -= 1;
			if (depth === 0) return vm.runInNewContext('(' + source.slice(start, index + 1) + ')', context || {});
		}
	}
	throw new Error(name + ' has no complete body');
}

function functionSource(file, name) {
	const source = fs.readFileSync(file, 'utf8');
	const start = source.indexOf('function ' + name + '(');
	assert.notEqual(start, -1, name + ' must exist');
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

const plain = value => JSON.parse(JSON.stringify(value));

/* ------------------------------------------------------------------ */
/* Store-first                                                         */
/* ------------------------------------------------------------------ */

const shops = [{ id: 1, name: 'Revesby' }, { id: 2, name: 'Bankstown' }, { id: 3, name: 'Roselands' }];

test('storeChoiceRequired: several shops and nothing stored needs a choice', () => {
	const required = extractFunction(storefront, 'storeChoiceRequired');
	assert.equal(required(shops, 0), true, '3 shops, no storage');
	assert.equal(required(shops, null), true);
	assert.equal(required(shops, 99), true, 'a stored shop that no longer exists is not a choice');
	assert.equal(required(shops, 2), false, 'a stored, valid shop is a choice');
	assert.equal(required([shops[0]], 0), false, 'one shop never needs a choice');
	assert.equal(required([], 0), false);
	assert.equal(required(undefined, 0), false);
});

test('explicitLocationId is 0 until a valid shop is stored and tolerates blocked storage', () => {
	const locById = extractFunction(storefront, 'locById');
	const make = storedId => extractFunction(storefront, 'explicitLocationId', { storedLocationId: () => storedId, locById });
	assert.equal(make(0)(shops), 0);
	assert.equal(make(2)(shops), 2);
	assert.equal(make(77)(shops), 0);
	const stored = extractFunction(storefront, 'storedLocationId', { Number, window: { localStorage: { getItem: () => { throw new Error('blocked'); } } } });
	assert.equal(stored(), 0, 'storage access is wrapped in try/catch');
});

test('storeStatusBadge maps the public pickup_status states', () => {
	const badge = extractFunction(storefront, 'storeStatusBadge');
	assert.equal(badge({ pickup_status: { state: 'open' } }).state, 'open');
	assert.equal(badge({ pickup_status: { state: 'closes_soon' } }).state, 'soon');
	assert.equal(badge({ pickup_status: { state: 'closed' } }).label, 'Closed');
	assert.equal(badge({ pickup_status: { state: 'open' }, ordering_paused: true }).state, 'paused');
	assert.equal(badge({}).state, 'unknown');
	assert.equal(badge(null).state, 'unknown');
});

test('revalidateMessage names what was removed and is empty otherwise', () => {
	const message = extractFunction(storefront, 'revalidateMessage', { Array, String });
	assert.equal(message([]), '');
	assert.equal(message(undefined), '');
	assert.equal(message(['', '  ']), '');
	assert.match(message(['Garlic Pide']), /^Garlic Pide isn.t available at this shop and was removed/);
	const many = message(['Garlic Pide', 'Lamb Wrap']);
	assert.match(many, /Garlic Pide, Lamb Wrap aren.t available/);
	assert.match(many, /were removed/);
});

test('the shop-change door confirms, re-validates the cart, then switches and honours the payment lock', async () => {
	const calls = [];
	let confirmed = true;
	const make = (overrides = {}) => extractFunction(storefront, 'requestShopChange', Object.assign({
		Number, Promise, I18N: {},
		paymentMutationAllowed: () => true,
		storedLocationId: () => 1,
		lastCartItemCount: 2,
		window: { confirm: () => confirmed },
		request: (route, options) => { calls.push(['request', route, options.body]); return Promise.resolve({ removed: ['Garlic Pide'] }); },
		setLocation: id => calls.push(['setLocation', id]),
		notifyCartChanged: () => calls.push(['notify']),
		dbToast: text => calls.push(['toast', text]),
		revalidateMessage: names => 'removed ' + names.join(',')
	}, overrides));
	assert.equal(await make()(2), true);
	assert.deepEqual(plain(calls), [
		['request', '/cart/revalidate', { location_id: 2 }],
		['setLocation', 2],
		['notify'],
		['toast', 'removed Garlic Pide']
	]);
	calls.length = 0;
	confirmed = false;
	assert.equal(await make()(2), false, 'declining the confirmation changes nothing');
	assert.deepEqual(calls, []);
	assert.equal(await make({ paymentMutationAllowed: () => false })(2), false, 'locked payment keeps the shop');
	assert.deepEqual(calls, []);
	confirmed = true;
	assert.equal(await make({ storedLocationId: () => 2 })(2), true, 'choosing the current shop is a no-op');
	assert.deepEqual(calls, []);
	// A failed re-validation must not strand the customer on the old shop.
	assert.equal(await make({ request: () => Promise.reject(new Error('404')) })(2), true);
	assert.deepEqual(plain(calls), [['setLocation', 2]]);
});

/* ------------------------------------------------------------------ */
/* Pure pickup helpers                                                 */
/* ------------------------------------------------------------------ */

test('pickupSlotLabel names a slot and marks full ones', () => {
	const label = extractFunction(storefront, 'pickupSlotLabel');
	assert.equal(label({ label: '12:45pm', available: true }), '12:45pm');
	assert.equal(label({ label: '12:45pm', available: false }), '12:45pm, full');
	assert.equal(label({ local_from: '12:45' }), '12:45');
	assert.equal(label({}), '');
	assert.equal(label(null), '');
});

test('pickupEtaLabel prefers the server label, else builds one from the ETA', () => {
	const eta = extractFunction(storefront, 'pickupEtaLabel', { Math, Number });
	assert.equal(eta({ label: 'ASAP — ready in about 25 min', eta_minutes: 25 }), 'ASAP — ready in about 25 min');
	assert.equal(eta({ eta_minutes: 18 }), 'ASAP — ready in about 18 min');
	assert.equal(eta({ eta_minutes: 0 }), '');
	assert.equal(eta(null), '');
});

test('pickupCountdownLabel counts down and reports expiry', () => {
	const countdown = extractFunction(storefront, 'pickupCountdownLabel', { Number, Math, isFinite });
	assert.equal(countdown(9 * 60000 + 42000), 'Held for 9:42');
	assert.equal(countdown(5000), 'Held for 0:05');
	assert.equal(countdown(0), 'Hold expired');
	assert.equal(countdown(-1000), 'Hold expired');
	assert.equal(countdown(NaN), 'Hold expired');
});

test('pickupSelectionValid is false after expires_at_utc and for malformed selections', () => {
	const valid = extractFunction(storefront, 'pickupSelectionValid', { Date, isFinite, String });
	const token = 'a'.repeat(64);
	const now = Date.parse('2026-10-16T01:00:00Z');
	const live = { mode: 'scheduled', hold_token: token, expires_at_utc: '2026-10-16T01:10:00Z' };
	assert.equal(valid(live, now), true);
	assert.equal(valid(live, Date.parse('2026-10-16T01:10:00Z')), false, 'expires exactly at the boundary');
	assert.equal(valid(live, Date.parse('2026-10-16T01:10:01Z')), false, 'false after expiry');
	assert.equal(valid(Object.assign({}, live, { mode: 'later' }), now), false);
	assert.equal(valid(Object.assign({}, live, { hold_token: 'short' }), now), false);
	assert.equal(valid(Object.assign({}, live, { expires_at_utc: 'soon' }), now), false);
	assert.equal(valid(null, now), false);
});

test('pickupHiddenFields exposes exactly the three checkout field names', () => {
	const fields = extractFunction(storefront, 'pickupHiddenFields', { String, Number });
	const selection = { mode: 'asap', slot_id: 123, hold_token: 'b'.repeat(64) };
	const out = plain(fields(selection));
	assert.deepEqual(Object.keys(out).sort(), ['capacity_hold_token', 'pickup_mode', 'pickup_slot_id']);
	assert.deepEqual(out, { pickup_mode: 'asap', pickup_slot_id: '123', capacity_hold_token: 'b'.repeat(64) });
	assert.deepEqual(plain(fields(null)), { pickup_mode: '', pickup_slot_id: '', capacity_hold_token: '' }, 'no selection = nothing to render or send');
	assert.deepEqual(plain(fields({})), { pickup_mode: '', pickup_slot_id: '', capacity_hold_token: '' });
});

test('pickupSelectionFromHold normalises the /pickup/hold response', () => {
	const fromHold = extractFunction(storefront, 'pickupSelectionFromHold', { String, Number });
	const res = {
		hold_token: 'c'.repeat(64), slot_id: 55, expires_at_utc: '2026-10-16T01:10:00Z',
		ready_from_utc: '2026-10-16T01:45:00Z', ready_by_utc: '2026-10-16T02:00:00Z',
		label: 'Today 12:45pm', eta_minutes: 25, pickup_mode: 'asap'
	};
	assert.deepEqual(plain(fromHold(res, 'scheduled', null)), {
		mode: 'asap', slot_id: 55, hold_token: 'c'.repeat(64), expires_at_utc: '2026-10-16T01:10:00Z',
		ready_from_utc: '2026-10-16T01:45:00Z', ready_by_utc: '2026-10-16T02:00:00Z', label: 'Today 12:45pm', eta_minutes: 25
	});
	assert.equal(fromHold({ hold_token: 'nope' }, 'asap', null), null);
	assert.equal(fromHold(null, 'asap', null), null);
	assert.equal(fromHold({ hold_token: 'c'.repeat(64) }, 'asap', { ready_from_utc: 'X' }).mode, 'asap', 'falls back to the requested mode');
});

test('pickupDefaultDay prefers the held day, else the first day with room', () => {
	const pick = extractFunction(storefront, 'pickupDefaultDay', { Array, String });
	const days = [
		{ date: '2026-10-16', slots: [{ ready_from_utc: 'A', available: false }] },
		{ date: '2026-10-17', slots: [{ ready_from_utc: 'B', available: true }] }
	];
	assert.equal(pick(days, null), '2026-10-17', 'today is full, so land on tomorrow');
	assert.equal(pick(days, { mode: 'scheduled', ready_from_utc: 'A' }), '2026-10-16');
	assert.equal(pick([{ date: '2026-10-16', slots: [] }], null), '2026-10-16');
	assert.equal(pick([], null), '');
});

test('pickupErrorMessage maps server codes and never trusts prototype names', () => {
	const message = extractFunction(storefront, 'pickupErrorMessage', { Object, String });
	assert.match(message('doughboss_slot_full'), /filled up/);
	assert.match(message('doughboss_hold_expired'), /expired/);
	assert.match(message('doughboss_location_paused'), /paused/);
	assert.equal(message('doughboss_other', 'Server said no'), 'Server said no');
	assert.equal(message('constructor', 'fallback'), 'fallback');
	assert.equal(message('', ''), '');
});

test('pickupSchedulingActive: nothing new unless cfg.pickup_scheduling, pickup, no table, ordering open', () => {
	const active = extractFunction(storefront, 'pickupSchedulingActive');
	const cfg = { pickup_scheduling: true, ordering_open: true };
	assert.equal(active(cfg, null, 'pickup'), true);
	assert.equal(active({ ordering_open: true }, null, 'pickup'), false, 'flag absent = current behaviour');
	assert.equal(active(Object.assign({}, cfg, { pickup_scheduling: false }), null, 'pickup'), false);
	assert.equal(active(cfg, null, 'delivery'), false);
	assert.equal(active(cfg, { active: true }, 'dine_in'), false, 'QR table orders never see it');
	assert.equal(active(Object.assign({}, cfg, { ordering_open: false }), null, 'pickup'), false);
	assert.equal(active(Object.assign({}, cfg, { staff_approval_required: true }), null, 'pickup'), false);
	assert.equal(active(null, null, 'pickup'), false);
});

test('confirmation and tracking text only appear for timed orders', () => {
	const confirm = extractFunction(storefront, 'pickupConfirmationText', { String });
	assert.equal(confirm({}), '');
	assert.equal(confirm({ pickup_mode: '' }), '');
	assert.equal(confirm({ pickup_mode: 'scheduled', pickup_label: 'Today 12:45pm', location_name: 'Dough Boss Revesby' }), 'Dough Boss Revesby, Today 12:45pm');
	assert.equal(confirm({ pickup_mode: 'asap', pickup_label: 'Today 12:45pm', location_name: 'Dough Boss Revesby' }), 'Dough Boss Revesby, ASAP · Today 12:45pm');
	assert.equal(confirm({ pickup_mode: 'asap' }), 'ASAP');
	assert.equal(confirm({ pickup_mode: 'scheduled', pickup_label: 'Tomorrow 7:30am' }), 'Tomorrow 7:30am');
	const track = extractFunction(storefront, 'pickupTrackingText', { String });
	assert.equal(track({ pickup_mode: '' }), '', 'legacy orders keep the staff estimate');
	assert.equal(track({ pickup_mode: 'scheduled', pickup_label: 'Today 12:45pm', location_name: 'Dough Boss Revesby' }), 'Pickup at Dough Boss Revesby, Today 12:45pm');
	assert.equal(track({ pickup_mode: 'asap', pickup_label: 'Today 12:45pm' }), 'Pickup, Today 12:45pm (ASAP)');
});

/* ------------------------------------------------------------------ */
/* Source invariants                                                   */
/* ------------------------------------------------------------------ */

test('payAndPlace and placeOrder (Square) carry the three pickup keys; the checkout form adds them to every /payment-intent body', () => {
	const pay = functionSource(square, 'payAndPlace');
	const place = functionSource(square, 'placeOrder');
	for (const key of ['pickup_mode', 'pickup_slot_id', 'capacity_hold_token']) {
		assert.ok(pay.includes("'" + key + "'"), 'payAndPlace sends ' + key);
		assert.ok(place.includes("'" + key + "'"), 'placeOrder sends ' + key);
		assert.ok(functionSource(storefront, 'pickupHiddenFields').includes(key), 'hidden fields include ' + key);
	}
	const form = functionSource(storefront, 'checkoutForm');
	assert.equal((form.match(/pickupBody\(payload\)/g) || []).length, 3, 'Square-hosted, Stripe-hosted and MPGS /payment-intent bodies');
	assert.match(functionSource(storefront, 'tyroConnectCheckout'), /s\.pickup/);
	assert.match(form, /Object\.assign\(payload, pickupPayloadFields\(\)\)/, 'the base payload carries the held time');
	assert.match(form, /type: 'hidden', name: fieldName/, 'hidden inputs are created only when a selection exists');
});

test('the picker is only mounted through pickupSchedulingActive and drives attempt keys', () => {
	assert.match(functionSource(storefront, 'renderCart'), /if \(pickupSchedulingActive\(cfg, tableContext, orderType\)\) \{\s*pickup\.show\(locationId\);/);
	const form = functionSource(storefront, 'checkoutForm');
	assert.match(form, /document\.addEventListener\('doughboss:pickup-changed', onPickupChanged\)/);
	assert.match(form, /paymentAttemptKey = newPaymentAttemptKey\(\);\s*checkoutAttemptId = null;/);
	assert.match(form, /pickup\.gate\(\)/, 'submit is gated on a held time');
	assert.match(squareSource, /form\.doughbossPickupGuard/, 'the Square card interceptor consults the same gate');
	assert.match(squareSource, /doughboss:pickup-changed/, 'unstarted Square sessions rotate their payment key');
});

test('storage access in the new code stays inside try/catch helpers', () => {
	const picker = functionSource(storefront, 'pickupPicker');
	assert.doesNotMatch(picker, /localStorage|sessionStorage/, 'the picker keeps its state in memory only');
	assert.match(functionSource(storefront, 'storedLocationId'), /try \{[\s\S]*DoughBossPreferences.readShop[\s\S]*\} catch/);
	assert.match(functionSource(storefront, 'setLocation'), /try \{[\s\S]*DoughBossPreferences.writeShop[\s\S]*\} catch/);
	assert.doesNotMatch(functionSource(storefront, 'storedLocationId') + functionSource(storefront, 'setLocation'), /localStorage/);
});

test('Square payAndPlace sends pickup fields when the form has them, and none otherwise', async () => {
	async function run(fields) {
		const requests = [];
		const payAndPlace = extractFunction(square, 'payAndPlace', {
			tokenizeTender: extractFunction(square, 'tokenizeTender'),
			I18N: {},
			orderTypeFor: () => 'pickup',
			storedLocationId: () => 12,
			verificationDetailsFor: () => ({ amount: '10.00' }),
			value: (node, name) => fields[name] || '',
			storeSession() {},
			request: (route, options) => { requests.push({ route, body: options.body }); return Promise.resolve({ payment_intent: 'sq-1' }); },
			placeOrder: () => Promise.resolve({ order_id: 1 })
		});
		await payAndPlace({
			form: {}, paymentAttemptKey: 'attempt-1', paymentId: '', providerRequestStarted: false,
			controller: { ready: () => Promise.resolve({ kind: 'card', card: { tokenize: () => Promise.resolve({ status: 'OK', token: 'cnon:x' }) } }) }
		});
		return requests[0].body;
	}
	const withPickup = await run({ pickup_mode: 'scheduled', pickup_slot_id: '55', capacity_hold_token: 'd'.repeat(64), customer_name: 'Ada' });
	assert.equal(withPickup.pickup_mode, 'scheduled');
	assert.equal(withPickup.pickup_slot_id, '55');
	assert.equal(withPickup.capacity_hold_token, 'd'.repeat(64));
	const without = await run({ customer_name: 'Ada' });
	for (const key of ['pickup_mode', 'pickup_slot_id', 'capacity_hold_token']) {
		assert.equal(Object.prototype.hasOwnProperty.call(without, key), false, 'mode off sends no ' + key);
	}
});

/* ------------------------------------------------------------------ */
/* The picker against a fake DOM                                       */
/* ------------------------------------------------------------------ */

function fakeDom() {
	const documentListeners = {};
	const dispatched = [];
	class FakeNode {
		constructor(tag) {
			this.tag = tag; this.children = []; this.attributes = {}; this.className = ''; this.ownText = '';
			this.hidden = false; this.disabled = false; this.listeners = {}; this.parentNode = null; this.value = '';
		}
		get textContent() { return this.ownText + this.children.map(child => child.textContent).join(''); }
		set textContent(value) { this.ownText = String(value); this.children.forEach(child => { child.parentNode = null; }); this.children = []; }
		set innerHTML(value) { this.children.forEach(child => { child.parentNode = null; }); this.children = []; this.ownText = ''; }
		get innerHTML() { return ''; }
		appendChild(child) {
			if (child.parentNode) child.parentNode.removeChild(child);
			child.parentNode = this; this.children.push(child); return child;
		}
		removeChild(child) {
			const index = this.children.indexOf(child);
			if (index >= 0) this.children.splice(index, 1);
			child.parentNode = null; return child;
		}
		setAttribute(key, value) { this.attributes[key] = String(value); }
		getAttribute(key) { return Object.prototype.hasOwnProperty.call(this.attributes, key) ? this.attributes[key] : null; }
		removeAttribute(key) { delete this.attributes[key]; }
		addEventListener(type, callback) { (this.listeners[type] = this.listeners[type] || []).push(callback); }
		click() { (this.listeners.click || []).forEach(callback => callback({})); }
		focus() {}
		contains(node) { return node === this || this.children.some(child => child.contains(node)); }
		all() { return [this].concat(...this.children.map(child => child.all())); }
		hasClass(name) { return this.className.split(/\s+/).includes(name); }
		querySelector(selector) {
			const pk = /^\[data-pk="(.+)"\]$/.exec(selector);
			if (pk) return this.all().find(node => node.getAttribute('data-pk') === pk[1]) || null;
			const parts = selector.split(',').map(part => part.trim());
			return this.all().find(node => parts.some(part => {
				const match = /^\.([\w-]+)(:not\(\[disabled\]\))?$/.exec(part);
				return match && node.hasClass(match[1]) && !(match[2] && node.disabled);
			})) || null;
		}
		find(predicate) { return this.all().filter(predicate); }
	}
	const document = {
		hidden: false, activeElement: null,
		createElement: tag => new FakeNode(tag),
		createTextNode: text => { const node = new FakeNode('#text'); node.ownText = String(text); return node; },
		addEventListener: (type, callback) => { (documentListeners[type] = documentListeners[type] || []).push(callback); },
		removeEventListener() {},
		dispatchEvent: event => { dispatched.push(event); (documentListeners[event.type] || []).forEach(callback => callback(event)); return true; }
	};
	return { document, dispatched, FakeNode };
}

function pickupHarness(routes) {
	const dom = fakeDom();
	const requests = [];
	const errors = [];
	const timers = [];
	class CustomEventStub { constructor(type, init) { this.type = type; this.detail = init && init.detail; } }
	const context = {
		document: dom.document, CustomEvent: CustomEventStub, Date, Math, Number, String, Object, Array, JSON, Promise, isFinite,
		I18N: {},
		paymentMutationLock: false,
		paymentMutationMessage: 'locked',
		el: null,
		request: (route, options) => {
			options = options || {};
			requests.push({ route, method: options.method || 'GET', body: options.body });
			const handler = routes(route, options);
			return handler instanceof Error ? Promise.reject(handler) : Promise.resolve(handler);
		},
		announceError: (node, message) => { errors.push(message); node.textContent = message; },
		clearError: node => { node.textContent = ''; },
		dbToast() {},
		newClientKey: () => 'key-' + requests.length + '-xxxxxxxxxxxx',
		setTimeout: callback => { timers.push(callback); return timers.length; },
		clearTimeout() {},
		setInterval: () => 1,
		clearInterval() {}
	};
	context.el = extractFunction(storefront, 'el', { document: dom.document });
	for (const name of ['pickupDefaultDay', 'pickupSelectionFromHold', 'pickupSelectionValid', 'pickupEtaLabel', 'pickupSlotLabel', 'pickupCountdownLabel', 'pickupSummaryText', 'pickupErrorMessage']) {
		context[name] = extractFunction(storefront, name, context);
	}
	const picker = extractFunction(storefront, 'pickupPicker', context)(() => 'Dough Boss Revesby');
	const settle = async () => { for (let i = 0; i < 6; i += 1) await new Promise(resolve => setImmediate(resolve)); };
	return { picker, requests, errors, timers, dom, settle, context };
}

const T0 = '2026-10-16T01:00:00Z';
function liveOptions(overrides = {}) {
	return Object.assign({
		mode: 'live', enforce: true, paused: false, closed_reason: '', hold_minutes: 10,
		asap: { ready_from_utc: '2026-10-16T01:30:00Z', ready_by_utc: '2026-10-16T01:45:00Z', eta_minutes: 25, label: 'ASAP — ready in about 25 min' },
		days: [
			{ date: '2026-10-16', label: 'Today', slots: [
				{ ready_from_utc: '2026-10-16T01:30:00Z', ready_by_utc: '2026-10-16T01:45:00Z', local_from: '12:30', local_by: '12:45', label: '12:30pm', available: false, remaining: 0 },
				{ ready_from_utc: '2026-10-16T01:45:00Z', ready_by_utc: '2026-10-16T02:00:00Z', local_from: '12:45', local_by: '13:00', label: '12:45pm', available: true, remaining: 4 }
			] },
			{ date: '2026-10-17', label: 'Tomorrow', slots: [
				{ ready_from_utc: '2026-10-16T20:30:00Z', ready_by_utc: '2026-10-16T20:45:00Z', local_from: '07:30', local_by: '07:45', label: '7:30am', available: true, remaining: 6 }
			] }
		]
	}, overrides);
}
function holdResponse(slot, token, mode) {
	return {
		hold_token: token, slot_id: 55, expires_at_utc: new Date(Date.now() + 10 * 60000).toISOString(),
		ready_from_utc: slot, ready_by_utc: slot, label: 'Today 12:45pm', eta_minutes: 25, pickup_mode: mode || 'scheduled'
	};
}

test('picker: mode off renders nothing, holds nothing and sends nothing but the options read', async () => {
	const h = pickupHarness(route => ({ mode: 'off', enforce: false, paused: false, days: [], asap: null, closed_reason: '' }));
	h.picker.show(7);
	await h.settle();
	assert.deepEqual(h.requests.map(r => r.method + ' ' + r.route), ['GET /pickup/options?location_id=7']);
	assert.equal(h.picker.element.hidden, true, 'nothing is rendered');
	assert.equal(h.picker.element.children.length, 0);
	assert.equal(h.picker.required(), false);
	assert.equal(h.picker.gate(), '', 'checkout is never blocked');
	assert.equal(h.picker.selection(), null);
	assert.deepEqual(h.dom.dispatched, [], 'no pickup event, so no hidden inputs');
	h.picker.hide(true);
	assert.equal(h.requests.length, 1, 'hiding an unused picker releases nothing');
});

test('picker: options failure shows a retry, never blocks checkout and never invents a mode', async () => {
	const h = pickupHarness(() => new Error('network'));
	h.picker.show(7);
	await h.settle();
	assert.equal(h.picker.element.hidden, false);
	assert.ok(h.picker.element.querySelector('[data-pk="retry"]'));
	assert.equal(h.picker.required(), false, 'unknown mode is left to the server');
	assert.equal(h.picker.gate(), '');
});

test('picker: live mode lists ASAP, day tabs and slots with full ones disabled', async () => {
	const h = pickupHarness(() => liveOptions());
	h.picker.show(7);
	await h.settle();
	const el = h.picker.element;
	assert.equal(el.hidden, false);
	assert.match(el.textContent, /When would you like it\?/);
	assert.match(el.textContent, /Collect from Dough Boss Revesby/);
	assert.match(el.textContent, /ASAP — ready in about 25 min/);
	const asap = el.querySelector('[data-pk="asap"]');
	assert.equal(asap.disabled, false);
	assert.equal(asap.getAttribute('aria-pressed'), 'false');
	assert.deepEqual(el.find(n => n.hasClass('db-pickup-day')).map(n => n.textContent), ['Today', 'Tomorrow']);
	const full = el.querySelector('[data-pk="slot:2026-10-16T01:30:00Z"]');
	assert.equal(full.disabled, true);
	assert.equal(full.getAttribute('aria-disabled'), 'true');
	assert.match(full.textContent, /Full/);
	assert.equal(full.getAttribute('aria-label'), '12:30pm, full');
	const open = el.querySelector('[data-pk="slot:2026-10-16T01:45:00Z"]');
	assert.equal(open.disabled, false);
	assert.equal(h.picker.required(), true);
});

test('picker: closed shop disables ASAP with the reason and still offers later slots', async () => {
	const h = pickupHarness(() => liveOptions({ asap: null, closed_reason: 'closed_now' }));
	h.picker.show(7);
	await h.settle();
	const asap = h.picker.element.querySelector('[data-pk="asap"]');
	assert.equal(asap.disabled, true);
	assert.match(asap.textContent, /Shop closed now/);
	assert.ok(h.picker.element.querySelector('[data-pk="slot:2026-10-16T01:45:00Z"]'));
});

test('picker: choosing a slot holds it, announces the selection and gates checkout until then', async () => {
	const token = 'e'.repeat(64);
	const h = pickupHarness((route, options) => route.indexOf('/pickup/options') === 0 ? liveOptions() : holdResponse(options.body.ready_from_utc, token));
	h.picker.show(7);
	await h.settle();
	assert.equal(h.picker.gate(), 'Choose a pickup time first', 'live shop with no time is blocked');
	assert.deepEqual(h.errors, ['Choose a pickup time first']);
	h.picker.element.querySelector('[data-pk="slot:2026-10-16T01:45:00Z"]').click();
	await h.settle();
	const hold = h.requests.find(r => r.route === '/pickup/hold');
	assert.deepEqual(plain(hold.body), {
		location_id: 7, pickup_mode: 'scheduled', ready_from_utc: '2026-10-16T01:45:00Z', idempotency_key: hold.body.idempotency_key
	});
	assert.ok(String(hold.body.idempotency_key).length >= 16);
	assert.equal(h.picker.selection().hold_token, token);
	assert.equal(h.picker.gate(), '', 'a valid hold lets checkout proceed');
	const events = h.dom.dispatched.filter(e => e.type === 'doughboss:pickup-changed');
	assert.equal(events.length, 1);
	assert.equal(events[0].detail.selection.hold_token, token);
	assert.match(h.picker.element.textContent, /Pickup: Today 12:45pm/);
	assert.match(h.picker.element.textContent, /Held for \d+:\d\d/);
	assert.equal(h.picker.element.querySelector('[data-pk="slot:2026-10-16T01:45:00Z"]').getAttribute('aria-pressed'), 'true');
});

test('picker: ASAP is held as mode asap with the server window', async () => {
	const h = pickupHarness((route, options) => route.indexOf('/pickup/options') === 0 ? liveOptions() : holdResponse(options.body.ready_from_utc, 'f'.repeat(64), 'asap'));
	h.picker.show(7);
	await h.settle();
	h.picker.element.querySelector('[data-pk="asap"]').click();
	await h.settle();
	const hold = h.requests.find(r => r.route === '/pickup/hold');
	assert.equal(hold.body.pickup_mode, 'asap');
	assert.equal(hold.body.ready_from_utc, '2026-10-16T01:30:00Z');
	assert.equal(h.picker.selection().mode, 'asap');
	assert.match(h.picker.element.textContent, /ASAP, about 25 min/);
});

test('picker: a cart change re-holds the same time (debounced) and releases the old hold', async () => {
	let n = 0;
	const h = pickupHarness((route, options) => {
		if (route.indexOf('/pickup/options') === 0) return liveOptions();
		if (route === '/pickup/release') return { released: true };
		n += 1;
		return holdResponse(options.body.ready_from_utc, String(n).repeat(64).slice(0, 64));
	});
	h.picker.show(7);
	await h.settle();
	h.picker.element.querySelector('[data-pk="slot:2026-10-16T01:45:00Z"]').click();
	await h.settle();
	const first = h.picker.selection().hold_token;
	h.dom.document.dispatchEvent({ type: 'doughboss:cart-updated' });
	assert.equal(h.requests.filter(r => r.route === '/pickup/hold').length, 1, 'debounced: nothing yet');
	h.timers.splice(0).forEach(callback => callback());
	await h.settle();
	const holds = h.requests.filter(r => r.route === '/pickup/hold');
	assert.equal(holds.length, 2);
	assert.equal(holds[1].body.ready_from_utc, holds[0].body.ready_from_utc, 'same slot');
	assert.notEqual(holds[1].body.idempotency_key, holds[0].body.idempotency_key, 'a fresh idempotency key');
	const second = h.picker.selection().hold_token;
	assert.notEqual(second, first);
	const release = h.requests.find(r => r.route === '/pickup/release');
	assert.deepEqual(plain(release.body), { hold_token: first }, 'the superseded hold is given back');
	// QA 2.51: the server frees the caller's own hold before counting the slot,
	// otherwise the last spot in a slot could be refused to its own holder.
	assert.equal(holds[1].body.release_token, first, 'a same-slot re-hold names the hold it replaces');
	assert.equal(Object.prototype.hasOwnProperty.call(holds[0].body, 'release_token'), false, 'a first hold has nothing to release');
});

test('picker: a same-slot re-hold that fails drops the selection (its old hold was already released server-side)', async () => {
	let n = 0;
	const h = pickupHarness((route, options) => {
		if (route.indexOf('/pickup/options') === 0) return liveOptions();
		if (route === '/pickup/release') return { released: true };
		n += 1;
		if (n === 1) return holdResponse(options.body.ready_from_utc, 'a'.repeat(64));
		const error = new Error('Slot full'); error.code = 'doughboss_slot_full'; return error;
	});
	h.picker.show(7);
	await h.settle();
	h.picker.element.querySelector('[data-pk="slot:2026-10-16T01:45:00Z"]').click();
	await h.settle();
	assert.equal(h.picker.selection().hold_token, 'a'.repeat(64));
	// A customer re-click on the held slot is not an "auto" hold, but it still
	// sends release_token, so a failure must not leave a phantom selection.
	h.picker.element.querySelector('[data-pk="slot:2026-10-16T01:45:00Z"]').click();
	await h.settle();
	const holds = h.requests.filter(r => r.route === '/pickup/hold');
	assert.equal(holds.length, 2);
	assert.equal(holds[1].body.release_token, 'a'.repeat(64));
	assert.equal(h.picker.selection(), null, 'no phantom hold after the replacement failed');
	assert.ok(h.errors.some(message => /filled up/.test(message)));
});

test('picker: a full slot reloads the options, keeps the picker open and announces the reason', async () => {
	let optionReads = 0;
	const h = pickupHarness((route) => {
		if (route.indexOf('/pickup/options') === 0) { optionReads += 1; return liveOptions(); }
		const error = new Error('Slot full'); error.code = 'doughboss_slot_full'; return error;
	});
	h.picker.show(7);
	await h.settle();
	h.picker.element.querySelector('[data-pk="slot:2026-10-16T01:45:00Z"]').click();
	await h.settle();
	assert.equal(h.picker.selection(), null);
	assert.equal(optionReads, 2, 'availability was refreshed');
	assert.equal(h.picker.element.hidden, false, 'the picker stays open');
	assert.ok(h.errors.some(message => /filled up/.test(message)), 'the failure is announced');
});

test('picker: a paused shop blocks checkout with the paused message', async () => {
	const h = pickupHarness(() => liveOptions({ paused: true, asap: null, days: [], closed_reason: 'paused' }));
	h.picker.show(7);
	await h.settle();
	assert.match(h.picker.element.textContent, /paused/);
	assert.match(h.picker.gate(), /paused/);
});

test('picker: hide() releases the held time and clears the checkout fields', async () => {
	const token = '9'.repeat(64);
	const h = pickupHarness((route, options) => route.indexOf('/pickup/options') === 0 ? liveOptions() : (route === '/pickup/release' ? { released: true } : holdResponse(options.body.ready_from_utc, token)));
	h.picker.show(7);
	await h.settle();
	h.picker.element.querySelector('[data-pk="slot:2026-10-16T01:45:00Z"]').click();
	await h.settle();
	h.picker.hide(true);
	await h.settle();
	const release = h.requests.filter(r => r.route === '/pickup/release');
	assert.equal(release.length, 1);
	assert.deepEqual(plain(release[0].body), { hold_token: token });
	const events = h.dom.dispatched.filter(e => e.type === 'doughboss:pickup-changed');
	assert.equal(events[events.length - 1].detail.selection, null, 'checkout drops the hidden inputs');
	assert.equal(h.picker.element.hidden, true);
	assert.equal(h.picker.selection(), null);
});

test('picker: switching shop gives the old hold back and re-reads options for the new shop', async () => {
	const token = '8'.repeat(64);
	const h = pickupHarness((route, options) => route.indexOf('/pickup/options') === 0 ? liveOptions() : (route === '/pickup/release' ? { released: true } : holdResponse(options.body.ready_from_utc, token)));
	h.picker.show(7);
	await h.settle();
	h.picker.element.querySelector('[data-pk="slot:2026-10-16T01:45:00Z"]').click();
	await h.settle();
	h.picker.show(9);
	await h.settle();
	assert.ok(h.requests.some(r => r.route === '/pickup/release' && r.body.hold_token === token));
	assert.ok(h.requests.some(r => r.route === '/pickup/options?location_id=9'));
	assert.equal(h.picker.selection(), null);
});

test('picker: a payment in flight cannot start a new hold', async () => {
	const h = pickupHarness(() => liveOptions());
	h.context.paymentMutationLock = true;
	// paymentMutationLock is read from the shared context object at call time.
	h.picker.show(7);
	await h.settle();
	h.picker.element.querySelector('[data-pk="slot:2026-10-16T01:45:00Z"]').click();
	await h.settle();
	assert.equal(h.requests.filter(r => r.route === '/pickup/hold').length, 0);
});

test('header shop changes with a carried cart are routed through the re-validating door', () => {
	assert.match(storefrontSource, /doughboss:shop-change-request'[\s\S]{0,700}lastCartItemCount > 0[\s\S]{0,200}requestShopChange\(requested\)/);
	const shopStatusSource = fs.readFileSync(path.join(root, 'public', 'js', 'doughboss-shop-status.js'), 'utf8');
	assert.match(shopStatusSource, /Store-first/, 'the header no longer assumes a first shop when several exist');
});
