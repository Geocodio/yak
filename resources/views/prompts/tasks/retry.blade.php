The CI pipeline failed for this task. Please fix the issues and try again.
@if($failureOutput)

**CI Failure Output:**
{{ $failureOutput }}
@else

No CI output was captured. Please review the code for issues and fix them.
@endif