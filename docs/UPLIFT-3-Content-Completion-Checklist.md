# UPLIFT-3 — Demo Site Content Completion Checklist

Scope: `demo/*.html` on the platform branch. Every placeholder, bracket, and unfinished
content item, with proposed final copy where a sensible default exists, or
**OWNER-INPUT-REQUIRED** where only the owner can answer. Legal items additionally flagged
**NEEDS-A-LAWYER** (NSW solicitor review before publication/signing — the pages say so themselves).

Canonical contact email: **hello@doughboss.com.au** (already consistent across all pages — keep it).

---

## 1. index.html (marketing/ordering home)

| Line / section | Current placeholder | Proposed final copy or owner question |
|---|---|---|
| 215 — "Call our catering line" card | `tel:+61400000000` displaying `0400 000 000` | **OWNER-INPUT-REQUIRED**: real catering phone number. Single `tel:` link + one visible number to swap — see §3. Sensible default until then: point the card at Revesby `(02) 9774 2286`. |
| 294 — student voucher form | `placeholder="you@student.edu.au"` on a `disabled` email input | Intentional UI placeholder (follow-gate form is dormant). No copy change; confirm the owner wants the form live or hidden at go-live. |
| 234–236 — shop cards | Hours: Revesby "7 days 6:30am–2:30pm", Bankstown "Mon–Fri 7am–2pm", Roselands "Daily 8am–3pm" | **OWNER-INPUT-REQUIRED**: confirm all three hour sets are current (they differ per shop, which may be correct, but they gate refund/pickup expectations). |
| 236 — Roselands phone | `0466 353 133` (mobile, vs landlines for the other two shops) | **OWNER-INPUT-REQUIRED**: confirm this mobile is the intended public number for Roselands, not a leftover personal number. |

## 2. franchise.html (licensing & wholesale pitch)

| Line / section | Current placeholder | Proposed final copy or owner question |
|---|---|---|
| 185 | `[Initial licence fee]` + `[royalty __% of gross sales]` + `[marketing levy __%]` | **OWNER-INPUT-REQUIRED**: commercial terms. Interim safe copy: "Fees confirmed in your licence agreement — enquire for the current schedule." |
| 202 | `[Trade price list]`, min order `[$___]`, payment `[__] days` | **OWNER-INPUT-REQUIRED**: wholesale price list, minimum order value, payment terms. Interim: "Trade pricing on request." |
| 254–259 — fee summary table | `[$ ___ ]` licence fee, `[ __% ]` royalty, `[ __% ]` marketing levy, `[ __ years ]` term, `[exclusive / non-exclusive territory]`, `[$ ___ ]` wholesale minimum, `[ __ days ]` payment terms | **OWNER-INPUT-REQUIRED** for every cell — these are the same numbers as licensing.html Schedule terms; fill both from one decision. Do not go live with a visible bracketed fee table: either fill it or replace with "Enquire for terms". |
| 287 — enquiry form | `placeholder="Timing, sites, current business…"` | Intentional form hint — keep as-is. |
| 296/313/324 | `hello@doughboss.com.au` mailto flow | Correct canonical email — no change. |

## 3. Catering phone number swap (blocks go-live)

- Only occurrence in the whole demo: **index.html line 215**, one `tel:+61400000000` href plus the visible text `0400 000 000 →`.
- Swap is a single-line edit once the owner supplies the number: update the href (`tel:+61XXXXXXXXX`, E.164) **and** the display text together.
- **OWNER-INPUT-REQUIRED**: the real catering line number (or a decision to reuse the Revesby landline).

## 4. privacy.html — **NEEDS-A-LAWYER** (marked DRAFT pending solicitor sign-off, line 64)

| Line | Current placeholder | Proposed copy / owner question |
|---|---|---|
| 73 | `[DOUGH BOSS LEGAL ENTITY NAME ___]` (ABN `[___ ___ ___ ___]`) | **OWNER-INPUT-REQUIRED**: registered entity name + ABN (same value feeds terms.html §72 and licensing.html). |
| 75 | `[BANKSTOWN ADDRESS ___]`, `[ROSELANDS ADDRESS ___]`, `[REVESBY ADDRESS ___]` | Sensible default: reuse the public addresses already on index.html (462 Chapel Rd Bankstown 2200; Shop MM03 Roselands Dr 2196; 12/25 Selems Parade Revesby 2212). Owner to confirm these are also the correct legal service addresses. |
| 78 | `[PRIVACY CONTACT EMAIL — e.g. privacy@doughboss.com.au ___]`, `[POSTAL ADDRESS ___]`, `[PRIVACY CONTACT PHONE ___]` | Proposed: create/alias **privacy@doughboss.com.au** → hello@; postal = Revesby shop address; phone = catering/main line. **OWNER-INPUT-REQUIRED** to confirm the alias exists. |
| 91 | `[hello@doughboss.com.au ___]` (catering enquiry recipient) | Proposed: strip brackets, keep **hello@doughboss.com.au**. |
| 121 | Email/hosting provider disclosure + guidance note to name actual providers (Mailchimp etc.) | **OWNER-INPUT-REQUIRED + NEEDS-A-LAWYER**: list the real email host, WordPress host/CDN, marketing platform. Data-flow accuracy is a legal exposure. |
| 127 | Overseas-disclosure table: POSPal `[CONFIRM DATA LOCATION ___]` | **OWNER-INPUT-REQUIRED**: confirm where POSPal stores data (Stripe/Formspree rows already filled). |
| 136 | `[PRIVACY CONTACT EMAIL ___]` (unsubscribe route) | Same answer as line 78 — fill from one decision. |
| 141 | `[DESCRIBE — e.g. access controls, staff confidentiality, secure hosting, HTTPS/TLS… ___]` security measures | Proposed default: adopt the example text verbatim (it matches the actual stack); solicitor to confirm. |
| 144 | `[RETENTION PERIOD — e.g. 5–7 years ___]` + guidance to set per-data-type retention | Proposed default: "7 years" for tax/accounting; **NEEDS-A-LAWYER** for per-type schedule. |
| 151 | Complaint response time `[e.g. 30 days ___]` | Proposed default: "30 days". |

## 5. terms.html — **NEEDS-A-LAWYER** (DRAFT banner, line 64)

| Line | Current placeholder | Proposed copy / owner question |
|---|---|---|
| 72 | `[DOUGH BOSS LEGAL ENTITY NAME ___]` (ABN `[___ ___ ___ ___]`) | Same entity/ABN as privacy.html §73 — single owner answer. |
| 117 | Catering cancellation: `[e.g. more than 7 days ___]` notice → `[refund of deposit, less reasonable costs / OR deposit forfeited — CONFIRM ___]`; within `[e.g. 48 hours ___]` → non-refundable | **OWNER-INPUT-REQUIRED + NEEDS-A-LAWYER**: pick the cancellation windows and deposit policy; must match the catering deposit flow in the plugin. Proposed default: 7 days / 48 hours with "refund less reasonable costs". |
| 146 | `[hello@doughboss.com.au ___]` (orders & catering), `[GENERAL / SUPPORT EMAIL ___]`, `[CONTACT PHONE ___]`, three shop `[ADDRESS ___]` slots | Proposed: hello@doughboss.com.au for both email roles (drop the second slot unless the owner wants a separate support inbox); phone = main line; addresses = the three public addresses from index.html. |

## 6. licensing.html — **NEEDS-A-LAWYER** (two full draft agreements; closing note at 348 mandates NSW solicitor review)

These are deal templates, not web copy. Most fields are per-deal blanks that should *stay* blank
until a specific licensee/customer signs, but the commercial defaults must be decided once:

| Line(s) | Current placeholder | Owner question |
|---|---|---|
| 72–73, 86, 95, 216–221 | Licensor/Licensee legal names, ABNs, addresses, dates, premises, signature blocks | Per-deal blanks — leave, but fill **Licensor** legal name/ABN once known (same as §4/§5 entity). |
| 111, 115–118 | Term `[TERM ___]` years; initial fee `[INITIAL FEE $___]`; royalty `[ROYALTY __%]` per `[week/month]`, payable by `[___th]` day; marketing levy `[__% / $___]` | **OWNER-INPUT-REQUIRED**: the standard commercial package. Must match franchise.html §254–259. |
| 192 | Mediation: `[__]` business days, `[Australian Disputes Centre / Resolution Institute ___]` | Proposed default: 10 business days, Resolution Institute — **NEEDS-A-LAWYER** to confirm. |
| 241–242, 263, 265, 317, 336–339 | Wholesale agreement: parties, order channel `[email / portal / phone ___]`, minimum order `[$___ / __ units]`, small-order surcharge `[$___]`, term/notice | **OWNER-INPUT-REQUIRED**: standard wholesale terms; order channel default: "the Dough Boss ordering portal, or email". |
| 348 | Closing DRAFT warning | Keep until solicitor sign-off, then delete guidance notes as instructed. |

## 7. Other copy inconsistencies spotted

- **Emails**: fully consistent — every live mailto is hello@doughboss.com.au. Only gaps are the bracketed legal-page slots (§4/§5) and the not-yet-existing privacy@ alias.
- **Roselands phone format**: mobile `0466 353 133` vs landline format for the other shops (index.html:236) — confirm intended (§1).
- **Ordering descriptor**: only Revesby's card says "online pickup ordering"; the "Order online" card (index.html ~239) also says "pickup at our Revesby shop". Consistent, but confirm Bankstown/Roselands are deliberately excluded from online ordering copy.
- **Franchise fee table vs licensing agreement**: two places state the same fees (franchise.html 254–259, licensing.html 111–118) — fill from one decision or they will drift.
- **demo.css 595–602**: "branded photo placeholders … (Higgsfield shot list)" — real menu-item photography is still outstanding (content, not code).
- **staff.html / menu-order.js placeholders**: all remaining `placeholder=` hits are genuine form input hints (Staff ID, passcode, demo card 4242…) — intentional, no action.

## 8. Priority order

**Blocks go-live (public-facing, visibly broken or legally unsafe):**
1. Catering phone swap on index.html:215 (§3) — a dead 0400 000 000 number on the homepage.
2. Legal entity name + ABN in privacy.html and terms.html (§4, §5) — pages are legally void without them.
3. privacy.html contact block (line 78) and bracketed email at line 91 — the policy must name a working contact.
4. franchise.html visible bracketed fee table (§2) — fill or replace with "enquire for terms" copy.
5. Solicitor sign-off on privacy.html + terms.html; remove DRAFT banners only after review (**NEEDS-A-LAWYER**).

**Should follow soon after:**
6. terms.html catering cancellation windows (must match plugin deposit behaviour).
7. privacy.html provider disclosures, POSPal data location, retention periods.
8. Hours + Roselands phone confirmation on index.html shop cards.

**Can trail (not customer-blocking):**
9. licensing.html commercial defaults + solicitor review (needed before the first deal, not before site launch).
10. Real menu photography to replace branded placeholder tiles.
11. Decision on the dormant student-voucher form (live vs hidden).
