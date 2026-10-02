"""Dough Boss hero: procedural "exploded manoush" scene builder.

Runs unchanged inside Higgsfield 3D Jutsu `scene_builder_3d_run_python`
(Blender 5.2, `bpy` in scope) or in a local Blender 5.x via
`blender -b -P build_exploded_manoush.py`.

Everything here is original procedural geometry: no imported or catalogue
assets, no image textures. The script is idempotent: it deletes and rebuilds
the "DoughBossHero" collection (plus the DB_* materials it owns) on every run,
and all jitter comes from a seeded RNG plus deterministic Perlin noise, so two
runs produce identical vertices and identical pose data.

Node names are a CONTRACT with the web app (src/components/hero) and with
public/hero/manifest.json. Do not rename them without updating both.

Coordinates: Blender is Z-up; the glTF exporter writes Y-up, so a Blender
point (x, y, z) becomes glTF (x, z, -y). Pose data in `result` is reported in
glTF space so the manifest can be written without further maths.
"""

import math
import random
import time

import bpy
from mathutils import Quaternion, Vector, noise

T0 = time.time()

# ---------------------------------------------------------------------------
# Tunables (metres, degrees). Keep in sync with docs/3d-assets.md.
# ---------------------------------------------------------------------------
GENERATOR = "build_exploded_manoush.py v3"
COLLECTION_NAME = "DoughBossHero"
MATERIAL_PREFIX = "DB_"
SEED = 1234
R = 0.15  # disc radius: a 30 cm manoush
SLICE_COUNT = 8
GAP_DEG = 0.4
DOUGH_THICKNESS = 0.006
CHEESE_THICKNESS = 0.004
MAX_DELAY = 0.3
MINT_COUNT = 12
CHILI_COUNT = 16

# Exploded offsets as fractions of R (radial distance along the wedge
# bisector, lift straight up).
EXPLODE = {
    "dough": (0.55, 0.0),
    "rim": (0.85, 0.10),
    "topping": (0.70, 0.35),
}
GARNISH_RADIAL = (0.6, 1.1)
GARNISH_LIFT = (0.5, 1.0)
GARNISH_TUMBLE_DEG = 25.0

# Stacking order, bottom to top. The web app uses it for draw-order tweaks.
LAYER = {"peel": 0, "dough": 1, "rim": 2, "topping": 3, "mint": 4, "chili": 5}

CAMERA_ELEVATION_DEG = 35.0
CAMERA_AZIMUTH_DEG = -110.0  # camera sits front-left of the pizza
CAMERA_LENS_MM = 35.0
CAMERA_SENSOR_MM = 36.0
FRAME_MARGIN = 0.06
FIT_INCLUDE_PEEL = False  # the handle may leave frame; the pizza must not
PEEL_HANDLE_SWING_DEG = 38.0  # handle angle off the view axis, to the right

# Mesh density (per wedge). Chosen to keep the meshopt GLB well under 600 KB.
DOUGH_NR, DOUGH_NA = 10, 14
CHEESE_NR, CHEESE_NA = 26, 26
RIM_NA, RIM_PROFILE = 40, 24


# ---------------------------------------------------------------------------
# Small maths helpers
# ---------------------------------------------------------------------------
def srgb(r, g, b):
    """8-bit sRGB to scene-linear, so colour picks can be reasoned about."""

    def lin(u):
        u = u / 255.0
        return u / 12.92 if u <= 0.04045 else ((u + 0.055) / 1.055) ** 2.4

    return (lin(r), lin(g), lin(b))


def mix(a, b, t):
    return tuple(a[i] + (b[i] - a[i]) * t for i in range(3))


def clamp01(x):
    return 0.0 if x < 0.0 else 1.0 if x > 1.0 else x


def smoothstep(e0, e1, x):
    t = clamp01((x - e0) / (e1 - e0))
    return t * t * (3.0 - 2.0 * t)


def n3(x, y, z):
    # Perlin is deterministic, so it is safe for an idempotent rebuild.
    return noise.noise(Vector((x, y, z)))


def wrap_angle(a):
    return (a + math.pi) % (2.0 * math.pi) - math.pi


def newell(points):
    nx = ny = nz = 0.0
    count = len(points)
    for i in range(count):
        x0, y0, z0 = points[i]
        x1, y1, z1 = points[(i + 1) % count]
        nx += (y0 - y1) * (z0 + z1)
        ny += (z0 - z1) * (x0 + x1)
        nz += (x0 - x1) * (y0 + y1)
    return (nx, ny, nz)


# ---------------------------------------------------------------------------
# Palette (picked in sRGB, stored linear)
# ---------------------------------------------------------------------------
C_CRUST_PALE = srgb(226, 186, 128)
C_CRUST_GOLD = srgb(204, 136, 58)
C_CRUST_DEEP = srgb(158, 92, 38)
C_CHAR = srgb(42, 27, 18)
C_CRUMB = srgb(236, 216, 172)
# White akkawi-style cheese with golden blisters; kept clearly lighter than
# the baked dough so the two layers separate when the slices fan out.
C_CHEESE = srgb(252, 244, 222)
C_CHEESE_MELT = srgb(247, 228, 176)
C_CHEESE_BROWN = srgb(196, 122, 46)
C_CHEESE_DARK = srgb(122, 64, 24)
C_DOUGH_TOP = srgb(218, 178, 118)
C_DOUGH_BASE = srgb(206, 150, 80)
C_MINT = srgb(38, 112, 44)
C_MINT_RIB = srgb(96, 150, 72)
C_MINT_EDGE = srgb(24, 78, 30)
C_CHILI = srgb(196, 32, 16)
C_CHILI_DARK = srgb(132, 18, 10)
C_CHILI_ORANGE = srgb(214, 78, 22)
# A well-used, oven-darkened peel: darker than raw timber so the pale dough
# reads against it.
C_WOOD = srgb(132, 90, 54)
C_WOOD_DARK = srgb(94, 60, 34)
C_SCORCH = srgb(48, 30, 18)


# ---------------------------------------------------------------------------
# Mesh assembly
# ---------------------------------------------------------------------------
class MeshBuilder:
    """Collects vertices (world-space at rest), per-vertex colours and faces."""

    def __init__(self):
        self.verts = []
        self.cols = []
        self.faces = []

    def vert(self, co, col):
        self.verts.append((co[0], co[1], co[2]))
        self.cols.append(col)
        return len(self.verts) - 1

    def face(self, idx, outward):
        # Orient every face from an explicit outward hint instead of trusting
        # winding bookkeeping; Newell's normal is robust for slightly
        # non-planar quads.
        nrm = newell([self.verts[i] for i in idx])
        if nrm[0] * outward[0] + nrm[1] * outward[1] + nrm[2] * outward[2] < 0.0:
            idx = list(reversed(idx))
        self.faces.append(list(idx))


def loft(mb, rows, outward_fn, closed=False):
    """Skin consecutive rows of vertex indices. A one-vertex row becomes a fan."""
    for k in range(len(rows) - 1):
        a, b = rows[k], rows[k + 1]
        if len(a) == 1 and len(b) == 1:
            continue
        if len(a) == 1 or len(b) == 1:
            apex, ring = (a[0], b) if len(a) == 1 else (b[0], a)
            segs = len(ring) if closed else len(ring) - 1
            for j in range(segs):
                j2 = (j + 1) % len(ring)
                tri = [apex, ring[j], ring[j2]]
                mb.face(tri, outward_fn(tri))
            continue
        segs = len(a) if closed else len(a) - 1
        for j in range(segs):
            j2 = (j + 1) % len(a)
            quad = [a[j], b[j], b[j2], a[j2]]
            mb.face(quad, outward_fn(quad))


def centroid_of(mb, idx):
    n = float(len(idx))
    return (
        sum(mb.verts[i][0] for i in idx) / n,
        sum(mb.verts[i][1] for i in idx) / n,
        sum(mb.verts[i][2] for i in idx) / n,
    )


def wedge_angles(i):
    span = 2.0 * math.pi / SLICE_COUNT
    half_gap = math.radians(GAP_DEG) / 2.0
    return i * span + half_gap, (i + 1) * span - half_gap


def polar_slab(mb, a0, a1, r1, nr, na, z_top, z_bot, col_top, col_bot, col_wall, bulge, bottom_res=(3, 6)):
    """A pie-wedge slab: top + bottom polar grids, rounded outer wall, cut walls.

    The underside is never seen from the hero camera (it looks down at 35
    degrees), so it gets a coarse grid to save GLB bytes.
    """
    up = (0.0, 0.0, 1.0)
    down = (0.0, 0.0, -1.0)

    def grid(zf, cf, nr, na):
        rows = []
        for ir in range(nr + 1):
            r = r1 * ir / nr
            if ir == 0:
                am = 0.5 * (a0 + a1)
                rows.append([mb.vert((0.0, 0.0, zf(0.0, 0.0, 0.0)), cf(0.0, 0.0, zf(0.0, 0.0, 0.0), 0.0, am))])
                continue
            row = []
            for ia in range(na + 1):
                a = a0 + (a1 - a0) * ia / na
                x, y = r * math.cos(a), r * math.sin(a)
                z = zf(x, y, r)
                row.append(mb.vert((x, y, z), cf(x, y, z, r, a)))
            rows.append(row)
        return rows

    loft(mb, grid(z_top, col_top, nr, na), lambda f: up)
    loft(mb, grid(z_bot, col_bot, bottom_res[0], bottom_res[1]), lambda f: down)

    # Rounded outer wall: top edge, bulged middle, bottom edge.
    wall_rows = [[], [], []]
    for ia in range(na + 1):
        a = a0 + (a1 - a0) * ia / na
        ca, sa = math.cos(a), math.sin(a)
        x, y = r1 * ca, r1 * sa
        zt, zb = z_top(x, y, r1), z_bot(x, y, r1)
        zm = 0.5 * (zt + zb)
        rm = r1 + bulge
        wall_rows[0].append(mb.vert((x, y, zt), col_wall(zt)))
        wall_rows[1].append(mb.vert((rm * ca, rm * sa, zm), col_wall(zm)))
        wall_rows[2].append(mb.vert((x, y, zb), col_wall(zb)))

    def radial_out(f):
        cx, cy, _ = centroid_of(mb, f)
        return (cx, cy, 0.0)

    loft(mb, wall_rows, radial_out)

    # Straight cut walls along the two radial edges.
    for a, sign in ((a0, -1.0), (a1, 1.0)):
        ca, sa = math.cos(a), math.sin(a)
        tangent = (-sa * sign, ca * sign, 0.0)
        top_row, bot_row = [], []
        for ir in range(nr + 1):
            r = r1 * ir / nr
            x, y = r * ca, r * sa
            zt, zb = z_top(x, y, r), z_bot(x, y, r)
            top_row.append(mb.vert((x, y, zt), col_wall(zt)))
            bot_row.append(mb.vert((x, y, zb), col_wall(zb)))
        loft(mb, [top_row, bot_row], lambda f, t=tangent: t)


# ---------------------------------------------------------------------------
# Procedural fields shared across wedges (global coords => seamless cuts)
# ---------------------------------------------------------------------------
RNG = random.Random(SEED)


def make_bubbles(rng, count):
    out = []
    for _ in range(count):
        r = 0.80 * R * math.sqrt(rng.random())
        a = rng.random() * 2.0 * math.pi
        out.append((r * math.cos(a), r * math.sin(a), rng.uniform(0.004, 0.012), rng.uniform(0.0012, 0.0034)))
    return out


def make_blisters(rng, count):
    return [
        (
            rng.random() * 2.0 * math.pi,
            rng.uniform(0.15, 0.85) * math.pi,
            rng.uniform(0.0018, 0.0042),
            rng.uniform(0.0008, 0.0022),
        )
        for _ in range(count)
    ]


BUBBLES = make_bubbles(RNG, 120)
BLISTERS = make_blisters(RNG, 112)
CHEESE_EDGE = 0.835 * R
DOUGH_EDGE = 0.975 * R


def bubble_height(x, y):
    h = 0.0
    for bx, by, br, bh in BUBBLES:
        d2 = (x - bx) ** 2 + (y - by) ** 2
        if d2 < br * br:
            q = 1.0 - d2 / (br * br)
            h += bh * q * q
    return h


def cheese_top(x, y, r):
    taper = smoothstep(0.70 * R, CHEESE_EDGE, r)
    bubble_fade = 1.0 - smoothstep(0.74 * R, CHEESE_EDGE, r)
    th = CHEESE_THICKNESS * (1.0 - 0.65 * taper)
    return DOUGH_THICKNESS + th + bubble_height(x, y) * bubble_fade + 0.0004 * n3(x * 45.0, y * 45.0, 2.7)


def cheese_bottom(_x, _y, _r):
    return DOUGH_THICKNESS - 0.0006


def cheese_colour(x, y, z, r, _a):
    b = bubble_height(x, y) / 0.003
    melt = 0.5 + 0.5 * n3(x * 22.0, y * 22.0, 9.1)
    col = mix(C_CHEESE, C_CHEESE_MELT, 0.2 + 0.5 * melt)
    patch = smoothstep(0.22, 0.55, n3(x * 30.0, y * 30.0, 5.5))
    brown = clamp01(smoothstep(0.35, 0.95, b) * 0.95 + patch * 0.75 + smoothstep(0.74 * R, CHEESE_EDGE, r) * 0.45)
    col = mix(col, C_CHEESE_BROWN, brown)
    dark = smoothstep(0.85, 1.25, b) * 0.6 + smoothstep(0.5, 0.75, n3(x * 60.0, y * 60.0, 1.9)) * 0.35
    return mix(col, C_CHEESE_DARK, clamp01(dark))


def cheese_wall_colour(z):
    return mix(C_CHEESE_MELT, C_CHEESE, smoothstep(DOUGH_THICKNESS, DOUGH_THICKNESS + CHEESE_THICKNESS, z))


def dough_top(x, y, _r):
    return DOUGH_THICKNESS + 0.0004 * n3(x * 40.0, y * 40.0, 1.3)


def dough_bottom(_x, _y, _r):
    # Lifted 0.3 mm off the peel so the two surfaces never share a depth.
    return 0.0003


def dough_top_colour(x, y, _z, _r, _a):
    return mix(C_DOUGH_TOP, C_CRUST_PALE, 0.5 + 0.5 * n3(x * 25.0, y * 25.0, 4.4))


def dough_bottom_colour(x, y, _z, _r, _a):
    spot = smoothstep(0.35, 0.65, n3(x * 120.0, y * 120.0, 8.8))
    return mix(mix(C_DOUGH_BASE, C_CRUST_GOLD, 0.4), C_CHAR, spot * 0.75)


def dough_wall_colour(z):
    return mix(C_DOUGH_BASE, C_DOUGH_TOP, smoothstep(0.0, DOUGH_THICKNESS, z))


def rim_frame(a):
    """Cross-section parameters for the crust at global angle a (periodic)."""
    ca, sa = math.cos(a), math.sin(a)
    rc = R * (0.900 + 0.012 * n3(ca * 1.7, sa * 1.7, 3.1))
    hw = R * (0.082 + 0.010 * n3(ca * 2.9, sa * 2.9, 7.7))
    ht = 0.0150 + 0.0040 * n3(ca * 4.3, sa * 4.3, 11.3) + 0.0015 * n3(ca * 11.0, sa * 11.0, 2.2)
    return rc, hw, ht


def rim_point(a, t):
    """Point on the crust tube surface plus a blister amount (for charring)."""
    rc, hw, ht = rim_frame(a)
    zc, hb = DOUGH_THICKNESS, 0.0035
    ct, st = math.cos(t), math.sin(t)
    if st >= 0.0:
        z = zc + ht * (st ** 0.85)
        r = rc + hw * ct * (1.0 + 0.08 * st)
    else:
        z = zc + hb * st
        r = rc + hw * ct
    blister = 0.0
    if st > -0.1:
        for ba, bt, bs, bh in BLISTERS:
            da = wrap_angle(a - ba) * rc
            if abs(da) > 4.0 * bs:
                continue
            dt = (t - bt) * 0.013
            blister += bh * math.exp(-(da * da + dt * dt) / (2.0 * bs * bs))
    # Push blisters out along the direction from the tube's core.
    dr, dz = r - rc, z - (zc + ht * 0.3)
    ln = math.hypot(dr, dz) or 1.0
    r += blister * dr / ln
    z += blister * dz / ln
    return r, z, blister, st


def rim_colour(x, y, z, st, blister):
    col = mix(C_CRUST_PALE, C_CRUST_GOLD, smoothstep(-0.6, 0.2, st))
    col = mix(col, C_CRUST_DEEP, smoothstep(0.3, 0.95, st) * 0.85)
    spots = smoothstep(0.32, 0.62, n3(x * 160.0, y * 160.0, z * 160.0)) * smoothstep(0.15, 0.8, st)
    char = clamp01(spots * 0.85 + smoothstep(0.0009, 0.002, blister) * 0.8 * smoothstep(0.0, 0.6, st))
    return mix(col, C_CHAR, char * 0.92)


def build_rim(mb, a0, a1):
    rows = []
    centre = []
    for ja in range(RIM_NA + 1):
        a = a0 + (a1 - a0) * ja / RIM_NA
        ca, sa = math.cos(a), math.sin(a)
        rc, _hw, ht = rim_frame(a)
        centre.append((rc * ca, rc * sa, DOUGH_THICKNESS + ht * 0.3))
        row = []
        for k in range(RIM_PROFILE):
            t = 2.0 * math.pi * k / RIM_PROFILE
            r, z, blister, st = rim_point(a, t)
            x, y = r * ca, r * sa
            row.append(mb.vert((x, y, z), rim_colour(x, y, z, st, blister)))
        rows.append(row)

    def out_from_core(f):
        cx, cy, cz = centroid_of(mb, f)
        a = math.atan2(cy, cx)
        rc, _hw, ht = rim_frame(a)
        return (cx - rc * math.cos(a), cy - rc * math.sin(a), cz - (DOUGH_THICKNESS + ht * 0.3))

    loft(mb, rows, out_from_core, closed=True)

    # End caps show the cut crumb.
    for ring_idx, a, sign in ((0, a0, -1.0), (RIM_NA, a1, 1.0)):
        ca, sa = math.cos(a), math.sin(a)
        normal = (-sa * sign, ca * sign, 0.0)
        cap = []
        for k in range(RIM_PROFILE):
            src = mb.verts[rows[ring_idx][k]]
            cap.append(mb.vert(src, mix(C_CRUMB, C_CRUST_GOLD, 0.35)))
        apex = mb.vert(centre[ring_idx], C_CRUMB)
        loft(mb, [[apex], cap], lambda f, nn=normal: nn, closed=True)


def build_mint(mb, rng):
    """A curved mint leaf with a creased midrib, lying along local +X."""
    length = rng.uniform(0.024, 0.030)
    width = length * rng.uniform(0.48, 0.58)
    curl = rng.uniform(0.10, 0.20)
    fold = rng.uniform(0.25, 0.45)
    twist = rng.uniform(-0.15, 0.15)
    nu, nv = 12, 3
    rows = []
    for iu in range(nu + 1):
        s = iu / nu
        half = 0.5 * width * (math.sin(math.pi * s) ** 0.7) * (1.0 - 0.3 * s)
        x = (s - 0.45) * length
        bend = curl * length * ((s - 0.5) * 2.0) ** 2 * 0.35
        if half < 1e-6:
            rows.append([mb.vert((x, 0.0, bend), C_MINT_RIB)])
            continue
        row = []
        for iv in range(-nv, nv + 1):
            v = iv / nv
            serr = 1.0 + (0.08 * math.sin(2.0 * math.pi * 7.0 * s) if abs(iv) == nv else 0.0)
            y = v * half * serr
            z = bend + fold * abs(y) * 0.7 + twist * y * (s - 0.5)
            vein = 0.5 + 0.5 * math.cos(2.0 * math.pi * (6.0 * s - 1.5 * abs(v)))
            col = mix(C_MINT, C_MINT_RIB, 0.9 if iv == 0 else vein * 0.25)
            if abs(iv) == nv:
                col = mix(col, C_MINT_EDGE, 0.6)
            row.append(mb.vert((x, y, z), col))
        rows.append(row)
    loft(mb, rows, lambda f: (0.0, 0.0, 1.0))
    return length


def build_chili(mb, rng):
    """A small curled chili flake: an irregular disc bent into a shallow trough."""
    size = rng.uniform(0.0070, 0.0100)
    aspect = rng.uniform(0.55, 0.8)
    curl = rng.uniform(60.0, 140.0)
    tone = rng.random()
    base = mix(C_CHILI, C_CHILI_DARK, tone * 0.7) if tone < 0.75 else mix(C_CHILI, C_CHILI_ORANGE, 0.6)
    phase = rng.random() * 10.0
    segs = 14
    rows = [[mb.vert((0.0, 0.0, 0.0), base)]]
    for ring in (0.55, 1.0):
        row = []
        for j in range(segs):
            th = 2.0 * math.pi * j / segs
            jag = 1.0 + 0.22 * n3(math.cos(th) * 2.0 + phase, math.sin(th) * 2.0, phase)
            x = ring * size * 0.5 * jag * math.cos(th)
            y = ring * size * 0.5 * aspect * jag * math.sin(th)
            z = curl * y * y
            row.append(mb.vert((x, y, z), mix(base, C_CHILI_DARK, 0.35 * ring)))
        rows.append(row)
    loft(mb, rows, lambda f: (0.0, 0.0, 1.0), closed=True)
    return size


def superellipse(theta, a, n):
    c, s = math.cos(theta), math.sin(theta)
    rr = (abs(c) ** n + abs(s) ** n) ** (-1.0 / n)
    return a * rr * c, a * rr * s


def build_peel(mb, handle_dir):
    """Wooden baker's peel: rounded-square blade (0.36 m) plus a 0.55 m handle."""
    half, n_exp, thick = 0.18, 5.0, 0.006
    hx, hy = handle_dir
    px, py = -hy, hx  # perpendicular, for grain direction

    def to_world(lx, ly):
        return lx * hx + ly * px, lx * hy + ly * py

    def wood(lx, ly, lz):
        grain = 0.5 + 0.5 * math.sin(ly * 230.0 + 2.5 * n3(lx * 6.0, ly * 40.0, lz * 40.0 + 0.3))
        col = mix(C_WOOD, C_WOOD_DARK, grain * 0.55)
        scorch = smoothstep(-0.12, -0.175, lx) * 0.65 + smoothstep(0.45, 0.75, n3(lx * 18.0, ly * 18.0, 6.6)) * 0.12
        return mix(col, C_SCORCH, clamp01(scorch))

    segs, rings = 112, 12

    def blade_grid(z, outward, rings):
        rows = []
        for k in range(rings + 1):
            f = k / rings
            if k == 0:
                rows.append([mb.vert((0.0, 0.0, z), wood(0.0, 0.0, z))])
                continue
            row = []
            for j in range(segs):
                lx, ly = superellipse(2.0 * math.pi * j / segs, half * f, n_exp)
                wx, wy = to_world(lx, ly)
                row.append(mb.vert((wx, wy, z), wood(lx, ly, z)))
            rows.append(row)
        loft(mb, rows, lambda f_: outward, closed=True)

    blade_grid(0.0, (0.0, 0.0, 1.0), rings)
    blade_grid(-thick, (0.0, 0.0, -1.0), 2)  # underside is never on camera
    wall = [[], [], []]
    for j in range(segs):
        lx, ly = superellipse(2.0 * math.pi * j / segs, half, n_exp)
        ln = math.hypot(lx, ly)
        bx, by = lx + 0.0015 * lx / ln, ly + 0.0015 * ly / ln
        for row, (ux, uy, z) in zip(wall, ((lx, ly, 0.0), (bx, by, -thick * 0.5), (lx, ly, -thick))):
            wx, wy = to_world(ux, uy)
            row.append(mb.vert((wx, wy, z), wood(ux, uy, z)))

    def wall_out(f):
        cx, cy, _ = centroid_of(mb, f)
        return (cx, cy, 0.0)

    loft(mb, wall, wall_out, closed=True)

    # Handle: a lathe along local +X with a flattened neck into the blade.
    start, length, radius = half - 0.012, 0.55, 0.0145
    axis_z = -thick * 0.5
    around, along = 18, 34
    rows = []
    for i in range(along + 1):
        s = i / along
        lx = start + s * (length + 0.012)
        neck = smoothstep(0.0, 0.14, s)
        wide = 0.030 * (1.0 - neck) + radius * neck
        tall = 0.0040 * (1.0 - neck) + radius * neck
        end = 1.0 if lx < half + length - 0.012 else math.sqrt(max(0.0, 1.0 - ((lx - (half + length - 0.012)) / 0.012) ** 2))
        row = []
        for j in range(around):
            th = 2.0 * math.pi * j / around
            ly = wide * math.cos(th) * max(end, 0.05)
            lz = axis_z + tall * math.sin(th) * max(end, 0.05)
            wx, wy = to_world(lx, ly)
            row.append(mb.vert((wx, wy, lz), wood(lx, ly, lz)))
        rows.append(row)
    tip_x = half + length
    twx, twy = to_world(tip_x, 0.0)
    rows.append([mb.vert((twx, twy, axis_z), wood(tip_x, 0.0, axis_z))])

    def handle_out(f):
        cx, cy, cz = centroid_of(mb, f)
        # Clamp to the cap centre so the rounded end still points outwards.
        lx = min(cx * hx + cy * hy, tip_x - 0.012)
        ax, ay = to_world(lx, 0.0)
        return (cx - ax, cy - ay, cz - axis_z)

    loft(mb, rows, handle_out, closed=True)


# ---------------------------------------------------------------------------
# Blender object plumbing
# ---------------------------------------------------------------------------
def clear_previous():
    coll = bpy.data.collections.get(COLLECTION_NAME)
    if coll is not None:
        # Walk bpy.data.objects rather than coll.all_objects: the 3D Jutsu
        # worker has been seen to yield None from collection iteration.
        owned = [
            o for o in bpy.data.objects
            if o is not None and any(c is not None and c.name == COLLECTION_NAME for c in o.users_collection)
        ]
        for obj in owned:
            data = obj.data
            bpy.data.objects.remove(obj, do_unlink=True)
            if data is not None and data.users == 0:
                if isinstance(data, bpy.types.Mesh):
                    bpy.data.meshes.remove(data)
                elif isinstance(data, bpy.types.Light):
                    bpy.data.lights.remove(data)
                elif isinstance(data, bpy.types.Camera):
                    bpy.data.cameras.remove(data)
        for child in list(coll.children):
            if child is not None:
                bpy.data.collections.remove(child)
        bpy.data.collections.remove(coll)
    for mat in list(bpy.data.materials):
        if mat is not None and mat.name.startswith(MATERIAL_PREFIX) and mat.users == 0:
            bpy.data.materials.remove(mat)
    for mesh in list(bpy.data.meshes):
        if mesh is not None and mesh.users == 0:
            bpy.data.meshes.remove(mesh)


def make_material(name, roughness, double_sided, viewport):
    mat = bpy.data.materials.new(name)
    try:
        mat.use_nodes = True
    except (AttributeError, TypeError):
        pass  # Blender 5 always uses nodes; the setter may be gone.
    nt = mat.node_tree
    nt.nodes.clear()
    out = nt.nodes.new("ShaderNodeOutputMaterial")
    bsdf = nt.nodes.new("ShaderNodeBsdfPrincipled")
    attr = nt.nodes.new("ShaderNodeVertexColor")
    attr.layer_name = "Col"
    # Colour attribute straight into Base Color is the one pattern the glTF
    # exporter maps to COLOR_0, which keeps charring/browning in the GLB.
    nt.links.new(attr.outputs["Color"], bsdf.inputs["Base Color"])
    bsdf.inputs["Roughness"].default_value = roughness
    nt.links.new(bsdf.outputs["BSDF"], out.inputs["Surface"])
    out.location = (300, 0)
    attr.location = (-300, 0)
    mat.use_backface_culling = not double_sided
    mat.diffuse_color = (viewport[0], viewport[1], viewport[2], 1.0)
    mat.roughness = roughness
    return mat


def make_object(name, mb, mat, coll, origin, uv_points=None):
    """Create a mesh object whose origin (pivot) is `origin` (world, rest)."""
    ox, oy, oz = origin
    local = [(v[0] - ox, v[1] - oy, v[2] - oz) for v in mb.verts]
    me = bpy.data.meshes.new(name)
    me.from_pydata(local, [], mb.faces)
    me.validate(clean_customdata=False)
    me.update()
    me.polygons.foreach_set("use_smooth", [True] * len(me.polygons))

    # One square top-down texture spans the whole disc: UV = rest XY / (2R).
    pts = uv_points if uv_points is not None else mb.verts
    vidx = [0] * len(me.loops)
    me.loops.foreach_get("vertex_index", vidx)
    flat = []
    for vi in vidx:
        flat.append(0.5 + pts[vi][0] / (2.0 * R))
        flat.append(0.5 + pts[vi][1] / (2.0 * R))
    uv = me.uv_layers.new(name="UVMap")
    uv.data.foreach_set("uv", flat)

    attr = me.color_attributes.new(name="Col", type="FLOAT_COLOR", domain="POINT")
    cols = []
    for c in mb.cols:
        cols.extend((c[0], c[1], c[2], 1.0))
    attr.data.foreach_set("color", cols)
    try:
        me.color_attributes.active_color = attr
        me.color_attributes.render_color_index = 0
    except (AttributeError, TypeError):
        pass
    me.materials.append(mat)
    me.update()

    obj = bpy.data.objects.new(name, me)
    coll.objects.link(obj)
    obj.rotation_mode = "QUATERNION"
    obj.location = origin
    obj.rotation_quaternion = (1.0, 0.0, 0.0, 0.0)
    return obj


def bbox_centre(points):
    xs = [p[0] for p in points]
    ys = [p[1] for p in points]
    zs = [p[2] for p in points]
    return ((min(xs) + max(xs)) / 2.0, (min(ys) + max(ys)) / 2.0, (min(zs) + max(zs)) / 2.0)


def gltf_pos(v):
    return [round(v[0], 6), round(v[2], 6), round(-v[1], 6)]


def gltf_quat(q):
    # Blender (w, x, y, z) Z-up -> glTF [x, y, z, w] Y-up.
    return [round(q[1], 6), round(q[3], 6), round(-q[2], 6), round(q[0], 6)]


def set_pose_props(obj, kind, slice_index, delay, exp_loc, exp_quat):
    obj["db_kind"] = kind
    obj["db_slice"] = -1 if slice_index is None else slice_index
    obj["db_layer"] = LAYER[kind]
    obj["db_delay"] = round(delay, 4)
    obj["db_rest_loc"] = list(obj.location)
    obj["db_rest_quat"] = list(obj.rotation_quaternion)
    obj["db_exp_loc"] = list(exp_loc)
    obj["db_exp_quat"] = list(exp_quat)


# ---------------------------------------------------------------------------
# Build
# ---------------------------------------------------------------------------
def build():
    scene = bpy.context.scene
    clear_previous()
    coll = bpy.data.collections.new(COLLECTION_NAME)
    scene.collection.children.link(coll)

    mats = {
        "dough": make_material("DB_Dough", 0.78, False, C_DOUGH_TOP),
        "rim": make_material("DB_Crust", 0.62, False, C_CRUST_GOLD),
        "topping": make_material("DB_Cheese", 0.34, False, C_CHEESE),
        "mint": make_material("DB_Mint", 0.45, True, C_MINT),
        "chili": make_material("DB_Chili", 0.38, True, C_CHILI),
        "peel": make_material("DB_Wood", 0.66, False, C_WOOD),
    }

    nodes = []
    verts_total = 0

    # Camera geometry first: the peel handle swings relative to the view.
    elev = math.radians(CAMERA_ELEVATION_DEG)
    azim = math.radians(CAMERA_AZIMUTH_DEG)
    to_cam = Vector((math.cos(elev) * math.cos(azim), math.cos(elev) * math.sin(azim), math.sin(elev)))
    view_h = Vector((-to_cam.x, -to_cam.y, 0.0)).normalized()
    right_h = view_h.cross(Vector((0.0, 0.0, 1.0))).normalized()
    swing = math.radians(PEEL_HANDLE_SWING_DEG)
    handle = view_h * math.cos(swing) + right_h * math.sin(swing)

    # Peel (static: rest == exploded).
    mb = MeshBuilder()
    build_peel(mb, (handle.x, handle.y))
    peel = make_object("Peel", mb, mats["peel"], coll, (0.0, 0.0, 0.0))
    set_pose_props(peel, "peel", None, 0.0, peel.location, peel.rotation_quaternion)
    nodes.append(peel)
    verts_total += len(mb.verts)

    span = 2.0 * math.pi / SLICE_COUNT
    builders = {
        "dough": lambda m, a0, a1: polar_slab(
            m, a0, a1, DOUGH_EDGE, DOUGH_NR, DOUGH_NA, dough_top, dough_bottom,
            dough_top_colour, dough_bottom_colour, dough_wall_colour, 0.0018,
        ),
        "rim": build_rim,
        "topping": lambda m, a0, a1: polar_slab(
            m, a0, a1, CHEESE_EDGE, CHEESE_NR, CHEESE_NA, cheese_top, cheese_bottom,
            cheese_colour, cheese_colour, cheese_wall_colour, 0.0008,
        ),
    }
    layer_base = {"topping": 0.04, "rim": 0.10, "dough": 0.16}
    for i in range(SLICE_COUNT):
        a0, a1 = wedge_angles(i)
        mid = (i + 0.5) * span
        bis = Vector((math.cos(mid), math.sin(mid), 0.0))
        for kind in ("dough", "rim", "topping"):
            mb = MeshBuilder()
            builders[kind](mb, a0, a1)
            obj = make_object("slice%d_%s" % (i, kind), mb, mats[kind], coll, bbox_centre(mb.verts))
            radial, lift = EXPLODE[kind]
            exp_loc = obj.location + bis * (radial * R) + Vector((0.0, 0.0, lift * R))
            delay = layer_base[kind] + 0.12 * i / (SLICE_COUNT - 1)
            set_pose_props(obj, kind, i, delay, exp_loc, obj.rotation_quaternion)
            nodes.append(obj)
            verts_total += len(mb.verts)

    # Garnish: rejection-sampled so leaves and flakes never overlap at rest
    # or in the exploded pose.
    grng = random.Random(SEED + 1)
    placed_rest = []
    placed_exp = []

    def garnish(kind, index, build_fn, clear_rest, clear_exp):
        nonlocal verts_total
        for _attempt in range(400):
            sub = random.Random(grng.random())
            mb = MeshBuilder()
            size = build_fn(mb, sub)
            # Recentre on the vertex centroid so tumbles pivot on the piece.
            c = centroid_of(mb, range(len(mb.verts)))
            local = [(v[0] - c[0], v[1] - c[1], v[2] - c[2]) for v in mb.verts]
            yaw = sub.random() * 2.0 * math.pi
            q_rest = Quaternion((0.0, 0.0, 1.0), yaw)
            r = 0.72 * R * math.sqrt(sub.random())
            a = sub.random() * 2.0 * math.pi
            px, py = r * math.cos(a), r * math.sin(a)
            rotated = [q_rest @ Vector(p) for p in local]
            # Sit the piece on the bubbled cheese: lowest clearance wins.
            pz = max(
                cheese_top(px + p.x, py + p.y, math.hypot(px + p.x, py + p.y)) + 0.0004 - p.z
                for p in rotated
            )
            rest = Vector((px, py, pz))
            reach = 0.6 * size
            if any((rest - o).length < clear_rest + s + reach for o, s in placed_rest):
                continue
            direction = Vector((px, py, 0.0))
            direction = direction.normalized() if direction.length > 1e-4 else Vector((math.cos(a), math.sin(a), 0.0))
            out = sub.uniform(*GARNISH_RADIAL) * R
            up = sub.uniform(*GARNISH_LIFT) * R
            exp_loc = rest + direction * out + Vector((0.0, 0.0, up))
            if any((exp_loc - o).length < clear_exp for o in placed_exp):
                continue
            axis = Vector((sub.uniform(-1, 1), sub.uniform(-1, 1), sub.uniform(-1, 1)))
            if axis.length < 1e-3:
                axis = Vector((1.0, 0.0, 0.0))
            tumble = Quaternion(axis.normalized(), math.radians(sub.uniform(8.0, GARNISH_TUMBLE_DEG)))
            q_exp = tumble @ q_rest
            placed_rest.append((rest, reach))
            placed_exp.append(exp_loc)
            world_pts = [tuple(rest + p) for p in rotated]
            mb.verts = local
            obj = make_object("%s_%02d" % (kind, index), mb, mats[kind], coll, (0.0, 0.0, 0.0), uv_points=world_pts)
            obj.location = rest
            obj.rotation_quaternion = q_rest
            ang = (math.atan2(py, px) % (2.0 * math.pi)) / (2.0 * math.pi)
            delay = min(MAX_DELAY, 0.10 * ang + sub.uniform(0.0, 0.04))
            set_pose_props(obj, kind, None, delay, exp_loc, q_exp)
            nodes.append(obj)
            verts_total += len(mb.verts)
            return
        raise RuntimeError("could not place %s_%02d" % (kind, index))

    for k in range(MINT_COUNT):
        garnish("mint", k, build_mint, 0.002, 0.030)
    for k in range(CHILI_COUNT):
        garnish("chili", k, build_chili, 0.002, 0.020)

    cam_info = setup_camera_and_lights(scene, coll, nodes, to_cam)
    setup_render(scene)

    out_nodes = []
    for obj in nodes:
        out_nodes.append({
            "name": obj.name,
            "kind": obj["db_kind"],
            "slice": None if obj["db_slice"] < 0 else int(obj["db_slice"]),
            "layer": int(obj["db_layer"]),
            "rest": {"position": gltf_pos(obj["db_rest_loc"]), "quaternion": gltf_quat(obj["db_rest_quat"])},
            "exploded": {"position": gltf_pos(obj["db_exp_loc"]), "quaternion": gltf_quat(obj["db_exp_quat"])},
            "delay": float(obj["db_delay"]),
            "vertices": len(obj.data.vertices),
        })
    return {
        "generator": GENERATOR,
        "blender": bpy.app.version_string,
        "seed": SEED,
        "radiusMetres": R,
        "sliceCount": SLICE_COUNT,
        "maxDelay": MAX_DELAY,
        "camera": cam_info,
        "nodes": out_nodes,
        "vertexTotal": verts_total,
        "seconds": round(time.time() - T0, 2),
    }


def exploded_corners(obj):
    loc = Vector(obj["db_exp_loc"])
    quat = Quaternion(obj["db_exp_quat"])
    return [loc + quat @ Vector(c) for c in obj.bound_box]


def rest_corners(obj):
    return [obj.matrix_world @ Vector(c) for c in obj.bound_box]


def aim(obj, target):
    obj.rotation_mode = "QUATERNION"
    obj.rotation_quaternion = (Vector(target) - obj.location).to_track_quat("-Z", "Y")


def setup_camera_and_lights(scene, coll, nodes, to_cam):
    bpy.context.view_layer.update()
    pts = []
    for obj in nodes:
        if obj["db_kind"] == "peel" and not FIT_INCLUDE_PEEL:
            continue
        pts.extend(exploded_corners(obj))
        pts.extend(rest_corners(obj))

    fwd = -to_cam
    right = fwd.cross(Vector((0.0, 0.0, 1.0))).normalized()
    upv = right.cross(fwd).normalized()
    tan_half = (CAMERA_SENSOR_MM / 2.0) / CAMERA_LENS_MM * (1.0 - FRAME_MARGIN)

    xs = [p.x for p in pts]
    ys = [p.y for p in pts]
    zs = [p.z for p in pts]
    target = Vector(((min(xs) + max(xs)) / 2, (min(ys) + max(ys)) / 2, (min(zs) + max(zs)) / 2))
    dist = 1.0
    for _ in range(8):
        # Closed form: the nearest distance at which every point fits.
        dist = max(max(abs((p - target).dot(right)), abs((p - target).dot(upv))) / tan_half - (p - target).dot(fwd) for p in pts)
        us, vs = [], []
        for p in pts:
            rel = p - target
            depth = rel.dot(fwd) + dist
            us.append(rel.dot(right) / depth)
            vs.append(rel.dot(upv) / depth)
        du = (min(us) + max(us)) / 2.0
        dv = (min(vs) + max(vs)) / 2.0
        target = target + right * du * dist + upv * dv * dist
    dist = max(max(abs((p - target).dot(right)), abs((p - target).dot(upv))) / tan_half - (p - target).dot(fwd) for p in pts)

    cam_data = bpy.data.cameras.new("HeroCamera")
    cam_data.lens = CAMERA_LENS_MM
    cam_data.sensor_width = CAMERA_SENSOR_MM
    cam_data.sensor_fit = "AUTO"
    cam_data.clip_start = 0.01
    cam_data.clip_end = 50.0
    cam = bpy.data.objects.new("HeroCamera", cam_data)
    coll.objects.link(cam)
    cam.location = target + to_cam * dist
    aim(cam, target)
    cam["db_target"] = list(target)
    scene.camera = cam

    right_h = Vector((right.x, right.y, 0.0)).normalized()
    view_h = Vector((fwd.x, fwd.y, 0.0)).normalized()
    up = Vector((0.0, 0.0, 1.0))

    def area(name, offset, power, size, temperature):
        ld = bpy.data.lights.new(name, "AREA")
        ld.energy = power
        ld.shape = "DISK"
        ld.size = size
        if hasattr(ld, "use_temperature"):
            ld.use_temperature = True
            ld.temperature = temperature
        obj = bpy.data.objects.new(name, ld)
        coll.objects.link(obj)
        obj.location = target + offset
        aim(obj, target)
        return obj

    # Warm key from upper left, hard-ish rim from behind, soft cool-ish fill.
    area("KeyLight", up * 0.95 - right_h * 0.75 - view_h * 0.25, 26.0, 0.45, 3200.0)
    area("RimLight", up * 0.55 + view_h * 1.0 + right_h * 0.15, 40.0, 0.35, 4300.0)
    area("FillLight", up * 0.30 + right_h * 0.95 - view_h * 0.55, 6.0, 1.0, 5600.0)

    fov = 2.0 * math.degrees(math.atan((CAMERA_SENSOR_MM / 2.0) / CAMERA_LENS_MM))
    return {
        "position": gltf_pos(cam.location),
        "target": gltf_pos(target),
        "fovDeg": round(fov, 3),
        "lensMm": CAMERA_LENS_MM,
        "elevationDeg": CAMERA_ELEVATION_DEG,
        "azimuthDeg": CAMERA_AZIMUTH_DEG,
        "distance": round(dist, 4),
    }


def setup_render(scene):
    scene.render.engine = "BLENDER_EEVEE"
    scene.render.film_transparent = True
    scene.render.resolution_x = 1024
    scene.render.resolution_y = 1024
    scene.render.resolution_percentage = 100
    world = scene.world
    if world is None:
        world = bpy.data.worlds.new("DB_World")
        scene.world = world
    try:
        world.use_nodes = True
    except (AttributeError, TypeError):
        pass
    if world.node_tree is not None:
        bg = world.node_tree.nodes.get("Background")
        if bg is not None:
            # Near-black warm ambience: the site background is #070707.
            bg.inputs["Color"].default_value = (0.012, 0.008, 0.006, 1.0)
            bg.inputs["Strength"].default_value = 1.0
    for vt in ("Khronos PBR Neutral", "AgX", "Standard"):
        try:
            scene.view_settings.view_transform = vt
            break
        except TypeError:
            continue


result = build()
