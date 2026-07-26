# DoughBoss Hybrid (unpublished scaffold)

This is an installable **classic** WordPress theme scaffold. It is deliberately
separate from the active theme and must not be activated, uploaded or used for
payments until a staging preview and acceptance test are complete.

## What it does

- Uses the DoughBoss plugin as the source of truth for the hero, shop picker,
  menu, builder, cart, order tracking and catering experience.
- Forces the plugin's existing storefront assets on templates that render
  shortcodes outside page content.
- Includes only local CSS and system fonts; there are no remote font, tracking
  or payment dependencies.
- Provides mobile layout, keyboard focus styles, a skip link and reduced-motion
  support.

## Required pages

Create pages with these exact slugs before previewing: `order`, `track-order`,
and `catering`. WordPress will select the matching `page-*.php` template.

## Preview checklist

1. Install the DoughBoss plugin and this theme in a staging WordPress site.
2. Confirm the home page, `/order/`, `/track-order/` and `/catering/` render.
3. Confirm the plugin stylesheet/script loads once per page and the live
   ordering configuration still controls availability.
4. Perform keyboard/mobile checks and a sandbox payment test only through the
   existing DoughBoss checkout adapter.
5. Obtain explicit approval before activating on production.
