#!/usr/bin/env python3
"""Split the generic Juice / Soft Drinks 600ml items into real flavours.

Every price comes from the POSPal till catalogue (verified), never invented.
Creates each item via wp/v2 (title/status/category), then saves the price and
availability meta through the classic-editor form, because the doughboss_item
post type does not declare custom-fields support so REST will not write meta.
"""
import json
import os
import re
import ssl
import sys
import time
import urllib.parse
import urllib.request
from http.cookiejar import MozillaCookieJar

BASE = "https://doughboss.com.au"
SP = os.environ.get("DB_OPS_DIR", ".")  # dir holding cookies.txt + restnonce.txt
UA = ("Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) "
      "Chrome/140.0.0.0 Mobile Safari/537.36")

# Build the Cookie header straight from curl's jar — the cookiejar module drops
# WordPress's session cookies (expiry 0) even with ignore_discard.
_ck = []
for line in open(SP + "/cookies.txt"):
    line = line.rstrip("\n")
    # curl marks HttpOnly cookies with a "#HttpOnly_" prefix — those are exactly
    # the WordPress auth cookies, so they must not be skipped as comments.
    if line.startswith("#HttpOnly_"):
        line = line[len("#HttpOnly_"):]
    elif line.startswith("#") or not line.strip():
        continue
    parts = line.split("\t")
    if len(parts) >= 7:
        _ck.append(parts[5] + "=" + parts[6])
COOKIE = "; ".join(_ck)
ctx = ssl.create_default_context(cafile="/root/.ccr/ca-bundle.crt")
opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=ctx))
REST_NONCE = open(SP + "/restnonce.txt").read().strip()


def req(url, data=None, headers=None, tries=4, form=False):
    """One request with retries; the sandbox proxy resets connections at random."""
    for attempt in range(tries):
        try:
            h = {"User-Agent": UA, "Cookie": COOKIE}
            body = None
            if data is not None:
                if form:
                    body = urllib.parse.urlencode(data).encode()
                    h["Content-Type"] = "application/x-www-form-urlencoded"
                else:
                    body = json.dumps(data).encode()
                    h["Content-Type"] = "application/json"
                    h["X-WP-Nonce"] = REST_NONCE
            if headers:
                h.update(headers)
            r = opener.open(urllib.request.Request(url, body, h), timeout=60)
            return r.getcode(), r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace")
        except Exception as exc:  # proxy reset / timeout
            if attempt == tries - 1:
                return 0, "ERR " + str(exc)
            time.sleep(4)
    return 0, "unreachable"


# Every price below is the till's own price for that exact product.
JUICE, SOFT = 4.5, 5.0
ITEMS = [
    # (website name, price, description, pospal uid, pospal name)
    ("Apple Juice", JUICE, "Chilled apple juice.", "886511720806357678", "JW Apple Juice"),
    ("Lemon Juice", JUICE, "Chilled lemon juice.", "1044970076495943972", "JW Lemon Juice"),
    ("Lemon & Mint Juice", JUICE, "Chilled lemon and mint juice.", "792710851415057990", "JW Lemon Mint Juice"),
    ("Orange & Mango Juice", JUICE, "Chilled orange and mango juice.", "782156886714765625", "JW Orange & Mango Juice"),
    ("Orange & Passion Juice", JUICE, "Chilled orange and passionfruit juice.", "783446383126357221", "JW Orange & Passion Juice"),
    ("Orange Juice", JUICE, "Chilled orange juice.", "930443682739176328", "Sunzest Orange Juice"),
    ("Coke 600ml", SOFT, "Ice-cold Coca-Cola, 600ml.", "292754875634270678", "Coke Classic 600mL"),
    ("Coke Zero 600ml", SOFT, "Ice-cold Coke Zero, 600ml.", "389059485108812919", "Coke Zero 600mL"),
    ("Coke Vanilla 600ml", SOFT, "Ice-cold Vanilla Coke, 600ml.", "323655520593920820", "Coke Vanilla 600ml"),
    ("Sprite 600ml", SOFT, "Ice-cold Sprite, 600ml.", "933253461615156581", "Sprite 600mL"),
    ("Fanta 600ml", SOFT, "Ice-cold Fanta, 600ml.", "643186909438094785", "Fanta 600mL"),
]

# The Drinks term id, read from the live taxonomy.
code, body = req(BASE + "/wp-json/wp/v2/doughboss_category?per_page=100&search=Drinks")
terms = json.loads(body) if code == 200 else []
drinks = next((t["id"] for t in terms if t["name"].strip().lower() == "drinks"), None)
print("Drinks term id:", drinks)
if not drinks:
    sys.exit("could not resolve the Drinks category")

created = []
for name, price, desc, uid, till in ITEMS:
    code, body = req(
        BASE + "/wp-json/wp/v2/doughboss_item",
        {"title": name, "status": "publish", "excerpt": desc,
         "content": desc, "doughboss_category": [drinks]},
    )
    if code not in (200, 201):
        print("  CREATE FAIL %-24s %s %s" % (name, code, body[:120]))
        continue
    pid = json.loads(body)["id"]
    created.append((pid, name, price, uid, till))
    print("  created #%-5s %-24s $%s" % (pid, name, price))

# Meta goes through the classic editor form (post.php), which the plugin hooks.
for pid, name, price, uid, till in created:
    code, page = req(BASE + "/wp-admin/post.php?post=%d&action=edit" % pid)
    if code != 200:
        print("  EDIT FAIL", pid, code)
        continue

    def field(n, src=page):
        m = (re.search(r'<input[^>]*name="%s"[^>]*value="([^"]*)"' % re.escape(n), src)
             or re.search(r'<input[^>]*value="([^"]*)"[^>]*name="%s"' % re.escape(n), src))
        return m.group(1) if m else ""

    nonce = field("_wpnonce")
    item_nonce = field("doughboss_item_nonce") or field("doughboss_nonce")
    if not nonce:
        print("  NO NONCE", pid)
        continue
    form = {
        "action": "editpost", "post_ID": pid, "post_type": "doughboss_item",
        "_wpnonce": nonce,
        "_wp_http_referer": "/wp-admin/post.php?post=%d&action=edit" % pid,
        "post_title": name, "content": "", "excerpt": "",
        "post_status": "publish", "original_post_status": "publish",
        "save": "Update",
        "doughboss_price": ("%.2f" % price),
        "doughboss_item_type": "drink",
        "doughboss_available": "1",
        "tax_input[doughboss_category][]": str(drinks),
    }
    if item_nonce:
        form["doughboss_item_nonce"] = item_nonce
    # Carry any other doughboss_* inputs the meta box rendered.
    for m in re.finditer(r'name="(doughboss_[a-z_]+)"[^>]*value="([^"]*)"', page):
        form.setdefault(m.group(1), m.group(2))
    code, _ = req(BASE + "/wp-admin/post.php", form, form=True)
    print("  meta #%-5s %-24s -> HTTP %s" % (pid, name, code))

json.dump([{"id": p, "name": n, "price": pr, "uid": u, "till": t}
           for p, n, pr, u, t in created],
          open(SP + "/pospal/new-drinks.json", "w"), indent=1)
print("\nwrote", len(created), "items")
