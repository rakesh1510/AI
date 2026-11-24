"""Services: a chrome gear driving glass nodes joined by glowing tubes around an iridescent core."""
import math, bpy
from lib import *

def gear(loc, R, teeth, mat, rot):
    bpy.ops.mesh.primitive_cylinder_add(radius=R, depth=0.5, location=loc, rotation=rot, vertices=96); g = bpy.context.active_object
    smooth(g, bevel=0.05, subsurf=1); assign(g, mat)
    for i in range(teeth):
        a = 2 * math.pi * i / teeth
        bpy.ops.mesh.primitive_cube_add(location=(loc[0] + (R + 0.12) * math.cos(a), loc[1], loc[2] + (R + 0.12) * math.sin(a)), rotation=(0, -a, 0)); t = bpy.context.active_object
        t.scale = (0.2, 0.25, 0.14); smooth(t, bevel=0.03, subsurf=1); assign(t, mat)
    return g

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    chrome = mat_chrome('chrome'); iri = mat_iridescent('iri'); glass = mat_glass('glass', (0.85, 0.92, 1.0))
    link = mat_emit('link', (0.3, 0.8, 1.0), 4)
    studio()
    gear((-1.0, 0, 0.3), 1.7, 14, chrome, (math.radians(90), 0, 0))
    bpy.ops.mesh.primitive_cylinder_add(radius=0.8, depth=0.7, location=(-1.0, -0.25, 0.3), rotation=(math.radians(90), 0, 0), vertices=64); c = bpy.context.active_object; smooth(c, bevel=0.06, subsurf=1); assign(c, iri)
    gear((2.35, 0, -1.2), 1.0, 9, mat_chrome('g2', (0.7, 0.8, 1.0), 0.08), (math.radians(90), 0, math.radians(10)))
    for (x, z, col) in ((3.3, 2.0, (0.2, 0.8, 1.0)), (-3.6, 1.6, (0.9, 0.3, 0.95)), (-3.2, -2.0, (0.4, 0.3, 1.0))):
        sphere((x, 0.2, z), 0.62, glass); sphere((x, 0.2, z), 0.28, mat_gloss('n', col, 0.05))
        tube([(-1.0, 0, 0.3), ((x - 1.0) / 2, 0.1, (z + 0.3) / 2), (x, 0.2, z)], 0.045, link)
    torus((0, 0.3, 0.1), 4.0, 0.09, mat_iridescent('r2'), rot=(math.radians(78), 0, math.radians(-12)))
    camera((0.3, -11.2, 0.8), (0, 0, 0.1), lens=55, pull=1.6)
