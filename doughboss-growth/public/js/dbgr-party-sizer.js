/*
 * DoughBoss Growth: catering party-pack sizer (WP-07).
 *
 * ES5 (hand-written, checked by scripts/es5-check.mjs). Dynamic text goes in with textContent only.
 *
 * This script computes no price and no quantity. The visitor types a head count; the script picks the published
 * package whose serve range covers it (the next size up when the count sits between two packages, and the largest when
 * it is beyond them all: never an underquote) and asks core for an indicative quote
 * (GET catering/quote?package_id=&guest_count=&order_type=pickup). It then shows the package name, core's serve range
 * and core's total. The only numbers that can appear on screen are: the head count the visitor typed, a package's serve
 * range from core, and a total returned by core's quote. A quote that is missing, malformed, zero or negative shows no
 * price at all (fail closed). Pieces-per-guest guidance appears only when the server passed a confirmed ledger claim.
 */
(function () {
	'use strict';

	var cfg = window.DoughBossGrowthSizer || null;
	var REQUEST_TIMEOUT_MS = 12000;
	var MAX_TOTAL = 10000000;

	/** One GET. done(status, data): status 0 means the network failed. */
	function get(url, done) {
		var xhr;
		var finished = false;
		function finish(status, data) {
			if (finished) {
				return;
			}
			finished = true;
			done(status, data);
		}
		try {
			xhr = new XMLHttpRequest();
			xhr.open('GET', url, true);
			xhr.setRequestHeader('Accept', 'application/json');
			xhr.timeout = REQUEST_TIMEOUT_MS;
			xhr.onreadystatechange = function () {
				var data = null;
				if (xhr.readyState !== 4) {
					return;
				}
				try {
					data = JSON.parse(xhr.responseText);
				} catch (e) {
					data = null;
				}
				finish(xhr.status, data);
			};
			xhr.send(null);
		} catch (e) {
			finish(0, null);
		}
	}

	function text(key, fallback) {
		var strings = cfg && cfg.strings ? cfg.strings : null;
		return strings && typeof strings[key] === 'string' ? strings[key] : fallback;
	}

	function trim(value) {
		return String(value === null || value === undefined ? '' : value).replace(/^\s+|\s+$/g, '');
	}

	/** Packages sorted by their largest serve count, smallest first (a defensive copy: the server already sorts). */
	function sortedPackages() {
		var list = [];
		var source = (cfg && cfg.packages && typeof cfg.packages.length === 'number') ? cfg.packages : [];
		var i;
		var p;
		for (i = 0; i < source.length; i += 1) {
			p = source[i];
			if (p && typeof p.id === 'number' && p.id > 0 && typeof p.name === 'string' && p.name !== '' &&
				typeof p.serves_max === 'number' && p.serves_max > 0 && typeof p.serves_min === 'number' && p.serves_min >= 0) {
				list.push(p);
			}
		}
		list.sort(function (a, b) {
			return a.serves_max !== b.serves_max ? a.serves_max - b.serves_max : a.id - b.id;
		});
		return list;
	}

	/**
	 * The package for a head count: the smallest whose largest serve count covers it, else the largest. Returns
	 * { index, covered }.
	 */
	function pick(list, guests) {
		var i;
		for (i = 0; i < list.length; i += 1) {
			if (list[i].serves_max >= guests) {
				return { index: i, covered: true };
			}
		}
		return { index: list.length - 1, covered: false };
	}

	/** A total from a core quote answer, or -1 when it cannot be trusted. */
	function quoteTotal(data) {
		var total;
		if (!data || typeof data !== 'object') {
			return -1;
		}
		total = data.total;
		if (typeof total !== 'number' || !isFinite(total) || total <= 0 || total > MAX_TOTAL) {
			return -1;
		}
		return total;
	}

	function money(total, currency) {
		var amount = total.toFixed(2);
		if (currency === 'AUD' || currency === undefined || currency === null || currency === '') {
			return '$' + amount;
		}
		return String(currency).replace(/[^A-Za-z]/g, '').slice(0, 3).toUpperCase() + ' ' + amount;
	}

	function clear(node) {
		while (node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	function para(className, content) {
		var p = document.createElement('p');
		p.className = className;
		p.textContent = content;
		return p;
	}

	function servesText(p) {
		if (p.serves_min > 0 && p.serves_min < p.serves_max) {
			return text('serves', 'Serves') + ' ' + p.serves_min + ' ' + text('to', 'to') + ' ' + p.serves_max;
		}
		return text('serves', 'Serves') + ' ' + p.serves_max;
	}

	function card(label, p, total, currency) {
		var box = document.createElement('div');
		var heading = document.createElement('p');
		box.className = 'dbgr-sizer__card';
		box.appendChild(para('dbgr-sizer__label', label));
		heading.className = 'dbgr-sizer__name';
		heading.textContent = p.name;
		box.appendChild(heading);
		box.appendChild(para('dbgr-sizer__serves', servesText(p)));
		box.appendChild(para('dbgr-sizer__price', money(total, currency)));
		return box;
	}

	function wire(root) {
		var form = root.querySelector('form');
		var input = root.querySelector('input[name="guests"]');
		var result = root.querySelector('[data-dbgr-sizer-result]');
		var generation = 0;
		var list = sortedPackages();
		var max = (cfg && cfg.maxGuests > 0) ? cfg.maxGuests : 1000;

		function show(message) {
			clear(result);
			result.appendChild(para('dbgr-sizer__note', message));
		}

		function quote(p, guests, done) {
			var url = cfg.quoteUrl + (cfg.quoteUrl.indexOf('?') === -1 ? '?' : '&') +
				'package_id=' + encodeURIComponent(String(p.id)) +
				'&guest_count=' + encodeURIComponent(String(guests)) +
				'&order_type=pickup';
			get(url, function (status, data) {
				var total = (status === 200) ? quoteTotal(data) : -1;
				done(total, data && typeof data.currency === 'string' ? data.currency : '');
			});
		}

		function render(guests, first, firstTotal, firstCurrency, second, secondTotal, secondCurrency) {
			clear(result);
			result.appendChild(para('dbgr-sizer__for', text('forGuests', 'For') + ' ' + guests + ' ' + text('guestsWord', 'guests')));
			result.appendChild(card(text('recommended', 'Suggested package'), first, firstTotal, firstCurrency));
			if (second && secondTotal > 0) {
				result.appendChild(card(text('next', 'Next size up'), second, secondTotal, secondCurrency));
			}
			if (cfg && typeof cfg.guidance === 'string' && trim(cfg.guidance) !== '') {
				result.appendChild(para('dbgr-sizer__guidance', cfg.guidance));
			}
			result.appendChild(para('dbgr-sizer__note', text('indicative', '')));
		}

		form.addEventListener('submit', function (event) {
			var raw;
			var guests;
			var choice;
			var first;
			var next;
			var mine;
			if (event && typeof event.preventDefault === 'function') {
				event.preventDefault();
			}
			raw = trim(input.value);
			if (!/^[0-9]{1,6}$/.test(raw) || parseInt(raw, 10) < 1) {
				show(text('guests', ''));
				return;
			}
			guests = parseInt(raw, 10);
			if (guests > max) {
				show(text('tooMany', ''));
				return;
			}
			if (list.length === 0) {
				show(text('unavailable', ''));
				return;
			}
			generation += 1;
			mine = generation;
			choice = pick(list, guests);
			first = list[choice.index];
			next = (choice.covered && choice.index + 1 < list.length) ? list[choice.index + 1] : null;
			show(text('loading', ''));
			quote(first, guests, function (firstTotal, firstCurrency) {
				if (mine !== generation) {
					return;
				}
				if (firstTotal < 0) {
					show(text('unavailable', ''));
					return;
				}
				if (!next) {
					render(guests, first, firstTotal, firstCurrency, null, -1, '');
					return;
				}
				quote(next, guests, function (nextTotal, nextCurrency) {
					if (mine !== generation) {
						return;
					}
					render(guests, first, firstTotal, firstCurrency, next, nextTotal, nextCurrency);
				});
			});
		});
	}

	function inert(root) {
		var form = root.querySelector('form');
		if (form) {
			form.addEventListener('submit', function (event) {
				if (event && typeof event.preventDefault === 'function') {
					event.preventDefault();
				}
			});
		}
	}

	function init() {
		var roots;
		var i;
		roots = document.querySelectorAll('[data-dbgr-sizer]');
		if (!cfg || typeof cfg.quoteUrl !== 'string' || cfg.quoteUrl === '') {
			/* No usable configuration: the form must do nothing (never let the browser submit it). */
			for (i = 0; i < roots.length; i += 1) {
				inert(roots[i]);
			}
			return;
		}
		for (i = 0; i < roots.length; i += 1) {
			if (roots[i].querySelector('form') && roots[i].querySelector('input[name="guests"]') && roots[i].querySelector('[data-dbgr-sizer-result]')) {
				wire(roots[i]);
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
