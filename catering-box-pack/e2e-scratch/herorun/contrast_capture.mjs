// usage: node contrast_capture.mjs <tag> <WxHxDPR> [times comma list]   (the page must be in the switch-ON state, or OFF for the photo baseline)
import { chromium, BASE, UA, SHOTS, HERE, fs } from "./lib.mjs";
const [tag, size, timesArg, mode] = process.argv.slice(2);
const [w, h, dpr] = size.split("x").map(Number);
const times = (timesArg || "0.5,3.5,6.5,9.5").split(",").map(Number);
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: dpr || 1, userAgent: UA, isMobile: w < 500, hasTouch: w < 500 });
const p = await ctx.newPage(); p.setDefaultTimeout(120000);
await p.goto(BASE + "/", { waitUntil: "load" });
const photo = mode === "photo";
if (!photo) await p.waitForFunction(() => { const v = document.querySelector(".dbgr-hero-video"); return v && /is-playing/.test(v.className) && v.currentTime > 0.3; }, null, { timeout: 90000 });
await p.waitForTimeout(1500);
const frames = [];
for (const t of (photo ? [0] : times)) {
  if (!photo) {
    await p.evaluate(async (tt) => { const v = document.querySelector(".dbgr-hero-video"); v.pause(); await new Promise((res) => { v.addEventListener("seeked", res, { once: true }); v.currentTime = tt; setTimeout(res, 5000); }); }, t);
    await p.waitForTimeout(300);
  }
  const boxes = await p.evaluate(() => {
    const hero = document.querySelector("[data-db-manoush-hero]");
    const r = (el, kind) => { const b = el.getBoundingClientRect(); return { kind, color: getComputedStyle(el).color, x: b.left, y: b.top, w: b.width, h: b.height, fs: parseFloat(getComputedStyle(el).fontSize) }; };
    const out = [];
    hero.querySelectorAll(".db-mh-kicker").forEach((e) => out.push(r(e, "kicker")));
    hero.querySelectorAll(".db-mh-copy h2").forEach((e) => out.push(r(e, "headline")));
    hero.querySelectorAll(".db-mh-copy > p:not(.db-mh-kicker)").forEach((e) => out.push(r(e, "paragraph")));
    hero.querySelectorAll(".db-mh-action--secondary").forEach((e) => { if (e.offsetParent) out.push(r(e, "secondary-button")); });
    hero.querySelectorAll(".db-mh-replay").forEach((e) => out.push(r(e, "pause-button")));
    hero.querySelectorAll(".db-mh-proof span").forEach((e) => out.push(r(e, "proof")));
    return out;
  });
  const css = await p.addStyleTag({ content: ".db-mh-copy,.db-mh-proof,.dbgr-hero-chip{visibility:hidden!important}" });
  const file = `${SHOTS}/contrast-${tag}-${String(t).replace(".", "_")}.png`;
  await p.screenshot({ path: file });
  await css.evaluate((n) => n.remove());
  frames.push({ t, file, boxes });
}
fs.writeFileSync(`${HERE}/out/contrast-${tag}.json`, JSON.stringify({ w, h, dpr: dpr || 1, frames }, null, 1));
console.log("captured", tag, frames.length, "frames");
await browser.close();
