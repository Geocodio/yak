

## Questions already answered

The person already answered these earlier in this task. Treat the answers as decided and do not ask them again.

@foreach ($rounds as $round)
@foreach ($round['answers'] as $answer)
Q: {{ $answer['question'] }}
A: {{ $answer['answer'] }}
@if ($answer['other'] !== null)
Other: {{ $answer['other'] }}
@endif

@endforeach
@if ($round['note'] !== null)
Additional instructions from {{ $round['answeredBy'] }}: {{ $round['note'] }}

@endif
@endforeach
