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
