// WP-03 browser check: consent banner, Consent Mode v2 default, Tag Manager loader and dataLayer dispatcher
// on the local WordPress runtime (companion mounted next to DoughBoss; see scripts/wp-local/start.sh).
//
//   WPL_URL=http://127.0.0.1:9403 node scripts/wp-local/growth/wp03-consent.mjs      (run from web/)
//   SHOTS_DIR=/some/dir          where screenshots go (default /tmp/wpl-WP-03/shots)
//
// Third-party hosts (googletagmanager.com, google-analytics.com, facebook.com / .net) are INTERCEPTED and counted; nothing
// is sent to them. The Tag Manager script is replaced by a stub that behaves like a consent-respecting GA4 tag: it
// resolves Consent Mode state from dataLayer and requests a "collect" URL only once analytics_storage is granted. So
// "no collect before Accept" proves the plugin's Consent Mode default and update ordering, not merely that the stub is idle.
//
// Phases:
//   A  all flags off            -> no banner, no tag, zero requests to any tracking host, no cookie, no global
//   B  consent_banner + gtm on   -> banner, container request, default before container, no collect before consent,
//                                  keyboard order, Choose/Escape, equal-prominence buttons, axe, Reject persists across reload,
//                                  Accept makes the tag collect, core purchase becomes order_placed in dataLayer
//   C  consent_banner only       -> banner works, container never requested
//   D  notice-and-opt-out        -> analytics_storage granted by default (collect before a choice), advertising still denied
// The script leaves the runtime with every flag OFF again.
import { chromium, devices } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const BASE = (process.env.WPL_URL || "http://127.0.0.1:9403").replace(/\/$/, "");
const SHOTS = process.env.SHOTS_DIR || "/tmp/wpl-WP-03/shots";
const HERE = path.dirname(fileURLToPath(import.meta.url));
const AXE = path.resolve(HERE, "../../../node_modules/axe-core/axe.min.js");
const ORIGIN = new URL(BASE).origin;
fs.mkdirSync(SHOTS, { recursive: true });

const TRACKING_HOSTS = /(^|\.)(googletagmanager\.com|google-analytics\.com|analytics\.google\.com|doubleclick\.net|facebook\.com|facebook\.net)$/;

const checks = [];
function record(name, ok, detail) {
  checks.push({ name, ok: !!ok, detail: detail || "" });
  process.stdout.write(`${ok ? "PASS" : "FAIL"}  ${name}${detail ? "  (" + detail + ")" : ""}\n`);
}

const STUB_GTM = `
(function () {
  var state = { analytics_storage: 'unset', ad_storage: 'unset' };
  var fired = false;
  var dl = window.dataLayer = window.dataLayer || [];
  function isArgs(v) { return Object.prototype.toString.call(v) === '[object Arguments]'; }
  function resolve(a) {
    if (a && a[0] === 'consent' && (a[1] === 'default' || a[1] === 'update') && a[2]) {
      if (a[2].analytics_storage) { state.analytics_storage = a[2].analytics_storage; }
      if (a[2].ad_storage) { state.ad_storage = a[2].ad_storage; }
    }
  }
  function maybeFire() {
    if (state.analytics_storage === 'granted' && !fired) {
      fired = true;
      new Image().src = 'https://www.google-analytics.com/g/collect?v=2&tid=G-STUB&en=page_view';
    }
  }
  for (var i = 0; i < dl.length; i += 1) { if (isArgs(dl[i])) { resolve(dl[i]); } }
  var original = dl.push;
  dl.push = function () {
    for (var j = 0; j < arguments.length; j += 1) { if (isArgs(arguments[j])) { resolve(arguments[j]); } }
    var result = original.apply(dl, arguments);
    maybeFire();
    return result;
  };
  window.__gtmStub = { state: state };
  maybeFire();
}());
`;

function newTracker() {
  const seen = [];
  return {
    seen,
    gtm: () => seen.filter((r) => r.host === "www.googletagmanager.com" && r.path === "/gtm.js").length,
    collect: () => seen.filter((r) => /google-analytics\.com$/.test(r.host) && /collect/.test(r.path)).length,
    tracking: () => seen.length,
    pixel: () => seen.filter((r) => /facebook\.(com|net)$/.test(r.host)).length,
  };
}

async function newContext(browser, tracker, deviceOptions) {
  const context = await browser.newContext(deviceOptions || { ...devices["Desktop Chrome"], viewport: { width: 1280, height: 800 } });
  await context.route((url) => TRACKING_HOSTS.test(url.hostname), async (route) => {
    const url = new URL(route.request().url());
    tracker.seen.push({ host: url.hostname, path: url.pathname, method: route.request().method() });
    if (url.hostname === "www.googletagmanager.com" && url.pathname === "/gtm.js") {
      await route.fulfill({ status: 200, contentType: "application/javascript", body: STUB_GTM });
    } else {
      await route.fulfill({ status: 204, body: "" });
    }
  });
  return context;
}

async function adminLogin(context) {
  const page = await context.newPage();
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill("#user_login", "admin");
  await page.fill("#user_pass", "password");
  await Promise.all([page.waitForURL(/wp-admin/), page.click("#wp-submit")]);
  await page.close();
}

async function saveSettings(context, fields) {
  const page = await context.request.get(`${BASE}/wp-admin/admin.php?page=doughboss-growth`);
  const html = await page.text();
  const nonce = (html.match(/name="_wpnonce"[^>]*value="([0-9a-f]+)"/) || html.match(/value="([0-9a-f]+)"[^>]*name="_wpnonce"/) || [])[1];
  if (!nonce) {
    throw new Error("no save nonce on the settings page");
  }
  const response = await context.request.post(`${BASE}/wp-admin/admin-post.php`, {
    form: { action: "doughboss_growth_save_settings", _wpnonce: nonce, ...fields },
    maxRedirects: 0,
  });
  return { status: response.status(), location: response.headers().location || "" };
}

async function health(context) {
  const nonceResponse = await context.request.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`);
  const restNonce = (await nonceResponse.text()).trim();
  const response = await context.request.get(`${BASE}/?rest_route=/doughboss-growth/v1/health`, { headers: { "x-wp-nonce": restNonce } });
  return response.json();
}

function collectErrors(page, bucket) {
  page.on("pageerror", (e) => bucket.push("pageerror: " + e.message));
  page.on("console", (m) => {
    if (m.type() !== "error") return;
    const loc = m.location() && m.location().url ? m.location().url : "";
    if (!loc || loc.startsWith(ORIGIN)) bucket.push("console.error: " + m.text() + (loc ? " @ " + loc : ""));
  });
  page.on("response", (r) => {
    if (r.status() >= 400 && r.url().startsWith(ORIGIN)) bucket.push("http " + r.status() + ": " + r.url());
  });
}

const readDataLayer = (page) => page.evaluate(() => (window.dataLayer || []).map((e) => (Object.prototype.toString.call(e) === "[object Arguments]" ? Array.prototype.slice.call(e) : e)));

async function runAxe(page, label, includeSelectors) {
  await page.addScriptTag({ path: AXE });
  const result = await page.evaluate(async (selectors) => {
    const r = await axe.run({ include: selectors.map((s) => [s]) }, { resultTypes: ["violations"] });
    return r.violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.map((n) => n.target.join(" ")) }));
  }, includeSelectors);
  record(`axe: no violations (${label})`, result.length === 0, result.length ? JSON.stringify(result) : "0 violations");
}

async function styleOf(locator) {
  return locator.evaluate((el) => {
    const s = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    return {
      backgroundColor: s.backgroundColor, color: s.color, borderTopWidth: s.borderTopWidth, borderTopColor: s.borderTopColor,
      borderTopStyle: s.borderTopStyle, borderRadius: s.borderTopLeftRadius, fontSize: s.fontSize, fontWeight: s.fontWeight,
      paddingTop: s.paddingTop, paddingLeft: s.paddingLeft, height: Math.round(r.height),
    };
  });
}

const browser = await chromium.launch();
let fatal = null;
try {
  /* ---------- admin session (separate context, no tracker needed) ---------- */
  const adminContext = await browser.newContext();
  await adminLogin(adminContext);
  let h = await health(adminContext);
  record("starting state: every flag is off on the runtime", Object.values(h.flags_configured).every((v) => v === false), `core ${h.core_version}, companion ${h.plugin_version}`);

  /* ---------- A: flags off ---------- */
  {
    const tracker = newTracker();
    const context = await newContext(browser, tracker);
    const page = await context.newPage();
    const errors = [];
    collectErrors(page, errors);
    for (const url of ["/", "/order/", "/catering/"]) {
      await page.goto(BASE + url, { waitUntil: "load" });
    }
    await page.waitForTimeout(500);
    record("A flags off: zero requests to Tag Manager, Analytics, Meta or any tracking host", tracker.tracking() === 0, `${tracker.tracking()} request(s)`);
    record("A flags off: no banner element and no reopen button in the page", (await page.locator("#dbgr-consent, #dbgr-consent-reopen").count()) === 0);
    record("A flags off: no DoughBossGrowth global, no dataLayer, no gtag, no consent cookie", await page.evaluate(() => typeof window.DoughBossGrowth === "undefined" && typeof window.dataLayer === "undefined" && typeof window.gtag === "undefined" && typeof window.DoughBossGrowthConfig === "undefined"));
    record("A flags off: no dbgr_consent cookie", !(await context.cookies()).some((c) => c.name === "dbgr_consent"));
    const html = await page.content();
    record("A flags off: the page source mentions no companion asset", !/dbgr-|DoughBossGrowth|doughboss-growth/.test(html));
    record("A flags off: no first-party console or HTTP errors", errors.length === 0, errors.slice(0, 3).join(" | "));
    await context.close();
  }

  /* ---------- B: banner + Tag Manager (dummy container id) ---------- */
  const saved = await saveSettings(adminContext, {
    "dbgr[features][consent_banner]": "1",
    "dbgr[features][gtm]": "1",
    "dbgr[gtm_container_id]": "GTM-TEST123",
    "dbgr[privacy_policy_url]": "/privacy-policy/",
    "dbgr[consent_text_version]": "1",
    "dbgr[consent_default]": "deny",
  });
  h = await health(adminContext);
  record("B settings saved: consent_banner and gtm effective", saved.status === 302 && h.flags.consent_banner === true && h.flags.gtm === true, saved.location.replace(BASE, ""));
  record("B the consent module is active in /health", h.modules_active.consent === true);

  {
    const tracker = newTracker();
    const context = await newContext(browser, tracker);
    const page = await context.newPage();
    const errors = [];
    collectErrors(page, errors);
    await page.goto(BASE + "/", { waitUntil: "load" });
    await page.waitForTimeout(800);

    const banner = page.locator("#dbgr-consent");
    record("B first visit: the banner is visible", await banner.isVisible());
    record("B first visit: the container was requested exactly once", tracker.gtm() === 1, `${tracker.gtm()} gtm.js request(s)`);
    record("B first visit: NO collect request before a choice", tracker.collect() === 0, `${tracker.collect()} collect request(s)`);
    record("B first visit: no Meta pixel request", tracker.pixel() === 0);
    const dl = await readDataLayer(page);
    const iDefault = dl.findIndex((e) => Array.isArray(e) && e[0] === "consent" && e[1] === "default");
    const iGtm = dl.findIndex((e) => !Array.isArray(e) && e.event === "gtm.js");
    record("B Consent Mode default is pushed BEFORE the container's gtm.js event", iDefault >= 0 && iGtm > iDefault, `default at ${iDefault}, gtm.js at ${iGtm}`);
    record("B default: all four signals denied, wait_for_update 500", JSON.stringify(dl[iDefault] && dl[iDefault][2]) === JSON.stringify({ ad_storage: "denied", ad_user_data: "denied", ad_personalization: "denied", analytics_storage: "denied", wait_for_update: 500 }));
    record("B first visit: no consent update and no typed event pushed yet", dl.filter((e) => Array.isArray(e) && e[1] === "update").length === 0 && dl.filter((e) => !Array.isArray(e) && e.event && e.event !== "gtm.js" && !/^gtm\./.test(e.event)).length === 0);
    record("B first visit: no dbgr_consent cookie before a choice", !(await context.cookies()).some((c) => c.name === "dbgr_consent"));
    record("B focus moved into the banner (non-modal dialog)", await page.evaluate(() => document.activeElement && document.activeElement.id === "dbgr-consent"));
    record("B no <noscript> Tag Manager frame in the page", !(await page.content()).includes("ns.html?id="));

    // Equal prominence of the three choices.
    const styles = [];
    for (const action of ["accept", "reject", "choose"]) styles.push(await styleOf(page.locator(`[data-dbgr-action="${action}"]`)));
    const same = styles.every((s) => JSON.stringify(s) === JSON.stringify(styles[0]));
    record("B Accept all, Reject all and Choose have identical computed styles (equal prominence)", same, same ? `${styles[0].fontSize}, ${styles[0].height}px tall` : JSON.stringify(styles));
    record("B each choice meets a 44px minimum target height", styles.every((s) => s.height >= 44), styles.map((s) => s.height).join("/"));

    await page.screenshot({ path: path.join(SHOTS, "wp03-banner-desktop.png") });
    await runAxe(page, "banner, choices closed", ["#dbgr-consent", "#dbgr-consent-reopen"]);

    // Keyboard: Tab order inside the dialog, Enter on Choose, Escape.
    const order = [];
    for (let i = 0; i < 4; i += 1) {
      await page.keyboard.press("Tab");
      order.push(await page.evaluate(() => {
        const el = document.activeElement;
        return el ? (el.getAttribute("data-dbgr-action") || (el.className || "").toString().split(" ")[0] || el.tagName) : "";
      }));
    }
    record("B Tab order: policy link, Accept, Reject, Choose", JSON.stringify(order) === JSON.stringify(["dbgr-consent__link", "accept", "reject", "choose"]), order.join(" > "));
    await page.keyboard.press("Enter");
    record("B Enter on Choose opens the panel and sets aria-expanded", (await page.locator("#dbgr-consent-panel").isVisible()) && (await page.locator('[data-dbgr-action="choose"]').getAttribute("aria-expanded")) === "true");
    record("B the category checkboxes start unticked (no pre-ticked consent)", !(await page.locator("#dbgr-consent-m").isChecked()) && !(await page.locator("#dbgr-consent-a").isChecked()));
    await page.screenshot({ path: path.join(SHOTS, "wp03-banner-choices-desktop.png") });
    await runAxe(page, "banner, choices open", ["#dbgr-consent"]);
    await page.locator("#dbgr-consent-m").focus();
    await page.keyboard.press("Escape");
    record("B Escape closes the panel and returns focus to Choose", !(await page.locator("#dbgr-consent-panel").isVisible()) && (await page.evaluate(() => document.activeElement.getAttribute("data-dbgr-action"))) === "choose");
    await page.keyboard.press("Escape");
    record("B Escape with nothing chosen does NOT dismiss the banner", await banner.isVisible());
    record("B still no collect request and no cookie after browsing the banner", tracker.collect() === 0 && !(await context.cookies()).some((c) => c.name === "dbgr_consent"));

    // Reject, then persistence across a reload.
    await page.locator('[data-dbgr-action="reject"]').click();
    await page.waitForTimeout(200);
    const cookie = (await context.cookies()).find((c) => c.name === "dbgr_consent");
    const parsed = cookie ? JSON.parse(decodeURIComponent(cookie.value)) : null;
    record("B Reject writes dbgr_consent {v,m,a,ts} with m=0 a=0", !!parsed && parsed.v === "1" && parsed.m === 0 && parsed.a === 0 && Number.isInteger(parsed.ts) && JSON.stringify(Object.keys(parsed)) === JSON.stringify(["v", "m", "a", "ts"]), cookie ? decodeURIComponent(cookie.value) : "no cookie");
    const days = cookie ? Math.round((cookie.expires - Date.now() / 1000) / 86400) : 0;
    record("B the cookie lasts 180 days, SameSite=Lax, path /", days >= 179 && days <= 180 && cookie.sameSite === "Lax" && cookie.path === "/", `${days} days, ${cookie && cookie.sameSite}`);
    record("B after Reject the banner closes and Privacy choices is shown and focused", !(await banner.isVisible()) && (await page.locator("#dbgr-consent-reopen").isVisible()) && (await page.evaluate(() => document.activeElement && document.activeElement.id === "dbgr-consent-reopen")));
    record("B after Reject: no collect request", tracker.collect() === 0);
    const afterReject = await readDataLayer(page);
    const update = afterReject.find((e) => Array.isArray(e) && e[1] === "update");
    record("B Reject sends a Consent Mode update with everything denied", !!update && Object.values(update[2]).every((v) => v === "denied"), JSON.stringify(update && update[2]));
    // Typed events are refused while nothing is allowed.
    const refused = await page.evaluate(() => window.DoughBossGrowth.track("select_store", { store: "revesby" }));
    record("B after Reject DoughBossGrowth.track refuses (nothing is allowed)", refused === false);

    await page.reload({ waitUntil: "load" });
    await page.waitForTimeout(500);
    record("B Reject persists across a reload: no banner, Privacy choices visible", !(await banner.isVisible()) && (await page.locator("#dbgr-consent-reopen").isVisible()));
    const replay = await readDataLayer(page);
    const replayUpdate = replay.find((e) => Array.isArray(e) && e[1] === "update");
    const iReplay = replay.findIndex((e) => Array.isArray(e) && e[1] === "update");
    const iGtm2 = replay.findIndex((e) => !Array.isArray(e) && e.event === "gtm.js");
    record("B replay: the stored choice is sent as a Consent Mode update before the container loads", !!replayUpdate && iReplay < iGtm2 && Object.values(replayUpdate[2]).every((v) => v === "denied"), `update at ${iReplay}, gtm.js at ${iGtm2}`);
    record("B after the reload there is still no collect request", tracker.collect() === 0);
    record("B the reload requested the container again (still one loader per page)", tracker.gtm() === 2);

    // Reopen, tick measurement only, Save -> tag collects.
    await page.locator("#dbgr-consent-reopen").click();
    record("B Privacy choices reopens the banner with the panel open", (await banner.isVisible()) && (await page.locator("#dbgr-consent-panel").isVisible()));
    await page.locator("#dbgr-consent-m").check();
    await page.locator('[data-dbgr-action="save"]').click();
    await page.waitForTimeout(400);
    const saved2 = JSON.parse(decodeURIComponent((await context.cookies()).find((c) => c.name === "dbgr_consent").value));
    record("B Save with measurement only stores m=1 a=0", saved2.m === 1 && saved2.a === 0);
    record("B after granting measurement the tag collects (the update reached the tag)", tracker.collect() === 1, `${tracker.collect()} collect request(s)`);
    const afterSave = await readDataLayer(page);
    const lastUpdate = afterSave.filter((e) => Array.isArray(e) && e[1] === "update").pop();
    record("B measurement-only: analytics granted, the three advertising signals denied", !!lastUpdate && lastUpdate[2].analytics_storage === "granted" && lastUpdate[2].ad_storage === "denied" && lastUpdate[2].ad_user_data === "denied" && lastUpdate[2].ad_personalization === "denied");

    // Typed events now flow; core purchase becomes order_placed.
    const pushedOk = await page.evaluate(() => window.DoughBossGrowth.track("select_store", { store: "revesby", email: "a@b.co" }));
    record("B after consent track() pushes, dropping a stray email parameter", pushedOk === true);
    await page.evaluate(() => {
      document.dispatchEvent(new CustomEvent("doughboss:marketing-event", { detail: { schema_version: 1, event_id: "e2e-1", event_type: "purchase", properties: { currency: "AUD", value: 23.45, num_items: 3, order_type: "pickup", location_id: 999, channel: "web" } } }));
      document.dispatchEvent(new CustomEvent("doughboss:marketing-event", { detail: { schema_version: 1, event_id: "e2e-2", event_type: "generate_lead", properties: { content_name: "After-hours preorder request", content_category: "Preorder", currency: "AUD", location_id: 1, channel: "web" } } }));
    });
    const events = (await readDataLayer(page)).filter((e) => !Array.isArray(e) && e.event && !/^gtm\./.test(e.event) && e.event !== "gtm.js");
    record("B core purchase reaches dataLayer as order_placed, never as purchase", events.some((e) => e.event === "order_placed" && e.value_cents === 2345 && e.item_count === 3) && !events.some((e) => e.event === "purchase"), JSON.stringify(events.map((e) => e.event)));
    record("B core pre-order generate_lead is not forwarded as a catering lead", !events.some((e) => e.event === "generate_lead"));
    record("B the typed event carries only taxonomy keys (no email, no value, no currency)", events.every((e) => Object.keys(e).every((k) => ["event", "store", "value_cents", "item_count", "item_slug", "item_name", "category", "quantity", "payment_method", "form", "step", "surface", "cta", "destination", "category", "guest_band"].includes(k))));
    const refusedNames = await page.evaluate(() => ["purchase", "hero_explore", "page_view"].map((n) => window.DoughBossGrowth.track(n, {})));
    record("B names outside the taxonomy are refused in the browser (purchase, the cancelled hero event, page_view)", refusedNames.every((v) => v === false));

    // Core's own bridge: pixels cleared, enabled.
    await page.goto(BASE + "/order/", { waitUntil: "load" });
    const bridge = await page.evaluate(() => (window.DoughBossMarketingConfig ? { enabled: window.DoughBossMarketingConfig.enabled, meta: window.DoughBossMarketingConfig.metaPixelId, tiktok: window.DoughBossMarketingConfig.tiktokPixelId, fbq: typeof window.fbq, ttq: typeof window.ttq } : null));
    record("B core bridge config: enabled (WordPress prints true as 1), both pixel ids empty, no fbq or ttq", !!bridge && (bridge.enabled === true || bridge.enabled === "1") && bridge.meta === "" && bridge.tiktok === "" && bridge.fbq === "undefined" && bridge.ttq === "undefined", JSON.stringify(bridge));
    const coreConsent = await page.evaluate(() => (window.DoughBossMarketing ? window.DoughBossMarketing.getConsent() : null));
    record("B core received the stored choice (doughboss:consent replay): measurement true, advertising false", !!coreConsent && coreConsent.measurement === true && coreConsent.advertising === false && coreConsent.version === "1", JSON.stringify(coreConsent));
    record("B no request to Meta on the ordering page", tracker.pixel() === 0);
    record("B no first-party console or HTTP errors", errors.length === 0, errors.slice(0, 3).join(" | "));
    await context.close();
  }

  // Accept path in a fresh context: nothing before, collect after.
  {
    const tracker = newTracker();
    const context = await newContext(browser, tracker);
    const page = await context.newPage();
    await page.goto(BASE + "/", { waitUntil: "load" });
    await page.waitForTimeout(500);
    const before = tracker.collect();
    await page.locator('[data-dbgr-action="accept"]').click();
    await page.waitForTimeout(400);
    record("B Accept: zero collect requests before, one after", before === 0 && tracker.collect() === 1, `before ${before}, after ${tracker.collect()}`);
    const update = (await readDataLayer(page)).filter((e) => Array.isArray(e) && e[1] === "update").pop();
    record("B Accept grants all four signals", !!update && Object.values(update[2]).length === 4 && Object.values(update[2]).every((v) => v === "granted"));
    await context.close();
  }

  // Mobile viewport: banner fits, no horizontal scroll, axe.
  {
    const tracker = newTracker();
    const context = await newContext(browser, tracker, { ...devices["Pixel 7"] });
    const page = await context.newPage();
    await page.goto(BASE + "/", { waitUntil: "load" });
    await page.waitForTimeout(500);
    const geometry = await page.evaluate(() => {
      const b = document.getElementById("dbgr-consent").getBoundingClientRect();
      return { left: Math.round(b.left), right: Math.round(b.right), top: Math.round(b.top), bottom: Math.round(b.bottom), vw: window.innerWidth, vh: window.innerHeight, scrollW: document.documentElement.scrollWidth };
    });
    record("B Pixel 7: banner sits inside the viewport and the page has no horizontal scroll", geometry.left >= 0 && geometry.right <= geometry.vw && geometry.bottom <= geometry.vh && geometry.scrollW <= geometry.vw, JSON.stringify(geometry));
    await page.screenshot({ path: path.join(SHOTS, "wp03-banner-pixel7.png") });
    await runAxe(page, "banner on Pixel 7", ["#dbgr-consent"]);
    await context.close();
  }

  /* ---------- C: banner only ---------- */
  await saveSettings(adminContext, { "dbgr[features][consent_banner]": "1", "dbgr[consent_default]": "deny" });
  h = await health(adminContext);
  record("C settings: banner on, gtm off", h.flags.consent_banner === true && h.flags.gtm === false);
  {
    const tracker = newTracker();
    const context = await newContext(browser, tracker);
    const page = await context.newPage();
    const errors = [];
    collectErrors(page, errors);
    await page.goto(BASE + "/", { waitUntil: "load" });
    await page.waitForTimeout(400);
    record("C banner only: the banner shows", await page.locator("#dbgr-consent").isVisible());
    record("C banner only: no Tag Manager, no dataLayer, no gtag, zero tracking requests", tracker.tracking() === 0 && (await page.evaluate(() => typeof window.dataLayer === "undefined" && typeof window.gtag === "undefined")));
    await page.locator('[data-dbgr-action="accept"]').click();
    await page.waitForTimeout(300);
    record("C banner only: Accept still stores the choice and touches no dataLayer", (await context.cookies()).some((c) => c.name === "dbgr_consent") && (await page.evaluate(() => typeof window.dataLayer === "undefined")) && tracker.tracking() === 0);
    record("C banner only: DoughBossGrowth.track exists and refuses (nothing to send to)", await page.evaluate(() => typeof window.DoughBossGrowth.track === "function" && window.DoughBossGrowth.track("select_store", { store: "revesby" }) === false));
    record("C no first-party console or HTTP errors", errors.length === 0, errors.slice(0, 3).join(" | "));
    await context.close();
  }

  /* ---------- D: notice-and-opt-out ---------- */
  await saveSettings(adminContext, {
    "dbgr[features][consent_banner]": "1",
    "dbgr[features][gtm]": "1",
    "dbgr[gtm_container_id]": "GTM-TEST123",
    "dbgr[consent_default]": "opt_out",
  });
  {
    const tracker = newTracker();
    const context = await newContext(browser, tracker);
    const page = await context.newPage();
    await page.goto(BASE + "/", { waitUntil: "load" });
    await page.waitForTimeout(600);
    const dl = await readDataLayer(page);
    const def = dl.find((e) => Array.isArray(e) && e[1] === "default");
    record("D opt-out default: analytics_storage granted, the three advertising signals denied", !!def && def[2].analytics_storage === "granted" && def[2].ad_storage === "denied" && def[2].ad_user_data === "denied" && def[2].ad_personalization === "denied", JSON.stringify(def && def[2]));
    record("D opt-out: the banner is still shown before a choice", await page.locator("#dbgr-consent").isVisible());
    record("D opt-out: measurement runs before a choice (collect requested), no Meta request", tracker.collect() === 1 && tracker.pixel() === 0);
    await page.locator('[data-dbgr-action="reject"]').click();
    await page.waitForTimeout(200);
    const update = (await readDataLayer(page)).filter((e) => Array.isArray(e) && e[1] === "update").pop();
    record("D opt-out: Reject then denies analytics too", !!update && update[2].analytics_storage === "denied");
    await context.close();
  }

  /* ---------- leave everything off ---------- */
  await saveSettings(adminContext, {});
  h = await health(adminContext);
  record("cleanup: every flag is off again", Object.values(h.flags_configured).every((v) => v === false) && Object.values(h.flags).every((v) => v === false));
  {
    const tracker = newTracker();
    const context = await newContext(browser, tracker);
    const page = await context.newPage();
    await page.goto(BASE + "/", { waitUntil: "load" });
    record("cleanup: flags off again means no banner and no tracking request", (await page.locator("#dbgr-consent").count()) === 0 && tracker.tracking() === 0);
    await context.close();
  }
  await adminContext.close();
} catch (error) {
  fatal = error;
  record("script completed without an exception", false, String(error && error.stack ? error.stack : error).split("\n").slice(0, 4).join(" | "));
} finally {
  await browser.close();
}

const failed = checks.filter((c) => !c.ok);
fs.writeFileSync(path.join(SHOTS, "wp03-checks.json"), JSON.stringify(checks, null, 2));
process.stdout.write(failed.length === 0 && !fatal ? `ALL ${checks.length} WP-03 BROWSER CHECKS PASSED\n` : `${failed.length} OF ${checks.length} WP-03 BROWSER CHECK(S) FAILED\n`);
process.exit(failed.length === 0 && !fatal ? 0 : 1);
