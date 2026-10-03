# WP-08 hand-off: server-side conversions and offline export

Status: built and verified as listed below. Everything is off by default. With the `server_conversions` flag off nothing in this module runs, no hook or outbox channel is registered and nothing is queued or sent. **Nothing has been sent to Google or Meta by anyone: no GA4 secret or Meta token exists in any test or runtime, and every test uses the harness's fake transport, which fails the test on an undeclared request.**

## What exists

All under `includes/conversions/` (registry entry `conversions`, flag `server_conversions`, `needs_storage`; the module has no tables of its own and uses the shared outbox):

- `class-doughboss-growth-conversions.php` (`DoughBoss_Growth_Conversions`, entry class): hooks, consent gating, payload allow-list, money, admin tab.
- `class-doughboss-growth-ga4.php` (`DoughBoss_Growth_Ga4`): outbox handler for channel `ga4` (GA4 Measurement Protocol).
- `class-doughboss-growth-meta.php` (`DoughBoss_Growth_Meta`): outbox handler for channel `meta` (Meta Conversions API), match keys, hashing.
- `class-doughboss-growth-offline-export.php` (`DoughBoss_Growth_Offline_Export`): the Google Ads offline-conversion CSV and its admin-post handler.

Tests and helpers: `tests/test-conversions.php` (680 assertions in 41 cases), `tests/stubs-conversions.php` (stubs core's `DoughBoss_Catering::get()` and `wp_timezone()`; `DoughBoss_Order::get()` comes from WP-04's `stubs-attribution.php`), `tests/fixtures/conversions-money.json` (cases for `to_cents()`), `tests/fixtures/conversions-bodies.json` (hand-written expected provider request bodies). Browser script: `web/scripts/wp-local/growth/wp08-conversions.mjs`.

### Behaviour

| Trigger (core hook, priority 30, after WP-04's record at 20) | Event | event_id | Channels |
| --- | --- | --- | --- |
| `doughboss_order_created`, `doughboss_order_payment_status_changed` to `paid`, when the STORED order row has `payment_status` exactly `paid` | `purchase` | `order:{order_number}` | GA4 (needs `measurement` consent), Meta `Purchase` (needs `advertising` consent) |
| `doughboss_order_payment_status_changed` to `refunded` from `paid` (row must say refunded) | `refund` | `refund:{order_number}` | GA4 only (Meta has no refund event) |
| `doughboss_catering_enquiry_created` | `generate_lead` (`form = catering_enquiry`, no value) | `lead:{enquiry_number}` | GA4, Meta `Lead` |

- **Consent.** Read from the snapshot WP-04 stored with the order or enquiry (`for_subject()`); no stored record, a malformed one, or a non-boolean flag all mean no consent: nothing queued. The flags travel in the payload (`consent: {m, a, v}`) and each handler re-checks them at delivery before any request (a row without the flag is parked terminal, zero requests).
- **Pay-at-shop (unpaid) orders** never produce a `purchase`; the hook's own `$data` is ignored, only the stored row counts. The same order becoming paid later does.
- **Idempotence.** The outbox UNIQUE `(channel, event_id)`: a replayed `doughboss_order_created`, or created followed by status `paid`, queues once per channel.
- **No personal data.** Payloads hold only: `v, event, event_id, event_time, currency, transaction_id, value_cents, form, consent`, and for Meta `fbc` (click id) and, ONLY when `send_hashed_identifiers` is 1 and advertising consent was given, `em` and `ph` (SHA-256 of the lower-cased email and of the Australian number as `61...`; a number that is not Australian is dropped, never guessed). GA4 never gets match keys. Hashing happens in memory at queue time; the plain values are never stored, queued or logged. Validation drops unknown keys and refuses a payload that carries a personal-data key.
- **Meta sends nothing without something to match on** (a click id or, if enabled, a hash): no `meta` row is queued otherwise.
- **Money.** Integer cents from the stored decimal (`to_cents()` parses strings without floating-point drift, half a cent rounds up, refuses negative, non-numeric, scientific notation and over 999,999.99), converted to a decimal AUD number once in the handler; the JSON is encoded with `serialize_precision` pinned to -1 so `35.99` is written as `35.99` on any host (when the host disables `ini_set()` the host's own precision applies). Currency must be `AUD`, else nothing is queued.
- **Filter `doughboss_growth_conversion_payload( $payload, $channel, $event )`.** Output re-validated: a non-array, an added personal-data key, a changed event, event id, consent flags, currency or match key, or a malformed value is refused (nothing queued, logged as event and channel only); unknown keys are stripped; a harmless change (for example a different value inside the range) is applied.
- **Delivery.** GA4: POST to the Measurement Protocol collect endpoint, per-order pseudonymous `client_id` derived from the event id, `non_personalized_ads` true when advertising consent is absent, `timestamp_micros` only when the event is under 71 hours old. Meta: POST to `/{version}/{pixel}/events`, `action_source` website, `event_source_url` the site home page, the token in the JSON body (never the URL). Both go through `DoughBoss_Growth_Http` (https, public host, 10 s, no redirects, redacted logs; the log line has the URL without its query, so the GA4 secret is never logged). 5xx, 429 and transport errors retry on the outbox backoff; other 4xx is terminal; a Meta event over 6.5 days old is terminal.
- **Secrets.** Only `DOUGHBOSS_GROWTH_GA4_API_SECRET` and `DOUGHBOSS_GROWTH_META_CAPI_TOKEN` from the environment or wp-config. A channel's handler is registered only when its id (settings) and its secret both exist; otherwise nothing is queued for it. The admin tab shows set / not set, never a value.
- **Offline export** (`admin-post` `doughboss_growth_export_offline_conversions`, manager capability AND nonce AND flag on): columns `Google Click ID, Conversion Name, Conversion Time, Conversion Value, Conversion Currency, Order ID`. Rows only for enquiries whose stored consent has advertising consent AND a click id that passes `^[A-Za-z0-9_][A-Za-z0-9_-]{9,119}$`; `Catering quote sent` at `quoted_at`, `Catering order won` at `balance_paid_at` (not for a lost enquiry); times converted from the site timezone (core stores enquiry times there) to UTC with `+0000`; value = core `quote_total` with two decimals (empty when zero); order id = enquiry number (so a repeat upload is recognised as a duplicate). Window by `from` and `to` (`YYYY-MM-DD`, site timezone; default the last 7 days). Page size 200, at most 100 pages and 5,000 rows. Every text cell is neutralised against spreadsheet formulas; an unaccepted click id leaves the row out.
- **Admin tab** "Conversions" (Growth screen): readiness of each destination (booleans), hashed-identifier setting, outbox counts by channel and status, the [CONFIRM] list, and the export form.

## Decisions I made (flag them if you disagree)

1. **`doughboss_catering_status_changed` is not hooked.** Core already stores `quoted_at`, `balance_paid_at` and the totals, and the export reads them on demand through `DoughBoss_Catering::get()`, which is more accurate than the moment a hook ran and needs no new storage (the companion may not add options or tables outside the frozen list).
2. **Quote rows carry the quote total as the value.** The task said "value from core enquiry totals"; the conversion plan (section 4) says no value at launch for the first three actions and does not decide rows 6 and 7. Easy to blank: [CONFIRM] below.
3. **Only `gclid` is exported**, per the task. `gbraid` and `wbraid` need separate template columns that the plan marks `[VERIFY]`.
4. **Meta `event_source_url` is the site home page** and the **Graph API version is `v22.0`**: both INFERRED and unverified, listed as [CONFIRM].
5. **GA4 `client_id` is a per-order pseudonymous value**, because WP-04 does not capture the browser's GA client id. Server events therefore count as separate users and are not linked to the browser session.
6. **GA4 `purchase` has no `items` array** (the task did not ask for line items).
7. **Order priority 30, not 20,** so the attribution record WP-04 writes at priority 20 always exists when this runs. A test proves the ordering (a record written at 20 is visible).
8. **A visitor with advertising-only consent and no click id** has no order record (WP-04 writes an order record only when it holds attribution), so nothing is sent for that order: fail closed. Measurement-consenting visitors almost always have a record because the landing path is kept.

## What was run (evidence)

| Check | Result |
| --- | --- |
| `php -l` on the 4 module files, `tests/test-conversions.php`, `tests/stubs-conversions.php` | clean (PHP 8.3.6) |
| `php tests/run.php conversions` | 680 assertions, 680 passed, 0 failed, 0 skipped, 0 foreign writes |
| `php tests/run.php` (everything) | 5,243 assertions: 5,237 passed, 6 failed, 1 skipped, 0 foreign writes. **The 6 failures are WP-01's `test-core-lifecycle.php` and were failing before I started** (they assert the shared schema is exactly the two shared tables; WP-04, WP-05 and WP-11 added tables). My module adds no table. The 1 skip is WP-11's (core source path). |
| `php scripts/php74-guard.php` | 67 files, no violations (PHP 8.3 runs the guard; a real 7.4 interpreter was not run by me) |
| `ACORN_PATH=web/node_modules/acorn node scripts/es5-check.mjs` | 7 files, no violations (this package ships no JavaScript) |
| Mutation check on a scratch copy | 23 deliberate breakages of the production code (consent gates removed, unpaid purchase allowed, priority 10, hashing ignoring its setting, Meta gating on the wrong flag, click-id allow-list relaxed, formula neutraliser removed, float cents, refund without a paid original, handler consent re-check removed on both channels, lost enquiries exported, UTC instead of Sydney, nonce removed, capability removed, wrong fbc prefix, lead carrying a value, random client id, filter output unvalidated, dollars instead of cents, no match-key guard, no `non_personalized_ads`, no stored-record requirement): every one failed the suite (23 of 23 killed) |
| `build-zip.php`, `validate-zip.php` (scratch path) | valid, 51 files, 220,457 bytes (22% of budget); the four module files are in the archive. Scratch output removed. |
| `web`: `npx eslint scripts/wp-local/growth/wp08-conversions.mjs` | clean |
| Browser, `wp08-conversions.mjs`, Playground PHP 8.2 / WP 7.1, core 2.43.2, port 9408, state `/tmp/wpl-WP-08`, fresh runtime | 31 of 31 checks passed (log: `/tmp/wpl-WP-08-run.log`). Runtime stopped, port closed, no process left. |

Negative controls in the PHP suite: a planted email must trip the privacy assertion; planted formula click ids (`=HYPERLINK(...)`, `+1+cmd|calc!A0`, `@SUM(...)`, `-2+3`, a comma, a too-short id) must not reach the file; a payload filter that adds an email, a phone, changes the event id or raises consent must be refused; planted outbox rows without consent or with a bad value must be parked with zero requests; a stale or missing nonce, a user without the capability, and a feature-off request must be refused; the scope scan's provider-URL pattern must match a planted URL.

Browser checks, on the real core and the real WordPress (no outbound request possible, no GA4 or Meta secret set; a webhook URL that is never called is the only destination so the flag can be on):
- Flags off: no Conversions tab, the export action is not served.
- Flags on: `/health` shows the conversions module active and storage ready; the tab renders with GA4 and Meta "not ready", an empty queue, the [CONFIRM] gaps and an export form with a nonce and no secret.
- A visitor lands with a Google click id, accepts all cookies, submits the REAL core catering form: core accepts it with the module on, no console or HTTP errors, nothing is queued.
- Core's own staff REST route quotes the enquiry (1234.56): the export is a `text/csv` attachment with exactly the Google columns and exactly one row, `Catering quote sent`, the click id, `1234.56`, `AUD`, the enquiry number, and a UTC time within three minutes of now (core stored it in Sydney time with the site timezone set to Australia/Sydney, so this proves the conversion on the real system). Paid-only and an excluded window are header-only. No email, name or secret in the file.
- No nonce: 403. A visitor: no CSV. A bad stage, bad dates or a reversed window: 400 and no download.
- Cleanup: every flag off again, site timezone restored.

## Not run, and why

- **No request to GA4 or Meta, ever.** Needs real secrets from accounts the right entity owns. The request bodies are proved against hand-written fixtures and the delivery path against the fake transport, not against the platforms. The GA4 live endpoint accepts and silently ignores malformed events, so a sample must go through its validation endpoint before relying on it (listed as [CONFIRM]).
- **A paid order, a refund and a paid catering leg in a browser.** Payments are off on the local runtime and no provider may be contacted. They are covered in the PHP suite against a stub `DoughBoss_Order::get()` and `DoughBoss_Catering::get()` and the real outbox on SQLite, NOT against core's real checkout. The real core `quoted_at` and the real `DoughBoss_Catering::get()` shape were exercised in the browser run; `balance_paid_at` was read in 2.41.0 and 2.43.2 source (`class-doughboss-catering.php`, `mark_payment_paid`) but not driven.
- **MySQL.** The suite runs the real `CREATE TABLE` statements and the real outbox SQL on SQLite; the Playground runtime also uses SQLite. `GROUP BY` counts and the paged attribution read were not run on MySQL.
- **PHP 7.4 interpreter and the CI workflow** (no runner): the guard ran on 8.3.
- **The Google Ads upload.** The file has not been uploaded to any account; its column names, the `+0000` time format and the Order ID column follow the conversion plan and are [VERIFY] against the account's own template.
- **Core 2.41.0 (baseline B) in a browser:** only 2.43.2 was run. The hooks and `get()` accessors used exist in both (read in source).

## [CONFIRM] gaps (also shown on the Conversions tab)

- GA4 measurement id and Measurement Protocol secret, Meta pixel id and Conversions API token, from the accounts owned by the right entity. Nothing is queued for a channel until both parts exist.
- **Privacy policy** must say that order and enquiry details and consented advertising identifiers go from the server to Google and Meta, before this is enabled (owner gate in the work breakdown).
- **GST basis** of the values sent (order `total`, enquiry `quote_total` are sent as stored in core).
- **Whether quote rows should carry a value** (see decision 2).
- **Google Ads conversion action names** `Catering quote sent` and `Catering order won` must match the account exactly; the template columns (including Order ID and `gbraid`/`wbraid`), the time format and the maximum click age are [VERIFY] against the account.
- **Meta Graph API version** (`v22.0`) and `event_source_url` against current Meta documentation.
- **GA4 validation endpoint** run before relying on any event.
- **Double counting of leads in GA4:** Tag Manager also pushes `generate_lead` from the browser (WP-03). Keep one source for the GA4 lead (the server or the tag), or leads count twice. Meta de-duplicates on `event_id` only if the pixel sends the same id, which the browser event does not yet.
- **Hashed identifiers** stay off until the privacy policy covers them.
- **No retention rule** for outbox rows (WP-01 owns the outbox): sent and failed rows stay until someone decides.

## Contract change requests

1. **WP-04 (`DoughBoss_Growth_Attribution`):** add a public enumerator, for example `subject_ids( $type, $after_id, $limit )`. The export reads the attribution table directly for the list of enquiry ids (columns `id`, `subject_type`, `subject_id`, then `for_subject()` for the data), which couples it to WP-04's schema.
2. **WP-04:** capture the browser's GA client id (the client part of the `_ga` cookie) under measurement consent, and Meta's `_fbp` under advertising consent, so server events can join the visitor's session and Meta can match better. Until then GA4 events use a per-order client id.
3. **WP-04:** write an order record whenever consent is given, even with no campaign data (today it is written only when attribution is non-empty), so an advertising-only visitor without a click id is still a known consent state. Today this fails closed (nothing sent).
4. **WP-03 (lead double count):** decide the single GA4 source for `generate_lead` (see [CONFIRM]).
5. **WP-01:** none required. (Its `test-core-lifecycle.php` assertions about "exactly two shared tables" are out of date and were already failing.)
