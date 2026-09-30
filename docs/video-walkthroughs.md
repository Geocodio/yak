# Video Walkthroughs

Every task that touches UI gets a recorded walkthrough attached to its PR: a short video of the change actually working, built from a script Claude writes and a set of shots the sandbox captures. See [Architecture](architecture.md#walkthrough-videos) for how the video is made, and [Troubleshooting](troubleshooting.md#walkthrough-video-did-not-appear) if a task's video is missing or failed.

This page covers the installation-wide look of that video: the theme editor at `/settings/video`.

## Theming

Go to **Settings → Video walkthroughs** (`/settings/video`, sign-in required like every other settings page). The page writes a single settings row (`video_themes`, id 1) that every future render reads from. Saving a change never touches videos that have already rendered; it only takes effect on the next one.

### Colors and fonts

Seven colors control the composition:

| Color | Paints |
|---|---|
| `background` | Page background behind every card |
| `surface` | The chapter card fill |
| `ink` | Body text |
| `muted` | Secondary/muted text |
| `accent` | The focused element and the caption rule |
| `done` | The checkmark color on the summary card |
| `captionBg` | The caption bar background |

Fonts have three roles (`display`, `body`, `mono`). Pick each from the list on the page.

> **The saved row always wins over the config default.** `config('yak.video.theme')` supplies the defaults only for keys the `video_themes` row does not set. Once the theme editor has ever been saved, the row holds a complete `colors` and `fonts` map, so changing `config/yak.php` (or a `YAK_VIDEO_*` env var) has no visible effect. To make config defaults take effect again, use **Reset to defaults** on the page, or delete the row.

### Logo

Logos: PNG or SVG, up to 512 KB. SVGs with scripts or event handlers are rejected. The logo shows 40 px tall in the top-left of the title and summary cards.

### Render sample video

The "Render sample video" action dispatches `RenderThemeSampleJob` on the `yak-render` queue, the same queue the real per-task renders use. Only one sample render is allowed in flight at a time (a cache flag the job clears when it ends), so repeated clicks cannot flood that queue. The download link on the page appears once `theme/sample.mp4` lands on the `artifacts` disk. If nothing appears after a few minutes, check `php artisan queue:failed` for a failed `RenderThemeSampleJob`, and confirm the `yak-render` worker is actually running (`supervisorctl status` on the Yak host, or see [Troubleshooting → Task Stuck In running](troubleshooting.md#task-stuck-in-running) for the general worker-health checklist).

### Live preview

The live preview column on the settings page needs a build artifact, `public/vendor/video-preview.js`. In the Docker image this is produced automatically during the build by `npm run build:preview` in `video/`. To get it locally without a full image build:

```bash
cd video && node scripts/build-preview.mjs
mkdir -p ../public/vendor/v3
cp dist/preview.js ../public/vendor/video-preview.js
cp public/v3/preview-still.jpg ../public/vendor/v3/preview-still.jpg
```

Without this file the page still saves normally; only the live preview player is blank.

### Public site URL

Each video's browser-bar chrome shows a URL, and by default that's the sandbox's internal address. To show something meaningful instead, set **Public site URL** on the repository (**Repositories → the repo → edit**). Leave it blank to show just the path with no host.

### Voiceover

Voiceover is read-only on this page: it's a global capability toggle, not a per-theme setting. It's on when `ELEVENLABS_API_KEY` is configured for the installation; otherwise every walkthrough is captions only.

Narration uses ElevenLabs (`eleven_multilingual_v2`). Before each render, Yak turns the script's intro, each shot's `say` line and the outro into audio and mixes it into the cut. Expect roughly 1,000 to 1,300 characters per walkthrough, billed at one credit per character. `ELEVENLABS_VOICE_ID` picks the voice.

To create the key, open **Developers → API Keys** in ElevenLabs and create a restricted key with **Text to Speech: Access** and everything else set to No Access. A per-credit refresh period works as a spend cap. Then set `elevenlabs_api_key` (and optionally `elevenlabs_voice_id`) in the vault.

Voiceover is best-effort. With no key, or when the API errors, the walkthrough still renders with captions and Yak still opens the PR. The **Voiceover** row on the health page shows the state, and the cost dashboard sums the characters sent.
