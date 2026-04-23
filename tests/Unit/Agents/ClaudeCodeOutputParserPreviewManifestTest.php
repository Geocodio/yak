<?php

use App\Agents\ClaudeCodeOutputParser;

it('extracts a manifest from a fenced preview_manifest code block', function () {
    $resultText = <<<'TXT'
Setup complete. The app boots via docker compose.

```preview_manifest
{
  "port": 80,
  "health_probe_path": "/up",
  "cold_start": "docker compose up -d",
  "checkout_refresh": "docker compose restart web"
}
```

All done.
TXT;

    $manifest = ClaudeCodeOutputParser::extractPreviewManifest($resultText);

    expect($manifest)->toBe([
        'port' => 80,
        'health_probe_path' => '/up',
        'cold_start' => 'docker compose up -d',
        'checkout_refresh' => 'docker compose restart web',
    ]);
});

it('returns null when no manifest block is present', function () {
    expect(ClaudeCodeOutputParser::extractPreviewManifest('just prose, no JSON'))->toBeNull();
});

it('returns null on malformed JSON', function () {
    $bad = "```preview_manifest\n{not json}\n```";
    expect(ClaudeCodeOutputParser::extractPreviewManifest($bad))->toBeNull();
});
