# wp-hero: hero WebGL bundle and asset build (WP-09)

Reproducible, budgeted, integrity-pinned build of the tier-2 hero for the `doughboss-growth`
companion plugin (`docs/wp/00-architecture-extend-wordpress.md` section 3.3, package WP-09 in
`docs/wp/05-work-breakdown.md`). Nothing here is loaded by WordPress directly: WP-10 copies a
finished `dist/` into the plugin (`public/vendor/`) and the media plugin.

Everything is **off by default** in the plugin; this directory only produces files.

## What it builds

| Output (`dist/`) | What |
| --- | --- |
| `hero-webgl.<sha8>.js` | ESM, target ES2018, minified. three.js (WebGLRenderer) + GLTFLoader + meshopt decoder only. Exports `boot`. |
| `hero-scene.<sha8>.glb` | Byte-for-byte copy of `public/hero/exploded-manoush.glb`. |
| `frames/{sm,lg}/<sha8>-NN.webp` | **Only** when `tools/wp-hero/frames-approval.json` records an owner approval (see below). Otherwise none. |
| `hero-manifest.json` | `files[]` (`role`, `path`, `bytes`, `sha384`), `three_version`, `esbuild_version`, `source_sha256`, `built_at`, `sources[]`, byte budgets and `confirm[]` gaps. |

`sha384` is a ready-to-use SRI token (`sha384-<base64>`): put it straight into an `integrity`
attribute or `fetch(url, { integrity })`. `<sha8>` is the first 8 hex characters of the file's SHA-256.

The AI stills in `public/hero/ai/` are illustrative and are never read, copied or referenced; the
build refuses them as a frame source and the tests grep every output for them.

## Commands (run from `web/`; never `npm install`)

```sh
node tools/wp-hero/build.mjs                  # build into tools/wp-hero/dist/
node tools/wp-hero/build.mjs --check          # rebuild in a temp dir, byte-compare with dist/
node tools/wp-hero/build.mjs --out /tmp/x --frames-approval path/to/frames-approval.json
SOURCE_DATE_EPOCH=1790000000 node tools/wp-hero/build.mjs   # stamp built_at (otherwise 1970-01-01T00:00:00Z)
node_modules/.bin/vitest run tests/unit/wp-hero-       # unit + build tests
node tools/wp-hero/harness/run.mjs            # real Chromium run of a fresh build (software GL)
```

Reproducibility: the output is a pure function of the sources, the pinned toolchain
(three 0.186.1, esbuild 0.28.2; the build refuses any other version) and `SOURCE_DATE_EPOCH`. The
clock is never read, `built_at` defaults to a fixed placeholder, the repository `tsconfig.json` is
not consulted, and no absolute path reaches the output. `--check` reads `built_at` from the existing
manifest so only real differences show up.

## Byte budgets (fail the build)

| Item | Limit | Current |
| --- | --- | --- |
| JS raw | 650 KiB (665,600 B) | see `hero-manifest.json` `budgets` |
| JS gzip -9 | 170 KiB (174,080 B) | see `hero-manifest.json` `budgets` |
| GLB | 550 KiB (563,200 B) | 515,376 B |
| frame | 60 KiB each, 24 per breakpoint, sm total 600 KiB, lg total 1,200 KiB | only when frames are included |

"KB" is read as KiB. With 1000-byte KB the bundle (three's renderer, GLTFLoader and the meshopt
decoder) is a few KB over 650,000 and cannot be trimmed without patching three; change `KB` in
`budget.mjs` to 1000 to enforce the stricter reading (the build then fails). Raised as a
contract-change request: `scripts/budgets.php` (WP-10/WP-01) must use the same reading.

## Frames need Elie's decision (blocking owner gate)

The 24-frame sets in `public/hero/frames/{sm,lg}` are renders of the procedural 3D scene (stylised
art, not photography). Per `00 section 3.3` they may ship only if they are real photographed layers
or Elie approves the stylised art. The build therefore includes frames only when
`tools/wp-hero/frames-approval.json` exists:

```json
{ "approved": true, "approved_by": "<name>", "approved_on": "YYYY-MM-DD",
  "scope": "real-photo | owner-approved-stylised-render", "source_dir": "public/hero/frames" }
```

This file is deliberately not created here. `source_dir` is relative to `web/` and may never be the
AI stills directory.

## Runtime API (`boot`)

```js
import { boot } from "./hero-webgl.<sha8>.js";
const hero = boot(canvas, manifest, { onReady, onFrameBudgetExceeded });
```

`manifest` is `hero-manifest.json`, optionally with `baseUrl` added by PHP (prefix for file paths).
Hooks and options (all optional): `onReady({ renderer: "webgl2", medianFrameMs, sampleFrames })`,
`onFrameBudgetExceeded(medianMs, stats)`, `onError({ code, message })`, `onComplete()`,
`frameBudgetMs` (24), `sampleFrames` (60), `glb` (already fetched, integrity-checked `ArrayBuffer`;
verified again with SHA-384), `baseUrl`, `size`, `durationMs` (1600), `respectReducedMotion` (true).

Controller: `state`, `stop()` (disposes the renderer and context, idempotent), `pause()`/`resume()`
(Stop button), `play()`, `replay()`, `setProgress(p)` (scroll-linked), `getStats()`.

Behaviour:

- Loads the scene file from `baseUrl + path` with `fetch(..., { integrity: sha384 })`; a size or hash
  mismatch, a missing integrity value, a failed fetch, reduced motion, WebGL2 missing, or a GLB that
  does not match the node-name contract and rest poses ends in a disposed, inert controller and one
  `onError` (never a half-working scene).
- Frame-time guard: after the GLB loads it renders 60 probe frames (sweeping the explosion), takes the
  median of the frame-to-frame deltas and, over 24 ms, calls `onFrameBudgetExceeded(median)` and
  disposes itself; otherwise `onReady` reports the median.
- No autonomous loop: frames are produced only while probing, playing, or after a pose change. Nothing
  renders while the tab is hidden (`visibilitychange`), the canvas is out of view
  (IntersectionObserver) or the user paused. Long gaps are clamped so animation never jumps.
- The canvas must have layout size during the probe (use `visibility: hidden`, not `display: none` or
  the `hidden` attribute, which also stops IntersectionObserver from reporting it as visible), or pass
  `size`.
- Pose maths are `docs/3d-assets.md` "Animation formula" (smootherstep per node, lerp/slerp), compiled
  in from `public/hero/manifest.json` (validated against the 53-name contract at build time).

## Layout

| File | Role |
| --- | --- |
| `entry.ts` | Bundle entry, wires the browser dependencies, exports `boot` |
| `boot-core.ts` | Lifecycle (load, integrity, probe, pause, dispose); dependency-injected so tests run it with fakes |
| `scene.ts` | three.js scene: GLTFLoader + meshopt, lights, camera, per-node poses, disposal |
| `pose.ts`, `stats.ts` | Pure maths and frame statistics (no three.js) |
| `contract.mjs` | The 53 node names, pose-manifest validation, compact pose data |
| `manifest.mjs`, `budget.mjs` | hero-manifest generation/validation/verification, byte budgets |
| `build.mjs` | The build, `--check`, frame-approval gate |
| `harness/` | Scratch HTML + Playwright runner (never in the plugin) |
| `*.d.mts`, `types.d.ts` | Types for the `.mjs` modules and the virtual `hero-poses` module |
