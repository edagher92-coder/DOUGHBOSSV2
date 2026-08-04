'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '..', 'public', 'js', 'doughboss-manoush-hero.js'),
	'utf8'
);

class FakeClassList {
	constructor(...names) { this.names = new Set(names); }
	add(...names) { names.forEach((name) => this.names.add(name)); }
	remove(...names) { names.forEach((name) => this.names.delete(name)); }
	contains(name) { return this.names.has(name); }
}

function fakeStyle() {
	return {
		setProperty(name, value) { this[name] = value; },
		removeProperty(name) { delete this[name]; }
	};
}

function bootHero({ reduced }) {
	let now = 0;
	let nextTimer = 1;
	let heroTop = 100;
	const timers = [];
	const frames = [];
	const windowListeners = new Map();
	const documentListeners = new Map();
	let mediaChange;

	function listen(registry, type, callback) {
		if (!registry.has(type)) { registry.set(type, []); }
		registry.get(type).push(callback);
	}

	function emit(registry, event) {
		(registry.get(event.type) || []).slice().forEach((callback) => callback(event));
	}

	class FakeEvent {
		constructor(type) { this.type = type; }
	}

	const parts = {
		central: { style: fakeStyle() },
		zaatar: { style: fakeStyle() },
		cheese: { style: fakeStyle() },
		meat: { style: fakeStyle() },
		spinach: { style: fakeStyle() }
	};
	const allParts = Object.values(parts);
	const labels = {
		'data-db-start-label': 'Start food animation',
		'data-db-pause-label': 'Pause food animation',
		'data-db-resume-label': 'Resume food animation'
	};
	const buttonListeners = new Map();
	const button = {
		textContent: 'Replay the food build',
		attributes: {},
		getAttribute(name) { return labels[name] || null; },
		setAttribute(name, value) { this.attributes[name] = value; },
		addEventListener(type, callback) { buttonListeners.set(type, callback); },
		click() { buttonListeners.get('click')(); }
	};
	const hero = {
		classList: new FakeClassList('is-assembled'),
		style: fakeStyle(),
		get offsetWidth() { return 1200; },
		getBoundingClientRect() {
			return { top: heroTop, bottom: heroTop + 600, height: 600 };
		},
		querySelector(selector) {
			if (selector === '[data-db-manoush-replay]') { return button; }
			if (selector === '.db-mh-central') { return parts.central; }
			const match = selector.match(/^\.db-mh-ingredient--(.+)$/);
			return match ? parts[match[1]] : null;
		},
		querySelectorAll(selector) {
			if (selector === 'img') { return [{ complete: true }]; }
			if (selector === '.db-mh-central,.db-mh-ingredient') { return allParts; }
			return [];
		}
	};
	const rootClasses = new FakeClassList();
	const document = {
		hidden: false,
		documentElement: { classList: rootClasses, clientHeight: 800 },
		querySelectorAll(selector) { return selector === '[data-db-manoush-hero]' ? [hero] : []; },
		addEventListener(type, callback) { listen(documentListeners, type, callback); }
	};
	const window = {
		innerHeight: 800,
		innerWidth: 1440,
		document,
		sessionStorage: { getItem() { return null; }, setItem() {} },
		matchMedia() {
			return {
				matches: reduced,
				addEventListener(type, callback) { if (type === 'change') { mediaChange = callback; } },
				addListener(callback) { mediaChange = callback; }
			};
		},
		addEventListener(type, callback) { listen(windowListeners, type, callback); },
		dispatchEvent(event) { emit(windowListeners, event); },
		requestAnimationFrame(callback) { frames.push(callback); return frames.length; },
		setTimeout(callback, delay) {
			const timer = { id: nextTimer++, at: now + delay, callback, cancelled: false };
			timers.push(timer);
			return timer.id;
		},
		clearTimeout(id) {
			const timer = timers.find((candidate) => candidate.id === id);
			if (timer) { timer.cancelled = true; }
		},
		getComputedStyle(part) {
			return {
				transform: part.style.transform || (hero.classList.contains('is-exploded') ? 'matrix(1, 0, 0, 1, 125, 75)' : 'none'),
				opacity: part.style.opacity || '1'
			};
		}
	};
	class FakeDate extends Date {
		static now() { return now; }
	}

	vm.runInNewContext(source, {
		window,
		document,
		Event: FakeEvent,
		Date: FakeDate,
		Math,
		console
	}, { filename: 'doughboss-manoush-hero.js' });

	function flushFrames() {
		let guard = 0;
		while (frames.length) {
			if (guard++ > 100) { throw new Error('animation frame loop did not settle'); }
			frames.shift()();
		}
	}

	function advanceTo(target) {
		let guard = 0;
		while (true) {
			if (guard++ > 100) { throw new Error('timer loop did not settle'); }
			const due = timers
				.filter((timer) => !timer.cancelled && timer.at <= target)
				.sort((left, right) => left.at - right.at)[0];
			if (!due) { break; }
			due.cancelled = true;
			now = due.at;
			due.callback();
			flushFrames();
		}
		now = target;
		flushFrames();
	}

	return {
		hero,
		button,
		parts,
		rootClasses,
		flushFrames,
		advanceTo,
		setHeroTop(value) { heroTop = value; },
		scroll() { window.dispatchEvent(new FakeEvent('scroll')); flushFrames(); },
		changeReduced(value) { mediaChange({ matches: value }); flushFrames(); }
	};
}

test('food build auto-plays once, hands off to reversible scroll, and truly pauses', () => {
	const run = bootHero({ reduced: true });
	run.flushFrames();

	assert.equal(run.rootClasses.contains('db-mh-motion-opted-in'), true, 'automatic build remains enabled for this approved experience');
	assert.equal(run.hero.classList.contains('is-exploded'), true, 'first-load build reaches its clearly separated pose');

	run.advanceTo(1500);
	assert.equal(run.hero.classList.contains('is-assembled'), true, 'first-load build reassembles');
	run.advanceTo(3400);
	assert.equal(run.hero.classList.contains('is-scroll-driven'), true, 'completed intro hands control to scroll');
	assert.equal(run.hero._dbManoushPlaying, false);

	const focalTransform = run.parts.central.style.transform;
	run.setHeroTop(-500);
	run.scroll();
	const separatedTransform = run.parts.central.style.transform;
	assert.notEqual(separatedTransform, focalTransform, 'scrolling away from the focal point separates the food');
	run.setHeroTop(100);
	run.scroll();
	assert.equal(run.parts.central.style.transform, focalTransform, 'scrolling back restores the exact position-driven pose');

	run.button.click();
	assert.equal(run.button.textContent, 'Resume food animation');
	const frozenTransform = run.parts.central.style.transform;
	run.setHeroTop(-500);
	run.scroll();
	assert.equal(run.parts.central.style.transform, frozenTransform, 'Pause freezes the current food pose during further scrolling');

	run.button.click();
	run.flushFrames();
	assert.equal(run.button.textContent, 'Pause food animation');
	assert.notEqual(run.parts.central.style.transform, frozenTransform, 'Resume synchronises food with the current scroll position');

	run.advanceTo(20000);
	assert.equal(run.hero.classList.contains('is-scroll-driven'), true, 'no repeat timer takes control back from scroll');
	assert.equal(run.hero.classList.contains('is-exploded'), false);
});

test('runtime change to reduced motion keeps the approved animation CSS in sync', () => {
	const run = bootHero({ reduced: false });
	assert.equal(run.rootClasses.contains('db-mh-motion-opted-in'), false);
	run.changeReduced(true);
	assert.equal(run.rootClasses.contains('db-mh-motion-opted-in'), true);
});

test('Pause samples and freezes the current mid-intro pose before disabling transitions', () => {
	const run = bootHero({ reduced: false });
	run.flushFrames();
	assert.equal(run.hero.classList.contains('is-exploded'), true);

	run.button.click();
	assert.equal(run.parts.central.style.transform, 'matrix(1, 0, 0, 1, 125, 75)');
	assert.equal(run.hero.classList.contains('is-exploded'), false);
	assert.equal(run.hero.classList.contains('is-user-paused'), true);
	run.advanceTo(5000);
	assert.equal(run.parts.central.style.transform, 'matrix(1, 0, 0, 1, 125, 75)');
});
