/* DoughBoss standalone Manoush hero. Scroll position drives the depth state. */
(function () {
	'use strict';
	var motionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
	var reduceMotion = motionQuery ? motionQuery.matches : false;
	var motionOptedIn = false;
	var explodeHoldMs = 1500;
	var settleMs = 1900;
	var repeatDelayMs = 5200;
	var lastScrollAt = 0;
	try { motionOptedIn = window.sessionStorage.getItem('doughbossHeroMotion') === 'on'; } catch (ignore) {}
	var heroes = document.querySelectorAll('[data-db-manoush-hero]');

	function motionAllowed() { return !reduceMotion || motionOptedIn; }
	function animationCanRun(hero) { return motionAllowed() && !hero._dbManoushUserPaused; }

	function updateReplayLabel(hero) {
		var replay = hero.querySelector('[data-db-manoush-replay]');
		if (!replay) { return; }
		var label;
		if (reduceMotion && !motionOptedIn) { label = replay.getAttribute('data-db-start-label'); }
		else if (hero._dbManoushUserPaused) { label = replay.getAttribute('data-db-resume-label'); }
		else { label = replay.getAttribute('data-db-pause-label'); }
		if (label) {
			replay.textContent = label;
			replay.setAttribute('aria-label', label);
		}
	}

	function clearRepeat(hero) {
		if (hero._dbManoushRepeatTimer) {
			window.clearTimeout(hero._dbManoushRepeatTimer);
			hero._dbManoushRepeatTimer = 0;
		}
	}

	function stopCycle(hero) {
		hero._dbManoushCycle = (hero._dbManoushCycle || 0) + 1;
		clearRepeat(hero);
		if (hero._dbManoushTimer) { window.clearTimeout(hero._dbManoushTimer); hero._dbManoushTimer = 0; }
		if (hero._dbManoushUnlockTimer) { window.clearTimeout(hero._dbManoushUnlockTimer); hero._dbManoushUnlockTimer = 0; }
		hero._dbManoushPlaying = false;
		hero._dbManoushHoldUntil = 0;
		releaseScrollHero(hero);
		hero.classList.remove('is-resetting', 'is-exploded');
		hero.classList.add('is-assembled');
	}

	function pauseHero(hero) {
		hero._dbManoushUserPaused = true;
		stopCycle(hero);
		hero.classList.add('is-user-paused');
		updateReplayLabel(hero);
	}

	function heroIsVisible(hero) {
		if (document.hidden || (hero._dbManoushObserved && hero._dbManoushInView === false)) { return false; }
		var rect = hero.getBoundingClientRect();
		return rect.bottom > 0 && rect.top < (window.innerHeight || document.documentElement.clientHeight || 800);
	}

	function scheduleReplay(hero, delay) {
		clearRepeat(hero);
		if (!hero._dbManoushReady || !animationCanRun(hero) || !heroIsVisible(hero)) { return; }
		hero._dbManoushRepeatTimer = window.setTimeout(function () {
			hero._dbManoushRepeatTimer = 0;
			if (!animationCanRun(hero) || !heroIsVisible(hero)) { return; }
			if (Date.now() - lastScrollAt < 1500) {
				scheduleReplay(hero, 1700);
				return;
			}
			play(hero);
		}, typeof delay === 'number' ? delay : repeatDelayMs);
	}

	function enableMotion() {
		if (!reduceMotion || motionOptedIn) { return; }
		motionOptedIn = true;
		try { window.sessionStorage.setItem('doughbossHeroMotion', 'on'); } catch (ignore) {}
		document.documentElement.classList.add('db-mh-motion-opted-in');
		for (var index = 0; index < heroes.length; index += 1) {
			heroes[index].classList.remove('is-motion-paused');
			updateReplayLabel(heroes[index]);
		}
	}

	if (reduceMotion && motionOptedIn) { document.documentElement.classList.add('db-mh-motion-opted-in'); }

	function releaseScrollHero(hero) {
		Array.prototype.forEach.call(hero.querySelectorAll('.db-mh-central,.db-mh-ingredient'), function (part) {
			part.style.removeProperty('transform');
			part.style.removeProperty('opacity');
		});
		hero.classList.remove('is-scroll-driven');
	}

	function play(hero) {
		stopCycle(hero);
		if (!animationCanRun(hero)) { return; }
		hero.classList.remove('is-user-paused');
		var cycle = hero._dbManoushCycle;
		hero._dbManoushPlaying = true;
		hero._dbManoushHasPlayed = true;
		hero._dbManoushHoldUntil = Date.now() + explodeHoldMs + settleMs;
		hero.classList.remove('is-exploded');
		hero.classList.remove('is-assembled');
		hero.classList.add('is-resetting');
		// Force a paint boundary before re-applying the exploded state. Without
		// this, a rapid replay can be coalesced by the browser into no animation.
		void hero.offsetWidth;
		window.requestAnimationFrame(function () {
			if (cycle !== hero._dbManoushCycle) { return; }
			window.requestAnimationFrame(function () {
				if (cycle !== hero._dbManoushCycle) { return; }
				hero.classList.remove('is-resetting');
				hero.classList.add('is-exploded');
				hero._dbManoushTimer = window.setTimeout(function () {
					if (cycle !== hero._dbManoushCycle) { return; }
					hero.classList.remove('is-exploded');
					hero.classList.add('is-assembled');
				}, explodeHoldMs);
				hero._dbManoushUnlockTimer = window.setTimeout(function () {
					if (cycle !== hero._dbManoushCycle) { return; }
					hero._dbManoushPlaying = false;
					scheduleReplay(hero);
				}, explodeHoldMs + settleMs);
			});
		});
	}

	function imagesReady(hero, done) {
		var images = hero.querySelectorAll('img');
		var pending = images.length;
		var finished = false;
		function doneOnce() { if (finished) { return; } finished = true; done(); }
		function finish() { pending -= 1; if (pending <= 0) { doneOnce(); } }
		if (!pending) { doneOnce(); return; }
		for (var i = 0; i < images.length; i += 1) {
			if (images[i].complete) { finish(); }
			else { images[i].addEventListener('load', finish, { once: true }); images[i].addEventListener('error', finish, { once: true }); }
		}
		window.setTimeout(doneOnce, 1800);
	}

	function prepareScrollHero(hero) {
		if (!animationCanRun(hero) || hero._dbManoushPlaying || Date.now() < (hero._dbManoushHoldUntil || 0)) { return; }
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
		if (!animationCanRun(hero) || hero._dbManoushPlaying || Date.now() < (hero._dbManoushHoldUntil || 0)) { return; }
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
		hero._dbManoushInView = heroIsVisible(hero);
		updateReplayLabel(hero);
		if (replay) {
			replay.addEventListener('click', function () {
				if (reduceMotion && !motionOptedIn) {
					enableMotion();
					hero._dbManoushUserPaused = false;
					updateReplayLabel(hero);
					play(hero);
				} else if (hero._dbManoushUserPaused) {
					hero._dbManoushUserPaused = false;
					updateReplayLabel(hero);
					play(hero);
				} else {
					pauseHero(hero);
				}
			});
		}
		if ('IntersectionObserver' in window) {
			hero._dbManoushObserved = true;
			var observer = new IntersectionObserver(function (entries) {
				for (var entryIndex = 0; entryIndex < entries.length; entryIndex += 1) {
					var entry = entries[entryIndex];
					entry.target._dbManoushInView = entry.isIntersecting;
					if (!entry.isIntersecting) {
						clearRepeat(entry.target);
					} else if (entry.target._dbManoushReady && animationCanRun(entry.target)) {
						if (entry.target._dbManoushPlaying) { continue; }
						if (!entry.target._dbManoushHasPlayed) { play(entry.target); }
						else { scheduleReplay(entry.target, 700); }
					}
				}
			}, { threshold: 0.22 });
			observer.observe(hero);
		}
		if (!motionAllowed()) { hero.classList.add('is-motion-paused'); }
		imagesReady(hero, function () {
			hero._dbManoushReady = true;
			if (animationCanRun(hero) && heroIsVisible(hero)) { play(hero); }
		});
	}

	for (var i = 0; i < heroes.length; i += 1) { wire(heroes[i]); }

	if (heroes.length) {
		var queued = false;
		function renderScrollScenes() {
			if (!motionAllowed()) { queued = false; return; }
			var viewport = window.innerHeight || 800;
			for (var index = 0; index < heroes.length; index += 1) {
				var hero = heroes[index];
				var rect = hero.getBoundingClientRect();
				var centre = (rect.top + rect.height / 2 - viewport / 2) / Math.max(viewport + rect.height, 1);
				var progress = Math.max(0, Math.min(1, (viewport - rect.top) / Math.max(viewport + rect.height, 1)));
				hero.style.setProperty('--db-mh-scene-y', (centre * -34).toFixed(1) + 'px');
				hero.style.setProperty('--db-mh-scene-scale', (1.055 + Math.sin(progress * Math.PI) * .035).toFixed(3));
				if (!animationCanRun(hero) || hero._dbManoushPlaying || Date.now() < (hero._dbManoushHoldUntil || 0)) { continue; }
				// Ingredients draw inward at the focal point, then separate at either
				// edge. The same position-driven motion plays in reverse on upward scroll.
				paintScrollHero(hero, Math.min(1, Math.max(0, Math.abs(centre) - .035) * 3.45));
				if (!hero._dbManoushObserved && hero._dbManoushReady && !hero._dbManoushRepeatTimer && heroIsVisible(hero)) {
					scheduleReplay(hero, 1700);
				}
			}
			queued = false;
		}
		function requestScrollScene() {
			lastScrollAt = Date.now();
			if (queued) { return; }
			queued = true;
			window.requestAnimationFrame(renderScrollScenes);
		}
		window.addEventListener('scroll', requestScrollScene, { passive: true });
		window.addEventListener('resize', requestScrollScene);
		document.addEventListener('visibilitychange', function () {
			for (var index = 0; index < heroes.length; index += 1) {
				if (document.hidden) { stopCycle(heroes[index]); }
				else if (heroes[index]._dbManoushReady && !heroes[index]._dbManoushPlaying && animationCanRun(heroes[index]) && heroIsVisible(heroes[index])) { scheduleReplay(heroes[index], 700); }
			}
		});
		function motionPreferenceChanged(event) {
			reduceMotion = event.matches;
			for (var index = 0; index < heroes.length; index += 1) {
				var hero = heroes[index];
				if (reduceMotion && !motionOptedIn) {
					hero.classList.add('is-motion-paused');
					stopCycle(hero);
				} else {
					hero.classList.remove('is-motion-paused');
					if (hero._dbManoushReady && !hero._dbManoushPlaying && animationCanRun(hero) && heroIsVisible(hero)) { scheduleReplay(hero, 700); }
				}
				updateReplayLabel(hero);
			}
		}
		if (motionQuery) {
			if (motionQuery.addEventListener) { motionQuery.addEventListener('change', motionPreferenceChanged); }
			else if (motionQuery.addListener) { motionQuery.addListener(motionPreferenceChanged); }
		}
		if (motionAllowed()) { requestScrollScene(); }
	}
}());
