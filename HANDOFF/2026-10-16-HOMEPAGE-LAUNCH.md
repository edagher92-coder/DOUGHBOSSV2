# DoughBoss homepage production release — 16 October 2026

## Coordination for Claude's Square integration

Owner assigned Square integration to Claude and approved this website presentation release. This branch is based on published `square-fullstack` commit `52e2e1b0a8d2aa977faff366124cc672d36f1267` and contains only the reviewed presentation, navigation and relevant test changes. It intentionally excludes the unpublished Square pickup/backend pilot from the separate development checkout. Do not replace Claude's newer backend with this branch's base; merge the presentation changes into the current Square branch and resolve only actual overlaps.

Read this file before further work and append a dated entry after any follow-up. Runtime source is copied from accepted development candidate `9945001a8f6e03244026e7eeed97c3bea6e777fc`. Astra accepted the earlier homepage/UX candidate at 9/10 and the additional steam/film delta at 9/10, both scoped to the reviewed UI. Whole-site, financial or Square operational readiness is not established by those scores.

## Published to WordPress

- Existing active WPCode snippet **598** now contains `scripts/hero-commercial/homepage-hero-v6-snippet.php` without its opening PHP tag. Stored source readback matched the approved source after line-ending normalization.
- Installed `doughboss-final` theme remains version **1.8.0**. Compared all 25 installed theme files before release: exactly five differed from the approved candidate: `assets/theme.js`, `footer.php`, `functions.php`, `page-vouchers.php`, `style.css`. All other theme files matched. The reviewed archive was uploaded to private hosting storage, downloaded again, verified, and extracted into the existing themes directory.
- Theme archive SHA-256: `DA2B3F6F691E837C29BC2ECAFF09FA4AB7209777E82626F195CA829A16309B8D`; 25-file source parity passed.
- Existing franchising-pause snippet **601** remains unchanged and independently active. Partners remapping runs at priority 9, before the pause filter at 10, and changes only the in-memory navigation item to a custom wholesale link.
- No core plugin, provider settings, payment or ordering setting was changed by this release.

Homepage: muted looping film, first in-view autoplay, down-scroll advances / up-scroll reverses, subtle bounded mouse tilt, larger text and improved contrast. Pause film shows the open-box poster with a separate gentle steam loop; Pause steam stops that effect. Reduced-motion, Save-Data, visibility and explicit pauses are respected. The connected browser reports reduced-motion by default, so poster/manual Play is expected until that preference permits automatic motion.

Vouchers now use an informational Square Gift Cards / loyalty-coming-soon page. The public theme no longer renders the standalone student-voucher claim path. Existing issued vouchers and backend records were not migrated, deleted or disabled. There is no verified Square gift-card purchase or member-enrolment URL in this release; add only a genuinely configured, tested destination. No loyalty reward values were approved or activated here. Partners uses the existing `/wholesale/` page; franchising remains paused.

## Square decisions to preserve

- Same Square merchant; Revesby, Bankstown and Roselands are separate shop locations. Shared menu/prices, shop-specific availability.
- Preserve the website's displayed item prices; configure GST per item before selling. Missing descriptions/options stay absent unless the owner confirms source information. Do not infer recipes from shared removal options.
- Keep gift-card-plus-card split checkout disabled until exclusive ownership of the Square Orders is confirmed.
- Staff/time cards/leave/pay/reporting are intended for Square. The owner previously directed temporary assignment of all imported staff to Revesby, with later shop assignments. This release changes no staff data or historical hours.
- Two Revesby KDS screens were last confirmed **not installed**. Actual paid-order delivery to both screens is still unproven.
- Preserve approval-first ordering: Kristy approves before payment; kitchen release follows server-verified successful payment. This UI release does not prove that complete Square journey.

## Validation and live readback

- Isolated release branch: Node frontend/storefront/navigation tests **122/122**, PHP 8.2 suite **1991/1991**, PHP 7.4 suite **1921/1921**, all exit 0. This branch excludes the unpublished backend tests; its counts differ from the development checkout.
- Existing combined Partners/franchising smoke: **51/51** on both PHP 8.2 and PHP 7.4.
- PHP 8.2/7.4 hero syntax passed; theme ZIP source/layout parity passed; `git diff --check` passed.
- Anonymous public HTTP readback at `2026-10-16T00:29:02Z`: homepage hero style, tilt script and controller each matched approved source. `/vouchers/`, `/wholesale/`, `/order/`, `/catering/`, `/locations/` returned 200; `/franchising/` returned 404. Vouchers showed Square information without the legacy claim invitation.
- Public config readback: `ordering_open=false`, `payments_enabled=false`. Split-checkout field was not exposed in that response and is not independently attested here. No provider activation was attempted.
- Rendered live browser confirmed muted automatic playback with `loop=true`, eight tilt panels and 15px desktop navigation under standard motion preference. Later observation had played range `[0,10]`, current time `8.624136`, and playback still running, confirming a completed loop. Pause film showed the poster with steam CSS running infinitely; Pause steam stopped it. At 390px: no horizontal overflow, homepage body copy 18px, navigation 28px, zero franchising links. Live screenshots are `live-desktop.jpg` and `live-mobile.jpg` in the local release evidence directory. Full physical-device, screen-reader, performance and Square financial acceptance remain separate.

## Rollback

- Private hosting theme backup: `/home/doughbos/doughboss-deploy-backups/homepage-before-20261016-ux443b86b.zip`.
- Backup SHA-256: `67D25696029539ADA9273B3290C1AA11FB884AE6FFE8285409C82CA6F4ABC705`. Downloaded and extracted locally for full file comparison before installation.
- Exact previous snippet 598 source saved locally as `C:/Codex/Output/doughboss-homepage-release-20261016/live-snippet-598-before.php`. Restore its code to snippet 598 if rolling back the hero. Keep snippet 601 active.
- Deployment theme archive and live evidence are in `C:/Codex/Output/doughboss-homepage-release-20261016`. The staged release archive remains outside `public_html` under `doughboss-deploy-backups`.
- An UpdraftPlus full-backup request was made, but its completion was not established at publication. The exact scoped rollback copies above are the verified backup evidence for this presentation release; do not describe a full WordPress restore/boot rehearsal as passed.

## Nonblocking follow-up

Astra P3: rejected-autoplay status still says "Play animation" while the control says "Play film". Deferred explicitly to the next controller edit; it does not prevent playback or pausing. A deferred-play-promise ordering regression case was also suggested for a future controller change; no browser failure was demonstrated.
