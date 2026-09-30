# Risk-Based Approval

By default, Yak's [PR reviews](pr-review.md) are GitHub comments. A repository can opt in to let Yak also submit an approval or a change request when a PR clears a risk policy. Yak never merges. A human always does that.

Nothing is enabled by default. Start with **Shadow** mode on a single repo.

## Turn it on

Open the repo's edit page and find the approval settings. Save them with **Save repository**.

| Mode | What Yak does |
|---|---|
| **Off** | Comments only. Turning it off stops future approvals but does not revoke existing ones. |
| **Shadow** | Reports the event it would have submitted, without submitting it. Compare these with human reviews before enforcing. |
| **Enforce** | Submits approvals and change requests for eligible PRs. |

Other settings on the same page:

- Allowed paths. Keep them narrow.
- Trusted CI sources. At least one is required: GitHub check names with their trusted App IDs, and/or commit-status contexts (such as Drone) with their trusted creator user IDs.
- File and line limits, and the score, confidence and profile-age thresholds. You can tighten these. You cannot turn off the built-in security exclusions or the human-review rule for high-risk areas.

Settings come from the database, never from the PR branch.

## Risk profiles

A risk profile lists the risky areas of a repo (paths, symbols, severity, evidence). Approval needs an approved, current profile.

1. Click **Generate draft with Claude** in the repo settings. This queues a research task. When it finishes, return to settings to inspect the areas, evidence and unknowns.
2. Fix mistakes in **Edit draft JSON**. Each save creates a new draft that needs its own approval.
3. Approve the draft. You confirm the displayed version, and Yak records who approved it.

From the host you can do the same with `php artisan yak:risk-profile <slug>`, adding `--import=<file.json>` to load an edited draft, or `--approve=<draft-hash> --reviewer=<name>` to activate one. The reviewer name is an audit label, not an identity check.

A missing, corrupt or expired profile (90 days by default) blocks approval. A changed profile version requires a fresh review. Profiles only add restrictions. Unknown paths need a human. Where areas overlap, the highest risk wins.

The **Repository Risk Profile** prompt is editable in the prompt editor. See [Prompting](prompting.md).

## How Yak decides

The reviewer rates each PR on five dimensions: impact, blast radius, behavior change, verification strength and context completeness. Yak computes a risk score from the ratings, with the profile severity as a floor. It approves only when all of these hold:

- The score is 30 or lower.
- The reviewer's confidence is at least 80. High confidence never lowers the risk score.
- There are no open uncertainties or human-review reasons.
- The review is full and clean, within the file and line limits, and all PR files count, including excluded paths.
- No blocked or unknown paths, removed or renamed files, missing patches, modified existing tests, or untested code changes.
- Trusted CI evidence has passed.

A concrete `must_fix` finding on an otherwise current, eligible PR produces a change request. Anything else short of approval is a comment. Test files are recognized through `pr_review.approval_test_paths` in `config/yak.php`. If a repo keeps tests elsewhere, extend that list first.

The review body, task logs and dashboard show the score, the reasons and the evidence. Shadow results are labelled as such and are never shown as a submitted approval.

## Checks before submitting

Right before approving, Yak verifies the live head and base SHAs, the PR state, that the head is in the same repository, resolved review threads, and every configured check from its trusted source. Pending, skipped, failed or incomplete CI blocks approval. If CI finishes after the review, request another review. Yak schedules no background approval.

The approval is pinned to the reviewed commit, so new commits need a fresh review. If GitHub rejects the submission, Yak falls back to a comment.

## Limits

- Yak's GitHub App cannot approve its own PRs. Those stay comment-only, even in Enforce mode.
- PRs with GitHub auto-merge enabled are ineligible.
- Yak needs to read branch protection, and `dismiss_stale_reviews` must be enabled. If it cannot, it falls back to a comment. Yak never changes or bypasses branch protection.
- The GitHub reads and the review write are not atomic. Keep required CI and stale-approval dismissal enforced in GitHub.
- The scores and thresholds are initial heuristics, not calibrated probabilities.
