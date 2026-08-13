­r‡^Ñf¥–Ø¦{M¬yÊ'vÃ®¶›­/**
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
	var SCREEN_MODE = ['make', 'pass', 'catering'].indexOf(cfg.screenMode) !== -1 ? cfg.screenMode : 'all';
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
			: (SCREEN_MODE === 'catering'
				? [
					{ key: 'new', title: 'Deposit paid', statuses: ['deposit_paid'] },
					{ key: 'prep', title: 'Booked / production', statuses: ['confirmed', 'balance_due'] },
					{ key: 'ready', title: 'Paid / hand-off', statuses: ['paid'] }
				]
				: ALL_LANES));

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
	var summaryTimer = null;
	var retryBtn = null;
	var lastSuccessfulSync = null;
	// Multiple refresh signals can overlap (poll, SSE and a staff action). Only
	// the newest response may repaint the board, otherwise a slower stale request
	// can visually undo a just-completed status transition.
	var loadEpoch = 0;
	var summaryEpoch = 0;
	var summaryAbort = null;
	var summaryLoaded = false;
	var summary = { make: null, pass: null, preorder_review: null, catering: null };
	var tables = [];
	var tablesLoaded = false;
	var tablesEpoch = 0;
	var tablesAbort = null;
	var tableInFlight = {};
	var summaryAnnouncement = document.querySelector('[data-db-board-summary-announcement]');
	var summaryLinks = {};
	['make', 'pass', 'catering'].forEach(function (mode) {
		summaryLinks[mode] = document.querySelector('[data-db-board-summary-link="' + mode + '"]');
	});

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
				if (props[k] === null || props[k] === undefined) { return; }
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

	function tableEventKey(table, action) {
		return ['table', table.id, table.reservation_version, action, Date.now(), Math.random().toString(36).slice(2)].join(':');
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

	function api(path, method, body, options) {
		var headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce };
		if (cfg.boardKey) { headers['X-DoughBoss-Board-Key'] = cfg.boardKey; }
		return fetch(cfg.restUrl + path, {
			method: method || 'GET',
			headers: headers,
			body: body ? JSON.stringify(body) : undefined,
			signal: options && options.signal ? options.signal : undefined
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (data) {
				if (!r.ok) { throw new Error(data.message || 'Request failed.'); }
				return data;
			});
		});
	}

	function scopedPath(path) {
		return path + (currentLocation ? '?location_id=' + encodeURIComponent(currentLocation) : '');
	}

	function normalCount(value) {
		var count = parseInt(value, 10);
		return isNaN(count) || count < 0 ? 0 : count;
	}

	function compactCount(value) {
		return value > 99 ? '99+' : String(value);
	}

	function summaryLabel(mode, counts, stale) {
		var label = mode.charAt(0).toUpperCase() + mode.slice(1);
		var count = counts[mode];
		var detail = label + ' ' + count + (count === 1 ? ' active item' : ' active items');
		if (mode === 'pass' && counts.preorder_review) {
			detail += ', including ' + counts.preorder_review + (counts.preorder_review === 1 ? ' pre-order review' : ' pre-order reviews');
		}
		return stale ? detail + ', last known count is stale' : detail;
	}

	function renderSummary(counts, stale) {
		['make', 'pass', 'catering'].forEach(function (mode) {
			var link = summaryLinks[mode];
			if (!link) { return; }
			var badge = link.querySelector('[data-db-board-count="' + mode + '"]');
			if (badge) { badge.textContent = compactCount(counts[mode]); }
			link.classList.toggle('db-portal-mode--stale', !!stale);
			link.setAttribute('aria-label', summaryLabel(mode, counts, stale));
		});
	}

	function loadSummary() {
		var requestEpoch = ++summaryEpoch;
		if (summaryAbort && summaryAbort.abort) { summaryAbort.abort(); }
		summaryAbort = window.AbortController ? new window.AbortController() : null;
		return api(scopedPath('/admin/board-summary'), 'GET', null, summaryAbort ? { signal: summaryAbort.signal } : null).then(function (res) {
			if (requestEpoch !== summaryEpoch || !res || !res.data) { return; }
			var next = {
				make: normalCount(res.data.make),
				pass: normalCount(res.data.pass),
				preorder_review: normalCount(res.data.preorder_review),
				catering: normalCount(res.data.catering)
			};
			var changed = summaryLoaded && ['make', 'pass', 'preorder_review', 'catering'].some(function (key) { return next[key] !== summary[key]; });
			summary = next;
			summaryLoaded = true;
			renderSummary(summary, false);
			if (changed && summaryAnnouncement) {
				summaryAnnouncement.textContent = 'Kitchen workload updated: ' + summaryLabel('make', summary, false) + '; ' + summaryLabel('pass', summary, false) + '; ' + summaryLabel('catering', summary, false) + '.';
			}
		}).catch(function (error) {
			if (requestEpoch !== summaryEpoch || (error && error.name === 'AbortError')) { return; }
			if (summaryLoaded) { renderSummary(summary, true); }
		});
	}

	/* --------------------------------------------------------- Table service */

	function tableCountdown(table) {
		var seconds = Math.max(0, parseInt(table && table.seconds_remaining, 10) || 0);
		if (!seconds) { return ''; }
		var minutes = Math.floor(seconds / 60);
		var remainder = seconds % 60;
		return minutes + ':' + (remainder < 10 ? '0' : '') + remainder;
	}

	function tableStatusLabel(table) {
		if (!table || table.status === 'inactive') { return 'Inactive'; }
		if (table.status !== 'reserved') { return 'Available'; }
		return 'Reserved ' + tableCountdown(table);
	}

	function tableSourceLabel(table) {
		if (!table || table.status !== 'reserved') { return ''; }
		if (table.reservation_source === 'order') {
			return table.reserved_order_number ? 'QR order ' + table.reserved_order_number : 'QR order';
		}
		return 'Staff hold';
	}

	function renderTableOccupancy() {
		if (!boardEl || SCREEN_MODE === 'catering') { return null; }
		var existing = boardEl.querySelector('.db-table-occupancy');
		if (existing) { existing.remove(); }
		var panel = el('section', { class: 'db-table-occupancy', 'aria-label': 'Table service' }, []);
		var reserved = tables.filter(function (table) { return table.status === 'reserved'; }).length;
		var available = tables.filter(function (table) { return table.status === 'available'; }).length;
		panel.appendChild(el('div', { class: 'db-table-occupancy-head' }, [
			el('div', {}, [
				el('span', { class: 'db-table-occupancy-kicker', text: 'Table service' }),
				el('h2', { text: tablesLoaded ? reserved + ' reserved Â· ' + available + ' available' : 'Loading tablesâ€¦' })
			]),
			el('p', { text: 'QR orders reserve a table for 15 minutes. A later order from that table renews the hold.' })
		]));
		if (!tablesLoaded) {
			panel.appendChild(el('p', { class: 'db-table-occupancy-empty', text: 'Loading the current table stateâ€¦' }));
			return panel;
		}
		if (!tables.length) {
			panel.appendChild(el('p', { class: 'db-table-occupancy-empty', text: 'No active table QR codes have been issued for this shop yet.' }));
			return panel;
		}
		var grid = el('div', { class: 'db-table-occupancy-grid' }, tables.map(function (table) {
			var status = table.status || 'available';
			var busy = !!tableInFlight[String(table.id)];
			var card = el('article', {
				class: 'db-table-card db-table-card--' + status,
				'data-table-id': String(table.id),
				'aria-busy': busy ? 'true' : 'false'
			}, []);
			card.appendChild(el('div', { class: 'db-table-card-head' }, [
				el('strong', { text: 'TABLE ' + (table.label || table.id) }),
				el('span', { class: 'db-table-card-status', text: tableStatusLabel(table) })
			]));
			card.appendChild(el('p', { class: 'db-table-card-zone', text: table.zone || table.location_name || 'Dining' }));
			card.appendChild(el('p', { class: 'db-table-card-source', text: tableSourceLabel(table) || (status === 'available' ? 'Ready for a new QR order' : 'Not in service') }));
			if (status !== 'inactive') {
				var action = status === 'reserved' ? 'release' : 'reserve';
				var labelText = status === 'reserved' ? 'Release' : 'Reserve 15 min';
				card.appendChild(el('button', {
					class: 'button db-table-card-action',
					type: 'button',
					disabled: busy || offline ? 'disabled' : null,
					onclick: function () { updateTableReservation(table, action); }
				}, [labelText]));
			}
			return card;
		}));
		panel.appendChild(grid);
		return panel;
	}

	function tablePanelPosition() {
		if (!boardEl || SCREEN_MODE === 'catering') { return; }
		var panel = renderTableOccupancy();
		if (!panel) { return; }
		var pulse = boardEl.querySelector('.db-live-pulse');
		if (pulse && pulse.parentNode === boardEl) {
			boardEl.insertBefore(panel, pulse.nextSibling);
		} else {
			boardEl.insertBefore(panel, boardEl.firstChild);
		}
	}

	function loadTableOccupancy() {
		if (SCREEN_MODE === 'catering') { return Promise.resolve(); }
		var requestEpoch = ++tablesEpoch;
		if (tablesAbort && tablesAbort.abort) { tablesAbort.abort(); }
		tablesAbort = window.AbortController ? new window.AbortController() : null;
		return api(scopedPath('/admin/table-occupancy'), 'GET', null, tablesAbort ? { signal: tables÷®6¶‰žËkºwµç@‘ˆµ…Ñ•É¥¹œµ‘Õ”œ°Ñ•áÐè…Ñ•É¥¹Õ”¡©½ˆ¤ô¤°($$%•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…ÉµÍÑ…ÑÕÌœ°Ñ•áÐè©½ˆ¹ÍÑ…ÑÕÍ}±…‰•°ñð©½ˆ¹ÍÑ…ÑÕÌô¤°($$%•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…Ñ•É¥¹œµ½¹Ñ…Ðœô°l($$$%•° ÍÑÉ½¹œœ°ìÑ•áÐè©½ˆ¹ÕÍÑ½µ•É}¹…µ”ñð€ÕÍÑ½µ•Èœô¤°($$$%©½ˆ¹ÕÍÑ½µ•É}Á¡½¹”€ü•° ÍÁ…¸œ°ìÑ•áÐè€œƒ
Ü€œ€¬©½ˆ¹ÕÍÑ½µ•É}Á¡½¹”ô¤€è¹Õ±°($$%t¤°($$%•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…Ñ•É¥¹œµÁ…­…”œô°l($$$%•° ÍÑÉ½¹œœ°ìÑ•áÐè©½ˆ¹Á…­…•}¹…µ”ñð€ÕÍÑ½´…Ñ•É¥¹œœô¤°($$$%•° ÍÁ…¸œ°ìÑ•áÐè€¡9Õµ‰•È¡©½ˆ¹Õ•ÍÑ}½Õ¹Ð¤ñð€À¤€¬€œÕ•ÍÑÌœô¤($$%t¤°($$%•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…Éµµ•Ñ„œô°µ•Ñ„¤°($$%•° Àœ°ì±…ÍÌè€‘ˆµ…Ñ•É¥¹œµÉ•…‘½¹±äœ°Ñ•áÐè€AÉ½‘ÕÑ¥½¸‘¥ÍÁ±…äƒ
Ü±¥™•å±”¡…¹•ÌÍÑ…ä¥¸…Ñ•É¥¹œ¹ÅÕ¥É¥•Ì¸œô¤($%t¤ì(%ô((%™Õ¹Ñ¥½¸É•¹‘•É…Ñ•É¥¹œ¡©½‰Ì¤ì($%±…ÍÑ=É‘•ÉÌ€ô©½‰Ìì($%‰½…É‘°¹Ñ•áÑ½¹Ñ•¹Ð€ô€œœì($%Ù…ÈÕ•ÍÑQ½Ñ…°€ô©½‰Ì¹É•‘Õ”¡™Õ¹Ñ¥½¸€¡ÍÕ´°©½ˆ¤ìÉ•ÑÕÉ¸ÍÕ´€¬€¡9Õµ‰•È¡©½ˆ¹Õ•ÍÑ}½Õ¹Ð¤ñð€À¤ìô°€À¤ì($%Ù…È‘•±¥Ù•Éå½Õ¹Ð€ô©½‰Ì¹™¥±Ñ•È¡™Õ¹Ñ¥½¸€¡©½ˆ¤ìÉ•ÑÕÉ¸©½ˆ¹½É‘•É}ÑåÁ”€ôôô€‘•±¥Ù•Éäœìô¤¹±•¹Ñ ì($%Ù…È½Ù•É‘Õ•½Õ¹Ð€ô©½‰Ì¹™¥±Ñ•È¡…Ñ•É¥¹%Í=Ù•É‘Õ”¤¹±•¹Ñ ì($%‰½…É‘°¹…ÁÁ•¹‘¡¥±¡•° Í•Ñ¥½¸œ°ì±…ÍÌè€‘ˆµ±¥Ù”µÁÕ±Í”‘ˆµ…Ñ•É¥¹œµÁÕ±Í”œ°€…É¥„µ±…‰•°œè€…Ñ•É¥¹œÁÉ½‘ÕÑ¥½¸ÍÕµµ…Éäœô°l($$%•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ±¥Ù”µÁÕ±Í•}}µ…¥¸œô°l($$$%•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµ±¥Ù”µÁÕ±Í•}}­¥­•Èœ°Ñ•áÐè€½µµ¥ÑÑ•©½‰Ìœô¤°($$$%•° ÍÑÉ½¹œœ°ìÑ•áÐè©½‰Ì¹±•¹Ñ €¬€œ…Ñ¥Ù”œô¤°($$$%•° Íµ…±°œ°ìÑ•áÐèÕ•ÍÑQ½Ñ…°€¬€œÕ•ÍÑÌ…É½ÍÌÑ¡”ÅÕ•Õ”œô¤($$%t¤°($$%•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ±¥Ù”µÁÕ±Í•}}¡¥ÁÌœô°l($$$%•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµÁÕ±Í”µ¡¥Àœ°Ñ•áÐè‘•±¥Ù•Éå½Õ¹Ð€¬€œ‘•±¥Ù•Éäœô¤°($$$%•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµÁÕ±Í”µ¡¥Àœ°Ñ•áÐè€¡©½‰Ì¹±•¹Ñ €´‘•±¥Ù•Éå½Õ¹Ð¤€¬€œÁ¥­ÕÀœô¤°($$$%½Ù•É‘Õ•½Õ¹Ð€ü•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµÁÕ±Í”µ¡¥À‘ˆµÁÕ±Í”µ¡¥À´µ±…Ñ”œ°Ñ•áÐè½Ù•É‘Õ•½Õ¹Ð€¬€œ½Ù•É‘Õ”¡•¬œô¤€è¹Õ±°($$%t¤($%t¤¤ì(($%‰½…É‘°¹…ÁÁ•¹‘¡¥±¡•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ±…¹•Ìœô°19L¹µ…À¡™Õ¹Ñ¥½¸€¡±…¹”¤ì($$%Ù…È±…¹•)½‰Ì€ô©½‰Ì¹™¥±Ñ•È¡™Õ¹Ñ¥½¸€¡©½ˆ¤ìÉ•ÑÕÉ¸±…¹”¹ÍÑ…ÑÕÍ•Ì¹¥¹‘•á=˜¡©½ˆ¹ÍÑ…ÑÕÌ¤€„ôô€´Äìô¤ì($$%É•ÑÕÉ¸•° Í•Ñ¥½¸œ°ì±…ÍÌè€‘ˆµ±…¹”‘ˆµ±…¹”´œ€¬±…¹”¹­•äô°l($$$%•°  Èœ°ì±…ÍÌè€‘ˆµ±…¹”µÑ¥Ñ±”œô°m±…¹”¹Ñ¥Ñ±”€¬€œ€œ°•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµ±…¹”µ½Õ¹Ðœ°Ñ•áÐèMÑÉ¥¹œ¡±…¹•)½‰Ì¹±•¹Ñ ¤ô¥t¤°($$$%•° Àœ°ì±…ÍÌè€‘ˆµ±…¹”µÍÕ‰Ñ¥Ñ±”œ°Ñ•áÐè±…¹•MÕ‰Ñ¥Ñ±”¡±…¹”¹­•ä¤ô¤($$%t¹½¹…Ð¡±…¹•)½‰Ì¹±•¹Ñ €ü±…¹•)½‰Ì¹µ…À¡…Ñ•É¥¹…É¤€èm•° Àœ°ì±…ÍÌè€‘ˆµ±…¹”µ•µÁÑäœ°Ñ•áÐè€9½¹”œô¥t¤¤ì($%ô¤¤¤ì(($%¥˜€¡ÍÑ…ÑÕÍ°¤ì($$%ÍÑ…ÑÕÍ°¹Ñ•áÑ½¹Ñ•¹Ð€ô©½‰Ì¹±•¹Ñ €¬€œ½µµ¥ÑÑ•…Ñ•É¥¹œ©½‰Ìƒ
ÜÕÁ‘…Ñ•€œ€¬¹•Ü…Ñ” ¤¹Ñ½1½…±•Q¥µ•MÑÉ¥¹œ ¤ì($%ô($%…ÁÁ±å½¹¹•Ñ¥½¹MÑ…Ñ” ¤ì(%ô((%™Õ¹Ñ¥½¸É•¹‘•È¡½É‘•ÉÌ¤ì($%±…ÍÑ=É‘•ÉÌ€ô½É‘•ÉÌì($%‰½…É‘°¹Ñ•áÑ½¹Ñ•¹Ð€ô€œœì(($$¼¼A•ÉÍ¥ÍÑ•¹ÐÝ…É¹¥¹œ¥˜Í½Õ¹¥Í¸Ð•¹…‰±•ƒŠP„É•±½…‘•Ñ…‰±•ÐµÕÍÐ($$¼¼¹•Ù•ÈÍ¥ÐÍ¥±•¹ÐÑ¡É½Õ ¹•Ü½É‘•ÉÌ¸($%¥˜€¡MI9}5=€„ôô€Á…ÍÌœ€˜˜€……Õ‘¥¼¹½¸¤ì($$%‰½…É‘°¹…ÁÁ•¹‘¡¥±¡•° ‘¥Øœ°ì±…ÍÌè€‘ˆµÍ½Õ¹µÝ…É¸œô°l($$$$ŸÂ~RM½Õ¹¥Ì=ƒŠPÑ…ÀƒŠq¹…‰±”Í½Õ¹…±•ÉÑÏŠt€¡Ñ½ÀÉ¥¡Ð¤Í¼å½Ô‘½»ŠeÐµ¥ÍÌ¹•Ü½É‘•ÉÌ¸œ($$%t¤¤ì($%ô(($%Ù…ÈÕ¹…­•€ôMI9}5=€ôôô€Á…ÍÌœ€ümt€è½É‘•ÉÌ¹™¥±Ñ•È¡™Õ¹Ñ¥½¸€¡¼¤ì($$%É•ÑÕÉ¸¼¹ÍÑ…ÑÕÌ€ôôô€Á•¹‘¥¹œœ€˜˜€…¼¹…­¹½Ý±•‘•€˜˜€…±½…±­m¼¹¥‘tì($%ô¤ì(($%¥˜€¡Õ¹…­•¹±•¹Ñ ¤ì($$%Ù…È¥‘Ì€ôÕ¹…­•¹µ…À¡™Õ¹Ñ¥½¸€¡¼¤ìÉ•ÑÕÉ¸¼¹¥ìô¤ì($$%‰½…É‘°¹…ÁÁ•¹‘¡¥±¡•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ‰…¹¹•Èœô°l($$$%•° ÍÁ…¸œ°ìÑ•áÐèÕ¹…­•¹±•¹Ñ €¬€œ¹•Ü½É‘•Èœ€¬€¡Õ¹…­•¹±•¹Ñ €ø€Ä€ü€Ìœ€è€œœ¤€¬€œ„œô¤°($$$%•° ‰ÕÑÑ½¸œ°ì±…ÍÌè€‰ÕÑÑ½¸‰ÕÑÑ½¸µÁÉ¥µ…Éäœ°ÑåÁ”è€‰ÕÑÑ½¸œ°½¹±¥¬è™Õ¹Ñ¥½¸€ ¤ì…­¹½Ý±•‘•±°¡¥‘Ì¤ìôô°l­¹½Ý±•‘”t¤($$%t¤¤ì($$%ÍÑ…ÉÑ±•ÉÐ ¤ì($%ô•±Í”ì($$%ÍÑ½Á±•ÉÐ ¤ì($%ô(($$¼¼±°µ‘…äÍÑÉ¥ÀƒŠP…É•…Ñ”¥Ñ•´½Õ¹ÑÌ…É½ÍÌ¥¸µÁÉ½É•ÍÌ½É‘•ÉÌÍ¼Ñ¡”($$¼¼­¥Ñ¡•¸…¸‰…Ñ € ˆÛ\i……Ñ…Èƒ
Ü€Ï\±°5•…ÐƒŠ˜ˆ¤¸!¥‘‘•¸Ý¡•¸•µÁÑä¸($%Ù…ÈMQI%A}MQQUML€ôlÁ•¹‘¥¹œœ°€½¹™¥Éµ•œ°€ÁÉ•Á…É¥¹œœ°€‰…­¥¹œtì($%Ù…È½Õ¹ÑÌ€ôíôì($%¥˜€¡MI9}5=€„ôô€Á…ÍÌœ¤ì½É‘•ÉÌ¹™½É… ¡™Õ¹Ñ¥½¸€¡¼¤ì($$%¥˜€¡MQI%A}MQQUML¹¥¹‘•á=˜¡¼¹ÍÑ…ÑÕÌ¤€ôôô€´Ä¤ìÉ•ÑÕÉ¸ìô($$$¡¼¹¥Ñ•µÌñðmt¤¹™½É… ¡™Õ¹Ñ¥½¸€¡¥Ð¤ì($$$%Ù…È¹…µ”€ôMÑÉ¥¹œ¡¥Ð¹¹…µ”ñð€œœ¤ì($$$%¥˜€ …¹…µ”¤ìÉ•ÑÕÉ¸ìô($$$%½Õ¹ÑÍm¹…µ•t€ô€¡½Õ¹ÑÍm¹…µ•tñð€À¤€¬€¡Á…ÉÍ•%¹Ð¡¥Ð¹ÅÕ…¹Ñ¥Ñä°€ÄÀ¤ñð€Ä¤ì($$%ô¤ì($%ô¤ìô($%Ù…ÈÍÑÉ¥Á¹ÑÉ¥•Ì€ô=‰©•Ð¹­•åÌ¡½Õ¹ÑÌ¤¹µ…À¡™Õ¹Ñ¥½¸€¡¹…µ”¤ì($$%É•ÑÕÉ¸ì¹…µ”è¹…µ”°½Õ¹Ðè½Õ¹ÑÍm¹…µ•tôì($%ô¤¹Í½ÉÐ¡™Õ¹Ñ¥½¸€¡„°ˆ¤ìÉ•ÑÕÉ¸ˆ¹½Õ¹Ð€´„¹½Õ¹Ðìô¤¹Í±¥” À°€ÄÈ¤ì($%¥˜€¡ÍÑÉ¥Á¹ÑÉ¥•Ì¹±•¹Ñ ¤ì($$%‰½…É‘°¹…ÁÁ•¹‘¡¥±¡•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ…±±‘…äœô°($$$%m•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµ…±±‘…äµ±…‰•°œ°Ñ•áÐè€±°‘…äèœô¥t¹½¹…Ð ($$$$%ÍÑÉ¥Á¹ÑÉ¥•Ì¹µ…À¡™Õ¹Ñ¥½¸€¡”¤ì($$$$$%É•ÑÕÉ¸•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµ…±±‘…äµ¥Ñ•´œ°Ñ•áÐè”¹½Õ¹Ð€¬€Ÿ\€œ€¬”¹¹…µ”ô¤ì($$$$%ô¤($$$$¤¤¤ì($%ô(($%Ù…ÈÙ¥Í¥‰±•=É‘•ÉÌ€ô½É‘•ÉÌ¹™¥±Ñ•È¡™Õ¹Ñ¥½¸€¡¼¤ìÉ•ÑÕÉ¸€„…±…¹•=˜¡¼¹ÍÑ…ÑÕÌ¤ìô¤ì($%‰½…É‘°¹…ÁÁ•¹‘¡¥±¡É•¹‘•ÉAÕ±Í”¡Ù¥Í¥‰±•=É‘•ÉÌ¤¤ì($%¥˜€¡MI9}5=€„ôô€…Ñ•É¥¹œœ¤ì($$%Ù…ÈÑ…‰±•A…¹•°€ôÉ•¹‘•ÉQ…‰±•=ÕÁ…¹ä ¤ì($$%¥˜€¡Ñ…‰±•A…¹•°¤ì‰½…É‘°¹…ÁÁ•¹‘¡¥±¡Ñ…‰±•A…¹•°¤ìô($%ô($%Ù…È±…¹•Í]É…À€ô•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ±…¹•Ìœô°19L¹µ…À¡™Õ¹Ñ¥½¸€¡±…¹”¤ì($$%Ù…È±…¹•=É‘•ÉÌ€ô½É‘•ÉÌ¹™¥±Ñ•È¡™Õ¹Ñ¥½¸€¡¼¤ìÉ•ÑÕÉ¸±…¹•=˜¡¼¹ÍÑ…ÑÕÌ¤€ôôô±…¹”¹­•äìô¤ì($$%Ù…È…É‘Ì€ô±…¹•=É‘•ÉÌ¹±•¹Ñ ($$$$ü±…¹•=É‘•ÉÌ¹µ…À¡…É¤($$$$èm•° Àœ°ì±…ÍÌè€‘ˆµ±…¹”µ•µÁÑäœ°Ñ•áÐè€9½¹”œô¥tì($$%É•ÑÕÉ¸•° ‘¥Øœ°ì±…ÍÌè€‘ˆµ±…¹”‘ˆµ±…¹”´œ€¬±…¹”¹­•äô°l($$$%•°  Èœ°ì±…ÍÌè€‘ˆµ±…¹”µÑ¥Ñ±”œô°m±…¹”¹Ñ¥Ñ±”€¬€œ€œ°•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµ±…¹”µ½Õ¹Ðœ°Ñ•áÐèMÑÉ¥¹œ¡±…¹•=É‘•ÉÌ¹±•¹Ñ ¤ô¥t¤°($$$%•° Àœ°ì±…ÍÌè€‘ˆµ±…¹”µÍÕ‰Ñ¥Ñ±”œ°Ñ•áÐè±…¹•MÕ‰Ñ¥Ñ±”¡±…¹”¹­•ä¤ô¤($$%t¹½¹…Ð¡…É‘Ì¤¤ì($%ô¤¤ì($%‰½…É‘°¹…ÁÁ•¹‘¡¥±¡±…¹•Í]É…À¤ì(($%¥˜€¡ÍÑ…ÑÕÍ°¤ì($$%ÍÑ…ÑÕÍ°¹Ñ•áÑ½¹Ñ•¹Ð€ô½É‘•ÉÌ¹±•¹Ñ €¬€œ…Ñ¥Ù”ƒ
ÜÕÁ‘…Ñ•€œ€¬($$$%¹•Ü…Ñ” ¤¹Ñ½1½…±•Q¥µ•MÑÉ¥¹œ ¤ì($%ô($%…ÁÁ±å½¹¹•Ñ¥½¹MÑ…Ñ” ¤ì(%ô(($¼¨€´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´!•…ÉÑ‰•…Ð€¨¼(($¼¼Mµ…±°½¹¹•Ñ¥½¸‰…‘”¹•áÐÑ¼Ñ¡”‰½…ÉÍÑ…ÑÕÌèÉ••¸€‰1¥Ù”ˆ€¡MM¤°($¼¼…µ‰•È€‰A½±±¥¹œˆ€¡Á½±°=,¤°É•€‰=™™±¥¹”ˆ€¡±…ÍÐ±½…™…¥±•¤¸(%Ù…È½™™±¥¹”€ô™…±Í”ì(%Ù…È¡•…ÉÑ‰•…Ñ°€ô¹Õ±°ì((%™Õ¹Ñ¥½¸Íå¹Q¥µ•1…‰•° ¤ì($%É•ÑÕÉ¸±…ÍÑMÕ•ÍÍ™Õ±Må¹Œ€ü±…ÍÑMÕ•ÍÍ™Õ±Må¹Œ¹Ñ½1½…±•Q¥µ•MÑÉ¥¹œ ¤€è€¹•Ù•Èœì(%ô((%™Õ¹Ñ¥½¸…ÁÁ±å½¹¹•Ñ¥½¹MÑ…Ñ” ¤ì($%¥˜€¡‰½…É‘°¤ì($$%‰½…É‘°¹±…ÍÍ1¥ÍÐ¹Ñ½±” ‘ˆµ‰½…Éµ½™™±¥¹”œ°½™™±¥¹”¤ì($$%Ù…ÈµÕÑ…Ñ¥½¹	ÕÑÑ½¹Ì€ô‰½…É‘°¹ÅÕ•ÉåM•±•Ñ½É±° ‰ÕÑÑ½¸œ¤ì($$%™½È€¡Ù…È¤€ô€Àì¤€ðµÕÑ…Ñ¥½¹	ÕÑÑ½¹Ì¹±•¹Ñ ì¤¬¬¤ì($$$%Ù…È…É€ôµÕÑ…Ñ¥½¹	ÕÑÑ½¹Ím¥t¹±½Í•ÍÐ m‘…Ñ„µ½É‘•Èµ¥‘tœ¤ì($$$%Ù…ÈÑ…‰±•…É€ôµÕÑ…Ñ¥½¹	ÕÑÑ½¹Ím¥t¹±½Í•ÍÐ m‘…Ñ„µÑ…‰±”µ¥‘tœ¤ì($$$%µÕÑ…Ñ¥½¹	ÕÑÑ½¹Ím¥t¹‘¥Í…‰±•€ô½™™±¥¹”ñð€„„¡…É€˜˜¥¹±¥¡Ñm…É¹•ÑÑÑÉ¥‰ÕÑ” ‘…Ñ„µ½É‘•Èµ¥œ¥t¤ñð€„„¡Ñ…‰±•…É€˜˜Ñ…‰±•%¹±¥¡ÑmÑ…‰±•…É¹•ÑÑÑÉ¥‰ÕÑ” ‘…Ñ„µÑ…‰±”µ¥œ¥t¤ì($$%ô($%ô($%¥˜€¡ÁÉ•½É‘•ÉA…¹•°¤ì($$%Ù…ÈÁÉ•½É‘•É	ÕÑÑ½¹Ì€ôÁÉ•½É‘•ÉA…¹•°¹ÅÕ•ÉåM•±•Ñ½É±° ‰ÕÑÑ½¸œ¤ì($$%™½È€¡Ù…È¨€ô€Àì¨€ðÁÉ•½É‘•É	ÕÑÑ½¹Ì¹±•¹Ñ ì¨¬¬¤ì($$$%Ù…ÈÁÉ•½É‘•É…É‘°€ôÁÉ•½É‘•É	ÕÑÑ½¹Ím©t¹±½Í•ÍÐ m‘…Ñ„µÁÉ•½É‘•Èµ¥‘tœ¤ì($$$%Ù…ÈÁÉ•½É‘•É	ÕÍä€ôÁÉ•½É‘•É…É‘°€˜˜¥¹±¥¡ÑmÁÉ•½É‘•É…É‘°¹•ÑÑÑÉ¥‰ÕÑ” ‘…Ñ„µÁÉ•½É‘•Èµ¥œ¥tì($$$%Ù…È½¹Ñ…Ñ¡•¬€ôÁÉ•½É‘•É…É‘°€˜˜ÁÉ•½É‘•É…É‘°¹ÅÕ•ÉåM•±•Ñ½È œ¹‘ˆµÁÉ•½É‘•Èµ½¹Ñ…Ðµ¡•¬¥¹ÁÕÑmÑåÁ”ô‰¡•­‰½à‰tœ¤ì($$$%ÁÉ•½É‘•É	ÕÑÑ½¹Ím©t¹‘¥Í…‰±•€ô½™™±¥¹”ñð€„…ÁÉ•½É‘•É	ÕÍäñð($$$$$¡ÁÉ•½É‘•É	ÕÑÑ½¹Ím©t¹±…ÍÍ1¥ÍÐ¹½¹Ñ…¥¹Ì ‘ˆµÁÉ•½É‘•Èµ…•ÁÐœ¤€˜˜€ …½¹Ñ…Ñ¡•¬ñð€…½¹Ñ…Ñ¡•¬¹¡•­•¤¤ì($$%ô($%ô($%¥˜€¡É•ÑÉå	Ñ¸¤ìÉ•ÑÉå	Ñ¸¹¡¥‘‘•¸€ô€…½™™±¥¹”ìô($%¥˜€¡ÍÑ…ÑÕÍ°¤ì($$%ÍÑ…ÑÕÍ°¹Ñ•áÑ½¹Ñ•¹Ð€ô½™™±¥¹”($$$$ü€=™™±¥¹”€´Í¡½Ý¥¹œ½É‘•ÉÌ±…ÍÐÍå¹•€œ€¬Íå¹Q¥µ•1…‰•° ¤€¬€œ¸¡…¹•Ì…É”±½­•¸œ($$$$è±…ÍÑ=É‘•ÉÌ¹±•¹Ñ €¬€œ…Ñ¥Ù”€´Íå¹•€œ€¬Íå¹Q¥µ•1…‰•° ¤ì($%ô(%ô((%™Õ¹Ñ¥½¸ÕÁ‘…Ñ•!•…ÉÑ‰•…Ð ¤ì($%¥˜€ …¡•…ÉÑ‰•…Ñ°¤ìÉ•ÑÕÉ¸ìô($%Ù…ÈÍÑ…Ñ”€ô½™™±¥¹”€ü€½™™±¥¹”œ€è€¡ÍÍ•!•…±Ñ¡ä€ü€±¥Ù”œ€è€Á½±±¥¹œœ¤ì($%Ù…ÈÝ½É€ô½™™±¥¹”€ü€=™™±¥¹”œ€è€¡ÍÍ•!•…±Ñ¡ä€ü€1¥Ù”œ€è€A½±±¥¹œœ¤ì($%¡•…ÉÑ‰•…Ñ°¹±…ÍÍ9…µ”€ô€‘ˆµ¡•…ÉÑ‰•…Ð‘ˆµ¡•…ÉÑ‰•…Ð´œ€¬ÍÑ…Ñ”ì($%¡•…ÉÑ‰•…Ñ°¹Ñ•áÑ½¹Ñ•¹Ð€ô€œœì($%¡•…ÉÑ‰•…Ñ°¹…ÁÁ•¹‘¡¥±¡•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµ¡•…ÉÑ‰•…Ðµ‘½Ðœ°€…É¥„µ¡¥‘‘•¸œè€ÑÉÕ”œô¤¤ì($%¡•…ÉÑ‰•…Ñ°¹…ÁÁ•¹‘¡¥±¡•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµ¡•…ÉÑ‰•…ÐµÝ½Éœ°Ñ•áÐèÝ½Éô¤¤ì(%ô(($¼¨€´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´å±”€¨¼((%™Õ¹Ñ¥½¸±½… ¤ì($%Ù…ÈÉ•ÅÕ•ÍÑÁ½ €ô€¬­±½…‘Á½ ì($%±½…‘MÕµµ…Éä ¤ì($$¼¼Q…‰±”½ÕÁ…¹ä¥ÌÕÍ•™Õ°½Á•É…Ñ¥½¹…°½¹Ñ•áÐ°¹½Ð„‘•Á•¹‘•¹ä½˜Ñ¡”($$¼¼ÁÉ½‘ÕÑ¥½¸™••¸‘•±…å•Á…¹•°µÕÍÐ¹•Ù•È¡½±‰…¬±¥Ù”½É‘•ÉÌ¸($%±½…‘Q…‰±•=ÕÁ…¹ä ¤¹Ñ¡•¸¡™Õ¹Ñ¥½¸€ ¤ì($$%¥˜€¡É•ÅÕ•ÍÑÁ½ €ôôô±½…‘Á½ €˜˜‰½…É‘°€˜˜€…½™™±¥¹”¤ìÑ…‰±•A…¹•±A½Í¥Ñ¥½¸ ¤ìô($%ô¤ì($%Ù…ÈÁ…Ñ €ôÍ½Á•‘A…Ñ ¡MI9}5=€ôôô€…Ñ•É¥¹œœ€ü€œ½…‘µ¥¸½…Ñ•É¥¹œµ‰½…Éœ€è€œ½…‘µ¥¸½½É‘•ÉÌœ¤ì($%É•ÑÕÉ¸…Á¤¡Á…Ñ °€Pœ¤¹Ñ¡•¸¡™Õ¹Ñ¥½¸€¡É•Ì¤ì($$%¥˜€ …É•Ìñð€…ÉÉ…ä¹¥ÍÉÉ…ä¡É•Ì¹‘…Ñ„¤¤ìÑ¡É½Ü¹•ÜÉÉ½È Q¡”ÁÉ½‘ÕÑ¥½¸™••É•ÑÕÉ¹•…¸¥¹Ù…±¥É•ÍÁ½¹Í”¸œ¤ìô($$%¥˜€¡É•ÅÕ•ÍÑÁ½ €„ôô±½…‘Á½ ¤ìÉ•ÑÕÉ¸¹Õ±°ìô($$%½™™±¥¹”€ô™…±Í”ì($$%±…ÍÑMÕ•ÍÍ™Õ±Må¹Œ€ô¹•Ü…Ñ” ¤ì($$%ÕÁ‘…Ñ•!•…ÉÑ‰•…Ð ¤ì($$%¥˜€¡MI9}5=€ôôô€…Ñ•É¥¹œœ¤ì($$$%É•¹‘•É…Ñ•É¥¹œ¡É•Ì¹‘…Ñ„¤ì($$$%¥˜€¡ÁÉ•½É‘•ÉA…¹•°¤ìÁÉ•½É‘•ÉA…¹•°¹¡¥‘‘•¸€ôÑÉÕ”ìô($$$%É•ÑÕÉ¸¹Õ±°ì($$%ô($$%É•¹‘•È¡É•Ì¹‘…Ñ„¤ì($$%¥˜€¡MI9}5=€„ôô€Á…ÍÌœ¤ì($$$%¥˜€¡ÁÉ•½É‘•ÉA…¹•°¤ìÁÉ•½É‘•ÉA…¹•°¹¡¥‘‘•¸€ôÑÉÕ”ìô($$$%É•ÑÕÉ¸¹Õ±°ì($$%ô($$%Ù…ÈÁÉ•½É‘•ÉA…Ñ €ô€œ½…‘µ¥¸½ÁÉ•½É‘•ÈµÉ•ÅÕ•ÍÑÌýÁ•É}Á…”ôÄÀÀœ€¬€¡ÕÉÉ•¹Ñ1½…Ñ¥½¸€ü€œ™±½…Ñ¥½¹}¥ôœ€¬ÕÉÉ•¹Ñ1½…Ñ¥½¸€è€œœ¤ì($$%É•ÑÕÉ¸…Á¤¡ÁÉ•½É‘•ÉA…Ñ °€Pœ¤¹Ñ¡•¸¡™Õ¹Ñ¥½¸€¡ÁÉ•½É‘•ÉÌ¤ì($$$%¥˜€ …ÁÉ•½É‘•ÉÌñð€…ÉÉ…ä¹¥ÍÉÉ…ä¡ÁÉ•½É‘•ÉÌ¹‘…Ñ„¤¤ìÑ¡É½Ü¹•ÜÉÉ½È Q¡”ÁÉ”µ½É‘•ÈÉ•Ù¥•Ü™••É•ÑÕÉ¹•…¸¥¹Ù…±¥É•ÍÁ½¹Í”¸œ¤ìô($$$%¥˜€¡É•ÅÕ•ÍÑÁ½ €„ôô±½…‘Á½ ¤ìÉ•ÑÕÉ¸ìô($$$%É•¹‘•ÉAÉ•½É‘•ÉÌ¡ÁÉ•½É‘•ÉÌ¹‘…Ñ„¤ì($$%ô¤¹…Ñ ¡™Õ¹Ñ¥½¸€¡•ÉÉ½È¤ì($$$$¼¼É•Ù¥•Üµ™••™…¥±ÕÉ”µÕÍÐ¹•Ù•È‰±…¹¬½È±½¬Ñ¡”±¥Ù”­¥Ñ¡•¸‰½…É¸($$$%¥˜€¡ÁÉ•½É‘•ÉA…¹•°¤ìÁÉ•½É‘•ÉA…¹•°¹¡¥‘‘•¸€ôÑÉÕ”ìô($$$%¥˜€¡ÍÑ…ÑÕÍ°¤ìÍÑ…ÑÕÍ°¹Ñ•áÑ½¹Ñ•¹Ð€ô€-¥Ñ¡•¸‰½…ÉÍå¹•ìÁÉ”µ½É‘•ÈÉ•Ù¥•ÜÕ¹…Ù…¥±…‰±”è€œ€¬€¡•ÉÉ½È¹µ•ÍÍ…”ñð€É•ÑÉå¥¹œœ¤ìô($$%ô¤ì($%ô¤¹…Ñ ¡™Õ¹Ñ¥½¸€ ¤ì($$%¥˜€¡É•ÅÕ•ÍÑÁ½ €„ôô±½…‘Á½ ¤ìÉ•ÑÕÉ¸ìô($$%½™™±¥¹”€ôÑÉÕ”ì($$%ÕÁ‘…Ñ•!•…ÉÑ‰•…Ð ¤ì($$$¼¼AÉ•Í•ÉÙ”Ñ¡”½¹¹•Ñ¥½¸Ý…É¹¥¹œ‰•±½Ü°Ñ¡•¸É•Á±…”¥ÐÝ¥Ñ Ñ¡”($$$¼¼…Ñ¥½¹…‰±”½™™±¥¹”ÍÑ…Ñ”…™Ñ•ÈÑ¡¥Ì•ÉÉ½È…±±‰…¬½µÁ±•Ñ•Ì¸($$%Í•ÑQ¥µ•½ÕÐ¡…ÁÁ±å½¹¹•Ñ¥½¹MÑ…Ñ”°€À¤ì($$%¥˜€¡ÍÑ…ÑÕÍ°¤ìÍÑ…ÑÕÍ°¹Ñ•áÑ½¹Ñ•¹Ð€ô€½¹¹•Ñ¥½¸ÁÉ½‰±•´ƒŠPÉ•ÑÉå¥¹ŸŠ˜œìô($%ô¤ì(%ô((%™Õ¹Ñ¥½¸±½½À ¤ì($%±½… ¤¹Ñ¡•¸¡™Õ¹Ñ¥½¸€ ¤ì($$%Á½±±Q¥µ•È€ôÍ•ÑQ¥µ•½ÕÐ¡±½½À°MI9}5=€ôôô€…Ñ•É¥¹œœ€üA=11}MP€è€¡ÍÍ•!•…±Ñ¡ä€üA=11}MQd€èA=11}MP¤¤ì($%ô¤ì(%ô(($¼¨€´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´5•ÉÕÉ”MM€¨¼(($¼¼=Á•¸…¸Ù•¹ÑM½ÕÉ”Ñ¼Ñ¡”5•ÉÕÉ”¡Õˆ¸=¸…¹äµ•ÍÍ…”°É”µÁÕ±°Ñ¡”($¼¼…ÕÑ¡½É¥Ñ…Ñ¥Ù”‰½…É€¡Ñ¡”MMÁ…å±½…¥Ì½¹±ä„€‰É•™É•Í ˆÍ¥¹…°°¹•Ù•È($¼¼ÑÉÕÍÑ•‘…Ñ„¤¸=¸•ÉÉ½È°™…±°‰…¬Ñ¼Ñ¡”¹½Éµ…°Á½±°…‘•¹”¸Q¡”Á½±°($¼¼…±Ý…åÌ­••ÁÌÉÕ¹¹¥¹œ…ÌÑ¡”™…±±‰…¬Á…Ñ ¸(%™Õ¹Ñ¥½¸½¹¹•ÑMÍ” ¤ì($%¥˜€¡MI9}5=€ôôô€…Ñ•É¥¹œœñð€…µ•ÉÕÉ”ñð€…µ•ÉÕÉ”¹•¹…‰±•ñð€…µ•ÉÕÉ”¹ÕÉ°ñðÑåÁ•½˜Ý¥¹‘½Ü¹Ù•¹ÑM½ÕÉ”€ôôô€Õ¹‘•™¥¹•œ¤ì($$%É•ÑÕÉ¸ì($%ô(($%Ù…ÈÕÉ°€ôµ•ÉÕÉ”¹ÕÉ°€¬€œýÑ½Á¥Œôœ€¬•¹½‘•UI%½µÁ½¹•¹Ð¡µ•ÉÕÉ”¹Ñ½Á¥Œ¤ì($$¼¼Q¡”‰½…ÉÑ½Á¥Œ¥ÌÁÕ‰±¥±äÉ•…‘…‰±”°Í¼¹½Éµ…±±ä¹¼Ñ½­•¸¥Ì¹••‘•½¸($$¼¼ÍÕ‰ÍÉ¥‰”¸Ù•¹ÑM½ÕÉ”…¹¹½ÐÍ•¹ÕÑ¡½É¥é…Ñ¥½¸¡•…‘•ÉÌì½¹±ä™…±°‰…¬($$¼¼Ñ¼Ñ¡”€¡UI0¤…ÕÑ¡½É¥é…Ñ¥½¸Á…É…´Ý¡•¸„ÍÕ‰ÍÉ¥‰”)]P¥Ì…ÑÕ…±±äÍ•Ð¸($%¥˜€¡µ•ÉÕÉ”¹ÍÕ‰ÍÉ¥‰•}©ÝÐ¤ì($$%ÕÉ°€¬ô€œ™…ÕÑ¡½É¥é…Ñ¥½¸ôœ€¬•¹½‘•UI%½µÁ½¹•¹Ð¡µ•ÉÕÉ”¹ÍÕ‰ÍÉ¥‰•}©ÝÐ¤ì($%ô(($%ÑÉäì($$%ÍÍ”€ô¹•ÜÙ•¹ÑM½ÕÉ”¡ÕÉ°¤ì($%ô…Ñ €¡”¤ì($$%É•ÑÕÉ¸ì($%ô(($%ÍÍ”¹½¹½Á•¸€ô™Õ¹Ñ¥½¸€ ¤ì($$%ÍÍ•!•…±Ñ¡ä€ôÑÉÕ”ì($$%ÕÁ‘…Ñ•!•…ÉÑ‰•…Ð ¤ì($%ôì(($%ÍÍ”¹½¹µ•ÍÍ…”€ô™Õ¹Ñ¥½¸€ ¤ì($$$¼¼µ•ÍÍ…”µ•…¹ÌÑ¡”¡…¹¹•°¥Ì…±¥Ù”ƒŠPÉ”µ…™™¥É´¡•…±Ñ Í¼„É•½Ù•Éä($$$¼¼…™Ñ•È„ÑÉ…¹Í¥•¹Ð•ÉÉ½ÈÍ±½ÝÌÑ¡”Á½±°……¥¸•Ù•¸‰•™½É”½¹½Á•¸É•™¥É•Ì¸($$%ÍÍ•!•…±Ñ¡ä€ôÑÉÕ”ì($$%ÕÁ‘…Ñ•!•…ÉÑ‰•…Ð ¤ì($$$¼¼ÕÑ¡½É¥Ñ…Ñ¥Ù”É”µ™•Ñ ƒŠP¹•Ù•ÈÉ•¹‘•È™É½´Ñ¡”MMÁ…å±½…¥ÑÍ•±˜¸($$%±½… ¤ì($%ôì(($%ÍÍ”¹½¹•ÉÉ½È€ô™Õ¹Ñ¥½¸€ ¤ì($$$¼¼É½À‰…¬Ñ¼™…ÍÐÁ½±±¥¹œìÑ¡”‰É½ÝÍ•È…ÕÑ¼µÉ•½¹¹•ÑÌÑ¡”MM°…¹($$$¼¼½¹½Á•¸Ý¥±°Í±½ÜÑ¡”Á½±°……¥¸½¹”¥ÐÉ•½Ù•ÉÌ¸($$%ÍÍ•!•…±Ñ¡ä€ô™…±Í”ì($$%ÕÁ‘…Ñ•!•…ÉÑ‰•…Ð ¤ì($%ôì(%ô((%¥˜€¡Í½Õ¹‘	Ñ¸¤ì($%Í½Õ¹‘	Ñ¸¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È ±¥¬œ°•¹…‰±•M½Õ¹¤ì(%ô(($¼¼%˜Ñ¡”Ñ…‰±•ÐÍ±••ÁÌ½É•™½ÕÍ•Ì°Ñ¡”…Õ‘¥¼½¹Ñ•áÐ…¸ÍÕÍÁ•¹ƒŠPÉ•ÍÕµ”¥Ð($¼¼Í¼Ñ¡”¡¥µ”­••ÁÌÝ½É­¥¹œÝ¥Ñ¡½ÕÐ„™É•Í Ñ…À¸(%‘½Õµ•¹Ð¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È Ù¥Í¥‰¥±¥Ñå¡…¹”œ°™Õ¹Ñ¥½¸€ ¤ì($%¥˜€ …‘½Õµ•¹Ð¹¡¥‘‘•¸€˜˜…Õ‘¥¼¹½¸€˜˜…Õ‘¥¼¹Ñà€˜˜…Õ‘¥¼¹Ñà¹ÍÑ…Ñ”€ôôô€ÍÕÍÁ•¹‘•œ¤ì($$%…Õ‘¥¼¹Ñà¹É•ÍÕµ” ¤ì($%ô(%ô¤ì(%Ý¥¹‘½Ü¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È ½™™±¥¹”œ°™Õ¹Ñ¥½¸€ ¤ì($%½™™±¥¹”€ôÑÉÕ”ì($%ÕÁ‘…Ñ•!•…ÉÑ‰•…Ð ¤ì($%…ÁÁ±å½¹¹•Ñ¥½¹MÑ…Ñ” ¤ì(%ô¤ì(%Ý¥¹‘½Ü¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È ½¹±¥¹”œ°™Õ¹Ñ¥½¸€ ¤ì($%¥˜€¡Á½±±Q¥µ•È¤ì±•…ÉQ¥µ•½ÕÐ¡Á½±±Q¥µ•È¤ìÁ½±±Q¥µ•È€ô¹Õ±°ìô($%±½… ¤ì(%ô¤ì(%Ý¥¹‘½Ü¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È ‰•™½É•Õ¹±½…œ°™Õ¹Ñ¥½¸€ ¤ì($%¥˜€¡ÍÕµµ…ÉåQ¥µ•È¤ì±•…É%¹Ñ•ÉÙ…°¡ÍÕµµ…ÉåQ¥µ•È¤ìô($%¥˜€¡ÍÕµµ…Éå‰½ÉÐ€˜˜ÍÕµµ…Éå‰½ÉÐ¹…‰½ÉÐ¤ìÍÕµµ…Éå‰½ÉÐ¹…‰½ÉÐ ¤ìô($%¥˜€¡Ñ…‰±•Í‰½ÉÐ€˜˜Ñ…‰±•Í‰½ÉÐ¹…‰½ÉÐ¤ìÑ…‰±•Í‰½ÉÐ¹…‰½ÉÐ ¤ìô(%ô¤ì(%‘½Õµ•¹Ð¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È ­•å‘½Ý¸œ°™Õ¹Ñ¥½¸€¡•Ù•¹Ð¤ì($%¥˜€¡•Ù•¹Ð¹­•ä€ôôô€Í…Á”œ€˜˜…µ•¹‘µ•¹ÑA…¹•°€˜˜€……µ•¹‘µ•¹ÑA…¹•°¹¡¥‘‘•¸¤ì($$%±½Í•µ•¹‘µ•¹ÑI•Ù¥•Ü ¤ì($%ô(%ô¤ì(($¼¼M¡½À™¥±Ñ•ÈƒŠP½¹±äÍ¡½Ý¸Ý¡•¸µ½É”Ñ¡…¸½¹”Í¡½À•á¥ÍÑÌ¸(%¥˜€¡…Ñ¥½¹Í°€˜˜1=Q%=9L¹±•¹Ñ €ø€Ä¤ì($%Ù…ÈÍ•°€ô•° Í•±•Ðœ°ì±…ÍÌè€‘ˆµÍ¡½ÀµÍ•±•Ðœ°€…É¥„µ±…‰•°œè€¥±Ñ•È‰äÍ¡½Àœô°mt¤ì($%Í•°¹…ÁÁ•¹‘¡¥±¡•° ½ÁÑ¥½¸œ°ìÙ…±Õ”è€œÀœ°Ñ•áÐè€±°Í¡½ÁÌœô¤¤ì($%1=Q%=9L¹™½É… ¡™Õ¹Ñ¥½¸€¡°¤ì($$%Í•°¹…ÁÁ•¹‘¡¥±¡•° ½ÁÑ¥½¸œ°ìÙ…±Õ”èMÑÉ¥¹œ¡°¹¥¤°Ñ•áÐè°¹¹…µ”ô¤¤ì($%ô¤ì($%Í•°¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È ¡…¹”œ°™Õ¹Ñ¥½¸€ ¤ì($$%ÕÉÉ•¹Ñ1½…Ñ¥½¸€ôÁ…ÉÍ•%¹Ð¡Í•°¹Ù…±Õ”°€ÄÀ¤ñð€Àì($$%±½… ¤ì($%ô¤ì($%…Ñ¥½¹Í°¹¥¹Í•ÉÑ	•™½É”¡Í•°°…Ñ¥½¹Í°¹™¥ÉÍÑ¡¥±¤ì(%ô(($¼¼!•…ÉÑ‰•…Ð‰…‘”ƒŠP¹•áÐÑ¼Ñ¡”•á¥ÍÑ¥¹œ‰½…ÉÍÑ…ÑÕÌÑ•áÐ¸(%¥˜€¡ÍÑ…ÑÕÍ°¤ì($%¡•…ÉÑ‰•…Ñ°€ô•° ÍÁ…¸œ°ì±…ÍÌè€‘ˆµ¡•…ÉÑ‰•…Ð‘ˆµ¡•…ÉÑ‰•…ÐµÁ½±±¥¹œœô°mt¤ì($%ÍÑ…ÑÕÍ°¹Á…É•¹Ñ9½‘”¹¥¹Í•ÉÑ	•™½É”¡¡•…ÉÑ‰•…Ñ°°ÍÑ…ÑÕÍ°¹¹•áÑM¥‰±¥¹œ¤ì($%¥˜€¡…Ñ¥½¹Í°¤ì($$%É•ÑÉå	Ñ¸€ô•° ‰ÕÑÑ½¸œ°ì($$$%±…ÍÌè€‰ÕÑÑ½¸‘ˆµ‰½…ÉµÉ•ÑÉäœ°ÑåÁ”è€‰ÕÑÑ½¸œ°¡¥‘‘•¸è€¡¥‘‘•¸œ°($$$$…É¥„µ±…‰•°œè€I•ÑÉä±½…‘¥¹œÑ¡”±¥Ù”½É‘•È‰½…É¹½Üœ°($$$%½¹±¥¬è™Õ¹Ñ¥½¸€ ¤ì±½… ¤ìô($$%ô°lI•ÑÉä¹½Üt¤ì($$%…Ñ¥½¹Í°¹¥¹Í•ÉÑ	•™½É”¡É•ÑÉå	Ñ¸°ÍÑ…ÑÕÍ°¹¹•áÑM¥‰±¥¹œ¤ì($%ô($%ÕÁ‘…Ñ•!•…ÉÑ‰•…Ð ¤ì($%…ÁÁ±å½¹¹•Ñ¥½¹MÑ…Ñ” ¤ì(%ô(($¼¼Q¡”-Lµ…äÉ•Ù¥•ÜÑ¡”ÁÉ•¥Í”ÕÉÉ•¹Ð±¥¹”±¥ÍÐ°‰ÕÐ¥ÐµÕÍÐ¹•Ù•È‰•½µ”($¼¼„‰…¬‘½½È™½ÈÉ•ÁÉ¥¥¹œ½ÈÉ•™Õ¹‘¥¹œ…¸½É‘•È¸Q¡”Á…¹•°¥ÌÉ•…Ñ•½¹”($¼¼½ÕÑÍ¥‘”Ñ¡”É•™É•Í¡•‰½…ÉÍ¼™½ÕÌ…¹Ñ¡”É•Ù¥•ÜÉ•µ…¥¸ÍÑ…‰±”¸(%¥˜€¡‰½…É‘°€˜˜‰½…É‘°¹Á…É•¹Ñ9½‘”¤ì($%…µ•¹‘µ•¹ÑA…¹•°€ô•° Í•Ñ¥½¸œ°ì($$%±…ÍÌè€‘ˆµ…µ•¹‘µ•¹ÐµÁ…¹•°œ°($$%Ñ…‰¥¹‘•àè€œ´Äœ°($$$…É¥„µ±…‰•°œè€=É‘•È¡…¹”É•Ù¥•Üœ($%ô°mt¤ì($%…µ•¹‘µ•¹ÑA…¹•°¹¡¥‘‘•¸€ôÑÉÕ”ì($%‰½…É‘°¹Á…É•¹Ñ9½‘”¹¥¹Í•ÉÑ	•™½É”¡…µ•¹‘µ•¹ÑA…¹•°°‰½…É‘°¤ì(%ô(($¼¼I•™É•Í €‰à…¼ˆ±…‰•±Ì•Ù•¸‰•ÑÝ••¸Á½±±Ì¸(%Í•Ñ%¹Ñ•ÉÙ…°¡™Õ¹Ñ¥½¸€ ¤ì($%¥˜€ …±…ÍÑ=É‘•ÉÌ¹±•¹Ñ ¤ìÉ•ÑÕÉ¸ìô($%¥˜€¡MI9}5=€ôôô€…Ñ•É¥¹œœ¤ìÉ•¹‘•É…Ñ•É¥¹œ¡±…ÍÑ=É‘•ÉÌ¤ìô($%•±Í”ìÉ•¹‘•È¡±…ÍÑ=É‘•ÉÌ¤ìô(%ô°€ÌÀÀÀÀ¤ì(($¼¼=Á•¸Ñ¡”É•…°µÑ¥µ”MM¡…¹¹•°Ý¡•¸½¹™¥ÕÉ•ìÑ¡”Á½±°‰•±½ÜÍÑ…åÌ…ÌÑ¡”($¼¼…±Ý…åÌµ½¸™…±±‰…¬É•…É‘±•ÍÌ¸(%½¹¹•ÑMÍ” ¤ì(%ÍÕµµ…ÉåQ¥µ•È€ôÍ•Ñ%¹Ñ•ÉÙ…°¡±½…‘MÕµµ…Éä°A=11}MP¤ì((%±½½À ¤ì)ô ¤¤ì(