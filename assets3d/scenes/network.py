"""Partners: a chrome hub with glass satellite nodes joined by glowing tubes and an iridescent orbit."""
import math, bpy
from lib import *

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    chrome = mat_chrome('chrome'); iri = mat_iridescent('iri'); glass = mat_glass('glass', (0.85, 0.92, 1.0))
    link = mat_emit('link', (0.3, 0.8, 1.0), 4)
    studio()
    sphere((0, 0, 0), 1.0, chrome); sphere((0, -0.2, 0), 0.62, mat_iridescent('core'))
    cols = [mat_gloss('a', (0.2, 0.8, 1.0), 0.06), mat_gloss('b', (0.9, 0.3, 0.95), 0.06), mat_gloss('c', (0.4, 0.3, 1.0), 0.06)]
    for i in range(6):
        a = math.radians(i * 60 + 18); x, z = 3.3 * math.cos(a), 2.1 * math.sin(a); y = 0.5 * math.sin(i * 1.7)
        sphere((x, y, z), 0.62, glass); sphere((x, y, z), 0.28, cols[i % 3])
        tube([(0, 0, 0), (x * .5, y * .5 - 0.1, z * .5), (x * 0.78, y * .78, z * 0.78)], 0.045, link)
    torus((0, 0, 0), 3.9, 0.1, iri, rot=(math.radians(77), 0, math.radians(-14)))
    shadow_floor(-3.0)
    camera((0.4, -11.0, 1.2), (0, 0, 0), lens=55)
