**This merge made `.yak/{!! $fileName !!}` invalid.** @if ($validSha !== null)Yak keeps using the version from `{!! substr($validSha, 0, 7) !!}` until the file is fixed.@else Yak uses its stored settings for this file until it is fixed.@endif

@foreach (explode("\n", $error) as $line)
- {!! preg_match('/^([^:\s]+): (.*)$/', $line, $parts) === 1 ? App\Support\MarkdownText::code($parts[1]) . ': ' . App\Support\MarkdownText::inline($parts[2]) : App\Support\MarkdownText::inline($line) !!}
@endforeach

Open a pull request that fixes the file. The `yak / config` check reports problems before merge. [See the repository's Yak settings]({!! $settingsUrl !!})
