import os, numpy as np
os.chdir('/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/pack')
from PIL import Image
import printcomp as P
photo=np.asarray(Image.open('photos/p7-blank.png').convert('RGB')).astype(np.float32)
artimg=P.art_from_black('flats/lid-ink.png')
TL,TR,BR,BL=np.float32([575,146]),np.float32([1430,119]),np.float32([1492,870]),np.float32([567,893])
f=min(1.0,(np.linalg.norm(TR-TL)/(388.2/291.6))/np.linalg.norm(BL-TL))
L=lambda a,b,t:a+(b-a)*t
tl=L(TL,TR,.012); tr=L(TR,TL,.012); bl=L(TL,BL,f); br=L(TR,BR,f); bl,br=L(bl,br,.012),L(br,bl,.012)
tl=L(tl,bl,.008); tr=L(tr,br,.008)
out=P.composite(photo, artimg, [tl,tr,br,bl], light_gain=0.45)
Image.fromarray(out.astype(np.uint8)).save('photos/p7-comp.png')
v=Image.fromarray(out.astype(np.uint8)); v.thumbnail((1200,1200)); v.save('photos/p7-comp-v.jpg',quality=90)
