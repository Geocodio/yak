"""Moonwalk: the yak turns to a three-quarter profile, glides backwards from right to left on alternating flat and
heel-up feet with a slight lean and pumping arms, then spins back to the camera and holds a toe stand with one arm raised."""

import math

from mascot_clips import keys

FRAMES = 168
CAMERA = {"height": 100.0, "look_at": (0, 0, 31), "direction": (0.12, -1.0, 0.12)}
PROFILE = 68.0
GLIDE_FROM, GLIDE_TO = 52.0, -52.0
TURN_IN, GLIDE_START, GLIDE_END, SPIN_END = 0.4, 1.0, 4.9, 5.5
STEPS_PER_SECOND = 2.6


def leg(cycle, amount):
    """(swing, spread, lift, heel) for one leg: flat foot sliding back, then heel up while it returns."""
    angle = 2 * math.pi * cycle
    swing = 20 * math.cos(angle) * amount
    heel = 20 * max(0.0, -math.sin(angle)) ** 0.7 * amount
    return (swing, 0, 0.6 * heel / 20, heel)


def animate(rig, frame):
    t = frame / 24
    gliding = keys(t, [(GLIDE_START - 0.2, 0), (GLIDE_START + 0.1, 1), (GLIDE_END - 0.1, 1), (GLIDE_END + 0.25, 0)])
    # Position: slide in while turning, glide at constant speed, then drift back to centre during the spin.
    glide = (t - GLIDE_START) / (GLIDE_END - GLIDE_START)
    if t < GLIDE_START:
        x = GLIDE_FROM * keys(t, [(TURN_IN, 0), (GLIDE_START, 1)])
    elif t < GLIDE_END:
        x = GLIDE_FROM + (GLIDE_TO - GLIDE_FROM) * glide
    else:
        x = GLIDE_TO * (1 - keys(t, [(GLIDE_END, 0), (SPIN_END, 1)]))
    turn = PROFILE * keys(t, [(TURN_IN, 0), (GLIDE_START - 0.1, 1)])
    if t >= GLIDE_END:
        turn = PROFILE + (360 - PROFILE) * keys(t, [(GLIDE_END, 0), (GLIDE_END + 0.1, 0), (SPIN_END - 0.05, 1)])
    cycle = STEPS_PER_SECOND * t / 2
    stepping = max(gliding, keys(t, [(TURN_IN, 0), (TURN_IN + 0.15, 1), (GLIDE_START, 1), (GLIDE_START + 0.1, 0)]))
    legs = [leg(cycle + offset, stepping) for offset in (0.0, 0.5)]
    pump = math.sin(math.pi * 2 * cycle) * gliding
    bob = math.sin(2 * math.pi * 2 * cycle)

    # Toe stand after the spin: both heels up, root a little higher, one arm raised.
    rise = keys(t, [(SPIN_END - 0.05, 0), (SPIN_END + 0.25, 1), (SPIN_END + 0.85, 1), (SPIN_END + 1.25, 0)])
    arm_up = keys(t, [(SPIN_END - 0.1, 0), (SPIN_END + 0.3, 1), (SPIN_END + 0.8, 1), (SPIN_END + 1.2, 0)])
    spin_hop = 3 * keys(t, [(GLIDE_END + 0.1, 0), (GLIDE_END + 0.3, 1), (SPIN_END - 0.05, 0)])
    settle = keys(t, [(SPIN_END + 0.8, 0), (SPIN_END + 0.9, 1), (SPIN_END + 1.15, 0)])
    heel = 20 * rise

    rig.pose(
        x=x, z=spin_hop + 2.5 * rise + 0.5 * bob * gliding, turn=turn,
        lean=-4.0 * gliding, roll=1.5 * pump, twist=-6 * gliding * pump, lift=2.0 * rise + 1.0 * bob * gliding,
        horn_droop=5.0 * bob * gliding - 6 * rise - 6 * settle, squash=0.06 * rise - 0.05 * settle,
        arm_l=(55 * gliding * (1 + 0.45 * pump) * (1 - arm_up), 18 * gliding, 0, 0.9 + 0.1 * gliding),
        arm_r=(55 * gliding * (1 - 0.45 * pump) * (1 - arm_up) + 0 * arm_up, 18 * gliding + 147 * arm_up, 0, 0.9 + 0.1 * gliding + 0.6 * arm_up),
        leg_l=(legs[0][0] * (1 - rise), 0, legs[0][2], legs[0][3] * (1 - rise) + heel),
        leg_r=(legs[1][0] * (1 - rise), 0, legs[1][2], legs[1][3] * (1 - rise) + heel),
    )
