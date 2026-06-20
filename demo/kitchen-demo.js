/* Dough Boss — Live Order Board (kitchen) demo. New -> Preparing -> Ready. */
(function () {
	'use strict';
	var mount = document.getElementById('db-kitchen-demo');
	if (!mount) { return; }
	var SAMPLES = [
		{ name: 'Layla', type: 'Pickup', shop: 'Bankstown', items: ['2× Zaatar', '1× Cheese', '1× Hummus'] },
		{ name: 'Sam', type: 'Delivery', shop: 'Revesby', items: ['1× Lahm bi Ajin', '6× Falafel', '2× Soft Drink'] },
		{ name: 'Mariam', type: 'Pickup', shop: 'Roselands', items: ['3× Zaatar & Cheese', '1× Labneh'] },
		{ name: 'Office order', type: 'Delivery', shop: 'Bankstown', items: ['10× Mixed Manoush', '4× Hummus', 'Catering — Lunch Run'] },
		{ name: 'Joe', type: 'Pickup', shop: 'Revesby', items: ['1× Kibbeh', '1× Chips', '1× Ayran'] }
	];
	var LANES = [ ['new', 'New'], ['preparing', 'Preparing'], ['ready', 'Ready'] ];
	var seq = 1043;
	var orders = [];
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function newOrder() {
		var s = SAMPLES[Math.floor(Math.random() * SAMPLES.length)];
		orders.unshift({ id: seq++, ref: 'DB-' + seq, name: s.name, type: s.type, shop: s.shop, items: s.items, status: 'new', t: Date.now() });
	}
	function advance(id) {
		for (var i = 0; i < orders.length; i++) {
			if (orders[i].id === id) {
				if (orders[i].status === 'new') { orders[i].status = 'preparing'; }
				else if (orders[i].status === 'preparing') { orders[i].status = 'ready'; }
				else { orders.splice(i, 1); }
				break;
			}
		}
		render();
	}
	function mins(t) { return Math.max(0, Math.round((Date.now() - t) / 60000)); }
	function card(o) {
		var btn = o.status === 'new' ? 'Accept' : (o.status === 'preparing' ? 'Mark ready' : 'Complete');
		return '<div class="dbk-card dbk-' + o.status + '"><div class="dbk-card-top"><strong>#' + esc(o.ref) + '</strong><span>' + mins(o.t) + 'm</span></div><div class="dbk-meta">' + esc(o.name) + ' · ' + esc(o.type) + ' · ' + esc(o.shop) + '</div><ul class="dbk-items">' + o.items.map(function (i) { return '<li>' + esc(i) + '</li>'; }).join('') + '</ul><button type="button" class="dbk-adv" data-adv="' + o.id + '">' + btn + '</button></div>';
	}
	function render() {
		mount.innerHTML = '<div class="dbk-bar"><button type="button" class="vb-btn vb-btn-ember dbk-new">+ Simulate new order</button><span class="dbk-count">' + orders.length + ' active</span></div><div class="dbk-board">' +
			LANES.map(function (l) {
				var list = orders.filter(function (o) { return o.status === l[0]; }).map(card).join('') || '<p class="dbk-empty">—</p>';
				return '<div class="dbk-lane"><div class="dbk-lane-h">' + l[1] + '</div>' + list + '</div>';
			}).join('') + '</div>';
	}
	mount.addEventListener('click', function (e) {
		if (e.target.classList.contains('dbk-new')) { newOrder(); render(); return; }
		var a = e.target.closest('[data-adv]');
		if (a) { advance(parseInt(a.getAttribute('data-adv'), 10)); }
	});
	newOrder(); newOrder(); orders[1].status = 'preparing'; newOrder(); orders[2].status = 'ready';
	render();
	setInterval(function () { var v = document.getElementById('view-kitchen'); if (v && v.classList.contains('active') && mount.querySelector('.dbk-board')) { render(); } }, 30000);
}());
