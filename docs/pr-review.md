# PR Review

Yak can review pull requests on every enabled repository. When a PR is opened, updated, or marked ready for review, Yak dispatches a `review` task that runs the same Claude Code pipeline used for fixes and research — this time with read-only access and a rubric tuned for code review. The output is posted back to GitHub as a line-level review, complete with `suggestion` blocks where they fit.

## Enabling It

Go to the repo's settings page (`/repos/{id}/edit`) and flip the **PR Review** toggle. When you flip it on, a second switch appears — **Review all currently open PRs on save** — which, when kept on, enqueues a retroactive review for every eligible open PR the moment you save.

After that:

- Opening a new PR triggers a full review.
- Marking a draft PR ready-for-review triggers a full review.
- Reopening a closed PR triggers a full review.
- Pushing new commits to an already-reviewed PR (`synchronize`) triggers an **incremental** review — only the commits since Yak's last review are considered. Force-pushes fall back to a full review automatically.

PRs authored by the Yak app bot itself are skipped to avoid recursive reviews.

## Path Filters

Yak ships with sensible defaults for what to exclude from review — `vendor/**`, `node_modules/**`, build output, minified assets, editor config. The full default list is in `config/yak.php` under `pr_review.default_path_excludes`. Migrations and lockfiles are **not** excluded by default: migrations contain real logic (schema changes, indexes, destructive drops) worth a look, and lockfile diffs can surface dependency version bumps the author didn't highlight.

If the defaults are wrong for a repo, the repo settings page has a **PR review path filters** section. You can add glob patterns (e.g. `custom/generated/**`), remove specific patterns, or reset back to the global defaults. The patterns support `*`, `**`, and `?` just like `.gitignore`.

When path filters are active, Yak filters the changed file list before building the prompt AND filters findings Claude produces — so a model that still hallucinated a finding in `vendor/` gets silently dropped.

## Interpreting Reviews

Each review comment has three pieces of metadata:

| Field | Values | Meaning |
|---|---|---|
| Category | Simplicity, Test Quality, Performance, ... | What kind of issue this is |
| Severity | `must_fix`, `should_fix`, `consider` | How important |
| Suggestion | yes/no | Whether the comment contains a 1–10 line code suggestion block |

`consider` findings are bundled into a single collapsed "Nitpicks" block at the bottom of the review body — they don't create line-level comments. `should_fix` and `must_fix` are inline.

Every review ends with a **verdict**: `Approve`, `Approve with suggestions`, or `Request changes`. The verdict is advisory — Yak does not use GitHub's Request Changes feature.

## Linear Ticket Context

If a PR body or title references a Linear ticket identifier (e.g. `GEO-1234`), and the Yak instance has an active Linear OAuth connection, Yak fetches the ticket's title and description and injects them into the review prompt. A new rubric category, **Ticket Alignment**, becomes available; Claude flags cases where the PR drifts from what the ticket actually asked for.

No Linear connection? No identifier in the PR text? The review just skips this context silently.

## Sandbox Tests

The review runs inside the repo's normal Incus sandbox, so Claude can execute tests and type checkers against the changed files. The prompt explicitly encourages:

- Running test suites when a subset is identifiable from `CLAUDE.md`
- Running type checkers (`phpstan`, `tsc`) and linters (`pint`, `eslint`, `biome`) on changed files
- Promoting genuine failures to `must_fix` findings

Style-only issues that auto-formatters catch are suppressed — the prompt forbids them.

## Tuning The Prompt

The review prompt lives at `resources/views/prompts/tasks/review.blade.php` and is editable from `/prompts` under the slug `tasks-review`. Per-repo `agent_instructions` (set on the repo settings page) are appended to the prompt automatically.

## Dashboard

The **PR Reviews** tab (top-level nav) shows every finding Yak has posted, filterable by severity, category, repo, scope, and reviewer. Reactions (👍 / 👎) posted on GitHub are polled hourly and rolled up — you can see which categories trend helpful vs. noisy at a glance.

- `/pr-reviews` — main table
- `/pr-reviews/for/{repoSlug}/{prNumber}` — all Yak reviews for a specific PR, with a **Re-run review** button

TaskDetail (`/tasks/{id}`) for a `review` task shows three additional panels: review output metadata, a rendered Markdown preview, and the full findings table.

## Limitations

- Yak does not post **approvals** — the verdict is advisory. GitHub's branch protection still requires a human reviewer if configured that way.
- Review accuracy scales with the prompt; expect some noise on `consider` findings. Use the 👎 reaction to signal false positives — the dashboard surfaces patterns.
- The `max_findings_per_review` cap (default 20) keeps reviews focused. Tune via `config/yak.php` if needed.
- Incremental reviews only compute the diff between Yak's last reviewed SHA and the current head. Re-reviews of the full PR can always be triggered from TaskDetail.

## Troubleshooting

- **Review never posted** — Check the TaskDetail page for the failed review task. The sandbox might have failed to check out the PR head, or the JSON output from Claude might be malformed. Both failure modes are logged in the activity log.
- **Review posted but comments missing** — Path filters may be too aggressive. Check `config/yak.php` `pr_review.default_path_excludes` or the per-repo overrides.
- **Reactions not showing up** — The polling job runs hourly. Force a manual poll with `php artisan schedule:run` or wait for the next cycle.
- **Linear ticket context not included** — Confirm a `LinearOauthConnection` exists and the ticket identifier follows the `[A-Z]{2,6}-\d+` pattern.
