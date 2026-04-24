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
- `checkout_refresh` — the **full** shell command sequence to bring a running deployment up-to-date after a new commit is checked out. This is the workflow that runs on every push to the branch. It must handle **everything the app needs to reflect the new code**, including (if applicable): Docker image rebuilds (`docker compose build`), service restarts (`docker compose up -d`), PHP dependencies (`composer install`), JS dependencies + asset builds (`npm ci && npm run prod` or `npm run build`), database migrations (`php artisan migrate --force`), cache clears, and any other step that's normally part of a deploy. Do **not** try to be selective about "only rebuild if the Dockerfile changed" — the underlying tools (Docker layer cache, npm cache, composer cache) already make no-op runs cheap. Write the full pipeline; let the caches handle skipping. Prefix with `cd /workspace && …`. Empty string is only correct if a plain `git checkout` already reflects all necessary state (very rare — basically static HTML).

  **Verify before emitting:** after you've brought the dev environment up and before you emit the manifest, run your proposed `checkout_refresh` command once from a clean `git` state (e.g. `git fetch && git checkout --force <current sha> && <your refresh command>`). Confirm it exits 0 and the app still responds at the health probe. If it fails, fix the script before emitting — a manifest whose refresh command is broken will break every push to the branch.

Before emitting the manifest, **verify the values**: run `curl -sS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:<port><health_probe_path>` inside the container (or the sandbox) and confirm you get 2xx. If you can't, pick different values and retry — don't emit a manifest that hasn't been probed.

Emit the manifest exactly once, in a single `preview_manifest` code block. Skip this section if the repo is not a web app (libraries, CLI tools, etc.).