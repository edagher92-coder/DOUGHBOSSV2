# Live release readback R2 — 18 October 2026

## Scope

Outcome: refresh customer-facing asset, installed-version, navigation and Square-readiness evidence against canonical source `e2e4fd9409ba58201902cc626860a1ad17b0c951`.
Non-goals: no runtime repair, deployment, provider read-control invocation, account change, payment, Ads activation or private restore attempt. Files: this handoff and three minimized JSON evidence files under `HANDOFF/artifacts/`. Proof: actual anonymous HTTP responses and hashes, followed by read-only authenticated Chrome DOM observations and a fresh GPT-6.1 Sol evidence gate.

## Current direct observations

- Anonymous homepage, menu, catering and Members & Events GETs all returned HTTP 200. Response-body elapsed times were 743/658/620/619 ms respectively. These are single request timings, not browser LCP, scroll smoothness, load-test results or performance improvements.
- Anonymous menu endpoint returned 43 items. Config still reports `ordering_open=false`, `payments_enabled=false`, `payment_gateway=square`.
- Authenticated Installed Plugins row directly shows active `doughboss/doughboss.php`, **Version 2.49.0**. The prepared 2.51.0 candidate is not established as installed; the active header contradicts treating that candidate as the active plugin.
- Anonymous bytes for `public/js/doughboss.js`, `public/js/doughboss-square.js` and `public/css/doughboss.css` all differ from the current candidate. Exact SHA-256 pairs are in the parity receipt. This proves sampled delivered-asset mismatch, not a complete installed filesystem reconciliation or the cause of any particular visual problem.
- Three sampled AVIF responses still omit Content-Type; sampled WebP/JPEG responses have expected image MIME types. The prepared MIME fix therefore cannot be considered live from this evidence.
- Authenticated browser menu hydrates to 43 cards, and the initial shop-loading state resolves. At viewport 390px the first three cards are 334px wide with 20px headings; document scroll width is 376px. These are current geometry observations, not proof of the candidate's compact layout, horizontal-card design or scrolling efficiency.
- Members & Events is present. The phone drawer opened, the link navigated to `/members-events/`, and the destination has the expected heading and active-menu label. At 320px its document scroll width is 305px. No physical phone or signed-out browser claim is made.
- Homepage hero `db-opening-film` advanced from time 0 to 3.53 seconds, was unpaused/readyState 4, muted and looping, without a media error. Although its HTML autoplay property is false, actual playback occurred. Do not report a playback failure solely from that attribute; this one observation does not prove loop seam quality, all browser policies or phone autoplay.
- Installed Operations Dashboard reports **test**, **Configured, not yet verified**, Revesby **Mapped, not verified**, and Bankstown/Roselands **Unmapped**. Its readiness labels are installed-version observations, not evidence that later candidate integrations are absent. No read-provider, demo, mapping, save or preparation control was invoked.

## Reproduction and evidence boundaries

The existing bounded `C:/Codex/Output/doughboss-live-validation-20261017/audit-live.cjs` was inspected, then run with `node`; exit 0. It permits same-origin observed image URLs, bounds response bodies and timeouts, and selects only public commerce flags/item count from API output. Fresh results: `artifacts/live-public-readback-20261018-r2.json`.

Asset comparison used anonymous `Invoke-WebRequest` on the three URLs observed in the menu DOM, hashing exact response bytes with SHA-256 and comparing with `Get-FileHash` of the same relative files in this checkout. Fresh results: `artifacts/live-asset-parity-20261018-r2.json`. No credential, cookie, private API response or source configuration was read by that command.

Read-only DOM results are minimized in `artifacts/live-browser-readback-20261018-r2.json`. Temporary 390px and 320px viewport overrides were reset. Browser selector timing issues were resolved by observing the settled DOM, not by interpreting an early missing locator or initial loading state as a product defect. Unloaded below-viewport lazy images are not counted as broken images.

## Next launch actions

1. Preserve the current terminal private R4 attempt and its frozen sources. The separately requested SESSION_CONTROL closed-label extension remains pending owner response; do not alter its wire contract or retry the private operation under the consumed one-attempt approval.
2. Complete the required isolated restore/application boot and fresh production backup/rollback proof, then reconcile the installed whole-tree manifest before the controlled candidate publication. These readbacks do not waive those gates.
3. After approved publication, verify exact delivered bytes, AVIF headers, mobile card layout, real browser performance, hero playback and signed-out customer journeys against the deployed candidate.
4. Complete Revesby merchant/legal seller/environment/location, item-tax/options/availability and explicitly authorized provider lifecycle acceptance. Current configured/mapped dashboard labels are not that evidence. Keep other-shop connections and financial/device gates unchanged.

The full launch objective remains open. No claim of deployment, current merchant ownership, successful payment/refund, loyalty activation, staff permissions or physical KDS receipt follows from this batch.

## Independent gate

Fresh explicit `gpt-6.1-sol` / `high` review returned **SHIP_EVIDENCE_ONLY** after one wording correction: the report no longer asserts menu ordering that was not preserved in the minimized receipt. The reviewer parsed all three JSON files and independently matched all three candidate SHA-256 values to actual source. Prior accepted checks were reused after the wording-only correction; no JSON or runtime changes followed. No residual evidence-gate defect remains.

This accepts the bounded readback evidence and conclusions only, not deployment, whole-installed-tree parity, signed-out/physical-device acceptance, performance improvement or provider/payment readiness. Root JSON parse checks and `git diff --check` passed; application source diff is empty. No application suite was rerun because no application source changed.
