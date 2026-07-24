# DoughBoss Remote Session Handoff

Date: 2026-07-23 (Australia/Sydney)

Source Codex thread: `019f608f-d5b3-7401-a1a5-17042893258c`

Remote Codex thread: `019f8c59-0a61-73d2-8379-c1b27d57083d`

Remote host: `ELZYDLAB`

Remote working copy: `C:\Users\edagh\DOUGHBOSSV2-session-20260723-clean`

Repository: `edagher92-coder/DOUGHBOSSV2`

Working branch: `codex/tyro-connect-acceptance`

## Current verified milestone

The original DoughBoss colour system has been restored without reverting newer
ordering, animation, tracking, or integration work.

- Shared demo: charcoal/cream with ember `#e2231a` and orange `#ff6a4d`.
- Franchise: accessible warm gold `#b5571f`.
- Offers view: navy `#071528`, ice `#a9dcff`, amber `#ff9d2e`.
- The late black-and-white override and retired-mono markers are removed.
- Local preview endpoints returned HTTP 200.
- `git diff --check` passed.
- Node demo configuration test passed.
- PHP demo scope test passed: 21 passed, 0 failed.
- Remote Node demo configuration test passed.
- Remote Codex is authenticated and app-server ready.
- Remote Git origin points to `https://github.com/edagher92-coder/DOUGHBOSSV2.git`.
- The full transferred integration worktree has been audited and is being
  published to `origin/codex/tyro-connect-acceptance` as the shared checkpoint.
- Tyro Connect payment-attempt creation is atomically claimed, provider
  references are immutably bound, and the browser checkout key is verified
  against the final server-side cart snapshot.
- All requested menu corrections from the supplied photo are represented in
  the demo catalogue, WordPress seeder, and automated contract test.
- The WordPress 3D manoush hero shortcode and independent asset loading are
  implemented and covered by a contract test.

Primary files:

- `demo/demo.css`
- `demo/franchise.html`
- `docs/DoughBoss-Colour-Restoration-Handoff-2026-07-23.md`

## Working-tree safety

This branch is the shared integration checkpoint. Preserve its history, inspect
the exact diff before any Git action, and use a reviewed pull request for further
integration. It is not production deployment approval.

Do not place credentials, API keys, private SSH material, payment secrets, or
production tokens in documentation, commits, logs, or chat.

## Active roadmap

1. Visually verify the implemented 3D manoush / catering-bites hero in staging.
2. Implement the separate after-hours Revesby preorder-request workflow:
   unconfirmed on submission, no payment taken, morning staff review, and only
   accepted requests converted into normal orders.
3. Verify the completed WordPress shortcode/enqueue and backend/frontend
   contracts in staging.
4. Complete live Tyro acceptance while keeping Stripe available; do not
   activate production payments without credentials, staging verification, and
   explicit release approval.
5. Run MariaDB-backed integration checks and browser acceptance in staging.

## Remote resume instruction

Continue the DoughBoss implementation from this handoff. First verify the remote
repository path, branch, worktree status, and whether the transferred working
copy matches this source. Do not overwrite newer remote work. Keep payment,
security, production, deployment, and irreversible changes behind explicit
verification gates.
