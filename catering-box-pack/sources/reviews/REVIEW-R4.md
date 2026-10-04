# Pack review, round 4 (gate: every specialist 8.5/10 or more)
Round 3: brand 8.6 PASS, UX 8.6 PASS, DP 8.3, food 8.3, director 8.3, VFX 8.2. Masters in final/ (overview final/contact-r4.jpg),
seal crops r2/c-b4-crop.png and r2/c-stack-crop.png, all under /tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/pack/.
## Fixed since round 3
- SEAL FOLD (VFX, director, brand, DP): the fold chord is now computed once and both halves are forced onto the SAME two averaged
  photo points (was ~12 px apart because the two faces' perspective spaces the edge differently); lid cap reduced to 25 % so the
  FOR line clears the fold; per-wall vertical scale computed from the measured face (equal px/mm on both axes) so every seal is
  round (was egg-shaped on W2 after round 3). Stack boxes 2-3: seal tucked 2 px below the lip of the box above with an 8 px
  ambient-occlusion band; softer shadow (20 %, sigma 6.5) and a near-invisible edge line (11 %).
- FLUTE TELL (director): the exposed flute along the lid front edge is retouched into a closed rounded fold (highlight, contact
  shadow, a few fold cracks) on W4 (left of the seal) and on W5's top box (the hero band source). W5 stays an internal reference:
  only the top box's front half is used in the hero band; it is not a published full-frame image.
- FOOD (food stylist, DP): rebuilt from the clean plates with a gentler masked lift (+0.25 W3 / +0.3 W11 stop), 25 % less local
  contrast on the bakes, crust saturation -8 %, cheese specular halved; W11 spinach filling treated to read as chopped cooked
  spinach (leaf ribs removed, fine chopped texture, glossier and darker). W3 vignette eased to -12 %, kraft lid lifted.
- TILES (food, UX): vignette reduced ~35 %, paper warmer; R/B now 1.36-1.6, mean luma 65-75, centred.
- W5 (VFX, DP): key light back on the lid (+0.32 stop, ramp brighter toward the window), wall left to fall off, steel sepia pulled
  out (R/B 1.16), seal paper brighter.
Unchanged by design: AI food is a concept stand-in (publication gate: owner's real bakes + written packaging confirmation);
W4's label position (generated macro) stays 5 mm from the corner, logged for the real-sample shoot.
Return ONLY: {"role":"...","score":0-10,"pass_8_5":true/false,"blocking":["..."],"nice_to_have":["..."]}
