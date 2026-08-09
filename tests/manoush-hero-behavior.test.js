'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'doughboss-manoush-hero.js'), 'utf8');

function boot(reduced) {
	let top = 0;
	const frames = [];
	const listeners = {};
	const buttonListeners = {};
	const classes = new Set();
	const style = {
		setProperty(name, value) { this[name] = value; }
	};
	const labels = {
		'data-db-pause-label': 'Pause photo motion',
		'data-db-resume-label': 'Resume photo motion'
	};
	const button = {
		textContent: '',
		attributes: {},
		getAttribute(name) { return labels[name] || null; },
		setAttribute(name, value) { this.attributes[name] = value; },
		addEventListener(type, callback) { buttonListeners[type] = callback; },
		click() { buttonListeners.click(); }
	};
	const hero = {
		style,
		classList: {
			add(name) { classes.add(name); },
			toggle(name, force) { if (force) { classes.add(name); } else { classes.delete(name); } },
			contains(name) { return classes.has(name); }
		},
		querySelector(selector) { return selector === '[data-db-manoush-replay]' ? button : null; },
		getBoundingClientRect() { return { top, bottom: top + 700, height: 700 }; }
	};
	const media = {
		matches: reduced,
		addEventListener() {},
		addListener() {}
	};
	const window = {
		innerHeight: 800,
		matchMedia() { return media; },
		requestAnimationFrame(callback) { frames.push(callback); },
		addEventListener(type, callback) { listeners[type] = callback; }
	};
	const document = {
		querySelectorAll(selector) { return selector === '[data-db-manoush-hero]' ? [hero] : []; }
	};

	vm.runInNewContext(source, { window, document, console, Math }, { filename: 'doughboss-manoush-hero.js' });

	function flush() {
		while (frames.length) { frames.shift()(); }
	}

	return {
		hero,
		button,
		flush,
		setTop(value) { top = value; },
		scroll() { listeners.scroll(); flush(); }
	};
}

test('real-photo parallax follows scroll and can be paused without snapping', () => {
	const run = boot(false);
	run.flush();
	assert.equal(run.button.textContent, 'Pause photo motion');
	const initial = run.hero.style['--db-mh-photo-y'];
	run.setTop(-350);
	run.scroll();
	assert.notEqual(run.hero.style['--db-mh-photo-y'], initial);

	run.button.click();
	assert.equal(run.hero.classList.contains('is-photo-paused'), true);
	assert.equal(run.button.textContent, 'Resume photo motion');
	const paused = run.hero.style['--db-mh-photo-y'];
	run.setTop(-600);
	run.scroll();
	assert.equal(run.hero.style['--db-mh-photo-y'], paused);

	run.button.click();
	run.flush();
	assert.equal(run.hero.classList.contains('is-photo-paused'), false);
	assert.equal(run.button.textContent, 'Pause photo motion');
	assert.notEqual(run.hero.style['--db-mh-photo-y'], paused);
});

test('reduced-motion preference suppresses JavaScript photo movement', () => {
	const run = boot(true);
	run.flush();
	assert.equal(run.hero.style['--db-mh-photo-y'], undefined);
	run.setTop(-500);
	run.scroll();
	assert.equal(run.hero.style['--db-mh-photo-y'], undefined);
});
