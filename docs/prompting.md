# Prompting

This page explains what Claude sees in a Yak thread and where each part comes from. Read it when you want to change how Yak behaves: tune its rules, adjust a template for one source, or add context for every task. If you only want Yak to work well in your codebase, edit the `CLAUDE.md` in the target repo first. See [Repositories](repositories.md#claudemd-the-highest-leverage-config-point).

## What Claude Sees

Every turn in a thread is built from these layers, from the most general to the most specific.

| Layer | Where it comes from | Scope | Who maintains it |
|---|---|---|---|
| Claude Code's own system prompt | The `claude_code` preset of the Claude Agent SDK | Tool use, safety, general behavior | Anthropic |
| Yak's system prompt | Appended to the preset. Rendered from the `system` prompt, with Sentry rules when that channel is enabled and the repo's agent instructions | Operating rules for every task: scope, commits, visual capture, no remote git | Your Yak admins, at `/prompts` |
| Repository instructions | `.yak/AGENTS.md` (or the Agent instructions field on the repository page), added to Yak's system prompt as "Repository-specific notes" | Yak-only rules for one repo | The team that owns the repo. See [Repo config](repo-config.md) |
| `CLAUDE.md` | Loaded by Claude Code from the repo (`settingSources` is `user`, `project`, `local`) | Repo conventions, test commands, do-not-touch lists | The team that owns the repo |
| The turn message | Rendered from a task template, per source and kind of turn | The task itself: the error, the issue, the feedback | Yak, from a template you can edit at `/prompts` |
| The environment note | Written into the sandbox when a thread is restored, shown once on resume | What a restored sandbox lost (refresh commands that ran, missing ignored files, preview, dataset) | Yak |

Rules that apply to one repo belong in `CLAUDE.md` or `.yak/AGENTS.md`, not in Yak's system prompt. The system prompt applies to every task on every repo.

Every prompt Yak renders (system, task, notification) can be edited live at `/prompts` without a redeploy. See [Editing prompts](#editing-prompts-in-the-dashboard).

## How The Runner Is Configured

Each thread has one runner process in its sandbox. Yak starts it with a start message and then sends turns to it. The start message carries these settings.

| Setting | Value |
|---|---|
| System prompt | `claude_code` preset plus Yak's text appended |
| Working directory | `/workspace` (the primary repo). Extra repos of a multi-repo thread are added as additional directories |
| Permission mode | `bypassPermissions`. The sandbox is the boundary, and your real Claude token never enters it |
| Setting sources | `user`, `project`, `local`, so `CLAUDE.md` and the repo's `.claude/` settings load |
| Resume | The thread's active session id, so a restarted runner continues the same conversation |
| Model | The thread's model, see [Model selection](#model-selection) |
| Disallowed tools | `ScheduleWakeup`, `CronCreate`, `CronDelete`, `CronList`, because they would outlive the turn |
| Environment | A minimal environment. `CLAUDE_CONFIG_DIR` points at the thread's config directory. The CLI authenticates with a placeholder against Yak's auth proxy, which swaps in the real token |
| Hooks | A `SessionStart` hook for resumed sessions that prints the environment note once |

Yak builds the system prompt text when a turn starts and stores it on the thread, so a runner that restarts later uses the same text. A prompt you edit at `/prompts` applies to threads from the next turn that starts a runner, not to a runner that is already alive.

## Follow-Up Turns

A thread is one Claude session. A follow-up turn (feedback on a PR, a clarification answer, a retry after CI failed, a message in the composer) goes into the live runner as another message, so Claude keeps the whole conversation. If the sandbox was stopped, Yak restores it and starts a new runner that resumes the stored session id. The session transcript is kept outside the sandbox for this reason.

Because the session already holds the context, follow-up templates are short: the new instructions and how to summarize the push. A fresh session (for example after the transcript could not be resumed) gets the full task prompt again. See [Threads](threads.md) for turns from the user's side.

## Model Selection

Yak picks the model for a new thread in this order:

1. The model you choose on the new-task form or in the thread's model picker.
2. The model set for the repository, when it applies to the account in use.
3. The account's default model.
4. `YAK_DEFAULT_MODEL` (default `opus`).

Account resolution follows the same order. See [Claude accounts](claude-accounts.md). You can change the model of a thread between turns. A change takes effect from the next turn.

Sub-threads started by Claude default to `YAK_SANDBOX_DEFAULT_CHILD_MODEL` (default `sonnet`) unless the brief names a model.

Yak's own helper prompts (task titles, repo routing, notification wording) use small models chosen by the app, not by the thread.

## The Yak Tools

Claude gets one extra tool server named `yak`, running inside the runner. Each call goes to Yak, which does the work and returns the result. A call that Yak does not answer within 120 seconds returns an error to Claude. A session running on your own machine (not in a sandbox) answers these tools with "teleport this session to Yak first".

| Tool | What it does |
|---|---|
| `spawn_thread` | Starts a sub-thread with its own sandbox and a brief. Inputs: `name`, `brief`, `kind` (`implement`, `mockup`, `research` or `general`), optional `model` and `repo`. See [Sub-threads](sub-threads.md) |
| `send_message` | Sends a message to another thread in the same stack |
| `list_threads` | Lists the threads in the stack |
| `add_repository` | Adds a repository to this thread. `role` is `changes` (Claude will edit it, it gets a PR) or `context` (read only). See [Multi-repo threads](multi-repo.md) |
| `set_merge_order` | Sets the order to merge the thread's pull requests, first to merge first |
| `request_refresh` | Asks Yak to refresh the sandbox environment |

Which of these a thread can use depends on the thread. Yak answers `spawn_thread`, `send_message` and `list_threads` for threads in a stack, `add_repository` and `set_merge_order` for multi-repo threads, and `request_refresh` for threads that can be refreshed. A tool Yak cannot serve returns "not available yet".

## The Yak System Prompt

The system prompt is the `system` prompt at `/prompts` (**System Rules**). It starts "You are Yak, an autonomous coding agent" and holds numbered rules. In summary:

| Rule | What it tells Claude |
|---|---|
| 1-3 | Stay in scope, make minimal changes, read the code before changing it |
| 4 | Run the project's tests before committing |
| 5 | Commit with `[<task id>] Short description` |
| 6 | Visual capture for UI changes, see [Visual capture](#visual-capture) |
| 7-8 | Stop and report when the task is ambiguous or Claude is stuck after three attempts |
| 9 | Use Context7 for library docs |
| 10 | Dev environment instructions for the repo |
| 11 | Work on the current branch only |
| 12 | Commit before exiting. A dirty tree with no commit counts as a failed task |
| 13 | No pushes, no PRs, no writes to GitHub. Claude may read with `gh`. Yak does the remote git work |
| 14-15 | No secrets in commits. Kill background processes before finishing |
| 16 | Run long commands synchronously. No background jobs, no wakeup tools |
| 17 | Final summary format. The summary becomes the PR body: `## Summary` and `## Changes` |

Two blocks follow the rules when they apply: the Sentry channel rules (the `channels-sentry` prompt, only when the Sentry channel is enabled) and the repository's agent instructions. Linear has no extra rules, because Yak posts Linear updates itself.

For a multi-repo thread, Yak adds a "Repositories in this thread" section after the rules. It lists each repo with its path and role (`changes` or `context`). See [Multi-repo threads](multi-repo.md).

### Customizing The System Prompt

Open **Prompts** in the sidebar and pick **System Rules**. A saved edit takes effect on the next turn that starts a runner, with no redeploy. This is the place for team-wide rules ("use Conventional Commits", "never add npm dependencies").

Once you customize a prompt, the Blade file on disk is ignored for that deployment. Structural changes (a new variable, a new conditional block) need a code change. See [Development](development.md).

## Task Prompt Templates

The turn message comes from a template in `resources/views/prompts/tasks/`. Yak picks the template from the task's source and kind of turn, and fills in variables from the task (the error, the issue, the failing test).

| Prompt label (slug) | When Yak sends it |
|---|---|
| Sentry Fix (`tasks-sentry-fix`) | A Sentry alert created the task |
| Linear Fix (`tasks-linear-fix`) | A Linear issue created the task |
| Slack Fix (`tasks-slack-fix`) | A Slack message created the task. This is also the default for sources without their own template. It asks Claude to check whether the request is clear before acting |
| Flaky Test (`tasks-flaky-test`) | CI reported a flaky test |
| Preview Repair (`tasks-preview-repair`) | A branch preview failed to start |
| Research (`tasks-research`) | A research task. No code changes, the answer is prose or an HTML report |
| Research Follow-up (`tasks-research-follow-up`) | A question on a finished research task. It carries the previous answer |
| Follow-up (`tasks-follow-up`) | Feedback on an open PR |
| Retry (`tasks-retry`) | CI failed after the push. Carries the failure output |
| Clarification Answers (`tasks-clarification-answers`) | People answered Claude's questions |
| Setup (`tasks-setup`) | Environment setup for a repository |
| Repository Risk Profile (`tasks-risk-profile`) | Drafting a risk profile for review |
| PR Review (`tasks-review`) | Yak reviews a pull request. See [PR review](pr-review.md) |

When a task has other active repositories, the Sentry, Flaky Test, Linear and Slack templates also list them, so Claude can name the right repository when the checkout it got is not where the change lives. When a task comes from a Slack or other source that may be ambiguous, Claude can answer with clarification questions instead of code, and the answers arrive as a Clarification Answers turn.

### Sub-Thread Briefs

A sub-thread's first turn is not a template. Yak wraps the brief Claude wrote: "You are a sub-thread named ... of the thread ...", a note that the parent is an agent and cannot approve anything for a person, and then the brief. Its system prompt is the same Yak system prompt.

### Editing Prompts In The Dashboard

The editor at `/prompts` lists every prompt in two groups. Each prompt has a sample fixture, so the **Preview** tab shows the text Claude would get. Each save creates a version, and **History** lets you load or reset an older one.

- **High-touch:** System Rules, Personality (notification wording), Sentry Fix, Linear Fix, Slack Fix, Flaky Test, Preview Repair, Repository Risk Profile, PR Review.
- **Advanced:** Setup, Research, Research Follow-up, Retry, Clarification Answers, Follow-up, Channel: Sentry, and the helper prompts Repo Routing Agent, Risk Area Guesser, Task Intent Classifier, Review Feedback Triage, Description Summarizer, Task Title Writer, PR Title Writer, Partial: Clarification Contract and Partial: Wrong Repository.

All of them are editable. A save is validated: the Blade must compile against the fixture, and directives that pull external state or run PHP (`@include`, `@extends`, `@component`, `@php`) are rejected. If a saved prompt fails to render later, Yak logs a warning and uses the file on disk, so a bad save never blocks the pipeline.

Adding a new prompt or a new variable needs code. See [Development](development.md).

## Visual Capture

Rule 6 of the system prompt makes Claude record a walkthrough video and screenshots when a task changes the UI. Research, setup and review turns capture nothing.

Claude uses `yak-browser`, never `agent-browser` directly. `yak-browser` is installed in the sandbox image and accepts every `agent-browser` command, plus the walkthrough commands. The capture has two phases:

1. **Implement and verify.** Nothing is recorded. Claude starts the dev server (from `CLAUDE.md` or the README), logs in, and confirms the feature works on the real page. Temporary scaffolding (stubbed external calls, seeded data, a dev-only auth bypass) is allowed here.
2. **Script and shoot.** Claude writes `.yak-artifacts/script.json` (shots, narration, screenshots), runs `yak-browser script ... --review` to lint it, then `yak-browser shoot` to record one clip per shot. Yak edits the clips into one video. See [Video walkthroughs](video-walkthroughs.md).

Claude must revert scaffolding before committing and must end its summary with one status line:

- `Visual capture: done (real flow)`
- `Visual capture: done (isolated harness) -- <why the real surface was not capturable>`
- `Visual capture: partial -- <what was and was not captured>`
- `Visual capture: skipped -- <specific reason>`

`.yak-artifacts/` is ignored by git inside every sandbox, so capture files never land in a commit. Yak collects them and attaches them to the PR.

Because Claude finds the dev server command, URL and test login in `CLAUDE.md`, the README or seed files, keep those accurate.

## MCP Servers

MCP servers give Claude extra tools. Where they come from depends on the kind of run.

- **Thread turns** always get the `yak` server described above. Yak's start message passes no other server list. Other servers come from Claude's own configuration in the thread's config directory and from the repo's `.mcp.json`, through the setting sources.
- **One-shot runs** (PR review and setup) load the file at `YAK_MCP_CONFIG_PATH`, which Ansible generates with only the servers for enabled channels: Context7 and GitHub always, Linear and Sentry when those channels are enabled. The sandbox sees it at `/home/yak/mcp-config.json`.

Settings > MCP servers in the dashboard shows the servers Yak knows about and their login state.

Yak has no connection to production databases, customer data, Intercom or deployment tools, and Slack access is limited to its own threads.

## Customization Decision Tree

When you want to change Yak's behavior, pick the layer closest to the change.

```
Is the change specific to one repo?
├─ YES → Edit that repo's CLAUDE.md, or .yak/AGENTS.md for Yak-only rules
└─ NO → Is it wording, an added rule or a different phrasing
        of an existing prompt?
        ├─ YES → Edit it at /prompts. No redeploy.
        └─ NO → Does it need a new variable, a new prompt or
                a new conditional block?
                ├─ YES → Code change, then fine-tune at /prompts
                └─ NO → It is a configuration concern: config/yak.php
                        or the environment variables
```

The closer to the repo, the better. `CLAUDE.md` is version-controlled with the code it describes, reviewed by the team that owns it, and travels with branch merges. Changes to the system prompt affect every repo.
