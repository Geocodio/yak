Yak reviewed the codebase at `{!! $shortSha !!}` and proposes these changes to `.yak/risk-profile.yml`. Merging approves them.

| Concern | Change |
| --- | --- |
@forelse ($changes as $name => $change)
| {!! App\Support\MarkdownText::inline((string) $name) !!} | {!! $change !!} |
@empty
| No concern changes | |
@endforelse
