# 08 Security and error-handling review: `doughboss-growth` companion plugin

Independent security and error-handling review of `/home/user/DOUGHBOSSV2/doughboss-growth/` (WP-01 to WP-08 and WP-11, plus the WP-16 integration edits), done on 2026-10-02. The reviewer did not write the code under review. This is a companion to `07-adversarial-review.md` (behavioural review); it does not repeat its findings. Nothing was deployed, no request left the machine, and git was not run.

**Bottom line.** The plugin is built carefully. Every admin handler checks capability and then nonce, every query with a variable goes through `$wpdb->prepare()`, every output is escaped, there is no `innerHTML`, no unserialisation and no direct `wp_remote_*` call outside the HTTP wrapper, and every `catch` block ends in a closed (inert) state. One real defect was found and fixed: the outbound URL policy could be bypassed with obfuscated IPv4 hosts and with DNS names that point at internal addresses, which reached the one owner-supplied destination (the notification webhook). Eleven lower-severity items are reported and left open; three of them need an owner decision. None lets a visitor reach an internal address, read another person's data, or change any state without a capability or a secret token.

Severity scale: **High** (exploitable by an unauthenticated visitor, or breaks a legal promise), **Medium** (needs a privileged user, or a deployment assumption that may not hold), **Low** (hardening, bounded impact), **Info** (note for the owner).

## Findings by severity

| # | Severity | Area | Status | Finding |
| --- | --- | --- | --- | --- |
| S1 | Medium | Outbound HTTP, SSRF | FIXED | The URL policy let obfuscated IPv4 hosts through (`127.1`, `0x7f.0.0.1`, `0177.0.0.1`, `0xa9.254.169.254`), and it never checked what a name resolves to; WordPress's own safe-URL check does not cover `169.254.0.0/16` (cloud metadata) or `100.64.0.0/10` |
| O1 | Medium | Rate limiting, opt-out | OPEN, [CONFIRM] | The limiter keys on `REMOTE_ADDR` only. If a proxy or CDN fronts the site, every visitor shares one bucket: 30 link actions an hour blocks opt-out for everyone (Spam Act), 5 sign-ups an hour blocks the list |
| O2 | Medium | Consent evidence | OPEN, owner | Lead-form marketing consent is recorded from fields the browser supplies: the wording version is never checked against the shown wording, and there is no double opt-in |
| O3 | Low | Privacy | OPEN, owner | The suppression list and the limiter store an unsalted SHA-256 of the email address, which can be reversed by dictionary |
| O4 | Low | Webhook | OPEN | The webhook signature has no timestamp, so a captured delivery can be replayed |
| O5 | Low | Sign-up abuse | OPEN | A re-submission for a pending address rotates its confirmation token and sends another email (grief and bounded email bombing) |
| O6 | Low | Availability | OPEN, by design | The 300 a day sign-up breaker can be used up from about 60 IPv6 networks |
| O7 | Low | Hardening | OPEN | The confirm and opt-out pages can be framed (no `frame-ancestors` or `X-Frame-Options`) |
| O8 | Low | Outbound HTTP | OPEN | DNS rebinding between the S1 lookup and the connection cannot be closed without pinning the address |
| O9 | Info | Authorisation | OPEN, owner | Anyone holding core's `manage_doughboss` can change the webhook URL and flags and export the VIP list |
| O10 | Info | Consent | OPEN | A queued conversion is not re-checked against a consent withdrawn after it was queued |
| O11 | Info | Error handling | OPEN | A module that throws inside `init()` is marked inactive, but hooks it added before the exception stay registered |
| O12 | Info | Caching | OPEN | The lead form prints a `wp_rest` nonce into the page; a full-page cache that stores a logged-in view would publish it |

No High finding was found.

## Fixed finding (evidence, fix, regression)

### S1 (Medium): the outbound URL policy could be bypassed

- **What the code did.** `DoughBoss_Growth_Http::url_allowed()` refused IP literals that PHP's `filter_var()` recognises as private or reserved, and accepted any other dotted name. `request()` then relied on `wp_safe_remote_request()` with `reject_unsafe_urls` as the second layer.
- **Reproduction** (the fuzz run before the fix, `url_allowed()` result):
  - `https://127.1/x`, `https://0x7f.0.0.1/x`, `https://0177.0.0.1/x`, `https://0300.0250.0.1/x` (192.168.0.1) and `https://0xa9.254.169.254/x` all returned **allowed**. `filter_var()` does not read these as addresses, but the operating system's resolver (`inet_aton`) does.
  - `https://100.64.0.1/` (carrier-grade NAT), `https://198.18.0.1/` (benchmarking) and `https://192.0.0.8/` also returned allowed.
  - A public-looking name such as `169.254.169.254.nip.io` was allowed and never resolved by the plugin.
- **Why the second layer is not enough.** WordPress core's `wp_http_validate_url()` only rejects `127/8`, `10/8`, `0/8`, `172.16/12` and `192.168/16`. It does not reject `169.254.0.0/16` (the cloud metadata address) or `100.64.0.0/10`. So a hex-spelled or DNS-pointed metadata address reached the transport.
- **Who can reach it.** The only owner-supplied destination is `notify_webhook_url` (Growth settings; GA4, Meta and Square hosts are constants). Saving it needs core's `manage_doughboss` (or `manage_options`) and a nonce. The response body is not returned to the user, and the request is a signed POST, so the impact is a blind internal request. Hence Medium, not High.
- **Fix** (`includes/class-doughboss-growth-http.php`):
  - A host name must end in a label that starts with a letter. That refuses every shorthand, hex and octal spelling in one rule (real top-level domains, including punycode `xn--`, all start with a letter).
  - New `is_public_ip()`: refuses loopback, private, link-local, carrier-grade NAT, `192.0.0.0/24`, documentation ranges, benchmarking, multicast and reserved IPv4; unspecified, unique-local, link-local, multicast and documentation IPv6; and IPv4-mapped IPv6 whose IPv4 part is not public. IP literals now go through it.
  - New `host_is_public()` called from `request()` before anything is sent: a name that resolves (A and AAAA) to any non-public address is refused with `error = host_not_public`, nothing sent. A name that resolves to nothing is left to the transport, which cannot connect either. The result is marked retryable (nothing was sent, a bad DNS answer may be transient, and the outbox caps retries at five).
  - New seam `resolve_host()` with the filter `doughboss_growth_http_resolve`. Inside the test harness nothing is looked up unless the filter supplies a list, so no test performs a DNS query.
- **Regression.** `tests/test-wp16-security.php` (98 assertions), S1 to S3, each with negative controls (ordinary names, `8.8.8.8`, `1.1.1.1`, punycode hosts, a public-resolving name all still work). Mutation check, each fix reverted in a scratch copy:
  - last-label rule removed: 11 assertions fail;
  - `host_is_public()` call removed from `request()`: 11 fail;
  - CGNAT and link-local ranges removed: 7 fail;
  - IP literals sent back through the old PHP-flag check: 3 fail.
- **Residual.** DNS rebinding (O8) and a resolver that answers differently at connection time.

## Open findings

### O1 (Medium, [CONFIRM]): the limiter trusts only `REMOTE_ADDR`

- **Evidence.** `DoughBoss_Growth_Rate_Limit::client_ip()` reads `$_SERVER['REMOTE_ADDR']` and nothing else (forwarded-for headers are deliberately not trusted; the `doughboss_growth_client_ip` filter exists for a known proxy). Limits: sign-up 5 an hour per address, link actions (confirm and opt-out) 30 an hour per address (`LIMIT_ACTION`).
- **Impact.** If the live host sits behind a reverse proxy or CDN and nobody supplies the filter, every visitor has the proxy's address. After 30 link actions in an hour nobody can opt out (HTTP 429), which breaks the Spam Act promise that opt-out always works. After 5 sign-ups an hour nobody can join.
- **Why not changed.** Whether a proxy exists on the Crazy Domains host is not known from the code. The right fix depends on the answer: supply the filter with the proxy's trusted header, or drop the limiter from the opt-out path (the opt-out token is 128 bits, so guessing it is not feasible and the limit protects nothing there).
- **[CONFIRM: is the live site served through a proxy or CDN, and which header carries the real client address?]** Until answered, test opt-out from two different networks after launch.

### O2 (Medium, owner decision): lead-form marketing consent is client-asserted

- **Evidence.** `DoughBoss_Growth_Attribution::stash_enquiry_params()` records `consent_marketing = 1` when the request carries `dbgr_consent_marketing=1` and any `dbgr_consent_text_version` that matches `[A-Za-z0-9._-]{1,20}`. It does not compare the version with the wording the server shows (`DoughBoss_Growth_Leads::consent_version()`), and nothing confirms the address belongs to the person who ticked the box.
- **Impact.** The stored record can name a wording version that never existed, and a third party can "consent" for any address. Nothing in the companion sends marketing from this record (the admin gaps say so), so no message is sent today. The record cannot stand as consent evidence for a later send.
- **Recommendation.** Before any marketing send: confirm by email (double opt-in, as the waitlist does) and compare the version with the wording in force or a known list of past versions. Not changed here because a stale cached page would then record "no consent", which is a product decision.

### O3 (Low, owner decision): unsalted email hashes

- **Evidence.** `DoughBoss_Growth_Waitlist::email_hash()` is `hash( 'sha256', $email )`. It keys the suppression table and the limiter bucket `wl:email:<hash>`. The eraser message calls it "a one-way hash".
- **Impact.** A hash of a guessable email address is reversible by dictionary, so it is still personal data. A keyed hash (HMAC with a site secret) would fix that, but if the secret is rotated every suppression is lost and opted-out people could be mailed again.
- **Recommendation.** Keep the plain hash until the owner decides on a dedicated, never-rotated secret constant (for example `DOUGHBOSS_GROWTH_HASH_KEY`) and a migration. [CONFIRM]

### O4 (Low): webhook signature has no timestamp

`deliver_webhook()` signs the exact body with HMAC-SHA256 (`X-DoughBoss-Growth-Signature`). There is no timestamp or nonce in the signed data, so a captured delivery can be replayed to the receiver. The payload carries the row id and confirmation time, so a receiver can de-duplicate on `id`. Recommendation: add a signed timestamp header and ask the receiver to reject old ones.

### O5 (Low): resend rotates the confirmation token

Submitting a pending address again calls `resend_confirmation()`, which replaces `confirm_token_hash` and emails a new link; the earlier link stops working. Someone can therefore invalidate a person's confirmation link, and cause up to three emails a day to an address. Bounded by 3 a day per address and 5 an hour per network. Recommendation: reuse the existing token while it is under a day old.

### O6 (Low, by design): the global breaker can be exhausted

`limit_visitor()` takes the per-network slot first, then the 300 a day global slot, before validation. Someone using about 60 IPv6 /64 networks can use up the day's allowance and stop genuine sign-ups until midnight UTC. Accepted by the work breakdown as a circuit breaker. No personal data is exposed.

### O7 (Low): confirm and opt-out pages can be framed

`send_page()` sends `Cache-Control`, `Referrer-Policy` and `X-Robots-Tag`, but not `X-Frame-Options` or `Content-Security-Policy: frame-ancestors`. A hostile page could overlay the Confirm or Unsubscribe button. The page needs a valid secret token in the URL, so the attacker must already hold it. Recommendation: add `X-Frame-Options: DENY`. Not changed because headers cannot be asserted by the CLI harness.

### O8 (Low): DNS rebinding

The S1 lookup and the transport's own lookup are separate. A name that answers with a public address for the first and an internal one for the second defeats the check. Closing it needs address pinning (cURL `resolve`), which `wp_safe_remote_request` does not offer. Mitigations in place: the destination list is tiny and owner-configured, redirects are off, the port is fixed to 443, and the body is a signed notification with no personal data.

### O9 (Info): who can change the webhook

`DoughBoss_Growth_Admin::cap()` is core's `manage_doughboss` (falling back to `manage_options`), by design. Every holder of it can set `notify_webhook_url`, switch features, and export the VIP list (names, emails, mobiles). If shop staff hold that capability, they hold these powers too. [CONFIRM: which core roles carry `manage_doughboss`.]

### O10 (Info): consent withdrawn after a conversion is queued

The outbox row carries the consent flags captured at order or enquiry time. `Ga4::deliver()` and `Meta::deliver()` re-validate the payload and refuse hashed identifiers if `send_hashed_identifiers` was switched off since (verified), but a visitor who withdraws consent in the next minutes does not stop an already queued row. The window is the backoff schedule (up to about two hours). Acceptable if the privacy policy says so.

### O11 (Info): partly initialised module after an exception

`DoughBoss_Growth::init_modules()` catches `Throwable` from a module's `init()`, removes the module from `$active` and logs the class name. Hooks the module added before it threw stay registered. The code paths behind those hooks re-check their flags, so nothing opens, but the health endpoint then reports the module inactive while part of it runs. Low practical risk.

### O12 (Info): nonce in the page

`DoughBoss_Growth_Leads::browser_config()` prints `wp_create_nonce( 'wp_rest' )` for the form. For visitors this is the standard anonymous nonce. If a page cache stores a view rendered for a logged-in user, that user's REST nonce is published. Already listed as a gap in the admin tab ("exclude the catering pages from full-page caching").

## What was checked and found sound

### Entry points

| Entry point | Capability | Nonce or credential | Notes |
| --- | --- | --- | --- |
| `admin_post_doughboss_growth_save_settings` | `user_can_manage()` first | `check_admin_referer` | all fields sanitised in `Settings::sanitize()`; dependency rules; redirect `wp_safe_redirect` to `admin_url` |
| `admin_post` VIP list CSV | yes | yes | status whitelisted; cells neutralised; `nocache_headers` |
| `admin_post` offline conversions CSV | yes | yes | then the flag; dates strictly parsed; click ids pattern-checked; cells neutralised |
| `admin_post` landing "create pages" | yes | yes | creates drafts only; shortcode-only content |
| `admin_post` coming-soon ribbon | yes | yes | one boolean |
| `admin_post` recon run, mapping, params, export (4) | `require_manager()` first | `check_admin_referer` each | the staff "suggest mappings" view is a GET guarded by `wp_verify_nonce` and the manager check in `render_page()` |
| REST `GET /health` | manager | cookie auth needs the REST nonce | booleans only, no secret |
| REST `GET /form-token`, `POST /waitlist`, `/waitlist/confirm`, `/waitlist/unsubscribe` | public by design | form token (HMAC, `hash_equals`), single-use confirm token (160 bits, only the SHA-256 stored), derived opt-out token (128-bit HMAC) | no ambient authority, so no CSRF; honeypot, time window, limiter, neutral answers |
| Email-link pages (`template_redirect`) | public | secret token in the link | a GET only shows a button; only a POST changes anything; `Referrer-Policy: no-referrer`, `noindex`, no cache |
| Shortcodes (5 plus empty stubs) | n/a | n/a | attributes validated or ignored; output escaped |
| Cron (outbox, retention purge, recon) | n/a | n/a | each re-checks its flag; outbox claim is a conditional `UPDATE` |

### Checks and their results

- **Capability and nonce.** All 9 `admin_post_` handlers check capability, then nonce, before reading input. No handler is registered for `admin_post_nopriv_`. Tab renderers run only inside `render_page()`, which checks capability.
- **SQL.** Every statement with a variable goes through `$wpdb->prepare()`. The ones without a variable are constant counts. Table names are built from `$wpdb->prefix` plus a constant, or validated by `uninstall_plan()` (`DROP TABLE` only after every name passes). `IN (...)` lists use generated placeholders. Dynamic `INSERT` tuples are prepared one by one.
- **Escaping.** `esc_html`, `esc_attr`, `esc_url`, `esc_textarea` on every dynamic value in admin, shortcode and link pages. JSON in `<script>` uses `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`; the one `echo` of core's form output is lint-checked first. Mail headers are fixed strings; the recipient is validated and cannot hold CR, LF or separators.
- **Open redirects.** The only redirects are `wp_safe_redirect( add_query_arg( ..., admin_url( 'admin.php' ) ) )` with fixed or whitelisted values.
- **Unsafe deserialisation.** None: no `unserialize`, no `maybe_unserialize`. Cookies and stored JSON go through `json_decode` (depth-limited in the ledger) and field-by-field sanitisers.
- **Token comparison.** Form token, confirm token, opt-out token and the consent version all use `hash_equals`. No other secret or token is compared.
- **Races.** Outbox claim is `UPDATE ... WHERE id = %d AND status = 'pending' AND next_attempt_at <= now` and succeeds for exactly one caller; recording the outcome is conditional on `in_flight`; a stuck `in_flight` row is quarantined, never re-sent. Waitlist confirm is `UPDATE ... WHERE status = 'pending' AND confirm_token_hash = %s`, so a double click has one winner. Sign-up is `INSERT IGNORE` on a unique email hash. Opt-out records the suppression before the row changes, and a confirm racing with it ends opted out. The limiter increments atomically (`hits < limit` in the `UPDATE`) and resets a stale window with a conditional `UPDATE`.
- **Fail closed.** Limiter storage error gives a refusal; no secure randomness gives no sign-up; a ledger or consent file error gives no output; an unreadable stored subject gives "no consent" and nothing queued; a manager's own browser is never attributed to an enquiry; settings are re-sanitised on every read.
- **Catch blocks.** Every `catch` was read. Conversion, attribution and landing hooks log a class name and do nothing (no event, no record, no page block). Outbox handler exceptions become a retry, never a send. Recon `run()` turns an exception into a FAILED run and deletes its rows. The `format_price` fallback in landing is cosmetic (a dollar format). The only partial state is O11.
- **Secrets.** Read only through `Settings::secret()` (environment, then constant) at the delivery call sites (GA4, Meta, webhook, Square); never printed, stored or logged. The Square token and the Meta token travel in a header and a JSON body that are never logged; GA4's secret is in the URL query, which `redact_url()` drops from every log line.
- **Outbound HTTP.** No `wp_remote_*`, `curl_*` or `file_get_contents( 'http...' )` outside the wrapper. https and port 443 only, no credentials in the URL, redirects off, 10 s ceiling, 64 KiB body cap, header-injection guard, TLS verified. (Plus S1.)
- **CSV injection.** All four CSV builders neutralise cells that start with `=`, `+`, `-`, `@`, tab or CR.
- **JavaScript.** No `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`, `eval`, `new Function`, string `setTimeout`, `postMessage` or assignment of server text to `href` or `src`; dynamic text uses `textContent`. Query-string and cookie parsing reads only whitelisted keys through `hasOwnProperty`; no merge or deep-assign helper exists, so prototype pollution has no path (a `__proto__` key in the query string only sets a string, which JavaScript ignores). The ES5 gate passes, with its planted-arrow negative control in the WP-01 tests.

## What was run

- `php tests/run.php` (PHP 8.3.6): **5592 passed, 0 failed, 1 skipped, 0 foreign writes** (baseline before any change: 5355 passed; the count also includes tests other packages added during the review).
- `php tests/run.php wp16-security`: 98 passed.
- Mutation check of each fix (above): 11, 11, 7 and 3 failures.
- `php -l` on the two PHP files written: clean (PHP 8.3).
- `php scripts/php74-guard.php`: 70 files, no violations.
- `node --test tests/*.test.js`: 144 passed.
- `node scripts/es5-check.mjs`: 7 files, no violations.
- A throw-away fuzz of 44 URL spellings against `url_allowed()` before and after the fix (scratch file, not shipped).

## Not run, and why

- A real PHP 7.4 lint or test run (the guard checks syntax rules only; the earlier review found the WASM CLI hung on the full suite).
- A browser or runtime check: nothing in this review needed a rendered page, and the machine was shared.
- MySQL (the harness uses SQLite); the conditional `UPDATE` race arguments above are read from the SQL, not exercised with two concurrent connections.
- Any live request: the resolver is injected and the transport is the harness fake. The real DNS path in `resolve_host()` (`gethostbynamel`, `dns_get_record`) is not exercised by any test; it was read and a manual lookup of a public name returned public addresses.
- A review of the core DoughBoss plugin (out of scope), and of the real Tag Manager container.

## [CONFIRM] gaps raised

- [CONFIRM: is the live site behind a proxy or CDN, and which header carries the client address? (O1)]
- [CONFIRM: double opt-in and wording-version check before any marketing send from lead-form consent. (O2)]
- [CONFIRM: a never-rotated secret for keyed email hashes, or acceptance of the plain hash. (O3)]
- [CONFIRM: which core roles carry `manage_doughboss`, given what it allows here. (O9)]

## Contract change requests (not applied here)

- WP-05 (`waitlist`): drop the per-address limiter from the opt-out path (or key it on a trusted header) (O1); reuse the confirmation token while it is under a day old (O5); optional signed timestamp header on the webhook (O4); `X-Frame-Options: DENY` in `send_page()` (O7).
- WP-04 (`attribution`) and WP-07 (`leads`): verify `dbgr_consent_text_version` against `DoughBoss_Growth_Leads::consent_version()` and add a double opt-in before any marketing send (O2).
- WP-01 (`outbox`, `class-doughboss-growth.php`): on a module `init()` exception, remove the hooks it added, or document the partial state (O11).
