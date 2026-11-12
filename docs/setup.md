# Setup

This page walks you through a fresh Yak install on one dedicated server. One Ansible playbook provisions the whole host. Use it once to install, then again for every update.

Moving from the previous version? Read [Upgrading to threads](upgrading.md) instead.

## What You End Up With

Every part below runs on the one server. [Operations](operations.md) describes each component, its service name, ports and logs.

| Component | What it is |
|---|---|
| Yak container | Docker container `yak`: the dashboard, queue workers, the scheduler, the thread drivers, the credential socket and Reverb (websockets). |
| MariaDB | Docker container `yak-mariadb`: the application database. |
| Redis | Docker container `yak-redis`: every queue and the broker streams. |
| telemetryd | Docker container `yak-telemetryd`: 30 days of metrics, traces and logs for the System page. |
| Caddy | Host service: TLS, and the reverse proxy to the container. Routes the wildcard preview hosts when DNS-01 is configured. |
| Incus + ZFS | Host service: one system container (sandbox) per thread, each with its own Docker daemon, network and filesystem. |
| `yak-broker` | Host service (Go): starts and supervises one runner per thread in its sandbox, and runs the auth proxy that keeps real Claude credentials out of sandboxes. |
| SSH jump host | Host services `yak-jump-sshd`, `yak-wake` and `yak-termbridge`: `yak ssh` and the browser terminal, on port 2222. |

You also get a dashboard at `https://yak.example.com` behind Google sign-in, and webhook endpoints for each channel you enable.

## Prerequisites

| Requirement | Notes |
|---|---|
| **Server** | Dedicated machine, 32GB+ RAM, 500GB+ disk, x86-64 (the playbook builds Linux amd64 binaries). Hetzner AX-series, bare metal or a VM. Debian 12+ or Ubuntu 24.04. A public IP for inbound webhooks. SSH access as `root` or a user with passwordless sudo. |
| **Domain** | A DNS name for the dashboard (`yak_domain`), plus a wildcard record for previews. See [DNS and firewall](#dns-and-firewall). |
| **Claude** | At least one Claude account for Yak to run on: a setup token (`claude setup-token`) or an Anthropic API key. You add accounts in the dashboard after the install. See [Claude accounts](claude-accounts.md). |
| **Anthropic API key** | Also required in the vault (`anthropic_api_key`). Yak uses it for its own small helper calls, such as classifying a request. |
| **GitHub** | An organization. The playbook walks you through creating a GitHub App. Yak clones over HTTPS with the App's installation token, so no SSH keys are needed. |
| **Google OAuth** | A Google Cloud project with OAuth credentials, for dashboard sign-in. |
| **Ansible** | 2.15+ on the machine you run the playbook from (`pip install ansible`), with the `community.general`, `community.docker` and `ansible.posix` collections. |
| **Go** | 1.26+ on the machine you run the playbook from. The `yak-broker` and `yak-ssh` roles run `go build` there and copy the binaries to the server. |
| **Node.js and npm** | On the same machine. The `incus` role runs `npm install` and `npm run build` for the in-sandbox `yak-browser` tool whenever it builds the sandbox base image (the first run, and after `yak_sandbox_base_version` changes). |

### Optional Per Channel

Only configure the channels you use. Everything except GitHub (for pushing branches and opening PRs) is optional.

| Channel | What you need |
|---|---|
| **Slack** | Slack app with bot token plus signing secret. |
| **Linear** | OAuth application (client id + secret + webhook signing secret). Authorize at `/settings/linear`. Requires workspace admin approval. |
| **Sentry** | Auth token plus webhook secret. An issue alert rule that notifies the integration. |
| **Email** | Postmark server with an inbound stream (MX record on the inbound domain) and a verified sending domain. |
| **Drone CI** | API token. Yak polls the Drone API -- no webhook needed. |
| **GitHub Actions** | Included with the GitHub App -- no additional setup. |

See the [Channels](channels.md) page for the full configuration of each channel.

### Optional: voiceover

Walkthrough videos render with captions by default. Set `ELEVENLABS_API_KEY` to add narration: before each render, Yak turns the script's `intro`, each shot's `say` line, and the `outro` into MP3s (ElevenLabs `eleven_multilingual_v2`) and mixes them into the cut. `ELEVENLABS_VOICE_ID` picks the voice and defaults to `UgBBYS2sOqTuMpoF3BR0`.

Expect roughly 1,000–1,300 characters per walkthrough, billed at one credit per character. The health page's **Voiceover** row shows `Off (no ELEVENLABS_API_KEY)`, the number of lines generated in the last 24 hours, or the last failure. The cost dashboard's video panel sums the characters sent.

Voiceover is best-effort: with no key, or when the API errors, the walkthrough still renders captions-only and Yak still opens the PR.

To create the key, go to **Developers → API Keys** in the ElevenLabs dashboard and create a restricted key. Yak calls exactly one endpoint, `POST /v1/text-to-speech/{voice_id}`, so the key needs a single permission:

| Setting | Value |
|---|---|
| Text to Speech | **Access** |
| Everything else | **No Access** |

Leave **Voices** and **Models** at No Access too -- the voice id comes from `ELEVENLABS_VOICE_ID` and the model is hardcoded, so neither is ever looked up over the API. Setting a per-credit refresh period is worth doing as a spend cap; at ~1,000–1,300 characters per walkthrough there is plenty of headroom under a modest monthly limit.

Then fill in `elevenlabs_api_key` (and optionally `elevenlabs_voice_id`) in the vault.


## DNS and Firewall

Create these DNS records before you provision, so Caddy can get certificates:

| Record | Points at | Used for |
|---|---|---|
| `yak.example.com` (A) | The server | Dashboard, webhooks, Reverb websockets, `yak` CLI. |
| `*.yak.example.com` (A or CNAME) | The server | Previews: `t-<random>.yak.example.com` for thread previews and one host per branch deployment. Needs the DNS-01 setup in [Previews](#previews). |

The playbook configures UFW with these inbound ports:

| Port | Purpose |
|---|---|
| 22 | SSH for Ansible and operators (`ufw_allowed_ports`). |
| 80 | HTTP, for certificate issuance and the redirect to HTTPS. |
| 443 | HTTPS: dashboard, webhooks, previews and Reverb. |
| 2222 | The SSH jump host for `yak ssh` (`yak_ssh_jump_port`, opened by the `yak-ssh` role). |

Everything else listens on loopback or on the sandbox bridge only: the container on `127.0.0.1:8080`, Redis on `127.0.0.1:6379`, the terminal bridge on `127.0.0.1:8090` and the auth proxy on `10.100.0.1:8787`. Do not publish them.

Clients connect to the jump host at `yak_domain` by default. If you put a proxy in front of the dashboard record that only passes HTTP (for example a proxied Cloudflare record), port 2222 will not get through. Point a second, DNS-only name at the server and set `yak_ssh_jump_host` in the vault to it.

## Install

You run steps 1 to 5 on your own machine. Ansible connects to the server over SSH and provisions everything. [First sign-in](#first-sign-in) happens in your browser.

### 1. Install the tools

```bash
pip install ansible        # or: brew install ansible
ansible --version          # 2.15 or newer
go version                 # 1.26 or newer
node --version
```

### 2. Clone Yak

```bash
git clone https://github.com/geocodio/yak.git
cd yak
```

### 3. Write the vault

```bash
cp ansible/vault/secrets.example.yml ansible/vault/secrets.yml
ansible-vault encrypt ansible/vault/secrets.yml
ansible-vault edit ansible/vault/secrets.yml
```

To skip typing the vault password on every run, save it to `ansible/vault/.vault_pass`. The file is gitignored and `ansible.cfg` reads it.

The vault file holds every secret and setting. The playbook stops before it changes anything if a required variable is missing or malformed.

#### Required variables

| Variable | What it is | How to get it |
|---|---|---|
| `yak_domain` | Dashboard hostname, for example `yak.example.com`. | Your DNS name. |
| `github_org` | Your GitHub organization. | Its login. |
| `google_oauth_client_id`, `google_oauth_client_secret` | Google sign-in. | [Google OAuth](#google-oauth-required) below. |
| `google_oauth_allowed_domains` | Comma-separated email domains that may sign in. | For example `example.com`. |
| `anthropic_api_key` | Key for Yak's helper calls. | [console.anthropic.com/settings/keys](https://console.anthropic.com/settings/keys), starts with `sk-ant-`. |
| `mariadb_root_password`, `mariadb_password` | Database passwords. | `openssl rand -hex 24` for each. MariaDB reads them only on its first start with an empty data directory, so choose them once. |
| `redis_password` | Redis `requirepass`. | `openssl rand -hex 32` |
| `telemetryd_ingest_token`, `telemetryd_query_token` | Tokens between the app and telemetryd. | `openssl rand -hex 32` for each. |
| `yak_session_store_key` | Encrypts stored Claude session files. Must be a 32-byte key in base64 (43 characters then `=`), or the playbook refuses to run. | `openssl rand -base64 32`. Keep it in the vault and never change it: see [Session store](operations.md#session-store). |
| `reverb_app_id`, `reverb_app_key`, `reverb_app_secret` | Credentials for the websocket server. Any random strings. The key is public (the browser uses it), the secret is not. | `openssl rand -hex 8`, `openssl rand -hex 16`, `openssl rand -hex 32`. |
| `yak_ssh_internal_token` | Shared secret between the app and the SSH host services. | `openssl rand -hex 32` |

`yak_app_key` (Laravel's `APP_KEY`) is optional. Leave it blank and the playbook generates one, saves it to `/etc/yak/app_key` on the server and reuses it on later runs. It encrypts database values, so never rotate it.

The GitHub App variables (`github_app_id`, `github_app_private_key`, `github_installation_id`, `github_webhook_secret`) start blank. On the first run the `github-app` role prints the manifest steps for creating the App. Fill in the result and run the playbook again. `github_app_client_id` and `github_app_client_secret` are optional and let people link GitHub for the co-owner gate.

#### Optional variables

| Variable | Effect |
|---|---|
| `slack_*`, `linear_*`, `sentry_*`, `email_*`, `postmark_api_key`, `drone_*` | One group per channel. Leave a channel's variables blank to disable it. See [Where to get credentials](#where-to-get-credentials) and [Channels](channels.md). |
| `slack_alert_channel` | Channel ID that receives health alerts (the container reads it as `YAK_SLACK_ALERT_CHANNEL`). Invite the bot first. |
| `elevenlabs_api_key`, `elevenlabs_voice_id` | Narration for walkthrough videos. Blank means captions only. |
| `github_personal_access_token` | Gives Claude a GitHub MCP server. Needs a personal access token, not the App key. |
| `caddy_dns_provider_api_token` | Enables wildcard TLS for previews. See [Previews](#previews). |
| `yak_ssh_jump_host`, `yak_ssh_host` | Hostnames `yak ssh` and Settings show for the jump host. Both default to `yak_domain`. |
| `agent_extra_env`, `docker_registries` | Tokens forwarded into sandboxes, and private registry logins. See below. |
| `yak_extra_env` | Any other Yak setting, as a map of environment variables. See [Other settings](#other-settings). |

#### Other settings

Every setting in [Operations](operations.md#capacity-and-lifecycle-settings) is an environment variable. To change one, put it in `yak_extra_env` in the vault. The playbook writes it into the container's environment:

```yaml
yak_extra_env:
  YAK_THREADS_AWAKE_CAP: "12"
  YAK_DAILY_BUDGET_USD: "100"
```

These settings have no default and need a value if you use the feature:

- Admins of the shared Yak Claude accounts: `YAK_SYSTEM_ACCOUNT_ADMIN_IDS`, a comma-separated list of user ids. Optional: the first person to sign in is made an admin without it, and setting it turns that off. See [First sign-in](#first-sign-in).
- Push notifications: `VAPID_SUBJECT` (for example `mailto:ops@example.com`), `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY`. Generate the key pair once after the first deploy with `docker exec -u www-data yak php artisan webpush:vapid --show`, then add all three. See [Notifications](notifications.md).
- Datasets: the `YAK_DATASETS_S3_*` variables. See [Datasets](operations.md#datasets).

#### Agent Environment Variables

Repos that need tokens at build time (e.g. private npm registries) can have those tokens forwarded to the agent process. Add them to `agent_extra_env` in your vault:

```yaml
agent_extra_env:
  NODE_AUTH_TOKEN: "ghp_..."
```

This does two things automatically:
1. Sets `NODE_AUTH_TOKEN=ghp_...` as a container env var (available to `npm install`)
2. Sets `YAK_AGENT_PASSTHROUGH_ENV=NODE_AUTH_TOKEN` so the sandboxed agent process receives it

Only vars listed here are forwarded -- app secrets like `DB_PASSWORD` and `APP_KEY` are never exposed to the agent.

#### Private Docker Registries

Repos that pull private Docker images (e.g. bases shared across services, internal tooling) can authenticate from inside every sandbox without rebuilding locally. Add credentials to `docker_registries` in your vault:

```yaml
docker_registries:
  ghcr.io:
    username: "your-github-username"
    password: "ghp_..."              # PAT with `read:packages` scope
  registry.example.com:
    username: "deploy"
    password: "..."
```

Ansible renders these into `~/.docker/config.json` on the host, bind-mounts them into the Yak container, and the sandbox manager pushes the file to `/home/yak/.docker/config.json` in each new sandbox. `docker pull` and `docker-compose up` pick it up automatically.

The `google_oauth_allowed_domains` field is **required**. Login is rejected for any email whose domain is not in the list.


### Where to get credentials

#### Google OAuth (required)

1. Go to [console.cloud.google.com](https://console.cloud.google.com) and create a new project (or select an existing one)
2. Go to **APIs & Services → OAuth consent screen**
3. Set user type to **Internal** (restricts login to your Google Workspace org -- no app review needed)
4. Fill in the app name (e.g. "Yak") and your support email, then save
5. Go to **APIs & Services → Credentials**
6. Click **Create Credentials → OAuth client ID**
7. Application type: **Web application**
8. Add an authorized redirect URI: `https://{your-domain}/auth/google/callback`
9. Copy the **Client ID** into `google_oauth_client_id`
10. Copy the **Client Secret** into `google_oauth_client_secret`
11. Set `google_oauth_allowed_domains` to your domain (e.g. `yourcompany.com`)

#### GitHub

No manual setup needed before provisioning. Leave the `github_app_id` fields blank and set `github_org` to your GitHub organization name. On first run, the playbook prints step-by-step instructions to create the GitHub App via the manifest flow -- you fill in the resulting credentials and re-run. Once the app exists, open its settings and under **Display information** upload [`public/bot-avatar.png`](../public/bot-avatar.png) as the logo, with badge background color `#f3efe6`.

#### Slack (optional)

1. Go to [api.slack.com/apps](https://api.slack.com/apps) and click **Create New App → From scratch**
2. Name it (e.g. "Yak") and select your workspace
3. Go to **OAuth & Permissions** and add these bot token scopes:
   - `chat:write`
   - `app_mentions:read`
   - `channels:history`
   - `users:read` -- required. Yak looks up every sender and ignores guests and Slack Connect users from other organizations. Without this scope every lookup fails and Yak ignores everyone
   - `im:history` -- lets Yak read direct messages, so people can DM Yak a task instead of mentioning it in a channel
   - `reactions:write` -- lets Yak react 👀 / 🚧 / ✅ / ❌ on your mention for glanceable status
4. Click **Install to Workspace** and authorize
5. Under **Basic Information → Display Information**, upload [`public/bot-avatar.png`](../public/bot-avatar.png) as the app icon, set the short description to *"AI coding agent -- mention me with a task, get a pull request"*, and the background color to `#2f3a42` (Yak ink, dark enough for Slack's white wordmark)
6. Copy the **Bot User OAuth Token** (`xoxb-...`) into `slack_bot_token`
7. Go to **Basic Information** and copy the **Signing Secret** into `slack_signing_secret`
8. Go to **App Home**, enable the **Home Tab** -- this powers the welcome DM Yak sends the first time a user opens Yak in the sidebar. Under **Show Tabs**, also enable the **Messages Tab** and tick **Allow users to send Slash commands and messages from the messages tab**, so people can DM Yak
9. Go to **Interactivity & Shortcuts**, enable interactivity, and set the request URL to `https://{your-domain}/webhooks/slack/interactive` -- this powers the click-to-answer buttons on clarification messages
10. Go to **Event Subscriptions**, enable events, and set the request URL to `https://{your-domain}/webhooks/slack`
11. Subscribe to bot events: `app_mention`, `message.channels`, `message.im`, and `app_home_opened`

Set `slack_workspace_url` (for example `https://acme.slack.com`) in your vault so the dashboard can deep-link tasks back to their originating Slack thread.

See [Channels → Slack](channels.md#slack-optional) for usage and gotchas.

#### Linear (optional)

Yak installs as a Linear **Agent** -- a first-class workspace participant that appears in the assignee picker without consuming a seat.

1. Go to [linear.app/settings/api/applications](https://linear.app/settings/api/applications)
   → **New application**.
   - Name: `Yak`
   - Description: `AI coding agent -- assign me an issue and I'll open a pull request` (this appears in the assignee picker and the install consent screen, so keep it plain)
   - Icon: upload [`public/bot-avatar.png`](../public/bot-avatar.png)
   - Callback URL: `https://{your-domain}/auth/linear/callback`
   - Enable **Webhooks**, set the URL to `https://{your-domain}/webhooks/linear`, and under **App events** tick **Agent session events**.
   - Copy the app's webhook signing secret into `linear_webhook_secret`.
2. Copy `Client ID` and `Client secret` into `linear_oauth_client_id` /
   `linear_oauth_client_secret`.
3. Re-run Ansible so the env vars land in the container.
4. Sign in to the Yak dashboard → **Settings → Linear → Connect Linear**
   and approve the consent screen. A workspace admin must approve -- the install requests `app:assignable` and `app:mentionable` scopes.

See [Channels → Linear](channels.md#linear-optional) for usage and gotchas.

#### Sentry (optional)

1. In your Sentry org, go to **Settings → Developer Settings → Custom Integrations**
2. Click **Create New Integration** → **Internal Integration**
3. Set permissions: **Organization: Read**, **Project: Read**, **Issue & Event: Read** (the first two are required for the Add Repository form to populate the Sentry project dropdown)
4. Set the webhook URL to `https://{your-domain}/webhooks/sentry`
5. Copy the **Token** into `sentry_auth_token`
6. Copy the **Webhook Signing Secret** (under "Webhook Secret" in the integration's Client Secret section) into `sentry_webhook_secret`
7. Set `sentry_org_slug` to your Sentry organization slug
8. Create an issue alert rule whose action notifies this integration, scoped to the issues you want Yak to pick up
9. Map Sentry projects to repos via the `sentry_project` field on each repo in the dashboard

See [Channels → Sentry](channels.md#sentry-optional) for filtering rules and gotchas.

#### Drone CI (optional)

1. Go to your Drone instance at `https://{drone-url}/account`
2. Copy the **Personal Token** into `drone_token`
3. Set `drone_url` to your Drone instance URL (e.g. `https://drone.yourcompany.com`)

Drone has no outbound webhooks -- Yak polls the Drone API every minute for CI results, so no webhook config is required on the Drone side.

See [Channels → Drone CI](channels.md#drone-ci-optional) for usage and gotchas.


### 4. Configure the inventory

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

### 5. Provision

```bash
ansible-playbook ansible/playbook.yml
```

The playbook runs these roles in this order:

| # | Role | What it does |
|---|---|---|
| 1 | `base` | Creates the `yak` user. Configures UFW, fail2ban, swap and automatic security updates. |
| 2 | `docker` | Installs Docker Engine and Compose. |
| 3 | `ssl` | Installs Caddy, gets the certificate, writes the Caddyfile (including the wildcard preview block when DNS-01 is configured) and log rotation. |
| 4 | `github-app` | Creates and installs the GitHub App. Runs only while the three GitHub App credentials are missing. |
| 5 | `mcp-config` | Writes `mcp-config.json` with the MCP servers of the channels you enabled. |
| 6 | `docker-registries` | Writes registry logins for sandboxes. Runs only if `docker_registries` is set. |
| 7 | `channel-slack`, `channel-linear`, `channel-sentry`, `channel-drone` | Each runs only if its credentials are set. |
| 8 | `incus` | Installs ZFS and Incus, creates the `yak-sandbox` network and its ACL, opens the sandbox firewall rules and builds the `yak-base` image. |
| 9 | `mariadb` | Runs MariaDB with persistent storage. |
| 10 | `redis` | Runs Redis with an append-only file and a password. |
| 11 | `yak-broker` | Builds the broker on your machine, installs it with its `systemd` unit and opens the auth proxy port to the sandboxes. |
| 12 | `telemetryd` | Runs telemetryd. |
| 13 | `yak-container` | Pulls the `geocodio/yak` image, drains running work if a container already exists, starts the container, then runs `yak:resume`. |
| 14 | `yak-ssh` | Builds the SSH host binaries, installs the jump `sshd`, wake proxy and terminal bridge, and opens port 2222. |
| 15 | `claude-code-config` | Installs the `yak-claude-login` and `yak-mcp` helpers. |

The first run is slow: the `incus` role builds the sandbox base image from scratch. Later runs skip it until `yak_sandbox_base_version` changes.

Two things you will notice in the output:

- On a first run with blank GitHub App variables, the `github-app` role prints instructions for creating the App. Follow them, fill in the vault and run the playbook again.
- The last role prints a Claude login prompt. Threads do not use that shared login. They run on [Claude accounts](claude-accounts.md), which you add in the dashboard. The System health row "Claude accounts" turns green once one is usable.

## First Sign-in

1. Open `https://yak.example.com` and sign in with Google. Your email domain must be in `google_oauth_allowed_domains`. The first person to sign in to a new Yak is its admin. Admins manage the shared Yak accounts and promote or demote other people in Settings > People.
2. Add the shared Yak account. It does work nobody started (Sentry alerts, Linear automation, flaky test fixes), and people without their own account run on it too. Use a Claude seat that belongs to the team, not your personal one. Choose **Add Yak account** in the setup bar at the top of the page, or go to Settings > Claude accounts > **Add Yak account**.
   1. On your own computer, run `claude setup-token`. It only prints a token and leaves your Claude login alone.
   2. The command opens a sign-in page that uses your browser's current Claude session. Copy the page's address into a private browser window and sign in to the team seat there.
   3. Paste the token into the dialog and choose **Connect**.

   To run the command on the server instead, over SSH:

   ```bash
   ssh <your-server> docker exec -it yak claude setup-token
   ```

   Logins for shared MCP servers still use the host-side login flow, not this dialog.
3. Add your own Claude account if you want your threads to run on your plan: **Connect Claude** in the setup bar, or Settings > Claude accounts > **Add account**. Without a usable account a task cannot start a thread. See [Claude accounts](claude-accounts.md) for how accounts are chosen and for how to make someone else an admin. Setting `YAK_SYSTEM_ACCOUNT_ADMIN_IDS` in `yak_extra_env` names admins in the server config instead. When it is set, the first person to sign in is not made an admin automatically.
4. Add your first repository: Repositories > Add repository. Yak clones it with the GitHub App and runs a setup task. See [Repositories](repositories.md).
5. Start a thread on that repository. See [Threads](threads.md).

## Verify the Install

Open `https://yak.example.com/health`. The System section lists one row per check and the Channels section one per enabled channel. A healthy host shows these System rows green:

| Row | Healthy means |
|---|---|
| Queue Worker | Queue workers are running in the container. |
| Redis | Redis answers a ping. |
| Incus Daemon | The container can reach the host's Incus socket. |
| Sandbox Base Template | The `yak-base` image exists at the current version. |
| Disk Space | The root disk is below its warning level. |
| Webhook Signatures | No webhook was rejected for a bad signature. |
| Repositories Reachable | Yak can fetch every repository you added. |

Voiceover reads as off without an ElevenLabs key, and the two shared-login rows described above can fail. Neither is a problem. The same checks run from the command line:

```bash
docker exec -u www-data yak php artisan yak:healthcheck
```

The scheduler runs them every 15 minutes. When a check fails, Yak posts to the Slack channel in `slack_alert_channel`. Each failing check alerts at most once per 24 hours, followed by one recovery message.

On the host, check that the processes behind threads are up:

```bash
docker ps --format '{{.Names}}'          # yak, yak-mariadb, yak-redis, yak-telemetryd
systemctl is-active yak-broker yak-wake yak-termbridge yak-jump-sshd
docker exec yak pgrep -fa 'yak:thread-drivers|yak:credential-socket|reverb:start'
```

Then run a real turn: start a thread from the dashboard with a small request such as "Add a comment to the README explaining what this repo does". If the thread streams output and opens a PR, the broker, runner, auth proxy and your Claude account all work. If it does not start, see [Troubleshooting](troubleshooting.md).

For each enabled channel, trigger a test event:

- **Slack**: mention `@yak` in a channel.
- **Linear**: assign a test issue to Yak (the OAuth app appears in the assignee picker).
- **Sentry**: trigger a test alert rule.
- **GitHub**: push a commit to a `yak/test-*` branch.

Each event should create a task. Check the task log under Operations in the dashboard, or the thread list.

## Updating Yak

### Routine updates

CI builds a new image on every push to `main` and tags it `geocodio/yak:latest` and `geocodio/yak:<commit sha>`. To deploy:

```bash
git pull
ansible-playbook ansible/playbook.yml --tags yak-container
```

To deploy one version:

```bash
ansible-playbook ansible/playbook.yml --tags yak-container -e yak_image_tag=abc1234
```

The `yak-container` tag also runs the `redis` and `telemetryd` roles and the `claude-code-config` helpers. It does not rebuild the broker or the SSH host binaries. After you pull changes under `broker/`, or change a role, run the full playbook, or `--tags yak-broker,yak-ssh` for those two. Before it recreates the container, the playbook runs `yak:drain`, so in-flight work finishes or is force-failed and resumed. See [Deploys and draining](operations.md#deploys-and-draining).

### Adding a channel

1. Add the channel's credentials to `ansible/vault/secrets.yml`.
2. Run `ansible-playbook ansible/playbook.yml`.
3. Ansible regenerates the MCP config, updates the container environment and restarts the container.
4. Set the external service's webhook URL. See [Channels](channels.md).

### Removing a channel

Clear the channel's credentials in the vault (set them to empty strings) and run the playbook again. Webhook routes for disabled channels return 404. Tasks from that channel stay in the database.

### Rotating secrets

```bash
ansible-vault edit ansible/vault/secrets.yml
ansible-playbook ansible/playbook.yml --tags secrets
```

Do not rotate `yak_app_key` or `yak_session_store_key`: both encrypt data already stored.

### Re-running a repository's setup

If a repository's dev environment changes (new Docker services, a different database), click Re-run setup on the repository's page, or run:

```bash
docker exec -u www-data yak php artisan yak:setup-repo acme-storefront
```

See [Repositories](repositories.md#re-running-setup).

## Previews

Thread previews and branch deployments use wildcard subdomains of `yak_domain`, such as `t-<random>.yak.example.com`. See [Previews](previews.md#branch-deployments) for what they are. Two things on the host make them work.

### Wildcard DNS

Add a wildcard record for `*.yak.example.com` that points at the same server as the main `yak_domain` record. Check it with `dig +short anything.yak.example.com`.

### DNS-01 TLS and a provider token

Wildcard certificates need the DNS-01 challenge, and Caddy needs a provider plugin in its binary.

1. Set `caddy_dns_provider` in `ansible/group_vars/yak.yml` to one of [Caddy's DNS providers](https://github.com/caddy-dns) (for example `cloudflare`, `route53` or `digitalocean`). The default is `cloudflare`.
2. Add the provider's API token to the vault:

   ```yaml
   caddy_dns_provider_api_token: "<token with edit access to the yak_domain zone>"
   ```

3. Run the full playbook. The `ssl` role downloads a Caddy binary with the plugin and enables the wildcard block.

Without both values the Caddyfile serves the dashboard only, and previews do not work.

## Where To Go Next

- [Operations](operations.md): components, workers, scheduled commands, backups
- [Claude accounts](claude-accounts.md): who runs on which account
- [Channels](channels.md): per-channel configuration and usage
- [Repositories](repositories.md): adding and managing repositories
- [Architecture](architecture.md): how Yak works
- [Troubleshooting](troubleshooting.md): common problems
