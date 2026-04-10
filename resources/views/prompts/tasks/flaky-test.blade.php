Fix the following flaky test:

**Test Class:** {{ $testClass }}
**Test Method:** {{ $testMethod }}

**Failure Output:**
{{ $failureOutput }}
@if($buildUrl)

**Build URL:** {{ $buildUrl }}
@endif