"""Legal / policy: stacked glass pages with glowing text lines and a lit sphere."""
import math, bpy
from lib import *

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    glass = mat_glass('glass', (0.86, 0.92, 1.0)); iri = mat_iridescent('iri'); chrome = mat_chrome('chrome')
    line = mat_emit('line', (0.55, 0.72, 1.0), 5)
    studio()
    for i, (x, rz, mat) in enumerate(((-0.5, 12, glass), (0.2, -4, mat_glass('g2', (0.9, 0.88, 1.0))), (0.9, 8, glass))):
        rbox((x, i * 0.55, 0), (3.4, 0.16, 4.4), mat, bevel=0.1, rot=(0, math.radians(rz), math.radians(-rz * 0.3)))
    for i, w in enumerate((2.4, 2.8, 2.4, 1.6, 2.6)):
        rbox((0.9 - (2.8 - w) / 2 * 0.0 - (2.8 - w) / 2, 1.0, 1.4 - i * 0.65), (w, 0.07, 0.17), line, bevel=0.03, rot=(0, math.radians(8), math.radians(-2)))
    sphere((2.9, 0.4, 1.7), 0.7, mat_gloss('c', (0.15, 0.75, 1.0), 0.05)); sphere((-2.8, 0.4, -1.8), 0.3, mat_gloss('m', (0.9, 0.3, 0.95), 0.05)); sphere((3.0, 0, -1.7), 0.18, chrome)
    torus((0.4, 0.5, 0), 3.5, 0.08, iri, rot=(math.radians(80), math.radians(4), math.radians(-26)))
    shadow_floor(-2.5)
    camera((0.3, -10.2, 0.6), (0.2, 0.3, 0), lens=55)
