# Square connection and migration preparation — 8 September 2026

## Owner request and current boundary

The owner requested a Square integration link while preparing to transfer in-store and online payments, POS and reporting to Square. This authorizes preparation and implementation planning, not live payment activation, merchant-account creation, new credential grants, a POSPal cutover, or real transactions. The merchant account, Square locations, developer application and pilot hardware remain unconfirmed. An owner question is outstanding; never ask for secrets in chat.

## Useful setup links — not a completed connection

- [Existing DoughBoss payment settings](https://doughboss.com.au/wp-admin/admin.php?page=doughboss-settings): the Square section already contains separate Sandbox/Live application and location fields, write-only token fields, webhook configuration and an explicit live-approval gate. Merely opening it does not select Square or enable payments.
- [Square Developer Console](https://developer.squareup.com/apps): the official console linked from Square's credential documentation. Sign in or create the application through the owner-controlled flow; do not expose its credentials in screenshots, prompts or source control.
- [Square for Restaurants Australia](https://squareup.com/au/en/point-of-sale/restaurants): merchant/POS setup information, not the DoughBoss application's authorization callback.

A genuine **Connect Square** button would start the registered application's authorization flow. The current plugin has no OAuth callback, token exchange/refresh, revocation handling or account-connection UI. No working authorization URL can be claimed until the actual application and callback are configured and the flow is implemented and tested.

Square distinguishes own-account personal access tokens from scoped OAuth tokens for applications accessing seller accounts. Multiple locations in one merchant account do not by themselves establish multiple sellers. Decide the account/ownership model first; do not add a broad token or OAuth framework speculatively. Sources: [credential types and intended uses](https://developer.squareup.com/docs/build-basics/access-tokens), [OAuth flow and HTTPS callback requirements](https://developer.squareup.com/docs/oauth-api/overview), accessed 8 September 2026.

## Verified implementation versus missing integration

| Area | Existing source evidence | Still required |
| --- | --- | --- |
| Online card payment | `includes/class-doughboss-square.php`: Web Payments SDK identifiers, Payments API create/retrieve/refund, immutable attempts and recovery. | Owner-provisioned sandbox setup and independently approved provider acceptance. |
| Payment location | `DoughBoss_Settings::square_location_id()` chooses one global Square location per mode. | Explicit WordPress installation/location → Square merchant/location mapping before serving multiple shops. Do not map every shop to Revesby by default. |
| Line-item orders and POS | The `POST /v2/payments` body has amount, currency, location and attempt reference; no itemized Square `order_id`, catalog IDs or fulfillment details. | Catalog/variation/modifier mapping and a durable Orders/Payments association with fulfillment state. Payment success alone is not a kitchen order. |
| Kitchen delivery | Existing WordPress POSPal path remains independent. | One designated kitchen owner, verified station/printer/KDS routing and duplicate prevention during transition. |
| Reporting | Payment references are retained locally; no verified consolidated Square reporting composition. | Reconcile source, store, channel, payment, refund and settlement identities; define a business-day cutoff and freshness states. |
| Account connection | Write-only manual token fields with environment-first secret lookup. | Either an explicitly approved own-account setup or a complete scoped OAuth connection lifecycle; no fake connected badge. |

Square's Orders API can attach purchased items and fulfillment data, associate payments, and surface fully paid fulfillment orders in POS and Order Manager. That is the missing itemized-order integration here; it does not by itself prove a particular kitchen device received a ticket. See [Orders API overview and POS visibility](https://developer.squareup.com/docs/orders-api/what-it-does), accessed 8 September 2026. Device acceptance is separate from API acceptance.

## Owner facts needed before connection

1. Does DoughBoss already have a Square merchant account, and is this one merchant with several locations or more than one merchant entity? No account email or secret is needed in this handoff.
2. Which Square location is the pilot, and which WordPress installation/native location does it represent? Currently the public DoughBoss site is single-location pickup, native location `1`; the corresponding Square identity is unconfirmed.
3. Does the owner already have a Square Developer application? Confirm its public application identity and sandbox/live environment through a secure configuration workflow, not by pasting tokens.
4. Which POS device/app and kitchen target will be used? Confirm hardware, stations and printer/KDS choice before relying on routing assumptions.
5. Who owns the catalog, pricing/modifier changes, taxes, discounts, reporting and the cutover decision? Resolve authority once rather than creating two competing writers.

## Bounded implementation sequence and exit gates

### 1. Connection and one-shop binding

Use the existing plugin architecture and payment-off defaults. Define the account connection contract, exact allowed origin/callback, minimum verified permissions, server-only secret storage, expiration/refresh/revocation behavior and explicit owner consent. Keep connection readiness independent from permission to charge. For OAuth, validate state, actor and installation binding before exchanging a one-use authorization code; never expose tokens to the browser or SamOS UI.

Exit: one real sandbox account and location are bound to the intended installation/location; wrong environment, wrong shop, expired/revoked permission and incomplete configuration fail closed. Local tests pass first. Any provider sandbox call is a separate approved batch using synthetic customer details.

### 2. Itemized order, payment and kitchen pilot

Map real Square catalog variations and modifiers to the canonical server-owned menu. Define durable order/payment identities and reconciliation for timeouts, retries, duplicate webhooks and refunds. WordPress and Square cannot share one database transaction: require explicit recovery for a Square order/payment whose local commit is incomplete. Do not announce an accepted order or print twice from competing POSPal and Square paths.

Exit: authorized sandbox evidence covers success, decline, unknown outcome, duplicate requests/events, amount/currency/location mismatch, browser abandonment and refund reconciliation. Verify exact items/modifiers and pickup details in Square Order Manager. Physical device/KDS acceptance and any real transaction require a staffed, separately approved pilot; none is authorized or performed by this preparation.

### 3. Reporting and controlled rollout

Keep historical Stripe/POSPal references and unresolved attempts reconcilable before changing gateway ownership. Agree the reporting source of truth and show per-location totals with channel, refund and settlement distinctions; reconcile against Square's authoritative reports. Add other locations only after their identities and access grants are verified. A payment gateway switch is not a historical-data migration.

Exit: one shop's payment, refund, order and kitchen evidence reconciles; outstanding attempts are resolved; rollback and staffing responsibilities are recorded; the owner approves a defined cutover window. No automatic enablement, dual kitchen dispatch or inferred all-store readiness.

## SamOS handoff — existing code, still disconnected

Read-only discovery located `snowflow-ask-sam` at `C:\Users\edagh\Documents\Codex\2026-07-06\sync-all-files-on-codex-across\github\edagher92-coder\02_Public_Repos\snowflow-ask-sam`, branch `feat/sam-os-finish-20260908`, base commit `096f2db3d680b4f619a82d23b0a3c5554f4873bd`. Its worktree has unrelated changes and was not edited.

`business-registry.js` declares DoughBoss a separate business workspace with installation setup required and a read-only, not-connected adapter. `doughboss-read-adapter.js` already validates origin/location, minimizes operational data and produces timestamped, receipted projections. It first requires `/auth/me`, but its transport omits credentials and only supplies an Accept header. There is no production caller composing it with an approved server-side read identity. The typed platform client currently targets the existing Snow Flow platform routes, not this projection.

The next SamOS slice must reuse that adapter behind an approved server-owned identity and endpoint, with installation/location grants and synthetic integration tests. Do not put Square merchant secrets in that UI, duplicate its topology in WordPress, or merge DoughBoss into another business workspace. Broader Square financial reporting needs its own explicit authorized projection; the current adapter marks reports unavailable.

## Advisory provenance and limits

One guarded Ollama `architecture` call used `kimi-k3:cloud` (preferred model), `synthetic` data only. Its useful recommendations were the payment-versus-POS distinction, owner-confirmed account/location facts and a one-shop pilot. The lead rejected an assumed WooCommerce architecture, speculative settings-schema fields, blanket payment-toggle gating for read-only reporting, and an impossible atomic commit across WordPress and Square. An unapproved real-payment test was not adopted. No credentials, customer data, account identifiers or private files were sent.

The accepted Astra design brief remains the visual direction; this is operational preparation, not another redesign. GPT-5.6 implementation/verification remains the normal route, with Ollama advisory work batched and inspected rather than treated as deployment authority. Provider cost is unknown; no claim of free or unlimited calls is made.
