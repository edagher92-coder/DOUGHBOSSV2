# Installing the catering box pages (Phase 1)

For Elie. Plain words, in order. About 15 minutes. Nothing public changes until step 7.

You will upload two zips under Plugins, Add New, Upload Plugin:

1. `doughboss-growth-media-0.1.0.zip` (the pictures, 1.3 MB, no code)
2. `doughboss-growth-box-0.1.0.zip` (the code, 28 KB)

Both are under the host's 2 MB upload limit. The pictures here are a concept: the box does not exist yet, and none of them shows food.

## Before you start

1. Take a full backup (files and database). Write down the time. This is your way back.
2. Open the live site once. Note how `/`, `/catering/` and `/menu/` look, so you can compare afterwards.
3. Read the five decisions at the bottom (B1 to B5). None of them stops you installing. They decide what you publish.

## Install

1. WordPress admin, Plugins, Add New, **Upload Plugin**. Choose `doughboss-growth-media-0.1.0.zip`. Click **Install Now**, then **Activate**. It does nothing by itself.
2. Upload `doughboss-growth-box-0.1.0.zip` the same way. Click **Install Now**, then **Activate**.
3. Open **DoughBoss, Catering box** in the left menu. If there is no DoughBoss menu, it is under **Settings, Catering box**. Every switch is off.
4. Open the home page, the catering page and the menu page in a private window. They must look exactly as they did before.
5. On the Catering box screen, look at **Image slots**. You should see `closed_art`, `closed`, `seal_art`, `seal_macro`, `closed_band`, `liner_tile` and `seal_badge`, each showing **ok**. If one says **missing** or **no media plugin**, the media zip is not active.
6. Click **Create draft page**. The page is a draft titled "Feed the whole table.", a child of Catering (address `/catering/box/`). Click **Preview**. Check:
   - every picture except the pattern carries the dark "Concept preview" chip;
   - the artwork, the box, the seal, the three steps and the email link all show;
   - read every line. Nothing should mention a price, a count, a phone number or "Minis".
7. When you are happy: on the Catering box screen tick **Story page** and click **Save changes**, then in Pages set the draft to **Published**. The page tells search engines to stay away (noindex) and is left out of the site's sitemap while it holds concept pictures. It is not linked from anywhere until you link it.
8. Optional. Turn on one switch at a time, saving and reloading the public pages after each:
   - **Site-wide strip**: "Feed the whole table." with the liner pattern, above the footer on every page. Text and pattern only.
   - **Catering band** and **Home band**: a slim band under each hero. It is text only.
   - **Concept image in the bands**: adds the concept picture of the box to the bands. The pages that show it become noindex, **including the home page**. I recommend leaving this off until real photographs exist.
9. If the site uses a page cache or CDN, purge it after each switch.

The four lines under **Copy** marked Draft print nothing until you tick them. Tick them only when you are happy with the wording (decision B2).

## Where things are

- Settings: **DoughBoss, Catering box** (needs an administrator).
- Draft page: Pages, "Feed the whole table.", or the Preview button on the Catering box screen.
- Shortcodes, if you want to place something yourself: `[doughboss_growth_box_story]`, `[doughboss_growth_box_band surface="home"]`, `[doughboss_growth_box_strip]`, `[doughboss_growth_box_badge]`, `[doughboss_growth_box_divider]`. A shortcode shows nothing to visitors until its switch is on. As an administrator you see a preview with a note.

## If the upload is refused

The host may block Upload Plugin. Tell me. The fallback is a WPCode snippet for the styles plus pictures in the Media Library and a normal page. It is slower and less tidy, and it has no manifest to stop a mistake.

## Rollback (strongest last)

1. Untick a switch on the Catering box screen and save. That part disappears at once.
2. Deactivate **DoughBoss Growth Catering Box**. The story page goes back to draft by itself, so no stray text shows.
3. Deactivate or delete **DoughBoss Growth Media**. Every box section shows as text only, with no broken pictures.
4. Emergency stop without logging in: add `define( 'DBGRBOX_DISABLE', true );` to `wp-config.php`.
5. Restore the backup.

Deleting the code plugin removes only its own two settings. It never deletes a page or a picture.

## Later: swapping in real photographs (Phase 2)

Upload a newer `doughboss-growth-media` zip over the old one (choose "Replace current with uploaded"). In its `manifest.json` each real photo is marked `real` and `ai_food: false`. The chip goes, and when every story picture is real the page stops being noindex. No code release is needed. A picture marked as AI food is never shown until it has been replaced by a real photograph.

## Decisions for you (B1 to B5)

Each has my recommended default. None blocks the install.

**B1. Is a labelled concept page allowed to be public?** The teaser rules say public pages should only say something is coming, with no product or category. A box page with a list of bakes could read as a hint.
Recommended: no public link yet. Install, preview and keep the story page unlisted (noindex). Turn on only the text-only strip when you want. Say yes to the concept page, and to the concept image in the bands, only when you accept a clearly labelled concept in public.

**B2. Caption wording, and the sample handwriting.** The concept pictures show handwriting such as "Friday lunch" and "9/10". A date on a concept could read as a booking.
Recommended: approve the two captions ("Artwork. The box is in development." and "Concept preview of the Dough Boss catering box.") and the line "The handwriting on the label is sample text." If you would rather not show the handwriting at all, tell me and I will drop the seal close-up from the media zip.

**B3. Page address and linking.** Recommended: `/catering/box/`, not linked from the home page or menus. The inside-lid artwork is left out because "FRESH FROM THE OVEN." and "Oven-baked, baked to order" need your confirmation for every catering bake.

**B4. The word "Minis" already on the live site.** The catering page title and one step, and four package titles and addresses, use "Mini" or "Minis". The new pages never print the word.
Recommended: rename those in wp-admin before the box goes public. It is a content edit, not code. The old package addresses can stay as they are.

**B5. Reusing the name `doughboss-growth-media`, and using two standalone plugins.** The name was dropped on 2 October with the 3D hero. Recommended: yes. It keeps pictures out of the code plugin, so the swap to real photographs is just a zip upload, and it avoids tying this to the unreleased companion plugin.
