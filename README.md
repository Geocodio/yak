<p align="center">
  <img src="docs/mascot.png" alt="Yak mascot" width="512">
</p>

<h1 align="center">Yak</h1>

<p align="center">
  <strong>Yak is an autonomous coding agent for papercuts, a line-by-line PR reviewer, and a per-branch preview server. One shared sandbox fleet powers all three workflows.</strong>
</p>

<p align="center">
  <a href="LICENSE">MIT License</a> &middot;
  <a href="CONTRIBUTING.md">Contributing</a> &middot;
  <a href="https://geocodio.github.io/yak/">Documentation</a>
</p>

---

## What It Does

- **Opens PRs for papercuts.** Receives tasks from Slack, Linear, Sentry, and GitHub; sends Claude into an isolated sandbox; opens a reviewable PR and verifies CI passes
- **Reviews pull requests.** Line-level comments, `suggestion` blocks, and a feedback dashboard
- **Serves preview deployments.** Every open PR gets a unique URL, OAuth-gated, hibernated when idle, destroyed when the PR closes
- **Shared sandbox fleet.** One Incus + ZFS substrate, one GitHub App, one Livewire dashboard, one cost model across all three workflows

## How It Works

<p align="center">
  <img src="docs/what-yak-does.png" alt="Yak picks up tasks from Slack, Linear, and Sentry and delivers pull requests" width="720">
</p>

## Quick Start

See the [Setup Guide](https://geocodio.github.io/yak/setup/) for provisioning a fresh server with Ansible, or the [Development Guide](https://geocodio.github.io/yak/development/) for running Yak locally.

## Channel Support

| Channel  | Input (receive tasks) | Notifications (send updates) |
|----------|-----------------------|------------------------------|
| Slack    | Yes                   | Yes                          |
| Linear   | Yes                   | Yes                          |
| Sentry   | Yes                   | --                           |
| GitHub   | Yes (PR review events)| Yes (PR reviews, comments)   |

## Design Philosophy

- **Laravel-native** -- built on Laravel 13, Livewire 4, and Flux UI.
- **Channel-agnostic** -- driver-based architecture makes it easy to add new input and notification channels.
- **State machine driven** -- every task follows a defined lifecycle with explicit transitions.
- **AI-assisted, human-supervised** -- tasks are automated but observable through the dashboard and notifications.

## Documentation

Full documentation is hosted at **[geocodio.github.io/yak](https://geocodio.github.io/yak/)**.

- [Setup](https://geocodio.github.io/yak/setup/)
- [Channels](https://geocodio.github.io/yak/channels/)
- [Repositories](https://geocodio.github.io/yak/repositories/)
- [PR Review](https://geocodio.github.io/yak/pr-review/)
- [Architecture](https://geocodio.github.io/yak/architecture/)
- [Prompting](https://geocodio.github.io/yak/prompting/)
- [Troubleshooting](https://geocodio.github.io/yak/troubleshooting/)
- [Development](https://geocodio.github.io/yak/development/)

## Contributing

We welcome contributions! Please read our [Contributing Guide](CONTRIBUTING.md) to get started.

## License

Yak is open-sourced software licensed under the [MIT license](LICENSE).

Copyright 2026 Geocodio
