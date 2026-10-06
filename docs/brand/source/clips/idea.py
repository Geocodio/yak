"""Idea: the yak ponders, jolts upright, the fringe pops, a light bulb switches on above the head, the yak points
at it and hops with a squash on landing, then holds a happy pose with the bulb glowing."""

import math

import bpy
from mathutils import Matrix, Vector

import cursor_yak as yak
from clips.thinking import pose_values
from mascot_clips import facing_camera, keys, material, rotation, srgb
from mascot_motion import ease_out_back

FRAMES = 96
CAMERA = {"look_at": (0, 0, 48), "direction": (0.3, -1.0, 0.18), "height": 145.0}
GLASS = srgb("#eb9b12")
RAY = srgb("#2f3a42")
RAY_COUNT = 8
BULB_CENTRE = (2.0, -1.0, 90.0)
BULB_RADIUS = 9.0


def setup(rig):
    bpy.ops.mesh.primitive_uv_sphere_add(segments=48, ring_count=24, radius=1.0)
    rig.bulb = bpy.context.object
    rig.bulb.name = "bulb"
    rig.bulb.visible_shadow = False
    bpy.ops.object.shade_smooth()
    rig.glass = material("bulb_glass", GLASS, emission=0.0, roughness=0.15)
    rig.bulb.data.materials.append(rig.glass)
    rig.base = yak.cylinder("bulb_base", 3.6, 5.5, (0, 0, 0))
    rig.base.data.materials.append(yak.material("bulb_base", yak.COLOURS["ink"]))
    rig.base.visible_shadow = False
    rig.rays = []
    ray_material = material("bulb_ray", RAY, roughness=0.6)
    for index in range(RAY_COUNT):
        ray = yak.cylinder(f"bulb_ray{index}", 0.7, 1.0, (0, 0.5, 0), axis="Y", segments=12)
        ray.data.materials.append(ray_material)
        ray.visible_shadow = False
        rig.rays.append(ray)


def blend(a, b, weight):
    if isinstance(a, tuple):
        return tuple(blend(x, y, weight) for x, y in zip(a, b))
    return a + (b - a) * weight


def pose_values_at(t):
    thinking = pose_values(0.0)
    jolt = keys(t, [(0.9, 0.0), (1.1, 1.0)])
    pop = ease_out_back((t - 1.0) / 0.4, 2.0)
    point = ease_out_back((t - 1.35) / 0.45, 1.2)
    happy = dict(
        roll=0.0, side_bend=-2.0 * keys(t, [(1.1, 0), (1.6, 1)]), bend=-3.0, twist=0.0, nod=0.0, tilt=0.0,
        lift=keys(t, [(1.0, 0), (1.15, 16), (1.5, 9), (2.1, 8), (2.4, 13), (2.8, 7), (3.2, 9)]) * 1.0,
        horn_droop=7.0 * math.sin(max(0.0, t - 1.1) * 9.0) * math.exp(-max(0.0, t - 1.1) * 1.4),
        arm_l=(0.0, 40.0 + 10.0 * keys(t, [(2.2, 0), (2.6, 1), (3.0, 0)])),
        arm_r=(0.0, 168.0, 0.0, 1.5),
        leg_l=(0.0, 0.0, 0.0), leg_r=(0.0, 0.0, 0.0),
    )
    values = {}
    for name, value in happy.items():
        values[name] = blend(thinking[name], value, jolt)
    arm_from, arm_to = thinking["arm_r"], happy["arm_r"]
    values["arm_r"] = blend(arm_from, arm_to, min(max(point, 0.0), 1.25) if point < 1 else point)
    anticipation = keys(t, [(0.0, 0), (0.55, 0), (0.85, 1), (1.0, 0)])
    squash = keys(t, [(0.0, 0), (0.7, -0.04), (0.9, -0.05), (1.05, 0.06), (1.35, 0.0), (1.95, 0.0),
                      (2.2, -0.08), (2.32, 0.05), (2.55, 0.03), (2.8, 0.0), (2.9, -0.1), (3.15, 0.0)])
    values.update(
        squash=squash, bend=values["bend"] + 5.0 * anticipation,
        z=keys(t, [(2.2, 0.0), (2.32, 0.0), (2.58, 7.0), (2.86, 0.0)]),
        hip_drop=1.2 * keys(t, [(2.0, 0), (2.2, 1), (2.32, 0), (2.8, 0), (2.9, 1), (3.15, 0)]),
    )
    return values


def place_bulb(rig, t):
    appear = ease_out_back((t - 1.05) / 0.4, 2.2)
    glow = keys(t, [(1.05, 0.0), (1.4, 0.35), (1.6, 0.12), (2.0, 0.15), (3.0, 0.15)])
    rig.glass.node_tree.nodes["Principled BSDF"].inputs["Emission Color"].default_value = (*GLASS, 1)
    rig.glass.node_tree.nodes["Principled BSDF"].inputs["Emission Strength"].default_value = glow
    visible = appear > 0.01
    for obj in [rig.bulb, rig.base, *rig.rays]:
        obj.hide_render = not visible
    if not visible:
        return
    centre = Vector(rig.upper @ Vector(BULB_CENTRE))
    spin = Matrix.Diagonal((1, 1, 1, 1))
    rig.bulb.matrix_world = Matrix.Translation(centre) @ Matrix.Diagonal((BULB_RADIUS * appear,) * 3 + (1,))
    rig.base.matrix_world = (Matrix.Translation(centre + Vector((0, 0, -BULB_RADIUS * 0.95 * appear)))
                             @ Matrix.Diagonal((appear,) * 3 + (1,)))
    flash = keys(t, [(1.15, 0.0), (1.35, 1.0), (1.8, 0.0)])
    for index, ray in enumerate(rig.rays):
        angle = index * 360.0 / RAY_COUNT + 22.5
        length = 8.0 * flash
        ray.matrix_world = (facing_camera(rig.camera, centre, 1.0, angle)
                            @ Matrix.Translation((0, BULB_RADIUS * 1.35 + 3.0 * flash, 0))
                            @ Matrix.Diagonal((1, max(length, 1e-4), 1, 1)))
        ray.hide_render = flash < 0.02


def animate(rig, frame):
    t = frame / 24
    rig.pose(**pose_values_at(t))
    place_bulb(rig, t)
