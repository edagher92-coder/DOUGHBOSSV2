# Handoff for a coding agent (OpenAI Codex): DoughBoss Growth Catering Box 0.2.0

Written 2026-10-04. Cold start: read this, then `INSTALL.md`. Use Australian English. The owner is Elie.

## 1. Goal and status

- Deliverable: `doughboss-growth-box`, a standalone WordPress plugin (0.2.0). It does two things:
  1. It adds the catering box pages: a story page, a text-only strip, and concept bands.
  2. It adds the home page hero video.
- The pictures and video are not in this plugin. They live in a separate media plugin, `doughboss-growth-media`, which the plugin finds by filename stem in the Media Library.
- Branches and pull requests:
  - This branch is `codex/catering-box-0.2.0`. It is the same code as draft PR #70 (branch `ccr-ba2d93d9-lbw916`, head 51d8e1c), plus `tests/`, this file and the pack in `../catering-box-pack/`.
  - PR #69 (0.1.0) is merged into the default branch `claude/awesome-johnson-bkjh83`.
- Live site: the plugin is not installed yet. Elie installs the zips by hand (see `INSTALL.md`).
- No CI covers this folder. `.github/workflows/growth-ci.yml` only triggers on `doughboss-growth/**`.
- Last verified (2026-10-04):
  - `php -l` is clean on every PHP file.
  - `tests/transform_test.php`: 24 passed.
  - `tests/stem_test.php`: 13 cases, 0 failures.
  - The zip is 18 files, 44% of the 100,000-byte budget.
  - The rebuild is byte-identical. sha256 `3d01e76178a35b84dc5aae4efcbbecb0150588bfc1dd66d887fb77beb439cfd0`, which matches the zip handed to Elie.

## 2. Where things are

`doughboss-growth-box/`

| Path | What it is |
|---|---|
| `doughboss-growth-box.php` | Entry point. Defines `DBGRBOX_VERSION`. |
| `uninstall.php` | Uninstall routine. |
| `readme.txt` | Plugin readme. |
| `INSTALL.md` | Owner install guide, rollback steps, and decisions B1 to B5. |
| `includes/class-dbgrbox.php` | Bootstrap. |
| `includes/class-dbgrbox-settings.php` | Options. |
| `includes/class-dbgrbox-admin.php` | Settings screen. Every handler checks capability and nonce. |
| `includes/class-dbgrbox-copy.php` | Copy loader. Reads `content/copy.json`. |
| `includes/class-dbgrbox-manifest.php` | Reads the media plugin's `manifest.json`. |
| `includes/class-dbgrbox-render.php` | Rendering. `can_render()` shows admins a preview. |
| `includes/class-dbgrbox-shortcodes.php` | Shortcodes. |
| `includes/class-dbgrbox-inject.php` | Injection into existing pages. |
| `includes/class-dbgrbox-hero.php` | Hero video. Hooks `shortcode_atts_doughboss_manoush_hero` and `do_shortcode_tag`. `transform()` puts the `<video>` inside `.db-mh-backdrop`, removes the steam layer, adds `has-dbgr-video`, and appends the concept chip. Anything it cannot match exactly is left unchanged (fail-closed). |
| `includes/class-dbgrbox-hero-media.php` | Finds the hero MP4s and poster in the Media Library by filename stem. Accepts WordPress `-1`/`-scaled` style suffixes and rejects look-alikes. |
| `public/js/dbgr-hero.js` | Loader, ES5. Tries formats in `canPlayType` order: AV1, then HEVC, then H.264. Loads after `load` and idle. Skipped under reduced-motion or Save-Data. Pauses when off-screen (IntersectionObserver) and follows core's pause button (MutationObserver). |
| `public/css/dbgr-{box,hero,strip}.css` | Styles. |
| `scripts/build-zip.php`, `scripts/budgets.php` | Deterministic zip builder and size budget. |
| `tests/` | `transform_test.php`, `stem_test.php`, and `fixtures/live-home-hero.html` (the public live hero section from doughboss.com.au, captured 2026-10-03). Not shipped in the zip. |

## 3. How to run every check

From `doughboss-growth-box/` (PHP 8.3):

```
for f in $(git ls-files '*.php'); do php -l "$f" >/dev/null || echo "LINT FAIL $f"; done
php tests/transform_test.php        # expect: done 24 passed, 0 failed
php tests/stem_test.php             # expect: 13 cases, 0 failures
php scripts/build-zip.php /tmp/a.zip && php scripts/build-zip.php /tmp/b.zip && cmp /tmp/a.zip /tmp/b.zip
php scripts/budgets.php /tmp/a.zip
```

The browser end-to-end runs used throwaway scripts outside the repo, so they are not committed. Those runs covered:

- visit, layout shift (CLS), fail-closed, kill switch, admin, and the format matrix;
- the local WordPress runtime in `web/scripts/wp-local/` (see `doughboss-growth/docs/HANDOFF-CODEX.md` §3 for how to start it).

Porting them, and adding a box CI workflow, are open work items (§5).

## 4. Rules that must not break

- **Never invent prices, dates or specs.** Unknowns stay `[CONFIRM]` or `[QUOTE]`.
- **No public link to the box pages without Elie's approval.** The story page stays noindex until every picture is real.
- **The word "Minis" never appears in output from this plugin.**
- **The concept chip stays on the hero video until both conditions are met:**
  - the real box is confirmed in writing;
  - real bakes replace the AI food.

  The hero video is the one owner-approved exception to "no AI food shown" (decided by Elie, 3 October 2026). It is recorded in the media plugin's `assets/hero/hero.json`.
- **Fail-closed.** If the hero markup or media is not exactly as expected, output the core markup unchanged.
- **Code and media stay separate.** Never commit secrets. Never put images or video in this plugin.

## 5. Open work

1. Port the browser e2e checks into `web/scripts/wp-local/box/`. Add a CI workflow for `doughboss-growth-box/**` that runs at least §3.
2. Real-device checks: Safari HEVC, iOS H.264 fallback, Android AV1.
3. Owner decisions B1 to B5 (`INSTALL.md`). Merge of PR #70 is waiting on Elie.
4. Phase 2: swap in real photographs through a new media zip. No code change is needed.

## 6. The pack

The catering box design and production pack is in `catering-box-pack/` at the repo root. Start with `catering-box-pack/README.md`. It holds:

- the PDFs: internal pack, PDS rev A, factory tech pack rev A, merch tech packs;
- dieline v2 (PDF and DXF);
- the section sources and build scripts;
- the artwork flats and photo masters;
- the media plugin source;
- the hero video scripts and MP4s.

Elie approved committing it with the repository still public (2026-10-04). Nothing in it is approved for public marketing use. The status rules at the top of that README apply.
