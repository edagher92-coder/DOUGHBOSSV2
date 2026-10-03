# Replacement photo candidates (AI-generated, not published)

**Status: candidates only.** Generated in Higgsfield on 2 October 2026 at Elie's request ("send master prompt into Higgsfield to generate better photos and fix the low res or repetitive photos"). They are **not photographs of Dough Boss products** and are **not on the live site**. Nothing here is uploaded anywhere.

Each file is a 1600 x 1280 (5:4) WebP, the size the photo audit recommends for sharp menu cards on phones. Each has a JSON sidecar with the exact prompt, model, job id and date.

## Before any of these goes live

1. **Elie checks every image against the real product**: crust style and thickness, size, portion, and every topping. The generated pizzas look like a hand-tossed chain pizza; Dough Boss crusts may be thinner or crisper. A photo that shows more than the customer receives is a misleading representation (Australian Consumer Law), so reject or re-prompt any that differ.
2. **Label policy.** The site replaced generated food art with real photography in release 2.34.0. Using these is a reversal of that policy and is Elie's decision. A real reshoot (`docs/site/photo-shotlist.md`) remains the better long-term fix.
3. **Not generated, on purpose:**
   - Coke, Coke Zero, Coke Vanilla, Fanta and Sprite: an AI bottle would invent third-party packaging. Use supplier pack shots.
   - Items with no description (Cheese, Tomato & Olives; Sujuk & Cheese; Half Meat & Cheese; Cheese Kaak; Zaatar Veggie Pizza): their contents are unconfirmed, and this brief does not invent recipes. Confirm the contents, then generate.
4. Drinks show a plain clear glass. **[CONFIRM] how each juice is actually served** (cup, bottle, glass) before using them.
5. Fix alt text and file names when uploading (quick fix 2 labels cards by item name).

## Files

| File | Menu item | Category | Replaces | Job |
|---|---|---|---|---|
| `all-meat.webp` | All Meat | Pizza | menu/all-meat.webp (300 px, one meat only) | 5ddd3a80 |
| `apple-juice.webp` | Apple Juice | Drinks | menu/juice.webp (shared by 11 drinks) | 7f7f6efe |
| `dough-boss-pie.webp` | Dough Boss Pie | Pies | real-v1/dough-boss-pie.jpg (olives shown, no chicken visible, two pies) | e3dc3435 |
| `dough-boss-wrap.webp` | Dough Boss Wrap | Wraps | menu/dough-boss-wrap.webp (300 px, bare flatbread) | c5f0411f |
| `garlic-prawns.webp` | Garlic Prawns | Pizza | menu/garlic-prawns.webp (300 px) | ff976840 |
| `labneh-veggie-pizza.webp` | Labneh Veggie Pizza | Pizza | menu/veggie-plus.webp (shared with 3 items; no labneh visible) | f2e1e903 |
| `lemon-juice.webp` | Lemon Juice | Drinks | menu/juice.webp (shared by 11 drinks) | e8f1e727 |
| `meat-manoush.webp` | Meat | Manoush | real-v1/meat.jpg (poor quality, letterbox bars) | 29d9c287 |
| `orange-juice.webp` | Orange Juice | Drinks | menu/juice.webp (shared by 11 drinks) | 764c2694 |
| `orange-mango-juice.webp` | Orange & Mango Juice | Drinks | menu/juice.webp (shared by 11 drinks) | 9f09cfcd |
| `orange-passion-juice.webp` | Orange & Passion Juice | Drinks | menu/juice.webp (shared by 11 drinks) | 2df312e6 |
| `spring-water.webp` | Spring Water | Drinks | real-v1/spring-water.jpg (third-party brand label visible) | 17dd505e |
| `sujuk-deluxe.webp` | Sujuk Deluxe | Pizza | real-v1/sujuk-deluxe.jpg (missing capsicum and mushroom; stretched as the Menu/Order/Locations hero) | 4946786d |
| `sujuk-special.webp` | Sujuk Special | Pizza | menu/dough-boss-special.webp (300 px, tight crop) | acde6492 |
| `ultimate-chicken.webp` | Ultimate Chicken | Wraps | menu/ultimate-chicken.webp (300 px, bare flatbread) | d3c1a6b0 |
| `zaatar-folded.webp` | Zaatar (folded) | Manoush | new second image: no folded manoush photo exists | a39b5d7e |

## Master prompt (menu tier, top-down)

```
Professional menu photograph for Dough Boss, a Lebanese bakery in Sydney, in the same clean, honest, appetising style as the reference photo (match its lighting, background and colour treatment, not its subject). Straight top-down view, the whole item centred with about 10 percent clear margin on every side, on a plain warm off-white seamless surface with a soft natural shadow. Soft even daylight from one side with a white bounce on the other, natural colours, no extra saturation, no vignette, sharp focus across the whole item, realistic baked texture. 5:4 frame. No text, no logos, no branded packaging, no props, no garnish that is not part of the item, no people, no hands, no watermark.
```

Per item, append `Subject: <item>. Show exactly and only these ingredients, all clearly visible: <live menu description>. Do not add any other ingredient.` Pies and wraps use "Viewed from about 45 degrees above" instead of top-down. Drinks use the drink prompt in each sidecar. Style reference image: the live `real-v1/zaatar-cheese.jpg`, passed as `image_references` (matches lighting and background, not subject).

Settings: model `gpt_image_2_5` (flare), quality high, 2k, aspect 5:4. Cost: 2.75 credits per image, 16 images, 44 credits.
