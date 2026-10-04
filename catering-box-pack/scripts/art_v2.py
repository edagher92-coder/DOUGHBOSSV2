"""Dough Boss dozen box artwork, v2: the SAME artwork as art.py (copy, positions, sizes, ember-only full stop, hinge bands)
re-expressed in SPOT colours only, so it separates into plates. Units: mm. Panels are drawn in a local frame: origin
bottom-left of the panel as the reader sees it.

Plates:  WHITE OPAQUE  = white artwork + the white underlay under every ember element (choked 0.3 mm)
         EMBER PMS 485 C = ember full stops and hinge bands (overprinting their own underlay)
         INSIDE DARK = optional inside-lid print (placeholder spot, colour undecided [CONFIRM])

`ACTIVE` is a plate filter: None draws every plate; a spot draws only that plate (used for the separation proof).

Changes from art.py (colour model and plates only; copy and positions are unchanged):
  1. RGB -> spot colours.
  2. Underlay choke is now exactly 0.3 mm on every edge. art.py approximated the full-stop choke by shrinking the glyph by
     ~0.19 mm per edge (Bebas period is 0.106 em square); here the glyph box is inset by exactly CHOKE.
  3. The wordmark full stop (lid, front, sides) gets the same choked white underlay. art.py painted it in ember directly on
     the black board, against the rule "ember always over a white underlay".
  4. Back-wall hinge band: art.py choked the underlay on the HINGE edge and left the free edge flush (inverted versus the
     lid band). It now matches the lid band: choked on the free edge and both ends, flush at the hinge crease.
"""
import os
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.lib.colors import Color
from spots import WHITE, EMBER, INSIDE, setf, sets

D = os.path.dirname(os.path.abspath(__file__))
for name, f in [('Bebas', 'BebasNeue'), ('Barlow', 'Barlow-500'), ('BarlowSB', 'Barlow-600'),
                ('BarlowB', 'Barlow-700'), ('BarlowC', 'BarlowCondensed-600'), ('Marker', 'PermanentMarker')]:
    try:
        pdfmetrics.getFont(name)
    except Exception:
        pdfmetrics.registerFont(TTFont(name, f'{D}/fonts/{f}.ttf'))

CHOKE = 0.3
ACTIVE = None            # plate filter (see module docstring)

# Bebas Neue '.' glyph box in font units (from the font file via fontTools): x 41..147, y 0..106, UPM 1000
_DOT = (41, 0, 147, 106)
_UPM = 1000.0


def set_active(spot):
    global ACTIVE
    ACTIVE = spot


def on(col):
    return ACTIVE is None or ACTIVE.spotName == col.spotName


def tracked(c, x, y, text, font, size, track_em, fill, anchor='l'):
    """Text with CSS-style letter-spacing. Returns width. Draws only if `fill`'s plate is active."""
    sp = track_em * size
    w = sum(pdfmetrics.stringWidth(ch, font, size) for ch in text) + sp * (len(text) - 1)
    if anchor == 'r': x -= w
    elif anchor == 'c': x -= w / 2
    if on(fill):
        setf(c, fill); c.setFont(font, size)
        for ch in text:
            c.drawString(x, y, ch); x += pdfmetrics.stringWidth(ch, font, size) + sp
    return w


def stop_glyph(c, x, y, size):
    """The full stop: white underlay choked by exactly CHOKE per edge, then ember at full size on top. (x, y) = glyph origin."""
    if on(WHITE):
        gx0, gy0, gx1, gy1 = [v * size / _UPM for v in _DOT]
        gw, gh = gx1 - gx0, gy1 - gy0
        sx, sy = (gw - 2 * CHOKE) / gw, (gh - 2 * CHOKE) / gh
        cx, cy = x + (gx0 + gx1) / 2, y + (gy0 + gy1) / 2
        c.saveState()
        setf(c, WHITE); c.setFont('Bebas', size)
        c.translate(cx, cy); c.scale(sx, sy); c.translate(-cx, -cy)
        c.drawString(x, y, '.')
        c.restoreState()
    if on(EMBER):
        setf(c, EMBER); c.setFont('Bebas', size); c.drawString(x, y, '.')


def band(c, x, y, w, h, free_bottom=True):
    """Ember band with a white underlay choked CHOKE on the free edge (bottom) and both ends; flush at the hinge crease (top)."""
    if on(WHITE):
        setf(c, WHITE); c.rect(x + CHOKE, y + CHOKE, w - 2 * CHOKE, h - CHOKE, stroke=0, fill=1)
    if on(EMBER):
        setf(c, EMBER); c.rect(x, y, w, h, stroke=0, fill=1)


def wordmark(c, x, y, size, ink=WHITE, dot=EMBER, box=True, anchor='l'):
    """The site wordmark: 'DOUGH BOSS' + '.', Bebas, tracking .13em, outlined box.
    Site CSS at 22 px: padding 8 8 7 11, border 2. (x,y) = bottom-left of the box. size = font size (mm)."""
    k = size / 22.0
    pad_t, pad_r, pad_b, pad_l, bw = 8 * k, 8 * k, 7 * k, 11 * k, 2 * k
    sp = 0.13 * size
    tw = sum(pdfmetrics.stringWidth(ch, 'Bebas', size) for ch in 'DOUGH BOSS') + sp * 9
    tw += sp + pdfmetrics.stringWidth('.', 'Bebas', size)
    W = pad_l + tw + pad_r + 2 * bw; H = pad_t + size + pad_b + 2 * bw
    if anchor == 'r': x -= W
    elif anchor == 'c': x -= W / 2
    cap = 0.70 * size
    base = y + bw + pad_b + (size - cap) / 2
    if box and on(ink):
        sets(c, ink); c.setLineWidth(bw)
        c.rect(x + bw / 2, y + bw / 2, W - bw, H - bw, stroke=1, fill=0)
    xx = x + bw + pad_l
    xx += tracked(c, xx, base, 'DOUGH BOSS', 'Bebas', size, 0.13, ink) + sp
    if dot.spotName == EMBER.spotName:
        stop_glyph(c, xx, base, size)
    elif on(dot):
        setf(c, dot); c.setFont('Bebas', size); c.drawString(xx, base, '.')
    return W, H


# =====================  FINAL: "THE TABLE BOX" (packaging director spec, 3 Oct 2026)  =====================
def lid_final(c, w, h):
    k = h / 294.0
    size, tr, x = 92, 0.01, 16
    for ln, by in [('FEED', 207), ('THE WHOLE', 134), ('TABLE', 60)]:
        wdt = tracked(c, x, by * k, ln, 'Bebas', size, tr, WHITE)
        if ln == 'TABLE':
            stop_glyph(c, x + wdt + tr * size, by * k, size)
    wordmark(c, 373, 60 * k, 13, anchor='r')
    # hinge band (lid half): top 5 mm of the lid, 12 mm short of each end
    band(c, 12, h - 5, w - 24, 5)


def front_final(c, w, h):
    wordmark(c, 14, (h - 33.6) / 2, 18)
    tracked(c, 195, h / 2 - 1.9, 'REVESBY  ·  BANKSTOWN  ·  ROSELANDS', 'BarlowC', 5.5, 0.14, WHITE, anchor='c')


def side_final(c, w, h):
    # panel read with its long side horizontal: w = 290, h = 50
    wordmark(c, w / 2, (h - 26.3) / 2, 14, anchor='c')


def back_final(c, w, h):
    tracked(c, 14, 24, 'A CONTEMPORARY LEBANESE BAKERY.', 'Bebas', 12, 0.04, WHITE)
    if on(WHITE):
        setf(c, WHITE); c.setFont('Barlow', 4.5); c.drawString(14, 12, 'Allergen information available on request.')
    tracked(c, 375, 27, 'CATERING@DOUGHBOSS.COM.AU     DOUGHBOSS.COM.AU     @DOUGHBOSS', 'BarlowC', 5, 0.12, WHITE, anchor='r')
    tracked(c, 375, 18, 'THREE SHOPS BAKING DAILY  ·  REVESBY  ·  BANKSTOWN  ·  ROSELANDS', 'BarlowC', 5, 0.12, WHITE, anchor='r')
    band(c, 12, h - 5, w - 24, 5)


def inside_lid(c, w, h):
    """Natural kraft, optional 1 colour dark (low-migration). Reads upright with the lid open, hinge at bottom."""
    ink = INSIDE
    tracked(c, 16, h - 16 - 0.7 * 46, 'FRESH FROM', 'Bebas', 46, 0.01, ink)
    tracked(c, 16, h - 16 - 0.7 * 46 - 40, 'THE OVEN.', 'Bebas', 46, 0.01, ink)
    tracked(c, 16, h - 16 - 0.7 * 46 - 58, 'OVEN-BAKED  ·  BAKED TO ORDER', 'BarlowC', 6, 0.14, ink)
    if on(ink):
        setf(c, ink); c.setFont('Barlow', 4.5); c.drawString(16, 14, 'Allergen information available on request.')
    wordmark(c, w - 16, 14, 11, ink=ink, dot=ink, anchor='r')   # mono: 1-colour inside pass
