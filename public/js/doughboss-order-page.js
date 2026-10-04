/**
 * DoughBoss integrated Order Online presentation.
 *
 * The menu is hydrated asynchronously, so this observer discovers cards as
 * they arrive and gives them a reversible enter/leave motion in both scroll
 * directions. The menu remains fully visible without JavaScript, without
 * IntersectionObserver, and when the customer requests reduced motion.
 */
(function () {
	'use strict';

	var body = document.body;
	if (!body || !body.classList.contains('doughboss-order-page')) {
		return;
	}

	var reduceMotion = window.matchMedia
		&& window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var menu = document.querySelector('[data-doughboss-menu]');

	body.classList.add('db-order-page-ready');

	if (!menu || reduceMotion || !('IntersectionObserver' in window)) {
		body.classList.add('db-order-page-static');
		return;
	}

	body.classList.add('db-order-page-motion');

	function initialState(node) {
		var rect = node.getBoundingClientRect();
		if (rect.bottom < 0) {
			return 'above';
		}
		if (rect.top > window.innerHeight) {
			return 'below';
		}
		return 'visible';
	}

	function setState(node, state) {
		node.setAttribute('data-db-scroll-state', state);
		node.classList.toggle('is-scroll-visible', state === 'visible');
	}

	var observer = new IntersectionObserver(function (entries) {
		entries.forEach(function (entry) {
			if (entry.isIntersecting) {
				setState(entry.target, 'visible');
				return;
			}

			var state = entry.boundingClientRect.bottom <= 0 ? 'above' : 'below';
			setState(entry.target, state);
		});
	}, {
		root: null,
		rootMargin: '-6% 0px -9% 0px',
		threshold: [0, 0.12, 0.45]
	});

	function prepare(node) {
		if (!node || node.nodeType !== 1 || node.getAttribute('data-db-scroll-ready') === '1') {
			return;
		}
		node.setAttribute('data-db-scroll-ready', '1');
		setState(node, initialState(node));
		observer.observe(node);
	}

	function discover(scope) {
		if (!scope || scope.nodeType !== 1) {
			return;
		}
		if (scope.matches && scope.matches('.db-card')) {
			prepare(scope);
		}
		if (scope.querySelectorAll) {
			scope.querySelectorAll('.db-card').forEach(prepare);
		}
	}

	discover(menu);

	var mutationObserver = new MutationObserver(function (mutations) {
		mutations.forEach(function (mutation) {
			mutation.addedNodes.forEach(discover);
		});
	});
	mutationObserver.observe(menu, { childList: true, subtree: true });
}());
