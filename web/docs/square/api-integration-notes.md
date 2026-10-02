# Square API integration notes (Next.js 15 / TypeScript server) - Dough Boss

Owner: Elie Dagher. Prepared 2026-10-02 as the verified engineering evidence base for using Square as the system of record (catalogue and prices, orders and payments, customers, loyalty, labour) so website orders appear on each store's Square POS, Orders app and KDS.

Read-only research. No Square API was called, no account was logged into, no Square connector was used. Nothing here has been run against a live Square account.

## 0. How to read this document

- Every factual claim carries a source tag such as [S53]. The source register in section 12 gives the URL; every source was retrieved on **2026-10-02** (today) unless stated.
- Evidence labels:
  - **OBSERVED** - read at the cited URL, or read in the official `square` npm package source (S7), or computed locally against a published test vector.
  - **INFERRED** - my reasoning from observed facts. Treat as a hypothesis to test.
  - **UNKNOWN** - not found in official docs; the "verify" note says where to check.
  - **AU: yes / no / UNKNOWN** - Australian availability, stated explicitly for each capability.
- Source tiers: official docs and reference (S1 to S84), the official npm package (S6, S7), official Square AU help pages (S85 to S88), and Square Developer Forum threads (F1 to F8). Forum threads are on developer.squareup.com and include replies from Square staff accounts, but they are not documentation; they are marked "forum" wherever used and every forum-based conclusion is in the smoke-test list.
- Versions at retrieval: Square API version **2026-09-16** (current in the API reference and in the SDK) [S7 `BaseClient.js`, S21]; npm package `square` **46.0.0**, published 2026-09-15T21:05:48Z, Node >= 18 [S6, S10 changelog "Node.js: 46.0.0"].
- Anything marked Beta in the docs is called out, because Beta features can change shape.

## 1. Executive summary (decisions this evidence supports)

1. **Square can be the system of record for catalogue, orders and payments in Australia.** Catalog, Orders, Checkout (payment links), Payments, Customers, Loyalty, Invoices, Labor, Team and Inventory APIs are all documented; Loyalty and Invoices explicitly list Australia as supported [S72, S81]; payment links work "in all regions where Square accepts payments" [S43]. Apple Pay and Google Pay are supported in AU; Cash App Pay is not; Afterpay is supported in AU with eligibility caveats [S66, S43, S67].
2. **A website order appears on POS/Orders app/KDS only when it has a fulfilment AND is paid.** "Orders with fulfillments appear on Square products (such as the Square Dashboard and Point of Sale application) only after they're paid for" [S25]; same condition stated in [S24]. Unpaid orders are not visible to sellers' Square products; a Square staff reply says access to unpaid orders "isn't currently available" (forum, 2023) [F3]. So "pay online first" is not a product choice, it is a technical requirement for KDS visibility. See section 4.4.
3. **PICKUP is the only fulfilment type Dough Boss can use.** DELIVERY and IN_STORE fulfilments are restricted (closed) Betas; a DELIVERY order "succeeds with a 200 response" but is **never visible** to the seller without Square partner approval [S25, F1].
4. **Scheduled pickup needs `prep_time_duration`.** A SCHEDULED order only reaches the Orders app Active tab and the KDS at `pickup_at - prep_time_duration`; with no prep time it goes active immediately like an ASAP order [S25].
5. **Payment links created by API are single-use, not visible in the Square Dashboard, and have no documented expiry field.** Abandoned links must be cleaned up by our own timer using DeletePaymentLink, which cancels the unpaid order [S44, S45, S48].
6. **There is no payment-link webhook.** Completion is detected from `payment.updated` (status COMPLETED, carries `order_id`) plus `order.updated` / `order.fulfillment.updated`, then confirmed with RetrieveOrder [S54, S56, S58, S59]. The Checkout API's only webhooks are settings changes [S54].
7. **Webhook signature is HMAC-SHA256, base64, over `notificationUrl + rawBody`, header `x-square-hmacsha256-signature`.** I verified this locally against Square's own published test vector (section 8.1). The SDK helper compares with `===` (not constant time), so write our own compare [S7].
8. **Square's own API version moves monthly and the Node SDK silently defaults to the newest.** `square@46.0.0` sends `Square-Version: 2026-09-16` on every request; pin the SDK version exactly and set the version explicitly [S7, S9].
9. **Money is `bigint` in the SDK.** `JSON.stringify` on SDK responses throws; convert at the boundary [S7].
10. **The SDK's POST `search` methods are not paginated iterators.** Only `list` methods return the iterable `Page`; `catalog.search`, `orders.search`, `labor.searchTimecards` and `teamMembers.search` return raw responses with a `cursor` you must loop on yourself [S7].
11. **Customer marketing consent is not stored by Square in a usable way.** The only consent-like field is `preferences.email_unsubscribed`, read-only from the API; there is no SMS or opt-in field. Keep consent in our own database [S71, S7 `CustomerPreferences`].
12. **Sandbox cannot prove KDS/Orders-app visibility.** Sandbox does not support Square for Restaurants, Square Point of Sale or the Invoices app, Afterpay/Apple Pay/Google Pay, or checkout settings; payment-link redirect parameters are not appended in sandbox [S69, S43, S46, F7, F8]. The make-or-break behaviours must be smoke-tested in production at a real location (section 10).
13. **Kitchen/KDS prerequisites are configuration, not code.** KDS needs Square for Restaurants Plus or Premium, or a standalone KDS subscription, on Android tablets; the device must have "View online, kiosk and delayed fulfilment orders" ON [S85, S86, S87].

## 2. Authentication, environments and scopes

### 2.1 Token types

| Type | What it is | Use for Dough Boss | Source |
|---|---|---|---|
| Personal access token (PAT) | "Unrestricted" access to everything in the account; cannot be scoped | Developer Console only: Webhook Subscriptions API, Events API (these two require the PAT), initial sandbox work | [S1] OBSERVED |
| OAuth access token | Scoped to the permissions the seller granted; expires after 30 days; refresh token | Production server calls | [S1, S3] OBSERVED |

- Square's own guidance: "multi-tenant applications that serve multiple sellers should use OAuth"; "for custom integrations that only access your own Square account, personal access tokens are suitable for production use" [S1]. A PAT is therefore *allowed* for a single-seller integration but cannot be least-privilege: a leaked PAT can read wages, issue refunds and change the catalogue.
- **Recommendation (INFERRED from S1, S4, S5):** use the OAuth **code flow** (confidential server client; refresh tokens do not expire, can mint multiple access tokens) with a registered Square application owned by Elie, authorise once against the Dough Boss seller account, then mint **reduced-scope access tokens** per function using `ObtainToken` with `grant_type: refresh_token` and an optional `scopes` array [S3, S5]. Keep the PAT only for webhook subscription and Events API calls, stored in the secrets manager, never in the Next.js runtime environment that serves the storefront.
- OAuth operations: access token lifetime 30 days; Square recommends refreshing every 7 days or less and alerting if a token is older than 8 days; `oauth.authorization.revoked` webhook signals revocation; ListLocations is the recommended validity check [S3, S4, S5].
- PKCE flow is for public clients (refresh tokens single-use, expire after 90 days). Not needed: the Next.js server is a confidential client [S3].
- Environments: production base `https://connect.squareup.com`, sandbox `https://connect.squareupsandbox.com`. Tokens, application IDs and OAuth endpoints are environment-specific; the wrong pairing returns `AUTHENTICATION_ERROR` / `UNAUTHORIZED` [S1, S3]. `SquareEnvironment.Production` / `.Sandbox` in the SDK [S7 `environments.d.ts`].
- The SDK reads `SQUARE_TOKEN` from the environment if `token` is not passed [S7 `auth/BearerAuthProvider.js`].
- Record the scopes you request; Square does not tell you later what an authorisation code or token carries (RetrieveTokenStatus lists scopes for a token) [S4, S13].
- An OAuth JWT option (`use_jwt` on ObtainToken) was added in 2026-01-22; not researched further [S10].

### 2.2 Minimum permissions by use case (OBSERVED in the permissions reference [S2] unless noted)

| Use case | Endpoints | Minimum OAuth permissions |
|---|---|---|
| Read catalogue | ListCatalog, SearchCatalogObjects, SearchCatalogItems, BatchRetrieveCatalogObjects, RetrieveCatalogObject, CatalogInfo | `ITEMS_READ` |
| Write catalogue (only if Square-to-website is not the only direction) | UpsertCatalogObject, BatchUpsert, images, UpdateItemTaxes/ModifierLists | `ITEMS_WRITE` |
| Read locations / hours | ListLocations, RetrieveLocation | `MERCHANT_PROFILE_READ` |
| Create and update orders | CreateOrder, UpdateOrder, CloneOrder | `ORDERS_WRITE` |
| Preview totals | CalculateOrder | none listed ("N/A") |
| Create payment link | CreatePaymentLink | `ORDERS_WRITE`, `ORDERS_READ`, `PAYMENTS_WRITE` |
| Delete payment link (cancels unpaid order) | DeletePaymentLink | `ORDERS_READ`, `ORDERS_WRITE` [S50] |
| Read orders (reporting, reconciliation) | SearchOrders, BatchRetrieveOrders | `ORDERS_READ` |
| Retrieve one order | RetrieveOrder | table lists `ORDERS_WRITE` and `ORDERS_READ` together (unclear if either suffices): grant both to the server token that calls it |
| Take a payment (Web Payments SDK path) | CreatePayment, CompletePayment, CancelPayment | `PAYMENTS_WRITE` |
| Read payments, refunds | GetPayment, ListPayments, GetPaymentRefund | `PAYMENTS_READ` |
| Refund | RefundPayment | `PAYMENTS_WRITE` (do not grant to the storefront token) |
| Read labour (timecards, scheduled shifts) | SearchTimecards, RetrieveTimecard, SearchScheduledShifts | `TIMECARDS_READ` |
| Read team members, wage settings, team-member wages | SearchTeamMembers, RetrieveWageSetting, ListTeamMemberWages | `EMPLOYEES_READ` |
| Read break types, workweek config | ListBreakTypes, ListWorkweekConfigs | `TIMECARDS_SETTINGS_READ` |
| Write timecards / scheduled shifts | CreateTimecard, CreateScheduledShift, Publish | `TIMECARDS_WRITE` (the UpdateTimecard row in the table appears to render the wrong permissions; verify before relying on it) |
| Read customers | SearchCustomers, RetrieveCustomer | `CUSTOMERS_READ` |
| Create/update customers | CreateCustomer, UpdateCustomer, groups, custom attributes | `CUSTOMERS_WRITE` |
| Loyalty read / write | RetrieveLoyaltyProgram, SearchLoyaltyAccounts, CalculateLoyaltyPoints / CreateLoyaltyAccount, AccumulateLoyaltyPoints, CreateLoyaltyReward, RedeemLoyaltyReward | `LOYALTY_READ` / `LOYALTY_WRITE` |
| Invoices | Get/List/Search / Create, Update, Publish, Cancel, Delete | `INVOICES_READ` / `INVOICES_WRITE` + `ORDERS_WRITE`; plus `CUSTOMERS_READ` + `PAYMENTS_WRITE` for card-on-file charging [S81] |
| Gift cards | List/Retrieve / Create, activities | `GIFTCARDS_READ` / `GIFTCARDS_WRITE` (+ `PAYMENTS_WRITE`, `ORDERS_WRITE` for order-integrated activate/reload) [S82] |
| Inventory | BatchRetrieveInventoryCounts, changes / BatchChangeInventory | `INVENTORY_READ` / `INVENTORY_WRITE` [S83] |
| Reporting API (Beta) | `/reporting/v1/meta`, `/v1/load` | `REPORTING_READ` [S84]; not in the permissions reference page I retrieved |
| Checkout settings (hosted page branding, tipping) | Retrieve/Update merchant and location settings | RetrieveMerchantSettings: `MERCHANT_PROFILE_READ` + `PAYMENT_METHODS_READ` [S46]; location-settings and update scopes not read (verify) |
| Webhook Subscriptions API, Events API | create/list subscriptions, SearchEvents | **PAT only**, not OAuth [S1, S61] |

Webhook events need the matching read permission (for example `payment.*` needs `PAYMENTS_READ`) or they are not delivered [S55].

### 2.3 Least-privilege token plan (INFERRED)

| Token | Scopes | Where it lives |
|---|---|---|
| `storefront` | `MERCHANT_PROFILE_READ`, `ITEMS_READ`, `ORDERS_WRITE`, `ORDERS_READ`, `PAYMENTS_WRITE`, `PAYMENTS_READ`, `CUSTOMERS_READ`, `CUSTOMERS_WRITE` (only if the site creates customer profiles) | Next.js server runtime secret |
| `backoffice-read` | `ORDERS_READ`, `PAYMENTS_READ`, `TIMECARDS_READ`, `EMPLOYEES_READ`, `INVENTORY_READ`, `ITEMS_READ`, `MERCHANT_PROFILE_READ`, `LOYALTY_READ`, `INVOICES_READ`, `REPORTING_READ` | worker/cron runtime only |
| `ops-write` (add only when needed) | `ITEMS_WRITE`, `INVENTORY_WRITE`, `TIMECARDS_WRITE`, `LOYALTY_WRITE`, `INVOICES_WRITE` | separate admin tool, behind staff login |
| PAT | all | secrets manager; used by a human or a deploy step to manage webhook subscriptions |

Refund (`PAYMENTS_WRITE`) is in the storefront token because CreatePaymentLink needs it [S2]; this is a Square design constraint, so isolate that token behind server code with no user-controlled amounts.

## 3. Official Node SDK (`square` on npm)

OBSERVED from the published package (S6, S7) and the GitHub README (S8):

- Version **46.0.0** (2026-09-15). Matches API `2026-09-16` [S10]. Node >= 18; the package is CommonJS (`main: ./index.js`, no `"type": "module"`); `exports` has `"."` and `"./legacy"`; `sideEffects: false` [S7 `package.json`]. Under Next.js 15 use it only in server code (Route Handlers, server actions) with `export const runtime = "nodejs"`; Edge runtime support is not documented (UNKNOWN; do not use the SDK there).
- Installation `npm i square`. Pin the exact version in `package.json` (no caret) because each SDK release bumps the default API version [S6, S7, S9, S10]. Square's SDK versions: Node uses `<MAJOR>.<MINOR>.<PATCH>` with no API date in the version, so only `version.js` / `BaseClient.js` tell you the API version [S9, S7].
- Client construction:

```ts
import { SquareClient, SquareEnvironment, SquareError } from "square";

export const square = new SquareClient({
  token: process.env.SQUARE_STOREFRONT_TOKEN,   // or a Supplier<string> that returns the current refreshed token
  environment: SquareEnvironment.Production,    // "https://connect.squareup.com"; Sandbox = "https://connect.squareupsandbox.com"
  version: "2026-09-16",                        // type is the literal "2026-09-16" in 46.0.0 (see below)
  timeoutInSeconds: 20,                         // SDK default is 60
  maxRetries: 2,                                // SDK default
});
```
  - `token` accepts a supplier function, which is how a refreshing OAuth token is injected [S7 `BearerAuthProvider.d.ts`: `core.Supplier<core.BearerToken>`].
  - `version` option type in 46.0.0 is the literal `"2026-09-16"` (per-client and per-request). The README example `version: "2024-05-04"` does not type-check against that literal; pinning an older API version needs a cast. Prefer pinning the SDK version instead [S7 `BaseClient.d.ts`, S8].
- Types: import the `Square` namespace, e.g. `Square.Order`, `Square.CatalogObject`. Types are camelCase (`catalogObjectId`, `priceMoney`, `idempotencyKey`); the SDK maps to/from the snake_case wire format [S7, S8].
- **Money amounts are `bigint`**: `amountMoney: { amount: BigInt(1000), currency: "AUD" }`. The request serialiser rejects non-bigint amounts; responses parse to `bigint`. Convert before `JSON.stringify` or returning data to the client [S7 `serialization/types/Money.js`, `core/schemas/builders/bigint`].
- Catalog `version`, `catalogVersion`, `minSelectedModifiers` on `CatalogModifierList` are also `bigint` (but `CatalogItemModifierListInfo.minSelectedModifiers` is `number`): handle both [S7 `CatalogObjectBase.d.ts`, `CatalogModifierList.d.ts`, `CatalogItemModifierListInfo.d.ts`].
- Error type: `SquareError` (`statusCode`, `message`, `body`, `errors[]` each `{category, code, detail?, field?}`, `rawResponse`, `cause`); `SquareTimeoutError` also exists [S7 `errors/SquareError.d.ts`, S8]. Error categories/codes are the API's own (`AUTHENTICATION_ERROR`/`UNAUTHORIZED`, `INSUFFICIENT_SCOPES`, `RATE_LIMITED`, ...) [S13].
- Retries: automatic, default **2** retries with exponential backoff; the current default policy retries 408, 429 and **all 5xx** (a "recommended" policy retries 408, 429, 502, 503, 504 only). Because POSTs can be retried, **always send an idempotency key on writes** [S7 README, S8].
- Pagination: `client.catalog.list`, `client.customers.list`, `client.checkout.paymentLinks.list` return an iterable `Page` (`for await (const x of page)`, `hasNextPage()`, `getNextPage()`). `catalog.search`, `catalog.searchItems`, `orders.search`, `labor.searchTimecards`, `teamMembers.search`, `customers.search` return raw `{ ..., cursor }` responses: loop yourself, passing the **same query** with the new `cursor` [S7 `Client.d.ts` files, S12].

```ts
async function* searchAll<T>(call: (cursor?: string) => Promise<{ items: T[]; cursor?: string }>) {
  let cursor: string | undefined;
  do {
    const page = await call(cursor);
    yield* page.items;
    cursor = page.cursor;
  } while (cursor);
}
```
  Cursors expire after 5 minutes; pages can go stale if data changes mid-iteration [S12].
- Raw response/headers: append `.withRawResponse()` to a call [S8]. Per-request overrides: `timeoutInSeconds`, `maxRetries`, `abortSignal`, `headers`, `queryParams` [S7 `BaseClient.d.ts`].
- Webhook helper: `WebhooksHelper.verifySignature({ requestBody, signatureHeader, signatureKey, notificationUrl })` is **async** in 46.0.0 and compares with `===`; see section 8.1 [S7 `wrapper/WebhooksHelper.js`].
- Resource clients available in 46.0.0 include `catalog`, `orders`, `checkout.paymentLinks`, `payments`, `customers`, `loyalty`, `labor`, `teamMembers`, `team`, `invoices`, `giftCards`, `inventory`, `locations`, `events`, `webhooks`, `reporting`, `employees`, `v1Transactions` [S7 `api/resources`]. The deprecated `employees` API and `labor.shifts.*` should not be used (Shift endpoints retired at 2026-05-21 and return 410 GONE) [S73].
- A legacy SDK is reachable as `square/legacy` for gradual migration; not needed for new code [S8].

## 4. API-by-API reference

Conventions: **Scope** is the minimum OAuth permission. All endpoints under `https://connect.squareup.com` (prod) with `Square-Version: 2026-09-16`. "Gotcha" lists traps found in the docs or SDK.

### 4.1 Locations API

- `GET /v2/locations`, `GET /v2/locations/{id}` (`client.locations.list/get`). Scope `MERCHANT_PROFILE_READ` [S2].
- Key fields on `Location` [S7 `api/types/Location.d.ts`]: `id`, `name`, `business_name`, `status` (`ACTIVE`/`INACTIVE`), `type` (`PHYSICAL`/`MOBILE`), `address` (standard `Address`: `address_line_1`, `locality`, `administrative_district_level_1`, `postal_code`, `country`), `timezone` (IANA, e.g. `Australia/Sydney` is the expected value; INFERRED), `country`, `currency` (expect `AUD`; INFERRED), `phone_number`, `business_email`, `website_url`, `description`, `coordinates`, `logo_url`, `mcc`, `capabilities`, `business_hours`, `custom_receipt_text`, `return_policy` (Beta, 1,000 chars, added 2026-08-19 [S10]).
- `business_hours.periods[]`: `{ day_of_week: SUN..SAT, start_local_time: "HH:MM:SS", end_local_time: "HH:MM:SS" }`, local times, "at most 10 periods per day" [S7 `BusinessHours*.d.ts`].
- `capabilities` enum only has `CREDIT_CARD_PROCESSING`, `AUTOMATIC_TRANSFERS`, `UNLINKED_REFUNDS`; it does **not** describe KDS, online ordering or pickup [S7 `LocationCapability.d.ts`].
- Gotchas: (a) no holiday/special-hours field and no pickup-specific or online-ordering hours field is exposed (OBSERVED absence in the type); keep Dough Boss's own overrides. (b) Whether a period may cross midnight is not documented (UNKNOWN; verify against a late-night store). (c) `location.updated` webhook exists (`MERCHANT_PROFILE_READ`) [S54]. (d) Every order, payment and timecard needs a `location_id`; store the three location IDs in config and also cache `ListLocations` daily.

### 4.2 Catalog API

Object types for a menu (`CatalogObjectType`): `ITEM`, `ITEM_VARIATION`, `MODIFIER_LIST`, `MODIFIER`, `CATEGORY`, `DISCOUNT`, `TAX`, `PRICING_RULE`, `PRODUCT_SET`, `TIME_PERIOD`, `IMAGE`, `ITEM_OPTION`, `ITEM_OPTION_VAL`, `CUSTOM_ATTRIBUTE_DEFINITION`, `AVAILABILITY_PERIOD`, others [S7 `CatalogObjectType.d.ts`].

Endpoints and limits (OBSERVED):

| Endpoint | Scope | Notes |
|---|---|---|
| `GET /v2/catalog/list?types=...&cursor=` | `ITEMS_READ` | Page size currently **100**. **Always pass `types` explicitly**. Default types are ITEM, CATEGORY, TAX, DISCOUNT, MODIFIER_LIST, PRICING_RULE, PRODUCT_SET, TIME_PERIOD, MEASUREMENT_UNIT, SUBSCRIPTION_PLAN, ITEM_OPTION, CUSTOM_ATTRIBUTE_DEFINITION, QUICK_AMOUNT_SETTINGS; **nested types (ITEM_VARIATION, MODIFIER, IMAGE, ITEM_OPTION_VAL) are not included unless named**. Does not return deleted objects. `catalog_version` (Beta) returns historical state [S21] |
| `POST /v2/catalog/search` (SearchCatalogObjects) | `ITEMS_READ` | `object_types[]`, `begin_time` (exclusive), `include_deleted_objects`, `include_related_objects` (one level), `include_category_path_to_root`, `limit` (max **1,000**), `query`, `include_options` (Beta). Response has `latest_time`. `include_deleted_objects` and `include_category_path_to_root` cannot both be true [S22] |
| `POST /v2/catalog/search-catalog-items` | `ITEMS_READ` | filters `text_filter`, `category_ids`, `enabled_location_ids`, `product_types`, `stock_levels`, `archived_state`, `custom_attribute_filters` (max 10); default limit 100; filters are ANDed [S17, S7] |
| `POST /v2/catalog/batch-retrieve` | `ITEMS_READ` | up to **1,000** `object_ids`; `include_related_objects` [S23] |
| `GET /v2/catalog/info` | `ITEMS_READ` | returns live limits (batch upsert, search page, etc.); read at start-up rather than hard-coding [S7 `CatalogInfoResponseLimits.d.ts`] |
| Batch upsert | `ITEMS_WRITE` | sync guide says up to 10,000 objects; live limit from `catalog/info` [S19, S7] |

**Presence per location** (`CatalogObjectBase`) [S7 `CatalogObjectBase.d.ts`]:
- `present_at_all_locations` (default true if unspecified) plus `absent_at_location_ids` (exceptions when true) and `present_at_location_ids` (inclusions when false). These lists "can include locations that are deactivated".
- Resolution rule for our cache (INFERRED, test it): an object is available at location L if `present_at_all_locations !== false ? !absent_at_location_ids.includes(L) : present_at_location_ids.includes(L)`. Evaluate for the item **and** for each variation, modifier list and modifier separately.
- Variation price override: `item_variation_data.location_overrides[]` = `{ location_id, price_money, pricing_type, track_inventory, sold_out (read-only), sold_out_valid_until (read-only) }`. Per-location price = override if present, else `price_money` [S7 `ItemVariationLocationOverrides.d.ts`]. Modifiers have `location_overrides` too [S7 `CatalogModifier.d.ts`]. Verify an order created at store X is priced with X's override (smoke test).
- Categories use **channels**, not `present_at_*`, for location visibility when they are menu categories (`category_type = MENU_CATEGORY`): menu builder creates MENU_CATEGORY objects that can look like duplicates of REGULAR_CATEGORY ones; filter on `category_type` and keep REGULAR_CATEGORY logic for reporting and kitchen routing [S20]. Item `channels` is read-only.

**Item**: `item_data` fields we need [S7 `CatalogItem.d.ts`]: `name`, `description` (deprecated), `description_html` (HTML subset: `a, b, strong, br, code, div, h1-h6, i, em, li, ol, p, ul, u`), `description_plaintext` (server-generated), `buyer_facing_name`, `kitchen_name`, `abbreviation`, `product_type` (`REGULAR`, `FOOD_AND_BEV`, `GIFT_CARD`, `APPOINTMENTS_SERVICE`, `EVENT`, `DIGITAL`, `DONATION`, ...), `is_taxable`, `tax_ids[]`, `categories[]` (`{id, ordinal}`; `category_id` is deprecated), `reporting_category`, `modifier_list_info[]`, `variations[]` (min 1, max 250), `image_ids[]` (first shows on POS), `skip_modifier_screen`, `item_options` (max 6), `is_archived`, `is_alcoholic`, `channels`, `food_and_beverage_details`.

**Dietary and ingredient fields** (OBSERVED, `item_data.food_and_beverage_details`, used with `product_type = FOOD_AND_BEV`) [S7 `CatalogItemFoodAndBeverageDetails*.d.ts`]:
- `calorie_count` (kcal).
- `dietary_preferences[]`: `{ type: "STANDARD" | "CUSTOM", standard_name, custom_name }`. `standard_name` enum: `DAIRY_FREE`, `GLUTEN_FREE`, `HALAL`, `KOSHER`, `NUT_FREE`, `VEGAN`, `VEGETARIAN`.
- `ingredients[]`: `{ type: "STANDARD" | "CUSTOM", standard_name, custom_name }`. `standard_name` enum: `CELERY`, `CRUSTACEANS`, `EGGS`, `FISH`, `GLUTEN`, `LUPIN`, `MILK`, `MOLLUSCS`, `MUSTARD`, `PEANUTS`, `SESAME`, `SOY`, `SULPHITES`, `TREE_NUTS`.
- Square documents these as descriptive fields; whether they meet Australian allergen labelling duties (Food Standards Code) was not researched here (UNKNOWN; outside this slice). Do not present them to customers as allergen guarantees until Elie confirms the compliance approach. Ingredients and modifier-level allergens (for example a sesame crust modifier) need their own check because the dietary/ingredient object is on the item, not on modifiers.

**Variation**: `item_variation_data` [S7 `CatalogItemVariation.d.ts`]: `item_id`, `name`, `sku`, `upc`, `pricing_type` (`FIXED_PRICING` / `VARIABLE_PRICING`), `price_money` (`{ amount, currency }`), `location_overrides`, `track_inventory`, `sellable`, `stockable`, `stockable_conversion`, `measurement_unit_id`, `item_option_values`, `kitchen_name`, `image_ids`, `vendor_information` (Beta; needs Retail/Restaurants plan).
- **Price units**: `price_money.amount` is the smallest currency unit as an integer; for AUD 100 = A$1.00 [S14]. Money minimum card payment in AU is A$0.01 [S67]. `VARIABLE_PRICING` items have no price and cannot be sold from the website without an amount decision.

**Modifier list and modifiers** [S7 `CatalogModifierList.d.ts`, `CatalogModifier.d.ts`, `CatalogItemModifierListInfo.d.ts`, `CatalogModifierOverride.d.ts`]:
- `modifier_type`: `LIST` or `TEXT` (text modifiers have `max_length`, `text_required`).
- `min_selected_modifiers` / `max_selected_modifiers`: `-1` default ("not set"), `0` min = none required, `0` max = no maximum, `>0` = limit; can exceed the number of modifiers when `allow_quantities` is true. **Per-item override** lives in `item_data.modifier_list_info[]` (`modifier_list_id`, `min_selected_modifiers`, `max_selected_modifiers`, `enabled`, `ordinal`, `allow_quantities`, `hidden_from_customer_override`, `modifier_overrides[]` with `on_by_default_override`, `hidden_online_override` using `YES`/`NO`/`NOT_SET`). When the item-level min and max are both `-1`, use the list's values.
- `selection_type` (`SINGLE`/`MULTIPLE`) is **deprecated** in favour of min/max.
- Modifier fields: `name`, `price_money`, `on_by_default`, `ordinal`, `hidden_online`, `kitchen_name`, `location_overrides`, `image_id`, `child_modifier_list_ids` (nested modifiers).
- **Nested modifiers are Beta** (2026-08-19): up to 5 child lists per modifier and 3 levels deep; fetching them needs `include_options` on SearchCatalogItems/SearchCatalogObjects/BatchRetrieve [S7, S10]. Order line modifiers then carry `parent_modifier_uid`.
- Gotcha (UNKNOWN): whether CreateOrder rejects selections that violate min/max. Enforce server-side and confirm in the smoke test.

**Discount, pricing rule** [S7 `CatalogDiscount.d.ts`, `CatalogPricingRule.d.ts`]: `CatalogDiscount` has `discount_type` (fixed or percentage), `percentage` (string), `amount_money`, `maximum_amount_money`, `pin_required`, `modify_tax_basis`. `CatalogPricingRule` auto-applies a discount: `discount_id`, `match_products_id` (PRODUCT_SET), `exclude_products_id`, `time_period_ids` (TIME_PERIOD), `valid_from/until_date`, `valid_from/until_local_time`, `minimum_order_subtotal_money`, `customer_group_ids_any`, `exclude_strategy`. Auto-apply requires `pricing_options.auto_apply_discounts: true` on the order and catalogue-referenced line items; ad hoc lines cannot get rule-based discounts [S24, S28]. This is how happy-hour or bundle promotions can run in Square rather than in WordPress code.

**Images**: `CatalogImage { name, url, caption }`; the URL is generated by Square after upload; only the first image on an item shows on POS [S7 `CatalogImage.d.ts`].

**Related objects**: `include_related_objects: true` on SearchCatalogObjects or BatchRetrieve returns referenced categories, taxes, images and modifier lists for an item, one level deep; a variation returns its parent item [S22, S23].

**Cache invalidation (OBSERVED)** [S18, S19, S16]:
- Webhook `catalog.version.updated` (needs `ITEMS_READ`); payload `data.object.catalog_version.updated_at`. Fired for any mutation from Dashboard, POS or API; a batch upsert fires **one** event for the whole batch; an import by a seller fires one event per imported item (burst).
- Recommended sync: on event, call SearchCatalogObjects with `begin_time = last stored latest_time` (the SearchCatalogObjects response `latest_time`), explicit `object_types`, `include_deleted_objects: true`; store the new `latest_time`. "Using a locally generated timestamp might cause you to miss updates" [S18]. The sync guide alternately says store the webhook's `updated_at` [S19]; the two docs differ, prefer `latest_time` and also run a daily full reconcile.
- Do **not** poll ListCatalog on an interval or on every event: "your result set can be very large and subject your application to rate limiting" [S19]. Debounce events (for example coalesce for 30 to 60 seconds).
- Object `version` increments per mutation; orders store the catalogue `version` of each referenced object so you can retrieve prices as at order time (`catalog_version` on line items) [S16].
- Gotcha: `isDeleted` objects arrive only with `include_deleted_objects`; a sold-out item is not deleted (see `sold_out` override) [S7].

### 4.3 Orders API

#### 4.3.1 CreateOrder (and the same `order` object inside CreatePaymentLink)

- `POST /v2/orders`, scope `ORDERS_WRITE`. Body `{ idempotency_key (max 192), order }`. If `order` is set, only `idempotency_key` may sit beside it [S37].
- `order` fields we use [S35, S7]:
  - `location_id` (required), `reference_id` (**max 40** chars, our order ref), `customer_id` (max 191; "specify a customer_id ... to ensure transactions are reliably linked ... omitting might result in new instant profiles"), `ticket_name` (max 255, "short-term identifier", e.g. customer first name or pickup number; whether it prints on KDS tickets is UNKNOWN), `source: { name }` (defaults to the application name; useful for filtering reports), `state` (`OPEN` default, `DRAFT`, `COMPLETED`, `CANCELED`), `line_items[]`, `taxes[]`, `discounts[]`, `service_charges[]`, `fulfillments[]` (**at most one** via API), `pricing_options { auto_apply_discounts, auto_apply_taxes }`, `metadata`.
- **Line items** [S24, S7]: two options: reference a catalogue variation ("strongly recommended", inherits catalogue price, updates inventory) or ad hoc (no catalogue, no rule-based discounts). Fields: `catalog_object_id` (the **ITEM_VARIATION** id), `catalog_version` (optional; if set, price is taken from that version), `quantity` (**string**, "1"), `note`, `modifiers[]` (`catalog_object_id`, `quantity` string, `base_price_money` overrides the catalogue price if set, `parent_modifier_uid`), `applied_taxes[]`, `applied_discounts[]`, `metadata`, `item_type`. Quantity 0 lines are removed when paid.
- **Kitchen routing / KDS**: use catalogue variation ids, not ad hoc lines, so kitchen routing categories, `kitchen_name` and inventory work (INFERRED from S86/S87: routing is by item assigned to kitchen routing categories).
- **Metadata limits** (applies to Order, Fulfillment, line item, tax, discount, service charge) [S27]: keys <= 60 chars from `a-z A-Z 0-9 _ -`; values <= **255** chars; **10** entries per metadata map; entries are private to the writing application; do not store PII or card data. Any app writing metadata bumps `Order.version`.
- **Idempotency**: `idempotency_key` max 192 for orders; CreatePayment is max **45** [S37, S63].
- **Draft orders**: `state: DRAFT` for cart-building; DRAFT cannot be fulfilled or paid, is not in Dashboard Sales Summary, and Square may delete DRAFT orders not updated in 30 days. UpdateOrder to `OPEN` before payment [S24].
- **Taxes and discounts** [S28, S7 `OrderLineItemTax.d.ts`, `CatalogTax.d.ts`]: tax `type` is `ADDITIVE` or `INCLUSIVE`; `scope` `ORDER` or `LINE_ITEM`; reference a `catalog_object_id` or give an ad hoc `percentage`. Catalogue tax `inclusion_type` is `ADDITIVE` or `INCLUSIVE`. In Australia the SDK docs state "For Europe and Australia, inclusive tax remains as part of the gross sale calculation" [S7 `OrderLineItem.d.ts`]. Interpretation (INFERRED): GST-inclusive prices should be modelled as an **INCLUSIVE** catalogue tax at the AU GST rate, so `total_money` equals the sum of shown prices and no GST is added on top. The GST rate itself was not retrieved: www.ato.gov.au returned HTTP 403 to automated fetch (origin response, not a proxy denial), so confirm the GST setup in the Square Dashboard and the ATO. Order-scoped taxes auto-attach to every line; set `pricing_options.auto_apply_taxes: true` to use catalogue rule-based taxes.
- **CalculateOrder** (`POST /v2/orders/calculate`, no permission listed): previews totals for an unsaved order, good for the cart total the buyer sees [S24, S2].
- **Order lifecycle**: Square snapshots the catalogue at creation; "create Order objects immediately before processing the transaction" [S26].

#### 4.3.2 PICKUP fulfilment (the exact fields)

`order.fulfillments[0]` = `{ uid?, type: "PICKUP", state: "PROPOSED", pickup_details, metadata? }` [S25, S34].

`pickup_details` [S32]:
| Field | Detail |
|---|---|
| `recipient` | `{ customer_id?, display_name, phone_number, email_address?, address? }`; `display_name` <= 255, `phone_number` <= 18, `email_address` <= 255. If `customer_id` is given the others fill from the profile; request values override. The reference page marks `display_name` and `phone_number` "required"; the narrative says `pickup_at` and `recipient.display_name` are the only required fields. Send both name and phone always [S33, S25]. |
| `schedule_type` | `SCHEDULED` (default) or `ASAP`. SCHEDULED requires `pickup_at`; ASAP requires `prep_time_duration` or `pickup_at` [S25, S32] |
| `pickup_at` | RFC 3339 timestamp, start of pickup window. For ASAP it is set to now + `prep_time_duration` unless you set it (then prep time is ignored) [S32, S25] |
| `prep_time_duration` | RFC 3339/ISO-8601 duration, e.g. `PT20M`. **"P5M" is five months; "PT5M" is five minutes** [S32] |
| `pickup_window_duration` | duration after `pickup_at` the order should be collected; informational for merchants [S32] |
| `note` | **max 500**, "displayed in the Square Point of Sale application" [S32] (the IN_STORE note is 550; do not mix them up [S25]) |
| `expires_at` | RFC 3339, up to 7 days ahead; fulfilment expires if not marked in progress. "If `expires_at` is not set, any new payments attached to the order are automatically completed" [S32]. Leave unset unless the smoke test shows it matters |
| `auto_complete_duration` | duration after which an in-progress pickup auto-moves to COMPLETED; unset = stays until completed or cancelled [S32] |
| `is_curbside_pickup`, `curbside_pickup_details` | Beta [S32] |
| read-only timestamps | `placed_at`, `accepted_at`, `ready_at`, `picked_up_at`, `rejected_at`, `expired_at`, `canceled_at`, `cancel_reason` (<= 100) [S32] |

Rules: only one fulfilment per API-created order; all items fulfilled at one location; `state`, `pickup_at`, `note`, recipient are editable while managed through our app; `expires_at`, `auto_complete_duration`, `prep_time_duration`, `schedule_type` only editable while `PROPOSED` [S25].

Fulfilment state machine for PICKUP: `PROPOSED` -> `RESERVED` (accepted, `accepted_at`) -> `PREPARED` (ready, `ready_at`) -> `COMPLETED` (picked up, `picked_up_at`); `CANCELED` and `FAILED` (`rejected_at`) [S25, S7 `FulfillmentState.d.ts`]. You cannot set a fulfilment COMPLETED until all payments on the order are complete [S25, S30]. You cannot delete a fulfilment, only cancel it [S25].

**Order closure (important)**: payment alone leaves an order `OPEN` when it has a fulfilment. Square staff advise setting the fulfilment and the order to COMPLETED in the **same** UpdateOrder call (`{ order: { version, state: "COMPLETED", fulfillments: [{ uid, state: "COMPLETED" }] } }`). Completing only the fulfilment hides the order from the Dashboard while it is still `OPEN` [F5, S30].

Example (payment-link order body; values illustrative, not real IDs):

```ts
const order: Square.Order = {
  locationId: LOCATION_ID,
  referenceId: ourOrderId,                   // <= 40 chars
  source: { name: "DoughBoss Web" },
  state: "OPEN",
  pricingOptions: { autoApplyDiscounts: true, autoApplyTaxes: true },
  lineItems: [{
    catalogObjectId: VARIATION_ID,
    quantity: "2",
    modifiers: [{ catalogObjectId: MODIFIER_ID, quantity: "1" }],
  }],
  fulfillments: [{
    type: "PICKUP",
    state: "PROPOSED",
    pickupDetails: {
      scheduleType: "SCHEDULED",
      pickupAt: pickupAtIso,                 // RFC 3339
      prepTimeDuration: "PT20M",
      note: noteForKitchen,                  // <= 500
      recipient: { displayName, phoneNumber, emailAddress },
    },
  }],
  metadata: { dbo_ref: ourOrderId },         // max 10 keys, 255-char values
};
```

#### 4.3.3 SearchOrders (reporting)

`POST /v2/orders/search`, scope `ORDERS_READ` [S36, S29, S7]:
- `location_ids` max **10** (same merchant); `limit` default 500, max **1,000**; `cursor` (re-send the **original query** with it); `return_entries: true` returns only `{order_id, location_id, version}` summaries.
- `query.filter` (ANDed): `state_filter.states[]` (OPEN, DRAFT, COMPLETED, CANCELED); `date_time_filter` with `created_at` / `updated_at` / `closed_at` ranges (RFC 3339); `fulfillment_filter` (`fulfillment_types[]`, `fulfillment_states[]`); `source_filter.source_names[]` (max 10); `customer_filter`.
- `query.sort`: `sort_field` `CREATED_AT` (default) | `UPDATED_AT` | `CLOSED_AT`; `sort_order` `DESC` (default) | `ASC`. **If you filter by a time range you must sort by the same field; sorting by `CLOSED_AT` requires a `state_filter` of COMPLETED and/or CANCELED** [S29, S35].
- Gotchas: includes orders from all sources (POS, Invoices, API); offline POS orders may take up to **72 hours** to arrive and carry the transmission time as `created_at` [S36]. `BatchRetrieveOrders` max **100** ids [S7 `BatchGetOrdersRequest.d.ts`]. No metadata filter is listed among the SearchOrders filters (OBSERVED absence), so keep our own order mapping rather than searching Square by metadata.

#### 4.3.4 Order metadata, notes and attribution storage

See section 7. Orders have no order-level free-text customer note field in the reference I read; text goes in `pickup_details.note` (<= 500), a line item `note`, or `ticket_name` (<= 255) [S35, S32, S7].

### 4.4 How API orders appear on POS, Orders app and KDS (the question)

**Question: what does an API-created pickup order need to appear on the KDS or Orders app, and must online orders be paid first?**

**Answer (OBSERVED unless labelled):**
1. **Fulfilment present and order paid.** "An order appears in the Square Dashboard or Square products (such as Square Point of Sale) if both the following conditions are true: the order includes fulfillment; the order is paid." [S24] "Orders with fulfillments appear on Square products ... only after they're paid for" [S25]. So **yes, online orders must be paid first.**
2. **State.** Order `OPEN`. DRAFT orders cannot be paid or fulfilled [S24]. For a quick-pay link the order is DRAFT until paid, then OPEN with a tender [S40]; the state transition for an order-checkout link was not documented (UNKNOWN; smoke test).
3. **Fulfilment type.** PICKUP works. DELIVERY "is not available to a seller and not shown in the Square Point of Sale unless you have a formal partnership agreement" [S25]; a July 2026 forum report confirms PICKUP orders created by the Orders API "appear in Orders Manager, appear in Square KDS, print correctly" while DELIVERY ones never appear (forum, community) [F1].
4. **Location.** The order's `location_id` must be the location where the KDS/POS device is signed in [S87 step 1 (device code must be signed in to the matching location); F4 shows the symptom "No Open Tickets" for an order created without a fulfilment, and a Square staff reply there: "A fulfillment is required if you'd like the order to stay open and push to the Order Manager"].
5. **Payment attached to the order.** Payment must reference the order (`order_id`) and equal `total_money`, via CreatePayment, PayOrder or the hosted checkout [S30, S62].
6. **Timing.** SCHEDULED orders enter Active/KDS at `pickup_at - prep_time_duration`; without `prep_time_duration` they go active immediately [S25]. ASAP orders: `pickup_at = now + prep_time_duration`.
7. **KDS routing configuration.** The KDS device must have **"View online, kiosk and delayed fulfilment orders"** toggled ON (Dashboard > Settings > Device management > Devices > Assigned modes > Manage > Routing > Source & fulfilment) and items must be in the right kitchen routing categories [S86, S87]. Whether an API-created order is classed as "online/kiosk" or "point of sale" for this toggle is UNKNOWN (verify; the troubleshooting article tells sellers to turn both ON [S87]).
8. **Subscription and hardware.** KDS is included in Square for Restaurants Plus or Premium, or a standalone KDS subscription; it runs on supported Android tablets (iPad KDS is being migrated away) [S85, S86]. KDS is available in Australia (AU support centre article, Australian support phone and hardware links) [S85]. Pricing for these plans was not verified here (another slice).
9. **Source.** No requirement on `source.name` is documented. The orders-API field `source.name` defaults to the application name [S35].
10. **Conflicting text.** The "Orders API: How it works" page says fulfilment orders "must have delay_capture set to true" and the order payment "must be approved with delayed capture" to appear in POS [S26]. This conflicts with the Create Orders / Fulfillments pages and with forum reports of normal paid (captured) orders appearing [S24, S25, F5, F1]. `delay_capture` is a legacy name for `autocomplete=false`. Treat S26 as stale; smoke-test with a normal captured card payment.

**Status round-trip.** KDS Expo devices update the fulfilment state; Prep-only devices may not ("I believe only the Expeditor will update an order fulfillment.state") (forum 2022) [F3]; a December 2025 forum post says in-store POS orders do not get fulfilment updates from KDS while online orders do (staff acknowledged, no fix announced) [F3]. A POS Quick Service order is COMPLETED on payment but "the item will still push to the KDS" (staff, 2026) [F2]. Practical consequence: do not depend on KDS to move our order to PREPARED; verify and have a staff-facing fallback (Orders app tap).

**Pay on pickup**: not supported for KDS visibility through the API (an unpaid order does not appear). Do not fake payment with a CASH/EXTERNAL payment to force visibility: it would falsify revenue and reconciliation (my recommendation). Unpaid-until-collection flows are UNKNOWN in the docs.

### 4.5 Checkout API (payment links)

- `POST /v2/online-checkout/payment-links` (`client.checkout.paymentLinks.create`), scopes `ORDERS_WRITE`, `ORDERS_READ`, `PAYMENTS_WRITE` [S47, S2].
- Request [S47, S49, S48]: `idempotency_key` (<=192), exactly one of `quick_pay { name, price_money, location_id }` or `order` (a full order as in CreateOrder; **you cannot pass an existing order id** [S43]); `description` (<=4096, internal); `payment_note` (<=500, copied to the Payment); `checkout_options`; `pre_populated_data`.
  - `checkout_options`: `redirect_url` (<=2048), `allow_tipping`, `custom_fields` (max **2**), `merchant_support_email` (<=256), `ask_for_shipping_address` (turns the fulfilment into SHIPMENT; do not set for pickup [S42]), `accepted_payment_methods { apple_pay, google_pay, cash_app_pay, afterpay_clearpay }`, `enable_coupon`, `enable_loyalty`, `shipping_fee`, `app_fee_money` (needs `PAYMENTS_WRITE_ADDITIONAL_RECIPIENTS`), `subscription_plan_id`.
  - `pre_populated_data`: `buyer_email`, `buyer_phone_number`, `buyer_address`. Buyer email/phone entered at checkout are written to the order fulfilment recipient after payment [S42].
- Response: `payment_link { id, version, order_id, url (short, https://square.link/u/...), long_url, checkout_options, pre_populated_data, created_at, updated_at, payment_note }` plus `related_resources.orders[]` [S48, S40].
- **Single use**: "A payment link can only be used to accept payment from a single buyer"; API links use `/order/{ORDER_ID}` URLs, are single use, and are never shown in the Dashboard; build our own management view [S43, S44].
- **Expiry**: no expiry/`expires_at` field exists on `PaymentLink`, `CheckoutOptions` or the create request (OBSERVED absence in the reference and in SDK 46.0.0 types [S48, S49, S7]). **Not found in official docs**: whether links expire on their own; verify with Square support. Plan our own TTL.
- **Abandoned links**: DeletePaymentLink (`DELETE /v2/online-checkout/payment-links/{id}`, scopes `ORDERS_READ`, `ORDERS_WRITE`) deletes the link and sets the unpaid order to **CANCELED** (returns `cancelled_order_id`). Deleting after payment leaves the order OPEN and payment COMPLETED. Deleting while a buyer is mid-checkout makes the buyer see an error and cancels the order [S44, S45, S50]. So: before deleting, RetrieveOrder and confirm no tender, then delete.
- **Updating** a link: `description`, `checkout_options` (send the whole object; omitted fields are deleted), `pre_populated_data` (merge); cannot change `order_id`, URL or timestamps; `fields_to_clear` exists [S45].
- **Payment completion and order state**: quick pay: order DRAFT -> OPEN, tender added, Square emails the receipt and a "payment received" email to the seller, then redirect to `redirect_url` or the Square confirmation page [S40]. Orders with fulfilments created via payment links stay `OPEN` after payment; Square documents a "known limitation" that the order and fulfilment must then be completed in Orders Manager and "Fulfillment cannot be updated programmatically" for DIGITAL fulfilments created by quick pay [S44]. Whether a **PICKUP** fulfilment on an order-checkout link can be advanced and the order completed through UpdateOrder is UNKNOWN: smoke test #7.
- **Customer linking**: orders from payment links "don't get associated with a customer"; read the buyer from `Payment.customer_id` (GetPayment) [S41, S44]. If you create the Customer first and set `order.customer_id`, whether it sticks is UNKNOWN (smoke test).
- **Redirect URL behaviour**: official docs only say the buyer is redirected to `redirect_url`. Forum posts by Square staff say production appends `orderId` (and community observed `transactionId`, `checkoutId`, `referenceId`), the sandbox does not append anything, and that it was once documented [S39, F7, F8]. **Not found in official docs.** Never use redirect parameters as proof of payment; use webhooks plus RetrieveOrder. A $0 payment link can test redirects in production (staff suggestion, forum) [F7].
- **Payment methods in AU**: cards (Visa, Mastercard, American Express, JCB, EFTPOS), Apple Pay and Google Pay yes; Cash App Pay no (US only); Afterpay yes with seller eligibility and default purchase ranges (AU minimum A$1.00, maximum A$2,000); Afterpay and Cash App not supported for subscription links [S66, S43, S67]. Enabling Afterpay/Clearpay is a Dashboard action, not an API action [S46].
- **Tipping**: default follows the country default tip setting (stated for the US/Canada as 15%); AU default not found in docs [S43].
- **Hosted page branding**: location checkout settings (tipping, branding, policies, customer notes) are API-manageable but the Sandbox is not supported [S46].
- Fees: the developer pricing page lists AU card-not-present/eCommerce **2.2%** and card-present 1.6% for API payments, but the page also carries dated text (for example about GST being excluded "on 11 May 2022"), and the International Transaction Fee table does not list Australia. Treat pricing as UNKNOWN until Elie reads his own Square agreement/Dashboard [S68].

### 4.6 Payments API and the Web Payments SDK (in-page alternative)

- `POST /v2/payments` (`client.payments.create`), scope `PAYMENTS_WRITE`. Required `source_id`, `idempotency_key` (**max 45**); `amount_money` (must **exactly equal** `order.total_money` when `order_id` is given); `order_id`, `location_id` (defaults to the seller's main location: always pass it), `customer_id`, `reference_id` (max 40), `note` (max 500), `autocomplete` (default true), `buyer_email_address`, `buyer_phone_number`, `verification_token`, `statement_description_identifier` (max 20) [S63, S62].
- Payment `status`: `APPROVED` (authorised), `COMPLETED`, `CANCELED`, `FAILED` [S62]. Tips are recorded on the payment (`tip_money`), not the order [S62]. Pay for an order with a single payment through CreatePayment; multiple payments use authorise (`autocomplete=false`) then `PayOrder` [S30]. `PayOrder` also needs both `ORDERS_WRITE` and `PAYMENTS_WRITE` [S38]. A $0 order needs a $0 CASH/EXTERNAL payment or PayOrder with no payments [S30].
- Web Payments SDK: browser JS that tokenises a card/wallet into a single-use token passed to CreatePayment as `source_id`; AU locale `English (Australia)` is supported; requires Secure Contexts and a Content-Security-Policy since 1 October 2025; payment session times out after 24 hours; does not create customers by itself [S65]. Token methods: card, gift card, Apple Pay, Google Pay, ACH, Afterpay, Cash App Pay (AU subset per S66/S67).
- Comparison (OBSERVED from Square's comparison table [S64] unless labelled):

| | Payment link (Checkout API) | Web Payments SDK + Payments API |
|---|---|---|
| Checkout UI | Square-hosted, Square-branded page; buyer leaves our site | Our page; card form components embedded by the SDK |
| Customisation | Low | High |
| Square manages orders and payment requests | Yes (creates the order) | No: we create the order and the payment |
| Extra APIs needed | none | Payments API (and Orders API) |
| PCI | Square's table marks "Square handles PCI compliance, chargebacks, and disputed payments" yes for both | yes for both |
| Integration effort | lowest | higher (CSP, SCA, token handling) |

  PCI implications (INFERRED): both reduce card-data exposure because card entry occurs in Square-controlled UI and our servers only see tokens/IDs; the exact PCI self-assessment type is **not stated in Square's docs** (UNKNOWN; confirm with Elie's acquirer, which is Square, and a QSA if needed). Never log card tokens or full webhook payloads containing card details (payment events include `card_details` with last 4, BIN and fingerprint) [S56].
- AU minimums: card A$0.01; cash/external A$0 in 5-cent increments; Afterpay A$1 to A$2,000 [S67]. SCA test cards are noted as JCB-capable only in AU/CA/JP [S70].
- **Recommendation**: start with the **payment link** (Square owns the order creation, hosted PCI surface, wallets, and Afterpay). Keep the Web Payments SDK path as the planned fallback if (a) a PICKUP order created through the link does not appear on KDS, or (b) conversion data shows the redirect to Square hurts checkout completion. The documented "order-ahead" reference flow itself uses CreateOrder + Web Payments SDK + CreatePayment [S31].

### 4.7 Webhooks

See section 8 for verification, retries and handler design. Events relevant to Dough Boss (permission): `order.created`, `order.updated`, `order.fulfillment.updated` (`ORDERS_READ`); `payment.created`, `payment.updated`, `refund.created`, `refund.updated` (`PAYMENTS_READ`); `catalog.version.updated` (`ITEMS_READ`); `inventory.count.updated` (`INVENTORY_READ`); `labor.timecard.created|updated|deleted`, `labor.scheduled_shift.created|updated|published|deleted` (`TIMECARDS_READ`); `team_member.created|updated`, `team_member.wage_setting.updated`, `job.created|updated` (`EMPLOYEES_READ`); `customer.created|updated|deleted` (`CUSTOMERS_READ`); `loyalty.account.created|updated|deleted`, `loyalty.program.created|updated`, `loyalty.promotion.created|updated`, `loyalty.event.created` (`LOYALTY_READ`); `invoice.created|published|updated|payment_made|scheduled_charge_failed|canceled|refunded|deleted` (`INVOICES_READ`); `gift_card.*` (`GIFTCARDS_READ`); `location.created|updated` (`MERCHANT_PROFILE_READ`); `oauth.authorization.revoked` (no permission); `online_checkout.location_settings.updated` / `merchant_settings.updated` [S54]. `labor.shift.*` are deprecated since 2025-05-21; until retirement both shift and timecard events fire for one action, so subscribe to only one family (timecard) [S73].

Event payload shapes (OBSERVED examples) [S56, S58, S59, S60]:
- `payment.updated`: `data.object.payment` = full Payment (`id`, `status`, `order_id`, `location_id`, `amount_money`, `total_money`, `approved_money`, `receipt_url`, `card_details`, `version_token`, ...).
- `order.updated` / `order.created`: `data.object.order_updated` = `{ order_id, location_id, state, version, created_at, updated_at }` only (**no line items**); always re-fetch the order.
- `order.fulfillment.updated`: `data.object.order_fulfillment_updated` = `{ order_id, location_id, state, version, fulfillment_update: [{ fulfillment_uid, old_state, new_state }] }`.
- `labor.timecard.updated`: full `timecard` object.
- Conflicting doc text: the per-event reference says `order.created` and `order.fulfillment.updated` are triggered **only** by API calls (not by POS actions) [S57, S59], while the events table says they also fire for Square product actions [S54]. Whether KDS/POS fulfilment changes produce `order.fulfillment.updated` is UNKNOWN: smoke test #9. Plan a polling backstop (SearchOrders by `updated_at`).

### 4.8 Idempotency, rate limits, errors

- Idempotency: same key + same request returns the original response; same key with a changed body returns an error (behaviour "might vary depending on the API"); recommended generator in Node is `crypto.randomUUID` [S11]. **Not found in official docs** (on the pages I read): how long a key is remembered; do not rely on key reuse across days. Key limits: CreateOrder/CreatePaymentLink/PayOrder 192; CreatePayment 45 [S37, S47, S38, S63].
- Pattern: store `{ourOrderId, step, idempotencyKey}` in our database before calling Square; reuse the same key on a retry of the same logical attempt; generate a new key (and a new Square order) if the cart or price changed.
- Rate limits: **no numeric limits are published** on the pages I read: "Square API endpoints might enforce different rate limits"; clients get HTTP **429** `RATE_LIMITED`; use exponential backoff with jitter, batch/bulk endpoints, filters on search/list, and a worker queue; ListCatalog polling is called out as a rate-limit trigger [S13, S19]. Not found in official docs: per-endpoint requests-per-second figures; check the Developer Console API Logs for 429s and ask Square support for figures if needed.
- Errors: every non-2xx has `errors[]` with `category`, `code`, `detail`, `field`; bulk endpoints can return per-item errors inside a 200. Auth errors: 401 `UNAUTHORIZED`, `ACCESS_TOKEN_EXPIRED`, `ACCESS_TOKEN_REVOKED`, `CLIENT_DISABLED`; 403 `FORBIDDEN`, `INSUFFICIENT_SCOPES` (some `INVALID_REQUEST_ERROR` are also 403). Show users friendly messages, never raw Square errors [S13].
- Money validation: `EXPECTED_INTEGER` if the amount is not an integer in the smallest unit [S14]. New `ErrorCode.AMOUNT_TOO_LOW` (2026-08-19) [S10].

### 4.9 Customers API and consent

- Endpoints (`CUSTOMERS_READ` / `CUSTOMERS_WRITE`): `CreateCustomer`, `UpdateCustomer`, `RetrieveCustomer`, `SearchCustomers`, bulk variants, groups, custom attributes [S2, S7].
- A customer needs at least one of given name, family name, company name, email or phone; `reference_id` ties to our own user id; CreateCustomer **does not de-duplicate**: SearchCustomers by phone/email/reference_id first; invalid phone numbers return 400 `INVALID_PHONE_NUMBER`; Square also auto-creates "instant profiles" after payments (some without public info, invisible to list/search) [S71].
- **Consent**: only `preferences.email_unsubscribed` (boolean, "read-only from the Customers API", reflects Square marketing email opt-out, possibly across all Square sellers). There is no SMS consent field and no opt-in timestamp field on `Customer` [S71, S7 `Customer.d.ts`, `CustomerPreferences.d.ts`]. Square's policy: "If you save customer contact information, you must obtain explicit permission from the customer ... otherwise your application might be disabled" [S71].
- Recommendation (INFERRED): keep our own consent ledger (what was consented, channel, wording version, time, source URL) in Dough Boss's database; mirror only the marketing preference you need into a Square **customer custom attribute** if sellers need to see it (not verified: custom attribute definition/visibility behaviour and limits).
- Square's own email marketing campaigns: no Marketing/Campaigns API appears in the permissions reference [S2] (absence, not proof). Plan email/SMS sending from our own tooling or a connected ESP.
- AU: Customers API available (no country restriction on that page except `tax_ids` for EU/UK) [S71].

### 4.10 Loyalty API

- AU: **yes**. "Square Loyalty is available in Australia, Canada, France, Ireland, Japan, Spain, the United Kingdom, and the United States"; the loyalty account phone number's country code must be a supported country; AU accrual uses the **after-tax** amount by default (seller setting can change it); points accrue before tips [S72].
- Seller must have a Square Loyalty subscription and program (created in the Dashboard; the API cannot create or modify the program; one program per seller; up to 10 ACTIVE+SCHEDULED promotions). Inactive subscription: writes (`CreateLoyaltyAccount`, `AdjustLoyaltyPoints`, `AccumulateLoyaltyPoints`) return 404 `NOT_FOUND` [S72].
- Endpoints: `RetrieveLoyaltyProgram` (the `ListLoyaltyPrograms` endpoint is deprecated), `CreateLoyaltyAccount` (needs E.164 phone; pass `customer_id` to avoid duplicate profiles), `SearchLoyaltyAccounts`, `CalculateLoyaltyPoints`, `AccumulateLoyaltyPoints` (compute and add points for an order), `AdjustLoyaltyPoints`, `CreateLoyaltyReward` (with `order_id`, Square updates the order with the discount and redeems after payment), `RedeemLoyaltyReward`, `DeleteLoyaltyReward`, promotions create/cancel/list; scopes `LOYALTY_READ`/`LOYALTY_WRITE` [S72, S2].
- Redeeming multiple rewards: different reward tiers on one order is supported, the same tier twice is not; only one reward per line item [S72].
- Hosted checkout can show the loyalty section via `enable_loyalty` [S7 `CheckoutOptions.d.ts`].
- UNKNOWN: whether points accumulate automatically for orders paid through an API payment link (the guide describes Orders API integration for the accumulate flow; confirm with a test order; webhook `loyalty.event.created` tells you).

### 4.11 Labor API and Team API (timesheets and labour cost)

- Labor API: Timecards (one per shift) and Scheduled shifts [S73]. **Square API version 2025-05-21 or later** is required for Timecards and scheduled shifts; Shift endpoints were retired 2026-05-21 and return 410 GONE [S73, S74, S76].
- `POST /v2/labor/timecards/search` (`TIMECARDS_READ`): filter `location_ids`, `team_member_ids`, `status` (OPEN/CLOSED), `start` range, `end` range, `workday { date_range, match_timecards_by, default_timezone }`; sort `START_AT`/`END_AT`/`CREATED_AT`/`UPDATED_AT`; `limit` default and max **200**. An invalid filter field or enum value is **silently ignored** (REST) so a typo can return too many results [S79, S77, S7].
- `Timecard` fields: `id`, `location_id`, `team_member_id`, `timezone` (read-only), `start_at`, `end_at` (minute precision, seconds truncated), `status` (OPEN/CLOSED), `wage { title, hourly_rate, job_id, tip_eligible }`, `breaks[] { id, start_at, end_at, break_type_id, name, expected_duration, is_paid }`, `declared_cash_tip_money`, `version`, timestamps [S7 `Timecard.d.ts`, `Break.d.ts`, `TimecardWage.d.ts`].
- Rules: a team member can have only **one OPEN timecard** at a time (create fails otherwise); to record labour cost you must set `wage.hourly_rate` on the timecard: "Job and wage information from the team member's primary job aren't used by default"; the wage defaults to zero if unset [S75, S74, S7].
- `ScheduledShift` has `draft_shift_details` and `published_shift_details` (`team_member_id`, `location_id`, `job_id`, `start_at`, `end_at`, `notes`); required for create: location, job, start, end. Some scheduling features need an active Shifts Plus subscription for sellers (for example updating shifts scheduled more than 10 days ahead) but the API itself is unrestricted [S76, S7]. Notifications go to team members' email on publish.
- Team API: `SearchTeamMembers` (`EMPLOYEES_READ`, default limit 100, max 200; filter by location, ACTIVE/INACTIVE, is_owner); `TeamMember` has `status`, names, email, phone (E.164), `assigned_locations`, `wage_setting { job_assignments[] { job_title, pay_type, hourly_rate | annual_rate + weekly_hours, job_id }, is_overtime_exempt }`; jobs endpoints (`CreateJob`, `ListJobs`) from API 2024-12-18; Square recommends reading wage settings from the team member record [S78, S80, S7].
- **What is not exposed**: team permissions/passcodes, the owner team member, adding staff to payroll or appointments, sending invitations, team-member sales/tip/activity reports (derive tips from Payments + timecards) [S78]. No award classification, penalty/overtime rate, superannuation or leave fields exist on `Timecard`, `WageSetting` or `JobAssignment` in SDK 46.0.0 (OBSERVED absence) [S7]. `is_overtime_exempt` and workweek config (`WorkweekConfig`, `TIMECARDS_SETTINGS_READ`) drive Square's own overtime calculation [S74, S7]. Do not treat Square timecards as award-compliant payroll; that analysis belongs to the timesheet/Fair Work slice.
- Payroll: the Labor API docs mention Square Payroll integration generally [S74]; Square Payroll availability in Australia was **not established** (UNKNOWN; confirm on the Australian Square site or with Square support before planning around it). The AU "Square Shifts" product page exists in search results but was not read.
- GraphQL and the Reporting API can also read labour data [S73, S84].
- Sandbox caveat: Permissions and Payroll sections are not available in the Sandbox Dashboard [S78].

### 4.12 Invoices API (catering and corporate deposits)

- AU: **yes** for the Invoices API (seller account must be activated in AU, CA, FR, IE, JP, ES, UK or US); Afterpay for invoices supported in AU [S81].
- Flow: CreateOrder (order gets `order_id`), ensure a customer in the Customer Directory, `CreateInvoice` (draft; must include `payment_requests[]`), then `PublishInvoice` [S81]. Scopes `INVOICES_WRITE` + `ORDERS_WRITE` (+ `CUSTOMERS_READ` + `PAYMENTS_WRITE` for cards on file) [S2, S81].
- Payment requests: `request_type` `BALANCE`, `DEPOSIT`, `INSTALLMENT`; deposit as `percentage_requested` (of order total) **or** `fixed_amount_requested_money` (not both); `due_date`; `automatic_payment_source` `NONE`/`CARD_ON_FILE`/`BANK_ON_FILE` (bank on file cannot be set by API); `reminders[]`; `tipping_enabled` (final request only). Max **13** payment requests (up to 12 INSTALLMENT); **INSTALLMENT needs an Invoices Plus subscription** [S7 `InvoicePaymentRequest.d.ts`, S81].
- Delivery: `delivery_method` `EMAIL`, `SHARE_MANUALLY`, `SMS`; **SMS cannot be set through the API** (can be left unchanged on update). SHARE_MANUALLY means Square does not send the invoice or receipts but still emails scheduled reminders [S81, S7].
- **Not supported by the API**: recurring invoices (use the Subscriptions API), invoice templates, **estimates**, bulk actions, invoice branding, attachments editing, shipping address; Square APIs cannot pay or set the status of an invoice; `invoice.public_url` is the hosted payment page [S81].
- Estimates in AU: the Square AU support centre documents "Create and send invoice estimates"; plan requirement stated as Invoices Plus or Premium (page summarised by the fetch tool; re-read before relying) [S88]. They are a Dashboard feature, not API-creatable.
- Events: `invoice.*` family (`INVOICES_READ`) [S54].

### 4.13 Gift Cards API

- Endpoints: `CreateGiftCard` (digital created PENDING with zero balance; or register a physical card), `CreateGiftCardActivity` (`ACTIVATE`, `LOAD`, `REDEEM`, `CLEAR_BALANCE`, `DEACTIVATE`, `ADJUST_INCREMENT`, `ADJUST_DECREMENT`, `REFUND`, ...), `LinkCustomerToGiftCard`, `RetrieveGiftCardFromGAN`; scopes `GIFTCARDS_READ`/`GIFTCARDS_WRITE` [S82, S2].
- AU: gift card **load fee of 2.5%** of the amount added applies to sellers in Australia, Canada and the US, on top of standard processing (ACTIVATE, LOAD, ADJUST_INCREMENT); no fee on redeem or refund. A formal "available in Australia" statement for the Gift Cards API was not found; the load-fee text implies it (INFERRED) [S82, S68].
- Developer must deliver digital gift card details to the buyer and secure custom GANs; no application fees on gift card payments; sandbox cannot use API-created gift cards in Virtual Terminal and cannot manage physical gift cards [S82, S69].

### 4.14 Inventory API

- Counts and adjustments **per item variation** per location per state (`IN_STOCK`, `SOLD`, `WASTE`, ...). Does **not** support ingredients, sub-components or bundles [S83]. Prep planning therefore needs our own recipe/ingredient model fed by sold quantities.
- Scopes `INVENTORY_READ` / `INVENTORY_WRITE`. Webhook `inventory.count.updated` (`INVENTORY_READ`) delivers `InventoryCount[]` [S54, S83].
- Cross-location moves are now ADJUSTMENTs with `from_location_id`/`to_location_id`; the old TRANSFER type retired 2026-07-15; cost and vendor recording requires Retail Plus, Restaurants Plus or Premium (Beta) [S10, S83]. AU applicability of those plans/Beta features is UNKNOWN.
- Orders referencing catalogue variations update inventory automatically when paid or fulfilled [S31, S26].

### 4.15 Events API and the Reporting API (additional)

- Events API (`SearchEvents`, PAT only): replays missed webhook events within **28 days**; call `EnableEvents` first (disabled by default); filter by event type, merchant, location, `created_at` [S61]. Use it for disaster recovery after downtime.
- Reporting API (**Beta**, 2026-04-21): `GET /reporting/v1/meta`, `POST /reporting/v1/load`, token scope `REPORTING_READ` (or PAT); Cube-style views (Sales, ItemSales, ModifierSales); data freshness about 15 minutes; `load` may return `{"error":"Continue wait"}` and must be retried; the SDK has `client.reporting` and `ReportingHelper.loadAndWait` [S84, S10, S8]. AU availability: UNKNOWN. Use it as a cross-check for SearchOrders totals, not as the transactional source.

## 5. Sandbox testing

- Sandbox base `https://connect.squareupsandbox.com`; free, unlimited API calls; up to 10 extra sandbox test accounts with a chosen country (choose AU for AUD behaviour); separate credentials per application [S69].
- Test card numbers for web tokenisation include Visa `4111 1111 1111 1111` CVV 111 and Mastercard `5105 1051 0510 5100`; any future expiry; the docs say a valid postal code is required for USD, CAD and GBP payments and not supported for Japan; AUD is not stated, so check what the sandbox form asks for [S70]. Payment tokens for direct CreatePayment tests: `cnon:card-nonce-ok`, `cnon:card-nonce-declined`, `cnon:card-nonce-rejected-cvv`, `cnon:card-nonce-rejected-postalcode`, `cnon:card-nonce-rejected-expiration`, `cnon:card-nonce-already-used`; error values: CVV `911`, postal `99999`, expiry `01/40`, card `4000000000000002` (decline) [S70]. Sandbox risk levels: amount 2222 = MODERATE, 3333 = HIGH [S70].
- Limitations that matter here: no Square for Restaurants / Point of Sale / Invoices app support; no Square hardware; no Apple Pay, Google Pay, Afterpay or Cash App checkout; creating a payment link with `app_fee_money` not supported; checkout settings API not supported; sandbox payment-link redirect does not append parameters; receipts and emails not sent; refunds viewable but not issuable in the sandbox Dashboard; no Permissions or Payroll Dashboard sections; do not keep PII in the sandbox [S69, S43, S46, S78, F7, F8].
- Use the sandbox for: catalogue sync, order and payment-link creation, webhook signature and idempotency handling, webhook retry handling, labour/team reads. Use **production** (with Elie's approval and a refund plan) for: KDS/Orders-app visibility, redirect parameters, wallet checkout, Afterpay, fulfilment status round trip.

## 6. Recommended architecture for Dough Boss

Principles: Square owns catalogue, order, payment and labour truth; our database holds a cache plus our own state (consent, attribution, order mapping, webhook ledger); the website never trusts the browser or redirect parameters for payment.

Components (Next.js 15, Node runtime):
1. **Square client module** (server-only): pinned SDK, token supplier with refresh, `Square-Version` pinned, idempotency helper, `bigint` to string/number boundary, `SquareError` mapper.
2. **Catalogue cache** (Postgres via Prisma, optionally in-memory/ISR): normalised tables for locations, categories, items, variations, modifier lists, modifiers, images, taxes, discounts; a `sync_state` row with `latest_time`. Initial full load by `ListCatalog` with explicit `types` (including `ITEM_VARIATION`, `MODIFIER`, `IMAGE`); incremental by `catalog.version.updated` -> debounce -> `SearchCatalogObjects(begin_time, include_deleted_objects)`; daily off-peak full reconcile; on change call `revalidateTag("menu")`.
3. **Menu resolver**: per-location availability and price (section 4.2 rule), hours from `Location.business_hours` plus Dough Boss overrides, `sold_out` overrides, dietary/ingredient flags, `hidden_online`.
4. **Checkout service**: validates cart against the cache (server re-prices from the cache, never from the browser), picks a pickup slot, builds the Order (PICKUP, `prep_time_duration`, `reference_id`, metadata `dbo_ref`), optional `CalculateOrder`, calls CreatePaymentLink with an idempotency key, records `{ourOrderId, squareOrderId, paymentLinkId, url, status=PENDING_PAYMENT}`.
5. **Webhook endpoint** (`runtime = "nodejs"`, reads raw body): verify signature, insert into `webhook_events` (unique `event_id`), return 200 immediately; process in a worker/queue.
6. **Order state machine** (our DB): `PENDING_PAYMENT -> PAID -> ACCEPTED -> READY -> COLLECTED`, plus `CANCELED`, `FAILED`, `REFUNDED`; always derived from RetrieveOrder/GetPayment, ordered by `Order.version`.
7. **Reaper** (cron every few minutes): for `PENDING_PAYMENT` older than the TTL (suggest 30 minutes; a business decision), RetrieveOrder; if unpaid, DeletePaymentLink; mark `EXPIRED`.
8. **Reconciler**: every 5 to 15 minutes SearchOrders (`updated_at` filter, `UPDATED_AT` sort, `source_filter`) per location to catch missed webhooks; Events API replay after any outage; nightly SearchOrders `closed_at` for reporting.
9. **Back-office jobs**: sales per location/hour from SearchOrders (and Reporting API as cross-check), labour via SearchTimecards (workday filter) joined to sales for labour-percent, prep sheets from sold items x recipe model (Inventory API cannot hold recipes).
10. **Consent and attribution store** (our DB).

### 6.1 Sequence (text)

```
Actors: B = Buyer browser, N = Next.js server, DB = our database, SQ = Square API, W = Square webhooks,
        POS = store POS/Orders app/KDS, ADS = GA4 / Meta / Google Ads

A. Browse menu (catalogue cache)
 B -> N: GET /menu?store=bankstown   (UTM / click IDs arrive on the landing request)
 N -> DB: read catalogue cache (resolved for location, hours, sold-out)         [no Square call on the hot path]
 N -> B: HTML (ISR, tagged "menu")
 SQ -> W -> N: catalog.version.updated  --> N: verify, store event, 200
 N (worker) -> SQ: SearchCatalogObjects(begin_time=latest_time, object_types=[...], include_deleted_objects)
 N -> DB: upsert; store latest_time; revalidateTag("menu")

B. Capture attribution (first party, consent-gated)
 B -> N: first landing: store utm_*, gclid, fbclid, _ga client id, fbp/fbc in a first-party cookie (with consent state)

C. Create order + payment link
 B -> N: POST checkout {cart, store, pickup slot, contact}   (server action)
 N -> DB: re-price from cache, validate availability/hours/modifier rules, create our order (PENDING_PAYMENT),
          persist attribution + consent snapshot against our order id
 N -> SQ: CreatePaymentLink(idempotency_key, order{location, PICKUP, prep_time, reference_id, metadata{dbo_ref}}, checkout_options{redirect_url=/order/<token>})
 SQ -> N: payment_link{url, order_id}     N -> DB: map ourOrderId <-> square order id/link id
 N -> B: 303 redirect to payment_link.url

D. Pay on Square-hosted page
 B -> SQ(hosted): card / Apple Pay / Google Pay / Afterpay
 SQ: order DRAFT/OPEN->OPEN with tender; receipt email; redirect buyer to /order/<token> (params NOT trusted)

E. Payment webhook
 SQ -> W -> N: payment.updated {payment.id, status, order_id}   (and order.updated)
 N: verify signature (url + raw body, HMAC-SHA256 base64, constant-time) ; dedupe on event_id ; 200
 N (worker) -> SQ: RetrieveOrder(order_id)   [authoritative]; GetPayment if needed
 N: check reference_id/metadata match, location match, total_money == our total, tender payment COMPLETED
 N -> DB: PENDING_PAYMENT -> PAID (idempotent upsert keyed on order_id + version)
 N -> ADS: server-side purchase events (section 7.3), using stored attribution (section 7.3)

F. Kitchen visibility
 SQ -> POS: paid order with PICKUP fulfilment appears in Orders app / KDS (at pickup_at - prep_time if scheduled)
 Staff on KDS/Orders app: accept (RESERVED) -> ready (PREPARED) -> collected (COMPLETED)

G. Status back to the buyer
 SQ -> W -> N: order.fulfillment.updated / order.updated {order_id, version, state}
 N -> SQ: RetrieveOrder -> map fulfilment state -> our state; DB update
 B -> N: GET /order/<token> polls our DB (or server-sent events)  --> "Accepted" / "Ready" / "Collected"
 Fallback: reconciler SearchOrders(updated_at) every 5-15 min

H. Unpaid / abandoned
 Cron: PENDING_PAYMENT older than TTL -> RetrieveOrder (no tender) -> DeletePaymentLink -> order CANCELED -> DB EXPIRED

I. Reporting
 Nightly + intraday: SearchOrders(location_ids<=10, closed_at range, state COMPLETED, sort CLOSED_AT) -> DB facts
 SearchTimecards(workday range) + team wages -> labour cost; join to sales by location/hour
 Reporting API (Beta) as cross-check
```

## 7. Closed-loop attribution and conversion reporting

### 7.1 What Square lets us store (OBSERVED)

- Order, fulfilment, line item metadata: <= 10 keys, key chars `a-z A-Z 0-9 _ -`, key <= 60, value <= 255, private to our app, not for PII or card data [S27]. A typical click ID (for example `gclid`, `fbclid`) fits 255 chars, but 10 keys is a hard cap: `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`, `gclid`, `fbclid`, `fbp`, `fbc`, `ga_client_id` is exactly 10, leaving no room for our own reference.
- `order.reference_id` <= 40 chars; `payment_note` <= 500; `ticket_name` <= 255; `pickup_details.note` <= 500 and visible to staff, so do not put attribution there [S35, S47, S32].

### 7.2 Recommendation (INFERRED)

- Keep the **full** attribution record in our own database, keyed by our order id (`order_attribution`: utm_*, gclid, wbraid, gbraid, fbclid, fbp, fbc, GA client id/session id, landing URL, referrer, consent state at checkout, timestamps).
- Put only an opaque reference in Square metadata (`dbo_ref` = our order id) and, if useful for reports run in Square, 2 to 3 compact fields (`utm_source`, `utm_medium`, `utm_campaign`). This keeps within the 10-key cap, leaves room for later keys, and avoids storing identifiers Square says not to hold (it prohibits PII; whether advertising identifiers count as PII under Square's terms or Australian privacy law is a legal judgement, not answered in docs; default to not putting them in Square).
- Orders created by POS, phone or walk-in have no attribution; reports should show them as "offline/unattributed".

### 7.3 Closed-loop firing (INFERRED architecture; the GA4, Meta and Google Ads API specifics are outside this slice and were not verified here)

1. Fire conversions only from the **verified-paid** step (E in the sequence), never on the redirect page; use a stable `event_id`/`transaction_id` = our order id so the browser Pixel/gtag event (if any) and the server event de-duplicate.
2. GA4: Measurement Protocol `purchase` with `transaction_id`, `value` (decimal AUD, convert from cents), `currency: "AUD"`, `items`, and the captured GA client id. Meta: Conversions API `Purchase` with the same `event_id`, `fbp`/`fbc`, and hashed email/phone only if consented. Google Ads: offline conversion upload using the stored `gclid` (or enhanced conversions); respect the conversion window.
3. Send refunds/cancellations as negative adjustments or order status updates (Square `refund.*`, `payment.updated` CANCELED) - platform-specific handling to be specified in the analytics slice.
4. Consent: store the consent snapshot with the order; only send identifiers that were consented.
5. Mark every new conversion integration as pending live verification against the platform's own docs.

## 8. Webhooks in detail

### 8.1 Signature verification (OBSERVED + computed)

- Header: `x-square-hmacsha256-signature` (HMAC-SHA256 "generated using the signature key for your webhook subscription, the notification URL for your webhook subscription, and the raw body of the request") [S53].
- Exact algorithm, from the official SDK source: `base64( HMAC_SHA256( key = signatureKey, message = notificationUrl + rawBody ) )` using Node `crypto.createHmac("sha256", key).update(payload, "utf8").digest("base64")`; the SDK then returns `computed === header` [S7 `wrapper/WebhooksHelper.js`, `core/crypto/createHmacOverride.js`].
- **Verified locally**: with signature key `asdf1234`, notification URL `https://example.com/webhook`, body `{"hello":"world"}` that formula yields `2kRE5qRU2tR+tBGlDwMEw2avJ7QM4ikPYD/PJ3bd9Og=`, exactly the signature in Square's own example [S53]. Reversing the concatenation order does not match. (Computed on 2026-10-02 with Node 22; no Square call.)
- **Which URL string**: the notification URL **as configured in the webhook subscription** (scheme, host, path, query exactly), not necessarily the URL the request arrived on. Behind a proxy or CDN, `request.url` may differ; use a constant from configuration [S53, S55].
- **Raw body**: sign over the exact bytes received. In a Next.js Route Handler use `await request.text()` and verify before `JSON.parse`; never re-serialise.
- Constant-time compare (Square: "A timing attack can exploit a signature comparison that stops at the first difference") [S53]; the SDK helper uses `===`, so use:

```ts
import { createHmac, timingSafeEqual } from "node:crypto";
export function verifySquareWebhook(rawBody: string, signatureHeader: string | null, signatureKey: string, notificationUrl: string): boolean {
  if (!signatureHeader) return false;
  const expected = createHmac("sha256", signatureKey).update(notificationUrl + rawBody, "utf8").digest("base64");
  const a = Buffer.from(expected);
  const b = Buffer.from(signatureHeader);
  return a.length === b.length && timingSafeEqual(a, b);
}
```
- One signature key per subscription; if two subscriptions share one listener URL you will fail validation for one of them; use a unique URL per subscription [S55].
- Sandbox and production are separate subscriptions with separate keys; the `square-environment` header says `Production` or `Sandbox` [S52].

### 8.2 Delivery, retries, idempotency

- HTTPS only; must answer **2xx quickly**; Square gives **10 seconds** then retries; typical delivery "well under 60 seconds"; **no delivery-order guarantee** [S51].
- Retries up to **24 hours**: 1 min, 2, 4, 8, 16, 32, 60 min, 2 h, 4 h, 8 h, 8 h (11 attempts); then discarded. Retried deliveries carry `square-retry-number` and `square-retry-reason` (`http_timeout`, `http_error`, `ssl_error`, `other_error`) and `square-initial-delivery-timestamp` [S51, S52].
- Body: `merchant_id`, optional `location_id`, `type`, `event_id` (idempotency UUID), `created_at`, `data { type, id, deleted?, object }` [S52].
- "Webhooks can be sent more than once": dedupe on `event_id` [S51].
- Static IPs (production): `54.245.1.154`, `34.202.99.168`; sandbox: `54.212.177.79`, `107.20.218.8` (published for firewall allowlists; they can change, so prefer signature checks over IP allowlisting) [S51].
- Handler rules: verify -> persist raw event -> 200 -> process async; processing must be idempotent (key on `event_id`, and on `(order_id, version)` for state); out-of-order tolerant (ignore if the stored `Order.version` is newer); always re-fetch the object rather than trusting payload fields; log IDs, not card details.
- Missed events: Events API (28 days, PAT) and reconciliation by SearchOrders [S61].
- Subscriptions: the Webhook Subscriptions API requires the PAT; the API version chosen on the subscription sets the event body shape [S1, S18].

## 9. Data-handling and Australian-law notes

- Square's API terms require explicit customer permission before you save customer contact information; breaches can get the application disabled [S71].
- PII: do not store PII or card data in Square metadata [S27]; do not keep PII in the Sandbox [S69].
- This brief does not interpret the Privacy Act 1988, Spam Act 2003 or the Fair Work Act; those need their own sourced review. The ATO page for GST could not be retrieved (HTTP 403 from www.ato.gov.au, not a proxy denial), so no GST figure is asserted here.

## 10. Smoke-test checklist: MUST be verified live once credentials exist

Prerequisites: Elie approves real-money tests (one small real order per store, refunded after); Square for Restaurants Plus/Premium or KDS subscription active; a KDS device signed in at each store; sandbox app plus AU sandbox test account for pre-checks. Mark each item PASS/FAIL with evidence (API response IDs, screenshots).

Authentication and platform
1. OAuth code flow: authorise, then mint a reduced-scope `storefront` token with `scopes`; confirm a call outside scope returns 403 `INSUFFICIENT_SCOPES`; confirm `RetrieveTokenStatus` shows expected scopes and expiry (30 days); confirm refresh works and alerts fire at 8 days.
2. `GET /v2/locations`: record the three location IDs, `timezone`, `currency` (expect `AUD`), `status`, `business_hours` (cross-midnight behaviour, holidays), `capabilities`.
3. `GET /v2/catalog/info`: record batch/search limits; confirm `ListCatalog` with explicit `types` returns variations, modifiers, images; compare counts with the Dashboard item library.

Catalogue behaviour
4. Presence per location: item present at one store only; confirm the resolution rule (item, variation, modifier level), `location_overrides` price per store, and that an order at store X is charged store X's price. Confirm `sold_out` appears when inventory hits zero.
5. Modifier min/max: try an order violating min/max on `CatalogItemModifierListInfo`; record whether Square rejects it. Check nested modifiers (Beta) and `hidden_online` handling.
6. Dietary/ingredient fields: confirm `food_and_beverage_details` round-trips for a FOOD_AND_BEV item; confirm which fields the team can edit in the Dashboard.
7. Tax: confirm the AU GST configuration (INCLUSIVE catalogue tax) produces `total_money` equal to the displayed price with no extra GST; confirm `CalculateOrder` matches the final order total to the cent.

Orders and payment link (the make-or-break set)
8. Create a payment link whose `order` carries a PICKUP fulfilment (`SCHEDULED` with `pickup_at` and `prep_time_duration`, and also `ASAP`); confirm the fulfilment survives, the order state before payment (DRAFT or OPEN), and `redirect_url` handling.
9. Pay the link with a real card (small amount): does the order appear in the **Orders app and on the KDS**? Record: time to appear, which tab, whether it prints to the kitchen printer, whether a SCHEDULED order waits until `pickup_at - prep_time_duration`, whether the KDS toggle "View online, kiosk and delayed fulfilment orders" is what governs it, whether `ticket_name`, `kitchen_name` and `pickup_details.note` print. Confirm an **unpaid** link/order does not appear.
10. After payment: capture the order state, `tenders`, `version`, `customer_id` (is it null?), `Payment.customer_id`, buyer email/phone landing in the recipient, and whether metadata/`reference_id` are intact.
11. Status round-trip: change the fulfilment via KDS Expo, KDS Prep, and the Orders app (accept, ready, complete). Record which webhooks fire (`order.fulfillment.updated`? `order.updated`?) and what `RetrieveOrder` shows. This decides whether we rely on webhooks or polling.
12. Closure: call UpdateOrder with `state: COMPLETED` and the fulfilment `COMPLETED` together on a paid payment-link order; confirm it succeeds and the order leaves the Active list; confirm that completing only the fulfilment hides the order while still OPEN.
13. Cancel/abandon: create a link, do not pay, DeletePaymentLink; confirm order CANCELED and nothing reaches the KDS; test delete-while-open-in-browser; test whether the link self-expires (leave one unpaid for 24 hours and 7 days; record outcome).
14. Redirect: confirm which query parameters Square appends in production (use a $0 link or the small real order); confirm our confirmation page works with no parameters.
15. Idempotency: replay CreatePaymentLink with the same key (same body) and with a changed body; record both responses; confirm CreatePayment key length limit (45) if the Web Payments SDK path is built.
16. Wallets/BNPL: Apple Pay (Safari/iOS), Google Pay, Afterpay (eligibility, A$1 to A$2,000) on the hosted page in production; confirm `accepted_payment_methods` behaviour and the seller's Dashboard Afterpay setting; record default tip behaviour in AU.
17. Refund: refund the test payment; confirm `refund.*` and `payment.updated` events and the order state.
18. Fallback test (only if 9 fails): CreateOrder (PICKUP) + Web Payments SDK token + CreatePayment (order_id, total match, `autocomplete: true`); confirm KDS visibility and that no `delay_capture` flag is needed (settles the S26 conflict).

Webhooks
19. Signature: send Square's test event from the Developer Console and validate with the section 8.1 function in sandbox and production; confirm the notification URL string that matches (with and without trailing slash and query) behind the real host/proxy.
20. Duplicate and out-of-order delivery: force a slow 500 response; confirm retries carry `square-retry-*` headers and `event_id` dedupe works; replay via the Events API (`EnableEvents` first).
21. `catalog.version.updated`: edit an item price in the Dashboard; confirm the event arrives, `SearchCatalogObjects(begin_time=latest_time)` returns the change, a bulk edit produces a burst you can debounce, and deleted objects appear with `include_deleted_objects`.

Customers, loyalty, labour, other
22. Customers: does passing `order.customer_id` on a payment-link order stick; does an instant profile get created; confirm SearchCustomers de-dupe by phone/email; confirm `email_unsubscribed` visibility.
23. Loyalty (if the seller subscribes): create account (E.164 phone), do points accrue automatically on a paid payment-link order, or is `AccumulateLoyaltyPoints` needed; verify after-tax accrual.
24. Labour: with a real clock-in/out at a store, confirm `labor.timecard.*` events, `SearchTimecards` fields (wage/hourly_rate populated? breaks?), team wages visibility with `EMPLOYEES_READ`, and that an unset `wage.hourly_rate` yields zero cost; confirm whether Labor/Team need a paid Square Team plan in AU for API writes.
25. Invoices (when catering work starts): deposit (`percentage_requested`) + balance invoice by EMAIL; confirm `invoice.payment_made`; confirm INSTALLMENT is blocked without Invoices Plus.
26. Reporting: compare SearchOrders (closed, COMPLETED, last 24 h) with the Dashboard Sales Summary; try the Reporting API `/v1/meta` (AU availability).
27. Rate limits: run a controlled burst of catalogue/orders reads and record any 429s; confirm SDK retry/backoff behaviour with `maxRetries`.

## 11. UNKNOWNs for Elie to check in the Square Dashboard or with Square support

- Whether Square payment links expire on their own, and the exact lifetime; whether links created with a `PICKUP` order show differently on the hosted page.
- Exact rate limits (requests per second per endpoint/application).
- Whether an API-created PICKUP order is routed by the KDS toggle "online, kiosk and delayed fulfilment orders" or by "point of sale orders", and whether the source name matters.
- AU availability: Reporting API, nested modifiers (Beta), inventory cost/vendor Beta, Square Payroll, Gift Cards API formal list, estimates plan requirement, Shifts Plus effect on API scheduling.
- Square AU pricing for payment links/online payments, KDS and Restaurants plans (the developer pricing page is dated).
- Which SAQ the business falls into when using hosted checkout versus the Web Payments SDK.
- Whether GST is configured as an inclusive catalogue tax at all three stores, and the GST treatment of the Square checkout receipt (receipt/tax-invoice wording was not researched).
- Allergen and dietary labelling compliance approach (Food Standards Code); Square's fields are descriptive only.

## 12. Source register (all retrieved 2026-10-02 unless noted)

Square developer docs (https://developer.squareup.com):
- S1 /docs/build-basics/access-tokens
- S2 /docs/oauth-api/square-permissions
- S3 /docs/oauth-api/overview
- S4 /docs/oauth-api/best-practices
- S5 /docs/oauth-api/refresh-revoke-limit-scope
- S9 /docs/build-basics/versioning-overview
- S10 /docs/changelog/connect and /docs/changelog/connect-logs/2026-09-16, 2026-08-19, 2026-07-15, 2026-05-20, 2026-04-21, 2026-01-22
- S11 /docs/build-basics/common-api-patterns/idempotency
- S12 /docs/build-basics/common-api-patterns/pagination
- S13 /docs/build-basics/handling-errors
- S14 /docs/build-basics/working-with-monetary-amounts
- S15 /docs/catalog-api/design-a-catalog
- S16 /docs/catalog-api/retrieve-catalog-objects
- S17 /docs/catalog-api/search-catalog-items
- S18 /docs/catalog-api/webhooks
- S19 /docs/catalog-api/sync-with-external-system
- S20 /docs/catalog-api/manage-menus
- S21 /reference/square/catalog-api/list-catalog
- S22 /reference/square/catalog-api/search-catalog-objects
- S23 /reference/square/catalog-api/batch-retrieve-catalog-objects
- S24 /docs/orders-api/create-orders
- S25 /docs/orders-api/fulfillments
- S26 /docs/orders-api/how-it-works
- S27 /docs/orders-api/metadata
- S28 /docs/orders-api/apply-taxes-and-discounts
- S29 /docs/orders-api/manage-orders/search-orders
- S30 /docs/orders-api/pay-for-orders
- S31 /docs/orders-api/order-ahead-usecase
- S32 /reference/square/objects/FulfillmentPickupDetails
- S33 /reference/square/objects/FulfillmentRecipient
- S34 /reference/square/objects/Fulfillment
- S35 /reference/square/objects/Order
- S36 /reference/square/orders-api/search-orders
- S37 /reference/square/orders-api/create-order
- S38 /reference/square/orders-api/pay-order
- S39 /docs/checkout-api/what-it-does
- S40 /docs/checkout-api/quick-pay-checkout
- S41 /docs/checkout-api/square-order-checkout
- S42 /docs/checkout-api/optional-checkout-configurations
- S43 /docs/checkout-api/guidelines-and-limitations
- S44 /docs/checkout-api/common-pitfalls
- S45 /docs/checkout-api/manage-checkout
- S46 /docs/checkout-api/checkout-settings
- S47 /reference/square/checkout-api/create-payment-link
- S48 /reference/square/objects/PaymentLink
- S49 /reference/square/objects/CheckoutOptions
- S50 /reference/square/checkout-api/delete-payment-link
- S51 /docs/webhooks/overview
- S52 /docs/webhooks/step2subscribe
- S53 /docs/webhooks/step3validate
- S54 /docs/webhooks/v2webhook-events-tech-ref
- S55 /docs/webhooks/troubleshooting
- S56 /reference/square/payments-api/webhooks/payment.updated
- S57 /reference/square/orders-api/webhooks/order.created
- S58 /reference/square/orders-api/webhooks/order.updated
- S59 /reference/square/orders-api/webhooks/order.fulfillment.updated
- S60 /reference/square/labor-api/webhooks/labor.timecard.updated
- S61 /docs/events-api/overview
- S62 /docs/payments-api/take-payments
- S63 /reference/square/payments-api/create-payment
- S64 /docs/online-payment-options
- S65 /docs/web-payments/overview
- S66 /docs/payment-card-support-by-country
- S67 /docs/payment-minimums
- S68 /docs/payments-pricing
- S69 /docs/devtools/sandbox/overview
- S70 /docs/devtools/sandbox/payments
- S71 /docs/customers-api/what-it-does
- S72 /docs/loyalty-api/overview
- S73 /docs/labor-api/what-it-does
- S74 /docs/labor-api/how-it-works
- S75 /docs/labor-api/build-with-labor
- S76 /docs/labor-api/scheduling
- S77 /docs/labor-api/troubleshooting
- S78 /docs/team/overview
- S79 /reference/square/labor-api/search-timecards
- S80 /reference/square/team-api/search-team-members
- S81 /docs/invoices-api/overview
- S82 /docs/gift-cards/using-gift-cards-api
- S83 /docs/inventory-api/what-it-does
- S84 /docs/reporting-api/overview

Official SDK:
- S6 https://registry.npmjs.org/square (dist-tags `latest` = 46.0.0; `time["46.0.0"]` = 2026-09-15T21:05:48.844Z; `engines.node` >= 18.0.0)
- S7 https://registry.npmjs.org/square/-/square-46.0.0.tgz, files read: `README.md`, `package.json`, `BaseClient.js`, `BaseClient.d.ts`, `version.js`, `environments.d.ts`, `auth/BearerAuthProvider.{js,d.ts}`, `wrapper/WebhooksHelper.js`, `core/crypto/createHmacOverride.js`, `errors/SquareError.d.ts`, `serialization/types/Money.js`, `core/schemas/builders/bigint`, `api/types/*.d.ts` (Location, BusinessHours*, CatalogObjectBase, CatalogItem, CatalogItemVariation, CatalogModifier*, CatalogItemFoodAndBeverageDetails*, CatalogDiscount, CatalogPricingRule, CatalogTax, CatalogInfoResponseLimits, Order*, Fulfillment*, PaymentLink, CheckoutOptions, AcceptedPaymentMethods, Customer, CustomerPreferences, Timecard, TimecardWage, Break, TimecardFilter, ScheduledShift, TeamMember, WageSetting, JobAssignment, InvoicePaymentRequest), `api/resources/*/client/Client.d.ts`, `reference.md`
- S8 https://github.com/square/square-nodejs-sdk (README, via the fetch tool; matches the package README)

Square AU support centre (https://squareup.com/help/au/en/article):
- S85 /7944-get-started-with-square-kds-android
- S86 /7959-route-orders-with-your-kds
- S87 /8103-troubleshoot-missing-orders-with-square-kds
- S88 /7215-create-an-estimate-online (via the fetch tool summary)

Square Developer Forums (https://developer.squareup.com/forums/t/, community plus Square staff replies; not documentation):
- F1 orders-api-delivery-fulfilments-created-successfully-but-not-appearing-in-orders-manager-or-square-kds/26705 (July 2026)
- F2 how-does-fulfillments-of-orders-from-restaurant-pos-interact-with-square-kds/25858 (April 2026)
- F3 square-kds-order-fulfillment-status-changes/6798 (2022 to December 2025)
- F4 trying-to-set-up-api-to-connect-with-square-kds-expeditor/14007 (2024)
- F5 orders-with-fulfillment-dashboard-behavior/18972 (September 2024)
- F6 creation-of-order-for-pos-via-api/20857 (January 2025; reply attributed to an AI assistant reviewed by staff)
- F7 grabbing-the-orderid-parameter-from-a-sandbox-create-payment-link-flow/18635 (2024)
- F8 square-parameters-are-not-being-added-to-checkout-redirect-url/20871 (January 2025)

Not retrievable: https://www.ato.gov.au/businesses-and-organisations/gst-excise-and-indirect-taxes/gst returned HTTP 403 to the fetch tool; https://squareup.com/au/en/payroll and https://squareup.com/au/en/staff returned HTTP 429 (rate limited). The proxy status endpoint showed no connect rejection for those hosts, so these were origin responses, not network-policy denials. The network-policy documentation was consulted once; the only policy-denied hosts seen in the proxy log were unrelated competitor/directory sites for other slices.
