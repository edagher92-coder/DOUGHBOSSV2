"""Parametric FEFCO 0427-style mailer (roll-end, tuck-front, hinged lid) dieline. Units: mm.
Viewed from the PRINTED (outside) face. Returns cut paths, crease lines and named panels.
All dimensions are working values [CONFIRM with the converter's structural engineer before tooling]."""
from dataclasses import dataclass, field

@dataclass
class Spec:
    L: float = 385.0   # internal length (left-right)  [CONFIRM: fit test with 12 bakes]
    W: float = 290.0   # internal depth (front-back)    [CONFIRM]
    H: float = 50.0    # internal height                [CONFIRM: tallest bake + liner + 5 mm]
    t: float = 1.6     # board caliper, E-flute          [CONFIRM with board spec]
    tuck: float = 45.0 # lid front tuck flap depth (H - 2t - ~2 mm) [CONFIRM with structural engineer]
    bleed: float = 3.0
    safe: float = 5.0  # artwork safe zone inside cuts and creases

@dataclass
class Die:
    cuts: list = field(default_factory=list)     # polylines (list of (x,y)), closed or open
    creases: list = field(default_factory=list)  # segments ((x1,y1),(x2,y2))
    holes: list = field(default_factory=list)    # ('oval', cx, cy, w, h) / ('slot', x, y, w, h)
    panels: dict = field(default_factory=dict)   # name -> (x, y, w, h, rotation_deg_for_reading)
    dims: list = field(default_factory=list)     # (p1, p2, label, offset)
    vents: list = field(default_factory=list)    # ('outer'|'inner', cx, cy, diameter)

def build(s: Spec = Spec()):
    L, W, H, t = s.L, s.W, s.H, s.t
    d = Die()
    # Base panel at origin
    Lo = L + 2 * t                    # outer side-wall pitch allowance
    d.panels['base'] = (0, 0, L, W, 0)
    # Front wall (outer, then rolled inner) below base
    d.panels['front'] = (0, -H, L, H, 180)
    d.panels['front_inner'] = (t, -2 * H + t, L - 2 * t, H - t, 0)
    # Back wall above base, lid above that, lid front flap, tuck
    d.panels['back'] = (0, W, L, H, 0)
    lidW, lidL = W + t, L + 2 * t
    d.panels['lid'] = (-t, W + H, lidL, lidW, 180)
    d.panels['lid_front'] = (-t + 3, W + H + lidW, lidL - 6, s.tuck, 180)
    # Side walls (outer + rolled inner) left and right of base
    d.panels['side_left'] = (-H, 0, H, W, 90)
    d.panels['side_left_inner'] = (-2 * H + t, t, H - t, W - 2 * t, 0)
    d.panels['side_right'] = (L, 0, H, W, -90)
    d.panels['side_right_inner'] = (L + H, t, H - t, W - 2 * t, 0)

    # ---- cut outline (clockwise from bottom-left of front_inner), incl. dust ears and lock tabs
    def tabbed_edge(x0, x1, y, depth, n=2, w=22):
        """Edge from x0 to x1 at y with n locking tabs of width w protruding by depth (sign = direction)."""
        pts = [(x0, y)]
        span = x1 - x0
        for i in range(n):
            cx = x0 + span * (i + 1) / (n + 1)
            a, b = cx - w / 2, cx + w / 2
            if span < 0: a, b = b, a
            pts += [(a, y), (a + (3 if span > 0 else -3), y + depth), (b - (3 if span > 0 else -3), y + depth), (b, y)]
        pts.append((x1, y))
        return pts

    fiy = -2 * H + t
    outline = []
    # front inner bottom edge with tabs (tabs lock into base slots)
    outline += tabbed_edge(t, L - t, fiy, -6)
    # right: front inner side, front-wall dust ear (right), side wall right outer+inner
    outline += [(L - t, -H), (L, -H)]            # step to front outer wall
    ear = H - 2 * t
    outline += [(L + ear, -H + 6), (L + ear, -6), (L, 0)]   # dust ear on front wall (chamfered)
    outline += [(L + H, 0), (L + H, t)]
    xr = L + 2 * H - t
    outline += [(xr, t + 4)]
    for yc in (W * 0.30, W * 0.70):                      # two locking tabs on the right inner wall
        outline += [(xr, yc - 11), (xr + 6, yc - 8), (xr + 6, yc + 8), (xr, yc + 11)]
    outline += [(xr, W - t - 4), (L + H, W - t), (L + H, W), (L, W)]
    outline += [(L + ear, W + 6), (L + ear, W + H - 6), (L, W + H)]   # dust ear on back wall
    # lid right dust flap (tapered)
    lx1 = L + t
    outline += [(lx1, W + H), (lx1 + H - 3 * t, W + H + 12), (lx1 + H - 3 * t, W + H + lidW - 12), (lx1, W + H + lidW)]
    # lid front: one tuck flap, 3 mm side relief, 6 mm corner radii (approximated by chamfer points)
    ty = W + H + lidW
    import math
    def arc(cx, cy, r, a0, a1, n=6):
        return [(cx + r * math.cos(a0 + (a1 - a0) * i / n), cy + r * math.sin(a0 + (a1 - a0) * i / n)) for i in range(n + 1)]
    outline += [(lx1, ty), (lx1 - 3, ty)]
    outline += arc(lx1 - 3 - 6, ty + s.tuck - 6, 6, 0, math.pi / 2)
    outline += arc(-t + 3 + 6, ty + s.tuck - 6, 6, math.pi / 2, math.pi)
    outline += [(-t + 3, ty), (-t, ty)]
    lx0 = -t
    outline += [(lx0, W + H + lidW), (lx0 - (H - 3 * t), W + H + lidW - 12), (lx0 - (H - 3 * t), W + H + 12), (lx0, W + H)]
    # left side: back ear, side wall left, front ear
    outline += [(0, W + H), (-ear, W + H - 6), (-ear, W + 6), (0, W), (-H, W), (-H, W - t)]
    xl = -2 * H + t
    outline += [(xl, W - t - 4)]
    for yc in (W * 0.70, W * 0.30):                      # two locking tabs on the left inner wall
        outline += [(xl, yc + 11), (xl - 6, yc + 8), (xl - 6, yc - 8), (xl, yc - 11)]
    outline += [(xl, t + 4), (-H, t), (-H, 0), (0, 0)]
    outline += [(-ear, -6), (-ear, -H + 6), (0, -H), (t, -H), (t, fiy)]
    d.cuts.append(outline)

    # ---- vents: two circles (20 % and 80 % of the 290 mm wall) through the outer ply and the same two through the
    # rolled inner ply of each side wall, so that the finished wall shows 2 aligned vents (4 per box, 8 punches on the flat).
    #
    # ALIGNMENT ARITHMETIC (v2 fix, rev B). Rolled-wall model: the inner ply folds 180 degrees about the roll fold and
    # lies flat against the inside of the outer ply, so a point on the flat at distance d from the roll fold lands at
    # distance u = H - d from the base crease (reflection about the fold). Board-thickness bend allowance is left to the
    # converter's CAD ("converter to confirm allowances in CAD").
    #   outer ply vent centre : u_out = H / 2                      = 50 / 2          = 25.0 mm from the base crease
    #   inner ply (v1 error)  : v1 placed it at the MID of the inner panel's own span, d = (H - t) / 2 = 24.2 mm
    #                           from the fold, so u_in = H - 24.2 = 25.8 mm, offset u_in - u_out = +0.8 mm (= t / 2).
    #   inner ply (v2 fix)    : require u_in = u_out  =>  d = H - H / 2 = H / 2 = 25.0 mm from the roll fold
    #                           => x_left  = -H - d = -50 - 25 = -75.0  (= -1.5 H)
    #                              x_right =  L + H + d = 385 + 50 + 25 = 460.0 (= L + 1.5 H)
    #   check: u_in = H - d = 50 - 25.0 = 25.0 = u_out, centre offset 0.000 mm.
    # Along the wall (y) the inner ply is attached to the outer ply along the roll fold, so y is unchanged by the roll:
    # both plies use y = 0.20 W = 58.0 mm and 0.80 W = 232.0 mm from the base corner. The inner ply spans y = t .. W - t,
    # so both vents sit well inside it (nearest edge 56.4 mm / 56.4 mm away).
    vent_d = 10.0
    d.vents = []
    for xo in (-H / 2, L + H / 2):                                   # outer ply: 25.0 from the base crease
        for yy in (W * 0.20, W * 0.80):
            d.vents.append(('outer', xo, yy, vent_d))
    for xi in (-H - H / 2, L + H + H / 2):                           # inner ply: 25.0 from the roll fold
        for yy in (W * 0.20, W * 0.80):
            d.vents.append(('inner', xi, yy, vent_d))
    for _ply, x, yy, dia in d.vents:
        d.holes.append(('oval', x, yy, dia, dia))
    # base slots receiving the inner-wall tabs
    for i in range(2):
        cxs = t + (L - 2 * t) * (i + 1) / 3
        d.holes.append(('slot', cxs - 12, 2.5, 24, 2.5))          # front-inner tabs
    for yc in (W * 0.30, W * 0.70):                               # side-inner tabs
        d.holes.append(('slot', 2.5, yc - 12, 2.5, 24))
        d.holes.append(('slot', L - 5.0, yc - 12, 2.5, 24))
    for i in range(1):
        pass

    # ---- creases
    C = d.creases
    C += [((0, 0), (L, 0)), ((0, W), (L, W)), ((0, 0), (0, W)), ((L, 0), (L, W))]           # base
    C += [((t, -H), (L - t, -H))]                                                            # front roll fold
    C += [((-H, t), (-H, W - t)), ((L + H, t), (L + H, W - t))]                             # side roll folds
    C += [((0, -H), (0, 0)), ((L, -H), (L, 0)), ((0, W), (0, W + H)), ((L, W), (L, W + H))]  # ear folds
    C += [((-t, W + H), (L + t, W + H))]                                                     # lid hinge
    C += [((-t + 3, W + H + lidW), (L + t - 3, W + H + lidW))]                               # lid front (tuck) fold
    C += [((-t, W + H), (-t, W + H + lidW)), ((L + t, W + H), (L + t, W + H + lidW))]       # lid dust flaps

    d.dims += [((0, -2 * H - 20), (L, -2 * H - 20), f'{L:.0f} internal'),
               ((-2 * H - 20, 0), (-2 * H - 20, W), f'{W:.0f} internal'),
               ((L + 2 * H + 18, 0), (L + 2 * H + 18, H), f'H {H:.0f}')]
    xs = [p[0] for p in outline]; ys = [p[1] for p in outline]
    d.bbox = (min(xs), min(ys), max(xs), max(ys))
    return d

if __name__ == '__main__':
    d = build(); print('bbox', [round(v, 1) for v in d.bbox], 'sheet', round(d.bbox[2] - d.bbox[0], 1), 'x', round(d.bbox[3] - d.bbox[1], 1))
