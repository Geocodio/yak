# Contributing to Yak

Welcome! We're glad you're interested in contributing to Yak. This guide will help you get set up and familiar with our workflow.

## Development Setup

### Requirements

- PHP 8.4+
- SQLite
- Node.js 20+
- Composer
- npm

### Getting Started

```bash
git clone https://github.com/geocodio/yak.git
cd yak
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer run dev
```

## Running Tests

```bash
# Run all tests
php artisan test

# Run with compact output
php artisan test --compact

# Run a specific test file
php artisan test --filter=YakTaskTest

# Run browser tests (requires Playwright)
npm install playwright && npx playwright install chromium
php artisan test tests/Browser
```

## Code Style

This project uses [Laravel Pint](https://laravel.com/docs/pint) for code formatting and [Larastan](https://github.com/larastan/larastan) for static analysis.

```bash
# Fix code style
vendor/bin/pint

# Run static analysis
vendor/bin/phpstan analyse
```

All PHP code must pass Pint and PHPStan (level 8) before merging.

## Pull Request Process

1. Fork the repository and create a feature branch from `main`.
2. Make your changes, including tests for any new functionality.
3. Ensure all checks pass:
   - `vendor/bin/pint --test` (code style)
   - `vendor/bin/phpstan analyse` (static analysis)
   - `php artisan test` (test suite)
4. Submit a pull request with a clear description of the change.

## Adding Channel Drivers

Yak uses a driver-based architecture for channels. To add a new channel:

1. Create an input driver in `app/Drivers/` implementing `App\Contracts\InputDriver`.
2. Create a notification driver in `app/Drivers/` implementing `App\Contracts\NotificationDriver`.
3. Add a webhook controller in `app/Http/Controllers/Webhooks/` if the channel receives inbound webhooks.
4. Register routes in `routes/web.php`.
5. Add tests in `tests/Feature/` covering the webhook endpoint and driver logic.

See existing drivers (Slack, Linear, Sentry) for reference implementations.

## Reporting Issues

- Use the [bug report template](.github/ISSUE_TEMPLATE/bug_report.yml) for bugs.
- Use the [feature request template](.github/ISSUE_TEMPLATE/feature_request.yml) for new ideas.
- Include as much context as possible: version, channel, steps to reproduce, and relevant logs.

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](https://www.contributor-covenant.org/version/2/1/code_of_conduct/). By participating, you agree to uphold this code.
