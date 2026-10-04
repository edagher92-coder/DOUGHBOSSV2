import { chromium, BASE, UA, SHOTS, videoState } from "./lib.mjs";
const browser = await chromium.launch();
const sizes = process.argv.slice(2).map((s) => s.split("x").map(Number));
for (const [w, h, dpr] of sizes) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: dpr || 1, userAgent: UA });
  const p = await ctx.newPage(); p.setDefaultTimeout(120000);
  await p.goto(BASE + "/", { waitUntil: "load" });
  await p.waitForFunction(() => { const v = document.querySelector(".dbgr-hero-video"); return v && /is-playing/.test(v.className); }, null, { timeout: 60000 });
  await p.waitForFunction(() => document.querySelector(".dbgr-hero-video").currentTime >= 6);
  await p.screenshot({ path: `${SHOTS}/size-${w}x${h}.png` });
  console.log(w, h, JSON.stringify(await videoState(p)));
  await ctx.close();
}
await browser.close();
