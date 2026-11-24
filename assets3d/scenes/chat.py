"""Contact: two glass speech bubbles with glowing text lines, an iridescent ring and a chrome sphere."""
import math, bpy
from lib import *

def bubble(loc, size, mat, tail_x):
    b = rbox(loc, size, mat, bevel=0.28)
    bpy.ops.mesh.primitive_cone_add(vertices=4, radius1=0.38, depth=0.55, location=(loc[0] + tail_x, loc[1], loc[2] - size[2] / 2 - 0.22), rotation=(math.radians(180), 0, math.radians(45)))
    t = bpy.context.active_object; smooth(t, bevel=0.05, subsurf=1); assign(t, mat); return b

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    glass = mat_glass('glass', (0.82, 0.9, 1.0)); chrome = mat_chrome('chrome'); iri = mat_iridescent('iri')
    line = mat_emit('line', (0.55, 0.7, 1.0), 6); lc = mat_emit('lc', (0.9, 0.4, 1.0), 6)
    studio()
    bubble((-0.9, 0.2, 1.1), (3.6, 0.5, 2.0), glass, -1.0)
    for i, w in enumerate((2.3, 2.7, 1.5)): rbox((-1.15 + (w - 2.7) / 2, -0.3, 1.65 - i * 0.5), (w, 0.08, 0.2), line, bevel=0.03)
    bubble((1.2, -0.4, -0.9), (3.4, 0.5, 1.8), mat_glass('g2', (0.9, 0.85, 1.0)), 1.0)
    for i, c in enumerate((line, lc, line)): sphere((0.55 + i * 0.7, -0.78, -0.9), 0.17, c)
    torus((0.2, 0.2, 0.1), 3.1, 0.2, iri, rot=(math.radians(74), math.radians(6), math.radians(-18)))
    sphere((2.7, 0.4, 2.0), 0.62, chrome); sphere((-2.9, -0.2, -1.8), 0.3, mat_gloss('mg', (0.9, 0.3, 0.95), 0.06)); sphere((3.3, -0.2, -0.2), 0.18, mat_gloss('cy', (0.2, 0.8, 1.0), 0.06))
    shadow_floor(-2.7)
    camera((0.6, -10.5, 0.8), (0.2, 0, 0.1), lens=55)
