"""Spot colours (reportlab CMYKColorSep) for the Dough Boss dozen box production files, dieline v2.
Names are the exact separation names the converter will see. The alternate CMYK values are SCREEN SIMULATION ONLY
(what a viewer paints for a 100 % tint); they never print. Ember alternate approximates PANTONE 485 C [CONFIRM against a
Pantone drawdown]."""
from reportlab.lib.colors import CMYKColorSep

WHITE = CMYKColorSep(0, 0.02, 0.06, 0.07, spotName='WHITE OPAQUE', density=1)       # cream-white screen simulation
EMBER = CMYKColorSep(0, 0.95, 1, 0, spotName='EMBER PMS 485 C', density=1)
DIE_CUT = CMYKColorSep(0, 1, 0, 0, spotName='DIE CUT', density=1)
DIE_CREASE = CMYKColorSep(1, 0, 0, 0, spotName='DIE CREASE', density=1)
DIMS = CMYKColorSep(0, 0, 0, 0.9, spotName='DIMENSIONS', density=1)
INSIDE = CMYKColorSep(0.2, 0.2, 0.3, 0.9, spotName='INSIDE DARK', density=1)        # placeholder: inside colour undecided [CONFIRM]
PREV_BLACK = CMYKColorSep(0.65, 0.6, 0.6, 0.9, spotName='PREVIEW BLACK KRAFT', density=1)      # non-printing board preview (outside)
PREV_KRAFT = CMYKColorSep(0.20, 0.42, 0.62, 0.05, spotName='PREVIEW NATURAL KRAFT', density=1)  # non-printing board preview (inside)
REG = CMYKColorSep(1, 1, 1, 1, spotName='All', density=1)                           # registration: every plate

# Overprint policy per separation.
#   DIE CUT / DIE CREASE / DIMENSIONS / All : overprint ON (they must never knock out artwork).
#   EMBER : overprint ON. EMBER prints over its WHITE underlay; if it knocked out, the white plate would lose the underlay.
#   WHITE, INSIDE DARK : knockout (nothing is printed beneath them; WHITE is painted first).
OVERPRINT = {WHITE.spotName: False, EMBER.spotName: True, DIE_CUT.spotName: True, DIE_CREASE.spotName: True,
             DIMS.spotName: True, INSIDE.spotName: False, REG.spotName: True,
             PREV_BLACK.spotName: False, PREV_KRAFT.spotName: False}

ALL_SPOTS = [WHITE, EMBER, DIE_CUT, DIE_CREASE, DIMS, INSIDE, PREV_BLACK, PREV_KRAFT, REG]


def setf(c, col):
    """Set the fill colour and its overprint state together (explicit every time, so no state can leak)."""
    c.setFillColor(col)
    c.setFillOverprint(OVERPRINT[col.spotName])


def sets(c, col):
    c.setStrokeColor(col)
    c.setStrokeOverprint(OVERPRINT[col.spotName])
