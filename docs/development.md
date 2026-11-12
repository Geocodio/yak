# Development

This page is for people working on Yak itself: fixing bugs, adding features, writing a new channel, or improving these docs. To run Yak against your repos, see [Setup](setup.md).

## Local Setup

### Prerequisites

| Tool | Version | Used for |
|---|---|---|
| PHP | 8.5 | The Laravel app. `composer.json` allows `^8.3`, but CI and the project target 8.5. Needs `pdo_mysql` and the `redis` extension (the config uses `phpredis`) |
| Composer | 2.x | PHP dependencies |
| Node | 22 | Frontend build, Playwright, and the runner. CI uses 22. `runner/package.json` allows `>=20` |
| Docker | 24+ | MariaDB (and Redis) through `docker compose` |
| Go | 1.26 | `broker/` and `cli/`, only when you change them |

You do not need Incus, Ansible, the Claude CLI or a Chromium for the Laravel app. Tests replace the sandbox and the broker with fakes.

### Services

| Service | Needed locally? | Notes |
|---|---|---|
| MariaDB | Yes | `docker compose up -d mariadb` starts it on `127.0.0.1:3306` (database, user and password `yak`). A second service, `mariadb-test`, listens on 3307 for tests that want a real MariaDB |
| Redis | For thread turns and some tests | Thread turns talk to the broker through Redis streams (`BrokerStreams`). Sessions, cache and queue use the database. CI runs a Redis 7 service. Tests that do not touch the real streams use a fake |
| Reverb | No | Leave `REVERB_APP_KEY` empty and the thread page polls over HTTP. `composer run dev` does not start it. The browser tests use a Reverb test environment (`yak-e2e-*` on `127.0.0.1:18080`, see `.env.example`) |
| Broker, runner, Incus | No | Real sandboxes need a Linux host with Incus. Locally you work on the dashboard, the jobs and the tests, which use fakes |

### Getting Started

```bash
git clone https://github.com/geocodio/yak.git
cd yak
cp .env.example .env
docker compose up -d mariadb
composer run setup     # install, key:generate, migrate, npm install, npm run build
```

`composer run setup` copies `.env.example` when `.env` is missing, so the `cp` line is optional.

### Running The App

```bash
composer run dev
```

This runs four processes together with `concurrently`: `php artisan serve`, `php artisan queue:listen --tries=1 --timeout=0`, `php artisan pail --timeout=0` (logs) and `npm run dev` (Vite). It does not start the scheduler, Reverb or the thread drivers. Start them in their own terminals when you need them: `php artisan schedule:work`, `php artisan reverb:start`, `php artisan yak:thread-drivers`.

Open `http://localhost:8000`. Login is Google-only, so visit `http://localhost:8000/letmein`. In a `local` environment it signs you in as the first user (creating one if there is none). The route does not exist in other environments.

## Repository Layout

| Path | What lives there |
|---|---|
| `app/Channels/<Name>/` | One folder per channel (Slack, Linear, Sentry, Email, GitHub, Drone): entry class, drivers, webhook controllers, health check |
| `app/Channels/Contracts/` | Capability interfaces: `InputDriver`, `NotificationDriver`, `CIDriver`, `CIBuildScanner` |
| `app/Services/Threads/` | Thread and turn machinery: `ThreadTurns`, `ThreadCommands`, `ThreadDriver`, `TurnFinisher`, `RunawayGuard`, the broker streams and the sandbox managers |
| `app/Services/Stacks/`, `app/Services/Threads/Repos/` | Sub-thread stacks and multi-repo threads |
| `app/Jobs/` | Queued jobs. Jobs that start a turn share `Jobs/Concerns/StartsThreadTurns`. Middleware is in `app/Jobs/Middleware/` |
| `app/Http/Controllers/`, `app/Http/Resources/` | Controllers return `Inertia::render(...)`. `*Data` resources shape the props |
| `app/Prompts/`, `app/YakPromptBuilder.php`, `resources/views/prompts/` | Prompt definitions, prompt assembly and the Blade defaults. See [Prompting](prompting.md) |
| `resources/js/pages/<Area>/<Name>.tsx` | Inertia pages (React 19, TypeScript) |
| `resources/js/components/`, `layouts/`, `types/` | Shared components, layouts, and types that mirror the PHP `*Data` shapes |
| `resources/js/routes/`, `resources/js/actions/` | Wayfinder output. Never edit by hand |
| `broker/` | `yak-broker` (Go): moves messages between Redis streams and the sandbox runner, runs the auth proxy. Wire format in `broker/PROTOCOL.md` |
| `runner/` | The Node runner that wraps the Claude Agent SDK inside each sandbox |
| `cli/` | The `yak` command-line tool (Go). See [CLI](cli.md) |
| `plugin/` | The Claude Code plugin. See [CLI](cli.md) |
| `sandbox-tools/` | Tools baked into the sandbox image, such as `yak-browser` |
| `schemas/` | JSON schemas for `.yak/config.yml`, `preview.yml` and `risk-profile.yml` |
| `ansible/`, `docker/`, `Dockerfile` | Production provisioning. `docker/supervisord.conf` is off limits without approval |
| `docs/`, `docs-site/` | The documentation and the site that renders it |

See [Architecture](architecture.md) for how a turn moves through these parts.

## Running Tests

### Laravel App (Pest)

```bash
# What CI runs for the main suite
vendor/bin/pest --parallel --processes=4 --exclude-group=contract,browser

# One file, one test
php artisan test --compact tests/Feature/Threads/FakeSandboxManagerTest.php
php artisan test --compact --filter="creates a task when a valid Sentry webhook arrives"
```

A full local run can exhaust PHP's default 128M memory limit. Raise it: `php -d memory_limit=2048M vendor/bin/pest`. `phpunit.xml` already sets 2048M for the test process.

Feature tests use SQLite in memory through `RefreshDatabase` (set globally in `tests/Pest.php`), with `QUEUE_CONNECTION=sync` and the array cache. Always create data with factories.

### Test Fakes

`tests/Pest.php` binds fakes so that thread tests never reach Incus or the broker:

| Fake | Replaces | Use it when |
|---|---|---|
| `FakeSandboxManager` | `SandboxManager` | A test starts, wakes or stops a thread sandbox |
| `FakeBrokerStreams` | `BrokerStreams` (Redis streams) | A test sends commands to the runner or feeds runner output to the thread driver. Assert on the commands it recorded |
| `FakeSessionFileSync` | `SessionFileSync` | A test would copy session transcripts |

A test of the Redis implementation itself builds `RedisBrokerStreams` directly. Use `Http::fake()` for external APIs and `Process::fake()` for git and shell calls (specific patterns before wildcards).

### Browser Tests

Browser tests drive a real Chromium through Pest's Playwright plugin. Target `data-testid` hooks.

```bash
npx playwright install --with-deps chromium    # first time
npm run build                                  # the tests need built assets
vendor/bin/pest tests/Browser
```

CI runs them in four shards (`--shard=N/4`) against the Reverb test environment.

### Contract Tests

Tests in the `contract` group are meant to run against the real Claude CLI and are excluded from every other run. The `tests/Contract/` folder is currently empty. The nightly workflow installs the CLI and runs `vendor/bin/pest --group=contract`.

### Broker, CLI And Runner

```bash
cd broker && go vet ./... && go test -race ./...
cd cli && go vet ./... && go test -race ./...
cd runner && npm ci && npm test        # node --test tests/*.test.mjs
```

## CI Workflows

| Workflow | Runs | What it does |
|---|---|---|
| `tests.yml` | Push and PR | Pest, parallel, excluding `contract` and `browser`. PHP 8.5, Node 22, a Redis service |
| `browser.yml` | Push and PR | `tests/Browser` in four shards with Playwright Chromium |
| `lint.yml` | Push and PR | Pint, PHPStan (`--memory-limit=2G`), `npx tsc --noEmit`, `gofmt` and `go vet` for `broker/` and `cli/` |
| `broker.yml` | Changes under `broker/` | `go vet`, gofmt check, `go test -race` |
| `cli.yml` | Changes under `cli/` | Same checks for the CLI |
| `runner.yml` | Changes under `runner/` | Node 22, `npm ci`, `npm test` |
| `contract-tests.yml` | Nightly | `pest --group=contract` with the real Claude CLI |
| `docker.yml` | Push | Builds and pushes the Docker image |
| `docs.yml` | Push to `main` touching docs | Builds the docs site and deploys it to GitHub Pages |
| `release.yml`, `cli-release.yml` | Tags | Release the app, and build the `yak` CLI for release |

## Code Style

All of these run in CI (`lint.yml`).

```bash
vendor/bin/pint                       # fix PHP style
vendor/bin/phpstan analyse            # level 8, Larastan
npx tsc --noEmit                      # TypeScript
gofmt -l broker cli                   # must print nothing
```

Pint uses the Laravel preset with `concat_space` set to `one`. `phpstan-baseline.neon` holds pre-existing errors: do not clear it, and do not add to it. Spell identifiers out in full (`isPayAsYouGo`, not `isPAYG`).

### Wayfinder

Routes and controller actions are exposed to React through generated helpers in `resources/js/routes` and `resources/js/actions`. After you change a route, run:

```bash
php artisan wayfinder:generate
```

The Vite plugin also regenerates them during `npm run dev` and `npm run build`. Never edit the output by hand.

## Adding A Channel

A channel is an integration: a way tasks arrive, notifications leave, or CI results come in. Each channel lives in `app/Channels/<Name>/` and implements `App\Channels\Channel`. Read a small existing channel first: `app/Channels/Email/` (input only) or `app/Channels/Drone/` (CI polling only).

### The Parts

| Part | Contract | Purpose |
|---|---|---|
| Entry class `<Name>Channel` | `Channel` | Names the channel (`name()`, which matches the `source` on a task and the `config('yak.channels.<name>')` key), lists `requiredConfig()`, registers routes, and returns the drivers it has (or `null`) |
| Input driver | `InputDriver::parse(Request): TaskDescription` | Turns a webhook into a task description |
| Notification driver | `NotificationDriver::send(YakTask, NotificationType, string): void` | Posts acknowledgments, progress, results and errors back to the source |
| CI driver | `CIDriver::parse(Request): BuildResult` | Parses a CI webhook |
| CI build scanner | `CIBuildScanner::getRecentFailures(Repository, int): Collection` | For CI systems that must be polled |
| Health check | `HealthCheck` | Shown on the health page |

The `ChecksRequiredConfig` trait implements `enabled()` and `config()`: a channel is enabled when every key in `requiredConfig()` has a value.

### Steps

1. Add `config/yak.php` entries under `channels.<name>`, reading from env vars.
2. Create `app/Channels/<Name>/<Name>Channel.php` and the drivers it needs. Return `null` for the capabilities it does not have.
3. If the channel receives webhooks, add an invokable controller that uses the `VerifiesWebhookSignature` trait, and register the route in `registerRoutes()` (routes live under the `webhooks` prefix, only for enabled channels).
4. Add the entry class to `channel_classes` in `config/yak.php`. `ChannelServiceProvider` registers every class there in the `ChannelRegistry`.
5. If the source needs its own task prompt, add a Blade template in `resources/views/prompts/tasks/`, declare it in `app/Prompts/PromptDefinitions.php`, add a sample to `PromptFixtures`, and route the source to it in `YakPromptBuilder::taskPrompt()`.
6. Add provisioning in `ansible/` if the channel needs setup on the host.
7. Write tests under `tests/Feature/Channels/<Name>/` (valid payload creates a task, bad signature is rejected, duplicates are ignored, `Http::fake()` for outbound calls).
8. Document the channel in [Channels](channels.md).

## Working On The Docs

The documentation is flat Markdown in `docs/`, one file per page, with lowercase kebab-case names. The first line is `# Page Title`, with no front matter. Link to other pages as `[text](name.md)` or `[text](name.md#anchor)`.

- **New page:** add it to the `PAGES` list in `docs-site/scripts/sync-docs.mjs` (a file that is not listed is skipped), and to the sidebar in `docs-site/astro.config.mjs`.
- **Preview locally:** run `cd docs-site && npm install && npm run dev`, then open `http://localhost:4321/yak/`. The dev server syncs `docs/` into the site on start. Do not edit `docs-site/src/content/docs/`, it is regenerated. `npm run build` builds the site and `npm run preview` serves the build. See `docs-site/README.md`.
- **Screenshots:** images sit at the top level of `docs/` and are named `screenshot-<slug>.png`. Make them with dummy data only (repos like `acme/storefront`, people like Ada and Grace, host `yak.example.com`), never real customers, repos, hosts or tokens.
- **Style:** short sentences, no em dashes, and describe Yak as opening PRs for review and serving previews.

## Pull Request Process

1. Fork the repo and branch off `main`.
2. Make your change with tests.
3. Run the pre-flight checks:

   ```bash
   vendor/bin/pint
   vendor/bin/phpstan analyse
   vendor/bin/pest --parallel --processes=4 --exclude-group=contract,browser
   ```

   Also run the Go and runner tests if you touched `broker/`, `cli/` or `runner/`.

4. Open a PR with the template in `.github/pull_request_template.md`.
5. A maintainer reviews it and merges once CI is green.

### Do Not Touch Without Approval

- `phpstan-baseline.neon`: pre-existing errors, do not clear.
- `docker/supervisord.conf`: production config.
- `.chief/`: local working files, never commit.
- Ansible vault and inventory files: the repo is public, never commit them.

## Reporting Bugs And Requesting Features

- Bug report: `https://github.com/geocodio/yak/issues/new?template=bug_report.yml`
- Feature request: `https://github.com/geocodio/yak/issues/new?template=feature_request.yml`

Include the Yak version (git SHA), the channel involved, steps to reproduce, and relevant logs.
