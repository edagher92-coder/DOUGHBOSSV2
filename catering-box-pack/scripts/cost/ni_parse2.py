import re,sys
s=open(sys.argv[1]).read().replace('\\\\"','in').replace('\\"','"')
rows=set()
for chunk in s.split('{"name":')[1:]:
    chunk=chunk[:1200]
    if '"variantSku"' not in chunk[:300]: continue
    g=lambda k: (re.search(r'"%s":"?([^",}]*)'%re.escape(k),chunk) or [None,''])[1]
    rows.add((g('style'),g('type'),g('size'),g('material'),g('print option'),int(re.sub(r'\D','',g('quantity')) or 0),float(g('price') or 0),g('currencyCode')))
for r in sorted(rows):
    if r[5]: print(' | '.join(map(str,r)), '| unit=%.3f'%(r[6]/r[5]))
