# Development

This page is for people working on Yak itself — fixing bugs in the Laravel app, adding new features, or writing new channel drivers. If you just want to run Yak against your repos, see [setup.md](setup.md).

## Local Development Setup

### Prerequisites

| Tool | Version | Notes |
|---|---|---|
| **PHP** | 8.4+ | With `sqlite3` and `pdo_sqlite` extensions |
| **Composer** | 2.x | `composer --version` to verify |
| **Node** | 20+ | For building frontend assets and running Playwright |
| **SQLite** | 3.x | `sqlite3 --version` |

You do NOT need Claude Code CLI, Docker, Chromium, or Ansible to develop on Yak. Those are runtime dependencies for a production Yak instance — tests fake all external process calls via Laravel's `Process::fake()` and `Http::fake()`.

### Getting Started

```bash
git clone https://github.com/geocodio/yak.git
cd yak

# Install PHP dependencies
composer install

# Install Node dependencies and build the dashboard
npm install
npm run build

# Set up the environment
cp .env.example .env
php artisan key:generate

# Create the SQLite database and run migrations
touch database/database.sqlite
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

Open `http://localhost:8000`. The Livewire starter kit's auth pages work for local development — Google OAuth is only used in production.

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
php artisan test --compact tests/Feature/Jobs/RunYakJobTest.php

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

Browser tests cover the auth flow, Livewire live updates on the task detail page, artifact viewer navigation, signed URL access, and accessibility (`assertNoAccessibilityIssues()` plus `assertNoJavaScriptErrors()` on dashboard pages).

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

## Architecture Overview For Contributors

See [architecture.md](architecture.md) for the full system design. For contributors, the shortest version:

- **`app/Jobs/`** — the pipeline. `RunYakJob`, `RetryYakJob`, `ResearchYakJob`, `SetupYakJob`, `ClarificationReplyJob`, `ProcessCIResultJob`. Each one is a single-responsibility queue job.
- **`app/Jobs/Middleware/`** — `CleanupDevEnvironment`, `EnsureDailyBudget`. Cross-cutting concerns as Laravel job middleware.
- **`app/Drivers/`** — channel driver implementations. Each channel has an input driver, a notification driver, or both.
- **`app/Contracts/`** — the three driver interfaces (`InputDriver`, `CIDriver`, `NotificationDriver`) plus `CIBuildScanner`.
- **`app/Http/Controllers/Webhooks/`** — one invokable controller per webhook endpoint. Uses the `VerifiesWebhookSignature` trait.
- **`app/Livewire/`** — dashboard components. `Tasks/TaskList`, `Tasks/TaskDetail`, `Repos/RepoList`, `Repos/RepoForm`, `CostDashboard`, `Health`.
- **`app/Models/`** — `YakTask` (note: `$table = 'tasks'`), `TaskLog`, `Artifact`, `Repository`, `DailyCost`.
- **`app/Enums/`** — `TaskStatus` (the state machine), `TaskMode`, `NotificationType`.
- **`app/Services/`** — external API integrations (GitHub, Linear, Slack, Sentry) and detection logic.
- **`app/YakPromptBuilder.php`** — Blade-based prompt assembly for Claude Code.
- **`app/GitOperations.php`** — centralized git commands via the `Process` facade.
- **`lib/fat-enums/`** — local package for the state machine. **Do not restructure without approval.**
- **`resources/views/prompts/`** — Blade templates for task prompts, one per source.
- **`docker/`** — production Docker configuration. The root `Dockerfile` builds from it.

## Adding A New Channel Driver

Yak's channel architecture is the primary extension point. Adding a new input source (Jira, GitHub Issues, email, etc.), a new CI system, or a new notification target means implementing one or more of the contracts in `app/Contracts/`.

### The Interfaces

```php
// app/Contracts/InputDriver.php
interface InputDriver
{
    public function parseWebhook(Request $request): ?TaskDescription;
    // Returns null if the webhook should be ignored.
}

// app/Contracts/CIDriver.php
interface CIDriver
{
    public function parseBuildResult(Request $request): ?BuildResult;
    public function fetchFailureOutput(string $buildId): string;
}

// app/Contracts/NotificationDriver.php
interface NotificationDriver
{
    public function acknowledge(YakTask $task): void;
    public function progress(YakTask $task, string $message): void;
    public function result(YakTask $task): void;
    public function failed(YakTask $task, string $reason): void;
}
```

### Worked Example: Adding A Jira Input Driver

1. **Add configuration**

   In `config/yak.php`, add a `jira` entry under `channels`:

   ```php
   'jira' => [
       'base_url'       => env('JIRA_BASE_URL'),
       'api_token'      => env('JIRA_API_TOKEN'),
       'webhook_secret' => env('JIRA_WEBHOOK_SECRET'),
   ],
   ```

   The `Channel` helper class (`app/Channel.php`) auto-detects channels as enabled when their credentials are present.

2. **Create the input driver**

   ```bash
   php artisan make:class Drivers/JiraInputDriver
   ```

   Implement `InputDriver`. Parse the incoming Jira webhook payload into a `TaskDescription` (source = `jira`, external_id from issue key, context from issue body).

3. **Create the webhook controller**

   ```bash
   php artisan make:controller Webhooks/JiraWebhookController --invokable
   ```

   Use the `VerifiesWebhookSignature` trait for signature checking. Resolve the input driver, parse the request, create a `YakTask`, dispatch `RunYakJob`. Look at `SlackWebhookController` for the canonical pattern.

4. **Register the route conditionally**

   Add to `app/Providers/RouteServiceProvider.php` (or wherever channels are resolved at boot):

   ```php
   if (Channel::enabled('jira')) {
       Route::post('/webhooks/jira', JiraWebhookController::class);
   }
   ```

5. **Add a notification driver** (optional but recommended)

   Create `app/Drivers/JiraNotificationDriver.php` implementing `NotificationDriver`. Post issue comments via the Jira REST API (use `Http::withToken()`). If omitted, notifications fall back to PR comments.

6. **Create a prompt template**

   Add `resources/views/prompts/jira-fix.blade.php` following the pattern of `linear-fix.blade.php`. Keep it short.

7. **Update `YakPromptBuilder`**

   Wire the new source to its template in `app/YakPromptBuilder.php`.

8. **Write tests**

   - `tests/Unit/JiraInputDriverTest.php` — parses sample webhook payloads, returns expected task description, rejects invalid payloads
   - `tests/Feature/Webhooks/JiraWebhookTest.php` — full controller test: valid payload creates task, invalid signature rejected, duplicate external_id rejected
   - `tests/Feature/Notifications/JiraNotificationTest.php` — uses `Http::fake()` to assert correct API payloads

9. **Add Ansible support** (for production deployment)

   Create `ansible/roles/channel-jira/` with tasks for registering the webhook on the Jira side. Add the channel to the conditional includes in `ansible/playbook.yml`. Follow `ansible/roles/channel-linear/` as a template.

10. **Document it**

    Add a Jira section to [channels.md](channels.md) following the pattern of Linear and Sentry.

### Adding A New CI Driver

Same pattern, but implement `CIDriver` instead of `InputDriver`. See `app/Drivers/` for the Drone and GitHub Actions implementations. The repo's `ci_system` column is the authority on which driver to use for a given repo.

## Testing Conventions

### Factories

Every model has a factory with named states. Factories are the **only** way to create test data — no raw DB inserts in tests.

```php
$task = YakTask::factory()
    ->awaitingClarification()
    ->forRepo('my-app')
    ->create();
```

Key factory states:

| Model | States |
|---|---|
| `YakTask` | `pending`, `running`, `awaitingClarification`, `awaitingCi`, `retrying`, `success`, `failed`, `expired` |
| `Repository` | `default`, `inactive`, `withAuth`, `withSentry` |
| `TaskLog` | `info`, `warning`, `error` |
| `Artifact` | `screenshot`, `video`, `research` |

### Test Helpers

`tests/Helpers/` provides reusable helpers loaded via Pest's `uses()` in `tests/Pest.php`:

| Helper | Purpose |
|---|---|
| `fakeClaudeRun()` | Fakes a successful Claude CLI run with configurable `result_summary`, `cost_usd`, `session_id`, `num_turns` |
| `fakeClaudeClarification()` | Fakes a Claude run that returns clarification JSON |
| `fakeClaudeError()` | Fakes a failed Claude run |
| `assertSlackThreadReply()` | Asserts an HTTP call to Slack `chat.postMessage` with correct channel/thread/text |
| `assertLinearComment()` | Asserts a Linear issue comment was posted |
| `assertLinearStateUpdate()` | Asserts a Linear issue's state was updated |

### Process And HTTP Faking

All external process calls (Claude CLI, git, docker-compose) use `Process::fake()`. Patterns matter — **specific patterns before wildcards**, because Laravel matches in registration order:

```php
Process::fake([
    'claude -p *' => Process::result(json: ['result' => '...', 'session_id' => '...']),
    '*'           => Process::result(),
]);
```

External API calls use `Http::fake()` with URL patterns:

```php
Http::fake([
    'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
    'api.linear.app/graphql'         => Http::response(['data' => ['...']]),
]);
```

### Database

All feature tests use SQLite in-memory via `RefreshDatabase` (configured globally in `tests/Pest.php`). No test database to manage, no shared state between tests.

### Naming

Pest `it()` syntax with descriptive names:

```php
it('creates a task when a valid Sentry webhook arrives', function () { ... });
it('rejects Sentry webhooks for CSP violations', function () { ... });
it('detects clarification JSON and pauses for user reply', function () { ... });
```

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

- `lib/fat-enums/` — local package, do not restructure
- `phpstan-baseline.neon` — pre-existing errors, do not clear
- `docker/supervisord.conf` — production config
- `.chief/` — local working files, never commit

## Reporting Bugs And Requesting Features

- **Bug report** — `https://github.com/geocodio/yak/issues/new?template=bug_report.yml`
- **Feature request** — `https://github.com/geocodio/yak/issues/new?template=feature_request.yml`

Include the Yak version (git SHA), the channel involved, steps to reproduce, and relevant logs from `docker logs yak --tail 500` or the task's debug section.
