"""Cursor Yak: builds the desk block and walker variants, exports per-colour STLs and renders previews.

Run: Blender -b -P cursor_yak.py -- <output_dir>
Units are millimetres. X is width, -Y is the front, Z is up. Every part has a flat base at z=0
and nothing overhangs more than 45 degrees except the muzzle underside, which needs slicer supports.
"""

import math
import os
import sys

import bmesh
import bpy
from mathutils import Matrix, Vector

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import locks as locks_module  # noqa: E402

OUT = sys.argv[sys.argv.index("--") + 1]
os.makedirs(OUT, exist_ok=True)

COLOURS = {
    "slate": (0.212, 0.333, 0.459),
    "cream": (0.862, 0.784, 0.584),
    "rust": (0.552, 0.130, 0.045),
    "sage": (0.212, 0.318, 0.110),
    "peach": (0.768, 0.305, 0.150),
    "ink": (0.030, 0.042, 0.052),
}

BODY_WIDTH, BODY_DEPTH, BODY_HEIGHT = 36.0, 30.0, 47.0
FRINGE_BOTTOM = 26.0
LEG_HEIGHT = 9.0


def clear_scene():
    for obj in list(bpy.data.objects):
        bpy.data.objects.remove(obj, do_unlink=True)
    for mesh in list(bpy.data.meshes):
        bpy.data.meshes.remove(mesh)


def object_from_bmesh(name, bm):
    mesh = bpy.data.meshes.new(name)
    bm.to_mesh(mesh)
    bm.free()
    obj = bpy.data.objects.new(name, mesh)
    bpy.context.collection.objects.link(obj)
    return obj


def rounded_box(name, size, center, radius, segments=16, vertical_only=False, open_bottom=False):
    """Box with rounded edges. vertical_only rounds just the upright edges; open_bottom leaves the base edges sharp."""
    bm = bmesh.new()
    bmesh.ops.create_cube(bm, size=1.0)
    bmesh.ops.scale(bm, vec=Vector(size), verts=bm.verts)
    edges = bm.edges[:]
    if vertical_only:
        edges = [edge for edge in edges if abs((edge.verts[0].co - edge.verts[1].co).normalized().z) > 0.9]
    if open_bottom:
        bottom = min(vert.co.z for vert in bm.verts)
        edges = [edge for edge in edges if not all(abs(vert.co.z - bottom) < 1e-6 for vert in edge.verts)]
    bmesh.ops.bevel(bm, geom=edges + ([] if vertical_only or open_bottom else bm.verts[:]), offset=radius, segments=segments, profile=0.5, affect="EDGES", clamp_overlap=True)
    bmesh.ops.translate(bm, vec=Vector(center), verts=bm.verts)
    return object_from_bmesh(name, bm)


def cylinder(name, radius, depth, center, axis="Z", segments=64):
    bm = bmesh.new()
    bmesh.ops.create_cone(bm, cap_ends=True, segments=segments, radius1=radius, radius2=radius, depth=depth)
    if axis == "Y":
        bmesh.ops.rotate(bm, verts=bm.verts, matrix=Matrix.Rotation(math.pi / 2, 3, "X"))
    elif axis == "X":
        bmesh.ops.rotate(bm, verts=bm.verts, matrix=Matrix.Rotation(math.pi / 2, 3, "Y"))
    bmesh.ops.translate(bm, vec=Vector(center), verts=bm.verts)
    return object_from_bmesh(name, bm)


def capsule(name, radius, start, end, segments=48):
    """A tube between two points with hemispherical ends."""
    start, end = Vector(start), Vector(end)
    bm = bmesh.new()
    bmesh.ops.create_uvsphere(bm, u_segments=segments, v_segments=segments // 2, radius=radius)
    length = (end - start).length
    for vert in bm.verts:
        if vert.co.z > 0:
            vert.co.z += length
    direction = (end - start).normalized()
    rotation = Vector((0, 0, 1)).rotation_difference(direction).to_matrix()
    bmesh.ops.rotate(bm, verts=bm.verts, matrix=rotation)
    bmesh.ops.translate(bm, vec=start, verts=bm.verts)
    return object_from_bmesh(name, bm)


def horn(name, side, lift, rings=56, segments=64):
    """Tapered horn swept along a quadratic curve that rises at 45 degrees, then turns vertical."""
    p0 = Vector((side * 11.0, 0.0, 42.0 + lift))
    p1 = Vector((side * 20.0, 0.0, 51.0 + lift))
    p2 = Vector((side * 19.5, 0.0, 59.0 + lift))
    r0, r1 = 5.8, 3.0
    bm = bmesh.new()
    loops = []
    for i in range(rings + 1):
        t = i / rings
        point = (1 - t) ** 2 * p0 + 2 * (1 - t) * t * p1 + t ** 2 * p2
        tangent = (2 * (1 - t) * (p1 - p0) + 2 * t * (p2 - p1)).normalized()
        normal = tangent.cross(Vector((0, 1, 0))).normalized()
        binormal = tangent.cross(normal).normalized()
        radius = r0 + (r1 - r0) * t
        loops.append([bm.verts.new(point + radius * (math.cos(a) * normal + math.sin(a) * binormal))
                      for a in (2 * math.pi * k / segments for k in range(segments))])
    for a, b in zip(loops, loops[1:]):
        for k in range(segments):
            bm.faces.new((a[k], a[(k + 1) % segments], b[(k + 1) % segments], b[k]))
    direction = (p2 - p1).normalized()
    normal = direction.cross(Vector((0, 1, 0))).normalized()
    binormal = direction.cross(normal).normalized()
    for j in range(1, 6):
        angle = j / 6 * math.pi / 2
        center, radius = p2 + direction * r1 * math.sin(angle), r1 * math.cos(angle)
        ring = [bm.verts.new(center + radius * (math.cos(a) * normal + math.sin(a) * binormal))
                for a in (2 * math.pi * k / segments for k in range(segments))]
        for k in range(segments):
            bm.faces.new((loops[-1][k], loops[-1][(k + 1) % segments], ring[(k + 1) % segments], ring[k]))
        loops.append(ring)
    bm.faces.new(list(reversed(loops[0])))
    tip_center = bm.verts.new(p2 + direction * r1)
    for k in range(segments):
        bm.faces.new((loops[-1][k], loops[-1][(k + 1) % segments], tip_center))
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces)
    return object_from_bmesh(name, bm)


def prism(name, outline, axis, start, end, start_drop=0.0, end_drop=0.0):
    """Extrudes a convex (u, z) outline between two offsets along X or Y.

    A drop lowers the outline at that end, shearing the prism; a drop equal to the length gives a 45 degree underside.
    """
    bm = bmesh.new()
    place = (lambda u, z, a: (u, a, z)) if axis == "Y" else (lambda u, z, a: (a, u, z))
    near = [bm.verts.new(place(u, z - start_drop, start)) for u, z in outline]
    far = [bm.verts.new(place(u, z - end_drop, end)) for u, z in outline]
    bm.faces.new(near)
    bm.faces.new(list(reversed(far)))
    count = len(outline)
    for i in range(count):
        j = (i + 1) % count
        bm.faces.new((near[i], near[j], far[j], far[i]))
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces)
    return object_from_bmesh(name, bm)


def boolean(target, other, operation):
    modifier = target.modifiers.new("bool", "BOOLEAN")
    modifier.operation = operation
    modifier.object = other
    modifier.solver = "MANIFOLD"
    depsgraph = bpy.context.evaluated_depsgraph_get()
    evaluated = target.evaluated_get(depsgraph)
    mesh = bpy.data.meshes.new_from_object(evaluated)
    target.modifiers.clear()
    old = target.data
    target.data = mesh
    bpy.data.meshes.remove(old)
    return target


def remove(*objects):
    for obj in objects:
        bpy.data.objects.remove(obj, do_unlink=True)


def flatten_base(obj):
    """Cut everything below z=0 so the part sits flat on the bed."""
    cutter = rounded_box("cutter", (400, 400, 100), (0, 0, -50), 0.01, segments=1)
    boolean(obj, cutter, "DIFFERENCE")
    remove(cutter)


def build_body(lift):
    # The bottom fillet is cut at 1.5 mm, where the 5 mm radius is at 45 degrees.
    body = rounded_box("body", (BODY_WIDTH, BODY_DEPTH, BODY_HEIGHT + 1.5), (0, 0, lift + (BODY_HEIGHT - 1.5) / 2), 5.0)
    if lift == 0:
        flatten_base(body)
        return body
    bottom_cut = rounded_box("cut", (400, 400, 100), (0, 0, lift - 50), 0.01, segments=1)
    boolean(body, bottom_cut, "DIFFERENCE")
    remove(bottom_cut)
    for side in (-1, 1):
        leg = rounded_box("leg", (14.0, 26.0, lift + 6.0), (side * 9.0, -1.0, (lift + 6.0) / 2 - 1.5), 4.0)
        flatten_base(leg)
        boolean(body, leg, "UNION")
        remove(leg)
        arm = capsule("arm", 3.8, (side * 18.4, -2.0, lift + 19.0), (side * 19.6, -3.5, lift + 7.0))
        boolean(body, arm, "UNION")
        remove(arm)
    return body


FRINGE_MARGIN = 1.2
FRINGE_RADIUS = 5.0 + FRINGE_MARGIN


def head_box(name, lift, bottom, inset=0.0):
    """The fringe's outer shape: rounded on top and on the upright edges, open at the bottom."""
    top = lift + BODY_HEIGHT + 1.5
    width, depth = BODY_WIDTH + 2 * FRINGE_MARGIN - 2 * inset, BODY_DEPTH + 2 * FRINGE_MARGIN - 2 * inset
    return rounded_box(name, (width, depth, top - bottom), (0, 0, (top + bottom) / 2), FRINGE_RADIUS - inset, open_bottom=True)


def build_fringe(body, lift):
    outer_width, outer_depth = BODY_WIDTH + 2 * FRINGE_MARGIN, BODY_DEPTH + 2 * FRINGE_MARGIN
    edge = lift + FRINGE_BOTTOM
    fringe = head_box("fringe", lift, edge)
    # Locks start outside the head and are trimmed to its exact rounded footprint, so their faces
    # are flush with the fringe band and follow the corners. Each lock is sheared so it sinks
    # 45 degrees towards the body: its underside grows out of the face instead of starting in mid-air.
    face, depth = -0.5, 3.0
    sink = depth - face
    locks = []
    for center, length in locks_module.FRONT:
        outline = locks_module.lock_outline(center, length, edge)
        locks.append(prism("lock", outline, "Y", -outer_depth / 2 + face, -outer_depth / 2 + depth, end_drop=sink))
        locks.append(prism("lock", [(-u, z) for u, z in reversed(outline)], "Y", outer_depth / 2 - depth, outer_depth / 2 - face, start_drop=sink))
    for side in (-1, 1):
        for center, length in locks_module.SIDE:
            outline = locks_module.lock_outline(side * center, length, edge)
            if side < 0:
                locks.append(prism("lock", outline, "X", -outer_width / 2 + face, -outer_width / 2 + depth, end_drop=sink))
            else:
                locks.append(prism("lock", outline, "X", outer_width / 2 - depth, outer_width / 2 - face, start_drop=sink))
    footprint = rounded_box("footprint", (outer_width, outer_depth, 60.0), (0, 0, edge), FRINGE_RADIUS, vertical_only=True)
    for lock in locks:
        boolean(lock, footprint, "INTERSECT")
        boolean(fringe, lock, "UNION")
    remove(footprint, *locks)
    boolean(fringe, body, "DIFFERENCE")
    return fringe


def build_muzzle(body, lift):
    front = -BODY_DEPTH / 2
    muzzle = rounded_box("muzzle", (18.0, 5.0, 10.5), (0, front - 0.3, lift + 11.5), 2.4)
    boolean(muzzle, body, "DIFFERENCE")
    for x in (-3.6, 3.6):
        nostril = cylinder("nostril", 1.4, 3.0, (x, front - 2.8, lift + 12.1), axis="Y")
        boolean(muzzle, nostril, "DIFFERENCE")
        remove(nostril)
    return muzzle


def deboss_prompt(body):
    """Cut a 0.8 mm deep '>_' into the back face, below the fringe."""
    back, z = BODY_DEPTH / 2, 9.0
    strokes = [((-9, z + 6), (-3, z)), ((-9, z - 6), (-3, z)), ((1, z - 6), (9, z - 6))]
    for (x0, z0), (x1, z1) in strokes:
        groove = capsule("groove", 1.6, (x0, back + 0.8, z0), (x1, back + 0.8, z1))
        boolean(body, groove, "DIFFERENCE")
        remove(groove)


def material(name, colour):
    mat = bpy.data.materials.get(name) or bpy.data.materials.new(name)
    mat.use_nodes = True
    bsdf = mat.node_tree.nodes.get("Principled BSDF")
    bsdf.inputs["Base Color"].default_value = (*colour, 1.0)
    bsdf.inputs["Roughness"].default_value = 0.55
    return mat


def triangulate_clean(obj):
    """Triangulates the way STL export does, then drops the slivers booleans leave behind."""
    bm = bmesh.new()
    bm.from_mesh(obj.data)
    bmesh.ops.triangulate(bm, faces=bm.faces[:], quad_method="BEAUTY", ngon_method="BEAUTY")
    bmesh.ops.remove_doubles(bm, verts=bm.verts, dist=1e-4)
    bmesh.ops.dissolve_degenerate(bm, edges=bm.edges, dist=1e-4)
    bmesh.ops.triangulate(bm, faces=[f for f in bm.faces if len(f.verts) > 3])
    bm.to_mesh(obj.data)
    bm.free()


def report_manifold(obj):
    bm = bmesh.new()
    bm.from_mesh(obj.data)
    bad = sum(1 for edge in bm.edges if not edge.is_manifold)
    bm.free()
    return bad


def export_stl(objects, path):
    bpy.ops.object.select_all(action="DESELECT")
    for obj in objects:
        obj.select_set(True)
    bpy.ops.wm.stl_export(filepath=path, export_selected_objects=True, apply_modifiers=True, ascii_format=False)


def setup_render(path, look_at, distance, square=False):
    scene = bpy.context.scene
    scene.render.engine = "CYCLES"
    scene.cycles.samples = 96
    scene.cycles.use_denoising = True
    try:
        prefs = bpy.context.preferences.addons["cycles"].preferences
        prefs.compute_device_type = "METAL"
        prefs.get_devices()
        for device in prefs.devices:
            device.use = True
        scene.cycles.device = "GPU"
    except Exception:
        scene.cycles.device = "CPU"
    scene.render.resolution_x, scene.render.resolution_y = (1600, 1600) if square else (1400, 1050)
    scene.render.film_transparent = square
    scene.view_settings.view_transform = "Standard" if square else "AgX"
    scene.view_settings.exposure = 0.15 if square else 0.45
    scene.render.filepath = path

    world = bpy.data.worlds.get("World") or bpy.data.worlds.new("World")
    scene.world = world
    world.use_nodes = True
    world.node_tree.nodes["Background"].inputs["Color"].default_value = (0.82, 0.84, 0.86, 1)
    world.node_tree.nodes["Background"].inputs["Strength"].default_value = 0.8

    bm = bmesh.new()
    bmesh.ops.create_grid(bm, x_segments=1, y_segments=1, size=600)
    ground = object_from_bmesh("ground", bm)
    ground.is_shadow_catcher = square
    ground.data.materials.append(material("ground", (0.80, 0.80, 0.80)))

    light_data = bpy.data.lights.new("key", "AREA")
    light_data.energy, light_data.size = 2.6e5, 160
    light = bpy.data.objects.new("key", light_data)
    light.location = (-120, -160, 220)
    light.rotation_euler = (math.radians(45), 0, math.radians(-35))
    bpy.context.collection.objects.link(light)

    camera_data = bpy.data.cameras.new("camera")
    camera_data.lens = 70
    camera = bpy.data.objects.new("camera", camera_data)
    bpy.context.collection.objects.link(camera)
    target = Vector(look_at)
    camera.location = target + Vector((0.42, -1.0, 0.3) if square else (0.55, -1.0, 0.42)).normalized() * distance
    camera.rotation_euler = (target - camera.location).to_track_quat("-Z", "Y").to_euler()
    scene.camera = camera
    bpy.ops.render.render(write_still=True)


# Pin sockets for plug-on accessories. The yak and each accessory get a matching hole, joined by a
# PIN_LENGTH piece of 1.75 mm filament, so every accessory prints flat side down with no peg.
SOCKET_DIAMETER = 1.9
SOCKET_DEPTH = 4.5
PIN_LENGTH = 8.0
EYEWEAR_X, EYEWEAR_Z = 9.0, 31.0
ARM_BOTTOM = (19.6, -3.5, 7.0)
ARM_RADIUS = 3.8


def socket_points(lift):
    """Where each socket opens, and the axis it runs along into the yak: (name, mouth, axis, inward sign)."""
    top = lift + BODY_HEIGHT + 1.5
    front = -BODY_DEPTH / 2 - FRINGE_MARGIN
    hand_z = lift + ARM_BOTTOM[2] - ARM_RADIUS
    return [
        ("head", (0.0, 0.0, top), "Z", -1),
        ("eyewear_left", (-EYEWEAR_X, front, lift + EYEWEAR_Z), "Y", 1),
        ("eyewear_right", (EYEWEAR_X, front, lift + EYEWEAR_Z), "Y", 1),
        ("hand_left", (-ARM_BOTTOM[0], ARM_BOTTOM[1], hand_z), "Z", 1),
        ("hand_right", (ARM_BOTTOM[0], ARM_BOTTOM[1], hand_z), "Z", 1),
    ]


def drill_sockets(parts, lift):
    """Cut every socket through whichever parts it meets."""
    for _, mouth, axis, sign in socket_points(lift):
        index = "XYZ".index(axis)
        center = list(mouth)
        center[index] += sign * SOCKET_DEPTH / 2 - sign * 1.0
        for obj, _ in parts:
            hole = cylinder("socket", SOCKET_DIAMETER / 2, SOCKET_DEPTH + 2.0, center, axis=axis, segments=48)
            boolean(obj, hole, "DIFFERENCE")
            remove(hole)


def build_parts(variant):
    """The yak as (object, colour) parts. 'walker-socket' is the walker with accessory sockets."""
    clear_scene()
    lift = 0.0 if variant == "block" else LEG_HEIGHT
    body = build_body(lift)
    if variant == "block":
        deboss_prompt(body)
    fringe = build_fringe(body, lift)
    muzzle = build_muzzle(body, lift)
    # The horns are cut by one rounded box that contains the whole head. The fringe crown is that
    # same box, so each horn meets the fringe exactly and the cut stays watertight once triangulated.
    envelope = head_box("envelope", lift, lift)
    horns = []
    for side, colour in ((-1, "rust"), (1, "sage")):
        part = horn(f"horn_{colour}", side, lift)
        boolean(part, envelope, "DIFFERENCE")
        horns.append((part, colour))
    remove(envelope)
    parts = [(body, "slate"), (fringe, "cream"), (muzzle, "peach")] + horns
    if variant == "walker-socket":
        drill_sockets(parts, lift)
    return parts


def export_and_render(parts, variant, look_at=(0, 0, 36), distance=300, square_look_at=(0, 0, 35.5), square_distance=280):
    """Writes one STL per part plus a single-colour STL into OUT/cursor-yak-<variant>/, then renders two previews."""
    folder = os.path.join(OUT, f"cursor-yak-{variant}")
    os.makedirs(folder, exist_ok=True)
    for obj, colour in parts:
        triangulate_clean(obj)
        obj.data.set_sharp_from_angle(angle=math.radians(35))
        obj.data.polygons.foreach_set("use_smooth", [True] * len(obj.data.polygons))
        obj.data.materials.clear()
        obj.data.materials.append(material(colour, COLOURS[colour]))
        export_stl([obj], os.path.join(folder, f"{variant}-{obj.name.split('.')[0]}-{colour}.stl"))
        print(f"[{variant}] {obj.name}: {len(obj.data.polygons)} faces, non-manifold edges: {report_manifold(obj)}, "
              f"height {max(v.co.z for v in obj.data.vertices) + obj.location.z:.1f} mm")
    # Single-filament version: the watertight parts in one file; slicers union touching shells per layer.
    export_stl([obj for obj, _ in parts], os.path.join(folder, f"{variant}-single-colour.stl"))

    setup_render(os.path.join(OUT, f"cursor-yak-{variant}.png"), look_at, distance)
    for obj in [o for o in bpy.data.objects if o.type in {"LIGHT", "CAMERA"} or o.name == "ground"]:
        bpy.data.objects.remove(obj, do_unlink=True)
    setup_render(os.path.join(OUT, f"cursor-yak-{variant}-square.png"), square_look_at, square_distance, square=True)


if __name__ == "__main__":
    for name in ("walker", "walker-socket"):
        export_and_render(build_parts(name), name)
