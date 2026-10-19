# Acquisition and retention priority — 16 October 2026

## Scope contract

**Outcome:** strengthen the existing WordPress journey from relevant organic/social discovery to a qualified corporate, office or event catering enquiry, then a safe repeat-booking path. Preserve fast phone browsing, truthful food/service information and consent boundaries. The owner prioritised this work over further decorative changes.

**Non-goals:** no ranking guarantee, advertising spend, customer campaigns/messages, invented reviews or ingredient/dietary claims, payment activation, account/permission changes, new marketing database, new production dependency, or bypass of the existing core/restore release gates. Square remains the intended membership/gift-card/loyalty authority; do not activate the dormant standalone loyalty shortcode as a shortcut. The broader launch goal remains active.

**Expected files:** existing SEO class and nearest SEO tests; existing catering page and nearest theme/frontend tests; an existing enquiry-response seam only if the audit proves a bounded defect; this handoff. Do not expand public APIs or storage without separately assessing the contract.

**Proof:** precise metadata/robots/schema and plugin-coexistence checks; useful server-rendered catering content and working enquiry anchors; mobile/keyboard layout; synthetic positive/negative enquiry tests where changed; full local source/package gate; exact frozen-candidate Astra review. Source, pushed branch, deployed code and provider acceptance are separate states.

Base: `49742f9438465c716b26d179a5d9321702025d0b`, branch `codex/2.51-handoff-20261016`, clean at start. Account-wide supported usage read: 43% used / 57% remaining, reset Unix 1792649620. Do not attribute this usage to an email or task.

## Current public evidence

Anonymous HTTP reads on 16 October 2026 returned 200 for homepage, catering, menu, Members, robots and native WordPress sitemap. This is response-body evidence, not a rendered speed or ranking result.

- Homepage and catering already emit descriptions, Open Graph/Twitter metadata and one JSON-LD block. Do not install another metadata owner blindly.
- Catering's current headline/description are generic mini-manoush/pies copy. Corporate, business and event use cases need useful, specific visible content, not keyword repetition or doorway pages.
- `/menu/` deliberately canonicalises to `/order/` in the existing theme because the two templates share content. Preserve that decision unless evidence justifies changing the duplicate-URL policy.
- Public Members currently has no custom description/social metadata or JSON-LD; it must remain honest about membership not yet being available.
- `robots.txt` advertises the native sitemap. The sitemap index includes menu-item/package/category post types; audit whether their destinations are useful before changing indexing.
- Search Console, Business Profile, Meta account ownership/configuration, organic ranking, conversion baselines and real customer retention are **not yet verified**. A successful HTTP response does not prove any of them.

## Delivery priorities

1. **Useful catering landing page:** corporate meetings, team lunches, professional events and celebrations; real product range; planning information; current notice-period policy; concise enquiry/rebooking routes. Availability, timing, collection/delivery arrangements and dietary requests remain confirmed by the team. No unsupported coverage, certification or response-time promises.
2. **Technical discovery:** one metadata owner, intentional canonical/indexing rules, clean titles/share cards, accurate structured data, sitemap/readability checks and private/transaction-state exclusion. Google AI discovery follows the same core search fundamentals; do not invent special AI markup or an `llms.txt` requirement.
3. **Conversion and return journey:** existing validated catering backend, understandable success/error states, lead-response reliability, repeat-enquiry guidance, no duplicate submissions or automatic marketing enrolment. Use customer-requested transactional messages only through existing approved paths.
4. **Measurement and channel setup:** establish baselines for qualified enquiries, enquiry completion and repeat bookings using approved aggregate/native reports. Prepare Search Console/Business Profile and Meta setup/readback requirements; do not enable advertising or analytics merely because a public identifier is configured. Optional device remembering is not advertising consent.
5. **Release:** integrate and test one coherent local batch, obtain bounded read-only Astra disposition, then use the applicable compatible production package, backup/rollback and live readback gates. Do not bulk-upload the 2.51 review candidate over the last-verified 2.49 installation.

## Reference and rights receipt

- Owner-selected Rea: <https://github.com/morluto/rea>. Repository README fetched read-only from `main`; README blob SHA `89a53ff75546ede74869ea85faee0b769cb48ea2`. Rea describes an inspection toolkit, not a WordPress SEO/animation replacement. No Rea runtime tool is registered in this session. Use repository-informed static/source/browser inspection; do not claim Rea runtime execution. No setup script, daemon, debugger bridge or dependency was installed.
- Rea's own MIT licence does not license third-party websites/assets inspected through it. No competitor code, copy, reviews or imagery has been imported. Any future compatible code adaptation must retain its exact licence/provenance.
- Official Google guidance: <https://developers.google.com/search/docs/appearance/ai-features> and <https://developers.google.com/search/docs/appearance/structured-data/local-business>. Useful crawlable content, links, page experience and truthful structured data matter; neither special AI schema nor a promised search position is established by this work. Google Rich Results/Search Console acceptance remains separate from local JSON parsing.
- Owner-approved menu reference: <https://www.ubereats.com/au/store/dough-boss-revesby/1pTldQavTxSJtAUj9nUn7g>. Use item-level descriptions only where the exact product match is verified. Preserve approved website prices; do not copy platform ratings or infer allergens/certification from item names. Six earlier description questions are not resolved merely by opening the store listing.

## Additional owner decisions received during this batch

- Revesby's two KDS screens are **not installed**. Physical kitchen delivery cannot be accepted yet.
- For Square pickup tickets, retain the website's promised pickup time by sending that exact time as scheduled pickup, while the customer may still see ASAP. This records the approved direction only; no fulfillment payload, provider write, device pairing or payment activation happened in this acquisition batch. Historical protocol compatibility and the existing KDS implementation/tests remain required.

## Implementation status

### Implemented locally

- Corporate/event catering landing copy, office/business/celebration use cases, configured notice policy and safe repeat-enquiry guidance. One existing enquiry shortcode; no new marketing database, tracker or production dependency.
- Catering and Members metadata; canonical WebPage/catering Service graph linked to the existing organisation. No fabricated ratings, offers, dietary promises or service coverage. Script-safe JSON encoding and hostile closing-script regression. Preserve dedicated SEO-plugin ownership and the existing menu/order canonical policy.
- Hydration now replaces only one `.dbc-app-flow` child. The configured contact cards, how-to section, FAQ identity and expanded state survive successful and failed package reads. Hide the initial loading message only after the form has rendered.
- Trimmed required contacts, accessible invalid-email feedback and first-invalid focus; invalid submissions make no POST. Existing pending-submit/recovery contracts preserved. Form and package introductions no longer imply a deposit automatically secures a booking.
- Enquiry receipt and intercepted transactional acknowledgements emphasise saved reference, pending staff confirmation, indicative prices and no payment taken by enquiry submission. Validated Reply-To headers reject CR/LF. No real message sent. The stored shop ID remains visible in customer acknowledgement location text; friendliness polish is deferred, not a data-integrity defect.
- Static preview encoder now honours production JSON flags. No provider calls, payment contract, schema, KDS implementation or membership activation changed.

### Final local verification — 16 October 2026

`scripts/test-release.ps1` on portable PHP 8.2, final log `C:/Codex/Output/doughboss-acquisition-20261016-gate-r3.log`:

- 136 PHP syntax files; **2,928 assertions passed, zero failed**.
- 24 JavaScript syntax files; **208 tests passed, zero failed**, across the existing five Node suites.
- Plugin archive 238 files and theme archive 27 files: layout and exact current-source byte validators passed.
- PHP 7.4 `php -n tests/run.php`: **2,858 passed, zero failed**. The extension-free runtime omits ZIP assertions; do not call this full PHP 7.4 ZIP compatibility proof.
- `git -c core.whitespace=cr-at-eol diff --check` passed. The plain whitespace check flags added CRLF lines as trailing whitespace in the existing mixed-EOL catering script; no logical test failure is concealed.

Final runtime artifacts (review only, **not deployment approval**):

- `doughboss-acquisition-review-r3.zip`: SHA-256 `A7B197DF7D6894AA2BED53900445045F141DAF971DF3CF53EE893E8311F7C1B4`.
- `doughboss-theme-acquisition-review-r3.zip`: SHA-256 `54EE4110432E74DC6F5B7E447117D2799A51D65371E93B113272675658A3F521`.

Artifacts are in `C:/Codex/Output/doughboss-acquisition-20261016/`. Earlier review archives remain untouched and are superseded by r3. An earlier gate stopped at the intentional archive-overwrite guard after its source tests passed; r3 uses fresh paths and completed.

Read-only synthetic browser preview `preview-r4` validated 9 pages/61 files with exact source hashes and local resource boundaries. IAB layout checks at 320, 390, 1440 and 1920px found no horizontal overflow. At 320px the email control uses 16px text. At 390px the settled enquiry anchor sits below the sticky header. Invalid-email UI displayed its error, marked the field and focused it with **zero POST requests**. Two contact cards, all six FAQs and the open FAQ state survived a package change. Final monitored preview loads had no failed network events or runtime exceptions. Hero images loaded on desktop. Screenshots saved beside the archives. These are synthetic preview results, not a live enquiry, live mail-delivery test, CrUX/Core Web Vitals or real customer conversion evidence.

### Google Ads boundary

The owner approved the shared account ending 1456 **with isolated DoughBoss setup**. See `HANDOFF/2026-10-16-GOOGLE-ADS-PROPOSAL.md` for the non-executing campaign/goal proposal and exact outstanding gates. No Ads resource changed; other businesses' campaigns and account-default goals remain untouched. Exact budget, currency, current estimates, domain/conversion ownership and consent acceptance remain unresolved. Do not infer spending approval from account selection or claim API validate-only ran.

### Release state / next gate

This is an **assurance-ready incomplete candidate**, not a Sol-complete production release. The mandatory real WordPress/database and isolated backup-restore boot gates remain unfinished/red. Last-verified installed core was 2.49.0; this local review candidate is 2.51.0. Do not bulk-upload it to publish these presentation changes. Keep ordering, payments, split checkout and membership activation paused, and preserve independent hero/franchising snippets 598/601.

The owner requires one bounded, read-only Astra review of the frozen acquisition/retention diff and evidence. Record the actual verdict and disposition after it returns; a score cannot waive the remaining operational gates. Source commit/push, installation, configured Ads, enabled spend, verified conversions and profitable retention are separate claims.

Completion entry: local source/package and synthetic browser checks finished; provider writes, customer messages, ad spend and production publication in this batch: **none**. The broader launch goal remains active.

## Astra disposition — 16 October 2026

Fresh read-only `gpt-6-astra` high review of the frozen runtime/test patch `9861B771F889299394E8E6987D81D29E34BC7E3487EA6B798539D3B5A0F0185F`, r3 ZIPs and frozen handoffs returned **ship for retaining/separately authorised pushing of this release-blocked local candidate only**. Scoped reviewer assessment **9/10**, meeting the owner's local quality threshold; no P0–P2 finding. This is not a live-service, SEO-ranking, conversion, financial or launch-readiness score.

Astra independently reran 166 relevant Node tests and all 2,928 PHP 8.2 assertions; both archive validators passed exact current-tree bytes, and the CRLF-aware diff check passed. It inspected desktop/phone screenshots and the full release log. The 320px screenshot does not itself show the invalid-email control/error; validation is evidenced by executable tests and the lead's monitored browser action with zero POSTs, not that screenshot alone.

Root disposition: accept the frozen local implementation and retain all release blocks above. The accurate but unnecessary customer-facing saved shop ID is a disclosed, deferred P3 polish item. No code changed after the review freeze. Adding this verdict record does not expand its reviewed scope. Source can be retained/pushed as a candidate, never bulk-deployed on the strength of this review.
