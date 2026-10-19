# A$300 routing — native acceptance R3

Date: 18 October 2026, Australia/Sydney.

This records local source, isolated WordPress/MySQL and package-preparation evidence only. It is not a live deployment, Square/provider acceptance, payment or ordering activation, or a connection of Bankstown or Roselands.

## Frozen candidate

- Branch: `codex/2.51-handoff-20261016`
- Source commit: `0a037d1e2e7b6a95f6023121c546aa096b763101`
- Runtime runner SHA-256: `EA2650699BB265EEFE2E1D1626722E4E648F69A158AD01CA9847F5E4D4EC5302`
- Freeze manifest SHA-256: `7A5053C3145A01314F23BAE961FBC7778400039ADF7D0CE747483BDFC59A1D32`
- Native evidence: `C:\Codex\Temp\doughboss-local-first-runtime-20261017\runtime-r19-routing300-r2`

## Native result

The fresh isolated runner completed successfully against the exact frozen commit.

- 239 installed WordPress plugin source files matched the repository bytes.
- 3,622 WordPress/MySQL assertions passed; 0 failed.
- Two-process ledger, Square web, approved-preorder and release race checks passed.
- Square crash/replay checkpoints retained their original identities and expected provider/kitchen-call counts.
- The 12-worker pickup race retained six holds and six full refusals with durable state verified.
- External HTTP and email were blocked and verified.
- The dataset was fresh; the older fixture was untouched.
- MariaDB stopped normally without a forced stop; port 33119 was closed after the run.
- `result.json`: `Passed=true`, `SourceGatePassed=true`, `HashesStable=true`, `DatabaseStopped=true`, `PortStillListening=false`, `ForcedStop=false`.

The exercised routing fixture covers A$299.99, A$300.00 and A$300.01, forged client totals, delivery-fee exclusion, explicit Revesby acceptance, non-AUD rejection, and missing/inactive-Revesby catering no-write behavior. The authoritative basis is food-and-drink total after discounts, including GST, before delivery/service fees. Ordinary orders at or above A$300.00 require explicitly selected Revesby; catering always resolves to active Revesby or fails closed.

## Package preparation

- Archive: `C:\Codex\Output\doughboss-release-candidate-20261018-0a037d1\doughboss-2.51.0-0a037d1.zip`
- SHA-256: `2FD048BF772DED566DBCA508C27C0DE964AF6125125EDCAFCA7BDF0913EA6DE4`
- Size: 5,689,350 bytes
- Validator: version `2.51.0`; 239 files; archive layout and current-tree bytes valid.

The theme was not changed by this routing batch and no replacement theme archive was created.

## Independent verification

The existing read-only GPT-6.1 Sol/xhigh verifier task `01a14ea2-748f-7d42-978c-1aef32850332` first returned `SHIP_LOCAL_SOURCE_ONLY` for the repair commit, then inspected the retained native evidence and returned:

`SHIP_LOCAL_NATIVE_AND_PACKAGE_PREP — no blocking findings.`

## Preserved gates

- Payments and ordering remain disabled.
- Bankstown and Roselands Square connections remain off.
- No Square/provider request, merchant mutation, live WordPress write, database migration, Ads spend or financial transaction occurred.
- This evidence does not replace production backup/restore proof, installed/live byte parity, provider financial lifecycle acceptance, item-level tax review, real KDS/device acceptance, or signed-out production verification.

