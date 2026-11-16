# PR Review

Yak can review pull requests on every enabled repository. When a PR is opened or marked ready for review, Yak starts a review task. It runs as a one-shot Claude run, not in a thread: Yak clones a sandbox from the repository's template, checks out the PR head, runs Claude with a review rubric, posts the result to GitHub and destroys the sandbox. The run uses the repository's unattended Claude account (see [Claude accounts](claude-accounts.md)). The output is a line-level GitHub review with `suggestion` blocks where they fit.

## Enabling It

Open the repository page (see [Repositories](repositories.md#the-repository-page)) and switch on **PR review** under **Automation**. When you switch it on, a second switch appears, **Review all currently open PRs on save**. Kept on, it queues a review for every open, non-draft PR when you save. Later, the **Re-review all open PRs** button does the same. You can also set `review.enabled` in `.yak/config.yml` (see [Repository config](repo-config.md#configyml)), which locks the toggle to the file value.

After that:

- Opening a new PR triggers a full review.
- Marking a draft PR ready-for-review triggers a full review.
- Reopening a closed PR triggers a full review.
- **Pushing new commits does not trigger another review.** Yak stays quiet while you keep iterating, so a series of commits does not produce a wall of overlapping reviews.

Draft PRs are skipped.

PRs authored by the Yak app bot are reviewed when `YAK_PR_SELF_REVIEW_ENABLED` is enabled (the default). They cannot receive approval or a change request from that same app identity.

## Asking For Another Review

When you're ready for a fresh pass, click **Re-request review** next to the Yak bot in the PR's Reviewers sidebar on GitHub. Yak runs an **incremental** review: only the commits since its last review are considered. If no prior Yak review exists on the PR, it falls back to a full review. Force-pushes between reviews also fall back to a full review automatically.

This is the only automatic re-review trigger: there is no slash command and no label. The button is GitHub-native and only appears after Yak has submitted at least one review on the PR. In the dashboard, **Re-run review** on the PR's page under **PR reviews** runs another review.

## Asking Yak To Fix A Finding

Reply to a Yak finding with `/yak` and a request, for example `/yak fix this please`. Yak commits the fix on top of the PR's branch, including on a PR a person opened, and replies in the thread with the commit. It never force-pushes and never edits the PR description. See [Follow-ups on PRs Yak did not open](channels.md#on-prs-yak-did-not-open) for the details.

## Path Filters

Yak leaves some paths out of review by default: `vendor/**`, `node_modules/**`, `public/build/**`, `public/hot`, `storage/**`, `*.min.js`, `*.min.css`, `.idea/**` and `.vscode/**`. The list is the host's `pr_review.default_path_excludes` setting. Migrations and lockfiles are not excluded: migrations contain real logic, and lockfile diffs can surface dependency bumps the author did not mention.

Where the repository's list comes from, in order:

1. `review.exclude_paths` in `.yak/config.yml`. When the key is present it replaces everything else, and **PR review path filters** on the repository page shows the file value read-only with a **Propose a change** link.
2. The list saved on the repository page: add patterns with **Add pattern**, remove a pattern with its x, or choose **Reset to defaults**.
3. The host defaults above.

Patterns support `*`, `**` and `?` like `.gitignore`. Yak filters the changed-file list before it builds the prompt and also drops findings on excluded paths, so a stray finding in `vendor/` never posts.

## Interpreting Reviews

Each review comment has three pieces of metadata:

| Field | Values | Meaning |
|---|---|---|
| Category | Correctness, Security, Data, Compatibility, Ticket Alignment, Tests, Performance, Conventions | What kind of issue this is, in the order Yak hunts for them |
| Severity | `must_fix`, `should_fix`, `consider` | How important |
| Suggestion | yes/no | Whether the comment contains a 1 to 10 line code suggestion block |

Comments are written like a senior engineer's inline GitHub comments: one sentence that names the concrete failure, with `nit:` marking the optional ones. Yak does not post a review report, a summary of what the PR does, or a list of what it tested. A clean review body says `LGTM` and nothing else. Findings whose line falls outside a diff hunk are folded into the review body; `consider` findings that can't be posted inline land in a collapsed "Nitpicks" block.

Every review body ends with a collapsed **For the reviewer** block. It is written for the human doing the intent review, not the author: what the PR does, how it covers the Linear ticket's requirements, risk areas worth a human question, and what Yak verified in the sandbox versus what it could not check. Open it before you start your own review.

Yak records a **verdict** (`Approve`, `Approve with suggestions`, or `Request changes`) for the dashboard. By default, reviews remain GitHub comments. Opted-in repositories can use the risk policy below to submit approvals or change requests. Merging always remains a human action.

The findings panel labels the model verdict separately from the actual GitHub
review event. It shows risk, subjective confidence, policy reasons, and expandable
signals and observed evidence. Shadow recommendations are explicitly labelled and
never displayed as an approval that was submitted.

## Risk-based approval (opt-in)

By default a review is a GitHub comment. A repository can opt in to approvals. Yak never merges. Start with **Shadow** on one repository and compare its decisions with human reviews before using **Enforce**. The score and confidence thresholds are starting heuristics, not calibrated probabilities.

### Settings

Set these under **Risk-based approval** on the repository page, or in `review.approval` in `.yak/config.yml` (a key in the file locks its field). Nothing is on by default.

| Setting | Meaning | Default |
|---|---|---|
| Approval mode | **Off** (comments only), **Shadow** (record what Yak would decide) or **Enforce** (submit eligible approvals) | Off |
| Allowed paths | Only changes inside these globs can be approved | none |
| Additional blocked paths | Always need a human. Added to Yak's built-in blocked paths, never replacing them | none |
| Required checks and commit statuses | Checks and statuses that must pass. In the dashboard a check needs its name and trusted GitHub App ID, a status its context and trusted creator ID. A file lists check names only, matched whichever app reported them | none |
| Maximum changed files | | 5 (1 to 100) |
| Maximum changed lines | | 150 (1 to 5000) |
| Maximum risk score | | 30 (0 to 30) |
| Minimum model confidence | | 80 (80 to 100) |
| Profile validity (days) | How old an approved risk profile may be | 90 (1 to 90) |

At least one trusted CI source is required for approval. The built-in blocked paths (CI config, `database/**`, `routes/**`, `config/**`, auth, billing, lockfiles and more) and the files that control Yak itself (`.yak/**`, CODEOWNERS, `AGENTS.md`, `CLAUDE.md`) always need a human. Settings never come from the PR branch.

### Risk profiles

A risk profile lists the risky areas of the codebase. Without an approved, current profile Yak never approves. Under **Risk-based approval**:

1. Choose **Generate draft with Claude**. Yak runs a research task and shows the result when it completes.
2. Read the areas, evidence and open questions. Use **Edit draft JSON** and **Save as new draft** to change it.
3. Turn on **I reviewed this exact draft** and choose **Approve this profile**. Yak records who approved it.

A repository can also keep the profile in `.yak/risk-profile.yml`, which locks this section (see [Repository config](repo-config.md#risk-profileyml)). Open questions in the profile block approval until they are answered. Operators can use `php artisan yak:risk-profile <slug>` to draft, `--import=<file.json>` to load an edited draft and `--approve=<draft-hash> --reviewer=<name>` to activate one. Import and approval are separate steps. The prompt is editable at **Prompts**, `tasks-risk-profile`.

### How the decision works

The reviewer rates five things from 0 to 4: impact, blast radius, behavior change, verification strength and context completeness. Yak computes the risk score as the highest of `impact*25`, `blast_radius*20`, `behavior_change*15`, `(4-verification_strength)*20` and `(4-context_completeness)*20`. A profile area sets a floor: low 0, medium 50, high 75, critical or unknown 100. Approval needs a score of at most the maximum (30 by default) and every other gate. Model confidence is a separate self-reported number. It cannot lower risk. Missing or malformed signals give an unknown score, which blocks approval.

Yak approves only a full, clean review of a low-risk change within the file and line limits. All PR files count, including excluded paths. These need a human:

- unknown or blocked paths, and deleted or renamed files
- missing or truncated diffs
- modified or removed existing tests, and added skips or TODOs
- code changes with no added tests (test paths are a host setting, `pr_review.approval_test_paths`)
- prior findings still open, or a review that was not full

A concrete `must_fix` finding on an otherwise eligible PR gives a Request changes review, unless the only one sits in an excluded path the author never sees. That case blocks approval and stays a comment. Other concerns give a comment.

Before it approves, Yak checks the live head and base commits, the base branch, PR state, that the head is in the same repository, the file count, resolved review threads and the configured checks and statuses. Pending, skipped, failed or missing CI blocks approval. If CI finishes after the review, request another review. Yak schedules nothing in the background. Re-reviews of an opted-in repository always run in full.

The GitHub App must be able to read the base branch's protection, and the branch must have `dismiss_stale_reviews` enabled. Otherwise Yak falls back to a comment. Yak never changes branch protection. A PR with auto-merge enabled is ineligible. The review is pinned to the reviewed commit, so new commits need a fresh approval. A rejected submission falls back to a comment, with no second approval attempt.

The same GitHub App cannot approve its own PRs. Yak-authored PRs stay comment-only, even in **Enforce**. The review body and the task page show the decision and reasons. The findings panel on the task page labels the model verdict separately from the real GitHub review event. Shadow recommendations are labelled and never shown as a submitted approval.

If you customized the review prompt, keep the Risk signals section from the default prompt. Without it, no signals arrive and approval is blocked.

## Linear Ticket Context

If a PR body or title references a Linear ticket identifier (e.g. `GEO-1234`), and the Yak instance has an active Linear OAuth connection, Yak fetches the ticket's title and description and injects them into the review prompt. A new rubric category, **Ticket Alignment**, becomes available; Claude flags cases where the PR drifts from what the ticket actually asked for.

No Linear connection? No identifier in the PR text? The review just skips this context silently.

## Sandbox Tests

The review runs in a sandbox cloned from the repository's template, so Claude can execute tests and type checkers against the changed files. The prompt explicitly encourages:

- Running test suites when a subset is identifiable from `CLAUDE.md`
- Running type checkers (`phpstan`, `tsc`) and linters (`pint`, `eslint`, `biome`) on changed files
- Promoting genuine failures to `must_fix` findings

Style-only issues that auto-formatters catch are suppressed: the prompt forbids them.

## Tuning The Prompt

The review prompt is editable under **Prompts**, slug `tasks-review` (it starts from `resources/views/prompts/tasks/review.blade.php`). The repository's **Agent instructions** are added to the instructions automatically.

## Dashboard

The **PR reviews** page shows every finding Yak posted, with filters for severity, category, repo, scope and reviewer, and a toggle for comments with reactions. 👍 and 👎 reactions on GitHub are polled hourly and rolled up, so you can see which categories trend helpful and which are noisy.

- `/pr-reviews`: the main table.
- `/pr-reviews/for/{repoSlug}/{prNumber}`: every Yak review of one PR, with a **Re-run review** button.

The task page of a review task shows the findings panel: the model verdict, the counts of must-fix, should-fix and consider findings, the risk details when approval is on, the review summary and each comment.

## Limitations

- Reviews are advisory unless the repository opts into risk-based approval. GitHub branch protection and CODEOWNERS requirements still apply; a Yak approval does not necessarily satisfy every required reviewer rule.
- Review accuracy scales with the prompt; expect some noise on `consider` findings. Use the 👎 reaction to signal false positives: the dashboard surfaces patterns.
- The `max_findings_per_review` cap (default 10) keeps reviews focused. Tune via `config/yak.php` if needed.
- Incremental reviews only compute the diff between Yak's last reviewed SHA and the current head. A full re-review is always available with **Re-run review**.

## Troubleshooting

- **Review never posted**: open the failed review task from the **PR reviews** page or the task list. The sandbox might have failed to check out the PR head, or Claude's output might be malformed. The task log records both.
- **Review posted but comments missing**: path filters may be too aggressive. Check the list under **PR review path filters** and `review.exclude_paths` in `.yak/config.yml`.
- **Reactions not showing up**: the polling job runs hourly. Wait for the next cycle.
- **Linear ticket context not included**: confirm Yak is connected under **Settings > Linear** and the ticket identifier follows the `[A-Z]{2,6}-\d+` pattern.
