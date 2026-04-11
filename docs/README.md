# Yak Documentation

User-facing documentation for running and operating Yak. Source of truth lives here as plain markdown — the hosted docs site at [geocodio.github.io/yak](https://geocodio.github.io/yak) renders these same files.

## Getting Started

- **[Setup](setup.md)** — provisioning a fresh server with Ansible, vault configuration, verification
- **[Channels](channels.md)** — configuring Slack, Linear, Sentry, GitHub, Drone, and the manual CLI
- **[Repositories](repositories.md)** — adding repos, the setup task, CLAUDE.md guidance, multi-repo routing

## Reference

- **[Architecture](architecture.md)** — two-tier AI, channel drivers, state machine, jobs and queues, safety model
- **[Prompting](prompting.md)** — the three prompt layers, system prompt, task templates, MCP servers, customization

## Operations

- **[Troubleshooting](troubleshooting.md)** — common issues and how to diagnose them

## Contributing

- **[Development](development.md)** — local dev setup, running tests, code style, adding new channel drivers

---

Looking for the internal design specs instead? Those live in [`spec/`](../spec/) at the repo root. The `spec/` files are the source of the user-facing docs here — they describe the target state and design rationale, while these docs describe how to use the running system.
