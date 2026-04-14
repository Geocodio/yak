# Linear OAuth2 Refactor Plan

Migrate Yak's Linear integration for **outbound notifications and state
updates** from a personal API key to a per-workspace OAuth2 app with
`actor=app`, so comments and state changes post as "Yak" rather than the
installer. Inbound webhooks continue to use the existing HMAC signing
secret. The Claude Code MCP consumer keeps using a personal API key
(Linear's MCP server requires one — see section 7).

Single commit, single deploy. Yak is single-tenant — one Geocodio
instance — so no coexistence window is needed between old and new
auth paths.

## Summary

Add a `linear_oauth_connections` table (one row) + `LinearOauthConnection`
model storing encrypted access/refresh tokens, `expires_at`, workspace
metadata, and `disconnected_at`. A `LinearOAuthService` handles the
Authorization Code flow (`actor=app`), token exchange, and refresh
(lazy, 60s skew). A `/settings/linear` Livewire page starts the flow and
shows connection state. `LinearNotificationDriver` reads the current
connection on each send and calls Linear GraphQL with
`Authorization: Bearer <token>`; on refresh failure the row is marked
`disconnected_at` and subsequent sends skip silently (no more legacy
fallback). Webhook verification is unchanged — the OAuth app's signing
secret drops into `YAK_LINEAR_WEBHOOK_SECRET` in place of the personal
integration's secret.

All in one commit: migration, model, service, controller, Livewire page,
blade, routes, config, driver swap, ansible updates, docs. Legacy
`YAK_LINEAR_API_KEY` stays in the env only for the MCP consumer
(renamed to `YAK_LINEAR_MCP_API_KEY` to make intent explicit).

---

## 1. Architectural decisions

### (a) Where OAuth tokens live
**Single-row `linear_oauth_connections` table, encrypted at rest via
Laravel's `encrypted` cast.** Matches the existing
`GitHubInstallationToken` pattern in this codebase
(`app/Models/GitHubInstallationToken.php` + its migration).

Schema (migration `create_linear_oauth_connections_table`):

- `id`
- `workspace_id` (string, Linear's `organization.id` UUID, unique)
- `workspace_name` (string, for UI display)
- `workspace_url_key` (string nullable, e.g. `geocodio`)
- `access_token` (text, `encrypted`)
- `refresh_token` (text, `encrypted`, nullable)
- `expires_at` (datetime)
- `scopes` (json, granted scopes returned by Linear)
- `actor` (string: `user` or `app`)
- `app_user_id` (string, nullable — Linear's synthetic OAuth-app user ID)
- `installer_user_id` (string, nullable — Linear user who authorized)
- `created_by_user_id` (FK to `users.id`, nullable)
- `disconnected_at` (datetime, nullable — set when refresh fails)
- `timestamps`

One row is expected, but `workspace_id` is unique so we can't
accidentally end up with two rows for the same workspace.

### (b) Detecting and refreshing expired tokens
`LinearOauthConnection::freshAccessToken(LinearOAuthService $svc): string`:

1. If `expires_at->subSeconds(60)->isFuture()` → return current `access_token`.
2. Else `$svc->refresh($this)` POSTs to `https://api.linear.app/oauth/token`
   with `grant_type=refresh_token`, `refresh_token=<refresh>`, `client_id`,
   `client_secret`.
3. On success, update `access_token`, `expires_at`, and (if rotated)
   `refresh_token`, save.
4. On `invalid_grant` (refresh expired or revoked): set `disconnected_at`,
   log at `warning`, throw `LinearOAuthRefreshFailedException`. The driver
   catches and returns silently — comment does not post.

Refresh happens lazily inside `LinearNotificationDriver::send()`. No
scheduled job.

### (c) Bootstrap: webhooks work without an active OAuth connection
Inbound webhook handling depends only on `webhook_secret`. We change
`Channel::REQUIRED_CREDENTIALS['linear']` from `['api_key', 'webhook_secret']`
to `['webhook_secret']` so the webhook route registers correctly before
anyone has completed the OAuth flow. Outbound calls from the notification
driver handle the "no connection yet" case by logging a one-shot warning
and returning — no comment posted, task still progresses.

### (d) MCP consumer stays on a personal API key
Linear's MCP server (consumed by Claude Code during agent runs) expects a
personal API key in `LINEAR_API_KEY` env. It's a read-side tool used by
Claude itself — not something that writes comments as the user. We rename
the env var from `YAK_LINEAR_API_KEY` → `YAK_LINEAR_MCP_API_KEY` so the
purpose is explicit and so it's unambiguous that the personal key is
*not* what posts user-visible Linear comments anymore. See §7.

---

## 2. Files to change / create (everything below ships in one commit)

### New files

| File | Purpose |
|---|---|
| `database/migrations/2026_04_14_XXXXXX_create_linear_oauth_connections_table.php` | Schema described in §1(a). |
| `app/Models/LinearOauthConnection.php` | Eloquent model; `encrypted` casts on tokens; `freshAccessToken(LinearOAuthService $svc): string`; `isExpired(int $skewSeconds = 60): bool`; `markDisconnected(): void`. |
| `database/factories/LinearOauthConnectionFactory.php` | For tests. |
| `app/Services/LinearOAuthService.php` | `authorizeUrl(string $state): string`, `exchangeCode(string $code): LinearOauthConnection`, `refresh(LinearOauthConnection): void`, `revoke(LinearOauthConnection): void`, `fetchViewer(string $accessToken): array`. |
| `app/Exceptions/LinearOAuthRefreshFailedException.php` | Typed exception for refresh failures. |
| `app/Livewire/Settings/LinearConnection.php` | Livewire settings page: shows connection state (workspace name, actor, expires at), "Connect Linear" and "Disconnect" actions. |
| `resources/views/livewire/settings/linear-connection.blade.php` | Flux UI view. |
| `app/Http/Controllers/Auth/LinearOAuthController.php` | `redirect()` (generate + store `state` in session, 302 to authorize URL) and `callback()` (verify `state`, exchange code, persist row, flash toast). |
| `tests/Feature/Settings/LinearConnectionTest.php` | Redirect builds correct URL; callback happy path; state mismatch → 403; Linear error → flash + no row; disconnect removes row. |
| `tests/Feature/LinearOAuthRefreshTest.php` | `freshAccessToken` within skew, refresh path, rotated refresh token, `invalid_grant` flow. |
| `tests/Feature/LinearNotificationDriverOAuthTest.php` | Driver sends `Bearer` header when connection row exists; does nothing when no row; refreshes expired access tokens; marks disconnected on refresh failure. |

### Modified files

| File | Change |
|---|---|
| `app/Drivers/LinearNotificationDriver.php` | Replace `getApiKey()` with `resolveAccessToken(): ?string` that loads the connection, calls `freshAccessToken()` through `LinearOAuthService`, and returns the current OAuth access token (or null). Replace the `Authorization: <apiKey>` header with `Authorization: Bearer <accessToken>` on both `commentCreate` and `issueUpdate` calls. Catch `LinearOAuthRefreshFailedException` and return silently. |
| `app/Http/Controllers/Webhooks/LinearWebhookController.php` | No change (webhook verification is unchanged). Already persists `linear_issue_id` after commit `4fec9ea`. |
| `config/yak.php` | Under `channels.linear`: remove `api_key`; add `oauth_client_id`, `oauth_client_secret`, `oauth_redirect_uri`, `oauth_scopes` (default `'read,write,issues:create,comments:create'`), `mcp_api_key` (for the MCP consumer only). Keep `webhook_secret`, `done_state_id`, `cancelled_state_id`. |
| `app/Channel.php` | `REQUIRED_CREDENTIALS['linear']` → `['webhook_secret']`. |
| `app/Providers/ChannelServiceProvider.php` | No change. |
| `routes/web.php` | `GET /auth/linear` → `LinearOAuthController@redirect`, `GET /auth/linear/callback` → `LinearOAuthController@callback`, inside the `auth` middleware group. |
| `routes/settings.php` (or wherever settings livewire routes live) | `Route::livewire('settings/linear', LinearConnection::class)->name('settings.linear');` |
| Settings sidebar nav (see `resources/views/components/layouts/settings.blade.php` or similar) | Add "Linear" item. |
| `tests/Feature/LinearWebhookTest.php` | `enableLinearChannel()` helper: drop `api_key` from the set config (required-credentials are now `webhook_secret` only). For tests exercising notification assertions (`LinearNotificationDriver posts comments`, etc.), create a `LinearOauthConnection::factory()` row and fake the GraphQL endpoint. Assertions on `Authorization:` change from raw string to `Bearer `-prefixed. |
| `tests/Feature/NotificationDriverTest.php` | Any Linear driver coverage switches to the OAuth row pattern (same change as above). |
| `tests/Feature/ChannelDriverTest.php` + `ChannelTest.php` | Update any "required credentials for Linear" assertions. |

### Config / ansible / env

| File | Change |
|---|---|
| `ansible/vault/secrets.example.yml` | **Remove** `linear_api_key`. **Add** `linear_oauth_client_id`, `linear_oauth_client_secret`, `linear_oauth_redirect_uri`, `linear_mcp_api_key`. Keep `linear_webhook_secret`. |
| `ansible/vault/secrets.yml` (prod vault, edited via `ansible-vault edit`) | Same keys as above. The existing `linear_api_key` value gets renamed to `linear_mcp_api_key` so the same personal key continues to serve MCP. |
| `ansible/roles/yak-container/templates/env.j2` | Remove `YAK_LINEAR_API_KEY=…`. Add `YAK_LINEAR_OAUTH_CLIENT_ID`, `YAK_LINEAR_OAUTH_CLIENT_SECRET`, `YAK_LINEAR_OAUTH_REDIRECT_URI`, `YAK_LINEAR_OAUTH_SCOPES` (optional override), `YAK_LINEAR_MCP_API_KEY`. |
| `ansible/roles/mcp-config/templates/mcp-config.json.j2` | Point the Linear MCP server's `LINEAR_API_KEY` env at `{{ linear_mcp_api_key }}` instead of `{{ linear_api_key }}`. |
| `ansible/playbook.yml` | Update the `when:` guard on the Linear channel setup task: require `linear_webhook_secret AND linear_oauth_client_id AND linear_oauth_client_secret`. MCP task keeps its own guard on `linear_mcp_api_key`. |

### Docs

| File | Change |
|---|---|
| `docs/channels.md` → Linear section | Rewrite "Setup" step-by-step: (1) create OAuth app in Linear, (2) set redirect URI, (3) enable `Actor: app` toggle, (4) pick scopes `read,write,issues:create,comments:create`, (5) copy client_id + client_secret + signing secret into vault, (6) generate separate personal API key for MCP, (7) re-run ansible, (8) visit `/settings/linear` to authorize. Rewrite "why no extra seat" callout — OAuth app user is free. Add "MCP still uses a personal key and that's fine because MCP is read-side" callout. |
| `docs/setup.md` | Update the "Linear (optional)" block to reflect the OAuth-app path. Keep the explicit MCP key step. |
| `README.md` | If it lists "Linear API key" as a required secret, update to "Linear OAuth client + app signing secret + MCP personal key". |
| `.env.example` (if present) | Mirror `env.j2` changes. |

---

## 3. External setup the user performs once

Document in `docs/channels.md`:

1. **Register the OAuth app**: Linear → Settings → API → OAuth applications
   → "New application".
   - Name: `Yak` (+ icon)
   - Redirect URI: `https://{your-domain}/auth/linear/callback`
2. **Enable "Actor: app"** on the app detail page. If this toggle isn't
   on, the `actor=app` query param on authorize is rejected. Confirm
   exact label in Linear's current UI at implementation time.
3. **Select scopes**: `read`, `write`, `issues:create`, `comments:create`.
   Do NOT include `admin` — Linear forbids it with `actor=app`. `write`
   covers both `commentCreate` and `issueUpdate`; the granular scopes are
   defensive.
4. **Configure the webhook** on this OAuth app: URL
   `https://{your-domain}/webhooks/linear`, subscribe to `Issue` events,
   copy the app's signing secret into `linear_webhook_secret` in the
   vault.
5. **Copy OAuth credentials** to the vault:
   - `linear_oauth_client_id` — from the app detail page.
   - `linear_oauth_client_secret` — from the app detail page.
   - `linear_oauth_redirect_uri` — e.g. `https://yak.geocod.io/auth/linear/callback`.
6. **Generate a personal API key for MCP** (Linear → Settings → API →
   Personal API keys) and put it in `linear_mcp_api_key` in the vault.
   This replaces today's `linear_api_key`. It's read-side only.
7. **Run ansible** to push the new env.
8. **Authorize from Yak**: log in → `/settings/linear` → "Connect Linear"
   → pick the workspace → redirected back with a toast.
9. **Rotating OAuth `client_secret`**: new secret in Linear console →
   update vault → re-run ansible. On the next refresh Linear will reject
   the old secret; the row flips to `disconnected_at`, and the user
   reconnects via `/settings/linear`.

---

## 4. Single deploy plan

Everything above ships as one PR / one deploy. Ordering within the PR:

1. Migration (no data migration — personal key is discarded; OAuth is
   authorized interactively post-deploy).
2. Model + factory + service + exception.
3. OAuth controller + routes.
4. Livewire settings page + view + sidebar nav.
5. Config/channel changes, driver swap.
6. Tests updated/added.
7. Ansible: env.j2, vault example, mcp-config template, playbook guard.
8. Docs: channels.md, setup.md, README.md, .env.example.

**Post-deploy runbook** (manual, documented in the PR description):

1. Create the Linear OAuth app per §3 steps 1–4.
2. `ansible-vault edit ansible/vault/secrets.yml` — remove
   `linear_api_key`, add the four new keys, add `linear_mcp_api_key`
   (same personal key as the old `linear_api_key`).
3. `./deploy.sh` — pushes new image, rewrites `/etc/yak/env`,
   recreates container.
4. Visit `https://yak.geocod.io/settings/linear` → "Connect Linear".
5. Add the `yak` label to a throwaway Linear issue, confirm the
   acknowledgment comment appears authored by "Yak".

Rollback: `git revert` + run ansible once the previous env is restored.
Because the OAuth row only persists new data and nothing in the old code
reads from that table, rollback is safe without a down-migration.

---

## 5. Tests

### Adjusted

- `tests/Feature/LinearWebhookTest.php`
  - `enableLinearChannel()` no longer sets `api_key`.
  - Existing "LinearNotificationDriver posts comments" tests create a
    `LinearOauthConnection::factory()` row; assert `Authorization: Bearer <token>`.
  - The `linear_issue_id` context persistence test (already there) is
    unaffected.
- `tests/Feature/NotificationDriverTest.php` — same pattern.
- `tests/Feature/ChannelDriverTest.php` / `ChannelTest.php` — update
  required-credential expectations for Linear.

### New

- `tests/Feature/Settings/LinearConnectionTest.php`
  - Unauthenticated user can't hit `/auth/linear`.
  - `/auth/linear` redirects to `https://linear.app/oauth/authorize` with
    correct `client_id`, `redirect_uri`, `response_type=code`, `scope`,
    `state`, `actor=app`, `prompt=consent`.
  - Callback with mismatched `state` → 403.
  - Callback happy path: `Http::fake` on `/oauth/token` and the viewer
    GraphQL query; persists a row with the right fields.
  - Callback Linear error flashes toast, no row created.
  - Disconnect removes the row.
- `tests/Feature/LinearOAuthRefreshTest.php`
  - `freshAccessToken` returns current token when expiry > 60s away.
  - Refreshes within skew window.
  - Refresh updates `expires_at` + rotates `refresh_token`.
  - `invalid_grant` response sets `disconnected_at`, throws typed
    exception.
- `tests/Feature/LinearNotificationDriverOAuthTest.php`
  - OAuth row present → sends `Authorization: Bearer <access_token>`.
  - No OAuth row (or `disconnected_at` set) → no HTTP call, logs warning.
  - Expired access token triggers refresh then comment post.
  - `issueUpdate` also uses bearer header.

---

## 6. Risks / unknowns to confirm in-flight

- **Comment author attribution with `actor=app`.** Post one comment from
  prod after connecting and eyeball it in Linear web, mobile app, and
  email notification before declaring success.
- **Scope minimums.** Docs list scopes `read`, `write`, `issues:create`,
  `comments:create`, `admin`, `timeSchedule:write`. `write` should cover
  both `commentCreate` and `issueUpdate`, but the docs don't enumerate
  per-mutation. Request the narrower granular scopes alongside `write` to
  be safe; never request `admin` (incompatible with `actor=app`).
- **`viewer.organization.id` under `actor=app`.** Unclear whether the
  `viewer` GraphQL field returns the workspace correctly when the token
  belongs to an app actor. Fallback: read `organizationId` from the first
  incoming webhook and stash it onto the connection then.
- **Workspace picker during authorize.** Unclear whether Linear's OAuth
  screen shows a workspace selector with `actor=app` or auto-targets the
  installer's workspace. Test in Linear sandbox or the Geocodio workspace
  during implementation.
- **`prompt=consent`** should be set explicitly on the authorize URL so
  reconnecting doesn't silently skip the permission screen.
- **Comment rate limits.** OAuth tokens may have different limits than
  personal keys. Watch `X-RateLimit-*` / `Retry-After` in logs after
  rollout.
- **Refresh-token failure surfacing.** First affected user action is a
  silently-missing comment. On `LinearOAuthRefreshFailedException`:
  - log at `warning` with the task id
  - set `disconnected_at`
  - show a persistent banner on `/settings/linear` ("Linear connection
    expired — click to reconnect")
- **MCP flow unaffected.** MCP authenticates with the personal API key
  (`linear_mcp_api_key`), and that's by design. Future work to migrate
  MCP to OAuth is out of scope.

---

## 7. Why MCP keeps a personal API key

The Claude Code Linear MCP server (installed per
`ansible/roles/mcp-config/templates/mcp-config.json.j2`) authenticates
with a Linear **personal** API key via an env var (`LINEAR_API_KEY`). It's
used during agent runs so Claude can look up issues, comments, and
metadata while working on a task. That's:

- **read-side**, not user-visible;
- invoked inside the agent's sandbox, not from a Yak-controlled HTTP
  flow, so there's no place to inject an OAuth token from a DB row;
- orthogonal to the notification/state-update path we're refactoring.

The right solve for MCP → OAuth is a future upstream change in Linear's
MCP server (or a proxy we write). Until then we keep the personal key,
renamed to `YAK_LINEAR_MCP_API_KEY` / `linear_mcp_api_key` so it's
unambiguous that this key is *not* the one that authors comments on
behalf of users.
