/**
 * DoughBoss â€” Live Order Board (Kitchen Display).
 *
 * Polls the admin orders feed, renders active orders into New / Preparing /
 * Ready lanes, raises an audible + visual alert on new orders until staff
 * acknowledge, and lets staff accept (with an ETA) and advance order status
 * with one tap. Vanilla JS; all customer-supplied text is set via textContent.
 */
(function () {
	'use strict';

	var cfg = window.DoughBossBoard;
	if (!cfg || !cfg.restUrl) {
		return;
	}

	var STATUSES = cfg.statuses || {};
	var ETA_CHOICES = [10, 15, 20, 30];
	var SCREEN_MODE = cfg.screenMode === 'make' || cfg.screenMode === 'pass' ? cfg.screenMode : 'all';
	var ALL_LANES = [
		{ key: 'new', title: 'New', statuses: ['pending'] },
		{ key: 'prep', title: 'Preparing', statuses: ['confirmed', 'preparing', 'baking'] },
		{ key: 'ready', title: 'Ready', statuses: ['ready', 'out_for_delivery'] }
	];
	var LANES = SCREEN_MODE === 'make'
		? [
			{ key: 'new', title: 'New', statuses: ['pending'] },
			{ key: 'bench', title: 'On the bench', statuses: ['confirmed', 'preparing'] },
			{ key: 'oven', title: 'Oven', statuses: ['baking'] }
		]
		: (SCREEN_MODE === 'pass'
			? [{ key: 'ready', title: 'Ready to call', statuses: ['ready', 'out_for_delivery'] }]
			: ALL_LANES);

	var boardEl = document.getElementById('db-board');
	var preorderPanel = document.getElementById('db-preorder-review');
	var statusEl = document.querySelector('.db-board-status');
	var soundBtn = document.querySelector('.db-sound-toggle');
	var actionsEl = document.querySelector('.db-board-actions');

	var LOCATIONS = cfg.locations || [];
	var locationsById = {};
	LOCATIONS.forEach(function (l) { locationsById[l.id] = l.name; });
	var currentLocation = 0; // 0 = all shops

	var localAck = {};      // Optimistically-acknowledged order IDs.
	// A tablet can be tapped twice before the server response arrives. Keep one
	// command per order in flight, rather than relying on the browser or a later
	// refresh to discover the duplicate. The server-side event key/version check
	// is still the source of truth.
	var inFlight = {};
	var audio = { ctx: null, on: false, timer: null };
	var pollTimer = null;
	var retryBtn = null;
	var lastSuccessfulSync = null;
	// Multiple refresh signals can overlap (poll, SSE and a staff action). Only
	// the newest response may repaint the board, otherwise a slower stale request
	// can visually undo a just-completed status transition.
	var loadEpoch = 0;

	// Mercure SSE transport (optional). When connected and healthy, the ~7s poll
	// is slowed to a long safety net; on any SSE error we fall straight back to
	// the normal poll cadence. The poll is NEVER disabled entirely.
	var mercure = cfg.mercure || null;
	var sse = null;
	var sseHealthy = false;
	var POLL_FAST = cfg.pollMs || 7000;
	var POLL_SAFETY = 60000;

	/* ----------------------------------------------------------------- DOM */

	function el(tag, props, children) {
		var node = document.createElement(tag);
		if (props) {
			Object.keys(props).forEach(function (k) {
				if (k === 'class') { node.className = props[k]; }
				else if (k === 'text') { node.textContent = props[k]; }
				else if (k.indexOf('on') === 0 && typeof props[k] === 'function') {
					node.addEventListener(k.slice(2).toLowerCase(), props[k]);
				} else { node.setAttribute(k, props[k]); }
			});
		}
		(children || []).forEach(function (c) {
			if (c === null || c === undefined) { return; }
			node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return node;
	}

	function money(amount) {
		return (cfg.currency || '$') + (Math.round(amount * 100) / 100).toFixed(2);
	}

	function elapsed(created) {
		var mins = minutesSince(created);
		return mins === null ? '' : mins + 'm ago';
	}

	// Whole minutes since a UTC 'YYYY-MM-DD HH:MM:SS' datetime, or null.
	function minutesSince(dt) {
		if (!dt) { return null; }
		var t = Date.parse(String(dt).replace(' ', 'T') + 'Z');
		if (isNaN(t)) { return null; }
		return Math.max(0, Math.floor((Date.now() - t) / 60000));
	}

	function label(status) {
		return STATUSES[status] || status;
	}

	function orderLabel(order, status) {
		if (order && order.order_type === 'dine_in') {
			if (status === 'ready') { return 'Ready to Serve'; }
			if (status === 'completed') { return 'Served'; }
		}
		return order && status === order.status && order.status_label ? order.status_label : label(status);
	}

	function eventKey(o, target) {
		return ['kds', o.id, o.version, target, Date.now(), Math.random().toString(36).slice(2)].join(':');
	}

	function formatTime(value, timezone) {
		if (!value) { return ''; }
		var date = new Date(value);
		if (isNaN(date.getTime())) { return ''; }
		var options = { hour: 'numeric', minute: '2-digit' };
		if (timezone) { options.timeZone = timezone; }
		try { return new Intl.DateTimeFormat('en-AU', options).format(date); }
		catch (e) { delete options.timeZone; return new Intl.DateTimeFormat('en-AU', options).format(date); }
	}

	function readyWindow(o) {
		var from = formatTime(o.promised_ready_from_utc, o.timezone);
		var by = formatTime(o.promised_ready_by_utc, o.timezone);
		if (!from) { return ''; }
		return by && by !== from ? from + 'â€“' + by : from;
	}

	/* --------------------------------------------------------------- Sound */

	function enableSound() {
		if (!audio.ctx) {
			try { audio.ctx = new (window.AudioContext || window.webkitAudioContext)(); }
			catch (e) { return; }
		}
		if (audio.ctx.state === 'suspended') { audio.ctx.resume(); }
		audio.on = true;
		if (soundBtn) {
			soundBtn.setAttribute('aria-pressed', 'true');
			soundBtn.textContent = 'ðŸ”” Sound on';
			soundBtn.classList.add('is-on');
		}
		beep();
	}

	function beep() {
		if (!audio.on || !audio.ctx) { return; }
		var o = audio.ctx.createOscillator();
		var g = audio.ctx.createGain();
		o.type = 'sine';
		o.frequency.value = 880;
		o.connect(g);
		g.connect(audio.ctx.destination);
		var now = audio.ctx.currentTime;
		g.gain.setValueAtTime(0.0001, now);
		g.gain.exponentialRampToValueAtTime(0.3, now + 0.02);
		g.gain.exponentialRampToValueAtTime(0.0001, now + 0.4);
		o.start(now);
		o.stop(now + 0.42);
	}

	function startAlert() {
		document.body.classList.add('db-alerting');
		if (audio.timer) { return; }
		beep();
		audio.timer = setInterval(beep, 1500);
	}

	function stopAlert() {
		document.body.classList.remove('db-alerting');
		if (audio.timer) { clearInterval(audio.timer); audio.timer = null; }
	}

	/* ----------------------------------------------------------------- API */

	function api(path, method, body) {
		var headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce };
		if (cfg.boardKey) { headers['X-DoughBoss-Board-Key'] = cfg.boardKey; }
		return fetch(cfg.restUrl + path, {
			method: method || 'GET',
			headers: headers,
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (data) {
				if (!r.ok) { throw new Error(data.message || 'Request failed.'); }
				return data;
			});
		});
	}

	function setBusy(o, action, busy) {
		var key = String(o.id);
		if (busy) {
			if (inFlight[key]) { return false; }
			inFlight[key] = action;
		} else {
			delete inFlight[key];
		}
		var cardEl = boardEl && boardEl.querySelector('[data-order-id="' + key + '"]');
		if (cardEl) {
			cardEl.classList.toggle('db-card-busy', !!busy);
			cardEl.setAttribute('aria-busy', busy ? 'true' : 'false');
			var buttons = cardEl.querySelectorAll('button');
			for (var i = 0; i < buttons.length; i++) { buttons[i].disabled = !!busy || offline; }
		}
		return true;
	}

	function setPreorderBusy(o, action, busy) {
		var key = String(o.id);
		if (busy) {
			if (inFlight[key]) { return false; }
			inFlight[key] = action;
		} else {
			delete inFlight[key];
		}
		var cardEl = preorderPanel && preorderPanel.querySelector('[data-preorder-id="' + key + '"]');
		if (cardEl) {
			cardEl.setAttribute('aria-busy', busy ? 'true' : 'false');
			var buttons = cardEl.querySelectorAll('button');
			for (var i = 0; i < buttons.length; i++) { buttons[i].disabled = !!busy || offline; }
		}
		return true;
	}

	function accept(o, eta) {
		if (offline) { return; }
		if (!setBusy(o, 'accept', true)) { return; }
		localAck[o.id] = true;
		api('/admin/order/' + o.id + '/accept', 'POST', {
			eta: eta || 0,
			expected_version: o.version,
			event_key: eventKey(o, 'confirmed')
		}).then(function () {
			return load();
		}).catch(function (error) {
			delete localAck[o.id];
			var message = error.message || 'Could not accept the order.';
			return load().then(function () { if (statusEl) { statusEl.textContent = message; } });
		}).then(function () {
			setBusy(o, 'accept', false);
		});
	}

	function setStatus(o, status) {
		if (offline) { return Promise.resolve(); }
		if (!setBusy(o, status, true)) { return Promise.resolve(); }
		return api('/admin/order/' + o.id + '/status', 'POST', {
			status: status,
			expected_version: o.version,
			event_key: eventKey(o, status),
			reason_code: status === 'cancelled' ? 'staff_cancelled' : ''
		}).then(function () {
			return load();
		}).catch(function (error) {
			var message = error.message || 'Could not update the order.';
			return load().then(function () { if (statusEl) { statusEl.textContent = message; } });
		}).then(function () {
			setBusy(o, status, false);
		});
	}

	function orderTag(o) {
		var n = String(o.order_number || o.id);
		return n.charAt(0) === '#' ? n : '#' + n;
	}

	function acknowledgeAll(ids) {
		if (offline) { return; }
		ids.forEach(function (id) {
			localAck[id] = true;
			api('/admin/order/' + id + '/ack', 'POST', {}).catch(function (error) {
				delete localAck[id];
				if (statusEl) { statusEl.textContent = error.message || 'Could not acknowledge an order.'; }
			});
		});
		stopAlert();
		render(lastOrders);
	}

	/* -------------------------------------------------------------- Render */

	var lastOrders = [];

	function laneOf(status) {
		for (var i = 0; i < LANES.length; i++) {
			if (LANES[i].statuses.indexOf(status) !== -1) { return LANES[i].key; }
		}
		return '';
	}

	function laneSubtitle(key) {
		var labels = {
			new: 'Accept only after checking notes, allergies and timing.',
			prep: 'Making now - keep oldest tickets moving first.',
			bench: 'Prep and toppings - watch dietary notes.',
			oven: 'Baking / finishing - send to pass when ready.',
			ready: SCREEN_MODE === 'pass' ? 'Call customer, hand over, then clear as collected.' : 'Ready for pass / pickup.'
		};
		return labels[key] || 'Live orders';
	}

	// Screen wording follows the real bench workflow while preserving the
	// server-authoritative status values and transition permissions.
	function actionLabel(o, status) {
		if (SCREEN_MODE === 'make') {
			if (status === 'preparing') { return 'Start prep'; }
			if (status === 'baking') { return 'Move to oven'; }
			if (status === 'ready') { return 'Send to pass'; }
		}
		if (SCREEN_MODE === 'pass' && status === 'completed') {
			return o.order_type === 'dine_in' ? 'Served' : 'Collected';
		}
		return orderLabel(o, status);
	}

	function advanceActions(o) {
		return (o.allowed_next_statuses || []).filter(function (status) { return status !== 'cancelled'; });
	}

	function serviceLabel(o) {
		if (o.order_source === 'catering' || o.order_type === 'catering') { return 'Catering'; }
		if (o.order_type === 'delivery') { return 'Delivery'; }
		if (o.order_type === 'dine_in') { return o.table_label ? 'Table service' : 'Dine in'; }
		if (o.order_source === 'store_qr' || o.order_source === 'counter_qr') { return 'Counter pickup'; }
		return 'Pickup';
	}

	function sourceLabel(source) {
		var labels = {
			'table_qr': 'Table QR',
			'store_qr': 'Store QR',
			'counter_qr': 'Counter QR',
			'web': 'Online',
			'staff': 'Staff',
			'catering': 'Catering'
		};
		return labels[source] || '';
	}

	function paymentLabel(status) {
		var labels = {
			'paid': 'Paid',
			'unpaid': 'Pay at counter',
			'pending': 'Payment pending',
			'failed': 'Payment failed - manager check',
			'refunded': 'Refunded'
		};
		return labels[status] || 'Payment check';
	}

	function toppingLabel(value) {
		if (value && typeof value === 'object') { return value.label || value.name || value.slug || ''; }
		return String(value || '');
	}

	function exceptionMessages(o) {
		var messages = [];
		if (o.timing_status === 'estimate_passed') { messages.push(o.timing_label || 'Estimate passed - check this order.'); }
		if (o.payment_status === 'refunded') { messages.push('Refunded - pause preparation and check with a manager.'); }
		if (!Array.isArray(o.allowed_next_statuses)) { messages.push('Status controls are unavailable - refresh before acting.'); }
		if (hasAllergenNote(o)) { messages.push('Allergy / dietary note - read before making.'); }
		return messages;
	}

	function hasAllergenNote(o) {
		var itemText = (o && o.items || []).map(function (item) {
			return [item.name || '', item.size || ''].concat((item.toppings || []).map(toppingLabel)).join(' ');
		}).join(' ');
		var text = (String(o && o.notes || '') + ' ' + itemText).toLowerCase();
		return /\b(allerg|gluten|nut|peanut|sesame|dairy|egg|soy|shellfish|vegan|vegetarian|halal|lactose|celiac|coeliac)\b/.test(text);
	}

	function confirmUnpaid(o, next) {
		if (o.payment_status === 'paid') { next(); return; }
		var prompt = 'This order is not marked paid (' + paymentLabel(o.payment_status) + '). Continue only after confirming the approved pay-at-counter process.';
		if (window.confirm(prompt)) { next(); }
	}

	function orderAgeMinutes(o) {
		return minutesSince(o && (o.accepted_at || o.created_at));
	}

	function liveStats(orders) {
		var stats = {
			active: orders.length,
			newCount: 0,
			makeCount: 0,
			passCount: 0,
			lateCount: 0,
			paidCount: 0,
			unpaidCount: 0,
			allergenCount: 0,
			itemCount: 0,
			oldest: 0
		};
		orders.forEach(function (o) {
			var lane = laneOf(o.status);
			var age = orderAgeMinutes(o);
			if (lane === 'new') { stats.newCount++; }
			if (lane === 'prep' || lane === 'bench' || lane === 'oven') { stats.makeCount++; }
			if (lane === 'ready') { stats.passCount++; }
			if (o.timing_status === 'estimate_passed' || (age !== null && age >= 10 && o.status !== 'completed' && o.status !== 'cancelled')) { stats.lateCount++; }
			if (o.payment_status === 'paid') { stats.paidCount++; }
			if (o.payment_status && o.payment_status !== 'paid' && o.payment_status !== 'refunded') { stats.unpaidCount++; }
			if (hasAllergenNote(o)) { stats.allergenCount++; }
			if (age !== null && age > stats.oldest) { stats.oldest = age; }
			(o.items || []).forEach(function (it) {
				stats.itemCount += Math.max(1, parseInt(it.quantity, 10) || 1);
			});
		});
		return stats;
	}

	function metricBar(labelText, value, max, tone) {
		var pct = max ? Math.max(4, Math.min(100, Math.round((value / max) * 100))) : 0;
		return el('div', { class: 'db-live-graph db-live-graph--' + tone }, [
			el('spaã}w¶‰žËkºwµçdì($$%µ•Ñ„¹ÁÕÍ ¡•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…Éµ…‘‘Èœ°Ñ•áÐè€ŸÂ~nÔ€œ€¬¼¹…‘‘É•ÍÌô¤¤ì($%ô($%¥˜€¡¼¹¹½Ñ•Ì¤ì($$%µ•Ñ„¹ÁÕÍ ¡•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…Éµ¹½Ñ•Ìœ€¬€¡¡…Í±±•É•¹9½Ñ”¡¼¤€ü€œ‘ˆµ…Éµ¹½Ñ•Ìµ…±•ÉÐœ€è€œœ¤°Ñ•áÐè€9½Ñ”è€œ€¬¼¹¹½Ñ•Ìô¤¤ì($%ô($%¥˜€¡¼¹•Ñ…}µ¥¹ÕÑ•Ì¤ì($$%Ù…ÈÝ¥¹‘½ÝQ•áÐ€ôÉ•…‘å]¥¹‘½Ü¡¼¤ì($$%µ•Ñ„¹ÁÕÍ ¡•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…Éµ•Ñ„œ°Ñ•áÐèÝ¥¹‘½ÝQ•áÐ€ü€MÑ…™˜•ÍÑ¥µ…Ñ”€œ€¬Ý¥¹‘½ÝQ•áÐ€è€MÑ…™˜•ÍÑ¥µ…Ñ”€œ€¬¼¹•Ñ…}µ¥¹ÕÑ•Ì€¬€œµ¥¸œô¤¤ì($%ô($%¥˜€¡¼¹Ñ¥µ¥¹}ÍÑ…ÑÕÌ€ôôô€•ÍÑ¥µ…Ñ•}Á…ÍÍ•œ¤ì($$%µ•Ñ„¹ÁÕÍ ¡•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…ÉµÑ¥µ¥¹œ‘ˆµ…ÉµÑ¥µ¥¹œµÁ…ÍÍ•œ°Ñ•áÐè¼¹Ñ¥µ¥¹}±…‰•°ñð€ÍÑ¥µ…Ñ”Á…ÍÍ•ƒŠP¡•¬Ñ¡¥Ì½É‘•Èœô¤¤ì($%ô($%µ•Ñ„¹ÁÕÍ ¡•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…ÉµÁ…åµ•¹Ð‘ˆµÁ…åµ•¹Ð´œ€¬€¡¼¹Á…åµ•¹Ñ}ÍÑ…ÑÕÌñð€Õ¹­¹½Ý¸œ¤°Ñ•áÐè€A…åµ•¹Ðè€œ€¬Á…åµ•¹Ñ1…‰•°¡¼¹Á…åµ•¹Ñ}ÍÑ…ÑÕÌ¤ô¤¤ì($%¥˜€¡MI9}5=€ôôô€…±°œ¤ì($$%µ•Ñ„¹ÁÕÍ ¡•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…ÉµÑ½Ñ…°œ°Ñ•áÐè€Q½Ñ…°€œ€¬µ½¹•ä¡¼¹Ñ½Ñ…°¤ô¤¤ì($%ô(($%Ù…È…Ñ¥½¹Ìì($%¥˜€¡¥Í9•Ü¤ì($$%Ù…È•Ñ…I½Ü€ôQ}!=%L¹µ…À¡™Õ¹Ñ¥½¸€¡´¤ì($$$%É•ÑÕÉ¸•° ‰ÕÑÑ½¸œ°ì±…ÍÌè€‰ÕÑÑ½¸‘ˆµ•Ñ„œ°ÑåÁ”è€‰ÕÑÑ½¸œ°€…É¥„µ±…‰•°œè€•ÁÐ½É‘•È€œ€¬¼¹½É‘•É}¹Õµ‰•È€¬€œ°É•…‘ä¥¸€œ€¬´€¬€œµ¥¹ÕÑ•Ìœ°½¹±¥¬è™Õ¹Ñ¥½¸€ ¤ì½¹™¥ÉµU¹Á…¥¡¼°™Õ¹Ñ¥½¸€ ¤ì…•ÁÐ¡¼°´¤ìô¤ìôô°m´€¬€´t¤ì($$%ô¤ì($$%…Ñ¥½¹Ì€ô•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…Éµ…Ñ¥½¹Ìœô°l($$$%•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…•ÁÐµ±…‰•°œ°Ñ•áÐè€•ÁÐƒŠPÉ•…‘ä¥¸èœô¤°($$$%•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ•Ñ„µÉ½Üœô°•Ñ…I½Ü¤°($$$%•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…Éµ…Ñ¥½¹ÌµÉ½Üœô°l($$$$%•° ‰ÕÑÑ½¸œ°ì±…ÍÌè€‰ÕÑÑ½¸‘ˆµÉ•Ù¥•Üµ¡…¹”œ°ÑåÁ”è€‰ÕÑÑ½¸œ°€…É¥„µ±…‰•°œè€I•Ù¥•Ü…¸…‘½ÈÉ•µ½Ù”¥Ñ•´É•ÅÕ•ÍÐ™½È½É‘•È€œ€¬¼¹½É‘•É}¹Õµ‰•È°½¹±¥¬è™Õ¹Ñ¥½¸€ ¤ì½Á•¹µ•¹‘µ•¹ÑI•Ù¥•Ü¡¼¤ìôô°lI•Ù¥•Ü¡…¹”t¤°($$$$%•° ‰ÕÑÑ½¸œ°ì±…ÍÌè€‰ÕÑÑ½¸‰ÕÑÑ½¸µÁÉ¥µ…Éä‘ˆµ…•ÁÐœ°ÑåÁ”è€‰ÕÑÑ½¸œ°€…É¥„µ±…‰•°œè€•ÁÐ½É‘•È€œ€¬¼¹½É‘•É}¹Õµ‰•È€¬€œÝ¥Ñ¡½ÕÐ…¸•ÍÑ¥µ…Ñ”œ°½¹±¥¬è™Õ¹Ñ¥½¸€ ¤ì½¹™¥ÉµU¹Á…¥¡¼°™Õ¹Ñ¥½¸€ ¤ì…•ÁÐ¡¼°€À¤ìô¤ìôô°l•ÁÐt¤($$$%t¤($$%t¤ì($%ô•±Í”ì($$%Ù…È…‘Ù	Ñ¹Ì€ô…‘Ù…¹•Ñ¥½¹Ì¡¼¤¹µ…À¡™Õ¹Ñ¥½¸€¡ÍÐ¤ì($$$%Ù…ÈÁÉ¥µ…Éä€ô€¡ÍÐ€ôôô€É•…‘äœñðÍÐ€ôôô€½µÁ±•Ñ•œ¤ì($$$%É•ÑÕÉ¸•° ‰ÕÑÑ½¸œ°ì($$$$%±…ÍÌè€‰ÕÑÑ½¸€œ€¬€¡ÁÉ¥µ…Éä€ü€‰ÕÑÑ½¸µÁÉ¥µ…Éäœ€è€œœ¤€¬€œ‘ˆµ…‘Ù…¹”œ°($$$$%ÑåÁ”è€‰ÕÑÑ½¸œ°($$$$$…É¥„µ±…‰•°œè…Ñ¥½¹1…‰•°¡¼°ÍÐ¤€¬€œ™½È½É‘•È€œ€¬¼¹½É‘•É}¹Õµ‰•È°($$$$%½¹±¥¬è™Õ¹Ñ¥½¸€ ¤ìÍ•ÑMÑ…ÑÕÌ¡¼°ÍÐ¤ìô($$$%ô°m…Ñ¥½¹1…‰•°¡¼°ÍÐ¥t¤ì($$%ô¤ì($$%Ù…È…Ñ¥½¹I½Ü€ôl($$$%•° ‰ÕÑÑ½¸œ°ì±…ÍÌè€‰ÕÑÑ½¸‘ˆµÉ•Ù¥•Üµ¡…¹”œ°ÑåÁ”è€‰ÕÑÑ½¸œ°€…É¥„µ±…‰•°œè€I•Ù¥•Ü…¸…‘½ÈÉ•µ½Ù”¥Ñ•´É•ÅÕ•ÍÐ™½È½É‘•È€œ€¬¼¹½É‘•É}¹Õµ‰•È°½¹±¥¬è™Õ¹Ñ¥½¸€ ¤ì½Á•¹µ•¹‘µ•¹ÑI•Ù¥•Ü¡¼¤ìôô°lI•Ù¥•Ü¡…¹”t¤($$%t¹½¹…Ð¡…‘Ù	Ñ¹Ì¤ì($$%…Ñ¥½¹Ì€ô•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…Éµ…Ñ¥½¹Ìœô°l($$$$……‘Ù	Ñ¹Ì¹±•¹Ñ €ü•° Àœ°ì±…ÍÌè€‘ˆµ¹¼µ¹•áÐµ…Ñ¥½¸œ°Ñ•áÐè€9¼¹•áÐ­¥Ñ¡•¸ÍÑ•À¥Ì…Ù…¥±…‰±”¸I•™É•Í ½È…Í¬„µ…¹…•È‰•™½É”¡…¹¥¹œÑ¡¥Ì½É‘•È¸œô¤€è¹Õ±°°($$$%•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…Éµ…Ñ¥½¹ÌµÉ½Üœô°…Ñ¥½¹I½Ü¤($$%t¤ì($%ô(($$¼¼M1…¥¹œƒŠP…•ÁÑ•½É‘•ÉÌÍÑ¥±°¥¸…¸…Ñ¥Ù”ÍÑ…Ñ”•Ð…µ‰•È…Ð€Ôµ¥¸($$¼¼…¹É•…Ð€ÄÀµ¥¸Í¥¹”…•ÁÑ…¹”¸($%Ù…È…•±…ÍÌ€ô€œœì($%¥˜€¡¼¹…•ÁÑ•‘}…Ð€˜˜¼¹ÍÑ…ÑÕÌ€„ôô€½µÁ±•Ñ•œ€˜˜¼¹ÍÑ…ÑÕÌ€„ôô€…¹•±±•œ¤ì($$%Ù…È…•5¥¹Ì€ôµ¥¹ÕÑ•ÍM¥¹”¡¼¹…•ÁÑ•‘}…Ð¤ì($$%¥˜€¡…•5¥¹Ì€„ôô¹Õ±°€˜˜…•5¥¹Ì€øô€ÄÀ¤ì…•±…ÍÌ€ô€œ‘ˆµ…”µ±…Ñ”œìô($$%•±Í”¥˜€¡…•5¥¹Ì€„ôô¹Õ±°€˜˜…•5¥¹Ì€øô€Ô¤ì…•±…ÍÌ€ô€œ‘ˆµ…”µÝ…É¸œìô($%ô(($%É•ÑÕÉ¸•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…É‘ˆµ…É´œ€¬¼¹ÍÑ…ÑÕÌ€¬…•±…ÍÌ€¬€¡¥Í9•Ü€˜˜€…¼¹…­¹½Ý±•‘•€˜˜€…±½…±­m¼¹¥‘t€ü€œ‘ˆµ…Éµ™É•Í œ€è€œœ¤°€‘…Ñ„µ½É‘•Èµ¥œèMÑÉ¥¹œ¡¼¹¥¤°€…É¥„µ‰ÕÍäœè¥¹±¥¡ÑmMÑÉ¥¹œ¡¼¹¥¥t€ü€ÑÉÕ”œ€è€™…±Í”œô°l($$%¡•…°($$%•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…ÉµÍÑ…ÑÕÌœ°Ñ•áÐè½É‘•É1…‰•°¡¼°¼¹ÍÑ…ÑÕÌ¤ô¤°($$%½¹Ñ…Ð°($$%¥Ñ•µÌ°($$%•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…Éµµ•Ñ„œô°µ•Ñ„¤°($$%…Ñ¥½¹Ì($%t¤ì(%ô((%™Õ¹Ñ¥½¸É•¹‘•È¡½É‘•ÉÌ¤ì($%±…ÍÑ=É‘•ÉÌ€ô½É‘•ÉÌì($%‰½…É‘°¹Ñ•áÑ½¹Ñ•¹Ð€ô€œœì(($$¼¼A•ÉÍ¥ÍÑ•¹ÐÝ…É¹¥¹œ¥˜Í½Õ¹¥Í¸Ð•¹…‰±•ƒŠP„É•±½…‘•Ñ…‰±•ÐµÕÍÐ($$¼¼¹•Ù•ÈÍ¥ÐÍ¥±•¹ÐÑ¡É½Õ ¹•Ü½É‘•ÉÌ¸($%¥˜€¡MI9}5=€„ôô€Á…ÍÌœ€˜˜€……Õ‘¥¼¹½¸¤ì($$%‰½…É‘°¹…ÁÁ•¹‘¡¥±¡•° ‘¥Øœ°ì±…ÍÌè€‘ˆµÍ½Õ¹µÝ…É¸œô°l($$$$ŸÂ~RM½Õ¹¥Ì=ƒŠPÑ…ÀƒŠq¹…‰±”Í½Õ¹…±•ÉÑÏŠt€¡Ñ½ÀÉ¥¡Ð¤Í¼å½Ô‘½»ŠeÐµ¥ÍÌ¹•Ü½É‘•ÉÌ¸œ($$%t¤¤ì($%ô(($%Ù…ÈÕ¹…­•€ôMI9}5=€ôôô€Á…ÍÌœ€ümt€è½É‘•ÉÌ¹™¥±Ñ•È¡™Õ¹Ñ¥½¸€¡¼¤ì($$%É•ÑÕÉ¸¼¹ÍÑ…ÑÕÌ€ôôô€Á•¹‘¥¹œœ€˜˜€…¼¹…­¹½Ý±•‘•€˜˜€…±½…±­m¼¹¥‘tì($%ô¤ì(($%¥˜€¡Õ¹…­•¹±•¹Ñ ¤ì($$%Ù…È¥‘Ì€ôÕ¹…­•¹µ…À¡™Õ¹Ñ¥½¸€¡¼¤ìÉ•ÑÕÉ¸¼¹¥ìô¤ì($$%‰½…É‘°¹…ÁÁ•¹‘¡¥±¡•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ‰…¹¹•Èœô°l($$$%•° ÍÁ…¸œ°ìÑ•áÐèÕ¹…­•¹±•¹Ñ €¬€œ¹•Ü½É‘•Èœ€¬€¡Õ¹…­•¹±•¹Ñ €ø€Ä€ü€Ìœ€è€œœ¤€¬€œ„œô¤°($$$%•° ‰ÕÑÑ½¸œ°ì±…ÍÌè€‰ÕÑÑ½¸‰ÕÑÑ½¸µÁÉ¥µ…Éäœ°ÑåÁ”è€‰ÕÑÑ½¸œ°½¹±¥¬è™Õ¹Ñ¥½¸€ ¤ì…­¹½Ý±•‘•±°¡¥‘Ì¤ìôô°l­¹½Ý±•‘”t¤($$%t¤¤ì($$%ÍÑ…ÉÑ±•ÉÐ ¤ì($%ô•±Í”ì($$%ÍÑ½Á±•ÉÐ ¤ì($%ô(($$¼¼±°µ‘…äÍÑÉ¥ÀƒŠP…É•…Ñ”¥Ñ•´½Õ¹ÑÌ…É½ÍÌ¥¸µÁÉ½É•ÍÌ½É‘•ÉÌÍ¼Ñ¡”($$¼¼­¥Ñ¡•¸…¸‰…Ñ € ˆÛ\i……Ñ…Èƒ
Ü€Ï\±°5•…ÐƒŠ˜ˆ¤¸!¥‘‘•¸Ý¡•¸•µÁÑä¸($%Ù…ÈMQI%A}MQQUML€ôlÁ•¹‘¥¹œœ°€½¹™¥Éµ•œ°€ÁÉ•Á…É¥¹œœ°€‰…­¥¹œtì($%Ù…È½Õ¹ÑÌ€ôíôì($%¥˜€¡MI9}5=€„ôô€Á…ÍÌœ¤ì½É‘•ÉÌ¹™½É… ¡™Õ¹Ñ¥½¸€¡¼¤ì($$%¥˜€¡MQI%A}MQQUML¹¥¹‘•á=˜¡¼¹ÍÑ…ÑÕÌ¤€ôôô€´Ä¤ìÉ•ÑÕÉ¸ìô($$$¡¼¹¥Ñ•µÌñðmt¤¹™½É… ¡™Õ¹Ñ¥½¸€¡¥Ð¤ì($$$%Ù…È¹…µ”€ôMÑÉ¥¹œ¡¥Ð¹¹…µ”ñð€œœ¤ì($$$%¥˜€ …¹…µ”¤ìÉ•ÑÕÉ¸ìô($$$%½Õ¹ÑÍm¹…µ•t€ô€¡½Õ¹ÑÍm¹…µ•tñð€À¤€¬€¡Á…ÉÍ•%¹Ð¡¥Ð¹ÅÕ…¹Ñ¥Ñä°€ÄÀ¤ñð€Ä¤ì($$%ô¤ì($%ô¤ìô($%Ù…ÈÍÑÉ¥Á¹ÑÉ¥•Ì€ô=‰©•Ð¹­•åÌ¡½Õ¹ÑÌ¤¹µ…À¡™Õ¹Ñ¥½¸€¡¹…µ”¤ì($$%É•ÑÕÉ¸ì¹…µ”è¹…µ”°½Õ¹Ðè½Õ¹ÑÍm¹…µ•tôì($%ô¤¹Í½ÉÐ¡™Õ¹Ñ¥½¸€¡„°ˆ¤ìÉ•ÑÕÉ¸ˆ¹½Õ¹Ð€´„¹½Õ¹Ðìô¤¹Í±¥” À°€ÄÈ¤ì($%¥˜€¡ÍÑÉ¥Á¹ÑÉ¥•Ì¹±•¹Ñ ¤ì($$%‰½…É‘°¹…ÁÁ•¹‘¡¥±¡•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…±±‘…äœô°($$$%m•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµ…±±‘…äµ±…‰•°œ°Ñ•áÐè€±°‘…äèœô¥t¹½¹…Ð ($$$$%ÍÑÉ¥Á¹ÑÉ¥•Ì¹µ…À¡™Õ¹Ñ¥½¸€¡”¤ì($$$$$%É•ÑÕÉ¸•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµ…±±‘…äµ¥Ñ•´œ°Ñ•áÐè”¹½Õ¹Ð€¬€Ÿ\€œ€¬”¹¹…µ”ô¤ì($$$$%ô¤($$$$¤¤¤ì($%ô(($%Ù…ÈÙ¥Í¥‰±•=É‘•ÉÌ€ô½É‘•ÉÌ¹™¥±Ñ•È¡™Õ¹Ñ¥½¸€¡¼¤ìÉ•ÑÕÉ¸€„…±…¹•=˜¡¼¹ÍÑ…ÑÕÌ¤ìô¤ì($%‰½…É‘°¹…ÁÁ•¹‘¡¥±¡É•¹‘•ÉAÕ±Í”¡Ù¥Í¥‰±•=É‘•ÉÌ¤¤ì($%Ù…È±…¹•Í]É…À€ô•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ±…¹•Ìœô°19L¹µ…À¡™Õ¹Ñ¥½¸€¡±…¹”¤ì($$%Ù…È±…¹•=É‘•ÉÌ€ô½É‘•ÉÌ¹™¥±Ñ•È¡™Õ¹Ñ¥½¸€¡¼¤ìÉ•ÑÕÉ¸±…¹•=˜¡¼¹ÍÑ…ÑÕÌ¤€ôôô±…¹”¹­•äìô¤ì($$%Ù…È…É‘Ì€ô±…¹•=É‘•ÉÌ¹±•¹Ñ ($$$$ü±…¹•=É‘•ÉÌ¹µ…À¡…É¤($$$$èm•° Àœ°ì±…ÍÌè€‘ˆµ±…¹”µ•µÁÑäœ°Ñ•áÐè€9½¹”œô¥tì($$%É•ÑÕÉ¸•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ±…¹”‘ˆµ±…¹”´œ€¬±…¹”¹­•äô°l($$$%•°  Èœ°ì±…ÍÌè€‘ˆµ±…¹”µÑ¥Ñ±”œô°m±…¹”¹Ñ¥Ñ±”€¬€œ€œ°•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµ±…¹”µ½Õ¹Ðœ°Ñ•áÐèMÑÉ¥¹œ¡±…¹•=É‘•ÉÌ¹±•¹Ñ ¤ô¥t¤°($$$%•° Àœ°ì±…ÍÌè€‘ˆµ±…¹”µÍÕ‰Ñ¥Ñ±”œ°Ñ•áÐè±…¹•MÕ‰Ñ¥Ñ±”¡±…¹”¹­•ä¤ô¤($$%t¹½¹…Ð¡…É‘Ì¤¤ì($%ô¤¤ì($%‰½…É‘°¹…ÁÁ•¹‘¡¥±¡±…¹•Í]É…À¤ì(($%¥˜€¡ÍÑ…ÑÕÍ°¤ì($$%ÍÑ…ÑÕÍ°¹Ñ•áÑ½¹Ñ•¹Ð€ô½É‘•ÉÌ¹±•¹Ñ €¬€œ…Ñ¥Ù”ƒ
ÜÕÁ‘…Ñ•€œ€¬($$$%¹•Ü…Ñ” ¤¹Ñ½1½…±•Q¥µ•MÑÉ¥¹œ ¤ì($%ô($%…ÁÁ±å½¹¹•Ñ¥½¹MÑ…Ñ” ¤ì(%ô(($¼¨€´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´!•…ÉÑ‰•…Ð€¨¼(($¼¼Mµ…±°½¹¹•Ñ¥½¸‰…‘”¹•áÐÑ¼Ñ¡”‰½…ÉÍÑ…ÑÕÌèÉ••¸€‰1¥Ù”ˆ€¡MM¤°($¼¼…µ‰•È€‰A½±±¥¹œˆ€¡Á½±°=,¤°É•€‰=™™±¥¹”ˆ€¡±…ÍÐ±½…™…¥±•¤¸(%Ù…È½™™±¥¹”€ô™…±Í”ì(%Ù…È¡•…ÉÑ‰•…Ñ°€ô¹Õ±°ì((%™Õ¹Ñ¥½¸Íå¹Q¥µ•1…‰•° ¤ì($%É•ÑÕÉ¸±…ÍÑMÕ•ÍÍ™Õ±Må¹Œ€ü±…ÍÑMÕ•ÍÍ™Õ±Må¹Œ¹Ñ½1½…±•Q¥µ•MÑÉ¥¹œ ¤€è€¹•Ù•Èœì(%ô((%™Õ¹Ñ¥½¸…ÁÁ±å½¹¹•Ñ¥½¹MÑ…Ñ” ¤ì($%¥˜€¡‰½…É‘°¤ì($$%‰½…É‘°¹±…ÍÍ1¥ÍÐ¹Ñ½±” ‘ˆµ‰½…Éµ½™™±¥¹”œ°½™™±¥¹”¤ì($$%Ù…ÈµÕÑ…Ñ¥½¹	ÕÑÑ½¹Ì€ô‰½…É‘°¹ÅÕ•ÉåM•±•Ñ½É±° ‰ÕÑÑ½¸œ¤ì($$%™½È€¡Ù…È¤€ô€Àì¤€ðµÕÑ…Ñ¥½¹	ÕÑÑ½¹Ì¹±•¹Ñ ì¤¬¬¤ì($$$%Ù…È…É€ôµÕÑ…Ñ¥½¹	ÕÑÑ½¹Ím¥t¹±½Í•ÍÐ m‘…Ñ„µ½É‘•Èµ¥‘tœ¤ì($$$%µÕÑ…Ñ¥½¹	ÕÑÑ½¹Ím¥t¹‘¥Í…‰±•€ô½™™±¥¹”ñð€„„¡…É€˜˜¥¹±¥¡Ñm…É¹•ÑÑÑÉ¥‰ÕÑ” ‘…Ñ„µ½É‘•Èµ¥œ¥t¤ì($$%ô($%ô($%¥˜€¡ÁÉ•½É‘•ÉA…¹•°¤ì($$%Ù…ÈÁÉ•½É‘•É	ÕÑÑ½¹Ì€ôÁÉ•½É‘•ÉA…¹•°¹ÅÕ•ÉåM•±•Ñ½É±° ‰ÕÑÑ½¸œ¤ì($$%™½È€¡Ù…È¨€ô€Àì¨€ðÁÉ•½É‘•É	ÕÑÑ½¹Ì¹±•¹Ñ ì¨¬¬¤ì($$$%Ù…ÈÁÉ•½É‘•É…É‘°€ôÁÉ•½É‘•É	ÕÑÑ½¹Ím©t¹±½Í•ÍÐ m‘…Ñ„µÁÉ•½É‘•Èµ¥‘tœ¤ì($$$%Ù…ÈÁÉ•½É‘•É	ÕÍä€ôÁÉ•½É‘•É…É‘°€˜˜¥¹±¥¡ÑmÁÉ•½É‘•É…É‘°¹•ÑÑÑÉ¥‰ÕÑ” ‘…Ñ„µÁÉ•½É‘•Èµ¥œ¥tì($$$%Ù…È½¹Ñ…Ñ¡•¬€ôÁÉ•½É‘•É…É‘°€˜˜ÁÉ•½É‘•É…É‘°¹ÅÕ•ÉåM•±•Ñ½È œ¹‘ˆµÁÉ•½É‘•Èµ½¹Ñ…Ðµ¡•¬¥¹ÁÕÑmÑåÁ”ô‰¡•­‰½à‰tœ¤ì($$$%ÁÉ•½É‘•É	ÕÑÑ½¹Ím©t¹‘¥Í…‰±•€ô½™™±¥¹”ñð€„…ÁÉ•½É‘•É	ÕÍäñð($$$$$¡ÁÉ•½É‘•É	ÕÑÑ½¹Ím©t¹±…ÍÍ1¥ÍÐ¹½¹Ñ…¥¹Ì ‘ˆµÁÉ•½É‘•Èµ…•ÁÐœ¤€˜˜€ …½¹Ñ…Ñ¡•¬ñð€…½¹Ñ…Ñ¡•¬¹¡•­•¤¤ì($$%ô($%ô($%¥˜€¡É•ÑÉå	Ñ¸¤ìÉ•ÑÉå	Ñ¸¹¡¥‘‘•¸€ô€…½™™±¥¹”ìô($%¥˜€¡ÍÑ…ÑÕÍ°¤ì($$%ÍÑ…ÑÕÍ°¹Ñ•áÑ½¹Ñ•¹Ð€ô½™™±¥¹”($$$$ü€=™™±¥¹”€´Í¡½Ý¥¹œ½É‘•ÉÌ±…ÍÐÍå¹•€œ€¬Íå¹Q¥µ•1…‰•° ¤€¬€œ¸¡…¹•Ì…É”±½­•¸œ($$$$è±…ÍÑ=É‘•ÉÌ¹±•¹Ñ €¬€œ…Ñ¥Ù”€´Íå¹•€œ€¬Íå¹Q¥µ•1…‰•° ¤ì($%ô(%ô((%™Õ¹Ñ¥½¸ÕÁ‘…Ñ•!•…ÉÑ‰•…Ð ¤ì($%¥˜€ …¡•…ÉÑ‰•…Ñ°¤ìÉ•ÑÕÉ¸ìô($%Ù…ÈÍÑ…Ñ”€ô½™™±¥¹”€ü€½™™±¥¹”œ€è€¡ÍÍ•!•…±Ñ¡ä€ü€±¥Ù”œ€è€Á½±±¥¹œœ¤ì($%Ù…ÈÝ½É€ô½™™±¥¹”€ü€=™™±¥¹”œ€è€¡ÍÍ•!•…±Ñ¡ä€ü€1¥Ù”œ€è€A½±±¥¹œœ¤ì($%¡•…ÉÑ‰•…Ñ°¹±…ÍÍ9…µ”€ô€‘ˆµ¡•…ÉÑ‰•…Ð‘ˆµ¡•…ÉÑ‰•…Ð´œ€¬ÍÑ…Ñ”ì($%¡•…ÉÑ‰•…Ñ°¹Ñ•áÑ½¹Ñ•¹Ð€ô€œœì($%¡•…ÉÑ‰•…Ñ°¹…ÁÁ•¹‘¡¥±¡•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµ¡•…ÉÑ‰•…Ðµ‘½Ðœ°€…É¥„µ¡¥‘‘•¸œè€ÑÉÕ”œô¤¤ì($%¡•…ÉÑ‰•…Ñ°¹…ÁÁ•¹‘¡¥±¡•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµ¡•…ÉÑ‰•…ÐµÝ½Éœ°Ñ•áÐèÝ½Éô¤¤ì(%ô(($¼¨€´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´å±”€¨¼((%™Õ¹Ñ¥½¸±½… ¤ì($%Ù…ÈÉ•ÅÕ•ÍÑÁ½ €ô€¬­±½…‘Á½ ì($%Ù…ÈÁ…Ñ €ô€œ½…‘µ¥¸½½É‘•ÉÌœ€¬€¡ÕÉÉ•¹Ñ1½…Ñ¥½¸€ü€œý±½…Ñ¥½¹}¥ôœ€¬ÕÉÉ•¹Ñ1½…Ñ¥½¸€è€œœ¤ì($%É•ÑÕÉ¸…Á¤¡Á…Ñ °€Pœ¤¹Ñ¡•¸¡™Õ¹Ñ¥½¸€¡É•Ì¤ì($$%¥˜€ …É•Ìñð€…ÉÉ…ä¹¥ÍÉÉ…ä¡É•Ì¹‘…Ñ„¤¤ìÑ¡É½Ü¹•ÜÉÉ½È Q¡”½É‘•È™••É•ÑÕÉ¹•…¸¥¹Ù…±¥É•ÍÁ½¹Í”¸œ¤ìô($$%¥˜€¡É•ÅÕ•ÍÑÁ½ €„ôô±½…‘Á½ ¤ìÉ•ÑÕÉ¸¹Õ±°ìô($$%½™™±¥¹”€ô™…±Í”ì($$%±…ÍÑMÕ•ÍÍ™Õ±Må¹Œ€ô¹•Ü…Ñ” ¤ì($$%ÕÁ‘…Ñ•!•…ÉÑ‰•…Ð ¤ì($$%É•¹‘•È¡É•Ì¹‘…Ñ„¤ì($$%¥˜€¡MI9}5=€„ôô€Á…ÍÌœ¤ì($$$%¥˜€¡ÁÉ•½É‘•ÉA…¹•°¤ìÁÉ•½É‘•ÉA…¹•°¹¡¥‘‘•¸€ôÑÉÕ”ìô($$$%É•ÑÕÉ¸¹Õ±°ì($$%ô($$%Ù…ÈÁÉ•½É‘•ÉA…Ñ €ô€œ½…‘µ¥¸½ÁÉ•½É‘•ÈµÉ•ÅÕ•ÍÑÌýÁ•É}Á…”ôÄÀÀœ€¬€¡ÕÉÉ•¹Ñ1½…Ñ¥½¸€ü€œ™±½…Ñ¥½¹}¥ôœ€¬ÕÉÉ•¹Ñ1½…Ñ¥½¸€è€œœ¤ì($$%É•ÑÕÉ¸…Á¤¡ÁÉ•½É‘•ÉA…Ñ °€Pœ¤¹Ñ¡•¸¡™Õ¹Ñ¥½¸€¡ÁÉ•½É‘•ÉÌ¤ì($$$%¥˜€ …ÁÉ•½É‘•ÉÌñð€…ÉÉ…ä¹¥ÍÉÉ…ä¡ÁÉ•½É‘•ÉÌ¹‘…Ñ„¤¤ìÑ¡É½Ü¹•ÜÉÉ½È Q¡”ÁÉ”µ½É‘•ÈÉ•Ù¥•Ü™••É•ÑÕÉ¹•…¸¥¹Ù…±¥É•ÍÁ½¹Í”¸œ¤ìô($$$%¥˜€¡É•ÅÕ•ÍÑÁ½ €„ôô±½…‘Á½ ¤ìÉ•ÑÕÉ¸ìô($$$%É•¹‘•ÉAÉ•½É‘•ÉÌ¡ÁÉ•½É‘•ÉÌ¹‘…Ñ„¤ì($$%ô¤¹…Ñ ¡™Õ¹Ñ¥½¸€¡•ÉÉ½È¤ì($$$$¼¼É•Ù¥•Üµ™••™…¥±ÕÉ”µÕÍÐ¹•Ù•È‰±…¹¬½È±½¬Ñ¡”±¥Ù”­¥Ñ¡•¸‰½…É¸($$$%¥˜€¡ÁÉ•½É‘•ÉA…¹•°¤ìÁÉ•½É‘•ÉA…¹•°¹¡¥‘‘•¸€ôÑÉÕ”ìô($$$%¥˜€¡ÍÑ…ÑÕÍ°¤ìÍÑ…ÑÕÍ°¹Ñ•áÑ½¹Ñ•¹Ð€ô€-¥Ñ¡•¸‰½…ÉÍå¹•ìÁÉ”µ½É‘•ÈÉ•Ù¥•ÜÕ¹…Ù…¥±…‰±”è€œ€¬€¡•ÉÉ½È¹µ•ÍÍ…”ñð€É•ÑÉå¥¹œœ¤ìô($$%ô¤ì($%ô¤¹…Ñ ¡™Õ¹Ñ¥½¸€ ¤ì($$%¥˜€¡É•ÅÕ•ÍÑÁ½ €„ôô±½…‘Á½ ¤ìÉ•ÑÕÉ¸ìô($$%½™™±¥¹”€ôÑÉÕ”ì($$%ÕÁ‘…Ñ•!•…ÉÑ‰•…Ð ¤ì($$$¼¼AÉ•Í•ÉÙ”Ñ¡”½¹¹•Ñ¥½¸Ý…É¹¥¹œ‰•±½Ü°Ñ¡•¸É•Á±…”¥ÐÝ¥Ñ Ñ¡”($$$¼¼…Ñ¥½¹…‰±”½™™±¥¹”ÍÑ…Ñ”…™Ñ•ÈÑ¡¥Ì•ÉÉ½È…±±‰…¬½µÁ±•Ñ•Ì¸($$%Í•ÑQ¥µ•½ÕÐ¡…ÁÁ±å½¹¹•Ñ¥½¹MÑ…Ñ”°€À¤ì($$%¥˜€¡ÍÑ…ÑÕÍ°¤ìÍÑ…ÑÕÍ°¹Ñ•áÑ½¹Ñ•¹Ð€ô€½¹¹•Ñ¥½¸ÁÉ½‰±•´ƒŠPÉ•ÑÉå¥¹ŸŠ˜œìô($%ô¤ì(%ô((%™Õ¹Ñ¥½¸±½½À ¤ì($%±½… ¤¹Ñ¡•¸¡™Õ¹Ñ¥½¸€ ¤ì($$%Á½±±Q¥µ•È€ôÍ•ÑQ¥µ•½ÕÐ¡±½½À°ÍÍ•!•…±Ñ¡ä€üA=11}MQd€èA=11}MP¤ì($%ô¤ì(%ô(($¼¨€´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´5•ÉÕÉ”MM€¨¼(($¼¼=Á•¸…¸Ù•¹ÑM½ÕÉ”Ñ¼Ñ¡”5•ÉÕÉ”¡Õˆ¸=¸…¹äµ•ÍÍ…”°É”µÁÕ±°Ñ¡”($¼¼…ÕÑ¡½É¥Ñ…Ñ¥Ù”‰½…É€¡Ñ¡”MMÁ…å±½…¥Ì½¹±ä„€‰É•™É•Í ˆÍ¥¹…°°¹•Ù•È($¼¼ÑÉÕÍÑ•‘…Ñ„¤¸=¸•ÉÉ½È°™…±°‰…¬Ñ¼Ñ¡”¹½Éµ…°Á½±°…‘•¹”¸Q¡”Á½±°($¼¼…±Ý…åÌ­••ÁÌÉÕ¹¹¥¹œ…ÌÑ¡”™…±±‰…¬Á…Ñ ¸(%™Õ¹Ñ¥½¸½¹¹•ÑMÍ” ¤ì($%¥˜€ …µ•ÉÕÉ”ñð€…µ•ÉÕÉ”¹•¹…‰±•ñð€…µ•ÉÕÉ”¹ÕÉ°ñðÑåÁ•½˜Ý¥¹‘½Ü¹Ù•¹ÑM½ÕÉ”€ôôô€Õ¹‘•™¥¹•œ¤ì($$%É•ÑÕÉ¸ì($%ô(($%Ù…ÈÕÉ°€ôµ•ÉÕÉ”¹ÕÉ°€¬€œýÑ½Á¥Œôœ€¬•¹½‘•UI%½µÁ½¹•¹Ð¡µ•ÉÕÉ”¹Ñ½Á¥Œ¤ì($$¼¼Q¡”‰½…ÉÑ½Á¥Œ¥ÌÁÕ‰±¥±äÉ•…‘…‰±”°Í¼¹½Éµ…±±ä¹¼Ñ½­•¸¥Ì¹••‘•½¸($$¼¼ÍÕ‰ÍÉ¥‰”¸Ù•¹ÑM½ÕÉ”…¹¹½ÐÍ•¹ÕÑ¡½É¥é…Ñ¥½¸¡•…‘•ÉÌì½¹±ä™…±°‰…¬($$¼¼Ñ¼Ñ¡”€¡UI0¤…ÕÑ¡½É¥é…Ñ¥½¸Á…É…´Ý¡•¸„ÍÕ‰ÍÉ¥‰”)]P¥Ì…ÑÕ…±±äÍ•Ð¸($%¥˜€¡µ•ÉÕÉ”¹ÍÕ‰ÍÉ¥‰•}©ÝÐ¤ì($$%ÕÉ°€¬ô€œ™…ÕÑ¡½É¥é…Ñ¥½¸ôœ€¬•¹½‘•UI%½µÁ½¹•¹Ð¡µ•ÉÕÉ”¹ÍÕ‰ÍÉ¥‰•}©ÝÐ¤ì($%ô(($%ÑÉäì($$%ÍÍ”€ô¹•ÜÙ•¹ÑM½ÕÉ”¡ÕÉ°¤ì($%ô…Ñ €¡”¤ì($$%É•ÑÕÉ¸ì($%ô(($%ÍÍ”¹½¹½Á•¸€ô™Õ¹Ñ¥½¸€ ¤ì($$%ÍÍ•!•…±Ñ¡ä€ôÑÉÕ”ì($$%ÕÁ‘…Ñ•!•…ÉÑ‰•…Ð ¤ì($%ôì(($%ÍÍ”¹½¹µ•ÍÍ…”€ô™Õ¹Ñ¥½¸€ ¤ì($$$¼¼µ•ÍÍ…”µ•…¹ÌÑ¡”¡…¹¹•°¥Ì…±¥Ù”ƒŠPÉ”µ…™™¥É´¡•…±Ñ Í¼„É•½Ù•Éä($$$¼¼…™Ñ•È„ÑÉ…¹Í¥•¹Ð•ÉÉ½ÈÍ±½ÝÌÑ¡”Á½±°……¥¸•Ù•¸‰•™½É”½¹½Á•¸É•™¥É•Ì¸($$%ÍÍ•!•…±Ñ¡ä€ôÑÉÕ”ì($$%ÕÁ‘…Ñ•!•…ÉÑ‰•…Ð ¤ì($$$¼¼ÕÑ¡½É¥Ñ…Ñ¥Ù”É”µ™•Ñ ƒŠP¹•Ù•ÈÉ•¹‘•È™É½´Ñ¡”MMÁ…å±½…¥ÑÍ•±˜¸($$%±½… ¤ì($%ôì(($%ÍÍ”¹½¹•ÉÉ½È€ô™Õ¹Ñ¥½¸€ ¤ì($$$¼¼É½À‰…¬Ñ¼™…ÍÐÁ½±±¥¹œìÑ¡”‰É½ÝÍ•È…ÕÑ¼µÉ•½¹¹•ÑÌÑ¡”MM°…¹($$$¼¼½¹½Á•¸Ý¥±°Í±½ÜÑ¡”Á½±°……¥¸½¹”¥ÐÉ•½Ù•ÉÌ¸($$%ÍÍ•!•…±Ñ¡ä€ô™…±Í”ì($$%ÕÁ‘…Ñ•!•…ÉÑ‰•…Ð ¤ì($%ôì(%ô((%¥˜€¡Í½Õ¹‘	Ñ¸¤ì($%Í½Õ¹‘	Ñ¸¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È ±¥¬œ°•¹…‰±•M½Õ¹¤ì(%ô(($¼¼%˜Ñ¡”Ñ…‰±•ÐÍ±••ÁÌ½É•™½ÕÍ•Ì°Ñ¡”…Õ‘¥¼½¹Ñ•áÐ…¸ÍÕÍÁ•¹ƒŠPÉ•ÍÕµ”¥Ð($¼¼Í¼Ñ¡”¡¥µ”­••ÁÌÝ½É­¥¹œÝ¥Ñ¡½ÕÐ„™É•Í Ñ…À¸(%‘½Õµ•¹Ð¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È Ù¥Í¥‰¥±¥Ñå¡…¹”œ°™Õ¹Ñ¥½¸€ ¤ì($%¥˜€ …‘½Õµ•¹Ð¹¡¥‘‘•¸€˜˜…Õ‘¥¼¹½¸€˜˜…Õ‘¥¼¹Ñà€˜˜…Õ‘¥¼¹Ñà¹ÍÑ…Ñ”€ôôô€ÍÕÍÁ•¹‘•œ¤ì($$%…Õ‘¥¼¹Ñà¹É•ÍÕµ” ¤ì($%ô(%ô¤ì(%Ý¥¹‘½Ü¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È ½™™±¥¹”œ°™Õ¹Ñ¥½¸€ ¤ì($%½™™±¥¹”€ôÑÉÕ”ì($%ÕÁ‘…Ñ•!•…ÉÑ‰•…Ð ¤ì($%…ÁÁ±å½¹¹•Ñ¥½¹MÑ…Ñ” ¤ì(%ô¤ì(%Ý¥¹‘½Ü¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È ½¹±¥¹”œ°™Õ¹Ñ¥½¸€ ¤ì($%¥˜€¡Á½±±Q¥µ•È¤ì±•…ÉQ¥µ•½ÕÐ¡Á½±±Q¥µ•È¤ìÁ½±±Q¥µ•È€ô¹Õ±°ìô($%±½… ¤ì(%ô¤ì(%‘½Õµ•¹Ð¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È ­•å‘½Ý¸œ°™Õ¹Ñ¥½¸€¡•Ù•¹Ð¤ì($%¥˜€¡•Ù•¹Ð¹­•ä€ôôô€Í…Á”œ€˜˜…µ•¹‘µ•¹ÑA…¹•°€˜˜€……µ•¹‘µ•¹ÑA…¹•°¹¡¥‘‘•¸¤ì($$%±½Í•µ•¹‘µ•¹ÑI•Ù¥•Ü ¤ì($%ô(%ô¤ì(($¼¼M¡½À™¥±Ñ•ÈƒŠP½¹±äÍ¡½Ý¸Ý¡•¸µ½É”Ñ¡…¸½¹”Í¡½À•á¥ÍÑÌ¸(%¥˜€¡…Ñ¥½¹Í°€˜˜1=Q%=9L¹±•¹Ñ €ø€Ä¤ì($%Ù…ÈÍ•°€ô•° Í•±•Ðœ°ì±…ÍÌè€‘ˆµÍ¡½ÀµÍ•±•Ðœ°€…É¥„µ±…‰•°œè€¥±Ñ•È‰äÍ¡½Àœô°mt¤ì($%Í•°¹…ÁÁ•¹‘¡¥±¡•° ½ÁÑ¥½¸œ°ìÙ…±Õ”è€œÀœ°Ñ•áÐè€±°Í¡½ÁÌœô¤¤ì($%1=Q%=9L¹™½É… ¡™Õ¹Ñ¥½¸€¡°¤ì($$%Í•°¹…ÁÁ•¹‘¡¥±¡•° ½ÁÑ¥½¸œ°ìÙ…±Õ”èMÑÉ¥¹œ¡°¹¥¤°Ñ•áÐè°¹¹…µ”ô¤¤ì($%ô¤ì($%Í•°¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È ¡…¹”œ°™Õ¹Ñ¥½¸€ ¤ì($$%ÕÉÉ•¹Ñ1½…Ñ¥½¸€ôÁ…ÉÍ•%¹Ð¡Í•°¹Ù…±Õ”°€ÄÀ¤ñð€Àì($$%±½… ¤ì($%ô¤ì($%…Ñ¥½¹Í°¹¥¹Í•ÉÑ	•™½É”¡Í•°°…Ñ¥½¹Í°¹™¥ÉÍÑ¡¥±¤ì(%ô(($¼¼!•…ÉÑ‰•…Ð‰…‘”ƒŠP¹•áÐÑ¼Ñ¡”•á¥ÍÑ¥¹œ‰½…ÉÍÑ…ÑÕÌÑ•áÐ¸(%¥˜€¡ÍÑ…ÑÕÍ°¤ì($%¡•…ÉÑ‰•…Ñ°€ô•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµ¡•…ÉÑ‰•…Ð‘ˆµ¡•…ÉÑ‰•…ÐµÁ½±±¥¹œœô°mt¤ì($%ÍÑ…ÑÕÍ°¹Á…É•¹Ñ9½‘”¹¥¹Í•ÉÑ	•™½É”¡¡•…ÉÑ‰•…Ñ°°ÍÑ…ÑÕÍ°¹¹•áÑM¥‰±¥¹œ¤ì($%¥˜€¡…Ñ¥½¹Í°¤ì($$%É•ÑÉå	Ñ¸€ô•° ‰ÕÑÑ½¸œ°ì($$$%±…ÍÌè€‰ÕÑÑ½¸‘ˆµ‰½…ÉµÉ•ÑÉäœ°ÑåÁ”è€‰ÕÑÑ½¸œ°¡¥‘‘•¸è€¡¥‘‘•¸œ°($$$$…É¥„µ±…‰•°œè€I•ÑÉä±½…‘¥¹œÑ¡”±¥Ù”½É‘•È‰½…É¹½Üœ°($$$%½¹±¥¬è™Õ¹Ñ¥½¸€ ¤ì±½… ¤ìô($$%ô°lI•ÑÉä¹½Üt¤ì($$%…Ñ¥½¹Í°¹¥¹Í•ÉÑ	•™½É”¡É•ÑÉå	Ñ¸°ÍÑ…ÑÕÍ°¹¹•áÑM¥‰±¥¹œ¤ì($%ô($%ÕÁ‘…Ñ•!•…ÉÑ‰•…Ð ¤ì($%…ÁÁ±å½¹¹•Ñ¥½¹MÑ…Ñ” ¤ì(%ô(($¼¼Q¡”-Lµ…äÉ•Ù¥•ÜÑ¡”ÁÉ•¥Í”ÕÉÉ•¹Ð±¥¹”±¥ÍÐ°‰ÕÐ¥ÐµÕÍÐ¹•Ù•È‰•½µ”($¼¼„‰…¬‘½½È™½ÈÉ•ÁÉ¥¥¹œ½ÈÉ•™Õ¹‘¥¹œ…¸½É‘•È¸Q¡”Á…¹•°¥ÌÉ•…Ñ•½¹”($¼¼½ÕÑÍ¥‘”Ñ¡”É•™É•Í¡•‰½…ÉÍ¼™½ÕÌ…¹Ñ¡”É•Ù¥•ÜÉ•µ…¥¸ÍÑ…‰±”¸(%¥˜€¡‰½…É‘°€˜˜‰½…É‘°¹Á…É•¹Ñ9½‘”¤ì($%…µ•¹‘µ•¹ÑA…¹•°€ô•° Í•Ñ¥½¸œ°ì($$%±…ÍÌè€‘ˆµ…µ•¹‘µ•¹ÐµÁ…¹•°œ°($$%Ñ…‰¥¹‘•àè€œ´Äœ°($$$…É¥„µ±…‰•°œè€=É‘•È¡…¹”É•Ù¥•Üœ($%ô°mt¤ì($%…µ•¹‘µ•¹ÑA…¹•°¹¡¥‘‘•¸€ôÑÉÕ”ì($%‰½…É‘°¹Á…É•¹Ñ9½‘”¹¥¹Í•ÉÑ	•™½É”¡…µ•¹‘µ•¹ÑA…¹•°°‰½…É‘°¤ì(%ô(($¼¼I•™É•Í €‰à…¼ˆ±…‰•±Ì•Ù•¸‰•ÑÝ••¸Á½±±Ì¸(%Í•Ñ%¹Ñ•ÉÙ…°¡™Õ¹Ñ¥½¸€ ¤ì¥˜€¡±…ÍÑ=É‘•ÉÌ¹±•¹Ñ ¤ìÉ•¹‘•È¡±…ÍÑ=É‘•ÉÌ¤ìôô°€ÌÀÀÀÀ¤ì(($¼¼=Á•¸Ñ¡”É•…°µÑ¥µ”MM¡…¹¹•°Ý¡•¸½¹™¥ÕÉ•ìÑ¡”Á½±°‰•±½ÜÍÑ…åÌ…ÌÑ¡”($¼¼…±Ý…åÌµ½¸™…±±‰…¬É•…É‘±•ÍÌ¸(%½¹¹•ÑMÍ” ¤ì((%±½½À ¤ì)ô ¤¤ì(