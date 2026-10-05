# Round 3: confirm vote (gate: every specialist 8/10 or better before any video credit)

Context files: BRIEF.md and ROUND2.md in this folder. Round 2 scores: PE 7, Brand 7, Food 7, VFX 7.5,
DP 7, UX 7. Every blocker you raised in round 2 is listed below with what was done. Score the plan as
it now stands.

## NEW owner decision 7 (fixed): a Dough Boss catering box replaces the back tray
- The minis "brewing" along the top edge now sit in an open Dough Boss catering box instead of a
  baking tray. It is still the "something is coming" tease: never named, no text.
- It will be built from the owner's OWN real photo of the box (requested today, not yet received).
  Nothing about the packaging is AI-invented. Until the photo lands, the top band of frame v4 still
  shows the old tray; judge the rest of the frame and the plan, and judge the box from the spec below.
- Photo brief sent to the owner: phone flat and directly overhead at about 60 to 80 cm, 1x lens (not
  ultra-wide), daylight from one side, no flash; box open and filled as delivered; on the steel bench;
  no hands, no lemons, no printed tissue; whole box in frame with a hand's width of margin; 3 to 5
  frames; the original file (not a messaging-app copy). If the lid carries a logo, the lid comes off
  or folds out of shot (hero rule: no text or logos).
- Composite plan for the box (free, deterministic): scale so the small bakes match their old size
  (about 105 px across); centre the box at about 80 percent of the frame width so the front wall and
  both side walls read as a box (a box running off both sides reads as a tray); crop the back wall
  and lid hinge with the top edge of the frame; front wall at y about 230 to 250, then steel down to
  the wooden tray edge at y 378. Match its white balance and black level to the steel and wood with
  one per-channel fit; add a soft contact shadow under the front and side walls from the same upper-
  left light; cap saturation in the band at 0.66 so it never outshouts the spread. The clean plate gets
  the identical top band. QC: seam MAD under 3 levels across the 20 px blend; no halo; box edges straight.
- Prompt wording uses only "an open catering box of small bakes along the top edge".

## Round-2 blockers and what was done
FRAME (built: v4/frame-v4.png, small: v4/frame-v4-small.jpg, with plate: v4/v4-and-plate.jpg, bottom
detail: v4/bottom-v4.jpg)
- Dough read grey-blue and blotchy, holes in the earlier attempt: rebuilt with smooth masks; recoloured
  ivory (means now L 227/214/201, R 208/195/184 RGB); no holes; settled dusting only.
- Left dough pair crowded the headline column: moved 60 px left. The right ball cannot move left (its
  cropped edge would show), so it stays.
- Headline legibility on the bottom steel: steel ramps darker toward the bottom (1 - 0.20 y/h) plus a
  soft dark pool behind the headline (centre 590,1700) that never touches the dough. Clear headline
  column x 309 to 794 (485 px, centre 551). White text contrast: median 10.9:1, worst 1 percent 5.9:1,
  0 percent of pixels under 4.5:1.
- Stray bright flour fleck near (825,1880) removed; flour specks inside the pool removed.
- Top-left strip (x 0 to 26, y 0 to 205) looked wrong: replaced with real steel from lower in the frame.
- Tray glow too strong: band saturation capped at 0.66 (rule carries over to the box).
- Steel warmed very slightly (R x1.015, B x0.99) so it sits with the wood without going gold.
- Clean plate v4 = the same frame with the wooden tray empty, static bands copied from frame v4 exactly.

PROMPT (v4, full text: video-prompt-v4.txt, about 400 words, same length as v3)
- References named by content, not by order: "the start image: the full spread on a wooden tray";
  "the extra still shows the same scene with the wooden tray empty ... never a frame of the film".
- Video reference limited to physics, framing protected: "a close-up of the same foods: use only its
  food physics ... Keep this film's wide, deep-focus overhead frame: every piece small, crisp and
  separate, cheese strings pencil-thin, droplets pea-sized."
- One hero: the cheese-and-mushroom wedge nearest the camera at about 1.5x, sharp; "every other piece
  grows no more than a tenth" (QC: hero no wider than a third of the frame, others no wider than 180 px).
- Strings "stretch and thin but stay joined" (reversible, no snap). Oil is "one golden ribbon that
  arcs and stays whole, with two or three separate droplets" (no beading). "Lift off", not "spring off".
- Hang: "1.2 s to the end: a slow, weightless drift that never stops" (reverses cleanly; no freeze).
  "Every piece stays aloft above the tray to the last frame. Nothing falls and nothing lands."
- "About forty separate dark green za'atar flakes"; sesame dropped. "Tomato, olive and capsicum"
  (salami dropped: it is not on that pizza). Light: "the same soft warm light as the first frame";
  "steel neutral brushed grey"; "dough and flour ivory white"; "settled dusting of flour".
- Positive wording only; no lists of things to avoid.

SETTINGS
- seedance_2_5, mode omni_reference, 9:16, duration 5, draft 480p, generate_audio false.
- start_image = frame v5 (v4 with the real box); image_references = clean plate v5;
  video_references = ref A (approved reel 1.92 to 4.40 s, 2.5 s, lemon-free), uploaded and confirmed.
- Branch: draft A with ref A. If A fails the macro/blur gate, draft B with ref B (cheese pull 1.92 to
  2.45 s + oil 2.95 to 3.25 s, 0.79 s, also uploaded). If B fails too, stop and ask the owner about no
  video reference. If A passes, draft B = same prompt and ref A for a second take to choose from.
- Finalise only a passing draft at 1080p, bitrate high (about 60 credits). 15 credits per draft.

POST (deterministic, free)
- Hook: start G at 0.3 s with a 0.2 s dissolve from the still, so the burst lands at about 0.4 s.
- Trim G to about 3.8 s. Turnaround: smoothstep re-time over 0.6 s. Reverse at no more than 2.5x the
  average speed, shutter-blend frames, no optical-flow interpolation.
- Still hold about 1.2 s once per cycle; cycle about 7 s; ping-pong baked into one file; no duplicated
  frame at the loop point.
- Static bands composited from the still every frame: top y 0 to 366 hard, feather to 384; bottom hard
  from 1406, feather 1396 to 1406.
- Box "brewing": a 3 to 6 percent warm light breath with period equal to the cycle, no more than 2 glints.
- One fixed colour-match LUT for all frames. No temporal grain on the static bands; grain in the middle
  band sigma 0.8 to 1.0, or AV1 film-grain synthesis.
- BT.709 tags. Poster = encoded frame 0 (AVIF/WebP).

QC GATES (all must pass before finalising)
- Sharpness: Laplacian variance of the food band at least 0.6 to 0.7 of frame 0 at every point.
- Size: hero no wider than a third of the frame; others no wider than 180 px.
- Static bands before compositing: MAD under 6 and under 0.3 percent of pixels off by more than 40.
- Wooden tray edge drift no more than 1.5 px. No piece touching the wood between 2 and 5 s.
- Hang motion at least 0.15 px per frame (no freeze). Reverse-scrub check. No lemon-yellow; no haze.
- G[0] against the still: MAD no more than 14.

DELIVERY (page)
- AV1 (SVT-AV1 with film-grain synthesis) plus H.264 fallback; codec chosen with mediaCapabilities.
- 720x1280: AV1 no more than 2 MB, H.264 no more than 4 MB. 1080x1920 (high-density phones): AV1 no
  more than 4.5 MB, H.264 no more than 8 MB. Native 16:9 desktop version (own frame, draft 15, final 60).
- Poster shown instantly; video fetched after the load event; muted playsinline loop; pause button
  (WCAG 2.2.2); prefers-reduced-motion and Save-Data get the poster; IntersectionObserver pauses it
  off-screen.

REAL PHOTOS
- Requested from the owner: the catering box (above), plus, before the 1080p final if he can, an
  empty steel bench and three machine-divided dough balls on steel. Those two swap into the static
  layers and do not block the drafts. The box photo does block: frame v5 needs it.

## Your task in round 3
Open frame v4, the plate, prompt v4 and the box spec. Return ONLY:
```json
{"role":"...","plan_score":0-10,"pass_8":true/false,
 "still_blocking":["only issues that keep you below 8, each with the exact fix"],
 "box_photo_and_composite":"one or two sentences: is the brief and composite plan right? anything to add to the photo request?",
 "nice_to_have":["optional"]}
```
Score conditional on the box composite meeting the spec above; the final frame v5 will be shown to you
for a yes/no confirm before the first draft.
