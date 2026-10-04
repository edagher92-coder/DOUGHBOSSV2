# Round-4 notes on the (rejected) composited box band, to apply when the hero band is rebuilt from the pack
- Slide 36-38 % (not 42); shift the box 30-40 px right; wordmark >= 50 px inside the 0.42 crop (x 137-943) in BOTH states; all within y 0-352.
- Slide = per-frame re-composite (never a dissolve): 0.9 s smootherstep; lift h(t) 0-1 over 0-0.25 s, back 0.65-0.9 s; scale 1+0.025h;
  rim/bake shadow offset 12+16h px, blur 7+4h; overhang shadow offset (46,52)*(0.6+0.4h), sigma 20, 0.40 opacity on steel (floor 0.55 local luma), fade by y 350;
  rotation <= 0.25 deg sin(pi s); 1 px settle in the last 3 frames; 5-subframe 180-degree shutter; first/last frames bit-identical to the stills.
- Build the interior plate with the lid shadow divided out; animate the shadow layer with the lid.
- Blacks: neutralise interior shadows < luma 30 (R=G+2, B=G-1); lid ~36, walls ~18; never lift box blacks in the grade.
- Mini cheese too orange (S 0.60): cut ~20 % in the band (target ~208,168,108); vary triangles (alternate tip, +-6-10 deg), rotate rounds, nudge +-4-6 px, contact shadows; inner-wall shadow 20-30 px at 25 %.
- Flour specks on the lid/steel, ink density +-4 %, 0.4 px edge breakup, lid pebble -30 %, unsharp 0.8 px on bakes/edges, frame-wide grain, clamp top steel highlights ~165.
- Brand: publication gate needs (a) supplier proof/order/quote for the box, (b) bakes sold, (c) wordmark approved at 100 %.
- PE: shrink the spread to ~90 % so >= 55 px of wood clears each seam; prompt "burst spreads left and right"; "stay well inside the tray's top and bottom edges";
  top bullet "spread sideways across the upper half of the tray"; still-sentence must include "the boxes with their printed wordmark and the small bakes inside them";
  "the top lid slid to the left to show the small bakes inside"; "steel as in the first frame"; QC: no moving piece within 15 px of y 352 / 1406 after 1.2 s; OCR the mid band for glyphs.
- DP: make the right dough ball whole (centred near x 885) and shift the left pair right ~25 px so dough survives the phone crop.
- UX handoff protocol (two stacked videos, rVFC, open-still tail 0.6-0.8 s, parity MAD <= 1.0), combined visit caps (720: H.264 5 MB / AV1 2.6 MB; 1080: 10 / 5.2), loop fetched after intro plays 1 s,
  1080-class only when DPR >= 2.5 and downlink >= 8 Mbps, intro <= ~8 s, repeat-visit skips the intro, closed poster = LCP, reduced-motion gets the open still via <picture>.
