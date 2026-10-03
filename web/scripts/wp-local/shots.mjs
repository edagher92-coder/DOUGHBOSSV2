// Playwright smoke + screenshots for the local DoughBoss WordPress runtime.
//
//   node scripts/wp-local/shots.mjs                 # from web/ (uses web/node_modules/@playwright/test)
//   WPL_URL=http://127.0.0.1:9410 SHOTS_DIR=/some/dir node scripts/wp-local/shots.mjs
//
// For the home page and the ordering page (/order/), at desktop (1360x860) and mobile (Pixel 7):
//   - loads the page, fails on any first-party console error / uncaught page error / HTTP >= 400
//   - on /order/ asserts the client-rendered menu (REST -> JS) produced menu cards
//   - saves a full-page screenshot to $SHOTS_DIR/wp-<page>-<viewport>.png
// Set WPL_NEGATIVE_CONTROL=1 to also request a missing first-party file and prove the error detector fires.
// Third-party failures (e.g. Google Fonts blocked by the sandbox network policy) are REPORTED but do not
// fail the run, because this runtime has no outbound internet by design. Exit code 1 on a first-party failure.
import { chromium, devices } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";

const BASE = process.env.WPL_URL || "http://127.0.0.1:9410";
const OUT = process.env.SHOTS_DIR || "/tmp/wp-local/shots";
fs.mkdirSync(OUT, { recursive: true });

const origin = new URL(BASE).origin;
const pages = [
  { name: "home", url: "/", expectCards: false },
  { name: "order", url: "/order/", expectCards: true },
];
const viewports = [
  { name: "desktop", opts: { ...devices["Desktop Chrome"], viewport: { width: 1360, height: 860 } } },
  { name: "mobile", opts: { ...devices["Pixel 7"] } },
];

const browser = await chromium.launch({
  args: ["--use-gl=angle", "--use-angle=swiftshader", "--enable-unsafe-swiftshader", "--ignore-gpu-blocklist"],
});
const report = [];
let failed = false;

for (const vp of viewports) {
  for (const pg of pages) {
    const ctx = await browser.newContext(vp.opts);
    const page = await ctx.newPage();
    const firstPartyErrors = [];
    const thirdPartyNoise = [];
    const isFirst = (u) => u.startsWith(origin);

    page.on("console", (m) => {
      if (m.type() !== "error") return;
      const loc = m.location() && m.location().url ? m.location().url : "";
      const text = m.text();
      // Chromium logs failed resource loads as console errors; classify by the failing URL.
      (loc && !isFirst(loc) ? thirdPartyNoise : firstPartyErrors).push("console.error: " + text + (loc ? " @ " + loc : ""));
    });
    page.on("pageerror", (e) => firstPartyErrors.push("pageerror: " + e.message));
    page.on("requestfailed", (r) => {
      const msg = "requestfailed: " + r.url() + " " + (r.failure() ? r.failure().errorText : "");
      (isFirst(r.url()) ? firstPartyErrors : thirdPartyNoise).push(msg);
    });
    page.on("response", (r) => {
      if (r.status() >= 400) {
        const msg = "http " + r.status() + ": " + r.url();
        (isFirst(r.url()) ? firstPartyErrors : thirdPartyNoise).push(msg);
      }
    });

    if (process.env.WPL_NEGATIVE_CONTROL === "1") {
      await page.route("**/wpl-negative-control.js", (r) => r.continue());
      await page.addInitScript(() => { const s = document.createElement("script"); s.src = "/wpl-negative-control.js"; document.addEventListener("DOMContentLoaded", () => document.head.appendChild(s)); });
    }
    const t0 = Date.now();
    await page.goto(BASE + pg.url, { waitUntil: "load", timeout: 90000 });
    await page.waitForLoadState("networkidle", { timeout: 30000 }).catch(() => {});
    let cards = null;
    if (pg.expectCards) {
      await page.waitForSelector(".db-card", { timeout: 30000 }).catch(() => {});
      cards = await page.locator(".db-card").count();
      if (cards < 1) firstPartyErrors.push("no .db-card rendered on " + pg.url + " (menu REST/JS did not populate)");
    }
    // Trigger scroll-reveal and lazy images before the full-page capture.
    await page.evaluate(async () => {
      const h = document.documentElement.scrollHeight;
      for (let y = 0; y < h; y += 500) { window.scrollTo({ top: y, behavior: "instant" }); await new Promise((r) => setTimeout(r, 60)); }
      window.scrollTo({ top: 0, behavior: "instant" });
    });
    await page.waitForTimeout(1200);
    const file = path.join(OUT, `wp-${pg.name}-${vp.name}.png`);
    await page.screenshot({ path: file, fullPage: true });
    // First-viewport shot: a full-page mobile capture is tens of thousands of px tall and unreadable when scaled.
    const topFile = path.join(OUT, `wp-${pg.name}-${vp.name}-top.png`);
    await page.screenshot({ path: topFile, fullPage: false });
    const pageHeightPx = await page.evaluate(() => document.documentElement.scrollHeight);

    const title = await page.title();
    const h1 = await page.locator("h1").first().innerText().catch(() => "");
    const overflowX = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    report.push({
      page: pg.url, viewport: vp.name, title, h1: h1.replace(/\s+/g, " ").trim(), menuCards: cards,
      horizontalOverflowPx: overflowX, pageHeightCssPx: pageHeightPx, screenshotTop: topFile, loadMs: Date.now() - t0, screenshot: file,
      firstPartyErrors, thirdPartyNoise: [...new Set(thirdPartyNoise)],
    });
    if (firstPartyErrors.length) failed = true;
    await ctx.close();
  }
}
await browser.close();
console.log(JSON.stringify(report, null, 2));
console.log(failed ? "RESULT: FAIL (first-party errors above)" : "RESULT: PASS (no first-party console/page/HTTP errors)");
process.exit(failed ? 1 : 0);
