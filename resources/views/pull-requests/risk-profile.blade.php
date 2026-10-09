Yak reviewed the codebase at `{!! $shortSha !!}` and proposes these changes to `.yak/risk-profile.yml`. Merging approves them.

| Area | Change |
| --- | --- |
@foreach ($changes as $name => $change)
| {!! str_replace('|', '\|', $name) !!} | {!! $change !!} |
@endforeach
@if ($unknownCount > 0)

{!! $unknownCount !!} open {!! $unknownCount === 1 ? 'question remains' : 'questions remain' !!}. Open questions block Yak's auto-approval until they are answered in the file.
@endif
