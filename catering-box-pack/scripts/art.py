"""Dough Boss catering box artwork (vector, reportlab). Units: mm.
Panels are drawn in a local frame: origin bottom-left of the panel as the reader sees it."""
import os
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.lib.units import mm
from reportlab.lib.colors import Color, CMYKColor

D = os.path.dirname(os.path.abspath(__file__))
for name, f in [('Bebas', 'BebasNeue'), ('Barlow', 'Barlow-500'), ('BarlowSB', 'Barlow-600'),
                ('BarlowB', 'Barlow-700'), ('BarlowC', 'BarlowCondensed-600'), ('Marker', 'PermanentMarker')]:
    pdfmetrics.registerFont(TTFont(name, f'{D}/fonts/{f}.ttf'))

# Brand tokens (site CSS). Print: spot WHITE (opaque) and spot EMBER on black kraft.
BLACK_KRAFT = Color(0.075, 0.068, 0.064)     # flat preview of black kraft
KRAFT = Color(0.72, 0.56, 0.39)              # natural kraft inside
INK_WHITE = Color(0.93, 0.91, 0.87)          # opaque white on black kraft reads warm (cream #eee8de)
EMBER = Color(0xe2 / 255, 0x23 / 255, 0x1a / 255)
CHAR = Color(0x0a / 255, 0x08 / 255, 0x07 / 255)

def tracked(c, x, y, text, font, size, track_em, fill, anchor='l'):
    """Draw text with CSS-style letter-spacing (track_em * size between glyphs). Returns width."""
    sp = track_em * size
    w = sum(pdfmetrics.stringWidth(ch, font, size) for ch in text) + sp * (len(text) - 1)
    if anchor == 'r': x -= w
    elif anchor == 'c': x -= w / 2
    c.setFillColor(fill); c.setFont(font, size)
    for ch in text:
        c.drawString(x, y, ch); x += pdfmetrics.stringWidth(ch, font, size) + sp
    return w

def wordmark(c, x, y, size, ink=INK_WHITE, dot=EMBER, box=True, anchor='l'):
    """The site wordmark: 'DOUGH BOSS' + ember '.', Bebas, tracking .13em, outlined box.
    Site CSS at 22 px: padding 8 8 7 11, border 2. (x,y) = bottom-left of the box. size = font size (mm)."""
    k = size / 22.0
    pad_t, pad_r, pad_b, pad_l, bw = 8 * k, 8 * k, 7 * k, 11 * k, 2 * k
    sp = 0.13 * size
    tw = sum(pdfmetrics.stringWidth(ch, 'Bebas', size) for ch in 'DOUGH BOSS') + sp * 9
    tw += sp + pdfmetrics.stringWidth('.', 'Bebas', size)
    W = pad_l + tw + pad_r + 2 * bw; H = pad_t + size + pad_b + 2 * bw
    if anchor == 'r': x -= W
    elif anchor == 'c': x -= W / 2
    cap = 0.70 * size                                   # Bebas cap height ~0.7 em
    base = y + bw + pad_b + (size - cap) / 2
    if box:
        c.setStrokeColor(ink); c.setLineWidth(bw)
        c.rect(x + bw / 2, y + bw / 2, W - bw, H - bw, stroke=1, fill=0)
    xx = x + bw + pad_l
    xx += tracked(c, xx, base, 'DOUGH BOSS', 'Bebas', size, 0.13, ink) + sp
    c.setFillColor(dot); c.setFont('Bebas', size); c.drawString(xx, base, '.')
    return W, H

def fill(c, w, h, col):
    c.setFillColor(col); c.rect(0, 0, w, h, stroke=0, fill=1)

# ---------------- concepts (lid top: w x h, reader orientation; front wall: w x h)
def lid_A(c, w, h):
    """A. EXPRESSIVE: the site's catering line set huge, left-aligned, ember full stop."""
    fill(c, w, h, BLACK_KRAFT)
    m = 18
    size = 74
    lines = ['FEED', 'THE WHOLE', 'TABLE']
    y = h - m - 0.70 * size
    for i, ln in enumerate(lines):
        wdt = tracked(c, m - 1.5, y, ln, 'Bebas', size, 0.02, INK_WHITE)
        if i == 2:
            c.setFillColor(EMBER); c.setFont('Bebas', size); c.drawString(m - 1.5 + wdt + 0.02 * size, y, '.')
        y -= 0.78 * size
    wordmark(c, w - m, m, 9, anchor='r')

def lid_B(c, w, h):
    """B. SIGNATURE + TICKET: wordmark off-centre upper left, printed order ticket lower right."""
    fill(c, w, h, BLACK_KRAFT)
    m = 22
    wordmark(c, m, h - m - 30, 20)
    # printed ticket (white ink rules) for handwritten order details
    tx, ty, tw, th = w - m - 150, m, 150, 92
    c.setStrokeColor(INK_WHITE); c.setLineWidth(0.6)
    c.rect(tx, ty, tw, th, stroke=1, fill=0)
    rows = ['FOR', 'DATE', 'PIECES', 'ALLERGENS']
    for i, r in enumerate(rows):
        yy = ty + th - 20 - i * 20
        tracked(c, tx + 6, yy + 2, r, 'BarlowC', 7.5, 0.12, INK_WHITE)
        c.line(tx + 38, yy, tx + tw - 6, yy)
    tracked(c, m, m + 4, 'FRESH FROM THE OVEN.', 'Bebas', 13, 0.06, INK_WHITE)

def lid_C(c, w, h):
    """C. CROPPED MARK: oversized wordmark letters bleeding off the lid, quiet ember dot."""
    fill(c, w, h, BLACK_KRAFT)
    size = 205
    c.saveState()
    p = c.beginPath(); p.rect(0, 0, w, h); c.clipPath(p, stroke=0, fill=0)
    tracked(c, -12, h - 0.70 * size + 30, 'DOUGH', 'Bebas', size, 0.0, INK_WHITE)
    c.restoreState()
    c.setFillColor(EMBER); c.circle(w - 40, 40, 11, stroke=0, fill=1)
    tracked(c, 22, 22, 'BOSS', 'Bebas', 26, 0.13, INK_WHITE)

def front_common(c, w, h, line='FEED THE WHOLE TABLE.'):
    fill(c, w, h, BLACK_KRAFT)
    m = 10
    wordmark(c, m, (h - 14) / 2, 9)
    tracked(c, w - m, h / 2 - 2.5, 'REVESBY  ·  BANKSTOWN  ·  ROSELANDS        DOUGHBOSS.COM.AU', 'BarlowC', 6.2, 0.14, INK_WHITE, anchor='r')

if __name__ == '__main__':
    from reportlab.pdfgen import canvas
    L, Wd, H = 389, 294, 50          # outer lid top / front wall approx (mm)
    for name, fn in [('A', lid_A), ('B', lid_B), ('C', lid_C)]:
        c = canvas.Canvas(f'{D}/concepts/concept-{name}.pdf', pagesize=(L * mm, (Wd + H + 8) * mm))
        c.scale(mm, mm)
        c.saveState(); c.translate(0, H + 8); fn(c, L, Wd); c.restoreState()
        front_common(c, L, H)
        c.showPage(); c.save()
    print('ok')

# ---------------- seal label: 70 mm round, uncoated natural kraft paper, 1 colour (char) + ember
SEAL_D = 70.0
def seal(c, cx, cy, d=SEAL_D, kraft=Color(0.78, 0.63, 0.45), ink=CHAR):
    r = d / 2
    c.setFillColor(kraft); c.circle(cx, cy, r, stroke=0, fill=1)
    c.setStrokeColor(EMBER); c.setLineWidth(1.4); c.circle(cx, cy, r - 2.6, stroke=1, fill=0)
    wordmark(c, cx, cy + 12, 6.2, ink=ink, dot=EMBER, anchor='c')
    c.setStrokeColor(ink); c.setLineWidth(0.35)
    rows = [('FOR', 4.5), ('DATE', -4.0), ('ALLERGENS', -12.5)]
    for lab, dy in rows:
        tracked(c, cx - 24, cy + dy, lab, 'BarlowC', 3.4, 0.12, ink)
        lw = sum(pdfmetrics.stringWidth(ch, 'BarlowC', 3.4) for ch in lab) + 0.12 * 3.4 * (len(lab) - 1)
        c.line(cx - 24 + lw + 1.5, cy + dy - 0.6, cx + 24, cy + dy - 0.6)
    tracked(c, cx, cy - 22, 'FRESH FROM THE OVEN.', 'BarlowC', 3.0, 0.14, ink, anchor='c')

# =====================  FINAL: "THE TABLE BOX" (packaging director spec, 3 Oct 2026)  =====================
# Two spots on black kraft: WHITE OPAQUE (single hit) and EMBER (always over a white underlay choked 0.3 mm).
# Board is the background: no flood. 100 % solids only. Imperfection comes from the process, not from art.
CHOKE = 0.3

def ember_over_white(c, draw):
    """Draw an element as white underlay (choked) + ember on top. `draw(c, colour, offset)`."""
    draw(c, INK_WHITE, CHOKE); draw(c, EMBER, 0)

def full_stop(c, x, y, size):
    def dr(c, col, ch):
        c.setFillColor(col); c.setFont('Bebas', size)
        # choke approximated by drawing the underlay glyph slightly smaller and centred
        if ch:
            s2 = size * (1 - 2 * ch / (0.17 * size)) if size > 10 else size
            w1 = pdfmetrics.stringWidth('.', 'Bebas', size); w2 = pdfmetrics.stringWidth('.', 'Bebas', s2)
            c.setFont('Bebas', s2); c.drawString(x + (w1 - w2) / 2, y + (size - s2) * 0.02, '.')
        else:
            c.drawString(x, y, '.')
    ember_over_white(c, dr)

def lid_final(c, w, h):
    k = h / 294.0
    size, tr, x = 92, 0.01, 16
    for ln, by in [('FEED', 207), ('THE WHOLE', 134), ('TABLE', 60)]:
        wdt = tracked(c, x, by * k, ln, 'Bebas', size, tr, INK_WHITE)
        if ln == 'TABLE':
            full_stop(c, x + wdt + tr * size, by * k, size)
    wordmark(c, 373, 60 * k, 13, anchor='r')
    # hinge band (lid half): top 5 mm of the lid, 12 mm short of each end
    def band(c, col, ch):
        c.setFillColor(col); c.rect(12 + ch, h - 5 + ch, w - 24 - 2 * ch, 5 - ch, stroke=0, fill=1)
    ember_over_white(c, band)

def front_final(c, w, h):
    wordmark(c, 14, (h - 33.6) / 2, 18)
    tracked(c, 195, h / 2 - 1.9, 'REVESBY  ·  BANKSTOWN  ·  ROSELANDS', 'BarlowC', 5.5, 0.14, INK_WHITE, anchor='c')

def side_final(c, w, h):
    # panel read with its long side horizontal: w = 290, h = 50
    wordmark(c, w / 2, (h - 26.3) / 2, 14, anchor='c')

def back_final(c, w, h):
    tracked(c, 14, 24, 'A CONTEMPORARY LEBANESE BAKERY.', 'Bebas', 12, 0.04, INK_WHITE)
    c.setFillColor(INK_WHITE); c.setFont('Barlow', 4.5); c.drawString(14, 12, 'Allergen information available on request.')
    tracked(c, 375, 27, 'CATERING@DOUGHBOSS.COM.AU     DOUGHBOSS.COM.AU     @DOUGHBOSS', 'BarlowC', 5, 0.12, INK_WHITE, anchor='r')
    tracked(c, 375, 18, 'THREE SHOPS BAKING DAILY  ·  REVESBY  ·  BANKSTOWN  ·  ROSELANDS', 'BarlowC', 5, 0.12, INK_WHITE, anchor='r')
    def band(c, col, ch):
        c.setFillColor(col); c.rect(12 + ch, h - 5, w - 24 - 2 * ch, 5 - ch, stroke=0, fill=1)
    ember_over_white(c, band)

INSIDE_INK = Color(0.10, 0.09, 0.085)
def inside_lid(c, w, h):
    """Natural kraft, optional 1 colour dense black (low-migration). Reads upright with the lid open, hinge at bottom."""
    tracked(c, 16, h - 16 - 0.7 * 46, 'FRESH FROM', 'Bebas', 46, 0.01, INSIDE_INK)
    tracked(c, 16, h - 16 - 0.7 * 46 - 40, 'THE OVEN.', 'Bebas', 46, 0.01, INSIDE_INK)
    tracked(c, 16, h - 16 - 0.7 * 46 - 58, 'OVEN-BAKED  ·  BAKED TO ORDER', 'BarlowC', 6, 0.14, INSIDE_INK)
    c.setFillColor(INSIDE_INK); c.setFont('Barlow', 4.5); c.drawString(16, 14, 'Allergen information available on request.')
    wordmark(c, w - 16, 14, 11, ink=INSIDE_INK, dot=INSIDE_INK, anchor='r')   # mono: 1-colour inside pass

LINER_PAPER = Color(0xf7 / 255, 0xf5 / 255, 0xf0 / 255)
def liner(c, w=420, h=330):
    """Greaseproof sheet, 1 colour ember, non-directional diagonal repeat of the unboxed wordmark (~6 % coverage)."""
    c.setFillColor(LINER_PAPER); c.rect(0, 0, w, h, stroke=0, fill=1)
    c.saveState()
    p = c.beginPath(); p.rect(0, 0, w, h); c.clipPath(p, stroke=0, fill=0)
    step = 60
    for row, yy in enumerate(range(-60, h + 60, step)):
        for col, xx in enumerate(range(-60, w + 60, step)):
            c.saveState(); c.translate(xx + (step / 2 if row % 2 else 0), yy)
            c.rotate(35 if (row + col) % 2 == 0 else 215)        # alternate direction: reads from any side
            tracked(c, 0, 0, 'DOUGH BOSS.', 'Bebas', 8, 0.13, EMBER, anchor='c')
            c.restoreState()
    c.restoreState()

def seal_final(c, cx, cy, d=70.0, kraft=Color(0.78, 0.63, 0.45), ink=CHAR):
    """70 mm uncoated kraft label, char + ember. Top half sits on the lid, bottom half on the front wall;
    an 8 mm clear band crosses the diameter where it bends over the lid edge."""
    r = d / 2
    c.setFillColor(kraft); c.circle(cx, cy, r, stroke=0, fill=1)
    c.setStrokeColor(EMBER); c.setLineWidth(1.2); c.circle(cx, cy, r - 2.4, stroke=1, fill=0)
    c.setStrokeColor(ink); c.setLineWidth(0.3)
    # top half: FOR and DATE; rules start 1.5 mm after each measured label
    def field(lab, x, y, x_end, size=4.2):
        w_ = tracked(c, x, y, lab, 'BarlowC', size, 0.12, ink)
        c.line(x + w_ + 1.5, y - 0.6, x_end, y - 0.6)
    field('FOR', cx - 21, cy + 14.5, cx + 21)
    field('DATE', cx - 21, cy + 6.0, cx + 9.5)
    # bottom half: tick list and box n of N
    def tick(x, y, lab):
        c.rect(x, y - 0.3, 3.0, 3.0, stroke=1, fill=0); tracked(c, x + 4.4, y, lab, 'BarlowC', 4.0, 0.10, ink)
    tick(cx - 21, cy - 8.5, 'CHEESE'); tick(cx + 3, cy - 8.5, "ZA'ATAR")
    tick(cx - 21, cy - 15, 'MEAT');  tick(cx + 3, cy - 15, 'SPINACH')
    wb = tracked(c, cx - 15, cy - 22, 'BOX', 'BarlowC', 4.2, 0.12, ink)
    c.line(cx - 15 + wb + 1.2, cy - 22.6, cx - 1.5, cy - 22.6)
    wo = tracked(c, cx + 1.5, cy - 22, 'OF', 'BarlowC', 4.2, 0.12, ink)
    c.line(cx + 1.5 + wo + 1.2, cy - 22.6, cx + 14, cy - 22.6)
    # no wordmark on the seal: the lid and wall already carry it (brand round 1)
