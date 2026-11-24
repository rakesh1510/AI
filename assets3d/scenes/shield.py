"""Trust: a glass shield with an iridescent core and a glowing check, orbited by a ring."""
import math, bpy
from lib import *

def shield_mesh(scale, depth, mat):
    import bmesh
    pts = [(-1.0, 1.15), (0, 1.55), (1.0, 1.15), (1.0, 0.1), (0.7, -0.75), (0, -1.55), (-0.7, -0.75), (-1.0, 0.1)]
    pts = [(x * scale, y * scale) for x, y in pts]
    me = bpy.data.meshes.new('shield'); bm = bmesh.new()
    vs = [bm.verts.new((x, 0, y)) for x, y in pts]; f = bm.faces.new(vs)
    r = bmesh.ops.extrude_face_region(bm, geom=[f]); mv = [v for v in r['geom'] if isinstance(v, bmesh.types.BMVert)]
    bmesh.ops.translate(bm, vec=(0, depth, 0), verts=mv); bm.normal_update(); bm.to_mesh(me); bm.free()
    ob = bpy.data.objects.new('shield', me); bpy.context.collection.objects.link(ob)
    smooth(ob, bevel=0.06 * scale, subsurf=1); assign(ob, mat); return ob

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    glass = mat_glass('glass', (0.85, 0.92, 1.0)); iri = mat_iridescent('iri'); chrome = mat_chrome('chrome')
    chk = mat_emit('chk', (0.3, 1.0, 0.85), 7)
    studio()
    shield_mesh(1.9, 0.5, glass).location = (0, -0.25, 0.0)
    s2 = shield_mesh(1.5, 0.55, iri); s2.location = (0, -0.3, 0.0)
    tube([(-0.75, -0.9, 0.0), (-0.2, -0.9, -0.6), (0.9, -0.9, 0.7)], 0.17, chk, bevel_res=12)
    torus((0, 0, -0.1), 3.0, 0.15, chrome, rot=(math.radians(76), math.radians(4), math.radians(22)))
    sphere((2.8, 0.6, 2.0), 0.4, mat_gloss('c', (0.2, 0.8, 1.0), 0.05)); sphere((-2.6, -0.4, -2.0), 0.28, mat_gloss('m', (0.9, 0.3, 0.95), 0.05)); sphere((3.1, -0.3, -1.6), 0.15, chrome)
    shadow_floor(-3.0)
    camera((0.3, -10.8, 0.5), (0, 0, 0), lens=55)
