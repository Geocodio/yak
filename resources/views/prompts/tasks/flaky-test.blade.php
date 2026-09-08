@if(count($tests) === 1)
Fix the following failing test:
@else
The following {{ count($tests) }} tests are all failing at the same commit. They usually share one root cause, so investigate them together and fix them in one pass.
@endif
@foreach($tests as $test)

---

**Test:** {{ $test['test_name'] }}
@if($test['test_class'] !== '' && $test['test_class'] !== $test['test_name'])
**Test Class:** {{ $test['test_class'] }}
@endif

**Failure Output:**
{{ $test['failure_output'] }}
@if(! empty($test['build_urls']))

**Observed failures ({{ $test['failure_count'] ?: count($test['build_urls']) }}):**
@foreach($test['build_urls'] as $url)
- {{ $url }}
@endforeach
@endif
@endforeach
@if($commitSha)

---

**Commit:** {{ $commitSha }}
@endif

## Before you fix anything

Check whether this is actually a flake. A test that fails on every run is
deterministic breakage, not a flaky test, and the two need different fixes.

If you find that the failure is already fixed on another branch, or that an
open pull request already covers it, do NOT commit anything. Say so in your
final summary and stop — an accurate report is the useful outcome, and a
second pull request for a fix that already exists is not.

@include('prompts.partials.clarification-contract')
