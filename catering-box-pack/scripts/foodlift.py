"""Masked food lift (food stylist round 2): +0.4 stop on bake tops, greens separated from olive-brown,
a soft specular roll on cheese, no bloom. Mask = saturated warm/green pixels inside a region polygon."""
import numpy as np, cv2
from PIL import Image

def lift(img, region_poly, stop=0.25, green_sat=1.10, clarity=-0.25, crust_sat=0.92, spinach_fix=False):
    a = img.astype(np.float32) / 255.0
    hsv = cv2.cvtColor(a, cv2.COLOR_RGB2HSV)
    H, S, V = hsv[..., 0], hsv[..., 1], hsv[..., 2]
    reg = np.zeros(img.shape[:2], np.uint8); cv2.fillPoly(reg, [np.int32(region_poly)], 1)
    warm = (H >= 12) & (H <= 55) & (S > 0.30) & (V > 0.12)
    green = (H > 55) & (H <= 140) & (S > 0.18) & (V > 0.08)
    food = ((warm | green) & (reg > 0)).astype(np.float32)
    food = cv2.morphologyEx(food, cv2.MORPH_CLOSE, np.ones((9, 9), np.uint8))
    m = cv2.GaussianBlur(food, (0, 0), 6)
    gain = 2 ** stop
    # cut local contrast on the bakes (less HDR/waxy), then lift
    Vb = cv2.GaussianBlur(V, (0, 0), 6)
    V = V + clarity * (V - Vb) * m
    V2 = np.clip(V * (1 + (gain - 1) * m), 0, 1)
    S = np.where(warm, S * (1 - (1 - crust_sat) * m), S)
    # keep the darkest crust detail: soft shoulder so highlights don't clip
    V2 = np.where(V2 > 0.85, 0.85 + (V2 - 0.85) * 0.5, V2)
    gm = cv2.GaussianBlur(green.astype(np.float32) * (reg > 0), (0, 0), 4)
    S2 = np.clip(S * (1 + (green_sat - 1) * gm), 0, 1)
    H2 = H + gm * np.clip(95 - H, -8, 8) * 0.5                     # nudge greens toward green, away from olive
    # cheese specular: brightest warm low-sat blister tops get a tiny lift
    spec = ((H >= 25) & (H <= 50) & (V > 0.62) & (S < 0.5) & (reg > 0)).astype(np.float32)
    spec = cv2.GaussianBlur(spec, (0, 0), 2) * 0.03
    V2 = np.clip(V2 + spec, 0, 1)
    if spinach_fix:
        # whole-leaf look -> chopped cooked spinach: kill leaf ribs, add fine chopped texture, glossier and darker
        g = cv2.GaussianBlur(green.astype(np.float32) * (reg > 0), (0, 0), 1.5)
        Vs = cv2.GaussianBlur(V2, (0, 0), 3.0)
        rng = np.random.default_rng(4)
        chop = cv2.GaussianBlur(rng.standard_normal(V2.shape).astype(np.float32), (0, 0), 1.1) * 0.06
        gloss = (cv2.GaussianBlur(rng.random(V2.shape).astype(np.float32), (0, 0), 0.9) > 0.62).astype(np.float32) * 0.10
        V2 = V2 * (1 - g) + np.clip(Vs * 0.88 + chop + gloss * Vs, 0, 1) * g
        S2 = np.clip(S2 * (1 + 0.12 * g), 0, 1)
    out = cv2.cvtColor(np.dstack([H2, S2, V2]).astype(np.float32), cv2.COLOR_HSV2RGB)
    return np.clip(out * 255, 0, 255).astype(np.uint8)
