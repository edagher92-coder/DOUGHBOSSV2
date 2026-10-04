"""Composite exact vector artwork onto a photographed blank surface, as print.
art_rgba: flat artwork with alpha (ink only). quad: 4 photo points (TL, TR, BR, BL) of the art's rectangle.
Ink recipe (VFX round 1): opacity 0.84, fibre dropout ~5 %, 0.4 px noisy edge, 2-3 px grain displacement,
ink brightness follows the surface light (low-pass of the blank), white ink multiplied ~15 % by card luma."""
import numpy as np, cv2
from PIL import Image

def art_from_black(path):
    """Artwork rendered on pure black -> RGBA (premultiplied recovery)."""
    a = np.asarray(Image.open(path).convert('RGB')).astype(np.float32)
    alpha = np.clip(a.max(2) / 238.0, 0, 1)            # cream ink peak ~238; ember R 226
    alpha[:4, :] = alpha[-4:, :] = 0; alpha[:, :4] = alpha[:, -4:] = 0   # kill raster edge artefacts
    rgb = np.where(alpha[..., None] > 1e-3, a / np.maximum(alpha[..., None], 1e-3), 0)
    return np.dstack([np.clip(rgb, 0, 255), alpha * 255]).astype(np.float32)

WEAR = None   # optional shared per-box wear map (H, W) in 0..1, set by the caller

def composite(photo, art, quad, seed=7, opacity=0.82, dropout=0.03, disp=1.5, edge=0.4, light_gain=0.4, blur=0.4):
    H, W = photo.shape[:2]; h, w = art.shape[:2]
    src = np.float32([[0, 0], [w, 0], [w, h], [0, h]]); dst = np.float32(quad)
    M = cv2.getPerspectiveTransform(src, dst)
    warped = cv2.warpPerspective(art, M, (W, H), flags=cv2.INTER_LANCZOS4, borderValue=(0, 0, 0, 0))
    rgb, al = warped[..., :3], warped[..., 3] / 255.0
    lum = cv2.cvtColor(photo.astype(np.uint8), cv2.COLOR_RGB2GRAY).astype(np.float32)
    rng = np.random.default_rng(seed)
    # grain displacement: shift ink along the card grain (high-pass of the blank surface)
    hp = lum - cv2.GaussianBlur(lum, (0, 0), 3)
    gx = cv2.Sobel(cv2.GaussianBlur(hp, (0, 0), 1.2), cv2.CV_32F, 1, 0); gy = cv2.Sobel(cv2.GaussianBlur(hp, (0, 0), 1.2), cv2.CV_32F, 0, 1)
    n = max(1e-3, np.percentile(np.abs(gx) + np.abs(gy), 99))
    mx, my = np.meshgrid(np.arange(W, dtype=np.float32), np.arange(H, dtype=np.float32))
    mapx = mx + disp * gx / n; mapy = my + disp * gy / n
    al = cv2.remap(al.astype(np.float32), mapx, mapy, cv2.INTER_LINEAR)
    rgb = cv2.remap(rgb.astype(np.float32), mapx, mapy, cv2.INTER_LINEAR)
    # noisy edge + fibre dropout (ink skips the deepest fibre pits)
    noise = cv2.GaussianBlur(rng.standard_normal((H, W)).astype(np.float32), (0, 0), 0.8)
    al = np.clip(al + edge * 0.25 * noise * (al * (1 - al) * 4), 0, 1)
    pits = (hp < np.percentile(hp, 100 * dropout)).astype(np.float32)
    wear = WEAR if (WEAR is not None and WEAR.shape == al.shape) else 0.0
    al = al * (1 - 0.45 * cv2.GaussianBlur(pits, (0, 0), 0.6)) * (1 - 0.6 * wear)
    if blur: al = cv2.GaussianBlur(al.astype(np.float32), (0, 0), blur); rgb = cv2.GaussianBlur(rgb, (0, 0), blur)
    # ink takes the surface light: brighter where the window light falls, darker away from it
    low = cv2.GaussianBlur(lum, (0, 0), 25)
    ref = np.median(low[al > 0.5]) if (al > 0.5).any() else low.mean()
    shade = np.clip(1 + light_gain * (low - ref) / max(ref, 1), 0.6, 1.25)
    card = np.clip(lum / max(np.median(lum[al > 0.5]) if (al > 0.5).any() else 1, 1), 0.6, 1.4)
    ink = rgb * shade[..., None] * (0.85 + 0.15 * card[..., None])
    red = np.clip((rgb[..., 0] - rgb[..., 1]) / 120.0, 0, 1)          # ember areas of the art
    a = (np.maximum(opacity, red * 0.98) * al)[..., None]
    return np.clip(photo * (1 - a) + ink * a, 0, 255)

def art_from_white(path, ink=(26, 23, 22)):
    """Dark ink rendered on white -> RGBA with a fixed ink colour (for 1-colour black on kraft)."""
    a = np.asarray(Image.open(path).convert('L')).astype(np.float32)
    alpha = np.clip((255 - a) / 230.0, 0, 1)
    alpha[:4, :] = alpha[-4:, :] = 0; alpha[:, :4] = alpha[:, -4:] = 0
    h, w = alpha.shape
    rgb = np.ones((h, w, 3), np.float32) * np.float32(ink)
    return np.dstack([rgb, alpha * 255]).astype(np.float32)

def composite_dark(photo, art, quad, seed=11, opacity=0.88, disp=2.0):
    """Dark ink on kraft: the ink darkens the paper (multiply-like), takes the fibre and the light."""
    H, W = photo.shape[:2]; h, w = art.shape[:2]
    M = cv2.getPerspectiveTransform(np.float32([[0, 0], [w, 0], [w, h], [0, h]]), np.float32(quad))
    warped = cv2.warpPerspective(art, M, (W, H), flags=cv2.INTER_LANCZOS4, borderValue=(0, 0, 0, 0))
    al = warped[..., 3] / 255.0
    lum = cv2.cvtColor(photo.astype(np.uint8), cv2.COLOR_RGB2GRAY).astype(np.float32)
    hp = lum - cv2.GaussianBlur(lum, (0, 0), 3)
    rng = np.random.default_rng(seed)
    noise = cv2.GaussianBlur(rng.standard_normal((H, W)).astype(np.float32), (0, 0), 0.8)
    al = np.clip(al * (1 - 0.25 * np.clip(hp / 25, 0, 1)) + 0.08 * noise * al * (1 - al) * 4, 0, 1)
    ink = photo * 0.16 + 6                                # ink: the paper darkened, keeps its light
    a = (opacity * al)[..., None]
    return np.clip(photo * (1 - a) + ink * a, 0, 255)


def wear_map(shape, edge_quads, seed=5):
    """One wear map per box: low-frequency noise x proximity to box edges/folds (more wear within ~25 mm of an edge).
    edge_quads: list of face quads in px; their outlines are the edges."""
    H, W = shape
    rng = np.random.default_rng(seed)
    n = cv2.GaussianBlur(rng.standard_normal((H // 8 + 1, W // 8 + 1)).astype(np.float32), (0, 0), 15)
    n = cv2.resize(n, (W, H)); n = (n - n.min()) / max(1e-6, n.max() - n.min())
    edges = np.zeros((H, W), np.uint8)
    for q in edge_quads:
        cv2.polylines(edges, [np.int32(q).reshape(-1, 1, 2)], True, 255, 2)
    dist = cv2.distanceTransform(255 - edges, cv2.DIST_L2, 5)
    near = np.clip(1 - dist / 70.0, 0, 1)
    fine = cv2.GaussianBlur(rng.random((H, W)).astype(np.float32), (0, 0), 1.2)
    fine = (fine > np.percentile(fine, 92)).astype(np.float32)
    return np.clip(0.03 + 0.05 * near * n + 0.10 * near * fine + 0.02 * n, 0, 0.25) / 0.25 * 0.15
