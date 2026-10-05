"""Plane mapping helpers: art in mm on a face -> photo pixels via a homography from the face's 4 corners."""
import numpy as np, cv2
from PIL import Image
import printcomp as P

def H_face(quad_px, w_mm, h_mm):
    src = np.float32([[0, 0], [w_mm, 0], [w_mm, h_mm], [0, h_mm]])
    return cv2.getPerspectiveTransform(src, np.float32(quad_px))

def mm_to_px(H, pts):
    p = cv2.perspectiveTransform(np.float32(pts).reshape(-1, 1, 2), H)
    return p.reshape(-1, 2)

def place(photo, art, H, x_mm, y_mm, w_mm, h_mm, dark=False, **kw):
    q = mm_to_px(H, [[x_mm, y_mm], [x_mm + w_mm, y_mm], [x_mm + w_mm, y_mm + h_mm], [x_mm, y_mm + h_mm]])
    return (P.composite_dark if dark else P.composite)(photo, art, q, **kw)

def seal_rgba(path, crop_frac=0.92):
    """Photographed flat seal -> circular RGBA (kraft sticker with handwriting)."""
    im = np.asarray(Image.open(path).convert('RGB')).astype(np.float32)
    h, w = im.shape[:2]
    g = cv2.cvtColor(im.astype(np.uint8), cv2.COLOR_RGB2GRAY)
    # find the sticker circle: bright kraft against black card
    m = (g > 90).astype(np.uint8)
    cnts, _ = cv2.findContours(m, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    c = max(cnts, key=cv2.contourArea); (cx, cy), r = cv2.minEnclosingCircle(c)
    r *= 0.995
    x0, y0 = int(cx - r), int(cy - r); s = int(2 * r)
    crop = im[y0:y0 + s, x0:x0 + s]
    yy, xx = np.mgrid[0:s, 0:s]
    d = np.sqrt((xx - s / 2) ** 2 + (yy - s / 2) ** 2)
    a = np.clip((s / 2 - d) / 2.0, 0, 1)
    return np.dstack([crop, a * 255]).astype(np.float32)

def paste_paper(photo, rgba, quad, shade_from=None, shadow=True, top_ao=0.0, paper_gain=0.78):
    """Paste an opaque paper element (sticker) with the surface light and a thin contact shadow."""
    H, W = photo.shape[:2]; h, w = rgba.shape[:2]
    M = cv2.getPerspectiveTransform(np.float32([[0, 0], [w, 0], [w, h], [0, h]]), np.float32(quad))
    wp = cv2.warpPerspective(rgba, M, (W, H), flags=cv2.INTER_LANCZOS4, borderValue=(0, 0, 0, 0))
    a = wp[..., 3:4] / 255.0
    lum = cv2.cvtColor(photo.astype(np.uint8), cv2.COLOR_RGB2GRAY).astype(np.float32)
    low = cv2.GaussianBlur(lum, (0, 0), 40)
    ref = np.median(low[a[..., 0] > 0.5]) if (a > 0.5).any() else low.mean()
    shade = np.clip(0.50 + 0.50 * low / max(ref, 1), 0.4, 1.15)[..., None]   # sticker sits in the scene light
    out = photo.copy()
    if shadow:
        a0 = a[..., 0]
        soft = cv2.GaussianBlur(np.roll(np.roll(a0, 5, 0), 5, 1), (0, 0), 6.5)[..., None]  # penumbra along the key light
        rim = cv2.GaussianBlur(a0, (0, 0), 0.8)[..., None] - a                              # tight 1 px dark edge line
        out = out * (1 - 0.20 * soft * (1 - a)) * (1 - 0.11 * np.clip(rim, 0, 1))
    # paper falls to ~0.65 of scene light on the side away from the key (left key -> darker right side)
    ys, xs = np.nonzero(a[..., 0] > 0.5)
    side = np.ones(a.shape[:2], np.float32)
    if len(xs):
        x0, x1 = xs.min(), xs.max()
        gx = np.clip((np.arange(a.shape[1]) - x0) / max(1, x1 - x0), 0, 1)
        side = (1 - 0.18 * gx)[None, :].repeat(a.shape[0], 0)
    paper = wp[..., :3] * shade * paper_gain * side[..., None]
    if top_ao:
        # ambient occlusion where the sticker tucks under the lip of the box above (top edge of the quad)
        q = np.float32(quad); M2 = cv2.getPerspectiveTransform(np.float32([[0, 0], [w, 0], [w, h], [0, h]]), q)
        yy = np.tile(np.linspace(0, 1, h, dtype=np.float32)[:, None], (1, w))
        ao = cv2.warpPerspective(yy, M2, (W, H), borderValue=1.0)
        px_h = np.linalg.norm(q[3] - q[0]); band = 8.0 / max(px_h, 1)
        paper = paper * (1 - top_ao * np.clip(1 - ao / band, 0, 1))[..., None]
    return out * (1 - a) + paper * a


def apply_seal(photo, seal, H_lid, H_wall, cx_mm, lid_depth_mm, r=35.0, cap_frac=0.30, deg=0.0, rng_seed=3, wall_v=1.0, lid_v=1.0):
    """One sticker, folded over the lid's front edge: the top cap_frac of the circle lies on the lid,
    the rest on the front wall. Both halves map the fold chord to the same image points, so the ring is
    continuous. Adds a crease highlight + soft shadow along the fold and a thin lift shadow round the edge."""
    s = seal.shape[0]
    if deg:
        M = cv2.getRotationMatrix2D((s / 2, s / 2), deg, 1)
        seal = cv2.warpAffine(seal, M, (s, s), flags=cv2.INTER_LINEAR, borderValue=(0, 0, 0, 0))
    cut = int(round(s * cap_frac)); d = 2 * r; c_mm = d * cap_frac
    top, bot = seal[:cut], seal[cut:]
    # wall_v / lid_v correct for a generated plate whose box height or depth differs from the dieline
    qt = mm_to_px(H_lid, [[cx_mm - r, lid_depth_mm - c_mm * lid_v], [cx_mm + r, lid_depth_mm - c_mm * lid_v], [cx_mm + r, lid_depth_mm], [cx_mm - r, lid_depth_mm]])
    qb = mm_to_px(H_wall, [[cx_mm - r, 0], [cx_mm + r, 0], [cx_mm + r, (d - c_mm) * wall_v], [cx_mm - r, (d - c_mm) * wall_v]])
    # shared fold chord: both halves meet at the same two photo points (VFX round 3)
    left = (qt[3] + qb[0]) / 2; right = (qt[2] + qb[1]) / 2
    shift_l, shift_r = left - qt[3], right - qt[2]
    qt = qt.copy(); qt[3], qt[2] = left, right; qt[0] = qt[0] + shift_l; qt[1] = qt[1] + shift_r
    dl, dr = left - qb[0], right - qb[1]
    qb = qb.copy(); qb[0], qb[1] = left, right; qb[3] = qb[3] + dl; qb[2] = qb[2] + dr
    out = paste_paper(photo, top, qt, shadow=True)
    out = paste_paper(out, bot, qb, shadow=True)
    # crease: 1 px highlight just below the fold, 2-3 px soft shadow under it, only inside the sticker
    Hh, Ww = out.shape[:2]
    a_full = np.zeros((Hh, Ww), np.float32)
    for part, q in ((top, qt), (bot, qb)):
        h_, w_ = part.shape[:2]
        Mq = cv2.getPerspectiveTransform(np.float32([[0, 0], [w_, 0], [w_, h_], [0, h_]]), np.float32(q))
        a_full = np.maximum(a_full, cv2.warpPerspective(part[..., 3] / 255.0, Mq, (Ww, Hh)))
    p0, p1 = mm_to_px(H_wall, [[cx_mm - r, 0], [cx_mm + r, 0]])
    line = np.zeros((Hh, Ww), np.float32)
    cv2.line(line, tuple(int(v) for v in p0), tuple(int(v) for v in p1), 1.0, 1, cv2.LINE_AA)
    hl = np.roll(line, 1, 0); shd = cv2.GaussianBlur(np.roll(line, 3, 0), (0, 0), 1.5)
    out = out * (1 - 0.35 * shd * a_full)[..., None] + 40 * (hl * a_full)[..., None]
    return np.clip(out, 0, 255)
