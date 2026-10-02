# Dough Boss website photo audit

Scope: every image on the public site doughboss.com.au and every image the live `/menu` API serves, checked on 2 October 2026. Owner: Elie Dagher.

The files were downloaded with public, read-only GET requests. Every image listed below was opened and looked at by the adjudicator, with zoomed crops where detail mattered. Six earlier review passes were checked against the pixels: each MISMATCH, PARTIAL, POOR and REMOVE/REPLACE/RESHOOT verdict, plus a sample of the KEEP verdicts. Reuse counts come from the live menu data (43 items), not from the reviewers' notes. This is an observation of what the photos show, not legal advice.

Confidence labels: **H** = high, **M** = medium, **L** = low. "Cannot tell" means the pixels do not settle the question.

---

## 1. The numbers

| Measure | Count | Confidence |
|---|---|---|
| Distinct images reviewed | **38**: 36 live, plus 2 AI stills that are not live. That is 40 files, because the Contact photo exists in 3 sizes. | H |
| Live menu items | **43**, served by **31** distinct image files | H |
| Menu items on the newer `real-v1` photos / on older legacy WebP files | **23 / 20** | H |
| Menu items with **no photo of their own** (they share an image) | **14**: 11 drinks share `juice.webp`, and 3 items share `veggie-plus.webp` | H |
| Item relevance: **MATCH / PARTIAL / MISMATCH** | **15 / 15 / 13** | M overall (H on the 13 mismatches) |
| Menu files by quality: **GOOD / ACCEPTABLE / POOR** | **10 / 12 / 9** (of 31) | M |
| All 36 live images by quality: GOOD / ACCEPTABLE / POOR | 12 / 14 / 10 | M |
| Menu items shown with a POOR-quality file | **11** | H |
| Menu items with a relevance problem (PARTIAL or MISMATCH) | **28 of 43** | M |
| HIGH-priority fixes | **8 live images**: 6 menu files affecting **17 menu items**, plus the site-wide link-preview card and the Franchising hero. One more applies before any deploy of `web/` (the AI stills). | M |
| Visible pork, alcohol, or meat on a vegetarian-tagged item | **None seen.** One ambiguity to confirm: Aged Cheese (section 2, item 16). | M |
| Visible watermarks | **None** | H |
| Visible third-party brands | **1**: Spring Water shows a "nu pure spring water 1 Litre" label | H |

How the menu photos are displayed (from the live CSS):

- Menu and order cards (`.db-card-img`, `css/3.css` line 230) are `aspect-ratio: 4 / 3`, `background-size: cover`, centred, on a `#f3efe9` card background.
- Settled card widths are 249-265 CSS px on desktop and 304-338 CSS px on a phone. A 3x phone needs about 1,014 device pixels across.
- The Home and About story images (`.dbf-story-media img`) are 5:4. The Home category cards (`.dbf-food-card`) are roughly square (about 396x384).

---

## 2. Ranked fix list

HIGH means the photo misrepresents what a customer receives, carries a "real" claim it cannot back, or is the most-seen image on the site. MEDIUM means a partial match or a POOR file on a single item. LOW means a technical re-export or a consistency fix on a photo that already matches.

### HIGH

1. **`menu/juice.webp`** (500x500): one orange drink in a highball glass, with ice, orange slices and a black straw, on dark slate.
   - **Used by 11 drinks.**
   - Wrong for **8**: Apple Juice, Lemon Juice, Lemon & Mint Juice, Coke 600ml, Coke Zero 600ml, Coke Vanilla 600ml, Sprite 600ml, Fanta 600ml. It shows an orange drink with orange fruit in a glass; these customers receive a different drink or a sealed branded 600 ml bottle.
   - Partial for 2: Orange & Mango and Orange & Passion (no mango or passionfruit visible).
   - Plausible only for Orange Juice. Medium confidence, because it cannot tell how the juice is actually served.
   - On a phone the drinks block is a column of 11 identical photos.
   - **Action:** REPLACE. Show the placeholder on the 10 non-orange items now, then shoot each one (see the shot list). **H**
2. **`menu/veggie-plus.webp`** (300x300) is a tighter, more saturated crop of the same Veggie Plus pizza photo as `real-v1/veggie-plus.jpg`. It shows a round pizza with mushroom, green capsicum, tomato, onion rings and black olives.
   - Used by **Cheese, Tomato & Olives** (a manoush). A pizza is shown, plus mushroom, capsicum and onion that the name does not include.
   - Used by **Labneh Veggie Pizza**. No labneh is visible.
   - Used by **Zaatar Veggie Pizza**. No zaatar is visible.
   - So one photograph appears on four menu items in total, counting Veggie Plus itself.
   - **Action:** REPLACE. Placeholder now; one photo per item. **H**
3. **`doughboss-social-card.jpg`** (1200x630) is the link-preview and schema.org image on Home, About, Catering, Locations, Menu and Order.
   - Its `og:image:alt` is "Fresh zaatar manoush at Dough Boss".
   - It shows a thick-rimmed flatbread with a dry, gravel-like topping, styled with mint, spice bowls and dough balls, in front of a blurred bakery with racks of loaves.
   - That does not match the thin, oily, sesame-flecked zaatar manoush in the feast photo or in `real-v1/zaatar.jpg`. It has the look of AI generation or styled stock (**M**).
   - There is no logo or text. The flatbread sits right of centre, so a square preview crop cuts it in half.
   - Contact and Franchising have no `og:image` at all.
   - **Action:** REPLACE with a card built from a real Dough Boss photo, and fix the alt text. **M** on provenance, **H** on the mismatch with the real product photos.
4. **`real-v1/sujuk-deluxe.jpg`** (550x440) is the most-seen pizza photo on the site.
   - It appears as the Home story image and the Home "Pizza" card (both with alt "Real Dough Boss Sujuk Deluxe pizza"), as the hero on Menu, Order and Locations, and on its own menu card.
   - It shows sausage slices, fresh tomato slices, black olives and cheese.
   - **No capsicum and no mushroom are visible**, although both are in the description.
   - As a hero it is stretched about 2.3x, and on Menu and Order it is darkened to a near-black band.
   - **Action:** RESHOOT with every listed topping visible. Today, remove "Real" from the alt text. **H**
5. **`menu/zaatar.webp`** (300x300) is the Franchising hero, stretched about 4.3x across a 1,280 px hero.
   - It is not used by any menu item.
   - It renders as an unrecognisable smear (confirmed in the rendered screenshot).
   - **Action:** REPLACE. Use the feast photo today; later, a real shopfront or team photo at least 2,400 px wide. **H**
6. **`real-v1/dough-boss-pie.jpg`** shows two boat-shaped pies.
   - The open ends show melted cheese and sliced black olives (zoomed).
   - The description is "Grilled chicken, capsicum, mushroom & cheese." No chicken or capsicum is visible; a few dark pieces could be olive or mushroom (cannot tell).
   - Olives are not in the description, and two pies are shown for one item.
   - **Action:** RESHOOT, cut open, with the described filling visible. **M-H**
7. **`menu/all-meat.webp`** (300x300) shows a pepperoni-style pizza with one kind of round red slice, on a baked-in black background.
   - The description is "Pepperoni, sujuk, chicken & cheese on a BBQ sauce base." No chicken, no second meat and no BBQ base are visible.
   - It looks like the separate Pepperoni & Cheese item.
   - **Action:** RESHOOT. Placeholder until then. **M**
8. **`real-v1/choco-banana.jpg`** (692x440) shows a closed half-moon pie with sesame seeds and an amber-red zig-zag drizzle.
   - The description is "Nutella chocolate & banana baked in a pie." No chocolate or banana is visible.
   - The sesame and drizzle are not described. Sesame is one of the allergens the Food Standards Code requires to be declared by its required name (`docs/marketing/research/compliance-au.md` section 7).
   - The 4:3 card crop cuts off both tips.
   - **Action:** first confirm with the store what the sold product looks like and whether sesame is on it. Then RESHOOT cut open, and correct the description and allergen information if needed. **M**

Before any deploy of `web/` (not live today): **`web/public/hero/ai/manoush-blowout-still.webp` and `manoush-topdown-reference.webp`**. REMOVE them from `web/public/` (section 7). **H**

### MEDIUM

9. **Hero layout bug** (rendering, not a photo). Every inner-page hero (`.dbf-page-hero-bg`, on Menu, Order, About, Locations and Franchising) leaves a **black strip about 51 px wide on the right** on desktop, and about 16 px on a phone.
   - Root cause, verified in the live CSS: the hero is `inset: -4%; width: 108%`, but the global rule `img { max-width: 100% }` (`css/4.css`) caps it at 100%.
   - Fix: add `max-width: none` to `.dbf-page-hero-bg`. **H**
10. **`menu/dough-boss-wrap.webp`** and **`menu/ultimate-chicken.webp`** (both 300x300, 11 KB and 20 KB).
    - Each is a tight close-up of a toasted wrap on black, with **no filling visible**. The two look interchangeable on the menu.
    - Dough Boss Wrap is the signature wrap (sujuk, tomato, pickled cucumber, lettuce, cheese, mayo).
    - **Action:** RESHOOT both, cut in half. **H** that no filling shows.
11. **`real-v1/sujuk-cheese.jpg`** (Sujuk & Cheese, a manoush with no description) is an angled, warm, shallow-focus close-up of round sausage slices and cheese on a thick, puffy, pizza-style rim. The whole product is never shown.
    - The sausage type cannot be identified from the photo.
    - **Action:** REPLACE. **M**
12. **`menu/dough-boss-special.webp`** (Sujuk Special, 300x300) is a tilted, over-processed close-up.
    - It shows sausage, capsicum and probably olives. Mushroom and onion are not visible, and the whole pizza is never shown.
    - The filename does not match the item name.
    - **Action:** REPLACE. **M**
13. **`real-v1/peri-peri-chicken.jpg`** shows chicken, mushroom and red capsicum.
    - It also shows small yellow pieces (could be pineapple or yellow capsicum; cannot tell) and a mustard-yellow drizzle, neither of which is in the description.
    - The puffy, charred crust differs from the thin bases in the grey-studio set.
    - **Action:** REPLACE. **M**
14. **`menu/meat-cheese.webp`** (Half Meat & Cheese, 300x300, no description) is a close crop of a thin mince-and-cheese bake. Nothing shows a "half".
    - **Action:** REPLACE, after confirming what "Half" means. **M**
15. **`real-v1/cheese-kaak.jpg`** shows a plain sesame kaak with **no cheese visible**.
    - The bread fills only about 45% of the frame width, and a lighter inset panel is visible (measured 38-45 px at the sides).
    - **Action:** RESHOOT, opened or cut, showing the cheese. **M**
16. **`real-v1/aged-cheese-pie.jpg`** is a moody, backlit, orange-cast macro.
    - The pale-yellow crumbly filling could be shanklish or egg (cannot tell).
    - **Red-brown strips behind the filling cannot be identified.** The item is tagged vegetarian, so confirm with the kitchen that nothing in the photo is meat.
    - **Action:** RESHOOT. **L-M**
17. **`menu/garlic-prawns.webp`** (300x300, 15 KB) is relevant: prawns and mushroom are visible. But it is soft, about 3.4x upscaled on a 3x phone, and on a cyan background.
    - **Action:** RESHOOT. **H** on quality.
18. **`real-v1/spring-water.jpg`** (550x440, 11 KB) is a supplier-style packshot of a third-party branded **"nu pure spring water, 1 Litre"** bottle.
    - The item text gives no brand or size ("Still, chilled.").
    - **Action:** confirm the brand and size sold, then shoot the bottle actually stocked. **H** on the label.
19. **Orange & Mango and Orange & Passion Juice**: give each its own photo (they are part of the `juice.webp` share). **M**
20. **Contact photo** (`uploads/contact-*.jpg`) is a vintage telephone on a navy background with no Dough Boss content, `alt=""`. It sits under a leftover post date ("30 November 2022").
    - **Action:** REPLACE with a shopfront or counter photo, or remove it. **H** on relevance.
21. **Catering imagery.**
    - The feast photo is used twice on Catering and twice on Home, with alt "A real Dough Boss catering spread".
    - It shows whole items on a table: no platter, box, portions or event setting. The copy beside it describes catering formats that the photo does not show.
    - **Action:** make the alt text literal today; shoot a real catering order (if the format is confirmed). **H** on what is visible.
22. **`real-v1/chicken-delight-wrap.jpg`** is a good photo.
    - Chicken, lettuce and a creamy sauce are visible. Tomato and pickled cucumber are not, and a mushroom-like piece is not in the description.
    - The 4:3 crop cuts the filling end, at about x=685 of 782.
    - **Action:** RECROP, and confirm the filling. **M**
23. **`real-v1/bbq-chicken.jpg`**, **`real-v1/chicken-cheese.jpg`** and **`real-v1/meat.jpg`** all match their items but have frame bars baked into the file.
    - BBQ Chicken: 16-17 px of pure white on the left and right (measured).
    - Chicken & Cheese: 15-16 px of pure white on the left and right (measured).
    - Meat: 23 px at the top and 18 px at the bottom (measured). About 9 px still shows on the card after the 4:3 crop.
    - **Action:** RECROP. **H**
24. **Menu and Order hero.** The hero is darkened so far that no food is recognisable, and no food photo appears in the first screen on either page (from the rendered captures).
    - **Action:** lighten it, or use a real wide banner. **M**
25. **Card accessibility.** All 43 menu-card photos are CSS backgrounds with no alt text, `aria-label` or role (measured on Menu and Order).
    - **Action:** render each card photo as an `<img>` with literal alt text. This also allows `srcset`. **H**

### LOW

26. Re-export the matching `real-v1` photos with about 10% margin and at 2x, if larger originals exist:
    - Zaatar, Zaatar & Cheese, Cheese, Meat & Cheese;
    - Pepperoni & Cheese, Spinach Deluxe, Veggie Plus;
    - Haloumi, Spinach Pie;
    - Zaatar & Veggie Wrap, Labneh Veggie Wrap.

    Several cut-outs touch the frame edge, so the card crop clips the crust.
27. The About page shows the 550 px `real-v1/meat-cheese.jpg` in a slot coded `width=900`. Use a larger file.
28. Haloumi is sealed, so the haloumi itself is not shown. Add a cut-open companion shot.
29. Re-export the favicon from a square, padded, opaque master. Today it is 270x270 RGBA, with artwork about 270x251 and the frame touching the sides.
30. Add Open Graph tags to Contact and Franchising.
31. Consistency reshoot: move the remaining matching photos onto the single house style in `photo-shotlist.md`.

---

## 3. Per-image table

File paths are relative to `wp-content/plugins/doughboss/public/images/` unless they start with `uploads/` or `web/`. "Px" is the native file size. "Conf." is confidence in the relevance verdict.

### Menu images (31 files, 43 items)

| # | File | Px | Used by (items) | Relevance | Quality | Action | Priority | Conf. |
|---|---|---|---|---|---|---|---|---|
| 1 | `menu/juice.webp` | 500x500 | 11 drinks (see fix 1) | Orange Juice MATCH; Orange & Mango and Orange & Passion PARTIAL; the other 8 MISMATCH | ACCEPTABLE (soft at 3x, 15 KB) | REPLACE | HIGH | H |
| 2 | `menu/veggie-plus.webp` | 300x300 | Cheese, Tomato & Olives; Labneh Veggie Pizza; Zaatar Veggie Pizza | MISMATCH x3 | POOR | REPLACE | HIGH | H / M / M |
| 3 | `menu/real-v1/sujuk-deluxe.jpg` | 550x440 | Sujuk Deluxe (+ 5 page roles) | PARTIAL (no capsicum, no mushroom) | GOOD on a card; POOR as a hero source | RESHOOT | HIGH | H |
| 4 | `menu/real-v1/dough-boss-pie.jpg` | 550x440 | Dough Boss Pie | MISMATCH (cheese and olive) | GOOD | RESHOOT | HIGH | M-H |
| 5 | `menu/all-meat.webp` | 300x300 | All Meat | MISMATCH (one meat, no BBQ base) | POOR | RESHOOT | HIGH | M |
| 6 | `menu/real-v1/choco-banana.jpg` | 692x440 | Choco Banana | PARTIAL (no chocolate or banana; sesame and glaze shown) | ACCEPTABLE (tips cropped) | RESHOOT | HIGH | M |
| 7 | `menu/dough-boss-wrap.webp` | 300x300 | Dough Boss Wrap | PARTIAL (wrap, no filling) | POOR | RESHOOT | MEDIUM | H |
| 8 | `menu/ultimate-chicken.webp` | 300x300 | Ultimate Chicken | PARTIAL (wrap, no filling) | POOR | RESHOOT | MEDIUM | H |
| 9 | `menu/real-v1/sujuk-cheese.jpg` | 653x440 | Sujuk & Cheese (manoush) | PARTIAL (pizza-style rim, no whole product) | ACCEPTABLE | REPLACE | MEDIUM | M |
| 10 | `menu/dough-boss-special.webp` | 300x300 | Sujuk Special | PARTIAL | POOR (over-processed) | REPLACE | MEDIUM | M (toppings L) |
| 11 | `menu/real-v1/peri-peri-chicken.jpg` | 782x440 | Peri Peri Chicken | PARTIAL (yellow pieces and drizzle) | ACCEPTABLE | REPLACE | MEDIUM | M |
| 12 | `menu/meat-cheese.webp` | 300x300 | Half Meat & Cheese | PARTIAL (no "half") | POOR | REPLACE | MEDIUM | M |
| 13 | `menu/real-v1/cheese-kaak.jpg` | 550x440 | Cheese Kaak | PARTIAL (no cheese visible) | POOR (small subject, inset panel) | RESHOOT | MEDIUM | M |
| 14 | `menu/real-v1/aged-cheese-pie.jpg` | 659x440 | Aged Cheese | PARTIAL (filling unidentifiable) | ACCEPTABLE (warm cast, shallow focus) | RESHOOT | MEDIUM | L-M |
| 15 | `menu/garlic-prawns.webp` | 300x300 | Garlic Prawns | MATCH (prawns, mushroom) | POOR | RESHOOT | MEDIUM | M |
| 16 | `menu/real-v1/spring-water.jpg` | 550x440 | Spring Water | PARTIAL (third-party brand, 1 L) | POOR (11 KB) | REPLACE | MEDIUM | M |
| 17 | `menu/real-v1/chicken-delight-wrap.jpg` | 782x440 | Chicken Delight | PARTIAL | GOOD | RECROP | MEDIUM | M |
| 18 | `menu/real-v1/bbq-chicken.jpg` | 550x440 | BBQ Chicken | MATCH (onion: cannot tell) | ACCEPTABLE (white side bars) | RECROP | MEDIUM | H |
| 19 | `menu/real-v1/chicken-cheese.jpg` | 550x440 | Chicken & Cheese | MATCH | ACCEPTABLE (white side bars) | RECROP | MEDIUM | H |
| 20 | `menu/real-v1/meat.jpg` | 550x440 | Meat (manoush) | MATCH | ACCEPTABLE (bars, flat, small subject) | RECROP | MEDIUM | M |
| 21 | `menu/real-v1/zaatar.jpg` | 550x440 | Zaatar | MATCH | ACCEPTABLE (dark patches, crust clipped) | RE-EXPORT | LOW | H |
| 22 | `menu/real-v1/zaatar-cheese.jpg` | 550x440 | Zaatar & Cheese; Home "Manoush" card | MATCH | GOOD | KEEP | LOW | H |
| 23 | `menu/real-v1/cheese.jpg` | 550x440 | Cheese | MATCH | GOOD (edge-tight) | KEEP | LOW | H |
| 24 | `menu/real-v1/meat-cheese.jpg` | 550x440 | Meat & Cheese; About story image | MATCH | GOOD on a card; soft in the About slot | KEEP | LOW | H |
| 25 | `menu/real-v1/pepperoni-cheese.jpg` | 550x440 | Pepperoni & Cheese | MATCH | GOOD | KEEP | LOW | H |
| 26 | `menu/real-v1/spinach-deluxe.jpg` | 550x440 | Spinach Deluxe | MATCH | ACCEPTABLE (soft, small subject) | KEEP | LOW | H |
| 27 | `menu/real-v1/veggie-plus.jpg` | 550x440 | Veggie Plus | MATCH | GOOD (best pizza photo) | KEEP | LOW | H |
| 28 | `menu/real-v1/haloumi-pie.jpg` | 550x440 | Haloumi; Home "Pies" card | PARTIAL (sealed, filling not shown) | GOOD | RE-EXPORT | LOW | M |
| 29 | `menu/real-v1/spinach-pie.jpg` | 550x440 | Spinach Pie | MATCH | GOOD (best pie photo) | KEEP | LOW | M-H |
| 30 | `menu/real-v1/labneh-veggie-wrap.jpg` | 550x440 | Labneh Veggie Wrap (no description) | MATCH against the name | ACCEPTABLE (17 KB, wide white bands) | RESHOOT | LOW | M |
| 31 | `menu/real-v1/zaatar-veggie-wrap.jpg` | 550x440 | Zaatar & Veggie | MATCH (cucumber not clear) | ACCEPTABLE (23 KB) | RE-EXPORT | LOW | M |

### Site-level and other images

| # | File | Px | Used on | Relevance | Quality | Action | Priority | Conf. |
|---|---|---|---|---|---|---|---|---|
| 32 | `doughboss-social-card.jpg` | 1200x630 | `og:image`, `twitter:image` and JSON-LD on 6 pages | PARTIAL (zaatar flatbread, unlike the real product photos) | ACCEPTABLE (no brand, subject off-centre) | REPLACE | HIGH | M |
| 33 | `menu/zaatar.webp` | 300x300 | Franchising hero | PARTIAL | POOR (4.3x stretch) | REPLACE | HIGH | H |
| 34 | `doughboss-feast-real-v1.jpg` | 1080x864 | Home hero and catering panel, Catering hero and panel, About hero | Home and About MATCH; Catering PARTIAL | GOOD (strongest site image; soft on Retina heroes) | KEEP, and fix the Catering alt | LOW (alt: MEDIUM) | H |
| 35 | `uploads/contact-*.jpg` (300x200, 1024x683, 2560x1707) | 2560x1707 master | Contact | MISMATCH (stock-style telephone) | GOOD as a file | REPLACE or remove | MEDIUM | H |
| 36 | `uploads/cropped-dbicon-270x270.png` | 270x270 | Favicon, app icon, tile | MATCH | ACCEPTABLE (not square, transparent) | RE-EXPORT | LOW | H |
| 37 | `web/public/hero/ai/manoush-blowout-still.webp` | 1920x1086 | Not live | MISMATCH (reads as Neapolitan pizza) | GOOD as art | REMOVE from `web/public` | HIGH before deploy | H |
| 38 | `web/public/hero/ai/manoush-topdown-reference.webp` | 2048x2048 | Not live | MISMATCH (reads as white pizza) | GOOD as art | REMOVE from `web/public` | MEDIUM | H |

### Per-item relevance (all 43, for the counts)

- **MATCH (15):**
  - Manoush: Zaatar, Zaatar & Cheese, Cheese, Meat, Meat & Cheese.
  - Pizza: BBQ Chicken, Chicken & Cheese, Garlic Prawns, Pepperoni & Cheese, Spinach Deluxe, Veggie Plus.
  - Pies: Spinach Pie.
  - Wraps: Labneh Veggie Wrap, Zaatar & Veggie.
  - Drinks: Orange Juice.
- **PARTIAL (15):**
  - Manoush: Sujuk & Cheese, Half Meat & Cheese, Cheese Kaak.
  - Pizza: Peri Peri Chicken, Sujuk Deluxe, Sujuk Special.
  - Pies: Aged Cheese, Haloumi.
  - Wraps: Chicken Delight, Dough Boss Wrap, Ultimate Chicken.
  - Desserts: Choco Banana.
  - Drinks: Orange & Mango Juice, Orange & Passion Juice, Spring Water.
- **MISMATCH (13):**
  - Manoush: Cheese, Tomato & Olives.
  - Pizza: All Meat, Labneh Veggie Pizza, Zaatar Veggie Pizza.
  - Pies: Dough Boss Pie.
  - Drinks: Apple Juice, Lemon Juice, Lemon & Mint Juice, Coke 600ml, Coke Zero 600ml, Coke Vanilla 600ml, Sprite 600ml, Fanta 600ml.

Rule used: **MATCH** means the item type and its defining ingredients are visible. **PARTIAL** means the right type, but a defining ingredient is not visible or an undescribed extra is. **MISMATCH** means a different product, a different defining ingredient, or another menu item is shown. For Fanta the orange colour may coincide with the flavour, but the customer receives a sealed 600 ml bottle, not a garnished glass (**M**).

Descriptions are empty for these items, so relevance there is judged against the name only:

- Sujuk & Cheese, Half Meat & Cheese, Cheese, Tomato & Olives, and Cheese Kaak;
- Zaatar Veggie Pizza and Labneh Veggie Wrap;
- all 11 drinks that share `juice.webp`.

---

## 4. Adjudication notes: where the reviewers disagreed or were wrong

- **White bars.** One review said Spinach Deluxe, Pepperoni, Cheese Kaak and Meat had white side bars. Measured: Pepperoni and Spinach Deluxe have **none**. The bars are BBQ Chicken and Chicken & Cheese (15-17 px, left and right), Meat (23/18 px, top and bottom) and Cheese Kaak (a 38-45 px inset panel).
- **Sujuk Deluxe quality.** It was rated GOOD in one review and POOR in another. Both are right in context: GOOD as a 550 px card, POOR as a full-width hero source.
- **All Meat.** It was rated MISMATCH/RESHOOT and PARTIAL/RE-EXPORT. **MISMATCH/RESHOOT** stands: re-exporting cannot add the missing meats or the BBQ base.
- **Garlic Prawns** moves from PARTIAL to **MATCH (M)**. The fault is quality, not content.
- **Cheese Kaak** gets **RESHOOT MEDIUM**, not recrop. Cropping cannot show the cheese.
- **Choco Banana** gets **HIGH**, not a LOW recrop. No hero ingredient is visible and an undescribed allergen-relevant topping is.
- **Dough Boss Wrap and Ultimate Chicken** move from MISMATCH to **PARTIAL, MEDIUM**. They under-inform rather than misrepresent. They still go into the first shoot because they are quick to fix.
- **Contact and Spring Water** are MEDIUM, not HIGH. The telephone is irrelevant but not misleading about food. The water bottle may be exactly what is sold.
- **Haloumi** moves from CANNOT_TELL to **PARTIAL, LOW**. A sealed pie is honest about its outside.
- **Labneh Veggie Wrap** moves from CANNOT_TELL to **MATCH against the name (M)**. A white spread and vegetables are visible; the item still needs a description.
- **Half Meat & Cheese** is not called "pizza-style" here. The webp reads as a thin mince-and-cheese bake, which could be a manoush. The problem is that nothing shows a "half".
- **Shared photo.** `veggie-plus.webp` was confirmed by eye to be the same photograph as `real-v1/veggie-plus.jpg`: same board and same topping layout.
- **Provenance.** Several reviewers guessed "stock" for `real-v1` cut-outs.
  - The plugin changelog records the `real-v1` set as "approved real Dough Boss merchant photography" (`docs/wp/01-storefront-map.md` section 5.3). That is prose only: no file carries camera or C2PA data.
  - `real-v1/spring-water.jpg` is plainly a supplier-style packshot, so the folder name alone proves nothing.
  - **Treat every photo as claimed-real-but-unverified until Elie confirms its source.**

---

## 5. Set-level findings

- **Lighting and background.** The menu grid mixes about six looks:
  - white cut-outs (Zaatar, Zaatar & Cheese, Cheese, Meat & Cheese, Sujuk Deluxe, Peri Peri, the two veggie wraps, Choco Banana);
  - a pale grey-lavender studio (BBQ Chicken, Chicken & Cheese, Pepperoni, Spinach Deluxe, Meat, Cheese Kaak);
  - light timber (Veggie Plus, Chicken Delight, Dough Boss Pie);
  - dark timber (Haloumi, Spinach Pie);
  - black (All Meat, the two legacy wraps);
  - single outliers on cyan (Garlic Prawns), dark slate (juice), and warm HDR close-ups (Sujuk & Cheese, Sujuk Special, Aged Cheese, Half Meat & Cheese).

  **H**
- **Angle.** Mostly top-down for rounds. The exceptions are three-quarter or low-angle macros that never show the whole product (Sujuk & Cheese, Sujuk Special, Aged Cheese) and side-on wraps. **H**
- **Crust style.** Thin, pale, flat bases (the grey set, the feast photo) sit beside puffy, charred rims (Peri Peri, Sujuk Deluxe, Sujuk & Cheese), so customers see two different-looking products under one brand. **M**
- **Crop.** Cards are 4:3 cover. Square files lose 25% of their height, the 550x440 files lose about 6%, and the 653-782 px wide files lose 10-25% of their width. Cut-outs that touch the frame edge get their crust clipped. Choco Banana's tips and Chicken Delight's filling end are cut off. **H**
- **Resolution.** No menu file is wider than 782 px. Seven files are 300x300, each upscaled about 3.4x on a 3x phone in the Order card. No plugin image ships a `srcset` on the live pages. **H**
- **Formats.** The legacy files are WebP at 300-500 px. The `real-v1` files are JPEG at 550-782 px and 11-63 KB, with visible compression on the smallest (Spring Water 11 KB, Labneh Wrap 17 KB, Cheese Kaak 20 KB). A responsive AVIF/WebP pipeline exists in a plugin candidate for five photos (`docs/wp/03-staff-dev-gaps.md`), but the live pages serve none of it. **H**
- **Placeholder.**
  - The plugin has a branded placeholder tile, and its 2.34.0 changelog says unverified products use it "instead of a lookalike".
  - Live, it **never appears**, because every item resolves to a photo (all 43 checked).
  - So the 20 legacy-file items show lookalikes against the plugin's own stated policy (`docs/wp/01-storefront-map.md` section 5.3).
  - **H**
- **No folded manoush photo exists**, though several manoush descriptions say "flat or folded". **H**
- **No premises photography.** No page shows a shopfront, oven, counter or staff. Locations has three shops and no shop photo. **H**

---

## 6. In-context rendering findings

From the rendered captures at 1280x800 (DPR 1) and 390x844 (DPR 3) on 2 October 2026, re-checked against the live CSS:

- **No broken images** on any of the 8 pages at either size. An early capture run hit network errors, but a retry run loaded every image (**H**).
- **Hero black strip** on Menu, Order, About, Locations and Franchising. The root cause and fix are in fix 9; the strip is visible in the Franchising screenshot (**H**).
- **Hero upscaling.**
  - Franchising: 300 px stretched to 1,280 (4.3x).
  - Menu, Order and Locations: 550 px stretched to 1,280 (2.3x).
  - Home and Catering backdrop: 1,080 px stretched to about 1,350 (1.25x), about 2.5x on a 3x phone.
  - About: about 1.2x (**H**).
- **Menu and Order** show no food in the first screen (fix 24) (**M**).
- **Repeat use on one page.** The feast photo appears twice on Home and twice on Catering. Sujuk Deluxe appears twice on Home and is the hero on three more pages (**H**).
- **Mobile drinks block.** Eleven identical photos in a single column, about 4,000 px of scrolling (**M**, measured in capture).
- **Contact** shows the telephone photo under a "30 November 2022" post date (**H**).
- **Phone sharpness.** On a 3x phone, All Meat, Garlic Prawns and Ultimate Chicken are visibly soft and blocky in the 3x crop. Ultimate Chicken's bread is blown out to white (**H**).

---

## 7. Verdict on the AI hero stills

Files: `web/public/hero/ai/manoush-blowout-still.webp` and `manoush-topdown-reference.webp`, with JSON sidecars. Both were generated on 2026-10-02. **Neither is referenced by any live page.**

- **They do not read as a Dough Boss cheese manoush (H).**
  - Both show a tall, airy, leopard-charred Neapolitan-style rim, with mint leaves and chilli flakes.
  - The real cheese manoush (`real-v1/cheese.jpg`, and the bottom right of the feast photo) is thin, with a pale, low edge, plain browned cheese and no garnish.
- **The blow-out has construction errors (M-H).**
  - The right-hand piece is a long rectangular strip, not a wedge.
  - The pieces do not reassemble into one round.
  - Cheese strands span pieces that would not be next to each other.
  - The embers and flare imply an open wood fire. What oven Dough Boss uses cannot be told from the photos.
- **The disclosure does not travel with the file (H).** The "illustrative AI-generated" notice exists only in the JSON sidecar. The WebP files carry no EXIF, XMP or ICC data (checked), so the label is lost the moment a file is copied elsewhere.
- **It conflicts with the recorded rule.** The project's must-not-break list says "real photography and honest copy only" (`docs/wp/03-staff-dev-gaps.md`), and the generated hero scene was removed in plugin 2.34.0 in favour of real photography.
- **Verdict:**
  - Not for the live site.
  - Move both out of `web/public/` (anything there is publicly served once `web/` deploys).
  - Embed the IPTC `DigitalSourceType` value `trainedAlgorithmicMedia`.
  - Keep them as internal mood references only.
  - Build any animated hero from layers of a real Dough Boss photo.
  - Any exception needs Elie's explicit written override and the conditions in section 9.

---

## 8. Alt-text findings

| Where | Current alt | Finding | Suggested |
|---|---|---|---|
| Home story image and Home "Pizza" card | "Real Dough Boss Sujuk Deluxe pizza" (x2) | "Real" is a claim the file cannot back, and the photo omits two listed toppings | "Sujuk Deluxe pizza" (story); "Pizza" or a category description (card) |
| Home "Manoush" card | "Real Dough Boss zaatar and cheese manoush" | Accurate content; "Real" prefix | "Zaatar and cheese manoush, half and half" |
| Home "Pies" card | "Real Dough Boss oven-baked haloumi pie" | The filling is not visible | "Haloumi pie" |
| About story image | "Real Dough Boss meat and cheese manoush" | Accurate content; "Real" prefix | "Meat and cheese manoush" |
| Home and Catering panels | "A real Dough Boss catering spread" | No catering format is visible | "Manoush, pizzas and pies on a table" |
| `og:image:alt` (6 pages) | "Fresh zaatar manoush at Dough Boss" | It sits on an image that does not match the real product photos | Describe the replacement card literally |
| 6 hero `<img>` and the Contact image | `alt=""` | Acceptable only if purely decorative. On Locations, Franchising and Contact it is the page's only photo. | Keep empty for decorative heroes; give a real shopfront photo a literal alt |
| 43 menu cards | none (CSS backgrounds) | The photo is invisible to assistive technology and image search | `<img>` with the item name and a literal description |

---

## 9. Conditions for the claims ledger

What the site may and may not say or show about its photos. These are recommendations to keep representations accurate (ACL s 18 and s 29(1), as summarised in `docs/marketing/research/compliance-au.md` section 1). They are not legal advice.

1. **"Real" or "our" claims** ("Real Dough Boss ...", "our pizza") may sit only on a photo with a recorded source: who shot it, when, at which store, and that it shows the product as sold. No photo has such a record today. Until one exists, use plain descriptive alt text.
2. **One photo, one item.** A photo may represent only the item it actually shows. A shared or stand-in image must either be replaced by the branded placeholder or carry a visible label such as "Image for illustration only". It must never show toppings, fillings or a product type the item does not have.
3. **What is visible must be served.** Every topping or filling named in the description should be visible, or at least not contradicted. Nothing visible should be absent from the product. Garnishes and props (lemon wedge, mint, chilli, sesame, drizzle) appear only if the customer gets them.
4. **Dietary tags.** A photo must never show something that contradicts the item's tags (meat on a vegetarian item, pork or alcohol anywhere). Photos are not evidence of any dietary or halal claim. Aged Cheese must be confirmed before reuse.
5. **AI-generated or stock imagery** is never used to depict a menu item, and never appears on menu, order, price, ads, social or Google Business surfaces. If Elie ever approves decorative use, it must:
   - carry a visible "Illustration" label;
   - carry embedded IPTC `trainedAlgorithmicMedia` metadata;
   - have empty or decorative alt text;
   - sit beside a real product photo.
6. **Third-party brands** (soft drinks, bottled water) are shown only for products actually stocked, in the size shown, using the business's own photo of its own stock, with no suggestion of endorsement.
7. **Catering** imagery may be called a catering spread or platter only if it shows the catering format actually supplied.
8. **Copy beside a photo** must not describe formats or products the photo does not show.
9. **Provenance log.** Every new photo gets a log row (file, item, date, store, owner, licence) kept with the business records, plus IPTC Creator and Copyright in the file.

---

## 10. What is firm and what is not

**Firm (H), verified by eye or by measurement:**

- the reuse counts and every MISMATCH listed above;
- the missing toppings on Sujuk Deluxe, All Meat and Dough Boss Pie;
- no filling on the two legacy wraps;
- the "nu 1 Litre" label;
- the file sizes, white bars and crop losses;
- the hero-strip cause;
- the alt texts and missing Open Graph tags;
- the AI stills not being live and carrying no embedded metadata.

**Depends on information the photos cannot give (M or L):**

- whether any photo is Dough Boss's own, stock, or from another business;
- the sausage type in any photo;
- what the Choco Banana drizzle is;
- the Aged Cheese filling and the red-brown strips;
- how the juices are served;
- which soft drinks and sizes are stocked;
- what "Half" means;
- whether Cheese Kaak contains cheese;
- the oven type.

**Images that could not be viewed well:**

- Toppings on the 300x300 files (Sujuk Special, Garlic Prawns, All Meat) are judged at low detail.
- The 16/32/180/192 px favicon files and the 768/1536/2048 Contact sizes were not downloaded. They were judged from the 270 px master and the other sizes.
- The unused `menu/real-v1/juice.jpg` named in `docs/wp/01-storefront-map.md` was not reviewed.
- Phone sharpness was judged from emulated 3x captures, not a physical device.

**Confirm with the store before the shoot:**

- descriptions for the items that have none;
- the Choco Banana product and allergen information;
- the Aged Cheese filling;
- the meaning of "Half";
- the Cheese Kaak contents;
- which drinks are stocked and in what form;
- the Spring Water brand and size;
- whether a catering platter or box format exists.
