# Handoff for a coding agent (OpenAI Codex): DoughBoss Growth Catering Box 0.2.0

Written 2026-10-04; status and local-check instructions updated 2026-10-08. Cold start: read this, then `INSTALL.md`. Use Australian English. The owner is Elie.

## 1. Goal and status

- Deliverable: `doughboss-growth-box`, a standalone WordPress plugin (0.2.0). It does two things:
  1. It adds the catering box pages: a story page, a text-only strip, and concept bands.
  2. It adds the home page hero video.
- The pictures and video are not in this plugin. They live in a separate media plugin, `doughboss-growth-media`, which the plugin finds by filename stem in the Media Library.
- Branches and pull requests:
  - PR #70 (the additive 0.2.0 box code) and PR #71 (the supporting release gates) are merged into the default branch `claude/awesome-johnson-bkjh83`.
  - This release-gates branch is `codex/growth-box-release-gates-20261008`; it is local release evidence only and does not publish or install anything.
- Live site: the plugin is **not deployed or installed**. Elie installs the two zips by hand only after the separate gates in `INSTALL.md` are satisfied. Keep every feature switch off until then.
- CI: `.github/workflows/growth-box-ci.yml` covers this folder, its PHP 7.4/8.2 compatibility, shared PHP/Node guards, deterministic code archive, canonical archive validation, and the code-zip byte budget. It does not prove installation or live behaviour.
- Last local verification (2026-10-08; source/worktree evidence, not hosted CI, publication, installation, or live acceptance):
  - PHP 7.4 and 8.2 syntax checks and the shared PHP 7.4 guard are clean on all 18 PHP files.
  - `tests/transform_test.php`: 24 passed.
  - `tests/stem_test.php`: 13 cases, 0 failures.
  - `tests/validate_zip_test.php`: 11 passed, including modified, missing, extra, wrong-root, metadata, non-runtime, traversal, duplicate, and symlink refusals.
  - The current canonical LF rebuild is 18 files, 43,747 bytes (44% of the 100,000-byte budget), SHA-256 `2d235d0fdd5aafc996c628a8ff45c87157d1300b04eda3a0f9e60062f3105d2b`. It is distinct from the historic `3d01…` handoff artifact, whose original ZIP container has not been matched; neither result proves installation or deployment.

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
php tests/validate_zip_test.php     # expect: 11 passed, 0 failed (requires ZipArchive)
php scripts/build-zip.php /tmp/a.zip && php scripts/build-zip.php /tmp/b.zip && cmp /tmp/a.zip /tmp/b.zip
php scripts/validate-zip.php /tmp/a.zip
php scripts/budgets.php /tmp/a.zip
```

The browser end-to-end runs used throwaway scripts outside the repo, so they are not committed. Those runs covered:

- visit, layout shift (CLS), fail-closed, kill switch, admin, and the format matrix;
- the local WordPress runtime in `web/scripts/wp-local/` (see `doughboss-growth/docs/HANDOFF-CODEX.md` §3 for how to start it).

Browser e2e porting remains separate work. The CI workflow now covers source-level PHP, JavaScript, archive, and media-build gates only; it does not replace the real-device or live acceptance gates in Section 5.

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

## 5. Separate release gates still open

1. **Owner decisions B1 to B5:** the choices and approvals in `INSTALL.md` remain owner-only. Merge is not deployment approval.
2. **Real-device media acceptance:** Safari HEVC, iOS H.264 fallback, and Android AV1 must be checked on real devices after the owner provides the separate media payload.
3. **Live/host acceptance:** take a fresh full files-and-MySQL backup with a rollback receipt; install only through the supported host route; then check `/`, `/catering/`, and `/menu/` while all ordering, payment, POS, and public-link decisions remain separately controlled. Local tests, CI, and a ZIP artifact do not establish any of this.
4. **Core compatibility:** this is an additive companion to core 2.41.0, not a core change. Re-check the actual installed core version and the hero transform against that live markup before enabling it.
5. **AI-label state:** the hero remains visibly labelled as a concept preview / AI hero until the two conditions in Section 4 are met (the real box confirmed in writing and AI food replaced with real bakes). This is a content/owner gate, not a CI result.
6. Phase 2 may swap real photographs through a new media zip. No box-code change is needed.

## 6. The pack

The catering box design and production pack is in `catering-box-pack/` at the repo root. Start with `catering-box-pack/README.md`. It holds:

- the PDFs: internal pack, PDS rev A, factory tech pack rev A, merch tech packs;
- dieline v2 (PDF and DXF);
- the section sources and build scripts;
- the artwork flats and photo masters;
- the media plugin source;
- the hero video scripts and MP4s.

Elie approved committing it with the repository still public (2026-10-04). Nothing in it is approved for public marketing use. The status rules at the top of that README apply.

## 7. Restart-safe evidence update - 8 October 2026

Release-engineering scope only: the new Box validator and CI workflow plus the media-builder repair do not change frontend/runtime features. Lead reran Box hero tests (24/24), stems (13/13), validator negative controls (11/11 on both PHP 7.4.33 and 8.2.33), PHP-7.4 guard (18 files), Node syntax and diff checks. ES5 parser and hosted workflow execution remain not_run locally; no Acorn dependency was installed.

Canonical release source must be exported without Windows checkout conversion: git -c core.autocrlf=false archive from exact merged HEAD 7c3cb6f2788ef9360661f54538888d5c2b0c326f. Git records LF blobs while this Windows working tree uses CRLF. The 43747-byte LF code ZIP above differs from the original documented 3d01e761... ZIP; archive/source parity and same-runtime reproducibility do not prove original-artifact identity. Do not substitute a newly built ZIP silently.

The separate Growth 0.1.0 suite on canonical LF source has 7686 passed / 7 failed / 4 skipped out of 7693 assertions, exit 1, zero foreign writes. Windows-only failure classes: /etc/hostname symlink fixture (3), separator-sensitive scope scan (1), hostile NTFS filename fixture (3). The eight additional CRLF failures from the initial export, including four CSV F10 oracles, disappear on the LF export. No test was weakened, deleted or skipped to make the result green. Growth Node has 125 passed / 0 failed / 37 skips in the exported scope. Missing adjacent web/core parity and Acorn coverage remain explicit.

The Media helper now reads tracked website/media-plugin, preserves the doughboss-growth-media/ archive root, refuses an existing output and uses explicit Unix creator metadata. Two Windows builds match: 1759433 bytes, SHA256 aa3f292ea575504270d8abe2f38d33067c59fde43503602b445f5518420ebeeaa; 44 files match source and CRC checks pass. This is a new artifact, not the documented original media ZIP.

Live remains core 2.41.0 with all three companions absent and ordering/payments/split checkout off. UpdraftPlus backup ccce1957292b (8 October 13:21 Sydney) completed 13:33:25 with database plus four wp-content archive groups, about 188.8 MB total. Producer checksums and completion log were inspected; independent downloaded archive verification, off-host copy and restore drill are not proved. Existing retention=2 automatically pruned the unpinned June set; October and September sets are protected. No manual deletion, install, activation, push, hosted CI dispatch or public release occurred.

The protected approval-first core candidate and its separate r3 Astra requirement are outside this batch. A fresh read-only Astra review of the frozen release-engineering diff is pending; no 8.5/10 acceptance or production-ready claim is inferred from these local checks.

### Bounded Astra review and remediation

The first frozen release-engineering review returned fix-first, 8.0/10, with one P2: non-regular entry types were not all rejected. Lead repaired the validator to require readable attributes and the builder's explicit Unix regular-file representation; directory bits, unsupported creator formats, directories, FIFOs, sockets and devices now fail closed. The compressed-size budget is checked before any entry reads. Complete 18-file payload fixtures exercise eight non-regular/unsupported representations, and new budget/direct-access-guard negatives retain the existing controls. The revised validator suite passes 21/21 on both PHP 7.4.33 and 8.2.33; the 18-file compatibility guard and diff check pass. This supersedes the earlier 11-case count for the current candidate. A fresh revised-candidate assurance verdict is still pending; the first score must not be reused.

Anonymous live GET checks again confirm only Bankstown, Revesby and Roselands in the public location response; ordering_open=false, payments_enabled=false and after_hours_preorders_enabled=false. No checkout/payment was exercised. Full website/Square release remains incomplete and gated independently of this engineering fix.
