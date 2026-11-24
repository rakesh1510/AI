"""Guided steps: ascending glossy blocks with a chrome sphere on the top step."""
import math, bpy
from lib import *

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    iri = mat_iridescent('iri'); glass = mat_glass('glass', (0.84, 0.92, 1.0)); chrome = mat_chrome('chrome')
    studio()
    for i in range(4):
        h = 0.9 + i * 0.75
        rbox((-3.0 + i * 2.0, 0, -1.2 + h / 2), (1.7, 2.0, h), iri if i % 2 == 0 else glass, bevel=0.13)
    sphere((3.0, 0, 2.15), 0.62, mat_gloss('mg', (0.9, 0.3, 0.95), 0.05)); torus((3.0, 0, 2.15), 1.0, 0.05, chrome, rot=(math.radians(72), 0, math.radians(14)))
    sphere((-3.3, 0.5, 1.3), 0.2, mat_gloss('c', (0.2, 0.8, 1.0), 0.05)); sphere((0.4, 0.3, 3.0), 0.14, chrome)
    shadow_floor(-1.25)
    camera((-0.5, -10.5, 2.2), (0, 0, 0.7), lens=52)
