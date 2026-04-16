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

### Optional Per Channel

Only configure the channels you use. Everything except GitHub (for pushing branches and opening PRs) is optional.

| Channel | What you need |
|---|---|
| **Slack** | Slack app with bot token plus signing secret. |
| **Linear** | OAuth application (client id + secret + webhook signing secret). Authorize at `/settings/linear`. Requires workspace admin approval. |
| **Sentry** | Auth token plus webhook secret. Alert rules tagged `yak-eligible`. |
| **Drone CI** | API token. Yak polls the Drone API — no webhook needed. |
| **GitHub Actions** | Included with the GitHub App — no additional setup. |

See the [Channels](channels.md) page for the full configuration of each channel.

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

Optionally, save your vault password to a file so you don't have to type `--ask-vault-pass` on every run:

```bash
echo 'your-vault-password' > ansible/vault/.vault_pass
```

This file is gitignored and referenced automatically by `ansible.cfg`.

Channels you are not using can be left blank — Ansible skips disabled channels automatically.

```yaml
# === Required ===
yak_domain: yak.yourcompany.com
anthropic_api_key: sk-ant-...
github_org: your-org

# Dashboard auth
google_oauth_client_id: "..."
google_oauth_client_secret: "..."
google_oauth_allowed_domains: "yourcompany.com"  # required, comma-separated

# === Auto-generated (leave blank) ===
yak_app_key: ""

# Database (auto-provisioned MariaDB container)
mariadb_root_password: ""
mariadb_password: ""

# GitHub App (filled after guided setup on first run, then re-run)
github_app_id: ""
github_app_private_key: ""
github_installation_id: ""
github_webhook_secret: ""

# === Channels (leave blank to disable) ===

slack_bot_token: ""
slack_signing_secret: ""
slack_workspace_url: ""          # e.g. https://acme.slack.com — for thread deep links

linear_oauth_client_id: ""
linear_oauth_client_secret: ""
linear_oauth_redirect_uri: ""   # defaults to https://{yak_domain}/auth/linear/callback
linear_webhook_secret: ""

sentry_auth_token: ""
sentry_webhook_secret: ""
sentry_org_slug: ""

drone_url: ""
drone_token: ""

# === Extra Agent Environment Variables ===
# agent_extra_env:
#   NODE_AUTH_TOKEN: "ghp_..."
#   NPM_TOKEN: "..."
```

#### Agent Environment Variables

Repos that need tokens at build time (e.g. private npm registries) can have those tokens forwarded to the agent process. Add them to `agent_extra_env` in your vault:

```yaml
agent_extra_env:
  NODE_AUTH_TOKEN: "ghp_..."
```

This does two things automatically:
1. Sets `NODE_AUTH_TOKEN=ghp_...` as a container env var (available to `npm install`)
2. Sets `YAK_AGENT_PASSTHROUGH_ENV=NODE_AUTH_TOKEN` so the sandboxed agent process receives it

Only vars listed here are forwarded — app secrets like `DB_PASSWORD` and `APP_KEY` are never exposed to the agent.

The `google_oauth_allowed_domains` field is **required**. Login is rejected for any email whose domain is not in the list.

### Where to get credentials

#### Anthropic API key

1. Go to [console.anthropic.com/settings/keys](https://console.anthropic.com/settings/keys)
2. Click **Create Key**
3. Copy the key (`sk-ant-...`) into `anthropic_api_key`

This key is for the routing layer (Haiku/Sonnet API calls), not the CLI. The CLI authenticates separately via a Max subscription — see step 5 below.

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

#### Slack (optional)

1. Go to [api.slack.com/apps](https://api.slack.com/apps) and click **Create New App → From scratch**
2. Name it (e.g. "Yak") and select your workspace
3. Go to **OAuth & Permissions** and add these bot token scopes:
   - `chat:write`
   - `app_mentions:read`
   - `channels:history`
   - `reactions:write` — lets Yak react 👀 / 🚧 / ✅ / ❌ on your mention for glanceable status
4. Click **Install to Workspace** and authorize
5. Under **Basic Information → Display Information**, upload [`public/slack-icon.png`](../public/slack-icon.png) as the app icon
6. Copy the **Bot User OAuth Token** (`xoxb-...`) into `slack_bot_token`
7. Go to **Basic Information** and copy the **Signing Secret** into `slack_signing_secret`
8. Go to **App Home**, enable the **Home Tab** — this powers the welcome DM Yak sends the first time a user opens Yak in the sidebar
9. Go to **Event Subscriptions**, enable events, and set the request URL to `https://{your-domain}/webhooks/slack`
10. Subscribe to bot events: `app_mention`, `message.channels`, and `app_home_opened`

Add `YAK_SLACK_WORKSPACE_URL=https://{your-workspace}.slack.com` to your vault so the dashboard can deep-link tasks back to their originating Slack thread.

See [Channels → Slack](channels.md#slack-optional) for usage and gotchas.

#### Linear (optional)

Yak installs as a Linear **Agent** — a first-class workspace participant that appears in the assignee picker without consuming a seat.

1. Go to [linear.app/settings/api/applications](https://linear.app/settings/api/applications)
   → **New application**.
   - Name: `Yak`
   - Callback URL: `https://{your-domain}/auth/linear/callback`
   - Enable **Webhooks**, set the URL to `https://{your-domain}/webhooks/linear`, and under **App events** tick **Agent session events**.
   - Copy the app's webhook signing secret into `linear_webhook_secret`.
2. Copy `Client ID` and `Client secret` into `linear_oauth_client_id` /
   `linear_oauth_client_secret`.
3. Re-run Ansible so the env vars land in the container.
4. Sign in to the Yak dashboard → **Settings → Linear → Connect Linear**
   and approve the consent screen. A workspace admin must approve — the install requests `app:assignable` and `app:mentionable` scopes.

See [Channels → Linear](channels.md#linear-optional) for usage and gotchas.

#### Sentry (optional)

1. In your Sentry org, go to **Settings → Developer Settings → Custom Integrations**
2. Click **Create New Integration** → **Internal Integration**
3. Set permissions: **Organization: Read**, **Project: Read**, **Issue & Event: Read** (the first two are required for the Add Repository form to populate the Sentry project dropdown)
4. Set the webhook URL to `https://{your-domain}/webhooks/sentry`
5. Copy the **Token** into `sentry_auth_token`
6. Copy the **Webhook Signing Secret** (under "Webhook Secret" in the integration's Client Secret section) into `sentry_webhook_secret`
7. Set `sentry_org_slug` to your Sentry organization slug
8. Create an alert rule tagged `yak-eligible` for the issues you want Yak to pick up
9. Map Sentry projects to repos via the `sentry_project` field on each repo in the dashboard

See [Channels → Sentry](channels.md#sentry-optional) for filtering rules and gotchas.

#### Drone CI (optional)

1. Go to your Drone instance at `https://{drone-url}/account`
2. Copy the **Personal Token** into `drone_token`
3. Set `drone_url` to your Drone instance URL (e.g. `https://drone.yourcompany.com`)

Drone has no outbound webhooks — Yak polls the Drone API every minute for CI results, so no webhook config is required on the Drone side.

See [Channels → Drone CI](channels.md#drone-ci-optional) for usage and gotchas.

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

### 4. Provision

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

### 5. Log In To Claude Code

Claude Code CLI authenticates against a Max subscription, not an API key. After provisioning completes, the playbook prints instructions — SSH into the server and run:

```bash
docker exec -it yak claude login
```

Follow the browser-based OAuth flow. The session token persists in the mounted `/home/yak/.claude` volume and survives container restarts.

The routing layer (Laravel AI) uses the `ANTHROPIC_API_KEY` from vault for Haiku/Sonnet API calls — separate from the CLI subscription auth.

### 6. Add Your Repositories

Repositories are managed through the dashboard — not Ansible. Log in to `https://{your-domain}`, go to **Repositories > Add**, and fill in each repo's HTTPS clone URL. Yak clones the repo using the GitHub App and dispatches a setup task automatically.

See the [Repositories](repositories.md) page for the full field reference and how setup tasks work.

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

#### Slack manifest changes between versions

Some releases add Slack scopes or event subscriptions. When they do, the Slack app manifest in your workspace has to be updated *by hand* — the container upgrade alone is not enough; reactions silently fail without the scope, and App Home events never fire without the subscription.

Steps when a release adds Slack scopes or events:

1. Go to your Slack app at [api.slack.com/apps](https://api.slack.com/apps) → your Yak app.
2. Under **OAuth & Permissions**, add any new bot scopes listed in the release notes, then click **Reinstall to Workspace**.
3. Under **Event Subscriptions**, add any new bot events listed in the release notes.
4. If the installation returned a new bot token, update `slack_bot_token` in vault and re-run `ansible-playbook ansible/playbook.yml --tags yak-container`.

Current required scopes and events are listed in the [Slack setup section](#slack-optional) above. If something that used to work (reactions, App Home DMs, interactivity) stops, check that your installed scopes still match that list.

### Adding a New Channel

1. Add the channel's credentials to `ansible/vault/secrets.yml`
2. Re-run Ansible: `ansible-playbook ansible/playbook.yml`
3. Ansible regenerates the MCP config, updates env vars, restarts the container
4. Configure the external service's webhook URL — see the [Channels](channels.md) page

### Removing a Channel

Clear the channel's credentials in vault (set them to empty strings) and re-run Ansible. Webhook routes for disabled channels return 404. Historical tasks from that channel remain in the database.

### Rotating Secrets

```bash
ansible-vault edit ansible/vault/secrets.yml
ansible-playbook ansible/playbook.yml --tags secrets
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

- [Channels](channels.md) — per-channel configuration and usage
- [Repositories](repositories.md) — adding and managing repos, CLAUDE.md guidance
- [Architecture](architecture.md) — how Yak works under the hood
- [Troubleshooting](troubleshooting.md) — common issues and solutions
