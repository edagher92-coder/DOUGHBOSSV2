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
| Storefront JavaScript only (`public/js/doughboss*.js`, minus stakes files) | `checkTask` |
| The edited file must parse. Each named function must be declared exactly once. An existing function stays in the statement list it was in; a new one sits beside `siblingOf`, not inside another function or block. With those declarations cut out at their exact parsed ranges, the rest of the file must be the same tokens (exact text, including every literal), with the same line breaks between them, AND the same syntax tree (so no statement is regrouped around an inserted declaration by ASI) | `outsideAllowed` uses a real parser (vendored acorn 8.14.0, `vendor/acorn/`), not brace counting, after every reply. The change is refused even if the tests pass |
| Model code runs in `node --permission` with reads limited to `public/`, `tests/`, `themes/`, `includes/` and `scripts/`, an empty environment and a 60 s limit | `runTest` |
| Nothing untracked or ignored (a token, a `.env`) may sit in those folders | Pre-flight; the run refuses to start |
| No network and no host sockets for model code, proven by a probe. On Linux the child runs in its own network, mount and PID namespaces (`unshare -rnmpf`), chrooted into a fresh empty root holding only `/usr`, `/lib`, `/lib64`, `/bin`, `/sbin`, the Node binary itself (never its install folder) and the five read folders, read-only. The probe checks those host paths without the permission layer, so only a real "does not exist" passes, and it needs a read folder to be visible as a positive control. `$HOME`, `/etc`, `/run`, `/tmp` and every other host path do not exist there. Where Node's own permission model blocks network and sockets, that is used instead | `networkIsolation`; every attempt logs `networkIsolated`. **On Windows with Node 24 neither can be cut.** The run warns at the start, and the read limits above are the protection |
| Oracles are lead-written and never edited by the model | Model edits are applied to `task.target` only |

## Tasks

| Task | Fixes | Oracle |
|---|---|---|
| `phone-helpers` | JS `phoneTel`/`phoneDisplay`, matching `DoughBoss_Settings::phone_tel`/`phone_display` for every valid AU number, and refusing a link for anything else (the PHP turns `1300 123 456` into `tel:+1300123456`) | `tests/qwen/phone-helpers.oracle.js`: 150 parity cases generated from the PHP, 70 reject cases |
| `shop-contact-phone` | The shop card on /order/ and /menu/ shows `(02) 8764 6783` with a `tel:+61…` link instead of `0287646783` | `tests/qwen/shop-contact-phone.oracle.js` |

Oracles are named `*.oracle.js`, not `*.test.js`, so plugin CI and `scripts/test-release.ps1` (which run every `*.test.js`) stay green while a fix is pending. When the lead accepts a change, its oracle moves to `tests/` as a `*.test.js` suite.

Both oracles fail on the current code (8/9 and 6/6 red) and pass against a lead reference fix that was then removed. The runner was exercised end to end against a scripted fake server; Qwen itself has not run these yet.
