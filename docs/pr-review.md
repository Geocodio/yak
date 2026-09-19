# PR Review

Yak can review pull requests on every enabled repository. When a PR is opened or marked ready for review, Yak dispatches a `review` task that runs the same Claude Code pipeline used for fixes and research — this time with read-only access and a rubric tuned for code review. The output is posted back to GitHub as a line-level review, complete with `suggestion` blocks where they fit.

## Enabling It

Go to the repo's settings page (`/repos/{id}/edit`) and flip the **PR Review** toggle. When you flip it on, a second switch appears — **Review all currently open PRs on save** — which, when kept on, enqueues a retroactive review for every eligible open PR the moment you save.

After that:

- Opening a new PR triggers a full review.
- Marking a draft PR ready-for-review triggers a full review.
- Reopening a closed PR triggers a full review.
- **Pushing new commits does NOT trigger another review.** Yak intentionally stays quiet while you keep iterating — otherwise a rapid series of commits would produce a wall of overlapping reviews.

PRs authored by the Yak app bot are reviewed when `YAK_PR_SELF_REVIEW_ENABLED` is enabled (the default). They cannot receive approval or a change request from that same app identity.

## Asking For Another Review

When you're ready for a fresh pass, click **Re-request review** next to the Yak bot in the PR's Reviewers sidebar on GitHub. Yak runs an **incremental** review — only the commits since its last review are considered. If no prior Yak review exists on the PR, it falls back to a full review. Force-pushes between reviews also fall back to a full review automatically.

This is the only re-review trigger: there's no slash command and no label. The button is GitHub-native and only appears after Yak has submitted at least one review on the PR.

## Path Filters

Yak ships with sensible defaults for what to exclude from review — `vendor/**`, `node_modules/**`, build output, minified assets, editor config. The full default list is in `config/yak.php` under `pr_review.default_path_excludes`. Migrations and lockfiles are **not** excluded by default: migrations contain real logic (schema changes, indexes, destructive drops) worth a look, and lockfile diffs can surface dependency version bumps the author didn't highlight.

If the defaults are wrong for a repo, the repo settings page has a **PR review path filters** section. You can add glob patterns (e.g. `custom/generated/**`), remove specific patterns, or reset back to the global defaults. The patterns support `*`, `**`, and `?` just like `.gitignore`.

When path filters are active, Yak filters the changed file list before building the prompt AND filters findings Claude produces — so a model that still hallucinated a finding in `vendor/` gets silently dropped.

## Interpreting Reviews

Each review comment has three pieces of metadata:

| Field | Values | Meaning |
|---|---|---|
| Category | Correctness, Security, Data, Compatibility, Ticket Alignment, Tests, Performance, Conventions | What kind of issue this is, in the order Yak hunts for them |
| Severity | `must_fix`, `should_fix`, `consider` | How important |
| Suggestion | yes/no | Whether the comment contains a 1–10 line code suggestion block |

Comments are written like a senior engineer's inline GitHub comments: one sentence that names the concrete failure, with `nit:` marking the optional ones. Yak does not post a review report, a summary of what the PR does, or a list of what it tested. A clean review body says `LGTM` and nothing else. Findings whose line falls outside a diff hunk are folded into the review body; `consider` findings that can't be posted inline land in a collapsed "Nitpicks" block.

Every review body ends with a collapsed **For the reviewer** block. It is written for the human doing the intent review, not the author: what the PR does, how it covers the Linear ticket's requirements, risk areas worth a human question, and what Yak verified in the sandbox versus what it could not check. Open it before you start your own review.

Yak records a **verdict** (`Approve`, `Approve with suggestions`, or `Request changes`) for the dashboard. By default, reviews remain GitHub comments. Opted-in repositories can use the risk policy below to submit approvals or change requests. Merging always remains a human action.

The findings panel labels the model verdict separately from the actual GitHub
review event. It shows risk, subjective confidence, policy reasons, and expandable
signals and observed evidence. Shadow recommendations are explicitly labelled and
never displayed as an approval that was submitted.

## Risk-based approval (opt-in)

### Rollout requirements

Keep approvals disabled until the PHP tests, browser tests, Pint and frontend
build pass in the project's supported environment. Apply the migration before
restarting workers with this version, then start with `shadow` on a single repo.
Scores and confidence thresholds are initial heuristics, not calibrated error
probabilities; compare shadow decisions with human reviews before enforcement.

Repository settings contain the approval mode, path lists, trusted CI providers,
size limits, score/confidence thresholds and profile validity. Save them with
the normal **Save repository** button. Settings are stored in the database and
reloaded before policy evaluation. Set the mode to **Off** to stop future
approvals; this does not revoke existing GitHub reviews.

The same section lets you generate, inspect, edit and approve risk profiles.
Approval requires confirmation of the displayed version and records the signed-in
user. All web and queue workers must share the private local storage containing
profiles. Back up that directory with deployment data.

PRs with GitHub auto-merge enabled are ineligible. Yak never invokes merge APIs.
External automation and branch settings must also preserve the intended human
merge step. The same GitHub app identity cannot approve its own PRs. GitHub
evidence is checked before submission, but those reads and the review write are
not atomic. Keep required CI and stale-approval dismissal enforced by GitHub.

### Repository risk profiles and signals

Click **Generate draft with Claude** in repository settings to queue research
and follow its task progress. Return to settings when the task completes to
inspect risk areas, evidence, source revision and unknowns. Changes in **Edit
draft JSON** are saved as a new draft and require a separate review and approval.
The **Repository Risk Profile** prompt lives in the existing prompt editor with
preview, version history and reset; PR review instructions remain in **PR Review**.

For host operators, `php artisan yak:risk-profile <slug>` also queues research through the
normal sandbox, budget and queue controls. The generic prompt maps critical
flows, shared symbols, callers, contracts and test gaps. It returns areas with
paths, symbols, severity, rationale and code evidence. A successful task shows
the JSON draft and its SHA-256 version; source revision is captured by the host.

Drafts live under the private local disk at `risk-profiles/<sha256-of-slug>/drafts/`.
Review the draft, then explicitly activate it using
`php artisan yak:risk-profile <slug> --approve=<draft-hash> --reviewer=<human-name>`.
This CLI operation requires host access and is not exposed to the agent. The
reviewer name is an operator-supplied audit label, not an identity verification
mechanism. To correct a draft, edit a local copy of its JSON and run
`php artisan yak:risk-profile <slug> --import=<file.json>`; this produces a new
hash and does not approve it. Import and approval are separate operations.
Approved snapshots are retained alongside the active profile. Missing, corrupt
or expired profiles prevent approval (default maximum age: 90 days). Each review
records the profile version it used; a changed active version requires re-review.

Profiles only add restrictions to the host allowlist and hard vetoes. Unknown
paths and unresolved profile context gaps require human review. Overlapping
areas use the highest risk. Symbol references guide the model's caller tracing;
they are not a deterministic whole-program dependency analysis.

The reviewer reports five ordinal dimensions with explanations and citations:
impact, blast radius and behavior change (0 safest to 4 highest), verification
strength and context completeness (0 missing to 4 strongest). PHP computes the
maximum of `impact*25`, `blast_radius*20`, `behavior_change*15`,
`(4-verification_strength)*20` and `(4-context_completeness)*20`. Profile severity
sets a floor: low 0, medium 50, high 75, critical/unknown 100. Approval requires a
score no greater than 30 plus every existing gate. Missing or malformed signals
yield an unknown score. Weights and threshold are initial policy choices, not
empirically calibrated probabilities.

`model_confidence` is a separate subjective 0-100 signal. Missing confidence or
values below 80 require human review; high confidence cannot lower risk. Open
uncertainties or human-review reasons also block approval independently of score.
Model citations and verification ratings remain model claims. Host-observed
facts are reported separately: file/line counts, test additions/deletions/skips,
known Yak writer attempts, reviewed SHA and independently fetched CI results.
Missing writer history is unknown, not a claim of first-attempt success. The
review body, task logs and submission telemetry carry scores and provenance.
Run migrations before deploying this version. `pr_reviews.risk_assessment`
stores the complete decision, signals, observed facts, profile/scoring versions
and reviewed SHAs. Task-detail and per-PR responses expose it as `riskAssessment`.
Existing customized review prompts must adopt the Risk signals section; absent
signals safely prevent approval.

In repository settings, choose **Shadow** to report the proposed event without
approving, or **Enforce** to submit eligible approvals. Nothing is enabled by
default. Add narrow allowed paths and required GitHub check names with their
trusted App IDs, and/or commit-status contexts (such as Drone) with their trusted
creator user IDs. At least one trusted CI source is required for approval.
Settings come from the database, never the PR branch. The UI can tighten score,
confidence and profile-age limits; it cannot disable the built-in security
exclusions or the human-review requirement for high-risk areas.

The policy approves only full, clean, explicitly low-risk reviews within the file and line limits. All PR files count, including excluded paths. Unknown paths, blocked paths, removed/renamed files, missing patches, modified existing tests and untested code changes require human review. Added test lines are only a structural signal, not proof of coverage; the reviewer must also verify the relevant behavior. Findings are evaluated before display filtering or truncation. A concrete `must_fix` finding produces `REQUEST_CHANGES` on an otherwise current, eligible PR; other concerns produce `COMMENT`.

Before approval Yak verifies the live head/base SHAs, base ref, PR state, same-repository head, complete file count, resolved review threads, and all configured checks/statuses from their trusted apps or creators. Pending, skipped, failed or incomplete CI evidence prevents approval. If CI finishes after the review, request another review; no background approval is scheduled. Opted-in re-reviews always run full scope.

The app must be able to read the base branch's protection, and it must have `dismiss_stale_reviews` enabled. Permission errors or unrecognized protection configurations fail closed to a comment. Yak never changes protection or bypasses it. Every submitted review is pinned using `commit_id`; new commits require fresh approval. A rejected submission falls back to a comment, never a second approval attempt. Decisions and reasons appear in the review body and task logs; submission telemetry records the actual event.

The same app cannot approve its own PR. Such PRs remain comment-only, even in enforce mode. A separately installed reviewer identity can review another writer's PR, but provisioning that identity is outside this feature. Existing edited prompt overrides must include the explicit Risk section from the default review prompt; missing risk assessments safely remain `unknown`.

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

- Reviews are advisory unless the repository opts into risk-based approval. GitHub branch protection and CODEOWNERS requirements still apply; a Yak approval does not necessarily satisfy every required reviewer rule.
- Review accuracy scales with the prompt; expect some noise on `consider` findings. Use the 👎 reaction to signal false positives — the dashboard surfaces patterns.
- The `max_findings_per_review` cap (default 10) keeps reviews focused. Tune via `config/yak.php` if needed.
- Incremental reviews only compute the diff between Yak's last reviewed SHA and the current head. Re-reviews of the full PR can always be triggered from TaskDetail.

## Troubleshooting

- **Review never posted** — Check the TaskDetail page for the failed review task. The sandbox might have failed to check out the PR head, or the JSON output from Claude might be malformed. Both failure modes are logged in the activity log.
- **Review posted but comments missing** — Path filters may be too aggressive. Check `config/yak.php` `pr_review.default_path_excludes` or the per-repo overrides.
- **Reactions not showing up** — The polling job runs hourly. Force a manual poll with `php artisan schedule:run` or wait for the next cycle.
- **Linear ticket context not included** — Confirm a `LinearOauthConnection` exists and the ticket identifier follows the `[A-Z]{2,6}-\d+` pattern.
