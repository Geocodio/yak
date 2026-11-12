# Upgrading to Threads

This page is for an operator who runs the previous version of Yak, where each task was one Claude CLI run in a fresh sandbox, and wants to move to the version built on threads. A fresh install does not need it: see [Setup](setup.md).

## What Changes for Users

- Work happens in [threads](threads.md): one sandbox, one Claude session and one Claude account that stay with a conversation across turns. Channels still create tasks, and each task runs as a turn in a thread. See [Concepts](concepts.md).
- Everyone runs on a [Claude account](claude-accounts.md) (a setup token or an API key) instead of the shared login on the server.
- People can [teleport](teleport.md) a thread to their own computer, use the [`yak` CLI](cli.md), and connect over [SSH](ssh.md) or the browser terminal.
- Threads have [previews](previews.md), can split into [sub-threads](sub-threads.md), can span [several repositories](multi-repo.md) and can notify people by [push](notifications.md).
- Repository settings that lived in the database move into `.yak/` files in each repository. See [Repository config](repo-config.md).

## What Changes on the Host

| Area | Change |
|---|---|
| Queue | Workers read Redis only (`QUEUE_CONNECTION=redis`). A new `yak-redis` container holds the queues. |
| New host services | `yak-broker` (with the auth proxy), `yak-jump-sshd`, `yak-wake`, `yak-termbridge`. |
| New container programs | `yak-thread-drivers`, `yak-credential-socket`, `reverb`. |
| New queues | `yak-render`, `yak-deployments`, `yak-poll` join `yak-claude` and `default`. `yak-claude` jobs now time out after 3600s. |
| Sandboxes | One sandbox per thread, named `yak-thread-<id>`, stopped and destroyed by timers. The sandbox network gets an ACL and the sandbox base image is rebuilt (`yak_sandbox_base_version` 5). |
| Claude login | The shared `claude login` session is no longer used by threads. |
| Firewall | Port 2222 opens for the SSH jump host. |
| Build tools | The machine that runs Ansible needs Go 1.26+ and Node.js. |
| Telemetry | A `yak-telemetryd` container holds System page metrics. |

[Operations](operations.md) describes each part.

## New Required Vault Variables

The playbook stops before it changes anything if these are missing. Generate each as shown in [Setup](setup.md#required-variables).

| Variable | Generate with |
|---|---|
| `redis_password` | `openssl rand -hex 32` |
| `telemetryd_ingest_token`, `telemetryd_query_token` | `openssl rand -hex 32` each |
| `yak_session_store_key` | `openssl rand -base64 32` (never change it afterwards) |
| `reverb_app_id`, `reverb_app_key`, `reverb_app_secret` | random strings |
| `yak_ssh_internal_token` | `openssl rand -hex 32` |

Keep `yak_app_key` as it is. The old database holds values encrypted with it. The playbook reads `/etc/yak/app_key` from the server if the vault value is blank.

## Upgrade Steps

Do these in order. Pick a quiet time: the container is recreated.

1. Back up the MariaDB database and `/home/yak/data`. The migrations only add tables and columns, but a dump is the way back. See [Backups](operations.md#backups).
2. Install Go 1.26+ and Node.js on the machine that runs Ansible.
3. `git pull` the release, add the new variables to the vault.
4. Run the full playbook: `ansible-playbook ansible/playbook.yml`. Do not use `--tags yak-container` for this first run, because the broker, SSH and sandbox network roles are not in that tag. In order, the playbook:
   - rebuilds the sandbox base image, adds the sandbox ACL and the firewall rules;
   - starts Redis, the broker and telemetryd;
   - drains running work (`yak:drain`), recreates the container (the entrypoint runs `php artisan migrate --force`), and runs `yak:resume`;
   - runs `yak:queue:move-database-jobs` to move jobs left in the old `jobs` table onto Redis, then `yak:resume-interrupted-tasks` to requeue tasks the drain failed;
   - installs the SSH services.
5. Mark old previews as gone. Branch deployments whose containers no longer exist must not show as running. The playbook does not run this, so you do:

   ```bash
   docker exec -u www-data yak php artisan yak:mark-deployments-gone --dry-run
   docker exec -u www-data yak php artisan yak:mark-deployments-gone
   ```

   It needs the `incus` command on the host. Marked previews rebuild on demand.
6. Sign in, make yourself a Yak account admin and add the first Claude account. Without a usable account a new task cannot start a thread, so tasks resumed in step 4 fail until you do this. After adding the account, retry them from the dashboard. Steps: [Setup, First sign-in](setup.md#first-sign-in).
7. Ask each person to add their own account under Settings > Claude accounts, or let them use the shared Yak account.
8. Move repository settings into the repositories. This opens one pull request per active repository and does not change anything else. Merge them, then check each repository:

   ```bash
   docker exec -u www-data yak php artisan yak:migrate-config --dry-run
   docker exec -u www-data yak php artisan yak:migrate-config
   ```

   Use `--repo=<slug>` for one repository. A repository that already has `.yak/` files is skipped.
9. Repository templates built on an older base image are out of date. Re-run setup for a repository (`yak:setup-repo <slug>` or Re-run setup on its page) if its first thread reports a stale or missing template.
10. If you used the shared login before, you can stop running `yak-claude-login`. The System rows "Claude CLI" and "Claude Max Session" still probe it and may stay red. Threads do not depend on them.

### Tasks in flight

`yak:drain` waits for tasks in Running or Retrying (up to `yak_drain_max_wait_seconds`, 2700 by default) and force-fails what is left. `yak:resume-interrupted-tasks` requeues those tasks, at most three times each. Tasks awaiting CI or a clarification are left alone and continue under the new version. Jobs left in the database queue move to Redis in step 4, so none are lost or run twice.

## Rollback

The old agent path is gone, so a rollback is a redeploy of the previous image, not a switch.

1. The new migrations only create tables and add columns, so the previous version runs against the migrated database. You do not need to undo them.
2. Check out the previous release and redeploy its image with its own playbook: `ansible-playbook ansible/playbook.yml --tags yak-container -e yak_image_tag=<previous sha>`. Use the vault and roles of that release, since the old image expects the database queue.
3. Jobs queued on Redis while the new version ran stay in Redis and do not run on the old version. Let the queues empty before you roll back.
4. Threads and their session data stay in the database, unused by the old version.

If the database itself is damaged, restore the dump from step 1.

## Checklist

- `https://yak.example.com/health` shows Queue Worker, Redis, Incus Daemon and Sandbox Base Template green.
- `systemctl is-active yak-broker yak-wake yak-termbridge yak-jump-sshd` prints `active` four times.
- `docker exec yak pgrep -fa 'yak:thread-drivers|yak:credential-socket|reverb:start'` lists all three.
- `jobs` is empty: `docker exec yak-mariadb mariadb -uroot -p -e 'select count(*) from yak.jobs'` prints 0.
- `yak:mark-deployments-gone --dry-run` reports a total of 0.
- A new thread on a test repository streams output and ends in a PR.
- A message sent to an existing thread from the dashboard starts a new turn.
- `yak ssh` to a thread connects through port 2222.
- Each repository has a merged `.yak/` pull request.
