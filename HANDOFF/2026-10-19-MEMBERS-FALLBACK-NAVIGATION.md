# 19 October 2026 — Members fallback navigation

## Implemented locally

The shared theme fallback menu now includes Members & Events immediately after Catering. It links to `/members-events/` in the same tab and uses the existing desktop navigation/mobile drawer. On the Members page the fallback marks this one destination `current-menu-item` and `aria-current="page"`.

At 1101–1599px the header uses two rows (brand/action, then navigation). At 1600px and above the header's own wrapper may expand to 1600px, and desktop labels remain unbroken. The mobile drawer breakpoint remains 1100px. The synthetic preview's existing page-slug map now recognises the Members route so its active-state checks represent the real theme condition.

This changes the fallback path only. An already-assigned WordPress Primary menu takes precedence and remains unchanged. Add the existing published Members page to that saved menu, checking for duplicates and preserving order, during the separately gated publication batch. No WordPress menu database write, provider action or publication occurred here.

## Evidence

- PHP 8.2 syntax: theme functions, affected PHP test and synthetic shim all passed.
- Existing PHP suite: **3196 assertions, 3196 passed, 0 failed**.
- Existing five frontend suites: **228 passed, 0 failed, 0 skipped**.
- `git -c core.whitespace=cr-at-eol diff --check`: passed.
- Current-source nine-page / 61-file local preview validation passed before the final active-state addition; the final active-state addition is covered by PHP runtime assertions. No claim is made that the earlier preview includes that final addition.
- Browser geometry after the header-fit repair: 1102px and 1440px show two header rows; 1920px shows one row with Members unbroken; document width never exceeded viewport. At 390px and 320px the existing drawer remains the active navigation.
- Settled 320px drawer: left 38.40px, right 320px; Members link left 68.40px, right 290px. Keyboard Escape closed the drawer and restored focus to Open navigation with `aria-expanded=false`.
- Chrome screenshots repeatedly timed out on the shorter Members page as well as checkout. DOM geometry/interaction evidence is accepted for those named checks only; final rendered screenshot/visual acceptance remains open.

## Qwen and gate

Qwen's bounded CSS response completed (265 prompt / 143 completion tokens, 18531ms first content, 23703ms total, reported predicted generation 28.996 tokens/second). Its code body was unavailable behind a result pointer, so it was not accepted into source. The lead implemented and inspected the actual CSS, tested it in-browser and corrected a demonstrated 1920px label wrap. This is lead-verified local source, not a new independent design score.

## Remaining goal work

Authentic per-item photography, full rendered checkout acceptance and the safe membership/account boundary remain subject to the earlier readiness handoff. Customer membership sign-up is still unavailable. Production backup/restore/parity, provider/financial/device acceptance and publication remain separate gates. Existing upload ZIPs do not contain these later changes; build fresh archives only after the next consolidated source freeze.
