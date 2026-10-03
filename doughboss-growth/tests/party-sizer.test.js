'use strict';
/*
 * WP-07 node tests for public/js/dbgr-party-sizer.js: the catering party-pack sizer.
 *
 * The browser script is an ES5 IIFE, so each test runs the REAL file inside a node:vm context with a small fake DOM (just
 * enough tree to read back what was rendered) and a fake XMLHttpRequest (every request is recorded, every answer is
 * scripted). No jsdom, no network. The invariant under test: every number on screen came from core (a serve range or a
 * quote total), from the visitor (the head count), or from a confirmed claim passed in by the server. A missing or
 * malformed quote shows no price at all.
 *
 * Dependency-free: node:test and node:assert only. The ES5 gate test is SKIPPED with a visible reason when acorn is
 * missing, never passed.
 */
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

const PLUGIN_DIR = path.resolve(__dirname, '..');
const SOURCE = fs.readFileSync(path.join(PLUGIN_DIR, 'public', 'js', 'dbgr-party-sizer.js'), 'utf8');
const GATE = path.join(PLUGIN_DIR, 'scripts', 'es5-check.mjs');
const ACORN = process.env.ACORN_PATH || path.resolve(PLUGIN_DIR, '..', 'web', 'node_modules', 'acorn');
const HAVE_ACORN = fs.existsSync(path.join(ACORN, 'package.json'));
const QUOTE_URL = 'https://doughboss.test/wp-json/doughboss/v1/catering/quote';

/* ---------------------------------------------------------------- fake DOM */

class Node {
  constructor(tag) {
    this.tag = tag;
    this.children = [];
    this.textContent = '';
    this.className = '';
    this.listeners = {};
  }
  get firstChild() {
    return this.children.length ? this.children[0] : null;
  }
  appendChild(child) {
    this.children.push(child);
    return child;
  }
  removeChild(child) {
    this.children.splice(this.children.indexOf(child), 1);
    return child;
  }
  addEventListener(type, fn) {
    (this.listeners[type] = this.listeners[type] || []).push(fn);
  }
  dispatch(type, event) {
    (this.listeners[type] || []).slice().forEach((fn) => fn(event || { type }));
  }
  /** Every text in this subtree, in order. */
  texts() {
    const out = [];
    if (this.textContent) {
      out.push(this.textContent);
    }
    this.children.forEach((c) => out.push(...c.texts()));
    return out;
  }
  /** Every node in this subtree with the class. */
  byClass(name) {
    const out = [];
    if ((' ' + this.className + ' ').indexOf(' ' + name + ' ') !== -1) {
      out.push(this);
    }
    this.children.forEach((c) => out.push(...c.byClass(name)));
    return out;
  }
}

function makeRoot() {
  const root = new Node('div');
  const form = new Node('form');
  const input = new Node('input');
  input.value = '';
  const result = new Node('div');
  root.querySelector = (selector) => {
    if (selector === 'form') {
      return form;
    }
    if (selector === 'input[name="guests"]') {
      return input;
    }
    if (selector === '[data-dbgr-sizer-result]') {
      return result;
    }
    return null;
  };
  root.form = form;
  root.input = input;
  root.result = result;
  return root;
}

const PACKAGES = [
  { id: 11, name: 'Small Platter', serves_min: 8, serves_max: 12 },
  { id: 13, name: 'Medium Platter', serves_min: 13, serves_max: 24 },
  { id: 12, name: 'Large Platter', serves_min: 25, serves_max: 40 },
];
const STRINGS = {
  guests: 'GUESTS_MSG', tooMany: 'TOOMANY_MSG', loading: 'LOADING_MSG', unavailable: 'UNAVAILABLE_MSG', recommended: 'SUGGESTED',
  next: 'NEXTUP', serves: 'Serves', to: 'to', forGuests: 'For', guestsWord: 'guests', indicative: 'INDICATIVE_MSG',
};

/** Run the real script. Returns the page controls. */
function load(options) {
  const opts = Object.assign({ config: true, roots: [], packages: PACKAGES, guidance: '' }, options || {});
  const requests = [];
  const doc = { readyState: 'complete' };
  doc.querySelectorAll = (selector) => (selector === '[data-dbgr-sizer]' ? opts.roots : []);
  doc.createElement = (tag) => new Node(tag);
  class FakeXHR {
    constructor() {
      this.headers = {};
      this.readyState = 0;
      this.status = 0;
      this.responseText = '';
      requests.push(this);
    }
    open(method, url) {
      this.method = method;
      this.url = url;
    }
    setRequestHeader(k, v) {
      this.headers[k] = v;
    }
    send(body) {
      this.body = body;
    }
    answer(status, data) {
      this.readyState = 4;
      this.status = status;
      this.responseText = typeof data === 'string' ? data : data === null ? '' : JSON.stringify(data);
      this.onreadystatechange();
    }
  }
  const sandbox = { document: doc, XMLHttpRequest: FakeXHR, JSON, String, Math, Object, parseInt, isFinite, encodeURIComponent };
  sandbox.window = sandbox;
  if (opts.config) {
    sandbox.window.DoughBossGrowthSizer = {
      quoteUrl: QUOTE_URL,
      maxGuests: 1000,
      packages: opts.packages,
      guidance: opts.guidance,
      strings: STRINGS,
    };
  }
  vm.runInNewContext(SOURCE, sandbox);
  return { requests, doc, sandbox };
}

function ask(root, guests) {
  root.input.value = String(guests);
  let prevented = false;
  root.form.dispatch('submit', { type: 'submit', preventDefault: () => { prevented = true; } });
  return prevented;
}

function parsed(req) {
  const u = new URL(req.url);
  return { base: u.origin + u.pathname, package_id: u.searchParams.get('package_id'), guest_count: u.searchParams.get('guest_count'), order_type: u.searchParams.get('order_type') };
}

/** Every digit run in the rendered text. */
function numbersOn(root) {
  const found = [];
  root.result.texts().forEach((t) => {
    (t.match(/[0-9]+(?:\.[0-9]+)?/g) || []).forEach((n) => found.push(n));
  });
  return found;
}

const QUOTE_OK = (total) => ({ subtotal: total, delivery_fee: 0, total, deposit_pct: 50, deposit: total / 2, balance: total / 2, lead_days: 3, currency: 'AUD' });

/* ------------------------------------------------------------------ tests */

test('party-sizer.js: sends nothing on load; without a configuration the form is inert', () => {
  const root = makeRoot();
  const page = load({ roots: [root] });
  assert.strictEqual(page.requests.length, 0);
  const inert = makeRoot();
  const none = load({ config: false, roots: [inert] });
  assert.strictEqual(ask(inert, 20), true, 'the browser is stopped from posting the form to the page');
  assert.strictEqual(none.requests.length, 0);
  assert.deepStrictEqual(inert.result.texts(), [], 'nothing rendered');
});

test('party-sizer.js: picks the smallest package that covers the count, then the next size up (overquote, never underquote)', () => {
  const cases = [
    { guests: 1, first: 11, next: 13 },
    { guests: 12, first: 11, next: 13 },
    { guests: 13, first: 13, next: 12 },
    { guests: 24, first: 13, next: 12 },
    { guests: 25, first: 12, next: null },
    { guests: 40, first: 12, next: null },
    { guests: 41, first: 12, next: null },
    { guests: 999, first: 12, next: null },
  ];
  cases.forEach((c) => {
    const root = makeRoot();
    const page = load({ roots: [root] });
    assert.strictEqual(ask(root, c.guests), true);
    assert.strictEqual(page.requests.length, 1, c.guests + ': one request first');
    const q = parsed(page.requests[0]);
    assert.strictEqual(q.base, QUOTE_URL);
    assert.strictEqual(q.package_id, String(c.first), c.guests + ': package');
    assert.strictEqual(q.guest_count, String(c.guests), c.guests + ': guests');
    assert.strictEqual(q.order_type, 'pickup');
    assert.deepStrictEqual(root.result.texts(), ['LOADING_MSG'], c.guests + ': loading shown');
    page.requests[0].answer(200, QUOTE_OK(100));
    if (c.next === null) {
      assert.strictEqual(page.requests.length, 1, c.guests + ': nothing larger to ask about');
    } else {
      assert.strictEqual(page.requests.length, 2, c.guests + ': the next size up is quoted too');
      assert.strictEqual(parsed(page.requests[1]).package_id, String(c.next));
      page.requests[1].answer(200, QUOTE_OK(200));
    }
  });
});

test('party-sizer.js: every number on screen is the visitor\'s head count, core\'s serve range or core\'s quote total', () => {
  const root = makeRoot();
  const page = load({ roots: [root] });
  ask(root, 20);
  page.requests[0].answer(200, QUOTE_OK(187.5));
  page.requests[1].answer(200, QUOTE_OK(412));
  const texts = root.result.texts();
  assert.deepStrictEqual(texts, [
    'For 20 guests',
    'SUGGESTED', 'Medium Platter', 'Serves 13 to 24', '$187.50',
    'NEXTUP', 'Large Platter', 'Serves 25 to 40', '$412.00',
    'INDICATIVE_MSG',
  ]);
  const allowed = new Set(['20', '13', '24', '25', '40', '187.50', '412.00']);
  numbersOn(root).forEach((n) => assert.ok(allowed.has(n), 'unexpected number on screen: ' + n));
  // Not the deposit, the balance, the lead days or the deposit percentage that the same answer carried.
  const shown = texts.join(' ');
  assert.doesNotMatch(shown, /93\.75|206|50%|lead/i, 'no deposit, balance, percentage or lead time');
});

test('party-sizer.js: package names are written with textContent; a hostile name stays text', () => {
  const root = makeRoot();
  const evil = [{ id: 11, name: '<img src=x onerror=alert(1)>', serves_min: 1, serves_max: 50 }];
  const page = load({ roots: [root], packages: evil });
  ask(root, 5);
  page.requests[0].answer(200, QUOTE_OK(60));
  assert.ok(root.result.texts().indexOf('<img src=x onerror=alert(1)>') !== -1, 'rendered as text');
  assert.strictEqual(root.result.byClass('dbgr-sizer__name')[0].tag, 'p', 'inside a paragraph element, never parsed as markup');
  assert.doesNotMatch(SOURCE, /innerHTML/);
});

test('party-sizer.js: a quote that is missing, malformed, zero, negative or oversized shows NO price (fail closed)', () => {
  const bad = [
    { status: 500, data: null },
    { status: 400, data: { code: 'doughboss_catering_package_unavailable', message: 'x' } },
    { status: 0, data: null },
    { status: 200, data: 'not json' },
    { status: 200, data: [] },
    { status: 200, data: {} },
    { status: 200, data: { total: 0 } },
    { status: 200, data: { total: -5 } },
    { status: 200, data: { total: '120.00' } },
    { status: 200, data: { total: null } },
    { status: 200, data: { total: 1e9 } },
    { status: 200, data: { total: 1.7976931348623157e308 * 10 } },
  ];
  bad.forEach((c, i) => {
    const root = makeRoot();
    const page = load({ roots: [root] });
    ask(root, 20);
    page.requests[0].answer(c.status, c.data);
    assert.deepStrictEqual(root.result.texts(), ['UNAVAILABLE_MSG'], 'case ' + i + ': only the unavailable message');
    assert.deepStrictEqual(numbersOn(root), [], 'case ' + i + ': no number at all');
    assert.strictEqual(page.requests.length, 1, 'case ' + i + ': no further request');
  });
});

test('party-sizer.js: if the next-size-up quote fails, the suggested package still shows and the failed one shows no price', () => {
  const root = makeRoot();
  const page = load({ roots: [root] });
  ask(root, 20);
  page.requests[0].answer(200, QUOTE_OK(187.5));
  page.requests[1].answer(500, null);
  const texts = root.result.texts();
  assert.ok(texts.indexOf('$187.50') !== -1, 'suggested package priced');
  assert.ok(texts.indexOf('Large Platter') === -1, 'the unquoted package is not shown');
});

test('party-sizer.js: bad head counts are refused before any request', () => {
  const cases = [['', 'GUESTS_MSG'], ['0', 'GUESTS_MSG'], ['-3', 'GUESTS_MSG'], ['2.5', 'GUESTS_MSG'], ['abc', 'GUESTS_MSG'], ['1e2', 'GUESTS_MSG'], ['1000000000', 'GUESTS_MSG'], ['1001', 'TOOMANY_MSG']];
  cases.forEach((c) => {
    const root = makeRoot();
    const page = load({ roots: [root] });
    ask(root, c[0]);
    assert.strictEqual(page.requests.length, 0, JSON.stringify(c[0]) + ': no request');
    assert.deepStrictEqual(root.result.texts(), [c[1]], JSON.stringify(c[0]) + ': message');
  });
  const ok = makeRoot();
  const page = load({ roots: [ok] });
  ask(ok, 1000);
  assert.strictEqual(page.requests.length, 1, 'the ceiling itself is allowed (negative control)');
});

test('party-sizer.js: no packages from the server means no price, and the guidance shows only when the server sent a confirmed claim', () => {
  const root = makeRoot();
  const page = load({ roots: [root], packages: [] });
  ask(root, 20);
  assert.strictEqual(page.requests.length, 0);
  assert.deepStrictEqual(root.result.texts(), ['UNAVAILABLE_MSG']);

  const plainRoot = makeRoot();
  const plainPage = load({ roots: [plainRoot], guidance: '' });
  ask(plainRoot, 30);
  plainPage.requests[0].answer(200, QUOTE_OK(100));
  assert.strictEqual(plainRoot.result.byClass('dbgr-sizer__guidance').length, 0, 'no guidance without a confirmed claim');

  const withRoot = makeRoot();
  const withPage = load({ roots: [withRoot], guidance: 'CONFIRMED GUIDANCE TEXT' });
  ask(withRoot, 30);
  withPage.requests[0].answer(200, QUOTE_OK(100));
  assert.deepStrictEqual(withRoot.result.byClass('dbgr-sizer__guidance').map((n) => n.textContent), ['CONFIRMED GUIDANCE TEXT']);

  // Malformed package entries are ignored, not rendered.
  const junk = makeRoot();
  const junkPage = load({ roots: [junk], packages: [{ id: 'x', name: 'A', serves_min: 1, serves_max: 5 }, { id: 5, name: '', serves_min: 1, serves_max: 5 }, { id: 6, name: 'B', serves_min: 1, serves_max: 0 }, null] });
  ask(junk, 3);
  assert.strictEqual(junkPage.requests.length, 0, 'no usable package, no request');
});

test('party-sizer.js: a newer question wins; a stale answer is ignored', () => {
  const root = makeRoot();
  const page = load({ roots: [root] });
  ask(root, 5); // small
  ask(root, 30); // large
  page.requests[0].answer(200, QUOTE_OK(111)); // stale
  assert.deepStrictEqual(root.result.texts(), ['LOADING_MSG'], 'the stale answer did not render');
  page.requests[1].answer(200, QUOTE_OK(333));
  assert.ok(root.result.texts().indexOf('$333.00') !== -1, 'the latest answer renders');
  assert.ok(root.result.texts().indexOf('$111.00') === -1, 'the stale price never shows');
});

test('party-sizer.js: a non-AUD currency is shown with its code, never a guessed symbol', () => {
  const root = makeRoot();
  const page = load({ roots: [root], packages: [PACKAGES[2]] });
  ask(root, 30);
  page.requests[0].answer(200, Object.assign(QUOTE_OK(100), { currency: 'nzd' }));
  assert.ok(root.result.texts().indexOf('NZD 100.00') !== -1);
});

test('party-sizer.js source: ES5 only, no markup injection, no storage, no hard-coded host, no price literal, no working name', () => {
  assert.doesNotMatch(SOURCE, /innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\b|new Function/);
  assert.doesNotMatch(SOURCE, /localStorage|sessionStorage|document\.cookie|indexedDB/, 'nothing is stored in the browser');
  assert.doesNotMatch(SOURCE, /https?:\/\//, 'no hard-coded address');
  assert.doesNotMatch(SOURCE, /\b[0-9]+\.[0-9]{2}\b/, 'no price literal in the script');
  assert.doesNotMatch(SOURCE, new RegExp('mini' + 's', 'i'), 'no working name');
  assert.match(SOURCE, /textContent/);
  assert.match(SOURCE, /'use strict'/);
});

test('party-sizer.js: passes the ES5 gate; a planted template literal in a copy fails it (negative control)', { skip: HAVE_ACORN ? false : 'acorn not found (set ACORN_PATH to an acorn package directory)' }, () => {
  const env = Object.assign({}, process.env, { ACORN_PATH: ACORN });
  const ok = spawnSync(process.execPath, [GATE, path.join(PLUGIN_DIR, 'public')], { encoding: 'utf8', env });
  assert.strictEqual(ok.status, 0, ok.stdout + ok.stderr);
  const tmp = fs.mkdtempSync(path.join(require('node:os').tmpdir(), 'dbgr-sz-es5-'));
  fs.mkdirSync(path.join(tmp, 'public', 'js'), { recursive: true });
  fs.writeFileSync(path.join(tmp, 'public', 'js', 'planted.js'), SOURCE.replace('var cfg = window', 'var tpl = `x`; var cfg = window'));
  const bad = spawnSync(process.execPath, [GATE, path.join(tmp, 'public')], { encoding: 'utf8', env });
  assert.strictEqual(bad.status, 1, 'the planted template literal is a violation');
});
