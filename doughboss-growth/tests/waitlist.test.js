'use strict';
/*
 * WP-05 node tests for public/js/dbgr-waitlist.js: the VIP waitlist form and the coming_soon_view event.
 *
 * The browser script is an ES5 IIFE that reads window/document, so each test runs the REAL file inside a node:vm context
 * with a small fake DOM, a fake XMLHttpRequest (every request is recorded, every answer is scripted), a fake clock and a
 * fake setTimeout. No jsdom and no network. The real rendered page (axe, keyboard, a mail capture, confirm and opt-out)
 * is covered by web/scripts/wp-local/growth/wp05-waitlist.mjs.
 *
 * Dependency-free: node:test and node:assert only. Tests that need acorn (the ES5 gate) are SKIPPED with a visible reason
 * when it is missing, never passed.
 */
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

const PLUGIN_DIR = path.resolve(__dirname, '..');
const SOURCE = fs.readFileSync(path.join(PLUGIN_DIR, 'public', 'js', 'dbgr-waitlist.js'), 'utf8');
const GATE = path.join(PLUGIN_DIR, 'scripts', 'es5-check.mjs');
const ACORN = process.env.ACORN_PATH || path.resolve(PLUGIN_DIR, '..', 'web', 'node_modules', 'acorn');
const HAVE_ACORN = fs.existsSync(path.join(ACORN, 'package.json'));

/* ---------------------------------------------------------------- fake DOM */

class Emitter {
  constructor() {
    this.listeners = {};
  }
  addEventListener(type, fn) {
    (this.listeners[type] = this.listeners[type] || []).push(fn);
  }
  dispatch(type, event) {
    (this.listeners[type] || []).slice().forEach((fn) => fn(event || { type }));
  }
}

class El extends Emitter {
  constructor(attrs) {
    super();
    this.attrs = Object.assign({}, attrs || {});
    this.value = '';
    this.checked = false;
    this.hidden = false;
    this.disabled = false;
    this.textContent = '';
    this.className = '';
    this.focused = false;
  }
  getAttribute(name) {
    return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null;
  }
  setAttribute(name, value) {
    this.attrs[name] = String(value);
  }
  removeAttribute(name) {
    delete this.attrs[name];
  }
  focus() {
    this.focused = true;
  }
}

function makeForm(options) {
  const opts = Object.assign({ stores: false }, options || {});
  const form = new El();
  const f = {};
  ['email', 'first_name', 'mobile', 'website', 'consent_version', 'token'].forEach((n) => {
    f[n] = new El({ name: n });
  });
  f.consent = new El({ name: 'consent' });
  f.consent_version.value = 'wl-0123456789ab';
  if (opts.stores) {
    f.store = new El({ name: 'store' });
    f.store.options = [
      new El({ 'data-slug': 'none' }),
      new El({ 'data-slug': 'bankstown' }),
      new El({}),
    ];
    f.store.options[0].value = '';
    f.store.options[1].value = '2';
    f.store.options[2].value = '9';
    f.store.selectedIndex = 0;
    Object.defineProperty(f.store, 'value', {
      get() {
        return this.options[this.selectedIndex].value;
      },
      set() {},
    });
  }
  const button = new El({ type: 'submit' });
  const status = new El({ 'data-dbgr-wl-status': '' });
  const fields = new El({ 'data-dbgr-wl-fields': '' });
  form.f = f;
  form.button = button;
  form.status = status;
  form.fields = fields;
  form.querySelector = (selector) => {
    let m = /^\[name="([a-z_]+)"\]$/.exec(selector);
    if (m) {
      return f[m[1]] || null;
    }
    if (selector === 'button[type="submit"]') {
      return button;
    }
    if (selector === '[data-dbgr-wl-status]') {
      return status;
    }
    if (selector === '[data-dbgr-wl-fields]') {
      return fields;
    }
    return null;
  };
  return form;
}

/** Run the real script. Returns the page controls. */
function load(options) {
  const opts = Object.assign({ config: true, forms: [], sections: [], io: false, track: 'record', pathname: '/coming-soon/' }, options || {});
  const requests = [];
  const timers = [];
  const tracked = [];
  const clock = { now: 1700000000000 };
  const doc = new Emitter();
  doc.readyState = 'complete';
  doc.querySelectorAll = (selector) => {
    if (selector === 'form[data-dbgr-waitlist]') {
      return opts.forms;
    }
    if (selector === '[data-dbgr-coming-soon][data-dbgr-surface="home"]') {
      return opts.sections;
    }
    return [];
  };
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
  const observers = [];
  class FakeIO {
    constructor(cb) {
      this.cb = cb;
      this.observed = [];
      this.disconnected = false;
      observers.push(this);
    }
    observe(node) {
      this.observed.push(node);
    }
    disconnect() {
      this.disconnected = true;
    }
    trigger(isIntersecting) {
      this.cb([{ isIntersecting }]);
    }
  }
  const sandbox = {
    document: doc,
    XMLHttpRequest: FakeXHR,
    JSON,
    String,
    Math,
    Date: function () {
      this.getTime = () => clock.now;
    },
    location: { pathname: opts.pathname },
  };
  sandbox.window = sandbox;
  sandbox.window.location = { pathname: opts.pathname };
  sandbox.window.setTimeout = (fn, ms) => {
    timers.push({ fn, ms });
    return timers.length;
  };
  if (opts.config) {
    sandbox.window.DoughBossGrowthWaitlist = {
      tokenUrl: 'https://doughboss.test/wp-json/doughboss-growth/v1/form-token',
      signupUrl: 'https://doughboss.test/wp-json/doughboss-growth/v1/waitlist',
      minAge: 3,
      strings: { consent: 'CONSENT_MSG', email: 'EMAIL_MSG', wait: 'WAIT_MSG', network: 'NETWORK_MSG', generic: 'GENERIC_MSG', sending: 'SENDING_MSG' },
    };
  }
  if (opts.io) {
    sandbox.window.IntersectionObserver = FakeIO;
  }
  if (opts.track === 'record' || opts.track === 'refuse') {
    sandbox.window.DoughBossGrowth = {
      track: (name, params) => {
        tracked.push({ name, params });
        return opts.track === 'record';
      },
    };
  } else if (opts.track === 'throw') {
    sandbox.window.DoughBossGrowth = {
      track: () => {
        throw new Error('boom');
      },
    };
  }
  vm.runInNewContext(SOURCE, sandbox);
  return { requests, timers, tracked, clock, doc, observers, sandbox };
}

const TOKEN_URL = 'https://doughboss.test/wp-json/doughboss-growth/v1/form-token';
const SIGNUP_URL = 'https://doughboss.test/wp-json/doughboss-growth/v1/waitlist';

/** Objects made inside the vm context have another Object prototype: compare plain copies. */
function plain(value) {
  return JSON.parse(JSON.stringify(value));
}

function submit(form) {
  let prevented = false;
  form.dispatch('submit', { type: 'submit', preventDefault: () => { prevented = true; } });
  return prevented;
}

function fill(form, email, consent) {
  form.f.email.value = email;
  form.f.consent.checked = consent;
}

/* ------------------------------------------------------------------ tests */

test('waitlist.js: loads and fetches a form token on load; never throws without a config; sends nothing else', () => {
  const form = makeForm();
  const page = load({ forms: [form] });
  assert.strictEqual(page.requests.length, 1, 'one request on load');
  assert.strictEqual(page.requests[0].method, 'GET');
  assert.strictEqual(page.requests[0].url, TOKEN_URL);
  assert.strictEqual(page.requests[0].headers.Accept, 'application/json');
  assert.strictEqual(page.requests[0].body, null, 'a GET sends no body');

  // No configuration: inert, and no request.
  const none = load({ config: false, forms: [makeForm()] });
  assert.strictEqual(none.requests.length, 0, 'no config: no request');
});

test('waitlist.js: an empty or malformed email and an unticked consent box stop the submit, show the message, send no POST', () => {
  const form = makeForm();
  const page = load({ forms: [form] });
  page.requests[0].answer(200, { token: '1700000000.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' });

  fill(form, '', true);
  assert.ok(submit(form), 'the default browser submit is always prevented');
  assert.strictEqual(form.status.textContent, 'EMAIL_MSG');
  assert.match(form.status.className, /dbgr-wl__status--error/);
  fill(form, 'not-an-email', true);
  submit(form);
  assert.strictEqual(form.status.textContent, 'EMAIL_MSG');
  assert.strictEqual(form.f.email.focused, true, 'focus goes to the email box');

  fill(form, 'jordan@example.com', false);
  submit(form);
  assert.strictEqual(form.status.textContent, 'CONSENT_MSG', 'consent is required');
  assert.strictEqual(form.f.consent.focused, true, 'focus goes to the consent box');
  assert.strictEqual(page.requests.filter((r) => r.method === 'POST').length, 0, 'no POST was ever sent');
  assert.strictEqual(page.timers.length, 0, 'nothing was scheduled');
});

test('waitlist.js: a submit waits until the token is old enough, then POSTs the exact fields with consent 1; nothing is sent before the wait ends', () => {
  const form = makeForm({ stores: true });
  const page = load({ forms: [form] });
  page.requests[0].answer(200, { token: '1700000000.bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' });
  page.clock.now += 1000; // the token is 1 s old
  fill(form, '  jordan@example.com ', true);
  form.f.first_name.value = 'Jordan';
  form.f.mobile.value = '0412 345 678';
  form.f.store.selectedIndex = 1;
  submit(form);
  assert.strictEqual(page.requests.filter((r) => r.method === 'POST').length, 0, 'not yet: the token is too young');
  assert.strictEqual(page.timers.length, 1, 'a timer was set');
  assert.ok(page.timers[0].ms >= 2299 && page.timers[0].ms <= 2301, 'waits 3 s + 0.3 s minus the age: ' + page.timers[0].ms);
  assert.strictEqual(form.status.textContent, 'WAIT_MSG');
  assert.strictEqual(form.button.disabled, true, 'the button is disabled while waiting');
  assert.strictEqual(form.getAttribute('aria-busy'), 'true');

  page.clock.now += 2300;
  page.timers[0].fn();
  const post = page.requests.filter((r) => r.method === 'POST');
  assert.strictEqual(post.length, 1, 'now exactly one POST');
  assert.strictEqual(post[0].url, SIGNUP_URL);
  assert.strictEqual(post[0].headers['Content-Type'], 'application/json');
  const body = JSON.parse(post[0].body);
  assert.deepStrictEqual(Object.keys(body).sort(), ['consent', 'consent_version', 'email', 'first_name', 'mobile', 'path', 'store', 'token', 'website']);
  assert.strictEqual(body.email, 'jordan@example.com', 'trimmed');
  assert.strictEqual(body.first_name, 'Jordan');
  assert.strictEqual(body.mobile, '0412 345 678', 'the server normalises the number');
  assert.strictEqual(body.store, '2');
  assert.strictEqual(body.consent, 1, 'consent is the number 1');
  assert.strictEqual(body.consent_version, 'wl-0123456789ab');
  assert.strictEqual(body.token, '1700000000.bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
  assert.strictEqual(body.path, '/coming-soon/', 'the path only');
  assert.strictEqual(body.website, '', 'the honeypot is forwarded as it is (empty for a person)');
});

test('waitlist.js: a second submit while one is in flight sends nothing; an old token is replaced before the POST', () => {
  const form = makeForm();
  const page = load({ forms: [form] });
  page.requests[0].answer(200, { token: '1700000000.cccccccccccccccccccccccccccccccc' });
  page.clock.now += 5000;
  fill(form, 'jordan@example.com', true);
  submit(form);
  page.timers[0] && page.timers[0].fn();
  submit(form);
  assert.strictEqual(page.requests.filter((r) => r.method === 'POST').length, 1, 'still one POST');

  // A token older than 23 hours is fetched again first.
  const form2 = makeForm();
  const page2 = load({ forms: [form2] });
  page2.requests[0].answer(200, { token: '1700000000.dddddddddddddddddddddddddddddddd' });
  page2.clock.now += 24 * 3600 * 1000;
  fill(form2, 'jordan@example.com', true);
  submit(form2);
  assert.strictEqual(page2.requests.length, 2, 'a second token request was made');
  assert.strictEqual(page2.requests[1].method, 'GET');
  assert.strictEqual(page2.requests.filter((r) => r.method === 'POST').length, 0, 'no POST until the new token arrives');
});

test('waitlist.js: success is shown only after the server says success; waitlist_submit is sent then, with the store slug or "none"; values are cleared; text goes in with textContent', () => {
  const form = makeForm({ stores: true });
  const page = load({ forms: [form] });
  page.requests[0].answer(200, { token: '1700000000.eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee' });
  page.clock.now += 5000;
  fill(form, 'jordan@example.com', true);
  form.f.store.selectedIndex = 1;
  submit(form);
  page.timers[0] && page.timers[0].fn();
  assert.strictEqual(page.tracked.length, 0, 'nothing is tracked while the request is in flight');
  assert.strictEqual(form.fields.hidden, false, 'the form is still there');
  const post = page.requests.filter((r) => r.method === 'POST')[0];
  post.answer(200, { success: true, message: '<b>Thanks.</b> Check your email.' });
  assert.strictEqual(form.status.textContent, '<b>Thanks.</b> Check your email.', 'shown as plain text, not markup');
  assert.match(form.status.className, /dbgr-wl__status--ok/);
  assert.strictEqual(form.fields.hidden, true, 'the fields are hidden');
  assert.strictEqual(form.f.email.value, '', 'email cleared');
  assert.strictEqual(form.f.consent.checked, false, 'consent box cleared');
  assert.strictEqual(form.button.disabled, false, 'button released');
  assert.deepStrictEqual(plain(page.tracked), [{ name: 'waitlist_submit', params: { store: 'bankstown' } }]);

  // A shop with no analytics slug and "no preference" both report "none".
  const f2 = makeForm({ stores: true });
  const p2 = load({ forms: [f2] });
  p2.requests[0].answer(200, { token: '1700000000.ffffffffffffffffffffffffffffffff' });
  p2.clock.now += 5000;
  fill(f2, 'a@example.com', true);
  f2.f.store.selectedIndex = 2;
  submit(f2);
  p2.timers[0] && p2.timers[0].fn();
  p2.requests.filter((r) => r.method === 'POST')[0].answer(200, { success: true, message: 'ok' });
  assert.deepStrictEqual(plain(p2.tracked), [{ name: 'waitlist_submit', params: { store: 'none' } }]);
});

test('waitlist.js: failures show the server message and never track; 429 and 503 keep the form; a network failure shows the network text; a refused token is replaced', () => {
  const cases = [
    { status: 429, data: { success: false, code: 'dbgr_rate_limited', message: 'Too many attempts. Please try again later.' }, text: 'Too many attempts. Please try again later.' },
    { status: 503, data: { success: false, code: 'dbgr_unavailable', message: 'We could not save your details just now.' }, text: 'We could not save your details just now.' },
    { status: 400, data: { success: false, code: 'dbgr_invalid_email', message: 'Please enter a valid email address.' }, text: 'Please enter a valid email address.' },
    { status: 0, data: null, text: 'NETWORK_MSG' },
    { status: 500, data: 'not json', text: 'GENERIC_MSG' },
  ];
  cases.forEach((c) => {
    const form = makeForm();
    const page = load({ forms: [form] });
    page.requests[0].answer(200, { token: '1700000000.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' });
    page.clock.now += 5000;
    fill(form, 'jordan@example.com', true);
    submit(form);
    page.timers[0] && page.timers[0].fn();
    page.requests.filter((r) => r.method === 'POST')[0].answer(c.status, c.data);
    assert.strictEqual(form.status.textContent, c.text, 'message for status ' + c.status);
    assert.match(form.status.className, /dbgr-wl__status--error/);
    assert.strictEqual(form.fields.hidden, false, 'the form stays so the person can retry: ' + c.status);
    assert.strictEqual(form.button.disabled, false, 'button released: ' + c.status);
    assert.strictEqual(page.tracked.length, 0, 'a failure is never tracked: ' + c.status);
  });

  // A refused token (too early, expired, wording changed) is fetched again.
  ['dbgr_token_early', 'dbgr_token_invalid', 'dbgr_consent_changed'].forEach((code) => {
    const form = makeForm();
    const page = load({ forms: [form] });
    page.requests[0].answer(200, { token: '1700000000.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' });
    page.clock.now += 5000;
    fill(form, 'jordan@example.com', true);
    submit(form);
    page.timers[0] && page.timers[0].fn();
    const before = page.requests.length;
    page.requests.filter((r) => r.method === 'POST')[0].answer(400, { success: false, code, message: 'try again' });
    assert.strictEqual(page.requests.length, before + 1, 'a new token was requested after ' + code);
    assert.strictEqual(page.requests[page.requests.length - 1].method, 'GET');
  });
});

test('waitlist.js: if the token cannot be fetched the submit fails with the network text and sends no POST', () => {
  const form = makeForm();
  const page = load({ forms: [form] });
  page.requests[0].answer(500, { code: 'x' });
  fill(form, 'jordan@example.com', true);
  submit(form);
  page.requests[1].answer(503, { code: 'dbgr_unavailable' });
  assert.strictEqual(form.status.textContent, 'NETWORK_MSG');
  assert.strictEqual(page.requests.filter((r) => r.method === 'POST').length, 0, 'no POST without a token');
  assert.strictEqual(form.button.disabled, false, 'button released');
});

test('coming_soon_view: sent once when the home section first scrolls into view, with surface home; not before; not for a section that is not marked', () => {
  const section = new El({ 'data-dbgr-coming-soon': '', 'data-dbgr-surface': 'home' });
  const page = load({ sections: [section], io: true, config: false });
  assert.strictEqual(page.observers.length, 1, 'one observer');
  assert.strictEqual(page.observers[0].observed[0], section);
  assert.strictEqual(page.tracked.length, 0, 'not sent before it is seen');
  page.observers[0].trigger(false);
  assert.strictEqual(page.tracked.length, 0, 'not sent while not intersecting');
  page.observers[0].trigger(true);
  assert.deepStrictEqual(plain(page.tracked), [{ name: 'coming_soon_view', params: { surface: 'home' } }]);
  assert.strictEqual(page.observers[0].disconnected, true, 'the observer is released');
  page.observers[0].trigger(true);
  assert.strictEqual(page.tracked.length, 1, 'a second intersection report does not send it again');
  page.doc.dispatch('doughboss-growth:consent-changed', { type: 'x' });
  assert.strictEqual(page.tracked.length, 1, 'never sent twice');

  const none = load({ sections: [], io: true, config: false });
  assert.strictEqual(none.observers.length, 0, 'no marked section: nothing observed');
  assert.strictEqual(none.tracked.length, 0, 'and nothing sent');
});

test('coming_soon_view: a view before consent (the event is dropped) is retried once when consent arrives; a throwing or missing tracker never breaks the page; no observer support sends at once', () => {
  const section = new El({ 'data-dbgr-surface': 'home' });
  const page = load({ sections: [section], io: true, track: 'refuse', config: false });
  page.observers[0].trigger(true);
  assert.strictEqual(page.tracked.length, 1, 'tried once');
  page.doc.dispatch('doughboss-growth:consent-changed', { type: 'x' });
  assert.strictEqual(page.tracked.length, 2, 'retried when the visitor chose');
  page.doc.dispatch('doughboss-growth:consent-changed', { type: 'x' });
  assert.strictEqual(page.tracked.length, 3, 'and again while it is still refused (the tracker, not this script, decides)');

  const before = load({ sections: [new El({ 'data-dbgr-surface': 'home' })], io: true, track: 'refuse', config: false });
  before.doc.dispatch('doughboss-growth:consent-changed', { type: 'x' });
  assert.strictEqual(before.tracked.length, 0, 'consent changing before the section was seen sends nothing');

  assert.doesNotThrow(() => {
    const t = load({ sections: [new El({ 'data-dbgr-surface': 'home' })], io: true, track: 'throw', config: false });
    t.observers[0].trigger(true);
  }, 'a throwing tracker is contained');
  assert.doesNotThrow(() => {
    const t = load({ sections: [new El({ 'data-dbgr-surface': 'home' })], io: true, track: 'none', config: false });
    t.observers[0].trigger(true);
  }, 'no tracker at all is fine');

  const noio = load({ sections: [new El({ 'data-dbgr-surface': 'home' })], io: false, config: false });
  assert.deepStrictEqual(plain(noio.tracked), [{ name: 'coming_soon_view', params: { surface: 'home' } }], 'without IntersectionObserver the view is sent at once');
});

test('waitlist.js source: ES5 only, no markup injection, no storage, no outbound host, no third-party tag', () => {
  assert.doesNotMatch(SOURCE, /innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\b|new Function/);
  assert.doesNotMatch(SOURCE, /localStorage|sessionStorage|document\.cookie|indexedDB/, 'nothing is stored in the browser');
  assert.doesNotMatch(SOURCE, /https?:\/\//, 'no hard-coded address: every URL comes from the server configuration');
  assert.doesNotMatch(SOURCE, /\bfbq\b|\bgtag\b|dataLayer|ttq/, 'no tracking tag is touched directly');
  assert.doesNotMatch(SOURCE, /mini(s)/i, 'no working name');
  assert.match(SOURCE, /textContent/, 'dynamic text uses textContent');
  assert.match(SOURCE, /'use strict'/);
});

test('waitlist.js: passes the ES5 gate; a planted arrow function in a copy fails it (negative control)', { skip: HAVE_ACORN ? false : 'acorn not found (set ACORN_PATH to an acorn package directory)' }, () => {
  const env = Object.assign({}, process.env, { ACORN_PATH: ACORN });
  const ok = spawnSync(process.execPath, [GATE, path.join(PLUGIN_DIR, 'public')], { encoding: 'utf8', env });
  assert.strictEqual(ok.status, 0, ok.stdout + ok.stderr);
  const tmp = fs.mkdtempSync(path.join(require('node:os').tmpdir(), 'dbgr-wl-es5-'));
  fs.mkdirSync(path.join(tmp, 'public', 'js'), { recursive: true });
  fs.writeFileSync(path.join(tmp, 'public', 'js', 'planted.js'), SOURCE.replace("var cfg = window", "var arrow = () => 1; var cfg = window"));
  const bad = spawnSync(process.execPath, [GATE, path.join(tmp, 'public')], { encoding: 'utf8', env });
  assert.strictEqual(bad.status, 1, 'the planted arrow function is a violation');
});
