/**
 * DoughBoss Growth attribution capture (WP-04). ES5, no dependencies.
 *
 * Reads where a visit came from (UTM parameters, ad click ids, referrer HOST, landing PATH) and keeps it in the
 * first-party cookie dbgr_attr (90 days, SameSite=Lax, Secure on https, 1.5 KB at most) so the server can attach it
 * to an enquiry or an order. Nothing is written without consent, and consent comes only from the consent banner
 * (DoughBossGrowth.consent, WP-03):
 *   - UTM parameters, referrer host, landing path and first-seen time need MEASUREMENT consent;
 *   - ad click ids (gclid, gbraid, wbraid, fbclid, msclkid) need ADVERTISING consent.
 * Withdrawing a category removes its fields from the cookie; withdrawing both deletes the cookie. Without the
 * consent script this file does nothing at all.
 *
 * Rules (the same as web/src/lib/attribution-schema.ts, proved by tests/fixtures/attribution-cases.json): every value
 * is trimmed, refused when it holds a control character or is longer than 120 UTF-16 code units; the referrer is a host
 * name only; the landing path never carries a query string. A value is dropped, never shortened.
 *
 * First touch wins for 90 days: a later landing replaces the stored record only when it carries campaign parameters
 * (a UTM value or a click id the visitor has consented to). Dynamic text is never written as markup.
 *
 * Exposes DoughBossGrowth.attribution = { sanitise( raw ), get() } (get() is the stored record, sanitised).
 */
(function () {
	'use strict';

	var COOKIE = 'dbgr_attr';
	var MAX_AGE = 7776000; /* 90 days in seconds */
	var MAX_COOKIE = 1500; /* the URL-encoded cookie value, in characters */
	var PARAM_MAX = 120;
	var HOST_MAX = 253;
	var PATH_MAX = 200;
	var win = window;
	var doc = document;

	var consentApi = win.DoughBossGrowth && win.DoughBossGrowth.consent;
	if (!consentApi || typeof consentApi.get !== 'function') {
		return;
	}

	/* Same field order as attribution-schema.ts. */
	var FIELDS = ['utmSource', 'utmMedium', 'utmCampaign', 'utmTerm', 'utmContent', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'referrerHost', 'landingPath', 'firstSeenAt'];
	var MEASUREMENT = { utmSource: 1, utmMedium: 1, utmCampaign: 1, utmTerm: 1, utmContent: 1, referrerHost: 1, landingPath: 1, firstSeenAt: 1 };
	var ADVERTISING = { gclid: 1, gbraid: 1, wbraid: 1, fbclid: 1, msclkid: 1 };
	var CAMPAIGN = ['utmSource', 'utmMedium', 'utmCampaign', 'utmTerm', 'utmContent', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid'];
	var QUERY_KEYS = {
		utmSource: 'utm_source',
		utmMedium: 'utm_medium',
		utmCampaign: 'utm_campaign',
		utmTerm: 'utm_term',
		utmContent: 'utm_content',
		gclid: 'gclid',
		gbraid: 'gbraid',
		wbraid: 'wbraid',
		fbclid: 'fbclid',
		msclkid: 'msclkid'
	};
	/* Dropped first when the cookie would exceed its budget: the least useful fields first. */
	var DROP_ORDER = ['utmContent', 'utmTerm', 'referrerHost', 'utmMedium', 'utmCampaign', 'firstSeenAt', 'landingPath'];

	var WS = '\\u0009-\\u000D\\u0020\\u00A0\\u1680\\u2000-\\u200A\\u2028\\u2029\\u202F\\u205F\\u3000\\uFEFF';
	var TRIM_RE = new RegExp('^[' + WS + ']+|[' + WS + ']+$', 'g');
	var PATH_RE = new RegExp('^\\/[^?#' + WS + ']*$');
	var SURROGATE_PAIR_RE = /[\uD800-\uDBFF][\uDC00-\uDFFF]/g;
	var HOST_RE = /^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/;
	var CONTROL_RE = /[\u0000-\u001f\u007f]/;
	var DATE_SOURCE = '(?:(?:\\d\\d[2468][048]|\\d\\d[13579][26]|\\d\\d0[48]|[02468][048]00|[13579][26]00)-02-29|\\d{4}-(?:(?:0[13578]|1[02])-(?:0[1-9]|[12]\\d|3[01])|(?:0[469]|11)-(?:0[1-9]|[12]\\d|30)|(?:02)-(?:0[1-9]|1\\d|2[0-8])))';
	var DATETIME_RE = new RegExp('^' + DATE_SOURCE + 'T(?:[01]\\d|2[0-3]):[0-5]\\d:[0-5]\\d(?:\\.\\d+)?Z$');

	var hasOwn = Object.prototype.hasOwnProperty;

	/* ---- sanitising (a port of sanitiseAttribution) ---- */

	/* Code points, not UTF-16 units: a surrogate pair counts once, a lone surrogate counts as itself. */
	function codePointCount(text) {
		return text.replace(SURROGATE_PAIR_RE, 'x').length;
	}

	function cleanText(value, max, controlScan) {
		var text;
		if (typeof value !== 'string') {
			return null;
		}
		text = value.replace(TRIM_RE, '');
		if (text.length < 1 || codePointCount(text) > max) {
			return null;
		}
		if (controlScan && CONTROL_RE.test(text)) {
			return null;
		}
		return text;
	}

	function cleanField(field, value) {
		var text;
		if (field === 'firstSeenAt') {
			return typeof value === 'string' && DATETIME_RE.test(value) ? value : null;
		}
		if (field === 'referrerHost') {
			text = cleanText(value, HOST_MAX, false);
			return text !== null && HOST_RE.test(text) ? text : null;
		}
		if (field === 'landingPath') {
			text = cleanText(value, PATH_MAX, false);
			return text !== null && PATH_RE.test(text) ? text : null;
		}
		return cleanText(value, PARAM_MAX, true);
	}

	function sanitise(raw) {
		var out = {};
		var i;
		var value;
		if (raw === null || typeof raw !== 'object') {
			return out;
		}
		for (i = 0; i < FIELDS.length; i += 1) {
			if (!hasOwn.call(raw, FIELDS[i])) {
				continue;
			}
			value = cleanField(FIELDS[i], raw[FIELDS[i]]);
			if (value !== null) {
				out[FIELDS[i]] = value;
			}
		}
		return out;
	}

	function count(obj) {
		var n = 0;
		var k;
		for (k in obj) {
			if (hasOwn.call(obj, k)) {
				n += 1;
			}
		}
		return n;
	}

	/* ---- the cookie ---- */

	function readRaw() {
		var match;
		try {
			match = String(doc.cookie || '').match(/(?:^|;\s*)dbgr_attr=([^;]*)/);
		} catch (e) {
			return '';
		}
		return match ? match[1] : '';
	}

	function readStored() {
		var raw = readRaw();
		var data;
		if (raw === '' || raw.length > MAX_COOKIE) {
			return {};
		}
		try {
			data = JSON.parse(decodeURIComponent(raw));
		} catch (e) {
			return {};
		}
		return sanitise(data);
	}

	function cookieAttributes() {
		var attrs = '; Path=/; SameSite=Lax';
		if (win.location && win.location.protocol === 'https:') {
			attrs += '; Secure';
		}
		return attrs;
	}

	function setCookie(text) {
		try {
			doc.cookie = text;
		} catch (e) {
			/* Cookies are blocked: nothing is stored. */
		}
	}

	function clearCookie() {
		if (readRaw() === '') {
			return;
		}
		setCookie(COOKIE + '=; Max-Age=0' + cookieAttributes());
	}

	/* Serialise in schema order; drop the least useful fields until the encoded value fits. Null when nothing fits. */
	function encode(record) {
		var copy = {};
		var i;
		var encoded;
		for (i = 0; i < FIELDS.length; i += 1) {
			if (hasOwn.call(record, FIELDS[i])) {
				copy[FIELDS[i]] = record[FIELDS[i]];
			}
		}
		i = 0;
		for (;;) {
			if (count(copy) === 0) {
				return null;
			}
			encoded = encodeURIComponent(JSON.stringify(copy));
			if (encoded.length <= MAX_COOKIE) {
				return encoded;
			}
			if (i >= DROP_ORDER.length) {
				return null;
			}
			delete copy[DROP_ORDER[i]];
			i += 1;
		}
	}

	function store(record) {
		var encoded = encode(record);
		if (encoded === null) {
			clearCookie();
			return;
		}
		if (readRaw() === encoded) {
			return; /* unchanged */
		}
		setCookie(COOKIE + '=' + encoded + '; Max-Age=' + MAX_AGE + cookieAttributes());
	}

	/* ---- reading the landing ---- */

	function decodeQuery(text) {
		try {
			return decodeURIComponent(text.replace(/\+/g, ' '));
		} catch (e) {
			return '';
		}
	}

	function parseQuery(search) {
		var out = {};
		var text = String(search || '');
		var parts;
		var i;
		var eq;
		var key;
		if (text.charAt(0) === '?') {
			text = text.substring(1);
		}
		if (text === '') {
			return out;
		}
		parts = text.split('&');
		for (i = 0; i < parts.length; i += 1) {
			if (parts[i] === '') {
				continue;
			}
			eq = parts[i].indexOf('=');
			key = decodeQuery(eq < 0 ? parts[i] : parts[i].substring(0, eq));
			if (!hasOwn.call(out, key)) {
				out[key] = eq < 0 ? '' : decodeQuery(parts[i].substring(eq + 1));
			}
		}
		return out;
	}

	/* The referrer's host name, lower case, or '' when there is none or it is this site. */
	function externalReferrerHost() {
		var referrer;
		var match;
		var host;
		var own;
		try {
			referrer = String(doc.referrer || '');
			own = String((win.location && win.location.hostname) || '').toLowerCase();
		} catch (e) {
			return '';
		}
		match = referrer.match(/^https?:\/\/(?:[^\/?#@]*@)?([^\/?#:]+)/i);
		if (!match) {
			return '';
		}
		host = match[1].toLowerCase();
		return host === own ? '' : host;
	}

	/* What this landing offers, before consent is applied. Built once per page view. */
	var landing = null;
	function readLanding() {
		var query;
		var raw = {};
		var field;
		if (landing !== null) {
			return landing;
		}
		query = parseQuery(win.location ? win.location.search : '');
		for (field in QUERY_KEYS) {
			if (hasOwn.call(QUERY_KEYS, field) && hasOwn.call(query, QUERY_KEYS[field])) {
				raw[field] = query[QUERY_KEYS[field]];
			}
		}
		raw.referrerHost = externalReferrerHost();
		raw.landingPath = win.location ? win.location.pathname : '';
		raw.firstSeenAt = new Date().toISOString();
		landing = sanitise(raw);
		return landing;
	}

	/* ---- the decision ---- */

	function filterByConsent(record, consent) {
		var out = {};
		var field;
		for (field in record) {
			if (!hasOwn.call(record, field)) {
				continue;
			}
			if ((hasOwn.call(MEASUREMENT, field) && consent.measurement) || (hasOwn.call(ADVERTISING, field) && consent.advertising)) {
				out[field] = record[field];
			}
		}
		return out;
	}

	function hasCampaign(record) {
		var i;
		for (i = 0; i < CAMPAIGN.length; i += 1) {
			if (hasOwn.call(record, CAMPAIGN[i])) {
				return true;
			}
		}
		return false;
	}

	function capture(consent) {
		var allowed;
		var stored;
		var fresh;
		if (!consent || (consent.measurement !== true && consent.advertising !== true)) {
			clearCookie(); /* no consent: nothing stays */
			return;
		}
		allowed = { measurement: consent.measurement === true, advertising: consent.advertising === true };
		stored = filterByConsent(readStored(), allowed); /* withdrawn categories leave the cookie */
		fresh = filterByConsent(readLanding(), allowed);
		if (count(stored) === 0 || hasCampaign(fresh)) {
			store(fresh); /* first touch, or a landing that carries campaign parameters */
		} else {
			store(stored);
		}
	}

	function currentConsent() {
		try {
			return consentApi.get();
		} catch (e) {
			return null;
		}
	}

	win.DoughBossGrowth.attribution = {
		sanitise: sanitise,
		get: function () {
			return readStored();
		}
	};

	doc.addEventListener('doughboss-growth:consent-changed', function (event) {
		var detail = event && event.detail;
		capture(detail && typeof detail === 'object' ? detail : currentConsent());
	});
	capture(currentConsent());
}());
