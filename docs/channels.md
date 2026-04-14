# Channels

Every external integration in Yak is a pluggable channel. A channel can fill up to three roles:

| Role | What it does | Example |
|---|---|---|
| **Input** | Creates tasks from external events | Slack `@yak` mention, Linear label, Sentry alert |
| **CI** | Reports build results back to Yak | GitHub Actions, Drone |
| **Notification** | Posts status updates and results | Slack thread reply, Linear comment, PR comment |

Channels are enabled by the presence of credentials — no credentials, no channel. Yak detects which channels are active at boot and only registers routes, webhooks, and MCP servers for active channels. Disabled channel routes return 404.

**The routing rule is simple:** respond where you were asked. If a task comes from Slack, results go back to Slack. If it comes from Linear, results go back to Linear. Tasks from the manual CLI or Sentry post results to the PR only.

## Channel Summary

| Channel | Roles | Required | Where usage begins |
|---|---|---|---|
| GitHub | CI (Actions), notification (PRs) | **yes** | Always on |
| Manual CLI | Input | **yes** | Always on |
| Slack | Input, notification | no | Bot mention |
| Linear | Input, notification | no | `yak` label |
| Sentry | Input | no | Alert rule |
| Drone CI | CI | no | Per-repo webhook |

The minimum viable setup is **GitHub + manual CLI**. Everything else is optional.

---

## GitHub (required)

GitHub is the only channel Yak cannot run without — it needs it to push branches and open PRs.

**Roles:** CI (via Actions), notification (PR bodies and comments).

### Setup

The Ansible provisioner creates a GitHub App automatically. You provide `github_org` in vault; Ansible handles app creation, installation, and credential storage.

If you already have a GitHub App and want to reuse it, fill in `github_app_id`, `github_app_private_key`, and `github_webhook_secret` in `ansible/vault/secrets.yml` before running the playbook.

### Permissions

| Permission | Access |
|---|---|
| Contents | Read & Write (push branches) |
| Pull requests | Read & Write (create PRs, add labels) |
| Issues | Read |
| Checks | Read (CI results) |
| Metadata | Read (default) |

### Webhook Events

The GitHub App subscribes to:

- `check_suite.completed` — CI result processing
- `pull_request.closed` — merge/close tracking

Webhook URL: `https://{your-domain}/webhooks/ci/github`

### Usage

If your repos use GitHub Actions for CI, set `ci_system: github_actions` in the repo definition. Nothing else is required — the GitHub App receives check suite events automatically.

**Important:** the GitHub App must NOT be in your branch protection bypass list and must not have permission to approve reviews. Yak has no merge authority by design.

---

## Manual CLI (always available)

No configuration needed. Available as soon as Yak is running.

```bash
# Run a task against the default repo
docker exec yak php artisan yak:run TICKET-123 "Fix the broken CSV export"

# Run against a specific repo
docker exec yak php artisan yak:run TICKET-456 "Fix timeout on batch endpoints" --repo=api

# Research task (no code changes, produces HTML findings page)
docker exec yak php artisan yak:run TICKET-789 "Audit deprecated field usage" --research --repo=api

# Run in foreground so you can watch progress (useful for debugging)
docker exec yak php artisan yak:run TICKET-001 "Add README comment" --sync
```

The full command signature:

```
yak:run {id} {description} [--repo=] [--context=] [--research] [--sync]
```

Results post to the PR (for fix tasks) or to the task's dashboard page (for research tasks). There is no originating channel to post back to.

---

## Slack (optional)

**Roles:** Input (task creation via `@yak` mention), notification (thread replies).

### Setup

1. Create a Slack app at [api.slack.com/apps](https://api.slack.com/apps)
2. Enable **Event Subscriptions** with request URL `https://{your-domain}/webhooks/slack`
3. Subscribe to bot events:
   - `app_mention`
   - `message.channels` (needed for thread replies to clarifications)
4. Add bot scopes:
   - `chat:write`
   - `app_mentions:read`
   - `channels:history`
5. Install the app to your workspace
6. Add the following to `ansible/vault/secrets.yml`:

   ```yaml
   slack_bot_token: xoxb-...
   slack_signing_secret: ...
   ```

7. Re-run Ansible

### Usage

```
@yak fix the broken CSV export
@yak in api: fix the timeout on batch endpoints
@yak research: which endpoints still use the deprecated `accuracy_type` field?
```

Yak responds in the same thread with acknowledgment, progress, and results.

### Clarification Flow

Slack is the only channel where Yak will ask for clarification. If a request is ambiguous, Claude Code reads the codebase and posts 2–3 specific options grounded in what it found:

```
I want to make sure I fix the right thing. Which did you mean?

1. Fix the XLSX parse failure on files with merged header cells
2. Fix the timeout on uploads over 50k rows
3. Fix the auto-detect picking "Street 2" over "Street"

Reply with a number and I'll get started.
```

The task pauses in `awaiting_clarification` for up to 3 days. Reply in the thread with a number and Yak resumes the same Claude session via `--resume` — no re-reading, no re-analysis.

Linear and Sentry tasks do not clarify because their inputs are already structured.

### Gotchas

- **Channels history scope is required** for thread reply matching. Without it, clarification replies cannot be routed to the correct task.
- **Bot token rotation** requires re-running Ansible to update the container env vars.
- **3-day TTL** — clarifications that aren't answered auto-expire with a "Closing this — mention me again" message.

---

## Linear (optional)

**Roles:** Input (task creation via label), notification (issue comments and state transitions).

Linear is integrated via an **OAuth2 app with `actor=app`** so comments and
state updates are authored by the Yak app rather than a human user. A
separate personal API key is used only by the Linear MCP server that
Claude Code invokes during agent runs (read-side only, never writes).

### Setup

1. **Register the OAuth app** at Linear → **Settings → API → Applications →
   New application**.
   - Name: `Yak`
   - Redirect URI: `https://{your-domain}/auth/linear/callback`
   - On the app detail page, enable the **Actor: app** toggle. Without
     this, Linear will reject the `actor=app` parameter at authorize
     time.
   - Scopes to request: `read` and `write`. That's all the outbound
     driver needs — `write` covers both `commentCreate` and
     `issueUpdate`. Do **not** request `admin`; Linear forbids it when
     combined with `actor=app`.
2. **Configure the webhook on the OAuth app** (same Applications page):
   - URL: `https://{your-domain}/webhooks/linear`
   - Subscribe to **Issue** events. (Linear's "Issue labels" event type
     is for label entity changes, not labels being applied to issues.)
   - Copy the app's webhook **signing secret**.
3. **Create a `yak` label** in your workspace (optionally a `research`
   label too).
4. **Generate a personal API key for MCP** at Linear → **Settings → API
   → Personal API keys**. This is consumed only by Claude Code's Linear
   MCP server during agent runs and is kept separate from the OAuth app
   deliberately. Revoking or rotating it does not affect the OAuth
   integration.
5. Add to `ansible/vault/secrets.yml`:

   ```yaml
   linear_oauth_client_id: lin_api_...
   linear_oauth_client_secret: lin_oauth_...
   # Defaults to https://{yak_domain}/auth/linear/callback if omitted.
   linear_oauth_redirect_uri: ""
   linear_webhook_secret: lin_wh_...
   linear_mcp_api_key: lin_api_...  # personal API key, MCP-only
   ```

6. Re-run Ansible to push the env onto the container.
7. **Authorize the app from Yak**: sign in to the dashboard → **Settings →
   Linear → Connect Linear**. Pick the workspace on Linear and you'll be
   redirected back with a confirmation.

### Usage

Add the `yak` label to any issue. Add `yak` + `research` for research-only tasks.

Anyone on the team can trigger a task by applying the label — no Linear seat is needed, because the OAuth app posts as itself, not as a user.

### Why two Linear credentials?

- **OAuth app** (required): posts comments, updates issue state. Authored
  by the Yak app — actions don't appear attributed to any individual
  user.
- **Personal API key** (optional but recommended): only for the Linear
  MCP server Claude Code uses while working on tasks. It's a read-side
  tool the agent uses to look up issue details. MCP's upstream server
  currently takes a personal key, not an OAuth token; that's the only
  reason this key still exists. It never posts comments.

### Issue State Management

Yak manages the Linear issue's workflow state throughout the task lifecycle:

| Event | Issue state |
|---|---|
| Task picked up | → **In Progress** |
| PR created (CI green) | → **In Review** |
| Research completed | → **Done** |
| Task failed | remains In Progress with a failure comment |

### Repo Detection

Linear issues rely on the standard priority chain:

1. Explicit mention in the issue body: `in my-cli:` or `repo: my-api`
2. Falls back to the default repo

Linear projects are not mapped to repos — issues frequently span projects, so a hard mapping is too limiting.

### Gotchas

- **Labels are the trigger**, not assignment or `@mentions`. Assignment requires a paid seat; labels don't.
- **Label removal does nothing** — removing the `yak` label after a task is dispatched will not cancel it.

---

## Sentry (optional)

**Roles:** Input (task creation from alert webhooks).

### Setup

1. Create an internal integration at **Settings → Developer Settings → Internal Integrations**
2. Permissions required: **Organization: Read**, **Project: Read**, **Issue & Event: Read**. Organization+Project read are what lets the Add Repository form populate the Sentry project dropdown — skip them and the form silently falls back to a plain slug text input.
3. Set the webhook URL: `https://{your-domain}/webhooks/sentry`
4. Create an alert rule tagged `yak-eligible` for the issues you want Yak to pick up
5. Map Sentry projects to repositories via the `sentry_project` field on each repo (see [repositories.md](repositories.md))
6. Add to `ansible/vault/secrets.yml`:

   ```yaml
   sentry_auth_token: ...
   sentry_webhook_secret: ...
   sentry_org_slug: your-org
   ```

7. Re-run Ansible

### Filtering

Most Sentry issues are infrastructure noise, not code bugs. Yak filters aggressively before creating a task:

| Filter | Rejected |
|---|---|
| **CSP violations** | Culprit matches `font-src`, `script-src-elem`, `script-src-attr`, `style-src-elem`, `connect-src`, `img-src`, `media-src`, `default-src`. Title starts with "Blocked". |
| **Transient infra errors** | `RedisException`, `Predis\*Exception`, `php_network_getaddresses`, `context deadline exceeded`, `Connection refused`, `Operation timed out`. |
| **Seer actionability** | Anything below `medium`. |
| **Event count** | Fewer than 5 events (one-off user errors). |
| **Deduplication** | The `UNIQUE(external_id, repo)` constraint on `tasks` rejects repeat issues. |

### Priority Bypass

Issues tagged `yak-priority` bypass both the event count and actionability filters. Use this for critical first-seen regressions that haven't accumulated 5 events yet. The tag is a deliberate human decision — Yak does not apply it automatically.

### Gotchas

- **Inactive repos are skipped.** If `sentry_project` points to a repo where `is_active = 0`, the webhook is silently dropped.
- **No fallback repo.** Unlike Slack/Linear, Sentry webhooks do not fall back to the default repo — they require an explicit `sentry_project` mapping.

---

## Drone CI (optional)

**Roles:** CI (build result reporting).

### Setup

1. Add to `ansible/vault/secrets.yml`:

   ```yaml
   drone_url: https://drone.yourcompany.com
   drone_token: ...
   ```

2. Configure a webhook on each Drone-CI repo pointing to `https://{your-domain}/webhooks/ci/drone`
3. Set `ci_system: drone` on repositories that use Drone
4. Re-run Ansible

Yak supports both Drone and GitHub Actions simultaneously — each repo specifies which CI system is authoritative via the `ci_system` field. During a migration from Drone to GitHub Actions, update repos one at a time.

### How It Works

1. RunYakJob pushes `yak/{external_id}`
2. Drone triggers a build on the branch
3. On completion, Drone calls `POST /webhooks/ci/drone`
4. Yak matches the branch name to a task with `status = awaiting_ci`
5. On green, Yak creates the PR. On red, Yak retries once or marks the task failed.

### Gotchas

- **Webhooks from the wrong CI system are ignored.** A GitHub Actions webhook for a repo configured as `drone` is silently dropped.
- **Retries use force push** to the same branch — the PR shows only the final attempt.

---

## Adding A New Channel

Channels are pluggable. Adding a new input source means implementing three interfaces in `app/Contracts/`:

- `InputDriver` — parse an incoming event, return a normalized task description
- `CIDriver` — parse a build result webhook, return pass/fail plus failure output
- `NotificationDriver` — post status updates back to the source

See [development.md](development.md#adding-a-new-channel-driver) for the interface reference and a worked example.
