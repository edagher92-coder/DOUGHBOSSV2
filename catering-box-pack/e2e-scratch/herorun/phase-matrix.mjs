// Phase C: the script's decisions (codec, height, connection, missing files, missing pause button, errors).
import { chromium, BASE, SHOTS, HERE, ok, note, tally, visit, videoState, fs, log } from "./lib.mjs";

const browser = await chromium.launch();
const D = { w: 1440, h: 900 }, P = { w: 390, h: 844, mobile: true };
const canPlay = (rule) => `(() => { const o = HTMLMediaElement.prototype.canPlayType; HTMLMediaElement.prototype.canPlayType = function (t) { const r = (${rule})(t); return r === null ? o.call(this, t) : r; }; })();`;
const noAv1 = canPlay("(t) => /av01/.test(t) ? '' : (/hvc1|avc1/.test(t) ? 'probably' : null)");
const noAv1NoHevc = canPlay("(t) => /av01|hvc1/.test(t) ? '' : (/avc1/.test(t) ? 'probably' : null)");
const nothing = canPlay("(t) => ''");
const strip = (names) => `document.addEventListener('DOMContentLoaded', () => { const v = document.querySelector('.dbgr-hero-video'); ${JSON.stringify(names)}.forEach((n) => v && v.removeAttribute(n)); });`;

async function run(label, opts, expectSource, extraCheck) {
  const rec = await visit(browser, { abortMp4: true, ...opts });
  await rec.page.waitForTimeout(5500);
  const vs = await videoState(rec.page);
  const got = vs ? vs.source : "no-video-element";
  ok(got === expectSource, `${label}: source ${JSON.stringify(got)} (wanted ${JSON.stringify(expectSource)})`);
  if (extraCheck) await extraCheck(rec, vs);
  await rec.ctx.close();
  return vs;
}

// codec x height x connection
await run("desktop DPR1 (video 761 css px wide x1 = 761)", { ...D, dpr: 1 }, "av1-720");
await run("desktop DPR2 (761 x2 = 1522)", { ...D, dpr: 2 }, "av1-1080");
await run("phone DPR1 (405 x1)", { ...P, dpr: 1 }, "av1-720");
await run("phone DPR2 (405 x2 = 810 > 800)", { ...P, dpr: 2 }, "av1-1080");
await run("phone DPR3, downlink 10", { ...P, dpr: 3, connection: { downlink: 10, effectiveType: "4g", saveData: false } }, "av1-1080");
await run("phone DPR3, downlink 5 (>= 5 counts as fast)", { ...P, dpr: 3, connection: { downlink: 5, effectiveType: "4g", saveData: false } }, "av1-1080");
await run("phone DPR3, downlink 4.9", { ...P, dpr: 3, connection: { downlink: 4.9, effectiveType: "4g", saveData: false } }, "av1-720");
await run("phone DPR3, downlink unknown (0)", { ...P, dpr: 3, connection: { downlink: 0, effectiveType: "4g", saveData: false } }, "av1-1080");
// poster-only conditions
for (const c of [{ saveData: true, effectiveType: "4g", downlink: 10 }, { saveData: false, effectiveType: "3g", downlink: 1 }, { saveData: false, effectiveType: "2g", downlink: 0.2 }, { saveData: false, effectiveType: "slow-2g", downlink: 0.05 }]) {
  await run(`poster only for ${JSON.stringify(c)}`, { ...D, dpr: 2, connection: c }, null, async (rec, vs) => {
    ok(!vs.src && rec.reqs.filter((r) => /\.mp4/.test(r.url)).length === 0, "  no src, zero video requests");
  });
}
// codec fallbacks (canPlayType faked; the file choice is what is under test)
await run("no AV1 -> HEVC (desktop DPR2)", { ...D, dpr: 2, init: noAv1 }, "hevc-1080");
await run("no AV1 -> HEVC (desktop DPR1)", { ...D, dpr: 1, init: noAv1 }, "hevc-720");
await run("no AV1, no HEVC -> H.264 (only a 720 file exists, desktop DPR2 wants 1080)", { ...D, dpr: 2, init: noAv1NoHevc }, "h264-720");
await run("no codec at all -> poster only", { ...D, dpr: 2, init: nothing }, null, async (rec, vs) => { ok(!vs.src, "  no src"); });
// missing files: fall back to whichever exist
await run("av1-1080 missing on a DPR2 desktop -> av1-720", { ...D, dpr: 2, init: strip(["data-av1-1080"]) }, "av1-720");
await run("av1-720 missing on a DPR1 desktop -> av1-1080", { ...D, dpr: 1, init: strip(["data-av1-720"]) }, "av1-1080");
await run("both AV1 files missing, this Chromium cannot play HEVC or H.264 -> poster only", { ...D, dpr: 2, init: strip(["data-av1-1080", "data-av1-720"]) }, null);
await run("both AV1 files missing, every codec reported playable -> hevc-1080", { ...D, dpr: 2, init: canPlay("(t) => 'probably'") + strip(["data-av1-1080", "data-av1-720"]) }, "hevc-1080");
{
  const rec = await visit(browser, { ...D, dpr: 1, abortMp4: true });
  const cp = await rec.page.evaluate(() => { const v = document.createElement("video"); return { av1: v.canPlayType('video/mp4; codecs="av01.0.08M.10"'), hevc: v.canPlayType('video/mp4; codecs="hvc1.1.6.L120.90"'), h264: v.canPlayType('video/mp4; codecs="avc1.640028"') }; });
  note("this Chromium's canPlayType: " + JSON.stringify(cp));
  await rec.ctx.close();
}
await run("every file missing -> poster only", { ...D, dpr: 2, init: strip(["data-av1-1080", "data-av1-720", "data-hevc-1080", "data-hevc-720", "data-h264-720"]) }, null);

// the pause control: core's button missing -> ours is injected and works; both missing -> no video
{
  const rec = await visit(browser, { ...D, dpr: 1, init: "document.addEventListener('DOMContentLoaded', () => { const b = document.querySelector('[data-db-manoush-replay]'); if (b) b.remove(); });" });
  const page = rec.page;
  await page.waitForFunction(() => { const v = document.querySelector(".dbgr-hero-video"); return v && /is-playing/.test(v.className); }, null, { timeout: 90000 });
  const own = await page.$$eval(".db-mh-actions button", (els) => els.map((e) => ({ cls: e.className, text: e.textContent, pressed: e.getAttribute("aria-pressed") })));
  note("core button removed; buttons now: " + JSON.stringify(own));
  ok(own.length === 1 && own[0].cls === "db-mh-replay" && own[0].text === "Pause video", "core's pause button missing: our own matching button was added (same db-mh-replay class)");
  await page.click(".db-mh-actions button.db-mh-replay");
  await page.waitForFunction(() => document.querySelector(".dbgr-hero-video").paused === true, null, { timeout: 5000 });
  ok(true, "  our button pauses the video");
  ok((await page.textContent(".db-mh-actions button.db-mh-replay")) === "Play video" && (await page.getAttribute(".db-mh-actions button.db-mh-replay", "aria-pressed")) === "true", "  it flips to 'Play video' with aria-pressed=true");
  await page.screenshot({ path: SHOTS + "/desktop-own-pause-button.png" });
  await page.click(".db-mh-actions button.db-mh-replay");
  await page.waitForFunction(() => document.querySelector(".dbgr-hero-video").paused === false, null, { timeout: 5000 });
  ok(true, "  and plays again");
  await rec.ctx.close();
}
{
  const rec = await visit(browser, { ...D, dpr: 1, abortMp4: true, init: "document.addEventListener('DOMContentLoaded', () => { document.querySelectorAll('[data-db-manoush-replay], .db-mh-actions').forEach((e) => e.remove()); });" });
  await rec.page.waitForTimeout(5500);
  const vs = await videoState(rec.page);
  ok(!vs.src, "no pause control available at all: the video does not start (WCAG 2.2.2 fail-closed)");
  await rec.ctx.close();
}

// error: a video that fails to load leaves the poster, silently
{
  const rec = await visit(browser, { ...D, dpr: 1, abortMp4: true });
  await rec.page.waitForTimeout(6000);
  const vs = await videoState(rec.page);
  const pageErrors = rec.errors.filter((e) => /^pageerror/.test(e));
  ok(!/is-playing/.test(vs.cls) && vs.opacity === "0", "video request fails: stays on the poster (opacity 0, no is-playing)");
  ok(pageErrors.length === 0, "video request fails: no uncaught page error (the browser logs the failed request itself: " + rec.errors.length + " console line(s))");
  await rec.ctx.close();
}

fs.writeFileSync(`${HERE}/out/phase-matrix.log`, log.join("\n"));
await browser.close();
const t = tally();
console.log(`\nphase C: ${t.pass} passed, ${t.fail} failed`);
process.exit(t.fail ? 1 : 0);
