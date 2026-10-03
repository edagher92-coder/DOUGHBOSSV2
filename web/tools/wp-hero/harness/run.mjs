// Real-browser check of a fresh hero build (scratch harness, not part of the plugin).
//
//   node tools/wp-hero/harness/run.mjs            # from web/
//
// Builds into a temp dir, serves it with a tiny static server on 127.0.0.1 (no outbound request),
// drives headless Chromium (software GL) through: render + frame stats, scroll-linked progress,
// pause when hidden, stop() disposal, the frame-time guard (impossible budget), a tampered
// integrity value, and reduced motion. Prints one JSON report; exit code 1 on any failed check.
import { chromium } from "@playwright/test";
import http from "node:http";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { build } from "../build.mjs";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const SHOTS = process.env.HERO_SHOTS_DIR || path.join(os.tmpdir(), "wp09-shots");
fs.mkdirSync(SHOTS, { recursive: true });
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), "wp09-harness-"));
const dist = path.join(tmp, "dist");
await build({ outDir: dist, env: { SOURCE_DATE_EPOCH: "1790000000" } });

const MIME = { ".html": "text/html", ".js": "text/javascript", ".json": "application/json", ".glb": "model/gltf-binary", ".webp": "image/webp" };
const requests = [];
const server = http.createServer((req, res) => {
  const url = new URL(req.url, "http://127.0.0.1");
  requests.push(url.pathname);
  let file = null;
  if (url.pathname === "/" || url.pathname === "/index.html") file = path.join(HERE, "index.html");
  else if (url.pathname.startsWith("/dist/")) file = path.join(dist, path.normalize(url.pathname.slice(6)));
  if (!file || !file.startsWith(HERE) && !file.startsWith(dist) || !fs.existsSync(file)) {
    res.writeHead(404);
    res.end("not found");
    return;
  }
  res.writeHead(200, { "content-type": MIME[path.extname(file)] || "application/octet-stream", "x-content-type-options": "nosniff" });
  fs.createReadStream(file).pipe(res);
});
await new Promise((r) => server.listen(0, "127.0.0.1", r));
const BASE = "http://127.0.0.1:" + server.address().port;

const checks = [];
function check(name, ok, detail) {
  checks.push({ name, ok: Boolean(ok), detail });
}

// Set the progress, let the controller draw it, and count non-transparent pixels in the WebGL
// canvas in the SAME frame (the drawing buffer is cleared once it has been composited). The
// controller's rAF is registered first by setProgress, so it runs before the measuring callback.
const measureAt = (p) => `new Promise((resolve) => {
  const c = document.getElementById('c');
  window.__hero.setProgress(${p});
  requestAnimationFrame(() => {
    const t = document.createElement('canvas'); t.width = c.width; t.height = c.height;
    const ctx = t.getContext('2d'); ctx.drawImage(c, 0, 0);
    const d = ctx.getImageData(0, 0, t.width, t.height).data;
    let opaque = 0, minX = t.width, maxX = 0;
    for (let i = 3, p = 0; i < d.length; i += 4, p += 1) { if (d[i] > 8) { opaque += 1; const x = p % t.width; if (x < minX) minX = x; if (x > maxX) maxX = x; } }
    resolve({ opaque, total: t.width * t.height, spanX: opaque ? maxX - minX : 0 });
  });
})`;

const browser = await chromium.launch({
  args: ["--use-gl=angle", "--use-angle=swiftshader", "--enable-unsafe-swiftshader", "--ignore-gpu-blocklist"],
});

async function open(query, ctxOptions) {
  const ctx = await browser.newContext({ viewport: { width: 820, height: 620 }, ...(ctxOptions || {}) });
  const page = await ctx.newPage();
  const errors = [];
  page.on("pageerror", (e) => errors.push(String(e)));
  await page.goto(BASE + "/" + (query || ""));
  await page.waitForFunction(() => window.__booted === true, null, { timeout: 30000 });
  return { ctx, page, errors };
}
const waitEvent = (page, type, timeout = 60000) =>
  page.waitForFunction((t) => window.__events.some((e) => e.type === t), type, { timeout });
const events = (page) => page.evaluate(() => window.__events);

const report = { base: "http://127.0.0.1:<port>", shots: SHOTS };
try {
  // 1. Render, stats, scroll-linked progress, pause when hidden, stop().
  {
    const { ctx, page, errors } = await open("?budget=5000");
    await waitEvent(page, "ready");
    const ready = (await events(page)).find((e) => e.type === "ready").detail;
    report.ready = ready;
    check("onReady fires with a median frame time", ready.renderer === "webgl2" && typeof ready.medianFrameMs === "number" && ready.sampleFrames === 60, ready);

    const rest = await page.evaluate(measureAt(0));
    await page.screenshot({ path: path.join(SHOTS, "wp09-rest.png"), clip: { x: 0, y: 0, width: 800, height: 600 } });
    check("scene renders visible pixels at rest", rest.opaque > 20000, rest);

    const exploded = await page.evaluate(measureAt(1));
    await page.screenshot({ path: path.join(SHOTS, "wp09-exploded.png"), clip: { x: 0, y: 0, width: 800, height: 600 } });
    check("exploded pose spreads wider than rest", exploded.spanX > rest.spanX, { rest: rest.spanX, exploded: exploded.spanX });

    await page.evaluate(measureAt(0.4));
    await page.screenshot({ path: path.join(SHOTS, "wp09-mid.png"), clip: { x: 0, y: 0, width: 800, height: 600 } });

    await page.evaluate("window.__hero.replay()");
    await waitEvent(page, "complete", 20000);
    check("replay() plays to completion and reports onComplete", true, null);

    // Pause when the tab is hidden: no draw calls while hidden.
    await page.evaluate("window.__hero.replay()");
    await page.evaluate(() => {
      Object.defineProperty(document, "hidden", { configurable: true, get: () => true });
      document.dispatchEvent(new Event("visibilitychange"));
    });
    await page.waitForTimeout(150);
    const before = await page.evaluate("window.__draws");
    await page.waitForTimeout(600);
    const during = await page.evaluate("window.__draws");
    check("no draw calls while the tab is hidden", during === before, { before, during });
    await page.evaluate(() => {
      Object.defineProperty(document, "hidden", { configurable: true, get: () => false });
      document.dispatchEvent(new Event("visibilitychange"));
    });
    await waitEvent(page, "complete", 20000);
    check("rendering resumes when visible again", (await page.evaluate("window.__draws")) > during, null);

    // stop() releases the context and stops drawing.
    await page.evaluate("window.__hero.stop()");
    await page.waitForTimeout(200);
    const drawsAtStop = await page.evaluate("window.__draws");
    await page.waitForTimeout(400);
    const lost = await page.evaluate(() => document.getElementById("c").getContext("webgl2").isContextLost());
    check("stop() disposes: context lost, state stopped, no further draws", lost && (await page.evaluate("window.__hero.state")) === "stopped" && (await page.evaluate("window.__draws")) === drawsAtStop, { lost });
    check("no page errors in the happy path", errors.length === 0, errors);
    await ctx.close();
  }

  // 2. Frame-time guard with an impossible budget (deterministic on any machine).
  {
    const { ctx, page } = await open("?budget=0.5");
    await waitEvent(page, "budget");
    const ev = (await events(page)).find((e) => e.type === "budget").detail;
    report.guard = { median: ev.median, sampleCount: ev.stats.sampleCount };
    check("guard fires when the median is over budget (negative control)", ev.median > 0.5 && ev.stats.exceeded === true && !(await events(page)).some((e) => e.type === "ready"), report.guard);
    await page.waitForTimeout(200);
    check("guard disposes the scene", (await page.evaluate("window.__hero.state")) === "failed" && (await page.evaluate(() => document.getElementById("c").getContext("webgl2").isContextLost())), null);
    await ctx.close();
  }

  // 3. Default 24 ms budget on this machine (informational: software GL is not representative).
  {
    const { ctx, page } = await open("?budget=24");
    await page.waitForFunction(() => window.__events.some((e) => e.type === "ready" || e.type === "budget"), null, { timeout: 60000 });
    const ev = (await events(page))[0];
    report.default_budget_run = { outcome: ev.type, medianMs: ev.type === "ready" ? ev.detail.medianFrameMs : ev.detail.median };
    await ctx.close();
  }

  // 4. Tampered integrity value: browser refuses the GLB, controller fails closed, never ready.
  {
    const { ctx, page } = await open("?tamper=1&budget=5000");
    await waitEvent(page, "error", 20000);
    const err = (await events(page)).find((e) => e.type === "error").detail;
    check("tampered sha384 -> error and never ready (negative control)", err.code === "fetch-failed" && !(await events(page)).some((e) => e.type === "ready"), err);
    await ctx.close();
  }

  // 5. Reduced motion: stays inert and requests no GLB at all.
  {
    const before = requests.length;
    const { ctx, page } = await open("?budget=5000", { reducedMotion: "reduce" });
    await waitEvent(page, "error", 20000);
    const err = (await events(page)).find((e) => e.type === "error").detail;
    const glbRequests = requests.slice(before).filter((r) => r.endsWith(".glb"));
    check("prefers-reduced-motion -> inert, zero GLB requests", err.code === "reduced-motion" && glbRequests.length === 0, { err, glbRequests });
    await ctx.close();
  }
} finally {
  await browser.close();
  server.close();
  fs.rmSync(tmp, { recursive: true, force: true });
}

report.checks = checks;
console.log(JSON.stringify(report, null, 2));
process.exit(checks.every((c) => c.ok) ? 0 : 1);
