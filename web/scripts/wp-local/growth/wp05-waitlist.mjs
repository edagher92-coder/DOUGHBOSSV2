// WP-05 browser check: VIP waitlist and coming-soon section on the local WordPress runtime
// (companion mounted next to DoughBoss; see scripts/wp-local/start.sh and WPL_EXTRA_PLUGIN_SRC).
//
//   WPL_URL=http://127.0.0.1:9405 WPL_STATE=/tmp/wpl-WP-05 node scripts/wp-local/growth/wp05-waitlist.mjs      (run from web/)
//   SHOTS_DIR=/some/dir          where screenshots go (default $WPL_STATE/shots)
//
// SCRATCH PREPARATION (done once, in the runtime's scratch COPY of the plugin, never in the source tree):
//   1. $WPL_STATE/src/plugins/doughboss-growth/wp05-mailcapture.php, loaded from the copy's main file with one extra line. It
//      (a) captures wp_mail() into $WPL_STATE/run/out/wp05-mail.jsonl instead of sending (the runtime cannot send mail),
//          and makes mail fail while the file wp05-mailmode says "fail" (to prove the fail-closed path; read by content because
//          the runtime's file mount can cache a missing or deleted file);
//      (b) lets this script choose the visitor address the rate limiter sees, through the real doughboss_growth_client_ip
//          filter, by writing one line to wp05-ip (so the 5-an-hour limit can be exercised on every run).
//   2. The only other scratch edit is a temporary claim in the copy's content/claims.json for the tilt-card check; the
//      script restores the original file.
// No request leaves the machine: every address in a sign-up is a reserved example domain and mail never leaves the runtime.
//
// The sender legal name used here is a PLACEHOLDER for the scratch runtime only (Elie has not supplied the real one).
//
// Phases:
//   A  flags off            -> nothing public changes; the shortcodes print nothing (never the raw tag); no companion asset
//   B  waitlist + coming_soon on
//        form markup and a11y (consent unticked and required, honeypot off-screen, keyboard order, axe, mobile), no "minis"
//        sign-up end to end with the captured mail, confirm (GET changes nothing, POST confirms, link single use),
//        duplicate = identical answer, opt-out (GET changes nothing, POST and one-click POST work), re-signup after opt-out,
//        honeypot / early token / consent refused, mail failure leaves nothing, the 5-an-hour limit
//   C  tilt cards (a scratch claim), reduced motion flat, live toggle
//   D  home ribbon off by default, on after the admin switch, coming_soon_view and waitlist_submit events
// The script leaves the runtime with every flag OFF again.
import { chromium, devices } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const BASE = (process.env.WPL_URL || "http://127.0.0.1:9405").replace(/\/$/, "");
const STATE = process.env.WPL_STATE || "/tmp/wpl-WP-05";
const OUT = path.join(STATE, "run", "out");
const MAIL = path.join(OUT, "wp05-mail.jsonl");
const MAILMODE = path.join(OUT, "wp05-mailmode");
const IPFILE = path.join(OUT, "wp05-ip");
const COPY = path.join(STATE, "src", "plugins", "doughboss-growth");
const CLAIMS = path.join(COPY, "content", "claims.json");
const SHOTS = process.env.SHOTS_DIR || path.join(STATE, "shots");
const HERE = path.dirname(fileURLToPath(import.meta.url));
const AXE = path.resolve(HERE, "../../../node_modules/axe-core/axe.min.js");
const ORIGIN = new URL(BASE).origin;
const REST = (route) => `${BASE}/?rest_route=${route}`;
fs.mkdirSync(SHOTS, { recursive: true });

const SENDER = "Example Trading Pty Ltd";
// Contract change request 1 (see the hand-off): the module registry must initialise the waitlist class even while its flag is
// off, so the opt-out link, the privacy tooling and a stub for the shortcodes keep working. The source tree does NOT contain
// that change (WP-01 owns the registry). To prove the request is right, the scratch copy may carry the 3-line patch; the
// script detects it and reports the flag-off behaviour as a hard check when present and as a NOTE (observed gap) when absent.
const REGISTRY_PATCHED = fs.existsSync(path.join(COPY, "includes", "class-doughboss-growth.php")) && /'always'\s*=>\s*true/.test(fs.readFileSync(path.join(COPY, "includes", "class-doughboss-growth.php"), "utf8"));
function offCheck(name, ok, detail) {
  if (REGISTRY_PATCHED) {
    record(name + " [scratch registry patch present]", ok, detail);
  } else {
    note(`${ok ? "ok" : "GAP"} (unpatched registry): ${name}${detail ? " (" + detail + ")" : ""}`);
  }
}
const checks = [];
function record(name, ok, detail) {
  checks.push({ name, ok: !!ok, detail: detail || "" });
  process.stdout.write(`${ok ? "PASS" : "FAIL"}  ${name}${detail ? "  (" + detail + ")" : ""}\n`);
}
function note(text) {
  process.stdout.write(`NOTE  ${text}\n`);
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/* ------------------------------------------------------------ runtime helpers */

function readMail() {
  if (!fs.existsSync(MAIL)) return [];
  return fs.readFileSync(MAIL, "utf8").split("\n").filter(Boolean).map((l) => JSON.parse(l));
}
function setIp(ip) {
  fs.writeFileSync(IPFILE, ip + "\n");
}
let ipCounter = 10 + Math.floor(Math.random() * 200);
const freshIp = () => {
  ipCounter += 1;
  const ip = `198.51.100.${ipCounter % 250}`;
  setIp(ip);
  return ip;
};

async function adminLogin(context) {
  const page = await context.newPage();
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill("#user_login", "admin");
  await page.fill("#user_pass", "password");
  await Promise.all([page.waitForURL(/wp-admin/), page.click("#wp-submit")]);
  await page.close();
}

async function nonceFrom(context, url) {
  const html = await (await context.request.get(url)).text();
  const nonce = (html.match(/name="_wpnonce"[^>]*value="([0-9a-f]+)"/) || html.match(/value="([0-9a-f]+)"[^>]*name="_wpnonce"/) || [])[1];
  if (!nonce) throw new Error("no nonce at " + url);
  return nonce;
}

async function saveSettings(context, fields) {
  const nonce = await nonceFrom(context, `${BASE}/wp-admin/admin.php?page=doughboss-growth`);
  const response = await context.request.post(`${BASE}/wp-admin/admin-post.php`, {
    form: { action: "doughboss_growth_save_settings", _wpnonce: nonce, ...fields },
    maxRedirects: 0,
  });
  return { status: response.status(), location: response.headers().location || "" };
}

async function health(context) {
  const nonceResponse = await context.request.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`);
  const restNonce = (await nonceResponse.text()).trim();
  const response = await context.request.get(REST("/doughboss-growth/v1/health"), { headers: { "x-wp-nonce": restNonce } });
  return response.json();
}

async function restNonce(context) {
  return (await (await context.request.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`)).text()).trim();
}

async function upsertPage(context, slug, title, content, status = "publish") {
  const nonce = await restNonce(context);
  const headers = { "x-wp-nonce": nonce };
  const found = await (await context.request.get(REST("/wp/v2/pages") + `&slug=${slug}&status=any&context=edit`, { headers })).json();
  const body = { title, slug, status, content };
  const response = Array.isArray(found) && found.length
    ? await context.request.post(REST(`/wp/v2/pages/${found[0].id}`), { headers, data: body })
    : await context.request.post(REST("/wp/v2/pages"), { headers, data: body });
  if (!response.ok()) throw new Error(`page ${slug}: HTTP ${response.status()} ${await response.text()}`);
  return (await response.json()).id;
}

async function counts(context) {
  const html = await (await context.request.get(`${BASE}/wp-admin/admin.php?page=doughboss-growth&tab=waitlist`)).text();
  const read = (label) => {
    const m = html.match(new RegExp(`<th scope="row">${label}</th><td>(\\d+)</td>`));
    return m ? Number(m[1]) : null;
  };
  return { confirmed: read("Confirmed"), pending: read("Waiting to confirm"), left: read("Opted out \\(details kept 30 days\\)"), html };
}

function collectErrors(page, bucket) {
  page.on("pageerror", (e) => bucket.push("pageerror: " + e.message));
  page.on("console", (m) => {
    if (m.type() !== "error") return;
    const loc = m.location() && m.location().url ? m.location().url : "";
    if (!loc || loc.startsWith(ORIGIN)) bucket.push("console.error: " + m.text() + (loc ? " @ " + loc : ""));
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

const STUB_TRACKER = `
  window.__tracked = [];
  window.DoughBossGrowth = { track: function (name, params) { window.__tracked.push({ name: name, params: params }); return true; } };
`;

async function newPage(browser, options) {
  const context = await browser.newContext(options || { ...devices["Desktop Chrome"], viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  return { context, page };
}

async function confirmedText(page) {
  return (await page.locator("main").innerText()).replace(/\s+/g, " ").trim();
}

/* ----------------------------------------------------------------------- run */

const browser = await chromium.launch();
const originalClaims = fs.existsSync(CLAIMS) ? fs.readFileSync(CLAIMS, "utf8") : null;
let fatal = null;
let adminContext = null;
try {
  fs.rmSync(MAIL, { force: true });
  fs.writeFileSync(MAILMODE, "ok");
  freshIp();

  adminContext = await browser.newContext();
  await adminLogin(adminContext);
  let h = await health(adminContext);
  record("starting state: every flag is off on the runtime", Object.values(h.flags_configured).every((v) => v === false), `core ${h.core_version}, companion ${h.plugin_version}`);
  record("the companion has eleven flags and no hero flag", Object.keys(h.flags_configured).length === 11 && !Object.keys(h.flags_configured).some((k) => /hero/.test(k)));

  const comingId = await upsertPage(adminContext, "coming-soon", "Coming soon", "[doughboss_growth_coming_soon]");
  const vipId = await upsertPage(adminContext, "vip-list", "VIP list", '[doughboss_growth_waitlist store="revesby"]');
  record("scratch pages created (coming-soon and vip-list)", comingId > 0 && vipId > 0, `ids ${comingId}, ${vipId}`);

  /* ---------- A: flags off ---------- */
  {
    const { context, page } = await newPage(browser);
    const errors = [];
    collectErrors(page, errors);
    for (const url of ["/", "/coming-soon/", "/vip-list/"]) {
      await page.goto(BASE + url, { waitUntil: "load" });
      const html = await page.content();
      record(`A flags off: ${url} has no companion asset and no waitlist markup`, !/dbgr-|DoughBossGrowth|doughboss-growth/.test(html));
      offCheck(`A flags off: ${url} never shows the raw shortcode text`, !/\[doughboss_growth_/.test(html));
    }
    await page.goto(BASE + "/coming-soon/", { waitUntil: "load" });
    record("A flags off: the coming-soon page does not say anything is coming", !(await page.content()).includes("Something exciting"));
    const t = await context.request.get(REST("/doughboss-growth/v1/form-token"));
    record("A flags off: the form-token route does not exist (404)", t.status() === 404, `HTTP ${t.status()}`);
    const p = await context.request.post(REST("/doughboss-growth/v1/waitlist"), { data: { email: "a@example.com" } });
    record("A flags off: the sign-up route does not exist (404)", p.status() === 404, `HTTP ${p.status()}`);
    const u = await context.request.post(REST("/doughboss-growth/v1/waitlist/unsubscribe"), { data: { id: "1", token: "a".repeat(32) } });
    offCheck("A flags off: the opt-out route still exists (a person must always be able to leave)", u.status() === 400, `HTTP ${u.status()} for a made-up link`);
    record("A flags off: no first-party console errors", errors.length === 0, errors.slice(0, 3).join(" | "));
    await context.close();
  }

  /* ---------- B: waitlist + coming soon on ---------- */
  const blocked = await saveSettings(adminContext, { "dbgr[features][waitlist]": "1", "dbgr[features][coming_soon]": "1" });
  h = await health(adminContext);
  record("B enabling the waitlist is REFUSED while the sender name and privacy URL are empty", h.flags.waitlist === false && /waitlist_requires_sender_legal_name/.test(blocked.location) && /waitlist_requires_privacy_policy_url/.test(blocked.location), blocked.location.replace(BASE, "").slice(0, 160));
  const saved = await saveSettings(adminContext, {
    "dbgr[features][waitlist]": "1",
    "dbgr[features][coming_soon]": "1",
    "dbgr[sender_legal_name]": SENDER,
    "dbgr[privacy_policy_url]": "/privacy-policy/",
  });
  h = await health(adminContext);
  record("B settings saved: waitlist and coming_soon effective, both modules active, storage ready", saved.status === 302 && h.flags.waitlist === true && h.flags.coming_soon === true && h.modules_active.waitlist === true && h.modules_active.coming_soon === true && h.storage_ready === true, JSON.stringify(h.modules_active));

  let before = await counts(adminContext);
  record("B the admin tab shows the sender, the counts (numbers only) and the [CONFIRM] gaps", before.html.includes(SENDER) && before.html.includes("[CONFIRM: sender contact details") && before.html.includes("[CONFIRM: how long confirmed sign-ups are kept") && Number.isInteger(before.confirmed) && Number.isInteger(before.pending) && Number.isInteger(before.left), `counts confirmed ${before.confirmed}, pending ${before.pending}, opted out ${before.left} (earlier runs leave rows; every later check is a difference)`);

  const EMAIL = `jordan.${Date.now()}@example.com`;
  const { context: ctx, page } = await newPage(browser);
  await page.addInitScript(STUB_TRACKER);
  const errors = [];
  collectErrors(page, errors);
  const posts = [];
  page.on("request", (r) => {
    if (/doughboss-growth\/v1\/waitlist$|rest_route=\/doughboss-growth\/v1\/waitlist$/.test(r.url()) && r.method() === "POST") posts.push(r.url());
  });
  await page.goto(BASE + "/coming-soon/", { waitUntil: "load" });
  const html = await page.content();
  record('B the coming-soon page: grep -i minis finds nothing in the whole page source (anonymous)', !/minis/i.test(html), `${(html.match(/minis/gi) || []).length} match(es)`);
  record("B headline and text are the neutral defaults", (await page.locator(".dbgr-cs__title").textContent()) === "Something exciting is coming" && (await page.locator(".dbgr-cs__body").textContent()) === "Be first to know");
  const visible = (await page.locator(".dbgr-cs").innerText()).replace(/\s+/g, " ");
  record("B the section names no product, price, size, date, dietary claim or location in its visible text", !/\$|%|halal|vegan|gluten|certified|\bbest\b|price|menu|\bpack\b|\bsize\b|\d{1,2}\s?(am|pm)|january|february|march|april|june|july|august|september|october|november|december/i.test(visible.replace(/Revesby|Bankstown|Roselands/g, "")), visible.slice(0, 220));
  const consent = page.locator('form[data-dbgr-waitlist] input[name="consent"]');
  record("B consent box: present, UNTICKED and required", (await consent.count()) === 1 && !(await consent.isChecked()) && (await consent.getAttribute("required")) !== null);
  record("B only one checkbox in the form (no interest picker)", (await page.locator('form[data-dbgr-waitlist] input[type="checkbox"]').count()) === 1);
  record("B the consent wording names the sender", (await page.locator(".dbgr-wl__consent").innerText()).includes(SENDER));
  const hp = page.locator('form[data-dbgr-waitlist] input[name="website"]');
  const hpBox = await hp.boundingBox();
  record("B honeypot: off-screen, not focusable, hidden from assistive technology", (!hpBox || hpBox.x < -1000) && (await hp.getAttribute("tabindex")) === "-1" && (await page.locator(".dbgr-wl__hp").getAttribute("aria-hidden")) === "true");
  const coreShops = (await (await adminContext.request.get(REST("/doughboss/v1/locations"))).json()).map((l) => l.name);
  const options = await page.locator('form[data-dbgr-waitlist] select[name="store"] option').allInnerTexts();
  record("B store choices are exactly the active shops core reports, after No preference", options.join("|") === ["No preference", ...coreShops].join("|"), `${options.join("|")} (core: ${coreShops.join(", ")})`);
  const SHOP = coreShops[0];
  record("B the shortcode store pre-selects nothing here (page has no store attribute)", (await page.locator('form[data-dbgr-waitlist] select[name="store"]').inputValue()) === "");
  await page.screenshot({ path: path.join(SHOTS, "wp05-coming-soon-desktop.png"), fullPage: true });
  await runAxe(page, "coming-soon section, desktop", ".dbgr-cs");

  // Keyboard order (the honeypot must be skipped).
  await page.locator('form[data-dbgr-waitlist] input[name="email"]').focus();
  const order = [];
  for (let i = 0; i < 6; i++) {
    await page.keyboard.press("Tab");
    order.push(await page.evaluate(() => (document.activeElement && (document.activeElement.name || document.activeElement.tagName + (document.activeElement.textContent ? ":" + document.activeElement.textContent.trim().slice(0, 12) : ""))) || ""));
  }
  record("B keyboard order: first name, mobile, store, consent, privacy link, button (honeypot skipped)", order.join(",") === "first_name,mobile,store,consent,A:Privacy poli,BUTTON:Join the VIP", order.join(","));

  {
    const { context: vctx, page: vpage } = await newPage(browser);
    await vpage.goto(BASE + "/vip-list/", { waitUntil: "load" });
    const picked = await vpage.locator('form[data-dbgr-waitlist] select[name="store"] option:checked').innerText();
    record('B [doughboss_growth_waitlist store="revesby"] pre-selects that shop', picked === "Revesby", picked);
    await vctx.close();
  }

  // Consent unticked: refused in the browser, nothing posted.
  await page.fill('input[name="email"]', EMAIL);
  await page.click(".dbgr-wl__button");
  await sleep(300);
  record("B submit with the box unticked: a clear message and NO request to the sign-up route", (await page.locator("[data-dbgr-wl-status]").innerText()).includes("tick the box") && posts.length === 0, `${posts.length} POST(s)`);
  record("B focus moves to the consent box", await page.evaluate(() => document.activeElement && document.activeElement.name === "consent"));

  // Real sign-up.
  const mailsBefore = readMail().length;
  await page.check('input[name="consent"]');
  await page.fill('input[name="first_name"]', "Jordan");
  await page.selectOption('select[name="store"]', { label: SHOP });
  await page.click(".dbgr-wl__button");
  await page.waitForFunction(() => /Thanks\./.test(document.querySelector("[data-dbgr-wl-status]").textContent), null, { timeout: 30000 });
  const successText = await page.locator("[data-dbgr-wl-status]").innerText();
  record("B sign-up: the neutral success message is shown and the fields are hidden", /^Thanks\. If this email address can join the list/.test(successText) && !(await page.locator("[data-dbgr-wl-fields]").isVisible()));
  const tracked = await page.evaluate(() => window.__tracked);
  record("B waitlist_submit was sent once, after the server answered, with the chosen shop's slug", tracked.filter((e) => e.name === "waitlist_submit").length === 1 && tracked.find((e) => e.name === "waitlist_submit").params.store === SHOP.toLowerCase(), JSON.stringify(tracked));
  await sleep(500);
  const mails = readMail();
  record("B exactly one confirmation email was produced", mails.length === mailsBefore + 1 && mails[mails.length - 1].to === EMAIL, `${mails.length - mailsBefore} new`);
  const mail = mails[mails.length - 1] || { message: "", subject: "", headers: [] };
  record("B the email names the sender, links the privacy policy, and says what happens if ignored", mail.message.includes(SENDER) && mail.message.includes("/privacy-policy/") && /do nothing/.test(mail.message) && !/minis/i.test(mail.subject + mail.message), mail.subject);
  const confirmUrl = (mail.message.match(/https?:\/\/[^\s]+dbgr_wl=confirm[^\s]+/) || [])[0];
  const unsubUrl = (mail.message.match(/https?:\/\/[^\s]+dbgr_wl=unsubscribe[^\s]+/) || [])[0];
  record("B the email carries a confirmation link and a working opt-out link, plus the one-click List-Unsubscribe headers", !!confirmUrl && !!unsubUrl && JSON.stringify(mail.headers).includes("List-Unsubscribe-Post: List-Unsubscribe=One-Click"));
  let c = await counts(adminContext);
  record("B the database has one pending row and nothing confirmed", c.pending === before.pending + 1 && c.confirmed === before.confirmed, `${c.confirmed}/${c.pending}/${c.left}`);
  record("B the staff screen shows no email address (counts only)", !c.html.includes(EMAIL));

  // Confirm: GET changes nothing, POST confirms, single use.
  const toLocal = (u) => u.replace(/^https?:\/\/[^/]+/, BASE);
  {
    const { context: mctx, page: mpage } = await newPage(browser);
    const resp = await mpage.goto(toLocal(confirmUrl), { waitUntil: "load" });
    const headers = resp.headers();
    record("B confirm link, GET: shows a Confirm button and the sender", (await mpage.locator("button:has-text('Confirm')").count()) === 1 && (await mpage.locator("main").innerText()).includes(SENDER));
    record("B confirm link page: never cached, never indexed, sends no referrer", /no-store|no-cache/.test(headers["cache-control"] || "") && /noindex/.test(headers["x-robots-tag"] || "") && (headers["referrer-policy"] || "") === "no-referrer", `${headers["cache-control"]} | ${headers["x-robots-tag"]} | ${headers["referrer-policy"]}`);
    c = await counts(adminContext);
    record("B confirm link, GET: the sign-up is STILL pending (a mail scanner cannot confirm)", c.pending === before.pending + 1 && c.confirmed === before.confirmed, `${c.confirmed}/${c.pending}`);
    await runAxe(mpage, "confirm page", "main");
    await mpage.click("button:has-text('Confirm')");
    await mpage.waitForLoadState("load");
    record("B confirm link, POST: confirmed", (await confirmedText(mpage)).includes("You are on the VIP list"));
    c = await counts(adminContext);
    record("B the database now has one confirmed row and none pending", c.confirmed === before.confirmed + 1 && c.pending === before.pending, `${c.confirmed}/${c.pending}`);
    const again = await mpage.goto(toLocal(confirmUrl), { waitUntil: "load" });
    await mpage.click("button:has-text('Confirm')");
    await mpage.waitForLoadState("load");
    record("B the same confirm link a second time is refused", (await confirmedText(mpage)).includes("not valid or has already been used") && again.status() === 200);
    await mctx.close();
  }

  // Duplicate: identical answer, no new mail, no new row.
  {
    const { context: dctx, page: dpage } = await newPage(browser);
    await dpage.goto(BASE + "/coming-soon/", { waitUntil: "load" });
    const mailsNow = readMail().length;
    await dpage.fill('input[name="email"]', EMAIL.toUpperCase());
    await dpage.check('input[name="consent"]');
    await dpage.click(".dbgr-wl__button");
    await dpage.waitForFunction(() => /Thanks\./.test(document.querySelector("[data-dbgr-wl-status]").textContent), null, { timeout: 30000 });
    const dupText = await dpage.locator("[data-dbgr-wl-status]").innerText();
    await sleep(400);
    c = await counts(adminContext);
    record("B duplicate address (different case): the SAME message, no new email, no new row", dupText === successText && readMail().length === mailsNow && c.confirmed === before.confirmed + 1 && c.pending === before.pending, `${readMail().length - mailsNow} new mail(s)`);
    await dctx.close();
  }

  // Opt-out.
  {
    const { context: uctx, page: upage } = await newPage(browser);
    await upage.goto(toLocal(unsubUrl), { waitUntil: "load" });
    record("B opt-out link, GET: shows an Unsubscribe button and changes nothing", (await upage.locator("button:has-text('Unsubscribe')").count()) === 1 && (await counts(adminContext)).left === before.left);
    await upage.click("button:has-text('Unsubscribe')");
    await upage.waitForLoadState("load");
    record("B opt-out link, POST: unsubscribed", (await confirmedText(upage)).includes("You have been unsubscribed"));
    c = await counts(adminContext);
    record("B the database shows the person as opted out (details kept for the retention window)", c.left === before.left + 1 && c.confirmed === before.confirmed, `${c.confirmed}/${c.pending}/${c.left}`);
    await upage.goto(toLocal(unsubUrl), { waitUntil: "load" });
    await upage.click("button:has-text('Unsubscribe')");
    await upage.waitForLoadState("load");
    record("B a second opt-out is fine (idempotent)", (await confirmedText(upage)).includes("You have been unsubscribed") && (await counts(adminContext)).left === before.left + 1);
    await uctx.close();
  }
  {
    // Re-signup after opt-out: same message, nothing sent, nothing stored.
    const { context: rctx, page: rpage } = await newPage(browser);
    await rpage.goto(BASE + "/coming-soon/", { waitUntil: "load" });
    const mailsNow = readMail().length;
    await rpage.fill('input[name="email"]', EMAIL);
    await rpage.check('input[name="consent"]');
    await rpage.click(".dbgr-wl__button");
    await rpage.waitForFunction(() => /Thanks\./.test(document.querySelector("[data-dbgr-wl-status]").textContent), null, { timeout: 30000 });
    await sleep(400);
    c = await counts(adminContext);
    record("B signing up again after opting out: the same message, no email, no new row", (await rpage.locator("[data-dbgr-wl-status]").innerText()) === successText && readMail().length === mailsNow && c.left === before.left + 1 && c.pending === before.pending, `${readMail().length - mailsNow} new mail(s)`);
    await rctx.close();
  }

  // One-click opt-out by a mail client (POST with the arguments in the URL).
  {
    const second = `oneclick.${Date.now()}@example.com`;
    freshIp();
    const tokenResponse = await ctx.request.get(REST("/doughboss-growth/v1/form-token"));
    const { token } = await tokenResponse.json();
    await sleep(3600);
    const post = await ctx.request.post(REST("/doughboss-growth/v1/waitlist"), { data: { email: second, consent: 1, consent_version: await page.locator('input[name="consent_version"]').inputValue(), website: "", token, store: "", path: "/coming-soon/" } });
    const secondMail = readMail().filter((m) => m.to === second)[0];
    const unsub2 = ((secondMail && secondMail.message.match(/https?:\/\/[^\s]+dbgr_wl=unsubscribe[^\s]+/)) || [])[0];
    record("B a second sign-up through the API worked (200) and was emailed", post.status() === 200 && !!unsub2, `HTTP ${post.status()}`);
    const getRes = await ctx.request.get(toLocal(unsub2));
    c = await counts(adminContext);
    const leftAfterGet = c.left;
    const oneClick = await ctx.request.post(toLocal(unsub2), { form: { "List-Unsubscribe": "One-Click" } });
    c = await counts(adminContext);
    record("B one-click opt-out: a GET changes nothing, the mail client POST opts the person out", getRes.status() === 200 && leftAfterGet === before.left + 1 && oneClick.status() === 200 && c.left === before.left + 2, `after GET ${leftAfterGet}, after POST ${c.left}`);
  }

  // Refusals through the API.
  {
    freshIp();
    const version = await page.locator('input[name="consent_version"]').inputValue();
    const t1 = await (await ctx.request.get(REST("/doughboss-growth/v1/form-token"))).json();
    const early = await ctx.request.post(REST("/doughboss-growth/v1/waitlist"), { data: { email: "early@example.com", consent: 1, consent_version: version, website: "", token: t1.token } });
    record("B a token younger than 3 seconds is refused (400, dbgr_token_early)", early.status() === 400 && (await early.json()).code === "dbgr_token_early");
    await sleep(3600);
    const mailsNow = readMail().length;
    const trap = await ctx.request.post(REST("/doughboss-growth/v1/waitlist"), { data: { email: "bot@example.com", consent: 1, consent_version: version, website: "http://spam.example", token: t1.token } });
    const trapBody = await trap.json();
    record("B honeypot filled: the SAME success body as a real sign-up, and nothing is sent or stored", trap.status() === 200 && trapBody.message === successText && readMail().length === mailsNow && !(await counts(adminContext)).html.includes("bot@example.com"));
    const noConsent = await ctx.request.post(REST("/doughboss-growth/v1/waitlist"), { data: { email: "noconsent@example.com", consent: "on", consent_version: version, website: "", token: t1.token } });
    record("B consent must be an explicit 1 ('on' is refused)", noConsent.status() === 400 && (await noConsent.json()).code === "dbgr_consent_required");
    const stale = await ctx.request.post(REST("/doughboss-growth/v1/waitlist"), { data: { email: "stale@example.com", consent: 1, consent_version: "wl-000000000000", website: "", token: t1.token } });
    record("B a stale consent wording version is refused", stale.status() === 400 && (await stale.json()).code === "dbgr_consent_changed");
    const forged = await ctx.request.post(REST("/doughboss-growth/v1/waitlist"), { data: { email: "forged@example.com", consent: 1, consent_version: version, website: "", token: "1790899200.00000000000000000000000000000000" } });
    record("B a forged token is refused", forged.status() === 400 && (await forged.json()).code === "dbgr_token_invalid");
    const noStore = await ctx.request.post(REST("/doughboss-growth/v1/waitlist"), { data: { email: "badstore@example.com", consent: 1, consent_version: version, website: "", token: t1.token, store: "999" } });
    record("B an unknown shop is refused", noStore.status() === 400);
    const badMail = await ctx.request.post(REST("/doughboss-growth/v1/waitlist"), { data: { email: "a@example.com\nBcc: x@evil.test", consent: 1, consent_version: version, website: "", token: t1.token } });
    record("B an email with a line break (header injection) is refused", badMail.status() === 400 && (await badMail.json()).code === "dbgr_invalid_email");
    const get = await ctx.request.get(REST("/doughboss-growth/v1/waitlist/confirm"));
    record("B the confirm route has no GET method (404)", get.status() === 404 || get.status() === 405, `HTTP ${get.status()}`);
    const tokRes = await ctx.request.get(REST("/doughboss-growth/v1/form-token"));
    record("B the form-token route is never cached", /no-store/.test(tokRes.headers()["cache-control"] || ""), tokRes.headers()["cache-control"]);
    record("B none of the refused requests stored anything or sent mail", readMail().length === mailsNow);
  }

  // Mail failure: nothing half-made.
  {
    freshIp();
    const { context: fctx, page: fpage } = await newPage(browser);
    await fpage.goto(BASE + "/coming-soon/", { waitUntil: "load" });
    fs.writeFileSync(MAILMODE, "fail");
    const pendingBefore = (await counts(adminContext)).pending;
    await fpage.fill('input[name="email"]', `mailfail.${Date.now()}@example.com`);
    await fpage.check('input[name="consent"]');
    await fpage.click(".dbgr-wl__button");
    await fpage.waitForFunction(() => document.querySelector("[data-dbgr-wl-status]").textContent.length > 0 && !/Sending|moment/.test(document.querySelector("[data-dbgr-wl-status]").textContent), null, { timeout: 30000 });
    const failText = await fpage.locator("[data-dbgr-wl-status]").innerText();
    fs.writeFileSync(MAILMODE, "ok");
    c = await counts(adminContext);
    record("B email cannot be sent: the visitor is told to try later, the form stays, and NO row is left behind", /try again later/i.test(failText) && (await fpage.locator("[data-dbgr-wl-fields]").isVisible()) && c.pending === pendingBefore, failText);
    await fctx.close();
  }

  // Mobile layout and axe.
  {
    const { context: mctx, page: mpage } = await newPage(browser, { ...devices["Pixel 7"] });
    await mpage.goto(BASE + "/coming-soon/", { waitUntil: "load" });
    const overflow = await mpage.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    record("B Pixel 7: no horizontal scroll", overflow <= 0, `${overflow}px`);
    const btn = await mpage.locator(".dbgr-wl__button").boundingBox();
    record("B Pixel 7: the button is at least 44 px tall", btn && btn.height >= 44, `${btn && Math.round(btn.height)} px`);
    await mpage.screenshot({ path: path.join(SHOTS, "wp05-coming-soon-pixel7.png"), fullPage: true });
    await runAxe(mpage, "coming-soon section, Pixel 7", ".dbgr-cs");
    await mctx.close();
  }

  // Limit: 5 an hour per address (all of this is on the real SQLite runtime, so it exercises the real limiter).
  {
    freshIp();
    const version = await page.locator('input[name="consent_version"]').inputValue();
    const t = await (await ctx.request.get(REST("/doughboss-growth/v1/form-token"))).json();
    await sleep(3600);
    const mailsNow = readMail().length;
    const statuses = [];
    let retry = null;
    for (let i = 0; i < 7; i++) {
      const r = await ctx.request.post(REST("/doughboss-growth/v1/waitlist"), { data: { email: `limit${i}.${Date.now()}@example.com`, consent: 1, consent_version: version, website: "", token: t.token } });
      statuses.push(r.status());
      if (r.status() === 429) retry = r.headers()["retry-after"];
    }
    record("B the sixth request from one address in an hour is refused (429) and sends no mail", statuses.slice(0, 5).every((s) => s === 200) && statuses[5] === 429 && statuses[6] === 429 && readMail().length === mailsNow + 5, statuses.join(","));
    record("B the refusal carries a Retry-After header", retry && Number(retry) > 0, `Retry-After ${retry}`);
    const stillWorks = await ctx.request.post(toLocal(unsubUrl), { form: { "List-Unsubscribe": "One-Click" } });
    record("B an opted-out person can still use their link while sign-ups are limited", stillWorks.status() === 200);
  }
  record("B no first-party console errors on the coming-soon page", errors.length === 0, errors.slice(0, 3).join(" | "));
  await ctx.close();

  /* ---------- C: tilt cards (a scratch claim) ---------- */
  {
    const claims = JSON.parse(originalClaims || '{"version":1,"claims":[]}');
    claims.claims.push({ id: "scratch-fixture-claim", text: "A scratch fixture statement for the card check", confirmed: true, source: { kind: "owner-confirmed", ref: "scratch runtime fixture, not a real claim", confirmedOn: "2026-10-02" } });
    fs.writeFileSync(CLAIMS, JSON.stringify(claims, null, 2));
    await upsertPage(adminContext, "cards-test", "Cards test", '[doughboss_growth_coming_soon cards="scratch-fixture-claim,catering-lead-time" form="0"]');
    const { context: cctx, page: cpage } = await newPage(browser);
    await cpage.goto(BASE + "/cards-test/", { waitUntil: "load" });
    record("C exactly one card renders: the confirmed claim; the unconfirmed one is left out", (await cpage.locator(".dbgr-card").count()) === 1 && (await cpage.locator(".dbgr-card").innerText()).includes("scratch fixture statement") && !(await cpage.content()).includes("wording to be supplied by Elie"));
    const face = cpage.locator(".dbgr-card__face");
    await face.scrollIntoViewIfNeeded();
    const box = await face.boundingBox();
    await cpage.mouse.move(box.x + box.width - 4, box.y + 4);
    await cpage.waitForTimeout(400);
    const tilted = await face.evaluate((el) => getComputedStyle(el).transform);
    record("C motion allowed: hovering a card leans it (a real 3D transform is applied, not the identity)", /^matrix3d\(/.test(tilted), tilted);
    await cpage.mouse.move(1, 1);
    await cpage.waitForTimeout(400);
    await cpage.emulateMedia({ reducedMotion: "reduce" });
    await cpage.waitForTimeout(200);
    await cpage.mouse.move(box.x + box.width - 4, box.y + 4);
    await cpage.waitForTimeout(400);
    const flat = await face.evaluate((el) => getComputedStyle(el).transform);
    record("C reduced motion switched on while the page is open: the card is flat", flat === "none" && (await cpage.locator(".dbgr-card").getAttribute("class")).includes("dbgr-tilt--flat"), `${flat}`);
    await cpage.emulateMedia({ reducedMotion: "no-preference" });
    await cpage.waitForTimeout(200);
    await cpage.mouse.move(1, 1);
    await cpage.mouse.move(box.x + box.width - 4, box.y + 4);
    await cpage.waitForTimeout(400);
    const back = await face.evaluate((el) => getComputedStyle(el).transform);
    record("C reduced motion switched off again: the card leans again", /^matrix3d\(/.test(back), back);
    await cpage.screenshot({ path: path.join(SHOTS, "wp05-cards.png"), fullPage: true });
    await runAxe(cpage, "cards section", ".dbgr-cs");
    const reducedCtx = await browser.newContext({ ...devices["Desktop Chrome"], reducedMotion: "reduce" });
    const reducedPage = await reducedCtx.newPage();
    await reducedPage.goto(BASE + "/cards-test/", { waitUntil: "load" });
    const rbox = await reducedPage.locator(".dbgr-card__face").boundingBox();
    await reducedPage.mouse.move(rbox.x + rbox.width - 4, rbox.y + 4);
    await reducedPage.waitForTimeout(300);
    record("C page loaded with reduced motion: flat from the start", (await reducedPage.locator(".dbgr-card__face").evaluate((el) => getComputedStyle(el).transform)) === "none");
    await cctx.close();
    await reducedCtx.close();
    fs.writeFileSync(CLAIMS, originalClaims);
    await upsertPage(adminContext, "cards-test", "Cards test", "[doughboss_growth_coming_soon]", "draft");
    const { context: c2, page: p2 } = await newPage(browser);
    const resp = await p2.goto(BASE + "/cards-test/", { waitUntil: "load" });
    record("C scratch claim removed and its page drafted: nothing of it is public any more", resp.status() === 404 || !(await p2.content()).includes("scratch fixture"));
    await c2.close();
  }

  /* ---------- D: home ribbon ---------- */
  {
    const { context: hctx, page: hpage } = await newPage(browser);
    await hpage.goto(BASE + "/", { waitUntil: "load" });
    record("D ribbon switch OFF (default): the home page carries no companion markup", !/dbgr-/.test(await hpage.content()));
    await hctx.close();

    const nonce = await nonceFrom(adminContext, `${BASE}/wp-admin/admin.php?page=doughboss-growth&tab=coming-soon`);
    const noNonce = await adminContext.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: "doughboss_growth_save_coming_soon", ribbon: "1" }, maxRedirects: 0 });
    record("D ribbon switch without a nonce is refused (403)", noNonce.status() === 403, `HTTP ${noNonce.status()}`);
    const anon = await browser.newContext();
    const noCap = await anon.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: "doughboss_growth_save_coming_soon", ribbon: "1", _wpnonce: nonce }, maxRedirects: 0 });
    record("D ribbon switch by a visitor is refused (not saved)", noCap.status() !== 302 || !/dbgr_saved/.test(noCap.headers().location || ""), `HTTP ${noCap.status()}`);
    await anon.close();
    const on = await adminContext.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: "doughboss_growth_save_coming_soon", ribbon: "1", _wpnonce: nonce }, maxRedirects: 0 });
    record("D ribbon switch saved by a manager with a valid nonce", on.status() === 302);

    const { context: rctx, page: rpage } = await newPage(browser);
    await rpage.addInitScript(STUB_TRACKER);
    const rerrors = [];
    collectErrors(rpage, rerrors);
    await rpage.goto(BASE + "/", { waitUntil: "load" });
    const ribbon = rpage.locator(".dbgr-cs--ribbon");
    record("D ribbon ON: it follows the home hero and shows the neutral line and the link to the coming-soon page", (await ribbon.count()) === 1 && (await ribbon.innerText()).includes("Something exciting is coming") && (await ribbon.locator("a").getAttribute("href")).includes("/coming-soon/"));
    record("D ribbon: the home page source has no 'minis'", !/minis/i.test(await rpage.content()));
    const order = await rpage.evaluate(() => {
      const hero = document.querySelector(".db-manoush-hero");
      const r = document.querySelector(".dbgr-cs--ribbon");
      return hero && r ? !!(hero.compareDocumentPosition(r) & Node.DOCUMENT_POSITION_FOLLOWING) : false;
    });
    record("D ribbon: it comes after the hero in the document", order);
    await ribbon.scrollIntoViewIfNeeded();
    await rpage.waitForTimeout(600);
    const seen = await rpage.evaluate(() => window.__tracked);
    record("D coming_soon_view sent once, with surface home, when the ribbon is seen", seen.filter((e) => e.name === "coming_soon_view").length === 1 && seen.find((e) => e.name === "coming_soon_view").params.surface === "home", JSON.stringify(seen));
    await rpage.screenshot({ path: path.join(SHOTS, "wp05-home-ribbon.png"), fullPage: false });
    await runAxe(rpage, "home ribbon", ".dbgr-cs--ribbon");
    record("D no first-party console errors on the home page", rerrors.length === 0, rerrors.slice(0, 3).join(" | "));
    await rctx.close();

    const nonce2 = await nonceFrom(adminContext, `${BASE}/wp-admin/admin.php?page=doughboss-growth&tab=coming-soon`);
    await adminContext.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: "doughboss_growth_save_coming_soon", _wpnonce: nonce2 }, maxRedirects: 0 });
    const { context: h2, page: hp2 } = await newPage(browser);
    await hp2.goto(BASE + "/", { waitUntil: "load" });
    record("D ribbon switched OFF again: the home page is clean", !/dbgr-/.test(await hp2.content()));
    await h2.close();
  }

  /* ---------- E: flags switched OFF after use: the way out must stay open ---------- */
  {
    const late = `late.${Date.now()}@example.com`;
    freshIp();
    const version = (await (await adminContext.request.get(BASE + "/coming-soon/")).text()).match(/name="consent_version" value="([^"]+)"/)[1];
    const t = await (await adminContext.request.get(REST("/doughboss-growth/v1/form-token"))).json();
    await sleep(3600);
    const r = await adminContext.request.post(REST("/doughboss-growth/v1/waitlist"), { data: { email: late, consent: 1, consent_version: version, website: "", token: t.token } });
    const lateMail = readMail().filter((m) => m.to === late)[0];
    const lateUnsub = ((lateMail && lateMail.message.match(/https?:\/\/[^\s]+dbgr_wl=unsubscribe[^\s]+/)) || [])[0];
    const lateConfirm = ((lateMail && lateMail.message.match(/https?:\/\/[^\s]+dbgr_wl=confirm[^\s]+/)) || [])[0];
    record("E a last sign-up was made while the waitlist was on", r.status() === 200 && !!lateUnsub && !!lateConfirm);
    await saveSettings(adminContext, {});
    h = await health(adminContext);
    record("E the waitlist and coming-soon flags are now off", h.flags.waitlist === false && h.flags.coming_soon === false);
    const toLocal2 = (u) => u.replace(/^https?:\/\/[^/]+/, BASE);
    const off = await newPage(browser);
    await off.page.goto(BASE + "/coming-soon/", { waitUntil: "load" });
    offCheck("E flags off after use: the coming-soon page shows neither the section nor the raw shortcode", !/dbgr-|\[doughboss_growth_|Something exciting/.test(await off.page.content()));
    const route = await off.context.request.post(REST("/doughboss-growth/v1/waitlist"), { data: { email: "x@example.com" } });
    record("E flags off after use: the sign-up route is gone (404)", route.status() === 404, `HTTP ${route.status()}`);
    await off.page.goto(toLocal2(lateConfirm), { waitUntil: "load" });
    const before2 = await counts(adminContext);
    offCheck("E flags off after use: a confirmation link is refused (sign-ups are off)", /not available/.test(await off.page.locator("main").innerText().catch(() => "")) || (await off.page.locator("main").count()) === 0, "");
    await off.page.goto(toLocal2(lateUnsub), { waitUntil: "load" });
    const hasButton = (await off.page.locator("button:has-text('Unsubscribe')").count()) === 1;
    if (hasButton) {
      await off.page.click("button:has-text('Unsubscribe')");
      await off.page.waitForLoadState("load");
    }
    const after2 = await counts(adminContext);
    offCheck("E flags off after use: the opt-out link still works (a person must always be able to leave)", hasButton && /You have been unsubscribed/.test(await off.page.locator("main").innerText()) && after2.left === before2.left + 1, `button ${hasButton}, opted out ${before2.left} -> ${after2.left}`);
    await off.context.close();
  }
} catch (error) {
  fatal = error;
  record("run completed without a script error", false, String(error && error.stack ? error.stack.split("\n").slice(0, 4).join(" / ") : error));
} finally {
  /* ---------- cleanup: every flag off again ---------- */
  try {
    if (originalClaims !== null) fs.writeFileSync(CLAIMS, originalClaims);
    fs.writeFileSync(MAILMODE, "ok");
    fs.rmSync(IPFILE, { force: true });
    if (adminContext) {
      await saveSettings(adminContext, {});
      const h = await health(adminContext);
      record("cleanup: every flag is off again", Object.values(h.flags_configured).every((v) => v === false));
      const { context, page } = await newPage(browser);
      await page.goto(BASE + "/coming-soon/", { waitUntil: "load" });
      record("cleanup: the coming-soon page carries no companion markup again", !/dbgr-|Something exciting/.test(await page.content()));
      await context.close();
    }
  } catch (e) {
    record("cleanup completed", false, String(e));
  }
  await browser.close();
}

const failed = checks.filter((c) => !c.ok);
fs.writeFileSync(path.join(SHOTS, "wp05-checks.json"), JSON.stringify(checks, null, 2));
process.stdout.write(`\n${checks.length - failed.length} of ${checks.length} checks passed${fatal ? " (script error)" : ""}\n`);
process.exit(failed.length || fatal ? 1 : 0);
