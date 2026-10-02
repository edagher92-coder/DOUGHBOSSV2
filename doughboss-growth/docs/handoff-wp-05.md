# WP-05 hand-off: VIP waitlist and coming-soon section

Status: built and verified as listed below. Everything is off by default. The waitlist cannot be enabled while the sender legal name or the privacy-policy URL is empty (WP-01 enforces it; the module re-checks). No company name is hard-coded anywhere.

## What exists

- `includes/waitlist/class-doughboss-growth-waitlist.php` (`DoughBoss_Growth_Waitlist`, registry entry for module `waitlist`): tables `doughboss_growth_waitlist` and `doughboss_growth_suppression` (`schema()`), validation, double opt-in, form token, rate limits, confirm and opt-out, the email-link pages, retention purge, outbox webhook handler, staff CSV export, shortcode `[doughboss_growth_waitlist store=""]`, admin tab "VIP waitlist".
- `-waitlist-rest.php`: `GET /form-token`, `POST /waitlist`, `POST /waitlist/confirm`, `POST /waitlist/unsubscribe` (all `no-store`).
- `-waitlist-privacy.php`: WordPress exporter and eraser.
- `-coming-soon.php` (`DoughBoss_Growth_Coming_Soon`, module `coming_soon`): shortcode `[doughboss_growth_coming_soon cards="" surface="" form="1"]`, optional home ribbon, tilt cards, admin tab "Coming soon".
- `public/js/dbgr-waitlist.js`, `public/js/dbgr-tilt-cards.js`, `public/css/dbgr-coming-soon.css`.
- Tests: `tests/test-waitlist.php`, `tests/stubs-waitlist.php`, `tests/waitlist.test.js`, `tests/tilt-cards.test.js`; browser check `web/scripts/wp-local/growth/wp05-waitlist.mjs`.

## Behaviour to know

- Consent: a separate, unticked, required box. Wording names the sender, covers email (and text only if a mobile number is given) and says how to withdraw. The wording version is derived from the wording (`wl-` + 12 hex), so a new sender name is a new version and a stale form is refused. Consent must be exactly `1`.
- Form token: HMAC over issue time (`wp_salt('nonce')`), refused when younger than 3 s (work-breakdown figure; the architecture text says 30 s, a person cannot reliably wait 30 s) or older than 24 h. The script waits for the token to be old enough before posting.
- Limits (fail closed, 503 on any storage error): 5 per hour per hashed address, 3 per day per email hash, 300 per day for the whole form. Honeypot `website` filled returns the identical success body, stores nothing and uses no limiter slot. Confirm and opt-out have their own 30 per hour per address bucket (engineering proposal).
- No enumeration of the list in the answers: new, pending, confirmed, opted-out and suppressed all return the same message. Residual: a new or pending address sends an email inline, so response time can differ from a confirmed one. Not mitigated.
- Confirm and opt-out change state only on POST. The email link opens a page with a button; a mail scanner's GET changes nothing (browser-proven). One-click `List-Unsubscribe-Post` works. The opt-out token is derived (HMAC of id and email hash), stateless, valid for every later message (`DoughBoss_Growth_Waitlist::unsubscribe_url( $id, $email_hash )` is the function later mail must use). The confirm token is 160 random bits, stored hashed, single use, replaced when a link is resent.
- Opt-out writes a suppression hash first, then updates the row; it reports an error if the hash cannot be recorded. Opted-out rows lose their details after 30 days. Erasure keeps an existing opt-out hash (reported to WordPress as retained); an erased person who never opted out is not suppressed unless the filter `doughboss_growth_waitlist_suppress_on_erase` returns true.
- A person who opted out and signs up again gets the normal message but nothing is stored or sent.
- Retention (daily cron `doughboss_growth_retention_purge`): pending after `retention_pending_days` (30); confirmed never until `retention_confirmed_months` is set.
- Notification webhook: only when `notify_webhook_url` and `DOUGHBOSS_GROWTH_WEBHOOK_SECRET` both exist; own outbox channel `waitlist_hook`; payload is `event, id, store_pref, confirmed_at` (no email or name); `X-DoughBoss-Growth-Signature: sha256=` HMAC of the exact body.
- No interest picker exists and no code path can show one (`interests_json` stays NULL). The mobile number is stored but nothing sends text messages.
- Coming soon: headline and text come from settings but pass `DoughBoss_Growth_Ledger::lint_public( $text, null )` at render time; a failing value (digit, `$`, `%`, dietary word, the working name) falls back to the neutral default, even if written straight into the database. Cards render only for ledger claims that are confirmed, sourced and lint-clean; none ship. Tilt is CSS custom properties on a hover card (not the hero, no canvas); flat under reduced motion in CSS and in the script (live).
- `coming_soon_view` only allows `surface: home` in `events.json`, so only the ribbon and `surface="home"` sections send it. `waitlist_submit` is sent only after a success answer. Events dropped before consent are retried once when consent changes.

## What was run (evidence)

| Check | Result |
| --- | --- |
| `php tests/run.php waitlist`, PHP 8.3.6 | 564 assertions, 564 passed, 0 failed, 0 foreign writes |
| same, real PHP 7.4.33 (Playground WASM build) | 563 passed, 0 failed, 1 skipped (kill-switch test needs a sub-process) |
| `php tests/run.php` (full, 8.3) | 4039 assertions, 4033 passed, 6 failed, 1 skipped. The 6 are WP-01 `test-core-lifecycle` dbDelta-count tests that hard-code 2 schema statements; they already failed without my files (5 vs 2, from WP-11) and now see 7. Not mine; see request 4 |
| `php -l`, `scripts/php74-guard.php` | clean, 53 files |
| `node --test tests/waitlist.test.js` / `tilt-cards.test.js` | 11 / 8 pass, 0 skipped |
| other node suites (consent, datalayer, core) | 22 / 26 / 24 pass |
| ES5 gate | 4 files, no violations; planted arrow function fails it (negative control, in `waitlist.test.js`) |
| Mutation checks (my scratch copies, not committed) | 22 PHP mutations (honeypot, consent, GET acting, token age, limits, single-use token, suppression, rollback, lint fallback, CSV neutralising, webhook PII and others) and 9 + 4 JS mutations: all killed after I added one assertion for a surviving JS mutation |
| `build-zip.php`, `validate-zip.php`, `budgets.php` | 40 files, 164,616 bytes (16% of 1.0 MB), valid |
| `web`: `npx vitest run`, `npx eslint` on `wp05-waitlist.mjs` | 780 passed; eslint clean |
| Browser, `wp05-waitlist.mjs`, Playground PHP 8.2 / WP 7.1 / SQLite, core 2.43.2, port 9405, state `/tmp/wpl-WP-05` | 97 of 97 checks passed (with the scratch registry patch below). Runtime stopped. Screenshots in `/tmp/wpl-WP-05/shots/`; run logs `/tmp/wpl-WP-05/run{1,2,3}.log` |

Browser checks include: `grep -i minis` on the whole anonymous page source of the coming-soon page and the home page with the ribbon is 0; consent box unticked and required; honeypot off-screen and skipped by Tab; axe 0 violations (section desktop and Pixel 7, confirm page, cards, ribbon); a sign-up end to end with the captured email, GET-then-POST confirm, single-use link, duplicate gets the identical message, opt-out by button and by one-click POST, re-sign-up after opt-out, refusals (early token, forged token, `on` as consent, stale wording, unknown shop, header injection), mail failure leaves no row, the real limiter on SQLite returns 429 with Retry-After on the sixth request, tilt leans under motion and is flat under reduced motion (live toggle), ribbon off by default and on after a nonce-protected manager save, `coming_soon_view` once, and every flag off again at the end. The runtime debug log gained no PHP notice or warning during the final run.

Scratch-only aids (never in the source tree): `wp05-mailcapture.php` in the runtime's copy of the plugin (captures `wp_mail`, can force failure by file content, and sets the visitor address through the real `doughboss_growth_client_ip` filter), one extra require line in the copy's main file, a temporary claim in the copy's `claims.json` (restored), and the registry patch below. The sender name `Example Trading Pty Ltd` is a placeholder used only there and in tests.

An early run found one 503 in the limit test: the runtime's file mount served a stale "mail failure" file. I changed the toggle to read file content, and it did not recur. A real bug the first run did find and I fixed: `enabled()` called a translation function at `plugins_loaded` (WordPress 6.7 notice on every request); the log is now clean.

## Not run, and why

- MySQL: the real DDL, unique key and INSERT IGNORE ran on SQLite only (harness and runtime). Real dbDelta and MySQL affected-row behaviour are untested.
- A real mail server, a real inbox, spam scoring, DKIM/SPF. The runtime cannot send mail.
- The webhook against a real receiver (fake transport only).
- Core 2.41.0 (baseline B) in a browser; only 2.43.2 was used. Chromium only; axe is automated, not a full WCAG audit.
- The exporter and eraser through the real Tools screens (they are tested by calling the registered callbacks).
- Behind the host's real proxy: the visitor-address limit depends on `REMOTE_ADDR`.
- The CI workflow (no runner).

## [CONFIRM] gaps (also listed in the admin tab "VIP waitlist")

- Sender legal name (and ABN if shown) and privacy-policy URL: required to enable; not supplied.
- Sender contact details (address or phone) for the email footer; only the legal name is printed.
- Retention for confirmed sign-ups (`retention_confirmed_months`); unset means never deleted.
- Consent wording review by Elie and a solicitor; text messages are covered by the wording but none are sent and no SMS opt-out exists.
- Whether an erased person who never opted out may stay on the opt-out list as a hash (default: not kept), and what happens when someone who opted out asks to join again (default: stored and sent nothing).
- Whether the host puts all visitors behind one address (the 5-per-hour limit would be shared); a real address can be supplied through `doughboss_growth_client_ip`.
- The mobile field: keep or drop.
- Which claims, if any, may appear as cards (none confirmed). If Elie's "no 3D" ruling extends to CSS tilt cards, remove `dbgr-tilt-cards.js`; nothing else depends on it.

## Contract change requests (nothing edited outside WP-05's files)

1. **Registry (WP-01 file `includes/class-doughboss-growth.php`, REQUIRED before the waitlist is enabled).** The `waitlist` module is initialised only while its flag is on. Observed on the unpatched runtime (run 1): with the flags off, a page holding `[doughboss_growth_coming_soon]` or `[doughboss_growth_waitlist]` prints the raw tag, the opt-out route returns 404, an opt-out link in an old email stops working after the feature is switched off, and the privacy exporter and eraser disappear. A person must always be able to leave and stored data must stay exportable and erasable. Fix, proven in the scratch copy (run 3, 97 of 97): add `'always' => true,` to the `waitlist` registry entry and, at the top of `module_wanted()`, `if ( ! empty( $module['always'] ) ) { return true; }`. The class's `init()` already behaves correctly in that mode (flag off: only the opt-out route and page, privacy tooling, purge hook, CSV export, empty stub shortcodes; no sign-up route, no cron scheduling, no form). Diff: `/tmp/wpl-WP-05/registry-patch.diff`.
2. **Ribbon switch.** The settings sanitiser keeps only WP-01's keys, so the ribbon switch lives in its own option `doughboss_growth_coming_soon` (`{ "ribbon": 1 }`, off by default) with admin-post action `doughboss_growth_save_coming_soon` (a new name, not in the frozen list). Please add the option to `DoughBoss_Growth_Activator::OPTIONS` (so a data-deleting uninstall removes it) or fold it into settings as `coming_soon_ribbon`.
3. **DB version.** Two tables were added: raise `DOUGHBOSS_GROWTH_DB_VERSION` at integration (WP-01 contract item 3). They are already in `TABLE_SUFFIXES`.
4. **WP-01 tests.** `tests/test-core-lifecycle.php` hard-codes 2 schema statements and fails whenever any module file is present; derive the expected count from `DoughBoss_Growth::schemas()` or use the registry override.
5. **Events.** `coming_soon_view.surface` allows only `home`, so a standalone coming-soon page cannot report a view. Add `page` to the enum in `web/src/lib/analytics/events.ts` (WP-03) if a view event is wanted there.
6. **Architecture text.** Section 3.4 says the form token is valid from 30 s; the work breakdown says 3 s. I built 3 s.
