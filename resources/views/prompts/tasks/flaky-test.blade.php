Fix the following flaky test:

**Test Class:** {{ $testClass }}
**Test Method:** {{ $testMethod }}

**Failure Output:**
{{ $failureOutput }}
@if(! empty($buildUrls))

**Observed failures ({{ $failureCount ?: count($buildUrls) }}):**
@foreach($buildUrls as $url)
- {{ $url }}
@endforeach
@elseif($buildUrl)

**Build URL:** {{ $buildUrl }}
@endif