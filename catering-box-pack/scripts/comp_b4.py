import os, numpy as np, cv2
os.chdir('/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/pack')
from PIL import Image
import printcomp as P, planes as Q
ph = np.asarray(Image.open('r2/b4-closed34.png').convert('RGB')).astype(np.float32)
LW, LD, WH = 388.2, 291.6, 50.0
lid = [(1005, 954), (2348, 1428), (1650, 2034), (243, 1448)]          # back-left, back-right, front-right, front-left
wall = [(243, 1448), (1650, 2034), (1650, 2339), (258, 1716)]         # front wall: top-left, top-right, bottom-right, bottom-left
side = [(1650, 2034), (2348, 1428), (2355, 1686), (1650, 2339)]       # right side wall (front end at left)
Hl, Hw, Hs = Q.H_face(lid, LW, LD), Q.H_face(wall, LW, WH), Q.H_face(side, 291.6, WH)
P.WEAR = P.wear_map(ph.shape[:2], [lid, wall, side])
lid_ink = P.art_from_black('flats/lid-ink.png'); front_ink = P.art_from_black('flats/front-ink.png'); side_ink = P.art_from_black('flats/side-ink.png')
out = Q.place(ph, lid_ink, Hl, 0, 0, LW, LD, light_gain=0.4)
out = Q.place(out, front_ink, Hw, 0, 0, LW, WH, light_gain=0.4)
out = Q.place(out, side_ink, Hs, 0, 0, 291.6, WH, light_gain=0.4)
out = Q.apply_seal(out, Q.seal_rgba('r2/s1.png'), Hl, Hw, 322.0, LD, deg=-1.5, wall_v=0.73, lid_v=1.0, cap_frac=0.25)
Image.fromarray(np.clip(out, 0, 255).astype(np.uint8)).save('r2/c-b4.png')
v = Image.fromarray(np.clip(out, 0, 255).astype(np.uint8)); v.thumbnail((1100, 1100)); v.save('r2/c-b4-v.jpg', quality=90)
Image.fromarray(np.clip(out, 0, 255).astype(np.uint8)).crop((1100, 1500, 2100, 2400)).save('r2/c-b4-crop.png')
print('ok')
