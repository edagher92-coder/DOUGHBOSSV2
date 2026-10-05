"""Dough Boss dozen box: production files, dieline v2 (rev B draft, 3 Oct 2026).
Writes out/v2/DoughBoss-DozenBox-Dieline-v2.pdf (separated, spot colours, overprint, layers, page boxes) and
out/v2/DoughBoss-DozenBox-Die-v2.dxf (CUT / CREASE / VENT layers) from the SAME geometry (dieline.build()).
Run:  python3 build_v2.py
"""
import os, math
from contextlib import contextmanager
from reportlab.pdfgen import canvas
from reportlab.lib.units import mm
from reportlab.lib.utils import simpleSplit
from reportlab.pdfbase import pdfmetrics
import pikepdf
from pikepdf import Name, Dictionary, Array
import dieline
import art_v2 as A
from spots import (WHITE, EMBER, DIE_CUT, DIE_CREASE, DIMS, INSIDE, REG, PREV_BLACK, PREV_KRAFT, setf, sets)
from reportlab.pdfgen.canvas import FILL_EVEN_ODD

D = os.path.dirname(os.path.abspath(__file__))
OUT = f'{D}/out/v2'
os.makedirs(OUT, exist_ok=True)
PDF_RAW = f'{OUT}/_raw.pdf'
PDF = f'{OUT}/DoughBoss-DozenBox-Dieline-v2.pdf'
DXF = f'{OUT}/DoughBoss-DozenBox-Die-v2.dxf'

s = dieline.Spec()
d = dieline.build(s)
x0, y0, x1, y1 = d.bbox
TW, TH = x1 - x0, y1 - y0                      # blank (die outline bounding rectangle) = TrimBox
M = 45.0                                       # page margin outside the trim: crop marks, registration, overall dimensions
INFO = 150.0                                   # information strip along the bottom of every page
CW, CH = TW + 2 * M, TH + 2 * M                # one sheet cell
PT = 25.4 / 72.0                               # 1 pt in mm (canvas is scaled to mm)
HAIR = 0.25 * PT                               # 0.25 pt line
BLEED, SAFE, CLEAR = s.bleed, s.safe, 6.0      # 3 mm / 5 mm / 6 mm clear of cut-outs and vents

PART, REV, DATE = 'DB-CAT-DOZEN', 'B (draft)', '3 Oct 2026'

# ------------------------------------------------------------------ layers (optional content)
LAYERS = [  # (name, key, prints)
    ('BOARD PREVIEW (non-printing)', 'ocPrev', False),
    ('WHITE OPAQUE', 'ocWhite', True),
    ('EMBER PMS 485 C', 'ocEmber', True),
    ('INSIDE DARK', 'ocInside', True),
    ('CUT', 'ocCut', True),
    ('CREASE', 'ocCrease', True),
    ('VENT', 'ocVent', True),
    ('DIMENSIONS (non-printing)', 'ocDims', False),
    ('MARKS', 'ocMarks', True),
]
KEY = {n: k for n, k, _ in LAYERS}


@contextmanager
def layer(c, name):
    c.addLiteral(f'/OC /{KEY[name]} BDC')
    try:
        yield
    finally:
        c.addLiteral('EMC')


# ------------------------------------------------------------------ text and dimension helpers (all in the DIMENSIONS spot)
def T(c, x, y, txt, size=3.2, font='Barlow', anchor='l', rot=0, col=DIMS):
    c.saveState()
    setf(c, col)
    c.translate(x, y); c.rotate(rot)
    c.setFont(font, size)
    {'l': c.drawString, 'c': c.drawCentredString, 'r': c.drawRightString}[anchor](0, 0, txt)
    c.restoreState()


def line(c, a, b, col=DIMS, w=HAIR, dash=None):
    c.saveState(); sets(c, col); c.setLineWidth(w)
    c.setDash(*dash) if dash else c.setDash()
    c.line(a[0], a[1], b[0], b[1]); c.restoreState()


def arrow(c, tip, ang, size=2.4, half=0.75, col=DIMS):
    c.saveState(); setf(c, col)
    c.translate(*tip); c.rotate(ang)
    p = c.beginPath(); p.moveTo(0, 0); p.lineTo(size, half); p.lineTo(size, -half); p.close()
    c.drawPath(p, stroke=0, fill=1); c.restoreState()


def dim_h(c, xa, xb, y, txt, ya=None, yb=None, text_dy=1.2, size=3.2, ext_over=1.5):
    """Horizontal dimension from xa to xb at height y. ya/yb = where the extension lines start (None = no extension line)."""
    line(c, (xa, y), (xb, y))
    arrow(c, (xa, y), 0 if xb > xa else 180); arrow(c, (xb, y), 180 if xb > xa else 0)
    for xx, yy in ((xa, ya), (xb, yb if yb is not None else ya)):
        if yy is not None:
            sgn = 1 if y > yy else -1
            line(c, (xx, yy + sgn * 1.0), (xx, y + sgn * ext_over))
    T(c, (xa + xb) / 2, y + text_dy, txt, size, 'BarlowSB', 'c')


def dim_v(c, ya, yb, x, txt, xa=None, xb=None, text_dx=-1.2, size=3.2, ext_over=1.5):
    """Vertical dimension from ya to yb at x. xa/xb = extension line starts."""
    line(c, (x, ya), (x, yb))
    arrow(c, (x, ya), 90 if yb > ya else 270); arrow(c, (x, yb), 270 if yb > ya else 90)
    for yy, xx in ((ya, xa), (yb, xb if xb is not None else xa)):
        if xx is not None:
            sgn = 1 if x > xx else -1
            line(c, (xx + sgn * 1.0, yy), (x + sgn * ext_over, yy))
    T(c, x + text_dx, (ya + yb) / 2, txt, size, 'BarlowSB', 'c', rot=90)


def leader(c, pts, txt, anchor='l', size=3.2):
    for a, b in zip(pts[:-1], pts[1:]):
        line(c, a, b)
    arrow(c, pts[0], math.degrees(math.atan2(pts[1][1] - pts[0][1], pts[1][0] - pts[0][0])), size=1.8, half=0.55)
    T(c, pts[-1][0] + (1.2 if anchor == 'l' else -1.2), pts[-1][1] - 1.0, txt, size, 'BarlowSB', anchor)


def para(c, x, y, width, txt, size=2.9, lead=3.9, font='Barlow', col=DIMS):
    """Wrapped paragraph, top-left at (x, y). Returns y below the last line."""
    for ln in simpleSplit(txt, font, size, width):
        T(c, x, y - size * 0.8, ln, size, font, 'l', col=col)
        y -= lead
    return y


# ------------------------------------------------------------------ die drawing (same geometry feeds the DXF)
def poly_path(c, pts, closed=True):
    p = c.beginPath(); p.moveTo(*pts[0])
    for q in pts[1:]: p.lineTo(*q)
    if closed: p.close()
    return p


def die_cut(c):
    with layer(c, 'CUT'):
        c.saveState(); sets(c, DIE_CUT); c.setLineWidth(HAIR); c.setDash(); c.setLineJoin(0)
        for poly in d.cuts:
            c.drawPath(poly_path(c, poly), stroke=1, fill=0)
        for kind, cx, cy, w, h in d.holes:
            if kind == 'slot':
                c.rect(cx, cy, w, h, stroke=1, fill=0)
        c.restoreState()


def die_vent(c):
    with layer(c, 'VENT'):
        c.saveState(); sets(c, DIE_CUT); c.setLineWidth(HAIR); c.setDash()
        for kind, cx, cy, w, h in d.holes:
            if kind == 'oval':
                assert w == h, 'vents are circles'
                c.circle(cx, cy, w / 2, stroke=1, fill=0)
        c.restoreState()


def die_crease(c):
    with layer(c, 'CREASE'):
        c.saveState(); sets(c, DIE_CREASE); c.setLineWidth(HAIR); c.setDash(3, 2)
        for a, b in d.creases:
            c.line(a[0], a[1], b[0], b[1])
        c.restoreState()


def die_all(c):
    die_cut(c); die_crease(c); die_vent(c)


# ------------------------------------------------------------------ board preview (non-printing layer, DeviceCMYK, never separated)
BOARD_BLACK, BOARD_KRAFT = PREV_BLACK, PREV_KRAFT


def preview(c, spot):
    """Non-printing board preview in its own spot (never process colour), holes cut out with an even-odd fill."""
    with layer(c, 'BOARD PREVIEW (non-printing)'):
        c.saveState()
        setf(c, spot)
        p = poly_path(c, d.cuts[0])
        for kind, cx, cy, w, h in d.holes:
            if kind == 'oval': p.circle(cx, cy, w / 2)
            else: p.rect(cx, cy, w, h)
        c.drawPath(p, stroke=0, fill=1, fillMode=FILL_EVEN_ODD)
        c.restoreState()


# ------------------------------------------------------------------ artwork on the panels
def panel_art(c, name, fn):
    x, y, w, h, r = d.panels[name]
    c.saveState()
    p = c.beginPath(); p.rect(x - BLEED, y - BLEED, w + 2 * BLEED, h + 2 * BLEED); c.clipPath(p, stroke=0, fill=0)
    c.translate(x + w / 2, y + h / 2); c.rotate(r)
    rw, rh = (w, h) if r in (0, 180, -180) else (h, w)
    c.translate(-rw / 2, -rh / 2)
    fn(c, rw, rh)
    c.restoreState()


OUTSIDE = {'lid': A.lid_final, 'front': A.front_final, 'back': A.back_final,
           'side_left': A.side_final, 'side_right': A.side_final}


def outside_art(c, plate):
    """Draw one plate's outside artwork inside its own layer."""
    nm = plate.spotName
    with layer(c, nm):
        A.set_active(plate)
        for name, fn in OUTSIDE.items():
            panel_art(c, name, fn)
        A.set_active(None)


def inside_art(c):
    """Inside print as seen from inside: the sheet mirrored left-right; art drawn un-mirrored (as v1)."""
    cx_m = (x0 + x1) / 2
    with layer(c, 'INSIDE DARK'):
        A.set_active(INSIDE)
        x, y, w, h, r = d.panels['lid']
        mx = 2 * cx_m - (x + w)
        c.saveState(); c.translate(mx, y); A.inside_lid(c, w, h); c.restoreState()
        A.set_active(None)


def mirrored(c, fn):
    cx_m = (x0 + x1) / 2
    c.saveState(); c.translate(2 * cx_m, 0); c.scale(-1, 1); fn(c); c.restoreState()


# ------------------------------------------------------------------ marks
def marks(c):
    """Crop marks 5 mm clear of trim (outside the 3 mm bleed), registration targets at mid-sides. Colourant 'All'."""
    with layer(c, 'MARKS'):
        c.saveState(); sets(c, REG); c.setLineWidth(HAIR); c.setDash()
        off, ln = 5.0, 10.0
        for (cx, sx) in ((x0, -1), (x1, 1)):
            for (cy, sy) in ((y0, -1), (y1, 1)):
                c.line(cx + sx * off, cy, cx + sx * (off + ln), cy)
                c.line(cx, cy + sy * off, cx, cy + sy * (off + ln))
        mx, my, g = (x0 + x1) / 2, (y0 + y1) / 2, 14.0
        for (tx, ty) in ((x0 - g, my), (x1 + g, my), (mx, y0 - g), (mx, y1 + g)):
            c.circle(tx, ty, 2.5, stroke=1, fill=0)
            c.line(tx - 4.5, ty, tx + 4.5, ty); c.line(tx, ty - 4.5, tx, ty + 4.5)
        c.restoreState()


# ------------------------------------------------------------------ page-2 annotation (dimensions, panel names, keep-out rings)
def panel_labels(c):
    P = d.panels
    def lab(name, txt, sub, rot=0, dx=0, dy=0):
        x, y, w, h, r = P[name]
        cx, cy = x + w / 2 + dx, y + h / 2 + dy
        if rot == 0:
            T(c, cx, cy + 1.3, txt, 4.0, 'BarlowB', 'c'); T(c, cx, cy - 3.2, sub, 3.2, 'Barlow', 'c')
        else:   # rotated 90: first line is left of the second
            T(c, cx - 0.5, cy, txt, 4.0, 'BarlowB', 'c', rot=90); T(c, cx + 3.7, cy, sub, 3.2, 'Barlow', 'c', rot=90)
    lab('base', 'BASE', f'{s.L:.0f} x {s.W:.0f}', dy=25)
    lab('front', 'FRONT WALL (outer ply)', f'{s.L:.0f} x {s.H:.0f}', dx=-40, dy=0)
    lab('front_inner', 'FRONT WALL (rolled inner ply)', f'{s.L - 2 * s.t:.1f} x {s.H - s.t:.1f}', dx=-20)
    lab('back', 'BACK WALL', f'{s.L:.0f} x {s.H:.0f}')
    lab('lid', 'LID (hinged on back wall)', f'{s.L + 2 * s.t:.1f} x {s.W + s.t:.1f}')
    lab('lid_front', 'LID TUCK FLAP', f'{s.L + 2 * s.t - 6:.1f} x {s.tuck:.0f}')
    lab('side_left', 'SIDE WALL', f'{s.H:.0f} x {s.W:.0f}', rot=90)
    lab('side_right', 'SIDE WALL', f'{s.H:.0f} x {s.W:.0f}', rot=90)
    lab('side_left_inner', 'SIDE (rolled inner ply)', f'{s.H - s.t:.1f} x {s.W - 2 * s.t:.1f}', rot=90)
    lab('side_right_inner', 'SIDE (rolled inner ply)', f'{s.H - s.t:.1f} x {s.W - 2 * s.t:.1f}', rot=90)


def keepout_rings(c):
    c.saveState(); sets(c, DIMS); c.setLineWidth(HAIR); c.setDash(1.2, 1.2)
    for kind, cx, cy, w, h in d.holes:
        if kind == 'oval':
            c.circle(cx, cy, w / 2 + CLEAR, stroke=1, fill=0)
        else:
            c.roundRect(cx - CLEAR, cy - CLEAR, w + 2 * CLEAR, h + 2 * CLEAR, CLEAR, stroke=1, fill=0)
    c.restoreState()


def dims_page(c):
    with layer(c, 'DIMENSIONS (non-printing)'):
        L, W, H, t = s.L, s.W, s.H, s.t
        panel_labels(c)
        keepout_rings(c)
        # --- orientation and basis, in the base panel
        T(c, L / 2, 148, 'OUTSIDE (PRINT SIDE) VIEW', 4.0, 'BarlowB', 'c')
        T(c, L / 2, 140, f'INTERNAL DIMENSIONS  L x W x H = {L:.0f} x {W:.0f} x {H:.0f} mm', 3.4, 'BarlowSB', 'c')
        T(c, L / 2, 133, '[CONFIRM: fit test with 12 real bakes]', 3.0, 'Barlow', 'c')
        # --- internal size
        dim_h(c, 0, L, 245, f'{L:.0f} internal L')
        dim_v(c, 0, W, 330, f'{W:.0f} internal W', text_dx=-1.4)
        dim_v(c, -H, 0, 360, f'{H:.0f} internal H', text_dx=-1.4)
        # --- overall blank
        dim_h(c, x0, x1, y0 - 26, f'BLANK {TW:.1f}', text_dy=1.5, size=3.6)
        line(c, (x0, y0 - 24), (x0, y0 - 28)); line(c, (x1, y0 - 24), (x1, y0 - 28))
        dim_v(c, y0, y1, x1 + 26, f'BLANK {TH:.1f}', text_dx=-1.5, size=3.6)
        line(c, (x1 + 24, y0), (x1 + 28, y0)); line(c, (x1 + 24, y1), (x1 + 28, y1))
        # --- tuck flap: 45 depth, 3 side relief, R6
        ty = W + H + (W + t)
        dim_v(c, ty, ty + s.tuck, -14, f'TUCK {s.tuck:.0f}', xa=-t + 3, text_dx=-1.4)
        lx1 = L + t
        leader(c, [(lx1 - 1.5, ty + 0.2), (lx1 + 14, ty + 14)], '3 side relief (each side)')
        ccx, ccy = lx1 - 3 - 6, ty + s.tuck - 6
        leader(c, [(ccx + 6 * math.cos(math.pi / 4), ccy + 6 * math.sin(math.pi / 4)), (lx1 + 14, ty + s.tuck + 8)], 'R6 (2 corners)')
        # --- lock tab 22 x 6 (front inner) and slot 24 x 2.5 (base)
        fiy = -2 * H + t
        cxt = t + (L - 2 * t) / 3
        dim_h(c, cxt - 11, cxt + 11, fiy - 17, '22', ya=fiy, text_dy=1.3)
        dim_v(c, fiy, fiy - 6, cxt + 28, '6', xa=cxt + 11, xb=cxt + 8, text_dx=1.9)
        T(c, cxt + 40, fiy - 5, 'LOCK TAB 22 x 6 (6 off)', 3.2, 'BarlowSB')
        T(c, cxt + 40, fiy - 9.2, 'front inner x2; each side inner x2 at y = 87.0, 203.0', 2.9)
        cxs = cxt
        dim_h(c, cxs - 12, cxs + 12, 16, '24', ya=5, text_dy=1.3)
        dim_v(c, 2.5, 5, cxs + 24, '2.5', xa=cxs + 12, text_dx=1.9)
        T(c, cxs + 38, 3.5, 'LOCK SLOT 24 x 2.5 (6 off)', 3.2, 'BarlowSB')
        # --- vents (left wall shown; right wall mirrored, coordinates in the schedule)
        yv1, yv2 = W * 0.20, W * 0.80
        xo, xi = -H / 2, -H - H / 2
        dim_h(c, 0, xo, 36, '25.0', ya=yv1 - 5, yb=yv1 - 5, text_dy=1.1)
        dim_h(c, -H, xi, 36, '25.0', ya=yv1 - 5, yb=yv1 - 5, text_dy=1.1)
        T(c, xo, 31.0, 'from crease', 2.7, 'Barlow', 'c'); T(c, xi, 31.0, 'from roll fold', 2.7, 'Barlow', 'c')
        xd1, xd2 = -110.0, -124.0
        dim_v(c, 0, yv1, xd1, f'{yv1:.1f}  (20 %)', xa=xi - 7, text_dx=-1.4)
        dim_v(c, 0, yv2, xd2, f'{yv2:.1f}  (80 %)', xa=xi - 7, text_dx=-1.4)
        line(c, (xi - 7, yv2), (xd2 + 1.5, yv2))
        line(c, (xd1 + 1.0, 0), (xd2 - 1.5, 0))
        T(c, xo, yv1 + 8, 'Ø10', 3.0, 'BarlowSB', 'c')
        T(c, xi, yv2 + 9, 'inner vent: centre coincides', 2.7, 'Barlow', 'c')
        T(c, xi, yv2 + 12.2, 'with outer when rolled', 2.7, 'Barlow', 'c')


def dims_all(c):
    dims_page(c)


# ------------------------------------------------------------------ information strip (title block, legend, notes, sign-off)
def info_strip(c, page_no, total, headline, sub, extra_notes=()):
    c.saveState()
    with layer(c, 'DIMENSIONS (non-printing)'):
        X = M
        top = INFO - 8
        # --- legend
        T(c, X, top, 'LINE AND PLATE LEGEND', 4.0, 'BarlowB')
        yy = top - 8
        rows = [
            ('cut', 'DIE CUT', "spot 'DIE CUT', solid 0.25 pt, overprint. Outline, tab edges, slots, vents."),
            ('crease', 'DIE CREASE', "spot 'DIE CREASE', dashed (3 mm dash, 2 mm gap) 0.25 pt, overprint. Folds, roll folds, hinge, tuck fold."),
            ('dim', 'DIMENSIONS', "spot 'DIMENSIONS', non-printing information layer, overprint. Dimensions, panel names, notes, this block."),
            ('ring', 'PRINT-FREE RING', f'dotted ring, {CLEAR:.0f} mm beyond every vent and slot (on DIMENSIONS). No print inside it.'),
            (None, 'WHITE OPAQUE', 'spot, 100 % only, knockout. White artwork plus the white underlay under every ember element, choked 0.3 mm.'),
            (None, 'EMBER PMS 485 C', 'spot, 100 % only, overprint. Prints over its own white underlay. Alternate CMYK is a screen simulation only [CONFIRM: Pantone drawdown].'),
            (None, 'INSIDE DARK', 'placeholder spot for the optional inside-lid print (page 3). Colour not decided [CONFIRM].'),
            ('reg', 'CROP / REGISTRATION', "colourant 'All': prints on every plate. Marks sit 5 mm clear of trim."),
            (None, 'BOARD PREVIEW', 'non-printing layer. Black kraft (outside), natural kraft (inside). Not a plate; never print it.'),
        ]
        for kind, name, desc in rows:
            sw = (X, X + 16)
            if kind == 'cut': line(c, (sw[0], yy - 1.5), (sw[1], yy - 1.5), DIE_CUT)
            elif kind == 'crease': line(c, (sw[0], yy - 1.5), (sw[1], yy - 1.5), DIE_CREASE, dash=(3, 2))
            elif kind == 'dim': line(c, (sw[0], yy - 1.5), (sw[1], yy - 1.5), DIMS)
            elif kind == 'ring': line(c, (sw[0], yy - 1.5), (sw[1], yy - 1.5), DIMS, dash=(1.2, 1.2))
            elif kind == 'reg': line(c, (sw[0], yy - 1.5), (sw[1], yy - 1.5), REG)
            else:
                c.saveState(); sets(c, DIMS); c.setLineWidth(HAIR); c.rect(sw[0], yy - 3.3, 16, 3.6, stroke=1, fill=0); c.restoreState()
            T(c, X + 19, yy - 2.4, name, 3.0, 'BarlowB')
            y2 = para(c, X + 19 + 33, yy + 0.4, 200 - 52, desc, 2.7, 3.5)
            yy = min(y2, yy - 5.2) - 1.9
        yy -= 1.0
        T(c, X, yy - 2.6, f'BLEED {BLEED:.0f} mm.  SAFE ZONE {SAFE:.0f} mm inside every cut and crease.', 3.3, 'BarlowB')
        T(c, X, yy - 6.8, f'KEEP ALL PRINT {CLEAR:.0f} mm CLEAR OF CUT-OUTS AND VENTS.', 3.3, 'BarlowB')
        T(c, X, yy - 11.0, 'FONTS EMBEDDED; OUTLINE BEFORE PLATE MAKING IF REQUIRED.', 3.3, 'BarlowB')

        # --- notes
        X2 = M + 215
        T(c, X2, top, 'NOTES', 4.0, 'BarlowB')
        notes = [
            f'View: from the OUTSIDE (print side). FEFCO catalogues draw from the inside: check orientation before cutting any tool.',
            f'TrimBox = blank {TW:.1f} x {TH:.1f} mm. BleedBox = trim + {BLEED:.0f} mm each side. MediaBox adds marks and this block.',
            'Registration: crop marks and targets are colourant All. Converter to confirm sheet layout, gripper edge, colour bar and mark positions.',
            'All dimensions are INTERNAL (erected box). CONVERTER TO CONFIRM ALLOWANCES IN CAD (caliper, fold, roll and bend allowances).',
            'Structure values are working values [CONFIRM with the converter\'s structural engineer before tooling].',
            'Hinge bands (ember, 5 mm either side of the hinge crease) run to the crease by design; the 5 mm safe zone does not apply to them.',
            'Flute direction: [CONFIRM: converter to propose]. No flute arrow is drawn.',
            'Vent alignment (rolled): outer centre 25.0 from base crease; inner centre 25.0 from roll fold; centre offset 0.000 mm (v1 was 0.8 mm).',
        ] + list(extra_notes)
        yy = top - 7
        for n in notes:
            yy = para(c, X2, yy, 195, '- ' + n, 2.7, 3.5) - 1.0

        # --- title block
        X3 = M + 430
        bw, bh = CW - 2 * M - 430, 80
        T(c, X3, top, 'TITLE BLOCK', 4.0, 'BarlowB')
        by = top - 5 - bh
        c.saveState(); sets(c, DIMS); c.setLineWidth(HAIR * 2); c.rect(X3, by, bw, bh, stroke=1, fill=0); c.restoreState()
        fields = [
            ('PART NO.', PART), ('REV.', REV), ('DATE', DATE), ('UNITS', 'mm   SCALE 1:1'),
            ('TITLE', 'Dough Boss catering box, dozen (12 bakes, 4 x 3)'),
            ('STYLE', 'FEFCO 0427 type [CONFIRM style with converter]'),
            ('SIZE', f'INTERNAL DIMENSIONS {s.L:.0f} x {s.W:.0f} x {s.H:.0f} mm'),
            ('BOARD', f'E-flute {s.t:.1f} mm [CONFIRM board build and caliper]'),
            ('VIEW', headline),
            ('PAGE', f'{page_no} of {total}   {sub}'),
            ('TECH PACK', 'DB-TP-CAT-001 rev A'),
        ]
        fy = by + bh - 4.8
        for k, v in fields:
            T(c, X3 + 2, fy, k, 2.6, 'BarlowB')
            vs = simpleSplit(v, 'Barlow', 2.9, bw - 22)
            T(c, X3 + 20, fy, vs[0], 2.9, 'Barlow')
            if len(vs) > 1:
                fy -= 3.3; T(c, X3 + 20, fy, vs[1], 2.9, 'Barlow')
            fy -= 5.2
        T(c, X3 + 2, by + 3.0, 'CONVERTER TO CONFIRM ALLOWANCES IN CAD', 2.9, 'BarlowB')
        T(c, X3 + 2, by + 7.0, 'STATUS: DRAFT. Not for press until sample, board and Pantone are approved.', 2.5, 'Barlow')
        # --- sign-off box
        T(c, X3, by - 4.6, 'SIGN-OFF', 3.4, 'BarlowB')
        sb_top = by - 7
        c.saveState(); sets(c, DIMS); c.setLineWidth(HAIR * 2); c.rect(X3, sb_top - 40, bw, 40, stroke=1, fill=0)
        c.setLineWidth(HAIR)
        for i, lab in enumerate(['DOUGH BOSS APPROVAL', 'CONVERTER ACKNOWLEDGEMENT']):
            ytop = sb_top - 3.5 - i * 19
            T(c, X3 + 2, ytop - 2.0, lab, 2.6, 'BarlowB')
            yl = ytop - 11.0
            for j, f in enumerate(['name', 'signature', 'date']):
                xa = X3 + 2 + j * (bw - 4) / 3
                c.line(xa, yl, xa + (bw - 4) / 3 - 3, yl)
                T(c, xa, yl - 2.8, f, 2.3, 'Barlow')
        c.restoreState()
    c.restoreState()


# ------------------------------------------------------------------ cells and pages
def cell(c, ox, oy, kind):
    """Draw one sheet (cell lower-left at page position ox, oy)."""
    c.saveState()
    c.translate(ox + M - x0, oy + INFO + M - y0)
    if kind == 'outside':
        preview(c, BOARD_BLACK)
        outside_art(c, WHITE); outside_art(c, EMBER)
        die_all(c)
    elif kind == 'die':
        die_all(c); dims_all(c)
    elif kind == 'inside':
        mirrored(c, lambda cc: preview(cc, BOARD_KRAFT))
        inside_art(c)
        mirrored(c, die_all)
    elif kind == 'p_white':
        preview(c, BOARD_BLACK); outside_art(c, WHITE)
    elif kind == 'p_ember':
        preview(c, BOARD_BLACK); outside_art(c, EMBER)
    elif kind == 'p_inside':
        mirrored(c, lambda cc: preview(cc, BOARD_KRAFT)); inside_art(c)
    elif kind == 'p_cut':
        die_cut(c); die_vent(c)
    elif kind == 'p_crease':
        die_crease(c)
    elif kind == 'p_dims':
        dims_all(c)
    marks(c)
    c.restoreState()


def boxes(c, tx0, ty0, tx1, ty1):
    c.setTrimBox((tx0 * mm, ty0 * mm, tx1 * mm, ty1 * mm))
    c.setArtBox((tx0 * mm, ty0 * mm, tx1 * mm, ty1 * mm))
    c.setBleedBox(((tx0 - BLEED) * mm, (ty0 - BLEED) * mm, (tx1 + BLEED) * mm, (ty1 + BLEED) * mm))


def new_page(c, w, h):
    c.setPageSize((w * mm, h * mm))
    c.scale(mm, mm)


SCHEDULE = None


def build_schedule():
    rows = ['FEATURE SCHEDULE (flat, mm from the base panel lower-left corner, outside view):',
            f'Blank {TW:.1f} x {TH:.1f} (x {x0:.1f} to {x1:.1f}, y {y0:.1f} to {y1:.1f}). Tuck {s.tuck:.0f}; tab 22 x 6 (6 off); slot 24 x 2.5 (6 off).']
    outer = [v for v in d.vents if v[0] == 'outer']; inner = [v for v in d.vents if v[0] == 'inner']
    rows.append('Vents, 8 punches on the flat (4 on the box), all D10, mid-height, at 20 % / 80 % of the 290 wall:')
    rows.append('outer ply (x, y): ' + '; '.join(f'({v[1]:.1f}, {v[2]:.1f})' for v in outer))
    rows.append('inner ply (x, y): ' + '; '.join(f'({v[1]:.1f}, {v[2]:.1f})' for v in inner))
    rows.append(f'Base slots (centres): front x = {t_cx(1):.1f}, {t_cx(2):.1f} at y = 3.75; sides y = {s.W*0.30:.1f}, {s.W*0.70:.1f} at x = 3.75 and {s.L-3.75:.2f}.')
    return rows


def t_cx(i):
    return s.t + (s.L - 2 * s.t) * i / 3


def build_pdf():
    c = canvas.Canvas(PDF_RAW, pagesize=(CW * mm, (CH + INFO) * mm), initialFontName='Barlow', pageCompression=1)
    c.setTitle('Dough Boss dozen catering box: dieline v2, rev B (draft)')
    c.setAuthor('Dough Boss'); c.setSubject('DB-CAT-DOZEN production files: separated dieline and artwork')
    c.setKeywords('DB-CAT-DOZEN rev B draft, 3 Oct 2026, mm, internal dimensions')
    total = 4
    PH = CH + INFO
    # page 1: outside, all plates + die
    new_page(c, CW, PH)
    cell(c, 0, 0, 'outside')
    info_strip(c, 1, total, 'OUTSIDE (print side), all plates + die', 'Outside print', [])
    boxes(c, M, INFO + M, M + TW, INFO + M + TH)
    c.showPage()
    # page 2: die only with dimensions
    new_page(c, CW, PH)
    cell(c, 0, 0, 'die')
    info_strip(c, 2, total, 'OUTSIDE (print side), die only', 'Die and dimensions', build_schedule())
    boxes(c, M, INFO + M, M + TW, INFO + M + TH)
    c.showPage()
    # page 3: inside
    new_page(c, CW, PH)
    cell(c, 0, 0, 'inside')
    info_strip(c, 3, total, 'INSIDE, viewed from inside (mirrored left-right)', 'Inside print, optional 1 colour [CONFIRM]', [])
    boxes(c, M, INFO + M, M + TW, INFO + M + TH)
    c.showPage()
    # page 4: separations proof, 3 x 2 plates at 1:1
    PW4, PH4 = 3 * CW, 2 * CH + INFO
    new_page(c, PW4, PH4)
    plates = [('WHITE OPAQUE', 'p_white', 'white artwork + white underlay (carries the choked underlay under ember)'),
              ('EMBER PMS 485 C', 'p_ember', 'ember full stops and hinge bands (overprints the white underlay)'),
              ('INSIDE DARK  (page 3 inside print)', 'p_inside', 'optional inside-lid print, viewed from inside; colour [CONFIRM]'),
              ('DIE CUT', 'p_cut', 'outline, tab edges, slots, vents'),
              ('DIE CREASE', 'p_crease', 'folds, roll folds, hinge, tuck fold'),
              ('DIMENSIONS', 'p_dims', 'non-printing information plate')]
    for i, (nm, kind, desc) in enumerate(plates):
        col, row = i % 3, 1 - i // 3
        ox, oy = col * CW, row * CH
        # cell origin: the info strip sits only under the whole sheet; shift cells above it by INFO (cell() adds INFO already)
        cell(c, ox, oy, kind)
        with layer(c, 'DIMENSIONS (non-printing)'):
            T(c, ox + M, oy + INFO + CH - 14, f'PLATE: {nm}', 6.0, 'BarlowB')
            T(c, ox + M, oy + INFO + CH - 21, desc, 3.4, 'Barlow')
    info_strip(c, 4, total, 'SEPARATIONS PROOF, each plate alone (outside view; inside plate viewed from inside)',
               'Separations proof, 1:1', ['Each cell shows one plate on a non-printing backdrop (black kraft, natural kraft or pale grey). Captions and this block are on the DIMENSIONS layer.',
                                          'Plate cells carry the registration marks (colourant All) so alignment can be checked on every plate.'])
    # TrimBox/BleedBox on the proof sheet: the plate grid (a proof, not a print page)
    boxes(c, 10, INFO + 10, PW4 - 10, PH4 - 10)
    c.showPage()
    c.save()


# ------------------------------------------------------------------ post-process: optional-content layers, PDF 1.6
def postprocess():
    pdf = pikepdf.open(PDF_RAW)
    ocgs = {}
    for name, key, prints in LAYERS:
        usage = Dictionary(View=Dictionary(ViewState=Name.ON),
                           Print=Dictionary(PrintState=Name.ON if prints else Name.OFF, Subtype=Name.Print))
        ocgs[key] = pdf.make_indirect(Dictionary(Type=Name.OCG, Name=name, Usage=usage))
    for page in pdf.pages:
        raw = page.Contents.read_bytes() if not isinstance(page.Contents, pikepdf.Array) else b''.join(x.read_bytes() for x in page.Contents)
        used = {k: ocgs[k] for k in ocgs if f'/OC /{k} BDC'.encode() in raw}
        res = page.Resources
        res.Properties = Dictionary({f'/{k}': v for k, v in used.items()})
    nonprint = [ocgs[k] for _, k, p in LAYERS if not p]
    pdf.Root.OCProperties = Dictionary(
        OCGs=Array(list(ocgs.values())),
        D=Dictionary(Name='Dough Boss dieline v2 layers', Order=Array(list(ocgs.values())),
                     ON=Array(list(ocgs.values())), OFF=Array([]),
                     AS=Array([Dictionary(Event=Name.Print, Category=Array([Name.Print]), OCGs=Array(nonprint)),
                               Dictionary(Event=Name.View, Category=Array([Name.View]), OCGs=Array(list(ocgs.values())))])))
    pdf.docinfo['/Title'] = 'Dough Boss dozen catering box: dieline v2, rev B (draft)'
    pdf.save(PDF, min_version='1.6', object_stream_mode=pikepdf.ObjectStreamMode.disable)
    pdf.close()
    os.remove(PDF_RAW)


# ------------------------------------------------------------------ DXF (R2010, mm): CUT / CREASE / VENT from the same geometry
def build_dxf():
    import ezdxf
    doc = ezdxf.new('R2010', setup=True)
    doc.units = ezdxf.units.MM
    doc.header['$MEASUREMENT'] = 1
    doc.header['$INSUNITS'] = 4
    if 'DASHED' not in doc.linetypes:
        doc.linetypes.add('DASHED', pattern=[5.0, 3.0, -2.0], description='Dashed (3 mm dash, 2 mm gap)')
    doc.layers.add('CUT', color=1, linetype='Continuous')
    doc.layers.add('CREASE', color=5, linetype='DASHED')
    doc.layers.add('VENT', color=3, linetype='Continuous')
    msp = doc.modelspace()
    for poly in d.cuts:
        msp.add_lwpolyline(poly, close=True, dxfattribs={'layer': 'CUT'})
    for kind, cx, cy, w, h in d.holes:
        if kind == 'slot':
            msp.add_lwpolyline([(cx, cy), (cx + w, cy), (cx + w, cy + h), (cx, cy + h)], close=True, dxfattribs={'layer': 'CUT'})
        else:
            msp.add_circle((cx, cy), w / 2, dxfattribs={'layer': 'VENT'})
    for a, b in d.creases:
        msp.add_line(a, b, dxfattribs={'layer': 'CREASE'})
    doc.saveas(DXF)


if __name__ == '__main__':
    build_pdf()
    postprocess()
    build_dxf()
    print('ok', PDF, DXF)
