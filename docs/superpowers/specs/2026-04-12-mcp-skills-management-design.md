# MCP Servers & Skills Management UI

Design date: 2026-04-12

## Problem

The Yak Claude Code agent needs access to user-scoped OAuth MCP servers (Notion, Figma, Sentry, Linear) and installable skill plugins to one-shot tasks effectively. Today, configuring these requires SSH access to the Yak server and manual CLI invocations. OAuth tokens expire periodically with no visibility or alerting, causing silent task failures.

## Scope

- **In scope:** User-scoped OAuth MCP servers (add, remove, re-authenticate, health monitoring), skill plugin management (install, uninstall, enable/disable, blocklist), and reauth alerting via existing notification channels.
- **Out of scope:** Project-scoped MCPs (`.mcp.json` / `--mcp-config`) which are managed via Ansible and the project repo. Claude Code permissions/settings (`.claude/settings.local.json`). Per-skill toggling within a plugin (Claude Code doesn't support granular enable/disable within a plugin -- the blocklist is a separate kill-switch mechanism, not per-plugin skill selection).

## Architecture

### Data Flow

```
Yak Web UI (Livewire)
    | user action (add MCP / install skill / toggle)
    v
Laravel Service (McpManager / SkillManager)
    | Process::run('claude ...')
    v
Claude Code CLI (inside same Docker container)
    | reads/writes
    v
/home/yak/.claude/ (persistent volume)
    | picked up by
    v
ClaudeCodeRunner.php (next task execution)
```

### Key Design Decision: Approach D (Control Protocol)

Three alternative approaches were investigated via POC spikes (see `docs/superpowers/research/mcp-oauth-approach-{a,b,c}.md`):

- **A (Proxied CLI):** Dead. Claude Code hardcodes `http://localhost:<port>/callback` as the redirect URI. Rewriting it returns `400 invalid_redirect_uri` from the OAuth provider. Confirmed empirically.
- **B (Yak as OAuth client):** Works. Yak reimplements MCP OAuth (DCR + PKCE), injects tokens into `$CLAUDE_CONFIG_DIR/.credentials.json`. Fragility 6/10, ~5 days effort. Proven end-to-end against mock server.
- **C (Terminal bridge):** Wrong tool for this job, but during investigation discovered **Approach D**.
- **D (Control protocol):** Claude Code exposes `mcp_authenticate` and `mcp_oauth_callback_url` subtypes over `--input-format stream-json --output-format stream-json`, purpose-built for remote/headless environments. Fragility 2/10, ~2 days effort.

Approach D is the clear winner: less code, less coupling to Claude Code internals, and Claude Code handles token storage and refresh natively.

### How the OAuth Flow Works (Approach D)

The Claude Code binary (2.1.x) contains explicit support for headless OAuth:

1. Yak spawns `claude --input-format stream-json --output-format stream-json`
2. Yak sends `{ subtype: "mcp_authenticate", serverName: "<name>" }` on stdin
3. Claude Code performs Dynamic Client Registration against the MCP server
4. Claude Code responds with `{ authUrl: "https://...", requiresUserAction: true }`
5. Yak surfaces `authUrl` in the web UI as a clickable link
6. User opens the link, completes OAuth in their browser
7. The browser redirects to `http://localhost:<port>/callback?code=...` which shows a connection error (expected -- localhost points at the user's machine, not the Yak server)
8. User copies the full URL from their browser's address bar and pastes it into Yak's UI
9. Yak sends `{ subtype: "mcp_oauth_callback_url", serverName: "<name>", callbackUrl: "<pasted-url>" }` on stdin
10. Claude Code extracts the authorization code, exchanges it for tokens, persists them natively to `/home/yak/.claude/.credentials.json`
11. Done. Next `claude -p ...` invocation sees the MCP as authenticated.

Additional control subtypes available: `mcp_clear_auth`, `mcp_reconnect`, `mcp_toggle`, `mcp_set_servers`.

### Skills/Plugins Management

Skills use the `claude plugins` CLI directly:

- `claude plugins install <name>` / `claude plugins install <name>@<marketplace>`
- `claude plugins enable <name>` / `claude plugins disable <name>`
- `claude plugins list`
- `claude plugins uninstall <name>` (to be confirmed -- may be `remove`)

Local/custom skills (e.g. from a git repo or local path) use the plugin's own `install.sh` which creates symlinks under `~/.claude/skills/`.

Config files managed by Claude Code:
- `~/.claude/plugins/installed_plugins.json` — plugin registry (version 2 format)
- `~/.claude/plugins/blocklist.json` — skills prevented from loading
- `~/.claude/plugins/cache/` — downloaded plugin code
- `~/.claude/plugins/known_marketplaces.json` — marketplace config

## UI Design

### Navigation

Two new items in the settings sidebar (`resources/views/components/settings/layout.blade.php`):

```
Profile
Security
Appearance
MCP Servers    <- new
Skills         <- new
```

Routes in `routes/settings.php`:
- `/settings/mcp-servers` -> `App\Livewire\Settings\McpServers`
- `/settings/skills` -> `App\Livewire\Settings\Skills`

Access: any authenticated Yak user (single-tenant deployment).

### MCP Servers Screen (`/settings/mcp-servers`)

**Server list:** Each row displays:
- Name (e.g. "Notion")
- URL (e.g. `https://mcp.notion.com/mcp`)
- Status badge:
  - `Connected` (green) -- healthy, tokens valid
  - `Needs Reauth` (amber) -- tokens expired or 401
  - `Error` (red) -- server unreachable or config broken
  - `Not Authenticated` (gray) -- added but OAuth never completed
- Actions: "Re-authenticate" button, "Remove" button

**Add MCP Server:** Button opens a Flux modal.

Step 1 -- Server details:
- Name (text input)
- Server URL (text input)
- Submit registers via `claude mcp add --transport http <name> <url>`

Step 2 -- OAuth authorization (modal transitions):
- Clickable link to the `authUrl` returned by `mcp_authenticate`
- Instructions: "After authorizing, your browser will show a connection error. Copy the full URL from the address bar and paste it below."
- Text input for pasting the callback URL
- "Complete Authorization" submit button
- Sends `mcp_oauth_callback_url` to the waiting subprocess

Step 3 -- Success or failure:
- On success: modal shows confirmation, server list refreshes with `Connected` badge
- On timeout (subprocess dies or user abandons): modal shows error with "Try Again" button. The subprocess is killed after 5 minutes of inactivity. Server stays in `Not Authenticated` state.
- On OAuth error (user denies, provider error): modal shows the error message from Claude Code's response. User can retry.

**Re-authentication:** Same flow as steps 2-3 (server already registered).

**Remove:** Runs `claude mcp remove <name>`, refreshes list. Confirmation dialog.

### Skills Screen (`/settings/skills`)

**Plugin list:** Each row displays:
- Plugin name (e.g. "superpowers", "geocodio-copywriting")
- Source -- "marketplace" or local path
- Version / git commit SHA
- Enabled/disabled toggle (calls `claude plugins enable/disable`)
- Remove button (calls `claude plugins uninstall`)

Expanding a row shows individual skills within the plugin (parsed from SKILL.md files): name + one-line description. Read-only -- toggling is at the plugin level.

**Install Plugin:** Button opens a Flux modal with two tabs.

Tab 1 -- From Marketplace:
- Plugin name text input
- Optional marketplace selector (from `known_marketplaces.json`)
- Submit runs `claude plugins install <name>`

Tab 2 -- From Local Path:
- Absolute path input (path on the Yak server, e.g. `/home/yak/agent-skills/geocodio-video`)
- OR git URL input -- Yak clones to `/home/yak/plugins/<name>/` then runs install
- Submit runs the plugin's `install.sh` or creates symlinks

**Blocklist:** Section at the bottom showing `blocklist.json` entries. Add/remove skills that should never load regardless of plugin state.

## Backend Services

### McpManager Service (`app/Services/McpManager.php`)

Wraps all Claude Code MCP CLI interactions:

- `listServers(): Collection` -- parses `claude mcp list` output
- `addServer(string $name, string $url): void` -- runs `claude mcp add`
- `removeServer(string $name): void` -- runs `claude mcp remove`
- `authenticate(string $name): AuthSession` -- spawns stream-json subprocess, sends `mcp_authenticate`, returns auth URL and holds subprocess handle
- `completeAuth(AuthSession $session, string $callbackUrl): void` -- sends `mcp_oauth_callback_url` to waiting subprocess
- `clearAuth(string $name): void` -- sends `mcp_clear_auth`

All CLI invocations set `CLAUDE_CONFIG_DIR` to the path of the persistent volume (derived from config, matching the value used by `ClaudeCodeRunner`).

### SkillManager Service (`app/Services/SkillManager.php`)

Wraps Claude Code plugin CLI interactions:

- `listPlugins(): Collection` -- parses `claude plugins list` output
- `installFromMarketplace(string $name, ?string $marketplace): void`
- `installFromPath(string $path): void` -- runs install.sh or symlinks
- `installFromGit(string $url, string $name): void` -- clones + installs
- `uninstall(string $name): void`
- `enable(string $name): void`
- `disable(string $name): void`
- `getBlocklist(): array` -- reads `blocklist.json`
- `addToBlocklist(string $skill): void`
- `removeFromBlocklist(string $skill): void`

### Health Check Job (`app/Jobs/CheckMcpHealthJob.php`)

Scheduled every 15 minutes via Laravel's scheduler:

1. Runs `McpManager::listServers()` to get current status
2. Compares against last known status (cached in `cache:store` or a simple JSON file)
3. If any server transitions to `Needs Reauth` or `Error`:
   - Sends notification through Yak's existing notification channels (Slack, etc.)
   - Updates cached status
4. Status is also pulled on-demand when the MCP Servers settings page loads

### Integration with Existing HealthCheckService

`app/Services/HealthCheckService.php` already reads MCP config and displays status at `/health`. The new `McpManager::listServers()` should power both the settings page and the health page, replacing the current file-parsing logic with the more authoritative `claude mcp list` output.

## Testing Strategy

- **McpManager:** Pest feature tests using `Process::fake()` to mock `claude` CLI responses. Test the full lifecycle: add -> authenticate -> complete auth -> list -> remove.
- **SkillManager:** Same `Process::fake()` pattern for plugin CLI commands.
- **Livewire components:** Pest Livewire tests for McpServers and Skills components -- form validation, modal state transitions, error handling.
- **Health check job:** Test state transition detection and notification dispatch.
- **Stream-json handshake:** One integration test (not faked) that runs `claude --input-format stream-json` in a sandboxed `CLAUDE_CONFIG_DIR` to confirm the control protocol handshake works. This test pins the Claude Code version and serves as a canary against protocol changes.

## Open Questions / Risks

1. **Stream-json handshake sequencing:** Agent C's probe found that a bare `control_request` without a prior user message produced no response. The exact startup sequence needs to be confirmed during implementation -- likely requires sending an initial message and waiting for a `system init` event before issuing control requests. This is the first implementation task and blocks MCP auth.
2. **`claude mcp list` hang on expired tokens:** Agent B found that `claude mcp list` hangs indefinitely when a server returns 401 instead of timing out cleanly. The health check job needs a hard timeout (e.g. 30 seconds) to avoid blocking the scheduler.
3. **Local skill installation:** The `install.sh` pattern from custom skill repos (like `agent-skills/`) varies per plugin. Yak should validate the script exists and runs cleanly, but can't guarantee all plugins follow the same convention.
4. **Concurrent writes:** During OAuth completion, both Yak's subprocess and future `claude -p` invocations could write to `.credentials.json`. The stream-json approach (D) avoids this since Claude Code owns the write, but timing during health checks needs care.
