# Merchandise tech packs: factory production specifications

Status: DRAFT FOR RFQ, 3 October 2026. Ten spec sheets for a decorator, garment factory or printer, local (Australia) or overseas (China), to quote, sample and produce without a briefing call. All dimensions are millimetres. Currency is AUD.

Source of every fact: `sections/merch.md` (blanks, placements, copy), `sections/brand-identity.md` (wordmark, colour, minimum sizes), `sections/costing.md` sections 3 to 6 and 8 (published prices, read 3 Oct 2026), `sections/packaging.md` and `sections/pds.md` (seal and liner). Placement diagrams, drawn from `merch_art.py` at the stated sizes, are in `flats/merch/`; `flats/merch/merch-diagrams.pdf` holds all ten.

`[QUOTE]` means a price, MOQ, lead time or fee we have no published figure for: the supplier must supply it. `[CONFIRM: ...]` means an open decision or fact that must close before anything is printed. Nothing in this pack is a price commitment.

## A. Read this first (applies to every sheet)

### A1. What we need back from you (the quote return)

For each item you quote, return in one sheet: unit price ex GST at each quantity tier you offer; setup, screen, digitising, artwork and sample fees listed separately; MOQ; sample cost and lead time; production lead time from approved sample; freight to Sydney and incoterm if overseas; whether you can use free-issue blanks (we supply) and the price difference; the blank and ink or thread codes you propose. Where you propose a change to this spec, state it as a separate line; do not quote a changed spec silently. All of these are `[QUOTE]` until you answer.

### A2. Colour key (used on every sheet)

| Name | Role | Reference | Garment print (screen, DTF) | Embroidery thread | Cut vinyl | Digital paper and board |
|---|---|---|---|---|---|---|
| EMBER | The full stop only (wordmark and headlines). Never body text, outlines or large fills, except as noted on sheet 6 | **PANTONE 485 C** (reference; screen token #e2231a) | Spot ink matched to 485 C, printed over the white underbase | Nearest thread to 485 C from your range; you nominate the code | Nearest stock red to 485 C | Pantone-matched digital if available, else CMYK proof to 485 C. Ember is the hardest colour to hold in CMYK |
| CREAM | The ink on black: wordmark, outlines, headlines | The reading of opaque white ink on black. Screen token #eee8de. **No Pantone number assigned: [CONFIRM: brand lead to assign one]**; until then the approved drawdown is the standard | Opaque white. Warm to read #eee8de on the drawdown; do not supply optical white | Nearest warm off-white to #eee8de; you nominate the code. Not optical white | Nearest stock vinyl to #eee8de, checked against the drawdown | Reproduced as the paper or flood contrast; check on the press proof |
| CHAR | The garment or stock colour; ink only where noted | **Black.** Screen token #0a0807. No separate Pantone: black blank, or rich black flood matched to #0a0807 on the proof | None: the garment is the colour | None | Char backing layer (sheet 8) | Flood on white stock (sheets 6 and 7) |

Both colours are named spot swatches in artwork: `DB-CREAM`, `DB-EMBER` (and `DB-CHAR` on flood jobs). No CMYK builds on garment jobs, no gradients. Approved swatch (ink drawdown, thread card or vinyl chip) goes on the job sheet and becomes the colour standard. A match on one substrate does not prove a match on another.

### A3. Wordmark minimum sizes and the check for each item

The wordmark is DOUGH BOSS. in Bebas Neue capitals, tracking 0.13 em, single-rule box, ember full stop. It is supplied as vector and is **never redrawn, retyped, stretched, outlined or re-spaced**. Box proportions: width to height 3.36 to 1; border 1.45 % of box width; cap height 11.2 % of box width. Clear space: at least one cap height on all sides, outside the box (proposal for owner approval).

| Process | Minimum box width (brand-identity.md) |
|---|---|
| Digital print (paper, vinyl stickers) | 25 mm |
| Screen print and DTF on fabric | 45 mm |
| Embroidery | 90 mm |
| Cut vinyl | 110 mm |

| Sheet | Wordmark box (W x H, border, cap height) | Process | Minimum | Check |
|---|---|---|---|---|
| 1 Tee, front | 90 x 26.8, 1.31, 10.1 | Screen or DTF | 45 | Pass |
| 2 Apron, bib | 100 x 29.8, 1.45, 11.2 | Embroidery | 90 | Pass |
| 3 Cap | 90 x 26.8, 1.31, 10.1 | Embroidery | 90 | Pass, at the minimum with no headroom: do not shrink |
| 4 Tote, front | 250 x 74.4, 3.63, 28.0 | Screen | 45 | Pass |
| 5 Paper bag, front | 140 x 41.7, 2.03, 15.7 | Bag maker's print process [CONFIRM] | 25 digital; carton flexo rule is 11 mm Bebas type or larger (box about 69 mm) | Pass either way |
| 6 Sticker sheet | 80 x 23.8; 45 x 13.4 (two); 36 x 10.7 (round) | Digital | 25 | Pass; smallest border is 0.52 mm |
| 7 Tent card | 60 x 17.9, 0.87, 6.7 | Digital or offset | 25 | Pass |
| 8 Window decal | 600 x 178.6, 8.71, 67.1 | Cut vinyl | 110 | Pass |
| 9 Seal label | No wordmark | n/a | n/a | n/a |
| 10 Liner | No boxed wordmark; unboxed pattern text only | Printed paper | n/a | See sheet 10 note |

### A4. Copy that may be printed

Only this copy appears on any item: DOUGH BOSS. (the wordmark) · FRESH FROM THE OVEN. · FEED THE WHOLE TABLE. · REVESBY · BANKSTOWN · ROSELANDS (shop name lines only) · doughboss.com.au · @doughboss · catering@doughboss.com.au · "Allergen information available on request." (counter and catering items only).

Never print: "Minis", "halal", "gluten free", "authentic", "best", "allergen-free"; any price, quantity, capacity, certification, health or origin claim (including "Australian cotton"); a phone number; hours; "Since 2009" (unconfirmed). "THREE SHOPS BAKING DAILY" stays off long-life items (decal, embroidery). If a proof carries any other word, stop and ask.

Headline type (all lines other than the wordmark): Bebas Neue, tracking 0.01 em, ember full stop. Small text: Barlow (500 or 600) and Barlow Condensed 600. Fonts are free (SIL OFL 1.1) and are supplied outlined; do not substitute.

### A5. Artwork file naming and format (every sheet)

Pattern, from the pack's naming convention: `DB_[ITEM]_[COMPONENT]_[SIZE]_v[NN]_[STATUS]_[YYYY-MM-DD].[ext]`. Items here: `TEE`, `APRON`, `CAP`, `TOTE`, `BAG`, `STICKER`, `TENT`, `DECAL`, `SEAL`, `LINER`. Files are vector PDF (PDF/X-4) or AI at 1:1, fonts outlined, spot swatches as named in A2, unless a sheet says otherwise. Any change, however small, is a new version number. We keep the master; files you hold are copies. Return your proofs and digitised files with the same names and your own status suffix.

### A6. Sample approval route (every sheet; item detail on each sheet)

Seven steps, from merch.md section 10. Nothing runs without step 6.

| Step | What happens | Signed by | Pass rule |
|---|---|---|---|
| 1 Master art | Our artwork from the master wordmark vector and the A4 copy list | Brand lead | Wordmark identical to master; every word on the list |
| 2 Digital proof | You return a proof on your template of the actual blank, dimensions in mm, placements from the seams | Owner | Sizes and placements within +/- 2 mm of this spec; above the process minimum |
| 3 Colour | Ink drawdown, thread swatch, vinyl chip or press proof, checked in daylight against A2 | Owner and brand lead | Ember reads as ember (not orange, not maroon); cream reads warm, not pure white |
| 4 Physical sample | Strike-off, sew-out, printed sample or test cut on the real blank or stock. Garments: XSM, MED and 5XL checked | Owner | Box border unbroken, counters of B, O, S open, full stop round and ember |
| 5 Wear or wash test | Per sheet | Brand lead | No cracking, lifting, fading against the swatch, or underbase showing |
| 6 Pre-production approval | Signed sample, quantity and size breakdown | Owner | In writing |
| 7 Delivery check | First-off and one sample per size or per carton against the approved sample | Shop manager | Matches the sample; otherwise reject and return |

### A7. Changes against merch.md and open conflicts found while writing this pack

These are decisions for the brand lead. Each is flagged again on its sheet.

1. **Headline tracking.** merch.md specifies the tee back, tote back and window lines with the wordmark's 0.13 em tracking. brand-identity.md allows 0.13 em in the wordmark only and sets headlines at 0.01 em. This pack follows brand-identity.md and keeps merch.md's **widths** (250, 120, 180, 300 mm), so the **cap heights are larger** than merch.md states: tee back option 1 is 23.5 mm (merch.md: 17.8), option 3 is 24.4 mm (18.5), the tote back line 24.4 mm (18.5), the apron pocket line 11.7 mm (8.5 to 8.9), the window line 29.3 mm (22), and the stacked back option block about 102 mm high (78). [CONFIRM: brand lead.]
2. **Ember dot sticker.** sheet 6 follows merch.md (six 25 mm ember circles), but brand-identity.md limits ember to the full stop, the hinge band and the seal ring, and merch.md rule 0.2 bans large fills. [CONFIRM: brand lead permits an ember fill at 25 mm; alternative is a char circle with a smaller ember dot.]
3. **Costing blanks do not match the spec blanks.** costing.md section 8 prices an Aussie Chef apron (not the AS Colour Carrie Apron 1082) and a Stock Cap 1100 (not the Access Cap 1130). Neither spec blank has a published price: both are [QUOTE].
4. **One print location only is costed.** The tee spec has a front and a back print, the tote a front and an optional back. costing.md section 8 prices one location. The second location is [QUOTE].
5. **Liner weight and size.** The PDS wants 40 to 50 gsm and a 380 x 285 mm working size. The published printed-paper listings in costing.md are 38 gsm (Top Shelf, noissue) and 35 gsm (Bio Supply) in other sizes. No published listing meets the PDS weight. [QUOTE] for a custom size and weight.
6. **Unboxed pattern text on the liner.** brand-identity.md says never drop the box from the wordmark. The liner pattern is unboxed text. [CONFIRM: brand lead accepts it as pattern, not logo use.]
7. **Tee front placement.** merch.md places the front wordmark 80 mm below the collar seam; an example in the brief said 75 mm. This pack uses 80 mm (merch.md).
8. **Grading of the tee art by size group** (100 or 110 mm from centre front; 250 or 280 mm back width) is a proposal. merch.md gives one front size and one back size, with 280 mm for 5XL as [CONFIRM].
9. **Overseas blanks.** The named blanks are AS Colour. An overseas factory either takes free-issue AS Colour blanks, or quotes an equivalent (same fabric weight, composition and fit). [CONFIRM: owner's choice.] No substitution without a signed sample.

---

## 1. Staff t-shirt

Diagram: `flats/merch/staff-tshirt.png`. Item code `TEE`.

| Field | Specification |
|---|---|
| Blank | **AS Colour Staple Tee 5001, Black.** 180 GSM, 28-singles, 100% combed cotton, neck rib, side seamed, shoulder-to-shoulder tape, double needle hems, preshrunk. Sizes XSM to 5XL. https://www.ascolour.com.au/staple-tee-5001/ (read 3 Oct 2026). Alternative retail tee: **AS Colour Classic Tee 5026, Black**, 220 GSM, 22-singles, sizes SML to 5XL: same art and placements [CONFIRM owner's choice; 5001 recommended for staff in hot shops] |
| Colourway | Garment CHAR (black). Art: CREAM (white ink, underbase) and EMBER (PANTONE 485 C). Two spot colours plus the white underbase. See A2 |
| Decoration, front | **Screen print**, plastisol or water-based as you advise for opaque cream on black. White underbase under the cream. DTF is the fallback for small top-ups only, never mixed with screen-printed shirts in one shift |
| Decoration, back | Same method as the front, one line of copy per run, chosen from the three options below. Do not mix lines within a uniform set |
| Placement, front | Wordmark box **90 x 26.8 mm**, the same on every size. Left chest (wearer's left). **Top of box 80 mm below the front neck seam**, measured from the collar seam at centre front on size MED. **Centre of the art 100 mm from centre front** on XSM to XL, **110 mm** on 2XL to 5XL (proposal, confirm on the sample). Inner edge of the box then sits 55 mm (XSM to XL) or 65 mm (2XL to 5XL) from centre front. Box is level (parallel to the hem). Check on XSM and 5XL that the art clears the armhole seam |
| Placement, back | Centred on centre back. **Top of the cap height 90 mm below the back neck seam** at centre back. Width **250 mm for XSM to XL; 280 mm for 2XL to 5XL** [CONFIRM 280 mm]. The drop from the seam is the same on every size |
| Back copy, option 1 (default) | FEED THE WHOLE TABLE. one line. Bebas Neue, tracking 0.01 em. At 250 mm wide: type 33.6 mm, cap height 23.5 mm. At 280 mm: type 37.6 mm, cap 26.3 mm |
| Back copy, option 2 | Stacked, left-aligned: FEED THE / WHOLE TABLE. The longer line is 250 mm wide (type 56.6 mm, cap 39.6 mm, line spacing 1.1 em, block about 102 mm high); at 280 mm wide: type 63.4 mm, block about 114 mm. Top of the first line's cap height 90 mm below the neck seam |
| Back copy, option 3 | FRESH FROM THE OVEN. one line. At 250 mm wide: type 34.9 mm, cap 24.4 mm. At 280 mm: type 39.1 mm, cap 27.4 mm |
| Minimum line and gap | 0.5 mm printed line; 1.0 mm open gap (house rule above the 0.3 mm process limit, because cream sits on a white underbase). Check the counters of B, O and S on the proof |
| Thread, ink | Ink only. DB-CREAM over white underbase; DB-EMBER. Drawdowns go on the job sheet |
| Artwork files | `DB_TEE_ART_FRONT-LC_XSM-5XL_v01_WIP_2026-10-03.pdf`; `DB_TEE_ART_BACK-OPT1_XSM-XL_v01_WIP_2026-10-03.pdf` and `..._BACK-OPT1_2XL-5XL_...pdf` (and OPT2, OPT3 if chosen). Vector PDF/X-4, fonts outlined, two named spot swatches. You make the underbase separation. DTF only: also a 1:1 PNG at 300 dpi with transparency |
| Size range and grading | XSM, SML, MED, LGE, XLG, 2XL, 3XL, 4XL, 5XL (5001). Size split [CONFIRM: owner, per staff list]. Art size is not graded except the back width (250 to 280 mm) and the front offset from centre front (100 to 110 mm), both in two groups |
| Care label | Keep the blank's own label intact: do not cover, remove or print over it, and add no label of ours. [CONFIRM with AS Colour that the blank's label meets Australian care-labelling requirements.] Care to follow the blank (AS Colour page): machine wash cold with like colours; wash inside out; do not bleach; do not tumble dry; do not dry clean; do not iron over the print; line dry in shade. A care card goes in each staff pack |
| Packing per unit | Folded flat, one tee per polybag, size marked on the bag. Cartons sorted by size, carton marked with item, size and quantity. No hang tags. [CONFIRM: owner] Carton quantity [QUOTE] |
| QC points | Wordmark 90 +/- 2 mm wide; 26.8 mm high. Placement within +/- 2 mm of the spec. Level, centred, not skewed. Box border unbroken (0.5 mm minimum line); counters open; full stop round and ember. Cream opaque with no underbase showing at edges. Hand feel soft, no cracking when stretched. Ink matches the approved drawdown. No smudges, spots or pinholes. Garment colour and size label correct. Same method on every shirt in one order |
| Sample approval | Steps 1 to 7 in A6. Step 4: strike-off on a MED, then XSM and 5XL for placement. Step 5: **10 wash cycles** following the care above on one sample per method; **reject on any cracking, lifting at the full stop or border, or underbase showing through the cream** |
| Cost reference (not a quote) | Blank, costing.md 5a, AS Colour page 3 Oct 2026: Staple Tee 5001 $30.00 inc / $27.27 ex GST (1 to 9); $22.50 / $20.45 (10 to 49); $18.00 / $16.36 (50+). Black assumed the same as the base colour [CONFIRM black is not a premium colour]. Print, 5b, Australianess, inc GST, per print, one location, minimum 25: 2 colour $7.08 (25+), $5.58 (50+), $5.08 (100+), $4.58 (200+); garment not included [CONFIRM], screen and setup fees not shown [CONFIRM]. Model, costing.md section 8 (ex GST, garment plus a **one-location** 2-colour print, before setup, screens and freight): $26.89 at 25, $21.43 at 50, $20.98 at 100. Classic Tee 5026, same basis: $30.99, $24.71, $24.26. **[QUOTE]**: second print location (back), white underbase, screens and setup per colour, DTF, black-garment surcharge, freight, lead time, MOQ |

## 2. Bib apron

Diagram: `flats/merch/bib-apron.png`. Item code `APRON`.

| Field | Specification |
|---|---|
| Blank | **AS Colour Carrie Apron 1082, Black.** 320 GSM 100% cotton canvas, herringbone cross-over shoulder strap, metal eyelets, front patch pocket, top-stitch detailing, preshrunk. One size: length 90 cm, waist 76 cm, pocket 24 x 46 cm (240 high x 460 wide). Bib width and waistline height not published [CONFIRM from the blank]. https://www.ascolour.com.au/carrie-apron-1082/ |
| Colourway | Garment CHAR (black). Thread CREAM and EMBER (PANTONE 485 C). See A2 |
| Decoration | **Embroidery**: crisp on heavy canvas, no print to crack where the apron flexes. At least one local decorator (Mercha) offers embroidery only on this apron |
| Placement, bib | Wordmark box **100 x 29.8 mm**, centred on the bib centre line. **Top of box 60 mm below the top hem.** Keep at least 15 mm from the eyelets and strap stitching (proposal, confirm on the sew-out) |
| Placement, pocket (optional) | Line A FRESH FROM THE OVEN. or Line B FEED THE WHOLE TABLE., **120 mm wide**, centred on the pocket, **top of cap height 30 mm below the pocket's top hem**. Bebas Neue 0.01 em. Line A: type 16.8 mm, cap 11.7 mm. Line B: type 16.1 mm, cap 11.3 mm. Both are above the 6.35 mm minimum letter height |
| Stitch notes | Box border: satin column at 1.45 mm, above the 1.27 mm minimum line. Letters 11.2 mm cap height: satin or tatami fill as the digitiser judges. Ember full stop: small satin or fill element, about 2.9 mm. Stabilising underlay on canvas; backing as the digitiser judges for 320 GSM. Two thread colours. Stitch count: you state it on the proof [QUOTE] |
| Thread | CREAM and EMBER, matched to A2. You nominate thread type and codes; polyester or rayon [CONFIRM: decorator's advice for repeated wiping and washing]. Approved thread card goes on the job sheet |
| Artwork files | `DB_APRON_ART_BIB_ONESIZE_v01_WIP_2026-10-03.pdf` and `..._POCKET-LINE-A_ONESIZE_...pdf` (master vectors, outlined). You return the machine file (for example DST) and a stitch-out proof. Dough Boss keeps the digitised file for reorders |
| Size range and grading | One size. No grading |
| Care label | Keep the blank's label intact; add none. Care per AS Colour page: hand wash cold separately; do not bleach; do not tumble dry; iron medium heat; line dry in shade. [CONFIRM the shop's laundering routine: if aprons go through a commercial wash, trial one apron through that cycle before bulk] |
| Packing per unit | Folded flat, one apron per polybag, no hang tag. Carton quantity [QUOTE] |
| QC points | Wordmark 100 +/- 2 mm wide; top 60 +/- 2 mm below the top hem; centred +/- 2 mm. At least 15 mm clear of eyelets. Border continuous, no gaps; satin lies flat, no puckering of the canvas or bib; letters legible with open counters; full stop round and ember; no loose threads, no backing visible from the front; thread colours match the swatch |
| Sample approval | A6 steps 1 to 7. Step 4: embroidery sew-out on the real canvas. Step 5: wash or wipe test following the shop's laundering routine, not just the AS Colour care |
| Cost reference (not a quote) | Blank: **no published price for the Carrie Apron 1082 in costing.md [QUOTE].** costing.md 5c and section 8 price a different apron (Aussie Chef Bib Apron with Pocket, 200 gsm poly/cotton, $18.70, GST basis unknown), so the section 8 apron line ($25.33 at 25, $24.33 at 50, $23.33 at 100) does **not** apply to this spec. Embroidery, 5b, Australianess, per unit, minimum 5, ex GST (inc GST): 6 to 11 $14.25 ($15.70); 12 to 19 $9.50 ($10.45); 20 to 49 $8.50 ($9.35); 50 to 90 $7.50 ($8.25); 100 to 199 $6.50 ($7.15); 200+ $6.00 ($6.60). Stitch count and digitising fee not stated [CONFIRM]. **[QUOTE]**: Carrie Apron blank, digitising, the optional pocket line (second location), lead time, MOQ |

## 3. Cap

Diagram: `flats/merch/cap.png`. Item code `CAP`.

| Field | Specification |
|---|---|
| Blank | **AS Colour Access Cap 1130, Black.** Light weight 100% cotton, six-panel, low profile, curved peak, adjustable fastener with metal clasp, tonal under-peak lining, one size. https://www.ascolour.com.au/access-cap-1130/ |
| Colourway | Garment CHAR (black). Thread CREAM and EMBER (PANTONE 485 C). See A2 |
| Decoration | **Embroidery** on the front panels. Heat press is not used: the cap is spot clean only and flour must be brushed off |
| Placement | Wordmark box **90 x 26.8 mm**, centred across the centre front seam. **Bottom of the box 15 mm above the peak seam** (peak-to-crown seam at centre front). Confirm on the sew-out that it sits evenly over the seam. Box is level to the peak seam. Printful's guidance for a low-profile cap allows up to 57 mm high; 26.8 mm leaves room |
| Stitch notes | Border: satin at 1.31 mm (minimum line 1.27 mm). Letters 10.1 mm cap height (minimum 6.35 mm). **Sew from the centre outward** to limit puckering at the seam. Stay under the decorator's cap stitch limit (Printful quotes about 15,000) [CONFIRM your limit]. The cap file is separate from the apron file even at the same size. Two thread colours |
| Thread | CREAM and EMBER, matched to A2. You nominate codes and type [CONFIRM] |
| Artwork files | `DB_CAP_ART_FRONT_ONESIZE_v01_WIP_2026-10-03.pdf` (master vector, outlined). You return the cap machine file and a stitch-out proof |
| Size range and grading | One size, adjustable. No grading |
| Care label | Keep the blank's label intact; add none. Care per AS Colour page: spot clean only; do not bleach; do not tumble dry. Issue two caps per staff member if worn daily. [CONFIRM whether caps form part of the food-safety hair-restraint practice] |
| Packing per unit | One cap per polybag, crown supported so it is not crushed, no hang tag. Carton quantity [QUOTE] |
| QC points | Wordmark 90 +/- 2 mm wide; bottom edge 15 +/- 2 mm above the peak seam; centred on the seam +/- 2 mm; level. No puckering or ripple across the panels; seam not distorted; border continuous; letters legible with open counters; full stop ember; backing trimmed; no thread tails inside. Fastener and peak undamaged |
| Sample approval | A6 steps 1 to 7. Step 4: sew-out on the real cap, checked from the front and a three-quarter view. Step 5: brush and spot-clean test only (no machine wash) |
| Cost reference (not a quote) | Blank: **no published price for the Access Cap 1130 in costing.md [QUOTE].** costing.md 5a prices other caps (Stock High Profile Cap 1100 and Finn Five Panel Cap 1103, both $30.00 inc / $27.27 ex at 1 to 9; $24.00 / $21.82 at 10 to 49; $18.00 / $16.36 at 50+), and section 8 models the Stock Cap 1100 plus embroidery at $30.32 (25), $23.86 (50), $22.86 (100) ex GST: reference only, a different blank. Embroidery as on sheet 2 (Australianess, per unit, minimum 5). **[QUOTE]**: Access Cap 1130 blank, digitising, cap-file fee, lead time, MOQ |

## 4. Tote bag

Diagram: `flats/merch/tote-bag.png`. Item code `TOTE`.

| Field | Specification |
|---|---|
| Blank | **AS Colour Carrie Tote 1001, Black.** 320 GSM 100% cotton canvas, reinforced shoulder straps, one large main compartment. 420 x 420 mm, gusset 95 mm, strap length 720 mm. https://www.ascolour.com.au/carrie-tote-1001/ |
| Colourway | Bag CHAR (black). Art CREAM (white ink, underbase as needed) and EMBER (PANTONE 485 C). See A2 |
| Decoration | **Screen print**, two spot colours with a cream underbase as needed: canvas takes screen ink well. Small-run alternative: DTF or a heat transfer (Mercha offers a Supacolour heat transfer on this tote) |
| Placement, front | Wordmark box **250 x 74.4 mm**, centred on the bag's centre line. **Top of the art 110 mm below the top edge**, clearing the strap stitch boxes. Confirm the strap-patch depth on the blank [CONFIRM] |
| Placement, back (optional) | FRESH FROM THE OVEN. **250 mm wide** (Bebas, 0.01 em; type 34.9 mm, cap 24.4 mm), centred, **top of cap height 110 mm below the top edge**. Beneath it doughboss.com.au in Barlow 600 at a **6 mm cap height** (type 8.6 mm), cap top 14 mm below the headline baseline (URL baseline 20 mm below it) |
| Minimum line and gap | 0.5 mm printed line; 1.0 mm open gap |
| Thread, ink | DB-CREAM over underbase; DB-EMBER |
| Artwork files | `DB_TOTE_ART_FRONT_ONESIZE_v01_WIP_2026-10-03.pdf`; `DB_TOTE_ART_BACK_ONESIZE_v01_WIP_2026-10-03.pdf`. As the tee: vector PDF/X-4, outlined, named spot swatches |
| Size range and grading | One size. No grading |
| Care label | Keep the blank's label intact; add none. Care per AS Colour page: hand wash cold separately; do not bleach; tumble dry low; iron low heat; line dry in shade; **do not iron over the print** |
| Packing per unit | Folded flat, one tote per polybag, no hang tag. Carton quantity [QUOTE] |
| QC points | Wordmark 250 +/- 2 mm wide; top 110 +/- 2 mm below the top edge; centred +/- 2 mm; level. Border unbroken; counters open; full stop ember; ink opaque and even on the canvas weave; no ghosting or off-register edges; print does not run into the strap patches |
| Sample approval | A6 steps 1 to 7. Step 4: strike-off on the real tote. Step 5: 10 wash cycles at the care above on one sample; reject on cracking, lifting or underbase showing |
| Cost reference (not a quote) | Blank, costing.md 5a (AS Colour page, 3 Oct 2026): Carrie Tote 1001 $20.00 inc / $18.18 ex (1 to 9); $16.01 / $14.55 (10 to 49); $14.00 / $12.73 (50+). Model, section 8 (ex GST, blank plus **1-colour** screen print, before setup, screens and freight): $20.54 (25), $17.36 (50), $16.90 (100) [CONFIRM the decorator prints totes at garment rates]. This spec is **2 colours**; the same formula with the published 2-colour print ($7.08 / $5.58 / $5.08 inc GST, which is $6.44 / $5.07 / $4.62 ex) gives $20.99 (25), $17.80 (50), $17.35 (100): derived from published figures, not a quote. A back print is a second location [QUOTE]. A black all-in printed tote from noissue exists in 5d and section 8 at an AUD estimate of $7.96 (25), $6.14 (50), $5.22 (100), excluding freight and import: a different product, indicative only. **[QUOTE]**: second location, underbase, screens and setup, freight, lead time, MOQ |

## 5. Takeaway paper bag (counter orders)

Diagram: `flats/merch/takeaway-bag.png`. Item code `BAG`.

| Field | Specification |
|---|---|
| Blank | **Detpak Small Paper Twist Handle Bag, code C400S0029**, black range, matte. Listed at 280 x 280 x 150 mm; **which figure is the gusset is not clear on the listing [CONFIRM with Detpak]**. Paper twisted handles, reinforced. Listed as recyclable and compostable with no added PFAS, suitable for hot contents and mild grease (supplier claims; we make none on the bag). https://www.detpak.com/bags/small-paper-twist-handle-bag/c400s0029 . Larger option: Detpak Large Twist Handle Bag C734S0001A, 305 x 305 x 175 mm, kraft. Same placement rules scaled on its own template [QUOTE, CONFIRM owner's choice] |
| Fit warning | The catering dozen box (working internal size 385 x 290 x 50 mm) **does not fit either bag**. Counter takeaway only. Catering needs its own carry solution [CONFIRM] |
| Colourway | Stock CHAR (black matte). Ink CREAM and EMBER (PANTONE 485 C) on Detpak's colour proof. **[CONFIRM with Detpak that opaque cream or white ink on black is possible and how opaque.]** Fallback if not: brown kraft bag, CHAR wordmark with the EMBER full stop (merch.md; the all-char mono variant in brand-identity.md is for one-colour jobs) |
| Decoration | Bag maker's custom print, up to 4 colours listed by Detpak, MOQ applies [QUOTE]. Process (flexo or digital) [CONFIRM with Detpak]. Outside print only |
| Placement, front | Wordmark box **140 x 41.7 mm**, centred horizontally on the front face. **Top of the box 60 mm below the top-fold line.** Final position follows Detpak's print template for the bag (handle patches and folds) |
| Placement, back (optional) | FRESH FROM THE OVEN. **180 mm wide** (Bebas, 0.01 em; type 25.1 mm, cap 17.6 mm), centred, **top of cap height 60 mm below the top-fold line**. Beneath it doughboss.com.au then @doughboss in Barlow 600 at a **7 mm cap height** (type 10 mm): first line's cap top 18 mm below the headline baseline (its baseline 25 mm below); second line's baseline 15 mm below the first |
| Minimum line | Per Detpak's process. The thinnest line in the design is 2.0 mm (the border), so it is safe |
| Ink | DB-CREAM, DB-EMBER, spot, per A2, approved on Detpak's colour proof |
| Artwork files | `DB_BAG_ART_FRONT_280x280x150_v01_WIP_2026-10-03.pdf` and `..._BACK_...pdf`: vector PDF placed on Detpak's own template, outlined, spot swatches |
| Size range and grading | One size (C400S0029). No grading |
| Care label | None. Food-safety note: print is on the outside only. Food goes in its own wrap or liner, not directly against printed surfaces. [CONFIRM with Detpak whether the ink is suitable for food packaging] |
| Packing per unit | Flat in bundles and cartons as Detpak packs custom bags: bundle and carton quantities [QUOTE]. Cartons marked with item, version and quantity. Stored dry |
| QC points | Wordmark 140 +/- 2 mm wide; top 60 +/- 2 mm below the fold; centred +/- 2 mm; not over a fold or a handle patch. Colour against the approved proof; cream opacity as approved; no ink rub-off on a dry-thumb rub; handles secure; no crushed folds; no odour; dimensions as the blank listing |
| Sample approval | A6 steps 1 to 7. Step 4: printed bag sample from Detpak (white or plain sample first if cost matters). Step 5: carry test with a full counter order, and a rub test on the print |
| Cost reference (not a quote) | **No published price for Detpak in costing.md; MOQ, setup and lead time [QUOTE].** The only published printed paper bag priced in costing.md section 6 is a different one: QIS Packaging coloured paper carry bag (black available), 310 W x 420 H x 110 gusset, twist handle, 1 colour 1 side, print area 180 x 180 mm, **$1.84 each inc GST, minimum 500**, one-off setup cost not on the page [QUOTE] (read 3 Oct 2026). Reference only |

## 6. Sticker sheet

Diagram: `flats/merch/sticker-sheet.png`. Item code `STICKER`.

| Field | Specification |
|---|---|
| Format | **A5 sheet, 148 x 210 mm, kiss-cut** stickers on one backing. Kiss-cut paths only: no die-cut through the backing except the sheet border |
| Stock | White permanent vinyl, printed full colour digitally with a **CHAR flood** (the char gives the black-base look without white ink), **matt laminate** over all. Why: lasts on laptops and bottles; the laminate stops scuffing |
| Colourway | CHAR flood (#0a0807, matched on the proof [CONFIRM black reference]); CREAM as the paper-white of the artwork reading warm: set cream to the A2 reading, not pure white; EMBER 485 C. Ember is the hardest to hold in CMYK: ask for a Pantone-matched digital press, or approve the closest match on the proof |
| Decoration | Digital print, one pass, with the flood. Minimum line 0.25 mm (house rule); the smallest border on the sheet is 0.52 mm |
| Contents (verified copy only) | 1 x wordmark sticker (box 80 mm wide); 2 x wordmark stickers (box 45 mm wide); 1 x FRESH FROM THE OVEN. strip, 120 x 20 mm; 1 x FEED THE WHOLE TABLE. strip, 120 x 20 mm; 6 x plain ember dot circles, 25 mm diameter, no text; 1 x round sticker, 50 mm diameter, with the wordmark (36 mm box) and @doughboss. Nothing else. [CONFIRM: ember fill at 25 mm, see A7 item 2] |
| Sticker shapes | Wordmark stickers: char rounded rectangle, the die edge sits one wordmark cap height outside the box (the clear space): 80 mm box on a **97.9 x 41.7 mm** sticker (corner radius 3 mm); 45 mm box on a **55.1 x 23.5 mm** sticker (radius 2 mm). Strips 120 x 20 mm (radius 2 mm), type 13.9 mm (cap 9.7 mm), text about 100 to 104 mm wide, centred, ember full stop. Round: 50 mm char circle, wordmark 36 x 10.7 mm centred 4 mm above the centre, @doughboss in Barlow 600 4.2 mm type, tracking 0.10 em, centred, baseline 14.5 mm below the centre. Dots: 25 mm ember circles |
| Layout (top-left of each sticker from the sheet's top-left; x, y, w x h) | Wordmark 80: 4.0, 6.0, 97.9 x 41.7. Dot: 105.9, 14.4, 25. Strip A: 14.0, 53.7, 120 x 20. Strip B: 14.0, 79.7, 120 x 20. Round: 4.0, 107.7, 50. Wordmark 45 (first): 58.0, 107.2, 55.1 x 23.5. Wordmark 45 (second): 58.0, 134.7, 55.1 x 23.5. Dot: 117.1, 105.7, 25. Dot: 117.1, 134.7, 25. Dots: 32.5, 61.5 and 90.5 at y 165.7, 25 each. Lowest edge 190.7 mm; 4 mm clear from the sheet edge. Gaps: 4 mm between stickers, 6 mm between rows |
| Bleed and safe | 2 mm bleed past each cut line (flood and ember extend 2 mm; the 4 mm gap holds two bleeds without overlap); keep copy 2 mm inside the cut |
| Files | `DB_STICKER_ART_A5_v01_WIP_2026-10-03.pdf`: one vector PDF. Separate cut layer: kiss-cut paths as one named spot swatch (you name it; commonly "CutContour"). Fonts outlined |
| Size range and grading | One sheet size. No grading |
| Care label | None. Use rule: stickers never touch food or the inside of a liner |
| Packing per unit | Sheets flat, not rolled, each in a clear sleeve [proposal: confirm cost], in rigid or flat cartons. Sheets per carton [QUOTE] |
| QC points | Sheet 148 x 210 +/- 1 mm [proposal]. Kiss-cut depth: cuts through the vinyl and laminate but not the backing, on every sticker; no stickers lifting or fused. Cut position within +/- 0.5 mm of the artwork so no white vinyl edge shows [proposal, CONFIRM]. Colour against the proof; ember reads ember; char reads black not grey; no banding on the flood. Laminate bubble-free. Wordmark border unbroken |
| Sample approval | A6 steps 1 to 7. Step 3: press proof; step 4: a printed, cut sheet. Step 5: stick one to a laptop lid and a water bottle, scuff and wipe test, one week |
| Cost reference (not a quote) | **No published price for an A5 kiss-cut sticker sheet in costing.md [QUOTE]** for price, MOQ and lead time. Reference only, a different product: costing.md 4 lists round paper stickers at 60 mm and 75 mm (for example Supr Pack 60 mm kraft, 250 $99 to 2,000 $249, tax included, 3 Oct 2026) |

## 7. Counter tent card

Diagram: `flats/merch/tent-card.png`. Item code `TENT`.

| Field | Specification |
|---|---|
| Format | A-frame tent, two A6 faces (105 x 148 mm each). Flat size **105 x 296 mm**, scored at 148 mm. Optional 20 mm glue-in base tab for stability [proposal; CONFIRM]. Face 2 is printed rotated 180 degrees on the flat so it reads upright when standing: the fold is the top of both faces |
| Stock | Recommended: **350 gsm coated board, CHAR flood, matt laminate both sides**: a bakery counter gets flour and grease, and a laminated card wipes clean. Premium alternative: G.F Smith Colorplan Ebony 350 gsm (uncoated black; Ball & Doggett, Australia) with white toner or foil; **[CONFIRM the printer has white toner or foil]**; not wipeable, so for catering presentations not the counter. https://www.ballanddoggett.com.au/brands/colorplan/ |
| Colourway | CHAR flood; CREAM (white ink or paper-white reading warm); EMBER 485 C (CMYK press proof, or Pantone-matched digital). See A2 |
| Decoration | Digital or offset full colour, CHAR flood, matt laminate. Score on a non-printing layer |
| Face 1 (customer side) | Distances are from the fold edge down. Wordmark **60 x 17.9 mm**, centred, top 18 mm. Headline FEED THE / WHOLE TABLE. in Bebas Neue, tracking 0.01 em, centred, ember full stop: type 19.2 mm (cap 13.5 mm), line spacing 19.2 mm, **the longer line WHOLE TABLE. 85 mm wide**, top of the first cap line 56 mm, baselines 69.5 and 88.7 mm. "Catering" Barlow 600 6 mm type, baseline 104 mm. catering@doughboss.com.au Barlow 500 5 mm type, baseline 114 mm. doughboss.com.au Barlow 500 5 mm type, baseline 122 mm. All centred. [CONFIRM the line break] |
| Face 2 | Wordmark as Face 1 (60 mm, top 18 mm). FRESH FROM / THE OVEN. at the same type size and positions as Face 1's headline. "Allergen information" and "available on request." on two lines, Barlow 500 **5 mm type (x-height 2.5 mm, an accessibility choice)**, baselines 104 and 110.5 mm. @doughboss Barlow 600 5 mm type, baseline 122 mm. All centred |
| Do not print | Package prices, dozen counts, package contents. The site's prices are indicative and counts may change. Send customers to the website |
| Files | `DB_TENT_ART_105x296_v01_WIP_2026-10-03.pdf`: vector PDF/X-4, **3 mm bleed, 4 mm safe area**, outlined fonts. Score line on a separate non-printing layer. Print the flat as one sheet with Face 2 rotated |
| Size range and grading | One size. No grading |
| Care label | None. Placement: one per till, front face to the queue. Replace when the laminate scuffs |
| Packing per unit | Flat, pre-scored, in bundles, cartons marked with item and version. Bundle and carton quantities [QUOTE] |
| QC points | Flat 105 x 296 +/- 1 mm [proposal]. Score centred at 148 +/- 1 mm and folds without cracking the board. Both faces upright when standing. Wordmark border unbroken. Flood solid, no streaks. Laminate smooth, no bubbles, no edge lift. Colour against the proof. No printing within the 4 mm safe area edge |
| Sample approval | A6 steps 1 to 7. Step 4: printed, laminated, scored sample. Step 5: wipe test with a damp cloth and a greasy fingerprint; fold and stand test over a counter shift |
| Cost reference | **No published price in costing.md [QUOTE]** for price, MOQ or lead time |

## 8. Window decal (shopfront)

Diagram: `flats/merch/window-decal.png`. Item code `DECAL`. This sheet goes to the sign installer, not a garment factory.

| Field | Specification |
|---|---|
| Material | **Cut vinyl, cast film, ORACAL 751C class** (rated about 8 years for black and white, 7 for colours, against 6 for calendered 651; does not shrink in sun the way 651 can: Sign Warehouse). Cream: nearest stock vinyl to #eee8de, checked against the drawdown; if no stock colour passes, print onto white cast vinyl with a laminate and cut it. Ember: the stock red closest to 485 C. All colours per A2. Application tape as the installer advises |
| Colourway | CREAM, EMBER, and a CHAR backing layer (below) |
| Application | **Second surface (inside the glass), reverse-cut**, so it reads correctly from the street and is protected from weather and scraping. **Add a CHAR layer behind the cream elements**, cut to the same shapes and registered to them, so the inside view is black not the back of the vinyl [CONFIRM: alternative is one char panel behind the whole wordmark; owner's choice]. If there is tint film or an inside-access problem, apply first surface instead [CONFIRM per shop] |
| Artwork stack (as seen from the street) | **Wordmark 600 x 178.6 mm** (border 8.7 mm, cap height 67.1 mm). Below it, **clear space 67 mm** (one cap height), then FRESH FROM THE OVEN. **300 mm wide** (Bebas, tracking 0.01 em; type 41.9 mm, cap height 29.3 mm), ember full stop. Then 30 mm, then doughboss.com.au in Barlow 600 at a **15 mm cap height**. Optional shop line (for example REVESBY, Barlow Condensed 600, tracking 0.14 em) at 15 mm cap height, 20 mm below the URL. **No hours, phone numbers or prices** [hours CONFIRM; if wanted, a separate replaceable panel] |
| Heights above finished floor (proposal; adjust on site) | Wordmark centre 1500 mm: top 1589.3, bottom 1410.7. FRESH line cap top 1343.6, baseline 1314.3. URL cap top 1284.3, baseline 1269.3. Shop line cap top 1249.3, baseline 1234.3 |
| Minimum detail | 1.5 mm line and 1.0 mm gap for plotter-cut vinyl. Every element here is far above that (the thinnest are the Barlow letter strokes of the URL at 15 mm cap height, roughly 1.5 to 2 mm, so check them and the counters of a, e and o on a test cut) |
| Placement | Measure each glazing panel first [CONFIRM: panel sizes for Revesby, Bankstown and Roselands]. Working proposal: centred on the main panel beside the door, centre of the wordmark at about 1500 mm above finished floor, adjusted on site to sightlines. At least 100 mm from frames and mullions. Do not cover or remove any existing glass-safety markings [CONFIRM: what is on site] |
| Approvals | **Roselands Centro: centre management's design criteria and approval before installation [CONFIRM].** Strip shops: landlord consent and whether the local council has signage controls [CONFIRM] |
| Files | `DB_DECAL_ART_600x179_v01_WIP_2026-10-03.pdf` (as seen from the street) and `..._MIRROR_...pdf` (supplied mirrored for second-surface work). Vector PDF or AI: closed paths only, no strokes (convert the border to a filled shape), fonts outlined, one layer per vinyl colour including the char layer. Installer's sign-off proof at 1:10 on a photo of the actual shopfront |
| Size range and grading | One wordmark size per glazing panel; if a panel is too small, the next rule is a smaller wordmark no less than 110 mm wide, never a thicker border |
| Care | Clean the glass gently. Keep scrapers and blades away from the vinyl edges. Inspect the edges every 6 months (house rule). Replace anything lifting |
| Packing per unit | Weeded and masked on transfer tape, rolled on a tube (not folded), with a 1:10 layout guide. [proposal; CONFIRM with the installer] |
| QC points | Cut vinyl matches the proof size +/- 2 mm. Clean cuts, no tearing at the box corners or in the counters of B, O and S; no bubbles or wrinkles after application; register of the char layer to the cream within 1 mm [proposal]; full stop ember and round; level against a spirit level; centred as specified; edges sealed. Glass clean before and after |
| Sample approval | A6 steps 1 to 7. Step 3: vinyl chips against A2. Step 4: a test cut on the actual vinyl, then a 1:10 proof on a photo of the shopfront. Step 5: a **1-week trial panel** if timing allows. Site approvals (above) before any install |
| Cost reference | **No published price in costing.md [QUOTE]** for supply, install, MOQ or lead time |

## 9. Seal label (printed consumable)

Diagram: `flats/merch/seal-label.png`. Item code `SEAL`. This label sits on the outside of the box and does not touch food.

| Field | Specification |
|---|---|
| Item and use | Round label across the lid front and front wall of the catering dozen box, centred at x = 322 mm +/- 11 mm. Filled in by hand with the shop's marker. Its tear-on-opening behaviour is a property of the stock, not a claim to print |
| Blank | **70 mm round, die cut, natural uncoated kraft paper face, permanent adhesive suitable for corrugated.** No supplier read on 3 Oct 2026 publishes a 70 mm kraft round (nearest published are 60 mm kraft and 75 mm white paper) |
| Colourway | Natural kraft face. Ink CHAR (text, rules, tick boxes) and EMBER 485 C (ring). Uncoated kraft takes no white ink at digital quantities: no cream is used. See A2 |
| Decoration | Digital or flexo print, 2 colours (char and ember). Process [QUOTE; CONFIRM which the label supplier offers] |
| Artwork (centre of the label is the origin; distances in mm) | Ember ring: 1.2 mm stroke, centreline 2.4 mm inside the edge (radius 32.6). Ink CHAR. **FOR** baseline 14.5 mm above centre, rule from after the label to x = +21; **DATE** baseline 6.0 mm above centre, rule to x = +9.5; tick row 1 (CHEESE, ZA'ATAR) baseline 8.5 below centre; row 2 (MEAT, SPINACH) 15.0 below; **BOX __ OF __** baseline 22.0 below. Left edge of the text block 21 mm left of centre (tick boxes and ZA'ATAR column start 3 mm right of centre). Barlow Condensed 600, 4.0 to 4.2 mm type, tracking 0.10 to 0.12 em; tick boxes 3.0 mm; rules 0.3 mm. **An 8 mm band across the diameter (4 mm each side of the centre) carries no print**: that is where the label bends over the lid edge. No wordmark on the seal |
| Files | `DB_SEAL_ART_70_v01_WIP_2026-10-03.pdf`: vector, outlined, spot swatches DB-CHAR and DB-EMBER. Die line as a separate spot (the printer names it). Size field in the name is the diameter |
| Size and grading | 70 mm only. No grading |
| Care label | None. Not for food contact |
| Packing per unit | Rolls or sheets [CONFIRM: roll or sheet form; Gift Packaging publishes A4 sheets of 24 at 60 mm]. Labels per roll or pack [QUOTE]. Stored dry, sealed, off the floor |
| QC points | Diameter 70 +/- 0.5 mm [proposal]. Ring concentric within 0.5 mm. Text on the 8 mm band clear. Type legible, tick boxes square, rules continuous. Ember ring reads ember on kraft. Adhesive sticks to corrugated board after 30 minutes hot, no lift at the fold (PDS test). Marker writes without feathering or smudge on the uncoated face |
| Sample approval | A6 steps 1 to 7. Step 3: proof on the real kraft (colour shifts on kraft). Step 4: a cut label on a real box. Step 5: apply across the fold and hold 30 minutes hot, then write the fields in marker |
| Cost reference (not a quote) | **70 mm kraft round: [QUOTE]** (costing.md section 4, 3 Oct 2026). Nearest published, 60 mm kraft: Supr Pack, tax included, 250 $99; 500 $129; 1,000 $169; 2,000 $249; Sydney and Melbourne within 2 weeks; custom size by contact. Gift Packaging, 60 mm kraft circles on A4 sheets (24 per sheet), 1,200+ at $0.20 each inc GST ($0.18 ex), ordered in lots of 12; **out of stock when read**. Reference only: sizes, MOQ and lead time for 70 mm are [QUOTE] |

## 10. Greaseproof liner (printed consumable)

Diagram: `flats/merch/liner.png`. Item code `LINER`. **Food-contact item.** The food-safety lead signs this sheet. [CONFIRM: named person]

| Field | Specification |
|---|---|
| Item and use | One sheet lies in each catering dozen box under the bakes. Plain is the safe fallback; printed is a decision [CONFIRM: printed or plain] |
| Stock | **Greaseproof (or glassine-type) paper, 40 to 50 gsm**; grease resistance KIT 6 or above, target 7 to 8 (TAPPI T 559); **no intentionally added PFAS, total fluorine below 100 ppm** (test report supplied). Declaration of compliance for the paper, and for any ink if printed [required, pds.md] |
| Size | Working size **380 x 285 mm** flat; or about 480 x 385 mm to cup up the walls [CONFIRM by fit sample]. Printer's custom size is [QUOTE] |
| Colourway | Natural white to paper tone (liner paper #f7f5f0). One colour EMBER 485 C. No char, no cream |
| Decoration | Printed, **1 colour ember, on the non-food face only** (the face against the board). Food-packaging ink with a food-safe statement [required]. [CONFIRM which face is non-food on the chosen paper] |
| Artwork | Repeating unboxed text "DOUGH BOSS." in Bebas Neue **8 mm type**, tracking 0.13 em, ember including the full stop, about 6 % coverage. **Grid pitch 60 mm**; alternate rows offset by 30 mm; alternate cells turned **35 degrees and 215 degrees** so the sheet reads from any side. Pattern is clipped at the sheet edge. Letter strokes about 0.8 mm: above the 0.25 mm digital minimum [CONFIRM the printer's process minimum]. The repeat is shown in the diagram |
| Brand note | The pattern text is unboxed. brand-identity.md says never drop the box from the wordmark; this is pattern, not logo use. [CONFIRM: brand lead, see A7 item 6] |
| Files | `DB_LINER_ART_380x285_v01_WIP_2026-10-03.pdf`: vector, outlined, one spot swatch DB-EMBER. Repeat unit supplied as a pattern swatch on request |
| Size and grading | One size. No grading |
| Care label | None. Handle liners by the edges. Do not fold over the vents. Packs sealed on arrival; stored sealed, dry, off the floor, away from the oven and wash area; first in, first out |
| Packing per unit | Sheets in sealed packs, packs in cartons. Sheets per pack and per carton [QUOTE]. Pack marked with item, version and batch. Batch label kept until the stock is used |
| QC points | Size 380 x 285 +/- 2 mm. Weight 40 to 50 gsm on the supplier's certificate. Strike-through test: oiliest bake (meat or cheese), 30 minutes hot, no grease through to the board. Print on the non-food face only; no ink transfer to the bake on a rub test; ember reads ember; no odour. Declaration of compliance, test report (KIT, total fluorine) and ink statement on file. Incoming quarantine until the pass |
| Sample approval | A6 steps 1 to 7, with the food-safety lead signing steps 4 to 6. Step 4: a printed sample at final size in a real box. Step 5: the liner strike-through test and a **30-minute hot hold** with a stack of 5 boxes (PDS performance tests) |
| Cost reference (not a quote) | costing.md 3a (3 Oct 2026, GST not stated by the suppliers): Top Shelf Concepts custom printed food paper, **38 gsm**, food-safe inks, 1 to 3 colours, **300 x 400 mm**: 1,000 $705.00; 2,500 $840.00; 5,000 $1,060.00; 10,000 $1,540.00; minimum 1,000; "2-3 weeks plus shipping". noissue custom foodsafe paper, 38 gsm, 380 x 380 mm, 1 colour, **AUD estimates** from USD at 0.6933, excluding freight, import GST and duty: 250 A$228.99; 500 A$250.80; 1,000 A$331.80; 2,000 A$514.06; 5,000 A$962.70. Bio Supply greaseproof 35 gsm, "from $330 per 1,000 ex GST" for the smallest cut; the 300 x 400 cut was not captured [QUOTE]. **None of these match the 40 to 50 gsm weight, and none the 380 x 285 mm size**: custom size and weight [QUOTE]. A plain greaseproof sheet from ATpack (400 x 330 mm, 800 pcs $18.50, GST not stated) is the plain fallback; the PDS owns the final size |

---

## B. Open items that block printing (summary)

| # | Item | Owner | Sheets |
|---|---|---|---|
| 1 | Headline tracking 0.01 em or 0.13 em (A7 item 1) | Brand lead | 1, 2, 4, 5, 8 |
| 2 | Cream Pantone number | Brand lead | All |
| 3 | Ember at 25 mm on the dot stickers | Brand lead | 6 |
| 4 | Back option, and 280 mm back width and 110 mm front offset for 2XL to 5XL | Owner | 1 |
| 5 | Prices, MOQ, lead time, setup, digitising, freight: every [QUOTE] above | Suppliers | All |
| 6 | Blanks: Carrie Apron 1082 and Access Cap 1130 have no published price; costing models other blanks | Owner and decorator | 2, 3 |
| 7 | Overseas: free-issue AS Colour blanks or an equivalent | Owner | 1 to 4 |
| 8 | Detpak: gusset figure, cream ink on black, ink food suitability, process | Detpak | 5 |
| 9 | Site approvals and panel sizes | Owner, centre management, landlords | 8 |
| 10 | Liner: printed or plain, size, weight, which face is non-food, food-safety sign-off | Food-safety lead | 10 |
| 11 | Seal: 70 mm kraft supplier, roll or sheet, marker and adhesive | Brand custodian | 9 |
| 12 | Care-label compliance of the blanks | Decorator or AS Colour | 1 to 4 |

## Sources (read 3 Oct 2026)

Blank pages and decorator, ink and process sources are as listed at the end of `sections/merch.md`. Published prices are as recorded in `sections/costing.md` sections 3a, 4, 5a to 5d, 6 and 8 on 3 Oct 2026; they must be re-quoted before ordering. Pack construction, seal and liner: `sections/packaging.md` and `sections/pds.md` (DB-PDS-CAT-001 revision A, draft).
