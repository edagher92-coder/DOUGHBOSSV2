# Claude × Gemini Playbook

How we use **Claude** (judgement, code, integration) and **Google Gemini** (images,
bulk content, cheap drafts) together — efficiently. Output of a 6-specialist review.
The API key lives only in `GEMINI_API_KEY` (never committed). Reusable helper:
**`scripts/gemini.py`** (`text|image`, `--model`, `--cache`, `--dry-run`).

---

## 1. Model routing — each in its strength
| Task | Use | Why |
|---|---|---|
| Codebase/architecture, refactors, interactive front-end, layout & judgement, integration | **Claude** | Holds repo context, reasons across files, respects conventions/security, integrates. |
| Image generation | **Gemini Imagen** (photoreal/hero) · **gemini-2.5-flash-image** (edits/composite) | Native, fast image synthesis. |
| Bulk structured data / variants | **Gemini Flash** | High throughput, low cost; Claude validates the schema once. |
| Alt-draft brainstorming | **Gemini Flash** | Cheap breadth; Claude curates. |
| Long-context synthesis, final client copy | **Claude** (Gemini Pro as bulk backup) | Tone, polish, no over-promising. |

**Handoff:** Claude specs the asset + writes the prompt → Gemini generates → Claude QAs, resizes, integrates.
*Example (this repo):* Claude specced 8 dish shots + prompts → Imagen-4-fast generated → Claude cropped/recompressed (480px JPEG q80) and embedded them inline in the interactive prototype.

## 2. Efficiency method (drive rebuild cost → ~$0)
1. **Prompt-hash caching** — key every result by `sha256(model + prompt + params)`; serve identical requests from `.cache/gemini/<hash>.{json,png}` = **zero API calls**. (`scripts/gemini.py` does this.)
2. **Model tiering** — iterate on **Flash / Imagen-4-fast**; promote to **Pro / Imagen-4 (or Ultra)** only for the final/hard pass (the new model changes the hash, so the final caches separately).
3. **Batch & reuse** — generate a set once, persist an `id→asset` map, and **reuse it on every rebuild** instead of regenerating. *The prototype reuses 8 cached images on each rebuild = 0 new calls.*
4. **Request hygiene** — short prompts, capped `maxOutputTokens`, one image at the needed resolution, downscale+recompress before embedding.
5. **Guardrails** — key only in `GEMINI_API_KEY`; `--dry-run` prints model + hash + cache hit/miss with no call; every real call is logged to `.cache/gemini/calls.log`.

```bash
# draft (cheap, cached)
python scripts/gemini.py image "overhead margherita pizza, dark slate, no text" --out m.jpg --max-px 480
python scripts/gemini.py text  "8 AU pizza menu items as strict JSON: {name,price_aud,category}"
# see cost/cache before calling
python scripts/gemini.py image "…" --out x.png --dry-run
```

## 3. Gemini prompt library (parameterised, reuse the same style tokens)
- **Food/product photo** *(Imagen)* — `Overhead {angle} food photograph of {dish}, on {surface}, dark slate background, dramatic side lighting, shallow DOF, photorealistic, moody editorial. No text, no logos, no hands. Aspect {ratio}.`
- **Hero/lifestyle** *(Imagen)* — `Cinematic {scene} featuring {subject}, {setting}, dark moody atmosphere, warm rim light, premium editorial, copy-space on {side}. Photorealistic. No text/logos. Aspect {ratio}.`
- **UI/social tile** *(gemini image / Imagen)* — `Minimal {format} graphic for {brand}, black-and-white, bold condensed headline "{headline}", {subject} photo, generous negative space, high contrast. Aspect {ratio}.`
- **Structured data** *(Flash)* — `Return ONLY valid JSON, no prose. Generate {count} {entity} matching {schema}. Constraints: {constraints}. Realistic AU values.`
- **Marketing/UX copy** *(Flash draft → Pro final)* — `Write {asset} for {brand} ({product}). Audience {audience}. Tone {tone}. Length {length}. Include {must_include}. Avoid {avoid}. AU English. {n} variants.`
- **Image edit/variation** *(gemini-2.5-flash-image)* — `Using the provided image, {edit_action} while keeping {preserve} unchanged. Match original lighting/perspective/style. Output one image, aspect {ratio}.`

**Prompt hygiene:** style tokens first → subject; always add negative constraints ("no text/logos") for imagery; fix the aspect ratio; ask for JSON-only when you need data; keep prompts short (tokens cost money); one job per prompt; reuse the same brand tokens for consistency.

---

## 4. Prototype review backlog (from the UX / a11y specialists)
Applied to the interactive prototype already: reduced-motion support, dialog/region semantics + Esc-to-close, `aria-live` (cart count, toasts), a payment-decline demo path (`4000 0000 0000 0002`), larger kitchen touch targets, disabled pay button during processing.

**Still open (next polish pass):**
- *Checkout:* clickable step nav without data loss; ETA as a clock time; echo shop/address in the summary; sticky total bar on mobile; working/disabled Apple-Google Pay; remove-promo affordance.
- *Kitchen board:* audible new-order chime (with an "Enable sound" tap for autoplay), SLA colour-escalating wait timer, undo on mis-tap, modifiers/notes on cards, multi-shop filter, "synced Ns ago" heartbeat.
- *A11y/perf:* convert remaining clickable `div/span` to real `<button>`; focus trap + `inert` background on overlays; `<label for>` on all inputs with `aria-describedby` errors; AA contrast + `:focus-visible`; diff-render the 4s board update; subset the Bebas font to `woff2`.
