# Development

This page is for people working on Yak itself — fixing bugs in the Laravel app, adding new features, or writing new channel drivers. If you just want to run Yak against your repos, see the [Setup](setup.md) page.

## Local Development Setup

### Prerequisites

| Tool | Version | Notes |
|---|---|---|
| **PHP** | 8.3+ | With `pdo_mysql` extension |
| **Composer** | 2.x | `composer --version` to verify |
| **Node** | 20+ | For building frontend assets and running Playwright |
| **Docker** | 24+ | For MariaDB via docker-compose |

You do NOT need Claude Code CLI, Chromium, or Ansible to develop on Yak. Those are runtime dependencies for a production Yak instance — tests fake all external process calls via Laravel's `Process::fake()` and `Http::fake()`.

### Getting Started

```bash
git clone https://github.com/geocodio/yak.git
cd yak

# Install PHP dependencies
composer install

# Install Node dependencies and build the dashboard
npm install
npm run build

# Start MariaDB
docker compose up -d

# Set up the environment
cp .env.example .env
php artisan key:generate

# Run migrations
php artisan migrate --seed
```

The starter kit's default test user is seeded in `database/seeders/DatabaseSeeder.php`.

### Running The Dev Server

```bash
composer run dev
```

This starts the Laravel dev server, the queue worker, the scheduler, and Vite all in parallel via `concurrently`. Equivalent to running:

```bash
php artisan serve
php artisan queue:listen --tries=1
php artisan schedule:work
npm run dev
```

Open `http://localhost:8000`. Login is Google OAuth only, so for local development visit `http://localhost:8000/letmein` instead — it signs you in as the first user in the database (creating one if the table is empty) and drops you on the dashboard. The route only exists when `APP_ENV=local` and returns a 404 everywhere else.

## Running Tests

Yak has four test tiers. The first three run in CI on every push; the fourth is nightly only.

| Tier | Directory | How to run | Speed |
|---|---|---|---|
| **Unit** | `tests/Unit/` | `vendor/bin/pest --testsuite=Unit` | Seconds |
| **Feature** | `tests/Feature/` | `vendor/bin/pest --testsuite=Feature` | Seconds |
| **Browser** | `tests/Browser/` | `vendor/bin/pest --testsuite=Browser` | ~30s |
| **Contract** | `tests/Contract/` | `vendor/bin/pest --group=contract` | ~60s, requires Claude CLI |

### Day-To-Day Commands

```bash
# Everything except contract tests (matches CI)
vendor/bin/pest --exclude-group=contract

# Compact output (recommended for iterative work)
php artisan test --compact

# A single file
php artisan test --compact tests/Feature/RunYakJobTest.php

# A single test by name filter
php artisan test --compact --filter="creates a task when a valid Sentry webhook arrives"
```

### Browser Tests

Browser tests use Pest's Playwright plugin. On first run:

```bash
npx playwright install --with-deps chromium
```

Then:

```bash
vendor/bin/pest --testsuite=Browser
```

Browser tests cover the auth flow, live polling updates on the task detail page, artifact viewer navigation, signed URL access, and accessibility (`assertNoAccessibilityIssues()` plus `assertNoJavaScriptErrors()` on dashboard pages).

### Contract Tests

Contract tests validate that real Claude CLI output matches the schema Yak expects. They run nightly against the real CLI — **not** in the normal test run — because they need Claude CLI installed and an Anthropic API key.

```bash
vendor/bin/pest --group=contract
```

If you change how Yak parses Claude CLI output (`ClaudeOutputParser`), add a contract test.

## Code Style

Two tools, both enforced in CI.

### Pint

Laravel Pint for formatting. Run before committing:

```bash
vendor/bin/pint
```

Or check without fixing:

```bash
vendor/bin/pint --test
```

Yak uses the Laravel preset with one override: `concat_space` is set to `one` (space before and after `.`). See `pint.json` at the repo root.

### PHPStan / Larastan

PHPStan at level 8 (maximum) with the Larastan extension:

```bash
vendor/bin/phpstan analyse
```

Yak ships with a `phpstan-baseline.neon` file containing pre-existing errors (mostly Livewire dynamic property access). **Do not clear the baseline** without approval. New code should not add to it.

### Pre-commit

Not enforced. Developers can run Pint on save or set up a git pre-commit hook. CI is the gate.

## Project Structure

See [Architecture](architecture.md) for the system design. Where things live:

- `app/Channels/` -- one folder per integration channel (`GitHub`, `Slack`, `Linear`, `Sentry`, `Drone`). Each holds the channel's entry class, drivers, webhook controllers, support classes and health check.
- `app/Channels/Contracts/` -- capability interfaces: `InputDriver`, `NotificationDriver`, `CIDriver`, `CIBuildScanner`.
- `app/Channels/Channel.php` and `ChannelRegistry.php` -- the entry-class interface and the runtime lookup by channel name.
- `app/Jobs/` -- the queued pipeline. Each agent job creates an Incus sandbox and destroys it in a `finally` block. Middleware is in `app/Jobs/Middleware/`.
- `app/Agents/` -- `SandboxedAgentRunner`, which runs Claude Code inside the task's sandbox, plus output parsing.
- `app/Services/` -- external API integrations, sandbox management (`IncusSandboxManager`), repo detection and routing, and health checks.
- `app/Models/` -- Eloquent models. `YakTask` uses `$table = 'tasks'`.
- `app/Enums/` -- `TaskStatus` (the state machine), `TaskMode`, `NotificationType`.
- `app/DataTransferObjects/` -- readonly DTOs with static factory methods.
- `app/Prompts/`, `app/YakPromptBuilder.php`, `resources/views/prompts/` -- prompt metadata, assembly and the default Blade templates. See [Prompting](prompting.md).
- `app/GitOperations.php` -- git commands through the `Process` facade.
- `app/Http/Controllers/` -- grouped by area, each returning `Inertia::render(...)`. Related folders: `Requests/` (form requests), `Resources/` (`*Data` classes that shape page props), `Concerns/` (webhook signature trait).
- `resources/js/pages/` -- Inertia pages (React), `<Area>/<Name>.tsx`. Shared code is in `components/`, `layouts/` and `types/`.
- `resources/js/routes` and `resources/js/actions` -- generated by Wayfinder. Run `php artisan wayfinder:generate` after route changes and never edit them by hand.
- `docker/` -- production Docker config. The root `Dockerfile` builds from it.

## Adding A New Channel

A channel is a folder in `app/Channels/<Name>/` with an entry class that implements `App\Channels\Channel`. `app/Channels/Sentry/` is the smallest example. Read it first.

The entry class declares what the channel can do:

| Method | Purpose |
|---|---|
| `name()` | Key used in `config('yak.channels.<name>')` and for lookup |
| `requiredConfig()` | Config keys that must be set. The channel is enabled only when all are non-empty. |
| `registerRoutes(Router)` | Webhook routes, registered under `/webhooks` only when the channel is enabled |
| `inputDriver()` | `parse(Request): TaskDescription`. Turns an incoming event into a task. |
| `notificationDriver()` | `send(YakTask, NotificationType, string): void`. Posts status back to the source. |
| `ciDriver()` / `ciBuildScanner()` | `parse(Request): BuildResult` for webhooks, or `getRecentFailures(...)` for polled CI |
| `healthChecks()` | Checks shown on `/health` |

Return `null` from the drivers the channel does not provide. The `ChecksRequiredConfig` trait implements `config()` and `enabled()`.

Steps:

1. Add the credentials to the `channels` array in `config/yak.php` and read them from env vars.
2. Create the entry class and drivers in `app/Channels/<Name>/`, plus a webhook controller that uses the `VerifiesWebhookSignature` trait. Copy the shape of `Sentry/WebhookController.php`.
3. Add the entry class to `channel_classes` in `config/yak.php`.
4. Add a task prompt: the Blade template, an entry in `app/Prompts/PromptDefinitions.php`, a fixture in `PromptFixtures`, and a render path in `YakPromptBuilder`.
5. Add tests: webhook feature tests (valid payload creates a task, bad signature is rejected, duplicates are ignored) and driver unit tests. Fake outbound calls with `Http::fake()`.
6. Add an Ansible role under `ansible/roles/channel-<name>/` and include it in `ansible/playbook.yml`. Follow `channel-linear`.
7. Document it in [Channels](channels.md).

## Testing Conventions

- Use factories for all test data. Check the factory for named states before building a model by hand.
- Fake external processes with `Process::fake()`, listing specific patterns before wildcards. Fake outbound HTTP with `Http::fake()`.
- Helpers such as `fakeClaudeRun()` and `assertSlackThreadReply()` live in `tests/Helpers/`.
- Feature tests use `RefreshDatabase` (configured in `tests/Pest.php`). Tests run on SQLite in memory.
- Name tests with Pest `it()` and a descriptive sentence.

## Pull Request Process

1. Fork the repo and create a branch off `main`
2. Make your changes with tests
3. Run the full pre-flight check:

   ```bash
   vendor/bin/pint
   vendor/bin/phpstan analyse
   vendor/bin/pest --exclude-group=contract
   ```

4. Open a PR using the template (`.github/pull_request_template.md`): What, Why, How to test, Checklist
5. CI runs Pint, PHPStan, unit tests, feature tests, and browser tests on every push
6. A maintainer reviews, and if all four checks pass, merges

### What Not To Touch Without Approval

- `phpstan-baseline.neon` — pre-existing errors, do not clear
- `docker/supervisord.conf` — production config
- `.chief/` — local working files, never commit

## Reporting Bugs And Requesting Features

- **Bug report** — `https://github.com/geocodio/yak/issues/new?template=bug_report.yml`
- **Feature request** — `https://github.com/geocodio/yak/issues/new?template=feature_request.yml`

Include the Yak version (git SHA), the channel involved, steps to reproduce, and relevant logs from `docker logs yak --tail 500` or the task's debug section.
