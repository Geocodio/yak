"""Yak shaving: the walker with its right half of the fringe shaved off and a razor raised to the bare side.

Run: Blender -b -P yak_shaving.py -- <output_dir>
Same frame and printing rules as cursor_yak.py: units mm, -Y is the front, flat base at z=0,
nothing overhangs more than 45 degrees except the muzzle underside.
"""

import math
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import cursor_yak as yak  # noqa: E402

LIFT = yak.LEG_HEIGHT
SHAVE_LINE_X = 0.0
SHOULDER = (16.0, -3.0, LIFT + 14.0)
HAND = (25.0, -8.0, LIFT + 27.0)
HANDLE_RADIUS = 2.8
EYE = (9.0, -yak.BODY_DEPTH / 2, LIFT + 33.0)
EYE_RADIUS = 2.2

yak.COLOURS.setdefault("ink", (0.030, 0.042, 0.052))


def build_shaving_body():
    """The walker body with the right arm swung up and out at more than 45 degrees."""
    body = yak.rounded_box("body", (yak.BODY_WIDTH, yak.BODY_DEPTH, yak.BODY_HEIGHT + 1.5), (0, 0, LIFT + (yak.BODY_HEIGHT - 1.5) / 2), 5.0)
    bottom_cut = yak.rounded_box("cut", (400, 400, 100), (0, 0, LIFT - 50), 0.01, segments=1)
    yak.boolean(body, bottom_cut, "DIFFERENCE")
    yak.remove(bottom_cut)
    for side in (-1, 1):
        leg = yak.rounded_box("leg", (14.0, 26.0, LIFT + 6.0), (side * 9.0, -1.0, (LIFT + 6.0) / 2 - 1.5), 4.0)
        yak.flatten_base(leg)
        yak.boolean(body, leg, "UNION")
        yak.remove(leg)
        if side < 0:
            arm = yak.capsule("arm", 3.8, (-18.4, -2.0, LIFT + 19.0), (-19.6, -3.5, LIFT + 7.0))
        else:
            arm = yak.capsule("arm", 3.8, SHOULDER, HAND)
        yak.boolean(body, arm, "UNION")
        yak.remove(arm)
    # A debossed eye on the shaved side; the ink disc fills it and stands 0.6 mm proud.
    eye = yak.cylinder("eye", EYE_RADIUS, 1.4, (EYE[0], EYE[1] + 0.1 - 0.7 + 0.6 - 0.0, EYE[2]), axis="Y")
    return body, eye


def build_razor():
    """A safety razor: fluted round handle with a peach band and a flat T-bar head that butts into the shaved side."""
    hx, hy, hz = HAND
    neck = hz + 14.0
    handle = yak.cylinder("razor", HANDLE_RADIUS, 17.0, (hx, hy, hz + 6.5), segments=48)
    for index in range(12):
        angle = index * math.pi / 6
        flute = yak.cylinder("flute", 0.55, neck - hz - 3.0, (hx + (HANDLE_RADIUS + 0.1) * math.cos(angle), hy + (HANDLE_RADIUS + 0.1) * math.sin(angle), (hz + 2.0 + neck - 1.0) / 2), segments=16)
        yak.boolean(handle, flute, "DIFFERENCE")
        yak.remove(flute)
    # The 14 x 4 x 3 bar runs into the head; the caller trims it to the body so it touches along the surface.
    bar = yak.prism("bar", [(hx - 11.0, neck), (hx + 7.0, neck), (hx + 7.0, neck + 3.0), (hx - 11.0, neck + 3.0)], "Y", hy - 2.0, hy + 2.0)
    yak.boolean(handle, bar, "UNION")
    yak.remove(bar)
    for offset in (-1.1, 1.1):
        slot = yak.prism("slot", [(hx - 6.0, neck + 1.7), (hx + 6.5, neck + 1.7), (hx + 6.5, neck + 3.5), (hx - 6.0, neck + 3.5)], "Y", hy + offset - 0.4, hy + offset + 0.4)
        yak.boolean(handle, slot, "DIFFERENCE")
        yak.remove(slot)
    center = (hx, hy, hz + 8.2)
    cutter = yak.cylinder("cutter", HANDLE_RADIUS + 0.4, 3.0, center, segments=48)
    inner = yak.cylinder("ring_inner", HANDLE_RADIUS - 0.8, 4.0, center, segments=48)
    yak.boolean(cutter, inner, "DIFFERENCE")
    yak.boolean(handle, cutter, "DIFFERENCE")
    yak.remove(cutter)
    outer = yak.cylinder("razor_band", HANDLE_RADIUS, 3.0, center, segments=48)
    yak.boolean(outer, inner, "DIFFERENCE")
    yak.remove(inner)
    return handle, outer


def build_fluff(body):
    """A low pile of shorn fringe by the right foot, merged deep into the leg and the bed."""
    fluff = None
    for x, y, radius in ((17.0, -8.0, 6.0), (18.5, -16.0, 5.0), (23.0, -12.0, 4.5), (21.0, -4.0, 4.0), (22.0, -19.0, 3.0)):
        lump = yak.capsule("fluff", radius, (x, y, 0.0), (x, y, 0.01))
        if fluff is None:
            fluff = lump
        else:
            yak.boolean(fluff, lump, "UNION")
            yak.remove(lump)
    yak.flatten_base(fluff)
    yak.boolean(fluff, body, "DIFFERENCE")
    return fluff


def build_parts():
    yak.clear_scene()
    body, eye = build_shaving_body()
    yak.boolean(body, eye, "DIFFERENCE")
    fringe = yak.build_fringe(body, LIFT)
    shaved = yak.rounded_box("shaved", (100, 200, 200), (SHAVE_LINE_X + 50, 0, LIFT + 50), 0.01, segments=1)
    yak.boolean(fringe, shaved, "DIFFERENCE")
    yak.remove(shaved)
    muzzle = yak.build_muzzle(body, LIFT)
    envelope = yak.head_box("envelope", LIFT, LIFT)
    rust = yak.horn("horn_rust", -1, LIFT)
    yak.boolean(rust, envelope, "DIFFERENCE")
    yak.remove(envelope)
    # With no fringe on the shaved side the sage horn grows straight out of the slate head.
    sage = yak.horn("horn_sage", 1, LIFT)
    yak.boolean(sage, body, "DIFFERENCE")
    razor, band = build_razor()
    yak.boolean(razor, body, "DIFFERENCE")
    yak.boolean(band, body, "DIFFERENCE")
    fluff = build_fluff(body)
    return [(body, "slate"), (fringe, "cream"), (fluff, "cream"), (muzzle, "peach"), (rust, "rust"),
            (sage, "sage"), (razor, "ink"), (band, "peach"), (eye, "ink")]


if __name__ == "__main__":
    yak.export_and_render(build_parts(), "shaving", look_at=(6, -5, 34), distance=300, square_look_at=(6, -5, 34), square_distance=280)
