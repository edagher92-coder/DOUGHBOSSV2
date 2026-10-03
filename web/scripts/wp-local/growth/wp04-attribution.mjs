// WP-04 browser check: attribution capture and side records on the local WordPress runtime (companion mounted next to
// DoughBoss; see scripts/wp-local/start.sh). It drives the REAL core catering form and reads the result back from the
// companion's own admin tab ("Attribution": counts and the latest lead records), so nothing is read from a database
// directly.
//
//   WPL_URL=http://127.0.0.1:9404 node scripts/wp-local/growth/wp04-attribution.mjs      (run from web/)
//
// Phases:
//   A  every flag off                       -> a campaign landing writes no cookie, the page carries no companion asset
//   B  attribution + consent banner on      -> before a choice no dbgr_attr cookie; Accept writes it (utm_source=test, gclid=x,
//                                              90 days, SameSite=Lax, <= 1.5 KB); the core catering form is submitted;
//                                              the admin tab shows ONE new lead row with source "test" and an ad click id kept;
//                                              a later landing without parameters keeps the first touch
//   C  consent rejected                     -> no cookie; the same submission makes a lead row with no source data
//   D  measurement only (Choose)            -> utm kept, click id not
//   E  withdrawal                           -> Privacy choices, Reject removes the cookie
//   F  honeypot filled                      -> core answers silently without saving (empty enquiry number); the companion stores no new row
//   G  a malformed dbgr_attr cookie planted by hand -> the enquiry still succeeds and its row holds no source data
// Run it against a freshly started runtime (core limits enquiries per visitor, so a second run within minutes is refused by core).
// The script leaves the runtime with every flag OFF again. Third-party hosts are never contacted (nothing here loads one).
import { chromium, devices } from "@playwright/test";

const BASE = (process.env.WPL_URL || "http://127.0.0.1:9404").replace(/\/$/, "");
const ORIGIN = new URL(BASE).origin;
const CAMPAIGN = "/catering/?utm_source=test&gclid=x";

const checks = [];
function record(name, ok, detail) {
  checks.push({ name, ok: !!ok, detail: detail || "" });
  process.stdout.write(`${ok ? "PASS" : "FAIL"}  ${name}${detail ? "  (" + detail + ")" : ""}\n`);
}

async function newContext(browser) {
  return browser.newContext({ ...devices["Desktop Chrome"], viewport: { width: 1280, height: 900 } });
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

/** The Attribution tab, as data: counts per subject type, the lead record count and the latest lead rows. */
async function attributionTab(context) {
  const response = await context.request.get(`${BASE}/wp-admin/admin.php?page=doughboss-growth&tab=attribution`);
  const html = await response.text();
  const text = (s) => s.replace(/<[^>]*>/g, "").replace(/&amp;/g, "&").replace(/\s+/g, " ").trim();
  const counts = {};
  for (const m of html.matchAll(/<th scope="row">([^<]+)<\/th><td>([^<]*)<\/td>/g)) {
    counts[m[1]] = m[2];
  }
  const rows = [];
  const body = html.split("Latest lead records")[1] || "";
  for (const tr of body.split("<tr>").slice(2)) {
    const cells = [...tr.matchAll(/<td>([^<]*)<\/td>/g)].map((c) => text(c[1]));
    if (cells.length === 7) {
      rows.push({ enquiry: cells[0], segment: cells[1], source: cells[2], medium: cells[3], campaign: cells[4], clickId: cells[5], recorded: cells[6] });
    }
  }
  return { status: response.status(), html, counts, rows };
}

const cookieNamed = async (context, name) => (await context.cookies()).find((c) => c.name === name);
const parseAttr = (cookie) => (cookie ? JSON.parse(decodeURIComponent(cookie.value)) : null);

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

/** Fill and submit core's catering form, wait for the success box. Returns the reference text. */
async function submitCateringForm(page, email) {
  await page.waitForSelector(".dbc-form", { timeout: 30000 });
  const select = page.locator('.dbc-form [name="location_id"]');
  await page.waitForFunction(() => {
    const s = document.querySelector('.dbc-form [name="location_id"]');
    return s && !s.disabled && s.options.length > 1;
  }, null, { timeout: 30000 });
  const firstShop = await select.locator("option:not([value=''])").first().getAttribute("value");
  await select.selectOption(firstShop);
  await page.fill('.dbc-form [name="customer_name"]', "WP04 Browser Check");
  await page.fill('.dbc-form [name="customer_email"]', email);
  await page.fill('.dbc-form [name="guest_count"]', "20");
  await page.click(".dbc-submit");
  await page.waitForSelector(".dbc-success, .dbc-error:not(:empty)", { timeout: 30000 });
  if (await page.locator(".dbc-success").count()) {
    return (await page.locator(".dbc-success-num").innerText()).trim();
  }
  throw new Error("the core form reported an error: " + (await page.locator(".dbc-error").first().innerText()));
}

const browser = await chromium.launch();
try {
  const adminContext = await newContext(browser);
  await adminLogin(adminContext);
  let h = await health(adminContext);
  record("starting state: every flag is off on the runtime", Object.values(h.flags_configured).every((v) => v === false), `core ${h.core_version}, companion ${h.plugin_version}`);

  /* ---------- A: flags off ---------- */
  {
    const context = await newContext(browser);
    const page = await context.newPage();
    const errors = [];
    collectErrors(page, errors);
    await page.goto(BASE + CAMPAIGN, { waitUntil: "load" });
    await page.waitForTimeout(500);
    record("A flags off: no dbgr_attr cookie after a campaign landing", !(await cookieNamed(context, "dbgr_attr")));
    record("A flags off: the page source mentions no companion asset", !/dbgr-|DoughBossGrowth|doughboss-growth/.test(await page.content()));
    record("A flags off: no first-party console or HTTP errors", errors.length === 0, errors.slice(0, 3).join(" | "));
    await context.close();
  }

  /* ---------- B: attribution + consent banner ---------- */
  const saved = await saveSettings(adminContext, {
    "dbgr[features][consent_banner]": "1",
    "dbgr[features][attribution]": "1",
    "dbgr[privacy_policy_url]": "/privacy-policy/",
    "dbgr[consent_text_version]": "1",
    "dbgr[consent_default]": "deny",
  });
  h = await health(adminContext);
  record("B settings saved: attribution and consent_banner effective", saved.status === 302 && h.flags.attribution === true && h.flags.consent_banner === true, saved.location.replace(BASE, ""));
  record("B the attribution module is active in /health and its storage is ready", h.modules_active.attribution === true && h.storage_ready === true, `storage_ready=${h.storage_ready}`);
  let tab = await attributionTab(adminContext);
  record("B the Attribution admin tab renders (on a fresh runtime it starts with zero lead records)", tab.status === 200 && tab.counts["Attribution"] === "On" && tab.counts["Storage"] === "Ready", JSON.stringify(tab.counts));
  record("B the tab lists the [CONFIRM] gaps (retention among them)", /\[CONFIRM: how long attribution and lead records are kept/.test(tab.html));
  const baselineLeads = Number(tab.counts["Lead records"]);
  const baselineEnquiryRows = Number(tab.counts["Attribution rows: enquiry"]);

  const accepted = await newContext(browser);
  {
    const page = await accepted.newPage();
    const errors = [];
    collectErrors(page, errors);
    await page.goto(BASE + CAMPAIGN, { waitUntil: "load" });
    await page.waitForSelector("#dbgr-consent:not([hidden])", { timeout: 15000 });
    record("B first visit: the banner is shown and the attribution script is on the page", (await page.locator("#dbgr-consent").isVisible()) && (await page.evaluate(() => typeof window.DoughBossGrowth.attribution === "object")));
    record("B before a choice: no dbgr_attr cookie", !(await cookieNamed(accepted, "dbgr_attr")));
    await page.click('[data-dbgr-action="accept"]');
    await page.waitForTimeout(400);
    const cookie = await cookieNamed(accepted, "dbgr_attr");
    const attr = parseAttr(cookie);
    record("B after Accept all: dbgr_attr holds utm_source=test and gclid=x", attr && attr.utmSource === "test" && attr.gclid === "x", JSON.stringify(attr));
    record("B the cookie also holds the landing path (no query string) and a first-seen time, and no referrer for a direct visit", attr && attr.landingPath === "/catering/" && /^\d{4}-\d\d-\d\dT/.test(attr.firstSeenAt) && !("referrerHost" in attr), JSON.stringify(attr));
    const days = cookie ? (cookie.expires - Date.now() / 1000) / 86400 : 0;
    record("B cookie attributes: about 90 days, SameSite=Lax, path /, within 1.5 KB", cookie && days > 89 && days < 91 && cookie.sameSite === "Lax" && cookie.path === "/" && cookie.value.length <= 1500, cookie ? `${days.toFixed(1)} days, ${cookie.sameSite}, ${cookie.value.length} chars` : "no cookie");

    const reference = await submitCateringForm(page, "wp04-accepted@example.com");
    record("B the core catering form accepted the enquiry", /E/i.test(reference) || reference.length > 3, reference);
    tab = await attributionTab(adminContext);
    const row = tab.rows[0];
    record("B exactly one new lead record exists, with utm_source=test and an ad click id kept", Number(tab.counts["Lead records"]) === baselineLeads + 1 && row && row.source === "test" && row.clickId === "Yes", JSON.stringify(row));
    record("B an enquiry attribution row was written", Number(tab.counts["Attribution rows: enquiry"]) === baselineEnquiryRows + 1, tab.counts["Attribution rows: enquiry"]);
    record("B the tab shows no email address", !/wp04-accepted@example\.com/.test(tab.html));

    // A later landing without campaign parameters keeps the first touch.
    await page.goto(BASE + "/menu/", { waitUntil: "load" });
    await page.waitForTimeout(300);
    const after = parseAttr(await cookieNamed(accepted, "dbgr_attr"));
    record("B first touch wins: a later page without parameters leaves the cookie as it was", after && after.utmSource === "test" && after.landingPath === "/catering/", JSON.stringify(after));
    record("B no first-party console or HTTP errors in the accepted flow", errors.length === 0, errors.slice(0, 3).join(" | "));
  }

  /* ---------- E: withdrawal (continuing the accepted context) ---------- */
  {
    const page = await accepted.newPage();
    await page.goto(BASE + "/", { waitUntil: "load" });
    await page.waitForSelector("#dbgr-consent-reopen:not([hidden])", { timeout: 15000 });
    await page.click("#dbgr-consent-reopen");
    await page.waitForSelector("#dbgr-consent:not([hidden])");
    if (await page.locator("#dbgr-consent-panel").isHidden()) {
      await page.click('[data-dbgr-action="choose"]');
    }
    await page.uncheck("#dbgr-consent-a");
    await page.click('[data-dbgr-action="save"]');
    await page.waitForTimeout(400);
    const partial = parseAttr(await cookieNamed(accepted, "dbgr_attr"));
    record("E withdrawing advertising consent removes the click id and keeps the measurement fields", partial && !("gclid" in partial) && partial.utmSource === "test", JSON.stringify(partial));
    await page.click("#dbgr-consent-reopen");
    await page.waitForSelector("#dbgr-consent:not([hidden])");
    await page.click('[data-dbgr-action="reject"]');
    await page.waitForTimeout(400);
    record("E Reject all removes the dbgr_attr cookie", !(await cookieNamed(accepted, "dbgr_attr")));
    await page.close();
  }
  await accepted.close();

  /* ---------- C: consent rejected ---------- */
  {
    const context = await newContext(browser);
    const page = await context.newPage();
    const errors = [];
    collectErrors(page, errors);
    await page.goto(BASE + CAMPAIGN, { waitUntil: "load" });
    await page.waitForSelector("#dbgr-consent:not([hidden])", { timeout: 15000 });
    await page.click('[data-dbgr-action="reject"]');
    await page.waitForTimeout(400);
    record("C after Reject all: no dbgr_attr cookie", !(await cookieNamed(context, "dbgr_attr")));
    await submitCateringForm(page, "wp04-rejected@example.com");
    tab = await attributionTab(adminContext);
    const row = tab.rows[0];
    record("C the enquiry still went through and its lead record holds no source data", Number(tab.counts["Lead records"]) === baselineLeads + 2 && row && row.source === "" && row.medium === "" && row.campaign === "" && row.clickId === "No", JSON.stringify(row));
    record("C no enquiry attribution row was added for the rejected visitor", Number(tab.counts["Attribution rows: enquiry"]) === baselineEnquiryRows + 1, tab.counts["Attribution rows: enquiry"]);
    record("C no first-party console or HTTP errors in the rejected flow", errors.length === 0, errors.slice(0, 3).join(" | "));
    await context.close();
  }

  /* ---------- D: measurement only ---------- */
  {
    const context = await newContext(browser);
    const page = await context.newPage();
    await page.goto(BASE + CAMPAIGN, { waitUntil: "load" });
    await page.waitForSelector("#dbgr-consent:not([hidden])", { timeout: 15000 });
    await page.click('[data-dbgr-action="choose"]');
    await page.check("#dbgr-consent-m");
    await page.click('[data-dbgr-action="save"]');
    await page.waitForTimeout(400);
    const attr = parseAttr(await cookieNamed(context, "dbgr_attr"));
    record("D measurement only: utm_source is kept, the click id is not", attr && attr.utmSource === "test" && !("gclid" in attr), JSON.stringify(attr));
    await submitCateringForm(page, "wp04-measure@example.com");
    tab = await attributionTab(adminContext);
    const row = tab.rows[0];
    record("D the lead record has the source but no ad click id", row && row.source === "test" && row.clickId === "No", JSON.stringify(row));
    await context.close();
  }

  /* ---------- F: core rejects the enquiry (honeypot) ---------- */
  {
    const before = Number((await attributionTab(adminContext)).counts["Lead records"]);
    const context = await newContext(browser);
    const page = await context.newPage();
    await page.goto(BASE + CAMPAIGN, { waitUntil: "load" });
    await page.waitForSelector("#dbgr-consent:not([hidden])", { timeout: 15000 });
    await page.click('[data-dbgr-action="accept"]');
    await page.waitForTimeout(300);
    const result = await page.evaluate(async () => {
      const DB = window.DoughBossData || {};
      const url = DB.restUrl ? DB.restUrl.replace(/\/$/, "") + "/catering/enquiry" : "/?rest_route=/doughboss/v1/catering/enquiry";
      const r = await fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-WP-Nonce": DB.nonce || "" },
        body: JSON.stringify({ customer_name: "Bot", customer_email: "wp04-bot@example.com", location_id: 1, hp: "i am a bot" }),
      });
      let body = null;
      try { body = await r.json(); } catch { body = null; }
      return { status: r.status, success: !!(body && body.success), number: body && typeof body.enquiry_number === "string" ? body.enquiry_number : null };
    });
    const after = Number((await attributionTab(adminContext)).counts["Lead records"]);
    // Core answers a bot with a normal-looking success but an EMPTY enquiry number and saves nothing (rest-controller.php, honeypot branch), so its
    // action never fires; the companion must therefore store nothing either.
    record("F the honeypot request: core accepted it silently without an enquiry number and the companion stored no new lead record", result.status === 200 && result.number === "" && after === before, `core status ${result.status}, enquiry number ${JSON.stringify(result.number)}, records ${before} -> ${after}`);
    await context.close();
  }

  /* ---------- G: malformed cookie planted by hand ---------- */
  {
    const context = await newContext(browser);
    const page = await context.newPage();
    await page.goto(BASE + "/catering/", { waitUntil: "load" });
    await page.waitForSelector("#dbgr-consent:not([hidden])", { timeout: 15000 });
    await page.click('[data-dbgr-action="accept"]');
    await page.waitForTimeout(300);
    await context.addCookies([{ name: "dbgr_attr", value: encodeURIComponent('{"utmSource":"a\\u0000b","landingPath":"/x?y=1"'), url: BASE }]);
    const before = Number((await attributionTab(adminContext)).counts["Lead records"]);
    await submitCateringForm(page, "wp04-malformed@example.com");
    tab = await attributionTab(adminContext);
    const row = tab.rows[0];
    record("G a malformed dbgr_attr cookie does not break the enquiry; its lead record holds no source data", Number(tab.counts["Lead records"]) === before + 1 && row && row.source === "" && row.clickId === "No", JSON.stringify(row));
    await context.close();
  }

  /* ---------- cleanup: every flag off again ---------- */
  await saveSettings(adminContext, {});
  h = await health(adminContext);
  record("cleanup: every flag is off again", Object.values(h.flags_configured).every((v) => v === false));
  {
    const context = await newContext(browser);
    const page = await context.newPage();
    await page.goto(BASE + CAMPAIGN, { waitUntil: "load" });
    await page.waitForTimeout(400);
    record("cleanup: with the flags off the page is clean again (no cookie, no companion asset)", !(await cookieNamed(context, "dbgr_attr")) && !/dbgr-|DoughBossGrowth/.test(await page.content()));
    await context.close();
  }
  await adminContext.close();
} catch (error) {
  record("script completed without an exception", false, String(error && error.stack ? error.stack : error).split("\n").slice(0, 4).join(" | "));
} finally {
  await browser.close();
}

const failed = checks.filter((c) => !c.ok);
process.stdout.write(`\n${checks.length - failed.length} of ${checks.length} checks passed\n`);
process.exit(failed.length === 0 ? 0 : 1);
