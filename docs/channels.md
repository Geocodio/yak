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
| Linear | Input, notification | no | Assign issue to Yak |
| Sentry | Input | no | Alert rule |
| Drone CI | CI | no | Polled by `yak:poll-drone-ci` |

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
   - `app_home_opened` (powers the welcome DM the first time a user opens Yak's App Home)
4. Enable the **App Home** tab (under **App Home** in the Slack app config). The tab itself can stay default — Yak uses the open event to DM the user, not to publish a Home view.
5. Add bot scopes:
   - `chat:write`
   - `app_mentions:read`
   - `channels:history`
   - `reactions:write` (lets Yak apply status reactions to your @mention)
6. Install the app to your workspace
7. Add the following to `ansible/vault/secrets.yml`:

   ```yaml
   slack_bot_token: xoxb-...
   slack_signing_secret: ...
   slack_workspace_url: https://{your-workspace}.slack.com  # for dashboard → thread deep links
   ```

8. Re-run Ansible

### Usage

```
@yak fix the broken CSV export
@yak in api: fix the timeout on batch endpoints
@yak research: which endpoints still use the deprecated `accuracy_type` field?
@yak help
```

Yak responds in the same thread with a Block Kit card — personality line, context chips (repo · mode · task id), and action buttons (**View task**, **View PR**).

- **Reactions.** Yak reacts on your original @mention as the task progresses: 👀 when picked up, 🚧 while working, ✅ when a PR is ready, ❌ on failure. You can see status at a glance without opening the thread.
- **`@yak help`.** Sending `@yak`, `@yak help`, or `@yak ?` returns a capabilities card with syntax examples — it does not create a task.
- **First-time intro.** The first time a given user gets a reply from Yak, the acknowledgment has a small *"First time seeing me?"* footer pointing to this doc. It only appears once per user.
- **App Home welcome.** The first time a user opens Yak's App Home tab in Slack, Yak DMs them a welcome card with syntax examples and links. Requires the `app_home_opened` event subscription above.
- **Direct ping on status changes.** When Yak needs clarification, completes the task, fails, or expires, it @-mentions the requester so they get a push. Progress ticks don't ping (avoids noise).

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
- **`reactions:write` must be granted** for status reactions to appear. Without it, reactions silently fail; everything else still works.
- **`app_home_opened` event must be subscribed** for welcome DMs. Enable the App Home tab in the Slack app config even if you never customize it — the event only fires when the tab is enabled.
- **Bot token rotation** requires re-running Ansible to update the container env vars.
- **3-day TTL** — clarifications that aren't answered auto-expire with a "Closing this — mention me again" message.
- **`slack_workspace_url` is optional but recommended.** Without it, the dashboard's "Source: Slack" chip renders as plain text instead of linking back to the originating thread.

---

## Linear (optional)

**Roles:** Input (task delegation via Linear Agents), notification (agent session activities, issue state transitions).

Yak installs into a Linear workspace as an **Agent** — a first-class workspace participant that appears in the assignee picker without consuming a seat. Delegating an issue to Yak opens an **agent session** on the issue; Yak posts its thoughts, actions, and final result as typed activities inside that session.

### Setup

1. **Register the OAuth Application** at Linear → **Settings → API → Applications → New application**.
   - Name: `Yak`
   - Callback URL: `https://{your-domain}/auth/linear/callback`
   - Enable **Webhooks**, set the URL to `https://{your-domain}/webhooks/linear`, and under **App events** tick **Agent session events**. Under **Authorization events**, tick OAuth authorization events if you want to track installs.
   - Copy the app's webhook **signing secret**.
2. Add the following to `ansible/vault/secrets.yml`:

   ```yaml
   linear_oauth_client_id: lin_api_...
   linear_oauth_client_secret: lin_oauth_...
   # Defaults to https://{yak_domain}/auth/linear/callback if omitted.
   linear_oauth_redirect_uri: ""
   linear_webhook_secret: lin_wh_...
   ```

3. Re-run Ansible to push the credentials into the container.
4. **Authorize the app**: sign in to the Yak dashboard → **Settings → Linear → Connect Linear**. Approve the consent screen — it requests scopes `read`, `write`, `app:assignable`, and `app:mentionable`. A workspace admin must approve the install.

Once installed, Yak appears in the Linear assignee picker for every team it belongs to. Team membership is managed inside Linear — an admin adds or removes the Yak agent per team like any other user.

### Usage

Assign any Linear issue to **Yak**. For research-only tasks, include the word **"research"** anywhere in the issue title (e.g. `Research: audit deprecated field usage` or `[research] memory leak investigation`).

Delegation opens an agent session on the issue. Yak immediately posts an acknowledgement activity, then emits progress updates as it works. When the run finishes:

- **Fix tasks** — Yak posts a `response` activity linking to the pull request and moves the issue to the configured "In review" (CI green, PR opened) or "Done" state.
- **Research tasks** — Yak posts the findings and moves the issue to "Done".
- **Failures** — Yak posts an `error` activity explaining what went wrong; the issue state is left alone.

Follow-up messages inside the agent session are not supported — Yak replies with a polite error pointing you to the pull request or a fresh Linear issue for further changes.

### Repo Detection

Linear issues follow the standard priority chain:

1. Explicit mention in the issue body: `in my-cli:` or `repo: my-api`.
2. Falls back to the default repo.

Linear projects are not mapped to repos — issues frequently span projects, so a hard mapping is too limiting.

### Issue State Management

Yak manages the Linear issue's workflow state throughout the task lifecycle:

| Event | Issue state |
|---|---|
| Task picked up | → **In Progress** |
| PR created (CI green) | → **In Review** |
| Research completed | → **Done** |
| Task failed | remains In Progress with a failure activity |

Configure the state UUIDs via `linear_done_state_id`, `linear_cancelled_state_id`, and `linear_in_review_state_id` in `ansible/vault/secrets.yml`.

### Gotchas

- **Delegation is the trigger.** Yak only acts on the initial `AgentSessionEvent.created` from delegation. Re-assigning an already-Yak issue does not re-trigger.
- **The `research` label has no effect.** Research mode is detected from the issue title only — `promptContext` doesn't surface label changes at session creation time.
- **Admin install required.** The `app:assignable` OAuth flow requires a workspace admin to approve. Non-admin installs fail at the consent screen.
- **10-second SLA.** Yak posts an acknowledgement activity synchronously during the webhook response to avoid Linear marking the session unresponsive. If the Linear API is slow, that ack may time out — the run still proceeds.

---

## Sentry (optional)

**Roles:** Input (task creation from alert webhooks).

### Setup

1. Create an internal integration at **Settings → Developer Settings → Internal Integrations**
2. Permissions required: **Organization: Read**, **Project: Read**, **Issue & Event: Read**. Organization+Project read are what lets the Add Repository form populate the Sentry project dropdown — skip them and the form silently falls back to a plain slug text input.
3. Set the webhook URL: `https://{your-domain}/webhooks/sentry`
4. Create an alert rule tagged `yak-eligible` for the issues you want Yak to pick up
5. Map Sentry projects to repositories via the `sentry_project` field on each repo (see the [Repositories](repositories.md) page)
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

2. Set `ci_system: drone` on repositories that use Drone
3. Re-run Ansible

Drone has no outbound webhooks, so Yak polls the Drone API on a schedule (see below). No webhook configuration is required on the Drone side.

Yak supports both Drone and GitHub Actions simultaneously — each repo specifies which CI system is authoritative via the `ci_system` field. During a migration from Drone to GitHub Actions, update repos one at a time.

### How It Works

1. RunYakJob pushes `yak/{external_id}`
2. Drone triggers a build on the branch
3. `yak:poll-drone-ci` runs every minute and calls the Drone API for each task in `awaiting_ci` on a `ci_system=drone` repo
4. When the latest build on the task's branch settles to `success`/`failure`/`error`/`killed`, Yak dispatches `ProcessCIResultJob`
5. On green, Yak creates the PR. On red, Yak retries once or marks the task failed.

### Gotchas

- **Poll cadence.** CI results surface within ~60s of the Drone build settling. Builds still running are skipped until the next tick.
- **Retry race.** After a retry pushes a new commit on the same branch, the poller ignores any Drone build that started before the task re-entered `awaiting_ci` (with a 60s grace period).
- **Retries use force push** to the same branch — the PR shows only the final attempt.

---

## Adding A New Channel

Channels are pluggable. Adding a new input source means implementing three interfaces in `app/Contracts/`:

- `InputDriver` — parse an incoming event, return a normalized task description
- `CIDriver` — parse a build result webhook, return pass/fail plus failure output
- `NotificationDriver` — post status updates back to the source

See [Development → Adding A New Channel Driver](development.md#adding-a-new-channel-driver) for the interface reference and a worked example.
