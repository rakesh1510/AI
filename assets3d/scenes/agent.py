"""Business hero: a glossy AI agent robot on a neon-edged glass platform with a glass gate and floating chips."""
import math, bpy
from lib import *

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    chrome = mat_chrome('chrome', (0.88, 0.9, 1.0), 0.05)
    white = mat_gloss('shell', (0.92, 0.94, 1.0), 0.10, 1.0)
    visor = mat_gloss('visor', (0.008, 0.01, 0.04), 0.03, 1.0)
    iri = mat_iridescent('iri')
    glass = mat_glass('glass', (0.85, 0.95, 1.0))
    eye = mat_emit('eye', (1.0, 0.05, 0.55), 3.2)
    cyan = mat_emit('cyan', (0.1, 0.85, 1.0), 14)
    dark = mat_gloss('plat', (0.03, 0.035, 0.12), 0.2, 1.0, 0.3)
    studio()
    # platform + neon rim
    rbox((0, 0, -1.55), (6.4, 6.4, 0.5), dark, bevel=0.12)
    for sx, sy, w, d in [(0, 3.2, 6.5, 0.07), (0, -3.2, 6.5, 0.07), (3.2, 0, 0.07, 6.5), (-3.2, 0, 0.07, 6.5)]:
        bpy.ops.mesh.primitive_cube_add(location=(sx, sy, -1.29)); o = bpy.context.active_object; o.scale = (w / 2, d / 2, 0.035); assign(o, cyan)
    # body (iridescent block with chrome belt) and head (glossy shell, dark visor, glowing eyes)
    rbox((0, 0, -0.55), (1.7, 1.5, 1.35), iri, bevel=0.22)
    rbox((0, 0, -0.05), (1.82, 1.62, 0.12), chrome, bevel=0.05)
    rbox((0, 0, 0.9), (2.5, 1.95, 1.55), white, bevel=0.5)
    rbox((0, -0.86, 0.92), (1.95, 0.2, 1.05), visor, bevel=0.3)
    for ex in (-0.5, 0.5):
        rbox((ex, -0.97, 0.95), (0.28, 0.06, 0.5), eye, bevel=0.1)
    for ex in (-1.32, 1.32):
        rbox((ex, 0, 0.9), (0.2, 0.7, 0.75), iri, bevel=0.08)
    bpy.ops.mesh.primitive_cylinder_add(radius=0.05, depth=0.55, location=(0, 0, 1.98)); a = bpy.context.active_object; assign(a, chrome)
    sphere((0, 0, 2.3), 0.14, mat_gloss('mg', (1.0, 0.3, 0.9), 0.08))
    # glass gate and floating chips
    rbox((-2.5, -1.2, -0.2), (0.12, 2.2, 1.9), glass, bevel=0.04, rot=(0, 0, math.radians(18)))
    chip = rbox((2.5, -0.4, 1.5), (0.95, 0.95, 0.2), glass, bevel=0.08, rot=(math.radians(70), math.radians(8), math.radians(-20)))
    tube([(2.2, -0.62, 1.52), (2.4, -0.62, 1.30), (2.82, -0.62, 1.8)], 0.06, mat_emit('ok', (0.3, 1.0, 0.8), 10))
    rbox((-2.4, 0.3, 1.9), (0.8, 0.8, 0.18), glass, bevel=0.08, rot=(math.radians(70), math.radians(-6), math.radians(22)))
    sphere((2.9, 1.4, 0.1), 0.24, mat_gloss('cs', (0.1, 0.7, 1.0), 0.06))
    sphere((-2.6, 1.8, -0.3), 0.15, chrome)
    sphere((1.6, 2.3, 2.3), 0.12, mat_gloss('ms', (0.9, 0.3, 1.0), 0.06))
    shadow_floor(-1.8)
    camera((7.6, -11.2, 4.3), (0, 0, 0.15), lens=52, pull=1.12)
