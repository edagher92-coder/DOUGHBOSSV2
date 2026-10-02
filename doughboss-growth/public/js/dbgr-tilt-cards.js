/*
 * DoughBoss Growth: tilt cards for the coming-soon section.
 *
 * ES5. A card leans a few degrees toward the pointer using CSS custom properties (transform only, no layout).
 * It stays completely flat when the visitor prefers reduced motion (checked live, as the setting can change while the
 * page is open), for touch input, and when the script cannot find the cards. The cards themselves are only rendered by
 * the server for confirmed claims, so on most pages this script is never loaded.
 */
(function () {
	'use strict';

	var MAX_DEG = 8;
	var query = null;
	var cards = [];
	var flat = false;

	/** Lean for a pointer position inside a rectangle: { rx, ry } in degrees, each within +/- MAX_DEG. */
	function compute(rect, x, y) {
		var px;
		var py;
		if (!rect || !(rect.width > 0) || !(rect.height > 0)) {
			return { rx: 0, ry: 0 };
		}
		px = (x - rect.left) / rect.width - 0.5;
		py = (y - rect.top) / rect.height - 0.5;
		px = Math.max(-0.5, Math.min(0.5, px));
		py = Math.max(-0.5, Math.min(0.5, py));
		return { rx: -py * 2 * MAX_DEG, ry: px * 2 * MAX_DEG };
	}

	function reset(card) {
		card.style.setProperty('--dbgr-rx', '0deg');
		card.style.setProperty('--dbgr-ry', '0deg');
	}

	function applyMotionPreference() {
		var i;
		flat = !!(query && query.matches);
		for (i = 0; i < cards.length; i += 1) {
			if (flat) {
				cards[i].className = cards[i].className.indexOf('dbgr-tilt--flat') === -1 ? cards[i].className + ' dbgr-tilt--flat' : cards[i].className;
				reset(cards[i]);
			} else {
				cards[i].className = cards[i].className.replace(/\s*dbgr-tilt--flat/g, '');
			}
		}
	}

	function bind(card) {
		card.addEventListener('pointermove', function (event) {
			var lean;
			if (flat || (event.pointerType && event.pointerType === 'touch')) {
				return;
			}
			lean = compute(card.getBoundingClientRect(), event.clientX, event.clientY);
			card.style.setProperty('--dbgr-rx', lean.rx.toFixed(2) + 'deg');
			card.style.setProperty('--dbgr-ry', lean.ry.toFixed(2) + 'deg');
		});
		card.addEventListener('pointerleave', function () {
			reset(card);
		});
	}

	function init() {
		var found = document.querySelectorAll('[data-dbgr-tilt]');
		var i;
		for (i = 0; i < found.length; i += 1) {
			cards.push(found[i]);
		}
		if (typeof window.matchMedia === 'function') {
			query = window.matchMedia('(prefers-reduced-motion: reduce)');
			if (query) {
				if (typeof query.addEventListener === 'function') {
					query.addEventListener('change', applyMotionPreference);
				} else if (typeof query.addListener === 'function') {
					query.addListener(applyMotionPreference);
				}
			}
		}
		applyMotionPreference();
		for (i = 0; i < cards.length; i += 1) {
			bind(cards[i]);
		}
	}

	window.DoughBossGrowthTilt = { compute: compute, maxDegrees: MAX_DEG };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
