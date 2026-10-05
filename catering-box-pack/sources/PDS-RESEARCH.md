# Dough Boss "dozen" catering box: PDS technical research

INTERNAL. Prepared 2026-10-03 for the packaging data sheet (PDS). Not legal advice.

- **OBSERVED** = I read it at the cited URL on 2026-10-03 (all access dates below are 2026-10-03). Where the fetch tool returned only a search-result summary, the line says *(search summary only)*; re-read those before relying on them.
- **INFERRED** = my prepress or engineering reasoning from the observed facts.
- **[CONFIRM: ...]** = an open gap for the converter, the owner or a physical test. **REGULATOR-CHECK** = confirm with NSW Food Authority or FSANZ.
- I invented no prices, MOQs, lead times or test results. Every MOQ or lead time below is quoted from the company's own page. **Quotes have to be requested from each converter.**

---

## 0. Key decisions (spec-sheet summary)

| Item | Decision | Basis |
|---|---|---|
| Style | FEFCO 0427 one-piece folder: hinged lid from the back wall, tuck front into front-wall slots, ear-lock side tabs | OBSERVED (FEFCO code, §1.1) |
| Working internal size | 385 x 290 x 50 mm (L x B x H, FEFCO internal convention) [CONFIRM: fit test with 12 real bakes, 4 x 3] | Brief |
| Flute / board | **E-flute single wall** (about 1.5 to 1.6 mm). Target about 32 ECT (about 5.6 kN/m) or equivalent. B-flute is the fallback if the lid sags or the stack test fails | INFERRED from §1.2 and §1.4 |
| Outer liner | Black. Either a through-dyed black kraft liner or natural kraft flood-printed black [CONFIRM: availability with converter; I found no AU mill black liner] | §1.3 |
| Inner liner | Natural kraft with a supplier food-contact declaration for the intended use | §2 |
| Print | **Water-based flexo post-print, 2 spot colours**: opaque white, then ember red printed over a white underbase. Digital only for prototypes or if a white-capable digital press is found | §3 |
| Red | **PANTONE 485 C** reference (approve against a 485 U drawdown on the real board over white) | §3.3 |
| Liner sheet | Greaseproof, **40 to 50 gsm, KIT 6 or above (aim 7 to 8)**, no added PFAS (total fluorine below 100 ppm), supplier declaration of compliance [CONFIRM by strike-through test] | §2.4 |
| Vents | **4 x Ø10 mm round vents, 2 per long side wall**, cut through both plies of the doubled wall (about 314 mm², about 2.5 times the 1984 pizza-box ratio) [CONFIRM by hold test] | §1.5 |
| Stacking | Order stacks of up to 5 boxes are an expected use. Safe count **[CONFIRM by test]** | §1.6 |
| Box print line (compliance) | "Allergen information available on request." No recycling, compostable, FSC or ARL marks | §4, §5 |
| Per-order seal label | Product names and counts, allergen declaration using FSANZ required names, packed date and time, storage instruction [CONFIRM], order reference | §4.3 |

---

## 1. Structure

### 1.1 FEFCO 0427 geometry

- OBSERVED: the FEFCO code (international fibreboard case code, adopted by ICCA) says the 04 series is "Folder-type boxes and trays usually consist of only one piece of board. The bottom of the box is hinged to form two or all side walls and the cover. Locking tabs, handles, display panels etc., can be incorporated." 0427 is drawn in the 04 series. https://www.fefco.org/sites/default/files/documents/FEFCO_Codes_of_Designs.pdf
- OBSERVED (same document): "Unless otherwise specified all dimensions are expressed as internal dimensions in mm ... Length (L) x Breadth (B) x Height (H)". L is the longer side at the opening. Dimensions are "measured ... on the flat blank from the centre of crease bearing the thickness of the material in mind". Layouts are viewed from the inside of the case. So write the PDS size as **385 x 290 x 50 mm (internal)**.
- OBSERVED *(search summary only)*: 0427 walls are connected to the floor by creases; the lid extends from the back panel and folds forward; the outer lid flaps tuck into two slots on the front as a reclosable lock; ear-lock tabs secure it. https://eu.lilpackaging.com/blogs/packaging-knowledgebase/what-are-fefco-codes-plus-the-difference-between-cardboard-boxes-postal-boxes , https://packhelp.com/fefco-codes/
- INFERRED panel anatomy to draw on the dieline (as on standard 0427 tooling): base; front wall rolled over to double thickness, with locking tabs that drop into slots in the base; side walls doubled, with dust flaps and ear locks that lock into the lid hinge; back wall; lid; lid side flaps; lid front tuck flap. Add a **thumb notch** (a half-round cut in the front wall top edge, about 25 to 30 mm wide [CONFIRM with converter's standard]) so the tuck can be pulled open. Tuck-front "cherry"/ear lock terms: https://pakfactory.com/roll-end-front-tuck-with-dust-flaps.html *(search summary only)*.

### 1.2 Flute caliper

| Flute | Nominal caliper | Source |
|---|---|---|
| E | 1/16 in nominal, 1.5 to 1.6 mm measured, about 90 flutes/ft | OBSERVED https://packwire.com/blog/corrugated-flute-types |
| B | 1/8 in nominal, 3.0 to 3.2 mm measured, about 47 flutes/ft | OBSERVED (same) |
| E (AU retail mailer boards) | "1.5 mm white or brown kraft E-flute" (Vistaprint AU); "E Flute (1.6mm)", "B Flute (3mm)" (EasySigns) | OBSERVED §6 URLs |

- OBSERVED: E-flute gives a print surface that "approaches the smoothness of folding carton" but has "less cushioning than B or C". B-flute has "good puncture resistance" with a "decent print surface". (packwire, above)

### 1.3 Inside-to-outside and crease (score) allowances

- OBSERVED: "Corrugated will fold at its midpoint ... so the scoring allowance is directly related to the caliper of the board." https://library.packagingschool.com/blog/dieline-design
- OBSERVED, score allowances to subtract from centre-of-score panel measurements to get inside dimensions (RSC boxes): https://www.atlanticpkg.com/wp-content/uploads/2025/12/How-to-Measure-a-Corrugated-Box.pdf

| Flute | Length panel | Width panel | Depth |
|---|---|---|---|
| E | 1/16 in (about 1.6 mm) | 1/16 in (about 1.6 mm) | 1/8 in (about 3.2 mm) |
| B | 1/8 in (about 3.2 mm) | 1/8 in (about 3.2 mm) | 1/4 in (about 6.4 mm) |

- INFERRED for 0427 (rolled double walls): the converter's CAD (ArtiosCAD or similar) will set the allowances, but plan on these approximate outside sizes:
  - **E-flute: about 391 x 295 x 53 mm.** That is about +4t on L (two doubled side walls), +3t on B (doubled front, single back) and +2t on H (base and lid), with t = 1.6 mm.
  - **B-flute: about 397 x 299 x 56 mm.**
  - The inner ply of each doubled wall takes up its own thickness inside. Let the converter compensate so 385 x 290 stays clear inside. [CONFIRM: converter sample at the final size]
- OBSERVED *(search summary only)*: corrugated dimensions, flap clearances, score allowances and joint construction "must be finalised by the box plant for its flute and equipment". https://racklify.com/encyclopedia/die-cut-corrugated-box-technical-guide-to-design-and-manufacturing/

### 1.4 Board grade and strength

- OBSERVED: "ECT 32 (32 lbs. per inch) is comparable to a 200-lb. burst strength"; 200 lb test is burst, 32 ECT is stacking strength. https://www.uline.com/CustomerService/ULINE_FAQ_Ans?FAQ_ID=45 *(search summary only)*
- OBSERVED *(search summary only)*: a print mailer supplier lists "1/16" e-flute ... 32ECT standard for light and medium-duty usage" (https://sinalite.com/en_us/mailer-boxes.html). A foodservice distributor lists a "Kraft B-flute Corrugated Cardboard Mailer 32 ECT" (https://www.imperialdade.com/catalog/product-detail/neww-packaging-and-display-10x7x3-in-kraft-b-flute-corrugated-cardboard-mailer-32?id=336538).
- INFERRED metric equivalents to put in the RFQ: 32 ECT ≈ **5.6 kN/m**; 200 lb/in² burst ≈ **1,380 kPa**. Ask AU converters to quote their own board code with ECT (kN/m) and burst (kPa) values. Do not specify a US grade.
- OBSERVED: the AU mill Opal makes AP Kraft liner (120 to 165 g/m²), High Performance Kraft (127 to 256 g/m²), Recycled (120 to 135 g/m²) and Premium Recycled (160 to 205 g/m²). The page does not mention a black or coloured liner. https://opalanz.com/products/paper/liner-paper/
- **Recommendation (INFERRED):** E-flute, kraft liners both faces, at about 32 ECT / 5.6 kN/m or the converter's nearest food-mailer grade. Black outer face: either a dyed black liner (not found in the AU mill range, so likely imported [CONFIRM]) or natural kraft flood-printed black (this adds a third ink, see §3.2).

### 1.5 Vents for hot baked goods

- OBSERVED: US patent 4,441,626 "Pizza box" (10 Apr 1984) gives "approximately one square inch of ventilation ... for each cubic foot of volume", with holes in the central side panel or corner cut-outs. https://patents.google.com/patent/US4441626A/en
- OBSERVED *(search summary only)*: vents in the lid, the sides or both let steam escape and slow base softening, but more venting cools the food faster. https://image-ppubs.uspto.gov/dirsearch-public/print/downloadPdf/7210613
- INFERRED sizing: internal volume 385 x 290 x 50 mm = 5.58 L ≈ 0.197 ft³. The 1984 ratio then gives about 0.2 in² (about 127 mm²) total.
  - Bakes are oilier than pizza and orders sit stacked, so specify **4 x Ø10 mm (about 314 mm² total), 2 per long side wall, centred at mid-height, symmetric, at least 30 mm in from each corner crease**, punched through both plies of the doubled wall with the holes aligned.
  - Vents must not sit on a crease. Keep print about 6 mm (1/4 in) clear of every cut-out (GLBC rule, §3.6).
  - [CONFIRM by test: 30-minute hold at serving temperature; check base crispness, condensation on the lid and board softening]

### 1.6 Stacking

- OBSERVED: McKee formula BCT = 5.87 x ECT x √(caliper x box perimeter) estimates box compression strength (BCT). Strength falls as relative humidity rises. https://www.ipack.com/blog/how-to-guide-measuring-boxes and https://racklify.com/encyclopedia/vertical-velocity-using-ect-edge-crush-test-to-maximize-your-warehouse-height *(search summaries only)*
- INFERRED, an order-of-magnitude estimate only, NOT a test result: E-flute 32 ECT, outside about 391 x 295 mm gives BCT ≈ 1.5 kN when dry, before any humidity, time or safety factor. A short 50 mm 0427 with doubled walls is outside McKee's normal range. Stacked catering boxes carry only the boxes above them (5 dozen = 5 boxes). Lid span sag and steam softening are therefore more likely to govern than crush.
- **Safe stack height: [CONFIRM by test].** Test filled, hot boxes stacked 5 high for 60 minutes at ambient. Check lid deflection, wall bulge and tuck release. Repeat for a car-boot ride. [CONFIRM: filled weight of 12 bakes]

---

## 2. Materials and food contact in Australia

### 2.1 What applies

- OBSERVED: **Standard 3.2.2, clause 9** (Australia only). When packaging food, a food business must "(a) only use packaging material that is fit for its intended use; (b) only use material that is not likely to cause food contamination; and (c) ensure that there is no likelihood that the food may become contaminated during the packaging process." FSANZ P1034 SD2: https://www.foodstandards.gov.au/sites/default/files/food-standards-code/proposals/Documents/P1034%20Packaging%201CFS%20SD2%20Code%20requirements.pdf
- OBSERVED (same document):
  - **Standard 1.1.1—11**: packaging and any article in contact with food must not, if taken into the mouth, be capable of being swallowed or obstructing a passage, or be likely to cause bodily harm. The note gives examples including "writing or other graphics".
  - **Standard 1.4.1 / Schedule 19** sets maximum levels for some migrants (vinyl chloride, tin, acrylonitrile).
  - State and territory Food Acts carry general packaging provisions.
- OBSERVED: FSANZ advice is to "check the packaging is suitable (ask the supplier or manufacturer for assurance or certification that the material is food-safe)". It names "use of recycled materials for packaging" as a factor in chemical leaching. https://www.foodstandards.gov.au/business/food-safety/fact-sheets/foodpackaging
- OBSERVED *(search summary only)*: AS 2070-1999 covers **plastics** for food contact, not paper, and is reported as withdrawn. https://www.compliancegate.com/food-contact-material-regulations-australia , https://store.standards.org.au/product/as-2070-1999 . Do not cite it for board.
- OBSERVED: from 1 July 2025 the federal IChEMS Schedule 7 bans the manufacture, import, export and use of PFOS, PFOA and PFHxS, including in articles such as packaging. https://afgc.org.au/newsletter_post/upcoming-bans-on-pfas-chemicals-in-packaging-effective-from-1-july-2025/
- OBSERVED: the APCO action plan (v3, Nov 2023) phased fibre-based direct food contact packaging out of PFAS by 31 Dec 2023, using a **total fluorine threshold of 100 ppm**. It names greaseproof paper as a historic PFAS use. https://prpackaging.com/wp-content/uploads/2026/05/Action-Plan-to-Phase-Out-PFAS-in-Fibre-Based-Food-Contact-Packaging.pdf
- REGULATOR-CHECK: NSW Food Act 2003 packaging provisions were not read here.

### 2.2 How converters usually evidence food-contact suitability

- OBSERVED: FSANZ reports that businesses "were currently utilising international regulations. The uptake of EU and US regulations is apparently similar". FSANZ P1034 SD6: https://www.foodstandards.gov.au/sites/default/files/food-standards-code/proposals/Documents/P1034%20Packaging%201CFS%20SD6%20Summary%20of%20international%20approaches%20to%20CMPF.pdf
- OBSERVED, example of a paper/board Declaration of Compliance (DoC) as issued in the trade (Duni):
  - EU Reg. (EC) 1935/2004 (framework), EU 2023/2006 (GMP), BfR Recommendation XXXVI (paper and board)
  - overall migration ≤ 10 mg/dm², with simulants, times and temperatures stated
  - area of use (food types, temperature)
  - https://mediabank.duni.com/doc/207319_DoC_en.pdf
- OBSERVED *(search summary only)*: DoCs commonly cite 21 CFR 176.170 (paper in contact with aqueous and fatty foods) and 176.180 (dry food). https://www.agrana.com/fileadmin/inhalte/Lieferantendokumente/FML_Notice_of_Conformity_packaging_paper__cardboard_and_paperboard.pdf
- OBSERVED, what AU suppliers publish:
  - Opal liners carry food-contact suitability "on nominated grades": AP Kraft "direct food contact (dry/peeled/washed)"; High Performance Kraft "direct food contact (dry, non-fatty)"; Premium Recycled "direct contact with aqueous and fatty food types stored at room temperature". https://opalanz.com/products/paper/liner-paper/
  - EasySigns says its mailers are "considered safe for use in contact with most foods that are dry, non-fatty products". https://www.easysigns.com.au/products/details/custom-printed-mailer-boxes
- **RFQ requirement (INFERRED):** for the board (inner liner and adhesive), the liner sheet, the inks and the seal label adhesive, ask for:
  - a written DoC naming the regulation set (AU Std 3.2.2 cl 9 plus EU 1935/2004 / BfR XXXVI and/or FDA 21 CFR 176.170)
  - the intended food type (fatty, hot) and contact temperature
  - a PFAS statement (no intentionally added PFAS; total fluorine below 100 ppm)
  - the ink system's food-packaging statement (§3.4)
  - Do not accept "food-grade" as a bare claim.

### 2.3 Mineral oil migration and recycled board

- OBSERVED: the FSANZ survey (3 Aug 2018, 61 packaging and 56 food samples) found mineral oil hydrocarbons "in all food packaging samples". There is "a strong correlation between the level of MOH detected and the proportion of recycled material used in the packaging", with the highest levels in 100% recycled packaging. "In most cases, packaging which contained recycled materials also used an inner lining as a physical barrier". Levels in Australian food were "very low and unlikely to be a public health and safety concern". https://www.foodstandards.gov.au/publications/Analytical-survey-of-mineral-oil-hydrocarbons-in-food-and-food-packaging and PDF https://www.foodstandards.gov.au/sites/default/files/publications/Documents/Mineral%20oil%20hydrocarbons.pdf
- OBSERVED *(search summary only)*: functional barriers cut MOSH/MOAH migration substantially; newspaper inks are the main source in recycled board. https://foodpackagingforum.org/news/mineral-oils-in-food , https://ink-safety-portal.siegwerk.com/substances-of-concern/mineral-oils
- INFERRED: **yes, use the greaseproof liner.** Bakes are fatty and go in hot, and fat and heat drive migration. The kraft liners that carry AU food claims are rated for dry, non-fatty food, or for fatty food only at room temperature (Opal). Grease strike-through would also show as dark stains through the kraft. Prefer a virgin-fibre (kraft) inner liner to recycled.

### 2.4 Greaseproof liner sheet spec

- OBSERVED: KIT level (TAPPI T 559) is "the highest degree of aggressiveness from progressively more aggressive oil-solvent mixtures that the sheet can resist for ... (typically 15 seconds)". The scale runs 1 to 12. "Heavy paper doesn't mean greaseproof"; gsm is weight and stiffness only. https://www.paperindex.com/academy/what-is-a-kit-level-the-simple-scale-for-measuring-grease-resistance/
- OBSERVED, application bands (same publisher): dry pastries and bread KIT 3 to 6, 30 to 45 gsm; **hot baked goods (warm pastries, paninis) KIT 5 to 8, 40 to 60 gsm, "heat-stable barrier"**, confirm heat tolerance in the supplier technical data sheet (TDS); pizza KIT 5 to 8, 40 to 60 gsm. https://www.paperindex.com/academy/the-menu-match-matrix-for-food-packaging-paper-how-to-match-your-menu-with-the-right-kit-level-and-specifications/
- OBSERVED, AU market reference points:
  - Ball & Doggett greaseproof reels (28 gsm and 30 gsm; country of origin Malaysia) list "Degree of Penetrated Oil" by a GB/T method rather than KIT. https://products.ballanddoggett.com.au/content/uploads/2018/06/technical_specs_packaging_greaseproof_reels.pdf
  - Foopak Grease board: "Kit level six is suitable for low level grease resistance". https://products.ballanddoggett.com.au/content/uploads/2020/07/technical_specs_packaging_foopak_grease.pdf
  - Retail sheets of 32 to 35 gsm are listed. https://galipofoods.com.au/product/greaseproof-paper-32gsm-royalpac-330x400mm-800/ , https://spicers.com.au/collections/food-services-food-wrap-foopak-premium-greaseproof-paper-35gsm *(search summaries only)*
- **Spec (INFERRED):**
  - Greaseproof (or glassine-type) paper, **40 to 50 gsm, KIT ≥ 6, target 7 to 8**, heat-stable to serving temperature [CONFIRM on TDS]
  - no intentionally added PFAS, with a total fluorine below 100 ppm test report
  - DoC for fatty food at hot fill
  - Size: either flat **380 x 285 mm** to cover the base, or **about 480 x 385 mm** to cup up the walls (better barrier, covers vent zones less). [CONFIRM: fit sample]
  - If the liner is printed, print on the non-food face only, with a food-packaging ink statement (§3.4). Unprinted is safest.
  - [CONFIRM by test: 30-minute hot-hold strike-through on the oiliest bake (spiced mince or cheese)]

---

## 3. Print

### 3.1 Flexo vs digital for short runs

- OBSERVED: flexo needs a plate per colour per design; digital has "no plate costs". The quoted article (UNI Packaging, 21 Apr 2017) frames digital as an "any quantity" solution. https://packagingeurope.com/digital-v-flexo-part-ii
- OBSERVED: some AU digital mailer services cannot print white. EasySigns (HP Latex, water-based): "We do not print white, so we won't be able to add white ink to your Kraft boxes." https://www.easysigns.com.au/products/details/custom-printed-mailer-boxes
- OBSERVED: Tailor Made Packaging (Sydney) prints flexo "up to 4 Pantone colours" and also litho; Paperlust kraft mailers print 1 to 2 spot colours flexo. §6 URLs.
- **Decision (INFERRED):** the design is 2 spot solids including opaque white on a dark substrate. That is a flexo job. Digital is for prototypes and fit tests only, unless a converter shows a white-capable digital press on black board.

### 3.2 Opaque white on black kraft

- OBSERVED: Weilburger SENOFLEX WB Opaque White 395802 was developed "for flexographic printing, in particular for corrugated post-printing ... especially for grey and brown board", with "excellent opacity even with single application". It is "not intended for overprinting or use in a mixing circuit". https://www.weilburger.com/en/news-media/news/detail-en/two-new-senoflexr-wb-opaque-whites-from-weilburger-graphics-gmbh
- INFERRED:
  - On black, one hit of white usually reads grey-cream with fibre show-through. Ask for a **high-opacity white, double hit (two stations) or a heavy anilox** on solids, and approve on a drawdown. A slightly translucent single hit can serve the "ink sitting in the card" look, but large type must stay legible.
  - **Ember red will not read on black without a white underbase.** Build the white plate to include every red area, choked about 0.25 to 0.5 mm inside the red edge so no white halo shows.
  - Any black flood on natural kraft (if no dyed liner exists) is a third colour. Reverse-out shapes in it reveal kraft brown, not white.

### 3.3 Pantone for #e2231a

- OBSERVED, third-party sRGB simulations (Pantone's own site hides the values behind Pantone Connect: https://www.pantone.com/connect/485-C). Source https://www.colorxs.com/color/pantone-485-c , https://www.colorxs.com/color/pantone-3556-c , https://www.colorxs.com/color/pantone-2347-c , https://www.colorxs.com/color/pantone-179-c

| Pantone | Published hex | Published Lab | ΔE76 vs #e2231a (INFERRED, sRGB D65) |
|---|---|---|---|
| **485 C** | #DA291C | L 47.72 a 65.46 b 51.39 | 4.4 |
| 3556 C | #E63422 | not shown | 4.0 |
| 2347 C | #E10600 | L 47.15 a 72.39 b 61.21 | 8.3 |
| 179 C | #E03C31 | L 51.04 a 62.15 b 44.46 | 11.8 |

- OBSERVED: a printplanet forum thread quotes PANTONE 485 C as Lab 48.92 / 67.16 / 52.6 ("Pantone published Oct '07"), with no illuminant stated. https://printplanet.com/threads/l-a-b-values-of-pantone-485.2598/latest
- **Decision (INFERRED): PANTONE 485 C.** It is within about 4 ΔE on screen, and the 2007 Lab value is within about 1 to 2 ΔE of #e2231a's computed Lab (48.8 / 69.0 / 53.8). It is a standard, widely stocked red. 3556 C is a hair closer on screen but less common.
  - Corrugated is uncoated, so approve against **485 U** expectations and a converter drawdown printed **over the white underbase on the actual black board**.
  - OBSERVED: GLBC provides a draw-down of the PMS colour on corrugated for approval before the final print proof. https://www.glbc.com/print-and-graphics/print-types/flexographic-printing-guidelines

### 3.4 Inks

- OBSERVED: EasySigns prints with water-based inks; Weilburger's corrugated opaque white is a WB (water-based) series. §3.1, §3.2 URLs.
- OBSERVED *(search summary only)*: inks for the non-food-contact side of food packaging are commonly formulated to the **Swiss Ordinance SR 817.023.21 Annex 10** and the **EuPIA Exclusion Policy**. https://www.sunchemical.com/wp-content/uploads/2022/05/Food-Packaging-Switzerland-Swiss-Ordinance-Legislation-Version-1.1.pdf
- **Spec (INFERRED):** water-based flexo inks on the outside only. Ask for the ink maker's food-packaging statement (Swiss Annex 10 / EuPIA). No print on the food-contact inner liner.

### 3.5 Line weights, type sizes, screens

OBSERVED, a corrugated converter's published flexo rules (Great Little Box Company): https://www.glbc.com/print-and-graphics/print-types/flexographic-printing-guidelines

| Rule | Value |
|---|---|
| Positive type | 8 pt minimum, 10 pt preferred |
| Reverse type | 10 pt minimum, 12 pt preferred |
| Positive line | 2 pt minimum |
| Reverse line | 4 pt minimum |
| Screen | "run at 45 lpi (i.e. a 3% dot may grow to 10%)" |

**PDS rule (INFERRED, stricter because white sits on black and red sits on white):**
- white type ≥ 10 pt, 12 pt preferred
- red type on white ≥ 12 pt
- lines: ≥ 2 pt white, ≥ 4 pt for red or knockouts
- **solids only, no tints or halftones**
- Bebas Neue wordmark tracking 0.13 em is fine at display size. Barlow body copy ≥ 10 pt.

### 3.6 Registration, trapping, bleed, safe zone

- OBSERVED (GLBC, above):
  - registration tolerance "between +/- 1/16″ and 1/4″ depending on which printing press"
  - minimum trapping (overprint) of 3/16″
  - bleed: minimum 1/2″
  - for die-cut items, minimum 1/4″ between print and the board edge, any score, hand holes or cut-outs
- **PDS values (INFERRED, confirm with the chosen converter):**
  - registration allowance **±1.5 mm** design target. Assume up to ±3 mm and never butt-register white to red.
  - red over white underbase with the white choked 0.25 to 0.5 mm. Overlap any other two-colour join by the converter's trap value.
  - **bleed 6 mm** beyond cut lines (GLBC asks 12.7 mm; use the converter's figure)
  - **safe zone 6 mm** inside cuts, creases and vents
  - No critical copy across lid or wall creases; fold cracking will show (intended texture, but not through text).

### 3.7 Dieline file supply

- OBSERVED, Printcraft (AU): create a swatch named **"Dieline"** (or Keyline), colour type **Spot**, 100% magenta recommended. Put it on a separate layer on top with no fill and **Overprint Stroke** on. Show perforation, fold and cut lines with different line types or separate named spots ("Cut Line, Fold Line & Perforation Line"). https://printcraft.com.au/post/how-to-create-a-dieline
- OBSERVED, 3M global dieline requirements: editable vector PDF at 100% scale; each line type a unique spot colour at **1 pt** stroke; a line-type legend in the file; separate labelled die outlines for inside and outside views. https://multimedia.3m.com/mws/media/2619412O/global-packaging-dieline-requirements.pdf
- **PDS convention (INFERRED from the above):**

| Element | Layer | Spot name | Style |
|---|---|---|---|
| Cut | Dieline | `Dieline-Cut` (100M) | solid 1 pt, overprint |
| Crease / fold | Dieline | `Dieline-Crease` (100C) | dashed 1 pt, overprint |
| Vent holes, thumb notch | Dieline | `Dieline-Cut` | solid 1 pt |
| Bleed limit (6 mm) | Guides | `Bleed` | thin dotted, non-printing |
| Safe zone (6 mm) | Guides | `Safe` | thin dotted, non-printing |
| White plate | Art-White | `Opaque White` | spot, solid only |
| Red plate | Art-Red | `PANTONE 485 C` | spot, over the white underbase |

  - Supply one 1:1 vector PDF (outside view, viewed from print side) plus a legend.
  - Outline fonts. Name the file to the converter's convention. Supply the liner and seal label as separate files.

---

## 4. Labelling (NSW bakery, catering box)

### 4.1 What the Code says

- OBSERVED, Standard 1.2.1 (compilation F2026C00538, 09 June 2026, https://www.legislation.gov.au/F2015L00386/latest/text ; text https://www.legislation.gov.au/F2015L00386/2026-06-09/2026-06-09/text/original/epub/OEBPS/document_1/document_1.html):
  - **1.2.1—6(1)**: packaged food need not bear a label if it "(a) is made and packaged on the premises from which it is sold" or "(d) is delivered packaged, and ready for consumption, at the express order of the purchaser (other than when the food is sold from a vending machine)".
  - **1.2.1—9(6)–(7)**: for such food, the **name of food** and "any advisory statements and **declarations** (see sections 1.2.3—2 and 1.2.3—4)" must be "(a) displayed in connection with the display of the food; or (b) provided to the purchaser on request". 1.2.3—4 is the mandatory allergen declaration.
  - **1.2.1—9(3)(a)**: any warning statement required by 1.2.3—3 must accompany or be displayed with the food (INFERRED: none expected for these bakes, REGULATOR-CHECK).
  - **1.2.1—9(4)(b)**: "information related to use required by paragraph 1.2.6—2(c)" must accompany the food (I did not read 1.2.6, REGULATOR-CHECK).
- OBSERVED, FSANZ: required names are wheat, fish, crustacean, mollusc, egg, milk, lupin, peanut, soy, sesame, almond, Brazil nut, cashew, hazelnut, macadamia, pecan, pistachio, pine nut, walnut, plus barley, oats and rye (gluten) and sulphites (≥ 10 mg/kg). For food not required to bear a label, "declarations must be displayed in connection with the food or provided to the purchaser upon request using the required names". New rules in force from 25 Feb 2024. https://www.foodstandards.gov.au/business/labelling/allergen-labelling
- OBSERVED, Safe Food Queensland factsheet (Qld regulator; same Code):
  - for food made at the express order of the consumer, consider "how long it can be kept, storage conditions (e.g. keep refrigerated)"
  - name of food, ingredient information and advisory statements are to be provided on request and are "recommended to be on the label"
  - potentially hazardous food must be kept at 5 °C or below or 60 °C or above, and delivered under temperature control in packaging that is "clean, food-grade and fit for purpose"
  - https://www.safefood.qld.gov.au/wp-content/uploads/2020/03/Safe-Food-Factsheet_Home-Delivery-or-Takeaway-Services_Final.pdf
- REGULATOR-CHECK:
  - (a) NSW Food Authority guidance on catering and delivery (its site returned HTTP 503 to the fetch tool).
  - (b) Division 3 of Standard 1.2.1 (sales to a "caterer") applies if Dough Boss sells boxes to an event caterer or other business that resells them. A label may then be required.
  - (c) Cheese and spiced-mince bakes are likely potentially hazardous food, so time and temperature controls on delivery apply.

### 4.2 Safest print line on the box (permanent print)

- **"Allergen information available on request."** plus the verified contact `catering@doughboss.com.au` (from the brief). INFERRED: this satisfies the "on request" route without fixing allergen content that varies by order.
- Do NOT print fixed allergen lists, "may contain", "allergen-free", "gluten free", "halal", ingredient claims or a phone number (brief hard rules).

### 4.3 Per-order seal or label instead (INFERRED, good practice beyond the minimum)

| Field | Content |
|---|---|
| For | customer or order reference |
| Contents | product names and counts (for example "Cheese round x 4") using the menu names |
| Allergens | bold **"Contains:"** + required names, from the shop's allergen matrix [CONFIRM: matrix exists]. Prefer a **pre-printed tick list of required names** over free handwriting |
| Packed | date and time |
| Storage | [CONFIRM: wording from the shop's food safety program, e.g. a refrigeration instruction; do not invent a time] |
| From | Dough Boss + shop (Revesby / Bankstown / Roselands) |

The round kraft seal can carry "For ___" and the date by hand, but the allergen field should be printed or ticked, not composed freehand.

---

## 5. Sustainability and recyclability claims

- OBSERVED, ACCC "Making environmental claims: a guide for business" (12 Dec 2023, https://www.accc.gov.au/about-us/publications/making-environmental-claims-a-guide-for-business ; PDF https://www.accc.gov.au/system/files/greenwashing-guidelines.pdf):
  - "Consumers are likely to understand the term 'recyclable' to mean that the product can be recycled using ordinary local collection or drop off points."
  - "Ensure that your product can be recycled through a household/local council waste collection ... before using such claims."
  - "If it is not practicable for the majority of consumers to recycle the product, the claim should not be made."
  - Compostable (FOGO) acceptance "may not be accepted in another" council.
- OBSERVED: Canterbury-Bankstown kerbside accepts "Pizza Boxes (clean)" in co-mingled recycling. https://recyclingnearyou.com.au/kerbside/CanterburyBankstownNSW . Food-soiled cardboard is not addressed. Customer councils vary.
- OBSERVED *(search summary only)*: the Australasian Recycling Label is part of APCO's ARL Program, with recyclability assessed in the PREP tool. https://apco.org.au/the-australasian-recycling-label (redirects to https://arl.org.au/about)
- **Decision (INFERRED): print no recyclability, compostability or "eco" claim, and no ARL, FSC or recycling symbol.**
  - A box used for oily bakes may be greasy, and the liner may not be kerbside recyclable.
  - The black dye or flood ink and the liner have not been assessed in PREP.
  - FSC marks need the converter's chain-of-custody and a licence [CONFIRM].
  - If a disposal line is later wanted, the only candidate is a qualified instruction, after a PREP assessment or council check, such as "Remove liner and food. Clean box: check your council's recycling rules." Treat that as REGULATOR-CHECK (ACCC).

---

## 6. Australian converters and printers (request quotes from each)

All figures are quoted exactly from each company's page, read 2026-10-03. **No prices given. Quotes must be requested.** White-on-black capability is unconfirmed for all of them; ask about it first.

| # | Company | Location (as published) | Relevant capability | Published MOQ / lead time | URL |
|---|---|---|---|---|---|
| 1 | Tailor Made Packaging | Sydney NSW; "in house design & manufacturing" | Flexo "up to 4 Pantone colours", litho; "All our cardboard is food-grade", "HACCP Certified", "food-safe inks" (supplier claims; ask for the DoC) | MOQ not published. Custom printed food packaging "generally takes 7 to 10 business days from artwork approval" | https://tailormadepackaging.com.au/industries/custom-cardboard-food-boxes/ |
| 2 | EasySigns | Smeaton Grange NSW | Digital HP Latex, water-based; E-flute 1.6 mm, B-flute 3 mm, kraft or white; **"We do not print white"**; food: "dry, non-fatty products" | Production "24 hours (1 working day) after artwork proof sign off" for smaller orders, scaling to 6 working days for larger orders (tiered by order value) | https://www.easysigns.com.au/products/details/custom-printed-mailer-boxes |
| 3 | Hero Packaging | "Made in Sydney" | Custom full-colour shipping boxes | "No Minimums"; lead time not published | https://www.heropackaging.com.au/ |
| 4 | Paperlust Print Shop | Oakleigh South VIC; "Made in Australia" | Kraft B-flute, 1 to 2 spot colours flexo; full-colour B-flute white-lined (digital or offset); custom sizes on request | Kraft: "Minimum order is 500 boxes", "approx. 2 to 3 weeks after proof approval". Full-colour: "from just 10 boxes", "3 to 7 working days from proof approval" | https://printshop.paperlust.co/products/custom-kraft-mailer-boxes ; https://printshop.paperlust.co/products/custom-full-colour-mailer-boxes |
| 5 | Rogue Print & Mail | Loganholme QLD; "100% Australian Owned & Operated" | 1.6 mm E-flute or 3 mm B-flute, kraft or white claycoat; 40+ die sizes; custom on request | Minimum 10 (quote form); "Just a few days (depending on qty + finish)" | https://www.rogueprintandmail.com.au/custom-mailerboxes/ |
| 6 | Vistaprint Australia | "Printed in Australia" | "1.5 mm white or brown kraft E-flute"; 9 sizes up to 43 x 30.5 x 14 cm; no food statement | Neither MOQ nor turnaround is published on the page; "Need more than 5000? Get a quote" | https://www.vistaprint.com.au/labels-stickers/packaging/shipping-packaging/mailer-boxes |
| 7 | Abbe | Dandenong VIC; national supply | Corrugated manufacturer, flexo-printed boxes, die-cut boxes; FSSC 22000 certification stated | Not published | https://www.abbe.com.au/ |
| 8 | Vivo Boxes (vivopak) | Warehouse Keysborough VIC; "our manufacturer" is a separate entity (INFERRED: likely offshore) | Custom bakery boxes incl. corrugated; "food grade, suitable for direct and indirect contact" | "Minimum order quantity is 1,000 units"; air "approximately 3-4 weeks", sea "approximately 8 weeks" | https://www.vivopak.com.au/custom-boxes/food/bakery/ |

INFERRED shortlist for this spec (flexo, opaque white on black, custom 0427 size): **1 (Sydney flexo, closest to the shops) and 7 (corrugated plant)** for production. **2 or 5** for fast unprinted or CMYK fit-test samples at the exact size. Send every one the same RFQ: the dieline, the board spec (§1.4), the 2-spot spec (§3), the food-contact documents required (§2.2), and quantities [CONFIRM: order volumes with owner].

---

## 7. Open items

- [CONFIRM] Bake size, filled weight and fit at 385 x 290 x 50 internal (sample test).
- [CONFIRM] Black liner availability (dyed) vs flood black (third ink); converter to advise.
- [CONFIRM by test] Vent hold test, liner strike-through, stack height (hot, 5 high), lid sag.
- [CONFIRM] Converter drawdowns: opaque white opacity on black; 485 over white.
- [CONFIRM] DoCs for board, liner, inks and label adhesive; PFAS (TF < 100 ppm) report for the liner.
- [CONFIRM] The shop's allergen matrix and the storage wording for the per-order label.
- REGULATOR-CHECK: NSW Food Authority on delivered catering (labelling, temperature control); Std 1.2.6—2(c) "information related to use"; Division 3 if selling to caterers; ACCC review before any disposal or recycling line.
