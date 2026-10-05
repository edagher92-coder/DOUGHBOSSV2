import build_pdf as B, art
notes = ['Style: one-piece corrugated mailer, FEFCO 0427 type, hinged lid, tuck front, side vents. E-flute, black kraft outer / natural kraft inner [CONFIRM board].',
         'Internal 385 x 290 x 50 mm (12 bakes, 4 x 3, about 9 cm each) [CONFIRM by fit test with a plain die-cut sample before plates].',
         'Inks: WHITE OPAQUE (single hit) + EMBER (over white underlay, choked 0.3 mm). No flood: the board is the background. Dieline = spot "Dieline", do not print.',
         'Safe: type 8 mm from creases and cuts, key brand 12 mm, 10 mm from vents, 7 mm from rolled edges. Seal keep-out 80 mm circle at x = 322 on the front edge.',
         'Status: CONCEPT FOR DEVELOPMENT, 3 Oct 2026. Not for press until structure sample, board and Pantone drawdown are approved.']
B.build(B.D + '/out/DoughBoss-DozenBox-Dieline-v1.pdf',
        outside={'lid': art.lid_final, 'front': art.front_final, 'back': art.back_final,
                 'side_left': art.side_final, 'side_right': art.side_final},
        inside={'lid': art.inside_lid}, notes=notes)
print('ok')
