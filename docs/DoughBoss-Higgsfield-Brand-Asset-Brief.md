# DoughBoss Higgsfield brand and menu asset brief

**Source:** `doughbossv2.docx`, supplied locally 14 July 2026

**Source hash:** `CFCC575EF8CE3BBA1B15BE7CF935347057BD7A3F4A53D085B6A276867B400930`
**Status:** production brief; assets not yet generated or approved

## Source direction captured

The Word file contains two logo references, current menu screenshots, a mobile checkout screenshot and a Domino's configurator reference. Its explicit creative requests are:

- correct the DoughBoss logo;
- use Higgsfield to render a 3D logo and make it the base direction for the brand;
- use approved WordPress food photos first;
- generate missing manoush and other menu images;
- create a lightweight animated kitchen visual of an older traditional Middle Eastern woman rolling and filling dough.

## Important production rule

AI output must not be the legal master logo. First rebuild the approved mark as clean vector artwork with exact typography, spacing and trademark ownership confirmed. The 3D render is a campaign/hero derivative. This prevents misspelled lettering, inconsistent geometry and an unusable raster-only identity.

## Logo system deliverables

1. Master horizontal logo: `DOUGH BOSS.` in the distressed boxed style, with `LEBANESE BAKERY` descriptor if approved.
2. Compact/stacked mark for mobile and social avatars.
3. Monochrome black and white SVG masters.
4. Warm brand-colour version and accessible inverse.
5. 3D hero render on transparent background.
6. Favicon/app-icon simplification that remains legible at 16–48 px.
7. Clear-space, minimum-size and incorrect-use sheet.

The current small `DOUGHBOSS` wordmark and the supplied large distressed boxed reference are not interchangeable. Owner must choose which is canonical before global replacement.

## Higgsfield logo prompt

> Create a premium restrained 3D brand render of the supplied approved Dough Boss vector logo. Preserve every letter, full stop, border, spacing and the words “LEBANESE BAKERY” exactly; do not invent or replace typography. Front-facing near-orthographic camera, shallow dimensional depth, charcoal-black material with subtle flour-dust texture, warm sandstone edge light, very soft contact shadow, transparent background, no extra objects, no mockup wall, no lens distortion, no altered text, no duplicate letters. Contemporary Lebanese bakery identity, crafted rather than glossy, suitable for a fast-loading website hero.

Generate separate light- and dark-background variants. Reject any output with text mutation. Composite the approved vector face over the render if necessary to guarantee exact lettering.

## Menu image standard

- Use the real WordPress photo when it clearly depicts the exact sold item and usage rights are confirmed.
- Generate only missing items; never relabel a generic food image as a specific product.
- One consistent camera: roughly 45-degree three-quarter view or approved overhead system.
- Warm natural bakery light, neutral stone/wood surface, realistic portion and toppings.
- No hands, text, logos, utensils or extra side dishes unless part of the product standard.
- Preserve culturally and commercially accurate manoush shape, topping distribution and bake.
- Deliver 4:3 master, 1:1 card crop and 16:9 category crop with safe subject margins.
- Export AVIF/WebP plus JPEG fallback, with meaningful alt text authored from the real item.

## Manoush base prompt

> Commercially accurate Lebanese manoush menu photograph of [EXACT MENU ITEM], prepared to the supplied DoughBoss recipe reference. Freshly stone-baked flatbread with realistic blistering and browned edges, [EXACT TOPPING DETAILS], no invented garnish. Warm natural bakery light, clean neutral stone surface, consistent 45-degree camera, entire product visible with generous crop margin, appetising but realistic, no text, no logo, no packaging, no hands, no duplicate food, no excessive cheese pull, no Western pizza styling. Match the approved DoughBoss menu photography system.

Create item-specific prompts only after recipes/reference photos are supplied for zaatar, zaatar and cheese, cheese, meat, meat and cheese, pies, wraps and pizzas.

## Kitchen animation brief

Concept: a respectful, warm, documentary-inspired scene of an older traditional Middle Eastern woman preparing dough at a bakery bench—rolling, filling and folding in a short seamless motion.

Requirements:

- obtain owner approval for cultural representation; do not imply a real family member or employee without consent;
- practical bakery setting and accurate dough handling;
- restrained camera movement; no surreal hands, utensils or food transformations;
- 4–6 second seamless loop, 16:9 desktop and 4:5 mobile-safe framing;
- silent by default, no autoplay audio;
- optimised WebM/MP4 target plus an approved WebP/AVIF still;
- lazy-load below the fold and never delay ordering controls;
- respect `prefers-reduced-motion` by showing the still only;
- text and controls must retain WCAG contrast over any media.

Suggested prompt:

> Warm authentic Lebanese bakery interior, an older Middle Eastern woman in modest traditional-inspired work clothing calmly rolling a small round of dough, placing a measured savoury filling and beginning to fold it, accurate hand movement and bakery technique, gentle natural side light, warm timber and stone, respectful documentary tone, subtle smile, no costume caricature, no visible brand text, no talking, locked camera with minimal parallax, 5-second seamless loop, realistic hands and food, clean background space for website heading, fast-loading hero composition.

## Asset inventory and governance

Create an asset register with:

- menu item ID and exact product name;
- real/generated/composited source;
- source photo and rights owner;
- generation prompt, model/version and date;
- human approver;
- allergens/ingredients represented;
- crops, dimensions, file weight and alt text;
- replacement/expiry status.

Never place AI-generated food online without kitchen confirmation that it is an honest representation of what customers receive.

## Acceptance checklist

- [ ] Canonical logo direction chosen by owner.
- [ ] Master lettering is exact at pixel and vector level.
- [ ] Trademark/licence ownership confirmed.
- [ ] Logo variants pass light/dark, small-size and contrast tests.
- [ ] Every food image maps to one exact sellable item.
- [ ] Kitchen verifies recipe/topping/portion accuracy.
- [ ] Cultural portrayal approved and not based on an unconsented real person.
- [ ] Mobile crops preserve the subject and do not obscure ordering UI.
- [ ] Reduced-motion fallback works.
- [ ] Core Web Vitals budget is met; below-fold media is lazy-loaded.
- [ ] AVIF/WebP and fallback outputs include width/height to prevent layout shift.
- [ ] All generated content is recorded in the asset register.

## Demo menu shot list (S2 — items awaiting real photography or generation)

Status as of 16 July 2026: the WordPress media library (`/wp-json/wp/v2/media`) was fully
enumerated. The static demo (`demo/index.html`) now shows a real, touched-up WordPress photo
for every item that has one (300x300 q82 progressive JPEG, stored locally in
`demo/assets/menu/`), and a branded mono placeholder tile for the 9 items below. Each needs
either a real kitchen photo (preferred) or a Higgsfield generation per the menu image
standard above.

| # | Section | Item | Notes for the shot |
|---|---------|------|--------------------|
| 1 | Pizza | All Meat | Pepperoni, sujuk, chicken & cheese on BBQ sauce base |
| 2 | Pizza | Peri Peri Chicken | Chicken, mushroom, capsicum, onion, cheese, peri peri drizzle |
| 3 | Pies | Chicken Pie | Grilled chicken, capsicum, mushroom & cheese turnover |
| 4 | Wraps | Chicken Delight | Grilled chicken, tomato, lettuce, pickled cucumber, garlic mayo |
| 5 | Wraps | Ultimate Chicken | Chicken, melted cheese, mushroom, capsicum, lettuce, mayo |
| 6 | Wraps | Dough Boss Wrap | Sujuk, tomato, pickled cucumber, lettuce, cheese, mayo |
| 7 | Drinks | Spring Water | Bottle shot, chilled condensation, neutral surface |
| 8 | Drinks | Soft Drinks 600ml | Range shot (Coke/Sprite/Fanta/Solo) or single hero bottle |
| 9 | Drinks | Juice | Chilled juice bottle/glass, neutral surface |

Real WordPress photos wired into the demo (source file -> item): Zaatar.jpg -> Zaatar;
zaatar-w-jebneh.jpg -> Zaatar & Cheese; jebneh.jpg -> Cheese manoush; Meat.png -> Meat;
lahme-w-jebneh.jpg -> Meat & Cheese; soujouk-deluxe.jpg -> Sujuk Deluxe; Spinach-Deluxe.png ->
Spinach Deluxe; Veggie-Lovers-scaled.jpg -> Veggie Plus (NOTE: WP file is named "Veggie
Lovers" — owner to confirm it is the same product as the menu's "Veggie Plus");
peperoni-cheese.png -> Pepperoni & Cheese; Dough-boss-special.jpg -> Dough Boss Special;
Chicken-Cheese.png -> Chicken & Cheese; BBQ-Chicken.png -> BBQ Chicken; Garlic-Prawns.png ->
Garlic Prawns; spinach-pie / Spinach-Cheese.jpg -> Spinach & Cheese pie; Halloumi-Pie.jpg ->
Haloumi pie; shanglish-pie.jpg -> Aged Cheese pie; zaatar-and-veges.jpg -> Zaatar & Veggie
wrap; chocobanana.jpg -> Choco Banana. (Aged-Cheese.jpg and soujouk-w-jebneh.jpg, previously
shown on the demo for Cheese and Meat & Cheese, were replaced by the name-exact jebneh.jpg and
lahme-w-jebneh.jpg.)

Bonus library items not on the current demo menu (possible future use): Kaak.png,
Kafta-Extra.png, Mini-Feta/Mini-Spinach/Mini-haloumi/mini-* range, Choco-Pie.png,
ashta-w-aasal.jpg, Aussie-Breakie-scaled.jpg, DB-*-Roll.png wrap renders, catering
placeholder heroes, Dough-Boss-Logo.png / dbicon.png.
