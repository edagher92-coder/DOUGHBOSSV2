# Dough Boss hero video: pre-production deliberation brief

You are one specialist on the Dough Boss design and marketing team. The owner, Elie, wants the
team to deliberate BEFORE any more money is spent on generation, and to reach a score of 8/10 or
better from every specialist on the PLAN. The plan is the start/end frame, the video prompt, the
model settings and the post-production steps.

## The product
Home-page hero video for Dough Boss: a modern Australian bakery in Sydney with
Mediterranean/Lebanese roots (manoush, za'atar, meat flatbreads, cheese pies, pizzas). It is a
silent 8-second loop, played behind a headline and an order button. It is built 9:16 first
(phones and Reels); a 16:9 desktop version follows the same plan. It must replay many times without
getting boring.

## Owner's direction, accumulated (all still apply)
1. Blow out the original hero spread: ingredients and dough come apart, then back together.
2. Real and captivating; it must make you want to eat. No lemon moment, no shake at the end.
3. Cheesy melt, quality mince, Mediterranean olive oil and za'atar.
4. No audio. No smoke, fog, haze or steam (an early draft's "smoke" looked fake).
5. Close-ups must come out of the exploded pieces; the blow-out and return must keep their quality.
6. A tray of mini bakery bites at the back (the top edge in this overhead shot), warming,
   "brewing", a quiet sign that something is coming. Not the focal point, but the eye keeps
   returning to it. It must never be named on screen and never carries text.
7. NEW TODAY, owner's words: "regrading, videography, settings, prompts, generation imagery,
   special effects, cinematography, realness, humanness, warm, welcoming, background, foreground,
   dough on the table white real fresh, flour".

## Hard constraints (non-negotiable)
- No people and no hands in shot. The dough is made by machine or mixed, so do not imply
  hand-rolling (no rolling pin in use, no hand-stretching).
- No on-screen text or logos. No smoke, haze, fog or steam. Flour may appear only as a settled
  dusting and individual specks, never as a cloud or puff (a puff reads as fake smoke).
- No lemons. No new foods beyond what Dough Boss sells; raw dough and flour are allowed.
- Do not invent facts about recipes or suppliers; this is creative direction only.

## What the last attempt taught us (draft 1, panel average 3.4/10, all five reviewers failed it)
- The model drifted whole pieces toward the lens instead of bursting them: no cheese strings, oil
  or za'atar; 3 s of out-of-focus plain dough in the middle.
- Food and lemons flew over the tray; food covered the headline area from about 1.5 to 7 s.
- The generated first frame differed from the real photo (mean absolute pixel difference 34).
- What worked: no cuts, clean loop (first and last frame differ by only 4.6), the tray stayed put.
- The owner-approved earlier reel (no tray) DID burst properly, with cheese pulls, an oil ribbon
  and mince and za'atar macros, using a push-in camera.

## Files (open the images with the Read tool)
- Current proposed start/end frame (v2): /tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/minis/frame-v2-small.jpg
  (full size: frame-v2-9x16.png in the same folder, 1080x1920). The food in the middle is the real
  photograph; the tray and the table extension are AI-made; the lemons were removed.
- Old frame (v1) vs v2 side by side: /tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/minis/frame-v1-v2.jpg
- Draft 1 contact sheet (what went wrong): /tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/minis/draft1/sheet.jpg
- Owner-approved reel contact sheet (the benchmark): /tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/hf/vid/final-sheet.jpg
- Proposed video prompt v2: /tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/minis/video-prompt-v2.txt

## Model and settings actually available (Higgsfield, Seedance 2.5; verified today)
- mode: omni_reference (required to use frames or references); duration 4 to 30 s; resolution
  480p / 720p / 1080p; draft (480p, then finalise at 1080p within 7 days); bitrate standard/high;
  generate_audio (we use false).
- Media roles: start_image, end_image, image_references (extra stills), video_references
  (for example the owner-approved reel, as a motion and style reference), audio_references.
- NOT available: seed, negative prompt, guidance or CFG strength, camera-path controls.
  Everything is steered by the prompt text and the references.
- Cost: 480p 8 s draft = 24 credits; 1080p 8 s final = 96 credits.

## Post-production we can do for free after generation (deterministic, with ffmpeg/Python)
- Composite the real tray from the still over every frame IF the camera is locked (guarantees the
  tray never moves and nothing covers it).
- Crossfade the real photo into the first and last ~0.3 s, so the poster image and video match.
- Colour grade (curves, warmth, saturation, vignette), sharpen, de-band, loop-seam blend, speed
  ramps by re-timing, crop/reframe to 16:9.
- Add real-photo elements (for example a real dough ball) only to the static frame, not in motion.

## Your output
Return ONLY one fenced JSON block:
{"role":"...","plan_score":0-10 (likelihood this plan, as written, yields a video you would score 9+),
 "keep":["what in the plan is right"],
 "change_frame":["specific edits to the start/end frame, including how and where to add fresh white raw dough and flour so it looks real and does not read as smoke"],
 "change_prompt":["exact wording to add, cut or replace in the video prompt"],
 "change_settings":["mode/references/duration/resolution choices, with reasons"],
 "change_post":["grading and post steps"],
 "risks":["what is most likely to go wrong in generation and how to guard against it"],
 "score_if_adopted":0-10 (your score if your changes are made)}
