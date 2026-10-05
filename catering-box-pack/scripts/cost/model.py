R=0.6933  # USD per AUD, RBA 2 Oct 2026
usd=lambda x: x/R
ex=lambda inc: inc/1.1
tiers=[250,500,1000,2500]
# Boxes: total ex GST incl. delivery for exactly q boxes
box={
 'A Easy Signs E-flute kraft 410x330x70 printed 1 side':{250:1372.50+17.27,500:2425+17.27,1000:4620+17.27,2500:11550+17.27},
 'A0 Easy Signs E-flute kraft 410x330x70 unprinted':{250:915+17.27,500:1615+17.27,1000:3080+17.27,2500:7700+17.27},
 'E Paperlust full-colour white 425x295x110':{250:ex(7972.50+10),500:ex(9345+10),1000:ex(13190+10)},
 'G noissue kraft 305x381x76 outside print (USD->AUD, excl. freight/import)':{250:usd(1745.70),500:usd(2751.375),1000:usd(5041.025)},
 'H ATpack BetaCater Ex Large 450x310x80 base+lid (GST basis not stated, treated as ex)':{250:5*96.50,500:10*96.50,1000:20*96.50,2500:50*96.50},
}
liner={
 'L1 ATpack plain greaseproof 400x330 (800/pk $18.50, GST basis not stated)':{250:18.50,500:18.50,1000:2*18.50,2500:4*18.50},
 'L2 Top Shelf printed 300x400 1-3 col (min 1000; GST basis not stated)':{250:705,500:705,1000:705,2500:840},
 'L3 noissue printed 380x380 1 col (USD->AUD)':{250:usd(158.76),500:usd(173.88),1000:usd(230.04),2500:usd(356.40+173.88)},
}
seal={
 'S1 Suprpack kraft 60x60 (inc GST, free ship)':{250:ex(99),500:ex(129),1000:ex(169),2500:ex(249+129)},
 'S2 Gift Packaging kraft 60mm circle (lots of 12)':{250:ex(252*0.35),500:ex(504*0.26),1000:ex(1008*0.22),2500:ex(2508*0.20)},
}
def show(d):
    for k,v in d.items():
        print(k); print('   ', '  '.join(f'{q}: total ${v[q]:,.2f} = ${v[q]/q:.3f}/box' for q in tiers if q in v))
for d in (box,liner,seal): show(d); print()
# scenarios
sc={
 'Pilot-plain (H + L1 + S1)':('H ATpack BetaCater Ex Large 450x310x80 base+lid (GST basis not stated, treated as ex)','L1 ATpack plain greaseproof 400x330 (800/pk $18.50, GST basis not stated)','S1 Suprpack kraft 60x60 (inc GST, free ship)'),
 'Plain-mailer (A0 + L1 + S1)':('A0 Easy Signs E-flute kraft 410x330x70 unprinted','L1 ATpack plain greaseproof 400x330 (800/pk $18.50, GST basis not stated)','S1 Suprpack kraft 60x60 (inc GST, free ship)'),
 'Printed-core (A + L1 + S1)':('A Easy Signs E-flute kraft 410x330x70 printed 1 side','L1 ATpack plain greaseproof 400x330 (800/pk $18.50, GST basis not stated)','S1 Suprpack kraft 60x60 (inc GST, free ship)'),
 'Printed-full AU (A + L2 + S1)':('A Easy Signs E-flute kraft 410x330x70 printed 1 side','L2 Top Shelf printed 300x400 1-3 col (min 1000; GST basis not stated)','S1 Suprpack kraft 60x60 (inc GST, free ship)'),
 'Printed-full mixed (A + L3 + S1)':('A Easy Signs E-flute kraft 410x330x70 printed 1 side','L3 noissue printed 380x380 1 col (USD->AUD)','S1 Suprpack kraft 60x60 (inc GST, free ship)'),
 'Premium white (E + L2 + S1)':('E Paperlust full-colour white 425x295x110','L2 Top Shelf printed 300x400 1-3 col (min 1000; GST basis not stated)','S1 Suprpack kraft 60x60 (inc GST, free ship)'),
}
alld={**box,**liner,**seal}
print('SCENARIOS (ex GST, AUD, per box-set = 1 box + 1 liner + 1 seal; outlay = cash for the tier)')
for name,(b,l,s) in sc.items():
    row=[]
    for q in tiers:
        if all(q in alld[x] for x in (b,l,s)):
            tot=sum(alld[x][q] for x in (b,l,s)); row.append(f'{q}: ${tot/q:.2f}/set (outlay ${tot:,.2f}) [2dz ${2*tot/q:.2f} | 3dz ${3*tot/q:.2f} | 5dz ${5*tot/q:.2f}]')
        else: row.append(f'{q}: n/a')
    print(name); [print('   ',r) for r in row]
