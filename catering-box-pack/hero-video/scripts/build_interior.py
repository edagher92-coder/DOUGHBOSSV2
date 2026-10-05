"""Interior plate of the open dozen box (12 bakes, 4 x 3, on greaseproof parchment), built from the panel-approved
bake tiles W7-W10. Plate space = box interior, 4 px/mm, 385 x 290 mm -> 1540 x 1160 px, front wall at the bottom."""
import numpy as np, cv2
from PIL import Image
P = '../pack/final/'
TILES = {'cheese': 'W7-tile-cheese', 'zaatar': 'W8-tile-zaatar', 'meat': 'W9-tile-meat', 'spinach': 'W10-tile-spinach'}
rng = np.random.default_rng(21)
def cut(name):
    a = np.asarray(Image.open(P + TILES[name] + '.png').convert('RGB'))
    s = 720 / a.shape[1]; a = cv2.resize(a, (720, int(a.shape[0] * s)), interpolation=cv2.INTER_AREA)
    h, w, _ = a.shape
    hsv = cv2.cvtColor(a, cv2.COLOR_RGB2HSV).astype(np.float32)
    thr = (hsv[..., 1] / 255 > 0.40) & ((hsv[..., 2] / 255 > 0.16) if name == 'spinach' else True)
    seed = cv2.GaussianBlur(thr.astype(np.float32), (0, 0), 4) > 0.5
    n, lab, st, _ = cv2.connectedComponentsWithStats(seed.astype(np.uint8)); k = 1 + int(np.argmax(st[1:, cv2.CC_STAT_AREA]))
    seed = (lab == k).astype(np.uint8)
    cnts, _ = cv2.findContours(seed, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE); c = max(cnts, key=cv2.contourArea)
    fgm = np.zeros_like(seed)
    if name == 'spinach':
        cv2.fillPoly(fgm, [cv2.convexHull(c)], 1)
    else:
        (ex, ey), (ma, mi), an = cv2.fitEllipse(c)
        cv2.ellipse(fgm, ((ex, ey), (ma * 0.985, mi * 0.985), an), 1, -1)
    print(name, 'mask area frac', round(fgm.mean(), 3))
    al = cv2.GaussianBlur(fgm.astype(np.float32), (0, 0), 1.4)
    ys, xs = np.where(fgm > 0); y0, y1, x0, x1 = ys.min(), ys.max(), xs.min(), xs.max()
    rgb = a[y0:y1 + 1, x0:x1 + 1].astype(np.float32); al = al[y0:y1 + 1, x0:x1 + 1]
    # undo the tile vignette a little on the bake (lift ~0.3 stop, keep colour)
    rgb = np.clip(rgb * 1.22, 0, 255)
    return rgb, al
CUT = {k: cut(k) for k in TILES}
for k, (r, a) in CUT.items(): Image.fromarray(np.dstack([r, a * 255]).astype(np.uint8), 'RGBA').save(f'lid/cut-{k}.png')
# ---- procedural crumpled parchment (no tiling seams)
Wp, Hp = 1540, 1160
def noise(sig, seed):
    n = cv2.GaussianBlur(np.random.default_rng(seed).normal(0, 1, (Hp, Wp)).astype(np.float32), (0, 0), sig); return n / n.std()
lum = 1 + 0.05 * noise(120, 1) + 0.035 * noise(35, 2) + 0.015 * noise(6, 3)
cr = np.zeros((Hp, Wp), np.float32); r = np.random.default_rng(4)
for _ in range(26):                                                 # soft crease ridges
    x0, y0 = r.uniform(0, Wp), r.uniform(0, Hp); ang = r.uniform(0, np.pi); L = r.uniform(200, 700)
    x1, y1 = x0 + L * np.cos(ang), y0 + L * np.sin(ang)
    cv2.line(cr, (int(x0), int(y0)), (int(x1), int(y1)), float(r.choice([-1, 1]) * r.uniform(0.5, 1)), 3, cv2.LINE_AA)
cr = cv2.GaussianBlur(cr, (0, 0), 4)
lum = lum + 0.06 * cr + 0.03 * np.roll(cr, 6, axis=0) * -1
plate = np.array([168, 152, 128], np.float32)[None, None] * lum[..., None]
# ---- 12 bakes, 4 x 3
order = [['meat', 'spinach', 'cheese', 'zaatar'], ['spinach', 'cheese', 'zaatar', 'meat'], ['cheese', 'zaatar', 'meat', 'spinach']]
shadow = np.zeros((Hp, Wp), np.float32); halo = np.zeros((Hp, Wp), np.float32)
layers = []
for j in range(3):
    for i in range(4):
        kind = order[j][i]; rgb, al = CUT[kind]
        size = 4 * 88 * rng.uniform(0.97, 1.03)
        sc = size / max(rgb.shape[:2]); r2 = cv2.resize(rgb, None, fx=sc, fy=sc, interpolation=cv2.INTER_AREA); a2 = cv2.resize(al, None, fx=sc, fy=sc, interpolation=cv2.INTER_AREA)
        ang = rng.uniform(-14, 14) + (180 if kind == 'spinach' and (i + j) % 2 else 0)
        hh, ww = a2.shape; M = cv2.getRotationMatrix2D((ww / 2, hh / 2), ang, 1.0)
        cos, sin = abs(M[0, 0]), abs(M[0, 1]); nw, nh = int(hh * sin + ww * cos), int(hh * cos + ww * sin)
        M[0, 2] += nw / 2 - ww / 2; M[1, 2] += nh / 2 - hh / 2
        r2 = cv2.warpAffine(r2, M, (nw, nh), flags=cv2.INTER_LINEAR); a2 = cv2.warpAffine(a2, M, (nw, nh), flags=cv2.INTER_LINEAR)
        cx = 4 * (48.1 + 96.25 * i + rng.uniform(-4, 4)); cy = 4 * (48.3 + 96.7 * j + rng.uniform(-4, 4))
        x0, y0 = int(cx - nw / 2), int(cy - nh / 2)
        layers.append((x0, y0, r2, a2))
        def stamp(buf, img, dx=0, dy=0, mode='max'):
            xa, ya = max(0, x0 + dx), max(0, y0 + dy); xb, yb = min(Wp, x0 + dx + nw), min(Hp, y0 + dy + nh)
            if xb <= xa or yb <= ya: return
            sub = img[ya - (y0 + dy):yb - (y0 + dy), xa - (x0 + dx):xb - (x0 + dx)]
            buf[ya:yb, xa:xb] = np.maximum(buf[ya:yb, xa:xb], sub)
        stamp(shadow, a2, 10, 13); stamp(halo, cv2.dilate(a2, np.ones((25, 25), np.uint8)))
plate *= (1 - 0.45 * cv2.GaussianBlur(shadow, (0, 0), 9))[..., None]
gh = cv2.GaussianBlur(halo, (0, 0), 16)
spots = np.clip(gh * (0.6 + 0.4 * noise(25, 7)), 0, 1.2)
plate *= (1 - 0.20 * spots)[..., None] * (np.array([1.0, 0.95, 0.82])[None, None] ** spots[..., None])
for x0, y0, r2, a2 in layers:
    nh, nw = a2.shape; xa, ya = max(0, x0), max(0, y0); xb, yb = min(Wp, x0 + nw), min(Hp, y0 + nh)
    sr = r2[ya - y0:yb - y0, xa - x0:xb - x0]; sa = a2[ya - y0:yb - y0, xa - x0:xb - x0][..., None]
    plate[ya:yb, xa:xb] = plate[ya:yb, xa:xb] * (1 - sa) + sr * sa
# ---- inside-the-box light: darker toward the walls and the back, warm ambient
yy, xx = np.mgrid[0:Hp, 0:Wp].astype(np.float32)
ao = np.minimum.reduce([xx, Wp - xx, yy, Hp - yy]); ao = 1 - 0.32 * np.exp(-ao / 70)
grad = 0.80 + 0.12 * (yy / Hp) + 0.05 * (1 - xx / Wp)
plate = np.clip(plate * (ao * grad)[..., None], 0, 255)
Image.fromarray(plate.astype(np.uint8)).save('lid/interior.png')
Image.fromarray(plate.astype(np.uint8)).resize((770, 580), Image.LANCZOS).save('lid/interior-small.jpg', quality=88)
print('ok')
