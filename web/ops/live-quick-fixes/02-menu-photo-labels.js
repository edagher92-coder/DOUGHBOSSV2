/*
 * Dough Boss quick fix 2: give menu card photos an accessible name.
 *
 * The menu and order pages draw each photo as a CSS background on <div class="db-card-img">, so the
 * photo has no accessible name at all. This adds role="img" and aria-label="<item name>, photo" to
 * each card photo.
 *
 * The item title is the first heading in the card body (h4 on the live 2.41.0 build, h3 in later builds).
 *
 * It does NOT label a photo that appears on more than one card on the page (for example the single
 * juice photo currently shown for 11 different drinks): labelling those as the item would say
 * something untrue. Those photos are hidden from assistive technology instead, and pick up labels
 * automatically once each item has its own photo. Placeholder tiles are left alone.
 *
 * ES5 only (the site's convention). Cards are built by JavaScript after the menu loads, so this
 * watches the menu container for new cards.
 *
 * Where to put it: WPCode > Add Snippet > "Add Your Custom Code", type JavaScript, location
 * "Site Wide Footer". Undo: deactivate the snippet.
 */
(function () {
	'use strict';

	function urlOf(node) {
		var bg = node.style && node.style.backgroundImage ? node.style.backgroundImage : '';
		var m = /url\(\s*["']?([^"')]+)["']?\s*\)/i.exec(bg);
		return m ? m[1] : '';
	}

	function label() {
		var nodes = document.querySelectorAll('.db-card-img');
		var counts = {};
		var i;
		for (i = 0; i < nodes.length; i++) {
			var u = urlOf(nodes[i]);
			if (u) { counts[u] = (counts[u] || 0) + 1; }
		}
		for (i = 0; i < nodes.length; i++) {
			var node = nodes[i];
			var url = urlOf(node);
			if (!url || (node.className || '').indexOf('db-card-img--placeholder') !== -1) { continue; }
			var card = node.parentNode;
			var h = card && card.querySelector ? card.querySelector('.db-card-body h2, .db-card-body h3, .db-card-body h4') : null;
			var name = h ? String(h.textContent || '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '') : '';
			if (counts[url] > 1) {
				node.removeAttribute('role');
				node.removeAttribute('aria-label');
				node.setAttribute('aria-hidden', 'true');
			} else if (name) {
				node.removeAttribute('aria-hidden');
				node.setAttribute('role', 'img');
				node.setAttribute('aria-label', name + ', photo');
			}
		}
	}

	function start() {
		var roots = document.querySelectorAll('[data-doughboss-menu]');
		if (!roots.length) { return; }
		label();
		if (typeof MutationObserver === 'undefined') { return; }
		var pending = false;
		var obs = new MutationObserver(function () {
			if (pending) { return; }
			pending = true;
			window.setTimeout(function () { pending = false; label(); }, 50);
		});
		for (var i = 0; i < roots.length; i++) {
			obs.observe(roots[i], { childList: true, subtree: true });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
}());
