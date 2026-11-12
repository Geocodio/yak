# Yak Documentation

Documentation for using and running Yak. These markdown files are the source. The hosted site at [geocodio.github.io/yak](https://geocodio.github.io/yak) renders the same files.

## Start here

- **[Concepts](concepts.md)**: threads, turns, tasks, sandboxes and Claude accounts, and how they relate

## Work with Yak

- **[Threads](threads.md)**: start a thread, steer Claude, read the thread page, share a thread or keep it private
- **[Multi-repo threads](multi-repo.md)**: several repositories in one thread, one pull request per changed repository
- **[Sub-threads](sub-threads.md)**: split work into sub-threads that report back to the parent
- **[Previews](previews.md)**: thread previews of live work, branch deployments for pull requests, preview repair
- **[Teleport](teleport.md)**: move a Claude Code session between your computer and a Yak thread
- **[SSH and terminal](ssh.md)**: open a shell in a thread sandbox from the browser or with `yak ssh`
- **[yak CLI](cli.md)**: command and flag reference
- **[Claude accounts](claude-accounts.md)**: add accounts, choose which one a thread uses, handle usage limits
- **[Notifications](notifications.md)**: push notifications, Slack direct messages, email
- **[PR Review](pr-review.md)**: let Yak review pull requests
- **[Video walkthroughs](video-walkthroughs.md)**: recorded walkthroughs of UI changes and the video theme
- **[Dashboard pages](dashboard.md)**: what each dashboard page shows

## Configure

- **[Channels](channels.md)**: Slack, Linear, Sentry, email, GitHub and Drone
- **[Repositories](repositories.md)**: add and manage repositories, setup, routing, per-repository settings
- **[Repository config](repo-config.md)**: the `.yak/` directory in a repository
- **[Prompting](prompting.md)**: prompt layers, templates, tools and MCP servers

## Run Yak

- **[Setup](setup.md)**: provision a server with Ansible
- **[Upgrading to threads](upgrading.md)**: move an existing installation to the thread model
- **[Operations](operations.md)**: host components, scheduled commands, capacity settings, datasets
- **[Architecture](architecture.md)**: the thread model, the life of a turn, sandboxes, safety
- **[Troubleshooting](troubleshooting.md)**: common problems and how to diagnose them

## Contribute

- **[Development](development.md)**: local setup, tests, code style, adding a channel, working on the docs
