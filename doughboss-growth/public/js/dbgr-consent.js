/**
 * DoughBoss Growth consent banner (WP-03). ES5, no dependencies.
 *
 * Owns the visitor's consent choice for the whole site:
 *  - reads and writes the dbgr_consent cookie {v, m, a, ts} (wording version, measurement 0/1, advertising 0/1,
 *    UNIX seconds), 180 days, SameSite=Lax, Secure on https;
 *  - on every change (and once on load, so a stored choice is replayed) dispatches core's doughboss:consent
 *    { measurement, advertising, version } and doughboss-growth:consent-changed { measurement, advertising,
 *    version, chosen } on document;
 *  - when Tag Manager is on, sends gtag('consent', 'update', ...) so Consent Mode v2 follows the choice;
 *  - drives the server-rendered banner (Accept all, Reject all, Choose; equal prominence) and the persistent
 *    "Privacy choices" button. The banner is never dismissed without a choice, so closing it is not consent.
 *
 * Configuration comes from window.DoughBossGrowthConfig (printed by the plugin). Without it this file does
 * nothing at all. Dynamic text is never written as markup: the banner is rendered by PHP and only toggled here.
 *
 * Exposes DoughBossGrowth.consent = { get(), open() }.
 */
(function () {
	'use strict';

	var COOKIE = 'dbgr_consent';
	var MAX_AGE = 15552000; /* 180 days in seconds */
	var win = window;
	var doc = document;
	var cfg = win.DoughBossGrowthConfig;
	if (!cfg || typeof cfg !== 'object') {
		return;
	}
	var version = typeof cfg.consentVersion === 'string' ? cfg.consentVersion : '';
	if (version === '') {
		return;
	}
	var optOut = cfg.mode === 'opt_out';
	var gtmOn = cfg.gtm === true;

	var state = null; /* { measurement, advertising, chosen } */
	var root = null;
	var panel = null;
	var reopen = null;
	var chooseButton = null;
	var checkM = null;
	var checkA = null;
	var lastFocus = null;

	/* ---- cookie ---- */

	function readCookie() {
		var match;
		try {
			match = String(doc.cookie || '').match(/(?:^|;\s*)dbgr_consent=([^;]*)/);
		} catch (e) {
			return '';
		}
		return match ? match[1] : '';
	}

	/* Same rules as the inline snippet printed by the plugin (Consent Mode default and replay). */
	function parse(raw) {
		var o;
		if (typeof raw !== 'string' || raw === '' || raw.length > 400) {
			return null;
		}
		try {
			o = JSON.parse(decodeURIComponent(raw));
		} catch (e) {
			return null;
		}
		if (!o || typeof o !== 'object' || o.v !== version) {
			return null;
		}
		if ((o.m !== 0 && o.m !== 1) || (o.a !== 0 && o.a !== 1)) {
			return null;
		}
		if (typeof o.ts !== 'number' || !(o.ts > 0)) {
			return null;
		}
		return { measurement: o.m === 1, advertising: o.a === 1, ts: o.ts };
	}

	function writeCookie(measurement, advertising) {
		var value = encodeURIComponent(JSON.stringify({
			v: version,
			m: measurement ? 1 : 0,
			a: advertising ? 1 : 0,
			ts: Math.floor(new Date().getTime() / 1000)
		}));
		var cookie = COOKIE + '=' + value + '; Max-Age=' + MAX_AGE + '; Path=/; SameSite=Lax';
		if (win.location && win.location.protocol === 'https:') {
			cookie += '; Secure';
		}
		try {
			doc.cookie = cookie;
		} catch (e) {
			/* Cookies are blocked: the choice still applies for this page view. */
		}
	}

	function load() {
		var stored = parse(readCookie());
		if (stored) {
			state = { measurement: stored.measurement, advertising: stored.advertising, chosen: true };
		} else {
			state = { measurement: optOut, advertising: false, chosen: false };
		}
	}

	/* ---- events and Consent Mode ---- */

	function fire(name, detail) {
		var event;
		try {
			event = new win.CustomEvent(name, { detail: detail });
		} catch (e) {
			try {
				event = doc.createEvent('CustomEvent');
				event.initCustomEvent(name, false, false, detail);
			} catch (e2) {
				return;
			}
		}
		doc.dispatchEvent(event);
	}

	function announce() {
		fire('doughboss:consent', { measurement: state.measurement, advertising: state.advertising, version: version });
		fire('doughboss-growth:consent-changed', {
			measurement: state.measurement,
			advertising: state.advertising,
			version: version,
			chosen: state.chosen
		});
	}

	function updateConsentMode() {
		var ads = state.advertising ? 'granted' : 'denied';
		if (!gtmOn) {
			return;
		}
		win.dataLayer = win.dataLayer || [];
		if (typeof win.gtag !== 'function') {
			win.gtag = function () {
				win.dataLayer.push(arguments);
			};
		}
		win.gtag('consent', 'update', {
			ad_storage: ads,
			ad_user_data: ads,
			ad_personalization: ads,
			analytics_storage: state.measurement ? 'granted' : 'denied'
		});
	}

	/* ---- banner ---- */

	function setHidden(el, hidden) {
		if (!el) {
			return;
		}
		if (hidden) {
			el.setAttribute('hidden', '');
		} else {
			el.removeAttribute('hidden');
		}
	}

	function panelOpen() {
		return !!panel && !panel.hasAttribute('hidden');
	}

	function openPanel(open) {
		if (!panel) {
			return;
		}
		if (open) {
			if (checkM) {
				checkM.checked = state.measurement;
			}
			if (checkA) {
				checkA.checked = state.advertising;
			}
		}
		setHidden(panel, !open);
		if (chooseButton) {
			chooseButton.setAttribute('aria-expanded', open ? 'true' : 'false');
		}
	}

	function canFocus(el) {
		return !!el && el !== doc.body && typeof el.focus === 'function' && (!doc.contains || doc.contains(el));
	}

	function showBanner(automatic) {
		if (!root) {
			return;
		}
		lastFocus = doc.activeElement || null;
		setHidden(reopen, true);
		openPanel(!automatic);
		setHidden(root, false);
		if (typeof root.focus === 'function') {
			root.focus();
		}
	}

	function hideBanner() {
		if (!root) {
			return;
		}
		setHidden(root, true);
		setHidden(reopen, false);
		if (canFocus(lastFocus) && !root.contains(lastFocus)) {
			lastFocus.focus();
		} else if (canFocus(reopen)) {
			reopen.focus();
		}
		lastFocus = null;
	}

	function decide(measurement, advertising) {
		state = { measurement: !!measurement, advertising: !!advertising, chosen: true };
		writeCookie(state.measurement, state.advertising);
		updateConsentMode();
		announce();
		hideBanner();
	}

	function actionOf(target) {
		var node = target;
		while (node && node !== root) {
			if (node.getAttribute && node.getAttribute('data-dbgr-action')) {
				return node.getAttribute('data-dbgr-action');
			}
			node = node.parentNode;
		}
		return '';
	}

	function onBannerClick(event) {
		var action = actionOf(event.target);
		if (action === 'accept') {
			decide(true, true);
		} else if (action === 'reject') {
			decide(false, false);
		} else if (action === 'choose') {
			openPanel(!panelOpen());
		} else if (action === 'save') {
			decide(!!(checkM && checkM.checked), !!(checkA && checkA.checked));
		}
	}

	function onBannerKey(event) {
		var key = event.key || event.keyCode;
		if (key !== 'Escape' && key !== 'Esc' && key !== 27) {
			return;
		}
		if (panelOpen()) {
			openPanel(false);
			if (chooseButton && typeof chooseButton.focus === 'function') {
				chooseButton.focus();
			}
		} else if (state.chosen) {
			hideBanner(); /* Reopened to review a stored choice: closing changes nothing. */
		}
		/* Before any choice Escape does nothing: dismissing the banner is not consent. */
	}

	function ancestorWithOpen(target) {
		var node = target;
		while (node && node !== doc) {
			if (node.getAttribute && node.getAttribute('data-dbgr-consent-open')) {
				return node;
			}
			node = node.parentNode;
		}
		return null;
	}

	function onDocumentClick(event) {
		if (ancestorWithOpen(event.target)) {
			if (typeof event.preventDefault === 'function') {
				event.preventDefault();
			}
			showBanner(false);
		}
	}

	function start() {
		root = doc.getElementById('dbgr-consent');
		panel = doc.getElementById('dbgr-consent-panel');
		reopen = doc.getElementById('dbgr-consent-reopen');
		checkM = doc.getElementById('dbgr-consent-m');
		checkA = doc.getElementById('dbgr-consent-a');
		if (root) {
			chooseButton = root.querySelector('[data-dbgr-action="choose"]');
			root.addEventListener('click', onBannerClick);
			root.addEventListener('keydown', onBannerKey);
		}
		doc.addEventListener('click', onDocumentClick);

		/* Replay: tell core and everyone else what the visitor already chose (or the default). */
		announce();
		if (!root) {
			return;
		}
		if (state.chosen) {
			setHidden(reopen, false);
		} else {
			showBanner(true);
		}
	}

	load();

	win.DoughBossGrowth = win.DoughBossGrowth || {};
	win.DoughBossGrowth.consent = {
		get: function () {
			return { measurement: state.measurement, advertising: state.advertising, chosen: state.chosen, version: version };
		},
		open: function () {
			showBanner(false);
		}
	};

	if (doc.readyState === 'loading') {
		doc.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
}());
