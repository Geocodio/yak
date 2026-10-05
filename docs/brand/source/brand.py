"""Builds a full branding pack for each Cursor Yak variant (block, walker).

Marks are drawn from the Blender model's front-view dimensions in millimetres, with y = -z,
so the flat logo and the printed object share one set of proportions.
"""

import json
import math
import os
import subprocess
import sys
import zipfile

from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from PIL import Image, ImageDraw, ImageFont

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import locks  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
RENDERS = os.path.join(HERE, "..", "print", "renders")
OUT = os.path.join(HERE, "out")

SLATE, SLATE_DARK, CREAM, RUST, SAGE, PEACH, INK, PAPER = (
    "#7f9cb4", "#6a879f", "#efe4c9", "#c4643a", "#7f9a5e", "#e3976b", "#2f3a42", "#f3efe6")
LEG_LIFT = 9.0
FONT = TTFont(os.path.join(HERE, "JetBrainsMono-ExtraBold.ttf"))


def rounded_rect(x0, z0, x1, z1, radius, top_only=False):
    """Path for a rounded rectangle given in model coordinates (z up)."""
    left, right, top, bottom = x0, x1, -z1, -z0
    r = radius
    bottom_r = 0 if top_only else r
    return (f"M{left + r},{top} H{right - r} A{r},{r} 0 0 1 {right},{top + r} "
            f"V{bottom - bottom_r} A{bottom_r},{bottom_r} 0 0 1 {right - bottom_r},{bottom} "
            f"H{left + bottom_r} A{bottom_r},{bottom_r} 0 0 1 {left},{bottom - bottom_r} "
            f"V{top + r} A{r},{r} 0 0 1 {left + r},{top} Z")


def horn_path(side, lift):
    """Front view of the swept horn: offset both sides of the curve, round the tip."""
    p0, p1, p2 = (side * 11.0, 42.0 + lift), (side * 20.0, 51.0 + lift), (side * 19.5, 59.0 + lift)
    r0, r1 = 5.8, 3.0
    left, right = [], []
    steps = 32
    for i in range(steps + 1):
        t = i / steps
        x = (1 - t) ** 2 * p0[0] + 2 * (1 - t) * t * p1[0] + t ** 2 * p2[0]
        z = (1 - t) ** 2 * p0[1] + 2 * (1 - t) * t * p1[1] + t ** 2 * p2[1]
        dx = 2 * (1 - t) * (p1[0] - p0[0]) + 2 * t * (p2[0] - p1[0])
        dz = 2 * (1 - t) * (p1[1] - p0[1]) + 2 * t * (p2[1] - p1[1])
        length = math.hypot(dx, dz)
        nx, nz = -dz / length, dx / length
        r = r0 + (r1 - r0) * t
        left.append((x + nx * r, z + nz * r))
        right.append((x - nx * r, z - nz * r))
    dx, dz = p2[0] - p1[0], p2[1] - p1[1]
    length = math.hypot(dx, dz)
    ux, uz = dx / length * r1 * 1.33, dz / length * r1 * 1.33
    lx, lz = left[-1]
    rx, rz = right[-1]
    path = f"M{left[0][0]:.2f},{-left[0][1]:.2f} " + " ".join(f"L{x:.2f},{-z:.2f}" for x, z in left[1:])
    path += f" C{lx + ux:.2f},{-(lz + uz):.2f} {rx + ux:.2f},{-(rz + uz):.2f} {rx:.2f},{-rz:.2f} "
    path += " ".join(f"L{x:.2f},{-z:.2f}" for x, z in reversed(right[:-1])) + " Z"
    return path


def shapes(variant, small=False):
    """Ordered (role, svg element body) pairs. Roles drive colour and the one-colour mask."""
    lift = LEG_LIFT if variant == "walker" else 0.0
    items = [("horn-rust", f'<path d="{horn_path(-1, lift)}"/>'), ("horn-sage", f'<path d="{horn_path(1, lift)}"/>')]
    if variant == "walker":
        for side in (-1, 1):
            items.append(("arm", f'<line x1="{side * 18.4}" y1="{-(lift + 19)}" x2="{side * 19.6}" y2="{-(lift + 7)}" stroke-width="7.6" stroke-linecap="round"/>'))
            items.append(("leg", f'<path d="{rounded_rect(side * 9 - 7, 0, side * 9 + 7, lift + 5, 4)}"/>'))
    items.append(("body", f'<path d="{rounded_rect(-18, lift, 18, lift + 47, 5)}"/>'))
    fringe = f'<path d="{rounded_rect(-20, lift + 26, 20, lift + 48.5, 6.5, top_only=True)}"/>'
    for center, length in locks.FRONT:
        outline = locks.lock_outline(center, length, lift + 26)
        fringe += '<path d="M' + " L".join(f"{u:.2f},{-z:.2f}" for u, z in outline) + ' Z"/>'
    items.append(("fringe", fringe))
    items.append(("muzzle", f'<path d="{rounded_rect(-9, lift + 6.25, 9, lift + 16.75, 2.4)}"/>'))
    if not small:
        items.append(("nostril", "".join(f'<circle cx="{x}" cy="{-(lift + 12.1)}" r="1.4"/>' for x in (-3.6, 3.6))))
    return items


COLOUR_BY_ROLE = {"horn-rust": RUST, "horn-sage": SAGE, "arm": SLATE_DARK, "leg": SLATE, "body": SLATE,
                  "fringe": CREAM, "muzzle": PEACH, "nostril": INK}


def mark_group(variant, small=False, mono=None, mask_id="m"):
    """SVG group for the mark in model units. mono is a colour for the one-colour version."""
    items = shapes(variant, small)
    if mono is None:
        body = ""
        for role, element in items:
            colour = COLOUR_BY_ROLE[role]
            attribute = "stroke" if role == "arm" else "fill"
            body += f'<g {attribute}="{colour}">{element}</g>'
        return f"<g>{body}</g>"
    # One colour: a mask where outlined shapes leave thin gaps so the parts stay readable.
    gap = 1.8
    mask = ""
    for role, element in items:
        if role == "nostril":
            mask += f'<g fill="black">{element}</g>'
        elif role == "arm":
            mask += f'<g stroke="white">{element}</g>'
        elif role in ("body", "fringe", "muzzle"):
            mask += f'<g fill="black" stroke="black" stroke-width="{gap}">{element}</g><g fill="white">{element}</g>'
        else:
            mask += f'<g fill="white">{element}</g>'
    return (f'<defs><mask id="{mask_id}" maskUnits="userSpaceOnUse" x="-60" y="-90" width="120" height="100">{mask}</mask></defs>'
            f'<rect x="-60" y="-90" width="120" height="100" fill="{mono}" mask="url(#{mask_id})"/>')


def view_box(variant):
    return "-33 -64 66 66" if variant == "block" else "-37 -73 74 74"


def svg(width, height, view, content):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}" viewBox="{view}">'
            f"{content}</svg>\n")


def tile(variant, small=False, radius=14, scale=0.84, background=INK):
    """64 x 64 tile with the mark centred, for favicons and app icons."""
    vx, vy, vw, _ = map(float, view_box(variant).split())
    factor = 64 * scale / vw
    offset = (64 - vw * factor) / 2
    shape = f'<rect width="64" height="64" rx="{radius}" fill="{background}"/>' if background else ""
    group = f'<g transform="translate({offset - vx * factor:.3f} {offset - vy * factor:.3f}) scale({factor:.4f})">{mark_group(variant, small)}</g>'
    return svg(64, 64, "0 0 64 64", shape + group)


def word_path(text, x, baseline, size):
    """Outlined glyphs, so the wordmark needs no font installed."""
    glyph_set = FONT.getGlyphSet()
    cmap = FONT.getBestCmap()
    scale = size / FONT["head"].unitsPerEm
    paths, cursor = [], x
    for char in text:
        name = cmap[ord(char)]
        pen = SVGPathPen(glyph_set)
        glyph_set[name].draw(TransformPen(pen, (scale, 0, 0, -scale, cursor, baseline)))
        paths.append(pen.getCommands())
        cursor += glyph_set[name].width * scale
    return " ".join(paths), cursor


def horizontal_logo(variant, text_colour, mono=None):
    size = 100
    if variant == "block":
        # The block sits after the word as the terminal caret: body spans descender to cap height.
        word, end = word_path("yak", 0, 0, size)
        mark_scale = 92 / 47
        mark_x = end + 4 + 20 * mark_scale
        mark = f'<g transform="translate({mark_x:.2f} 18) scale({mark_scale:.4f})">{mark_group(variant, mono=mono, mask_id="hb")}</g>'
        width = mark_x + 27 * mark_scale + 4
        content = f'<path d="{word}" fill="{text_colour}"/>{mark}'
        return svg(round(width * 2), 2 * 150, f"-4 -112 {width + 4:.2f} 150", content)
    mark_scale = 132 / 71
    mark = f'<g transform="translate({37 * mark_scale:.2f} 22) scale({mark_scale:.4f})">{mark_group(variant, mono=mono, mask_id="hw")}</g>'
    word, end = word_path("yak", 74 * mark_scale + 14, 0, size)
    content = f'{mark}<path d="{word}" fill="{text_colour}"/>'
    return svg(round((end + 8) * 2), 2 * 160, f"0 -120 {end + 8:.2f} 160", content)


def stacked_logo(variant, text_colour):
    word, end = word_path("yak", 0, 0, 100)
    _, _, vw, vh = map(float, view_box(variant).split())
    mark_scale = 150 / vh
    vx, vy = map(float, view_box(variant).split()[:2])
    width = max(end, vw * mark_scale)
    mark = f'<g transform="translate({width / 2:.2f} {-vy * mark_scale:.2f}) scale({mark_scale:.4f})">{mark_group(variant, mask_id="st")}</g>'
    text = f'<g transform="translate({(width - end) / 2:.2f} {150 + 92})"><path d="{word}" fill="{text_colour}"/></g>'
    return svg(round((width + 20) * 2), round(270 * 2), f"-10 -10 {width + 20:.2f} 270", mark + text)


def raster(svg_text, path, size):
    subprocess.run(["rsvg-convert", "-w", str(size), "-h", str(size), "-o", path], input=svg_text.encode(), check=True)


def raster_width(svg_text, path, width):
    subprocess.run(["rsvg-convert", "-w", str(width), "-o", path], input=svg_text.encode(), check=True)


def avatar_from_render(variant, path, background):
    render = tight_square(Image.open(os.path.join(RENDERS, f"cursor-yak-{variant}-square.png")).convert("RGBA"))
    canvas = Image.new("RGBA", render.size, background)
    canvas.alpha_composite(render)
    canvas.resize((1024, 1024), Image.LANCZOS).save(path)


def tight_square(render, padding=0.16):
    """Crops a transparent render to a square around the figure, leaving room for circular avatars."""
    box = render.getchannel("A").point(lambda a: 255 if a > 40 else 0).getbbox()
    side = max(box[2] - box[0], box[3] - box[1])
    side = int(side * (1 + 2 * padding))
    cx, cy = (box[0] + box[2]) // 2, (box[1] + box[3]) // 2
    canvas = Image.new("RGBA", (side, side), (0, 0, 0, 0))
    canvas.alpha_composite(render, (side // 2 - cx, side // 2 - cy))
    return canvas


def social_card(variant, folder):
    card = Image.new("RGBA", (1200, 630), PAPER)
    render = tight_square(Image.open(os.path.join(RENDERS, f"cursor-yak-{variant}-square.png")).convert("RGBA"), padding=0.08).resize((560, 560), Image.LANCZOS)
    card.alpha_composite(render, (40, 35))
    logo_path = os.path.join(folder, "_logo_for_card.png")
    raster_width(horizontal_logo(variant, INK), logo_path, 470)
    logo = Image.open(logo_path).convert("RGBA")
    card.alpha_composite(logo, (620, 200))
    os.remove(logo_path)
    draw = ImageDraw.Draw(card)
    font = ImageFont.truetype(os.path.join(HERE, "JetBrainsMono-ExtraBold.ttf"), 30)
    draw.text((626, 200 + logo.height + 28), "Does the first pass.\nOpens a PR for your review.", font=font, fill=INK, spacing=10)
    card.convert("RGB").save(os.path.join(folder, "social-card-1200x630.png"))


FAVICON_PIXELS = [
    # (colour, x, y, width, height) on a 16 x 16 grid; whole pixels only, so nothing blurs.
    (RUST, 1, 1, 2, 4), (RUST, 2, 4, 2, 2),
    (SAGE, 13, 1, 2, 4), (SAGE, 12, 4, 2, 2),
    (SLATE, 4, 8, 8, 6), (SLATE, 5, 14, 6, 1),
    (CREAM, 4, 3, 8, 1), (CREAM, 3, 4, 10, 4),
    (CREAM, 4, 8, 2, 1), (CREAM, 7, 8, 2, 2), (CREAM, 10, 8, 2, 1),
    (PEACH, 5, 11, 6, 3),
]


def favicon_face(background=INK):
    """Face-only glyph: horn stubs, a fringe with three uneven teeth, the face and the muzzle."""
    tile_shape = f'<rect width="16" height="16" rx="3" fill="{background}"/>' if background else ""
    pixels = "".join(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" fill="{c}"/>' for c, x, y, w, h in FAVICON_PIXELS)
    return svg(16, 16, "0 0 16 16", f'{tile_shape}<g shape-rendering="crispEdges">{pixels}</g>')


def face_view_box(variant):
    return "-30 -74 60 60" if variant == "walker" else "-32.5 -64 65 65"


def build(variant):
    folder = os.path.join(OUT, f"cursor-yak-{variant}-brand")
    for sub in ("mark", "logo", "favicon", "avatar"):
        os.makedirs(os.path.join(folder, sub), exist_ok=True)
    write = lambda rel, text: open(os.path.join(folder, rel), "w").write(text)

    vb = view_box(variant)
    write("mark/mark.svg", svg(512, 512, vb, mark_group(variant)))
    write("mark/mark-one-colour-ink.svg", svg(512, 512, vb, mark_group(variant, mono=INK)))
    write("mark/mark-one-colour-white.svg", svg(512, 512, vb, mark_group(variant, mono="#ffffff")))
    raster(svg(512, 512, vb, mark_group(variant)), os.path.join(folder, "mark/mark-1024.png"), 1024)

    write("logo/logo-horizontal.svg", horizontal_logo(variant, INK))
    write("logo/logo-horizontal-on-dark.svg", horizontal_logo(variant, PAPER))
    write("logo/logo-horizontal-one-colour.svg", horizontal_logo(variant, INK, mono=INK))
    write("logo/logo-stacked.svg", stacked_logo(variant, INK))
    write("logo/logo-stacked-on-dark.svg", stacked_logo(variant, PAPER))
    raster_width(horizontal_logo(variant, INK), os.path.join(folder, "logo/logo-horizontal-1200.png"), 1200)
    raster_width(horizontal_logo(variant, PAPER), os.path.join(folder, "logo/logo-horizontal-on-dark-1200.png"), 1200)

    favicon = favicon_face()
    write("favicon/favicon.svg", favicon)
    write("favicon/favicon-transparent.svg", favicon_face(background=None))
    write("mark/mark-face.svg", svg(512, 512, face_view_box(variant), mark_group(variant, small=True)))
    for size, source in ((16, favicon), (32, favicon), (48, favicon)):
        raster(source, os.path.join(folder, f"favicon/favicon-{size}.png"), size)
    Image.open(os.path.join(folder, "favicon/favicon-48.png")).save(
        os.path.join(folder, "favicon/favicon.ico"),
        sizes=[(16, 16), (32, 32), (48, 48)],
        append_images=[Image.open(os.path.join(folder, f"favicon/favicon-{s}.png")) for s in (16, 32)])
    raster(tile(variant, radius=0, scale=0.8), os.path.join(folder, "favicon/apple-touch-icon.png"), 180)
    raster(tile(variant, radius=0, scale=0.84), os.path.join(folder, "favicon/icon-192.png"), 192)
    raster(tile(variant, radius=0, scale=0.84), os.path.join(folder, "favicon/icon-512.png"), 512)
    raster(tile(variant, radius=0, scale=0.7), os.path.join(folder, "favicon/icon-maskable-512.png"), 512)
    write("favicon/site.webmanifest", json.dumps({
        "name": "Yak", "short_name": "Yak", "theme_color": INK, "background_color": INK, "display": "standalone",
        "icons": [{"src": "icon-192.png", "sizes": "192x192", "type": "image/png"},
                  {"src": "icon-512.png", "sizes": "512x512", "type": "image/png"},
                  {"src": "icon-maskable-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable"}]}, indent=2) + "\n")

    avatar_from_render(variant, os.path.join(folder, "avatar/avatar-3d-1024.png"), "#dbe3ea")
    tight_square(Image.open(os.path.join(RENDERS, f"cursor-yak-{variant}-square.png")).convert("RGBA")).resize((1024, 1024), Image.LANCZOS).save(
        os.path.join(folder, "avatar/avatar-3d-transparent-1024.png"))
    raster(tile(variant, radius=0, scale=0.8), os.path.join(folder, "avatar/avatar-flat-1024.png"), 1024)
    social_card(variant, folder)

    with zipfile.ZipFile(os.path.join(OUT, f"cursor-yak-{variant}-brand.zip"), "w", zipfile.ZIP_DEFLATED) as archive:
        for root, _, files in os.walk(folder):
            for name in files:
                full = os.path.join(root, name)
                archive.write(full, os.path.relpath(full, OUT))
    print(variant, "pack ready:", folder)


for name in sys.argv[1:] or ("walker",):
    build(name)
