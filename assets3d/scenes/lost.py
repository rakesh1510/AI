"""404: a lit sphere with a question mark inside broken orbit rings."""
import math, bpy
from lib import *

def arc(r, a0, a1, tilt, mat, tube_r=0.2, z=0):
    pts = []
    n = 60
    for i in range(n + 1):
        a = math.radians(a0 + (a1 - a0) * i / n); pts.append((r * math.cos(a), r * math.sin(a), 0))
    ob = tube(pts, tube_r, mat, closed=False); ob.rotation_euler = tilt; ob.location = (0, 0, z); return ob

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    iri = mat_iridescent('iri'); chrome = mat_chrome('chrome'); glass = mat_glass('glass', (0.86, 0.92, 1.0))
    studio()
    sphere((0, 0, 0), 1.55, glass); sphere((0, 0, 0), 1.0, iri)
    cu = bpy.data.curves.new('q', 'FONT'); cu.body = '?'; cu.size = 1.6; cu.extrude = 0.1; cu.bevel_depth = 0.04; cu.align_x = 'CENTER'; cu.align_y = 'CENTER'
    q = bpy.data.objects.new('q', cu); bpy.context.collection.objects.link(q); q.location = (0, -1.65, 0); q.rotation_euler = (math.radians(90), 0, 0); q.data.materials.append(mat_chrome('q', (0.95, 0.97, 1.0), 0.06))
    tilt = (math.radians(70), math.radians(6), math.radians(-14))
    arc(3.1, 20, 120, tilt, iri, 0.2); arc(3.1, 160, 320, tilt, mat_iridescent('i2', stops=[(0, (0.0, 0.1, 0.4, 1)), (0.5, (0.1, 0.7, 1.0, 1)), (1, (0.7, 1.0, 1.0, 1))]), 0.2)
    sphere((2.8, 0.6, 2.2), 0.4, mat_gloss('c', (0.2, 0.8, 1.0), 0.05)); sphere((-3.0, 0.3, -1.6), 0.26, mat_gloss('m', (0.9, 0.3, 0.95), 0.05)); sphere((3.3, -0.2, -1.4), 0.15, chrome)
    shadow_floor(-2.6)
    camera((0.3, -10.4, 0.7), (0, 0, 0), lens=55)
