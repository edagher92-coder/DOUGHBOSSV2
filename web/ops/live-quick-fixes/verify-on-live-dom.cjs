// READ-ONLY check against the real live pages: loads each page, injects the snippets into MY browser's copy of the page only
// (nothing is sent to or stored on the site), then measures the result. No forms, no clicks that change state.
const fs = require('fs');
const { chromium } = require('../../node_modules/@playwright/test');
const css = fs.readFileSync(__dirname + '/01-hero-strip.css', 'utf8');
const js = fs.readFileSync(__dirname + '/02-menu-photo-labels.js', 'utf8');
(async () => {
  const b = await chromium.launch({ args: ['--ignore-certificate-errors'] });
  // The sandbox network only allows requests made from Node, so route every request through route.fetch with retries (the proxy sometimes drops parallel fetches).
  async function routeThrough(ctx) { await ctx.route('**/*', async (route) => { let last; for (let a = 0; a < 6; a++) { try { const r = await route.fetch({ timeout: 45000 }); if (r.status() < 500) { return route.fulfill({ response: r }); } last = r; } catch (e) { /* retry */ } await new Promise((x) => setTimeout(x, 600 * (a + 1))); } if (last) { return route.fulfill({ response: last }); } return route.abort(); }); }
  let failed = 0;
  const log = (ok, name, detail) => { if (!ok) failed++; console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '  ' + detail : '')); };
  async function load(p, url) { for (let i = 0; i < 3; i++) { try { await p.goto(url, { waitUntil: 'load', timeout: 60000 }); return true; } catch (e) { await p.waitForTimeout(3000); } } return false; }
  for (const [label, vp] of [['1280', { width: 1280, height: 800 }], ['390', { width: 390, height: 844 }]]) {
    for (const path of ['/order/', '/locations/', '/about-us/', '/franchising/']) {
      const ctx = await b.newContext({ viewport: vp }); await routeThrough(ctx); const p = await ctx.newPage();
      if (!(await load(p, 'https://doughboss.com.au' + path))) { log(false, 'hero ' + path + ' @' + label, 'page did not load'); await ctx.close(); continue; }
      await p.waitForTimeout(1200);
      const m = () => p.evaluate(() => { var bg = document.querySelector('.dbf-page-hero-bg'), h = document.querySelector('.dbf-page-hero'); if (!bg || !h) return null; var a = bg.getBoundingClientRect(), r = h.getBoundingClientRect(); return { right: Math.round((r.right - a.right) * 10) / 10, left: Math.round((a.left - r.left) * 10) / 10, over: document.documentElement.scrollWidth > document.documentElement.clientWidth }; });
      const before = await m(); await p.addStyleTag({ content: css }); await p.waitForTimeout(300); const after = await m();
      log(before && after && before.right > 1 && after.right <= 0.5 && after.left <= 0.5 && !after.over, 'LIVE hero strip ' + path + ' @' + label, JSON.stringify({ before: before && before.right, after: after && after.right, overflow: after && after.over }));
      await ctx.close();
    }
  }
  for (const path of ['/menu/', '/order/']) {
    const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } }); await routeThrough(ctx); const p = await ctx.newPage();
    if (!(await load(p, 'https://doughboss.com.au' + path))) { log(false, 'labels ' + path, 'page did not load'); await ctx.close(); continue; }
    await p.waitForSelector('.db-card-img', { timeout: 60000 }).catch(() => null); await p.waitForTimeout(1500);
    await p.addScriptTag({ content: js }); await p.waitForTimeout(1200);
    const r = await p.evaluate(() => {
      var nodes = Array.prototype.slice.call(document.querySelectorAll('.db-card-img')), counts = {};
      nodes.forEach(function (n) { var m = /url\(["']?([^"')]+)/.exec(n.style.backgroundImage || ''); n._u = m ? m[1] : ''; counts[n._u] = (counts[n._u] || 0) + 1; });
      var lab = 0, hid = 0, bad = 0, samples = [];
      nodes.forEach(function (n) {
        var h = n.parentNode.querySelector('.db-card-body h2, .db-card-body h3, .db-card-body h4'), name = h ? h.textContent.replace(/\s+/g, ' ').trim() : '';
        if (n.getAttribute('role') === 'img') { lab++; if (n.getAttribute('aria-label') !== name + ', photo' || counts[n._u] > 1) bad++; if (samples.length < 3) samples.push(n.getAttribute('aria-label')); }
        if (n.getAttribute('aria-hidden') === 'true') { hid++; if (counts[n._u] < 2) bad++; }
      });
      var hiddenNames = nodes.filter(function (n) { return n.getAttribute('aria-hidden') === 'true'; }).map(function (n) { return n.parentNode.querySelector('.db-card-body h4, .db-card-body h3').textContent.trim(); });
      return { cards: nodes.length, labelled: lab, hidden: hid, bad: bad, samples: samples, hiddenNames: hiddenNames };
    });
    log(r.cards === 43 && r.labelled + r.hidden === r.cards && r.bad === 0 && r.hidden === 14, 'LIVE card labels ' + path, JSON.stringify(r));
    await ctx.close();
  }
  await b.close(); console.log(failed ? failed + ' FAILED' : 'all live-DOM checks passed'); process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
