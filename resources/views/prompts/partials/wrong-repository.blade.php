@if(! empty($otherRepositories ?? []))

## When this is the wrong repository

If the code in this checkout is clearly not where the requested change lives (the files, services, or feature the request describes do not exist here and belong to a different repository), stop right away. Make no changes, leave the working tree untouched, and end your final output with a fenced block tagged `wrong_repository` containing JSON:

```wrong_repository
{"reason": "One short sentence on why this checkout is not the right place.", "suggested_repository": "Owner/name"}
```

Set `suggested_repository` to the slug of the repository that does look right, or to `null` when none of them does.

Other repositories Yak can work in:
@foreach($otherRepositories as $otherRepository)
- {{ $otherRepository['slug'] }}@if(($otherRepository['description'] ?? '') !== ''): {{ $otherRepository['description'] }}@endif

@endforeach

Do not use this block when the change belongs here but is hard. Use it only when you are confident the request targets another codebase.
@endif
