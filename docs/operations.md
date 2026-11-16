# Operations

This page is the reference for the person who runs Yak: what runs on the host, which queues and scheduled commands keep threads moving, the settings that cap capacity, and how to deploy and back up safely. For a first install see [Setup](setup.md). For problems see [Troubleshooting](troubleshooting.md).

Commands below run on the server. Artisan commands run inside the container as `www-data`:

```bash
docker exec -u www-data yak php artisan <command>
```

## Host Components

A turn travels through these parts: a queue worker in the container starts it, the thread driver writes it to Redis, `yak-broker` runs the runner in the thread's sandbox, the runner calls Claude through the auth proxy, and the driver reads the output back. [Architecture](architecture.md) has the full request flow.

| Component | What it does | Where it runs | Service or program | Logs |
|---|---|---|---|---|
| Yak app | Dashboard, webhooks, API for the `yak` CLI. | Container `yak` (nginx and php-fpm, loopback port 8080 on the host). | Programs `nginx`, `php-fpm` | `docker logs yak` |
| Queue workers | Run jobs: turn starts, finishers, PRs, renders, previews. | Container `yak` | Programs below, see [Queues and workers](#queues-and-workers) | `/home/yak/logs/<program>.log` on the host |
| Scheduler | Runs `schedule:run` once a minute. | Container `yak` | Program `scheduler` | `/home/yak/logs/scheduler.log` |
| Thread drivers | Read runner output from Redis, update turns and threads, publish events, enforce timeouts and the runaway guard. | Container `yak` | Program `yak-thread-drivers` (`yak:thread-drivers`) | `/home/yak/logs/yak-thread-drivers.log`, `yak-thread-drivers-error.log` |
| `yak-broker` | Starts one runner per thread in its sandbox (`incus exec`), copies runner lines to and from Redis, and refuses to start a runner when it finds a Claude login in the shared credentials file. Allows at most 5 starts of one thread in 5 minutes. Protocol: `broker/PROTOCOL.md`. | Host | `systemd` unit `yak-broker`, user `yak-broker` (in `incus-admin`), environment in `/etc/yak/broker.env` | `journalctl -u yak-broker` |
| Auth proxy | Part of `yak-broker`. Accepts requests from sandboxes to Claude's API, swaps the `yakph-` placeholder for the real credential and forwards them. | Host, `10.100.0.1:8787` (`yak_auth_proxy_address`) | Same unit | `journalctl -u yak-broker` |
| Credential socket | The app serves placeholders to the broker over a Unix socket. No credentials are stored in Redis. | Container `yak`, socket `/var/lib/yak/credentials/redeem.sock` (directory mode 2770, group `yak-broker`, mounted at the same path inside the container) | Program `yak-credential-socket` (`yak:credential-socket`) | `/home/yak/logs/yak-credential-socket.log` |
| Runner | The Claude Agent SDK process in each thread sandbox. Reads turns on stdin, writes JSON lines on stdout. Started and killed by the broker. | Thread sandbox | Not a service | Events in the thread's stream, `broker.*` lines in the `:out` stream |
| Redis | Queues, broker streams, queue metrics, telemetry buffers. Append-only file, `noeviction`, `maxmemory` 1gb (`redis_maxmemory`), password required. | Container `yak-redis`, `127.0.0.1:6379` | Docker restart policy | `docker logs yak-redis`. Data in `/var/lib/yak/redis` (mode 0700). |
| Reverb | Websocket server for live thread updates. Caddy routes `/app/*` and `/apps/*` to it. With an empty `REVERB_APP_KEY` the thread page polls over HTTP instead. | Container `yak`, `127.0.0.1:8080` inside the container | Program `reverb` | `/home/yak/logs/reverb.log`, `reverb-error.log` |
| MariaDB | Application database. | Container `yak-mariadb`, data in `/home/yak/mariadb-data` | Docker restart policy | `docker logs yak-mariadb` |
| telemetryd | Keeps metrics, traces and logs for the System page. Reachable only on the Docker network `yak`, port 4319, with the ingest and query tokens. Disk budget `telemetryd_disk_budget` (10GiB). | Container `yak-telemetryd` | Docker restart policy | `docker logs yak-telemetryd` |
| Caddy | TLS and reverse proxy to the container. Wildcard preview routing when DNS-01 is set up. | Host | `systemd` unit `caddy` | `/var/log/caddy/access.log`, `preview-access.log` |
| Incus and the sandbox network | One system container per thread (`yak-thread-<id>`) on the bridge `yak-sandbox` (`10.100.0.1/24`). The ACL `yak-sandbox-acl` lets a sandbox reach the bridge host only on the auth proxy port plus DNS and DHCP, and drops the host's own public addresses and the private ranges. The rest of the internet is open. The Yak container sits on its own Docker network `yak-app` (`172.30.0.0/29`, container at `.2`) so the auth proxy sees a fixed source address. | Host | `incus` | `incus list`, `incus info <name> --show-log` |
| SSH jump host | `sshd` on port 2222 for `ssh -J`. It only forwards TCP to sandbox addresses. Which key may reach which sandbox is decided by `yak-ssh-lookup`, an `AuthorizedKeysCommand` that asks the app. | Host, port 2222 | Unit `yak-jump-sshd`, users `yak-jump` and `yak-ssh-lookup`, config in `/etc/ssh/yak-jump` | `journalctl -u yak-jump-sshd` |
| Wake proxy | Listens per sandbox on a `127.x` address, port 22. A connection to a stopped sandbox wakes it first (`YAK_WAKE_TIMEOUT` 90s, longest session `YAK_WAKE_MAX_SESSION` 12h). | Host | Unit `yak-wake`, runs as `yak-broker` | `journalctl -u yak-wake` |
| Terminal bridge | Serves the browser terminal as a websocket on `127.0.0.1:8090`. Allowed origin: `https://yak.example.com`. | Host | Unit `yak-termbridge`, user `yak-termbridge` | `journalctl -u yak-termbridge` |

The SSH services share a secret, `YAK_SSH_INTERNAL_TOKEN` in the container, which the `yak-ssh` role writes as one token file per service under `/etc/yak/`. An empty token locks every internal SSH route.

The broker, wake proxy and terminal bridge are Go binaries built from `broker/` by the playbook. Environment variables of the broker:

| Variable | Value | Meaning |
|---|---|---|
| `YAK_BROKER_REDIS_ADDR` | `127.0.0.1:6379` | Redis address. |
| `YAK_BROKER_REDIS_PASSWORD` | from `redis_password` | Redis password. |
| `YAK_BROKER_CREDENTIAL_SOCKET` | `/var/lib/yak/credentials/redeem.sock` | Where the broker redeems placeholders. |
| `YAK_BROKER_PROXY_ADDR` | `10.100.0.1:8787` | Auth proxy listen address. |
| `YAK_BROKER_PROXY_HOST_CIDRS` | `172.16.0.0/12` | Source ranges the proxy treats as the Yak container. |

## Queues and Workers

All queues use Redis. The workers run inside the container under Supervisor.

| Queue | Program | Processes | Timeout | Tries | Runs |
|---|---|---|---|---|---|
| `yak-claude` | `yak-claude-worker` | 4 | 3600s | 3 | Turn starts, finishers after a turn, setup and PR review runs. |
| `default` | `default-worker` | 3 | 30s | 3 | Webhooks, PR creation, CI results, notifications, follow-up batches. |
| `yak-render` | `yak-render-worker` | 1 | 900s | 2 | Walkthrough video renders. |
| `yak-deployments` | `yak-deployments-worker` | 2 | 900s | 3 | Branch deployments: build, wake, rebuild, destroy. |
| `yak-poll` | `yak-poll-worker` | 1 | 600s | 1 | Polling GitHub for PR reactions. |

The workers on `yak-claude` only start and finish turns. The turn itself runs in the sandbox, so four workers do not cap the number of live threads. The caps below do.

Queue depth, throughput and worker state show on the System page.

## Scheduled Commands

`schedule:run` runs every minute as `www-data`. The commands are defined in `routes/console.php`.

| Command | Cadence | Purpose |
|---|---|---|
| `yak:thread-sandboxes` | every minute | Sandbox lifecycle: pause woken sandboxes with no turn, stop idle paused ones, destroy old stopped ones, enforce the pool watermark and memory floor, release threads waiting for a sandbox. |
| `yak:reap-account-slots` | every minute | Free account slots and auth proxy placeholders leaked by workers that died mid-run. |
| `yak:deliver-stack-results` | every minute | Deliver sub-thread results to their parent once every child is done or the wait window passed. |
| `yak:expire-teleports` | every minute | Expire teleports with no activity. |
| `yak:poll-drone-ci` | every minute | Poll Drone for CI results. |
| `telemetry:flush` | every minute | Flush buffered telemetry to telemetryd. |
| `deployments:hibernate-idle` | every minute | Hibernate idle branch deployments. |
| `yak:reap-orphaned-tasks` | every 5 minutes | Finish tasks stuck in Running or Retrying whose worker crashed. |
| `yak:reap-lost-pending` | every 5 minutes | Re-dispatch Pending tasks whose queued job vanished. |
| `yak:check-stack-usage` | every 5 minutes | Alert the owner of a stack that crossed its turn, hour or sub-thread mark. |
| `deployments:watchdog-stuck` | every 10 minutes | Recover deployments stuck while starting or destroying. |
| `yak:timeout-ci` | every 15 minutes | Advance or fail tasks stuck awaiting CI past `YAK_CI_TIMEOUT_MINUTES` (30). |
| `yak:healthcheck` | every 15 minutes | Run the health checks and alert Slack on failure. |
| `yak:scan-ci` | every 2 hours | Scan CI logs for flaky tests and create fix tasks. |
| `yak:cleanup` | hourly | Expire unanswered clarifications and remind about open ones. |
| `yak:cleanup-sandboxes` | hourly | Delete leftover sandbox containers from aborted runs. |
| `yak:close-idle-threads` | hourly | Close threads that are idle past `YAK_THREADS_DESTROY_DAYS`, or whose non-PR work ended `YAK_THREADS_TERMINAL_CLOSE_HOURS` ago. Threads with an open PR stay open. |
| `yak:reconcile-pr-state` | hourly | Record merged and closed state of Yak's PRs. |
| `yak:co-owner:sweep` | hourly | Expire and remind co-owner requests. |
| `yak:preview-repair:sweep` | hourly | Remind people about open preview repair PRs. |
| `yak:dataset-sweep` | hourly | Release dataset clones of vanished containers, delete unused old snapshots. |
| `yak:ssh-sweep` | hourly | Refresh cached GitHub keys, drop expired terminal keys. |
| `poll-pr-reactions`, `deployments:sweep-expired`, `deployments:gc-template-snapshots`, `deployments:backfill-open-prs` | hourly | Jobs: PR reactions, expired deployments, template snapshot cleanup, open PR backfill. |
| `yak:prune-session-store` | daily | Delete session files of threads closed longer than `YAK_THREADS_SESSION_RETENTION_DAYS` ago. |
| `yak:claude-accounts-expiry` | daily | Mark expired Claude accounts and warn owners 14 days before a token expires. |
| `yak:video:prune`, `yak:observations:prune`, `yak:telemetry:prune` | daily | Delete old video artifacts, observations and telemetry events. |
| `model:prune` (device authorizations) | daily | Delete expired `yak login` device codes. |

`yak:prune-thread-events` exists but is not scheduled.

## Artisan Commands for Operators

| Command | When to use it | Example |
|---|---|---|
| `yak:healthcheck` | Run every health check now and print the result. | `php artisan yak:healthcheck` |
| `yak:drain {--wait=300} {--max-wait=2700} {--poll=5}` | Before recreating the container by hand. The playbook runs it for you. See [Deploys and draining](#deploys-and-draining). | `php artisan yak:drain --max-wait=600` |
| `yak:resume` | Clear the drain flag so workers pick up jobs again. | `php artisan yak:resume` |
| `yak:resume-interrupted-tasks {--max-resumes=3}` | Requeue tasks a drain force-failed. A task is resumed at most `--max-resumes` times. | `php artisan yak:resume-interrupted-tasks` |
| `yak:dataset-fetch {repository} {--dataset-version=}` | Fetch a repository's dataset now, instead of waiting for a thread to need it. | `php artisan yak:dataset-fetch acme-storefront` |
| `yak:setup-repo {slug}` | Re-run a repository's setup task. | `php artisan yak:setup-repo acme-storefront` |
| `yak:thread-sandboxes` | Run the sandbox lifecycle now, for example after raising a cap. | `php artisan yak:thread-sandboxes` |
| `yak:close-idle-threads`, `yak:reap-account-slots`, `yak:ssh-sweep`, `yak:prune-session-store` | Run a scheduled sweep by hand. | `php artisan yak:reap-account-slots` |
| `yak:thread-drivers {--once}` | The supervised consumer loop. Run `--once` to do one read and exit while debugging. Do not start a second loop next to the supervised one. | `php artisan yak:thread-drivers --once` |
| `yak:credential-socket` | The supervised socket server. Not run by hand. | |
| `yak:risk-profile {slug} {--import=} {--approve=} {--reviewer=}` | Draft a repository's risk profile, or activate a draft a person reviewed. | `php artisan yak:risk-profile acme-storefront` |
| `yak:video:rerender {--task=} {--failed-since=} {--dry-run}` | Re-dispatch video renders that never finished. | `php artisan yak:video:rerender --dry-run` |
| `yak:telemetry:export {table=runs} {--since=} {--until=} {--format=csv} {--output=}` | Export task runs or events. | `php artisan yak:telemetry:export runs --output=runs.csv` |

The one-time upgrade commands are on [Upgrading to threads](upgrading.md).

## Capacity and Lifecycle Settings

Set any of these in `yak_extra_env` in the vault (see [Setup](setup.md#other-settings)). The config key is under `config/yak.php`.

### Sandboxes

| Setting | Default | Env var | Config key |
|---|---|---|---|
| Sandboxes awake at once, all threads | 8 | `YAK_THREADS_AWAKE_CAP` | `threads.awake_cap` |
| Sandboxes awake at once, per person | 4 | `YAK_THREADS_PERSON_AWAKE_CAP` | `threads.person_awake_cap` |
| Free host memory below which idle sandboxes are stopped | 8 GB | `YAK_THREADS_MEMORY_FLOOR_GB` | `threads.memory_floor_gb` |
| Storage pool use above which stopped sandboxes are destroyed | 80% | `YAK_THREADS_POOL_WATERMARK_PERCENT` | `threads.pool_watermark_percent` |
| Minutes a paused sandbox must be idle before it can be evicted for capacity | 10 | `YAK_THREADS_EVICTION_GRACE_MINUTES` | `threads.eviction_grace_minutes` |
| Minutes a woken sandbox may sit without a turn before it is paused | 5 | `YAK_THREADS_WAKE_PAUSE_GRACE_MINUTES` | `threads.wake_pause_grace_minutes` |
| Minutes from paused to stopped | 30 | `YAK_THREADS_PAUSE_TO_STOP_MINUTES` | `threads.pause_to_stop_minutes` |
| Hours from stopped to destroyed | 72 | `YAK_THREADS_STOP_TO_DESTROY_HOURS` | `threads.stop_to_destroy_hours` |
| Longest a thread may ask to keep its stopped sandbox (hours) | 168 | `YAK_THREADS_MAX_DESTROY_AFTER_HOURS` | `threads.max_destroy_after_hours` |
| Days without activity before an open thread is closed | 30 | `YAK_THREADS_DESTROY_DAYS` | `threads.destroy_days` |
| Hours after the last task ends before a thread with no PR and no branch is closed | 24 | `YAK_THREADS_TERMINAL_CLOSE_HOURS` | `threads.terminal_close_hours` |
| Seconds a sandbox gets to stop cleanly | 60 | `YAK_THREADS_STOP_TIMEOUT_SECONDS` | `threads.stop_timeout_seconds` |
| CPU cores per sandbox | 4 | `YAK_SANDBOX_CPU_LIMIT` | `sandbox.cpu_limit` |
| Memory per sandbox | 8GB | `YAK_SANDBOX_MEMORY_LIMIT` | `sandbox.memory_limit` |
| Disk per sandbox | 30GB | `YAK_SANDBOX_DISK_LIMIT` | `sandbox.disk_limit` |

When a thread needs a sandbox and a cap is reached, Yak stops the oldest paused, idle sandbox. If none can be stopped, the thread waits and shows that it is waiting for a sandbox. `yak:thread-sandboxes` releases it when a slot frees up.

### Turns, accounts and stacks

| Setting | Default | Env var | Config key |
|---|---|---|---|
| Concurrent turns per Claude account | 16 (set per account, 1 to 64) | Settings > Claude accounts | none |
| Runaway guard: assistant steps in one turn | 300 | `YAK_MAX_TURNS` | `max_turns` |
| Runaway guard: wall clock per turn, minutes | 240 | `YAK_THREADS_TURN_WALL_CLOCK_MINUTES` | `threads.turn_wall_clock_minutes` |
| Daily spend ceiling in USD for new work | 50 | `YAK_DAILY_BUDGET_USD` | `daily_budget_usd` |
| Minutes to wait for an account's usage limit to reset when Anthropic gives no time | 60 | `YAK_THREADS_USAGE_LIMIT_FALLBACK_MINUTES` | `threads.usage_limit_fallback_minutes` |
| Runner restarts after a crash before the turn fails | 3 | `YAK_THREADS_RUNNER_RESTART_CAP` | `threads.runner_restart_cap` |
| Sub-threads awake at once under one parent | 3 | `YAK_THREADS_CHILD_AWAKE_LIMIT` | `threads.child_awake_limit` |
| Stack alert: turns | 50 | `YAK_THREADS_STACK_ALERT_TURNS` | `threads.stack_alert_turns` |
| Stack alert: hours | 3 | `YAK_THREADS_STACK_ALERT_HOURS` | `threads.stack_alert_hours` |
| Stack alert: sub-threads | 12 | `YAK_THREADS_STACK_ALERT_CHILDREN` | `threads.stack_alert_children` |
| Minutes a parent waits for its children's results | 10 | `YAK_THREADS_CHILD_RESULT_WAIT_MINUTES` | `threads.child_result_wait_minutes` |

How the runaway guard stops a turn is described in [Threads](threads.md). Account behavior is in [Claude accounts](claude-accounts.md).

### Teleport, sessions and previews

| Setting | Default | Env var | Config key |
|---|---|---|---|
| Minutes without activity before a teleport expires | 30 | `YAK_TELEPORT_EXPIRY_MINUTES` | `teleport.expiry_minutes` |
| Days a closed thread's session files are kept | 30 | `YAK_THREADS_SESSION_RETENTION_DAYS` | `threads.session_retention_days` |
| Minutes of runner output kept in Redis for catch-up | 10 | `YAK_THREADS_STREAM_CATCHUP_MINUTES` | `threads.stream_catchup_minutes` |
| Branch deployments running at once | 6 | `YAK_DEPLOYMENTS_RUNNING_CAP` | `deployments.running_cap` |
| Minutes idle before a branch deployment hibernates | 15 | `YAK_DEPLOYMENTS_IDLE_MINUTES` | `deployments.idle_minutes` |
| Days before an unused branch deployment is destroyed | 7 | `YAK_DEPLOYMENTS_DESTROY_DAYS` | `deployments.destroy_days` |

## Datasets

A dataset is a large read-only data set, such as a database snapshot, that a repository's threads and previews mount instead of downloading each time. A repository declares one in the `data:` section of `.yak/config.yml` (`name`, `prefix`, `version_key`, `mount_path`, `size_gb`). See [Repository config](repo-config.md) for the keys.

Yak fetches the data from an S3-compatible store you configure once for the whole host:

| Env var | Default | Meaning |
|---|---|---|
| `YAK_DATASETS_S3_ENDPOINT` | none | Endpoint URL. Required. |
| `YAK_DATASETS_S3_BUCKET` | none | Bucket. Required. |
| `YAK_DATASETS_S3_ACCESS_KEY`, `YAK_DATASETS_S3_SECRET` | none | Credentials. Required. Use a key that can only read this bucket. |
| `YAK_DATASETS_S3_REGION` | `us-east-1` | Region. |
| `YAK_DATASETS_S3_PATH_STYLE` | `true` | Path-style addressing. |
| `YAK_DATASETS_FETCH_TIMEOUT_MINUTES` | 720 | Longest a fetch may run. |
| `YAK_DATASETS_CLI` | `aws` | The CLI the fetch sandbox runs. It must exist in the `yak-base` image. |

Without the endpoint, bucket and both credentials, every fetch is refused. The S3 credential goes only to a short-lived data sandbox that does the fetch. Threads and previews never see it.

How it works:

1. `yak:dataset-fetch {repository}` creates a data sandbox, syncs the prefix into one Incus volume for that repository, and takes a snapshot per version. It runs in the foreground and can take as long as the sync does. Add `--dataset-version=` to fetch a named version.
2. A thread or preview for that repository gets a clone of the current snapshot, attached as device `data` at `mount_path`. A repository that never fetched a dataset costs no Incus call.
3. `yak:dataset-sweep` (hourly) releases clones whose container is gone and deletes old snapshots nobody clones.

A fetch is refused when the declared size exceeds the repository's cap, when the storage pool is at its watermark (`YAK_THREADS_POOL_WATERMARK_PERCENT`), or when two versions already have live clones.

## Session Store

Claude's session files for each thread are stored encrypted, so a thread can resume after its sandbox is destroyed. After that, the store is the only copy of the conversation.

| Part | Where |
|---|---|
| File index and entries | Database tables `session_files`, `session_file_segments`, `session_entries` |
| Encrypted segments | `YAK_THREADS_SESSION_STORE_PATH`, `/data/session-store` in the container, `/home/yak/data/session-store` on the host |
| Key | `YAK_SESSION_STORE_KEY`, from the vault variable `yak_session_store_key` |

The cipher is libsodium secretbox with a 32-byte key. Yak never stores the key in the database.

- Never change the key once threads exist. Sessions encrypted with the old key become unreadable.
- Keep the key in the vault, apart from your backups of `/home/yak/data`. A leaked backup then cannot be decrypted.
- Back up the database and the store directory together. The index and the segments must match.

Closed threads lose their session files after `YAK_THREADS_SESSION_RETENTION_DAYS` (30 days) through `yak:prune-session-store`.

## Deploys and Draining

A deploy recreates the container, which kills running work. The `yak-container` role protects it:

1. If a container exists, the role runs `yak:drain --wait=300 --max-wait=2700`. This sets a flag so workers stop picking up new turns, then waits for tasks in Running or Retrying to finish. A task that has been silent for 15 minutes and for at least `--wait` seconds is force-failed. At `--max-wait` (`yak_drain_max_wait_seconds`, 2700) every task still in flight is force-failed. Tasks awaiting CI or clarification are left alone.
2. The role recreates the container. On start the entrypoint runs `php artisan migrate --force`.
3. The role runs `yak:resume` to clear the flag, `yak:queue:move-database-jobs` to move leftover rows from the old database queue to Redis, and `yak:resume-interrupted-tasks` to requeue the tasks the drain failed.

Drain is best-effort: if it fails, the deploy goes ahead. A deploy can block for up to `yak_drain_max_wait_seconds`, since turns routinely run 30 to 40 minutes. To deploy sooner, set a smaller `yak_drain_max_wait_seconds` or `yak_drain_wait_seconds` in the vault.

To recreate the container by hand, run `yak:drain` first and `yak:resume` and `yak:resume-interrupted-tasks` afterwards. If workers stay paused after a failed deploy, `yak:resume` clears the flag.

Threads themselves live in their sandboxes and in the broker, not in the container. A thread that is between turns is untouched by a deploy. Restarting `yak-broker` is also safe: it finds each channel again in Redis and the runners resume from their session.

## Backups

Yak does not schedule backups. Set up your own, and test a restore.

| Back up | Why | Where |
|---|---|---|
| MariaDB dump | Threads, tasks, accounts, the session store index. `docker exec yak-mariadb mariadb-dump --single-transaction --routines --triggers -uroot -p yak` | Container `yak-mariadb` |
| `/home/yak/data` | Task artifacts, legacy Claude transcripts and the session store segments | Host |
| `/etc/yak` | `app_key` and the environment files. Holds secrets: encrypt the copy. | Host |
| The vault and its password | Source of every other secret, including `yak_session_store_key`. Keep it apart from the data backups. | Your machine |

You do not need to back up Redis (queues and streams are transient), sandboxes, repository templates, previews or datasets. Yak rebuilds templates and previews and refetches datasets. `/home/yak/.claude` holds only the optional shared login.
