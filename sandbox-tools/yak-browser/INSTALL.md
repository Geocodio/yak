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
