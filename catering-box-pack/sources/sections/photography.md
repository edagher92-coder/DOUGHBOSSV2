# Photography and website masters

One art-directed set, one light and one grade. The same masters serve the website (home hero, catering page, item tiles), social and print. Every master is a **concept**: the box plates are generated blank and the exact vector artwork is composited as print, then graded with one LUT. The food is an AI stand-in for the real bakes (see the gates in Section 1).

## The set

| # | Master | Size (px) | Use | Notes |
|---|---|---|---|---|
| W11 | Catering hero, 16:9 overhead | 3840 x 2160 | The only home hero (desktop) | Headline column x 6 to 32 % of the width; white-text contrast median 19:1 in the text block |
| W1 | Closed box, 4:5 | 2560 x 3200 | Catering page, "how it arrives" strip, OG | Seal across the fold |
| W3 | Open box, 4:5 | 2560 x 3200 | "The reveal" tile, catering alt | Liner, 12 bakes, inside lid |
| W2 | Stack of three, 3:2 | 2048 x 1360 | Order sizes strip | BOX 1/2/3 OF 3 seals |
| W4 | Seal macro, 4:5 | 1792 x 2240 | "Made for you" detail, social | Do not enlarge beyond 100 % |
| W5 | Overhead stack, 3:2 | 2048 x 1360 | Internal reference for the hero video band only | Never published full frame |
| W7 to W10 | Single bake tiles, 1:1 | 1640 to 2048 square | Itemised catering tiles: cheese, za'atar, meat, spinach | Paper matched warm, the bake brightest |

## Grade

One soft key from the upper left; deep shadows to the lower right; blacks aligned (5th percentile about 7); steel desaturated and neutral; warm highlights; food saturation capped; mono film grain applied last. The ember red is the single saturated accent.

## Web delivery (UX specification)

- Formats: AVIF (10-bit, quality 55 or higher for W11), WebP, JPEG fallback; sRGB.
- Widths: 2560 / 1920 / 1280 / 828 / 640 px, chosen by srcset.
- Measured WebP q75 weights: W11 115 KB at 1920 wide, 30 KB at 828; W1 80 KB; W3 123 KB; W2 135 KB; W4 143 KB; tiles 28 to 41 KB at 640 (all under the per-tile cap of 45 KB).
- Hero layout: text above the image below an aspect of about 1.5; object-position 70 % 35 %; the headline and button never mention boxes or catering.
- Still to check at encode time: AVIF banding in the dark left third of W11 on a phone and a desktop panel; fibre and crumb detail in W3 and W4 after AVIF.

## Before the real shoot

- Shoot the real box and the real bakes on the same set (brushed steel, dark timber, one key from the left) so the real photos drop into the same slots.
- Keep the W4 label clear of the box corner; keep a flat bake profile; stagger the rounds slightly in the open box.
- The spinach triangles are the darkest items; give them about 0.15 stop more exposure.
