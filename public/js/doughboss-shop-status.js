/** Shared pickup-hours presentation. Public reads only; this never books a slot. */
(function () {
	'use strict';
	var data = window.DoughBossShopData;
	if (!data) { return; }
	var watches = [];
	var snapshotRequest = null;
	var lastRefresh = 0;
	var snapshot = null;
	var tickTimer = null;

	function pickupStatusText(location, config, now) {
		if (!config || !config.ordering_open) { return 'Online ordering is paused. Browse the menu or contact the shop.'; }
		if (!config.enable_pickup || !location || !location.pickup_enabled) { return 'Online pickup is unavailable at this shop.'; }
		var status = location.pickup_status;
		var observed = status && Date.parse(status.observed_at_utc);
		var expires = status && Date.parse(status.expires_at_utc);
		if (!status || !Number.isFinite(observed) || !Number.isFinite(expires) || expires <= now || observed > now + 5000 || expires - observed > 60000) {
			return 'Pickup hours unconfirmed. Please check with the shop.';
		}
		if (status.state === 'open') { return 'Pickup hours: open now. Availability confirmed at checkout.'; }
		if (status.state === 'closes_soon') { return 'Pickup hours: closing soon. Please check before ordering.'; }
		if (status.state === 'closed') {
			return status.next_open_label ? 'Pickup is closed. Next scheduled opening: ' + status.next_open_label + '. Not a pre-order booking.' : 'Pickup is closed. Please check with the shop for the next opening.';
		}
		return 'Pickup hours unconfirmed. Please check with the shop.';
	}

	function locationById(locations, id) {
		return locations.filter(function (location) { return Number(location.id) === Number(id); })[0] || null;
	}

	function read(path) {
		var controller = typeof AbortController === 'function' ? new AbortController() : null;
		var timeout = controller ? setTimeout(function () { controller.abort(); }, 8000) : null;
		// These routes are public. Table authority is its signed HttpOnly cookie,
		// not WP user authentication; an expired page-cache nonce must not break reads.
		return fetch(data.restUrl + path, {
			credentials: 'same-origin', cache: 'no-store',
			signal: controller ? controller.signal : undefined
		}).then(function (response) {
			if (!response.ok) { throw new Error('Shop details are unavailable.'); }
			return response.json();
		}).then(function (result) {
			if (timeout) { clearTimeout(timeout); }
			return result;
		}, function (error) {
			if (timeout) { clearTimeout(timeout); }
			throw error;
		});
	}

	function refresh() {
		if (snapshotRequest) { return snapshotRequest; }
		lastRefresh = Date.now();
		snapshotRequest = Promise.all([read('/locations'), read('/config')]).then(function (results) {
			if (!Array.isArray(results[0]) || !results[1] || typeof results[1].ordering_open !== 'boolean') {
				throw new Error('Shop details are unavailable.');
			}
			snapshot = { locations: results[0], config: results[1] };
			watches.forEach(function (watch) {
				watch.location = locationById(snapshot.locations, watch.id);
				watch.config = snapshot.config;
			});
			paint();
			return snapshot;
		}).then(function (result) { snapshotRequest = null; return result; }, function (error) {
			snapshotRequest = null;
			// Old successful hours must not survive a failed refresh indefinitely.
			watches.forEach(function (watch) { watch.location = null; });
			paint();
			throw error;
		});
		return snapshotRequest;
	}

	function paint() {
		watches = watches.filter(function (watch) { return watch.node.isConnected; });
		watches.forEach(function (watch) {
			var text = watch.location ? pickupStatusText(watch.location, watch.config, Date.now()) : 'Shop availability could not be confirmed. Please contact the shop.';
			if (watch.node.textContent !== text) { watch.node.textContent = text; }
		});
		scheduleTick();
	}

	function attach(node, location, config) {
		watches = watches.filter(function (watch) { return watch.node !== node && watch.node.isConnected; });
		if (snapshot && Date.now() - lastRefresh < 60000 && location) {
			location = locationById(snapshot.locations, location.id);
			config = snapshot.config;
		}
		watches.push({ node: node, id: location ? Number(location.id) : 0, location: location, config: config });
		node.textContent = pickupStatusText(location, config, Date.now());
		scheduleTick();
	}

	function chosenId(locations) {
		var saved = 0;
		try { saved = Number(window.localStorage.getItem('doughboss_location')); } catch (error) { /* Optional preference only. */ }
		return locationById(locations, saved) ? saved : (locations.length ? Number(locations[0].id) : 0);
	}

	function renderHeader(root) {
		Promise.all([refresh(), read('/table/context')]).then(function (results) {
			var locations = results[0].locations;
			var config = results[0].config;
			var table = results[1];
			root.textContent = '';
			if (table && table.active && table.location && table.table) {
				root.textContent = table.location.name + ' \u00b7 Table ' + table.table.label + ' \u00b7 Dine in';
				return;
			}
			if (!locations.length) { throw new Error('No online shops are configured.'); }
			var current = chosenId(locations);
			var label = document.createElement(locations.length > 1 ? 'label' : 'div');
			label.className = 'dbf-shop-choice';
			var caption = document.createElement('span');
			caption.textContent = 'Pickup shop';
			label.appendChild(caption);
			var select = document.createElement('select');
			select.setAttribute('aria-label', 'Choose your pickup shop');
			locations.forEach(function (location) {
				var option = document.createElement('option');
				option.value = String(location.id);
				option.textContent = location.name;
				select.appendChild(option);
			});
			select.value = String(current);
			// One real online location is not presented as three orderable stores.
			if (locations.length > 1) { label.appendChild(select); }
			else {
				var name = document.createElement('strong');
				name.textContent = locations[0].name;
				label.appendChild(name);
			}
			var status = document.createElement('span');
			status.className = 'db-pickup-status';
			status.setAttribute('role', 'status');
			status.setAttribute('aria-live', 'polite');
			function show(id) {
				current = id;
				select.value = String(id);
				attach(status, locationById(snapshot.locations, id), snapshot.config);
			}
			select.addEventListener('change', function () {
				var id = Number(select.value);
				var intent = new CustomEvent('doughboss:shop-change-request', { cancelable: true, detail: { id: id } });
				// The ordering application can veto an immutable in-flight checkout.
				if (!document.dispatchEvent(intent) || !locationById(snapshot.locations, id)) { select.value = String(current); return; }
				try { window.localStorage.setItem('doughboss_location', String(id)); } catch (error) { /* Current-page selection still works. */ }
				show(id);
				document.dispatchEvent(new CustomEvent('doughboss:shop-changed', { detail: { id: id } }));
			});
			document.addEventListener('doughboss:shop-changed', function (event) {
				var id = event.detail && Number(event.detail.id);
				if (locationById(snapshot.locations, id)) { show(id); }
			});
			root.appendChild(label);
			root.appendChild(status);
			show(current);
		}).catch(function () {
			root.textContent = '';
			var fallback = document.createElement('a');
			fallback.href = root.getAttribute('data-locations-url');
			fallback.textContent = 'Check locations and pickup availability';
			root.appendChild(fallback);
		});
	}

	window.DoughBossShopStatus = { attach: attach };
	function scheduleTick() {
		if (tickTimer) { clearTimeout(tickTimer); }
		var delay = 15000;
		watches.forEach(function (watch) {
			var status = watch.location && watch.location.pickup_status;
			var until = status && Date.parse(status.expires_at_utc) - Date.now();
			if (until > 0) { delay = Math.min(delay, until + 20); }
		});
		tickTimer = setTimeout(tick, Math.max(100, delay));
	}
	function tick() {
		if (!document.hidden) {
			paint();
			if (watches.length && Date.now() - lastRefresh >= 45000) { refresh().catch(function () {}); }
		}
		scheduleTick();
	}
	function boot() {
		document.querySelectorAll('[data-doughboss-header-shop]').forEach(renderHeader);
		tick();
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden) {
				paint();
				if (watches.length && Date.now() - lastRefresh >= 45000) { refresh().catch(function () {}); }
			}
		});
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); }
	else { boot(); }
}());
