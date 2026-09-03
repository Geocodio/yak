# Channels

> **Scope.** The channel driver interfaces in this document (InputDriver, NotificationDriver, CIDriver, CIBuildScanner) are task-workflow scoped. Yak's other two workflows — PR Review and Branch Deployments — integrate with GitHub directly rather than going through a channel driver.

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

**Roles:** CI (via Actions), notification (PR bodies and comments), input (follow-up commands on open PRs).

### Setup

The Ansible provisioner creates a GitHub App automatically. You provide `github_org` in vault; Ansible handles app creation, installation, and credential storage.

If you already have a GitHub App and want to reuse it, fill in `github_app_id`, `github_app_private_key`, and `github_webhook_secret` in `ansible/vault/secrets.yml` before running the playbook.

### Permissions

| Permission | Access |
|---|---|
| Contents | Read & Write (push branches) |
| Pull requests | Read & Write (create PRs, add labels) |
| Issues | Read & Write (react to follow-up comments) |
| Checks | Read (CI results) |
| Actions | Read (flaky-test scan reads workflow jobs and their logs) |
| Metadata | Read (default) |

### Webhook Events

The GitHub App subscribes to:

- `check_suite.completed` — CI result processing
- `pull_request.closed` — merge/close tracking (also denormalizes onto `pr_reviews`)
- `pull_request.opened` / `ready_for_review` / `reopened` — triggers a full PR review when `pr_review_enabled` is on
- `pull_request.synchronize` — triggers an incremental PR review
- `issue_comment.created` — `/yak` follow-up comments on an open PR (see [Follow-ups](#follow-ups) below)
- `pull_request_review_comment.created` — `/yak` follow-up replies on an inline review comment (the file, line, and diff hunk are passed to Yak as context)
- `push` / `delete` — refreshes and tears down branch preview deployments
- `repository.renamed` / `repository.transferred` — keeps Yak's record of where the repo lives on GitHub current

Webhook URL: `https://{your-domain}/webhooks/ci/github` for CI; `https://{your-domain}/webhooks/github` for PR review and follow-up events.

> **Subscribing an existing app.** Freshly provisioned apps include these events and permissions via the Ansible manifest. If you reuse a pre-existing GitHub App, add **Issue comments** and **Pull request review comments** to its event subscriptions (or follow-ups won't fire) and bump **Issues** to **Read & Write** (or the 👀 acknowledgement on PR conversation comments will 403). GitHub will prompt installations to re-accept the new permission.

### Repository renames

A repository's `slug` is Yak's internal identity: it is the foreign key for tasks, the base of preview hostnames, the sandbox template alias, and the on-disk clone path. It never changes.

Where the repository lives on GitHub is tracked separately, in `github_repo_id` (immutable) and `github_full_name` (`owner/name`). Inbound webhooks resolve by repo id first, then by GitHub name, then by slug. A `repository.renamed` or `repository.transferred` event updates the GitHub side in place, so nothing else has to move.

If a rename happened while the app was not subscribed to the `repository` event, heal the record after the fact:

```
php artisan yak:sync-github-repo-identity --dry-run   # preview
php artisan yak:sync-github-repo-identity
```

GitHub redirects requests for a repository's old path, so the stale name Yak holds is enough to rediscover the current one.

### Usage

If your repos use GitHub Actions for CI, set `ci_system: github_actions` in the repo definition. Nothing else is required — the GitHub App receives check suite events automatically.

**Important:** the GitHub App must NOT be in your branch protection bypass list and must not have permission to approve reviews. Yak has no merge authority by design.

### Follow-ups

Once Yak has opened a PR, you can keep refining it without a local checkout. Comment on the PR (or reply to an inline review comment) with a `/yak` prefix:

```
/yak also handle the empty-file case
/yak the error message should mention the row number
```

Yak reacts 👀 on the comment to acknowledge receipt, resumes the original Claude session, applies the feedback, and pushes follow-up commits to the **same branch** — then posts a result comment when the push lands. CI re-runs and the existing auto-retry pipeline applies. The follow-up becomes a chained task under the original on the dashboard.

- **Trigger prefixes** are configurable via `YAK_FOLLOWUP_GITHUB_PREFIXES` (default `/yak,@yak-bot[bot],yak:`, case-insensitive). A comment without a prefix is ignored.
- **Bursts are debounced.** Multiple comments within `YAK_FOLLOWUP_GITHUB_BATCH_WINDOW_SECONDS` (default `60`) are collapsed into a single follow-up run, so a flurry of review notes produces one coherent revision rather than racing pushes.
- **Inline review comments** carry their file, line, and surrounding diff hunk into the instruction, so "this variable name is confusing" lands with the context Yak needs.
- **Merged or closed PRs** decline politely and point you at a fresh issue or task — follow-ups only work while the PR is open.

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

**Roles:** Input (task creation via `@yak` mention, follow-ups via thread replies), notification (thread replies).

### Setup

1. Create a Slack app at [api.slack.com/apps](https://api.slack.com/apps)
2. Enable **Event Subscriptions** with request URL `https://{your-domain}/webhooks/slack`
3. Subscribe to bot events:
   - `app_mention`
   - `message.channels` (needed for thread replies — clarification answers and follow-ups)
   - `app_home_opened` (powers the welcome DM the first time a user opens Yak's App Home)
4. Enable the **App Home** tab (under **App Home** in the Slack app config). The tab itself can stay default — Yak uses the open event to DM the user, not to publish a Home view.
5. Enable **Interactivity & Shortcuts** with request URL `https://{your-domain}/webhooks/slack/interactive` — powers click-to-answer buttons on clarification messages.
6. Add bot scopes:
   - `chat:write`
   - `app_mentions:read`
   - `channels:history`
   - `reactions:write` (lets Yak apply status reactions to your @mention)
7. Install the app to your workspace
8. Add the following to `ansible/vault/secrets.yml`:

   ```yaml
   slack_bot_token: xoxb-...
   slack_signing_secret: ...
   slack_workspace_url: https://{your-workspace}.slack.com  # for dashboard → thread deep links
   ```

9. Re-run Ansible

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
- **Start-of-work progress.** When the worker picks a task up, Yak posts a short in-thread message ("Starting on `{repo}` — exploring the codebase now."). Closes the silent gap between ack and first push. Disable with `YAK_EMIT_START_PROGRESS=false` if you find it noisy.
- **Click-to-answer clarification.** When Yak asks a clarification question, each option is rendered as a Block Kit button. Clicking one is equivalent to replying in the thread — it dispatches the same ClarificationReplyJob. Requires Interactivity & Shortcuts to be enabled in the Slack app config (step 5 above).

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

### Follow-ups

After Yak has opened a PR, replying in the same thread keeps the conversation going. A thread reply on a task with an open PR is treated as feedback: Yak resumes the original session, applies your message, and pushes follow-up commits to the same branch — replying in-thread when the push lands. No new mention required; just reply where the PR was announced. This is the same thread-matching that powers clarification answers, so it relies on the `channels:history` scope.

### Gotchas

- **Channels history scope is required** for thread reply matching. Without it, clarification replies and follow-ups cannot be routed to the correct task.
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

Assign any Linear issue to **Yak**. For research-only tasks, either (a) include the word **"research"** anywhere in the issue title (e.g. `Research: audit deprecated field usage` or `[research] memory leak investigation`), or (b) add a **`research`** label to the issue. Label matching is case-insensitive. Use the label when the title naturally reads like a fix ("Replace AWS Inspector…") but the task is actually investigative — it's lower-friction than rewriting the title.

Delegation opens an agent session on the issue. Yak immediately posts an acknowledgement activity, then emits progress updates as it works. When the run finishes:

- **Fix tasks** — Yak posts a `response` activity linking to the pull request and moves the issue to the configured "In review" (CI green, PR opened) or "Done" state.
- **Research tasks** — Yak posts the findings and moves the issue to "Done".
- **Failures** — Yak posts an `error` activity explaining what went wrong; the issue state is left alone.

Follow-up messages inside the agent session are supported. Once a PR is open, commenting in the session is routed as feedback: Yak resumes the original session, applies your message, and pushes follow-up commits to the same branch (see [Follow-ups](#follow-ups-2) below). If the PR has already merged or closed, Yak declines politely and points you at a fresh issue.

#### What you'll see during a run

- **Acknowledgement (sync).** Posted during the webhook response, before the 10-second SLA. Runs through Yak's personality agent with a short timeout, so the voice matches later messages — if the LLM is slow or unreachable, it falls back to a static template but still sounds like Yak.
- **Start-of-work progress.** As soon as the worker picks the task up (often seconds later), Yak posts a `thought` activity describing what it's about to do. Closes the silent gap between pickup and first push on longer tasks. Controlled by `YAK_EMIT_START_PROGRESS` — default on.
- **Push + CI.** Once the agent has changes, Yak pushes to a branch and posts another progress activity noting CI is running.
- **Final response.** On success, a `response` activity with the PR link; on failure, an `error` activity with the reason.
- **Multi-turn replies.** Commenting *inside* the agent session is state-aware: while Yak is `awaiting_clarification` your reply answers the question; once a PR is open it becomes a follow-up that pushes more commits; a `stop` signal cancels the in-flight task; and a merged/closed PR gets a polite decline.

#### Follow-ups

Yak generalizes the clarification engine into full two-way feedback. After the PR is open, post a comment in the agent session with your refinement and Yak resumes the original session, applies it, and pushes to the **same branch** — emitting a `thought` ack on receipt and a `response` when the push lands. CI re-runs through the existing auto-retry pipeline, and the follow-up appears as a chained task under the original on the dashboard. Reassigning the issue away from Yak (an unassignment) cancels any in-flight work.

### Repo Detection

Linear issues follow the standard priority chain:

1. Explicit mention in the issue body: `in my-cli:` or `repo: my-api`.
2. Falls back to the default repo.

Linear projects are not mapped to repos — issues frequently span projects, so a hard mapping is too limiting.

### Issue State Management

Yak manages the Linear issue's workflow state throughout the task lifecycle:

| Event | Issue state |
|---|---|
| Task picked up | → **In Progress / Started** |
| PR created (CI green) | → **In Review** |
| Research completed | → **Done** |
| Task failed | remains In Progress with a failure activity |

The picked-up → started transition is automatic: Yak queries the issue's team's workflow states and moves the issue to the leftmost `started`-type state (workflow states are per-team in Linear, so there is no single workspace-wide UUID). It can be disabled per connection with the "Move issues to In Progress when Yak picks them up" toggle on the Linear settings page, or overridden with a specific state UUID via `linear_started_state_id` (`YAK_LINEAR_STARTED_STATE_ID`) in `ansible/vault/secrets.yml`. If discovery finds no `started`-type state, the transition is skipped and the issue stays in its current state until the PR is opened. The remaining state UUIDs are configured via `linear_done_state_id`, `linear_cancelled_state_id`, and `linear_in_review_state_id`.

### Gotchas

- **Delegation is the trigger.** Yak only acts on the initial `AgentSessionEvent.created` from delegation. Re-assigning an already-Yak issue does not re-trigger.
- **Research mode triggers on either the title or a `research` label.** Label changes made *after* the session is created don't re-route the task (session type is decided at creation time) — but labels present at creation are read from the webhook payload and honoured.
- **Admin install required.** The `app:assignable` OAuth flow requires a workspace admin to approve. Non-admin installs fail at the consent screen.
- **10-second SLA.** Yak posts an acknowledgement activity synchronously during the webhook response to avoid Linear marking the session unresponsive. If the Linear API is slow, that ack may time out — the run still proceeds.

---

## Sentry (optional)

**Roles:** Input (task creation from alert webhooks).

### Setup

1. Create an internal integration at **Settings → Developer Settings → Internal Integrations**
2. Permissions required: **Organization: Read**, **Project: Read**, **Issue & Event: Read**. Organization+Project read are what lets the Add Repository form populate the Sentry project dropdown — skip them and the form silently falls back to a plain slug text input.
3. Set the webhook URL: `https://{your-domain}/webhooks/sentry`
4. Create an issue alert rule whose action notifies this integration. The rule is the opt-in: whichever issues it fires on are the ones Yak considers
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
- **Rejections are logged, not silent.** Every filtered issue writes a `Sentry issue filtered` debug line to the `yak` log channel with its reason and the event's tag keys. Start there when an alert you expected never became a task.
- **Optional per-event opt-in.** Set `YAK_SENTRY_REQUIRED_TAG` (e.g. `yak-eligible`) to additionally require that tag key on the event. Off by default — the tag has to be set in application code at the moment the error is thrown, so the alert rule is usually the better place to decide eligibility.

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
