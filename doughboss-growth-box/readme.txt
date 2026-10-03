=== DoughBoss Growth Catering Box ===
Contributors: doughboss
Tags: catering, shortcode, landing page
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shortcodes for the Dough Boss catering box story page, a band under the hero, a site-wide strip and a seal badge, plus an optional looping video in the home hero. Every feature switch ships off; installing it changes nothing on the public site.

== Description ==

This is the code half of a two-plugin pair. The images live in the separate DoughBoss Growth Media plugin, so a later swap to real photographs needs no code release.

Shortcodes (each prints nothing for a visitor until its switch is on; an administrator sees a preview):

* `[doughboss_growth_box_story]` the long-form story page.
* `[doughboss_growth_box_band surface="home"]` or `surface="catering"` a slim band. The same band is added under the home and catering heroes when its switch is on.
* `[doughboss_growth_box_strip]` a "Feed the whole table." strip with the liner pattern. The same strip is added above the footer on every public page when the Site-wide strip switch is on.
* `[doughboss_growth_box_badge]` the kraft seal badge with a "Plan catering" label.
* `[doughboss_growth_box_divider]` a band of the liner pattern.

Home hero video (0.2.0, switch "Home hero video", off by default):

* Plays a short, silent, looping video in the home page hero ONLY, using the core hero shortcode's own filters. The poster (first frame, WebP) paints first and is preloaded; the video starts after the window load event and only on a capable connection. Reduced motion, Save-Data and slow connections get the poster only.
* The five MP4 files go in the Media Library (Media, Add New): the host caps an upload at 2 MB, so they are not in the media zip. They are found again by file name, including the -1 and -2 that WordPress adds to a repeat upload. A transient caches the lookup and is cleared when settings are saved and when an attachment is added.
* Core's "Pause photo motion" button also pauses the video (WCAG 2.2.2). The video also pauses when it scrolls out of view or the tab is hidden.
* The video and its first frame show AI-generated food. That is a concept, and it was the owner's own decision on 3 October 2026 ("Add the video to the website hero"), which supersedes the earlier "keep the photo hero" ruling for the home hero video only. A "Concept preview" label is shown on the hero (setting "Show Concept preview label on the hero video", on by default). The home page is deliberately NOT noindexed by the hero video: the visible label carries the honesty.
* If the core hero markup is not what the plugin expects (a newer core draws the backdrop as an image), it leaves the hero exactly as core made it and says so on the settings screen.

Honesty rules, enforced in code:

* The media plugin ships a manifest that declares each image as `concept` or `real` and says whether it shows AI-generated food.
* A concept image always carries a visible "Concept preview" chip.
* An image flagged as AI food is never printed unless it has been replaced and marked `real`.
* A page that prints a concept image is sent with noindex and kept out of the core XML sitemap. The one owner-approved exception is the home hero video above, which does not add noindex.
* The only words printed are the lines in content/copy.json. Draft lines print nothing until an administrator approves them.

Settings are under DoughBoss, Catering box (or Settings, Catering box when the DoughBoss menu is absent). Capability: manage_options.

Safety switches:

* Turn a switch off on the settings screen and that part disappears at once.
* Define `DBGRBOX_DISABLE` as true in wp-config.php to stop the plugin without deactivating it.
* Deactivating the plugin moves the draft story page back to draft so no raw shortcode text is ever shown.
* Deleting the plugin removes only its two options. It never removes a page or an image.

The plugin makes no external request and stores no personal data. Styles load only on a page that holds a shortcode or an automatic band; the site-wide strip uses a small inline style instead of a stylesheet. The hero video stylesheet and script load only on the home page while the hero video is running.

Developers: the media location can be changed with the filters `doughboss_growth_box_media_dir` and `doughboss_growth_box_media_url`, and the hero folder with `doughboss_growth_box_hero_dir` and `doughboss_growth_box_hero_url`.

== Installation ==

1. Upload and activate the DoughBoss Growth Media plugin first (0.2.0 or later for the hero video).
2. Upload this zip under Plugins, Add New, Upload Plugin, then activate.
3. Open DoughBoss, Catering box. Nothing is enabled until you enable it there.

== Changelog ==

= 0.2.0 =
* Home hero video: an optional silent looping video in the home hero (switch off by default), with the first-frame poster as the LCP image and preload, found-by-file-name lookup of five MP4 files in the Media Library (cached in a transient), a "Concept preview" label setting (on by default), a settings-screen status table, a deferred ES5 script and a small stylesheet that load only while it runs. Fails closed if the core hero markup is not recognised. The home page is not noindexed by it (deliberate owner decision).

= 0.1.0 =
* First release. Story page, band, site-wide strip, seal badge and divider shortcodes; manifest-driven image renderer with a concept chip, an AI-food refusal and noindex for concept pages; settings screen with a switch per feature (all off), a draft story page button, an image slot status table and copy approvals.
