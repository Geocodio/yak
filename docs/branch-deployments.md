# Branch Deployments

Every open PR on an opted-in repo gets a live preview URL. Click it, sign in with your OAuth-allowed Google account, and see the change running exactly as a fresh clone would.

## What you get

- Unique URL per branch: `<repo>-<branch>.<hostname>`
- Single-sign-on via Google OAuth against the domain allowlist
- Isolated container per branch. Each preview has its own database, its own files, everything.
- Updated automatically on every push
- Destroyed automatically when the PR closes or merges

## Lifecycle

1. You open a PR on an opted-in repo.
2. Yak creates a `BranchDeployment`, provisions a container from the repo's per-branch template snapshot, and checks out the PR head.
3. GitHub shows a "Deployments" entry on the PR with a "View deployment" button.
4. You or a reviewer clicks the button. If the preview has been idle, the request holds for a few seconds while the container wakes.
5. Every push to the branch updates the preview in place (or marks it dirty to be refreshed on next wake if hibernated).
6. After 15 minutes of no traffic, the container hibernates. Next request wakes it again.
7. When the PR is closed, merged, or the branch is deleted, the preview is torn down.
8. Preview state never lives longer than 30 days of idle, regardless of PR state.

## First-hit timing

- Fresh deployment (PR just opened): up to ~2 minutes for the initial provision
- Warm deployment (someone hit it recently): sub-second
- Hibernated deployment (15+ minutes idle): 5 to 15 seconds on the first hit. A loading shim appears if the boot takes longer than a few seconds.

## Sharing with people outside the OAuth allowlist

Operators can mint a public share link from the deployment detail page. The link is time-boxed (default 7 days, max 30) and bypasses OAuth for anyone who has the URL.

1. Open the Yak dashboard and navigate to Deployments
2. Click into the deployment you want to share
3. Click "Generate share link", pick a duration, copy the URL

Share links work like this: the first hit sets a short-lived cookie scoped to the preview hostname, and subsequent requests carry the cookie so in-app navigation, AJAX, and asset loading all keep working without the `_share/<token>/` prefix.

Share links should be treated as secrets. To invalidate a link early, click "Revoke" on the deployment's share toggle.

## Opting a repo in

In the repository row on the Yak dashboard, toggle "Deployments enabled". The next PR opened on that repo gets a preview.

### The preview manifest

Every repo's manifest describes how to boot the dev environment as a preview:

| Field | Purpose |
|---|---|
| `port` | Container port serving HTTP |
| `health_probe_path` | Path that returns 2xx when the app is ready |
| `cold_start` | Command to bring services up from a stopped container |
| `checkout_refresh` | Command to run after `git fetch && git checkout $sha` |
| `wake_timeout_seconds` | Overall cap on wake + refresh time |

SetupYakJob authors the manifest automatically based on how it booted the dev env. Edit it later via the repository settings page.

Alternative: drop a `.yak/preview.sh` script into your repo. If present, Yak runs `/app/.yak/preview.sh $SHA` after checkout instead of the manifest's `checkout_refresh`.

## Idle hibernation and resource caps

Yak runs up to 6 deployments concurrently (configurable). Hibernated deployments consume only disk (ZFS copy-on-write keeps each one tiny).

When someone hits a hibernated preview:

1. If there's a free running slot, the preview starts.
2. If we're at the cap, Yak evicts the deployment with the oldest last-accessed time (provided it has been idle for at least 5 minutes).
3. If every running deployment was active within the last 5 minutes, the new request gets a "server at capacity" response.

## Troubleshooting

### "Preview unavailable" page appears

The deployment failed to boot. Click through to the Yak dashboard's deployment detail page to see the failure reason and which phase stalled (`cold_start`, `checkout_refresh`, or `health_probe`).

Common causes:

- The repo's preview manifest has a broken `cold_start` command
- Migrations fail on this branch
- The health probe path 404s on the new code

### Template is stale after a dependency bump

Use the "Rebuild from latest template" button on the deployment detail page, or "Rebuild all deployments" on the repository page for a bulk action. Rebuilds destroy container state: the DB and any local files reset.

## Security

- Previews sit on the `yak-sandbox` network bridge, firewalled from the Yak app DB and internal services
- Previews cannot reach production data or credentials
- Container state lives on a ZFS dataset that is destroyed with the container
- Secrets injected via `preview_env_overrides` come from Ansible vault at start time, never baked into snapshots
- Share tokens are stored hashed (SHA-256); the raw token is shown once at mint time and never persisted
