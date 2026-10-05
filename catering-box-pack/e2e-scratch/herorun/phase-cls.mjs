// CLS and LCP of the photo hero (switch OFF) against the video hero (switch ON), same machine, same run.
import { chromium, BASE, HERE, ok, note, tally, adminLogin, setSwitches, visit, fs, log } from "./lib.mjs";
const browser = await chromium.launch();
const { p: ap } = await adminLogin(browser);
const res = {};
async function measure(label) {
  for (const vp of [{ name: "desktop", w: 1440, h: 900, dpr: 1 }, { name: "phone", w: 390, h: 844, dpr: 2, mobile: true }]) {
    const runs = [];
    for (let i = 0; i < 3; i++) {
      const rec = await visit(browser, { ...vp, abortMp4: true });
      await rec.page.waitForTimeout(4500);
      const m = await rec.page.evaluate(() => window.__m);
      const last = m.lcp.slice(-1)[0];
      runs.push({ cls: +m.cls.toFixed(4), lcpMs: last && last.t, lcpUrl: last && (last.url || "").replace(BASE, "").replace(/^.*\//, ""), lcpBytes: last && last.size });
      await rec.ctx.close();
    }
    res[label + " " + vp.name] = runs;
    note(label + " " + vp.name + ": " + JSON.stringify(runs));
  }
}
await setSwitches(ap, { home_hero_video: false, hero_chip: true });
await measure("photo hero (switch OFF)");
await setSwitches(ap, { home_hero_video: true, hero_chip: true });
await measure("video hero (switch ON)");
fs.writeFileSync(HERE + "/out/phase-cls.json", JSON.stringify(res, null, 1));
await browser.close();
