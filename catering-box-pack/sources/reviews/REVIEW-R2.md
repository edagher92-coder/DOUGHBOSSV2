# Pack review, round 2 (gate: every specialist 8.5/10 or more)
Round 1: director 7.5, brand 8, DP 8, food 7.5, VFX 6.5, UX 7. Read REVIEW-R1.md and PACK-BRIEF.md (incl. the website section),
SHOTLIST-WEB.md. Everything below is under /tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/pack/.

## What changed (your round-1 blockers)
METHOD (VFX, director, brand): the generator no longer draws any packaging lettering. Box plates are generated BLANK
(unprinted black kraft) and the exact vector artwork is composited as print with printcomp.py / planes.py: per-face
homography from measured corners, opacity 0.82-0.84 single-hit white, grain displacement 2.5 px, fibre dropout,
0.4 px noisy edge, ink brightness follows the surface light. Dark ink on kraft for the inside lid. Seals are separate
photographed kraft stickers (r2/s1-s3: hand-filled 'Friday lunch', 9/10, all four ticked, BOX 1/2/3 OF 3) warped onto
the lid and wall planes, split at the bend, each with its own position (+-11 mm) and rotation (+-3 deg).
STRUCTURE (director): lid front is one 45 mm tuck flap (6 mm radii, 3 mm side relief), separate tuck panel deleted;
vents now 10 mm round at W x 0.20 and 0.80 on both wall plies; thumb notch deleted; inside wordmark mono (1-colour pass);
liner repeat carries the ember full stop; seal: wordmark line removed (brand), field caps 4.0-4.2 mm, rules measured
from labels. Dieline PDF rebuilt: out/DoughBoss-DozenBox-Dieline-v1.pdf (page 2 = dieline with dimensions).
FOOD (food stylist): flat thin discs about 85 mm x 18 mm with a low rim, packed tight, triangles alternating, plain oiled
fatayer crust (no seeds), grease halos, crumbs; seal ticks all four; date 9/10 (a Friday). Inner front/side walls black,
base and inside lid kraft (director).
LIGHT/GRADE (DP): darker warm steel and dark timber, single soft key from the left, negative fill right. One global
grade pass across the set is still to do after selection (LUT + grain), and the website derivatives come after that.
WEB (UX): native 16:9 catering hero with a calm dark left 40 percent and no printed slogan in frame (wordmark only);
4K masters for the 16:9 / 4:5 shots; four 1:1 bake tiles.
REMOVED: p6 (dome oven) and every invented setting.

## Files to judge (open with the Read tool)
- r2/contact-r2.jpg (overview)
- r2/c-b3-v.jpg  W11 catering hero 16:9 (master r2/c-b3.png, 3840x2160)
- r2/c-b4-v.jpg  W1 closed box 3/4 with seal (master 2560x3200); crop r2/c-b4-crop.png
- r2/c-b5-v.jpg  W3 open box, inside-lid print (master 2560x3200)
- r2/c-stack-v.jpg W2 stack of three, three seals (master 2048x1360)
- r2/c-macro-v.jpg W4 seal macro (2K)
- r2/t-cheese-v.jpg, t-zaatar-v.jpg, t-meat-v.jpg, t-spinach-v.jpg  W7-W10 tiles (2K). Known: spinach tile off-centre
  and cooler paper (retake or re-crop planned).
- photos/p7-comp-v.jpg overhead stack with exact print: the reference for the hero top band (seal and darker grade to add).
Known open items: the 9:16 hero plate is built in the hero task from p7 (two generated 9:16 plates failed composition);
liner print is plain in photos (printed liner is an option in the PDS); the global grade pass.

Return ONLY:
{"role":"...","score":0-10,"pass_8_5":true/false,"best_assets":["..."],
 "blocking":["issues keeping you below 8.5, each with the exact fix"],"nice_to_have":["..."]}
