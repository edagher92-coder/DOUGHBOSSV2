# Meta creative brief: real photography and short video, DRAFT

Prepared 2026-10-02 for Elie and the store teams. Internal planning document, so it carries `[CONFIRM: ...]` and `[VERIFY: ...]` items. Nothing here has been shot, uploaded or published. Companion to `marketing/meta/copy.csv` and `docs/marketing/03b-meta-ads.md`.

## 1. The rule that matters most

The AI "blowout" and exploded-pizza hero images are ILLUSTRATIVE. They are artwork. They are not photographs of the real product. They must not be used as if they were photographs of what a customer will receive, so they must not be the picture in any Dough Boss ad.

Why: the Australian Consumer Law bars false or misleading representations about the quality, composition or nature of goods in promotion (practical summary in `docs/marketing/research/compliance-au.md` section 1; LAWYER to confirm). An ad image says "this is what you get". If the image is a render, it says something we cannot back. Real photographs of real food, taken in our own stores, are the only food images in ads.

Rules that follow:

1. Every food image in an ad is a real photograph or real video of Dough Boss food, shot in a Dough Boss store or at a real handover.
2. What is photographed is what the customer receives. No props that are not supplied (extra platters, drinks, garnishes, boards). If the shot is styled, the styling is something the customer also gets, or the shot is plainly of the store and the baker, not of a catering order.
3. An illustrative graphic may be used for a logo lock-up or a background texture only if it carries no food that could be read as the product, and Elie approves it. Our default is no.
4. Minis: no Minis image of any kind until real Minis exist and have been photographed. No stand-ins, no stock, no render. The Minis ads stay held until then (campaigns.json).
5. No stock photography and no generated people. No image implies a customer, a review or an endorsement that is not real.

## 2. What the ads need to show, by angle

| Angle (from copy.csv notes) | What the viewer needs to see in the first frame | Ads that use it |
| --- | --- | --- |
| office-breakfast | A real breakfast order laid out for a team, in an office or meeting setting | CORP-BKT-01, CORP-BKT-02, CORP-REV-01 |
| team-lunch | A real lunch order on a table or bench, ready for a group | CORP-BKT-03, CORP-REV-02, CORP-ROS-01 |
| meeting-spread | A meeting-room table with a real order unpacked | CORP-BKT-04, CORP-REV-03 |
| event-party | A real party-table order | EVNT-ALL-01, EVNT-ALL-02, EVNT-ALL-04 |
| event-community | A real order at a community or club setting, with permission | EVNT-ALL-03 |
| minis-waitlist, minis-organiser | The real Minis, once they exist | MINI-ALL-01, 02, 03 (held) |
| catering-enquiry, catering-question | Reuse a proven photo from the corporate set | WARM-ALL-01, 02 (held) |

Each ad needs its own picture or video. The test method (03b section 8) changes one variable at a time, so keep the base picture constant when the variable under test is the headline.

## 3. Photography shot list (stills)

Shoot on a phone or camera, natural light where possible, a clean surface, no filters that change colour, no retouching that changes the food. Shoot every setup in vertical (9:16 and 4:5 crops) and a wider frame (1:1 or 4:5), so one session feeds Feed, Stories and Reels.

Before the shoot, `[CONFIRM: what the catering offer physically is: packaging, trays, boxes, labels]`. The shots below show it as it is, not as we wish it were.

| Code | Shot | Setting and notes | Needed for |
| --- | --- | --- | --- |
| P1 | Breakfast order laid out for a team | A desk or meeting table. Include the packaging as supplied. Show the real items in the real order. Hands only, no faces. | office-breakfast |
| P2 | Lunch order laid out for a group | Bench or table, one clean overhead shot and one three-quarter angle. | team-lunch |
| P3 | Meeting-room table with an order unpacked | A real or borrowed meeting room, with permission. No client logos, whiteboards with writing, laptops showing data or documents. | meeting-spread |
| P4 | Party-table order | A party or event setting, with permission. No children's faces. | event-party |
| P5 | Minis, once they exist | Real Minis only, on a clean surface, a hand for scale. | Minis (held) |
| P6 | Close-up of the food, one item per frame | Overhead and side angles, one item at a time. These are cutaways for video and thumbnails. | all |
| P7 | The baker at work | Hands shaping, topping, or taking something out of the oven. No faces unless the person has signed a release. | all |
| P8 | Store front and sign | Each store, daytime, straight on, with the real sign visible. Bankstown, Revesby, Roselands Centro. | store trust, location copy |
| P9 | Packing and handover | The order being packed and handed over, with hands only. | all |
| P10 | Allergen information as displayed in store | A real printed card or board, legible. Only if it exists. `[CONFIRM: what allergen information the stores hold]` | trust |

For the allergen shot: do not show an allergen statement that is not true or not complete. FSANZ allergen names and display duties are in `docs/marketing/research/compliance-au.md` section 7.

Technical: shoot at the highest native resolution; keep the originals. For the Feed export use 4:5 at 1440 x 1800 pixels (Meta's Feed image guide, OBSERVED 2026-10-02, https://www.facebook.com/business/ads-guide/image/facebook-feed/traffic: JPG or PNG, 4:5, 1440 x 1800, minimum width 600, maximum 30 MB). Keep the key subject in the centre two thirds so a 1:1 or 9:16 crop does not cut it.

## 4. Video shot list (short, vertical, silent-first)

Aim for short videos that make sense with the sound off. Length: keep each under about 15 seconds `[VERIFY: Meta's current recommended lengths per placement]`. Each video opens on the food or the handover, not on a logo. All text is burned in (section 6).

| Code | Working title | Beats in order (about one second or two each) | Needed for |
| --- | --- | --- | --- |
| V1 | Breakfast arrives | Order being packed. Closing the lid or bag. Walking through an office door or lobby, hands only. Placing it on the table. Opening it. A hand takes an item. End card: store name and address from the typed store data. | CORP-BKT-02 |
| V2 | Team day unpack | A table in a meeting room. Pieces laid out one by one. Wide shot of the spread. A hand takes one. End card: store name. | CORP-REV-03 |
| V3 | Party table | Packing at the store. Arrival at the event setting. The table filling up. Wide shot. End card: store name. | EVNT-ALL-04 |
| V4 | Community table | A club, school or community setting with permission, adults only. | EVNT-ALL-03 |
| V5 | From the oven | The baker takes a tray from the oven. A close-up of the food. A hand lifts a piece. | any, cutaway |
| V6 | Minis (held) | Real Minis only. | MINI-ALL-03 |

Do not narrate claims. No voiceover that states anything the claims rule does not allow. If audio is used, use a track the business has the rights to; Meta's own licensed music is limited to certain placements `[VERIFY]`. Prefer no music, or a track licensed to Dough Boss.

## 5. Formats and safe zones

| Placement | Ratio | Pixels | Source and status |
| --- | --- | --- | --- |
| Facebook and Instagram Feed (image) | 4:5 | 1440 x 1800 | OBSERVED, Meta Feed image guide, 2026-10-02 (URL above). Minimum width 600 pixels, minimum height 750 pixels, ratio tolerance 3 per cent. |
| Feed (video) | 4:5 preferred, 1:1 acceptable | 1080 x 1350 or 1080 x 1080 | `[VERIFY: current Meta video spec]` |
| Stories and Reels | 9:16 | 1080 x 1920 | `[VERIFY: current Meta Stories and Reels spec]` (the guide page I tried returned 404). |
| Fallback | 1:1 | 1080 x 1080 | `[VERIFY]` |

Text guidance from the same Feed guide (OBSERVED 2026-10-02): primary text of roughly 50 to 150 characters and a headline of about 27 characters are Meta's recommended lengths. Our copy keeps the opening message inside the first 125 characters and headlines short. The hard limits in the tests are looser than Meta's recommendations on purpose; shorter is better.

Safe zones. Stories and Reels place the profile name, the caption, the reaction buttons and the call-to-action button over the picture. Keep faces of food, logos and all text out of the top and bottom bands. Working hypothesis for a 1080 x 1920 canvas: leave about 250 pixels clear at the top and about 340 pixels clear at the bottom, and keep text inside the central area with a side margin of about 60 pixels. `[VERIFY: Meta's current safe zone guidance. The placement preview in Ads Manager is authoritative: check every ad in the Stories, Reels and Feed previews before staging.]`

With Advantage+ placements on (03b section 4), Meta can run any ad in any of these placements. So every creative must work in 4:5 and 9:16, or be uploaded with a separate crop for each.

## 6. Captions, subtitles and text on screen

- Burn in captions on every video. The video must be understood with the sound off.
- Australian English spelling and plain words. Short lines, about seven words or fewer, one idea per line.
- Contrast: white or very dark text on a solid or darkened band, so it stays legible on a small phone. Do not place text over busy food.
- No price, no promise of speed, no "fresh", "best", "authentic", "halal" or other banned words, in a caption or on screen. The banned list is the one in `tests/unit/marketing-meta-ads.test.ts`. On-screen text counts as ad copy and goes through the same review.
- Upload an accessible caption file where Meta allows it `[VERIFY: caption file support per placement]`, and write alt text for every image.
- End card: store name and typed address only. The call to action lives in the button, not as a second CTA in the picture. One action per ad.

## 7. People, permissions and privacy

- Hands only unless the person has signed a release. Staff photos need a staff release. Customers and their premises need written permission, and no client logos, screens or documents may be visible.
- No children's faces, in any ad. Minis and event ads speak to adults.
- Keep releases on file. `[CONFIRM: who holds them]`.
- Never include a customer's name, order, review or a testimonial unless it is real, current and used with permission (`compliance-au.md` section 3).
- No claims about a named company being a customer.

## 8. File handling

- Name files `db-<angle>-<format>-<variant>.<ext>`, for example `db-office-breakfast-photo-a.jpg`, so the file name matches the `utm_content` slug in `copy.csv`.
- Keep originals and exports in the team's shared drive, not in the repository. Do not commit images or video here. `[CONFIRM: shared drive location]`.
- Record who shot it, where and when, in a one-line caption note kept with the file.

## 9. One-page review checklist (tick before any ad is staged)

Creative

- [ ] Every food image is a real photograph or video shot by the team, in a store or at a real handover.
- [ ] No AI render, stock photo or illustrative hero image appears as the product.
- [ ] What is shown is what the customer receives. No unsupplied props.
- [ ] No Minis imagery of any kind unless real Minis were shot.
- [ ] No children's faces. No client logos, screens or documents visible.
- [ ] Hands only, or a signed release is on file.

Copy and on-screen text

- [ ] No superlative or comparison (best, number one, cheapest, leading, famous, award-winning).
- [ ] No price, no "from" figure, no discount, no free offer.
- [ ] No delivery area, radius, lead time, capacity or minimum order.
- [ ] No rating, review count or testimonial.
- [ ] No halal, organic, "fresh daily" or "authentic" claim.
- [ ] No health, nutrition or allergen-free claim. Allergen wording asks for information and promises none.
- [ ] No urgency, scarcity or exclamation marks. No emoji.
- [ ] Opening line carries the message inside the first 125 characters.
- [ ] Headline and description are inside the limits in `copy.csv` tests.
- [ ] Any fact stated (store name, address, "Lebanese bakery") matches the typed store data or a confirmed ledger entry. `[CONFIRM: the ledger entries that back "Lebanese bakery" and "three stores in the south-west"]`.

Format

- [ ] 4:5 and 9:16 versions both previewed in Ads Manager and nothing is cut off or covered.
- [ ] Captions burned in and readable with the sound off.
- [ ] Alt text written. Caption file uploaded where possible.
- [ ] One call to action only.

Links and tracking

- [ ] Final URL is on `doughboss.com.au` and on the route contract.
- [ ] UTM parameters are lower-case and hyphenated, with a unique `utm_content`.
- [ ] The landing page matches the ad: same offer, same words, the enquiry form visible without hunting.

Policy and sign-off

- [ ] Advantage+ creative enhancements are off, so no text or image is rewritten without review.
- [ ] No religious or ethnic reference, no personal-attribute language ("for Muslim families", "for Lebanese customers").
- [ ] Reviewed against `docs/marketing/research/compliance-au.md` section 11 and Meta's current ad standards `[VERIFY: re-read Meta's ad standards before each launch]`.
- [ ] Status is PAUSED. Named reviewer and date recorded.
