"""Shared Blender (bpy, Cycles) helpers for the AIHubpedia studio renders.
All geometry, materials and lighting are procedural and original: no third-party meshes, HDRIs, textures or fonts beyond Blender's built-in default font.
Render: /tmp/bpyenv/bin/python assets3d/render.py <scene> <out.png> [--samples N] [--scale S]
"""
import bpy, math, os, sys
from mathutils import Vector

SAMPLES = 160
SCALE = 1.0

def reset(w, h, samples=None, scale=None):
    bpy.ops.wm.read_factory_settings(use_empty=True)
    s = bpy.context.scene
    s.render.engine = 'CYCLES'
    s.cycles.device = 'CPU'
    s.cycles.samples = samples or SAMPLES
    s.cycles.use_denoising = True
    s.cycles.denoiser = 'OPENIMAGEDENOISE'
    s.cycles.max_bounces = 14; s.cycles.transmission_bounces = 14; s.cycles.glossy_bounces = 10; s.cycles.transparent_max_bounces = 16
    s.cycles.caustics_reflective = False; s.cycles.caustics_refractive = False
    s.cycles.sample_clamp_indirect = 8
    k = scale or SCALE
    s.render.resolution_x = int(w * k); s.render.resolution_y = int(h * k); s.render.resolution_percentage = 100
    s.render.film_transparent = True
    s.render.image_settings.file_format = 'PNG'; s.render.image_settings.color_mode = 'RGBA'; s.render.image_settings.color_depth = '8'
    s.view_settings.view_transform = 'AgX'
    try: s.view_settings.look = 'AgX - Medium High Contrast'
    except Exception: pass
    s.view_settings.exposure = 0.0
    w_ = bpy.data.worlds.new('W'); s.world = w_; w_.use_nodes = True
    bg = w_.node_tree.nodes['Background']; bg.inputs[0].default_value = (0.012, 0.014, 0.05, 1); bg.inputs[1].default_value = 1.0
    return s

def _principled(mat):
    mat.use_nodes = True
    return mat.node_tree.nodes['Principled BSDF']

def _set(node, name, val):
    if name in node.inputs: node.inputs[name].default_value = val

def mat_chrome(name='chrome', color=(0.82, 0.86, 1.0), rough=0.05):
    m = bpy.data.materials.new(name); p = _principled(m)
    _set(p, 'Base Color', (*color, 1)); _set(p, 'Metallic', 1.0); _set(p, 'Roughness', rough)
    return m

def mat_glass(name='glass', tint=(0.86, 0.92, 1.0), rough=0.025, ior=1.46):
    m = bpy.data.materials.new(name); p = _principled(m)
    _set(p, 'Base Color', (*tint, 1)); _set(p, 'Roughness', rough); _set(p, 'IOR', ior)
    _set(p, 'Transmission Weight', 1.0); _set(p, 'Specular IOR Level', 0.6)
    return m

def mat_gloss(name, color, rough=0.12, coat=1.0, metallic=0.0):
    m = bpy.data.materials.new(name); p = _principled(m)
    _set(p, 'Base Color', (*color, 1)); _set(p, 'Roughness', rough); _set(p, 'Metallic', metallic)
    _set(p, 'Coat Weight', coat); _set(p, 'Coat Roughness', 0.03)
    return m

def mat_emit(name, color, strength=8.0):
    m = bpy.data.materials.new(name); m.use_nodes = True
    nt = m.node_tree; nt.nodes.clear()
    e = nt.nodes.new('ShaderNodeEmission'); e.inputs[0].default_value = (*color, 1); e.inputs[1].default_value = strength
    o = nt.nodes.new('ShaderNodeOutputMaterial'); nt.links.new(e.outputs[0], o.inputs[0])
    return m

def mat_iridescent(name='iri', stops=None, rough=0.14, metallic=0.92, coat=0.6):
    """Angle-dependent violet / blue / cyan / magenta body colour (layer-weight driven colour ramp) over a glossy metallic base."""
    m = bpy.data.materials.new(name); m.use_nodes = True
    nt = m.node_tree; p = nt.nodes['Principled BSDF']
    lw = nt.nodes.new('ShaderNodeLayerWeight'); lw.inputs['Blend'].default_value = 0.3
    ramp = nt.nodes.new('ShaderNodeValToRGB')
    stops = stops or [(0.0, (0.03, 0.01, 0.25, 1)), (0.22, (0.28, 0.06, 0.85, 1)), (0.45, (0.06, 0.30, 0.98, 1)), (0.7, (0.03, 0.80, 1.0, 1)), (1.0, (0.95, 0.45, 1.0, 1))]
    el = ramp.color_ramp.elements
    el[0].position = stops[0][0]; el[0].color = stops[0][1]; el[1].position = stops[-1][0]; el[1].color = stops[-1][1]
    for pos, col in stops[1:-1]:
        e = el.new(pos); e.color = col
    nt.links.new(lw.outputs['Facing'], ramp.inputs['Fac'])
    nt.links.new(ramp.outputs['Color'], p.inputs['Base Color'])
    _set(p, 'Metallic', metallic); _set(p, 'Roughness', rough); _set(p, 'Coat Weight', coat); _set(p, 'Coat Roughness', 0.02)
    return m

def assign(obj, mat):
    obj.data.materials.clear(); obj.data.materials.append(mat)

def smooth(obj, bevel=None, subsurf=1):
    for pgn in obj.data.polygons: pgn.use_smooth = True
    if bevel:
        b = obj.modifiers.new('bv', 'BEVEL'); b.width = bevel; b.segments = 8; b.limit_method = 'ANGLE'; b.angle_limit = math.radians(30)
    if subsurf:
        sm = obj.modifiers.new('ss', 'SUBSURF'); sm.levels = 2; sm.render_levels = subsurf + 1
    return obj

def sphere(loc, r, mat, seg=64):
    bpy.ops.mesh.primitive_uv_sphere_add(radius=r, location=loc, segments=seg, ring_count=seg // 2)
    o = bpy.context.active_object
    for pgn in o.data.polygons: pgn.use_smooth = True
    assign(o, mat); return o

def rbox(loc, size, mat, bevel=0.08, rot=(0, 0, 0)):
    bpy.ops.mesh.primitive_cube_add(location=loc, rotation=rot)
    o = bpy.context.active_object; o.scale = (size[0] / 2, size[1] / 2, size[2] / 2)
    bpy.ops.object.transform_apply(scale=True)
    smooth(o, bevel=bevel, subsurf=1); assign(o, mat); return o

def torus(loc, major, minor, mat, rot=(0, 0, 0)):
    bpy.ops.mesh.primitive_torus_add(location=loc, rotation=rot, major_radius=major, minor_radius=minor, major_segments=128, minor_segments=48)
    o = bpy.context.active_object
    for pgn in o.data.polygons: pgn.use_smooth = True
    assign(o, mat); return o

def tube(points, radius, mat, closed=False, bevel_res=10):
    cu = bpy.data.curves.new('tube', 'CURVE'); cu.dimensions = '3D'
    cu.bevel_depth = radius; cu.bevel_resolution = bevel_res; cu.use_fill_caps = True
    sp = cu.splines.new('POLY'); sp.points.add(len(points) - 1)
    for i, p in enumerate(points): sp.points[i].co = (p[0], p[1], p[2], 1)
    sp.use_cyclic_u = closed
    ob = bpy.data.objects.new('tube', cu); bpy.context.collection.objects.link(ob)
    ob.data.materials.append(mat); return ob

def torus_knot(p, q, R, r, tube_r, mat, n=600, loc=(0, 0, 0), rot=(0, 0, 0), scale=1.0):
    pts = []
    for i in range(n):
        t = 2 * math.pi * i / n
        rr = R + r * math.cos(q * t)
        pts.append((rr * math.cos(p * t) * scale, rr * math.sin(p * t) * scale, r * math.sin(q * t) * scale))
    ob = tube(pts, tube_r, mat, closed=True)
    ob.location = loc; ob.rotation_euler = rot
    return ob

def softbox(loc, size, rot, color=(1, 1, 1), strength=10.0):
    """Emissive panel used for realistic reflections on chrome and glass; invisible to the camera."""
    bpy.ops.mesh.primitive_plane_add(location=loc, rotation=rot); o = bpy.context.active_object
    o.scale = (size[0] / 2, size[1] / 2, 1)
    assign(o, mat_emit('sb', color, strength))
    o.visible_camera = False; o.visible_shadow = False
    return o

def studio():
    """Three-point studio: large top-left key, cyan rim right, magenta kicker behind, plus reflection panels."""
    softbox((-6, -5, 7), (9, 6), (math.radians(40), math.radians(-25), math.radians(-20)), (1.0, 0.97, 0.95), 26)
    softbox((8, -2, 3), (3, 9), (math.radians(80), math.radians(40), math.radians(10)), (0.35, 0.8, 1.0), 40)
    softbox((-8, 4, 2), (3, 8), (math.radians(80), math.radians(-40), math.radians(0)), (0.9, 0.35, 1.0), 34)
    softbox((0, 7, 8), (10, 3), (math.radians(150), 0, 0), (0.55, 0.5, 1.0), 12)
    softbox((0, -8, -3), (12, 2.2), (math.radians(-80), 0, 0), (0.12, 0.08, 0.45), 1.6)   # deep indigo under-light: keeps glass from reflecting a grey band

def camera(loc, target, lens=70, dof=None, pull=1.42):
    loc = tuple(Vector(target) + (Vector(loc) - Vector(target)) * pull)
    cam = bpy.data.cameras.new('cam'); cam.lens = lens
    ob = bpy.data.objects.new('cam', cam); bpy.context.collection.objects.link(ob)
    ob.location = loc
    d = Vector(target) - Vector(loc); ob.rotation_euler = d.to_track_quat('-Z', 'Y').to_euler()
    bpy.context.scene.camera = ob
    if dof:
        cam.dof.use_dof = True; cam.dof.focus_distance = (Vector(target) - Vector(loc)).length; cam.dof.aperture_fstop = dof
    return ob

def shadow_floor(z=-1.4, size=30):
    """Intentionally a no-op: the artwork floats on the page, and a shadow-catcher plane greys out transmitted glass."""
    return None

def render(path):
    s = bpy.context.scene; s.render.filepath = path
    bpy.ops.render.render(write_still=True)
