(function () {
	'use strict';
	document.documentElement.classList.add('dbf-js');

	var toggle = document.querySelector('[data-dbf-menu-toggle]');
	var nav = document.querySelector('[data-dbf-nav]');
	if (toggle && nav) {
		var closeButton = nav.querySelector('[data-dbf-menu-close]');
		var mobileQuery = window.matchMedia('(max-width: 900px)');
		function menuOpen() {
			return toggle.getAttribute('aria-expanded') === 'true';
		}
		function focusableMenuItems() {
			return Array.prototype.slice.call(nav.querySelectorAll('a[href], button:not([disabled])'));
		}
		function closeMenu(restoreFocus) {
			toggle.setAttribute('aria-expanded', 'false');
			toggle.setAttribute('aria-label', 'Open navigation');
			nav.classList.remove('is-open');
			document.body.classList.remove('dbf-menu-open');
			if (mobileQuery.matches) nav.setAttribute('aria-hidden', 'true');
			else nav.removeAttribute('aria-hidden');
			if (restoreFocus) toggle.focus();
		}
		function openMenu() {
			toggle.setAttribute('aria-expanded', 'true');
			toggle.setAttribute('aria-label', 'Close navigation');
			nav.removeAttribute('aria-hidden');
			nav.classList.add('is-open');
			document.body.classList.add('dbf-menu-open');
			var items = focusableMenuItems();
			if (items.length) items[0].focus();
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
				if (!items.length) return;
				var first = items[0];
				var last = items[items.length - 1];
				if (event.shiftKey && document.activeElement === first) {
					event.preventDefault();
					last.focus();
				} else if (!event.shiftKey && document.activeElement === last) {
					event.preventDefault();
					first.focus();
				}
			}
		});
		document.addEventListener('click', function (event) {
			if (menuOpen() && !nav.contains(event.target) && !toggle.contains(event.target)) closeMenu(false);
		});
		if (mobileQuery.addEventListener) mobileQuery.addEventListener('change', syncMenuMode);
		else if (mobileQuery.addListener) mobileQuery.addListener(syncMenuMode);
		syncMenuMode();
	}

	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var reveals = Array.prototype.slice.call(document.querySelectorAll('[data-dbf-reveal]'));
	if (!reduceMotion && 'IntersectionObserver' in window) {
		var observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					entry.target.classList.add('is-visible');
					entry.target.setAttribute('data-dbf-scroll-state', 'visible');
				} else {
					entry.target.classList.remove('is-visible');
					entry.target.setAttribute('data-dbf-scroll-state', entry.boundingClientRect.bottom <= 0 ? 'above' : 'below');
				}
			});
		}, { threshold: 0.08, rootMargin: '-3% 0px -4% 0px' });
		reveals.forEach(function (element) { observer.observe(element); });
	} else {
		reveals.forEach(function (element) { element.classList.add('is-visible'); });
	}
}());
