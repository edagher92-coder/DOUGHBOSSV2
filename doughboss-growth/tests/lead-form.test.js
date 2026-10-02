'use strict';
/*
 * WP-07 node tests for public/js/dbgr-lead-form.js: the corporate lead form.
 *
 * The browser script is an ES5 IIFE that reads window/document, so each test runs the REAL file inside a node:vm context
 * with a small fake DOM and a fake XMLHttpRequest (every request is recorded, every answer is scripted). No jsdom, no
 * network. The real rendered page and the real core enquiry route are covered by web/scripts/wp-local/growth/wp07-leads.mjs.
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
const SOURCE = fs.readFileSync(path.join(PLUGIN_DIR, 'public', 'js', 'dbgr-lead-form.js'), 'utf8');
const GATE = path.join(PLUGIN_DIR, 'scripts', 'es5-check.mjs');
const ACORN = process.env.ACORN_PATH || path.resolve(PLUGIN_DIR, '..', 'web', 'node_modules', 'acorn');
const HAVE_ACORN = fs.existsSync(path.join(ACORN, 'package.json'));
const ENQUIRY_URL = 'https://doughboss.test/wp-json/doughboss/v1/catering/enquiry';

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
  const opts = Object.assign({ segment: 'corporate', company: true, consent: true, stores: true }, options || {});
  const form = new El({ 'data-segment': opts.segment, 'data-landing': 'catering-' + opts.segment, 'data-company-required': opts.company ? '1' : '0' });
  const f = {};
  ['customer_name', 'dbgr_company', 'customer_email', 'customer_phone', 'package_id', 'guest_count', 'event_date', 'order_type', 'address', 'notes', 'hp'].forEach((n) => {
    f[n] = new El({ name: n });
  });
  f.order_type.value = 'pickup';
  f.package_id.value = '0';
  if (opts.consent) {
    f.dbgr_consent_marketing = new El({ name: 'dbgr_consent_marketing' });
    f.dbgr_consent_text_version = new El({ name: 'dbgr_consent_text_version' });
    f.dbgr_consent_text_version.value = 'ld-0123456789ab';
  }
  if (opts.stores) {
    f.location_id = new El({ name: 'location_id' });
    f.location_id.options = [new El({ 'data-slug': 'none' }), new El({ 'data-slug': 'bankstown' }), new El({ 'data-slug': 'weird' })];
    f.location_id.options[0].value = '0';
    f.location_id.options[1].value = '2';
    f.location_id.options[2].value = '9';
    f.location_id.selectedIndex = 0;
    Object.defineProperty(f.location_id, 'value', {
      get() {
        return this.options[this.selectedIndex].value;
      },
      set() {},
    });
  }
  const button = new El({ type: 'submit' });
  const status = new El({ 'data-dbgr-lead-status': '' });
  const fields = new El({ 'data-dbgr-lead-fields': '' });
  const address = new El({ 'data-dbgr-lead-address': '' });
  address.hidden = true;
  form.f = f;
  form.button = button;
  form.status = status;
  form.fields = fields;
  form.address = address;
  form.querySelector = (selector) => {
    const m = /^\[name="([a-z_]+)"\]$/.exec(selector);
    if (m) {
      return f[m[1]] || null;
    }
    if (selector === 'button[type="submit"]') {
      return button;
    }
    if (selector === '[data-dbgr-lead-status]') {
      return status;
    }
    if (selector === '[data-dbgr-lead-fields]') {
      return fields;
    }
    if (selector === '[data-dbgr-lead-address]') {
      return address;
    }
    return null;
  };
  return form;
}

/** Run the real script. Returns the page controls. */
function load(options) {
  const opts = Object.assign({ config: true, forms: [], track: 'record', nonce: 'NONCE123' }, options || {});
  const requests = [];
  const tracked = [];
  const doc = new Emitter();
  doc.readyState = 'complete';
  doc.querySelectorAll = (selector) => (selector === 'form[data-dbgr-lead-form]' ? opts.forms : []);
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
  const sandbox = { document: doc, XMLHttpRequest: FakeXHR, JSON, String, Math, Object, parseInt };
  sandbox.window = sandbox;
  if (opts.config) {
    sandbox.window.DoughBossGrowthLeads = {
      enquiryUrl: ENQUIRY_URL,
      nonce: opts.nonce,
      maxGuests: 1000,
      strings: {
        name: 'NAME_MSG', email: 'EMAIL_MSG', company: 'COMPANY_MSG', guests: 'GUESTS_MSG', sending: 'SENDING_MSG', sent: 'SENT_MSG',
        number: 'NUMBER_MSG', refresh: 'REFRESH_MSG', limit: 'LIMIT_MSG', network: 'NETWORK_MSG', generic: 'GENERIC_MSG',
      },
    };
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
  return { requests, tracked, doc, sandbox };
}

/** Objects made inside the vm context have another Object prototype: compare plain copies. */
function plain(value) {
  return JSON.parse(JSON.stringify(value));
}

function submit(form) {
  let prevented = false;
  form.dispatch('submit', { type: 'submit', preventDefault: () => { prevented = true; } });
  return prevented;
}

function fillValid(form, extra) {
  form.f.customer_name.value = '  Test Person ';
  form.f.dbgr_company.value = 'Acme Pty Ltd';
  form.f.customer_email.value = 'person@example.com';
  form.f.guest_count.value = '30';
  Object.keys(extra || {}).forEach((k) => {
    form.f[k].value = extra[k];
  });
}

/* ------------------------------------------------------------------ tests */

test('lead-form.js: with a configuration it sends nothing until submitted; without one the form is inert and never posts', () => {
  const form = makeForm();
  const page = load({ forms: [form] });
  assert.strictEqual(page.requests.length, 0, 'no request on load');

  const none = load({ config: false, forms: [makeForm()] });
  assert.strictEqual(none.requests.length, 0, 'no config: no request');
  const inert = makeForm();
  const page2 = load({ config: false, forms: [inert] });
  fillValid(inert);
  assert.strictEqual(submit(inert), true, 'the browser is stopped from posting the form to the page');
  assert.strictEqual(page2.requests.length, 0, 'still no request');
  assert.strictEqual(inert.status.textContent, 'Something went wrong. Please try again later.', 'a visible error, not silence');

  const noNonce = makeForm();
  const page3 = load({ forms: [noNonce], nonce: '' });
  fillValid(noNonce);
  assert.strictEqual(submit(noNonce), true, 'empty nonce: inert');
  assert.strictEqual(page3.requests.length, 0);
});

test('lead-form.js: client validation stops a bad submit before any request (name, email, company, guests)', () => {
  const cases = [
    { name: 'no name', mutate: (f) => { f.f.customer_name.value = '   '; }, msg: 'NAME_MSG', focus: 'customer_name' },
    { name: 'no @', mutate: (f) => { f.f.customer_email.value = 'person.example.com'; }, msg: 'EMAIL_MSG', focus: 'customer_email' },
    { name: 'no dot after @', mutate: (f) => { f.f.customer_email.value = 'person@example'; }, msg: 'EMAIL_MSG', focus: 'customer_email' },
    { name: 'trailing dot', mutate: (f) => { f.f.customer_email.value = 'person@example.'; }, msg: 'EMAIL_MSG', focus: 'customer_email' },
    { name: 'company required for corporate', mutate: (f) => { f.f.dbgr_company.value = ''; }, msg: 'COMPANY_MSG', focus: 'dbgr_company' },
    { name: 'guests empty', mutate: (f) => { f.f.guest_count.value = ''; }, msg: 'GUESTS_MSG', focus: 'guest_count' },
    { name: 'guests zero', mutate: (f) => { f.f.guest_count.value = '0'; }, msg: 'GUESTS_MSG', focus: 'guest_count' },
    { name: 'guests negative', mutate: (f) => { f.f.guest_count.value = '-5'; }, msg: 'GUESTS_MSG', focus: 'guest_count' },
    { name: 'guests decimal', mutate: (f) => { f.f.guest_count.value = '12.5'; }, msg: 'GUESTS_MSG', focus: 'guest_count' },
    { name: 'guests above the ceiling', mutate: (f) => { f.f.guest_count.value = '1001'; }, msg: 'GUESTS_MSG', focus: 'guest_count' },
    { name: 'guests text', mutate: (f) => { f.f.guest_count.value = '1e3'; }, msg: 'GUESTS_MSG', focus: 'guest_count' },
  ];
  cases.forEach((c) => {
    const form = makeForm();
    const page = load({ forms: [form] });
    fillValid(form);
    c.mutate(form);
    assert.strictEqual(submit(form), true, c.name + ': default prevented');
    assert.strictEqual(page.requests.length, 0, c.name + ': no request');
    assert.strictEqual(form.status.textContent, c.msg, c.name + ': message');
    assert.strictEqual(form.f[c.focus].focused, true, c.name + ': focus moved to the field');
    assert.match(form.status.className, /dbgr-lead__status--error/, c.name + ': error styling');
  });
  // Negative control: the same form with valid values DOES send, so the rejections above are the validator's doing.
  const ok = makeForm();
  const page = load({ forms: [ok] });
  fillValid(ok);
  submit(ok);
  assert.strictEqual(page.requests.length, 1, 'valid values send');

  // An events form does not need a company.
  const events = makeForm({ segment: 'events', company: false });
  const evPage = load({ forms: [events] });
  fillValid(events, { dbgr_company: '' });
  submit(events);
  assert.strictEqual(evPage.requests.length, 1, 'events: company optional');
});

test('lead-form.js: posts core field names with core\'s nonce header and the dbgr_* extras; consent is omitted when not ticked', () => {
  const form = makeForm();
  const page = load({ forms: [form] });
  fillValid(form, { customer_phone: ' 0400 000 000 ', order_type: 'delivery', address: ' 1 Test St ', notes: ' hello ', event_date: '2030-01-02', package_id: '12' });
  assert.strictEqual(submit(form), true);
  assert.strictEqual(page.requests.length, 1);
  const req = page.requests[0];
  assert.strictEqual(req.method, 'POST');
  assert.strictEqual(req.url, ENQUIRY_URL);
  assert.strictEqual(req.headers['X-WP-Nonce'], 'NONCE123', 'core wp_rest nonce header');
  assert.strictEqual(req.headers['Content-Type'], 'application/json');
  assert.deepStrictEqual(JSON.parse(req.body), {
    customer_name: 'Test Person',
    customer_email: 'person@example.com',
    customer_phone: '0400 000 000',
    package_id: 12,
    guest_count: 30,
    order_type: 'delivery',
    location_id: 0,
    event_date: '2030-01-02',
    address: '1 Test St',
    notes: 'hello',
    hp: '',
    dbgr_company: 'Acme Pty Ltd',
    dbgr_segment: 'corporate',
    dbgr_landing_key: 'catering-corporate',
  }, 'exact payload; the consent keys are absent while the box is unticked');
  assert.strictEqual(form.button.disabled, true, 'button disabled while sending');
  assert.strictEqual(form.getAttribute('aria-busy'), 'true');
  assert.strictEqual(form.status.textContent, 'SENDING_MSG');
});

test('lead-form.js: marketing consent is sent ONLY when ticked, with the wording version; an unknown order type is pickup', () => {
  const ticked = makeForm();
  const page = load({ forms: [ticked] });
  fillValid(ticked);
  ticked.f.dbgr_consent_marketing.checked = true;
  submit(ticked);
  const body = JSON.parse(page.requests[0].body);
  assert.strictEqual(body.dbgr_consent_marketing, '1');
  assert.strictEqual(body.dbgr_consent_text_version, 'ld-0123456789ab');

  // Ticked but the version field is missing (a stale or tampered form): no consent is claimed.
  const noVersion = makeForm();
  const page2 = load({ forms: [noVersion] });
  fillValid(noVersion);
  noVersion.f.dbgr_consent_marketing.checked = true;
  noVersion.f.dbgr_consent_text_version.value = '';
  submit(noVersion);
  const body2 = JSON.parse(page2.requests[0].body);
  assert.ok(!('dbgr_consent_marketing' in body2) && !('dbgr_consent_text_version' in body2), 'no version: no consent claimed');

  // No marketing box on the form at all (no sender name configured): nothing is sent and nothing throws.
  const none = makeForm({ consent: false });
  const page3 = load({ forms: [none] });
  fillValid(none, { order_type: 'collect' });
  submit(none);
  const body3 = JSON.parse(page3.requests[0].body);
  assert.ok(!('dbgr_consent_marketing' in body3));
  assert.strictEqual(body3.order_type, 'pickup', 'anything but delivery is pickup');
});

test('lead-form.js: success shows the message and enquiry number, sends generate_lead with category, guest band and store, and resets the form', () => {
  const form = makeForm();
  const page = load({ forms: [form] });
  fillValid(form);
  form.f.location_id.selectedIndex = 1;
  form.f.dbgr_consent_marketing.checked = true;
  submit(form);
  page.requests[0].answer(200, { success: true, enquiry_number: 'E-1234', total: 321 });
  assert.strictEqual(form.status.textContent, 'SENT_MSG NUMBER_MSG E-1234.');
  assert.match(form.status.className, /dbgr-lead__status--ok/);
  assert.strictEqual(form.fields.hidden, true, 'fields hidden after success');
  assert.strictEqual(form.f.customer_email.value, '', 'personal data cleared');
  assert.strictEqual(form.f.dbgr_consent_marketing.checked, false, 'consent box cleared');
  assert.strictEqual(form.button.disabled, false);
  assert.deepStrictEqual(plain(page.tracked), [{ name: 'generate_lead', params: { form: 'catering_enquiry', category: 'corporate', guest_band: '25-49', store: 'bankstown' } }]);
  assert.doesNotMatch(JSON.stringify(page.tracked), /@|E-1234|Test Person|Acme/, 'no personal data and no enquiry number in the event');
});

test('lead-form.js: guest bands and the unknown-store fallback', () => {
  const bands = { 1: '1-9', 9: '1-9', 10: '10-24', 24: '10-24', 25: '25-49', 49: '25-49', 50: '50-99', 99: '50-99', 100: '100+', 1000: '100+' };
  Object.keys(bands).forEach((n) => {
    const form = makeForm();
    const page = load({ forms: [form] });
    fillValid(form, { guest_count: n });
    form.f.location_id.selectedIndex = 2; // a slug that is not one of the three shops
    submit(form);
    page.requests[0].answer(200, { success: true, enquiry_number: 'E1' });
    assert.strictEqual(page.tracked[0].params.guest_band, bands[n], n + ' guests');
    assert.strictEqual(page.tracked[0].params.store, 'none', 'unknown slug is none');
  });
  const noShops = makeForm({ stores: false });
  const page = load({ forms: [noShops] });
  fillValid(noShops);
  submit(noShops);
  page.requests[0].answer(200, { success: true, enquiry_number: 'E1' });
  assert.strictEqual(page.tracked[0].params.store, 'none', 'no shop select: none');
});

test('lead-form.js: a silent honeypot success (no enquiry number) is NOT a lead and sends no event', () => {
  const form = makeForm();
  const page = load({ forms: [form] });
  fillValid(form, { hp: 'bot' });
  submit(form);
  assert.strictEqual(JSON.parse(page.requests[0].body).hp, 'bot');
  page.requests[0].answer(200, { success: true, enquiry_number: '' });
  assert.deepStrictEqual(plain(page.tracked), [], 'no generate_lead for a honeypot');
  assert.strictEqual(form.status.textContent, 'SENT_MSG', 'the visitor sees the plain success, no number');
});

test('lead-form.js: rejected enquiries show the right message, send no event, keep what was typed and re-enable the button', () => {
  const cases = [
    { status: 403, data: { code: 'doughboss_bad_nonce', message: 'Session expired.' }, msg: 'REFRESH_MSG' },
    { status: 429, data: { code: 'doughboss_rate_limit', message: 'Too many.' }, msg: 'LIMIT_MSG' },
    { status: 400, data: { code: 'doughboss_catering_date', message: 'The event date can\'t be in the past.' }, msg: 'The event date can\'t be in the past.' },
    { status: 500, data: null, msg: 'GENERIC_MSG' },
    { status: 200, data: { success: false }, msg: 'GENERIC_MSG' },
    { status: 200, data: 'not json', msg: 'GENERIC_MSG' },
    { status: 0, data: null, msg: 'NETWORK_MSG' },
  ];
  cases.forEach((c) => {
    const form = makeForm();
    const page = load({ forms: [form] });
    fillValid(form);
    submit(form);
    page.requests[0].answer(c.status, c.data);
    assert.strictEqual(form.status.textContent, c.msg, 'status ' + c.status);
    assert.match(form.status.className, /dbgr-lead__status--error/, 'status ' + c.status + ': error styling');
    assert.deepStrictEqual(plain(page.tracked), [], 'status ' + c.status + ': no lead event');
    assert.strictEqual(form.f.customer_email.value, 'person@example.com', 'status ' + c.status + ': typed values kept');
    assert.strictEqual(form.fields.hidden, false, 'status ' + c.status + ': form still shown');
    assert.strictEqual(form.button.disabled, false, 'status ' + c.status + ': button re-enabled');
  });
});

test('lead-form.js: a double submit while busy sends one request; the dispatcher refusing or throwing never breaks success', () => {
  const form = makeForm();
  const page = load({ forms: [form] });
  fillValid(form);
  submit(form);
  submit(form);
  assert.strictEqual(page.requests.length, 1, 'one request while busy');

  const refused = makeForm();
  const refusedPage = load({ forms: [refused], track: 'refuse' });
  fillValid(refused);
  submit(refused);
  refusedPage.requests[0].answer(200, { success: true, enquiry_number: 'E9' });
  assert.match(refused.status.textContent, /SENT_MSG/, 'consent refused the event: success still shown');

  const thrown = makeForm();
  const thrownPage = load({ forms: [thrown], track: 'throw' });
  fillValid(thrown);
  submit(thrown);
  thrownPage.requests[0].answer(200, { success: true, enquiry_number: 'E9' });
  assert.match(thrown.status.textContent, /SENT_MSG/, 'a throwing tracker never breaks the form');

  const none = makeForm();
  const nonePage = load({ forms: [none], track: 'none' });
  fillValid(none);
  submit(none);
  nonePage.requests[0].answer(200, { success: true, enquiry_number: 'E9' });
  assert.match(none.status.textContent, /SENT_MSG/, 'no tracker at all is fine');
});

test('lead-form.js: the delivery address row shows only for delivery', () => {
  const form = makeForm();
  load({ forms: [form] });
  assert.strictEqual(form.address.hidden, true, 'hidden for pickup');
  form.f.order_type.value = 'delivery';
  form.f.order_type.dispatch('change');
  assert.strictEqual(form.address.hidden, false, 'shown for delivery');
  form.f.order_type.value = 'pickup';
  form.f.order_type.dispatch('change');
  assert.strictEqual(form.address.hidden, true, 'hidden again');
});

test('lead-form.js source: ES5 only, no markup injection, no storage, no hard-coded host, no direct tag, no working name', () => {
  assert.doesNotMatch(SOURCE, /innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\b|new Function/);
  assert.doesNotMatch(SOURCE, /localStorage|sessionStorage|document\.cookie|indexedDB/, 'nothing is stored in the browser');
  assert.doesNotMatch(SOURCE, /https?:\/\//, 'no hard-coded address: every URL comes from the server configuration');
  assert.doesNotMatch(SOURCE, /\bfbq\b|\bgtag\b|dataLayer|ttq/, 'no tracking tag is touched directly');
  assert.doesNotMatch(SOURCE, new RegExp('mini' + 's', 'i'), 'no working name');
  assert.match(SOURCE, /textContent/, 'dynamic text uses textContent');
  assert.match(SOURCE, /'use strict'/);
});

test('lead-form.js: passes the ES5 gate; a planted arrow function in a copy fails it (negative control)', { skip: HAVE_ACORN ? false : 'acorn not found (set ACORN_PATH to an acorn package directory)' }, () => {
  const env = Object.assign({}, process.env, { ACORN_PATH: ACORN });
  const ok = spawnSync(process.execPath, [GATE, path.join(PLUGIN_DIR, 'public')], { encoding: 'utf8', env });
  assert.strictEqual(ok.status, 0, ok.stdout + ok.stderr);
  const tmp = fs.mkdtempSync(path.join(require('node:os').tmpdir(), 'dbgr-ld-es5-'));
  fs.mkdirSync(path.join(tmp, 'public', 'js'), { recursive: true });
  fs.writeFileSync(path.join(tmp, 'public', 'js', 'planted.js'), SOURCE.replace('var cfg = window', 'var arrow = () => 1; var cfg = window'));
  const bad = spawnSync(process.execPath, [GATE, path.join(tmp, 'public')], { encoding: 'utf8', env });
  assert.strictEqual(bad.status, 1, 'the planted arrow function is a violation');
});
