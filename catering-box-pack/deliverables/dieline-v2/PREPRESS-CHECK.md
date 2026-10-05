# PREPRESS-CHECK: DB-CAT-DOZEN dieline v2, rev B (draft), 3 Oct 2026

Files checked: `DoughBoss-DozenBox-Dieline-v2.pdf`, `DoughBoss-DozenBox-Die-v2.dxf`. Tools: reportlab 5.0.1, pikepdf, ezdxf, poppler (pdfinfo, pdffonts, pdftoppm), Ghostscript 10.02.1 (`tiffsep`). Everything below was measured on the delivered files by `verify_v2.py`; nothing is copied from the design intent.

Result summary: ALL MEASURED CHECKS PASS. Open items are listed in section 9.

## 1. Page boxes (`pdfinfo -box`)

| Page | MediaBox (pt) | TrimBox (pt) | BleedBox (pt) | Meaning |
|---|---|---|---|---|
| 1 | 0.0 0.0 1938.3 2894.2 | 127.6 552.8 1810.8 2766.6 | 119.1 544.2 1819.3 2775.1 | outside print, all plates + die |
| 2 | 0.0 0.0 1938.3 2894.2 | 127.6 552.8 1810.8 2766.6 | 119.1 544.2 1819.3 2775.1 | die only, dimensions, legend, title block |
| 3 | 0.0 0.0 1938.3 2894.2 | 127.6 552.8 1810.8 2766.6 | 119.1 544.2 1819.3 2775.1 | inside print, viewed from inside |
| 4 | 0.0 0.0 5815.0 5363.1 | 28.4 453.5 5786.6 5334.8 | 19.8 445.0 5795.1 5343.3 | separations proof, 6 plates at 1:1 (proof sheet, not a print page; boxes enclose the plate grid) |

Page 1 TrimBox = 593.8 x 781.0 mm (the blank). BleedBox = TrimBox + 3 mm each side (599.8 x 787.0 mm). Neither equals the MediaBox, which carries crop marks, registration targets and the information strip. ArtBox = TrimBox. PDF version 1.6. Result: **PASS**.

## 2. Separations in the PDF

Separation (spot) colourants found in the page resources (pikepdf):

| Colourant | Role | Paint operations (overprint ON / OFF) |
|---|---|---|
| `All` | crop and registration marks (every plate) | 184 / 0 |
| `DIE CREASE` | creases (dashed) | 64 / 0 |
| `DIE CUT` | cut, slots, vents | 64 / 0 |
| `DIMENSIONS` | non-printing information | 602 / 0 |
| `EMBER PMS 485 C` | ember full stops and hinge bands | 14 / 0 |
| `INSIDE DARK` | optional inside-lid print (placeholder colour) | 0 / 122 |
| `PREVIEW BLACK KRAFT` | non-printing board preview, outside | 0 / 3 |
| `PREVIEW NATURAL KRAFT` | non-printing board preview, inside | 0 / 2 |
| `WHITE OPAQUE` | white artwork + white underlay | 0 / 522 |

Exact required names present in the file (content/resources grep): `WHITE OPAQUE`: yes, `EMBER PMS 485 C`: yes, `DIE CUT`: yes, `DIE CREASE`: yes, `DIMENSIONS`: yes.

RGB: **0** RGB colour operators in all page content (v1 was RGB throughout). DeviceCMYK / DeviceGray colour operators used for painting: **none**. Every painted object is a spot colour (the board preview is its own spot, so it can never land on a process plate).

Overprint, read from the content streams (graphics-state tracker over `gs` / `cs` / paint operators; `/op` fill and `/OP` stroke):

- `DIE CUT`: 64 painted with overprint ON, 0 with overprint OFF. **PASS**
- `DIE CREASE`: 64 painted with overprint ON, 0 with overprint OFF. **PASS**
- `DIMENSIONS`: 602 painted with overprint ON, 0 with overprint OFF. **PASS**
- `All`: 184 painted with overprint ON, 0 with overprint OFF. **PASS**
- `EMBER PMS 485 C`: 14 painted with overprint ON, 0 with overprint OFF. **PASS**
- `WHITE OPAQUE`: 0 ON, 522 OFF (knockout by design; painted first; nothing printed beneath it).
- `INSIDE DARK`: 0 ON, 122 OFF (knockout by design; painted first; nothing printed beneath it).

reportlab 5.0.1 supports overprint natively: `canvas.setFillOverprint()` writes `/op` and `canvas.setStrokeOverprint()` writes `/OP` into an ExtGState; `setOverprintMask()` writes `/OPM`. The ExtGState resources and `gs` operators are present in the file (see counts above). EMBER overprint is deliberate: with a knockout the ember paint would erase the white underlay on the WHITE plate (control test in section 4).

Layers (optional content): BOARD PREVIEW (non-printing), WHITE OPAQUE, EMBER PMS 485 C, INSIDE DARK, CUT, CREASE, VENT, DIMENSIONS (non-printing), MARKS. Non-printing (Print usage OFF): BOARD PREVIEW (non-printing), DIMENSIONS (non-printing). Note: Ghostscript ignores Print-usage flags, which is why the previews are spot colours and not relied on to hide.

Fonts (`pdffonts`): all embedded as TrueType subsets (**PASS**); the non-embedded default Helvetica of v1 is gone. Legend states: fonts embedded; outline before plate making if required.

```
name                                 type              encoding         emb sub uni object ID
------------------------------------ ----------------- ---------------- --- --- --- ---------
AAAAAA+BebasNeue-Regular             TrueType          WinAnsi          yes yes yes     38  0
AAAAAA+BarlowCondensed-SemiBold      TrueType          WinAnsi          yes yes yes     39  0
AAAAAA+Barlow-Medium                 TrueType          WinAnsi          yes yes yes     40  0
AAAAAA+Barlow-Bold                   TrueType          WinAnsi          yes yes yes     41  0
AAAAAA+Barlow-SemiBold               TrueType          WinAnsi          yes yes yes     42  0
```

## 3. Separation render (Ghostscript 10.02.1 `-sDEVICE=tiffsep`)

Pages 1 to 3 rendered at 50 dpi (clearances at 127 dpi, choke at 254 dpi). Plates produced and ink pixel counts at 50 dpi:

| Page | Plate | Ink px |
|---|---|---|
| 1 | Black | 435 |
| 1 | Cyan | 435 |
| 1 | DIE CREASE | 5799 |
| 1 | DIE CUT | 7801 |
| 1 | DIMENSIONS | 14681 |
| 1 | EMBER PMS 485 C | 15876 |
| 1 | Magenta | 435 |
| 1 | PREVIEW BLACK KRAFT | 1402009 |
| 1 | WHITE OPAQUE | 109473 |
| 1 | Yellow | 435 |
| 2 | Black | 435 |
| 2 | Cyan | 435 |
| 2 | DIE CREASE | 5799 |
| 2 | DIE CUT | 7801 |
| 2 | DIMENSIONS | 28274 |
| 2 | Magenta | 435 |
| 2 | Yellow | 435 |
| 3 | Black | 435 |
| 3 | Cyan | 435 |
| 3 | DIE CREASE | 5790 |
| 3 | DIE CUT | 7801 |
| 3 | DIMENSIONS | 14860 |
| 3 | INSIDE DARK | 22630 |
| 3 | Magenta | 435 |
| 3 | PREVIEW NATURAL KRAFT | 1488852 |
| 3 | Yellow | 435 |

Process plates (Cyan, Magenta, Yellow, Black) hold only the registration marks (colourant All maps to every plate). Ink pixels inside the BleedBox, per page [inside bleed / total]: p1 Cyan 0/435, Magenta 0/435, Yellow 0/435, Black 0/435; p2 Cyan 0/435, Magenta 0/435, Yellow 0/435, Black 0/435; p3 Cyan 0/435, Magenta 0/435, Yellow 0/435, Black 0/435. Result: **PASS** (no process ink inside the box).

Expected plates by page: page 1 WHITE OPAQUE, EMBER PMS 485 C, DIE CUT, DIE CREASE, DIMENSIONS (+ PREVIEW BLACK KRAFT, non-printing); page 2 DIE CUT, DIE CREASE, DIMENSIONS; page 3 INSIDE DARK, DIE CUT, DIE CREASE, DIMENSIONS (+ PREVIEW NATURAL KRAFT). Page 4 is the proof sheet. Contact sheets of the plates: `proof/page1-plates.png`, `proof/page3-plates.png` (ink = black); page renders at 30 dpi `proof/page*.png`.

## 4. White underlay and ember (choke 0.3 mm), measured on page 1 at 10 px/mm

- Hinge band (largest ember object): ember 364.2 x 10.0 mm; white underlay inside it 363.6 x 9.4 mm. Expected for the lid and back bands together: ember 364.2 x 10.0, white 363.6 x 9.4 (choked 0.3 mm at both ends and the free edges, flush at the hinge crease).
- Full stop on the lid: ember 9.70 x 9.70 mm, white 9.10 x 9.10 mm (expected white = ember minus 0.6 mm in each direction, resolution 0.1 mm).
- Overprint control: with EMBER overprint ON, 351209 px of WHITE sit under EMBER (93.6 % of the ember area, the rest is the 0.3 mm choke rim and the wordmark stops' choke). With EMBER set to knockout (control build, not delivered) only 2253 px do (0.6 %): the white underlay would be erased. Result: **PASS**.

## 5. Vent alignment arithmetic (side walls, centres after rolling)

Model: the inner ply folds 180 degrees about the roll fold and lies on the inside of the outer ply, so a point at distance d from the roll fold lands at u = H - d from the base crease. H = 50 mm, t = 1.6 mm. Bend and caliper allowances are left to the converter's CAD.

| Version | Wall | y (mm) | Outer: u = H/2 | Inner on the flat: d from fold | Inner rolled: u = H - d | Offset |
|---|---|---|---|---|---|---|
| v1 | left | 58.0 | 25.0 | 24.2 | 25.8 | +0.800 |
| v1 | left | 232.0 | 25.0 | 24.2 | 25.8 | +0.800 |
| v1 | right | 58.0 | 25.0 | 24.2 | 25.8 | +0.800 |
| v1 | right | 232.0 | 25.0 | 24.2 | 25.8 | +0.800 |
| v2 | left | 58.0 | 25.0 | 25.0 | 25.0 | +0.000 |
| v2 | left | 232.0 | 25.0 | 25.0 | 25.0 | +0.000 |
| v2 | right | 58.0 | 25.0 | 25.0 | 25.0 | +0.000 |
| v2 | right | 232.0 | 25.0 | 25.0 | 25.0 | +0.000 |

v1: inner centre at the middle of the inner panel's own 48.4 mm span, d = (H - t)/2 = 24.2, so u = 50 - 24.2 = 25.8 and offset = 25.8 - 25.0 = +0.8 mm (= t/2). v2: require u_in = u_out, so d = H - H/2 = 25.0 mm from the roll fold, i.e. x = -H - 25 = -75.0 (left) and L + H + 25 = 460.0 (right); u = 50 - 25.0 = 25.0. Maximum offset v1 0.800 mm, v2 0.000 mm. Along the wall both plies use y = 58.0 and 232.0 (20 % and 80 % of 290): the inner ply is attached along the roll fold so y does not change. The arithmetic is also in the comment in `dieline.py`. Result: **PASS**.

## 6. Blank size and features (from the geometry)

- Blank (bounding rectangle of the die outline): **593.8 x 781.0 mm** (x -104.4 to 489.4, y -104.4 to 676.6). Matches the pinned working die 593.8 x 781.0 (PASS). Minimum printed sheet with 3 mm bleed: 599.8 x 787.0 mm.
- Internal size 385 x 290 x 50; board caliper t = 1.6 (E-flute, working value).
- Panel sizes (mm): base 385.0 x 290.0; front 385.0 x 50.0; front_inner 381.8 x 48.4; back 385.0 x 50.0; lid 388.2 x 291.6; lid_front 382.2 x 45.0; side_left 50.0 x 290.0; side_left_inner 48.4 x 286.8; side_right 50.0 x 290.0; side_right_inner 48.4 x 286.8.
- Tuck flap depth 45.0; lock tab (front inner) 22 x 6 (2 front tabs found; right-side inner tabs [(22.0, 6.0), (22.0, 6.0)] (width, depth), 2 per side); lock slots [(2.5, 24), (24, 2.5)] (6 off); vent diameter [10.0] (8 punches on the flat, 4 on the box).
- Tuck side relief 3 mm and corner radius 6 mm are constants in `dieline.py`; the 6 mm radius is approximated by 6 chords per quarter-circle in both the PDF and the DXF (sagitta about 0.05 mm). [CONFIRM: converter to replace with true arcs in CAD.]

## 7. Clearances: print against vents, slots, creases (raster, from the separated plates)

- Page 1 (WHITE + EMBER ink): nearest ink to any of the 14 cut-outs (8 vents, 6 slots) is **10.8 mm** (resolution 0.20 mm; requirement at least 6 mm): **PASS**.
- Page 3 (INSIDE DARK ink, mirrored view): nearest ink to a cut-out **123.1 mm**: **PASS**.
- Safe zone, page 1: nearest art ink to any cut or crease line, excluding the two hinge bands that sit on the hinge crease by design, is **8.2 mm** (at x 260, y -8); requirement 5 mm: **PASS**. The hinge bands themselves touch the hinge crease by design (min distance 0.0 mm); the converter should confirm that is acceptable at the fold.

## 8. DXF read-back (`ezdxf`)

- Units: $INSUNITS = 4 (4 = millimetres). Layers: CUT, CREASE, VENT. Entities: {('LWPOLYLINE', 'CUT'): 7, ('CIRCLE', 'VENT'): 8, ('LINE', 'CREASE'): 15}.
- Extents: x -104.4 to 489.4, y -104.4 to 676.6 = 593.8 x 781.0 mm, same origin and orientation (outside view, y up) as the PDF. Result: **PASS**.
- 8 vent circles on layer VENT match the 8 vent holes in the PDF geometry (centres and diameters): **PASS**. CUT: outline polyline + 6 slot rectangles. CREASE: 15 lines, linetype DASHED.
- Both files are generated from the same `dieline.build()` object in one run of `build_v2.py`. Not verified: that a particular CAD (ArtiosCAD, Kongsberg) imports the layers with the intended line types.

## 9. Not verified or open

- No physical proof, press check or converter CAD import was done. The die is a working structure: internal size (fit test with 12 bakes), caliper, flute direction, crease rule, tab/slot and tuck values, and bend allowances are [CONFIRM] with the converter's structural engineer before tooling.
- Ember alternate CMYK is a screen simulation of PANTONE 485 C, not a measured match [CONFIRM: drawdown]. White alternate is a cream screen colour only.
- `INSIDE DARK` is a placeholder spot name; the inside-lid colour and whether to print it are undecided (tech pack gap 13) [CONFIRM].
- Registration marks use the colourant `All`; mark positions, gripper edge, sheet layout and trapping values are [SUPPLIER TO PROPOSE]. The PDF is not PDF/X.
- Art changes from v1, colour model and plates only: choke now exactly 0.3 mm (v1 about 0.19 mm on the full stop); the wordmark full stop now has its white underlay (v1 ember direct on black); the back-wall hinge band underlay is choked on the free edge, not the hinge edge. Copy and positions are unchanged.
- The seal label is not on this die; `art.py` still holds it in RGB and it needs its own label file.
- Dimensions on page 2 are drawn from the geometry; the converter should still verify the dimension scale on the first plot (print page 2 at 100 % and measure the 385 mm base).
