"""Walk: the yak strolls in from the left edge and out past the right edge, turned towards the camera,
with a mid-way glance at the audience."""

import math

from mascot_clips import keys

FRAMES = 168
CAMERA = {"direction": (0.25, -1.0, 0.16), "look_at": (0, 0, 34), "height": 135.0}
STEPS_PER_SECOND = 2.4
START, END = -165.0, 165.0


def setup(rig):
    right = rig.camera.matrix_world.to_3x3().col[0].copy()
    right.z = 0
    rig.walk_direction = right.normalized()
    heading = math.degrees(math.atan2(rig.walk_direction.x, -rig.walk_direction.y))
    rig.walk_turn = heading - 40.0


def animate(rig, frame):
    t = frame / 24
    distance = START + (END - START) * t / (FRAMES / 24)
    phase = math.pi * STEPS_PER_SECOND * t
    swing = math.sin(phase)
    position = rig.walk_direction * distance
    glance = keys(t, [(0, 0), (2.6, 0), (3.1, 1), (4.2, 1), (4.7, 0), (7, 0)])
    rig.pose(
        x=position.x, y=position.y,
        z=1.6 * (1 - abs(swing)),
        turn=rig.walk_turn, lean=4.0, roll=4.0 * swing,
        twist=-22.0 * glance, side_bend=-2.0 * swing,
        nod=1.5 * math.sin(2 * phase - 0.6), horn_droop=4.0 * math.sin(2 * phase - 1.0),
        arm_l=(-24.0 * swing, 8.0), arm_r=(24.0 * swing, 8.0),
        leg_l=(26.0 * swing, 0, 3.0 * max(0.0, math.cos(phase))),
        leg_r=(-26.0 * swing, 0, 3.0 * max(0.0, -math.cos(phase))),
    )
