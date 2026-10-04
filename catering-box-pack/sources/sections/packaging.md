# Packaging: the catering dozen box

One box holds one dozen bakes, 4 x 3, on a greaseproof liner. Orders stack: Morning Tea is 2 boxes, Office Platter 3, Function Spread 5. The box is the hero of the catering product, so it is built to look and work like a premium takeaway mailer, not a pizza box.

## Structure

| Item | Specification | Status |
|---|---|---|
| Style | FEFCO 0427 one-piece folder: hinged lid from the back wall, rolled double front and side walls, tuck front | Working |
| Internal size (L x B x H) | 385 x 290 x 50 mm | [CONFIRM: fit test with 12 real bakes, 4 x 3, about 9 cm each] |
| Approximate outside size | about 391 x 295 x 53 mm (E-flute) | INFERRED; converter's CAD sets the allowances |
| Board | E-flute single wall, caliper 1.6 mm, about 32 ECT (about 5.6 kN/m). B-flute is the fallback if the lid sags or the stack test fails | [CONFIRM with converter] |
| Outer face | Black kraft (dyed black liner, or natural kraft flood-printed black as a third ink) | [CONFIRM availability] |
| Inner face | Natural brown kraft, food-contact declaration for the intended use | [CONFIRM: DoC] |
| Flat blank (working die) | 593.8 x 781.0 mm | From the parametric dieline |
| Lid front | One tuck flap 45 mm deep, 3 mm side relief, 6 mm corner radii | [CONFIRM with structural engineer] |
| Locks | 2 tabs on the rolled front wall, 22 mm wide x 6 mm deep, into 24 x 2.5 mm base slots; 2 tabs per rolled side wall into matching slots | Working |
| Vents | 4 x Ø10 mm round, 2 per side wall (the 290 mm ends) at 20 % and 80 % of the wall length, mid-height; 8 punches on the flat (outer and inner ply), aligned exactly when rolled (v2) | [CONFIRM by 30-minute hold test] |
| Thumb notch | None. The tuck opens from the flap edge; a notch was removed in review because it weakened the front wall | Decision |
| Liner sheet | Greaseproof 40 to 50 gsm, KIT 6 or above, no added PFAS (total fluorine below 100 ppm) | [CONFIRM by strike-through test] |
| Seal label | Round kraft, 70 mm, across the lid front and front wall | Working |
| Stacking | Up to 5 boxes, hot | [CONFIRM by test] |

Note: the PDS research text says "2 per long side wall". The vents are on the side walls, which are the 290 mm ends of the box. The dieline and the photographs follow that placement.

## Print

| Item | Specification |
|---|---|
| Process | Water-based flexo post-print (production). Digital only for prototypes, or if a white-capable digital press is found |
| Inks | 2 spot colours: **WHITE OPAQUE** (single hit; reads warm cream on black kraft with fibre showing) and **EMBER** (PANTONE 485 C reference, printed over a white underlay choked 0.3 mm) |
| Dieline | Its own spot layer named **Dieline**, 100 % magenta, overprint, never printed |
| Bleed / safe | 3 mm bleed; 5 mm safe zone inside every cut and crease; print kept 6 mm clear of every cut-out |
| Type | Outlined text; Bebas Neue no smaller than 11 mm cap height for the wordmark on board; rules no thinner than 1.0 mm |
| Inside print | Lid inside: mono wordmark and "FRESH FROM THE OVEN." in one colour on the natural kraft [CONFIRM: inside print adds a pass; drop it if the quote is high] |
| Approval | Press drawdown on the real board for white opacity and for 485 over white. Never approve colour from a screen proof |

## Panels and copy

| Panel | Content |
|---|---|
| Lid | DOUGH BOSS. wordmark (cream, ember full stop) and "FEED THE WHOLE TABLE." |
| Front wall | REVESBY · BANKSTOWN · ROSELANDS and the ember hinge band |
| Side walls | Wordmark, vents |
| Back wall | "A CONTEMPORARY LEBANESE BAKERY.", "THREE SHOPS BAKING DAILY" [CONFIRM], doughboss.com.au · catering@doughboss.com.au · @doughboss, "Allergen information available on request." |
| Inside lid | Mono wordmark, "FRESH FROM THE OVEN." [CONFIRM as a printed claim] |
| Liner | "DOUGH BOSS." in a repeating ember pattern [CONFIRM: printed liner costs more; plain is the fallback] |
| Seal | FOR ____, DATE ____, tick boxes CHEESE / ZA'ATAR / MEAT / SPINACH, BOX __ OF __, filled in by hand with marker |

Never on the box: "Minis", prices, phone numbers, recycling or compostable claims, certification marks, "Since 2009" until confirmed.

## Files

| File | Contents |
|---|---|
| DoughBoss-DozenBox-Dieline-v2.pdf | Separated 1:1 print and die file: page 1 outside plates with the die, page 2 die with dimensions, legend and title block, page 3 inside print, page 4 separations proof. Spot plates WHITE OPAQUE, EMBER PMS 485 C, DIE CUT, DIE CREASE, DIMENSIONS |
| DoughBoss-DozenBox-Die-v2.dxf | Die geometry for the converter's CAD (CUT, CREASE, VENT layers, mm) |
| DoughBoss-CateringBox-TechPack-revA.pdf | The factory technical pack (send under NDA) |
| flats/*.pdf | Vector artwork per panel: lid, front, side, back, inside lid, liner, seal |
| art.py, dieline.py | The parametric source: change the internal size and every panel and the die regenerate |

The following pages show the dieline and the artwork flats.
