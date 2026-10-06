"""Shaving: yak shaving, literally. The yak lifts a safety razor and shaves its right half of the fringe in three
downward strokes; cream tufts fall and pile by the right foot; it lowers the razor and looks pleased."""

import math
import random

import bpy
from mathutils import Matrix, Vector

import cursor_yak as yak
from mascot_clips import HAND, SHOULDER, about, keys, rotation, translate
from mascot_motion import smoothstep

FRAMES = 192
CAMERA = {"look_at": (6, -6, 33), "direction": (0.42, -1.0, 0.2), "height": 90.0}

HANDLE_RADIUS = 2.8
BLADE_REACH = 15.5
BLADE_HALF_WIDTH = 7.0
BLADE_Y_ON, BLADE_Y_OFF = -18.2, -22.5
TILT = -25.0
STROKES = [(1.4, 2.5, 20.0), (3.0, 4.1, 13.0), (4.6, 5.7, 7.0)]
TUFTS_PER_STROKE = 9
GRAVITY = 1600.0
EYE = (9.0, -15.0, yak.LEG_HEIGHT + 33.0)
EYE_RADIUS = 2.2


def smooth_shaded(obj, material):
    obj.data.set_sharp_from_angle(angle=math.radians(35))
    obj.data.polygons.foreach_set("use_smooth", [True] * len(obj.data.polygons))
    obj.data.materials.clear()
    obj.data.materials.append(material)


def build_razor():
    """A safety razor with its handle bottom at the origin: fluted ink handle, peach band, T-bar head 14 wide."""
    neck = 14.0
    handle = yak.cylinder("razor", HANDLE_RADIUS, 19.0, (0, 0, 7.5), segments=48)
    for index in range(12):
        angle = index * math.pi / 6
        flute = yak.cylinder("flute", 0.55, neck - 3.0, ((HANDLE_RADIUS + 0.1) * math.cos(angle), (HANDLE_RADIUS + 0.1) * math.sin(angle), (2.0 + neck - 1.0) / 2), segments=16)
        yak.boolean(handle, flute, "DIFFERENCE")
        yak.remove(flute)
    half = BLADE_HALF_WIDTH
    bar = yak.prism("bar", [(-half, neck), (half, neck), (half, neck + 3.0), (-half, neck + 3.0)], "Y", -2.0, 2.0)
    yak.boolean(handle, bar, "UNION")
    yak.remove(bar)
    for offset in (-1.1, 1.1):
        slot = yak.prism("slot", [(-half + 1.0, neck + 1.7), (half - 1.0, neck + 1.7), (half - 1.0, neck + 3.5), (-half + 1.0, neck + 3.5)], "Y", offset - 0.4, offset + 0.4)
        yak.boolean(handle, slot, "DIFFERENCE")
        yak.remove(slot)
    center = (0, 0, 8.2)
    cutter = yak.cylinder("cutter", HANDLE_RADIUS + 0.4, 3.0, center, segments=48)
    inner = yak.cylinder("ring_inner", HANDLE_RADIUS - 0.8, 4.0, center, segments=48)
    yak.boolean(cutter, inner, "DIFFERENCE")
    yak.boolean(handle, cutter, "DIFFERENCE")
    yak.remove(cutter)
    band = yak.cylinder("razor_band", HANDLE_RADIUS, 3.0, center, segments=48)
    yak.boolean(band, inner, "DIFFERENCE")
    yak.remove(inner)
    return handle, band


def hand_local(side, swing, raise_degrees, stretch):
    """The arm tip in upper-body coordinates, mirroring Rig.pose."""
    shoulder = Vector((side * SHOULDER[0], SHOULDER[1], SHOULDER[2]))
    tip = Vector((side * HAND[0], HAND[1], HAND[2] - 3.8)) - shoulder
    tip.z *= stretch
    turn = rotation("Y", -side * raise_degrees) @ rotation("X", -swing)
    return shoulder + turn.to_3x3() @ tip


def reach(side, target, guess=(80.0, 0.0, 1.2)):
    """Arm (swing, raise, 0, stretch) that puts the tip at target (upper-body coordinates), by Gauss-Newton."""
    x = list(guess)
    low, high = (0.0, -40.0, 1.0), (180.0, 90.0, 1.8)
    for _ in range(40):
        error = target - hand_local(side, *x)
        if error.length < 0.05:
            break
        columns = []
        for index, step in enumerate((0.5, 0.5, 0.01)):
            shifted = list(x)
            shifted[index] += step
            columns.append((hand_local(side, *shifted) - hand_local(side, *x)) / step)
        jacobian = Matrix(columns).transposed()
        try:
            delta = jacobian.inverted() @ error
        except ValueError:
            break
        for index, gain in enumerate((0.8, 0.8, 0.8)):
            x[index] = min(max(x[index] + delta[index] * gain, low[index]), high[index])
    return (x[0], x[1], 0.0, x[2])


def blade_local(t):
    """The blade centre in head coordinates while shaving."""
    xs, ys, zs = [(0.5, 20.0)], [(0.5, BLADE_Y_OFF)], [(0.5, 59.0)]
    for index, (start, end, centre) in enumerate(STROKES):
        xs += [(start, centre), (end, centre)]
        ys += [(start - 0.2, BLADE_Y_OFF), (start, BLADE_Y_ON), (end, BLADE_Y_ON), (end + 0.2, BLADE_Y_OFF)]
        zs += [(start, 59.0), (end, 35.5)]
        if index + 1 < len(STROKES):
            zs.append((STROKES[index + 1][0] - 0.1, 59.0))
    xs.append((6.3, STROKES[-1][2]))
    ys.append((6.3, BLADE_Y_OFF))
    zs.append((6.3, 42.0))
    return Vector((keys(t, xs), keys(t, ys), keys(t, zs)))


def work_weight(t):
    return smoothstep((t - 0.5) / 0.9) * (1 - smoothstep((t - 6.3) / 0.8))


def body_params(t):
    return dict(
        bend=keys(t, [(0.5, 0), (1.2, 9), (5.9, 9), (6.6, 0)]),
        side_bend=keys(t, [(1.4, 0), (2.0, -2), (3.0, 0), (3.6, -2), (4.6, 0), (5.2, -2), (5.9, 0)]),
        lift=keys(t, [(6.7, 0), (6.9, 6), (7.15, 1), (7.35, 4), (7.6, 2)]),
        horn_droop=keys(t, [(6.7, 0), (6.9, -8), (7.2, 3), (7.6, 0)]),
        arm_l=(0, 3),
    )


def tuft_state(tuft, now):
    """Position of one falling tuft at time now, by stepping a bouncing particle from its birth."""
    steps = int((now - tuft["birth"]) * 240)
    position, velocity = Vector(tuft["start"]), Vector(tuft["velocity"])
    for _ in range(steps):
        velocity.z -= GRAVITY / 240
        velocity.x *= 0.995
        velocity.y *= 0.995
        position += velocity / 240
        if position.z < 1.0 and velocity.z < 0:
            position.z = 1.0
            velocity.z = -velocity.z * 0.3
            if abs(velocity.z) < 25:
                velocity.z = 0
            velocity.x *= 0.6
            velocity.y *= 0.6
    return position


def setup(rig):
    rig.pose()
    rest_hand = rig.hand(1).copy()
    rig.rest_blade = rest_hand + Vector((0, 0, BLADE_REACH))

    ink, cream = yak.COLOURS["ink"], yak.COLOURS["cream"]
    razor, band = build_razor()
    smooth_shaded(razor, yak.material("razor", ink))
    smooth_shaded(band, yak.material("razor_band", yak.COLOURS["peach"]))
    rig.razor_parts = (razor, band)

    eye = yak.cylinder("eye", EYE_RADIUS, 1.4, EYE, axis="Y")
    smooth_shaded(eye, yak.material("eye", ink))
    rig.eye = eye

    rig.cutters = []
    for name in ("shave_pass", "shave_done"):
        bpy.ops.mesh.primitive_cube_add(size=2)
        cutter = bpy.context.object
        cutter.name = name
        cutter.display_type = "WIRE"
        cutter.hide_render = True
        modifier = rig.fringe.modifiers.new(name, "BOOLEAN")
        modifier.operation, modifier.object, modifier.solver = "DIFFERENCE", cutter, "MANIFOLD"
        rig.cutters.append((cutter, modifier))

    mesh = yak.capsule("tuft_source", 1.3, (0, 0, 0), (1.2, 0, 0)).data
    for obj in [o for o in bpy.data.objects if o.name.startswith("tuft_source")]:
        bpy.data.objects.remove(obj, do_unlink=True)
    mesh.materials.append(rig.fringe.data.materials[0])
    rig.tufts = []
    for index, (start, end, centre) in enumerate(STROKES):
        for number in range(TUFTS_PER_STROKE):
            chance = random.Random(index * 100 + number)
            birth = start + (number + 0.5) / TUFTS_PER_STROKE * (end - start)
            rig.pose(**body_params(birth))
            origin = rig.cap @ Vector((centre + chance.uniform(-6, 6), BLADE_Y_ON - 1.0, blade_local(birth).z - 2.0))
            obj = bpy.data.objects.new(f"tuft{index}_{number}", mesh)
            bpy.context.collection.objects.link(obj)
            rig.tufts.append((obj, {
                "birth": birth, "start": tuple(origin), "yaw": chance.uniform(0, 180),
                "velocity": (chance.uniform(2, 14), chance.uniform(-12, 4), chance.uniform(-10, 10)),
                "size": chance.uniform(1.2, 1.9),
            }))


def animate(rig, frame):
    t = frame / 24
    body = body_params(t)
    rig.pose(**body)
    weight = work_weight(t)
    blade = rig.rest_blade.lerp(rig.cap @ blade_local(t), weight)
    tilt = TILT * weight
    hand = blade - rotation("X", tilt).to_3x3() @ Vector((0, 0, BLADE_REACH))
    arm = reach(1, rig.upper.inverted() @ hand)
    rig.pose(arm_r=arm, **body)
    for part in rig.razor_parts:
        part.matrix_world = translate(*rig.hand(1)) @ rotation("Y", 30.0 * (1 - weight)) @ rotation("X", tilt)

    squint = keys(t, [(6.6, 1.0), (6.9, 0.3), (7.8, 0.3)])
    rig.eye.matrix_world = rig.upper @ about(EYE, Matrix.Diagonal((1, 1, squint, 1)))

    finished = None
    for index, (start, end, centre) in enumerate(STROKES):
        left = centre - BLADE_HALF_WIDTH
        if t > end:
            finished = left
        if start <= t <= end:
            active = (left, blade_local(t).z - 1.5)
    sage = rig.horns[1]
    drop = 1.5 * smoothstep((t - 3.6) / 0.5)
    sage.matrix_world = rig.cap @ translate(z=-drop) @ rig.cap.inverted() @ sage.matrix_world

    for (cutter, modifier), box in zip(rig.cutters, (
            (active[0], 80.0, active[1], 80.0) if any(s <= t <= e for s, e, _ in STROKES) else None,
            (finished, 80.0, 20.0, 80.0) if finished is not None else None)):
        modifier.show_render = modifier.show_viewport = box is not None
        if box is not None:
            left, right, bottom, top = box
            centre = Vector(((left + right) / 2, 0, (bottom + top) / 2))
            half = Vector(((right - left) / 2, 30, (top - bottom) / 2))
            cutter.matrix_world = rig.cap @ translate(*centre) @ Matrix.Diagonal((*half, 1))

    for obj, tuft in rig.tufts:
        obj.hide_render = obj.hide_viewport = t < tuft["birth"]
        if t >= tuft["birth"]:
            obj.matrix_world = (translate(*tuft_state(tuft, t)) @ rotation("Z", tuft["yaw"])
                                @ Matrix.Diagonal((tuft["size"], tuft["size"], tuft["size"], 1)))
