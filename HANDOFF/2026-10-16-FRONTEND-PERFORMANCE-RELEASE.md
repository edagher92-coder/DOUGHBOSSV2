# Frontend presentation performance repair — 16 October 2026

## Published scope

One consolidated presentation batch, based on branch `codex/2.51-handoff-20261016` at `08d4545c9ae04d361b5e9b2366341fe17b3995d0`:

- WPCode snippet **598**: native full-film looping instead of scroll-driven seeking; offscreen/hidden suspension, remembered motion preference, muted optional quiet audio, sparse title/box steam; larger responsive layout/type; opaque sticky header instead of backdrop blur; controlled announcement ribbon. Intermediate desktop navigation uses two rows without splitting link labels. Snippet **601** was not changed.
- New media: `https://doughboss.com.au/wp-content/uploads/doughboss-commercial-v3-web-loop.mp4`.
- New public page **603**, `/members-events/`, sourced from `scripts/hero-commercial/members-events-page.html`. Square membership is explicitly not open; existing order tracking is linked; catering follow-up is contact-based, not an invented tracker. No signup form or customer data was added.
- Installed theme `page-about-us.php`: only hero class, authentic pie image/alt and responsive sizes changed. No other theme/core file replaced.

No Square/provider settings, ordering/payment activation, staff/customer records or core plugin code changed. Anonymous configuration readback remained `ordering_open=false`, `payments_enabled=false`. Local core is 2.51 while public assets advertise 2.49; this presentation release does not resolve core parity or authorise a bulk upload.

## Evidence

- `node --test tests/frontend-upgrade.test.js tests/theme-navigation-layering.test.js tests/storefront-helpers.test.js tests/pickup-storefront-2-51.test.js tests/orderboard-2-51.test.js`: **175 passed, 0 failed, 0 skipped**, exit 0, rerun after final repairs.
- PHP 7.4.33 and 8.2.33 `-n -l` on the snippet and About template: all passed. Snippet rechecked after final navigation repair; About unchanged since its accepted lint checks.
- `git diff --check`: passed.
- Astra read-only gate: initial FIX-FIRST for Members CTA contrast (1.42:1). Repaired explicit white/#b41913 normal and white/#8f110d hover/focus colours, with visible focus outline and existing-test assertions. New frozen candidate received **SHIP**, scoped qualitative **9/10** acceptance. This is not a whole-site performance score.
- Final reviewed SHA-256: snippet `16D0C80F212FC41CBF484EB89D3C39369CAE86A518D61FA8FA273817CF47B190`; Members HTML `DB4AD694ADF26C1D97159477390998C172F21C89C66F03A20A5EAD766B1EBD14`; About `E9FD93858FA70FC60FF37BDFE7722EB9D73AF558BE04FBB5DBBEEFEF6CBBBB7B`; tests `AC04B598DE406E5E4CD8ACE919BAE5D9D9091D2508CE9AC6CF7AD6690CB5CF06` (working-file byte hashes; Git may normalise line endings).
- New video 2,720,427 bytes vs 10,410,982 original (~74% smaller), 720x1280/30fps/10.005s. Full decode passed. Existing quiet audio copied unchanged, not a new sound mix.
- Public video HTTP 200 and SHA-256 `6035ffcef72dcae2c667ced78cd56d8e7823e5841c6bd530be2e0be9cf9ecff4` exactly match the local reviewed file.
- Saved WPCode source normalised readback equals candidate. WordPress confirmed page603 published at `/members-events/`. Public About DOM uses `db-about-hero` and loaded `dough-boss-pie-550.avif`.
- Live video samples advanced 5.16 through 9.16, then 0.095 through 5.08 seconds, continuously playing, muted and loop=true. Preview confirmed offscreen pause. Announcement Next advanced to Members & Events.
- Desktop1440, intermediate1102 and narrow720 layouts checked. Phone390 homepage/Members document width390, no horizontal overflow. About mobile layout checked. Live Members primary buttons computed white on rgb(180,25,19).
- Screenshots and JSON playback evidence: `C:\Codex\Output\doughboss-251-handoff-20261016` (`homepage-live-final-desktop.png`, `homepage-live-final-mobile.png`, `members-live-final-mobile.png`, `about-live-final-desktop.png`, `live-loop-readback.json`). Browser preview style/viewport overrides were removed after testing.

## Rollback and preserved state

Fresh exact copies outside the repository in the evidence folder:

- `wpcode-598-before-frontend-release.php` — restore through existing snippet598 editor; do not disable/remove snippet601.
- `about-template-before-frontend-release.php` — restore only installed theme `page-about-us.php`.

Live About prior bytes SHA `849d115ef06b5d44f39ccd187bf9a46c6a025ba08a38dfaaf4b23590c653b350`; normalised LF SHA `75ba5c737d65cba3260d4168ecfe6514b30ad661eb186ff892c5e5606c646542` equals base HEAD. Apparent drift was CRLF only. Fresh pre-write editor read matched the backup. Older video remains on disk; new media used a new filename with overwrite disabled. Page603 can return to Draft if rolling the new page back, after removing its announcement link. No destructive cleanup performed.

## Still open — do not claim full performance/commerce completion

Menu photo discovery still waits for asynchronous shop/config/menu hydration. One public sample measured order HTML897ms, menu721ms, config622ms; these are not LCP/CLS/mobile benchmarks. Existing local code caches completed config/location values but not in-flight requests. Next narrow performance task should confirm precise served2.49 source parity, deduplicate same-page in-flight requests with failure/retry tests, and verify a browser waterfall. Do not bypass shop/availability authority, cache consequential settings across sessions, globally monkeypatch fetch, eagerly load every photograph or replace live core with unpublished2.51.

Real low-end-device and Safari/iOS behaviour remains unverified. Production film/publication checks do not constitute Square payment, KDS or accounting acceptance.

## Owner's next-project direction

After frontend work, Square integration/validation is **Revesby only**. Bankstown and Roselands account connection is deferred to the weekend. See the dated sequencing update in `2026-10-16-SQUARE-SHOP-ROUTING-PLAN.md`: preserve reusable approved menu/prices/setup/validation evidence, but re-establish each shop's separate merchant/location, ABN/tax, OAuth, staff/device and webhook configuration. Never duplicate credentials or route disconnected shops through Revesby by fallback. Astra is used only when it offers tangible assurance benefit.
