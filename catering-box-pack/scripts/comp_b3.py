import os, numpy as np
os.chdir('/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/pack')
from PIL import Image
import printcomp as P, planes as Q
ph = np.asarray(Image.open('r2/b3-hero169.png').convert('RGB')).astype(np.float32)
wall = [(1462, 1518), (3482, 1968), (3470, 2150), (1470, 1712)]
Hw = Q.H_face(wall, 388.2, 50.0)
front = P.art_from_black('flats/front-ink.png')
out = Q.place(ph, front, Hw, 0, 0, 388.2, 50.0, light_gain=0.3, opacity=0.80)
Image.fromarray(np.clip(out, 0, 255).astype(np.uint8)).save('r2/c-b3.png')
v = Image.fromarray(np.clip(out, 0, 255).astype(np.uint8)); v.thumbnail((1400, 1400)); v.save('r2/c-b3-v.jpg', quality=90)
Image.fromarray(np.clip(out, 0, 255).astype(np.uint8)).crop((1300, 1350, 3700, 2250)).resize((1200, 450)).save('r2/c-b3-crop.jpg')
