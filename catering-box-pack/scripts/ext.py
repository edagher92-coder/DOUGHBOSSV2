import re,html,sys
s=open(sys.argv[1],encoding='utf-8',errors='ignore').read()
s=re.sub(r'(?s)<script.*?</script>|<style.*?</style>|<noscript.*?</noscript>','',s)
t=html.unescape(re.sub(r'<[^>]+>','\n',s))
print('\n'.join(l.strip() for l in t.splitlines() if l.strip()))
