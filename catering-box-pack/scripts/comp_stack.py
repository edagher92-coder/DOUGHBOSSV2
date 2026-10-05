import os, numpy as np, cv2
os.chdir('/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/pack')
from PIL import Image
import printcomp as P, planes as Q
ph = np.asarray(Image.open('r2/p2-blank.png').convert('RGB')).astype(np.float32)
LW, LD, WH = 388.2, 291.6, 50.0
lid = [(560, 164), (1510, 164), (1756, 410), (304, 400)]
walls = [[(304, 406), (1756, 416), (1744, 650), (310, 640)],
         [(304, 656), (1744, 664), (1736, 890), (316, 880)],
         [(310, 890), (1736, 900), (1730, 1120), (330, 1110)]]
lid_ink = P.art_from_black('flats/lid-ink.png'); front = P.art_from_black('flats/front-ink.png')
Hl = Q.H_face(lid, LW, LD)
P.WEAR = P.wear_map(ph.shape[:2], [lid] + walls)
out = Q.place(ph, lid_ink, Hl, 0, 0, LW, LD, light_gain=0.4)
Hws = [Q.H_face(w, LW, WH) for w in walls]
for i, H in enumerate(Hws):
    out = Q.place(out, front, H, 0, 0, LW, WH, light_gain=0.4, seed=20 + i)
def wall_v(q):
    # equal px per mm on both axes of the wall face, so a round sticker stays round on a plate whose box is taller than 50 mm
    q = np.float32(q); length = (np.linalg.norm(q[1] - q[0]) + np.linalg.norm(q[2] - q[3])) / 2
    height = (np.linalg.norm(q[3] - q[0]) + np.linalg.norm(q[2] - q[1])) / 2
    return float((length / 388.2) / (height / 50.0))
# one placement everywhere: across the tuck-front edge, 30 % cap on the lid, 70 % on the wall.
# Boxes 2 and 3: their caps sit under the box above, so only the wall part is visible.
for i, (sp, dx, deg) in enumerate([('r2/s1.png', 0, -1.5), ('r2/s2.png', 6, 2.0), ('r2/s3.png', -7, -2.5)]):
    seal = Q.seal_rgba(sp)
    if i == 0:
        wv = wall_v(walls[0])
        out = Q.apply_seal(out, seal, Hl, Hws[0], 322 + dx, LD, deg=deg, wall_v=wv, cap_frac=0.25)
    else:
        s_ = seal.shape[0]
        if deg:
            M = cv2.getRotationMatrix2D((s_ / 2, s_ / 2), deg, 1)
            seal = cv2.warpAffine(seal, M, (s_, s_), flags=cv2.INTER_LINEAR, borderValue=(0, 0, 0, 0))
        cut = int(round(s_ * 0.25)); bot = seal[cut:]
        wv = wall_v(walls[i])
        qb = Q.mm_to_px(Hws[i], [[322 + dx - 35, 0], [322 + dx + 35, 0], [322 + dx + 35, 52.5 * wv], [322 + dx - 35, 52.5 * wv]])
        qb[0] = qb[0] + [0, 2]; qb[1] = qb[1] + [0, 2]          # 2 px below the lip of the box above
        out = Q.paste_paper(out, bot, qb, top_ao=0.45)
Image.fromarray(np.clip(out, 0, 255).astype(np.uint8)).save('r2/c-stack.png')
v = Image.fromarray(np.clip(out, 0, 255).astype(np.uint8)); v.thumbnail((1400, 1400)); v.save('r2/c-stack-v.jpg', quality=90)
