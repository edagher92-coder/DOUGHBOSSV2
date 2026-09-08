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
			toggle.setAttribute('aria-expanded', 'false');
			toggle.setAttribute('aria-label', 'Open navigation');
			nav.classList.remove('is-open');
			document.body.classList.remove('dbf-menu-open');
			if (mobileQuery.matches) nav.setAttribute('aria-hidden', 'true');
			else nav.removeAttribute('aria-hidden');
			restoreNavigationBackground();
			if (restoreFocus && menuReturnFocus && document.documentElement.contains(menuReturnFocus)) menuReturnFocus.focus();
		}
		function openMenu() {
			if (!mobileQuery.matches) return;
			menuReturnFocus = toggle;
			toggle.setAttribute('aria-expanded', 'true');
			toggle.setAttribute('aria-label', 'Close navigation');
			nav.removeAttribute('aria-hidden');
			nav.classList.add('is-open');
			document.body.classList.add('dbf-menu-open');
			var items = focusableMenuItems();
			if (items.length) items[0].focus();
			isolateNavigationBackground();
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
