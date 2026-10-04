import json, sys, re
from PIL import Image
import numpy as np
def lin(c):
    c = c/255.0
    return np.where(c <= 0.04045, c/12.92, ((c+0.055)/1.055)**2.4)
def lum(arr):
    return 0.2126*lin(arr[...,0]) + 0.7152*lin(arr[...,1]) + 0.0722*lin(arr[...,2])
def parse(c):
    m = re.match(r'rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)', c); return [float(m.group(i)) for i in (1,2,3)]
def L1(rgb): return float(lum(np.array([[rgb]]))[0,0])
res = {}
for tag in sys.argv[1:]:
    d = json.load(open(f'/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/herorun/out/contrast-{tag}.json'))
    dpr = d['dpr']; worst = {}
    for fr in d['frames']:
        im = np.array(Image.open(fr['file']).convert('RGB')).astype(float)
        H, W = im.shape[:2]
        for b in fr['boxes']:
            x0 = max(0, int(b['x']*dpr)); y0 = max(0, int(b['y']*dpr)); x1 = min(W, int((b['x']+b['w'])*dpr)); y1 = min(H, int((b['y']+b['h'])*dpr))
            if x1 - x0 < 2 or y1 - y0 < 2: continue
            crop = im[y0:y1, x0:x1]
            bg = lum(crop).ravel()
            p98 = float(np.percentile(bg, 98))
            col = parse(b['color']); Lt = L1(col)
            k = b['kind']
            if k in ('secondary-button', 'pause-button'):
                # translucent dark fill rgba(9,9,8,.28) over the background
                bgrgb = crop.reshape(-1, 3)
                idx = np.argsort(bg)[int(len(bg)*0.98)]
                over = bgrgb[idx]*0.72 + np.array([9,9,8])*0.28
                p98 = L1(over)
            ratio = (max(Lt, p98)+0.05)/(min(Lt, p98)+0.05)
            key = k
            if key not in worst or ratio < worst[key][0]:
                worst[key] = (ratio, fr['t'], p98, b['fs'])
    res[tag] = worst
    print(f"== {tag} ({d['w']}x{d['h']}) worst-case contrast of the real text colour against the lightest 2% of the background behind it, over {len(d['frames'])} frame(s)")
    for k, (r, t, p98, fs) in worst.items():
        need = 3.0 if (k == 'headline' or fs >= 24) else 4.5
        print(f"   {k:17s} {r:5.2f}:1  (frame {t}s, bg luminance {p98:.3f}, font {fs:.0f}px)  need {need}:1  {'ok' if r >= need else 'LOW'}")
json.dump({t: {k: round(v[0], 2) for k, v in w.items()} for t, w in res.items()}, open('/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/herorun/out/contrast-summary.json', 'w'), indent=1)
