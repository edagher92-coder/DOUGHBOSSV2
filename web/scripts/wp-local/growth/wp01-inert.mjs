#!/usr/bin/env node
/**
 * WP-01 inert check for the doughboss-growth companion on the local WordPress runtime.
 *
 * With every feature flag off the companion must not change a single byte of public HTML. This script captures the
 * public pages from a running runtime, normalises the only legitimately varying part (WordPress nonces), and compares
 * a capture taken WITHOUT the companion to one taken WITH it. It also checks the companion's admin side on the
 * "with" run (settings page renders, /health returns every flag false, tables exist).
 *
 *   node wp01-inert.mjs capture <baseUrl> <outDir>      fetch /, /order/, /catering/, /locations/ and write <outDir>
 *   node wp01-inert.mjs compare <dirA> <dirB>           exit 1 unless every page is identical after masking nonces
 *   node wp01-inert.mjs admin   <baseUrl> <outDir>      log in as the Playground admin; check settings page and /health
 *   node wp01-inert.mjs roundtrip <baseUrl> <outDir>    save settings through admin-post.php with a real nonce (and a forged one)
 *   node wp01-inert.mjs selftest <dir>                  negative control: plant a change in a copy of a capture and prove compare() fails
 *
 * Only built-in Node modules are used (Node 18+ fetch). Requests go to the runtime on 127.0.0.1 only.
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const PAGES = [
  { slug: 'home', path: '/' },
  { slug: 'order', path: '/order/' },
  { slug: 'catering', path: '/catering/' },
  { slug: 'locations', path: '/locations/' },
];

const sha = (text) => crypto.createHash('sha256').update(text).digest('hex');

/** Mask nonces: 8 to 12 hex characters next to anything called nonce. Returns { text, count }. */
export function maskNonces(html) {
  let count = 0;
  const bump = (match, ...groups) => {
    count += 1;
    return groups[0] + 'NONCE' + groups[1];
  };
  let text = html;
  // JSON or JS style: "nonce":"abc1234567", nonce: 'abc1234567', restNonce = "..."
  text = text.replace(/((?:nonce|Nonce)["']?\s*[:=]\s*["'])[0-9a-f]{8,12}(["'])/g, bump);
  // Query strings: _wpnonce=abc1234567, ?nonce=abc1234567 (also the entity-encoded ampersand)
  text = text.replace(/((?:_wpnonce|[?&;]nonce)=)[0-9a-f]{8,12}()/g, bump);
  // Hidden inputs: name="_wpnonce" ... value="abc1234567"
  text = text.replace(/(name=["']?_?[a-z_]*nonce[a-z_]*["']?[^>]*?value=["'])[0-9a-f]{8,12}(["'])/gi, bump);
  text = text.replace(/(value=["'])[0-9a-f]{8,12}(["'][^>]*?name=["']?_?[a-z_]*nonce[a-z_]*["']?)/gi, bump);
  return { text, count };
}

function assetUrls(html) {
  const urls = [];
  const re = /(?:src|href)=["']([^"']+)["']/g;
  let match;
  while ((match = re.exec(html))) {
    urls.push(match[1]);
  }
  return urls;
}

async function fetchPage(base, p) {
  const response = await fetch(base + p, { redirect: 'follow', headers: { 'user-agent': 'wp01-inert/1' } });
  const body = await response.text();
  const setCookie = typeof response.headers.getSetCookie === 'function' ? response.headers.getSetCookie() : [];
  return {
    status: response.status,
    finalPath: new URL(response.url).pathname + new URL(response.url).search,
    body,
    headers: {
      'content-type': response.headers.get('content-type') || '',
      'cache-control': response.headers.get('cache-control') || '',
      link: response.headers.get('link') || '',
      'x-robots-tag': response.headers.get('x-robots-tag') || '',
    },
    cookieNames: setCookie.map((line) => line.split('=')[0]).sort(),
  };
}

async function capture(base, outDir) {
  fs.mkdirSync(outDir, { recursive: true });
  const summary = { base, pages: {} };
  for (const page of PAGES) {
    const result = await fetchPage(base, page.path);
    const masked = maskNonces(result.body);
    fs.writeFileSync(path.join(outDir, page.slug + '.raw.html'), result.body);
    fs.writeFileSync(path.join(outDir, page.slug + '.html'), masked.text);
    summary.pages[page.slug] = {
      path: page.path,
      status: result.status,
      finalPath: result.finalPath,
      bytes: Buffer.byteLength(masked.text),
      noncesMasked: masked.count,
      sha256: sha(masked.text),
      headers: result.headers,
      cookieNames: result.cookieNames,
      assets: assetUrls(masked.text),
      mentionsCompanion: /doughboss[-_]growth|dbgr[-_]|DoughBossGrowth/i.test(masked.text),
    };
    process.stdout.write(`captured ${page.path} -> ${result.status} ${masked.text.length} chars, ${masked.count} nonce(s) masked, sha256 ${summary.pages[page.slug].sha256.slice(0, 12)}\n`);
  }
  fs.writeFileSync(path.join(outDir, 'summary.json'), JSON.stringify(summary, null, 2));
  return summary;
}

function firstDifferences(a, b, limit = 5) {
  const la = a.split('\n');
  const lb = b.split('\n');
  const out = [];
  for (let i = 0; i < Math.max(la.length, lb.length) && out.length < limit; i += 1) {
    if (la[i] !== lb[i]) {
      out.push(`  line ${i + 1}\n    A: ${(la[i] || '').slice(0, 220)}\n    B: ${(lb[i] || '').slice(0, 220)}`);
    }
  }
  return out;
}

function compare(dirA, dirB) {
  const a = JSON.parse(fs.readFileSync(path.join(dirA, 'summary.json'), 'utf8'));
  const b = JSON.parse(fs.readFileSync(path.join(dirB, 'summary.json'), 'utf8'));
  let failures = 0;
  for (const page of PAGES) {
    const pa = a.pages[page.slug];
    const pb = b.pages[page.slug];
    const problems = [];
    if (!pa || !pb) {
      problems.push('page missing from one capture');
    } else {
      if (pa.status !== pb.status) problems.push(`status ${pa.status} vs ${pb.status}`);
      if (pa.finalPath !== pb.finalPath) problems.push(`final path ${pa.finalPath} vs ${pb.finalPath}`);
      if (pa.sha256 !== pb.sha256) problems.push('normalised HTML differs');
      if (JSON.stringify(pa.assets) !== JSON.stringify(pb.assets)) problems.push('asset URL list differs');
      if (JSON.stringify(pa.headers) !== JSON.stringify(pb.headers)) problems.push('response headers differ');
      if (JSON.stringify(pa.cookieNames) !== JSON.stringify(pb.cookieNames)) problems.push(`Set-Cookie names differ (${pa.cookieNames} vs ${pb.cookieNames})`);
      if (pb.mentionsCompanion) problems.push('the capture with the companion mentions doughboss-growth / dbgr / DoughBossGrowth');
      if (pa.status !== 200) problems.push(`not HTTP 200 (${pa.status}): the comparison would be meaningless`);
      if (pa.bytes < 2000) problems.push(`suspiciously small page (${pa.bytes} bytes)`);
    }
    if (problems.length === 0) {
      process.stdout.write(`IDENTICAL  ${page.path}  (${pa.bytes} bytes, sha256 ${pa.sha256.slice(0, 12)}, ${pa.noncesMasked} vs ${pb.noncesMasked} nonces masked)\n`);
    } else {
      failures += 1;
      process.stdout.write(`DIFFERENT  ${page.path}: ${problems.join('; ')}\n`);
      if (pa && pb && pa.sha256 !== pb.sha256) {
        const textA = fs.readFileSync(path.join(dirA, page.slug + '.html'), 'utf8');
        const textB = fs.readFileSync(path.join(dirB, page.slug + '.html'), 'utf8');
        firstDifferences(textA, textB).forEach((line) => process.stdout.write(line + '\n'));
      }
    }
  }
  process.stdout.write(failures === 0 ? 'ALL PAGES IDENTICAL (nonces masked)\n' : `${failures} PAGE(S) DIFFER\n`);
  return failures === 0 ? 0 : 1;
}

/** Minimal cookie jar over fetch. */
function jar() {
  const cookies = new Map();
  return {
    absorb(response) {
      const lines = typeof response.headers.getSetCookie === 'function' ? response.headers.getSetCookie() : [];
      lines.forEach((line) => {
        const [pair] = line.split(';');
        const index = pair.indexOf('=');
        cookies.set(pair.slice(0, index).trim(), pair.slice(index + 1));
      });
    },
    header() {
      return Array.from(cookies.entries()).map(([k, v]) => `${k}=${v}`).join('; ');
    },
  };
}


async function login(base) {
  const cookies = jar();
  const loginPage = await fetch(`${base}/wp-login.php`, { redirect: 'manual' });
  cookies.absorb(loginPage);
  const form = new URLSearchParams({ log: 'admin', pwd: 'password', 'wp-submit': 'Log In', redirect_to: `${base}/wp-admin/`, testcookie: '1' });
  const response = await fetch(`${base}/wp-login.php`, {
    method: 'POST',
    redirect: 'manual',
    headers: { 'content-type': 'application/x-www-form-urlencoded', cookie: cookies.header() + '; wordpress_test_cookie=WP%20Cookie%20check' },
    body: form,
  });
  cookies.absorb(response);
  return /wordpress_logged_in_/.test(cookies.header()) ? cookies : null;
}

async function roundtrip(base, outDir) {
  fs.mkdirSync(outDir, { recursive: true });
  const checks = [];
  const record = (name, ok, detail) => {
    checks.push({ name, ok, detail });
    process.stdout.write(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  (' + detail + ')' : ''}\n`);
  };
  const cookies = await login(base);
  record('admin login worked', cookies !== null);
  if (!cookies) {
    return 1;
  }
  const restNonce = (await (await fetch(`${base}/wp-admin/admin-ajax.php?action=rest-nonce`, { headers: { cookie: cookies.header() } })).text()).trim();
  const health = async () => (await fetch(`${base}/?rest_route=/doughboss-growth/v1/health`, { headers: { cookie: cookies.header(), 'x-wp-nonce': restNonce } })).json();
  const page = await (await fetch(`${base}/wp-admin/admin.php?page=doughboss-growth`, { headers: { cookie: cookies.header() } })).text();
  const nonceMatch = page.match(/name="_wpnonce"[^>]*value="([0-9a-f]+)"/) || page.match(/value="([0-9a-f]+)"[^>]*name="_wpnonce"/) || page.match(/id="_wpnonce" name="_wpnonce" value="([0-9a-f]+)"/);
  record('settings page carries a save nonce', !!nonceMatch);
  if (!nonceMatch) {
    return 1;
  }
  const post = (nonce, fields) => fetch(`${base}/wp-admin/admin-post.php`, {
    method: 'POST',
    redirect: 'manual',
    headers: { 'content-type': 'application/x-www-form-urlencoded', cookie: cookies.header() },
    body: new URLSearchParams({ action: 'doughboss_growth_save_settings', _wpnonce: nonce, ...fields }),
  });

  // 1. Forged nonce: refused, nothing saved.
  const forged = await post('0000000000', { 'dbgr[features][coming_soon]': '1' });
  record('a forged nonce is refused (403)', forged.status === 403, `HTTP ${forged.status}`);
  let h = await health();
  record('nothing was saved by the forged request', h.flags_configured.coming_soon === false);

  // 2. Real nonce: coming_soon (no prerequisite) and gtm (needs the banner) ticked.
  const saved = await post(nonceMatch[1], {
    'dbgr[features][coming_soon]': '1',
    'dbgr[features][gtm]': '1',
    'dbgr[features][hero_enhanced]': '1',
    'dbgr[sender_legal_name]': 'Example Pty Ltd',
    'dbgr[coming_soon_headline]': 'Something exciting is coming',
  });
  const location = saved.headers.get('location') || '';
  record('a valid save redirects back to the Growth page', saved.status === 302 && /page=doughboss-growth/.test(location) && /dbgr_saved=1/.test(location), location.replace(base, ''));
  record('the dependency error is reported for gtm', /dbgr_err=[^&]*gtm_requires_consent_banner/.test(location));
  h = await health();
  record('coming_soon is now configured and effective', h.flags_configured.coming_soon === true && h.flags.coming_soon === true);
  record('gtm was forced off (needs the consent banner)', h.flags_configured.gtm === false && h.flags.gtm === false);
  record('the cancelled hero flag is not accepted', !('hero_enhanced' in h.flags_configured));
  record('no module is active (no module file ships yet), so nothing runs', Object.values(h.modules_active).every((v) => v === false));
  record('the sender legal name gap is closed', !h.confirm_gaps.includes('sender_legal_name') && h.confirm_gaps.includes('privacy_policy_url'));

  // 3. Waitlist refused while the privacy URL is missing.
  const second = await post(nonceMatch[1], { 'dbgr[features][waitlist]': '1', 'dbgr[sender_legal_name]': 'Example Pty Ltd' });
  record('waitlist is refused without a privacy-policy URL', /waitlist_requires_privacy_policy_url/.test(second.headers.get('location') || ''));
  fs.writeFileSync(path.join(outDir, 'roundtrip-checks.json'), JSON.stringify(checks, null, 2));
  const failed = checks.filter((check) => !check.ok);
  process.stdout.write(failed.length === 0 ? `ALL ${checks.length} ROUND-TRIP CHECKS PASSED\n` : `${failed.length} ROUND-TRIP CHECK(S) FAILED\n`);
  return failed.length === 0 ? 0 : 1;
}

async function admin(base, outDir) {
  fs.mkdirSync(outDir, { recursive: true });
  const checks = [];
  const record = (name, ok, detail) => {
    checks.push({ name, ok, detail });
    process.stdout.write(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  (' + detail + ')' : ''}\n`);
  };

  // Anonymous /health must be refused.
  const anon = await fetch(`${base}/?rest_route=/doughboss-growth/v1/health`);
  record('anonymous GET /doughboss-growth/v1/health is refused', anon.status === 401 || anon.status === 403, `HTTP ${anon.status}`);

  // Log in as the Playground admin.
  const cookies = jar();
  const loginPage = await fetch(`${base}/wp-login.php`, { redirect: 'manual' });
  cookies.absorb(loginPage);
  const form = new URLSearchParams({ log: 'admin', pwd: 'password', 'wp-submit': 'Log In', redirect_to: `${base}/wp-admin/`, testcookie: '1' });
  const login = await fetch(`${base}/wp-login.php`, {
    method: 'POST',
    redirect: 'manual',
    headers: { 'content-type': 'application/x-www-form-urlencoded', cookie: cookies.header() + '; wordpress_test_cookie=WP%20Cookie%20check' },
    body: form,
  });
  cookies.absorb(login);
  const loggedIn = /wordpress_logged_in_/.test(cookies.header());
  record('admin login worked (Playground default credentials)', loggedIn, `HTTP ${login.status}`);
  if (!loggedIn) {
    fs.writeFileSync(path.join(outDir, 'admin-checks.json'), JSON.stringify(checks, null, 2));
    return 1;
  }

  const nonceResponse = await fetch(`${base}/wp-admin/admin-ajax.php?action=rest-nonce`, { headers: { cookie: cookies.header() } });
  const restNonce = (await nonceResponse.text()).trim();
  record('REST nonce obtained', /^[0-9a-f]{8,12}$/.test(restNonce), `HTTP ${nonceResponse.status}`);

  const healthResponse = await fetch(`${base}/?rest_route=/doughboss-growth/v1/health`, { headers: { cookie: cookies.header(), 'x-wp-nonce': restNonce } });
  const healthText = await healthResponse.text();
  fs.writeFileSync(path.join(outDir, 'health.json'), healthText);
  let health = null;
  try {
    health = JSON.parse(healthText);
  } catch {
    health = null;
  }
  record('GET /doughboss-growth/v1/health returns 200 JSON for the admin', healthResponse.status === 200 && health !== null, `HTTP ${healthResponse.status}`);
  if (health) {
    const flags = Object.keys(health.flags || {});
    record('/health reports exactly 11 flags', flags.length === 11, flags.join(','));
    record('/health: every effective flag is false', flags.length === 11 && flags.every((flag) => health.flags[flag] === false));
    record('/health: every configured flag is false', Object.keys(health.flags_configured || {}).every((flag) => health.flags_configured[flag] === false));
    record('/health: no hero flag', !('hero_enhanced' in (health.flags || {})));
    record('/health: core is ready and the companion is not inert', health.core_ready === true && health.kill_switch === false, `core ${health.core_version}`);
    record('/health: schema installed (storage_ready)', health.storage_ready === true, `db_version ${health.db_version}`);
    record('/health: companion version 0.1.0', health.plugin_version === '0.1.0');
    // The waitlist module is registered "always" (WP-05): a person must be able to opt out, be exported and be erased even while sign-ups
    // are switched off. With every flag off it adds no public output (the byte comparison above proves it).
    const alwaysOn = ['waitlist'];
    const activeModules = Object.entries(health.modules_active || {}).filter(([, active]) => active === true).map(([name]) => name);
    record('/health: no module active except the registry "always" module (waitlist: opt-out, export, erasure)', activeModules.every((name) => alwaysOn.includes(name)), `active: ${activeModules.join(', ') || 'none'}`);
    record('/health: cache-control no-store', (healthResponse.headers.get('cache-control') || '').includes('no-store'), healthResponse.headers.get('cache-control') || '');
  }

  const settings = await fetch(`${base}/wp-admin/admin.php?page=doughboss-growth`, { headers: { cookie: cookies.header() } });
  const settingsHtml = await settings.text();
  fs.writeFileSync(path.join(outDir, 'settings-page.html'), settingsHtml);
  record('Growth settings page renders (HTTP 200, no fatal error)', settings.status === 200 && settingsHtml.includes('DoughBoss Growth') && !/Fatal error|There has been a critical error/i.test(settingsHtml), `HTTP ${settings.status}`);
  const boxes = (settingsHtml.match(/name="dbgr\[features\]\[[a-z_]+\]"/g) || []).length;
  record('settings page lists 11 feature checkboxes, none ticked', boxes === 11 && !/name="dbgr\[features\]\[[a-z_]+\]"[^>]*checked/.test(settingsHtml), `${boxes} boxes`);
  record('settings page has a nonce field and posts to admin-post.php', /name="_wpnonce"/.test(settingsHtml) && /admin-post\.php/.test(settingsHtml));
  record('settings page shows the [CONFIRM] owner decisions', settingsHtml.includes('[CONFIRM:'));
  record('settings page mentions no hero feature', !/hero/i.test(settingsHtml.replace(/<(script|style)[\s\S]*?<\/\1>/gi, '').replace(/class="[^"]*"/g, '')));

  // The core Dashboard menu still exists beside it (the companion only adds a submenu).
  const adminHome = await fetch(`${base}/wp-admin/`, { headers: { cookie: cookies.header() } });
  const adminHtml = await adminHome.text();
  record('wp-admin loads with a Growth submenu under DoughBoss', adminHome.status === 200 && /page=doughboss-growth/.test(adminHtml));

  fs.writeFileSync(path.join(outDir, 'admin-checks.json'), JSON.stringify(checks, null, 2));
  const failed = checks.filter((check) => !check.ok);
  process.stdout.write(failed.length === 0 ? `ALL ${checks.length} ADMIN CHECKS PASSED\n` : `${failed.length} ADMIN CHECK(S) FAILED\n`);
  return failed.length === 0 ? 0 : 1;
}

/** Negative control for compare(): plant (a) an injected script tag and (b) a changed byte, expect DIFFERENT. */
function selftest(dir) {
  const summary = JSON.parse(fs.readFileSync(path.join(dir, 'summary.json'), 'utf8'));
  const planted = [
    { name: 'injected companion script', mutate: (html) => html.replace('</body>', '<script src="/wp-content/plugins/doughboss-growth/public/js/dbgr-x.js"></script></body>') },
    { name: 'one changed byte', mutate: (html) => html.replace('<html', '<html data-x="1"') },
  ];
  let ok = true;
  for (const plant of planted) {
    const copy = fs.mkdtempSync(path.join(fs.mkdtempSync(path.join(process.env.TMPDIR || '/tmp', 'wp01-selftest-')), 'cap-'));
    const copied = { base: summary.base, pages: {} };
    for (const page of PAGES) {
      const source = fs.readFileSync(path.join(dir, page.slug + '.html'), 'utf8');
      const text = page.slug === 'home' ? plant.mutate(source) : source;
      fs.writeFileSync(path.join(copy, page.slug + '.html'), text);
      copied.pages[page.slug] = {
        ...summary.pages[page.slug],
        sha256: sha(text),
        bytes: Buffer.byteLength(text),
        assets: assetUrls(text),
        mentionsCompanion: /doughboss[-_]growth|dbgr[-_]|DoughBossGrowth/i.test(text),
      };
    }
    fs.writeFileSync(path.join(copy, 'summary.json'), JSON.stringify(copied));
    const originalWrite = process.stdout.write.bind(process.stdout);
    let captured = '';
    process.stdout.write = (chunk) => { captured += chunk; return true; };
    const code = compare(dir, copy);
    process.stdout.write = originalWrite;
    const detected = code === 1 && /DIFFERENT  \/:/.test(captured);
    process.stdout.write(`${detected ? 'PASS' : 'FAIL'}  negative control "${plant.name}" is detected as DIFFERENT (compare exit ${code})\n`);
    ok = ok && detected;
  }
  return ok ? 0 : 1;
}

const [, , command, ...rest] = process.argv;
const isMain = process.argv[1] && path.resolve(process.argv[1]) === path.resolve(new URL(import.meta.url).pathname);
if (isMain) {
  let code = 2;
  try {
    if (command === 'capture' && rest.length === 2) {
      await capture(rest[0].replace(/\/$/, ''), rest[1]);
      code = 0;
    } else if (command === 'compare' && rest.length === 2) {
      code = compare(rest[0], rest[1]);
    } else if (command === 'selftest' && rest.length === 1) {
      code = selftest(rest[0]);
    } else if (command === 'roundtrip' && rest.length === 2) {
      code = await roundtrip(rest[0].replace(/\/$/, ''), rest[1]);
    } else if (command === 'admin' && rest.length === 2) {
      code = await admin(rest[0].replace(/\/$/, ''), rest[1]);
    } else {
      process.stderr.write('Usage: wp01-inert.mjs capture <baseUrl> <outDir> | compare <dirA> <dirB> | admin <baseUrl> <outDir> | selftest <dir>\n');
    }
  } catch (error) {
    process.stderr.write(`ERROR: ${error && error.stack ? error.stack : error}\n`);
    code = 2;
  }
  process.exit(code);
}
