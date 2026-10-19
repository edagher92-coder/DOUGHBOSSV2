'use strict';
/*
 * WP-03 node tests for public/js/dbgr-datalayer.js: the typed dataLayer dispatcher and DoughBossGrowth.track.
 *
 * The real ES5 file runs inside a node:vm context with a fake document (an event emitter) and the REAL typed-event list
 * (content/events.json, reduced to the shape the PHP side prints into window.DoughBossGrowthConfig). A few tests also run
 * the real dbgr-consent.js beside it. No jsdom, no network.
 *
 * Tests that need tsx (the export check) or core's source tree (the selector contract) are SKIPPED with a visible
 * reason when those are missing, never passed.
 */
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

const PLUGIN_DIR = path.resolve(__dirname, '..');
const DATALAYER_JS = fs.readFileSync(path.join(PLUGIN_DIR, 'public', 'js', 'dbgr-datalayer.js'), 'utf8');
const CONSENT_JS = fs.readFileSync(path.join(PLUGIN_DIR, 'public', 'js', 'dbgr-consent.js'), 'utf8');
const EVENTS_FILE = JSON.parse(fs.readFileSync(path.join(PLUGIN_DIR, 'content', 'events.json'), 'utf8'));

/** The browser shape of the event list, exactly as Consent::validate_events() reduces it. */
const EVENTS = {};
EVENTS_FILE.event_names.forEach((name) => {
  EVENTS[name] = {};
  Object.keys(EVENTS_FILE.events[name].params).forEach((param) => {
    const spec = EVENTS_FILE.events[name].params[param];
    EVENTS[name][param] = spec.type === 'enum' ? { type: 'enum', values: spec.values.slice() } : { type: spec.type };
  });
});

/* ---------------------------------------------------------------- fake DOM */

class FakeEvent {
  constructor(type, init) {
    this.type = type;
    this.detail = init ? init.detail : undefined;
    this.target = null;
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

class FakeNode {
  constructor(selectors) {
    this.selectors = selectors || {};
    this.textContent = '';
  }
  querySelector(selector) {
    return Object.prototype.hasOwnProperty.call(this.selectors, selector) ? this.selectors[selector] : null;
  }
}

/**
 * @param {object} options config (merged over the defaults), consent (the fake consent API, or null for none),
 *   catering (true to put a [data-doughboss-catering] root on the page), observer (false to remove MutationObserver).
 */
function makeEnv(options) {
  const opts = Object.assign({ config: {}, consent: { measurement: true, advertising: false }, catering: false, observer: true, readyState: 'complete' }, options || {});
  const doc = new Emitter();
  doc.readyState = opts.readyState;
  const cateringRoot = new FakeNode();
  doc.querySelector = (selector) => (selector === '[data-doughboss-catering]' && opts.catering ? cateringRoot : null);
  const win = { document: doc, CustomEvent: FakeEvent };
  win.window = win;
  win.DoughBossGrowthConfig = Object.assign({ gtm: true, events: EVENTS, locations: { '1': 'revesby', '3': 'bankstown' } }, opts.config);
  if (opts.consent) {
    win.DoughBossGrowth = { consent: { get: () => Object.assign({ chosen: true, version: '1' }, opts.consent) } };
  }
  const observers = [];
  if (opts.observer) {
    win.MutationObserver = function (callback) {
      this.callback = callback;
      this.observe = (node, init) => {
        this.node = node;
        this.init = init;
      };
      observers.push(this);
    };
  }
  return { win, doc, cateringRoot, observers };
}

function load(env, code) {
  vm.runInContext(code || DATALAYER_JS, vm.createContext({ window: env.win, document: env.doc }), { filename: 'dbgr-datalayer.js' });
  return env;
}

const plain = (value) => JSON.parse(JSON.stringify(value));
const pushed = (env) => plain(Array.prototype.slice.call(env.win.dataLayer || []));
const track = (env, name, params) => env.win.DoughBossGrowth.track(name, params);

function coreEvent(env, type, properties, id) {
  env.doc.dispatchEvent(new FakeEvent('doughboss:marketing-event', { detail: { schema_version: 1, event_id: id === undefined ? 'id-' + Math.random() : id, event_type: type, properties: properties } }));
}

/* ---------------------------------------------------------------- taxonomy gate */

test('UNKNOWN EVENT REFUSED: names outside events.json (including purchase, the cancelled hero event and prototype keys) push nothing', () => {
  const env = load(makeEnv());
  const refused = ['', 'purchase', 'hero_explore', 'page_view', 'Select_Store', 'select_store ', '__proto__', 'constructor', 'toString', 'hasOwnProperty', 'valueOf', 'refund', 'purchase_simulated'];
  refused.forEach((name) => {
    assert.strictEqual(track(env, name, { store: 'revesby', cta: 'x', destination: 'y' }), false, 'refused: ' + JSON.stringify(name));
  });
  [undefined, null, 5, {}, [], true, function () {}].forEach((name) => {
    assert.strictEqual(track(env, name, {}), false, 'refused non-string name');
  });
  assert.strictEqual(env.win.dataLayer, undefined, 'dataLayer was never even created');
  /* positive control: a taxonomy name with the same arguments is pushed */
  assert.strictEqual(track(env, 'select_store', { store: 'revesby' }), true);
  assert.deepStrictEqual(pushed(env), [{ event: 'select_store', store: 'revesby' }]);
});

test('the taxonomy in events.json has 13 events, no hero event, and a begin_checkout payment_method of SQUARE or PAY_AT_SHOP', () => {
  assert.strictEqual(EVENTS_FILE.event_names.length, 13);
  assert.ok(!('hero_explore' in EVENTS));
  assert.deepStrictEqual(EVENTS.begin_checkout.payment_method.values, ['SQUARE', 'PAY_AT_SHOP']);
  assert.ok(!JSON.stringify(EVENTS).match(new RegExp(['web' + 'gl', 'sprites', 'hero', 'minis'].join('|'), 'i')));
});

test('with Tag Manager off nothing is pushed and no listener is installed; the same call with it on is pushed (positive control)', () => {
  const off = load(makeEnv({ config: { gtm: false }, catering: true }));
  assert.strictEqual(track(off, 'select_store', { store: 'revesby' }), false);
  coreEvent(off, 'add_to_cart', { content_ids: ['9'], content_name: 'Zaatar', content_category: 'Manoush', value: 4, quantity: 1 });
  off.doc.dispatchEvent(new FakeEvent('doughboss:catering-enquiry-created', { detail: { enquiry_number: 'E1' } }));
  assert.strictEqual(off.win.dataLayer, undefined);
  assert.strictEqual(off.observers.length, 0, 'no MutationObserver either');
  assert.strictEqual(off.doc.listeners['doughboss:marketing-event'], undefined, 'no core listener');
  assert.strictEqual(typeof off.win.DoughBossGrowth.track, 'function', 'track still exists so other modules can call it blindly');
  const on = load(makeEnv());
  assert.strictEqual(track(on, 'select_store', { store: 'revesby' }), true);
});

test('missing or empty configuration: track exists and refuses everything', () => {
  const env = makeEnv();
  delete env.win.DoughBossGrowthConfig;
  load(env);
  assert.strictEqual(track(env, 'select_store', { store: 'revesby' }), false);
  const empty = load(makeEnv({ config: { events: [] } }));
  assert.strictEqual(track(empty, 'select_store', { store: 'revesby' }), false);
});

/* ---------------------------------------------------------------- consent gate */

test('consent gate: nothing before a choice; measurement-only and advertising-only each allow; no consent API means refuse', () => {
  const denied = load(makeEnv({ consent: { measurement: false, advertising: false } }));
  assert.strictEqual(track(denied, 'select_store', { store: 'revesby' }), false, 'denied');
  assert.strictEqual(denied.win.dataLayer, undefined);
  const measurement = load(makeEnv({ consent: { measurement: true, advertising: false } }));
  assert.strictEqual(track(measurement, 'select_store', { store: 'revesby' }), true, 'measurement');
  const advertising = load(makeEnv({ consent: { measurement: false, advertising: true } }));
  assert.strictEqual(track(advertising, 'select_store', { store: 'revesby' }), true, 'advertising');
  const none = load(makeEnv({ consent: null }));
  assert.strictEqual(track(none, 'select_store', { store: 'revesby' }), false, 'no consent API: fail closed');
  const odd = load(makeEnv({ consent: { measurement: 'yes', advertising: 1 } }));
  assert.strictEqual(track(odd, 'select_store', { store: 'revesby' }), false, 'truthy strings are not consent');
});

test('the consent state is read at push time: a later grant allows later events (earlier ones were dropped, not queued)', () => {
  let granted = false;
  const env = makeEnv({ consent: null });
  env.win.DoughBossGrowth = { consent: { get: () => ({ measurement: granted, advertising: false }) } };
  load(env);
  assert.strictEqual(track(env, 'select_store', { store: 'revesby' }), false);
  granted = true;
  assert.strictEqual(track(env, 'select_store', { store: 'bankstown' }), true);
  assert.deepStrictEqual(pushed(env), [{ event: 'select_store', store: 'bankstown' }], 'only the post-consent event exists');
});

/* ---------------------------------------------------------------- parameter allow-list and value rules */

test('parameters outside the event allow-list are dropped; the event key cannot be overridden; prototypes are not polluted', () => {
  const env = load(makeEnv());
  track(env, 'select_store', { store: 'revesby', email: 'a@b.co', phone: '0400 000 000', event: 'purchase', gtm: 1, consent: 'x', value: 12 });
  assert.deepStrictEqual(pushed(env), [{ event: 'select_store', store: 'revesby' }]);
  const hostile = JSON.parse('{"__proto__": {"polluted": "yes"}, "constructor": {"prototype": {"polluted": "yes"}}, "store": "revesby"}');
  track(env, 'select_store', hostile);
  assert.strictEqual({}.polluted, undefined, 'Object.prototype untouched (host realm)');
  assert.strictEqual(vm.runInContext('({}).polluted', vm.createContext({})), undefined);
  assert.deepStrictEqual(pushed(env)[1], { event: 'select_store', store: 'revesby' });
  track(env, 'select_store', Object.create({ store: 'bankstown' }));
  assert.deepStrictEqual(pushed(env).length, 3);
  assert.deepStrictEqual(pushed(env)[2], { event: 'select_store' }, 'inherited properties are not read');
  track(env, 'select_store', null);
  track(env, 'select_store');
  track(env, 'select_store', 'revesby');
  assert.deepStrictEqual(pushed(env).slice(3), [{ event: 'select_store' }, { event: 'select_store' }, { event: 'select_store' }], 'a missing or non-object params still pushes the bare event');
});

test('PERSONAL DATA DROPPED: any string with "@" or a digit run longer than 8 never reaches dataLayer', () => {
  const env = load(makeEnv());
  const cases = [
    ['email', 'jo@example.com', false],
    ['lone at sign', '@', false],
    ['email with label', 'order for jo@example.com', false],
    ['nine digits', '123456789', false],
    ['ten digit mobile', '0400000000', false],
    ['mobile with spaces', '0400 000 000', false],
    ['landline with brackets', '(02) 9123 4567', false],
    ['mobile with dashes', '0400-000-000', false],
    ['digits inside text', 'call 0400000000 now', false],
    ['eight digits (allowed)', '12345678', true],
    ['a date (eight digits, allowed)', '2026-10-02', true],
    ['short number', 'pizza 12', true],
    ['plain slug', 'cheese-pizza', true],
  ];
  cases.forEach(([label, value, kept]) => {
    env.win.dataLayer = [];
    track(env, 'cta_click', { cta: value, destination: '/menu/' });
    const entry = pushed(env)[0];
    if (kept) {
      assert.strictEqual(entry.cta, value, label + ' is kept');
    } else {
      assert.ok(!('cta' in entry), label + ' is dropped');
    }
    assert.strictEqual(entry.destination, '/menu/', label + ': other parameters are unaffected');
  });
  /* the same rule on every free-text parameter of every event, and on numbers */
  Object.keys(EVENTS).forEach((name) => {
    Object.keys(EVENTS[name]).filter((param) => EVENTS[name][param].type === 'string').forEach((param) => {
      env.win.dataLayer = [];
      const params = {};
      params[param] = 'x@y.com';
      track(env, name, params);
      assert.ok(!(param in pushed(env)[0]), name + '.' + param + ' drops an email');
      params[param] = '0412345678';
      env.win.dataLayer = [];
      track(env, name, params);
      assert.ok(!(param in pushed(env)[0]), name + '.' + param + ' drops a mobile number');
    });
  });
  env.win.dataLayer = [];
  track(env, 'add_to_cart', { item_slug: 'a', item_name: 'b', quantity: 1, value_cents: 100000000 });
  assert.ok(!('value_cents' in pushed(env)[0]), 'a nine-digit number is dropped');
  env.win.dataLayer = [];
  track(env, 'add_to_cart', { item_slug: 'a', item_name: 'b', quantity: 1, value_cents: 99999999 });
  assert.strictEqual(pushed(env)[0].value_cents, 99999999, 'eight digits is allowed');
});

test('value rules: enums are exact, integers are whole non-negative numbers, strings are trimmed, cleaned and capped at 100', () => {
  const env = load(makeEnv());
  const one = (name, params) => {
    env.win.dataLayer = [];
    track(env, name, params);
    return pushed(env)[0];
  };
  assert.deepStrictEqual(one('select_store', { store: 'Revesby' }), { event: 'select_store' }, 'enum is case sensitive');
  assert.deepStrictEqual(one('select_store', { store: 'revesby ' }), { event: 'select_store' }, 'enum is exact');
  assert.deepStrictEqual(one('select_store', { store: ['revesby'] }), { event: 'select_store' }, 'enum is not an array');
  assert.deepStrictEqual(one('quote_step', { step: 2 }), { event: 'quote_step', step: 2 }, 'numeric enum');
  assert.deepStrictEqual(one('quote_step', { step: '2' }), { event: 'quote_step' }, 'a numeric string is not the number');
  assert.deepStrictEqual(one('quote_step', { step: 3 }), { event: 'quote_step' }, 'outside the enum');
  [-1, 1.5, NaN, Infinity, '5', null, true, {}, [], 100000000].forEach((bad) => {
    assert.ok(!('quantity' in one('remove_from_cart', { item_slug: 'a', quantity: bad })), 'integer refuses ' + String(bad));
  });
  [0, 1, 99999999].forEach((good) => {
    assert.strictEqual(one('remove_from_cart', { item_slug: 'a', quantity: good }).quantity, good, 'integer keeps ' + good);
  });
  assert.strictEqual(one('cta_click', { cta: '  Order now  ', destination: '/x' }).cta, 'Order now', 'trimmed');
  assert.strictEqual(one('cta_click', { cta: 'a\u0000b\nc\td', destination: '/x' }).cta, 'a b c d', 'control characters become spaces');
  assert.strictEqual(one('cta_click', { cta: 'x'.repeat(250), destination: '/x' }).cta.length, 100, 'capped at 100');
  [' ', '', '\n', 5, null, {}, ['a'], true].forEach((bad) => {
    assert.ok(!('cta' in one('cta_click', { cta: bad, destination: '/x' })), 'string refuses ' + JSON.stringify(bad));
  });
  assert.deepStrictEqual(one('generate_lead', { form: 'waitlist', store: 'none', category: 'x', guest_band: 'y' }), { event: 'generate_lead', form: 'waitlist', store: 'none', category: 'x', guest_band: 'y' }, 'optional parameters pass');
  assert.deepStrictEqual(one('generate_lead', { form: 'newsletter' }), { event: 'generate_lead' }, 'an unknown form value is dropped');
});

/* ---------------------------------------------------------------- core marketing events */

test('CORE PURCHASE NEVER REACHES dataLayer AS purchase: it becomes order_placed with integer cents', () => {
  const env = load(makeEnv());
  coreEvent(env, 'purchase', { currency: 'AUD', value: 23.45, num_items: 3, order_type: 'pickup', location_id: 1, channel: 'web' });
  const out = pushed(env);
  assert.deepStrictEqual(out, [{ event: 'order_placed', store: 'revesby', value_cents: 2345, item_count: 3 }]);
  assert.ok(out.every((entry) => entry.event !== 'purchase'), 'no purchase event');
  assert.ok(out.every((entry) => !('value' in entry) && !('currency' in entry) && !('transaction_id' in entry)), 'no GA4 purchase fields');
  /* even when the page itself tries to push a purchase through the public API */
  assert.strictEqual(track(env, 'purchase', { value: 1 }), false);
});

test('core add_to_cart, view_item and begin_checkout map to the taxonomy; money is converted to cents exactly once', () => {
  const env = load(makeEnv());
  coreEvent(env, 'add_to_cart', { content_ids: ['42'], content_name: 'Zaatar manoush', content_category: 'Manoush', content_type: 'product', currency: 'AUD', value: 19.99, quantity: 2 });
  coreEvent(env, 'view_item', { content_ids: ['custom-pizza'], content_name: 'Custom pizza', content_category: 'Pizza' });
  coreEvent(env, 'begin_checkout', { currency: 'AUD', value: 12.35, num_items: 2, order_type: 'pickup', location_id: 3, channel: 'web' });
  assert.deepStrictEqual(pushed(env), [
    { event: 'add_to_cart', item_slug: '42', item_name: 'Zaatar manoush', quantity: 2, value_cents: 1999 },
    { event: 'view_item', item_slug: 'custom-pizza', item_name: 'Custom pizza', category: 'Pizza' },
    { event: 'begin_checkout', store: 'bankstown', value_cents: 1235, item_count: 2 },
  ], 'begin_checkout has no payment_method because core 2.43.2 does not send one (a [CONFIRM] gap, never a guess)');
});

test('begin_checkout carries payment_method only when core sends exactly SQUARE or PAY_AT_SHOP', () => {
  const env = load(makeEnv());
  coreEvent(env, 'begin_checkout', { value: 5, num_items: 1, location_id: 1, payment_method: 'square' });
  coreEvent(env, 'begin_checkout', { value: 5, num_items: 1, location_id: 1, payment_method: 'PAY_AT_SHOP' });
  coreEvent(env, 'begin_checkout', { value: 5, num_items: 1, location_id: 1, payment_method: 'stripe' });
  coreEvent(env, 'begin_checkout', { value: 5, num_items: 1, location_id: 1, payment_method: 'PAY_AT_PICKUP' });
  assert.deepStrictEqual(pushed(env).map((entry) => entry.payment_method), ['SQUARE', 'PAY_AT_SHOP', undefined, undefined]);
});

test('core generate_lead (the after-hours pre-order request) is NOT forwarded: it must never count as a catering enquiry', () => {
  const env = load(makeEnv());
  coreEvent(env, 'generate_lead', { content_name: 'After-hours preorder request', content_category: 'Preorder', currency: 'AUD', location_id: 1, channel: 'web' });
  assert.strictEqual(env.win.dataLayer, undefined);
  /* positive control: the real catering signal does produce the lead */
  env.doc.dispatchEvent(new FakeEvent('doughboss:catering-enquiry-created', { detail: { enquiry_number: 'E100' } }));
  assert.deepStrictEqual(pushed(env), [{ event: 'generate_lead', form: 'catering_enquiry' }]);
});

test('core events with no taxonomy counterpart are ignored; malformed envelopes never throw', () => {
  const env = load(makeEnv());
  ['purchase_simulated', 'social_engagement', 'review_engagement', 'refund', 'cancel', 'fulfilment', 'page_view', '', undefined].forEach((type) => coreEvent(env, type, { value: 1 }));
  [null, undefined, 'x', 5, [], { event_type: 5 }, { event_type: 'purchase', properties: 'x' }, { event_type: 'purchase', properties: null }].forEach((detail) => {
    assert.doesNotThrow(() => env.doc.dispatchEvent(new FakeEvent('doughboss:marketing-event', { detail })));
  });
  assert.ok(pushed(env).every((entry) => entry.event === 'order_placed'), 'only the order_placed from the malformed purchase envelopes, with no parameters');
  assert.ok(pushed(env).every((entry) => Object.keys(entry).length === 1), 'and those carry no invented values');
});

test('shop mapping: only mapped ids get a store; unmapped and prototype-named ids are left out, never guessed', () => {
  const env = load(makeEnv());
  [1, '1', 3, 2, 99, '__proto__', 'constructor', 'toString', null, undefined, {}, [], 'revesby'].forEach((id) => {
    env.win.dataLayer = [];
    coreEvent(env, 'purchase', { value: 1, num_items: 1, location_id: id });
    const store = pushed(env)[0].store;
    if (id === 1 || id === '1') {
      assert.strictEqual(store, 'revesby');
    } else if (id === 3) {
      assert.strictEqual(store, 'bankstown');
    } else {
      assert.strictEqual(store, undefined, 'no store for ' + JSON.stringify(id));
    }
  });
  const none = load(makeEnv({ config: { locations: {} } }));
  coreEvent(none, 'purchase', { value: 1, num_items: 1, location_id: 1 });
  assert.strictEqual(pushed(none)[0].store, undefined, 'no map, no store');
});

test('personal data in a core envelope is dropped by the same rules (a customer name with an email, a long number)', () => {
  const env = load(makeEnv());
  coreEvent(env, 'add_to_cart', { content_ids: ['0400000000'], content_name: 'for jo@example.com', content_category: 'Manoush', value: 4, quantity: 1 });
  assert.deepStrictEqual(pushed(env), [{ event: 'add_to_cart', item_slug: undefined, quantity: 1, value_cents: 400 }].map((entry) => plain(entry)), 'item_slug and item_name are dropped');
});

test('the same core event id is pushed once; distinct ids are each pushed', () => {
  const env = load(makeEnv());
  coreEvent(env, 'add_to_cart', { content_ids: ['1'], content_name: 'A', content_category: 'B', value: 1, quantity: 1 }, 'same');
  coreEvent(env, 'add_to_cart', { content_ids: ['1'], content_name: 'A', content_category: 'B', value: 1, quantity: 1 }, 'same');
  coreEvent(env, 'add_to_cart', { content_ids: ['1'], content_name: 'A', content_category: 'B', value: 1, quantity: 1 }, 'other');
  assert.strictEqual(pushed(env).length, 2);
});

/* ---------------------------------------------------------------- catering enquiry lead */

test('catering enquiry (core 2.44.0 event): one generate_lead {form: catering_enquiry}; the reference is never pushed; repeats are deduplicated', () => {
  const env = load(makeEnv());
  const fire = (ref) => env.doc.dispatchEvent(new FakeEvent('doughboss:catering-enquiry-created', { detail: ref === undefined ? {} : { enquiry_number: ref } }));
  fire('CE-1001');
  fire('CE-1001');
  assert.deepStrictEqual(pushed(env), [{ event: 'generate_lead', form: 'catering_enquiry' }]);
  assert.ok(!JSON.stringify(pushed(env)).includes('CE-1001'), 'no reference in the dataLayer');
  fire('CE-1002');
  assert.strictEqual(pushed(env).length, 2, 'a different enquiry counts');
});

test('catering enquiry (interim): the .dbc-success box inside [data-doughboss-catering] triggers one lead; the deposit screen for the same reference does not', () => {
  const env = load(makeEnv({ catering: true }));
  assert.strictEqual(env.observers.length, 1);
  assert.strictEqual(env.observers[0].node, env.cateringRoot);
  assert.deepStrictEqual(plain(env.observers[0].init), { childList: true, subtree: true });
  const strong = new FakeNode();
  strong.textContent = 'CE-2001';
  const box = new FakeNode({ '.dbc-success-num strong': strong });
  env.cateringRoot.selectors['.dbc-success'] = box;
  env.observers[0].callback();
  env.observers[0].callback();
  assert.deepStrictEqual(pushed(env), [{ event: 'generate_lead', form: 'catering_enquiry' }]);
  /* the "Deposit received" screen reuses .dbc-success with the same reference */
  env.observers[0].callback();
  assert.strictEqual(pushed(env).length, 1, 'same reference: still one lead');
  /* both signals for one enquiry (the 2.44.0 event and the box): one lead */
  env.doc.dispatchEvent(new FakeEvent('doughboss:catering-enquiry-created', { detail: { enquiry_number: 'CE-2001' } }));
  assert.strictEqual(pushed(env).length, 1);
});

test('catering enquiry: a signal with no reference right after a lead is the same enquiry; with no box on the page the observer is never created', () => {
  const env = load(makeEnv({ catering: true }));
  env.doc.dispatchEvent(new FakeEvent('doughboss:catering-enquiry-created', { detail: { enquiry_number: 'CE-3001' } }));
  const box = new FakeNode({ '.dbc-success-num strong': null });
  env.cateringRoot.selectors['.dbc-success'] = box;
  env.observers[0].callback();
  assert.strictEqual(pushed(env).length, 1, 'unknown-reference signal within ten seconds is not a second lead');
  const noBox = load(makeEnv({ catering: false }));
  assert.strictEqual(noBox.observers.length, 0);
  const noObserverApi = load(makeEnv({ catering: true, observer: false }));
  assert.strictEqual(noObserverApi.observers.length, 0);
  assert.doesNotThrow(() => noObserverApi.doc.dispatchEvent(new FakeEvent('doughboss:catering-enquiry-created', { detail: {} })));
});

test('catering lead respects consent like every other event', () => {
  const env = load(makeEnv({ consent: { measurement: false, advertising: false } }));
  env.doc.dispatchEvent(new FakeEvent('doughboss:catering-enquiry-created', { detail: { enquiry_number: 'CE-4001' } }));
  assert.strictEqual(env.win.dataLayer, undefined);
});

/* ---------------------------------------------------------------- consent.js and datalayer.js together */

function bothScripts(cookie, mode) {
  const env = makeEnv({ consent: null, config: { consentVersion: '1', mode: mode || 'deny' } });
  env.doc.getElementById = () => null;
  env.doc.body = null;
  env.doc.cookie = cookie ? 'dbgr_consent=' + cookie : '';
  env.win.location = { protocol: 'https:' };
  const context = vm.createContext({ window: env.win, document: env.doc });
  vm.runInContext(CONSENT_JS, context, { filename: 'dbgr-consent.js' });
  vm.runInContext(DATALAYER_JS, context, { filename: 'dbgr-datalayer.js' });
  return env;
}

test('integration: with no stored choice (deny) events are refused; a stored measurement choice allows them', () => {
  const none = bothScripts('', 'deny');
  assert.strictEqual(track(none, 'select_store', { store: 'revesby' }), false);
  const stored = bothScripts(encodeURIComponent(JSON.stringify({ v: '1', m: 1, a: 0, ts: 1790899200 })), 'deny');
  assert.strictEqual(track(stored, 'select_store', { store: 'revesby' }), true);
  const rejected = bothScripts(encodeURIComponent(JSON.stringify({ v: '1', m: 0, a: 0, ts: 1790899200 })), 'deny');
  assert.strictEqual(track(rejected, 'select_store', { store: 'revesby' }), false, 'a stored reject refuses');
  const oldWording = bothScripts(encodeURIComponent(JSON.stringify({ v: '0', m: 1, a: 1, ts: 1790899200 })), 'deny');
  assert.strictEqual(track(oldWording, 'select_store', { store: 'revesby' }), false, 'a choice made under old wording does not count');
});

test('integration: notice-and-opt-out measures before a choice; the core purchase still becomes order_placed', () => {
  const env = bothScripts('', 'opt_out');
  coreEvent(env, 'purchase', { value: 10, num_items: 1, location_id: 1 });
  assert.deepStrictEqual(pushed(env), [{ event: 'order_placed', store: 'revesby', value_cents: 1000, item_count: 1 }]);
});

/* ---------------------------------------------------------------- contracts with the outside */

const CORE_SRC = process.env.DBGR_CORE_SRC || '/tmp/wp-src/candidate-2.43.2';
const SKIP_CORE = fs.existsSync(path.join(CORE_SRC, 'public', 'js', 'doughboss-marketing.js')) ? false : 'core source tree not found (set DBGR_CORE_SRC to a DoughBoss core checkout)';

test('CONTRACT with core: the events, selectors and filter the dispatcher relies on still exist in core', { skip: SKIP_CORE }, () => {
  const marketing = fs.readFileSync(path.join(CORE_SRC, 'public', 'js', 'doughboss-marketing.js'), 'utf8');
  assert.ok(marketing.includes("'doughboss:marketing-event'"), 'core still dispatches doughboss:marketing-event');
  assert.ok(marketing.includes("'doughboss:consent'"), 'core still listens for doughboss:consent');
  assert.ok(marketing.includes('event_type: name') && marketing.includes('properties: clean'), 'envelope shape event_type / properties');
  const catering = fs.readFileSync(path.join(CORE_SRC, 'public', 'js', 'doughboss-catering.js'), 'utf8');
  assert.ok(catering.includes('data-doughboss-catering'), 'catering root selector');
  assert.ok(catering.includes('dbc-success') && catering.includes('dbc-success-num'), 'success box selectors');
  const assets = fs.readFileSync(path.join(CORE_SRC, 'includes', 'class-doughboss-assets.php'), 'utf8');
  assert.ok(assets.includes("'doughboss_marketing_config'"), 'config filter name');
  const app = fs.readFileSync(path.join(CORE_SRC, 'public', 'js', 'doughboss.js'), 'utf8');
  assert.ok(/trackCommerce\('purchase'/.test(app), 'core still fires purchase from the browser (the reason it is renamed)');
  assert.ok(/trackCommerce\('generate_lead'[\s\S]{0,200}Preorder/.test(app), 'core generate_lead is still the pre-order request (the reason it is not forwarded)');
});

test('NEGATIVE CONTROL for the contract test: a source without the selectors fails the same assertions', { skip: SKIP_CORE }, () => {
  const stripped = fs.readFileSync(path.join(CORE_SRC, 'public', 'js', 'doughboss-catering.js'), 'utf8').split('dbc-success').join('renamed-box');
  assert.ok(!(stripped.includes('dbc-success') && stripped.includes('dbc-success-num')));
});

const WEB_ROOT = path.resolve(PLUGIN_DIR, '..', 'web');
const TSX = path.join(WEB_ROOT, 'node_modules', 'tsx', 'dist', 'cli.mjs');
const EXPORTER = path.join(WEB_ROOT, 'scripts', 'wp-oracle', 'export-events.ts');
const SKIP_TSX = fs.existsSync(TSX) && fs.existsSync(EXPORTER) ? false : 'web/node_modules/tsx/dist/cli.mjs or the exporter not found next to the plugin';

test('events.json equals the TypeScript export (export-events.ts --check)', { skip: SKIP_TSX }, () => {
  const result = spawnSync(process.execPath, [TSX, EXPORTER, '--check'], { encoding: 'utf8', env: Object.assign({}, process.env, { NODE_PATH: path.join(WEB_ROOT, 'node_modules') }) });
  assert.strictEqual(result.status, 0, result.stdout + result.stderr);
  assert.match(result.stdout, /up to date/);
});
