"""Guides / blog: an open glass book with glowing text lines, floating pages, a ribbon ring and lit spheres."""
import math, bpy
from lib import *

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    glass = mat_glass('glass', (0.84, 0.92, 1.0)); iri = mat_iridescent('iri'); chrome = mat_chrome('chrome')
    line = mat_emit('line', (0.55, 0.72, 1.0), 4.5); hot = mat_emit('hot', (0.85, 0.45, 1.0), 5)
    studio()
    for sg in (1, -1):
        rbox((sg * 1.55, 0, -0.5), (3.0, 0.22, 3.6), glass, bevel=0.1, rot=(math.radians(28), sg * math.radians(10), math.radians(sg * -4)))
        rbox((sg * 1.55, 0.05, -0.62), (3.18, 0.1, 3.78), iri, bevel=0.06, rot=(math.radians(28), sg * math.radians(10), math.radians(sg * -4)))
        for i, w in enumerate((2.1, 2.3, 1.8, 2.2)):
            rbox((sg * 1.5 - sg * (2.3 - w) * 0.25, -0.2, -0.05 + 0.45 - i * 0.42 - 0.0), (w, 0.05, 0.13), line if sg > 0 else hot, bevel=0.03, rot=(math.radians(28), sg * math.radians(10), math.radians(sg * -4)))
    rbox((0, 0.3, -0.9), (0.28, 0.28, 3.5), chrome, bevel=0.08, rot=(math.radians(62), 0, 0))
    rbox((-0.7, -1.2, 2.3), (1.5, 0.1, 1.9), glass, bevel=0.08, rot=(math.radians(20), math.radians(-18), math.radians(-24)))
    rbox((1.2, -1.4, 2.7), (1.3, 0.1, 1.7), glass, bevel=0.08, rot=(math.radians(-10), math.radians(22), math.radians(16)))
    torus((0, -0.2, 0.3), 3.6, 0.1, mat_iridescent('r2'), rot=(math.radians(76), 0, math.radians(-18)))
    sphere((3.3, 0.2, 2.2), 0.4, mat_gloss('c', (0.2, 0.8, 1.0), 0.05)); sphere((-3.4, 0.2, -1.2), 0.28, mat_gloss('m', (0.9, 0.3, 0.95), 0.05)); sphere((0.4, 0, 3.4), 0.2, chrome)
    camera((0.2, -11.0, 3.4), (0, 0, 0), lens=55, pull=1.4)
