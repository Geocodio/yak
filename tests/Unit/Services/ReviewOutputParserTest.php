<?php

use App\Services\ReviewOutputParser;

it('parses a well-formed JSON block from the agent output', function () {
    $output = <<<'TEXT'
Here is my review.

```json
{
  "summary": "This PR adds retry.",
  "verdict": "Approve with suggestions",
  "verdict_detail": "One blocker found.",
  "findings": [
    {"file": "app/Foo.php", "line": 12, "severity": "must_fix", "category": "Performance", "body": "Null check", "suggestion_loc": 2}
  ]
}
```
TEXT;

    $parsed = app(ReviewOutputParser::class)->parse($output);

    expect($parsed->summary)->toBe('This PR adds retry.')
        ->and($parsed->verdict)->toBe('Approve with suggestions')
        ->and($parsed->findings)->toHaveCount(1)
        ->and($parsed->findings[0]->severity)->toBe('must_fix')
        ->and($parsed->findings[0]->suggestionLoc)->toBe(2);
});

it('throws when no JSON block is present', function () {
    app(ReviewOutputParser::class)->parse('No JSON here');
})->throws(RuntimeException::class);

it('throws when required keys are missing', function () {
    app(ReviewOutputParser::class)->parse("```json\n{\"summary\": \"x\"}\n```");
})->throws(RuntimeException::class);

it('extracts JSON even when findings contain nested suggestion fences', function () {
    $suggestion = "```suggestion\n\$client = Http::withHeaders(\$headers);\nforeach (range(1, 3) as \$attempt) {\n    \$response = \$client->get(\$url);\n}\n```";

    $body = "Retry loop re-creates the client each iteration. Reuse it:\n\n{$suggestion}";

    $json = json_encode([
        'summary' => 'PR review with a suggestion block.',
        'verdict' => 'Approve with suggestions',
        'verdict_detail' => 'One performance suggestion.',
        'findings' => [[
            'file' => 'app/Services/Foo.php',
            'line' => 87,
            'severity' => 'should_fix',
            'category' => 'Performance',
            'body' => $body,
            'suggestion_loc' => 4,
        ]],
    ], JSON_PRETTY_PRINT);

    $output = "Review complete.\n\n```json\n{$json}\n```\n";

    $parsed = app(ReviewOutputParser::class)->parse($output);

    expect($parsed->findings)->toHaveCount(1)
        ->and($parsed->findings[0]->body)->toContain('```suggestion')
        ->and($parsed->findings[0]->suggestionLoc)->toBe(4);
});

it('handles trailing text after the closing fence', function () {
    $output = <<<'TEXT'
```json
{
  "summary": "x",
  "verdict": "Approve",
  "verdict_detail": "y",
  "findings": []
}
```

I'm done now.
TEXT;

    $parsed = app(ReviewOutputParser::class)->parse($output);

    expect($parsed->summary)->toBe('x')
        ->and($parsed->findings)->toBeEmpty();
});

it('accepts a finding without suggestion_loc', function () {
    $output = <<<'TEXT'
```json
{
  "summary": "x",
  "verdict": "Approve",
  "verdict_detail": "y",
  "findings": [{"file":"a.php","line":1,"severity":"consider","category":"Simplicity","body":"b"}]
}
```
TEXT;

    $parsed = app(ReviewOutputParser::class)->parse($output);

    expect($parsed->findings[0]->suggestionLoc)->toBeNull();
});
