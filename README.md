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
  <a href="https://geocodio.github.io/yak/">Documentation</a>
</p>

---

## What It Does

- **Receives tasks** from Slack, Linear, Sentry, and other channels via webhooks
- **Executes AI-driven workflows** using Claude to fix bugs, set up repos, and research codebases
- **Integrates with CI** to verify changes pass before merging pull requests
- **Tracks costs and progress** through a real-time Livewire dashboard

## How It Works

<p align="center">
  <img src="docs/what-yak-does.jpg" alt="Yak picks up tasks from Slack, Linear, and Sentry and delivers pull requests" width="720">
</p>

## Quick Start

See the [Setup Guide](https://geocodio.github.io/yak/setup/) for provisioning a fresh server with Ansible, or the [Development Guide](https://geocodio.github.io/yak/development/) for running Yak locally.

## Channel Support

| Channel  | Input (receive tasks) | Notifications (send updates) |
|----------|-----------------------|------------------------------|
| Slack    | Yes                   | Yes                          |
| Linear   | Yes                   | Yes                          |
| Sentry   | Yes                   | --                           |
| GitHub   | --                    | Yes (PR comments)            |

## Design Philosophy

- **Laravel-native** -- built on Laravel 13, Livewire 4, and Flux UI. No custom frontend frameworks.
- **Channel-agnostic** -- driver-based architecture makes it easy to add new input and notification channels.
- **State machine driven** -- every task follows a defined lifecycle with explicit transitions.
- **AI-assisted, human-supervised** -- tasks are automated but observable through the dashboard and notifications.

## Documentation

Full documentation is hosted at **[geocodio.github.io/yak](https://geocodio.github.io/yak/)**.

- [Setup](https://geocodio.github.io/yak/setup/)
- [Channels](https://geocodio.github.io/yak/channels/)
- [Repositories](https://geocodio.github.io/yak/repositories/)
- [Architecture](https://geocodio.github.io/yak/architecture/)
- [Prompting](https://geocodio.github.io/yak/prompting/)
- [Troubleshooting](https://geocodio.github.io/yak/troubleshooting/)
- [Development](https://geocodio.github.io/yak/development/)

## Contributing

We welcome contributions! Please read our [Contributing Guide](CONTRIBUTING.md) to get started.

## License

Yak is open-sourced software licensed under the [MIT license](LICENSE).

Copyright 2026 Geocodio
