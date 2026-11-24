"""Method / pricing: stacked glass slabs with an iridescent core layer and a sphere on top."""
import math, bpy
from lib import *

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    glass = mat_glass('glass', (0.84, 0.92, 1.0)); iri = mat_iridescent('iri'); chrome = mat_chrome('chrome')
    studio()
    rot = (0, 0, math.radians(32))
    rbox((0, 0, -1.2), (4.4, 4.4, 0.28), iri, bevel=0.1, rot=rot)
    rbox((0, 0, -0.45), (4.4, 4.4, 0.28), glass, bevel=0.1, rot=rot)
    rbox((0, 0, 0.3), (4.4, 4.4, 0.28), mat_iridescent('i2', stops=[(0, (0.0, 0.05, 0.25, 1)), (0.5, (0.05, 0.6, 0.95, 1)), (1, (0.5, 0.95, 1.0, 1))]), bevel=0.1, rot=rot)
    rbox((0, 0, 1.05), (4.4, 4.4, 0.28), glass, bevel=0.1, rot=rot)
    sphere((0.3, 0.1, 1.85), 0.62, chrome); sphere((2.7, -0.6, 1.9), 0.2, mat_gloss('c', (0.2, 0.8, 1.0), 0.05)); sphere((-2.8, 0.4, 0.2), 0.16, mat_gloss('m', (0.9, 0.3, 0.95), 0.05))
    shadow_floor(-1.55)
    camera((5.0, -9.2, 4.4), (0, 0, 0.2), lens=52)
