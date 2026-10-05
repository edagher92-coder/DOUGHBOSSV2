# DoughBoss catering box pack (October 2026)

This folder holds the design and production pack for the DoughBoss dozen catering box and its merch, plus the source material and scripts behind the home page hero video.

The plugin code is in `../doughboss-growth-box/`. Its Codex handoff is `../doughboss-growth-box/docs/HANDOFF-CODEX.md`. Committed 2026-10-04 on Elie's instruction, with the repository still public.

## Status: concept, not approved for production

- **The box is not ordered or confirmed.** Every price, lead time, supplier, board grade and quantity that is not verified is marked `[CONFIRM]` or `[QUOTE]`. Do not remove a marker without written evidence.
- **The food is AI-generated concept imagery.** Before any public marketing use, real bakes must replace it. The concept labels stay on until both conditions are met:
  - the real box is confirmed in writing;
  - real bakes replace the AI food.
- **Factory files.** The tech packs are marked "CONFIDENTIAL · SUPPLIED UNDER NDA". They are for suppliers under NDA, not for publication.
- **"Minis".** Some internal notes use the word "Minis". It must not appear in any public copy.
- **Panel scores.** Final scores were 8.5 or above from every specialist. The reviews are in `sources/reviews/` and `hero-video/reviews/`.

## What's where

| Path | Contents |
|---|---|
| `deliverables/DoughBoss-Brand-and-Catering-Pack-v1.pdf` | Internal brand and catering pack. 95 pages. Section 10, "Before the factory", lists the open gaps. |
| `deliverables/DoughBoss-CateringBox-TechPack-revA.pdf` | Factory tech pack DB-TP-CAT-001 rev A. 27 pages, with dieline v2 appended. |
| `deliverables/DoughBoss-DozenBox-PDS-revA.pdf` | Packaging data sheet, rev A. 5 pages. |
| `deliverables/DoughBoss-Merch-TechPacks-revA.pdf` | Merch tech packs. 25 pages. |
| `deliverables/dieline-v2/` | Dieline v2 at 1:1. Includes the PDF (4 pages), the DXF die, the prepress check, and proof renders. Trim 593.8 x 781.0 mm, bleed 3 mm. Spot plates are listed in `PREPRESS-CHECK.md`. |
| `sources/sections/*.md` | Markdown source for each pack section. |
| `sources/PACK-BRIEF.md`, `PDS-RESEARCH.md`, `SHOTLIST-WEB.md`, `WEB-PLAN.md` | Brief, research, web shot list, and website plan. |
| `scripts/` | Python builders: `build_pack.py`, `build_pds.py`, `build_techpack.py`, `build_v2.py`/`verify_v2.py` (dieline), `spots.py`, `art*.py`, `merch_art.py`. Also the photo comp and retouch scripts (`comp_*`, `fix_*`, `grade.py`, `foodlift.py`), `cost/model.py`, `web/build_web_ladders.py`, and `fonts/` (open-licence fonts with their licences). |
| `artwork/flats/`, `artwork/concepts/` | Print flats for each panel (PDF and PNG), merch flats, and the three original concepts. |
| `photography/masters/*.png` | Final concept photography masters W1 to W11. `previews/*-v.jpg` are the small previews. |
| `website/media-plugin/` | Unzipped source of the `doughboss-growth-media` plugin (box images and manifest). `build_media_zip.py` builds its zip. |
| `website/HERO-VIDEO-CHECK.md`, `hero-video-evidence/` | Release checks for the hero video, with screenshots and contrast data. `INSTALL-catering-box.md` is the owner install guide. |
| `hero-video/video/web/` | The five hero MP4s (AV1 720/1080, HEVC 720/1080, H.264 720) and posters, each under the 2 MB upload cap. |
| `hero-video/video/hero-play-lid-1080.mp4` | Final production video. It ends with the lid open. |
| `hero-video/video/hero-loop-lid-1080.mp4` | Seamless loop master. |
| `hero-video/video/seedance-raw-take.mp4` | Raw generated take, before post. |
| `hero-video/scripts/` | `build_v6_top.py` (start frame), `post2.py` (registration, band lock, loop, grade), `encode.sh`, `build_interior.py` and `lid_anim.py` (lid opening). |
| `hero-video/prompts/`, `stills/` | Generation prompt, start frame, and clean plate. |
| `e2e-scratch/` | The throwaway browser checks used for the hero video and box pages. Kept for reference only (see Rebuilding). |

## Rebuilding

The scripts are committed as a record of how each output was made. They were written against a working folder with these subfolders:

- `final/` = `photography/masters`
- `flats/` = `artwork/flats`
- `sections/` = `sources/sections`
- `fonts/` = `scripts/fonts`
- `out/`
- `pdfimg/`, made from the masters

Some comp and e2e scripts still use absolute scratch paths, so recreate that layout or edit the path constant at the top of each script.

Dependencies: Python 3 with reportlab, pikepdf, numpy, opencv-python and Pillow. The CJK glossary also needs the WenQuanYi Zen Hei font (`fonts-wqy-zenhei`).

The intermediate frames and retouch rounds (several GB) were not committed. Third-party reference documents (standards, supplier pages, regulator PDFs) are not committed either. They are cited by name in `PDS-RESEARCH.md` and the pack.
