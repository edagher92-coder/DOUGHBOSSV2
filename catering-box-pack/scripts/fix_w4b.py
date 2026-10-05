# R5b: close the diagonal lid-to-side edge on W4 (lid dust-flap crease over the rolled side-wall top).
# The vertical front-corner flute and the ~30 px lid-corner tip stay as they are (real cut edges).
import numpy as np
from PIL import Image, ImageDraw
from scipy.ndimage import gaussian_filter, map_coordinates
SRC='r2/W4-r5a.png'; OUT='final/W4-seal-macro-4x5.png'
a=np.asarray(Image.open(SRC)).astype(np.float32); Hh,Ww,_=a.shape
yy,xx=np.mgrid[0:Hh,0:Ww].astype(np.float32)
k,b=-0.893,2273.4                     # fitted flute centreline x = k*y + b
nx,ny=np.array([1.0,-k])/np.hypot(1,k) # unit normal toward the side face (right/down)
u=(xx-(k*yy+b))*nx                     # signed perpendicular distance, px
rng=np.random.default_rng(5)
jx=gaussian_filter(rng.normal(size=(Hh,Ww)),6)*14; jy=gaussian_filter(rng.normal(size=(Hh,Ww)),6)*14
def sample(off):
    # texture from 'off' px along the normal, with a sub-pixel jitter so it does not repeat exactly
    cy=yy+off*ny+jy; cx=xx+off*nx+jx
    return np.stack([map_coordinates(a[...,c],[cy,cx],order=1,mode='reflect') for c in range(3)],-1)
lid=sample(-58); side=sample(46)
w=np.clip((u+6)/12,0,1); w=w*w*(3-2*w)
fill=lid*(1-w[...,None])+side*w[...,None]
g=np.where(u<0,0.80-0.30*np.clip((u+30)/30,0,1),0.62+0.38*np.clip((u-14)/12,0,1))
g*=1-0.45*np.exp(-((u-13)/2.4)**2)          # contact shadow: lid fold sits on the side-wall top
fill=fill*g[...,None]
fill+= (np.exp(-((u+17)/1.6)**2)*15+np.exp(-((u+14)/6.0)**2)*5)[...,None]   # crest highlight
m=(np.abs(u)<27).astype(np.float32)
m=gaussian_filter(m,1.8)
m*=np.clip((925-yy)/12,0,1)                 # stop above the lid-corner tip and front-corner flute
out=a*(1-m[...,None])+fill*m[...,None]
# fold cracks along the crest
S=4; lay=Image.new('L',(Ww*S,Hh*S),0); d=ImageDraw.Draw(lay)
for y0,ln in ((880,20),(770,14),(690,24),(600,16)):
    x0=k*y0+b-17*nx; yc=y0-17*ny; pts=[]; x=x0; y=yc
    for i in range(6):
        pts.append((x*S,y*S)); y-=ln/5*0.75; x-=k*ln/5*0.75+rng.uniform(-0.6,0.6)
    d.line(pts,fill=255,width=int(1.2*S))
lay=np.asarray(lay.resize((Ww,Hh),Image.LANCZOS)).astype(np.float32)/255*0.5
out=out*(1-lay[...,None])+np.array([128,96,66],np.float32)*lay[...,None]
out=np.clip(out,0,255).astype(np.uint8)
Image.fromarray(out).save(OUT)
Image.fromarray(out).resize((Ww*2//3,Hh*2//3),Image.LANCZOS).save('final/W4-seal-macro-4x5-v.jpg',quality=88)
