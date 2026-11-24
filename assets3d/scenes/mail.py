"""Newsletter / success: a glass envelope with an iridescent flap, a glowing check sphere and an orbit ring."""
import math, bpy
from lib import *

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    glass = mat_glass('glass', (0.84, 0.92, 1.0)); iri = mat_iridescent('iri'); chrome = mat_chrome('chrome')
    studio()
    rot = (math.radians(8), 0, math.radians(-8))
    rbox((0, 0, -0.2), (5.0, 0.5, 3.2), glass, bevel=0.16, rot=rot)
    # flap: two slabs meeting in a V (rotated about Y, in the plane of the front face)
    for sg in (1, -1):
        rbox((sg * 1.15, -0.34, 0.45), (2.9, 0.14, 0.3), iri, bevel=0.06, rot=(0, sg * math.radians(32), 0))
    torus((0.2, 0, 0.1), 3.0, 0.14, mat_iridescent('ri', stops=[(0, (0.0, 0.1, 0.4, 1)), (0.5, (0.1, 0.7, 1.0, 1)), (1, (0.7, 1.0, 1.0, 1))]), rot=(math.radians(72), math.radians(6), math.radians(-16)))
    sphere((2.4, -0.9, 1.5), 0.85, mat_gloss('ok', (0.1, 0.75, 1.0), 0.05, 1.0))
    tube([(2.05, -1.55, 1.5), (2.3, -1.55, 1.25), (2.8, -1.55, 1.85)], 0.09, mat_emit('chk', (1, 1, 1), 6))
    sphere((-3.2, -0.4, -1.4), 0.26, mat_gloss('m', (0.9, 0.3, 0.95), 0.05)); sphere((3.3, 0.2, -1.3), 0.16, chrome)
    shadow_floor(-2.2)
    camera((0.3, -10.4, 0.8), (0.2, 0, 0.1), lens=55)
