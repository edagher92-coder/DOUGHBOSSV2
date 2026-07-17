/**
 * DoughBoss — Live Order Board (Kitchen Display).
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
	var LANES = [
		{ key: 'new', title: 'New', statuses: ['pending'] },
		{ key: 'prep', title: 'Preparing', statuses: ['confirmed', 'preparing', 'baking'] },
		{ key: 'ready', title: 'Ready', statuses: ['ready', 'out_for_delivery'] }
	];

	var boardEl = document.getElementById('db-board');
	var statusEl = document.querySelector('.db-board-status');
	var soundBtn = document.querySelector('.db-sound-toggle');
	var actionsEl = document.querySelector('.db-board-actions');

	var LOCATIONS = cfg.locations || [];
	var locationsById = {};
	LOCATIONS.forEach(function (l) { locationsById[l.id] = l.name; });
	var currentLocation = 0; // 0 = all shops

	var localAck = {};      // Optimistically-acknowledged order IDs.
	var audio = { ctx: null, on: false, timer: null };
	var pollTimer = null;

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
			soundBtn.textContent = '🔔 Sound on';
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

	function accept(id, eta) {
		localAck[id] = true;
		api('/admin/order/' + id + '/accept', 'POST', { eta: eta || 0 }).then(load).catch(function (error) {
			delete localAck[id];
			var message = error.message || 'Could not accept the order.';
			return load().then(function () { if (statusEl) { statusEl.textContent = message; } });
		});
	}

	function setStatus(id, status) {
		return api('/admin/order/' + id + '/status', 'POST', { status: status }).then(load).catch(function (error) {
			var message = error.message || 'Could not update the order.';
			return load().then(function () { if (statusEl) { statusEl.textContent = message; } });
		});
	}

	// Status change initiated from a card button: on success, offer a 10s UNDO
	// toast and (for completed/cancelled) remember the order for the Recent
	// recall panel. Server already allows any→any transitions.
	function changeStatus(o, status) {
		var prev = o.status;
		api('/admin/order/' + o.id + '/status', 'POST', { status: status }).then(function () {
			if (status === 'completed' || status === 'cancelled') {
				recallRemember(o, prev);
			}
			showUndoToast(o, status, prev);
			return load();
		}).catch(function (error) {
			var message = error.message || 'Could not update the order.';
			return load().then(function () { if (statusEl) { statusEl.textContent = message; } });
		});
	}

	/* ------------------------------------------------- Undo toast + recall */

	var toast = { el: null, timer: null };
	var recall = []; // { id, order_number, prev, time } — this tablet only.
	var RECALL_MAX = 20;
	var RECALL_TTL = 60 * 60000; // 60 min.
	var recallOpen = false;

	function orderTag(o) {
		var n = String(o.order_number || o.id);
		return n.charAt(0) === '#' ? n : '#' + n;
	}

	function hideToast() {
		if (toast.timer) { clearTimeout(toast.timer); toast.timer = null; }
		if (toast.el && toast.el.parentNode) { toast.el.parentNode.removeChild(toast.el); }
		toast.el = null;
	}

	function showUndoToast(o, status, prev) {
		hideToast(); // One toast at a time — replace the previous one.
		toast.el = el('div', { class: 'db-toast', role: 'status' }, [
			el('span', { class: 'db-toast-text', text: orderTag(o) + ' → ' + label(status) }),
			el('button', {
				class: 'button db-toast-undo', type: 'button',
				onclick: function () {
					hideToast();
					recallForget(o.id);
					setStatus(o.id, prev);
				}
			}, ['UNDO'])
		]);
		document.body.appendChild(toast.el);
		toast.timer = setTimeout(hideToast, 10000);
	}

	function recallPrune() {
		var cutoff = Date.now() - RECALL_TTL;
		recall = recall.filter(function (r) { return r.time >= cutoff; });
	}

	function recallRemember(o, prev) {
		recallForget(o.id);
		recall.unshift({ id: o.id, order_number: o.order_number, prev: prev, time: Date.now() });
		if (recall.length > RECALL_MAX) { recall.length = RECALL_MAX; }
		renderRecall();
	}

	function recallForget(id) {
		recall = recall.filter(function (r) { return r.id !== id; });
		renderRecall();
	}

	var recallBtn = null;
	var recallPanel = null;

	function renderRecall() {
		recallPrune();
		if (recallBtn) {
			recallBtn.textContent = 'Recent (' + recall.length + ')';
			recallBtn.setAttribute('aria-expanded', recallOpen ? 'true' : 'false');
		}
		if (!recallPanel) { return; }
		recallPanel.textContent = '';
		recallPanel.hidden = !recallOpen;
		if (!recallOpen) { return; }
		if (!recall.length) {
			recallPanel.appendChild(el('p', { class: 'db-recall-empty', text: 'No recently completed or cancelled orders from this tablet.' }));
			return;
		}
		recall.forEach(function (r) {
			recallPanel.appendChild(el('div', { class: 'db-recall-row' }, [
				el('span', { class: 'db-recall-info', text: orderTag(r) + ' · ' + Math.max(0, Math.floor((Date.now() - r.time) / 60000)) + 'm ago → back to ' + label(r.prev) }),
				el('button', {
					class: 'button db-recall-restore', type: 'button',
					onclick: function () {
						api('/admin/order/' + r.id + '/status', 'POST', { status: r.prev }).then(function () {
							recallForget(r.id);
							return load();
						}).catch(function (error) {
							if (statusEl) { statusEl.textContent = error.message || 'Could not restore the order.'; }
						});
					}
				}, ['Restore'])
			]));
		});
	}

	function acknowledgeAll(ids) {
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
		return 'prep';
	}

	function advanceActions(o) {
		switch (o.status) {
			case 'confirmed': return ['preparing', 'ready'];
			case 'preparing': return ['baking', 'ready'];
			case 'baking': return ['ready'];
			case 'ready': return o.order_type === 'delivery' ? ['out_for_delivery', 'completed'] : ['completed'];
			case 'out_for_delivery': return ['completed'];
			default: return [];
		}
	}

	function itemLine(it) {
		var bits = [it.quantity + '× ' + it.name];
		if (it.size) { bits.push(it.size); }
		var text = bits.join(' · ');
		var toppings = (it.toppings && it.toppings.length)
			? it.toppings.map(function (t) { return t.label || t.slug || t; }).join(', ')
			: '';
		return el('li', { class: 'db-card-item' }, [
			el('span', { class: 'db-card-item-name', text: text }),
			toppings ? el('span', { class: 'db-card-item-toppings', text: toppings }) : null
		]);
	}

	function card(o) {
		var isNew = o.status === 'pending';
		var showShop = LOCATIONS.length > 1 && !currentLocation && o.location_id && locationsById[o.location_id];
		var head = el('div', { class: 'db-card-head' }, [
			el('span', { class: 'db-card-number', text: o.order_number }),
			el('span', { class: 'db-card-type db-type-' + o.order_type, text: o.order_type === 'delivery' ? 'Delivery' : 'Pickup' }),
			showShop ? el('span', { class: 'db-card-shop', text: locationsById[o.location_id] }) : null,
			el('span', { class: 'db-card-time', text: elapsed(o.created_at) })
		]);

		var contact = el('div', { class: 'db-card-contact' }, [
			el('strong', { text: o.customer_name || '—' }),
			o.customer_phone ? el('span', { text: ' · ' + o.customer_phone }) : null
		]);

		var items = el('ul', { class: 'db-card-items' }, o.items.map(itemLine));

		var meta = [];
		if (o.order_type === 'delivery' && o.address) {
			meta.push(el('div', { class: 'db-card-addr', text: '🛵 ' + o.address }));
		}
		if (o.notes) {
			meta.push(el('div', { class: 'db-card-notes', text: '📝 ' + o.notes }));
		}
		if (o.eta_minutes) {
			meta.push(el('div', { class: 'db-card-eta', text: 'ETA ' + o.eta_minutes + ' min' }));
			// "Due" hint — measured from acceptance when known, else order creation.
			var sinceRef = minutesSince(o.accepted_at || o.created_at);
			if (sinceRef !== null) {
				var remaining = o.eta_minutes - sinceRef;
				meta.push(el('div', {
					class: 'db-card-due' + (remaining < 0 ? ' db-card-due-over' : ''),
					text: remaining >= 0 ? 'Due in ' + remaining + 'm' : 'Overdue ' + (-remaining) + 'm'
				}));
			}
		}
		meta.push(el('div', { class: 'db-card-total', text: 'Total ' + money(o.total) }));

		var actions;
		if (isNew) {
			var etaRow = ETA_CHOICES.map(function (m) {
				return el('button', { class: 'button db-eta', type: 'button', onclick: function () { accept(o.id, m); } }, [m + 'm']);
			});
			actions = el('div', { class: 'db-card-actions' }, [
				el('div', { class: 'db-accept-label', text: 'Accept — ready in:' }),
				el('div', { class: 'db-eta-row' }, etaRow),
				el('div', { class: 'db-card-actions-row' }, [
					el('button', { class: 'button button-primary db-accept', type: 'button', onclick: function () { accept(o.id, 0); } }, ['Accept']),
					el('button', { class: 'button db-cancel', type: 'button', onclick: function () { if (window.confirm('Cancel order ' + o.order_number + '?')) { changeStatus(o, 'cancelled'); } } }, ['Cancel'])
				])
			]);
		} else {
			var advBtns = advanceActions(o).map(function (st) {
				var primary = (st === 'ready' || st === 'completed');
				return el('button', {
					class: 'button ' + (primary ? 'button-primary' : '') + ' db-advance',
					type: 'button',
					onclick: function () { changeStatus(o, st); }
				}, [label(st)]);
			});
			advBtns.push(el('button', {
				class: 'button db-cancel', type: 'button',
				onclick: function () { if (window.confirm('Cancel order ' + o.order_number + '?')) { changeStatus(o, 'cancelled'); } }
			}, ['Cancel']));
			actions = el('div', { class: 'db-card-actions' }, [el('div', { class: 'db-card-actions-row' }, advBtns)]);
		}

		// SLA aging — accepted orders still in an active state get amber at 5 min
		// and red at 10 min since acceptance.
		var ageClass = '';
		if (o.accepted_at && o.status !== 'completed' && o.status !== 'cancelled') {
			var ageMins = minutesSince(o.accepted_at);
			if (ageMins !== null && ageMins >= 10) { ageClass = ' db-age-late'; }
			else if (ageMins !== null && ageMins >= 5) { ageClass = ' db-age-warn'; }
		}

		return el('div', { class: 'db-card db-card-' + o.status + ageClass + (isNew && !o.acknowledged && !localAck[o.id] ? ' db-card-fresh' : '') }, [
			head,
			el('div', { class: 'db-card-status', text: label(o.status) }),
			contact,
			items,
			el('div', { class: 'db-card-meta' }, meta),
			actions
		]);
	}

	function render(orders) {
		lastOrders = orders;
		boardEl.textContent = '';

		// Persistent warning if sound isn't enabled — a reloaded tablet must
		// never sit silent through new orders.
		if (!audio.on) {
			boardEl.appendChild(el('div', { class: 'db-sound-warn' }, [
				'🔇 Sound is OFF — tap “Enable sound alerts” (top right) so you don’t miss new orders.'
			]));
		}

		var unacked = orders.filter(function (o) {
			return o.status === 'pending' && !o.acknowledged && !localAck[o.id];
		});

		if (unacked.length) {
			var ids = unacked.map(function (o) { return o.id; });
			boardEl.appendChild(el('div', { class: 'db-banner' }, [
				el('span', { text: unacked.length + ' new order' + (unacked.length > 1 ? 's' : '') + '!' }),
				el('button', { class: 'button button-primary', type: 'button', onclick: function () { acknowledgeAll(ids); } }, ['Acknowledge'])
			]));
			startAlert();
		} else {
			stopAlert();
		}

		// All-day strip — aggregate item counts across in-progress orders so the
		// kitchen can batch ("6× Zaatar · 3× All Meat …"). Hidden when empty.
		var STRIP_STATUSES = ['pending', 'confirmed', 'preparing', 'baking'];
		var counts = {};
		orders.forEach(function (o) {
			if (STRIP_STATUSES.indexOf(o.status) === -1) { return; }
			(o.items || []).forEach(function (it) {
				var name = String(it.name || '');
				if (!name) { return; }
				counts[name] = (counts[name] || 0) + (parseInt(it.quantity, 10) || 1);
			});
		});
		var stripEntries = Object.keys(counts).map(function (name) {
			return { name: name, count: counts[name] };
		}).sort(function (a, b) { return b.count - a.count; }).slice(0, 12);
		if (stripEntries.length) {
			boardEl.appendChild(el('div', { class: 'db-allday' },
				[el('span', { class: 'db-allday-label', text: 'All day:' })].concat(
					stripEntries.map(function (e) {
						return el('span', { class: 'db-allday-item', text: e.count + '× ' + e.name });
					})
				)));
		}

		var lanesWrap = el('div', { class: 'db-lanes' }, LANES.map(function (lane) {
			var laneOrders = orders.filter(function (o) { return laneOf(o.status) === lane.key; });
			var cards = laneOrders.length
				? laneOrders.map(card)
				: [el('p', { class: 'db-lane-empty', text: 'None' })];
			return el('div', { class: 'db-lane db-lane-' + lane.key }, [
				el('h2', { class: 'db-lane-title' }, [lane.title + ' ', el('span', { class: 'db-lane-count', text: String(laneOrders.length) })])
			].concat(cards));
		}));
		boardEl.appendChild(lanesWrap);

		if (statusEl) {
			statusEl.textContent = orders.length + ' active · updated ' +
				new Date().toLocaleTimeString();
		}
	}

	/* ----------------------------------------------------------- Heartbeat */

	// Small connection badge next to the board status: green "Live" (SSE),
	// amber "Polling" (poll OK), red "Offline" (last load failed).
	var offline = false;
	var heartbeatEl = null;

	function updateHeartbeat() {
		if (!heartbeatEl) { return; }
		var state = offline ? 'offline' : (sseHealthy ? 'live' : 'polling');
		var word = offline ? 'Offline' : (sseHealthy ? 'Live' : 'Polling');
		heartbeatEl.className = 'db-heartbeat db-heartbeat-' + state;
		heartbeatEl.textContent = '';
		heartbeatEl.appendChild(el('span', { class: 'db-heartbeat-dot', 'aria-hidden': 'true' }));
		heartbeatEl.appendChild(el('span', { class: 'db-heartbeat-word', text: word }));
	}

	/* --------------------------------------------------------------- Cycle */

	function load() {
		var path = '/admin/orders' + (currentLocation ? '?location_id=' + currentLocation : '');
		return api(path, 'GET').then(function (res) {
			offline = false;
			updateHeartbeat();
			if (res && res.data) { render(res.data); }
		}).catch(function () {
			offline = true;
			updateHeartbeat();
			if (statusEl) { statusEl.textContent = 'Connection problem — retrying…'; }
		});
	}

	function loop() {
		load().then(function () {
			pollTimer = setTimeout(loop, sseHealthy ? POLL_SAFETY : POLL_FAST);
		});
	}

	/* ----------------------------------------------------- Mercure SSE */

	// Open an EventSource to the Mercure hub. On any message, re-pull the
	// authoritative board (the SSE payload is only a "refresh" signal, never
	// trusted data). On error, fall back to the normal poll cadence. The poll
	// always keeps running as the fallback path.
	function connectSse() {
		if (!mercure || !mercure.enabled || !mercure.url || typeof window.EventSource === 'undefined') {
			return;
		}

		var url = mercure.url + '?topic=' + encodeURIComponent(mercure.topic);
		// The board topic is publicly readable, so normally no token is needed on
		// subscribe. EventSource cannot send Authorization headers; only fall back
		// to the (URL) authorization param when a subscribe JWT is actually set.
		if (mercure.subscribe_jwt) {
			url += '&authorization=' + encodeURIComponent(mercure.subscribe_jwt);
		}

		try {
			sse = new EventSource(url);
		} catch (e) {
			return;
		}

		sse.onopen = function () {
			sseHealthy = true;
			updateHeartbeat();
		};

		sse.onmessage = function () {
			// A message means the channel is alive — re-affirm health so a recovery
			// after a transient error slows the poll again even before onopen refires.
			sseHealthy = true;
			updateHeartbeat();
			// Authoritative re-fetch — never render from the SSE payload itself.
			load();
		};

		sse.onerror = function () {
			// Drop back to fast polling; the browser auto-reconnects the SSE, and
			// onopen will slow the poll again once it recovers.
			sseHealthy = false;
			updateHeartbeat();
		};
	}

	if (soundBtn) {
		soundBtn.addEventListener('click', enableSound);
	}

	// If the tablet sleeps/refocuses, the audio context can suspend — resume it
	// so the chime keeps working without a fresh tap.
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden && audio.on && audio.ctx && audio.ctx.state === 'suspended') {
			audio.ctx.resume();
		}
	});

	// Shop filter — only shown when more than one shop exists.
	if (actionsEl && LOCATIONS.length > 1) {
		var sel = el('select', { class: 'db-shop-select', 'aria-label': 'Filter by shop' }, []);
		sel.appendChild(el('option', { value: '0', text: 'All shops' }));
		LOCATIONS.forEach(function (l) {
			sel.appendChild(el('option', { value: String(l.id), text: l.name }));
		});
		sel.addEventListener('change', function () {
			currentLocation = parseInt(sel.value, 10) || 0;
			load();
		});
		actionsEl.insertBefore(sel, actionsEl.firstChild);
	}

	// Heartbeat badge — next to the existing board status text.
	if (statusEl) {
		heartbeatEl = el('span', { class: 'db-heartbeat db-heartbeat-polling' }, []);
		statusEl.parentNode.insertBefore(heartbeatEl, statusEl.nextSibling);
		updateHeartbeat();
	}

	// Recall ("Recent") panel — restore orders this tablet completed/cancelled
	// in the last 60 minutes.
	if (actionsEl) {
		recallBtn = el('button', {
			class: 'button db-recall-toggle', type: 'button', 'aria-expanded': 'false',
			onclick: function () { recallOpen = !recallOpen; renderRecall(); }
		}, ['Recent (0)']);
		actionsEl.appendChild(recallBtn);
		recallPanel = el('div', { class: 'db-recall-panel' }, []);
		recallPanel.hidden = true;
		if (boardEl && boardEl.parentNode) {
			boardEl.parentNode.insertBefore(recallPanel, boardEl);
		}
		renderRecall();
	}

	// Refresh "x ago" labels even between polls.
	setInterval(function () { if (lastOrders.length) { render(lastOrders); } }, 30000);

	// Open the real-time SSE channel when configured; the poll below stays as the
	// always-on fallback regardless.
	connectSse();

	loop();
}());
