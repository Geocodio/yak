# Agent Sandboxing & MariaDB Migration Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent Claude Code agents from accessing files outside their assigned repo directory, and migrate from SQLite to MariaDB for durability.

**Architecture:** Two independent workstreams. (1) Sandbox the agent process using Linux filesystem permissions — make `/data` owned by `www-data` only, and run the `yak` user's Claude Code process with no write access outside `/home/yak/repos/<slug>`. (2) Add a MariaDB container alongside the Yak container, update Laravel config, migrate data, and update local dev to use docker compose with MariaDB.

**Tech Stack:** Docker, MariaDB 11, Ansible, Laravel database config, Pest tests

---

## Part A: Agent Sandboxing

### Problem

Claude Code runs as the `yak` user with `--dangerously-skip-permissions`. When it processes a repo that uses SQLite (e.g. "deployer"), it can write to `/data/database.sqlite` — Yak's own database — because the `yak` user has write access to `/data`. This corrupted the production database.

### Approach

Lock down filesystem permissions so the `yak` user can only write inside `/home/yak/repos/` and `/home/yak/.claude/`. The database at `/data/` should only be writable by `www-data` (PHP-FPM and queue workers run as `www-data` for web, but the `yak-claude-worker` runs as `yak`). The key insight: the queue worker that orchestrates Claude Code runs as `yak`, but it only needs to *read* the database to pick up jobs — Laravel's queue worker uses `SELECT ... FOR UPDATE` which requires write access. So we need to split concerns: the queue workers should run as `www-data`, and only the Claude Code subprocess should run as `yak` with restricted access.

### Task 1: Run yak-claude-worker as www-data, subprocess as yak

The queue worker currently runs as `user=yak` in supervisord. This means the whole worker process (including DB access) runs as yak. Instead, run the worker as `www-data` (which has DB write access) and use `su` / `runuser` to execute the Claude Code subprocess as the `yak` user.

**Files:**
- Modify: `docker/supervisord.conf:25-36`
- Modify: `app/Agents/ClaudeCodeRunner.php:44` and `app/Agents/ClaudeCodeRunner.php:136-142`

- [ ] **Step 1: Change yak-claude-worker to run as www-data**

In `docker/supervisord.conf`, change the `yak-claude-worker` section:

```ini
[program:yak-claude-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /app/artisan queue:work database --queue=yak-claude --timeout=600 --memory=2048 --tries=3 --sleep=3
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
stdout_logfile=/app/storage/logs/yak-claude-worker.log
stderr_logfile=/app/storage/logs/yak-claude-worker-error.log
stopwaitsecs=610
```

The only change is `user=yak` → `user=www-data`.

- [ ] **Step 2: Wrap Claude Code subprocess in `runuser`**

In `app/Agents/ClaudeCodeRunner.php`, update the streaming method to run Claude as the `yak` user via `runuser`. Change the `proc_open` call in `runStreaming()`:

```php
// In runStreaming(), replace the proc_open line:
$wrappedCommand = sprintf('runuser -u yak -- bash -c %s', escapeshellarg($command));
$process = proc_open($wrappedCommand, $descriptors, $pipes, $request->workingDirectory, $env);
```

And update `runBatch()` similarly:

```php
private function runBatch(AgentRunRequest $request): AgentRunResult
{
    $command = $this->buildCommand($request, streaming: false);
    $wrappedCommand = sprintf('runuser -u yak -- bash -c %s', escapeshellarg($command));

    $result = Process::path($request->workingDirectory)
        ->env([
            'HOME' => '/home/yak',
            'ANTHROPIC_API_KEY' => '',
        ])
        ->timeout($request->timeoutSeconds)
        ->run($wrappedCommand);

    // ... rest unchanged
}
```

- [ ] **Step 3: Lock down /data permissions**

In `docker/entrypoint.sh`, replace the current permission block with stricter ownership. The `yak` user should NOT have write access to `/data`:

```bash
#!/usr/bin/env bash
set -e

if [ ! -f /data/database.sqlite ]; then
    touch /data/database.sqlite
fi

# /data is owned by www-data only — yak user must NOT write here
chown -R www-data:www-data /data
chmod -R 750 /data

chown -R www-data:www-data /app/bootstrap/cache /app/storage
chmod -R 775 /app/storage

# Ensure yak user owns its home directory contents
chown -R yak:yak /home/yak/repos /home/yak/.claude /home/yak/.cache /home/yak/.config

# Ensure log files are writable by both users via group
usermod -aG www-data yak 2>/dev/null || true

# Remove any .env so Laravel reads from environment variables directly
rm -f /app/.env

php artisan migrate --force --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

exec "$@"
```

- [ ] **Step 4: Test locally with Docker**

```bash
docker build --platform linux/amd64 -t geocodio/yak:sandbox-test .
docker run --rm --platform linux/amd64 geocodio/yak:sandbox-test bash -c "
    # Verify yak user cannot write to /data
    runuser -u yak -- touch /data/test 2>&1 && echo 'FAIL: yak can write /data' || echo 'PASS: yak cannot write /data'

    # Verify yak user CAN write to repos
    runuser -u yak -- touch /home/yak/repos/test 2>&1 && echo 'PASS: yak can write repos' || echo 'FAIL: yak cannot write repos'

    # Verify www-data CAN write to /data
    runuser -u www-data -- touch /data/test2 2>&1 && echo 'PASS: www-data can write /data' || echo 'FAIL: www-data cannot write /data'
"
```

Expected: all three PASS.

- [ ] **Step 5: Commit**

```bash
git add docker/supervisord.conf docker/entrypoint.sh app/Agents/ClaudeCodeRunner.php
git commit -m "fix(security): sandbox agent process so it cannot write outside repos"
```

---

## Part B: Migrate from SQLite to MariaDB

### Problem

SQLite is a single file on disk. It's fragile in a containerized environment — susceptible to corruption during deploys, and now an attack surface for rogue agent processes. MariaDB runs as a separate container with its own persistent volume, making it immune to both issues.

### Approach

1. Add a MariaDB Docker container in production (via Ansible)
2. Add a `docker-compose.yml` for local development with MariaDB
3. Update Laravel config and env files
4. Update the Dockerfile to remove SQLite dependencies and entrypoint DB init
5. Update tests (SQLite in-memory is fine for tests — no migration needed there)

### Task 2: Add MariaDB container to production via Ansible

**Files:**
- Create: `ansible/roles/mariadb/tasks/main.yml`
- Modify: `ansible/playbook.yml`
- Modify: `ansible/group_vars/yak.yml`

- [ ] **Step 1: Create MariaDB Ansible role**

Create `ansible/roles/mariadb/tasks/main.yml`:

```yaml
---
- name: Ensure MariaDB data directory exists
  ansible.builtin.file:
    path: "{{ mariadb_data_path }}"
    state: directory
    mode: "0755"
    owner: root
    group: root

- name: Run MariaDB container
  community.docker.docker_container:
    name: "{{ mariadb_container_name }}"
    image: "mariadb:11"
    state: started
    restart_policy: unless-stopped
    ports:
      - "127.0.0.1:3306:3306"
    volumes:
      - "{{ mariadb_data_path }}:/var/lib/mysql"
    env:
      MARIADB_ROOT_PASSWORD: "{{ mariadb_root_password }}"
      MARIADB_DATABASE: "yak"
      MARIADB_USER: "yak"
      MARIADB_PASSWORD: "{{ mariadb_password }}"
    networks:
      - name: yak

- name: Ensure Docker network exists
  community.docker.docker_network:
    name: yak
    state: present
```

- [ ] **Step 2: Add MariaDB variables to group_vars**

Add to `ansible/group_vars/yak.yml`:

```yaml
mariadb_container_name: yak-mariadb
mariadb_data_path: /home/yak/mariadb-data
```

- [ ] **Step 3: Add MariaDB secrets to vault**

Add to `ansible/vault/secrets.yml` (encrypted):

```yaml
mariadb_root_password: <generate-strong-password>
mariadb_password: <generate-strong-password>
```

Add the same keys to `ansible/vault/secrets.example.yml` with placeholder values.

- [ ] **Step 4: Add MariaDB role to playbook and connect Yak container to network**

In `ansible/playbook.yml`, add the `mariadb` role before `yak-container`:

```yaml
roles:
  # ... existing roles ...
  - mariadb
  - yak-container
```

In `ansible/roles/yak-container/tasks/main.yml`, add the yak container to the `yak` Docker network so it can reach MariaDB by container name:

```yaml
- name: Run Yak container
  community.docker.docker_container:
    # ... existing config ...
    networks:
      - name: yak
```

- [ ] **Step 5: Commit**

```bash
git add ansible/
git commit -m "feat(infra): add MariaDB container for production"
```

### Task 3: Update env template and Laravel config for MariaDB

**Files:**
- Modify: `ansible/roles/yak-container/templates/env.j2`
- Modify: `.env.example`

- [ ] **Step 1: Update production env template**

In `ansible/roles/yak-container/templates/env.j2`, replace the SQLite config:

```env
DB_CONNECTION=mariadb
DB_HOST=yak-mariadb
DB_PORT=3306
DB_DATABASE=yak
DB_USERNAME=yak
DB_PASSWORD={{ mariadb_password }}
```

Remove the old `DB_CONNECTION=sqlite` and `DB_DATABASE=/data/database.sqlite` lines.

- [ ] **Step 2: Update .env.example for local dev**

In `.env.example`, update the database section:

```env
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=yak
DB_USERNAME=yak
DB_PASSWORD=yak
```

- [ ] **Step 3: Commit**

```bash
git add ansible/roles/yak-container/templates/env.j2 .env.example
git commit -m "feat(config): switch database config from SQLite to MariaDB"
```

### Task 4: Add docker-compose.yml for local development

**Files:**
- Create: `docker-compose.yml`

- [ ] **Step 1: Create docker-compose.yml**

```yaml
services:
  mariadb:
    image: mariadb:11
    ports:
      - "127.0.0.1:3306:3306"
    environment:
      MARIADB_ROOT_PASSWORD: root
      MARIADB_DATABASE: yak
      MARIADB_USER: yak
      MARIADB_PASSWORD: yak
    volumes:
      - mariadb-data:/var/lib/mysql

  mariadb-test:
    image: mariadb:11
    ports:
      - "127.0.0.1:3307:3306"
    environment:
      MARIADB_ROOT_PASSWORD: root
      MARIADB_DATABASE: yak_test
      MARIADB_USER: yak
      MARIADB_PASSWORD: yak
    tmpfs:
      - /var/lib/mysql

volumes:
  mariadb-data:
```

The test database uses `tmpfs` for speed — no persistence needed.

- [ ] **Step 2: Commit**

```bash
git add docker-compose.yml
git commit -m "feat(dev): add docker-compose with MariaDB for local development"
```

### Task 5: Update Dockerfile and entrypoint for MariaDB

**Files:**
- Modify: `Dockerfile`
- Modify: `docker/entrypoint.sh`

- [ ] **Step 1: Update entrypoint to remove SQLite init**

In `docker/entrypoint.sh`, remove the SQLite database creation. The entrypoint should just run migrations against MariaDB:

```bash
#!/usr/bin/env bash
set -e

# /data is owned by www-data only — yak user must NOT write here
chown -R www-data:www-data /data
chmod -R 750 /data

chown -R www-data:www-data /app/bootstrap/cache /app/storage
chmod -R 775 /app/storage

# Ensure yak user owns its home directory contents
chown -R yak:yak /home/yak/repos /home/yak/.claude /home/yak/.cache /home/yak/.config

# Ensure log files are writable by both users via group
usermod -aG www-data yak 2>/dev/null || true

# Remove any .env so Laravel reads from environment variables directly
rm -f /app/.env

# Wait for MariaDB to be ready before running migrations
echo "Waiting for database..."
for i in $(seq 1 30); do
    php artisan db:monitor --databases=mariadb > /dev/null 2>&1 && break
    sleep 2
done

php artisan migrate --force --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

exec "$@"
```

- [ ] **Step 2: Keep pdo_mysql in Dockerfile (already present), remove sqlite volume**

In `Dockerfile`, the `pdo_mysql` extension is already installed. Remove `/data` from the VOLUME directive since the database no longer lives there (artifacts still use `/data` though — keep it for now if `ARTIFACTS_PATH` points there):

```dockerfile
VOLUME ["/home/yak/repos", "/data", "/home/yak/.claude"]
```

Actually, `/data` is still used for `ARTIFACTS_PATH=/data/artifacts`. Keep it as-is. No Dockerfile changes needed beyond what's already there — `pdo_mysql` is already compiled in.

- [ ] **Step 3: Commit**

```bash
git add docker/entrypoint.sh
git commit -m "fix(entrypoint): remove SQLite init, add MariaDB readiness check"
```

### Task 6: Update test configuration

**Files:**
- Modify: `phpunit.xml` (or `phpunit.xml.dist`)
- Modify: `tests/Pest.php` (if needed)

- [ ] **Step 1: Check current test database config**

```bash
grep -A5 'DB_CONNECTION\|DB_DATABASE' phpunit.xml 2>/dev/null || grep -A5 'DB_CONNECTION\|DB_DATABASE' phpunit.xml.dist
```

Tests can keep using SQLite in-memory for speed. Ensure `phpunit.xml` has:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

If these are already present, no change needed. If they're missing, add them to the `<php>` section.

- [ ] **Step 2: Run full test suite to verify tests still pass with SQLite in-memory**

```bash
php artisan test --compact
```

Expected: same pass/fail as before (only Contract tests should fail).

- [ ] **Step 3: Commit (if changes were needed)**

```bash
git add phpunit.xml
git commit -m "test: ensure test suite uses SQLite in-memory"
```

### Task 7: Production data migration

This is a one-time operational task, not a code change. Run after deploying the MariaDB container but before switching Yak to MariaDB.

**Files:** None (operational steps)

- [ ] **Step 1: Deploy MariaDB container first**

Run the Ansible playbook with just the MariaDB role to get the container running:

```bash
cd ansible && ansible-playbook playbook.yml --tags mariadb
```

- [ ] **Step 2: Verify MariaDB is accessible from Yak container**

```bash
ssh root@yak.geocod.io "docker exec yak php artisan tinker --execute '
    config([\"database.connections.mariadb.host\" => \"yak-mariadb\"]);
    DB::connection(\"mariadb\")->getPdo();
    echo \"MariaDB connection OK\";
'"
```

- [ ] **Step 3: Run migrations on MariaDB**

```bash
ssh root@yak.geocod.io "docker exec yak php artisan migrate --database=mariadb --force"
```

- [ ] **Step 4: Export SQLite data and import into MariaDB**

```bash
ssh root@yak.geocod.io "
    # Export each table from SQLite
    docker exec yak sqlite3 /data/database.sqlite '.dump users' > /tmp/sqlite_dump.sql
    docker exec yak sqlite3 /data/database.sqlite '.dump tasks' >> /tmp/sqlite_dump.sql
    docker exec yak sqlite3 /data/database.sqlite '.dump task_logs' >> /tmp/sqlite_dump.sql
    docker exec yak sqlite3 /data/database.sqlite '.dump artifacts' >> /tmp/sqlite_dump.sql
    docker exec yak sqlite3 /data/database.sqlite '.dump repositories' >> /tmp/sqlite_dump.sql
    docker exec yak sqlite3 /data/database.sqlite '.dump daily_costs' >> /tmp/sqlite_dump.sql
"
```

Note: SQLite dump format is not directly compatible with MariaDB. Use a PHP migration script instead:

```bash
ssh root@yak.geocod.io "docker exec yak php artisan tinker --execute '
    \$tables = [\"users\", \"repositories\", \"tasks\", \"task_logs\", \"artifacts\", \"daily_costs\"];
    config([\"database.connections.mariadb.host\" => \"yak-mariadb\", \"database.connections.mariadb.database\" => \"yak\", \"database.connections.mariadb.username\" => \"yak\", \"database.connections.mariadb.password\" => env(\"DB_PASSWORD\")]);
    foreach (\$tables as \$table) {
        \$rows = DB::connection(\"sqlite\")->table(\$table)->get();
        if (\$rows->isEmpty()) continue;
        DB::connection(\"mariadb\")->table(\$table)->insert(\$rows->map(fn(\$r) => (array)\$r)->toArray());
        echo \$table . \": \" . \$rows->count() . \" rows\\n\";
    }
'"
```

- [ ] **Step 5: Deploy Yak with MariaDB config**

Deploy the updated env template that points to MariaDB:

```bash
./deploy.sh
```

- [ ] **Step 6: Verify production is working**

```bash
ssh root@yak.geocod.io "curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/"
# Expected: 200 or 302 (redirect to login)
```

### Task 8: Update documentation

All docs that reference SQLite, database setup, or the data model need updating to reflect MariaDB and the sandbox.

**Files:**
- Modify: `docs/setup.md`
- Modify: `docs/development.md`
- Modify: `docs/architecture.md`
- Modify: `docs/troubleshooting.md`
- Modify: `ansible/vault/secrets.example.yml`

- [ ] **Step 1: Update `docs/setup.md`**

In the prerequisites table, replace SQLite with MariaDB (it's now handled by Docker, not a local install). No user-facing MariaDB setup is needed — it's provisioned by Ansible automatically.

In the secrets example block (~line 64), add the MariaDB credentials:

```yaml
# === Required ===
# ... existing ...

# Database (auto-provisioned MariaDB container)
mariadb_root_password: ""   # generated — keep secure
mariadb_password: ""        # generated — keep secure
```

In the "What You End Up With" section (~line 8), update the description to mention MariaDB:

```markdown
- A dedicated server running the Yak Docker container (Laravel app, queue workers, scheduler, nginx)
- A MariaDB container with persistent storage for the application database
```

In the provisioning roles list (~line 203), add the MariaDB role:

```markdown
5. **mariadb** — runs a MariaDB 11 container with persistent storage on a Docker network
```

In the "Emergency: Kill Everything" section of troubleshooting (and the restart section at the bottom of setup), remove the reference to "SQLite data ... persist across restarts" and update to mention MariaDB:

```markdown
MariaDB runs as a separate container with its own persistent volume — it is unaffected by Yak container restarts.
```

- [ ] **Step 2: Update `docs/development.md`**

In the prerequisites table (~line 9), replace SQLite with Docker:

```markdown
| **PHP** | 8.4+ | With `pdo_mysql` extension |
| **Docker** | 24+ | For MariaDB via docker-compose |
```

Remove the `SQLite` row.

In the "Getting Started" section (~line 20), replace the SQLite setup steps:

```bash
# Start MariaDB
docker compose up -d

# Set up the environment
cp .env.example .env
php artisan key:generate

# Run migrations
php artisan migrate --seed
```

Remove the `touch database/database.sqlite` line.

In the "Database" section under Testing Conventions (~line 324), update:

```markdown
### Database

All feature tests use SQLite in-memory via `RefreshDatabase` (configured globally in `tests/Pest.php`). The application uses MariaDB in development and production, but tests use SQLite for speed. No test database container is needed.
```

- [ ] **Step 3: Update `docs/architecture.md`**

In "The Data Model" section (~line 239), replace:

```markdown
Five tables, deliberately minimal. SQLite is the backing store — single file, no separate database server, no Redis.
```

With:

```markdown
Five tables, deliberately minimal. MariaDB is the backing store, running as a separate Docker container with its own persistent volume.
```

In the Safety Model section under "--dangerously-skip-permissions" (~line 282), add a bullet about the sandbox:

```markdown
- **Process isolation.** Claude Code runs as the `yak` user, which has no write access outside `/home/yak/repos/` and `/home/yak/.claude/`. The database, artifacts, and application files are owned by `www-data` and inaccessible to the agent process.
```

- [ ] **Step 4: Update `docs/troubleshooting.md`**

In the "Emergency: Kill Everything" section (~line 229), replace:

```markdown
SQLite data, repo clones, and the Claude session token all persist across restarts via mounted volumes. Nothing is lost.
```

With:

```markdown
MariaDB runs as a separate container (`yak-mariadb`) and is unaffected by Yak container restarts. Repo clones and the Claude session token persist via mounted volumes. Nothing is lost.
```

Update the "queues are SQLite-backed" reference (~line 251):

```markdown
This is safe — the queues are MariaDB-backed and any in-flight jobs will be retried on the next worker boot (with the caveat that tasks mid-`claude -p` session may be left in `running` and need manual reset per the earlier section).
```

Add a new section for MariaDB issues:

```markdown
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
# Stop MariaDB, wipe data, restart
docker stop yak-mariadb
rm -rf /home/yak/mariadb-data/*
docker start yak-mariadb
# Wait for init, then re-run migrations
docker exec yak php artisan migrate --force
```
```

- [ ] **Step 5: Update `ansible/vault/secrets.example.yml`**

Add the MariaDB credential placeholders:

```yaml
# Database
mariadb_root_password: "change-me"
mariadb_password: "change-me"
```

- [ ] **Step 6: Commit**

```bash
git add docs/ ansible/vault/secrets.example.yml
git commit -m "docs: update setup, development, architecture, and troubleshooting for MariaDB migration"
```

---

## Summary of Changes

| Area | What Changes | Why |
|------|-------------|-----|
| `supervisord.conf` | `yak-claude-worker` runs as `www-data` | Worker needs DB write access; `yak` user should not |
| `ClaudeCodeRunner.php` | Claude subprocess wrapped in `runuser -u yak` | Agent code runs sandboxed as `yak` with no `/data` access |
| `entrypoint.sh` | `/data` locked to `www-data`; SQLite init removed | Prevent agent from corrupting DB; MariaDB handles its own storage |
| Ansible | New `mariadb` role + Docker network | MariaDB as a separate container with persistent volume |
| `env.j2` | `DB_CONNECTION=mariadb`, host=`yak-mariadb` | Point Laravel at MariaDB instead of SQLite file |
| `docker-compose.yml` | MariaDB + test DB containers for local dev | Consistent dev/prod parity |
| `.env.example` | Updated DB defaults | Guide local setup |
| `docs/setup.md` | Add MariaDB to prerequisites, secrets, provisioning roles | Accurate setup instructions |
| `docs/development.md` | Replace SQLite with Docker/MariaDB in local dev setup | Dev environment matches prod |
| `docs/architecture.md` | Update data model description, add sandbox to safety model | Reflect current architecture |
| `docs/troubleshooting.md` | Replace SQLite references, add MariaDB troubleshooting section | Accurate debugging guidance |
| `secrets.example.yml` | Add `mariadb_root_password` and `mariadb_password` placeholders | Guide credential setup |
