// Phase B: public visits at 1440x900 and 390x844 with metrics and screenshots (a)-(d), plus contrast frames.
import { chromium, BASE, SHOTS, HERE, ok, note, tally, visit, videoState, mp4Requests, fs, log } from "./lib.mjs";

const browser = await chromium.launch();
const VPS = [
  { name: "desktop", w: 1440, h: 900, dpr: 1, mobile: false },
  { name: "phone", w: 390, h: 844, dpr: 2, mobile: true },
];
const out = {};

for (const vp of VPS) {
  const R = (out[vp.name] = {});
  // ---------------- (a) the poster before the video loads: hold every mp4 request.
  {
    const rec = await visit(browser, { ...vp, name: vp.name + "-a", stall: true });
    const page = rec.page;
    await page.waitForTimeout(7000);
    const m = await page.evaluate(() => window.__m);
    const vs = await videoState(page);
    R.a = { lcp: m.lcp.slice(-1)[0], cls: m.cls, srcAtLoad: m.srcAtLoad, video: vs, heldMp4: rec.held.length, mp4: mp4Requests(rec).map((r) => ({ url: r.url.replace(BASE, ""), afterLoadMs: r.t - rec.loadAtNode })) };
    await page.screenshot({ path: `${SHOTS}/${vp.name}-a-poster.png` });
    ok(m.srcAtLoad === null, `${vp.name} (a): no src on the video at the window load event`);
    ok(vs.opacity === "0" && !/is-playing/.test(vs.cls) && vs.t === 0, `${vp.name} (a): video invisible (opacity ${vs.opacity}), no is-playing class, at 0 s while its request is held (src set after load: ${!!vs.src})`);
    ok(R.a.lcp && /dbgr-hero-poster-1080\.webp/.test(R.a.lcp.url || ""), `${vp.name} (a): LCP element is the poster (${R.a.lcp && R.a.lcp.url ? R.a.lcp.url.replace(BASE, "") : "?"}, ${R.a.lcp && R.a.lcp.t} ms, ${R.a.lcp && R.a.lcp.cls})`);
    ok(rec.errors.length === 0, `${vp.name} (a): zero console errors${rec.errors.length ? " " + JSON.stringify(rec.errors) : ""}`);
    R.a.posterRequests = rec.reqs.filter((r) => /dbgr-hero-poster-1080/.test(r.url)).length;
    ok(R.a.posterRequests === 1, `${vp.name} (a): the poster is requested once (${R.a.posterRequests}) although it is preloaded, a background and the video poster`);
    for (const r of rec.held) await r.abort().catch(() => {});
    await rec.ctx.close();
  }

  // ---------------- (b) playing, with (d) pause/resume and the scroll / hidden-tab pauses.
  {
    const rec = await visit(browser, { ...vp, name: vp.name + "-b" });
    const page = rec.page;
    await page.waitForFunction(() => { const v = document.querySelector(".dbgr-hero-video"); return v && /is-playing/.test(v.className); }, null, { timeout: 120000 });
    const playingAt = Date.now();
    await page.waitForFunction(() => document.querySelector(".dbgr-hero-video").currentTime >= 3);
    await page.screenshot({ path: `${SHOTS}/${vp.name}-b-3s.png` });
    const s3 = await videoState(page);
    await page.waitForFunction(() => document.querySelector(".dbgr-hero-video").currentTime >= 9);
    await page.screenshot({ path: `${SHOTS}/${vp.name}-b-9s.png` });
    const s9 = await videoState(page);
    const m = await page.evaluate(() => window.__m);
    R.b = { s3, s9, lcp: m.lcp.slice(-1)[0], lcpAll: m.lcp, cls: +m.cls.toFixed(4), srcAtLoad: m.srcAtLoad };
    R.b.mp4 = mp4Requests(rec).map((r) => ({ url: r.url.replace(BASE, ""), afterLoadMs: r.t - rec.loadAtNode }));
    R.b.posterRequests = rec.reqs.filter((r) => /dbgr-hero-poster-1080/.test(r.url)).length;
    note(`${vp.name} (b): source chosen ${s3.source} (${s3.currentSrc.replace(BASE, "")}), video ${s3.w}x${s3.h}, element ${s3.rect.join("x")}, DPR ${vp.dpr}`);
    ok(s3.source === "av1-1080" || s3.source === "av1-720", `${vp.name} (b): AV1 chosen (${s3.source})`);
    ok(!s3.paused && s3.opacity === "1" && /is-playing/.test(s3.cls), `${vp.name} (b): video playing and faded in at ${s3.t}s (opacity ${s3.opacity})`);
    ok(!s9.paused && s9.t >= 9, `${vp.name} (b): still playing at ${s9.t}s`);
    ok(s3.muted && s3.err === 0, `${vp.name} (b): muted, no media error`);
    ok(R.b.mp4.length >= 1 && R.b.mp4.every((r) => r.afterLoadMs > 0), `${vp.name} (b): every video request starts after the window load event (first ${R.b.mp4[0] && R.b.mp4[0].afterLoadMs} ms after load; ${R.b.mp4.length} request(s))`);
    ok(m.srcAtLoad === null, `${vp.name} (b): video had no src at the load event`);
    ok(rec.errors.length === 0, `${vp.name} (b): zero console errors${rec.errors.length ? " " + JSON.stringify(rec.errors) : ""}`);
    ok(R.b.cls < 0.1, `${vp.name} (b): CLS ${R.b.cls} (Good is under 0.1; compare with the switch-off baseline in phase-cls)`);
    ok(R.b.lcp && /dbgr-hero-poster-1080\.webp/.test(R.b.lcp.url || ""), `${vp.name} (b): LCP candidate is the poster, ${R.b.lcp && R.b.lcp.t} ms`);
    ok(R.b.posterRequests === 1, `${vp.name} (b): poster requested once (${R.b.posterRequests})`);

    // (d) core's pause button
    const btn = '[data-db-manoush-replay]';
    note(`${vp.name} (d): core button text "${(await page.textContent(btn)).trim()}" aria-pressed=${await page.getAttribute(btn, "aria-pressed")}`);
    await page.click(btn);
    await page.waitForFunction(() => document.querySelector(".dbgr-hero-video").paused === true, null, { timeout: 5000 });
    const d1 = await videoState(page);
    await page.screenshot({ path: `${SHOTS}/${vp.name}-d-paused.png` });
    R.d = { paused: d1 };
    ok(d1.paused === true && d1.heroPaused, `${vp.name} (d): after clicking core's pause button video.paused === true (t=${d1.t}s, hero.is-photo-paused ${d1.heroPaused})`);
    await page.waitForTimeout(1200);
    const d2 = await videoState(page);
    ok(d2.t === d1.t, `${vp.name} (d): the frame stays frozen while paused (${d1.t}s -> ${d2.t}s)`);
    await page.click(btn);
    await page.waitForFunction(() => document.querySelector(".dbgr-hero-video").paused === false, null, { timeout: 5000 });
    await page.waitForTimeout(800);
    const d3 = await videoState(page);
    ok(!d3.paused && d3.t !== d2.t, `${vp.name} (d): pressing again resumes it (${d2.t}s -> ${d3.t}s)`);
    // out of view
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForFunction(() => document.querySelector(".dbgr-hero-video").paused === true, null, { timeout: 8000 });
    ok(true, `${vp.name}: video pauses when the hero scrolls out of view`);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForFunction(() => document.querySelector(".dbgr-hero-video").paused === false, null, { timeout: 8000 });
    ok(true, `${vp.name}: video resumes when the hero scrolls back`);
    // hidden tab
    await page.evaluate(() => { Object.defineProperty(document, "hidden", { configurable: true, get: () => true }); document.dispatchEvent(new Event("visibilitychange")); });
    await page.waitForFunction(() => document.querySelector(".dbgr-hero-video").paused === true, null, { timeout: 5000 });
    ok(true, `${vp.name}: video pauses when the tab is hidden`);
    await page.evaluate(() => { Object.defineProperty(document, "hidden", { configurable: true, get: () => false }); document.dispatchEvent(new Event("visibilitychange")); });
    await page.waitForFunction(() => document.querySelector(".dbgr-hero-video").paused === false, null, { timeout: 5000 });
    ok(true, `${vp.name}: video resumes when the tab is visible again`);
    // pause during the user's pause survives a scroll out and in
    await page.click(btn);
    await page.waitForFunction(() => document.querySelector(".dbgr-hero-video").paused === true);
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(600);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(1200);
    ok((await videoState(page)).paused === true, `${vp.name}: a user pause is not undone by scrolling away and back`);
    await page.click(btn);

    // ---- contrast frames: pause on a frame, hide the text, screenshot the hero, remember the text boxes.
    const times = [0.5, 2, 3.5, 5, 6.5, 8, 9, 10];
    const frames = [];
    await page.evaluate(() => { document.querySelector(".dbgr-hero-video").pause(); });
    for (const t of times) {
      await page.evaluate(async (tt) => { const v = document.querySelector(".dbgr-hero-video"); v.pause(); await new Promise((res) => { v.addEventListener("seeked", res, { once: true }); v.currentTime = tt; setTimeout(res, 4000); }); }, t);
      await page.waitForTimeout(250);
      const boxes = await page.evaluate(() => {
        const hero = document.querySelector("[data-db-manoush-hero]");
        const r = (el, kind, color) => { const b = el.getBoundingClientRect(); return { kind, color, x: b.left, y: b.top, w: b.width, h: b.height }; };
        const out = [];
        const cs = (el) => getComputedStyle(el).color;
        hero.querySelectorAll(".db-mh-kicker").forEach((e) => out.push(r(e, "kicker", cs(e))));
        hero.querySelectorAll(".db-mh-copy h2").forEach((e) => out.push(r(e, "headline", cs(e))));
        hero.querySelectorAll(".db-mh-copy > p:not(.db-mh-kicker)").forEach((e) => out.push(r(e, "paragraph", cs(e))));
        hero.querySelectorAll(".db-mh-action--secondary").forEach((e) => { if (e.offsetParent) out.push(r(e, "secondary-button", cs(e))); });
        hero.querySelectorAll(".db-mh-replay").forEach((e) => out.push(r(e, "pause-button", cs(e))));
        hero.querySelectorAll(".db-mh-proof span").forEach((e) => out.push(r(e, "proof", cs(e))));
        const hb = hero.getBoundingClientRect();
        return { hero: { x: hb.left, y: hb.top, w: hb.width, h: hb.height }, boxes: out };
      });
      await page.addStyleTag({ content: ".db-mh-copy,.db-mh-proof,.dbgr-hero-chip{visibility:hidden!important}" });
      const file = `${SHOTS}/contrast-${vp.name}-${String(t).replace(".", "_")}.png`;
      await page.screenshot({ path: file });
      await page.evaluate(() => { document.querySelectorAll("style").forEach((s) => { if (/visibility:hidden!important/.test(s.textContent) && /dbgr-hero-chip/.test(s.textContent)) s.remove(); }); });
      frames.push({ t, file, ...boxes });
    }
    fs.writeFileSync(`${HERE}/out/contrast-${vp.name}.json`, JSON.stringify({ dpr: vp.dpr, frames }, null, 1));
    await rec.ctx.close();
  }

  // ---------------- (c) reduced motion: poster only.
  {
    const rec = await visit(browser, { ...vp, name: vp.name + "-c", reduced: true });
    const page = rec.page;
    await page.waitForTimeout(7000);
    const vs = await videoState(page);
    const m = await page.evaluate(() => window.__m);
    R.c = { video: vs, mp4: mp4Requests(rec).length, errors: rec.errors, lcp: m.lcp.slice(-1)[0] };
    await page.screenshot({ path: `${SHOTS}/${vp.name}-c-reduced-motion.png` });
    ok(vs.src === null && vs.paused && vs.display === "none", `${vp.name} (c): reduced motion: no src set, display ${vs.display}, paused`);
    ok(R.c.mp4 === 0, `${vp.name} (c): reduced motion: zero video requests`);
    ok(rec.errors.length === 0, `${vp.name} (c): zero console errors`);
    ok(await page.evaluate(() => !!document.querySelector(".dbgr-hero-chip") && getComputedStyle(document.querySelector(".dbgr-hero-chip")).display !== "none"), `${vp.name} (c): the Concept preview label still shows (the poster is still a concept)`);
    await rec.ctx.close();
  }
}

fs.writeFileSync(`${HERE}/out/metrics.json`, JSON.stringify(out, null, 1));
fs.writeFileSync(`${HERE}/out/phase-visit.log`, log.join("\n"));
await browser.close();
const t = tally();
console.log(`\nphase B: ${t.pass} passed, ${t.fail} failed`);
process.exit(t.fail ? 1 : 0);
