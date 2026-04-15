# Troubleshooting

Common problems and how to diagnose them. Start with the health page at `https://{your-domain}/health` — it covers most of what could be wrong in one place.

## First-Line Diagnostics

Before diving into a specific problem, run these three commands. They catch the majority of issues in under a minute.

```bash
# 1. Health check — queue workers, Claude CLI, MCP servers, repo fetchability
docker exec yak php artisan yak:healthcheck

# 2. Recent application logs
docker logs yak --tail 100

# 3. Queue status
docker exec yak php artisan queue:monitor yak-claude,default
```

If the health check is green and logs are clean, the problem is usually in the external service (Slack app, Linear webhook, GitHub App installation) rather than Yak itself.

## Task Stuck In `running`

Symptoms: a task's status on the dashboard is `running` and hasn't moved for several minutes beyond the expected Claude Code duration (typically 2–10 minutes).

### Likely Causes

1. **Queue worker crashed mid-task.** Supervisord will restart the worker, but the task row remains in `running` until a human resets it.
2. **Claude CLI hung on network I/O.** MCP server call, docker-compose bringing up a service, or a very long test suite.
3. **Budget exhausted silently.** The task hit `--max-budget-usd` and the job didn't update status cleanly.

### Diagnosis

```bash
# Is the Claude queue worker actually running?
docker exec yak ps aux | grep "queue:work"

# Any recent errors in the logs?
docker logs yak --tail 200 | grep -i error

# What does the task's own timeline say? Look at its detail page:
#   https://{your-domain}/tasks/{id}
# The Debug section at the bottom has session ID, cost, turns, full Claude output.
```

### Resolution

If the worker is running but the task is stale, it will eventually time out on the 600s `yak-claude` queue timeout. To manually fail a stuck task:

```bash
docker exec yak php artisan tinker
# >>> App\Models\YakTask::find($id)->update(['status' => 'failed', 'error_log' => 'Manual reset']);
```

Manually resetting a task does not restart it. If you want Yak to try again, create a new task with the same description.

## Setup Task Fails For A Repo

Symptoms: adding a new repo via the dashboard or Ansible, and the repo's `setup_status` goes from `running` to `failed`.

### Common Causes

- **Missing system dependencies** — the repo's dev environment needs a tool that isn't in the Yak Docker image (e.g., a specific Node version, or `pg_config`).
- **Docker-in-Docker issues** — the repo's `docker-compose.yml` references services that need privileged mode or specific network configuration.
- **Private package registry auth** — the repo uses a private npm/composer registry and the token isn't configured. See [Agent Environment Variables](#agent-environment-variables-not-visible) below.
- **`CLAUDE.md` missing or misleading** — Claude couldn't figure out the correct setup commands from the README alone.

### Diagnosis

1. Open the setup task's detail page: `https://{your-domain}/tasks/{setup_task_id}`
2. Expand the **Debug** section at the bottom
3. Read the full Claude Code output — it reports exactly what command failed and why

The task detail page shows the Claude session as collapsible CI-style steps. Each turn includes the tool type, a description, duration, and the full terminal output.

### Resolution

Fix the underlying issue, then re-run:

```bash
docker exec yak php artisan yak:setup-repo {slug}
```

Or click **Re-run Setup** on the repo's edit page.

If the issue is `CLAUDE.md` coverage, update the `CLAUDE.md` file in the target repo with the specific commands Yak got wrong. See [Repositories → CLAUDE.md](repositories.md#claudemd--the-highest-leverage-config-point).

## Webhooks Not Arriving

Symptoms: you `@yak` in Slack (or add a Linear label, or trigger a Sentry alert) and nothing happens. No task appears on the dashboard.

### Checklist

1. **Is the channel actually enabled?** The webhook endpoint only exists if the channel's credentials are present in the vault and Ansible has been re-run since they were added.

   ```bash
   docker exec yak php artisan route:list | grep webhooks
   ```

   Only enabled channels appear. If `/webhooks/slack` is missing, Slack is not enabled.

2. **Can the external service reach your server?** Test from the internet:

   ```bash
   curl -I https://{your-domain}/webhooks/slack
   # Expect: 200 (OK) or 401 (signature required), NOT 404 or connection refused
   ```

3. **Check Caddy / nginx logs** for the incoming request:

   ```bash
   docker exec yak tail -f /var/log/caddy/access.log
   ```

4. **Check signing secrets match.** Slack rejects with 401 if `slack_signing_secret` doesn't match the app's signing secret. Linear and Sentry do the same with their respective webhook secrets.

5. **UFW rules** — port 443 must be open for inbound HTTPS:

   ```bash
   ssh root@{server} ufw status
   ```

### Per-Channel Gotchas

- **Slack** — channel history scope is required for thread reply matching. If clarification replies don't route to the right task, verify `channels:history` is in the bot scopes.
- **Linear** — the webhook must subscribe to **Issues** events (not "Issue labels" — that resource type fires for label entity changes, not for labels being applied to issues). Events with `type: "IssueLabel"` are silently dropped.
- **Sentry** — alerts must be tagged `yak-eligible`. Alerts without the tag are ignored even if they hit the webhook.
- **GitHub** — the App must be installed on the target org and must have webhook events for `check_suite.completed` and `pull_request.closed`.

## Claude CLI Errors

### CLI Not Found Or Not Responding

```bash
docker exec yak claude --version
docker exec yak claude -p "Say hello" --output-format json
```

If the first command fails, the CLI isn't installed in the container — rebuild the Docker image. If the second command hangs or errors, the CLI is installed but can't reach Anthropic — check network connectivity.

### Authentication Failures (Token Expired)

Symptoms: tasks fail with `error_log` mentioning auth, 401, or "token expired". The health check posts an alert to Slack.

Claude Code authenticates via an interactive `claude login` session token stored in `/home/yak/.claude/`. When the token expires, every Claude Code job fails gracefully with an auth error — tasks are marked `failed`, a notification goes to the source, and the health check raises an alert.

**Resolution:**

```bash
docker exec -it yak claude login
```

Follow the browser-based OAuth flow. The new session token persists in the mounted volume and takes effect immediately. No restart is needed.

### MCP Server Connection Issues

```bash
docker exec yak cat /home/yak/mcp-config.json
```

This shows which MCP servers are currently configured. If a server you expect is missing, the corresponding channel isn't enabled — re-run Ansible with the channel's credentials set.

If a server is configured but Claude can't reach it, check:

- Network connectivity from the Yak container
- Credentials (`GITHUB_PAT`, `LINEAR_API_KEY`, `SENTRY_AUTH_TOKEN`) are set in the container env
- The MCP server URL is not blocked by any firewall or proxy

## CI Integration Issues

### PRs Not Being Created

Symptoms: a task's status is `awaiting_ci` and never advances even though CI actually ran.

1. **CI result not reaching Yak.** For GitHub Actions, check Caddy/nginx logs for inbound requests to `/webhooks/ci/github`. For Drone, check the scheduler/`default` worker logs — CI results are polled by `yak:poll-drone-ci` every minute, not pushed.
2. **Wrong CI system.** The repo's `ci_system` must match which CI is authoritative for that repo. A GitHub Actions webhook for a repo configured as `drone` is silently dropped.
3. **Branch name mismatch.** The task's `branch_name` must match what was pushed. Look at the task's Debug section for the actual branch name.
4. **GitHub App permissions.** The App needs `Checks: Read` and `Pull requests: Read & Write` to receive check suite events and create PRs.

### CI Keeps Failing On The Same Issue

Retries are capped at one. After two failed attempts, the task is marked `failed` and a human has to take over. If you see this pattern repeatedly for a specific repo:

- The repo's `CLAUDE.md` likely needs a rule that would have prevented the class of mistake
- The Yak system prompt may need tuning for your team's conventions (see [Prompting → Customizing the System Prompt](prompting.md#customizing-the-system-prompt))

## High Costs

Open `https://{your-domain}/costs`. The cost dashboard shows daily totals, per-source breakdown, and the 30-day trend.

### Routing Layer Spike (Haiku/Sonnet)

If routing-layer costs are climbing, the cause is almost always one of:

- **Sentry alert storm** — a noisy alert rule is creating many tasks. Check the `/tasks` page filtered by `source: sentry` for a cluster of similar tasks. Tighten the alert rule in Sentry, or raise the `min_events` threshold in `config/yak.php`.
- **Slack bot being over-mentioned** — a user is pasting long threads that hit `@yak`. Check the task list filtered by `source: slack`.
- **Failed webhook retries** — some services retry webhook delivery on 5xx responses, creating duplicate routing calls. The `UNIQUE(external_id, repo)` constraint deduplicates tasks, but not routing analysis.

### Implementation Layer (Claude Code)

Implementation cost is covered by the Claude Max subscription, not billed per token. The cost dashboard shows Claude Code usage for monitoring but it does not affect your bill.

### Budget Enforcement

The `EnsureDailyBudget` job middleware fails new Claude Code jobs gracefully once the daily routing-layer budget is exceeded. Raise the limit via:

```bash
# In ansible/vault/secrets.yml or the Yak container env:
YAK_DAILY_BUDGET=100
```

Re-run Ansible or restart the container.

## Health Check Failures

The `/health` page (and the scheduled `yak:healthcheck` command) runs these checks every 15 minutes:

| Check | If failing |
|---|---|
| **Queue worker running** | Supervisord crash — `docker restart yak` |
| **Last task completed within N hours** | No traffic, or workers hung on a stuck task |
| **All repos fetchable** | Git auth issue — re-run `yak:refresh-repos` manually, check SSH key |
| **Claude CLI responding** | See [Claude CLI errors](#claude-cli-errors) above |
| **Claude CLI authenticated** | Token expired — run `docker exec -it yak claude login` |
| **Enabled channel MCP servers reachable** | Network issue or external service down |

Failed health checks post to Slack if the Slack channel is enabled. If Slack isn't available, check the health page manually or set up external monitoring against `/health`.

## Agent Environment Variables Not Visible

Symptoms: the agent can't find a token that the repo needs at build time (e.g. `npm install` fails with 401 on a private registry).

### Cause

Each task runs in its own Incus sandbox container. Sandboxes start from the base template snapshot and have no access to the yak app's environment. Only variables explicitly listed in `agent_extra_env` are pushed into the sandbox.

### Resolution

Add the token to `agent_extra_env` in your Ansible vault:

```yaml
agent_extra_env:
  NODE_AUTH_TOKEN: "ghp_..."
```

Redeploy and re-run the affected repo's setup task — the new env vars are baked into the next snapshot.

To verify the var is set inside a running sandbox:

```bash
incus exec task-<id> -- printenv NODE_AUTH_TOKEN
```

## Emergency: Kill Everything And Restart

If Yak is in a bad state and you can't figure out what's wrong:

```bash
# Stop the container gracefully (in-flight tasks finish first)
docker stop yak

# Start it again
docker start yak

# Verify
docker exec yak php artisan yak:healthcheck
```

MariaDB runs as a separate container (`yak-mariadb`) and is unaffected by Yak container restarts. Repo clones and the Claude session token persist via mounted volumes. Nothing is lost.

If supervisord itself is wedged, restart the container outright:

```bash
docker restart yak
```

This is safe — the queues are MariaDB-backed and any in-flight jobs will be retried on the next worker boot (with the caveat that tasks mid-`claude -p` session may be left in `running` and need manual reset per the earlier section).

## MariaDB Issues

### Container Not Starting

```bash
docker logs yak-mariadb --tail 50
```

Common causes: data directory permissions, port 3306 already in use, or corrupted InnoDB tablespace.

### Connection Refused From Yak

Verify both containers are on the same Docker network:

```bash
docker network inspect yak
```

Both `yak` and `yak-mariadb` should appear. If not, re-run Ansible.

### Resetting The Database

```bash
docker stop yak-mariadb
rm -rf /home/yak/mariadb-data/*
docker start yak-mariadb
# Wait for init, then re-run migrations
docker exec yak php artisan migrate --force
```

## Collecting Diagnostics For A Bug Report

When filing an issue, collect:

```bash
# Version
docker exec yak git rev-parse HEAD

# Health check output
docker exec yak php artisan yak:healthcheck

# Recent logs
docker logs yak --tail 500 > yak.log

# The affected task's full dashboard page (screenshot or save as HTML)
# https://{your-domain}/tasks/{id}
```

File at `https://github.com/geocodio/yak/issues/new/choose` — include the version, health output, the task ID, and which channel was involved.
