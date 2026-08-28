# yak-browser sandbox install

## Image build
- Path: `ansible/roles/incus/tasks/main.yml` (lines 232-304) — the `yak-base` Incus container is provisioned by running `apt` + `npm` commands via `incus exec yak-base`, then snapshotted as `yak-base/ready`. There is no `docker/sandbox/` Dockerfile; the sandbox is an Incus system container, not a Docker image.
- `agent-browser` installed at: `ansible/roles/incus/tasks/main.yml:263` — `npm install -g @anthropic-ai/claude-code agent-browser playwright`. Global npm binaries land in `/usr/bin/` (NodeSource layout on Ubuntu 24.04) and are owned by `root:root`. The `yak` user runs the binary from `PATH`; no per-user install.

## Sandbox manager
- create() method: `app/Services/IncusSandboxManager.php:28`
- File-push helper: `pushFile(string $containerName, string $localPath, string $remotePath)` at `app/Services/IncusSandboxManager.php:177`. Wraps `incus file push`. Note: `incus file push` lands files owned by `root:root` inside the container — a follow-up `run(..., asRoot: true)` `chown yak:yak` is required if the yak user needs to write to them (see `pushClaudeConfig` at line 570 for the pattern). For a binary dropped into `/usr/local/bin` and executed by yak, root ownership + executable bit is fine.

## Install strategy
- Baked fallback: see Task 3 — add a step to `ansible/roles/incus/tasks/main.yml` (adjacent to the existing `npm install -g ... agent-browser ...` at line 263) that installs the bundled `yak-browser` binary into `/usr/local/bin/yak-browser` before the `yak-base/ready` snapshot is taken. The bundled artifact comes from Task 2.
- Push-on-launch: see Task 4 — after `incus start` / `waitForReady` in `IncusSandboxManager::create()` (around line 67, before `pushClaudeConfig` at line 69), call `pushFile($containerName, <host-path-to-bundled-yak-browser>, '/usr/local/bin/yak-browser')` followed by `run($containerName, 'chmod +x /usr/local/bin/yak-browser', asRoot: true)`. This lets fresh `yak-browser` builds ship without rebuilding the Incus base image.

## Video v3 commands

| Command | Purpose | Exit codes |
|---|---|---|
| `yak-browser script <file> [--base <url>] [--review]` | Lint a v3 `script.json` (spec §4, §8b). With `--base`, dry-runs every selector in a headless page and runs the asset preflight. `--review` prints the script with the editor checklist. | 0 clean, 2 lint error |
| `yak-browser shoot <file> --base <url> [--width N --height N] [--only <id>]` | One Playwright context per shot: synthetic cursor, eased scroll, 1 s hold. Writes `shots/<id>.webm`, `stills/<id>.png`, `screenshots/<id>.png` and `manifest.json` under `YAK_ARTIFACTS_DIR`. | 0 ok, 2 bad script, 3 a shot failed twice, 4 asset preflight |
| `yak-browser assets check --base <url> [--project-root <dir>]` | Asset preflight only: failed stylesheet/script requests, no same-origin stylesheet rules, a bundler error on the page, a UA-default body font, or build output older than the frontend sources. | 0 ok, 4 failure |

`manifest.json` shape:

```json
{
  "version": 3,
  "width": 1440,
  "height": 900,
  "base": "https://preview.example",
  "shots": [{ "id": "levels", "clip": "shots/levels.webm", "start": 1.9, "end": 8.4, "rect": { "x": 0, "y": 0, "w": 0, "h": 0 }, "url": "https://…", "still": "stills/levels.png" }],
  "screenshots": [{ "id": "zip-section", "file": "screenshots/zip-section.png", "caption": "The new ZIP-level section" }]
}
```

`start`/`end` are seconds within that shot's own clip; the webm carries no reliable duration header, so `end` is authoritative and ffprobe is never used on clips.

### Playwright

`yak-browser` bundles `playwright-core` (pinned in `package.json`) and launches the Chromium the sandbox image installs, found under `PLAYWRIGHT_BROWSERS_PATH` (`/home/yak/.cache/ms-playwright`) with `chromium.executablePath()` as the fallback. `ansible/roles/incus/tasks/main.yml` installs `playwright@<same version>`; `tests/v3/build.test.ts` asserts the two agree. Bump both together.

The bundle is built by `scripts/build.mjs`, which generates a banner that gives playwright-core a real `require`, a materialised package root (its `package.json` and `browsers.json` under `os.tmpdir()`), and marks `chromium-bidi/*` external. Without that banner the single-file ESM bundle throws at import.
