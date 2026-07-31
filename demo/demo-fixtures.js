(function (root) {
	'use strict';

	function build(nowValue, runtimeValue) {
		var runtime = runtimeValue || root.DBDemo;
		var now = nowValue ? new Date(nowValue) : new Date();
		var locations = runtime.activeLocations();
		var seq = 1042;
		function ref() { seq += 1; return runtime.config.brand.orderReferencePrefix + '-' + seq; }
		function at(dayOffset, hour, minute) { var d = new Date(now); d.setDate(d.getDate() + dayOffset); d.setHours(hour, minute || 0, 0, 0); return d; }
		function route(index) {
			var location = locations[index % locations.length];
			var methods = runtime.enabledFulfilments(location.id);
			var fulfilment = methods[Math.floor(index / locations.length) % methods.length];
			return { locationId: location.id, store: location.name, fulfilment: fulfilment, type: runtime.t('fulfilment.' + fulfilment) };
		}
		function online(index, who, items, total, status, placed, scheduled) {
			var routing = route(index);
			return Object.assign({ ref: ref(), channel: 'online', who: who, items: items, total: total, status: status, placed: placed }, routing, scheduled ? { scheduled: scheduled } : {});
		}
		function catering(index, who, items, total, deposit, status, placed, scheduled, pax, pkg) {
			var location = locations[index % locations.length];
			return { ref: ref(), channel: 'catering', locationId: location.id, store: location.name, fulfilment: 'pickup', type: 'Catering', who: who, items: items, total: total, deposit: deposit, status: status, placed: placed, scheduled: scheduled, pax: pax, pkg: pkg };
		}

		return [
			online(0, 'Layla M.', ['2× Zaatar', '1× Cheese', '1× Soft drink 600ml'], 27.50, 'new', at(0, now.getHours(), Math.max(0, now.getMinutes() - 2))),
			online(1, 'Sam K.', ['1× All Meat', '1× Garlic Prawns', '2× Spring water'], 41.00, 'new', at(0, now.getHours(), Math.max(0, now.getMinutes() - 6))),
			online(2, 'Mariam H.', ['3× Zaatar & Cheese', '1× Choc banana'], 33.50, 'preparing', at(0, Math.max(0, now.getHours() - 1), 12)),
			online(3, 'Joe T.', ['1× Chicken Delight', '1× BBQ Chicken', '1× Juice'], 29.00, 'preparing', at(0, Math.max(0, now.getHours() - 1), 38)),
			online(4, 'Nadia A.', ['2× Veggie Plus', '1× Fattoush', '1× Ayran'], 36.50, 'ready', at(0, Math.max(0, now.getHours() - 1), 5)),
			online(5, 'Omar S.', ['2× Lahm bi ajeen', '1× Lentil soup'], 24.00, 'completed', at(0, 9, 14)),
			online(6, 'Priya R.', ['1× Margherita', '1× Garlic bread'], 28.50, 'completed', at(0, 11, 2)),
			online(7, 'Hassan D.', ['4× Mixed manoush', '2× Soft drinks'], 46.00, 'new', at(0, Math.max(0, now.getHours() - 2), 0)),
			online(8, 'Office — Lvl 3', ['6× Assorted manoush', '3× Salads', '6× Drinks'], 92.00, 'new', at(0, now.getHours(), Math.max(0, now.getMinutes() - 12))),
			catering(0, 'Sample School', ['Lunch run — 60 pax', 'Mixed manoush platters ×6', 'Fruit & juice'], 540, 150, 'confirmed', at(-3, 10, 0), at(2, 11, 30), 60, 'School Lunch Run'),
			catering(0, 'Smith Wedding', ['Grazing + hot manoush — 120 pax', 'Dessert platters', 'Service staff ×2'], 1980, 500, 'confirmed', at(-6, 13, 0), at(9, 17, 0), 120, 'Premium Event'),
			catering(0, 'Sample Corporate', ['Corporate breakfast — ~40 pax', 'Manoush + coffee cart'], 0, 0, 'enquiry', at(0, Math.max(0, now.getHours() - 3), 20), null, 40, 'Corporate Breakfast'),
			catering(0, 'Brown Family', ['Engagement — ~80 pax', 'Mixed platters + dessert'], 0, 0, 'enquiry', at(-1, 19, 5), null, 80, 'Event (TBC)'),
			online(9, 'Karim B.', ['2× Zaatar', '1× Cheese & tomato'], 22, 'completed', at(-1, 12, 30)),
			online(10, 'Sophie L.', ['1× All Meat', '1× Veggie Plus'], 31, 'completed', at(-1, 18, 12)),
			online(11, 'Daniel P.', ['1× Chicken Delight'], 14, 'cancelled', at(-2, 13, 47)),
			catering(0, 'Sample Sports Club', ['Function — 90 pax', 'Mixed manoush + salads'], 1260, 350, 'completed', at(-12, 10, 0), at(-4, 18, 0), 90, 'Function'),
			online(12, 'Yusra M.', ['2× Margherita', '1× Garlic bread', '1× Soft drink'], 39.50, 'completed', at(-2, 19, 22))
		];
	}

	root.DBDemoFixtures = { build: build };
}(typeof globalThis !== 'undefined' ? globalThis : this));
