# 2026-10-17 — Current candidate archives and retained runtime evidence

## Scope and state

Built the existing canonical plugin and theme using repository build tools;
no application source changed. Source revision was the clean, already-pushed
`codex/2.51-handoff-20261016` commit
`9597e480340821acbffc91e0ed0335b356b01101`.

These are **local review candidates, not deployed or launch-accepted releases**.
No live site, Square account, payment/order state, credentials, Ads spending,
hosted CI or deployment was changed by this batch.

## Exact packages

Output folder: `C:/Codex/Output/doughboss-candidate-9597e48-20261017/`.

| Artifact | Version | Runtime files | Bytes | SHA-256 |
| --- | --- | --- | --- | --- |
| `doughboss-2.51.0-9597e48-candidate.zip` | 2.51.0 | 238 | 5,680,410 | `ABAFFCB1F1A91E60ED905FE9A71496C0F6FADB95577F6E7411A5E7483C36F59E` |
| `doughboss-final-9597e48-candidate.zip` | 1.8.0 | 27 | 42,111 | `54EE4110432E74DC6F5B7E447117D2799A51D65371E93B113272675658A3F521` |

Using PHP 8.2 at
`C:/Codex/Temp/doughboss-portable-php-20261012-c1d4232b/php82/php.exe`,
with `-n`, that runtime's `ext` as `extension_dir`, and `-d extension=zip`:

```text
scripts/build-zip.php <output-folder>/doughboss-2.51.0-9597e48-candidate.zip
scripts/build-theme-zip.php <output-folder>/doughboss-final-9597e48-candidate.zip
scripts/validate-zip.php <output-folder>/doughboss-2.51.0-9597e48-candidate.zip
scripts/validate-theme-zip.php <output-folder>/doughboss-final-9597e48-candidate.zip
```

All four commands exited 0. Both validators check archive layout, required
metadata/version consistency, complete runtime-file sets and exact bytes
against the current source tree; plugin validator reported **238 files**,
theme validator **27 files**. Builders refused existing output paths.

## Runtime-evidence reuse, with exact boundary

Compared all 238 entries in the accepted local WordPress/database run's
`runtime-r14/runtime-parity.json` against the current source using SHA-256:

- All 238 entries had matched at that historical run.
- **237/238 are still byte-identical** to current runtime source.
- The only changed runtime file is `public/js/doughboss.js`:
  - Tested database snapshot: `7E1953ABAECCBC5B0999D32AAD10AA25A2FCAA6911B6BB15888885E39420EF1D`.
  - Current candidate: `A2AE9ED04C34CE989FA822837B3AF6936225E7D21579C06793E76BE5E16360A1`.
- `git diff --stat 529fd255b3d58c762d9c71eb0622eced31165de0..HEAD`
  over the whitelisted plugin runtime paths independently confirms that
  sole JavaScript change (15 insertions, 10 deletions).
- The runtime result file still hashes to
  `B59DB6339F64CB5053832DE4FAF3214A7A8ADBBDEEBE6AC2556BA2D9C0B9B0D6`.
  It records passed/source-gate/hash stability, HTTP/mail guards, graceful
  database stop and no remaining listener. The accepted 3,437/3,437 assertion
  evidence is retained in the pickup handoff, not rerun here.
- The changed JavaScript has its separate 57/57 frontend-check and browser
  loading evidence in `2026-10-17-MENU-PHOTO-LAZY-LOADING.md`.

Thus the unchanged backend contract evidence can be reused, but this is not
a claim that the new whole-plugin archive was installed for runtime-r14,
nor a new full database test or whole-site browser acceptance.

## Archive secret scan

Gitleaks scanned both ZIP contents with `dir`, `--max-archive-depth 1`,
`--redact`, and `--exit-code 1`. It returned **one finding / exit 1**, not
an unqualified clean scan. Redacted report is local-only:
`archive-secret-scan.json` in the output folder.

The sole finding is `generic-api-key` at
`doughboss/public/js/doughboss-square.js:660`. Direct inspection identifies
`SESSION_STORAGE_KEY`, a fixed 27-character DoughBoss-prefixed browser
storage namespace. Its consumers call `sessionStorage.getItem`, `setItem`
and `removeItem`; it is not a provider credential. That source line is
unchanged from the database-tested commit. Disposition: **reviewed false
positive**. No scanner suppression, source edit or credential was added.
Do not copy stored session contents or credentials into the handoff.

## Remaining release gates

### Fresh anonymous live readback

At `2026-10-17T04:40:25Z`, an anonymous HTTPS GET of
`https://doughboss.com.au/wp-json/doughboss/v1/config` returned
`ordering_open=false`, `payments_enabled=false`, `payment_gateway=square`,
`currency_code=AUD`, `gst_inclusive=true`. This confirms the public controls
at observation time, not item-level tax classification or provider readiness.

The homepage returned HTTP 200 at `04:40:37Z` and referenced
`doughboss.js?ver=2.49.0-menu-6cfded01496d` and
`doughboss-square.js?ver=2.49.0`. Cache/version labels alone are not core
version evidence. Direct HTTP 200 `application/javascript` readback of the
two public asset bodies at `04:40:46Z` showed:

| Public asset | Served bytes | Served SHA-256 | Current candidate SHA-256 |
| --- | --- | --- | --- |
| `public/js/doughboss.js` | 160,942 | `6CFDED01496D708D21F41F5228C9E8E25151B764853181B8A187684F80E420F5` | `A2AE9ED04C34CE989FA822837B3AF6936225E7D21579C06793E76BE5E16360A1` |
| `public/js/doughboss-square.js` | 34,635 | `63415DFA7A1AF5BD80AC62E103CD30CF4387884D95A0A6EED27738F7F07E316B` | `8699D6B7CC9E4EAC428C599A8E0FD8B2BD5348233D0AD39676D82B17953BF736` |

Neither served body matches this candidate. These observations establish a
real deployment mismatch for the public-asset subset, not the exact installed
PHP source/version or full live/core reconciliation. No session credentials,
provider write or live-site mutation was used. Do not describe the current
2.51 candidate as fully installed.

Live/core byte reconciliation, complete isolated private-backup restore and
WordPress boot, fresh production backup/rollback evidence, release review
and required Revesby provider/financial/device acceptance remain separate.
Do not install these candidates merely because ZIP validation passes.

Route: one bounded Sol 6.1 executor-preparation lane runs separately;
root owns this deterministic package check. No extra model call or duplicate
backend test run was used for archive parity. Live allowance before delegation:
37% used / 63% remaining; per-task/model cost and savings are unknown.
