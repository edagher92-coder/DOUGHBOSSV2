import re,json,sys
s=open(sys.argv[1]).read().replace('\\"','"').replace('\\\\"','"')
rows=set()
for m in re.finditer(r'\{"name":"([^"]*)","variantSku":"([^"]*)","productId":"\d+","variantId":"\d+","price":([\d.]+),"salePrice":([^,]*),"currencyCode":"(\w+)".*?"quantity":"(\d+)"(.*?)\}',s):
    extra=m.group(7)
    attrs=dict(re.findall(r'"([^"]+)":"([^"]*)"',extra))
    rows.add((m.group(1),attrs.get('size',''),attrs.get('type',''),attrs.get('colors',''),attrs.get('thickness (gsm)',''),int(m.group(6)),float(m.group(3)),m.group(4),m.group(5)))
for r in sorted(rows): print(r, ' unit=%.4f'%(r[6]/r[5]))
