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

- `port` is the container port where the app serves HTTP
- `health_probe_path` is a path that returns 2xx when the app is ready
- `cold_start` is what to run when the container boots from stopped; empty if services auto-start
- `checkout_refresh` is what to run after a branch checkout; empty if `git checkout` alone is enough

Emit the manifest exactly once, in a single `preview_manifest` code block. Skip this section if the repo is not a web app (libraries, CLI tools, etc.).