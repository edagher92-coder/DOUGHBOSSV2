# Square dashboard preview — 30 September 2026

Owner instruction supersedes the earlier clock deployment requirement for this
work: staff attendance will be handled through Square. This dashboard includes
a Square team link and no clock controls. Existing attendance records and
legacy code remain preserved; this batch does not disable their existing routes.

The owner also disclosed an unannounced future location. Only a private
"Planned location" card appears in the protected dashboard. It has no public
name/address, Square ID, revenue or checkout eligibility. Revesby, Bankstown
and Roselands remain the three selectable demo shops under the confirmed
single merchant plan. None is assigned a fabricated Square location ID.

## Scope and contract

The existing WordPress Operations Dashboard now starts with a Square section.
It shows connection status by default. "Preview demo data" explicitly loads
synthetic sales, refunds, order lines and a sales chart. A location filter
selects only demo records; "Connection status" returns to the unconnected view.
Real local DoughBoss reports remain clearly labelled below that section.

`GET /wp-json/doughboss/v1/admin/square-dashboard` uses the established
management permission and WordPress REST authentication. Parameters:
`mode=status|demo` and `location=all|revesby|bankstown|roselands`.
Invalid selections return 400; users without management authority receive 403.
The callback also rechecks authority and returns private/no-store responses.

The service never makes Square requests, writes data or exposes credentials.
Configured credentials are labelled unverified, not connected. Demo records
are generated in memory, use integer cents, exclude unpaid requests from sales,
and reconcile line, location and chart totals. The unannounced location cannot
be selected. The frontend rejects malformed and wrong-location responses and
never substitutes demo figures after an API error.

This is the frontend/backend dashboard preparation requested while the owner
has no API key. A real Square data adapter, merchant/location mapping, signed
event recovery, approval/payment flow and POS/kitchen acceptance remain pending
as described in OPUS-SQUARE-INTEGRATION-20260926.md.

## Validation

- `scripts/test-release.ps1` with PHP 8.2.33 and ZIP extension, final archive
  names: exit 0; 97 PHP syntax files, 499 PHP assertions, 17 JavaScript syntax
  files and 42 Node tests passed.
- PHP 7.4.33 `-n tests/run.php`: exit 0; 477 assertions passed. ZIP-dependent
  tests remain unavailable on that runtime; archive validation used PHP 8.2.
- Existing disposable WordPress/MySQL gate: exit 0, 450 assertions passed.
  It includes anonymous/staff denial, manager status/demo access, no-store,
  location rejection and unavailable live-mode rejection. Existing payment and
  preorder races passed with one synthetic provider POST/fulfillment each.
- Chrome local preview: status -> demo -> Bankstown -> all locations worked;
  390-by-844 viewport had no horizontal overflow; no browser warnings/errors.
  The preview uses the actual dashboard section markup, service and UI assets.
  Its local fixture does not simulate WordPress authentication: route authority
  was exercised independently by the actual WordPress integration tests.
- The first repeat packaging attempt correctly refused to overwrite an older
  archive. Fresh final filenames passed the complete release script. No tests
  were weakened and no old artifacts were deleted.

After the frozen review, the existing test harness was corrected to exercise
the actual status-return button, and a success-after-failure retry regression
was added. Credential copy now accurately covers partially entered settings.
Focused Node suite: 43/43 passed; JavaScript syntax and diff checks passed.
The PHP/runtime contract was unchanged by these refinements.

Final plugin archive: `dist/doughboss-square-dashboard-reviewed.zip`, source-matched
2.44.0 candidate, 165 files. Theme archive is unchanged source, 1.6.2.
Ignored local preview router under dist/ is excluded from production packaging.
Preview is loopback-only at http://127.0.0.1:8089/ on this computer.

## Ownership and acceptance

Lead owns service/route/test integration. Terra implemented only the admin
section and its two UI assets; lead inspected and integrated them. The owner
directed this narrowed batch after seeing 92% used / 8% remaining. That explicit
development instruction covers this dashboard preparation, not provider calls,
GitHub publication or deployment. The earlier handoff document remains untouched.

Astra reviewed frozen commit `7ede3c57ada6c142cd25eb55d54179825af60991`:
ship this protected local preview, 8.7/10, no blockers. Root corrected both
nonblocking findings above; a bounded review of that final delta is pending.
The score is not real Square readiness or deployment approval. No live code,
settings, ordering, payments or Square provider state changed in this batch.
