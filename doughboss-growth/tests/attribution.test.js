'use strict';
/*
 * WP-04 node tests for public/js/dbgr-attribution.js: the sanitiser (identical to the TypeScript oracle on every fixture
 * case) and the capture rules (nothing without consent, measurement versus advertising fields, withdrawal, first touch,
 * the 1.5 KB cookie budget, a cookie that cannot be written).
 *
 * The browser script is an ES5 IIFE that reads window/document, so each test runs the REAL file inside a node:vm context
 * with a small fake DOM (a cookie jar, a location, a referrer, the consent API the consent script exposes). No jsdom and
 * no network. The real rendered page and the real catering form are covered by
 * web/scripts/wp-local/growth/wp04-attribution.mjs.
 *
 * Tests that need acorn (the ES5 gate) or tsx (the exporter check) are SKIPPED with a visible reason when missing.
 */
const test = require('node:test');
const assert = require('node:assert');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

const PLUGIN_DIR = path.resolve(__dirname, '..');
const SCRIPT_PATH = path.join(PLUGIN_DIR, 'public', 'js', 'dbgr-attribution.js');
const SCRIPT = fs.readFileSync(SCRIPT_PATH, 'utf8');
const FIXTURE = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures', 'attribution-cases.json'), 'utf8'));
const GATE = path.join(PLUGIN_DIR, 'scripts', 'es5-check.mjs');
const WEB_ROOT = path.resolve(PLUGIN_DIR, '..', 'web');

/* ---------------------------------------------------------------- fake DOM */

class Emitter {
  constructor() {
    this.listeners = {};
  }
  addEventListener(type, fn) {
    (this.listeners[type] = this.listeners[type] || []).push(fn);
  }
  dispatchEvent(event) {
    (this.listeners[event.type] || []).slice().forEach((fn) => fn.call(this, event));
    return true;
  }
}

/**
 * options: search, pathname, hostname, referrer, https, consent ({measurement, advertising, chosen}), cookie (raw value),
 * noConsentApi, cookieThrows.
 */
function makeEnv(options) {
  const opts = Object.assign({ search: '', pathname: '/catering/', hostname: 'doughboss.example', referrer: '', https: true, consent: { measurement: false, advertising: false, chosen: false }, cookie: '', noConsentApi: false }, options || {});
  const doc = new Emitter();
  doc.referrer = opts.referrer;
  doc.cookieJar = opts.cookie; /* raw value of dbgr_attr, or '' */
  doc.cookieWrites = [];
  doc.cookieThrows = Boolean(opts.cookieThrows);
  Object.defineProperty(doc, 'cookie', {
    get() {
      return doc.cookieJar ? 'a=b; dbgr_attr=' + doc.cookieJar + '; dbgr_consent=zzz' : 'a=b; dbgr_consent=zzz';
    },
    set(value) {
      if (doc.cookieThrows) {
        throw new Error('cookies blocked');
      }
      doc.cookieWrites.push(value);
      const m = /^dbgr_attr=([^;]*)/.exec(value);
      if (m) {
        doc.cookieJar = /Max-Age=0/.test(value) ? '' : m[1];
      }
    },
  });
  const win = {
    document: doc,
    location: { protocol: opts.https ? 'https:' : 'http:', search: opts.search, pathname: opts.pathname, hostname: opts.hostname },
  };
  win.window = win;
  const state = Object.assign({}, opts.consent);
  win.consentState = state;
  if (!opts.noConsentApi) {
    win.DoughBossGrowth = {
      consent: {
        get: () => Object.assign({ version: '1' }, state),
      },
    };
  } else {
    win.DoughBossGrowth = {};
  }
  return { win, doc, state };
}

function run(env) {
  const context = vm.createContext({ window: env.win, document: env.doc, Date, JSON, Object, String, encodeURIComponent, decodeURIComponent, RegExp });
  vm.runInContext(SCRIPT, context, { filename: 'dbgr-attribution.js' });
  return env;
}

/** Change consent the way the consent script does: update the state, then fire the event on document. */
function changeConsent(env, next) {
  Object.assign(env.state, next);
  env.doc.dispatchEvent({ type: 'doughboss-growth:consent-changed', detail: { measurement: env.state.measurement, advertising: env.state.advertising, version: '1', chosen: env.state.chosen } });
}

const plain = (value) => JSON.parse(JSON.stringify(value));
const stored = (env) => (env.doc.cookieJar ? JSON.parse(decodeURIComponent(env.doc.cookieJar)) : null);
const encodeCookie = (obj) => encodeURIComponent(JSON.stringify(obj));
const CAMPAIGN_URL = '?utm_source=test&utm_medium=cpc&utm_campaign=eofy&gclid=x&fbclid=f1';

/* ---------------------------------------------------------------- oracle parity */

function sanitiseWith(source) {
  const env = makeEnv();
  const context = vm.createContext({ window: env.win, document: env.doc, Date, JSON, Object, String, encodeURIComponent, decodeURIComponent, RegExp });
  vm.runInContext(source, context, { filename: 'dbgr-attribution.js' });
  return (input) => plain(env.win.DoughBossGrowth.attribution.sanitise(input));
}

test('fixture sanity: the oracle file is well formed and is not trivially empty', () => {
  assert.strictEqual(FIXTURE.schema_version, 1);
  assert.ok(FIXTURE.cases.length >= 100, 'at least 100 cases');
  assert.match(FIXTURE.source_sha256, /^[0-9a-f]{64}$/);
  const names = FIXTURE.cases.map((c) => c.name);
  assert.strictEqual(new Set(names).size, names.length, 'case names are unique');
  assert.ok(FIXTURE.cases.some((c) => Object.keys(c.expected).length > 0), 'some cases keep fields');
  assert.ok(FIXTURE.cases.some((c) => Object.keys(c.expected).length === 0), 'some cases drop everything');
});

test('the browser sanitiser returns the oracle result for every fixture case, key order included', () => {
  const sanitise = sanitiseWith(SCRIPT);
  const mismatches = [];
  FIXTURE.cases.forEach((c) => {
    const actual = sanitise(c.input);
    if (JSON.stringify(actual) !== JSON.stringify(c.expected)) {
      mismatches.push(c.name + ': expected ' + JSON.stringify(c.expected) + ' got ' + JSON.stringify(actual));
    }
  });
  assert.deepStrictEqual(mismatches, []);
});

test('the only cases where the companion differs from the TypeScript schema are the host-rule cases', () => {
  const differing = FIXTURE.cases.filter((c) => JSON.stringify(c.ts_expected) !== JSON.stringify(c.expected)).map((c) => c.name);
  assert.deepStrictEqual(differing, FIXTURE.host_rule_cases);
  differing.forEach((name) => {
    const c = FIXTURE.cases.find((item) => item.name === name);
    assert.strictEqual(c.host_rule_differs, true);
    const dropped = Object.keys(c.ts_expected).filter((key) => !(key in c.expected));
    assert.deepStrictEqual(dropped, ['referrerHost'], name + ' differs only by the referrer host');
  });
});

test('negative control: a sanitiser with a rule removed fails the fixture, so the parity test above can fail', () => {
  const mutate = (from, to) => SCRIPT.replace(from, () => to); /* a function replacer: "$'" in the text is not a replacement pattern */
  const broken = {
    'control characters not refused': mutate('var CONTROL_RE = /[\\u0000-\\u001f\\u007f]/;', 'var CONTROL_RE = /(?!)/;'),
    'cap raised to 500': mutate('var PARAM_MAX = 120;', 'var PARAM_MAX = 500;'),
    'path query allowed': mutate("'^\\\\/[^?#' + WS + ']*$'", "'^\\\\/[^' + WS + ']*$'"),
    'host rule removed': mutate('return text !== null && HOST_RE.test(text) ? text : null;', 'return text;'),
    'cap counted in UTF-16 units': mutate("return text.replace(SURROGATE_PAIR_RE, 'x').length;", 'return text.length;'),
  };
  Object.keys(broken).forEach((label) => {
    assert.notStrictEqual(broken[label], SCRIPT, label + ': the mutation must change the source');
    const sanitise = sanitiseWith(broken[label]);
    const mismatches = FIXTURE.cases.filter((c) => JSON.stringify(sanitise(c.input)) !== JSON.stringify(c.expected));
    assert.ok(mismatches.length > 0, label + ' must be caught by at least one fixture case');
  });
});

test('hostile values are refused: control characters, 121 characters, a path with a query, a non-string', () => {
  const sanitise = sanitiseWith(SCRIPT);
  assert.deepStrictEqual(sanitise({ utmSource: 'a\u0000b', utmMedium: 'x'.repeat(121), landingPath: '/a?b=1', gclid: 5, referrerHost: 'https://a.example' }), {});
  assert.deepStrictEqual(sanitise({ utmSource: ' ok ', landingPath: '/ok', referrerHost: 'a.example' }), { utmSource: 'ok', referrerHost: 'a.example', landingPath: '/ok' });
  assert.deepStrictEqual(sanitise(null), {});
  assert.deepStrictEqual(sanitise('x'), {});
});

test('the oracle fixture is current: it records the hash of attribution-schema.ts, and the exporter agrees', (t) => {
  const schema = path.join(WEB_ROOT, 'src', 'lib', 'attribution-schema.ts');
  if (!fs.existsSync(schema)) {
    t.skip('web/src/lib/attribution-schema.ts not found next to the plugin');
    return;
  }
  const hash = crypto.createHash('sha256').update(fs.readFileSync(schema)).digest('hex');
  assert.strictEqual(FIXTURE.source_sha256, hash, 'attribution-schema.ts changed: regenerate the fixture with export-attribution-fixtures.ts');
});

const TSX = path.join(WEB_ROOT, 'node_modules', '.bin', 'tsx');
const EXPORTER = path.join(WEB_ROOT, 'scripts', 'wp-oracle', 'export-attribution-fixtures.ts');
const SKIP_TSX = fs.existsSync(TSX) && fs.existsSync(EXPORTER) ? false : 'web/node_modules/.bin/tsx or the exporter not found next to the plugin';

test('attribution-cases.json equals the TypeScript export (export-attribution-fixtures.ts --check)', { skip: SKIP_TSX }, () => {
  const result = spawnSync(TSX, [EXPORTER, '--check'], { encoding: 'utf8', env: Object.assign({}, process.env, { NODE_PATH: path.join(WEB_ROOT, 'node_modules') }) });
  assert.strictEqual(result.status, 0, result.stdout + result.stderr);
  assert.match(result.stdout, /up to date/);
});

/* ---------------------------------------------------------------- inert without the consent script */

test('without the consent API the script does nothing: no global, no cookie, no listener', () => {
  const env = run(makeEnv({ noConsentApi: true, search: CAMPAIGN_URL }));
  assert.strictEqual(env.win.DoughBossGrowth.attribution, undefined);
  assert.deepStrictEqual(env.doc.cookieWrites, []);
  assert.deepStrictEqual(env.doc.listeners, {});
  const none = run(makeEnv({ search: CAMPAIGN_URL }));
  none.win.DoughBossGrowth = undefined;
  assert.doesNotThrow(() => run(none), 'a missing global is not an error either');
});

/* ---------------------------------------------------------------- consent gating */

test('no consent: nothing is written even when the URL carries campaign parameters', () => {
  const env = run(makeEnv({ search: CAMPAIGN_URL, referrer: 'https://www.google.com/search?q=x' }));
  assert.deepStrictEqual(env.doc.cookieWrites, []);
  assert.strictEqual(env.doc.cookieJar, '');
});

test('no consent: an existing cookie from an earlier visit is removed', () => {
  const env = run(makeEnv({ search: CAMPAIGN_URL, cookie: encodeCookie({ utmSource: 'old' }) }));
  assert.strictEqual(env.doc.cookieJar, '', 'the cookie is cleared');
  assert.match(env.doc.cookieWrites[0], /^dbgr_attr=; Max-Age=0; Path=\/; SameSite=Lax; Secure$/);
});

test('measurement only: UTM, referrer host, landing path and first-seen time are kept; click ids are not', () => {
  const env = run(makeEnv({ search: CAMPAIGN_URL, referrer: 'https://WWW.Google.com/search?q=secret', consent: { measurement: true, advertising: false, chosen: true } }));
  const record = stored(env);
  assert.deepStrictEqual(Object.keys(record), ['utmSource', 'utmMedium', 'utmCampaign', 'referrerHost', 'landingPath', 'firstSeenAt']);
  assert.strictEqual(record.utmSource, 'test');
  assert.strictEqual(record.referrerHost, 'www.google.com', 'host only, lower case, no path and no query');
  assert.strictEqual(record.landingPath, '/catering/');
  assert.match(record.firstSeenAt, /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/);
  assert.ok(!('gclid' in record) && !('fbclid' in record), 'no click id without advertising consent');
});

test('advertising only: the click ids are kept and nothing that needs measurement consent', () => {
  const env = run(makeEnv({ search: CAMPAIGN_URL, referrer: 'https://www.google.com/', consent: { measurement: false, advertising: true, chosen: true } }));
  assert.deepStrictEqual(stored(env), { gclid: 'x', fbclid: 'f1' });
});

test('both consents: every field, cookie attributes are 90 days, path, SameSite=Lax and Secure on https', () => {
  const env = run(makeEnv({ search: CAMPAIGN_URL + '&gbraid=g&wbraid=w&msclkid=m&utm_term=t&utm_content=c', consent: { measurement: true, advertising: true, chosen: true } }));
  const record = stored(env);
  assert.deepStrictEqual(Object.keys(record), ['utmSource', 'utmMedium', 'utmCampaign', 'utmTerm', 'utmContent', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'landingPath', 'firstSeenAt']);
  assert.strictEqual(env.doc.cookieWrites.length, 1);
  assert.match(env.doc.cookieWrites[0], /; Max-Age=7776000; Path=\/; SameSite=Lax; Secure$/);
  const plainHttp = run(makeEnv({ https: false, search: CAMPAIGN_URL, consent: { measurement: true, advertising: false, chosen: true } }));
  assert.doesNotMatch(plainHttp.doc.cookieWrites[0], /Secure/);
});

test('a visit with no campaign parameters and no referrer still records the first landing path (first touch)', () => {
  const env = run(makeEnv({ pathname: '/catering/corporate', consent: { measurement: true, advertising: false, chosen: true } }));
  const record = stored(env);
  assert.deepStrictEqual(Object.keys(record), ['landingPath', 'firstSeenAt']);
  assert.strictEqual(record.landingPath, '/catering/corporate');
});

test('the visitor\'s own site is not a referrer, and a malformed referrer is ignored', () => {
  const own = run(makeEnv({ referrer: 'https://doughboss.example/menu', consent: { measurement: true, advertising: false, chosen: true } }));
  assert.ok(!('referrerHost' in stored(own)));
  const junk = run(makeEnv({ referrer: 'android-app://com.google.android.gm/', consent: { measurement: true, advertising: false, chosen: true } }));
  assert.ok(!('referrerHost' in stored(junk)));
  const userinfo = run(makeEnv({ referrer: 'https://user:pw@l.facebook.com:8443/x?y=1', consent: { measurement: true, advertising: false, chosen: true } }));
  assert.strictEqual(stored(userinfo).referrerHost, 'l.facebook.com', 'userinfo, port, path and query never reach the cookie');
  assert.ok(!JSON.stringify(stored(userinfo)).includes('pw'));
});

test('query parsing: plus is a space, encoded values decode, a bad escape drops only that value, the first duplicate wins', () => {
  const env = run(makeEnv({ search: '?utm_source=a%20b+c&utm_medium=%E0%A4%A&utm_campaign=first&utm_campaign=second&unrelated=1&gclid', consent: { measurement: true, advertising: true, chosen: true } }));
  const record = stored(env);
  assert.strictEqual(record.utmSource, 'a b c');
  assert.ok(!('utmMedium' in record), 'a malformed escape is dropped, not guessed');
  assert.strictEqual(record.utmCampaign, 'first');
  assert.ok(!('gclid' in record), 'an empty value is dropped');
  assert.ok(!JSON.stringify(record).includes('unrelated'));
});

test('hostile URL values never reach the cookie (control character, 121 characters)', () => {
  const env = run(makeEnv({ search: '?utm_source=a%00b&utm_medium=' + 'm'.repeat(121) + '&utm_campaign=fine', consent: { measurement: true, advertising: true, chosen: true } }));
  const record = stored(env);
  assert.ok(!('utmSource' in record) && !('utmMedium' in record));
  assert.strictEqual(record.utmCampaign, 'fine');
});

/* ---------------------------------------------------------------- consent changes */

test('consent given after landing (Accept on the banner) captures the landing still in the URL', () => {
  const env = run(makeEnv({ search: CAMPAIGN_URL }));
  assert.deepStrictEqual(env.doc.cookieWrites, [], 'nothing before the choice');
  changeConsent(env, { measurement: true, advertising: true, chosen: true });
  assert.strictEqual(stored(env).utmSource, 'test');
  assert.strictEqual(stored(env).gclid, 'x');
  assert.strictEqual(env.doc.cookieWrites.length, 1);
  changeConsent(env, { measurement: true, advertising: true, chosen: true });
  assert.strictEqual(env.doc.cookieWrites.length, 1, 'an identical event rewrites nothing');
});

test('Reject after Accept deletes the cookie; partial withdrawal removes only that category', () => {
  const env = run(makeEnv({ search: CAMPAIGN_URL, consent: { measurement: true, advertising: true, chosen: true } }));
  assert.ok(stored(env).gclid);
  changeConsent(env, { measurement: true, advertising: false, chosen: true });
  assert.ok(!('gclid' in stored(env)) && !('fbclid' in stored(env)), 'click ids leave when advertising is withdrawn');
  assert.strictEqual(stored(env).utmSource, 'test', 'measurement fields stay');
  changeConsent(env, { measurement: false, advertising: false, chosen: true });
  assert.strictEqual(env.doc.cookieJar, '', 'both withdrawn: the cookie is gone');
  assert.match(env.doc.cookieWrites[env.doc.cookieWrites.length - 1], /Max-Age=0/);
});

test('consent given later for advertising on the same landing adds the click id (the landing carries campaign parameters)', () => {
  const env = run(makeEnv({ search: CAMPAIGN_URL, consent: { measurement: true, advertising: false, chosen: true } }));
  assert.ok(!('gclid' in stored(env)));
  changeConsent(env, { measurement: true, advertising: true, chosen: true });
  assert.strictEqual(stored(env).gclid, 'x');
  assert.strictEqual(stored(env).utmSource, 'test');
});

/* ---------------------------------------------------------------- first touch */

test('first touch wins: a later landing without campaign parameters keeps the stored record', () => {
  const first = { utmSource: 'google', gclid: 'old', landingPath: '/catering/', firstSeenAt: '2026-09-01T00:00:00.000Z' };
  const env = run(makeEnv({ pathname: '/menu/', cookie: encodeCookie(first), consent: { measurement: true, advertising: true, chosen: true } }));
  assert.deepStrictEqual(stored(env), first);
  assert.deepStrictEqual(env.doc.cookieWrites, [], 'an unchanged record is not rewritten');
});

test('a later landing that carries campaign parameters replaces the stored record', () => {
  const first = { utmSource: 'google', landingPath: '/catering/', firstSeenAt: '2026-09-01T00:00:00.000Z' };
  const env = run(makeEnv({ pathname: '/catering/corporate', search: '?utm_source=newsletter', cookie: encodeCookie(first), consent: { measurement: true, advertising: false, chosen: true } }));
  assert.strictEqual(stored(env).utmSource, 'newsletter');
  assert.strictEqual(stored(env).landingPath, '/catering/corporate');
});

test('a malformed or oversized stored cookie is ignored and replaced; it never throws', () => {
  ['not json', encodeURIComponent('[1,2'), 'x'.repeat(2000), encodeURIComponent('"just a string"'), '%E0%A4%A'].forEach((raw) => {
    const env = makeEnv({ search: '?utm_source=fresh', cookie: raw, consent: { measurement: true, advertising: false, chosen: true } });
    assert.doesNotThrow(() => run(env));
    assert.strictEqual(stored(env).utmSource, 'fresh', 'replaced by the current landing');
  });
});

test('a stored cookie holding invalid fields is cleaned on read (get() returns only sanitised fields)', () => {
  const dirty = { utmSource: 'ok', utmMedium: 'bad\u0000', landingPath: '/x?y=1', evil: '<b>' };
  const env = run(makeEnv({ cookie: encodeCookie(dirty), consent: { measurement: true, advertising: false, chosen: true } }));
  assert.deepStrictEqual(plain(env.win.DoughBossGrowth.attribution.get()), { utmSource: 'ok' });
});

/* ---------------------------------------------------------------- budget and failure */

test('the cookie never exceeds 1500 encoded characters: the least useful fields are dropped first', () => {
  const long = (c) => c.repeat(120);
  const search = '?utm_source=' + long('s') + '&utm_medium=' + long('m') + '&utm_campaign=' + long('c') + '&utm_term=' + long('t') + '&utm_content=' + long('n')
    + '&gclid=' + long('g') + '&gbraid=' + long('b') + '&wbraid=' + long('w') + '&fbclid=' + long('f') + '&msclkid=' + long('k');
  const env = run(makeEnv({ search, referrer: 'https://' + 'h'.repeat(60) + '.example/', consent: { measurement: true, advertising: true, chosen: true } }));
  assert.ok(env.doc.cookieJar.length <= 1500, 'encoded length ' + env.doc.cookieJar.length);
  const record = stored(env);
  assert.ok(record.gclid && record.gbraid && record.wbraid && record.fbclid && record.msclkid, 'click ids survive');
  assert.ok(record.utmSource && record.landingPath, 'source and landing path survive');
  assert.ok(!('utmContent' in record) && !('utmTerm' in record), 'the least useful fields went first');
  assert.deepStrictEqual(plain(env.win.DoughBossGrowth.attribution.sanitise(record)), record, 'what was written is still valid');
});

test('multibyte values count their encoded size: 120 CJK characters in every parameter still fits or is trimmed to fit', () => {
  const cjk = encodeURIComponent('\u4e2d'.repeat(120));
  const env = run(makeEnv({ search: '?utm_source=' + cjk + '&utm_medium=' + cjk + '&utm_campaign=' + cjk, consent: { measurement: true, advertising: true, chosen: true } }));
  assert.ok(env.doc.cookieJar.length <= 1500, 'encoded length ' + env.doc.cookieJar.length);
  assert.ok(stored(env).landingPath, 'the landing path is kept');
});

test('cookies blocked by the browser: no exception, no state, the API still answers', () => {
  const env = makeEnv({ search: CAMPAIGN_URL, cookieThrows: true, consent: { measurement: true, advertising: true, chosen: true } });
  assert.doesNotThrow(() => run(env));
  assert.deepStrictEqual(plain(env.win.DoughBossGrowth.attribution.get()), {});
});

test('a consent API that throws is treated as no consent', () => {
  const env = makeEnv({ search: CAMPAIGN_URL, cookie: encodeCookie({ utmSource: 'old' }) });
  env.win.DoughBossGrowth.consent.get = () => {
    throw new Error('boom');
  };
  assert.doesNotThrow(() => run(env));
  assert.strictEqual(env.doc.cookieJar, '', 'fail closed: the cookie is cleared');
});

/* ---------------------------------------------------------------- ES5 gate on the shipped file */

function acornAvailable() {
  const candidates = [];
  if (process.env.ACORN_PATH) {
    candidates.push(path.resolve(process.env.ACORN_PATH, 'package.json'));
  }
  candidates.push(path.resolve(PLUGIN_DIR, '..', 'web', 'node_modules', 'acorn', 'package.json'));
  return candidates.some((candidate) => fs.existsSync(candidate));
}
const SKIP_ACORN = acornAvailable() ? false : 'acorn not found (set ACORN_PATH to an acorn package directory)';

test('dbgr-attribution.js passes the ES5 gate, and arrow functions, template literals and innerHTML planted in a copy fail it (negative control)', { skip: SKIP_ACORN }, () => {
  const ok = spawnSync(process.execPath, [GATE, SCRIPT_PATH], { encoding: 'utf8' });
  assert.strictEqual(ok.status, 0, ok.stdout + ok.stderr);
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'dbgr-wp04-es5-'));
  [
    ['arrow', 'var bad = () => 1;'],
    ['template', 'var bad = `x`;'],
    ['innerhtml', 'doc.body.innerHTML = "x";'],
    ['const', 'const bad = 1;'],
  ].forEach(([label, line]) => {
    const file = path.join(dir, 'dbgr-attribution-' + label + '.js');
    fs.writeFileSync(file, SCRIPT.replace("var COOKIE = 'dbgr_attr';", "var COOKIE = 'dbgr_attr';\n\t" + line));
    const bad = spawnSync(process.execPath, [GATE, file], { encoding: 'utf8' });
    assert.notStrictEqual(bad.status, 0, 'the gate must reject the planted ' + label);
  });
});
