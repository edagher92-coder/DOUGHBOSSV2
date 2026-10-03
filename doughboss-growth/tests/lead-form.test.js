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

/* Every element made with (or later given) an id, so the fake document can answer getElementById. */
const REGISTRY = {};
let UID = 0;

class El extends Emitter {
  constructor(attrs, nodeName) {
    super();
    this.attrs = Object.assign({}, attrs || {});
    this.nodeName = nodeName || 'DIV';
    this.parentNode = null;
    this.children = [];
    this.value = '';
    this.checked = false;
    this.hidden = false;
    this.disabled = false;
    this.textContent = '';
    this.className = '';
    this.focused = false;
    if (this.attrs.id) {
      REGISTRY[this.attrs.id] = this;
    }
  }
  getAttribute(name) {
    return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null;
  }
  setAttribute(name, value) {
    this.attrs[name] = String(value);
    if (name === 'id') {
      REGISTRY[String(value)] = this;
    }
  }
  removeAttribute(name) {
    delete this.attrs[name];
  }
  focus() {
    this.focused = true;
  }
  add(child) {
    child.parentNode = this;
    this.children.push(child);
    return child;
  }
  insertBefore(node, ref) {
    const at = ref ? this.children.indexOf(ref) : -1;
    node.parentNode = this;
    if (at === -1) {
      this.children.push(node);
    } else {
      this.children.splice(at, 0, node);
    }
    return node;
  }
  get nextSibling() {
    if (!this.parentNode) {
      return null;
    }
    const siblings = this.parentNode.children;
    return siblings[siblings.indexOf(this) + 1] || null;
  }
}

function makeForm(options) {
  const opts = Object.assign({ segment: 'corporate', company: true, consent: true, stores: true }, options || {});
  const form = new El({ 'data-segment': opts.segment, 'data-landing': 'catering-' + opts.segment, 'data-company-required': opts.company ? '1' : '0' });
  const f = {};
  UID += 1;
  ['customer_name', 'dbgr_company', 'customer_email', 'customer_phone', 'package_id', 'guest_count', 'event_date', 'order_type', 'address', 'notes', 'hp'].forEach((n) => {
    f[n] = new El({ name: n, id: 'dbgr-ld-' + UID + '-' + n }, 'INPUT');
    new El({}, 'P').add(f[n]); // each field sits in its own paragraph, as the PHP markup has it
  });
  f.order_type.value = 'pickup';
  f.package_id.value = '0';
  if (opts.consent) {
    f.dbgr_consent_marketing = new El({ name: 'dbgr_consent_marketing', id: 'dbgr-ld-' + UID + '-consent' }, 'INPUT');
    new El({}, 'P').add(new El({}, 'LABEL')).add(f.dbgr_consent_marketing); // inside its label, as in the markup
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
  const button = new El({ type: 'submit' }, 'BUTTON');
  const status = new El({ 'data-dbgr-lead-status': '' }, 'P');
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
  doc.getElementById = (id) => REGISTRY[id] || null;
  doc.createElement = (tag) => new El({}, String(tag).toUpperCase());
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

/** The error span the script made for a field (null when none). */
function errOf(form, name) {
  return REGISTRY[form.f[name].getAttribute('id') + '-err'] || null;
}

/** Fire the delegated input listener the way a browser does: the event reaches the form with the field as its target. */
function edit(form, name, type) {
  form.dispatch(type || 'input', { type: type || 'input', target: form.f[name] });
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
    const span = errOf(form, c.focus);
    assert.ok(span, c.name + ': an error span was made next to the field');
    assert.strictEqual(span.textContent, c.msg, c.name + ': message sits with the field');
    assert.strictEqual(span.className, 'dbgr-lead__err', c.name + ': styling hook');
    assert.strictEqual(span.getAttribute('id'), form.f[c.focus].getAttribute('id') + '-err', c.name + ': id is <field id>-err');
    assert.strictEqual(form.f[c.focus].getAttribute('aria-invalid'), 'true', c.name + ': field marked invalid');
    assert.strictEqual(form.f[c.focus].getAttribute('aria-describedby'), span.getAttribute('id'), c.name + ': field described by its message');
    assert.strictEqual(form.f[c.focus].focused, true, c.name + ': focus moved to the field');
    assert.strictEqual(form.status.textContent, '', c.name + ': the bottom status is not used for field errors');
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
  assert.strictEqual(form.button.getAttribute('aria-disabled'), 'true', 'button is aria-disabled while sending');
  assert.strictEqual(form.button.disabled, false, 'and NOT disabled, so keyboard focus is not dropped');
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
  assert.strictEqual(form.status.getAttribute('tabindex'), '-1', 'the confirmation can take focus');
  assert.strictEqual(form.status.focused, true, 'focus lands on the confirmation after the form collapses');
  assert.strictEqual(form.f.customer_email.value, '', 'personal data cleared');
  assert.strictEqual(form.f.dbgr_consent_marketing.checked, false, 'consent box cleared');
  assert.strictEqual(form.button.getAttribute('aria-disabled'), null, 'button released');
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
    assert.strictEqual(form.button.getAttribute('aria-disabled'), null, 'status ' + c.status + ': button re-enabled');
    assert.strictEqual(form.status.focused, false, 'status ' + c.status + ': a failure does not steal focus');
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

test('lead-form.js: every failing field is reported at once, in page order; only the first is focused; no request is sent', () => {
  const form = makeForm();
  const page = load({ forms: [form] });
  // Nothing filled in: name, company (required for corporate), email and guests are all wrong.
  assert.strictEqual(submit(form), true);
  assert.strictEqual(page.requests.length, 0);
  ['customer_name', 'dbgr_company', 'customer_email', 'guest_count'].forEach((name) => {
    const msg = { customer_name: 'NAME_MSG', dbgr_company: 'COMPANY_MSG', customer_email: 'EMAIL_MSG', guest_count: 'GUESTS_MSG' }[name];
    assert.strictEqual(errOf(form, name).textContent, msg, name + ' reported');
    assert.strictEqual(form.f[name].getAttribute('aria-invalid'), 'true', name + ' invalid');
  });
  assert.strictEqual(form.f.customer_name.focused, true, 'focus goes to the first invalid field in page order');
  ['dbgr_company', 'customer_email', 'guest_count'].forEach((name) => {
    assert.strictEqual(form.f[name].focused, false, name + ' is not focused');
  });
  assert.strictEqual(form.f.customer_phone.getAttribute('aria-invalid'), null, 'a field that was fine is not marked');
  assert.strictEqual(errOf(form, 'customer_phone'), null, 'and has no message');
  assert.strictEqual(form.status.textContent, '', 'the bottom status stays empty');

  // Page order, not check order: a missing company is focused before a bad email.
  const order = makeForm();
  load({ forms: [order] });
  fillValid(order);
  order.f.dbgr_company.value = '';
  order.f.customer_email.value = 'nope';
  submit(order);
  assert.strictEqual(order.f.dbgr_company.focused, true, 'company comes before email on the page');
  assert.strictEqual(order.f.customer_email.focused, false);
});

test('lead-form.js: the error span is made once, sits right after its field and is reused on the next try', () => {
  const form = makeForm();
  load({ forms: [form] });
  fillValid(form, { customer_email: 'bad' });
  submit(form);
  const first = errOf(form, 'customer_email');
  const wrapper = form.f.customer_email.parentNode;
  assert.strictEqual(wrapper.children.indexOf(first), wrapper.children.indexOf(form.f.customer_email) + 1, 'directly after the field');
  assert.strictEqual(first.nodeName, 'SPAN');
  submit(form);
  assert.strictEqual(errOf(form, 'customer_email'), first, 'the same span is reused');
  assert.strictEqual(wrapper.children.length, 2, 'no second span was added');
  assert.strictEqual(first.hidden, false);
  assert.strictEqual(first.textContent, 'EMAIL_MSG');
});

test('lead-form.js: editing an invalid field clears its own error (input and change), and only its own', () => {
  const form = makeForm();
  load({ forms: [form] });
  submit(form); // all four wrong
  edit(form, 'customer_name');
  assert.strictEqual(form.f.customer_name.getAttribute('aria-invalid'), null, 'invalid flag removed');
  assert.strictEqual(form.f.customer_name.getAttribute('aria-describedby'), null, 'description removed');
  assert.strictEqual(errOf(form, 'customer_name').textContent, '', 'message emptied');
  assert.strictEqual(errOf(form, 'customer_name').hidden, true, 'and hidden');
  assert.strictEqual(form.f.customer_email.getAttribute('aria-invalid'), 'true', 'the others are left alone');
  assert.strictEqual(errOf(form, 'customer_email').textContent, 'EMAIL_MSG');
  edit(form, 'customer_email', 'change');
  assert.strictEqual(form.f.customer_email.getAttribute('aria-invalid'), null, 'a change event clears it too');
  // Typing in a field that is not invalid does nothing (and does not throw).
  assert.doesNotThrow(() => edit(form, 'customer_phone'));
  assert.strictEqual(form.f.customer_phone.getAttribute('aria-describedby'), null);
  // A bare event with no target never throws.
  assert.doesNotThrow(() => form.dispatch('input', { type: 'input' }));
});

test('lead-form.js: fixing the fields and resubmitting clears the old errors and sends; a stale server message is cleared on a new try', () => {
  const form = makeForm();
  const page = load({ forms: [form] });
  submit(form);
  fillValid(form);
  submit(form);
  assert.strictEqual(page.requests.length, 1, 'valid now: sent');
  ['customer_name', 'dbgr_company', 'customer_email', 'guest_count'].forEach((name) => {
    assert.strictEqual(form.f[name].getAttribute('aria-invalid'), null, name + ' no longer invalid');
    assert.strictEqual(form.f[name].getAttribute('aria-describedby'), null, name + ' no longer described');
    assert.strictEqual(errOf(form, name).textContent, '', name + ' message gone');
  });

  // A server error sits in the bottom status; the next failed try replaces it with field errors only.
  const form2 = makeForm();
  const page2 = load({ forms: [form2] });
  fillValid(form2);
  submit(form2);
  page2.requests[0].answer(429, { message: 'Too many.' });
  assert.strictEqual(form2.status.textContent, 'LIMIT_MSG');
  assert.strictEqual(form2.f.customer_email.getAttribute('aria-invalid'), null, 'a server error is not pinned to a field');
  form2.f.customer_name.value = '';
  submit(form2);
  assert.strictEqual(form2.status.textContent, '', 'the old server message is cleared');
  assert.strictEqual(errOf(form2, 'customer_name').textContent, 'NAME_MSG');
});

test('lead-form.js: an existing aria-describedby is kept; only the script\'s own id is added and removed', () => {
  const form = makeForm();
  load({ forms: [form] });
  fillValid(form, { customer_email: 'bad' });
  form.f.customer_email.setAttribute('aria-describedby', 'theme-hint');
  submit(form);
  const id = form.f.customer_email.getAttribute('id') + '-err';
  assert.strictEqual(form.f.customer_email.getAttribute('aria-describedby'), 'theme-hint ' + id);
  submit(form);
  assert.strictEqual(form.f.customer_email.getAttribute('aria-describedby'), 'theme-hint ' + id, 'not added twice');
  edit(form, 'customer_email');
  assert.strictEqual(form.f.customer_email.getAttribute('aria-describedby'), 'theme-hint', 'the theme\'s own token survives');
});

test('lead-form.js: while sending the button is aria-disabled, never disabled, so keyboard focus is not lost; a double submit still sends once', () => {
  const form = makeForm();
  const page = load({ forms: [form] });
  fillValid(form);
  form.button.focus();
  submit(form);
  assert.strictEqual(form.button.getAttribute('aria-disabled'), 'true');
  assert.strictEqual(form.button.disabled, false);
  submit(form);
  assert.strictEqual(page.requests.length, 1, 'the busy guard, not the disabled attribute, stops the second send');
  page.requests[0].answer(500, null);
  assert.strictEqual(form.button.getAttribute('aria-disabled'), null, 'released after the answer');
  submit(form);
  assert.strictEqual(page.requests.length, 2, 'a retry works after a failure');
});

test('lead-form.js: success moves focus to the confirmation (made focusable); a rejected send does not', () => {
  const ok = makeForm();
  const page = load({ forms: [ok] });
  fillValid(ok);
  submit(ok);
  assert.strictEqual(ok.status.focused, false, 'not focused while sending');
  page.requests[0].answer(200, { success: true, enquiry_number: 'E-1' });
  assert.strictEqual(ok.fields.hidden, true);
  assert.strictEqual(ok.status.getAttribute('tabindex'), '-1');
  assert.strictEqual(ok.status.focused, true);
  assert.match(ok.status.className, /dbgr-lead__status--ok/);

  const bad = makeForm();
  const page2 = load({ forms: [bad] });
  fillValid(bad);
  submit(bad);
  page2.requests[0].answer(500, null);
  assert.strictEqual(bad.status.focused, false, 'a failure leaves focus where it was');
  assert.strictEqual(bad.status.getAttribute('tabindex'), null);
});

test('lead-form.js: the event date picker starts at today (visitor calendar) unless the markup already sets a minimum', () => {
  const two = (n) => String(n).padStart(2, '0');
  const iso = (d) => d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate());
  const form = makeForm();
  const before = iso(new Date());
  load({ forms: [form] });
  const after = iso(new Date());
  assert.match(form.f.event_date.getAttribute('min'), /^\d{4}-\d{2}-\d{2}$/);
  assert.ok([before, after].indexOf(form.f.event_date.getAttribute('min')) !== -1, 'today, read from the clock');

  const own = makeForm();
  own.f.event_date.setAttribute('min', '2031-01-01');
  load({ forms: [own] });
  assert.strictEqual(own.f.event_date.getAttribute('min'), '2031-01-01', 'a minimum already in the markup is kept');

  const inert = makeForm();
  load({ config: false, forms: [inert] });
  assert.strictEqual(inert.f.event_date.getAttribute('min'), null, 'an inert form is left alone');
});

test('lead-form.js: a required field that is missing from the markup still blocks the send and says why in the status line', () => {
  const form = makeForm();
  const page = load({ forms: [form] });
  fillValid(form);
  delete form.f.dbgr_company; // markup without the company box, but the form says it is required
  submit(form);
  assert.strictEqual(page.requests.length, 0, 'not sent');
  assert.strictEqual(form.status.textContent, 'COMPANY_MSG', 'nothing to attach it to, so it is the bottom status');
});

test('lead-form.js source: ES5 only, no markup injection, no storage, no hard-coded host, no direct tag, no working name', () => {
  assert.doesNotMatch(SOURCE, /innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\b|new Function/);
  assert.doesNotMatch(SOURCE, /localStorage|sessionStorage|document\.cookie|indexedDB/, 'nothing is stored in the browser');
  assert.doesNotMatch(SOURCE, /https?:\/\//, 'no hard-coded address: every URL comes from the server configuration');
  assert.doesNotMatch(SOURCE, /\bfbq\b|\bgtag\b|dataLayer|ttq/, 'no tracking tag is touched directly');
  assert.doesNotMatch(SOURCE, new RegExp('mini' + 's', 'i'), 'no working name');
  assert.match(SOURCE, /textContent/, 'dynamic text uses textContent');
  assert.match(SOURCE, /'use strict'/);
  assert.doesNotMatch(SOURCE, /\.disabled\s*=/, 'the button is never set disabled (aria-disabled keeps focus on it)');
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
