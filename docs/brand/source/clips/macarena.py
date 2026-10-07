"""Macarena: one full cycle at 103 bpm. Right arm out, left arm out, two palm flips, hands to opposite shoulders, to the
back of the head and to the opposite hips (one move per beat), three hip swirls, then a jump with a full spin and a squash
landing. Starts and ends at rest facing the camera."""

import math

from mascot_clips import keys

FRAMES = 240
CAMERA = {"height": 115.0, "look_at": (0, 0, 44), "direction": (0.45, -1.0, 0.12)}
BEAT = 60 / 103
START = 0.5
OUT = (88, 0, -28, 1.7)
FLIP = (100, 0, -45, 1.7)
SHOULDER = (85, 0, 70, 1.7)
HEAD = (25, 150, 0, 1.7)
HIP = (15, 45, 0, 1.3)
UP = (0, 165, 0, 1.5)
REST = (0, 0, 0, 1)
# (beat, right arm, left arm); each pose is reached on its beat and held for a short moment.
MOVES = [
    (0, OUT, REST), (1, OUT, OUT), (2, FLIP, OUT), (3, FLIP, FLIP), (4, SHOULDER, FLIP), (5, SHOULDER, SHOULDER),
    (6, HEAD, SHOULDER), (7, HEAD, HEAD), (8, HIP, HEAD), (9, HIP, HIP),
]
SWIRL_START, JUMP_BEAT = 10, 13.1
AIR = 0.5


def beat_time(beat):
    return START + beat * BEAT


def arm(t, which):
    """One arm's (swing, raise, cross, stretch) over time."""
    poses = [(-1, REST, REST)] + [(beat, right, left) for beat, right, left in MOVES]
    channels = []
    for component in range(4):
        frames = []
        for beat, right, left in poses:
            pose = (right, left)[which][component]
            start = beat_time(beat) if beat >= 0 else 0
            frames += [(start, pose), (start + 0.3, pose)]
        # The arms go up for the jump and come back down to rest afterwards.
        takeoff = beat_time(JUMP_BEAT)
        frames += [(takeoff - 0.1, HIP[component]), (takeoff + 0.15, UP[component]), (takeoff + AIR, UP[component]),
                   (takeoff + AIR + 0.35, REST[component]), (11, REST[component])]
        channels.append(keys(t, frames))
    return tuple(channels)


def animate(rig, frame):
    t = frame / 24
    beat = (t - START) / BEAT
    # Small bounce on every beat while the arms work.
    bounce = max(0.0, 1 - ((beat % 1) * 2)) if 0 <= beat < SWIRL_START else 0
    working = keys(t, [(0, 0), (START, 0), (START + 0.3, 1), (beat_time(SWIRL_START), 1), (beat_time(SWIRL_START) + 0.2, 0)])
    hip_drop = 0.8 * bounce * working

    # Hip swirls: one circle per beat, starting from the right so they begin at rest.
    swirl_t = (t - beat_time(SWIRL_START)) / BEAT
    swirl_amount = keys(t, [(beat_time(SWIRL_START) - 0.01, 0), (beat_time(SWIRL_START) + 0.25, 1),
                            (beat_time(JUMP_BEAT) - 0.55, 1), (beat_time(JUMP_BEAT) - 0.15, 0)])
    angle = 2 * math.pi * swirl_t
    roll = 10 * swirl_amount * math.sin(angle)
    lean = 8 * swirl_amount * math.cos(angle)
    twist = -12 * swirl_amount * math.sin(angle)
    swirl_drop = 1.0 * swirl_amount * (1 + math.cos(2 * angle)) / 2

    # Jump: crouch, launch, a full spin in the air, squash on landing.
    takeoff = beat_time(JUMP_BEAT)
    crouch = keys(t, [(takeoff - 0.45, 0), (takeoff, 1), (takeoff + 0.08, 0)])
    airborne = max(0.0, min(1.0, (t - takeoff) / AIR))
    height = 16 * 4 * airborne * (1 - airborne) if 0 < airborne < 1 else 0
    spin = 360 * keys(t, [(takeoff - 0.05, 0), (takeoff + 0.08, 0), (takeoff + AIR - 0.04, 1)])
    land = keys(t, [(takeoff + AIR - 0.02, 0), (takeoff + AIR + 0.1, 1), (takeoff + AIR + 0.45, 0)])
    stretch = 0.12 * keys(t, [(takeoff + 0.05, 0), (takeoff + 0.2, 1), (takeoff + AIR - 0.15, 1), (takeoff + AIR - 0.04, 0)])
    squash = -0.2 * crouch - 0.22 * land + stretch
    legs = 10 * (crouch + land)
    droop = 10 * keys(t, [(takeoff + 0.1, 0), (takeoff + 0.3, 1), (takeoff + AIR + 0.2, -0.6), (takeoff + AIR + 0.6, 0)])
    lift = 6 * keys(t, [(takeoff + 0.1, 0), (takeoff + 0.3, 1), (takeoff + AIR + 0.2, 0)])

    right, left = arm(t, 0), arm(t, 1)
    rig.pose(
        z=height, turn=spin, roll=roll, lean=lean, twist=twist,
        hip_drop=hip_drop + swirl_drop,
        squash=squash, lift=lift, horn_droop=droop,
        arm_r=right, arm_l=left,
        leg_l=(0, legs * 0.3, 0, 0), leg_r=(0, legs * 0.3, 0, 0),
    )
