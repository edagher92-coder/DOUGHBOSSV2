// WP-07 browser check: corporate lead form and party-pack sizer on the local WordPress runtime (companion mounted next to
// DoughBoss; see scripts/wp-local/start.sh and WPL_EXTRA_PLUGIN_SRC). It drives the REAL core enquiry route and the REAL core
// quote route, and reads the results back from core's own staff REST list and from the companion's "Leads" admin tab, so nothing
// is read from a database directly.
//
//   WPL_URL=http://127.0.0.1:9407 WPL_STATE=/tmp/wpl-WP-07 node scripts/wp-local/growth/wp07-leads.mjs      (run from web/)
//   SHOTS_DIR=/some/dir          where screenshots go (default $WPL_STATE/shots)
//
// SCRATCH PREPARATION (done once, in the runtime's scratch COPY of the plugin, never in the source tree; the script stops with
// NOT RUN if it is missing):
//   $WPL_STATE/src/plugins/doughboss-growth/wp07-scratch.php, loaded from the copy's main file with one extra line:
//       require_once __DIR__ . '/wp07-scratch.php';
//   It answers /?wp07_seed=1 for an administrator by adding FAKE catering packages (Test Box One 45.00 serves 10-12, Test Box Two
//   123.50 serves 20-25, Test Box Three 310.00 serves 30-40, one with no price, one draft) through WordPress, idempotently.
// Core limits catering enquiries to 5 per hour per visitor (and counts a honeypot hit), so run this against a freshly started
// runtime; this script submits at most 5 enquiries to core. No request leaves the machine.
//
// Phases:
//   A  flags off       -> a page holding both shortcodes carries no companion asset and no form
//   B  flags on        -> the Leads tab renders; the form is shown with an UNTICKED marketing box; accessibility (axe) desktop + mobile
//   C  consent ticked  -> submit the corporate form: core gets one enquiry; one lead record with segment corporate, company, consent
//                         Yes with the wording version; generate_lead {form, category, guest_band, store} reaches the tracker
//   D  not ticked      -> a second enquiry: lead record with consent No
//   E  rejected        -> a stale nonce (403), a filled honeypot (silent success, no number) and a past event date (core 400, message shown)
//                         leave NO new lead record and no new core enquiry
//   F  sizer           -> head counts 12/13/20/26/41: the suggested package covers the count (never under), the next size up is quoted
//                         too, every price equals core's own /catering/quote answer, no draft or unpriced package, no number that
//                         did not come from core or the visitor, guidance hidden, over-limit refused; axe desktop + mobile
//   G  cleanup         -> every flag off again, the test pages deleted
import { chromium, devices } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const BASE = (process.env.WPL_URL || "http://127.0.0.1:9407").replace(/\/$/, "");
const STATE = process.env.WPL_STATE || "/tmp/wpl-WP-07";
const COPY = path.join(STATE, "src", "plugins", "doughboss-growth");
const SHOTS = process.env.SHOTS_DIR || path.join(STATE, "shots");
const HERE = path.dirname(fileURLToPath(import.meta.url));
const AXE = path.resolve(HERE, "../../../node_modules/axe-core/axe.min.js");
const ORIGIN = new URL(BASE).origin;
const REST = (route) => `${BASE}/?rest_route=${route}`;
const SENDER = "Example Trading Pty Ltd";
const PAGE_SLUG = "wp07-leads-test";
const MARK = "wp07-test";
fs.mkdirSync(SHOTS, { recursive: true });

const checks = [];
function record(name, ok, detail) {
  checks.push({ name, ok: !!ok, detail: detail || "" });
  process.stdout.write(`${ok ? "PASS" : "FAIL"}  ${name}${detail ? "  (" + detail + ")" : ""}\n`);
}
function note(text) {
  process.stdout.write(`NOTE  ${text}\n`);
}

if (!fs.existsSync(path.join(COPY, "wp07-scratch.php")) || !/wp07-scratch\.php/.test(fs.readFileSync(path.join(COPY, "doughboss-growth.php"), "utf8"))) {
  process.stdout.write(`NOT RUN  the scratch preparation is missing in ${COPY} (see the header of this script)\n`);
  process.exit(2);
}

/* ------------------------------------------------------------ runtime helpers */

async function adminLogin(context) {
  const page = await context.newPage();
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill("#user_login", "admin");
  await page.fill("#user_pass", "password");
  await Promise.all([page.waitForURL(/wp-admin/), page.click("#wp-submit")]);
  await page.close();
}

async function nonceFrom(context, url, action) {
  const html = await (await context.request.get(url)).text();
  for (const f of html.split("<form")) {
    if (action && !f.includes(`value="${action}"`)) continue;
    const nonce = (f.match(/name="_wpnonce"[^>]*value="([0-9a-f]+)"/) || f.match(/value="([0-9a-f]+)"[^>]*name="_wpnonce"/) || [])[1];
    if (nonce) return nonce;
  }
  throw new Error("no nonce at " + url);
}

async function saveSettings(context, fields) {
  const nonce = await nonceFrom(context, `${BASE}/wp-admin/admin.php?page=doughboss-growth`, "doughboss_growth_save_settings");
  const response = await context.request.post(`${BASE}/wp-admin/admin-post.php`, {
    form: { action: "doughboss_growth_save_settings", _wpnonce: nonce, ...fields },
    maxRedirects: 0,
  });
  return { status: response.status(), location: response.headers().location || "" };
}

async function restNonce(context) {
  return (await (await context.request.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`)).text()).trim();
}

async function health(context) {
  const response = await context.request.get(REST("/doughboss-growth/v1/health"), { headers: { "x-wp-nonce": await restNonce(context) } });
  return response.json();
}

async function listTestPages(context) {
  const response = await context.request.get(REST("/wp/v2/pages") + "&per_page=100&status=any&context=edit", { headers: { "x-wp-nonce": await restNonce(context) } });
  return (await response.json()).filter((p) => p.slug === PAGE_SLUG || /wp07-test/.test((p.content && p.content.raw) || ""));
}

async function createPage(context, content) {
  const response = await context.request.post(REST("/wp/v2/pages"), {
    headers: { "x-wp-nonce": await restNonce(context) },
    data: { title: "WP07 leads test", slug: PAGE_SLUG, status: "publish", content },
  });
  if (!response.ok()) throw new Error(`create page: HTTP ${response.status()} ${await response.text()}`);
  const page = await response.json();
  return { id: page.id, link: page.link.replace(/^https?:\/\/[^/]+/, BASE) };
}

async function deletePage(context, id) {
  const response = await context.request.delete(REST(`/wp/v2/pages/${id}`) + "&force=true", { headers: { "x-wp-nonce": await restNonce(context) } });
  if (!response.ok()) throw new Error(`delete page ${id}: HTTP ${response.status()}`);
}

/** Core's own staff list of catering enquiries (the real writer), as data. */
async function coreEnquiries(context) {
  const response = await context.request.get(REST("/doughboss/v1/admin/catering") + "&per_page=100", { headers: { "x-wp-nonce": await restNonce(context) } });
  const body = await response.json();
  return Array.isArray(body.data) ? body.data : [];
}

/** The Leads tab, as data: the lead record count and the latest rows. */
async function leadsTab(context) {
  const response = await context.request.get(`${BASE}/wp-admin/admin.php?page=doughboss-growth&tab=leads`);
  const html = await response.text();
  const text = (s) => s.replace(/<[^>]*>/g, "").replace(/&amp;/g, "&").replace(/\s+/g, " ").trim();
  const count = Number((html.match(/Lead records: (\d+)/) || [])[1]);
  const rows = [];
  const body = html.split("Latest lead records")[1] || "";
  for (const tr of body.split("<tr>").slice(2)) {
    const cells = [...tr.matchAll(/<td>([^<]*)<\/td>/g)].map((c) => text(c[1]));
    if (cells.length === 6) rows.push({ enquiry: cells[0], segment: cells[1], company: cells[2], consent: cells[3], version: cells[4], recorded: cells[5] });
  }
  return { status: response.status(), html, count, rows };
}

function collectErrors(page, bucket) {
  page.on("pageerror", (e) => bucket.push("pageerror: " + e.message));
  page.on("console", (m) => {
    if (m.type() !== "error") return;
    const loc = m.location() && m.location().url ? m.location().url : "";
    // The browser logs every 4xx as a console error; the refusals of the enquiry route are the point of phase E.
    if (/catering(\/|%2F)enquiry/.test(loc)) return;
    if (!loc || loc.startsWith(ORIGIN)) bucket.push("console.error: " + m.text() + (loc ? " @ " + loc : ""));
  });
  page.on("response", (r) => {
    // A 4xx on the enquiry route is the point of phase E; every other first-party failure is an error.
    if (r.status() >= 400 && r.url().startsWith(ORIGIN) && !/favicon|catering\/enquiry|catering%2Fenquiry/.test(r.url())) bucket.push("http " + r.status() + ": " + r.url());
  });
}

async function runAxe(page, label, selector) {
  await page.addScriptTag({ path: AXE });
  const result = await page.evaluate(async (sel) => {
    const r = await axe.run({ include: [[sel]] }, { resultTypes: ["violations"] });
    return r.violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.map((n) => n.target.join(" ")) }));
  }, selector);
  record(`axe: no violations (${label})`, result.length === 0, result.length ? JSON.stringify(result) : "0 violations");
}

async function visit(browser, url, options = {}) {
  const context = await browser.newContext({ ...devices[options.mobile ? "Pixel 7" : "Desktop Chrome"] });
  await context.addInitScript(() => {
    // Stand in for the companion's tracker (not loaded: the consent module is off here) to see exactly what the form would send.
    window.__tracked = [];
    window.DoughBossGrowth = { track: (name, params) => { window.__tracked.push({ name, params }); return true; } };
  });
  const page = await context.newPage();
  const errors = [];
  collectErrors(page, errors);
  await page.goto(url, { waitUntil: "load" });
  return { context, page, errors };
}

/* ----------------------------------------------------------------------- run */

const browser = await chromium.launch();
let adminContext = null;
let testPage = null;
try {
  adminContext = await browser.newContext();
  await adminLogin(adminContext);
  let h = await health(adminContext);
  record("starting state: every flag is off on the runtime", Object.values(h.flags_configured).every((v) => v === false), `core ${h.core_version}, companion ${h.plugin_version}`);
  record("the leads module is present on disk and not active while its flags are off", h.modules_present.leads === true && h.modules_active.leads === false);

  for (const p of await listTestPages(adminContext)) await deletePage(adminContext, p.id);
  const seed = await (await adminContext.request.get(`${BASE}/?wp07_seed=1`)).json();
  record("scratch seed: the fake catering packages exist in core", seed.packages >= 5, JSON.stringify(seed));
  const corePackages = await (await adminContext.request.get(REST("/doughboss/v1/catering/packages"))).json();
  record("core publishes the three priced test packages (the draft is not listed)", corePackages.filter((p) => /^Test Box (One|Two|Three)$/.test(p.name)).length === 3 && !corePackages.some((p) => p.name === "Test Box Draft"), corePackages.map((p) => p.name).join(" | "));
  const shops = await (await adminContext.request.get(REST("/doughboss/v1/locations"))).json();
  const revesby = shops.find((s) => s.slug === "revesby");
  record("core reports the Revesby shop", !!revesby, JSON.stringify(shops.map((s) => s.slug)));

  testPage = await createPage(adminContext, `<!-- ${MARK} -->\n[doughboss_growth_lead_form variant="corporate"]\n\n[doughboss_growth_party_sizer]`);
  note(`test page created at ${testPage.link}`);

  /* ---------- A: flags off ---------- */
  {
    const { context, page, errors } = await visit(browser, testPage.link);
    const html = await page.content();
    record("A flags off: no companion asset, no form and no sizer on the page", !/dbgr-|DoughBossGrowth(Leads|Sizer)|data-dbgr-lead-form|data-dbgr-sizer/.test(html.replace(/window\.DoughBossGrowth = \{[^}]*\}[^<]*/, "")));
    const raw = /\[doughboss_growth_(lead_form|party_sizer)/.test(html);
    note(`${raw ? "GAP" : "ok"}: with the flags off the page ${raw ? "prints the raw shortcode text (the module is not started while its flags are off; a registry gap, see the hand-off)" : "prints no raw shortcode text"}`);
    record("A flags off: no first-party console or HTTP errors", errors.length === 0, errors.slice(0, 3).join(" | "));
    await context.close();
  }

  /* ---------- B: flags on ---------- */
  const saved = await saveSettings(adminContext, {
    "dbgr[features][lead_form]": "1",
    "dbgr[features][party_sizer]": "1",
    "dbgr[sender_legal_name]": SENDER,
    "dbgr[privacy_policy_url]": "/privacy-policy/",
  });
  h = await health(adminContext);
  record("B settings saved: lead_form and party_sizer effective, the leads module active, storage ready", saved.status === 302 && h.flags.lead_form === true && h.flags.party_sizer === true && h.modules_active.leads === true && h.storage_ready === true, JSON.stringify({ flags: [h.flags.lead_form, h.flags.party_sizer], active: h.modules_active.leads, storage: h.storage_ready }));
  let tab = await leadsTab(adminContext);
  record("B the Leads tab renders with zero lead records, the opt-in wording version and the [CONFIRM] gaps", tab.status === 200 && tab.count === 0 && /Shown \(wording version ld-[0-9a-f]{12}\)/.test(tab.html) && /CONFIRM: pieces-per-guest guidance/.test(tab.html) && /CONFIRM: the form uses core/.test(tab.html), `records ${tab.count}`);
  const baseline = await coreEnquiries(adminContext);
  const baseCount = baseline.length;

  let version = "";
  {
    const { context, page, errors } = await visit(browser, testPage.link);
    const form = page.locator("form[data-dbgr-lead-form]");
    record("B the lead form is on the page for a visitor", (await form.count()) === 1 && (await form.isVisible()));
    const facts = await page.evaluate(() => {
      const f = document.querySelector("form[data-dbgr-lead-form]");
      const box = f.querySelector('[name="dbgr_consent_marketing"]');
      return {
        segment: f.getAttribute("data-segment"),
        landing: f.getAttribute("data-landing"),
        ticked: box ? box.checked : null,
        boxRequired: box ? box.required : null,
        version: (f.querySelector('[name="dbgr_consent_text_version"]') || {}).value || "",
        label: box ? box.closest("label").textContent.trim() : "",
        shops: Array.from(f.querySelectorAll('[name="location_id"] option')).map((o) => o.textContent.trim()),
        packages: Array.from(f.querySelectorAll('[name="package_id"] option')).map((o) => o.textContent.trim()),
        text: f.textContent,
        cfg: window.DoughBossGrowthLeads ? Object.keys(window.DoughBossGrowthLeads) : [],
      };
    });
    version = facts.version;
    record("B the marketing box is UNTICKED and optional, and names the sender", facts.ticked === false && facts.boxRequired === false && facts.label.includes(SENDER) && /withdraw/.test(facts.label), facts.label);
    record("B the form is the corporate segment with the matching landing key", facts.segment === "corporate" && facts.landing === "catering-corporate");
    record("B the package choice lists the three real packages and 'Not sure yet', not the draft or the unpriced one", facts.packages.join("|") === "Not sure yet|Test Box One|Test Box Two|Test Box Three", facts.packages.join("|"));
    record("B the shop choice comes from core", facts.shops.includes("Revesby"), facts.shops.join("|"));
    record("B no product word and no currency symbol anywhere in the form text", !/mini/i.test(facts.text) && !/\$/.test(facts.text));
    record("B the browser configuration holds the core enquiry route and a nonce, no secret", facts.cfg.sort().join(",") === "enquiryUrl,maxGuests,nonce,strings", facts.cfg.join(","));
    await form.screenshot({ path: path.join(SHOTS, "wp07-form-desktop.png") });
    await runAxe(page, "lead form, desktop", "form[data-dbgr-lead-form]");
    record("B no first-party console or HTTP errors on the form page", errors.length === 0, errors.slice(0, 3).join(" | "));
    await context.close();
    const mobile = await visit(browser, testPage.link, { mobile: true });
    const overflow = await mobile.page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    record("B mobile: the form does not overflow the viewport", !overflow);
    await mobile.page.locator("form[data-dbgr-lead-form]").screenshot({ path: path.join(SHOTS, "wp07-form-mobile.png") });
    await runAxe(mobile.page, "lead form, mobile", "form[data-dbgr-lead-form]");
    await mobile.context.close();
  }

  /* ---------- C: consent ticked, real core enquiry ---------- */
  const fillForm = async (page, extra = {}) => {
    const form = page.locator("form[data-dbgr-lead-form]");
    await form.locator('[name="customer_name"]').fill("WP07 Browser Check");
    await form.locator('[name="dbgr_company"]').fill("Acme Test Pty Ltd");
    await form.locator('[name="customer_email"]').fill(extra.email || "wp07-ticked@example.com");
    await form.locator('[name="customer_phone"]').fill("0400 000 000");
    await form.locator('[name="guest_count"]').fill("30");
    if (extra.date) await form.locator('[name="event_date"]').fill(extra.date);
    await form.locator('[name="location_id"]').selectOption({ label: "Revesby" });
    if (extra.tick) await form.locator('[name="dbgr_consent_marketing"]').check();
  };
  {
    const { context, page, errors } = await visit(browser, testPage.link);
    await fillForm(page, { tick: true, email: "wp07-ticked@example.com" });
    const posted = page.waitForRequest((r) => /catering(\/|%2F)enquiry/.test(r.url()) && r.method() === "POST");
    await page.locator("form[data-dbgr-lead-form] button[type=submit]").click();
    const request = await posted;
    const payload = JSON.parse(request.postData());
    record("C the request goes to core's enquiry route with core's nonce header and core's field names", /doughboss(\/|%2F)v1(\/|%2F)catering(\/|%2F)enquiry/.test(request.url()) && !!request.headers()["x-wp-nonce"] && ["customer_name", "customer_email", "guest_count", "order_type", "location_id", "hp"].every((k) => k in payload), request.url().replace(BASE, ""));
    record("C the payload carries dbgr_company, dbgr_segment=corporate, the landing key and the consent pair", payload.dbgr_company === "Acme Test Pty Ltd" && payload.dbgr_segment === "corporate" && payload.dbgr_landing_key === "catering-corporate" && payload.dbgr_consent_marketing === "1" && payload.dbgr_consent_text_version === version, JSON.stringify({ seg: payload.dbgr_segment, v: payload.dbgr_consent_text_version }));
    await page.waitForFunction(() => /Thank you/.test(document.querySelector("[data-dbgr-lead-status]").textContent), null, { timeout: 30000 });
    const status = (await page.locator("[data-dbgr-lead-status]").innerText()).trim();
    const number = (status.match(/enquiry number is ([A-Za-z0-9-]+)\./) || [])[1];
    record("C success is shown only after core answered, with core's enquiry number", !!number, status);
    const tracked = await page.evaluate(() => window.__tracked);
    record("C generate_lead is sent with form, category, guest_band and store, and nothing personal", tracked.length === 1 && tracked[0].name === "generate_lead" && JSON.stringify(tracked[0].params) === JSON.stringify({ form: "catering_enquiry", category: "corporate", guest_band: "25-49", store: "revesby" }) && !/@|Acme|WP07|E\d/.test(JSON.stringify(tracked)), JSON.stringify(tracked));
    record("C the form is hidden and the typed personal data is cleared after success", (await page.locator("[data-dbgr-lead-fields]").isHidden()) && (await page.locator('[name="customer_email"]').inputValue()) === "");
    const after = await coreEnquiries(adminContext);
    const created = after.filter((e) => !baseline.some((b) => b.id === e.id));
    record("C core holds exactly one new enquiry, for 30 guests at the chosen shop", after.length === baseCount + 1 && created.length === 1 && Number(created[0].guest_count) === 30 && String(created[0].enquiry_number) === number, JSON.stringify(created.map((e) => ({ n: e.enquiry_number, g: e.guest_count }))));
    tab = await leadsTab(adminContext);
    const row = tab.rows[0];
    record("C exactly one lead record: segment corporate, company, marketing consent Yes with the wording version, linked to that enquiry", tab.count === 1 && row && row.segment === "corporate" && row.company === "Acme Test Pty Ltd" && row.consent === "Yes" && row.version === version && String(row.enquiry) === String(created[0] && created[0].id) && /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(row.recorded), JSON.stringify(row));
    record("C the Leads tab shows no email address", !/wp07-ticked@example\.com/.test(tab.html));
    record("C no first-party console or HTTP errors in the submit flow", errors.length === 0, errors.slice(0, 3).join(" | "));
    await context.close();
  }

  /* ---------- D: consent not ticked ---------- */
  {
    const { context, page } = await visit(browser, testPage.link);
    await fillForm(page, { tick: false, email: "wp07-unticked@example.com" });
    const posted = page.waitForRequest((r) => /catering(\/|%2F)enquiry/.test(r.url()) && r.method() === "POST");
    await page.locator("form[data-dbgr-lead-form] button[type=submit]").click();
    const payload = JSON.parse((await posted).postData());
    record("D unticked: the request carries no consent keys at all", !("dbgr_consent_marketing" in payload) && !("dbgr_consent_text_version" in payload));
    await page.waitForFunction(() => /Thank you/.test(document.querySelector("[data-dbgr-lead-status]").textContent), null, { timeout: 30000 });
    tab = await leadsTab(adminContext);
    const row = tab.rows[0];
    record("D a second lead record exists with marketing consent No and no wording version", tab.count === 2 && row && row.consent === "No" && row.version === "" && row.segment === "corporate", JSON.stringify(row));
    await context.close();
  }

  /* ---------- E: rejected by core => no lead record ---------- */
  {
    const beforeCore = (await coreEnquiries(adminContext)).length;
    const beforeLeads = (await leadsTab(adminContext)).count;
    const { context, page, errors } = await visit(browser, testPage.link);
    // E1: a stale nonce (the tab was left open too long) is refused by core with 403 and the visitor is told to refresh.
    await page.evaluate(() => { window.DoughBossGrowthLeads.nonce = "0123456789"; });
    await fillForm(page, { tick: true, email: "wp07-stale@example.com" });
    const stale = page.waitForResponse((r) => /catering(\/|%2F)enquiry/.test(r.url()) && r.request().method() === "POST");
    await page.locator("form[data-dbgr-lead-form] button[type=submit]").click();
    const staleStatus = (await stale).status();
    await page.waitForFunction(() => /expired/.test(document.querySelector("[data-dbgr-lead-status]").textContent), null, { timeout: 15000 });
    record("E1 a stale nonce: core answers 403, the visitor is told to refresh, no generate_lead is sent, the typed values are kept", staleStatus === 403 && (await page.evaluate(() => window.__tracked.length)) === 0 && (await page.locator('[name="customer_email"]').inputValue()) === "wp07-stale@example.com", `HTTP ${staleStatus}`);
    await context.close();

    // E2: a filled honeypot. Core answers 200 with an EMPTY enquiry number and saves nothing; the form must not count it as a lead.
    {
      const bot = await visit(browser, testPage.link);
      await fillForm(bot.page, { tick: true, email: "wp07-bot@example.com" });
      await bot.page.evaluate(() => { document.querySelector('form[data-dbgr-lead-form] [name="hp"]').value = "i am a bot"; });
      const hp = bot.page.waitForResponse((r) => /catering(\/|%2F)enquiry/.test(r.url()) && r.request().method() === "POST");
      await bot.page.locator("form[data-dbgr-lead-form] button[type=submit]").click();
      const response = await hp;
      const body = await response.json();
      await bot.page.waitForFunction(() => /Thank you/.test(document.querySelector("[data-dbgr-lead-status]").textContent), null, { timeout: 15000 });
      record("E2 a filled honeypot: core answers 200 with no enquiry number, and the form sends no generate_lead", response.status() === 200 && body.enquiry_number === "" && (await bot.page.evaluate(() => window.__tracked.length)) === 0, `HTTP ${response.status()}, number ${JSON.stringify(body.enquiry_number)}`);
      await bot.context.close();
    }

    // E3: a past event date. Core validates and answers 400; its message is shown.
    {
      const past = await visit(browser, testPage.link);
      await fillForm(past.page, { tick: true, email: "wp07-past@example.com", date: "2020-01-01" });
      const r = past.page.waitForResponse((x) => /catering(\/|%2F)enquiry/.test(x.url()) && x.request().method() === "POST");
      await past.page.locator("form[data-dbgr-lead-form] button[type=submit]").click();
      const response = await r;
      await past.page.waitForFunction(() => /past/i.test(document.querySelector("[data-dbgr-lead-status]").textContent), null, { timeout: 15000 });
      const shown = (await past.page.locator("[data-dbgr-lead-status]").innerText()).trim();
      record("E3 a past event date: core answers 400, its own message is shown, no generate_lead, the form stays open", response.status() === 400 && /past/i.test(shown) && (await past.page.evaluate(() => window.__tracked.length)) === 0 && (await past.page.locator("[data-dbgr-lead-fields]").isVisible()), `HTTP ${response.status()}: ${shown}`);
      await past.context.close();
    }

    const afterCore = (await coreEnquiries(adminContext)).length;
    const afterLeads = (await leadsTab(adminContext)).count;
    record("E the four rejected or silent submissions left core's enquiries and the lead records exactly as they were", afterCore === beforeCore && afterLeads === beforeLeads, `core ${beforeCore} -> ${afterCore}, leads ${beforeLeads} -> ${afterLeads}`);
    record("E no first-party console or HTTP errors other than the expected enquiry refusals", errors.length === 0, errors.slice(0, 3).join(" | "));
  }

  /* ---------- F: the sizer ---------- */
  {
    const { context, page, errors } = await visit(browser, testPage.link);
    const quoteFor = async (id, guests) => (await (await adminContext.request.get(REST("/doughboss/v1/catering/quote") + `&package_id=${id}&guest_count=${guests}&order_type=pickup`)).json());
    const byName = Object.fromEntries(corePackages.map((p) => [p.name, p]));
    const sizer = page.locator("[data-dbgr-sizer]");
    record("F the sizer is on the page and ships no price in its markup or configuration", (await sizer.count()) === 1 && !/\$\d/.test(await sizer.innerHTML()) && !/"price"/.test(JSON.stringify(await page.evaluate(() => window.DoughBossGrowthSizer))));
    record("F the sizer configuration lists only the three real packages", (await page.evaluate(() => window.DoughBossGrowthSizer.packages.map((p) => p.name))).join("|") === "Test Box One|Test Box Two|Test Box Three");
    await runAxe(page, "sizer, desktop", "[data-dbgr-sizer]");

    const ask = async (n) => {
      await sizer.locator('input[name="guests"]').fill(String(n));
      await sizer.locator('button[type="submit"]').click();
      await page.waitForFunction(() => {
        const t = document.querySelector("[data-dbgr-sizer-result]").textContent;
        return t !== "" && !/Checking the current price/.test(t);
      }, null, { timeout: 20000 });
      return page.evaluate(() => ({
        cards: Array.from(document.querySelectorAll(".dbgr-sizer__card")).map((c) => ({
          label: c.querySelector(".dbgr-sizer__label").textContent,
          name: c.querySelector(".dbgr-sizer__name").textContent,
          serves: c.querySelector(".dbgr-sizer__serves").textContent,
          price: c.querySelector(".dbgr-sizer__price").textContent,
        })),
        text: document.querySelector("[data-dbgr-sizer-result]").textContent,
        guidance: document.querySelectorAll(".dbgr-sizer__guidance").length,
      }));
    };
    const expectations = [
      { guests: 12, first: "Test Box One", next: "Test Box Two" },
      { guests: 13, first: "Test Box Two", next: "Test Box Three" }, // 13 is above One's range, so One is never offered
      { guests: 20, first: "Test Box Two", next: "Test Box Three" },
      { guests: 26, first: "Test Box Three", next: null },
      { guests: 41, first: "Test Box Three", next: null },
    ];
    for (const e of expectations) {
      const out = await ask(e.guests);
      const wantFirst = byName[e.first];
      const quoteFirst = await quoteFor(wantFirst.id, e.guests);
      const shownFirst = out.cards[0];
      const money = (n) => "$" + Number(n).toFixed(2);
      const okFirst = shownFirst && shownFirst.name === e.first && shownFirst.price === money(quoteFirst.total) && shownFirst.serves.includes(String(wantFirst.serves_max));
      let okNext = true;
      if (e.next) {
        const quoteNext = await quoteFor(byName[e.next].id, e.guests);
        okNext = out.cards.length === 2 && out.cards[1].name === e.next && out.cards[1].price === money(quoteNext.total);
      } else {
        okNext = out.cards.length === 1;
      }
      record(`F ${e.guests} guests: suggested ${e.first}${e.next ? ", next size up " + e.next : ", nothing larger"}; every price equals core's own quote`, okFirst && okNext, JSON.stringify(out.cards.map((c) => [c.name, c.serves, c.price])));
      const allowed = new Set([String(e.guests)]);
      for (const p of corePackages) {
        allowed.add(String(p.serves_min));
        allowed.add(String(p.serves_max));
      }
      for (const name of [e.first, e.next]) {
        if (name) allowed.add(Number((await quoteFor(byName[name].id, e.guests)).total).toFixed(2));
      }
      const numbers = (out.text.match(/[0-9]+(?:\.[0-9]+)?/g) || []);
      record(`F ${e.guests} guests: every number on screen is the head count, a core serve range or a core quote total`, numbers.every((n) => allowed.has(n)), numbers.join(", "));
      record(`F ${e.guests} guests: never an unpriced or draft package, and no pieces-per-guest guidance`, !/No Price|Draft/.test(out.text) && out.guidance === 0);
    }
    const over = await ask(1001);
    record("F over the limit: refused with no price", /send an enquiry/.test(over.text) && over.cards.length === 0 && !/\$/.test(over.text), over.text);
    const bad = await ask(0);
    record("F zero guests: refused with no price", over.cards.length === 0 && /whole number/.test(bad.text) && !/\$/.test(bad.text), bad.text);
    await ask(20);
    await sizer.screenshot({ path: path.join(SHOTS, "wp07-sizer-desktop.png") });
    record("F no product word on the page", !/mini/i.test(await page.locator("main, body").first().innerText()));
    record("F no first-party console or HTTP errors in the sizer flow", errors.length === 0, errors.slice(0, 3).join(" | "));
    await context.close();
    const mobile = await visit(browser, testPage.link, { mobile: true });
    await mobile.page.locator('[data-dbgr-sizer] input[name="guests"]').fill("20");
    await mobile.page.locator('[data-dbgr-sizer] button[type="submit"]').click();
    await mobile.page.waitForSelector(".dbgr-sizer__card", { timeout: 20000 });
    record("F mobile: the sizer result does not overflow the viewport", !(await mobile.page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)));
    await runAxe(mobile.page, "sizer with a result, mobile", "[data-dbgr-sizer]");
    await mobile.context.close();
  }

  /* ---------- G: cleanup ---------- */
  await saveSettings(adminContext, {});
  h = await health(adminContext);
  record("G cleanup: every flag is off again and the leads module is inactive", Object.values(h.flags_configured).every((v) => v === false) && h.modules_active.leads === false);
  if (testPage) {
    const { context, page } = await visit(browser, testPage.link);
    record("G cleanup: with the flags off the page carries no form and no companion asset again", !/data-dbgr-lead-form|data-dbgr-sizer|DoughBossGrowthLeads|DoughBossGrowthSizer/.test(await page.content()));
    await context.close();
    await deletePage(adminContext, testPage.id);
    testPage = null;
  }
  await adminContext.close();
} catch (error) {
  record("script completed without an exception", false, String(error && error.stack ? error.stack : error).split("\n").slice(0, 4).join(" | "));
} finally {
  try {
    if (adminContext && testPage) await deletePage(adminContext, testPage.id);
  } catch {
    /* best effort */
  }
  await browser.close();
}

fs.writeFileSync(path.join(SHOTS, "wp07-checks.json"), JSON.stringify(checks, null, 2));
const failed = checks.filter((c) => !c.ok);
process.stdout.write(`\n${checks.length - failed.length} of ${checks.length} checks passed\n`);
process.exit(failed.length === 0 ? 0 : 1);
