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
- `avatar/` has square avatars: 3D render, 3D render on transparent, flat, and `avatar-face-paper-1024.png`, the zoomed face on paper used as the GitHub App avatar.
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

## Character set

Square 2048 px renders of the walker, its variants and the desk buddy, all from one camera and one light rig so the yak is the same size in every image. Each shot comes as a transparent PNG (the contact shadow is soft alpha that fades out before the edges) in `renders/transparent/` and the same image on cream #f5f0e8 in `renders/cream/`.

- Shots: `walker`, `sunglasses` (fringe lifted), `sleeping`, `laptop`, `shaving`, `desk-buddy-closed`, `desk-buddy-peek`, `desk-buddy-open`
- Worn accessories on the walker-socket body: `accessory-sunglasses`, `accessory-vr-goggles`, `accessory-party-hat`, `accessory-santa-hat`, `accessory-hard-hat`, `accessory-headphones`
- `renders/lineup.png`: six main poses side by side on cream, 3840 x 1600
- `renders/accessories-grid.png`: the six accessory shots in a 3 x 2 grid on cream

To regenerate everything (CPU only, about 30 minutes at full quality):

```sh
blender -b -P docs/brand/source/character_set.py -- docs/brand/renders
```

Add `--size 640 --samples 24` for a quick draft, or `--only walker,laptop` to redo single shots. The script also writes a labelled `contact.png` for review into its scratch folder.

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

## Animations

`source/mascot_motion.py` renders two animations of the walker: the login video (`public/videos/yak-walker-hair-lift.mp4`, 7.5 s, where the fringe lifts to reveal sunglasses and then drops back, so the last frame matches the first; the login page plays it once on load and again on hover, with frame 0 as the poster) and the sleeping loop for the preview loading page (`public/mascot-sleeping.webp`, with `public/mascot-sleeping.png` as the static fallback). It renders on the CPU, because the Metal kernel compile can crash Blender. Frames go to a scratch folder, then ffmpeg and img2webp encode them.

```sh
/Applications/Blender.app/Contents/MacOS/Blender -b -P docs/brand/source/mascot_motion.py -- /tmp/motion all
ffmpeg -framerate 24 -i /tmp/motion/video/%04d.png -c:v libx264 -preset veryslow -crf 17 -pix_fmt yuv420p -movflags +faststart -an public/videos/yak-walker-hair-lift.mp4
img2webp -loop 0 -lossy -q 75 -m 4 -d 83 /tmp/motion/sleep/*.png -o public/mascot-sleeping.webp
cp /tmp/motion/sleep/0014.png public/mascot-sleeping.png
ffmpeg -i /tmp/motion/video/0000.png -q:v 3 public/videos/yak-walker-hair-lift-poster.jpg
```

Use `video` or `sleep` instead of `all` to render one of them. Frames that already exist are skipped, so delete the folder to start over.

## Presentation clips

`source/mascot_clips.py` renders short clips of the walker with jointed legs and arms: `walk`, `thinking`, `idea`, `shrug`, `wave`, `intake` (Linear, Slack, Sentry and GitHub task cards fly in, a PR card flies out), `typing`, `shaving`, `breakdance`, `robot`, `macarena`, `moonwalk` and `disco`. Each clip is one module in `source/clips/` that poses the rig per frame. Frames are transparent 1920 x 1080 PNGs at 24 fps, rendered on the Metal GPU (about 3.5 s a frame on an M4 Max). `source/encode_clips.sh` turns them into an MP4 on cream #f5f0e8 and a ProRes 4444 `.mov` with alpha for slides. The videos are not committed.

```sh
cd docs/brand/source
blender -b -P mascot_clips.py -- /tmp/clips walk --gpu --samples 32
./encode_clips.sh /tmp/clips ~/Desktop/yak-clips walk
```

Add `--size 640x360 --samples 12 --step 3` for a quick draft, or `--frames 40,60` to render a range. Frames that already exist are skipped, so a crashed render resumes where it stopped. The intake logos in `source/clips/intake/` come from Simple Icons and lucide-static.
