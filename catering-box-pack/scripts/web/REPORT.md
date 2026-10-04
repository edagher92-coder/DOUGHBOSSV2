# Catering web derivatives

Generated 2026-10-03 from `pack/final/*.png` masters (W1-W4, W7-W11; W5 and underscore files skipped). Nothing uploaded or published.

## Encoding

- AVIF q55, speed 4, 8-bit. 10-bit was not possible: Pillow 12.3 native AVIF has no bit-depth option (sources are 8-bit PNG anyway).
- WebP q75 method 6. JPEG progressive q80, optimised, 4:2:0.
- sRGB; sources carry no ICC profile and are treated as sRGB. EXIF/ICC stripped (verified on readback).
- No upscaling: W2 (2048 wide) has no 2560; W4 (1792 wide) has no 2560/1920. Tiles 400-1280 only.
- Caps checked with 1 KB = 1000 bytes (stricter reading).

## Weight table (KB; AVIF / WebP / JPEG)

| Asset | Crop | Width | AVIF | WebP | JPEG |
|---|---|---|---|---|---|
| w1-closed-box | original | 2560 | 294.5 | 322.6 | 756.7 |
| w1-closed-box | original | 1920 | 181.5 | 197.8 | 451.7 |
| w1-closed-box | original | 1280 | 91.8 | 102.1 | 222.0 |
| w1-closed-box | original | 828 | 43.7 | 47.6 | 101.6 |
| w1-closed-box | original | 640 | 27.7 | 30.3 | 63.2 |
| w2-stack-of-three | original | 1920 | 107.5 | 130.9 | 285.5 |
| w2-stack-of-three | original | 1280 | 59.6 | 71.1 | 144.2 |
| w2-stack-of-three | original | 828 | 30.1 | 36.7 | 69.4 |
| w2-stack-of-three | original | 640 | 20.2 | 24.9 | 45.0 |
| w3-open-box | original | 2560 | 397.2 | 448.7 | 986.6 |
| w3-open-box | original | 1920 | 256.3 | 295.3 | 606.5 |
| w3-open-box | original | 1280 | 131.1 | 157.8 | 301.2 |
| w3-open-box | original | 828 | 62.9 | 77.4 | 139.5 |
| w3-open-box | original | 640 | 39.5 | 50.6 | 87.3 |
| w4-seal-macro | original | 1280 | 144.1 | 177.0 | 306.1 |
| w4-seal-macro | original | 828 | 70.6 | 88.0 | 142.7 |
| w4-seal-macro | original | 640 | 45.2 | 55.3 | 88.8 |
| w7-tile-cheese | original | 1280 | 108.8 | 106.6 | 220.1 |
| w7-tile-cheese | original | 828 | 46.5 | 44.8 | 93.7 |
| w7-tile-cheese | original | 640 | 28.3 | 26.8 | 56.0 |
| w7-tile-cheese | original | 400 | 11.2 | 10.7 | 22.5 |
| w8-tile-zaatar | original | 1280 | 122.0 | 139.6 | 255.0 |
| w8-tile-zaatar | original | 828 | 54.7 | 64.2 | 111.8 |
| w8-tile-zaatar | original | 640 | 33.0 | 39.8 | 67.6 |
| w8-tile-zaatar | original | 400 | 13.4 | 16.5 | 27.3 |
| w9-tile-meat | original | 1280 | 111.4 | 125.3 | 237.4 |
| w9-tile-meat | original | 828 | 51.1 | 54.3 | 103.1 |
| w9-tile-meat | original | 640 | 30.6 | 33.3 | 61.9 |
| w9-tile-meat | original | 400 | 11.9 | 13.2 | 24.5 |
| w10-tile-spinach | original | 1280 | 80.6 | 88.1 | 192.7 |
| w10-tile-spinach | original | 828 | 42.2 | 43.3 | 93.8 |
| w10-tile-spinach | original | 640 | 31.0 | 28.5 | 58.8 |
| w10-tile-spinach | original | 400 | 12.7 | 11.8 | 23.9 |
| w11-catering-hero | original | 2560 | 148.0 | 173.2 | 371.5 |
| w11-catering-hero | original | 1920 | 94.7 | 112.0 | 229.0 |
| w11-catering-hero | original | 1280 | 49.5 | 59.0 | 114.0 |
| w11-catering-hero | original | 828 | 24.5 | 29.1 | 53.4 |
| w11-catering-hero | original | 640 | 15.2 | 18.7 | 33.2 |
| w11-catering-hero | 9:16 phone (focus x70% y35%) | 1080 | 107.6 | 130.3 | 265.6 |
| w11-catering-hero | 9:16 phone (focus x70% y35%) | 828 | 70.5 | 87.1 | 169.7 |
| w11-catering-hero | 9:16 phone (focus x70% y35%) | 640 | 46.5 | 58.6 | 108.9 |
| w1-closed-box | 1.91:1 OG 1200x630 | 1200 | 47.2 | 56.8 | 113.0 |
| w3-open-box | 1:1 square 1200x1200 | 1200 | 107.1 | 132.7 | 239.3 |

## Cap flags

| Check | File | Bytes | Cap |
|---|---|---|---|
| 4:5 1280 JPG | w1-closed-box-1280.jpg | 222021 | 150000 |
| 4:5 1280 WEBP | w3-open-box-1280.webp | 157802 | 150000 |
| 4:5 1280 JPG | w3-open-box-1280.jpg | 301153 | 150000 |
| 4:5 1280 WEBP | w4-seal-macro-1280.webp | 177022 | 150000 |
| 4:5 1280 JPG | w4-seal-macro-1280.jpg | 306104 | 150000 |
| tile 640 JPG | w7-tile-cheese-640.jpg | 56008 | 45000 |
| tile 640 JPG | w8-tile-zaatar-640.jpg | 67633 | 45000 |
| tile 640 JPG | w9-tile-meat-640.jpg | 61880 | 45000 |
| tile 640 JPG | w10-tile-spinach-640.jpg | 58847 | 45000 |

## Crops

- W11 9:16: 1215x2160 from the full-height strip, x 2080-3295 (centre x70%). Food and box front are in frame but a 9:16 strip is only 32% of the width, so the outer buns and the lid logo are cut. Widths 1080/828/640.
- W1 OG 1200x630: full width, y 985-2329. The box and seal are fully in frame, but the box fills the frame edge to edge (a 1.91:1 crop of a portrait master cannot give margin); the lid tip touches the top edge.
- W3 1:1 1200x1200: full width, y 12-2572. Headline, all 12 items and the box are in frame; only a sliver of the bottom rim is cropped.

## Files

See `manifest.json`; `contact.jpg` shows every crop.
