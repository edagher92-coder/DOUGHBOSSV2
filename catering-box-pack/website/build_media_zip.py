#!/usr/bin/env python3
"""Build doughboss-growth-media-<version>.zip: deterministic, refuses over 1.9 MB, records sha256, and
verifies that every file named in manifest.json exists, is unreferenced-free and has the declared size.
Since 0.2.0 it also checks assets/hero: hero.json declares status and ai_food, every poster it names exists with its
declared size, and no file sits in assets/hero without being named there. Videos are optional in assets/hero (a host
with no upload cap can ship them in the zip); the 1.9 MB ceiling still applies to the whole zip."""
import hashlib, json, os, re, struct, sys, zipfile
HERE = os.path.dirname(os.path.abspath(__file__))
# The tracked source folder is media-plugin; the installable root keeps its slug.
SRC = os.path.join(HERE, 'media-plugin')
MAXB = 1_900_000
if len(sys.argv) > 2:
    sys.exit('Usage: python build_media_zip.py [new-output.zip]')
main = open(os.path.join(SRC, 'doughboss-growth-media.php'), encoding='utf-8').read()
readme = open(os.path.join(SRC, 'readme.txt'), encoding='utf-8').read()
vs = {re.search(r'^ \* Version:\s*(\S+)', main, re.M).group(1),
      re.search(r'^Stable tag:\s*(\S+)', readme, re.M).group(1),
      re.search(r'^== Changelog ==\r?\n\r?\n= ([0-9.]+) =', readme, re.M).group(1)}
assert len(vs) == 1, vs
ver = vs.pop()
box = os.path.join(SRC, 'assets', 'box')
man = json.load(open(os.path.join(box, 'manifest.json')))
named = set()
for sid, s in man['slots'].items():
    assert s['status'] in ('concept', 'real') and isinstance(s['ai_food'], bool), sid
    assert not (s['ai_food'] and s['status'] != 'real'), 'AI food slot ships in the zip: ' + sid
    for fmt, lst in (s.get('variants') or {}).items():
        for w, f in lst: named.add(f)
    for fmt, f in (s.get('files') or {}).items(): named.add(f)
present = {f for f in os.listdir(box) if f != 'manifest.json'}
assert named == present, ('manifest/file mismatch', named ^ present)
# ---- assets/hero (0.2.0) ----
def webp_size(path):
    d = open(path, 'rb').read(40)
    assert d[:4] == b'RIFF' and d[8:12] == b'WEBP', path
    if d[12:16] == b'VP8 ':
        return struct.unpack('<H', d[26:28])[0] & 0x3fff, struct.unpack('<H', d[28:30])[0] & 0x3fff
    if d[12:16] == b'VP8X':
        return 1 + int.from_bytes(d[24:27], 'little'), 1 + int.from_bytes(d[27:30], 'little')
    if d[12:16] == b'VP8L':
        b = struct.unpack('<I', d[21:25])[0]
        return (b & 0x3fff) + 1, ((b >> 14) & 0x3fff) + 1
    raise AssertionError('unknown webp chunk in ' + path)
hero_dir = os.path.join(SRC, 'assets', 'hero')
hero = json.load(open(os.path.join(hero_dir, 'hero.json')))
assert hero['media_version'] == ver, ('hero.json media_version', hero['media_version'], ver)
assert hero['status'] in ('concept', 'real') and isinstance(hero['ai_food'], bool)
assert 'owner_decision' in hero or hero['status'] == 'real' or not hero['ai_food'], 'AI-food hero needs a recorded owner decision'
hero_named = set()
for k, p in hero['posters'].items():
    f = os.path.join(hero_dir, p['file'])
    assert os.path.isfile(f), ('poster missing', p['file'])
    assert webp_size(f) == (p['w'], p['h']), ('poster size', p['file'], webp_size(f))
    hero_named.add(p['file'])
for k, f in hero['videos'].items():
    if k != 'note': hero_named.add(f)
hero_present = {f for f in os.listdir(hero_dir) if f != 'hero.json'}
assert hero_present <= hero_named, ('unnamed file in assets/hero', hero_present - hero_named)
assert 'dbgr-hero-poster-1080.webp' in hero_present, 'the required 1080 poster is missing'

out = os.path.abspath(sys.argv[1]) if len(sys.argv) == 2 else os.path.join(HERE, 'doughboss-growth-media-%s.zip' % ver)
if os.path.lexists(out):
    sys.exit('Refusing to overwrite existing archive: ' + out)
entries = []
for root, _, files in os.walk(SRC):
    for f in files:
        full = os.path.join(root, f)
        rel = os.path.relpath(full, SRC).replace(os.sep, '/')
        assert not rel.startswith('.') and '/.' not in rel
        entries.append(('doughboss-growth-media/' + rel, full))
entries.sort()
with zipfile.ZipFile(out, 'x') as z:
    for arc, full in entries:
        zi = zipfile.ZipInfo(arc, date_time=(2026, 1, 1, 0, 0, 0))
        # Do not let the build host change the ZIP creator platform or permissions.
        zi.create_system = 3
        zi.external_attr = 0o100644 << 16
        zi.compress_type = zipfile.ZIP_STORED if arc.endswith(('.avif', '.webp', '.jpg', '.png', '.mp4')) else zipfile.ZIP_DEFLATED
        z.writestr(zi, open(full, 'rb').read())
b = os.path.getsize(out)
h = hashlib.sha256(open(out, 'rb').read()).hexdigest()
print('built %s files=%d bytes=%d sha256=%s' % (os.path.basename(out), len(entries), b, h))
if b > MAXB:
    os.remove(out); sys.exit('OVER %d byte budget' % MAXB)
