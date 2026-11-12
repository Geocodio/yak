# Concepts

Read this page first. It explains the few terms the rest of the docs use, and how they fit together.

## Thread

A thread is one piece of work with Claude. Each thread has:

- one sandbox, a private machine where Claude runs your repository's code
- one Claude session, so Claude keeps the whole conversation in mind
- one branch for each repository that changes
- one Claude account, fixed when the thread starts

You work in a thread from the dashboard: you read what Claude does, send messages, look at the changes and open pull requests. See [Threads](threads.md).

## Turn

A turn is one stretch of Claude working. You send a message (or a channel creates a task), Claude works until it is done, and the turn ends. A thread has many turns, one after another. Each turn continues the same conversation, in the same sandbox, on the same branch.

## Task

A task is one request that arrived through a channel: a Slack message, a Linear issue, a Sentry alert, an email, a GitHub event or a Drone failure. See [Channels](channels.md).

- Channels create tasks. Each task runs as a turn inside a thread.
- A new task joins the open thread of its conversation. If that thread is closed, Yak starts a new one. A closed thread never reopens.
- The Tasks pages still exist. Use them to see what each channel request did, and to retry, cancel or reroute a task. Use the Threads list to work with Claude.
- A thread you start from the dashboard has a task too, but you rarely need the Tasks pages for it.

## Sandbox

A sandbox is the isolated machine one thread runs in. It is awake while Claude or you use it, and paused when the thread is idle. A paused sandbox stops after a while, and a stopped one is destroyed after a few days. Sending a message to a stopped or destroyed sandbox rebuilds it from the thread's branch and saved conversation, so nothing is lost. See [Threads](threads.md#sandbox-lifecycle-and-restore).

## Claude account

A Claude account is the subscription or API key a thread runs on. Yak picks it when the thread starts and never switches it, so cost and usage limits stay with one account. See [Claude accounts](claude-accounts.md).

## Where the work ends

Yak opens pull requests for human review. It never merges, deploys to production or releases anything. You can also look at a running preview of the branch (see [Previews](previews.md)). A person reviews and merges.

## Term map

| Term | Meaning | Page |
| --- | --- | --- |
| Thread | One sandbox, one Claude session, one branch per changed repository, one Claude account | [Threads](threads.md) |
| Turn | One stretch of Claude working on a message | [Threads](threads.md#the-composer) |
| Multi-repo thread | A thread with a primary repository plus extra repositories that Yak changes or only reads | [Multi-repo threads](multi-repo.md) |
| Sub-thread, stack | A thread Claude or you spawn from a parent thread, and the group they form | [Sub-threads](sub-threads.md) |
| Preview | The branch's app, served from the thread sandbox or as a branch deployment | [Previews](previews.md) |
| Teleport | Move a thread between Yak and your own computer | [Teleport](teleport.md) |
| SSH | A shell or editor connection into a thread's sandbox | [SSH](ssh.md) |
| `yak` CLI | The command line client and Claude Code plugin | [The yak CLI](cli.md) |
| Claude account | The subscription or key a thread runs on | [Claude accounts](claude-accounts.md) |
| Notifications | Push alerts when a thread needs you | [Notifications](notifications.md) |
| Dashboard | The other pages: costs, analytics, health, settings | [Dashboard](dashboard.md) |
| PR review | Reviews Yak writes on pull requests | [PR review](pr-review.md) |
| Video walkthrough | A recorded demo of a change, attached to a pull request | [Video walkthroughs](video-walkthroughs.md) |
