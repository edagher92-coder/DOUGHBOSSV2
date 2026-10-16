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
const isUpcoming = extractFunction('isUpcoming', { Date });
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
