"""Character set: renders the walker yak, its variants, the worn accessories and the desk buddy with one camera, one light rig and one look.

Run: blender -b -P character_set.py -- <renders_dir> [--scratch DIR] [--size 2048] [--samples 128] [--only name,name] [--measure] [--no-compose]
Blender renders each shot on the CPU as a transparent PNG (shadow as alpha), then runs `python3 character_set.py compose ...` to write
transparent/ and cream/ versions, lineup.png, accessories-grid.png and the contact sheet. The compose step needs Pillow and numpy only.
"""

import os
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
CREAM = (245, 240, 232)
SCRATCH_DEFAULT = "/private/tmp/claude-501/-Users-codemonkey-projects-yak/b25b726a-4745-4a22-b169-32655c10d268/scratchpad/charset"

# One camera for every walker-sized shot. Feet sit on the same screen line, the figure is centred sideways.
DIRECTION = (0.42, -1.0, 0.3)
DISTANCE = 270.0
LOOK_Z = 51.0
BUDDY_SCALE = 1.5

WALKER_SHOTS = ["walker", "sunglasses", "sleeping", "laptop", "shaving"]
ACCESSORY_SHOTS = ["sunglasses", "vr-goggles", "party-hat", "santa-hat", "hard-hat", "headphones"]
BUDDY_SHOTS = ["closed", "peek", "open"]
SHOTS = WALKER_SHOTS + [f"accessory-{name}" for name in ACCESSORY_SHOTS] + [f"desk-buddy-{name}" for name in BUDDY_SHOTS]
LINEUP = ["walker", "sunglasses", "sleeping", "laptop", "shaving", "desk-buddy-open"]
LABELS = {name: name.replace("accessory-", "").replace("-", " ") for name in SHOTS}
LABELS.update({"sunglasses": "sunglasses (fringe lift)", "accessory-sunglasses": "accessory: sunglasses"})


# ---------------------------------------------------------------- Blender side

def options():
    args = sys.argv[sys.argv.index("--") + 1:] if "--" in sys.argv else sys.argv[1:]
    out = {"renders": args[0] if args else os.path.join(HERE, "..", "renders"), "scratch": SCRATCH_DEFAULT, "size": 2048, "samples": 128,
           "only": None, "measure": False, "compose": True}
    flags = iter(args[1:])
    for flag in flags:
        if flag == "--scratch":
            out["scratch"] = next(flags)
        elif flag == "--size":
            out["size"] = int(next(flags))
        elif flag == "--samples":
            out["samples"] = int(next(flags))
        elif flag == "--only":
            out["only"] = next(flags).split(",")
        elif flag == "--measure":
            out["measure"] = True
        elif flag == "--no-compose":
            out["compose"] = False
    out["renders"] = os.path.abspath(out["renders"])
    return out


def render_all(opts):
    import math
    import bpy
    from bpy_extras.object_utils import world_to_camera_view
    from mathutils import Matrix, Vector

    # The sibling scripts read their own arguments from argv; give them only the output folder.
    sys.argv = [sys.argv[0], "--", opts["renders"]]
    sys.path.insert(0, HERE)
    import accessories as acc
    import cursor_yak as yak
    import desk_buddy as buddy
    import mascot_motion as mascot
    import yak_laptop
    import yak_shaving

    raw_folder = os.path.join(opts["scratch"], "raw")
    os.makedirs(raw_folder, exist_ok=True)
    wanted = [name for name in SHOTS if not opts["only"] or name in opts["only"]]

    def finish(parts):
        """Triangulates, smooths and paints yak parts the way the STL export does."""
        for obj, colour in parts:
            yak.triangulate_clean(obj)
            obj.data.set_sharp_from_angle(angle=math.radians(35))
            obj.data.polygons.foreach_set("use_smooth", [True] * len(obj.data.polygons))
            obj.data.materials.clear()
            obj.data.materials.append(yak.material(colour, acc.COLOURS[colour]))

    def clear_stage():
        for obj in [o for o in bpy.data.objects if o.type in {"LIGHT", "CAMERA"} or o.name == "ground"]:
            bpy.data.objects.remove(obj, do_unlink=True)

    def visible_points():
        depsgraph = bpy.context.evaluated_depsgraph_get()
        points = []
        for obj in bpy.data.objects:
            if obj.type not in {"MESH", "FONT"} or obj.hide_render or obj.name == "ground":
                continue
            evaluated = obj.evaluated_get(depsgraph)
            mesh = evaluated.to_mesh()
            points += [evaluated.matrix_world @ v.co for v in mesh.vertices]
            evaluated.to_mesh_clear()
        return points

    def stage(scale):
        """Lights, ground and camera; scale is 1 for walker-sized shots and BUDDY_SCALE for the desk buddy, so both frame alike."""
        clear_stage()
        camera = mascot.setup_scene(opts["size"], opts["size"], True, opts["samples"])
        scene = bpy.context.scene
        scene.cycles.device = "CPU"
        scene.cycles.use_adaptive_sampling = True
        for obj in bpy.data.objects:
            if obj.type == "LIGHT":
                obj.location *= scale
                obj.data.size *= scale
                obj.data.energy *= scale ** 2
        look_at = Vector((0, 0, LOOK_Z * scale))
        distance = DISTANCE * scale
        for _ in range(3):
            mascot.aim(camera, look_at, DIRECTION, distance)
            xs = [world_to_camera_view(scene, camera, p).x for p in visible_points()]
            right = camera.matrix_world.to_3x3() @ Vector((1, 0, 0))
            look_at = look_at + right * (((max(xs) + min(xs)) / 2 - 0.5) * distance * 36 / 70)
        mascot.aim(camera, look_at, DIRECTION, distance)
        return camera

    def report(name, camera):
        pts = [world_to_camera_view(bpy.context.scene, camera, p) for p in visible_points()]
        print(f"[frame] {name}: x {min(p.x for p in pts):.3f}..{max(p.x for p in pts):.3f} "
              f"y {min(p.y for p in pts):.3f}..{max(p.y for p in pts):.3f}", flush=True)

    def shoot(name, scale=1.0, before=None):
        camera = stage(scale)
        if before:
            before(camera)
        report(name, camera)
        if opts["measure"]:
            return
        bpy.context.scene.render.filepath = os.path.join(raw_folder, f"{name}.png")
        bpy.ops.render.render(write_still=True)
        print(f"[done] {name}", flush=True)

    # --- walker, sunglasses (fringe lift), sleeping
    if "walker" in wanted:
        yak.clear_scene()
        finish(yak.build_parts("walker"))
        shoot("walker")

    if "sunglasses" in wanted or "sleeping" in wanted:
        yak.clear_scene()
        model = mascot.build_model(short=False)
        if "sunglasses" in wanted:
            mascot.pose(model, lift=mascot.CAP_LIFT)
            shoot("sunglasses")
        if "sleeping" in wanted:
            mascot.pose(model, squash=0.0, pivot=12.0, nod=math.radians(10), lift=0.0, show_glasses=False)
            zeds = [mascot.make_zed(i, None) for i in range(3)]

            def place_zeds(camera):
                rotation = camera.matrix_world.to_3x3()
                right, up = rotation @ Vector((1, 0, 0)), rotation @ Vector((0, 1, 0))
                for (obj, alpha), p, strength in zip(zeds, (0.2, 0.5, 0.8), (1.0, 1.0, 0.85)):
                    alpha.outputs[0].default_value = strength
                    centre = Vector((6, -4, 74)) + right * (p * 22 + math.sin(p * math.pi * 2) * 3.5) + up * (p * 24)
                    scale = 0.55 + 0.75 * p
                    roll = Matrix.Rotation(math.radians(10 - 20 * p), 4, "Z")
                    obj.matrix_world = (Matrix.Translation(centre) @ camera.matrix_world.to_3x3().to_4x4() @ roll
                                        @ Matrix.Diagonal((scale, scale, scale, 1)))
            shoot("sleeping", before=place_zeds)

    if "laptop" in wanted:
        parts = yak_laptop.build_laptop_parts()
        finish(parts)
        shoot("laptop")

    if "shaving" in wanted:
        parts = yak_shaving.build_parts()
        finish(parts)
        shoot("shaving")

    # --- worn accessories on the walker-socket body
    builders = {"sunglasses": acc.build_sunglasses, "vr-goggles": acc.build_goggles, "party-hat": acc.build_party_hat,
                "santa-hat": acc.build_santa_hat, "hard-hat": acc.build_hard_hat, "headphones": acc.build_headphones}
    # Sockets the accessory covers; every other socket on the head is plugged with cream so no pin hole shows.
    covered = {"sunglasses": {"eyewear_left", "eyewear_right"}, "vr-goggles": {"eyewear_left", "eyewear_right"},
               "party-hat": {"head"}, "santa-hat": {"head"}, "hard-hat": {"head"}, "headphones": set()}
    for name in ACCESSORY_SHOTS:
        if f"accessory-{name}" not in wanted:
            continue
        yak_parts = yak.build_parts("walker-socket")
        parts, _, _ = builders[name]()
        keep = {obj for obj, _ in yak_parts + parts}
        for stray in [o for o in bpy.data.objects if o not in keep]:
            bpy.data.objects.remove(stray, do_unlink=True)
        acc.check_fit(name, parts, yak_parts)
        # Where a part still presses into the yak (the headphone cups clamp 0.8 mm), cut the accessory back so the two only touch.
        for obj, _ in parts:
            for other, _ in yak_parts:
                if acc.overlap_volume(obj, other) > 0.01:
                    yak.boolean(obj, other, "DIFFERENCE")
        acc.check_fit(name + " after trim", parts, yak_parts)
        plugs = []
        for socket, mouth, axis, sign in yak.socket_points(yak.LEG_HEIGHT):
            if socket.startswith("hand") or socket in covered[name]:
                continue
            center = list(mouth)
            center["XYZ".index(axis)] += sign * (yak.SOCKET_DEPTH / 2 + 0.01)
            plugs.append((yak.cylinder("plug", yak.SOCKET_DIAMETER / 2, yak.SOCKET_DEPTH, center, axis=axis, segments=48), "cream"))
        for obj, colour in yak_parts + parts + plugs:
            acc.paint(obj, colour)
        shoot(f"accessory-{name}")

    # --- desk buddy, last: building it rescales the fringe margin and lock sizes in cursor_yak
    if any(f"desk-buddy-{pose}" in wanted for pose in BUDDY_SHOTS):
        model = buddy.build()
        for pose in BUDDY_SHOTS:
            if f"desk-buddy-{pose}" not in wanted:
                continue
            buddy.pose(model, pose)
            for obj in model["eyes"]["round"]:
                obj.hide_render = pose == "open"
            for obj in model["eyes"]["happy"]:
                obj.hide_render = pose != "open"
            shoot(f"desk-buddy-{pose}", scale=BUDDY_SCALE)


# ---------------------------------------------------------------- compose (Pillow and numpy)

def fade_shadow(image):
    """Fades the contact shadow to nothing well before the frame edge, leaving the figure untouched."""
    import numpy
    pixels = numpy.asarray(image.convert("RGBA"), dtype=numpy.float32) / 255.0
    height, width = pixels.shape[:2]
    solid = pixels[:, :, 3] > 0.97
    rows = numpy.where(solid.any(axis=1))[0]
    columns = numpy.where(solid.any(axis=0))[0]
    centre_x, centre_y = (columns.min() + columns.max()) / 2, rows.max() - 0.04 * height
    y, x = numpy.mgrid[0:height, 0:width]
    radius = numpy.sqrt(((x - centre_x) / (0.30 * width)) ** 2 + ((y - centre_y) / (0.17 * height)) ** 2)
    ramp = 1 - numpy.clip((radius - 0.55) / 0.45, 0, 1)
    ramp = ramp * ramp * (3 - 2 * ramp)
    # Anything near the border goes to zero regardless, so a clipped tail can never leave an edge.
    edge = numpy.minimum(numpy.minimum(y, height - 1 - y), numpy.minimum(x, width - 1 - x)) / (0.12 * width)
    edge = numpy.clip(edge, 0, 1)
    ramp = ramp * edge * edge * (3 - 2 * edge)
    is_shadow = (pixels[:, :, :3].max(axis=2) < 0.06) & (pixels[:, :, 3] < 0.97)
    pixels[:, :, 3] = numpy.where(is_shadow, pixels[:, :, 3] * ramp, pixels[:, :, 3])
    pixels[:, :, :3] = numpy.where(pixels[:, :, 3:4] > 0, pixels[:, :, :3], 0)
    from PIL import Image
    return Image.fromarray((pixels * 255 + 0.5).astype(numpy.uint8), "RGBA")


def on_cream(image):
    from PIL import Image
    backdrop = Image.new("RGBA", image.size, CREAM + (255,))
    return Image.alpha_composite(backdrop, image).convert("RGB")


def compose(renders, scratch):
    from PIL import Image, ImageDraw, ImageFont
    raw_folder = os.path.join(scratch, "raw")
    for sub in ("transparent", "cream"):
        os.makedirs(os.path.join(renders, sub), exist_ok=True)
    transparent = {}
    for name in SHOTS:
        path = os.path.join(raw_folder, f"{name}.png")
        if not os.path.exists(path):
            continue
        transparent[name] = fade_shadow(Image.open(path))
        transparent[name].save(os.path.join(renders, "transparent", f"{name}.png"))
        on_cream(transparent[name]).save(os.path.join(renders, "cream", f"{name}.png"))
    print(f"[compose] {len(transparent)} shots", flush=True)

    # Lineup: every figure at the same scale on one baseline, evenly spaced.
    if all(name in transparent for name in LINEUP):
        width, height = 3840, 1600
        side = transparent[LINEUP[0]].size[0]
        boxes = [transparent[name].getchannel("A").point(lambda v: 255 if v > 200 else 0).getbbox() for name in LINEUP]
        figure_width = max(box[2] - box[0] for box in boxes)
        figure_height = max(box[3] - box[1] for box in boxes)
        scale = min(0.9 * width / len(LINEUP) / figure_width, 0.78 * height / figure_height)
        baseline = max(box[3] for box in boxes)
        top = min(box[1] for box in boxes)
        canvas = Image.new("RGBA", (width, height), CREAM + (255,))
        size = round(side * scale)
        offset_y = round((height - (baseline - top) * scale) / 2 - top * scale)
        for index, name in enumerate(LINEUP):
            box = boxes[index]
            shrunk = transparent[name].resize((size, size), Image.LANCZOS)
            centre_x = (index + 0.5) * width / len(LINEUP)
            layer = Image.new("RGBA", canvas.size, (0, 0, 0, 0))
            layer.paste(shrunk, (round(centre_x - (box[0] + box[2]) / 2 * scale), offset_y))
            canvas = Image.alpha_composite(canvas, layer)
        canvas.convert("RGB").save(os.path.join(renders, "lineup.png"))

    # Accessories grid: 3 x 2 on cream.
    names = [f"accessory-{name}" for name in ACCESSORY_SHOTS]
    if all(name in transparent for name in names):
        cell = 1024
        grid = Image.new("RGB", (cell * 3, cell * 2), CREAM)
        for index, name in enumerate(names):
            grid.paste(on_cream(transparent[name]).resize((cell, cell), Image.LANCZOS), ((index % 3) * cell, (index // 3) * cell))
        grid.save(os.path.join(renders, "accessories-grid.png"))

    # Contact sheet for review.
    present = [name for name in SHOTS if name in transparent]
    cell, label, columns = 512, 44, 5
    rows = (len(present) + columns - 1) // columns
    sheet = Image.new("RGB", (cell * columns, (cell + label) * rows), CREAM)
    draw = ImageDraw.Draw(sheet)
    font = ImageFont.truetype("/System/Library/Fonts/Helvetica.ttc", 24)
    for index, name in enumerate(present):
        x, y = (index % columns) * cell, (index // columns) * (cell + label)
        sheet.paste(on_cream(transparent[name]).resize((cell, cell), Image.LANCZOS), (x, y + label))
        draw.text((x + 14, y + 9), LABELS[name], fill=(40, 40, 40), font=font)
    os.makedirs(scratch, exist_ok=True)
    sheet.save(os.path.join(scratch, "contact.png"))


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "compose":
        compose(os.path.abspath(sys.argv[2]), sys.argv[3] if len(sys.argv) > 3 else SCRATCH_DEFAULT)
    else:
        settings = options()
        render_all(settings)
        if settings["compose"] and not settings["measure"]:
            subprocess.run(["python3", os.path.abspath(__file__), "compose", settings["renders"], settings["scratch"]], check=True)
