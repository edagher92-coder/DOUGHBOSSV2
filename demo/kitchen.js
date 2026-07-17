/**
 * Dough Boss — SIMULATED Live Order Board preview (demo only).
 *
 * Flagship in-browser simulation of the real kitchen board
 * (public/js/doughboss-orderboard.js): same lanes, card structure, lane
 * advance graph, SLA aging, accept-with-ETA, 10s undo toast, recall panel,
 * all-day strip, heartbeat badge and new-order alert — plus a Today results
 * strip and a companion "customer's phone" panel showing the staff-action →
 * customer-notification loop (stage emails + live order tracker).
 *
 * Everything lives in an in-memory array; time is accelerated
 * (1 real second = 30 simulated seconds) so SLA colours visibly ramp.
 * No network, no storage. Vanilla JS so the interaction patterns port
 * cleanly back into the real wp-admin board.
 */
(function () {
	'use strict';

	/* ------------------------------------------------------- Simulated time */

	var SIM_RATE = 30; // 1 real second = 30 simulated seconds.
	var bootReal = Date.now();

	function simNow() {
		return bootReal + (Date.now() - bootReal) * SIM_RATE;
	}

	function simMinutesAgo(mins) {
		return simNow() - mins * 60000;
	}

	// Whole simulated minutes since a sim-epoch timestamp (ms), or null.
	function minutesSince(t) {
		if (!t) { return null; }
		return Math.max(0, Math.floor((simNow() - t) / 60000));
	}

	function elapsed(t) {
		var mins = minutesSince(t);
		return mins === null ? '' : mins + 'm ago';
	}

	var reduceMotion = window.matchMedia &&
		window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* -------------------------------------------------------------- Config */

	var STATUSES = {
		pending: 'New',
		confirmed: 'Confirmed',
		preparing: 'Preparing',
		baking: 'Baking',
		ready: 'Ready',
		out_for_delivery: 'Out for delivery',
		completed: 'Completed',
		cancelled: 'Cancelled'
	};
	var ETA_CHOICES = [10, 15, 20, 30];
	var LANES = [
		{ key: 'new', title: 'New', statuses: ['pending'] },
		{ key: 'prep', title: 'Preparing', statuses: ['confirmed', 'preparing', 'baking'] },
		{ key: 'ready', title: 'Ready', statuses: ['ready', 'out_for_delivery'] }
	];
	var ACTIVE = ['pending', 'confirmed', 'preparing', 'baking', 'ready', 'out_for_delivery'];

	var LOCATIONS = [
		{ id: 1, name: 'Revesby' },
		{ id: 2, name: 'Bankstown' },
		{ id: 3, name: 'Roselands' }
	];
	var locationsById = {};
	LOCATIONS.forEach(function (l) { locationsById[l.id] = l.name; });
	var currentLocation = 0; // 0 = all shops.

	var boardEl = document.getElementById('db-board');
	var statusEl = document.querySelector('.db-board-status');
	var soundBtn = document.querySelector('.db-sound-toggle');
	var offlineBtn = document.querySelector('.db-offline-toggle');
	var shopSel = document.getElementById('db-shop');
	var heartbeatEl = document.getElementById('db-heartbeat');
	var recallBtn = document.querySelector('.db-recall-toggle');
	var recallPanel = document.getElementById('db-recall');
	var todayEl = document.getElementById('db-today');
	var phoneNotifsEl = document.getElementById('db-phone-notifs');
	var phoneTrackerEl = document.getElementById('db-phone-tracker');
	var phoneClockEl = document.getElementById('db-phone-clock');
	var introEl = document.getElementById('db-intro');
	var introClose = document.getElementById('db-intro-close');

	if (!boardEl) { return; }

	var localAck = {};
	var audio = { ctx: null, on: false, timer: null };

	/* ------------------------------------------------------------ Fake data */

	function orderNumber() {
		return 'DB-260717-' + String(Math.floor(100000 + Math.random() * 900000));
	}

	var nextId = 1;

	function makeOrder(o) {
		o.id = nextId++;
		o.order_number = o.order_number || orderNumber();
		return o;
	}

	// Seeded board: varied ages so SLA amber/red states are visible at load,
	// plus a few already-completed orders so the Today tiles look like a real
	// service (completed orders never render in the lanes).
	var orders = [
		makeOrder({
			status: 'pending', order_type: 'pickup', location_id: 1,
			customer_name: 'Sarah Nguyen', customer_phone: '0412 555 210',
			items: [
				{ name: 'Zaatar', quantity: 2 },
				{ name: 'Zaatar & Cheese', quantity: 1, toppings: [{ label: 'Folded' }] },
				{ name: 'Soft Drink 600ml', quantity: 1, toppings: [{ label: 'Solo' }] }
			],
			notes: 'Extra crispy please', total: 22.50, payment: 'collect',
			created_at: simMinutesAgo(1), acknowledged: false
		}),
		makeOrder({
			status: 'pending', order_type: 'pickup', location_id: 2,
			customer_name: 'Omar Haddad', customer_phone: '0433 555 981',
			items: [
				{ name: 'All Meat', quantity: 1, size: 'Large', toppings: [{ label: 'Wholemeal base' }, { label: 'Lemon & chilli' }] },
				{ name: 'Chicken Delight Wrap', quantity: 1, toppings: [{ label: 'No pickles' }] }
			],
			pickup_window: '6:15–6:30 pm', total: 29.00, payment: 'paid',
			created_at: simMinutesAgo(3), acknowledged: true
		}),
		makeOrder({
			status: 'confirmed', order_type: 'pickup', location_id: 1,
			customer_name: 'Jess Taylor', customer_phone: '0401 555 664',
			items: [
				{ name: 'Meat & Cheese', quantity: 2 },
				{ name: 'Spinach & Cheese Pie', quantity: 1 }
			],
			eta_minutes: 15, total: 32.00, payment: 'paid',
			created_at: simMinutesAgo(4), accepted_at: simMinutesAgo(3), acknowledged: true
		}),
		makeOrder({
			status: 'preparing', order_type: 'pickup', location_id: 3,
			customer_name: 'Michael Chen', customer_phone: '0455 555 302',
			items: [
				{ name: 'Dough Boss Special', quantity: 1, size: 'Large' },
				{ name: 'Zaatar', quantity: 3, toppings: [{ label: '1× gluten-free base' }] },
				{ name: 'Juice', quantity: 2 }
			],
			notes: 'Birthday pickup — box separately', eta_minutes: 15, total: 46.50, payment: 'collect',
			created_at: simMinutesAgo(8), accepted_at: simMinutesAgo(7), acknowledged: true
		}),
		makeOrder({
			status: 'baking', order_type: 'delivery', location_id: 1,
			customer_name: 'Layla Karam', customer_phone: '0422 555 118',
			address: '14 Marco Ave, Revesby NSW 2212',
			items: [
				{ name: 'Sujuk Deluxe', quantity: 1 },
				{ name: 'Dough Boss Wrap', quantity: 2 },
				{ name: 'Choco Banana', quantity: 1 }
			],
			eta_minutes: 20, total: 54.00, payment: 'paid',
			created_at: simMinutesAgo(13), accepted_at: simMinutesAgo(12), acknowledged: true
		}),
		makeOrder({
			status: 'ready', order_type: 'pickup', location_id: 2,
			customer_name: 'Daniel Rossi', customer_phone: '0466 555 447',
			items: [
				{ name: 'Zaatar & Cheese', quantity: 2 },
				{ name: 'Haloumi Pie', quantity: 1 }
			],
			eta_minutes: 10, total: 28.00, payment: 'collect',
			created_at: simMinutesAgo(7), accepted_at: simMinutesAgo(6), acknowledged: true
		}),
		makeOrder({
			status: 'ready', order_type: 'pickup', location_id: 1,
			customer_name: 'Priya Sharma', customer_phone: '0477 555 890',
			items: [
				{ name: 'Ultimate Chicken Wrap', quantity: 1 },
				{ name: 'Spring Water', quantity: 1 }
			],
			eta_minutes: 10, total: 17.50, payment: 'paid',
			created_at: simMinutesAgo(4), accepted_at: simMinutesAgo(3), acknowledged: true
		}),
		// Scheduled-for-later order: sits calm/greyed with a LATER chip and a
		// future pickup window; no SLA pressure and no batching until started.
		makeOrder({
			status: 'pending', order_type: 'pickup', location_id: 1,
			customer_name: 'Nadia Saleh', customer_phone: '0491 555 072',
			items: [
				{ name: 'Zaatar', quantity: 6 },
				{ name: 'Cheese', quantity: 2 }
			],
			pickup_window: '12:00–12:30 pm', later: true,
			notes: 'Office order — scheduled for lunch',
			total: 46.00, payment: 'paid',
			created_at: simMinutesAgo(2), acknowledged: true
		}),
		// Completed earlier today — feed the Today tiles only.
		makeOrder({
			status: 'completed', order_type: 'pickup', location_id: 1,
			customer_name: 'Georgia Kelly', items: [{ name: 'Zaatar', quantity: 4 }],
			total: 18.00, payment: 'paid', created_at: simMinutesAgo(70), acknowledged: true
		}),
		makeOrder({
			status: 'completed', order_type: 'pickup', location_id: 2,
			customer_name: 'Sam Aoun', items: [{ name: 'Pepperoni & Cheese', quantity: 2 }],
			total: 26.00, payment: 'collect', collected: true, created_at: simMinutesAgo(55), acknowledged: true
		}),
		makeOrder({
			status: 'completed', order_type: 'pickup', location_id: 3,
			customer_name: 'Ava Morris', items: [{ name: 'BBQ Chicken', quantity: 1 }, { name: 'Juice', quantity: 2 }],
			total: 23.00, payment: 'paid', created_at: simMinutesAgo(40), acknowledged: true
		})
	];

	// Pool for the "new order pops in" simulation.
	var INCOMING = [
		{
			customer_name: 'Tony Abdallah', customer_phone: '0410 555 733', order_type: 'pickup',
			items: [{ name: 'Meat', quantity: 2, toppings: [{ label: 'Folded' }] }, { name: 'Zaatar', quantity: 1 }],
			total: 22.50, payment: 'collect'
		},
		{
			customer_name: 'Emily Watson', customer_phone: '0490 555 205', order_type: 'pickup',
			items: [{ name: 'BBQ Chicken', quantity: 1, size: 'Large' }, { name: 'Soft Drink 600ml', quantity: 2, toppings: [{ label: 'Coke' }] }],
			total: 24.00, payment: 'paid'
		},
		{
			customer_name: 'Hassan Merhi', customer_phone: '0403 555 612', order_type: 'delivery',
			address: '3/22 The River Rd, Revesby NSW 2212',
			items: [{ name: 'All Meat', quantity: 1, toppings: [{ label: 'Lemon & chilli' }] }, { name: 'Garlic Prawns', quantity: 1 }, { name: 'Aged Cheese Pie', quantity: 1 }],
			total: 48.00, payment: 'paid'
		},
		{
			customer_name: 'Grace Papadopoulos', customer_phone: '0428 555 356', order_type: 'pickup',
			items: [{ name: 'Zaatar & Veggie Wrap', quantity: 2, toppings: [{ label: 'Add labneh' }] }, { name: 'Peri Peri Chicken', quantity: 1 }],
			total: 33.50, payment: 'collect'
		}
	];
	var incomingIdx = 0;

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
		return '$' + (Math.round(amount * 100) / 100).toFixed(2);
	}

	function label(status) {
		return STATUSES[status] || status;
	}

	/* --------------------------------------------------------------- Sound */

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

	function setSound(on) {
		if (on && !audio.ctx) {
			try { audio.ctx = new (window.AudioContext || window.webkitAudioContext)(); }
			catch (e) { return; }
		}
		if (on && audio.ctx.state === 'suspended') { audio.ctx.resume(); }
		audio.on = on;
		if (soundBtn) {
			soundBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
			soundBtn.textContent = on ? '🔔 Sound on' : '🔕 Sound off';
			soundBtn.classList.toggle('is-on', on);
		}
		if (on) { beep(); }
		render();
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

	/* ------------------------------------------------ Simulated "API" ops */

	function findOrder(id) {
		for (var i = 0; i < orders.length; i++) {
			if (orders[i].id === id) { return orders[i]; }
		}
		return null;
	}

	function accept(id, eta) {
		var o = findOrder(id);
		if (!o) { return; }
		localAck[id] = true;
		o.acknowledged = true;
		o.status = 'confirmed';
		o.accepted_at = simNow();
		o.later = false; // Starting a scheduled order brings it into the flow.
		if (eta) { o.eta_minutes = eta; }
		custNotify(o, 'accepted');
		render();
	}

	// Direct status set (used by UNDO / recall restore) — no toast.
	function setStatus(id, status) {
		var o = findOrder(id);
		if (!o) { return; }
		o.status = status;
		o.acknowledged = true; // A restored order must not re-fire the alert.
		localAck[id] = true;
		render();
	}

	// Status change from a card button: 10s UNDO toast, and completed/
	// cancelled orders are remembered for the Recent recall panel.
	function changeStatus(o, status) {
		var prev = o.status;
		o.status = status;
		if (status === 'completed' || status === 'cancelled') {
			recallRemember(o, prev);
		}
		showUndoToast(o, status, prev);
		custNotify(o, status);
		render();
	}

	function acknowledgeAll(ids) {
		ids.forEach(function (id) {
			localAck[id] = true;
			var o = findOrder(id);
			if (o) { o.acknowledged = true; }
		});
		stopAlert();
		render();
	}

	/* ---------------------------- Customer phone (illustrative loop) */

	// The staff action → customer notification loop (stage emails + live
	// order tracker) rendered on a companion phone mockup so the owner sees
	// exactly what the customer receives. Purely illustrative.
	var phoneNotifs = [];   // { title, text, at }
	var phoneOrderId = null;

	function custNotify(o, kind) {
		var n = null;
		if (kind === 'accepted') {
			n = {
				title: 'Order confirmed — in the oven 🔥',
				text: (o.eta_minutes ? 'Ready in about ' + o.eta_minutes + ' minutes. ' : 'We’re onto it. ') + 'Track it live: ' + o.order_number
			};
		} else if (kind === 'ready') {
			n = o.order_type === 'delivery'
				? { title: 'Your order is ready 🎉', text: 'Fresh out of the oven — heading out shortly.' }
				: { title: 'Ready for pickup! 🎉', text: 'Fresh out of the oven — see you soon at ' + (locationsById[o.location_id] || 'the shop') + '.' };
		} else if (kind === 'out_for_delivery') {
			n = { title: 'On its way 🛵', text: 'Your order just left the shop.' };
		} else if (kind === 'completed') {
			n = { title: 'Enjoy! 😋', text: 'Thanks for ordering with Dough Boss.' };
		} else if (kind === 'cancelled') {
			n = { title: 'Order cancelled', text: 'Sorry — the shop cancelled ' + o.order_number + '. Any card payment is refunded.' };
		} else {
			return; // Intermediate kitchen steps don't notify the customer.
		}
		n.at = new Date();
		phoneNotifs.unshift(n);
		if (phoneNotifs.length > 2) { phoneNotifs.length = 2; }
		phoneOrderId = o.id;
		renderPhone();
	}

	var TRACK_STEPS = [
		{ key: 'received', label: 'Order received' },
		{ key: 'oven', label: 'In the oven' },
		{ key: 'ready', label: 'Ready' },
		{ key: 'done', label: 'Picked up' }
	];

	function trackerStage(o) {
		if (o.status === 'pending') { return 0; }
		if (o.status === 'confirmed' || o.status === 'preparing' || o.status === 'baking') { return 1; }
		if (o.status === 'ready' || o.status === 'out_for_delivery') { return 2; }
		return 3;
	}

	function renderPhone() {
		if (!phoneNotifsEl || !phoneTrackerEl) { return; }
		if (phoneClockEl) {
			phoneClockEl.textContent = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
		}

		phoneNotifsEl.textContent = '';
		phoneNotifs.forEach(function (n) {
			phoneNotifsEl.appendChild(el('div', { class: 'ph-notif' }, [
				el('span', { class: 'ph-notif-icon', 'aria-hidden': 'true', text: 'DB' }),
				el('div', { class: 'ph-notif-body' }, [
					el('div', { class: 'ph-notif-top' }, [
						el('span', { text: 'Email · Dough Boss' }),
						el('span', { text: n.at.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) })
					]),
					el('div', { class: 'ph-notif-title', text: n.title }),
					el('div', { class: 'ph-notif-text', text: n.text })
				])
			]));
		});

		phoneTrackerEl.textContent = '';
		var o = phoneOrderId ? findOrder(phoneOrderId) : null;
		if (!o) {
			phoneTrackerEl.appendChild(el('p', { class: 'phone-empty', text: 'Waiting for your order… (accept an order on the board to see the customer side react)' }));
			return;
		}

		var stage = trackerStage(o);
		var cancelled = o.status === 'cancelled';
		var steps = TRACK_STEPS.map(function (s, i) {
			if (s.key === 'done' && o.order_type === 'delivery') { s = { key: 'done', label: 'Delivered' }; }
			var cls = 'ph-step' + (i < stage ? ' is-done' : '') + (i === stage && !cancelled ? ' is-active' : '');
			return el('li', { class: cls }, [
				el('span', { class: 'ph-step-dot', 'aria-hidden': 'true' }),
				el('span', { text: s.label })
			]);
		});

		var countdown = null;
		if (!cancelled && stage === 1 && o.eta_minutes) {
			var left = o.eta_minutes - (minutesSince(o.accepted_at || o.created_at) || 0);
			countdown = el('div', { class: 'ph-count' }, [
				left > 0 ? '~' + left + ' min' : 'Any minute now',
				el('small', { text: left > 0 ? '  until it’s ready' : '' })
			]);
		} else if (!cancelled && stage === 2) {
			countdown = el('div', { class: 'ph-count' }, [
				o.order_type === 'delivery' ? 'Ready 🎉' : 'Ready for pickup 🎉'
			]);
		}

		phoneTrackerEl.appendChild(el('div', { class: 'ph-tracker' + (cancelled ? ' is-cancelled' : '') }, [
			el('div', { class: 'ph-tracker-head' }, [
				el('span', { class: 'ph-tracker-num', text: o.order_number }),
				el('span', { class: 'ph-tracker-label', text: 'Live order tracker' })
			]),
			countdown,
			el('ul', { class: 'ph-steps' }, steps),
			cancelled ? el('p', { class: 'ph-cancelled', text: 'This order was cancelled.' }) : null
		]));
	}

	/* ------------------------------------------------- Undo toast + recall */

	var toast = { el: null, timer: null };
	var recall = []; // { id, order_number, prev, time } — sim-epoch ms.
	var RECALL_MAX = 20;
	var RECALL_TTL = 60 * 60000; // 60 sim-minutes.
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
		hideToast(); // One toast at a time.
		toast.el = el('div', { class: 'db-toast', role: 'status' }, [
			el('span', { class: 'db-toast-text', text: orderTag(o) + ' → ' + label(status) }),
			el('button', {
				class: 'button db-toast-undo', type: 'button',
				onclick: function () {
					hideToast();
					recallForget(o.id);
					setStatus(o.id, prev);
				}
			}, ['UNDO']),
			el('span', { class: 'db-toast-bar', 'aria-hidden': 'true' })
		]);
		document.body.appendChild(toast.el);
		toast.timer = setTimeout(hideToast, 10000); // 10 real seconds, like the real board.
	}

	function recallPrune() {
		var cutoff = simNow() - RECALL_TTL;
		recall = recall.filter(function (r) { return r.time >= cutoff; });
	}

	function recallRemember(o, prev) {
		recallForget(o.id);
		recall.unshift({ id: o.id, order_number: o.order_number, prev: prev, time: simNow() });
		if (recall.length > RECALL_MAX) { recall.length = RECALL_MAX; }
		renderRecall();
	}

	function recallForget(id) {
		recall = recall.filter(function (r) { return r.id !== id; });
		renderRecall();
	}

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
				el('span', { class: 'db-recall-info', text: orderTag(r) + ' · ' + Math.max(0, Math.floor((simNow() - r.time) / 60000)) + 'm ago → back to ' + label(r.prev) }),
				el('button', {
					class: 'button db-recall-restore', type: 'button',
					onclick: function () {
						recallForget(r.id);
						setStatus(r.id, r.prev);
					}
				}, ['Restore'])
			]));
		});
	}

	/* ------------------------------------------------- Today results strip */

	function renderToday() {
		if (!todayEl) { return; }
		var count = 0, gross = 0, paid = 0, toCollect = 0;
		orders.forEach(function (o) {
			if (o.status === 'cancelled') { return; }
			count++;
			gross += o.total;
			if (o.payment === 'paid') { paid += o.total; }
			else if (o.status !== 'completed') { toCollect += o.total; }
		});
		todayEl.textContent = '';
		[
			{ v: String(count), l: 'Orders today', c: '' },
			{ v: money(gross), l: 'Gross sales', c: '' },
			{ v: money(paid), l: 'Paid by card', c: 't-paid' },
			{ v: money(toCollect), l: 'Still to collect', c: 't-collect' }
		].forEach(function (t) {
			todayEl.appendChild(el('div', { class: 'db-today-tile ' + t.c }, [
				el('b', { text: t.v }),
				el('span', { text: t.l })
			]));
		});
	}

	/* -------------------------------------------------------------- Render */

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

	// Order ids that have already been rendered once — used so only genuinely
	// new arrivals play the enter animation.
	var seenIds = {};

	function card(o) {
		var isNew = o.status === 'pending';
		var showShop = !currentLocation && o.location_id && locationsById[o.location_id];
		var payChip = o.payment === 'paid'
			? el('span', { class: 'db-card-pay db-pay-paid', title: 'Paid online by card', text: 'PAID' })
			: el('span', { class: 'db-card-pay db-pay-collect', title: 'Take payment at handover', text: 'COLLECT ' + money(o.total) });

		var head = el('div', { class: 'db-card-head' }, [
			el('span', { class: 'db-card-number', text: o.order_number }),
			el('span', { class: 'db-card-type db-type-' + o.order_type, text: o.order_type === 'delivery' ? 'Delivery' : 'Pickup' }),
			o.later ? el('span', { class: 'db-card-later-chip', title: 'Scheduled order — sits calm until it’s due', text: 'LATER' }) : null,
			showShop ? el('span', { class: 'db-card-shop', text: locationsById[o.location_id] }) : null,
			payChip,
			el('span', { class: 'db-card-time', title: 'Time since the order arrived — the pill turns amber, then red, as it ages', text: elapsed(o.created_at) })
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
		if (o.pickup_window) {
			meta.push(el('div', { class: 'db-card-window', text: '⏰ Pickup window ' + o.pickup_window }));
		}
		if (o.eta_minutes) {
			meta.push(el('div', { class: 'db-card-eta', text: 'ETA ' + o.eta_minutes + ' min' }));
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
				return el('button', { class: 'button db-eta', type: 'button', title: 'Accept and promise ' + m + ' minutes', onclick: function () { accept(o.id, m); } }, [m + 'm']);
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

		// SLA aging — accepted orders still in an active state get amber at
		// 5 sim-minutes and red at 10 sim-minutes since acceptance.
		var ageClass = '';
		if (o.accepted_at && o.status !== 'completed' && o.status !== 'cancelled') {
			var ageMins = minutesSince(o.accepted_at);
			if (ageMins !== null && ageMins >= 10) { ageClass = ' db-age-late'; }
			else if (ageMins !== null && ageMins >= 5) { ageClass = ' db-age-warn'; }
		}

		var enterClass = (!seenIds[o.id] && !reduceMotion) ? ' db-card-enter' : '';

		return el('div', {
			class: 'db-card db-card-' + o.status + ageClass +
				(o.later ? ' db-card-later' : '') +
				(isNew && !o.acknowledged && !localAck[o.id] ? ' db-card-fresh' : '') +
				enterClass,
			'data-oid': String(o.id)
		}, [
			head,
			el('div', { class: 'db-card-status', text: label(o.status) }),
			contact,
			items,
			el('div', { class: 'db-card-meta' }, meta),
			actions
		]);
	}

	function visibleOrders() {
		return orders.filter(function (o) {
			if (ACTIVE.indexOf(o.status) === -1) { return false; }
			if (currentLocation && o.location_id !== currentLocation) { return false; }
			return true;
		});
	}

	// FLIP: capture card positions before a re-render, then spring each card
	// that survived the render from its old spot to its new one.
	function capturePositions() {
		var map = {};
		if (reduceMotion) { return map; }
		boardEl.querySelectorAll('.db-card[data-oid]').forEach(function (c) {
			map[c.getAttribute('data-oid')] = c.getBoundingClientRect();
		});
		return map;
	}

	function playFlip(before) {
		if (reduceMotion) { return; }
		boardEl.querySelectorAll('.db-card[data-oid]').forEach(function (c) {
			var old = before[c.getAttribute('data-oid')];
			if (!old) { return; }
			var now = c.getBoundingClientRect();
			var dx = old.left - now.left;
			var dy = old.top - now.top;
			if (Math.abs(dx) < 2 && Math.abs(dy) < 2) { return; }
			c.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
			c.style.transition = 'none';
			requestAnimationFrame(function () {
				c.style.transition = 'transform 380ms cubic-bezier(.32,.72,.33,1)';
				c.style.transform = '';
				c.addEventListener('transitionend', function () {
					c.style.transition = '';
				}, { once: true });
			});
		});
	}

	// Last all-day counts, so changed entries can play a little bump.
	var lastStrip = {};

	function render() {
		var before = capturePositions();
		var shown = visibleOrders();
		boardEl.textContent = '';

		// Persistent warning while sound is off — mirrors the real board's
		// "a reloaded tablet must never sit silent" rule.
		if (!audio.on) {
			boardEl.appendChild(el('div', { class: 'db-sound-warn' }, [
				'🔇 Sound is OFF — tap the sound toggle (top right) to hear the new-order chime in this preview.'
			]));
		}

		var unacked = shown.filter(function (o) {
			return o.status === 'pending' && !o.acknowledged && !localAck[o.id];
		});

		if (unacked.length) {
			var ids = unacked.map(function (o) { return o.id; });
			boardEl.appendChild(el('div', { class: 'db-banner' }, [
				el('span', { text: unacked.length + ' new order' + (unacked.length > 1 ? 's' : '') + '!' }),
				el('button', { class: 'button', type: 'button', onclick: function () { acknowledgeAll(ids); } }, ['Acknowledge'])
			]));
			startAlert();
		} else {
			stopAlert();
		}

		// All-day strip — aggregate item counts across in-progress orders so
		// the kitchen can batch ("6× Zaatar · 3× All Meat …").
		var STRIP_STATUSES = ['pending', 'confirmed', 'preparing', 'baking'];
		var counts = {};
		shown.forEach(function (o) {
			if (STRIP_STATUSES.indexOf(o.status) === -1) { return; }
			if (o.later) { return; } // Scheduled-for-later orders don't batch yet.
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
			boardEl.appendChild(el('div', { class: 'db-allday', title: 'Everything the kitchen still has to make, totalled across active orders' },
				[el('span', { class: 'db-allday-label', text: 'All day:' })].concat(
					stripEntries.map(function (e) {
						var changed = lastStrip[e.name] !== undefined && lastStrip[e.name] !== e.count;
						return el('span', { class: 'db-allday-item' + (changed && !reduceMotion ? ' bump' : ''), text: e.count + '× ' + e.name });
					})
				)));
		}
		lastStrip = counts;

		var lanesWrap = el('div', { class: 'db-lanes' }, LANES.map(function (lane) {
			var laneOrders = shown.filter(function (o) { return laneOf(o.status) === lane.key; });
			var cards = laneOrders.length
				? laneOrders.map(card)
				: [el('p', { class: 'db-lane-empty', text: 'None' })];
			return el('div', { class: 'db-lane db-lane-' + lane.key }, [
				el('h2', { class: 'db-lane-title' }, [lane.title + ' ', el('span', { class: 'db-lane-count', text: String(laneOrders.length) })])
			].concat(cards));
		}));
		boardEl.appendChild(lanesWrap);

		playFlip(before);
		shown.forEach(function (o) { seenIds[o.id] = true; });

		if (statusEl) {
			statusEl.textContent = simOffline
				? 'Connection problem — retrying… (simulated)'
				: shown.length + ' active · simulated · updated ' + new Date().toLocaleTimeString();
		}
		renderToday();
		renderPhone();
		if (recallOpen) { renderRecall(); }
	}

	/* ----------------------------------------------------------- Heartbeat */

	// Simulated connection badge: mostly "Live" (SSE), occasionally dropping
	// to "Polling" for a few seconds, exactly the states the real board shows.
	// "Offline" only ever appears via the explicit simulate-drop control.
	var simOffline = false;
	var sseHealthy = true;

	function updateHeartbeat() {
		if (!heartbeatEl) { return; }
		var state = simOffline ? 'offline' : (sseHealthy ? 'live' : 'polling');
		var word = simOffline ? 'Offline' : (sseHealthy ? 'Live' : 'Polling');
		heartbeatEl.className = 'db-heartbeat db-heartbeat-' + state;
		heartbeatEl.textContent = '';
		heartbeatEl.appendChild(el('span', { class: 'db-heartbeat-dot', 'aria-hidden': 'true' }));
		heartbeatEl.appendChild(el('span', { class: 'db-heartbeat-word', text: word }));
	}

	function heartbeatCycle() {
		// Drop to "Polling" for 5–8s every 20–35s, then recover to "Live".
		// While a simulated connection drop is running, that takes precedence.
		setTimeout(function () {
			if (!simOffline) {
				sseHealthy = false;
				updateHeartbeat();
			}
			setTimeout(function () {
				if (!simOffline) {
					sseHealthy = true;
					updateHeartbeat();
				}
				heartbeatCycle();
			}, 5000 + Math.random() * 3000);
		}, 20000 + Math.random() * 15000);
	}

	/* ------------------------------------------------- New-order simulator */

	function spawnOrder() {
		var tpl = INCOMING[incomingIdx % INCOMING.length];
		incomingIdx++;
		var o = makeOrder({
			status: 'pending',
			order_type: tpl.order_type,
			location_id: 1 + Math.floor(Math.random() * LOCATIONS.length),
			customer_name: tpl.customer_name,
			customer_phone: tpl.customer_phone,
			address: tpl.address,
			items: tpl.items.map(function (it) {
				return { name: it.name, quantity: it.quantity, size: it.size, toppings: it.toppings };
			}),
			total: tpl.total,
			payment: tpl.payment,
			created_at: simNow(),
			acknowledged: false
		});
		orders.push(o);
		render(); // The unacked pending order raises the banner + chime.
	}

	function scheduleSpawn() {
		setTimeout(function () {
			if (!simOffline) { spawnOrder(); }
			scheduleSpawn();
		}, 30000 + Math.random() * 15000); // Every 30–45 real seconds.
	}

	/* ---------------------------------------------------------------- Wire */

	if (soundBtn) {
		soundBtn.addEventListener('click', function () { setSound(!audio.on); });
	}

	// One-shot connection-drop demo: Offline (red) for ~8s, then it recovers
	// through Polling (amber) back to Live (green) — the exact sequence the
	// real board shows when the network blips and the poll takes over.
	if (offlineBtn) {
		offlineBtn.addEventListener('click', function () {
			if (simOffline) { return; }
			simOffline = true;
			sseHealthy = false;
			offlineBtn.disabled = true;
			offlineBtn.textContent = 'Offline — recovering…';
			updateHeartbeat();
			render();
			setTimeout(function () {
				simOffline = false;
				sseHealthy = false; // Recover via "Polling" first…
				offlineBtn.disabled = false;
				offlineBtn.textContent = 'Simulate connection drop';
				updateHeartbeat();
				render();
				setTimeout(function () {
					if (!simOffline) {
						sseHealthy = true; // …then back to "Live".
						updateHeartbeat();
					}
				}, 3000);
			}, 8000);
		});
	}

	if (shopSel) {
		shopSel.addEventListener('change', function () {
			currentLocation = parseInt(shopSel.value, 10) || 0;
			render();
		});
	}

	if (recallBtn) {
		recallBtn.addEventListener('click', function () {
			recallOpen = !recallOpen;
			renderRecall();
		});
	}

	// Intro / onboarding overlay — dismissible, session-only (no storage).
	function closeIntro() {
		if (introEl) { introEl.hidden = true; }
	}
	if (introClose) {
		introClose.addEventListener('click', closeIntro);
		introClose.focus();
	}
	if (introEl) {
		introEl.addEventListener('click', function (e) {
			if (e.target === introEl) { closeIntro(); }
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { closeIntro(); }
		});
	}

	// Resume the audio context after tab sleep, like the real board.
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden && audio.on && audio.ctx && audio.ctx.state === 'suspended') {
			audio.ctx.resume();
		}
	});

	// Accelerated clock: re-render every real second so timer pills, due
	// countdowns, SLA colours and the phone tracker visibly move.
	setInterval(render, 1000);

	// Seeded orders are "already on the board" — don't play their enter pop.
	orders.forEach(function (o) { seenIds[o.id] = true; });

	updateHeartbeat();
	heartbeatCycle();
	scheduleSpawn();
	render();
}());
