# Prompting

This page is for teams who want to customize Yak's behavior — adjust its rules, tune templates for a specific source, or add context that Claude Code receives on every task. If you just want Yak to work against your codebase, the answer is almost always **edit `CLAUDE.md` in the target repo**, which is covered in [repositories.md](repositories.md#claudemd--the-highest-leverage-config-point).

## Three Prompt Layers

Claude Code receives three distinct prompt inputs on every task. Each one lives in a different place and serves a different purpose.

| Layer | Where it lives | Scope | Who maintains it |
|---|---|---|---|
| **`CLAUDE.md`** | Root of the target repo | Per-repo conventions, test patterns, do-not-touch lists | The team that owns that repo |
| **`--append-system-prompt`** | Yak's runtime (assembled by `YakPromptBuilder`) | Operating rules, commit format, scope limits, visual capture, if-stuck behavior. Same for every task. | Yak itself — the "Yak persona" |
| **`-p` prompt** | Assembled from Blade templates per task | Task description, source-specific context, instructions | Yak assembles at runtime from source + template |

These stack: `CLAUDE.md` is loaded by Claude Code itself from the repo, the system prompt is appended to Claude's built-in prompt via `--append-system-prompt`, and the `-p` prompt is the task-specific instructions.

## CLI Invocation

The exact invocation for every task:

```bash
claude -p "$TASK_PROMPT" \
    --dangerously-skip-permissions \
    --output-format json \
    --model opus \
    --max-turns 40 \
    --max-budget-usd 5.00 \
    --append-system-prompt "$YAK_SYSTEM_PROMPT" \
    --mcp-config /home/yak/mcp-config.json
```

| Flag | Why |
|---|---|
| `--dangerously-skip-permissions` | Isolated server, no production access. Machine is the boundary. |
| `--output-format json` | Parseable result with cost, session_id, success/failure |
| `--model opus` | Implementation always uses Opus |
| `--max-turns 40` | Enough for read → plan → edit → test → fix → commit. Not infinite. |
| `--max-budget-usd 5.00` | Per-task runaway guardrail |
| `--append-system-prompt` | Yak persona. Appends, doesn't replace Claude's built-in prompt. |
| `--mcp-config` | Context7 and GitHub always; Linear and Sentry conditionally |

Retries and clarification replies add `--resume $session_id` to continue the original session — see [architecture.md](architecture.md#session-continuity).

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

## The Yak System Prompt

The system prompt is assembled at runtime by `YakPromptBuilder`. Rules referencing channel-specific MCP servers (Linear, Sentry) are only included when those channels are enabled — this keeps the prompt clean and prevents Claude from seeing instructions for tools it doesn't have access to.

```
You are Yak, an autonomous coding agent.
You work unattended. Your output will be a pull request that a human reviews.

## Rules

1. SCOPE: Stay focused on the task at hand. Don't expand scope or
   refactor unrelated code — keep the diff as small as the fix allows.

2. MINIMAL CHANGES: Fix the described issue. Don't refactor surrounding
   code or improve things that aren't broken.

3. UNDERSTAND FIRST: Read relevant files before writing code. Use grep/glob
   to find related code. Check git log for recent changes.

4. TEST LOCALLY: After making changes, find and run the tests most relevant
   to the files you changed. Do not run the entire test suite — just the
   tests that cover your changes. The full suite runs on CI after you push.

5. COMMIT: Single clean commit:
   [{TASK_ID}] Short description

   What was wrong and why.
   What was changed and why.

   Automated fix by Yak

6. VISUAL CAPTURE: When the task involves UI changes (frontend, CSS,
   forms, pages, layouts), record a video walkthrough AND take
   screenshots of the affected area.
   a. Start the dev server (read CLAUDE.md/README for how).
   b. If authentication is needed, read CLAUDE.md/README or seeder files
      for test credentials. Log in using agent-browser.
   c. Navigate to the page affected by your changes.
   d. For screenshots: agent-browser screenshot --full
      Save to .yak-artifacts/
   e. For video (multi-step flows): agent-browser record start
      .yak-artifacts/walkthrough.webm — walk through, then stop.
   f. If something blocks a *full* capture (dev server won't start, auth
      can't be bypassed, an external dependency like a deploy trigger or
      payment API can't be reached), do a PARTIAL capture — record
      whatever state you CAN reach. Never skip silently.
   g. TEMPORARY HELPERS (must be reverted before committing): You MAY
      add short-lived scaffolding to make a capture possible — seed a
      test user via `php artisan tinker`, stub out an external call
      (e.g. comment out a Drone CI dispatch, fake a payment gateway),
      add a dev-only route, or bypass auth for the test URL. These
      changes MUST be reverted before `git commit`. Run
      `git diff --stat` immediately before committing and confirm only
      the files you intended to change are staged. After committing,
      run `git show --stat HEAD` and re-read the diff to verify no
      temporary scaffolding slipped through.
   h. Stop the dev server when done.
   i. REQUIRED STATUS LINE: End the result summary with exactly one of
      these lines — no exceptions:
      - `Visual capture: done`
      - `Visual capture: partial — <what was captured and what wasn't>`
      - `Visual capture: skipped — <specific reason>`
      A missing line is a task violation. Silent skipping is not
      allowed.

7. SCOPE CHECK: If the task's requirements are ambiguous or unclear,
   stop. Commit nothing and output what you found and why this needs
   human judgment.

8. IF STUCK: Don't make random changes. Commit nothing and output:
   - What you investigated
   - What the root cause likely is
   - Why you couldn't fix it
   - What a human should look at

9. CONTEXT7: Use Context7 MCP for current library docs when unsure.

10. DEV ENVIRONMENT: The dev environment should already be set up (via the
    setup task when the repo was added). To start it: docker-compose start
    (or read CLAUDE.md/README for the correct command). Stop it when done:
    docker-compose stop. If the environment isn't working, note it in the
    result and proceed without it if possible.
```

### Channel-Specific Additions

These are appended only when the corresponding channel is enabled:

```
# Appended when Linear channel is enabled:
11. LINEAR: If from Linear, read the full issue and comments via Linear MCP.

# Appended when Sentry channel is enabled:
12. SENTRY: If from Sentry, use Sentry MCP to pull breadcrumbs, tags,
    and related events for fuller context.
```

### Customizing The System Prompt

The system prompt lives in the `YakPromptBuilder` class (`app/YakPromptBuilder.php`). If you want to add team-wide rules — "always use Conventional Commits," "never introduce new npm dependencies," "prefer pure functions" — add them there.

Be careful with scope creep: the system prompt applies to every task. Rules that apply to only one repo belong in that repo's `CLAUDE.md`, not the system prompt.

## Task Prompt Templates

Task prompts are Blade templates in `resources/views/prompts/`. Yak picks the template based on the task's `source` field and renders it with source-specific variables.

### Sentry Fix

```
## Task: {external_id}
Fix the following Sentry error.

### Error
{issue_title}

### Culprit
{culprit}

### Stacktrace
{top_10_frames}

### Context
- Occurrences: {event_count}
- First seen: {first_seen}
- Users affected: {affected_users}

Use the Sentry MCP to pull breadcrumbs and related events for this issue
if the stacktrace alone isn't enough to understand the problem.

### Instructions
1. Read the culprit file and trace the code path
2. Implement a fix
3. Add or update a test that would have caught this
4. Run the relevant tests locally to verify
5. Commit
```

### Flaky Test Fix

```
## Task: {external_id}
Fix the failing test on the main branch.

### Test
{test_class}::{test_method}

### Failure Output
{failure_output}

### CI Build
{build_url}

### Instructions
1. Read the failing test and the code it covers
2. Determine: flaky test or real bug?
3. Fix accordingly
4. Run the relevant tests locally
5. Commit
```

### Linear Fix

```
## Task: {external_id}
{issue_title}

### Description
{issue_description}

### Instructions
1. Read comments on {external_id} via Linear MCP
2. Implement the fix with minimal changes
3. Write or update tests
4. Run relevant tests locally
5. Commit
```

### Research

```
## Task: {external_id} (Research — no code changes)
{issue_title}

### Description
{issue_description}

### Instructions
Do NOT make code changes or commits.

1. Investigate using grep, glob, file reading
2. Read Linear comments for context if from Linear
3. Use Context7 for library docs if needed
4. Write your findings as a standalone HTML page:
   - Self-contained (inline CSS, no external dependencies)
   - Clean, readable, professional formatting
   - Structure: summary, detailed findings (with file paths and
     line numbers), recommendations, effort estimate, risks
   - Save to .yak-artifacts/research.html
5. Also output a plain text summary (2-3 sentences) as the result_summary.
   This summary will be posted to the source with a link to the full
   findings page.
```

### Slack Fix (with ambiguity check)

```
## Task: {external_id}
{description}

### Source
Requested via Slack by {requester_name}.

### Ambiguity Check
Before starting work, assess whether this request is clear enough to
implement confidently. Read relevant code, check Sentry for related recent
errors, and consider whether the request has multiple valid interpretations.

If the task is CLEAR — proceed to implementation.

If the task is AMBIGUOUS — do NOT implement anything. Instead, output ONLY
a JSON object with your clarification options:
{"clarification_needed": true, "options": ["Description of interpretation 1", "Description of interpretation 2", "Description of interpretation 3"]}

Make options specific and grounded in what you found in the code, not
generic. The user will pick one and you'll resume from here.

### Instructions (if clear)
1. Implement the fix with minimal changes
2. Write or update tests
3. Run relevant tests locally
4. Commit
```

### Clarification Reply (via `--resume`)

```
The user chose option {n}: "{option_text}"

Proceed with this interpretation. Implement the fix, test, and commit.
```

### Retry (via `--resume`)

```
CI failed after your previous push. Here's the failure output:

{ci_failure_output}

### Instructions
1. Read the CI failure carefully
2. Check your previous changes: git diff HEAD~1
3. Determine what went wrong
4. Fix it — different approach if needed
5. Run the relevant tests locally
6. Commit (amend or new commit)
```

### Customizing Templates

Edit the Blade views in `resources/views/prompts/`. Variables come from the task row and the source-specific context parser. Keep templates short — Claude Code is the one doing the heavy lifting, and long templates crowd out context.

## Visual Capture

Visual capture is driven by rule 6 in the system prompt. Claude records a video walkthrough AND takes screenshots whenever a task touches UI:

- **Research tasks** — no capture (nothing to show)
- **Setup tasks** — no capture
- **Code changes touching UI files** — screenshots + video walkthrough

No special prompt block is appended. Claude follows rule 6 and reads `CLAUDE.md`/`README.md` to find:

- How to start the dev server
- The dev URL
- Test credentials (from `CLAUDE.md`, `README.md`, or seeder files)

Visual capture is flexible by design — different repos have different setups, and Claude adapts to what it finds rather than relying on stored configuration. The cost of this flexibility is that `CLAUDE.md` needs to be accurate about how to run the dev server.

### Partial captures and temporary helpers

If something blocks a full capture (dev server won't start, an external dependency like a deploy trigger or payment API can't be reached, a feature fires only after an event that requires real infrastructure), Claude records a partial capture rather than skipping. Claude may also add short-lived scaffolding — seeding a test user via tinker, stubbing out an external call, adding a dev-only route, bypassing auth — and MUST revert it before committing. A `git diff --stat` pre-commit and a `git show --stat HEAD` post-commit check are both part of the prompt.

### Required status line

Every task result summary must end with one of:

- `Visual capture: done`
- `Visual capture: partial — <what was and wasn't captured>`
- `Visual capture: skipped — <specific reason>`

This turns silent skips into loud ones — reviewers can see at a glance whether visual verification happened.

### agent-browser

Visual capture uses the `agent-browser` CLI, not an MCP server. Claude invokes it as bash commands during the session. This supports video recording, mobile viewports, and authentication state — capabilities that would be awkward in a stateless MCP browser process.

`agent-browser` is installed globally on the Yak server via the Docker image. Headless Chromium is the only system dependency.

## MCP Servers

The Ansible provisioner generates `/home/yak/mcp-config.json` from a template, including only the MCP servers for enabled channels. This keeps Claude Code's tool list clean — no phantom tools for disabled integrations.

### Always Included

```json
{
  "mcpServers": {
    "context7": {
      "command": "npx",
      "args": ["-y", "@upstash/context7-mcp@latest"]
    },
    "github": {
      "type": "http",
      "url": "https://api.githubcopilot.com/mcp",
      "headers": {
        "Authorization": "Bearer ${GITHUB_PAT}"
      }
    }
  }
}
```

### Conditionally Included

When the corresponding channel is enabled:

```json
{
  "linear": {
    "type": "http",
    "url": "https://mcp.linear.app/sse",
    "headers": {
      "Authorization": "Bearer ${LINEAR_API_KEY}"
    }
  },
  "sentry": {
    "type": "http",
    "url": "https://mcp.sentry.dev/sse",
    "headers": {
      "Authorization": "Bearer ${SENTRY_AUTH_TOKEN}"
    }
  }
}
```

### Server Summary

| Server | Condition | Purpose | Access |
|---|---|---|---|
| **Context7** | Always | Current library documentation | Read-only, no auth |
| **GitHub** | Always | PR creation, reading related PRs | Push, PR create, issue read |
| **Linear** | Linear enabled | Read issues and comments, post results | Read + comment |
| **Sentry** | Sentry enabled | Breadcrumbs, tags, related events | Read-only |

### What Is NOT Connected

No production databases, no customer data systems, no Intercom, no deployment tools, no Slack write-access beyond its own threads. Yak has no way to reach these even if asked.

## Customization Decision Tree

When you want to change Yak's behavior, pick the right layer:

```
Is the change specific to one repo?
├─ YES → Edit that repo's CLAUDE.md
└─ NO → Is the change about task templates or source handling?
        ├─ YES → Edit the Blade templates in resources/views/prompts/
        └─ NO → Is the change about Yak's operating rules (always-apply)?
                ├─ YES → Edit YakPromptBuilder (app/YakPromptBuilder.php)
                └─ NO → It's probably a configuration concern — edit
                        config/yak.php or the vault variables
```

As a rule of thumb: **the closer to the target repo, the better**. `CLAUDE.md` in the repo is version-controlled with the code it describes, reviewed by the team that owns it, and travels with branch merges. Changes to Yak's global config or system prompt need a Yak redeploy and affect every repo.
