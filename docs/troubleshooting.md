# Troubleshooting

Symptom-first fixes for the server side of Yak. Start with the health page at `https://{your-domain}/health`, which runs most of the checks below in one place. Components and commands are explained in [Operations](operations.md); how a turn flows is in [Architecture](architecture.md).

Commands assume the Yak container is named `yak`, Redis `yak-redis` and MariaDB `yak-mariadb`.

## First-line diagnostics

```bash
# 1. Health checks
docker exec yak php artisan yak:healthcheck

# 2. Recent application and worker logs (every supervisor program writes to stdout)
docker logs yak --tail 100

# 3. Failed jobs
docker exec yak php artisan queue:failed

# 4. The broker, on the host
systemctl status yak-broker
```

If the health checks are green and the logs are clean, the problem is usually in the external service (the Slack app, the Linear webhook, the GitHub App installation).

## Nothing happens after a message

A thread shows no progress after you send a message. Work through the chain in order.

1. **Is the task or turn waiting?** Look at the header pill. "Waiting for the account" and "Waiting for a sandbox" are covered below.
2. **Is the entry job queued or failing?** `docker exec yak php artisan queue:failed`, and look for `RunYakJob`, `RunFollowUpJob` or `StartRepositoryTurnJob`. Check the `yak-claude` workers run: `docker exec yak ps aux | grep "queue:work"`.
3. **Is the broker up?**

   ```bash
   systemctl status yak-broker
   journalctl -u yak-broker -n 100 --no-pager
   ```

   The broker also writes a heartbeat key. It should exist and have a TTL:

   ```bash
   docker exec yak-redis sh -c 'redis-cli -a "$(sed -n "s/^requirepass //p" /usr/local/etc/redis/redis.conf)" ttl broker:alive'
   ```

   If the broker is down, start it with `systemctl restart yak-broker`. A restarted broker finds each channel again and the thread drivers recover running turns by restarting the runner with the session resumed.
4. **Are the thread drivers running?** The program `yak-thread-drivers` reads the runner's output. If it is down, turns start but never finish and the page shows no output.

   ```bash
   docker exec yak ps aux | grep "yak:thread-drivers"
   docker logs yak --tail 200 | grep -i thread
   ```

   Restarting the container restarts it (`docker restart yak`). One pass for debugging: `docker exec yak php artisan yak:thread-drivers --once`.
5. **Is Redis healthy?** The health page has a Redis row. See [Redis memory is full](#redis-memory-is-full).
6. **Is the runner crash-looping?** Past `YAK_THREADS_RUNNER_RESTART_CAP` restarts (default 3) a turn fails. The broker also refuses more than 5 starts of one channel in 5 minutes (see [The broker refuses to start a runner](#the-broker-refuses-to-start-a-runner)).

Supervisor program names in the container are `php-fpm`, `nginx`, `yak-claude-worker`, `default-worker`, `yak-render-worker`, `yak-deployments-worker`, `yak-poll-worker`, `scheduler`, `yak-thread-drivers`, `yak-credential-socket` and `reverb`.

## A thread stays on "Waiting for the account" or "Waiting for a sandbox"

**Waiting for the account.** The thread's Claude account is running its maximum number of turns (`max_concurrent_turns`, default 16). Yak starts the turn when a slot frees. If the wait never ends, a slot may be held by a dead worker. `yak:reap-account-slots` runs every minute and frees them. Run it by hand to check:

```bash
docker exec yak php artisan yak:reap-account-slots
```

**Waiting for a sandbox.** All awake sandboxes are in use (`YAK_THREADS_AWAKE_CAP`, default 8), the person has reached their share (`YAK_THREADS_PERSON_AWAKE_CAP`, default 4), or host memory is below `YAK_THREADS_MEMORY_FLOOR_GB` (default 8). Yak stops a paused sandbox to make room once it has been idle for `YAK_THREADS_EVICTION_GRACE_MINUTES` (default 10). If every awake sandbox is busy, the thread waits. Check the host:

```bash
incus list
free -g
```

`yak:thread-sandboxes` runs every minute and releases waiting threads when room appears. If the pool is full of stale sandboxes, stop them from the Environment panel or raise the caps.

## "Token problem"

The pill means the thread's Claude account was rejected, has expired, or (rarely) a credential was exposed. The thread page says which. Only the account's owner can replace the secret; see [Invalid and expired tokens](claude-accounts.md#invalid-and-expired-tokens). Yak never switches the thread to another account.

On the server, `AccountFailureClassifier` reads the runner's result lines and sets the block reason (`account_invalid`, `account_expired`, `usage_limit`). The thread continues on its own after the secret is replaced.

"A credential was exposed" means the broker refused to start the runner because the mounted Claude credentials file holds a login that is not an allowed shared account. See [the broker refuses to start a runner](#the-broker-refuses-to-start-a-runner).

## Usage limit

The thread shows "Usage limit reached" with a reset time. Claude's own limit for the account was hit. The thread resumes when the limit resets, and messages sent meanwhile are read after the reset. When Claude gives no reset time, Yak retries after `YAK_THREADS_USAGE_LIMIT_FALLBACK_MINUTES` (default 60). Nothing to fix on the server. See [Usage limits](claude-accounts.md#usage-limits).

## "Rebuilding" fails at a step

Yak destroys a stopped sandbox after `YAK_THREADS_STOP_TO_DESTROY_HOURS` (default 72) and rebuilds it on the next message. The Environment panel shows "Rebuilding, step N of 7". A failure shows "Rebuild stopped at step N" and the pill reads "Rebuild failed". The steps are:

1. Create the sandbox (an `incus copy` of the repository template)
2. Check out the branch
3. Run the setup script
4. Run refresh triggers
5. Set up extra repositories
6. Restore session files
7. Prepare the environment

Common causes by step:

- **Create the sandbox.** The repository has no template snapshot (setup never finished), or the host disk is full. Check the health page rows Sandbox Base Template and Disk Space, and `incus list`.
- **Check out the branch.** The branch was deleted or force-pushed on GitHub, or the GitHub App lost access.
- **Run the setup script / refresh triggers.** The commands in `.yak/` fail. The panel shows the exit code and the last output lines. Fix the repo, then retry.
- **Restore session files.** The session store key is missing or changed (`YAK_SESSION_STORE_KEY`), or stored segments are damaged. Decryption errors in `docker logs yak` point to the key.

After the fix, press **Retry from this step** on the thread. The thread's message is saved and Claude reads it once the sandbox is back.

## A turn was stopped by the guard

The pill reads "Stopped" and the thread says "Claude stopped after N steps" or "Claude stopped after N minutes". `RunawayGuard` ends a turn at `YAK_MAX_TURNS` assistant turns (default 300) or `YAK_THREADS_TURN_WALL_CLOCK_MINUTES` minutes (default 240). The turn is `stopped_by_guard` and no push or PR work runs for it. Press **Continue** to start a new turn with fresh limits. If it keeps happening, the prompt is probably looping; read the thread's last events. Raise the limits in the container env only for work that really needs them. See [Threads](threads.md).

## The thread page does not update live

The page updates through Reverb. Check, in order:

1. **Is Reverb running?** `docker exec yak ps aux | grep reverb` and `docker logs yak --tail 100 | grep -i reverb`.
2. **Does Caddy route it?** Requests to `/app/*` and `/apps/*` must reach `localhost:8080`. In the browser's network tab, look for a websocket on `/app/...` that stays open.
3. **Are the Reverb values set?** `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` come from the vault. The browser reads `VITE_REVERB_APP_KEY`, which is baked into the frontend build. If it is empty, there is no socket by design and the page polls every 3 seconds while the tab is visible. That fallback works but is slower and heavier.
4. **A stale tab.** After a reconnect the page fetches missed events over HTTP. Reload the tab if it still shows an offline state.

If events appear on reload but never live, the socket is the problem. If they never appear at all, see [Nothing happens after a message](#nothing-happens-after-a-message).

## The broker refuses to start a runner

The thread seems to start and then nothing runs. The broker wrote a `broker.start_refused` line. Find the reason in the journal:

```bash
journalctl -u yak-broker --no-pager | grep "start refused"
```

| Reason | Meaning | Fix |
|---|---|---|
| `credential_exposure` | The mounted `.credentials.json` holds a Claude login whose refresh token is not in `YAK_BROKER_SHARED_CLAUDE_REFRESH_SHA256`, or the file cannot be read or parsed. The broker fails closed. A missing file, or one without a `claudeAiOauth` entry, is allowed. | Remove the personal login from the mounted Claude config directory, or add the digest of an intended shared account to `/etc/yak/broker.env` and restart the broker |
| `restart_cap` | More than 5 starts of one channel in 5 minutes. A runner that exits at once is looping. | Read the runner's exit in the journal (`broker.runner_exited` with an `exit_code`), fix the cause (a broken sandbox image), and wait 5 minutes or restart the broker |

No process is started in either case.

## Redis memory is full

Redis runs with `maxmemory-policy noeviction`. When it reaches `maxmemory` (1gb by default, `redis_maxmemory` in Ansible) it refuses writes instead of dropping keys, so queued jobs are safe but new jobs and stream writes fail. Symptoms: `OOM command not allowed` in `docker logs yak`, thread output stops, jobs are not queued.

```bash
docker exec yak-redis sh -c 'redis-cli -a "$(sed -n "s/^requirepass //p" /usr/local/etc/redis/redis.conf)" info memory | grep -E "used_memory_human|maxmemory_human"'
```

Common causes: a large backlog of unprocessed jobs (workers down), or a thread channel whose `:out` stream is not being read because `yak-thread-drivers` is down. Fix the stalled consumer first so streams trim, then raise `redis_maxmemory` and re-run Ansible if the working set is simply larger. Do not flush Redis: it holds the queue.

## A preview will not wake

**Thread preview.** The panel states the reason. The usual ones:

- "Please add a .yak/preview.yml": the primary repository has no manifest. See [Repo config](repo-config.md).
- Waiting for a free slot: the sandbox cannot wake because of the capacity limits above.
- The app did not answer: the dev server started but the health probe failed within `YAK_THREADS_PREVIEW_HOLD_SECONDS` plus the manifest's timeouts. Open the Terminal panel and start the dev server by hand.
- Cannot be restored: the sandbox was destroyed and the rebuild failed (see [Rebuilding fails](#rebuilding-fails-at-a-step)).
- The thread is closed or on your computer: Yak never wakes a sandbox for a preview in those cases.

**Branch deployment.** See [Branch deployments](#branch-deployments) below.

## Terminal or `yak ssh` is refused

User-visible messages are in [SSH and terminal](ssh.md#errors). On the host, check the three units and the firewall:

```bash
systemctl status yak-jump-sshd yak-wake yak-termbridge
journalctl -u yak-jump-sshd -n 50 --no-pager
journalctl -u yak-wake -n 50 --no-pager
journalctl -u yak-termbridge -n 50 --no-pager
ufw status | grep 2222
```

- **Connection refused or times out.** The jump host listens on port 2222 (`yak_ssh_jump_port`). UFW must allow it, and `YAK_SSH_JUMP_HOST` and `YAK_SSH_HOST` must name the host.
- **Key not accepted.** sshd asks the app which sandbox a key may reach through `yak-ssh-lookup`. If the app rejects its calls, `YAK_SSH_INTERNAL_TOKEN` (vault `yak_ssh_internal_token`) does not match the token files in `/etc/yak/`. Re-run Ansible.
- **Sandbox does not wake.** `yak-wake` wakes a stopped sandbox and waits `YAK_WAKE_TIMEOUT` (90 s). Check that sandbox in `incus list` and the wake journal. A destroyed sandbox cannot be woken; send a message in the thread to rebuild it.
- **Browser terminal fails.** `yak-termbridge` listens on 127.0.0.1:8090 and checks the websocket origin against `YAK_TERMBRIDGE_ORIGINS` (your domain).
- **Stale keys.** `docker exec yak php artisan yak:ssh-sweep` refreshes cached GitHub keys and drops expired terminal keys.

## Teleport problems on the server side

CLI messages are in [Teleport](teleport.md#troubleshooting). On the server:

- **Stuck in "waiting for turn".** A turn on the thread has to end first. Check the thread; a stuck turn is covered under [A task is stuck in running](#a-task-is-stuck-in-running).
- **Expired.** Teleports expire after `YAK_TELEPORT_EXPIRY_MINUTES` (default 30). `yak:expire-teleports` runs every minute.
- **Preparing never finishes.** The sandbox could not commit the work-in-progress, push the branch, or sync the session. Look for `PrepareTeleportDownJob` or `PrepareTeleportUpJob` in `docker exec yak php artisan queue:failed` and in `docker logs yak`. A branch the GitHub App cannot push to fails here.
- **Session cannot be restored.** The session store is unreadable. Check `YAK_SESSION_STORE_KEY` and `YAK_THREADS_SESSION_STORE_PATH`.

## Datasets are not attached

A thread or preview sandbox starts without its dataset when the repository has none fetched, or when the clone fails. The repository settings and the thread's environment note show the state: not declared, not on this server, fetching, failed, ready.

```bash
docker exec yak php artisan yak:dataset-fetch {slug}
```

The argument is the repository's slug. The command runs inline for as long as the sync takes (`YAK_DATASETS_FETCH_TIMEOUT_MINUTES`, default 720). It needs `YAK_DATASETS_S3_ENDPOINT`, `YAK_DATASETS_S3_BUCKET`, `YAK_DATASETS_S3_ACCESS_KEY` and `YAK_DATASETS_S3_SECRET` set in the container env, and the `aws` CLI (`YAK_DATASETS_CLI`). If the dataset is ready but a sandbox lacks it, a clone failure was logged: search `docker logs yak` for "Dataset clone failed". Only the primary repository's dataset is attached. `yak:dataset-sweep` (hourly) releases clones of vanished containers.

## Push notifications are not arriving

Push needs VAPID keys in the container env: `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY`. Generate a pair with `docker exec yak php artisan webpush:vapid`. Without keys the notifications page offers nothing. Then check:

- The device is listed on the notifications settings page and not paused. Use "Send a test" there.
- The event's toggle is on for that person.
- Yak skips a push while the person has the thread open, and for threads they cannot see (private threads).
- On iOS, push works only for the installed web app (Add to Home Screen, iOS 16.4 or later).
- A failing subscription keeps its last error on the device row (`push_subscriptions` via `PushDevice.last_error`). Expired subscriptions are dropped.

See [Notifications](notifications.md).

## PR review not posting

Symptoms: a PR opened on a repo with PR review enabled but no Yak review appears.

1. **Webhook reaching Yak?** Check delivery in the GitHub App settings. `pull_request.opened` and `pull_request.synchronize` must be subscribed.
2. **Repo active and enabled?** Open `/repos/{id}/edit` and confirm **Active** and **PR Review** are on.
3. **Draft or authored by yak-bot?** Both are skipped on purpose. Mark the draft ready for review.
4. **Task failed?** Look in `/tasks?tab=reviews` for a failed row. A review is a one-shot run, so failures are a sandbox checkout failure (the PR head was force-pushed or the branch deleted), an account problem (see [Token problem](#token-problem)), or Claude output without a valid JSON block, which lands in the task's `result_summary`.
5. **Path filters too aggressive?** A PR that touches only excluded paths is filtered. Check `pr_review_path_excludes` on the repo.

Use **Re-run review** on the task page to retry. See [PR review](pr-review.md).

## A task is stuck in `running`

A task shows `running` for much longer than usual. The runner is a long-lived process, so look at the turn, not at a queue timeout.

```bash
docker exec yak ps aux | grep -E "queue:work|yak:thread-drivers"
docker logs yak --tail 200 | grep -i error
systemctl status yak-broker
```

Likely causes:

1. **The broker or the thread drivers were down.** Restart them as described in [Nothing happens after a message](#nothing-happens-after-a-message). Running turns are recovered.
2. **The turn is waiting on an account or sandbox.** See the two waiting sections above.
3. **A long background task or a hung tool call.** `TurnTracker` will not end a turn while a tool call is open or a background task runs. The runaway guard stops it at `YAK_THREADS_TURN_WALL_CLOCK_MINUTES`.
4. **Orphaned task.** `yak:reap-orphaned-tasks` runs every 5 minutes and fails a `running` or `retrying` task silent for 15 minutes (`--minutes`). `yak:reap-lost-pending` re-dispatches a `pending` task whose job was lost (10 minutes). After a deploy, `yak:resume-interrupted-tasks` resumes tasks that were cut off, up to 3 times each.

The task page has a Debug section with the session id, cost, turns and full output. To fail a task by hand:

```bash
docker exec yak php artisan tinker
# >>> App\Models\YakTask::find($id)->update(['status' => 'failed', 'error_log' => 'Manual reset']);
```

A manual reset does not restart anything. Use Retry on the task page, or send a new message in the thread.

## Setup task fails for a repo

Symptoms: a new repo's `setup_status` goes from `running` to `failed`.

Common causes:

- **Missing system dependency** in the Yak sandbox image, such as a specific Node version or `pg_config`.
- **Docker-in-Docker problems.** The repo's `docker-compose.yml` needs privileged mode or specific networking.
- **Private registry auth.** See [Agent environment variables](#agent-environment-variables-are-not-visible) and [Private Docker images](#private-docker-images-fail-to-pull).
- **`CLAUDE.md` missing or misleading.** Claude could not work out the setup commands from the README.

Open the setup task's page (`https://{your-domain}/tasks/{setup_task_id}`), expand **Debug** and read the output. Fix the cause, then:

```bash
docker exec yak php artisan yak:setup-repo {slug}
```

Or press **Re-run setup** on the repo's edit page. If `CLAUDE.md` is the cause, update it in the target repo. See [Repositories](repositories.md#claudemd-the-highest-leverage-config-point).

## Webhooks not arriving

Symptoms: you `@yak` in Slack (or assign a Linear issue, fire a Sentry alert, forward an email) and no task appears.

1. **Is the channel enabled?** A webhook route exists only if the channel's credentials are in the vault and Ansible ran after they were added.

   ```bash
   docker exec yak php artisan route:list | grep webhooks
   ```

2. **Can the service reach your server?**

   ```bash
   curl -I https://{your-domain}/webhooks/slack
   # Expect 200 or 401, not 404 or connection refused
   ```

3. **Caddy logs** show the incoming request: `docker exec yak tail -f /var/log/caddy/access.log`.
4. **Signing secrets match.** A mismatch is rejected with 401. The Webhook Signatures health row lists rejecting channels.
5. **Port 443 open.** `ssh root@{server} ufw status`.

Per-channel gotchas:

- **Slack.** The `channels:history` scope is needed to match thread replies to tasks.
- **Linear.** Subscribe the webhook to **Agent session events** and keep the OAuth connection active. If `linear_oauth_connections.installer_user_id` is null, re-authorize from Yak's settings. A non-admin install looks successful but delivers no agent session events.
- **Sentry.** The issue must reach the webhook (an issue alert rule with the Yak integration as action, which needs **Alert Rule Action** on) and clear the filters (not a CSP violation, not a transient infra error). Each rejection is logged to the `yak` channel with its reason. If `YAK_SENTRY_REQUIRED_TAG` is set, the event needs that tag.
- **GitHub.** The App must be installed on the org and subscribed to `check_suite.completed` and `pull_request.closed`.

## Claude errors

There is no host-side `claude` login to fix. Each thread runs on a Claude account that a person added; see [Claude accounts](claude-accounts.md).

- **Token problem or usage limit on a thread.** See the sections above.
- **No usable account.** A task that cannot find an account for the repository fails with a message saying so. Add an account, or set the repository's unattended account.
- **Health row "Claude accounts".** Green when at least one Claude account is usable, the same test thread turns use to pick an account. When it is red, add an account or fix the token of an existing one under Settings > Claude accounts.

MCP servers: `docker exec yak cat /home/yak/mcp-config.json` shows what is configured. A missing server means its channel is not enabled. For a configured one that cannot connect, check container network access and the credentials (`GITHUB_PAT`, `LINEAR_API_KEY`, `SENTRY_AUTH_TOKEN`).

## CI integration issues

### PRs not being created

Symptoms: a task is `awaiting_ci` and never advances although CI ran.

1. **CI result not reaching Yak.** For GitHub Actions, look for requests to `/webhooks/ci/github` in the Caddy log. For Drone, results are polled by `yak:poll-drone-ci` every minute.
2. **Wrong CI system.** The repo's `ci_system` must match its authoritative CI. A GitHub webhook for a repo set to `drone` is dropped.
3. **Branch name mismatch.** The task's `branch_name` must match what was pushed (see Debug on the task page).
4. **GitHub App permissions.** The App needs `Checks: Read` and `Pull requests: Read & Write`.

### CI timed out while runs were queued

`yak:timeout-ci` fails a task in `awaiting_ci` after `YAK_CI_TIMEOUT_MINUTES` (default 30). For GitHub Actions repos it first asks GitHub whether the newest commit on the branch still has a queued or running workflow. If so, the task keeps waiting and logs "CI still running on GitHub, waiting" once. The wait ends after `YAK_CI_MAX_WAIT_MINUTES` (default 180), counted from the last agent push, and the task fails as a CI timeout. If GitHub cannot be reached, or the repo uses Drone, the plain timeout applies.

A CI result that arrives after a timeout is still processed when it is for the branch's current commit and no PR exists: the task returns to `awaiting_ci`, green opens the PR, red follows the retry path. Retry on a task that failed with a CI timeout goes back to CI instead of re-running the agent: green opens the PR, red re-runs the failed jobs, and a running workflow is waited for. If GitHub cannot be reached, Retry falls back to a full agent retry.

### CI keeps failing on the same issue

Retries are capped (`YAK_MAX_ATTEMPTS`, default 2 attempts). After that the task is `failed` and a human takes over. If a repo does this repeatedly, its `CLAUDE.md` probably needs a rule for the class of mistake, or the system prompt needs tuning (see [Prompting](prompting.md#customizing-the-system-prompt)).

## High costs

Open `https://{your-domain}/costs` for daily totals, the per-source breakdown and the 30-day trend.

- **Routing layer spikes.** The usual cause is a Sentry alert storm (tighten the alert rule), an over-mentioned Slack bot, or webhook retries. Duplicate tasks are blocked by `UNIQUE(external_id, repo)`, but not the routing analysis.
- **Implementation.** Thread turns run on Claude accounts, so Claude's own plan limits apply, not a per-task dollar budget. The page shows usage for monitoring.
- **Daily budget.** `EnsureDailyBudget` fails new entry, setup and review jobs with "Daily budget of $X exceeded" once the day's total reaches `YAK_DAILY_BUDGET_USD` (default 50). Raise it:

  ```yaml
  # ansible/vault/secrets.yml, in yak_extra_env, or the Yak container env
  YAK_DAILY_BUDGET_USD: "100"
  ```

  Then re-run Ansible or restart the container.

## Health check failures

The `/health` page and the scheduled `yak:healthcheck` (every 15 minutes) run these checks:

| Check | What it tests | If failing |
|---|---|---|
| Queue Worker | `pgrep` finds an `artisan queue:work` process | Supervisor crash: `docker restart yak` |
| Redis | Redis answers a ping | Check the `yak-redis` container and its password; see [Redis memory is full](#redis-memory-is-full) |
| Last Task Completed | Time of the last task that ended | Informational: no traffic, or stuck work |
| Video Render | Failed renders in the last 24 h | See [Walkthrough videos](#walkthrough-videos-are-raw-webm-instead-of-the-rendered-cut) |
| Voiceover | ElevenLabs key and last result | See [The walkthrough has no narration](#the-walkthrough-has-no-narration) |
| Incus Daemon | `incus list` works from the container | The Incus socket is not mounted, or the worker user is not in `incus-admin` |
| Disk Space | Usage of `/`: warns at 85%, errors at 95% | Prune old images (`docker image prune -f`) and old sandboxes |
| Sandbox Base Template | `yak-base` has a `ready` snapshot | Re-run the `incus` Ansible role |
| Claude CLI | `claude --version` runs | Rebuild the image |
| Claude Max Session | A one-line probe on the container's Claude login | Re-authenticate with `yak-claude-login` on the host |
| Repositories Reachable | Each active repo answers the GitHub API | GitHub App installation or permissions |
| Webhook Signatures | Count of rejected webhooks per channel | A signing secret does not match |
| Telemetry | Telemetry is enabled and recording | `YAK_TELEMETRY_ENABLED` is false, so Analytics has no data |
| One row per enabled channel | The channel's API answers with its credentials | The credential is rejected (401), lacks a scope (403) or the service is down |

A check alerts on Slack only after it failed on two consecutive runs, at most once per 24 hours, and a recovery message follows. If Slack is not enabled, watch `/health` or monitor it externally.

## Agent environment variables are not visible

Symptoms: the agent cannot find a token the repo needs (for example `npm install` returns 401 on a private registry).

A sandbox has no access to the app's environment. Only variables listed in `agent_extra_env` are pushed in. Add the token in the vault:

```yaml
agent_extra_env:
  NODE_AUTH_TOKEN: "ghp_..."
```

Redeploy and re-run the repo's setup so the template picks it up. Existing thread sandboxes keep their old environment until they are rebuilt. To check a running sandbox:

```bash
incus exec yak-thread-<id> -- printenv NODE_AUTH_TOKEN
```

## Private Docker images fail to pull

Symptoms: setup or a turn fails with `docker pull` errors such as `unauthorized` or `denied: requested access to the resource is denied`.

Sandboxes start with no Docker authentication. Add credentials to `docker_registries` in the vault:

```yaml
docker_registries:
  ghcr.io:
    username: "your-github-username"
    password: "ghp_..."            # PAT with `read:packages` scope
  registry.example.com:
    username: "deploy"
    password: "..."
```

Redeploy. Ansible renders them into `~/.docker/config.json` on the host, the Yak container mounts it, and `IncusSandboxManager` pushes it to `/home/yak/.docker/config.json` in each sandbox (mode `0600`, owner `yak`). Re-run the repo's setup so the template caches the images. To check:

```bash
incus exec yak-thread-<id> -- cat /home/yak/.docker/config.json
```

## Emergency: restart everything

```bash
docker restart yak

# Verify
docker exec yak php artisan yak:healthcheck
```

Queued jobs are in Redis, which has its own container and a persistent volume, and MariaDB is separate too, so neither is lost on a restart. Running turns are recovered: the thread drivers restart a lost runner with the session resumed, and after a deploy `yak:resume-interrupted-tasks` resumes cut-off tasks. A task that does not recover is failed by `yak:reap-orphaned-tasks` and can be retried. The broker is a host service: restart it separately with `systemctl restart yak-broker`.

## MariaDB issues

**Container not starting.** `docker logs yak-mariadb --tail 50`. Common causes: data directory permissions, port 3306 in use, a damaged InnoDB tablespace.

**Connection refused from Yak.** Both containers must be on the `yak` Docker network: `docker network inspect yak`. If not, re-run Ansible.

**Resetting the database** (destroys all data):

```bash
docker stop yak-mariadb
rm -rf /home/yak/mariadb-data/*
docker start yak-mariadb
# Wait for init, then migrate
docker exec yak php artisan migrate --force
```

## Branch deployments

### A PR did not get a preview URL

Check in order:

1. Is `deployments_enabled` true on the repository? If not, the webhook is a no-op.
2. Did the deployment fail? Look at the `branch_deployments` row: `status = failed` and `failure_reason`.
3. Did setup finish for this repo? With `current_template_version = 0` there is no snapshot to clone. Run setup first.
4. Are the `yak-deployments` workers running? `docker exec yak ps aux | grep yak-deployments`.

### The preview shows a loading shim that never resolves

- Open the deployment's detail page for the failure reason and the phase that stalled.
- On the host, `incus list` shows whether the container is `RUNNING` or `STOPPED`.
- Read the container's logs: `incus exec <container> -- journalctl -xe`, or `docker compose logs` inside it.

### Template snapshots accumulate on disk

Check that the hourly `deployments:gc-template-snapshots` job runs (`docker exec yak php artisan schedule:list`) and look for warnings in `storage/logs/laravel.log`. To delete a version by hand: `incus snapshot delete yak-tpl-<repo>/ready-v<n>`, only if no `branch_deployments` row has `template_version = n`.

### "At capacity" on a wake

Every running deployment was active in the last 5 minutes, so eviction was refused. Wait, raise `YAK_DEPLOYMENTS_RUNNING_CAP` (default 6), or `incus stop` one.

If deployments exist in the database whose container is gone, `docker exec yak php artisan yak:mark-deployments-gone --dry-run` lists them; run it without `--dry-run` to mark them destroyed.

## Collecting diagnostics for a bug report

```bash
docker exec yak git rev-parse HEAD
docker exec yak php artisan yak:healthcheck
docker logs yak --tail 500 > yak.log
journalctl -u yak-broker -n 500 --no-pager > broker.log
```

Also save the affected task's page (`https://{your-domain}/tasks/{id}`) as a screenshot or HTML. File the issue at `https://github.com/geocodio/yak/issues/new/choose` with the version, health output, the task id and the channel involved.

## Walkthrough videos are raw webm instead of the rendered cut

The health page's **Video Render** row shows failed renders in the last 24 h. A final failure also notifies the task's channel and rewrites the PR's video line to "_Video walkthrough unavailable_". To inspect, run `php artisan queue:failed` or query `video_metrics` where `status = 'failed'`. Renders stage under `storage/app/private/render` (`YAK_VIDEO_RENDER_STAGING_PATH`), the image bakes Remotion's browser at build time (`npx remotion browser ensure`), and `node_modules/.remotion` and `node_modules/.cache` under `video/` must be owned by `www-data`. After fixing the cause, re-run the renders with `php artisan yak:video:rerender --failed-since=<date>`.

## Walkthrough video did not appear

For a task with a `manifest` artifact, `RenderWalkthroughJob` turns the task's `shot`, `voiceover` and `script`/`manifest` artifacts into a `cut`. If the PR body still shows the placeholder in the `<!-- yak:walkthrough -->` section, or the dashboard shows no video, check:

1. **The health row.** **Video Render** shows failed renders in the last 24 h.
2. **`failed_jobs`.** `php artisan queue:failed` lists any `RenderWalkthroughJob` (or `RenderVideoJob` for older tasks) that ran out of retries, with the exception.
3. **`video_metrics`.** Rows with `status = 'failed'` for the task have an `error` column that names the failed step.

Common causes:

- **`timeline.ts` failure.** The script that builds the timeline threw before any frames. Look for a `timeline.ts` stack trace in the job's log.
- **Caption overflow.** A caption was too long for its slide and `WalkthroughV3` refused to render it. Shorten the caption or lengthen the beat.
- **Duration outside bounds.** The timeline fell outside the configured minimum or maximum, usually from an unrealistic wait or an empty shot list.
- **The QA frame test.** `RenderQaCheck` samples frames and fails a render with a blank, garbled or caption-less frame. This catches a shoot that captured a blank page.

To retry, `php artisan yak:video:rerender --task=<id>` re-dispatches the render from the artifacts already on disk. Add `--dry-run` to preview, or `--failed-since=<date>` to sweep every task that failed after a fix.

### The walkthrough has no narration

Check the **Voiceover** row. `Off (no ELEVENLABS_API_KEY)` means narration was never enabled (see [Setup](setup.md)). An error row shows the last ElevenLabs failure: 401 is a bad or expired key, 429 is a quota. A failed narration never blocks the render: the cut goes out captions-only. Look for `VoiceoverGenerator` warnings in the `yak` log channel. A task that already has `voiceover` artifacts is not regenerated, so delete those rows and re-run `yak:video:rerender` to retry.

### `Visual capture: partial` in the task log

The **shoot** step (`yak-browser shoot` in the sandbox) failed to capture some shots or stills. The render did not fail. A partial capture gives a shorter cut or fails the QA test. Read the sandbox's shoot log for the page or navigation error before touching the render pipeline.

## Video theme page preview is blank

If the live preview player never shows on `/settings/video`, the build artifact `public/vendor/video-preview.js` is missing. The page still saves theme changes. If the **Render sample video** link never appears, `RenderThemeSampleJob` failed or has not run: check `php artisan queue:failed` and that the `yak-render` worker is up. See [Video walkthroughs](video-walkthroughs.md#theming).
