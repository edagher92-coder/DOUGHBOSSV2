"""One-LUT grade for the Dough Boss pack set (DP round-2 recipe).
Per-shot white-balance fit (target R/B), curve with black point 4 and soft shoulder, split-tone, saturation
rules (food cap, ember kept, steel desaturated), vignette, halation, uniform mono grain last."""
import numpy as np, cv2
from PIL import Image

CURVE_X = np.array([0, .12, .30, .50, .72, .90, 1.0]); CURVE_Y = np.array([.016, .085, .265, .50, .745, .89, .935])

def wb(img, target_rb=None, region=None):
    if target_rb is None: return img
    r = img if region is None else img[region]
    rb = r[..., 0].mean() / max(r[..., 2].mean(), 1e-3)
    k = (target_rb / rb) ** 0.5
    out = img.copy(); out[..., 0] *= k; out[..., 2] /= k
    return out

def grade(img, target_rb=None, wb_region=None, vignette=0.15, grain=1.1, seed=1, extra_gain=1.0):
    a = wb(img.astype(np.float32), target_rb, wb_region) * extra_gain
    x = np.clip(a / 255.0, 0, 1)
    x = np.interp(x, CURVE_X, CURVE_Y)
    hsv = cv2.cvtColor((x * 255).astype(np.float32), cv2.COLOR_RGB2HSV)   # H 0-360, S 0-1, V 0-255
    H, S, V = hsv[..., 0], hsv[..., 1], hsv[..., 2] / 255.0
    S *= 0.94
    food = (H >= 15) & (H <= 45); S = np.where(food, np.minimum(S, 0.62), S)
    steel = S < 0.18; S = np.where(steel, S * 0.9, S)
    hsv[..., 1] = S
    x = cv2.cvtColor(hsv, cv2.COLOR_HSV2RGB) / 255.0
    # split tone: shadows a hint cool, mids/highlights warm
    L = 0.299 * x[..., 0] + 0.587 * x[..., 1] + 0.114 * x[..., 2]
    sh = np.clip((0.25 - L) / 0.25, 0, 1)[..., None]; hi = np.clip((L - 0.6) / 0.4, 0, 1)[..., None]
    mid = (1 - sh) * (1 - hi)
    cool = np.array([-0.006, 0.0, 0.010]); warm_m = np.array([0.010, 0.004, -0.008]); warm_h = np.array([0.016, 0.006, -0.012])
    x = x + sh * cool + mid * warm_m + hi * warm_h
    h, w = L.shape
    yy, xx = np.mgrid[0:h, 0:w]; r = np.sqrt(((xx - w / 2) / (w / 2)) ** 2 + ((yy - h / 2) / (h / 2)) ** 2) / 1.414
    x *= (1 - vignette * np.clip((r - 0.3) / 0.7, 0, 1) ** 1.5)[..., None]
    # halation on speculars
    spec = np.clip((L - 225 / 255) * 8, 0, 1)
    if spec.any():
        glow = cv2.GaussianBlur(spec.astype(np.float32), (0, 0), 1.5)
        x += 0.06 * glow[..., None] * np.array([1.0, 0.75, 0.55])
    rng = np.random.default_rng(seed)
    g = rng.standard_normal((h, w)).astype(np.float32) * (grain / 255.0)
    x += g[..., None]
    return np.clip(x * 255, 0, 255).astype(np.uint8)

def run(src, dst, **kw):
    im = np.asarray(Image.open(src).convert('RGB'))
    Image.fromarray(grade(im, **kw)).save(dst)
