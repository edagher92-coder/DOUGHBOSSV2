# DoughBoss Colour Restoration Handoff

Status: completed on 2026-07-23. The shared demo and franchise palette were
restored without reverting later ordering, animation, or integration changes.

## Verified original palette

- Ink / charcoal: `#0d0d0d`
- Warm paper: `#f7f5f0`
- Ember red: `#e2231a`
- Warm orange: `#ff6a4d`
- Accessible franchise accent: `#b5571f`

## Required restoration

### `demo/demo.css`

Removed the complete late block beginning with:

```css
/* ---- black & white scheme: flip the accent to white on dark sections ---- */
```

and ending with the `.hero h1 em,#view-snow .disp em` underline rule. The
existing root variables already contain the correct red/orange values, so this
removes only the monochrome overrides and preserves all later layout, ordering,
tracking, and ingredient-animation work.

### `demo/franchise.html`

Restored the accessible warm baseline from commits `36f29f7` / `24ed1b3`:

- `--gold:#111111` -> `--gold:#b5571f`
- remove the forced white `.fhero .eyebrow,.fform .eyebrow` override
- button shadow `rgba(17,17,17,.30)` -> `rgba(192,99,46,.30)`
- hero emphasis `#ffffff` -> `var(--gold)`
- form focus white -> `var(--gold)`
- ribbon bold white -> `var(--gold)`
- inline `Register interest` eyebrow white -> `var(--gold)`

Keep white text where it provides contrast on dark buttons, headings, fields,
and confirmation surfaces. Do not turn the page into a blanket dark theme: the
original DoughBoss system intentionally combines charcoal sections, warm cream
surfaces, and controlled white cards with ember/orange accents.

## Verification

1. Load `/demo/index.html` and `/demo/franchise.html` with a cache-busting query.
2. Confirm hero emphasis, primary CTAs, active navigation, badges, ribbons,
   focus rings, and franchise highlights are red/orange rather than white/black.
3. Verify desktop and mobile widths, keyboard focus, and reduced motion.
4. Confirm no changes to ordering, preorder, WordPress, Tyro, POSPal, or KDS
   behaviour.

Completed verification:

- Both `/demo/index.html` and `/demo/franchise.html` return HTTP 200.
- Served CSS contains ember `#e2231a`, orange `#ff6a4d`, ice `#a9dcff`,
  amber `#ff9d2e`, and no monochrome override.
- Served franchise HTML contains accessible gold `#b5571f` and no retired-mono
  marker.
- `git diff --check` passed.
- Demo configuration test passed.
- Demo scope test passed: 21 passed, 0 failed.
