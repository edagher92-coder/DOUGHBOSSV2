"""Lid opening on the top catering box at the end of the hero loop.
Hinged FEFCO 0427 lid: rotates about the back edge (off-frame), lid foreshortens and magnifies toward the camera,
tuck flap swings out, the lid shadows the interior, the seal tears at the fold. Builds:
  final7/frames (loop: burst + snap-back + open + hold + close, last frame == first)   and   final7/play (ends open)."""
import os, shutil, numpy as np, cv2
from PIL import Image
STILL = np.asarray(Image.open('v6/frame-v6.png').convert('RGB')).astype(np.float32)
H, W, _ = STILL.shape
INT = np.asarray(Image.open('lid/interior.png').convert('RGB')).astype(np.float32)
S, OX, OY = 0.42, 115, -214                                   # W5 -> frame mapping used for the top band
def m(p): return np.array([OX + S * p[0], OY + S * p[1]], np.float32)
TL, TR, BR, BL = m((572, 150)), m((1420, 125)), m((1510, 862)), m((575, 880))   # top box lid outline
C = np.array([540.0, 960.0]); ZC = 2200.0                      # principal point, camera height (px at box-top scale)
PXMM = np.linalg.norm(BR - BL) / 385.0                         # ~1.0 px per mm in the band
def P0(u, v):                                                  # lid point at rest (u across, v hinge->front)
    top = TL + (TR - TL) * u; bot = BL + (BR - BL) * u; return top + (bot - top) * v
def proj(u, v, th):
    h = P0(u, 0); q = P0(u, v); d = q - h
    w = h + d * np.cos(th); z = np.linalg.norm(d) * np.sin(th)
    return C + (w - C) * ZC / (ZC - z), z
# interior plate -> box opening (inset 2 px)
src = np.float32([[0, 0], [INT.shape[1], 0], [INT.shape[1], INT.shape[0]], [0, INT.shape[0]]])
ins = np.float32([TL + [3, 2], TR + [-3, 2], BR + [-3, -2], BL + [3, -2]])
Mi = cv2.getPerspectiveTransform(src, ins)
interior = cv2.warpPerspective(INT, Mi, (W, H), flags=cv2.INTER_AREA)
imask = cv2.warpPerspective(np.ones(INT.shape[:2], np.float32), Mi, (W, H))
open_mask = np.zeros((H, W), np.float32); cv2.fillPoly(open_mask, [np.int32(np.round([TL, TR, BR, BL]))], 1.0)
# box-interior wall details: rim along the front fold, dark inner face down the left wall
yy, xx = np.mgrid[0:H, 0:W].astype(np.float32)
def line_dist(a, b):
    ab = b - a; t = np.clip(((xx - a[0]) * ab[0] + (yy - a[1]) * ab[1]) / (ab @ ab), 0, 1)
    return np.hypot(xx - (a[0] + t * ab[0]), yy - (a[1] + t * ab[1]))
dF, dL, dR = line_dist(BL, BR), line_dist(TL, BL), line_dist(TR, BR)
interior *= (1 - 0.65 * np.exp(-dF / 7))[..., None]            # front wall hides the floor edge (camera is south)
interior *= (1 - 0.60 * np.exp(-dL / 24))[..., None]           # the left wall shades the floor (key from the upper left)
interior *= 0.80; interior *= np.array([1.0, 0.97, 0.92])
rim = np.exp(-(dF / 1.6) ** 2) + np.exp(-(dL / 1.4) ** 2) + np.exp(-(dR / 1.4) ** 2)
interior = interior * (1 - 0.8 * rim[..., None]) + np.array([24, 22, 21]) * 0.8 * rim[..., None]
interior += (np.exp(-((dF - 2.2) / 0.8) ** 2) * 22)[..., None] * imask[..., None]   # 1 px highlight on the fold
# lid source (the closed lid as it is in the still), seal torn edge prepared
lid_src = STILL.copy()
SEAL_C, SEAL_R = m((1335, 880)), 0.42 * 75
rng = np.random.default_rng(8)
def torn_edge(img, y_line_fn, alpha, side):
    xs = np.arange(int(SEAL_C[0] - SEAL_R * 0.95), int(SEAL_C[0] + SEAL_R * 0.95))
    jit = np.cumsum(rng.normal(0, 0.45, len(xs))); jit -= jit.mean(); jit = np.clip(jit, -1.6, 1.6)
    for x, j in zip(xs, jit):
        y = y_line_fn(x) + j + (1.0 if side == 'wall' else -1.0)
        for dy, a in ((0, 0.9), (side == 'wall' and 1 or -1, 0.35)):
            yi = int(round(y + dy))
            if 0 <= yi < H: img[yi, x] = img[yi, x] * (1 - 0.65 * a * alpha) + np.array([200, 184, 152]) * 0.65 * a * alpha
def fold_y(x): t = (x - BL[0]) / (BR[0] - BL[0]); return BL[1] + (BR[1] - BL[1]) * t
torn_edge(lid_src, fold_y, 1.0, 'lid')
NS = 70; V0 = 0.40
def render(th, tear_alpha):
    f = STILL.copy()
    if th <= 1e-4 and tear_alpha <= 0: return f
    # projected lid front edge per column, for the interior shadow
    fe = np.array([proj(u, 1.0, th)[0] for u in np.linspace(0, 1, 50)])
    yf = np.interp(xx[0], fe[:, 0], fe[:, 1], left=np.inf, right=np.inf)
    dist = np.clip(yy - yf[None, :], 0, None)
    sh = 1 - 0.6 * np.exp(-dist / (18 + 140 * np.sin(th))) * (1 - 0.55 * np.sin(th))
    inside = (open_mask * imask)[..., None] * (th > 1e-4)
    f = f * (1 - inside) + (interior * sh[..., None]) * inside
    # seal: the half on the front wall stays, with a torn top edge
    torn_edge(f, fold_y, tear_alpha, 'wall')
    if th <= 1e-4: return f
    # lid strips, back to front
    lid_layer = np.zeros_like(f); lid_a = np.zeros((H, W), np.float32)
    g = 1 - 0.30 * np.sin(th)
    vs = np.linspace(V0, 1, NS + 1)
    for k in range(NS):
        va, vb = vs[k], vs[k + 1]
        s4 = np.float32([P0(0, va), P0(1, va), P0(1, vb), P0(0, vb)])
        d4 = np.float32([proj(0, va, th)[0], proj(1, va, th)[0], proj(1, vb, th)[0], proj(0, vb, th)[0]])
        if d4[:, 1].max() < -2: continue
        Mk = cv2.getPerspectiveTransform(s4, d4)
        msk = np.zeros((H, W), np.float32); cv2.fillPoly(msk, [np.int32(np.round(s4))], 1.0)
        wm = cv2.warpPerspective(msk, Mk, (W, H), flags=cv2.INTER_LINEAR)
        wi = cv2.warpPerspective(lid_src, Mk, (W, H), flags=cv2.INTER_LINEAR)
        lid_layer = lid_layer * (1 - wm[..., None]) + wi * wm[..., None]; lid_a = np.maximum(lid_a, wm)
    lid_layer *= g
    # tuck flap: swings out of the box from the lid's front edge; visible above the rim
    fl = 45 * PXMM; zf = np.linalg.norm(P0(0.5, 1) - P0(0.5, 0)) * np.sin(th)
    vis = np.clip(zf / max(fl * np.cos(th), 1e-3), 0, 1)
    if vis > 0.02:
        a0, a1 = proj(0.02, 1, th)[0], proj(0.98, 1, th)[0]
        dirv = (P0(0.5, 1) - P0(0.5, 0)); dirv = dirv / np.linalg.norm(dirv)
        off = dirv * fl * np.sin(th) * vis
        quad = np.int32(np.round([a0, a1, a1 + off, a0 + off]))
        fm = np.zeros((H, W), np.float32); cv2.fillPoly(fm, [quad], 1.0); fm = cv2.GaussianBlur(fm, (0, 0), 0.7)
        flap = np.array([20, 18, 17], np.float32) * (0.9 + 0.3 * np.sin(th))
        f = f * (1 - fm[..., None]) + flap * fm[..., None]
    # soft shadow of the lifted lid on the steel to the right of the box
    shl = cv2.GaussianBlur(np.roll(np.roll(lid_a, int(10 + 30 * np.sin(th)), 1), int(14 + 20 * np.sin(th)), 0), (0, 0), 10)
    outside = 1 - open_mask
    f *= (1 - 0.35 * shl * outside * np.sin(th) * 1.2)[..., None].clip(0, 1) * 1.0
    f = f * (1 - lid_a[..., None]) + lid_layer * lid_a[..., None]
    return f
def grade(x8):
    x = x8 / 255.0
    x = np.clip(x + 0.012 * np.sin(np.pi * x) * np.array([1.0, 0.4, -0.6]), 0, 1)
    return np.clip(x * 255 + G0, 0, 255).astype(np.uint8)
G0 = np.random.default_rng(0).normal(0, 1.6, (H, W))[..., None]
def ss(t): t = np.clip(t, 0, 1); return t * t * t * (t * (6 * t - 15) + 10)
FPS = 24; TH = np.radians(64)
opening = [TH * ss(k / 19) + np.radians(2.0) * np.sin(np.pi * min(1, k / 19)) ** 8 for k in range(1, 20)]
hold = [TH] * 38
closing = [TH * (1 - (k / 17) ** 2) for k in range(1, 18)]          # lid drops (ease-in)
settle = [np.radians(0.6), 0.0, np.radians(0.25), 0.0]
for out in ('final7/frames', 'final7/play'):
    shutil.rmtree(out, ignore_errors=True); os.makedirs(out)
base = sorted(os.listdir('final6/frames'))[:166]                       # burst, snap-back, land
for i, fn in enumerate(base):
    shutil.copy(f'final6/frames/{fn}', f'final7/frames/{i:04d}.png'); shutil.copy(f'final6/frames/{fn}', f'final7/play/{i:04d}.png')
n = 166
def put(dirn, idx, img): cv2.imwrite(f'{dirn}/{idx:04d}.png', cv2.cvtColor(grade(img), cv2.COLOR_RGB2BGR))
seq = [(th, 1.0) for th in opening] + [(th, 1.0) for th in hold]
for k, (th, ta) in enumerate(seq):
    img = render(th, ta); put('final7/frames', n + k, img); put('final7/play', n + k, img)
p = n + len(seq)
for k in range(24):                                                     # single-play cut: one more second open, then ends
    put('final7/play', p + k, render(TH, 1.0))
tail = [(th, 1.0) for th in closing] + [(th, max(0.0, 1 - (k + 1) / 4)) for k, th in enumerate(settle)] + [(0.0, 0.0)] * 8
for k, (th, ta) in enumerate(tail):
    put('final7/frames', p + k, render(th, ta))
print('loop frames', p + len(tail), 'play frames', p + 24)
