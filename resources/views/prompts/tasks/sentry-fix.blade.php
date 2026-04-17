Fix the following Sentry error:

**Error:** {{ $error }}
**Culprit:** {{ $culprit }}

**Stacktrace:**
{{ $stacktrace }}
@if($context)

**Additional Context:**
{{ $context }}
@endif

**Instructions:**
{{ $instructions }}

@include('prompts.partials.clarification-contract')