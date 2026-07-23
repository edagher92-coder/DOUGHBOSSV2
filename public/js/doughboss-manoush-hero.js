/* DoughBoss standalone Manoush hero. */
(function () {
	'use strict';
	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var heroes = document.querySelectorAll('[data-db-manoush-hero]');

	function play(hero) {
		if (hero._dbManoushTimer) { window.clearTimeout(hero._dbManoushTimer); }
		if (reduceMotion) { hero.classList.remove('is-exploded'); hero.classList.add('is-assembled'); return; }
		hero.classList.remove('is-assembled');
		// Force a paint boundary before re-applying the exploded state. Without
		// this, a rapid replay can be coalesced by the browser into no animation.
		void hero.offsetWidth;
		window.requestAnimationFrame(function () {
			window.requestAnimationFrame(function () {
				hero.classList.add('is-exploded');
				hero._dbManoushTimer = window.setTimeout(function () {
					hero.classList.remove('is-exploded');
					hero.classList.add('is-assembled');
				}, 1200);
			});
		});
	}

	function imagesReady(hero, done) {
		var images = hero.querySelectorAll('img');
		var pending = images.length;
		function finish() { pending -= 1; if (pending <= 0) { done(); } }
		if (!pending) { done(); return; }
		for (var i = 0; i < images.length; i += 1) {
			if (images[i].complete) { finish(); }
			else { images[i].addEventListener('load', finish, { once: true }); images[i].addEventListener('error', finish, { once: true }); }
		}
	}

	function wire(hero) {
		var replay = hero.querySelector('[data-db-manoush-replay]');
		if (replay) { replay.addEventListener('click', function () { play(hero); }); }
		if (reduceMotion) { return; }
		function entered() { if (!hero._dbManoushEntered) { hero._dbManoushEntered = true; imagesReady(hero, function () { play(hero); }); } else { play(hero); } }
		if (!('IntersectionObserver' in window)) { window.setTimeout(entered, 280); return; }
		var observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) { entered(); }
			});
		}, { threshold: 0.35 });
		observer.observe(hero);
	}

	for (var i = 0; i < heroes.length; i += 1) { wire(heroes[i]); }
}());
