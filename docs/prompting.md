# Prompting

This page is for teams who want to customize Yak's behavior: adjust its rules, tune templates for a specific source, or add context that Claude Code receives on every task. If you just want Yak to work against your codebase, the answer is almost always **edit `CLAUDE.md` in the target repo**, which is covered in [Repositories](repositories.md#write-a-claudemd).

## Three Prompt Layers

Claude Code receives three distinct prompt inputs on every task. Each one lives in a different place and serves a different purpose.

| Layer | Where it lives | Scope | Who maintains it |
|---|---|---|---|
| **`CLAUDE.md`** | Root of the target repo | Per-repo conventions, test patterns, do-not-touch lists | The team that owns that repo |
| **`--append-system-prompt`** | Yak's runtime (assembled by `YakPromptBuilder`) | Operating rules, commit format, scope limits, visual capture, if-stuck behavior. Same for every task. | Yak itself (the "Yak persona") |
| **`-p` prompt** | Assembled from Blade templates (with optional DB overrides) per task | Task description, source-specific context, instructions | Yak assembles at runtime from source + template |

These stack: `CLAUDE.md` is loaded by Claude Code itself from the repo, the system prompt is appended to Claude's built-in prompt via `--append-system-prompt`, and the `-p` prompt is the task-specific instructions.

> **Every prompt (both the system prompt and the task prompts) can be tweaked live from the dashboard at `/prompts` without a redeploy.** See [Editing prompts in the dashboard](#editing-prompts-in-the-dashboard) below.

## CLI Invocation

The CLI command is built by `SandboxedAgentRunner` (the `AgentRunner` implementation in `app/Agents/`) and executed inside the task's Incus sandbox via `incus exec`:

```bash
claude -p "$TASK_PROMPT" \
    --dangerously-skip-permissions \
    --output-format stream-json --verbose \
    --model opus \
    --max-turns 300 \
    --max-budget-usd 5.00 \
    --append-system-prompt "$YAK_SYSTEM_PROMPT" \
    --mcp-config /home/yak/mcp-config.json
```

| Flag | Why |
|---|---|
| `--dangerously-skip-permissions` | Each task runs in an isolated Incus sandbox. The sandbox is the boundary. |
| `--output-format stream-json` | Incremental events streamed back to the task log in real time (falls back to `json` when the caller doesn't need streaming). |
| `--model opus` | Implementation always uses Opus. Configurable via `YAK_DEFAULT_MODEL`. |
| `--max-turns 300` | Large enough to cover long read → plan → edit → test → fix → commit loops, including retries. Configurable via `YAK_MAX_TURNS`. |
| `--max-budget-usd 5.00` | Per-task runaway guardrail. Configurable via `YAK_MAX_BUDGET_PER_TASK`. |
| `--append-system-prompt` | Yak persona. Appends, doesn't replace Claude's built-in prompt. |
| `--mcp-config` | Context7 always; Sentry and GitHub when their credentials are set. See [MCP Servers](#mcp-servers). |

Retries and clarification replies add `--resume $session_id` to continue the original session. See [Architecture → Session Continuity](architecture.md#session-continuity).

The command runs inside the sandbox container as the unprivileged `yak` user. App secrets (`DB_PASSWORD`, `APP_KEY`, etc.) live only in the yak app container and are never present in the sandbox's environment.

## Model Selection

### Routing Layer

The routing layer (Laravel AI) picks between Haiku and Sonnet based on the task:

```php
$model = match (true) {
    $needsCodeComprehension => 'sonnet', // Sentry triage, complex context assembly
    default                 => 'haiku',  // Parsing, formatting, simple routing
};
```

### Implementation Layer

Always Opus. Both initial runs and retries. Opus produces better first-attempt results, which means fewer retries and less total work.

## Prompts

Yak assembles the system prompt and each task prompt at runtime from Blade templates. Every one can be edited at **Prompts** in the dashboard (`/prompts`), which shows a live preview. The dashboard is the source of truth for the current text, so this page does not copy it.

| Slug | Used when | Worth customizing |
|---|---|---|
| `system` | Every task run (system prompt). Scope and safety rules, commit format, visual capture. | Team-wide rules |
| `personality` | Writing notification messages | Tone |
| `tasks-sentry-fix` | A Sentry alert creates a task | Extra triage hints |
| `tasks-linear-fix` | A Linear issue creates a task | Conventions for issue-driven work |
| `tasks-slack-fix` | A Slack message creates a task | The ambiguity check |
| `tasks-flaky-test` | The CI scan finds a flaky test | Fix policy (fix vs. quarantine) |
| `tasks-review` | Yak reviews a pull request | Review focus |
| `tasks-risk-profile` | Drafting a repository risk profile | Rarely |
| `tasks-setup` | Repository setup tasks | Rarely |
| `tasks-research` | Research tasks | Report format |
| `tasks-retry` | A task re-runs after failing CI | Rarely |
| `tasks-clarification-reply` | A clarification is answered | Rarely |
| `tasks-follow-up` | Feedback on an open PR | Rarely |
| `channels-sentry` | Sentry channel enabled (appended to `system`) | Rarely |
| `agents-repo-routing`, `agents-task-intent`, `agents-review-feedback-triage`, `agents-description-summary`, `agents-pr-title` | Small internal agents that route and summarize | Rarely |
| `partials-clarification-contract` | Shared clarification instructions | Rarely |

The Blade files live in `resources/views/prompts/`. Slug metadata and variables are declared in `app/Prompts/PromptDefinitions.php`.

### Customizing The System Prompt

Pick **System Rules** in the editor. Saved edits are stored in the `prompts` table and apply to the next task, with no redeploy. This is where team-wide rules belong ("always use Conventional Commits", "never add npm dependencies"). Rules for one repo belong in that repo's `CLAUDE.md`, because the system prompt applies to every task.

Linear has no prompt injection into the system prompt. Yak posts agent session activity and updates issue state server-side, and the issue text is already in the task prompt.

## Task Prompt Templates

Yak picks the task slug from the task's source and mode, then renders it with the variables the slug declares. If the slug has a customized row in the `prompts` table, that content is used. Otherwise the Blade file on disk is used.

### Editing prompts in the dashboard

- Pick a slug, edit, and check the **Preview** tab, which renders against a sample fixture.
- Saves are validated. The Blade must compile, and directives that pull external state or run PHP (`@extends`, `@component`, `@php`, and `@include` outside `prompts.partials.*`) are rejected.
- Each save creates a version, so you can roll back.
- If a saved prompt fails to render at runtime, Yak logs a warning and falls back to the Blade file on disk. A bad save never breaks the pipeline.

Once a prompt is customized, editing its Blade file has no effect on your deployment. Edit the files only to change the default that comes with Yak. A new slug or a new variable needs a code change: wire it in `app/YakPromptBuilder.php` and declare it in `app/Prompts/PromptDefinitions.php`, plus a sample in `PromptFixtures`.

Keep templates short. Claude Code does the heavy lifting, and long templates crowd out context.

## Visual Capture

When a task touches UI, the system prompt tells Claude to record a video walkthrough and take screenshots with `yak-browser`. Research and setup tasks skip capture.

Claude reads `CLAUDE.md` and `README.md` to find how to start the dev server, the dev URL and test credentials. Keep those accurate in the target repo's `CLAUDE.md`.

Every task summary ends with a `Visual capture:` status line (`done`, `partial` or `skipped`, with a reason), so a skipped capture is visible in the PR. See [Video Walkthroughs](video-walkthroughs.md) for how the video is produced.

## MCP Servers

Ansible generates `/home/yak/mcp-config.json` from a template. It only includes servers that are configured, so Claude never sees tools for disabled integrations.

| Server | Included when | Purpose |
|---|---|---|
| **Context7** | Always | Current library documentation |
| **Sentry** | A Sentry auth token is set | Breadcrumbs, tags, related events |
| **GitHub** | `github_personal_access_token` is set | Reading related PRs and issues |

Add more servers at **MCP** (`/mcp`) in the dashboard. Yak has no connection to production databases, customer data systems, or deployment tools.

## Customization Decision Tree

```
Is the change specific to one repo?
├─ YES → Edit that repo's CLAUDE.md
└─ NO → Does it fit an existing prompt slug (wording, added rules)?
        ├─ YES → Edit it at /prompts. No redeploy.
        └─ NO → Does it need a new variable, slug, or channel block?
                ├─ YES → Code change (see "Editing prompts in the dashboard")
                └─ NO → A configuration concern: edit config/yak.php or the vault
```

The closer to the target repo, the better. `CLAUDE.md` is version-controlled with the code it describes and reviewed by the team that owns it. Changes to the system prompt affect every repo.
