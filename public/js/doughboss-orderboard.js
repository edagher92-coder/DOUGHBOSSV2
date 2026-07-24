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
		return by && by !== from ? from + '–' + by : from;
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
		var labels = { 'paid': 'Paid', 'unpaid': 'Pay at counter', 'refunded': 'Refunded' };
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
		return messages;
	}

	function buildAmendmentSummary(o) {
		var lines = (o.items || []).map(function (it) {
			var toppings = (it.toppings && it.toppings.length) ? ' (' + it.toppings.map(toppingLabel).filter(Boolean).join(', ') + ')' : '';
			return (parseInt(it.quantity, 10) || 1) + 'x ' + (it.name || 'Menu item') + toppings;
		});
		return orderTag(o) + ' - ' + serviceLabel(o) + '\n' + lines.join('\n');
	}

	var amendmentPanel = null;
	function closeAmendmentReview() {
		if (amendmentPanel) { amendmentPanel.hidden = true; amendmentPanel.textContent = ''; }
	}

	function copyAmendmentSummary(o, feedback) {
		var summary = buildAmendmentSummary(o);
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(summary).then(function () {
				feedback.textContent = 'Order summary copied. Use it when confirming the change with a manager or customer.';
			}).catch(function () {
				feedback.textContent = 'Copy is unavailable in this browser. Read the line list above to the manager.';
			});
		} else {
			feedback.textContent = 'Copy is unavailable in this browser. Read the line list above to the manager.';
		}
	}

	function openAmendmentReview(o) {
		if (!amendmentPanel) { return; }
		amendmentPanel.hidden = false;
		amendmentPanel.textContent = '';
		var feedback = el('p', { class: 'db-amendment-feedback', role: 'status' }, []);
		var currentItems = el('ul', { class: 'db-amendment-items' }, (o.items || []).map(itemLine));
		amendmentPanel.appendChild(el('div', { class: 'db-amendment-head' }, [
			el('h2', { text: 'Review change for ' + orderTag(o) }),
			el('button', { class: 'button db-amendment-close', type: 'button', onclick: closeAmendmentReview }, ['Close'])
		]));
		amendmentPanel.appendChild(el('p', { class: 'db-amendment-context', text: serviceLabel(o) + ' - ' + paymentLabel(o.payment_status) + ' - ' + label(o.status) }));
		amendmentPanel.appendChild(el('h3', { text: 'Current items' }));
		amendmentPanel.appendChild(currentItems);
		amendmentPanel.appendChild(el('p', { class: 'db-amendment-boundary', text: 'This kitchen board is intentionally review-only for add/remove requests. It never changes a live or paid order total. Confirm the exact request, then use the manager-safe reprice and payment-adjustment workflow when it is available.' }));
		amendmentPanel.appendChild(el('div', { class: 'db-amendment-actions' }, [
			el('button', { class: 'button', type: 'button', onclick: function () { copyAmendmentSummary(o, feedback); } }, ['Copy order summary']),
			el('button', { class: 'button', type: 'button', onclick: closeAmendmentReview }, ['Done'])
		]));
		amendmentPanel.appendChild(feedback);
		amendmentPanel.focus();
	}

	function itemLine(it) {
		var quantity = Math.max(1, parseInt(it.quantity, 10) || 1);
		var toppings = (it.toppings && it.toppings.length)
			? it.toppings.map(toppingLabel).filter(Boolean).join(', ')
			: '';
		return el('li', { class: 'db-card-item' }, [
			el('span', { class: 'db-card-item-quantity', text: quantity + 'x' }),
			el('span', { class: 'db-card-item-body' }, [
				el('span', { class: 'db-card-item-name', text: it.name || 'Menu item' }),
				it.size ? el('span', { class: 'db-card-item-size', text: it.size }) : null,
				toppings ? el('span', { class: 'db-card-item-toppings', text: 'Custom: ' + toppings }) : null
			])
		]);
	}

	/* ------------------------------------------------------ Pre-order review */

	// These requests are deliberately outside the normal live KDS lanes: they
	// are unpaid and are not a kitchen commitment until a staff member has
	// spoken to the customer. This panel keeps the required morning decision in
	// the same staff surface without making it look like an ordinary new order.
	function setPreorderMessage(message, isError) {
		if (!preorderPanel) { return; }
		var feedback = preorderPanel.querySelector('.db-preorder-feedback');
		if (!feedback) { return; }
		feedback.textContent = message || '';
		feedback.classList.toggle('notice-error', !!isError);
		feedback.hidden = !message;
	}

	function decidePreorder(o, decision, contactConfirmed, eta) {
		if (offline || !setPreorderBusy(o, 'preorder-' + decision, true)) { return; }
		api('/admin/preorder/' + o.id + '/decision', 'POST', {
			decision: decision,
			contact_confirmed: !!contactConfirmed,
			eta: eta || 0,
			expected_version: o.version,
			event_key: eventKey(o, 'preorder-' + decision)
		}).then(function (res) {
			var message = res && res.message ? res.message : 'Pre-order request updated.';
			return load().then(function () {
				setPreorderMessage(message, false);
				if (statusEl) { statusEl.textContent = message; }
			});
		}).catch(function (error) {
			var message = error.message || 'Could not update the pre-order request.';
			return load().then(function () {
				setPreorderMessage(message, true);
				if (statusEl) { statusEl.textContent = message; }
			});
		}).then(function () {
			setPreorderBusy(o, 'preorder-' + decision, false);
		});
	}

	function preorderCard(o) {
		var timingConfirmed = false;
		var rejectOpen = false;
		var eta = 0;
		var cardEl = el('article', {
			class: 'db-card db-card-pending db-preorder-card',
			'data-preorder-id': String(o.id),
			'aria-busy': 'false'
		}, []);
		var acceptBtn = el('button', {
			class: 'button button-primary db-preorder-accept', type: 'button',
			text: 'Accept into kitchen', disabled: 'disabled'
		}, []);
		var rejectBtn = el('button', { class: 'button db-preorder-reject', type: 'button', text: 'Reject request' }, []);
		var rejectConfirm = el('div', { class: 'db-card-actions-row', hidden: 'hidden' }, []);
		var etaSelect = el('select', { class: 'db-preorder-eta', 'aria-label': 'Agreed pickup estimate for ' + orderTag(o) }, []);
		etaSelect.appendChild(el('option', { value: '0', text: 'Timing agreed by phone' }));
		ETA_CHOICES.forEach(function (minutes) {
			etaSelect.appendChild(el('option', { value: String(minutes), text: minutes + ' minutes' }));
		});
		etaSelect.addEventListener('change', function () { eta = parseInt(etaSelect.value, 10) || 0; });
		var contactCheck = el('input', { type: 'checkbox' }, []);
		contactCheck.addEventListener('change', function () {
			timingConfirmed = !!contactCheck.checked;
			acceptBtn.disabled = !timingConfirmed || offline;
		});
		acceptBtn.addEventListener('click', function () { decidePreorder(o, 'accept', timingConfirmed, eta); });
		rejectBtn.addEventListener('click', function () {
			rejectOpen = !rejectOpen;
			rejectConfirm.hidden = !rejectOpen;
			rejectBtn.textContent = rejectOpen ? 'Keep request' : 'Reject request';
		});
		rejectConfirm.appendChild(el('span', { text: 'No payment has been taken. Reject this request?' }));
		rejectConfirm.appendChild(el('button', {
			class: 'button button-link-delete', type: 'button', text: 'Confirm reject',
			onclick: function () { decidePreorder(o, 'reject', false, 0); }
		}, []));

		cardEl.appendChild(el('div', { class: 'db-card-head' }, [
			el('span', { class: 'db-card-number', text: o.order_number || orderTag(o) }),
			el('span', { class: 'db-card-time', text: elapsed(o.created_at) })
		]));
		cardEl.appendChild(el('div', { class: 'db-card-status', text: 'Pre-order request — awaiting morning review' }));
		cardEl.appendChild(el('div', { class: 'db-card-contact' }, [
			el('strong', { text: o.customer_name || 'Customer' }),
			o.customer_phone ? el('span', { text: ' · ' + o.customer_phone }) : null
		]));
		if (o.notes) { cardEl.appendChild(el('div', { class: 'db-card-notes', text: 'Notes: ' + o.notes })); }
		cardEl.appendChild(el('ul', { class: 'db-card-items', 'aria-label': (o.items || []).length + ' items' }, (o.items || []).map(itemLine)));
		cardEl.appendChild(el('div', { class: 'db-card-meta' }, [
			el('div', { class: 'db-card-exception', role: 'alert', text: 'Unconfirmed and unpaid — call the customer to agree pickup timing before accepting.' }),
			el('div', { class: 'db-card-payment db-payment-unpaid', text: 'Payment: unpaid — agree the payment method with the customer.' }),
			el('div', { class: 'db-card-exception', text: 'POSPal is deferred. After acceptance, create or confirm the POS action and issue the required manual kitchen ticket.' }),
			el('div', { class: 'db-card-total', text: 'Total ' + money(o.total) })
		]));
		cardEl.appendChild(el('label', { class: 'db-preorder-contact-check' }, [
			contactCheck,
			' I called the customer and agreed pickup timing.'
		]));
		cardEl.appendChild(el('div', { class: 'db-card-actions' }, [
			el('div', { class: 'db-card-actions-row' }, [etaSelect, acceptBtn, rejectBtn]),
			rejectConfirm
		]));
		return cardEl;
	}

	function renderPreorders(requests) {
		if (!preorderPanel) { return; }
		preorderPanel.textContent = '';
		if (!requests.length) {
			preorderPanel.hidden = true;
			return;
		}
		preorderPanel.hidden = false;
		preorderPanel.appendChild(el('h2', { id: 'db-preorder-review-title', text: 'Morning pre-order review (' + requests.length + ')' }));
		preorderPanel.appendChild(el('p', { text: 'These requests are not confirmed, paid, sent to POSPal, or printed for the kitchen. Call first, then accept or reject.' }));
		preorderPanel.appendChild(el('div', { class: 'db-preorder-feedback', role: 'status', hidden: 'hidden' }, []));
		var list = el('div', { class: 'db-lanes db-preorder-list' }, requests.map(preorderCard));
		preorderPanel.appendChild(list);
		applyConnectionState();
	}

	function card(o) {
		var isNew = o.status === 'pending';
		var showShop = LOCATIONS.length > 1 && !currentLocation && o.location_id && locationsById[o.location_id];
		var tableLabel = o.dining_table_label || o.table_label || (o.table && o.table.label) || '';
		var typeLabel = serviceLabel(o);
		var source = sourceLabel(o.order_source);
		var head = el('div', { class: 'db-card-head' }, [
			el('span', { class: 'db-card-number', text: o.order_number }),
			el('span', { class: 'db-card-type db-type-' + o.order_type, text: typeLabel }),
			tableLabel ? el('span', { class: 'db-card-table', text: 'TABLE ' + tableLabel }) : null,
			source ? el('span', { class: 'db-card-source', text: source }) : null,
			showShop ? el('span', { class: 'db-card-shop', text: locationsById[o.location_id] }) : null,
			el('span', { class: 'db-card-time', text: elapsed(o.created_at) })
		]);

		var contact = el('div', { class: 'db-card-contact' }, [
			el('strong', { text: o.customer_name || '—' }),
			o.customer_phone ? el('span', { text: ' · ' + o.customer_phone }) : null
		]);

		var items = el('ul', { class: 'db-card-items', 'aria-label': (o.items || []).length + ' items' }, (o.items || []).map(itemLine));

		var meta = [];
		exceptionMessages(o).forEach(function (message) {
			meta.push(el('div', { class: 'db-card-exception', role: 'alert', text: message }));
		});
		if (o.order_type === 'delivery' && o.address) {
			meta.push(el('div', { class: 'db-card-addr', text: '🛵 ' + o.address }));
		}
		if (o.notes) {
			meta.push(el('div', { class: 'db-card-notes', text: '📝 ' + o.notes }));
		}
		if (o.eta_minutes) {
			var windowText = readyWindow(o);
			meta.push(el('div', { class: 'db-card-eta', text: windowText ? 'Staff estimate ' + windowText : 'Staff estimate ' + o.eta_minutes + ' min' }));
		}
		if (o.timing_status === 'estimate_passed') {
			meta.push(el('div', { class: 'db-card-timing db-card-timing-passed', text: o.timing_label || 'Estimate passed — check this order' }));
		}
		meta.push(el('div', { class: 'db-card-payment db-payment-' + (o.payment_status || 'unknown'), text: 'Payment: ' + paymentLabel(o.payment_status) }));
		meta.push(el('div', { class: 'db-card-total', text: 'Total ' + money(o.total) }));

		var actions;
		if (isNew) {
			var etaRow = ETA_CHOICES.map(function (m) {
				return el('button', { class: 'button db-eta', type: 'button', 'aria-label': 'Accept order ' + o.order_number + ', ready in ' + m + ' minutes', onclick: function () { accept(o, m); } }, [m + 'm']);
			});
			actions = el('div', { class: 'db-card-actions' }, [
				el('div', { class: 'db-accept-label', text: 'Accept — ready in:' }),
				el('div', { class: 'db-eta-row' }, etaRow),
				el('div', { class: 'db-card-actions-row' }, [
					el('button', { class: 'button db-review-change', type: 'button', 'aria-label': 'Review an add or remove item request for order ' + o.order_number, onclick: function () { openAmendmentReview(o); } }, ['Review change']),
					el('button', { class: 'button button-primary db-accept', type: 'button', 'aria-label': 'Accept order ' + o.order_number + ' without an estimate', onclick: function () { accept(o, 0); } }, ['Accept'])
				])
			]);
		} else {
			var advBtns = advanceActions(o).map(function (st) {
				var primary = (st === 'ready' || st === 'completed');
				return el('button', {
					class: 'button ' + (primary ? 'button-primary' : '') + ' db-advance',
					type: 'button',
					'aria-label': orderLabel(o, st) + ' for order ' + o.order_number,
					onclick: function () { setStatus(o, st); }
				}, [orderLabel(o, st)]);
			});
			var actionRow = [
				el('button', { class: 'button db-review-change', type: 'button', 'aria-label': 'Review an add or remove item request for order ' + o.order_number, onclick: function () { openAmendmentReview(o); } }, ['Review change'])
			].concat(advBtns);
			actions = el('div', { class: 'db-card-actions' }, [
				!advBtns.length ? el('p', { class: 'db-no-next-action', text: 'No next kitchen step is available. Refresh or ask a manager before changing this order.' }) : null,
				el('div', { class: 'db-card-actions-row' }, actionRow)
			]);
		}

		// SLA aging — accepted orders still in an active state get amber at 5 min
		// and red at 10 min since acceptance.
		var ageClass = '';
		if (o.accepted_at && o.status !== 'completed' && o.status !== 'cancelled') {
			var ageMins = minutesSince(o.accepted_at);
			if (ageMins !== null && ageMins >= 10) { ageClass = ' db-age-late'; }
			else if (ageMins !== null && ageMins >= 5) { ageClass = ' db-age-warn'; }
		}

		return el('div', { class: 'db-card db-card-' + o.status + ageClass + (isNew && !o.acknowledged && !localAck[o.id] ? ' db-card-fresh' : ''), 'data-order-id': String(o.id), 'aria-busy': inFlight[String(o.id)] ? 'true' : 'false' }, [
			head,
			el('div', { class: 'db-card-status', text: orderLabel(o, o.status) }),
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
		applyConnectionState();
	}

	/* ----------------------------------------------------------- Heartbeat */

	// Small connection badge next to the board status: green "Live" (SSE),
	// amber "Polling" (poll OK), red "Offline" (last load failed).
	var offline = false;
	var heartbeatEl = null;

	function syncTimeLabel() {
		return lastSuccessfulSync ? lastSuccessfulSync.toLocaleTimeString() : 'never';
	}

	function applyConnectionState() {
		if (boardEl) {
			boardEl.classList.toggle('db-board-offline', offline);
			var mutationButtons = boardEl.querySelectorAll('button');
			for (var i = 0; i < mutationButtons.length; i++) {
				var card = mutationButtons[i].closest('[data-order-id]');
				mutationButtons[i].disabled = offline || !!(card && inFlight[card.getAttribute('data-order-id')]);
			}
		}
		if (preorderPanel) {
			var preorderButtons = preorderPanel.querySelectorAll('button');
			for (var j = 0; j < preorderButtons.length; j++) {
				var preorderCardEl = preorderButtons[j].closest('[data-preorder-id]');
				var preorderBusy = preorderCardEl && inFlight[preorderCardEl.getAttribute('data-preorder-id')];
				var contactCheck = preorderCardEl && preorderCardEl.querySelector('.db-preorder-contact-check input[type="checkbox"]');
				preorderButtons[j].disabled = offline || !!preorderBusy ||
					(preorderButtons[j].classList.contains('db-preorder-accept') && (!contactCheck || !contactCheck.checked));
			}
		}
		if (retryBtn) { retryBtn.hidden = !offline; }
		if (statusEl) {
			statusEl.textContent = offline
				? 'Offline - showing orders last synced ' + syncTimeLabel() + '. Changes are locked.'
				: lastOrders.length + ' active - synced ' + syncTimeLabel();
		}
	}

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
			if (!res || !Array.isArray(res.data)) { throw new Error('The order feed returned an invalid response.'); }
			offline = false;
			lastSuccessfulSync = new Date();
			updateHeartbeat();
			render(res.data);
			var preorderPath = '/admin/preorder-requests?per_page=100' + (currentLocation ? '&location_id=' + currentLocation : '');
			return api(preorderPath, 'GET').then(function (preorders) {
				if (!preorders || !Array.isArray(preorders.data)) { throw new Error('The pre-order review feed returned an invalid response.'); }
				renderPreorders(preorders.data);
			}).catch(function (error) {
				// A review-feed failure must never blank or lock the live kitchen board.
				if (preorderPanel) { preorderPanel.hidden = true; }
				if (statusEl) { statusEl.textContent = 'Kitchen board synced; pre-order review unavailable: ' + (error.message || 'retrying'); }
			});
		}).catch(function () {
			offline = true;
			updateHeartbeat();
			// Preserve the connection warning below, then replace it with the
			// actionable offline state after this error callback completes.
			setTimeout(applyConnectionState, 0);
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
	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && amendmentPanel && !amendmentPanel.hidden) {
			closeAmendmentReview();
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
		if (actionsEl) {
			retryBtn = el('button', {
				class: 'button db-board-retry', type: 'button', hidden: 'hidden',
				'aria-label': 'Retry loading the live order board now',
				onclick: function () { load(); }
			}, ['Retry now']);
			actionsEl.insertBefore(retryBtn, statusEl.nextSibling);
		}
		updateHeartbeat();
		applyConnectionState();
	}

	// The KDS may review the precise current line list, but it must never become
	// a back door for repricing or refunding an order. The panel is created once
	// outside the refreshed board so focus and the review remain stable.
	if (boardEl && boardEl.parentNode) {
		amendmentPanel = el('section', {
			class: 'db-amendment-panel',
			tabindex: '-1',
			'aria-label': 'Order change review'
		}, []);
		amendmentPanel.hidden = true;
		boardEl.parentNode.insertBefore(amendmentPanel, boardEl);
	}

	// Refresh "x ago" labels even between polls.
	setInterval(function () { if (lastOrders.length) { render(lastOrders); } }, 30000);

	// Open the real-time SSE channel when configured; the poll below stays as the
	// always-on fallback regardless.
	connectSse();

	loop();
}());
