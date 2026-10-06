"""Yak laptop: the walker yak typing on an open laptop, as one multi-colour plate.

Run: Blender -b -P yak_laptop.py -- <output_dir>
The laptop base sits on the bed in front of the legs and the lid stands upright at its far edge, so the
yak looks over it. The arms reach down to the keyboard; the hands are fused into the base. The lid back
carries an inlaid '>_' in cream.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import cursor_yak as yak  # noqa: E402

BASE_WIDTH, BASE_FRONT, BASE_BACK, BASE_HEIGHT = 36.0, -38.0, -19.0, 3.0
LID_HEIGHT, LID_THICKNESS = 16.0, 3.0
PROMPT_Z = 10.0
SHOULDER = (18.4, -2.0, 31.0)
HAND = (14.0, -25.0, 6.2)


def build_posed_body(lift):
    """The standard walker body with both arms reaching forward and down to the keyboard."""
    body = yak.rounded_box("body", (yak.BODY_WIDTH, yak.BODY_DEPTH, yak.BODY_HEIGHT + 1.5), (0, 0, lift + (yak.BODY_HEIGHT - 1.5) / 2), 5.0)
    bottom_cut = yak.rounded_box("cut", (400, 400, 100), (0, 0, lift - 50), 0.01, segments=1)
    yak.boolean(body, bottom_cut, "DIFFERENCE")
    yak.remove(bottom_cut)
    for side in (-1, 1):
        leg = yak.rounded_box("leg", (14.0, 26.0, lift + 6.0), (side * 9.0, -1.0, (lift + 6.0) / 2 - 1.5), 4.0)
        yak.flatten_base(leg)
        yak.boolean(body, leg, "UNION")
        yak.remove(leg)
        arm = yak.capsule("arm", 3.8, (side * SHOULDER[0], SHOULDER[1], SHOULDER[2]), (side * HAND[0], HAND[1], HAND[2]))
        yak.boolean(body, arm, "UNION")
        yak.remove(arm)
    return body


def build_laptop():
    """Returns (ink laptop, cream inlay): flat slab, upright lid, and the '>_' inlaid in the lid back."""
    depth = BASE_BACK - BASE_FRONT
    base = yak.rounded_box("laptop", (BASE_WIDTH, depth, BASE_HEIGHT + 1.4), (0, (BASE_FRONT + BASE_BACK) / 2, (BASE_HEIGHT - 1.4) / 2), 1.4)
    yak.flatten_base(base)
    lid_bottom = BASE_HEIGHT - 1.0
    lid = yak.rounded_box("lid", (34.0, LID_THICKNESS, LID_HEIGHT - lid_bottom), (0, BASE_FRONT + LID_THICKNESS / 2, (LID_HEIGHT + lid_bottom) / 2), 1.0)
    yak.boolean(base, lid, "UNION")
    yak.remove(lid)
    z = PROMPT_Z
    strokes = [((-8, z + 4), (-3, z)), ((-8, z - 4), (-3, z)), ((1, z - 4), (7, z - 4))]
    inlay = None
    for (x0, z0), (x1, z1) in strokes:
        groove = yak.capsule("groove", 1.2, (x0, BASE_FRONT - 0.4, z0), (x1, BASE_FRONT - 0.4, z1))
        if inlay is None:
            inlay = groove
        else:
            yak.boolean(inlay, groove, "UNION")
            yak.remove(groove)
    cutter = yak.rounded_box("cutter", (60, 3.0, 60), (0, BASE_FRONT + 1.5, 20), 0.01, segments=1)
    yak.boolean(inlay, cutter, "INTERSECT")
    yak.remove(cutter)
    yak.boolean(base, inlay, "DIFFERENCE")
    # The inlay is a copy of the removed groove, so the two parts touch without overlapping.
    return base, inlay


def build_laptop_parts():
    yak.clear_scene()
    lift = yak.LEG_HEIGHT
    body = build_posed_body(lift)
    laptop, inlay = build_laptop()
    yak.boolean(body, laptop, "DIFFERENCE")
    fringe = yak.build_fringe(body, lift)
    muzzle = yak.build_muzzle(body, lift)
    envelope = yak.head_box("envelope", lift, lift)
    horns = []
    for side, colour in ((-1, "rust"), (1, "sage")):
        part = yak.horn(f"horn_{colour}", side, lift)
        yak.boolean(part, envelope, "DIFFERENCE")
        horns.append((part, colour))
    yak.remove(envelope)
    return [(body, "slate"), (fringe, "cream"), (muzzle, "peach"), (laptop, "ink"), (inlay, "cream")] + horns


if __name__ == "__main__":
    parts = build_laptop_parts()
    parts[4][0].name = "inlay"
    yak.export_and_render(parts, "laptop", look_at=(0, -10, 28), distance=330, square_look_at=(0, -10, 28), square_distance=330)
