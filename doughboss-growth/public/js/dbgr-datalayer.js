/**
 * DoughBoss Growth dataLayer dispatcher (WP-03). ES5, no dependencies.
 *
 * Pushes typed analytics events to window.dataLayer, and nothing else:
 *  - DoughBossGrowth.track(name, params) refuses any name that is not in the taxonomy (content/events.json,
 *    exported from web/src/lib/analytics/events.ts and passed in through window.DoughBossGrowthConfig.events),
 *    keeps only the parameters in that event's allow-list, and drops any value of the wrong type, any string
 *    containing "@", and any string or number carrying a run of more than 8 digits (an email or phone number
 *    must never reach an ad platform, whatever the caller meant to send).
 *  - It listens for core's doughboss:marketing-event and maps core events to the taxonomy. Core's "purchase"
 *    becomes "order_placed": the browser cannot prove payment (core fires it for unpaid orders too), so a
 *    browser event named "purchase" is never pushed (purchase is server-side only). Core's own generate_lead
 *    is the after-hours pre-order request, not a catering enquiry, so it is not forwarded either.
 *  - A catering enquiry lead (generate_lead, form catering_enquiry) is pushed when core dispatches
 *    doughboss:catering-enquiry-created (core 2.44.0) or, until then, when the success box (.dbc-success)
 *    appears inside [data-doughboss-catering]. Each enquiry is counted once.
 *
 * Nothing is pushed unless Tag Manager is on (config.gtm) AND the visitor has allowed measurement or
 * advertising (DoughBossGrowth.consent.get()). Events before a choice are dropped, not queued.
 */
(function () {
	'use strict';

	var win = window;
	var doc = document;
	var cfg = win.DoughBossGrowthConfig;
	if (!cfg || typeof cfg !== 'object') {
		cfg = {};
	}
	var gtmOn = cfg.gtm === true;
	var events = (cfg.events && typeof cfg.events === 'object') ? cfg.events : {};
	var locations = (cfg.locations && typeof cfg.locations === 'object') ? cfg.locations : {};

	var MAX_STRING = 100;
	var MAX_INTEGER = 99999999; /* eight digits: cents up to $999,999.99 */
	var MAX_SEEN = 50;
	var seenIds = [];
	var leadsSeen = {};
	var lastLeadAt = 0;

	function hasOwn(object, key) {
		return Object.prototype.hasOwnProperty.call(object, key);
	}

	/* An "@", or nine or more digits (up to two separator characters allowed between digits, so "0400 000 000" and "(02) 9123 4567" are caught too). */
	function looksPersonal(text) {
		return text.indexOf('@') !== -1 || /\d(?:[\s().\-]{0,2}\d){8,}/.test(text);
	}

	/* Returns { ok: true, value } or { ok: false }. */
	function cleanValue(spec, value) {
		var i;
		var text;
		if (!spec || typeof spec !== 'object') {
			return { ok: false };
		}
		if (spec.type === 'enum') {
			if (!spec.values || typeof spec.values.length !== 'number') {
				return { ok: false };
			}
			for (i = 0; i < spec.values.length; i += 1) {
				if (spec.values[i] === value) {
					return { ok: true, value: value };
				}
			}
			return { ok: false };
		}
		if (spec.type === 'integer') {
			if (typeof value !== 'number' || !isFinite(value) || Math.floor(value) !== value || value < 0 || value > MAX_INTEGER) {
				return { ok: false };
			}
			return { ok: true, value: value };
		}
		if (spec.type === 'string') {
			if (typeof value !== 'string') {
				return { ok: false };
			}
			text = value.replace(/[\u0000-\u001f\u007f]/g, ' ').replace(/^\s+|\s+$/g, '').slice(0, MAX_STRING);
			if (text === '' || looksPersonal(text)) {
				return { ok: false };
			}
			return { ok: true, value: text };
		}
		return { ok: false };
	}

	function consentAllows() {
		var api = win.DoughBossGrowth && win.DoughBossGrowth.consent;
		var current;
		if (!api || typeof api.get !== 'function') {
			return false;
		}
		current = api.get();
		return !!current && (current.measurement === true || current.advertising === true);
	}

	/**
	 * Push one typed event. Returns true when it was pushed, false when it was refused or dropped.
	 */
	function track(name, params) {
		var specs;
		var entry;
		var keys;
		var i;
		var result;
		if (typeof name !== 'string' || !hasOwn(events, name)) {
			return false;
		}
		if (!gtmOn || !consentAllows()) {
			return false;
		}
		specs = events[name];
		if (!specs || typeof specs !== 'object') {
			return false;
		}
		entry = { event: name };
		if (params && typeof params === 'object') {
			keys = Object.keys(specs);
			for (i = 0; i < keys.length; i += 1) {
				if (hasOwn(params, keys[i])) {
					result = cleanValue(specs[keys[i]], params[keys[i]]);
					if (result.ok) {
						entry[keys[i]] = result.value;
					}
				}
			}
		}
		win.dataLayer = win.dataLayer || [];
		win.dataLayer.push(entry);
		return true;
	}

	/* ---- core doughboss:marketing-event -> taxonomy ---- */

	function wholeNumber(value) {
		var n = Number(value);
		return (isFinite(n) && Math.floor(n) === n && n >= 0) ? n : undefined;
	}

	/* Core sends decimal dollars; the taxonomy is integer cents, converted here and only here. */
	function cents(value) {
		var n = Number(value);
		return (isFinite(n) && n >= 0) ? Math.round(n * 100) : undefined;
	}

	function firstId(props) {
		var ids = props.content_ids;
		return (ids && typeof ids.length === 'number' && typeof ids[0] === 'string') ? ids[0] : undefined;
	}

	function storeOf(locationId) {
		var key = String(locationId);
		return (locationId !== undefined && locationId !== null && hasOwn(locations, key) && typeof locations[key] === 'string') ? locations[key] : undefined;
	}

	function paymentOf(value) {
		var upper = (typeof value === 'string') ? value.toUpperCase() : '';
		return (upper === 'SQUARE' || upper === 'PAY_AT_SHOP') ? upper : undefined;
	}

	function mapCore(type, p) {
		if (type === 'view_item') {
			return { name: 'view_item', params: { item_slug: firstId(p), item_name: p.content_name, category: p.content_category } };
		}
		if (type === 'add_to_cart') {
			return { name: 'add_to_cart', params: { item_slug: firstId(p), item_name: p.content_name, quantity: wholeNumber(p.quantity), value_cents: cents(p.value) } };
		}
		if (type === 'begin_checkout') {
			return {
				name: 'begin_checkout',
				params: { store: storeOf(p.location_id), value_cents: cents(p.value), item_count: wholeNumber(p.num_items), payment_method: paymentOf(p.payment_method) }
			};
		}
		if (type === 'purchase') {
			return { name: 'order_placed', params: { store: storeOf(p.location_id), value_cents: cents(p.value), item_count: wholeNumber(p.num_items) } };
		}
		return null;
	}

	function alreadySeen(id) {
		var i;
		for (i = 0; i < seenIds.length; i += 1) {
			if (seenIds[i] === id) {
				return true;
			}
		}
		seenIds.push(id);
		if (seenIds.length > MAX_SEEN) {
			seenIds.shift();
		}
		return false;
	}

	function onCoreEvent(event) {
		var envelope = event && event.detail;
		var type;
		var mapped;
		var id;
		if (!envelope || typeof envelope !== 'object') {
			return;
		}
		type = typeof envelope.event_type === 'string' ? envelope.event_type : '';
		mapped = mapCore(type, (envelope.properties && typeof envelope.properties === 'object') ? envelope.properties : {});
		if (!mapped) {
			return;
		}
		id = typeof envelope.event_id === 'string' ? envelope.event_id : '';
		if (id !== '' && alreadySeen(id)) {
			return;
		}
		track(mapped.name, mapped.params);
	}

	/* ---- catering enquiry lead ---- */

	function cateringLead(reference) {
		var key = (typeof reference === 'string' && reference !== '') ? reference.slice(0, 64) : '_';
		var now = new Date().getTime();
		var recent = lastLeadAt !== 0 && now - lastLeadAt < 10000;
		var unknownBefore = key !== '_' && hasOwn(leadsSeen, '_');
		if (hasOwn(leadsSeen, key)) {
			return;
		}
		leadsSeen[key] = true;
		/* Both signals (the 2.44.0 event and the success box) can arrive for one enquiry, and one of them may
		   lack the reference: an unknown reference within ten seconds of a lead is the same enquiry. */
		if (recent && (key === '_' || unknownBefore)) {
			return;
		}
		lastLeadAt = now;
		track('generate_lead', { form: 'catering_enquiry' });
	}

	function watchCateringBox() {
		var root = doc.querySelector('[data-doughboss-catering]');
		var observer;
		if (!root || typeof win.MutationObserver !== 'function') {
			return;
		}
		observer = new win.MutationObserver(function () {
			var box = root.querySelector('.dbc-success');
			var strong;
			if (!box) {
				return;
			}
			strong = box.querySelector('.dbc-success-num strong');
			cateringLead(strong ? strong.textContent : '');
		});
		observer.observe(root, { childList: true, subtree: true });
	}

	win.DoughBossGrowth = win.DoughBossGrowth || {};
	win.DoughBossGrowth.track = track;

	if (gtmOn) {
		doc.addEventListener('doughboss:marketing-event', onCoreEvent);
		doc.addEventListener('doughboss:catering-enquiry-created', function (event) {
			var detail = event && event.detail;
			cateringLead(detail && typeof detail.enquiry_number === 'string' ? detail.enquiry_number : '');
		});
		if (doc.readyState === 'loading') {
			doc.addEventListener('DOMContentLoaded', watchCateringBox);
		} else {
			watchCateringBox();
		}
	}
}());
