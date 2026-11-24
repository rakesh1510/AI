"""Article covers: four abstract glossy compositions (rendered as a 2x2 contact of separate objects, cropped in post)."""
import math, bpy, sys, os
from lib import *

VARIANT = 0
def build(samples=None, scale=None):
    reset(1360, 840, samples, scale)
    chrome = mat_chrome('chrome'); glass = mat_glass('glass', (0.86, 0.92, 1.0)); iri = mat_iridescent('iri')
    mag = mat_iridescent('mag', stops=[(0.0, (0.12, 0.01, 0.2, 1)), (0.4, (0.65, 0.08, 0.8, 1)), (0.75, (0.95, 0.3, 0.9, 1)), (1.0, (0.45, 0.35, 1.0, 1))])
    studio()
    v = int(os.environ.get('THUMB', '0'))
    if v == 0:
        rbox((-0.8, 0, 0), (2.6, 0.9, 2.6), iri, bevel=0.35, rot=(math.radians(8), math.radians(18), math.radians(-14))); sphere((1.4, -0.4, 0.2), 1.25, mag); sphere((2.3, 0.2, 1.5), 0.3, chrome)
    elif v == 1:
        torus_knot(2, 3, 1.1, 0.42, 0.24, iri, n=700, rot=(math.radians(64), 0, math.radians(-10)), scale=1.0); sphere((2.0, -0.3, 1.2), 0.4, glass)
    elif v == 2:
        rbox((0, 0, -0.4), (3.6, 3.6, 0.3), glass, bevel=0.1, rot=(0, 0, math.radians(30))); rbox((0, 0, 0.3), (3.0, 3.0, 0.3), iri, bevel=0.1, rot=(0, 0, math.radians(30))); sphere((0.2, 0, 1.4), 0.8, chrome)
    else:
        torus((0, 0, 0), 1.5, 0.45, mag, rot=(math.radians(66), math.radians(10), math.radians(-12))); sphere((0, 0, 0), 0.7, glass); sphere((2.0, -0.3, 1.2), 0.3, mat_gloss('c', (0.2, 0.8, 1.0), 0.05))
    shadow_floor(-1.7)
    camera((0.2, -8.0, 1.0), (0.1, 0, 0.1), lens=55)
