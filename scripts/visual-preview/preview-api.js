/** Synthetic, read-only data adapter for the exported visual preview. */
(function () {
	'use strict';

	var script = document.currentScript;
	var root = new URL('../', script.src);
	var apiRoot = 'https://preview.invalid/doughboss/v1';
	var fixture = window.DoughBossPreviewOptions || { menu: [] };
	var paused = document.body.classList.contains('dbf-ordering-paused');
	var orderingOpen = !paused;

	function asset(path) {
		return new URL(path, root).href;
	}

	var config = {
		currency_symbol: '$', currency_code: 'AUD', tax_rate: 0, gst_inclusive: true, delivery_fee: 0,
		enable_pickup: true, enable_delivery: false, single_location_mode: false, single_location_id: 0,
		ordering_open: orderingOpen,
		ordering_closed_message: 'Sample paused-state message. Check a shop for current availability.',
		after_hours_preorders_enabled: false, after_hours_preorders_message: '', sizes: [], toppings: [],
		payments_enabled: false, stripe_pk: '', payment_gateway: 'stripe',
		mercure: { enabled: false, url: '', topic: '' }
	};

	function pickupStatus() {
		var observed = new Date();
		var expires = new Date(observed.getTime() + 55000);
		return {
			state: 'open', observed_at_utc: observed.toISOString(), expires_at_utc: expires.toISOString(),
			closes_at_utc: null, next_open_at_utc: null, next_open_label: null, timezone: 'Australia/Sydney'
		};
	}

	function locations() {
		return [
			{ id: 901, name: 'Sample Revesby', slug: 'sample-revesby', suburb: 'Revesby', address: 'Sample address — preview only', phone: '', pickup_enabled: true, delivery_enabled: false, prep_time: 20, timezone: 'Australia/Sydney', pickup_status: pickupStatus(), capacity_preview: false },
			{ id: 902, name: 'Sample Bankstown', slug: 'sample-bankstown', suburb: 'Bankstown', address: 'Sample address — preview only', phone: '', pickup_enabled: true, delivery_enabled: false, prep_time: 20, timezone: 'Australia/Sydney', pickup_status: pickupStatus(), capacity_preview: false }
		];
	}

	var menu = fixture.menu.map(function (item) {
		var copy = Object.assign({}, item);
		copy.image = asset(item.image);
		return copy;
	});

	window.DoughBossShopData = { restUrl: apiRoot };
	window.DoughBossData = {
		restUrl: apiRoot, nonce: '', currency: '$', googleReviewUrl: '#preview-unavailable',
		payments: { enabled: false, pk: '', gateway: 'stripe', hostedCheckout: false, liveMode: false },
		i18n: {}
	};

	function jsonResponse(payload, status) {
		return Promise.resolve(new Response(JSON.stringify(payload), {
			status: status,
			headers: { 'Content-Type': 'application/json' }
		}));
	}
	window.fetch = function (input, init) {
		var method = String((init && init.method) || (input && typeof input !== 'string' && input.method) || 'GET').toUpperCase();
		var url;
		try { url = new URL(typeof input === 'string' ? input : input.url, document.baseURI); }
		catch (error) { return jsonResponse({ code: 'preview_invalid_request', message: 'This request is not available in the visual preview.' }, 400); }
		if (method !== 'GET') {
			return jsonResponse({ code: 'preview_read_only', message: 'Orders, payments and other submissions are disabled in this visual preview.' }, 405);
		}
		if (url.origin !== 'https://preview.invalid' || url.pathname.indexOf('/doughboss/v1/') !== 0 || url.search || url.hash) {
			return jsonResponse({ code: 'preview_external_blocked', message: 'External requests are disabled in this visual preview.' }, 403);
		}
		var path = url.pathname.slice('/doughboss/v1'.length);
		if (path === '/config') { return jsonResponse(config, 200); }
		if (path === '/locations') { return jsonResponse(locations(), 200); }
		if (path === '/table/context') { return jsonResponse({ active: false }, 200); }
		if (path === '/menu') { return jsonResponse(menu, 200); }
		return jsonResponse({ code: 'preview_route_blocked', message: 'This route is not available in the visual preview.' }, 404);
	};

	document.addEventListener('submit', function (event) {
		event.preventDefault();
	});
	document.addEventListener('click', function (event) {
		var link = event.target.closest && event.target.closest('a[href]');
		if (!link) { return; }
		var href = link.getAttribute('href') || '';
		if (href === '#preview-unavailable' || /^(?:https?:|mailto:|tel:)/i.test(href)) {
			event.preventDefault();
			var notice = document.getElementById('db-preview-action');
			if (notice) { notice.textContent = 'Links, calls, email and external actions are disabled in this visual preview.'; }
		}
	});
}());
