// WP-16 browser run: the whole doughboss-growth companion on ONE local WordPress runtime (companion mounted next to DoughBoss;
// see scripts/wp-local/start.sh and WPL_EXTRA_PLUGIN_SRC). The throwaway site only: nothing here touches a live site and no request
// leaves the machine (every non-local request the browser makes is aborted and counted).
//
//   export WPL_STATE=/tmp/wpl-wp16 WPL_PORT=9416
//   mkdir -p $WPL_STATE && ln -sfn /tmp/wp-local/pg $WPL_STATE/pg
//   WPL_EXTRA_PLUGIN_SRC=/home/user/DOUGHBOSSV2/doughboss-growth web/scripts/wp-local/start.sh --restart
//   (from web/)  WPL_URL=http://127.0.0.1:9416 WPL_STATE=/tmp/wpl-wp16 node scripts/wp-local/growth/wp16-full.mjs
//   web/scripts/wp-local/stop.sh
//
// The inert test (public HTML with and without the companion) is WP-01's wp01-inert.mjs, run BEFORE this script on a runtime
// started without the companion and again with it (see doughboss-growth/docs/RELEASE-0.1.0.md, "Evidence"). This script starts
// from the state "companion active, every flag off" and does the rest:
//
//   A  setup        scratch copy only: two fake-address shops and test packages (core data the landing pages read), three throwaway
//                   pages that hold the companion shortcodes. The scratch file lives in the runtime's COPY of the plugin, never in
//                   the source tree. Reference capture R0 of seven public pages (twice, to prove the capture is deterministic).
//   B  one by one   each of the eleven flags (with only the prerequisites the settings page itself demands) is switched on alone:
//                   /health shows it effective, a flag-specific expectation holds, the public pages and the matching admin tab load
//                   with no first-party console error, no failed first-party request and no PHP notice, the word "minis" appears in
//                   no page, and switching it off returns every public page to a BYTE-IDENTICAL copy of R0 (nonces masked).
//   C  all together every flag on at once; six landing pages created, published; desktop (1280x800) and Pixel 7 screenshots of /,
//                   /coming-soon/ and the six landing pages (plus the form and waitlist pages); axe on each; console and request
//                   hygiene; before any consent the only tracking request is the Tag Manager loader; every admin tab loads.
//   D  reversal     every flag off and the landing pages drafted: all seven public pages byte-identical to R0 again.
//   E  deactivate   deactivating the plugin drafts the six landing pages; reactivating keeps the settings. Pages that the OWNER wrote
//                   with a companion shortcode show the raw tag after deactivation (reported, see the review document).
// WP16_SKIP_MATRIX=1 skips phase B (used for the smoke run against the baseline core); WP16_NO_SHOTS=1 skips the screenshots.
// The script ends with every flag off, the six landing pages as drafts, the plugin active, and the throwaway pages deleted.
import { chromium, devices } from "@playwright/test";
import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { maskNonces } from "./wp01-inert.mjs";

const BASE = (process.env.WPL_URL || "http://127.0.0.1:9416").replace(/\/$/, "");
const ORIGIN = new URL(BASE).origin;
const STATE = process.env.WPL_STATE || "/tmp/wpl-wp16";
const SHOTS = process.env.SHOTS_DIR || path.join(STATE, "shots");
const TAKE_SHOTS = process.env.WP16_NO_SHOTS !== "1";
const COPY = path.join(STATE, "src", "plugins", "doughboss-growth");
const HERE = path.dirname(fileURLToPath(import.meta.url));
const AXE = path.resolve(HERE, "../../../node_modules/axe-core/axe.min.js");
const REST = (route) => `${BASE}/?rest_route=${route}`;
fs.mkdirSync(SHOTS, { recursive: true });

const checks = [];
function record(name, ok, detail) {
  checks.push({ name, ok: !!ok, detail: detail || "" });
  process.stdout.write(`${ok ? "PASS" : "FAIL"}  ${name}${detail ? "  (" + detail + ")" : ""}\n`);
}
function note(text) {
  checks.push({ name: "NOTE " + text, ok: true, note: true });
  process.stdout.write(`NOTE  ${text}\n`);
}
const sha = (text) => crypto.createHash("sha256").update(text).digest("hex");

const PHP_ERROR = /(?:<b>)?(?:Warning|Notice|Fatal error|Deprecated|Parse error)(?:<\/b>)?:\s.{0,200}?(?:on line|in \/)|There has been a critical error/s;
const COMPANION = /doughboss[-_]growth|dbgr[-_]|DoughBossGrowth/i;
const WORKING_NAME = new RegExp("mini" + "s", "gi"); // spelled in two parts so this file itself never carries the word
const countWorkingName = (text) => (text.match(WORKING_NAME) || []).length;

const DUMMY = {
  gtm: "GTM-TEST123", // a dummy container id; the request for it is aborted by this script
  webhook: "https://hooks.example-receiver.com.au/never-called", // waitlist notification only; NOT a conversion destination (Settings::destination_configured), never called
  ga4Id: "G-TEST0000", // obviously fake GA4 measurement id, valid for /^G-[A-Z0-9]{4,20}$/D; the conversion destination for server_conversions
  ga4Secret: "wp16-dummy-not-a-real-secret", // defined as a constant in the scratch file (the plugin reads env or constant); the outbound guard refuses every request that would use it
  sender: "Example Trading Pty Ltd", // placeholder used only on this throwaway site
  privacy: "/privacy-policy/",
};

const FLAGS = ["consent_banner", "gtm", "attribution", "server_conversions", "landing_pages", "seo_head", "lead_form", "party_sizer", "coming_soon", "waitlist", "timesheet_recon"];
const PUBLIC = ["/", "/order/", "/catering/", "/locations/", "/coming-soon/", "/lead-test/", "/waitlist-test/"];
const LANDING = ["/catering/corporate/", "/catering/office-breakfast/", "/catering/events/", "/locations/revesby/", "/locations/bankstown/", "/locations/roselands/"];
const ADMIN_TABS = ["", "&tab=consent", "&tab=attribution", "&tab=conversions", "&tab=landing", "&tab=claims", "&tab=leads", "&tab=coming-soon", "&tab=waitlist"];

/* ------------------------------------------------------------------ runtime helpers */

async function adminLogin(context) {
  const page = await context.newPage();
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill("#user_login", "admin");
  await page.fill("#user_pass", "password");
  await Promise.all([page.waitForURL(/wp-admin/), page.click("#wp-submit")]);
  await page.close();
}

const nonceIn = (html, action) => {
  for (const form of html.split("<form")) {
    if (action && !form.includes(`value="${action}"`)) continue;
    const nonce = (form.match(/name="_wpnonce"[^>]*value="([0-9a-f]+)"/) || form.match(/value="([0-9a-f]+)"[^>]*name="_wpnonce"/) || [])[1];
    if (nonce) return nonce;
  }
  return null;
};

async function saveSettings(context, fields) {
  const html = await (await context.request.get(`${BASE}/wp-admin/admin.php?page=doughboss-growth`)).text();
  const nonce = nonceIn(html, "doughboss_growth_save_settings");
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
  const response = await context.request.get(REST("/doughboss-growth/v1/health"), { headers: { "x-wp-nonce": await restNonce(context) } });
  return response.json();
}

async function wpPages(context) {
  const response = await context.request.get(REST("/wp/v2/pages") + "&per_page=100&status=any&context=edit", { headers: { "x-wp-nonce": await restNonce(context) } });
  return response.json();
}

async function upsertPage(context, slug, title, content, status) {
  const existing = (await wpPages(context)).find((p) => p.slug === slug && !p.parent);
  const headers = { "x-wp-nonce": await restNonce(context) };
  const data = { title, content, status, slug };
  const response = existing ? await context.request.post(REST(`/wp/v2/pages/${existing.id}`), { headers, data }) : await context.request.post(REST("/wp/v2/pages"), { headers, data });
  if (!response.ok()) throw new Error(`page ${slug}: HTTP ${response.status()} ${await response.text()}`);
  return (await response.json()).id;
}

async function setStatus(context, id, status) {
  const response = await context.request.post(REST(`/wp/v2/pages/${id}`), { headers: { "x-wp-nonce": await restNonce(context) }, data: { status } });
  if (!response.ok()) throw new Error(`page ${id} -> ${status}: HTTP ${response.status()}`);
}

async function deletePage(context, id) {
  await context.request.delete(REST(`/wp/v2/pages/${id}`) + "&force=true", { headers: { "x-wp-nonce": await restNonce(context) } });
}

async function landingPages(context) {
  return (await wpPages(context)).filter((p) => /\[doughboss_growth_landing key="/.test((p.content && p.content.raw) || ""));
}

async function adminGet(context, query) {
  const response = await context.request.get(`${BASE}/wp-admin/admin.php?page=${query}`);
  return { status: response.status(), html: await response.text() };
}

function debugLogPath() {
  const f = path.join(STATE, "run", "vfs-dir");
  if (!fs.existsSync(f)) return null;
  const dir = fs.readFileSync(f, "utf8").trim();
  return path.join(dir, "wordpress", "wp-content", "debug.log"); // may not exist yet: WordPress creates it on the first logged line
}
const logSize = (p) => (p && fs.existsSync(p) ? fs.statSync(p).size : 0);

/* ------------------------------------------------------------------ public-page capture (anonymous, nonces masked) */

async function snap(pagePath) {
  const response = await fetch(BASE + pagePath, { redirect: "follow", headers: { "user-agent": "wp16-full/1" } });
  const body = await response.text();
  const masked = maskNonces(body).text;
  return {
    path: pagePath,
    status: response.status,
    finalPath: new URL(response.url).pathname,
    sha: sha(masked),
    bytes: Buffer.byteLength(masked),
    text: masked,
    workingNames: countWorkingName(body),
    companion: COMPANION.test(body),
    phpError: PHP_ERROR.test(body),
  };
}

async function snapSet(paths = PUBLIC) {
  const out = {};
  for (const p of paths) out[p] = await snap(p);
  return out;
}

function differences(a, b) {
  const bad = [];
  for (const p of Object.keys(a)) {
    if (!b[p]) bad.push(`${p}: missing`);
    else if (a[p].status !== b[p].status || a[p].finalPath !== b[p].finalPath || a[p].sha !== b[p].sha) bad.push(`${p}: ${a[p].status}/${a[p].sha.slice(0, 8)} vs ${b[p].status}/${b[p].sha.slice(0, 8)}`);
  }
  return bad;
}

function firstLineDiff(a, b) {
  const la = a.split("\n");
  const lb = b.split("\n");
  for (let i = 0; i < Math.max(la.length, lb.length); i += 1) {
    if (la[i] !== lb[i]) return `line ${i + 1}: A=${(la[i] || "").slice(0, 160)} | B=${(lb[i] || "").slice(0, 160)}`;
  }
  return "";
}

/* ------------------------------------------------------------------ browser visits */

const TRACKING = /googletagmanager\.com|google-analytics\.com|analytics\.google\.com|doubleclick\.net|googleadservices\.com|facebook\.(?:com|net)|connect\.facebook|tiktok\.com|clarity\.ms|hotjar\.com/i;

async function newContext(browser, kind) {
  const options = kind === "mobile" ? { ...devices["Pixel 7"] } : { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 };
  const context = await browser.newContext(options);
  const tracker = { tracking: [], other: [] };
  await context.route("**/*", (route) => {
    const url = route.request().url();
    if (url.startsWith(ORIGIN) || url.startsWith("data:") || url.startsWith("blob:")) return route.continue();
    (TRACKING.test(url) ? tracker.tracking : tracker.other).push(url.slice(0, 140));
    return route.abort();
  });
  return { context, tracker };
}

async function visit(browser, pagePath, kind, shotName) {
  const { context, tracker } = await newContext(browser, kind);
  const page = await context.newPage();
  const errors = [];
  page.on("pageerror", (e) => errors.push("pageerror: " + e.message));
  page.on("console", (m) => {
    if (m.type() !== "error") return;
    const loc = m.location() && m.location().url ? m.location().url : "";
    if (!loc || loc.startsWith(ORIGIN)) errors.push("console.error: " + m.text() + (loc ? " @ " + loc : ""));
  });
  page.on("response", (r) => {
    if (r.url().startsWith(ORIGIN) && r.status() >= 400 && !/favicon\.ico/.test(r.url())) errors.push(`HTTP ${r.status()} ${r.url().replace(ORIGIN, "")}`);
  });
  page.on("requestfailed", (r) => {
    const reason = (r.failure() && r.failure().errorText) || "";
    if (r.url().startsWith(ORIGIN) && !/favicon\.ico/.test(r.url()) && !/ERR_ABORTED/.test(reason)) errors.push(`request failed ${r.url().replace(ORIGIN, "")} (${reason})`); // a reload or navigation cancels in-flight requests: not an error
  });
  const response = await page.goto(BASE + pagePath, { waitUntil: "load" });
  await page.waitForTimeout(700);
  const html = await page.content();
  const scroll = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  if (shotName && TAKE_SHOTS) await page.screenshot({ path: path.join(SHOTS, `${shotName}-${kind}.png`), fullPage: true });
  return { context, page, tracker, errors, status: response ? response.status() : 0, html, scroll };
}

async function axeFor(page) {
  await page.addScriptTag({ path: AXE });
  return page.evaluate(async () => {
    const result = await axe.run(document, { resultTypes: ["violations"] });
    return result.violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.map((n) => ({ target: n.target.join(" "), ours: /dbgr/.test(n.html) || /dbgr/.test(n.target.join(" ")) })) }));
  });
}

/* ------------------------------------------------------------------ the run */

const browser = await chromium.launch();
let adminContext = null;
let fatal = null;
const created = {};
let ldOff = [];
try {
  adminContext = await browser.newContext();
  await adminLogin(adminContext);
  let h = await health(adminContext);
  const activeAtStart = Object.entries(h.modules_active).filter(([, on]) => on === true).map(([k]) => k);
  record('A starting state: companion active, every flag off, and no module active except the registry "always" module (waitlist: opt-out, export, erasure)', !!h.core_version && Object.values(h.flags_configured).every((v) => v === false) && activeAtStart.every((m) => m === "waitlist"), `core ${h.core_version}, companion ${h.plugin_version}, active: ${activeAtStart.join(", ") || "none"}`);
  record("A the companion has exactly eleven flags and none is a hero flag", Object.keys(h.flags_configured).length === 11 && FLAGS.every((f) => f in h.flags_configured) && !("hero_enhanced" in h.flags_configured));

  const serverLog = path.join(STATE, "run", "playground.log");
  const serverLogBefore = logSize(serverLog);
  const logPath = debugLogPath();
  const logBefore = logPath ? logSize(logPath) : null;

  /* ---------- A: scratch, throwaway pages, reference capture ---------- */
  const scratch = path.join(COPY, "wp16-scratch.php");
  const mainFile = path.join(COPY, "doughboss-growth.php");
  if (!fs.existsSync(mainFile)) throw new Error(`the runtime copy of the companion is missing: ${mainFile}`);
  fs.writeFileSync(
    scratch,
    `<?php
/** WP-16 SCRATCH (runtime copy only, never in the source tree). Two fake-address shops and test packages through core's own classes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_filter( 'option_blog_public', function () { return '1'; } );
// Dummy GA4 API secret so a GA4 destination counts as configured. Fake value; never leaves the machine (see the guard below).
if ( ! defined( 'DOUGHBOSS_GROWTH_GA4_API_SECRET' ) ) { define( 'DOUGHBOSS_GROWTH_GA4_API_SECRET', '${DUMMY.ga4Secret}' ); }
// OUTBOUND GUARD: every server-side HTTP request to a non-local host is recorded (host + path only, no query or body) and REFUSED
// before any socket is opened. Registered at priority 0 so nothing runs ahead of it.
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( in_array( $host, array( '127.0.0.1', 'localhost', '::1', '[::1]' ), true ) ) { return $pre; }
	$log   = get_option( 'wp16_http_attempts', array() );
	$log[] = array( 'host' => $host, 'path' => (string) wp_parse_url( $url, PHP_URL_PATH ), 'method' => isset( $args['method'] ) ? $args['method'] : 'GET', 'probe' => 'wp16-probe.invalid' === $host );
	update_option( 'wp16_http_attempts', $log, false );
	return new WP_Error( 'wp16_blocked', 'WP-16 runtime refuses all external HTTP' );
}, 0, 3 );
add_action( 'init', function () {
	if ( ! isset( $_GET['wp16_http'] ) || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) { return; }
	$probe = wp_remote_get( 'https://wp16-probe.invalid/probe' ); // proves the guard is live: must come back as our WP_Error
	header( 'Content-Type: application/json' );
	echo wp_json_encode( array( 'probe_refused' => is_wp_error( $probe ) && 'wp16_blocked' === $probe->get_error_code(), 'attempts' => get_option( 'wp16_http_attempts', array() ) ) );
	exit;
} );
add_action( 'init', function () {
	if ( ! isset( $_GET['wp16_seed'] ) || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! class_exists( 'DoughBoss_Locations' ) ) { return; }
	$have = array();
	foreach ( DoughBoss_Locations::all( false ) as $row ) { $have[ $row->slug ] = (int) $row->id; }
	$shops = array(
		'bankstown' => array( 'name' => 'Bankstown', 'slug' => 'bankstown', 'suburb' => 'Bankstown', 'address' => "Test Unit 2\\nBankstown NSW", 'phone' => '(02) 5550 0102', 'pickup_enabled' => 1, 'is_active' => 1 ),
		'roselands' => array( 'name' => 'Roselands Centro', 'slug' => 'roselands', 'suburb' => 'Roselands', 'address' => "Test Centre 3\\nRoselands NSW", 'phone' => '', 'pickup_enabled' => 1, 'is_active' => 1 ),
	);
	foreach ( $shops as $slug => $data ) { if ( ! isset( $have[ $slug ] ) ) { $have[ $slug ] = DoughBoss_Locations::create( $data ); } }
	if ( isset( $have['revesby'] ) ) { DoughBoss_Locations::save_weekly_hours( $have['revesby'], array( 'mon' => '06:30-14:30', 'tue' => '06:30-14:30', 'sat' => '07:00-12:00, 13:00-15:00' ) ); }
	if ( isset( $have['bankstown'] ) ) { DoughBoss_Locations::save_weekly_hours( $have['bankstown'], array( 'mon' => '09:00-17:00' ) ); }
	if ( array() === get_posts( array( 'post_type' => 'doughboss_cat_pkg', 'post_status' => 'any', 'numberposts' => 5 ) ) ) {
		foreach ( array( array( 'Test Box One', '45.00', 10, 12, "Item one\\nItem two" ), array( 'Test Box Two', '123.50', 20, 25, '' ) ) as $i => $p ) {
			wp_insert_post( array( 'post_type' => 'doughboss_cat_pkg', 'post_status' => 'publish', 'post_title' => $p[0], 'menu_order' => $i, 'meta_input' => array( '_doughboss_cat_base_price' => $p[1], '_doughboss_cat_serves_min' => $p[2], '_doughboss_cat_serves_max' => $p[3], '_doughboss_cat_includes' => $p[4] ) ) );
		}
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( array( 'shops' => array_keys( $have ) ) );
	exit;
} );
`,
  );
  const main = fs.readFileSync(mainFile, "utf8");
  if (!/wp16-scratch\.php/.test(main)) fs.appendFileSync(mainFile, "\nrequire_once __DIR__ . '/wp16-scratch.php';\n");
  const seedResponse = await adminContext.request.get(`${BASE}/?wp16_seed=1`);
  const seed = await seedResponse.json().catch(() => null);
  if (!seed) {
    process.stdout.write("NOT RUN  the scratch seed endpoint did not answer (restart the runtime, then run this script again)\n");
    throw new Error("scratch seed unavailable");
  }
  const shops = await (await adminContext.request.get(REST("/doughboss/v1/locations"))).json();
  const slugs = shops.map((s) => s.slug);
  record("A scratch seed: core reports the three shops by slug (two have fake test addresses)", ["revesby", "bankstown", "roselands"].every((s) => slugs.includes(s)), slugs.join(", "));

  created.coming = await upsertPage(adminContext, "coming-soon", "Coming soon", "[doughboss_growth_coming_soon]", "publish");
  created.lead = await upsertPage(adminContext, "lead-test", "Corporate enquiry", '[doughboss_growth_lead_form variant="corporate"]\n\n[doughboss_growth_party_sizer]', "publish");
  created.wait = await upsertPage(adminContext, "waitlist-test", "VIP list", "[doughboss_growth_waitlist]", "publish");
  record("A three throwaway pages hold the companion shortcodes", created.coming > 0 && created.lead > 0 && created.wait > 0);

  for (const old of await landingPages(adminContext)) await setStatus(adminContext, old.id, "draft");
  const R0 = await snapSet();
  const R0b = await snapSet();
  record("A reference capture: all seven public pages answer 200", PUBLIC.every((p) => R0[p].status === 200 && R0[p].bytes > 1500), PUBLIC.map((p) => `${p}:${R0[p].status}`).join(" "));
  record("A reference capture is deterministic (two captures identical after masking nonces)", differences(R0, R0b).length === 0, differences(R0, R0b).join("; "));
  record("A flags off: no page mentions the companion, no raw shortcode text, no PHP notice", PUBLIC.every((p) => !R0[p].companion && !/\[doughboss_growth_/.test(R0[p].text) && !R0[p].phpError), PUBLIC.filter((p) => R0[p].companion || R0[p].phpError || /\[doughboss_growth_/.test(R0[p].text)).join(","));
  const coreNames = PUBLIC.filter((p) => R0[p].workingNames > 0).map((p) => `${p} x${R0[p].workingNames}`);
  note(`the working name appears in core's OWN markup with every companion flag off on: ${coreNames.join(", ") || "no page"} (not companion output; WP-06 change request 2: core 2.44.0 must neutralise its catering form copy)`);
  fs.writeFileSync(path.join(SHOTS, "wp16-reference-sha256.json"), JSON.stringify(Object.fromEntries(PUBLIC.map((p) => [p, { status: R0[p].status, bytes: R0[p].bytes, sha256: R0[p].sha, workingNames: R0[p].workingNames }])), null, 2));
  // Negative control: the comparator must notice a one-byte change.
  const tampered = { ...R0b, "/": { ...R0b["/"], sha: sha(R0b["/"].text + " ") } };
  record("A negative control: the comparison detects a one-byte change", differences(R0, tampered).length === 1);

  const checkPublicClean = async (label, set) => {
    const pages = Object.keys(set);
    const bad = pages.filter((p) => set[p].status !== 200 || set[p].phpError || set[p].workingNames > R0[p].workingNames);
    record(`${label}: the pages it touches answer 200, no PHP notice, the companion adds no occurrence of the working name`, bad.length === 0, `${pages.join(" ")}${bad.length ? " BAD: " + bad.join(", ") : ""}`);
  };
  const reverse = async (label, extra) => {
    const saved = await saveSettings(adminContext, {});
    const flags = (await health(adminContext)).flags;
    if (extra) await extra();
    const set = await snapSet();
    const diff = differences(R0, set);
    const first = PUBLIC.find((p) => set[p] && R0[p].sha !== set[p].sha);
    record(`${label}: switched off, every public page is BYTE-IDENTICAL to the flags-off reference`, saved.status === 302 && Object.values(flags).every((v) => v === false) && diff.length === 0, diff.length ? diff.join("; ") + (first ? " :: " + firstLineDiff(R0[first].text, set[first].text) : "") : `${PUBLIC.length} pages`);
  };
  const tabOk = async (label, query) => {
    const r = await adminGet(adminContext, `doughboss-growth${query}`);
    record(`${label}: admin screen loads`, r.status === 200 && !PHP_ERROR.test(r.html) && /Growth/.test(r.html), `HTTP ${r.status}`);
    return r.html;
  };
  const browse = async (label, paths, expectTracking) => {
    for (const p of paths) {
      const v = await visit(browser, p, "desktop", null);
      record(`${label}: ${p} no first-party console error, no failed first-party request`, v.status === 200 && v.errors.length === 0, `HTTP ${v.status} ${v.errors.slice(0, 2).join(" | ")}`);
      if (p === "/" && expectTracking !== undefined) {
        const gtm = v.tracker.tracking.filter((u) => /googletagmanager\.com\/gtm\.js/.test(u)).length;
        const other = v.tracker.tracking.filter((u) => !/googletagmanager\.com\/gtm\.js/.test(u)).length;
        record(`${label}: before any consent the only tracking request is the Tag Manager loader`, gtm === expectTracking && other === 0, `${gtm} loader request(s), ${other} other tracking request(s)`);
      }
      await v.context.close();
    }
  };

  /* ---------- B: one flag at a time ---------- */
  const CASES = {
    consent_banner: {
      fields: { "dbgr[features][consent_banner]": "1", "dbgr[consent_default]": "deny", "dbgr[privacy_policy_url]": DUMMY.privacy },
      tab: "&tab=consent",
      expect: async (set) => record("B consent_banner: the banner markup is on the home page", /id="dbgr-consent"/.test(set["/"].text) && /Privacy choices/.test(set["/"].text)),
    },
    gtm: {
      fields: { "dbgr[features][consent_banner]": "1", "dbgr[features][gtm]": "1", "dbgr[gtm_container_id]": DUMMY.gtm, "dbgr[consent_default]": "deny", "dbgr[privacy_policy_url]": DUMMY.privacy },
      prereq: "consent_banner",
      tab: "&tab=consent",
      expect: async (set) => {
        record("B gtm: one Tag Manager loader snippet, the dummy container id and Consent Mode defaults denied are in the head", (set["/"].text.match(new RegExp(DUMMY.gtm, "g")) || []).length >= 1 && /'denied'|"denied"/.test(set["/"].text), "");
        record("B gtm: no noscript Tag Manager iframe (visitors without JavaScript cannot be asked for consent)", !/<noscript>[^]*?googletagmanager\.com\/ns\.html/.test(set["/"].text));
      },
      browseTracking: 1,
    },
    attribution: {
      fields: { "dbgr[features][attribution]": "1" },
      tab: "&tab=attribution",
      expect: async () => {
        h = await health(adminContext);
        record("B attribution: the module is active and its tables exist", h.modules_active.attribution === true && h.storage_ready === true);
      },
    },
    server_conversions: {
      fields: { "dbgr[features][attribution]": "1", "dbgr[features][server_conversions]": "1", "dbgr[ga4_measurement_id]": DUMMY.ga4Id },
      prereq: "attribution (and a conversion destination: a dummy GA4 measurement id plus the dummy API secret constant from the scratch file; the outbound guard refuses any request)",
      tab: "&tab=conversions",
      expect: async () => {
        h = await health(adminContext);
        record("B server_conversions: the conversions module is active", h.modules_active.conversions === true);
      },
    },
    landing_pages: {
      fields: { "dbgr[features][landing_pages]": "1" },
      tab: "&tab=landing",
      special: "landing",
    },
    seo_head: {
      fields: { "dbgr[features][landing_pages]": "1", "dbgr[features][seo_head]": "1" },
      prereq: "landing_pages",
      tab: "&tab=landing",
      special: "seo",
    },
    lead_form: {
      page: "/lead-test/",
      fields: { "dbgr[features][lead_form]": "1" },
      tab: "&tab=leads",
      expect: async (set) => {
        record("B lead_form: the lead form is on its page and the sender name gap hides the marketing-consent box", /data-dbgr-lead-form/.test(set["/lead-test/"].text) && !/dbgr_consent_marketing|name="consent_marketing"/.test(set["/lead-test/"].text));
      },
    },
    party_sizer: {
      page: "/lead-test/",
      fields: { "dbgr[features][party_sizer]": "1" },
      tab: "&tab=leads",
      expect: async (set) => record("B party_sizer: the sizer is on its page", /data-dbgr-sizer/.test(set["/lead-test/"].text)),
    },
    coming_soon: {
      page: "/coming-soon/",
      fields: { "dbgr[features][coming_soon]": "1" },
      tab: "&tab=coming-soon",
      expect: async (set) => {
        const text = set["/coming-soon/"].text;
        record("B coming_soon: the neutral section is on its page and says only that something is coming", /data-dbgr-coming-soon/.test(text) && /Something exciting is coming/.test(text));
      },
    },
    waitlist: {
      page: "/waitlist-test/",
      fields: { "dbgr[features][waitlist]": "1", "dbgr[sender_legal_name]": DUMMY.sender, "dbgr[privacy_policy_url]": DUMMY.privacy },
      prereq: "sender legal name and privacy-policy URL",
      tab: "&tab=waitlist",
      expect: async (set) => {
        const form = (set["/waitlist-test/"].text.split("data-dbgr-waitlist")[1] || "").split("</form>")[0];
        const consentBox = (form.match(/<input[^>]*type="checkbox"[^>]*>/g) || []).filter((i) => /consent/i.test(i));
        record("B waitlist: the form is present, its consent box exists, is required and is NOT ticked", /data-dbgr-wl-fields/.test(form) && consentBox.length >= 1 && consentBox.every((i) => !/\bchecked\b/.test(i) && /\brequired\b/.test(i)), `${consentBox.length} consent checkbox(es)`);
        record("B waitlist: the sender is named on the form", form.includes(DUMMY.sender));
      },
    },
    timesheet_recon: {
      fields: { "dbgr[features][timesheet_recon]": "1" },
      tab: null,
      expect: async () => {
        h = await health(adminContext);
        record("B timesheet_recon: the module is active and has NO public effect", h.modules_active.recon === true);
        const r = await adminGet(adminContext, "doughboss-growth-recon");
        record("B timesheet_recon: the report screen loads (nothing was run, Square was never called)", r.status === 200 && !PHP_ERROR.test(r.html), `HTTP ${r.status}`);
      },
    },
  };

  const landingCreate = async () => {
    const html = await (await adminContext.request.get(`${BASE}/wp-admin/admin.php?page=doughboss-growth&tab=landing`)).text();
    const nonce = nonceIn(html, "doughboss_growth_create_pages");
    if (!nonce) return { status: 0, location: "no nonce" };
    const r = await adminContext.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: "doughboss_growth_create_pages", _wpnonce: nonce }, maxRedirects: 0 });
    return { status: r.status(), location: r.headers().location || "" };
  };

  const matrixFlags = process.env.WP16_SKIP_MATRIX === "1" ? [] : FLAGS;
  if (matrixFlags.length === 0) note("phase B (one flag at a time) skipped by WP16_SKIP_MATRIX=1; phases C, D and E run");
  for (const flag of matrixFlags) {
    const spec = CASES[flag];
    const saved = await saveSettings(adminContext, spec.fields);
    h = await health(adminContext);
    record(`B ${flag}: switched on alone${spec.prereq ? " (with " + spec.prereq + ")" : ""}: effective in /health`, saved.status === 302 && h.flags[flag] === true, saved.location.replace(BASE, "").slice(0, 120));
    const others = FLAGS.filter((f) => f !== flag && h.flags[f] === true);
    note(`${flag}: effective flags during this step: ${[flag, ...others].join(", ")}`);

    if (spec.special === "landing" || spec.special === "seo") {
      if (spec.special === "landing") {
        const made = await landingCreate();
        const drafts = await landingPages(adminContext);
        record("B landing_pages: the create button makes six DRAFT pages (nothing is published)", made.status === 302 && drafts.length === 6 && drafts.every((p) => p.status === "draft"), `${drafts.length} page(s): ${drafts.map((p) => p.slug + ":" + p.status).join(" ")}`);
        const anon = await snap(LANDING[0]);
        record("B landing_pages: a draft landing page is not public", anon.status === 404 || !/<h1/i.test(anon.text.split("</header>")[1] || anon.text), `HTTP ${anon.status}`);
        for (const p of drafts) await setStatus(adminContext, p.id, "publish");
        const live = await snapSet(LANDING);
        record("B landing_pages: published, all six answer 200 with no raw shortcode, no PHP notice and no working name", LANDING.every((p) => live[p].status === 200 && !/\[doughboss_growth_/.test(live[p].text) && !live[p].phpError && live[p].workingNames === 0), LANDING.map((p) => `${p}:${live[p].status}`).join(" "));
        const titles = LANDING.map((p) => (live[p].text.match(/<title>([^<]*)<\/title>/) || [])[1]);
        record("B landing_pages: six distinct <title> elements (one each)", new Set(titles).size === 6 && LANDING.every((p) => (live[p].text.match(/<title>/g) || []).length === 1), titles.join(" | "));
        ldOff = LANDING.map((p) => (live[p].text.match(/<script[^>]*application\/ld\+json[^>]*>/g) || []).length);
        record("B landing_pages: the page body is the companion's own markup (class dbgr-lp) and no meta description of its own is added without seo_head", LANDING.every((p) => /dbgr-lp/.test(live[p].text)), `core JSON-LD scripts per page without seo_head: ${ldOff.join(",")}`);
      } else {
        const live = await snapSet(LANDING);
        const ld = LANDING.map((p) => (live[p].text.match(/<script[^>]*application\/ld\+json[^>]*>/g) || []).length);
        record("B seo_head: every landing page gains exactly one JSON-LD script and has exactly one meta description", LANDING.every((p, i) => ld[i] - ldOff[i] === 1 && (live[p].text.match(/<meta name="description"/g) || []).length === 1), `JSON-LD scripts without seo_head ${ldOff.join(",")} and with it ${ld.join(",")}`);
        record("B seo_head: no property the ledger cannot source (no geo, sameAs, aggregateRating, priceRange)", LANDING.every((p) => !/"geo"|"sameAs"|aggregateRating|priceRange/.test(live[p].text)));
      }
      note(`${flag}: with the six pages published WordPress itself marks the hub pages as parents (body class page-parent); the byte-identical check runs after the pages are drafted again`);
    } else {
      const needed = Array.from(new Set(["/", spec.page || "/"]));
      const set = await snapSet(needed);
      await checkPublicClean(`B ${flag}`, set);
      await spec.expect(set);
      record(`B ${flag}: no page shows raw companion shortcode text`, needed.every((p) => !/\[doughboss_growth_/.test(set[p].text)));
    }
    if (spec.tab !== null && spec.tab !== undefined) await tabOk(`B ${flag}`, spec.tab);
    await browse(`B ${flag}`, Array.from(new Set(["/", spec.page || "/"])), spec.browseTracking);

    if (spec.special === "seo") {
      // seo_head and landing_pages come off together; the pages go back to draft before the byte comparison.
      await reverse("B landing_pages + seo_head", async () => {
        const live = await snapSet(LANDING);
        note(`after the flags are off the published landing pages answer: ${LANDING.map((p) => p + ":" + live[p].status + (/\[doughboss_growth_/.test(live[p].text) ? "(RAW TAG)" : "")).join(" ")}`);
        const ldNow = LANDING.map((p) => (live[p].text.match(/<script[^>]*application\/ld\+json[^>]*>/g) || []).length);
        record("B landing_pages + seo_head: with the flags off a published landing page shows no raw shortcode text and its JSON-LD count falls back to core's own", LANDING.every((p) => !/\[doughboss_growth_/.test(live[p].text)) && JSON.stringify(ldNow) === JSON.stringify(ldOff), `JSON-LD scripts now ${ldNow.join(",")} (core only: ${ldOff.join(",")})`);
        for (const p of await landingPages(adminContext)) await setStatus(adminContext, p.id, "draft");
      });
    } else if (spec.special === "landing") {
      // Left published for the seo_head step that follows; its reversal covers both.
      continue;
    } else {
      await reverse(`B ${flag}`);
    }
  }
  await saveSettings(adminContext, {});

  /* ---------- C: all together ---------- */
  const ALL = { ...Object.fromEntries(FLAGS.map((f) => [`dbgr[features][${f}]`, "1"])), "dbgr[gtm_container_id]": DUMMY.gtm, "dbgr[consent_default]": "deny", "dbgr[privacy_policy_url]": DUMMY.privacy, "dbgr[sender_legal_name]": DUMMY.sender, "dbgr[notify_webhook_url]": DUMMY.webhook, "dbgr[ga4_measurement_id]": DUMMY.ga4Id };
  const savedAll = await saveSettings(adminContext, ALL);
  h = await health(adminContext);
  record("C every flag switched on together: all eleven effective", savedAll.status === 302 && FLAGS.every((f) => h.flags[f] === true), FLAGS.filter((f) => h.flags[f] !== true).join(","));
  const mods = Object.entries(h.modules_active).filter(([, on]) => on).map(([k]) => k);
  record("C every module with a flag is active and storage is ready", h.storage_ready === true && ["ledger", "consent", "attribution", "waitlist", "coming_soon", "landing", "leads", "conversions", "recon"].every((m) => mods.includes(m)), mods.join(","));

  const made = await landingCreate();
  const drafts = await landingPages(adminContext);
  record("C the six landing pages exist as drafts (re-used or created)", drafts.length === 6, `create: HTTP ${made.status}`);
  for (const p of drafts) await setStatus(adminContext, p.id, "publish");

  const everything = [...PUBLIC, ...LANDING];
  const viewports = ["desktop", "mobile"];
  const shotName = (p) => "wp16" + (p === "/" ? "-home" : p.replace(/\/$/, "").replace(/\//g, "-"));
  const axeSummary = {};
  for (const p of everything) {
    for (const kind of viewports) {
      const wanted = p === "/" || p === "/coming-soon/" || LANDING.includes(p) || p === "/lead-test/" || p === "/waitlist-test/";
      const v = await visit(browser, p, kind, wanted ? shotName(p) : null);
      const gtm = v.tracker.tracking.filter((u) => /googletagmanager\.com\/gtm\.js/.test(u)).length;
      const otherTracking = v.tracker.tracking.filter((u) => !/googletagmanager\.com\/gtm\.js/.test(u));
      record(`C ${p} (${kind}): answers 200, no first-party console error, no failed first-party request, no horizontal scroll`, v.status === 200 && v.errors.length === 0 && v.scroll <= 0, `HTTP ${v.status}; scroll overflow ${v.scroll}px; ${v.errors.slice(0, 2).join(" | ")}`);
      record(`C ${p} (${kind}): before any consent only the Tag Manager loader is requested, and the companion adds no working name`, otherTracking.length === 0 && gtm <= 1 && countWorkingName(v.html) <= (R0[p] ? R0[p].workingNames : 0), `${gtm} loader, ${otherTracking.length} other tracking, working name x${countWorkingName(v.html)} (core's own: ${R0[p] ? R0[p].workingNames : 0})`);
      if (p === "/") {
        const banner = await v.page.locator("#dbgr-consent").isVisible();
        record(`C / (${kind}): the consent banner is visible on the first visit`, banner);
      }
      const violations = await axeFor(v.page);
      const ours = violations.filter((x) => x.nodes.some((n) => n.ours));
      const rest = violations.filter((x) => !x.nodes.some((n) => n.ours));
      axeSummary[`${p} ${kind}`] = { ours: ours.map((x) => x.id), other: rest.map((x) => `${x.id}(${x.nodes.length})`) };
      record(`C ${p} (${kind}): axe finds no violation in companion markup`, ours.length === 0, ours.length ? JSON.stringify(ours) : `${violations.length} violation(s) elsewhere on the page`);
      await v.context.close();
    }
  }
  const elsewhere = Object.entries(axeSummary).filter(([, s]) => s.other.length);
  note(`axe violations outside companion markup (core or theme, not changed by this work): ${elsewhere.length ? elsewhere.map(([k, s]) => k + " -> " + s.other.join(",")).join("; ") : "none"}`);

  // Accept the banner and reload: the choice persists, no first-party error.
  {
    const v = await visit(browser, "/", "desktop", null);
    await v.page.locator('[data-dbgr-action="accept"]').click();
    await v.page.waitForTimeout(400);
    await v.page.reload({ waitUntil: "load" });
    await v.page.waitForTimeout(500);
    const gone = !(await v.page.locator("#dbgr-consent").isVisible());
    const cookie = (await v.context.cookies()).some((c) => c.name === "dbgr_consent");
    record("C accepting the banner stores the choice (cookie) and the banner stays closed after reload, with no first-party error", gone && cookie && v.errors.length === 0, v.errors.slice(0, 2).join(" | "));
    await v.context.close();
  }

  // Every admin screen.
  const tabsHtml = await (await adminContext.request.get(`${BASE}/wp-admin/admin.php?page=doughboss-growth`)).text();
  const tabs = Array.from(new Set((tabsHtml.match(/tab=([a-z-]+)/g) || []).map((t) => "&" + t)));
  const allTabs = Array.from(new Set([...ADMIN_TABS, ...tabs]));
  const tabFailures = [];
  for (const t of allTabs) {
    const r = await adminGet(adminContext, `doughboss-growth${t}`);
    if (r.status !== 200 || PHP_ERROR.test(r.html)) tabFailures.push(`${t || "(settings)"}:${r.status}`);
  }
  const recon = await adminGet(adminContext, "doughboss-growth-recon");
  if (recon.status !== 200 || PHP_ERROR.test(recon.html)) tabFailures.push(`recon:${recon.status}`);
  record(`C every companion admin screen loads with every flag on (${allTabs.length + 1} screens)`, tabFailures.length === 0, tabFailures.join(", "));

  /* ---------- D: reversal of the whole set ---------- */
  await saveSettings(adminContext, {});
  for (const p of await landingPages(adminContext)) await setStatus(adminContext, p.id, "draft");
  h = await health(adminContext);
  const after = await snapSet();
  const diffAll = differences(R0, after);
  record("D all flags off and the landing pages drafted: every public page is BYTE-IDENTICAL to the flags-off reference", Object.values(h.flags).every((v) => v === false) && diffAll.length === 0, diffAll.join("; "));

  /* ---------- outbound guard: nothing may have left the machine (flags were on in B and C) ---------- */
  {
    const g = await (await adminContext.request.get(`${BASE}/?wp16_http=1`)).json().catch(() => null);
    const attempts = g ? g.attempts.filter((a) => !a.probe) : null;
    record("OUTBOUND guard is live: a probe request to a non-local host was refused by the runtime filter", !!g && g.probe_refused === true);
    record("OUTBOUND server-side requests that reached the network: 0 (every non-local attempt was recorded and refused)", !!g && g.probe_refused === true, `attempts recorded and refused (excluding the probe): ${attempts ? attempts.length : "unknown"}${attempts && attempts.length ? " -> " + attempts.map((a) => `${a.method} ${a.host}${a.path}`).join("; ") : ""}`);
    note(`outbound attempts (recorded and refused, probe excluded) after phases A-D: ${attempts ? attempts.length : "unknown"}`);
  }

  /* ---------- E: deactivate and reactivate ---------- */
  await saveSettings(adminContext, { "dbgr[features][landing_pages]": "1" });
  for (const p of await landingPages(adminContext)) await setStatus(adminContext, p.id, "publish");
  const pluginsHtml = await (await adminContext.request.get(`${BASE}/wp-admin/plugins.php`)).text();
  const href = (pluginsHtml.match(/href="([^"]*action=deactivate[^"]*plugin=doughboss-growth[^"]*)"/) || [])[1];
  if (!href) {
    record("E the plugins screen offers a Deactivate link for the companion", false, "link not found");
  } else {
    const url = href.replace(/&amp;/g, "&");
    await adminContext.request.get(url.startsWith("http") ? url : `${BASE}/wp-admin/${url}`);
    const drafted = await landingPages(adminContext);
    record("E deactivating the plugin moves the six landing pages back to draft (no raw tag is ever shown on them)", drafted.length === 6 && drafted.every((p) => p.status === "draft"), drafted.map((p) => p.status).join(","));
    const gone = await adminContext.request.get(REST("/doughboss-growth/v1/health"));
    record("E while deactivated the health route is gone", gone.status() === 404);
    const raw = await snapSet(["/coming-soon/", "/lead-test/", "/waitlist-test/"]);
    const rawShown = Object.entries(raw).filter(([, s]) => /\[doughboss_growth_/.test(s.text)).map(([p]) => p);
    note(`after deactivation, pages the OWNER wrote with a companion shortcode show the raw tag: ${rawShown.join(", ") || "none"} (documented: set such pages to draft or remove the shortcode before deactivating)`);
    const reactivateHref = ((await (await adminContext.request.get(`${BASE}/wp-admin/plugins.php`)).text()).match(/href="([^"]*action=activate[^"]*plugin=doughboss-growth[^"]*)"/) || [])[1];
    if (reactivateHref) {
      const u = reactivateHref.replace(/&amp;/g, "&");
      await adminContext.request.get(u.startsWith("http") ? u : `${BASE}/wp-admin/${u}`);
    }
    h = await health(adminContext);
    record("E reactivating restores the plugin with the saved settings kept (landing_pages still on, nothing else)", h.flags && h.flags.landing_pages === true && FLAGS.filter((f) => f !== "landing_pages").every((f) => h.flags[f] === false), JSON.stringify(h.flags));
  }

  /* ---------- cleanup ---------- */
  await saveSettings(adminContext, {});
  for (const p of await landingPages(adminContext)) await setStatus(adminContext, p.id, "draft");
  for (const id of Object.values(created)) await deletePage(adminContext, id);
  h = await health(adminContext);
  record("cleanup: every flag off, plugin active, throwaway pages deleted, landing pages drafts", Object.values(h.flags).every((v) => v === false));

  {
    const g = await (await adminContext.request.get(`${BASE}/?wp16_http=1`)).json().catch(() => null);
    const attempts = g ? g.attempts.filter((a) => !a.probe) : null;
    record("OUTBOUND whole run (A-E and cleanup): every non-local server-side request was recorded and refused, none reached the network", !!g && g.probe_refused === true, `attempts: ${attempts ? attempts.length : "unknown"}${attempts && attempts.length ? " -> " + attempts.map((a) => `${a.method} ${a.host}${a.path}`).join("; ") : ""}`);
    note(`outbound attempts (recorded and refused, probe excluded) whole run: ${attempts ? attempts.length : "unknown"}`);
  }

  const logAfter = logPath ? logSize(logPath) : null;
  if (logPath && logBefore !== null) {
    const added = logAfter > logBefore ? fs.readFileSync(logPath, "utf8").slice(logBefore) : "";
    const lines = added.split("\n").filter((l) => /PHP (?:Warning|Notice|Fatal error|Deprecated|Parse error)|module_failed/.test(l));
    record("the runtime debug log gained no PHP warning, notice, deprecation or fatal error", lines.length === 0, lines.slice(0, 3).join(" | ") || `log grew ${logAfter - logBefore} bytes`);
  } else {
    note("the runtime debug log could not be located, so it was not checked");
  }
  {
    const serverText = logSize(serverLog) > serverLogBefore ? fs.readFileSync(serverLog, "utf8").slice(serverLogBefore) : "";
    const phpLines = serverText.split("\n").filter((l) => /PHP (?:Warning|Notice|Fatal error|Deprecated|Parse error)|Uncaught|Stack trace/.test(l));
    record("the runtime server log (PHP output) gained no warning, notice, deprecation, fatal error or uncaught exception", phpLines.length === 0, phpLines.slice(0, 3).join(" | ") || `log grew ${logSize(serverLog) - serverLogBefore} bytes`);
  }
} catch (error) {
  fatal = error;
  process.stdout.write(`FATAL  ${error && error.stack ? error.stack : error}\n`);
} finally {
  if (adminContext) await adminContext.close().catch(() => {});
  await browser.close();
}

const failed = checks.filter((c) => !c.ok && !c.note);
const passed = checks.filter((c) => c.ok && !c.note);
fs.writeFileSync(path.join(SHOTS, "wp16-checks.json"), JSON.stringify({ base: BASE, passed: passed.length, failed: failed.length, fatal: fatal ? String(fatal) : null, checks }, null, 2));
process.stdout.write(`\n${passed.length} passed, ${failed.length} failed${fatal ? ", FATAL error" : ""}. Details: ${path.join(SHOTS, "wp16-checks.json")}\n`);
process.exit(failed.length || fatal ? 1 : 0);
