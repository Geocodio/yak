You are re-running a task that failed CI. The branch is already checked out and contains the previous attempt's commits — inspect them with `git log main..HEAD` and `git diff main..HEAD` to see exactly what was changed.

## Original task

{{ $taskDescription }}

@if($previousSummary)
## What the previous attempt did

{{ $previousSummary }}

@endif
## Why CI failed

@if($failureOutput)
```
{{ $failureOutput }}
```
@else
No CI output was captured. Investigate the test/build failures by running the repo's test suite locally in this sandbox.
@endif

## What to do

1. Read the previous commits on this branch (`git log -p main..HEAD`) so you understand the change set before editing.
2. Identify the root cause of the CI failure. If the CI output is unclear, reproduce it locally in the sandbox.
3. Fix it — either by amending the approach, adjusting the implementation, or addressing a test flake if that's genuinely what it is.
4. Commit the fix on the same branch and push. Yak will wait for CI to re-run.

## How to write your final summary

Your final summary replaces the **entire** pull request body — not just the part about this retry. Reviewers read it to understand the whole branch, and most of them do not care that CI needed a second pass.

- Summarize everything on the branch (`git log main..HEAD`), not only what you changed in this attempt. Merge the previous attempt's summary above with your own work.
- The original task is the headline. `## Summary` must describe the problem the branch solves, in the terms of the original task.
- A CI fix that was unrelated to the original task (a flaky test, a dependency advisory that predates the branch, an infrastructure failure) gets at most one bullet near the end of `## Changes`. Never make it the summary, and do not open the body with an explanation of it.
- Do not complain about the CI failure, argue that it was pre-existing, or justify your choices to the reviewer in the PR body. State what changed. If a decision genuinely needs the reviewer's attention, make it one short bullet.

@include('prompts.partials.clarification-contract')
