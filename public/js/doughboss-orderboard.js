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
		var t = Date.parse(String(created).replace(' ', 'T') + 'Z');
		if (isNaN(t)) { return ''; }
		var mins = Math.max(0, Math.floor((Date.now() - t) / 60000));
		return mins + 'm ago';
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
		return fetch(cfg.restUrl + path, {
			method: method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) { return r.json().catch(function () { return {}; }); });
	}

	function accept(id, eta) {
		localAck[id] = true;
		api('/admin/order/' + id + '/accept', 'POST', { eta: eta || 0 }).then(load);
	}

	function setStatus(id, status) {
		api('/admin/order/' + id + '/status', 'POST', { status: status }).then(load);
	}

	function acknowledgeAll(ids) {
		ids.forEach(function (id) {
			localAck[id] = true;
			api('/admin/order/' + id + '/ack', 'POST', {});
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
					el('button', { class: 'button db-cancel', type: 'button', onclick: function () { if (window.confirm('Cancel order ' + o.order_number + '?')) { setStatus(o.id, 'cancelled'); } } }, ['Cancel'])
				])
			]);
		} else {
			var advBtns = advanceActions(o).map(function (st) {
				var primary = (st === 'ready' || st === 'completed');
				return el('button', {
					class: 'button ' + (primary ? 'button-primary' : '') + ' db-advance',
					type: 'button',
					onclick: function () { setStatus(o.id, st); }
				}, [label(st)]);
			});
			advBtns.push(el('button', {
				class: 'button db-cancel', type: 'button',
				onclick: function () { if (window.confirm('Cancel order ' + o.order_number + '?')) { setStatus(o.id, 'cancelled'); } }
			}, ['Cancel']));
			actions = el('div', { class: 'db-card-actions' }, [el('div', { class: 'db-card-actions-row' }, advBtns)]);
		}

		return el('div', { class: 'db-card db-card-' + o.status + (isNew && !o.acknowledged && !localAck[o.id] ? ' db-card-fresh' : '') }, [
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

	/* --------------------------------------------------------------- Cycle */

	function load() {
		var path = '/admin/orders' + (currentLocation ? '?location_id=' + currentLocation : '');
		return api(path, 'GET').then(function (res) {
			if (res && res.data) { render(res.data); }
		}).catch(function () {
			if (statusEl) { statusEl.textContent = 'Connection problem — retrying…'; }
		});
	}

	function loop() {
		load().then(function () {
			pollTimer = setTimeout(loop, cfg.pollMs || 7000);
		});
	}

	if (soundBtn) {
		soundBtn.addEventListener('click', enableSound);
	}

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

	// Refresh "x ago" labels even between polls.
	setInterval(function () { if (lastOrders.length) { render(lastOrders); } }, 30000);

	loop();
}());
