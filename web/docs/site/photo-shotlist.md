# Dough Boss photo reshoot and replacement brief

This brief is for the store team and a photographer. It turns the findings in `photo-audit.md` into a shoot plan. Owner: Elie Dagher.

Ground rules for every frame:

1. **Shoot the real product, exactly as a customer receives it**, from the normal kitchen, on the day.
2. **One item, one photo.** No photo is shared between two menu items.
3. **What the description names must be visible.** Nothing the customer does not get may appear. That includes garnish, props, extra toppings, drizzle and a second piece.
4. **No stock images and no AI food photos on the live site as the end state.** *Update 2026-10-02: Elie asked for AI-generated replacements as a stopgap. 16 candidates are in `web/assets/photo-candidates/`, each to be checked against the real product and approved by Elie before use. Branded drinks use supplier pack shots, not generated bottles. A real reshoot remains the better long-term fix.*
5. **Fill in the provenance log for every keeper** (see section 5).

Ingredient lists below are copied from the live menu descriptions. Where an item has no description, the line says **[CONFIRM]**: the store must confirm what goes on it **before** it is photographed. This brief does not invent recipes, sizes, prices or stock.

---

## 1. Shared visual style

The style comes from the best photos already on the site.

- **Menu photos** follow `real-v1/zaatar-cheese.jpg`, `real-v1/cheese.jpg` and `real-v1/meat-cheese.jpg`. These are the clearest, most consistent and most honest product photos: the whole item, top-down, on a clean light background. Nine live menu photos already use this look, so adopting it means the fewest reshoots.
- **Site and lifestyle photos** follow `doughboss-feast-real-v1.jpg`, a top-down spread on dark timber. It is the strongest site image and shares its dark timber with `real-v1/haloumi-pie.jpg` and `real-v1/spinach-pie.jpg`.

### Menu tier (every card on Menu and Order)

| Setting | Standard |
|---|---|
| Background | One plain off-white seamless sheet for the whole menu. Keep a soft natural shadow, rather than a hard clipped cut-out on pure white. |
| Light | Soft, even, daylight-balanced (about 5,000-5,600 K) from one side, with a white bounce card opposite. Set white balance on a grey card every session. No HDR, no extra saturation, no vignette. |
| Angle, round flat items (manoush, pizza) | Straight top-down (90 degrees). Whole round in frame. Cut into slices only if that is how it is served. |
| Angle, pies, wraps and dessert | About 45 degrees, whole item. Then a second frame cut open or in half so the filling shows. |
| Angle, sealed drinks | Straight-on packshot, label square to the camera, cap and base fully in frame (section 3). |
| Surface and props | None, except what is served with the item. If a lemon wedge is not supplied, it does not appear. |
| Consistency | Same background, same light position, same camera height and the same lens for the whole menu, so the grid reads as one set. |

### Site tier (heroes, catering, social card, shop pages)

| Setting | Standard |
|---|---|
| Food spreads | Top-down on the dark timber table used for the feast photo, with natural light. Real items only. |
| Premises | Shopfront and counter at each store (Revesby, Bankstown, Roselands Centro), and the oven and team at work. Only with staff consent, and with no customers identifiable. |
| Catering | Only the format actually supplied (platter, box or tray) [CONFIRM the format exists], with portions as delivered. |

### Crop ratio and safe zone

- **Export every menu master at 5:4.** This matches the existing `real-v1` set (550x440) and the 5:4 story slots on Home and About.
- The live menu card is 4:3 (`.db-card-img`, cover, centred), and the Home category cards are close to square. **Compose so that both a centre 4:3 crop and a centre 1:1 crop keep the whole item:**
  - keep the subject inside the middle 80% of the width and 90% of the height;
  - leave about 10% clear margin on every side.

  Today's cut-outs touch the frame edge, which is why crusts get clipped.
- Square (1:1) or very wide (16:9) menu files are not used again.

### Minimum pixel size, and why

The largest menu-card slots measured on the live site, and the device pixels each needs:

- **Phone card on Order:** about 338 CSS px wide. At 3x density that needs about **1,014 device pixels**.
- **Story image on Home and About:** about 555 CSS px wide. At 2x that needs about **1,110 px**.
- **Home category cards:** about 357-396 CSS px wide and close to square. A 1:1 crop at 2-3x needs about **1,070-1,190 px**.

Therefore:

- **Menu web master: 1,600 x 1,280 px (5:4).** This covers every slot above at full sharpness, even after a 1:1 crop (1,280 px square). Never export below 1,200 px wide.
- **Camera original:** at least 4,000 px on the long side. Archive it with the business records, not in the website repository.
- **Hero master:** at least **2,880 px wide**. Heroes render at 1,280-1,440 CSS px on desktop, which is 2,560-2,880 px at 2x. On a phone the same hero becomes nearly square or portrait (390x406-444, and 422x727 on Home), so keep the key food within the central 40% of the width.
- **Social card:** **1,200 x 630 JPEG**, with the food inside the centre 630 x 630 square so square previews do not cut it in half. Add the Dough Boss wordmark and at most a short, verifiable line.

---

## 2. Shot list by item

"Must show" is taken from the live description, word for word in substance. Every item gets one 5:4 hero frame. "+ cut" means an extra cut-open frame for the item page or a second image.

### Manoush (top-down, whole, flat as served; add a folded frame where noted)

| Item | Must show | Notes |
|---|---|---|
| Cheese, Tomato & Olives | **[CONFIRM description]**; from the name: cheese, tomato, olives | Currently shows a pizza with mushroom and capsicum. **Shoot first.** |
| Sujuk & Cheese | **[CONFIRM description]**; from the name: sujuk and cheese, whole product visible | Confirm which sausage is used. Do not use a close-up. |
| Half Meat & Cheese | **[CONFIRM what "Half" means]** before shooting | Show the half clearly, whether that is size or split. |
| Cheese Kaak | **[CONFIRM description]**; the cheese must be visible (open or cut) | The current photo shows a plain sesame kaak. |
| Meat | Minced lamb with spices, onions and tomatoes. Flat **and** one folded frame. | The current file has letterbox bars and a small subject. |
| Zaatar | Thyme, sumac and sesame with olive oil. Flat **and** one folded frame. | No folded manoush photo exists, although several items say "flat or folded". |
| Zaatar & Cheese | Zaatar on one half, blended cheese on the other | The current photo is good; reshoot only for consistency. |
| Cheese | Blended cheese, baked golden | Good now; consistency only. |
| Meat & Cheese | Minced lamb with spices, topped with melted cheese | Good now; also needs a larger frame for the About page. |

### Pizza (top-down, whole, sliced only as served)

| Item | Must show | Notes |
|---|---|---|
| Sujuk Deluxe | Spiced beef sausage, tomato, **capsicum**, **mushroom**, olives, cheese | Also shoot a **hero frame at 2,880 px or more**: this item is the Menu, Order and Locations hero. The current photo lacks capsicum and mushroom. |
| All Meat | Pepperoni, sujuk, chicken and cheese on a BBQ sauce base. **Each meat distinguishable.** | The current photo shows one meat on black. |
| Labneh Veggie Pizza | Labneh, tomato, olives and fresh vegetables | Labneh must be clearly visible. Shoot with the default sauce [CONFIRM which]. |
| Zaatar Veggie Pizza | **[CONFIRM description]**; zaatar must be visible | It has no description and no photo of its own. |
| Sujuk Special | Sujuk, tomato, mushroom, capsicum, onion, black olives and cheese on a tomato base | Whole pizza, no tilted close-up. |
| Peri Peri Chicken | Grilled chicken, mushroom, capsicum, onion and cheese, finished with peri peri sauce | Show only what is on the real pizza. The current photo has yellow pieces and a drizzle that are not described. |
| Garlic Prawns | Prawns, mushroom, onion, capsicum and cheese on a garlic-tomato base | The current file is 300 px and soft. |
| BBQ Chicken | BBQ sauce base with chicken, onion, capsicum, mushroom and cheese | The current photo matches; it needs a crop or a consistency reshoot. |
| Chicken & Cheese | Grilled chicken and mushroom on garlic sauce, topped with cheese | As above. |
| Pepperoni & Cheese | Pepperoni and cheese on a tomato sauce base | Good now; consistency only. |
| Spinach Deluxe | Spinach mix, mushroom, tomato, olives and cheese | Good now; consistency only. |
| Veggie Plus | Cheese, tomato, olives, capsicum, onion and mushroom on a garlic sauce base | Best pizza photo now. Use it as the reference for colour and topping density. |

### Pies (45 degrees whole, + cut)

| Item | Must show | Notes |
|---|---|---|
| Dough Boss Pie | Grilled chicken, capsicum, mushroom and cheese. **Cut open.** One pie only. | The current photo shows cheese and olives, two pies. **Shoot first.** |
| Aged Cheese | Aged white cheese (shanklish) with diced tomatoes and onions. **Cut open.** | Neutral light, not backlit. The kitchen must confirm nothing else is in it (the item is tagged vegetarian). |
| Haloumi | Haloumi cheese baked in a pie: whole + cut | The current sealed photo is good; add the cut frame. |
| Spinach Pie | Triangular turnover of spinach, onion, lemon, spices and cheese: whole + cut | A lemon wedge only if it is served with it [CONFIRM]. |

### Wraps (45 degrees, cut in half, filling facing camera)

| Item | Must show | Notes |
|---|---|---|
| Dough Boss Wrap | Sujuk, fresh tomato, pickled cucumber, lettuce and cheese, topped with mayo | The current file is a black-background bread tube. **Shoot first.** |
| Ultimate Chicken | Grilled chicken, melted cheese, mushroom, capsicum and lettuce, topped with mayo | As above. |
| Chicken Delight | Grilled chicken, fresh tomato, lettuce, pickled cucumber and garlic mayo | Confirm the current photo's mushroom-like piece is not in it. Keep the filling end inside the frame. |
| Zaatar & Veggie | Zaatar with fresh tomato, cucumber, olives and mint | Shoot the base wrap. Show add-ons only in a separately labelled frame. |
| Labneh Veggie Wrap | **[CONFIRM description]**; labneh must be visible | It has no description today. |

### Dessert

| Item | Must show | Notes |
|---|---|---|
| Choco Banana | Nutella chocolate and banana baked in a pie. **Cut open so the chocolate and banana show.** | **[CONFIRM first]** whether the sold pie has sesame and a drizzle like the current photo. If it does, the photo may show them, and the description and allergen information must say so. Keep both tips inside the frame. |

### Drinks: every item needs its own photo (12 of 12)

| Item | Recommended shot | Why |
|---|---|---|
| Coke 600ml | **Packshot** of the exact bottle stocked | A sealed branded product. A clean packshot is the most accurate picture of what the customer gets; a lifestyle glass with ice would misrepresent it. |
| Coke Zero 600ml | **Packshot** | A distinct product; it must not share a photo with Coke. |
| Coke Vanilla 600ml | **Packshot** | A distinct product. |
| Sprite 600ml | **Packshot** | A distinct product. |
| Fanta 600ml | **Packshot** of the flavour actually stocked **[CONFIRM flavour]** | The item name does not say which flavour. |
| Spring Water | **Packshot** of the bottle actually stocked **[CONFIRM brand and size]** | The current photo is a supplier image of a 1-litre branded bottle. |
| Orange Juice | **[CONFIRM how it is sold]**. If bottled or packaged: packshot. If poured in store: the actual cup as served, straight-on. | The current shared glass with ice and orange slices may not be what is served. |
| Orange & Mango Juice | As for Orange Juice | It needs its own photo; it currently shares one. |
| Orange & Passion Juice | As for Orange Juice | As above. |
| Apple Juice | As for Orange Juice | It currently shows an orange drink. |
| Lemon Juice | As for Orange Juice | It currently shows an orange drink. |
| Lemon & Mint Juice | As for Orange Juice; mint visible only if it is in the drink | It currently shows an orange drink. |

Packshot setup:

- the same off-white sweep as the food;
- one bottle;
- label facing camera;
- light that avoids hot reflections on the label;
- cap and base inside the frame;
- **all six packshots at the same height in frame**, so the drinks grid lines up.

No fruit garnish, ice or glass unless that is how the drink is served. Use only your own photos of your own stock, not manufacturer or supplier images.

### Site-level

| Need | Shot | Replaces |
|---|---|---|
| Social card | A real zaatar manoush, top-down on the dark timber, subject in the centre square, wordmark added | `doughboss-social-card.jpg` |
| Menu, Order and Locations hero | A wide, real-food frame at 2,880 px or more (the Sujuk Deluxe hero frame, or a feast-style spread) | The 550 px Sujuk Deluxe stretch |
| Franchising hero | The shopfront or the team at the oven, at 2,880 px or more | The 300 px `menu/zaatar.webp` |
| Locations and Contact | One shopfront per store (Revesby, Bankstown, Roselands Centro) | The stock telephone on Contact; the pizza close-up on Locations |
| Catering | The real catering format as delivered [CONFIRM] | The feast photo used as a "catering spread" |
| Home and About hero | A feast-style spread at 2,880 px or more, or the original of the feast photo if a larger file exists | The 1,080 px feast photo stretched on Retina |

---

## 3. Priority order

### First half-day: fixes the worst problems

These items cover every HIGH finding that a camera can fix, plus the two quickest MEDIUM wins. Before the day, the store must answer the [CONFIRM] questions for these items.

1. **Drinks packshots:** Coke, Coke Zero, Coke Vanilla, Sprite, Fanta, Spring Water. One setup, fastest win. This removes the wrong orange-juice photo from five items.
2. **Juices:** Apple, Lemon, Lemon & Mint, Orange, Orange & Mango, Orange & Passion, in the confirmed serving form.
3. **Cheese, Tomato & Olives** manoush.
4. **Labneh Veggie Pizza** and **Zaatar Veggie Pizza.**
5. **Dough Boss Pie**, cut open.
6. **All Meat.**
7. **Sujuk Deluxe**: menu frame **and** a 2,880 px wide hero frame.
8. **Choco Banana**, cut open, once confirmed.
9. **Dough Boss Wrap** and **Ultimate Chicken**, cut in half.
10. **Zaatar manoush, top-down on dark timber**, for the new social card. Make it at least 2,400 px so it can also crop to a hero.

### Second half-day

1. Sujuk & Cheese, Sujuk Special, Peri Peri Chicken, Half Meat & Cheese, Cheese Kaak, Aged Cheese, Garlic Prawns, Meat (flat and folded), Chicken Delight.
2. Shopfronts at the three stores, and one team or oven frame for Franchising.
3. The catering format, if confirmed.

### Consistency pass (when convenient)

Reshoot the items that already match in the new house style, so the grid is uniform:

- Pizza: BBQ Chicken, Chicken & Cheese, Pepperoni & Cheese, Spinach Deluxe, Veggie Plus.
- Manoush: Zaatar (flat and folded), Zaatar & Cheese, Cheese, Meat & Cheese.
- Pies: Haloumi (+ cut), Spinach Pie (+ cut).
- Wraps: Zaatar & Veggie, Labneh Veggie Wrap.

### Before any shoot: no camera needed (each needs Elie's approval before going live)

- Show the plugin's existing branded placeholder instead of the shared or wrong photo. This covers the 10 non-orange drinks, the 3 items sharing `veggie-plus.webp`, All Meat and Dough Boss Pie. The plugin's own changelog already promises a placeholder instead of a lookalike.
- Remove "Real" from the alt text. Make the catering alt text literal.
- Point the Franchising hero at the feast photo.
- Add `max-width: none` to `.dbf-page-hero-bg` to remove the black strip.
- Remove the Contact telephone photo.
- Move the two AI stills out of `web/public/`.

---

## 4. File naming and export spec

### Naming

`{item-id}-{item-slug}-{view}-{width}w.{ext}`

- **item-id** is the live menu item id, for example `345`. It stays stable even if a name changes.
- **item-slug** is the item name in lower-case kebab case, for example `sujuk-deluxe`.
- **view** is one of:
  - `top` (top-down);
  - `45` (three-quarter);
  - `cut` (cut open);
  - `fold` (folded manoush);
  - `pack` (drink packshot);
  - `hero` (wide banner);
  - `social`.
- Examples: `345-sujuk-deluxe-top-1600w.avif`, `463-dough-boss-pie-cut-1200w.webp`, `550-coke-600ml-pack-800w.jpg`.
- Put new files in a new folder, `menu/real-v2/`, so they never mix with the `real-v1` or legacy files. Never reuse one file for two items.
- Site images: `site-{page}-{subject}-{width}w.{ext}`, for example `site-locations-revesby-shopfront-2880w.avif`.

### Export

| Use | Ratio | Widths to export | Formats |
|---|---|---|---|
| Menu card and story image | 5:4 | 480, 800, 1200, 1600 | AVIF (about q55-60), WebP (about q75-80), JPEG fallback (about q82, progressive) |
| Hero | Wide master; art-directed crops per breakpoint | 960, 1600, 2400, 2880 | AVIF, WebP, JPEG fallback |
| Social card | 1200x630 | 1200 | JPEG (most compatible for link previews) |

These quality levels are in line with the plugin candidate's AVIF q58 / WebP q75 pipeline (`docs/wp/03-staff-dev-gaps.md`). Check each file by eye at 100% rather than relying on a number. As a guide, a 1,200 px menu WebP should land well under about 150 KB.

- **Colour and metadata:** sRGB, 8-bit. Strip GPS and location data. Keep IPTC Creator and Copyright.
- **Delivery:** menu cards are CSS backgrounds today, so `srcset` cannot apply. Rendering each card photo as an `<img srcset sizes alt>` fixes the resolution and the missing alt text together.

---

## 5. Quality check before a photo goes live

Answer each question yes or no; any "no" means reshoot.

1. Is every ingredient in the description visible, or at least not contradicted?
2. Is anything visible that the customer does not get (garnish, props, extra topping, extra piece)?
3. Does it contradict the item's dietary tag?
4. Is the whole item inside the frame, with about 10% margin, and does it survive a centre 4:3 crop and a centre 1:1 crop?
5. Is it the same background, light, angle and colour as the rest of the set? Is the white balance neutral, with no HDR or over-saturation?
6. Is the master at least 1,600 x 1,280, named to the convention, with every export width present?
7. **Provenance log row written:** file, item id, date, store, photographer or owner, and licence or ownership. The log is kept with the business records, not in this repository.
8. Is the alt text literal (describing the food, not claiming "real")?
