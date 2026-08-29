# Yak walkthrough video

Remotion project that turns a recorded walkthrough (`script.json` + `manifest.json`,
produced by `yak-browser shoot`) into the mp4 attached to a pull request.

## Compositions

| id | What it is |
|---|---|
| `WalkthroughV3` | The v3 cut. Title card, a card per chapter, one block per shot over its footage, summary card. |
| `PreviewWalkthrough` | `WalkthroughV3` with a bundled sample script and a still instead of footage, for the theme settings page. |
| `Walkthrough` | The v2 cut. Still rendered by the Laravel host until the v3 swap lands. Do not build on it. |
| `FakeUI` | A synthetic page used by the v2 fixtures. |

## `WalkthroughV3` props

```ts
type WalkthroughV3Props = {
  script: Script;                 // script.json, spec §4
  manifest: Manifest;             // manifest.json, spec §5
  voiceover: Record<string, { file: string; durationSeconds?: number; duration?: number }> | null;
  theme: PartialTheme | null;     // spec §9; null uses the defaults below
  publicOrigin: string | null;    // e.g. "https://www.example.com"; null shows the path alone
};
```

`durationSeconds` is spec §6's key; `duration` is an alias produced by the spike tooling and is
accepted as a fallback (see `voiceoverSeconds()` in `src/lib/v3/types.ts`).

- `manifest.shots[].clip` is a per-shot clip; a manifest-level `clip` is used when a shot omits one, and
  failing that the conventional `shots/<id>.webm`. Clips and voiceover files may be absolute local paths,
  `file://` urls, `https://` urls, `data:`/`blob:` urls, or names relative to `video/public/` (resolved
  via `classifySrc()` in `src/lib/v3/assets.ts`).
- `manifest.shots[].start` / `end` are seconds within the clip; the webm carries no reliable duration
  header, so these are authoritative and nothing probes the clip.
- The composition is `manifest.width` × (`manifest.height` + 52), the 52 px being the browser bar.
- `publicOrigin` is substituted into the browser bar. The manifest's raw host is never rendered.
- `theme.logo` is the only image on a card. There is no built-in branding.
- Font families must be one of the 24 bundled Google families (`supportedFontFamilies()` in
  `src/lib/v3/fonts.ts`); anything else falls back to a system stack rather than failing the render.

Default theme (spec §9):

```json
{
  "colors": {
    "background": "#f5f0e8", "surface": "#3d4f5f", "ink": "#1f2428", "muted": "#4e5049",
    "accent": "#c4744a", "done": "#7a8c5e", "captionBg": "rgba(31,36,40,0.92)"
  },
  "fonts": { "display": "Bricolage Grotesque", "body": "Instrument Sans", "mono": "JetBrains Mono" },
  "logo": null
}
```

## Timing

`src/lib/v3/blocks.ts` (`buildBlocks`, re-exported from `src/lib/timeline.ts`) is the single source of
truth. Every block has a readable floor, a driver and a ceiling; fades are added on top of readable
time, never subtracted from it.

| Block | Readable floor | Driver | Ceiling |
|---|---|---|---|
| Title card | 4.0 s | voiceover(intro) + 0.7 s, else reading time of `intro` | 8 s |
| Chapter card | 2.0 s | fixed | 2.0 s |
| Shot | 3.0 s | max(clip length + 1.0 s hold, voiceover(say) + 0.5 s, reading time of `say`) | 20 s |
| Summary card | 5.0 s + 0.4 s per bullet | voiceover(outro) + 1.0 s | 10 s |
| Fade in / out | 0.25 s / 0.30 s | fixed | |
| Shot-to-shot crossfade | 0.25 s | fixed | |
| Chapter dip | 0.40 s | fixed | |

Reading time = words / 165 wpm × 60 s + 0.9 s, minimum 2.4 s.

Blocks sit back to back. Each carries `transitionInSeconds` (its own lead-in: 0.25 s for the title card
and for a shot following another shot, 0.40 s for a chapter card, the first shot after one, and the
summary card) and is rendered in a sequence extended by the *next* block's lead-in, fading out over that
tail. That overlap is the crossfade. The composition ends with a 0.30 s fade-out.

When a shot's clip is shorter than its block, the last frame freezes for the remainder; the caption and
spotlight stay up. The spotlight fades in over the clip's last 0.6 s (`spotlightFadeSeconds`). Captions
themselves cross-fade over 0.35 s (`captionFadeSeconds`) when the line changes.

## `scripts/timeline.ts`

Remotion components run in headless Chrome and cannot write files, so the host runs this before a render
to get the cut's shape.

```
npx tsx scripts/timeline.ts --script script.json --manifest manifest.json \
  [--voiceover vo.json] [--fps 30]
```

Prints one JSON object to stdout and exits 0; on a usage or file error it prints to stderr and exits 2
with nothing on stdout. Flags accept either `--flag value` or `--flag=value`.

| Field | Meaning |
|---|---|
| `fps`, `width`, `height` | Composition settings; `height` already includes the 52 px browser bar. |
| `durationSeconds`, `durationInFrames` | Expected length of the render. |
| `blocks[]` | Every block: `kind`, `id`, `startSeconds`, `startFrame`, `durationSeconds`, `durationInFrames`, `transitionInSeconds`, `voiceover`, plus kind-specific fields (`title`/`index`/`total`/`leadSay` for chapters; `shot`, `manifestShot`, `clip`, `clipStartSeconds`, `clipSeconds`, `freezeSeconds`, `clipTruncatedSeconds` for shots). `freezeSeconds` is how long the last clip frame is held after a short clip runs out; `clipTruncatedSeconds` is how much of an over-long clip the block has no room for and the viewer never sees. Exactly one of the two is non-zero. |
| `chapters[]` | `{ title, startSeconds, shots: [{ id, startSeconds, say }] }` — write this to `chapters.json`. |
| `captionOverflow[]` | `{ shotId, width }` for every caption that will not fit its box. Empty means every caption fits. |

### `--theme-defaults`

```
npx tsx scripts/timeline.ts --theme-defaults
```

Needs none of the other flags. Prints `{ "theme": <DEFAULT_THEME>, "fonts": [...] }` and exits 0, where
`theme` is the spec §9 default palette and font trio and `fonts` is every Google family a theme may name.
A settings page seeds its default theme row and populates its font dropdown from this instead of
restating the values, so the two can never drift.

### How caption overflow is measured

There is no font metric available outside a browser, so the estimate is deliberately font independent
and identical wherever it runs. Each character contributes `fontSize × ratio` pixels, with ratios of
0.26 for a space, 0.30 for narrow glyphs (`i j l I t f r . , : ; ' ! | ( ) [ ] \``), 0.90 for wide ones
(`m w M W @ —`), 0.56 for digits, 0.66 for capitals and 0.52 otherwise. The caption box holds
`1040 − 2 × 28 − 6 = 978` px per line and at most 3 lines, so a caption overflows when its estimated
single-line width exceeds `978 × 3 = 2934` px.

This is an approximate lower-bound estimate, not a proof of fit. Summing glyph advances undercounts what
greedy wrapping really consumes, because wrapping wastes whatever is left on each ragged right edge. A
reported caption certainly will not fit; a caption that passes is likely but not guaranteed to fit, and
can still spill onto a fourth line.

## Development

```bash
cd video
npm run preview            # remotion preview src/index.ts — Remotion Studio
npm run render              # remotion render src/index.ts Walkthrough out/walkthrough.mp4
npm run render:v3           # remotion render src/index.ts WalkthroughV3 out/walkthrough.mp4
npm test                    # vitest run (pure logic only; no DOM tests)
npm run typecheck           # tsc --noEmit
npm run timeline -- --script fixtures/v3/script.json --manifest fixtures/v3/manifest.json
YAK_E2E_RENDER=1 npx vitest run src/__tests__/render.e2e.test.ts
```

### Live preview bundle

```bash
npm run build:preview       # esbuild → dist/preview.js, copied to public/vendor/video-preview.js in the image
```

A single self-contained IIFE (React and `@remotion/player` bundled in) that runs
the `PreviewWalkthrough` cut from `src/preview/sample.ts` so the theme settings
page can preview a theme without a render. It sets `window.YakVideoPreview` to
`{ mount(el, props?), update(theme), seekToBlock('title'|'chapter'|'shot'|'summary') }`.

The sample clip is `v3/preview-still.jpg`, resolved through Remotion's
`staticFile()`; the bundle sets `window.remotion_staticBase` to `/vendor`, so
the page must serve `public/v3/` under `/vendor/` (the Dockerfile copies the
still to `public/vendor/v3/preview-still.jpg`). Set
`window.YakVideoPreviewStaticBase` before the script loads to point elsewhere.

Fixtures live in `fixtures/v3/` and reference the media in `public/v3/`.
