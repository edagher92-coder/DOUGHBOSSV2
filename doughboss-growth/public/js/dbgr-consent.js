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
 *  - drives the server-rendered banner (Accept all, Reject all, Settings; equal prominence) and the persistent
 *    "Privacy choices" button. The banner is never dismissed without a choice, so closing it is not consent;
 *    once a choice exists a Close button (and Escape) lets the visitor leave a reopened banner as it was, with no write.
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
	var closeButton = null; /* only shown once a choice exists, so the reopened banner can be closed without changing it */
	var checkM = null;
	var checkA = null;
	var lastFocus = null;
	var savedScroll = null; /* the page's own inline scroll-padding-bottom and padding-bottom, put back when the banner is hidden */
	var savedPadding = null;
	var basePadding = 0; /* the root element's bottom padding before the banner added to it */

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
		syncPageClearance(); /* the banner is taller with the panel open */
	}

	/*
	 * The banner is fixed to the bottom of the screen, so a keyboard user tabbing down the page would land on controls that
	 * sit underneath it (WCAG 2.4.11, Focus Not Obscured). While it is showing the page keeps that much of the bottom of the
	 * viewport clear in two ways: scroll-padding-bottom makes the browser scroll a focused element clear of the banner, and
	 * padding-bottom on the root element lets the very end of the page (the footer links) scroll up above it. Both are the
	 * page's own inline values put back when the banner closes.
	 */
	function bannerCover() {
		var rect;
		var height = doc.documentElement && typeof doc.documentElement.clientHeight === 'number' ? doc.documentElement.clientHeight : 0;
		if (height > 0 && typeof root.getBoundingClientRect === 'function') {
			rect = root.getBoundingClientRect();
			if (rect && typeof rect.top === 'number' && height - rect.top > 0) {
				return Math.ceil(height - rect.top); /* includes the gap under a floating banner on wide screens */
			}
		}
		return typeof root.offsetHeight === 'number' ? root.offsetHeight : 0;
	}

	function pagePadding() {
		var value = 0;
		try {
			if (typeof win.getComputedStyle === 'function') {
				value = parseFloat(win.getComputedStyle(doc.documentElement).paddingBottom);
			}
		} catch (e) {
			value = 0;
		}
		return isNaN(value) ? 0 : value;
	}

	function syncPageClearance() {
		var style = doc.documentElement ? doc.documentElement.style : null;
		var cover;
		if (!style || !root) {
			return;
		}
		if (root.hasAttribute('hidden')) {
			if (savedScroll !== null) {
				style.scrollPaddingBottom = savedScroll;
				style.paddingBottom = savedPadding;
				savedScroll = null;
				savedPadding = null;
			}
			return;
		}
		cover = bannerCover();
		if (cover > 0) {
			if (savedScroll === null) {
				savedScroll = style.scrollPaddingBottom || '';
				savedPadding = style.paddingBottom || '';
				basePadding = pagePadding();
			}
			style.scrollPaddingBottom = cover + 'px';
			style.paddingBottom = (basePadding + cover) + 'px';
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
		setHidden(closeButton, !state.chosen);
		openPanel(!automatic);
		setHidden(root, false);
		syncPageClearance();
		if (typeof root.focus === 'function') {
			root.focus();
		}
	}

	function hideBanner() {
		if (!root) {
			return;
		}
		setHidden(root, true);
		syncPageClearance();
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
		} else if (action === 'close' && state.chosen) {
			hideBanner(); /* No cookie write, no event: the stored choice is unchanged. */
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
			closeButton = root.querySelector('[data-dbgr-action="close"]');
			root.addEventListener('click', onBannerClick);
			root.addEventListener('keydown', onBannerKey);
			if (typeof win.addEventListener === 'function') {
				win.addEventListener('resize', syncPageClearance); /* rotation or a wider window changes how much it covers */
			}
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
