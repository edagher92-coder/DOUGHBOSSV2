# Hero blow-out reel: brief, prompt and test log

Status: **9:16 final rendered (Elie approved the spend on 2 Oct 2026).** Silent, 8 s, loops.
Final job `ae223d91-205d-4617-ad0c-6156ad6d51de`, 1080x1920, high bitrate, 96 credits. The 4:3 website-hero
draft (`c78e8a8d`) is weaker, see "4:3 website-hero draft" below.
Source still: the original hero photo (Higgsfield media `9fd8c0f8-60ab-41f3-8c9a-e4321fda890c`).
Start frame and end frame are the same photo, so the reel loops.

## What Elie asked for (in order)

1. Blow out the original hero: ingredients and dough come apart, then back together.
2. Feels real and captivating, makes you want to eat. No lemon moment, no end shake.
3. Cheesy melt, quality mince meat, Mediterranean olive oil and za'atar. Modern Australian
   bakery with Mediterranean influence.
4. No audio.
5. The smoke at the start looks fake. The blow-out and the return must stay in quality and
   the mid-scene close-ups must come out of the exploded pieces.

## Direction that came out of the tests

| Finding | Evidence | Rule going forward |
|---|---|---|
| Olive-green haze over the table in the first 1.6 s reads as fake smoke | draft `fe13ff85` | Ban smoke, fog, haze, mist and "cloud" wording. Individual flakes, crumbs, seeds, droplets only |
| Asking for "appetising macros" made the model drop the blow-out and cut to a montage | drafts `ce78f0e4`, `d60fd700` | Keep the camera inside the floating pieces. Never cut to a different scene |
| Lemon wedge flying at the lens ruins the mid and end | drafts `587aed89`, `fe13ff85` | Lemon stays tiny and far from the lens |
| Empty timber for long stretches, oversized out-of-focus oil blob | draft `78297c93` | Food must fill the frame the whole time |
| Best so far: clean air, pieces float, cheese strings between toppings, mince crumbs, oil droplet over za'atar flakes, melted cheese macro, clean snap-back | draft `85fc27ce` | **Current candidate (variant A)** |

## Current candidate prompt (variant A, draft `85fc27ce-8699-4233-9ad0-1912f0b0cd68`)

Model `seedance_2_5`, mode `omni_reference`, 9:16, 8 s, `generate_audio: false`,
`start_image` and `end_image` both the hero media above.

```
Real, mouth-watering food cinematography for a modern Australian bakery with Mediterranean roots. Shot like a premium food documentary on a cinema macro lens: natural, believable, appetising, never CGI. The first and last frame are the exact reference photo, every item in exactly the same position. Warm natural window light and true-to-life colour throughout. The air stays perfectly clear: NO smoke, NO fog, NO haze, NO dust cloud, NO mist, NO particle clouds at any point; the dark timber table stays crisp and clean behind everything. Only individual real physical bits move: flakes, crumbs, seeds, droplets, strings of cheese. Only the foods already in the photo appear: spiced minced-meat flatbreads, za'atar manoush, the loaded cheese pizza, folded cheese pies. The small lemon wedges stay tiny and far from the lens, never the focus. No text, logos, people or hands.

0.0 to 0.2 s: the photo.
0.2 to 1.0 s: DETONATION in crisp slow motion. The spread blows apart outward and upward, pizza toppings and peppers peeling off the molten cheese on long glossy mozzarella strings that stretch and snap, juicy browned mince crumbs springing off the meat flatbreads, individual za'atar flakes and sesame seeds scattering, golden extra-virgin olive oil droplets glinting in the light. Real motion blur on fast pieces, physically believable.
1.0 to 3.0 s: weightless slow-motion hang with a slow push-in and rack focus from piece to piece: the cheese pull stretching, a golden ribbon of olive oil pouring over dark green za'atar, glistening mince with visible juices, golden crust edges. The camera stays among the floating pieces over the table, never cutting to an empty table.
3.0 to 4.6 s: keep gliding through the suspended food in macro, cheese bubbling, oil sheen, mince, sesame sparkling.
4.6 to 5.1 s: a held beat as everything hovers.
5.1 to 7.2 s: reverse speed-ramp SNAP-BACK, every piece rushing back onto its own base and into its original place, cheese strings retracting, oil and za'atar returning.
7.2 to 8.0 s: settles exactly onto the original photo arrangement with a soft landing, perfectly locked steady camera, no shake, holds still.

Visual rules: no smoke or haze anywhere, including the first second; no new foods or objects; no plastic or cartoon look; no lemon close-ups; no camera shake in the final second.
```

Finalised with `draft_job_id` = `85fc27ce-...` at 1080p, high bitrate: **96 credits** (standard and high
bitrate quoted the same). The finalise call needs the prompt and both reference images again, or it is
rejected with a 422 (no charge). Balance after the final and the 4:3 draft: 48.25 credits.

Frame check of the final: clear air throughout, no lemon moment, close-ups come out of the exploded
pieces, clean snap-back, locked steady end frame that matches the opening frame.

## 4:3 website-hero draft (`c78e8a8d-ede3-4efc-8173-fd2bb13c12b1`, 480p)

Same prompt at 4:3. Its first and last frames are the real hero photo (good), and the olive oil over
za'atar, charred mince flatbread and cheese macros look real. But the blow-out is far too gentle (pieces
barely lift) and the macros arrive as hard cuts to a different scene, which is the continuity problem Elie
flagged. **Not ready for the site.** Options: one more 24-credit 4:3 draft with a stronger detonation
instruction, or use the 1080p 9:16 final for social only. A 4:3 1080p final would cost about the same as the
9:16 (96 credits), which the current balance does not cover.

## Virality predictor: what it actually says

Higgsfield's predictor returns 0 to 100 proxy scores (a brain-activity model, not a measured
audience). Disclaimer in its own output: "Predictive proxy metrics, not guaranteed performance".

| Draft | Overall | Hook (0 to 3 s) | Brain engagement | Viral potential |
|---|---|---|---|---|
| `2fb4ff6d` 4:3 silent, first prompt | 46 | 29 | 37 | 47 |
| `fe13ff85` 9:16 audio, timed beats | 42 | 26 | 31 | 40 |
| `587aed89` 4:3 audio, timed beats | 43 | 27 | 33 | 41 |
| `ce78f0e4` 9:16 appetite montage | 42 | 25 | 31 | 41 |
| `85fc27ce` 9:16 variant A (candidate) | 42 | 26 | 31 | 41 |

Reading: five different prompts landed in a 42 to 46 band, so the predictor is not moving with
the creative changes tested (audio, pacing, appetite close-ups, smoke removal). Differences of
a few points are within noise. **A 9/10 (90) target is not reachable by prompt changes alone on this
evidence**, and no result here should be quoted as a predicted performance claim.
Every draft peaks at second 8 (the settle back to the still photo) and shows high default-mode
activity (lower is better) until then.

## Honesty and claims

- The reel is a stylised blow-out of the real hero photo. It is not a depiction of a specific
  product, recipe or ingredient source.
- "Quality mince", "Mediterranean olive oil" and "modern Australian" are creative direction only.
  No on-screen text says any of this. Do not caption it that way until Elie confirms the
  supplier and recipe facts (claims ledger applies).
- The 9:16 draft is re-composed by the model, so its first frame is not pixel-identical to the
  4:3 website hero. For the site hero use a 4:3 render so the start frame is the real photo.

## 4:3 redraft 2 (`6d66f510-a285-4bb5-a275-d24298fa62b6`, 480p, 24 credits, 2 Oct 2026)

Prompt change from the first 4:3 draft: one continuous shot with no cuts, a "violent" blow-out where each
piece travels at least a third of the frame, and the camera staying inside the floating food. Result:
starts and ends on the real hero photo, one continuous camera move, clear air, olive oil over za'atar,
mince crumbs and melted cheese with oil droplets all come out of the spread. Weaker points: the blow-out is
still gentler than the 9:16 version (pieces drift and tilt rather than fly apart), and one small lemon wedge
floats past the lens around 4 s. Verdict: better than the first 4:3 draft, not as strong as the 9:16 final.
Balance afterwards: 24.25 credits. A 1080p final of a 4:3 draft would cost about 96 credits, so it needs a
top-up or an upscale route; no further spend without Elie's go.

## Minis in the background (teaser), 3 Oct 2026

Elie's direction: the Mediterranean minis sit in the back of the shot, warming up, "brewing" as a sign that
something is coming. Not the focal point, but the thing the eye keeps going back to. Creative direction
only: no on-screen text, and the word "Minis" stays off every public surface (`teaser-direction.md`).

What the minis are (from our own records, not invented): the live catering packages list mini za'atar,
mixed, meat, cheese pizza and spinach minis by the dozen (`docs/wp/01-storefront-map.md` rows 538 to 540).
The tray shows mini cheese manoush, mini za'atar manoush, mini meat flatbreads and spinach triangles.

**New 9:16 start and end frame:** Higgsfield media `7b325e42-5697-4c59-8527-ef4940ab5208` (1080x1920).
How it was made:
1. Canvas 1080x1920 with the real hero photo pasted at y = 500, grey above and below.
2. `gpt_image_2_5` filled the table and added a steel tray at the top edge, mostly out of frame, in warmer
   oven light (job `7e41ecfe`), then one edit pass for deep-golden cheese minis (job `25e82fa6`). Further edit
   passes made the za'atar and meat look patterned and artificial, so that loop was stopped.
3. The za'atar and meat minis were re-textured with the real za'atar manoush and meat flatbread from the hero
   photo, re-lit to the tray's glow (so they match the full-size items in the spread).
4. The real hero photo was pasted back pixel-exact at (0, 499) with a 40 px feathered blend at the top and
   bottom seams. The middle of the frame is therefore the real photograph, not a re-render.
Image spend: 2 credits (four 0.25-credit pairs). Balance after: 21.25 credits.

**Video prompt for this frame** (not yet rendered): same structure as variant A, with two additions. The
tray never moves and is never hit by the flying food, only its cheese bubbles and its oil glints; and in
the held beat (4.4 to 5.1 s) the camera drifts up for one brief look at the tray before the snap-back.
Model `seedance_2_5`, `omni_reference`, 9:16, 8 s, `generate_audio: false`, `start_image` and `end_image` both media `7b325e42-...`.

```
Real, mouth-watering food cinematography for a modern Australian bakery with Mediterranean roots. Shot like a premium food documentary on a cinema macro lens: natural, believable, appetising, never CGI. The first and last frame are the exact reference image, every item in exactly the same position. Warm natural window light and true-to-life colour. The air stays perfectly clear: NO smoke, NO fog, NO haze, NO steam, NO mist, NO particle clouds at any point; the dark timber table stays crisp. Only individual real physical bits move: flakes, crumbs, seeds, droplets, strings of cheese. No text, logos, people or hands. The small lemon wedges stay tiny and far from the lens.

THE TRAY AT THE TOP: a dark steel tray of fresh mini bakery bites sits at the top edge of the frame in a pool of warm amber oven light. It NEVER moves, never blows apart and is never touched by the flying food: it stays exactly in place for the whole clip, quietly alive, the cheese on the golden cheese minis gently bubbling and glistening, olive oil sheen catching the light on the za'atar minis, crisp golden pastry edges on the spinach triangles. It is always visible but never the centre of the frame: something waiting.

0.0 to 0.2 s: the image, still.
0.2 to 1.0 s: DETONATION of the main spread only, in crisp slow motion. The flatbreads, pizzas and folded pies in the middle blow apart outward and upward, toppings peeling off molten cheese on long glossy mozzarella strings that stretch and snap, juicy browned mince crumbs springing off the meat flatbread, individual za'atar flakes and sesame seeds scattering, golden extra-virgin olive oil droplets glinting. Real motion blur, physically believable. The tray at the top stays perfectly still.
1.0 to 3.0 s: weightless slow-motion hang with a slow push-in and rack focus from piece to piece: the cheese pull stretching, a golden ribbon of olive oil pouring over dark green za'atar, glistening mince, golden crust edges. The camera stays among the floating pieces, never cutting.
3.0 to 4.4 s: keep gliding through the suspended food in macro, cheese bubbling, oil sheen, sesame sparkling.
4.4 to 5.1 s: a held beat as everything hovers. The camera drifts gently up past the floating pieces toward the glowing tray for one brief, tempting look at the mini bites, bubbling cheese and oil sheen in warm light, then eases back.
5.1 to 7.2 s: reverse speed-ramp SNAP-BACK, every piece of the main spread rushing back onto its own base and into its original place, cheese strings retracting, oil and za'atar returning. The tray never moves.
7.2 to 8.0 s: settles exactly onto the original image with a soft landing, perfectly locked steady camera, no shake, holds still. The tray still glows at the top.

Visual rules: one continuous shot, no cuts; no smoke, steam or haze anywhere; no new foods or objects; the tray and its minis never fly, move or change; no plastic or cartoon look; no lemon close-ups; no camera shake in the final second.
```

A 9:16 8 s draft costs 24 credits, more than the balance,
so it waits on a top-up and Elie's go. After a draft passes the team rating page, the 1080p final is
about 96 credits.

**Honesty notes:** the minis on the tray are AI-made (with real Dough Boss textures on two of them). Swap
in a real photo of the minis as soon as one exists. A visible tray of minis is a product hint, so this
version should only go public once Elie is happy for the teaser to show product.

**Not done yet:** a 16:9 desktop version of the frame (tray along one edge), the video draft, and the
team rating.
