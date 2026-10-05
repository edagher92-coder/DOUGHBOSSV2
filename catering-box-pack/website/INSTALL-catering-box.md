# Installing the catering box pages and the home hero video (version 0.2.0)

For Elie. Plain words, in order. Nothing public changes until you tick a switch.

You will use two zips (Plugins, Add New, Upload Plugin) and, for the video, five MP4 files (Media, Add New):

1. `doughboss-growth-media-0.2.0.zip` (the pictures, about 1.8 MB, no code)
2. `doughboss-growth-box-0.2.0.zip` (the code, about 43 KB)
3. Five video files, each under the host's 2 MB upload limit:
   `dbgr-hero-loop-720-av1.mp4`, `dbgr-hero-loop-1080-av1.mp4`, `dbgr-hero-loop-720-hevc.mp4`, `dbgr-hero-loop-1080-hevc.mp4`, `dbgr-hero-loop-720-h264.mp4`

The box pictures are a concept: the box does not exist yet, and none of them shows food. The hero video and its first-frame picture DO show food, and that food is AI-generated, so it is also a concept. See "Why the label is on" below.

## Part A. Add the video to the home page hero (you asked for this on 3 October 2026)

This replaces the 2 October "keep the photo hero" decision for the home hero video only. The catering hero and every other page stay as they are.

1. **Media, Add New.** Drag the five MP4 files in. Do not rename them: the plugin finds them by file name. (If WordPress adds `-1` to a name because you uploaded one twice, that is fine, the newest one is used.)
2. **Plugins, Add New, Upload Plugin.** Choose `doughboss-growth-media-0.2.0.zip`. If WordPress says the plugin already exists, click **Replace current with uploaded**. This adds the first-frame picture of the video.
3. Upload `doughboss-growth-box-0.2.0.zip` the same way (**Replace current with uploaded** if you already have 0.1.0), then **Activate** if it asks. Everything stays off.
4. Open **DoughBoss, Catering box**. Scroll to **Home hero video** at the bottom: the table should show all five videos and both pictures as found. Then tick **Home hero video** (leave **Show "Concept preview" label on the hero video** ticked), click **Save changes**, and purge any cache plugin or CDN.
5. Check it on a phone and on a computer, in a private window:
   - the home page hero shows the picture straight away, with a small "Concept preview" label in the top right corner;
   - a second or two after the page has finished loading, the picture comes to life as a silent 10-second loop (the box lid opens near the end);
   - the "Pause photo motion" button stops the video, and pressing it again starts it;
   - on a phone the picture fills the hero; on a computer it is a tall panel on the right half, with the words on the left.

If nothing plays, that is often by design: the video stays as a still picture when the visitor has asked their device to reduce motion, has Data Saver on, is on a slow connection, or the browser cannot play any of the files. To see what is missing, open **DoughBoss, Catering box** and read the **Home hero video** section: it says Running, or says exactly what it is waiting for.

### Why the label is on (and what is not done)

- The picture and the video show AI-generated food. They are a concept, not a photograph of our bakes. The "Concept preview" label is how the page tells visitors that, so it is ticked by default. Untick it only when the hero shows real photographs or real footage.
- The home hero video does **not** make the home page noindex. That is deliberate: you decided the home page must stay visible to Google, so the visible label carries the honesty. (The plugin records the same facts in the media plugin's `assets/hero/hero.json`: concept, shows AI food, your decision of 3 October 2026.)
- The existing box rule still applies elsewhere: if you also tick **Concept image in the bands**, the home page becomes noindex because of that switch, not because of the video. I recommend leaving that switch off.
- If WordPress core or the DoughBoss plugin is updated and draws the home hero differently, the video switches itself off and the normal photo hero stays. The Home hero video section says so. Tell me and I will adjust.

### Rolling back the video (strongest last)

1. Untick **Home hero video** on the Catering box screen, save, purge any cache. The home page is back to the photo hero at once, byte for byte, and nothing about the video loads.
2. To keep the video but drop only the label: untick **Show "Concept preview" label**. I do not recommend it while the food is AI-generated.
3. Deactivate **DoughBoss Growth Catering Box**. All of its parts stop, including the video.
4. Emergency stop without logging in: add `define( 'DBGRBOX_DISABLE', true );` to `wp-config.php`. This overrides every switch, the video included.
5. Go back to the old version: Plugins, Add New, Upload Plugin, choose the 0.1.0 zip, **Replace current with uploaded** (the old code ignores the video switch). The five MP4 files in Media are harmless and can stay or be deleted.
6. Restore the backup.

## Part B. The catering box pages (first installed in 0.1.0)

About 15 minutes. Nothing public changes until step 7.

### Before you start

1. Take a full backup (files and database). Write down the time. This is your way back.
2. Open the live site once. Note how `/`, `/catering/` and `/menu/` look, so you can compare afterwards.
3. Read the five decisions at the bottom (B1 to B5). None of them stops you installing. They decide what you publish.

### Install

1. WordPress admin, Plugins, Add New, **Upload Plugin**. Choose `doughboss-growth-media-0.2.0.zip`. Click **Install Now**, then **Activate**. It does nothing by itself.
2. Upload `doughboss-growth-box-0.2.0.zip` the same way. Click **Install Now**, then **Activate**.
3. Open **DoughBoss, Catering box** in the left menu. If there is no DoughBoss menu, it is under **Settings, Catering box**. Every feature switch is off (only the "Concept preview" label for the hero video starts ticked, and it does nothing until the video is on).
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

### Where things are

- Settings: **DoughBoss, Catering box** (needs an administrator).
- Draft page: Pages, "Feed the whole table.", or the Preview button on the Catering box screen.
- Shortcodes, if you want to place something yourself: `[doughboss_growth_box_story]`, `[doughboss_growth_box_band surface="home"]`, `[doughboss_growth_box_strip]`, `[doughboss_growth_box_badge]`, `[doughboss_growth_box_divider]`. A shortcode shows nothing to visitors until its switch is on. As an administrator you see a preview with a note.
- The hero video needs no shortcode: it works through the existing home hero.

### If the upload is refused

The host may block Upload Plugin. Tell me. The fallback is a WPCode snippet for the styles plus pictures in the Media Library and a normal page. It is slower and less tidy, and it has no manifest to stop a mistake.

### Rolling back the box pages (strongest last)

1. Untick a switch on the Catering box screen and save. That part disappears at once.
2. Deactivate **DoughBoss Growth Catering Box**. The story page goes back to draft by itself, so no stray text shows.
3. Deactivate or delete **DoughBoss Growth Media**. Every box section shows as text only, with no broken pictures, and the hero video (which needs its first-frame picture) stays off.
4. Emergency stop without logging in: add `define( 'DBGRBOX_DISABLE', true );` to `wp-config.php`.
5. Restore the backup.

Deleting the code plugin removes only its own two settings and one cached lookup. It never deletes a page, a picture or a video.

## Later: swapping in real photographs (Phase 2)

Upload a newer `doughboss-growth-media` zip over the old one (choose "Replace current with uploaded"). In its `manifest.json` each real photo is marked `real` and `ai_food: false`. The chip goes, and when every story picture is real the page stops being noindex. No code release is needed. A picture marked as AI food is never shown until it has been replaced by a real photograph. The hero video is the one owner-approved exception, recorded in `assets/hero/hero.json`; when real footage replaces it, update that file, replace the five MP4s and untick the label.

## Decisions for you (B1 to B5)

Each has my recommended default. None blocks the install.

**B1. Is a labelled concept page allowed to be public?** The teaser rules say public pages should only say something is coming, with no product or category. A box page with a list of bakes could read as a hint.
Recommended: no public link yet. Install, preview and keep the story page unlisted (noindex). Turn on only the text-only strip when you want. Say yes to the concept page, and to the concept image in the bands, only when you accept a clearly labelled concept in public. (The home hero video is already your decision, 3 October.)

**B2. Caption wording, and the sample handwriting.** The concept pictures show handwriting such as "Friday lunch" and "9/10". A date on a concept could read as a booking.
Recommended: approve the two captions ("Artwork. The box is in development." and "Concept preview of the Dough Boss catering box.") and the line "The handwriting on the label is sample text." If you would rather not show the handwriting at all, tell me and I will drop the seal close-up from the media zip.

**B3. Page address and linking.** Recommended: `/catering/box/`, not linked from the home page or menus. The inside-lid artwork is left out because "FRESH FROM THE OVEN." and "Oven-baked, baked to order" need your confirmation for every catering bake.

**B4. The word "Minis" already on the live site.** The catering page title and one step, and four package titles and addresses, use "Mini" or "Minis". The new pages never print the word.
Recommended: rename those in wp-admin before the box goes public. It is a content edit, not code. The old package addresses can stay as they are.

**B5. Reusing the name `doughboss-growth-media`, and using two standalone plugins.** The name was dropped on 2 October with the 3D hero. Recommended: yes. It keeps pictures out of the code plugin, so the swap to real photographs is just a zip upload, and it avoids tying this to the unreleased companion plugin.
