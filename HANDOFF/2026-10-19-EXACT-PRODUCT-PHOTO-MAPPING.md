# 19 October 2026 — exact product photo selection

The public anonymous 43-item website menu readback still showed generic juice.webp assigned to 11 distinct named drinks, plus veggie-plus.webp assigned to multiple different products. The candidate backend retained those category/default aliases even though frontend cards had been repaired. This batch corrects source selection.

## Implemented candidate

- Removed category-wide photo fallback. An unrecognised product name returns an empty image URL so the existing frontend renders its branded tile.
- Removed eight unverified cross-product aliases: Half Meat & Cheese; Cheese, Tomato & Olives; Zaatar Veggie Pizza; Labneh Veggie Pizza; Sujuk Special; Spinach & Cheese; Chicken Pie; Shanklish.
- Preserved featured WordPress upload precedence and other existing exact-name image mappings.
- Owner confirmed Coca-Cola, V, Juice World and generic water, and instructed using Dough Boss Uber Eats as reference. The Revesby listing identifies 500mL juice variants, 600mL Coca-Cola and spring water, and 350mL V bottles. Third-party prices were not imported.
- Inspected https://juiceworld.net.au/products and downloaded six exact 500mL manufacturer JPEGs: Apple, Lemon, Lemon & Mint, Orange, Orange & Mango, Orange & Passionfruit. Each label was visually inspected and matched to the existing website flavour. Original bytes are retained; total 118,037 bytes.
- Added six explicit flavour-to-image mappings. Soda items without a verified photo now remain empty rather than displaying juice. V does not exist in the current 43-item website catalogue and was not silently added.

Manufacturer product-photo sources:

| Website item | Source URL | SHA-256 |
| --- | --- | --- |
| Apple Juice | https://juiceworld.net.au/product_photos/1944906309500ml-apple-juice.jpg | 4f05c2f20a1eec68b4d780d47a408fe96916a3dc830c4c87b189e5b8b61ab0ec |
| Lemon Juice | https://juiceworld.net.au/product_photos/1931679022500ml-lemon.jpg | 81306c62511530d070f1d2bb563f8e0daa722138569f62b203a80b96b8baf5e0 |
| Lemon & Mint Juice | https://juiceworld.net.au/product_photos/259450824500ml-lemon-mint.jpg | 8018b1f009b29168526ea10e546822f27b0746422b4310ee66b0d95f0bec67f2 |
| Orange Juice | https://juiceworld.net.au/product_photos/1253476244500ml-orange-juice.jpg | 00a6d84d42bfa742ea83973ca6e1ae82d261faaec6755aab5c95475b4dfc2e85 |
| Orange & Mango Juice | https://juiceworld.net.au/product_photos/1060332853500ml-orange-mango.jpg | 358f76b3312d39e5f6fe080334d828b1ccce65c3f7c67a0f03e5dda201ad8130 |
| Orange & Passion Juice | https://juiceworld.net.au/product_photos/837301581500ml-orange-passionfruit.jpg | 4cda372699d50b300b573e06a226d17f7051adf79d75f8e9ee6e2b0764c2aca8 |

Local files are in public/images/menu/brand-v1 with explicit flavour names. These manufacturer originals are 500x333; they are not represented as new high-resolution photography. This record does not assert an independent licence.

## Validation and remaining evidence

Full PHP 8.2 suite: **3232 assertions / 0 failures**, covering unknown names across every category, five unphotographed soda names, six exact juice flavours with existing decodable JPEGs, eight removed aliases and retained exact-name originals. Both changed PHP files parse, and CRLF-aware diff hygiene passed.

The image field remains a string with the existing empty-image contract. No price, option, availability, checkout, database, provider or featured-upload mutation occurs. REST controller runtime bytes changed, so earlier source-pinned database/package evidence is not automatically current. Build and validate fresh archives after the next source freeze.

Nothing in this batch is installed live. Coca-Cola/Fanta/Sprite packshots, generic-water image matching and remaining food-photo audit are still open. Merchant OAuth is not customer login; membership, payments, Ads, other-shop connections and device activation remain off.

Fresh GPT-6.1 Sol review of the updated six mappings and tests returned **SHIP_LOCAL_SOURCE_ONLY**, with no actionable findings. The reviewer independently decoded all six JPEGs at 500x333 and verified exact flavour mapping and unchanged response shape. This is source acceptance, not publication or a licence declaration.
