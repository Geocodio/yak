"""Packs per-colour STLs into one multi-part 3MF per model for Bambu Studio.

Every part keeps the shared coordinate frame and carries its display colour. Filament slots follow
SLOT_ORDER, keeping only the colours a model uses, so the walker is 1 slate, 2 cream, 3 rust, 4 sage, 5 peach.
Each folder under print/variants/ and print/accessories/ holds STLs named <model>-<part>-<colour>.stl
and gets its own <folder>.3mf.

Run: python3 build_3mf.py
"""

import os
import struct
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
STL_DIR = os.path.join(HERE, "..", "print", "stl")
OUTPUT = os.path.join(HERE, "..", "print", "yak-walker-multicolour.3mf")

PARTS = [
    ("1-body-slate.stl", "body", "slate"),
    ("2-fringe-cream.stl", "fringe", "cream"),
    ("3-horn-rust.stl", "horn rust", "rust"),
    ("4-horn-sage.stl", "horn sage", "sage"),
    ("5-muzzle-peach.stl", "muzzle", "peach"),
]
COLOURS = {"slate": "#7F9CB4", "cream": "#EFE4C9", "rust": "#C4643A", "sage": "#7F9A5E", "peach": "#E3976B",
           "ink": "#2F3A42", "red": "#C8423A", "white": "#F5F5F2", "yellow": "#E8B53A"}
SLOT_ORDER = list(COLOURS)


def read_stl(path):
    """Binary STL to (vertices, triangles) with shared vertices merged."""
    data = open(path, "rb").read()
    count = struct.unpack("<I", data[80:84])[0]
    index, vertices, triangles = {}, [], []
    for i in range(count):
        offset = 84 + i * 50 + 12
        corners = []
        for k in range(3):
            point = struct.unpack("<3f", data[offset + k * 12:offset + k * 12 + 12])
            key = tuple(round(value, 5) for value in point)
            if key not in index:
                index[key] = len(vertices)
                vertices.append(key)
            corners.append(index[key])
        if len(set(corners)) == 3:
            triangles.append(corners)
    return vertices, triangles


def mesh_xml(vertices, triangles, material_index):
    vertex_rows = "".join(f'<vertex x="{x}" y="{y}" z="{z}"/>' for x, y, z in vertices)
    triangle_rows = "".join(f'<triangle v1="{a}" v2="{b}" v3="{c}" pid="1" p1="{material_index}"/>' for a, b, c in triangles)
    return f"<mesh><vertices>{vertex_rows}</vertices><triangles>{triangle_rows}</triangles></mesh>"


def build(parts, output, title):
    """parts: [(stl_path, part name, colour name)]."""
    slots = [colour for colour in SLOT_ORDER if any(part_colour == colour for _, _, part_colour in parts)]
    materials = "".join(f'<base name="{name}" displaycolor="{COLOURS[colour]}"/>' for _, name, colour in parts)
    object_id_of_group = len(parts) + 2
    objects, components, part_settings = [], [], []
    for number, (path, name, colour) in enumerate(parts):
        object_id = number + 2
        vertices, triangles = read_stl(path)
        objects.append(f'<object id="{object_id}" name="{name}" type="model" pid="1" pindex="{number}">'
                       f"{mesh_xml(vertices, triangles, number)}</object>")
        components.append(f'<component objectid="{object_id}"/>')
        part_settings.append(f'    <part id="{object_id}" subtype="normal_part">\n'
                             f'      <metadata key="name" value="{name}"/>\n'
                             f'      <metadata key="extruder" value="{slots.index(colour) + 1}"/>\n'
                             f"    </part>\n")

    model = ('<?xml version="1.0" encoding="UTF-8"?>\n'
             '<model unit="millimeter" xml:lang="en-US" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02">'
             f'<metadata name="Title">{title}</metadata>'
             f'<resources><basematerials id="1">{materials}</basematerials>{"".join(objects)}'
             f'<object id="{object_id_of_group}" name="{title}" type="model"><components>{"".join(components)}</components></object>'
             f'</resources><build><item objectid="{object_id_of_group}"/></build></model>\n')
    settings = ('<?xml version="1.0" encoding="UTF-8"?>\n<config>\n'
                f'  <object id="{object_id_of_group}">\n    <metadata key="name" value="{title}"/>\n'
                f'    <metadata key="extruder" value="1"/>\n{"".join(part_settings)}  </object>\n</config>\n')
    content_types = ('<?xml version="1.0" encoding="UTF-8"?>\n'
                     '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                     '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                     '<Default Extension="model" ContentType="application/vnd.ms-package.3dmanufacturing-3dmodel+xml"/>'
                     '<Default Extension="config" ContentType="text/xml"/></Types>\n')
    relationships = ('<?xml version="1.0" encoding="UTF-8"?>\n'
                     '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                     '<Relationship Target="/3D/3dmodel.model" Id="rel0" '
                     'Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel"/></Relationships>\n')

    with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED) as archive:
        archive.writestr("[Content_Types].xml", content_types)
        archive.writestr("_rels/.rels", relationships)
        archive.writestr("3D/3dmodel.model", model)
        archive.writestr("Metadata/model_settings.config", settings)
    print("wrote", output)


def build_folder(folder):
    """One 3MF from every <model>-<part>-<colour>.stl in a folder, skipping the single-colour file."""
    name = os.path.basename(folder)
    parts = []
    for filename in sorted(os.listdir(folder)):
        if not filename.endswith(".stl") or filename.endswith("single-colour.stl"):
            continue
        stem = filename[:-4]
        colour = stem.rsplit("-", 1)[1]
        part = stem[len(name) + 1:].rsplit("-", 1)[0].replace("_", " ") or name
        parts.append((os.path.join(folder, filename), part, colour))
    build(parts, os.path.join(folder, f"{name}.3mf"), f"Yak {name.replace('-', ' ')}")


if __name__ == "__main__":
    build([(os.path.join(STL_DIR, filename), name, colour) for filename, name, colour in PARTS], OUTPUT, "Yak walker")
    for group in ("variants", "accessories"):
        root = os.path.join(HERE, "..", "print", group)
        for folder in sorted(os.listdir(root)) if os.path.isdir(root) else []:
            if os.path.isdir(os.path.join(root, folder)):
                build_folder(os.path.join(root, folder))
