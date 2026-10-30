# Qwen storefront tasks

Claude (the lead) writes each brief and its acceptance test (the oracle). Qwen writes the change as SEARCH/REPLACE edits. `qwen-site-task.mjs` applies them, then runs the oracle plus every `tests/*.test.js` suite in a locked-down child process. It feeds failures back and logs every attempt.

Nothing is committed automatically:
- A passing change stays in the working tree for the lead to review.
- A failing one is put back byte for byte.

## Run it on the PC (ELZYDLAB, where Qwen answers at http://127.0.0.1:8090/v1)

```powershell
git fetch origin claude/brave-davinci-exkwqh
git switch claude/brave-davinci-exkwqh
git pull origin claude/brave-davinci-exkwqh
$env:QWEN_LOCK_SCRIPT = 'C:\AI\locks\qwen-lock.ps1'   # one Qwen job at a time
node ops/qwen/qwen-site-task.mjs --all                 # reads LOCAL_QWEN_KEY; never prints it
git add ops/qwen/log public/js/doughboss.js
git commit -m "Qwen site-task run: drafts pending lead review"
git push origin claude/brave-davinci-exkwqh
```

- **Before it starts**, the runner checks that `qwen3.8-27b:code` is actually served and that the target files have no local edits. If either check fails it stops and changes nothing.
- **Options:** `--endpoint`, `--model`, `--key-env`, `--attempts` (default 4).
- **Running a subset:** name the tasks, for example `node ops/qwen/qwen-site-task.mjs phone-helpers`.

## Guard rails

| Rule | Enforced by |
|---|---|
| The NUMBERS RULE: no payment, checkout, cart, price, quote, voucher, catering, refund or order code | `checkTask`, before any model call |
| Only `public/js/doughboss.js`, `public/css/doughboss*.css` and `themes/doughboss-final/` | `checkTask` |
| Every changed line must sit inside the function(s) the task names | `outsideAllowed`, after every reply; the change is refused even if tests pass |
| Model code runs in `node --permission` (read-only repo, empty environment, 60 s) | `runTest` |
| Oracles are lead-written and never edited by the model | Model edits are applied to `task.target` only |

## Tasks

| Task | Fixes | Oracle |
|---|---|---|
| `phone-helpers` | JS `phoneTel`/`phoneDisplay`, matching `DoughBoss_Settings::phone_tel`/`phone_display` for every valid AU number, and refusing a link for anything else (the PHP turns `1300 123 456` into `tel:+1300123456`) | `tests/qwen/phone-helpers.oracle.js`: 150 parity cases generated from the PHP, 70 reject cases |
| `shop-contact-phone` | The shop card on /order/ and /menu/ shows `(02) 8764 6783` with a `tel:+61…` link instead of `0287646783` | `tests/qwen/shop-contact-phone.oracle.js` |

Oracles are named `*.oracle.js`, not `*.test.js`, so plugin CI and `scripts/test-release.ps1` (which run every `*.test.js`) stay green while a fix is pending. When the lead accepts a change, its oracle moves to `tests/` as a `*.test.js` suite.

Both oracles fail on the current code (8/9 and 6/6 red) and pass against a lead reference fix that was then removed. The runner was exercised end to end against a scripted fake server; Qwen itself has not run these yet.
