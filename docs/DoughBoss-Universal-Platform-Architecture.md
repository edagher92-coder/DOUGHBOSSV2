# DoughBoss Universal Platform Architecture

Status: implementation foundation added; backend universalisation is phased and not yet complete.

## Decision

DoughBoss must be one configurable ordering platform, not a Revesby-specific codebase and not a collection of store-specific forks. The launch profile remains `doughboss-revesby-launch`: one active location, direct pickup only, online payments disabled, AUD, `en-AU`, and `Australia/Sydney`. Future capabilities are enabled by validated configuration and adapters.

The words **universal** and **production-ready** are not interchangeable. A configuration contract makes the demo reusable; server-enforced workflow, provider adapters, migration proof, and translated interfaces are still required before the production platform is universal.

## Versioned profile contract

Schema version 1 owns:

- brand identity, theme, contact details, and order-reference prefix;
- language, fallback language, locale, currency, timezone, and text direction;
- locations and their independent pickup/delivery capabilities;
- fulfilment-specific lanes, statuses, actions, and terminal states;
- allowed and selected payment providers, including disabled/pay-on-pickup mode;
- promotion values;
- demo safety guarantees such as no external writes and no real payment.

The browser implementation lives in `demo/config`, `demo/demo-runtime.js`, and `demo/demo-fixtures.js`. Customer, staff, and owner surfaces consume the same profile and fixtures.

## Profiles

### DoughBoss Revesby launch

- Profile: `doughboss-revesby-launch`
- Location: Revesby
- Direct fulfilment: pickup
- Payments: disabled; Stripe and Tyro are allowed future adapters
- Currency/locale: AUD / `en-AU`
- Timezone: `Australia/Sydney`
- External demo writes: disabled

### Universal reference

CI also validates a synthetic profile with two locations, mixed pickup/delivery, EUR, a different timezone, and Tyro selected. It is not deployed and contains no real credentials. Its purpose is to prove the schema can describe a different operation without modifying platform code.

## Backend gaps and implementation order

### Phase U1 — profile foundation

Implemented in the repository demo. Add the same profile identity and compatibility mapping to WordPress settings without changing stored launch values.

### Phase U2 — server-owned workflow

Create one order-transition service. It must validate current state, target state, fulfilment method, actor capability, and expected order version. Use compare-and-set updates so two staff devices cannot apply conflicting transitions. REST responses should return allowed actions; clients must not invent the graph.

### Phase U3 — payment gateway boundary

Define a provider-neutral contract for intent/session creation, authoritative verification, webhook authentication, refund, reconciliation, health, and idempotency. Wrap existing Stripe behavior first without changing production. Add Tyro only after sandbox credentials and reconciliation tests. Keep `none`/pay-on-pickup as the default.

### Phase U4 — location integrations

Replace numbered POSPal Store 1/2/3 settings with integration-account rows keyed by `location_id`. Remove named store placeholders and the three-store limit. Every new integration remains disabled until verified.

### Phase U5 — money and business time

Validate ISO 4217 currency codes, use currency exponent metadata, avoid assuming two decimals, and format through locale-aware APIs. Store event instants in UTC and calculate business dates with the configured/site timezone. Add per-location timezones only when operations cross timezones.

### Phase U6 — translation

Keep machine keys stable and translate display labels. Move PHP and JavaScript copy into catalogues; generate a POT file; add reviewed language packs and RTL layout tests. Legal documents are jurisdiction/profile packs and must be professionally reviewed rather than machine-generalised.

## Test matrix

1. Revesby launch profile and demo safety.
2. Two-location mixed-fulfilment reference profile.
3. Single location with delivery enabled and single-location routing disabled.
4. Missing, inactive, and incompatible location rejection.
5. Payments disabled/pay-on-pickup.
6. Stripe fake adapter contract.
7. Tyro fake adapter contract after the gateway boundary exists.
8. `en-AU` plus a reviewed RTL language.
9. Database upgrade from zero, one, and multiple active locations.
10. Idempotent rerun, settings preservation, concurrent migration, and full rollback fingerprints.

Use pairwise profile combinations instead of an unmanageable full Cartesian product.

## Deployment and rollback

- Ship schema and compatibility changes additively.
- Preserve existing Revesby settings and keep new feature flags off.
- Test alternate profiles only on disposable staging with fake providers and fictional data.
- Enable one capability at a time: location, fulfilment, payment, then language.
- Roll back by restoring the prior profile/settings snapshot and previous plugin package; do not rely on destructive down-migrations.

## Explicit exclusions

Privacy, terms, licensing, tax, and franchise claims are not universal runtime strings. They remain versioned, lawyer-reviewed jurisdiction and tenant documents. Historical Snow Boss material should be archived or removed through an explicit content decision rather than silently translated into another profile.
