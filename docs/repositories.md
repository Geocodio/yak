# Repositories

Every Yak task targets exactly one repository. This guide covers how to add repos, what the automatic setup task does, how repos get routed to, and how to customize per-repo behavior through `CLAUDE.md`.

## Adding A Repository

Go to `https://{your-domain}/repos/create` and fill in the form. Yak clones the repo using the GitHub App's installation token and automatically dispatches a setup task — Claude Code reads the repo's README and `CLAUDE.md`, sets up the dev environment, and verifies everything works.

The form has three sections:

| Section | Fields |
|---|---|
| **Basics** | Slug (auto-generated from name, editable), display name, Git URL (HTTPS clone URL), path on disk (auto-filled from slug), default branch, active toggle, default toggle |
| **Integration** | CI system (`github_actions` or `drone`), Sentry project slug (optional) |
| **Notes** | Free-text operational notes. Shown only in the dashboard — never sent to Claude. |

### Validation Rules

- `slug` must be unique across all repos
- `path` must be an absolute filesystem path (typically `/home/yak/repos/{slug}`)
- At most one repo can have `is_default = true`. Toggling default on a new repo clears it from any other.

## The Setup Task

When a repo is first added, Yak dispatches a one-time **setup task** — a Claude Code session that bootstraps the dev environment. This task has `mode = setup` and runs on the `yak-claude` queue like any other task.

### What It Does

1. Reads `README.md`, `CLAUDE.md`, `docker-compose.yml`, and other config files
2. Sets up the dev environment:
   - `docker-compose up -d`
   - Installs dependencies (composer install, npm install, pip install, etc.)
   - Runs migrations and seeders
3. Verifies the environment works by starting the dev server and running the test suite
4. Reports success or failure with details of what was set up

### Dev Environment Lifecycle

The dev environment **persists across tasks**. Setup runs once to bring it up; subsequent tasks just `docker-compose start` and `docker-compose stop` as needed. There is no cold-start penalty for each new task.

If a task crashes mid-run, the `CleanupDevEnvironment` job middleware runs `docker-compose stop` so the next task starts from a clean state.

### Setup Status

The repo's `setup_status` column tracks progress:

| Status | Meaning |
|---|---|
| `pending` | Repo was added but setup has not started yet |
| `running` | Setup task is currently executing |
| `ready` | Setup completed successfully — repo is ready for tasks |
| `failed` | Setup failed. Check the setup task's detail page for logs. |

The repo list in the dashboard shows this as a colored badge.

### Re-running Setup

If the dev environment breaks (new Docker services, migration changes, dependency updates), re-run the setup task:

- **Dashboard:** click **Re-run Setup** on the repo's edit page
- **CLI:** `docker exec yak php artisan yak:setup-repo my-app`

Re-running setup tears down the old environment and brings up a fresh one.

## Routing Tasks To Repos

Every task targets exactly one repo. When a task arrives, Yak detects the target using this priority chain:

1. **Explicit mention** — `@yak in my-cli: ...`, `--repo=my-cli`, `repo: my-cli`
2. **Sentry project mapping** — the `sentry_project` column on `repositories` matches the incoming Sentry project slug
3. **Default repo** — falls back to whichever repo has `is_default = true`

Only **active repos** (`is_active = true`) are considered. Inactive repos are skipped entirely, including their Sentry mappings.

### Multi-Repo Requests

One repo per task, always. If a request mentions multiple repos — for example, "audit cron jobs across `app` and `api`" — the routing layer creates separate tasks, one per repo. Each task runs independently and posts its own results.

### Low-Confidence Detection (Slack only)

If a Slack task has no explicit `in {repo}:` mention, no Sentry mapping, and multiple active repos exist, the task enters `awaiting_clarification` with repo options before any Claude Code work begins. This avoids wasting an Opus run on the wrong codebase. If only one active repo exists, it's always the right one and no clarification is needed.

Linear and Sentry tasks never clarify — Linear falls back to the default repo, Sentry requires an explicit mapping.

## CLAUDE.md — The Highest-Leverage Config Point

Every repo should have a `CLAUDE.md` at its root. This file is loaded by Claude Code for every task and is the single most important customization point — it's how you teach Yak the conventions, patterns, and landmines specific to your codebase.

**A good `CLAUDE.md` reduces rework and bad PRs more than any other change you can make.**

### What To Put In It

- **Project structure** — where controllers, models, tests live; which directories are off-limits
- **Code conventions** — naming, type hints, docblock style, preferred patterns
- **Test patterns** — test framework, factory usage, how to run a single test, what tests to run for a given file
- **Do-not-touch list** — vendored files, generated code, migration files from past releases, packages you don't own
- **Known quirks** — environment setup steps, hidden dependencies, services that must be running
- **Dev environment** — how to start the dev server, default ports, how to seed test data, how to log in with a test user for visual capture

### Example Structure

```markdown
# CLAUDE.md

## Project Structure
- app/Http/Controllers/ — HTTP controllers; one invokable per action
- app/Services/ — external API clients and business services
- app/Models/ — Eloquent models; use factories in tests
- tests/Feature/ — feature tests (RefreshDatabase enabled globally)

## Conventions
- Use single quotes unless interpolation is needed
- Explicit return types on all methods
- Pest for tests — no PHPUnit class syntax

## Testing
- `vendor/bin/pest --compact --filter={name}` for a single test
- For controller changes, run `tests/Feature/Http/` only
- Full suite runs on CI, not locally

## Do Not Touch
- app/Legacy/ — scheduled for deletion in Q2
- database/migrations/2019_* — frozen, don't amend
- lib/vendor-patches/ — maintained by hand

## Dev Environment
- `docker-compose up -d` brings up mysql, redis, meilisearch
- App runs at http://localhost:8000 after `php artisan serve`
- Test user: test@example.com / password (seeded via DatabaseSeeder)
```

### Iterating On CLAUDE.md

Treat `CLAUDE.md` as a living document. Every bad Yak PR is a signal that `CLAUDE.md` needs a new rule. When a reviewer rejects a PR for a convention Yak should have known, add that convention to `CLAUDE.md` — the next task won't repeat the mistake.

## Repo Management Pages

### `/repos` — List

Shows all configured repos with slug, name, CI system, setup status badge, active/inactive state, default flag, and task counts (total and last 7 days). Click a row to edit.

### `/repos/create` — Add

The three-section form described above. After save, the setup task is auto-dispatched and the repo's setup status transitions from `pending` → `running`.

### `/repos/{id}/edit` — Edit

Same form pre-filled with current values. Also includes:

- **Re-run Setup** button — re-dispatches the setup task
- **Deactivate** toggle — soft-disables the repo (historical tasks remain; new tasks will not route here)
- **Delete** (danger zone) — only available if the repo has zero tasks. If the repo has any task history, you must deactivate instead.

## Repo Refresh

Yak automatically runs `git fetch origin {default_branch}` every 30 minutes via the scheduled `yak:refresh-repos` command. This keeps each repo's default branch tip up to date so that new tasks start from the latest code without a manual pull.

## What Isn't Stored Per-Repo

The `repositories` table is deliberately minimal — just slug, name, path, default branch, CI system, Sentry mapping, and notes. Everything else is auto-detected at task time:

| Thing | Where it comes from |
|---|---|
| Language / framework | `README.md`, `CLAUDE.md`, and file inspection by Claude Code |
| Test commands | `CLAUDE.md` and `composer.json` / `package.json` scripts |
| Dev server command | `CLAUDE.md` and `docker-compose.yml` |
| Dependency install commands | `CLAUDE.md` and detected package managers |
| Test credentials for visual capture | `CLAUDE.md`, seeder files, or `.env.example` |

This is intentional: different repos have very different setups, and forcing them all into a single database schema would be brittle. `CLAUDE.md` is the one file you maintain — and because it lives in the target repo, it travels with the code and is version-controlled by the team that owns it.
