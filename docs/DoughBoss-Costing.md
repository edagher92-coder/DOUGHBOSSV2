# DoughBoss — Costing & Valuation Report

**Prepared for:** Dough Boss / Snow Boss hospitality group (Revesby · Bankstown · Roselands)
**Subject:** Replacement-cost and retail valuation of the custom DoughBoss ordering, voucher, POS-integration and real-time kitchen platform
**Currency:** All figures in Australian Dollars (AUD), ex-GST unless stated
**Date:** 25 June 2026 · **Market basis:** 2026 Australian agency / freelance rates

---

## Executive summary

DoughBoss is a fully bespoke, **commission-free** WordPress ordering platform — not a theme, not an off-the-shelf plugin, and not a SaaS rental. It is roughly **12,000 lines of hand-written PHP across 25 classes** plus a vanilla-JS storefront, a real-time kitchen board, a single-use voucher engine, a POS (POSPal/银豹) till bridge, and a multi-channel notification/printing layer. Owning the code outright means **zero per-order commission and zero monthly licence fees** — the entire economic case below.

| Question | Headline figure (AUD, ex-GST) |
|---|---|
| **1. The plugin alone** — cost to commission this custom code from an Australian developer/agency | **$95,000 – $165,000** (typical **~$128,000**) |
| **2. The whole system** — plugin + website + branding + integrations + content + PM, retail | **$135,000 – $235,000** (typical **~$180,000**) |
| **Ongoing running cost** to operate DoughBoss | **~$95 – $180 / month** (≈ $1,150 – $2,150 / year) |
| **3-year saving vs a 30% commission marketplace** (at modest volume) | **$250,000 – $500,000+** |

> **The one-line takeaway:** a system of this scope would retail at roughly **$180k** to build from an Australian agency, and because it is commission-free, it pays for itself many times over within the first 1–2 years against marketplace or SaaS alternatives.

---

## 1. What is actually being valued

DoughBoss is a single self-contained WordPress plugin (text domain `doughboss`, REST namespace `doughboss/v1`) that bundles eight functional modules, all custom-built:

| Module | What it does | Evidence of scope |
|---|---|---|
| **Storefront** | Menu CPT + custom pizza/manousheh builder, guest cookie-token cart, pickup/delivery checkout, order tracking, multi-shop order routing | `class-doughboss-post-types`, `-cart`, `-order`, `-locations`, `-shortcodes`, `doughboss.js` (~690 LOC) |
| **Payments** | Stripe PaymentIntents, server-verified, replay-blocked, server-side totals | `class-doughboss-stripe` |
| **Voucher engine** | High-entropy typo-resistant codes (check digit), atomic single-use redeem, daily-capped shared-pool student campaigns, claim widget, scannable QR, in-cart apply, discount through checkout | `class-doughboss-voucher` (660 LOC) + `-coupon-code` (250 LOC) |
| **KDS + real-time** | Live Order Board kitchen display with **Mercure SSE** (instant push) + polling fallback | `class-doughboss-mercure`, `doughboss-orderboard.js` |
| **POS integration** | POSPal (银豹) bridge: voucher → in-store member coupon at the till (`addCouponcode` API), revoke on redemption, now per-store credentials + grant-to-all routing across 3 stores | `class-doughboss-pospal` (490 LOC) + `-pospal-sync` (226 LOC) |
| **Notifications & printing** | ntfy staff push, ClickSend SMS, printer-pull tickets (Star CloudPRNT / Epson Server Direct Print) | `class-doughboss-printer` (760 LOC), `-sms`, `-ntfy` |
| **Console & staff tools** | wp-admin "Voucher Scan" dashboard (camera QR) + a standalone web "Console" (Scan / Vouchers / Order Board over REST, Application-Password auth, hostable on GitHub Pages) | admin app + `doughboss-voucher-scan.js` |
| **Catering** | Package CPT, enquiry/lead pipeline, server-side quote engine | `class-doughboss-catering` (500 LOC) + `-catering-package` |

The whole thing follows WordPress Coding Standards, is **dependency-free** (no Composer/npm/bundler), is security-hardened (nonces, capability checks, prepared SQL, server-recomputed pricing), and is fully internationalised. The 2,676-line REST controller alone is larger than many complete commercial plugins.

---

## 2. Rate assumptions (2026 Australian market)

All costing below uses these blended 2026 rates. "Effective build rate" assumes a realistic mix of senior architecture, mid-level implementation, and junior/QA support.

| Resource | Freelancer / contractor (AUD/hr) | Agency / consultancy (AUD/hr) |
|---|---|---|
| Junior developer | $55 – $85 | $90 – $140 |
| Mid-level developer | $90 – $130 | $140 – $200 |
| Senior / specialist developer | $130 – $200 | $200 – $320 |
| **Blended effective build rate (used below)** | **$110 / hr** | **$185 / hr** |
| Project management / BA | $90 – $140 | $160 – $240 |
| UI/UX design | $90 – $150 | $150 – $250 |

**Notes:** Sydney agency rates sit at the higher end of the national range. A skilled solo WordPress freelancer in Australia typically bills $100–$140/hr; a digital agency bills $185–$250/hr blended once overhead, PM and warranty are loaded in. Figures are ex-GST; add 10% GST for a gross invoice. The module table in §3 is priced at the **agency blended $185/hr** for the "typical" and "high" columns, and at the **freelancer $110/hr** for the "low" column, which is the single biggest driver of the range.

---

## 3. Question 1 — Cost to build the plugin alone

This is the build cost for the **custom code only** — the ordering + voucher + POSPal + real-time + notifications WordPress plugin — excluding the marketing website, branding and content (those are in §4).

Estimated effort by module, with the spread reflecting freelancer-vs-agency rates and discovery/risk:

| Module | Est. hours | Low (freelancer @ ~$110) | Typical (agency @ ~$185) | High (agency + risk) |
|---|---:|---:|---:|---:|
| Storefront (menu CPT, builder, cart, checkout, routing, tracking) | 180 | $19,800 | $33,300 | $42,000 |
| Stripe payments (PaymentIntents, server verification, replay-block) | 55 | $6,050 | $10,200 | $13,500 |
| Voucher / coupon engine (codes, check digit, atomic redeem, capped pools, QR, in-cart) | 150 | $16,500 | $27,800 | $36,000 |
| KDS + real-time (Live Order Board, Mercure SSE + polling) | 95 | $10,450 | $17,600 | $23,000 |
| POSPal POS integration (till coupon mirror, revoke, 3-store routing) | 110 | $12,100 | $20,400 | $27,000 |
| Notifications & printing (ntfy, ClickSend SMS, CloudPRNT/Epson) | 85 | $9,350 | $15,700 | $20,000 |
| Staff Console + Voucher-Scan dashboard (REST, App-Password auth, QR camera) | 75 | $8,250 | $13,900 | $18,000 |
| Catering (package CPT, lead pipeline, quote engine) | 60 | $6,600 | $11,100 | $14,500 |
| Cross-cutting: REST controller, settings, migrations, security hardening, i18n, WPCS | 120 | $13,200 | $22,200 | $29,000 |
| QA, accessibility, browser/device testing, bug-fix passes | 70 | $7,700 | $13,000 | $17,000 |
| **Subtotal (build)** | **~1,000** | **$110,000** | **$185,200** | **$240,000** |

Because real projects don't bill a flat 1,000 hours and the freelancer/agency split dominates, the **defensible plugin-alone range** is:

| | Plugin alone (AUD, ex-GST) |
|---|---|
| **Low** (capable solo freelancer, lean discovery, reused patterns) | **$95,000** |
| **Typical** (small specialist agency, proper PM + warranty) | **~$128,000** |
| **High** (full agency, formal discovery, hardening, documentation) | **$165,000** |

> Sanity check against scope: at the agency blended rate of $185/hr, ~$128k implies ≈ 690 billable hours of senior-led work — entirely consistent with ~12,000 lines of hand-written, security-reviewed PHP plus 2,000+ lines of JS, integrating five external systems (Stripe, Mercure, POSPal, ClickSend, cloud printers).

---

## 4. Question 2 — Cost of the whole system, retail

The full project an agency would quote includes the plugin **plus** everything around it: the marketing website, branding, integration setup/accounts, content/menu entry, testing and project management.

| Line item | Scope | Retail (AUD, ex-GST) |
|---|---|---:|
| **Custom plugin** (from §3, typical) | The ordering/voucher/POS/real-time/notifications engine | $128,000 |
| **Website build & design** | WordPress theme, responsive storefront pages, builder/cart/checkout UI styling, 3-store pages, performance + SEO basics | $18,000 – $35,000 |
| **Branding & creative** | Dough Boss + Snow Boss brand application, menu/voucher/QR artwork, food photography direction | $6,000 – $15,000 |
| **Integrations setup** | Stripe account + keys, Mercure VPS provisioning, POSPal per-store credentials (×3), ClickSend, ntfy, printer onboarding | $6,000 – $12,000 |
| **Content & menu** | Menu data entry across all categories/3 stores, item photos, descriptions, pricing, voucher campaign config | $4,000 – $9,000 |
| **Testing & UAT** | Cross-browser/device, end-to-end order + payment + voucher + KDS, in-store till + printer validation | $6,000 – $12,000 |
| **Project management** | Discovery, stakeholder coordination, sprint management, training, handover docs | $10,000 – $20,000 |
| **Contingency (~10%)** | Scope risk on hardware/POS integration | included in range |
| **Whole-system retail total** | | **$135,000 – $235,000** |

| | Whole system (AUD, ex-GST) |
|---|---|
| **Low** | **$135,000** |
| **Typical** | **~$180,000** |
| **High** | **$235,000** |

Add 10% GST for the gross figure (typical ≈ **$198,000 inc GST**).

---

## 5. Ongoing running cost of DoughBoss

Owning the code means the only recurring spend is infrastructure and pay-as-you-go messaging — **no commission, no per-seat SaaS licence.**

| Item | Plan / basis | Monthly (AUD) |
|---|---|---:|
| WordPress hosting | Managed AU WP host (handles 3 stores' traffic) | $25 – $60 |
| Mercure SSE VPS | Small cloud VPS (1–2 vCPU) for the real-time hub | $10 – $25 |
| Stripe fees | ~1.75% + $0.30 per domestic card txn — **scales with sales, not a fixed cost** | usage-based |
| ClickSend SMS | Pay-as-you-go, ~$0.08–$0.10 / SMS AU | $10 – $40 (volume-dependent) |
| ntfy push | Self-host or free tier | $0 – $5 |
| POSPal (银豹) API | Free tier for the coupon endpoints used | $0 |
| Domain + SSL | Annualised | ~$3 |
| Printer cloud service | CloudPRNT / Epson SDP — typically no recurring fee | $0 |
| **Total fixed running cost** | | **~$95 – $180 / month** |

Annualised: roughly **$1,150 – $2,150/year** plus Stripe processing on actual card sales. That is the entire ongoing cost of running a commission-free ordering platform across three stores.

---

## 6. 3-year comparison vs off-the-shelf alternatives

The value of owning custom, commission-free code is clearest over time. The scenarios below assume a **modest blended volume of ~$45,000/month in online order value across the 3 stores** (~$540k/year) — deliberately conservative for three Sydney venues.

| Option | Year 1 | Years 2–3 (each) | **3-year total cost** | Who owns it |
|---|---:|---:|---:|---|
| **Commission marketplace** (~30% per order) | ~$162,000 | ~$162,000 | **~$486,000** | Platform owns customer + data |
| **SaaS ordering** (UpMenu / Orderable-style: ~$150–$400/mo + setup, limited POS/voucher) | ~$4,000 | ~$3,600 | **~$11,200** | Vendor — you rent, no POSPal bridge, no atomic vouchers |
| **Voucherify-style coupon SaaS** (added on top, ~$300–$1,000+/mo at campaign scale) | ~$6,000 | ~$6,000 | **~$18,000** | Vendor — coupons only, still no ordering/POS |
| **DoughBoss (own the code)** | $180,000 build + ~$1,650 run | ~$1,650 run | **~$183,300** | **You own it outright** |

**Read-across:**

- **vs commission marketplaces:** Even at a conservative $540k/year online, a 30% marketplace skims **~$162k/year**. DoughBoss's entire 3-year cost (build + run) of **~$183k** is recovered in **~13–14 months** of avoided commission. Over 3 years the net saving is **~$300,000**, and it widens every year because the build is a one-off while commission is forever. At higher volume the saving runs **$500k+**.
- **vs SaaS ordering/coupon rental:** SaaS looks cheap monthly, but you never own it, you can't get the POSPal till bridge or the atomic daily-capped student-voucher pools off the shelf, prices rise, and you're locked in. DoughBoss trades a higher upfront cost for **permanent ownership, no per-order fee, and capabilities SaaS simply doesn't offer.**

> **Break-even:** Against marketplace commission, DoughBoss pays for itself in **roughly 12–14 months**; everything after that is margin retained in the business rather than paid to a platform.

---

## 7. What makes this expensive to replicate

The bespoke, non-trivial engineering — the parts you cannot buy as a plugin and that drive the cost — are:

- **Atomic single-use vouchers.** Codes use a high-entropy, typo-resistant alphabet with a **check digit**, and redemption is **atomic and concurrency-safe** so the same code can never be spent twice even under simultaneous requests. Getting single-use guarantees right under race conditions is genuinely hard engineering, not a checkbox.
- **Daily-capped shared-pool campaigns.** The student-voucher system draws from a **shared pool with a per-day cap** — a distributed-counter problem (don't over-issue, reset daily, stay correct under load) far beyond a static coupon code.
- **The POSPal (银豹) till bridge.** A custom integration that mirrors an online voucher into an **in-store member coupon at the physical POS** via the `addCouponcode` API and **revokes it on redemption** — keeping online and in-store state in sync. Now extended to **three stores with per-store credentials and grant-to-all routing.** This is one-of-a-kind middleware between a WordPress site and a Chinese POS system.
- **Multi-store routing.** Orders, vouchers, prep times and POS credentials are all **per-store aware** across Revesby, Bankstown and Roselands — every module carries location logic.
- **Real-time KDS over Mercure SSE** with a **polling fallback** — instant kitchen updates with graceful degradation, rather than a naive refresh loop.
- **Server-authoritative money & security throughout.** Pricing, discounts and Stripe amounts are **always recomputed server-side and replay-blocked**; nothing trusts the browser. This security rigour adds hours to every module but is what makes the platform safe to take real money.

These are exactly the features an off-the-shelf product does not provide and that a replacement build would have to engineer from scratch — which is why the replacement cost lands where it does.

---

## 8. Summary of figures

| Metric | AUD (ex-GST) |
|---|---|
| **Plugin alone** — low / typical / high | **$95k / $128k / $165k** |
| **Whole system** — low / typical / high | **$135k / $180k / $235k** |
| Whole system, typical, **inc GST** | ~$198,000 |
| Ongoing running cost | ~$95–$180 / month (~$1,150–$2,150 / yr) |
| 3-year saving vs 30% marketplace (conservative volume) | ~$300,000 (up to $500k+ at higher volume) |
| Break-even vs marketplace commission | ~12–14 months |

*Estimates are professional valuations for a project of this documented scope, based on 2026 Australian agency and freelance rates. Actual quotes vary with supplier, discovery depth, hardware on site, and warranty terms. Figures are ex-GST unless noted.*
