"""Hero frame v6: rebuild the TOP BAND (y 0-372) with the approved pack box stack (W5 master, real print composite).
Run from minis/:  python3 v6/build_v6_top.py"""
import numpy as np, cv2
from PIL import Image
from scipy import ndimage as ndi
F = np.asarray(Image.open('v5/bottom-v5.png').convert('RGB')).astype(np.float32)
C = np.asarray(Image.open('v5/cleanplate-bottom-v5.png').convert('RGB')).astype(np.float32)
W5 = np.asarray(Image.open('../pack/final/W5-overhead-stack-3x2.png').convert('RGB')).astype(np.float32)
H, W, _ = F.shape
BAND = 372
# ---- 1. steel plate from the clean bottom steel (texture ratio, mirrored), base colour from the existing top steel
src = F[1490:1880, 330:760]
tex = src / np.maximum(cv2.GaussianBlur(src, (0, 0), 30), 1)
tex = tex.mean(2, keepdims=True) * 0.6 + tex * 0.4          # keep brushing, mute chroma noise
row = np.concatenate([tex, tex[:, ::-1]], 1)
tile = np.concatenate([row] * 3, 1)[:, :W]
tile = np.concatenate([tile, tile[::-1]], 0)[:BAND]
tile = 1 + (tile - 1) * 0.65
lf = cv2.GaussianBlur(np.random.default_rng(9).normal(0, 1, (BAND, W)).astype(np.float32), (0, 0), 45)
tile = tile * (1 + 0.035 * lf / lf.std())[..., None]
yy, xx = np.mgrid[0:BAND, 0:W].astype(np.float32)
base_col = F[285:330, 200:880].reshape(-1, 3).mean(0)          # ~ (151,135,123)
illum = 1.0 - 0.10 * (1 - yy / BAND) - 0.06 * ((xx - W * 0.42) / W) ** 2 * 4
illum += 0.05 * np.exp(-((xx - 260) / 260) ** 2 - ((yy - 210) / 180) ** 2)   # soft key from the upper left
steel = base_col[None, None] * illum[..., None] * tile
# ---- 2. box stack cut-out from W5
s = 0.42
POLY = np.array([(513,470),(1493,470),(1505,600),(1545,860),(1532,965),(1515,1055),(673,1225),(640,1180),(600,1075),(553,905),(550,870)], np.int32)
m = np.zeros(W5.shape[:2], np.uint8); cv2.fillPoly(m, [POLY], 1)
# refine the outline: inside a 6 px band around the polygon edge, keep only dark (box) pixels
edge = cv2.dilate(m, np.ones((13, 13), np.uint8)) - cv2.erode(m, np.ones((13, 13), np.uint8))
g = cv2.GaussianBlur(W5.mean(2), (0, 0), 1.0)
m = np.where(edge > 0, (g < 60) & (cv2.dilate(m, np.ones((13, 13), np.uint8)) > 0), m).astype(np.uint8)
m = cv2.morphologyEx(m, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8)); m = ndi.binary_fill_holes(m).astype(np.uint8)
ys, xs = np.where(m); print('W5 stack bbox', xs.min(), xs.max(), ys.min(), ys.max())
core = m.astype(np.float32); alpha = cv2.GaussianBlur(core, (0, 0), 1.3)
sw, sh = int(W5.shape[1] * s), int(W5.shape[0] * s)
Ws = cv2.resize(W5, (sw, sh), interpolation=cv2.INTER_AREA); As = cv2.resize(alpha, (sw, sh), interpolation=cv2.INTER_AREA)
bottom_target, cx_target = 300, 548
oy = int(bottom_target - 1225 * s); ox = int(cx_target - 1030 * s)
print('offset', ox, oy)
box = np.zeros((BAND, W, 3), np.float32); A = np.zeros((BAND, W), np.float32)
y0, y1 = max(0, oy), min(BAND, oy + sh); x0, x1 = max(0, ox), min(W, ox + sw)
box[y0:y1, x0:x1] = Ws[y0 - oy:y1 - oy, x0 - ox:x1 - ox]; A[y0:y1, x0:x1] = As[y0 - oy:y1 - oy, x0 - ox:x1 - ox]
Cs = np.zeros((BAND, W), np.float32); cs_full = cv2.resize(core, (sw, sh), interpolation=cv2.INTER_AREA)
Cs[y0:y1, x0:x1] = cs_full[y0 - oy:y1 - oy, x0 - ox:x1 - ox]
# box blacks: neutralise and never lift (notes: lid ~36, walls ~18)
lum = box.mean(2, keepdims=True)
dark = np.clip((40 - lum) / 25, 0, 1) * Cs[..., None]
box = box * (1 - dark * 0.5) + lum * np.array([1.02, 1.0, 0.97]) * dark * 0.5
# ---- 3. shadows on the steel: soft cast (key upper left -> falls lower right) + tight contact
def shift(a, dx, dy): return ndi.shift(a, (dy, dx), order=1, mode='constant')
cast = cv2.GaussianBlur(shift(Cs, 16, 20), (0, 0), 14) * 0.50
contact = cv2.GaussianBlur(shift(Cs, 2, 3), (0, 0), 2.5) * 0.35
sh_ = 1 - np.clip(cast + contact, 0, 0.75)
plate = steel * sh_[..., None]
band = plate * (1 - A[..., None]) + box * A[..., None]
# flour specks on steel (sparse, settled), not on the box
rng = np.random.default_rng(3); sp = np.zeros((BAND, W), np.float32)
for cx, cy, n in ((120, 90, 22), (965, 150, 18), (250, 300, 8)):
    for _ in range(n):
        x = int(cx + rng.normal(0, 55)); y = int(cy + rng.normal(0, 40))
        if 0 <= x < W and 0 <= y < BAND - 25 and A[y, x] < 0.05:
            r = rng.choice([0.6, 0.9, 1.4], p=[0.6, 0.3, 0.1]); cv2.circle(sp, (x, y), 1, float(rng.uniform(0.35, 0.9)), -1)
            if r > 1: sp[y, x] = 1
sp = sp * 0  # flour specks dropped in the top band (read as stars)
band = band * (1 - np.clip(sp, 0, 1)[..., None]) + np.array([236, 230, 220]) * np.clip(sp, 0, 1)[..., None]
# grain matched to the frame
band += rng.normal(0, 2.2, band.shape[:2])[..., None]
# ---- 4. feather into the untouched rows above the tray rim
w = np.clip((yy - 330) / (BAND - 330), 0, 1)[..., None]; w = w * w * (3 - 2 * w)
outF, outC = F.copy(), C.copy()
outF[:BAND] = band * (1 - w) + F[:BAND] * w
outC[:BAND] = band * (1 - w) + C[:BAND] * w
for a, nm in ((outF, 'frame-v6'), (outC, 'cleanplate-v6')):
    im = Image.fromarray(np.clip(a, 0, 255).astype(np.uint8)); im.save(f'v6/{nm}.png')
Image.fromarray(np.clip(outF[:520], 0, 255).astype(np.uint8)).save('v6/top-v6.png')
Image.fromarray(np.clip(outF, 0, 255).astype(np.uint8)).resize((540, 960), Image.LANCZOS).save('v6/frame-v6-small.jpg', quality=88)
np.save('v6/box_alpha.npy', A); print('done')
