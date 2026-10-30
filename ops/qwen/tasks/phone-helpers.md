# Brief: phoneTel and phoneDisplay in public/js/doughboss.js

**Why:** the shop card on /order/ and /menu/ shows the Bankstown number as `0287646783`. The rest of the site prints `(02) 8764 6783`, and its tel: links have no `+61`. The server already has the right rules in PHP: `DoughBoss_Settings::phone_tel` and `phone_display`, shown below. The storefront needs the same rules in JavaScript. A later task makes the shop card use them, so don't change `shopContact` here.

**Add two new functions** inside the storefront script, directly **before** `function shopContact(loc, cfg) {`, at the same indentation (one tab), with one blank line between them and before `shopContact`:

- `phoneTel(value)` returns the dial form for a valid Australian number, else `''`.
- `phoneDisplay(value)` returns the readable form for a valid Australian number. For anything else it returns the value as typed, trimmed.

**What counts as a valid Australian number.** Take the input's digits only, dropping every non-digit, including `+`, spaces, brackets, dots, dashes and non-breaking spaces. The number is valid when those digits are either:

- `0` then one of `2 3 4 7 8` then 8 more digits (10 digits in all), or
- `61` then one of `2 3 4 7 8` then 8 more digits (11 digits in all).

Nothing else is valid, including `1300`/`13` numbers, `05` numbers, `0061…`, foreign numbers, short numbers and `+61 (0) 2 …`. The PHP version turns `1300 123 456` into `+1300123456`, which would dial another country, so this rule is deliberately stricter than the PHP. For valid numbers, the output must match the PHP exactly.

**Outputs for a valid number:**

- `phoneTel` gives `+61` plus the 9 digits after the leading `0` (or after `61`). Examples: `0297742286` → `+61297742286`, `+61 466 353 133` → `+61466353133`.
- `phoneDisplay`: mobiles (`04…`) print as `0466 353 133` (4-3-3). Every other valid number prints as `(02) 9774 2286`, that is `(0X) XXXX XXXX`. A `+61`/`61` input prints in this local `0…` form.

**Odd input:**

- A string is used as is.
- A finite number is read as `String(value)`.
- Anything else (`null`, `undefined`, objects, arrays, booleans, `NaN`) gives `''` from both functions.
- Neither function ever throws.

**Rules:**

- Pure functions, ES5.
- No DOM, `window`, `document`, network, storage, `DATA`, `I18N` or other storefront helpers.
- `phoneDisplay` may call `phoneTel`.
- Change nothing else in the file.
