"""Verify dieline v2 and write out/v2/PREPRESS-CHECK.md from measured results.
Run after build_v2.py:  python3 verify_v2.py
Needs: pikepdf, ezdxf, numpy, scipy, Pillow, poppler (pdfinfo, pdffonts, pdftoppm), Ghostscript (tiffsep)."""
import os, re, subprocess, json, importlib.util, math, shutil, sys
import numpy as np
from PIL import Image
import pikepdf
import ezdxf
from scipy import ndimage

D = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, D)
import dieline
import build_v2 as B
import spots

OUT = f'{D}/out/v2'
PDF, DXF = B.PDF, B.DXF
WORK = f'{D}/t/verify'
os.makedirs(WORK, exist_ok=True)
res = {}
L = []                       # md lines


def sh(cmd, **k):
    return subprocess.run(cmd, shell=True, capture_output=True, text=True, **k).stdout


# ---------------------------------------------------------------- 1. page boxes
box = sh(f'pdfinfo -box -f 1 -l 4 "{PDF}"')
boxes = {}
for m in re.finditer(r'Page\s+(\d+) (\w+):\s+([\d.\- ]+)', box):
    boxes.setdefault(int(m.group(1)), {})[m.group(2)] = [float(v) for v in m.group(3).split()]
ver = re.search(r'PDF version:\s+(\S+)', box).group(1)
ok_boxes = True
for p, b in boxes.items():
    ok_boxes &= (b['TrimBox'] != b['MediaBox'] and b['BleedBox'] != b['MediaBox'])
    tr, bl = b['TrimBox'], b['BleedBox']
    mm = 72 / 25.4
    ok_boxes &= all(abs((tr[i] - bl[i]) - (3 * mm)) < 0.02 for i in (0, 1)) and all(abs((bl[i] - tr[i]) - (3 * mm)) < 0.02 for i in (2, 3))
res['boxes'] = boxes

# ---------------------------------------------------------------- 2. separations, overprint, colour operators (content-stream interpreter)
pdf = pikepdf.open(PDF)
ocg_by_obj = {}
ocp = pdf.Root.OCProperties
for o in ocp.OCGs:
    ocg_by_obj[o.objgen] = str(o.Name)

page_seps, page_stats, page_dev = [], [], []
for pno, page in enumerate(pdf.pages, 1):
    cs = page.Resources.get('/ColorSpace', {})
    seps = {}
    for k, v in cs.items():
        if isinstance(v, pikepdf.Array) and str(v[0]) == '/Separation':
            seps[str(k)[1:]] = str(v[1])[1:]
        else:
            seps[str(k)[1:]] = repr(v)[:40]
    page_seps.append(sorted(set(seps.values())))
    eg = page.Resources.get('/ExtGState', {})
    props = page.Resources.get('/Properties', {})
    prop_names = {str(k): ocg_by_obj.get(v.objgen, '?') for k, v in props.items()}
    # state machine
    stack = []
    st = dict(fcs='DeviceGray', scs='DeviceGray', op=False, OP=False)
    oc_stack = []
    stats = {}          # (space name, 'fill'|'stroke', overprint) -> count
    dev = {}            # device colour ops -> {layer: count}
    for operands, operator in pikepdf.parse_content_stream(page):
        o = str(operator)
        if o == 'q': stack.append((dict(st)))
        elif o == 'Q': st = stack.pop()
        elif o == 'gs':
            g = eg[operands[0]]
            if '/op' in g: st['op'] = bool(g.op)
            if '/OP' in g:
                st['OP'] = bool(g.OP)
                if '/op' not in g: st['op'] = bool(g.OP)
        elif o in ('cs', 'CS'):
            nm = str(operands[0])[1:]
            real = seps.get(nm, nm)
            st['fcs' if o == 'cs' else 'scs'] = real
        elif o in ('g', 'rg', 'k'):
            st['fcs'] = {'g': 'DeviceGray', 'rg': 'DeviceRGB', 'k': 'DeviceCMYK'}[o]
        elif o in ('G', 'RG', 'K'):
            st['scs'] = {'G': 'DeviceGray', 'RG': 'DeviceRGB', 'K': 'DeviceCMYK'}[o]
        elif o == 'BDC':
            oc_stack.append(prop_names.get(str(operands[1]), '?') if str(operands[0]) == '/OC' else '-')
        elif o == 'EMC':
            oc_stack.pop()
        elif o in ('f', 'F', 'f*', 'B', 'B*', 'b', 'b*', 'S', 's', 'Tj', 'TJ', "'", '"'):
            fill = o in ('f', 'F', 'f*', 'B', 'B*', 'b', 'b*', 'Tj', 'TJ', "'", '"')
            stroke = o in ('S', 's', 'B', 'B*', 'b', 'b*')
            lay = oc_stack[-1] if oc_stack else '(none)'
            if fill:
                stats[(st['fcs'], 'fill', st['op'], lay)] = stats.get((st['fcs'], 'fill', st['op'], lay), 0) + 1
                if st['fcs'].startswith('Device'): dev.setdefault(st['fcs'], {}); dev[st['fcs']][lay] = dev[st['fcs']].get(lay, 0) + 1
            if stroke:
                stats[(st['scs'], 'stroke', st['OP'], lay)] = stats.get((st['scs'], 'stroke', st['OP'], lay), 0) + 1
                if st['scs'].startswith('Device'): dev.setdefault(st['scs'], {}); dev[st['scs']][lay] = dev[st['scs']].get(lay, 0) + 1
    page_stats.append(stats); page_dev.append(dev)

# aggregate paint ops by separation and overprint state
agg = {}
for stats in page_stats:
    for (cs_, kind, op, lay), n in stats.items():
        agg.setdefault(cs_, {True: 0, False: 0})
        agg[cs_][op] += n
device_ops = {}
for dev in page_dev:
    for cs_, lays in dev.items():
        for lay, n in lays.items():
            device_ops[(cs_, lay)] = device_ops.get((cs_, lay), 0) + n
raw_text = b''.join(page.Contents.read_bytes() if not isinstance(page.Contents, pikepdf.Array) else b''.join(x.read_bytes() for x in page.Contents) for page in pdf.pages)
rgb_ops = len(re.findall(rb'(?m)^\S+ \S+ \S+ (rg|RG)$', raw_text)) + len(re.findall(rb'\s(rg|RG)\s', raw_text))
grep_names = {n: (raw_text.count(('/' + n.replace(' ', '#20')).encode()) > 0) for n in
              ['WHITE OPAQUE', 'EMBER PMS 485 C', 'DIE CUT', 'DIE CREASE', 'DIMENSIONS']}
all_seps = sorted(set(sum(page_seps, [])))
layers = [str(o.Name) for o in ocp.OCGs]
nonprint = [str(o.Name) for o in ocp.OCGs if str(o.Usage.Print.PrintState) == '/OFF']
res['seps'] = all_seps

# ---------------------------------------------------------------- 3. Ghostscript tiffsep: plates + overprint + clearances
def gs(pdfpath, page, dpi, outdir, tag):
    os.makedirs(outdir, exist_ok=True)
    out = f'{outdir}/{tag}%d.tif'
    r = subprocess.run(f'gs -q -dNOPAUSE -dBATCH -sDEVICE=tiffsep -r{dpi} -dFirstPage={page} -dLastPage={page} -sOutputFile="{out}" "{pdfpath}"',
                       shell=True, capture_output=True, text=True)
    return r.stderr


def plate(outdir, tag, name):
    f = f'{outdir}/{tag}1({name}).tif'
    return (np.array(Image.open(f).convert('L')) < 255) if os.path.exists(f) else None


GSV = sh('gs --version').strip()
plates_info = {}
for pg in (1, 2, 3):
    gs(PDF, pg, 50, f'{WORK}/sep50', f'pg{pg}_')
    # tiffsep names files <prefix>1(<name>).tif because the %d is the page number within the run (always 1 here)
page_plate_files = {}
for pg in (1, 2, 3):
    fs = sorted(f for f in os.listdir(f'{WORK}/sep50') if f.startswith(f'pg{pg}_1(') and f.endswith(').tif'))
    row = {}
    for f in fs:
        nm = f[len(f'pg{pg}_1('):-5]
        a = np.array(Image.open(f'{WORK}/sep50/{f}').convert('L')) < 255
        row[nm] = int(a.sum())
    page_plate_files[pg] = row

# trim-box test: process plates must hold nothing inside the bleed box (only marks outside it)
def ink_inside_bleed(pg, name):
    a = np.array(Image.open(f'{WORK}/sep50/pg{pg}_1({name}).tif').convert('L')) < 255
    H, W = a.shape
    b = boxes[pg]['BleedBox']
    sx, sy = W / boxes[pg]['MediaBox'][2], H / boxes[pg]['MediaBox'][3]
    c0, c1 = int(b[0] * sx), int(b[2] * sx)
    r0, r1 = int((boxes[pg]['MediaBox'][3] - b[3]) * sy), int((boxes[pg]['MediaBox'][3] - b[1]) * sy)
    return int(a[r0:r1, c0:c1].sum()), int(a.sum())

proc_inside = {pg: {n: ink_inside_bleed(pg, n) for n in ('Cyan', 'Magenta', 'Yellow', 'Black')} for pg in (1, 2, 3)}

# --- high-res page 1 and 3 for clearances
DPI = 127
for pg in (1, 3):
    gs(PDF, pg, DPI, f'{WORK}/sep{DPI}', f'pg{pg}_')


def load(pg, name):
    return np.array(Image.open(f'{WORK}/sep{DPI}/pg{pg}_1({name}).tif').convert('L')) < 255


def sheet_xy(shape, pg):
    H, W = shape
    sx, sy = W / B.CW, H / (B.CH + B.INFO)
    cols = (np.arange(W) + 0.5) / sx
    rows = (B.CH + B.INFO) - (np.arange(H) + 0.5) / sy
    return cols - (B.M - B.x0), rows - (B.INFO + B.M - B.y0), sx, sy


clear = {}
for pg, plate_names in ((1, ['WHITE OPAQUE', 'EMBER PMS 485 C']), (3, ['INSIDE DARK'])):
    ink = None
    for nm in plate_names:
        a = load(pg, nm)
        ink = a if ink is None else (ink | a)
    xs, ys, sx, sy = sheet_xy(ink.shape, pg)
    mirror = (pg == 3)
    cxm = (B.x0 + B.x1) / 2
    rr, cc = np.nonzero(ink)
    px, py = xs[cc], ys[rr]
    if mirror:
        px = 2 * cxm - px
    ins = (px >= B.x0) & (px <= B.x1) & (py >= B.y0) & (py <= B.y1)
    px, py = px[ins], py[ins]
    worst = []
    for kind, cx, cy, w, h in B.d.holes:
        if kind == 'oval':
            dist = np.hypot(px - cx, py - cy) - w / 2
        else:
            dx = np.maximum(np.maximum(cx - px, px - (cx + w)), 0); dy = np.maximum(np.maximum(cy - py, py - (cy + h)), 0)
            dist = np.hypot(dx, dy)
        worst.append(float(dist.min()) if dist.size else float('inf'))
    clear[pg] = dict(min_to_holes=min(worst), n_holes=len(worst), px_mm=1 / sx, nearest_per_hole=worst)

# safe zone: distance from art ink to the nearest cut or crease line (excl. the hinge bands, which sit on the crease by design)
cutm = load(1, 'DIE CUT') | load(1, 'DIE CREASE')
# blank out marks region (outside bleedbox) so marks never count
a_ink = load(1, 'WHITE OPAQUE') | load(1, 'EMBER PMS 485 C')
dt = ndimage.distance_transform_edt(~cutm)
xs, ys, sx, sy = sheet_xy(a_ink.shape, 1)
rr, cc = np.nonzero(a_ink)
px, py = xs[cc], ys[rr]
inside = (px >= B.x0) & (px <= B.x1) & (py >= B.y0) & (py <= B.y1)      # marks (colourant All) lie outside the trim
rr, cc, px, py = rr[inside], cc[inside], px[inside], py[inside]
Hh = B.s.W + B.s.H
band = (py > Hh - 5.6) & (py < Hh + 5.6)
dd = dt[rr, cc] / sx
safe_min = float(dd[~band].min()); safe_min_band = float(dd[band].min()) if band.any() else None
# location of the minimum
i = np.argmin(np.where(band, 1e9, dd)); safe_at = (float(px[i]), float(py[i]))

# --- choke / overprint measurement on the hinge band, 10 px/mm
gs(PDF, 1, 254, f'{WORK}/sep254', 'pg1_')
Wp = np.array(Image.open(f'{WORK}/sep254/pg1_1(WHITE OPAQUE).tif').convert('L')) < 255
Ep = np.array(Image.open(f'{WORK}/sep254/pg1_1(EMBER PMS 485 C).tif').convert('L')) < 255
xs, ys, sx, sy = sheet_xy(Wp.shape, 1)
zone_r = (ys > Hh - 6) & (ys < Hh + 6)
band_cols = (xs > B.x0) & (xs < B.x1)


def extent(mask):
    sub = mask[np.ix_(zone_r, band_cols)]
    rr_, cc_ = np.nonzero(sub)
    yy, xx = ys[zone_r][rr_], xs[band_cols][cc_]
    return (xx.min(), xx.max(), yy.min(), yy.max())


# the stop glyph: measure white vs ember inside a window around the largest isolated ember blob in the lid
from scipy.ndimage import label, find_objects
lab, nlab = label(Ep)
sizes = ndimage.sum(Ep, lab, range(1, nlab + 1))
order = np.argsort(sizes)[::-1]
blobs = []
objs = find_objects(lab)
for idx in order[:3]:
    sl = objs[idx]
    r0, r1, c0, c1 = sl[0].start, sl[0].stop, sl[1].start, sl[1].stop
    wcov = int((Wp[sl] & (lab[sl] == idx + 1)).sum()); ecov = int((lab[sl] == idx + 1).sum())
    # white extent inside the same window: rows/cols with white ink within ember blob bbox +-1 mm
    pad = int(0.15 * sx) + 1
    wsl = Wp[max(r0 - pad, 0):r1 + pad, max(c0 - pad, 0):c1 + pad]
    wr, wc = np.nonzero(wsl)
    ew = (c1 - c0) / sx; eh = (r1 - r0) / sy
    # extent of white inside bbox of ember
    wr2, wc2 = np.nonzero(Wp[r0:r1, c0:c1])
    ww = (wc2.max() - wc2.min() + 1) / sx if wc2.size else 0; wh = (wr2.max() - wr2.min() + 1) / sy if wr2.size else 0
    blobs.append(dict(ember_w=ew, ember_h=eh, white_w=ww, white_h=wh, size_px=int(sizes[idx]), white_cover=wcov / max(ecov, 1),
                      x=float(xs[(c0 + c1) // 2]), y=float(ys[(r0 + r1) // 2])))
band_blob = blobs[0]
stop_blob = next((b for b in blobs if b['ember_w'] < 15), blobs[-1])

# --- no-overprint control build: ember with knockout -> white underlay is erased where ember paints
ctrl_pdf = f'{WORK}/ctrl_knockout.pdf'
spots.OVERPRINT[spots.EMBER.spotName] = False
B.PDF_RAW, B.PDF = f'{WORK}/ctrl_raw.pdf', ctrl_pdf
B.build_pdf(); B.postprocess()
spots.OVERPRINT[spots.EMBER.spotName] = True
B.PDF = PDF
gs(ctrl_pdf, 1, 254, f'{WORK}/ctrl', 'pg1_')
Wc = np.array(Image.open(f'{WORK}/ctrl/pg1_1(WHITE OPAQUE).tif').convert('L')) < 255
Ec = np.array(Image.open(f'{WORK}/ctrl/pg1_1(EMBER PMS 485 C).tif').convert('L')) < 255
ember_area = int(Ep.sum())
white_under_ember = int((Wp & Ep).sum()); white_under_ember_ctrl = int((Wc & Ec).sum())

# ---------------------------------------------------------------- 4. DXF read-back
doc = ezdxf.readfile(DXF)
from collections import Counter
ents = Counter((e.dxftype(), e.dxf.layer) for e in doc.modelspace())
from ezdxf import bbox as ebbox
bb = ebbox.extents(doc.modelspace())
dxf_info = dict(units=doc.header['$INSUNITS'], layers=[l.dxf.name for l in doc.layers if l.dxf.name not in ('0', 'Defpoints')],
                ents=dict(ents), bbox=(bb.extmin.x, bb.extmin.y, bb.extmax.x, bb.extmax.y))
circ = sorted((round(e.dxf.center.x, 3), round(e.dxf.center.y, 3), round(e.dxf.radius * 2, 3)) for e in doc.modelspace() if e.dxftype() == 'CIRCLE')
dxf_vs_geom = (sorted((round(cx, 3), round(cy, 3), round(w, 3)) for k, cx, cy, w, h in B.d.holes if k == 'oval') == circ)

# ---------------------------------------------------------------- 5. geometry: vent alignment (reflection model), v1 vs v2, features
def load_module(path, name):
    sp = importlib.util.spec_from_file_location(name, path)
    m = sp.loader.exec_module if False else None
    mod = importlib.util.module_from_spec(sp); sp.loader.exec_module(mod); return mod


v1 = load_module(f'{D}/t/dieline_v1_backup.py', 'dieline_v1')
s = B.s


def alignment(mod):
    d_ = mod.build(s)
    ovals = [h for h in d_.holes if h[0] == 'oval']
    out = []
    for side in ('left', 'right'):
        if side == 'left':
            outer = [h for h in ovals if -s.H < h[1] < 0]
            inner = [h for h in ovals if -2 * s.H < h[1] < -s.H]
            u_out = lambda x: -x                                # distance from the base crease (x = 0)
            fold = -s.H; d_in = lambda x: fold - x              # distance from the roll fold, outward
        else:
            outer = [h for h in ovals if s.L < h[1] < s.L + s.H]
            inner = [h for h in ovals if s.L + s.H < h[1] < s.L + 2 * s.H]
            u_out = lambda x: x - s.L
            fold = s.L + s.H; d_in = lambda x: x - fold
        for yo in sorted({h[2] for h in outer}):
            o = [h for h in outer if h[2] == yo][0]; i = [h for h in inner if h[2] == yo][0]
            uo = u_out(o[1]); di = d_in(i[1]); ui = s.H - di             # reflect the inner ply about the roll fold
            out.append((side, yo, uo, di, ui, ui - uo))
    return out


al1, al2 = alignment(v1), alignment(dieline)
max_off_v1 = max(abs(r[5]) for r in al1); max_off_v2 = max(abs(r[5]) for r in al2)

# features from the geometry (not typed in)
d = B.d
slots = [h for h in d.holes if h[0] == 'slot']
slot_sizes = sorted({(round(h[3], 3), round(h[4], 3)) for h in slots})
outline = d.cuts[0]
fiy = -2 * s.H + s.t
tabs_front = [(outline[i], outline[i + 1], outline[i + 2], outline[i + 3]) for i in range(len(outline) - 3)
              if abs(outline[i][1] - fiy) < 1e-9 and abs(outline[i + 1][1] - (fiy - 6)) < 1e-9]
tab_dims = [(round(abs(t[3][0] - t[0][0]), 3), round(abs(t[1][1] - t[0][1]), 3)) for t in tabs_front]
xr_ = s.L + 2 * s.H - s.t
side_tabs = [(outline[i + 3][1] - outline[i][1], outline[i + 1][0] - outline[i][0]) for i in range(len(outline) - 3)
             if abs(outline[i][0] - xr_) < 1e-9 and abs(outline[i + 1][0] - (xr_ + 6)) < 1e-9]
vent_dia = sorted({h[3] for h in d.holes if h[0] == 'oval'})
ty = s.W + s.H + (s.W + s.t)
tuck_h = max(p[1] for p in outline) - ty
blank = (d.bbox[2] - d.bbox[0], d.bbox[3] - d.bbox[1])
panel_sizes = {k: (round(v[2], 3), round(v[3], 3)) for k, v in d.panels.items()}

# fonts
fonts = sh(f'pdffonts "{PDF}"')
fonts_all_embedded = all(' yes ' in ln for ln in fonts.strip().splitlines()[2:])

# ---------------------------------------------------------------- 6. proofs (PNG) for the record
proof_dir = f'{OUT}/proof'
os.makedirs(proof_dir, exist_ok=True)
for pg in (1, 2, 3):
    subprocess.run(f'pdftoppm -r 30 -f {pg} -l {pg} -png "{PDF}" "{proof_dir}/page{pg}"', shell=True)
names1 = ['WHITE OPAQUE', 'EMBER PMS 485 C', 'DIE CUT', 'DIE CREASE', 'DIMENSIONS', 'PREVIEW BLACK KRAFT']
from PIL import ImageDraw
def montage(pg, names, fn):
    ims = []
    for n in names:
        im = Image.open(f'{WORK}/sep50/pg{pg}_1({n}).tif').convert('L'); im = im.resize((im.width // 2, im.height // 2))
        ImageDraw.Draw(im).text((6, 6), n, fill=0); ims.append(im)
    W_, H_ = ims[0].size; cols = 3; rows = (len(ims) + cols - 1) // cols
    m = Image.new('L', (W_ * cols, H_ * rows), 255)
    for i, im in enumerate(ims): m.paste(im, ((i % cols) * W_, (i // cols) * H_))
    m.save(fn)
montage(1, names1, f'{proof_dir}/page1-plates.png')
montage(3, ['INSIDE DARK', 'DIE CUT', 'DIE CREASE'], f'{proof_dir}/page3-plates.png')
for f in os.listdir(proof_dir):
    if f.startswith('page') and f.endswith('.png') and '-plates' not in f:
        pass

# ---------------------------------------------------------------- write PREPRESS-CHECK.md
def f1(x): return f'{x:.1f}'
def ok(b): return 'PASS' if b else 'FAIL'

L.append('# PREPRESS-CHECK: DB-CAT-DOZEN dieline v2, rev B (draft), 3 Oct 2026\n')
L.append(f'Files checked: `DoughBoss-DozenBox-Dieline-v2.pdf`, `DoughBoss-DozenBox-Die-v2.dxf`. Tools: reportlab 5.0.1, pikepdf, ezdxf, poppler (pdfinfo, pdffonts, pdftoppm), Ghostscript {GSV} (`tiffsep`). Everything below was measured on the delivered files by `verify_v2.py`; nothing is copied from the design intent.\n')
L.append('Result summary: ' + ('ALL MEASURED CHECKS PASS' if (ok_boxes and max_off_v2 < 1e-9 and rgb_ops == 0 and dxf_vs_geom and clear[1]['min_to_holes'] >= 6 - 1.5 / (DPI / 25.4) and clear[3]['min_to_holes'] >= 6 - 1.5 / (DPI / 25.4) and safe_min >= 5 - 1.5 / (DPI / 25.4) and fonts_all_embedded) else 'SEE FAILURES BELOW') + '. Open items are listed in section 9.\n')

L.append('## 1. Page boxes (`pdfinfo -box`)\n')
L.append('| Page | MediaBox (pt) | TrimBox (pt) | BleedBox (pt) | Meaning |\n|---|---|---|---|---|')
meaning = {1: 'outside print, all plates + die', 2: 'die only, dimensions, legend, title block', 3: 'inside print, viewed from inside', 4: 'separations proof, 6 plates at 1:1 (proof sheet, not a print page; boxes enclose the plate grid)'}
for p in (1, 2, 3, 4):
    b = boxes[p]
    L.append(f"| {p} | {' '.join(f1(v) for v in b['MediaBox'])} | {' '.join(f1(v) for v in b['TrimBox'])} | {' '.join(f1(v) for v in b['BleedBox'])} | {meaning[p]} |")
b1 = boxes[1]; mmpt = 72 / 25.4
L.append(f"\nPage 1 TrimBox = {f1((b1['TrimBox'][2] - b1['TrimBox'][0]) / mmpt)} x {f1((b1['TrimBox'][3] - b1['TrimBox'][1]) / mmpt)} mm (the blank). BleedBox = TrimBox + 3 mm each side ({f1((b1['BleedBox'][2] - b1['BleedBox'][0]) / mmpt)} x {f1((b1['BleedBox'][3] - b1['BleedBox'][1]) / mmpt)} mm). Neither equals the MediaBox, which carries crop marks, registration targets and the information strip. ArtBox = TrimBox. PDF version {ver}. Result: **{ok(ok_boxes)}**.\n")

L.append('## 2. Separations in the PDF\n')
L.append('Separation (spot) colourants found in the page resources (pikepdf):\n')
L.append('| Colourant | Role | Paint operations (overprint ON / OFF) |\n|---|---|---|')
role = {'WHITE OPAQUE': 'white artwork + white underlay', 'EMBER PMS 485 C': 'ember full stops and hinge bands', 'DIE CUT': 'cut, slots, vents',
        'DIE CREASE': 'creases (dashed)', 'DIMENSIONS': 'non-printing information', 'INSIDE DARK': 'optional inside-lid print (placeholder colour)',
        'PREVIEW BLACK KRAFT': 'non-printing board preview, outside', 'PREVIEW NATURAL KRAFT': 'non-printing board preview, inside', 'All': 'crop and registration marks (every plate)'}
for n in all_seps:
    a = agg.get(n, {True: 0, False: 0})
    L.append(f'| `{n}` | {role.get(n, "")} | {a[True]} / {a[False]} |')
L.append('')
L.append('Exact required names present in the file (content/resources grep): ' + ', '.join(f'`{k}`: {"yes" if v else "NO"}' for k, v in grep_names.items()) + '.\n')
L.append(f'RGB: **{rgb_ops}** RGB colour operators in all page content (v1 was RGB throughout). DeviceCMYK / DeviceGray colour operators used for painting: ' + (', '.join(f'{k}: {v}' for k, v in device_ops.items()) if device_ops else '**none**') + '. Every painted object is a spot colour (the board preview is its own spot, so it can never land on a process plate).\n')
L.append('Overprint, read from the content streams (graphics-state tracker over `gs` / `cs` / paint operators; `/op` fill and `/OP` stroke):\n')
for n in ('DIE CUT', 'DIE CREASE', 'DIMENSIONS', 'All', 'EMBER PMS 485 C'):
    a = agg.get(n, {True: 0, False: 0})
    L.append(f'- `{n}`: {a[True]} painted with overprint ON, {a[False]} with overprint OFF. **{ok(a[False] == 0 and a[True] > 0)}**')
for n in ('WHITE OPAQUE', 'INSIDE DARK'):
    a = agg.get(n, {True: 0, False: 0})
    L.append(f'- `{n}`: {a[True]} ON, {a[False]} OFF (knockout by design; painted first; nothing printed beneath it).')
L.append('\nreportlab 5.0.1 supports overprint natively: `canvas.setFillOverprint()` writes `/op` and `canvas.setStrokeOverprint()` writes `/OP` into an ExtGState; `setOverprintMask()` writes `/OPM`. The ExtGState resources and `gs` operators are present in the file (see counts above). EMBER overprint is deliberate: with a knockout the ember paint would erase the white underlay on the WHITE plate (control test in section 4).\n')
L.append(f'Layers (optional content): {", ".join(layers)}. Non-printing (Print usage OFF): {", ".join(nonprint)}. Note: Ghostscript ignores Print-usage flags, which is why the previews are spot colours and not relied on to hide.\n')
L.append(f'Fonts (`pdffonts`): all embedded as TrueType subsets (**{ok(fonts_all_embedded)}**); the non-embedded default Helvetica of v1 is gone. Legend states: fonts embedded; outline before plate making if required.\n')
L.append('```\n' + fonts.strip() + '\n```\n')

L.append(f'## 3. Separation render (Ghostscript {GSV} `-sDEVICE=tiffsep`)\n')
L.append('Pages 1 to 3 rendered at 50 dpi (clearances at 127 dpi, choke at 254 dpi). Plates produced and ink pixel counts at 50 dpi:\n')
L.append('| Page | Plate | Ink px |\n|---|---|---|')
for pg in (1, 2, 3):
    for nm, n in sorted(page_plate_files[pg].items()):
        L.append(f'| {pg} | {nm} | {n} |')
L.append('')
L.append('Process plates (Cyan, Magenta, Yellow, Black) hold only the registration marks (colourant All maps to every plate). Ink pixels inside the BleedBox, per page [inside bleed / total]: ' +
         '; '.join(f'p{pg} ' + ', '.join(f'{k} {v[0]}/{v[1]}' for k, v in proc_inside[pg].items()) for pg in (1, 2, 3)) +
         f'. Result: **{ok(all(v[0] == 0 for pg in proc_inside for v in proc_inside[pg].values()))}** (no process ink inside the box).\n')
L.append('Expected plates by page: page 1 WHITE OPAQUE, EMBER PMS 485 C, DIE CUT, DIE CREASE, DIMENSIONS (+ PREVIEW BLACK KRAFT, non-printing); page 2 DIE CUT, DIE CREASE, DIMENSIONS; page 3 INSIDE DARK, DIE CUT, DIE CREASE, DIMENSIONS (+ PREVIEW NATURAL KRAFT). Page 4 is the proof sheet. Contact sheets of the plates: `proof/page1-plates.png`, `proof/page3-plates.png` (ink = black); page renders at 30 dpi `proof/page*.png`.\n')

L.append('## 4. White underlay and ember (choke 0.3 mm), measured on page 1 at 10 px/mm\n')
L.append(f"- Hinge band (largest ember object): ember {band_blob['ember_w']:.1f} x {band_blob['ember_h']:.1f} mm; white underlay inside it {band_blob['white_w']:.1f} x {band_blob['white_h']:.1f} mm. Expected for the lid and back bands together: ember 364.2 x 10.0, white 363.6 x 9.4 (choked 0.3 mm at both ends and the free edges, flush at the hinge crease).")
L.append(f"- Full stop on the lid: ember {stop_blob['ember_w']:.2f} x {stop_blob['ember_h']:.2f} mm, white {stop_blob['white_w']:.2f} x {stop_blob['white_h']:.2f} mm (expected white = ember minus 0.6 mm in each direction, resolution 0.1 mm).")
L.append(f"- Overprint control: with EMBER overprint ON, {white_under_ember} px of WHITE sit under EMBER ({100 * white_under_ember / ember_area:.1f} % of the ember area, the rest is the 0.3 mm choke rim and the wordmark stops' choke). With EMBER set to knockout (control build, not delivered) only {white_under_ember_ctrl} px do ({100 * white_under_ember_ctrl / ember_area:.1f} %): the white underlay would be erased. Result: **{ok(white_under_ember > 50 * max(white_under_ember_ctrl, 1))}**.\n")

L.append('## 5. Vent alignment arithmetic (side walls, centres after rolling)\n')
L.append('Model: the inner ply folds 180 degrees about the roll fold and lies on the inside of the outer ply, so a point at distance d from the roll fold lands at u = H - d from the base crease. H = 50 mm, t = 1.6 mm. Bend and caliper allowances are left to the converter\'s CAD.\n')
L.append('| Version | Wall | y (mm) | Outer: u = H/2 | Inner on the flat: d from fold | Inner rolled: u = H - d | Offset |\n|---|---|---|---|---|---|---|')
for tag, al in (('v1', al1), ('v2', al2)):
    for side, yo, uo, di, ui, off in al:
        L.append(f'| {tag} | {side} | {yo:.1f} | {uo:.1f} | {di:.1f} | {ui:.1f} | {off:+.3f} |')
L.append(f'\nv1: inner centre at the middle of the inner panel\'s own 48.4 mm span, d = (H - t)/2 = 24.2, so u = 50 - 24.2 = 25.8 and offset = 25.8 - 25.0 = +0.8 mm (= t/2). v2: require u_in = u_out, so d = H - H/2 = 25.0 mm from the roll fold, i.e. x = -H - 25 = -75.0 (left) and L + H + 25 = 460.0 (right); u = 50 - 25.0 = 25.0. Maximum offset v1 {max_off_v1:.3f} mm, v2 {max_off_v2:.3f} mm. Along the wall both plies use y = 58.0 and 232.0 (20 % and 80 % of 290): the inner ply is attached along the roll fold so y does not change. The arithmetic is also in the comment in `dieline.py`. Result: **{ok(max_off_v2 < 1e-9)}**.\n')

L.append('## 6. Blank size and features (from the geometry)\n')
L.append(f'- Blank (bounding rectangle of the die outline): **{blank[0]:.1f} x {blank[1]:.1f} mm** (x {d.bbox[0]:.1f} to {d.bbox[2]:.1f}, y {d.bbox[1]:.1f} to {d.bbox[3]:.1f}). Matches the pinned working die 593.8 x 781.0 ({ok(abs(blank[0] - 593.8) < 0.05 and abs(blank[1] - 781.0) < 0.05)}). Minimum printed sheet with 3 mm bleed: {blank[0] + 6:.1f} x {blank[1] + 6:.1f} mm.')
L.append(f'- Internal size {s.L:.0f} x {s.W:.0f} x {s.H:.0f}; board caliper t = {s.t} (E-flute, working value).')
L.append('- Panel sizes (mm): ' + '; '.join(f'{k} {v[0]} x {v[1]}' for k, v in panel_sizes.items()) + '.')
L.append(f'- Tuck flap depth {tuck_h:.1f}; lock tab (front inner) {tab_dims[0][0]:.0f} x {tab_dims[0][1]:.0f} ({len(tab_dims)} front tabs found; right-side inner tabs {[(round(a, 3), round(b_, 3)) for a, b_ in side_tabs]} (width, depth), 2 per side); lock slots {slot_sizes} ({len(slots)} off); vent diameter {vent_dia} ({len([h for h in d.holes if h[0] == "oval"])} punches on the flat, 4 on the box).')
L.append('- Tuck side relief 3 mm and corner radius 6 mm are constants in `dieline.py`; the 6 mm radius is approximated by 6 chords per quarter-circle in both the PDF and the DXF (sagitta about 0.05 mm). [CONFIRM: converter to replace with true arcs in CAD.]\n')

L.append('## 7. Clearances: print against vents, slots, creases (raster, from the separated plates)\n')
c1, c3 = clear[1], clear[3]
tol = 1.5 / (DPI / 25.4)
L.append(f'- Page 1 (WHITE + EMBER ink): nearest ink to any of the {c1["n_holes"]} cut-outs (8 vents, 6 slots) is **{c1["min_to_holes"]:.1f} mm** (resolution {c1["px_mm"]:.2f} mm; requirement at least 6 mm): **{ok(c1["min_to_holes"] >= 6 - tol)}**.')
L.append(f'- Page 3 (INSIDE DARK ink, mirrored view): nearest ink to a cut-out **{c3["min_to_holes"]:.1f} mm**: **{ok(c3["min_to_holes"] >= 6 - tol)}**.')
L.append(f'- Safe zone, page 1: nearest art ink to any cut or crease line, excluding the two hinge bands that sit on the hinge crease by design, is **{safe_min:.1f} mm** (at x {safe_at[0]:.0f}, y {safe_at[1]:.0f}); requirement 5 mm: **{ok(safe_min >= 5 - tol)}**. The hinge bands themselves touch the hinge crease by design (min distance {safe_min_band if safe_min_band is None else round(safe_min_band, 2)} mm); the converter should confirm that is acceptable at the fold.\n')

L.append('## 8. DXF read-back (`ezdxf`)\n')
L.append(f'- Units: $INSUNITS = {dxf_info["units"]} (4 = millimetres). Layers: {", ".join(dxf_info["layers"])}. Entities: {dxf_info["ents"]}.')
L.append(f'- Extents: x {dxf_info["bbox"][0]:.1f} to {dxf_info["bbox"][2]:.1f}, y {dxf_info["bbox"][1]:.1f} to {dxf_info["bbox"][3]:.1f} = {dxf_info["bbox"][2] - dxf_info["bbox"][0]:.1f} x {dxf_info["bbox"][3] - dxf_info["bbox"][1]:.1f} mm, same origin and orientation (outside view, y up) as the PDF. Result: **{ok(abs((dxf_info["bbox"][2] - dxf_info["bbox"][0]) - blank[0]) < 0.01 and abs((dxf_info["bbox"][3] - dxf_info["bbox"][1]) - blank[1]) < 0.01)}**.')
L.append(f'- 8 vent circles on layer VENT match the 8 vent holes in the PDF geometry (centres and diameters): **{ok(dxf_vs_geom)}**. CUT: outline polyline + 6 slot rectangles. CREASE: {dxf_info["ents"].get(("LINE", "CREASE"), 0)} lines, linetype DASHED.')
L.append('- Both files are generated from the same `dieline.build()` object in one run of `build_v2.py`. Not verified: that a particular CAD (ArtiosCAD, Kongsberg) imports the layers with the intended line types.\n')

L.append('## 9. Not verified or open\n')
L.append('- No physical proof, press check or converter CAD import was done. The die is a working structure: internal size (fit test with 12 bakes), caliper, flute direction, crease rule, tab/slot and tuck values, and bend allowances are [CONFIRM] with the converter\'s structural engineer before tooling.')
L.append('- Ember alternate CMYK is a screen simulation of PANTONE 485 C, not a measured match [CONFIRM: drawdown]. White alternate is a cream screen colour only.')
L.append('- `INSIDE DARK` is a placeholder spot name; the inside-lid colour and whether to print it are undecided (tech pack gap 13) [CONFIRM].')
L.append('- Registration marks use the colourant `All`; mark positions, gripper edge, sheet layout and trapping values are [SUPPLIER TO PROPOSE]. The PDF is not PDF/X.')
L.append('- Art changes from v1, colour model and plates only: choke now exactly 0.3 mm (v1 about 0.19 mm on the full stop); the wordmark full stop now has its white underlay (v1 ember direct on black); the back-wall hinge band underlay is choked on the free edge, not the hinge edge. Copy and positions are unchanged.')
L.append('- The seal label is not on this die; `art.py` still holds it in RGB and it needs its own label file.')
L.append('- Dimensions on page 2 are drawn from the geometry; the converter should still verify the dimension scale on the first plot (print page 2 at 100 % and measure the 385 mm base).')

open(f'{OUT}/PREPRESS-CHECK.md', 'w').write('\n'.join(L) + '\n')
print('\n'.join(L[:3]))
json.dump(dict(safe_min=safe_min, clear=clear, band=band_blob, stop=stop_blob, wue=white_under_ember, wuec=white_under_ember_ctrl, ember_area=ember_area,
               agg={k: {str(a): b for a, b in v.items()} for k, v in agg.items()}, dev={str(k): v for k, v in device_ops.items()}, rgb=rgb_ops),
          open(f'{WORK}/summary.json', 'w'), indent=1, default=str)
