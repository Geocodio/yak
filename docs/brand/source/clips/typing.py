"""Typing: the yak stands at a laptop on a small desk and types fast, hits Enter, then jumps with both arms up while
confetti bursts from behind the laptop."""

import math
import random

import bpy
from mathutils import Matrix, Vector

import cursor_yak as yak
import yak_laptop
from mascot_clips import HAND, SHOULDER, keys, rotation, srgb, translate
from mascot_motion import ease_out_back, smoothstep

FRAMES = 192
CAMERA = {"look_at": (0, -10, 46), "direction": (0.5, -1.0, 0.45), "height": 134.0}

DESK_HEIGHT = 9.0
LAPTOP_SHIFT = Vector((0, 2.5, DESK_HEIGHT))
KEY_Z = DESK_HEIGHT + yak_laptop.BASE_HEIGHT + 0.6
KEY_Y = -26.0
HITS_PER_SECOND = 4.5
TYPING_START, ENTER_TIME, JUMP_TIME = 1.1, 4.7, 5.5
PIECE_COUNT = 120
BURST_ORIGIN = Vector((0, -33.0, 33.0))
GRAVITY = 520.0
DRAG = 0.9


def hand_local(side, swing, raise_degrees, stretch):
    """The arm tip in upper-body coordinates, mirroring Rig.pose."""
    shoulder = Vector((side * SHOULDER[0], SHOULDER[1], SHOULDER[2]))
    tip = Vector((side * HAND[0], HAND[1], HAND[2] - 3.8)) - shoulder
    tip.z *= stretch
    turn = rotation("Y", -side * raise_degrees) @ rotation("X", -swing)
    return shoulder + turn.to_3x3() @ tip


def reach(side, target, guess=(70.0, 0.0, 1.3)):
    """Arm (swing, raise, 0, stretch) that puts the tip at target (upper-body coordinates), by Gauss-Newton."""
    x = list(guess)
    low, high = (0.0, -40.0, 1.0), (180.0, 90.0, 1.8)
    for _ in range(40):
        error = target - hand_local(side, *x)
        if error.length < 0.05:
            break
        columns = []
        for index, step in enumerate((0.5, 0.5, 0.01)):
            shifted = list(x)
            shifted[index] += step
            columns.append((hand_local(side, *shifted) - hand_local(side, *x)) / step)
        try:
            delta = Matrix(columns).transposed().inverted() @ error
        except ValueError:
            break
        for index in range(3):
            x[index] = min(max(x[index] + delta[index] * 0.8, low[index]), high[index])
    return (x[0], x[1], 0.0, x[2])


def smooth_shaded(obj, material):
    obj.data.set_sharp_from_angle(angle=math.radians(35))
    obj.data.polygons.foreach_set("use_smooth", [True] * len(obj.data.polygons))
    obj.data.materials.clear()
    obj.data.materials.append(material)


def key_hash(number, salt):
    return random.Random(number * 31 + salt).uniform(-1, 1)


def keyboard_target(side, t):
    """Where one hand's tip is in world space: tapping while typing, resting after."""
    period = 1 / (HITS_PER_SECOND)
    phase_time = t / period + (0.5 if side > 0 else 0.0)
    number, fraction = int(math.floor(phase_time)), phase_time - math.floor(phase_time)
    blend = smoothstep(fraction)
    x = side * 8.0 + (key_hash(number, side) * (1 - blend) + key_hash(number + 1, side) * blend) * 4.5
    y = KEY_Y + (key_hash(number, side + 7) * (1 - blend) + key_hash(number + 1, side + 7) * blend) * 2.0
    height = 4.5 * (1 - math.cos(2 * math.pi * fraction)) / 2
    typing = smoothstep((t - TYPING_START) / 0.2) * (1 - smoothstep((t - ENTER_TIME + 0.35) / 0.15))
    approach = smoothstep((t - 0.5) / 0.6)
    raised = 8.0 * (1 - approach)
    z = KEY_Z + raised + height * typing + 0.4 * (1 - typing)
    return Vector((x, y, z))


def enter_target(t):
    """The right hand's Enter strike: wind up high and right, slam down, rest."""
    z = keys(t, [(ENTER_TIME - 0.35, KEY_Z + 4), (ENTER_TIME - 0.12, KEY_Z + 16), (ENTER_TIME, KEY_Z), (ENTER_TIME + 0.2, KEY_Z), (ENTER_TIME + 0.5, KEY_Z + 1.5)])
    x = keys(t, [(ENTER_TIME - 0.35, 8), (ENTER_TIME - 0.12, 13), (ENTER_TIME, 14)])
    return Vector((x, KEY_Y + 1, z))


def body_params(t):
    typing = smoothstep((t - TYPING_START) / 0.3) * (1 - smoothstep((t - ENTER_TIME + 0.3) / 0.2))
    beat = math.sin(2 * math.pi * HITS_PER_SECOND * t)
    # Upright through the jump, leaning in over the keys before it.
    bend = keys(t, [(0.4, 0), (1.0, 6), (ENTER_TIME - 0.1, 6), (ENTER_TIME + 0.05, 12), (ENTER_TIME + 0.4, 6),
                    (JUMP_TIME - 0.25, 8), (JUMP_TIME + 0.1, 0)])
    jump = JUMP_TIME + 0.12
    air = max(0.0, min(1.0, (t - jump) / 0.6))
    height = 15.0 * 4 * air * (1 - air)
    landing = keys(t, [(jump + 0.6, 0.0), (jump + 0.72, -0.2), (jump + 0.95, 0.04), (jump + 1.2, 0.0)])
    crouch = keys(t, [(JUMP_TIME - 0.3, 0.0), (JUMP_TIME, -0.12), (JUMP_TIME + 0.12, 0.0)])
    stretch_up = 0.10 * math.sin(math.pi * air) if 0 < air < 1 else 0.0
    cheer = max(0.0, t - (jump + 1.0))
    hop = 1.2 * abs(math.sin(math.pi * cheer * 2.2)) * math.exp(-cheer * 0.7) if cheer > 0 else 0.0
    return dict(
        z=height + hop,
        bend=bend,
        squash=landing + crouch + stretch_up,
        hip_drop=typing * 0.5 * (0.5 - 0.5 * beat) + keys(t, [(JUMP_TIME - 0.3, 0), (JUMP_TIME, 2.0), (JUMP_TIME + 0.12, 0)]),
        side_bend=typing * 1.5 * math.sin(math.pi * HITS_PER_SECOND * t),
        lift=(typing * (0.8 + 0.8 * math.sin(2 * math.pi * HITS_PER_SECOND * t + 1.0))
              + keys(t, [(ENTER_TIME, 0), (ENTER_TIME + 0.1, 3), (ENTER_TIME + 0.4, 0)])
              + 15.0 * ease_out_back((t - jump) / 0.25, 1.2) * (1 - smoothstep((t - jump - 0.3) / 0.5))
              + (4.0 * smoothstep((t - jump - 0.3) / 0.3)) * (1 + 0.3 * math.sin(7 * (t - jump)) * math.exp(-(t - jump)))),
        horn_droop=typing * 4 * math.sin(2 * math.pi * HITS_PER_SECOND * t - 1.0) + 12 * math.sin(6 * max(0, t - jump)) * math.exp(-2.5 * max(0, t - jump)),
        leg_l=(0, 0, 0), leg_r=(0, 0, 0),
    )


def cheer_arm(side, t):
    jump = JUMP_TIME + 0.12
    wave = math.sin(2 * math.pi * 2.0 * (t - jump) + (math.pi if side > 0 else 0)) * math.exp(-0.15 * max(0, t - jump))
    return (0.0, 160.0 + 8.0 * wave, 0.0, 1.5)


def arms(rig, body, t):
    """Both arms' parameters: reaching to the keys, then thrown up for the jump."""
    result = {}
    hold = enter_target(ENTER_TIME + 0.6)
    for side in (-1, 1):
        if t < ENTER_TIME - 0.4:
            target = keyboard_target(side, t)
        elif side > 0:
            target = enter_target(t)
        else:
            target = keyboard_target(-1, ENTER_TIME - 0.4) if t < ENTER_TIME + 0.6 else Vector((-8, KEY_Y, KEY_Z))
        if side > 0 and t >= ENTER_TIME + 0.5:
            target = enter_target(ENTER_TIME + 0.5)
        local = rig.upper.inverted() @ target
        keys_arm = reach(side, local)
        up = cheer_arm(side, t)
        blend = ease_out_back((t - (JUMP_TIME + 0.02)) / 0.22, 1.1) if t > JUMP_TIME - 0.1 else 0.0
        result[side] = tuple(a + (b - a) * blend for a, b in zip(keys_arm, up))
    return result


def build_scene(rig):
    ink, cream = yak.COLOURS["ink"], yak.COLOURS["cream"]
    desk = yak.rounded_box("desk", (50.0, 28.0, DESK_HEIGHT + 3.0), (0, -28.5, (DESK_HEIGHT - 3.0) / 2), 2.0)
    smooth_shaded(desk, yak.material("desk", srgb("#7f9a5e")))
    # A 16:10 screen: the lid rises 21 mm above the base, with the prompt centred on it.
    yak_laptop.LID_HEIGHT = 23.0
    yak_laptop.PROMPT_Z = 12.5
    laptop, inlay = yak_laptop.build_laptop()
    underscore = inlay.copy()
    underscore.data = inlay.data.copy()
    bpy.context.collection.objects.link(underscore)
    box = yak.rounded_box("split", (30, 20, 60), (14.5, yak_laptop.BASE_FRONT, 10), 0.01, segments=1)
    yak.boolean(underscore, box, "INTERSECT")
    yak.boolean(inlay, box, "DIFFERENCE")
    yak.remove(box)
    for name, part, colour in (("laptop_body", laptop, ink), ("laptop_prompt", inlay, cream), ("laptop_cursor", underscore, cream)):
        smooth_shaded(part, yak.material(name, colour))
        part.matrix_world = translate(*LAPTOP_SHIFT)
    rig.underscore = underscore


def piece_state(index, now):
    """Position, spin angles and visibility of one confetti piece at time now."""
    chance = random.Random(index * 7919 + 3)
    birth = JUMP_TIME + 0.08 + chance.uniform(0, 0.12)
    colour = chance.choice(range(5))
    direction = chance.uniform(0, 2 * math.pi)
    spread = chance.uniform(15, 75)
    velocity = Vector((math.cos(direction) * spread, math.sin(direction) * spread * 0.45 - 10, chance.uniform(150, 290)))
    spin = (chance.uniform(-14, 14), chance.uniform(-14, 14), chance.uniform(-10, 10))
    sway = chance.uniform(0.5, 2.5), chance.uniform(0, 6.28), chance.uniform(2, 7)
    position = BURST_ORIGIN + Vector((chance.uniform(-10, 10), 0, 0))
    seconds = now - birth
    if seconds < 0:
        return colour, None, spin, 0.0
    angle = spin[0] * seconds
    for step in range(int(seconds * 120)):
        velocity.z -= GRAVITY / 120
        velocity *= 1 - DRAG / 120
        velocity.z = max(velocity.z, -28.0)
        flutter = Vector((math.cos(sway[1] + sway[0] * step / 120 * 6.28) * sway[2], 0, 0))
        position += (velocity + flutter) / 120
        floor = DESK_HEIGHT + 3.0 if abs(position.x) < 25 and -42 < position.y < -14 else 0.3
        if position.z <= floor and velocity.z <= 0:
            position.z = floor
            velocity = Vector((0, 0, 0))
            break
    resting = velocity.length == 0
    return colour, position, spin, (0.0 if resting else seconds)


def setup(rig):
    build_scene(rig)
    colours = [srgb(h) for h in ("#7f9cb4", "#c4643a", "#7f9a5e", "#e3976b", "#efe4c9")]
    mesh = yak.rounded_box("piece_source", (1, 1, 1), (0, 0, 0), 0.01, segments=1).data
    bpy.data.objects.remove(bpy.data.objects["piece_source"], do_unlink=True)
    meshes = []
    for number, colour in enumerate(colours):
        copy = mesh.copy()
        copy.materials.append(yak.material(f"confetti{number}", colour))
        meshes.append(copy)
    rig.confetti = []
    for index in range(PIECE_COUNT):
        colour = random.Random(index * 7919 + 3)
        colour.uniform(0, 0.12)
        obj = bpy.data.objects.new(f"confetti{index}", meshes[index % 5])
        bpy.context.collection.objects.link(obj)
        rig.confetti.append(obj)


def animate(rig, frame):
    t = frame / 24
    body = body_params(t)
    rig.pose(**body)
    placed = arms(rig, body, t)
    rig.pose(arm_l=placed[-1], arm_r=placed[1], **body)

    blinking = t < ENTER_TIME + 0.2
    rig.underscore.hide_render = rig.underscore.hide_viewport = blinking and int(t * 4) % 2 == 1

    for index, obj in enumerate(rig.confetti):
        colour, position, spin, flight = piece_state(index, t)
        obj.hide_render = obj.hide_viewport = position is None
        if position is not None:
            chance = random.Random(index * 13)
            size = chance.uniform(3.4, 5.0)
            flap = math.cos(flight * 11 + index) if flight else 1.0
            obj.matrix_world = (translate(*position) @ rotation("Z", spin[2] * flight * 20 + index * 40)
                                @ rotation("X", spin[0] * flight * 12 + index * 20)
                                @ Matrix.Diagonal((size, size * 0.6 * (0.4 + 0.6 * abs(flap)), 0.12, 1)))
