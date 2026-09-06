/* DoughBoss authentic photographic hero: quiet load reveal and scroll parallax. */
(function () {
	'use strict';

	var heroes = document.querySelectorAll('[data-db-manoush-hero]');
	var motionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
	var reduceMotion = motionQuery ? motionQuery.matches : false;
	var queued = false;

	function updateControl(hero) {
		var control = hero.querySelector('[data-db-manoush-replay]');
		if (!control) { return; }
		var label = hero._dbPhotoPaused
			? control.getAttribute('data-db-resume-label')
			: control.getAttribute('data-db-pause-label');
		if (label) {
			control.textContent = label;
			control.setAttribute('aria-label', label);
			control.setAttribute('aria-pressed', hero._dbPhotoPaused ? 'true' : 'false');
		}
	}

	function paint() {
		queued = false;
		if (reduceMotion) { return; }
		var viewport = window.innerHeight || 800;
		for (var index = 0; index < heroes.length; index += 1) {
			var hero = heroes[index];
			if (hero._dbPhotoPaused) { continue; }
			var rect = hero.getBoundingClientRect();
			if (rect.bottom < 0 || rect.top > viewport) { continue; }
			var progress = Math.max(-1, Math.min(1, -rect.top / Math.max(rect.height, 1)));
			hero.style.setProperty('--db-mh-photo-y', (progress * 18).toFixed(1) + 'px');
			hero.style.setProperty('--db-mh-photo-scale', (1.035 + Math.abs(progress) * .025).toFixed(3));
		}
	}

	function requestPaint() {
		if (queued) { return; }
		queued = true;
		window.requestAnimationFrame(paint);
	}

	function wire(hero) {
		var control = hero.querySelector('[data-db-manoush-replay]');
		hero.classList.add('is-photo-ready');
		if (!control) { return; }
		control.addEventListener('click', function () {
			hero._dbPhotoPaused = !hero._dbPhotoPaused;
			hero.classList.toggle('is-photo-paused', hero._dbPhotoPaused);
			updateControl(hero);
			if (!hero._dbPhotoPaused) { requestPaint(); }
		});
		updateControl(hero);
	}

	for (var index = 0; index < heroes.length; index += 1) { wire(heroes[index]); }

	if (heroes.length) {
		window.addEventListener('scroll', requestPaint, { passive: true });
		window.addEventListener('resize', requestPaint);
		if (motionQuery) {
			var preferenceChanged = function (event) {
				reduceMotion = event.matches;
				if (!reduceMotion) { requestPaint(); }
			};
			if (motionQuery.addEventListener) { motionQuery.addEventListener('change', preferenceChanged); }
			else if (motionQuery.addListener) { motionQuery.addListener(preferenceChanged); }
		}
		requestPaint();
	}
}());
