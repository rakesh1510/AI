"""Search: a glass magnifier with a chrome rim and handle, an iridescent sphere inside and an orbit ring."""
import math, bpy
from lib import *

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    chrome = mat_chrome('chrome'); glass = mat_glass('glass', (0.9, 0.95, 1.0), 0.01, 1.5); iri = mat_iridescent('iri')
    studio()
    rot = (math.radians(90), 0, 0)
    torus((0, 0, 0.4), 1.75, 0.26, chrome, rot=rot)
    bpy.ops.mesh.primitive_uv_sphere_add(radius=1.75, location=(0, 0, 0.4), segments=64, ring_count=32); l = bpy.context.active_object
    l.scale = (1, 0.18, 1); l.data.materials.append(glass)
    for pgn in l.data.polygons: pgn.use_smooth = True
    bpy.ops.mesh.primitive_cylinder_add(radius=0.28, depth=2.8, location=(1.75, 0, -1.6), rotation=(0, math.radians(35), 0)); h = bpy.context.active_object
    smooth(h, bevel=0.1, subsurf=1); assign(h, mat_gloss('h', (0.05, 0.05, 0.2), 0.12, 1.0, 0.4))
    sphere((-0.1, 0.8, 0.5), 0.62, iri)
    torus((0, 0, 0.4), 2.5, 0.07, mat_chrome('c2'), rot=(math.radians(100), math.radians(6), math.radians(20)))
    sphere((2.7, 0.6, 2.0), 0.28, mat_gloss('c', (0.2, 0.8, 1.0), 0.05)); sphere((-2.6, 0.4, -1.3), 0.2, mat_gloss('m', (0.9, 0.3, 0.95), 0.05))
    shadow_floor(-3.2)
    camera((0.2, -10.2, 0.8), (0.3, 0, -0.2), lens=55)
