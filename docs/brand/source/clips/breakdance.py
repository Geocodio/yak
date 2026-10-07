"""Breakdance at 120 bpm (12 frames per beat): toprock, a drop to the back, a three-turn backspin with the legs kicked
up, a pop to the feet, a tilted freeze held for 0.6 s, then a brush-off and rest."""

import math

import numpy as np

from mascot_clips import keys, rotation

FRAMES = 204
CAMERA = {"direction": (0.3, -1.0, 0.2), "look_at": (0, 0, 34), "height": 100.0}
BEAT_FRAMES = 12
SPIN_TURNS = 3
BODY_CENTRE = 30.0


def setup(rig):
    parts = [rig.torso, rig.fringe, rig.muzzle, *rig.legs.values(), *rig.horns.values()]
    rig.floor_parts = []
    for part in parts:
        vertices = np.empty(len(part.data.vertices) * 3)
        part.data.vertices.foreach_get("co", vertices)
        rig.floor_parts.append((part, vertices.reshape(-1, 3)))


def lowest_point(rig):
    """Lowest world z over the body, legs and horns after the current pose; the arms may reach the floor."""
    low = 1e9
    for part, vertices in rig.floor_parts:
        matrix = np.array(part.matrix_world)
        low = min(low, float((vertices @ matrix[:3, :3].T)[:, 2].min() + matrix[2, 3]))
    return low


def smooth(u):
    u = min(max(u, 0.0), 1.0)
    return u * u * (3 - 2 * u)


def lying(b):
    """1 while on the back, with a small bounce as it lands and a kick-up when it pops to its feet."""
    return keys(b, [(5.4, 0), (5.8, 1.03), (6.0, 1), (11.0, 1), (11.45, 0.45), (11.8, -0.06), (12.0, 0)])


def parameters(b):
    on_beat = abs(math.sin(math.pi * b))
    step = math.cos(math.pi * (b - 1))
    toprock = keys(b, [(0.8, 0), (1.4, 1), (4.6, 1), (5.0, 0)])
    crouch = keys(b, [(5.0, 0), (5.35, 1), (5.55, 0.6), (6.0, 0)])
    flat = lying(b)
    arms_out = keys(b, [(5.4, 0), (6.0, 1), (11.0, 1), (11.4, 0)])
    freeze = keys(b, [(11.7, 0), (12.0, 1), (12.15, 1.07), (12.4, 1), (13.25, 1), (13.9, 0)])
    brush_r = keys(b, [(13.9, 0), (14.2, 100), (14.5, 30), (14.75, 100), (15.0, 30), (15.4, 0)])
    brush_l = keys(b, [(14.5, 0), (14.8, 100), (15.1, 30), (15.35, 100), (15.6, 30), (16.0, 0)])
    brush = max(brush_r, brush_l) / 100
    spin_progress = (b - 6.0) / 5.0
    spin = 360 * SPIN_TURNS * (0.5 * smooth(spin_progress) + 0.5 * min(max(spin_progress, 0.0), 1.0))
    wobble = math.sin(math.radians(spin) * 2)
    in_air = math.sin(math.pi * min(max((b - 11.15) / 0.75, 0.0), 1.0))

    lean = -90 * flat
    flat_part = max(0.0, min(1.0, flat))
    side = max(0.0, 1 - arms_out)
    p = dict(
        x=5 * step * toprock,
        z=1.6 * on_beat * toprock + 7 * in_air + 0.6 * flat_part * on_beat * abs(wobble) * 0,
        y=-BODY_CENTRE * math.sin(math.radians(-lean)),
        lean=lean + 3 * wobble * flat_part,
        roll=-7 * step * toprock - 26 * freeze,
        twist=-18 * step * toprock + 40 * crouch * 0 - 8 * freeze + 5 * math.sin(2 * math.pi * b) * brush,
        bend=4 * toprock + 20 * crouch + 14 * flat_part * (0.5 + 0.5 * wobble) + 8 * freeze,
        side_bend=-12 * freeze,
        squash=-0.07 * (1 - on_beat) * toprock - 0.18 * crouch - 0.08 * freeze,
        hip_drop=4 * crouch + 3 * freeze,
        lift=3 * (1 - on_beat) * toprock + 4 * flat_part + 3 * freeze,
        horn_droop=8 * math.sin(math.pi * b - 1.0) * toprock + 6 * math.sin(math.radians(spin) * 2 - 0.8) * flat_part,
        turn=-12 * freeze,
    )
    arm_l = [50 * step * toprock + 30 * crouch, 15 * toprock + 90 * arms_out, 0, 1.0]
    arm_r = [-50 * step * toprock + 30 * crouch, 15 * toprock + 90 * arms_out, 0, 1.0]
    arm_l[0] += 25 * arms_out
    arm_r[0] += 25 * arms_out
    arm_l = [arm_l[0] * (1 - freeze) + 30 * freeze, arm_l[1] * (1 - freeze) + 45 * freeze, 0, 1 + 0.8 * freeze]
    arm_r = [arm_r[0] * (1 - freeze) + 20 * freeze, arm_r[1] * (1 - freeze) + 130 * freeze, 0, 1 + 0.5 * freeze]
    arm_r[0] += brush_r
    arm_r[2] = 30 * brush_r / 100
    arm_l[0] += brush_l
    arm_l[2] = 30 * brush_l / 100
    p["arm_l"], p["arm_r"] = tuple(arm_l), tuple(arm_r)
    kick = 36 * arms_out
    p["leg_l"] = (15 * step * toprock + kick + 28 * freeze, 18 * arms_out + 12 * freeze, 3 * max(0.0, -step) * toprock, 12 * freeze)
    p["leg_r"] = (-15 * step * toprock + kick - 22 * freeze, 18 * arms_out + 8 * freeze, 3 * max(0.0, step) * toprock, 0)
    return p, spin


def animate(rig, frame):
    p, spin = parameters(frame / BEAT_FRAMES)
    base = rotation("Z", spin)
    rig.pose(base=base, **p)
    floor = lowest_point(rig)
    p["z"] -= floor if abs(floor) > 0.02 else 0.0
    rig.pose(base=base, **p)


def camera(frame):
    b = frame / BEAT_FRAMES
    high = keys(b, [(5.3, 0), (6.3, 1), (10.8, 1), (11.8, 0)])
    return {"direction": (0.3, -1.0, 0.2 + 0.4 * high), "look_at": (0, 0, 34 - 12 * high)}
