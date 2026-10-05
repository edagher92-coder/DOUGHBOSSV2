# Merchandise and brand application: production specifications

Status: working spec for quoting and proofing. All blanks were checked on the supplier's own product page on 3 Oct 2026 (sources at the end). No prices are given here; costing is handled in the costing section. `[CONFIRM: ...]` means an open fact that must be closed before anything goes to print.

## 0. Rules that apply to every item

### 0.1 Copy that may be printed (verified on the live site only)

| Use | Exact copy | Notes |
|---|---|---|
| Logo | DOUGH BOSS. | The wordmark, rebuilt from the site CSS. Never redrawn, retyped or AI-generated. [CONFIRM with the owner that this is the shop's logo.] |
| Line A | FRESH FROM THE OVEN. | Verified. |
| Line B | FEED THE WHOLE TABLE. | Verified. |
| Line C | OVEN-BAKED | Verified. |
| Line D | A CONTEMPORARY LEBANESE BAKERY. | Verified. |
| Line E | THREE SHOPS BAKING DAILY | Verified, but time-sensitive: it becomes false if a shop opens or closes. Keep it off long-life items (decals, embroidery). |
| Places | REVESBY · BANKSTOWN · ROSELANDS | Verified. "Roselands Centro" is the full site name. |
| Contact | doughboss.com.au · @doughboss · catering@doughboss.com.au · orders@doughboss.com.au | Verified. **No phone number on any item.** |
| Allergen line | Allergen information available on request. | The safe FSANZ-aligned line from the brief. Counter and catering items only. |
| Heritage | Since 2009 / "Oven-baked in Sydney since 2009" | On the site, but **[CONFIRM before printing]**: a permanent run makes it a fixed claim. Not used in the specs below. |

**Never print:** "Minis"; "halal", "gluten free", "authentic", "best" or "allergen-free"; any price, quantity, capacity, certification, health claim or origin claim. This includes "Australian cotton": AS Colour says some 5001 colours use Australian combed cotton, but the merch must not make that claim. No hours, phone numbers or dates go on long-life items.

### 0.2 Colourway

| Token | Hex | Role on merch | Pantone |
|---|---|---|---|
| char (base) | #0a0807 | The garment or stock colour is black. Char is used as ink only on light grounds, such as a flood on white sticker vinyl. | Black blank: no ink. Flood: PMS per PDS |
| cream (ink) | #eee8de | Main ink, thread and vinyl: wordmark, box outline and headlines | PMS: per PDS |
| ember (accent) | #e2231a | **Only** the wordmark's full stop and the full stop that ends a headline. Never body text, outlines or large fills. | PMS: per PDS |

Thread and vinyl are matched to the PDS Pantone references. The decorator nominates a thread or vinyl code, and an approved swatch goes on the job sheet.

### 0.3 Wordmark geometry (measured from the pack's vector build, `art.py`, which follows the site CSS at 22 px: padding 8/8/7/11, border 2, tracking .13em)

| Property | Ratio | Consequence |
|---|---|---|
| Width : height of the outlined box | 3.36 : 1 | 90 mm wide = 26.8 mm high |
| Box border stroke | 1.45 % of the box width | 90 mm wide = 1.31 mm border |
| Letter cap height (approx., Bebas Neue at 0.70 em) | 11.2 % of the box width | 90 mm wide = 10.1 mm cap height |
| Ember full stop | about 0.19 em wide | At 90 mm wide it is about 2.7 mm |

The box border is the thinnest element, so it sets the minimum size for every process.

| Process | Thinnest line the process holds (source) | Smallest wordmark width allowed (border at that weight) |
|---|---|---|
| Embroidery | 0.05 in = 1.27 mm minimum line; 0.25 in = 6.35 mm minimum letter height (Printful) | **90 mm** (border 1.31 mm, cap height 10.1 mm) |
| Screen print and DTF on fabric | 0.012 in = 0.3 mm printed line; 0.04 in = 1.0 mm open gap (Transfer Express). House rule: 0.5 mm, because cream sits on a white underbase on black. | **45 mm** (border 0.65 mm). Check the counters of B, O and S on the proof. |
| Digital or offset print on paper or vinyl stickers | House rule 0.25 mm | **25 mm** (border 0.36 mm) |
| Cut vinyl (plotter) | 1.5 mm minimum detail; 1 mm minimum gap (Spreadshop vector guide) | **110 mm** (border 1.6 mm) |

Do not thicken the border to sneak under these minimums. Below the minimum for a process, use a larger size or a different process.

**Clear space (proposal for owner approval):** keep a margin of at least one wordmark cap height on all sides, measured from the outside of the box.

---

## 1. Staff t-shirt

| Field | Specification |
|---|---|
| Blank (primary) | **AS Colour Staple Tee 5001**, Black. Mid weight 180 GSM, 28-singles, 100% combed cotton; neck ribbing, side seamed, shoulder-to-shoulder tape, double needle hems, preshrunk. Sizes XSM–5XL. AS Colour lists it as suited to screen print, DTG and embroidery. https://www.ascolour.com.au/staple-tee-5001/ |
| Blank (alternative, retail or heavier) | **AS Colour Classic Tee 5026**, Black. Heavy weight 220 GSM, 22-singles, 100% combed cotton. Sizes SML–5XL. https://www.ascolour.com.au/classic-tee-5026/ We recommend 5001 for staff because shops run hot ovens; offer 5026 as the retail tee. [CONFIRM the owner's choice] |
| Front | Wordmark in cream, ember full stop, on the left chest. Two spot colours. |
| Back (choose one) | **Option 1, one line:** FEED THE WHOLE TABLE. in Bebas Neue caps, tracking .13em, cream with an ember full stop. **Option 2, stacked** (the owner's "stacked" direction): FEED THE / WHOLE TABLE., left-aligned. **Option 3:** FRESH FROM THE OVEN. on one line. One back line per run: do not mix lines within a uniform set. |
| Print method | **Screen print** (plastisol or water-based, as the printer advises for opaque cream on black). This needs a white underbase under the cream on a dark garment (PIA/printing.org on underbases). Why: a two-spot job repeated across a uniform set is screen printing's strength, and the colour is consistent across reorders. **DTF** is the fallback for small top-ups (new starters, one or two sizes). It needs no screens; the trade-off is a different hand-feel and sheen next to screen-printed shirts, so do not mix the two methods within one shift's uniforms. |
| Front artwork size | Wordmark **90 x 26.8 mm**, the same on all sizes. That is inside the left-chest norm of 3–4 in (76–102 mm) wide. Border 1.31 mm. |
| Front placement | Top of the box **80 mm below the front neck seam** (the norm is 3–4 in, 76–102 mm, below the collar). Centre the art horizontally between centre front and the wearer's left side seam (Vistaprint guide). Measure on size MED. On XSM and 5XL, check the art does not run past the armhole. |
| Back artwork size | Option 1: **250 mm wide**, cap height about 17.8 mm (Bebas Neue at 25.4 mm). Option 2: **250 mm wide** on the longer line WHOLE TABLE., both lines at 43.6 mm type (cap height about 30.5 mm), leading 1.1 em, block about 78 mm high. Option 3: 250 mm wide, cap height about 18.5 mm. The upper-back norm is 8–10 in (203–254 mm) wide. For 5XL, the printer may supply a 280 mm version [CONFIRM]. |
| Back placement | Top of the art **90 mm below the back neck seam** (the norm is 3–4 in, 76–102 mm). Centred on centre back. |
| Minimum line / gap | 0.5 mm printed line; 1.0 mm open gap. |
| Pantone | Cream: PMS per PDS. Ember: PMS per PDS. Ink drawdowns go on the job sheet. |
| Files to supply | Vector PDF (PDF/X-4) or AI at 1:1, with fonts converted to outlines. Two named spot swatches, `DB-CREAM` and `DB-EMBER`; no CMYK builds and no gradients. The printer makes the underbase separation. For DTF: a 1:1 PNG at 300 dpi with transparency, as well as the vector. |
| Care (from the AS Colour page) | Machine wash cold with like colours. Do not bleach. Do not tumble dry. Do not dry clean. Do not iron if printed. Line dry in shade. Add: wash inside out. A care card goes in each staff pack. |
| Durability acceptance | DTF sellers say their transfers last 50+ washes (Ninja Transfers, Transfer Kingdom); treat that as a vendor claim. Our own test: 10 cycles following the AS Colour care above on one sample per method. Reject on any cracking, lifting at the full stop or border, or underbase showing through the cream. |

## 2. Bib apron

| Field | Specification |
|---|---|
| Blank | **AS Colour Carrie Apron 1082**, Black. Heavy weight 320 GSM 100% cotton canvas; herringbone cross-over shoulder strap, metal eyelets, front patch pocket, top-stitch detailing, preshrunk. One size: length 90 cm, waist 76 cm, pocket 24 x 46 cm. https://www.ascolour.com.au/carrie-apron-1082/ |
| Decoration | Wordmark on the bib, centred. Optional: Line A or B embroidered on the pocket, within the pocket's 46 cm width. |
| Method | **Embroidery.** Why: it stays crisp on heavy canvas, has no print to crack where the apron flexes, and suits an apron that is wiped and rubbed. AS Colour also lists the apron for screen print and DTG. At least one decorator (Mercha) offers embroidery only on this apron, and the eyelets and straps make the bib awkward to print. |
| Artwork size | Wordmark **100 x 29.8 mm** (border 1.45 mm, cap height 11.2 mm). Pocket line (optional): **120 mm wide**, cap height about 8.5–8.9 mm, which is above the 6.35 mm minimum. |
| Placement | Top of the box **60 mm below the bib's top hem**, centred on the bib's centre line. Keep at least 15 mm from the eyelets and the strap stitching (proposal; confirm on the sew-out). Pocket line: centred, 30 mm below the pocket's top hem. |
| Stitch notes | Box border: satin column at 1.45 mm. Letters: satin or tatami fill, as the digitiser judges for 11 mm caps. Ember full stop: a small satin or fill element of about 2.9 mm. Ask for a stabilising underlay on canvas. Cream thread and ember thread are matched to the PDS Pantone references. Two thread colours. The digitiser chooses the backing for 320 GSM canvas. |
| Files | Master vector (PDF or AI, outlined). The digitiser returns a machine file (for example DST) and a stitch-out proof. Dough Boss keeps the digitised file for reorders. |
| Care (AS Colour page) | Hand wash cold separately. Do not bleach. Do not tumble dry. Iron medium heat. Line dry in shade. **[CONFIRM the shop's laundering routine:** if aprons go through a commercial wash, trial one apron through that cycle before ordering in bulk.] |

## 3. Cap

| Field | Specification |
|---|---|
| Blank | **AS Colour Access Cap 1130**, Black. Light weight 100% cotton, six-panel, low profile, curved peak, adjustable fastener with metal clasp, tonal under-peak lining, one size. https://www.ascolour.com.au/access-cap-1130/ AS Colour lists it as suited to embroidery and heat press. |
| Method | **Embroidery** on the front panels. Why: it suits the blank and lasts. Heat press is not recommended, because the cap is spot-clean only and flour must be brushed off it. |
| Artwork size | Wordmark **90 x 26.8 mm**, which is the embroidery minimum. Printful's front-logo guidance is 4–5 in (102–127 mm) wide and up to 2.25 in (57 mm) high, and low-profile caps take a lower height, so 26.8 mm leaves room. |
| Placement | Centred across the centre front seam. The bottom of the box sits **15 mm above the peak seam**: Printful advises a 0.5–1 in margin, and we take the low end because the design is short. Confirm on the sew-out that it sits evenly over the seam. |
| Stitch notes | Box border: satin at 1.31 mm. Letters at 10.1 mm cap height (minimum 6.35 mm). Ask the digitiser to sew from the centre outward to limit puckering on the seam. Keep it under the decorator's cap stitch limit (Printful quotes about 15,000). Two thread colours. |
| Files | Master vector. The digitiser produces the cap file (a cap file is separate from the apron file, even at the same size). |
| Care (AS Colour page) | Spot clean only. Do not bleach. Do not tumble dry. Issue two caps per staff member if they must be worn daily. [CONFIRM whether caps form part of the shop's food-safety hair-restraint practice.] |

## 4. Tote bag

| Field | Specification |
|---|---|
| Blank | **AS Colour Carrie Tote 1001**, Black. Heavy weight 320 GSM 100% cotton canvas, reinforced shoulder straps, one large main compartment. 42 x 42 cm, gusset 9.5 cm, strap length 72 cm. AS Colour lists it as suited to screen print, DTG and embroidery. https://www.ascolour.com.au/carrie-tote-1001/ |
| Front | Wordmark, cream with an ember full stop. |
| Back (optional) | FRESH FROM THE OVEN. on one line, with doughboss.com.au beneath it in Barlow. |
| Method | **Screen print** with a cream underbase as needed. Why: canvas takes screen ink well, it is two spot colours, and it suits a retail or giveaway run. DTF or a heat transfer is the small-run alternative (Mercha offers a Supacolour heat transfer on this tote). |
| Artwork size | Front wordmark **250 x 74.4 mm** (border 3.6 mm). Back line **250 mm wide**, cap height about 18.5 mm. URL at a 6 mm cap height. |
| Placement | Top of the art **110 mm below the top edge**, centred, so it clears the strap stitch boxes and sits mid-chest when carried on the shoulder. Confirm the strap-patch depth on the blank. |
| Minimum line / gap | 0.5 mm / 1.0 mm. |
| Files | As for the tee: vector PDF/X-4, outlined, `DB-CREAM` and `DB-EMBER` spot swatches. |
| Care (AS Colour page) | Hand wash cold separately. Do not bleach. Tumble dry low. Iron low heat. Line dry in shade. Do not iron over the print. |

## 5. Takeaway paper bag (counter orders)

| Field | Specification |
|---|---|
| Blank | **Detpak Small Paper Twist Handle Bag, C400S0029**, Black range, matte. Detpak lists it at 280 x 280 x 150 mm [CONFIRM with Detpak which figure is the gusset; the listing's labelling is inconsistent]. Paper twisted handle, reinforced handles. Listed as recyclable and compostable with no added PFAS, and suitable for hot contents and mild grease. https://www.detpak.com/bags/small-paper-twist-handle-bag/c400s0029 Larger option: **Detpak Large Twist Handle Bag C734S0001A**, 305 x 305 x 175 mm, kraft (white or brown variants). https://www.detpak.com/bags/large-twist-handle-bag/c734s0001a/ |
| Fit warning | The catering dozen box (working internal size 385 x 290 x 50 mm) **does not fit either bag**. These bags are for counter takeaway only. Catering orders need their own carry solution. [CONFIRM] |
| Method | Detpak custom print, up to 4 colours, minimum order quantities apply. **[CONFIRM with Detpak** that opaque cream or white ink on the black bag is possible and how opaque it is. If not: brown kraft bag, black wordmark, ember full stop.] |
| Artwork size | Wordmark **140 x 41.7 mm** (border 2.0 mm) on the front face. Optional back face: FRESH FROM THE OVEN. at 180 mm wide, with doughboss.com.au and @doughboss beneath it. |
| Placement | Centred on the face, with the top of the box 60 mm below the top-fold line. Final position follows Detpak's print template for that bag (handle patches and folds). |
| Minimum line | Per Detpak's process. The design's thinnest line is 2.0 mm, so it is safe. |
| Pantone | Cream and ember: PMS per PDS, on Detpak's colour proof. |
| Files | Vector PDF placed on Detpak's own template, outlined, spot swatches. |
| Food-safety note | Printing is on the outside only. Food goes in its own wrap or liner, not directly against printed surfaces. [CONFIRM with Detpak whether the ink is suitable for food packaging.] |

## 6. Coffee or drink cup sleeve: **not recommended (parked)**

The live site shows no coffee, hot drinks or beverages (read 3 Oct 2026). A branded cup sleeve would suggest a drinks offer the shop is not shown to have. Park it. If the owner confirms the shops serve hot drinks [CONFIRM], specify it then from the cup supplier's sleeve dieline: wordmark 60 mm wide in cream on a black sleeve, printed on the supplier's stock, the same files and proof steps as item 5, and no copy beyond the wordmark and doughboss.com.au.

## 7. Sticker sheet

| Field | Specification |
|---|---|
| Format | **A5 sheet (148 x 210 mm), kiss-cut** stickers on one backing. |
| Stock | White permanent vinyl, printed full colour digitally with a **char #0a0807 flood**, with a matt laminate. Why: the stickers last on laptops and bottles, the laminate stops scuffing, and the char flood gives the black-base look without needing white ink. Seal stickers for paper bags: the same art on uncoated paper label stock, which tears on opening (this tamper evidence is a property of the stock, not a claim to print). |
| Contents (only verified copy) | 1 x wordmark, 80 mm wide · 2 x wordmark, 45 mm wide · 1 x "FRESH FROM THE OVEN." strip, 120 x 20 mm · 1 x "FEED THE WHOLE TABLE." strip, 120 x 20 mm · 6 x ember dot circles, 25 mm diameter (no text) · 1 x round sticker, 50 mm diameter, with the wordmark and "@doughboss". |
| Minimum line | 0.25 mm (house rule). The smallest border here is 0.65 mm (on the 45 mm wordmark), so it is safe. |
| Bleed / safe | 2 mm bleed past each cut line; keep copy 2 mm inside the cut. |
| Pantone | Ember and cream: PMS per PDS, reproduced in CMYK with a press proof. Ember is the hardest to hold in CMYK, so ask for a Pantone-matched digital press or check the closest match on the proof. |
| Files | Vector PDF. A separate cut layer holds the kiss-cut paths as one named spot swatch (the printer names it; commonly "CutContour"). Kiss-cut paths only: no die-cut through the backing except the sheet border. |
| Use rule | Stickers never touch food or the inside of the liner. |

## 8. Counter tent card

| Field | Specification |
|---|---|
| Format | A-frame tent with two A6 faces (105 x 148 mm each). Flat size **105 x 296 mm**, scored at 148 mm. Optional 20 mm glue-in base tab for stability [proposal]. |
| Stock (recommended) | **350 gsm coated board with a char #0a0807 flood and matt laminate** on both sides. Why: a bakery counter gets flour and grease on it, and a laminated card can be wiped clean. |
| Stock (premium alternative) | **G.F Smith Colorplan Ebony 350 gsm** (uncoated black, sold in Australia exclusively by Ball & Doggett) with white toner or foil. [CONFIRM the printer has a white-toner or foil capability.] It cannot be wiped clean, so keep it for catering presentations, not the counter. https://www.ballanddoggett.com.au/brands/colorplan/ |
| Face 1 (customer side) | Wordmark 60 mm wide at the top · headline FEED THE WHOLE TABLE. (Bebas Neue, about 85 mm wide, ember full stop) · "Catering" in Barlow · catering@doughboss.com.au · doughboss.com.au. |
| Face 2 | Wordmark 60 mm wide · FRESH FROM THE OVEN. · "Allergen information available on request." (Barlow, at least 2.5 mm x-height, an accessibility choice) · @doughboss. |
| Do not print | Package prices, dozen counts or package contents. The site's prices are indicative, and counts may change. Send customers to the website. |
| Pantone | Ember and cream: PMS per PDS (CMYK press proof, or Pantone-matched digital). |
| Files | Vector PDF/X-4, 3 mm bleed, 4 mm safe area, outlined fonts. The score line goes on a separate non-printing layer. |
| Placement | One per till, front face to the queue. Replace when the laminate scuffs. |

## 9. Window decal (shopfront)

| Field | Specification |
|---|---|
| Material | **Cut vinyl, cast film (ORACAL 751C class).** Cast is rated longer outdoors than calendered 651 (751C: 8 years for black and white, 7 for colours; 651: 6 years) and does not shrink in sun the way 651 can (Sign Warehouse). Cream: the nearest stock vinyl to #eee8de, checked against the drawdown. If no stock colour passes, print onto white cast vinyl with a laminate and cut it. Ember full stop: the stock red closest to the ember PMS. All colours: PMS per PDS. |
| Application | **Second-surface (on the inside of the glass), reverse-cut,** so it reads correctly from the street and is protected from weather and scraping. Add a char-black backing layer behind the cream so the inside view is clean black, not the back of the vinyl. If there is tint film or an inside-access problem, apply first-surface (outside) instead. [CONFIRM per shop] |
| Artwork | Wordmark **600 x 178.6 mm** (border 8.7 mm, cap height 67 mm). Below it, FRESH FROM THE OVEN. at 300 mm wide (cap height about 22 mm), then doughboss.com.au in Barlow at a 15 mm cap height. Optional location line, for example REVESBY, at a 15 mm cap height. **No hours, phone numbers or prices** [hours CONFIRM, and keep them on a separate replaceable panel if wanted]. |
| Minimum detail | 1.5 mm line and 1 mm gap for plotter-cut vinyl. Every element here is far above that. |
| Placement | Measure each glazing panel first [CONFIRM: panel sizes for Revesby, Bankstown and Roselands]. Working proposal: centred on the main panel beside the door, with the centre of the wordmark at about eye level (about 1500 mm above finished floor; adjust on site to sightlines). At least 100 mm from frames and mullions. Do not cover or remove any existing glass-safety markings [CONFIRM: what is on site]. |
| Approvals | **Roselands Centro: centre management's design criteria and approval are required before installation [CONFIRM].** Strip shops: [CONFIRM landlord consent and whether the local council has signage controls]. |
| Files | Vector PDF or AI: closed paths only, no strokes (convert the border to a filled shape), fonts outlined, one layer per vinyl colour, supplied mirrored for second-surface work **and** unmirrored for the proof. Installer's sign-off proof at 1:10 on a photo of the actual shopfront. |
| Care | Clean the glass gently. Keep scrapers and blades away from the vinyl edges. Inspect edges every 6 months (house rule). |

---

## 10. Approval and proof steps (every item)

| Step | What happens | Who signs | Pass rule |
|---|---|---|---|
| 1. Master art | Artwork is built from the master wordmark vector (rebuilt from the site CSS) and the verified copy list in 0.1 | Brand lead | Wordmark identical to the master. Every word is on the verified list. No banned words. No phone, price or quantity. |
| 2. Digital proof | The decorator or printer returns a proof on their template of the actual blank, with dimensions in mm and placement from the seams | Owner | Sizes and placements match this spec to ±2 mm. Above the process minimum in 0.3. |
| 3. Colour | Ink drawdown, thread swatch, vinyl swatch or press proof, checked against **PMS per PDS** in daylight | Owner + brand lead | Ember reads as ember (not orange, not maroon). Cream reads warm, not pure white. |
| 4. Physical sample | Screen strike-off, embroidery sew-out, printed sticker or tent card, or a vinyl test cut on the actual blank or stock | Owner | Box border unbroken. Letter counters open. Full stop round and in ember. On-garment placement checked on XSM, MED and 5XL. |
| 5. Wear / wash test | Garments: 10 cycles following the AS Colour care for that blank. Cap: spot clean. Card: wipe test. Decal: 1-week trial panel if timing allows. | Brand lead | No cracking, lifting, fading against the swatch, or underbase showing. |
| 6. Pre-production approval | Signed approval of the sample, quantity and size breakdown | Owner | Signed in writing. Nothing runs without it. |
| 7. Delivery check | Check the first-off and a sample from each carton (one per size) against the approved sample | Shop manager | Matches the sample. Reject and return otherwise. |

---

## 11. Brand in the wild: usage rules

### Staff uniforms

| Do | Don't |
|---|---|
| Wear the black tee with the cream wordmark on the left chest, tucked or untucked neatly. One back line per uniform set. | Mix screen-printed and DTF shirts within one shift, or mix back lines within a team. |
| Use the cap and apron with an embroidered wordmark at 90 mm (cap) and 100 mm (apron). | Shrink the embroidered wordmark below 90 mm wide, or thicken its border to fit a smaller space. |
| Keep the ember full stop as the only red on the uniform. | Add red trims, red text, red outlines or other colours to the mark. |
| Replace any garment whose print cracks, fades or shows the underbase. A worn uniform is part of the brand. | Draw, hand-letter or AI-generate the wordmark; stretch it, outline it, add a shadow, or recolour the box. |
| Brush flour off caps and spot-clean them, following the cap's care label. | Machine-wash caps or tumble-dry printed tees (both against AS Colour's care instructions). |
| Use only the verified lines (0.1) on any staff garment. | Put "Minis", "halal", "gluten free", "authentic", "best", "allergen-free", a price, a phone number or "Since 2009" (until confirmed) on a garment. |

### Signage, counter and shopfront

| Do | Don't |
|---|---|
| Use cream on black. On light grounds, use the char (#0a0807) wordmark with the ember full stop. | Place the wordmark on photos or busy patterns without a solid char or cream panel behind it. |
| Keep the wordmark's clear space (one cap height on all sides) clear of other copy, stickers and frames. | Crowd the window with promotional stickers next to the decal. |
| Put changeable facts (hours, specials) on separate replaceable panels. | Cut hours, prices, phone numbers or package counts into long-life vinyl. |
| Put "Allergen information available on request." on the counter card at every till. | Make any allergen-free or dietary claim on any sign. |
| Get centre management approval for Roselands Centro, and landlord or council approval for strip shops, before any install. | Install before the signed proof (step 6) and the site approvals are in hand. |
| Inspect the window decals and counter cards each season; replace anything scuffed or lifting. | Leave peeling vinyl or a stained card in service. |

---

## Sources (read 3 Oct 2026)

- AS Colour Staple Tee 5001: https://www.ascolour.com.au/staple-tee-5001/
- AS Colour Classic Tee 5026: https://www.ascolour.com.au/classic-tee-5026/
- AS Colour Carrie Apron 1082: https://www.ascolour.com.au/carrie-apron-1082/
- AS Colour Access Cap 1130: https://www.ascolour.com.au/access-cap-1130/
- AS Colour Carrie Tote 1001: https://www.ascolour.com.au/carrie-tote-1001/
- Mercha, Carrie Apron decoration (embroidery only at this decorator): https://www.mercha.com.au/products/as-colour-carrie-apron-1082
- Mercha, Carrie Tote decoration (Supacolour heat transfer): https://mercha.com.au/products/as-colour-carrie-tote-bag-1001
- Detpak Small Paper Twist Handle Bag C400S0029: https://www.detpak.com/bags/small-paper-twist-handle-bag/c400s0029
- Detpak Large Twist Handle Bag C734S0001A: https://www.detpak.com/bags/large-twist-handle-bag/c734s0001a/
- Ball & Doggett, Colorplan (exclusive in Australia, 135–350 gsm): https://www.ballanddoggett.com.au/brands/colorplan/
- Printful, embroidered hat guide (front size, minimum line and text, stitch count): https://www.printful.com/uk/blog/custom-embroidered-hats-guide-to-creating-a-design-and-embroidery-file
- Transfer Express, screen-print detail guidelines (0.012 in line, 0.04 in show-through): https://blog.transferexpress.com/tips-for-screen-printing-custom-artwork-tip-4-detail-guidelines/
- Spreadshop, vector and plotter guidelines (1.5 mm detail, 1 mm gap): https://www.spreadshop.com/helpcenter/create-designs/optimize-vector/
- Printing United Alliance (PIA), underbases on dark garments: https://www.printing.org/docs/default-source/default-document-library/journal/07-1-underbases-why-when-how.pdf
- Vistaprint, t-shirt placement guide: https://www.vistaprint.com/hub/t-shirt-design-placement-guide
- Shopify, logo placement on shirts: https://www.shopify.com/blog/logo-placement-on-shirt
- Sign Warehouse, ORACAL 651 vs 751C: https://signwarehouse.com/blogs/content/oracal-651-vs-751c
- DTF wash-life vendor claims: https://ninjatransfers.com/blogs/dtf/how-long-do-dtf-transfers-last and https://transferkingdom.com/blogs/articles/washing-instructions-durability-guide-for-dtf-transfer-printed-t-shirts-and-apparel
- Live site copy and the absence of drinks: https://doughboss.com.au/ (read 3 Oct 2026)
