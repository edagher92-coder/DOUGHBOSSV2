import { chromium, devices, request } from "/home/user/DOUGHBOSSV2/web/node_modules/@playwright/test/index.mjs";
import fs from "node:fs";

const BASE = "http://127.0.0.1:9411";
const SHOTS = "/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/boxrun/shots";
const MANIFEST = "/tmp/wp-local-box/src/plugins/doughboss-growth-media/assets/box/manifest.json";
const manifestOrig = fs.readFileSync(MANIFEST, "utf8");
let pass = 0, fail = 0;
const ok = (cond, msg) => { if (cond) { pass++; console.log("PASS", msg); } else { fail++; console.log("FAIL", msg); } };
const count = (s, re) => (s.match(re) || []).length;

const browser = await chromium.launch();
const admin = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const ap = await admin.newPage();
ap.setDefaultTimeout(120000);
await ap.goto(BASE + "/wp-login.php");
await ap.fill("#user_login", "admin");
await ap.fill("#user_pass", "password");
await Promise.all([ap.waitForNavigation(), ap.click("#wp-submit")]);

// The local site is seeded with "discourage search engines" ON, which makes core add noindex everywhere and empties
// the sitemap. Turn it OFF so the noindex and sitemap checks below are real, and add controls.
await ap.goto(BASE + "/wp-admin/options-reading.php");
await ap.uncheck("#blog_public");
await Promise.all([ap.waitForNavigation(), ap.click("#submit")]);
const anon = await request.newContext({ baseURL: BASE, extraHTTPHeaders: { "User-Agent": "Mozilla/5.0 (X11; Linux x86_64) Chrome/130" } });
const get = async (p) => (await anon.get(p, { timeout: 120000 })).text();

const robotsOf = (h) => (h.match(/<meta name=['"]robots['"] content=['"]([^'"]*)['"]/) || [, ""])[1];
const frag = (h, a, b) => { const i = h.indexOf(a); if (i < 0) return ""; const j = b ? h.indexOf(b, i) : -1; return h.slice(i, j < 0 ? undefined : j); };
{
  const h = await get("/menu/");
  ok(!/noindex/.test(robotsOf(h)), "control: /menu/ is indexable (robots='" + robotsOf(h) + "')");
  const smc = await get("/wp-sitemap-posts-page-1.xml");
  ok(/\/menu\/<\/loc>/.test(smc), "control: core sitemap lists /menu/");
}
// 0. Switches off by default: public pages carry nothing from the plugin.
for (const p of ["/", "/catering/", "/menu/"]) {
  const h = await get(p);
  ok(!/dbgr|doughboss-growth-box|Concept preview/i.test(h), `all switches off: ${p} has no plugin output`);
}

// 1. Settings screen, defaults all off, create the draft page with the button.
await ap.goto(BASE + "/wp-admin/admin.php?page=doughboss-growth-box");
const boxes = await ap.$$eval('input[name^="sw["]', (els) => els.map((e) => e.checked));
ok(boxes.length === 5 && boxes.every((c) => c === false), "settings screen: 5 switches, all unticked by default");
await ap.screenshot({ path: SHOTS + "/admin-settings-1440.png", fullPage: true });
await Promise.all([ap.waitForNavigation(), ap.click('input[value="Create draft page"]')]);
const bodyTxt = await ap.textContent("#wpbody-content");
ok(/Draft page created/.test(bodyTxt), "Create draft page button made the page");
const editHref = await ap.getAttribute('a:has-text("Edit page")', "href");
const pageId = new URL(editHref, BASE).searchParams.get("post");
console.log("draft page id", pageId);
// A second press must refuse (page exists), the button is gone and the box says it exists.
ok(/story page exists/i.test(await ap.textContent("#wpbody-content")), "second visit shows the page exists (no duplicate button)");

// 2. Admin preview of the draft with every switch off.
const previewUrl = `${BASE}/?page_id=${pageId}&preview=true`;
await ap.setViewportSize({ width: 1440, height: 900 });
await ap.goto(previewUrl);
await ap.waitForLoadState("networkidle");
let html = await ap.content();
ok(/class="dbgr-admin-note"/.test(html), "draft preview: admin note shown (story switch off)");
ok(count(html, /class="dbgr-chip"/g) === 4, "draft preview: concept chip on all 4 concept images (got " + count(html, /class="dbgr-chip"/g) + ")");
ok(/<meta name='robots' content='[^']*noindex/.test(html) || /<meta name="robots" content="[^"]*noindex/.test(html), "draft preview: robots noindex present");
ok(count(frag(html, 'class="dbgr-story"'), /fetchpriority="high"/g) === 1, "exactly one fetchpriority=high among our images");
await ap.screenshot({ path: SHOTS + "/story-draft-1440.png", fullPage: true });
await ap.setViewportSize({ width: 390, height: 844 });
await ap.goto(previewUrl);
await ap.waitForLoadState("networkidle");
await ap.screenshot({ path: SHOTS + "/story-draft-390.png", fullPage: true });
const overflow = await ap.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
ok(overflow <= 0, "390px: no horizontal overflow (" + overflow + "px)");
// picture markup facts
const facts = await ap.evaluate(() => {
  const out = [];
  document.querySelectorAll(".dbgr-fig picture").forEach((p) => {
    const img = p.querySelector("img");
    out.push({
      types: [...p.querySelectorAll("source")].map((s) => s.type),
      hasSrcset: [...p.querySelectorAll("source")].every((s) => s.srcset && s.sizes),
      w: img.getAttribute("width"), h: img.getAttribute("height"),
      loading: img.getAttribute("loading"), decoding: img.getAttribute("decoding"), fp: img.getAttribute("fetchpriority"),
      loaded: img.complete && img.naturalWidth > 0,
    });
  });
  return out;
});
console.log(JSON.stringify(facts));
ok(facts.length === 4, "4 pictures rendered");
ok(facts.every((f) => f.types.includes("image/avif") && f.types.includes("image/webp") && f.types.includes("image/jpeg")), "every picture has AVIF, WebP and JPEG sources");
ok(facts.every((f) => f.hasSrcset && f.w && f.h && f.decoding === "async"), "srcset+sizes, width, height and decoding=async on all");
ok(facts.filter((f) => f.loading === "lazy").length === 3 && facts.filter((f) => f.fp === "high").length === 1, "3 lazy + 1 fetchpriority=high");
ok(facts.every((f) => f.loaded), "all images actually loaded in the browser");

// 3. Turn the switches on through the real form (nonce + capability path), approve the draft lines.
await ap.setViewportSize({ width: 1440, height: 900 });
await ap.goto(BASE + "/wp-admin/admin.php?page=doughboss-growth-box");
for (const k of ["story", "home_band", "catering_band", "band_image", "strip"]) await ap.check(`input[name="sw[${k}]"]`);
for (const k of ["caption_artwork", "caption_render", "caption_sample", "cta_concept"]) await ap.check(`input[name="approve[${k}]"]`);
await Promise.all([ap.waitForNavigation(), ap.click('input#submit[value="Save changes"], #submit')]);
ok(/Settings saved/.test(await ap.textContent("#wpbody-content")), "settings saved");
await ap.screenshot({ path: SHOTS + "/admin-settings-saved-1440.png", fullPage: true });
const slotsTxt = await ap.textContent("#wpbody-content");
ok(/closed_art/.test(slotsTxt) && /concept/.test(slotsTxt), "slot status table lists the manifest slots");

// publish the page via REST using the logged-in nonce
const nonce = await (await admin.request.get(BASE + "/wp-admin/admin-ajax.php?action=rest-nonce")).text();
const pub = await admin.request.post(`${BASE}/?rest_route=/wp/v2/pages/${pageId}`, { headers: { "X-WP-Nonce": nonce }, data: { status: "publish" } });
ok(pub.ok(), "page published (REST), status " + pub.status());
const pubJson = await pub.json();
const storyUrl = pubJson.link;
console.log("story url", storyUrl);

// 4. Logged-out views.
const storyPath = new URL(storyUrl).pathname;
html = await get(storyPath);
ok(/name=['"]robots['"] content=['"][^'"]*noindex/.test(html), "story (visitor): noindex");
ok(count(html, /class="dbgr-chip"/g) === 4 && count(html, /Concept preview/g) >= 4, "story (visitor): Concept preview chip on every concept image");
ok(!/dbgr-admin-note/.test(html) && !/not shown \(/.test(html), "story (visitor): no admin-only note or comment");
const storyFrag = frag(html, 'class="dbgr-story"', "</article>");
ok(storyFrag.length > 500 && !/Mini|\$\s?\d|2009|FRESH FROM THE OVEN|halal|gluten/i.test(storyFrag), "story (visitor): our output has no price, Mini(s), 2009, FRESH FROM THE OVEN, halal (" + storyFrag.length + " bytes checked)");
ok(/FEED THE WHOLE TABLE|Feed the whole table/i.test(html) && /REVESBY/.test(html) && /Allergen information available on request\./.test(html), "story (visitor): verified lines present");
const sm = await get("/wp-sitemap-posts-page-1.xml");
ok(!sm.includes(storyPath), "story page excluded from the core sitemap");
ok(/dbgr-box\.css/.test(html), "story page loads dbgr-box.css");
const ext = [...html.matchAll(/(?:src|href)=["'](https?:\/\/[^"']+)["']/g)].map((m) => m[1]).filter((u) => !u.startsWith(BASE) && !/w\.org|gravatar/.test(u));
console.log("external refs in story html:", ext.length ? ext.join(" ") : "none");

// home and catering with bands + strip
const home = await get("/");
ok(/class="dbgr-band dbgr-band--home"/.test(home), "home: band under hero");
ok(/name=['"]robots['"] content=['"][^'"]*noindex/.test(home), "home: noindex while band shows the concept image");
ok(count(home, /class="dbgr-chip"/g) === 1, "home: one chip on the band image");
ok(/data-dbgr-strip/.test(home) && /dbgr-strip-css/.test(home), "home: site-wide strip present with inline css");
ok(count(home, /dbgr-box\.css/g) === 1, "home: stylesheet loaded once");
const cat = await get("/catering/");
ok(/class="dbgr-band dbgr-band--catering"/.test(cat), "catering: band under hero");
const menu = await get("/menu/");
ok(!/dbgr-band|dbgr-box\.css/.test(menu) && /data-dbgr-strip/.test(menu), "menu: strip only, no band, no stylesheet");
const sm2 = await get("/wp-sitemap-posts-page-1.xml");
console.log("sitemap page urls:", [...sm2.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]).join(" "));
ok(/\/menu\/<\/loc>/.test(sm2), "sitemap still lists other pages");
ok(!/\/catering\/<\/loc>/.test(sm2), "catering page excluded from sitemap while band image is a concept (home stays: core adds it itself when posts show on front; it is noindex)");

// screenshots of public pages (logged out)
const vis = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const vp = await vis.newPage();
vp.setDefaultTimeout(120000);
const shot = async (url, name, w, h, full = true) => {
  await vp.setViewportSize({ width: w, height: h });
  await vp.goto(BASE + url);
  await vp.waitForLoadState("networkidle");
  await vp.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 600) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); } window.scrollTo(0, 0); });
  await vp.waitForTimeout(400);
  await vp.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: full });
};
await shot(storyPath, "story-live-1440", 1440, 900);
await shot(storyPath, "story-live-390", 390, 844);
await shot("/", "home-band-1440", 1440, 900);
await shot("/", "home-band-390", 390, 844);
// strip position: it must sit above the footer after the relocation script
const stripAbove = await vp.evaluate(() => { const s = document.querySelector("[data-dbgr-strip]"), f = document.querySelector(".dbf-footer"); return !!(s && f && (s.compareDocumentPosition(f) & Node.DOCUMENT_POSITION_FOLLOWING)); });
ok(stripAbove, "strip moved above .dbf-footer by the inline script");
await shot("/menu/", "menu-strip-390", 390, 844);

// 5. Reduced motion: no transitions on buttons.
const rm = await browser.newContext({ reducedMotion: "reduce", viewport: { width: 1440, height: 900 } });
const rp = await rm.newPage(); rp.setDefaultTimeout(120000);
await rp.goto(BASE + storyPath);
const tr = await rp.evaluate(() => getComputedStyle(document.querySelector(".dbgr-btn")).transitionDuration);
ok(tr === "0s", "prefers-reduced-motion: button transition is 0s (" + tr + ")");
await rm.close();

// 6. AI-food refusal and the Phase 2 real flip, by editing ONLY the media manifest (no code change).
const m = JSON.parse(manifestOrig);
m.slots.closed.ai_food = true;          // concept + ai_food -> must be refused
fs.writeFileSync(MANIFEST, JSON.stringify(m, null, 1));
html = await get(storyPath);
ok(!/data-dbgr-slot="closed"/.test(html) && count(html, /class="dbgr-chip"/g) === 3, "ai_food slot (concept) is refused: not printed, others still shown");
// the same refusal for the band image
const home2 = await get("/");
ok(count(home2, /class="dbgr-chip"/g) === 1, "band uses closed_band (not flagged): still shown");
m.slots.closed_band.ai_food = true;
fs.writeFileSync(MANIFEST, JSON.stringify(m, null, 1));
const home3 = await get("/");
ok(!/dbgr-chip/.test(home3) && /class="dbgr-band dbgr-band--home"/.test(home3), "band image slot flagged AI food: band falls back to text only");
ok(/\/catering\/<\/loc>/.test(await get("/wp-sitemap-posts-page-1.xml")), "catering back in the sitemap when no concept image prints");
ok(!/name=['"]robots['"] content=['"][^'"]*noindex/.test(home3), "home no longer noindex when no concept image prints");
// admin sees the reason as an HTML comment
await ap.goto(BASE + storyPath);
ok(/slot "closed" not shown \(blocked: ai_food\)/.test(await ap.content()), "admin HTML comment names the refused slot and reason");
// real + not AI food: chip disappears, page becomes indexable once all four story slots are real
const m2 = JSON.parse(manifestOrig);
for (const k of ["closed_art", "closed", "seal_art", "seal_macro", "closed_band"]) m2.slots[k].status = "real";
fs.writeFileSync(MANIFEST, JSON.stringify(m2, null, 1));
html = await get(storyPath);
ok(!/dbgr-chip/.test(html) && !/name=['"]robots['"] content=['"][^'"]*noindex/.test(html), "all slots real: no chip and no noindex");
// malformed manifest -> text only, no fatal
fs.writeFileSync(MANIFEST, "{ not json");
html = await get(storyPath);
ok(!/<picture/.test(frag(html, 'class="dbgr-story"', '</article>')) && /FEED THE WHOLE TABLE|Office runs/.test(html) && /name=['"]robots['"] content=['"][^'"]*noindex/.test(html), "broken manifest: text only, still noindex, no fatal");
fs.writeFileSync(MANIFEST, manifestOrig);

// 7. Rollback: untick everything -> public pages clean again.
await ap.goto(BASE + "/wp-admin/admin.php?page=doughboss-growth-box");
for (const k of ["story", "home_band", "catering_band", "band_image", "strip"]) await ap.uncheck(`input[name="sw[${k}]"]`);
await Promise.all([ap.waitForNavigation(), ap.click("#submit")]);
for (const p of ["/", "/catering/", "/menu/", storyPath]) {
  const h = await get(p);
  ok(!/dbgr-chip|dbgr-band|data-dbgr-strip|dbgr-story/.test(h), `rollback: ${p} has no plugin output after switches off`);
}
// capability + nonce: anonymous POST to admin-post is refused
const bad = await anon.post(BASE + "/wp-admin/admin-post.php", { form: { action: "dbgrbox_save", "sw[story]": "1" }, maxRedirects: 0 });
ok(bad.status() !== 200 || !/Settings saved/.test(await bad.text()), "anonymous POST to the save action does nothing (status " + bad.status() + ")");
const stored = await ap.evaluate(() => 1);
await browser.close();
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
