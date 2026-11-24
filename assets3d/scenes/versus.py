"""Compare: a glass cube with a cyan core faces an iridescent cube with a magenta core, a chrome VS between them."""
import math, bpy
from lib import *

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    glass = mat_glass('glass', (0.84, 0.92, 1.0)); iri = mat_iridescent('iri'); chrome = mat_chrome('chrome')
    mag = mat_iridescent('mag', stops=[(0.0, (0.12, 0.01, 0.2, 1)), (0.4, (0.65, 0.08, 0.8, 1)), (0.75, (0.95, 0.3, 0.9, 1)), (1.0, (0.45, 0.35, 1.0, 1))])
    studio()
    rbox((-2.6, 0.3, 0.2), (2.6, 2.6, 2.6), glass, bevel=0.3, rot=(math.radians(14), math.radians(-24), math.radians(10)))
    sphere((-2.6, 0.3, 0.2), 0.8, mat_gloss('cy', (0.1, 0.75, 1.0), 0.05))
    rbox((2.6, 0.3, 0.2), (2.6, 2.6, 2.6), iri, bevel=0.3, rot=(math.radians(-12), math.radians(24), math.radians(-8)))
    sphere((2.6, -1.1, 0.3), 0.5, mag)
    cu = bpy.data.curves.new('vs', 'FONT'); cu.body = 'VS'; cu.size = 1.35; cu.extrude = 0.16; cu.bevel_depth = 0.05; cu.align_x = 'CENTER'; cu.align_y = 'CENTER'
    t = bpy.data.objects.new('vs', cu); bpy.context.collection.objects.link(t); t.location = (0, -0.8, 0.1); t.rotation_euler = (math.radians(90), 0, 0); t.data.materials.append(chrome)
    torus((0, 0.2, 0.1), 1.35, 0.1, mat_iridescent('r2'), rot=(math.radians(84), 0, math.radians(8)))
    sphere((-0.2, 0.4, 2.3), 0.3, mat_gloss('c', (0.2, 0.8, 1.0), 0.05)); sphere((0.3, 0.2, -2.3), 0.22, mat_gloss('m', (0.9, 0.3, 0.95), 0.05))
    camera((0.2, -11.0, 0.8), (0, 0, 0.1), lens=55, pull=1.5)
