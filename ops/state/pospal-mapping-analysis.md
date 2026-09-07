# Dough Boss — website menu → POSPal till mapping

Source: `for-fable.json` (34 site items, 136 till products). Nothing external fetched; no product invented.
Output: `map.json` holds the 30 entries rated `certain`/`probable`. **4 items are deliberately left out** and need a human decision (section 2).

Operating facts (from the plugin source, taken as given): key = lowercased, whitespace-collapsed site name; exact lookup;
`manualSellPrice` = website price (so a till/site price gap never mis-charges the customer, it only affects which uid the sale,
stock and reporting attach to); **one unmapped item silently drops the whole order**; size/topping choices are a line comment.

Confidence scale used here:
- `certain` — exact or trivially-variant name match, unique full-size candidate, no conflicting evidence.
- `probable` — unique reasonable candidate, but name differs or price differs; include, glance at it once.
- `ambiguous` — two or more candidates and the evidence does not settle it; **not in map.json**.

---

## 1. Proposed mapping (all 34)

| # | Site item (price, cat) | Till product (price) | uid | Conf. | Evidence |
|---|---|---|---|---|---|
| 1 | Aged Cheese ($10, Pies) | Shanklish Pie ($10) | 383383408068295061 | probable | Site description literally says "(shanklish)"; price exact; only full-size shanklish product. Rejected: Mini Shanklish $2.50 (27452188083372288), SHANKLISH DOZ $26 (427413701758748606). |
| 2 | All Meat ($15, Pizza) | All Meat ($15) | 1000176113527479459 | certain | Exact name + price. |
| 3 | BBQ Chicken ($14, Pizza) | BBQ Chicken ($14) | 601960757835269803 | certain | Exact name + price. Rejected: BBQ CHICKEN DOZ $26 (868976876966880356). |
| 4 | Cheese ($9.50, Manoush) | Cheese ($9.50) | 357464733811096509 | certain | Exact name + price. Rejected: CHEESE PIZZA DOZ (998277656012703401), Cheese Kaak. |
| 5 | Cheese Kaak ($9.50, Manoush) | Cheese Kaak ($10) | 395419170033572901 | certain | Exact name, unique. Price −$0.50 (see §4). |
| 6 | Cheese, Tomato & Olives ($9.50, Manoush) | Cheese Tomato Olives ($12) | 533157990367203157 | probable | Same name minus punctuation; unique. Price −$2.50 — largest gap in the set, see §4. |
| 7 | Chicken & Cheese ($14, Pizza) | Chicken & Cheese ($14) | 589211437208351718 | certain | Exact name + price. |
| 8 | Chicken Delight ($14, Wraps) | Chicken Delight Wrap ($14) | 54006388924430607 | certain | Name + "Wrap" suffix; category and price match; unique. |
| 9 | Choco Banana ($13, Desserts) | Choco Banana ($13) | 767823326775608113 | certain | Exact name + price. |
| 10 | Dough Boss Pie ($11, Pies) | Dough Boss Pie ($11) | 650564251717128871 | certain | Exact name + price. Note: till also has "Chicken Pie" $11 (287045296882957736) whose likely contents match the site description (grilled chicken, capsicum, mushroom, cheese) — possible duplicate SKU on the till; exact name wins. |
| 11 | Dough Boss Wrap ($14, Wraps) | Dough Boss Wrap ($14) | 770965248117830777 | certain | Exact name + price. |
| 12 | Garlic Prawns ($15, Pizza) | Garlic Prawns ($15) | 75687466824665548 | certain | Exact name + price. |
| 13 | Half Meat & Cheese ($11, Manoush) | 1/2 Meat 1/2 Cheese ($10) | 1105411054394869509 | probable | Description "half minced meat and half blended cheese" = "1/2 Meat 1/2 Cheese"; unique. Price +$1.00. Rejected: Meat & Cheese $11 (1024632448679161168) — that is the site's separate "Meat & Cheese" item (#19), despite the coincidental $11. |
| 14 | Haloumi ($11, Pies) | Halloumi Pie ($11) | 853774576125599873 | certain | Spelling variant; category + price exact; only full-size halloumi. Rejected: Mini Halloumi $2.50 (1113054642612602444), HALLOUMI DOZ $26 (887059670818242605). |
| 15 | Juice ($4.50, Drinks) | — | — | **ambiguous** | Six $4.50 juices, no generic product. See §2. |
| 16 | Labneh Veggie Pizza ($13, Pizza) | Zaatar Labneh & Veggie Pizza ($13) | 143604223896833881 | probable | Only pizza on the till containing labneh; price exact. Conflict: site description does not mention zaatar. Rejected: Labneh & Veggie Roll $9 (967331084193529613, a roll), Zaatar Labneh Veggie Roll $11 (177102653036773144, a roll). See §2 for the pairing logic. |
| 17 | Labneh Veggie Wrap ($8.50, Wraps) | Labneh & Veggie Roll ($9) | 967331084193529613 | probable | Name match with Roll≡Wrap (proven by #33: site "Zaatar & Veggie" wrap $8.50 = till "Zaatar & Veggie Roll" $8.50). Price −$0.50. Rejected: Zaatar Labneh Veggie Roll $11 (has zaatar, add-on variant). |
| 18 | Meat ($9, Manoush) | Meat ($9) | 200026100942585173 | certain | Exact name + price. Rejected: Mini Meat (1082031397849684824), MEAT DOZ (883504711687841523), Meat Kebbeh, Meat Samboussek. |
| 19 | Meat & Cheese ($11, Manoush) | Meat & Cheese ($11) | 1024632448679161168 | certain | Exact name + price. Rejected: MEAT CHEESE DOZ $26 (275001002432715844), Mini Meat & Cheese $2.50 (1016769847752840430). |
| 20 | Pepperoni & Cheese ($13, Pizza) | Pepperoni & Cheese ($13) | 1147758441649868374 | certain | Exact name + price. |
| 21 | Peri Peri Chicken ($14, Pizza) | Peri Peri Chicken ($14) | 603578442243939595 | certain | Exact name + price. |
| 22 | Soft Drinks 600ml ($5, Drinks) | — | — | **ambiguous** | Five 600 mL SKUs at $5; Solo does not exist on the till. See §2. |
| 23 | Spinach Deluxe ($13, Pizza) | Spinach Deluxe ($13) | 975814623541456188 | certain | Exact name + price. Rejected: SPINACH DOZ (456550183898565037). |
| 24 | Spinach Pie ($10, Pies) | — | — | **ambiguous** | Name → Spinach Pie $9; price + description → Spinach & Cheese Pie $10. See §2. |
| 25 | Spring Water ($3.50, Drinks) | Water ($3.50) | 689784845052146676 | certain | Only still water; price exact. Rejected: Mount Franklin Sparkling Water $4.50 (303347172613985858) — site says "Still". |
| 26 | Sujuk & Cheese ($11, Manoush) | Soujouk & Cheese ($12) | 580417599268709770 | probable | Transliteration variant of the same name; unique manoush candidate. Price −$1.00. Rejected: Sujuk & Egg $12 (933978724249213950) — egg not on site. |
| 27 | Sujuk Deluxe ($14, Pizza) | Sujuk Deluxe ($14) | 320293836119968026 | certain | Exact name + price. (Your first pass bundled this with Sujuk Special — on its own it is unambiguous.) |
| 28 | Sujuk Special ($15, Pizza) | — | — | **ambiguous** | No till product of that name; "Dough Boss Special" $15 is the lean. See §2. |
| 29 | Ultimate Chicken ($14, Wraps) | Ultimate Chicken ($14) | 554485541575844924 | certain | Exact name + price. |
| 30 | Veggie Plus ($13, Pizza) | Veggie Plus ($13) | 892839511100556809 | certain | Exact name + price. |
| 31 | Zaatar ($4.50, Manoush) | Zaatar ($4.50) | 698845363306336552 | certain | Exact name + price. Rejected: Mini Zaatar $2.50 (877288740435417345), ZAATAR DOZ $20 (953846371310224399). |
| 32 | Zaatar & Cheese ($8.50, Manoush) | Zaatar & Cheese ($8.50) | 1008630471358438942 | certain | Exact name + price. Site says "zaatar on one half, cheese on the other" = half/half, so "Zaatar & Cheese mixed" $9 (798541604693866312) is correctly rejected. Rejected: Mini Zaatar & Cheese (121193143933433104). |
| 33 | Zaatar & Veggie ($8.50, Wraps) | Zaatar & Veggie Roll ($8.50) | 258385677505526747 | certain | Name + Roll, category Wrap, price exact. Rejected: Zaatar & Veggie Pizza $11 (pizza), Zaatar Cheese/Labneh Veggie Roll $11 (add-on variants). |
| 34 | Zaatar Veggie Pizza ($13, Pizza) | Zaatar & Veggie Pizza ($11) | 44756336935373349 | probable | Name match (ampersand only); the only non-labneh zaatar pizza. Price +$2.00. See §2. |

Summary: 24 certain, 6 probable (in `map.json`), 4 ambiguous (excluded).

---

## 2. Items that must NOT be auto-mapped — and the ones that can be

### Confirmed ambiguous (human decides) — 4 items

**Spinach Pie $10 — ambiguous, lean Spinach & Cheese Pie (~65/35).**
- Spinach Pie $9 → 997175960298317979 (name match only)
- Spinach & Cheese Pie $10 → 135599981968615601 (price match + description "and cheese")
- Two evidence points against one, and the site has no separate spinach-and-cheese item, which fits "the online menu only sells the cheese version". But site prices deviate from the till in both directions (§4), so price equality is not decisive, and "and cheese" could be a copywriting slip. Traditional fatayer sabanekh has no cheese, so the description's explicit "and cheese" is either deliberate or careless — the data cannot say which.
- Resolution: a 10-second question to the owner — *"Does the website Spinach Pie come with cheese?"* Yes → 135599981968615601. No → 997175960298317979 and fix the website description.

**Juice $4.50 — coin-flip, cannot be resolved from the data.**
- Six equal-priced candidates: JW Apple 886511720806357678, JW Lemon 1044970076495943972, JW Lemon Mint 792710851415057990, JW Orange & Mango 782156886714765625, JW Orange & Passion 783446383126357221, Sunzest Orange 930443682739176328. (Keri Apple / Apple & Blackcurrant are $3.50 — wrong price, rejected; Ayran is not juice.)
- The site description ("Chilled fruit juice") lists no flavours at all, so it is not even clear the website collects a flavour choice to put in the comment.
- Resolution options for the owner, in order of correctness: (a) split the website item into six flavour items and map each 1:1; (b) create a generic "Juice" product on the till and map to it; (c) knowingly pick one placeholder uid (stock will deduct against the wrong flavour). Any of these is a business decision, not a guess I should make.

**Soft Drinks 600ml $5 — coin-flip, plus a catalogue gap. Highest operational impact of the four.**
- Candidates at $5/600 mL: Coke Classic 292754875634270678, Coke Zero 389059485108812919, Coke Vanilla 323655520593920820, Sprite 933253461615156581, Fanta 643186909438094785.
- **Solo is on the website but does not exist on the till in any size.** A Solo order cannot be recorded against a real SKU whatever uid is chosen.
- Because a soft drink is the most common add-on to a food order, leaving this unmapped will drop a large share of orders (silent abandon). Same resolution options as Juice; if the owner wants a fast interim, the honest version is a *deliberately chosen* placeholder (e.g. Coke Classic) with the flavour in the line comment, understanding stock reporting for drinks becomes unreliable — and Solo either gets added to the till or removed from the site.

**Sujuk Special $15 — ambiguous, lean Dough Boss Special.**
- Dough Boss Special $15 → 956629099921120835: price exact, "Special" token, the only other $15 pizza not already claimed (All Meat and Garlic Prawns are taken by exact matches). Against: no "sujuk" in the name and the till has no description, so its ingredients are unknown. The "Dough Boss" prefix is not a sujuk tell — Dough Boss Wrap is sujuk, Dough Boss Pie is chicken.
- Sujuk Deluxe $14 → 320293836119968026 is *not* a valid fallback: it is the exact match for the site's own Sujuk Deluxe, and the site descriptions show Special = Deluxe + onion + tomato base for $1 more, i.e. two distinct products.
- Sujuk & Egg $12 → 933978724249213950: egg is not on the site description; rejected.
- Resolution: ask *"Is the till's Dough Boss Special the sujuk special?"* Yes → 956629099921120835. No → the till needs a new product; do not map to Sujuk Deluxe.

### Your flagged items I resolve as `probable` (include, but glance once)

**Labneh Veggie Pizza $13 → Zaatar Labneh & Veggie Pizza $13 (143604223896833881)** and
**Zaatar Veggie Pizza $13 → Zaatar & Veggie Pizza $11 (44756336935373349).**
- The till pattern is base + add-on: "Zaatar & Veggie Roll" $8.50 → "Zaatar Labneh Veggie Roll" / "Zaatar Cheese Veggie Roll" $11 (+$2.50, exactly the site's "Add labneh or cheese +$2.50"). The pizzas follow the same shape: "Zaatar & Veggie Pizza" $11 → "Zaatar Labneh & Veggie Pizza" $13.
- Two distinct site items must not share a uid, and this is the only pairing that (a) matches the name "Zaatar (&) Veggie Pizza", (b) puts the only labneh pizza on the labneh item, and (c) avoids mapping a pizza to a roll.
- Residual doubt: the site's Zaatar Veggie Pizza says "with cheese" at $13, which reads like a cheese variant the till does not have as a pizza SKU (it only has the roll). Mapping it to the $11 base is the least-wrong option: the customer still pays $13 (manualSellPrice), the till just records it under the base SKU. And the site's Labneh Veggie Pizza omits "zaatar" — mild conflict, but there is no zaatar-free labneh pizza on the till.
- The 34-item list contains no `Zaatar Labneh Veggie Roll`/`Zaatar Cheese Veggie Roll` equivalents; those variant SKUs will never be hit from the web because add-ons are comments, not products. That is a reporting limitation of the plugin design, not a mapping error.

**Sujuk Deluxe $14** — certain, exact match; only its sibling (Sujuk Special) is ambiguous.

---

## 3. Adversarial critique of the mapping (done before finalising)

Checked programmatically (`build_map.py`) and by hand:

- **Dozen / bulk SKUs:** none of the 30 chosen uids has "DOZ" in the name or a price ≥ $20. The nine tempting collisions (BBQ CHICKEN DOZ, CHEESE PIZZA DOZ, HALLOUMI DOZ, MEAT DOZ, MEAT CHEESE DOZ, SHANKLISH DOZ, SPINACH DOZ, ZAATAR DOZ, PIZZA/MIXED/MARGARITA/FETTA DOZ) are all explicitly rejected above.
- **Mini $2.50 SKUs:** none chosen. Every site item with a mini twin (Zaatar, Zaatar & Cheese, Meat, Meat & Cheese, Haloumi, Aged Cheese/Shanklish, Spinach) is mapped to the full-size product. The generic "MINI ITEM $2.50" (781140564326613737) is also untouched.
- **Roll ↔ pizza:** both site wraps that have roll/pizza siblings (Labneh Veggie Wrap, Zaatar & Veggie) map to `…Roll`; both site pizzas map to `…Pizza`. Checked mechanically by category.
- **Duplicate uids:** none — 30 distinct uids for 30 keys. I judged sharing a uid *unacceptable* here (it would silently merge two products' sales history) and structured the veggie-pizza pairing specifically to avoid it. Had the pairing been unresolvable I would have flagged both rather than double-map.
- **Duplicate till names:** "Dough 1kg" (278352984834166261) and "Dough 1KG" (994032663983823439) both exist at $7. Neither is on the website, so the choice never arises; worth deleting one on the till regardless. No other duplicate names (case-insensitive check across all 136). Note one trailing-space name, "FROZEN VEGETERIAN KEBBE DOZ " — harmless, not mapped.
- **Near-miss names I deliberately did not take:** Chicken Pie $11 (vs Dough Boss Pie), Zaatar & Cheese mixed $9 (vs Zaatar & Cheese), Sujuk & Egg $12 (vs Sujuk & Cheese / Special), Mount Franklin Sparkling (vs Spring Water), Keri juices at $3.50 (vs Juice).
- **Key-normalisation risk (needs one verification by the integrator):** 8 of the 30 keys contain an ampersand. The sample data shows "&" decoded in names but a raw `&amp;` in the Sujuk Special description, so the site clearly stores entities somewhere. If the plugin builds its key from an *entity-encoded* title (`cheese, tomato &amp; olives`), all 8 ampersand items would miss the map and drop their orders. Confirm the plugin decodes entities before normalising (or that the stored title is already decoded). The key `cheese, tomato & olives` also depends on the comma being present in the live product title exactly as in the data.
- **What I could not verify:** whether `manualSellPrice` includes add-on surcharges (e.g. Labneh Veggie Wrap + cheese = $11). If it sends the base $8.50, the till under-records revenue by the add-on on those lines. Outside the mapping brief, but it is a money-integrity point worth a test order.

Nothing material was found on the second pass; the mapping stands as written.

---

## 4. Price-delta table (site price − till price)

| Site item | Site | Till product | Till | Delta |
|---|---|---|---|---|
| Cheese Kaak | $9.50 | Cheese Kaak (395419170033572901) | $10.00 | −$0.50 |
| Cheese, Tomato & Olives | $9.50 | Cheese Tomato Olives (533157990367203157) | $12.00 | −$2.50 |
| Half Meat & Cheese | $11.00 | 1/2 Meat 1/2 Cheese (1105411054394869509) | $10.00 | +$1.00 |
| Labneh Veggie Wrap | $8.50 | Labneh & Veggie Roll (967331084193529613) | $9.00 | −$0.50 |
| Sujuk & Cheese | $11.00 | Soujouk & Cheese (580417599268709770) | $12.00 | −$1.00 |
| Zaatar Veggie Pizza | $13.00 | Zaatar & Veggie Pizza (44756336935373349) | $11.00 | +$2.00 |
| *(if resolved to Spinach Pie)* Spinach Pie | $10.00 | Spinach Pie (997175960298317979) | $9.00 | +$1.00 |
| *(if resolved to Spinach & Cheese Pie)* Spinach Pie | $10.00 | Spinach & Cheese Pie (135599981968615601) | $10.00 | $0.00 |

The other 24 mapped items are price-identical. Reading: the gaps run both ways, so neither catalogue is uniformly stale. The four manoush/roll items where the site is *lower* (kaak, cheese-tomato-olives, labneh roll, sujuk & cheese) look like the till was repriced after the website was built. Because the plugin charges the website price, online customers currently pay $0.50–$2.50 *less* than walk-ins on those four — real leakage for the bakery, small per item. The $12 Cheese Tomato Olives is the one to query first: $2.50 above plain Cheese ($9.50) is exactly one add-on unit, so it may be intentional, or a till typo.

---

## 5. Till products not on the website (business observation, no action)

The till carries a full café/breakfast/catering range the site does not sell: 14 coffee/hot-drink lines (Espresso through Dirty Chai, Babyccino, iced coffees), 4 milkshakes at $6, Aussie Breakkie $14, Sujuk & Egg $12, Kafta & Cheese $11 / Kafta Extra $13, hot and frozen samboussek/kebbeh, Kaak Sticks, dough and bases, 13 "…DOZ" catering packs ($20–$35), canned soft drinks at $3.50, Coke 330 mL glass, sparkling water, Fuze iced teas, Ayran, Powerade/V/Red Bull, and a $0.50 Takeaway Box. Conversely the site offers Solo, which the till does not stock at all.
