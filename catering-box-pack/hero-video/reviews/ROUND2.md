# Round 2: the merged plan (vote before any video credits are spent)

Read the original brief first for context: /tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/minis/panel/BRIEF.md
Gate: every specialist must score this plan 8/10 or better before generation. Round 1 scores were
3-4 now and 7.5-8 "if adopted". This is the adopted plan.

## Owner decisions since round 1 (fixed; do not argue against them, design for them)
1. Use the owner-approved reel as a reference. Implemented as you advised: trimmed to its lemon-free
   food window (1.92 to 4.62 s: the cheese pull with olives, the olive-oil ribbon over za'atar, the
   mince cluster, the crust, the molten-cheese macro), passed as video_reference and limited by wording
   to food physics only. Window contact sheet: /tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/reelref/window-sheet.jpg
   (frames 1.9 to 4.4 s are the window; 1.1, 1.5 and 4.7 s onward are outside it).
2. Raw dough goes in the BOTTOM corners (two touching at lower left, one at lower right).
3. New surfaces: a realistic commercial brushed stainless steel bench; the spread sits on its real
   timber, which is now a large wooden tray with visible top and bottom edges that runs off both sides.
   This removed the AI timber extension that every round-1 reviewer flagged (orange cast, brightness
   step, mirrored and stamped knots).

## The frame (v3), built
- Start frame: /tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/minis/v3/frame-v3-small.jpg
  (full size frame-v3.png, 1080x1920, same folder). Frame and clean plate side by side: v3-and-plate.jpg (same folder).
- The food is the real photograph, pixel for pixel (lemons removed by three small patches; fidelity to
  the real photo outside the patches: mean abs difference 1.5 levels), scaled to 95 percent, pasted at
  (29,459) with a 100 px blend at top and bottom and 40 px at the sides into the AI-made wooden tray.
- Minis tray: the earlier refined tray (real za'atar and meat textures), now 240 px tall (12.5
  percent), full front rim visible below the phone status bar, glow desaturated 15 percent and 10
  percent darker. Steel bench between it and the wooden tray.
- Bottom: brushed steel, about 25 percent of the frame (y 1440 to 1920), dough balls in the two
  corners with a speckled, crisp-edged flour dusting; the centre stays clear steel for the headline.
- Clean plate (image reference 2): the same frame with the wooden tray empty: v3/cleanplate.png.

## Adopted from round 1 (with who asked)
- Locked overhead camera; composite static zones from the still in post (all six).
- Generate the OUTWARD HALF ONLY, 5 s, start_image only, and build the snap-back by reversing it in
  post (palindrome): perfect return and loop by construction, cheaper (prompt engineer, VFX). The
  8 s start=end route stays as the fallback.
- Positive wording only; no lists of smoke, haze, lemons or hands (prompt engineer, VFX, food
  stylist, brand). "Melted cheese" instead of brand or recipe words.
- Named hero beats by position; measured motion (sideways, "no more than a quarter larger", half of
  each base stays down, ring from the centre); shadows under lifted pieces; material colours
  (food stylist, VFX, DP, UX, brand).
- No flour in motion, no glisten requested from the model; the tray's "brewing" is done in post as a
  loop-safe light breath and glints (DP, VFX, food stylist, brand).
- Clean-plate image reference (DP, VFX).
- Drafts before finals; 1080p finalise only of a passing draft, bitrate high (all).

## Not adopted, and why
- Dough at the top corners (brand, UX): the owner chose the bottom corners.
- Video reference left out entirely (food stylist, DP, prompt engineer, UX): the owner chose to use
  it; it is trimmed to the lemon-free food window and limited to physics by wording.
- Push-in camera arm (DP option, prompt engineer arm B): kept as a fallback only, because it breaks
  the static composites. A gentle post punch-in of at most 1.10x on the middle band is allowed after
  review (VFX, prompt engineer).
- Lens and shutter jargon ("Phantom, 1000 fps, 100 mm f/4"; DP): the prompt engineer warned that
  cinematic camera words pull in a shallow-focus macro look, which caused draft 1's blur.
- A real phone photo of dough (brand, DP, VFX): recommended to the owner as an upgrade; AI dough is
  used for now and will be swapped if he supplies a photo.

## Settings (costs checked today)
- seedance_2_5, mode omni_reference, 9:16, duration 5, draft (480p), generate_audio false.
- start_image: frame v3. image_references: clean plate. video_references: the trimmed reel window.
- No end_image (palindrome). Cost: 15 credits per 5 s draft; 1080p finalise about 60 credits.
- Two drafts of the same prompt (no seed, so takes differ): 30 credits. Finalise only a winner that
  passes the QC gate, at 1080p, bitrate high. A second 1080p take may be kept to alternate on replay.

## Prompt v3 (full text)
/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/minis/panel/video-prompt-v3.txt

## Post (free, deterministic), in order
1. QC gate on the raw draft: no cuts; tray band and bottom band difference from the still under a
   set threshold before compositing (otherwise reject); food coverage at peak 0.8 to 1.4 times the
   still; no haze signature in empty steel areas; no lemon-yellow blobs; no piece over a sixth of
   frame width.
2. One fixed colour match (per-channel, from static regions of frame 0 to the still), applied to all frames.
3. Composite from the still every frame: top band (tray and steel, y 0 to about 330, feathered) and
   bottom band (steel, dough and flour, y 1440 down, feathered from 1400).
4. Timeline (8.0 s at 24 fps): dissolve still to G[0] (frozen) 0 to 0.35 s; play G forward; reverse
   G with an ease (fast snap-back, shutter-blur blend, no optical-flow interpolation); dissolve back
   to the still; hold to 8.0 s. First and last frame are exactly the still, which is also the poster.
5. Grade: lift blacks slightly, warm shadows and mids, neutral highlights (dough and flour stay ivory
   white), food saturation up slightly, no bloom or glow, mild vignette.
6. Tray "brewing": a loop-safe 3 to 6 percent warm light breath and a few tiny glints on the minis.
7. Fine film grain over everything, sharpen food only, encode H.264 high quality plus WebM; poster
   taken from the encoded frame 0.
8. Page build notes (later): header offset so the tray is visible, headline in the bottom quarter
   with a scrim, pause control, reduced-motion and Save-Data fall back to the poster, play twice then rest.

## Your task in round 2
Open the frame v3, the clean plate, the window sheet and prompt v3. Deliberate with the round-1
positions above in mind. Score the plan as it now stands. Return ONLY:
```json
{"role":"...","plan_score":0-10,"pass_8":true/false,
 "blocking":["only issues that keep you below 8, each with the exact fix (wording, setting or frame edit)"],
 "nice_to_have":["optional improvements that do not block"],
 "frame_v3_verdict":"one sentence on the built frame: real, warm, welcoming? any visible seam or AI tell?",
 "palindrome_vs_8s":"which route and why, one sentence"}
```

## ADDENDUM: new owner requirements (fixed), received during round 2
4. Everything is taken from photorealistic photos: the static parts (tray, steel, dough, flour) are
   composited from the photoreal still; nothing may look rendered.
5. Playback: the reel plays front-to-back and back-to-front continuously (ping-pong) for as long as
   the visitor browses the site, after the intro. (This replaces the earlier "play twice then rest"
   idea. A pause control still has to exist for accessibility, and reduced-motion users get the still.)
6. The page must be smooth, reactive and fast to load, with high-quality, photoreal output.
   Planned delivery: the ping-pong is baked into one seamless loop file (browsers cannot play video
   backwards smoothly), poster image shown instantly, video loaded after the poster, AV1/VP9 plus
   H.264 fallback, 1080x1920 for high-density phones and 720x1280 otherwise, 16:9 for desktop.
If any of this changes your score or adds a blocking item, include it in your JSON.
