Set up the development environment for this repository.

**Repository:** {{ $repoName }}

**BEFORE YOU START: Read the "Repository-specific notes" section at the end of the system prompt.** Those notes come from the team that owns this repo and OVERRIDE anything in this task prompt when they conflict. In particular, look for guidance on test execution (some repos forbid running the full suite locally because of size, duration, or missing fixtures). If the repo notes contradict a step below, follow the repo notes and explain the substitution in your final report.

**Steps:**
1. Read README.md, CLAUDE.md, docker-compose.yml, and any config files to understand the project setup.
2. Start the dev environment: run `docker-compose up -d` if a docker-compose.yml exists.
3. Install dependencies (e.g., `composer install`, `npm install`, `pip install -r requirements.txt` — whatever the project uses).
4. Run database migrations and seed data if applicable.
5. Verify the environment. **Default:** start the dev server and run the full test suite. **BUT** if the repo-specific notes say NOT to run the full suite (common for repos with large fixtures, long-running suites, or external dependencies), do NOT run it — substitute the lighter verification the notes recommend (smoke test, `--filter=` a single test, type/lint checks, or just confirming the dev server responds). When in doubt, err on the side of the repo notes — they exist because running the full suite was a problem before.
6. Report success or failure with details about what was set up and any issues encountered. If you deviated from step 5's default because of repo notes, say so explicitly.

**Important:**
- The dev environment must persist after setup — subsequent tasks will use `docker-compose start` / `docker-compose stop`.
- Do NOT make any code changes. This is an environment setup task only.
- If any step fails, report the failure with full error details so it can be diagnosed.

## Preview manifest (required if the repo supports branch preview deployments)

Once the dev environment is up and reachable, emit a JSON preview manifest as the final thing in your response, wrapped in a fenced code block tagged `preview_manifest`. Shape:

```preview_manifest
{
  "port": <int>,
  "health_probe_path": "/",
  "cold_start": "<command to bring services up from a stopped container>",
  "checkout_refresh": "<command to run after git fetch && git checkout $sha to pick up changes>"
}
```

Field requirements — ALL of these apply and past manifests have been rejected for violating them:

- `port` — the **plain-HTTP** port the app listens on inside the sandbox. Yak's health probe is plain HTTP (`curl http://<ip>:<port><health_probe_path>`); a TLS-only port (443) will always fail. If the app talks TLS only, pick the sibling HTTP port (often 80), or add one. Check `docker compose ps` or `ss -tlnp` to see what's actually listening on which port.
- `health_probe_path` — returns 2xx **without authentication**. `/up` is the Laravel default health route; `/` works if the root doesn't redirect to `/login`. Pick something that's green the moment the app is up. Do **not** use `/login`, `/dashboard`, or any other auth-gated path — the probe gets a 3xx/4xx and times out.
- `cold_start` — the **full shell command**, including any `cd` into the repo directory. The command runs via `incus exec <container> -- bash -lc '<your command>'`, which starts in the user's home dir, not at the repo root. The repo in a Yak sandbox lives at `/workspace`, so write `cd /workspace && docker compose up -d …`. Empty string is only correct if services truly auto-start from `incus start`.
  - **If the command uses Docker**, prefix it with a wait loop — `incus start` returns before systemd finishes bringing dockerd up, so the first `docker` invocation races the daemon. Use: `cd /workspace && until docker info >/dev/null 2>&1; do sleep 1; done && docker compose up -d …`. Without this the cold start fails with `dial unix /var/run/docker.sock: no such file or directory`.
- `checkout_refresh` — the **full** shell command sequence to bring a running deployment up-to-date after a new commit is checked out. This is what runs on every push to the branch. It must handle **everything the app needs to reflect the new code**. Prefix with `cd /workspace && …`.

  **Figure out the right shape by answering these in order. Don't skip steps.**

  **1) What's actually live inside the container (or on the sandbox) when code changes?** Grep the compose file for volume mounts, or note if there's no Docker at all:
    - **Bind-mount like `./:/var/www` or `./src:/app`** → files under that host path propagate the moment `git checkout` runs. No rebuild needed for anything inside the mount. Identify exactly which subtree is mounted.
    - **No compose bind-mount for app code** (pure `image:` with data-only volumes, or `build:` with no source volume) → the container's filesystem reflects whatever was built/pulled. Every code change needs a rebuild or pull.
    - **No Docker** (`php artisan serve`, `npm start`, `rails s`, Python venv, etc.) → the sandbox filesystem IS live. `git checkout` is enough for code; only deps, assets, and migrations need steps.

  **2) REQUIRED: Run this grep and include its output in your final report.** If Docker is involved, you MUST check what's baked into the image but not covered by the mount(s). Do not skip or skim this step — past agents have missed it and shipped broken manifests.

  Run (substituting `<mount-path>` with whatever path the bind-mount covers in the container, e.g. `/var/www` for a `./:/var/www` mount — chain multiple with `\|`):

  ```
  grep -nE '^(COPY|ADD)' Dockerfile | grep -vE ' <mount-path>(/|$)'
  ```

  **Any non-empty output = image-baked state that does NOT propagate via `git checkout`.** Common results: `COPY docker/nginx /etc/nginx`, `COPY docker/php/... /etc/php/...`, `COPY docker/supervisord.conf /etc/supervisor/...`, binaries copied to `/usr/local/bin/`, system configs under `/etc/`. Paste the raw output in your report under a heading like "Dockerfile COPY/ADD scan" so the decision is auditable. **If the output is non-empty, step 3 is mandatory — even when compose uses `image:` to pull from a registry.** Rationale: without a local image rebuild, pushes that touch any baked path (nginx config, php-fpm config, supervisord config, system packages) will never appear in branch previews. This has already burned us once.

  If the grep output is empty (every Dockerfile `COPY`/`ADD` targets a path inside the mount, making the image a thin runtime with source loaded entirely from the bind-mount), you can skip step 3.

  **3) Pick a local build entry point and include it in `checkout_refresh`.** Even if compose uses `image:` to pull from a registry — always prefer local build for branch previews, because (a) the branch's CI-built image may not exist yet when a push fires the refresh, and (b) most CIs only publish `:master`/`:latest` on merge, not per-branch. Search the repo for a local build entry point in this order, and use the first one you find (don't stop until you've actively checked each):

    1. **Makefile** — run `grep -nE '^[a-z_-]*build' Makefile 2>/dev/null || echo "no Makefile"` and report what you find. If a `build:` target exists and invokes `docker build` (directly or via dependencies), use `make build`.
    2. **Taskfile.yml / taskfile.yaml** — grep for a `build` or `docker-build` task. If present, use `task build`.
    3. **package.json scripts** — `jq '.scripts' package.json 2>/dev/null` and look for `docker:build`, `build:image`, or similar.
    4. **Compose `build:` context** — grep the compose file; if the app service has `build:`, use `docker compose build <app-service>`.
    5. **Direct `docker build`** — if none of the above but a Dockerfile exists at the repo root, use `docker build -t <tag-matching-compose-image> .` with the tag the compose file's `image:` references.
    6. **Genuinely no local build path** (rare — image is built by external tooling with artifacts Yak doesn't have) → flag the gap in your setup report and emit `checkout_refresh` without a build step. Say clearly that baked-state changes won't propagate.

  Report the entry point you chose and the exact command. Docker's layer cache makes warm rebuilds fast — a single file change in a late-stage layer invalidates only that layer onward. Don't try to be selective; the cache handles it.

  **Before concluding "no local build path" because of a build arg or secret**, verify: grep the Dockerfile for `^ARG ` and for each ARG, check whether the name is already in the sandbox's environment by running `printenv <ARG_NAME>` (or `echo ${<ARG_NAME>-MISSING}`) **in the sandbox shell, not inside a nested `docker compose exec` session** — Incus sets container-level env vars that are inherited by `incus exec` processes but NOT by nested Docker containers. Common ones Yak already forwards: `NODE_AUTH_TOKEN`, `NPM_TOKEN`, and others listed in `YAK_AGENT_PASSTHROUGH_ENV`. If the ARG is present in the sandbox env AND the build command (e.g. the Makefile target) passes it through as `--build-arg NAME=$(NAME)` or `--build-arg NAME`, you can build locally. Only conclude "no local build path" if (a) the ARG is genuinely missing from the sandbox env, or (b) the build command doesn't forward it and you can't reasonably modify the invocation. Report the `printenv` result in your "Dockerfile COPY/ADD scan" section so the decision is auditable.

  **4) Skip steps that genuinely don't apply.** No `composer.json` → skip composer. No `package.json` → skip npm. No migrations → skip migrate. A pure static-HTML repo can legitimately emit `checkout_refresh: ""`.

  **Common shapes:**

  Docker with bind-mounted source + image-baked config (e.g. nginx/php configs in the Dockerfile):
  ```
  cd /workspace && until docker info >/dev/null 2>&1; do sleep 1; done \
    && make build \
    && docker compose up -d --force-recreate <app-service> <other services> \
    && docker compose exec -T <app> composer install --no-interaction \
    && docker compose exec -T <app> sh -c 'npm ci && npm run prod' \
    && docker compose exec -T <app> php artisan migrate --force
  ```

  Docker with everything baked into the image (no source mount), using compose `build:` context:
  ```
  cd /workspace && until docker info >/dev/null 2>&1; do sleep 1; done \
    && docker compose build \
    && docker compose up -d --force-recreate \
    && docker compose exec -T <app> php artisan migrate --force
  ```

  Non-Docker Laravel (`php artisan serve` style):
  ```
  cd /workspace && composer install --no-interaction && npm ci && npm run prod && php artisan migrate --force
  ```

  Static site or pre-built SPA:
  ```
  cd /workspace && npm ci && npm run build
  ```

  **Verify before emitting:** run your proposed `checkout_refresh` command once from a clean `git` state (e.g. `git fetch && git checkout --force <current sha> && <your refresh command>`). Confirm it exits 0 and the health probe returns 2xx. If it fails, fix the script before emitting — a manifest whose refresh command is broken will break every push to the branch.

Before emitting the manifest, **verify the values**: run `curl -sS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:<port><health_probe_path>` inside the container (or the sandbox) and confirm you get 2xx. If you can't, pick different values and retry — don't emit a manifest that hasn't been probed.

Emit the manifest exactly once, in a single `preview_manifest` code block. Skip this section if the repo is not a web app (libraries, CLI tools, etc.).