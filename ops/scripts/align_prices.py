#!/usr/bin/env python3
"""Align every website price to the POSPal till price for its mapped product.

Reads the live menu, the live till catalogue and the saved product map, then
saves each differing item through the classic-editor form (the doughboss_item
post type has no custom-fields support, so REST cannot write the price meta).
Only the price changes; title, category, type and availability are preserved.
"""
import html as _h
import json
import os
import re
import ssl
import sys
import time
import urllib.parse
import urllib.request

SP = os.environ.get("DB_OPS_DIR", ".")  # dir holding cookies.txt + restnonce.txt
BASE = "https://doughboss.com.au"
UA = ("Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) "
      "Chrome/140.0.0.0 Mobile Safari/537.36")
APPLY = "--apply" in sys.argv

_ck = []
for line in open(SP + "/cookies.txt"):
    line = line.rstrip("\n")
    if line.startswith("#HttpOnly_"):
        line = line[len("#HttpOnly_"):]
    elif line.startswith("#") or not line.strip():
        continue
    parts = line.split("\t")
    if len(parts) >= 7:
        _ck.append(parts[5] + "=" + parts[6])
COOKIE = "; ".join(_ck)
NONCE = open(SP + "/restnonce.txt").read().strip()
ctx = ssl.create_default_context(cafile="/root/.ccr/ca-bundle.crt")
opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=ctx))


def req(url, data=None, tries=4, form=False):
    for attempt in range(tries):
        try:
            # Send the REST nonce on reads too — the POSPal endpoints are
            # capability-gated, and without it they answer as a logged-out user.
            h = {"User-Agent": UA, "Cookie": COOKIE, "X-WP-Nonce": NONCE}
            body = None
            if data is not None:
                if form:
                    body = urllib.parse.urlencode(data).encode()
                    h["Content-Type"] = "application/x-www-form-urlencoded"
                else:
                    body = json.dumps(data).encode()
                    h["Content-Type"] = "application/json"
                    h["X-WP-Nonce"] = NONCE
            r = opener.open(urllib.request.Request(url, body, h), timeout=60)
            return r.getcode(), r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace")
        except Exception as exc:
            if attempt == tries - 1:
                return 0, "ERR " + str(exc)
            time.sleep(4)
    return 0, "unreachable"


code, body = req(BASE + "/wp-json/doughboss/v1/menu")
menu = json.loads(body)
code, body = req(BASE + "/wp-json/doughboss/v1/pospal/products")
till = {p["uid"]: p for p in json.loads(body).get("products", [])}
pmap = json.load(open(SP + "/pospal/map.json"))

deltas = []
for item in menu:
    key = " ".join(item["name"].lower().split())
    uid = pmap.get(key)
    if not uid or uid not in till:
        print("  !! no till product for %s" % item["name"])
        continue
    site, shop = float(item["price"]), float(till[uid]["price"])
    if abs(site - shop) >= 0.005:
        deltas.append((item["id"], item["name"], site, shop, till[uid]["name"]))

print("\n%d item(s) differ from the till:" % len(deltas))
for pid, name, site, shop, tname in sorted(deltas, key=lambda d: d[2] - d[3]):
    print("  #%-5s %-26s $%-7s -> $%-7s (%+.2f)  [till: %s]"
          % (pid, name, site, shop, shop - site, tname))
if not APPLY:
    print("\ndry run — re-run with --apply")
    sys.exit(0)

print("\napplying:")
for pid, name, site, shop, tname in deltas:
    code, page = req(BASE + "/wp-admin/post.php?post=%d&action=edit" % pid)
    if code != 200:
        print("  #%s EDIT FAIL %s" % (pid, code))
        continue

    def field(n, src=page):
        m = (re.search(r'<input[^>]*name="%s"[^>]*value="([^"]*)"' % re.escape(n), src)
             or re.search(r'<input[^>]*value="([^"]*)"[^>]*name="%s"' % re.escape(n), src))
        return _h.unescape(m.group(1)) if m else ""

    if not field("_wpnonce") or not field("doughboss_item_nonce"):
        print("  #%s missing nonce, skipped" % pid)
        continue
    title = re.search(r'<input[^>]*id="title"[^>]*value="([^"]*)"', page)
    exc = re.search(r'<textarea[^>]*id="excerpt"[^>]*>(.*?)</textarea>', page, re.S)
    cont = re.search(r'<textarea[^>]*id="content"[^>]*>(.*?)</textarea>', page, re.S)
    itype = re.search(r'<select[^>]*name="doughboss_item_type"[^>]*>.*?'
                      r'<option[^>]*value="([a-z]+)"[^>]*selected', page, re.S)
    avail_box = re.search(r'<input[^>]*name="doughboss_available"[^>]*>', page)
    form = {
        "action": "editpost", "post_ID": pid, "post_type": "doughboss_item",
        "_wpnonce": field("_wpnonce"),
        "_wp_http_referer": "/wp-admin/post.php?post=%d&action=edit" % pid,
        "post_title": _h.unescape(title.group(1)) if title else name,
        "content": _h.unescape(cont.group(1)) if cont else "",
        "excerpt": _h.unescape(exc.group(1)) if exc else "",
        "post_status": "publish", "original_post_status": "publish", "save": "Update",
        "doughboss_item_nonce": field("doughboss_item_nonce"),
        "doughboss_price": "%.2f" % shop,
        "doughboss_item_type": itype.group(1) if itype else "standard",
    }
    if not avail_box or "checked" in avail_box.group(0):
        form["doughboss_available"] = "1"
    code, _ = req(BASE + "/wp-admin/post.php", form, form=True)
    print("  #%-5s %-26s $%s -> $%.2f  HTTP %s" % (pid, name, site, shop, code))

time.sleep(3)
code, body = req(BASE + "/wp-json/doughboss/v1/menu")
after = {i["id"]: i for i in json.loads(body)}
print("\nverification:")
bad = 0
for pid, name, site, shop, tname in deltas:
    now = float(after[pid]["price"])
    ok = abs(now - shop) < 0.005
    bad += 0 if ok else 1
    print("  %-26s now $%-7s %s (category %s, available %s)"
          % (name, now, "OK" if ok else "MISMATCH", after[pid]["category"], after[pid]["available"]))
print("\n%d item(s) still wrong" % bad)
