"""Print-ready dieline PDF for the Dough Boss dozen box.
Page 1: OUTSIDE print (art + dieline overlay, 1:1 mm). Page 2: dieline only with dimensions.
Page 3: INSIDE print (optional 1-colour on natural kraft), mirrored as seen from the inside face.
Dieline drawn in a separation named 'Dieline' (100 % magenta spot, overprint) so the printer can drop it."""
import os, sys
from reportlab.pdfgen import canvas
from reportlab.lib.units import mm
from reportlab.lib.colors import CMYKColorSep, Color, black
import dieline, art

D = os.path.dirname(os.path.abspath(__file__))
s = dieline.Spec(); d = dieline.build(s)
x0, y0, x1, y1 = d.bbox
M = 25                                        # sheet margin outside the bleed (mm)
PW, PH = (x1 - x0) + 2 * (s.bleed + M), (y1 - y0) + 2 * (s.bleed + M) + 40
DIE = CMYKColorSep(0, 1, 0, 0, spotName='Dieline', density=1)
CREASE = CMYKColorSep(0, 1, 0, 0, spotName='Dieline', density=1)

def to_sheet(c):
    c.translate(-x0 + s.bleed + M, -y0 + s.bleed + M + 40)

def outline_path(c, poly, closed=True):
    p = c.beginPath(); p.moveTo(*poly[0])
    for q in poly[1:]: p.lineTo(*q)
    if closed: p.close()
    return p

def draw_die(c, dims=False):
    c.setStrokeColor(DIE); c.setLineWidth(0.25); c.setDash()
    for poly in d.cuts:
        closed = poly is d.cuts[0]
        c.drawPath(outline_path(c, poly, closed), stroke=1, fill=0)
    for h in d.holes:
        kind, cx, cy, w, hh = h
        if kind == 'oval': c.ellipse(cx - w / 2, cy - hh / 2, cx + w / 2, cy + hh / 2, stroke=1, fill=0)
        else: c.rect(cx, cy, w, hh, stroke=1, fill=0)
    c.setStrokeColor(CREASE); c.setDash(3, 2)
    for a, b in d.creases: c.line(a[0], a[1], b[0], b[1])
    c.setDash()
    if dims:
        c.setStrokeColor(black); c.setFillColor(black); c.setLineWidth(0.2)
        c.setFont('BarlowSB', 4)
        for (p1, p2, lab) in d.dims:
            c.line(*p1, *p2)
            c.drawCentredString((p1[0] + p2[0]) / 2 + 2, (p1[1] + p2[1]) / 2 + 2, lab + ' mm')
        for name, (x, y, w, h, r) in d.panels.items():
            c.setFont('Barlow', 3.5); c.setFillColor(Color(.3, .3, .8))
            c.drawString(x + 2, y + h - 5, name.replace('_', ' '))

def panel_art(c, name, fn):
    x, y, w, h, r = d.panels[name]
    c.saveState()
    # clip to the panel plus bleed so art may run over the outer cut edge
    p = c.beginPath(); p.rect(x - s.bleed, y - s.bleed, w + 2 * s.bleed, h + 2 * s.bleed); c.clipPath(p, stroke=0, fill=0)
    c.translate(x + w / 2, y + h / 2); c.rotate(r)
    rw, rh = (w, h) if r in (0, 180, -180) else (h, w)
    c.translate(-rw / 2, -rh / 2)
    fn(c, rw, rh)
    c.restoreState()

def flood(c, col):
    """Background flood of the whole blank incl. bleed (black kraft is the board itself: no ink)."""
    c.saveState(); c.setFillColor(col)
    poly = d.cuts[0]
    c.drawPath(outline_path(c, poly), stroke=0, fill=1)
    c.restoreState()

def title_block(c, title, notes):
    c.saveState(); c.setFillColor(black)
    c.setFont('Bebas', 9); c.drawString(M, 18, title)
    c.setFont('Barlow', 3.6)
    yy = 12
    for n in notes:
        c.drawString(M, yy, n); yy -= 4.5
    c.restoreState()

def build(out, outside, inside=None, notes=()):
    c = canvas.Canvas(out, pagesize=(PW * mm, PH * mm))
    c.setTitle('Dough Boss catering box (dozen): dieline and artwork'); c.setAuthor('Dough Boss')
    c.scale(mm, mm)
    # page 1: outside
    c.saveState(); to_sheet(c)
    flood(c, art.BLACK_KRAFT)
    for name, fn in outside.items(): panel_art(c, name, fn)
    draw_die(c)
    c.restoreState()
    title_block(c, 'DOUGH BOSS  ·  DOZEN CATERING BOX  ·  OUTSIDE PRINT  ·  1:1', notes)
    c.showPage(); c.scale(mm, mm)
    # page 2: dieline + dimensions
    c.saveState(); to_sheet(c); draw_die(c, dims=True); c.restoreState()
    title_block(c, 'DOUGH BOSS  ·  DOZEN CATERING BOX  ·  DIELINE  ·  1:1', notes)
    c.showPage(); c.scale(mm, mm)
    if inside:
        # page 3: inside face as seen from the inside: the sheet mirrored left-right; art drawn un-mirrored.
        c.saveState(); to_sheet(c)
        cx_m = (x0 + x1) / 2
        c.saveState(); c.translate(2 * cx_m, 0); c.scale(-1, 1)
        flood(c, art.KRAFT); draw_die(c)
        c.restoreState()
        for name, fn in inside.items():
            x, y, w, h, r = d.panels[name]
            mx = 2 * cx_m - (x + w)                      # mirrored panel origin
            c.saveState(); c.translate(mx, y); fn(c, w, h); c.restoreState()
        c.restoreState()
        title_block(c, 'DOUGH BOSS  ·  DOZEN CATERING BOX  ·  INSIDE PRINT (OPTIONAL, 1 COLOUR)  ·  viewed from inside', notes)
        c.showPage()
    c.save()
