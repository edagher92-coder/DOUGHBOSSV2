# Public teaser direction: "something exciting is coming"

**Decision (Elie, 2026-10-02):** the "coming soon" teaser is generic. It is *not* specifically about Minis, and the word "Minis" must not appear on anything public.

## Rules for every public surface (site, plugin, ads, GBP posts, outreach, social)

1. **Say only that something is coming.** Headline and body are neutral (for example "Something exciting is coming" and "Be first to know"). Both are admin-editable in the plugin; the default stays neutral.
2. **No product claims.** No product name, category, price, size, pack, dietary or halal claim, ingredient, launch date or location. None of these is confirmed, and an unconfirmed claim is a fabrication risk.
3. **No product-specific interest picker.** The waitlist captures name, email, an optional mobile number, store preference and consent. Any "what are you interested in" options are empty by default and only appear if Elie supplies them.
4. **Consent first.** Marketing consent (Spam Act 2003) is a separate, unticked checkbox with the sender identified and an unsubscribe route. The waitlist is a "VIP first look" list, nothing more.
5. **Internal documents may keep a working name** (for example in research notes) but must be labelled internal and never copied into customer-facing copy.
6. **Ads and keywords:** no paid campaign, keyword set or ad copy may be built around an unannounced product. A generic "coming soon / join the list" awareness line is allowed once Elie approves; product-specific campaigns stay parked until the product is confirmed.

## What this changes in the drafts already written

- Remove or neutralise Minis-specific lines in: Google Ads (RSA, campaigns, keywords, extensions, negatives), Meta Ads (campaigns, copy, audiences, creative brief), Google Business Profile post ideas, outreach segments/sequences/one-pager, link-target and PR angles, WordPress SEO checklist, and the on-site SEO page list.
- The Next.js lab app (`web/src`) keeps its pack-sizer library as dormant code, but its public teaser strings, section anchor, analytics event names and tests become generic.
- The companion plugin's teaser, waitlist and tracking events use neutral names (`coming_soon_view`, `waitlist_submit`), not product names.
