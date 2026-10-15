(function () {
	'use strict';
	document.documentElement.classList.add('dbf-js');

	var toggle = document.querySelector('[data-dbf-menu-toggle]');
	var nav = document.querySelector('[data-dbf-nav]');
	if (toggle && nav) {
		var closeButton = nav.querySelector('[data-dbf-menu-close]');
		var mobileQuery = window.matchMedia('(max-width: 900px)');
		var isolatedNavigationBackground = [];
		var menuReturnFocus = toggle;
		var menuFocusRevision = 0;
		function menuOpen() {
			return toggle.getAttribute('aria-expanded') === 'true';
		}
		function focusableMenuItems() {
			return Array.prototype.slice.call(nav.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'));
		}
		function navigationIsolationTargets(root, exception) {
			var targets = [];
			Array.prototype.forEach.call(root.children || [], function (child) {
				if (child === exception) return;
				if (child.contains(exception)) {
					targets = targets.concat(navigationIsolationTargets(child, exception));
				} else {
					targets.push(child);
				}
			});
			return targets;
		}
		function isolateNavigationBackground() {
			if (!mobileQuery.matches || isolatedNavigationBackground.length) return;
			isolatedNavigationBackground = navigationIsolationTargets(document.body, nav).map(function (element) {
				var state = {
					element: element,
					inert: element.getAttribute('inert'),
					ariaHidden: element.getAttribute('aria-hidden')
				};
				element.setAttribute('inert', '');
				element.setAttribute('aria-hidden', 'true');
				return state;
			});
		}
		function restoreNavigationBackground() {
			isolatedNavigationBackground.forEach(function (state) {
				if (state.inert === null) state.element.removeAttribute('inert');
				else state.element.setAttribute('inert', state.inert);
				if (state.ariaHidden === null) state.element.removeAttribute('aria-hidden');
				else state.element.setAttribute('aria-hidden', state.ariaHidden);
			});
			isolatedNavigationBackground = [];
		}
		function closeMenu(restoreFocus) {
			menuFocusRevision += 1;
			toggle.setAttribute('aria-expanded', 'false');
			toggle.setAttribute('aria-label', 'Open navigation');
			nav.classList.remove('is-open');
			document.body.classList.remove('dbf-menu-open');
			if (mobileQuery.matches) nav.setAttribute('aria-hidden', 'true');
			else nav.removeAttribute('aria-hidden');
			restoreNavigationBackground();
			if (restoreFocus && menuReturnFocus && document.documentElement.contains(menuReturnFocus)) menuReturnFocus.focus();
		}
		function queueNavigationInitialFocus() {
			var revision = ++menuFocusRevision;
			var defer = window.requestAnimationFrame || function (callback) { return window.setTimeout(callback, 0); };
			defer(function () {
				if (revision !== menuFocusRevision || !menuOpen() || !mobileQuery.matches || !nav.classList.contains('is-open')) return;
				var items = focusableMenuItems();
				if (items.length) items[0].focus();
				// Do not hide the triggering control while it still owns focus. If a
				// browser rejects the focus move, leave the background available.
				if (nav.contains(document.activeElement)) isolateNavigationBackground();
			});
		}
		function openMenu() {
			if (!mobileQuery.matches) return;
			menuReturnFocus = toggle;
			toggle.setAttribute('aria-expanded', 'true');
			toggle.setAttribute('aria-label', 'Close navigation');
			nav.removeAttribute('aria-hidden');
			nav.classList.add('is-open');
			document.body.classList.add('dbf-menu-open');
			// The closed drawer is visibility:hidden. Let its open styles reach
			// layout before asking the browser to move focus into it.
			queueNavigationInitialFocus();
		}
		function syncMenuMode() {
			closeMenu(false);
		}
		toggle.addEventListener('click', function () {
			if (menuOpen()) closeMenu(false);
			else openMenu();
		});
		if (closeButton) closeButton.addEventListener('click', function () { closeMenu(true); });
		nav.addEventListener('click', function (event) {
			if (event.target.closest('a')) closeMenu(false);
		});
		document.addEventListener('keydown', function (event) {
			if (!menuOpen()) return;
			if (event.key === 'Escape') {
				closeMenu(true);
				return;
			}
			if (event.key === 'Tab') {
				var items = focusableMenuItems();
				if (!items.length) {
					event.preventDefault();
					return;
				}
				var first = items[0];
				var last = items[items.length - 1];
				if (!nav.contains(document.activeElement)) {
					event.preventDefault();
					(event.shiftKey ? last : first).focus();
				} else if (event.shiftKey && document.activeElement === first) {
					event.preventDefault();
					last.focus();
				} else if (!event.shiftKey && document.activeElement === last) {
					event.preventDefault();
					first.focus();
				}
			}
		});
		document.addEventListener('click', function (event) {
			if (menuOpen() && !nav.contains(event.target) && !toggle.contains(event.target)) closeMenu(true);
		});
		if (mobileQuery.addEventListener) mobileQuery.addEventListener('change', syncMenuMode);
		else if (mobileQuery.addListener) mobileQuery.addListener(syncMenuMode);
		syncMenuMode();
	}

	/* The storefront CSS positions its sticky bits against --db-sticky-top but
	 * nothing ever set it, so the menu's category jump-bar stuck to y=0 and sat
	 * *behind* this sticky header (measured: 47px of overlap at 1280px wide, and
	 * elementFromPoint returned the header — the chips were unclickable).
	 * Publish the real header height instead of hard-coding one: this stays
	 * correct with the admin bar, a wrapped nav, or a future header change. */
	var stickyHeader = document.querySelector('[data-dbf-header]');
	if (stickyHeader && document.documentElement.style.setProperty) {
		var syncStickyTop = function () {
			var h = Math.round(stickyHeader.getBoundingClientRect().height);
			if (h > 0) {
				document.documentElement.style.setProperty('--db-sticky-top', h + 'px');
				document.documentElement.style.setProperty('--dbf-sticky-top', h + 'px');
			}
		};
		syncStickyTop();
		window.addEventListener('resize', syncStickyTop);
		window.addEventListener('orientationchange', syncStickyTop);
		window.addEventListener('load', syncStickyTop);
		// Shop status arrives after initial layout and can wrap on small screens.
		if (window.ResizeObserver) { new ResizeObserver(syncStickyTop).observe(stickyHeader); }
	}

	/* Store cards: live "Open now / Closed" badge in Sydney time, recomputed
	 * every minute from the card's weekly hours (data-dbf-hours, weekday 1=Mon
	 * … 7=Sun => [["06:30","14:30"]]). Mirrors DoughBoss_Store_Hours::state()
	 * so a cached page never shows a stale server-rendered badge for long. */
	function sydneyClock(now) {
		try {
			var parts = new Intl.DateTimeFormat('en-AU', {
				timeZone: 'Australia/Sydney', weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
			}).formatToParts(now);
			var map = {};
			parts.forEach(function (part) { map[part.type] = part.value; });
			var days = { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 };
			var day = days[String(map.weekday || '').slice(0, 3)];
			var hour = Number(map.hour) % 24;
			var minute = Number(map.minute);
			if (!day || !isFinite(hour) || !isFinite(minute)) return null;
			return { day: day, clock: (hour < 10 ? '0' : '') + hour + ':' + (minute < 10 ? '0' : '') + minute };
		} catch (error) {
			return null;
		}
	}
	function storeOpenState(hours, clock) {
		var state = { known: false, open: false, closesAt: null, nextDay: null, nextAt: null };
		if (!clock || !hours || typeof hours !== 'object') return state;
		var valid = /^(?:[01]\d|2[0-3]):[0-5]\d$/;
		function ranges(day) {
			var list = Array.isArray(hours[day]) ? hours[day] : [];
			return list.filter(function (range) {
				return Array.isArray(range) && range.length === 2 && valid.test(range[0]) && valid.test(range[1]) && range[0] < range[1];
			}).sort(function (a, b) { return a[0] < b[0] ? -1 : 1; });
		}
		for (var d = 1; d <= 7; d += 1) { if (ranges(d).length) state.known = true; }
		if (!state.known) return state;
		var today = ranges(clock.day);
		for (var i = 0; i < today.length; i += 1) {
			if (clock.clock >= today[i][0] && clock.clock < today[i][1]) {
				state.open = true;
				state.closesAt = today[i][1];
				return state;
			}
		}
		for (var offset = 0; offset < 8; offset += 1) {
			var candidate = ((clock.day - 1 + offset) % 7) + 1;
			var list = ranges(candidate);
			for (var j = 0; j < list.length; j += 1) {
				if (offset === 0 && list[j][0] <= clock.clock) continue;
				state.nextDay = candidate;
				state.nextAt = list[j][0];
				return state;
			}
		}
		return state;
	}
	function formatStoreClock(time) {
		var match = /^(\d{2}):(\d{2})$/.exec(String(time || ''));
		if (!match) return '';
		var hour = Number(match[1]);
		var minute = Number(match[2]);
		var twelve = hour % 12 === 0 ? 12 : hour % 12;
		return twelve + (minute ? ':' + match[2] : '') + (hour >= 12 ? 'pm' : 'am');
	}
	function storeBadgeLabel(state, clock) {
		if (!state || !state.known) return '';
		if (state.open) return 'Open now · until ' + formatStoreClock(state.closesAt);
		if (!state.nextDay) return 'Closed now';
		var names = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
		var when = clock && state.nextDay === clock.day ? '' : ' ' + names[state.nextDay];
		return 'Closed now · opens' + when + ' ' + formatStoreClock(state.nextAt);
	}
	var storeCards = Array.prototype.slice.call(document.querySelectorAll('[data-dbf-store][data-dbf-hours]'));
	function paintStoreBadges() {
		var clock = sydneyClock(new Date());
		storeCards.forEach(function (card) {
			var badge = card.querySelector('[data-dbf-open-badge]');
			if (!badge) return;
			var hours = null;
			try { hours = JSON.parse(card.getAttribute('data-dbf-hours') || 'null'); } catch (error) { hours = null; }
			var state = storeOpenState(hours, clock);
			var label = storeBadgeLabel(state, clock);
			badge.hidden = !label;
			if (badge.textContent !== label) badge.textContent = label;
			badge.classList.toggle('is-open', !!label && state.open);
			badge.classList.toggle('is-closed', !!label && !state.open);
		});
	}
	if (storeCards.length) {
		paintStoreBadges();
		window.setInterval(function () { if (!document.hidden) paintStoreBadges(); }, 60000);
		document.addEventListener('visibilitychange', function () { if (!document.hidden) paintStoreBadges(); });
	}

	var motionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
	var reveals = Array.prototype.slice.call(document.querySelectorAll('[data-dbf-reveal]'));
	var revealObserver = null;
	function showAllReveals() {
		reveals.forEach(function (element) {
			element.classList.add('is-visible');
			element.removeAttribute('data-dbf-scroll-state');
		});
	}
	function stopRevealObserver() {
		if (revealObserver) revealObserver.disconnect();
		revealObserver = null;
	}
	function startRevealObserver() {
		stopRevealObserver();
		if (!('IntersectionObserver' in window)) {
			showAllReveals();
			return;
		}
		var observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					entry.target.classList.add('is-visible');
					observer.unobserve(entry.target);
				}
			});
		}, { threshold: 0.08, rootMargin: '-3% 0px -4% 0px' });
		revealObserver = observer;
		reveals.forEach(function (element) { revealObserver.observe(element); });
	}
	function syncMotionPreference() {
		var reduceMotion = motionQuery ? motionQuery.matches : false;
		document.documentElement.classList.toggle('dbf-motion-ok', !reduceMotion);
		if (reduceMotion) {
			stopRevealObserver();
			showAllReveals();
		} else {
			startRevealObserver();
		}
	}
	if (motionQuery && motionQuery.addEventListener) motionQuery.addEventListener('change', syncMotionPreference);
	else if (motionQuery && motionQuery.addListener) motionQuery.addListener(syncMotionPreference);
	syncMotionPreference();
}());
