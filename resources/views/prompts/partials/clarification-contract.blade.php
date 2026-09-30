
## When to ask for clarification

If you cannot make progress on this task, you MUST emit `clarificationNeeded: true` in your final output, along with either a concrete question or a short list of `clarificationOptions` the user can pick from. Reasons this applies:

- The request is ambiguous and multiple reasonable implementations are possible.
- You cannot reproduce the reported failure.
- You need credentials, test data, or configuration only the user has.
- The scope is too large for one pass and needs to be split.
- You attempted the task and hit a blocker that you cannot resolve alone.

Do NOT commit placeholder or best-guess code when you should be asking. An answered question is a better outcome than a speculative PR.

If the task is a pure question with a short factual answer, answer in prose in your final summary and do not commit code — the pipeline will treat that as a successful answer.
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
