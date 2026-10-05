"""Desk buddy, printable first-test version: slide-up cap, hollow tub plus head, rack and pinion drive.

Run: Blender -b -P desk_buddy.py -- <output_dir>
Units are millimetres, X is width, -Y is front, Z is up. Writes one STL per printed part in print orientation,
renders (assembled, exploded, drive x-ray, print plate) and prints interference checks per pose.
"""

import math
import os
import sys

import bmesh
import bpy
from mathutils import Matrix, Vector

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import cursor_yak as yak  # noqa: E402

OUT = yak.OUT
S = 1.5  # scale of the yak
WALL = 2.4
SLIDE_CLEARANCE = 0.25  # per side, sliding fits
PRESS_CLEARANCE = 0.15  # per side, press fits
RIB = 1.6  # minimum structural wall

# Body envelope after scaling: x +-27, y +-22.5, z 13.5..84, legs below.
BW, BD = yak.BODY_WIDTH * S, yak.BODY_DEPTH * S
HW, HD = BW / 2, BD / 2
FLOOR = 15.9
SPLIT = 44.0  # tub below, head above
CHAMFER = 1 - math.cos(math.pi / 4)
HEAD_TOP = 84.0 - 5.0 * S * CHAMFER  # head roof outer, the 45 degree cut keeps it printable upside down
ROOF = WALL
CEIL = HEAD_TOP - ROOF
CAP_TOP = 57.5 * S - 6.25 * S * CHAMFER  # cap crown outer after the 45 degree cut

# 0.71 inch round GC9D01 module (Waveshare): PCB 20.12 x 22.3, 18 mm round display, thickness assumed 3.0.
MODULE_W, MODULE_H, MODULE_T = 20.12, 22.3, 3.0
WINDOW_D = 17.6
MODULE_GAP = 2.0
CIRCLE_OFFSET = 1.1
EYE_X = (MODULE_W + MODULE_GAP) / 2
EYE_Z = 60.0
INNER_FRONT, INNER_BACK = -HD + WALL, HD - WALL

ESP = (17.5, 21.0, 3.5)  # XIAO ESP32-S3
USB_Z = 23.0
USB_SLOT = (9.5, 3.9)
SERVO = (12.2, 22.8, 22.8)  # MG90S on its side (22.8 x 12.2 x 28.5 with spline): x thickness, y depth, z long axis; spline at the low z end
SERVO_FACE = INNER_BACK - SERVO[1]  # servo face the spline comes out of
EAR_PLATE = (SERVO_FACE + 5.5, SERVO_FACE + 8.0)  # assumed: ear plate sits 5.5 mm behind the spline face, 2.5 mm thick
EAR_BOSS_Y0 = EAR_PLATE[1] + 0.15
SPLINE_LEN = 5.0  # assumed spline length above the output boss
SERVO_ZS = 55.45  # spline axis height
CAPACITOR = (8.0, 12.0)  # electrolytic diameter, height
LOCK_SCALE = 0.55

# Drive: 24 tooth, module 1 pinion straight on the servo spline, rack column beside it.
MODULE = 1.0
PINION_TEETH = 24
PITCH_R = PINION_TEETH * MODULE / 2
PINION_Y = (-9.6, -3.0)
PINION_YC = sum(PINION_Y) / 2
BACKLASH = 0.15
RACK_SEC = (5.25, 5.0)  # x (tooth tip to back), y
RACK_X0 = PITCH_R - 1.0  # tooth tip x
RACK_CENTER = (RACK_X0 + RACK_SEC[0] / 2, PINION_YC)
GUIDE_SEC = (5.0, 5.0)
GUIDE_CENTER = (-14.0, 12.0)
COLUMN_TOP = CAP_TOP - 1.0  # blind bore leaves a 1 mm skin
RACK_LEN, GUIDE_LEN = 65.0, 64.0
RACK_TUBE_BOTTOM, GUIDE_TUBE_BOTTOM = 69.0, 60.0
STOP_PIN_Z = 24.5  # cross pin height on the guide column when closed
STOP_PIN_HEAD_R = 2.0
TRAVEL_STOP = GUIDE_TUBE_BOTTOM - (STOP_PIN_Z + STOP_PIN_HEAD_R)  # 33.5 mm hard stop at the top of travel

EXTRA = {"pcb": (0.03, 0.30, 0.16), "servo": (0.08, 0.20, 0.75), "grey": (0.32, 0.32, 0.36), "flex": (0.9, 0.55, 0.1),
         "usb": (0.7, 0.7, 0.72), "cap": (0.1, 0.1, 0.45), "wire": (0.8, 0.2, 0.2)}


# ---------------------------------------------------------------- helpers

def B(x0, x1, y0, y1, z0, z1, name="box"):
    return yak.rounded_box(name, (x1 - x0, y1 - y0, z1 - z0), ((x0 + x1) / 2, (y0 + y1) / 2, (z0 + z1) / 2), 0.01, segments=1)


def cyl(axis, centre, radius, length, name="cyl", segments=48):
    return yak.cylinder(name, radius, length, centre, axis=axis, segments=segments)


def clone(obj, name):
    dup = obj.copy()
    dup.data = obj.data.copy()
    dup.name = name
    bpy.context.collection.objects.link(dup)
    return dup


def add(target, *others):
    for other in others:
        yak.boolean(target, other, "UNION")
        yak.remove(other)
    return target


def cut(target, *others):
    for other in others:
        yak.boolean(target, other, "DIFFERENCE")
        yak.remove(other)
    return target


def keep(target, other):
    yak.boolean(target, other, "INTERSECT")
    yak.remove(other)
    return target


def merge(objs):
    first = objs[0]
    return add(first, *objs[1:]) if len(objs) > 1 else first


def paint(obj, colour):
    obj.data.materials.clear()
    rgb = yak.COLOURS[colour] if colour in yak.COLOURS else EXTRA[colour]
    obj.data.materials.append(yak.material(colour, rgb))
    obj.data.polygons.foreach_set("use_smooth", [True] * len(obj.data.polygons))
    obj.data.set_sharp_from_angle(angle=math.radians(35))


def emissive(name, colour, strength):
    mat = bpy.data.materials.get(name) or bpy.data.materials.new(name)
    mat.use_nodes = True
    bsdf = mat.node_tree.nodes["Principled BSDF"]
    bsdf.inputs["Base Color"].default_value = (0, 0, 0, 1)
    bsdf.inputs["Roughness"].default_value = 1.0
    bsdf.inputs["Emission Color"].default_value = (*colour, 1)
    bsdf.inputs["Emission Strength"].default_value = strength
    return mat


def volume(obj):
    bm = bmesh.new()
    bm.from_mesh(obj.data)
    value = abs(bm.calc_volume())
    bm.free()
    return value


def world_copy(obj):
    dup = obj.copy()
    dup.data = obj.data.copy()
    dup.data.transform(obj.matrix_world)
    dup.parent = None
    dup.matrix_world = Matrix.Identity(4)
    bpy.context.collection.objects.link(dup)
    return dup


def overlap(a_obj, b_obj):
    """Volume (mm3) shared by two objects in their current world positions."""
    a, b = world_copy(a_obj), world_copy(b_obj)
    yak.boolean(a, b, "INTERSECT")
    value = volume(a)
    yak.remove(a, b)
    return value


# ---------------------------------------------------------------- gears

def pinion_outline(cx, cz):
    pressure = math.radians(20)
    base_r = PITCH_R * math.cos(pressure)
    tip_r, root_r = PITCH_R + MODULE, PITCH_R - 1.25 * MODULE
    inv = lambda a: math.tan(a) - a  # noqa: E731
    half = (math.pi * MODULE / 2 - BACKLASH / 2) / 2 / PITCH_R
    phi = lambda r: half + inv(pressure) - inv(math.acos(base_r / r))  # noqa: E731
    start = max(base_r, root_r)
    radii = [start + (tip_r - start) * i / 6 for i in range(7)]
    points = []
    for k in range(PINION_TEETH):
        theta = 2 * math.pi * k / PINION_TEETH
        seq = []
        if root_r < base_r:
            seq.append((theta - phi(base_r), root_r))
        seq += [(theta - phi(r), r) for r in radii] + [(theta + phi(r), r) for r in reversed(radii)]
        if root_r < base_r:
            seq.append((theta + phi(base_r), root_r))
        nxt = theta + 2 * math.pi / PINION_TEETH - phi(base_r)
        seq += [(theta + phi(base_r) + (nxt - theta - phi(base_r)) * f, root_r) for f in (0.33, 0.66)]
        points += seq
    return [(cx + r * math.cos(a), cz + r * math.sin(a)) for a, r in points]


def rack_outline():
    tip, root, back = RACK_X0, RACK_X0 + 2.25, RACK_X0 + RACK_SEC[0]
    thick = (math.pi * MODULE / 2 - BACKLASH / 2) / 2
    half_tip, half_root = thick - math.tan(math.radians(20)) * 1.0, thick + math.tan(math.radians(20)) * 1.25
    zbot, ztop = COLUMN_TOP - RACK_LEN, COLUMN_TOP
    centres = [SERVO_ZS + math.pi / 2 - j * math.pi for j in range(-2, 13)]
    pts = [(back, zbot), (back, ztop), (tip, ztop), (tip, centres[0] + half_root)]
    for zk in centres:
        pts += [(root, zk + half_root), (tip, zk + half_tip), (tip, zk - half_tip), (root, zk - half_root)]
    pts.append((root, zbot))
    return pts


# ---------------------------------------------------------------- model

def build_shells():
    yak.clear_scene()
    yak.FRINGE_MARGIN = 1.25  # keeps the cap skirt at 1.6 mm after the sliding clearance
    yak.FRINGE_RADIUS = 5.0 + yak.FRINGE_MARGIN
    yak.locks_module.FRONT[:] = [(c, n * LOCK_SCALE) for c, n in yak.locks_module.FRONT]
    yak.locks_module.SIDE[:] = [(c, n * LOCK_SCALE) for c, n in yak.locks_module.SIDE]
    lift = yak.LEG_HEIGHT
    body = yak.build_body(lift)
    # The cap's inner surface is the head enlarged by the sliding clearance, with the same 45 degree top cut.
    tool = clone(body, "tool")
    c = SLIDE_CLEARANCE / S
    tool.data.transform(Matrix.Diagonal((1 + 2 * c / yak.BODY_WIDTH, 1 + 2 * c / yak.BODY_DEPTH, 1, 1)))
    cut(tool, B(-100, 100, -100, 100, HEAD_TOP / S, 300))
    fringe = yak.build_fringe(tool, lift)
    yak.remove(tool)
    muzzle = yak.build_muzzle(body, lift)
    envelope = yak.head_box("envelope", lift, lift)
    cut(envelope, B(-100, 100, -100, 100, CAP_TOP / S, 300))
    horns = []
    for side, colour in ((-1, "rust"), (1, "sage")):
        horn = yak.horn(f"horn_{colour}", side, lift)
        yak.boolean(horn, envelope, "DIFFERENCE")
        horns.append(horn)
    yak.remove(envelope)
    for obj in [body, fringe, muzzle] + horns:
        obj.data.transform(Matrix.Scale(S, 4))
    cut(fringe, B(-100, 100, -100, 100, CAP_TOP, 300))
    return body, fringe, muzzle, horns


def eye_cradle_parts():
    """Rib, lip and stop solids that hold the two modules against the front wall; added to the head."""
    solids = []
    ribs_x = [(-EYE_X - MODULE_W / 2 - 0.15 - RIB, -EYE_X - MODULE_W / 2 - 0.15), (-0.85, 0.85),
              (EYE_X + MODULE_W / 2 + 0.15, EYE_X + MODULE_W / 2 + 0.15 + RIB)]
    z0, z1 = 47.0, 70.2
    y0, y1 = INNER_FRONT - 0.2, -15.95
    for x0, x1 in ribs_x:
        solids.append(B(x0, x1, y0, y1, z0, z1, "rib"))
    for side in (-1, 1):
        c = side * EYE_X
        for x0, x1 in ((c - MODULE_W / 2 - 0.15, c - MODULE_W / 2 + 1.0), (c + MODULE_W / 2 - 1.0, c + MODULE_W / 2 + 0.15)):
            solids.append(B(x0, x1, -16.95, y1, z0, z1, "lip"))
        outline = [(INNER_FRONT - 0.2, 70.2), (INNER_FRONT + MODULE_T + 0.0, 70.2), (INNER_FRONT + MODULE_T, 72.2), (INNER_FRONT - 0.2, 75.4)]
        solids.append(yak.prism("stop", outline, "X", c - 4, c + 4))
    return solids


def build_tub(body):
    tub = clone(body, "tub")
    keep(tub, B(-100, 100, -100, 100, -1, SPLIT))
    for lo, hi in ((27.15, 100), (-100, -27.15)):
        bit = clone(body, "arm_bit")
        keep(bit, B(lo, hi, -100, 100, SPLIT - 0.5, 50))
        add(tub, bit)
    cavity = yak.rounded_box("cavity", (BW - 2 * WALL, BD - 2 * WALL, 50 - FLOOR), (0, 0, (50 + FLOOR) / 2), 5.0 * S - WALL)
    cut(tub, cavity)
    adds = []
    # ESP32 pad, side rails (0.15 clearance), strap boss, capacitor ring, connector pocket, cable lane ribs.
    ey0, ey1 = INNER_BACK - ESP[1], INNER_BACK
    pad_top = USB_Z - ESP[2] / 2
    adds.append(B(-ESP[0] / 2, ESP[0] / 2, ey0, ey1 + 0.2, FLOOR - 0.2, pad_top, "pad"))
    for side in (-1, 1):
        x0 = side * (ESP[0] / 2 + 0.15)
        adds.append(B(min(x0, x0 + side * RIB), max(x0, x0 + side * RIB), ey0, ey1 + 0.2, FLOOR - 0.2, pad_top + ESP[2] + 0.8, "rail"))
        adds.append(B(min(side * 3.7, side * 5.3), max(side * 3.7, side * 5.3), -17.5, -4.5, FLOOR - 0.2, FLOOR + 5, "lane"))
    boss_y = ey0 - 3.0
    boss_top = pad_top + ESP[2] + 0.2
    adds.append(cyl("Z", (0, boss_y, (FLOOR - 0.2 + boss_top) / 2), 2.5, boss_top - FLOOR + 0.2, "strap_boss"))
    ring = cyl("Z", (16.5, 10.0, FLOOR + 3.0), CAPACITOR[0] / 2 + 0.15 + RIB, 6.4, "cap_ring")
    cut(ring, cyl("Z", (16.5, 10.0, FLOOR + 3.5), CAPACITOR[0] / 2 + 0.15, 7.0, "cap_hole"))
    adds.append(ring)
    pocket = B(17.5, 23.9, -18.6, -4.4, FLOOR - 0.2, FLOOR + 8, "conn_pocket")
    cut(pocket, B(19.1, 22.3, -17.0, -6.0, FLOOR + 1.6, FLOOR + 9))
    adds.append(pocket)
    # USB-C plug saddle: two blocks flank the plug overmold outside the back wall, a cable tie runs through the two
    # holes beside them and over the plug, so cable pull goes into the tub and not the ESP32 board.
    for side in (-1, 1):
        outline = [(HD - 0.1, USB_Z - 4.2), (HD + 5.5, USB_Z + 1.3), (HD + 5.5, USB_Z + 3.7), (HD - 0.1, USB_Z + 3.7)]
        adds.append(yak.prism("saddle", outline, "X", min(side * 5.2, side * 8.2), max(side * 5.2, side * 8.2)))
    add(tub, merge(adds))
    holes = [yak.rounded_box("usb", (USB_SLOT[0], 3 * WALL, USB_SLOT[1]), (0, HD, USB_Z), 1.4)]
    for side in (-1, 1):
        holes.append(cyl("Y", (side * 17.9, HD - 1.0, 40.0), 1.35, 6.0, "rear_hole"))
        holes.append(cyl("X", (side * 29.5, -3.0, 42.0), 1.35, 11.0, "side_hole"))
        holes.append(cyl("Y", (side * 18.0, -HD + 1.2, 42.4), 1.35, 6.0, "front_hole"))
        holes.append(B(side * 9.5 - 1.7, side * 9.5 + 1.7, HD - WALL - 1, HD + 1, USB_Z - 1.7, USB_Z + 1.7, "tie_hole"))
    cut(tub, *holes)
    cut(tub, cyl("Z", (0, boss_y, FLOOR + 5), 1.1, 12.0, "strap_pilot"))
    return tub


def build_head(body):
    head = clone(body, "head")
    keep(head, B(-27.05, 27.05, -100, 100, SPLIT, 100))
    cut(head, B(-100, 100, -100, 100, HEAD_TOP, 300))
    cavity = yak.rounded_box("cavity", (BW - 2 * WALL, BD - 2 * WALL, CEIL - (SPLIT - 3)), (0, 0, (CEIL + SPLIT - 3) / 2), 2.0)  # tight inner corners leave room for the modules
    cut(head, cavity)
    adds = eye_cradle_parts()
    # Servo pocket ribs and ear bosses.
    for side in (-1, 1):
        x0 = side * (SERVO[0] / 2 + 0.15)
        adds.append(B(min(x0, x0 + side * RIB), max(x0, x0 + side * RIB), -1.0, INNER_BACK + 0.2, 45.5, CEIL + 0.2, "servo_rib"))
    ear_zs = [SERVO_ZS - 5.75 + SERVO[2] / 2 + dz for dz in (-13.9, 13.9)]
    ear_zs = [SERVO_ZS + 5.75 + dz for dz in (-13.9, 13.9)]
    for z in ear_zs:
        adds.append(cyl("Y", (0, (EAR_BOSS_Y0 + INNER_BACK + 0.2) / 2, z), 2.3, INNER_BACK + 0.2 - EAR_BOSS_Y0, "ear_boss"))
    # Column guide tubes: boss pocket on top, sliding bore below.
    rack_bore = (RACK_SEC[0] + 2 * SLIDE_CLEARANCE, RACK_SEC[1] + 2 * SLIDE_CLEARANCE)
    guide_bore = (GUIDE_SEC[0] + 2 * SLIDE_CLEARANCE, GUIDE_SEC[1] + 2 * SLIDE_CLEARANCE)
    pocket_floor = CAP_BOSS_BOTTOM - 0.3
    cuts = []
    for centre, sec, bore, tube_bottom in ((RACK_CENTER, RACK_SEC, rack_bore, RACK_TUBE_BOTTOM), (GUIDE_CENTER, GUIDE_SEC, guide_bore, GUIDE_TUBE_BOTTOM)):
        boss_w, boss_d = boss_size(sec)
        pocket_w, pocket_d = boss_w + 2 * SLIDE_CLEARANCE, boss_d + 2 * SLIDE_CLEARANCE
        adds.append(B(centre[0] - pocket_w / 2 - RIB, centre[0] + pocket_w / 2 + RIB, centre[1] - pocket_d / 2 - RIB, centre[1] + pocket_d / 2 + RIB, tube_bottom, CEIL + 0.3, "tube"))
        cuts.append(B(centre[0] - pocket_w / 2, centre[0] + pocket_w / 2, centre[1] - pocket_d / 2, centre[1] + pocket_d / 2, pocket_floor, HEAD_TOP + 1, "pocket"))
        cuts.append(B(centre[0] - bore[0] / 2, centre[0] + bore[0] / 2, centre[1] - bore[1] / 2, centre[1] + bore[1] / 2, tube_bottom - 1, pocket_floor + 1, "bore"))
    # Tongue ring (1.6 mm, 0.15 clearance) that locates the head on the tub, open across the front for the modules.
    r_out = yak.rounded_box("ring", (BW - 2 * WALL - 0.3, BD - 2 * WALL - 0.3, 4.4), (0, 0, SPLIT - 1.9), 5.0 * S - WALL - 0.15, vertical_only=True)
    r_in = yak.rounded_box("ring_in", (BW - 2 * WALL - 0.3 - 2 * RIB, BD - 2 * WALL - 0.3 - 2 * RIB, 6), (0, 0, SPLIT - 1.9), 5.0 * S - WALL - 0.15 - RIB, vertical_only=True)
    cut(r_out, r_in, B(-22.7, 22.7, -40, -10, SPLIT - 6, SPLIT + 3))
    adds.append(r_out)
    # Screw tabs: two at the rear, two at the sides.
    for side in (-1, 1):
        xa, xb = sorted((side * 15.4, side * 20.4))
        adds.append(B(xa, xb, 15.5, 19.95, 36, SPLIT + 0.2, "tab"))
        adds.append(B(xa, xb, 15.5, INNER_BACK + 0.2, SPLIT, 50, "tab_up"))
        xa, xb = sorted((side * 19.6, side * 24.45))
        adds.append(B(xa, xb, -6, 0, 36, SPLIT + 0.2, "tab"))
        xf, xg = sorted((side * 17.0, side * 23.9))
        adds.append(B(xf, xg, -16.9, -5.9, 36, SPLIT + 0.2, "tab_front"))
        adds.append(B(xf, xg, -16.9, -5.9, SPLIT, 50, "tab_front_up"))
        cuts.append(cyl("Y", (side * 18.0, -13.8, 42.4), 1.1, 6.2, "front_pilot"))
        xa, xb = sorted((side * 19.6, side * (HW - WALL + 0.2)))
        adds.append(B(xa, xb, -6, 0, SPLIT, 50, "tab_up"))
        cuts.append(cyl("Y", (side * 17.9, 15.95, 40.0), 1.1, 8.0, "pilot"))
        cuts.append(cyl("X", (side * 22.0, -3.0, 42.0), 1.1, 6.0, "pilot"))
    for z in ear_zs:
        cuts.append(cyl("Y", (0, EAR_BOSS_Y0 + 5.0, z), 1.0, 10.0, "ear_pilot"))
    for side in (-1, 1):
        cuts.append(cyl("Y", (side * EYE_X, -HD + WALL / 2, EYE_Z), WINDOW_D / 2, 3 * WALL, "window"))
    add(head, merge(adds))
    cut(head, *cuts)
    return head


def boss_size(sec):
    bore = (sec[0] + 2 * PRESS_CLEARANCE, sec[1] + 2 * PRESS_CLEARANCE)
    return bore[0] + 2 * RIB, bore[1] + 2 * RIB


CAP_BOSS_BOTTOM = HEAD_TOP - 5.5


def finish_cap(fringe, horns):
    adds, cuts = [], []
    for centre, sec in ((RACK_CENTER, RACK_SEC), (GUIDE_CENTER, GUIDE_SEC)):
        w, d = boss_size(sec)
        adds.append(B(centre[0] - w / 2, centre[0] + w / 2, centre[1] - d / 2, centre[1] + d / 2, CAP_BOSS_BOTTOM, HEAD_TOP + 0.5, "boss"))
        bw, bd = sec[0] + 2 * PRESS_CLEARANCE, sec[1] + 2 * PRESS_CLEARANCE
        cuts.append(B(centre[0] - bw / 2, centre[0] + bw / 2, centre[1] - bd / 2, centre[1] + bd / 2, CAP_BOSS_BOTTOM - 0.5, COLUMN_TOP, "boss_bore"))
    add(fringe, merge(adds))
    for side in (-1, 1):
        for y in (-1.7, 1.7):
            cuts.append(cyl("Z", (side * 20.5, y, CAP_TOP - 0.5), 0.95, 2.0, "pin_pocket"))
    cut(fringe, *cuts)
    for horn, side in zip(horns, (-1, 1)):
        keep(horn, B(-100, 100, -100, 100, CAP_TOP, 300))  # flat base: prints upright, the horn leans at 45 degrees
        cut(horn, *[cyl("Z", (side * 20.5, y, CAP_TOP + 2.0), 0.95, 5.0, "pin_hole") for y in (-1.7, 1.7)])


def build_drive():
    pinion = yak.prism("pinion", pinion_outline(0, SERVO_ZS), "Y", PINION_Y[0], PINION_Y[1])
    # Back face recess for the stock single-arm horn (assumed: hub 7.0 mm, arm 4.5 mm wide, trimmed to 9 mm from the
    # centre, plate 2 mm), 0.15 mm clearance; the horn centre screw goes through the 3.0 mm hole to the spline.
    hub_end, back = -8.1, PINION_Y[1]
    cut(pinion, cyl("Y", (0, (back + 0.1 + hub_end) / 2, SERVO_ZS), 3.5 + PRESS_CLEARANCE, back + 0.1 - hub_end, "horn_hub"),
        B(-(9.0 + PRESS_CLEARANCE), 0, hub_end + 0.1 - 3.2, back + 0.1, SERVO_ZS - 2.25 - PRESS_CLEARANCE, SERVO_ZS + 2.25 + PRESS_CLEARANCE, "horn_arm"),
        cyl("Y", (0, (hub_end - PINION_Y[0] - 1) / 2 + PINION_Y[0] - 0.5, SERVO_ZS), 1.5, hub_end - PINION_Y[0] + 2.0, "screw_hole"),
        cyl("Y", (0, PINION_Y[0] + 0.4, SERVO_ZS), 2.7, 1.0, "screw_head"))
    rack = yak.prism("rack", rack_outline(), "Y", RACK_CENTER[1] - RACK_SEC[1] / 2, RACK_CENTER[1] + RACK_SEC[1] / 2)
    guide = B(GUIDE_CENTER[0] - GUIDE_SEC[0] / 2, GUIDE_CENTER[0] + GUIDE_SEC[0] / 2, GUIDE_CENTER[1] - GUIDE_SEC[1] / 2,
              GUIDE_CENTER[1] + GUIDE_SEC[1] / 2, COLUMN_TOP - GUIDE_LEN, COLUMN_TOP, "guide_column")
    cut(guide, cyl("Y", (GUIDE_CENTER[0], GUIDE_CENTER[1], STOP_PIN_Z), 1.0, 8.0, "pin_hole"))
    stop_pin = cyl("Y", (GUIDE_CENTER[0], 10.4, STOP_PIN_Z), 1.0, 9.2, "stop_pin")
    add(stop_pin, cyl("Y", (GUIDE_CENTER[0], 5.4, STOP_PIN_Z), STOP_PIN_HEAD_R, 0.8, "pin_head"))
    # Strap that holds the ESP32 against its pad: one M2.5 screw into the boss in front of the board.
    boss_y = INNER_BACK - ESP[1] - 3.0
    top = USB_Z + ESP[2] / 2 + 0.2
    strap = B(-3.5, 3.5, boss_y - 3.0, boss_y + 4.5, top, top + RIB, "esp_strap")
    cut(strap, cyl("Z", (0, boss_y, top + RIB / 2), 1.35, 4.0, "strap_hole"))
    return pinion, rack, guide, stop_pin, strap


def build_electronics():
    parts = []
    for side in (-1, 1):
        parts.append((B(side * EYE_X - MODULE_W / 2, side * EYE_X + MODULE_W / 2, INNER_FRONT + 0.03, INNER_FRONT + 0.03 + MODULE_T,
                        EYE_Z - CIRCLE_OFFSET - MODULE_H / 2, EYE_Z - CIRCLE_OFFSET + MODULE_H / 2, "screen_module"), "pcb"))
    parts.append((B(-ESP[0] / 2, ESP[0] / 2, INNER_BACK - ESP[1], INNER_BACK, USB_Z - ESP[2] / 2, USB_Z + ESP[2] / 2, "esp32"), "pcb"))
    parts.append((B(-4.2, 4.2, INNER_BACK - 4.0, INNER_BACK + 1.5, USB_Z - 1.5, USB_Z + 1.5, "usb_c"), "usb"))
    zs = SERVO_ZS
    parts.append((B(-SERVO[0] / 2, SERVO[0] / 2, INNER_BACK - SERVO[1], INNER_BACK, zs - 5.75, zs - 5.75 + SERVO[2], "servo"), "servo"))
    parts.append((B(-5.9, 5.9, EAR_PLATE[0], EAR_PLATE[1], zs + 5.75 - 16.4, zs + 5.75 + 16.4, "servo_ears"), "servo"))
    parts.append((cyl("Y", (0, SERVO_FACE - SPLINE_LEN / 2, zs), 2.45, SPLINE_LEN, "spline"), "grey"))
    parts.append((cyl("Z", (16.5, 10.0, FLOOR + 0.03 + CAPACITOR[1] / 2), CAPACITOR[0] / 2, CAPACITOR[1], "capacitor"), "cap"))
    parts.append((B(19.3, 22.1, -16.6, -6.4, FLOOR + 1.8, FLOOR + 7.8, "connector"), "usb"))
    return parts


def build_eyes():
    eyes = {"round": [], "happy": []}
    glass_y = INNER_FRONT - 0.05
    for side in (-1, 1):
        x = side * EYE_X
        glass = cyl("Y", (x, glass_y, EYE_Z), WINDOW_D / 2 - 0.05, 0.1, "glass")
        paint(glass, "ink")
        iris = cyl("Y", (x, glass_y - 0.1, EYE_Z), 6.3, 0.1, "iris")
        pupil = cyl("Y", (x + 0.8, glass_y - 0.2, EYE_Z + 0.5), 2.9, 0.1, "pupil")
        crescent = cyl("Y", (x, glass_y - 0.1, EYE_Z - 1.0), 6.8, 0.1, "crescent")
        cut(crescent, cyl("Y", (x, glass_y - 0.1, EYE_Z - 3.4), 6.8, 1.0, "crescent_cut"))
        for obj, mat in ((iris, emissive("eye", (0.0, 0.7, 1.0), 3.0)), (pupil, emissive("pupil", (0.0, 0.08, 0.15), 0.2)),
                         (crescent, emissive("eye", (0.0, 0.7, 1.0), 3.0))):
            obj.data.materials.append(mat)
        eyes["round"] += [iris, pupil]
        eyes["happy"] += [crescent]
        eyes.setdefault("glass", []).append(glass)
    for obj in eyes["happy"]:
        obj.hide_render = True
    return eyes


def build():
    body, fringe, muzzle, horns = build_shells()
    tip_z = min(v.co.z for v in fringe.data.vertices)
    edge_z = (yak.LEG_HEIGHT + yak.FRINGE_BOTTOM) * S
    tub = build_tub(body)
    head = build_head(body)
    yak.remove(body)
    finish_cap(fringe, horns)
    pinion, rack, guide, stop_pin, strap = build_drive()
    electronics = build_electronics()
    eyes = build_eyes()
    printed = {"tub": (tub, "slate"), "head": (head, "slate"), "cap": (fringe, "cream"), "horn_rust": (horns[0], "rust"),
               "horn_sage": (horns[1], "sage"), "muzzle": (muzzle, "peach"), "rack": (rack, "grey"), "guide_column": (guide, "grey"),
               "pinion": (pinion, "grey"), "esp_strap": (strap, "grey")}
    for obj, colour in printed.values():
        paint(obj, colour)
    paint(stop_pin, "usb")
    for obj, colour in electronics:
        paint(obj, colour)
    empty = bpy.data.objects.new("cap_slide", None)
    bpy.context.collection.objects.link(empty)
    for obj in (fringe, horns[0], horns[1], rack, guide, stop_pin):
        obj.parent = empty
    eye_top = EYE_Z + WINDOW_D / 2
    travel = {"closed": 0.0, "peek": EYE_Z + 3.0 - edge_z, "open": eye_top + 1.0 - tip_z}
    return dict(printed=printed, electronics=electronics, eyes=eyes, empty=empty, stop_pin=stop_pin, travel=travel, tip_z=tip_z)


def pose(model, name):
    travel = model["travel"][name]
    model["empty"].location = (0, 0, travel)
    centre = Vector((0, PINION_YC, SERVO_ZS))
    pinion = model["printed"]["pinion"][0]
    pinion.matrix_world = Matrix.Translation(centre) @ Matrix.Rotation(-travel / PITCH_R, 4, "Y") @ Matrix.Translation(-centre)
    bpy.context.view_layer.update()


def moving_objects(model):
    p = model["printed"]
    return [p["cap"][0], p["horn_rust"][0], p["horn_sage"][0], p["rack"][0], p["guide_column"][0], model["stop_pin"]]


def fixed_objects(model):
    p = model["printed"]
    fixed = [p[n][0] for n in ("tub", "head", "muzzle", "pinion", "esp_strap")]
    fixed += [o for o, _ in model["electronics"]]
    return fixed


def check_poses(model):
    report = {}
    moving, fixed = moving_objects(model), fixed_objects(model)
    for name in model["travel"]:
        pose(model, name)
        hits = {}
        for m in moving:
            for f in fixed:
                if m.name.startswith("rack") and f.name.startswith("pinion"):
                    continue
                value = overlap(m, f)
                if value > 0.05:
                    hits[f"{m.name} x {f.name}"] = round(value, 2)
        rack, pinion = model["printed"]["rack"][0], model["printed"]["pinion"][0]
        report[name] = {"hits": hits, "rack_vs_pinion": round(overlap(rack, pinion), 3)}
    pose(model, "closed")
    # Static pairs: tub and head together, ESP strap against the board.
    p = model["printed"]
    shells = [p["tub"][0], p["head"][0]]
    report["static"] = {"tub_x_head": round(overlap(shells[0], shells[1]), 2)}
    for shell in shells:
        for other in [p["pinion"][0], p["esp_strap"][0], p["muzzle"][0]] + [o for o, _ in model["electronics"]]:
            if shell is shells[0] and other is p["muzzle"][0]:
                continue
            value = overlap(shell, other)
            if value > 0.05:
                report["static"][f"{shell.name} x {other.name}"] = round(value, 2)
    return report


# ---------------------------------------------------------------- export and render

ORIENT = {"tub": Matrix.Identity(4), "head": Matrix.Rotation(math.pi, 4, "X"), "cap": Matrix.Rotation(math.pi, 4, "X"),
          "horn_rust": Matrix.Identity(4), "horn_sage": Matrix.Identity(4),
          "muzzle": Matrix.Rotation(-math.pi / 2, 4, "X"), "rack": Matrix.Rotation(math.pi / 2, 4, "X"),
          "guide_column": Matrix.Rotation(math.pi / 2, 4, "X"), "pinion": Matrix.Rotation(math.pi / 2, 4, "X"),
          "esp_strap": Matrix.Identity(4)}


def oriented(model, name, offset=(0, 0)):
    obj, colour = model["printed"][name]
    pose(model, "closed")
    dup = world_copy(obj)
    dup.name = f"print_{name}"
    dup.data.transform(ORIENT[name])
    verts = [v.co for v in dup.data.vertices]
    cx = (min(v.x for v in verts) + max(v.x for v in verts)) / 2
    cy = (min(v.y for v in verts) + max(v.y for v in verts)) / 2
    dup.data.transform(Matrix.Translation((-cx + offset[0], -cy + offset[1], -min(v.z for v in verts))))
    paint(dup, colour)
    dup.hide_render = dup.hide_viewport = False
    return dup


def export_all(model):
    folder = os.path.join(OUT, "stl")
    os.makedirs(folder, exist_ok=True)
    paths = []
    for name in model["printed"]:
        dup = oriented(model, name)
        path = os.path.join(folder, f"{name}.stl")
        yak.export_stl([dup], path)
        paths.append(path)
        yak.remove(dup)
    return paths


def render(path, look_at, distance, direction=(0.55, -1.0, 0.42), samples=40):
    scene = bpy.context.scene
    for obj in [o for o in bpy.data.objects if o.type in {"LIGHT", "CAMERA"} or o.name == "ground"]:
        bpy.data.objects.remove(obj, do_unlink=True)
    original = bpy.ops.render.render
    bpy.ops.render.render = lambda **kwargs: None
    try:
        yak.setup_render(path, look_at, distance)
    finally:
        bpy.ops.render.render = original
    scene.cycles.samples = samples
    scene.view_settings.view_transform = "Standard"
    scene.view_settings.exposure = 0.1
    camera = scene.camera
    target = Vector(look_at)
    camera.location = target + Vector(direction).normalized() * distance
    camera.rotation_euler = (target - camera.location).to_track_quat("-Z", "Y").to_euler()
    bpy.ops.render.render(write_still=True)


def set_alpha(objects, alpha):
    for obj in objects:
        for mat in obj.data.materials:
            mat.node_tree.nodes["Principled BSDF"].inputs["Alpha"].default_value = alpha


def all_objects(model):
    return [o for o, _ in model["printed"].values()] + [o for o, _ in model["electronics"]] + [model["stop_pin"]] + model["eyes"]["round"] + model["eyes"]["happy"] + model["eyes"]["glass"]


def main():
    model = build()
    p = model["printed"]
    print("[travel]", {k: round(v, 1) for k, v in model["travel"].items()}, "stop", TRAVEL_STOP, "lock tip", round(model["tip_z"], 1))
    report = check_poses(model)
    for name, row in report.items():
        print("[check]", name, row)
    for name in model["travel"]:
        pose(model, name)
        pts = [o.matrix_world @ v.co for o in (p["tub"][0], p["head"][0], p["cap"][0], p["horn_rust"][0], p["horn_sage"][0]) for v in o.data.vertices]
        print(f"[size] {name}: X {max(q.x for q in pts) - min(q.x for q in pts):.1f} Y {max(q.y for q in pts) - min(q.y for q in pts):.1f} Z {max(q.z for q in pts) - min(q.z for q in pts):.1f}")
    os.makedirs(os.path.join(OUT, "renders"), exist_ok=True)
    export_all(model)
    shell = [p[n][0] for n in ("tub", "head", "cap", "horn_rust", "horn_sage", "muzzle")]
    focus, dist = (0, 0, 66), 480
    for name in ("closed", "peek", "open"):
        pose(model, name)
        render(os.path.join(OUT, "renders", f"assembled-{name}.png"), focus, dist)
    # Exploded view.
    pose(model, "closed")
    offsets = {"head": (0, 0, 30), "tub": (0, 0, 0), "muzzle": (0, -25, 0), "pinion": (0, -45, 55), "esp_strap": (0, 0, 40), "stop_pin": (0, 0, 0)}
    for key, off in offsets.items():
        obj = model["stop_pin"] if key == "stop_pin" else p[key][0]
        obj.location = off
    model["empty"].location = (0, 0, 75)
    p["horn_rust"][0].location, p["horn_sage"][0].location = (-14, 0, 22), (14, 0, 22)
    p["rack"][0].location, p["guide_column"][0].location = (45, 0, -75), (-45, 0, -75)
    model["stop_pin"].location = (-45, 0, -75)
    for obj, _ in model["electronics"]:
        obj.location = (0, 70 if obj.name.startswith(("servo", "esp", "usb", "spline", "capacitor", "connector")) else -70, 30)
    for obj in model["eyes"]["round"] + model["eyes"]["glass"]:
        obj.location = (0, -70, 30)
    render(os.path.join(OUT, "renders", "exploded.png"), (0, 0, 80), 700, direction=(0.8, -1.0, 0.5))
    for obj in all_objects(model):
        obj.location = (0, 0, 0)
    for obj, _ in model["electronics"]:
        obj.location = (0, 0, 0)
    # Drive x-ray, open pose.
    pose(model, "open")
    set_alpha(shell, 0.1)
    for obj in shell:
        for mat in obj.data.materials:
            mat.blend_method = "BLEND"
    render(os.path.join(OUT, "renders", "xray-drive.png"), (0, -3, 62), 300, direction=(1.0, -0.12, 0.12))
    set_alpha(shell, 1.0)
    # Print plate: every printed part in print orientation.
    for obj in all_objects(model) + [model["empty"]]:
        obj.hide_render = True
        obj.hide_viewport = True
    layout = {"tub": (-70, 40), "head": (0, 40), "cap": (75, 40), "horn_rust": (-60, -30), "horn_sage": (-35, -30), "muzzle": (-5, -30),
              "rack": (25, -30), "guide_column": (50, -30), "pinion": (-60, -60), "esp_strap": (-35, -60)}
    for name, offset in layout.items():
        oriented(model, name, offset)
    render(os.path.join(OUT, "renders", "print-plate.png"), (0, 0, 20), 420, direction=(0.2, -1.0, 1.3))


if __name__ == "__main__":
    main()
