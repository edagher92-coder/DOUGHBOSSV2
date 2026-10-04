import os, json, io
from PIL import Image, features
from concurrent.futures import ProcessPoolExecutor
SRC='final/'; OUT='web/'
ASSETS=[('w1','closed-box','W1-closed-box-4x5.png','hero4x5'),
 ('w2','stack-of-three','W2-stack-of-three-3x2.png','std'),
 ('w3','open-box','W3-open-box-4x5.png','hero4x5'),
 ('w4','seal-macro','W4-seal-macro-4x5.png','std'),
 ('w7','tile-cheese','W7-tile-cheese.png','tile'),
 ('w8','tile-zaatar','W8-tile-zaatar.png','tile'),
 ('w9','tile-meat','W9-tile-meat.png','tile'),
 ('w10','tile-spinach','W10-tile-spinach.png','tile'),
 ('w11','catering-hero','W11-catering-hero-16x9.png','std')]
STD=[2560,1920,1280,828,640]; TILE=[1280,828,640,400]
# jobs: (id, slug, crop_name, suffix, box or None, fixed_size or None, widths)
jobs=[]
for i,s,f,k in ASSETS:
    jobs.append(dict(id=i,slug=s,src=f,crop='original',suffix='',box=None,fixed=None,widths=TILE if k=='tile' else STD))
jobs.append(dict(id='w11',slug='catering-hero',src='W11-catering-hero-16x9.png',crop='9:16 phone (focus x70% y35%)',suffix='-9x16',box=(2080,0,3295,2160),fixed=None,widths=[1080,828,640]))
jobs.append(dict(id='w1',slug='closed-box',src='W1-closed-box-4x5.png',crop='1.91:1 OG 1200x630',suffix='-og',box=(0,985,2560,985+1344),fixed=(1200,630),widths=[1200]))
jobs.append(dict(id='w3',slug='open-box',src='W3-open-box-4x5.png',crop='1:1 square 1200x1200',suffix='-1x1',box=(0,12,2560,12+2560),fixed=(1200,1200),widths=[1200]))

def enc(im,path,fmt):
    if fmt=='jpg': im.save(path,'JPEG',quality=80,progressive=True,optimize=True,subsampling='4:2:0')
    elif fmt=='webp': im.save(path,'WEBP',quality=75,method=6)
    elif fmt=='avif': im.save(path,'AVIF',quality=55,speed=4)
    return os.path.getsize(path)

def run(j):
    im=Image.open(SRC+j['src']).convert('RGB')  # sources are untagged 8-bit RGB, treated as sRGB; no metadata carried
    if j['box']: im=im.crop(j['box'])
    W,H=im.size
    res={'id':j['id'],'slug':j['slug'],'crop':j['crop'],'source_px':[W,H],'widths':[],'files':{}}
    for w in j['widths']:
        if j['fixed']: tw,th=j['fixed']
        else:
            if w>W: continue
            tw=w; th=round(H*w/W)
        if tw>W: continue
        r=im.resize((tw,th),Image.LANCZOS) if (tw,th)!=(W,H) else im.copy()
        res['widths'].append(tw)
        for fmt in ('avif','webp','jpg'):
            name=f"{j['id']}-{j['slug']}{j['suffix']}-{tw}.{fmt}"
            b=enc(r,OUT+name,fmt)
            res['files'].setdefault(fmt,{})[str(tw)]={'file':name,'bytes':b,'height':th}
    return res
if __name__=='__main__':
    with ProcessPoolExecutor(4) as ex: results=list(ex.map(run,jobs))
    json.dump(results,open('work/results.json','w'),indent=1)
    print(sum(f['bytes'] for r in results for d in r['files'].values() for f in d.values()))
