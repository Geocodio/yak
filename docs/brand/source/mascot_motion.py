"""Mascot motion: renders the walker yak's login video frames and sleeping loop frames as PNG sequences.

Run: Blender -b -P mascot_motion.py -- <frames_dir> [video|sleep|all] [first_frame last_frame]
Writes <frames_dir>/video/NNNN.png (1280x720, 24 fps, 120 frames, opaque) and <frames_dir>/sleep/NNNN.png
(520x459, transparent, SLEEP_FRAMES frames that loop). Encoding is done by the commands in docs/brand/README.md.
Every pose is a pure function of the frame number, so the sleeping loop closes exactly on itself.
"""

import math
import os
import shutil
import sys

import bpy
from mathutils import Matrix, Vector

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import cursor_yak as yak  # noqa: E402

ARGS = sys.argv[sys.argv.index("--") + 1:]
MODE = ARGS[1] if len(ARGS) > 1 else "all"
FRAME_RANGE = (int(ARGS[2]), int(ARGS[3])) if len(ARGS) > 3 else None
HERE = os.path.dirname(os.path.abspath(__file__))

VIDEO_FPS, VIDEO_FRAMES = 24, 180
SLEEP_FPS, SLEEP_FRAMES = 12, 42
PAPER = (0.905, 0.884, 0.838)  # linear value of about #f4f1ea, a light warm neutral that sits beside the white login card
HEAD_PIVOT = Vector((0, 0, 40))
CAP_LIFT = 17.0
GLASSES_Z, GLASSES_HEIGHT = 40.5, 9.0
SHORT_DROP = 8.0  # the shorter login-video walker: body height 39 instead of 47, fringe edge just above the muzzle


def apply_proportions(short):
    """Sets the walker's proportions in memory (cursor_yak.py itself is untouched). The short build lowers the head and
    fringe edge, drops the horns with the crown and puts the full-size sunglasses about 3 mm above the muzzle."""
    global CAP_LIFT, GLASSES_Z, GLASSES_HEIGHT
    yak.BODY_HEIGHT = 47.0 - (SHORT_DROP if short else 0.0)
    yak.FRINGE_BOTTOM = 19.8 if short else 26.0
    if not hasattr(yak, "original_horn"):
        yak.original_horn = yak.horn
    drop = SHORT_DROP if short else 0.0
    yak.horn = lambda name, side, lift, **kwargs: yak.original_horn(name, side, lift - drop, **kwargs)
    CAP_LIFT = 16.0 if short else 17.0
    GLASSES_Z, GLASSES_HEIGHT = (33.5, 9.0) if short else (40.5, 9.0)


def translate(x=0.0, y=0.0, z=0.0):
    return Matrix.Translation((x, y, z))


def about(pivot, rotation):
    return translate(*pivot) @ rotation @ translate(*(-Vector(pivot)))


def smoothstep(u):
    u = min(max(u, 0.0), 1.0)
    return u * u * (3 - 2 * u)


def ease_out_back(u, overshoot=1.5):
    u = min(max(u, 0.0), 1.0)
    return 1 + (overshoot + 1) * (u - 1) ** 3 + overshoot * (u - 1) ** 2


def segment(frame, start, end):
    """0 before start, 1 after end, linear in between (times in seconds)."""
    return min(max((frame / VIDEO_FPS - start) / (end - start), 0.0), 1.0)


def spline(t, keys):
    """Monotone cubic (PCHIP) through (time, value) keys: smooth, never overshoots between keys, flat outside them."""
    if t <= keys[0][0]:
        return keys[0][1]
    if t >= keys[-1][0]:
        return keys[-1][1]
    slopes = [(keys[i + 1][1] - keys[i][1]) / (keys[i + 1][0] - keys[i][0]) for i in range(len(keys) - 1)]
    tangents = [0.0]
    for i in range(1, len(keys) - 1):
        left, right = slopes[i - 1], slopes[i]
        tangents.append(0.0 if left * right <= 0 else 2 * left * right / (left + right))
    tangents.append(0.0)
    for i in range(len(keys) - 1):
        (t0, v0), (t1, v1) = keys[i], keys[i + 1]
        if t <= t1:
            h, u = t1 - t0, (t - t0) / (t1 - t0)
            return ((2 * u ** 3 - 3 * u ** 2 + 1) * v0 + (u ** 3 - 2 * u ** 2 + u) * h * tangents[i]
                    + (-2 * u ** 3 + 3 * u ** 2) * v1 + (u ** 3 - u ** 2) * h * tangents[i + 1])


def build_model(short=False):
    apply_proportions(short)
    if "lens" in bpy.data.materials:
        bpy.data.materials.remove(bpy.data.materials["lens"])
    parts = yak.build_parts("walker")
    objects = {}
    for obj, colour in parts:
        yak.triangulate_clean(obj)
        obj.data.set_sharp_from_angle(angle=math.radians(35))
        obj.data.polygons.foreach_set("use_smooth", [True] * len(obj.data.polygons))
        obj.data.materials.clear()
        obj.data.materials.append(yak.material(colour, yak.COLOURS[colour]))
        objects[obj.name.split(".")[0]] = obj
    model = {"body": objects["body"], "muzzle": objects["muzzle"], "fringe": objects["fringe"],
             "horns": {-1: objects["horn_rust"], 1: objects["horn_sage"]}, "glasses": []}
    lens_material = yak.material("lens", yak.COLOURS["ink"])
    nodes, links = lens_material.node_tree.nodes, lens_material.node_tree.links
    bsdf = nodes["Principled BSDF"]
    bsdf.inputs["Roughness"].default_value = 0.18
    # A diagonal glint band sweeps across both lenses; model["glint"] is its position along x + z.
    coordinates = nodes.new("ShaderNodeTexCoord")
    split = nodes.new("ShaderNodeSeparateXYZ")
    along = nodes.new("ShaderNodeMath")
    offset = nodes.new("ShaderNodeMath")
    distance = nodes.new("ShaderNodeMath")
    band = nodes.new("ShaderNodeMapRange")
    strength = nodes.new("ShaderNodeMath")
    position = nodes.new("ShaderNodeValue")
    links.new(coordinates.outputs["Object"], split.inputs[0])
    along.operation, offset.operation, distance.operation, strength.operation = "ADD", "SUBTRACT", "ABSOLUTE", "MULTIPLY"
    links.new(split.outputs[0], along.inputs[0])
    links.new(split.outputs[2], along.inputs[1])
    links.new(along.outputs[0], offset.inputs[0])
    links.new(position.outputs[0], offset.inputs[1])
    links.new(offset.outputs[0], distance.inputs[0])
    band.inputs["From Min"].default_value, band.inputs["From Max"].default_value = 0.0, 2.4
    band.inputs["To Min"].default_value, band.inputs["To Max"].default_value = 1.0, 0.0
    links.new(distance.outputs[0], band.inputs["Value"])
    links.new(band.outputs[0], strength.inputs[0])
    strength.inputs[1].default_value = 1.4
    bsdf.inputs["Emission Color"].default_value = (0.9, 0.95, 1.0, 1)
    links.new(strength.outputs[0], bsdf.inputs["Emission Strength"])
    position.outputs[0].default_value = -100.0
    model["glint"] = position
    # Thin lenses lie flush on the slate face, inside the 1.2 mm fringe clearance, so the fringe hides them while it is down.
    face = -15.0
    for name, size, centre in (("lens_left", (12.2, 1.0, GLASSES_HEIGHT), (-8.6, face - 0.85 + 0.5, GLASSES_Z)),
                               ("lens_right", (12.2, 1.0, GLASSES_HEIGHT), (8.6, face - 0.85 + 0.5, GLASSES_Z)),
                               ("bridge", (5.4, 0.9, 2.0), (0, face - 0.8 + 0.5, GLASSES_Z + 2.2))):
        obj = yak.rounded_box(name, (size[0], size[1] + 1.0, size[2]), (centre[0], centre[1] + 0.5, centre[2]), 0.45, segments=4)
        obj.data.polygons.foreach_set("use_smooth", [True] * len(obj.data.polygons))
        obj.data.materials.append(lens_material)
        model["glasses"].append(obj)
    return model


def pose(model, nod=0.0, squash=0.0, lift=0.0, hop=0.0, fringe_nod=0.0, horn_droop=0.0, pivot=0.0, show_glasses=True, glint=-100.0):
    """Places every object. nod and fringe_nod are forward tilts in radians, squash scales height (and inverse width)."""
    body = (translate(z=hop) @ about((0, 0, pivot), Matrix.Rotation(nod, 4, "X"))
            @ Matrix.Diagonal((1 - squash / 2, 1 - squash / 2, 1 + squash, 1)))
    cap = body @ translate(z=lift) @ about(HEAD_PIVOT, Matrix.Rotation(fringe_nod, 4, "X"))
    model["body"].matrix_world = body
    model["muzzle"].matrix_world = body
    model["fringe"].matrix_world = cap
    for side, horn in model["horns"].items():
        base = Vector((side * 11.0, 0, 51.0))
        horn.matrix_world = cap @ about(base, Matrix.Rotation(side * horn_droop, 4, "Y"))
    model["glint"].outputs[0].default_value = glint
    for obj in model["glasses"]:
        obj.hide_render = not show_glasses
        obj.matrix_world = body


def setup_scene(width, height, transparent, samples):
    scene = bpy.context.scene
    scene.render.engine = "CYCLES"
    scene.cycles.samples = samples
    scene.cycles.use_denoising = True
    scene.cycles.seed = 7
    scene.cycles.device = "CPU"  # the Metal kernel compile crashes Blender on some macOS builds
    scene.render.resolution_x, scene.render.resolution_y = width, height
    scene.render.resolution_percentage = 100
    scene.render.film_transparent = transparent
    scene.render.image_settings.file_format = "PNG"
    scene.render.image_settings.color_mode = "RGBA" if transparent else "RGB"
    scene.view_settings.view_transform = "Standard"
    scene.view_settings.exposure = 0.15

    # World: grey ambient light for the scene, flat paper colour for camera rays.
    world = bpy.data.worlds.get("World") or bpy.data.worlds.new("World")
    scene.world = world
    world.use_nodes = True
    nodes, links = world.node_tree.nodes, world.node_tree.links
    nodes.clear()
    ambient = nodes.new("ShaderNodeBackground")
    ambient.inputs["Color"].default_value = (0.82, 0.84, 0.86, 1)
    ambient.inputs["Strength"].default_value = 0.95
    backdrop = nodes.new("ShaderNodeBackground")
    backdrop.inputs["Color"].default_value = (*PAPER, 1)
    backdrop.inputs["Strength"].default_value = 2 ** -scene.view_settings.exposure
    light_path = nodes.new("ShaderNodeLightPath")
    mix = nodes.new("ShaderNodeMixShader")
    output = nodes.new("ShaderNodeOutputWorld")
    links.new(light_path.outputs["Is Camera Ray"], mix.inputs["Fac"])
    links.new(ambient.outputs["Background"], mix.inputs[1])
    links.new(backdrop.outputs["Background"], mix.inputs[2])
    links.new(mix.outputs["Shader"], output.inputs["Surface"])

    import bmesh
    bm = bmesh.new()
    bmesh.ops.create_grid(bm, x_segments=1, y_segments=1, size=600)
    ground = yak.object_from_bmesh("ground", bm)
    ground.is_shadow_catcher = True
    ground.data.materials.append(yak.material("ground", (0.80, 0.80, 0.80)))

    light_data = bpy.data.lights.new("key", "AREA")
    light_data.energy, light_data.size = 2.3e5, 220
    light = bpy.data.objects.new("key", light_data)
    light.location = (-120, -160, 220)
    light.rotation_euler = (math.radians(45), 0, math.radians(-35))
    bpy.context.collection.objects.link(light)

    rim_data = bpy.data.lights.new("rim", "AREA")
    rim_data.energy, rim_data.size = 2.0e5, 90
    rim_data.color = (1.0, 0.96, 0.9)
    rim = bpy.data.objects.new("rim", rim_data)
    rim.location = (170, 140, 130)
    rim.rotation_euler = Vector((-170, -140, -90)).to_track_quat("-Z", "Y").to_euler()
    bpy.context.collection.objects.link(rim)

    camera_data = bpy.data.cameras.new("camera")
    camera_data.lens = 70
    camera = bpy.data.objects.new("camera", camera_data)
    bpy.context.collection.objects.link(camera)
    scene.camera = camera
    return camera


def aim(camera, look_at, direction, distance):
    target = Vector(look_at)
    camera.location = target + Vector(direction).normalized() * distance
    camera.rotation_euler = (target - camera.location).to_track_quat("-Z", "Y").to_euler()
    bpy.context.view_layer.update()


def render(path):
    bpy.context.scene.render.filepath = path
    bpy.ops.render.render(write_still=True)


def run_frames(folder, count, params_for, camera_for, camera, extra=None):
    """Renders each frame, copying the previous file when nothing changed (held poses cost nothing)."""
    os.makedirs(folder, exist_ok=True)
    first, last = FRAME_RANGE or (0, count - 1)
    previous_key, previous_path = None, None
    for frame in range(count):
        path = os.path.join(folder, f"{frame:04d}.png")
        key = repr(([round(v, 5) for v in params_for(frame).values()], camera_for(frame)))
        if first <= frame <= last and not os.path.exists(path):
            if key == previous_key and previous_path and os.path.exists(previous_path):
                shutil.copyfile(previous_path, path)
            else:
                pose(MODEL, **params_for(frame))
                aim(camera, *camera_for(frame))
                if extra:
                    extra(frame)
                render(path)
        previous_key, previous_path = key, path


# ---------------------------------------------------------------- login video

VIDEO_DIRECTION = (0.5, -1.0, 0.24)
VIDEO_FRAMING = {}


def video_params(frame):
    """Opening beat, crouch, lift with overshoot, glasses reveal, nod, wiggle and glint; hold; then a lively pop back down
    to the exact starting pose, which is held until the end so the last frame matches frame 0."""
    t = frame / VIDEO_FPS
    k = CAP_LIFT / 17.0
    lift = k * spline(t, [(0, 0), (1.1, 0), (1.45, 8.5), (1.8, 19.6), (2.2, 16.1), (2.55, 17.4), (2.9, 17.0), (4.3, 17.0),
                          (4.5, 19.0), (4.82, -0.9), (5.05, 1.3), (5.3, -0.2), (5.55, 0), (7.5, 0)])
    stretch = spline(t, [(0, 0), (0.7, 0), (1.08, -0.05), (1.4, 0.035), (1.8, -0.012), (2.15, 0.006), (2.5, 0), (4.3, 0),
                         (4.5, 0.03), (4.85, -0.055), (5.12, 0.02), (5.4, -0.006), (5.65, 0), (7.5, 0)])
    hop = spline(t, [(0, 0), (1.15, 0), (1.5, 1.5), (1.85, 0), (4.4, 0), (4.62, 1.2), (4.82, 0), (7.5, 0)])
    nod = spline(t, [(0, 0), (0.7, 0), (1.08, 2.0), (1.5, -1.2), (2.1, 0), (2.45, 0), (2.75, 3.4), (3.15, -0.5), (3.5, 0),
                     (4.5, 0), (4.85, 2.4), (5.2, -0.8), (5.55, 0), (7.5, 0)])
    wiggle = spline(t, [(0, 0), (2.5, 0), (2.7, 6), (2.9, -4), (3.1, 3), (3.3, -1.5), (3.5, 0),
                        (4.85, 0), (5.0, 5), (5.2, -3), (5.4, 1.5), (5.6, 0), (7.5, 0)])
    glint = spline(t, [(0, 0), (2.45, 0), (3.2, 1), (5, 1)]) * 56 + 8 if 2.45 < t <= 3.25 else -100.0
    return {"lift": lift, "squash": stretch, "hop": hop, "nod": math.radians(nod), "horn_droop": math.radians(wiggle), "glint": glint}


def fit_camera():
    """Frames the settled yak so its horns and feet nearly touch the top and bottom, then derives a wider opening shot."""
    from bpy_extras.object_utils import world_to_camera_view
    scene = bpy.context.scene
    camera = scene.camera
    pose(MODEL, lift=CAP_LIFT * 1.15)
    points = [obj.matrix_world @ v.co for obj in [MODEL["body"], MODEL["fringe"], *MODEL["horns"].values()] for v in obj.data.vertices]
    look_at, distance = Vector((0, 0, 46)), 330.0
    for _ in range(8):
        aim(camera, look_at, VIDEO_DIRECTION, distance)
        ys = [world_to_camera_view(scene, camera, p).y for p in points]
        xs = [world_to_camera_view(scene, camera, p).x for p in points]
        height = max(ys) - min(ys)
        centre = (max(ys) + min(ys)) / 2 - 0.5 - 0.04
        centre_x = (max(xs) + min(xs)) / 2 - 0.5
        rotation = camera.matrix_world.to_3x3()
        view_height = distance * 36 / 70 * 9 / 16
        look_at = look_at + rotation @ Vector((centre_x * view_height * 16 / 9, centre * view_height, 0))
        distance *= height / 0.72
    VIDEO_FRAMING.update(look_at=look_at, distance=distance)


def video_camera(frame):
    return tuple(VIDEO_FRAMING["look_at"]), VIDEO_DIRECTION, VIDEO_FRAMING["distance"]


def render_video(folder):
    camera = setup_scene(1280, 720, True, 56)
    fit_camera()
    run_frames(os.path.join(folder, "video"), VIDEO_FRAMES, video_params, video_camera, camera)
    # The backdrop is exactly #f5f0e8: frames render transparent with the contact shadow in the alpha, then sit on that flat colour.
    feather_edges(os.path.join(folder, "video"), margin=0, flat=(245 / 255, 240 / 255, 232 / 255), shadow_box=(0.13, 0.19))


# ---------------------------------------------------------------- sleeping loop

SLEEP_DIRECTION = (0.42, -1.0, 0.3)
SLEEP_LOOK_AT = (-3, 0, 47)
SLEEP_DISTANCE = 305


def sleep_params(frame):
    p = frame / SLEEP_FRAMES
    # Dozing: the head sinks forward slowly, then drifts back up; two breaths per loop stay in step with it.
    dip = smoothstep(p / 0.6) if p < 0.6 else 1 - smoothstep((p - 0.6) / 0.4)
    breath = math.sin(2 * math.pi * 2 * p)
    return {"squash": 0.02 * breath, "pivot": 12.0, "nod": math.radians(2 + 8 * dip),
            "lift": 0.0, "show_glasses": False}


def sleep_camera(frame):
    return SLEEP_LOOK_AT, SLEEP_DIRECTION, SLEEP_DISTANCE


def make_zed(index, camera):
    curve = bpy.data.curves.new(f"zed{index}", "FONT")
    curve.body = "Z"
    curve.font = bpy.data.fonts.load(os.path.join(HERE, "JetBrainsMono-ExtraBold.ttf"))
    curve.size, curve.extrude, curve.align_x, curve.align_y = 10.0, 0.1, "CENTER", "CENTER"
    obj = bpy.data.objects.new(f"zed{index}", curve)
    bpy.context.collection.objects.link(obj)
    obj.visible_shadow = False
    material = bpy.data.materials.new(f"zed{index}")
    material.use_nodes = True
    nodes, links = material.node_tree.nodes, material.node_tree.links
    bsdf = nodes["Principled BSDF"]
    bsdf.inputs["Base Color"].default_value = (*yak.COLOURS["ink"], 1)
    bsdf.inputs["Roughness"].default_value = 0.6
    clear = nodes.new("ShaderNodeBsdfTransparent")
    mix = nodes.new("ShaderNodeMixShader")
    alpha = nodes.new("ShaderNodeValue")
    links.new(alpha.outputs[0], mix.inputs["Fac"])
    links.new(clear.outputs[0], mix.inputs[1])
    links.new(bsdf.outputs[0], mix.inputs[2])
    links.new(mix.outputs[0], nodes["Material Output"].inputs["Surface"])
    curve.materials.append(material)
    return obj, alpha


def place_zeds(frame, camera, zeds):
    rotation = camera.matrix_world.to_3x3()
    right, up = rotation @ Vector((1, 0, 0)), rotation @ Vector((0, 1, 0))
    for index, (obj, alpha) in enumerate(zeds):
        p = (frame / SLEEP_FRAMES + index / len(zeds)) % 1.0
        fade = min(1.0, p / 0.18, (1 - p) / 0.35)
        alpha.outputs[0].default_value = smoothstep(fade)
        # Screen-space path: starts above the head, rises and drifts right with a lazy sway.
        sway = math.sin(p * math.pi * 2) * 3.5
        centre = Vector((6, -4, 76)) + right * (p * 22 + sway) + up * (p * 24)
        scale = 0.55 + 0.75 * p
        roll = Matrix.Rotation(math.radians(10 - 20 * p), 4, "Z")
        obj.matrix_world = Matrix.Translation(centre) @ camera.matrix_world.to_3x3().to_4x4() @ roll @ Matrix.Diagonal((scale, scale, scale, 1))


def feather_edges(folder, margin=70, flat=None, shadow_box=None):
    """Fades alpha to zero at the image border, so the clipped tail of the contact shadow leaves no visible edge.
    With flat (sRGB 0 to 1), the frame is then composited over that flat colour (display-encoded sRGB values, as Blender image pixels are) and saved opaque."""
    import numpy
    for name in sorted(os.listdir(folder)):
        path = os.path.join(folder, name)
        image = bpy.data.images.load(path)
        image.alpha_mode = "CHANNEL_PACKED"
        width, height = image.size
        pixels = numpy.empty(width * height * 4, dtype=numpy.float32)
        image.pixels.foreach_get(pixels)
        pixels = pixels.reshape(height, width, 4)
        rows, columns = numpy.arange(height)[:, None], numpy.arange(width)[None, :]
        edge = numpy.minimum(numpy.minimum(rows, height - 1 - rows), numpy.minimum(columns, width - 1 - columns))
        if margin:
            fade = numpy.clip(edge / margin, 0, 1)
            pixels[:, :, 3] *= fade * fade * (3 - 2 * fade)
        if shadow_box:
            # Only the contact shadow (black, partly transparent pixels) is faded out towards the frame edges; the yak is untouched.
            near, far = shadow_box
            across = numpy.minimum(columns / width, 1 - columns / width)
            down = numpy.minimum(rows / height, 1 - rows / height)
            ramp = numpy.clip((numpy.minimum(across, down) - near) / (far - near), 0, 1)
            # A soft elliptical falloff around the feet keeps the shadow small, well inside the frame.
            radius = numpy.sqrt(((columns - 0.5 * width) / (0.27 * width)) ** 2 + ((rows - 0.22 * height) / (0.2 * height)) ** 2)
            blob = 1 - numpy.clip((radius - 0.35) / 0.65, 0, 1)
            ramp = ramp * blob * blob * (3 - 2 * blob)
            is_shadow = (pixels[:, :, :3].max(axis=2) < 0.05) & (pixels[:, :, 3] < 0.95)
            pixels[:, :, 3] = numpy.where(is_shadow, pixels[:, :, 3] * ramp, pixels[:, :, 3])
        if flat is not None:
            alpha = pixels[:, :, 3:4]
            pixels[:, :, :3] = pixels[:, :, :3] * alpha + numpy.array(flat, dtype=numpy.float32) * (1 - alpha)
            pixels[:, :, 3] = 1.0
        image.pixels.foreach_set(pixels.reshape(-1))
        image.filepath_raw = path
        image.file_format = "PNG"
        image.save()
        bpy.data.images.remove(image)


def render_sleep(folder):
    camera = setup_scene(520, 459, True, 128)
    zeds = [make_zed(i, camera) for i in range(3)]
    run_frames(os.path.join(folder, "sleep"), SLEEP_FRAMES, sleep_params, sleep_camera, camera,
               extra=lambda frame: place_zeds(frame, camera, zeds))
    feather_edges(os.path.join(folder, "sleep"))


def intersection_volume(first, second):
    """Volume of the overlap of two posed objects in cubic millimetres (touching surfaces give 0)."""
    import bmesh
    copies = []
    for obj in (first, second):
        copy = obj.copy()
        copy.data = obj.data.copy()
        bpy.context.collection.objects.link(copy)
        copy.data.transform(obj.matrix_world)
        copy.matrix_world = Matrix.Identity(4)
        copies.append(copy)
    yak.boolean(copies[0], copies[1], "INTERSECT")
    bm = bmesh.new()
    bm.from_mesh(copies[0].data)
    volume = abs(bm.calc_volume())
    bm.free()
    yak.remove(*copies)
    return volume


def check_sleep_clearance():
    """Every third frame of the sleeping loop: overlaps of the fringe and horns with the body and muzzle must match the
    rest pose (the muzzle top already tucks under the fringe locks in the model), so motion adds no intersection."""
    def overlaps():
        return [intersection_volume(cap, core) for cap in [MODEL["fringe"], *MODEL["horns"].values()]
                for core in (MODEL["body"], MODEL["muzzle"])]
    pose(MODEL)
    rest = overlaps()
    worst = 0.0
    for frame in range(0, SLEEP_FRAMES, 3):
        pose(MODEL, **sleep_params(frame))
        worst = max(worst, *(abs(now - before) for now, before in zip(overlaps(), rest)))
    print(f"CLEARANCE rest overlaps {[round(v, 4) for v in rest]}, worst change in motion {worst:.6f} mm3")


def render_compare(folder):
    """Four low-sample stills (old and short proportions, closed and revealed) for a proportion check."""
    os.makedirs(os.path.join(folder, "compare"), exist_ok=True)
    for label, short in (("old", False), ("new", True)):
        global MODEL
        MODEL = build_model(short)
        camera = setup_scene(640, 360, False, 24)
        fit_camera()
        for state, lift in (("closed", 0.0), ("open", CAP_LIFT)):
            pose(MODEL, lift=lift)
            aim(camera, VIDEO_FRAMING["look_at"], VIDEO_DIRECTION, VIDEO_FRAMING["distance"])
            render(os.path.join(folder, "compare", f"{label}-{state}.png"))


if __name__ == "__main__":
    if MODE == "compare":
        render_compare(ARGS[0])
        sys.exit(0)
    if MODE == "check":
        MODEL = build_model()
        check_sleep_clearance()
        sys.exit(0)
    if MODE in ("video", "all"):
        MODEL = build_model(short=True)
        render_video(ARGS[0])
    if MODE in ("sleep", "all"):
        MODEL = build_model()
        render_sleep(ARGS[0])
