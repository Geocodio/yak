<p align="center">
  <img src="docs/mascot.png" alt="Yak mascot" width="120">
</p>

<h1 align="center">Yak</h1>

<p align="center">
  <strong>AI-powered task automation that integrates with your existing developer workflow.</strong>
</p>

<p align="center">
  <a href="LICENSE">MIT License</a> &middot;
  <a href="CONTRIBUTING.md">Contributing</a> &middot;
  <a href="docs/">Documentation</a>
</p>

---

## What It Does

- **Receives tasks** from Slack, Linear, Sentry, and other channels via webhooks
- **Executes AI-driven workflows** using Claude to fix bugs, set up repos, and research codebases
- **Integrates with CI** to verify changes pass before merging pull requests
- **Tracks costs and progress** through a real-time Livewire dashboard

## How It Works

```
┌──────────────────────────────────────────────────────────────┐
│                        Channels                               │
│   Slack  ·  Linear  ·  Sentry  ·  GitHub  ·  (more...)      │
└──────────┬──────────┬──────────┬──────────┬──────────────────┘
           │          │          │          │
           ▼          ▼          ▼          ▼
┌──────────────────────────────────────────────────────────────┐
│                     Webhook Controllers                       │
│              Signature verification · Filtering               │
└──────────────────────────┬───────────────────────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────────────────────┐
│                     Task Pipeline                             │
│   RepoDetector → YakTask → State Machine → Job Dispatch      │
└──────────────────────────┬───────────────────────────────────┘
                           │
              ┌────────────┼────────────┐
              ▼            ▼            ▼
        ┌──────────┐ ┌──────────┐ ┌──────────┐
        │  Claude   │ │   Git    │ │   CI     │
        │  (LLM)   │ │  Ops     │ │  Scanner │
        └────┬─────┘ └────┬─────┘ └────┬─────┘
             │             │             │
             ▼             ▼             ▼
┌──────────────────────────────────────────────────────────────┐
│                     Notifications                             │
│         GitHub PR · Slack · Linear · Dashboard                │
└──────────────────────────────────────────────────────────────┘
```

## Quick Start

```bash
# 1. Clone the repository
git clone https://github.com/geocodio/yak.git && cd yak

# 2. Install dependencies
composer install && npm install

# 3. Configure environment
cp .env.example .env
php artisan key:generate

# 4. Run migrations
php artisan migrate

# 5. Start the dev server
composer run dev
```

## Channel Support

| Channel  | Input (receive tasks) | Notifications (send updates) |
|----------|-----------------------|------------------------------|
| Slack    | Yes                   | Yes                          |
| Linear   | Yes                   | Yes                          |
| Sentry   | Yes                   | --                           |
| GitHub   | --                    | Yes (PR comments)            |

## Dashboard

<!-- TODO: Add dashboard screenshot -->
![Dashboard screenshot](docs/dashboard-screenshot.png)

## Design Philosophy

- **Laravel-native** -- built on Laravel 13, Livewire 4, and Flux UI. No custom frontend frameworks.
- **Channel-agnostic** -- driver-based architecture makes it easy to add new input and notification channels.
- **State machine driven** -- every task follows a defined lifecycle with explicit transitions.
- **AI-assisted, human-supervised** -- tasks are automated but observable through the dashboard and notifications.

## Documentation

- [Setup Guide](docs/setup.md)
- [Channels](docs/channels.md)
- [Repositories](docs/repositories.md)
- [Architecture](docs/architecture.md)
- [Prompting](docs/prompting.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Development](docs/development.md)

## Contributing

We welcome contributions! Please read our [Contributing Guide](CONTRIBUTING.md) to get started.

## License

Yak is open-sourced software licensed under the [MIT license](LICENSE).

Copyright 2026 Geocodio
