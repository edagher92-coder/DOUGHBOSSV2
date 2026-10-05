import os, numpy as np, cv2
os.chdir('/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/pack')
from PIL import Image
im = np.asarray(Image.open('r2/c-b5.png').convert('RGB')).copy()
old = [(286, 1430), (270, 1564), (2276, 1414), (2292, 1556)]
stamps = {}
for (x, y) in old[:1] + old[2:3]:
    stamps[x < 1000] = im[y - 40:y + 40, x - 30:x + 30].copy()
mask = np.zeros(im.shape[:2], np.uint8)
for (x, y) in old: cv2.ellipse(mask, (x, y), (20, 32), 0, 0, 360, 255, -1)
mask = cv2.dilate(mask, np.ones((7, 7), np.uint8))
im = cv2.inpaint(im, mask, 9, cv2.INPAINT_TELEA)
# subtle texture back onto the inpainted wall (grain from nearby wall)
rng = np.random.default_rng(3); g = rng.normal(0, 3, im.shape[:2]).astype(np.float32)
m = cv2.GaussianBlur(mask.astype(np.float32) / 255, (0, 0), 3)[..., None]
im = np.clip(im + (g[..., None] * m), 0, 255).astype(np.uint8)
new = {True: [(323, 1120, 0.9), (201, 2140, 1.15)], False: [(2243, 1120, 0.9), (2358, 2140, 1.15)]}
for left, pts in new.items():
    st = stamps[left]
    for (x, y, s) in pts:
        p = cv2.resize(st, None, fx=s, fy=s, interpolation=cv2.INTER_LANCZOS4)
        h, w = p.shape[:2]
        a = np.zeros((h, w), np.float32); cv2.ellipse(a, (w // 2, h // 2), (int(w * 0.42), int(h * 0.42)), 0, 0, 360, 1.0, -1)
        a = cv2.GaussianBlur(a, (0, 0), 3)[..., None]
        y0, x0 = y - h // 2, x - w // 2
        roi = im[y0:y0 + h, x0:x0 + w].astype(np.float32)
        im[y0:y0 + h, x0:x0 + w] = (roi * (1 - a) + p * a).astype(np.uint8)
Image.fromarray(im).save('r2/c-b5.png')
v = Image.fromarray(im); v.thumbnail((1000, 1000)); v.save('r2/c-b5-v.jpg', quality=90)
Image.fromarray(im[700:3300, 0:560]).resize((280, 1300)).save('r2/c-b5-leftcheck.png')
