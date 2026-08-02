(function () {
	'use strict';
	document.documentElement.classList.add('dbf-js');

	var toggle = document.querySelector('[data-dbf-menu-toggle]');
	var nav = document.querySelector('[data-dbf-nav]');
	if (toggle && nav) {
		function closeMenu() {
			toggle.setAttribute('aria-expanded', 'false');
			nav.classList.remove('is-open');
			document.body.classList.remove('dbf-menu-open');
		}
		toggle.addEventListener('click', function () {
			var open = toggle.getAttribute('aria-expanded') !== 'true';
			toggle.setAttribute('aria-expanded', String(open));
			nav.classList.toggle('is-open', open);
			document.body.classList.toggle('dbf-menu-open', open);
		});
		nav.addEventListener('click', function (event) {
			if (event.target.closest('a')) closeMenu();
		});
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				closeMenu();
				toggle.focus();
			}
		});
		window.addEventListener('resize', function () {
			if (window.innerWidth > 900) closeMenu();
		});
	}

	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var reveals = Array.prototype.slice.call(document.querySelectorAll('[data-dbf-reveal]'));
	if (!reduceMotion && 'IntersectionObserver' in window) {
		var observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					entry.target.classList.add('is-visible');
					observer.unobserve(entry.target);
				}
			});
		}, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
		reveals.forEach(function (element) { observer.observe(element); });
	} else {
		reveals.forEach(function (element) { element.classList.add('is-visible'); });
	}
}());
