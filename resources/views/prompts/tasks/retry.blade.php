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
