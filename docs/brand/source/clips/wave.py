"""Wave: a small anticipation dip, then the right arm goes up and out and waves four times while the body sways,
with a hop on the first wave; the arm comes back down and the yak settles at rest."""

import math

from mascot_clips import keys

FRAMES = 96
WAVES_PER_SECOND = 2.0
WAVE_START, WAVE_END = 1.0, 3.0
CAMERA = {"direction": (0.25, -1.0, 0.16), "look_at": (4, 0, 38), "height": 90.0}


def animate(rig, frame):
    t = frame / 24
    # 0 at rest, 1 with the arm fully up; the dip pulls the arm back a touch first.
    arm_up = keys(t, [(0.0, 0), (0.45, 0), (0.62, -0.12), (1.0, 1.0), (1.12, 1.06), (1.25, 1.0), (3.0, 1.0), (3.5, 0), (4.0, 0)])
    # The wave swing fades in after the arm arrives and out before it comes down.
    wave_amount = keys(t, [(0.0, 0), (WAVE_START, 0), (WAVE_START + 0.2, 1), (WAVE_END - 0.1, 1), (WAVE_END + 0.2, 0)])
    phase = 2 * math.pi * WAVES_PER_SECOND * (t - WAVE_START)
    rock = wave_amount * math.sin(phase)
    sway = wave_amount * math.sin(phase / 2 - 0.4)
    dip = keys(t, [(0.0, 0), (0.4, 0), (0.68, 1), (0.95, 0), (4.0, 0)])
    hop = keys(t, [(0.0, 0), (0.95, 0), (1.12, 4.5), (1.34, 0), (1.42, -0.0), (4.0, 0)])
    landing = keys(t, [(0.0, 0), (1.3, 0), (1.4, 1), (1.65, 0), (4.0, 0)])
    settle = keys(t, [(0.0, 0), (3.4, 0), (3.55, 1), (3.85, 0), (4.0, 0)])
    rig.pose(
        z=hop,
        squash=-0.07 * dip - 0.05 * landing - 0.04 * settle + 0.03 * keys(t, [(0.0, 0), (0.9, 0), (1.05, 1), (1.3, 0), (4.0, 0)]),
        hip_drop=1.2 * dip + 0.8 * landing,
        side_bend=-5.0 * sway - 3.0 * arm_up, roll=-1.5 * sway, twist=-6.0 * arm_up + 2.0 * rock,
        bend=3.0 * dip,
        lift=max(0.0, 2.5 * arm_up + 2.0 * wave_amount * (0.5 + 0.5 * math.sin(2 * phase - 1.0)) + 5.0 * keys(t, [(0.0, 0), (0.9, 0), (1.15, 1), (1.5, 0), (4.0, 0)])),
        horn_droop=-4.0 * rock + 3.0 * landing,
        arm_r=(10.0 * arm_up + 8.0 * rock, 150.0 * arm_up + 14.0 * rock, 6.0 * arm_up + 10.0 * math.sin(phase + math.pi / 2) * wave_amount, 1.0 + 0.4 * arm_up),
        arm_l=(-4.0 * dip, 8.0 * arm_up, 0.0),
        leg_l=(0, 0, 0, 0), leg_r=(0, 0, 0, 0),
    )
