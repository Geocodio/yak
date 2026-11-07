A branch preview of this repository failed to start. Find out why, and fix the cause only when it is in the repository's shared preview setup.

**Branch:** {{ $branchName }}
@if($pullRequestNumber)
**Pull request:** #{{ $pullRequestNumber }}
@endif
@if($headSha)
**Commit:** {{ $headSha }}
@endif
**Failed step:** {{ $phase }}
@if($failingCommand)
**Failed command:** {{ $failingCommand }}
@endif

**Output (last lines):**
{{ $errorExcerpt }}

**Preview recipe in effect** (`.yak/preview.yml`, or Yak's stored manifest when the file is absent):
@foreach($manifest as $key => $value)
- `{{ $key }}`: {{ is_scalar($value) ? $value : json_encode($value) }}
@endforeach

How a preview starts: Yak clones the repository's template container, runs `cold_start`, checks out the commit at `/workspace`, runs `checkout_refresh` (or `.yak/preview.sh <sha>` when that file exists in the branch), then probes `http://<ip>:<port><health_probe_path>` until it answers 2xx. `cold_start` and `checkout_refresh` run through `bash -lc` from the home directory, so they `cd /workspace` first.

## Decide where the fault is

You are on a new branch made from the default branch. Read the failure, the recipe, `docker-compose.yml` and anything else the recipe uses, and the recent history of the default branch (`git log -p` on the files the recipe touches). Then check out the failing branch and compare.

The fault is in one of these places:

- **branch**: the failing branch's own changes break the preview, for example a migration in that branch crashes, or its code does not build. The default branch would preview fine.
- **recipe**: the shared preview setup is broken, for example the recipe builds a file the repository no longer has, or the stack changed and the recipe did not. Every branch made from the default branch would fail the same way.
- **infrastructure**: Yak itself, the host, the network or GitHub failed, not the repository.
- **unknown**: you cannot tell.

This sandbox runs its own Docker daemon (wait for `docker info` to succeed), so you can run the recipe from `/workspace` to reproduce the failure on the failing branch and on the default branch. If the preview needs secrets or environment this sandbox does not have, say so and judge from what you can run. Do not keep retrying a run the sandbox cannot complete.

## What to do

- **recipe**: switch back to your branch (the one made from the default branch) and fix the cause there. You may change any file it needs: `.yak/preview.yml`, `docker-compose.yml`, scripts or app code. Run the repaired recipe against the failing branch if the sandbox allows it. Commit the fix.
- **branch**, **infrastructure** or **unknown**: do NOT commit anything. Yak tells the right people with your explanation; a pull request is not the useful outcome here.

End your response with this block, as valid JSON:

```preview_repair
{"verdict": "recipe|branch|infrastructure|unknown", "cause": "<one short paragraph in plain words: what broke and why>", "introduced_by": "<the commit sha that introduced the cause, or null>"}
```
