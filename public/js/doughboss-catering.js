/**
 * DoughBoss — catering page app.
 *
 * Hydrates [data-doughboss-catering]: loads packages, runs a live server-side
 * quote (deposit estimate), and submits a catering enquiry. Vanilla JS, no
 * build step. Reuses DoughBossData (restUrl, nonce, currency) from the main
 * storefront bundle.
 */
(function () {
	'use strict';

	var root = document.querySelector('[data-doughboss-catering]');
	if (!root) {
		return;
	}

	var DB = window.DoughBossData || {};
	var API = (DB.restUrl || '').replace(/\/$/, '');
	var NONCE = DB.nonce || '';
	var CUR = DB.currency || '$';

	// Earliest bookable date: N business days after today (Sat/Sun skipped).
	// Mirrors DoughBoss_Catering::earliest_event_date(); the server is the gate.
	var NOTICE_DAYS = Math.max(0, Math.min(30, parseInt(root.getAttribute('data-notice-days'), 10) || 0)) || (root.hasAttribute('data-notice-days') ? 0 : 2);
	function earliestDate() {
		var d = new Date();
		var left = NOTICE_DAYS;
		while (left > 0) {
			d.setDate(d.getDate() + 1);
			if (d.getDay() !== 0 && d.getDay() !== 6) { left--; }
		}
		var m = d.getMonth() + 1, day = d.getDate();
		return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
	}

	// Catering is prepared at the catering shops only (Revesby) and is pickup
	// only unless the server says otherwise. Both come from the shortcode.
	var CATERING_LOCATION_IDS = (function () {
		try {
			var ids = JSON.parse(root.getAttribute('data-catering-locations') || 'null');
			return Array.isArray(ids) && ids.length ? ids.map(cateringLocationId).filter(Boolean) : null;
		} catch (error) { return null; }
	}());
	var DELIVERY_ALLOWED = root.getAttribute('data-catering-delivery') === '1';
	var MAX_PACKAGE_QTY = 50;

	var state = {
		packages: [],
		quantities: {},
		lines: [],
		cateringLocationIds: CATERING_LOCATION_IDS,
		selectedId: 0,
		guests: 0,
		orderType: 'pickup',
		quote: null,
		quoteStatus: 'idle',
		quoteGeneration: 0,
		packagesUnavailable: false,
		locations: [],
		locationStatus: 'loading',
		locationId: 0,
		requestedLocationId: null,
		locationLocked: false,
		submitting: false,
		submittedLocationName: '',
		email: '',
		name: ''
	};

	function money(n) {
		return CUR + Number(n || 0).toFixed(2);
	}

	function el(html) {
		var d = document.createElement('div');
		d.innerHTML = html.trim();
		return d.firstChild;
	}

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function cateringRequestUrl(path) {
		return API + (API.indexOf('?') !== -1 ? path.replace('?', '&') : path);
	}

	function get(path) {
		// Public reads must not depend on a cached page's expiring nonce.
		var controller = typeof AbortController === 'function' ? new AbortController() : null;
		var options = { credentials: 'same-origin', cache: 'no-store' };
		if (controller) { options.signal = controller.signal; }
		var timer;
		var timeout = new Promise(function (resolve, reject) {
			timer = setTimeout(function () {
				reject(new Error('Catering request timed out.'));
				if (controller) { controller.abort(); }
			}, 8000);
		});
		var response = fetch(cateringRequestUrl(path), options).then(function (r) {
			if (!r.ok) { throw new Error('Catering request failed.'); }
			return r.json();
		});
		return Promise.race([response, timeout]).then(function (data) {
			clearTimeout(timer);
			return data;
		}, function (error) {
			clearTimeout(timer);
			throw error;
		});
	}

	function post(path, body) {
		return fetch(cateringRequestUrl(path), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
			body: JSON.stringify(body)
		}).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, data: j }; }); });
	}

	function selectedPackage() {
		for (var i = 0; i < state.packages.length; i++) {
			if (state.packages[i].id === state.selectedId) { return state.packages[i]; }
		}
		return null;
	}

	function cateringLocationId(value) {
		if (typeof value !== 'number' && (typeof value !== 'string' || !/^[1-9][0-9]*$/.test(value))) { return 0; }
		var id = Number(value);
		return isFinite(id) && id > 0 && id <= 9007199254740991 && Math.floor(id) === id ? id : 0;
	}

	function selectedCateringLocation() {
		return state.locations.filter(function (location) { return location.id === state.locationId; })[0] || null;
	}

	function updateCateringLocation(form) {
		var select = form.querySelector('[name="location_id"]');
		var note = form.querySelector('.dbc-location-note');
		if (!select || !note) { return; }
		select.innerHTML = '<option value="">Choose a shop</option>' + state.locations.map(function (location) {
			return '<option value="' + location.id + '">' + esc(location.name) + '</option>';
		}).join('');
		select.value = state.locationId ? String(state.locationId) : '';
		var cateringOnly = Array.isArray(state.cateringLocationIds) && state.cateringLocationIds.length > 0;
		select.disabled = state.submitting || state.locationLocked || state.locationStatus !== 'ready' || !state.locations.length || (cateringOnly && state.locations.length === 1);
		var location = selectedCateringLocation();
		if (state.submitting) { note.textContent = 'Sending enquiry for ' + state.submittedLocationName + '. Shop selection is locked until the request finishes.'; }
		else if (cateringOnly && location && state.locationStatus === 'ready') { note.textContent = 'Catering is prepared at ' + location.name + ' for pickup only. Availability will be confirmed by staff.'; }
		else if (state.locationStatus === 'loading') { note.textContent = 'Checking shops...'; }
		else if (state.locationStatus === 'error') { note.textContent = 'Shop information is unavailable. Retry before sending your enquiry.'; }
		else if (!state.locations.length) { note.textContent = 'No shop is configured. Staff will confirm the location for this enquiry.'; }
		else if (!location) { note.textContent = 'Your previous shop is no longer available. Choose a shop before sending.'; }
		else { note.textContent = 'Enquiry for ' + location.name + (state.locationLocked ? '. Using your current table location.' : '. Availability will be confirmed by staff.'); }
		var retry = form.querySelector('[data-catering-locations-retry]');
		if (retry) { retry.hidden = state.locationStatus !== 'error'; }
	}

	function applyCateringLocation(id) {
		state.requestedLocationId = cateringLocationId(id);
		if (state.submitting || state.locationLocked) { return; }
		// The sitewide header shop is for ordering; catering stays at its own shop.
		if (Array.isArray(state.cateringLocationIds) && state.cateringLocationIds.length) { return; }
		state.locationId = state.locations.some(function (location) { return location.id === state.requestedLocationId; }) ? state.requestedLocationId : 0;
		var form = root.querySelector('.dbc-form');
		if (form) { updateCateringLocation(form); }
	}

	function loadCateringLocations() {
		state.locationStatus = 'loading';
		var form = root.querySelector('.dbc-form');
		if (form) { updateCateringLocation(form); }
		return Promise.all([get('/locations'), get('/table/context')]).then(function (results) {
			var list = results[0];
			var table = results[1];
			if (!table || typeof table.active !== 'boolean' || (table.active && (!table.location || !table.table))) { throw new Error('Table context is unavailable.'); }
			if (!Array.isArray(list) || !list.every(function (location) {
				return location && cateringLocationId(location.id) && typeof location.name === 'string' && location.name.trim();
			})) { throw new Error('Invalid shop information.'); }
			var allowed = Array.isArray(state.cateringLocationIds) && state.cateringLocationIds.length ? state.cateringLocationIds : null;
			var eligible = allowed ? list.filter(function (location) { return allowed.indexOf(cateringLocationId(location.id)) !== -1; }) : list;
			state.locations = eligible.map(function (location) { return { id: cateringLocationId(location.id), name: location.name }; });
			// A dine-in table elsewhere cannot move catering away from its shop.
			state.locationLocked = !allowed && !!(table && table.active && table.location && table.table);
			var desired = state.requestedLocationId;
			if (allowed) { desired = state.locations.length ? state.locations[0].id : 0; }
			else if (state.locationLocked) { desired = cateringLocationId(table.location.id); }
			else if (desired === null) {
				try { desired = window.DoughBossPreferences ? cateringLocationId(window.DoughBossPreferences.readShop()) : 0; } catch (error) { desired = 0; }
				// No preference uses the same first configured shop as the site header.
				if (!desired) { desired = list.length ? cateringLocationId(list[0].id) : 0; }
			}
			state.locationId = state.locations.some(function (location) { return location.id === desired; }) ? desired : 0;
			if (state.locationLocked && !state.locationId) { throw new Error('Table shop is unavailable.'); }
			state.locationStatus = 'ready';
		}).catch(function () {
			state.locationStatus = 'error';
			state.locationId = 0;
		}).then(function () {
			var current = root.querySelector('.dbc-form');
			if (current) { updateCateringLocation(current); }
		});
	}

	/* ---------- rendering ---------- */

	// Keep the shortcode's configured contact actions and help intact. Only this
	// child belongs to the changing package, enquiry and confirmation UI.
	function cateringFlow() {
		var flow = root.querySelector('.dbc-app-flow');
		if (!flow) {
			flow = document.createElement('div');
			flow.className = 'dbc-app-flow';
			root.insertBefore(flow, root.querySelector('.dbc-how') || root.querySelector('.dbc-faq'));
		}
		return flow;
	}

	function render() {
		var flow = cateringFlow();
		flow.innerHTML = '';
		flow.appendChild(renderPackages());
		flow.appendChild(renderBuilder());
		var loadingNote = root.querySelector('.dbc-coming-soon');
		if (loadingNote) loadingNote.hidden = true;
	}

	// "Office Platter — 3 Dozen" -> title + size badge, so the size,
	// guest range and price read as separate facts instead of one run-on line.
	function packageTitleParts(name) {
		var parts = String(name || '').split(/\s+[—–-]\s+/);
		return { title: parts[0] || String(name || ''), size: parts.length > 1 ? parts.slice(1).join(' – ') : '' };
	}

	function packageQuantity(id) {
		var qty = state.quantities ? Number(state.quantities[id]) : 0;
		return isFinite(qty) && qty > 0 ? Math.floor(qty) : 0;
	}

	function renderPackages() {
		var wrap = el('<div class="dbc-packages"></div>');
		var head = el(
			'<div class="dbc-head">' +
				'<p class="dbc-kicker">Catering</p>' +
				'<h2 class="dbc-h2">Choose your packages</h2>' +
				'<p class="dbc-sub">Combine packages with the + and − buttons. Availability, final pricing and any deposit requirements are confirmed by our team.</p>' +
			'</div>'
		);
		wrap.appendChild(head);

		if (!state.packages.length) {
			wrap.appendChild(el('<p class="dbc-empty"' + (state.packagesUnavailable ? ' role="alert"' : '') + '>' +
				(state.packagesUnavailable ? 'We couldn\'t load packages. Refresh to try again, or use the form below for a custom enquiry.' : 'Catering packages are coming soon — use the form below to enquire.') + '</p>'));
			return wrap;
		}

		var grid = el('<div class="dbc-grid"></div>');
		state.packages.forEach(function (p) {
			var serves = p.serves_min && p.serves_max ? ('Serves ' + p.serves_min + '–' + p.serves_max)
				: (p.serves_min ? 'Serves ' + p.serves_min + '+' : '');
			var parts = packageTitleParts(p.name);
			var qty = packageQuantity(p.id);
			var id = cateringLocationId(p.id);
			var card = el(
				'<article class="dbc-card' + (qty ? ' is-selected' : '') + '" data-package-card="' + id + '">' +
					(p.image ? '<span class="dbc-card-img" style="background-image:url(\'' + esc(p.image) + '\')"></span>' : '') +
					'<div class="dbc-card-body">' +
						'<h3 class="dbc-card-name">' + esc(parts.title) + '</h3>' +
						'<ul class="dbc-card-meta">' +
							(parts.size ? '<li class="dbc-badge">' + esc(parts.size) + '</li>' : '') +
							(serves ? '<li class="dbc-badge dbc-badge--muted">' + esc(serves) + '</li>' : '') +
							'<li class="dbc-badge dbc-badge--price">' + money(p.price) + '</li>' +
						'</ul>' +
						(p.description ? '<p class="dbc-card-desc">' + esc(p.description) + '</p>' : '') +
						(p.includes ? '<p class="dbc-card-includes">' + esc(p.includes) + '</p>' : '') +
						'<div class="dbc-stepper" role="group" aria-label="' + esc('Quantity of ' + parts.title) + '">' +
							'<button type="button" class="dbc-step" data-step="-1" data-package="' + id + '" aria-label="' + esc('Remove one ' + parts.title) + '"' + (qty ? '' : ' disabled') + '>−</button>' +
							'<output class="dbc-qty" data-qty-for="' + id + '" aria-live="polite">' + qty + '</output>' +
							'<button type="button" class="dbc-step" data-step="1" data-package="' + id + '" aria-label="' + esc('Add one ' + parts.title) + '"' + (qty >= MAX_PACKAGE_QTY ? ' disabled' : '') + '>+</button>' +
						'</div>' +
					'</div>' +
				'</article>'
			);
			grid.appendChild(card);
		});
		wrap.appendChild(grid);
		return wrap;
	}

	function renderBuilder() {
		var pkg = selectedPackage();
		var wrap = el('<div class="dbc-builder"></div>');
		wrap.appendChild(el(
			'<div class="dbc-head">' +
				'<h2 class="dbc-h2">Build your quote</h2>' +
				'<p class="dbc-sub">Tell us the details for a staff-reviewed quote. Sending an enquiry does not confirm a booking or take payment.</p>' +
			'</div>'
		));

		var form = el('<form class="dbc-form" novalidate></form>');
		form.innerHTML =
			'<input class="dbc-hp" type="text" name="hp" tabindex="-1" autocomplete="off" aria-hidden="true" />' +
			'<div class="dbc-selected" aria-live="polite">' + selectionSummary(pkg) + '</div>' +
			'<label class="dbc-field"><span>' + (DELIVERY_ALLOWED ? 'Catering shop' : 'Pickup from') + '</span><select name="location_id" aria-describedby="dbc-location-note"></select></label>' +
			'<p id="dbc-location-note" class="dbc-location-note dbc-sub" role="status"></p>' +
			'<button type="button" data-catering-locations-retry hidden>Retry shop information</button>' +
			'<div class="dbc-row">' +
				'<label class="dbc-field"><span>Guests</span><input type="number" min="0" step="1" name="guest_count" inputmode="numeric" /></label>' +
				'<label class="dbc-field"><span>Event date</span><input type="date" name="event_date" min="' + earliestDate() + '" /></label>' +
				'<label class="dbc-field"><span>Event time</span><input type="time" name="event_time" /></label>' +
			'</div>' +
			(NOTICE_DAYS > 0 ? '<p class="dbc-sub">Please allow at least ' + NOTICE_DAYS + ' business day' + (NOTICE_DAYS === 1 ? '' : 's') + '’ notice (weekends don’t count). Pickup from Revesby. Need it sooner? Call us.</p>' : '') +
			(DELIVERY_ALLOWED
				? '<div class="dbc-row">' +
					'<fieldset class="dbc-field dbc-ful"><span>Fulfilment</span>' +
						'<label class="dbc-radio"><input type="radio" name="order_type" value="pickup" checked /> Pickup</label>' +
						'<label class="dbc-radio"><input type="radio" name="order_type" value="delivery" /> Delivery</label>' +
					'</fieldset>' +
				'</div>' +
				'<label class="dbc-field dbc-addr" hidden><span>Delivery address</span><textarea name="address" rows="2"></textarea></label>'
				: '') +
			'<div class="dbc-row">' +
			'<label class="dbc-field"><span>Your name</span><input type="text" name="customer_name" required aria-describedby="dbc-contact-error" /></label>' +
			'<label class="dbc-field"><span>Email</span><input type="email" name="customer_email" required aria-describedby="dbc-contact-error" /></label>' +
				'<label class="dbc-field"><span>Phone</span><input type="tel" name="customer_phone" /></label>' +
			'</div>' +
			'<label class="dbc-field"><span>Dietary requirements (optional)</span><textarea name="dietary" rows="2"></textarea></label>' +
			'<label class="dbc-field"><span>Notes (optional)</span><textarea name="notes" rows="2"></textarea></label>' +
			'<div class="dbc-quote" aria-live="polite"></div>' +
			'<div class="dbc-error" id="dbc-contact-error" role="alert" aria-live="assertive"></div>' +
			'<button type="submit" class="dbc-submit">Request booking &amp; quote</button>';

		wrap.appendChild(form);
		updateQuoteBox(form);
		updateCateringLocation(form);
		return wrap;
	}

	function updateQuoteBox(form) {
		var box = form.querySelector('.dbc-quote');
		if (!box) { return; }
		box.setAttribute('aria-busy', state.quoteStatus === 'loading' ? 'true' : 'false');
		var q = state.quote;
		if (state.quoteStatus === 'loading') {
			box.innerHTML = '<span class="dbc-quote-note">Updating estimate…</span>';
			return;
		}
		if (state.quoteStatus === 'error') {
			box.innerHTML = '<span class="dbc-quote-note">Estimate unavailable. Change the package or guest count to retry, or send an enquiry for a staff-confirmed quote.</span>';
			return;
		}
		if (!q) {
			box.innerHTML = '<span class="dbc-quote-note">Add packages with the + buttons to see your subtotal and deposit, or send the form for a custom quote.</span>';
			return;
		}
		var deliveryNote = state.orderType === 'delivery'
			? '<span class="dbc-quote-note">Delivery is quoted separately based on distance.</span>' : '';
		var quoteMoney = function (value) { return esc(q.currency.trim().toUpperCase()) + ' ' + Number(value).toFixed(2); };
		var capacityNote = q.serves_max > 0 && state.guests > q.serves_max
			? '<span class="dbc-quote-note">Your selection serves up to ' + Number(q.serves_max) + ' guests. Add packages, or the team will adjust your quote.</span>' : '';
		var subtotalLine = typeof q.subtotal === 'number' && q.subtotal !== q.total
			? '<div class="dbc-quote-line"><span>Packages subtotal</span><strong>' + quoteMoney(q.subtotal) + '</strong></div>' : '';
		box.innerHTML =
			subtotalLine +
			'<div class="dbc-quote-line"><span>Estimated total</span><strong>' + quoteMoney(q.total) + '</strong></div>' +
			'<div class="dbc-quote-line dbc-quote-deposit"><span>Deposit to book (' + q.deposit_pct + '%)</span><strong>' + quoteMoney(q.deposit) + '</strong></div>' +
			'<div class="dbc-quote-line"><span>Balance later</span><strong>' + quoteMoney(q.balance) + '</strong></div>' +
			'<span class="dbc-quote-note">Indicative estimate from current package prices. Staff confirm the final quote before any payment.</span>' +
			capacityNote +
			deliveryNote;
	}

	function validCateringQuote(quote) {
		if (!quote || typeof quote !== 'object' || Array.isArray(quote)) { return false; }
		var fields = ['subtotal', 'delivery_fee', 'total', 'deposit_pct', 'deposit', 'balance', 'lead_days'];
		return fields.every(function (field) {
			return typeof quote[field] === 'number' && isFinite(quote[field]) && quote[field] >= 0;
		}) && quote.deposit_pct <= 100 && Math.floor(quote.lead_days) === quote.lead_days &&
			typeof quote.currency === 'string' && /^[A-Za-z]{3}$/.test(quote.currency.trim());
	}

	function refreshQuote() {
		// One generation covers package, headcount and fulfilment changes together.
		var generation = ++state.quoteGeneration;
		var lines = Array.isArray(state.lines) ? state.lines.filter(function (line) { return line && line.qty > 0; }) : [];
		var hasSelection = lines.length > 0 || !!state.selectedId;
		state.quote = null;
		state.quoteStatus = hasSelection ? 'loading' : 'idle';
		var form = root.querySelector('.dbc-form');
		if (form) { updateQuoteBox(form); }
		if (!hasSelection) { return; }
		// Only IDs and quantities are sent; the server prices every package.
		var path = '/catering/quote?' + (lines.length
			? 'packages=' + encodeURIComponent(lines.map(function (line) { return line.id + ':' + line.qty; }).join(','))
			: 'package_id=' + state.selectedId) +
			'&guest_count=' + (state.guests || 0) +
			'&order_type=' + encodeURIComponent(state.orderType);
		return get(path).then(function (q) {
			if (generation !== state.quoteGeneration) { return; }
			if (!validCateringQuote(q)) { throw new Error('Invalid catering estimate.'); }
			state.quote = q;
			state.quoteStatus = 'ready';
			var f = root.querySelector('.dbc-form');
			if (f) { updateQuoteBox(f); }
		}).catch(function () {
			if (generation !== state.quoteGeneration) { return; }
			state.quote = null;
			state.quoteStatus = 'error';
			var f = root.querySelector('.dbc-form');
			if (f) { updateQuoteBox(f); }
		});
	}

	function selectionSummary(pkg) {
		var lines = Array.isArray(state.lines) ? state.lines : [];
		if (!lines.length) {
			return pkg ? 'Selected: <strong>' + esc(pkg.name) + '</strong> · ' + money(pkg.price) : 'No packages selected yet — use + to add packages, or send the form for a custom quote.';
		}
		return 'Selected: ' + lines.map(function (line) {
			var match = state.packages.filter(function (p) { return Number(p.id) === line.id; })[0];
			return '<strong>' + line.qty + ' × ' + esc(match ? match.name : 'Package') + '</strong>';
		}).join(', ');
	}

	// Set one package's quantity (0–50) and refresh only the selection chrome
	// and the server quote; contact, event and dietary inputs stay intact.
	function setPackageQuantity(id, qty) {
		id = Number(id);
		if (!state.packages.some(function (p) { return Number(p.id) === id; })) { return; }
		qty = Math.max(0, Math.min(MAX_PACKAGE_QTY, Math.floor(Number(qty) || 0)));
		state.quantities = state.quantities || {};
		if (qty) { state.quantities[id] = qty; } else { delete state.quantities[id]; }
		state.lines = state.packages.filter(function (p) { return state.quantities[Number(p.id)] > 0; }).map(function (p) {
			return { id: Number(p.id), qty: state.quantities[Number(p.id)] };
		});
		state.selectedId = state.lines.length === 1 ? state.lines[0].id : 0;
		var summary = root.querySelector('.dbc-selected');
		if (summary) { summary.innerHTML = selectionSummary(null); }
		Array.prototype.forEach.call(root.querySelectorAll('[data-package-card]'), function (card) {
			var cardId = Number(card.getAttribute('data-package-card'));
			var current = state.quantities[cardId] || 0;
			card.classList.toggle('is-selected', current > 0);
			var output = card.querySelector('[data-qty-for]');
			if (output) { output.textContent = String(current); }
			var minus = card.querySelector('[data-step="-1"]');
			var plus = card.querySelector('[data-step="1"]');
			if (minus) { minus.disabled = current < 1; }
			if (plus) { plus.disabled = current >= MAX_PACKAGE_QTY; }
		});
		refreshQuote();
	}

	/* ---------- interactions ---------- */

	function guardCateringShopChange(event) {
		if (state.submitting || state.locationLocked) { event.preventDefault(); }
	}
	document.addEventListener('doughboss:shop-change-request', guardCateringShopChange);
	document.addEventListener('doughboss:shop-changed', function (event) {
		applyCateringLocation(event.detail && event.detail.id);
	});

	root.addEventListener('click', function (e) {
		if (e.target.closest('[data-catering-locations-retry]')) { loadCateringLocations(); return; }
		var step = e.target.closest('[data-step]');
		if (step && !step.disabled) {
			var packageId = parseInt(step.getAttribute('data-package'), 10) || 0;
			var delta = parseInt(step.getAttribute('data-step'), 10) || 0;
			setPackageQuantity(packageId, packageQuantity(packageId) + delta);
		}
	});

	root.addEventListener('input', function (e) {
		var t = e.target;
		if (t.name === 'guest_count') {
			state.guests = parseInt(t.value, 10) || 0;
			refreshQuote();
		}
	});

	root.addEventListener('change', function (e) {
		var t = e.target;
		if (t.name === 'location_id') {
			var id = cateringLocationId(t.value);
			var intent = new CustomEvent('doughboss:shop-change-request', { cancelable: true, detail: { id: id } });
			if (!id || !state.locations.some(function (location) { return location.id === id; }) || !document.dispatchEvent(intent)) {
				updateCateringLocation(t.form);
				return;
			}
			try { if (window.DoughBossPreferences) { window.DoughBossPreferences.writeShop(id); } } catch (error) { /* Current-page events remain authoritative. */ }
			document.dispatchEvent(new CustomEvent('doughboss:shop-changed', { detail: { id: id } }));
			return;
		}
		if (t.name === 'order_type') {
			state.orderType = t.value === 'delivery' ? 'delivery' : 'pickup';
			var addr = root.querySelector('.dbc-addr');
			if (addr) { addr.hidden = state.orderType !== 'delivery'; }
			refreshQuote();
		}
	});

	function submitCateringEnquiry(e) {
		if (!e.target.classList.contains('dbc-form')) { return; }
		e.preventDefault();
		if (state.submitting) { return; }
		var form = e.target;
		var errBox = form.querySelector('.dbc-error');
		errBox.textContent = '';
		if (state.locationStatus !== 'ready' || (state.locations.length && !selectedCateringLocation())) {
			errBox.textContent = 'Choose an available shop before sending your enquiry. Retry shop information if it could not be loaded.';
			return;
		}

		var fd = new FormData(form);
		var name = (fd.get('customer_name') || '').toString().trim();
		var email = (fd.get('customer_email') || '').toString().trim();
		var nameInput = form.querySelector('[name="customer_name"]');
		var emailInput = form.querySelector('[name="customer_email"]');
		// Match the B2B form's conservative fallback as well as native email
		// validity when available. Never spend a submission on an obvious typo.
		var emailValid = /^[^\s@<>,;"]+@[^\s@<>,;"]+\.[^\s@<>,;"]+$/.test(email);
		if (emailInput && emailInput.validity && emailInput.validity.valid === false) { emailValid = false; }
		var firstInvalid = null;
		var messages = [];
		[[nameInput, !!name, 'Please enter your name.'], [emailInput, emailValid, 'Please enter a valid email address.']].forEach(function (field) {
			if (field[1]) {
				if (field[0]) { field[0].removeAttribute('aria-invalid'); }
			} else {
				messages.push(field[2]);
				if (field[0]) {
					field[0].setAttribute('aria-invalid', 'true');
					if (!firstInvalid) { firstInvalid = field[0]; }
				}
			}
		});
		if (messages.length) {
			errBox.textContent = messages.join(' ');
			if (firstInvalid && firstInvalid.focus) { firstInvalid.focus(); }
			return;
		}
		state.email = email;
		state.name = name;
		state.submitting = true;
		var location = selectedCateringLocation();
		state.submittedLocationName = location ? location.name : 'a staff-confirmed shop';
		updateCateringLocation(form);

		var btn = form.querySelector('.dbc-submit');
		btn.disabled = true;
		var prev = btn.textContent;
		btn.textContent = 'Sending…';

		return post('/catering/enquiry', {
			location_id: state.locationId,
			customer_name: name,
			customer_email: email,
			customer_phone: (fd.get('customer_phone') || '').toString(),
			package_id: state.selectedId,
			// Combined selection: IDs and quantities only. Prices are server-side.
			packages: Array.isArray(state.lines) ? state.lines.map(function (line) { return { id: line.id, qty: line.qty }; }) : [],
			guest_count: parseInt(fd.get('guest_count'), 10) || 0,
			order_type: state.orderType,
			event_date: (fd.get('event_date') || '').toString(),
			event_time: (fd.get('event_time') || '').toString(),
			address: (fd.get('address') || '').toString(),
			dietary: (fd.get('dietary') || '').toString(),
			notes: (fd.get('notes') || '').toString(),
			hp: (fd.get('hp') || '').toString()
		}).then(function (res) {
			if (!res.ok || !res.data || !res.data.success) {
				var msg = res.data && res.data.message ? res.data.message : 'Something went wrong. Please try again.';
				errBox.textContent = msg;
				btn.disabled = false;
				btn.textContent = prev;
				return;
			}
			showSuccess(res.data);
		}).catch(function () {
			errBox.textContent = 'Something went wrong. Please try again.';
			btn.disabled = false;
			btn.textContent = prev;
		}).then(function () {
			state.submitting = false;
			updateCateringLocation(form);
		});
	}
	root.addEventListener('submit', submitCateringEnquiry);

	function showSuccess(data) {
		var pay = (window.DoughBossData && window.DoughBossData.payments) || {};
		var canPay = pay.enabled && data.deposit > 0 && ((pay.gateway === 'tyro' && typeof window.Tyro === 'function') || (pay.gateway === 'stripe' && pay.pk && typeof window.Stripe === 'function'));

		var flow = cateringFlow();
		flow.innerHTML = '';
		var box = el('<div class="dbc-success" role="status"></div>');
		box.innerHTML =
			'<div class="dbc-success-check">✓</div>' +
			'<h2 class="dbc-h2">Enquiry received</h2>' +
			'<p class="dbc-success-num">Reference: <strong>' + esc(data.enquiry_number) + '</strong></p>';
		box.appendChild(el('<p class="dbc-sub">Shop: ' + esc(state.submittedLocationName) + '. Your event details, availability and final quote still need staff confirmation. This is not a confirmed booking.</p>'));
		box.appendChild(el('<p>Keep your reference. Reply to your catering acknowledgement or use the catering contact details above to discuss this enquiry.</p>'));
		box.appendChild(el('<p class="dbc-sub">Planning another event? Contact the team with the new date, guest count and preferred pickup time. Each event needs its own availability and quote confirmation.</p>'));
		var contact = root.querySelector('.dbc-contact');
		if (contact) {
			var contactButton = document.createElement('button');
			contactButton.type = 'button';
			contactButton.className = 'dbc-submit';
			contactButton.textContent = 'Contact catering about this enquiry';
			contactButton.addEventListener('click', function () {
				if (contact.scrollIntoView) { contact.scrollIntoView({ block: 'start' }); }
				var link = contact.querySelector('a[href]');
				if (link && link.focus) { link.focus(); }
			});
			box.appendChild(contactButton);
		}
		flow.appendChild(box);

		if (canPay) {
			box.appendChild(el('<p>Secure your date with a deposit of <strong>' + money(data.deposit) + '</strong>.</p>'));
			var panel = el(
				'<div class="dbc-pay">' +
					'<label class="dbc-pay-label">Secure payment</label>' +
					'<div class="dbc-card-element"></div>' +
					'<div class="dbc-error" role="alert" aria-live="assertive"></div>' +
					'<button type="button" class="dbc-submit dbc-pay-btn">Pay ' + money(data.deposit) + ' deposit</button>' +
					'<p class="dbc-sub dbc-pay-secure">Secure provider-hosted payment · balance payable later.</p>' +
				'</div>'
			);
			box.appendChild(panel);
			mountPayment(panel, data);
		}

		if (root.scrollIntoView) { root.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
	}

	function mountPayment(panel, data) {
		var pay = window.DoughBossData.payments;
		var errBox = panel.querySelector('.dbc-error');
		var stripe, stripeElements, paymentElement, tyro;
		var paymentId = '';
		var confirmedPaymentId = '';
		var stripeClientSecret = '';
		var attemptKey = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (String(Date.now()) + '-' + Math.random());
		var cardMount = panel.querySelector('.dbc-card-element');
		var cardLabel = panel.querySelector('.dbc-pay-label');
		var btn = panel.querySelector('.dbc-pay-btn');
		var payButtonLabel = 'Pay ' + money(data.deposit) + ' deposit';
		cardMount.id = 'dbc-tyro-' + String(Date.now());
		if (pay.gateway === 'tyro') {
			btn.disabled = true;
			post('/catering/payment-intent', { enquiry_number: data.enquiry_number, email: state.email, leg: 'deposit', payment_attempt_key: attemptKey }).then(function (res) {
				if (!res.ok || !res.data || !res.data.client_secret) { throw new Error((res.data && res.data.message) || 'Could not start the payment.'); }
				paymentId = res.data.payment_intent;
				tyro = window.Tyro({ liveMode: !!pay.liveMode });
				return tyro.init(res.data.client_secret);
			}).then(function () {
				var form = tyro.createPayForm({ theme: 'minimal', options: { creditCardForm: { enabled: true }, applePay: { enabled: false }, googlePay: { enabled: false } } });
				return form.inject('#' + cardMount.id);
			}).then(function () {
				btn.disabled = false;
				panel.querySelector('.dbc-pay-secure').textContent = 'Secure payment by Tyro · bank verification may appear here.';
			}).catch(function (err) { errBox.textContent = err.message || 'Card payments are unavailable right now.'; });
		} else {
			try {
				stripe = window.Stripe(pay.pk);
				cardLabel.style.display = 'none';
				cardMount.style.display = 'none';
				btn.textContent = 'Continue to payment';
			} catch (e) {
				errBox.textContent = 'Card payments are unavailable right now.';
				btn.disabled = true;
				return;
			}
		}

		function resetStripePaymentForm() {
			if (paymentElement) {
				try { paymentElement.unmount(); } catch (ignoreUnmount) {}
			}
			paymentElement = null;
			stripeElements = null;
			stripeClientSecret = '';
			paymentId = '';
			cardMount.innerHTML = '';
			cardMount.style.display = 'none';
			cardLabel.style.display = 'none';
			btn.disabled = false;
			btn.textContent = 'Continue to payment';
		}

		function prepareStripePayment() {
			btn.textContent = 'Preparing secure payment...';
			return post('/catering/payment-intent', {
				enquiry_number: data.enquiry_number,
				email: state.email,
				leg: 'deposit',
				payment_attempt_key: attemptKey
			}).then(function (res) {
				if (!res.ok || !res.data || !res.data.client_secret || !res.data.payment_intent) {
					throw new Error((res.data && res.data.message) || 'Could not start the payment.');
				}
				paymentId = res.data.payment_intent;
				stripeClientSecret = res.data.client_secret;
				stripeElements = stripe.elements({
					clientSecret: stripeClientSecret,
					appearance: {
						theme: 'stripe',
						variables: {
							colorPrimary: '#e52b2f',
							colorText: '#171717',
							borderRadius: '10px'
						}
					}
				});
				paymentElement = stripeElements.create('payment', {
					layout: {
						type: 'accordion',
						defaultCollapsed: false,
						radios: 'never',
						spacedAccordionItems: true
					},
					defaultValues: {
						billingDetails: {
							name: state.name,
							email: state.email
						}
					},
					paymentMethodOrder: ['card'],
					wallets: { applePay: 'auto', googlePay: 'auto' }
				});
				paymentElement.on('change', function (event) {
					errBox.textContent = event && event.error ? (event.error.message || 'Check your payment details.') : '';
				});
				paymentElement.on('loaderror', function (event) {
					var loadMessage = event && event.error && event.error.message ? event.error.message : 'The secure payment form could not load. Please try again.';
					resetStripePaymentForm();
					errBox.textContent = loadMessage;
				});
				paymentElement.on('ready', function () {
					try { paymentElement.focus(); } catch (ignoreFocus) {}
					if (window.innerWidth < 720 && cardMount.scrollIntoView) {
						cardMount.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
					}
					panel.querySelector('.dbc-pay-secure').textContent = 'Secure Stripe payment · Apple Pay or Google Pay appears when supported.';
					btn.disabled = false;
					btn.textContent = payButtonLabel;
				});
				cardLabel.style.display = '';
				cardMount.style.display = '';
				paymentElement.mount(cardMount);
				panel.querySelector('.dbc-pay-secure').textContent = 'Loading secure payment options...';
			}).catch(function (err) {
				resetStripePaymentForm();
				throw err;
			});
		}

		btn.addEventListener('click', function () {
			errBox.textContent = '';
			btn.disabled = true;
			var prev = btn.textContent;
			btn.textContent = confirmedPaymentId ? 'Confirming deposit...' : 'Processing...';

			if (pay.gateway === 'stripe' && !paymentElement && !confirmedPaymentId) {
				prepareStripePayment().catch(function (err) {
					errBox.textContent = err.message || 'Card payments are unavailable right now.';
					btn.disabled = false;
					btn.textContent = 'Continue to payment';
				});
				return;
			}

			var payment;
			if (confirmedPaymentId) {
				payment = Promise.resolve(confirmedPaymentId);
			} else if (pay.gateway === 'tyro') {
				payment = tyro.submitPay().then(function () {
				return tyro.fetchPayRequest();
			}).then(function (result) {
				var req = result && result.payRequest ? result.payRequest : result;
				if (!req || String(req.status).toUpperCase() !== 'SUCCESS') { throw new Error('Your payment is still being checked. Do not pay again; please wait and retry confirmation.'); }
					confirmedPaymentId = paymentId;
					return confirmedPaymentId;
				});
			} else {
				payment = stripeElements.submit().then(function (result) {
					if (result && result.error) {
						throw new Error(result.error.message || 'Check your payment details.');
					}
					rememberStripePayment(data, paymentId);
					return stripe.confirmPayment({
						elements: stripeElements,
						clientSecret: stripeClientSecret,
						confirmParams: {
							return_url: cateringPaymentReturnUrl()
						},
						redirect: 'if_required'
					});
				}).then(function (result) {
					if (result.error) { throw new Error(result.error.message || 'Your card was declined.'); }
					if (!result.paymentIntent || result.paymentIntent.id !== paymentId) {
						throw new Error('The completed payment could not be matched safely.');
					}
					confirmedPaymentId = result.paymentIntent.id;
					return confirmedPaymentId;
				});
			}

			payment.then(function (confirmedId) {
				return post('/catering/confirm-payment', {
					enquiry_number: data.enquiry_number,
					email: state.email,
					leg: 'deposit',
					payment_intent_id: confirmedId
				});
			}).then(function (conf) {
				if (!conf.ok || !conf.data || !conf.data.success) {
					throw new Error((conf.data && conf.data.message) || 'We could not confirm your payment.');
				}
				showPaid(conf.data, data);
			}).catch(function (err) {
				errBox.textContent = err.message || 'Something went wrong. Please try again.';
				btn.disabled = false;
				btn.textContent = confirmedPaymentId ? 'Confirm paid deposit' : prev;
			});
		});
	}

	function cateringPaymentReturnUrl() {
		var current = new URL(window.location.href);
		var safe = new URL(current.origin + current.pathname);
		['page_id', 'p'].forEach(function (key) {
			var value = current.searchParams.get(key);
			if (/^[1-9][0-9]*$/.test(value || '')) { safe.searchParams.set(key, value); }
		});
		if (current.searchParams.get('preview') === 'true') { safe.searchParams.set('preview', 'true'); }
		return safe.toString();
	}

	// A 3DS challenge may leave this page and return after Stripe has already
	// captured the deposit. Persist only the short-lived browser-local reference
	// needed to finish the server-side verification on return; the REST endpoint
	// still retrieves Stripe and checks the immutable enquiry/amount binding.
	function rememberStripePayment(data, paymentIntentId) {
		try {
			window.sessionStorage.setItem('doughbossCateringPending', JSON.stringify({
				enquiryNumber: data.enquiry_number,
				email: state.email,
				leg: 'deposit',
				paymentIntentId: paymentIntentId,
				savedAt: Date.now()
			}));
		} catch (ignoreStorage) {}
	}

	function clearRememberedStripePayment() {
		try { window.sessionStorage.removeItem('doughbossCateringPending'); } catch (ignoreStorage) {}
	}

	function resumeStripePaymentReturn() {
		if (!(window.DoughBossData && window.DoughBossData.payments && window.DoughBossData.payments.gateway === 'stripe') || typeof window.URLSearchParams !== 'function') {
			return false;
		}
		var params = new URLSearchParams(window.location.search);
		var returnedId = params.get('payment_intent') || '';
		if (!returnedId) {
			return false;
		}
		var pending = null;
		try { pending = JSON.parse(window.sessionStorage.getItem('doughbossCateringPending') || 'null'); } catch (ignoreStorage) {}

		// Remove Stripe's return parameters (including the client secret) from the
		// visible URL regardless of whether this tab holds the matching attempt.
		try {
			var clean = new URL(window.location.href);
			['payment_intent', 'payment_intent_client_secret', 'redirect_status'].forEach(function (key) { clean.searchParams.delete(key); });
			window.history.replaceState({}, document.title, clean.toString());
		} catch (ignoreHistory) {}

		if (!pending || pending.paymentIntentId !== returnedId || !pending.enquiryNumber || !pending.email || pending.leg !== 'deposit' || (Date.now() - Number(pending.savedAt || 0)) > 30 * 60 * 1000) {
			return false;
		}

		cateringFlow().innerHTML = '<div class="dbc-builder"><p class="dbc-sub" role="status">Confirming your secure deposit payment. Please do not pay again.</p></div>';
		post('/catering/confirm-payment', {
			enquiry_number: pending.enquiryNumber,
			email: pending.email,
			leg: pending.leg,
			payment_intent_id: pending.paymentIntentId
		}).then(function (conf) {
			if (!conf.ok || !conf.data || !conf.data.success) {
				throw new Error((conf.data && conf.data.message) || 'We could not confirm your payment.');
			}
			clearRememberedStripePayment();
			showPaid(conf.data, { enquiry_number: pending.enquiryNumber });
		}).catch(function () {
			cateringFlow().innerHTML = '<div class="dbc-builder"><p class="dbc-sub" role="alert">Your payment return could not be confirmed yet. Please do not pay again; contact us so we can check it safely.</p></div>';
		});
		return true;
	}

	function showPaid(conf, data) {
		clearRememberedStripePayment();
		var flow = cateringFlow();
		flow.innerHTML = '';
		flow.appendChild(el(
			'<div class="dbc-success" role="status">' +
				'<div class="dbc-success-check">✓</div>' +
				'<h2 class="dbc-h2">Deposit received</h2>' +
				'<p class="dbc-success-num">Reference: <strong>' + esc(data.enquiry_number) + '</strong></p>' +
				'<p class="dbc-sub">' + esc(conf.message || 'Your date is secured. We\'ll be in touch with the details.') + '</p>' +
			'</div>'
		));
		if (root.scrollIntoView) { root.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
	}

	/* ---------- boot ---------- */

	if (resumeStripePaymentReturn()) {
		return;
	}
	loadCateringLocations();

	get('/catering/packages').then(function (list) {
		if (!Array.isArray(list)) { throw new Error('Invalid catering packages.'); }
		state.packages = list;
		render();
	}).catch(function () {
		state.packagesUnavailable = true;
		render();
	});
}());
