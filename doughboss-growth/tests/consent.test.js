'use strict';
/*
 * WP-03 node tests for public/js/dbgr-consent.js (the banner, the dbgr_consent cookie, Consent Mode updates and the
 * core doughboss:consent event) and for the inline Consent Mode default printed by the PHP Tags class.
 *
 * The browser scripts are ES5 IIFEs that read window/document, so each test runs the REAL file inside a node:vm context
 * with a small fake DOM (an event emitter per element, a cookie jar, focus tracking). No jsdom and no network.
 * The real rendered page, axe and keyboard behaviour are covered by web/scripts/wp-local/growth/wp03-consent.mjs.
 *
 * Tests that need php (the inline snippet) or acorn (the ES5 gate) are SKIPPED with a visible reason when missing.
 */
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

const PLUGIN_DIR = path.resolve(__dirname, '..');
const CONSENT_JS = fs.readFileSync(path.join(PLUGIN_DIR, 'public', 'js', 'dbgr-consent.js'), 'utf8');
const TAGS_PHP = path.join(PLUGIN_DIR, 'includes', 'consent', 'class-doughboss-growth-tags.php');
const GATE = path.join(PLUGIN_DIR, 'scripts', 'es5-check.mjs');

/* ---------------------------------------------------------------- fake DOM */

class FakeEvent {
  constructor(type, init) {
    this.type = type;
    this.detail = init ? init.detail : undefined;
    this.target = null;
    this.defaultPrevented = false;
  }
  preventDefault() {
    this.defaultPrevented = true;
  }
}

class Emitter {
  constructor() {
    this.listeners = {};
  }
  addEventListener(type, fn) {
    (this.listeners[type] = this.listeners[type] || []).push(fn);
  }
  dispatchEvent(event) {
    if (!event.target) {
      event.target = this;
    }
    (this.listeners[event.type] || []).slice().forEach((fn) => fn.call(this, event));
    return true;
  }
}

class FakeElement extends Emitter {
  constructor(doc, id, attrs) {
    super();
    this.doc = doc;
    this.id = id;
    this.attrs = Object.assign({}, attrs || {});
    this.children = [];
    this.parentNode = null;
    this.checked = false;
  }
  add(child) {
    child.parentNode = this;
    this.children.push(child);
    return child;
  }
  setAttribute(name, value) {
    this.attrs[name] = String(value);
  }
  getAttribute(name) {
    return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null;
  }
  removeAttribute(name) {
    delete this.attrs[name];
  }
  hasAttribute(name) {
    return Object.prototype.hasOwnProperty.call(this.attrs, name);
  }
  focus() {
    this.doc.activeElement = this;
    this.doc.focusLog.push(this.id);
  }
  contains(node) {
    for (let n = node; n; n = n.parentNode) {
      if (n === this) {
        return true;
      }
    }
    return false;
  }
  walk(visit) {
    visit(this);
    this.children.forEach((child) => child.walk(visit));
  }
  querySelector(selector) {
    const match = /^\[data-dbgr-action="([a-z]+)"\]$/.exec(selector);
    let found = null;
    this.walk((node) => {
      if (!found && match && node.getAttribute('data-dbgr-action') === match[1]) {
        found = node;
      }
    });
    return found;
  }
}

/** Build a window + document with the server-rendered banner (same ids and data attributes as the PHP markup). */
function makeEnv(options) {
  const opts = Object.assign({ config: { consentVersion: '1', mode: 'deny', gtm: true }, banner: true, cookie: '', https: true, readyState: 'complete' }, options || {});
  const doc = new Emitter();
  doc.focusLog = [];
  doc.cookieWrites = [];
  doc.cookieJar = opts.cookie; /* raw value of dbgr_consent, or '' */
  doc.cookieThrows = false;
  doc.readyState = opts.readyState;
  doc.body = new FakeElement(doc, 'body');
  doc.body.parentNode = doc;
  doc.activeElement = doc.body;
  doc.contains = (node) => {
    for (let n = node; n; n = n.parentNode) {
      if (n === doc) {
        return true;
      }
    }
    return false;
  };
  Object.defineProperty(doc, 'cookie', {
    get() {
      return doc.cookieJar ? 'a=b; dbgr_consent=' + doc.cookieJar + '; z=y' : 'a=b; z=y';
    },
    set(value) {
      if (doc.cookieThrows) {
        throw new Error('cookies blocked');
      }
      doc.cookieWrites.push(value);
      const m = /^dbgr_consent=([^;]*)/.exec(value);
      if (m) {
        doc.cookieJar = m[1];
      }
    },
  });
  const byId = {};
  if (opts.banner) {
    const root = doc.body.add(new FakeElement(doc, 'dbgr-consent', { hidden: '' }));
    const panel = root.add(new FakeElement(doc, 'dbgr-consent-panel', { hidden: '' }));
    byId['dbgr-consent'] = root;
    byId['dbgr-consent-panel'] = panel;
    byId['dbgr-consent-m'] = panel.add(new FakeElement(doc, 'dbgr-consent-m'));
    byId['dbgr-consent-a'] = panel.add(new FakeElement(doc, 'dbgr-consent-a'));
    byId.save = panel.add(new FakeElement(doc, 'btn-save', { 'data-dbgr-action': 'save' }));
    byId.accept = root.add(new FakeElement(doc, 'btn-accept', { 'data-dbgr-action': 'accept' }));
    byId.reject = root.add(new FakeElement(doc, 'btn-reject', { 'data-dbgr-action': 'reject' }));
    byId.choose = root.add(new FakeElement(doc, 'btn-choose', { 'data-dbgr-action': 'choose', 'aria-expanded': 'false' }));
    byId['dbgr-consent-reopen'] = doc.body.add(new FakeElement(doc, 'dbgr-consent-reopen', { hidden: '', 'data-dbgr-consent-open': '1' }));
  }
  doc.getElementById = (id) => byId[id] || null;
  doc.createEvent = () => {
    throw new Error('createEvent not used in this environment');
  };
  doc.querySelector = () => null;

  const win = { document: doc, location: { protocol: opts.https ? 'https:' : 'http:' }, CustomEvent: FakeEvent };
  win.window = win;
  if (opts.config) {
    win.DoughBossGrowthConfig = opts.config;
  }
  doc.fired = [];
  ['doughboss:consent', 'doughboss-growth:consent-changed'].forEach((type) => {
    doc.addEventListener(type, (event) => doc.fired.push({ type: event.type, detail: JSON.parse(JSON.stringify(event.detail)) }));
  });
  return { win, doc, els: byId };
}

function run(env) {
  const context = vm.createContext({ window: env.win, document: env.doc });
  vm.runInContext(CONSENT_JS, context, { filename: 'dbgr-consent.js' });
  return env;
}

/** Click an element and let the event bubble to its ancestors and the document, as a browser would. */
function click(env, el) {
  const event = new FakeEvent('click');
  event.target = el;
  for (let n = el; n; n = n.parentNode) {
    if (n === env.doc) {
      break;
    }
    (n.listeners.click || []).slice().forEach((fn) => fn.call(n, event));
  }
  (env.doc.listeners.click || []).slice().forEach((fn) => fn.call(env.doc, event));
  return event;
}

function key(env, el, name) {
  const event = new FakeEvent('keydown');
  event.target = el;
  event.key = name;
  for (let n = el; n; n = n.parentNode) {
    if (n === env.doc) {
      break;
    }
    (n.listeners.keydown || []).slice().forEach((fn) => fn.call(n, event));
  }
}

const plain = (value) => JSON.parse(JSON.stringify(value));
const isHidden = (el) => el.hasAttribute('hidden');
const gtagCalls = (env) => plain(Array.prototype.slice.call(env.win.dataLayer || []).map((entry) => Array.prototype.slice.call(entry)));
const cookieValue = (env) => JSON.parse(decodeURIComponent(env.doc.cookieJar));
const encodeCookie = (obj) => encodeURIComponent(JSON.stringify(obj));

/* ---------------------------------------------------------------- tests */

test('without configuration the script does nothing at all (no global, no event, no cookie)', () => {
  const env = run(makeEnv({ config: null }));
  assert.strictEqual(env.win.DoughBossGrowth, undefined);
  assert.deepStrictEqual(env.doc.fired, []);
  assert.deepStrictEqual(env.doc.cookieWrites, []);
  const empty = run(makeEnv({ config: { consentVersion: '', mode: 'deny', gtm: true } }));
  assert.strictEqual(empty.win.DoughBossGrowth, undefined, 'an empty wording version is also inert');
});

test('first visit, deny mode: banner shown and focused, nothing stored, nothing granted, defaults announced', () => {
  const env = run(makeEnv());
  assert.strictEqual(isHidden(env.els['dbgr-consent']), false, 'banner visible');
  assert.strictEqual(isHidden(env.els['dbgr-consent-reopen']), true, 'reopen hidden while the banner is open');
  assert.strictEqual(isHidden(env.els['dbgr-consent-panel']), true, 'choices panel collapsed');
  assert.strictEqual(env.doc.activeElement, env.els['dbgr-consent'], 'focus moved to the banner');
  assert.deepStrictEqual(env.doc.cookieWrites, [], 'no cookie before a choice');
  assert.deepStrictEqual(env.win.dataLayer, undefined, 'no consent update before a choice (the head snippet owns the default)');
  assert.deepStrictEqual(env.doc.fired, [
    { type: 'doughboss:consent', detail: { measurement: false, advertising: false, version: '1' } },
    { type: 'doughboss-growth:consent-changed', detail: { measurement: false, advertising: false, version: '1', chosen: false } },
  ]);
  assert.deepStrictEqual(plain(env.win.DoughBossGrowth.consent.get()), { measurement: false, advertising: false, chosen: false, version: '1' });
});

test('Accept all: cookie {v,m,a,ts} for 180 days, Consent Mode update granted, both events, banner closes, focus to Privacy choices', () => {
  const env = run(makeEnv());
  env.doc.fired.length = 0;
  click(env, env.els.accept);
  const stored = cookieValue(env);
  assert.deepStrictEqual(Object.keys(stored), ['v', 'm', 'a', 'ts']);
  assert.strictEqual(stored.v, '1');
  assert.strictEqual(stored.m, 1);
  assert.strictEqual(stored.a, 1);
  assert.ok(Number.isInteger(stored.ts) && stored.ts > 1700000000, 'ts is UNIX seconds');
  const write = env.doc.cookieWrites[0];
  assert.match(write, /; Max-Age=15552000; Path=\/; SameSite=Lax; Secure$/, 'attributes: 180 days, path, SameSite, Secure on https');
  assert.deepStrictEqual(gtagCalls(env), [['consent', 'update', { ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted', analytics_storage: 'granted' }]]);
  assert.deepStrictEqual(env.doc.fired, [
    { type: 'doughboss:consent', detail: { measurement: true, advertising: true, version: '1' } },
    { type: 'doughboss-growth:consent-changed', detail: { measurement: true, advertising: true, version: '1', chosen: true } },
  ]);
  assert.strictEqual(isHidden(env.els['dbgr-consent']), true);
  assert.strictEqual(isHidden(env.els['dbgr-consent-reopen']), false);
  assert.strictEqual(env.doc.activeElement, env.els['dbgr-consent-reopen'], 'focus is not lost when the banner closes');
});

test('Reject all: cookie m=0 a=0, everything denied, no Secure flag on plain http', () => {
  const env = run(makeEnv({ https: false }));
  click(env, env.els.reject);
  const stored = cookieValue(env);
  assert.strictEqual(stored.m, 0);
  assert.strictEqual(stored.a, 0);
  assert.doesNotMatch(env.doc.cookieWrites[0], /Secure/);
  assert.deepStrictEqual(gtagCalls(env), [['consent', 'update', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied' }]]);
  assert.deepStrictEqual(plain(env.win.DoughBossGrowth.consent.get()), { measurement: false, advertising: false, chosen: true, version: '1' });
});

test('REPLAY: a stored Reject persists across a reload (no banner, reopen button, no rewrite, events announce the stored choice)', () => {
  const first = run(makeEnv());
  click(first, first.els.reject);
  const reload = run(makeEnv({ cookie: first.doc.cookieJar }));
  assert.strictEqual(isHidden(reload.els['dbgr-consent']), true, 'banner stays closed');
  assert.strictEqual(isHidden(reload.els['dbgr-consent-reopen']), false, 'Privacy choices is available');
  assert.deepStrictEqual(reload.doc.cookieWrites, [], 'replay does not rewrite the cookie');
  assert.deepStrictEqual(reload.doc.fired[0], { type: 'doughboss:consent', detail: { measurement: false, advertising: false, version: '1' } });
  assert.strictEqual(reload.doc.fired[1].detail.chosen, true);
});

test('REPLAY: a stored Accept is replayed to core on load (doughboss:consent) before any click', () => {
  const env = run(makeEnv({ cookie: encodeCookie({ v: '1', m: 1, a: 1, ts: 1790899200 }) }));
  assert.deepStrictEqual(env.doc.fired[0], { type: 'doughboss:consent', detail: { measurement: true, advertising: true, version: '1' } });
  assert.deepStrictEqual(plain(env.win.DoughBossGrowth.consent.get()), { measurement: true, advertising: true, chosen: true, version: '1' });
  assert.strictEqual(isHidden(env.els['dbgr-consent']), true);
});

test('replay waits for DOMContentLoaded when the script runs while the document is still loading', () => {
  const env = makeEnv({ readyState: 'loading', cookie: encodeCookie({ v: '1', m: 1, a: 0, ts: 1790899200 }) });
  run(env);
  assert.deepStrictEqual(env.doc.fired, [], 'nothing announced yet: core has not registered its listener');
  env.doc.dispatchEvent(new FakeEvent('DOMContentLoaded'));
  assert.strictEqual(env.doc.fired.length, 2, 'announced once the document is ready');
  assert.strictEqual(env.doc.fired[0].detail.measurement, true);
});

test('cookie validation: a new wording version, wrong types and garbage are NOT a choice (banner shows again, all denied)', () => {
  const bad = {
    'old wording version': encodeCookie({ v: '0', m: 1, a: 1, ts: 1790899200 }),
    'numeric version': encodeCookie({ v: 1, m: 1, a: 1, ts: 1790899200 }),
    'm is 2': encodeCookie({ v: '1', m: 2, a: 0, ts: 1790899200 }),
    'm is a string': encodeCookie({ v: '1', m: '1', a: 0, ts: 1790899200 }),
    'a is true': encodeCookie({ v: '1', m: 1, a: true, ts: 1790899200 }),
    'ts zero': encodeCookie({ v: '1', m: 1, a: 0, ts: 0 }),
    'ts string': encodeCookie({ v: '1', m: 1, a: 0, ts: '1790899200' }),
    'missing a': encodeCookie({ v: '1', m: 1, ts: 1790899200 }),
    'array': encodeCookie([1, 0, 1790899200]),
    'not json': 'm%3D1%26a%3D1',
    'bad percent-encoding': '%E0%A4%A',
    'too long': encodeCookie({ v: '1', m: 1, a: 1, ts: 1790899200, pad: 'x'.repeat(500) }),
  };
  Object.keys(bad).forEach((label) => {
    const env = run(makeEnv({ cookie: bad[label] }));
    assert.strictEqual(isHidden(env.els['dbgr-consent']), false, label + ': banner shown');
    assert.deepStrictEqual(plain(env.win.DoughBossGrowth.consent.get()), { measurement: false, advertising: false, chosen: false, version: '1' }, label + ': denied');
  });
  /* positive control: the same helper with a valid cookie is a choice */
  const good = run(makeEnv({ cookie: encodeCookie({ v: '1', m: 1, a: 0, ts: 1790899200 }) }));
  assert.strictEqual(good.win.DoughBossGrowth.consent.get().chosen, true);
});

test('Choose opens the panel (aria-expanded), Save stores exactly the ticked categories', () => {
  const env = run(makeEnv());
  click(env, env.els.choose);
  assert.strictEqual(isHidden(env.els['dbgr-consent-panel']), false);
  assert.strictEqual(env.els.choose.getAttribute('aria-expanded'), 'true');
  assert.strictEqual(env.els['dbgr-consent-m'].checked, false, 'categories start unticked: no pre-ticked consent');
  assert.strictEqual(env.els['dbgr-consent-a'].checked, false);
  env.els['dbgr-consent-m'].checked = true;
  click(env, env.els.save);
  const stored = cookieValue(env);
  assert.strictEqual(stored.m, 1);
  assert.strictEqual(stored.a, 0);
  assert.deepStrictEqual(plain(gtagCalls(env)[0][2]), { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'granted' });
  /* Choose again toggles the panel closed */
  const second = run(makeEnv());
  click(second, second.els.choose);
  click(second, second.els.choose);
  assert.strictEqual(isHidden(second.els['dbgr-consent-panel']), true);
  assert.strictEqual(second.els.choose.getAttribute('aria-expanded'), 'false');
});

test('Privacy choices reopens the banner with the panel showing the stored choice; Escape closes the panel, then the banner', () => {
  const env = run(makeEnv({ cookie: encodeCookie({ v: '1', m: 1, a: 0, ts: 1790899200 }) }));
  const event = click(env, env.els['dbgr-consent-reopen']);
  assert.strictEqual(event.defaultPrevented, true);
  assert.strictEqual(isHidden(env.els['dbgr-consent']), false);
  assert.strictEqual(isHidden(env.els['dbgr-consent-panel']), false);
  assert.strictEqual(env.els['dbgr-consent-m'].checked, true, 'panel reflects the stored measurement choice');
  assert.strictEqual(env.els['dbgr-consent-a'].checked, false);
  key(env, env.els['dbgr-consent-m'], 'Escape');
  assert.strictEqual(isHidden(env.els['dbgr-consent-panel']), true, 'first Escape closes the panel');
  assert.strictEqual(env.doc.activeElement, env.els.choose, 'focus returns to Choose');
  key(env, env.els.choose, 'Escape');
  assert.strictEqual(isHidden(env.els['dbgr-consent']), true, 'second Escape closes a reopened banner (nothing changes)');
  assert.deepStrictEqual(env.doc.cookieWrites, [], 'closing does not rewrite the choice');
  assert.strictEqual(env.doc.activeElement, env.els['dbgr-consent-reopen']);
});

test('Escape before any choice does NOT dismiss the banner (dismissal is not consent)', () => {
  const env = run(makeEnv());
  key(env, env.els.accept, 'Escape');
  assert.strictEqual(isHidden(env.els['dbgr-consent']), false);
  assert.deepStrictEqual(env.doc.cookieWrites, []);
  assert.strictEqual(env.win.DoughBossGrowth.consent.get().chosen, false);
});

test('any element carrying data-dbgr-consent-open (for example a footer menu link) reopens the banner; DoughBossGrowth.consent.open() too', () => {
  const env = run(makeEnv({ cookie: encodeCookie({ v: '1', m: 0, a: 0, ts: 1790899200 }) }));
  const link = env.doc.body.add(new FakeElement(env.doc, 'menu-link', { 'data-dbgr-consent-open': '1' }));
  const inner = link.add(new FakeElement(env.doc, 'menu-link-text'));
  click(env, inner);
  assert.strictEqual(isHidden(env.els['dbgr-consent']), false, 'a click inside the marked element opens it');
  click(env, env.els.reject);
  env.win.DoughBossGrowth.consent.open();
  assert.strictEqual(isHidden(env.els['dbgr-consent']), false, 'open() works');
  const other = env.doc.body.add(new FakeElement(env.doc, 'plain'));
  click(env, env.els.reject);
  const untouched = click(env, other);
  assert.strictEqual(untouched.defaultPrevented, false, 'NEGATIVE CONTROL: an unrelated click is left alone');
  assert.strictEqual(isHidden(env.els['dbgr-consent']), true);
});

test('notice-and-opt-out mode: before a choice measurement is on and advertising off, and the banner is still shown', () => {
  const env = run(makeEnv({ config: { consentVersion: '1', mode: 'opt_out', gtm: true } }));
  assert.deepStrictEqual(plain(env.win.DoughBossGrowth.consent.get()), { measurement: true, advertising: false, chosen: false, version: '1' });
  assert.deepStrictEqual(env.doc.fired[0], { type: 'doughboss:consent', detail: { measurement: true, advertising: false, version: '1' } });
  assert.strictEqual(isHidden(env.els['dbgr-consent']), false);
  click(env, env.els.reject);
  assert.deepStrictEqual(plain(env.win.DoughBossGrowth.consent.get()), { measurement: false, advertising: false, chosen: true, version: '1' }, 'a Reject beats the default');
  /* an unknown mode is deny */
  const odd = run(makeEnv({ config: { consentVersion: '1', mode: 'allow_all', gtm: true } }));
  assert.strictEqual(odd.win.DoughBossGrowth.consent.get().measurement, false);
});

test('Tag Manager off: a choice is stored and announced but no gtag or dataLayer is touched', () => {
  const env = run(makeEnv({ config: { consentVersion: '1', mode: 'deny', gtm: false } }));
  click(env, env.els.accept);
  assert.strictEqual(env.win.dataLayer, undefined);
  assert.strictEqual(env.win.gtag, undefined);
  assert.strictEqual(cookieValue(env).m, 1);
  assert.strictEqual(env.doc.fired.length, 4);
});

test('blocked cookies: the choice still applies for the page view and nothing throws', () => {
  const env = run(makeEnv());
  env.doc.cookieThrows = true;
  click(env, env.els.accept);
  assert.deepStrictEqual(plain(env.win.DoughBossGrowth.consent.get()), { measurement: true, advertising: true, chosen: true, version: '1' });
});

test('no banner markup on the page (for example a theme that strips the footer): events still replay and nothing throws', () => {
  const env = run(makeEnv({ banner: false, cookie: encodeCookie({ v: '1', m: 1, a: 0, ts: 1790899200 }) }));
  assert.strictEqual(env.doc.fired.length, 2);
  assert.doesNotThrow(() => env.win.DoughBossGrowth.consent.open());
});

/* ---------------------------------------------------------------- the inline snippet printed by PHP */

function phpAvailable() {
  const probe = spawnSync('php', ['-r', 'echo 1;'], { encoding: 'utf8' });
  return probe.status === 0 && probe.stdout === '1';
}
const SKIP_PHP = phpAvailable() ? false : 'php binary not available';

function inlineSnippet(version, mode) {
  const code = 'define("ABSPATH","/"); require ' + JSON.stringify(TAGS_PHP) + '; echo DoughBoss_Growth_Tags::consent_default_script($argv[1], $argv[2]);';
  const result = spawnSync('php', ['-r', code, version, mode], { encoding: 'utf8' });
  assert.strictEqual(result.status, 0, result.stderr);
  return result.stdout;
}

function runInline(snippet, cookieRaw) {
  const doc = { cookie: cookieRaw ? 'x=1; dbgr_consent=' + cookieRaw : 'x=1' };
  const win = { document: doc };
  win.window = win;
  vm.runInContext(snippet, vm.createContext({ window: win, document: doc }), { filename: 'inline.js' });
  return win;
}

test('inline default: all four signals denied with wait_for_update 500, before any cookie logic (deny mode)', { skip: SKIP_PHP }, () => {
  const win = runInline(inlineSnippet('1', 'deny'), '');
  assert.deepStrictEqual(plain(Array.prototype.slice.call(win.dataLayer).map((e) => Array.prototype.slice.call(e))), [
    ['consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied', wait_for_update: 500 }],
  ]);
});

test('inline default, notice-and-opt-out: only analytics_storage starts granted', { skip: SKIP_PHP }, () => {
  const win = runInline(inlineSnippet('1', 'opt_out'), '');
  assert.deepStrictEqual(plain(Array.prototype.slice.call(win.dataLayer[0])), ['consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'granted', wait_for_update: 500 }]);
});

test('inline replay and dbgr-consent.js agree on EVERY cookie vector (same rules in both places)', { skip: SKIP_PHP }, () => {
  const vectors = [
    ['', null],
    [encodeCookie({ v: '1', m: 1, a: 1, ts: 1790899200 }), { ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted', analytics_storage: 'granted' }],
    [encodeCookie({ v: '1', m: 1, a: 0, ts: 1790899200 }), { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'granted' }],
    [encodeCookie({ v: '1', m: 0, a: 1, ts: 1790899200 }), { ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted', analytics_storage: 'denied' }],
    [encodeCookie({ v: '1', m: 0, a: 0, ts: 1790899200 }), { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied' }],
    [encodeCookie({ v: '2', m: 1, a: 1, ts: 1790899200 }), null],
    [encodeCookie({ v: 1, m: 1, a: 1, ts: 1790899200 }), null],
    [encodeCookie({ v: '1', m: 2, a: 1, ts: 1790899200 }), null],
    [encodeCookie({ v: '1', m: '1', a: 1, ts: 1790899200 }), null],
    [encodeCookie({ v: '1', m: 1, a: true, ts: 1790899200 }), null],
    [encodeCookie({ v: '1', m: 1, a: 1, ts: 0 }), null],
    [encodeCookie({ v: '1', m: 1, a: 1, ts: '1790899200' }), null],
    [encodeCookie({ v: '1', m: 1, ts: 1790899200 }), null],
    [encodeCookie([1, 1, 1790899200]), null],
    ['not-json', null],
    ['%E0%A4%A', null],
    [encodeCookie({ v: '1', m: 1, a: 1, ts: 1790899200, pad: 'x'.repeat(500) }), null],
  ];
  const snippet = inlineSnippet('1', 'deny');
  vectors.forEach(([raw, expected]) => {
    const win = runInline(snippet, raw);
    const updates = plain(Array.prototype.slice.call(win.dataLayer).map((e) => Array.prototype.slice.call(e))).filter((c) => c[1] === 'update');
    const env = run(makeEnv({ cookie: raw }));
    const chosen = env.win.DoughBossGrowth.consent.get().chosen;
    if (expected === null) {
      assert.deepStrictEqual(updates, [], 'inline: no update for ' + raw.slice(0, 40));
      assert.strictEqual(chosen, false, 'banner script: not a choice for ' + raw.slice(0, 40));
    } else {
      assert.deepStrictEqual(updates, [['consent', 'update', expected]], 'inline: update for ' + raw.slice(0, 40));
      assert.strictEqual(chosen, true, 'banner script: a choice for ' + raw.slice(0, 40));
      const got = env.win.DoughBossGrowth.consent.get();
      assert.strictEqual(got.measurement, expected.analytics_storage === 'granted');
      assert.strictEqual(got.advertising, expected.ad_storage === 'granted');
    }
  });
});

test('inline replay honours only the current wording version', { skip: SKIP_PHP }, () => {
  const stored = encodeCookie({ v: '1', m: 1, a: 1, ts: 1790899200 });
  const sameVersion = runInline(inlineSnippet('1', 'deny'), stored).dataLayer.length;
  const newVersion = runInline(inlineSnippet('2', 'deny'), stored).dataLayer.length;
  assert.strictEqual(sameVersion, 2, 'default + update');
  assert.strictEqual(newVersion, 1, 'NEGATIVE CONTROL: after the wording changes the old choice is ignored (default only)');
});

test('inline snippet survives a hostile version string and still parses', { skip: SKIP_PHP }, () => {
  const snippet = inlineSnippet('</script><script>alert(1)//"\'&', 'deny');
  assert.doesNotMatch(snippet, /<\/script>|<script>/);
  assert.doesNotThrow(() => runInline(snippet, ''));
});

/* ---------------------------------------------------------------- ES5 gate on the shipped files */

function acornAvailable() {
  const candidates = [];
  if (process.env.ACORN_PATH) {
    candidates.push(path.resolve(process.env.ACORN_PATH, 'package.json'));
  }
  candidates.push(path.resolve(PLUGIN_DIR, '..', 'web', 'node_modules', 'acorn', 'package.json'));
  return candidates.some((candidate) => fs.existsSync(candidate));
}
const SKIP_ACORN = acornAvailable() ? false : 'acorn not found (set ACORN_PATH to an acorn package directory)';

test('the shipped scripts pass the ES5 gate, and an arrow function planted in a copy fails it (negative control)', { skip: SKIP_ACORN }, () => {
  const ok = spawnSync(process.execPath, [GATE, path.join(PLUGIN_DIR, 'public', 'js')], { encoding: 'utf8' });
  assert.strictEqual(ok.status, 0, ok.stdout + ok.stderr);
  const dir = fs.mkdtempSync(path.join(require('node:os').tmpdir(), 'dbgr-wp03-es5-'));
  fs.writeFileSync(path.join(dir, 'dbgr-consent.js'), CONSENT_JS.replace('function readCookie() {', 'var bad = () => 1;\n\tfunction readCookie() {'));
  const bad = spawnSync(process.execPath, [GATE, dir], { encoding: 'utf8' });
  assert.notStrictEqual(bad.status, 0, 'the gate must reject the planted arrow function');
});
