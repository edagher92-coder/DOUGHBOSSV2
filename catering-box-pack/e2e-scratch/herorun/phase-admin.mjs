// Phase A: baselines, settings screen, upload of the five videos, lookup cache behaviour, duplicate upload, plugin-folder preference.
import { chromium, BASE, SHOTS, HERE, VID, STATE, VIDEOS, ok, note, tally, sha, adminLogin, anon, get, setSwitches, heroStatus, fs, log } from "./lib.mjs";

fs.mkdirSync(HERE + "/out", { recursive: true });
fs.mkdirSync(SHOTS, { recursive: true });
const browser = await chromium.launch();
const { ctx, p } = await adminLogin(browser);
const a = await anon();

// The local site is seeded with "discourage search engines" ON; turn it OFF so robots meta reflects real behaviour.
await p.goto(BASE + "/wp-admin/options-reading.php");
await p.uncheck("#blog_public");
await Promise.all([p.waitForNavigation(), p.click("#submit")]);

// ---- 1. Baselines: plugin ACTIVE with every switch off vs plugin INACTIVE.
const off1 = await get(a, "/");
const off2 = await get(a, "/");
ok(off1 === off2, "natural variance: two plain fetches of / are byte-identical (" + off1.length + " bytes)");
ok(!/dbgr|growth-box/i.test(off1), "plugin active, all switches off: no plugin output on /");

await p.goto(BASE + "/wp-admin/plugins.php");
await Promise.all([p.waitForNavigation(), p.click('tr[data-plugin="doughboss-growth-box/doughboss-growth-box.php"] .deactivate a')]);
const base1 = await get(a, "/");
const base2 = await get(a, "/");
fs.writeFileSync(HERE + "/out/baseline-home.html", base1);
ok(base1 === base2, "baseline (plugin inactive) is stable");
ok(off1 === base1, "SWITCHES OFF: home markup is byte-identical to the plugin-inactive baseline (" + base1.length + " bytes, sha256 " + sha(base1).slice(0, 16) + ")");
await p.goto(BASE + "/wp-admin/plugins.php");
await Promise.all([p.waitForNavigation(), p.click('tr[data-plugin="doughboss-growth-box/doughboss-growth-box.php"] .activate a')]);
ok((await get(a, "/")) === base1, "after reactivation (still all off): byte-identical again");

// ---- 2. Settings screen defaults.
await p.goto(BASE + "/wp-admin/admin.php?page=doughboss-growth-box");
const sw = await p.$$eval('input[name^="sw["]', (els) => els.map((e) => [e.name, e.checked]));
note("switches: " + JSON.stringify(sw));
ok(sw.length === 7, "settings screen has 7 switches");
ok(sw.filter((s) => s[1]).map((s) => s[0]).join() === "sw[hero_chip]", "defaults: only the Concept preview label is ticked; Home hero video is OFF");
await p.screenshot({ path: SHOTS + "/admin-settings-defaults-1440.png", fullPage: true });
let st = await heroStatus(p);
note("status (no videos): " + JSON.stringify(st.state));
ok(/Off\./.test(st.state), "status says Off while the switch is off");
ok(Object.keys(st.rows).length === 7, "status table lists 5 videos + 2 posters");
ok(st.rows["dbgr-hero-poster-1080.webp"].found === "yes" && st.rows["dbgr-hero-poster-720.webp"].found === "yes", "both posters found in the media plugin");
ok(VIDEOS.every((v) => st.rows[v].found === "not found"), "all 5 videos 'not found' before upload");

// ---- 3. Switch ON, no videos: the page must not change.
await setSwitches(p, { home_hero_video: true, hero_chip: true });
st = await heroStatus(p);
ok(/On, but not running: no hero video was found/.test(st.state), "switch on + no videos: status explains why it is not running");
ok((await get(a, "/")) === base1, "switch on but no videos: home is still byte-identical to baseline (fail closed on missing media)");

// ---- 4. Upload the five videos exactly as Elie would: Media, Add New, drag the files in.
await p.goto(BASE + "/wp-admin/media-new.php");
await p.setInputFiles('input[type="file"][id^="html5_"]', VIDEOS.map((v) => VID + "/" + v));
await p.waitForFunction((n) => document.querySelectorAll("#media-items .media-item").length >= n && !document.querySelector("#media-items .media-item .progress:not([style*='display: none'])"), VIDEOS.length, { timeout: 300000 });
await p.waitForTimeout(3000);
const items = await p.$$eval("#media-items .media-item", (els) => els.map((e) => (e.querySelector(".filename.new") || e.querySelector(".filename") || e).textContent.trim().replace(/\s+/g, " ").slice(0, 80)));
note("upload screen items: " + JSON.stringify(items));
await p.screenshot({ path: SHOTS + "/admin-media-upload-1440.png" });
// NO settings save here: the add_attachment hook alone must have cleared the cached "no videos" answer.
st = await heroStatus(p);
note("status after upload: " + JSON.stringify(st));
ok(VIDEOS.every((v) => st.rows[v].found === "yes" && /^Media Library \(item \d+\)$/.test(st.rows[v].where)), "after upload (no settings save): all 5 found in the Media Library, so add_attachment cleared the cache");
ok(/Running on the home page hero/.test(st.state), "status says Running");
const ids = Object.fromEntries(VIDEOS.map((v) => [v, +st.rows[v].where.match(/\d+/)[0]]));
fs.writeFileSync(HERE + "/out/video-ids.json", JSON.stringify(ids));
await p.screenshot({ path: SHOTS + "/admin-settings-running-1440.png", fullPage: true });

let home = await get(a, "/");
const dataAttrs = [...home.matchAll(/ data-(av1|hevc|h264)-(720|1080)="([^"]+)"/g)].map((m) => m[1] + "-" + m[2] + " " + m[3].replace(BASE, ""));
note("data attributes on the video: " + JSON.stringify(dataAttrs));
ok(dataAttrs.length === 5 && !/<source/.test(home), "home markup carries 5 data-* addresses and no <source> tag");

// ---- 5. WordPress renames a repeat upload (-1): the lookup must tolerate it and prefer the newest.
await p.goto(BASE + "/wp-admin/media-new.php");
await p.setInputFiles('input[type="file"][id^="html5_"]', [VID + "/dbgr-hero-loop-720-av1.mp4"]);
await p.waitForFunction(() => document.querySelectorAll("#media-items .media-item").length >= 1 && !document.querySelector("#media-items .media-item .progress:not([style*='display: none'])"), null, { timeout: 120000 });
await p.waitForTimeout(2000);
st = await heroStatus(p);
const dupId = +st.rows["dbgr-hero-loop-720-av1.mp4"].where.match(/\d+/)[0];
ok(dupId > ids["dbgr-hero-loop-720-av1.mp4"], "repeat upload: lookup picks the newest item (" + dupId + " > " + ids["dbgr-hero-loop-720-av1.mp4"] + ")");
home = await get(a, "/");
const dupUrl = (home.match(/data-av1-720="([^"]+)"/) || [])[1] || "";
ok(/dbgr-hero-loop-720-av1-1\.mp4$/.test(dupUrl), "WordPress named the repeat upload with -1 and the page uses it: " + dupUrl.replace(BASE, ""));
// delete the duplicate (REST, force) -> delete_attachment flushes the cache
const nonce = await (await ctx.request.get(BASE + "/wp-admin/admin-ajax.php?action=rest-nonce")).text();
const del = await ctx.request.delete(`${BASE}/?rest_route=/wp/v2/media/${dupId}&force=true`, { headers: { "X-WP-Nonce": nonce } });
ok(del.ok(), "duplicate deleted (REST " + del.status() + ")");
st = await heroStatus(p);
ok(+st.rows["dbgr-hero-loop-720-av1.mp4"].where.match(/\d+/)[0] === ids["dbgr-hero-loop-720-av1.mp4"], "after deleting the duplicate the original is found again (delete_attachment cleared the cache)");
home = await get(a, "/");
ok(/data-av1-720="[^"]*dbgr-hero-loop-720-av1\.mp4"/.test(home), "page is back on the original file name");

// ---- 6. A file inside the media plugin wins; the lookup is cached until a flush event.
const heroDir = STATE + "/src/plugins/doughboss-growth-media/assets/hero";
fs.copyFileSync(VID + "/dbgr-hero-loop-720-h264.mp4", heroDir + "/dbgr-hero-loop-720-h264.mp4");
st = await heroStatus(p);
ok(/Media Library/.test(st.rows["dbgr-hero-loop-720-h264.mp4"].where), "CACHE: a new file in the media plugin is not seen until the cache is cleared (transient in use)");
await setSwitches(p, { home_hero_video: true, hero_chip: true }); // a settings save clears the transient
st = await heroStatus(p);
ok(st.rows["dbgr-hero-loop-720-h264.mp4"].where === "Media plugin folder", "settings save cleared the cache: the media plugin copy is now preferred over the Media Library");
home = await get(a, "/");
ok(/data-h264-720="[^"]*doughboss-growth-media\/assets\/hero\/dbgr-hero-loop-720-h264\.mp4"/.test(home), "page serves the media plugin copy for h264-720");
fs.unlinkSync(heroDir + "/dbgr-hero-loop-720-h264.mp4");
await setSwitches(p, { home_hero_video: true, hero_chip: true });
st = await heroStatus(p);
ok(/Media Library/.test(st.rows["dbgr-hero-loop-720-h264.mp4"].where), "plugin copy removed + save: back to the Media Library file");

// ---- 7. Chip off / chip on, and what the markup looks like with the switch on.
home = await get(a, "/");
fs.writeFileSync(HERE + "/out/home-on.html", home);
ok(/<section class="db-manoush-hero db-manoush-hero--home has-dbgr-video"/.test(home), "ON: section carries has-dbgr-video");
ok(/<div class="db-mh-backdrop" style="background-image:url\('[^']*dbgr-hero-poster-1080\.webp\?ver=\d+'\)" aria-hidden="true"><video class="dbgr-hero-video"/.test(home), "ON: background_image is the 1080 WebP poster and the <video> sits inside .db-mh-backdrop");
ok(/<\/video><\/div><span class="dbgr-hero-chip">Concept preview<\/span>/.test(home), "ON: Concept preview chip follows the backdrop");
ok(!/db-mh-steam/.test(home), "ON: steam element removed");
ok((home.match(/<link rel="preload" as="image" href="[^"]*dbgr-hero-poster-1080\.webp\?ver=\d+" fetchpriority="high" type="image\/webp">/g) || []).length === 1, "ON: exactly one wp_head poster preload with fetchpriority=high");
ok(/dbgr-hero\.css/.test(home) && /dbgr-hero\.js/.test(home), "ON: stylesheet and script are loaded");
ok(/<script[^>]*dbgr-hero\.js[^>]*defer/.test(home) || /defer/.test((home.match(/<script[^>]*dbgr-hero-js[^>]*>/) || [""])[0]), "ON: script is deferred in the footer");
note("script tag: " + (home.match(/<script[^>]*dbgr-hero[^>]*><\/script>/) || [""])[0].replace(BASE, ""));
ok(!/noindex/.test((home.match(/<meta name=['"]robots['"][^>]*>/g) || []).join(" ")), "ON: the home page is NOT noindex (robots: " + (home.match(/<meta name=['"]robots['"] content=['"]([^'"]*)/g) || []).join(" | ").replace(/<meta name=['"]robots['"] content=['"]/g, "") + ")");
const sm = await get(a, "/wp-sitemap-posts-page-1.xml");
note("sitemap unaffected by the hero video (not asserted beyond: contains pages) " + (/<loc>/.test(sm)));

await setSwitches(p, { home_hero_video: true, hero_chip: false });
home = await get(a, "/");
ok(!/dbgr-hero-chip|Concept preview/.test(home) && /has-dbgr-video/.test(home), "label setting OFF: no chip in the markup, video still on");
await setSwitches(p, { home_hero_video: true, hero_chip: true });

// Catering hero and other pages must not change.
const cat = await get(a, "/catering/");
ok(!/dbgr-hero|has-dbgr-video|dbgr-hero-poster/.test(cat), "catering page: no hero video output");
const menu = await get(a, "/menu/");
ok(!/dbgr-hero|has-dbgr-video|dbgr-hero-poster/.test(menu), "menu page: no hero video output");

fs.writeFileSync(HERE + "/out/phase-admin.log", log.join("\n"));
await browser.close();
const t = tally();
console.log(`\nphase A: ${t.pass} passed, ${t.fail} failed`);
process.exit(t.fail ? 1 : 0);
