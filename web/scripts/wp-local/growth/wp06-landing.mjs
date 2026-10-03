// WP-06 browser check: landing pages engine and SEO head on the local WordPress runtime
// (companion mounted next to DoughBoss; see scripts/wp-local/start.sh and WPL_EXTRA_PLUGIN_SRC).
//
//   WPL_URL=http://127.0.0.1:9406 WPL_STATE=/tmp/wpl-WP-06 node scripts/wp-local/growth/wp06-landing.mjs      (run from web/)
//   SHOTS_DIR=/some/dir          where screenshots and the checks JSON go (default $WPL_STATE/shots)
//
// SCRATCH PREPARATION (done once, in the runtime's scratch COPY of the plugin, never in the source tree; the script stops
// with NOT RUN if it is missing):
//   $WPL_STATE/src/plugins/doughboss-growth/wp06-scratch.php, loaded from the copy's main file with one extra line:
//       require_once __DIR__ . '/wp06-scratch.php';
//   It (a) forces the blog_public option to 1 so WordPress prints its normal robots tag (the seed makes the site non-public,
//   which would put "noindex, nofollow" on every page and hide what the companion adds), (b) defines WPSEO_VERSION while the
//   file $WPL_STATE/run/out/wp06-seoplugin holds "1" (the "scratch SEO plugin" of the acceptance check; start.sh has no mu-plugin
//   mount, so the constant is defined from the scratch file of the copy instead; the CONTENT is read because Playground's file
//   layer caches existence checks), and (c) answers /?wp06_seed=1 for an
//   administrator by adding two shops with FAKE test addresses, weekly hours and catering packages through core's own classes.
//   The only other scratch edit is a temporary claim in the copy's content/claims.json; the script restores the original file.
// No request leaves the machine.
//
// Phases:
//   A  flags off      -> hub baseline captured with wp01-inert.mjs; the contract URLs do not exist
//   B  flags on       -> admin tab, create button (CSRF refused, idempotent), six drafts under the right parents, drafts are not public,
//                        hub pages unchanged
//   C  published      -> each page: one title, one description, one canonical (WordPress core's), one companion JSON-LD script, zero core
//                        JSON-LD, address/phone/hours from core, package prices equal core, no product name, no raw shortcode,
//                        a11y (axe) desktop and mobile, hub pages unchanged
//   D  a claim        -> a scratch confirmed claim appears exactly as written and makes the page indexable; unconfirmed claims never appear
//   E  SEO plugin     -> with WPSEO_VERSION defined the companion prints no head tags; with seo_jsonld_with_seo_plugin only the JSON-LD
//   F  flags off      -> nothing in the head; the raw shortcode text is reported (a registry gap is a NOTE unless the scratch patch is present)
//   G  rollback       -> deactivating the plugin drafts the six pages; reactivating keeps the settings
// The script leaves the runtime with every flag OFF and the six pages as drafts.
import { chromium, devices } from "@playwright/test";
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const BASE = (process.env.WPL_URL || "http://127.0.0.1:9406").replace(/\/$/, "");
const STATE = process.env.WPL_STATE || "/tmp/wpl-WP-06";
const OUT = path.join(STATE, "run", "out");
const SEOFLAG = path.join(OUT, "wp06-seoplugin");
const COPY = path.join(STATE, "src", "plugins", "doughboss-growth");
const CLAIMS = path.join(COPY, "content", "claims.json");
const SHOTS = process.env.SHOTS_DIR || path.join(STATE, "shots");
const HERE = path.dirname(fileURLToPath(import.meta.url));
const AXE = path.resolve(HERE, "../../../node_modules/axe-core/axe.min.js");
const WP01 = path.join(HERE, "wp01-inert.mjs");
const SOURCE_DEFS = path.resolve(HERE, "../../../../doughboss-growth/content/landing");
const ORIGIN = new URL(BASE).origin;
const REST = (route) => `${BASE}/?rest_route=${route}`;
fs.mkdirSync(SHOTS, { recursive: true });

const KEYS = ["catering-corporate", "catering-events", "catering-office-breakfast", "locations-bankstown", "locations-revesby", "locations-roselands"];
const BRAND = "Dough Boss";
const checks = [];
function record(name, ok, detail) {
  checks.push({ name, ok: !!ok, detail: detail || "" });
  process.stdout.write(`${ok ? "PASS" : "FAIL"}  ${name}${detail ? "  (" + detail + ")" : ""}\n`);
}
function note(text) {
  process.stdout.write(`NOTE  ${text}\n`);
}

// Contract change request 1 (see the hand-off): the module registry must initialise the landing module (and register an empty
// shortcode) even while its flag is off, so a published page never prints the raw tag. The source tree does NOT contain that
// change (WP-01 owns the registry). The script reports the flag-off behaviour as a hard check when the scratch copy carries the
// patch and as a NOTE (observed gap) when it does not.
const REGISTRY_PATCHED = fs.existsSync(path.join(COPY, "includes", "class-doughboss-growth.php")) && /'always'\s*=>\s*true/.test(fs.readFileSync(path.join(COPY, "includes", "class-doughboss-growth.php"), "utf8"));
function offCheck(name, ok, detail) {
  if (REGISTRY_PATCHED) {
    record(name + " [scratch registry patch present]", ok, detail);
  } else {
    note(`${ok ? "ok" : "GAP"} (unpatched registry): ${name}${detail ? " (" + detail + ")" : ""}`);
  }
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
  const forms = html.split("<form");
  for (const f of forms) {
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

async function health(context) {
  const restNonce = (await (await context.request.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`)).text()).trim();
  const response = await context.request.get(REST("/doughboss-growth/v1/health"), { headers: { "x-wp-nonce": restNonce } });
  return response.json();
}

async function restNonce(context) {
  return (await (await context.request.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`)).text()).trim();
}

async function listPages(context, extra = "") {
  const headers = { "x-wp-nonce": await restNonce(context) };
  const response = await context.request.get(REST("/wp/v2/pages") + `&per_page=100&status=any&context=edit${extra}`, { headers });
  return response.json();
}

async function setPageStatus(context, id, status) {
  const headers = { "x-wp-nonce": await restNonce(context) };
  const response = await context.request.post(REST(`/wp/v2/pages/${id}`), { headers, data: { status } });
  if (!response.ok()) throw new Error(`page ${id} -> ${status}: HTTP ${response.status()} ${await response.text()}`);
}

async function deletePage(context, id) {
  const headers = { "x-wp-nonce": await restNonce(context) };
  const response = await context.request.delete(REST(`/wp/v2/pages/${id}`) + "&force=true", { headers });
  if (!response.ok()) throw new Error(`delete page ${id}: HTTP ${response.status()}`);
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

function capture(dir) {
  fs.rmSync(dir, { recursive: true, force: true });
  return execFileSync("node", [WP01, "capture", BASE, dir], { encoding: "utf8" });
}
function compare(a, b) {
  try {
    return { ok: true, text: execFileSync("node", [WP01, "compare", a, b], { encoding: "utf8" }) };
  } catch (e) {
    return { ok: false, text: String(e.stdout || e.message) };
  }
}

/** What the head of a page holds, read from the DOM of the rendered page. */
async function headFacts(page) {
  return page.evaluate(() => {
    const q = (s) => Array.from(document.querySelectorAll(s));
    const ld = q('script[type="application/ld+json"]').map((s) => s.textContent);
    const meta = (sel) => (document.querySelector(sel) ? document.querySelector(sel).getAttribute("content") : null);
    return {
      titleTags: q("title").length,
      title: document.title,
      descriptions: q('meta[name="description"]').length,
      description: meta('meta[name="description"]'),
      canonicals: q('link[rel="canonical"]').length,
      canonical: q('link[rel="canonical"]').length ? q('link[rel="canonical"]')[0].getAttribute("href") : null,
      robots: q('meta[name="robots"]').map((m) => m.getAttribute("content")),
      ogTitle: meta('meta[property="og:title"]'),
      ogUrl: meta('meta[property="og:url"]'),
      ogCount: q('meta[property^="og:"]').length,
      twitterCount: q('meta[name^="twitter:"]').length,
      ldCount: ld.length,
      ld,
      h1: q("h1").map((h) => h.textContent.trim()),
    };
  });
}

function readDef(key) {
  return JSON.parse(fs.readFileSync(path.join(SOURCE_DEFS, `${key}.json`), "utf8"));
}

/* ----------------------------------------------------------------------- run */

if (!fs.existsSync(path.join(COPY, "wp06-scratch.php")) || !/wp06-scratch\.php/.test(fs.readFileSync(path.join(COPY, "doughboss-growth.php"), "utf8"))) {
  process.stdout.write(`NOT RUN  the scratch preparation is missing in ${COPY} (see the header of this script)\n`);
  process.exit(2);
}

const browser = await chromium.launch();
const originalClaims = fs.existsSync(CLAIMS) ? fs.readFileSync(CLAIMS, "utf8") : null;
let fatal = null;
let adminContext = null;
let pageIds = {};
try {
  fs.writeFileSync(SEOFLAG, "0");
  adminContext = await browser.newContext();
  await adminLogin(adminContext);
  let h = await health(adminContext);
  record("starting state: every flag is off on the runtime", Object.values(h.flags_configured).every((v) => v === false), `core ${h.core_version}, companion ${h.plugin_version}`);
  record("the landing module is present on disk and not active while its flags are off", h.modules_present.landing === true && h.modules_active.landing === false);

  const leftovers = (await listPages(adminContext)).filter((p) => /\[doughboss_growth_landing key="/.test((p.content && p.content.raw) || ""));
  for (const p of leftovers) await deletePage(adminContext, p.id);
  if (leftovers.length) note(`removed ${leftovers.length} landing page(s) left by an earlier run, so this run starts from nothing`);

  const seed = await (await adminContext.request.get(`${BASE}/?wp06_seed=1`)).json();
  record("scratch seed: two fake-address shops and the test packages exist in core", seed.shop_bankstown > 0 && seed.shop_roselands > 0, JSON.stringify(seed));
  const shops = await (await adminContext.request.get(REST("/doughboss/v1/locations"))).json();
  const shop = Object.fromEntries(shops.map((s) => [s.slug, s]));
  record("core reports the three shops by slug", ["revesby", "bankstown", "roselands"].every((s) => shop[s]), Object.keys(shop).join(", "));
  const packages = await (await adminContext.request.get(REST("/doughboss/v1/catering/packages"))).json();

  /* ---------- A: flags off ---------- */
  const CAP = path.join(STATE, "capture");
  capture(path.join(CAP, "A-baseline"));
  note("hub baseline captured (/, /order/, /catering/, /locations/) with every companion flag off");
  {
    const context = await browser.newContext();
    for (const p of ["/catering/corporate/", "/locations/revesby/"]) {
      const r = await context.request.get(BASE + p);
      const body = await r.text();
      record(`A flags off: ${p} serves no landing page (no companion markup, title or head tag)`, !/dbgr-lp|data-dbgr-landing|Shop Details \| Dough Boss|Enquiries \| Dough Boss/.test(body), `HTTP ${r.status()}`);
    }
    const stray = await (await context.request.get(BASE + "/catering/no-such-page-here/")).status();
    note(`this runtime answers HTTP ${stray} for a path under /catering/ that does not exist at all (a Playground/core routing quirk, not the companion), so "does not exist" is checked by content, not by status`);
    await context.close();
  }

  /* ---------- B: flags on, pages created as drafts ---------- */
  const saved = await saveSettings(adminContext, { "dbgr[features][landing_pages]": "1", "dbgr[features][seo_head]": "1" });
  h = await health(adminContext);
  record("B flags saved: landing_pages and seo_head are effective and the landing module is active", saved.status === 302 && h.flags.landing_pages === true && h.flags.seo_head === true && h.modules_active.landing === true, JSON.stringify({ flags: h.flags.landing_pages, active: h.modules_active.landing }));

  const tabUrl = `${BASE}/wp-admin/admin.php?page=doughboss-growth&tab=landing`;
  let tab = await (await adminContext.request.get(tabUrl)).text();
  record("B admin tab: lists all six contract paths as not created yet, with the create button", KEYS.length === 6 && ["/catering/corporate/", "/catering/office-breakfast/", "/catering/events/", "/locations/revesby/", "/locations/bankstown/", "/locations/roselands/"].every((p) => tab.includes(p)) && (tab.match(/Not created yet/g) || []).length === 6 && tab.includes('value="doughboss_growth_create_pages"'));
  record("B admin tab: shows each page's title and description for an SEO plugin to paste", tab.includes('value="Corporate Catering Enquiries | Dough Boss"') && tab.includes('value="Revesby Shop Details | Dough Boss"') && tab.includes("Dough Boss Revesby shop: address, phone number and opening hours."));
  record("B admin tab: states why the core catering form is left out (the product name in core copy)", /core catering form is left out because its own text fails the public-copy lint/.test(tab));
  record("B admin tab: waiting claims are listed as gaps", tab.includes("claim:catering-lead-time") && tab.includes("Waiting for a confirmed, sourced claim in the ledger."));

  // CSRF and anonymous attempts create nothing.
  {
    const anon = await browser.newContext();
    const r = await anon.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: "doughboss_growth_create_pages" }, maxRedirects: 0 });
    record("B negative control: an anonymous POST to the create action is refused", r.status() >= 400 || /wp-login/.test(r.headers().location || ""), `HTTP ${r.status()}`);
    await anon.close();
    const forged = await adminContext.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: "doughboss_growth_create_pages", _wpnonce: "0123456789" }, maxRedirects: 0 });
    record("B negative control: a forged nonce is refused (403)", forged.status() === 403, `HTTP ${forged.status()}`);
    const other = await nonceFrom(adminContext, `${BASE}/wp-admin/admin.php?page=doughboss-growth`, "doughboss_growth_save_settings");
    const wrongAction = await adminContext.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: "doughboss_growth_create_pages", _wpnonce: other }, maxRedirects: 0 });
    record("B negative control: the settings nonce does not work for the create action (403)", wrongAction.status() === 403, `HTTP ${wrongAction.status()}`);
    const none = (await listPages(adminContext)).filter((p) => /doughboss_growth_landing/.test((p.content && p.content.raw) || ""));
    record("B none of those attempts created a page", none.length === 0, `${none.length} page(s)`);
  }

  const createNonce = await nonceFrom(adminContext, tabUrl, "doughboss_growth_create_pages");
  const created = await adminContext.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: "doughboss_growth_create_pages", _wpnonce: createNonce }, maxRedirects: 0 });
  const loc = decodeURIComponent(created.headers().location || "");
  record("B create button: redirects back to the tab reporting six created", created.status() === 302 && KEYS.every((k) => loc.includes(`${k}.created`)), loc.replace(BASE, "").slice(0, 200));

  const all = await listPages(adminContext);
  const mine = all.filter((p) => /\[doughboss_growth_landing key="/.test((p.content && p.content.raw) || ""));
  const catering = all.find((p) => p.slug === "catering" && p.parent === 0);
  const locations = all.find((p) => p.slug === "locations" && p.parent === 0);
  record("B six pages were created", mine.length === 6, mine.map((p) => p.slug).sort().join(", "));
  record("B every one is a DRAFT", mine.every((p) => p.status === "draft"), mine.map((p) => p.status).join(","));
  const wantPath = (k) => (k.startsWith("catering") ? "catering/" : "locations/") + readDef(k).slug;
  record(
    "B each is a child of the existing Catering or Locations page with the contract slug and exactly the shortcode as its body",
    KEYS.every((k) => {
      const p = mine.find((m) => m.content.raw === `[doughboss_growth_landing key="${k}"]`);
      return p && p.slug === readDef(k).slug && p.parent === (k.startsWith("catering") ? catering.id : locations.id);
    }),
    KEYS.map(wantPath).join(", ")
  );
  record("B the hub pages were not edited", !!catering && !!locations && catering.status === "publish" && locations.status === "publish" && !/doughboss_growth/.test(catering.content.raw + locations.content.raw));
  pageIds = Object.fromEntries(KEYS.map((k) => [k, mine.find((m) => m.content.raw === `[doughboss_growth_landing key="${k}"]`).id]));

  const second = await adminContext.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: "doughboss_growth_create_pages", _wpnonce: await nonceFrom(adminContext, tabUrl, "doughboss_growth_create_pages") }, maxRedirects: 0 });
  const loc2 = decodeURIComponent(second.headers().location || "");
  const mine2 = (await listPages(adminContext)).filter((p) => /\[doughboss_growth_landing key="/.test((p.content && p.content.raw) || ""));
  record("B pressing the button again is a no-op: all six report exists and no page is duplicated", KEYS.every((k) => loc2.includes(`${k}.exists`)) && mine2.length === 6, loc2.replace(BASE, "").slice(0, 160));
  tab = await (await adminContext.request.get(tabUrl)).text();
  record("B admin tab now shows Draft for each page with an Edit link", (tab.match(/>Draft</g) || []).length >= 6 && (tab.match(/post\.php\?post=\d+&#038;action=edit|post\.php\?post=\d+&amp;action=edit/g) || []).length >= 6);

  {
    const context = await browser.newContext();
    for (const p of ["/catering/corporate/", "/locations/revesby/"]) {
      const r = await context.request.get(BASE + p);
      record(`B a draft is not public: ${p} is 404 for a visitor`, r.status() === 404, `HTTP ${r.status()}`);
    }
    await context.close();
  }
  capture(path.join(CAP, "B-drafts"));
  const cmpB = compare(path.join(CAP, "A-baseline"), path.join(CAP, "B-drafts"));
  record("B hub pages unchanged: /, /order/, /catering/ and /locations/ are byte-identical to the flags-off baseline with the drafts in place", cmpB.ok, cmpB.text.split("\n").filter(Boolean).slice(-1)[0]);

  /* ---------- C: published ---------- */
  for (const k of KEYS) await setPageStatus(adminContext, pageIds[k], "publish");
  note("the six pages were published through the REST API as the owner would in wp-admin");
  capture(path.join(CAP, "C-published"));
  const cmpC = compare(path.join(CAP, "A-baseline"), path.join(CAP, "C-published"));
  // WordPress core itself adds the body class "page-parent" to a page that has a published child. Prove that is the ONLY difference:
  // the normalised HTML is identical once that one token is removed, and the asset list, headers and cookie names are unchanged.
  const sumA = JSON.parse(fs.readFileSync(path.join(CAP, "A-baseline", "summary.json"), "utf8"));
  const sumC = JSON.parse(fs.readFileSync(path.join(CAP, "C-published", "summary.json"), "utf8"));
  const onlyParentClass = ["catering", "locations"].every((slug) => {
    const ta = fs.readFileSync(path.join(CAP, "A-baseline", slug + ".html"), "utf8");
    const tc = fs.readFileSync(path.join(CAP, "C-published", slug + ".html"), "utf8");
    return tc.replace(" page-parent ", " ") === ta && JSON.stringify(sumA.pages[slug].assets) === JSON.stringify(sumC.pages[slug].assets) && JSON.stringify(sumA.pages[slug].headers) === JSON.stringify(sumC.pages[slug].headers) && !sumC.pages[slug].mentionsCompanion;
  });
  const cmpCn = { ok: onlyParentClass, text: onlyParentClass ? "identical except the page-parent body class; assets, headers unchanged; no companion mention" : "differs by more than page-parent" };
  const differing = (cmpC.text.match(/^DIFFERENT\s+(\S+)/gm) || []).map((l) => l.replace(/^DIFFERENT\s+/, "").replace(/:$/, ""));
  record("C hub pages: published children change nothing but WordPress's own body class page-parent (/catering/ and /locations/); / and /order/ are byte-identical", differing.sort().join(",") === "/catering/,/locations/" && cmpCn.ok, `strict: ${differing.join(", ") || "none"}; with page-parent removed: ${cmpCn.text.split("\n").filter(Boolean).slice(-1)[0]}`);
  note("with the six pages still DRAFT the hub pages are byte-identical (checked in phase B); publishing them makes WordPress add the page-parent body class to the two hub pages, which is core behaviour and not companion output");

  const desktop = { ...devices["Desktop Chrome"], viewport: { width: 1280, height: 900 } };
  const titles = [];
  const descriptions = [];
  const firstLines = (s) => s.split(/\r?\n/).map((x) => x.trim()).filter(Boolean);
  {
    const context = await browser.newContext(desktop);
    const page = await context.newPage();
    const errors = [];
    collectErrors(page, errors);

    // Control: a core page DOES carry core's JSON-LD, so the "zero core JSON-LD" check below means something.
    await page.goto(BASE + "/locations/", { waitUntil: "load" });
    const homeFacts = await headFacts(page);
    const homeLd = homeFacts.ld.join(" ");
    record("C control: the core /locations/ page carries core's own JSON-LD (an Organization graph), so the zero-core-JSON-LD check below means something", homeFacts.ldCount >= 1 && /"Organization"/.test(homeLd), `${homeFacts.ldCount} script(s)`);

    for (const key of KEYS) {
      const def = readDef(key);
      const isShop = key.startsWith("locations");
      const urlPath = `/${isShop ? "locations" : "catering"}/${def.slug}/`;
      const resp = await page.goto(BASE + urlPath, { waitUntil: "load" });
      const html = await page.content();
      const f = await headFacts(page);
      const slug = isShop ? def.location_slug : null;
      const s = isShop ? shop[slug] : null;
      let expectTitle;
      let expectDesc;
      if (isShop) {
        const hasPhone = !!s.phone;
        const hasHours = slug !== "roselands";
        const parts = ["address"].concat(hasPhone ? ["phone number"] : [], hasHours ? ["opening hours"] : []);
        const last = parts.pop();
        const details = parts.length ? `${parts.join(", ")} and ${last}` : last;
        expectTitle = `${s.name} Shop Details | ${BRAND}`;
        expectDesc = def.description.replace("{name}", s.name).replace("{details}", details);
      } else {
        expectTitle = `${def.title} | ${BRAND}`;
        expectDesc = def.description;
      }
      titles.push(f.title);
      descriptions.push(f.description);
      record(`C ${urlPath}: HTTP 200 for a visitor`, resp.status() === 200, `HTTP ${resp.status()}`);
      record(`C ${urlPath}: exactly one <title>, and it is the companion title`, f.titleTags === 1 && f.title === expectTitle, `${f.titleTags} tag(s): ${f.title}`);
      record(`C ${urlPath}: exactly one meta description, and it is the companion description`, f.descriptions === 1 && f.description === expectDesc, f.description);
      record(`C ${urlPath}: exactly one canonical (WordPress core's) and it is the page URL; og:url matches`, f.canonicals === 1 && f.canonical === BASE + urlPath && f.ogUrl === BASE + urlPath, `${f.canonicals}: ${f.canonical}`);
      record(`C ${urlPath}: Open Graph and Twitter tags are present`, f.ogCount >= 6 && f.twitterCount >= 3 && f.ogTitle === expectTitle, `${f.ogCount} og, ${f.twitterCount} twitter`);
      record(`C ${urlPath}: exactly one JSON-LD script (the companion's) and no core graph`, f.ldCount === 1 && !/"Organization"|"WebSite"/.test(f.ld[0]) && !/"@type":\s*"(Organization|WebSite)"/.test(f.ld[0]), `${f.ldCount} script(s)`);
      let graph = null;
      try {
        graph = JSON.parse(f.ld[0]);
      } catch {
        graph = null;
      }
      record(`C ${urlPath}: the JSON-LD parses and is a schema.org @graph with a BreadcrumbList`, !!graph && graph["@context"] === "https://schema.org" && graph["@graph"].some((n) => n["@type"] === "BreadcrumbList"));
      const crumbs = graph ? graph["@graph"].find((n) => n["@type"] === "BreadcrumbList").itemListElement : [];
      record(`C ${urlPath}: breadcrumb is Home, the hub page and this page, with real URLs`, crumbs.length === 3 && crumbs[0].item === BASE + "/" && crumbs[1].item === BASE + (isShop ? "/locations/" : "/catering/") && crumbs[2].item === BASE + urlPath, crumbs.map((c) => c.name).join(" > "));
      const text = JSON.stringify(graph);
      record(`C ${urlPath}: JSON-LD has no geo, sameAs, rating, price range, menu or image`, !/"geo"|"sameAs"|"aggregateRating"|"review"|"priceRange"|"hasMenu"|"servesCuisine"|"image"/.test(text));
      record(`C ${urlPath}: grep -i for the unannounced product name finds nothing in the whole page source`, !new RegExp("mini" + "s", "i").test(html), `${(html.match(new RegExp("mini" + "s", "gi")) || []).length} match(es)`);
      record(`C ${urlPath}: no raw companion shortcode text`, !/\[doughboss_growth_/.test(html));
      record(`C ${urlPath}: the landing stylesheet is loaded and applied`, (await page.locator('link[id="dbgr-landing-css"]').count()) === 1 && (await page.evaluate(() => getComputedStyle(document.querySelector(".dbgr-lp")).maxWidth)) === "896px");
      record(`C ${urlPath}: a visible breadcrumb names the hub page`, (await page.locator(".dbgr-lp__crumbs li").allInnerTexts()).length === 3);

      if (isShop) {
        const visible = (await page.locator(".dbgr-lp").innerText()).replace(/\s+/g, " ");
        const addrFirst = firstLines(s.address)[0];
        record(`C ${urlPath}: the address on the page is core's address`, firstLines(s.address).every((l) => visible.includes(l)), s.address.replace(/\n/g, " / "));
        if (s.phone) {
          record(`C ${urlPath}: the phone is core's phone with a tel: link`, visible.includes(s.phone) && (await page.locator('.dbgr-lp a[href^="tel:"]').count()) === 1);
        } else {
          record(`C ${urlPath}: core has no phone, so the page shows none`, (await page.locator(".dbgr-lp__phone").count()) === 0);
        }
        const node = graph["@graph"].find((n) => Array.isArray(n["@type"]));
        record(`C ${urlPath}: shop node @id is core's ${BASE}/#location-${slug} and its url is this page`, !!node && node["@id"] === `${BASE}/#location-${slug}` && node.url === BASE + urlPath, node ? node["@id"] : "no node");
        record(`C ${urlPath}: shop node address, telephone and name come from core`, !!node && node.address.streetAddress === addrFirst && (s.phone ? node.telephone === s.phone : !("telephone" in node)) && node.name === s.name);
        const specs = node && node.openingHoursSpecification ? node.openingHoursSpecification.length : 0;
        const wantSpecs = { revesby: 4, bankstown: 1, roselands: 0 }[slug];
        record(`C ${urlPath}: opening hours come from core's weekly hours (${wantSpecs} range(s))`, specs === wantSpecs && (wantSpecs === 0 ? (await page.locator(".dbgr-lp__hours").count()) === 0 : (await page.locator(".dbgr-lp__hours dt").count()) > 0), `${specs} spec(s)`);
        if (slug === "revesby") {
          const hours = (await page.locator(".dbgr-lp__hours").innerText()).replace(/\s+/g, " ");
          record(`C ${urlPath}: hours read naturally (6:30am to 2:30pm, two Saturday ranges) and a day with no hours is not listed as closed`, /Monday 6:30am to 2:30pm/.test(hours) && /Saturday 7am to 12pm, 1pm to 3pm/.test(hours) && !/Wednesday|closed/i.test(hours), hours);
        }
        record(`C ${urlPath}: a shop page is indexable (no noindex)`, !f.robots.some((r) => /noindex/.test(r)), JSON.stringify(f.robots));
        record(`C ${urlPath}: a shop page shows no price`, !/\$\d/.test(visible));
      } else {
        const texts = (sel) => page.locator(sel).evaluateAll((els) => els.map((e) => e.textContent.trim()));
        const names = await texts(".dbgr-lp__package-name");
        const prices = await texts(".dbgr-lp__price");
        const want = packages.filter((p) => p.price > 0);
        record(`C ${urlPath}: the package cards are core's published packages that have a price, in core's order`, names.join("|") === want.map((p) => p.name).join("|") && names.length === 2, names.join("|"));
        record(`C ${urlPath}: each card price equals core's price`, prices.join("|") === want.map((p) => "$" + p.price.toFixed(2)).join("|"), prices.join("|"));
        record(`C ${urlPath}: a package with no price and a draft package are not shown`, !names.includes("Test Box No Price") && !names.includes("Test Box Draft"));
        const service = graph["@graph"].find((n) => n["@type"] === "Service");
        record(`C ${urlPath}: Service node: provider is core's organisation id, offers equal core prices exactly`, !!service && service.provider["@id"] === `${BASE}/#organization` && service.offers.length === want.length && service.offers.every((o, i) => o.name === want[i].name && parseFloat(o.price) === want[i].price && o.price === want[i].price.toFixed(2) && o.priceCurrency === "AUD"), service ? service.offers.map((o) => o.name + " " + o.price).join(", ") : "none");
        record(`C ${urlPath}: no service area is claimed (areaServed absent) while the claim is unconfirmed`, !!service && !("areaServed" in service));
        record(`C ${urlPath}: no ledger claim block is shown (every claim is a gap)`, (await page.locator(".dbgr-lp__block--claim").count()) === 0 && !/where we cater|delivery or drop-off|lead time|wording to be supplied/i.test(await page.locator(".dbgr-lp").innerText()));
        record(`C ${urlPath}: the core catering form is NOT embedded (its copy names the unannounced product), and core's catering assets are not loaded`, (await page.locator("[data-doughboss-catering]").count()) === 0 && !/doughboss-catering\.(css|js)/.test(html));
        record(`C ${urlPath}: with no content of its own yet the page is marked noindex`, f.robots.some((r) => /noindex/.test(r)), JSON.stringify(f.robots));
      }
      await page.screenshot({ path: path.join(SHOTS, `wp06-${key}-desktop.png`), fullPage: true });
      await runAxe(page, `${urlPath}, desktop`, ".dbgr-lp");
      if (key === "locations-revesby" || key === "catering-corporate") {
        const mobile = await browser.newContext({ ...devices["Pixel 7"] });
        const mp = await mobile.newPage();
        await mp.goto(BASE + urlPath, { waitUntil: "load" });
        const overflow = await mp.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        record(`C ${urlPath}: no horizontal scroll on a phone`, overflow <= 0, `overflow ${overflow}px`);
        await mp.screenshot({ path: path.join(SHOTS, `wp06-${key}-mobile.png`), fullPage: true });
        await runAxe(mp, `${urlPath}, mobile`, ".dbgr-lp");
        await mobile.close();
      }
    }
    record("C all six titles are unique", new Set(titles).size === 6, titles.join(" || "));
    record("C all six descriptions are unique", new Set(descriptions).size === 6);
    record("C no first-party console errors on any landing page", errors.length === 0, errors.slice(0, 3).join(" | "));
    await context.close();
  }

  {
    const context = await browser.newContext();
    const sitemap = await (await context.request.get(`${BASE}/wp-sitemap-posts-page-1.xml`)).text();
    record("C the six pages appear in WordPress's own sitemap", KEYS.every((k) => sitemap.includes(`/${k.startsWith("catering") ? "catering" : "locations"}/${readDef(k).slug}/`)), `${(sitemap.match(/<loc>/g) || []).length} page URLs`);
    for (const p of ["/catering/", "/locations/"]) {
      const html = await (await context.request.get(BASE + p)).text();
      record(`C hub ${p}: carries no companion head tag, stylesheet or JSON-LD of ours`, !/dbgr-landing|dbgr-lp|Shop Details \| Dough Boss/.test(html));
    }
    await context.close();
  }

  /* ---------- D: a confirmed claim ---------- */
  if (originalClaims !== null) {
    const claims = JSON.parse(originalClaims);
    const lead = claims.claims.find((c) => c.id === "catering-lead-time");
    lead.text = "Scratch lead time wording from the owner";
    lead.confirmed = true;
    lead.source = { kind: "owner-confirmed", ref: "wp06 scratch run", confirmedOn: "2026-10-02" };
    fs.writeFileSync(CLAIMS, JSON.stringify(claims, null, 2));
    const context = await browser.newContext(desktop);
    const page = await context.newPage();
    await page.goto(BASE + "/catering/corporate/", { waitUntil: "load" });
    const f = await headFacts(page);
    const body = await page.locator(".dbgr-lp").innerText();
    record("D a confirmed, sourced claim appears exactly as written under its neutral heading", (await page.locator(".dbgr-lp__block--claim .dbgr-lp__text").evaluateAll((els) => els.map((e) => e.textContent.trim()))).join("|") === "Scratch lead time wording from the owner" && /lead time/i.test(body));
    record("D the other, unconfirmed claims still do not appear", !/where we cater|delivery or drop-off/i.test(body));
    record("D with a claim of its own the page is no longer noindex", !f.robots.some((r) => /noindex/.test(r)), JSON.stringify(f.robots));
    fs.writeFileSync(CLAIMS, originalClaims);
    await page.goto(BASE + "/catering/corporate/", { waitUntil: "load" });
    record("D restoring the ledger removes the claim again", (await page.locator(".dbgr-lp__block--claim").count()) === 0);
    await context.close();
  }

  /* ---------- E: an SEO plugin is active ---------- */
  {
    fs.writeFileSync(SEOFLAG, "1");
    const context = await browser.newContext(desktop);
    const page = await context.newPage();
    await page.goto(BASE + "/locations/revesby/", { waitUntil: "load" });
    let f = await headFacts(page);
    record("E SEO plugin active: the companion prints no description, no Open Graph or Twitter tags and no JSON-LD", f.descriptions === 0 && f.ogCount === 0 && f.twitterCount === 0 && f.ldCount === 0, `${f.descriptions} desc, ${f.ogCount} og, ${f.ldCount} ld`);
    record("E SEO plugin active: the title is WordPress's own, not the companion's", f.titleTags === 1 && !/Shop Details \| Dough Boss/.test(f.title), f.title);
    record("E SEO plugin active: the page body still renders", (await page.locator(".dbgr-lp__address").count()) === 1);
    record("E SEO plugin active: WordPress still prints its single canonical", f.canonicals === 1);
    const tabHtml = await (await adminContext.request.get(tabUrl)).text();
    record("E the admin tab says an SEO plugin is active and still lists the title and description to paste", /An SEO plugin is active: the companion prints nothing in the page head/.test(tabHtml) && tabHtml.includes('value="Revesby Shop Details | Dough Boss"'));
    await saveSettings(adminContext, { "dbgr[features][landing_pages]": "1", "dbgr[features][seo_head]": "1", "dbgr[seo_jsonld_with_seo_plugin]": "1" });
    await page.goto(BASE + "/locations/revesby/", { waitUntil: "load" });
    f = await headFacts(page);
    record("E seo_jsonld_with_seo_plugin on: exactly one JSON-LD graph and still no description, Open Graph tags or companion title", f.ldCount === 1 && f.descriptions === 0 && f.ogCount === 0 && !/Shop Details \| Dough Boss/.test(f.title), `${f.ldCount} ld, ${f.descriptions} desc`);
    await saveSettings(adminContext, { "dbgr[features][landing_pages]": "1", "dbgr[features][seo_head]": "1" });
    fs.writeFileSync(SEOFLAG, "0");
    await page.goto(BASE + "/locations/revesby/", { waitUntil: "load" });
    f = await headFacts(page);
    record("E SEO plugin gone and the option off again: the companion head is back", f.descriptions === 1 && f.ldCount === 1 && /Shop Details \| Dough Boss/.test(f.title));
    await context.close();
  }

  /* ---------- F: flags off after publish ---------- */
  {
    await saveSettings(adminContext, {});
    h = await health(adminContext);
    record("F every flag is off again", Object.values(h.flags).every((v) => v === false));
    const context = await browser.newContext(desktop);
    const page = await context.newPage();
    for (const p of ["/locations/revesby/", "/catering/corporate/"]) {
      const resp = await page.goto(BASE + p, { waitUntil: "load" });
      const f = await headFacts(page);
      const html = await page.content();
      record(`F flags off: ${p} prints no companion head tag, stylesheet or JSON-LD`, f.descriptions === 0 && f.ldCount === 0 && f.ogCount === 0 && !/dbgr-landing|dbgr-lp/.test(html) && !/Shop Details \| Dough Boss|Enquiries \| Dough Boss/.test(f.title), `${f.descriptions} desc, ${f.ldCount} ld`);
      offCheck(`F flags off: ${p} never shows the raw shortcode text`, !/\[doughboss_growth_landing/.test(html), `HTTP ${resp.status()}`);
    }
    await context.close();
  }

  /* ---------- G: rollback by deactivation ---------- */
  {
    await saveSettings(adminContext, { "dbgr[features][landing_pages]": "1", "dbgr[features][seo_head]": "1" });
    const plugins = await (await adminContext.request.get(`${BASE}/wp-admin/plugins.php`)).text();
    const link = (plugins.match(/href="([^"]*plugins\.php\?action=deactivate&(?:amp;|#038;)plugin=doughboss-growth[^"]*)"/) || [])[1];
    if (!link) {
      note("G could not find the deactivate link for the companion on plugins.php");
      record("G deactivating the plugin moves the six pages to draft", false, "deactivate link not found");
    } else {
      const url = new URL(link.replace(/&#038;|&amp;/g, "&"), BASE + "/wp-admin/").toString();
      await adminContext.request.get(url);
      const after = (await listPages(adminContext)).filter((p) => /\[doughboss_growth_landing key="/.test((p.content && p.content.raw) || ""));
      record("G deactivating the companion moves all six pages to draft (no page can show a raw tag)", after.length === 6 && after.every((p) => p.status === "draft"), after.map((p) => p.status).join(","));
      const context = await browser.newContext();
      const r = await context.request.get(BASE + "/locations/revesby/");
      record("G a visitor now gets 404, not the raw shortcode", r.status() === 404, `HTTP ${r.status()}`);
      await context.close();
      const plugins2 = await (await adminContext.request.get(`${BASE}/wp-admin/plugins.php`)).text();
      const activate = (plugins2.match(/href="([^"]*plugins\.php\?action=activate&(?:amp;|#038;)plugin=doughboss-growth[^"]*)"/) || [])[1];
      if (activate) await adminContext.request.get(new URL(activate.replace(/&#038;|&amp;/g, "&"), BASE + "/wp-admin/").toString());
      h = await health(adminContext);
      record("G reactivated: the companion is back with its settings and the landing module active", h.flags_configured.landing_pages === true && h.modules_active.landing === true, JSON.stringify(h.flags_configured.landing_pages));
      const kept = (await listPages(adminContext)).filter((p) => /\[doughboss_growth_landing key="/.test((p.content && p.content.raw) || ""));
      record("G the six page records survive deactivation (still drafts, content unchanged)", kept.length === 6 && kept.every((p) => p.status === "draft"));
      const again = await adminContext.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: "doughboss_growth_create_pages", _wpnonce: await nonceFrom(adminContext, tabUrl, "doughboss_growth_create_pages") }, maxRedirects: 0 });
      const l3 = decodeURIComponent(again.headers().location || "");
      record("G after reactivation the create button finds all six already there (no duplicates)", KEYS.every((k) => l3.includes(`${k}.exists`)), l3.replace(BASE, "").slice(0, 160));
    }
  }
} catch (e) {
  fatal = e;
  record("the script ran to the end without an exception", false, String((e && e.stack) || e).slice(0, 500));
} finally {
  try {
    if (originalClaims !== null) fs.writeFileSync(CLAIMS, originalClaims);
    fs.writeFileSync(SEOFLAG, "0");
    if (adminContext) {
      await saveSettings(adminContext, {});
      for (const id of Object.values(pageIds)) {
        try {
          await setPageStatus(adminContext, id, "draft");
        } catch {
          /* already a draft */
        }
      }
    }
  } catch (e) {
    note("cleanup problem: " + e.message);
  }
  await browser.close();
}

const failed = checks.filter((c) => !c.ok);
fs.writeFileSync(path.join(SHOTS, "wp06-checks.json"), JSON.stringify(checks, null, 2));
process.stdout.write(`\n${checks.length - failed.length} passed, ${failed.length} failed (${checks.length} checks). Screenshots and wp06-checks.json: ${SHOTS}\n`);
process.exit(failed.length || fatal ? 1 : 0);
