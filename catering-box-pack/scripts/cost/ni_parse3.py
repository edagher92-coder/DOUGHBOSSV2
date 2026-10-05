import re,sys
s=open(sys.argv[1]).read().replace('\\\\"','in').replace('\\"','"')
flt=sys.argv[2] if len(sys.argv)>2 else ''
rows=set()
skip={'variantSku','productId','variantId','price','salePrice','currencyCode','modifiersId','metadataModifierId','optionsValues','quantity','name'}
for chunk in s.split('{"name":')[1:]:
    end=chunk.find('}')
    c='{"name":'+chunk[:end+1]
    if '"variantSku"' not in c: continue
    kv=dict(re.findall(r'"([^"]+)":"?([^",}]*)"?',c))
    attrs=' ; '.join(f'{k}={v}' for k,v in kv.items() if k not in skip)
    q=int(re.sub(r'\D','',kv.get('quantity','0')) or 0)
    if q: rows.add((attrs,q,float(kv.get('price',0)),kv.get('currencyCode')))
for r in sorted(rows):
    if flt in r[0]: print(r[0],'|',r[1],'|',r[2],r[3],'| unit=%.4f'%(r[2]/r[1]))
