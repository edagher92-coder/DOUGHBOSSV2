# DoughBoss Phase 3 — Capacity Scheduling Foundation

Date: 15 July 2026  
Status: implemented in draft PR #23; disabled by default; not authorised for customer enforcement

## Plain-English outcome

DoughBoss can now calculate honest, location-owned pickup windows in the shop's timezone and reserve a materialised window without letting two simultaneous requests take the final capacity.

The first planning model is deliberately conservative and easy to measure: each pickup window has both a maximum number of orders and a maximum number of item units. An item unit currently means one cart quantity. It is not presented as prep minutes, oven space or a full kitchen optimiser.

Nothing in this phase changes live customer checkout or activates Stripe or Tyro. New and upgraded shops default to `off`. Managers can select only `off` or `shadow`; the customer-enforced state is intentionally unavailable until the real database race and checkout-conversion gates pass.

## What is implemented

### Deterministic window engine

The planning engine:

- uses an explicit IANA timezone per location, normally `Australia/Sydney`;
- stores and returns UTC instants while keeping wall-clock labels for display only;
- supports multiple service periods per weekday;
- supports configurable window length, minimum notice and booking horizon;
- excludes closed days and blackout dates;
- rejects invalid timezones, malformed or overlapping hours, invalid demand and invalid capacity;
- preserves unique slot identifiers across daylight-saving repeated hours;
- omits wall times that do not exist during the daylight-saving spring jump;
- marks a window full when server-computed demand exceeds remaining item-unit capacity.

### Durable storage

Database contract `1.12.0` adds:

- location timezone, rollout mode, window/notice/horizon/hold settings, conservative order/item limits and planning version;
- order capacity-hold reference, item units, fire-time projection and planning version;
- weekly location hours;
- dated schedule exceptions;
- materialised capacity slots;
- transactional capacity holds.

All tables participating in scheduling are required to use InnoDB. Migration does not record `1.12.0` unless the required tables and unique mutex/idempotency indexes exist.

### Atomic holds

The hold service:

1. Recomputes cart demand and a canonical cart hash from server-owned cart lines, quantities, prices, voucher, location, order type and total.
2. Starts a database transaction.
3. Locks the durable slot row with `SELECT ... FOR UPDATE`.
4. Expires stale holds for that slot inside the transaction.
5. Counts converted and active unexpired holds.
6. Enforces both order-count and item-unit limits.
7. Inserts the hold and commits, or rolls back the complete attempt.

Locking the slot row is essential. A query that only locks existing hold rows cannot serialize two requests when the slot has no holds yet.

The browser receives a deterministic, unguessable HMAC hold token for retry recovery. Only its SHA-256 verifier is stored. Repeating the same idempotency key with the same cart returns the same logical hold and consumes no extra capacity. Reusing the key for another cart, shop or slot returns a conflict.

### Staff configuration

Each shop can configure:

- timezone;
- rollout state (`off` or `shadow` only);
- pickup window length;
- minimum notice;
- booking horizon;
- hold duration;
- maximum orders per window;
- maximum item units per window;
- split weekly pickup hours.

Every configuration save advances the planning version. Existing materialised slots and future order promises retain their snapshots rather than being silently rewritten by later settings.

When a shop is in shadow mode, its administration page now shows the first proposed schedule-only windows. The panel is staff-only, includes no current-order load, reserves nothing and is never sent to checkout or a payment provider.

### Atomic hold-to-order primitive

A verified-paid backend caller can now attach a valid hold to order creation. Slot, hold, order, items and creation event participate in one transaction. The service locks slot then hold, recomputes the cart hash, stores the ready-window/timezone/planning snapshots and converts the hold exactly once. Any item, event or conversion failure rolls the whole operation back and leaves the hold retryable.

This backend primitive does not make the feature customer-live. Capacity orders retain the existing pending/staff-acceptance lifecycle, fire time remains unset until a snapshotted planning model exists, and a converted-hold replay verifies both sides of the order/hold link without creating another order, event or notification hook.

## Verification completed

- Capacity window suite: 17 passed, 0 failed.
- Capacity hold transaction suite: 15 passed, 0 failed.
- Atomic hold-to-order suite: 25 passed, 0 failed.
- WordPress-stub boot suite: 123 passed, 0 failed.
- Lifecycle transaction suite: 16 passed, 0 failed.
- Strict PHP syntax and release-version checks pass locally.
- GitHub draft PR #23 runs PHP 7.4, 8.2, 8.3, 8.4 and 8.5, the installable zip build and secret scan.

The deterministic suite covers Sydney summer/winter offsets, spring and autumn daylight-saving transitions, exact notice boundaries, closing boundaries, blackouts, invalid configuration, exact/full capacity, stable cart hashes, retry idempotency, expired capacity, token storage and transaction rollback.

## Explicitly not implemented or enabled yet

- No public availability endpoint or customer slot picker.
- No customer-enforced capacity mode.
- No checkout or payment endpoint passes a capacity hold into the atomic conversion primitive.
- No payment attempt starts from a capacity hold.
- No Tyro or Stripe provider activation.
- No item-specific prep, station, oven, tray or batch model.
- No staff fire-time lane or automatic queue projection.
- No promise or notification is sent from shadow calculations.

These are deliberate safety boundaries, not missing feature flags that should be switched on manually.

## Required gates before customer scheduling

1. Run database migration and rollback rehearsal against a recent sanitised production copy.
2. Add a GitHub MariaDB 10.6 and 11.4 integration job using a real WordPress installation.
3. Race two independent database connections for the final unit on an initially empty slot; exactly one must succeed.
4. Prove migration idempotency and fail-closed behaviour for a missing index, missing table and MyISAM transaction participant.
5. Materialise slot rows from validated hours and dated exceptions.
6. Wire checkout/payment attempts to the proven atomic conversion primitive without allowing duplicate confirmation side effects.
7. Add checkout and payment-attempt records so a provider success after browser loss is reconciled safely.
8. Configure actual Revesby hours and observe shadow recommendations against real order history.
9. Measure useful order and item-unit limits with staff during both one-person and two-person shifts.
10. Only then add the accessible customer picker and incremental KDS fire-time projections.

## Payment boundary

Tyro remains the preferred primary candidate because DoughBoss already uses Tyro in-store and Tyro has offered eCommerce test credentials. Stripe remains a dormant fallback. Neither should be connected to enforced capacity until a paid-but-unconverted attempt can be recovered, reconciled and refunded or voided without creating a duplicate charge or losing the order.

The next safe implementation slice is real MariaDB concurrency plus atomic hold-to-order conversion. Customer UI comes after those invariants are proven.
