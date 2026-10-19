# A$300 routing — Qwen follow-up and native test preparation

18 October 2026, Australia/Sydney. Runtime and unit-test candidate remains local commit `20977b4e2fd7c61a7a6a2ac49b18e09ea84fcc9b`. No runtime change, push, deployment or provider action in this follow-up.

## Qwen work actually performed

One bounded supervised `task=code` call to `qwen3.8-27b:code` drafted additional real-controller tests. Actual response: normal stop, 828 prompt / 1707 output tokens, 38.921s connector time, 4.296s first raw content, 49.3061 native generation TPS, 36 connector-reported streamed updates. App-display latency and sustained/deep-context acceptance were not measured.

The draft was not accepted unchanged: root corrected invented namespaces, the cart method signature, contact parameter names, the provider counter, environment guard assumptions, exact cleanup and table-cookie isolation. Its useful contribution was the boundary-case/insert-count test structure. The corrected draft is not evidence that Qwen can autonomously integrate the repository. See `artifacts/routing300-qwen-native-prep-20261018-r1.json` for the measured receipt and corrections. No Qwen engine/profile/model/backend setting changed.

## Additional source prepared — not executed

Extended the existing `tests/integration/wordpress-mysql.php`, before removal of its HTTP/mail guards. SHA-256: `4db7e0978b1d0190a0bb319228ca09bc053facc10b8caed41041fdbb788f00de`.

Seven actual-controller cases are prepared: Bankstown pickup at A$299.99/A$300/A$300.01; A$300 with forged browser total/discount/amount; explicit Revesby A$300; Bankstown delivery with A$299.99 or A$300 in goods plus A$25 delivery. Assertions check real cart GST-inclusive goods arithmetic, exact rejection, order insertion count, zero provider POSTs and payment acceptance still disabled.

The block uses only the already-guarded disposable WordPress database. It preserves/restores options, location rows, cart/table cookies and REMOTE_ADDR; only a newly created synthetic location row can be deleted. It does not connect a Square account or alter provider bindings. Delivery/ordering options are only temporary synthetic fixture inputs; payments remain disabled even inside that fixture. These source statements are not proof of execution or cleanup until the guarded run occurs.

PHP 8.2 and PHP 7.4 `-n -l tests/integration/wordpress-mysql.php` passed, and `git diff --check` passed. No new native source freeze, runner, operation admission, database boot or test run has occurred. Do not count these seven cases as passed. Existing R18 evidence and the earlier 3177/3107 local unit results remain unchanged, not full-controller proof for the new test block.

## Owner-authorised independent Sol 6.1 task

Owner approved a separate verification task after this conversation's agent limit prevented its verifier. Created through the supported task interface:

- Title: **DoughBoss A$300 routing — Sol 6.1 verification**
- Thread ID: `01a14ea2-748f-7d42-978c-1aef32850332`, host `local`.
- Requested model/effort: `gpt-6.1-sol` / `xhigh`.
- Saved project: DOUGHBOSS FINAL, whose default checkout differs; the task explicitly pins all review commands to the canonical checkout and committed `20977b4` candidate, read-only.
- No edits, new models/agents, native DB/VM/provider/browser actions, commits/pushes, deployments, payment activation or shop connections are permitted in that task.
- It acknowledged the assignment and verified both frozen source hashes. Last supported wait returned `active` / `waitingOnApproval`, revision 1, after a 30-second event-aware wait. It is not terminal and must not be restarted merely because the wait timed out. No final verdict or completed independent test run is claimed.

The owner needs to handle the pending approval in that separate task. Parent must inspect the eventual findings and any test results, resolve defects, then obtain applicable exact-source native operation/result acceptance. Do not change the frozen runtime candidate or advance HEAD while the reviewer checks it. The new integration-test preparation remains an explicit uncommitted follow-up until that review boundary is reconciled. Preserve unrelated `HANDOFF/2026-10-18-QWEN-LEAD-RESUME.md`.
