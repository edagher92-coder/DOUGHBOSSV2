'use strict';
/*
 * WP-05 node tests for public/js/dbgr-tilt-cards.js: the CSS custom-property tilt on the coming-soon cards.
 *
 * Runs the REAL ES5 file in a node:vm context with a fake card, a fake pointer and a fake matchMedia (so the live
 * prefers-reduced-motion behaviour can be flipped while the page is "open"). No jsdom, no network.
 * The CSS side (flat under reduced motion even if the script fails) is checked in test-waitlist.php and by the browser
 * check wp05-waitlist.mjs.
 */
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const PLUGIN_DIR = path.resolve(__dirname, '..');
const SOURCE = fs.readFileSync(path.join(PLUGIN_DIR, 'public', 'js', 'dbgr-tilt-cards.js'), 'utf8');
const CSS = fs.readFileSync(path.join(PLUGIN_DIR, 'public', 'css', 'dbgr-coming-soon.css'), 'utf8');

class Card {
  constructor(rect) {
    this.listeners = {};
    this.props = {};
    this.className = 'dbgr-card';
    this.rect = rect || { left: 100, top: 200, width: 200, height: 100 };
    this.style = {
      setProperty: (k, v) => {
        this.props[k] = v;
      },
    };
  }
  addEventListener(type, fn) {
    this.listeners[type] = fn;
  }
  getBoundingClientRect() {
    return this.rect;
  }
  fire(type, event) {
    if (this.listeners[type]) {
      this.listeners[type](event || {});
    }
  }
}

function load(options) {
  const opts = Object.assign({ cards: [new Card()], reduced: false, matchMedia: true, modern: true }, options || {});
  const media = {
    matches: opts.reduced,
    handlers: [],
    addEventListener(type, fn) {
      if (type === 'change') {
        this.handlers.push(fn);
      }
    },
    flip(matches) {
      this.matches = matches;
      this.handlers.forEach((fn) => fn());
    },
  };
  if (!opts.modern) {
    media.addEventListener = undefined;
    media.addListener = function (fn) {
      this.handlers.push(fn);
    };
  }
  const sandbox = {
    document: {
      readyState: 'complete',
      querySelectorAll: (sel) => (sel === '[data-dbgr-tilt]' ? opts.cards : []),
      addEventListener: () => {},
    },
    Math,
  };
  sandbox.window = sandbox;
  if (opts.matchMedia) {
    sandbox.window.matchMedia = (q) => {
      assert.strictEqual(q, '(prefers-reduced-motion: reduce)', 'asks the reduced-motion question');
      return media;
    };
  }
  vm.runInNewContext(SOURCE, sandbox);
  return { sandbox, media, cards: opts.cards };
}

test('compute: the centre is flat, the corners lean by the maximum, and anything outside the card is clamped', () => {
  const { sandbox } = load();
  const t = sandbox.window.DoughBossGrowthTilt;
  const rect = { left: 0, top: 0, width: 200, height: 100 };
  const centre = t.compute(rect, 100, 50);
  assert.strictEqual(centre.rx + 0, 0);
  assert.strictEqual(centre.ry + 0, 0);
  const topLeft = t.compute(rect, 0, 0);
  assert.strictEqual(topLeft.ry, -t.maxDegrees, 'left edge leans left');
  assert.strictEqual(topLeft.rx, t.maxDegrees, 'top edge leans toward the top');
  const bottomRight = t.compute(rect, 200, 100);
  assert.strictEqual(bottomRight.ry, t.maxDegrees);
  assert.strictEqual(bottomRight.rx, -t.maxDegrees);
  const far = t.compute(rect, 5000, -5000);
  assert.ok(Math.abs(far.ry) <= t.maxDegrees && Math.abs(far.rx) <= t.maxDegrees, 'clamped');
  assert.ok(t.maxDegrees > 0 && t.maxDegrees <= 12, 'a small lean');
});

test('compute: a zero-size or missing rectangle never produces NaN (negative control)', () => {
  const { sandbox } = load();
  const t = sandbox.window.DoughBossGrowthTilt;
  [null, undefined, { left: 0, top: 0, width: 0, height: 10 }, { left: 0, top: 0, width: 10, height: 0 }, { left: 0, top: 0 }].forEach((rect) => {
    const r = t.compute(rect, 5, 5);
    assert.strictEqual(r.rx, 0);
    assert.strictEqual(r.ry, 0);
  });
});

test('a mouse or pen over a card sets the two custom properties; leaving resets them; the markup is never touched', () => {
  const card = new Card();
  load({ cards: [card] });
  card.fire('pointermove', { pointerType: 'mouse', clientX: 300, clientY: 200 }); // top-right corner
  assert.strictEqual(card.props['--dbgr-ry'], '8.00deg');
  assert.strictEqual(card.props['--dbgr-rx'], '8.00deg');
  card.fire('pointermove', { pointerType: 'pen', clientX: 200, clientY: 250 }); // centre
  assert.strictEqual(card.props['--dbgr-ry'], '0.00deg');
  card.fire('pointerleave');
  assert.strictEqual(card.props['--dbgr-rx'], '0deg');
  assert.strictEqual(card.props['--dbgr-ry'], '0deg');
  assert.strictEqual(card.className, 'dbgr-card', 'no class change while motion is allowed');
});

test('touch input never tilts', () => {
  const card = new Card();
  load({ cards: [card] });
  card.fire('pointermove', { pointerType: 'touch', clientX: 300, clientY: 200 });
  assert.deepStrictEqual(Object.keys(card.props), [], 'nothing was set by a touch');
});

test('reduced motion: the cards are flat from the start (class added, nothing set on pointer move)', () => {
  const card = new Card();
  load({ cards: [card], reduced: true });
  assert.match(card.className, /dbgr-tilt--flat/, 'flat class added');
  card.props = {};
  card.fire('pointermove', { pointerType: 'mouse', clientX: 300, clientY: 200 });
  assert.deepStrictEqual(Object.keys(card.props), [], 'no lean under reduced motion');
});

test('reduced motion is read live: turning it on flattens the cards at once, turning it off lets them lean again', () => {
  const card = new Card();
  const { media } = load({ cards: [card] });
  card.fire('pointermove', { pointerType: 'mouse', clientX: 300, clientY: 200 });
  assert.strictEqual(card.props['--dbgr-ry'], '8.00deg', 'leans while allowed');
  media.flip(true);
  assert.match(card.className, /dbgr-tilt--flat/, 'flattened');
  assert.strictEqual(card.props['--dbgr-ry'], '0deg', 'and reset');
  card.fire('pointermove', { pointerType: 'mouse', clientX: 100, clientY: 200 });
  assert.strictEqual(card.props['--dbgr-ry'], '0deg', 'no lean after the change');
  media.flip(false);
  assert.doesNotMatch(card.className, /dbgr-tilt--flat/, 'flat class removed');
  card.fire('pointermove', { pointerType: 'mouse', clientX: 100, clientY: 200 });
  assert.strictEqual(card.props['--dbgr-ry'], '-8.00deg', 'leans again');
});

test('older browsers: addListener is used when addEventListener is missing; no matchMedia at all still works; no cards is harmless', () => {
  const card = new Card();
  const old = load({ cards: [card], modern: false });
  old.media.flip(true);
  assert.match(card.className, /dbgr-tilt--flat/, 'the legacy listener works');
  const bare = new Card();
  load({ cards: [bare], matchMedia: false });
  bare.fire('pointermove', { pointerType: 'mouse', clientX: 300, clientY: 200 });
  assert.strictEqual(bare.props['--dbgr-ry'], '8.00deg', 'tilts when matchMedia does not exist (the CSS media query still flattens it)');
  assert.doesNotThrow(() => load({ cards: [] }), 'no cards: nothing to do');
});

test('source and CSS: ES5, transform-only, no markup injection, and the stylesheet is flat under reduced motion on its own', () => {
  assert.doesNotMatch(SOURCE, /innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\b|new Function/);
  assert.doesNotMatch(SOURCE, /\bwidth\s*=|\bheight\s*=|\.top\s*=|\.left\s*=/, 'no layout properties are written');
  assert.match(SOURCE, /'use strict'/);
  assert.match(CSS, /@media \(prefers-reduced-motion: reduce\)\s*\{[^}]*\.dbgr-card__face\s*\{[^}]*transform:\s*none\s*!important/s, 'reduced motion is flat in CSS even if the script never runs');
  assert.match(CSS, /\.dbgr-tilt--flat \.dbgr-card__face\s*\{[^}]*transform:\s*none/s, 'the flat class is flat');
  assert.doesNotMatch(CSS + SOURCE, /mini(s)/i, 'no working name');
});
