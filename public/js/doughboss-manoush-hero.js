/* DoughBoss standalone Manoush hero. Scroll position drives the depth state. */
(function () {
	'use strict';
	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var heroes = document.querySelectorAll('[data-db-manoush-hero]');

	function releaseScrollHero(hero) {
		Array.prototype.forEach.call(hero.querySelectorAll('.db-mh-central,.db-mh-ingredient'), function (part) {
			part.style.removeProperty('transform');
			part.style.removeProperty('opacity');
		});
		hero.classList.remove('is-scroll-driven');
	}

	function play(hero) {
		releaseScrollHero(hero);
		if (hero._dbManoushTimer) { window.clearTimeout(hero._dbManoushTimer); }
		if (hero._dbManoushUnlockTimer) { window.clearTimeout(hero._dbManoushUnlockTimer); }
		if (reduceMotion) { hero.classList.remove('is-resetting'); hero.classList.remove('is-exploded'); hero.classList.add('is-assembled'); return; }
		hero._dbManoushPlaying = true;
		hero._dbManoushHoldUntil = Date.now() + 3100;
		hero.classList.remove('is-exploded');
		hero.classList.remove('is-assembled');
		hero.classList.add('is-resetting');
		// Force a paint boundary before re-applying the exploded state. Without
		// this, a rapid replay can be coalesced by the browser into no animation.
		void hero.offsetWidth;
		window.requestAnimationFrame(function () {
			window.requestAnimationFrame(function () {
				hero.classList.remove('is-resetting');
				hero.classList.add('is-exploded');
				hero._dbManoushTimer = window.setTimeout(function () {
					hero.classList.remove('is-exploded');
					hero.classList.add('is-assembled');
				}, 820);
				hero._dbManoushUnlockTimer = window.setTimeout(function () {
					hero._dbManoushPlaying = false;
				}, 2350);
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

	function prepareScrollHero(hero) {
		if (reduceMotion || hero._dbManoushPlaying || Date.now() < (hero._dbManoushHoldUntil || 0)) { return; }
		if (hero._dbManoushTimer) { window.clearTimeout(hero._dbManoushTimer); hero._dbManoushTimer = 0; }
		hero.classList.remove('is-exploded', 'is-assembled');
		hero.classList.add('is-scroll-driven');
	}

	function mix(from, to, amount) { return from + (to - from) * amount; }

	function heroRecipe(name) {
		var recipes = {
			zaatar: { near: [-166, -104, 44, 10, 0, -12], scatter: [-300, -178, 240, 20, -12, -32] },
			cheese: { near: [170, -100, 64, 10, 0, 13], scatter: [302, -162, 300, 20, 12, 29] },
			meat: { near: [164, 116, 54, 10, 0, -10], scatter: [286, 204, 240, 20, 12, -25] },
			spinach: { near: [-162, 116, 60, 10, 0, 12], scatter: [-290, 202, 280, 20, 12, 31] }
		};
		var recipe = recipes[name];
		if (window.innerWidth <= 720) {
			var width = window.innerWidth;
			var mobile = {
				zaatar: { near: [-.26 * width, -.15 * width], scatter: [-.39 * width, -.24 * width] },
				cheese: { near: [.26 * width, -.15 * width], scatter: [.39 * width, -.24 * width] },
				meat: { near: [.24 * width, .17 * width], scatter: [.36 * width, .27 * width] },
				spinach: { near: [-.24 * width, .17 * width], scatter: [-.36 * width, .27 * width] }
			}[name];
			recipe.near[0] = mobile.near[0]; recipe.near[1] = mobile.near[1];
			recipe.scatter[0] = mobile.scatter[0]; recipe.scatter[1] = mobile.scatter[1];
		}
		return recipe;
	}

	function paintScrollHero(hero, amount) {
		if (hero._dbManoushPlaying || Date.now() < (hero._dbManoushHoldUntil || 0)) { return; }
		prepareScrollHero(hero);
		var central = hero.querySelector('.db-mh-central');
		if (central) {
			central.style.transform = 'translate3d(-50%,calc(-50% + ' + mix(0, 16, amount).toFixed(2) + 'px),' + mix(16, -90, amount).toFixed(2) + 'px) rotateX(' + mix(14, 18, amount).toFixed(2) + 'deg) rotateY(' + mix(0, -8, amount).toFixed(2) + 'deg) rotateZ(' + mix(-3, 6, amount).toFixed(2) + 'deg) scale(' + mix(1, .87, amount).toFixed(3) + ')';
			central.style.opacity = mix(1, .73, amount).toFixed(3);
		}
		['zaatar', 'cheese', 'meat', 'spinach'].forEach(function (name) {
			var part = hero.querySelector('.db-mh-ingredient--' + name);
			if (!part) { return; }
			var recipe = heroRecipe(name);
			var values = recipe.near.map(function (value, index) { return mix(value, recipe.scatter[index], amount); });
			part.style.transform = 'translate3d(calc(-50% + ' + values[0].toFixed(2) + 'px),calc(-50% + ' + values[1].toFixed(2) + 'px),' + values[2].toFixed(2) + 'px) rotateX(' + values[3].toFixed(2) + 'deg) rotateY(' + values[4].toFixed(2) + 'deg) rotateZ(' + values[5].toFixed(2) + 'deg) scale(' + mix(1, 1.09, amount).toFixed(3) + ')';
			part.style.opacity = mix(1, 1, amount).toFixed(3);
		});
		hero.style.setProperty('--db-scroll-energy', amount.toFixed(3));
	}

	function wire(hero) {
		var replay = hero.querySelector('[data-db-manoush-replay]');
		if (replay) { replay.addEventListener('click', function () { play(hero); }); }
		if (reduceMotion) { return; }
		imagesReady(hero, function () { play(hero); });
	}

	for (var i = 0; i < heroes.length; i += 1) { wire(heroes[i]); }

	if (!reduceMotion && heroes.length) {
		var queued = false;
		function renderScrollScenes() {
			var viewport = window.innerHeight || 800;
			for (var index = 0; index < heroes.length; index += 1) {
				var hero = heroes[index];
				var rect = hero.getBoundingClientRect();
				var centre = (rect.top + rect.height / 2 - viewport / 2) / Math.max(viewport + rect.height, 1);
				var progress = Math.max(0, Math.min(1, (viewport - rect.top) / Math.max(viewport + rect.height, 1)));
				hero.style.setProperty('--db-mh-scene-y', (centre * -34).toFixed(1) + 'px');
				hero.style.setProperty('--db-mh-scene-scale', (1.055 + Math.sin(progress * Math.PI) * .035).toFixed(3));
				if (hero._dbManoushPlaying || Date.now() < (hero._dbManoushHoldUntil || 0)) { continue; }
				// Ingredients draw inward at the focal point, then separate at either
				// edge. The same position-driven motion plays in reverse on upward scroll.
				paintScrollHero(hero, Math.min(1, Math.max(0, Math.abs(centre) - .035) * 3.45));
			}
			queued = false;
		}
		function requestScrollScene() {
			if (queued) { return; }
			queued = true;
			window.requestAnimationFrame(renderScrollScenes);
		}
		window.addEventListener('scroll', requestScrollScene, { passive: true });
		window.addEventListener('resize', requestScrollScene);
		requestScrollScene();
	}
}());
