"""Labelled contact sheet for the desk buddy renders. Run: python3 desk_buddy_sheet.py <desk-buddy folder>"""

import os
import sys

from PIL import Image, ImageDraw, ImageFont

ITEMS = [("assembled-closed", "Assembled, closed (cap down)"), ("assembled-peek", "Peek (cap up 10.5 mm)"),
         ("assembled-open", "Open (cap up 27.1 mm)"), ("exploded", "Exploded view"),
         ("xray-drive", "X-ray, open: servo, pinion, rack, guide, ESP32, capacitor"), ("print-plate", "Print plate, parts in print orientation")]

folder = sys.argv[1]
width, height, label = 700, 525, 44
sheet = Image.new("RGB", (width * 3, (height + label) * 2), "white")
draw = ImageDraw.Draw(sheet)
font = ImageFont.truetype("/System/Library/Fonts/Helvetica.ttc", 24)
for index, (name, title) in enumerate(ITEMS):
    x, y = (index % 3) * width, (index // 3) * (height + label)
    sheet.paste(Image.open(os.path.join(folder, "renders", f"{name}.png")).convert("RGB").resize((width, height)), (x, y + label))
    draw.text((x + 14, y + 9), title, fill="black", font=font)
sheet.save(os.path.join(folder, "desk-buddy-sheet.png"))
