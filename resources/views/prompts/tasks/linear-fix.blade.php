Fix the following Linear issue:

@if($identifier ?? '')**Linear Issue:** {{ $identifier }} ({{ $url ?? '' }})

@endif**Title:** {{ $title }}

**Description:**
{{ $description }}

**Instructions:**
{{ $instructions }}

Yak posts progress and result comments back on the Linear issue automatically — no need for you to call Linear yourself.

@include('prompts.partials.clarification-contract')