"""Hero reel v6 post (streaming): per-frame registration to the still, colour lock, band lock with food pass-through,
ping-pong loop with eased snap-back, light grade, PNG frames for encoding.  Run from minis/: python3 final6/post2.py"""
import os, json, numpy as np, cv2
from PIL import Image
RAW = 'final6/raw.mp4'; OUT = 'final6'
STILL8 = np.asarray(Image.open('v6/frame-v6.png').convert('RGB'))
STILL = STILL8.astype(np.float32); H, W, _ = STILL.shape
Sg = cv2.cvtColor(STILL8, cv2.COLOR_RGB2GRAY).astype(np.float32)
def frames():
    cap = cv2.VideoCapture(RAW)
    while True:
        ok, f = cap.read()
        if not ok: return
        yield cv2.cvtColor(f, cv2.COLOR_BGR2RGB)
# ---- pass 1: registration on the far bands (boxes + steel top, dough corners bottom)
mask = np.zeros((H, W), np.uint8); mask[:220] = 255; mask[1640:] = 255
crit = (cv2.TERM_CRITERIA_EPS | cv2.TERM_CRITERIA_COUNT, 200, 1e-7)
ks, Ps = [], []; w = np.eye(2, 3, dtype=np.float32)
for i, f in enumerate(frames()):
    if i % 4: continue
    g = cv2.cvtColor(f, cv2.COLOR_RGB2GRAY).astype(np.float32)
    try: cc, w = cv2.findTransformECC(Sg, g, w.copy(), cv2.MOTION_AFFINE, crit, mask, 5)
    except cv2.error: cc = 0
    ks.append(i); Ps.append(w.flatten()); print('reg', i, w.round(4).flatten().tolist(), round(cc, 4))
ks = np.array(ks); Ps = np.array(Ps)
n = int(cv2.VideoCapture(RAW).get(cv2.CAP_PROP_FRAME_COUNT))
fit = [np.polyval(np.polyfit(ks, Ps[:, j], 2), np.arange(n)) for j in range(6)]
WARP = np.stack(fit, 1).reshape(n, 2, 3).astype(np.float32)
# ---- pass 2: align, colour-lock, band-lock (food passes through), store uint8
zone = np.zeros((H, W), np.float32)
zone[:330] = 1; zone[330:372] = np.linspace(1, 0, 42)[:, None]
zone[1400:1460] = np.linspace(0, 1, 60)[:, None]; zone[1460:] = 1
static = zone > 0.99
Sl = STILL.mean(2); A = None; G = []; mad = []
for i, f in enumerate(frames()):
    a = cv2.warpAffine(f, WARP[i], (W, H), flags=cv2.INTER_LANCZOS4 | cv2.WARP_INVERSE_MAP, borderMode=cv2.BORDER_REFLECT).astype(np.float32)
    if A is None:
        A = np.array([np.polyfit(a[..., c][static], STILL[..., c][static], 1) for c in range(3)])
        print('colour fit', A.round(3).tolist())
    a = np.clip(a * A[:, 0] + A[:, 1], 0, 255)
    if i == 0: print('MAD aligned frame0 vs still', round(float(np.abs(a - STILL).mean()), 2))
    d = cv2.GaussianBlur(np.abs(a.mean(2) - Sl), (0, 0), 3)
    mv = np.clip((d - 10) / 18, 0, 1); mv = mv * mv * (3 - 2 * mv)
    mv = cv2.GaussianBlur(cv2.dilate(mv, np.ones((9, 9), np.uint8)), (0, 0), 4)
    lock = (zone * (1 - mv))[..., None]
    a = a * (1 - lock) + STILL * lock
    mad.append(float(np.abs(a - STILL).mean()))
    G.append(np.clip(a, 0, 255).astype(np.uint8))
n = len(G); print('frames', n)
# ---- timeline: [still] + forward + eased snap-back with shutter blend + settle + hold
FPS = 24
def F32(i): return G[i].astype(np.float32)
seq = [STILL8] + G[1:]
back_len = int(1.6 * FPS); t = np.linspace(0, 1, back_len); e = t * t * (3 - 2 * t); idx = (1 - e) * (n - 1)
for k, p in enumerate(idx):
    i0 = int(np.floor(p)); i1 = min(i0 + 1, n - 1); al = p - i0
    fr = F32(i0) * (1 - al) + F32(i1) * al
    sp = abs(idx[min(k + 1, back_len - 1)] - idx[max(k - 1, 0)]) / 2
    if sp > 1.5:
        j0, j2 = int(np.clip(p - sp * 0.4, 0, n - 1)), int(np.clip(p + sp * 0.4, 0, n - 1))
        fr = (F32(j0) + fr * 2 + F32(j2)) / 4
    seq.append(np.clip(fr, 0, 255).astype(np.uint8))
last = seq[-1].astype(np.float32); land = int(0.35 * FPS)
for k in range(land):
    al = (k + 1) / land; al = al * al * (3 - 2 * al); seq.append(np.clip(last * (1 - al) + STILL * al, 0, 255).astype(np.uint8))
hold = int(0.6 * FPS); seq += [STILL8] * hold
# ---- grade + grain; the still frames get one fixed grain so the loop seam is bit-identical
os.makedirs(f'{OUT}/frames', exist_ok=True)
for f in os.listdir(f'{OUT}/frames'): os.remove(f'{OUT}/frames/{f}')
g0 = np.random.default_rng(0).normal(0, 1.6, (H, W))[..., None]
for i, f in enumerate(seq):
    x = f.astype(np.float32) / 255.0
    x = np.clip(x + 0.012 * np.sin(np.pi * x) * np.array([1.0, 0.4, -0.6]), 0, 1)
    is_still = (f is STILL8)
    gr = g0 if is_still else np.random.default_rng(i).normal(0, 1.6, (H, W))[..., None]
    cv2.imwrite(f'{OUT}/frames/{i:04d}.png', cv2.cvtColor(np.clip(x * 255 + gr, 0, 255).astype(np.uint8), cv2.COLOR_RGB2BGR))
print('loop frames', len(seq), 'seconds', round(len(seq) / FPS, 2))
json.dump({'band_mad': mad, 'frames': len(seq)}, open(f'{OUT}/qc.json', 'w'))
