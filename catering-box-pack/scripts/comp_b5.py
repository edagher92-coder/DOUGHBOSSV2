import os, numpy as np
os.chdir('/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/pack')
from PIL import Image
import printcomp as P
ph = np.asarray(Image.open('r2/b5-open.png').convert('RGB')).astype(np.float32)
k = 4
quad = [(98 * k, 0), (545 * k, 0), (548 * k, 188 * k), (95 * k, 190 * k)]   # inside lid, hinge at the bottom
art = P.art_from_white('flats/inside-ink.png')
out = P.composite_dark(ph, art, quad, opacity=0.86)
Image.fromarray(np.clip(out, 0, 255).astype(np.uint8)).save('r2/c-b5.png')
v = Image.fromarray(np.clip(out, 0, 255).astype(np.uint8)); v.thumbnail((1000, 1000)); v.save('r2/c-b5-v.jpg', quality=90)
