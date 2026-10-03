// WP-08 browser check: server-side conversions and the offline-conversion export on the local WordPress runtime (companion mounted
// next to DoughBoss; see scripts/wp-local/start.sh and WPL_EXTRA_PLUGIN_SRC). It runs inside the REAL WordPress and the REAL core:
// the core catering form creates an enquiry, core's own staff REST route quotes it, and the export is read back from the real
// admin-post download. Nothing is read from a database directly and no request leaves the machine (no GA4 secret or Meta token is
// ever set here, so no outbound conversion call is possible; the only "destination" is a webhook URL that is never called).
//
//   WPL_URL=http://127.0.0.1:9408 node scripts/wp-local/growth/wp08-conversions.mjs      (run from web/)
//
// Phases:
//   A  flags off        -> no Conversions tab, the export action is not served (no CSV)
//   B  flags on         -> consent banner + attribution + server conversions (a webhook URL is the only destination, so GA4 and Meta stay
//                          "not ready"); /health shows the conversions module active; the tab renders with its [CONFIRM] gaps
//   C  site timezone    -> set to Australia/Sydney through the Settings, General screen (restored at the end), because core stores
//                          enquiry times in the site timezone and the export converts them to UTC
//   D  real enquiry     -> a visitor lands with a Google click id, accepts all cookies, submits the REAL core catering form: core accepts
//                          it (the companion hooks never break it) and NOTHING is queued (no GA4 or Meta destination is configured)
//   E  real quote       -> core's staff REST route quotes it (1234.56, pickup); the export (admin-post, nonce) returns a CSV with exactly
//                          one row: the click id, "Catering quote sent", a UTC time with +0000 within a few minutes of now (so the
//                          Sydney to UTC conversion is right), 1234.56, AUD and the enquiry number; paid-only and an old window are empty
//   F  refusals         -> no nonce, a visitor, a bad stage and bad dates download nothing
//   G  cleanup          -> every flag off, timezone restored
// Not covered here (covered by the PHP suite): a paid order or a paid catering leg, because payments are off on the local runtime.
// Core limits catering enquiries per visitor, so run this against a freshly started runtime.
import { chromium, devices } from "@playwright/test";

const BASE = (process.env.WPL_URL || "http://127.0.0.1:9408").replace(/\/$/, "");
const ORIGIN = new URL(BASE).origin;
const CLICK_ID = "Cj0KCQjwWP08BROWSERCHECK1234";
const CAMPAIGN = `/catering/?utm_source=test&gclid=${CLICK_ID}`;
const WEBHOOK = "https://hooks.example-receiver.com.au/never-called";

const checks = [];
function record(name, ok, detail) {
  checks.push({ name, ok: !!ok, detail: detail || "" });
  process.stdout.write(`${ok ? "PASS" : "FAIL"}  ${name}${detail ? "  (" + detail + ")" : ""}\n`);
}

const newContext = (browser) => browser.newContext({ ...devices["Desktop Chrome"], viewport: { width: 1280, height: 900 } });

async function adminLogin(context) {
  const page = await context.newPage();
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill("#user_login", "admin");
  await page.fill("#user_pass", "password");
  await Promise.all([page.waitForURL(/wp-admin/), page.click("#wp-submit")]);
  await page.close();
}

const nonceFrom = (html) => (html.match(/name="_wpnonce"[^>]*value="([0-9a-f]+)"/) || html.match(/value="([0-9a-f]+)"[^>]*name="_wpnonce"/) || [])[1];

async function saveSettings(context, fields) {
  const html = await (await context.request.get(`${BASE}/wp-admin/admin.php?page=doughboss-growth`)).text();
  const nonce = nonceFrom(html);
  if (!nonce) throw new Error("no save nonce on the settings page");
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
  const response = await context.request.get(`${BASE}/?rest_route=/doughboss-growth/v1/health`, { headers: { "x-wp-nonce": await restNonce(context) } });
  return response.json();
}

async function conversionsTab(context) {
  const response = await context.request.get(`${BASE}/wp-admin/admin.php?page=doughboss-growth&tab=conversions`);
  return { status: response.status(), html: await response.text() };
}

const exportCsv = async (context, form) => {
  const response = await context.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: "doughboss_growth_export_offline_conversions", ...form }, maxRedirects: 0 });
  return { status: response.status(), type: response.headers()["content-type"] || "", disposition: response.headers()["content-disposition"] || "", body: await response.text() };
};

const csvRows = (body) => body.split("\r\n").filter(Boolean).map((line) => line.split(","));

function collectErrors(page, bucket) {
  page.on("pageerror", (e) => bucket.push("pageerror: " + e.message));
  page.on("console", (m) => {
    if (m.type() !== "error") return;
    const loc = m.location() && m.location().url ? m.location().url : "";
    if (!loc || loc.startsWith(ORIGIN)) bucket.push("console.error: " + m.text() + (loc ? " @ " + loc : ""));
  });
  page.on("response", (r) => {
    if (r.status() >= 400 && r.url().startsWith(ORIGIN) && !/favicon/.test(r.url())) bucket.push("http " + r.status() + ": " + r.url());
  });
}

async function setTimezone(context, zone) {
  const page = await context.newPage();
  await page.goto(`${BASE}/wp-admin/options-general.php`);
  const previous = await page.inputValue("#timezone_string");
  await page.selectOption("#timezone_string", zone);
  await Promise.all([page.waitForURL(/options-general\.php/), page.click("#submit")]);
  const now = await page.inputValue("#timezone_string");
  await page.close();
  return { previous, now };
}

async function submitCateringForm(page, email) {
  await page.waitForSelector(".dbc-form", { timeout: 30000 });
  await page.waitForFunction(() => {
    const s = document.querySelector('.dbc-form [name="location_id"]');
    return s && !s.disabled && s.options.length > 1;
  }, null, { timeout: 30000 });
  const select = page.locator('.dbc-form [name="location_id"]');
  await select.selectOption(await select.locator("option:not([value=''])").first().getAttribute("value"));
  await page.fill('.dbc-form [name="customer_name"]', "WP08 Browser Check");
  await page.fill('.dbc-form [name="customer_email"]', email);
  await page.fill('.dbc-form [name="guest_count"]', "20");
  await page.click(".dbc-submit");
  await page.waitForSelector(".dbc-success, .dbc-error:not(:empty)", { timeout: 30000 });
  if (await page.locator(".dbc-success").count()) return (await page.locator(".dbc-success-num").innerText()).replace(/^\s*Reference:\s*/i, "").trim();
  throw new Error("the core form reported an error: " + (await page.locator(".dbc-error").first().innerText()));
}

const browser = await chromium.launch();
let originalZone = null;
const adminContext = await newContext(browser);
try {
  await adminLogin(adminContext);
  let h = await health(adminContext);
  record("starting state: every flag is off on the runtime", Object.values(h.flags_configured).every((v) => v === false), `core ${h.core_version}, companion ${h.plugin_version}`);

  /* ---------- A: flags off ---------- */
  {
    const tab = await conversionsTab(adminContext);
    record("A flags off: the Growth page has no Conversions tab", tab.status === 200 && !/class="nav-tab[^"]*" href="[^"]*tab=conversions/.test(tab.html) && !/GA4 destination ready/.test(tab.html));
    const refused = await exportCsv(adminContext, { _wpnonce: "0123456789" });
    record("A flags off: the export action is not served (no CSV)", !/text\/csv/.test(refused.type) && !/Google Click ID/.test(refused.body), `HTTP ${refused.status}`);
  }

  /* ---------- B: flags on ---------- */
  const saved = await saveSettings(adminContext, {
    "dbgr[features][consent_banner]": "1",
    "dbgr[features][attribution]": "1",
    "dbgr[features][server_conversions]": "1",
    "dbgr[notify_webhook_url]": WEBHOOK,
    "dbgr[privacy_policy_url]": "/privacy-policy/",
    "dbgr[consent_text_version]": "1",
    "dbgr[consent_default]": "deny",
  });
  h = await health(adminContext);
  record("B settings saved: server_conversions effective (it needs attribution and a destination)", saved.status === 302 && h.flags.server_conversions === true && h.flags.attribution === true, saved.location.replace(BASE, ""));
  record("B the conversions module is active in /health and storage is ready", h.modules_active.conversions === true && h.storage_ready === true, `modules_active.conversions=${h.modules_active.conversions}`);
  record("B only GA4 and Meta channels would register, and neither is configured, so no outbox channel is registered", h.outbox_channels === 0, `outbox_channels=${h.outbox_channels}`);
  let tab = await conversionsTab(adminContext);
  record("B the Conversions tab renders: heading, GA4 and Meta shown as not ready, nothing queued", tab.status === 200 && /Server-side conversions/.test(tab.html) && /GA4 destination ready<\/th><td>No/.test(tab.html) && /Meta destination ready<\/th><td>No/.test(tab.html) && /Nothing has been queued/.test(tab.html));
  record("B the tab lists the [CONFIRM] gaps (privacy policy, GST basis, GA4 validation, Graph API version)", /\[CONFIRM: the privacy policy/.test(tab.html) && /\[CONFIRM: whether order and quote totals/.test(tab.html) && /\[CONFIRM: run a sample event/.test(tab.html) && /Graph API version/.test(tab.html));
  record("B the tab shows the export form with a nonce and no secret", /name="action" value="doughboss_growth_export_offline_conversions"/.test(tab.html) && !!nonceFrom(tab.html) && !/secret value|TESTONLY/i.test(tab.html));

  /* ---------- C: site timezone ---------- */
  const tz = await setTimezone(adminContext, "Australia/Sydney");
  originalZone = tz.previous;
  record("C the site timezone is Australia/Sydney for this check", tz.now === "Australia/Sydney", `was "${tz.previous || "(UTC offset setting)"}"`);

  /* ---------- D: real enquiry ---------- */
  const visitor = await newContext(browser);
  let reference = "";
  {
    const page = await visitor.newPage();
    const errors = [];
    collectErrors(page, errors);
    await page.goto(BASE + CAMPAIGN, { waitUntil: "load" });
    await page.waitForSelector("#dbgr-consent:not([hidden])", { timeout: 15000 });
    await page.click('[data-dbgr-action="accept"]');
    await page.waitForTimeout(400);
    const cookie = (await visitor.cookies()).find((c) => c.name === "dbgr_attr");
    const attr = cookie ? JSON.parse(decodeURIComponent(cookie.value)) : null;
    record("D the visitor accepted all cookies and the click id was captured", attr && attr.gclid === CLICK_ID, JSON.stringify(attr));
    reference = await submitCateringForm(page, "wp08-browser@example.com");
    record("D the REAL core catering form accepted the enquiry with the conversions module on", reference.length > 3, reference);
    record("D no first-party console or HTTP errors during the submission", errors.length === 0, errors.slice(0, 3).join(" | "));
    tab = await conversionsTab(adminContext);
    record("D nothing was queued (no GA4 or Meta destination is configured: fail closed, no outbound call)", /Nothing has been queued/.test(tab.html));
  }

  /* ---------- E: real quote and the export ---------- */
  const nonce = await restNonce(adminContext);
  const headers = { "x-wp-nonce": nonce, "content-type": "application/json" };
  const list = await (await adminContext.request.get(`${BASE}/?rest_route=/doughboss/v1/admin/catering&per_page=50`, { headers })).json();
  const rows = Array.isArray(list.data) ? list.data : Array.isArray(list) ? list : [];
  const mine = rows.find((r) => String(r.enquiry_number || r.number || "") === reference) || rows[0];
  const id = mine ? mine.id : 0;
  record("E core's staff list returns the new enquiry", id > 0, `id=${id} ref=${reference}`);
  const quote = await adminContext.request.post(`${BASE}/?rest_route=/doughboss/v1/admin/catering/${id}/quote`, { headers, data: { subtotal: 1234.56, delivery_fee: 0, deposit_pct: 50 } });
  const quoteBody = await quote.json().catch(() => ({}));
  record("E core's staff route quoted it: 1234.56, status quoted", quote.status() === 200 && quoteBody.status === "quoted" && Number(quoteBody.total) === 1234.56, `HTTP ${quote.status()} ${JSON.stringify(quoteBody).slice(0, 120)}`);

  tab = await conversionsTab(adminContext);
  const exportNonce = nonceFrom(tab.html);
  const csv = await exportCsv(adminContext, { _wpnonce: exportNonce, stage: "both" });
  const parsed = csvRows(csv.body);
  record("E the export is a CSV download with safe headers", csv.status === 200 && /text\/csv/.test(csv.type) && /attachment/.test(csv.disposition), `${csv.status} ${csv.type} ${csv.disposition}`);
  record("E header row is exactly the Google offline-import columns", parsed[0] && parsed[0].join(",") === "Google Click ID,Conversion Name,Conversion Time,Conversion Value,Conversion Currency,Order ID", parsed[0] ? parsed[0].join(",") : "none");
  record("E exactly one data row: the quote (not won: nothing is paid)", parsed.length === 2, `${parsed.length - 1} data rows`);
  const row = parsed[1] || [];
  record("E the row carries the click id, the conversion name, AUD, the value from core and the enquiry number as order id", row[0] === CLICK_ID && row[1] === "Catering quote sent" && row[3] === "1234.56" && row[4] === "AUD" && row[5] === reference, row.join(" | "));
  const match = /^(\d{4})-(\d\d)-(\d\d) (\d\d):(\d\d):(\d\d)\+0000$/.exec(row[2] || "");
  const when = match ? Date.UTC(+match[1], +match[2] - 1, +match[3], +match[4], +match[5], +match[6]) : NaN;
  const drift = Math.abs(Date.now() - when) / 1000;
  record("E the time is UTC with +0000 and within three minutes of now (core stored it in Sydney time, so the conversion is right; a missed conversion would be hours out)", match && drift < 180, `${row[2]} drift ${drift.toFixed(0)}s`);
  const paidOnly = await exportCsv(adminContext, { _wpnonce: exportNonce, stage: "paid" });
  record("E paid only: header only (nothing is paid)", paidOnly.status === 200 && csvRows(paidOnly.body).length === 1);
  const oldWindow = await exportCsv(adminContext, { _wpnonce: exportNonce, stage: "both", from: "2020-01-01", to: "2020-01-02" });
  record("E a window that excludes the quote: header only", oldWindow.status === 200 && csvRows(oldWindow.body).length === 1);
  record("E the file holds no email address, name or secret", !/wp08-browser@example\.com|WP08 Browser Check/.test(csv.body));

  /* ---------- F: refusals ---------- */
  {
    const noNonce = await exportCsv(adminContext, { stage: "both" });
    record("F no nonce: refused, nothing downloaded", noNonce.status === 403 && !/Google Click ID/.test(noNonce.body), `HTTP ${noNonce.status}`);
    const stranger = await newContext(browser);
    const anon = await exportCsv(stranger, { _wpnonce: exportNonce, stage: "both" });
    record("F a visitor (not logged in): refused, nothing downloaded", !/text\/csv/.test(anon.type) && !/Google Click ID/.test(anon.body), `HTTP ${anon.status}`);
    await stranger.close();
    const badStage = await exportCsv(adminContext, { _wpnonce: exportNonce, stage: "everything" });
    record("F a bad stage: 400, nothing downloaded", badStage.status === 400 && !/Google Click ID/.test(badStage.body), `HTTP ${badStage.status}`);
    const badDates = await exportCsv(adminContext, { _wpnonce: exportNonce, stage: "both", from: "2026-13-45" });
    record("F bad dates: 400, nothing downloaded", badDates.status === 400 && !/Google Click ID/.test(badDates.body), `HTTP ${badDates.status}`);
    const reversed = await exportCsv(adminContext, { _wpnonce: exportNonce, stage: "both", from: "2026-10-10", to: "2026-10-01" });
    record("F a reversed window: 400, nothing downloaded", reversed.status === 400, `HTTP ${reversed.status}`);
  }
} catch (error) {
  record("script completed without an exception", false, String(error && error.stack ? error.stack : error).split("\n").slice(0, 3).join(" | "));
} finally {
  /* ---------- G: cleanup ---------- */
  try {
    await saveSettings(adminContext, {});
    const h = await health(adminContext);
    record("G cleanup: every flag is off again", Object.values(h.flags_configured).every((v) => v === false));
    if (originalZone !== null) {
      const back = await setTimezone(adminContext, originalZone);
      record("G cleanup: the site timezone is restored", back.now === originalZone, `"${back.now}"`);
    }
  } catch (error) {
    record("G cleanup completed", false, String(error).slice(0, 200));
  }
  await browser.close();
}

const failed = checks.filter((c) => !c.ok);
process.stdout.write(`\n${checks.length - failed.length} of ${checks.length} checks passed\n`);
process.exit(failed.length === 0 ? 0 : 1);
