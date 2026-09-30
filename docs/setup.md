# Setup Guide

One command provisions a fresh server. Everything runs through Ansible — the manual steps in this guide document what Ansible automates, not an alternative path.

## What You End Up With

- A dedicated server running the Yak Docker container (Laravel app, queue workers, scheduler, nginx)
- A MariaDB container with persistent storage for the application database
- **Incus + ZFS** for sandboxed task execution — each task runs in its own isolated system container with its own Docker daemon, network, and filesystem
- Webhook endpoints for whichever channels you have enabled
- A dashboard at `https://{your-domain}` behind Google OAuth
- Claude Code CLI configured with MCP servers matching your enabled channels

## Prerequisites

| Requirement | Notes |
|---|---|
| **Server** | Dedicated box with 32GB+ RAM, 500GB+ disk. Hetzner AX-series, bare metal, or VM. Ubuntu 24.04 or Debian 12+. Public IP for inbound webhooks. |
| **Domain** | DNS A record pointing to the server. Used for dashboard and webhook endpoints. |
| **Claude** | Max subscription (for Claude Code CLI) plus an Anthropic API key (for the routing layer). |
| **GitHub** | Organization account. The Ansible provisioner creates a GitHub App automatically — repos are cloned via HTTPS using the App's installation token (no SSH keys needed). |
| **Google OAuth** | Google Cloud project with OAuth credentials. Used for dashboard authentication. |
| **Ansible** | 2.15+ on your local machine (`pip install ansible`). |

### Optional Channels

GitHub is the only required channel. Slack, Linear, Sentry and Drone CI are optional, and each has its own setup section in [Channels](channels.md). Enable only the ones you use.

### Optional: voiceover

Set `ELEVENLABS_API_KEY` in the vault to narrate walkthrough videos. Without it they render with captions only. See [Video Walkthroughs](video-walkthroughs.md#voiceover).

## Quick Start

You run steps 1–5 **on your own machine** (laptop or workstation). Ansible reads the inventory file, connects to your target server over SSH, and provisions everything remotely. You only SSH into the server itself for step 6 (the one-time Claude Code login). Step 7 happens in your browser.

### 1. Install Ansible (on your local machine)

Ansible 2.15 or newer is required. If you don't already have it:

```bash
pip install ansible        # or: brew install ansible
ansible --version          # confirm 2.15+
```

You also need SSH access to the target server as `root` (or another user with passwordless sudo) before continuing.

### 2. Clone Yak (on your local machine)

```bash
git clone https://github.com/geocodio/yak.git
cd yak
```

### 3. Configure Secrets (on your local machine)

```bash
cp ansible/vault/secrets.example.yml ansible/vault/secrets.yml
ansible-vault encrypt ansible/vault/secrets.yml
ansible-vault edit ansible/vault/secrets.yml
```

Optionally, save your vault password to a file so you don't have to type `--ask-vault-pass` on every run:

```bash
echo 'your-vault-password' > ansible/vault/.vault_pass
```

This file is gitignored and referenced automatically by `ansible.cfg`.

The example file is commented and lists every key. Set the required ones below. Leave channel keys you do not use blank, and Ansible skips those channels. Channel keys are covered in [Channels](channels.md).

```yaml
yak_domain: yak.yourcompany.com
anthropic_api_key: sk-ant-...
github_org: your-org
google_oauth_client_id: "..."
google_oauth_client_secret: "..."
google_oauth_allowed_domains: "yourcompany.com"  # required, comma-separated; other domains cannot log in
mariadb_root_password: "..."   # strong random values, e.g. `openssl rand -base64 24`
mariadb_password: "..."
```

`yak_app_key` is generated for you. The `github_app_*` keys are filled in after the guided GitHub App setup on the first run.

For repos that need private npm tokens or private Docker registries, see [Advanced configuration](#advanced-configuration).

### Where to get credentials

#### Anthropic API key

1. Go to [console.anthropic.com/settings/keys](https://console.anthropic.com/settings/keys)
2. Click **Create Key**
3. Copy the key (`sk-ant-...`) into `anthropic_api_key`

This key is for the routing layer (Haiku/Sonnet API calls), not the CLI. The CLI authenticates separately via a Max subscription — see step 6 below.

#### Google OAuth (required — dashboard authentication)

1. Go to [console.cloud.google.com](https://console.cloud.google.com) and create a new project (or select an existing one)
2. Go to **APIs & Services → OAuth consent screen**
3. Set user type to **Internal** (restricts login to your Google Workspace org — no app review needed)
4. Fill in the app name (e.g. "Yak") and your support email, then save
5. Go to **APIs & Services → Credentials**
6. Click **Create Credentials → OAuth client ID**
7. Application type: **Web application**
8. Add an authorized redirect URI: `https://{your-domain}/auth/google/callback`
9. Copy the **Client ID** into `google_oauth_client_id`
10. Copy the **Client Secret** into `google_oauth_client_secret`
11. Set `google_oauth_allowed_domains` to your domain (e.g. `yourcompany.com`)

#### GitHub

No manual setup needed before provisioning. Leave the `github_app_id` fields blank and set `github_org` to your GitHub organization name. On first run, the playbook prints step-by-step instructions to create the GitHub App via the manifest flow — you fill in the resulting credentials and re-run.

#### Channels (optional)

Slack, Linear, Sentry and Drone CI credentials are covered in [Channels](channels.md). You can enable them after your first task works, then re-run Ansible.

### 4. Configure Inventory (on your local machine)

Tell Ansible which server to provision:

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

### 5. Provision (run from your local machine)

This connects to the server over SSH and provisions everything:

```bash
ansible-playbook ansible/playbook.yml
```

This single command runs the following roles in order:

1. **base** — creates the `yak` user, configures UFW, fail2ban, swap, and automatic security updates
2. **docker** — installs Docker Engine and Compose
3. **ssl** — provisions a Let's Encrypt certificate via Caddy, configures log rotation
4. **github-app** — creates and installs the GitHub App on your org (skipped if already provisioned)
5. **mcp-config** — generates `mcp-config.json` with only the enabled channels' MCP servers
6. **mariadb** — runs a MariaDB 11 container with persistent storage on a Docker network
7. **channel-*** — conditionally runs each enabled channel role (Slack, Linear, Sentry, Drone)
8. **yak-container** — pulls the pre-built Docker image from ghcr.io, starts the container with env vars
9. **claude-code-config** — installs the Claude CLI, configures slash commands, prints the interactive login prompt

Total time: about 10 minutes.

### 6. Log In To Claude Code (on the server)

This is the first step that runs **on the Yak server itself**, not your local machine. Claude Code CLI authenticates against a Max subscription, not an API key. After provisioning completes, SSH into the server and run:

```bash
yak-claude-login
```

Type `/login` at the prompt and finish the browser flow. The session token persists in the mounted `/home/yak/.claude` volume and survives container restarts.

The routing layer (Laravel AI) uses the `ANTHROPIC_API_KEY` from vault for Haiku/Sonnet API calls — separate from the CLI subscription auth.

### 7. Add Your Repositories (in your browser)

Repositories are managed through the dashboard — not Ansible. Log in to `https://{your-domain}`, go to **Repositories > Add**, and fill in each repo's HTTPS clone URL. Yak clones the repo using the GitHub App and dispatches a setup task automatically.

See the [Repositories](repositories.md) page for the full field reference and how setup tasks work.

## Verifying the Installation

### Health Check

Visit `https://{your-domain}/health` or run:

```bash
docker exec yak php artisan yak:healthcheck
```

The check covers queue workers, repo fetchability, Claude CLI responsiveness, enabled channel MCP servers, and setup status for each repo.

The scheduler runs it every 15 minutes. Failing checks can alert Slack. See [Troubleshooting](troubleshooting.md#health-check-failures).

### Your First Task

1. Open **Repositories** in the dashboard. Add your repo if you have not, and wait until its setup badge reads **Ready**.
2. Go to **Tasks** and click **New task**. Pick the repo, keep the **Fix** mode, and describe a small change, for example "Add a comment to the README explaining what this repo does".
3. Click **Start task**. You land on the task page, where the timeline shows Yak working.
4. When the task reaches `awaiting_ci` and then `success`, open the PR link at the top of the task page. If the task fails, use **Retry** on the task page.

If a task does not appear or does not finish, see [Troubleshooting](troubleshooting.md). You can also run a task from the server with `docker exec yak php artisan yak:run TEST-001 "..." --sync`.

### Webhook Verification

For each enabled channel, trigger a test event:

- **Slack** — mention `@yak` in a channel
- **Linear** — assign a test issue to Yak (the OAuth app appears in the assignee picker)
- **Sentry** — trigger a test alert rule
- **GitHub Actions** — push a commit to a `yak/test-*` branch

Check `https://{your-domain}/tasks` — each event should create a task row.

## Updating Yak

### Application Updates

Push to `main` triggers a GitHub Actions build that pushes a new image to `ghcr.io/geocodio/yak`. Then pull and deploy:

```bash
ansible-playbook ansible/playbook.yml --tags yak-container
```

To deploy a specific version:

```bash
ansible-playbook ansible/playbook.yml --tags yak-container -e yak_image_tag=abc1234
```

### Adding a New Channel

Follow the channel's setup section in [Channels](channels.md), then re-run `ansible-playbook ansible/playbook.yml`.

### Removing a Channel

Clear the channel's credentials in vault (set them to empty strings) and re-run Ansible. Webhook routes for disabled channels return 404. Historical tasks from that channel remain in the database.

### Rotating Secrets

```bash
ansible-vault edit ansible/vault/secrets.yml
ansible-playbook ansible/playbook.yml --tags secrets
```

## Updating Repos

When a repo's dev environment changes, re-run its setup task. See [Repositories](repositories.md#re-running-setup).

## Branch deployments

Branch preview deployments use wildcard subdomains of `yak_domain` (e.g. `my-repo-feat-x.yak.example.com`). Two pieces of infrastructure beyond the base Yak install are required:

### Wildcard DNS

Add a wildcard CNAME for `*.yak.example.com` pointing at the Yak host, same target as the main `yak_domain` A record. Verify with `dig +short anything.yak.example.com`.

### DNS-01 TLS + provider API token

Wildcard certificates require DNS-01 (HTTP-01 does not issue wildcards). Caddy needs a provider plugin baked into its binary.

1. Set `caddy_dns_provider` in `ansible/group_vars/yak.yml` to one of [Caddy's supported providers](https://github.com/caddy-dns) (e.g. `cloudflare`, `route53`, `digitalocean`).
2. Add the provider's API token to `ansible/vault/secrets.yml`:
   ```yaml
   caddy_dns_provider_api_token: "<token with zone:edit permission for your yak_domain zone>"
   ```
3. Re-run the provisioning playbook (`./deploy.sh` or `ansible-playbook ansible/playbook.yml`). The `ssl` role will download a Caddy binary bundled with the chosen plugin and enable the wildcard Caddyfile block.

If either value is unset, the Caddyfile falls back to dashboard-only routing. Preview deployments will not work until both are configured.

## Advanced configuration

### Agent Environment Variables

Repos that need tokens at build time (for example private npm registries) can have them forwarded to the agent. Add them to `agent_extra_env` in your vault:

```yaml
agent_extra_env:
  NODE_AUTH_TOKEN: "ghp_..."
```

Ansible sets the variable on the container and lists it in `YAK_AGENT_PASSTHROUGH_ENV`, so the sandboxed agent receives it. Only variables listed here are forwarded. App secrets like `DB_PASSWORD` and `APP_KEY` never reach the agent. Redeploy and re-run the repo's setup task to bake the variable into the next snapshot.

### Private Docker Registries

Repos that pull private Docker images can authenticate from inside every sandbox. Add credentials to `docker_registries` in your vault:

```yaml
docker_registries:
  ghcr.io:
    username: "your-github-username"
    password: "ghp_..."              # PAT with `read:packages` scope
```

Ansible renders these into `~/.docker/config.json` on the host and Yak copies the file into each new sandbox. Re-run the repo's setup task so the snapshot picks up the cached images.

## Where To Go Next

- [Channels](channels.md) — per-channel configuration and usage
- [Repositories](repositories.md) — adding and managing repos, CLAUDE.md guidance
- [Architecture](architecture.md) — how Yak works under the hood
- [Troubleshooting](troubleshooting.md) — common issues and solutions
