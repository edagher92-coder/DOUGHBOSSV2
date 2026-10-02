# Hero 3D assets: the exploded manoush

The hero on doughboss.com.au "blows out" a Lebanese manoush/pizza into its
layers (dough, crust, cheese, garnish) as the visitor scrolls. This document
covers the asset kit that animation is built from: a procedural Blender scene,
a compressed GLB for WebGL, a manifest with every node's rest and exploded
pose, a pre-rendered WebP frame sequence for devices without good WebGL, two
posters, and two clearly-labelled illustrative AI images.

Everything here was generated on 2 October 2026 (AEST) with Higgsfield 3D
Jutsu (a hosted Blender 5.2.0 LTS worker) driven by the scripts in
`tools/blender/`. No third-party models, textures or catalogue assets were
used.

> The 3D model is a stylised, generic manoush/pizza. It is not a model of any
> specific Dough Boss menu item, and nothing in it is a claim about real
> ingredients, toppings, sizes or allergens.

## File inventory

All paths are under `web/`. Sizes are bytes on disk as measured after the
final build (2 October 2026).

| File | Bytes | What it is |
| --- | ---: | --- |
| `public/hero/exploded-manoush.glb` | 515,376 | 53 nodes, 21,629 vertices, 38,250 triangles; meshopt + quantised; no cameras or lights |
| `public/hero/manifest.json` | 31,088 | Node contract, rest/exploded poses, delays, camera, frame and poster metadata |
| `public/hero/frames/lg/frame-000.webp` .. `frame-023.webp` | 768,644 total | 24 frames, 840 x 840, alpha, WebP q78 (budget 1.8 MB) |
| `public/hero/frames/sm/frame-000.webp` .. `frame-023.webp` | 519,842 total | 24 frames, 480 x 480, alpha, WebP q78 (budget 700 KB) |
| `public/hero/poster-assembled.webp` | 32,954 | 1200 x 1200, alpha, rest pose |
| `public/hero/poster-exploded.webp` | 66,686 | 1200 x 1200, alpha, fully exploded pose |
| `public/hero/ai/manoush-topdown-reference.webp` | 619,894 | ILLUSTRATIVE AI image, 2048 x 2048 (see below) |
| `public/hero/ai/manoush-topdown-reference.json` | 1,546 | Sidecar: model, prompt, job id, date, credits |
| `public/hero/ai/manoush-blowout-still.webp` | 383,258 | ILLUSTRATIVE AI image, 1920 x 1086 (see below) |
| `public/hero/ai/manoush-blowout-still.json` | 1,618 | Sidecar: model, prompt, job id, date, credits |
| `tools/blender/build_exploded_manoush.py` | 43,849 | Source of truth: builds and commits the scene (runs unchanged in `run_python`) |
| `tools/blender/render_frames.py` | 6,778 | Renders previews, the 24 frames and posters (in `query_python`) |
| `tools/blender/export_pose_data.py` | 4,028 | Packs the pose JSON into a PNG artifact (in `query_python`) |
| `tools/blender/make_manifest.mjs` | 4,814 | Decodes the pose PNG and writes `manifest.json` |
| `tools/blender/encode_frames.mjs` | 2,647 | PNG renders to alpha WebP frames/posters within budget |
| `tools/blender/optimize_glb.mjs` | 6,671 | GLB clean-up, quantise, meshopt, and contract/pose verification |

Individual lg frames are 18.7 to 40.7 KB; sm frames 12.4 to 29.0 KB (early
frames are smaller because the pizza is still compact).

## Node-name contract (do not rename)

The web app finds pieces by **node name**. These 53 names exist exactly once
in `exploded-manoush.glb` and in `manifest.json` (verified by
`tools/blender/optimize_glb.mjs`, which exits non-zero otherwise):

| Kind | Names | Notes |
| --- | --- | --- |
| peel | `Peel` | Wooden baker's peel, 0.36 m rounded-square blade + 0.55 m handle. Never moves. |
| dough | `slice0_dough` .. `slice7_dough` | Flat dough wedge, 6 mm thick. Slides out, no lift. |
| rim | `slice0_rim` .. `slice7_rim` | Blistered crust arc with charred spots and crumb-coloured cut faces. |
| topping | `slice0_topping` .. `slice7_topping` | Bubbled melted-cheese layer, about 4 mm, browned patches. |
| mint | `mint_00` .. `mint_11` | Curved leaf with creased midrib, 2.4 to 3.0 cm long. |
| chili | `chili_00` .. `chili_15` | Small curled flake, 7 to 10 mm. |

Slice `i` spans 45 degrees starting at `i * 45` degrees (0.4 degree gap
between slices), measured counter-clockwise from glTF +X towards glTF -Z
(that is, Blender +X towards +Y), seen from above.

Oregano dust, flour, embers and steam are **not** in the GLB: they are runtime
particles in the web app.

## Coordinates, units and pivots

- glTF metres, **Y up**, origin at the centre of the pizza on the peel's top
  face. The disc radius R is 0.15 m (`radiusMetres`).
- Each node's origin is its own pivot: slices pivot on their bounding-box
  centre, garnish on its vertex centroid, the peel on the origin. The GLB
  stores every node in its **rest** pose, so `node.position` and
  `node.quaternion` straight out of the loader equal `manifest.nodes[n].rest`.
- No node has a parent and no node is scaled.
- POSITION is float32 on purpose: quantising positions with gltf-transform
  rewrites node translation/scale, which would move pivots and break the
  rest pose in the manifest.

## manifest.json

`public/hero/manifest.json` is generated (never hand-edited) by
`tools/blender/make_manifest.mjs`. Shape:

```jsonc
{
  "version": 1,
  "generator": "...",
  "poseDataSha256": "...",          // integrity of the pose data it was built from
  "radiusMetres": 0.15,
  "sliceCount": 8,
  "up": "Y",
  "units": "metres",
  "glb": { "src": "/hero/exploded-manoush.glb", "bytes": 0, "compression": "EXT_meshopt_compression" },
  "camera": { "position": [x, y, z], "target": [x, y, z], "fovDeg": 54.432, "fovAxis": "...", "lensMm": 35 },
  "animation": { "maxDelay": 0.3, "...": "formula strings, see below" },
  "nodes": [
    { "name": "slice0_rim", "kind": "rim", "slice": 0, "layer": 2,
      "rest":     { "position": [x, y, z], "quaternion": [x, y, z, w] },
      "exploded": { "position": [x, y, z], "quaternion": [x, y, z, w] },
      "delay": 0.0 }
  ],
  "frames": { "count": 24, "alpha": true,
              "sizes": { "lg": { "width": 840, "height": 840, "pattern": "/hero/frames/lg/frame-%03d.webp", "totalBytes": 0 },
                         "sm": { "width": 480, "height": 480, "pattern": "/hero/frames/sm/frame-%03d.webp", "totalBytes": 0 } } },
  "posters": { "assembled": { "src": "/hero/poster-assembled.webp", "width": 1200, "height": 1200, "alpha": true, "bytes": 0 },
               "exploded":  { "src": "/hero/poster-exploded.webp",  "width": 1200, "height": 1200, "alpha": true, "bytes": 0 } }
}
```

`layer` is the stacking order bottom to top: peel 0, dough 1, rim 2,
topping 3, mint 4, chili 5. `slice` is `null` for the peel and garnish.
`pattern` uses printf `%03d` (frame 7 is `frame-007.webp`).

### Animation formula (shared by the frames and the WebGL hero)

For a global progress `p` in [0, 1] (frame `k` of `N` is `p = k / (N - 1)`):

```
t = clamp((p - delay) / (1 - maxDelay), 0, 1)       // maxDelay = 0.3
e = t * t * t * (t * (6 * t - 15) + 10)              // smootherstep
position   = lerp(rest.position, exploded.position, e)
quaternion = slerp(rest.quaternion, exploded.quaternion, e)
```

Every node therefore moves for 70% of the timeline and has finished by
`p = 1`. The delays make the blow-out ripple outward-in: the crust ring
bursts first (rim 0.00 to 0.06), the garnish leaps (0.05 to 0.14), the
cheese lifts (0.12 to 0.18) and the dough slides out last (0.20 to 0.30),
each layer sweeping around the circle. That ORDER is what keeps pieces from
passing through each other: the cheese and dough are tucked under the crust
at rest, so the crust must leave first, and garnish must clear the cheese it
sits on before the cheese rises. The build script checks this numerically
(48-step simulation): every garnish flight is rejection-sampled against the
moving crust, the moving cheese height field and every other garnish piece,
and cheese/dough surface samples are tested against the moving crust
(`sliceTimelineViolations: 0` in the committed build).

Exploded targets (R = 0.15 m): dough 0.55 R outward along its slice
bisector with no lift; rim 0.85 R out and 0.10 R up; topping 0.70 R out and
0.35 R up; garnish 0.6 to 1.1 R radially and 0.5 to 1.0 R up with a tumble of
8 to 25 degrees. All jitter is seeded (1234), so rebuilds are identical.

## Using the GLB in the web app

- It is meshopt-compressed (`EXT_meshopt_compression`, required) and uses
  `KHR_mesh_quantization` (required) for normals, UVs and colours. three.js
  ships the decoder locally: `GLTFLoader.setMeshoptDecoder(MeshoptDecoder)`
  from `three/examples/jsm/libs/meshopt_decoder.module.js`. drei's
  `useGLTF` enables meshopt by default. No CDN fetch is involved (unlike
  Draco).
- Materials are portable glTF PBR: `DB_Dough`, `DB_Crust`, `DB_Cheese`,
  `DB_Mint` (double-sided), `DB_Chili` (double-sided), `DB_Wood`. Colour
  detail (charring, cheese browning, mint veins, wood grain) is baked into
  per-vertex `COLOR_0`; base colour factors are white. If the app replaces
  materials by node name, set `vertexColors: true` on the replacement to keep
  that detail.
- `TEXCOORD_0` exists only on dough, rim and topping meshes: planar
  top-down UVs normalised to the whole disc (`u = 0.5 + x / 2R`,
  `v = 0.5 + z / 2R`, with x and z the rest-pose glTF position of the
  vertex; verified on the shipped GLB to within 1.3e-4, the 12-bit
  quantisation step), so ONE square top-down texture maps across all eight
  wedges. Image top (v = 0) is glTF -Z. UVs are clamped to [0, 1] where the
  blistered crust bulges a hair past R. No texture is bound by default (the
  validator lists these UVs as "unused", which is expected).
- Cameras and lights are stripped from the GLB; the app owns both. The
  manifest camera reproduces the render framing (vertical FOV 54.432 degrees
  for a square viewport; widen or dolly for other aspect ratios).

## Frame sequence and posters

- 24 frames, progress `k / 23`, rendered at 840 px square with a transparent
  background (Eevee, 24 samples, Khronos PBR Neutral view transform, camera
  white balance 4300 K), then encoded with alpha by
  `tools/blender/encode_frames.mjs` (sharp, WebP quality 78, alpha quality
  90): `frames/lg` is 840 px, `frames/sm` is 480 px.
- Posters are 1200 px renders of the rest (`poster-assembled.webp`) and fully
  exploded (`poster-exploded.webp`) poses with alpha.
- All renders are designed to composite over the site's near-black
  (`#070707`) with an oven-glow gradient behind them. Lighting: warm 3200 K
  key from the upper left, 4300 K rim from behind, soft 5600 K fill.
- The rendered frames add a little procedural bump (crust, cheese, dough,
  wood) and a thin glossy coat on the cheese. Those shader nodes exist only in
  the render queries; the committed scene and GLB keep the portable
  materials, so WebGL and the frames differ slightly in micro-detail.

## How to regenerate

1. **Build** (commits the scene): run `tools/blender/build_exploded_manoush.py`
   unchanged through `scene_builder_3d_run_python` on the 3D Jutsu project
   (or `blender -b -P build_exploded_manoush.py` locally with Blender 5.x).
   It is idempotent: it deletes and rebuilds the `DoughBossHero` collection.
   Check the result reports `sliceTimelineViolations: 0`.
2. **Pose data**: run `tools/blender/export_pose_data.py` through
   `scene_builder_3d_query_python`, then download the published
   `pose-data.png` (resolve it with `scene_builder_3d_get_artifact`).
3. **Frames**: run `tools/blender/render_frames.py` through
   `scene_builder_3d_query_python` with one prepended line, for example
   `RENDER_JOB = {"mode": "frames", "indices": [0, 1, 2, 3], "count": 24, "size": 840, "samples": 24}`.
   The worker has a hard **300 s** execution limit and queued operations
   expire after about 5 minutes in the queue, so render 3 to 5 frames per
   operation and submit them one at a time. Posters:
   `RENDER_JOB = {"mode": "poster", "size": 1200, "samples": 24}` renders
   both; when the worker is slow, render one per operation with
   `RENDER_JOB = {"mode": "preview", "progress": [0.0], "names": ["poster-assembled"], "size": 1200, "samples": 24}`
   (and `[1.0]` / `"poster-exploded"`), which is what the shipped posters used.
4. **Download** every published PNG into one folder as `frame-NNN.png`,
   `poster-assembled.png`, `poster-exploded.png`.
5. **Encode**: `node tools/blender/encode_frames.mjs <render-dir> public/hero`
   (from `web/`). It enforces the budgets (lg 1.8 MB, sm 700 KB) by stepping
   quality down and reports what it chose.
6. **GLB**: download the committed GLB with `scene_builder_3d_get_glb`, then
   (gltf-transform is deliberately not a web dependency):
   ```sh
   mkdir -p /tmp/gt && (cd /tmp/gt && npm init -y >/dev/null && \
     npm i @gltf-transform/core@4 @gltf-transform/extensions@4 \
           @gltf-transform/functions@4 meshoptimizer)
   GLTF_TOOLS_DIR=/tmp/gt node tools/blender/optimize_glb.mjs raw.glb public/hero/exploded-manoush.glb
   ```
7. **Manifest**: `node tools/blender/make_manifest.mjs pose-data.png public/hero`,
   then re-run step 6 with the manifest as a third argument to verify every
   GLB node still matches its manifest rest pose:
   `GLTF_TOOLS_DIR=/tmp/gt node tools/blender/optimize_glb.mjs raw.glb public/hero/exploded-manoush.glb public/hero/manifest.json`.
8. Optional: `npx --yes @gltf-transform/cli@4 validate public/hero/exploded-manoush.glb`
   and `npx --yes @gltf-transform/cli@4 inspect public/hero/exploded-manoush.glb`.

## Provenance

| Item | Value |
| --- | --- |
| Tool | Higgsfield 3D Jutsu scene builder (`scene_builder_3d_*`), hosted Blender **5.2.0 LTS**, Eevee (`BLENDER_EEVEE`), CPU-only worker |
| Project | "Dough Boss - exploded manoush hero", id `3d14a9a7-a01b-4a9e-8b13-9e25c5b25833` (private to the account owner) |
| Committed revision | 4 (operation `build-v6-002`, 2 October 2026); revisions 1 to 3 were earlier QA builds. The editable `.blend` for any revision can be fetched with `scene_builder_3d_get_blend` (not stored in the repo). |
| Build script | `build_exploded_manoush.py` v6, SHA-256 `e718ba13d0bfa4f52fecfaa3dd75966f3fecbf23617c90201e702357e932b9a2` |
| Render script | `render_frames.py`, SHA-256 `c4488d69fc34565ce6cc144626ad79b21d2ca673e94b99a083b096d3458b5c61` |
| Pose data | `pose-data.png` from operation `pose-data-r4-002`, payload SHA-256 `49bb5b33250222ce5e32419a92eda5ebf95474e551c6e405be31e6ab5f6034ea` (also stored in the manifest) |
| Raw GLB | 1,041,224 bytes from 3D Jutsu revision 4 (SHA-256 `bfcb7f4d07f3bbf0e7312c654a32722c2307bec2a28d46ae6a21da3048c172c0`), optimised to 515,376 bytes (SHA-256 `0efda41a39ba2090f360498238042c5fd74926b1beaca41c2c9337cf473f7851`) with gltf-transform 4.5.1 and meshoptimizer 1.3.0 |
| Credits | Account balance 433.75 before, 428.25 after: **5.50 credits**, exactly the two AI images (2.75 each, preflighted with `get_cost`). The 3D Jutsu scene-builder operations (4 commits and about 25 query/render operations, including failed and expired ones) consumed **0 credits** (balance was unchanged at 433.75 after the first builds and renders, and the final delta equals the image cost). No free-trial/unlimited allowance was used. |
| AI images | Higgsfield `gpt_image_2_5` (variant `flare`, quality `high`, resolution `2k`); jobs `9b0768a1-8d6e-4623-bf26-ad77f71af4c5` (top-down, 1:1) and `bfe850f2-88c6-4e08-825e-7ddd38f05b9e` (blow-out, 16:9), 2 October 2026 |

## Illustrative AI images (`public/hero/ai/`)

`manoush-topdown-reference.webp` and `manoush-blowout-still.webp` are
**illustrative AI-generated images** (Higgsfield, model `gpt_image_2_5`), not
photographs of Dough Boss products, stores or ingredients. They were made as
a texture/colour reference and a mood reference for the hero. Do not use them
anywhere a customer could read them as "what you will get" (menu, ordering,
product pages, pricing, ads) and do not present them as photography. Each has
a sidecar JSON with the model, exact prompt, job id, date, parameters and
credits.

## Licences

- `exploded-manoush.glb`, the frames, posters and `manifest.json` are
  original procedural work generated by the scripts in `tools/blender/`
  (no imported meshes, textures, HDRIs or catalogue assets). They belong to
  the project like the rest of the source.
- The two AI images were generated on the account owner's Higgsfield plan;
  use is subject to Higgsfield's and the model provider's terms of service.
  Treat them as internal references unless those terms have been checked for
  the intended use.
- Build-time tooling: Blender (GPL; output files are not covered by the GPL),
  gltf-transform (MIT), meshoptimizer (MIT), sharp/libvips (Apache-2.0 /
  LGPL-3.0).

## QA log

Five visual QA rounds, each judged on renders composited over `#070707`
against the brief's rubric (reads as a manoush; clear radial depth layers;
warm and readable on near-black; no interpenetration, black materials or
z-fighting; nothing important clipped):

1. **Overexposed**: everything washed out to peach; mint and chili lost.
   Fix: cut light power roughly 4x.
2. **Orange cast**: the 3200 K key turned the cheese peach and the crust
   orange; cheese and dough were the same colour. Fix: camera white balance
   4300 K in the renders, whiter akkawi-style cheese with stronger browning,
   darker baked dough, bigger cheese bubbles, larger garnish.
3. **Peel read pink and dominated**, and the handle sat under the crust of
   slice 0. Fix: browner, darker peel; handle swung onto the 45-degree cut so
   the exploded crust arcs straddle it; dough speckle; less saturated crust.
4. **Mid-explosion check** (p = 0.5) showed a cheese wedge lifting through
   the crust it was still tucked under (the cheese moved before the crust),
   and working through the original delays showed garnish could clip the
   crust and the dough could graze the peel's handle neck. Fix: re-ordered the ripple (crust, garnish,
   cheese, dough), flattened the peel neck below the dough's path, added the
   in-build flight simulation (garnish rejection-sampled, cheese/dough versus
   crust counted: 0 violations).
5. **Full 24-frame review** of the final sequence and posters: crust ring
   bursts first, garnish leaps, cheese lifts, dough slides; no visible
   interpenetration or z-fighting; every piece stays inside the frame.

## Limitations and honest caveats

- **Stylised, not photoreal.** Colour detail lives in vertex colours plus a
  little render-only bump; there are no image textures, no subsurface
  scattering and no cheese strings. It reads as a manoush/pizza on a dark
  page, but it will not pass for a photograph.
- **Not a real Dough Boss product.** Toppings (white cheese, mint, chili)
  follow the creative brief only; no menu item, ingredient, allergen or
  dietary claim should be inferred from the model or the AI images.
- **Garnish scale.** Mint leaves are 2.4 to 3.0 cm (the brief said about
  2 cm) and chili flakes 7 to 10 mm; both were enlarged in QA rounds 2 and 3
  because 2 cm leaves read as specks at hero size.
- **Peel handle framing.** The camera fit deliberately ignores the peel; at
  this angle the whole handle still lands inside the square frame, but a
  different crop or aspect ratio in the web app may cut it. That is
  acceptable: the pizza is the subject.
- **Rest pose is small in frame.** One fixed camera frames the fully
  exploded pose, so frame 0 and the assembled poster show the pizza at about
  45% of the frame width. Crop or scale in CSS if a tighter still is wanted.
- **Frames vs WebGL differ slightly.** The frames add procedural bump and a
  glossy cheese coat that the GLB cannot carry; the GLB relies on vertex
  colours and flat PBR factors. Lighting in WebGL is whatever the app sets.
- **Collision checks are approximate.** The crust is modelled as an
  elliptical tube and garnish as sampled vertices or spheres; checks run at
  48 steps. Visual review of all 24 frames found no interpenetration, but a
  much finer timeline could still show a sub-millimetre graze.
- **Unused UVs on purpose.** `TEXCOORD_0` has no bound texture; the Khronos
  validator reports these as informational "unused" notes (no errors, no
  warnings).
- **Worker limits.** 3D Jutsu queries are capped at 300 s and queued
  operations expire after about 5 minutes; one 8-frame batch timed out and
  four queued batches expired during this build and were re-run in smaller
  chunks. Render times varied from 21 to 76 s per 840 px frame.
- **No local Blender.** Everything ran on the hosted worker; the build script
  should also run in a local Blender 5.x, but that was not tested here.
