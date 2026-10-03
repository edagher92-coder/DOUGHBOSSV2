# Hero decision: no 3D

**Decision (Elie, 2026-10-02): "3D here is a no no".** The live site keeps its real-photography hero. No generated 3D, WebGL, canvas or frame-sprite hero goes on doughboss.com.au.

## What this cancels

- **WP-09** (hero WebGL bundle) and **WP-10** (hero loader, frame player, media pack) in `docs/wp/05-work-breakdown.md`.
- The `hero_enhanced` feature flag (the companion plugin ships 11 flags, not 12) and the separate `doughboss-growth-media` plugin that only existed to carry hero frames.
- The exploding-pizza hero in the original brief.

## What exists and is shelved (not deleted, not shipped)

- `web/public/hero/` (GLB, 24 alpha frames, posters, manifest, two illustrative AI stills) and `web/tools/blender/`, documented in `docs/3d-assets.md`.
- `web/tools/wp-hero/` (a built WebGL bundle and its budget tooling), finished before the decision was received.

None of it is referenced by the companion plugin. If the decision changes, the assets and tooling are ready; nothing needs to be rebuilt.

## What replaces it (real photography)

1. Fix the black strip on every inner-page hero (quick fix 1: `web/ops/live-quick-fixes/01-hero-strip.css`).
2. Replace the over-stretched hero photos (Franchising: a 300 px image stretched about 4.3 times; Locations and Menu: 550 px photos stretched about 2.3 times) with large real photos, per `docs/site/photo-shotlist.md`.
3. Give the Catering page its own hero photo instead of reusing the homepage photo.
4. The homepage hero stays core's real photo with its existing scroll effect.
