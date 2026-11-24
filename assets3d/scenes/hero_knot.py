"""Home hero: an iridescent torus-knot ribbon around a chrome ring, glass sphere and satellites."""
import math, bpy
from lib import *

def build(samples=None, scale=None):
    reset(1500, 1300, samples, scale)
    iri = mat_iridescent('iri')
    chrome = mat_chrome('chrome', (0.86, 0.9, 1.0), 0.04)
    glass = mat_glass('glass', (0.9, 0.95, 1.0))
    mag = mat_iridescent('mag', stops=[(0.0, (0.12, 0.01, 0.2, 1)), (0.4, (0.65, 0.08, 0.8, 1)), (0.75, (0.95, 0.3, 0.9, 1)), (1.0, (0.45, 0.35, 1.0, 1))], rough=0.08)
    studio()
    torus_knot(2, 3, 1.55, 0.62, 0.34, iri, n=900, rot=(math.radians(62), math.radians(8), math.radians(-18)))
    torus((0, 0, 0), 1.15, 0.12, chrome, rot=(math.radians(78), math.radians(10), math.radians(32)))
    sphere((2.3, 0.2, 1.7), 0.42, glass)
    sphere((-2.2, -0.6, -1.0), 0.26, mat_gloss('mg', (0.9, 0.25, 0.95), 0.08))
    sphere((2.0, -0.4, -1.5), 0.16, chrome)
    sphere((-1.9, 0.3, 1.9), 0.12, mat_gloss('cy', (0.2, 0.8, 1.0), 0.08))
    shadow_floor(-2.35)
    camera((0.0, -9.5, 0.9), (0, 0, 0.1), lens=62, pull=1.0)
