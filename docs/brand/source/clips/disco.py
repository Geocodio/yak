"""Disco: the Saturday Night Fever point at 150 bpm. The right arm points up and out, sweeps across the body to the floor
on the opposite side, four times on the beat with hip sway and step-touches, then the left arm does the same four times.
Coloured party lights pulse in brand colours on the beat and fade back to the normal rig at the end."""

import math

import bpy
from mathutils import Vector

from mascot_clips import keys, srgb

FRAMES = 192
CAMERA = {"height": 105.0, "look_at": (0, 0, 33), "direction": (0.3, -1.0, 0.14)}
BEAT = 60 / 150
START = 0.5
UP = (30, 145, 0, 1.7)
DOWN = (22, 0, 50, 1.8)
REST = (0, 0, 0, 1)
# Saturated club colours: pastel tints only brighten the cream fringe, these read as coloured light.
PALETTE = ["#ff2e88", "#00b8ff", "#ffb000", "#8a3dff", "#00d68f", "#ff5a1f"]
LAST_BEAT = 16


def beat_time(beat):
    return START + beat * BEAT


def setup(rig):
    rim = bpy.data.objects["rim"]
    rig.rim_normal = (tuple(rim.data.color), rim.data.energy)
    rig.key_energy = bpy.data.objects["key"].data.energy
    rig.party = []
    for name, location in (("party_left", (-110, -70, 80)), ("party_right", (130, -90, 50)), ("party_top", (0, -60, 150))):
        data = bpy.data.lights.new(name, "AREA")
        data.size, data.energy = 70, 0
        light = bpy.data.objects.new(name, data)
        light.location = location
        light.rotation_euler = (Vector((0, 0, 30)) - Vector(location)).to_track_quat("-Z", "Y").to_euler()
        bpy.context.collection.objects.link(light)
        rig.party.append(light)


def arm(t, first_beat):
    """One arm: up on every even beat from first_beat, down on every odd beat, four sweeps, then back to rest."""
    channels = []
    for component in range(4):
        frames = [(beat_time(first_beat) - 0.6, REST[component])]
        for sweep in range(4):
            for step, pose in enumerate((UP, DOWN)):
                start = beat_time(first_beat + 2 * sweep + step)
                frames += [(start, pose[component]), (start + 0.1, pose[component])]
        frames += [(beat_time(first_beat + 8) + 0.3, REST[component]), (12, REST[component])]
        channels.append(keys(t, frames))
    return tuple(channels)


def animate(rig, frame):
    t = frame / 24
    beat = (t - START) / BEAT
    active = keys(t, [(START - 0.1, 0), (START + 0.25, 1), (beat_time(LAST_BEAT) - 0.1, 1), (beat_time(LAST_BEAT) + 0.5, 0)])
    # Sway and step-touch alternate every beat, leaning towards the side of the raised arm.
    side = 1 if beat < 8 else -1
    sway = math.cos(math.pi * beat) * active
    step = math.sin(math.pi * beat)
    bump = max(0.0, 1 - (beat % 1) * 2.5) * active
    foot_a = max(0.0, step) * active
    foot_b = max(0.0, -step) * active

    right = arm(t, 0)
    left = arm(t, 8)
    rig.pose(
        x=3.5 * side * sway * -1, roll=-6 * side * sway, twist=-7 * side * sway, side_bend=3 * side * sway,
        hip_drop=1.0 * bump, lift=2.0 * bump, horn_droop=6 * math.sin(math.pi * beat - 0.8) * active,
        arm_r=right, arm_l=left,
        leg_l=(0, 9 * foot_a, 1.5 * foot_a, 0), leg_r=(0, 9 * foot_b, 1.5 * foot_b, 0),
    )

    # Two-tone club lighting: one colour from the left, its partner from the right, swapping sides on every beat.
    # The top light stays off and the key dims, so the side colours read instead of adding up to white.
    step = int(max(0, beat))
    pair = [Vector(srgb(PALETTE[(step // 2) % 3])), Vector(srgb(PALETTE[3 + (step // 2) % 3]))]
    if step % 2:
        pair.reverse()
    pulse = 0.6 + 0.4 * max(0.0, 1 - (beat % 1) * 1.5)
    for index, light in enumerate(rig.party):
        light.data.color = tuple(pair[min(index, 1)])
        light.data.energy = (0.0 if light.name == "party_top" else 6e4) * pulse * active
    bpy.data.objects["key"].data.energy = rig.key_energy * (1 - 0.6 * active)
    rim = bpy.data.objects["rim"].data
    rim.color = tuple(Vector(rig.rim_normal[0]).lerp(pair[1], 0.8 * active))
    rim.energy = rig.rim_normal[1]
