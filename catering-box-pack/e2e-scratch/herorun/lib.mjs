import { chromium, request } from "/home/user/DOUGHBOSSV2/web/node_modules/@playwright/test/index.mjs";
import fs from "node:fs";
import crypto from "node:crypto";

export const BASE = "http://127.0.0.1:9411";
export const HERE = "/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/herorun";
export const SHOTS = HERE + "/shots";
export const VID = "/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/minis/final7/web";
export const STATE = "/tmp/wp-local-box";
export const UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36";
export const VIDEOS = [
  "dbgr-hero-loop-720-av1.mp4",
  "dbgr-hero-loop-1080-av1.mp4",
  "dbgr-hero-loop-720-hevc.mp4",
  "dbgr-hero-loop-1080-hevc.mp4",
  "dbgr-hero-loop-720-h264.mp4",
];

export const log = [];
let pass = 0, fail = 0;
export const ok = (cond, msg) => {
  if (cond) { pass++; console.log("PASS", msg); log.push("PASS " + msg); }
  else { fail++; console.log("FAIL", msg); log.push("FAIL " + msg); }
};
export const note = (msg) => { console.log("NOTE", msg); log.push("NOTE " + msg); };
export const tally = () => ({ pass, fail });
export const sha = (s) => crypto.createHash("sha256").update(s).digest("hex");

export async function adminLogin(browser) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const p = await ctx.newPage();
  p.setDefaultTimeout(180000);
  await p.goto(BASE + "/wp-login.php");
  await p.fill("#user_login", "admin");
  await p.fill("#user_pass", "password");
  await Promise.all([p.waitForNavigation(), p.click("#wp-submit")]);
  return { ctx, p };
}

export async function anon() {
  return request.newContext({ baseURL: BASE, extraHTTPHeaders: { "User-Agent": UA } });
}
export async function get(a, path) {
  const r = await a.get(path, { timeout: 180000 });
  return r.text();
}

export async function setSwitches(p, map) {
  await p.goto(BASE + "/wp-admin/admin.php?page=doughboss-growth-box");
  for (const [k, v] of Object.entries(map)) {
    const sel = `input[name="sw[${k}]"]`;
    if (v) await p.check(sel); else await p.uncheck(sel);
  }
  await Promise.all([p.waitForNavigation(), p.click("#submit")]);
}

// The status table text of the hero section on the settings screen.
export async function heroStatus(p) {
  await p.goto(BASE + "/wp-admin/admin.php?page=doughboss-growth-box");
  return p.evaluate(() => {
    const h = [...document.querySelectorAll("h2")].find((e) => /Home hero video/.test(e.textContent));
    const state = h && h.nextElementSibling ? h.nextElementSibling.textContent.trim() : "";
    let tbl = h ? h.nextElementSibling : null;
    while (tbl && tbl.tagName !== "TABLE") tbl = tbl.nextElementSibling;
    const rows = {};
    if (tbl) tbl.querySelectorAll("tbody tr").forEach((tr) => {
      const t = [...tr.children].map((c) => c.textContent.trim());
      rows[t[0]] = { found: t[1], where: t[2], size: t[3] };
    });
    return { state, rows };
  });
}

// One public visit with instrumentation. opts: name, w, h, dpr, mobile, reduced, stall (hold mp4 requests), abortMp4,
// init (extra init script source), waitPlay (default true), connection (object to fake navigator.connection).
export async function visit(browser, opts) {
  const ctx = await browser.newContext({
    viewport: { width: opts.w, height: opts.h },
    deviceScaleFactor: opts.dpr || 1,
    isMobile: !!opts.mobile,
    hasTouch: !!opts.mobile,
    reducedMotion: opts.reduced ? "reduce" : "no-preference",
    userAgent: UA,
  });
  await ctx.addInitScript(() => {
    window.__m = { lcp: [], cls: 0, loadAt: null, srcAtLoad: null, playingAt: null };
    try { new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__m.lcp.push({ t: Math.round(e.startTime), size: e.size, url: e.url, tag: e.element && e.element.tagName, cls: e.element && String(e.element.className) }); }).observe({ type: "largest-contentful-paint", buffered: true }); } catch (e) {}
    try { new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__m.cls += e.value; }).observe({ type: "layout-shift", buffered: true }); } catch (e) {}
    window.addEventListener("load", () => {
      window.__m.loadAt = performance.now();
      const v = document.querySelector(".dbgr-hero-video");
      window.__m.srcAtLoad = v ? v.getAttribute("src") : "no-video-element";
    });
  });
  if (opts.connection) {
    await ctx.addInitScript((c) => { Object.defineProperty(navigator, "connection", { value: c, configurable: true }); }, opts.connection);
  }
  if (opts.init) await ctx.addInitScript(opts.init);
  const page = await ctx.newPage();
  page.setDefaultTimeout(180000);
  const rec = { name: opts.name, errors: [], failed: [], reqs: [], loadAtNode: 0 };
  page.on("console", (m) => { if (m.type() === "error") rec.errors.push(m.text()); });
  page.on("pageerror", (e) => rec.errors.push("pageerror: " + e.message));
  page.on("requestfailed", (r) => rec.failed.push(r.url() + " " + (r.failure() && r.failure().errorText)));
  page.on("request", (r) => rec.reqs.push({ url: r.url(), t: Date.now(), type: r.resourceType() }));
  page.on("load", () => { rec.loadAtNode = Date.now(); });
  const held = [];
  if (opts.stall) await page.route(/\.mp4/, (route) => held.push(route));
  if (opts.abortMp4) await page.route(/\.mp4/, (route) => route.abort());
  await page.goto(BASE + "/", { waitUntil: "load" });
  rec.page = page; rec.ctx = ctx; rec.held = held;
  return rec;
}

export const q = (page, fn, arg) => page.evaluate(fn, arg);

export async function videoState(page) {
  return page.evaluate(() => {
    const v = document.querySelector(".dbgr-hero-video");
    if (!v) return null;
    const hero = v.closest("[data-db-manoush-hero]");
    const r = v.getBoundingClientRect();
    return {
      src: v.getAttribute("src"), currentSrc: v.currentSrc, source: v.getAttribute("data-dbgr-source"),
      paused: v.paused, t: +v.currentTime.toFixed(2), ready: v.readyState, w: v.videoWidth, h: v.videoHeight,
      cls: v.className, opacity: getComputedStyle(v).opacity, display: getComputedStyle(v).display,
      rect: [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)],
      heroPaused: hero.classList.contains("is-photo-paused"), muted: v.muted, err: v.error ? v.error.code : 0,
    };
  });
}

export function mp4Requests(rec) { return rec.reqs.filter((r) => /\.mp4/.test(r.url)); }
export { chromium, fs };
