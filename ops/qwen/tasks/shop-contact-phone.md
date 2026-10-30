# Brief: the shop card's phone in shopContact (public/js/doughboss.js)

**Why:** `shopContact` prints `loc.phone` exactly as stored, so Bankstown shows as `0287646783`. It also builds the tel: link from raw digits, so it has no `+61`, and anything with 6 to 15 digits becomes a link, even numbers that aren't Australian. The helpers `phoneTel` and `phoneDisplay` now exist (shown below).

**Change only the `if (loc && loc.phone) { … }` block inside `shopContact`** so that:

- If `phoneTel(loc.phone)` is not empty, append `el('a', { class: 'db-shop-phone', text: phoneDisplay(loc.phone), href: 'tel:' + <that phoneTel value> })`.
- Otherwise append `el('span', { class: 'db-shop-phone', text: phoneDisplay(loc.phone) })`, with **no `href` key at all**.
- Keep the outer `if (loc && loc.phone)` guard, so no phone means no element.

**Everything else in `shopContact` stays exactly as it is:**

- the shop status line;
- the address;
- the order of the children;
- the ordering and payment context block and its wording.

Do not touch `phoneTel`, `phoneDisplay` or any other function.
