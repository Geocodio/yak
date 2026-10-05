# Yak brand kit

The Yak walker: a block-cursor yak on two legs, with a cream fringe over its eyes and one rust and one sage horn. Open `brand-kit.html` in a browser to see everything in this folder on one page.

## Palette

| Name  | Hex       | Used for             |
|-------|-----------|----------------------|
| Slate | `#7f9cb4` | Body                 |
| Cream | `#efe4c9` | Fringe               |
| Rust  | `#c4643a` | Left horn            |
| Sage  | `#7f9a5e` | Right horn           |
| Peach | `#e3976b` | Muzzle               |
| Ink   | `#2f3a42` | Wordmark, icon tiles |
| Paper | `#f3efe6` | Light backgrounds    |

The wordmark is JetBrains Mono ExtraBold, outlined in every SVG, so no font install is needed.

## Files

- `mark/` is the character in colour, one colour (ink and white), and `mark-face.svg`, a head-only crop for small avatars.
- `logo/` has horizontal and stacked lockups for light and dark backgrounds.
- `favicon/` holds the favicon set. The favicon is a separate face-only glyph drawn on a 16 × 16 pixel grid, because the full character is too detailed at that size. It also has app icons (`apple-touch-icon.png`, `icon-192.png`, `icon-512.png`, `icon-maskable-512.png`) and `site.webmanifest`.
- `avatar/` has square avatars: 3D render, 3D render on transparent, and flat.
- `social-card-1200x630.png` is the share image.
- `print/` contains the 3D print files (below).
- `source/` contains the scripts that generate all of the above.

## Printing on a Bambu printer

The walker is 47 × 35 × 71 mm. It prints upright as one piece. Only the muzzle's flat underside needs support. The legs have a 5 mm bridge between them, and the horns rise at 45 degrees or steeper.

### Multi-colour with an AMS

1. Load five filaments in this order: 1 slate, 2 cream, 3 rust, 4 sage, 5 peach.
2. In Bambu Studio, open `print/yak-walker-multicolour.3mf`. It is one object with five parts.
3. Check that each part is assigned to the matching filament slot, then slice.

If the 3MF does not keep the slot assignments, use the STLs:

1. Select all five files in `print/stl/` that start with `1-` to `5-`, and drag them into Bambu Studio together.
2. When asked "Load these files as a single object with multiple parts?", choose **Yes**.
3. In the object list, set each part's filament to match the number in its file name.

All five STLs share one coordinate frame, so the parts line up without moving them. Each part is a closed, watertight mesh, and the parts touch without overlapping.

Suggested settings:

- 0.12 mm layer height (0.20 mm leaves visible steps on the rounded head and horns).
- Prime tower on.
- Supports on: tree (auto), build plate only. Only the muzzle underside needs them.
- Seam position **Back**, so the seam does not run down the belly.
- To cut the cream wisps from colour changes: ooze prevention on, wipe while retracting on, and PLA 5 to 10 °C cooler than the profile.

### Variants and accessories

Each folder has per-colour STLs and a ready 3MF. The filament slots follow the order slate, cream, rust, sage, peach, ink, red, white, yellow, keeping only the colours that model uses.

- `print/variants/walker-socket/` is the walker with pin sockets: one on top of the head, two on the fringe front for eyewear, and one under each hand.
- `print/variants/laptop/` is the yak typing on a laptop. It prints as one plate.
- `print/variants/shaving/` is the half-shaved yak with a razor.
- `print/accessories/` holds the sunglasses, VR goggles, party hat, Santa hat, hard hat and headphones. Each one prints flat side down, as its STL is oriented.

Accessories attach with filament pins:

1. Cut an 8 mm piece of 1.75 mm filament.
2. Push it into the yak's socket.
3. Press the accessory's matching hole onto it.

The headphones need no pin. They clamp onto the head.

If the pins are loose, lower `SOCKET_DIAMETER` in `source/cursor_yak.py` from 1.9 to 1.85, then rebuild.

### One colour

Print `print/stl/yak-walker-single-colour.stl`.

## Desk buddy

A 1.5x walker with a cap that slides up on a servo-driven rack to show two round screen eyes, for a desk. It is a printable mockup for a Bambu printer. Yak's output is still a PR and a preview, and this is a physical companion, not part of that flow. Parts, wiring, assembly and risks are in `print/desk-buddy/BUILD.md`, with STLs in `print/desk-buddy/stl/` and renders in `print/desk-buddy/renders/`.

Regenerate it (writes into `print/desk-buddy/`), from the repo root:

```
/Applications/Blender.app/Contents/MacOS/Blender -b -P docs/brand/source/desk_buddy.py -- docs/brand/print/desk-buddy
python3 docs/brand/source/desk_buddy_sheet.py docs/brand/print/desk-buddy
```

## Regenerating

Requires Blender 5 and Python 3 with Pillow, fontTools and `rsvg-convert`.

```sh
# 3D model, STLs and renders (writes into the folder you pass)
/Applications/Blender.app/Contents/MacOS/Blender -b -P source/cursor_yak.py -- /tmp/yak-model

# Multi-colour 3MFs from print/stl/, print/variants/ and print/accessories/
python3 source/build_3mf.py

# Variants and accessories (each writes into the folder you pass)
/Applications/Blender.app/Contents/MacOS/Blender -b -P source/yak_laptop.py -- /tmp/yak-laptop
/Applications/Blender.app/Contents/MacOS/Blender -b -P source/yak_shaving.py -- /tmp/yak-shaving
/Applications/Blender.app/Contents/MacOS/Blender -b -P source/accessories.py -- /tmp/yak-accessories

# Marks, logos, favicons and avatars (writes source/out/), then the overview page
python3 source/brand.py
python3 source/kit_page.py
```

After a model rebuild, copy the STLs from `/tmp/yak-model/cursor-yak-walker/` into `print/stl/` using the numbered names above. Copy the two renders into `print/renders/`.

`source/locks.py` defines the fringe locks. The model and the flat marks both use it, so they always match.
