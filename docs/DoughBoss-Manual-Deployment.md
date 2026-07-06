# DoughBoss — Deployment Manual

**For:** whoever pushes a DoughBoss code change to a real WordPress site (owner, developer, or an agent acting on their behalf).
**Covers:** deploying the plugin itself (`includes/`, `admin/`, `public/`) — plugin v2.12.3, DB schema v1.7.0 at time of writing.
**Does not cover:** the `demo/` static site (that has its own path — a GitHub Actions workflow pushes it to GitHub Pages on every push to `demo/**`; it is not a WordPress install and this manual doesn't apply to it) or the standalone Staff Console PWA (`app/`), which deploys however you host static files today — neither is touched by `build-zip.sh`.

Every command and file path below was checked directly against this repository on **2026-07-03**. Where something can't be verified from the repo alone (anything about the actual state of the live `doughboss.com.au` site), that is called out explicitly rather than assumed.

---

## 0. The honest shape of this pipeline

Say this plainly before anything else: **there is no automated deploy pipeline for the plugin.** `build-zip.sh` produces a zip on whatever machine you run it on; getting that zip onto the live site is a manual upload-and-activate step in wp-admin (or a manual file copy over SFTP/hosting file manager). The only GitHub Actions workflow in this repo (`.github/workflows/pages.yml`) deploys the **static demo site**, not the plugin — don't mistake a green demo-deploy for a plugin deploy. If you want push-to-deploy for the plugin itself, that would be new infrastructure to build, not something you're forgetting to run.

There is also no automated test suite (see §1) and no automated rollback (see §5). This manual is written around those facts, not around a pipeline that doesn't exist.

---

## 1. Pre-deployment checklist

Work through this list, in order, before you package a build for the live site.

### 1.1 Lint the PHP
```bash
bash scripts/dev-check.sh
```
This runs `php -l` (syntax lint only) over every `*.php` file and prints a PASS/FAIL summary. **Understand exactly what this does and doesn't prove:** it catches things that fail to *parse* — it says nothing about whether the logic is correct, and **it always exits `0` by design** (a comment at the top of the script says so explicitly), so it cannot fail a CI gate or block a session even when it finds a `FAIL:` line. Read the printed summary yourself; don't rely on the exit code. Confirm the last line reads `RESULT: PASS` and the count is `N/N passed · 0 failed` before proceeding. If `php` isn't on `$PATH`, the script falls back to `/usr/bin/php` automatically.

There is no PHPUnit harness, no `tests/` directory, and no `composer.json` in this repo — "verification" for a DoughBoss change is `php -l` clean plus the manual QA in §4, not an automated regression suite. Don't tell a client or teammate "tests passed"; say "syntax lint passed, manual QA done."

### 1.2 Confirm what you're actually shipping matches what you audited
Three real bugs were found and fixed today, shipped in **v2.12.3** (`DOUGHBOSS_VERSION` in `doughboss.php:14`), and are sitting in this working tree as uncommitted/committed changes on the `claude/*` branch, **not yet deployed to the live site**:
1. Guest cart token case-mismatch (`includes/class-doughboss-cart.php`, `get_token()`) — was silently losing a new visitor's first cart write on nearly every session.
2. Settings-save wiping unlisted option keys (`admin/class-doughboss-admin.php`, `sanitize_settings()`) — was silently deleting `app_origin` (Staff Console CORS allow-list) and `voucher_campaigns` on any unrelated Settings save.
3. Stripe (4 fields) and POSPal (3 fields) secret keys echoed into admin HTML on every Settings page load — now write-only like the other integrations.

Before you build the zip, confirm these three fixes are actually present in the tree you're about to package — don't assume "today's session" and "the code on disk" are the same thing by the time you deploy:
```bash
grep -n "preg_replace" includes/class-doughboss-cart.php | head -3
grep -n "DoughBoss_Settings::all()" admin/class-doughboss-admin.php | head -3
grep -n "keep_secret" admin/class-doughboss-admin.php | wc -l   # expect 7+ call sites (4 Stripe + 3 POSPal)
```
If any of these come back empty, you are about to ship the *old*, buggy behaviour — stop and check you're on the right branch/commit.

### 1.3 Known blockers and things that are NOT blockers
Deliberately separating "must fix before this deploy" from "known, tracked, acceptable to ship" — don't hold up a deploy over items in the second list without a specific reason:

**Should fix before shipping to a real paying customer's checkout, if not already done (see §1.2):**
- The three v2.12.3 fixes above.

**Known and tracked, not blocking, but worth the deployer's awareness:**
- `includes/class-doughboss-rest-controller.php` is a 2,699-line god-class (route registration + permissions + rate limiting + voucher/checkout/catering/POSPal logic all in one file). Ugly, not currently broken.
- The rate limiter (`rate_limited()`, `class-doughboss-rest-controller.php:889`) keys on raw `REMOTE_ADDR` and is proxy-naive, with a non-atomic get-then-set transient increment. If the live site sits behind Cloudflare or another reverse proxy, be aware checkout/voucher rate limits may throttle *all* customers as one "IP" during a rush, or undercount concurrent hits. Not new in this deploy; not something `build-zip.sh` can fix — a code change, if you decide to prioritize it.
- `vouchers.single_use` is a real schema column that `redeem()` does not honor (multi-use vouchers are schema-representable but functionally unimplemented). Not a regression from today; existing behavior.
- `0000-00-00 00:00:00` datetime defaults on `created_at`/`updated_at`/`redeemed_at` (`includes/class-doughboss-activator.php`) are rejected by MySQL 5.7+/8 running in strict mode with `NO_ZERO_DATE`. **This is a genuine pre-deployment risk if the live host's MySQL has strict mode on** — confirm the target site's `sql_mode` before activating/upgrading if you have any doubt (ask the host, or if you have WP-CLI/DB access: `SELECT @@sql_mode;`). If strict mode is on and includes `NO_ZERO_DATE`/`STRICT_TRANS_TABLES`, `dbDelta()` can error on table creation/alteration.
- `readme.txt`'s `Stable tag` (line 7) currently reads `2.12.1` while `doughboss.php`'s `DOUGHBOSS_VERSION` and `CLAUDE.md` say `2.12.3` — nothing in the repo keeps these in sync automatically. Not functionally important (WordPress reads the plugin header, not `readme.txt`, to know the running version) but bump it by hand if you want the two files to agree, especially before any WordPress.org-style distribution.
- The demo site (`demo/`) and the Staff Console (`app/`) are separate deploy surfaces with their own risk profiles (see the codebase audit doc for detail); this manual is scoped to the WordPress plugin only.

**Do not treat "0 menu items live" or "ordering not yet open" as a deployment blocker** — that's a content/business-configuration step (Settings → import/add menu items, toggle "Accept orders"), not a code deployment concern, and it's the site owner's action to take post-install, not something `build-zip.sh` or an upload can fix.

### 1.4 Take a backup — non-negotiable, before touching the live site
CLAUDE.md's own stated policy (Working with the live site section) is explicit: **never push to production without an explicit go-ahead and a fresh backup.** UpdraftPlus is the backup tool referenced for the live site. Confirm a current backup exists (database **and** files) before you do anything below — this applies whether you're installing DoughBoss for the first time, upgrading an existing install, or just changing Settings. There is no code-level safety net if this step is skipped (see §5, Rollback).

### 1.5 Confirm you're deploying the branch you think you are
Per CLAUDE.md's git workflow: development happens on a designated `claude/*` branch, never straight on a shared base. Before building the zip, confirm:
```bash
git status
git rev-parse --abbrev-ref HEAD
git log --oneline -5
```
Make sure the branch is what you expect and there's nothing uncommitted that should be committed (or, conversely, nothing you're about to package that hasn't been reviewed).

---

## 2. Deployment steps (building and installing the plugin)

There are two ways to get code onto a WordPress site; pick based on what access you have to the live site.

### 2.1 Build the installable zip
From the repo root:
```bash
bash build-zip.sh
```
What this actually does (`build-zip.sh`, read in full):
- Wipes and recreates `dist/`.
- Stages a `dist/doughboss/` directory containing: `doughboss.php`, `uninstall.php`, `readme.txt`, `README.md`, and the `includes/`, `admin/`, `public/` directories, plus `scripts/seed-menu.php` if present (the menu-seeder CLI wrapper) and `languages/` if present.
- Zips it to `dist/doughboss.zip`.

`dist/` and `*.zip` are gitignored (`.gitignore:2-3`) — the zip is a **build artifact**, not something you commit. Rebuild it fresh for every deploy; don't reuse an old zip from a previous session.

Sanity-check the output before uploading:
```bash
unzip -l dist/doughboss.zip | head -20
```
Confirm it has a single top-level `doughboss/` folder (required for a clean WordPress "Upload Plugin" install) and that `includes/`, `admin/`, `public/` are all present.

### 2.2 Install/upgrade on the live WordPress site — manual, no automated path
**This step is manual today.** There is no CI/CD job, no WP-CLI-over-SSH script, and no MCP/API-driven deploy path wired up in this repo for the plugin itself (contrast with the demo site, which auto-deploys via GitHub Actions). Note also: at the time of this audit, no live-site tool connection was available in this environment, so the actual current state of `doughboss.com.au` (what version is installed, whether it's even the same code as this repo) **could not be verified directly** — treat any assumption about "what's live right now" as unconfirmed until checked by whoever has that access.

Whoever does have wp-admin access to the live site performs one of:

**Option A — Upload Plugin (typical path, no server file access needed):**
1. wp-admin → **Plugins → Add New Plugin → Upload Plugin**.
2. Choose `dist/doughboss.zip`, click **Install Now**.
3. If DoughBoss is already installed, WordPress will prompt to replace the existing plugin files — confirm that (this is how an upgrade is applied; WordPress does not diff/merge, it replaces the plugin directory wholesale).
4. Click **Activate Plugin** (fresh install) — if this is an upgrade over an already-active plugin, WordPress keeps it active through the replace-and-you're-done; you generally don't need to manually deactivate/reactivate for an upgrade, but if the flow lands you at the Plugins list, confirm the entry still shows "Active."

**Option B — Direct file copy (SFTP / hosting file manager), for a site you can reach that way:**
1. Unzip `dist/doughboss.zip` locally (or transfer the zip and unzip on the server).
2. Copy/replace the `doughboss/` folder into the site's `wp-content/plugins/doughboss/` directory, overwriting the existing files.
3. No activation step needed if the plugin was already active — WordPress will pick up the new files on the next request. If this is a fresh install, activate it from the Plugins screen afterward.

Either way, **the moment the new code is active, the migration step below runs automatically** — read §3 before you do this, not after.

---

## 3. Migration steps — automatic, but back up first anyway

DoughBoss's schema/data migrations are **not a separate command you run.** `includes/class-doughboss.php`'s constructor calls `maybe_upgrade_db()` → `DoughBoss_Migrations::run()` unconditionally on every request, as soon as the plugin is loaded (`plugins_loaded` → `DoughBoss::instance()`, `doughboss.php`). Concretely:

1. `DoughBoss_Migrations::run()` (`includes/class-doughboss-migrations.php:27`) compares the stored `doughboss_db_version` option against the code's `DOUGHBOSS_DB_VERSION` constant (`1.7.0` as shipped in this repo, `doughboss.php:24`).
2. If the stored version is already `>= 1.7.0`, it returns immediately — a no-op on every normal request once an install is current. This is why upgrading is "just replace the files": the very next page load (admin or front-end, whichever fires first) does the DB work for you.
3. If behind, it takes a short **transient lock** (`doughboss_migrating`, 5 minutes) so two concurrent visitors can't race on `dbDelta()`/capability-seeding at once, then:
   - Re-runs `DoughBoss_Activator::create_tables()` — `dbDelta()` is additive-only, so this adds any new columns/tables the new code needs without touching existing rows.
   - Re-runs `DoughBoss_Activator::add_capabilities()` to self-heal roles/caps.
   - Walks an ordered list of version-gated steps (currently `1.1.0` through `1.7.0` — kitchen role, locations table seed, AUD/GST localisation, Stripe columns, catering tables, voucher redeem capability, voucher-discount columns) for anything `dbDelta` can't express, checkpointing `doughboss_db_version` after each step so a failure partway through doesn't replay everything already applied.
   - Wraps the whole run in a `try/catch (Throwable $e)`: a failing step logs to `error_log` and leaves the version at the last successful checkpoint — **it does not white-screen the site**, and the next request simply retries the remaining steps.
4. Releases the lock.

**What this means practically:**
- You do not need to (and cannot) manually invoke a migration command — it fires the instant the upgraded plugin code is active and something loads WordPress.
- **Take the backup in §1.4 before uploading/copying the new files, not after** — once the new `includes/` lands and any request hits the site, the migration has already tried to run. If something in the new schema is wrong for this specific site (e.g. the strict-mode `NO_ZERO_DATE` issue in §1.3), you want a pre-migration backup to restore to, not a post-migration one.
- If you're upgrading a site that's several versions behind (e.g. anything below `1.6.0`), all the intermediate steps run in sequence on that first post-upgrade request — this is by design and expected, not a sign something is wrong.
- A failed migration step is silent to the end user (no white screen) but **not silent in the logs** — check the server's PHP error log after any upgrade of a site with an older DB version than `1.7.0`, looking specifically for a line starting `DoughBoss migration halted:`. If you see one, the site is running on a partially-migrated schema and needs the underlying issue fixed before it will finish catching up (it'll keep retrying on every request, so fixing the root cause and reloading any page is enough — no manual "resume" needed).

---

## 4. Verification steps

### 4.1 Before packaging (local/repo-level — do this first, see §1.1)
```bash
bash scripts/dev-check.sh
```
Confirm `RESULT: PASS` and `0 failed`. This is the only automated check in the repo — treat everything after this as manual QA.

### 4.2 On the real site, after install/upgrade — a short smoke test
There is no automated end-to-end test for any of this; walk it by hand, in this order, every time:

1. **Menu loads.** Visit the page carrying `[doughboss_menu]` (or `[doughboss_builder]`) as a logged-out visitor. Confirm items render with prices, and that DoughBoss's CSS/JS actually loaded — assets only enqueue on pages that contain one of the five DoughBoss shortcodes (or via the `doughboss_load_assets` filter), so an unstyled/inert page usually means the shortcode isn't actually on that page, not a broken deploy.
2. **Add to cart, as a genuinely fresh guest** (new private/incognito window — a stale cookie from before the upgrade won't exercise the same code path). Add an item, then navigate to the cart page (`[doughboss_cart]`) as a **separate request** and confirm the item is still there. This exact "add, then reload/re-navigate" sequence is what the v2.12.3 cart-token fix (§1.2) addresses — if the cart appears empty on the second request, that regression is back.
3. **Checkout completes.** Go through checkout for a pickup order (delivery too, if the site has it enabled) with a test name/email/phone. Confirm the order confirms and, if tracking is set up, that `[doughboss_order_tracking]` finds it by order number + the same email used at checkout.
4. **Order appears on the Live Order Board.** Log in as a user with `manage_doughboss_kds` (an Administrator, or the `doughboss_kitchen` role) and open the **Order Board** admin page. Confirm the test order from step 3 appears — remember this is a **polling** board (~7 second interval), not push, so allow a few seconds rather than assuming it's broken if it doesn't appear instantly. Run it through Accept → Preparing → Ready and confirm each status change sticks.
5. **Settings save behaves correctly.** Open **DoughBoss → Settings**, change one unrelated field (e.g. toggle "Accept orders" and toggle it back), save, then reload the page and confirm any Stripe/POSPal fields you had configured are still reported as "A key is set" rather than blank/gone, and that nothing else on the form reset. This directly exercises the §1.2 settings-wipe fix.
6. **If Stripe or POSPal is meant to be live on this site,** confirm their status lines on the Settings page read as expected ("card payments are ON (Live mode)" / "POSPal is configured and enabled") — see §6 below; this is as much a post-deploy check as a functional one, because plugin file upgrades don't touch the settings the site already had configured, but it's worth confirming nothing about the upgrade broke the *reading* of those settings.
7. **Check the PHP error log** for anything new since the deploy, specifically the migration-halt message named in §3, and any unrelated fatal/warning that wasn't there pre-deploy.

If you changed anything touching money (cart totals, vouchers, Stripe), also work through the higher-risk manual-QA list in `docs/DoughBoss-Manual-Developer-Setup.md` §5 (GST-inclusive/exclusive branches, voucher double-redemption, Stripe PaymentIntent replay, voucher code typo-rejection) — those are the classes flagged as highest-risk precisely because nothing automated exercises them.

---

## 5. Rollback process — be honest, there isn't an automated one

**There is no rollback command, no version-diffing, and no "undo" button.** WordPress does not keep a history of plugin file versions the way a deploy platform might — when you upload/copy new plugin files over old ones, the old files are simply gone from that directory. Rolling back means one of two things, and both require you to have prepared for this *before* the deploy:

### 5.1 Restore from the backup taken in §1.4
This is the primary rollback path and the reason §1.4 is non-negotiable. Restoring a full site backup (files + database) taken immediately before the deploy returns both the old plugin code *and* the pre-migration database state together — this matters because a migration (§3) may have already altered the schema/data before you decide you need to roll back, and restoring files alone without also restoring the database can leave you with old code pointed at a partially-migrated database, which is its own broken state. Use whatever backup tool the live site has (UpdraftPlus, per CLAUDE.md) to restore the pre-deploy snapshot.

### 5.2 Reinstall the previous plugin zip, if you kept one
If you archived the previous version's `dist/doughboss.zip` (or can rebuild it by checking out the previous release commit/tag and running `bash build-zip.sh` again) before you built today's zip, you can re-upload that older zip the same way as §2.2 to put the old code back. Two things this does **not** undo on its own:
- **Schema changes already applied by the migration runner** (§3) are additive (`dbDelta` never drops columns), so reinstalling older code does not remove new columns/tables the newer version added — it just stops using them. This is usually harmless (old code ignores columns it doesn't know about) but is not a true "undo."
- **Any data written under the new version** (new orders, new vouchers, changed settings) is untouched by a code-only rollback — only a full backup restore (§5.1) undoes data changes, not a file-level rollback.

Because DoughBoss doesn't tag/keep old zips anywhere in this repo (`dist/` is gitignored and gets wiped by every `build-zip.sh` run), **you must deliberately keep a copy of the previous zip yourself** — outside `dist/`, e.g. renamed to `doughboss-2.12.2.zip` in a dated backups folder — before overwriting it with a new build, if you want this option available. If you didn't keep one, your only path back is §5.1, or checking out the previous version's commit in git and rebuilding.

### 5.3 What to actually do if something breaks post-deploy
1. Check the PHP error log first (§4.2 step 7) — a fatal error from a code issue (not a data issue) is often fixable in place faster than a full rollback (e.g. reverting one file).
2. If it's a data-affecting issue (a migration step failed, or the new code corrupted something), restore the full backup (§5.1) rather than trying to hand-patch the database.
3. If it's a code-only regression with no data impact, either fix forward (patch the specific file and re-deploy) or reinstall the previous zip (§5.2) if you have one.
4. Either way, **note what happened** — there's no CI/CD audit trail for plugin deploys in this repo, so a written note of "deployed vX, saw Y, did Z" is the only record that will exist afterward.

---

## 6. Post-deployment checks

Settings persist across a plugin file upgrade (they live in the `doughboss_settings` option in the database, untouched by replacing plugin files) — but that's exactly why it's worth explicitly re-confirming the *intended* on/off state of every optional integration after a deploy, rather than assuming "it was fine before, so it's fine now."

1. **Watch the error log for the first while after deploy.** Specifically look for:
   - `DoughBoss migration halted: ...` (see §3) — means the DB schema didn't fully catch up.
   - Any new fatal/warning referencing `includes/` or `admin/` files that wasn't present before the deploy.
2. **Confirm Stripe is in the intended state.** DoughBoss → Settings → Payments (Stripe): the status line reads either "card payments are OFF" or "card payments are ON (Test/Live mode)" — computed from `payments_enabled()` AND both keys present for the active mode. If this site is meant to be taking real card payments and it now reads OFF (or Test when it should be Live), the deploy didn't change the stored settings, but it's worth confirming nothing about the keys was lost — check the "A key is set" status lines under each of the four secret fields.
3. **Confirm POSPal is in the intended state**, if used: Settings → POSPal POS status line ("POSPal is configured and enabled" vs. "not connected yet"), and specifically whether the coupon-grant leg is on (requires the master toggle **and** the $5 coupon-rule UID filled in for at least one store) — this is easy to have half-configured without noticing, per the troubleshooting guidance in `docs/DoughBoss-Manual-Admin.md` §7.
4. **Confirm the real-time/notification channels (Mercure, ntfy, SMS, receipt printer) are each in the state you expect** — each is independently off by default and gated by its own `*_ready()` check; a deploy doesn't change these, but it's a good moment to verify nothing was accidentally toggled during testing on a staging copy and then carried into what got deployed.
5. **Re-run the smoke test in §4.2** against the live site specifically (not just a staging copy) if you haven't already — menu loads, add-to-cart survives a second request, checkout completes, order appears on the board.
6. **If you touched `readme.txt`,** confirm its `Stable tag` matches `DOUGHBOSS_VERSION` in `doughboss.php` — nothing enforces this automatically (see §1.3), and it's a five-second check while you're already in the file.
7. **Confirm the backup taken in §1.4 is still retained** for a reasonable window (not immediately deleted) — it's your rollback path (§5.1) if a problem surfaces a few hours or days later rather than immediately.

---

## Related reading
- `docs/DoughBoss-Manual-Developer-Setup.md` — environment variables, local dev loop, the higher-risk manual-QA list for money-path classes.
- `docs/DoughBoss-Manual-Admin.md` — what each Settings field does, day-to-day owner/manager operation, voucher/POSPal troubleshooting.
- `docs/DoughBoss-Codebase-Strengths-Weaknesses-Report.md` — the full audit behind §1.2/§1.3 above (what was fixed today, what's tracked-but-not-blocking, and why).
- `CLAUDE.md` (repo root) — architecture map and stated project policies (e.g. "never push to production without an explicit go-ahead and a fresh backup," quoted in §1.4). Treat its version numbers as stale (see the Developer Setup manual's §1) but its stated *policies* — backups, branch discipline, no live curl of the production site — as current and binding.
- `build-zip.sh`, `includes/class-doughboss-migrations.php`, `includes/class-doughboss-activator.php`, `uninstall.php` — the actual source this manual describes; re-read these directly if in doubt, they're short and this manual doesn't paraphrase anything it doesn't cite.

*This manual describes the plugin as it exists in this repository (v2.12.3 / DB v1.7.0) on 2026-07-03. It does not describe the current state of any live WordPress installation — no live-site tool connection was available to verify that independently while writing this. Confirm the live site's actual installed version (Plugins screen, or the DoughBoss version string if shown in Settings) before assuming any of the "already fixed" items in §1.2 are actually running there.*
