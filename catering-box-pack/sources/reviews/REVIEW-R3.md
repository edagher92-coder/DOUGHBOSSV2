# Pack review, round 3 (gate: every specialist 8.5/10 or more)
Round 2: DP 8.5 (pass), brand 8.3, food 8.2, director 8.2, VFX 7.5, UX 7.5. Files under
/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/pack/. FINAL graded masters are in final/ (PNG, with
-v.jpg previews); overview final/contact-r3.jpg.

## Fixed since round 2
- SEALS (brand, VFX, director): one sticker per box, wobble applied to the whole label before the fold; split along ONE shared
  fold chord mapped into both planes (continuous ring), 30 % cap on the lid / 70 % on the wall; 1 px crease highlight + soft
  crease shadow; soft penumbra shadow (sigma 5, ~28 %) + 1 px edge line; paper ~0.78 of scene light falling to ~0.64 on the side
  away from the key. Wall-plane scale corrected for the plate's taller box (no teardrop). Stack: boxes 2 and 3 keep the
  physically correct half visible below the box above (the director confirmed this is how a real stack looks); same hand,
  FOR/DATE hidden by geometry, only BOX n OF 3 changes. Macro unchanged (it is the single-piece reference).
- INK (VFX): one shared wear map per box across lid, front and side (more wear within ~25 mm of edges and folds), grain
  displacement 2.5 -> 1.5 px, ink blur 0.4 px to match the plate, opacity 0.82 and light_gain 0.4 on every face; the ember full
  stop is at full opacity (brand).
- W11 hero (food, VFX, director): the inside lid now carries its print, shown only as the lower band (mono wordmark + allergen
  line; the slogan and sub-line sit out of frame above); the two blank background boxes are pulled into shadow.
- W3 open box (director): the generator's clustered vents are cloned out; two 10 mm vents per side re-placed at 0.20 / 0.80 of
  the wall using the photo's own hole as the stamp.
- FOOD (food stylist): masked lift on the bakes in W3 and W11: +0.4 to 0.45 stop on bake tops with a soft shoulder, greens
  separated from olive-brown (+10 % saturation, hue nudged to green), small specular roll on cheese blisters. No bloom.
- ONE GRADE (DP recipe, UX targets): grade.py applied to every master (WB fit per shot, the DP curve with black point 4 and soft
  shoulder, split tone, food saturation cap, ember kept, steel desaturated, vignette, halation, uniform mono grain last); black
  points aligned. Measured: mean luma W1 65, W2 38, W3 72, W4 49, W5 65, W11 41, tiles 68-81; 5th percentile 4.9-8.3;
  R/B 1.27-1.54 (W11 timber 1.97, kept warm per DP).
- TILES (UX, food, VFX): re-centred (cheese 1700 px, spinach 1640 px squares), paper warmed (R/B ~1.3-1.5) and darkened with
  a stronger vignette so the bake is the brightest object.
- W5 overhead (director, DP): thumb notches retouched out (top box clean; the two lower notches patched, minor residue at
  100 %), seal added at x = 322 on the front edge, steel darkened and warmed (luma 65, R/B 1.27). This is the reference for the
  home hero's top band.
Not changed: AI food remains a concept stand-in (publication gate: owner's real bakes + written packaging confirmation);
printed liner stays plain in photos (optional in the PDS); W5 lid proportion 1.23 vs 1.32 (hero band will use the lid's front
half only).

## Masters to judge
final/W11-catering-hero-16x9.png (3840x2160), W1-closed-box-4x5.png (2560x3200), W3-open-box-4x5.png (2560x3200),
W2-stack-of-three-3x2.png (2048x1360), W4-seal-macro-4x5.png (1792x2240), W5-overhead-stack-3x2.png (2048x1360),
W7-W10 tiles (1640-2048 square). Crops for seal checks: r2/c-b4-crop.png.

Return ONLY:
{"role":"...","score":0-10,"pass_8_5":true/false,"blocking":["remaining issues keeping you below 8.5, with the exact fix"],"nice_to_have":["..."]}
