# DoughBoss design brief: The DoughBoss counter

## Authority and evidence

Requested by the owner on 8 September 2026. Prepared by the existing `gpt-6-astra` / `xhigh` read-only design lane at source `e1c7f57`; checked against source by the lead. This completes the bounded design assignment, not rendered UI acceptance or publication. No application model-selector switch is claimed. Return ownership is the Sol orchestration workflow with bounded implementation and independent review.

Existing WordPress PHP/vanilla JavaScript/CSS remains the stack. No new dependency, font, schema, provider, live setting, saved navigation or commercial rule is authorised by this brief. Preserve the original/quarantined artifacts.

Historical screenshots under `C:\Codex\Temp\doughboss-review-20260908` informed the visual diagnosis. `candidate-order-ui-390.png` shows excessive pre-menu height and nested rounded containers; `live-home-mobile.png` establishes the real-food-led brand. They predate current interaction work and are not current-browser proof. The lead checked the source's tall order masthead, menu-card fading/transforms, missing toolbar solid fallback, navigation focus handling and gold focus colour.

## Recommended direction

Keep condensed bakery-sign typography, genuine food photography, ink-and-paper ordering surfaces and restrained ember accents. Each surface has one job:

- Dark navigation: location, destination and orientation.
- Opaque content/working panels: products, selections, forms and money.
- Elevated dialogs: one focused decision while the background is inactive.

Preserve the immersive homepage. Make `/order/` compact and practical, exposing products sooner without concealing shop, availability or payment-state information. Do not turn the public storefront into a dashboard or create a second checkout drawer.

## Colour, type and layers

| Role | Colour | Use |
| --- | --- | --- |
| Ink | `#0D0D0D` | Body text, transactional actions, selected controls |
| Coal | `#151210` | Navigation and optional decorative fallback |
| Paper | `#F7F5F0` | Page canvas |
| Surface | `#FFFFFF` | Forms, cards, dialogs |
| Ember ink | `#C92017` | Small brand emphasis on light surfaces |
| Quiet text | `#5F5A54` | Secondary text on light surfaces |

Reuse existing tokens where they express these roles; do not globally recolour the plugin's monochrome controls to match theme decoration. Reuse self-hosted Bebas Neue 400 for short headings and Barlow 400/500/600/700 for body, fields, labels and prices. Inputs normally remain 16px. Use tabular numerals for money, not condensed display type for long instructions.

Lead-recomputed solid-pair contrast: Ink/Paper 17.84:1; Quiet/Paper 6.26:1; White/Coal 18.65:1; Ember ink/Paper 5.21:1. Existing gold/white is only 2.12:1. Replace the single gold focus outline with a context-appropriate contrasting or two-colour ring. These calculations do not certify translucent composites or whole-page accessibility. Normal text targets at least 4.5:1; large text at least 3:1, following [W3C contrast guidance](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html).

Only navigation may use restrained glass: opaque Coal first, then the existing pseudo-element enhanced with approximately 96% opacity and 10-12px blur. Use solid output when unsupported or increased contrast is requested. Never put filter, backdrop-filter, transform or containment on `.dbf-header` itself: that previously broke fixed mobile-drawer positioning. Keep toolbar, cart, price fields and dialogs opaque; replace the existing translucent path instead of piling on another override.

Preserve existing stacking bands: normal content; sticky tools 25; cart cue 40; navigation around 100; modal backdrop/sheet 1000/1001. Check paused banner/nav overlap and toast/customizer-footer overlap. Do not blindly renumber WordPress or provider overlays. Do not open competing modal surfaces; restore pre-existing inert states on close.

## Layout and interactions

```text
Public order page
[DOUGH BOSS]                      [Navigation]
[Selected pickup shop] [configured / unconfirmed status]
Menu
[compact shop details and ordering notice]
[Search the menu________________________]
[Manoush] [Pizza] [Pies] ... horizontal category rail
[Real photo | product name | saved dietary flags]
[Price]                              [Customise / Add]
...
[View cart: item count]                   [server total]
```

| Width | Target |
| --- | --- |
| 320px | One product column; 12-14px gutters; no nested wide padding or page overflow; status wraps |
| 390px | One column; compact entrance brings discovery and products earlier than the historical view |
| 768px | Two columns when readable; test customizer breakpoint separately from navigation |
| 1440px | Bounded content width and natural 3-4 product columns; reuse checkout structure |

Order masthead target: approximately 100-140px phone / 140-180px desktop before content expansion, not fixed maximum heights. Preserve expansion at 200% zoom and with long status text. Test both 767/768 and 900/901px: current components intentionally use different breakpoints. Controls target at least 44px, primary actions preferably 48-52px; preserve safe-area spacing and independently scrollable sheet bodies.

| Component | Required states |
| --- | --- |
| Navigation | Current destination, hover/focus parity, Escape, focus trap/restoration and background isolation; preserve saved WP menus |
| Shop | Loading, configured hours, expired/unconfirmed, unavailable and global pause; no fabricated live dot |
| Product/options | Saved dietary claims only; canonical option values; selected, sold-out and disabled text |
| Customizer | Opaque labelled dialog; inactive background; stable header/footer and keyboard close |
| Cart | Hidden empty cue; pending is not success; server totals; unobscured primary action |
| Catering | Visible shop/package; pending/unavailable/custom estimate; retained form; enquiry is not a reservation |

Motion: existing ease-out, 120-160ms hover/focus colour and 180-220ms drawer/panel transitions. Remove repeated scale/rotation/blur and low-opacity states from interactive menu cards; names, prices and targets remain readable during scrolling. Keep optional small nonessential entrance translation at most 8px. Reduced motion removes translate/scale/blur/parallax, delayed reveals and smooth programmatic scrolling; test preference changes during the session.

## Implementation sequence and proof

### Stage 0: catering enquiry integrity

Implement the three accepted findings in [Interaction handoff](INTERACTION-POLISH-20260908.md): selected-shop binding, invalid positive package rejection, complete staff notification. Valid server arithmetic and explicit custom package 0 remain unchanged. One backend owner holds the catering service, REST methods and real-WordPress tests; one client owner holds catering JavaScript and existing Node tests. No pricing-policy decision is invented.

### Stage 1: surfaces, focus and motion

- Theme lease: `themes/doughboss-final/style.css`, `assets/theme.js`, and `header.php` only if semantics require it.
- Separate plugin lease: `public/css/doughboss.css`, `public/css/doughboss-order-page.css`, `public/js/doughboss-order-page.js`.
- Lead owns cross-surface layering acceptance; keep existing customizer JavaScript untouched unless a reproduced defect warrants a separate scope.
- Remove replaced animation paths; no parallel implementation or modal framework.

### Stage 2: compact order entrance

Theme owner serially updates `page-order.php` and existing theme styles. Keep homepage imagery, configured shop details and status. Replace blanket coming-soon claims with honest paused/browse wording. Reduce redundant container framing and order-only padding. Catering CSS changes require a demonstrated field/layout problem.

Run focused existing tests during each coherent change and the full release gate once after the completed batch. Browser acceptance requires a permitted preview: four widths, zoom, long labels, native/segmented controls, keyboard, dialog isolation/restoration, reduced-motion changes, solid fallbacks, sticky offsets and safe-area clearance. Prior blocked fixture-server/file-protocol paths must not be retried or bypassed. Source tests alone cannot pass this gate.

Stop for ownership collisions, payment/checkout contract changes, new infrastructure, missing required browser evidence at acceptance, or edits to saved menus and unconfirmed branch facts. Hosted CI/deployment remain a separately controlled consolidated release batch.

## Optional Higgsfield and Obsidian

No generated asset is needed for the recommended implementation. Connector availability was discovered; no generation/upload was made. Optional later still-image prompt:

> Abstract bakery-material study: warm dark oven steel, a sparse trace of pale semolina and restrained ember light at the far edge. Wide composition, quiet central negative space. No food depiction, lettering, logo, people, interface or information. Subtle texture, not a luminous glass dashboard.

Use only below-fold, nonessential homepage/interstitial decoration, never behind controls. Proposed incremental budget is at most 80KB mobile or 160KB desktop, static/lazy-loaded with no animation JavaScript. Solid Coal is the complete fallback. Generation is not a dependency or permission to copy competitor assets.

This ordinary Markdown brief and a linked acceptance checklist can be used in Obsidian. No vault, community plugin, customer-data copy or sync configuration is created.

## SamOS contract, not an implementation claim

```text
[Granted workspace / installation / location]
[Navigation] | [Observed source and freshness]
             | [Readable operational content]
             | [Unavailable/stale when evidence is missing]
             | [Review dialog for permitted consequential actions]
```

Reuse the actual SamOS schemas/grants after inspecting its repository in the separately scoped integration work. Do not invent KPIs, order counts, connected status or a second WordPress business schema.

## Benchmarks and critique

On 8 September 2026 the lead inspected public content from [Ooshman](https://ooshman.au/) and [Black Star Pastry](https://blackstarpastry.com/). The useful principles are obvious ordering/location/catering entry, branch-specific information and identifiable product collections. This was not a ranked usability study, visual-performance certification or permission to copy assets.

Astra rejected its initial broader glass treatment: translucent tools/cart and repeated card motion weakened the hierarchy. The retained signature is genuine food and bold bakery type above a quiet, practical ordering surface.
