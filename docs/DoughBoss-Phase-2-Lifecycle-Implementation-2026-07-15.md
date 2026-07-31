# DoughBoss Phase 2 — Order Lifecycle Foundation

Date: 15 July 2026
Status: implemented on the architecture-review branch; production rollout not yet authorised

## Plain-English outcome

The order system now has one authoritative path for moving an order through the kitchen. A stale tablet can no longer overwrite a newer update, an order cannot jump from Pending straight to Completed, and a completed or cancelled order cannot be reopened accidentally.

Each successful change receives a new version number, lifecycle timestamp and non-customer-data audit event. The kitchen board, staff console and WordPress order screen send the version they actually saw. If another screen has already moved the order, the losing screen is told to refresh.

Customer tracking now translates internal kitchen wording into customer wording and shows an absolute staff-estimated ready window only after staff accept the order with an ETA. The display is informational: elapsed time never changes the real order status.

## Authoritative transition graph

```text
Pending
  ├─> Confirmed ─> Preparing ─> Baking ─> Ready ─> Completed
  │                         └────────────> Ready
  └─> Cancelled

Confirmed / Preparing / Baking may be cancelled when the order is not paid.
Paid orders must be refunded before cancellation.
Delivery orders may move Ready -> Out for delivery -> Completed.
Completed and Cancelled are terminal.
```

The current database status names remain unchanged so the POSPal mirror, reports, SMS listener, printer and existing order history stay compatible. The storefront receives a separate customer projection:

| Internal state | Customer state | Customer wording |
|---|---|---|
| `pending` | `received` | Order received — waiting for the shop to accept |
| `confirmed` | `confirmed` | Accepted by the shop |
| `preparing`, `baking` | `preparing` | Being prepared |
| `ready` pickup | `ready_for_pickup` | Ready for pickup |
| `ready` delivery | `ready_for_delivery` | Ready for delivery |
| `out_for_delivery` | `out_for_delivery` | On its way |
| `completed` pickup | `collected` | Collected |
| `completed` delivery | `delivered` | Delivered |
| `cancelled` | `cancelled` | Cancelled |

## Data changes

Database contract version increases from `1.10.0` to `1.11.0`.

Orders gain:

- optimistic `version`;
- `status_changed_at`;
- `promised_ready_from_utc` and `promised_ready_by_utc`;
- IANA `timezone_snapshot` when the WordPress timezone is valid;
- `cooking_started_at`, `ready_at`, `completed_at`, and `cancelled_at`.

The new `doughboss_order_events` table stores the order/version, from/to states, controlled actor/reason fields, unique idempotency key and UTC occurrence time. It deliberately does not store customer names, contact details, addresses, free-text notes, card data, POSPal payloads or arbitrary metadata.

Historical timestamps and events are not invented during migration. Existing orders keep their status and values; their durable audit trail starts with the first post-upgrade change.

The migration verifies that Orders, Order Items and Order Events exist and use InnoDB before recording version `1.11.0`. If that invariant is not met, checkout and staff transitions fail closed with a temporary-unavailable response and owners receive a visible administration notice; the code does not silently convert a potentially large production table during a web request.

## Timing rules

When staff accept an order with an ETA:

1. `accepted_at` is recorded in UTC.
2. The staff estimate begins at acceptance time plus the selected ETA.
3. The estimate window ends 15 minutes later.
4. Screens render the UTC values in the stored shop timezone where available.

The words are intentionally “Staff estimate”, not a guaranteed promise. If its end passes before Ready, staff see “Estimate passed — check this order”. No timer or scheduled job can mark an order Ready.

## Concurrency and idempotency

Every status/accept request includes the order version the screen saw and a unique event key. The server validates the permitted edge, conditionally updates the matching version, inserts the event in the same transaction, commits, then fires realtime/notification hooks.

Zero updated rows are a conflict, never success. Replaying the same successful event key returns the existing result without another event, hook or ready SMS. If event insertion fails, the status and version roll back. A stateful test covers this failure.

## Staff and customer changes

- Kitchen board actions come from `allowed_next_statuses` returned by the server.
- The standalone staff console uses the same contract and replaces its free-form ETA prompt with 10/15/20/30-minute controls.
- WordPress order history shows only the current state and legal next states.
- Kitchen-only accounts cannot cancel orders; cancellation is manager-only and requires a controlled reason code.
- Paid cancellation is withheld until refund state is recorded.
- Customer tracking uses customer wording, payment wording, ready collection cues, staff estimate windows and a last-checked time.
- The whole kitchen board is no longer an ARIA live region; the compact status message remains live.
- Repeated action buttons now have order-specific accessible names.
- Confirmation email copy no longer promises ongoing notifications that the current best-effort notification system cannot guarantee.

## Payments remain open by design

No Tyro or Stripe provider choice was activated in this phase. The existing optional Stripe path remains dormant unless configured. Tyro can be added behind a future payment-provider interface. Order lifecycle state and payment state remain separate so a processor outage or refund issue cannot silently rewrite kitchen truth.

Before enabling online payment in production, decide and document:

1. Stripe only, Tyro only, or a provider adapter supporting both.
2. Authorise/capture timing.
3. Cancellation/refund ownership and failure handling.
4. Webhook idempotency and reconciliation.
5. Staff presentation for paid, disputed, refund-pending and refunded states.

## Verification completed

- PHP syntax checks for modified PHP files under PHP 8.3.
- JavaScript syntax checks for the kitchen board, storefront and staff console.
- WordPress boot smoke suite: 119 passed, 0 failed.
- Stateful lifecycle transaction suite: 16 passed, 0 failed.
- Tests cover allowed/forbidden edges, terminal states, customer projection, stale-version conflict, idempotent replay, paid-cancellation gate, timestamps and rollback when event insertion fails.

## Production gates still required

This slice is not production-live until all gates below are completed:

1. Run the `1.10.0 -> 1.11.0` migration against a recent sanitised database copy.
2. Confirm Orders, Order Items and Order Events use InnoDB; atomicity depends on transactional tables.
3. Take a database backup and rehearse rollback.
4. Test two real tablets updating the same order.
5. Verify POSPal, printer, email, optional SMS and Mercure listeners after commit.
6. Test Sydney daylight-saving formatting with WordPress timezone `Australia/Sydney`.
7. Confirm owner policy for cancellation reasons and paid-order refunds.

The exact backup, migration, preservation, rollback and evidence procedure is
recorded in `docs/DoughBoss-Phase-2-Staging-Rehearsal-Runbook.md`. Automated
MariaDB CI is supporting evidence only; it does not complete this manual gate.
8. Add a durable notification outbox before promising reliable status messages.

## Recommended next slice

Build capacity-aware ordering without connecting payments yet:

- store opening hours, blackout periods and pickup-slot capacity;
- server-authoritative available-slot API;
- customer selection of an offered pickup window;
- staff queue ordered by fire time and promised window;
- explicit manager override with controlled reason codes;
- migration/integration tests against MariaDB;
- notification outbox and delivery status.

Only after the capacity model has been observed with real kitchen data should online payment capture be enabled.
