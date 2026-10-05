# Production documentation, brand governance and expansion

Internal section of the Dough Boss brand and catering pack. Status: DRAFT, 3 Oct 2026.

How to read this section:

- `[CONFIRM: ...]` is an open fact. Nothing marked this way may be printed, quoted to a supplier as fixed, or shown to a customer until it is closed in the register (Part D).
- `[QUOTE]` is a cost that needs a written supplier quote with source and date. No price appears in this section.
- `[X]`, `[N]` and similar are placeholders for figures that must come from a supplier spec, a measured test or the shop's own data.
- The catering box referred to throughout is the working "dozen box" from the pack brief: one-piece corrugated mailer, hinged lid, tuck front, 4 x 3 layout, side vents, black kraft outer, natural kraft inner, 2 spot colours (opaque white and ember red), greaseproof liner, round kraft seal label. All sizes are working sizes `[CONFIRM: fit test with a measured bake]`.

> **[CONFIRM trade mark ownership and registration before any franchise or merch scale-up]**
> This pack gives no view on who owns the "DOUGH BOSS." name or wordmark, or whether it is registered. Ordering a large print run, branded merchandise for sale, signage for a new site, or making any franchise offer all depend on this answer. See C2 and register item G-01.

---

## Part A. Production documentation

### A1. Artwork file standards

Every file sent to a printer or supplier must pass this checklist. Prepress at the printer will check again; this list exists so the file arrives right the first time.

**Colour**

- [ ] Packaging printed on black kraft (box): spot colours only. Two print spots, named exactly `DB Opaque White` and `DB Ember Red`. No CMYK, RGB or hex values in the box file.
- [ ] Spot references for the two inks are set by the printer from an ink drawdown on the actual board `[CONFIRM: spot ink reference for ember #e2231a on black kraft, and white opacity (single or double hit)]`.
- [ ] Items printed in process (seal labels, menus, flyers, some merch): CMYK, with the profile the printer specifies `[CONFIRM: printer's CMYK profile]`.
- [ ] Hex values (char #0a0807, coal #151210, cream #eee8de, ember #e2231a, ember-dark #b71912, gold #f1a132, paper #f7f5f0, mist #b8b1a4) are screen values only. They are the target for matching, never a print instruction.
- [ ] No unused swatches, no registration colour used for artwork, no rich black on a spot job.
- [ ] Overprint and knockout set deliberately: white knocks out of nothing (it is the base); red over white is set as the printer advises for trap `[CONFIRM: trap amount from printer]`.

**Vectors and type**

- [ ] Logo, type and line art are vector. The wordmark is the rebuilt master from `02_Logo/` (see B2), never redrawn, traced or AI-generated.
- [ ] All fonts converted to outlines in the print file (Bebas Neue, Barlow, and the marker face used for handwriting mock-ups). Keep a live-type working file alongside it.
- [ ] Minimum line weight and minimum type size for the board and process `[CONFIRM: printer minimums for flexo or digital on corrugated]`.
- [ ] Reversed (white on black) type checked at the printer's minimum; thin Barlow weights avoided for small reversed copy.

**Images**

- [ ] Raster images at 300 ppi at final printed size (effective resolution, not file resolution).
- [ ] Images embedded or packaged with the file; no missing links.
- [ ] No photographs on the corrugated box unless the printer confirms the process can hold them.

**Geometry**

- [ ] Document at 1:1 scale, units in millimetres.
- [ ] Bleed 3 mm beyond every cut edge.
- [ ] Safe zone 5 mm inside every cut and fold line. No copy, logo or required mark inside the safe zone margin.
- [ ] Fold and crease lines clear of fine type and of the full stop in the wordmark.
- [ ] Dieline on its own layer named exactly `Dieline`, drawn in a separate spot swatch also named `Dieline`, set to overprint, so it never prints and never knocks out.
- [ ] Line styles on the Dieline layer: cut solid, crease dashed, perforation dotted (or the printer's own convention) `[CONFIRM: printer's dieline line-style convention]`.
- [ ] Glue areas and vent cut-outs marked on the Dieline layer and kept free of ink if the printer requires.
- [ ] Artwork layer(s) above the Dieline layer: `Art_White`, `Art_Red` (one layer per ink), plus `Notes` (non-printing).

**File output**

- [ ] Print file: PDF/X-4 (or the printer's stated standard) `[CONFIRM: printer's PDF standard]`.
- [ ] A separate flattened low-resolution PDF for internal review, clearly marked "NOT FOR PRINT".
- [ ] The packaging data sheet (PDS) version number written in the file's `Notes` layer and in the file name.

**Copy check before any file leaves**

- [ ] Only copy verified on the live site is printed (see the pack brief's verified copy list).
- [ ] No price, phone number, quantity claim, certification, origin claim or health claim.
- [ ] None of these words: "Minis", "halal", "gluten free", "authentic", "best", "allergen-free".
- [ ] "Since 2009" does not appear on any permanent item until G-04 is closed.
- [ ] Allergen line, if used, reads exactly: "Allergen information available on request."

### A2. Naming convention

Pattern:

```
DB_[ITEM]_[COMPONENT]_[SIZE]_v[NN]_[STATUS]_[YYYY-MM-DD].[ext]
```

| Field | Values |
|---|---|
| ITEM | `CATBOX-DZ` (dozen box), `LINER`, `SEAL`, `TEE`, `CAP`, `APRON`, `BAG`, `SIGN`, `MENU`, `FLYER` (extend as needed, uppercase, no spaces) |
| COMPONENT | `OUTER`, `INNER`, `DIELINE`, `ART`, `PDS`, `PROOF`, `MOCKUP` |
| SIZE | Internal size in mm as `LxWxH`, or garment size range, or `NA` |
| vNN | Two-digit version, starting `v01`. Never reuse a number |
| STATUS | `WIP`, `REVIEW`, `PROOF`, `APPROVED`, `SUPERSEDED` |
| Date | Date the version was saved |

Example (working size, not final): `DB_CATBOX-DZ_ART_385x290x50_v03_PROOF_2026-10-03.pdf`

Rules:

- [ ] One name per file; no "final", "final2", "new" or initials in file names.
- [ ] The `APPROVED` status is applied only after the sign-off form (A3) is complete, and only by the brand custodian.
- [ ] When a new version is approved, the previous `APPROVED` file is renamed `SUPERSEDED` and moved to `_archive/` in the same folder. It is never deleted.

### A3. Version control

- [ ] The master library (B2) is the only source. Files emailed to suppliers are copies.
- [ ] Each folder holds a `CHANGELOG.txt`: date, version, what changed, who changed it, why, who approved it.
- [ ] Any change to an approved file, however small (one word, a colour shift, a 1 mm move), creates a new version and a new proof cycle.
- [ ] The PDS, the dieline and the artwork for one item move together: if one changes, all three get the new version number.
- [ ] A supplier's purchase order quotes the exact file name and version being ordered.

### A4. Proof and approval workflow

Three proof stages. No stage may be skipped for a new item, a new supplier, a new board or a new ink. A reprint of an unchanged approved file with the same supplier can go straight to a press check at the custodian's discretion.

| Stage | What it is | What is checked | Who checks |
|---|---|---|---|
| 1. Digital proof | Printer's PDF proof returned after prepress | Copy, spelling, layout, ink separations (one plate per spot plus Dieline), bleed, safe zone, Dieline layer not printing, file name and version | Brand custodian; second reader for copy |
| 2. Physical sample | Unprinted or plain-printed structural sample on the specified board, then a printed prototype if available | Fit test with real bakes (12 in a 4 x 3 layout), lid closure, tuck holds, vents clear, liner fits, seal label spans the front, stack test, odour, fold cracking on black kraft | Brand custodian with shop lead; food-safety lead for the liner and food-contact surfaces |
| 3. Press proof (or press check) | First sheets off the press on the production board and ink | Colour against the retained standard (A6), white opacity, registration, rub, crease quality | Brand custodian on site or by received sheets |

**Sign-off form (one per item, per version, per stage)**

| Field | Entry |
|---|---|
| Item and component | |
| File name and version | |
| PDS version | |
| Supplier and contact | |
| Proof stage (1 digital / 2 sample / 3 press) | |
| Date received | |
| Board / substrate as specified (grade, flute, liner) | `[CONFIRM per PDS]` |
| Inks as specified | |
| Dimensions checked (internal L x W x H, measured) | |
| Copy checked against verified copy list (Y / N) | |
| Banned-word check passed (Y / N) | |
| Colour checked against retained standard (Pass / Fail / Not applicable at this stage) | |
| Fit test result (sample stage) | |
| Food-contact declaration on file for food-contact components (Y / N / Not applicable) | |
| Defects or changes required | |
| Decision: Approved / Approved with changes / Rejected | |
| Signed: brand custodian (name, date) | |
| Signed: food-safety lead, food-contact items only (name, date) | |
| Signed: owner, final release to production (name, date) | |

Who signs:

- **Brand custodian** signs every stage. `[CONFIRM: named person]`
- **Food-safety lead** signs any stage involving a food-contact component (liner, inside of the box, any label that touches food). `[CONFIRM: named person]`
- **Owner** (Elie) gives the final release to production at stage 3, and signs any first order with a new supplier.
- A supplier's own approval or "looks fine" never replaces a Dough Boss signature.

### A5. Incoming-goods QA checklist

Run on every delivery of boxes, liners, seal labels and merch, before stock is put away. Hold the delivery in the quarantine zone (A7) until it passes.

**Delivery record**

- [ ] Delivery matches the purchase order: item, file version, quantity, supplier.
- [ ] Supplier batch or lot number recorded, with delivery date.
- [ ] Cartons and pallets undamaged, dry, no crushing, no water marks.
- [ ] Sample size drawn per the agreed sampling plan `[CONFIRM: sampling plan agreed with each supplier, for example an ISO 2859-1 inspection level]`.

**Boxes (corrugated dozen box)**

- [ ] Internal dimensions within tolerance: L, W, H each within plus or minus `[X] mm` of the PDS `[CONFIRM: tolerance from supplier spec]`.
- [ ] Board grade, flute and liner as on the PDS `[CONFIRM]`.
- [ ] Colour of both inks against the retained standard under the same lighting (A6). Record Pass / Fail.
- [ ] White opacity consistent; no board showing through where the standard shows solid.
- [ ] Ink rub: rub a printed area with a clean dry finger and with the back of another box; no visible transfer or smearing `[CONFIRM: formal rub test method and pass level with printer, for example a Sutherland rub tester]`.
- [ ] Fold cracking: erect `[N]` boxes; check every crease on the black outer for cracking, white fibre showing or liner splitting. Light fibre at folds may be within the agreed standard; splitting is a reject `[CONFIRM: acceptable crease standard agreed at press proof]`.
- [ ] Lid closes square; tuck holds; locking tabs engage without tearing.
- [ ] Vents cut cleanly and fully; no hanging chads.
- [ ] Odour: open a carton and smell the inside surface; no solvent, ink, damp, musty or chemical odour.
- [ ] No contamination: no dust, insects, oil, foreign matter.
- [ ] Food-contact declaration for the board and any inner coating on file for this supplier and this specification.

**Greaseproof liners**

- [ ] Sheet size as specified, within plus or minus `[X] mm`.
- [ ] Grease resistance as specified `[CONFIRM: grade and test from supplier spec]`.
- [ ] Print (if any) does not transfer to a damp finger.
- [ ] Odour check passed.
- [ ] Food-contact declaration on file for the liner, including any print ink on it.
- [ ] Packs sealed on arrival; stored sealed until use.

**Seal labels**

- [ ] Diameter and printed content match the approved version; no copy variations.
- [ ] Adhesive holds on the box's black kraft at shop temperature, and peels without tearing the box only if that is the specified behaviour `[CONFIRM: permanent or removable adhesive]`.
- [ ] Writable: a test field filled in with the shop's marker does not smear or bead after `[X]` seconds `[CONFIRM: marker type to be standardised]`.
- [ ] Colour against the retained standard.
- [ ] Rolls or sheets wound and sized as ordered.

**Merch (shirts, aprons, caps, bags and other items)**

- [ ] Garment blank, colour and size breakdown match the PO.
- [ ] Print or embroidery placement within plus or minus `[X] mm` of the approved mock-up.
- [ ] Colour of the ember red against the retained standard (fabric standard, not the box standard).
- [ ] Print adhesion: stretch test on screen print or transfer; no cracking.
- [ ] Wash test on one sample per batch per the care label before issue or sale `[CONFIRM: wash test method]`.
- [ ] Care and fibre labels present as supplied by the manufacturer `[CONFIRM: labelling obligations for merch offered for sale]`.
- [ ] No merch is offered for sale until G-01 (trade mark) is closed.

**Outcome**

- [ ] Pass: release from quarantine, label with received date and batch, put away under FIFO.
- [ ] Fail: keep in quarantine, photograph defects, notify supplier in writing within `[N]` days `[CONFIRM: claim window in supplier terms]`, record in the supplier log.

### A6. Colour standards (retained samples)

- [ ] At press proof approval, keep `[N]` signed sheets or boxes as the master colour standard, dated and with the version written on the back.
- [ ] Keep one standard at the supplier and one at Dough Boss.
- [ ] Store standards flat, in the dark, away from heat. Replace them on a set cycle because they fade `[CONFIRM: replacement interval]`.
- [ ] Compare under the same light each time, ideally a standard viewing light (D50, as ISO 3664 describes) or, if none is available, the same bench and lamp every time.
- [ ] One standard per substrate: the box (black kraft), the seal label (kraft label stock), and each fabric for merch. A colour that matches on one substrate does not prove a match on another.

### A7. Storage and FIFO

- [ ] Packaging stored in a dry, clean, ventilated area away from chemicals, cleaning products, fuel, strong food odours, ovens and direct sun `[CONFIRM: storage conditions on each supplier's PDS]`.
- [ ] Off the floor on shelving or pallets, and clear of walls.
- [ ] Food-contact items (liners, and boxes once unwrapped) kept covered.
- [ ] A marked quarantine zone for goods not yet checked or failed.
- [ ] Every carton labelled on arrival: item, version, batch, received date.
- [ ] First in, first out: new stock goes behind or under existing stock. Pick from the oldest received date.
- [ ] Superseded versions are segregated and labelled "DO NOT USE" or returned or recycled, so an old design or old copy is never packed.
- [ ] Monthly stock count against the reorder table (A8).

### A8. Reorder points

No usage, lead time or order figures are set here. Each shop fills the table from its own records.

**Formulas**

```
Reorder point (ROP)  = (average daily usage x lead time in days) + safety stock

Safety stock         = (maximum daily usage x maximum lead time in days)
                       - (average daily usage x average lead time in days)

Order quantity       = the larger of (planned usage for the cover period - stock on hand + ROP)
                       and the supplier's minimum order quantity (MOQ)
```

Usage for the box is driven by catering orders: boxes per order = dozens ordered (2 dozen = 2 boxes, 3 dozen = 3 boxes, 5 dozen = 5 boxes), plus a spoilage allowance.

```
Average daily box usage = (dozens sold over the period / days in the period) x (1 + spoilage rate)
Liner usage             = box usage x liners per box
Seal label usage        = box usage x labels per box
```

**Per-item table (fill per shop)**

| Item | Unit | Avg daily usage | Max daily usage | Avg lead time (days) | Max lead time (days) | Safety stock | ROP | MOQ | Cover period | Storage limit |
|---|---|---|---|---|---|---|---|---|---|---|
| Dozen box | each | `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[QUOTE]` | `[ ]` | `[ ]` |
| Greaseproof liner | each | `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[QUOTE]` | `[ ]` | `[ ]` |
| Seal label | each | `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[QUOTE]` | `[ ]` | `[ ]` |
| Staff shirt | each by size | `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[QUOTE]` | `[ ]` | `[ ]` |

Notes:

- [ ] Review the inputs before known peaks (for example end-of-year functions) using the catering production calendar.
- [ ] The storage limit caps the order quantity; a large MOQ that will not fit is a supplier-selection problem, not a storage one.
- [ ] Recalculate when a lead time changes or when a second shop draws from the same stock.

### A9. Packing SOP: catering order

Purpose: every catering order leaves the shop in the approved box, correctly filled, correctly labelled and matched to the order.

Before you start you need: the order (production calendar entry or ticket), erected boxes that passed QA, liners, seal labels, the shop's standard marker, the shop's allergen matrix `[CONFIRM: matrix exists and is current, G-07]`.

1. Read the order. Note the customer name, order number, pickup or hand-over time, the dozens and bakes ordered, and any special notes.
2. Count the boxes needed: one box per dozen. Write the number down (for example "3 boxes").
3. Wash and dry hands. Clean and sanitise the packing bench per the shop's food-safety procedure.
4. Take boxes from the oldest received stock (FIFO). Erect each one. Reject any box with cracked folds, odour, marks or damage.
5. Lay one greaseproof liner in each box so it covers the base and comes up the sides as set in the fit test. Handle liners by the edges.
6. Check the bakes are ready to pack per the shop's food-safety plan `[CONFIRM: hold and venting rule for hot bakes in a closed box, from the food-safety plan]`.
7. Place 12 bakes in each box in the 4 x 3 layout, one variety per row or as the order requires `[CONFIRM: layout after the fit test]`. Use tongs or gloves per the food-safety procedure. Do not stack bakes on top of each other.
8. Check each box against the order: right count (12), right varieties, nothing broken or under-baked.
9. Keep the side vents clear. Do not fold the liner over the vents.
10. Close the lid and push the tuck fully in. Do not force it; if it bulges, the layout is wrong. Repack.
11. Fill in the seal label by hand, in the standard marker, before applying it:
    - For: the customer or event name as on the order.
    - Date: the pack date `[CONFIRM: whether the date field means pack date; no use-by or best-before wording unless the food-safety plan supports it, G-12]`.
    - Order number, and box number as "1 of 3", "2 of 3", "3 of 3".
    - Tick list: tick each variety in this box.
    - Allergens: copy from the allergen matrix, for every variety ticked, using the required allergen names exactly as the matrix shows them. Never write "none", "free from" or any allergen-free wording. If the matrix does not cover an item, stop and ask the shop lead.
12. Apply the seal label centred across the front so it spans the lid and the tuck. Press it down firmly.
13. Stack the boxes for the order squarely, lids up, labels facing out, vents unobstructed. Do not exceed `[N]` boxes high `[CONFIRM: maximum stack height from the compression test]`.
14. Place the stack in the catering hand-over area, away from the oven and out of direct sun, with the order docket on top.
15. At hand-over, confirm the customer or driver name and order number, count the boxes with them against the order, and point out the allergen field on each label. Tell them allergen information is available on request.
16. Record the hand-over: time, who collected, number of boxes, staff initials. Close the order in the production calendar.

If anything does not match the order at step 8, 11 or 15, do not release it. Fix it or call the shop lead.

---

## Part B. Brand governance

### B1. Ownership of master files

| Role | Responsibility | Holder |
|---|---|---|
| Owner | Owns the brand and all master files; final release to production; approves new suppliers and any new use of the logo | Elie `[CONFIRM: legal owner of the brand assets is the trading entity, G-01]` |
| Brand custodian | Maintains the master library, applies the naming and version rules, runs proof sign-off, answers usage requests | `[CONFIRM: named person]` |
| Food-safety lead | Signs off food-contact items; owns the allergen matrix | `[CONFIRM: named person]` |
| Shop leads | Use approved assets only; run incoming QA and the packing SOP; report defects | One per shop `[CONFIRM: names]` |
| External designers, printers, agencies | Work on copies; return all working files to the custodian; never hold the only copy | Per contract `[CONFIRM: work-for-hire or assignment terms so Dough Boss owns commissioned files, G-17]` |

Rules:

- [ ] The master library is held in one Dough Boss-controlled location with backup `[CONFIRM: location, for example a shared drive owned by the business account, G-16]`.
- [ ] At least two people can access the masters; no single freelancer, staff member or supplier holds the only copy.
- [ ] Source files (editable vector and layered files) are kept, not only PDFs.
- [ ] Font licences for Bebas Neue, Barlow and any marker face are recorded with their licence type `[CONFIRM: licence terms permit commercial print and merch]`.

### B2. Asset library structure

```
DOUGH-BOSS-BRAND/
  00_README/            how to use the library, contacts, this governance section
  01_Brand-Standards/   brand and catering pack (PDF), colour and type specs, do/don't
  02_Logo/              master wordmark (vector, rebuilt from site CSS), reversed, one-colour, clear-space guide
  03_Colour/            token list, spot references, scanned colour standards
  04_Type/              font files and licence records
  05_Packaging/
     CATBOX-DZ/         dieline, artwork, PDS, proofs, sign-off forms, CHANGELOG.txt, _archive/
     LINER/
     SEAL/
  06_Merch/             shirts, aprons, caps, bags (mock-ups, tech packs, approvals)
  07_Signage/           per-shop signage drawings and approvals
  08_Photography/
     approved/          cleared for use, with captions and usage notes
     raw/               unedited originals
     rejected/          kept for reference, never used
  09_Digital/           web, social, listing images, link-preview cards
  10_Templates/         menus, flyers, catering quote layout, social templates
  11_Suppliers/         approved supplier list, PDS per supplier, food-contact declarations, quotes ([QUOTE] log)
  12_Approvals/         completed sign-off forms and usage approvals, by year
  _archive/             superseded files, never deleted
```

### B3. Usage approvals

| Use | Needs approval from | Notes |
|---|---|---|
| Approved template, unchanged content (for example a reprint of an approved flyer) | None; log the use | Must use the current `APPROVED` version |
| New artwork using the logo or brand colours | Brand custodian | Sign-off form A4 |
| Anything printed in volume, packaging, signage | Brand custodian then owner | Full proof workflow |
| Any customer-facing claim (dates, history, origin, process, dietary) | Owner, plus advice where required | "Since 2009" is open, G-04 |
| Food-contact items | Brand custodian, food-safety lead, owner | Declaration on file first |
| Merch for sale | Owner | Blocked until G-01 is closed |
| Third-party use of the logo (stockists, venues, media, partners) | Owner, in writing | Supply the logo file from `02_Logo/`; never let a third party redraw it |
| New photography | Brand custodian | Must meet the photo rules below |

### B4. Do and don't

Do:

- [ ] Use the wordmark "DOUGH BOSS." exactly as rebuilt from the site CSS: Bebas Neue caps, tracking .13em, outlined box, ember-red full stop (#e2231a) `[CONFIRM: owner confirms this is the shop's logo; a supplied shop-sign logo replaces it, G-02]`.
- [ ] Keep clear space around the wordmark `[CONFIRM: clear-space rule, for example the height of the full stop]` and respect a minimum size `[CONFIRM: minimum print and screen sizes]`.
- [ ] Keep the palette dark and high-contrast: char and coal grounds, cream and paper type, ember red as the accent, gold sparingly.
- [ ] Use Bebas Neue for display and Barlow for body.
- [ ] Use only copy that is verified on the live site.
- [ ] Print "Allergen information available on request." where an allergen line is needed, and complete the per-order allergen field from the matrix.
- [ ] Photograph real product and real packaging, with natural imperfection (fibre, flour dust, slight misregistration).

Don't:

- [ ] Don't redraw, trace, stretch, recolour, outline differently, add effects to, or AI-generate the logo.
- [ ] Don't move or recolour the ember full stop.
- [ ] Don't print prices, phone numbers, quantities as claims, certifications, origin or health claims.
- [ ] Don't use "Minis" anywhere public, or "halal", "gluten free", "authentic", "best", "allergen-free".
- [ ] Don't print "Since 2009" on permanent items until G-04 is closed.
- [ ] Don't show people or hands in photos; no smoke, steam or haze; no lemons.
- [ ] Don't use stretched or low-resolution photos (the live Franchising hero is a 300 px image stretched about 4.3 times; that is the example to avoid).
- [ ] Don't send a supplier a file that is not the current `APPROVED` version.
- [ ] Don't let a supplier hold the only copy of a file or a cutting die without a written statement of who owns it.

---

## Part C. Expansion

Context from the repository (read 3 Oct 2026): the live site has a published `/franchising/` page, but the Google Ads negative keyword list states "Franchise enquiries are not our offer" and the keyword research marks franchise intent as `[CONFIRM]`. The brand's position on franchising is therefore unresolved (G-03). No Darlinghurst material was found under `web/docs`; any new-site references below are generic `[CONFIRM: name and status of the next site before it appears in any listing, G-14]`. No lease details are included in this pack.

### C1. New-shop rollout kit

Work through in dependency order. An item cannot start until everything in its "Depends on" column is closed. Lead times are placeholders to be filled from supplier quotes.

**Gate 0: before anything is ordered**

- [ ] G-01 trade mark ownership and registration confirmed.
- [ ] G-02 logo confirmed by the owner.
- [ ] Site confirmed and authorised to trade under the brand (no lease details in this pack).
- [ ] Council, landlord or centre approvals for external signage identified `[CONFIRM: approvals required at the site]`.
- [ ] Opening date set by the owner (no date is assumed here).

**Kit checklist**

| # | Item | Depends on | Lead time | Owner | Done |
|---|---|---|---|---|---|
| 1 | Site survey: measure fascia, windows, counter, menu-board wall, storage area for packaging | Gate 0 | `[ ]` days | Brand custodian | [ ] |
| 2 | Signage drawings (fascia, window, menu boards, internal) from `02_Logo/` masters | 1, Gate 0 | `[ ]` | Brand custodian | [ ] |
| 3 | Signage approvals (landlord, centre, council as applicable) | 2 | `[ ]` | Owner | [ ] |
| 4 | Signage fabrication and install | 3, signage supplier `[QUOTE]` | `[ ]` | Owner | [ ] |
| 5 | Uniform spec (garment, colour, print placement, sizes) | G-01, G-02 | `[ ]` | Brand custodian | [ ] |
| 6 | Uniform order and incoming QA (A5 merch) | 5, staff size list, `[QUOTE]` | `[ ]` | Shop lead | [ ] |
| 7 | Packaging stock: dozen boxes, liners, seal labels at opening stock level | Approved PDS and artwork, A8 reorder table filled for the new shop, storage area from 1 | `[ ]` | Shop lead | [ ] |
| 8 | Incoming QA of opening packaging stock | 7 | `[ ]` | Shop lead | [ ] |
| 9 | Allergen matrix issued for the new shop's menu | Menu confirmed for the site, G-07 | `[ ]` | Food-safety lead | [ ] |
| 10 | Packing SOP training (A9) with a practice order | 8, 9 | `[ ]` | Shop lead | [ ] |
| 11 | POS: new Square location, item library, price per location, KDS and printers, allergen modifiers, staff roles | Site address, menu, pricing decision by owner | `[ ]` | Owner | [ ] |
| 12 | POS test: test orders through every channel (counter, website, catering) reach the right station | 11 | `[ ]` | Shop lead | [ ] |
| 13 | Website: add the shop to Locations, opening hours, per-shop structured data, ordering availability | 11, opening date | `[ ]` | Owner / web | [ ] |
| 14 | Google Business Profile, Apple Maps and other listings, with the business name exactly as used on the shopfront and website | Signage installed or install date set, G-01, G-14 | `[ ]` | Owner | [ ] |
| 15 | Delivery and ordering platforms, if used | 11, owner decision `[CONFIRM: platforms used, G-15]` | `[ ]` | Owner | [ ] |
| 16 | Photography: shopfront (at least 2,880 px wide, no people), interior, product, packed catering box | 4 installed, 7 in stock, shot list rules | `[ ]` | Brand custodian | [ ] |
| 17 | Load approved photos to `08_Photography/approved/` and to listings and site | 16 | `[ ]` | Brand custodian | [ ] |
| 18 | Opening readiness check: every item above ticked, no open `[CONFIRM]` on public-facing material | 1 to 17 | n/a | Owner | [ ] |

### C2. Franchise readiness (brand items)

> **[CONFIRM trade mark ownership and registration before any franchise or merch scale-up]**
>
> This is the first and blocking item. The pack draws no legal conclusion about ownership, registration, classes, conflicts or the ability to licence the brand. Obtain advice from a qualified trade mark attorney or lawyer, and record the outcome in G-01, before any franchise discussion, licence, merch sale or large print run.

Franchising in Australia carries specific regulatory obligations. Obtain advice from a franchise lawyer on what applies before any franchise offer, discussion with a prospective franchisee, or change to the `/franchising/` page. Nothing below is legal advice; it is the brand-side readiness list.

**Brand and identity**

- [ ] Trade mark ownership and registration status confirmed and documented (G-01).
- [ ] Business name, domain (doughboss.com.au) and social handles (@doughboss) held by the same entity that owns the brand `[CONFIRM, G-01]`.
- [ ] Logo confirmed (G-02) and master files held by the brand owner (B1).
- [ ] Commissioned artwork, photography and fonts licensed or assigned so they can be used by franchisees `[CONFIRM, G-17]`.
- [ ] Brand position on franchising decided and made consistent across the site, ads and listings (G-03).

**Standards a franchisee would receive**

- [ ] This brand and catering pack, finalised with no open `[CONFIRM]` on brand items.
- [ ] Shop fit and signage standards (from C1 items 1 to 4, documented once).
- [ ] Packaging specification (PDS, dieline, artwork) and the approved supplier list.
- [ ] Packing SOP (A9), incoming QA (A5), storage and FIFO (A7), reorder method (A8).
- [ ] Allergen matrix process: who maintains it, how changes reach every shop.
- [ ] Photography and digital listing standards.
- [ ] Usage approvals process (B3) adapted for multiple operators.

**Control and audit**

- [ ] A brand compliance check per shop (signage, uniform, packaging version, labels, listings) at a set interval `[CONFIRM: interval]`.
- [ ] A single supplier ordering route, so superseded packaging cannot be reordered locally.
- [ ] Claims register: every public claim with its evidence ("Since 2009" stays open until G-04 is closed).

### C3. Supplier scaling

**Second source**

- [ ] Identify a second supplier for each critical item (dozen box, liner, seal label) before demand outgrows the first.
- [ ] Give both suppliers the same PDS, the same dieline and the same colour standard. The second supplier goes through the full proof workflow (A4) as if it were a new item.
- [ ] Confirm who owns the cutting die and print plates, and whether the die can move between suppliers `[CONFIRM in each quote, G-18]`.
- [ ] Hold food-contact declarations from both suppliers for the exact specification.
- [ ] Split volume, or keep the second source warm with periodic small orders, as the owner decides.

**Colour standards across suppliers**

- [ ] One master standard per substrate (A6), physically sent to each supplier.
- [ ] Each supplier's press proof is approved against the master, not against the other supplier's output.
- [ ] Record each supplier's ink references for the same target; they may differ by supplier and board.

**Lead times and capacity (placeholders only)**

| Item | Supplier | Artwork-to-proof (days) | Proof-to-delivery (days) | Reorder lead time (days) | MOQ | Max monthly capacity | Price |
|---|---|---|---|---|---|---|---|
| Dozen box | Primary `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[QUOTE]` | `[ ]` | `[QUOTE]` |
| Dozen box | Second `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[QUOTE]` | `[ ]` | `[QUOTE]` |
| Liner | Primary `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[QUOTE]` | `[ ]` | `[QUOTE]` |
| Seal label | Primary `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[QUOTE]` | `[ ]` | `[QUOTE]` |
| Staff shirts | Primary `[ ]` | `[ ]` | `[ ]` | `[ ]` | `[QUOTE]` | `[ ]` | `[QUOTE]` |

**Other scaling checks**

- [ ] Storage space at each shop versus MOQ; consider a central store if MOQs exceed shop storage.
- [ ] Price breaks are recorded only from written quotes with source and date.
- [ ] Change control: any supplier change of board, ink, adhesive or coating must be notified in writing and triggers a new sample and press proof.
- [ ] Supplier performance log: on-time delivery, QA pass rate, claims raised.

---

## Part D. Decisions and gaps register

Scope: compiled from the pack brief and this section (3 Oct 2026). When the full pack is assembled, merge every `[CONFIRM]` and `[QUOTE]` from the other sections into this table so it remains the single list.

Status for every row at the time of writing: OPEN.

| ID | Item | Owner | Why it matters | Blocks |
|---|---|---|---|---|
| **G-01** | **Trade mark ownership and registration of "DOUGH BOSS." name and wordmark; that the business name, domain and social handles sit with the same entity** | **Owner, with a trade mark attorney or lawyer** | **Investing in print, signage and merch, licensing the brand, or making any franchise offer all depend on who owns the brand and whether it is protected. No legal conclusion is given here.** | **Merch for sale, large print runs, new-shop signage, any franchise activity** |
| G-02 | Logo: the site wordmark is confirmed as the shop's logo, or a supplied shop-sign logo replaces it | Owner | Every printed and digital asset uses it; changing it after print wastes the run | All artwork release |
| G-03 | Brand position on franchising: live `/franchising/` page versus ads negative "Franchise enquiries are not our offer" | Owner | Mixed public signals; franchising also carries regulatory obligations that need advice first | C2, the franchising page, ads keywords |
| G-04 | "Since 2009" as a printed claim | Owner | A permanent print run fixes the claim; it must be accurate and supportable | Any permanent item using it |
| G-05 | Catering phone line | Owner | No phone number may be printed until a dedicated line is confirmed | Box, labels, flyers |
| G-06 | Bake size: measure a real bake (working 8 to 10 cm, 9 cm used) and run a fit test | Shop lead, brand custodian | Drives the box internal size, the layout and the dieline | Dieline, PDS, sample stage |
| G-07 | Allergen matrix exists, is current and covers every catering item | Food-safety lead | The per-order allergen field is completed from it; a wrong entry is a safety risk | Seal label use, packing SOP step 11 |
| G-08 | Box internal size (working 385 x 290 x 50 mm), board grade, flute and liner | Brand custodian with supplier | Fit, strength, stacking and cost all depend on it | PDS, quotes |
| G-09 | Spot ink references for ember red on black kraft, and white opacity (single or double hit) | Printer, brand custodian | Without a drawdown on the real board the red and white will not match the brand | Press proof, colour standard |
| G-10 | Food-contact declarations for board, liner, any coating and any ink on food-contact surfaces, and which standard the food-safety adviser requires | Food-safety lead, suppliers | Packaging must be fit for food contact; the declaration is the evidence | Incoming QA pass, first order |
| G-11 | Hold and venting rule for hot bakes in a closed box | Food-safety lead | Condensation and food safety; sets packing SOP step 6 | Packing SOP |
| G-12 | Meaning of the date field on the seal label (pack date) and whether any use-by or best-before wording is supportable | Food-safety lead, owner | A date on food packaging can be read as a safety or quality claim | Seal label artwork |
| G-13 | Maximum stack height from a compression test | Brand custodian with supplier | Crushed boxes and damaged bakes at hand-over | Packing SOP step 13 |
| G-14 | Next site name and status (no Darlinghurst material found in `web/docs`) | Owner | Listings and signage must not announce a site that is not confirmed | C1 rollout |
| G-15 | Delivery and ordering platforms used per shop | Owner | Listings, menu images and packaging hand-over differ by platform | C1 item 15 |
| G-16 | Location and backup of the master asset library | Owner, brand custodian | Prevents loss and prevents a supplier or freelancer holding the only copy | B1, B2 |
| G-17 | Ownership or licence of commissioned artwork, photography and font use for print and merch | Owner | Without it the brand may not be able to reuse or licence its own assets | Merch, franchise readiness |
| G-18 | Ownership and portability of cutting dies and plates | Owner, suppliers | Decides whether a second source can be added without new tooling | C3 second source |
| G-19 | Named brand custodian, food-safety lead and shop leads | Owner | Sign-off forms need named signatories | A4 workflow |
| G-20 | Printer standards: CMYK profile, PDF standard, minimum line and type, trap, dieline line styles, rub test method | Printer | Files fail prepress or print badly without them | A1 file release |
| G-21 | Tolerances, sampling plan and claim window per supplier | Brand custodian, suppliers | Incoming QA needs agreed pass and fail limits | A5 |
| G-22 | Seal label adhesive (permanent or removable) and the standard marker | Brand custodian | Labels must stay on and stay legible | Seal label order |
| G-23 | Retained colour standard quantity and replacement interval | Brand custodian | Standards fade; a faded standard approves the wrong colour | A6 |
| G-24 | Usage, lead time, safety stock and MOQ figures per item per shop | Shop leads, suppliers | Reorder points cannot be set without them | A8 |
| G-25 | All costs: boxes, liners, labels, merch, signage, photography | Owner | No price is used without a written quote with source and date | Budgets, RFQs |
| G-26 | Pizza diameter ("generally medium-sized"; AU medium commonly about 28 to 30 cm) | Shop lead | Needed if pizza packaging is added to the range | Pizza packaging |
| G-27 | Font licences permit commercial print and merch | Brand custodian | Avoids a licence breach on printed and sold items | Merch, signage |
| G-28 | Merch labelling obligations and wash-test method for items offered for sale | Owner, merch supplier | Items sold to the public carry their own obligations | Merch for sale |
| G-29 | Clear-space rule and minimum logo sizes | Brand custodian | Keeps the wordmark legible and consistent at every size | Brand standards |
| G-30 | Site approvals for external signage (landlord, centre, council) | Owner | Signage cannot be installed without them | C1 items 3 and 4 |
