"""Builds the Cursor Yak brand kit page from the generated pack files."""

import base64
import io
import os
import re

from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "out")


def inline_svg(path, height=None, width=None):
    text = open(path).read().strip()
    text = re.sub(r' width="[^"]+"', "", text, count=1)
    text = re.sub(r' height="[^"]+"', "", text, count=1)
    style = ";".join(filter(None, [f"height:{height}" if height else "", f"width:{width}" if width else "", "max-width:100%", "display:block"]))
    # Unique mask ids so several inlined copies on one page do not collide.
    uid = f"u{abs(hash((path, height, width))) % 10 ** 8}"
    text = re.sub(r'id="(\w+)"', lambda m: f'id="{m.group(1)}-{uid}"', text)
    text = re.sub(r"url\(#(\w+)\)", lambda m: f"url(#{m.group(1)}-{uid})", text)
    return text.replace("<svg ", f'<svg role="img" aria-label="Yak logo" style="{style}" ', 1)


def jpeg(path, width):
    image = Image.open(path).convert("RGB")
    image.thumbnail((width, width))
    buffer = io.BytesIO()
    image.save(buffer, "JPEG", quality=84)
    return "data:image/jpeg;base64," + base64.b64encode(buffer.getvalue()).decode()


def png(path):
    return "data:image/png;base64," + base64.b64encode(open(path, "rb").read()).decode()


FILES = {
    "mark": ["mark.svg", "mark-face.svg", "mark-one-colour-ink.svg", "mark-one-colour-white.svg", "mark-1024.png"],
    "logo": ["logo-horizontal.svg", "logo-horizontal-on-dark.svg", "logo-horizontal-one-colour.svg", "logo-stacked.svg",
             "logo-stacked-on-dark.svg", "logo-horizontal-1200.png", "logo-horizontal-on-dark-1200.png"],
    "favicon": ["favicon.svg", "favicon-transparent.svg", "favicon.ico", "favicon-16.png", "favicon-32.png", "favicon-48.png", "apple-touch-icon.png",
                "icon-192.png", "icon-512.png", "icon-maskable-512.png", "site.webmanifest"],
    "avatar": ["avatar-3d-1024.png", "avatar-3d-transparent-1024.png", "avatar-flat-1024.png"],
    "": ["social-card-1200x630.png"],
}


def section(variant, title, blurb):
    d = os.path.join(OUT, f"cursor-yak-{variant}-brand")
    f = lambda rel: os.path.join(d, rel)
    file_list = "".join(f"<li><span class=\"dir\">{folder + '/' if folder else ''}</span>{name}</li>" for folder, names in FILES.items() for name in names)
    return f"""
  <section class="kit" id="{variant}">
    <div class="kit-head"><h2>{title}</h2><p class="lede">{blurb}</p></div>

    <div class="row marks">
      <figure class="cell light">{inline_svg(f('mark/mark.svg'), height='180px')}<figcaption>Mark</figcaption></figure>
      <figure class="cell light">{inline_svg(f('mark/mark-face.svg'), height='180px')}<figcaption>Face</figcaption></figure>
      <figure class="cell light">{inline_svg(f('mark/mark-one-colour-ink.svg'), height='180px')}<figcaption>One colour</figcaption></figure>
      <figure class="cell dark">{inline_svg(f('mark/mark-one-colour-white.svg'), height='180px')}<figcaption>Reversed</figcaption></figure>
    </div>

    <div class="row logos">
      <figure class="cell light wide">{inline_svg(f('logo/logo-horizontal.svg'), height='96px')}<figcaption>Horizontal</figcaption></figure>
      <figure class="cell dark wide">{inline_svg(f('logo/logo-horizontal-on-dark.svg'), height='96px')}<figcaption>Horizontal on dark</figcaption></figure>
      <figure class="cell light">{inline_svg(f('logo/logo-stacked.svg'), height='170px')}<figcaption>Stacked</figcaption></figure>
    </div>

    <div class="row icons">
      <figure class="cell light">
        <div class="browser"><div class="tab"><img src="{png(f('favicon/favicon-16.png'))}" width="16" height="16" alt="">Yak · Tasks</div><div class="tab dim">GitHub</div></div>
        <div class="sizes"><img src="{png(f('favicon/favicon-16.png'))}" width="16" height="16" alt="16 px favicon"><img src="{png(f('favicon/favicon-32.png'))}" width="32" height="32" alt="32 px favicon"><img src="{png(f('favicon/favicon-48.png'))}" width="48" height="48" alt="48 px favicon"></div>
        <figcaption>Favicon at 16, 32, 48 px</figcaption>
        <img class="zoom" src="{png(f('favicon/favicon-16.png'))}" width="128" height="128" alt="16 px favicon enlarged to show the pixel grid">
        <figcaption>16 px, enlarged</figcaption>
      </figure>
      <figure class="cell light"><img class="app" src="{png(f('favicon/apple-touch-icon.png'))}" width="120" height="120" alt="App icon"><figcaption>App icon</figcaption></figure>
      <figure class="cell light"><img class="round" src="{jpeg(f('avatar/avatar-flat-1024.png'), 300)}" width="120" height="120" alt="Flat avatar"><figcaption>Flat avatar</figcaption></figure>
      <figure class="cell light"><img class="round" src="{jpeg(f('avatar/avatar-3d-1024.png'), 400)}" width="120" height="120" alt="3D avatar"><figcaption>3D avatar</figcaption></figure>
    </div>

    <div class="row social">
      <figure class="cell plain"><img class="card" src="{jpeg(f('social-card-1200x630.png'), 1200)}" alt="Social share card"><figcaption>Social card, 1200 × 630</figcaption></figure>
      <div class="cell files">
        <h3>In the pack</h3>
        <ul>{file_list}</ul>
        <p class="path">cursor-yak-{variant}-brand.zip</p>
      </div>
    </div>
  </section>"""


page = """<title>Yak Brand Kit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700&family=IBM+Plex+Sans:wght@400;500&family=JetBrains+Mono:wght@400;800&display=swap">
<style>
/* Layout: a brand kit. Palette strip up top, then one full kit per variant: marks, lockups, icons, avatars, social card and the file list. */
:root {
  --bg: #e9edef; --surface: #ffffff; --fg: #26313a; --muted: #5f6d78; --line: #d3dadf; --accent: #b9572f;
  --paper: #f3efe6; --ink: #2f3a42;
  --display: "Bricolage Grotesque", "Avenir Next", system-ui, sans-serif;
  --body: "IBM Plex Sans", system-ui, sans-serif;
  --mono: "JetBrains Mono", ui-monospace, Menlo, monospace;
}
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { --bg: #161b1f; --surface: #1f262b; --fg: #e6eaec; --muted: #9aa7b0; --line: #2f383e; --accent: #e08458; color-scheme: dark } }
:root[data-theme="dark"] { --bg: #161b1f; --surface: #1f262b; --fg: #e6eaec; --muted: #9aa7b0; --line: #2f383e; --accent: #e08458; color-scheme: dark }
body { background: var(--bg); color: var(--fg); font-family: var(--body); font-size: 15px; line-height: 1.5; }
.wrap { max-width: 1160px; margin: 0 auto; padding-inline: 20px; padding-block: 36px 64px; display: grid; gap: 40px; }
h1 { font-family: var(--display); font-weight: 700; font-size: clamp(30px, 4vw, 44px); margin: 0 0 8px; letter-spacing: -0.01em; }
h2 { font-family: var(--display); font-weight: 700; font-size: 28px; margin: 0 0 4px; }
h3 { font-family: var(--display); font-weight: 700; font-size: 17px; margin: 0 0 8px; }
.lede { margin: 0; color: var(--muted); max-width: 68ch; }
nav { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; }
nav a { font: 500 13px var(--body); color: var(--fg); text-decoration: none; border: 1px solid var(--line); border-radius: 999px; padding: 6px 14px; background: var(--surface); }
nav a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
.palette { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px; }
.swatch { border-radius: 10px; padding: 46px 12px 10px; font-family: var(--mono); font-size: 12px; border: 1px solid var(--line); }
.swatch b { display: block; font-weight: 800; }
.kit { display: grid; gap: 14px; }
.row { display: grid; gap: 12px; }
.marks { grid-template-columns: repeat(4, minmax(0, 1fr)); }
.logos { grid-template-columns: 1.3fr 1.3fr 1fr; }
.icons { grid-template-columns: 1.4fr 1fr 1fr 1fr; }
.social { grid-template-columns: 1.6fr 1fr; }
.cell { margin: 0; border-radius: 14px; border: 1px solid var(--line); padding: 22px; display: grid; gap: 14px; justify-items: center; align-content: center; min-width: 0; }
.cell.light { background: var(--paper); color: #5f6d78; }
.cell.dark { background: var(--ink); color: #b9c3ca; border-color: var(--ink); }
.cell.plain { background: var(--surface); padding: 12px; }
figcaption { font-family: var(--mono); font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; }
.browser { display: flex; gap: 2px; background: #dfe3e6; padding: 8px 8px 0; border-radius: 10px 10px 0 0; width: 100%; max-width: 280px; }
.tab { display: flex; align-items: center; gap: 7px; font-size: 12px; color: #2b333a; background: #fff; padding: 7px 12px; border-radius: 8px 8px 0 0; white-space: nowrap; }
.tab.dim { background: transparent; color: #6b757d; }
.sizes { display: flex; align-items: end; gap: 16px; }
.sizes img, .app, .round { image-rendering: auto; }
.app { border-radius: 26px; }
.zoom { image-rendering: pixelated; border-radius: 10px; }
.round { border-radius: 50%; }
.card { width: 100%; height: auto; border-radius: 8px; display: block; }
.files { background: var(--surface); justify-items: start; align-content: start; }
.files ul { margin: 0; padding: 0; list-style: none; font-family: var(--mono); font-size: 12px; columns: 2; column-gap: 18px; }
.files li { break-inside: avoid; padding: 1px 0; }
.files .dir { color: var(--muted); }
.path { margin: 0; font-family: var(--mono); font-size: 12px; color: var(--muted); }
@media (max-width: 860px) {
  .marks, .logos, .icons, .social { grid-template-columns: 1fr 1fr; }
  .logos .wide, .social > * { grid-column: 1 / -1; }
  .palette { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 480px) { .marks, .icons { grid-template-columns: 1fr; } .files ul { columns: 1; } }
</style>

<div class="wrap">
  <header>
    <h1>Yak brand kit</h1>
    <p class="lede">The walker, finalised. Every mark is drawn from the print model's front view, so the logo and the printed figure share one set of proportions. The favicon is a separate face-only glyph drawn on a 16 &times; 16 pixel grid, because the full character is too detailed at that size. The wordmark is JetBrains Mono ExtraBold, outlined, so no font install is needed.</p>
  </header>

  <section>
    <h3>Palette</h3>
    <div class="palette">
      <div class="swatch" style="background:#7f9cb4;color:#16222c"><b>Slate</b>#7f9cb4</div>
      <div class="swatch" style="background:#efe4c9;color:#2f3a42"><b>Cream</b>#efe4c9</div>
      <div class="swatch" style="background:#c4643a;color:#fff"><b>Rust</b>#c4643a</div>
      <div class="swatch" style="background:#7f9a5e;color:#fff"><b>Sage</b>#7f9a5e</div>
      <div class="swatch" style="background:#e3976b;color:#2f3a42"><b>Peach</b>#e3976b</div>
      <div class="swatch" style="background:#2f3a42;color:#f3efe6"><b>Ink</b>#2f3a42</div>
    </div>
  </section>
""" + section("walker", "Walker", "The block-cursor yak on two legs, arms tucked under its fringe. In the horizontal logo it stands beside the word.") + """
</div>
"""
open(os.path.join(HERE, "..", "brand-kit.html"), "w").write(page)
print("ok", len(page))
