"""Packaging data sheet PDF: a short, standalone spec for converters (reuses the pack's renderer)."""
import os
import build_pack as B
from reportlab.platypus import BaseDocTemplate, PageTemplate, Frame, PageBreak, Paragraph, Spacer
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
W, H = A4; M = B.M; D = B.D
B.VERSION = 'DB-PDS-CAT-001 rev A · 3 October 2026'

class PDS(BaseDocTemplate):
    def afterFlowable(self, f):
        if isinstance(f, B.SetSection): self.section = f.name

def build(out):
    doc = PDS(out, pagesize=A4, title='Dough Boss catering dozen box: packaging data sheet (draft)', author='Dough Boss (concept)')
    fr = Frame(M, 17 * mm, W - 2 * M, H - 35 * mm, leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
    doc.addPageTemplates([PageTemplate('content', [fr], onPage=B.content_page)])
    cw = W - 2 * M
    S = [B.SetSection('Packaging data sheet'), B.mark('Packaging data sheet', 0)]
    S += B.md(f'{D}/sections/pds.md', cw)
    S += [PageBreak(), Paragraph('Appendix: dieline and artwork', B.st['h2']),
          B.pic(f'{D}/out/dlv2-2.png', cw, 200 * mm, 'Working dieline with dimensions (DoughBoss-DozenBox-Dieline-v2.pdf, page 2). The converter draws production CAD from it.', 2000),
          PageBreak(), B.pic(f'{D}/out/dlv2-1.png', cw, 200 * mm, 'Outside print plates with the die overlaid (v2, page 1).', 2000)]
    doc.build(S)

if __name__ == '__main__':
    out = f'{D}/out/DoughBoss-DozenBox-PDS-revA.pdf'; build(out); print(out, os.path.getsize(out) // 1024, 'KB')
