# WP-16 security and error-handling review: hand-off note

The full report is in `web/docs/wp/08-security-review.md` (findings by severity, evidence, fixed versus open). This note lists what changed, what was run, what was not, and the [CONFIRM] gaps.

## Changed (smallest fix, frozen names kept)

| Fix | File | Regression test |
| --- | --- | --- |
| S1: the outbound URL policy refuses obfuscated IPv4 hosts (`127.1`, `0x7f.0.0.1`, `0177.0.0.1`, `0xa9.254.169.254`), treats link-local, carrier-grade NAT, benchmarking, documentation, multicast, unique-local and mapped addresses as non-public, and refuses a host name that resolves to any non-public address before a request is made (`host_not_public`, retryable, nothing sent). New seam: filter `doughboss_growth_http_resolve` (the harness never looks a name up unless the filter supplies a list) | `includes/class-doughboss-growth-http.php` | `tests/test-wp16-security.php` S1 to S3 (98 assertions, negative controls included) |

No other source file was changed. New file: `tests/test-wp16-security.php` (picked up by the `tests/test-*.php` glob; `run.php` is unchanged).

## Run

- `php tests/run.php` (PHP 8.3.6): 5592 passed, 0 failed, 1 skipped (the real-core parity test needs `DBGR_CORE_SRC`), 0 foreign writes. Baseline before any change: 5355 passed. The total also includes tests other packages added while this review ran.
- `php tests/run.php wp16-security`: 98 passed.
- Mutation check, each fix reverted in a scratch copy: last-label rule removed 11 failed; `host_is_public()` call removed 11; carrier-grade NAT and link-local ranges removed 7; IP literals sent back through the old PHP-flag check 3. Every revert was caught.
- `php -l` on `includes/class-doughboss-growth-http.php` and `tests/test-wp16-security.php`: clean.
- `php scripts/php74-guard.php`: 70 files, no violations.
- `node --test tests/*.test.js`: 144 passed.
- `node scripts/es5-check.mjs`: 7 files, no violations.
- Scratch fuzz of 44 URL spellings against `url_allowed()` before and after the fix (not shipped).

## Not run, and why

- A real PHP 7.4 lint or full-suite run (only the syntax guard ran; the earlier review found the 7.4 WASM CLI hangs on the full suite).
- A browser or runtime check (nothing in this review needed a rendered page; the machine was shared).
- MySQL: the harness is SQLite, so the race arguments (outbox claim, waitlist confirm, limiter) are read from the conditional `UPDATE` statements, not exercised with two concurrent connections.
- The real DNS path of `resolve_host()` (`gethostbynamel`, `dns_get_record`): no test performs a lookup by design.
- Core DoughBoss plugin code (out of scope) and a real Tag Manager container.

## [CONFIRM] gaps

- [CONFIRM: is the live site behind a proxy or CDN, and which header carries the real client address? Until answered, the sign-up limit (5 an hour) and the opt-out limit (30 an hour) are shared by every visitor. Test opt-out from two networks after launch. See O1.]
- [CONFIRM: double opt-in and a check of the consent wording version before any marketing send from lead-form consent. See O2.]
- [CONFIRM: a never-rotated secret for keyed email hashes, or acceptance of the plain SHA-256 in the suppression list. See O3.]
- [CONFIRM: which core roles carry `manage_doughboss`, since holders can set the webhook URL, switch features and export the VIP list. See O9.]

## Contract change requests (not applied here)

- WP-05: drop the per-address limiter from the opt-out path or key it on a trusted header (O1); reuse the confirmation token while under a day old (O5); signed timestamp on the webhook (O4); `X-Frame-Options: DENY` in `send_page()` (O7).
- WP-04 and WP-07: verify `dbgr_consent_text_version` against `DoughBoss_Growth_Leads::consent_version()` and add a double opt-in before any marketing send (O2).
- WP-01: on a module `init()` exception, remove the hooks the module added, or document the partial state (O11).
