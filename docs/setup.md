# Setup Guide

One command provisions a fresh server. Everything runs through Ansible — the manual steps in this guide document what Ansible automates, not an alternative path.

## What You End Up With

- A dedicated server running the Yak Docker container (Laravel app, queue workers, scheduler, nginx)
- Your repositories cloned and ready for Claude Code to work on
- Webhook endpoints for whichever channels you have enabled
- A dashboard at `https://{your-domain}` behind Google OAuth
- Claude Code CLI configured with MCP servers matching your enabled channels

## Prerequisites

| Requirement | Notes |
|---|---|
| **Server** | Dedicated box with 32GB+ RAM, 500GB+ disk. Hetzner AX-series, bare metal, or VM. Ubuntu 24.04 or Debian 12+. Public IP for inbound webhooks. |
| **Domain** | DNS A record pointing to the server. Used for dashboard and webhook endpoints. |
| **Claude** | Max subscription (for Claude Code CLI) plus an Anthropic API key (for the routing layer). |
| **GitHub** | Account with push access to target repos. The Ansible provisioner creates the GitHub App automatically. |
| **Google OAuth** | Google Cloud project with OAuth credentials. Used for dashboard authentication. |
| **Ansible** | 2.15+ on your local machine (`pip install ansible`). |

### Optional Per Channel

Only configure the channels you use. Everything except GitHub (for pushing branches and opening PRs) is optional.

| Channel | What you need |
|---|---|
| **Slack** | Slack app with bot token plus signing secret. |
| **Linear** | API key. Webhook configured to `https://{your-domain}/webhooks/linear`. |
| **Sentry** | Auth token plus webhook secret. Alert rules tagged `yak-eligible`. |
| **Drone CI** | API token. Webhook configured per-repo. |
| **GitHub Actions** | Included with the GitHub App — no additional setup. |

See [channels.md](channels.md) for the full configuration of each channel.

## Quick Start

### 1. Clone Yak

```bash
git clone https://github.com/geocodio/yak.git
cd yak
```

### 2. Configure Secrets

```bash
cp ansible/vault/secrets.example.yml ansible/vault/secrets.yml
ansible-vault encrypt ansible/vault/secrets.yml
ansible-vault edit ansible/vault/secrets.yml
```

Channels you are not using can be left blank — Ansible skips disabled channels automatically.

```yaml
# === Required ===
yak_domain: yak.yourcompany.com
yak_app_key: "{{ lookup('password', '/dev/null length=32 chars=ascii_letters,digits') }}"

# Claude (routing layer API calls)
anthropic_api_key: sk-ant-...

# GitHub App (Ansible creates this automatically if the ID is empty)
github_org: your-org
github_app_id: ""
github_app_private_key: ""
github_webhook_secret: ""

# Dashboard auth
google_oauth_client_id: "..."
google_oauth_client_secret: "..."
google_oauth_allowed_domains: "yourcompany.com"  # required, comma-separated

# === Channels (leave blank to disable) ===

slack_bot_token: ""
slack_signing_secret: ""

linear_api_key: ""
linear_webhook_secret: ""

sentry_auth_token: ""
sentry_webhook_secret: ""
sentry_org_slug: ""

drone_url: ""
drone_token: ""
```

The `google_oauth_allowed_domains` field is **required**. Login is rejected for any email whose domain is not in the list.

### 3. Configure Inventory

```bash
cp ansible/inventory/hosts.example.yml ansible/inventory/hosts.yml
```

```yaml
all:
  hosts:
    yak:
      ansible_host: 203.0.113.10
      ansible_user: root
      ansible_python_interpreter: /usr/bin/python3
```

### 4. Define Your Repositories

```bash
cp ansible/group_vars/repos.example.yml ansible/group_vars/repos.yml
```

```yaml
yak_repos:
  - slug: my-app
    name: My App
    git_url: git@github.com:your-org/my-app.git
    default_branch: main
    ci_system: github_actions    # github_actions or drone
    is_default: true             # exactly one repo must be default
    sentry_project: my-app       # optional, maps Sentry alerts to this repo

  - slug: api
    name: API Service
    git_url: git@github.com:your-org/api.git
    default_branch: main
    ci_system: github_actions
    sentry_project: api-service
```

See [repositories.md](repositories.md) for the full repo field reference.

### 5. Provision

```bash
ansible-playbook -i ansible/inventory/hosts.yml ansible/playbook.yml --ask-vault-pass
```

This single command runs the following roles in order:

1. **base** — creates the `yak` user, configures UFW, fail2ban, and swap
2. **docker** — installs Docker Engine and Compose
3. **ssl** — provisions a Let's Encrypt certificate via Caddy
4. **github-app** — creates and installs the GitHub App on your org (skipped if already provisioned)
5. **repos** — clones repositories, seeds the `repositories` table
6. **mcp-config** — generates `mcp-config.json` with only the enabled channels' MCP servers
7. **yak-container** — builds the Docker image, starts the container with the correct env vars
8. **claude-code-config** — installs the Claude CLI, configures slash commands, prints the interactive login prompt
9. **channel-*** — conditionally runs each enabled channel role (Slack, Linear, Sentry, Drone)

Total time: about 10 minutes for provisioning, plus 5–15 minutes per repo for setup tasks.

### 6. Log In To Claude Code

Claude Code CLI authenticates against a Max subscription, not an API key. After provisioning completes, the playbook prints instructions — SSH into the server and run:

```bash
docker exec -it yak claude login
```

Follow the browser-based OAuth flow. The session token persists in the mounted `/home/yak/.claude` volume and survives container restarts.

The routing layer (Laravel AI) uses the `ANTHROPIC_API_KEY` from vault for Haiku/Sonnet API calls — separate from the CLI subscription auth.

## Verifying the Installation

### Health Check

Visit `https://{your-domain}/health` or run:

```bash
docker exec yak php artisan yak:healthcheck
```

The check covers queue workers, repo fetchability, Claude CLI responsiveness, enabled channel MCP servers, and setup status for each repo.

### Smoke Test

Run a manual task against your default repo:

```bash
docker exec yak php artisan yak:run TEST-001 "Add a comment to the README explaining what this repo does" --sync
```

The `--sync` flag runs the task in the foreground so you can watch the output. If it creates a branch, pushes, and CI runs, Yak is working.

### Webhook Verification

For each enabled channel, trigger a test event:

- **Slack** — mention `@yak` in a channel
- **Linear** — add the `yak` label to a test issue
- **Sentry** — trigger a test alert rule
- **GitHub Actions** — push a commit to a `yak/test-*` branch

Check `https://{your-domain}/tasks` — each event should create a task row.

## Updating Yak

### Application Updates

```bash
cd yak
git pull
ansible-playbook -i ansible/inventory/hosts.yml ansible/playbook.yml --tags yak-app
```

The Docker image is rebuilt and the container restarts. Active tasks finish before the worker restarts.

### Adding a New Channel

1. Add the channel's credentials to `ansible/vault/secrets.yml`
2. Re-run Ansible: `ansible-playbook ... --ask-vault-pass`
3. Ansible regenerates the MCP config, updates env vars, restarts the container
4. Configure the external service's webhook URL — see [channels.md](channels.md)

### Removing a Channel

Clear the channel's credentials in vault (set them to empty strings) and re-run Ansible. Webhook routes for disabled channels return 404. Historical tasks from that channel remain in the database.

### Rotating Secrets

```bash
ansible-vault edit ansible/vault/secrets.yml
ansible-playbook ... --tags secrets
```

## Updating Repos

### Routine Maintenance

Yak runs `git fetch origin {default_branch}` every 30 minutes via the scheduled `yak:refresh-repos` command. No manual repo updates are needed during normal operation.

### Infrastructure Changes

If a repo's dev environment changes (new Docker services, different database, etc.), re-run the setup task:

```bash
docker exec yak php artisan yak:setup-repo my-app
```

Or click **Re-run Setup** on the repo's edit page in the dashboard.

## Where To Go Next

- [channels.md](channels.md) — per-channel configuration and usage
- [repositories.md](repositories.md) — adding and managing repos, CLAUDE.md guidance
- [architecture.md](architecture.md) — how Yak works under the hood
- [troubleshooting.md](troubleshooting.md) — common issues and solutions
