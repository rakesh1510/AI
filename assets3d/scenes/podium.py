"""Rankings: three glossy podium blocks (2-1-3) with metallic medals and chrome numerals."""
import math, bpy
from lib import *

def numeral(txt, loc, mat, size=0.9):
    cu = bpy.data.curves.new('t', 'FONT'); cu.body = txt; cu.size = size; cu.extrude = 0.08; cu.bevel_depth = 0.03; cu.align_x = 'CENTER'
    ob = bpy.data.objects.new('t', cu); bpy.context.collection.objects.link(ob)
    ob.location = loc; ob.rotation_euler = (math.radians(90), 0, 0); ob.data.materials.append(mat); return ob

def build(samples=None, scale=None):
    reset(1400, 1200, samples, scale)
    chrome = mat_chrome('chrome'); iri = mat_iridescent('iri')
    glass = mat_glass('glass', (0.82, 0.9, 1.0))
    gold = mat_chrome('gold', (1.0, 0.72, 0.25), 0.12); silver = mat_chrome('silver', (0.82, 0.86, 0.95), 0.1); bronze = mat_chrome('bronze', (0.9, 0.5, 0.28), 0.14)
    studio()
    rbox((0, 0, 0.3), (1.9, 1.9, 2.6), iri, bevel=0.16); rbox((-2.1, 0, -0.2), (1.9, 1.9, 1.6), glass, bevel=0.16); rbox((2.1, 0, -0.45), (1.9, 1.9, 1.1), iri, bevel=0.16)
    numeral('1', (0, -1.0, 0.0), chrome, 1.2); numeral('2', (-2.1, -1.0, -0.45), chrome, 0.9); numeral('3', (2.1, -1.0, -0.62), chrome, 0.8)
    sphere((0, 0, 2.05), 0.55, gold); sphere((-2.1, 0, 0.95), 0.42, silver); sphere((2.1, 0, 0.35), 0.36, bronze)
    torus((0, 0, 2.05), 0.95, 0.05, chrome, rot=(math.radians(72), 0, math.radians(12)))
    sphere((-3.3, 0.4, 2.2), 0.2, mat_gloss('c', (0.2, 0.8, 1.0), 0.06)); sphere((3.2, 0.2, 1.9), 0.16, mat_gloss('m', (1.0, 0.3, 0.9), 0.06))
    shadow_floor(-1.15)
    camera((0.8, -9.6, 2.6), (0, 0, 0.5), lens=55)
