"""Plug-on accessories for the walker-socket yak: builds each one worn, checks the fit, renders it and exports it for printing.

Run: Blender -b -P accessories.py -- <output_dir>
Every accessory is modelled in its worn position, then rotated onto its flat side for printing. A 1.9 mm hole,
SOCKET_DEPTH deep, is drilled into each accessory coaxial with the yak socket; a cut piece of filament joins the two.
Hats stand on their base, eyewear lies on its back face and the headphones lie on their side.
"""

import math
import os
import sys

import bmesh
import bpy
from mathutils import Matrix, Vector

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import cursor_yak as yak  # noqa: E402

OUT = yak.OUT
COLOURS = {**yak.COLOURS, "red": (0.60, 0.03, 0.03), "white": (0.85, 0.85, 0.82), "yellow": (0.85, 0.55, 0.02)}

SOCKETS = {name: mouth for name, mouth, _, _ in yak.socket_points(yak.LEG_HEIGHT)}
HEAD_TOP = SOCKETS["head"][2]
FACE_Y = SOCKETS["eyewear_left"][1]
EYE_Z = SOCKETS["eyewear_left"][2]
EYE_X = yak.EYEWEAR_X
HEAD_SIDE = yak.BODY_WIDTH / 2 + yak.FRINGE_MARGIN
HEAD_DEPTH = yak.BODY_DEPTH + 2 * yak.FRINGE_MARGIN
HORN_CLEARANCE = 0.5
# Height of a fillet above its tangent plane, per unit radius, where its wall is 40 degrees off vertical.
FILLET_CUT = 1 - math.cos(math.radians(50))
FACE_UP = Matrix.Rotation(math.pi / 2, 4, "X")
BACK_DOWN = Matrix.Rotation(-math.pi / 2, 4, "X")
UPRIGHT = Matrix.Identity(4)


def lathe(name, profile, segments=64):
    """Solid of revolution about Z from a closed (radius, z) profile; points on the axis become poles."""
    bm = bmesh.new()
    rings = []
    for radius, z in profile:
        if radius < 1e-6:
            rings.append([bm.verts.new((0, 0, z))])
        else:
            rings.append([bm.verts.new((radius * math.cos(a), radius * math.sin(a), z))
                          for a in (2 * math.pi * k / segments for k in range(segments))])
    for i in range(len(profile)):
        near, far = rings[i], rings[(i + 1) % len(profile)]
        if len(near) == 1 and len(far) == 1:
            continue
        for k in range(segments):
            k2 = (k + 1) % segments
            if len(near) == 1:
                bm.faces.new((near[0], far[k2], far[k]))
            elif len(far) == 1:
                bm.faces.new((near[k], near[k2], far[0]))
            else:
                bm.faces.new((near[k], near[k2], far[k2], far[k]))
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces)
    return yak.object_from_bmesh(name, bm)


def sphere(name, radius, center):
    bm = bmesh.new()
    bmesh.ops.create_uvsphere(bm, u_segments=48, v_segments=24, radius=radius)
    bmesh.ops.translate(bm, vec=Vector(center), verts=bm.verts)
    return yak.object_from_bmesh(name, bm)


def pin_hole(mouth, axis, sign):
    """A filament pin hole drilled into the accessory from the socket mouth, along axis ('Y' or 'Z'), sign = +1 or -1."""
    center = list(mouth)
    center["XYZ".index(axis)] += sign * (yak.SOCKET_DEPTH - 1.0) / 2
    return yak.cylinder("pin_hole", yak.SOCKET_DIAMETER / 2, yak.SOCKET_DEPTH + 1.0, center, axis=axis, segments=48)


def drill(obj, *holes):
    for hole in holes:
        yak.boolean(obj, hole, "DIFFERENCE")
        yak.remove(hole)


def eyewear_holes():
    return [pin_hole((side * EYE_X, FACE_Y, EYE_Z), "Y", -1) for side in (-1, 1)]


def flat_back(obj):
    """Cuts everything behind the face plane, so the back is a sharp-edged flat that lies on the bed."""
    cutter = yak.rounded_box("cutter", (200, 100, 200), (0, FACE_Y + 50, EYE_Z), 0.01, segments=1)
    yak.boolean(obj, cutter, "DIFFERENCE")
    yak.remove(cutter)


def eyewear_box(name, size, center, radius, segments=8):
    """Rounded box whose back edges lie behind the face plane, so flat_back removes them."""
    extra = 1.5 * radius
    width, depth, height = size
    return yak.rounded_box(name, (width, depth + extra, height), (center[0], FACE_Y - depth + (depth + extra) / 2, center[1]), radius, segments=segments)


def join(base, *others):
    for other in others:
        yak.boolean(base, other, "UNION")
        yak.remove(other)
    return base


def duplicate(obj):
    copy = obj.copy()
    copy.data = obj.data.copy()
    bpy.context.collection.objects.link(copy)
    return copy


def move(parts, offset):
    for obj, _ in parts:
        obj.data.transform(Matrix.Translation(offset))


def build_sunglasses():
    depth, radius = 6.5, 1.8
    left = eyewear_box("sunglasses", (13.0, depth, 9.6), (-EYE_X, EYE_Z), radius)
    right = eyewear_box("lens", (13.0, depth, 9.6), (EYE_X, EYE_Z), radius)
    bridge = eyewear_box("bridge", (8.0, 4.0, 2.6), (0, EYE_Z + 2.4), 1.0, segments=6)
    join(left, right, bridge)
    flat_back(left)
    drill(left, *eyewear_holes())
    return [(left, "ink")], BACK_DOWN, 0


def build_goggles():
    depth, radius = 9.0, 4.0
    visor = eyewear_box("body", (34.0, depth, 15.0), (0, 41.0), radius, segments=10)
    flat_back(visor)
    nose = yak.rounded_box("nose", (9.0, 30.0, 6.0), (0, FACE_Y - 10, 33.5), 1.5, segments=6)
    yak.boolean(visor, nose, "DIFFERENCE")
    yak.remove(nose)
    drill(visor, *eyewear_holes())
    # The faceplate is the 3 mm front slab, printed last on top of the body, so the colour change needs no overhang.
    slab = yak.rounded_box("slab", (200, 100, 100), (0, FACE_Y - depth + 3.0 - 50, 41.0), 0.01, segments=1)
    faceplate = duplicate(visor)
    faceplate.name = "faceplate"
    yak.boolean(faceplate, slab, "INTERSECT")
    yak.boolean(visor, slab, "DIFFERENCE")
    yak.remove(slab)
    return [(visor, "white"), (faceplate, "ink")], BACK_DOWN, 0


def sweep(name, spine, first_radius, last_radius, rings=40, segments=48):
    """Tapered tube along a quadratic curve in the XZ plane with flat ends; a radius is a number or an (x, y) pair."""
    p0, p1, p2 = (Vector(point) for point in spine)
    first, last = ((r, r) if isinstance(r, (int, float)) else r for r in (first_radius, last_radius))
    bm = bmesh.new()
    loops = []
    for i in range(rings + 1):
        t = i / rings
        point = (1 - t) ** 2 * p0 + 2 * (1 - t) * t * p1 + t ** 2 * p2
        tangent = (2 * (1 - t) * (p1 - p0) + 2 * t * (p2 - p1)).normalized()
        normal = tangent.cross(Vector((0, 1, 0))).normalized()
        binormal = tangent.cross(normal).normalized()
        radius_x, radius_y = (first[k] + (last[k] - first[k]) * t for k in (0, 1))
        loops.append([bm.verts.new(point + radius_x * math.cos(a) * normal + radius_y * math.sin(a) * binormal)
                      for a in (2 * math.pi * k / segments for k in range(segments))])
    for near, far in zip(loops, loops[1:]):
        for k in range(segments):
            bm.faces.new((near[k], near[(k + 1) % segments], far[(k + 1) % segments], far[k]))
    bm.faces.new(list(reversed(loops[0])))
    bm.faces.new(loops[-1])
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces)
    return yak.object_from_bmesh(name, bm)


def pom(radius, center):
    """A pom-pom with a 45 degree cone underneath instead of a lower hemisphere, so it prints without support."""
    profile = [(0, radius)]
    for degrees in range(80, -46, -15):
        profile.append((radius * math.cos(math.radians(degrees)), radius * math.sin(math.radians(degrees))))
    profile += [(0, -radius * math.sqrt(2))]
    obj = lathe("pom", profile)
    obj.data.transform(Matrix.Translation(center))
    return obj


def horn_cutter(side):
    """The horn grown by HORN_CLEARANCE, in worn coordinates, to cut a hat so it sits down over the horn."""
    lift = yak.LEG_HEIGHT
    spine = [(side * 11.0, 0, 42.0 + lift), (side * 20.0, 0, 51.0 + lift), (side * 19.5, 0, 59.0 + lift)]
    tube = sweep("horn_cutter", spine, 5.8 + HORN_CLEARANCE, 3.0 + HORN_CLEARANCE)
    return join(tube, sphere("tip", 3.0 + HORN_CLEARANCE, spine[2]))


def notch_horns(*objects):
    """Cuts the horn clearance out of hats given in worn coordinates."""
    for side in (-1, 1):
        cutter = horn_cutter(side)
        for obj in objects:
            yak.boolean(obj, cutter, "DIFFERENCE")
        yak.remove(cutter)


def crown(height, name="crown", radius=6.2):
    """The head top's footprint, standing on z=0."""
    return yak.rounded_box(name, (yak.BODY_WIDTH + 2 * yak.FRINGE_MARGIN, HEAD_DEPTH, height), (0, 0, height / 2), radius, vertical_only=True)


def build_party_hat():
    height = 42.0
    cone = sweep("cone", [(0, 0, 0), (0, 0, height / 2), (0, 0, height)], (19.0, 16.0), 2.0, segments=64)
    top = pom(4.2, (0, 0, height + 1.5))
    yak.boolean(top, cone, "DIFFERENCE")
    parts = [(cone, "yellow"), (top, "red")]
    move(parts, (0, 0, HEAD_TOP))
    notch_horns(cone, top)
    drill(cone, pin_hole((0, 0, HEAD_TOP), "Z", 1))
    return parts, UPRIGHT, 0


def build_santa_hat():
    tip = Vector((13.0, 0, 33.0))
    body = sweep("body", [(0, 0, 0), (0, 0, 17.0), tip], (17.8, 15.0), 2.6, rings=60, segments=64)
    fur = yak.rounded_box("fur", (38.4, HEAD_DEPTH, 6.0), (0, 0, 3.0), 2.4, open_bottom=True)
    yak.boolean(fur, crown(6.0, "footprint"), "INTERSECT")
    yak.boolean(fur, body, "DIFFERENCE")
    top = pom(5.0, tip + Vector((0.5, 0, 1.5)))
    yak.boolean(top, body, "DIFFERENCE")
    parts = [(body, "red"), (fur, "white"), (top, "white")]
    move(parts, (0, 0, HEAD_TOP))
    notch_horns(body, fur)
    drill(body, pin_hole((0, 0, HEAD_TOP), "Z", 1))
    return parts, UPRIGHT, 0


def build_hard_hat():
    brim = crown(2.4, "hard_hat")
    peak = yak.rounded_box("peak", (24.0, 16.0, 2.4), (0, -HEAD_DEPTH / 2 - 2.0, 1.2), 4.0, vertical_only=True)
    dome = yak.rounded_box("dome", (32.0, 26.0, 17.0), (0, 0, 8.5), 8.0, open_bottom=True)
    # The ridge is the dome grown by 2.5 mm and trimmed to a strip, so it follows the dome without a ledge.
    ridge = yak.rounded_box("ridge", (37.0, 31.0, 19.5), (0, 0, 9.75), 10.5, open_bottom=True)
    strip = yak.rounded_box("strip", (6.0, 100, 100), (0, 0, 50), 0.01, segments=1)
    yak.boolean(ridge, strip, "INTERSECT")
    join(brim, peak, dome, ridge)
    yak.remove(strip)
    parts = [(brim, "yellow")]
    move(parts, (0, 0, HEAD_TOP))
    notch_horns(brim)
    drill(brim, pin_hole((0, 0, HEAD_TOP), "Z", 1))
    return parts, UPRIGHT, 0


def build_headphones():
    thickness, width, clamp = 3.2, 6.0, 0.8
    front, cup_radius, cup_depth = FACE_Y + 2.7, 5.5, 5.6
    gap = 1.6 + thickness / 2
    side_x, top_z, corner = HEAD_SIDE + gap, HEAD_TOP + gap, 6.2 + gap
    cup_z = 43.5
    centre = []
    for sign in (-1, 1):
        arc = [(sign * (13.0 + corner * math.cos(math.pi / 2 * s / 20)), top_z - corner + corner * math.sin(math.pi / 2 * s / 20)) for s in range(21)]
        centre.append(arc)
    path = [(-side_x, cup_z + 1.0)] + centre[0] + centre[1][::-1] + [(side_x, cup_z + 1.0)]
    outer, inner = [], []
    for i, (x, z) in enumerate(path):
        before, after = path[max(i - 1, 0)], path[min(i + 1, len(path) - 1)]
        tangent = Vector((after[0] - before[0], after[1] - before[1])).normalized()
        outer.append((x - tangent.y * thickness / 2, z + tangent.x * thickness / 2))
        inner.append((x + tangent.y * thickness / 2, z - tangent.x * thickness / 2))
    band = yak.prism("headphones", outer + inner[::-1], "Y", front, front + width)
    cups = []
    for sign in (-1, 1):
        cup = lathe("cup", [(0, 0), (cup_radius - 0.6, 0), (cup_radius, 0.6), (cup_radius, cup_depth - 1.1),
                            (cup_radius - 0.8, cup_depth), (0, cup_depth)])
        cup.data.transform(Matrix.Translation((sign * (HEAD_SIDE - clamp), front + cup_radius, cup_z))
                           @ Matrix.Rotation(sign * math.pi / 2, 4, "Y"))
        cups.append(cup)
    join(band, *cups)
    return [(band, "red")], FACE_UP, cup_radius


def paint(obj, colour):
    obj.data.set_sharp_from_angle(angle=math.radians(35))
    obj.data.polygons.foreach_set("use_smooth", [True] * len(obj.data.polygons))
    obj.data.materials.clear()
    obj.data.materials.append(yak.material(colour, COLOURS[colour]))


def overlap_volume(part, other):
    """Volume in mm3 where two parts occupy the same space."""
    probe = duplicate(part)
    yak.boolean(probe, other, "INTERSECT")
    bm = bmesh.new()
    bm.from_mesh(probe.data)
    volume = abs(bm.calc_volume()) if bm.verts else 0.0
    bm.free()
    yak.remove(probe)
    return volume


def check_fit(name, parts, yak_parts):
    for obj, _ in parts:
        for other, colour in yak_parts:
            volume = overlap_volume(obj, other)
            if volume > 0.01:
                print(f"[{name}] {obj.name} overlaps yak {colour} by {volume:.2f} mm3")


def to_print_frame(parts, rotation, cut_radius):
    """Rotates the parts onto the bed, with the face that touches it cut where its rounding is 40 degrees off vertical."""
    for obj, _ in parts:
        obj.data.transform(rotation)
    lowest = min(vert.co.z for obj, _ in parts for vert in obj.data.vertices)
    for obj, _ in parts:
        obj.data.transform(Matrix.Translation((0, 0, -lowest - FILLET_CUT * cut_radius)))
        yak.flatten_base(obj)


def export(name, parts):
    os.makedirs(OUT, exist_ok=True)
    for obj, colour in parts:
        yak.triangulate_clean(obj)
        path = os.path.join(OUT, f"{name}-{obj.name.split('.')[0]}-{colour}.stl")
        yak.export_stl([obj], path)
        size = [max(v.co[i] for v in obj.data.vertices) - min(v.co[i] for v in obj.data.vertices) for i in range(3)]
        print(f"[{name}] {obj.name}: {len(obj.data.polygons)} faces, non-manifold edges: {yak.report_manifold(obj)}, "
              f"size {size[0]:.1f} x {size[1]:.1f} x {size[2]:.1f} mm -> {path}")


ACCESSORIES = [
    ("sunglasses", build_sunglasses, (0, -14, 42), 110),
    ("vr-goggles", build_goggles, (0, -14, 42), 120),
    ("party-hat", build_party_hat, (0, 0, 72), 220),
    ("santa-hat", build_santa_hat, (4, 0, 72), 220),
    ("hard-hat", build_hard_hat, (0, -4, 65), 190),
    ("headphones", build_headphones, (0, -6, 50), 150),
]

if __name__ == "__main__":
    for name, builder, look_at, distance in ACCESSORIES:
        yak_parts = yak.build_parts("walker-socket")
        parts, rotation, cut_radius = builder()
        check_fit(name, parts, yak_parts)
        for obj, colour in yak_parts + parts:
            paint(obj, colour)
        yak.setup_render(os.path.join(OUT, f"{name}.png"), look_at, distance)
        to_print_frame(parts, rotation, cut_radius)
        export(name, parts)
