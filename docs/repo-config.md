# Repository config

A repository can keep Yak's settings in a `.yak/` directory on its default branch. Changes then go through a PR and review like any other code. Every file is optional.

## Layout

```
.yak/
  config.yml          # behaviour: routing, CI, walkthrough, PR size, co-owner gate, review policy
  preview.yml         # how a branch preview is started and refreshed
  preview.sh          # optional, existing hook; wins over preview.yml's checkout_refresh
  risk-profile.yml    # risk areas used by the co-owner gate and the PR reviewer
  AGENTS.md           # Yak-only agent rules; general guidance stays in the root AGENTS.md / CLAUDE.md
.github/
  CODEOWNERS          # who is asked to co-own a held change (step 4)
```

CODEOWNERS support arrives later. The file is listed here because the co-owner gate will read it.

## Where config comes from

- Yak reads `.yak/` from the head commit of the repository's default branch. It never reads a task branch or a PR branch.
- A missing file, or a missing key, falls back to the value stored in Yak. This is how migration works: a repository with no `.yak/` keeps behaving as it does today.
- Yak reads again after every push to the default branch.
- An invalid file never replaces a valid one. Yak keeps using the last valid version, and the repository page shows the error, the commit that introduced it, and a link to fix it. A file that has never been valid is ignored, and its settings come from Yak.
- Once any valid version of `config.yml` says `co_owner_gate.mode: enforce`, the mode stays `enforce` while the file is broken or unreadable. A typo cannot switch the gate off.

Each file has a JSON Schema. The `yaml-language-server` line at the top of the examples gives editors completion and inline errors:

- `config.yml`: https://raw.githubusercontent.com/Geocodio/yak/main/schemas/config.schema.json
- `preview.yml`: https://raw.githubusercontent.com/Geocodio/yak/main/schemas/preview.schema.json
- `risk-profile.yml`: https://raw.githubusercontent.com/Geocodio/yak/main/schemas/risk-profile.schema.json

## config.yml

```yaml
# yaml-language-server: $schema=https://raw.githubusercontent.com/Geocodio/yak/main/schemas/config.schema.json
version: 1

# One line on what this repository is. Yak's router uses it to pick a repository for a task.
description: Geocoding API, customer dashboard and billing      # max 1000 characters

# Where Yak reads CI results after it pushes a branch. Detected from the repository when absent.
ci: github_actions                                          # github_actions | drone | none

walkthrough:
  # Shown in the walkthrough video's browser bar instead of the preview address.
  public_site_url: https://dash.geocod.io

pull_requests:
  # Above this many changed lines, the PR description gets a "large change" note.
  large_change_lines: 200                                   # at least 1

co_owner_gate:
  # enforce: hold risky Yak changes until someone with write access co-owns them.
  # Once any valid version says enforce, a broken file keeps the gate on.
  mode: enforce                                             # off | enforce

review:
  # Yak reviews every open, non-draft PR on this repository.
  enabled: true
  # Findings on these paths are not posted. Up to 100 globs.
  exclude_paths:
    - "public/build/**"
    - "*.min.js"
    - "database/schema/*.sql"
  approval:
    # off: never approve. shadow: record what Yak would decide. enforce: approve when every rule passes.
    mode: shadow                                            # off | shadow | enforce
    allowed_paths:                                          # only these paths can be approved; empty = none
      - "resources/views/docs/**"
      - "lang/**"
    blocked_paths:                                          # added to Yak's host floor, never replaces it
      - "app/Billing/**"
    required_checks:                                        # check run or commit status names, up to 50
      - tests
      - phpstan
    max_files: 5                                            # 1 to 100
    max_lines: 150                                          # 1 to 5000
    max_risk_score: 30                                      # 0 to 30
    min_confidence: 80                                      # 80 to 100
```

The smallest useful file is:

```yaml
version: 1
co_owner_gate:
  mode: enforce
```

| Key | Meaning |
|---|---|
| `version` | The file format version. Always `1`. Required. |
| `description` | A short description of what the repository is, shown to Yak as context. Up to 1000 characters. |
| `ci` | The CI system Yak reads build results from: `github_actions`, `drone` or `none`. |
| `walkthrough.public_site_url` | The public origin shown in the mock URL bar of walkthrough videos. Up to 255 characters. |
| `pull_requests.large_change_lines` | The number of changed lines above which a PR counts as large. At least 1. |
| `co_owner_gate.mode` | `off` or `enforce`. See the note below. |
| `review.enabled` | Whether Yak reviews pull requests in this repository. |
| `review.exclude_paths` | Path globs Yak leaves out of reviews. Up to 100, each up to 500 characters. |
| `review.approval.mode` | Required when `approval` is set. `off`, `shadow` (record what Yak would decide without acting) or `enforce`. |
| `review.approval.allowed_paths` | Path globs a change must stay within to be approved. Up to 100. |
| `review.approval.blocked_paths` | Path globs that always need a human. Up to 100. They are added to the host floor, never replace it. |
| `review.approval.required_checks` | Check names that must pass before Yak approves. Up to 50. Matched by name. |
| `review.approval.max_files` | The most files a change may touch and still be approved. 1 to 100. |
| `review.approval.max_lines` | The most changed lines a change may have and still be approved. 1 to 5000. |
| `review.approval.max_risk_score` | The highest risk score a change may have and still be approved. 0 to 30. |
| `review.approval.min_confidence` | The lowest review confidence, as a percentage, at which Yak approves. 80 to 100. |

Required checks match by name. A check run or commit status counts if its name equals the entry, whichever app reported it.

Path globs may contain letters, digits, `_`, `.`, `/`, `-`, `*` and `?`.

### The co-owner gate

`co_owner_gate.mode` is parsed and stored today, and the stay-on-enforce rule above already applies to it. The gate itself, which holds risky changes until a code owner co-owns them, arrives in a later release. Setting `enforce` now does not hold any PR.

## preview.yml

```yaml
# yaml-language-server: $schema=https://raw.githubusercontent.com/Geocodio/yak/main/schemas/preview.schema.json
port: 80                                # 1 to 65535
health_probe_path: /up
cold_start: docker compose up -d
checkout_refresh: |
  docker compose exec -T app composer install --no-interaction
  docker compose exec -T app php artisan migrate --force
  docker compose exec -T app npm ci
  docker compose exec -T app npm run build
wake_timeout_seconds: 120                    # at least 1

# Optional. Defaults come from Yak's host config.
cold_start_timeout_seconds: 300
checkout_refresh_timeout_seconds: 900
health_probe_timeout_seconds: 5
```

| Key | Meaning |
|---|---|
| `port` | The port the app listens on inside the preview. 1 to 65535. Required. |
| `health_probe_path` | The path Yak requests to check the preview is up. Starts with a slash. Required. |
| `cold_start` | The command that starts the app from a stopped preview. |
| `checkout_refresh` | The command that refreshes a preview after a new commit. |
| `wake_timeout_seconds` | Seconds to wait for a sleeping preview to wake. At least 1. |
| `cold_start_timeout_seconds` | Seconds to wait for the cold start command to finish. At least 1. |
| `checkout_refresh_timeout_seconds` | Seconds to wait for the checkout refresh command to finish. At least 1. |
| `health_probe_timeout_seconds` | Seconds to wait for the health probe to answer. At least 1. |

If the repository also has an existing `.yak/preview.sh` hook, it wins over `checkout_refresh`.

## risk-profile.yml

```yaml
# yaml-language-server: $schema=https://raw.githubusercontent.com/Geocodio/yak/main/schemas/risk-profile.schema.json
version: 1

areas:                                         # 1 to 100 areas
  - name: Billing and Stripe                    # max 200 characters
    risk: critical                            # low | medium | high | critical | unknown
    paths:                                    # 1 to 50 globs
      - "app/Billing/**"
      - "app/Http/Controllers/Webhooks/StripeWebhookController.php"
    symbols:                                  # may be empty
      - App\Billing\InvoiceCalculator::total
    rationale: >-
      Charges real cards. A wrong rounding rule or a missed webhook state bills
      customers twice or not at all.
    evidence:                                 # at least one
      - "app/Billing/InvoiceCalculator.php:41 rounds per line item"

  - name: API middleware and HTTP kernel
    risk: high
    paths:
      - "app/Http/Middleware/**"
      - "app/Http/Kernel.php"
      - "bootstrap/app.php"
    symbols: []
    rationale: >-
      Runs on every API request. New headers, throttling or auth changes here
      affect every customer at once.
    evidence:
      - "bootstrap/app.php registers the API middleware group"

  - name: Transport and CSP
    risk: medium
    paths: [ "config/cors.php", "app/Http/Middleware/ContentSecurityPolicy.php" ]
    symbols: []
    rationale: A wrong CORS or CSP rule breaks browser clients and the dashboard.
    evidence: [ "config/cors.php allows the dashboard origin only" ]

  - name: Marketing and docs pages
    risk: low
    paths: [ "resources/views/docs/**", "resources/views/marketing/**" ]
    symbols: []
    rationale: Static Blade views with no data access.
    evidence: [ "No controllers under these views read customer data" ]

# Questions the profile could not answer. Any entry here blocks Yak's auto-approval.
unknowns:
  - Is app/Legacy/** still reachable from any route?
```

| Key | Meaning |
|---|---|
| `version` | Always `1`. Required. |
| `areas` | The areas of the codebase and their risk. 1 to 100 entries. Required. |
| `areas[].name` | A unique name for the area. Up to 200 characters. |
| `areas[].paths` | Path globs that belong to the area. 1 to 50, each up to 500 characters. |
| `areas[].symbols` | Functions or classes that belong to the area. May be empty. Up to 100. |
| `areas[].risk` | `low`, `medium`, `high`, `critical` or `unknown`. |
| `areas[].rationale` | Why the area has this risk level. Up to 4000 characters. |
| `areas[].evidence` | Facts from the repository that support the rationale. 1 to 100 entries, each up to 1000 characters. |
| `unknowns` | Questions Yak could not answer and a human should check. Up to 100. Any entry blocks auto-approval. |

Yak can write this file for you. Starting risk profile generation on the repository page opens a PR on the `yak/risk-profile` branch, or updates the open one. Merging the PR approves the profile.

## AGENTS.md

`.yak/AGENTS.md` holds rules for Yak tasks only. General guidance for every coding agent stays in the root `AGENTS.md` or `CLAUDE.md`.

```markdown
# Yak rules for geocodio

These apply to Yak tasks only. General codebase
guidance lives in the root CLAUDE.md.

## Tests
- Never run the full address import in tests. Use the
  `small` fixture set.
- Run `php artisan test --compact --filter=...`,
  never the whole suite.

## UI changes
- Record a walkthrough for any change under
  `resources/js/`.

## Never
- Change API response shapes without a version bump.
- Touch `config/billing.php`.
```

## CODEOWNERS

CODEOWNERS support arrives later, together with the co-owner gate. Use placeholders like these until then:

```
# Last matching line wins.
*                          @your-org/engineering

# Billing needs someone from the billing team.
/app/Billing/              @your-org/billing

# Request pipeline
/app/Http/Middleware/      @your-login @your-org/platform
/bootstrap/app.php         @your-login

# Changes to Yak's own rules
/.yak/                     @your-login
/.github/CODEOWNERS        @your-login
```

## The yak / config check

When a PR touches `.yak/**`, Yak adds a check run named `yak / config` to the PR head commit. It validates the changed files and reports any errors. The check reads only the PR's head commit. Config used by Yak itself still comes only from the default branch.

Make `yak / config` a required status check in branch protection, so a broken file cannot merge. This needs the Checks (Read & Write) permission. See [Accepting new permissions](channels.md#accepting-new-permissions).

## Paths Yak never auto-approves

The host floor is a fixed list of paths that always need a human reviewer, whatever `allowed_paths` says:

- `.yak/**`
- `CODEOWNERS`, `.github/CODEOWNERS` and `docs/CODEOWNERS`
- `AGENTS.md`
- `CLAUDE.md`

`blocked_paths` adds to this list.

## Migrating an existing repository

A repository that was added before `.yak/` existed keeps its settings in Yak. To move them into the repository, either:

- click **Open a config PR** on the repository page, or
- run `php artisan yak:migrate-config` on the server. Add `--repo=<slug>` for one repository and `--dry-run` to see what would be written without opening a PR.

Yak opens a PR on the `yak/config-migration` branch with the `yak` label. The PR reproduces today's effective values. The one difference is that required checks no longer pin a GitHub App ID, because they match by name. The PR body says so.

New repositories get the same thing from setup: it opens a PR on `yak/setup-config` that adds `config.yml` and `preview.yml`. Settings apply once the PR merges.
