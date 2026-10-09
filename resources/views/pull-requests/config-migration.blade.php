## Summary

Yak now reads per-repository settings from a `.yak/` directory on the default branch. This pull request moves the values stored in Yak for **{!! $repository->name !!}** into files, so later changes go through review here. Nothing changes in how Yak behaves when it merges: every value is the one Yak uses today.

| File | From |
| --- | --- |
@foreach ($files as $path => $source)
| `{!! $path !!}` | {!! $source !!} |
@endforeach
@if ($riskProfileUnapproved)

> [!WARNING]
> **The risk profile was never approved.** This is the draft Yak generated on {!! $riskProfileDate !!}. Nobody approved it in Yak. Merging this pull request approves it, so review every area, risk level and path before merging, or remove the file from this pull request and add it later.
@endif
@if ($checksLostAppPin !== [])

> [!NOTE]
> **Required checks match by name.** Yak stored {!! collect($checksLostAppPin)->map(fn ($name) => "`{$name}`")->join(', ', ' and ') !!} with a trusted GitHub App ID. In `config.yml` they match by name, so a check with the same name from any app counts. Branch protection still decides who can report checks.
@endif

## After merging

- The settings page shows these values as read-only, with links to the files.
- Changes to `.yak/` get the `yak / config` check. Consider making it required.
