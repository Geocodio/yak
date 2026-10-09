Yak reviewed the codebase at `{!! $shortSha !!}` and proposes these changes to `.yak/risk-profile.yml`. Merging approves them.

| Area | Change |
| --- | --- |
@forelse ($changes as $name => $change)
| {!! App\Support\MarkdownText::inline((string) $name) !!} | {!! $change !!} |
@empty
| No area changes | |
@endforelse
@if ($unknownCount > 0)

{!! $unknownCount !!} open {!! $unknownCount === 1 ? 'question remains' : 'questions remain' !!}. Open questions block Yak's auto-approval until they are answered in the file.
@endif
