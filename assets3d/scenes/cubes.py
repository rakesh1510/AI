"""Categories: a field of rounded blocks in glass, iridescent, chrome and candy colours at different heights."""
import math, bpy
from lib import *

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    glass = mat_glass('glass', (0.84, 0.92, 1.0)); iri = mat_iridescent('iri'); chrome = mat_chrome('chrome')
    cols = [mat_gloss('a', (0.1, 0.75, 1.0), 0.08), mat_gloss('b', (0.9, 0.25, 0.9), 0.08), mat_gloss('c', (0.98, 0.6, 0.15), 0.08), mat_gloss('d', (0.2, 0.85, 0.5), 0.08)]
    mats = [iri, glass, chrome, cols[0], glass, cols[1], iri, cols[2], glass, cols[3], chrome, iri]
    studio()
    k = 0
    for ix in range(4):
        for iy in range(3):
            h = 0.9 + ((ix * 3 + iy * 5) % 4) * 0.45
            x = (ix - 1.5) * 1.75; y = (iy - 1) * 1.75
            rbox((x, y, -1.0 + h / 2), (1.45, 1.45, h), mats[k % len(mats)], bevel=0.18, rot=(0, 0, math.radians(0))); k += 1
    sphere((0.4, -0.2, 2.5), 0.5, mat_chrome('s')); sphere((-3.4, 1.6, 1.4), 0.25, mat_gloss('m', (0.9, 0.3, 0.95), 0.05))
    camera((5.0, -8.5, 5.5), (0, 0, 0.2), lens=50, pull=1.35)
