"""Assemble the Dough Boss Brand and Catering Pack PDF from the pack's markdown sections and images."""
import os, re, glob
from PIL import Image as PILImage
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib.colors import HexColor, Color
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.enums import TA_LEFT, TA_CENTER
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.lib.fonts import addMapping
from reportlab.platypus import (BaseDocTemplate, PageTemplate, Frame, Paragraph, Spacer, Table, TableStyle,
                                PageBreak, NextPageTemplate, Image, KeepTogether, CondPageBreak, Flowable)
from reportlab.platypus.tableofcontents import TableOfContents

D = os.path.dirname(os.path.abspath(__file__))
for n, f in [('Bebas', 'BebasNeue'), ('Barlow', 'Barlow-400'), ('Barlow-B', 'Barlow-600'), ('Barlow-L', 'Barlow-300'),
             ('BarlowC', 'BarlowCondensed-600'), ('BarlowC-R', 'BarlowCondensed-400')]:
    pdfmetrics.registerFont(TTFont(n, f'{D}/fonts/{f}.ttf'))
pdfmetrics.registerFont(TTFont('CJK', '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc', subfontIndex=0))
for b, i, fn in [(0, 0, 'Barlow'), (1, 0, 'Barlow-B'), (0, 1, 'Barlow-L'), (1, 1, 'Barlow-B')]:
    addMapping('Barlow', b, i, fn)

CHAR, COAL, CREAM, EMBER, PAPER, MIST, GOLD = (HexColor(h) for h in
    ('#0a0807', '#151210', '#eee8de', '#e2231a', '#f7f5f0', '#b8b1a4', '#f1a132'))
RULE = HexColor('#d9d3c7'); ZEBRA = HexColor('#efebe3'); INK = HexColor('#1c1815')
W, H = A4; M = 16 * mm
VERSION = 'Brand & Catering Pack v1 · 3 October 2026'
FOOT = 'CONCEPT FOR DEVELOPMENT  ·  INTERNAL, NOT FOR PUBLICATION'

st = {
    'body': ParagraphStyle('body', fontName='Barlow', fontSize=9.2, leading=13.2, textColor=INK, spaceAfter=5),
    'h1': ParagraphStyle('h1', fontName='Bebas', fontSize=34, leading=34, textColor=CHAR, spaceAfter=10),
    'h2': ParagraphStyle('h2', fontName='Bebas', fontSize=19, leading=21, textColor=CHAR, spaceBefore=12, spaceAfter=5),
    'h3': ParagraphStyle('h3', fontName='BarlowC', fontSize=11.5, leading=14, textColor=EMBER, spaceBefore=9, spaceAfter=3),
    'h4': ParagraphStyle('h4', fontName='Barlow-B', fontSize=9.5, leading=13, textColor=CHAR, spaceBefore=6, spaceAfter=2),
    'cell': ParagraphStyle('cell', fontName='Barlow', fontSize=7.4, leading=9.4, textColor=INK),
    'hcell': ParagraphStyle('hcell', fontName='BarlowC', fontSize=7.8, leading=9.4, textColor=CREAM),
    'quote': ParagraphStyle('quote', fontName='Barlow', fontSize=8.8, leading=12.6, textColor=INK, leftIndent=9,
                            borderPadding=(5, 6, 5, 8), backColor=ZEBRA, spaceBefore=3, spaceAfter=8),
    'code': ParagraphStyle('code', fontName='Courier', fontSize=7.2, leading=9.2, textColor=INK, backColor=ZEBRA,
                           borderPadding=6, leftIndent=4, spaceBefore=4, spaceAfter=8),
    'cap': ParagraphStyle('cap', fontName='BarlowC-R', fontSize=8, leading=10, textColor=MIST, spaceBefore=3, spaceAfter=8),
    'toc0': ParagraphStyle('toc0', fontName='Bebas', fontSize=15, leading=20, textColor=CHAR),
    'toc1': ParagraphStyle('toc1', fontName='Barlow', fontSize=8.6, leading=11.5, leftIndent=14, textColor=INK),
}
for k in range(1, 4):
    st[f'li{k}'] = ParagraphStyle(f'li{k}', parent=st['body'], leftIndent=10 + 11 * (k - 1), bulletIndent=2 + 11 * (k - 1),
                                  spaceAfter=2.5)

SUBS = {'Δ': 'd', '†': '*', '√': 'sqrt', '≈': '~', '≤': '<=', '≥': '>=', '″': '"'}
def esc(s):
    for k, v in SUBS.items(): s = s.replace(k, v)
    return s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')

def inline(s):
    s = esc(s)
    s = re.sub(r'(https?://[^\s<]{40,})', lambda m: m.group(1).replace('/', '/​'), s)
    codes = []
    s = re.sub(r'`([^`]+)`', lambda m: codes.append(m.group(1)) or f'\x00{len(codes)-1}\x00', s)
    s = re.sub(r'\[([^\]]+)\]\((https?://[^)\s]+)\)', r'\1 (<font color="#8a8174">\2</font>)', s)
    s = re.sub(r'\*\*(.+?)\*\*', r'<b>\1</b>', s)
    s = re.sub(r'(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])', r'<i>\1</i>', s)
    s = re.sub(r'(\[(?:CONFIRM|QUOTE|DRAFT COPY)[^\]<]*\]?)', r'<font color="#b71912">\1</font>', s)
    s = s.replace('REGULATOR-CHECK', '<font color="#b71912">REGULATOR-CHECK</font>')
    s = re.sub(r'\x00(\d+)\x00', lambda m: f'<font name="Courier" size="7.6">{codes[int(m.group(1))]}</font>', s)
    s = re.sub(r'([\u2e80-\u9fff\u3000-\u303f\uff00-\uffef]+)', r'<font name="CJK">\1</font>', s)
    return s

class Bookmark(Flowable):
    """Zero-size marker that feeds the TOC and the PDF outline."""
    def __init__(self, title, level, key):
        super().__init__(); self.title, self.level, self.key = title, level, key
    def wrap(self, *a): return (0, 0)
    def draw(self):
        self.canv.bookmarkPage(self.key)
        self.canv.addOutlineEntry(self.title, self.key, level=self.level, closed=self.level > 0)

_bk = [0]
def mark(title, level):
    _bk[0] += 1; return Bookmark(title, level, f'k{_bk[0]}')

def table(rows, width):
    ncol = max(len(r) for r in rows)
    rows = [r + [''] * (ncol - len(r)) for r in rows]
    lens = [max(min(len(r[c]), 70) for r in rows) + 4 for c in range(ncol)]
    wt = [l ** 0.75 for l in lens]; tot = sum(wt)
    cw = [max(width * x / tot, 13 * mm) for x in wt]; f = width / sum(cw); cw = [c * f for c in cw]
    data = [[Paragraph(inline(c), st['hcell'] if i == 0 else st['cell']) for c in r] for i, r in enumerate(rows)]
    t = Table(data, colWidths=cw, repeatRows=1)
    sty = [('BACKGROUND', (0, 0), (-1, 0), COAL), ('VALIGN', (0, 0), (-1, -1), 'TOP'),
           ('LINEBELOW', (0, 1), (-1, -1), 0.3, RULE), ('LEFTPADDING', (0, 0), (-1, -1), 4),
           ('RIGHTPADDING', (0, 0), (-1, -1), 4), ('TOPPADDING', (0, 0), (-1, -1), 3), ('BOTTOMPADDING', (0, 0), (-1, -1), 3)]
    for i in range(2, len(rows), 2): sty.append(('BACKGROUND', (0, i), (-1, i), ZEBRA))
    t.setStyle(TableStyle(sty)); return t

def md(path, width, skip_h1=False):
    out = []; lines = open(path, encoding='utf8').read().split('\n'); i = 0; para = []
    def flush():
        if para: out.append(Paragraph(inline(' '.join(x.strip() for x in para)), st['body'])); para.clear()
    while i < len(lines):
        ln = lines[i]; s = ln.strip()
        if s.startswith('```'):
            flush(); j = i + 1; buf = []
            while j < len(lines) and not lines[j].strip().startswith('```'): buf.append(lines[j]); j += 1
            txt = '<br/>'.join(esc(b).replace('  ', '&nbsp; ') for b in buf)
            out.append(Paragraph(txt, st['code'])); i = j + 1; continue
        if not s: flush(); i += 1; continue
        if 'page-break-after' in s: flush(); out.append(PageBreak()); i += 1; continue
        m = re.match(r'^(#{1,4})\s+(.*)', s)
        if m:
            flush(); lvl = len(m.group(1)); txt = m.group(2)
            if lvl == 1:
                if not skip_h1: out.append(Paragraph(inline(txt), st['h1']))
            else:
                if lvl == 2: out += [CondPageBreak(40 * mm), mark(re.sub(r'\*+', '', txt), 1)]
                if lvl == 3: out.append(CondPageBreak(25 * mm))
                out.append(Paragraph(inline(txt), st[f'h{lvl}']))
            i += 1; continue
        if s.startswith('|'):
            flush(); rows = []
            while i < len(lines) and lines[i].strip().startswith('|'):
                r = lines[i].strip().strip('|')
                cells = [c.strip() for c in re.split(r'(?<!\\)\|', r)]
                if not all(re.fullmatch(r':?-{2,}:?', c) for c in cells if c): rows.append(cells)
                i += 1
            out.append(table(rows, width)); out.append(Spacer(1, 6)); continue
        if re.fullmatch(r'-{3,}|\*{3,}', s): flush(); out.append(Spacer(1, 6)); i += 1; continue
        if s.startswith('>'):
            flush(); buf = []
            while i < len(lines) and lines[i].strip().startswith('>'): buf.append(lines[i].strip()[1:].strip()); i += 1
            out.append(Paragraph(inline(' '.join(buf)), st['quote'])); continue
        m = re.match(r'^(\s*)([-*+]|\d+[.)])\s+(.*)', ln)
        if m:
            flush(); ind = len(m.group(1).replace('\t', '    ')); lvl = min(3, 1 + ind // 2)
            txt = m.group(3); i += 1
            while i < len(lines) and lines[i].strip() and not re.match(r'^\s*([-*+]|\d+[.)])\s+', lines[i]) \
                    and not lines[i].strip().startswith(('|', '#', '>', '```')) and lines[i].startswith(' '):
                txt += ' ' + lines[i].strip(); i += 1
            bullet = m.group(2) if m.group(2)[0].isdigit() else ('•' if lvl == 1 else '–')
            out.append(Paragraph(inline(txt), st[f'li{lvl}'], bulletText=bullet)); continue
        para.append(ln); i += 1
    flush(); return out

def jpg(src, maxw=1800, q=84):
    dst = f'{D}/pdfimg/{os.path.basename(src).rsplit(".",1)[0]}-{maxw}.jpg'
    if not os.path.exists(dst) or os.path.getmtime(dst) < os.path.getmtime(src):
        im = PILImage.open(src)
        if im.mode in ('RGBA', 'LA', 'P'):
            im = im.convert('RGBA'); bg = PILImage.new('RGB', im.size, (247, 245, 240)); bg.paste(im, mask=im.split()[-1]); im = bg
        else: im = im.convert('RGB')
        if im.width > maxw: im = im.resize((maxw, round(im.height * maxw / im.width)), PILImage.LANCZOS)
        im.save(dst, quality=q)
    return dst

def pic(src, maxw, maxh, caption=None, px=1800, raw=False):
    p = jpg(src, px); w, h = PILImage.open(p).size; s = min(maxw / w, maxh / h)
    items = [Image(p, w * s, h * s)]
    if caption: items.append(Paragraph(esc(caption), st['cap']))
    return items if raw else KeepTogether(items)

# ---------- page furniture
def content_page(c, doc):
    c.saveState(); c.setFillColor(PAPER); c.rect(0, 0, W, H, stroke=0, fill=1)
    c.setFillColor(CHAR); c.setFont('Bebas', 11)
    c.drawString(M, H - 11 * mm, 'DOUGH BOSS'); x = M + pdfmetrics.stringWidth('DOUGH BOSS', 'Bebas', 11)
    c.setFillColor(EMBER); c.drawString(x, H - 11 * mm, '.')
    c.setFillColor(MIST); c.setFont('BarlowC-R', 7.5)
    c.drawRightString(W - M, H - 11 * mm, getattr(doc, 'section', '').upper())
    c.setStrokeColor(RULE); c.setLineWidth(0.4); c.line(M, H - 13 * mm, W - M, H - 13 * mm)
    c.line(M, 12 * mm, W - M, 12 * mm)
    c.drawString(M, 8 * mm, VERSION + '  ·  ' + FOOT)
    c.setFillColor(CHAR); c.setFont('BarlowC', 8); c.drawRightString(W - M, 8 * mm, str(doc.page))
    c.restoreState()

def dark_page(c, doc):
    c.saveState(); c.setFillColor(CHAR); c.rect(0, 0, W, H, stroke=0, fill=1)
    c.setFillColor(MIST); c.setFont('BarlowC-R', 7.5)
    c.drawString(M, 8 * mm, VERSION + '  ·  CONCEPT FOR DEVELOPMENT  ·  INTERNAL')
    c.drawRightString(W - M, 8 * mm, str(doc.page)); c.restoreState()

def wordmark(c, x, y, size, fg=CREAM, stop=EMBER):
    """Site wordmark: Bebas, tracking .13em, single rule box, padding 8/8/7/11 per 22 px, rule 2/22, ember full stop."""
    tr = .13 * size; txt = 'DOUGH BOSS'
    tw = sum(pdfmetrics.stringWidth(ch, 'Bebas', size) for ch in txt + '.') + tr * len(txt)
    pt, pr, pb, pl, rw = (v * size / 22 for v in (8, 8, 7, 11, 2))
    capH = 0.70 * size; bw = pl + tw + pr; bh = pt + capH + pb
    c.setStrokeColor(fg); c.setLineWidth(rw); c.rect(x + rw / 2, y + rw / 2, bw - rw, bh - rw, stroke=1, fill=0)
    cx = x + pl; c.setFont('Bebas', size)
    for ch in txt:
        c.setFillColor(fg); c.drawString(cx, y + pb, ch); cx += pdfmetrics.stringWidth(ch, 'Bebas', size) + tr
    c.setFillColor(stop); c.drawString(cx, y + pb, '.')
    return bw, bh

class Cover(Flowable):
    def __init__(self, img): super().__init__(); self.img = img
    def wrap(self, *a): return (0, 0)
    def draw(self):
        c = self.canv; c.saveState(); c.translate(-M, -(H - M))
        c.setFillColor(CHAR); c.rect(0, 0, W, H, stroke=0, fill=1)
        p = jpg(self.img, 2400, 86); iw, ih = PILImage.open(p).size; h = W * ih / iw
        c.drawImage(p, 0, H - h - 70 * mm, W, h)
        bw, bh = wordmark(c, M, H - 40 * mm, 30)
        c.setFillColor(CREAM); c.setFont('Bebas', 52); c.drawString(M, 78 * mm, 'BRAND & CATERING PACK')
        c.setFont('Barlow', 11); c.setFillColor(MIST)
        c.drawString(M, 68 * mm, 'The catering dozen box, the brand around it, and how to make it real.')
        c.setFillColor(EMBER); c.rect(M, 60 * mm, 22 * mm, 1.4 * mm, stroke=0, fill=1)
        c.setFillColor(CREAM); c.setFont('BarlowC', 9.5)
        for k, t in enumerate(['VERSION 1  ·  3 OCTOBER 2026', 'STATUS: CONCEPT FOR DEVELOPMENT  ·  INTERNAL, NOT FOR PUBLICATION',
                               'Imagery is concept: AI food stand-ins and composited print. See the gates in Section 1.']):
            c.setFont('BarlowC' if k < 2 else 'BarlowC-R', 9.5 if k < 2 else 8.5)
            c.setFillColor(CREAM if k < 2 else MIST); c.drawString(M, 50 * mm - k * 5.5 * mm, t)
        c.restoreState()

class Divider(Flowable):
    def __init__(self, num, title, sub, img=None):
        super().__init__(); self.num, self.title, self.sub, self.img = num, title, sub, img
    def wrap(self, *a): return (0, 0)
    def draw(self):
        c = self.canv; c.saveState(); c.translate(-M, -(H - M))
        if self.img:
            p = jpg(self.img, 2000, 84); iw, ih = PILImage.open(p).size
            s = max(W / iw, (H * 0.55) / ih); dw, dh = iw * s, ih * s
            c.saveState(); pth = c.beginPath(); pth.rect(0, H * 0.45, W, H * 0.55); c.clipPath(pth, stroke=0, fill=0)
            c.drawImage(p, (W - dw) / 2, H * 0.45 + (H * 0.55 - dh) / 2, dw, dh); c.restoreState()
        c.setFillColor(EMBER); c.setFont('Bebas', 90); c.drawString(M, H * 0.45 - 38 * mm, self.num)
        c.setFillColor(CREAM); c.setFont('Bebas', 44)
        y = H * 0.45 - 58 * mm
        for line in self.title.split('\n'): c.drawString(M, y, line); y -= 44
        c.setFillColor(MIST); c.setFont('Barlow', 10.5); c.drawString(M, y - 4, self.sub)
        c.restoreState()

class Swatches(Flowable):
    COLS = [('Char', '#0a0807'), ('Coal', '#151210'), ('Cream', '#eee8de'), ('Ember', '#e2231a'),
            ('Ember dark', '#b71912'), ('Gold', '#f1a132'), ('Paper', '#f7f5f0'), ('Mist', '#b8b1a4')]
    def __init__(self, width): super().__init__(); self.width = width
    def wrap(self, *a): return (self.width, 44 * mm)
    def draw(self):
        c = self.canv; n = 4; gw = (self.width - 3 * 4 * mm) / n
        for k, (nm, hx) in enumerate(self.COLS):
            col, row = k % n, k // n; x = col * (gw + 4 * mm); y = 23 * mm - row * 23 * mm
            c.setFillColor(HexColor(hx)); c.setStrokeColor(RULE); c.setLineWidth(0.4)
            c.rect(x, y + 6 * mm, gw, 14 * mm, stroke=1, fill=1)
            c.setFillColor(CHAR); c.setFont('BarlowC', 8.5); c.drawString(x, y + 2.2 * mm, nm.upper())
            c.setFont('Barlow', 7.5); c.setFillColor(INK); c.drawRightString(x + gw, y + 2.2 * mm, hx)

class BrandBoard(Flowable):
    def __init__(self, width): super().__init__(); self.width = width
    def wrap(self, *a): return (self.width, 92 * mm)
    def draw(self):
        c = self.canv; h = 46 * mm
        c.setFillColor(CHAR); c.rect(0, 46 * mm, self.width, h, stroke=0, fill=1)
        wordmark(c, 10 * mm, 46 * mm + 17 * mm, 34)
        c.setFillColor(MIST); c.setFont('BarlowC-R', 7.5)
        c.drawString(10 * mm, 46 * mm + 6 * mm, 'PRIMARY: CREAM ON CHAR, EMBER FULL STOP. BUILT FROM THE SITE CSS (22 PX: PADDING 8/8/7/11, RULE 2, TRACKING .13 EM).')
        kraft = HexColor('#b88f63'); c.setFillColor(kraft); c.rect(0, 0, self.width / 2 - 2 * mm, 44 * mm, stroke=0, fill=1)
        wordmark(c, 8 * mm, 16 * mm, 20, fg=CHAR, stop=CHAR)
        c.setFillColor(CHAR); c.setFont('BarlowC-R', 7); c.drawString(8 * mm, 6 * mm, 'MONO ON KRAFT (ONE-COLOUR JOBS: THE FULL STOP GOES CHAR IN PRINT)')
        x0 = self.width / 2 + 2 * mm; c.setFillColor(HexColor('#121010')); c.rect(x0, 0, self.width / 2 - 2 * mm, 44 * mm, stroke=0, fill=1)
        c.setFillColor(CREAM); c.setFont('Bebas', 26); c.drawString(x0 + 8 * mm, 24 * mm, 'FEED THE WHOLE TABLE.')
        c.setFont('BarlowC', 9); c.drawString(x0 + 8 * mm, 16 * mm, 'REVESBY · BANKSTOWN · ROSELANDS')
        c.setFont('Barlow', 8); c.setFillColor(MIST); c.drawString(x0 + 8 * mm, 6 * mm, 'Bebas Neue display · Barlow text · Barlow Condensed labels')

class Spec(Flowable):
    """Type specimen."""
    def __init__(self, width): super().__init__(); self.width = width
    def wrap(self, *a): return (self.width, 40 * mm)
    def draw(self):
        c = self.canv; c.setFillColor(CHAR)
        c.setFont('Bebas', 30); c.drawString(0, 28 * mm, 'BEBAS NEUE  ABCDEFGHIJKLMNOPQRSTUVWXYZ 0123456789')
        c.setFont('Barlow', 13); c.drawString(0, 18 * mm, 'Barlow 400  Fresh from the oven, every morning. 0123456789')
        c.setFont('Barlow-B', 13); c.drawString(0, 11 * mm, 'Barlow 600  Feed the whole table.')
        c.setFont('BarlowC', 13); c.drawString(0, 4 * mm, 'BARLOW CONDENSED 600  REVESBY · BANKSTOWN · ROSELANDS')

class Doc(BaseDocTemplate):
    def afterFlowable(self, f):
        if isinstance(f, Bookmark) and f.level <= 1:
            self.notify('TOCEntry', (f.level, f.title, self.page, f.key))
        if isinstance(f, SetSection): self.section = f.name

class SetSection(Flowable):
    def __init__(self, name): super().__init__(); self.name = name
    def wrap(self, *a): return (0, 0)
    def draw(self): pass

def build(out):
    doc = Doc(out, pagesize=A4, leftMargin=M, rightMargin=M, topMargin=18 * mm, bottomMargin=17 * mm,
              title='Dough Boss Brand and Catering Pack v1', author='Dough Boss (concept)', subject='Concept for development, internal')
    fr = Frame(M, 17 * mm, W - 2 * M, H - 35 * mm, id='f', leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
    fd = Frame(M, M, W - 2 * M, H - 2 * M, id='d', leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
    doc.addPageTemplates([PageTemplate('dark', [fd], onPage=dark_page), PageTemplate('content', [fr], onPage=content_page)])
    cw = W - 2 * M; S = []
    F = lambda p: f'{D}/{p}'
    S += [Cover(F('final/W11-catering-hero-16x9.png')), NextPageTemplate('content'), PageBreak()]
    toc = TableOfContents(); toc.levelStyles = [st['toc0'], st['toc1']]; toc.dotsMinLevel = 0
    S += [SetSection('Contents'), Paragraph('Contents', st['h1']), toc]

    def section(num, title, sub, src, img=None, extra_before=(), extra_after=()):
        nonlocal S
        S += [NextPageTemplate('dark'), PageBreak(), mark(f'{num}  {title.replace(chr(10), " ")}', 0),
              Divider(num, title, sub, img), NextPageTemplate('content'), PageBreak(),
              SetSection(f'{num} · {title.replace(chr(10), " ")}')]
        S += list(extra_before)
        for s_ in ([src] if isinstance(src, str) else src): S += md(F(s_), cw)
        S += list(extra_after)

    section('01', 'STATUS AND GATES', 'Where the pack stands, the specialist panel, and what must happen before anything goes public.',
            'sections/status.md', F('final/W1-closed-box-4x5.png'))
    section('02', 'BRAND IDENTITY', 'Wordmark, colour, typography, voice and photography direction.',
            'sections/brand-identity.md', F('final/W4-seal-macro-4x5.png'),
            extra_after=[CondPageBreak(150 * mm), Paragraph('Brand board', st['h2']), BrandBoard(cw), Spacer(1, 8),
                         Paragraph('Palette', st['h3']), Swatches(cw), Spacer(1, 6), Paragraph('Type', st['h3']), Spec(cw)])
    dl = [PageBreak(), Paragraph('Dieline and print', st['h2'])]
    for k, cap in [(1, 'Dieline v2, page 1: outside print (WHITE OPAQUE and EMBER PMS 485 C plates) with DIE CUT and DIE CREASE overlaid.'),
                   (2, 'Dieline v2, page 2: the die with dimensions, line legend and title block. Flat blank 593.8 x 781.0 mm.'),
                   (3, 'Dieline v2, page 3: inside print viewed from inside the box.')]:
        dl += [pic(F(f'out/dlv2-{k}.png'), cw, 205 * mm, cap, 2000)]
    flats = [PageBreak(), Paragraph('Artwork flats', st['h2']),
             pic(F('flats/closed-art.png'), cw, 110 * mm, 'Closed box: lid, front, sides and back as printed (white opaque and ember on black kraft).'),
             pic(F('flats/back.png'), cw, 30 * mm, 'Back wall.'),
             pic(F('flats/inside-lid.png'), cw * 0.72, 80 * mm, 'Inside lid: mono wordmark and "FRESH FROM THE OVEN." on natural kraft.'),
             pic(F('flats/liner.png'), cw * 0.72, 80 * mm, 'Greaseproof liner: repeating ember "DOUGH BOSS." [CONFIRM printed liner vs plain].'),
             pic(F('flats/seal.png'), 70 * mm, 70 * mm, 'Seal label, 70 mm round kraft: FOR, DATE, tick list, BOX __ OF __, filled in by hand.')]
    section('03', 'PACKAGING', 'The catering dozen box: structure, print, panels, dieline and artwork.',
            'sections/packaging.md', F('final/W3-open-box-4x5.png'), extra_after=dl + flats)
    section('04', 'TECHNICAL DATA', 'PDS research: structure, board, food contact, print, labelling, converters.',
            'PDS-RESEARCH.md', F('final/W2-stack-of-three-3x2.png'))
    photos = [PageBreak(), Paragraph('The masters', st['h2'])]
    for f, cap, mh in [('W11-catering-hero-16x9', 'W11 · catering hero, 16:9 · 3840 x 2160 · the only home hero', 110),
                       ('W1-closed-box-4x5', 'W1 · closed box, 4:5', 200), ('W3-open-box-4x5', 'W3 · open box, 4:5', 200),
                       ('W2-stack-of-three-3x2', 'W2 · stack of three, 3:2', 120), ('W4-seal-macro-4x5', 'W4 · seal macro, 4:5', 200),
                       ('W5-overhead-stack-3x2', 'W5 · overhead stack, 3:2 · internal reference only', 120)]:
        photos.append(pic(F(f'final/{f}.png'), cw, mh * mm, cap, 2000))
    tiles = [[pic(F(f'final/{f}.png'), cw / 2 - 4 * mm, cw / 2 - 4 * mm, cap, 1100, raw=True) for f, cap in row] for row in
             [[('W7-tile-cheese', 'W7 · cheese'), ('W8-tile-zaatar', "W8 · za'atar")],
              [('W9-tile-meat', 'W9 · meat'), ('W10-tile-spinach', 'W10 · spinach triangle')]]]
    tt = Table(tiles, colWidths=[cw / 2] * 2); tt.setStyle(TableStyle([('VALIGN', (0, 0), (-1, -1), 'TOP'), ('LEFTPADDING', (0, 0), (-1, -1), 0)]))
    photos += [PageBreak(), Paragraph('Item tiles', st['h3']), tt]
    section('05', 'PHOTOGRAPHY AND\nWEBSITE MASTERS', 'One set, one light, one grade: the home hero, the catering page and the item tiles.',
            'sections/photography.md', F('final/W11-catering-hero-16x9.png'), extra_after=photos)
    section('06', 'COSTING', 'Published prices only, dated 3 October 2026, the cost model and the RFQs.',
            'sections/costing.md', F('final/W2-stack-of-three-3x2.png'))
    section('07', 'MERCHANDISE', 'Shirts, aprons, caps, totes, bags, stickers, signage and usage rules.',
            'sections/merch.md', F('final/W5-overhead-stack-3x2.png'))
    section('08', 'MARKETING', 'Launching catering as the hero product: plan, point of sale, content, measurement, compliance.',
            'sections/marketing.md', F('final/W8-tile-zaatar.png'))
    section('09', 'PRODUCTION AND\nEXPANSION', 'File standards, proofs, QA, packing, governance, rollout and the decisions register.',
            'sections/production-expansion.md', F('final/W7-tile-cheese.png'))
    section('10', 'BEFORE THE\nFACTORY', 'The decisions and supplies needed before the tech packs go to a manufacturer.',
            'sections/techpack-gaps.md', F('final/W1-closed-box-4x5.png'),
            extra_before=[Paragraph('Factory documents in this release', st['h2']),
                          Paragraph('Three documents are written to be sent to a manufacturer under NDA. They carry no internal costing, marketing or concept imagery:', st['body']),
                          Paragraph('<b>DoughBoss-CateringBox-TechPack-revA.pdf</b>: the box technical pack DB-TP-CAT-001, with the RFQ form, the English / Chinese glossary and the separated 1:1 dieline appended.', st['li1'], bulletText='•'),
                          Paragraph('<b>DoughBoss-DozenBox-Dieline-v2.pdf</b> and <b>DoughBoss-DozenBox-Die-v2.dxf</b>: the print and die files.', st['li1'], bulletText='•'),
                          Paragraph('<b>DoughBoss-Merch-TechPacks-revA.pdf</b>: ten merchandise and consumable spec sheets with placement diagrams.', st['li1'], bulletText='•'),
                          Spacer(1, 6)])
    # Appendices
    S += [NextPageTemplate('content'), PageBreak(), SetSection('Appendix A · Original brief'), mark('A  Original brief', 0)]
    S += md(F('PACK-BRIEF.md'), cw)
    S += [PageBreak(), SetSection('Appendix B · Open items'), mark('B  Open items index', 0),
          Paragraph('Open items index', st['h1']),
          Paragraph('Every [CONFIRM], [QUOTE], [DRAFT COPY] and REGULATOR-CHECK marker found in this pack, by section, generated from the source files. '
                    'The decisions register in Section 9 (Part D) carries owners and due dates; this index is the completeness check.', st['body'])]
    rows = [['Section', 'Count', 'Items (first 160 characters of each line)']]
    srcs = [('01 Status', 'sections/status.md'), ('02 Brand', 'sections/brand-identity.md'), ('03 Packaging', 'sections/packaging.md'),
            ('04 Technical', 'PDS-RESEARCH.md'), ('05 Photography', 'sections/photography.md'), ('06 Costing', 'sections/costing.md'),
            ('07 Merch', 'sections/merch.md'), ('08 Marketing', 'sections/marketing.md'), ('09 Production', 'sections/production-expansion.md'),
            ('A Brief', 'PACK-BRIEF.md')]
    total = 0
    for nm, p in srcs:
        hits = []
        for ln in open(F(p), encoding='utf8'):
            if re.search(r'\[(CONFIRM|QUOTE|DRAFT COPY)|REGULATOR-CHECK', ln):
                t = re.sub(r'[#>*|`]+', ' ', ln).strip(); t = re.sub(r'\s+', ' ', t)
                hits.append(t[:160] + ('…' if len(t) > 160 else ''))
        total += len(hits)
        S.append(CondPageBreak(30 * mm)); S.append(Paragraph(f'{nm}  ·  {len(hits)} lines', st['h3']))
        for h_ in hits: S.append(Paragraph(inline(h_), st['li1'], bulletText='•'))
    S.append(Paragraph(f'<b>Total lines carrying an open marker: {total}.</b>', st['body']))
    doc.multiBuild(S)
    return total

if __name__ == '__main__':
    out = f'{D}/out/DoughBoss-Brand-and-Catering-Pack-v1.pdf'
    n = build(out); print('built', out, os.path.getsize(out) // 1024, 'KB; open-marker lines', n)
