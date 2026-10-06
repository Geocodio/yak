You stopped earlier to ask questions about this task. The answers are below.

Your workspace is a fresh checkout of the task branch; edits you made before asking were not kept unless they were pushed, so re-apply anything you still need.

**Original task:**
{{ $taskDescription }}

@foreach ($answers as $answer)
Q: {{ $answer['question'] }}
A: {{ $answer['answer'] }}
@if ($answer['other'] !== null)
Other: {{ $answer['other'] }}
@endif

@endforeach
@if ($note !== null)
Additional instructions from {{ $answeredBy }}: {{ $note }}

@endif
Continue the task with these answers.
@if ($isLastRound)
You have asked the maximum number of rounds. Do not ask again. Proceed with your best judgment and state each assumption in your summary.
@include('prompts.partials.wrong-repository')
@else
@include('prompts.partials.clarification-contract')
@endif
