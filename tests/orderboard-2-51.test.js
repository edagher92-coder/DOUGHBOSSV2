'use strict';

// 2.51.0 kitchen board: timed-pickup helpers and wiring. No browser needed.
// Run with: node tests/orderboard-2-51.test.js

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const file = path.resolve(__dirname, '..', 'public', 'js', 'doughboss-orderboard.js');
const source = fs.readFileSync(file, 'utf8');

function extractFunction(name, context) {
	const start = source.indexOf('function ' + name + '(');
	assert.notEqual(start, -1, name + ' must exist in the board script');
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

const DELAY_STATUSES = ['pending', 'confirmed', 'preparing', 'baking'];
const formatTime = extractFunction('formatTime', { Intl, Date });
const readyWindow = extractFunction('readyWindow', { formatTime });
const pickupBadge = extractFunction('pickupBadge');
const utcIso = extractFunction('utcIso', { String });
const isUpcoming = extractFunction('isUpcoming', { Date, utcIso });
const delayAllowed = extractFunction('delayAllowed', { pickupBadge, DELAY_STATUSES });
const pickupText = extractFunction('pickupText', { readyWindow });

function mysql(offsetMinutes) {
	return new Date(Date.now() + offsetMinutes * 60000).toISOString().slice(0, 19).replace('T', ' ');
}

test('readyWindow is unchanged for legacy orders', () => {
	assert.equal(readyWindow({}), '');
	const text = readyWindow({ promised_ready_from_utc: '2026-10-16T02:45:00Z', promised_ready_by_utc: '2026-10-16T03:00:00Z', timezone: 'Australia/Sydney' });
	assert.match(text, /^1[:.]45.*–.*2[:.]00/i);
	assert.ok(text.includes('–'), 'a window renders as from–by');
	const single = readyWindow({ promised_ready_from_utc: '2026-10-16T02:45:00Z', timezone: 'Australia/Sydney' });
	assert.ok(single && !single.includes('–'));
});

test('pickupBadge only labels timed orders', () => {
	assert.equal(pickupBadge({ pickup_mode: 'asap' }), 'ASAP');
	assert.equal(pickupBadge({ pickup_mode: 'scheduled' }), 'Scheduled');
	assert.equal(pickupBadge({ pickup_mode: '' }), '');
	assert.equal(pickupBadge({}), '');
	assert.equal(pickupBadge(null), '');
});

test('isUpcoming needs a pending order flagged upcoming whose fire time has not passed', () => {
	assert.equal(isUpcoming({ status: 'pending', upcoming: true, fire_at_utc: mysql(30) }), true);
	assert.equal(isUpcoming({ status: 'pending', upcoming: 1 }), true);
	assert.equal(isUpcoming({ status: 'pending', upcoming: true, fire_at_utc: mysql(-1) }), false, 'fire time passed on this clock');
	// The board API actually sends ISO 'Z' (DoughBoss_Order::utc_iso); appending a second Z broke the clock check.
	assert.equal(isUpcoming({ status: 'pending', upcoming: true, fire_at_utc: mysql(-1).replace(' ', 'T') + 'Z' }), false, 'ISO fire time passed on this clock');
	assert.equal(isUpcoming({ status: 'pending', upcoming: true, fire_at_utc: mysql(30).replace(' ', 'T') + 'Z' }), true, 'ISO fire time still ahead');
	assert.equal(utcIso('2026-10-16 02:25:00'), '2026-10-16T02:25:00Z');
	assert.equal(utcIso('2026-10-16T02:25:00Z'), '2026-10-16T02:25:00Z', 'no double Z');
	assert.equal(utcIso(''), '');
	assert.equal(isUpcoming({ status: 'pending', upcoming: false, fire_at_utc: mysql(30) }), false);
	assert.equal(isUpcoming({ status: 'pending' }), false, 'legacy orders are never upcoming');
	assert.equal(isUpcoming({ status: 'confirmed', upcoming: true, fire_at_utc: mysql(30) }), false);
	assert.equal(isUpcoming(null), false);
});

test('delayAllowed covers worked statuses of timed orders only', () => {
	DELAY_STATUSES.forEach((status) => {
		assert.equal(delayAllowed({ pickup_mode: 'scheduled', status }), true, status);
		assert.equal(delayAllowed({ pickup_mode: 'asap', status }), true, status);
		assert.equal(delayAllowed({ pickup_mode: '', status }), false, status + ' legacy');
	});
	['ready', 'completed', 'cancelled', 'out_for_delivery'].forEach((status) => {
		assert.equal(delayAllowed({ pickup_mode: 'scheduled', status }), false, status);
	});
});

test('pickupText is prominent and drops a redundant Today', () => {
	assert.equal(pickupText({ pickup_label: 'Today 12:45pm' }), 'Pickup 12:45pm');
	assert.equal(pickupText({ pickup_label: 'Tomorrow 7:30am' }), 'Pickup Tomorrow 7:30am');
	assert.match(pickupText({ promised_ready_from_utc: '2026-10-16T02:45:00Z', timezone: 'Australia/Sydney' }), /^Pickup \d/);
});

test('delay call posts minutes, expected_version and an event key then reloads', () => {
	const start = source.indexOf('function delayOrder(');
	const body = source.slice(start, source.indexOf('function setStatus(', start));
	assert.match(body, /'\/admin\/order\/' \+ o\.id \+ '\/delay', 'POST'/);
	assert.match(body, /minutes: minutes \|\| DELAY_MINUTES/);
	assert.match(body, /expected_version: o\.version/);
	assert.match(body, /event_key: eventKey\(o, 'delay'\)/);
	assert.match(body, /return load\(\)/);
});

test('timed orders get a single Accept with no ETA picker and a +10 min button', () => {
	const start = source.indexOf('if (isNew && timed) {');
	assert.notEqual(start, -1);
	const block = source.slice(start, source.indexOf('} else if (isNew) {', start));
	assert.match(block, /accept\(o, 0\)/);
	assert.doesNotMatch(block, /ETA_CHOICES/);
	assert.match(block, /delayBtn/);
	assert.match(source, /\.concat\(delayBtn \? \[delayBtn\] : \[\], advBtns\)/);
});

test('upcoming orders leave the lanes and the new-order alarm', () => {
	const start = source.indexOf('\tfunction render(orders)');
	const body = source.slice(start, source.indexOf('/* ----------------------------------------------------------- Heartbeat', start));
	const upIdx = body.indexOf('orders.filter(isUpcoming)');
	const unackedIdx = body.indexOf('var unacked');
	const laneIdx = body.indexOf('laneOf(o.status) === lane.key');
	assert.ok(upIdx !== -1 && upIdx < unackedIdx && unackedIdx < laneIdx, 'upcoming is split off before the alarm filter and lanes');
	assert.match(body, /orders = orders\.filter\(function \(o\) \{ return !isUpcoming\(o\); \}\)/);
	assert.match(body, /renderUpcoming\(upcoming\)/);
});

test('upcoming section is collapsed by default and header reads summary.upcoming', () => {
	assert.match(source, /var upcomingOpen = false;/);
	assert.match(source, /el\('details', \{ class: 'db-upcoming' \}/);
	assert.match(source, /upcoming: normalCount\(res\.data\.upcoming\)/);
	assert.match(source, /Over capacity — slot was full when paid\./);
	assert.match(source, /Delayed \+/);
});

const normalizePreorderPickupOptions = extractFunction('normalizePreorderPickupOptions', { Date, Number });
function pickupOptions() {
	return {
		request_version: 7, config_key: 'server-config', timezone: 'Australia/Sydney', hold_minutes: 10,
		days: [{ label: 'Today', slots: [
			{ label: '1:45pm', ready_from_utc: '2026-10-17T02:45:00Z', available: true },
			{ label: '2:00pm', ready_from_utc: '2026-10-17T03:00:00Z', available: false }
		] }]
	};
}
test('preorder options accept seconds-only UTC and flatten only available server-labelled slots without mutation', () => {
	const data = pickupOptions();
	const before = JSON.stringify(data);
	const result = normalizePreorderPickupOptions(data, 7);
	assert.deepEqual(JSON.parse(JSON.stringify(result)), {
		config_key: 'server-config', timezone: 'Australia/Sydney', hold_minutes: 10,
		choices: [{ label: 'Today — 1:45pm', ready_from_utc: '2026-10-17T02:45:00Z', pickup_mode: 'scheduled' }]
	});
	assert.equal(JSON.stringify(data), before);
	assert.notEqual(result.choices[0], data.days[0].slots[0]);
	data.days = [];
	assert.equal(normalizePreorderPickupOptions(data, 7).choices.length, 0);
	data.days = [{ label: 'Tomorrow', slots: [] }];
	assert.equal(normalizePreorderPickupOptions(data, 7).choices.length, 0);
});
test('preorder options reject invalid UTC/calendar values, offsets and fractions', () => {
	for (const timestamp of ['2026-02-30T02:45:00Z', '2026-13-17T02:45:00Z', '2026-10-17T24:45:00Z', '2026-10-17T02:60:00Z', '2026-10-17T02:45:00.000Z', '2026-10-17T02:45:00+00:00', '2026-10-17 02:45:00', '', null, 5]) {
		const data = pickupOptions();
		data.days[0].slots[0].ready_from_utc = timestamp;
		assert.throws(() => normalizePreorderPickupOptions(data, 7), /invalid/, String(timestamp));
	}
});
test('preorder options reject mismatched versions and malformed response types', () => {
	for (const [key, value] of [['request_version', '7'], ['request_version', 6], ['request_version', 0], ['request_version', 7.5], ['config_key', ' '], ['config_key', null], ['timezone', ''], ['timezone', 5], ['hold_minutes', 0], ['hold_minutes', 31], ['hold_minutes', '10'], ['hold_minutes', 1.5], ['days', {}], ['days', null]]) {
		const data = pickupOptions(); data[key] = value;
		assert.throws(() => normalizePreorderPickupOptions(data, 7), /invalid/, key + '=' + value);
	}
	for (const expected of [0, '7', 7.5, null]) { assert.throws(() => normalizePreorderPickupOptions(pickupOptions(), expected), /invalid/); }
	for (const data of [null, [], 'options']) { assert.throws(() => normalizePreorderPickupOptions(data, 7), /invalid/); }
	for (const malformed of [null, { label: '', slots: [] }, { label: 'Today', slots: {} }]) {
		const data = pickupOptions(); data.days = [malformed];
		assert.throws(() => normalizePreorderPickupOptions(data, 7), /invalid/);
	}
	for (const mutation of [(slot) => { slot.available = 'true'; }, (slot) => { slot.label = ''; }, (slot) => { slot.label = null; }]) {
		const data = pickupOptions(); mutation(data.days[0].slots[0]);
		assert.throws(() => normalizePreorderPickupOptions(data, 7), /invalid/);
	}
});
test('duplicate slot selectors fail closed even across days or unavailable slots', () => {
	const data = pickupOptions();
	data.days.push({ label: 'Duplicate day', slots: [{ ...data.days[0].slots[0], available: false }] });
	assert.throws(() => normalizePreorderPickupOptions(data, 7), /invalid/);
	data.days = [{ label: 'Today', slots: data.days[0].slots.map((slot) => ({ ...slot, available: false })) }];
	assert.equal(normalizePreorderPickupOptions(data, 7).choices.length, 0);
});

// A small DOM double exercises the existing extraction/VM seam, without adding browser infrastructure.
class BoardNode {
	constructor(tag) { this.tagName = tag; this.children = []; this.attributes = {}; this.listeners = {}; this.parentNode = null; this.value = ''; this.checked = false; this.disabled = false; this._text = ''; }
	set textContent(value) { this._text = String(value); this.children.forEach((child) => { child.parentNode = null; }); this.children = []; }
	get textContent() { return this._text + this.children.map((child) => child.textContent).join(''); }
	setAttribute(name, value) { this.attributes[name] = String(value); if (name === 'disabled') this.disabled = true; if (name === 'value') this.value = String(value); }
	getAttribute(name) { return this.attributes[name]; }
	appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
	addEventListener(name, callback) { this.listeners[name] = callback; }
	trigger(name) { if (this.listeners[name]) return this.listeners[name](); }
	get isConnected() { return !!(this.connectedRoot || (this.parentNode && this.parentNode.isConnected)); }
	get classList() { return { contains: (name) => (this.className || '').split(' ').includes(name), toggle() {} }; }
	contains(node) { return node === this || this.children.some((child) => child.contains(node)); }
	querySelectorAll(selector) {
		const matches = (node) => selector[0] === '.' ? node.classList.contains(selector.slice(1))
			: (selector[0] === '[' ? node.attributes['data-preorder-id'] !== undefined && (selector === '[data-preorder-id]' || selector === '[data-preorder-id="' + node.attributes['data-preorder-id'] + '"]') : node.tagName === selector);
		const found = [];
		this.children.forEach((child) => { if (matches(child)) found.push(child); found.push(...child.querySelectorAll(selector)); });
		return found;
	}
	querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
}
function preorderHarness(overrides) {
	const panel = new BoardNode('section'); panel.connectedRoot = true;
	const calls = [];
	const context = {
		document: { createElement: (tag) => new BoardNode(tag), createTextNode: (text) => { const node = new BoardNode('#text'); node.textContent = text; return node; } },
		preorderPanel: panel, boardEl: null, statusEl: null, retryBtn: null, offline: false, inFlight: {},
		elapsed: () => '1m', orderTag: () => '#123', money: () => '$10.00', itemLine: () => null,
		eventKey: () => 'event-test', load: () => Promise.resolve(), setPreorderMessage: () => {},
		api: (url, method, body) => new Promise((resolve, reject) => calls.push({ url, method, body, resolve, reject }))
	};
	for (const name of ['el', 'normalizePreorderPickupOptions', 'preorderCanAccept', 'updatePreorderControls', 'setPreorderBusy', 'decidePreorder', 'preorderCard', 'applyConnectionState']) {
		context[name] = extractFunction(name, context);
	}
	const order = { id: 123, version: 7, decision_available: true, pickup_selection_available: true, items: [], ...overrides };
	const card = context.preorderCard(order); panel.appendChild(card); context.applyConnectionState();
	return { context, card, panel, order, calls, pickup: card.preorderPickup, accept: card.querySelector('.db-preorder-accept'), reject: card.querySelector('.db-preorder-reject') };
}
async function settleUI() { await new Promise((resolve) => setImmediate(resolve)); }
async function selectPreorder(h) {
	h.pickup.contact.checked = true; h.pickup.contact.trigger('change');
	h.calls[0].resolve(pickupOptions()); await settleUI();
	h.pickup.select.value = '2026-10-17T02:45:00Z'; h.pickup.select.trigger('change');
}
test('staff selection loads only after checked contact and posts exact server choice/config', async () => {
	const h = preorderHarness();
	assert.equal(h.calls.length, 0); assert.equal(h.accept.disabled, true);
	h.pickup.contact.trigger('change'); assert.equal(h.calls.length, 0, 'unchecked never fetches');
	await selectPreorder(h);
	assert.equal(h.calls[0].url, '/admin/preorder/123/pickup-options?expected_version=7&contact_confirmed=true');
	assert.equal(h.calls[0].method, 'GET');
	assert.equal(h.pickup.select.children.length, 2, 'placeholder plus one available slot');
	assert.equal(h.pickup.select.children[1].textContent, 'Today — 1:45pm');
	assert.equal(h.pickup.select.getAttribute('aria-describedby'), 'db-preorder-pickup-123-status');
	assert.equal(h.accept.disabled, false);
	h.accept.trigger('click'); h.accept.trigger('click');
	assert.equal(h.calls.length, 2, 'in-flight duplicate click ignored');
	assert.deepEqual(JSON.parse(JSON.stringify(h.calls[1].body)), { decision: 'accept', contact_confirmed: true, expected_version: 7, event_key: 'event-test', pickup_mode: 'scheduled', ready_from_utc: '2026-10-17T02:45:00Z', pickup_config_key: 'server-config' });
	assert.equal(h.accept.disabled, true); assert.equal(h.pickup.select.disabled, true); assert.equal(h.pickup.contact.disabled, true);
	h.calls[1].resolve({}); await settleUI();
});
test('contact toggles immediately invalidate selection/config and ignore stale successes and errors', async () => {
	const h = preorderHarness();
	h.pickup.contact.checked = true; h.pickup.contact.trigger('change');
	h.pickup.contact.checked = false; h.pickup.contact.trigger('change');
	assert.equal(h.pickup.options, null); assert.equal(h.pickup.selection, null); assert.equal(h.accept.disabled, true);
	h.calls[0].resolve(pickupOptions()); await settleUI(); assert.equal(h.pickup.options, null);
	h.pickup.contact.checked = true; h.pickup.contact.trigger('change');
	h.pickup.contact.checked = false; h.pickup.contact.trigger('change');
	h.pickup.contact.checked = true; h.pickup.contact.trigger('change');
	h.calls[2].resolve(pickupOptions()); await settleUI();
	const currentOptions = h.pickup.options;
	h.calls[1].reject(new Error('stale failure')); await settleUI();
	assert.equal(h.pickup.options, currentOptions); assert.doesNotMatch(h.card.textContent, /stale failure/);
	h.pickup.select.value = '2026-10-17T02:45:00Z'; h.pickup.select.trigger('change'); assert.equal(h.accept.disabled, false);
	h.pickup.contact.checked = false; h.pickup.contact.trigger('change');
	assert.equal(h.pickup.options, null); assert.equal(h.pickup.selection, null); assert.equal(h.pickup.select.value, '');
	h.context.setPreorderBusy(h.order, 'test', false); assert.equal(h.accept.disabled, true);
});
test('detached-card responses cannot update the old or replacement card', async () => {
	const h = preorderHarness(); h.pickup.contact.checked = true; h.pickup.contact.trigger('change');
	h.panel.textContent = '';
	const replacement = h.context.preorderCard(h.order); h.panel.appendChild(replacement);
	h.calls[0].resolve(pickupOptions()); await settleUI();
	assert.equal(h.pickup.options, null); assert.equal(replacement.preorderPickup.options, null);
	h.accept.trigger('click'); assert.equal(h.calls.length, 1);
});
test('a slower superseded success cannot overwrite newer options or the selected slot', async () => {
	const h = preorderHarness(); h.pickup.contact.checked = true; h.pickup.contact.trigger('change');
	h.pickup.contact.trigger('change');
	const current = pickupOptions(); current.config_key = 'new-config'; h.calls[1].resolve(current); await settleUI();
	h.pickup.select.value = '2026-10-17T02:45:00Z'; h.pickup.select.trigger('change');
	const selected = h.pickup.selection;
	h.calls[0].resolve(pickupOptions()); await settleUI();
	assert.equal(h.pickup.options.config_key, 'new-config'); assert.equal(h.pickup.selection, selected);
	assert.equal(h.pickup.select.children.length, 2); assert.equal(h.accept.disabled, false);
});
test('an offline transition invalidates pending options even when its response arrives after reconnect', async () => {
	const h = preorderHarness(); h.pickup.contact.checked = true; h.pickup.contact.trigger('change');
	h.context.offline = true; h.context.applyConnectionState();
	assert.equal(h.pickup.loading, false); assert.equal(h.pickup.options, null);
	h.context.offline = false; h.context.applyConnectionState();
	h.calls[0].resolve(pickupOptions()); await settleUI();
	assert.equal(h.pickup.options, null); assert.equal(h.accept.disabled, true);
	h.pickup.contact.trigger('change'); h.calls[1].resolve(pickupOptions()); await settleUI();
	assert.ok(h.pickup.options); assert.equal(h.accept.disabled, true, 'new options still need explicit selection');
});
test('busy and reconnect paths cannot reenable approval without a current valid selection', async () => {
	const h = preorderHarness();
	h.pickup.contact.checked = true; h.pickup.contact.trigger('change');
	assert.equal(h.accept.disabled, true); assert.equal(h.pickup.select.disabled, true);
	h.context.setPreorderBusy(h.order, 'reject', true);
	h.calls[0].resolve(pickupOptions()); await settleUI();
	h.context.setPreorderBusy(h.order, 'reject', false);
	assert.equal(h.pickup.options, null); assert.equal(h.accept.disabled, true);
	h.pickup.contact.trigger('change'); h.calls[1].resolve(pickupOptions()); await settleUI();
	h.context.applyConnectionState(); assert.equal(h.accept.disabled, true, 'loaded choices require explicit selection');
	h.pickup.select.value = '2026-10-17T02:45:00Z'; h.pickup.select.trigger('change'); assert.equal(h.accept.disabled, false);
	h.context.offline = true; h.context.applyConnectionState(); assert.equal(h.accept.disabled, true); assert.equal(h.pickup.options, null);
	h.context.offline = false; h.context.applyConnectionState(); h.context.setPreorderBusy(h.order, 'test', false);
	assert.equal(h.accept.disabled, true); h.accept.trigger('click'); assert.equal(h.calls.length, 2);
	h.pickup.select.value = 'fabricated'; h.pickup.select.trigger('change'); assert.equal(h.accept.disabled, true);
});
test('approval rechecks connection, contact, busy, selected-choice membership and server eligibility at click time', async () => {
	const h = preorderHarness(); await selectPreorder(h);
	const selection = h.pickup.selection;
	h.context.inFlight['123'] = 'other'; h.accept.trigger('click'); delete h.context.inFlight['123'];
	h.pickup.contact.checked = false; h.accept.trigger('click'); h.pickup.contact.checked = true;
	h.pickup.selection = { ...selection }; h.accept.trigger('click'); h.pickup.selection = selection;
	h.order.pickup_selection_available = false; h.accept.trigger('click'); h.order.pickup_selection_available = true;
	h.context.offline = true; h.accept.trigger('click'); h.context.offline = false;
	h.pickup.select.value = 'fabricated'; h.accept.trigger('click'); h.pickup.select.value = selection.ready_from_utc;
	assert.equal(h.calls.length, 1, 'no decision may bypass the current acceptance contract');
});
test('no-slots and load-error states stay disabled and allow contact-toggle retry', async () => {
	const h = preorderHarness(); h.pickup.contact.checked = true; h.pickup.contact.trigger('change');
	const empty = pickupOptions(); empty.days = []; h.calls[0].resolve(empty); await settleUI();
	assert.match(h.card.textContent, /No pickup times/); assert.equal(h.pickup.select.disabled, true); assert.equal(h.accept.disabled, true);
	h.pickup.contact.trigger('change'); h.calls[1].reject(new Error('Please refresh')); await settleUI();
	assert.match(h.card.textContent, /Please refresh/); assert.equal(h.accept.disabled, true);
	h.pickup.contact.trigger('change'); const invalid = pickupOptions(); invalid.request_version = '7'; h.calls[2].resolve(invalid); await settleUI();
	assert.match(h.card.textContent, /invalid/); assert.equal(h.pickup.options, null); assert.equal(h.accept.disabled, true);
});
test('historical requests keep rejection but never offer or dispatch a new pickup approval', async () => {
	const h = preorderHarness({ pickup_selection_available: false, preorder_workflow_version: 0 });
	assert.equal(h.accept, null); assert.ok(h.reject); assert.match(h.card.textContent, /historical request/);
	h.pickup.contact.checked = true; h.pickup.contact.trigger('change'); assert.equal(h.calls.length, 0);
	h.context.decidePreorder(h.order, 'accept', true, h.card); assert.equal(h.calls.length, 0);
	h.reject.trigger('click'); h.card.querySelector('.button-link-delete').trigger('click');
	assert.equal(h.calls.length, 1); assert.deepEqual(JSON.parse(JSON.stringify(h.calls[0].body)), { decision: 'reject', contact_confirmed: false, expected_version: 7, event_key: 'event-test' });
	h.calls[0].resolve({}); await settleUI();
});
