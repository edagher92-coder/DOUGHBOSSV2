# Home hero video: evidence (plugin 0.2.0)

Built and tested 3 October 2026. Nothing was committed, pushed or deployed. Owner instruction (Elie, 3 October): "Add the video to the website hero". It supersedes the 2 October "keep the photo hero" ruling for the home hero video only.

## Files

| File | Bytes | sha256 |
| --- | --- | --- |
| `doughboss-growth-box-0.2.0.zip` (code) | 43,747 | `3d01e76178a35b84dc5aae4efcbbecb0150588bfc1dd66d887fb77beb439cfd0` |
| `doughboss-growth-media-0.2.0.zip` (posters + hero.json + box pictures) | 1,759,411 | `a55e64b86aad7680f8dc2b01174d6d58c4172e014d2cc93514c39dfcbc589f60` |
| `doughboss-growth-box-0.2.0-source.zip` (whole code folder incl. scripts and INSTALL) | 53,487 | `b911af3fa5d2a1ccd06e2fb627c777fefc06df6f9732b98e4d798705b3762e1e` |

Both installable zips are under 1.9 MB (code 2.3% of that, media 92.6%) and under the host's 2 MB upload cap. Both builds are deterministic: a rebuild gave the same sha256. The five MP4 files are not in the zips (1.4 to 1.9 MB each); Elie uploads them under Media, Add New.

Media zip budget: the box set was 1,305,352 B; adding the 1080 WebP (299,378) and 720 WebP (152,980) gives 1,759,411 B. The 1080 AVIF (202,341) would take it to 1,960,051 B and the 1080 JPEG (436,098) further, so both were left out. Neither is used by the page (the spec fixes the hero background and preload to the 1080 WebP). The 720 WebP is shipped but not referenced yet (a possible phone-size switch later).

Changed or new in the code plugin: `doughboss-growth-box.php`, `readme.txt`, `INSTALL.md`, `uninstall.php`, `includes/class-dbgrbox{,-settings,-inject,-admin}.php`, new `includes/class-dbgrbox-hero.php`, new `includes/class-dbgrbox-hero-media.php`, new `public/css/dbgr-hero.css` (3.1 KB), new `public/js/dbgr-hero.js` (4.6 KB, 1.9 KB gzipped). In the media plugin: version 0.2.0, new `assets/hero/` (two posters, `hero.json`), and `build_media_zip.py` now also checks hero.json, poster sizes and stray files.

## What it does

- Settings: "Home hero video" (default OFF) and "Show 'Concept preview' label on the hero video" (default ON). A status table lists the 5 videos and 2 posters found and where (Media Library item or media plugin folder), plus a one-line state (Off / Running / why not).
- Active only when: switch on, `DBGRBOX_DISABLE` not set, front page, 1080 WebP poster found, at least one video found, and the core markup was recognised. Otherwise the page is core's own.
- `shortcode_atts_doughboss_manoush_hero` sets `background_image` to the poster (attribute name checked in core 2.41.0 and 2.43.2: `background_image`, third `shortcode_atts` argument present). `wp_head` priority 1 prints `<link rel="preload" as="image" href=".../dbgr-hero-poster-1080.webp?ver=..." fetchpriority="high" type="image/webp">`. `do_shortcode_tag` (priority 15, before the band at 20) adds `has-dbgr-video`, puts the inert `<video>` inside the empty `.db-mh-backdrop`, removes `.db-mh-steam`, adds the chip. No `<source>`; the 5 addresses are `data-av1-720` etc.
- Fail closed: if the markup is not the expected shape, core's shortcode is run again with our change switched off, so the output is core's own, and a marker in the lookup transient switches the whole feature off (no preload, no CSS, no JS) until a settings save, plugin or core update, or a day passes. The settings screen says why.
- Lookup: media plugin `assets/hero` first, then the Media Library by file-name stem, tolerating `-1`, `-2` and `-scaled`; the newest upload wins. Cached in transient `dbgrbox_hero_media`, cleared on settings save, `add_attachment`, `edit_attachment`, `delete_attachment`, plugin install, activate and deactivate; also dropped if the site address changes, and expires after a day.
- noindex: the hero video deliberately does NOT add noindex (owner decision; the visible label carries the honesty). Documented in `class-dbgrbox-hero.php`, `readme.txt`, `INSTALL.md`, `hero.json` and the settings help text. The existing 0.1.0 rule for "Concept image in the bands" is unchanged.

## Tests

Harness: local WordPress runtime (Playground, PHP 8.2, WP 7.1, SQLite), core **2.41.0** (the markup that is live), plus core **2.43.2** for the fail-closed control. Playwright Chromium from `/opt/pw-browsers`. Scripts and raw logs: `herorun/` (scratchpad) and the copies in `hero-video-evidence/`.

| Check | Result |
| --- | --- |
| `php -l` on all 14 PHP files, PHP 8.3.6 | 0 failures |
| `php -l` on PHP 7.4.33 (php-wasm) | 14 files, 0 failures |
| Repo guard `doughboss-growth/scripts/php74-guard.php` on the plugin | 14 files, no violations |
| `dbgr-hero.js` parsed with acorn at ecmaVersion 5 | OK |
| `transform()` unit test on the real captured live hero markup and variants | 24 of 24 |
| File-name stem tolerance (`-1`, `-scaled`, case, wrong names) | 13 of 13 |
| Phase A, admin (baselines, settings, upload, cache, duplicates, markup) | 36 of 36 |
| Phase B, visits at 1440x900 and 390x844 (a)-(d), metrics, pauses | 54 of 54 |
| Phase C, script decisions matrix | 33 of 33 |
| Phase D, kill switch | 5 of 5 |
| Phase E, core 2.43.2 fail-closed | 10 of 10 |

Key evidence:

- **Switch off is byte-identical.** Home with the plugin inactive: 37,038 bytes, sha256 `f143f046d31413ef...`. With the plugin active and every switch off: identical. Switch on but no videos found: identical. `DBGRBOX_DISABLE` set (mu-plugin) with the switch on and files present: identical, and the settings screen says "Stopped". Two plain fetches were also identical, so there is no natural variance in the comparison.
- **Install flow as Elie does it.** Five files dragged into Media, Add New (plupload): the status table found all five immediately with no settings save (so `add_attachment` cleared the cache). A repeat upload became `...-720-av1-1.mp4`, was picked as newest, and deleting it fell back to the original. A new file placed in the media plugin folder was not seen until a settings save (the transient is in use), then was preferred over the Media Library copy.
- **(a) Poster before the video loads** (video requests held): `desktop-a-poster.png`, `phone-a-poster.png`. The video had no `src` at the window load event, was opacity 0, at 0 s.
- **(b) Playing**: AV1 chosen and played. Desktop 1440x900 DPR1: `av1-720` (video box 761 CSS px, below the 800 threshold). Phone 390x844 DPR2: `av1-1080` (405 x 2 = 810). Frames at 3 s and 9 s in `*-b-3s.png`, `*-b-9s.png` (the lid is open at 9 s). Faded in on the first `playing` event; muted; no media error.
- **(c) Reduced motion** (emulated): no `src` set, `display:none`, zero video requests; poster and label still show. `*-c-reduced-motion.png`.
- **(d) Pause**: clicking core's "Pause photo motion" gave `video.paused === true`, `is-photo-paused` on the hero, frame frozen (9.45 s stayed 9.45 s); clicking again resumed. Also verified: pauses when scrolled out of view and resumes on return, pauses when the tab is hidden and resumes, and a user pause survives scrolling away and back. `*-d-paused.png`.
- **Console errors: zero** in every visit (a, b, c, both viewports). A deliberately failing video request logs only the browser's own failed-request line and no page error.
- **Network: no video request before the load event.** First `.mp4` request started 57 ms (desktop) and 78 ms (phone) after load. The poster is requested once although it is preloaded, a CSS background and the video poster (and, on core 2.41.0, also preloaded inline by core).
- **LCP** is the poster in all runs (element `.db-mh-backdrop`). Local, three runs each: desktop 1.20 to 1.37 s with the video hero against 1.15 to 1.26 s with the photo hero; phone 1.12 to 1.20 s against 1.09 to 1.13 s. About 50 to 100 ms later on localhost, within noise for this setup; poster is 299 KB WebP against the 329 KB JPEG.
- **CLS**: desktop 0.0144 with the video hero against 0.0157 to 0.0159 with the photo hero; phone 0 for both. The shifts are the existing web-font swap (headline, nav), not the video (layout-shift sources logged).
- **Source choice matrix** (all as specified): DPR1 desktop `av1-720`; DPR2 desktop `av1-1080`; phone DPR1 `av1-720`; phone DPR2/3 `av1-1080`; downlink 4.9 gives 720, 5 and unknown give 1080; Save-Data, `3g`, `2g`, `slow-2g` give poster only with zero video requests; no AV1 gives HEVC then H.264; no codec gives poster only; a missing height falls back to the other height; all files missing gives poster only. This Chromium reports `canPlayType` AV1 "probably", HEVC "" and H.264 "", so HEVC and H.264 selection was tested with `canPlayType` faked; playback of those two codecs was NOT exercised.
- **Pause control**: with core's button removed, our own `db-mh-replay` button was added to `.db-mh-actions`, toggles Pause video / Play video with `aria-pressed`; with no control available the video does not start.
- **Contrast** (real screenshots with the text hidden, lightest 2% of pixels behind each text box, 7 video frames each, at 768, 1024, 1280, 1440, 1920 and 390 wide): headline at least 14.1:1, paragraph at least 10.8:1, kicker and proof at least 13.1:1, secondary and pause buttons at least 18.5:1 (the primary button keeps core's own red fill). See `contrast-analysis.txt`. Before the fix below, 768 and 1024 wide failed (paragraph 1.7:1 and 2.8:1).
- **Fail closed on core 2.43.2** (backdrop is an `<img>`): first view after switching on gave a hero section byte-identical to core's own with no video, chip or class; the second view was byte-identical to the plugin-inactive baseline (41,789 bytes); the settings screen explained why; a settings save retried and failed closed again.

## Deviations from the brief, and why

1. **Chip is top right on desktop too** (brief: bottom right). At 1440x900 and 1280x720 the home hero extends below the fold (hero bottom at y 1019 and 968), so a bottom-right label is not visible on first view. Honesty depends on it being visible.
2. **Mask is `transparent 50%, #000 64%`** (brief: 46% / 62%). With 46% the panel's left edge sat at about 25% opacity, leaving a visible seam at x 712. Starting at 50% removes it.
3. **Copy column limited to the left half at 721px and up** (`max-width: min(660px, calc(50% - 1rem))` on `.db-mh-copy` and `.db-mh-proof`, only while `.has-dbgr-video`). The theme lets the copy run to 39rem, which put the paragraph over the picture on tablets and small laptops (contrast 1.7:1 at 768, 2.8:1 at 1024). The extra class is needed to beat the theme's `.dbf-hero .db-mh-copy` rule.
4. Chip font is 0.7rem (brief: about 0.65rem), for legibility.
5. Posters: 1080 and 720 WebP only (see budget above). The preload carries `type="image/webp"` as well.
6. JS is 4.6 KB raw (1.9 KB gzipped), above "about 3 KB" raw. Not minified, like the rest of the plugin.

## Not verified

- No real phone, Safari, Firefox or Edge. Chromium only. HEVC and H.264 playback not exercised.
- Not the live host (Crazy Domains): its ModSecurity, caching or CDN, how it serves `.mp4` (MIME type, range requests) and the real 2 MB cap were not tested. SQLite stand-in, not MySQL (the `_wp_attached_file LIKE` lookup ran on SQLite only).
- The live site's exact core version is unknown. The captured live home markup passes the edit (unit test); core 2.41.0 passes end to end; core 2.43.2 fails closed by design, so updating core to that markup would switch the video off until the edit is adapted.
- PHP 7.4 was linted and token-guarded, not run. LCP and CLS numbers are from localhost, not field data.
- Core's pause button still reads "Pause photo motion" (core's own label, set by core's script); it works for the video.
- Elie's decision on showing AI-generated food rests with the label and the switch; this build does not change what the food is.
