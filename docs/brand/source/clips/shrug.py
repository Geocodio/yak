"""Shrug: both arms swing out and up, shoulders rise and the body leans a little while a question mark pops up
above the head, wobbles, then everything settles back to rest and the question mark shrinks away."""

import math

from mathutils import Matrix, Vector

from mascot_clips import keys, text_object
from mascot_motion import ease_out_back

FRAMES = 84
CAMERA = {"look_at": (0, 0, 42), "direction": (0.3, -1.0, 0.18), "height": 140.0}


def setup(rig):
    rig.question = text_object("question", "?", 24.0)
    rig.question.visible_shadow = False


def animate(rig, frame):
    t = frame / 24
    arms = keys(t, [(0.0, 0.0), (0.35, 0.0), (0.5, -0.12), (0.85, 1.0), (1.0, 0.9), (1.1, 1.0), (2.05, 1.0),
                    (2.6, 0.0), (3.1, 0.0)])
    body = keys(t, [(0.0, 0.0), (0.4, 0.0), (0.9, 1.0), (2.1, 1.0), (2.75, 0.0), (3.1, 0.0)])
    bounce = keys(t, [(0.0, 0.0), (0.8, 0.0), (1.0, 1.0), (1.25, 0.2), (1.45, 0.7), (1.8, 0.4), (2.2, 0.0)])
    sway = 1.0 + 0.5 * math.sin(t * 5.0) * keys(t, [(1.4, 1), (2.1, 0)])
    rig.pose(
        squash=0.05 * body + 0.02 * bounce, side_bend=5.0 * body * sway, tilt=2.0 * body, lift=3.0 * body + 4.0 * bounce,
        horn_droop=5.0 * math.sin(t * 7.0) * keys(t, [(0.8, 0), (1.1, 1), (2.4, 0)]),
        arm_l=(25.0 * arms, 75.0 * arms, 0.0, 1.0 + 0.15 * arms),
        arm_r=(25.0 * arms, 75.0 * arms, 0.0, 1.0 + 0.15 * arms),
    )
    appear = ease_out_back((t - 0.8) / 0.45, 2.0) * (1 - keys(t, [(2.3, 0.0), (2.9, 1.0)]))
    visible = appear > 0.01
    rig.question.hide_render = not visible
    if visible:
        centre = Vector(rig.upper @ Vector((10.0, -1.0, 84.0)))
        wobble = 14.0 * math.sin((t - 0.8) * 9.0) * math.exp(-(t - 0.8) * 0.9)
        camera = rig.camera.matrix_world.to_3x3().to_4x4()
        rig.question.matrix_world = (Matrix.Translation(centre) @ camera @ yak_roll(wobble)
                                     @ Matrix.Diagonal((max(appear, 1e-4),) * 3 + (1,)))


def yak_roll(degrees):
    return Matrix.Rotation(math.radians(degrees), 4, "Z")
