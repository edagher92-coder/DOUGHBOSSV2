# Promotions announcement bar — Astra/Sol plan, 16 October 2026

Status: planned only; not implemented, published or scored as accepted.

Owner requested animated sliding text in the top bar for current deals, promotions, membership/rewards signup, and any new or important announcements. Astra and Sol recommend two independent areas: the current truthful ordering-status/call notice stays visible whenever ordering is paused, with a separate promotions/announcements area alongside it on desktop or on a stable second row on phones. Never rotate the paused notice out of view or imply checkout is available. Important operational announcements must remain readable and take priority over promotional messages; approved shop applicability and expiry must accompany time-sensitive content.

## Smallest implementation packet

- Existing source: `themes/doughboss-final/header.php:18` renders the paused notice using the existing `data-db-when-ordering` hook. `functions.php:123` supplies ordering state and the configured phone. Keep those service boundaries and live state hooks unchanged.
- Expected runtime files: `header.php`, `style.css`, `assets/theme.js` in the existing theme. Extend `tests/frontend-upgrade.test.js` and `tests/theme-navigation-layering.test.js`. No new dependencies, database schema, admin system or provider integration is needed for an initial approved content batch.
- Show one readable promotion at a time with restrained horizontal sliding, not a continuously racing marquee. Animate only with two or more approved messages; otherwise render a static message.
- Provide Pause/Play announcements. Pause on hover, keyboard focus, hidden/offscreen state; retain explicit pause. Reduced-motion and Save-Data are static. Links remain stable and focusable; do not repeatedly announce slide changes in a live region.
- Stable mobile row height, no horizontal overflow, useful 44px touch targets and existing header/drawer stacking must remain. Verify desktop/phone, keyboard focus, pause persistence, reduced motion, text wrapping and menu layers before a release.

## Verified source destinations and content boundaries

Existing destinations: Browse menu `/order/`, Find your shop `/locations/`, informational Gift cards & loyalty `/vouchers/`, and the existing configured telephone link. These are source-verified; route acceptance must be refreshed when releasing.

The current `page-vouchers.php:18` explicitly states gift-card purchasing/loyalty signup is not available on the website yet. No usable Square signup URL, actual offer configuration or approved deal terms were found in the inspected theme. A first informational slide may say “Gift cards & loyalty — coming soon”; it must not say “Join rewards” or imply an active scheme without proof.

Before publishing promotional/signup slides, obtain approved wording, value/terms, applicable shops, start/end dates and destination; obtain the exact public Square membership/loyalty signup URL and activation/merchant evidence. Do not invent discounts, scarcity, deadlines, reward balances or URLs. Separate shop Square accounts also require an explicit decision about chain-wide versus shop-specific programmes; never share customer/member data across sellers by assumption.

This plan is a separate next batch from the accepted play-once/steam hero. Its implementation needs focused checks, browser acceptance and the requested assurance gate; the hero's 9/10 does not transfer to it.
