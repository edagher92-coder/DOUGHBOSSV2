# Costing: catering box, liner, seal, merchandise

> **Prices as published on 3 October 2026 (all pages accessed 2026-10-03). Re-quote before ordering.**
> Every figure below was read from a supplier's own page or its live price calculator on that date. Nothing is
> estimated except the USD to AUD conversions, which are flagged. Where no price is published the item is marked
> **[QUOTE]** and the RFQ in section 9 lists what to ask for. Technical specs (board grade, food contact, dieline)
> sit in PDS-RESEARCH.md. This section covers cost only.

## 0. Basis and conventions

| Item | Rule used |
|---|---|
| GST | Prices are shown as the supplier states them (inc or ex GST). The cost model runs **ex GST**, on the assumption that Dough Boss is GST-registered and claims input tax credits **[CONFIRM with the accountant]**. Inc to ex = inc / 1.1. |
| GST not stated | ATpack, Top Shelf Concepts and Aussie Chef do not state GST treatment on the pages read. The model treats those figures **as ex GST**. That is the conservative (higher-cost) reading: if they turn out to include GST, real cost is 1/11 lower. |
| USD figures | noissue showed USD (the session's egress geolocated to the US). AUD = USD / 0.6933, the RBA AUD/USD rate for 2 Oct 2026, 4 pm AEST (rba.gov.au, accessed 2026-10-03). **This is an estimate**: it excludes international freight, import GST, any duty or customs fees, and card FX margin. noissue's Australian storefront price may also differ. Treat every noissue AUD figure as indicative only. |
| Tiers | Where a supplier has no tier at exactly the quantity needed, the model buys the next tier up (or adds two published tiers together, which is stated) and divides the cash outlay by the boxes actually needed. This overstates the unit cost and does not understate it. |
| Rounding | Unit costs are total / quantity, shown to 2 or 3 decimals. No figure is rounded to a "nice" number. |
| Evidence | Raw page captures and calculator responses are saved under `scratchpad/pack/cost/` (for example `cost/pw/es_batch.txt` holds the Easy Signs calculator JSON). |

### Fit test against the working size
Working internal size from the brief: **385 x 290 x 50 mm** (4 x 3 bakes at about 9 cm) **[CONFIRM by fit test]**.
A candidate fits if its internal length is 385 mm or more and its width is 290 mm or more. If it is deeper than 50 mm it still
fits, but the box wastes board and the bakes can slide around.

| Candidate size (supplier label) | L x W x D mm | Fits 385 x 290? | Note |
|---|---|---|---|
| Easy Signs XL Satchel | 410 x 330 x 70 | Yes | Listed on the site's "Mailer Box Internal Dimensions Chart" **[CONFIRM internal]** |
| Easy Signs A3 | 440 x 310 x 70 | Yes | Same price as XL at every tier quoted |
| Easy Signs B-flute | 405 x 350 x 95 | Yes | Deeper and stiffer |
| noissue 12 x 15 x 3 in | 381 x 305 x 76 | Marginal | Length 381 is 4 mm under the 385 working length. Four 90 mm bakes (360 mm) still fit **[CONFIRM by fit test]** |
| Paperlust full-colour Large | 425 x 295 x 110 | Yes | 110 deep: more than twice the depth needed |
| Paperlust kraft Large | 400 x 270 x 90 | **No** | 270 width leaves no clearance for 3 x 90 mm rows. Paperlust says "custom sizes available on request" [QUOTE] |
| ATpack BetaCater Ex Large catering box | 450 x 310 x 80 | Yes | Kraft tray with a window lid (plain stock) |
| Packaging Place (Colpac) Large Platter | 482 x 331 x 82 | Yes | Base plus a windowed sleeve (plain stock) |
| eBPak A3 Premium Tuck Full Black | 430 x 305 x 140 | Yes | Black stock mailer, 140 deep |

---

## 1. Custom printed corrugated mailer boxes

### 1a. Easy Signs (AU, digital print) - live calculator readback
Source: https://www.easysigns.com.au/products/details/custom-printed-mailer-boxes, accessed 2026-10-03. The figures come from the
calculator's own price response. Item price is **ex GST**, which was checked against the on-page total inc GST: $1,389.77 x 1.1 =
$1,528.75 as displayed. Delivery is a fixed $17.27 ex ($19 inc) per order to one location. Minimum order value is $99 inc GST.
The calculator also prices a single sample at $30.00 ex GST, credited back if the full order is over $500 + GST.
Printing is HP Latex digital, water-based inks. The page says: "We do not print using white ink so when ordering the Kraft finish,
white elements will be left blank and will have the natural Kraft material look."
Lead time (calculator `lead_time` field, working days after artwork approval): 1 to 5. A 30% deposit applies over $3,300 inc GST.

| Spec | 50 | 250 | 500 | 1,000 | 2,500 |
|---|---|---|---|---|---|
| E-flute kraft, 410x330x70, single-sided print: total ex GST (unit) | $394.00 ($7.88) | $1,372.50 ($5.49) | $2,425.00 ($4.85) | $4,620.00 ($4.62) | $11,550.00 ($4.62) |
| E-flute kraft, 440x310x70 (A3), single-sided | $394.00 ($7.88) | $1,372.50 ($5.49) | $2,425.00 ($4.85) | $4,620.00 ($4.62) | $11,550.00 ($4.62) |
| E-flute white clay coat, 410x330x70, single-sided | $405.82 ($8.12) | $1,413.68 ($5.65) | $2,497.75 ($5.00) | $4,758.60 ($4.76) | $11,896.50 ($4.76) |
| B-flute kraft, 405x350x95, single-sided | $412.00 ($8.24) | $1,435.00 ($5.74) | $2,535.00 ($5.07) | $4,830.00 ($4.83) | $12,075.00 ($4.83) |
| Lead time (working days) | 1 | 2 | 3 | 4 | 5 |

Additional readings: the 100-unit price for E-flute kraft 410x330x70, single-sided, was $664.00 ex ($6.64).
The calculator did not offer black board. Stocks were white clay coat or kraft only.

### 1b. Paperlust Print Shop (AU, Melbourne)
Prices are **inc GST**. Delivery is $10 standard or $15 express per order, or free pickup in Oakleigh South VIC.

| Product | Spec | 250 | 500 | 1,000 | 2,500 | MOQ / lead | URL |
|---|---|---|---|---|---|---|---|
| Custom Kraft Mailer Boxes, Large | 400x270x90 internal, B-flute natural brown kraft, 1 spot colour flexo, outside only, RETF | n/a | $4,945 ($9.89) | $7,140 ($7.14) | $15,100 ($6.04) | MOQ 500; "approx. 2 to 3 weeks after proof approval" | https://printshop.paperlust.co/products/custom-kraft-mailer-boxes |
| Same, 2 colours | "2 Colour (Upgrade)" option exists. The only 2-colour price in the page data was Medium 300x230x115 at 500 = $5,495 ($10.99). Large 2-colour: [QUOTE] | | | | | | same |
| Custom Full-Colour Mailer Boxes, Large | 425x295x110 internal, B-flute, white liner, CMYK (digital small batch, offset at volume) | $7,972.50 ($31.89) | $9,345 ($18.69) | $13,190 ($13.19) | not listed | MOQ 10; 3 to 7 working days (small batch), 2 to 3 weeks (volume). Inside print +50% | https://printshop.paperlust.co/products/custom-full-colour-mailer-boxes |

The kraft Large size fails the width fit (see the fit table), so it is listed for reference only.

### 1c. noissue (USD shown; AUD conversion is an ESTIMATE)
Product: Box Custom, Mailer, E Flute 32 ECT, 305 x 381 x 76 mm (12 x 15 x 3 in). Source: https://noissue.co/shop/boxes/custom-boxes/, accessed
2026-10-03. Prices are from the page's variant data in **USD**, GST treatment not stated. The only lead time on the page is the FAQ's figure for a
**US sample** ("approximately 11 business days"). The production-run lead time to Australia is **[CONFIRM]**.

| Type / print | 250 | 500 | 1,000 | AUD estimate @0.6933 (250 / 500 / 1,000) |
|---|---|---|---|---|
| Kraft, outside only | US$1,745.70 (US$6.98) | US$2,751.38 (US$5.50) | US$5,041.03 (US$5.04) | A$2,517.96 / A$3,968.52 / A$7,271.06 (A$10.07 / A$7.94 / A$7.27 each) |
| White, outside only | US$1,815.28 | US$2,852.58 | US$5,224.45 | A$2,618.31 / A$4,114.49 / A$7,535.63 |
| Kraft, inside and outside | US$2,523.68 | US$3,953.13 | US$7,121.95 | A$3,640.09 / A$5,701.90 / A$10,272.54 |

There is no 2,500 tier: the largest published tier is 1,000. A single sample costs US$51.75 (about A$74.64). The page offers no black board
(stocks are Kraft, White and Premium White).

### 1d. Suppliers named in the brief with no usable published price
| Supplier | Result on 2026-10-03 |
|---|---|
| Packhelp (AU) | packhelp.com.au failed to connect (proxy CONNECT 502). packhelp.com/au/ returned 404. No AU storefront could be verified. **[QUOTE]** |
| EcoEnclose | Not checked (US supplier). **[QUOTE]** if wanted |
| Gift Box Co, Lux Packaging | Not checked. **[QUOTE]** |
| Local corrugated converter (Sydney) for the brief's actual spec | **[QUOTE]**. No published calculator offers black-liner board with 2 spot colours (opaque white + ember red) at 385 x 290 x 50. Use the RFQ in section 9a |

**Design implication (cost-relevant):** the cheapest published route (Easy Signs digital on kraft) cannot print white. A black flood on kraft
with the wordmark knocked out would show **kraft brown, not white**, in the letters. The brief's spec (black outer, opaque white + ember red) needs a
converter quote: either black-liner board with 2 spot colours, or kraft board with 3 spot colours (black flood + white + red).

---

## 2. Plain stock boxes (pilot before custom print)

| Supplier | Product | Size mm | Pack / price as published | Unit | GST | Delivery | URL |
|---|---|---|---|---|---|---|---|
| ATpack | BetaCater Catering Box with Window Lid, Ex Large (corrugated kraft paperboard, brown) | 450 x 310 x 80 | 10 base + 10 lids $32.50; 50 + 50 $96.50 | $3.25 (10); $1.93 (50) | Not stated | Site banner: "FREE shipping to Sydney Metro (>$300)" | https://www.atpack.com.au/betacater-catering-box-ex-large-450-x-310-x-80-mm |
| ATpack | Pack'n'Carry Catering Box, Large | 400 x 250 x 85 | 100 pcs $110.50 | $1.105 | Not stated | as above | https://www.atpack.com.au/packncarry-catering-box-l-100pcs-400x250x85mm |
| Packaging Place (Colpac) | Large Platter Box, Base & Sleeve (FSC board, compostable window) | 482 x 331 x 82 | 25 for $70.73 ex GST ("Now", sale); 5 for $16.97 ex GST | $2.83 (25) | Ex GST | not read | https://www.packagingplace.com.au/boxes/platter-boxes/ |
| eBPak | A3 Premium Tuck Full Black Mailing Box B360 | 430 x 305 x 140 | 25 for $84.50 | $3.38 | "Tax included" | not read | https://ebpackaging.com.au/products/premium-black-430-x-305-x-140mm-mailing-box |
| Easy Signs | E-flute kraft mailer, **no printing** | 410 x 330 x 70 | 50 $262.50; 250 $915.00; 500 $1,615.00; 1,000 $3,080.00; 2,500 $7,700.00 (all ex GST) | $5.25 / $3.66 / $3.23 / $3.08 / $3.08 | Ex GST | $17.27 ex per order | calculator, section 1a |
| Easy Signs | B-flute kraft mailer, no printing | 405 x 350 x 95 | 50 $329.60; 250 $1,148.00; 500 $2,028.00; 1,000 $3,864.00; 2,500 $9,660.00 (ex GST) | $6.59 / $4.59 / $4.06 / $3.86 / $3.86 | Ex GST | $17.27 ex | calculator |

The Pack'n'Carry Large (250 wide) fails the width fit. The ATpack Ex Large and the Colpac Large Platter are windowed catering trays, not mailers:
they suit a pilot (bake visibility, and a seal across the lid), not the final hinged-lid structure.

---

## 3. Greaseproof liner sheets

### 3a. Printed (custom)
| Supplier | Product / spec | Qty and price as published | Unit | GST | MOQ / lead | URL |
|---|---|---|---|---|---|---|
| Top Shelf Concepts (AUD) | Custom Print Food Paper, 38gsm, food-safe inks, 1 to 3 colours, **300 x 400 mm** | 1,000 $705.00; 2,500 $840.00; 5,000 $1,060.00; 10,000 $1,540.00 | $0.705 / $0.336 / $0.212 / $0.154 | Not stated | Min 1,000; "2-3 weeks plus shipping" | https://topshelfconcepts.com/products/custom-printing-greaseproof-paper |
| Top Shelf Concepts | same, 400 x 600 mm | 1,000 $795; 2,500 $1,076; 5,000 $1,535; 10,000 $2,845 | $0.795 / $0.430 / $0.307 / $0.285 | Not stated | as above | same |
| noissue (USD, AUD estimate) | Custom Foodsafe Paper 38gsm, white or kraft, **380 x 380 mm**, 1 colour | 250 US$158.76; 500 US$173.88; 1,000 US$230.04; 2,000 US$356.40; 5,000 US$667.44 | AUD est. 250 A$228.99 (A$0.916); 500 A$250.80 (A$0.502); 1,000 A$331.80 (A$0.332); 2,000 A$514.06 (A$0.257); 5,000 A$962.70 (A$0.193) | Not stated | The site's "Custom Food Paper" listing tag reads "Delivery: 2 - 3 weeks" **[CONFIRM to AU]** | https://noissue.co/shop/food-papers/custom-foodsafe-paper/ |
| noissue | same, 380 x 380, 2 colours | 250 US$250.56; 500 US$261.36; 1,000 US$322.92; 2,000 US$459.00; 5,000 US$830.52 | AUD est. A$361.40 / A$376.98 / A$465.77 / A$662.05 / A$1,197.92 | | | same |
| noissue | same, 300 x 420 mm, 1 colour | 250 US$153.36; 500 US$168.48; 1,000 US$220.32; 2,000 US$340.20; 5,000 US$618.84 | AUD est. A$221.20 / A$243.01 / A$317.78 / A$490.70 / A$892.60 | | | same |
| Bio Supply (AU) | Greaseproof 35gsm, full colour, cuts up to 400 x 600 | "From $330 /1,000 ex GST" (headline, smallest cut). Half cut 300 x 400 is live-calculator only and was not captured: **[QUOTE]** | | Ex GST | Min 1,000 per version; 7 to 10 business days dispatch | https://www.biosupply.com.au/greaseproof-paper.html |
| WF Plastic | Printed greaseproof | MOQ 10,000 sheets; no price published | | | | https://wholesale.wfplastic.com.au/custom-printing/printed-greaseproof-paper/ |
| Gorilla Print, Star Stuff Group | Printed greaseproof | No price table read. Gorilla states MOQ 250 for food paper | | | | gorillaprint.com.au/catering/greaseproof-paper/ ; starstuffgroup.com.au/printed-greaseproof-paper/ |

Liner sizing note: 300 x 400 lies flat in a 385 x 290 base, with 15 mm of excess on the length. 380 x 380 and 400 x 330 would turn up the sides.
The PDS owns the final liner size.

### 3b. Plain
| Supplier | Product | Pack / price | Per sheet | GST | URL |
|---|---|---|---|---|---|
| ATpack | Greaseproof Wrapping Paper 1/2 Cut, **400 x 330 mm** | 800 pcs $18.50 | $0.0231 | Not stated | https://www.atpack.com.au/accessories/greaseproof-wrapping-paper/ |
| ATpack | Greaseproof Wrapping Paper 1/3 Cut, 400 x 220 mm | 1,200 pcs $18.50 | $0.0154 | Not stated | https://www.atpack.com.au/greaseproof-wrapping-paper-1-3-cut-400x220mm-1200p |

---

## 4. Seal labels (round kraft, about 70 mm)
No supplier read on 2026-10-03 publishes a **70 mm kraft** round. The nearest published sizes are 60 mm (kraft) and 75 mm (white paper).
A 70 mm kraft round is **[QUOTE]**.

| Supplier | Spec | Prices as published | GST | Lead / notes | URL |
|---|---|---|---|---|---|
| Supr Pack (AU) | Kraft paper stickers, **60 x 60 mm** (circle available), CMYK; "White printing is available only for bulk quantities (5000 & above)"; recycled uncoated paper | 250 $99; 500 $129; 1,000 $169; 2,000 $249 (no 5,000 tier listed) | "Tax included"; free shipping | "Sydney/Melbourne: Within 2 weeks". Custom size: contact (70 mm = [QUOTE]) | https://suprpack.com.au/products/craft-paper-base-custom-stickers |
| Gift Packaging (AU) | Custom printed 60 mm kraft brown circle labels on A4 sheets (24/sheet), CMYK, no white ink, uncoated | Each: 60-119 $0.46 inc ($0.42 ex); 120-238 $0.37 ($0.34); 240-468 $0.35 ($0.32); 480-948 $0.26 ($0.24); 960-1,188 $0.22 ($0.20); 1,200+ $0.20 ($0.18). Order in lots of 12 | Inc and ex both shown | 2 to 5 business days from proof. **Out of stock** when read | https://www.giftpackaging.com.au/p/custom-printed-60mm-kraft-brown-circle-labels-self/DIG-LABEL-A4-60RC-KRAFT |
| Paperlust (AU) | Circle stickers, white matte paper, no laminate, **60 mm** (not kraft) | 100 $104; 300 $176; 500 $212; 2,000 $613; 5,000 $1,072 (1,000 not in the table: configurator only) | Inc GST | 24 h production after proof; delivery $10 | https://printshop.paperlust.co/products/circle-stickers |
| noissue (USD) | Sticker sheets, circle **75 x 75 mm**, acid-free uncoated paper (not kraft) | 250 US$115; 500 US$120.75; 1,000 US$166.75; 2,000 US$218.50; 5,000 US$391 | Not stated | AUD est. A$165.87 / A$174.17 / A$240.52 / A$315.16 / A$563.97 | https://noissue.co/shop/stickers/sticker-sheets/custom-printed-stickers/ |

For 5,000 kraft 60 mm: Gift Packaging's 1,200+ rate gives 5,004 x $0.20 = $1,000.80 inc GST (lots of 12). Supr Pack publishes no 5,000 tier.

---

## 5. Merchandise

### 5a. Blank garments and bags (AS Colour, published on ascolour.com.au, AUD)
AS Colour's own page shows quantity pricing. Black is assumed to be the same price as the base colour **[CONFIRM black is not a premium colour]**.

| Product | Spec (as listed) | 1-9 | 10-49 | 50+ | URL |
|---|---|---|---|---|---|
| Staple Tee 5001 | Regular fit, 180 GSM | $30.00 inc / $27.27 ex | $22.50 / $20.45 | $18.00 / $16.36 | https://www.ascolour.com.au/staple-tee-5001/ |
| Classic Tee 5026 | Regular fit, 220 GSM | $36.00 / $32.73 | $27.01 / $24.55 | $21.60 / $19.64 | https://www.ascolour.com.au/classic-tee-5026/ |
| Stock High Profile Cap 1100 | High profile, flat peak | $30.00 / $27.27 | $24.00 / $21.82 | $18.00 / $16.36 | https://www.ascolour.com.au/stock-cap-1100/ |
| Finn Five Panel Cap 1103 | Low profile, flat peak | $30.00 / $27.27 | $24.00 / $21.82 | $18.00 / $16.36 | https://www.ascolour.com.au/finn-five-panel-cap-1103/ |
| Carrie Tote 1001 | "Large Style" | $20.00 / $18.18 | $16.01 / $14.55 | $14.00 / $12.73 | https://www.ascolour.com.au/carrie-tote-1001/ |

### 5b. Decoration (published price tables)
| Decorator | Method | Table as published | GST | Notes | URL |
|---|---|---|---|---|---|
| Australianess (AESS) | Screen print, per print, one location, badge/A4/A3, min 25 | 1 colour: 25+ $6.59; 50+ $5.09; 100+ $4.59; 200+ $4.09. 2 colour: 25+ $7.08; 50+ $5.58; 100+ $5.08; 200+ $4.58 | Inc GST | Garment not included **[CONFIRM]**. Screen or setup fees not shown **[CONFIRM]**. The page's 3-colour row (25+ $6.88) is lower than its 2-colour row, an anomaly to query | https://australianess.com.au/pricing-for-decorations/ |
| Australianess | Embroidery, per unit, min 5 | 6-11 $14.25 ex / $15.70 inc; 12-19 $9.50 / $10.45; 20-49 $8.50 / $9.35; 50-90 $7.50 / $8.25; 100-199 $6.50 / $7.15; 200+ $6.00 / $6.60 | Both shown | Stitch count and digitising fee not stated **[CONFIRM]** | same |
| T-Shirts Only | Screen print, **price includes a WHITE t-shirt**, printing and metro shipping | Standard tee, 1 col: 25 $14.60; 50 $13.20; 100 $10.10. 2 col: 25 $16.30; 50 $14.10; 100 $10.90. Premium tee, 1 col: 25 $16.10; 50 $14.70; 100 $11.60. 2 col: 25 $17.80; 50 $15.60; 100 $12.40 | Ex GST | Black garments and the garment brand are not stated: **[QUOTE]** for a black AS Colour tee | https://www.tshirtsonly.com.au/printing-pricelist/ |

### 5c. Aprons
| Supplier | Product | Price as published | GST | Notes | URL |
|---|---|---|---|---|---|
| Aussie Chef | Bib Apron Black, 200gsm poly/cotton (65/35), 70 x 86 cm | $17.60 | Not stated | "Buy 10+ and Save 5%, Buy 20+ and Save 10%". Logo embroidery: "Initial one-off set up charge, cost per unit depends on logo size, colours & quantity", with no figure published: **[QUOTE]** | https://www.aussiechef.com.au/bib-apron-black |
| Aussie Chef | Bib Apron with Pocket, Black | $18.70 | Not stated | as above | https://www.aussiechef.com.au/bib-apron-w-pkt-black |
| Aussie Chef | Heavy Weight bib apron, 240gsm black poly/cotton | $26.20 | Not stated | as above | https://www.aussiechef.com.au/heavy-weight-black |

### 5d. Totes (decorated, all-in)
| Supplier | Spec | 25 | 50 | 100 | URL |
|---|---|---|---|---|---|
| noissue (USD) | Custom tote, organic cotton 4 oz, black, 400 x 400 mm | US$138.00 (A$199.05 est.) | US$212.75 (A$306.87) | US$362.25 (A$522.50) | https://noissue.co/shop/bags/tote-bags/custom-basic-tote-bags/ (page redirected from /custom-tote-bags/) |

---

## 6. Printed paper takeaway bags
| Supplier | Spec | Price as published | GST | MOQ / notes | URL |
|---|---|---|---|---|---|
| QIS Packaging (AU) | Coloured paper carry bag (black available), 310 W x 420 H, 110 gusset, twist handle, 1 colour 1 side, print area 180 x 180 | $1.84 each | Inc GST ("Tax included") | Min 500. "One-off set up cost", amount not on the product page: **[QUOTE]** | https://www.qispackaging.com.au/products/custom-printed-paper-carry-bags-in-a-range-of-colours-1-colour-1-side-420x310mm |
| QIS Packaging | Brown paper carry bag 350 x 260, 1 colour 1 side | $1.40 each | Inc GST | Min 500 + setup | https://www.qispackaging.com.au/collections/printed-paper-carry-bags-with-handle |
| noissue (USD) | Food-service paper bag, kraft 100gsm, flat handle, 430 x 330 x 180, 1 colour | 10,000 US$4,588.50 (US$0.459 each; about A$6,618 est.) | Not stated | Smallest volume tier is 10,000 (a single sample is US$57.50) | https://noissue.co/shop/bags/takeout-bags/wholesale-custom-paper-bags-food-service/ |

**Fit:** neither QIS bag takes a 410 x 330 box lying flat (the bag widths are 310 and 350). A bag sized for flat catering boxes is **[QUOTE]**.

---

## 7. Cost model: landed packaging cost per catering box-set

### 7a. Definitions and formulas
- **Box-set** = 1 box + 1 greaseproof liner + 1 seal label. One box holds one dozen (working assumption from the brief).
- **Order cost** = box-set cost x dozens. Site packages: Morning Tea = 2 dozen, Office Platter = 3, Function Spread = 5 (internal use only; never printed).
- For each component at quantity tier Q: `component_cost_per_set = (published price for the smallest tier >= Q, or a stated sum of tiers, + delivery) / Q`, ex GST.
- `set_cost(Q) = box(Q) + liner(Q) + seal(Q)`; `cash_outlay(Q) = sum of the component totals`.
- Inc to ex: `/1.1`. USD to AUD: `/0.6933` (estimate, landed costs excluded).
- Excluded (no published figure): artwork and design, freight on the ATpack and Top Shelf orders, the converter's tooling or plate charges, storage, wastage, and the labour of folding and packing.

### 7b. Component inputs (ex GST, AUD)
| Component | 250 | 500 | 1,000 | 2,500 | Derivation |
|---|---|---|---|---|---|
| **A** Easy Signs printed kraft E-flute 410x330x70 (incl. $17.27 delivery) | $1,389.77 ($5.559) | $2,442.27 ($4.885) | $4,637.27 ($4.637) | $11,567.27 ($4.627) | item + 17.27 |
| **A0** Easy Signs unprinted kraft, same size | $932.27 ($3.729) | $1,632.27 ($3.265) | $3,097.27 ($3.097) | $7,717.27 ($3.087) | item + 17.27 |
| **H** ATpack BetaCater Ex Large tray + lid (GST not stated, treated as ex) | $482.50 ($1.930) | $965.00 ($1.930) | $1,930.00 ($1.930) | $4,825.00 ($1.930) | n x $96.50 per 50 |
| **E** Paperlust full-colour Large 425x295x110 | $7,256.82 ($29.027) | $8,504.55 ($17.009) | $12,000.00 ($12.000) | not published | (inc + $10 delivery) / 1.1 |
| **G** noissue kraft 305x381x76 (AUD est., excl. freight/import) | $2,517.96 ($10.072) | $3,968.52 ($7.937) | $7,271.06 ($7.271) | not published | USD / 0.6933 |
| **L1** ATpack plain greaseproof 400x330 (800/pk) | $18.50 ($0.074) | $18.50 ($0.037) | $37.00 ($0.037) | $74.00 ($0.030) | packs of 800 needed x $18.50 |
| **L2** Top Shelf printed 300x400, 1-3 col (min 1,000) | $705.00 ($2.820) | $705.00 ($1.410) | $705.00 ($0.705) | $840.00 ($0.336) | next tier up |
| **L3** noissue printed 380x380, 1 col (AUD est.) | $228.99 ($0.916) | $250.80 ($0.502) | $331.80 ($0.332) | $764.86 ($0.306) | 2,500 = 2,000 + 500 tiers |
| **S1** Supr Pack kraft 60 mm | $90.00 ($0.360) | $117.27 ($0.235) | $153.64 ($0.154) | $343.64 ($0.137) | inc / 1.1; 2,500 = 2,000 + 500 tiers |
| **S2** Gift Packaging kraft 60 mm (lots of 12) | $80.18 ($0.321) | $119.13 ($0.238) | $201.60 ($0.202) | $456.00 ($0.182) | 252/504/1,008/2,508 x tier rate inc / 1.1 |

### 7c. Scenarios: cost per box-set and per order (ex GST, AUD)
| Scenario | Tier | Per set | Cash outlay | 2-dozen order | 3-dozen | 5-dozen |
|---|---|---|---|---|---|---|
| **P0 Pilot, plain** (H + L1 + S1): windowed kraft tray, plain liner, printed kraft seal | 250 | $2.36 | $591.00 | $4.73 | $7.09 | $11.82 |
| | 500 | $2.20 | $1,100.77 | $4.40 | $6.60 | $11.01 |
| | 1,000 | $2.12 | $2,120.64 | $4.24 | $6.36 | $10.60 |
| | 2,500 | $2.10 | $5,242.64 | $4.19 | $6.29 | $10.49 |
| **P1 Plain mailer** (A0 + L1 + S1): right structure, no print | 250 | $4.16 | $1,040.77 | $8.33 | $12.49 | $20.82 |
| | 500 | $3.54 | $1,768.04 | $7.07 | $10.61 | $17.68 |
| | 1,000 | $3.29 | $3,287.91 | $6.58 | $9.86 | $16.44 |
| | 2,500 | $3.25 | $8,134.91 | $6.51 | $9.76 | $16.27 |
| **C1 Printed core** (A + L1 + S1): printed kraft mailer, plain liner | 250 | $5.99 | $1,498.27 | $11.99 | $17.98 | $29.97 |
| | 500 | $5.16 | $2,578.04 | $10.31 | $15.47 | $25.78 |
| | 1,000 | $4.83 | $4,827.91 | $9.66 | $14.48 | $24.14 |
| | 2,500 | $4.79 | $11,984.91 | $9.59 | $14.38 | $23.97 |
| **C2 Printed, all-AU** (A + L2 + S1): printed liner from Top Shelf | 250 | $8.74 | $2,184.77 | $17.48 | $26.22 | $43.70 |
| | 500 | $6.53 | $3,264.54 | $13.06 | $19.59 | $32.65 |
| | 1,000 | $5.50 | $5,495.91 | $10.99 | $16.49 | $27.48 |
| | 2,500 | $5.10 | $12,750.91 | $10.20 | $15.30 | $25.50 |
| **C3 Printed, noissue liner** (A + L3 + S1); liner is an AUD estimate | 250 | $6.84 | $1,708.76 | $13.67 | $20.51 | $34.18 |
| | 500 | $5.62 | $2,810.34 | $11.24 | $16.86 | $28.10 |
| | 1,000 | $5.12 | $5,122.71 | $10.25 | $15.37 | $25.61 |
| | 2,500 | $5.07 | $12,675.77 | $10.14 | $15.21 | $25.35 |
| **C4 Premium full colour** (E + L2 + S1) | 250 | $32.21 | $8,051.82 | $64.41 | $96.62 | $161.04 |
| | 500 | $18.65 | $9,326.82 | $37.31 | $55.96 | $93.27 |
| | 1,000 | $12.86 | $12,858.64 | $25.72 | $38.58 | $64.29 |

Worked example (C1 at 1,000): box $4,620.00 + $17.27 delivery = $4,637.27; liner 2 x $18.50 = $37.00; seal $169 / 1.1 = $153.64.
Total $4,827.91 / 1,000 = **$4.83 per set**. A 3-dozen Office Platter order = 3 x $4.83 = $14.48 (computed on the unrounded set cost).

### 7d. What the model does not yet price (the brief's real spec)
The brief's production spec (black-liner outer, 2 spot colours: opaque white + ember red, side vents, FEFCO 0427 with tuck front at 385 x 290 x 50) is
**[QUOTE]** from a converter. Scenario C1 is the cheapest published stand-in, but it **cannot reproduce opaque white** (section 1a). Use C1 to set a cost
ceiling for comparing converter quotes, not to fix the final spec.

---

## 8. Merch cost model (ex GST, AUD, garment + decoration, before setup/screen fees and freight)
Formula: `unit = AS Colour tier price (ex) + decoration tier price (ex)`. The quantity tier applies to each line separately.

| Item | 25 | 50 | 100 | Inputs |
|---|---|---|---|---|
| Staple Tee 5001 black + 1-col screen print | $26.44 | $20.99 | $20.53 | blank $20.45 / $16.36 / $16.36 + print $5.99 / $4.63 / $4.17 |
| Staple Tee 5001 + 2-col screen print | $26.89 | $21.43 | $20.98 | + print $6.44 / $5.07 / $4.62 |
| Classic Tee 5026 + 1-col | $30.54 | $24.27 | $23.81 | blank $24.55 / $19.64 / $19.64 |
| Classic Tee 5026 + 2-col | $30.99 | $24.71 | $24.26 | |
| Bib apron with pocket (Aussie Chef, GST basis unknown) + embroidery | $25.33 | $24.33 | $23.33 | apron $18.70 less 10% (20+) = $16.83 + embroidery $8.50 / $7.50 / $6.50; embroidery setup [QUOTE] |
| Stock Cap 1100 + embroidery | $30.32 | $23.86 | $22.86 | cap $21.82 / $16.36 / $16.36 + embroidery $8.50 / $7.50 / $6.50 |
| Carrie Tote 1001 + 1-col screen print | $20.54 | $17.36 | $16.90 | tote $14.55 / $12.73 / $12.73 + print $5.99 / $4.63 / $4.17 [CONFIRM the decorator prints totes at garment rates] |
| noissue printed black tote (all-in, AUD est.) | $7.96 | $6.14 | $5.22 | USD / 0.6933, excl. freight/import |

Apron check at 25: $18.70 x 0.90 = $16.83, plus $8.50, = $25.33.

---

## 9. RFQ templates

### 9a. Converter RFQ: catering box (send to 2-3 Sydney corrugated converters plus one online supplier)
```
Subject: RFQ - printed corrugated catering box, Dough Boss (Sydney) - [date]

1. Structure: one-piece mailer, hinged lid with tuck front (FEFCO 0427 style),
   side vents (dieline attached). Internal 385 x 290 x 50 mm [CONFIRM after fit test].
2. Board: E-flute or B-flute (please advise). Outer liner: black kraft; inner: natural brown kraft.
   Quote both flutes. State the board grade/ECT and the board supplier.
3. Food contact: the inside contacts a greaseproof liner, not food directly. Please state food-contact
   compliance of the board and inks, and supply the declaration.
4. Print: outside only, 2 spot colours: opaque white + red (target PMS to match #e2231a; draw-down on board).
   Alternative quote: natural kraft board with 3 spot colours (black flood + white + red).
   Print method (flexo / digital) and the minimum line and type sizes.
5. Quantities: 250 / 500 / 1,000 / 2,500 / 5,000. Price per unit AND total, ex GST.
6. One-off costs, listed separately: cutting die/forme, print plates, proof, prototype/white sample,
   pre-production printed sample. State whether tooling is kept for reorders and for how long.
7. Lead times: sample, first run, and repeat run, from artwork approval.
8. Packing: flat-packed, units per bundle and per carton, pallet quantity, carton dimensions.
9. Delivery: to [Revesby / Bankstown / Roselands] - per drop, ex GST. Split delivery to 3 shops?
10. Payment terms, deposit, validity period of the quote, over/under-run tolerance (%).
11. Minimum reorder quantity, and the price break on reorder (no tooling).
12. Can you supply 10 unprinted structural samples before tooling?
```

### 9b. Liner and seal RFQ
```
Liner: greaseproof sheet [300 x 400 / 380 x 380 mm, PDS to confirm], 35-40 gsm, food-contact grade,
  1 colour (black or red) one side, repeat pattern, food-safe ink. Qty 1,000 / 2,500 / 5,000 / 10,000.
  Unit and total ex GST, setup/plate fees, lead time, delivery to Sydney, food-contact declaration.
Seal: round 70 mm kraft paper label, permanent adhesive, printed 1-2 colours (black + red; white if possible
  - state the MOQ for white), supplied on sheets or rolls (state which), writable surface for marker.
  Qty 1,000 / 5,000. Unit and total ex GST, lead time, delivery.
```

### 9c. Merch RFQ
```
Subject: RFQ - staff and retail merch, Dough Boss (Sydney)

Items and quantities:
- AS Colour Staple Tee 5001 (and Classic 5026 alt), BLACK, sizes S-XXL split [list], qty 25 / 50 / 100.
- Bib apron, black, with pocket, qty 10 / 25 / 50.
- Cap: AS Colour 1100 or 1103, black, qty 25 / 50.
- Tote: AS Colour Carrie 1001, black/natural, qty 50 / 100.
Decoration:
- Tees/totes: screen print, front [size] 1-2 spot colours (white + red on black), optional back print.
  State colours, max print size, ink type (plastisol/water-based), underbase charge for white on black.
- Aprons/caps: embroidery, logo approx [W x H] mm, est. stitch count, thread colours [white + red].
Please quote per unit and total ex GST, separately listing: garment cost, decoration cost, screen/setup
per colour, embroidery digitising, artwork fee, freight to Sydney, lead time from artwork approval,
and whether we can supply our own garments (and the price difference).
```

---

## 10. Open [CONFIRM] / [QUOTE] items that change the numbers
1. **Fit test** a mini in a sample box (Easy Signs $30 ex sample, or the noissue sample). The box size drives every box line.
2. Dimension basis (internal or external) for Easy Signs and noissue sizes.
3. GST treatment for ATpack, Top Shelf and Aussie Chef; Dough Boss's GST registration.
4. noissue: AU storefront pricing, freight, import GST/duty. Every noissue AUD figure here is an estimate at 0.6933.
5. Converter quote for the real spec (black outer, opaque white + red): section 9a.
6. 70 mm kraft seal: no published price (nearest published: 60 mm).
7. Screen/setup fees and embroidery digitising (none published by the decorators read).
8. Food-contact suitability of each printed box and ink (PDS-RESEARCH.md owns this; it may rule out a low-cost option).
9. A paper bag sized to carry flat catering boxes: [QUOTE].
