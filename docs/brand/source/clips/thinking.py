"""Thinking: the yak ponders with a hand under the muzzle, the body tilted to one side, a slow foot tap,
a drifting fringe and three ink thought dots that rise and fade in turn. The first and last frame are the same pose."""

import math

import bpy
from mathutils import Matrix, Vector

import cursor_yak as yak
from mascot_clips import keys

FRAMES = 121
LOOP_SECONDS = 5.0
CAMERA = {"look_at": (0, 0, 40), "direction": (0.3, -1.0, 0.18), "height": 135.0}
DOT_RADIUS = (2.4, 3.2, 4.2)
DOT_OFFSET = ((17.0, 9.0), (24.0, 17.0), (32.0, 27.0))


def build_dots():
    dots = []
    for index, radius in enumerate(DOT_RADIUS):
        bpy.ops.mesh.primitive_uv_sphere_add(segments=32, ring_count=16, radius=1.0)
        dot = bpy.context.object
        dot.name = f"thought_dot{index}"
        dot.visible_shadow = False
        dot.data.materials.append(yak.material(f"thought_dot{index}", yak.COLOURS["ink"]))
        bpy.ops.object.shade_smooth()
        dots.append(dot)
    return dots


def setup(rig):
    rig.dots = build_dots()


def pose_values(t):
    """Keyword arguments of the pondering pose at time t seconds; periodic over LOOP_SECONDS."""
    cycle = 2 * math.pi * t / LOOP_SECONDS
    slow = math.sin(cycle * 2)
    tap = max(0.0, math.sin(cycle * 5 - 0.4)) ** 2
    return dict(
        roll=-1.5 + 0.8 * math.sin(cycle),
        side_bend=9.0 + 1.2 * math.sin(cycle),
        bend=3.0,
        twist=-4.0,
        nod=-1.0 + 1.0 * math.sin(cycle * 2 - 0.5),
        tilt=-2.0 + 1.0 * slow,
        lift=3.0 + 2.5 * math.sin(cycle * 3 - 1.0),
        horn_droop=5.0 * math.sin(cycle * 2 - 1.3),
        arm_l=(0.0, 6.0 + 1.5 * slow),
        arm_r=(56.0, 0.0, 40.0 + 2.0 * slow, 1.7),
        leg_l=(0.0, 0.0, 0.0),
        leg_r=(8.0 * tap, 0.0, 2.2 * tap, 12.0 * tap),
    )


def pose_at(rig, t):
    rig.pose(**pose_values(t))


def place_dots(rig, t):
    camera = rig.camera.matrix_world.to_3x3()
    right, up = camera.col[0], camera.col[1]
    anchor = Vector(rig.upper @ Vector((0, -1, 62)))
    for index, dot in enumerate(rig.dots):
        start = 0.5 + 0.7 * index
        grow = keys(t, [(start, 0.0), (start + 0.4, 1.0), (start + 0.55, 0.92), (start + 0.7, 1.0)])
        fade = keys(t, [(3.7 + 0.15 * index, 1.0), (4.4 + 0.15 * index, 0.0)])
        size = grow * fade * DOT_RADIUS[index]
        drift = 1.5 * math.sin(2 * math.pi * (t - start) / LOOP_SECONDS * 2)
        dx, dy = DOT_OFFSET[index]
        centre = anchor + right * dx + up * (dy + drift * (index + 1) / 3)
        dot.matrix_world = Matrix.Translation(centre) @ Matrix.Diagonal((max(size, 1e-4),) * 3 + (1,))
        dot.hide_render = size < 1e-3


def animate(rig, frame):
    t = frame / 24
    pose_at(rig, t)
    place_dots(rig, t)
