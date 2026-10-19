# A$300 routing — Sol repair candidate R2

18 October 2026, Australia/Sydney. This is a local source candidate, not a deployment, Square activation, payment enablement or other-shop connection.

## Review trigger and repair

Independent GPT-6.1 Sol/xhigh task `01a14ea2-748f-7d42-978c-1aef32850332` reviewed frozen commit `20977b4e2fd7c61a7a6a2ac49b18e09ea84fcc9b` and returned `FIX_FIRST`.

- The new A$300 guard compared 30000 cents without proving the caller's authoritative currency was AUD.
- An inherited new-catering path could fall back to the default shop when no active Revesby existed. That behavior predated the ordinary-order guard but contradicted the approved rule that catering always routes through Revesby.

R2 passes an authoritative currency into every new-money routing call. Current-cart paths use the current server setting; approved pre-order payment starts use the saved order currency. Non-AUD or malformed currency fails closed with `doughboss_order_routing_currency` before a new order/payment path. Existing same-shop initiated-attempt recovery still returns before the currency/routing guard, preserving historical money and seller identity.

New catering enquiries now resolve an active, exact-slug `revesby` location from the configured catering IDs. Missing, inactive or explicitly different shops return `doughboss_catering_location_unavailable`; there is no default-shop fallback and no enquiry row is created. Historical saved enquiries and payment recovery were not changed.

## Qwen contribution and correction

A bounded local `qwen3.8-27b:code` follow-up proposed the two fail-closed invariants. It was incomplete at its output cap: 421 prompt / 1400 output tokens, 36.6715 native generated tokens/s, 4.281 seconds to first raw content, 42.437 seconds total, 38 streamed progress updates, `finish_reason=length`. It invented an incompatible location API and did not correctly preserve per-caller saved currency, so the draft was not accepted unchanged. Root integrated the smaller repository-native repair and independently tested it.

## Source and tests at the pre-native checkpoint

| File | SHA-256 |
| --- | --- |
| `includes/class-doughboss-rest-controller.php` | `F7C168417EB863FEF623B2C9DB66BC620A8A92111F8F66F526260CEFB6C051D3` |
| `tests/test-zz-checkout-pickup-2-51.php` | `94F7E15976DB32AEC82EFC33748A57B5F04656E94523A07096D9A6D88437CDC3` |
| `tests/integration/wordpress-mysql.php` | `2AC1127B4C23FA9BD8C4DBC031D7FD8BDCB6B37E3B283BD73677A84BCE34AE71` |

Actual local results:

- PHP 8.2 unit suite: **3190/3190 assertions passed**.
- PHP 7.4 unit suite: **3120/3120 assertions passed**.
- PHP 8.2 and PHP 7.4 syntax: controller, routing unit test and WordPress/MySQL integration file passed.
- `git -c core.whitespace=cr-at-eol diff --check`: passed; the controller retains its pre-existing mixed line-ending convention without a broad reformat.

The WordPress/MySQL fixture now has eight ordinary-order cases, including a non-AUD fail-closed case, and new missing/inactive-Revesby catering no-write cases. These native cases are prepared but not yet counted as passed here. A fresh exact-source freeze, isolated native run and post-repair Sol verdict are required before package/push acceptance.

Payments remain disabled. Bankstown and Roselands remain unconnected. No provider request, browser mutation, database migration or production write occurred in this repair.
