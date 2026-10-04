"""Factory-facing documents (no internal costing or marketing):
  out/DoughBoss-CateringBox-TechPack-revA.pdf  = tech pack + the separated dieline (vector pages appended) + artwork flats
  out/DoughBoss-Merch-TechPacks-revA.pdf       = merch spec sheets, each followed by its placement diagram"""
import os, re, glob
import pikepdf
import build_pack as B
from reportlab.platypus import BaseDocTemplate, PageTemplate, Frame, PageBreak, Paragraph, Spacer
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
W, H = A4; M = B.M; D = B.D
B.FOOT = 'CONFIDENTIAL  ·  SUPPLIED UNDER NDA FOR QUOTATION AND SAMPLES ONLY'

class Doc(BaseDocTemplate):
    def afterFlowable(self, f):
        if isinstance(f, B.SetSection): self.section = f.name

def doc(out, title):
    d = Doc(out, pagesize=A4, title=title, author='Dough Boss', subject='Confidential: supplied under NDA')
    fr = Frame(M, 17 * mm, W - 2 * M, H - 35 * mm, leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
    d.addPageTemplates([PageTemplate('content', [fr], onPage=B.content_page)]); return d

def box_techpack():
    B.VERSION = 'DB-TP-CAT-001 rev A · 3 October 2026'
    tmp = f'{D}/out/_tp_body.pdf'; d = doc(tmp, 'Dough Boss catering dozen box: technical pack DB-TP-CAT-001 rev A'); cw = W - 2 * M
    S = [B.SetSection('Technical pack DB-TP-CAT-001'), B.mark('Technical pack', 0)] + B.md(f'{D}/sections/techpack.md', cw)
    S += [PageBreak(), B.SetSection('Artwork reference'), B.mark('Artwork reference (concept artwork, vector files supplied separately)', 1),
          Paragraph('Artwork reference', B.st['h2']),
          Paragraph('Flat artwork as designed. The vector print files and the separated dieline (appended after this page) govern; these previews are for orientation only.', B.st['body']),
          B.pic(f'{D}/flats/closed-art.png', cw, 110 * mm, 'Outside: lid, front, sides (WHITE OPAQUE and EMBER on black kraft).'),
          B.pic(f'{D}/flats/back.png', cw, 30 * mm, 'Back wall.'),
          B.pic(f'{D}/flats/inside-lid.png', cw * 0.7, 70 * mm, 'Inside lid, optional one-colour print on natural kraft.'),
          PageBreak(),
          B.pic(f'{D}/flats/liner.png', cw * 0.7, 80 * mm, 'Greaseproof liner (plain is the default).'),
          B.pic(f'{D}/flats/seal.png', 70 * mm, 70 * mm, 'Seal label, 70 mm round kraft.')]
    d.build(S)
    out = f'{D}/out/DoughBoss-CateringBox-TechPack-revA.pdf'
    die = f'{D}/out/v2/DoughBoss-DozenBox-Dieline-v2.pdf'
    if not os.path.exists(die): die = f'{D}/out/DoughBoss-DozenBox-Dieline-v1.pdf'
    with pikepdf.open(tmp) as body, pikepdf.open(die) as dl:
        body.pages.extend(dl.pages)
        with body.open_outline() as ol:
            ol.root.append(pikepdf.OutlineItem(f'Dieline ({os.path.basename(die)}), 1:1 vector pages', len(body.pages) - len(dl.pages)))
        body.docinfo['/Title'] = 'Dough Boss catering dozen box: technical pack DB-TP-CAT-001 rev A'
        body.save(out)
    os.remove(tmp); return out, die

def merch_techpack():
    B.VERSION = 'DB-TP-MERCH-001 rev A · 3 October 2026'
    out = f'{D}/out/DoughBoss-Merch-TechPacks-revA.pdf'; d = doc(out, 'Dough Boss merchandise tech packs DB-TP-MERCH-001 rev A'); cw = W - 2 * M
    txt = open(f'{D}/sections/merch-techpacks.md', encoding='utf8').read()
    parts = re.split(r'(?m)^(?=## )', txt)
    diagrams = {1: 'staff-tshirt', 2: 'bib-apron', 3: 'cap', 4: 'tote-bag', 5: 'takeaway-bag', 6: 'sticker-sheet',
                7: 'tent-card', 8: 'window-decal', 9: 'seal-label', 10: 'liner'}
    S = [B.SetSection('Merchandise tech packs'), B.mark('Merchandise tech packs', 0)]
    tmpmd = f'{D}/out/_part.md'
    for part in parts:
        open(tmpmd, 'w', encoding='utf8').write(part)
        m = re.match(r'## (\d+)\.', part)
        if m: S.append(PageBreak())
        S += B.md(tmpmd, cw)
        if m and int(m.group(1)) in diagrams:
            png = f'{D}/flats/merch/{diagrams[int(m.group(1))]}.png'
            if os.path.exists(png):
                S += [PageBreak(), B.pic(png, cw, 225 * mm, f'Placement diagram: {diagrams[int(m.group(1))]}. Artwork and dimension lines true to size; garment outline schematic.', 2200)]
    os.remove(tmpmd); d.build(S); return out

if __name__ == '__main__':
    o, die = box_techpack(); print(o, os.path.getsize(o) // 1024, 'KB', 'dieline:', os.path.basename(die))
    o = merch_techpack(); print(o, os.path.getsize(o) // 1024, 'KB')
