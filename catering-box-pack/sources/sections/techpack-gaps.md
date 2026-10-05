# Tech pack gaps: what Elie must decide or supply before sending

**Internal. Not for the factory.** Companion to sections/techpack.md (DB-TP-CAT-001 rev A), 3 October 2026.

## 1. Decide or supply before sending the RFQ

| # | Item | Who | Recommended default | Blocks |
|---|---|---|---|---|
| 1 | **Dieline v2: issued** (separated spot plates, page boxes, vent alignment fixed, DXF). Remaining: the factory draws its production CAD from v2 and Dough Boss signs it at S1; decide the INSIDE DARK colour or drop the inside print | Elie | Send v2 with the RFQ; approve the factory CAD at S1 | Nothing for the RFQ |
| 2 | Legal entity name, ABN, GST status, importer of record, customs broker and freight forwarder | Elie | Name one broker before any DDP or CIF quote is compared | Incoterm quotes, customs |
| 3 | Delivery address and port (shops are Revesby, Bankstown, Roselands) | Elie | One receiving point; Port Botany, Sydney | Quotes, samples |
| 4 | Quantities: tiers 1,000 / 5,000 / 10,000 are in the RFQ. First real order size and yearly forecast | Elie | Quote the three tiers; order nothing until quotes are in (status gate 6) | Price comparison |
| 5 | Incoterm and who books freight | Elie | FOB with Dough Boss's own forwarder; CIF and DDP asked for comparison | Annex A3 |
| 6 | Payment terms Dough Boss will accept (deposit, method, protection) and quote currency | Elie, accountant | Quote in USD or RMB; compare in AUD at the RBA rate | Annex A5 |
| 7 | **Signatories**: brand custodian, food-safety lead (G-19), who gives final release (owner, Elie), who receives samples | Elie | Name them in section 17.2 | Sign-off, approvals |
| 8 | NDA / non-use agreement and the trade mark position G-01 (and logo G-02) | Elie, lawyer | NDA before artwork is sent; no volume order until G-01 closes. Not legal advice | Sending artwork, volume order |
| 9 | Ownership of dies and plates (G-18) | Elie | Dough Boss owns, factory stores free, releasable | Tooling quote |
| 10 | Fit test: measure a real bake and test 12 in a white sample (G-06). Order 10 unprinted structural samples first | Shop lead | Do not lock 385 x 290 x 50 until this passes | Dieline, tooling |
| 11 | Filled mass of 12 bakes, serving temperature, hot-hold and venting rule (G-11), max stack height (G-13) | Food-safety lead, shop lead | Weigh and record. Sets the BCT target and tests | BCT target, tests |
| 12 | Board option: accept quotes for Option A (dyed black) and B (flood black), B-flute as information only | Elie | Quote both | Board choice |
| 13 | Inside-lid print: yes or no, and which dark colour | Elie, brand | Quote it as a separate line; drop if the quote is high | Plates, quote |
| 14 | Liner: plain or printed, size 380 x 285 or 480 x 385; liner and seal from this factory or another | Elie | Plain, size by fit sample; source separately if cheaper | Liner and seal RFQ lines |
| 15 | Seal label adhesive permanent or removable, marker type (G-22) | Brand custodian | Permanent; one standard marker | Seal quote |
| 16 | Print copy freeze: "Since 2009" (G-04), "Three shops baking daily" and "Fresh from the oven" as printed claims, no phone (G-05), allergen line wording | Elie | Print only the verified lines; leave out "Since 2009" | Artwork release |
| 17 | Spot colour names (WHITE OPAQUE / EMBER vs "DB Opaque White" / "DB Ember Red" in the production notes) | Brand custodian | WHITE OPAQUE and EMBER | Dieline v2 |
| 18 | Quality: appoint a third-party inspector or do it yourself; budget for lab tests (food-contact, PFAS, MOSH/MOAH) and pre-shipment inspection | Elie | Appoint one before first shipment; costs are [QUOTE] | Section 10, 14 |
| 19 | Compliance scope: which regimes the food-safety adviser requires (G-10); tighten total fluorine from below 100 ppm to 50 ppm | Food-safety lead | Keep the pinned limit below 100 ppm, require the measured value, ask if it is 50 ppm or less | Section 14 |
| 20 | Origin marking: "MADE IN CHINA" on cartons only, or also inside the box. Pack brief bans origin claims on print | Elie, broker | Cartons only unless the broker says otherwise | Section 15 |
| 21 | Language and channel (email for approvals; chat is not approval) and who replies | Elie | Email only | Section 17 |
| 22 | Reply-by date and the sender mailbox on the RFQ | Elie | Use catering@doughboss.com.au (verified on the site) | Annex A |

## 2. Audit: what the PDS and the dieline v1 did not give

Checked against the factory tech-pack checklist. "Now" is where the item is covered in techpack.md.

| Checklist item | PDS rev A / dieline v1 | Now |
|---|---|---|
| 1:1 vector dieline | Present: PDF page 649.8 x 877.0 mm = 1:1; vector | Target in s4 |
| Separate cut, crease, perf, bleed, safe layers | **Missing.** One spot "Dieline" for cut and crease; no layers; no overprint flag in the file; PDF 1.4, not PDF/X; TrimBox and BleedBox equal MediaBox; text is subset-embedded but a Helvetica font is not embedded | s4.2, s4.3 |
| Line legend | **Missing** from the PDF (title block only) | s4.3 |
| Perforation, glue areas, flute arrow | **Missing** | s3.3, s3.4 (none: perf and glue marked as not used) |
| Dimensions with tolerances | Nominals only, no tolerances | s3.2 (RECOMMENDED) |
| Internal / external / blank | External "about"; blank pinned | s3.2 |
| Count of punched vent holes | PDS says 4; the die has 8 punches (outer and inner ply) | s3.3 |
| Board per ply in factory terms | Caliper, ECT, burst only; no liner / flute / medium gsm | s5.1 (RECOMMENDED ranges) |
| ECT and BCT target | ECT given; BCT absent | s5.2 (formula; mass is a gap) |
| Spot colour list, Pantone, ink order, trapping, coverage | Names and 485 C only | s6.2 to 6.4 |
| White underlay choke | 0.3 mm given | s6.3 |
| Finish or varnish | Absent | s6.6 |
| Artwork file list and versioning | Naming rule in the production notes only | s7 |
| Sample and approval stages | Three proof stages in the production notes only | s9 (S0 to S6) |
| QC and AQL | **Missing** | s10 (table verified from ISO 2859-1) |
| Defect classification | **Missing** | s10.3 |
| Packing: bundle, carton, pallet, moisture | Only "supplied flat, bundled" | s12 |
| Shipping: Incoterms, port, HS | **Missing** | s13 |
| Compliance documents (AU, GB, FDA, EU, AS 2070, PFAS, heavy metals, MOSH/MOAH) | Partly: AU, PFAS 100 ppm, mineral oil | s14 |
| Country-of-origin marking | **Missing** | s15 |
| IP and confidentiality | **Missing** | s16.2 |
| Contacts, sign-off, revision control | Revision table only | s17, s18 |
| RFQ form and bilingual glossary | Costing RFQ text (no price grid for 1,000 / 5,000 / 10,000) | Annex A, B |

## 3. Conflicts found between pack documents

| Conflict | Default used in the tech pack |
|---|---|
| Bleed and safe zone: 3 mm / 5 mm (PDS, dieline v1) vs 6 mm / 6 mm (PDS research) | 3 mm / 5 mm, with 6 mm clear of cut-outs. Factory may ask for more |
| Reversed type minimum: 8 pt (PDS) vs white type 10 pt, 12 pt preferred (research) | 10 pt, the stricter value [CONFIRM] |
| Line weights: PDS 1.0 mm vs research 2 pt white, 4 pt for EMBER | White 1.0 mm; EMBER and knockouts 4 pt |
| Spot and layer names: three versions (PDS, research, production notes) | WHITE OPAQUE, EMBER; layers Art_White, Art_Ember |
| Liner: PDS 40 to 50 gsm, 380 x 285 or 480 x 385 mm vs costing RFQ 35 to 40 gsm, 300 x 400 or 380 x 380 mm | PDS values (pinned) |
| Quote tiers: costing RFQ 250 / 500 / 1,000 / 2,500 / 5,000 vs brief 1,000 / 5,000 / 10,000 | 1,000 / 5,000 / 10,000 |
| Vent placement: research says "2 per long side wall"; PDS and die put them on the 290 mm side walls | Side walls (290 mm), as pinned |
| Thumb notch: research proposes one; PDS says none | None |
| AS 2070: listed in the brief, but it is a plastics standard withdrawn 22 March 2021 | Not used for board; the other regimes stand in |
| Vent centres: outer 25.0 mm from base crease vs inner 24.2 mm from roll fold, about 0.8 mm offset when rolled (computed from dieline.py) | Tolerance 2.0 mm; factory CAD compensates |
| Dieline is drawn from the outside; FEFCO draws from the inside | Stated in s3.1 |

## 4. Not verified (do not rely on yet)

- Numeric limits in GB 4806.8-2022 (only the raw-material clauses were readable). Ask the lab to state them.
- EU PPWR PFAS limits, 21 CFR 176.170 and the EU DoC citations: seen in search summaries or supplier examples, not the primary text.
- HS sub-codes for liner and label, ChAFTA treatment, and whether origin marking applies: broker to confirm.
- Board gsm ranges, tolerances and the safety factor are RECOMMENDED values from general industry practice, not Dough Boss tests. No ECT, BCT, price, MOQ or lead time has been invented anywhere in the pack.
