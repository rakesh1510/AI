"""Solutions / use-cases: a chrome compass with glass face, iridescent needle and dial ticks."""
import math, bpy
from lib import *

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    chrome = mat_chrome('chrome'); glass = mat_glass('glass', (0.86, 0.92, 1.0)); iri = mat_iridescent('iri')
    face = mat_gloss('face', (0.02, 0.03, 0.16), 0.1, 1.0, 0.3); tick = mat_emit('tick', (0.55, 0.75, 1.0), 4)
    studio()
    rot = (math.radians(90), 0, 0)
    torus((0, 0, 0), 2.35, 0.3, chrome, rot=rot)
    bpy.ops.mesh.primitive_cylinder_add(radius=2.2, depth=0.2, location=(0, 0.05, 0), rotation=rot); f = bpy.context.active_object; smooth(f, bevel=0.03, subsurf=0); assign(f, face)
    for i in range(24):
        a = math.radians(i * 15); L = 0.22 if i % 6 == 0 else 0.1
        bpy.ops.mesh.primitive_cube_add(location=(1.85 * math.sin(a), -0.08, 1.85 * math.cos(a)), rotation=(0, -a, 0)); t = bpy.context.active_object; t.scale = (0.025, 0.02, L / 2); assign(t, tick)
    for ang, m in ((45, iri), (225, mat_chrome('n2'))):
        d = (math.sin(math.radians(ang)), 0, math.cos(math.radians(ang)))
        bpy.ops.mesh.primitive_cone_add(vertices=4, radius1=0.34, depth=1.8, location=(d[0] * 0.9, -0.14, d[2] * 0.9), rotation=(0, math.radians(ang), 0)); n = bpy.context.active_object
        smooth(n, bevel=0.04, subsurf=1); assign(n, m)
    sphere((0, -0.2, 0), 0.2, mat_chrome('pin'))
    bpy.ops.mesh.primitive_cylinder_add(radius=2.2, depth=0.06, location=(0, -0.32, 0), rotation=rot); g = bpy.context.active_object; assign(g, glass)
    sphere((2.9, 0.5, 2.0), 0.3, mat_gloss('c', (0.2, 0.8, 1.0), 0.05)); sphere((-2.8, 0.2, -1.8), 0.2, mat_gloss('m', (0.9, 0.3, 0.95), 0.05))
    shadow_floor(-3.0)
    camera((1.6, -10.2, 1.2), (0, 0, 0), lens=55)
