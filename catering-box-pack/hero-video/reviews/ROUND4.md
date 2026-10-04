# Round 4: the owner's new box direction + all round-3 fixes (gate: everyone 8+ before any video credit)

Round 3 scores: prompt engineer 8, DP 8, brand 8 (conditions), food 7.5, VFX 7.5, UX 7.
Correction first: several of you found the bright teardrop near (828,1890) still in frame v4. My
"removed" claim was wrong. It is being fixed in the bottom-band repair below and will be re-measured.

## NEW owner decision 8 (fixed, his words): "generate and open it up with the bites at the end.
## Black, modern, relative and real. Using the logo. Professional. Stacked."
This replaces decision 7 (his own box photo) and overrides the earlier "no text or logos" rule for
the packaging only: the logo is printed on the box (part of the real object), never an overlay.
No naming, no copy, no price. Built today (images to open with the Read tool):
- Full frames (left closed, right open): /tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/box/out/v5-pair.jpg
- Bands at full width, closed above, open below: box/out/bands.jpg. Full size: box/out/frame-v5-closed.png, frame-v5-slid.png
- Phone cover-crop at 0.42 aspect (x 137 to 943): box/out/phone-crop-closed.jpg, phone-crop-slid.jpg
- Source stills (GPT Image 2.5, high): box/open-a.png, box/closed-a.png. Build script: box/build_box_band.py
How it is made:
- Three matte black rigid catering boxes, stacked slightly off-square, on brushed steel; the top box
  filled with 15 bakes (5 x 3): cheese rounds, za'atar rounds, spiced-mince rounds and spinach
  TRIANGLES (the live menu's Spinach Pie is "a triangular turnover", so variant B's square pies were
  rejected). The live catering packages already sell these minis by the dozen (site data: Morning Tea
  2 dozen, Office Platter 3 dozen, Function Spread 5 dozen). The black branded box itself is NOT yet
  confirmed as real packaging; that is asked of the owner and gates publication (see below).
- The logo is the live site's own wordmark, rebuilt exactly from the site CSS (Bebas Neue, letter-
  spacing .13em, 2 px-ratio border, ember-red full stop #e2231a), printed as white ink: 0.86 of white,
  modulated by the lid's light and card grain, 0.7 px ink edge. It is not AI-drawn.
- The box image fills the whole top band edge to edge with its own steel (VFX round 3), fitted to the
  frame's steel by one per-channel gain pinned at black (an offset fit turned the boxes grey; rejected).
  Single seam on the wooden tray's top edge (y 372 to 378). Saturation capped at 0.66, highlights rolled
  off above 205. Stack bottom at about y 335, 43 px of steel before the wood. Bakes about 112 px across
  at full size (about 9 cm if the pizza is 30 cm); box about 600 px wide (x 240 to 840, 56 percent),
  side walls inside the 0.42 crop.
- Closed state: the lid (from the edit, aligned to the open still) with the wordmark centred.
  Open state: the lid lifted a touch and slid LEFT by 42 percent of its width. Its centre of mass stays
  over the box, so it rests level on the rim; the wordmark stays fully in view (x about 172 to 384) and
  2.2 columns of bakes show. A soft shadow from the lid's right edge falls on the revealed bakes (light
  from the left), and the overhang casts a soft shadow on the steel.

## Proposed reveal mechanic and timeline (please rule on these)
- Mechanic (recommended): done in post, free and exact. From directly overhead, a lid that lifts about
  2 cm and slides sideways is a pure 2D move (a 2 percent scale change and a shadow that widens then
  tightens), so it can be animated pixel-exactly from the two stills. Ease in and out over about 0.9 s,
  shutter blur, nothing generated. Alternative: a hinged lid swinging open, which would have to be
  generated (start = closed, end = open; 15-credit draft plus a 60-credit final) and adds a seam risk
  in the top band.
- Timeline A (recommended): INTRO once per visit = closed still (poster) -> burst -> hang -> eased
  reverse -> 1.0 s hold of the reassembled spread -> lid slides open, revealing the bakes -> ends on
  the open still. Then the LOOP (continuous ping-pong while browsing) runs with the box open: open
  still hold 1.2 s -> burst -> hang -> eased reverse -> land, about 7 s per cycle, with the bakes'
  3 to 6 percent warm breath. One generated burst (G) serves both files, because the top band is
  composited from whichever still applies. The page plays intro.mp4 once and then hands off to
  loop.mp4 on an identical frame (the open still). Reduced-motion and Save-Data get the OPEN still.
- Timeline B: a single file in which the lid opens at the end of every forward pass and closes at the
  start of every reverse pass. It shows the logo more often but the bites for less time, and it adds
  lid motion every cycle.

## Publication gate (brand round 3, adapted)
Drafts are internal and the box band is a separate layer, so the drafts do not depend on the claim.
Nothing goes public until the owner confirms in writing that (a) Dough Boss uses or will use this
black branded box, and (b) the bakes shown are ones Dough Boss sells. If (a) is a no, the band swaps
back to an unbranded tray at no cost. The headline and button never mention boxes or catering.

## Round-3 fixes now in the plan
FRAME (bottom band, being rebuilt now; the v5 confirm shows the result)
- Teardrop at x 815 to 838, y 1870 to 1920 cloned out from same-row steel; nothing in x 300 to 860,
  y 1700 to 1920 brighter than 1.15 x the local median.
- The dark oval pool is deleted; the ramp is softened to 1 - 0.10 y/h; steel in the headline column is
  about RGB (82,74,68) or lighter; white text targets a median of about 7 to 8:1 with the 1st percentile
  at least 4.5:1; the page adds a CSS bottom scrim (rgba(0,0,0,.35) to 0 over 40 percent) as backup.
- Dough: alpha re-cut (1.5 px erode, 2 to 3 px feather); fringe, chip and facet repainted; hue moved
  toward about 37 degrees (G +4, B -4); the right ball lifted 6 to 8 percent; a soft crease at the A1/A2
  join; two or three subtle gas blisters per ball.
- Flour: the hollow-ring stipple is removed; only solid 1 to 4 px specks, one-sided and irregular,
  none in the headline column; no ring halo around ball B. Blue-grey smears removed.
- Top-left stamped strip: gone (the new box band replaces y 0 to 378 edge to edge with real-photo-style steel).
- The 1080p FINAL still needs the owner's real dough photo, or a texture pass he signs off at 100 percent.
PROMPT v5 (/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/minis/panel/video-prompt-v5.txt)
- "Each item peels apart: its toppings and a few thin ragged slices of base lift away, and half of every
  base stays on the tray" (replaces "tears into ragged pieces").
- "each piece builds speed over its first 0.15 s"; shadows "falling to the lower right, as in the first frame".
- Hero strings "about as thick as a pencil"; the others "fine and silky".
- Za'atar: "about forty separate dark green za'atar flakes lift while one golden olive-oil ribbon arcs
  over them and stays whole, with two or three round droplets beside it".
- Ending: "every piece stays aloft above the tray, drifting, to the last frame" ("nothing falls and
  nothing lands" removed). "steel warm brushed grey exactly as in the first frame". "generous",
  "creamy highlights" and "wood dark oiled brown" trimmed. The box line matches the open still.
SETTINGS
- start_image = frame v5 OPEN; image_references = clean plate v5 (the same band, tray empty);
  video_references = ref A. Preflight ref B (0.79 s) with get_cost before any reliance on it; if it is
  rejected or too short, loop it to at least 2 s. Up to 4 drafts (60 credits) before asking the owner
  about no reference. Credits now: 195.5 minus about 5.5 spent on box stills = about 190.
POST
- Stabilise each frame to the still first (ECC translation + scale on the steel and board-edge regions),
  then composite. Top band hard to y 352, feather 352 to 370 (wood edge at 378); bottom hard from 1406,
  feather 1396 to 1406. Wood-edge drift: 1.5 px target after stabilisation, hard fail at 3 px.
- Hook: G[0.3 s] frozen under a 0.2 s dissolve from the still, then play (burst at about 0.3 s).
  QC: mid-band MAD between the still and G at the end of the dissolve at most 14, measured AFTER the LUT.
- Reverse: smoothstep turnaround over 0.6 s AND an eased arrival over the last 1.6 s,
  g(u) = 0.3 + 3.5(1-u)^1.4 (about 3x falling to zero); shutter blend; never optical flow.
  QC: for 0.5 s after the turnaround no piece moves faster than 6 px per frame.
- One LUT fitted jointly on empty wood (clean plate) and steel; after it, the mean error in steel, wood
  and dough regions is at most 4 levels per channel, otherwise per-band masks.
- Grade: food hues 20 to 45 degrees capped at S 0.62; highlights roll off at 238; dough at 240 or below;
  no bloom or glow. Grain: one mechanism frame-wide (AV1 film-grain synthesis, strength 4 to 6, tested on
  a phone at the y 370 and y 1406 seams); H.264 gets a uniform sigma 0.8 everywhere.
- Human food checklist on the 480p draft AND its reverse scrub, all required: at least two cheese strings
  of 30 px or more long and 5 px or more thick between 1.5 and 3.0 s, one on the hero wedge; the oil
  ribbon visible for 0.6 s or more; moist mince with no scaly or tiled texture; za'atar as separate specks
  with no dense field or puff; no plain-dough close-up and no out-of-focus piece; cheese ivory to pale
  gold, never lemon yellow.
DELIVERY (UX round 3, adopted as written)
- 720x1280: H.264 at most 2.5 MB (-maxrate 3M -bufsize 6M), AV1 at most 1.3 MB. 1080x1920: H.264 at most
  5 MB, AV1 at most 2.6 MB. Desktop 1280x720: H.264 3 MB, AV1 1.5 MB; 1920x1080: H.264 5 MB, AV1 2.6 MB.
  Posters (AVIF): 120 KB / 220 KB / 200 KB; WebP and JPEG fallbacks at most 1.5x. These caps apply PER
  FILE; the intro file is budgeted at the same caps as the loop.
- Gate on the 480p draft: a 720x1280 test encode at CRF 24 must come in under 2.5 MB, trimming grain or
  hang length first, never resolution. Throttled Fast 4G: poster LCP at most 2.0 s, CLS 0, first motion
  at most 3.0 s, no stall in the first two cycles.
- The hero starts below a solid in-flow header; no viewport-fit=cover over the hero. Headline contract:
  bottom-anchored, max-width 200 CSS px centred on video x 551, at most 3 lines of 13 characters at 24 to
  26 px bold, button label at most 12 characters, bottom max(28px, env(safe-area-inset-bottom)), nothing
  above video y 1440; tested at 360x780, 390x844 and 430x932.
- Pause button, IntersectionObserver pause, mediaCapabilities codec choice, reduced-motion / Save-Data
  poster, 5-minute idle stop: as in UX's round-3 notes.

## Open scale question
Physical scale (VFX, DP, PE, food) puts the bakes at about 105 to 125 px; UX asked for at least 130.
The build uses 112 px (about 50 CSS px on a 390 px phone). The owner is being asked for the real pizza
and mini diameters in cm; the band is rescaled from those if they differ. UX: accept 112 for the drafts?

## Your task in round 4
Open v5-pair.jpg, bands.jpg, the phone crops and prompt v5. Judge the owner's new direction as built
and the full plan. Return ONLY:
```json
{"role":"...","plan_score":0-10,"pass_8":true/false,
 "box_direction_verdict":"one or two sentences: does the black branded stack work in this frame? real, modern, professional?",
 "reveal_mechanic":"post slide | generated hinged lid | other, one sentence why",
 "timeline":"A | B, one sentence why",
 "still_blocking":["only issues that keep you below 8, each with the exact fix"],
 "nice_to_have":["optional"]}
```
