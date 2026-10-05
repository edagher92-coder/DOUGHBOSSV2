# R5 retouch on W4: close the exposed lid-front flute into a rounded fold,
# remove the four stray tan dashes and smooth the tone seam below them.
import numpy as np
from PIL import Image, ImageDraw
from scipy.ndimage import gaussian_filter, binary_dilation, gaussian_filter1d
SRC='r2/W4-pre-r5.png'; OUT='final/W4-seal-macro-4x5.png'
a=np.asarray(Image.open(SRC)).astype(np.float32)
Hh,Ww,_=a.shape
out=a.copy()
yy,xx=np.mgrid[0:Hh,0:Ww].astype(np.float32)

# --- 1. flute band -> closed fold -------------------------------------------
top=764+0.17*xx            # upper edge of exposed flute (lid surface ends)
bot=806+0.135*xx           # lower edge (just above the existing crest highlight)
seal_edge=407-(yy-800)*0.28  # seal's left edge in this zone
band=(yy>top-3)&(yy<bot+1)&(xx<seal_edge+2)
t=np.clip((yy-top)/np.maximum(bot-top,1),0,1)
# texture: same column from the lid above the band
H=48
src=np.roll(a,H,axis=0)    # src[y]=a[y-H]
# curvature falloff: surface turns away from the key toward the crest
gain=0.62-0.22*np.sin(np.pi*np.clip(t,0,1))*0.5-0.10*t
fill=src*gain[...,None]
# a 1-2 px soft highlight on the upper face where the lid starts to roll
hl=np.exp(-((yy-(top+5))/2.2)**2)*14
fill=np.nan_to_num(fill+hl[...,None])
# feather: vertical 3 px, toward seal 3 px
m=band.astype(np.float32)
m=gaussian_filter(m,1.6)
m*=np.clip((seal_edge-xx)/3.0,0,1)
out=out*(1-m[...,None])+fill*m[...,None]

# fold cracks along the crest: black coating cracked to brown kraft
S=4
lay=Image.new('L',(Ww*S,Hh*S),0); d=ImageDraw.Draw(lay)
rng=np.random.default_rng(11)
for x0,ln in ((48,22),(152,15),(236,26),(318,13)):
    yc=806+0.135*x0-2
    pts=[];x=x0;y=yc
    for k in range(6):
        pts.append((x*S,y*S)); x+=ln/5; y+=0.135*ln/5+rng.uniform(-0.7,0.7)
    d.line(pts,fill=255,width=int(1.3*S))
lay=np.asarray(lay.resize((Ww,Hh),Image.LANCZOS)).astype(np.float32)/255*0.55
kraft=np.array([128,96,66],np.float32)
out=out*(1-lay[...,None])+kraft*lay[...,None]

# --- 2. stray tan dashes y 1160-1210 -----------------------------------------
L=a.mean(2); warm=a[...,0]-a[...,2]
zone=(yy>1155)&(yy<1215)&(xx<420)
dash=zone&(warm>14)&(L>38)
dash=binary_dilation(dash,iterations=5)
rep=0.5*(np.roll(a,45,axis=0)+np.roll(a,-45,axis=0))
dm=gaussian_filter(dash.astype(np.float32),2.0)
out=out*(1-dm[...,None])+rep*dm[...,None]

# --- 3. tone seam ~y1235: smooth the row-mean profile, keep texture ----------
y0,y1=1140,1300; xs=slice(0,400)
prof=out[y0:y1,xs].mean(axis=(1,2))
sm=gaussian_filter1d(prof,18,mode='nearest')
off=(sm-prof)
w=np.clip((420-np.arange(Ww))/20,0,1)     # fade before the seal
taper=np.ones(y1-y0); r=np.linspace(0,1,25); taper[:25]=r; taper[-25:]=r[::-1]
out[y0:y1]+= (off*taper)[:,None,None]*w[None,:,None]

out=np.clip(out,0,255).astype(np.uint8)
Image.fromarray(out).save(OUT)
Image.fromarray(out).resize((Ww*2//3,Hh*2//3),Image.LANCZOS).save('final/W4-seal-macro-4x5-v.jpg',quality=88)
print('dash px',int(dash.sum()),'prof range before',prof.min().round(1),prof.max().round(1))

# --- 4. seam line at y~1236: cross-fade textures from above/below ----------
a2=np.asarray(Image.open(OUT)).astype(np.float32)
up=np.roll(a2,22,axis=0); dn=np.roll(a2,-22,axis=0)
s=np.clip((yy-1222)/28,0,1); s=s*s*(3-2*s)
mix=up*(1-s[...,None])+dn*s[...,None]
mm=np.exp(-((yy-1236)/7.0)**2)*np.clip((415-xx)/15,0,1)
a2=a2*(1-mm[...,None])+mix*mm[...,None]
a2=np.clip(a2,0,255).astype(np.uint8)
Image.fromarray(a2).save(OUT)
Image.fromarray(a2).resize((Ww*2//3,Hh*2//3),Image.LANCZOS).save('final/W4-seal-macro-4x5-v.jpg',quality=88)
