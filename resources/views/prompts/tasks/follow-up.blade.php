The pull request for this task is already open. The user has reviewed it and is giving you feedback to refine it. Apply the following changes and push to the same branch. Do not ask for clarification; use your best judgment.

**Feedback:**
{{ $instructions }}

Inline comments are tagged `[c:<id>]`. Some feedback may be a question or a disagreement rather than a change request: read the relevant code first, answer it in your reply for that comment, and do not change code for them. Change code only where a change is asked for. Every reply is posted on that comment's thread, so write each one as a direct answer to that reviewer, in one to three sentences, naming the commit when you changed something.

If your change alters anything the existing screenshots or walkthrough show, capture them again under rule 6 (VISUAL CAPTURE). If nothing visible changed, capture nothing.

**Final summary format:** This run's final summary is parsed by Yak. Produce exactly these sections, in this order, with these exact headings:

## What changed in this run

A few short bullets or sentences describing only what you changed in response to this feedback. This becomes a comment on the pull request. Do not restate the original PR description, repeat the Summary/Changes structure, or describe work from earlier runs.

## Replies

Write one entry for every tagged comment you acted on or answered, in this exact form, one per line (continuation lines are allowed until the next entry):

- [c:<id>] Your reply to that comment.

Omit comments that need no reply (praise, acknowledgements). Omit the whole section when there are no tagged comments. Never put replies anywhere else in the summary.

## PR description

If the pull request description still accurately describes what the PR does after your changes, write exactly the single line `Unchanged.` and nothing else under this heading.

Otherwise write the full replacement description in the FINAL SUMMARY FORMAT structure (## Summary, ## Changes), describing the PR as it now stands. A reviewer reading only this should understand the whole PR, not just this run. Rewrite; do not append a changelog.

A description is out of date when the follow-up added, removed, or changed a feature, a file group, a behaviour, or a decision that the description mentions or should mention. Renames, refactors that keep behaviour, test-only changes, and small fixes inside an already-described change do not require a rewrite.
