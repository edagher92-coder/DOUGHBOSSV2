# A$300 routing candidate — local implementation, final verification pending

Later checkpoint: `2026-10-18-300-ROUTING-NATIVE-PREP-R1.md` records a further Qwen test draft, syntax-checked (not executed) real-controller boundary cases, and the owner-authorised separate Sol 6.1 verification task. That task is active/waitingOnApproval, not a final verdict. Runtime/unit-test hashes below remain unchanged; no runtime push or activation occurred.

18 October 2026, Australia/Sydney. Baseline `10816b95ac6c5b60a5aee37977fe8ef16f29ece6`, branch `codex/2.51-handoff-20261016`. Owner explicitly requested Qwen coding assistance, Sol 6.1 verification, boundary tests, payments and other shop connections kept off.

## Scope and behaviour

Only the existing REST controller and its nearest existing regression file changed. No dependencies, routes, schemas, settings, gateway configuration or provider state changed. Existing catering routing remains unchanged.

- A new private guard validates bounded canonical money and compares integer AUD cents. It uses the **server-calculated total minus delivery and optional service fees**, which already includes discounts and GST under the existing cart calculation. It does not subtract GST again, apply a discount twice or use the browser's claimed amount/tender balance.
- At A$300.00 and above, the explicitly selected location must be the active, exact `revesby` slug with its matching native ID. Otherwise a 409 explains the policy and asks the customer to select Revesby/review pickup details or contact the shop. It does not auto-reroute a customer, table session, pickup reservation, seller or payment.
- Enforcement is wired into ordinary payment initiation, new unpaid checkout, new pre-order request creation, and both Stripe/Square approved-request payment starts. It precedes new provider dispatch/order creation. Other existing eligibility, binding, availability, approval and pickup checks remain in place.
- The existing same-shop initiated-attempt recovery exemption remains before the new guard. Merely prepared/failed or wrong-shop attempts do not get that exemption. Paid checkout returns, durable order replay, hosted settlement and webhook recovery remain on their original seller/snapshot paths. This is not permission to create another charge or change a historical seller.
- Current cart/order models have no persisted service-fee field; ordinary totals can explicitly exclude an optional server service fee, while historical approved rows retain their existing total/delivery fields. Any future fee/storage change still needs its own validated contract.

Frozen candidate file hashes:

| File | SHA-256 |
| --- | --- |
| `includes/class-doughboss-rest-controller.php` | `a4c405c039704e34e3beb466610d88fb5063a7c6d1386ec6c35b5781dd255798` |
| `tests/test-zz-checkout-pickup-2-51.php` | `d9b65c996e36624f6b89963da1d50e11f8a25ef1c80c934802300f2f511912e4` |

## Tests actually run

Existing PHP runtimes: `C:/Codex/Temp/doughboss-portable-php-20261012-c1d4232b/php82/php.exe` and sibling `php74/php.exe`.

```text
PHP -n -l includes/class-doughboss-rest-controller.php
PHP -n -l tests/test-zz-checkout-pickup-2-51.php
PHP -n tests/run.php
git diff --check
```

- Red before implementation: PHP 8.2 **3116 assertions, 3114 passed, 2 expected missing-helper failures**.
- Final PHP 8.2: **3177/3177**, zero failures.
- Final PHP 7.4: **3107/3107**, zero failures.
- Both changed files passed syntax on both runtimes; diff check passed. A format-only operation normalized CR endings on touched lines only, preserving unrelated mixed endings.
- Added 63 assertions cover A$299.99/A$300/A$300.01, discounts/fee exclusion, whole-dollar integer versus float/decimal input, optional service fees, missing/inactive/mismatched/name-spoofed Revesby, invalid/nonfinite/subcent/overflow money, existing initiated recovery versus prepared/failed/wrong-shop attempts, and no helper writes to orders/attempts/snapshots.

These execute the actual private guard and existing payment preflight helper with the existing fake database. They are **not full REST/real WordPress/database/provider tests**. No fresh native database run has been admitted or executed, and no direct customer browser journey is accepted from them.

## Qwen and Sol evidence

Receipt: `artifacts/routing300-qwen-20261018-r1.json`.

- Actual local model `qwen3.8-27b:code`. Initial draft: 683 prompt / 2226 output tokens, 48.9087 native generated TPS, 53.968s connector time, first raw content 8.422s, normal stop, 46 streamed updates reported.
- Corrective draft: 649 prompt / 1600 output tokens, 56.8730 native TPS, 29.875s connector time, first raw content 1.750s. It hit the output cap (`finish_reason=length`, `status=incomplete`); its returned `truncated=false` flag must not be mistaken for a complete response.
- Root rejected the invented accounting contract/new class, corrected its own initial location-property wording, caught Qwen's integer-dollar/cents mistake, and integrated a smaller helper using the repository's existing strict cents-validation pattern. This is **Qwen-assisted/root-corrected work, not autonomous Qwen success or a sustained-context benchmark**. No further model retry was made.
- Requested fresh GPT-6.1 Sol/xhigh verifier did **not** start: collaboration returned `agent thread limit reached`. An interrupt of an unstarted DoughBoss lane returned `pending_init` but did not release the limit. The unrelated Vulkan lane was untouched. No substitute model or Sol verdict is claimed. Independent Sol 6.1 verification remains mandatory before accepting/pushing the runtime batch.

## Remaining acceptance and publication hold

Do not upload the older packaged ZIP as this implementation: R18/native/package evidence predates this runtime change. After Sol source verification, prepare a new exact-source freeze and fresh guarded synthetic WordPress/MySQL run to exercise ordinary payment/checkout and staff-approved requests, including direct/stale-client totals and old-attempt recovery; review the actual result. Build new packages only after the applicable gates. Do not retry or overwrite older test operations/receipts.

No runtime push, deployment, ordering/payment activation or Bankstown/Roselands connection occurred in this batch. The full launch remains unaccepted. Square access changed separately: the existing browser tab now opens `https://app.squareup.com/dashboard/items/library` with title Square Dashboard after the owner signed in. This is access evidence only, not verification of the correct merchant/location/catalogue or a provider mutation.
