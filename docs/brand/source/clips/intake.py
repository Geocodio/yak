"""Intake: tasks in, a PR out. Linear, Slack, Sentry and GitHub cards fly in along arcs, the yak turns to catch each one
and tucks it in under the lifted fringe; after a thinking wiggle an ink tile with a pull request icon shoots out
to the right for review. Ends with a small satisfied bounce."""

import math
import os

import bpy
from mathutils import Matrix, Vector

import cursor_yak as yak
from mascot_clips import facing_camera, keys, material, rotation, srgb, text_object, translate

FRAMES = 240
CAMERA = {"direction": (0.25, -1.0, 0.16), "look_at": (0, 0, 40), "height": 104.0}
ASSETS = os.path.join(os.path.dirname(os.path.abspath(__file__)), "intake")
CARD_SIZE, CARD_DEPTH, LOGO_SIZE = 24.0, 3.0, 14.0
# (slug, brand colour, start on screen in mm from frame centre, arc bulge, twist towards it, arrival time)
SOURCES = [
    ("linear", "#5E6AD2", (-96, 46), 20, -22.0, 1.6),
    ("slack", "#4A154B", (-98, -4), -16, -30.0, 3.2),
    ("sentry", "#362D59", (98, -4), -16, 30.0, 4.8),
    ("github", "#181717", (96, 46), 20, 22.0, 6.4),
]
FLIGHT = 1.15
TUCK_START, TUCK_END = 0.18, 0.62
PR_START, PR_LAND = 7.75, 8.45
PR_REST = (62, 8)


def textured_plane(name, image_path, size, emission):
    plane = yak.rounded_box(name, (size, size, 0.05), (0, 0, 0), 0.01, segments=1)
    mat = bpy.data.materials.new(name)
    mat.use_nodes = True
    nodes = mat.node_tree.nodes
    bsdf = nodes["Principled BSDF"]
    image = nodes.new("ShaderNodeTexImage")
    image.image = bpy.data.images.load(image_path)
    mat.node_tree.links.new(image.outputs["Color"], bsdf.inputs["Base Color"])
    mat.node_tree.links.new(image.outputs["Color"], bsdf.inputs["Emission Color"])
    mat.node_tree.links.new(image.outputs["Alpha"], bsdf.inputs["Alpha"])
    bsdf.inputs["Emission Strength"].default_value = emission
    bsdf.inputs["Roughness"].default_value = 0.6
    plane.data.materials.clear()
    plane.data.materials.append(mat)
    uv = plane.data.uv_layers.new(name="UV")
    for loop in plane.data.loops:
        co = plane.data.vertices[loop.vertex_index].co
        uv.data[loop.index].uv = (co.x / size + 0.5, co.y / size + 0.5)
    return plane


def tile(name, colour):
    obj = yak.rounded_box(name, (CARD_SIZE, CARD_SIZE, CARD_DEPTH), (0, 0, 0), 3.0, segments=6)
    obj.data.materials.clear()
    obj.data.materials.append(material(name, srgb(colour) if isinstance(colour, str) else colour, roughness=0.45))
    obj.data.polygons.foreach_set("use_smooth", [True] * len(obj.data.polygons))
    return obj


def setup(rig):
    rig.cards = []
    for slug, colour, *_ in SOURCES:
        rig.cards.append((tile(slug + "_card", colour), textured_plane(slug + "_logo", os.path.join(ASSETS, slug + ".png"), LOGO_SIZE, 0.5)))
    ink = yak.COLOURS["ink"]
    cream = yak.COLOURS["cream"]
    rig.pr_card = tile("pr_card", ink)
    rig.pr_icon = textured_plane("pr_icon", os.path.join(ASSETS, "pull-request.png"), 13.0, 0.3)
    rig.pr_text = text_object("pr_text", "PR", 7.0, cream)
    rig.camera_right = rig.camera.matrix_world.to_3x3().col[0].copy()
    rig.camera_up = rig.camera.matrix_world.to_3x3().col[1].copy()
    rig.camera_origin = Vector((0, 0, 34))


def screen_point(rig, x, y):
    return rig.camera_origin + rig.camera_right * x + rig.camera_up * y


def smooth(u):
    u = min(max(u, 0.0), 1.0)
    return u * u * (3 - 2 * u)


def place(rig, objects, location, scale, spin=0.0, tumble=0.0, roll=0.0):
    """Puts a tile and its decorations (objects are (object, local z offset, local y offset)) at a spot, facing the camera."""
    base = (facing_camera(rig.camera, location, 1.0, roll) @ rotation("Y", spin) @ rotation("Z", tumble)
            @ Matrix.Diagonal((scale, scale, scale, 1)))
    for obj, z, y in objects:
        obj.matrix_world = base @ translate(0, y, z)
        obj.hide_render = scale < 0.02


def animate(rig, frame):
    t = frame / 24
    arrivals = [source[5] for source in SOURCES]
    # Arm envelope: rises into each catch, falls once the card is tucked away.
    catch = 0.0
    for arrival in arrivals:
        catch = max(catch, keys(t, [(arrival - 0.5, 0), (arrival - 0.05, 1), (arrival + 0.2, 1), (arrival + 0.65, 0)]))
    twist_keys, previous = [(0.0, 0.0)], 0.0
    for source in SOURCES:
        arrival, target = source[5], source[4]
        twist_keys += [(arrival - 0.95, previous), (arrival - 0.1, target), (arrival + 0.5, target)]
        previous = target
    twist_keys += [(PR_START - 0.9, previous), (PR_START - 0.4, 0.0)]
    twist = keys(t, twist_keys)
    squash, lift, drop = 0.0, 0.0, 0.0
    for arrival in arrivals:
        squash += keys(t, [(arrival - 0.1, 0), (arrival + 0.06, -0.13), (arrival + 0.26, 0.05), (arrival + 0.5, 0)])
        drop += keys(t, [(arrival - 0.1, 0), (arrival + 0.06, 1.5), (arrival + 0.4, 0)])
        # Fringe opens as the card goes in and snaps shut with a small bounce.
        lift += keys(t, [(arrival + TUCK_START - 0.1, 0), (arrival + TUCK_START + 0.08, 15), (arrival + TUCK_END - 0.04, 15),
                         (arrival + TUCK_END + 0.05, -2), (arrival + TUCK_END + 0.14, 3), (arrival + TUCK_END + 0.28, 0)])
    # Thinking wiggle, then the PR goes out; fringe opens for it, then a satisfied bounce.
    wiggle_amount = keys(t, [(6.95, 0), (7.15, 1), (7.55, 1), (7.75, 0)])
    wiggle = wiggle_amount * math.sin(2 * math.pi * 3.0 * (t - 6.95))
    throw = keys(t, [(PR_START - 0.4, 0), (PR_START, 1), (PR_START + 0.2, 1), (PR_LAND, 0.0)])
    squash += keys(t, [(7.0, 0), (7.15, -0.05), (7.3, 0)]) + keys(t, [(PR_START - 0.4, 0), (PR_START - 0.25, -0.1), (PR_START, 0.06), (PR_START + 0.3, 0)])
    lift += wiggle_amount * (5 + 2 * math.sin(2 * math.pi * 3.0 * (t - 6.95) + 1.0))
    lift += keys(t, [(PR_START - 0.2, 0), (PR_START, 14), (PR_START + 0.35, 14), (PR_START + 0.45, -2), (PR_START + 0.55, 3), (PR_START + 0.75, 0)])
    bounce_hop = keys(t, [(8.95, 0), (9.1, 2.5), (9.3, 0), (9.38, 0.9), (9.48, 0), (10, 0)])
    squash += keys(t, [(8.8, 0), (8.95, -0.08), (9.1, 0.03), (9.3, -0.06), (9.45, 0)])
    lift += keys(t, [(8.9, 0), (9.1, 3), (9.35, 0)])
    grab = catch
    rig.pose(
        z=bounce_hop,
        squash=squash, hip_drop=drop,
        twist=twist + 6.0 * throw + 2.5 * wiggle,
        bend=5.0 * grab, side_bend=3.5 * wiggle_amount * math.sin(2 * math.pi * 3.0 * (t - 6.95) + 1.2),
        lift=max(0.0, lift), horn_droop=5.0 * wiggle + 3.0 * grab - 3.0 * throw,
        arm_l=(70.0 * grab + 40.0 * throw, 6.0 + 4.0 * grab, 38.0 * grab, 1.0 + 0.3 * grab),
        arm_r=(70.0 * grab + 85.0 * throw, 6.0 + 4.0 * grab - 6.0 * throw, 38.0 * grab, 1.0 + 0.3 * grab + 0.35 * throw),
    )
    hands = (rig.hand(-1) + rig.hand(1)) / 2
    mouth = rig.head_point((0, -9, 30))
    for i, (source, (card, logo)) in enumerate(zip(SOURCES, rig.cards)):
        slug, colour, (sx, sy), bulge, tw, arrival = source
        u = (t - (arrival - FLIGHT)) / FLIGHT
        parts = [(card, 0, 0), (logo, CARD_DEPTH / 2 + 0.06, 0)]
        if u <= 0:
            place(rig, parts, hands, 0.0)
            continue
        tuck = smooth((t - arrival - TUCK_START) / (TUCK_END - TUCK_START))
        if t < arrival + TUCK_END + 0.01:
            start = screen_point(rig, sx, sy)
            e = smooth(min(u, 1.0)) * 0.55 + min(u, 1.0) * 0.45
            control = (start + hands) / 2 + rig.camera_up * bulge * 1.4 + rig.camera_right * (bulge * -0.6 * (1 if sx < 0 else -1))
            a = (1 - e) ** 2 * start + 2 * (1 - e) * e * control + e ** 2 * hands
            position = a.lerp(mouth, tuck)
            arrive = 1 - min(u, 1.0)
            scale = (1.0 + 0.5 * arrive) * (1 - 0.8 * tuck)
            place(rig, parts, position, scale, spin=360.0 * arrive ** 2, tumble=-220.0 * arrive ** 2 * (1 if sx < 0 else -1), roll=0)
        else:
            place(rig, parts, hands, 0.0)
    # The PR tile pops out under the fringe and sails to the right.
    parts = [(rig.pr_card, 0, 0), (rig.pr_icon, CARD_DEPTH / 2 + 0.06, 3.0), (rig.pr_text, CARD_DEPTH / 2 + 0.05, -7.2)]
    if t < PR_START:
        place(rig, parts, mouth, 0.0)
    else:
        w = (t - PR_START) / (PR_LAND - PR_START)
        goal = screen_point(rig, *PR_REST)
        start = mouth
        control = (start + goal) / 2 + rig.camera_up * 22
        e = min(max(w, 0.0), 1.0)
        eased = 1 - (1 - e) ** 3
        position = (1 - eased) ** 2 * start + 2 * (1 - eased) * eased * control + eased ** 2 * goal
        grow = 0.2 + 0.8 * smooth(w / 0.35)
        scale = grow * (1.5 if w >= 1 else 1.0 + 0.5 * eased)
        settle = 1 + 0.05 * math.sin(max(t - PR_LAND, 0) * 14) * math.exp(-max(t - PR_LAND, 0) * 5)
        place(rig, parts, position, scale * settle, spin=360.0 * (1 - eased) ** 2, roll=0)
