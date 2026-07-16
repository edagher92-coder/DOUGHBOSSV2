# ADR: order lifecycle, timing and capacity

**Status:** proposed; Stage 0 initiated by architecture review

**Date:** 14 July 2026
**Scope:** Revesby pickup-first customer ordering and staff kitchen workflow

## Context

The supplied lifecycle memo proposes customer-selected 30-minute slots, per-item preparation time, `max(prep time)` as the order duration, and KDS states including queued, fire now, cooking, ready and late. The store has one oven and usually one or two staff.

The business goal is correct: promise a time the kitchen can keep, tell staff when to start, prevent cold food and notify the customer at useful moments. The proposed calculation is not sufficient because it assumes items can all be prepared and cooked in parallel.

## Decision

Use four separate models:

1. **Checkout/payment:** an immutable checkout attempt, capacity hold and provider payment attempt.
2. **Fulfilment truth:** persisted order state and promised time window.
3. **Kitchen projection:** calculated queue/fire/late/stale warnings.
4. **Customer projection:** simple language derived from fulfilment truth.

### Persisted fulfilment states

`scheduled -> cooking -> ready -> completed`

`cancelled` is an exception from a permitted non-terminal state. Manager overrides require a reason, actor and timestamp. Legacy `pending/confirmed/preparing/baking` may be mapped during a migration, but new code must not add more overlapping meanings.

### Derived staff projections

- `queued`: capacity allocated, fire time not reached.
- `fire_due`: current time is at/after calculated start and cooking has not begun.
- `late`: the predicted ready time exceeds the promise or the promise start has passed without ready.
- `stale_ready`: ready was marked but collection has not happened within the configured threshold.

These values are calculated. They are not order states and do not emit lifecycle events merely because time passes.

### Customer projection

- `confirmed`: paid/accepted and capacity reserved.
- `preparing`: staff started cooking.
- `ready_for_pickup`: staff marked ready.
- `collected`: completed.
- `cancelled`: cancelled with refund/payment explanation.

## Time promise

Prefer a 15-minute ready window. Display: **Ready from 6:15 pm; please collect by 6:30 pm**.

If the owner retains 30-minute selection, calculate and show a narrower ready/collect window within it. Never interpret the slot start as both “begin preparing” and “customer collection begins”.

All calculations use the location's IANA timezone and store UTC timestamps. Opening hours, exceptions and blackout dates are location-owned.

## Capacity allocation

Each item version records:

- preparation seconds;
- oven seconds;
- preparation station;
- preparation capacity units;
- oven/tray capacity units;
- batch key and maximum batch size;
- finishing/packing seconds;
- modifier deltas.

The capacity engine expands an order into resource demand and finds a feasible schedule before offering a customer window. Existing confirmed allocations and unexpired holds consume capacity.

Suggested additive tables:

- `doughboss_checkout_attempts`: unique idempotency key, cart hash, customer/location/type, immutable snapshot, state, expiry.
- `doughboss_capacity_holds`: location, checkout attempt, resource interval, units, expiry, converted timestamp.
- `doughboss_capacity_allocations`: order, resource interval, units, planning version.
- `doughboss_order_events`: order, previous/new state, event type, actor, reason, unique event key, UTC timestamp.
- `doughboss_notification_outbox`: event key, channel, destination reference, template, payload, attempts, status.
- `doughboss_payment_attempts`: checkout/order, provider, provider reference, amount/currency, normalised/provider state, idempotency key.

Add `version`, `promised_ready_from_utc`, `promised_ready_by_utc`, `cooking_started_at`, `ready_at`, `completed_at`, `cancelled_at`, `planning_version` and timezone snapshot fields to orders through an online-safe migration.

## Concurrency rules

- A slot response is advisory until an expiring capacity hold succeeds.
- Hold creation is atomic and fails when any resource interval would exceed capacity.
- Payment begins only after the hold exists.
- Payment success converts the hold to an allocation exactly once.
- Expired holds release capacity.
- State transitions use compare-and-set with the current `version`; a stale staff screen receives a conflict and refreshes.
- Each transition and notification event has a unique idempotency key.

## Payment and acceptance

Recommended flow for online pickup:

1. Customer configures items.
2. Server prices the cart and offers capacity-backed windows.
3. Customer selects a window and the server creates a short hold.
4. Customer pays through the selected provider.
5. Verified payment converts hold to allocation and creates/confirms the order atomically.
6. Staff receives a new-order alert and acknowledges it.

If manual staff acceptance remains mandatory, use authorise-then-capture where the provider supports it, with a strict acceptance timeout and automatic void on rejection/expiry.

## Staff controls

- **Acknowledge:** staff saw the order; no customer-state change.
- **Start cooking:** moves scheduled to cooking and sends the optional preparing update.
- **Mark ready:** moves cooking to ready and sends the ready notification.
- **Complete/collected:** moves ready to completed.
- **Cancel:** requires allowed source state, reason and payment/refund handling.
- **Manager override:** reason-coded transition outside the normal path; always audited.

No automatic ready button, countdown or escalation may change order truth. An overdue order escalates visually and audibly until staff acts.

## Notification rules

- Confirmation is sent once after the order is durable.
- Preparing is sent once only when enabled and cooking begins.
- Ready is sent once only after an explicit staff action.
- Cancellation/refund status is sent once per material change.
- Channel delivery is asynchronous and retried through an outbox.
- Templates include order number, Revesby, ready/collect window, contact method and allergy disclaimer where approved.

## Payment interface

The order model calls a provider-neutral gateway. Provider adapters normalise Stripe, Tyro or PayPal states into DoughBoss payment states. Orders never interpret raw provider statuses.

Tyro and Stripe both remain valid options. No Tyro UI or public slot picker is enabled until sandbox credentials, webhooks, refunds and idempotency tests pass.

## Rollout and gates

1. Add tables/columns behind disabled feature flags; migration/rollback and privacy coverage tests.
2. Add transition service, event log and notification outbox; retain current UI.
3. Wrap Stripe in the gateway contract and add payment-attempt reconciliation.
4. Configure real Revesby hours/resources/items and run a shadow scheduler against real order history.
5. Add customer slot picker and capacity hold; load/concurrency test.
6. Add incremental KDS projections and staff controls; run kitchen simulation.
7. Enable for supervised Revesby orders; monitor promises, late rate and ready-to-collect time.

## Acceptance criteria

- Two customers cannot hold or confirm capacity above a resource limit.
- Large mixed orders account for sequential preparation and oven batches.
- A stale staff client cannot overwrite a newer transition.
- No clock-based process marks an order ready.
- Provider success with browser loss is reconciled without a second charge.
- Notifications are sent at most once per event and failure does not undo the order.
- Daylight-saving/timezone tests use the location timezone.
- Paused/closed/blackout periods offer no slots.
- Manager overrides and cancellations identify actor and reason.
- Measured promised-ready accuracy is reviewed before expanding beyond Revesby.
