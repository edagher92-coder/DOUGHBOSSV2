# Pack review, round 1 (gate: every specialist scores 8.5/10 or more)
Owner bar (his words): "doesn't look real at all ... not production quality" (the old box). The new pack must read
"lifelike and professional and concept to reality as possible": real, personal, dark, expressive, contrast, photoreal,
professional but real and not perfect. Catering is the hero product: no shortfall. Brief: pack/PACK-BRIEF.md.

Open these with the Read tool (all under /tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/pack/):
- Structure and print: out/dl-all.jpg (outside print / dieline with dimensions / inside print); the vector PDF is
  out/DoughBoss-DozenBox-Dieline-v1.pdf. Flats: flats/closed-art.png (lid + front wall as read, seal applied),
  flats/inside-lid.png, flats/liner.png, flats/seal.png, flats/back.png. Source: art.py, dieline.py.
- Photography (GPT Image 2.5, references = the flats): photos/p1-hero-v.jpg (closed, 3/4), photos/p2-stack-v.jpg
  (3-box stack on counter), photos/p4-open-v.jpg (open, liner, 12 bakes), photos/p5-seal-v.jpg (macro),
  photos/p6-counter-v.jpg (shop counter), photos/p7-overhead-v.jpg (overhead stack, the hero reference).
  Superseded: p3-open (square spinach pies, black inner walls).
Known issues already logged: the photo headline renders a little heavier than Bebas Regular; p6/p7 simplify the seal
(fields missing); p6 shows a stone dome oven as mood only (not a real Dough Boss shop); "Since 2009" is held off;
box size, board and Pantone are [CONFIRM] pending the print-production research.

Return ONLY:
{"role":"...","score":0-10,"pass_8_5":true/false,
 "best_assets":["which photos/flats are usable as is"],
 "blocking":["issues keeping you below 8.5, each with the exact fix (artwork change in mm, prompt change, or retake)"],
 "nice_to_have":["optional"]}
