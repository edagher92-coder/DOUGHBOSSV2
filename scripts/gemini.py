#!/usr/bin/env python3
"""
Cached, tiered Google Gemini helper for asset/content generation.

Efficiency by design (see docs/Gemini-Claude-Playbook.md):
  - Prompt-hash caching: identical (model + prompt + params) requests are served
    from .cache/gemini/ and cost ZERO API calls.
  - Model tiering: draft on Flash / Imagen-4-fast; promote to Pro / Imagen-4
    only for the final pass (just change --model; the new hash caches separately).
  - --dry-run shows model + hash + cache hit/miss WITHOUT calling the API.

The API key is read ONLY from the GEMINI_API_KEY environment variable. Never
hard-code or commit it.

Usage:
  GEMINI_API_KEY=... python scripts/gemini.py text  "PROMPT" [--model gemini-2.5-flash] [--max-tokens 512] [--no-cache] [--dry-run]
  GEMINI_API_KEY=... python scripts/gemini.py image "PROMPT" --out out.png [--model imagen-4.0-fast-generate-001] [--aspect 1:1] [--max-px 480] [--dry-run]
"""
import argparse, base64, hashlib, json, os, sys, time, urllib.request, urllib.error

BASE = "https://generativelanguage.googleapis.com/v1beta/models/"
CACHE = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), ".cache", "gemini")


def _key():
    k = os.environ.get("GEMINI_API_KEY")
    if not k:
        sys.exit("error: set GEMINI_API_KEY in the environment (never hard-code it).")
    return k


def _hash(model, prompt, params):
    raw = "%s|%s|%s" % (model, prompt, json.dumps(params, sort_keys=True))
    return hashlib.sha256(raw.encode()).hexdigest()


def _log(model, h, kind, hit):
    os.makedirs(CACHE, exist_ok=True)
    with open(os.path.join(CACHE, "calls.log"), "a") as f:
        f.write("%s\t%s\t%s\t%s\t%s\n" % (time.strftime("%Y-%m-%dT%H:%M:%S"), kind, model, h[:12], "HIT" if hit else "CALL"))


def _post(model, body):
    req = urllib.request.Request(
        BASE + model, data=json.dumps(body).encode(),
        headers={"Content-Type": "application/json", "X-goog-api-key": _key()}, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return json.loads(r.read())
    except urllib.error.HTTPError as e:
        sys.exit("Gemini API error %s: %s" % (e.code, e.read().decode()[:300]))


def gen_text(prompt, model="gemini-2.5-flash", max_tokens=512, cache=True, dry=False):
    params = {"maxOutputTokens": max_tokens}
    h = _hash(model, prompt, params)
    cf = os.path.join(CACHE, h + ".json")
    if cache and os.path.exists(cf):
        _log(model, h, "text", True)
        return json.load(open(cf))["text"]
    if dry:
        print("[dry-run] text model=%s hash=%s cache=%s" % (model, h[:12], "MISS"))
        return None
    d = _post(model + ":generateContent", {"contents": [{"parts": [{"text": prompt}]}], "generationConfig": params})
    text = "".join(p.get("text", "") for p in d["candidates"][0]["content"]["parts"])
    os.makedirs(CACHE, exist_ok=True)
    json.dump({"text": text, "model": model, "prompt": prompt}, open(cf, "w"))
    _log(model, h, "text", False)
    return text


def gen_image(prompt, out, model="imagen-4.0-fast-generate-001", aspect="1:1", max_px=0, cache=True, dry=False):
    params = {"sampleCount": 1, "aspectRatio": aspect}
    h = _hash(model, prompt, params)
    cp = os.path.join(CACHE, h + ".png")
    if cache and os.path.exists(cp):
        _log(model, h, "image", True)
        png = open(cp, "rb").read()
    elif dry:
        print("[dry-run] image model=%s hash=%s cache=%s" % (model, h[:12], "MISS"))
        return None
    else:
        d = _post(model + ":predict", {"instances": [{"prompt": prompt}], "parameters": params})
        png = base64.b64decode(d["predictions"][0]["bytesBase64Encoded"])
        os.makedirs(CACHE, exist_ok=True)
        open(cp, "wb").write(png)
        _log(model, h, "image", False)
    if max_px:  # optional downscale + recompress to keep deliverables lean
        try:
            import io
            from PIL import Image
            im = Image.open(io.BytesIO(png)).convert("RGB")
            w, hh = im.size
            if max(w, hh) > max_px:
                im.thumbnail((max_px, max_px), Image.LANCZOS)
            im.save(out, "JPEG" if out.lower().endswith((".jpg", ".jpeg")) else "PNG", quality=82)
            return out
        except Exception:
            pass
    open(out, "wb").write(png)
    return out


def main():
    ap = argparse.ArgumentParser(description="Cached, tiered Gemini helper.")
    sub = ap.add_subparsers(dest="cmd", required=True)
    t = sub.add_parser("text"); t.add_argument("prompt"); t.add_argument("--model", default="gemini-2.5-flash"); t.add_argument("--max-tokens", type=int, default=512)
    i = sub.add_parser("image"); i.add_argument("prompt"); i.add_argument("--out", required=True); i.add_argument("--model", default="imagen-4.0-fast-generate-001"); i.add_argument("--aspect", default="1:1"); i.add_argument("--max-px", type=int, default=0)
    for p in (t, i):
        p.add_argument("--no-cache", action="store_true"); p.add_argument("--dry-run", action="store_true")
    a = ap.parse_args()
    if a.cmd == "text":
        out = gen_text(a.prompt, a.model, a.max_tokens, not a.no_cache, a.dry_run)
        if out is not None:
            print(out)
    else:
        out = gen_image(a.prompt, a.out, a.model, a.aspect, a.max_px, not a.no_cache, a.dry_run)
        if out:
            print("wrote", out)


if __name__ == "__main__":
    main()
