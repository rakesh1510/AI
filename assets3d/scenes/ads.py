"""Advertise: three floating glass placement cards (the middle one framed in iridescent metal), chrome coins and lit spheres."""
import math, bpy
from lib import *

def card(loc, rot, mat, lines, accent):
    c = rbox(loc, (2.5, 0.16, 3.2), mat, bevel=0.14, rot=rot)
    for i, w in enumerate(lines):
        rbox((loc[0] + (w - 1.7) / 2 * 0 - (1.7 - w) / 2, loc[1] - 0.14, loc[2] + 0.55 - i * 0.42), (w, 0.05, 0.16), accent, bevel=0.03, rot=rot)
    return c

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    glass = mat_glass('glass', (0.84, 0.92, 1.0)); iri = mat_iridescent('iri'); chrome = mat_chrome('chrome')
    gold = mat_chrome('gold', (1.0, 0.74, 0.28), 0.1)
    line = mat_emit('line', (0.55, 0.72, 1.0), 4); hot = mat_emit('hot', (0.8, 0.55, 1.0), 5)
    studio()
    card((-2.6, 0.5, -0.3), (0, math.radians(-8), math.radians(-12)), glass, (1.5, 1.9, 1.1), line)
    card((2.7, 0.7, -0.45), (0, math.radians(8), math.radians(10)), glass, (1.5, 1.9, 1.1), line)
    rbox((0, 0.15, 0.1), (3.1, 0.3, 3.9), iri, bevel=0.18)
    card((0, -0.12, 0.1), (0, 0, 0), mat_glass('g2', (0.9, 0.9, 1.0)), (1.9, 1.4, 1.7), hot)
    sphere((0, -0.3, 1.9), 0.42, gold)
    for i, (x, z) in enumerate(((-3.8, -2.0), (3.9, -2.2), (-0.2, -2.6))):
        bpy.ops.mesh.primitive_cylinder_add(radius=0.5, depth=0.14, location=(x, -0.4, z), rotation=(math.radians(78), 0, math.radians(14 * (i - 1)))); co = bpy.context.active_object
        smooth(co, bevel=0.05, subsurf=1); assign(co, gold if i != 1 else chrome)
    sphere((3.6, 0.2, 2.2), 0.34, mat_gloss('c', (0.2, 0.8, 1.0), 0.05)); sphere((-3.5, 0.2, 2.0), 0.24, mat_gloss('m', (0.9, 0.3, 0.95), 0.05))
    camera((0.4, -11.0, 1.0), (0, 0, -0.2), lens=55, pull=1.45)
