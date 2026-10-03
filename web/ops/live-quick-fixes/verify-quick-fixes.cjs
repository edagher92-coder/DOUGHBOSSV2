// Verifies the three live quick fixes against a LOCAL runtime that mirrors the live baseline plugin and theme.
// The runtime loads the snippets through a harness; add ?qf=0 to any URL to see the page without them.
// Usage: BASE=http://127.0.0.1:9420 node verify-quick-fixes.cjs
const { chromium } = require('../../node_modules/playwright-core');
const BASE = process.env.BASE || 'http://127.0.0.1:9420';
const results = [];
function check(name, ok, detail) { results.push({ name, ok: !!ok, detail }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '  ' + detail : '')); }

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  // 1. hero strip
  for (const [label, vp] of [['1280', { width: 1280, height: 800 }], ['390', { width: 390, height: 844 }]]) {
    for (const path of ['/order/', '/locations/', '/about-us/']) {
      const gaps = {};
      for (const q of ['?qf=0', '']) {
        const ctx = await b.newContext({ viewport: vp }); const p = await ctx.newPage();
        await p.goto(BASE + path + q, { waitUntil: 'load' }); await p.waitForTimeout(800);
        gaps[q || 'fix'] = await p.evaluate(() => {
          var bg = document.querySelector('.dbf-page-hero-bg'), hero = document.querySelector('.dbf-page-hero');
          if (!bg || !hero) { return null; }
          var a = bg.getBoundingClientRect(), h = hero.getBoundingClientRect();
          return { rightGap: Math.round((h.right - a.right) * 10) / 10, leftGap: Math.round((a.left - h.left) * 10) / 10, scrollW: document.documentElement.scrollWidth, clientW: document.documentElement.clientWidth };
        });
        await ctx.close();
      }
      const before = gaps['?qf=0'], after = gaps['fix'];
      check(`hero strip ${path} @${label}`, before && after && before.rightGap > 1 && after.rightGap <= 0.5 && after.leftGap <= 0.5 && after.scrollW <= after.clientW, JSON.stringify({ before: before && before.rightGap, after: after && after.rightGap, noOverflow: after && after.scrollW <= after.clientW }));
    }
  }
  // 2. card labels
  for (const path of ['/menu/', '/order/']) {
    const out = {};
    for (const q of ['?qf=0', '']) {
      const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } }); const p = await ctx.newPage();
      await p.goto(BASE + path + q, { waitUntil: 'load' });
      await p.waitForSelector('.db-card-img', { timeout: 60000 }).catch(() => null);
      await p.waitForTimeout(1500);
      out[q || 'fix'] = await p.evaluate(() => {
        var nodes = Array.prototype.slice.call(document.querySelectorAll('.db-card-img'));
        var counts = {}; nodes.forEach(function (n) { var m = /url\(["']?([^"')]+)/.exec(n.style.backgroundImage || ''); n._u = m ? m[1] : ''; counts[n._u] = (counts[n._u] || 0) + 1; });
        var labelled = 0, hidden = 0, bad = 0, wrongHidden = 0, sharedLabelled = 0, placeholders = 0;
        nodes.forEach(function (n) {
          var name = (n.parentNode.querySelector('.db-card-body h2, .db-card-body h3, .db-card-body h4') || {}).textContent || '';
          name = name.replace(/\s+/g, ' ').trim();
          if ((n.className || '').indexOf('placeholder') !== -1) { placeholders++; if (n.getAttribute('role') || n.getAttribute('aria-hidden')) { bad++; } return; }
          if (n.getAttribute('role') === 'img') {
            labelled++; if (n.getAttribute('aria-label') !== name + ', photo') { bad++; } if (counts[n._u] > 1) { sharedLabelled++; }
          }
          if (n.getAttribute('aria-hidden') === 'true') { hidden++; if (counts[n._u] < 2) { wrongHidden++; } }
        });
        return { cards: nodes.length, labelled: labelled, hidden: hidden, bad: bad, sharedLabelled: sharedLabelled, wrongHidden: wrongHidden, placeholders: placeholders, shared: Object.keys(counts).filter(function (k) { return counts[k] > 1; }).length };
      });
      await ctx.close();
    }
    const a = out['?qf=0'], f = out['fix'];
    check(`card labels ${path}`, a.cards > 0 && a.labelled === 0 && a.hidden === 0 && f.labelled + f.hidden === f.cards - f.placeholders && f.bad === 0 && f.sharedLabelled === 0 && f.wrongHidden === 0, JSON.stringify({ without: a, with: f }));
  }
  // 3. staff pages
  async function get(path) { const ctx = await b.newContext(); const r = await ctx.request.get(BASE + path, { maxRedirects: 0 }); const o = { status: r.status(), headers: r.headers(), text: r.status() === 200 ? await r.text() : '' }; await ctx.close(); return o; }
  const smOn = await get('/wp-sitemap-posts-page-1.xml'), smOff = await get('/wp-sitemap-posts-page-1.xml?qf=0');
  const locs = (x) => (x.match(/<loc>[^<]+<\/loc>/g) || []).map((s) => s.replace(/<\/?loc>/g, ''));
  check('sitemap lists /track-order/ without the fix', locs(smOff.text).some((u) => /\/track-order\/$/.test(u)));
  check('sitemap drops /kitchen/ and /track-order/ with the fix', smOn.status === 200 && !locs(smOn.text).some((u) => /\/(kitchen|track-order)\/$/.test(u)), locs(smOn.text).length + ' pages remain; ' + locs(smOff.text).length + ' before');
  check('sitemap keeps the customer pages', ['/menu/', '/order/', '/catering/', '/locations/'].every((s) => locs(smOn.text).some((u) => u.endsWith(s))));
  const to = await get('/track-order/'), toOff = await get('/track-order/?qf=0'), menu = await get('/menu/');
  check('X-Robots-Tag noindex on /track-order/', /noindex/i.test(to.headers['x-robots-tag'] || '') && !/noindex/i.test(toOff.headers['x-robots-tag'] || ''), to.headers['x-robots-tag']);
  check('robots meta noindex on /track-order/', /<meta name='robots' content='[^']*noindex/i.test(to.text) && !/<meta name='robots' content='[^']*noindex/i.test(toOff.text));
  check('other pages untouched (/menu/ has no noindex)', !/noindex/i.test(menu.headers['x-robots-tag'] || '') && !/<meta name='robots' content='[^']*noindex/i.test(menu.text));
  await b.close();
  const failed = results.filter((r) => !r.ok);
  console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
  process.exit(failed.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
