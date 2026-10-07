"""Mascot clips: presentation animations of the walker yak with jointed legs and arms.

Run: Blender -b -P mascot_clips.py -- <frames_dir> <clip> [--size 1920x1080] [--samples 48] [--frames first,last] [--step N] [--gpu]
Writes <frames_dir>/<clip>/NNNN.png as transparent 24 fps frames; the contact shadow is soft alpha. Each clip lives in
clips/<clip>.py and defines FRAMES, animate(rig, frame) and optionally setup(rig) and CAMERA. Encoding is done by
encode_clips.sh. Every pose is a pure function of the frame number, so any frame range can be re-rendered on its own.
"""

import importlib
import math
import os
import sys

import bpy
from mathutils import Matrix, Vector

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
ARGS = sys.argv[sys.argv.index("--") + 1:]
# mascot_motion reads its own arguments at import, so it sees only the frames folder.
sys.argv = sys.argv[:sys.argv.index("--") + 2]
import cursor_yak as yak  # noqa: E402
import mascot_motion as motion  # noqa: E402
FPS = 24
LIFT = yak.LEG_HEIGHT
HIP_Z = LIFT
HEAD_PIVOT = Vector((0, 0, 40))
SHOULDER = (18.4, -2.0, LIFT + 19.0)
HAND = (19.6, -3.5, LIFT + 7.0)
DEFAULT_CAMERA = {"look_at": (0, 0, 34), "direction": (0.42, -1.0, 0.2), "height": 135.0}


def option(name, default):
    return ARGS[ARGS.index(name) + 1] if name in ARGS else default


def translate(x=0.0, y=0.0, z=0.0):
    return Matrix.Translation((x, y, z))


def about(pivot, rotation):
    return translate(*pivot) @ rotation @ translate(*(-Vector(pivot)))


def rotation(axis, degrees):
    return Matrix.Rotation(math.radians(degrees), 4, axis)


def keys(t, frames):
    """Shorthand for motion.spline with times in seconds."""
    return motion.spline(t, frames)


class Rig:
    """The walker split into torso, legs, arms, muzzle, fringe and horns, posed by plain numbers in degrees and mm."""

    def __init__(self):
        yak.clear_scene()
        torso = yak.rounded_box("torso", (yak.BODY_WIDTH, yak.BODY_DEPTH, yak.BODY_HEIGHT + 1.5), (0, 0, LIFT + (yak.BODY_HEIGHT - 1.5) / 2), 5.0)
        cut = yak.rounded_box("cut", (400, 400, 100), (0, 0, LIFT - 50), 0.01, segments=1)
        yak.boolean(torso, cut, "DIFFERENCE")
        yak.remove(cut)
        parts = [(torso, "slate")]
        self.legs, self.arms = {}, {}
        for side in (-1, 1):
            leg = yak.rounded_box(f"leg{side}", (14.0, 26.0, LIFT + 6.0), (side * 9.0, -1.0, (LIFT + 6.0) / 2 - 1.5), 4.0)
            yak.flatten_base(leg)
            arm = yak.capsule(f"arm{side}", 3.8, (side * SHOULDER[0], SHOULDER[1], SHOULDER[2]), (side * HAND[0], HAND[1], HAND[2]))
            parts += [(leg, "slate"), (arm, "slate")]
            self.legs[side], self.arms[side] = leg, arm
        fringe = yak.build_fringe(torso, LIFT)
        muzzle = yak.build_muzzle(torso, LIFT)
        envelope = yak.head_box("envelope", LIFT, LIFT)
        self.horns = {}
        for side, colour in ((-1, "rust"), (1, "sage")):
            horn = yak.horn(f"horn_{colour}", side, LIFT)
            yak.boolean(horn, envelope, "DIFFERENCE")
            parts.append((horn, colour))
            self.horns[side] = horn
        yak.remove(envelope)
        parts += [(fringe, "cream"), (muzzle, "peach")]
        for obj, colour in parts:
            yak.triangulate_clean(obj)
            obj.data.set_sharp_from_angle(angle=math.radians(35))
            obj.data.polygons.foreach_set("use_smooth", [True] * len(obj.data.polygons))
            obj.data.materials.clear()
            obj.data.materials.append(yak.material(colour, yak.COLOURS[colour]))
        self.torso, self.fringe, self.muzzle = torso, fringe, muzzle
        self.upper = Matrix.Identity(4)
        self.root = Matrix.Identity(4)
        self.cap = Matrix.Identity(4)

    def pose(self, x=0.0, y=0.0, z=0.0, turn=0.0, lean=0.0, roll=0.0, base=None,
             twist=0.0, bend=0.0, side_bend=0.0, squash=0.0, hip_drop=0.0,
             nod=0.0, tilt=0.0, head_turn=0.0, lift=0.0, horn_droop=0.0,
             arm_l=(), arm_r=(), leg_l=(), leg_r=()):
        """Places every part.

        Root: x, y, z move the whole yak; turn spins it about z (0 faces -Y, the camera side); lean tips it forward and
        roll tips it sideways about the point between the feet; base is an optional extra matrix applied first.
        Upper body (about the hips): twist, bend (forward), side_bend, squash (stretch up when positive), hip_drop (mm down).
        Head cap (fringe and horns): nod, tilt, head_turn, lift (mm up), horn_droop.
        Arms: (swing forward, raise outward, cross inward, stretch) as degrees, degrees, degrees, length factor.
        Legs: (swing forward, spread outward, lift mm, heel raise degrees about the toe).
        """
        root = (base or Matrix.Identity(4)) @ translate(x, y, z) @ rotation("Z", turn) @ rotation("X", lean) @ rotation("Y", roll)
        hip = (0, -1, HIP_Z)
        upper = (root @ translate(z=-hip_drop)
                 @ about(hip, rotation("Z", twist) @ rotation("X", bend) @ rotation("Y", side_bend))
                 @ about(hip, Matrix.Diagonal((1 - squash / 2, 1 - squash / 2, 1 + squash, 1))))
        cap = upper @ translate(z=lift) @ about(HEAD_PIVOT, rotation("Z", head_turn) @ rotation("X", nod) @ rotation("Y", tilt))
        self.root, self.upper, self.cap = root, upper, cap
        self.torso.matrix_world = upper
        self.muzzle.matrix_world = upper
        self.fringe.matrix_world = cap
        for side, horn in self.horns.items():
            horn.matrix_world = cap @ about((side * 11.0, 0, 51.0), rotation("Y", side * horn_droop))
        for side, values in ((-1, arm_l), (1, arm_r)):
            swing, raise_, cross, stretch = (tuple(values) + (0.0, 0.0, 0.0, 1.0)[len(values):])
            shoulder = (side * SHOULDER[0], SHOULDER[1], SHOULDER[2])
            local = about(shoulder, rotation("Z", -side * cross) @ rotation("Y", -side * raise_) @ rotation("X", -swing)
                          @ Matrix.Diagonal((1, 1, stretch, 1)))
            self.arms[side].matrix_world = upper @ local
        for side, values in ((-1, leg_l), (1, leg_r)):
            swing, spread, raise_mm, heel = (tuple(values) + (0.0, 0.0, 0.0, 0.0)[len(values):])
            leg_hip = (side * 9.0, -1.0, HIP_Z + 2.0)
            toe = (side * 9.0, -14.0, 0.0)
            local = (translate(z=raise_mm) @ about(leg_hip, rotation("Y", -side * spread) @ rotation("X", -swing))
                     @ about(toe, rotation("X", heel)))
            self.legs[side].matrix_world = root @ local

    def head_point(self, offset):
        """A point in the head cap's frame, for props that follow the head (thought bubbles, light bulbs)."""
        return self.cap @ Vector(offset)

    def hand(self, side):
        """World position of the end of an arm."""
        arm = self.arms[side]
        return arm.matrix_world @ Vector((side * HAND[0], HAND[1], HAND[2] - 3.8))


def material(name, colour, emission=0.0, roughness=0.5):
    mat = bpy.data.materials.new(name)
    mat.use_nodes = True
    bsdf = mat.node_tree.nodes["Principled BSDF"]
    bsdf.inputs["Base Color"].default_value = (*colour, 1)
    bsdf.inputs["Roughness"].default_value = roughness
    if emission:
        bsdf.inputs["Emission Color"].default_value = (*colour, 1)
        bsdf.inputs["Emission Strength"].default_value = emission
    return mat


def srgb(hex_colour):
    """Linear RGB from an sRGB hex string such as '#5e6ad2'."""
    values = [int(hex_colour.lstrip("#")[i:i + 2], 16) / 255 for i in (0, 2, 4)]
    return tuple(v / 12.92 if v <= 0.04045 else ((v + 0.055) / 1.055) ** 2.4 for v in values)


def text_object(name, body, size, colour=yak.COLOURS["ink"], extrude=0.6):
    curve = bpy.data.curves.new(name, "FONT")
    curve.body = body
    curve.font = bpy.data.fonts.load(os.path.join(HERE, "JetBrainsMono-ExtraBold.ttf"), check_existing=True)
    curve.size, curve.extrude, curve.align_x, curve.align_y = size, extrude, "CENTER", "CENTER"
    obj = bpy.data.objects.new(name, curve)
    bpy.context.collection.objects.link(obj)
    curve.materials.append(material(name, colour, roughness=0.6))
    return obj


def facing_camera(camera, location, scale=1.0, roll=0.0):
    """Matrix that places a flat prop at location, turned to face the camera."""
    return (Matrix.Translation(location) @ camera.matrix_world.to_3x3().to_4x4() @ rotation("Z", roll)
            @ Matrix.Diagonal((scale, scale, scale, 1)))


def place_camera(camera, settings):
    view_height = settings["height"]
    distance = view_height / (36 / 70 * 9 / 16)
    motion.aim(camera, settings["look_at"], settings["direction"], distance)


def main():
    folder, name = ARGS[0], ARGS[1]
    width, height = (int(v) for v in option("--size", "1920x1080").split("x"))
    samples = int(option("--samples", "48"))
    step = int(option("--step", "1"))
    clip = importlib.import_module(f"clips.{name}")
    rig = Rig()
    camera = motion.setup_scene(width, height, True, samples)
    bpy.context.scene.cycles.use_denoising = True
    if "--gpu" in ARGS:
        preferences = bpy.context.preferences.addons["cycles"].preferences
        preferences.compute_device_type = "METAL"
        preferences.get_devices()
        for device in preferences.devices:
            device.use = device.type == "METAL"
        bpy.context.scene.cycles.device = "GPU"
        bpy.context.scene.cycles.denoising_use_gpu = True
    bpy.context.scene.render.use_persistent_data = True
    bpy.context.scene.cycles.adaptive_threshold = 0.02
    bpy.context.scene.render.fps = FPS
    settings = dict(DEFAULT_CAMERA, **getattr(clip, "CAMERA", {}))
    place_camera(camera, settings)
    rig.camera = camera
    if hasattr(clip, "setup"):
        clip.setup(rig)
    first, last = (int(v) for v in option("--frames", f"0,{clip.FRAMES - 1}").split(","))
    lights = {obj: obj.location.copy() for obj in bpy.data.objects if obj.type == "LIGHT"}
    out = os.path.join(folder, name)
    os.makedirs(out, exist_ok=True)
    for frame in range(first, last + 1, step):
        path = os.path.join(out, f"{frame:04d}.png")
        if os.path.exists(path):
            continue
        rig.pose()
        clip.animate(rig, frame)
        # The lights travel with the yak across the floor, so its shading and shadow stay the same wherever it goes.
        travel = rig.root.translation
        for light, home in lights.items():
            light.location = home + Vector((travel.x, travel.y, 0))
        if hasattr(clip, "camera"):
            place_camera(camera, dict(settings, **clip.camera(frame)))
        bpy.context.view_layer.update()
        motion.render(path)


if __name__ == "__main__":
    main()
