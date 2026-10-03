/* DoughBoss Growth: home hero video. ES5, no dependencies, silent, decorative. The poster stays unless every check passes. */
(function () {
	'use strict';

	var w = window;
	var d = document;
	var video = d.querySelector('.dbgr-hero-video');
	var hero = video;
	var nav = w.navigator;
	var conn = nav.connection || nav.mozConnection || nav.webkitConnection;
	var motion = w.matchMedia ? w.matchMedia('(prefers-reduced-motion: reduce)') : null;
	var CODECS = [
		['av1', 'video/mp4; codecs="av01.0.08M.10"'],
		['hevc', 'video/mp4; codecs="hvc1.1.6.L120.90"'],
		['h264', 'video/mp4; codecs="avc1.640028"']
	];
	var started = false;
	var inView = true;
	var ownPause = false;
	var ownButton = null;

	while (hero && !(hero.getAttribute && hero.getAttribute('data-db-manoush-hero') !== null)) { hero = hero.parentNode; }
	if (!video || !hero || !hero.querySelector) { return; }

	function reduced() { return !!(motion && motion.matches); }

	function slowOrSaving() {
		return !!(conn && (conn.saveData === true || /^(slow-2g|2g|3g)$/.test(conn.effectiveType || '')));
	}

	function heroPaused() { return / is-photo-paused /.test(' ' + hero.className + ' '); }

	function wantPlay() { return inView && !d.hidden && !heroPaused() && !ownPause && !reduced(); }

	function sync() {
		if (!started) { return; }
		if (wantPlay()) {
			if (!video.paused) { return; }
			try {
				var p = video.play();
				if (p && p.catch) { p.catch(function () {}); }
			} catch (e) {}
		} else if (!video.paused) {
			try { video.pause(); } catch (e2) {}
		}
	}

	/* The codec first, then the height: 1080 only for a wide render on a fast link, else 720; fall back to any file there is. */
	function choose() {
		var big = video.offsetWidth * (w.devicePixelRatio || 1) > 800 && (!conn || !conn.downlink || conn.downlink >= 5);
		var heights = big ? ['1080', '720'] : ['720', '1080'];
		for (var i = 0; i < CODECS.length; i += 1) {
			if (!video.canPlayType(CODECS[i][1])) { continue; }
			for (var j = 0; j < heights.length; j += 1) {
				var key = CODECS[i][0] + '-' + heights[j];
				var url = video.getAttribute('data-' + key);
				if (url) { return { key: key, url: url }; }
			}
		}
		return null;
	}

	/* WCAG 2.2.2: motion longer than 5 s needs a pause control. Core's button does it; if it is missing, add one that matches. */
	function hasControl() {
		if (hero.querySelector('[data-db-manoush-replay]')) { return true; }
		var bar = hero.querySelector('.db-mh-actions');
		if (!bar) { return false; }
		ownButton = d.createElement('button');
		ownButton.type = 'button';
		ownButton.className = 'db-mh-replay';
		ownButton.setAttribute('aria-pressed', 'false');
		ownButton.textContent = 'Pause video';
		ownButton.addEventListener('click', function () {
			ownPause = !ownPause;
			ownButton.textContent = ownPause ? 'Play video' : 'Pause video';
			ownButton.setAttribute('aria-pressed', ownPause ? 'true' : 'false');
			sync();
		});
		bar.appendChild(ownButton);
		return true;
	}

	function start() {
		if (started || reduced() || slowOrSaving() || !video.canPlayType || !hasControl()) { return; }
		var pick = choose();
		if (!pick) { return; }
		started = true;
		video.muted = true;
		video.addEventListener('playing', function () {
			if (!/ is-playing /.test(' ' + video.className + ' ')) { video.className += ' is-playing'; }
		});
		video.addEventListener('error', function () {
			video.className = video.className.replace(/\s*is-playing/, '');
			started = false;
		});
		video.setAttribute('data-dbgr-source', pick.key);
		video.src = pick.url;
		sync();
	}

	function whenIdle() {
		if (w.requestIdleCallback) { w.requestIdleCallback(start, { timeout: 3000 }); } else { w.setTimeout(start, 1200); }
	}

	/* Pause and resume: scrolled away, tab hidden, core's pause button (it toggles is-photo-paused on the hero). */
	if (w.IntersectionObserver) {
		new w.IntersectionObserver(function (entries) {
			inView = entries[entries.length - 1].isIntersecting;
			sync();
		}, { threshold: 0 }).observe(hero);
	}
	if (w.MutationObserver) {
		new w.MutationObserver(sync).observe(hero, { attributes: true, attributeFilter: ['class'] });
	}
	var core = hero.querySelector('[data-db-manoush-replay]');
	if (core) { core.addEventListener('click', function () { w.setTimeout(sync, 0); }); }
	d.addEventListener('visibilitychange', sync);
	if (motion) {
		if (motion.addEventListener) { motion.addEventListener('change', sync); } else if (motion.addListener) { motion.addListener(sync); }
	}

	if (d.readyState === 'complete') { whenIdle(); } else { w.addEventListener('load', whenIdle); }
}());
