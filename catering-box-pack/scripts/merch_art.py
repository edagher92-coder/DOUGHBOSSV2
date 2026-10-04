"""Dough Boss merchandise placement diagrams (reportlab). Units: mm.

Run from anywhere:  python3 merch_art.py
Writes flats/merch/<item>.png (200 dpi) and flats/merch/merch-diagrams.pdf.

What is exact and what is schematic
  - The wordmark (art.wordmark), headline lines, sticker layout, seal and liner are drawn at their real size and
    position, scaled uniformly for the page. Every dimension line states the real size in mm.
  - Garment, bag and shopfront OUTLINES are schematic flats with indicative proportions. They are not a size chart.
    Only the figures quoted in sections/merch.md (blank dimensions) are used where the supplier published them.
Spec text for these placements lives in sections/merch-techpacks.md; keep the two in step.
"""
import os
import sys
import math
import subprocess

D = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, D)
import art  # noqa: E402  (registers Bebas, Barlow, BarlowSB, BarlowC; provides wordmark, tracked, seal_final, liner)

from reportlab.pdfgen import canvas  # noqa: E402
from reportlab.pdfbase import pdfmetrics  # noqa: E402
from reportlab.lib.colors import Color, HexColor  # noqa: E402

OUT = os.path.join(D, 'flats', 'merch')
os.makedirs(OUT, exist_ok=True)

# ---------------------------------------------------------------- tokens
CREAM, EMBER, CHAR = art.INK_WHITE, art.EMBER, art.CHAR
GARMENT = HexColor('#2a2724')      # black garment, lifted so cream art and dimension lines read on it
GARMENT_LINE = HexColor('#8d867b')
PAPER = HexColor('#ffffff')
INK = HexColor('#1c1815')
MUTE = HexColor('#6e675d')
DIM = HexColor('#0098cc')
DIMTXT = HexColor('#04445f')
GUIDE = HexColor('#9a948a')
CUT = HexColor('#d6008f')
OKGREEN = HexColor('#2d7a3e')

MINS = {'digital': 25, 'screen': 45, 'dtf': 45, 'embroidery': 90, 'vinyl': 110}   # brand-identity.md minimum box widths
WM_RATIO = 62.60454545454546 / 10.0                                               # box width per mm of type size (art.wordmark)
TRACK_HEAD = 0.01                                                                  # headline tracking (brand-identity.md)
DATE = '3 Oct 2026'


def wm_size(width):
    """Bebas type size (mm) that gives a wordmark box of the stated width."""
    return width / WM_RATIO


def wm_dims(width):
    s = wm_size(width)
    return dict(size=s, w=width, h=s * 18.636363636363637 / 10.0 * 1.0, border=2 * s / 22.0, cap=0.70 * s)


def place_wordmark(c, x, y, width, process, ink=CREAM, dot=EMBER, anchor='l'):
    """Draw the wordmark so its box is `width` mm wide, bottom-left at (x, y). Enforces the process minimum."""
    assert width >= MINS[process], f'wordmark {width} mm is below the {process} minimum {MINS[process]} mm'
    s = wm_size(width)
    W, H = art.wordmark(c, x, y, s, ink=ink, dot=dot, anchor=anchor)
    return W, H


def tw(text, font, size, tr):
    return sum(pdfmetrics.stringWidth(ch, font, size) for ch in text) + tr * size * (len(text) - 1)


def headline(c, x, y, text, width=None, size=None, ink=CREAM, anchor='l', dot=True, tr=TRACK_HEAD):
    """Bebas headline with an ember full stop. Give `width` (mm, incl. the stop) or `size`. (x, y) = baseline.
    Returns (width, size, cap)."""
    unit = tw(text, 'Bebas', 1.0, tr) + tr + (pdfmetrics.stringWidth('.', 'Bebas', 1.0) if dot else 0)
    if size is None:
        size = width / unit
    full = unit * size
    x0 = x - full / 2 if anchor == 'c' else (x - full if anchor == 'r' else x)
    w = art.tracked(c, x0, y, text, 'Bebas', size, tr, ink)
    if dot:
        c.setFillColor(EMBER)
        c.setFont('Bebas', size)
        c.drawString(x0 + w + tr * size, y, '.')
    return full, size, 0.70 * size


def barlow(c, x, y, text, size, font='BarlowSB', ink=CREAM, anchor='l', tr=0.0):
    w = tw(text, font, size, tr)
    x0 = x - w / 2 if anchor == 'c' else (x - w if anchor == 'r' else x)
    if tr:
        art.tracked(c, x0, y, text, font, size, tr, ink)
    else:
        c.setFillColor(ink)
        c.setFont(font, size)
        c.drawString(x0, y, text)
    return w


# ---------------------------------------------------------------- page furniture (page mm)
def T(c, x, y, s, size=3.0, font='Barlow', col=INK, anchor='l'):
    c.setFillColor(col)
    c.setFont(font, size)
    if anchor == 'c':
        c.drawCentredString(x, y, s)
    elif anchor == 'r':
        c.drawRightString(x, y, s)
    else:
        c.drawString(x, y, s)


def wrap(text, font, size, width):
    words, lines, cur = text.split(), [], ''
    for w in words:
        t = (cur + ' ' + w).strip()
        if pdfmetrics.stringWidth(t, font, size) <= width:
            cur = t
        else:
            lines.append(cur)
            cur = w
    if cur:
        lines.append(cur)
    return lines


def notes(c, x, y, w, rows, size=2.9, lead=3.9, head=None):
    """rows: list of (label, text). Draws downward from y. Returns the y after the block."""
    if head:
        T(c, x, y, head, 3.6, 'BarlowC', INK)
        y -= 5.0
    for lab, txt in rows:
        lw = pdfmetrics.stringWidth(lab + '  ', 'BarlowSB', size) if lab else 0
        lines = wrap(txt, 'Barlow', size, w - lw)
        if lab:
            T(c, x, y, lab, size, 'BarlowSB', MUTE)
        for i, ln in enumerate(lines):
            T(c, x + lw, y, ln, size, 'Barlow', INK)
            y -= lead
        y -= 0.9
    return y


def page(c, W, H, title, code, scale_txt, sub=''):
    c.setFillColor(PAPER)
    c.rect(0, 0, W, H, stroke=0, fill=1)
    T(c, 10, H - 13.5, title, 10.5, 'Bebas', INK)
    T(c, W - 10, H - 11.0, f'{code}   |   {DATE}   |   {scale_txt}', 3.0, 'BarlowC', MUTE, 'r')
    if sub:
        T(c, W - 10, H - 15.2, sub, 2.7, 'Barlow', MUTE, 'r')
    c.setStrokeColor(INK)
    c.setLineWidth(0.3)
    c.line(10, H - 18, W - 10, H - 18)
    T(c, 10, 5.5, 'Dough Boss. Placement diagram, dimensions in mm. Wordmark and artwork are exact; outlines are schematic, '
                  'not a size chart. Spec: sections/merch-techpacks.md', 2.3, 'Barlow', MUTE)


class V:
    """A view: real-mm coordinates placed on the page. origin (ox, oy) in page mm, S page-mm per real mm."""

    def __init__(self, c, ox, oy, S):
        self.c, self.ox, self.oy, self.S, self.u = c, ox, oy, S, 1.0 / S

    def __enter__(self):
        self.c.saveState()
        self.c.translate(self.ox, self.oy)
        self.c.scale(self.S, self.S)
        return self

    def __exit__(self, *a):
        self.c.restoreState()

    # strokes are given in PAGE mm so they stay a constant weight at any scale
    def ln(self, x1, y1, x2, y2, w=0.3, col=GARMENT_LINE, dash=None):
        c = self.c
        c.setStrokeColor(col)
        c.setLineWidth(w * self.u)
        c.setDash(*( [d * self.u for d in dash], 0)) if dash else c.setDash()
        c.line(x1, y1, x2, y2)
        c.setDash()

    def poly(self, pts, fill=None, stroke=GARMENT_LINE, w=0.35, close=True, dash=None):
        c = self.c
        p = c.beginPath()
        p.moveTo(*pts[0])
        for q in pts[1:]:
            p.lineTo(*q)
        if close:
            p.close()
        self._paint(p, fill, stroke, w, dash)

    def _paint(self, p, fill, stroke, w, dash=None):
        c = self.c
        if fill is not None:
            c.setFillColor(fill)
        if stroke is not None:
            c.setStrokeColor(stroke)
            c.setLineWidth(w * self.u)
        c.setDash(*([d * self.u for d in dash], 0)) if dash else c.setDash()
        c.drawPath(p, stroke=1 if stroke is not None else 0, fill=1 if fill is not None else 0)
        c.setDash()

    def path(self, fn, fill=None, stroke=GARMENT_LINE, w=0.35, dash=None):
        p = self.c.beginPath()
        fn(p)
        self._paint(p, fill, stroke, w, dash)

    def rect(self, x, y, w, h, fill=None, stroke=GARMENT_LINE, lw=0.35, dash=None, r=0):
        p = self.c.beginPath()
        if r:
            p.roundRect(x, y, w, h, r)
        else:
            p.rect(x, y, w, h)
        self._paint(p, fill, stroke, lw, dash)

    def circle(self, x, y, r, fill=None, stroke=GARMENT_LINE, lw=0.35, dash=None):
        p = self.c.beginPath()
        p.circle(x, y, r)
        self._paint(p, fill, stroke, lw, dash)

    def guide(self, x1, y1, x2, y2):
        self.ln(x1, y1, x2, y2, 0.2, GUIDE, (1.6, 1.0))

    def txt(self, x, y, s, size=2.5, font='BarlowSB', col=DIMTXT, anchor='c', rot=0):
        c = self.c
        c.saveState()
        c.translate(x, y)
        c.rotate(rot)
        c.setFillColor(col)
        c.setFont(font, size * self.u)
        w = pdfmetrics.stringWidth(s, font, size * self.u)
        c.drawString(-w / 2 if anchor == 'c' else (-w if anchor == 'r' else 0), 0, s)
        c.restoreState()

    def label(self, x, y, s, size=2.5, rot=0, col=DIMTXT):
        """Text on a white pill, so it reads over dark garment or paper."""
        c, u = self.c, self.u
        w = pdfmetrics.stringWidth(s, 'BarlowSB', size * u) + 1.4 * u
        h = size * u * 1.45
        c.saveState()
        c.translate(x, y)
        c.rotate(rot)
        c.setFillColor(PAPER)
        c.roundRect(-w / 2, -h / 2, w, h, 0.4 * u, stroke=0, fill=1)
        c.setFillColor(col)
        c.setFont('BarlowSB', size * u)
        c.drawCentredString(0, -size * u * 0.33, s)
        c.restoreState()

    def arrow(self, x, y, ang, sz=1.7):
        c, u = self.c, self.u
        c.saveState()
        c.translate(x, y)
        c.rotate(math.degrees(ang))
        c.setFillColor(DIM)
        p = c.beginPath()
        p.moveTo(0, 0)
        p.lineTo(-sz * u, 0.45 * sz * u)
        p.lineTo(-sz * u, -0.45 * sz * u)
        p.close()
        c.drawPath(p, stroke=0, fill=1)
        c.restoreState()

    def dim(self, p1, p2, text, off=6.0, size=2.5, ext=True, tshift=0.5):
        """Dimension from p1 to p2 (real mm). The dimension line sits `off` PAGE-mm to the left of p1->p2."""
        u = self.u
        dx, dy = p2[0] - p1[0], p2[1] - p1[1]
        L = math.hypot(dx, dy)
        ux, uy = dx / L, dy / L
        nx, ny = -uy, ux
        sg = 1 if off >= 0 else -1
        o = off * u
        q1 = (p1[0] + nx * o, p1[1] + ny * o)
        q2 = (p2[0] + nx * o, p2[1] + ny * o)
        if ext and abs(off) > 0.5:
            for p, q in ((p1, q1), (p2, q2)):
                self.ln(p[0] + nx * 0.8 * u * sg, p[1] + ny * 0.8 * u * sg,
                        q[0] + nx * 1.3 * u * sg, q[1] + ny * 1.3 * u * sg, 0.2, DIM)
        self.ln(q1[0], q1[1], q2[0], q2[1], 0.3, DIM)
        ang = math.atan2(dy, dx)
        self.arrow(q1[0], q1[1], ang + math.pi)
        self.arrow(q2[0], q2[1], ang)
        a = math.degrees(ang)
        if a > 90 or a <= -90:
            a += 180
        mx, my = q1[0] + (q2[0] - q1[0]) * tshift, q1[1] + (q2[1] - q1[1]) * tshift
        self.label(mx, my, text, size, a)


def mmtxt(v, d=1):
    s = f'{v:.{d}f}'
    return (s.rstrip('0').rstrip('.') if '.' in s else s) + ' mm'


# ---------------------------------------------------------------- 1:1 wordmark detail (used on several pages)
def wm_detail(c, x, y, width, process, title='Wordmark detail'):
    """Wordmark on a char panel at a fitted scale, with construction dimensions. (x, y) = bottom-left of the panel."""
    d = wm_dims(width)
    clear = d['cap']
    S = min(1.0, 66.0 / (width + 2 * clear))
    pad = clear + 7.0 / S
    pw, ph = width + 2 * pad, d['h'] + 2 * pad
    c.saveState()
    c.translate(x, y)
    c.scale(S, S)
    c.setFillColor(CHAR)
    c.rect(0, 0, pw, ph, stroke=0, fill=1)
    c.setStrokeColor(GUIDE)
    c.setLineWidth(0.15 / S)
    c.setDash(1.2 / S, 0.8 / S)
    c.rect(pad - clear, pad - clear, width + 2 * clear, d['h'] + 2 * clear, stroke=1, fill=0)
    c.setDash()
    place_wordmark(c, pad, pad, width, process)
    c.restoreState()
    v = V(c, x, y, S)
    with v:
        v.dim((pad, pad + d['h']), (pad + width, pad + d['h']), mmtxt(width), 4.5)
        v.dim((pad + width, pad), (pad + width, pad + d['h']), mmtxt(d['h']), -4.5)
    page_h = ph * S
    sc = 'scale 1:1' if S >= 0.999 else f'scale 1:{1 / S:.2g}'
    T(c, x, y - 4.2, f'{title}, {sc}', 2.8, 'BarlowSB', INK)
    T(c, x, y - 7.6, f"border {d['border']:.2f} mm   cap height {d['cap']:.1f} mm   type {d['size']:.2f} mm", 2.8, 'Barlow', INK)
    T(c, x, y - 11.0, f"clear space {clear:.1f} mm (dashed)   {process} minimum {MINS[process]} mm: "
                      f"{'PASS' if width >= MINS[process] else 'FAIL'}", 2.8, 'Barlow', INK)
    return page_h


# ================================================================ geometry for garments (real mm, MED indicative)
def tee_outline(v, back=False):
    """Flat tee, origin = hem centre. Indicative proportions only."""
    seam_y = 700 if back else 640        # collar seam height at centre
    rib = 20

    def body(p):
        p.moveTo(-260, 0)
        p.lineTo(260, 0)
        p.lineTo(260, 470)
        p.lineTo(372, 540)        # sleeve underside to cuff
        p.lineTo(345, 612)        # cuff top
        p.lineTo(228, 690)        # shoulder point
        p.lineTo(95, 710)         # neck/shoulder point
        # neck seam (collar rib's lower edge): front is deep, back is shallow
        p.curveTo(95, seam_y + 40 if not back else 706, 50, seam_y, 0, seam_y)
        p.curveTo(-50, seam_y, -95, seam_y + 40 if not back else 706, -95, 710)
        p.lineTo(-228, 690)
        p.lineTo(-345, 612)
        p.lineTo(-372, 540)
        p.lineTo(-260, 470)
        p.close()
    v.path(body, fill=GARMENT, stroke=GARMENT_LINE, w=0.45)

    def collar(p):
        p.moveTo(-95, 710)
        p.lineTo(-95, 724)
        p.curveTo(-95, seam_y + 40 + rib if not back else 726, -50, seam_y + rib, 0, seam_y + rib)
        p.curveTo(50, seam_y + rib, 95, seam_y + 40 + rib if not back else 726, 95, 724)
        p.lineTo(95, 710)
        p.curveTo(95, seam_y + 40 if not back else 706, 50, seam_y, 0, seam_y)
        p.curveTo(-50, seam_y, -95, seam_y + 40 if not back else 706, -95, 710)
        p.close()
    v.path(collar, fill=HexColor('#3a3631'), stroke=GARMENT_LINE, w=0.35)
    v.ln(-260, 18, 260, 18, 0.2, GARMENT_LINE, (1.2, 0.9))     # hem stitch
    return seam_y


# ================================================================ PAGE 1: staff t-shirt
def p_tee(c):
    W, H = 297, 210
    page(c, W, H, 'STAFF T-SHIRT', 'DB_TEE_ART', 'garments 1:7.4, detail 1:1.7',
         'AS Colour Staple Tee 5001, Black. Screen print (DTF top-ups)')
    S = 0.135
    top = H - 26
    # ---- FRONT
    vF = V(c, 62, top - 735 * S, S)
    with vF:
        sy = tee_outline(vF, False)
        ax, aw = 100, 90                       # art centre from centre front toward the wearer's left (viewer's right)
        d = wm_dims(aw)
        ay = sy - 80 - d['h']                  # bottom of box
        vF.guide(0, sy, 190, sy)
        vF.guide(0, ay + d['h'], 190, ay + d['h'])
        vF.ln(0, 40, 0, 705, 0.2, GUIDE, (3, 1.5))
        vF.ln(ax, ay - 40, ax, ay + d['h'] + 60, 0.2, GUIDE, (3, 1.5))
        place_wordmark(c, ax - aw / 2, ay, aw, 'screen')
        vF.dim((185, sy), (185, ay + d['h']), '80 mm', 4.0)
        vF.dim((0, 600), (ax, 600), '100 mm to art centre', 0.0, ext=False)
        vF.dim((ax - aw / 2, ay - 30), (ax + aw / 2, ay - 30), '90 mm', 0.0, ext=False)
        vF.label(ax + aw / 2 + 62, ay + d['h'] / 2, f"{d['h']:.1f} mm high", 2.3)
        vF.txt(-150, 300, "wearer's right", 2.3, 'Barlow', CREAM)
        vF.txt(150, 300, "wearer's left", 2.3, 'Barlow', CREAM)
        vF.txt(0, 775, 'FRONT', 3.2, 'BarlowC', INK)
        vF.txt(-175, sy + 6, 'collar seam level', 2.0, 'Barlow', MUTE, 'r')
    # ---- BACK
    vB = V(c, 190, top - 735 * S, S)
    with vB:
        sy = tee_outline(vB, True)
        bw = 250
        s = bw / (tw('FEED THE WHOLE TABLE', 'Bebas', 1.0, TRACK_HEAD) + TRACK_HEAD + pdfmetrics.stringWidth('.', 'Bebas', 1.0))
        cap = 0.7 * s
        by = sy - 90 - cap
        vB.guide(-190, sy, 190, sy)
        vB.guide(-190, by + cap, 190, by + cap)
        vB.ln(0, 40, 0, 724, 0.2, GUIDE, (3, 1.5))
        vB.rect(-140, by - 3, 280, cap + 6, fill=None, stroke=GUIDE, lw=0.25, dash=(1.6, 1.0))   # 280 mm version
        headline(c, 0, by, 'FEED THE WHOLE TABLE', width=bw, anchor='c')
        vB.dim((-185, sy), (-185, by + cap), '90 mm', -4.0)
        vB.dim((-125, by - 32), (125, by - 32), '250 mm  (XSM to XL)', 0.0, ext=False)
        vB.dim((-140, by - 66), (140, by - 66), '280 mm  (2XL to 5XL)', 0.0, ext=False)
        vB.label(190, by + cap / 2, f'cap {cap:.1f}', 2.3)
        vB.txt(0, 775, 'BACK (option 1 shown)', 3.2, 'BarlowC', INK)
    # ---- notes
    y0 = 78
    notes(c, 10, y0, 78, [
        ('Front', 'Wordmark 90 x 26.8 mm, cream with ember full stop, left chest. Top of box 80 mm below the CF collar seam. '
                  'Art centre 100 mm from CF (XSM to XL), 110 mm (2XL to 5XL): proposal, confirm on the MED sample.'),
        ('Check', 'On XSM and 5XL the box must stay clear of the armhole seam.'),
    ], head='PLACEMENT')
    notes(c, 100, y0, 80, [
        ('Back', 'One line per run, centred on CB, top of cap 90 mm below the back neck seam. Bebas Neue, tracking 0.01 em. '
                 'Option 1 is shown; options 2 and 3 are in the spec sheet.'),
        ('Grade', '250 mm wide XSM to XL; 280 mm wide 2XL to 5XL [CONFIRM]. The drop from the neck seam is the same on every size.'),
    ], head='BACK AND GRADING')
    wm_detail(c, 200, 36, 90, 'screen', title='Front wordmark')


# ================================================================ PAGE 2: bib apron
def p_apron(c):
    W, H = 297, 210
    page(c, W, H, 'BIB APRON', 'DB_APRON_ART', 'apron 1:6, detail 1:1.9', 'AS Colour Carrie Apron 1082, Black. Embroidery')
    S = 0.165
    v = V(c, 78, 28, S)
    with v:
        # skirt + bib, schematic (bib width and waist height are not published: indicative)
        def ap(p):
            p.moveTo(-380, 0)
            p.lineTo(380, 0)
            p.lineTo(380, 500)
            p.curveTo(380, 560, 200, 520, 185, 600)
            p.lineTo(172, 900)
            p.lineTo(-172, 900)
            p.lineTo(-185, 600)
            p.curveTo(-200, 520, -380, 560, -380, 500)
            p.close()
        v.path(ap, fill=GARMENT, stroke=GARMENT_LINE, w=0.45)
        v.ln(-172, 884, 172, 884, 0.2, GARMENT_LINE, (1.2, 0.9))               # top hem stitch
        for sx in (-1, 1):                                                       # eyelets, strap stubs
            v.circle(sx * 140, 862, 7, fill=HexColor('#7a746a'), stroke=GARMENT_LINE, lw=0.3)
            v.circle(sx * 140, 862, 22, fill=None, stroke=GUIDE, lw=0.2, dash=(1.2, 0.9))
            v.ln(sx * 140, 869, sx * 95, 980, 0.8, GARMENT_LINE)
        # pocket 460 x 240 (published 24 x 46 cm), top 60 below the waistline (indicative)
        v.rect(-230, 200, 460, 240, fill=HexColor('#33302b'), stroke=GARMENT_LINE, lw=0.35)
        # bib wordmark: 100 mm wide, top of box 60 mm below the top hem
        aw = 100
        d = wm_dims(aw)
        top_hem = 900
        ay = top_hem - 60 - d['h']
        v.ln(0, 600, 0, 905, 0.2, GUIDE, (3, 1.5))
        v.guide(-215, top_hem, 215, top_hem)
        v.guide(-215, ay + d['h'], 215, ay + d['h'])
        place_wordmark(c, -aw / 2, ay, aw, 'embroidery')
        v.dim((205, top_hem), (205, ay + d['h']), '60 mm', 5.0)
        v.dim((-aw / 2, ay - 30), (aw / 2, ay - 30), '100 mm', 0.0, ext=False)
        v.label(aw / 2 + 62, ay + d['h'] / 2, f"{d['h']:.1f} mm", 2.3)
        v.txt(0, 740, 'keep 15 mm clear of eyelets and strap stitching', 2.1, 'Barlow', CREAM)
        # optional pocket line: Line A, 120 mm wide, 30 mm below pocket top hem
        s = 120 / (tw('FRESH FROM THE OVEN', 'Bebas', 1.0, TRACK_HEAD) + TRACK_HEAD + pdfmetrics.stringWidth('.', 'Bebas', 1.0))
        cap = 0.7 * s
        pt = 440
        base = pt - 30 - cap
        headline(c, 0, base, 'FRESH FROM THE OVEN', width=120, anchor='c')
        v.guide(-250, pt, 250, pt)
        v.guide(-250, base + cap, 250, base + cap)
        v.dim((248, pt), (248, base + cap), '30 mm', 4.0)
        v.dim((-60, base - 40), (60, base - 40), '120 mm (optional)', 0.0, ext=False)
        v.label(-230 - 36, 320, 'pocket 460 x 240', 2.3, 90)
        v.label(0, -50, 'indicative outline, 900 mm long, 760 mm at the waist', 2.3)
        v.txt(-380, 1000, 'FRONT', 3.2, 'BarlowC', INK, 'l')
    notes(c, 150, 170, 135, [
        ('Bib', 'Wordmark 100 x 29.8 mm, centred on the bib centre line, top of box 60 mm below the top hem. Cream thread, ember full stop. '
                'Keep at least 15 mm from the eyelets and strap stitching (proposal).'),
        ('Pocket', 'Optional: Line A (FRESH FROM THE OVEN.) or Line B (FEED THE WHOLE TABLE.), 120 mm wide, centred, '
                   'top of cap 30 mm below the pocket top hem. Bebas Neue, tracking 0.01 em.'),
        ('Blank', 'One size: length 90 cm, waist 76 cm, pocket 24 x 46 cm (AS Colour page, read 3 Oct 2026). '
                  'Bib width and waistline height are not published [CONFIRM from the blank].'),
    ], head='PLACEMENT')
    wm_detail(c, 150, 62, 100, 'embroidery', title='Bib wordmark')


# ================================================================ PAGE 3: cap
def p_cap(c):
    W, H = 297, 210
    page(c, W, H, 'CAP', 'DB_CAP_ART', 'cap 1:1.5, detail 1:1.7', 'AS Colour Access Cap 1130, Black. Embroidery')
    S = 0.66
    v = V(c, 92, 100, S)
    with v:
        # crown, front view (indicative). seam line y=0 is where the peak joins the crown at centre front
        def crown(p):
            p.moveTo(-100, 0)
            p.curveTo(-104, 62, -62, 108, 0, 108)
            p.curveTo(62, 108, 104, 62, 100, 0)
            p.curveTo(60, -4, -60, -4, -100, 0)
            p.close()
        v.path(crown, fill=GARMENT, stroke=GARMENT_LINE, w=0.45)

        def peak(p):
            p.moveTo(-100, 0)
            p.curveTo(-60, -4, 60, -4, 100, 0)
            p.curveTo(98, -30, 40, -48, 0, -48)
            p.curveTo(-40, -48, -98, -30, -100, 0)
            p.close()
        v.path(peak, fill=HexColor('#35312c'), stroke=GARMENT_LINE, w=0.45)
        v.ln(0, 108, 0, -2, 0.35, GARMENT_LINE)                                    # centre-front panel seam
        for sx in (-1, 1):
            v.path(lambda p, sx=sx: (p.moveTo(0, 108), p.curveTo(sx * 40, 100, sx * 80, 60, sx * 92, 8)),
                   fill=None, stroke=GARMENT_LINE, w=0.3)
        v.circle(0, 108, 4, fill=HexColor('#3a3631'), stroke=GARMENT_LINE, lw=0.3)
        aw = 90
        d = wm_dims(aw)
        ay = 15
        place_wordmark(c, -aw / 2, ay, aw, 'embroidery')
        v.guide(-125, 0, 125, 0)
        v.guide(-125, ay, 125, ay)
        v.guide(-125, ay + d['h'], 125, ay + d['h'])
        v.dim((118, 0), (118, ay), '15 mm', 7.0)
        v.dim((118, ay), (118, ay + d['h']), f"{d['h']:.1f} mm", 7.0)
        v.dim((-aw / 2, ay + d['h'] + 14), (aw / 2, ay + d['h'] + 14), '90 mm', 0.0, ext=False)
        v.txt(-96, -14, 'peak seam (centre front)', 2.3, 'Barlow', CREAM, 'l')
        v.txt(0, 124, 'FRONT, centred across the centre front seam', 3.0, 'BarlowC', INK)
        v.txt(0, -70, 'schematic: low-profile six-panel, curved peak', 2.3, 'Barlow', MUTE)
    notes(c, 175, 176, 110, [
        ('Placement', 'Wordmark 90 x 26.8 mm (embroidery minimum). Centred across the centre front seam; bottom of the box 15 mm above the peak seam.'),
        ('Stitch', 'Two thread colours (cream, ember). Sew from the centre outward. Cap file is separate from the apron file. '
                   'Under the decorator\'s cap stitch limit [CONFIRM the limit].'),
        ('Blank', 'One size, adjustable, 100% cotton, low profile, curved peak. Spot clean only.'),
    ], head='PLACEMENT')
    wm_detail(c, 175, 70, 90, 'embroidery', title='Cap wordmark')


# ================================================================ PAGE 4: tote bag
def p_tote(c):
    W, H = 297, 210
    page(c, W, H, 'TOTE BAG', 'DB_TOTE_ART', 'bags 1:5', 'AS Colour Carrie Tote 1001, Black. Screen print')
    S = 0.2
    for k, (ox, name) in enumerate([(54, 'FRONT'), (150, 'BACK (optional)')]):
        v = V(c, ox, 22, S)
        with v:
            # straps first (behind the body)
            for off in (-1, 1):
                pass
            def strap(p):
                p.moveTo(-130, 420)
                p.curveTo(-130, 760, 130, 760, 130, 420)
            for lw_, col in ((28, GARMENT_LINE), (25, HexColor('#3a3631'))):
                c.saveState()
                c.setStrokeColor(col)
                c.setLineWidth(lw_)
                pp = c.beginPath()
                strap(pp)
                c.drawPath(pp, stroke=1, fill=0)
                c.restoreState()
            v.rect(-210, 0, 420, 420, fill=GARMENT, stroke=GARMENT_LINE, lw=0.45)
            v.rect(-143, 420 - 90, 26, 90, fill=None, stroke=GUIDE, lw=0.25, dash=(1.2, 0.9))
            v.rect(117, 420 - 90, 26, 90, fill=None, stroke=GUIDE, lw=0.25, dash=(1.2, 0.9))
            v.ln(0, 40, 0, 420, 0.2, GUIDE, (3, 1.5))
            v.guide(-235, 420, 235, 420)
            if k == 0:
                aw = 250
                d = wm_dims(aw)
                ay = 420 - 110 - d['h']
                place_wordmark(c, -aw / 2, ay, aw, 'screen')
                v.guide(-235, ay + d['h'], 235, ay + d['h'])
                v.dim((228, 420), (228, ay + d['h']), '110 mm', 4.0)
                v.dim((-aw / 2, ay - 30), (aw / 2, ay - 30), '250 mm', 0.0, ext=False)
                v.label(aw / 2 + 50, ay + d['h'] / 2, f"{d['h']:.1f} mm", 2.3)
            else:
                s_ = 250 / (tw('FRESH FROM THE OVEN', 'Bebas', 1.0, TRACK_HEAD) + TRACK_HEAD + pdfmetrics.stringWidth('.', 'Bebas', 1.0))
                cap = 0.7 * s_
                base = 420 - 110 - cap
                headline(c, 0, base, 'FRESH FROM THE OVEN', width=250, anchor='c')
                us = 6 / 0.7
                barlow(c, 0, base - 14 - 6, 'doughboss.com.au', us, 'BarlowSB', CREAM, 'c')
                v.guide(-235, base + cap, 235, base + cap)
                v.dim((228, 420), (228, base + cap), '110 mm', 4.0)
                v.dim((-125, base + cap + 30), (125, base + cap + 30), '250 mm', 0.0, ext=False)
                v.label(185, base + cap / 2, f'cap {cap:.1f}', 2.3)
                v.label(185, base - 20, 'URL cap 6', 2.3)
                v.label(-185, base - 20, '14 below', 2.3)
            v.label(-210 - 20, 210, '420', 2.4, 90)
            v.txt(0, 735, name, 3.2, 'BarlowC', INK)
            v.txt(0, 350, 'strap patch depth [CONFIRM]', 2.0, 'Barlow', CREAM) if k == 0 else None
    notes(c, 214, 176, 75, [
        ('Front', 'Wordmark 250 x 74.4 mm, cream with ember full stop, centred, top of box 110 mm below the top edge.'),
        ('Back', 'Optional: FRESH FROM THE OVEN. 250 mm wide (Bebas, 0.01 em), top of cap 110 mm below the top edge, with doughboss.com.au '
                 'in Barlow at 6 mm cap height, 14 mm below the baseline.'),
    ], head='PLACEMENT')
    notes(c, 214, 108, 75, [
        ('Blank', '420 x 420 mm, gusset 95 mm, strap length 720 mm (AS Colour page, read 3 Oct 2026). Strap outline and strap-patch depth are indicative.'),
        ('Print', 'Two spot colours, cream with a white underbase, and ember. Minimum printed line 0.5 mm, open gap 1.0 mm.'),
    ], head='BLANK AND PRINT')


# ================================================================ PAGE 5: takeaway paper bag
def p_bag(c):
    W, H = 297, 210
    page(c, W, H, 'TAKEAWAY PAPER BAG', 'DB_BAG_ART', 'bags 1:5',
         'Detpak Small Paper Twist Handle Bag C400S0029, black, matte. Printed by the bag maker')
    S = 0.25
    for k, (ox, name) in enumerate([(56, 'FRONT FACE'), (154, 'BACK FACE (optional)')]):
        v = V(c, ox, 30, S)
        with v:
            for sx in (-1, 1):                                          # twisted handles (schematic)
                v.path(lambda p, sx=sx: (p.moveTo(sx * 55, 280), p.curveTo(sx * 40, 470, sx * 110, 470, sx * 95, 280)),
                       fill=None, stroke=GARMENT_LINE, w=0.5)
            v.rect(-140, 0, 280, 280, fill=GARMENT, stroke=GARMENT_LINE, lw=0.45)
            for sx in (-1, 1):                                          # handle patches, indicative
                v.rect(sx * 75 - 40, 230, 80, 50, fill=None, stroke=GUIDE, lw=0.25, dash=(1.2, 0.9))
            v.ln(0, 20, 0, 280, 0.2, GUIDE, (3, 1.5))
            v.guide(-160, 280, 160, 280)
            if k == 0:
                aw = 140
                d = wm_dims(aw)
                ay = 280 - 60 - d['h']
                place_wordmark(c, -aw / 2, ay, aw, 'digital')
                v.guide(-160, ay + d['h'], 160, ay + d['h'])
                v.dim((152, 280), (152, ay + d['h']), '60 mm', 5.0)
                v.dim((-aw / 2, ay - 30), (aw / 2, ay - 30), '140 mm', 0.0, ext=False)
                v.label(aw / 2 + 50, ay + d['h'] / 2, f"{d['h']:.1f} mm", 2.3)
            else:
                s_ = 180 / (tw('FRESH FROM THE OVEN', 'Bebas', 1.0, TRACK_HEAD) + TRACK_HEAD + pdfmetrics.stringWidth('.', 'Bebas', 1.0))
                cap = 0.7 * s_
                base = 280 - 60 - cap
                headline(c, 0, base, 'FRESH FROM THE OVEN', width=180, anchor='c')
                us = 7 / 0.7
                barlow(c, 0, base - 18 - 7, 'doughboss.com.au', us, 'BarlowSB', CREAM, 'c')
                barlow(c, 0, base - 18 - 7 - 7 - 8, '@doughboss', us, 'BarlowSB', CREAM, 'c')
                v.guide(-160, base + cap, 160, base + cap)
                v.dim((152, 280), (152, base + cap), '60 mm', 5.0)
                v.dim((-90, base + cap + 30), (90, base + cap + 30), '180 mm', 0.0, ext=False)
                v.label(120, base + cap / 2, f'cap {cap:.1f}', 2.3)
                v.label(-116, base - 25, '18 below', 2.3)
            v.label(-140 - 20, 140, '280', 2.4, 90)
            v.txt(0, 480, name, 3.2, 'BarlowC', INK)
            v.txt(0, -30, 'top-fold line = top edge; handle patches per Detpak template [CONFIRM]', 2.0, 'Barlow', MUTE)
    notes(c, 214, 176, 75, [
        ('Front', 'Wordmark 140 x 41.7 mm, centred, top of box 60 mm below the top-fold line. Final position follows Detpak\'s own print template.'),
        ('Back', 'Optional: FRESH FROM THE OVEN. 180 mm wide, top of cap 60 mm below the top-fold line; doughboss.com.au then @doughboss beneath, Barlow at 7 mm cap height.'),
    ], head='PLACEMENT')
    notes(c, 214, 112, 75, [
        ('Size', 'Listed at 280 x 280 x 150 mm; which figure is the gusset is not clear on the listing [CONFIRM with Detpak].'),
        ('Ink', 'Opaque cream on black stock is not confirmed; fallback is brown kraft with a char wordmark and ember full stop [CONFIRM with Detpak].'),
        ('Fit', 'Counter takeaway only. The catering dozen box (385 x 290 x 50 mm working size) does not fit.'),
    ], head='BLANK')


# ================================================================ PAGE 6: sticker sheet
def sticker_layout():
    """A5 sheet 148 x 210. Returns list of dict(kind, x, y, w, h) with (x, y) = top-left from the sheet's top-left, mm."""
    gap, gv, mx, my = 4.0, 6.0, 4.0, 4.0
    w80, h80 = 80 + 2 * 8.95, 23.81 + 2 * 8.95
    cap45 = 5.03
    w45, h45 = 45 + 2 * cap45, 13.40 + 2 * cap45
    items = []
    y = my + 2.0
    # R1: wordmark 80 + one dot
    xs = mx
    items.append(dict(kind='wm80', x=xs, y=y, w=w80, h=h80))
    items.append(dict(kind='dot', x=xs + w80 + gap, y=y + (h80 - 25) / 2, w=25, h=25))
    y += h80 + gv
    items.append(dict(kind='strip_a', x=(148 - 120) / 2, y=y, w=120, h=20))
    y += 20 + gv
    items.append(dict(kind='strip_b', x=(148 - 120) / 2, y=y, w=120, h=20))
    y += 20 + gv
    # R4: round 50 | two wm45 stacked | two dots stacked
    rh = 54.0
    items.append(dict(kind='round', x=mx, y=y + (rh - 50) / 2, w=50, h=50))
    sx = mx + 50 + gap
    stack_h = 2 * h45 + gap
    items.append(dict(kind='wm45', x=sx, y=y + (rh - stack_h) / 2, w=w45, h=h45))
    items.append(dict(kind='wm45', x=sx, y=y + (rh - stack_h) / 2 + h45 + gap, w=w45, h=h45))
    dx = sx + w45 + gap
    items.append(dict(kind='dot', x=dx, y=y, w=25, h=25))
    items.append(dict(kind='dot', x=dx, y=y + 25 + (rh - 50), w=25, h=25))
    y += rh + gv
    # R5: three dots
    x3 = (148 - (3 * 25 + 2 * gap)) / 2
    for i in range(3):
        items.append(dict(kind='dot', x=x3 + i * (25 + gap), y=y, w=25, h=25))
    y += 25
    return items, y + my


def p_sticker(c):
    W, H = 210, 297
    page(c, W, H, 'STICKER SHEET', 'DB_STICKER_ART', 'sheet 1:1', 'A5 (148 x 210 mm), kiss-cut, white permanent vinyl, char flood, matt laminate')
    ox, oy = 31, H - 28 - 210                    # sheet bottom-left on page
    items, used = sticker_layout()
    v = V(c, ox, oy, 1.0)
    with v:
        v.rect(0, 0, 148, 210, fill=HexColor('#f4f2ee'), stroke=INK, lw=0.4)
        for it in items:
            x, yb = it['x'], 210 - it['y'] - it['h']
            k = it['kind']
            if k in ('wm80', 'wm45'):
                wd = 80 if k == 'wm80' else 45
                r = 3.0 if k == 'wm80' else 2.0
                v.rect(x - 2, yb - 2, it['w'] + 4, it['h'] + 4, fill=None, stroke=GUIDE, lw=0.15, dash=(0.8, 0.8))
                v.rect(x, yb, it['w'], it['h'], fill=CHAR, stroke=CUT, lw=0.25, r=r)
                cl = it['w'] - wd
                place_wordmark(c, x + cl / 2, yb + cl / 2, wd, 'digital')
            elif k == 'dot':
                v.circle(x + 12.5, yb + 12.5, 14.5, fill=None, stroke=GUIDE, lw=0.15, dash=(0.8, 0.8))
                v.circle(x + 12.5, yb + 12.5, 12.5, fill=EMBER, stroke=CUT, lw=0.25)
            elif k == 'round':
                v.circle(x + 25, yb + 25, 27, fill=None, stroke=GUIDE, lw=0.15, dash=(0.8, 0.8))
                v.circle(x + 25, yb + 25, 25, fill=CHAR, stroke=CUT, lw=0.25)
                place_wordmark(c, x + 25 - 18, yb + 25 - 4.0, 36, 'digital')
                barlow(c, x + 25, yb + 25 - 14.5, '@doughboss', 4.2, 'BarlowSB', CREAM, 'c', 0.10)
            elif k in ('strip_a', 'strip_b'):
                v.rect(x - 2, yb - 2, 124, 24, fill=None, stroke=GUIDE, lw=0.15, dash=(0.8, 0.8))
                v.rect(x, yb, 120, 20, fill=CHAR, stroke=CUT, lw=0.25, r=2.0)
                txt = 'FRESH FROM THE OVEN' if k == 'strip_a' else 'FEED THE WHOLE TABLE'
                headline(c, x + 60, yb + 10 - 0.35 * 13.9, txt, size=13.9, anchor='c')
    T(c, ox, oy - 14, 'Magenta = kiss-cut path (CutContour spot). Dotted = 2 mm bleed. Positions are in the spec sheet.', 2.6, 'Barlow', MUTE)
    # dimension callouts down the right-hand side
    yy = oy + 210
    notes(c, ox, oy - 22, 150, [
        ('Sheet', f'148 x 210 mm. Margins 4 mm; 4 mm between stickers (6 mm between rows). Used height {used:.1f} mm of 210 mm.'),
        ('Stock', 'White permanent vinyl, digital full colour, char flood, matt laminate. Copy only from the verified list.'),
        ('Check', 'Smallest wordmark on the sheet is 36 mm (round sticker); digital minimum is 25 mm.'),
    ], head='NOTES')
    v2 = V(c, ox, oy, 1.0)
    with v2:
        v2.dim((0, 0), (148, 0), '148 mm', -6.0)
        v2.dim((148, 0), (148, 210), '210 mm', -6.0)


# ================================================================ PAGE 7: counter tent card
def tent_face(c, face):
    """Draw one 105 x 148 face upright (origin bottom-left of the face, top edge = the fold). Returns dict of placements."""
    c.setFillColor(CHAR)
    c.rect(0, 0, 105, 148, stroke=0, fill=1)
    pl = {}
    wmw = 60
    d = wm_dims(wmw)
    top_wm = 18.0
    place_wordmark(c, (105 - wmw) / 2, 148 - top_wm - d['h'], wmw, 'digital')
    pl['wm'] = (wmw, d['h'], top_wm)
    # headline, two lines, longer line 85 mm wide
    size = 85.0 / (tw('WHOLE TABLE', 'Bebas', 1.0, TRACK_HEAD) + TRACK_HEAD + pdfmetrics.stringWidth('.', 'Bebas', 1.0))
    cap = 0.7 * size
    if face == 1:
        a, b = 'FEED THE', 'WHOLE TABLE'
        top_h = 56.0
        base1 = 148 - top_h - cap
        base2 = base1 - size
        headline(c, 52.5, base1, a, size=size, anchor='c', dot=False)
        headline(c, 52.5, base2, b, size=size, anchor='c')
        pl['head'] = (85.0, size, cap, top_h, size)
        barlow(c, 52.5, 148 - 104.0, 'Catering', 6.0, 'BarlowSB', CREAM, 'c')
        barlow(c, 52.5, 148 - 114.0, 'catering@doughboss.com.au', 5.0, 'Barlow', CREAM, 'c')
        barlow(c, 52.5, 148 - 122.0, 'doughboss.com.au', 5.0, 'Barlow', CREAM, 'c')
        pl['text'] = (104.0, 114.0, 122.0)
    else:
        top_h = 56.0
        l1 = 'FRESH FROM'
        base1 = 148 - top_h - cap
        base2 = base1 - size
        headline(c, 52.5, base1, l1, size=size, anchor='c', dot=False)
        headline(c, 52.5, base2, 'THE OVEN', size=size, anchor='c')
        pl['head'] = (None, size, cap, top_h, size)
        barlow(c, 52.5, 148 - 104.0, 'Allergen information', 5.0, 'Barlow', CREAM, 'c')
        barlow(c, 52.5, 148 - 110.5, 'available on request.', 5.0, 'Barlow', CREAM, 'c')
        barlow(c, 52.5, 148 - 122.0, '@doughboss', 5.0, 'BarlowSB', CREAM, 'c')
        pl['text'] = (104.0, 110.5, 122.0)
    return pl


def p_tent(c):
    W, H = 297, 210
    page(c, W, H, 'COUNTER TENT CARD', 'DB_TENT_ART', 'flat 1:1.8, faces 1:1.25',
         'A-frame, two A6 faces, 350 gsm coated board, char flood, matt laminate both sides')
    # flat: face 1 (below fold) upright, face 2 (above fold) rotated 180 degrees
    S = 0.55
    fx, fy = 14, 22
    c.saveState()
    c.translate(fx, fy)
    c.scale(S, S)
    c.saveState()
    tent_face(c, 1)
    c.restoreState()
    c.saveState()
    c.translate(105, 296)
    c.rotate(180)
    tent_face(c, 2)
    c.restoreState()
    c.setStrokeColor(CUT)
    c.setLineWidth(0.3 / S)
    c.rect(0, 0, 105, 296, stroke=1, fill=0)
    c.setDash(2 / S, 1.2 / S)
    c.line(0, 148, 105, 148)
    c.setDash()
    c.restoreState()
    v = V(c, fx, fy, S)
    with v:
        v.dim((0, 0), (0, 296), '296 mm flat', -6.5)
        v.dim((0, 0), (105, 0), '105 mm', -5.0)
        v.label(52.5, 148, 'score / fold at 148 mm', 2.2)
        v.txt(-14, 74, 'FACE 1 (customer side)', 2.3, 'Barlow', MUTE, 'c', 90)
        v.txt(-14, 222, 'FACE 2 (rotated 180 deg on the flat)', 2.3, 'Barlow', MUTE, 'c', 90)
    # standing faces at larger scale
    S2 = 0.8
    for k, ox in ((1, 100), (2, 200)):
        c.saveState()
        c.translate(ox, 52)
        c.scale(S2, S2)
        pl = tent_face(c, k)
        c.setStrokeColor(CUT)
        c.setLineWidth(0.25 / S2)
        c.rect(0, 0, 105, 148, stroke=1, fill=0)
        c.setStrokeColor(GUIDE)
        c.setLineWidth(0.15 / S2)
        c.setDash(1.2 / S2, 0.8 / S2)
        c.rect(4, 4, 97, 140, stroke=1, fill=0)
        c.setDash()
        c.restoreState()
        v = V(c, ox, 52, S2)
        wmw, wmh, topwm = pl['wm']
        _, size, cap, toph, lead = pl['head']
        with v:
            v.dim((105, 148), (105, 148 - topwm), f'{topwm:g}', 5.0)
            v.dim((105, 148 - topwm - wmh), (105, 148 - toph), f'{toph - topwm - wmh:.1f}', 5.0)
            v.dim((105 + 0, 148 - toph), (105, 148 - toph - cap - lead), f'{cap + lead:.1f}', 5.0) if False else None
            v.dim((22.5, 148 - topwm), (82.5, 148 - topwm), '60', 4.0)
            if k == 1:
                v.dim((10, 148 - toph - cap - lead - 6), (95, 148 - toph - cap - lead - 6), '85', 0.0, ext=False)
        T(c, ox, 52 + 148 * S2 + 3, f'FACE {k}' + (' (customer side)' if k == 1 else ''), 3.2, 'BarlowC', INK)
        T(c, ox, 52 - 4.0, 'Magenta = trim. Dashed = 4 mm safe area. Figures: mm from the fold edge.', 2.2, 'Barlow', MUTE)
    notes(c, 100, 40, 185, [
        ('Face 1', 'Wordmark 60 mm wide, top 18 mm below the fold. Headline FEED THE / WHOLE TABLE. in Bebas Neue (the longer line 85 mm wide, ember full stop), top of cap 56 mm below the fold. '
                   '"Catering" Barlow 6 mm type, then catering@doughboss.com.au and doughboss.com.au at 5 mm type.'),
        ('Face 2', 'Wordmark as Face 1. FRESH FROM / THE OVEN. at the same type size. "Allergen information available on request." Barlow 5 mm type (x-height 2.5 mm), then @doughboss.'),
    ])


# ================================================================ PAGE 8: window decal
def p_decal(c):
    W, H = 297, 210
    page(c, W, H, 'WINDOW DECAL', 'DB_DECAL_ART', 'shopfront 1:21, stack 1:7.4',
         'Cast cut vinyl, second surface (inside the glass), reverse-cut. Installed by the sign installer')
    # ---- shopfront elevation (schematic panel; real glazing size to be measured on site)
    S = 0.048
    v = V(c, 16, 72, S)
    with v:
        pw, ph, pb = 1700, 2200, 250               # illustrative glazing panel
        v.rect(-120, 0, pw + 240 + 900, 40, fill=HexColor('#cfcac0'), stroke=None)
        v.rect(0, pb, pw, ph, fill=HexColor('#e7f0f4'), stroke=GARMENT_LINE, lw=0.5)
        v.rect(pw + 120, pb, 900, ph, fill=HexColor('#dfe9ee'), stroke=GARMENT_LINE, lw=0.4)      # door, indicative
        v.txt(pw + 570, pb + ph / 2, 'door', 2.4, 'Barlow', MUTE)
        cx, cy = pw / 2, 1500
        wm = wm_dims(600)
        v.rect(cx - 300, cy - wm['h'] / 2, 600, wm['h'], fill=CHAR, stroke=None)
        place_wordmark(c, cx - 300, cy - wm['h'] / 2, 600, 'vinyl')
        # the rest of the stack, schematic at this scale (see detail)
        capF = 0.7 * (300 / (tw('FRESH FROM THE OVEN', 'Bebas', 1.0, TRACK_HEAD) + TRACK_HEAD + pdfmetrics.stringWidth('.', 'Bebas', 1.0)))
        yb1 = cy - wm['h'] / 2 - wm['cap'] - capF
        v.rect(cx - 150, yb1, 300, capF, fill=CHAR, stroke=None)
        v.rect(cx - 90, yb1 - 30 - 15, 180, 15, fill=CHAR, stroke=None)
        v.guide(-60, cy, pw + 260, cy)
        v.dim((pw + 250, 0), (pw + 250, cy), '1500 to wordmark centre', 4.0)
        v.dim((cx - 300, cy + wm['h'] / 2 + 130), (cx + 300, cy + wm['h'] / 2 + 130), '600', 0.0, ext=False)
        v.dim((0, cy + 300), (cx - 300, cy + 300), '550', 0.0, ext=False)
        v.dim((cx + 300, cy + 300), (pw, cy + 300), '550', 0.0, ext=False)
        v.txt(-120, -110, 'SHOPFRONT ELEVATION, seen from the street. Glazing size is illustrative [CONFIRM on site]', 2.7, 'BarlowC', INK, 'l')
    # ---- stack detail
    S2 = 0.135
    ox, oy = 184, 44
    v2 = V(c, ox, oy, S2)
    with v2:
        wm = wm_dims(600)
        capF = 0.7 * (300 / (tw('FRESH FROM THE OVEN', 'Bebas', 1.0, TRACK_HEAD) + TRACK_HEAD + pdfmetrics.stringWidth('.', 'Bebas', 1.0)))
        y0 = 400.0
        gap1 = wm['cap']
        yw = y0 + 15 + 20 + 15 + 30 + capF + gap1                  # bottom of wordmark box
        v2.rect(-90, y0 - 40, 810, wm['h'] + (yw - y0) + 80, fill=HexColor('#e7f0f4'), stroke=GUIDE, lw=0.25, dash=(1.2, 1.0))
        v2.rect(0, yw, 600, wm['h'], fill=CHAR, stroke=None)
        place_wordmark(c, 0, yw, 600, 'vinyl')
        yb1 = yw - gap1 - capF
        v2.rect(150 - 8, yb1 - 8, 316, capF + 16, fill=CHAR, stroke=None)
        headline(c, 300, yb1, 'FRESH FROM THE OVEN', width=300, anchor='c')
        s3 = 15 / 0.7
        yb2 = yb1 - 30 - 15
        v2.rect(300 - 95, yb2 - 6, 190, 15 + 12 + 4, fill=CHAR, stroke=None)
        barlow(c, 300, yb2, 'doughboss.com.au', s3, 'BarlowSB', CREAM, 'c')
        yb3 = yb2 - 20 - 15
        v2.rect(300 - 50, yb3 - 6, 100, 15 + 12, fill=CHAR, stroke=None)
        barlow(c, 300, yb3, 'REVESBY', s3, 'BarlowC', CREAM, 'c', 0.14)
        x0 = 640
        v2.dim((x0, yw), (x0, yw + wm['h']), f"{wm['h']:.1f}", 4.0)
        v2.dim((x0, yb1 + capF), (x0, yw), f'{gap1:.0f}', 4.0)
        v2.dim((x0, yb1), (x0, yb1 + capF), f'{capF:.1f}', 4.0)
        v2.dim((-20, yb2 + 15), (-20, yb1), '30', 4.0)
        v2.dim((-20, yb3 + 15), (-20, yb2), '20', 4.0)
        v2.dim((150, yb1 + capF + 30), (450, yb1 + capF + 30), '300', 0.0, ext=False)
        v2.dim((0, yw + wm['h'] + 40), (600, yw + wm['h'] + 40), '600', 0.0, ext=False)
        v2.txt(300, yw + wm['h'] + 160, 'STACK DETAIL, as seen from the street', 3.0, 'BarlowC', INK)
    notes(c, 10, 52, 130, [
        ('Placement', 'Centre of the wordmark about 1500 mm above finished floor (adjust to sightlines on site), centred on the main panel beside the door, '
                      'at least 100 mm from frames and mullions.'),
        ('Approvals', 'Roselands Centro: centre management approval before install. Strip shops: landlord consent and council signage controls [CONFIRM].'),
    ])
    notes(c, 186, 28, 100, [
        ('Stack', 'Wordmark 600 x 178.6 mm; 67 mm clear; FRESH FROM THE OVEN. 300 mm wide (cap 29.3); 30 mm; doughboss.com.au 15 mm cap height; 20 mm; optional shop line 15 mm cap height.'),
    ])


# ================================================================ PAGE 9: seal label
def p_seal(c):
    W, H = 297, 210
    page(c, W, H, 'SEAL LABEL', 'DB_SEAL_ART', 'label 1:1 and 1:2', '70 mm round, natural kraft, char and ember, permanent adhesive')
    cx, cy = 96, 104
    # kraft sample surround for context
    art.seal_final(c, cx, cy)
    v = V(c, cx, cy, 1.0)
    with v:
        v.dim((-35, 0), (35, 0), '70 mm', 0.0, ext=False)
        v.guide(-48, 4, 48, 4)
        v.guide(-48, -4, 48, -4)
        v.dim((44, -4), (44, 4), '8 mm clear band', 0.0, ext=False)
        v.label(60, 0, 'bend line', 2.3)
        v.dim((-35, 40), (-35 + 2.4, 40), '', 0.0, ext=False) if False else None
        v.dim((-36, 14.5), (-36, 0), '14.5', 0.0, ext=False)
        v.guide(-36, 14.5, -21, 14.5)
        v.dim((-36, 0), (-36, -22), '22', 0.0, ext=False)
        v.guide(-36, -22, -15, -22)
        v.dim((-21, 24), (21, 24), '42', 0.0, ext=False)
        v.guide(-21, 14, -21, 25)
        v.guide(21, 14, 21, 25)
    T(c, cx, cy + 46, 'SEAL, as printed (1:1)', 3.2, 'BarlowC', INK, 'c')
    notes(c, 160, 170, 125, [
        ('Size', '70 mm round die cut, natural uncoated kraft face, permanent adhesive suitable for corrugated.'),
        ('Ink', 'One colour char for text, rules and tick boxes; ember ring 1.2 mm wide, centreline 2.4 mm inside the edge. No wordmark on the seal.'),
        ('Fields', 'FOR, DATE, tick list CHEESE, ZA\'ATAR, MEAT, SPINACH, then BOX __ OF __. Barlow Condensed 600, 4.0 to 4.2 mm type, tracking 0.10 to 0.12 em. Rules 0.3 mm.'),
        ('Bend', 'The label crosses the lid edge: an 8 mm band across the diameter carries no print. Seal placed across the lid front and the front wall at x = 322 mm +/- 11 mm.'),
        ('Rows', 'Distances from the centre: FOR baseline +14.5, DATE +6.0, tick rows -8.5 and -15, BOX/OF -22 mm. Text block 42 mm wide, left edge 21 mm left of centre.'),
    ], head='SPECIFICATION')


# ================================================================ PAGE 10: liner
def p_liner(c):
    W, H = 297, 210
    page(c, W, H, 'GREASEPROOF LINER', 'DB_LINER_ART', 'sheet 1:2, detail 1:1.6',
         'Greaseproof 40 to 50 gsm, 1 colour ember (PANTONE 485 C), printed on the non-food face')
    S = 0.5
    ox, oy = 12, 40
    c.saveState()
    c.translate(ox, oy)
    c.scale(S, S)
    p = c.beginPath()
    p.rect(0, 0, 380, 285)
    c.clipPath(p, stroke=0, fill=0)
    art.liner(c, 380, 285)
    c.restoreState()
    c.setStrokeColor(INK)
    c.setLineWidth(0.3)
    c.rect(ox, oy, 380 * S, 285 * S, stroke=1, fill=0)
    v = V(c, ox, oy, S)
    with v:
        v.dim((0, 0), (380, 0), '380 mm', -6.0)
        v.dim((380, 0), (380, 285), '285 mm', -6.0)
    T(c, ox, oy + 285 * S + 3, 'WHOLE SHEET (working size, fit sample to confirm)', 3.0, 'BarlowC', INK)
    # repeat detail: window 120 x 120 mm from the lower-left of the sheet
    S2 = 0.62
    dx, dy = 215, 100
    c.saveState()
    c.translate(dx, dy)
    c.scale(S2, S2)
    p = c.beginPath()
    p.rect(0, 0, 120, 120)
    c.clipPath(p, stroke=0, fill=0)
    art.liner(c, 380, 285)
    c.restoreState()
    c.setStrokeColor(INK)
    c.setLineWidth(0.3)
    c.rect(dx, dy, 120 * S2, 120 * S2, stroke=1, fill=0)
    v2 = V(c, dx, dy, S2)
    with v2:
        v2.dim((0, 60), (60, 60), '', 0.0, ext=False) if False else None
        v2.dim((60, 0), (120, 0), '60 pitch', -4.0)
        v2.dim((0, 60), (0, 120), '60 pitch', -4.0)
    T(c, dx, dy + 120 * S2 + 3, 'REPEAT, lower-left corner (1:1.6)', 3.0, 'BarlowC', INK)
    notes(c, dx, dy - 12, 72, [
        ('Type', '"DOUGH BOSS." Bebas Neue 8 mm, tracking 0.13 em, no box, ember. Pattern text, not the boxed logo.'),
        ('Grid', '60 mm pitch; alternate rows shifted 30 mm; alternate cells turned 35 and 215 degrees so it reads from any side.'),
    ])


PAGES = [
    ('staff-tshirt', (297, 210), p_tee),
    ('bib-apron', (297, 210), p_apron),
    ('cap', (297, 210), p_cap),
    ('tote-bag', (297, 210), p_tote),
    ('takeaway-bag', (297, 210), p_bag),
    ('sticker-sheet', (210, 297), p_sticker),
    ('tent-card', (297, 210), p_tent),
    ('window-decal', (297, 210), p_decal),
    ('seal-label', (297, 210), p_seal),
    ('liner', (297, 210), p_liner),
]


def render_all():
    from reportlab.lib.units import mm
    tmp = os.path.join(OUT, '_tmp')
    os.makedirs(tmp, exist_ok=True)
    combined = canvas.Canvas(os.path.join(OUT, 'merch-diagrams.pdf'), pagesize=(297 * mm, 210 * mm))
    combined.setTitle('Dough Boss merchandise placement diagrams')
    combined.setAuthor('Dough Boss')
    for slug, (w, h), fn in PAGES:
        # single page pdf -> png
        one = os.path.join(tmp, slug + '.pdf')
        c1 = canvas.Canvas(one, pagesize=(w * mm, h * mm))
        c1.scale(mm, mm)
        fn(c1)
        c1.showPage()
        c1.save()
        subprocess.run(['pdftoppm', '-r', '200', '-png', '-singlefile', one, os.path.join(OUT, slug)], check=True)
        # combined
        combined.setPageSize((w * mm, h * mm))
        combined.saveState()
        combined.scale(mm, mm)
        fn(combined)
        combined.restoreState()
        combined.showPage()
        os.remove(one)
    combined.save()
    os.rmdir(tmp)
    print('wrote', len(PAGES), 'diagrams to', OUT)


if __name__ == '__main__':
    render_all()
