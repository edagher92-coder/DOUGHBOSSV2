/**
 * DoughBoss consent-gated commerce measurement bridge.
 *
 * Vendor IDs are public browser identifiers, but no vendor is initialised and
 * no event leaves the page until the host consent manager grants advertising
 * consent. AdPilot is intentionally server-to-server: this bridge emits a
 * sanitised first-party event that WordPress can later forward through a
 * signed, replay-protected outbox.
 */
(function () {
	'use strict';

	var config = window.DoughBossMarketingConfig || {};
	var consent = { measurement: false, advertising: false, version: '' };
	var attribution = {};
	var allowedFields = {
		content_ids: true,
		content_name: true,
		content_category: true,
		content_type: true,
		currency: true,
		value: true,
		num_items: true,
		quantity: true,
		order_type: true,
		location_id: true,
		channel: true,
		simulated: true
	};
	var eventMap = {
		view_item: { meta: 'ViewContent', tiktok: 'ViewContent' },
		add_to_cart: { meta: 'AddToCart', tiktok: 'AddToCart' },
		begin_checkout: { meta: 'InitiateCheckout', tiktok: 'InitiateCheckout' },
		purchase: { meta: 'Purchase', tiktok: 'CompletePayment' },
		purchase_simulated: { meta: '', tiktok: '' },
		generate_lead: { meta: 'Lead', tiktok: 'SubmitForm' },
		social_engagement: { meta: '', tiktok: '' },
		review_engagement: { meta: '', tiktok: '' }
	};

	function clipped(value, max) {
		return String(value == null ? '' : value).slice(0, max);
	}

	function eventId() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}
		return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
	}

	function cleanPayload(payload) {
		var clean = {};
		Object.keys(payload || {}).forEach(function (key) {
			if (!allowedFields[key]) { return; }
			var value = payload[key];
			if (key === 'content_ids') {
				clean[key] = Array.isArray(value) ? value.slice(0, 20).map(function (id) { return clipped(id, 80); }) : [];
			} else if (key === 'value') {
				clean[key] = Math.max(0, Number(value || 0));
			} else if (key === 'num_items' || key === 'quantity' || key === 'location_id') {
				clean[key] = Math.max(0, Number(value || 0));
			} else if (typeof value === 'boolean') {
				clean[key] = value;
			} else {
				clean[key] = clipped(value, 120);
			}
		});
		clean.currency = /^[A-Z]{3}$/.test(clean.currency || '') ? clean.currency : 'AUD';
		return clean;
	}

	function readAttribution() {
		if (!consent.measurement) { return {}; }
		var params = new URLSearchParams(window.location.search || '');
		var map = {
			utm_source: 'source',
			utm_medium: 'medium',
			utm_campaign: 'campaign',
			utm_content: 'content',
			utm_term: 'term'
		};
		Object.keys(map).forEach(function (key) {
			var value = params.get(key);
			if (value) { attribution[map[key]] = clipped(value, 120); }
		});
		return Object.assign({}, attribution);
	}

	function vendorReady() {
		return !!(config.enabled && consent.advertising);
	}

	function sendToVendors(name, payload, id) {
		if (!vendorReady() || !eventMap[name]) { return; }
		var mapping = eventMap[name];
		if (mapping.meta && config.metaPixelId && typeof window.fbq === 'function') {
			window.fbq('track', mapping.meta, payload, { eventID: id });
		}
		if (mapping.tiktok && config.tiktokPixelId && window.ttq && typeof window.ttq.track === 'function') {
			window.ttq.track(mapping.tiktok, Object.assign({ event_id: id }, payload));
		}
	}

	function track(name, payload) {
		name = clipped(name, 64).toLowerCase();
		if (!eventMap[name] && ['refund', 'cancel', 'fulfilment'].indexOf(name) === -1) { return null; }
		var clean = cleanPayload(payload || {});
		var envelope = {
			schema_version: 1,
			event_id: eventId(),
			event_type: name,
			occurred_at: new Date().toISOString(),
			consent: {
				measurement: !!consent.measurement,
				advertising: !!consent.advertising,
				version: clipped(consent.version, 40)
			},
			attribution: readAttribution(),
			properties: clean
		};
		document.dispatchEvent(new CustomEvent('doughboss:marketing-event', { detail: envelope }));
		sendToVendors(name, clean, envelope.event_id);
		return envelope.event_id;
	}

	function setConsent(next) {
		next = next || {};
		consent = {
			measurement: !!next.measurement,
			advertising: !!next.advertising,
			version: clipped(next.version || config.consentVersion || '', 40)
		};
		if (consent.measurement) { readAttribution(); }
		document.dispatchEvent(new CustomEvent('doughboss:marketing-consent-ready', { detail: Object.assign({}, consent) }));
	}

	document.addEventListener('doughboss:consent', function (event) {
		setConsent(event && event.detail ? event.detail : {});
	});

	window.DoughBossMarketing = {
		track: track,
		setConsent: setConsent,
		getAttribution: function () { return Object.assign({}, attribution); },
		getConsent: function () { return Object.assign({}, consent); }
	};
	document.addEventListener('click', function (event) {
		var link = event && event.target && event.target.closest ? event.target.closest('[data-doughboss-engagement]') : null;
		if (!link) { return; }
		track(link.getAttribute('data-doughboss-engagement'), {
			content_name: link.getAttribute('data-content-name') || '',
			content_category: 'social',
			channel: link.getAttribute('data-channel') || 'website'
		});
	});

	// A host consent manager may provide already-resolved consent at page load.
	if (config.initialConsent) { setConsent(config.initialConsent); }
}());
